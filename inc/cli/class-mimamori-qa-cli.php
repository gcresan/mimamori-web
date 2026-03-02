<?php
// FILE: inc/cli/class-mimamori-qa-cli.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_CLI
 *
 * WP-CLI コマンド: AIチャット自動QAバッチ。
 *
 * サブコマンド:
 *   wp mimamori qa:run   --user_id=X [--n=20] [--seed=YYYYMMDD] …  QA実行
 *   wp mimamori qa:list                                             最近のQA実行一覧
 *   wp mimamori qa:show  --run=YYYYMMDD_HHMMSS                     特定実行の詳細表示
 *
 * @package GCREV_INSIGHT
 * @since   3.0.0
 */
class Mimamori_QA_CLI {

    /** QA 出力ベースディレクトリ */
    private const OUTPUT_BASE = 'mimamori/qa_runs';

    // =========================================================
    // qa:run — QA バッチ実行
    // =========================================================

    /**
     * AIチャットの自動QAを実行する。
     *
     * ## OPTIONS
     *
     * --user_id=<id>
     * : GA4設定済みユーザーID（必須）
     *
     * [--n=<count>]
     * : 質問数（デフォルト: 20）
     *
     * [--seed=<seed>]
     * : 乱数シード（デフォルト: 当日日付 YYYYMMDD）
     *
     * [--mode=<mode>]
     * : 実行モード: quick(5問) / nightly(100問) / custom(--n使用)。デフォルト: custom
     *
     * [--category=<cat>]
     * : 特定カテゴリのみ: kpi / trend / comparison / page / general
     *
     * [--dry-run]
     * : 質問生成のみ（API実行しない）
     *
     * [--sleep=<ms>]
     * : リクエスト間スリープ(ms)。デフォルト: 2000
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:run --user_id=5 --n=20
     *     wp mimamori qa:run --user_id=5 --mode=quick --dry-run
     *     wp mimamori qa:run --user_id=5 --mode=nightly --seed=20260302
     *     wp mimamori qa:run --user_id=5 --category=kpi --n=10
     *
     * @subcommand qa:run
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function qa_run( array $args, array $assoc_args ): void {

        // --- パラメータ解析 ---
        $user_id  = $this->require_user_id( $assoc_args );
        $mode     = $assoc_args['mode'] ?? 'custom';
        $dry_run  = isset( $assoc_args['dry-run'] );
        $sleep_ms = (int) ( $assoc_args['sleep'] ?? 2000 );
        $category = $assoc_args['category'] ?? null;

        $n = match ( $mode ) {
            'quick'   => 5,
            'nightly' => 100,
            default   => (int) ( $assoc_args['n'] ?? 20 ),
        };

        $seed = (int) ( $assoc_args['seed'] ?? gmdate( 'Ymd' ) );

        // --- セーフティチェック ---
        $this->safety_checks( $user_id );

        // --- カテゴリ検証 ---
        if ( $category !== null ) {
            $valid_cats = Mimamori_QA_Question_Generator::get_categories();
            if ( ! in_array( $category, $valid_cats, true ) ) {
                WP_CLI::error( sprintf(
                    'Invalid category: %s. Valid: %s',
                    $category,
                    implode( ', ', $valid_cats )
                ) );
            }
        }

        WP_CLI::log( '=== Mimamori QA Batch ===' );
        WP_CLI::log( sprintf( 'User: %d | Questions: %d | Seed: %d | Mode: %s | Category: %s',
            $user_id, $n, $seed, $mode, $category ?? 'mixed'
        ) );

        // --- 質問生成 ---
        $generator = new Mimamori_QA_Question_Generator( $seed );
        $questions = $generator->generate( $n, $category );

        WP_CLI::log( sprintf( 'Generated %d questions.', count( $questions ) ) );

        if ( $dry_run ) {
            WP_CLI::log( '' );
            WP_CLI::log( '--- Dry Run: Questions Only ---' );
            $table = [];
            foreach ( $questions as $q ) {
                $table[] = [
                    'ID'       => $q['id'],
                    'Category' => $q['category'],
                    'Message'  => mb_strimwidth( $q['message'], 0, 50, '…' ),
                    'PageType' => $q['page_type'],
                ];
            }
            WP_CLI\Utils\format_items( 'table', $table, [ 'ID', 'Category', 'Message', 'PageType' ] );
            WP_CLI::success( 'Dry run complete. No API calls were made.' );
            return;
        }

        // --- 出力ディレクトリ作成 ---
        $run_id  = wp_date( 'Ymd_His' );
        $out_dir = $this->get_output_dir( $run_id );

        if ( ! wp_mkdir_p( $out_dir ) ) {
            WP_CLI::error( "Failed to create output directory: {$out_dir}" );
        }

        // ロックファイル
        $lock_file = $out_dir . '/.lock';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $lock_file, (string) getmypid() );

        // --- ランナー実行 ---
        $this->load_qa_classes();

        $runner  = new Mimamori_QA_Runner( $user_id, $sleep_ms );
        $results = $runner->run( $questions, $out_dir );

        // --- 採点 ---
        WP_CLI::log( '' );
        WP_CLI::log( 'Scoring...' );
        $scorer = new Mimamori_QA_Scorer();

        foreach ( $results as &$case ) {
            if ( $case['success'] ) {
                $case['score'] = $scorer->score( $case );
            } else {
                $case['score'] = [
                    'total'           => 0,
                    'data_integrity'  => 0,
                    'period_accuracy' => 0,
                    'honesty'         => 0,
                    'structure'       => 0,
                    'details'         => [ 'error' => $case['error'] ?? 'execution failed' ],
                ];
            }
        }
        unset( $case );

        // --- 分類 ---
        WP_CLI::log( 'Triaging failures...' );
        $triager = new Mimamori_QA_Triage();

        foreach ( $results as &$case ) {
            $case['triage'] = $triager->classify( $case );
        }
        unset( $case );

        // --- レポート出力 ---
        WP_CLI::log( 'Writing reports...' );
        $writer = new Mimamori_QA_Report_Writer();
        $meta   = [
            'run_id'     => $run_id,
            'user_id'    => $user_id,
            'n'          => $n,
            'seed'       => $seed,
            'mode'       => $mode,
            'category'   => $category,
            'sleep_ms'   => $sleep_ms,
            'started_at' => $results[0]['started_at'] ?? '',
            'ended_at'   => end( $results )['ended_at'] ?? '',
            'model'      => $results[0]['trace']['model'] ?? 'unknown',
        ];

        $writer->write( $out_dir, $meta, $results );

        // ロックファイル削除
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        @unlink( $lock_file );

        // --- サマリー表示 ---
        WP_CLI::log( '' );
        $this->print_summary( $results );

        WP_CLI::success( sprintf( 'QA run complete. Output: %s', $out_dir ) );
    }

    // =========================================================
    // qa:list — 最近の QA 実行一覧
    // =========================================================

    /**
     * 最近のQA実行を一覧表示する。
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:list
     *
     * @subcommand qa:list
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function qa_list( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

        $base = $this->get_base_dir();

        if ( ! is_dir( $base ) ) {
            WP_CLI::log( 'No QA runs found.' );
            return;
        }

        $dirs = glob( $base . '/*', GLOB_ONLYDIR );

        if ( empty( $dirs ) ) {
            WP_CLI::log( 'No QA runs found.' );
            return;
        }

        // 新しい順に並べ替え
        rsort( $dirs );

        $table = [];
        foreach ( array_slice( $dirs, 0, 20 ) as $dir ) {
            $run_id    = basename( $dir );
            $meta_file = $dir . '/meta.json';
            $meta      = [];

            if ( file_exists( $meta_file ) ) {
                $raw = file_get_contents( $meta_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
                if ( $raw !== false ) {
                    $meta = json_decode( $raw, true ) ?? [];
                }
            }

            $table[] = [
                'Run ID'    => $run_id,
                'User'      => $meta['user_id'] ?? '?',
                'Questions' => $meta['n'] ?? '?',
                'Avg Score' => isset( $meta['avg_score'] ) ? number_format( $meta['avg_score'], 1 ) : '?',
                'Mode'      => $meta['mode'] ?? '?',
            ];
        }

        WP_CLI\Utils\format_items( 'table', $table, [ 'Run ID', 'User', 'Questions', 'Avg Score', 'Mode' ] );
    }

    // =========================================================
    // qa:show — 特定実行の詳細表示
    // =========================================================

    /**
     * 特定のQA実行結果を詳細表示する。
     *
     * ## OPTIONS
     *
     * --run=<run_id>
     * : 実行ID（YYYYMMDD_HHMMSS 形式）
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:show --run=20260302_030000
     *
     * @subcommand qa:show
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function qa_show( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

        if ( ! isset( $assoc_args['run'] ) ) {
            WP_CLI::error( '--run=<YYYYMMDD_HHMMSS> is required.' );
        }

        $run_id    = sanitize_file_name( $assoc_args['run'] );
        $out_dir   = $this->get_output_dir( $run_id );
        $summary_f = $out_dir . '/summary.md';

        if ( ! file_exists( $summary_f ) ) {
            WP_CLI::error( "Run not found: {$run_id}" );
        }

        // summary.md をそのまま表示
        $content = file_get_contents( $summary_f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( $content !== false ) {
            WP_CLI::log( $content );
        }

        // failures_top10.md があれば表示
        $failures_f = $out_dir . '/failures_top10.md';
        if ( file_exists( $failures_f ) ) {
            WP_CLI::log( '' );
            $failures = file_get_contents( $failures_f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
            if ( $failures !== false ) {
                WP_CLI::log( $failures );
            }
        }
    }

    // =========================================================
    // ヘルパー
    // =========================================================

    /**
     * セーフティチェック
     *
     * @param int $user_id ユーザーID
     */
    private function safety_checks( int $user_id ): void {

        // 本番禁止チェック
        $env = defined( 'MIMAMORI_ENV' ) ? MIMAMORI_ENV : '';
        if ( $env === 'production' && ! WP_DEBUG ) {
            WP_CLI::error( 'QA batch is disabled in production (MIMAMORI_ENV=production && WP_DEBUG=false).' );
        }

        // ユーザー検証
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            WP_CLI::error( "User not found: {$user_id}" );
        }

        // GA4/GSC 設定確認
        try {
            $config = new Gcrev_Config();
            $config->get_user_config( $user_id );
        } catch ( \RuntimeException $e ) {
            WP_CLI::error( "User config check failed: " . $e->getMessage() );
        }

        // OpenAI API キー確認
        if ( ! defined( 'MIMAMORI_OPENAI_API_KEY' ) || MIMAMORI_OPENAI_API_KEY === '' ) {
            WP_CLI::error( 'MIMAMORI_OPENAI_API_KEY is not defined in wp-config.php.' );
        }
    }

    /**
     * user_id 必須パラメータを取得・検証
     *
     * @param  array $assoc_args Named arguments.
     * @return int
     */
    private function require_user_id( array $assoc_args ): int {
        if ( ! isset( $assoc_args['user_id'] ) ) {
            WP_CLI::error( '--user_id=<id> is required.' );
        }
        $id = absint( $assoc_args['user_id'] );
        if ( $id < 1 ) {
            WP_CLI::error( '--user_id must be a positive integer.' );
        }
        return $id;
    }

    /**
     * QA 関連クラスの読み込み
     */
    private function load_qa_classes(): void {
        $base = get_template_directory() . '/inc/gcrev-api';

        $files = [
            $base . '/modules/class-qa-runner.php',
            $base . '/utils/class-qa-scorer.php',
            $base . '/utils/class-qa-triage.php',
            $base . '/utils/class-qa-report-writer.php',
        ];

        foreach ( $files as $file ) {
            if ( file_exists( $file ) ) {
                require_once $file;
            } else {
                WP_CLI::error( "Required file not found: {$file}" );
            }
        }
    }

    /**
     * 出力ベースディレクトリ
     *
     * @return string
     */
    private function get_base_dir(): string {
        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . self::OUTPUT_BASE;
    }

    /**
     * 特定実行の出力ディレクトリ
     *
     * @param  string $run_id 実行ID
     * @return string
     */
    private function get_output_dir( string $run_id ): string {
        return $this->get_base_dir() . '/' . $run_id;
    }

    /**
     * 実行結果のサマリーをCLI表示
     *
     * @param array $results 実行結果配列
     */
    private function print_summary( array $results ): void {
        $total   = count( $results );
        $success = 0;
        $scores  = [];

        foreach ( $results as $r ) {
            if ( $r['success'] ) {
                $success++;
            }
            $scores[] = $r['score']['total'] ?? 0;
        }

        $avg = $total > 0 ? array_sum( $scores ) / $total : 0;
        $min = $total > 0 ? min( $scores ) : 0;
        $max = $total > 0 ? max( $scores ) : 0;

        WP_CLI::log( '=== Summary ===' );
        WP_CLI::log( sprintf( 'Total: %d | Success: %d | Failed: %d', $total, $success, $total - $success ) );
        WP_CLI::log( sprintf( 'Score — Avg: %.1f | Min: %d | Max: %d', $avg, $min, $max ) );

        // 分類ごとの件数
        $triage_counts = [];
        foreach ( $results as $r ) {
            foreach ( ( $r['triage'] ?? [] ) as $t ) {
                $cat = $t['category'] ?? 'UNKNOWN';
                $triage_counts[ $cat ] = ( $triage_counts[ $cat ] ?? 0 ) + 1;
            }
        }

        if ( ! empty( $triage_counts ) ) {
            arsort( $triage_counts );
            WP_CLI::log( '' );
            WP_CLI::log( 'Failure Distribution:' );
            foreach ( $triage_counts as $cat => $cnt ) {
                WP_CLI::log( sprintf( '  %-20s %3d (%4.1f%%)', $cat, $cnt, ( $cnt / $total ) * 100 ) );
            }
        }
    }
}
