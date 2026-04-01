<?php
// FILE: inc/gcrev-api/utils/class-report-queue.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Report_Queue' ) ) { return; }

/**
 * Gcrev_Report_Queue
 *
 * 月次レポート自動生成のキュー管理。
 * Cron Logger と同じ静的メソッドパターンで実装。
 *
 * ステータス遷移:
 *   pending → processing → success / failed / skipped
 *   failed  → pending（リトライ時）
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Gcrev_Report_Queue {

    /** テーブル名サフィックス */
    private const TABLE = 'gcrev_report_queue';

    /** ストール検知しきい値（分） */
    private const STALE_THRESHOLD_MINUTES = 30;

    /** デフォルト最大リトライ回数 */
    private const DEFAULT_MAX_ATTEMPTS = 3;

    // =========================================================
    // テーブル名ヘルパー
    // =========================================================

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    // =========================================================
    // テーブル作成（dbDelta）
    // =========================================================

    public static function create_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = self::table_name();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            year_month VARCHAR(7) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
            client_info_snapshot LONGTEXT NULL,
            error_message TEXT NULL,
            locked_at DATETIME NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_month_job (user_id, year_month, job_id),
            KEY status (status),
            KEY job_id (job_id),
            KEY job_status (job_id, status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // =========================================================
    // キュー登録
    // =========================================================

    /**
     * 全対象ユーザーをキューに登録する。
     *
     * @param int      $job_id            Cron Logger の job_id
     * @param string   $year_month        対象年月 (YYYY-MM)
     * @param callable $build_client_info  function(int $user_id): array — スナップショット取得コールバック
     * @return int 登録件数
     */
    public static function enqueue_all_users( int $job_id, string $year_month, callable $build_client_info ): int {
        global $wpdb;

        $users = get_users( [ 'fields' => [ 'ID' ] ] );
        if ( empty( $users ) ) {
            return 0;
        }

        $now       = current_time( 'mysql', false );
        $enqueued  = 0;
        $repo      = class_exists( 'Gcrev_Report_Repository' ) ? new Gcrev_Report_Repository() : null;

        foreach ( $users as $u ) {
            $user_id = (int) $u->ID;

            // 管理者のみスキップ（レポート対象外）
            if ( user_can( $user_id, 'manage_options' ) ) {
                continue;
            }

            // 既にキュー登録済み（同一 job_id + user_id + year_month）
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM %i WHERE job_id = %d AND user_id = %d AND year_month = %s",
                self::table_name(),
                $job_id,
                $user_id,
                $year_month
            ) );
            if ( $exists ) {
                continue;
            }

            // 既にレポートが存在する場合はスキップ
            if ( $repo ) {
                $existing_reports = $repo->get_reports_by_month( $user_id, $year_month );
                if ( ! empty( $existing_reports ) ) {
                    self::enqueue_user_internal( $job_id, $user_id, $year_month, [], 'skipped', 'Report already exists', $now );
                    $enqueued++;
                    continue;
                }
            }

            // クライアント情報スナップショット取得
            $client_info = call_user_func( $build_client_info, $user_id );

            // site_url が空の場合はスキップ
            if ( empty( $client_info['site_url'] ?? '' ) ) {
                self::enqueue_user_internal( $job_id, $user_id, $year_month, $client_info, 'skipped', 'Missing client site_url', $now );
                $enqueued++;
                continue;
            }

            // 正常登録
            self::enqueue_user_internal( $job_id, $user_id, $year_month, $client_info, 'pending', null, $now );
            $enqueued++;
        }

        return $enqueued;
    }

    /**
     * 単一ユーザーをキューに登録する。
     */
    public static function enqueue_user( int $job_id, int $user_id, string $year_month, array $client_info, string $status = 'pending' ): bool {
        $now = current_time( 'mysql', false );
        return self::enqueue_user_internal( $job_id, $user_id, $year_month, $client_info, $status, null, $now );
    }

    /**
     * 内部: INSERT 実行
     */
    private static function enqueue_user_internal(
        int $job_id,
        int $user_id,
        string $year_month,
        array $client_info,
        string $status,
        ?string $error_message,
        string $now
    ): bool {
        global $wpdb;

        $result = $wpdb->insert(
            self::table_name(),
            [
                'job_id'               => $job_id,
                'user_id'              => $user_id,
                'year_month'           => $year_month,
                'status'               => $status,
                'attempts'             => 0,
                'max_attempts'         => self::DEFAULT_MAX_ATTEMPTS,
                'client_info_snapshot' => ! empty( $client_info ) ? wp_json_encode( $client_info, JSON_UNESCAPED_UNICODE ) : null,
                'error_message'        => $error_message ? mb_substr( $error_message, 0, 500 ) : null,
                'finished_at'          => $status === 'skipped' ? $now : null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $result !== false;
    }

    // =========================================================
    // Claim（取得＆ロック）
    // =========================================================

    /**
     * 次のN件の pending アイテムを取得し、processing に更新する。
     *
     * @param int $job_id ジョブID
     * @param int $limit  取得件数
     * @return array キューアイテムの配列
     */
    public static function claim_next( int $job_id, int $limit = 3 ): array {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql', false );

        // pending アイテムを取得（attempts < max_attempts）
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i
             WHERE job_id = %d AND status = 'pending' AND attempts < max_attempts
             ORDER BY id ASC
             LIMIT %d",
            $table,
            $job_id,
            $limit
        ) );

        if ( empty( $items ) ) {
            return [];
        }

        // processing に更新
        $ids = array_map( fn( $item ) => (int) $item->id, $items );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare(
            "UPDATE %i
             SET status = 'processing', locked_at = %s, started_at = COALESCE(started_at, %s), attempts = attempts + 1, updated_at = %s
             WHERE id IN ({$placeholders})",
            array_merge( [ $table, $now, $now, $now ], $ids )
        ) );

        // 更新後のデータを再取得
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $updated = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE id IN ({$placeholders})",
            array_merge( [ $table ], $ids )
        ) );

        return $updated ?: [];
    }

    // =========================================================
    // ステータス更新
    // =========================================================

    public static function mark_success( int $queue_id ): void {
        global $wpdb;
        $now = current_time( 'mysql', false );

        $wpdb->update(
            self::table_name(),
            [
                'status'        => 'success',
                'error_message' => null,
                'finished_at'   => $now,
                'updated_at'    => $now,
            ],
            [ 'id' => $queue_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    public static function mark_failed( int $queue_id, string $error ): void {
        global $wpdb;
        $now = current_time( 'mysql', false );

        $wpdb->update(
            self::table_name(),
            [
                'status'        => 'failed',
                'error_message' => mb_substr( $error, 0, 500 ),
                'finished_at'   => $now,
                'updated_at'    => $now,
            ],
            [ 'id' => $queue_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    public static function mark_skipped( int $queue_id, string $reason ): void {
        global $wpdb;
        $now = current_time( 'mysql', false );

        $wpdb->update(
            self::table_name(),
            [
                'status'        => 'skipped',
                'error_message' => mb_substr( $reason, 0, 500 ),
                'finished_at'   => $now,
                'updated_at'    => $now,
            ],
            [ 'id' => $queue_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    // =========================================================
    // リトライ
    // =========================================================

    /**
     * 失敗アイテムを pending に戻す。
     *
     * @return bool max_attempts に達している場合は false
     */
    public static function retry( int $queue_id ): bool {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql', false );

        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM %i WHERE id = %d",
            $table,
            $queue_id
        ) );

        if ( ! $item || $item->status !== 'failed' ) {
            return false;
        }

        if ( (int) $item->attempts >= (int) $item->max_attempts ) {
            return false;
        }

        $wpdb->update(
            $table,
            [
                'status'        => 'pending',
                'error_message' => null,
                'locked_at'     => null,
                'finished_at'   => null,
                'updated_at'    => $now,
            ],
            [ 'id' => $queue_id ],
            [ '%s', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return true;
    }

    /**
     * ジョブ内の全 failed アイテムを pending に戻す。
     *
     * @return int リトライ対象件数
     */
    public static function retry_all_failed( int $job_id ): int {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql', false );

        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE %i
             SET status = 'pending', error_message = NULL, locked_at = NULL, finished_at = NULL, updated_at = %s
             WHERE job_id = %d AND status = 'failed' AND attempts < max_attempts",
            $table,
            $now,
            $job_id
        ) );
    }

    // =========================================================
    // ストール復旧
    // =========================================================

    /**
     * processing のまま一定時間経過したアイテムを pending に戻す。
     *
     * @return int 復旧件数
     */
    public static function recover_stale_items( int $job_id ): int {
        global $wpdb;
        $table     = self::table_name();
        $now       = current_time( 'mysql', false );
        $threshold = gmdate( 'Y-m-d H:i:s', time() - self::STALE_THRESHOLD_MINUTES * 60 );

        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE %i
             SET status = 'pending', locked_at = NULL, updated_at = %s
             WHERE job_id = %d AND status = 'processing' AND locked_at < %s",
            $table,
            $now,
            $job_id,
            $threshold
        ) );
    }

    // =========================================================
    // クエリ
    // =========================================================

    /**
     * ジョブ内のアイテム一覧を取得。
     */
    public static function get_by_job( int $job_id, ?string $status = null, int $limit = 200 ): array {
        global $wpdb;
        $table = self::table_name();

        if ( $status ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM %i WHERE job_id = %d AND status = %s ORDER BY id ASC LIMIT %d",
                $table,
                $job_id,
                $status,
                $limit
            ) ) ?: [];
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE job_id = %d ORDER BY id ASC LIMIT %d",
            $table,
            $job_id,
            $limit
        ) ) ?: [];
    }

    /**
     * ジョブ内のステータス別件数を取得。
     *
     * @return array ['pending' => N, 'processing' => N, 'success' => N, 'failed' => N, 'skipped' => N, 'total' => N]
     */
    public static function get_counts_by_status( int $job_id ): array {
        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) as cnt FROM %i WHERE job_id = %d GROUP BY status",
            $table,
            $job_id
        ) ) ?: [];

        $counts = [
            'pending'    => 0,
            'processing' => 0,
            'success'    => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'total'      => 0,
        ];

        foreach ( $rows as $row ) {
            $counts[ $row->status ] = (int) $row->cnt;
        }

        $counts['total'] = array_sum( $counts );

        return $counts;
    }

    /**
     * pending または processing のアイテムが残っているか。
     */
    public static function has_pending_or_processing( int $job_id ): bool {
        global $wpdb;
        $table = self::table_name();

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE job_id = %d AND status IN ('pending', 'processing')",
            $table,
            $job_id
        ) );

        return $count > 0;
    }

    /**
     * 指定年月の最新ジョブIDを取得。
     */
    public static function get_latest_job_id( string $year_month ): int {
        global $wpdb;
        $table = self::table_name();

        $job_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(job_id) FROM %i WHERE year_month = %s",
            $table,
            $year_month
        ) );

        return (int) $job_id;
    }

    /**
     * 全ジョブの概要一覧を取得（管理画面用）。
     */
    public static function get_job_summary_list( int $limit = 20 ): array {
        global $wpdb;
        $table = self::table_name();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                job_id,
                year_month,
                MIN(created_at) as created_at,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending'    THEN 1 ELSE 0 END) as cnt_pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as cnt_processing,
                SUM(CASE WHEN status = 'success'    THEN 1 ELSE 0 END) as cnt_success,
                SUM(CASE WHEN status = 'failed'     THEN 1 ELSE 0 END) as cnt_failed,
                SUM(CASE WHEN status = 'skipped'    THEN 1 ELSE 0 END) as cnt_skipped
             FROM %i
             WHERE job_id > 0
             GROUP BY job_id, year_month
             ORDER BY job_id DESC
             LIMIT %d",
            $table,
            $limit
        ) ) ?: [];
    }

    /**
     * 単一アイテム取得。
     */
    public static function get_item( int $queue_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM %i WHERE id = %d",
            self::table_name(),
            $queue_id
        ) );
    }

    // =========================================================
    // クリーンアップ
    // =========================================================

    /**
     * 指定日数より古いキューデータを削除する。
     *
     * @return int 削除件数
     */
    public static function cleanup_old( int $days = 90 ): int {
        global $wpdb;
        $table  = self::table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM %i WHERE created_at < %s",
            $table,
            $cutoff
        ) );
    }
}
