<?php
/**
 * みまもりウェブ Bootstrap
 * - Cron / Hook 登録を集約
 * - 既存イベント名・実行時刻は変更しない
 */

if ( ! class_exists('Gcrev_Bootstrap') ) {

class Gcrev_Bootstrap {

    public static function register(): void {
        // Cron callbacks
        add_action('gcrev_prefetch_daily_event', [__CLASS__, 'on_prefetch_daily_event']);
        add_action('gcrev_prefetch_chunk_event', [__CLASS__, 'on_prefetch_chunk_event'], 10, 2);

        add_action('gcrev_monthly_report_generate_event', [__CLASS__, 'on_monthly_report_generate_event']);
        add_action('gcrev_monthly_report_generate_chunk_event', [__CLASS__, 'on_monthly_report_generate_chunk_event'], 10, 2);
        add_action('gcrev_monthly_report_finalize_event', [__CLASS__, 'on_monthly_report_finalize_event']);

        // Cron Log クリーンアップ
        add_action('gcrev_cron_log_cleanup_event', [__CLASS__, 'on_cron_log_cleanup']);

        // 順位トラッキング（週次）
        add_action('gcrev_rank_fetch_daily_event', [__CLASS__, 'on_rank_fetch_daily_event']); // 後方互換
        add_action('gcrev_rank_fetch_weekly_event', [__CLASS__, 'on_rank_fetch_weekly_event']);
        add_action('gcrev_rank_fetch_chunk_event', [__CLASS__, 'on_rank_fetch_chunk_event'], 10, 2);

        // MEO 週次フェッチ
        add_action('gcrev_meo_fetch_weekly_event', [__CLASS__, 'on_meo_fetch_weekly_event']);
        add_action('gcrev_meo_fetch_chunk_event', [__CLASS__, 'on_meo_fetch_chunk_event'], 10, 2);

        // キーワード指標（月次 — 検索ボリューム + SEO難易度）
        add_action('gcrev_keyword_metrics_monthly_event', [__CLASS__, 'on_keyword_metrics_monthly']);
        add_action('gcrev_keyword_metrics_chunk_event', [__CLASS__, 'on_keyword_metrics_chunk'], 10, 2);

        // スロット別プリフェッチ
        if ( class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            $slot_count = Gcrev_Prefetch_Scheduler::get_slot_count();
            for ( $i = 0; $i < $slot_count; $i++ ) {
                add_action( "gcrev_prefetch_daily_event_slot_{$i}", [__CLASS__, 'on_prefetch_slot_event'] );
                add_action( "gcrev_prefetch_chunk_slot_{$i}_event", [__CLASS__, 'on_prefetch_chunk_slot_event'], 10, 3 );
            }
        }

        // weekly / monthly スケジュール追加
        add_filter('cron_schedules', [__CLASS__, 'add_custom_schedules']);

        // schedule (initで「未登録なら登録」※現状と同じ)
        add_action('init', [__CLASS__, 'maybe_schedule_events']);

        // 任意：テーマ切替時に掃除（挙動を変えたくなければ削ってOK）
        add_action('switch_theme', [__CLASS__, 'unschedule_events']);

        // 管理画面専用 設定ページ
        if ( is_admin() ) {
            $gbp_settings_path = __DIR__ . '/admin/class-gbp-settings-page.php';
            if ( file_exists($gbp_settings_path) ) {
                require_once $gbp_settings_path;
                (new Gcrev_GBP_Settings_Page())->register();
            }

            $payment_settings_path = __DIR__ . '/admin/class-payment-settings-page.php';
            if ( file_exists($payment_settings_path) ) {
                require_once $payment_settings_path;
                (new Gcrev_Payment_Settings_Page())->register();
            }

            $notification_settings_path = __DIR__ . '/admin/class-notification-settings-page.php';
            if ( file_exists($notification_settings_path) ) {
                require_once $notification_settings_path;
                (new Gcrev_Notification_Settings_Page())->register();
            }

            $cron_monitor_path = __DIR__ . '/admin/class-cron-monitor-page.php';
            if ( file_exists($cron_monitor_path) ) {
                require_once $cron_monitor_path;
                (new Gcrev_Cron_Monitor_Page())->register();
            }

            $client_management_path = __DIR__ . '/admin/class-client-management-page.php';
            if ( file_exists($client_management_path) ) {
                require_once $client_management_path;
                (new Gcrev_Client_Management_Page())->register();
            }

            $cv_settings_path = __DIR__ . '/admin/class-cv-settings-page.php';
            if ( file_exists($cv_settings_path) ) {
                require_once $cv_settings_path;
                (new Gcrev_CV_Settings_Page())->register();
            }

            $qa_report_path = __DIR__ . '/admin/class-qa-report-page.php';
            if ( file_exists( $qa_report_path ) ) {
                require_once $qa_report_path;
                (new Gcrev_QA_Report_Page())->register();
            }

            $rank_tracker_path = __DIR__ . '/admin/class-rank-tracker-settings-page.php';
            if ( file_exists( $rank_tracker_path ) ) {
                require_once $rank_tracker_path;
                (new Gcrev_Rank_Tracker_Settings_Page())->register();
            }

            // デプロイ管理画面（Dev 環境のみ）
            if ( defined( 'MIMAMORI_ENV' ) && MIMAMORI_ENV === 'development' ) {
                $deploy_page_path = __DIR__ . '/admin/class-deploy-page.php';
                if ( file_exists( $deploy_page_path ) ) {
                    require_once $deploy_page_path;
                    (new Gcrev_Deploy_Page())->register();
                }
            }
        }
    }

    // =========================================================
    // Cron Callbacks（現状の closure をメソッド化しただけ）
    // =========================================================

    public static function on_prefetch_daily_event(): void {
        // スロット方式が有効な場合はスロット側で処理するので旧方式はスキップ
        if ( class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            error_log( '[GCREV] gcrev_prefetch_daily_event: slot-based scheduling active, skipping legacy prefetch' );
            return;
        }
        error_log('[GCREV] gcrev_prefetch_daily_event triggered');
        $api = new Gcrev_Insight_API(false);
        $api->prefetch_chunk(0, Gcrev_Insight_API::PREFETCH_CHUNK_LIMIT);
    }

    public static function on_prefetch_chunk_event($offset, $limit): void {
        error_log("[GCREV] gcrev_prefetch_chunk_event triggered: offset={$offset}, limit={$limit}");
        $api = new Gcrev_Insight_API(false);
        $api->prefetch_chunk((int)$offset, (int)$limit);
    }

    public static function on_monthly_report_generate_event(): void {
        error_log('[GCREV] gcrev_monthly_report_generate_event triggered');
        $api = new Gcrev_Insight_API(false);
        $api->auto_generate_monthly_reports();
    }

    /**
     * 月次レポート生成チャンクイベント
     * auto_generate_monthly_reports() からスケジュールされ、
     * 3社ずつ自己チェーンで処理する。
     */
    public static function on_monthly_report_generate_chunk_event( $offset, $limit ): void {
        error_log("[GCREV] gcrev_monthly_report_generate_chunk_event triggered: offset={$offset}, limit={$limit}");
        $api = new Gcrev_Insight_API(false);
        $api->report_generate_chunk( (int) $offset, (int) $limit );
    }

    public static function on_monthly_report_finalize_event(): void {
        error_log('[GCREV] gcrev_monthly_report_finalize_event triggered');
        $api = new Gcrev_Insight_API(false);
        $api->auto_finalize_monthly_reports();
    }

    public static function on_cron_log_cleanup(): void {
        if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
            $deleted = Gcrev_Cron_Logger::cleanup_old( 90 );
            error_log( "[GCREV] Cron log cleanup: {$deleted} old entries removed" );
        }
    }

    /**
     * 順位トラッキング — 旧日次フェッチ（後方互換: 週次に転送）
     */
    public static function on_rank_fetch_daily_event(): void {
        error_log('[GCREV] gcrev_rank_fetch_daily_event triggered (legacy → forwarding to weekly)');
        $api = new Gcrev_Insight_API(false);
        $api->auto_fetch_rankings();
    }

    /**
     * 順位トラッキング — 週次フェッチイベント（月曜 03:30）
     */
    public static function on_rank_fetch_weekly_event(): void {
        error_log('[GCREV] gcrev_rank_fetch_weekly_event triggered');
        $api = new Gcrev_Insight_API(false);
        $api->auto_fetch_rankings();
    }

    /**
     * MEO 週次フェッチイベント（月曜 04:30）
     */
    public static function on_meo_fetch_weekly_event(): void {
        error_log('[GCREV] gcrev_meo_fetch_weekly_event triggered');
        $api = new Gcrev_Insight_API(false);
        $api->auto_fetch_meo_rankings();
    }

    /**
     * MEO 週次フェッチ — チャンクイベント
     */
    public static function on_meo_fetch_chunk_event( $offset, $limit ): void {
        error_log("[GCREV] gcrev_meo_fetch_chunk_event triggered: offset={$offset}, limit={$limit}");
        $api = new Gcrev_Insight_API(false);
        $api->meo_fetch_chunk( (int) $offset, (int) $limit );
    }

    /**
     * 順位トラッキング — チャンクフェッチイベント
     */
    public static function on_rank_fetch_chunk_event( $offset, $limit ): void {
        error_log("[GCREV] gcrev_rank_fetch_chunk_event triggered: offset={$offset}, limit={$limit}");
        $api = new Gcrev_Insight_API(false);
        $api->rank_fetch_chunk( (int) $offset, (int) $limit );
    }

    /**
     * キーワード指標 — 月次フェッチイベント（検索ボリューム + SEO難易度）
     */
    public static function on_keyword_metrics_monthly(): void {
        error_log('[GCREV] gcrev_keyword_metrics_monthly_event triggered');
        $api = new Gcrev_Insight_API(false);
        $api->auto_fetch_keyword_metrics();
    }

    /**
     * キーワード指標 — チャンクフェッチイベント
     */
    public static function on_keyword_metrics_chunk( $offset, $limit ): void {
        error_log("[GCREV] gcrev_keyword_metrics_chunk_event triggered: offset={$offset}, limit={$limit}");
        $api = new Gcrev_Insight_API(false);
        $api->keyword_metrics_chunk( (int) $offset, (int) $limit );
    }

    /**
     * スロット別プリフェッチ日次イベント
     */
    public static function on_prefetch_slot_event(): void {
        $action = current_action();
        if ( preg_match( '/slot_(\d+)$/', $action, $m ) ) {
            $slot = (int) $m[1];
            error_log( "[GCREV] gcrev_prefetch_daily_event_slot_{$slot} triggered" );
            $api = new Gcrev_Insight_API( false );
            $api->prefetch_chunk_for_slot( $slot, 0, Gcrev_Insight_API::PREFETCH_CHUNK_LIMIT );
        }
    }

    /**
     * スロット別プリフェッチチャンクイベント
     */
    public static function on_prefetch_chunk_slot_event( $slot, $offset, $limit ): void {
        $slot   = (int) $slot;
        $offset = (int) $offset;
        $limit  = (int) $limit;
        error_log( "[GCREV] prefetch_chunk_slot_{$slot}_event triggered: offset={$offset}, limit={$limit}" );
        $api = new Gcrev_Insight_API( false );
        $api->prefetch_chunk_for_slot( $slot, $offset, $limit );
    }

    // =========================================================
    // Schedule登録（イベント名・時刻は現状維持）
    // =========================================================

    public static function maybe_schedule_events(): void {

        // Dev/Staging 環境では Cron スケジュール登録を完全スキップ
        if ( defined( 'MIMAMORI_ENV' ) && MIMAMORI_ENV !== 'production' ) {
            return;
        }

        // Prefetch: スロット方式が利用可能ならスロット別にスケジュール
        if ( class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            Gcrev_Prefetch_Scheduler::schedule_all_slots();
        }

        // Prefetch daily (レガシー: スロット方式が無効な場合のフォールバック)
        self::schedule_daily_if_missing('gcrev_prefetch_daily_event', 'tomorrow 03:10:00');

        // Monthly report generate (tomorrow 04:00)
        self::schedule_daily_if_missing('gcrev_monthly_report_generate_event', 'tomorrow 04:00:00');

        // Monthly report finalize (tomorrow 23:00)
        self::schedule_daily_if_missing('gcrev_monthly_report_finalize_event', 'tomorrow 23:00:00');

        // Cron log cleanup (tomorrow 02:00)
        self::schedule_daily_if_missing('gcrev_cron_log_cleanup_event', 'tomorrow 02:00:00');

        // 順位トラッキング: 週次（月曜 03:30）
        // 旧日次イベントを解除（週次に移行）
        $old_daily_rank = wp_next_scheduled('gcrev_rank_fetch_daily_event');
        if ( $old_daily_rank ) {
            wp_unschedule_event( $old_daily_rank, 'gcrev_rank_fetch_daily_event' );
        }
        self::schedule_weekly_if_missing('gcrev_rank_fetch_weekly_event', 'next Monday 03:30:00');

        // MEO 週次フェッチ（月曜 04:30）
        self::schedule_weekly_if_missing('gcrev_meo_fetch_weekly_event', 'next Monday 04:30:00');

        // キーワード指標: 月1回（1日 06:00）
        self::schedule_monthly_if_missing('gcrev_keyword_metrics_monthly_event', 'first day of next month 06:00:00');
    }

    /**
     * weekly / monthly スケジュール定義
     */
    public static function add_custom_schedules( array $schedules ): array {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => '週1回',
            ];
        }
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => '月1回',
            ];
        }
        return $schedules;
    }

    private static function schedule_daily_if_missing(string $hook, string $when): void {
        if (wp_next_scheduled($hook)) {
            return;
        }
        $tz = wp_timezone();
        $dt = new DateTimeImmutable($when, $tz);

        wp_schedule_event($dt->getTimestamp(), 'daily', $hook);
        error_log('[GCREV] Scheduled ' . $hook . ' at ' . $dt->format('Y-m-d H:i:s T'));
    }

    private static function schedule_weekly_if_missing(string $hook, string $when): void {
        if (wp_next_scheduled($hook)) {
            return;
        }
        $tz = wp_timezone();
        $dt = new DateTimeImmutable($when, $tz);

        wp_schedule_event($dt->getTimestamp(), 'weekly', $hook);
        error_log('[GCREV] Scheduled ' . $hook . ' (weekly) at ' . $dt->format('Y-m-d H:i:s T'));
    }

    private static function schedule_monthly_if_missing(string $hook, string $when): void {
        if (wp_next_scheduled($hook)) {
            return;
        }
        $tz = wp_timezone();
        $dt = new DateTimeImmutable($when, $tz);

        wp_schedule_event($dt->getTimestamp(), 'monthly', $hook);
        error_log('[GCREV] Scheduled ' . $hook . ' (monthly) at ' . $dt->format('Y-m-d H:i:s T'));
    }

    // =========================================================
    // 任意：イベント掃除（テーマ切替時）
    // =========================================================
    public static function unschedule_events(): void {
        $hooks = [
            'gcrev_prefetch_daily_event',
            'gcrev_monthly_report_generate_event',
            'gcrev_monthly_report_finalize_event',
            'gcrev_cron_log_cleanup_event',
            'gcrev_rank_fetch_daily_event',
            'gcrev_rank_fetch_weekly_event',
            'gcrev_meo_fetch_weekly_event',
            'gcrev_keyword_metrics_monthly_event',
            // chunk は single schedule が連鎖するので掃除したい場合は下も
            // 'gcrev_prefetch_chunk_event',
            // 'gcrev_monthly_report_generate_chunk_event',
            // 'gcrev_rank_fetch_chunk_event',
            // 'gcrev_keyword_metrics_chunk_event',
        ];

        foreach ($hooks as $hook) {
            $ts = wp_next_scheduled($hook);
            while ($ts) {
                wp_unschedule_event($ts, $hook);
                $ts = wp_next_scheduled($hook);
            }
        }

        // スロット別プリフェッチイベントも掃除
        if ( class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            Gcrev_Prefetch_Scheduler::unschedule_all_slots();
        }

        error_log('[GCREV] Unschedule events done (switch_theme)');
    }
}

} // end class_exists
