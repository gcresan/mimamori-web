<?php
// FILE: inc/gcrev-api/modules/class-qa-auto-rollback.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Auto_Rollback
 *
 * Comparator の結果を基に「悪化した」と判断できれば Prompt Registry を
 * 1 世代前に自動ロールバックする。
 *
 * 悪化判定（いずれか 1 つでも該当）:
 *   - 平均スコアが MIN_SCORE_DROP pts 以上低下
 *   - HALLUCINATION 件数が増加
 *   - fail 件数（90 未満）が FAIL_DELTA_THRESHOLD 件以上増加
 *
 * @package Mimamori_Web
 * @since   3.2.0
 */
class Mimamori_QA_Auto_Rollback {

    /** rollback トリガーとなる平均スコア低下幅（pts） */
    public const MIN_SCORE_DROP = 5.0;

    /** rollback トリガーとなる fail 件数増加（件） */
    public const FAIL_DELTA_THRESHOLD = 2;

    /**
     * 比較結果から rollback 必要か判定する。
     */
    public function should_rollback( array $diff ): array {
        $reasons = [];

        if ( ( $diff['avg_score_delta'] ?? 0.0 ) <= -self::MIN_SCORE_DROP ) {
            $reasons[] = sprintf(
                'avg_score_drop:%.1fpts',
                $diff['avg_score_delta']
            );
        }
        if ( ( $diff['hallucination_delta'] ?? 0 ) > 0 ) {
            $reasons[] = sprintf(
                'hallucination+%d',
                $diff['hallucination_delta']
            );
        }
        if ( ( $diff['fail_count_delta'] ?? 0 ) >= self::FAIL_DELTA_THRESHOLD ) {
            $reasons[] = sprintf(
                'fail_count+%d',
                $diff['fail_count_delta']
            );
        }

        return [
            'should'  => ! empty( $reasons ),
            'reasons' => $reasons,
        ];
    }

    /**
     * 2 つの run を比較し、必要なら rollback を実行する。
     *
     * @param string $current_run_id
     * @param string $previous_run_id
     * @return array {
     *   rolled_back: bool,
     *   reasons: string[],
     *   diff_summary: array,
     *   rolled_to_version: ?string,
     * }
     */
    public function rollback_if_needed( string $current_run_id, string $previous_run_id ): array {
        $comparator = new Mimamori_QA_Run_Comparator();
        $diff       = $comparator->compare( $current_run_id, $previous_run_id );

        // 結果は保存しておく（管理画面で確認できる）
        $comparator->save_comparison( $current_run_id, $previous_run_id, $diff );

        $verdict = $this->should_rollback( $diff );

        $summary = [
            'avg_score_delta'     => $diff['avg_score_delta'] ?? null,
            'avg_current'         => $diff['avg_current'] ?? null,
            'avg_previous'        => $diff['avg_previous'] ?? null,
            'fail_count_delta'    => $diff['fail_count_delta'] ?? null,
            'hallucination_delta' => $diff['hallucination_delta'] ?? null,
        ];

        if ( ! $verdict['should'] ) {
            $this->log( 'evaluate_ok', [
                'current'  => $current_run_id,
                'previous' => $previous_run_id,
                'summary'  => $summary,
            ] );
            return [
                'rolled_back'       => false,
                'reasons'           => [],
                'diff_summary'      => $summary,
                'rolled_to_version' => null,
            ];
        }

        // history から一番新しい snapshot を rollback 先に選ぶ
        $history = Mimamori_QA_Prompt_Registry::get_history( 1 );
        $target_version = $history[0]['version'] ?? '';

        if ( $target_version === '' ) {
            $this->log( 'rollback_skip', [
                'reason'   => 'no_history_available',
                'current'  => $current_run_id,
                'previous' => $previous_run_id,
                'reasons'  => $verdict['reasons'],
            ] );
            return [
                'rolled_back'       => false,
                'reasons'           => $verdict['reasons'],
                'diff_summary'      => $summary,
                'rolled_to_version' => null,
            ];
        }

        $ok = Mimamori_QA_Prompt_Registry::rollback_to( $target_version, 0 );

        $this->log( 'rollback', [
            'current'  => $current_run_id,
            'previous' => $previous_run_id,
            'to'       => $target_version,
            'reasons'  => $verdict['reasons'],
            'diff'     => $summary,
            'intent_affected' => $this->extract_affected_intents( $diff ),
        ] );

        return [
            'rolled_back'       => $ok,
            'reasons'           => $verdict['reasons'],
            'diff_summary'      => $summary,
            'rolled_to_version' => $ok ? $target_version : null,
        ];
    }

    /**
     * スコアが悪化した intent 一覧を抽出する（ログ用）。
     */
    private function extract_affected_intents( array $diff ): array {
        $out = [];
        foreach ( ( $diff['by_intent'] ?? [] ) as $intent => $data ) {
            $delta = $data['delta'] ?? null;
            if ( $delta !== null && $delta < 0 ) {
                $out[] = [
                    'intent' => $intent,
                    'delta'  => $delta,
                ];
            }
        }
        return $out;
    }

    private function log( string $event, array $ctx = [] ): void {
        $line = sprintf(
            "%s event=%s %s\n",
            date( 'Y-m-d H:i:s' ),
            $event,
            wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE )
        );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents( '/tmp/gcrev_qa_rollback.log', $line, FILE_APPEND );
    }
}
