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

        // 順位トラッキング（日次）
        add_action('gcrev_rank_fetch_daily_event', [__CLASS__, 'on_rank_fetch_daily_event']);
        add_action('gcrev_rank_fetch_weekly_event', [__CLASS__, 'on_rank_fetch_daily_event']); // 後方互換
        add_action('gcrev_rank_fetch_chunk_event', [__CLASS__, 'on_rank_fetch_chunk_event'], 10, 2);

        // MEO 日次フェッチ
        add_action('gcrev_meo_fetch_daily_event', [__CLASS__, 'on_meo_fetch_daily_event']);
        add_action('gcrev_meo_fetch_weekly_event', [__CLASS__, 'on_meo_fetch_daily_event']); // 後方互換
        add_action('gcrev_meo_fetch_chunk_event', [__CLASS__, 'on_meo_fetch_chunk_event'], 10, 2);

        // キーワード指標（月次 — 検索ボリューム + SEO難易度）
        add_action('gcrev_keyword_metrics_monthly_event', [__CLASS__, 'on_keyword_metrics_monthly']);
        add_action('gcrev_keyword_metrics_chunk_event', [__CLASS__, 'on_keyword_metrics_chunk'], 10, 2);

        // GBP予約投稿（10分ごと）
        add_action('gcrev_gbp_posts_publish_event', [__CLASS__, 'on_gbp_posts_publish']);

        // MEOダッシュボード日次プリフェッチ
        add_action('gcrev_meo_dashboard_prefetch_event', [__CLASS__, 'on_meo_dashboard_prefetch']);

        // 年次レポート自動生成（1月のみ実行）
        add_action('gcrev_annual_report_generate_event', [__CLASS__, 'on_annual_report_generate_event']);

        // Clarity日次蓄積
        add_action('gcrev_clarity_daily_sync_event', [__CLASS__, 'on_clarity_daily_sync']);

        // AIO SERP 週次取得（Bright Data）
        add_action('gcrev_aio_serp_weekly_event', [__CLASS__, 'on_aio_serp_weekly_event']);
        add_action('gcrev_aio_serp_chunk_event', [__CLASS__, 'on_aio_serp_chunk_event'], 10, 2);

        // AIO SERP バックグラウンド取得（手動ボタン用）
        add_action('gcrev_aio_serp_bg_fetch_event', [__CLASS__, 'on_aio_serp_bg_fetch_event']);

        // AIO ページ分析（バックグラウンド）
        add_action('gcrev_aio_page_analysis_event', [__CLASS__, 'on_aio_page_analysis_event']);

        // キーワード調査: Bright Data SERP 補強（非同期、CPT保存後に即スケジュール）
        add_action('gcrev_kwr_bd_serp_async', [__CLASS__, 'on_kwr_bd_serp_async'], 10, 1);

        // 自動記事生成（日次）
        add_action('gcrev_auto_article_daily_event', [__CLASS__, 'on_auto_article_daily_event']);
        add_action('gcrev_auto_article_chunk_event', [__CLASS__, 'on_auto_article_chunk_event'], 10, 1);

        // 月次データプリフェッチ（月固定期間: prev-month, prev-prev-month, last180, last365）
        add_action('gcrev_monthly_data_prefetch_event', [__CLASS__, 'on_monthly_data_prefetch_event']);
        add_action('gcrev_monthly_prefetch_chunk_event', [__CLASS__, 'on_monthly_prefetch_chunk_event'], 10, 2);

        // 手動: 単一ユーザー全期間取得（管理画面「全取得」ボタン）
        add_action('gcrev_manual_fetch_all_event', [__CLASS__, 'on_manual_fetch_all_event']);

        // 単一ユーザーのダッシュボード初回表示用キャッシュ温め
        // - wp_login 直後の非同期 warm
        // - ダッシュボード初回表示時のキャッシュミスからの背景 warm
        add_action('gcrev_user_dashboard_warm_event', [__CLASS__, 'on_user_dashboard_warm_event'], 10, 1);
        add_action('wp_login', [__CLASS__, 'schedule_user_dashboard_warm_on_login'], 10, 2);

        // スロット別プリフェッチ
        if ( class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            $slot_count = Gcrev_Prefetch_Scheduler::get_slot_count();
            for ( $i = 0; $i < $slot_count; $i++ ) {
                add_action( "gcrev_prefetch_daily_event_slot_{$i}", [__CLASS__, 'on_prefetch_slot_event'] );
                add_action( "gcrev_prefetch_chunk_slot_{$i}_event", [__CLASS__, 'on_prefetch_chunk_slot_event'], 10, 3 );

                // 月次スロット
                add_action( "gcrev_monthly_data_event_slot_{$i}", [__CLASS__, 'on_monthly_prefetch_slot_event'] );
                add_action( "gcrev_monthly_data_chunk_slot_{$i}_event", [__CLASS__, 'on_monthly_prefetch_chunk_slot_event'], 10, 3 );
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

            $qa_registry_path = __DIR__ . '/admin/class-qa-registry-page.php';
            if ( file_exists( $qa_registry_path ) ) {
                require_once $qa_registry_path;
                (new Mimamori_QA_Registry_Page())->register();
            }

            $rank_tracker_path = __DIR__ . '/admin/class-rank-tracker-settings-page.php';
            if ( file_exists( $rank_tracker_path ) ) {
                require_once $rank_tracker_path;
                (new Gcrev_Rank_Tracker_Settings_Page())->register();
            }

            $aio_settings_path = __DIR__ . '/admin/class-aio-settings-page.php';
            if ( file_exists( $aio_settings_path ) ) {
                require_once $aio_settings_path;
                (new Gcrev_AIO_Settings_Page())->register();
            }


            // WordPress投稿連携設定
            $wp_publish_settings_path = __DIR__ . '/admin/class-wp-publish-settings-page.php';
            if ( file_exists( $wp_publish_settings_path ) ) {
                require_once $wp_publish_settings_path;
                (new Gcrev_WP_Publish_Settings_Page())->register();
            }

            $prefetch_management_path = __DIR__ . '/admin/class-prefetch-management-page.php';
            if ( file_exists( $prefetch_management_path ) ) {
                require_once $prefetch_management_path;
                (new Gcrev_Prefetch_Management_Page())->register();
            }

            $report_queue_path = __DIR__ . '/admin/class-report-queue-page.php';
            if ( file_exists( $report_queue_path ) ) {
                require_once $report_queue_path;
                (new Gcrev_Report_Queue_Page())->register();
            }

            // アンケート管理は表側ダッシュボード (page-review-survey.php + REST API) に移行済み
            // $survey_page_path = __DIR__ . '/admin/class-survey-page.php';
            // if ( file_exists( $survey_page_path ) ) {
            //     require_once $survey_page_path;
            //     (new Gcrev_Survey_Page())->register();
            // }

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
     * 月次レポート生成チャンクイベント（キューベース）
     * auto_generate_monthly_reports() からスケジュールされ、
     * キューテーブルの pending アイテムを REPORT_CHUNK_LIMIT ずつ処理する。
     *
     * @param int|mixed $job_id Cron Logger の job_id（= キューの job_id）
     * @param int|mixed $limit  チャンクサイズ
     */
    public static function on_monthly_report_generate_chunk_event( $job_id, $limit ): void {
        file_put_contents( '/tmp/gcrev_report_debug.log',
            date( 'Y-m-d H:i:s' ) . " chunk_event triggered: job_id={$job_id}, limit={$limit}\n", FILE_APPEND );
        $api = new Gcrev_Insight_API(false);
        $api->report_generate_chunk( (int) $job_id, (int) $limit );
    }

    public static function on_monthly_report_finalize_event(): void {
        error_log('[GCREV] gcrev_monthly_report_finalize_event triggered');
        $api = new Gcrev_Insight_API(false);
        $api->auto_finalize_monthly_reports();
    }

    /**
     * 年次レポート自動生成（1月のみ実行）
     * 前年1年分のデータが揃っている全ユーザーに対して年次レポートを生成。
     */
    public static function on_annual_report_generate_event(): void {
        // 1月以外はスキップ
        if ( (int) date( 'n' ) !== 1 ) {
            return;
        }

        $prev_year = (int) date( 'Y' ) - 1;
        $meta_key  = "gcrev_annual_snapshot_{$prev_year}";

        error_log( "[GCREV] annual_report_generate: starting for year={$prev_year}" );

        // GA4設定があるユーザーを取得（管理者除外）
        $users = get_users( [
            'role__not_in' => [ 'administrator' ],
            'fields'       => [ 'ID' ],
        ] );

        $api       = new Gcrev_Insight_API( false );
        $generated = 0;
        $skipped   = 0;

        foreach ( $users as $user ) {
            $uid = (int) $user->ID;

            // 既にスナップショットがあればスキップ
            $existing = get_user_meta( $uid, $meta_key, true );
            if ( ! empty( $existing ) && is_array( $existing ) ) {
                $skipped++;
                continue;
            }

            // GA4設定チェック
            try {
                $cfg = new Gcrev_Config();
                $user_config = $cfg->get_user_config( $uid );
                if ( empty( $user_config['ga4_id'] ) ) {
                    $skipped++;
                    continue;
                }
            } catch ( \Throwable $e ) {
                $skipped++;
                continue;
            }

            // REST APIのコールバックを内部呼び出し（WP_REST_Requestを模擬）
            try {
                $request = new \WP_REST_Request( 'GET', '/gcrev/v1/annual-report' );
                $request->set_param( 'year', (string) $prev_year );

                // ユーザーコンテキストを一時的に切り替え
                wp_set_current_user( $uid );

                $response = $api->rest_get_annual_report( $request );
                $data     = $response->get_data();

                if ( ! empty( $data['success'] ) ) {
                    $generated++;
                    error_log( "[GCREV] annual_report_generate: user={$uid} generated successfully" );
                } else {
                    error_log( "[GCREV] annual_report_generate: user={$uid} failed: " . ( $data['message'] ?? 'unknown' ) );
                }
            } catch ( \Throwable $e ) {
                error_log( "[GCREV] annual_report_generate: user={$uid} error: " . $e->getMessage() );
            }

            // API負荷軽減
            sleep( 2 );
        }

        // ユーザーコンテキストをリセット
        wp_set_current_user( 0 );

        error_log( "[GCREV] annual_report_generate: completed. generated={$generated}, skipped={$skipped}" );
    }

    /**
     * Clarity日次蓄積 — numOfDays=1 で直近1日分を取得し gcrev_clarity_daily に保存
     */
    public static function on_clarity_daily_sync(): void {
        $log = '/tmp/gcrev_cron_debug.log';
        file_put_contents( $log, date( 'Y-m-d H:i:s' ) . " clarity_daily_sync START\n", FILE_APPEND );

        global $wpdb;

        // Clarity連携が有効なユーザーを取得
        $users = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = '1'",
            '_gcrev_clarity_enabled'
        ) );

        if ( empty( $users ) ) {
            file_put_contents( $log, date( 'Y-m-d H:i:s' ) . " clarity_daily_sync: no enabled users\n", FILE_APPEND );
            return;
        }

        // API制限に配慮: 3コール/ユーザー × 最大3ユーザー = 9コール (10制限以内)
        $max_users = 3;
        $synced    = 0;

        foreach ( array_slice( $users, 0, $max_users ) as $uid ) {
            $uid = (int) $uid;
            try {
                wp_set_current_user( $uid );

                $result = Gcrev_Clarity_Client::sync_data( $uid, 'scheduled', 1 );

                // 日次スナップショット保存
                if ( $result['success'] ) {
                    Gcrev_Clarity_Client::save_daily_snapshot( $uid, $result, 1 );
                    $synced++;

                    // AI改善案を再生成（画像+Clarityデータが揃っているページのみ）
                    $ai_generated = self::regenerate_ai_for_user( $uid, $log );
                    file_put_contents( $log,
                        date( 'Y-m-d H:i:s' ) . " clarity_daily_sync: user={$uid}, ai_regenerated={$ai_generated}\n",
                        FILE_APPEND
                    );
                }

                file_put_contents( $log,
                    date( 'Y-m-d H:i:s' ) . " clarity_daily_sync: user={$uid}, status=" . ( $result['summary']['status'] ?? 'unknown' ) . "\n",
                    FILE_APPEND
                );
            } catch ( \Throwable $e ) {
                file_put_contents( $log,
                    date( 'Y-m-d H:i:s' ) . " clarity_daily_sync ERROR: user={$uid}, " . $e->getMessage() . "\n",
                    FILE_APPEND
                );
            }

            // API負荷軽減
            sleep( 2 );
        }

        wp_set_current_user( 0 );
        file_put_contents( $log, date( 'Y-m-d H:i:s' ) . " clarity_daily_sync END: synced={$synced}\n", FILE_APPEND );
    }

    /**
     * 指定ユーザーの全ページ分析に対してAI改善案を再生成
     *
     * @param int    $user_id
     * @param string $log  デバッグログファイルパス
     * @return int   生成したページ数
     */
    private static function regenerate_ai_for_user( int $user_id, string $log ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_page_analysis';

        // 画像 + Clarityデータが揃っているページを取得
        $pages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE user_id = %d AND status = 'active'
               AND screenshot_pc IS NOT NULL
               AND clarity_data IS NOT NULL AND clarity_data != ''",
            $user_id
        ), ARRAY_A );

        if ( empty( $pages ) ) return 0;

        $api = new \Gcrev_Insight_API( false );
        $generated = 0;

        foreach ( $pages as $page ) {
            try {
                wp_set_current_user( $user_id );
                $req = new \WP_REST_Request( 'POST' );
                $req->set_param( 'id', (int) $page['id'] );
                $res = $api->rest_trigger_page_analysis( $req );
                $data = $res->get_data();

                if ( ! empty( $data['success'] ) ) {
                    $generated++;
                }

                file_put_contents( $log,
                    date( 'Y-m-d H:i:s' ) . " ai_regen: page_id={$page['id']}, success=" . ( $data['success'] ? 'true' : 'false' ) . "\n",
                    FILE_APPEND
                );
            } catch ( \Throwable $e ) {
                file_put_contents( $log,
                    date( 'Y-m-d H:i:s' ) . " ai_regen ERROR: page_id={$page['id']}, " . $e->getMessage() . "\n",
                    FILE_APPEND
                );
            }

            // Gemini API負荷軽減
            sleep( 3 );
        }

        return $generated;
    }

    public static function on_cron_log_cleanup(): void {
        if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
            $deleted = Gcrev_Cron_Logger::cleanup_old( 90 );
            error_log( "[GCREV] Cron log cleanup: {$deleted} old entries removed" );
        }
        if ( class_exists( 'Gcrev_Report_Queue' ) ) {
            $deleted_q = Gcrev_Report_Queue::cleanup_old( 90 );
            error_log( "[GCREV] Report queue cleanup: {$deleted_q} old entries removed" );
        }
        if ( function_exists( 'gcrev_chat_logs_cleanup_old' ) ) {
            $deleted_chat = gcrev_chat_logs_cleanup_old( 90 );
            error_log( "[GCREV] Chat logs cleanup: {$deleted_chat} old entries removed" );
        }
    }

    /**
     * 順位トラッキング — 旧日次フェッチ（後方互換: 週次に転送）
     */
    public static function on_rank_fetch_daily_event(): void {
        error_log('[GCREV] gcrev_rank_fetch_daily_event triggered');
        $api = new Gcrev_Insight_API(false);
        $api->auto_fetch_rankings();
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
     * MEO 週次フェッチイベント（月曜 04:30）
     */
    public static function on_meo_fetch_daily_event(): void {
        error_log('[GCREV] gcrev_meo_fetch_daily_event triggered');
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
    // 月次データプリフェッチ Callbacks
    // =========================================================

    /**
     * 月次データプリフェッチ日次イベント（毎日 05:00 に発火、月初のみ実行）
     */
    public static function on_monthly_data_prefetch_event(): void {
        error_log('[GCREV] gcrev_monthly_data_prefetch_event triggered');
        // スロット方式が有効な場合はスロット側で処理
        if ( class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            error_log( '[GCREV] monthly_data_prefetch: slot-based scheduling active, skipping legacy' );
            return;
        }
        $api = new Gcrev_Insight_API(false);
        $api->auto_monthly_data_prefetch();
    }

    /**
     * 月次データプリフェッチ — チャンクイベント
     */
    public static function on_monthly_prefetch_chunk_event( $offset, $limit ): void {
        error_log("[GCREV] gcrev_monthly_prefetch_chunk_event triggered: offset={$offset}, limit={$limit}");
        $api = new Gcrev_Insight_API(false);
        $api->monthly_data_prefetch_chunk( (int) $offset, (int) $limit );
    }

    /**
     * 単一ユーザーのダッシュボード初回表示用キャッシュを温める。
     * wp_login 直後 + ダッシュボードキャッシュミス時 から呼ばれる。
     */
    public static function on_user_dashboard_warm_event( $user_id ): void {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) { return; }

        // 多重実行防止（同時に複数走るとAPI叩きすぎる）
        $lock_key = "gcrev_lock_dash_warm_{$user_id}";
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );

        @ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        try {
            $api = new Gcrev_Insight_API( false );
            $api->prefetch_user_dashboard( $user_id );
        } catch ( \Throwable $e ) {
            file_put_contents( '/tmp/gcrev_dash_warm_debug.log',
                date( 'Y-m-d H:i:s' ) . " warm event ERROR user_id={$user_id}: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
        }

        delete_transient( $lock_key );
    }

    /**
     * wp_login フック: ログイン直後にダッシュボード warm をスケジュール。
     *
     * 30 分以内に warm 済みならスキップ（短時間に複数回ログインしても API を叩きすぎない）。
     * その後 wp-cron.php に非同期 POST して即時実行を促す（数秒待ちを回避）。
     */
    public static function schedule_user_dashboard_warm_on_login( $user_login, $user ): void {
        if ( ! $user instanceof \WP_User ) { return; }
        $user_id = (int) $user->ID;
        if ( $user_id <= 0 ) { return; }

        // スロットル: 30 分以内に warm 済みならスキップ
        $throttle_key = "gcrev_throttle_dash_warm_{$user_id}";
        if ( get_transient( $throttle_key ) ) { return; }
        set_transient( $throttle_key, 1, 30 * MINUTE_IN_SECONDS );

        // 単発イベントをスケジュール（既に同 args でスケジュール済みなら WP が無視する）
        if ( ! wp_next_scheduled( 'gcrev_user_dashboard_warm_event', [ $user_id ] ) ) {
            wp_schedule_single_event( time(), 'gcrev_user_dashboard_warm_event', [ $user_id ] );
        }

        // wp-cron.php を非同期トリガー（ユーザーは待たされない）
        // wp_remote_post の blocking=false で fire-and-forget
        $cron_url = site_url( '/wp-cron.php?doing_wp_cron=' . microtime( true ) );
        wp_remote_post( $cron_url, [
            'blocking'  => false,
            'timeout'   => 0.5,
            'sslverify' => false,
        ] );
    }

    /**
     * 手動: 単一ユーザー全期間取得（管理画面「全取得」ボタン）
     * バックグラウンドで実行し nginx タイムアウトを回避する
     */
    public static function on_manual_fetch_all_event( $user_id ): void {
        $user_id = (int) $user_id;
        $log = '/tmp/gcrev_prefetch_debug.log';
        file_put_contents( $log, date('Y-m-d H:i:s') . " [manual_fetch_all] START user_id={$user_id}\n", FILE_APPEND );

        $api = new Gcrev_Insight_API( false );
        $all_periods = [ 'last30', 'last90', 'previousMonth', 'twoMonthsAgo', 'last180', 'last365' ];

        foreach ( $all_periods as $p ) {
            file_put_contents( $log, date('Y-m-d H:i:s') . " [manual_fetch_all] fetching period={$p} user_id={$user_id}\n", FILE_APPEND );
            try {
                $result = $api->manual_fetch_for_user( $user_id, $p );
                file_put_contents( $log, date('Y-m-d H:i:s') . " [manual_fetch_all] period={$p} result=" . wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND );
            } catch ( \Throwable $e ) {
                file_put_contents( $log, date('Y-m-d H:i:s') . " [manual_fetch_all] period={$p} EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND );
            }
        }

        file_put_contents( $log, date('Y-m-d H:i:s') . " [manual_fetch_all] DONE user_id={$user_id}\n", FILE_APPEND );
    }

    /**
     * 月次データプリフェッチ — スロット別イベント
     */
    public static function on_monthly_prefetch_slot_event(): void {
        $action = current_action();
        if ( preg_match( '/slot_(\d+)$/', $action, $m ) ) {
            $slot = (int) $m[1];
            error_log( "[GCREV] gcrev_monthly_data_event_slot_{$slot} triggered" );
            $api = new Gcrev_Insight_API( false );
            $api->monthly_prefetch_chunk_for_slot( $slot, 0, Gcrev_Insight_API::PREFETCH_CHUNK_LIMIT );
        }
    }

    /**
     * 月次データプリフェッチ — スロット別チャンクイベント
     */
    public static function on_monthly_prefetch_chunk_slot_event( $slot, $offset, $limit ): void {
        $slot   = (int) $slot;
        $offset = (int) $offset;
        $limit  = (int) $limit;
        error_log( "[GCREV] monthly_data_chunk_slot_{$slot}_event triggered: offset={$offset}, limit={$limit}" );
        $api = new Gcrev_Insight_API( false );
        $api->monthly_prefetch_chunk_for_slot( $slot, $offset, $limit );
    }

    // =========================================================
    // MEO ダッシュボード日次プリフェッチ（毎日 02:30）
    // =========================================================

    /**
     * MEOダッシュボード + 検索語句分析のキャッシュを事前に温める。
     * 深夜実行 → 日中はキャッシュから即座に返却。
     */
    public static function on_meo_dashboard_prefetch(): void {
        $lock_key = 'gcrev_lock_meo_prefetch';
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, 1800 ); // 30分ロック

        file_put_contents( '/tmp/gcrev_meo_debug.log',
            date( 'Y-m-d H:i:s' ) . " [MEO Prefetch] Cron triggered\n", FILE_APPEND );

        try {
            $api = new Gcrev_Insight_API( false );
            $api->prefetch_meo_dashboard_data();
        } catch ( \Throwable $e ) {
            file_put_contents( '/tmp/gcrev_meo_debug.log',
                date( 'Y-m-d H:i:s' ) . " [MEO Prefetch] Fatal error: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
        }

        delete_transient( $lock_key );
    }

    // =========================================================
    // GBP 予約投稿実行（10分ごと）
    // =========================================================

    public static function on_gbp_posts_publish(): void {
        $lock_key = 'gcrev_lock_gbp_publish';
        if ( get_transient( $lock_key ) ) {
            file_put_contents( '/tmp/gcrev_gbp_debug.log',
                date( 'Y-m-d H:i:s' ) . " [PostPublish] ロック中 — スキップ\n",
                FILE_APPEND
            );
            return;
        }
        set_transient( $lock_key, 1, 300 ); // 5分ロック

        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_gbp_posts';
        $now   = current_time( 'mysql' );

        file_put_contents( '/tmp/gcrev_gbp_debug.log',
            date( 'Y-m-d H:i:s' ) . " [PostPublish] バッチ開始 now={$now}\n",
            FILE_APPEND
        );

        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'scheduled' AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT 10",
            $now
        ), ARRAY_A );

        if ( empty( $posts ) ) {
            delete_transient( $lock_key );
            return;
        }

        file_put_contents( '/tmp/gcrev_gbp_debug.log',
            date( 'Y-m-d H:i:s' ) . " [PostPublish] 対象 " . count( $posts ) . " 件を投稿開始\n",
            FILE_APPEND
        );

        $api = new Gcrev_Insight_API( false );

        foreach ( $posts as $post ) {
            $uid     = (int) $post['user_id'];
            $post_id = (int) $post['id'];

            wp_set_current_user( $uid );

            $result = $api->gbp_create_local_post( $uid, $post );

            if ( $result['success'] ) {
                $wpdb->update( $table, [
                    'status'        => 'posted',
                    'posted_at'     => current_time( 'mysql' ),
                    'gbp_post_name' => $result['gbp_post_name'],
                    'error_message' => null,
                    'updated_at'    => current_time( 'mysql' ),
                ], [ 'id' => $post_id ] );

                file_put_contents( '/tmp/gcrev_gbp_debug.log',
                    date( 'Y-m-d H:i:s' ) . " [PostPublish] post_id={$post_id} user={$uid} posted OK\n",
                    FILE_APPEND
                );
            } else {
                $retry = (int) $post['retry_count'] + 1;
                $new_status = $retry >= 3 ? 'failed' : 'scheduled';
                // リスケジュール: current_time ベースで10分後（タイムゾーン統一）
                $tz = wp_timezone();
                $new_scheduled = $retry < 3
                    ? ( new \DateTimeImmutable( 'now', $tz ) )->modify( '+10 minutes' )->format( 'Y-m-d H:i:s' )
                    : $post['scheduled_at'];

                $wpdb->update( $table, [
                    'status'        => $new_status,
                    'retry_count'   => $retry,
                    'error_message' => $result['message'],
                    'scheduled_at'  => $new_scheduled,
                    'updated_at'    => current_time( 'mysql' ),
                ], [ 'id' => $post_id ] );

                file_put_contents( '/tmp/gcrev_gbp_debug.log',
                    date( 'Y-m-d H:i:s' ) . " [PostPublish] post_id={$post_id} user={$uid} FAILED (retry={$retry}): " . $result['message'] . "\n",
                    FILE_APPEND
                );
            }

            sleep( 1 ); // レート制限
        }

        wp_set_current_user( 0 );
        delete_transient( $lock_key );
    }

    // =========================================================
    // Schedule登録（イベント名・時刻は現状維持）
    // =========================================================

    public static function maybe_schedule_events(): void {

        // GBP予約投稿: 環境を問わず常に登録（予約投稿はDev/Prodどちらでも動く必要がある）
        self::maybe_schedule_gbp_publish();

        // Dev/Staging 環境では以降の日次バッチCronスケジュール登録をスキップ
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

        // 順位トラッキング: 日次（毎日 03:30）
        // 旧週次イベントを解除（日次に移行）
        $old_weekly_rank = wp_next_scheduled('gcrev_rank_fetch_weekly_event');
        if ( $old_weekly_rank ) {
            wp_unschedule_event( $old_weekly_rank, 'gcrev_rank_fetch_weekly_event' );
        }
        self::schedule_daily_if_missing('gcrev_rank_fetch_daily_event', 'tomorrow 03:30:00');

        // MEO 日次フェッチ（毎日 04:30）
        // 旧週次イベントを解除
        $old_weekly_meo = wp_next_scheduled('gcrev_meo_fetch_weekly_event');
        if ( $old_weekly_meo ) {
            wp_unschedule_event( $old_weekly_meo, 'gcrev_meo_fetch_weekly_event' );
        }
        self::schedule_daily_if_missing('gcrev_meo_fetch_daily_event', 'tomorrow 04:30:00');

        // Clarity日次蓄積（毎日 03:45）
        self::schedule_daily_if_missing('gcrev_clarity_daily_sync_event', 'tomorrow 03:45:00');

        // 月次データプリフェッチ: 毎日 05:00（月初のみ実行、月固定期間データを取得）
        if ( class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            // スロット版: 05:10, 05:40, 06:10, 06:40
            $monthly_slot_times = ['05:10:00', '05:40:00', '06:10:00', '06:40:00'];
            $slot_count = Gcrev_Prefetch_Scheduler::get_slot_count();
            for ( $i = 0; $i < $slot_count; $i++ ) {
                $slot_hook = "gcrev_monthly_data_event_slot_{$i}";
                if ( ! wp_next_scheduled( $slot_hook ) ) {
                    $time_str = $monthly_slot_times[ $i ] ?? $monthly_slot_times[0];
                    $tz = wp_timezone();
                    $dt = new DateTimeImmutable( "tomorrow {$time_str}", $tz );
                    wp_schedule_event( $dt->getTimestamp(), 'daily', $slot_hook );
                    error_log( "[GCREV] Scheduled {$slot_hook} (daily) at " . $dt->format('Y-m-d H:i:s T') );
                }
            }
        }
        self::schedule_daily_if_missing('gcrev_monthly_data_prefetch_event', 'tomorrow 05:00:00');

        // キーワード指標: 月1回（1日 06:00）
        self::schedule_monthly_if_missing('gcrev_keyword_metrics_monthly_event', 'first day of next month 06:00:00');

        // 年次レポート自動生成: 毎日 06:30（1月のみ実行、前年分を全ユーザーに対して生成）
        self::schedule_daily_if_missing('gcrev_annual_report_generate_event', 'tomorrow 06:30:00');

        // MEOダッシュボード日次プリフェッチ: 毎日 02:30
        self::schedule_daily_if_missing('gcrev_meo_dashboard_prefetch_event', 'tomorrow 02:30:00');

        // GBP予約投稿: 環境ガード前に移動済み（maybe_schedule_gbp_publish()）

        // 自動記事生成: 毎日 07:00（他の日次Cronの後）
        self::schedule_daily_if_missing('gcrev_auto_article_daily_event', 'tomorrow 07:00:00');

        // AIO SERP 週次取得: 毎週月曜 05:30（Bright Data）
        if ( ! wp_next_scheduled( 'gcrev_aio_serp_weekly_event' ) ) {
            // 次の月曜 05:30 を計算
            $tz = wp_timezone();
            $next_monday = new \DateTimeImmutable( 'next monday 05:30:00', $tz );
            wp_schedule_event( $next_monday->getTimestamp(), 'weekly', 'gcrev_aio_serp_weekly_event' );
            error_log( '[GCREV] Scheduled gcrev_aio_serp_weekly_event (weekly, Mon 05:30)' );
        }
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
        if ( ! isset( $schedules['ten_minutes'] ) ) {
            $schedules['ten_minutes'] = [
                'interval' => 600,
                'display'  => '10分ごと',
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

    /**
     * GBP予約投稿Cronを環境非依存で登録
     */
    private static function maybe_schedule_gbp_publish(): void {
        if ( ! wp_next_scheduled( 'gcrev_gbp_posts_publish_event' ) ) {
            wp_schedule_event( time(), 'ten_minutes', 'gcrev_gbp_posts_publish_event' );
            file_put_contents( '/tmp/gcrev_gbp_debug.log',
                date( 'Y-m-d H:i:s' ) . " [Cron] Scheduled gcrev_gbp_posts_publish_event (ten_minutes)\n",
                FILE_APPEND
            );
        }
    }

    /**
     * 期限超過の予約投稿を手動で一括処理する（管理者用）
     * REST API やWP-CLIから呼び出し可能
     */
    public static function process_overdue_gbp_posts(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_gbp_posts';
        $now   = current_time( 'mysql' );

        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'scheduled' AND scheduled_at <= %s ORDER BY scheduled_at ASC",
            $now
        ), ARRAY_A );

        $log = date( 'Y-m-d H:i:s' ) . " [ManualPublish] 手動実行開始 対象={$now} 件数=" . count( $posts ) . "\n";

        if ( empty( $posts ) ) {
            $log .= date( 'Y-m-d H:i:s' ) . " [ManualPublish] 対象なし — 終了\n";
            file_put_contents( '/tmp/gcrev_gbp_debug.log', $log, FILE_APPEND );
            return [ 'processed' => 0, 'success' => 0, 'failed' => 0, 'details' => [] ];
        }

        $api       = new \Gcrev_Insight_API( false );
        $success   = 0;
        $failed    = 0;
        $details   = [];
        $saved_uid = get_current_user_id();

        foreach ( $posts as $post ) {
            $uid     = (int) $post['user_id'];
            $post_id = (int) $post['id'];
            wp_set_current_user( $uid );

            $log .= date( 'Y-m-d H:i:s' ) . " [ManualPublish] post_id={$post_id} user={$uid} scheduled_at={$post['scheduled_at']} — API送信中\n";

            $result = $api->gbp_create_local_post( $uid, $post );

            if ( $result['success'] ) {
                $wpdb->update( $table, [
                    'status'        => 'posted',
                    'posted_at'     => current_time( 'mysql' ),
                    'gbp_post_name' => $result['gbp_post_name'],
                    'error_message' => null,
                    'updated_at'    => current_time( 'mysql' ),
                ], [ 'id' => $post_id ] );
                $success++;
                $log .= date( 'Y-m-d H:i:s' ) . " [ManualPublish] post_id={$post_id} 成功\n";
                $details[] = [ 'id' => $post_id, 'result' => 'posted' ];
            } else {
                $retry      = (int) $post['retry_count'] + 1;
                $new_status = $retry >= 3 ? 'failed' : 'scheduled';
                $wpdb->update( $table, [
                    'status'        => $new_status,
                    'retry_count'   => $retry,
                    'error_message' => $result['message'],
                    'updated_at'    => current_time( 'mysql' ),
                ], [ 'id' => $post_id ] );
                $failed++;
                $log .= date( 'Y-m-d H:i:s' ) . " [ManualPublish] post_id={$post_id} 失敗 (retry={$retry}): {$result['message']}\n";
                $details[] = [ 'id' => $post_id, 'result' => $new_status, 'error' => $result['message'] ];
            }
            sleep( 1 );
        }

        wp_set_current_user( $saved_uid );

        $log .= date( 'Y-m-d H:i:s' ) . " [ManualPublish] 完了 成功={$success} 失敗={$failed}\n";
        file_put_contents( '/tmp/gcrev_gbp_debug.log', $log, FILE_APPEND );

        return [
            'processed' => count( $posts ),
            'success'   => $success,
            'failed'    => $failed,
            'details'   => $details,
        ];
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
            'gcrev_monthly_data_prefetch_event',
            'gcrev_gbp_posts_publish_event',
            'gcrev_meo_dashboard_prefetch_event',
            'gcrev_auto_article_daily_event',
            // chunk は single schedule が連鎖するので掃除したい場合は下も
            // 'gcrev_prefetch_chunk_event',
            // 'gcrev_monthly_report_generate_chunk_event',
            // 'gcrev_rank_fetch_chunk_event',
            // 'gcrev_keyword_metrics_chunk_event',
            // 'gcrev_monthly_prefetch_chunk_event',
        ];

        foreach ($hooks as $hook) {
            $ts = wp_next_scheduled($hook);
            while ($ts) {
                wp_unschedule_event($ts, $hook);
                $ts = wp_next_scheduled($hook);
            }
        }

        // スロット別プリフェッチイベントも掃除（日次 + 月次）
        if ( class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            Gcrev_Prefetch_Scheduler::unschedule_all_slots();
            $slot_count = Gcrev_Prefetch_Scheduler::get_slot_count();
            for ( $i = 0; $i < $slot_count; $i++ ) {
                $hook = "gcrev_monthly_data_event_slot_{$i}";
                $ts   = wp_next_scheduled( $hook );
                while ( $ts ) {
                    wp_unschedule_event( $ts, $hook );
                    $ts = wp_next_scheduled( $hook );
                }
            }
        }

        error_log('[GCREV] Unschedule events done (switch_theme)');
    }

    // =========================================================
    // AIO SERP バックグラウンド取得（手動ボタン用）
    // =========================================================

    /**
     * 手動ボタンからの SERP 取得をバックグラウンドで実行
     * desktop + mobile 両方取得 → 完了後にページ分析をスケジュール
     */
    public static function on_aio_serp_bg_fetch_event( $user_id ): void {
        $user_id = (int) $user_id;
        error_log( "[GCREV] AIO SERP bg fetch: starting for user_id={$user_id}" );

        @ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        try {
            $config  = new Gcrev_Config();
            $service = new Gcrev_AIO_Serp_Service( $config );

            $result_desktop = $service->fetch_all_keywords( $user_id, 'desktop' );
            $result_mobile  = $service->fetch_all_keywords( $user_id, 'mobile' );

            $total = ( $result_desktop['processed'] ?? 0 ) + ( $result_mobile['processed'] ?? 0 );
            error_log( "[GCREV] AIO SERP bg fetch: user_id={$user_id} complete, {$total} keywords processed" );

            // 完了ステータス
            update_user_meta( $user_id, 'gcrev_aio_fetch_status', 'complete' );

            // 認識サマリーキャッシュを無効化（新データで再生成させる）
            delete_transient( "gcrev_aio_recog_{$user_id}" );

            // ページ分析をスケジュール
            update_user_meta( $user_id, 'gcrev_aio_analysis_status', 'analyzing' );
            wp_schedule_single_event( time() + 30, 'gcrev_aio_page_analysis_event', [ $user_id ] );

        } catch ( \Throwable $e ) {
            update_user_meta( $user_id, 'gcrev_aio_fetch_status', 'failed' );
            file_put_contents( '/tmp/gcrev_aio_serp_debug.log',
                date('Y-m-d H:i:s') . " BG fetch error user_id={$user_id}: " . $e->getMessage() . "\n", FILE_APPEND );
        }
    }

    /**
     * キーワード調査: Bright Data SERP 補強を非同期で実行
     *
     * research() が CPT 保存後に wp_schedule_single_event() で呼ぶ。
     * 同期レスポンスとは別リクエストで走るため 504 を回避する。
     */
    public static function on_kwr_bd_serp_async( $post_id ): void {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) { return; }

        @ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );  // 5分
        }

        file_put_contents( '/tmp/gcrev_seo_debug.log',
            date( 'Y-m-d H:i:s' ) . " [KWR-Async] start post_id={$post_id}\n",
            FILE_APPEND
        );

        try {
            $config = new Gcrev_Config();
            $ai     = new Gcrev_AI_Client( $config );

            $dataforseo = null;
            if ( class_exists( 'Gcrev_DataForSEO_Client' ) && Gcrev_DataForSEO_Client::is_configured() ) {
                $dataforseo = new Gcrev_DataForSEO_Client( $config );
            }
            $google_ads = class_exists( 'Gcrev_Google_Ads_Client' ) ? new Gcrev_Google_Ads_Client() : null;
            $brightdata = null;
            if ( class_exists( 'Gcrev_Brightdata_Serp_Client' ) && Gcrev_Brightdata_Serp_Client::is_configured() ) {
                $brightdata = new Gcrev_Brightdata_Serp_Client();
            }

            $service = new Gcrev_Keyword_Research_Service( $ai, $config, $dataforseo, $google_ads, $brightdata );
            $service->enrich_saved_with_brightdata_async( $post_id );

            file_put_contents( '/tmp/gcrev_seo_debug.log',
                date( 'Y-m-d H:i:s' ) . " [KWR-Async] complete post_id={$post_id}\n",
                FILE_APPEND
            );
        } catch ( \Throwable $e ) {
            file_put_contents( '/tmp/gcrev_seo_debug.log',
                date( 'Y-m-d H:i:s' ) . " [KWR-Async] ERROR post_id={$post_id}: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
        }
    }

    // =========================================================
    // AIO SERP（Bright Data）週次取得
    // =========================================================

    /**
     * 週次 AIO SERP 取得イベント
     * 全ユーザーの aio_enabled キーワードをチャンク処理で取得
     */
    public static function on_aio_serp_weekly_event(): void {
        error_log( '[GCREV] gcrev_aio_serp_weekly_event triggered' );

        if ( ! class_exists( 'Gcrev_Brightdata_Serp_Client' ) || ! Gcrev_Brightdata_Serp_Client::is_configured() ) {
            error_log( '[GCREV] AIO SERP: Bright Data not configured, skipping' );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_rank_keywords';

        // AIO 有効ユーザー一覧
        $user_ids = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$table} WHERE aio_enabled = 1 AND enabled = 1"
        );

        if ( empty( $user_ids ) ) {
            error_log( '[GCREV] AIO SERP: No users with AIO-enabled keywords' );
            return;
        }

        // 最初のチャンクをスケジュール
        wp_schedule_single_event( time() + 30, 'gcrev_aio_serp_chunk_event', [ $user_ids, 0 ] );
        error_log( '[GCREV] AIO SERP: Scheduled chunk 0 for ' . count( $user_ids ) . ' users' );
    }

    /**
     * AIO SERP チャンクイベント（1ユーザーずつ処理）
     */
    public static function on_aio_serp_chunk_event( $user_ids, $offset ): void {
        if ( ! is_array( $user_ids ) ) {
            return;
        }
        $offset = (int) $offset;

        if ( $offset >= count( $user_ids ) ) {
            error_log( '[GCREV] AIO SERP: All users processed' );
            return;
        }

        $user_id = (int) $user_ids[ $offset ];
        error_log( "[GCREV] AIO SERP chunk: processing user_id={$user_id} (offset={$offset})" );

        try {
            $config  = new Gcrev_Config();
            $service = new Gcrev_AIO_Serp_Service( $config );
            $result  = $service->fetch_all_keywords( $user_id );
            error_log( "[GCREV] AIO SERP: user_id={$user_id} processed " . ( $result['processed'] ?? 0 ) . ' keywords' );
        } catch ( \Throwable $e ) {
            file_put_contents( '/tmp/gcrev_aio_serp_debug.log',
                date('Y-m-d H:i:s') . " Cron error user_id={$user_id}: " . $e->getMessage() . "\n", FILE_APPEND );
        }

        // 次のユーザーを 60 秒後にスケジュール
        $next_offset = $offset + 1;
        if ( $next_offset < count( $user_ids ) ) {
            wp_schedule_single_event( time() + 60, 'gcrev_aio_serp_chunk_event', [ $user_ids, $next_offset ] );
        } else {
            // 全ユーザーの SERP 取得完了 → ページ分析をスケジュール
            error_log( '[GCREV] AIO SERP: All users done, scheduling page analysis' );
            foreach ( $user_ids as $uid ) {
                wp_schedule_single_event( time() + 120 + ( (int) $uid * 30 ), 'gcrev_aio_page_analysis_event', [ (int) $uid ] );
            }
        }
    }

    // =========================================================
    // AIO ページ分析（バックグラウンド）
    // =========================================================

    /**
     * 競合ページ分析バックグラウンドジョブ
     */
    public static function on_aio_page_analysis_event( $user_id ): void {
        $user_id = (int) $user_id;
        error_log( "[GCREV] AIO page analysis: starting for user_id={$user_id}" );

        @ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        try {
            $config  = new Gcrev_Config();
            $service = new Gcrev_AIO_Serp_Service( $config );
            $result  = $service->analyze_competitor_pages( $user_id );
            error_log( "[GCREV] AIO page analysis: user_id={$user_id} complete, gaps=" . ( $result['gaps_count'] ?? 0 ) );
        } catch ( \Throwable $e ) {
            update_user_meta( $user_id, 'gcrev_aio_analysis_status', 'failed' );
            file_put_contents( '/tmp/gcrev_aio_analysis_debug.log',
                date('Y-m-d H:i:s') . " Analysis error user_id={$user_id}: " . $e->getMessage() . "\n", FILE_APPEND );
        }
    }
    // =========================================================
    // 自動記事生成
    // =========================================================

    public static function on_auto_article_daily_event(): void {
        file_put_contents( '/tmp/gcrev_autoarticle_debug.log',
            date( 'Y-m-d H:i:s' ) . " daily_event triggered\n", FILE_APPEND );

        require_once __DIR__ . '/utils/class-auto-article-queue.php';
        require_once __DIR__ . '/modules/class-ai-client.php';
        require_once __DIR__ . '/modules/class-openai-client.php';
        require_once __DIR__ . '/utils/class-config.php';
        require_once __DIR__ . '/modules/class-writing-service.php';
        require_once __DIR__ . '/modules/class-keyword-research-service.php';
        require_once __DIR__ . '/modules/class-auto-article-service.php';

        $config     = new Gcrev_Config();
        $ai         = new Gcrev_AI_Client( $config );
        $openai     = null;
        if ( $config->get_writing_ai_provider() === 'openai' && $config->get_openai_api_key() !== '' ) {
            $openai = new Gcrev_OpenAI_Client( $config );
        }
        $writing    = new Gcrev_Writing_Service( $ai, $config, $openai );
        $kw_service = new Gcrev_Keyword_Research_Service( $ai, $config, null, null );
        $service    = new Gcrev_Auto_Article_Service( $writing, $ai, $config, $kw_service );
        $service->build_daily_queue();
    }

    public static function on_auto_article_chunk_event( $job_id ): void {
        file_put_contents( '/tmp/gcrev_autoarticle_debug.log',
            date( 'Y-m-d H:i:s' ) . " chunk_event triggered: job_id={$job_id}\n", FILE_APPEND );

        require_once __DIR__ . '/utils/class-auto-article-queue.php';
        require_once __DIR__ . '/modules/class-ai-client.php';
        require_once __DIR__ . '/modules/class-openai-client.php';
        require_once __DIR__ . '/utils/class-config.php';
        require_once __DIR__ . '/modules/class-writing-service.php';
        require_once __DIR__ . '/modules/class-keyword-research-service.php';
        require_once __DIR__ . '/modules/class-auto-article-service.php';

        $config     = new Gcrev_Config();
        $ai         = new Gcrev_AI_Client( $config );
        $openai     = null;
        if ( $config->get_writing_ai_provider() === 'openai' && $config->get_openai_api_key() !== '' ) {
            $openai = new Gcrev_OpenAI_Client( $config );
        }
        $writing    = new Gcrev_Writing_Service( $ai, $config, $openai );
        $kw_service = new Gcrev_Keyword_Research_Service( $ai, $config, null, null );
        $service    = new Gcrev_Auto_Article_Service( $writing, $ai, $config, $kw_service );
        $service->process_chunk( (int) $job_id );
    }
}

} // end class_exists
