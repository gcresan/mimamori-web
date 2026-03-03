<?php
// FILE: inc/gcrev-api/modules/class-qa-runner.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Runner
 *
 * QAバッチの実行オーケストレーター。
 * 質問配列を受け取り、1問ずつ mimamori_process_chat_with_trace() を呼び出す。
 *
 * - レートリミット確認 → 高負荷時は追加スリープ
 * - cases.jsonl にインクリメンタル書き出し
 * - 失敗時はリトライ1回
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Mimamori_QA_Runner {

    /** 1質問あたりの全体タイムアウト（秒） */
    private const QUESTION_TIMEOUT = 120;

    /** レートリミッター高負荷閾値（300/min 超で追加スリープ） */
    private const RATE_LIMIT_THRESHOLD = 300;

    /** 高負荷時の追加スリープ（秒） */
    private const RATE_LIMIT_EXTRA_SLEEP = 5;

    /** @var int ユーザーID */
    private int $user_id;

    /** @var int リクエスト間スリープ（ミリ秒） */
    private int $sleep_ms;

    /**
     * @param int $user_id  WordPress ユーザーID
     * @param int $sleep_ms リクエスト間スリープ（ms）
     */
    public function __construct( int $user_id, int $sleep_ms = 2000 ) {
        $this->user_id  = $user_id;
        $this->sleep_ms = max( 500, $sleep_ms ); // 最低500ms
    }

    /**
     * 質問配列を実行し、結果配列を返す。
     *
     * @param  array  $questions 質問配列（Mimamori_QA_Question_Generator::generate() の出力）
     * @param  string $out_dir   出力ディレクトリ（cases.jsonl のインクリメンタル書き出し用）
     * @return array  結果配列
     */
    public function run( array $questions, string $out_dir ): array {
        $results    = [];
        $total      = count( $questions );
        $jsonl_path = trailingslashit( $out_dir ) . 'cases.jsonl';
        $progress   = \WP_CLI\Utils\make_progress_bar( 'Running QA', $total );

        foreach ( $questions as $i => $question ) {
            $progress->tick();

            // レートリミット確認
            $this->check_rate_limit();

            // 実行
            $case = $this->execute_question( $question );

            // cases.jsonl にインクリメンタル書き出し（採点前の生データ）
            $this->append_jsonl( $jsonl_path, $case );

            $results[] = $case;

            // スリープ（最後の質問では不要）
            if ( $i < $total - 1 ) {
                usleep( $this->sleep_ms * 1000 );
            }
        }

        $progress->finish();
        WP_CLI::log( sprintf( 'Executed %d / %d questions.', count( $results ), $total ) );

        return $results;
    }

    /**
     * 1問を実行する（リトライ1回 + マルチターン自動フォローアップ付き）。
     *
     * ParamResolver が確認質問を返した場合、自動でフォローアップ回答を
     * 生成して Turn 2 を実行する。これにより実データ回答の品質もテストできる。
     *
     * @param  array $question 質問データ
     * @return array 結果
     */
    private function execute_question( array $question ): array {
        $result = $this->call_chat( $question );

        // 失敗時にリトライ1回
        if ( ! $result['success'] ) {
            WP_CLI::warning( sprintf(
                '[%s] Failed: %s — Retrying...',
                $question['id'],
                $result['error'] ?? 'unknown'
            ) );
            usleep( 1000 * 1000 ); // 1秒待機
            $result = $this->call_chat( $question );

            if ( ! $result['success'] ) {
                WP_CLI::warning( sprintf( '[%s] Retry also failed.', $question['id'] ) );
            }
        }

        // === マルチターン: PARAM_GATE 検出時に自動フォローアップ ===
        $param_gate = $result['trace']['param_gate'] ?? [];
        if ( $result['success'] && ! empty( $param_gate ) ) {
            $followup = $this->build_followup( $question, $result );
            if ( $followup !== null ) {
                WP_CLI::log( sprintf(
                    '[%s] PARAM_GATE detected (missing: %s) → auto follow-up',
                    $question['id'],
                    implode( ', ', $param_gate )
                ) );
                usleep( 1000 * 1000 ); // 1秒待機

                $turn2_result = $this->call_chat( $followup );

                // Turn 2 失敗時にリトライ1回
                if ( ! $turn2_result['success'] ) {
                    usleep( 1000 * 1000 );
                    $turn2_result = $this->call_chat( $followup );
                }

                // Turn 1 データを保存、Turn 2 を主結果として返す
                $turn2_result['turn1'] = [
                    'raw_text'    => $result['raw_text'],
                    'structured'  => $result['structured'],
                    'trace'       => $this->slim_trace( $result['trace'] ?? [] ),
                    'duration_ms' => $result['duration_ms'],
                ];
                $turn2_result['is_followup']  = true;
                $turn2_result['duration_ms'] += $result['duration_ms'];
                $turn2_result['started_at']   = $result['started_at'];

                return $turn2_result;
            }
        }

        return $result;
    }

    /**
     * PARAM_GATE 結果から自動フォローアップ質問を構築する。
     *
     * missing パラメータに基づいてシミュレーション回答を生成し、
     * 会話履歴付きの新しい質問データを返す。
     *
     * @param  array $question 元の質問データ
     * @param  array $result   Turn 1 の結果
     * @return array|null      フォローアップ質問データ（構築不能なら null）
     */
    private function build_followup( array $question, array $result ): ?array {
        $missing  = $result['trace']['param_gate'] ?? [];
        $raw_text = $result['raw_text'] ?? '';

        if ( empty( $missing ) || $raw_text === '' ) {
            return null;
        }

        // 確認質問テキストを取得（JSONの場合はデコード）
        $clarification_text = $raw_text;
        if ( mb_substr( $raw_text, 0, 1 ) === '{' ) {
            $decoded = json_decode( $raw_text, true );
            if ( is_array( $decoded ) && isset( $decoded['text'] ) ) {
                $clarification_text = $decoded['text'];
            }
        }

        // シミュレーション回答を構築
        // 標準マッピング: period→①(今月), metric→②(検索), comparison→①(前月)
        $answer_parts = [];
        foreach ( $missing as $param ) {
            switch ( $param ) {
                case 'period':
                    $answer_parts[] = '①';
                    break;
                case 'metric':
                    // 元の質問カテゴリに基づく選択
                    $cat = $question['category'] ?? '';
                    $answer_parts[] = ( $cat === 'kpi' ) ? '②' : '①';
                    break;
                case 'comparison_target':
                    $answer_parts[] = '①';
                    break;
            }
        }

        if ( empty( $answer_parts ) ) {
            return null;
        }

        $followup_answer = implode( ' ', $answer_parts );

        // 会話履歴を構築
        $history = [
            [ 'role' => 'user',      'content' => $question['message'] ],
            [ 'role' => 'assistant', 'content' => $clarification_text ],
        ];

        return [
            'id'              => $question['id'] . '_t2',
            'category'        => $question['category'],
            'message'         => $followup_answer,
            'page_type'       => $question['page_type'],
            'current_page'    => $question['current_page'],
            'history'         => $history,
            'section_context' => null,
        ];
    }

    /**
     * mimamori_process_chat_with_trace() を呼び出す。
     *
     * @param  array $question 質問データ
     * @return array 結果
     */
    private function call_chat( array $question ): array {
        $started_at = wp_date( 'Y-m-d H:i:s' );
        $start_time = microtime( true );

        $data = [
            'message'        => $question['message'],
            'history'        => $question['history'],
            'currentPage'    => $question['current_page'],
            'sectionContext' => $question['section_context'],
        ];

        try {
            $chat_result = mimamori_process_chat_with_trace( $data, $this->user_id );
        } catch ( \Throwable $e ) {
            $elapsed = (int) ( ( microtime( true ) - $start_time ) * 1000 );
            return [
                'question'    => $question,
                'success'     => false,
                'error'       => $e->getMessage(),
                'raw_text'    => '',
                'structured'  => [],
                'trace'       => [],
                'duration_ms' => $elapsed,
                'started_at'  => $started_at,
                'ended_at'    => wp_date( 'Y-m-d H:i:s' ),
            ];
        }

        $elapsed = (int) ( ( microtime( true ) - $start_time ) * 1000 );

        return [
            'question'    => $question,
            'success'     => $chat_result['success'],
            'error'       => $chat_result['error'] ?? null,
            'raw_text'    => $chat_result['raw_text'] ?? '',
            'structured'  => $chat_result['structured'] ?? [],
            'trace'       => $chat_result['trace'] ?? [],
            'duration_ms' => $elapsed,
            'started_at'  => $started_at,
            'ended_at'    => wp_date( 'Y-m-d H:i:s' ),
        ];
    }

    /**
     * レートリミットを確認し、高負荷時は追加スリープ。
     */
    private function check_rate_limit(): void {
        if ( ! class_exists( 'Gcrev_Rate_Limiter' ) ) {
            return;
        }

        $ga4_count = Gcrev_Rate_Limiter::get_current_count( 'ga4' );
        $gsc_count = Gcrev_Rate_Limiter::get_current_count( 'gsc' );

        if ( $ga4_count > self::RATE_LIMIT_THRESHOLD || $gsc_count > self::RATE_LIMIT_THRESHOLD ) {
            WP_CLI::warning( sprintf(
                'Rate limit high (GA4: %d, GSC: %d) — sleeping %ds extra',
                $ga4_count, $gsc_count, self::RATE_LIMIT_EXTRA_SLEEP
            ) );
            sleep( self::RATE_LIMIT_EXTRA_SLEEP );
        }
    }

    /**
     * JSONL ファイルに1行追記する。
     *
     * @param string $path ファイルパス
     * @param array  $case ケースデータ
     */
    private function append_jsonl( string $path, array $case ): void {
        // トレースを軽量化（大きなデータはサマリーのみ）
        $slim_case = [
            'id'          => $case['question']['id'] ?? '',
            'category'    => $case['question']['category'] ?? '',
            'message'     => $case['question']['message'] ?? '',
            'page_type'   => $case['question']['page_type'] ?? '',
            'success'     => $case['success'],
            'error'       => $case['error'] ?? null,
            'duration_ms' => $case['duration_ms'],
            'raw_text'    => $case['raw_text'],
            'structured'  => $case['structured'],
            'trace'       => $this->slim_trace( $case['trace'] ?? [] ),
            'started_at'  => $case['started_at'],
            'ended_at'    => $case['ended_at'],
            // マルチターン情報
            'is_followup' => $case['is_followup'] ?? false,
            'turn1'       => $case['turn1'] ?? null,
        ];

        $json = wp_json_encode( $slim_case, JSON_UNESCAPED_UNICODE );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $path, $json . "\n", FILE_APPEND | LOCK_EX );
    }

    /**
     * トレースを軽量化する。
     *
     * @param  array $trace 元トレース
     * @return array 軽量版
     */
    private function slim_trace( array $trace ): array {
        return [
            'intent'                => $trace['intent'] ?? [],
            'page_type'             => $trace['page_type'] ?? '',
            'sources'               => $trace['sources'] ?? [],
            'deterministic_queries' => $trace['deterministic_queries'] ?? [],
            'flex_results_count'    => is_array( $trace['flex_results'] ?? null ) ? count( $trace['flex_results'] ) : 0,
            'flex_text_length'      => mb_strlen( (string) ( $trace['flex_text'] ?? '' ) ),
            'digest_length'         => mb_strlen( (string) ( $trace['digest'] ?? '' ) ),
            'planner_queries'       => $trace['planner_queries'] ?? [],
            'enrichment_data_count' => is_array( $trace['enrichment_data'] ?? null ) ? count( $trace['enrichment_data'] ) : 0,
            'enrichment_text_length'=> mb_strlen( (string) ( $trace['enrichment_text'] ?? '' ) ),
            'context_blocks_length' => mb_strlen( (string) ( $trace['context_blocks'] ?? '' ) ),
            'instructions_length'   => $trace['instructions_length'] ?? 0,
            'model'                 => $trace['model'] ?? '',
            // 採点用に実テキストも保持
            'digest'                => $trace['digest'] ?? '',
            'flex_text'             => $trace['flex_text'] ?? '',
            'enrichment_text'       => $trace['enrichment_text'] ?? '',
            'context_blocks'        => $trace['context_blocks'] ?? '',
            // マルチターン用
            'param_gate'            => $trace['param_gate'] ?? [],
            'followup_resolved'     => $trace['followup_resolved'] ?? null,
        ];
    }
}
