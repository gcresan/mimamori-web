<?php
// FILE: inc/cli/class-gcrev-cli.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Gcrev_CLI
 *
 * WP-CLI コマンド: テナント（ユーザー）単位のデータ管理。
 *
 * サブコマンド:
 *   wp gcrev list-data   --user_id=X           … データ件数一覧
 *   wp gcrev export      --user_id=X --output=  … JSON エクスポート
 *   wp gcrev purge       --user_id=X --confirm  … テナントデータ全削除
 *   wp gcrev migrate-tokens                     … 平文→暗号化トークン移行
 *   wp gcrev rate-limit-status                  … レートリミッターの現在値
 *
 * @package GCREV_INSIGHT
 * @since   2.1.0
 */
class Gcrev_CLI {

    // =========================================================
    // ユーザーメタキー定義
    // =========================================================

    /** クライアント設定系メタキー（report_* は _gcrev_ プレフィックスなし） */
    private const CLIENT_META_KEYS = [
        'report_site_url',
        'report_target',
        'report_issue',
        'report_goal_monthly',
        'report_focus_numbers',
        'report_current_state',
        'report_goal_main',
        'report_additional_notes',
        'report_output_mode',
        'report_company_name',
    ];

    /** GCREV 固有ユーザーメタキー */
    private const GCREV_META_KEYS = [
        '_gcrev_gbp_access_token',
        '_gcrev_gbp_refresh_token',
        '_gcrev_gbp_token_expires',
        '_gcrev_gbp_oauth_state',
        '_gcrev_gbp_location_id',
        '_gcrev_gbp_location_name',
        '_gcrev_gbp_location_address',
        '_gcrev_gbp_location_radius',
        '_gcrev_config',
        '_gcrev_phone_event_name',
        '_gcrev_cv_only_configured',
    ];

    // =========================================================
    // list-data: テナントデータ件数一覧
    // =========================================================

    /**
     * テナント（ユーザー）のデータ件数を一覧表示する。
     *
     * ## OPTIONS
     *
     * --user_id=<id>
     * : 対象ユーザーID
     *
     * ## EXAMPLES
     *
     *     wp gcrev list-data --user_id=5
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function list_data( array $args, array $assoc_args ): void {
        $user_id = $this->require_user_id( $assoc_args );

        $counts = $this->collect_counts( $user_id );

        WP_CLI::log( "=== GCREV Data Summary for user_id={$user_id} ===" );

        $table = [];
        foreach ( $counts as $label => $count ) {
            $table[] = [ 'Data Type' => $label, 'Count' => $count ];
        }

        WP_CLI\Utils\format_items( 'table', $table, [ 'Data Type', 'Count' ] );
    }

    // =========================================================
    // export: JSON エクスポート
    // =========================================================

    /**
     * テナントデータを JSON ファイルにエクスポートする。
     *
     * ## OPTIONS
     *
     * --user_id=<id>
     * : 対象ユーザーID
     *
     * [--output=<path>]
     * : 出力ファイルパス（省略時: gcrev-export-{user_id}-{date}.json）
     *
     * ## EXAMPLES
     *
     *     wp gcrev export --user_id=5
     *     wp gcrev export --user_id=5 --output=/tmp/backup-user5.json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function export( array $args, array $assoc_args ): void {
        $user_id = $this->require_user_id( $assoc_args );

        $output_path = $assoc_args['output']
            ?? sprintf( 'gcrev-export-%d-%s.json', $user_id, gmdate( 'Ymd-His' ) );

        WP_CLI::log( "Exporting data for user_id={$user_id} ..." );

        $data = [
            'meta'       => [
                'user_id'     => $user_id,
                'exported_at' => gmdate( 'Y-m-d H:i:s' ),
                'site_url'    => home_url(),
            ],
            'user_meta'  => $this->export_user_meta( $user_id ),
            'reports'    => $this->export_reports( $user_id ),
            'actual_cvs' => $this->export_actual_cvs( $user_id ),
            'cv_routes'  => $this->export_cv_routes( $user_id ),
            'transients' => $this->export_transients( $user_id ),
        ];

        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        if ( $json === false ) {
            WP_CLI::error( 'Failed to encode data as JSON.' );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = file_put_contents( $output_path, $json );

        if ( $written === false ) {
            WP_CLI::error( "Failed to write to {$output_path}" );
        }

        $counts = $this->collect_counts( $user_id );
        WP_CLI::success( sprintf(
            'Exported to %s (%s bytes) — Reports: %d, Actual CVs: %d, CV Routes: %d',
            $output_path,
            number_format( $written ),
            $counts['gcrev_report CPT'],
            $counts['gcrev_actual_cvs rows'],
            $counts['gcrev_cv_routes rows']
        ) );
    }

    // =========================================================
    // purge: テナントデータ全削除
    // =========================================================

    /**
     * テナントの GCREV データをすべて削除する（ユーザーアカウントは残す）。
     *
     * ## OPTIONS
     *
     * --user_id=<id>
     * : 対象ユーザーID
     *
     * --confirm
     * : 削除確認フラグ（必須）
     *
     * ## EXAMPLES
     *
     *     wp gcrev purge --user_id=5 --confirm
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function purge( array $args, array $assoc_args ): void {
        $user_id = $this->require_user_id( $assoc_args );

        if ( ! isset( $assoc_args['confirm'] ) ) {
            WP_CLI::error( '--confirm flag is required for purge. This will permanently delete all GCREV data for this user.' );
        }

        // 削除前のカウントを取得
        $before = $this->collect_counts( $user_id );

        WP_CLI::log( "=== Pre-purge data counts for user_id={$user_id} ===" );
        foreach ( $before as $label => $count ) {
            WP_CLI::log( "  {$label}: {$count}" );
        }

        WP_CLI::log( '' );
        WP_CLI::log( 'Purging ...' );

        // 1. レポート CPT 削除
        $deleted_reports = $this->purge_reports( $user_id );
        WP_CLI::log( "  Deleted {$deleted_reports} gcrev_report posts" );

        // 2. actual_cvs 削除
        $deleted_cvs = $this->purge_actual_cvs( $user_id );
        WP_CLI::log( "  Deleted {$deleted_cvs} gcrev_actual_cvs rows" );

        // 3. cv_routes 削除
        $deleted_routes = $this->purge_cv_routes( $user_id );
        WP_CLI::log( "  Deleted {$deleted_routes} gcrev_cv_routes rows" );

        // 4. Transient 削除
        $deleted_transients = $this->purge_transients( $user_id );
        WP_CLI::log( "  Deleted {$deleted_transients} transients" );

        // 5. ユーザーメタ削除
        $deleted_meta = $this->purge_user_meta( $user_id );
        WP_CLI::log( "  Deleted {$deleted_meta} user_meta entries" );

        WP_CLI::success( "Purge complete for user_id={$user_id}. User account is preserved." );
    }

    // =========================================================
    // migrate-tokens: 平文→暗号化移行
    // =========================================================

    /**
     * 全ユーザーの GBP トークンを平文から暗号化に移行する。
     *
     * ## EXAMPLES
     *
     *     wp gcrev migrate-tokens
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function migrate_tokens( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        if ( ! class_exists( 'Gcrev_Crypto' ) ) {
            WP_CLI::error( 'Gcrev_Crypto class not found. Ensure class-crypto.php is loaded.' );
        }

        if ( ! Gcrev_Crypto::is_available() ) {
            WP_CLI::error( 'Encryption is not available. Define GCREV_ENCRYPTION_KEY in wp-config.php.' );
        }

        WP_CLI::log( 'Migrating GBP tokens from plaintext to encrypted ...' );

        $result = Gcrev_Crypto::migrate_all_tokens();

        WP_CLI::log( sprintf(
            '  Migrated: %d, Skipped (already encrypted): %d, Errors: %d',
            $result['migrated'],
            $result['skipped'],
            $result['errors']
        ) );

        if ( $result['errors'] > 0 ) {
            WP_CLI::warning( 'Some tokens failed to migrate. Check error_log for details.' );
        } else {
            WP_CLI::success( 'Token migration complete.' );
        }
    }

    // =========================================================
    // rate-limit-status: レートリミッターの現在値
    // =========================================================

    /**
     * レートリミッターの現在のカウンター値を表示する。
     *
     * ## EXAMPLES
     *
     *     wp gcrev rate-limit-status
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     */
    public function rate_limit_status( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
        if ( ! class_exists( 'Gcrev_Rate_Limiter' ) ) {
            WP_CLI::error( 'Gcrev_Rate_Limiter class not found.' );
        }

        $apis = [ 'ga4', 'gsc' ];
        $table = [];

        foreach ( $apis as $api ) {
            $count = Gcrev_Rate_Limiter::get_current_count( $api );
            $table[] = [
                'API'           => strtoupper( $api ),
                'Current Count' => $count,
                'Limit'         => '400/min',
            ];
        }

        WP_CLI\Utils\format_items( 'table', $table, [ 'API', 'Current Count', 'Limit' ] );
    }

    // =========================================================
    // Private: バリデーション
    // =========================================================

    /**
     * --user_id を取得・検証する。
     *
     * @param  array $assoc_args Named arguments.
     * @return int   ユーザーID
     */
    private function require_user_id( array $assoc_args ): int {
        if ( empty( $assoc_args['user_id'] ) ) {
            WP_CLI::error( '--user_id is required.' );
        }
        $user_id = absint( $assoc_args['user_id'] );
        if ( $user_id <= 0 ) {
            WP_CLI::error( '--user_id must be a positive integer.' );
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            WP_CLI::error( "User with ID {$user_id} does not exist." );
        }
        return $user_id;
    }

    // =========================================================
    // Private: カウント集計
    // =========================================================

    /**
     * テナントの全データカウントを収集する。
     *
     * @param  int   $user_id
     * @return array<string, int>
     */
    private function collect_counts( int $user_id ): array {
        global $wpdb;

        // Reports (CPT)
        $report_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'gcrev_report'
             AND pm.meta_key = '_gcrev_user_id'
             AND pm.meta_value = %s",
            (string) $user_id
        ) );

        // actual_cvs
        $cvs_table = $wpdb->prefix . 'gcrev_actual_cvs';
        $cvs_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$cvs_table} WHERE user_id = %d",
            $user_id
        ) );

        // cv_routes
        $routes_table = $wpdb->prefix . 'gcrev_cv_routes';
        $routes_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$routes_table} WHERE user_id = %d",
            $user_id
        ) );

        // User meta (GCREV関連)
        $all_keys   = array_merge( self::CLIENT_META_KEYS, self::GCREV_META_KEYS );
        $meta_count = 0;
        foreach ( $all_keys as $key ) {
            $val = get_user_meta( $user_id, $key, true );
            if ( $val !== '' && $val !== false ) {
                $meta_count++;
            }
        }

        // Transients
        $transient_count = $this->count_transients( $user_id );

        return [
            'gcrev_report CPT'      => $report_count,
            'gcrev_actual_cvs rows' => $cvs_count,
            'gcrev_cv_routes rows'  => $routes_count,
            'User meta entries'     => $meta_count,
            'Transients (cached)'   => $transient_count,
        ];
    }

    // =========================================================
    // Private: Transient カウント/エクスポート/削除
    // =========================================================

    /**
     * @param  int $user_id
     * @return int
     */
    private function count_transients( int $user_id ): int {
        global $wpdb;
        $patterns = $this->transient_patterns( $user_id );
        $total    = 0;
        foreach ( $patterns as $pattern ) {
            $total += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ) );
        }
        return $total;
    }

    /**
     * Transient の LIKE パターンを生成。
     *
     * @param  int $user_id
     * @return string[]
     */
    private function transient_patterns( int $user_id ): array {
        global $wpdb;
        $uid = (string) $user_id;
        return [
            $wpdb->esc_like( "_transient_gcrev_dash_{$uid}_" ) . '%',
            $wpdb->esc_like( "_transient_timeout_gcrev_dash_{$uid}_" ) . '%',
            $wpdb->esc_like( "_transient_gcrev_report_{$uid}_" ) . '%',
            $wpdb->esc_like( "_transient_timeout_gcrev_report_{$uid}_" ) . '%',
            $wpdb->esc_like( "_transient_gcrev_effcv_{$uid}_" ) . '%',
            $wpdb->esc_like( "_transient_timeout_gcrev_effcv_{$uid}_" ) . '%',
        ];
    }

    // =========================================================
    // Private: Export helpers
    // =========================================================

    /**
     * @param  int   $user_id
     * @return array ユーザーメタの key-value 配列
     */
    private function export_user_meta( int $user_id ): array {
        $data = [];
        $all_keys = array_merge( self::CLIENT_META_KEYS, self::GCREV_META_KEYS );
        foreach ( $all_keys as $key ) {
            $val = get_user_meta( $user_id, $key, true );
            if ( $val !== '' && $val !== false ) {
                // トークンは暗号化されたまま出力（平文復元しない）
                $data[ $key ] = $val;
            }
        }
        return $data;
    }

    /**
     * @param  int   $user_id
     * @return array レポート CPT の配列
     */
    private function export_reports( int $user_id ): array {
        $posts = get_posts( [
            'post_type'      => 'gcrev_report',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_query'     => [  // phpcs:ignore WordPress.DB.SlowDBQuery
                [
                    'key'   => '_gcrev_user_id',
                    'value' => (string) $user_id,
                ],
            ],
        ] );

        $result = [];
        foreach ( $posts as $post ) {
            $meta = get_post_meta( $post->ID );
            $entry = [
                'ID'         => $post->ID,
                'title'      => $post->post_title,
                'status'     => $post->post_status,
                'created_at' => $post->post_date,
                'meta'       => [],
            ];
            foreach ( $meta as $k => $v ) {
                $entry['meta'][ $k ] = is_array( $v ) && count( $v ) === 1 ? $v[0] : $v;
            }
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * @param  int   $user_id
     * @return array
     */
    private function export_actual_cvs( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_actual_cvs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY cv_date DESC",
            $user_id
        ), ARRAY_A ) ?: [];
    }

    /**
     * @param  int   $user_id
     * @return array
     */
    private function export_cv_routes( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_cv_routes';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY sort_order ASC",
            $user_id
        ), ARRAY_A ) ?: [];
    }

    /**
     * @param  int   $user_id
     * @return array Transient名 => 値 の配列
     */
    private function export_transients( int $user_id ): array {
        global $wpdb;
        $patterns = $this->transient_patterns( $user_id );
        $data     = [];

        foreach ( $patterns as $pattern ) {
            // timeout ではなく _transient_ のみ
            if ( strpos( $pattern, '_transient_timeout_' ) !== false ) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ) );
            foreach ( $rows as $row ) {
                $data[ $row->option_name ] = maybe_unserialize( $row->option_value );
            }
        }

        return $data;
    }

    // =========================================================
    // Private: Purge helpers
    // =========================================================

    /**
     * @param  int $user_id
     * @return int 削除件数
     */
    private function purge_reports( int $user_id ): int {
        $posts = get_posts( [
            'post_type'      => 'gcrev_report',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'meta_query'     => [  // phpcs:ignore WordPress.DB.SlowDBQuery
                [
                    'key'   => '_gcrev_user_id',
                    'value' => (string) $user_id,
                ],
            ],
        ] );

        $count = 0;
        foreach ( $posts as $post_id ) {
            if ( wp_delete_post( $post_id, true ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param  int $user_id
     * @return int 削除行数
     */
    private function purge_actual_cvs( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_actual_cvs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE user_id = %d",
            $user_id
        ) );
    }

    /**
     * @param  int $user_id
     * @return int 削除行数
     */
    private function purge_cv_routes( int $user_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_cv_routes';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE user_id = %d",
            $user_id
        ) );
    }

    /**
     * @param  int $user_id
     * @return int 削除件数
     */
    private function purge_transients( int $user_id ): int {
        global $wpdb;
        $patterns = $this->transient_patterns( $user_id );
        $total    = 0;

        foreach ( $patterns as $pattern ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $deleted = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ) );
            $total += $deleted;
        }
        return $total;
    }

    /**
     * @param  int $user_id
     * @return int 削除件数
     */
    private function purge_user_meta( int $user_id ): int {
        $all_keys = array_merge( self::CLIENT_META_KEYS, self::GCREV_META_KEYS );
        $count    = 0;

        foreach ( $all_keys as $key ) {
            if ( delete_user_meta( $user_id, $key ) ) {
                $count++;
            }
        }
        return $count;
    }
}
