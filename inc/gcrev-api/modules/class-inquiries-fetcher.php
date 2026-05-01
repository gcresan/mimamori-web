<?php
/**
 * Mimamori_Inquiries_Fetcher
 *
 * 契約サイトに導入された「mimamori-inquiries-api」プラグインから
 * 月次の有効問い合わせ件数を取得して DB に保存するクライアント側モジュール。
 *
 * - 月初 09:30 に前月分を全ユーザー分取得（Cron）
 * - 取得結果は wp_gcrev_inquiries（月次確定値）に upsert
 * - 取得設定は user_meta（_gcrev_inquiries_endpoint, _gcrev_inquiries_token）
 *   トークンは Gcrev_Crypto で暗号化して保存
 * - REST: 手動取得 / 月次データ取得 / 設定更新
 *
 * @package Mimamori_Web
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( class_exists( 'Mimamori_Inquiries_Fetcher' ) ) {
    return;
}

class Mimamori_Inquiries_Fetcher {

    public const CRON_HOOK     = 'gcrev_inquiries_monthly_fetch_event';
    public const META_ENDPOINT = '_gcrev_inquiries_endpoint';
    public const META_TOKEN    = '_gcrev_inquiries_token';
    public const META_ENABLED  = '_gcrev_inquiries_enabled';

    private const TABLE_BASENAME = 'gcrev_inquiries';
    private const REQUEST_TIMEOUT = 15;
    private const DEBUG_LOG      = '/tmp/gcrev_inquiries_debug.log';

    /**
     * フック登録
     */
    public function register(): void {
        add_action( 'init', [ $this, 'maybe_install_table' ], 5 );
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_action( self::CRON_HOOK, [ $this, 'run_monthly_fetch' ] );
    }

    /* ================================================================
     * テーブル定義
     * ================================================================ */

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_BASENAME;
    }

    /**
     * 必要なら CREATE TABLE。バージョン option で重複実行を抑止。
     * dbDelta が失敗した場合のフォールバックとして直接 CREATE TABLE も試みる。
     */
    public function maybe_install_table(): void {
        global $wpdb;
        $table   = self::table_name();

        // 既にテーブルがあるなら何もしない
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists === $table ) {
            update_option( 'gcrev_inquiries_db_version', '1.0', false );
            return;
        }

        $charset = $wpdb->get_charset_collate();

        // dbDelta は PRIMARY KEY の前後にスペース2個など独特のフォーマット要件があるので
        // それに合わせる。各列名はバッククォートで囲み、リザーブドワード問題（year_month 等）を回避。
        $sql = "CREATE TABLE {$table} (
 `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 `user_id` bigint(20) unsigned NOT NULL,
 `year_month` char(7) NOT NULL,
 `total` int(10) unsigned NOT NULL DEFAULT 0,
 `valid_count` int(10) unsigned NOT NULL DEFAULT 0,
 `excluded` int(10) unsigned NOT NULL DEFAULT 0,
 `reason_spam` int(10) unsigned NOT NULL DEFAULT 0,
 `reason_test` int(10) unsigned NOT NULL DEFAULT 0,
 `reason_sales` int(10) unsigned NOT NULL DEFAULT 0,
 `sources` varchar(255) NOT NULL DEFAULT '',
 `fetched_at` datetime NOT NULL,
 `error_message` text NULL,
 PRIMARY KEY  (`id`),
 UNIQUE KEY `uniq_user_period` (`user_id`, `year_month`),
 KEY `idx_year_month` (`year_month`)
) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // dbDelta 後にテーブルが出来ているか確認
        $exists2 = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists2 !== $table ) {
            // フォールバック: 直接 CREATE TABLE
            self::log( '[INSTALL] dbDelta did not create table, trying direct CREATE TABLE' );
            $direct_sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                `total` INT UNSIGNED NOT NULL DEFAULT 0,
                `valid_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `excluded` INT UNSIGNED NOT NULL DEFAULT 0,
                `reason_spam` INT UNSIGNED NOT NULL DEFAULT 0,
                `reason_test` INT UNSIGNED NOT NULL DEFAULT 0,
                `reason_sales` INT UNSIGNED NOT NULL DEFAULT 0,
                `sources` VARCHAR(255) NOT NULL DEFAULT '',
                `fetched_at` DATETIME NOT NULL,
                `error_message` TEXT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_user_period` (`user_id`, `year_month`),
                KEY `idx_year_month` (`year_month`)
            ) {$charset}";
            $wpdb->query( $direct_sql );
            $exists3 = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists3 !== $table ) {
                self::log( '[INSTALL] Direct CREATE TABLE also failed: ' . ( $wpdb->last_error ?: 'unknown' ) );
                return;
            }
            self::log( '[INSTALL] Direct CREATE TABLE succeeded' );
        } else {
            self::log( '[INSTALL] dbDelta created table successfully' );
        }

        update_option( 'gcrev_inquiries_db_version', '1.0', false );
    }

    /* ================================================================
     * 設定アクセサ
     * ================================================================ */

    public static function get_endpoint( int $user_id ): string {
        $url = (string) get_user_meta( $user_id, self::META_ENDPOINT, true );
        return esc_url_raw( $url );
    }

    /**
     * 暗号化されたトークンを復号して返す
     */
    public static function get_token( int $user_id ): string {
        $stored = (string) get_user_meta( $user_id, self::META_TOKEN, true );
        if ( $stored === '' ) {
            return '';
        }
        if ( class_exists( 'Gcrev_Crypto' ) ) {
            $plain = Gcrev_Crypto::decrypt( $stored );
            return is_string( $plain ) ? $plain : '';
        }
        return $stored;
    }

    public static function is_enabled( int $user_id ): bool {
        return (bool) get_user_meta( $user_id, self::META_ENABLED, true )
            && self::get_endpoint( $user_id ) !== ''
            && (string) get_user_meta( $user_id, self::META_TOKEN, true ) !== '';
    }

    /**
     * トークンを暗号化して保存
     */
    public static function set_token( int $user_id, string $plain ): void {
        if ( $plain === '' ) {
            delete_user_meta( $user_id, self::META_TOKEN );
            return;
        }
        $stored = $plain;
        if ( class_exists( 'Gcrev_Crypto' ) ) {
            $enc = Gcrev_Crypto::encrypt( $plain );
            if ( is_string( $enc ) && $enc !== '' ) {
                $stored = $enc;
            }
        }
        update_user_meta( $user_id, self::META_TOKEN, $stored );
    }

    /* ================================================================
     * REST: 取得・更新
     * ================================================================ */

    public function register_routes(): void {

        // GET /mimamori/v1/inquiries/monthly?year=&month=
        register_rest_route( 'mimamori/v1', '/inquiries/monthly', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_monthly' ],
            'permission_callback' => static function () {
                return is_user_logged_in();
            },
            'args' => [
                'year'    => [ 'required' => false, 'sanitize_callback' => 'absint' ],
                'month'   => [ 'required' => false, 'sanitize_callback' => 'absint' ],
                'user_id' => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // POST /mimamori/v1/inquiries/fetch — 手動取得（管理者か本人）
        register_rest_route( 'mimamori/v1', '/inquiries/fetch', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_fetch_now' ],
            'permission_callback' => static function () {
                return is_user_logged_in();
            },
            'args' => [
                'year'    => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'month'   => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'user_id' => [ 'required' => false, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // POST /mimamori/v1/inquiries/settings — 設定保存
        register_rest_route( 'mimamori/v1', '/inquiries/settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_settings' ],
            'permission_callback' => static function () {
                return is_user_logged_in();
            },
        ] );
    }

    public function rest_get_monthly( \WP_REST_Request $request ): \WP_REST_Response {
        $year    = (int) $request->get_param( 'year' );
        $month   = (int) $request->get_param( 'month' );
        $user_id = self::resolve_target_user_id( $request );
        if ( $user_id === 0 ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'forbidden' ], 403 );
        }

        if ( $year === 0 || $month === 0 ) {
            $tz   = wp_timezone();
            $prev = ( new \DateTimeImmutable( 'first day of last month', $tz ) );
            $year  = (int) $prev->format( 'Y' );
            $month = (int) $prev->format( 'n' );
        }

        $row = self::get_record( $user_id, $year, $month );
        return new \WP_REST_Response( [
            'success' => true,
            'period'  => sprintf( '%04d-%02d', $year, $month ),
            'data'    => $row,
        ], 200 );
    }

    public function rest_fetch_now( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = self::resolve_target_user_id( $request );
        if ( $user_id === 0 ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'forbidden' ], 403 );
        }
        $year  = (int) $request->get_param( 'year' );
        $month = (int) $request->get_param( 'month' );

        $result = $this->fetch_and_store( $user_id, $year, $month );
        return new \WP_REST_Response( $result, $result['success'] ? 200 : 500 );
    }

    public function rest_save_settings( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = self::resolve_target_user_id( $request );
        if ( $user_id === 0 ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'forbidden' ], 403 );
        }
        $params   = $request->get_json_params() ?: $request->get_params();
        $endpoint = isset( $params['endpoint'] ) ? esc_url_raw( (string) $params['endpoint'] ) : '';
        $token    = isset( $params['token'] )    ? (string) $params['token']    : '';
        $enabled  = ! empty( $params['enabled'] );

        // endpoint バリデーション: http(s) のみ許可
        if ( $endpoint !== '' && ! preg_match( '#^https?://#i', $endpoint ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'invalid endpoint' ], 400 );
        }

        update_user_meta( $user_id, self::META_ENDPOINT, $endpoint );
        if ( $token !== '' ) {
            self::set_token( $user_id, $token );
        }
        update_user_meta( $user_id, self::META_ENABLED, $enabled ? 1 : 0 );

        return new \WP_REST_Response( [
            'success'  => true,
            'endpoint' => $endpoint,
            'enabled'  => $enabled,
        ], 200 );
    }

    /**
     * REST 共通: 操作対象ユーザーIDの解決と権限チェック
     * - 管理者: user_id 指定可
     * - 一般  : 自身のみ
     */
    private static function resolve_target_user_id( \WP_REST_Request $request ): int {
        $current  = get_current_user_id();
        $target   = (int) $request->get_param( 'user_id' );
        $is_admin = current_user_can( 'manage_options' );

        if ( $target > 0 ) {
            if ( $is_admin || $target === $current ) {
                return $target;
            }
            return 0;
        }
        return $current;
    }

    /* ================================================================
     * Cron: 月次フェッチ
     * ================================================================ */

    public function run_monthly_fetch(): void {
        $tz   = wp_timezone();
        $prev = ( new \DateTimeImmutable( 'first day of last month', $tz ) );
        $year  = (int) $prev->format( 'Y' );
        $month = (int) $prev->format( 'n' );

        self::log( "[CRON] monthly fetch START year={$year} month={$month}" );

        $user_ids = get_users( [ 'fields' => 'ID' ] );
        $total = 0;
        $ok    = 0;
        $skip  = 0;
        $fail  = 0;

        foreach ( $user_ids as $uid ) {
            $user_id = (int) $uid;
            $total++;
            if ( ! self::is_enabled( $user_id ) ) {
                $skip++;
                continue;
            }
            $result = $this->fetch_and_store( $user_id, $year, $month );
            if ( ! empty( $result['success'] ) ) {
                $ok++;
            } else {
                $fail++;
            }
            usleep( 200 * 1000 );
        }

        self::log( "[CRON] monthly fetch DONE total={$total} ok={$ok} skip={$skip} fail={$fail}" );
    }

    /* ================================================================
     * フェッチ本体
     * ================================================================ */

    /**
     * @return array{success:bool, message?:string, data?:array}
     */
    public function fetch_and_store( int $user_id, int $year, int $month ): array {
        if ( $year < 2000 || $year > 2100 || $month < 1 || $month > 12 ) {
            return [ 'success' => false, 'message' => 'invalid year/month' ];
        }

        $endpoint = self::get_endpoint( $user_id );
        $token    = self::get_token( $user_id );
        if ( $endpoint === '' || $token === '' ) {
            return [ 'success' => false, 'message' => 'endpoint or token not configured' ];
        }

        $url = add_query_arg(
            [ 'year' => $year, 'month' => $month ],
            self::normalize_endpoint( $endpoint )
        );

        $response = wp_remote_get( $url, [
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => [
                'Accept'           => 'application/json',
                'X-Mimamori-Token' => $token,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            self::log( "[FETCH] user={$user_id} period={$year}-{$month} ERROR " . $msg );
            self::store_error( $user_id, $year, $month, $msg );
            return [ 'success' => false, 'message' => $msg ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $msg = "HTTP {$code}: " . substr( $body, 0, 300 );
            self::log( "[FETCH] user={$user_id} period={$year}-{$month} HTTP_ERROR " . $msg );
            self::store_error( $user_id, $year, $month, $msg );
            return [ 'success' => false, 'message' => $msg ];
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) || ! isset( $data['period'] ) ) {
            $msg = 'invalid response payload';
            self::log( "[FETCH] user={$user_id} period={$year}-{$month} BAD_PAYLOAD " . substr( $body, 0, 300 ) );
            self::store_error( $user_id, $year, $month, $msg );
            return [ 'success' => false, 'message' => $msg ];
        }

        $reasons = is_array( $data['excluded_reasons'] ?? null ) ? $data['excluded_reasons'] : [];
        $sources = is_array( $data['sources'] ?? null )          ? $data['sources']          : [];

        $row = [
            'user_id'       => $user_id,
            'year_month'    => sprintf( '%04d-%02d', $year, $month ),
            'total'         => (int) ( $data['total']    ?? 0 ),
            'valid_count'   => (int) ( $data['valid']    ?? 0 ),
            'excluded'      => (int) ( $data['excluded'] ?? 0 ),
            'reason_spam'   => (int) ( $reasons['spam']  ?? 0 ),
            'reason_test'   => (int) ( $reasons['test']  ?? 0 ),
            'reason_sales'  => (int) ( $reasons['sales'] ?? 0 ),
            'sources'       => substr( implode( ',', array_map( 'strval', $sources ) ), 0, 255 ),
            'fetched_at'    => current_time( 'mysql' ),
            'error_message' => null,
        ];

        $stored = self::upsert( $row );

        if ( ! $stored ) {
            global $wpdb;
            $db_error = $wpdb->last_error ?: 'unknown DB error (table may not exist)';
            $table    = self::table_name();
            self::log( "[FETCH] user={$user_id} period={$year}-{$month} DB_ERROR table={$table} error={$db_error}" );
            return [
                'success' => false,
                'message' => 'DB保存に失敗: ' . $db_error . ' (table: ' . $table . ')',
                'data'    => $row,
            ];
        }

        // CV キャッシュ無効化（実質CVに後段で利用される可能性に備えて統一）
        if ( function_exists( 'gcrev_invalidate_user_cv_cache' ) ) {
            gcrev_invalidate_user_cv_cache( $user_id );
        }

        self::log( "[FETCH] user={$user_id} period={$year}-{$month} OK total={$row['total']} valid={$row['valid_count']}" );

        return [
            'success' => true,
            'data'    => $row,
        ];
    }

    private static function normalize_endpoint( string $endpoint ): string {
        // 末尾が /wp-json または /wp-json/mimamori/v1/inquiries 等を許容しつつ整形
        $endpoint = rtrim( $endpoint, '/' );
        if ( substr( $endpoint, -strlen( '/inquiries' ) ) === '/inquiries' ) {
            return $endpoint;
        }
        if ( substr( $endpoint, -strlen( '/wp-json' ) ) === '/wp-json' ) {
            return $endpoint . '/mimamori/v1/inquiries';
        }
        if ( strpos( $endpoint, '/wp-json/' ) !== false ) {
            return rtrim( $endpoint, '/' );
        }
        // ホストのみ与えられた → 推測補完
        return $endpoint . '/wp-json/mimamori/v1/inquiries';
    }

    /* ================================================================
     * DB 操作
     * ================================================================ */

    public static function upsert( array $row ): bool {
        global $wpdb;
        $table = self::table_name();

        // テーブル不在ならその場で作成（init で作られていないケースの保険）
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            ( new self() )->maybe_install_table();
            // フラグだけ残っていてテーブル無しの場合に備え、option を一旦削除して再実行
            $exists2 = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists2 !== $table ) {
                delete_option( 'gcrev_inquiries_db_version' );
                ( new self() )->maybe_install_table();
            }
        }

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT `id` FROM `{$table}` WHERE `user_id` = %d AND `year_month` = %s",
            (int) $row['user_id'],
            (string) $row['year_month']
        ) );

        if ( $existing ) {
            $result = $wpdb->update(
                $table,
                $row,
                [ 'id' => (int) $existing ],
                [ '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return $result !== false;
        }

        $result = $wpdb->insert(
            $table,
            $row,
            [ '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
        );
        return $result !== false;
    }

    private static function store_error( int $user_id, int $year, int $month, string $msg ): void {
        global $wpdb;
        $table = self::table_name();
        $ym    = sprintf( '%04d-%02d', $year, $month );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT `id` FROM `{$table}` WHERE `user_id` = %d AND `year_month` = %s",
            $user_id,
            $ym
        ) );
        if ( $existing ) {
            $wpdb->update(
                $table,
                [
                    'error_message' => substr( $msg, 0, 1000 ),
                    'fetched_at'    => current_time( 'mysql' ),
                ],
                [ 'id' => (int) $existing ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert(
                $table,
                [
                    'user_id'       => $user_id,
                    'year_month'    => $ym,
                    'error_message' => substr( $msg, 0, 1000 ),
                    'fetched_at'    => current_time( 'mysql' ),
                ],
                [ '%d', '%s', '%s', '%s' ]
            );
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get_record( int $user_id, int $year, int $month ): ?array {
        global $wpdb;
        $table = self::table_name();
        $ym    = sprintf( '%04d-%02d', $year, $month );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE `user_id` = %d AND `year_month` = %s",
            $user_id,
            $ym
        ), ARRAY_A );

        return is_array( $row ) ? $row : null;
    }

    /**
     * 直近 N ヶ月分の確定値を返す（古い → 新しい順）
     *
     * @return array<int, array<string,mixed>>
     */
    public static function get_recent( int $user_id, int $months = 12 ): array {
        global $wpdb;
        $table = self::table_name();
        $months = max( 1, min( 60, $months ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE `user_id` = %d ORDER BY `year_month` DESC LIMIT %d",
            $user_id,
            $months
        ), ARRAY_A );
        if ( ! is_array( $rows ) ) {
            return [];
        }
        return array_reverse( $rows );
    }

    /* ================================================================
     * ログ
     * ================================================================ */

    private static function log( string $message ): void {
        file_put_contents(
            self::DEBUG_LOG,
            date( 'Y-m-d H:i:s' ) . ' ' . $message . "\n",
            FILE_APPEND
        );
    }
}
