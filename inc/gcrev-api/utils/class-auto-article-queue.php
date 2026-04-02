<?php
// FILE: inc/gcrev-api/utils/class-auto-article-queue.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Auto_Article_Queue' ) ) { return; }

/**
 * Gcrev_Auto_Article_Queue
 *
 * 自動記事生成のキュー管理。
 * Gcrev_Report_Queue と同じ静的メソッドパターンで実装。
 *
 * ステータス遷移:
 *   pending → processing → draft_created / published / skipped / angle_shifted / failed
 *   failed  → pending（リトライ時）
 *
 * @package Mimamori_Web
 */
class Gcrev_Auto_Article_Queue {

    /** テーブル名サフィックス */
    private const TABLE = 'gcrev_auto_article_queue';

    /** ストール検知しきい値（分） */
    private const STALE_THRESHOLD_MINUTES = 60;

    /** デフォルト最大リトライ回数 */
    private const DEFAULT_MAX_ATTEMPTS = 2;

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
            keyword VARCHAR(255) NOT NULL,
            keyword_group VARCHAR(50) NOT NULL DEFAULT '',
            priority_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            final_keyword VARCHAR(255) NOT NULL DEFAULT '',
            angle_json LONGTEXT NULL,
            similarity_json LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 2,
            article_id BIGINT(20) UNSIGNED NULL,
            wp_draft_id BIGINT(20) UNSIGNED NULL,
            quality_score DECIMAL(5,2) NULL,
            quality_feedback TEXT NULL,
            error_message TEXT NULL,
            locked_at DATETIME NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_keyword (user_id, keyword(191)),
            KEY status (status),
            KEY job_id (job_id),
            KEY job_status (job_id, status),
            KEY user_status (user_id, status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // =========================================================
    // キュー登録
    // =========================================================

    /**
     * キーワードをキューに登録する。
     */
    public static function enqueue(
        int    $job_id,
        int    $user_id,
        string $keyword,
        string $keyword_group,
        float  $priority_score,
        string $status = 'pending'
    ): bool {
        global $wpdb;
        $now = current_time( 'mysql', false );

        $result = $wpdb->insert(
            self::table_name(),
            [
                'job_id'         => $job_id,
                'user_id'        => $user_id,
                'keyword'        => mb_substr( $keyword, 0, 255 ),
                'keyword_group'  => sanitize_text_field( $keyword_group ),
                'priority_score' => round( $priority_score, 2 ),
                'final_keyword'  => mb_substr( $keyword, 0, 255 ),
                'status'         => $status,
                'attempts'       => 0,
                'max_attempts'   => self::DEFAULT_MAX_ATTEMPTS,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );

        return $result !== false;
    }

    // =========================================================
    // Claim（取得＆ロック）
    // =========================================================

    /**
     * 次のN件の pending アイテムを取得し、processing に更新する。
     */
    public static function claim_next( int $job_id, int $limit = 1 ): array {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql', false );

        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i
             WHERE job_id = %d AND status = 'pending' AND attempts < max_attempts
             ORDER BY priority_score DESC, id ASC
             LIMIT %d",
            $table,
            $job_id,
            $limit
        ) );

        if ( empty( $items ) ) {
            return [];
        }

        $ids = array_map( fn( $item ) => (int) $item->id, $items );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare(
            "UPDATE %i
             SET status = 'processing', locked_at = %s, started_at = COALESCE(started_at, %s), attempts = attempts + 1, updated_at = %s
             WHERE id IN ({$placeholders})",
            array_merge( [ $table, $now, $now, $now ], $ids )
        ) );

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

    public static function mark_success( int $queue_id, int $article_id, int $wp_draft_id, float $quality_score, string $quality_feedback = '', string $status = 'draft_created' ): void {
        global $wpdb;
        $now = current_time( 'mysql', false );

        $wpdb->update(
            self::table_name(),
            [
                'status'           => $status,
                'article_id'       => $article_id,
                'wp_draft_id'      => $wp_draft_id,
                'quality_score'    => round( $quality_score, 2 ),
                'quality_feedback' => mb_substr( $quality_feedback, 0, 2000 ),
                'error_message'    => null,
                'finished_at'      => $now,
                'updated_at'       => $now,
            ],
            [ 'id' => $queue_id ],
            [ '%s', '%d', '%d', '%f', '%s', '%s', '%s', '%s' ],
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
    // メタ更新
    // =========================================================

    public static function update_angle( int $queue_id, string $angle_json, string $final_keyword = '' ): void {
        global $wpdb;
        $now  = current_time( 'mysql', false );
        $data = [
            'angle_json' => $angle_json,
            'updated_at' => $now,
        ];
        $fmt = [ '%s', '%s' ];
        if ( $final_keyword !== '' ) {
            $data['final_keyword'] = mb_substr( $final_keyword, 0, 255 );
            $fmt[] = '%s';
        }

        $wpdb->update( self::table_name(), $data, [ 'id' => $queue_id ], $fmt, [ '%d' ] );
    }

    public static function update_similarity( int $queue_id, string $similarity_json ): void {
        global $wpdb;
        $now = current_time( 'mysql', false );

        $wpdb->update(
            self::table_name(),
            [ 'similarity_json' => $similarity_json, 'updated_at' => $now ],
            [ 'id' => $queue_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    // =========================================================
    // リトライ
    // =========================================================

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

    public static function get_by_job( int $job_id, ?string $status = null, int $limit = 200 ): array {
        global $wpdb;
        $table = self::table_name();

        if ( $status ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM %i WHERE job_id = %d AND status = %s ORDER BY priority_score DESC, id ASC LIMIT %d",
                $table,
                $job_id,
                $status,
                $limit
            ) ) ?: [];
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE job_id = %d ORDER BY priority_score DESC, id ASC LIMIT %d",
            $table,
            $job_id,
            $limit
        ) ) ?: [];
    }

    public static function get_counts_by_status( int $job_id ): array {
        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) as cnt FROM %i WHERE job_id = %d GROUP BY status",
            $table,
            $job_id
        ) ) ?: [];

        $counts = [
            'pending'        => 0,
            'processing'     => 0,
            'draft_created'  => 0,
            'published'      => 0,
            'skipped'        => 0,
            'angle_shifted'  => 0,
            'failed'         => 0,
            'total'          => 0,
        ];

        foreach ( $rows as $row ) {
            if ( isset( $counts[ $row->status ] ) ) {
                $counts[ $row->status ] = (int) $row->cnt;
            }
        }

        $counts['total'] = array_sum( $counts );

        return $counts;
    }

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
     * ユーザーの当日生成数を取得。
     */
    public static function get_today_count( int $user_id ): int {
        global $wpdb;
        $table = self::table_name();
        $today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE user_id = %d AND DATE(created_at) = %s AND status NOT IN ('failed')",
            $table,
            $user_id,
            $today
        ) );
    }

    /**
     * 同じキーワードが最近（30日以内）キューに入っているか。
     */
    public static function keyword_already_queued( int $user_id, string $keyword, int $days = 30 ): bool {
        global $wpdb;
        $table  = self::table_name();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE user_id = %d AND keyword = %s AND created_at >= %s AND status NOT IN ('failed')",
            $table,
            $user_id,
            $keyword,
            $cutoff
        ) );

        return $count > 0;
    }

    /**
     * ユーザーの生成履歴を取得。
     */
    public static function get_user_history( int $user_id, int $limit = 50 ): array {
        global $wpdb;
        $table = self::table_name();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $table,
            $user_id,
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
