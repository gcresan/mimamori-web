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
 * @package Mimamori_Web
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

        // Prompt Registry の world state を meta に焼き込む（Comparator / Rollback が参照）
        if ( class_exists( 'Mimamori_QA_Prompt_Registry' ) ) {
            $meta['prompt_version']  = Mimamori_QA_Prompt_Registry::get_active_version();
            $meta['intent_versions'] = Mimamori_QA_Prompt_Registry::get_intent_versions();
        }

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
    // qa:improve — 自動改善ループ
    // =========================================================

    /**
     * QA実行結果の未合格ケースに対して自動改善ループを実行する。
     *
     * ## OPTIONS
     *
     * --run=<run_id>
     * : 対象QA実行ID（YYYYMMDD_HHMMSS 形式、必須）
     *
     * --user_id=<id>
     * : GA4設定済みユーザーID（必須）
     *
     * [--pass-score=<score>]
     * : 合格点（デフォルト: 100）
     *
     * [--max-revisions=<n>]
     * : 最大試行回数（デフォルト: 5）
     *
     * [--pass-mode=<mode>]
     * : 合格判定モード: total_score / no_critical（デフォルト: total_score）
     *
     * [--min-improvement=<n>]
     * : 最小改善幅（デフォルト: 2）
     *
     * [--case=<case_id>]
     * : 特定ケースのみ改善（省略時は全未合格ケース）
     *
     * [--dry-run]
     * : 改善案の生成のみ（再実行しない）
     *
     * [--sleep=<ms>]
     * : リクエスト間スリープ(ms)（デフォルト: 2000）
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:improve --run=20260306_030000 --user_id=1
     *     wp mimamori qa:improve --run=20260306_030000 --user_id=1 --dry-run
     *     wp mimamori qa:improve --run=20260306_030000 --user_id=1 --pass-score=95 --pass-mode=no_critical
     *     wp mimamori qa:improve --run=20260306_030000 --user_id=1 --case=qa_003 --max-revisions=3
     *
     * @subcommand qa:improve
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function qa_improve( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter

        // --- パラメータ解析 ---
        $user_id         = $this->require_user_id( $assoc_args );
        $run_id          = $assoc_args['run'] ?? '';
        $pass_score      = (int) ( $assoc_args['pass-score'] ?? 100 );
        $max_revisions   = (int) ( $assoc_args['max-revisions'] ?? 5 );
        $pass_mode       = $assoc_args['pass-mode'] ?? 'total_score';
        $min_improvement = (int) ( $assoc_args['min-improvement'] ?? 2 );
        $target_case     = $assoc_args['case'] ?? null;
        $dry_run         = isset( $assoc_args['dry-run'] );
        $sleep_ms        = (int) ( $assoc_args['sleep'] ?? 2000 );

        if ( $run_id === '' ) {
            WP_CLI::error( '--run=<YYYYMMDD_HHMMSS> is required.' );
        }

        if ( ! in_array( $pass_mode, [ 'total_score', 'no_critical' ], true ) ) {
            WP_CLI::error( '--pass-mode must be "total_score" or "no_critical".' );
        }

        // --- セーフティチェック ---
        $this->safety_checks( $user_id );

        // --- 既存 QA 結果読み込み ---
        $out_dir   = $this->get_output_dir( sanitize_file_name( $run_id ) );
        $jsonl_path = $out_dir . '/cases.jsonl';

        if ( ! file_exists( $jsonl_path ) ) {
            WP_CLI::error( "Run not found: {$run_id}" );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $lines = file( $jsonl_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( empty( $lines ) ) {
            WP_CLI::error( 'No cases found in cases.jsonl.' );
        }

        $cases = [];
        foreach ( $lines as $line ) {
            $c = json_decode( $line, true );
            if ( is_array( $c ) ) {
                $cases[] = $c;
            }
        }

        WP_CLI::log( '=== Mimamori QA Auto-Improve ===' );
        WP_CLI::log( sprintf(
            'Run: %s | User: %d | Pass: %d (%s) | Max Rev: %d | Dry-run: %s',
            $run_id, $user_id, $pass_score, $pass_mode, $max_revisions, $dry_run ? 'yes' : 'no'
        ) );

        // --- 未合格ケース抽出 ---
        $below = [];
        foreach ( $cases as $c ) {
            $case_id = $c['id'] ?? '';
            $total   = $c['score']['total'] ?? 0;

            if ( $target_case !== null && $case_id !== $target_case ) {
                continue;
            }

            if ( $total < $pass_score ) {
                // question データを復元
                $c['question'] = [
                    'id'              => $c['id'] ?? '',
                    'category'        => $c['category'] ?? '',
                    'message'         => $c['message'] ?? '',
                    'page_type'       => $c['page_type'] ?? '',
                    'current_page'    => $this->restore_current_page( $c['page_type'] ?? '' ),
                    'history'         => [],
                    'section_context' => null,
                ];
                $below[] = $c;
            }
        }

        if ( empty( $below ) ) {
            WP_CLI::success( 'All cases already pass. Nothing to improve.' );
            return;
        }

        WP_CLI::log( sprintf( 'Found %d cases below pass score (%d).', count( $below ), $pass_score ) );

        // --- QAクラス読み込み + 改善ループ実行 ---
        $this->load_qa_classes();

        $runner   = new Mimamori_QA_Runner( $user_id, $sleep_ms );
        $improver = new Mimamori_QA_Improver( $runner, [
            'pass_score'      => $pass_score,
            'pass_mode'       => $pass_mode,
            'max_revisions'   => $max_revisions,
            'min_improvement' => $min_improvement,
            'stale_limit'     => 2,
            'dry_run'         => $dry_run,
            'sleep_ms'        => $sleep_ms,
        ] );

        $store    = new Mimamori_QA_Revision_Store( $out_dir );
        $progress = \WP_CLI\Utils\make_progress_bar( 'Improving', count( $below ) );

        $results = [];

        foreach ( $below as $case ) {
            $progress->tick();
            $case_id = $case['id'] ?? 'unknown';

            WP_CLI::log( sprintf(
                "\n[%s] Initial score: %d — Starting improvement loop...",
                $case_id, $case['score']['total'] ?? 0
            ) );

            try {
                $result = $improver->improve( $case );
            } catch ( \Throwable $e ) {
                WP_CLI::warning( sprintf( '[%s] Error: %s', $case_id, $e->getMessage() ) );
                $result = [
                    'revisions'     => [],
                    'stop_reason'   => 'error',
                    'passed'        => false,
                    'initial_score' => $case['score']['total'] ?? 0,
                    'final_score'   => $case['score']['total'] ?? 0,
                ];
            }

            $results[ $case_id ] = $result;

            // 保存
            $store->save_case( $case_id, $result['revisions'], $result['stop_reason'] );

            $status = $result['passed'] ? 'PASSED' : 'NOT PASSED';
            $rev_count = count( $result['revisions'] );
            WP_CLI::log( sprintf(
                '[%s] %s — Score: %d → %d (%d revisions, stop: %s)',
                $case_id, $status,
                $result['initial_score'], $result['final_score'],
                $rev_count, $result['stop_reason']
            ) );
        }

        $progress->finish();

        // --- サマリー生成・保存 ---
        $summary = $this->build_improve_summary( $run_id, $results, $cases, [
            'pass_score'    => $pass_score,
            'pass_mode'     => $pass_mode,
            'max_revisions' => $max_revisions,
            'dry_run'       => $dry_run,
        ] );
        $store->save_summary( $summary );

        // --- レポート生成・保存 ---
        $report = $this->render_improve_report( $summary, $results );
        $store->save_report( $report );

        // --- CLI サマリー表示 ---
        WP_CLI::log( '' );
        WP_CLI::log( '=== Improvement Summary ===' );
        WP_CLI::log( sprintf( 'Total cases: %d | Below pass: %d', $summary['total_cases'], $summary['below_pass'] ) );
        WP_CLI::log( sprintf( 'Passed: %d | Still below: %d', $summary['passed_count'], $summary['still_below'] ) );
        WP_CLI::log( sprintf( 'Avg initial: %.1f | Avg final: %.1f | Avg revisions: %.1f',
            $summary['avg_initial_score'], $summary['avg_final_score'], $summary['avg_revisions']
        ) );

        if ( ! empty( $summary['stop_reasons'] ) ) {
            WP_CLI::log( '' );
            WP_CLI::log( 'Stop Reasons:' );
            foreach ( $summary['stop_reasons'] as $reason => $cnt ) {
                WP_CLI::log( sprintf( '  %-20s %d', $reason, $cnt ) );
            }
        }

        WP_CLI::success( sprintf( 'Improvement complete. Output: %s', $store->get_dir() ) );
    }

    /**
     * ページタイプからcurrent_pageを復元する。
     */
    private function restore_current_page( string $page_type ): array {
        $domain = 'https://dev.mimamori-web.jp';
        return match ( $page_type ) {
            'report_dashboard' => [ 'url' => $domain . '/mypage/dashboard/', 'title' => 'ダッシュボード' ],
            'analysis_detail'  => [ 'url' => $domain . '/mypage/analysis/', 'title' => '分析' ],
            default            => [ 'url' => $domain . '/mypage/', 'title' => 'マイページ' ],
        };
    }

    /**
     * 改善サマリーを構築する。
     */
    private function build_improve_summary( string $run_id, array $results, array $all_cases, array $config ): array {
        $passed_count    = 0;
        $initial_scores  = [];
        $final_scores    = [];
        $rev_counts      = [];
        $stop_reasons    = [];

        foreach ( $results as $r ) {
            if ( $r['passed'] ) $passed_count++;
            $initial_scores[] = $r['initial_score'];
            $final_scores[]   = $r['final_score'];
            $rev_counts[]     = count( $r['revisions'] );

            $reason = $r['stop_reason'];
            $stop_reasons[ $reason ] = ( $stop_reasons[ $reason ] ?? 0 ) + 1;
        }

        $n = count( $results );

        return [
            'run_id'             => $run_id,
            'improve_started_at' => wp_date( 'Y-m-d H:i:s' ),
            'config'             => $config,
            'total_cases'        => count( $all_cases ),
            'below_pass'         => $n,
            'improved'           => $n,
            'passed_count'       => $passed_count,
            'still_below'        => $n - $passed_count,
            'avg_initial_score'  => $n > 0 ? array_sum( $initial_scores ) / $n : 0,
            'avg_final_score'    => $n > 0 ? array_sum( $final_scores ) / $n : 0,
            'avg_revisions'      => $n > 0 ? array_sum( $rev_counts ) / $n : 0,
            'stop_reasons'       => $stop_reasons,
        ];
    }

    /**
     * 改善レポート（Markdown）を生成する。
     */
    private function render_improve_report( array $summary, array $results ): string {
        $md = "# AI品質改善レポート\n\n";
        $md .= sprintf( "- 実行日: %s\n", $summary['improve_started_at'] );
        $md .= sprintf( "- 対象run: %s\n", $summary['run_id'] );
        $md .= sprintf( "- 合格基準: %d点（%s モード）\n", $summary['config']['pass_score'], $summary['config']['pass_mode'] );
        if ( $summary['config']['dry_run'] ) {
            $md .= "- **dry-run モード（実行なし）**\n";
        }
        $md .= "\n## サマリー\n\n";
        $md .= "| 項目 | 値 |\n|---|---|\n";
        $md .= sprintf( "| 対象ケース | %d / %d |\n", $summary['below_pass'], $summary['total_cases'] );
        $md .= sprintf( "| 合格到達 | %d (%.1f%%) |\n", $summary['passed_count'],
            $summary['below_pass'] > 0 ? ( $summary['passed_count'] / $summary['below_pass'] ) * 100 : 0 );
        $md .= sprintf( "| 未到達 | %d |\n", $summary['still_below'] );
        $md .= sprintf( "| 平均初回スコア | %.1f |\n", $summary['avg_initial_score'] );
        $md .= sprintf( "| 平均最終スコア | %.1f |\n", $summary['avg_final_score'] );
        $md .= sprintf( "| 平均試行回数 | %.1f |\n", $summary['avg_revisions'] );

        $md .= "\n## 停止理由\n\n";
        $md .= "| 理由 | 件数 |\n|---|---|\n";
        foreach ( $summary['stop_reasons'] as $reason => $cnt ) {
            $md .= sprintf( "| %s | %d |\n", $reason, $cnt );
        }

        $md .= "\n## ケース別推移\n\n";

        foreach ( $results as $case_id => $r ) {
            $icon = $r['passed'] ? '✅' : '❌';
            $md .= sprintf( "### %s (%s) %s\n\n", $case_id, $r['stop_reason'], $icon );
            $md .= "| Rev | Total | Data | Period | Honesty | Structure | 変更 |\n";
            $md .= "|-----|-------|------|--------|---------|-----------|------|\n";

            foreach ( $r['revisions'] as $rev ) {
                $changes_str = ! empty( $rev['changes'] ) ? implode( ', ', $rev['changes'] ) : '—';
                if ( mb_strlen( $changes_str ) > 40 ) {
                    $changes_str = mb_strimwidth( $changes_str, 0, 40, '…' );
                }
                $md .= sprintf( "| %d | %d | %d | %d | %d | %d | %s |\n",
                    $rev['revision_no'],
                    $rev['score_total'],
                    $rev['data_integrity'],
                    $rev['period_accuracy'],
                    $rev['honesty'],
                    $rev['structure'],
                    $changes_str
                );
            }

            if ( ! $r['passed'] && ! empty( $r['revisions'] ) ) {
                $last = end( $r['revisions'] );
                $md .= sprintf( "\n停止理由: %s\n", $r['stop_reason'] );
            }
            $md .= "\n";
        }

        return $md;
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
            $base . '/utils/class-qa-diagnosis.php',
            $base . '/utils/class-qa-prompt-tuner.php',
            $base . '/utils/class-qa-revision-store.php',
            $base . '/modules/class-qa-improver.php',
            // QA → 本番改善ループ
            $base . '/utils/class-qa-prompt-registry.php',
            $base . '/utils/class-qa-intent-resolver.php',
            $base . '/utils/class-qa-run-comparator.php',
            $base . '/modules/class-qa-auto-promoter.php',
            $base . '/modules/class-qa-auto-rollback.php',
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

    // =========================================================
    // qa:auto-promote — 改訂案を Prompt Registry に自動昇格
    // =========================================================

    /**
     * qa:improve で生成された改訂案のうち、安全条件を満たすものだけを
     * Prompt Registry に自動昇格する。
     *
     * ## OPTIONS
     *
     * --run=<run_id>
     * : 対象QA実行ID（YYYYMMDD_HHMMSS 形式、必須）
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:auto-promote --run=20260419_030000
     *
     * @subcommand qa:auto-promote
     */
    public function qa_auto_promote( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $run_id = (string) ( $assoc_args['run'] ?? '' );
        if ( ! preg_match( '/^\d{8}_\d{6}$/', $run_id ) ) {
            WP_CLI::error( '--run=<YYYYMMDD_HHMMSS> is required.' );
        }

        $this->load_qa_classes();

        $promoter = new Mimamori_QA_Auto_Promoter();
        $result   = $promoter->promote_from_run( $run_id );

        WP_CLI::log( '=== QA Auto-Promote ===' );
        WP_CLI::log( sprintf(
            'Promoted: %d | Rejected: %d | Skipped: %d',
            count( $result['promoted'] ),
            count( $result['rejected'] ),
            count( $result['skipped'] )
        ) );

        if ( ! empty( $result['promoted'] ) ) {
            WP_CLI::log( '' );
            WP_CLI::log( 'Promoted intents:' );
            foreach ( $result['promoted'] as $p ) {
                WP_CLI::log( sprintf(
                    '  %-24s  %d → %d (+%d)  version=%s',
                    $p['intent'], $p['initial'], $p['final'], $p['delta'], $p['new_version']
                ) );
            }
        }

        if ( ! empty( $result['rejected'] ) ) {
            WP_CLI::log( '' );
            WP_CLI::log( 'Rejected:' );
            foreach ( $result['rejected'] as $r ) {
                WP_CLI::log( sprintf(
                    '  [%s] intent=%s reason=%s',
                    $r['case_id'], $r['intent'], $r['reason']
                ) );
            }
        }

        WP_CLI::success( 'Auto-promote complete.' );
    }

    // =========================================================
    // qa:compare — 2 run の差分比較
    // =========================================================

    /**
     * 2 つの QA run のスコアを比較し、comparison_<prev>.json を保存する。
     *
     * ## OPTIONS
     *
     * --current=<run_id>
     * : 今回 run ID
     *
     * [--previous=<run_id>]
     * : 前回 run ID。省略時は current の直前に自動検出。
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:compare --current=20260419_030000
     *     wp mimamori qa:compare --current=20260419_030000 --previous=20260418_030000
     *
     * @subcommand qa:compare
     */
    public function qa_compare( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $current  = (string) ( $assoc_args['current'] ?? '' );
        $previous = (string) ( $assoc_args['previous'] ?? '' );

        if ( ! preg_match( '/^\d{8}_\d{6}$/', $current ) ) {
            WP_CLI::error( '--current=<YYYYMMDD_HHMMSS> is required.' );
        }

        $this->load_qa_classes();

        $comparator = new Mimamori_QA_Run_Comparator();

        if ( $previous === '' ) {
            $previous = (string) $comparator->find_previous_run( $current );
            if ( $previous === '' ) {
                WP_CLI::warning( 'No previous run found. Nothing to compare.' );
                return;
            }
        }

        $diff = $comparator->compare( $current, $previous );
        $path = $comparator->save_comparison( $current, $previous, $diff );

        WP_CLI::log( '=== QA Run Compare ===' );
        WP_CLI::log( sprintf(
            'Current:  %s (avg %.1f, %d cases)',
            $current, $diff['avg_current'], $diff['meta']['cases_current']
        ) );
        WP_CLI::log( sprintf(
            'Previous: %s (avg %.1f, %d cases)',
            $previous, $diff['avg_previous'], $diff['meta']['cases_previous']
        ) );
        WP_CLI::log( sprintf(
            'Δ avg score: %+.1f pts | Δ fail: %+d | Δ hallucination: %+d',
            $diff['avg_score_delta'],
            $diff['fail_count_delta'],
            $diff['hallucination_delta']
        ) );

        if ( $path !== null ) {
            WP_CLI::log( 'Saved: ' . $path );
        }

        WP_CLI::success( 'Compare complete.' );
    }

    // =========================================================
    // qa:auto-rollback — 悪化時の自動 rollback
    // =========================================================

    /**
     * 2 run の比較が悪化条件を満たせば Prompt Registry を 1 世代前に戻す。
     *
     * ## OPTIONS
     *
     * --current=<run_id>
     * : 今回 run ID
     *
     * [--previous=<run_id>]
     * : 前回 run ID。省略時は直前の run を自動検出。
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:auto-rollback --current=20260419_030000
     *
     * @subcommand qa:auto-rollback
     */
    public function qa_auto_rollback( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $current  = (string) ( $assoc_args['current'] ?? '' );
        $previous = (string) ( $assoc_args['previous'] ?? '' );

        if ( ! preg_match( '/^\d{8}_\d{6}$/', $current ) ) {
            WP_CLI::error( '--current=<YYYYMMDD_HHMMSS> is required.' );
        }

        $this->load_qa_classes();

        if ( $previous === '' ) {
            $comparator = new Mimamori_QA_Run_Comparator();
            $previous   = (string) $comparator->find_previous_run( $current );
            if ( $previous === '' ) {
                WP_CLI::warning( 'No previous run found. Skipping rollback evaluation.' );
                return;
            }
        }

        $rollback = new Mimamori_QA_Auto_Rollback();
        $res      = $rollback->rollback_if_needed( $current, $previous );

        WP_CLI::log( '=== QA Auto Rollback ===' );
        WP_CLI::log( sprintf(
            'Δ avg: %.2f | Δ fail: %d | Δ hallucination: %d',
            $res['diff_summary']['avg_score_delta'] ?? 0.0,
            $res['diff_summary']['fail_count_delta'] ?? 0,
            $res['diff_summary']['hallucination_delta'] ?? 0
        ) );

        if ( $res['rolled_back'] ) {
            WP_CLI::warning( sprintf(
                'ROLLED BACK to version %s | reasons: %s',
                $res['rolled_to_version'],
                implode( ', ', $res['reasons'] )
            ) );
        } elseif ( ! empty( $res['reasons'] ) ) {
            WP_CLI::warning( sprintf(
                'Regression detected (%s) but rollback could not be performed (no history).',
                implode( ', ', $res['reasons'] )
            ) );
        } else {
            WP_CLI::success( 'No regression detected. Registry unchanged.' );
        }
    }

    // =========================================================
    // qa:registry-export — registry を JSON ファイルに書き出す
    // =========================================================

    /**
     * Prompt Registry の現状を JSON にエクスポートする（Dev → Prod 同期用）。
     *
     * エクスポート対象は active_version と intents のみ。history は含めない
     * （Prod 側が自身の history を保持できるようにするため）。
     *
     * ## OPTIONS
     *
     * --to=<path>
     * : 出力先ファイルパス（絶対 or 相対）
     *
     * [--pretty]
     * : JSON を整形出力する
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:registry-export --to=/tmp/mimamori-registry-export.json --pretty
     *
     * @subcommand qa:registry-export
     */
    public function qa_registry_export( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $path   = (string) ( $assoc_args['to'] ?? '' );
        $pretty = isset( $assoc_args['pretty'] );

        if ( $path === '' ) {
            WP_CLI::error( '--to=<path> is required.' );
        }

        $this->load_qa_classes();

        $reg = Mimamori_QA_Prompt_Registry::get_registry();

        $payload = [
            'schema_version'     => 1,
            'exported_at'        => wp_date( 'Y-m-d H:i:s' ),
            'exported_from_env'  => defined( 'MIMAMORI_ENV' ) ? MIMAMORI_ENV : 'unknown',
            'exported_from_host' => (string) gethostname(),
            'exported_by_user'   => (int) get_current_user_id(),
            'registry'           => [
                'active_version' => $reg['active_version'] ?? '',
                'intents'        => $reg['intents'] ?? [],
            ],
        ];

        $flags = JSON_UNESCAPED_UNICODE | ( $pretty ? JSON_PRETTY_PRINT : 0 );
        $json  = wp_json_encode( $payload, $flags );

        // 書き出し
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = @file_put_contents( $path, $json );
        if ( $written === false ) {
            WP_CLI::error( "Failed to write: {$path}" );
        }

        $intent_count = count( (array) $payload['registry']['intents'] );
        WP_CLI::success( sprintf(
            'Exported registry (active=%s, %d intents, %d bytes) → %s',
            $payload['registry']['active_version'] ?: '(none)',
            $intent_count,
            strlen( $json ),
            $path
        ) );
    }

    // =========================================================
    // qa:registry-import — JSON ファイルから registry を取り込む
    // =========================================================

    /**
     * JSON ファイルから Prompt Registry を取り込む（本番側で実行）。
     *
     * 取り込み前に現 Prod registry を history に退避するため、
     * 問題があれば `wp mimamori qa:registry-show` で version を確認し、
     * 既存の rollback 機構で戻せる。
     *
     * ## OPTIONS
     *
     * --from=<path>
     * : 入力 JSON ファイルパス
     *
     * [--dry-run]
     * : 実際には DB を書き換えず、取り込み内容だけ表示する
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:registry-import --from=/tmp/mimamori-registry-export.json
     *     wp mimamori qa:registry-import --from=/tmp/mimamori-registry-export.json --dry-run
     *
     * @subcommand qa:registry-import
     */
    public function qa_registry_import( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $path    = (string) ( $assoc_args['from'] ?? '' );
        $dry_run = isset( $assoc_args['dry-run'] );

        if ( $path === '' ) {
            WP_CLI::error( '--from=<path> is required.' );
        }
        if ( ! file_exists( $path ) ) {
            WP_CLI::error( "File not found: {$path}" );
        }

        $this->load_qa_classes();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = @file_get_contents( $path );
        if ( $raw === false || $raw === '' ) {
            WP_CLI::error( "Cannot read: {$path}" );
        }

        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) ) {
            WP_CLI::error( 'Invalid JSON.' );
        }

        $schema = (int) ( $payload['schema_version'] ?? 0 );
        if ( $schema !== 1 ) {
            WP_CLI::error( sprintf( 'Unsupported schema_version: %d (expected 1)', $schema ) );
        }

        $incoming = is_array( $payload['registry'] ?? null ) ? $payload['registry'] : [];
        $incoming_version = (string) ( $incoming['active_version'] ?? '' );
        $incoming_intents = is_array( $incoming['intents'] ?? null ) ? $incoming['intents'] : [];

        $cur_reg     = Mimamori_QA_Prompt_Registry::get_registry();
        $cur_version = (string) ( $cur_reg['active_version'] ?? '' );
        $cur_intents = (array) ( $cur_reg['intents'] ?? [] );

        WP_CLI::log( '=== Registry Import ===' );
        WP_CLI::log( sprintf( 'Source:       %s', $path ) );
        WP_CLI::log( sprintf( 'Exported at:  %s (from %s)',
            $payload['exported_at'] ?? '?', $payload['exported_from_env'] ?? '?' ) );
        WP_CLI::log( sprintf( 'Current ver:  %s (%d intents)', $cur_version ?: '(none)', count( $cur_intents ) ) );
        WP_CLI::log( sprintf( 'Incoming ver: %s (%d intents)', $incoming_version ?: '(none)', count( $incoming_intents ) ) );

        if ( $cur_version === $incoming_version && $cur_version !== '' ) {
            WP_CLI::log( 'No-op: version already matches.' );
            WP_CLI::success( 'Nothing to import.' );
            return;
        }

        if ( $dry_run ) {
            WP_CLI::log( '' );
            WP_CLI::log( 'Intents to be written:' );
            foreach ( $incoming_intents as $intent => $data ) {
                $len = mb_strlen( (string) ( $data['addendum'] ?? '' ) );
                WP_CLI::log( sprintf( '  [%s] version=%s addendum_len=%d',
                    $intent, $data['version'] ?? '-', $len ) );
            }
            WP_CLI::success( 'Dry-run: no changes written.' );
            return;
        }

        // 現 registry を history に退避してから intents + active_version を差し替え
        $reg = $cur_reg;
        if ( ! empty( $reg['intents'] ) ) {
            $hist_entry = [
                'version'       => $cur_version,
                'snapshot'      => [ 'intents' => $cur_intents ],
                'retired_at'    => wp_date( 'Y-m-d H:i:s' ),
                'retire_reason' => 'import_from_dev',
            ];
            $history = is_array( $reg['history'] ?? null ) ? $reg['history'] : [];
            array_unshift( $history, $hist_entry );
            // HISTORY_LIMIT を超えたら切る
            if ( count( $history ) > Mimamori_QA_Prompt_Registry::HISTORY_LIMIT ) {
                $history = array_slice( $history, 0, Mimamori_QA_Prompt_Registry::HISTORY_LIMIT );
            }
            $reg['history'] = $history;
        }

        $reg['intents']        = $incoming_intents;
        $reg['active_version'] = $incoming_version;
        $reg['updated_at']     = wp_date( 'Y-m-d H:i:s' );

        $ok = Mimamori_QA_Prompt_Registry::save_registry( $reg );
        if ( ! $ok ) {
            WP_CLI::error( 'Failed to save registry.' );
        }

        // /tmp/gcrev_qa_registry_debug.log に記録（Registry クラスは log を private にしているので、ここで直接書く）
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents(
            '/tmp/gcrev_qa_registry_debug.log',
            sprintf(
                "%s event=import from=%s to=%s intents=%d source=%s\n",
                date( 'Y-m-d H:i:s' ),
                $cur_version,
                $incoming_version,
                count( $incoming_intents ),
                $payload['exported_from_env'] ?? '?'
            ),
            FILE_APPEND
        );

        WP_CLI::success( sprintf(
            'Imported: %s → %s (%d intents). Previous snapshot retained in history.',
            $cur_version ?: '(none)',
            $incoming_version ?: '(none)',
            count( $incoming_intents )
        ) );
    }

    // =========================================================
    // qa:registry-stage — intent をステージングに切り替え（Phase 4）
    // =========================================================

    /**
     * 指定 intent を特定ユーザーだけに適用（staged）する。
     *
     * ## OPTIONS
     *
     * --intent=<name>
     * : intent 名（_global 不可）
     *
     * --users=<csv>
     * : カンマ区切りの user_id（例: 1,5,42）
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:registry-stage --intent=site_improvement --users=1,5
     *
     * @subcommand qa:registry-stage
     */
    public function qa_registry_stage( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $intent = (string) ( $assoc_args['intent'] ?? '' );
        $users_csv = (string) ( $assoc_args['users'] ?? '' );
        if ( $intent === '' ) {
            WP_CLI::error( '--intent=<name> is required.' );
        }
        if ( $users_csv === '' ) {
            WP_CLI::error( '--users=<csv> is required.' );
        }
        $user_ids = array_map( 'trim', explode( ',', $users_csv ) );

        $this->load_qa_classes();

        try {
            Mimamori_QA_Prompt_Registry::stage( $intent, $user_ids, (int) get_current_user_id() );
        } catch ( \Throwable $e ) {
            WP_CLI::error( 'stage failed: ' . $e->getMessage() );
        }

        WP_CLI::success( sprintf( 'Intent "%s" staged for users: %s',
            $intent, implode( ',', Mimamori_QA_Prompt_Registry::sanitize_user_id_list( $user_ids ) ) ) );
    }

    // =========================================================
    // qa:registry-unstage — ステージング解除して全員適用
    // =========================================================

    /**
     * 指定 intent のステージングを解除し、全員に適用する。
     *
     * ## OPTIONS
     *
     * --intent=<name>
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:registry-unstage --intent=site_improvement
     *
     * @subcommand qa:registry-unstage
     */
    public function qa_registry_unstage( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $intent = (string) ( $assoc_args['intent'] ?? '' );
        if ( $intent === '' ) {
            WP_CLI::error( '--intent=<name> is required.' );
        }

        $this->load_qa_classes();

        try {
            Mimamori_QA_Prompt_Registry::unstage( $intent, (int) get_current_user_id() );
        } catch ( \Throwable $e ) {
            WP_CLI::error( 'unstage failed: ' . $e->getMessage() );
        }

        WP_CLI::success( sprintf( 'Intent "%s" is now full rollout.', $intent ) );
    }

    // =========================================================
    // qa:canary-show — 現在の canary ユーザー一覧を表示
    // =========================================================

    /**
     * Auto Promoter のデフォルト canary ユーザーを表示する。
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:canary-show
     *
     * @subcommand qa:canary-show
     */
    public function qa_canary_show( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $this->load_qa_classes();
        $ids = Mimamori_QA_Prompt_Registry::get_canary_users();
        if ( empty( $ids ) ) {
            WP_CLI::log( 'Canary users: (none) — Auto Promoter は全ユーザーに即時反映します。' );
        } else {
            WP_CLI::log( 'Canary users: ' . implode( ',', $ids ) );
            WP_CLI::log( 'Auto Promoter はこれらのユーザーだけに先行適用します。' );
        }
    }

    // =========================================================
    // qa:canary-set — canary ユーザー一覧を設定（空で解除）
    // =========================================================

    /**
     * Auto Promoter のデフォルト canary ユーザーを設定する。
     *
     * ## OPTIONS
     *
     * [--users=<csv>]
     * : カンマ区切りの user_id（例: 1,5,42）。省略時は空配列（canary 解除）。
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:canary-set --users=1
     *     wp mimamori qa:canary-set           # canary 解除 → Auto Promoter は full
     *
     * @subcommand qa:canary-set
     */
    public function qa_canary_set( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $users_csv = (string) ( $assoc_args['users'] ?? '' );
        $users     = $users_csv === '' ? [] : array_map( 'trim', explode( ',', $users_csv ) );

        $this->load_qa_classes();
        Mimamori_QA_Prompt_Registry::set_canary_users( $users );
        $clean = Mimamori_QA_Prompt_Registry::get_canary_users();
        if ( empty( $clean ) ) {
            WP_CLI::success( 'Canary cleared. Auto Promoter will roll out to all users.' );
        } else {
            WP_CLI::success( 'Canary users updated: ' . implode( ',', $clean ) );
        }
    }

    // =========================================================
    // qa:registry-show — registry デバッグ表示
    // =========================================================

    /**
     * 現在の Prompt Registry を表示する（管理者確認用）。
     *
     * ## EXAMPLES
     *
     *     wp mimamori qa:registry-show
     *
     * @subcommand qa:registry-show
     */
    public function qa_registry_show( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        $this->load_qa_classes();

        $reg = Mimamori_QA_Prompt_Registry::get_registry();

        WP_CLI::log( sprintf( 'active_version: %s', $reg['active_version'] ?? '(none)' ) );
        WP_CLI::log( sprintf( 'updated_at:     %s', $reg['updated_at'] ?? '(never)' ) );
        WP_CLI::log( '' );
        WP_CLI::log( 'Intents:' );
        foreach ( (array) ( $reg['intents'] ?? [] ) as $intent => $data ) {
            $stage      = is_array( $data['stage'] ?? null ) ? $data['stage'] : [];
            $stage_mode = (string) ( $stage['mode'] ?? 'full' );
            $stage_tag  = $stage_mode === 'staged'
                ? ' staged=[' . implode( ',', (array) ( $stage['user_ids'] ?? [] ) ) . ']'
                : '';
            WP_CLI::log( sprintf( '  [%s] version=%s len=%d%s', $intent,
                $data['version'] ?? '', mb_strlen( (string) ( $data['addendum'] ?? '' ) ), $stage_tag ) );
            if ( ! empty( $data['overrides'] ) ) {
                $kv = [];
                foreach ( $data['overrides'] as $k => $v ) {
                    $kv[] = $k . '=' . ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : $v );
                }
                WP_CLI::log( '      overrides: ' . implode( ', ', $kv ) );
            }
            $src = $data['source'] ?? [];
            if ( ! empty( $src['run_id'] ) ) {
                WP_CLI::log( sprintf( '      source:    run=%s case=%s rev=%d',
                    $src['run_id'], $src['case_id'] ?? '', (int) ( $src['revision_no'] ?? 0 ) ) );
            }
        }

        $canary = Mimamori_QA_Prompt_Registry::get_canary_users();
        if ( ! empty( $canary ) ) {
            WP_CLI::log( '' );
            WP_CLI::log( 'Canary users: ' . implode( ',', $canary ) . ' (Auto Promoter default stage)' );
        }

        $hist = $reg['history'] ?? [];
        WP_CLI::log( '' );
        WP_CLI::log( sprintf( 'History (%d entries):', count( $hist ) ) );
        foreach ( array_slice( $hist, 0, 5 ) as $h ) {
            WP_CLI::log( sprintf( '  %s  retired=%s  reason=%s',
                $h['version'] ?? '', $h['retired_at'] ?? '', $h['retire_reason'] ?? '' ) );
        }
    }
}
