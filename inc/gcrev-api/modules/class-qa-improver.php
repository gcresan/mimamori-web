<?php
// FILE: inc/gcrev-api/modules/class-qa-improver.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Improver
 *
 * QA採点結果に対して自動改善ループを実行するオーケストレーター。
 *
 * フロー:
 *   1. 初回スコア確認 → 合格なら即終了
 *   2. Diagnosis で減点理由抽出
 *   3. PromptTuner でオーバーライド生成
 *   4. Runner.execute_single() で再実行
 *   5. 再採点 + Triage
 *   6. 停止条件チェック
 *   7. 未合格なら 2 へ
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Mimamori_QA_Improver {

    /** @var Mimamori_QA_Runner */
    private Mimamori_QA_Runner $runner;

    /** @var Mimamori_QA_Scorer */
    private Mimamori_QA_Scorer $scorer;

    /** @var Mimamori_QA_Triage */
    private Mimamori_QA_Triage $triager;

    /** @var Mimamori_QA_Diagnosis */
    private Mimamori_QA_Diagnosis $diagnosis;

    /** @var Mimamori_QA_Prompt_Tuner */
    private Mimamori_QA_Prompt_Tuner $tuner;

    /** @var array 設定 */
    private array $config;

    /** デフォルト設定 */
    private const DEFAULTS = [
        'pass_score'      => 100,
        'pass_mode'       => 'total_score',  // 'total_score' | 'no_critical'
        'max_revisions'   => 5,
        'min_improvement' => 2,
        'stale_limit'     => 2,
        'dry_run'         => false,
        'sleep_ms'        => 2000,
    ];

    /**
     * @param Mimamori_QA_Runner $runner  実行エンジン
     * @param array              $config  設定オーバーライド
     */
    public function __construct( Mimamori_QA_Runner $runner, array $config = [] ) {
        $this->runner    = $runner;
        $this->scorer    = new Mimamori_QA_Scorer();
        $this->triager   = new Mimamori_QA_Triage();
        $this->diagnosis = new Mimamori_QA_Diagnosis();
        $this->tuner     = new Mimamori_QA_Prompt_Tuner();
        $this->config    = array_merge( self::DEFAULTS, $config );
    }

    /**
     * 1ケースの改善ループを実行する。
     *
     * @param  array $case 採点済みケースデータ（score, triage, question 付き）
     * @return array 改善結果 { revisions, stop_reason, passed, initial_score, final_score }
     */
    public function improve( array $case ): array {
        $revisions    = [];
        $stop_reason  = 'unknown';
        $overrides    = [];
        $prev_top_key = '';
        $stale_count  = 0;

        // Rev 0: 初回スコア記録
        $current_score = $case['score']['total'] ?? 0;
        $revisions[] = $this->build_revision( 0, $case, [], [] );

        // 合格チェック
        if ( $this->is_passed( $case ) ) {
            return [
                'revisions'     => $revisions,
                'stop_reason'   => 'already_passed',
                'passed'        => true,
                'initial_score' => $current_score,
                'final_score'   => $current_score,
            ];
        }

        // 改善ループ
        for ( $rev = 1; $rev <= $this->config['max_revisions']; $rev++ ) {
            // 1. 減点理由の構造化
            $actions = $this->diagnosis->diagnose( $case );

            if ( empty( $actions ) ) {
                $stop_reason = 'no_actions';
                break;
            }

            // 2. stale 検出（同一トップ理由の連続）
            $top_key = $this->diagnosis->top_action_key( $actions );
            if ( $top_key === $prev_top_key ) {
                $stale_count++;
                if ( $stale_count >= $this->config['stale_limit'] ) {
                    $stop_reason = 'stale';
                    break;
                }
            } else {
                $stale_count = 0;
            }
            $prev_top_key = $top_key;

            // 3. オーバーライド生成
            $tune_result = $this->tuner->tune( $actions, $overrides );
            $overrides   = $tune_result['overrides'];
            $changes     = $tune_result['changes'];

            if ( empty( $changes ) ) {
                $stop_reason = 'no_new_changes';
                break;
            }

            // dry-run: 改善案の記録のみ
            if ( $this->config['dry_run'] ) {
                $revisions[] = $this->build_dry_run_revision( $rev, $case, $actions, $changes, $overrides );
                $stop_reason = 'dry_run';
                continue;
            }

            // 4. 再実行
            $question = $case['question'] ?? [];
            if ( $rev > 1 ) {
                usleep( $this->config['sleep_ms'] * 1000 );
            }

            try {
                $new_result = $this->runner->execute_single( $question, $overrides );
            } catch ( \Throwable $e ) {
                $revisions[] = $this->build_error_revision( $rev, $e->getMessage(), $actions, $changes, $overrides );
                $stop_reason = 'error';
                break;
            }

            // 5. 再採点 + Triage
            if ( $new_result['success'] ) {
                $new_result['question'] = $question;
                $new_result['score']    = $this->scorer->score( $new_result );
                $new_result['triage']   = $this->triager->classify( $new_result );
            } else {
                $new_result['score'] = [
                    'total'           => 0,
                    'data_integrity'  => 0,
                    'period_accuracy' => 0,
                    'honesty'         => 0,
                    'structure'       => 0,
                    'details'         => [ 'error' => $new_result['error'] ?? 'execution failed' ],
                ];
                $new_result['triage'] = $this->triager->classify( $new_result );
            }

            $new_score = $new_result['score']['total'] ?? 0;
            $revisions[] = $this->build_revision( $rev, $new_result, $changes, $overrides );

            // 6. 合格チェック
            if ( $this->is_passed( $new_result ) ) {
                $stop_reason = $this->config['pass_mode'] === 'no_critical' ? 'passed_no_critical' : 'passed';
                $case = $new_result;
                break;
            }

            // 7. 改善幅チェック
            $improvement = $new_score - $current_score;
            if ( $rev >= 2 && $improvement < $this->config['min_improvement'] ) {
                $stop_reason = 'plateau';
                $case = $new_result;
                break;
            }

            $current_score = $new_score;
            $case          = $new_result;
        }

        if ( $stop_reason === 'unknown' ) {
            $stop_reason = 'max_revisions';
        }

        $initial = $revisions[0]['score_total'] ?? 0;
        $final   = end( $revisions )['score_total'] ?? 0;
        $passed  = in_array( $stop_reason, [ 'passed', 'passed_no_critical', 'already_passed' ], true );

        return [
            'revisions'     => $revisions,
            'stop_reason'   => $stop_reason,
            'passed'        => $passed,
            'initial_score' => $initial,
            'final_score'   => $final,
        ];
    }

    /**
     * 合格判定
     */
    private function is_passed( array $case ): bool {
        $total = $case['score']['total'] ?? 0;

        if ( $this->config['pass_mode'] === 'no_critical' ) {
            if ( $total < 95 ) return false;
            $triage = $case['triage'] ?? [];
            foreach ( $triage as $t ) {
                $sev = $t['severity'] ?? '';
                if ( in_array( $sev, [ 'critical', 'major' ], true ) ) {
                    return false;
                }
            }
            return true;
        }

        return $total >= $this->config['pass_score'];
    }

    /**
     * リビジョンデータを構築する。
     */
    private function build_revision( int $rev_no, array $case, array $changes, array $overrides ): array {
        $score = $case['score'] ?? [];

        return [
            'revision_no'     => $rev_no,
            'score_total'     => $score['total'] ?? 0,
            'data_integrity'  => $score['data_integrity'] ?? 0,
            'period_accuracy' => $score['period_accuracy'] ?? 0,
            'honesty'         => $score['honesty'] ?? 0,
            'structure'       => $score['structure'] ?? 0,
            'details'         => $score['details'] ?? [],
            'triage'          => $case['triage'] ?? [],
            'changes'         => $changes,
            'overrides_summary' => $this->tuner->summarize( $overrides ),
            'answer_excerpt'  => mb_strimwidth( $case['raw_text'] ?? '', 0, 200, '…' ),
            'executed_at'     => wp_date( 'Y-m-d H:i:s' ),
        ];
    }

    /**
     * dry-run 用リビジョン
     */
    private function build_dry_run_revision( int $rev_no, array $case, array $actions, array $changes, array $overrides ): array {
        $rev = $this->build_revision( $rev_no, $case, $changes, $overrides );
        $rev['dry_run']          = true;
        $rev['proposed_actions'] = array_map( static fn( $a ) => $a['action'] . ' (' . $a['reason'] . ')', $actions );
        return $rev;
    }

    /**
     * エラー用リビジョン
     */
    private function build_error_revision( int $rev_no, string $error, array $actions, array $changes, array $overrides ): array {
        return [
            'revision_no'       => $rev_no,
            'score_total'       => 0,
            'data_integrity'    => 0,
            'period_accuracy'   => 0,
            'honesty'           => 0,
            'structure'         => 0,
            'details'           => [ 'error' => $error ],
            'triage'            => [],
            'changes'           => $changes,
            'overrides_summary' => $this->tuner->summarize( $overrides ),
            'answer_excerpt'    => '',
            'executed_at'       => wp_date( 'Y-m-d H:i:s' ),
            'error'             => $error,
        ];
    }
}
