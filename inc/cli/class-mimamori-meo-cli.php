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

    /**
     * 保存済みのMEO順位データ（誤マッチ含む）をパージする
     *
     * gcrev_meo_results テーブルの順位行と gcrev_meo_rank_* Transient キャッシュを削除する。
     * 順位照合ロジックの修正後、過去の誤った順位を消して再取得させたい場合に使う。
     * パージ後は「最新の情報を見る」ボタン、または日次 cron で正しい順位が再取得される。
     *
     * ## OPTIONS
     *
     * --user_id=<id>
     * : 対象のユーザーID
     *
     * [--from=<date>]
     * : この日付以降(fetch_date >=)の行のみ削除（YYYY-MM-DD）。省略時は全期間。
     *
     * [--dry-run]
     * : 実際には削除せず、削除対象件数のみ表示する。
     *
     * ## EXAMPLES
     *
     *     wp mimamori meo purge --user_id=23 --dry-run
     *     wp mimamori meo purge --user_id=23
     *     wp mimamori meo purge --user_id=23 --from=2026-05-26
     */
    public function purge( array $args, array $assoc_args ): void {
        global $wpdb;

        $user_id = isset( $assoc_args['user_id'] ) ? (int) $assoc_args['user_id'] : 0;
        if ( $user_id <= 0 ) {
            \WP_CLI::error( '--user_id は必須です' );
        }
        $from    = isset( $assoc_args['from'] ) ? sanitize_text_field( (string) $assoc_args['from'] ) : '';
        $dry_run = isset( $assoc_args['dry-run'] );

        if ( $from !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
            \WP_CLI::error( '--from は YYYY-MM-DD 形式で指定してください' );
        }

        $meo_table = $wpdb->prefix . 'gcrev_meo_results';

        // --- gcrev_meo_results 行 ---
        if ( $from !== '' ) {
            $row_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$meo_table} WHERE user_id = %d AND fetch_date >= %s",
                $user_id, $from
            ) );
        } else {
            $row_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$meo_table} WHERE user_id = %d",
                $user_id
            ) );
        }

        // --- gcrev_meo_rank_* Transient（options テーブル）---
        $like_value   = $wpdb->esc_like( '_transient_gcrev_meo_rank_' . $user_id . '_' ) . '%';
        $like_timeout = $wpdb->esc_like( '_transient_timeout_gcrev_meo_rank_' . $user_id . '_' ) . '%';
        $transient_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like_value
        ) );

        \WP_CLI::log( "対象ユーザー: user_id={$user_id}" . ( $from !== '' ? " （fetch_date >= {$from}）" : ' （全期間）' ) );
        \WP_CLI::log( "  gcrev_meo_results 行:   {$row_count} 件" );
        \WP_CLI::log( "  gcrev_meo_rank Transient: {$transient_count} 件" );

        if ( $dry_run ) {
            \WP_CLI::success( 'dry-run: 削除は行っていません。' );
            return;
        }

        // 行削除
        if ( $from !== '' ) {
            $deleted_rows = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$meo_table} WHERE user_id = %d AND fetch_date >= %s",
                $user_id, $from
            ) );
        } else {
            $deleted_rows = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$meo_table} WHERE user_id = %d",
                $user_id
            ) );
        }

        // Transient 削除（値・timeout の両方）
        $deleted_tr = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like_value, $like_timeout
        ) );

        \WP_CLI::success( "削除完了: meo_results {$deleted_rows} 行 / Transient {$deleted_tr} 件。"
            . '「最新の情報を見る」または日次 cron で再取得されます。' );
    }

    /**
     * DataForSEO の Maps / Local Finder 生レスポンスを診断表示する
     *
     * 指定ユーザーの基準座標で実際にAPIを叩き、各結果の type(maps_search/maps_paid)・
     * rank_group・rank_absolute・domain・評価を一覧表示する。
     * 「広告(maps_paid)が順位に混入していないか」「自然枠での本当の位置」を確認する。
     * 保存・キャッシュは行わない（読み取り専用診断）。
     *
     * ## OPTIONS
     *
     * --user_id=<id>
     * : 対象のユーザーID
     *
     * --keyword=<keyword>
     * : 検索キーワード（例: "松山市 歯科"）
     *
     * [--device=<device>]
     * : mobile | desktop（デフォルト: mobile）
     *
     * ## EXAMPLES
     *
     *     wp mimamori meo probe --user_id=2 --keyword="松山市 歯科" --device=mobile
     */
    public function probe( array $args, array $assoc_args ): void {
        $user_id = isset( $assoc_args['user_id'] ) ? (int) $assoc_args['user_id'] : 0;
        $keyword = isset( $assoc_args['keyword'] ) ? (string) $assoc_args['keyword'] : '';
        $device  = isset( $assoc_args['device'] ) ? (string) $assoc_args['device'] : 'mobile';
        if ( $user_id <= 0 ) {
            \WP_CLI::error( '--user_id は必須です' );
        }
        if ( $keyword === '' ) {
            \WP_CLI::error( '--keyword は必須です' );
        }
        if ( ! in_array( $device, [ 'mobile', 'desktop' ], true ) ) {
            $device = 'mobile';
        }

        if ( ! class_exists( 'Gcrev_Insight_API' ) ) {
            \WP_CLI::error( 'Gcrev_Insight_API クラスが見つかりません' );
        }
        global $gcrev_api_instance;
        if ( ! isset( $gcrev_api_instance ) || ! ( $gcrev_api_instance instanceof \Gcrev_Insight_API ) ) {
            $gcrev_api_instance = new \Gcrev_Insight_API( false );
        }

        $r = $gcrev_api_instance->meo_probe_raw( $user_id, $keyword, $device );

        if ( isset( $r['error'] ) ) {
            \WP_CLI::error( $r['error'] );
        }

        \WP_CLI::log( "keyword='{$keyword}' device={$device}" );
        \WP_CLI::log( "coordinate={$r['coordinate']} (zoom={$r['zoom']}, radius={$r['radius']}m)" );
        \WP_CLI::log( "match_domain={$r['match_domain']}" );
        \WP_CLI::log( str_repeat( '-', 60 ) );

        $render = function ( string $label, array $items, string $err ) use ( $r ) {
            \WP_CLI::log( "■ {$label}" . ( $err !== '' ? "  (ERROR: {$err})" : '' ) );
            if ( empty( $items ) ) {
                \WP_CLI::log( '  （結果なし）' );
                return;
            }
            \WP_CLI::log( sprintf( '  %-4s %-4s %-14s %-22s %-26s %s',
                'grp', 'abs', 'type', 'domain', 'title', 'rating/reviews' ) );
            foreach ( $items as $it ) {
                $self = ( $it['domain'] !== '' && $r['match_domain'] !== ''
                    && preg_replace( '/^www\./i', '', strtolower( $it['domain'] ) ) === strtolower( $r['match_domain'] ) )
                    ? ' ★self(domain)' : '';
                \WP_CLI::log( sprintf( '  %-4s %-4s %-14s %-22s %-26s %s/%s%s',
                    (string) ( $it['rank_group'] ?? '-' ),
                    (string) ( $it['rank_absolute'] ?? '-' ),
                    (string) $it['type'],
                    (string) ( $it['domain'] ?: '-' ),
                    mb_strimwidth( (string) $it['title'], 0, 24, '…' ),
                    (string) ( $it['rating'] ?? '-' ),
                    (string) ( $it['reviews'] ?? '-' ),
                    $self
                ) );
            }
        };

        $render( 'Maps SERP', $r['maps'], $r['maps_error'] );
        \WP_CLI::log( str_repeat( '-', 60 ) );
        $render( 'Local Finder SERP', $r['finder'], $r['finder_error'] );
        \WP_CLI::success( '診断完了（保存・キャッシュはしていません）' );
    }
}
