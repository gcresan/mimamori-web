<?php
// FILE: inc/gcrev-api/modules/class-clarity-client.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Clarity_Client') ) { return; }

/**
 * Gcrev_Clarity_Client
 *
 * Microsoft Clarity Data Export API 通信クライアント。
 * 接続テスト・ライブインサイト取得を行う。
 *
 * 認証: ユーザーごとの user_meta に保存された API トークン（暗号化済み）
 *
 * @package Mimamori_Web
 * @since   2.6.0
 */
class Gcrev_Clarity_Client {

    /** API ベース URL */
    private const API_BASE = 'https://www.clarity.ms/export-data/api/v1';

    /** HTTP タイムアウト（秒） */
    private const HTTP_TIMEOUT = 30;

    /** デバッグログファイル */
    private const LOG_FILE = '/tmp/gcrev_clarity_debug.log';

    // =========================================================
    // User Meta キー定数
    // =========================================================

    /** Clarity 連携有効フラグ */
    public const META_ENABLED           = '_gcrev_clarity_enabled';

    /** API トークン（暗号化保存） */
    public const META_API_TOKEN         = '_gcrev_clarity_api_token';

    /** プロジェクト名メモ（任意） */
    public const META_PROJECT_NAME      = '_gcrev_clarity_project_name';

    /** プロジェクトID（ClarityダッシュボードURL用） */
    public const META_PROJECT_ID        = '_gcrev_clarity_project_id';

    /** 接続ステータス: 'success' | 'failed' | '' */
    public const META_CONNECTION_STATUS = '_gcrev_clarity_connection_status';

    /** 最終接続確認日時 (Y-m-d H:i:s) */
    public const META_LAST_CONNECTED    = '_gcrev_clarity_last_connected_at';

    /** 最終接続メッセージ */
    public const META_LAST_MESSAGE      = '_gcrev_clarity_last_connection_message';

    // =========================================================
    // 設定読み書き
    // =========================================================

    /**
     * ユーザーの Clarity 設定を取得
     *
     * @param int $user_id
     * @return array
     */
    public static function get_settings( int $user_id ): array {
        $enabled      = get_user_meta( $user_id, self::META_ENABLED, true );
        $token_raw    = get_user_meta( $user_id, self::META_API_TOKEN, true );
        $project_name = get_user_meta( $user_id, self::META_PROJECT_NAME, true );
        $project_id   = get_user_meta( $user_id, self::META_PROJECT_ID, true );
        $status       = get_user_meta( $user_id, self::META_CONNECTION_STATUS, true );
        $last_conn    = get_user_meta( $user_id, self::META_LAST_CONNECTED, true );
        $last_msg     = get_user_meta( $user_id, self::META_LAST_MESSAGE, true );

        // トークンマスク表示用
        $has_token  = ! empty( $token_raw );
        $token_mask = '';
        if ( $has_token ) {
            $decrypted = self::decrypt_token( $token_raw );
            if ( strlen( $decrypted ) > 8 ) {
                $token_mask = substr( $decrypted, 0, 4 ) . str_repeat( '*', 8 ) . substr( $decrypted, -4 );
            } else {
                $token_mask = '****';
            }
        }

        return [
            'clarity_enabled'           => $enabled === '1',
            'clarity_has_token'         => $has_token,
            'clarity_token_mask'        => $token_mask,
            'clarity_project_name'      => $project_name ?: '',
            'clarity_project_id'        => $project_id ?: '',
            'clarity_connection_status' => $status ?: '',
            'clarity_last_connected_at' => $last_conn ?: '',
            'clarity_last_message'      => $last_msg ?: '',
        ];
    }

    /**
     * Clarity 設定を保存
     *
     * @param int    $user_id
     * @param array  $data
     * @return void
     */
    public static function save_settings( int $user_id, array $data ): void {
        // enabled
        if ( isset( $data['clarity_enabled'] ) ) {
            update_user_meta( $user_id, self::META_ENABLED, $data['clarity_enabled'] ? '1' : '0' );
        }

        // API トークン（空でなければ暗号化して保存、空なら既存を維持）
        if ( isset( $data['clarity_api_token'] ) ) {
            $token = sanitize_text_field( $data['clarity_api_token'] );
            if ( $token !== '' ) {
                $encrypted = self::encrypt_token( $token );
                update_user_meta( $user_id, self::META_API_TOKEN, $encrypted );
            }
            // 空文字の場合は既存トークンを維持（設定画面ではパスワードフィールドが常に空のため）
        }

        // プロジェクト名
        if ( isset( $data['clarity_project_name'] ) ) {
            update_user_meta( $user_id, self::META_PROJECT_NAME,
                sanitize_text_field( $data['clarity_project_name'] )
            );
        }

        // プロジェクトID（ClarityダッシュボードURL用）
        if ( isset( $data['clarity_project_id'] ) ) {
            update_user_meta( $user_id, self::META_PROJECT_ID,
                sanitize_text_field( $data['clarity_project_id'] )
            );
        }
    }

    // =========================================================
    // 接続テスト
    // =========================================================

    /**
     * 接続テストを実行
     *
     * @param int $user_id
     * @return array { success: bool, message: string, data?: array }
     */
    public static function test_connection( int $user_id ): array {
        $token_raw = get_user_meta( $user_id, self::META_API_TOKEN, true );
        if ( empty( $token_raw ) ) {
            self::save_connection_result( $user_id, 'failed', 'APIトークンが設定されていません' );
            return [ 'success' => false, 'message' => 'APIトークンが設定されていません' ];
        }

        $token = self::decrypt_token( $token_raw );
        if ( empty( $token ) ) {
            self::save_connection_result( $user_id, 'failed', 'APIトークンの復号に失敗しました' );
            return [ 'success' => false, 'message' => 'APIトークンの復号に失敗しました' ];
        }

        $url = self::API_BASE . '/project-live-insights';

        $response = wp_remote_get( $url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ] );

        // ネットワークエラー
        if ( is_wp_error( $response ) ) {
            $err_msg = $response->get_error_message();
            self::log( "CONNECTION TEST ERROR: {$err_msg}" );
            self::save_connection_result( $user_id, 'failed', 'Clarity APIへの接続に失敗しました' );
            return [ 'success' => false, 'message' => 'Clarity APIへの接続に失敗しました' ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        self::log( "CONNECTION TEST: HTTP {$code}, body=" . substr( $body, 0, 500 ) );

        // HTTP ステータス別処理
        if ( $code === 200 ) {
            $json = json_decode( $body, true );
            self::save_connection_result( $user_id, 'success', '接続に成功しました' );
            return [
                'success' => true,
                'message' => '接続に成功しました',
                'data'    => $json,
            ];
        }

        // エラーレスポンス
        $user_message = self::get_user_friendly_error( $code );
        self::save_connection_result( $user_id, 'failed', $user_message );
        return [ 'success' => false, 'message' => $user_message ];
    }

    // =========================================================
    // API呼び出しヘルパー（将来の拡張用）
    // =========================================================

    /**
     * Clarity API に GET リクエスト
     *
     * @param string $endpoint  例: '/project-live-insights'
     * @param string $token     復号済みトークン
     * @param array  $params    クエリパラメータ
     * @return array { success: bool, code: int, data: mixed, error?: string }
     */
    public static function api_get( string $endpoint, string $token, array $params = [] ): array {
        $url = self::API_BASE . $endpoint;
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            $err = $response->get_error_message();
            self::log( "API GET ERROR: endpoint={$endpoint}, error={$err}" );
            return [ 'success' => false, 'code' => 0, 'data' => null, 'error' => $err ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( $code !== 200 ) {
            self::log( "API GET FAILED: endpoint={$endpoint}, HTTP {$code}, body=" . substr( $body, 0, 500 ) );
            return [ 'success' => false, 'code' => $code, 'data' => $json, 'error' => self::get_user_friendly_error( $code ) ];
        }

        return [ 'success' => true, 'code' => 200, 'data' => $json ];
    }

    /**
     * ユーザーの復号済みトークンを取得
     *
     * @param int $user_id
     * @return string|null
     */
    public static function get_decrypted_token( int $user_id ): ?string {
        $raw = get_user_meta( $user_id, self::META_API_TOKEN, true );
        if ( empty( $raw ) ) { return null; }
        $token = self::decrypt_token( $raw );
        return $token ?: null;
    }

    // =========================================================
    // 手動 / 自動同期
    // =========================================================

    /**
     * Clarity データ同期を実行
     *
     * 3回のAPIコール（URL別、Device別、全体サマリー）で
     * 取得可能なデータを網羅的に取得し、保存する。
     *
     * @param int    $user_id
     * @param string $sync_type 'manual' | 'scheduled'
     * @return array { success, message, summary }
     */
    public static function sync_data( int $user_id, string $sync_type = 'manual', int $num_of_days = 3 ): array {
        global $wpdb;

        $started_at = current_time( 'Y-m-d H:i:s' );

        // 前提チェック
        $enabled = get_user_meta( $user_id, self::META_ENABLED, true );
        if ( $enabled !== '1' ) {
            return [ 'success' => false, 'message' => 'Clarity連携が無効です' ];
        }

        $token = self::get_decrypted_token( $user_id );
        if ( ! $token ) {
            return [ 'success' => false, 'message' => 'APIトークンが未設定です' ];
        }

        $log_table = $wpdb->prefix . 'gcrev_clarity_sync_logs';
        $metrics_fetched = 0;
        $pages_updated   = 0;
        $all_responses   = [];
        $errors          = [];

        // ---- API呼び出し（最大3回 / 1日10回制限に配慮） ----

        // 1) URL別データ（ページ分析用）
        $url_result = self::api_get( '/project-live-insights', $token, [
            'numOfDays' => $num_of_days,
            'dimension1' => 'URL',
        ] );
        $all_responses['by_url'] = $url_result;
        if ( $url_result['success'] ) {
            $metrics_fetched += count( $url_result['data'] ?? [] );
        } else {
            $errors[] = 'URL別取得失敗: ' . ( $url_result['error'] ?? 'unknown' );
        }

        // 2) Device別データ
        $device_result = self::api_get( '/project-live-insights', $token, [
            'numOfDays' => $num_of_days,
            'dimension1' => 'Device',
        ] );
        $all_responses['by_device'] = $device_result;
        if ( $device_result['success'] ) {
            $metrics_fetched += count( $device_result['data'] ?? [] );
        }

        // 3) URL × Device データ（ページ別デバイス差分用）
        $url_device_result = self::api_get( '/project-live-insights', $token, [
            'numOfDays' => $num_of_days,
            'dimension1' => 'URL',
            'dimension2' => 'Device',
        ] );
        $all_responses['by_url_device'] = $url_device_result;
        if ( $url_device_result['success'] ) {
            $metrics_fetched += count( $url_device_result['data'] ?? [] );
        }

        // ---- データ正規化 & 既存ページ分析テーブルに反映 ----
        $normalized = self::normalize_responses( $all_responses );

        if ( ! empty( $normalized['pages'] ) ) {
            $pa_table = $wpdb->prefix . 'gcrev_page_analysis';

            // 登録済みページ一覧を取得（URL正規化マッチ用）
            $registered = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, page_url FROM {$pa_table} WHERE user_id = %d AND status = 'active'",
                $user_id
            ), ARRAY_A );

            // 正規化URLマップ: normalized_url => id
            $url_map = [];
            foreach ( $registered as $row ) {
                $url_map[ self::normalize_url( $row['page_url'] ) ] = (int) $row['id'];
            }

            $now = current_time( 'Y-m-d H:i:s' );
            $matched_urls   = [];
            $unmatched_urls = [];

            // サイト全体のDevice別データを取得
            $site_wide_data = $normalized['summary']['site_wide'] ?? [];

            foreach ( $normalized['pages'] as $page_url => $page_data ) {
                $norm_url = self::normalize_url( $page_url );
                $pa_id    = $url_map[ $norm_url ] ?? null;

                if ( $pa_id ) {
                    // サイト全体のDevice別データをページに付与
                    $page_data['site_wide'] = $site_wide_data;
                    $clarity_json = wp_json_encode( $page_data, JSON_UNESCAPED_UNICODE );
                    $wpdb->update( $pa_table, [
                        'clarity_data'      => $clarity_json,
                        'clarity_sync_date' => $now,
                    ], [ 'id' => $pa_id ] );
                    $pages_updated++;
                    $matched_urls[] = $page_url;
                } else {
                    $unmatched_urls[] = $page_url;
                }
            }

            self::log( "SYNC MATCH: matched=" . count( $matched_urls )
                . ", unmatched=" . count( $unmatched_urls )
                . ( $unmatched_urls ? "\nUnmatched URLs: " . implode( ', ', array_slice( $unmatched_urls, 0, 10 ) ) : '' )
            );
        }

        // ---- 同期結果判定 ----
        $status  = 'success';
        $message = '同期が完了しました';
        if ( ! empty( $errors ) && $metrics_fetched === 0 ) {
            $status  = 'failed';
            $message = implode( '; ', $errors );
        } elseif ( ! empty( $errors ) ) {
            $status  = 'partial';
            $message = '一部データのみ取得しました';
        }

        $finished_at = current_time( 'Y-m-d H:i:s' );

        // ---- 同期ログ保存 ----
        $wpdb->insert( $log_table, [
            'user_id'          => $user_id,
            'sync_type'        => $sync_type,
            'status'           => $status,
            'num_of_days'      => $num_of_days,
            'dimensions'       => 'URL,Device,URL+Device',
            'metrics_fetched'  => $metrics_fetched,
            'pages_updated'    => $pages_updated,
            'response_json'    => wp_json_encode( $all_responses, JSON_UNESCAPED_UNICODE ),
            'error_message'    => ! empty( $errors ) ? implode( "\n", $errors ) : null,
            'started_at'       => $started_at,
            'finished_at'      => $finished_at,
        ] );

        // user meta に最終同期情報を保存
        update_user_meta( $user_id, '_gcrev_clarity_last_sync_at', $finished_at );
        update_user_meta( $user_id, '_gcrev_clarity_last_sync_status', $status );
        update_user_meta( $user_id, '_gcrev_clarity_last_sync_message', $message );

        return [
            'success' => $status !== 'failed',
            'message' => $message,
            'summary' => [
                'status'          => $status,
                'metrics_fetched' => $metrics_fetched,
                'pages_updated'   => $pages_updated,
                'synced_at'       => $finished_at,
                'normalized'      => $normalized['summary'] ?? [],
                'matched_urls'    => $matched_urls ?? [],
                'unmatched_urls'  => $unmatched_urls ?? [],
            ],
        ];
    }

    /**
     * sync_data() の結果を gcrev_clarity_daily テーブルに日次スナップショットとして保存
     *
     * @param int   $user_id
     * @param array $sync_result  sync_data() の戻り値
     * @param int   $num_of_days  取得日数（1: 昨日のみ, 3: 3日平均）
     */
    public static function save_daily_snapshot( int $user_id, array $sync_result, int $num_of_days = 1 ): void {
        global $wpdb;

        if ( ! $sync_result['success'] ) return;

        $daily_table = $wpdb->prefix . 'gcrev_clarity_daily';
        $pa_table    = $wpdb->prefix . 'gcrev_page_analysis';
        $is_estimated = ( $num_of_days > 1 ) ? 1 : 0;

        // target_date: numOfDays=1 なら昨日、それ以外は今日（平均として）
        $target_date = ( $num_of_days === 1 )
            ? gmdate( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) )
            : current_time( 'Y-m-d' );

        // 登録済みページ一覧
        $pages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, page_url, clarity_data FROM {$pa_table} WHERE user_id = %d AND status = 'active' AND clarity_data IS NOT NULL",
            $user_id
        ), ARRAY_A );

        foreach ( $pages as $page ) {
            $clarity = json_decode( $page['clarity_data'], true );
            if ( empty( $clarity ) ) continue;

            $site_wide = $clarity['site_wide']['by_device'] ?? [];

            foreach ( [ 'PC' => 'pc', 'Mobile' => 'mobile' ] as $api_key => $db_key ) {
                $dev = $site_wide[ $api_key ] ?? [];
                if ( empty( $dev ) ) continue;

                $t  = $dev['traffic'] ?? [];
                $sd = $dev['scroll_depth'] ?? [];
                $et = $dev['engagement_time'] ?? [];
                $dc = $dev['dead_click_count'] ?? [];
                $rc = $dev['rage_click_count'] ?? [];
                $ec = $dev['error_click_count'] ?? [];

                $row_data = [
                    'user_id'          => $user_id,
                    'page_analysis_id' => (int) $page['id'],
                    'device_type'      => $db_key,
                    'target_date'      => $target_date,
                    'sessions'         => (int) ( $t['sessionsCount'] ?? $t['totalSessionCount'] ?? 0 ),
                    'page_views'       => (int) ( $t['pagesViews'] ?? 0 ),
                    'scroll_depth'     => isset( $sd['averageScrollDepth'] ) ? round( (float) $sd['averageScrollDepth'], 2 ) : null,
                    'engagement_time'  => isset( $et['activeTime'] ) ? (int) $et['activeTime'] : ( isset( $et['totalTime'] ) ? (int) $et['totalTime'] : null ),
                    'dead_click'       => (int) ( $dc['subTotal'] ?? $dc['sessionsCount'] ?? 0 ),
                    'rage_click'       => (int) ( $rc['subTotal'] ?? $rc['sessionsCount'] ?? 0 ),
                    'error_click'      => (int) ( $ec['subTotal'] ?? $ec['sessionsCount'] ?? 0 ),
                    'source'           => 'clarity_api',
                    'is_estimated'     => $is_estimated,
                    'raw_json'         => wp_json_encode( $dev, JSON_UNESCAPED_UNICODE ),
                ];

                // UPSERT: UNIQUE KEY (user_id, page_analysis_id, device_type, target_date) で冪等
                $wpdb->replace( $daily_table, $row_data );
            }
        }

        self::log( "DAILY SNAPSHOT: user={$user_id}, date={$target_date}, pages=" . count( $pages ) . ", estimated={$is_estimated}" );
    }

    /**
     * 最新の同期ログを取得
     *
     * @param int $user_id
     * @param int $limit
     * @return array
     */
    public static function get_sync_logs( int $user_id, int $limit = 5 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_clarity_sync_logs';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sync_type, status, metrics_fetched, pages_updated,
                    error_message, started_at, finished_at
             FROM {$table}
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $user_id, $limit
        ), ARRAY_A ) ?: [];
    }

    /**
     * 最新の同期結果サマリーを取得
     *
     * @param int $user_id
     * @return array
     */
    public static function get_sync_summary( int $user_id ): array {
        return [
            'last_sync_at'      => get_user_meta( $user_id, '_gcrev_clarity_last_sync_at', true ) ?: '',
            'last_sync_status'  => get_user_meta( $user_id, '_gcrev_clarity_last_sync_status', true ) ?: '',
            'last_sync_message' => get_user_meta( $user_id, '_gcrev_clarity_last_sync_message', true ) ?: '',
        ];
    }

    // =========================================================
    // データ正規化
    // =========================================================

    /**
     * APIレスポンスを正規化
     *
     * @param array $responses  { by_url, by_device, by_url_device }
     * @return array { pages: { url => data }, summary: { ... } }
     */
    private static function normalize_responses( array $responses ): array {
        $pages   = [];
        $summary = [
            'available_metrics' => [],
            'total_urls'        => 0,
            'device_types'      => [],
        ];

        // URL別データの正規化
        // Clarity APIは ?gclid= 等パラメータ付きURLを個別に返すため、
        // ベースURL（パラメータ除去）で集約する
        if ( ! empty( $responses['by_url']['data'] ) && is_array( $responses['by_url']['data'] ) ) {
            foreach ( $responses['by_url']['data'] as $metric_block ) {
                $metric_name = $metric_block['metricName'] ?? '';
                if ( empty( $metric_name ) ) continue;

                $summary['available_metrics'][] = $metric_name;
                $key = self::normalize_metric_key( $metric_name );

                foreach ( $metric_block['information'] ?? [] as $row ) {
                    $raw_url = $row['Url'] ?? $row['URL'] ?? $row['url'] ?? '';
                    if ( empty( $raw_url ) ) continue;

                    // ベースURL（クエリ・フラグメント除去）で集約
                    $base_url = preg_replace( '/[?#].*$/', '', $raw_url );
                    $base_url = rtrim( $base_url, '/' );
                    if ( empty( $base_url ) ) continue;

                    if ( ! isset( $pages[ $base_url ] ) ) {
                        $pages[ $base_url ] = [ 'metrics' => [], 'devices' => [], '_raw_url_count' => 0 ];
                    }
                    $pages[ $base_url ]['_raw_url_count']++;

                    $values = self::extract_metric_value( $row, $metric_name );

                    // 集約: sessionsCount は合算、率(%)は加重平均用に蓄積
                    if ( ! isset( $pages[ $base_url ]['metrics'][ $key ] ) ) {
                        $pages[ $base_url ]['metrics'][ $key ] = $values;
                        $pages[ $base_url ]['metrics'][ $key ]['_agg_count'] = 1;
                    } else {
                        $existing = &$pages[ $base_url ]['metrics'][ $key ];
                        $existing['_agg_count'] = ( $existing['_agg_count'] ?? 1 ) + 1;
                        // sessionsCount: 合算
                        if ( isset( $values['sessionsCount'] ) ) {
                            $existing['sessionsCount'] = (string)( intval( $existing['sessionsCount'] ?? 0 ) + intval( $values['sessionsCount'] ) );
                        }
                        // pagesViews: 合算
                        if ( isset( $values['pagesViews'] ) ) {
                            $existing['pagesViews'] = (string)( intval( $existing['pagesViews'] ?? 0 ) + intval( $values['pagesViews'] ) );
                        }
                        // subTotal: 合算
                        if ( isset( $values['subTotal'] ) ) {
                            $existing['subTotal'] = (string)( intval( $existing['subTotal'] ?? 0 ) + intval( $values['subTotal'] ) );
                        }
                        // sessionsWithMetricPercentage: セッション数加重で再計算
                        if ( isset( $values['sessionsWithMetricPercentage'] ) && isset( $values['sessionsCount'] ) ) {
                            $s1 = intval( $existing['sessionsCount'] ?? 0 ) - intval( $values['sessionsCount'] ?? 0 );
                            $s2 = intval( $values['sessionsCount'] ?? 0 );
                            $total_s = $s1 + $s2;
                            if ( $total_s > 0 ) {
                                $p1 = floatval( $existing['sessionsWithMetricPercentage'] ?? 0 );
                                $p2 = floatval( $values['sessionsWithMetricPercentage'] ?? 0 );
                                $existing['sessionsWithMetricPercentage'] = round( ( $p1 * $s1 + $p2 * $s2 ) / $total_s, 2 );
                            }
                        }
                        unset( $existing );
                    }
                }
            }
        }

        // Device別データの正規化（サイト全体の正確な集計値）
        // URL別データは1000行制限で不正確になるため、
        // サマリー表示にはDevice別データを使用する
        $site_wide = [ 'by_device' => [] ];
        if ( ! empty( $responses['by_device']['data'] ) && is_array( $responses['by_device']['data'] ) ) {
            foreach ( $responses['by_device']['data'] as $metric_block ) {
                $metric_name = $metric_block['metricName'] ?? '';
                if ( empty( $metric_name ) ) continue;
                $key = self::normalize_metric_key( $metric_name );

                foreach ( $metric_block['information'] ?? [] as $row ) {
                    $device = $row['Device'] ?? $row['device'] ?? '';
                    if ( empty( $device ) ) continue;
                    if ( ! in_array( $device, $summary['device_types'], true ) ) {
                        $summary['device_types'][] = $device;
                    }
                    if ( ! isset( $site_wide['by_device'][ $device ] ) ) {
                        $site_wide['by_device'][ $device ] = [];
                    }
                    $site_wide['by_device'][ $device ][ $key ] = self::extract_metric_value( $row, $metric_name );
                }
            }
        }
        // サイト全体のデバイス別集計値を全ページに反映
        $summary['site_wide'] = $site_wide;

        // URL×Device データの正規化（ベースURLで集約）
        if ( ! empty( $responses['by_url_device']['data'] ) && is_array( $responses['by_url_device']['data'] ) ) {
            foreach ( $responses['by_url_device']['data'] as $metric_block ) {
                $metric_name = $metric_block['metricName'] ?? '';
                if ( empty( $metric_name ) ) continue;

                $key = self::normalize_metric_key( $metric_name );
                foreach ( $metric_block['information'] ?? [] as $row ) {
                    $raw_url = $row['Url'] ?? $row['URL'] ?? $row['url'] ?? '';
                    $device  = $row['Device'] ?? $row['device'] ?? '';
                    if ( empty( $raw_url ) || empty( $device ) ) continue;

                    $base_url = rtrim( preg_replace( '/[?#].*$/', '', $raw_url ), '/' );
                    if ( empty( $base_url ) ) continue;

                    if ( ! isset( $pages[ $base_url ] ) ) {
                        $pages[ $base_url ] = [ 'metrics' => [], 'devices' => [], '_raw_url_count' => 0 ];
                    }
                    if ( ! isset( $pages[ $base_url ]['devices'][ $device ] ) ) {
                        $pages[ $base_url ]['devices'][ $device ] = [];
                    }

                    $values = self::extract_metric_value( $row, $metric_name );

                    if ( ! isset( $pages[ $base_url ]['devices'][ $device ][ $key ] ) ) {
                        $pages[ $base_url ]['devices'][ $device ][ $key ] = $values;
                    } else {
                        // 合算
                        $ex = &$pages[ $base_url ]['devices'][ $device ][ $key ];
                        if ( isset( $values['sessionsCount'] ) ) {
                            $ex['sessionsCount'] = (string)( intval( $ex['sessionsCount'] ?? 0 ) + intval( $values['sessionsCount'] ) );
                        }
                        if ( isset( $values['subTotal'] ) ) {
                            $ex['subTotal'] = (string)( intval( $ex['subTotal'] ?? 0 ) + intval( $values['subTotal'] ) );
                        }
                        if ( isset( $values['pagesViews'] ) ) {
                            $ex['pagesViews'] = (string)( intval( $ex['pagesViews'] ?? 0 ) + intval( $values['pagesViews'] ) );
                        }
                        if ( isset( $values['sessionsWithMetricPercentage'] ) && isset( $values['sessionsCount'] ) ) {
                            $s1 = intval( $ex['sessionsCount'] ?? 0 ) - intval( $values['sessionsCount'] ?? 0 );
                            $s2 = intval( $values['sessionsCount'] ?? 0 );
                            $total_s = $s1 + $s2;
                            if ( $total_s > 0 ) {
                                $ex['sessionsWithMetricPercentage'] = round(
                                    ( floatval( $ex['sessionsWithMetricPercentage'] ?? 0 ) * $s1 + floatval( $values['sessionsWithMetricPercentage'] ?? 0 ) * $s2 ) / $total_s, 2
                                );
                            }
                        }
                        unset( $ex );
                    }
                }
            }
        }

        $summary['available_metrics'] = array_unique( $summary['available_metrics'] );
        $summary['total_urls']        = count( $pages );

        return [ 'pages' => $pages, 'summary' => $summary ];
    }

    /**
     * メトリクス名をスネークケースのキーに正規化
     */
    private static function normalize_metric_key( string $metric_name ): string {
        $map = [
            'Traffic'           => 'traffic',
            'EngagementTime'    => 'engagement_time',
            'ScrollDepth'       => 'scroll_depth',
            'ExcessiveScroll'   => 'excessive_scroll',
            'DeadClickCount'    => 'dead_click_count',
            'RageClickCount'    => 'rage_click_count',
            'QuickbackClick'    => 'quickback_click',
            'ErrorClickCount'   => 'error_click_count',
            'ScriptErrorCount'  => 'script_error_count',
            'PopularPages'      => 'popular_pages',
        ];
        return $map[ $metric_name ] ?? strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $metric_name ) );
    }

    /**
     * メトリクス行から値を抽出
     */
    private static function extract_metric_value( array $row, string $metric_name ): array {
        $value = [];
        foreach ( $row as $k => $v ) {
            // ディメンション列はスキップ
            if ( in_array( $k, [ 'Url', 'URL', 'url', 'Device', 'device', 'Browser', 'OS', 'Country/Region', 'Source', 'Medium', 'Campaign', 'Channel' ], true ) ) {
                continue;
            }
            $value[ $k ] = $v;
        }
        return $value;
    }

    // =========================================================
    // 内部ヘルパー
    // =========================================================

    /**
     * 接続結果を user meta に保存
     */
    private static function save_connection_result( int $user_id, string $status, string $message ): void {
        $now = current_time( 'Y-m-d H:i:s' );
        update_user_meta( $user_id, self::META_CONNECTION_STATUS, $status );
        update_user_meta( $user_id, self::META_LAST_CONNECTED, $now );
        update_user_meta( $user_id, self::META_LAST_MESSAGE, $message );
    }

    /**
     * HTTP ステータスコードからユーザー向けメッセージ
     */
    private static function get_user_friendly_error( int $code ): string {
        switch ( $code ) {
            case 401:
                return 'APIトークンが無効の可能性があります。トークンを再確認してください';
            case 403:
                return 'アクセス権限がありません。トークンの権限設定を確認してください';
            case 429:
                return 'APIリクエスト制限に達しました。時間をおいて再度お試しください';
            default:
                if ( $code >= 500 ) {
                    return 'Clarity側でサーバーエラーが発生しました。時間をおいて再度お試しください';
                }
                return "Clarity APIへの接続に失敗しました（HTTP {$code}）";
        }
    }

    /**
     * トークンを暗号化
     */
    private static function encrypt_token( string $token ): string {
        if ( class_exists( 'Gcrev_Crypto' ) && Gcrev_Crypto::is_available() ) {
            return Gcrev_Crypto::encrypt( $token );
        }
        return $token; // フォールバック: 平文
    }

    /**
     * トークンを復号
     */
    private static function decrypt_token( string $stored ): string {
        if ( class_exists( 'Gcrev_Crypto' ) && Gcrev_Crypto::is_available() ) {
            return Gcrev_Crypto::decrypt( $stored );
        }
        return $stored;
    }

    /**
     * デバッグログ出力
     */
    /**
     * URL を正規化（マッチング精度向上用）
     * 末尾スラッシュ・プロトコル・www の差異を吸収
     */
    private static function normalize_url( string $url ): string {
        $url = strtolower( trim( $url ) );
        // プロトコル除去
        $url = preg_replace( '#^https?://#', '', $url );
        // www 除去
        $url = preg_replace( '#^www\.#', '', $url );
        // 末尾スラッシュ除去
        $url = rtrim( $url, '/' );
        // クエリ・フラグメント除去
        $url = preg_replace( '/[?#].*$/', '', $url );
        return $url;
    }

    private static function log( string $message ): void {
        file_put_contents(
            self::LOG_FILE,
            date( 'Y-m-d H:i:s' ) . " [Clarity] {$message}\n",
            FILE_APPEND
        );
    }
}
