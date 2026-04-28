<?php
// FILE: inc/cli/class-mimamori-strategy-cli.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_Strategy_CLI
 *
 * WP-CLI: 戦略連動レポートの単発生成。Cron 連動前の検証・障害復旧用。
 *
 * サブコマンド:
 *   wp mimamori strategy-report generate --user_id=X --year_month=YYYY-MM [--source=manual_admin]
 *   wp mimamori strategy-report show     --user_id=X --year_month=YYYY-MM
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Mimamori_Strategy_CLI {

    /**
     * 戦略レポートを生成する。
     *
     * ## OPTIONS
     *
     * --user_id=<id>
     * : 対象クライアントの WP user_id（必須）
     *
     * --year_month=<ym>
     * : 対象年月（YYYY-MM）（必須）
     *
     * [--source=<source>]
     * : 生成ソース（cron / manual_admin / manual_user）。デフォルト manual_admin
     *
     * ## EXAMPLES
     *
     *     wp mimamori strategy-report generate --user_id=12 --year_month=2026-03
     */
    public function generate( $args, $assoc_args ): void {
        $user_id    = (int)    ( $assoc_args['user_id']    ?? 0 );
        $year_month = (string) ( $assoc_args['year_month'] ?? '' );
        $source     = (string) ( $assoc_args['source']     ?? 'manual_admin' );

        if ( $user_id <= 0 ) {
            \WP_CLI::error( '--user_id は必須です' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $year_month ) ) {
            \WP_CLI::error( '--year_month は YYYY-MM 形式で指定してください' );
        }

        $config = new Gcrev_Config();
        $ai     = new Gcrev_AI_Client( $config );
        $ga4    = new Gcrev_GA4_Fetcher( $config );
        $gsc    = new Gcrev_GSC_Fetcher( $config );

        $service = new Gcrev_Strategy_Report_Service( $config, $ai, $ga4, $gsc );

        \WP_CLI::log( "戦略レポート生成開始: user={$user_id}, year_month={$year_month}, source={$source}" );

        try {
            $result = $service->generate( $user_id, $year_month, $source );
        } catch ( \Throwable $e ) {
            \WP_CLI::error( '生成失敗: ' . $e->getMessage() );
        }

        $status = $result['status'] ?? 'unknown';
        $report_id = $result['report_id'] ?? 0;

        \WP_CLI::success( "status={$status} report_id={$report_id}" );

        if ( $status === 'completed' && ! empty( $result['report'] ) ) {
            $r = $result['report'];
            $score = $r['alignment_score'] ?? '-';
            \WP_CLI::log( "alignment_score: {$score}" );
            $sec = $r['report_json']['sections'] ?? [];
            \WP_CLI::log( '結論: ' . substr( (string) ( $sec['conclusion'] ?? '' ), 0, 200 ) );
            $issues_count = is_array( $sec['issues'] ?? null ) ? count( $sec['issues'] ) : 0;
            \WP_CLI::log( "問題点: {$issues_count} 件" );
        }
        if ( $status === 'skipped' ) {
            \WP_CLI::log( '理由: ' . ( $result['message'] ?? '' ) );
        }
    }

    /**
     * 既存レポートを表示する。
     *
     * ## OPTIONS
     *
     * --user_id=<id>
     * --year_month=<ym>
     */
    public function show( $args, $assoc_args ): void {
        $user_id    = (int)    ( $assoc_args['user_id']    ?? 0 );
        $year_month = (string) ( $assoc_args['year_month'] ?? '' );
        if ( $user_id <= 0 || ! preg_match( '/^\d{4}-\d{2}$/', $year_month ) ) {
            \WP_CLI::error( '--user_id と --year_month=YYYY-MM が必須' );
        }

        $repo = new Gcrev_Strategy_Report_Repository();
        $row  = $repo->get_by_user_month( $user_id, $year_month );
        if ( ! $row ) {
            \WP_CLI::warning( 'レポートが存在しません' );
            return;
        }

        \WP_CLI::log( "id: {$row['id']}" );
        \WP_CLI::log( "status: {$row['status']}" );
        \WP_CLI::log( 'alignment_score: ' . ( $row['alignment_score'] ?? '-' ) );
        \WP_CLI::log( 'started_at: '  . ( $row['started_at']  ?? '-' ) );
        \WP_CLI::log( 'finished_at: ' . ( $row['finished_at'] ?? '-' ) );
        if ( ! empty( $row['error_message'] ) ) {
            \WP_CLI::log( 'error: ' . $row['error_message'] );
        }
        if ( is_array( $row['report_json'] ?? null ) ) {
            $sec = $row['report_json']['sections'] ?? [];
            \WP_CLI::log( '結論: ' . substr( (string) ( $sec['conclusion'] ?? '' ), 0, 200 ) );
        }
    }
}
