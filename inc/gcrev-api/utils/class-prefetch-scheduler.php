<?php
// FILE: inc/gcrev-api/utils/class-prefetch-scheduler.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Prefetch_Scheduler' ) ) { return; }

/**
 * Gcrev_Prefetch_Scheduler
 *
 * 100社以上のテナントを複数の時間スロットに分散し、
 * API クォータの集中を回避する。
 *
 * デフォルト: 4スロット × 30分間隔
 *   Slot 0: 03:10 → ~25社
 *   Slot 1: 03:40 → ~25社
 *   Slot 2: 04:10 → ~25社
 *   Slot 3: 04:40 → ~25社
 *
 * スロット割り当て: user_id % スロット数
 *
 * @package GCREV_INSIGHT
 * @since   3.0.0
 */
class Gcrev_Prefetch_Scheduler {

    /** スロット数のオプション名 */
    private const OPTION_SLOTS = 'gcrev_prefetch_slots';

    /** デフォルトスロット数 */
    private const DEFAULT_SLOTS = 4;

    /** 開始時刻（時） */
    private const BASE_HOUR = 3;

    /** 開始時刻（分） */
    private const BASE_MINUTE = 10;

    /** スロット間隔（分） */
    private const SLOT_INTERVAL_MINUTES = 30;

    // =========================================================
    // スロット情報
    // =========================================================

    /**
     * スロット数を取得する。
     *
     * @return int
     */
    public static function get_slot_count(): int {
        $count = (int) get_option( self::OPTION_SLOTS, self::DEFAULT_SLOTS );
        return max( 1, min( $count, 12 ) ); // 1〜12 の範囲
    }

    /**
     * ユーザーが属するスロット番号を返す。
     *
     * @param  int $user_id
     * @return int 0-indexed スロット番号
     */
    public static function get_slot_for_user( int $user_id ): int {
        return $user_id % self::get_slot_count();
    }

    /**
     * スロットの開始時刻文字列を返す（wp-cron スケジュール用）。
     *
     * @param  int    $slot スロット番号（0-indexed）
     * @return string "tomorrow HH:MM:00" 形式
     */
    public static function get_slot_time( int $slot ): string {
        $total_minutes = ( self::BASE_HOUR * 60 + self::BASE_MINUTE ) + ( $slot * self::SLOT_INTERVAL_MINUTES );
        $hour   = (int) floor( $total_minutes / 60 );
        $minute = $total_minutes % 60;
        return sprintf( 'tomorrow %02d:%02d:00', $hour, $minute );
    }

    // =========================================================
    // ユーザー取得（スロット別）
    // =========================================================

    /**
     * 指定スロットに属するユーザーID一覧を取得する。
     *
     * @param  int $slot   スロット番号
     * @param  int $limit  最大件数
     * @param  int $offset オフセット
     * @return int[] ユーザーIDの配列
     */
    public static function get_users_for_slot( int $slot, int $limit = 100, int $offset = 0 ): array {
        global $wpdb;

        $slot_count = self::get_slot_count();

        // MOD 演算でスロット割り当て
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->users}
             WHERE (ID %% %d) = %d
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $slot_count,
            $slot,
            $limit,
            $offset
        ) );

        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * 指定スロットにまだ未処理のユーザーが残っているか。
     *
     * @param  int $slot   スロット番号
     * @param  int $offset チェック開始オフセット
     * @return bool
     */
    public static function has_more_users( int $slot, int $offset ): bool {
        return ! empty( self::get_users_for_slot( $slot, 1, $offset ) );
    }

    // =========================================================
    // Cron スケジュール管理
    // =========================================================

    /**
     * 全スロットの daily cron イベントを登録する。
     */
    public static function schedule_all_slots(): void {
        $slot_count = self::get_slot_count();
        $tz = wp_timezone();

        for ( $i = 0; $i < $slot_count; $i++ ) {
            $hook = "gcrev_prefetch_daily_event_slot_{$i}";
            if ( wp_next_scheduled( $hook ) ) {
                continue;
            }
            $when = self::get_slot_time( $i );
            $dt   = new \DateTimeImmutable( $when, $tz );
            wp_schedule_event( $dt->getTimestamp(), 'daily', $hook );
            error_log( "[GCREV] Scheduled {$hook} at " . $dt->format( 'Y-m-d H:i:s T' ) );
        }
    }

    /**
     * 全スロットの cron イベントを解除する。
     */
    public static function unschedule_all_slots(): void {
        $slot_count = self::get_slot_count();

        for ( $i = 0; $i < $slot_count; $i++ ) {
            $hook = "gcrev_prefetch_daily_event_slot_{$i}";
            $ts   = wp_next_scheduled( $hook );
            while ( $ts ) {
                wp_unschedule_event( $ts, $hook );
                $ts = wp_next_scheduled( $hook );
            }

            // チャンクイベントも掃除
            $chunk_hook = "gcrev_prefetch_chunk_slot_{$i}_event";
            $ts = wp_next_scheduled( $chunk_hook );
            while ( $ts ) {
                wp_unschedule_event( $ts, $chunk_hook );
                $ts = wp_next_scheduled( $chunk_hook );
            }
        }

        error_log( '[GCREV] Unscheduled all prefetch slot events' );
    }
}
