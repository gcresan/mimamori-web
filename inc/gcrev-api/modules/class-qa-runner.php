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
 * @package GCREV_INSIGHT
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
     * 1問を実行する（リトライ1回付き）。
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

        return $result;
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
        ];
    }
}
