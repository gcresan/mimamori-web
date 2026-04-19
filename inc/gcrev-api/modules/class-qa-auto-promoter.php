<?php
// FILE: inc/gcrev-api/modules/class-qa-auto-promoter.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Auto_Promoter
 *
 * QA run で生成された改訂案 (qa_runs/<run_id>/improvements/*.json) を評価し、
 * 安全条件を満たす intent の最終 revision のみ Prompt Registry に自動昇格する。
 *
 * 判定ポリシー:
 *   - _global は絶対に昇格しない（手動管理レイヤー）
 *   - intent 不明なケースは除外
 *   - addendum は 4000 文字上限 / 禁止ワードチェック
 *   - overrides は whitelist のみ
 *   - revision が passed で final_score >= initial_score + MIN_IMPROVEMENT
 *   - triage に critical が残っていない
 *   - 1 run で昇格できる intent は最大 MAX_PROMOTIONS_PER_RUN 件
 *   - 同じ intent が直近 COOLDOWN_DAYS 日以内に auto_rollback されていれば却下
 *
 * @package Mimamori_Web
 * @since   3.2.0
 */
class Mimamori_QA_Auto_Promoter {

    /** 同一 run で昇格できる intent 数上限 */
    public const MAX_PROMOTIONS_PER_RUN = 3;

    /** 最低スコア改善幅（initial→final） */
    public const MIN_SCORE_DELTA = 5;

    /** 最低 final_score（これ以上でないと昇格させない） */
    public const MIN_FINAL_SCORE = 90;

    /** rollback 後のクールダウン日数 */
    public const COOLDOWN_DAYS = 7;

    /** QA 出力ベース（upload ディレクトリ相対） */
    public const OUTPUT_BASE = 'mimamori/qa_runs';

    /**
     * 指定 run の improvements/ を走査して昇格候補を処理する。
     *
     * @param string $run_id sanitized run_id
     * @return array {
     *   promoted: list<array>  昇格した intent の一覧
     *   rejected: list<array>  却下理由の一覧
     *   skipped:  list<array>  評価対象外
     * }
     */
    public function promote_from_run( string $run_id ): array {
        $result = [ 'promoted' => [], 'rejected' => [], 'skipped' => [] ];

        $run_dir = $this->get_run_dir( $run_id );
        if ( $run_dir === null ) {
            $result['skipped'][] = [ 'reason' => 'invalid_run_id', 'run_id' => $run_id ];
            return $result;
        }

        $improve_dir = trailingslashit( $run_dir ) . 'improvements';
        if ( ! is_dir( $improve_dir ) ) {
            $result['skipped'][] = [ 'reason' => 'no_improvements_dir', 'run_id' => $run_id ];
            return $result;
        }

        // 改善ケース & 元 cases（intent 取得用）
        $cases_by_id = $this->load_cases_map( $run_dir );
        $rollback_log = $this->load_recent_rollback_intents();

        $case_files = glob( $improve_dir . '/qa_*.json' );
        if ( ! is_array( $case_files ) || empty( $case_files ) ) {
            $result['skipped'][] = [ 'reason' => 'no_case_files', 'run_id' => $run_id ];
            return $result;
        }

        // 最良候補を intent ごとに 1 件に絞る（final_score - initial_score 降順、同順位は final_score 降順）
        $candidates = []; // intent => candidate record

        foreach ( $case_files as $path ) {
            $data = $this->read_json( $path );
            if ( ! is_array( $data ) ) {
                continue;
            }
            $case_id   = (string) ( $data['case_id'] ?? '' );
            $revisions = is_array( $data['revisions'] ?? null ) ? $data['revisions'] : [];

            if ( empty( $revisions ) ) {
                continue;
            }

            // passed = 最終スコアが initial を十分上回る && revision に error なし
            $initial = (int) ( $revisions[0]['score_total'] ?? 0 );
            $last    = end( $revisions );
            if ( ! is_array( $last ) ) { continue; }

            $final   = (int) ( $last['score_total'] ?? 0 );
            $delta   = $final - $initial;
            $passed  = ( $data['stop_reason'] ?? '' ) === 'passed'
                    || ( $data['stop_reason'] ?? '' ) === 'passed_no_critical';

            $case_row = $cases_by_id[ $case_id ] ?? [];
            $intent   = Mimamori_QA_Intent_Resolver::from_case_row( $case_row );

            $reject_reason = $this->pre_reject_reason(
                $intent, $last, $initial, $final, $delta, $passed, $rollback_log
            );
            if ( $reject_reason !== '' ) {
                $result['rejected'][] = [
                    'case_id' => $case_id,
                    'intent'  => $intent,
                    'reason'  => $reject_reason,
                    'delta'   => $delta,
                ];
                continue;
            }

            $score_for_rank = $delta * 1000 + $final; // delta 優先、同率なら final
            $existing = $candidates[ $intent ] ?? null;
            if ( $existing === null || $score_for_rank > $existing['rank'] ) {
                $candidates[ $intent ] = [
                    'rank'        => $score_for_rank,
                    'intent'      => $intent,
                    'case_id'     => $case_id,
                    'run_id'      => $run_id,
                    'revision_no' => (int) ( $last['revision_no'] ?? 0 ),
                    'initial'     => $initial,
                    'final'       => $final,
                    'delta'       => $delta,
                    'revision'    => $last,
                ];
            }
        }

        // delta 降順で MAX_PROMOTIONS_PER_RUN 件まで
        usort( $candidates, static fn( $a, $b ) => $b['rank'] <=> $a['rank'] );
        $candidates = array_slice( $candidates, 0, self::MAX_PROMOTIONS_PER_RUN );

        foreach ( $candidates as $cand ) {
            try {
                $payload = Mimamori_QA_Prompt_Registry::sanitize_revision_payload(
                    array_merge( $cand['revision'], [
                        'score_before' => $cand['initial'],
                        'score_after'  => $cand['final'],
                    ] )
                );

                // 最終バリデーション（Registry::promote でも見るが、ここで早期排除）
                if ( $payload['prompt_addendum'] === '' ) {
                    throw new \RuntimeException( 'empty_addendum' );
                }
                if ( Mimamori_QA_Prompt_Registry::contains_forbidden_word( $payload['prompt_addendum'] ) ) {
                    throw new \RuntimeException( 'forbidden_word' );
                }

                $new_version = Mimamori_QA_Prompt_Registry::promote(
                    $cand['intent'],
                    array_merge( $cand['revision'], [
                        'score_before' => $cand['initial'],
                        'score_after'  => $cand['final'],
                    ] ),
                    [
                        'run_id'      => $cand['run_id'],
                        'case_id'     => $cand['case_id'],
                        'revision_no' => $cand['revision_no'],
                    ],
                    0, // auto promote → user_id=0
                    null
                );

                $result['promoted'][] = [
                    'intent'      => $cand['intent'],
                    'case_id'     => $cand['case_id'],
                    'revision_no' => $cand['revision_no'],
                    'initial'     => $cand['initial'],
                    'final'       => $cand['final'],
                    'delta'       => $cand['delta'],
                    'new_version' => $new_version,
                ];
                $this->log( 'promote', end( $result['promoted'] ) );
            } catch ( \Throwable $e ) {
                $result['rejected'][] = [
                    'case_id' => $cand['case_id'],
                    'intent'  => $cand['intent'],
                    'reason'  => 'promote_error:' . $e->getMessage(),
                    'delta'   => $cand['delta'],
                ];
                $this->log( 'promote_fail', [
                    'case_id' => $cand['case_id'],
                    'intent'  => $cand['intent'],
                    'error'   => $e->getMessage(),
                ] );
            }
        }

        return $result;
    }

    /**
     * 昇格前の却下条件チェック。
     * 却下理由を文字列で返す（空なら OK）。
     */
    private function pre_reject_reason(
        string $intent,
        array $revision,
        int $initial,
        int $final,
        int $delta,
        bool $passed,
        array $rollback_log
    ): string {
        if ( $intent === '' || $intent === Mimamori_QA_Prompt_Registry::GLOBAL_KEY ) {
            return 'intent_unknown_or_global';
        }
        if ( ! $passed ) {
            return 'not_passed';
        }
        if ( $delta < self::MIN_SCORE_DELTA ) {
            return sprintf( 'delta<%d', self::MIN_SCORE_DELTA );
        }
        if ( $final < self::MIN_FINAL_SCORE ) {
            return sprintf( 'final_score<%d', self::MIN_FINAL_SCORE );
        }

        // triage に critical が残っていればダメ
        $triage = is_array( $revision['triage'] ?? null ) ? $revision['triage'] : [];
        foreach ( $triage as $t ) {
            $sev = (string) ( $t['severity'] ?? '' );
            if ( $sev === 'critical' ) {
                return 'triage_critical';
            }
        }

        // addendum empty → Diagnosis/Tuner がパラメータ調整のみ行った場合。addendum がなければ昇格しない
        if ( trim( (string) ( $revision['prompt_addendum'] ?? '' ) ) === '' ) {
            return 'empty_addendum';
        }

        // 直近 rollback されていないか
        if ( isset( $rollback_log[ $intent ] ) ) {
            return sprintf( 'cooldown<%dd_since_rollback', self::COOLDOWN_DAYS );
        }

        return '';
    }

    // =========================================================
    // Data loaders
    // =========================================================

    /**
     * cases.jsonl を読み込んで id => row 辞書にする。
     */
    private function load_cases_map( string $run_dir ): array {
        $path = trailingslashit( $run_dir ) . 'cases.jsonl';
        if ( ! file_exists( $path ) ) {
            return [];
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file
        $lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! is_array( $lines ) ) {
            return [];
        }
        $out = [];
        foreach ( $lines as $line ) {
            $row = json_decode( $line, true );
            if ( is_array( $row ) && isset( $row['id'] ) ) {
                $out[ (string) $row['id'] ] = $row;
            }
        }
        return $out;
    }

    /**
     * /tmp/gcrev_qa_rollback.log を読み、直近 COOLDOWN_DAYS 日以内に
     * rollback された intent を抽出する。
     *
     * 厳密な構造ログではないため、ログ行に含まれる intent 名を grep する。
     * 昇格全体を止めるのは過剰なので「該当 intent のみ昇格却下」とする。
     */
    private function load_recent_rollback_intents(): array {
        $path = '/tmp/gcrev_qa_rollback.log';
        if ( ! file_exists( $path ) ) {
            return [];
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file
        $lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! is_array( $lines ) ) {
            return [];
        }
        $cutoff = time() - self::COOLDOWN_DAYS * 86400;
        $hit    = [];
        foreach ( $lines as $line ) {
            // 先頭の日付を抽出
            if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m ) ) {
                continue;
            }
            $ts = strtotime( $m[1] );
            if ( $ts === false || $ts < $cutoff ) {
                continue;
            }
            // intent=xxx パターンを拾う
            if ( preg_match_all( '/"intent"\s*:\s*"([^"]+)"/', $line, $im ) ) {
                foreach ( $im[1] as $intent ) {
                    $hit[ $intent ] = true;
                }
            }
        }
        return $hit;
    }

    /**
     * run_id を sanitize して実ディレクトリを返す。存在しなければ null。
     */
    private function get_run_dir( string $run_id ): ?string {
        if ( ! preg_match( '/^\d{8}_\d{6}$/', $run_id ) ) {
            return null;
        }
        $upload  = wp_upload_dir();
        $base    = trailingslashit( $upload['basedir'] ) . self::OUTPUT_BASE;
        $run_dir = trailingslashit( $base ) . $run_id;
        if ( ! is_dir( $run_dir ) ) {
            return null;
        }
        return $run_dir;
    }

    private function read_json( string $path ): ?array {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = @file_get_contents( $path );
        if ( $raw === false ) {
            return null;
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    private function log( string $event, array $ctx = [] ): void {
        $line = sprintf(
            "%s event=%s %s\n",
            date( 'Y-m-d H:i:s' ),
            $event,
            wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE )
        );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents( '/tmp/gcrev_qa_autopromote.log', $line, FILE_APPEND );
    }
}
