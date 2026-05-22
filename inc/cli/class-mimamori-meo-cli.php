<?php
// FILE: inc/cli/class-mimamori-meo-cli.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_MEO_CLI
 *
 * MEOダッシュボードのGBP取得状況を診断するCLIコマンド。
 * REST 403 等のフロント側問題を回避してサーバー側で直接 Google Business Profile
 * Performance API を呼び出し、結果を返す。
 *
 * サブコマンド:
 *   wp mimamori meo diagnose --user_id=X    … 指定ユーザーのGBP取得状況を診断
 */
class Mimamori_MEO_CLI {

    /**
     * 指定ユーザーのMEOダッシュボードのGBP取得状況を診断する
     *
     * ## OPTIONS
     *
     * --user_id=<id>
     * : 対象のユーザーID
     *
     * ## EXAMPLES
     *
     *     wp mimamori meo diagnose --user_id=23
     */
    public function diagnose( array $args, array $assoc_args ): void {
        $user_id = isset( $assoc_args['user_id'] ) ? (int) $assoc_args['user_id'] : 0;
        if ( $user_id <= 0 ) {
            \WP_CLI::error( '--user_id は必須です' );
        }

        if ( ! class_exists( 'Gcrev_Insight_API' ) ) {
            \WP_CLI::error( 'Gcrev_Insight_API クラスが見つかりません' );
        }

        global $gcrev_api_instance;
        if ( ! isset( $gcrev_api_instance ) || ! ( $gcrev_api_instance instanceof \Gcrev_Insight_API ) ) {
            $gcrev_api_instance = new \Gcrev_Insight_API( false );
        }

        \WP_CLI::log( "MEO 診断開始: user_id={$user_id}" );
        \WP_CLI::log( str_repeat( '-', 60 ) );

        $result = $gcrev_api_instance->diagnose_meo_for_user( $user_id );

        \WP_CLI::log( "ユーザー: {$result['user_login']} ({$result['user_email']})" );
        \WP_CLI::log( "ロケーションID:   " . ( $result['location_id'] ?: '<未設定>' ) );
        \WP_CLI::log( "ロケーション名:   " . ( $result['location_name'] ?: '<未設定>' ) );
        \WP_CLI::log( "ロケーション住所: " . ( $result['location_address'] ?: '<未設定>' ) );
        \WP_CLI::log( "リフレッシュトークン: " . ( $result['has_refresh_token'] ? 'あり' : 'なし' ) );
        \WP_CLI::log( "アクセストークン:     " . ( $result['has_access_token'] ? 'あり' : 'なし' ) );
        \WP_CLI::log( "トークン有効期限:     " . ( $result['token_expires_at'] ?: '<未設定>' ) );
        \WP_CLI::log( "pending状態:          " . ( $result['is_pending'] ? 'はい (ロケーション未確定)' : 'いいえ' ) );

        if ( isset( $result['error'] ) ) {
            \WP_CLI::error( '中断: ' . $result['error'] );
        }

        \WP_CLI::log( str_repeat( '-', 60 ) );
        \WP_CLI::log( "期間 (当期): {$result['period_current']['start']} 〜 {$result['period_current']['end']}" );
        \WP_CLI::log( "期間 (比較): {$result['period_previous']['start']} 〜 {$result['period_previous']['end']}" );

        $cur_diag = $result['diagnostics_current'] ?? [];
        $cur_total   = (int) ( $cur_diag['total_count']   ?? 0 );
        $cur_success = (int) ( $cur_diag['success_count'] ?? 0 );
        $cur_errors  = $cur_diag['errors'] ?? [];

        \WP_CLI::log( str_repeat( '-', 60 ) );
        \WP_CLI::log( "メトリクス取得結果（当期）: {$cur_success}/{$cur_total} 成功" );

        if ( ! empty( $cur_errors ) ) {
            \WP_CLI::log( '' );
            \WP_CLI::log( "❌ エラー詳細:" );
            foreach ( $cur_errors as $i => $err ) {
                $n = $i + 1;
                $metric  = $err['metric']  ?? '?';
                $status  = $err['status']  ?? '?';
                $message = $err['message'] ?? '';
                \WP_CLI::log( "  [{$n}] metric={$metric}" );
                \WP_CLI::log( "      status={$status}" );
                \WP_CLI::log( "      message={$message}" );
            }
        }

        \WP_CLI::log( str_repeat( '-', 60 ) );
        \WP_CLI::log( '取得した数値（当期合計）:' );
        $m = $result['metrics_current'] ?? [];
        \WP_CLI::log( '  total_impressions:   ' . (int) ( $m['total_impressions']   ?? 0 ) );
        \WP_CLI::log( '  mobile_impressions:  ' . (int) ( $m['mobile_impressions']  ?? 0 ) );
        \WP_CLI::log( '  desktop_impressions: ' . (int) ( $m['desktop_impressions'] ?? 0 ) );
        \WP_CLI::log( '  call_clicks:         ' . (int) ( $m['call_clicks']         ?? 0 ) );
        \WP_CLI::log( '  website_clicks:      ' . (int) ( $m['website_clicks']      ?? 0 ) );
        \WP_CLI::log( '  direction_clicks:    ' . (int) ( $m['direction_clicks']    ?? 0 ) );

        \WP_CLI::log( str_repeat( '-', 60 ) );

        if ( $cur_success === 0 && $cur_total > 0 ) {
            \WP_CLI::error( '全メトリクス取得失敗。上記エラー詳細を確認してください。' );
        } elseif ( $cur_success < $cur_total ) {
            \WP_CLI::warning( "{$cur_success}/{$cur_total} 成功（一部失敗）" );
        } else {
            \WP_CLI::success( "全{$cur_total}メトリクス取得成功" );
        }
    }
}
