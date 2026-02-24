<?php
// FILE: inc/gcrev-api/utils/class-cron-logger.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Cron_Logger' ) ) { return; }

/**
 * Gcrev_Cron_Logger
 *
 * Cron ジョブの実行状況を DB に記録する。
 * 2テーブル構成:
 *   - gcrev_cron_logs         … ジョブ単位の開始/終了/ステータス
 *   - gcrev_cron_log_details  … ユーザー単位の処理結果
 *
 * @package GCREV_INSIGHT
 * @since   3.0.0
 */
class Gcrev_Cron_Logger {

    /** テーブル名サフィックス */
    private const TABLE_LOGS    = 'gcrev_cron_logs';
    private const TABLE_DETAILS = 'gcrev_cron_log_details';

    // =========================================================
    // テーブル名ヘルパー
    // =========================================================

    public static function logs_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LOGS;
    }

    public static function details_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_DETAILS;
    }

    // =========================================================
    // テーブル作成（dbDelta）
    // =========================================================

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $logs_table    = self::logs_table();
        $details_table = self::details_table();

        $sql_logs = "CREATE TABLE {$logs_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_name VARCHAR(80) NOT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            users_total INT NOT NULL DEFAULT 0,
            users_success INT NOT NULL DEFAULT 0,
            users_skipped INT NOT NULL DEFAULT 0,
            users_error INT NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            meta_json TEXT NULL,
            PRIMARY KEY  (id),
            KEY job_name_status (job_name, status),
            KEY started_at (started_at)
        ) {$charset};";

        $sql_details = "CREATE TABLE {$details_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            log_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL,
            detail TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY log_id (log_id),
            KEY user_id (user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_logs );
        dbDelta( $sql_details );
    }

    // =========================================================
    // ジョブ開始
    // =========================================================

    /**
     * ジョブの実行開始を記録する。
     *
     * @param  string $job_name ジョブ名（例: 'prefetch', 'report_generate', 'report_finalize'）
     * @param  array  $meta     任意のメタデータ（JSON化して保存）
     * @return int    挿入された log_id
     */
    public static function start( string $job_name, array $meta = [] ): int {
        global $wpdb;

        $now = current_time( 'mysql', false );

        $wpdb->insert(
            self::logs_table(),
            [
                'job_name'   => sanitize_key( $job_name ),
                'started_at' => $now,
                'status'     => 'running',
                'meta_json'  => ! empty( $meta ) ? wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) : null,
            ],
            [ '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    // =========================================================
    // ジョブ終了
    // =========================================================

    /**
     * ジョブの実行終了を記録する。
     *
     * @param int         $log_id       start() で返された ID
     * @param string      $status       'success' | 'partial' | 'error' | 'locked'
     * @param string|null $error_message エラーメッセージ（任意）
     */
    public static function finish( int $log_id, string $status = 'success', ?string $error_message = null ): void {
        global $wpdb;

        if ( $log_id <= 0 ) {
            return;
        }

        $now = current_time( 'mysql', false );

        // ユーザーカウントを集計
        $details_table = self::details_table();
        $counts = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN status = 'skip' THEN 1 ELSE 0 END) AS skip_count,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS error_count
             FROM {$details_table} WHERE log_id = %d",
            $log_id
        ) );

        $wpdb->update(
            self::logs_table(),
            [
                'finished_at'    => $now,
                'status'         => $status,
                'users_total'    => (int) ( $counts->total ?? 0 ),
                'users_success'  => (int) ( $counts->success_count ?? 0 ),
                'users_skipped'  => (int) ( $counts->skip_count ?? 0 ),
                'users_error'    => (int) ( $counts->error_count ?? 0 ),
                'error_message'  => $error_message,
            ],
            [ 'id' => $log_id ],
            [ '%s', '%s', '%d', '%d', '%d', '%d', '%s' ],
            [ '%d' ]
        );

        // エラー通知チェック
        if ( class_exists( 'Gcrev_Error_Notifier' ) ) {
            Gcrev_Error_Notifier::maybe_notify( $log_id );
        }
    }

    // =========================================================
    // ユーザー単位の記録
    // =========================================================

    /**
     * 個別ユーザーの処理結果を記録する。
     *
     * @param int         $log_id  ジョブの log_id
     * @param int         $user_id 処理対象ユーザーID
     * @param string      $status  'success' | 'skip' | 'error'
     * @param string|null $detail  詳細メッセージ
     */
    public static function log_user( int $log_id, int $user_id, string $status, ?string $detail = null ): void {
        global $wpdb;

        if ( $log_id <= 0 ) {
            return;
        }

        $now = current_time( 'mysql', false );

        $wpdb->insert(
            self::details_table(),
            [
                'log_id'     => $log_id,
                'user_id'    => $user_id,
                'status'     => $status,
                'detail'     => $detail ? mb_substr( $detail, 0, 500 ) : null,
                'created_at' => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%s' ]
        );
    }

    // =========================================================
    // 読み取り系
    // =========================================================

    /**
     * 直近のログ一覧を取得する。
     *
     * @param  int         $limit    最大件数
     * @param  string|null $job_name ジョブ名でフィルタ（任意）
     * @return array
     */
    public static function get_recent( int $limit = 50, ?string $job_name = null ): array {
        global $wpdb;
        $table = self::logs_table();

        if ( $job_name !== null ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE job_name = %s ORDER BY id DESC LIMIT %d",
                $job_name,
                $limit
            ) ) ?: [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
            $limit
        ) ) ?: [];
    }

    /**
     * 単一ログを取得する。
     *
     * @param  int          $log_id
     * @return object|null
     */
    public static function get_log( int $log_id ): ?object {
        global $wpdb;
        $table = self::logs_table();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $log_id
        ) );

        return $row ?: null;
    }

    /**
     * ジョブのユーザー別詳細を取得する。
     *
     * @param  int   $log_id
     * @return array
     */
    public static function get_user_logs( int $log_id ): array {
        global $wpdb;
        $table = self::details_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE log_id = %d ORDER BY id ASC",
            $log_id
        ) ) ?: [];
    }

    /**
     * 特定ユーザーの最新ログを取得する（テナント一覧用）。
     *
     * @param  int         $user_id
     * @param  string|null $job_name_like  ジョブ名の LIKE パターン（例: 'prefetch%'）
     * @return object|null
     */
    public static function get_latest_for_user( int $user_id, ?string $job_name_like = null ): ?object {
        global $wpdb;
        $logs_table    = self::logs_table();
        $details_table = self::details_table();

        if ( $job_name_like !== null ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT d.created_at, d.status, d.detail, l.job_name
                 FROM {$details_table} d
                 INNER JOIN {$logs_table} l ON d.log_id = l.id
                 WHERE d.user_id = %d AND l.job_name LIKE %s AND d.status = 'success'
                 ORDER BY d.created_at DESC LIMIT 1",
                $user_id,
                $job_name_like
            ) );
        } else {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT d.created_at, d.status, d.detail
                 FROM {$details_table} d
                 WHERE d.user_id = %d AND d.status = 'success'
                 ORDER BY d.created_at DESC LIMIT 1",
                $user_id
            ) );
        }

        return $row ?: null;
    }

    /**
     * 各ジョブの最新実行結果を取得する（ステータスカード用）。
     *
     * @return array job_name => log object
     */
    public static function get_latest_per_job(): array {
        global $wpdb;
        $table = self::logs_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            "SELECT l1.* FROM {$table} l1
             INNER JOIN (
                SELECT job_name, MAX(id) AS max_id FROM {$table} GROUP BY job_name
             ) l2 ON l1.id = l2.max_id"
        ) ?: [];

        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row->job_name ] = $row;
        }
        return $result;
    }

    // =========================================================
    // クリーンアップ
    // =========================================================

    /**
     * N日より古いログを削除する。
     *
     * @param  int $days 保持日数（デフォルト: 90）
     * @return int 削除件数
     */
    public static function cleanup_old( int $days = 90 ): int {
        global $wpdb;

        $logs_table    = self::logs_table();
        $details_table = self::details_table();
        $cutoff        = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        // まず詳細テーブルの関連行を削除
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( $wpdb->prepare(
            "DELETE d FROM {$details_table} d
             INNER JOIN {$logs_table} l ON d.log_id = l.id
             WHERE l.started_at < %s",
            $cutoff
        ) );

        // 次にログテーブルを削除
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$logs_table} WHERE started_at < %s",
            $cutoff
        ) );

        return $deleted;
    }
}
