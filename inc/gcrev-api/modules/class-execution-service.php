<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Gcrev_Execution_Service' ) ) { return; }

/**
 * 実行ダッシュボード — コアサービス
 *
 * 順位変動・GA4/GSCデータ・競合情報を統合し、
 * AIで「今やるべきアクション」を生成・管理する。
 */
class Gcrev_Execution_Service {

    private Gcrev_Config      $config;
    private Gcrev_AI_Client   $ai;
    private Gcrev_GA4_Fetcher $ga4;
    private Gcrev_GSC_Fetcher $gsc;

    private const DEBUG_LOG          = '/tmp/gcrev_execution_debug.log';
    private const ANALYSIS_CACHE_TTL = 86400; // 24h

    public function __construct(
        Gcrev_Config      $config,
        Gcrev_AI_Client   $ai,
        Gcrev_GA4_Fetcher $ga4,
        Gcrev_GSC_Fetcher $gsc
    ) {
        $this->config = $config;
        $this->ai     = $ai;
        $this->ga4    = $ga4;
        $this->gsc    = $gsc;
    }

    /* ==================================================================
       メインエントリ — ダッシュボード全データ一括取得
       ================================================================== */

    public function get_dashboard( int $user_id ): array {
        $tz         = wp_timezone();
        $now        = new \DateTimeImmutable( 'now', $tz );
        $action_month = $now->format( 'Y-m' );

        // ---- 高速パス: DB/キャッシュのみ（外部API呼び出しなし） ----
        $rank_alerts = $this->build_rank_alerts( $user_id );
        $progress    = $this->build_progress( $user_id, $action_month );

        // アクション: DBに既存があればそのまま返す。なければ空（生成はフロント側で /refresh を呼ぶ）
        $actions        = $this->get_existing_actions( $user_id, $action_month );
        $needs_generate = empty( $actions );

        // ステータス: キャッシュがあれば使う。なければ順位変動カウントだけ返す（GA4/GSCは呼ばない）
        $status = $this->build_status_summary_fast( $user_id );

        // 原因分析: キャッシュのみ（なければ null）
        $root_cause = $this->get_cached_root_cause( $user_id );

        // AIメッセージ（ローカル生成、軽量）
        $status['ai_message'] = $this->generate_status_message( $status, $progress, $rank_alerts );

        return [
            'status'         => $status,
            'actions'        => $actions,
            'rank_alerts'    => $rank_alerts,
            'progress'       => $progress,
            'root_cause'     => $root_cause,
            'action_month'     => $action_month,
            'needs_generate' => $needs_generate,
        ];
    }

    /* ==================================================================
       A. ステータスサマリー
       ================================================================== */

    /**
     * 高速ステータス（キャッシュ + DB のみ、外部API呼び出しなし）
     */
    private function build_status_summary_fast( int $user_id ): array {
        // キャッシュ済みスコアがあれば使う
        $cached = get_transient( "gcrev_exec_score_{$user_id}" );
        $score_data = is_array( $cached ) ? $cached : [ 'score' => 0, 'status' => 'none', 'breakdown' => [] ];

        // 既存のダッシュボードキャッシュからスコアを取得（main dashboardが先に訪問済みなら存在する）
        if ( ! is_array( $cached ) ) {
            $dash_cache = get_transient( "gcrev_dash_{$user_id}_last30" );
            if ( is_array( $dash_cache ) && isset( $dash_cache['health_score'] ) ) {
                $score_data = [
                    'score'     => (int)( $dash_cache['health_score']['score'] ?? 0 ),
                    'status'    => $dash_cache['health_score']['status'] ?? 'none',
                    'breakdown' => $dash_cache['health_score']['breakdown'] ?? [],
                ];
            }
        }

        $rank_counts = $this->count_rank_changes( $user_id );

        return [
            'score'          => $score_data['score'] ?? 0,
            'score_status'   => $score_data['status'] ?? 'none',
            'score_breakdown'=> $score_data['breakdown'] ?? [],
            'rank_up'        => $rank_counts['up'],
            'rank_down'      => $rank_counts['down'],
            'rank_stable'    => $rank_counts['stable'],
            'rank_new'       => $rank_counts['new'],
            'ai_message'     => '',
        ];
    }

    /**
     * 完全版ステータス（外部API呼び出しあり — refresh時に使用）
     */
    private function build_status_summary( int $user_id ): array {
        $score_data = $this->get_health_score( $user_id );

        $rank_counts = $this->count_rank_changes( $user_id );

        return [
            'score'          => $score_data['score'] ?? 0,
            'score_status'   => $score_data['status'] ?? 'none',
            'score_breakdown'=> $score_data['breakdown'] ?? [],
            'rank_up'        => $rank_counts['up'],
            'rank_down'      => $rank_counts['down'],
            'rank_stable'    => $rank_counts['stable'],
            'rank_new'       => $rank_counts['new'],
            'ai_message'     => '',
        ];
    }

    private function get_health_score( int $user_id ): array {
        $cached = get_transient( "gcrev_exec_score_{$user_id}" );
        if ( is_array( $cached ) ) { return $cached; }

        // 既存のダッシュボードキャッシュから取得（外部API呼び出し不要）
        $dash_cache = get_transient( "gcrev_dash_{$user_id}_last30" );
        if ( is_array( $dash_cache ) && isset( $dash_cache['health_score'] ) ) {
            $health = $dash_cache['health_score'];
            set_transient( "gcrev_exec_score_{$user_id}", $health, 3600 );
            return $health;
        }

        try {
            $user_config = $this->config->get_user_config( $user_id );
            $ga4_id  = $user_config['ga4_id'];
            $gsc_url = $user_config['gsc_url'];

            $api = new Gcrev_Insight_API( false );
            $date_helper = new Gcrev_Date_Helper();
            $last30      = $date_helper->get_date_range( 'last30' );
            $last30_comp = $date_helper->get_comparison_range( $last30['start'], $last30['end'] );

            $ga4_curr = $this->ga4->fetch_ga4_summary( $ga4_id, $last30['start'], $last30['end'], $user_id );
            $ga4_prev = $this->ga4->fetch_ga4_summary( $ga4_id, $last30_comp['start'], $last30_comp['end'], $user_id );

            $sess_curr = (int)( $ga4_curr['sessions'] ?? 0 );
            $sess_prev = (int)( $ga4_prev['sessions'] ?? 0 );
            $cv_curr   = (int)( $ga4_curr['conversions'] ?? 0 );
            $cv_prev   = (int)( $ga4_prev['conversions'] ?? 0 );

            $gsc_curr = $this->gsc->fetch_gsc_data( $gsc_url, $last30['start'], $last30['end'] );
            $gsc_prev = $this->gsc->fetch_gsc_data( $gsc_url, $last30_comp['start'], $last30_comp['end'] );
            $gsc_c = (int)( $gsc_curr['total']['impressions'] ?? 0 );
            $gsc_p = (int)( $gsc_prev['total']['impressions'] ?? 0 );

            $curr = [ 'traffic' => $sess_curr, 'cv' => $cv_curr, 'gsc' => $gsc_c, 'meo' => 0 ];
            $prev = [ 'traffic' => $sess_prev, 'cv' => $cv_prev, 'gsc' => $gsc_p, 'meo' => 0 ];

            $health = $api->calc_monthly_health_score( $curr, $prev, [], $user_id );

            set_transient( "gcrev_exec_score_{$user_id}", $health, 3600 );
            return $health;

        } catch ( \Throwable $e ) {
            $this->log( "get_health_score ERROR: " . $e->getMessage() );
            return [ 'score' => 0, 'status' => 'none', 'breakdown' => [] ];
        }
    }

    private function count_rank_changes( int $user_id ): array {
        global $wpdb;
        $res_table = $wpdb->prefix . 'gcrev_rank_results';
        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';

        $counts = [ 'up' => 0, 'down' => 0, 'stable' => 0, 'new' => 0 ];

        // 直近2回のfetch_dateを取得
        $dates = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT fetch_date FROM {$res_table}
             WHERE user_id = %d
             ORDER BY fetch_date DESC LIMIT 2",
            $user_id
        ) );

        if ( count( $dates ) < 2 ) { return $counts; }

        $latest = $dates[0];
        $prev   = $dates[1];

        // 有効キーワードの最新・前回ランクを比較
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT k.id AS keyword_id,
                    curr.rank_group AS curr_rank, curr.is_ranked AS curr_ranked,
                    prev.rank_group AS prev_rank, prev.is_ranked AS prev_ranked
             FROM {$kw_table} k
             LEFT JOIN {$res_table} curr
                 ON curr.keyword_id = k.id AND curr.user_id = k.user_id
                 AND curr.fetch_date = %s AND curr.device = 'desktop'
             LEFT JOIN {$res_table} prev
                 ON prev.keyword_id = k.id AND prev.user_id = k.user_id
                 AND prev.fetch_date = %s AND prev.device = 'desktop'
             WHERE k.user_id = %d AND k.enabled = 1",
            $latest, $prev, $user_id
        ), ARRAY_A );

        foreach ( $rows as $r ) {
            $curr_ranked = (int)( $r['curr_ranked'] ?? 0 );
            $prev_ranked = (int)( $r['prev_ranked'] ?? 0 );
            $curr_rank   = (int)( $r['curr_rank'] ?? 0 );
            $prev_rank   = (int)( $r['prev_rank'] ?? 0 );

            if ( $curr_ranked && ! $prev_ranked ) {
                $counts['new']++;
            } elseif ( ! $curr_ranked && $prev_ranked ) {
                $counts['down']++;
            } elseif ( $curr_ranked && $prev_ranked ) {
                $diff = $prev_rank - $curr_rank; // 正=上昇
                if ( $diff >= 3 )      { $counts['up']++; }
                elseif ( $diff <= -3 ) { $counts['down']++; }
                else                   { $counts['stable']++; }
            } else {
                $counts['stable']++;
            }
        }

        return $counts;
    }

    private function generate_status_message( array $status, array $progress, array $rank_alerts ): string {
        $down = $status['rank_down'] ?? 0;
        $rate = $progress['overall']['rate'] ?? 0;

        if ( $down > 0 ) {
            return sprintf(
                '順位が下がっているキーワードが%d件あります。アクションリストを確認して対策しましょう。',
                $down
            );
        }
        if ( $rate < 0.3 ) {
            return '今月のアクションがまだあまり進んでいません。まずは優先度の高いものから始めましょう。';
        }
        if ( $rate >= 0.8 ) {
            return '順調に進んでいます。この調子で残りのアクションも完了させましょう。';
        }
        return '全体的に安定しています。引き続きアクションを進めていきましょう。';
    }

    /* ==================================================================
       B. アクションリスト
       ================================================================== */

    /**
     * DB既存アクションのみ取得（AI生成しない）
     */
    public function get_existing_actions( int $user_id, string $action_month ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_execution_actions';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND action_month = %s ORDER BY sort_order ASC, id ASC",
            $user_id, $action_month
        ), ARRAY_A );

        return ! empty( $rows ) ? $this->format_actions( $rows ) : [];
    }

    /**
     * アクション取得（なければAI生成）— refresh 時に使用
     */
    public function get_or_generate_actions( int $user_id, string $action_month ): array {
        $existing = $this->get_existing_actions( $user_id, $action_month );
        if ( ! empty( $existing ) ) {
            return $existing;
        }

        return $this->generate_and_store_actions( $user_id, $action_month );
    }

    private function generate_and_store_actions( int $user_id, string $action_month ): array {
        $this->log( "generate_and_store_actions START user={$user_id} month={$action_month}" );

        $context = $this->collect_context_for_ai( $user_id );
        $this->log( "context collected: rank_alerts=" . count( $context['rank_alerts'] ?? [] ) . " ga4_sessions=" . ( $context['ga4']['sessions'] ?? 'N/A' ) );

        $actions = $this->call_ai_for_actions( $context, $user_id );
        $this->log( "AI returned " . count( $actions ) . " actions" );

        if ( empty( $actions ) ) {
            $this->log( "Using fallback actions" );
            $actions = $this->get_fallback_actions();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_execution_actions';
        $now   = current_time( 'mysql' );

        // テーブル存在チェック
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( ! $table_exists ) {
            $this->log( "generate: TABLE NOT FOUND: {$table}" );
            gcrev_execution_actions_create_table();
        }

        $inserted = 0;
        foreach ( $actions as $i => $action ) {
            $result = $wpdb->insert( $table, [
                'user_id'               => $user_id,
                'action_month'            => $action_month,
                'action_type'           => sanitize_text_field( $action['action_type'] ?? 'article_create' ),
                'priority'              => sanitize_text_field( $action['priority'] ?? 'medium' ),
                'title'                 => sanitize_text_field( $action['title'] ?? '' ),
                'reason'                => sanitize_text_field( $action['reason'] ?? '' ),
                'target_keyword'        => sanitize_text_field( $action['target_keyword'] ?? '' ),
                'target_url'            => esc_url_raw( $action['target_url'] ?? '' ),
                'quantity'              => absint( $action['quantity'] ?? 0 ),
                'unit'                  => sanitize_text_field( $action['unit'] ?? '' ),
                'expected_effect'       => sanitize_text_field( $action['expected_effect'] ?? '' ),
                'comparison_self'       => sanitize_text_field( $action['comparison']['self'] ?? '' ),
                'comparison_competitor' => sanitize_text_field( $action['comparison']['competitor_avg'] ?? '' ),
                'guide_text'            => wp_kses_post( $action['guide_text'] ?? '' ),
                'is_auto_executable'    => in_array( $action['action_type'], [ 'article_create', 'meo_post' ], true ) ? 1 : 0,
                'status'                => 'pending',
                'sort_order'            => $i,
                'created_at'            => $now,
                'updated_at'            => $now,
            ] );
            if ( $result === false ) {
                $this->log( "generate INSERT FAILED: " . $wpdb->last_error );
            } else {
                $inserted++;
            }
        }
        $this->log( "generate_and_store: inserted={$inserted}/" . count( $actions ) );

        return $this->get_or_generate_actions( $user_id, $action_month );
    }

    /**
     * AI分析用コンテキスト収集（外部API呼び出しなし — DB/キャッシュのみ）
     */
    private function collect_context_for_ai( int $user_id ): array {
        $context = [];

        try {
            // 順位変動（DBクエリのみ）
            $context['rank_alerts'] = $this->build_rank_alerts( $user_id );

            // GA4/GSC: キャッシュ優先 → なければAPI直接取得
            $ga4_data = null;
            $gsc_data = null;

            // 1) Transientキャッシュから試す
            $dash_cache = get_transient( "gcrev_dash_{$user_id}_last30" );
            if ( is_array( $dash_cache ) && ( ( $dash_cache['sessions'] ?? $dash_cache['visits'] ?? 0 ) > 0 ) ) {
                $ga4_data = [
                    'sessions'    => $dash_cache['sessions'] ?? $dash_cache['visits'] ?? 0,
                    'users'       => $dash_cache['users'] ?? 0,
                    'pageviews'   => $dash_cache['pageViews'] ?? $dash_cache['pv'] ?? 0,
                    'conversions' => $dash_cache['conversions'] ?? $dash_cache['cv'] ?? 0,
                    'avg_duration'=> $dash_cache['avgDuration'] ?? 0,
                ];
                $gsc_data = $dash_cache['keywords'] ?? [];
            }

            // 2) キャッシュなし or 空 → GA4/GSC APIを直接呼ぶ（refresh時のみ実行されるので許容）
            if ( ! $ga4_data ) {
                $this->log( "collect_context: cache miss — calling GA4/GSC APIs" );
                try {
                    $user_config = $this->config->get_user_config( $user_id );
                    $date_helper = new Gcrev_Date_Helper();
                    $last30 = $date_helper->get_date_range( 'last30' );

                    $ga4_raw = $this->ga4->fetch_ga4_summary( $user_config['ga4_id'], $last30['start'], $last30['end'], $user_id );
                    $ga4_data = [
                        'sessions'    => $ga4_raw['sessions'] ?? 0,
                        'users'       => $ga4_raw['users'] ?? 0,
                        'pageviews'   => $ga4_raw['pageViews'] ?? 0,
                        'conversions' => $ga4_raw['conversions'] ?? 0,
                        'avg_duration'=> $ga4_raw['avgDuration'] ?? 0,
                    ];

                    $gsc_raw = $this->gsc->fetch_gsc_data( $user_config['gsc_url'], $last30['start'], $last30['end'] );
                    $gsc_data = $gsc_raw['keywords'] ?? [];
                } catch ( \Throwable $e ) {
                    $this->log( "collect_context GA4/GSC API error: " . $e->getMessage() );
                    $ga4_data = [ 'sessions' => 0, 'users' => 0, 'pageviews' => 0, 'conversions' => 0, 'avg_duration' => 0 ];
                    $gsc_data = [];
                }
            }

            $context['ga4'] = $ga4_data;
            $context['gsc_keywords'] = array_slice( $gsc_data, 0, 20 );

            // 記事公開履歴（DBクエリのみ）
            global $wpdb;
            $articles = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_title, post_date, post_name
                 FROM {$wpdb->posts}
                 WHERE post_author = %d AND post_type = 'post' AND post_status = 'publish'
                 AND post_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                 ORDER BY post_date DESC LIMIT 20",
                $user_id
            ), ARRAY_A );
            $context['recent_articles'] = $articles;

            // クライアント設定（business / 月次レポート方針）
            $context['business']       = $this->collect_client_settings( $user_id );
            $context['report_policy']  = $this->collect_report_policy( $user_id );

            // ペルソナ
            $context['persona']        = $this->collect_persona( $user_id );

            // ページ診断 + Clarity 指標
            $context['page_analysis']  = $this->collect_page_analysis( $user_id );

            // 競合サイト内容（最新のキーワード調査結果から）
            $context['competitors']    = $this->collect_competitor_data( $user_id );

            // MEO（GBP）ダッシュボードデータ
            $context['meo']            = $this->collect_meo_data( $user_id );

        } catch ( \Throwable $e ) {
            $this->log( "collect_context_for_ai ERROR: " . $e->getMessage() );
        }

        return $context;
    }

    /**
     * クライアント設定（業種・地域・事業タイプ・主要CV など）を集約
     */
    private function collect_client_settings( int $user_id ): array {
        $client_info = get_user_meta( $user_id, 'gcrev_client_info', true );
        $client_info = is_array( $client_info ) ? $client_info : [];

        $get = fn( string $key ) => (string) ( get_user_meta( $user_id, $key, true ) ?: ( $client_info[ $key ] ?? '' ) );

        $area_type   = $get( 'gcrev_client_area_type' );
        $area_pref   = $get( 'gcrev_client_area_pref' );
        $area_city   = $get( 'gcrev_client_area_city' );
        $area_custom = $get( 'gcrev_client_area_custom' );
        $area_parts  = array_filter( [ $area_pref, $area_city, $area_custom ] );
        $area_label  = $area_parts ? implode( ' ', $area_parts ) : ( $client_info['area'] ?? '' );

        $main_conversions = $get( 'gcrev_client_main_conversions' );
        if ( $main_conversions === '' ) { $main_conversions = (string) ( $client_info['main_conversions'] ?? '' ); }

        return [
            'industry'           => $get( 'gcrev_client_industry_category' ) ?: ( $client_info['industry'] ?? '' ),
            'industry_detail'    => $get( 'gcrev_client_industry_detail' ),
            'industry_sub'       => $get( 'gcrev_client_industry_subcategory' ),
            'area_type'          => $area_type,
            'area'               => $area_label,
            'business_type'      => $get( 'gcrev_client_business_type' ),
            'stage'              => $get( 'gcrev_client_stage' ),
            'main_conversions'   => $main_conversions,
            'site_url'           => $get( 'gcrev_client_site_url' ) ?: ( $client_info['site_url'] ?? '' ),
        ];
    }

    /**
     * 月次レポート方針（課題・目標・重視する数値 など）
     */
    private function collect_report_policy( int $user_id ): array {
        $keys = [
            'report_issue', 'report_goal_monthly', 'report_focus_numbers',
            'report_current_state', 'report_goal_main', 'report_additional_notes',
            'report_output_mode',
        ];
        $out = [];
        foreach ( $keys as $k ) {
            $v = get_user_meta( $user_id, $k, true );
            if ( is_array( $v ) ) {
                $v = implode( ', ', array_map( 'strval', $v ) );
            }
            $out[ $k ] = (string) ( $v ?? '' );
        }
        return $out;
    }

    /**
     * ペルソナ設定
     */
    private function collect_persona( int $user_id ): array {
        $flatten = function ( $v ): string {
            if ( is_array( $v ) ) { return implode( ', ', array_map( 'strval', $v ) ); }
            return (string) ( $v ?? '' );
        };
        return [
            'one_liner'        => $flatten( get_user_meta( $user_id, 'gcrev_client_persona_one_liner', true ) ),
            'detail_text'      => $flatten( get_user_meta( $user_id, 'gcrev_client_persona_detail_text', true ) ),
            'age_ranges'       => $flatten( get_user_meta( $user_id, 'gcrev_client_persona_age_ranges', true ) ),
            'genders'          => $flatten( get_user_meta( $user_id, 'gcrev_client_persona_genders', true ) ),
            'attributes'       => $flatten( get_user_meta( $user_id, 'gcrev_client_persona_attributes', true ) ),
            'decision_factors' => $flatten( get_user_meta( $user_id, 'gcrev_client_persona_decision_factors', true ) ),
        ];
    }

    /**
     * ページ診断結果 + Clarity 指標（直近30日集計）
     *
     * 各ページ最大15件まで、AI に重要ページのみ渡す。
     */
    private function collect_page_analysis( int $user_id, int $limit = 15 ): array {
        global $wpdb;
        $pa_table      = $wpdb->prefix . 'gcrev_page_analysis';
        $clarity_table = $wpdb->prefix . 'gcrev_clarity_daily';

        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$pa_table}'" );
        if ( ! $exists ) { return []; }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, page_url, page_title, page_type, page_purpose, page_cta,
                    ai_summary, ai_insights, ai_analysis_date, clarity_sync_date
             FROM {$pa_table}
             WHERE user_id = %d AND status = 'active'
             ORDER BY sort_order ASC, updated_at DESC
             LIMIT %d",
            $user_id, $limit
        ), ARRAY_A );
        if ( empty( $rows ) ) { return []; }

        $clarity_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$clarity_table}'" );

        $out = [];
        foreach ( $rows as $r ) {
            $clarity = null;
            if ( $clarity_exists ) {
                $clarity = $wpdb->get_row( $wpdb->prepare(
                    "SELECT
                        SUM(sessions) AS sessions,
                        SUM(page_views) AS page_views,
                        AVG(scroll_depth) AS scroll_depth,
                        AVG(engagement_time) AS engagement_time,
                        SUM(dead_click) AS dead_click,
                        SUM(rage_click) AS rage_click,
                        SUM(error_click) AS error_click
                     FROM {$clarity_table}
                     WHERE user_id = %d AND page_analysis_id = %d AND device_type = 'all'
                     AND target_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                    $user_id, (int) $r['id']
                ), ARRAY_A );
            }

            // ai_insights は JSON の場合があるので文字列化
            $insights = $r['ai_insights'];
            if ( $insights && ( $decoded = json_decode( $insights, true ) ) !== null ) {
                $insights = is_array( $decoded ) ? wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE ) : $insights;
            }

            $out[] = [
                'url'         => $r['page_url'],
                'title'       => $r['page_title'],
                'type'        => $r['page_type'],
                'purpose'     => $r['page_purpose'],
                'cta'         => $r['page_cta'],
                'ai_summary'  => $r['ai_summary'] ? mb_substr( (string) $r['ai_summary'], 0, 300 ) : '',
                'ai_insights' => $insights ? mb_substr( (string) $insights, 0, 400 ) : '',
                'clarity'     => $clarity ? [
                    'sessions'        => (int) ( $clarity['sessions'] ?? 0 ),
                    'page_views'      => (int) ( $clarity['page_views'] ?? 0 ),
                    'scroll_depth'    => $clarity['scroll_depth'] !== null ? round( (float) $clarity['scroll_depth'], 1 ) : null,
                    'engagement_time' => $clarity['engagement_time'] !== null ? (int) $clarity['engagement_time'] : null,
                    'dead_click'      => (int) ( $clarity['dead_click'] ?? 0 ),
                    'rage_click'      => (int) ( $clarity['rage_click'] ?? 0 ),
                    'error_click'     => (int) ( $clarity['error_click'] ?? 0 ),
                ] : null,
            ];
        }
        return $out;
    }

    /**
     * MEO（GBP）ダッシュボードデータ
     *
     * 既存の MEO REST エンドポイントが作成する Transient キャッシュを読む。
     * キャッシュが無ければ空配列を返す（外部API呼び出しはしない）。
     */
    private function collect_meo_data( int $user_id ): array {
        $location_id = get_user_meta( $user_id, '_gcrev_gbp_location_id', true );
        if ( empty( $location_id ) || strpos( (string) $location_id, 'pending_' ) === 0 ) {
            return [];
        }

        try {
            $dates_helper = new Gcrev_Date_Helper();
            $periods = [ 'prev-month', 'last30' ];

            foreach ( $periods as $period ) {
                $dates  = $dates_helper->calculate_period_dates( $period );
                $hash   = md5( "{$dates['start']}_{$dates['end']}" );
                $cache  = get_transient( "gcrev_meo_{$user_id}_{$period}_{$hash}" );
                if ( ! is_array( $cache ) || empty( $cache['metrics'] ) ) { continue; }

                $curr = $cache['metrics']          ?? [];
                $prev = $cache['metrics_previous'] ?? [];
                $kw_series = $cache['search_keywords'] ?? [];

                // 検索キーワード: 最新月のトップをいくつか抽出
                $top_keywords = [];
                if ( is_array( $kw_series ) && ! empty( $kw_series ) ) {
                    $latest = end( $kw_series );
                    if ( is_array( $latest ) && ! empty( $latest['keywords'] ) ) {
                        foreach ( array_slice( $latest['keywords'], 0, 10 ) as $k ) {
                            $top_keywords[] = [
                                'keyword' => (string) ( $k['keyword'] ?? $k['query'] ?? '' ),
                                'count'   => (int) ( $k['count'] ?? $k['impressions'] ?? 0 ),
                            ];
                        }
                    }
                }

                return [
                    'period_label' => $cache['current_range_label'] ?? '',
                    'metrics'      => [
                        'total_impressions'   => (int) ( $curr['total_impressions']   ?? 0 ),
                        'mobile_impressions'  => (int) ( $curr['mobile_impressions']  ?? 0 ),
                        'desktop_impressions' => (int) ( $curr['desktop_impressions'] ?? 0 ),
                        'call_clicks'         => (int) ( $curr['call_clicks']         ?? 0 ),
                        'direction_clicks'    => (int) ( $curr['direction_clicks']    ?? 0 ),
                        'website_clicks'      => (int) ( $curr['website_clicks']      ?? 0 ),
                    ],
                    'previous'     => [
                        'total_impressions' => (int) ( $prev['total_impressions'] ?? 0 ),
                        'call_clicks'       => (int) ( $prev['call_clicks']       ?? 0 ),
                        'direction_clicks'  => (int) ( $prev['direction_clicks']  ?? 0 ),
                        'website_clicks'    => (int) ( $prev['website_clicks']    ?? 0 ),
                    ],
                    'top_keywords' => $top_keywords,
                ];
            }
        } catch ( \Throwable $e ) {
            $this->log( "collect_meo_data ERROR: " . $e->getMessage() );
        }
        return [];
    }

    /**
     * 競合サイト内容（ユーザーが指定した参照URLのHTML解析結果）
     *
     * 最新のキーワード調査 CPT の post_meta `_gcrev_kwr_competitor_data` を参照。
     */
    private function collect_competitor_data( int $user_id ): array {
        $posts = get_posts( [
            'post_type'      => 'gcrev_kw_research',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_key'       => '_gcrev_kwr_user_id',
            'meta_value'     => (string) $user_id,
            'fields'         => 'ids',
            'post_status'    => 'any',
            'no_found_rows'  => true,
        ] );
        if ( empty( $posts ) ) { return []; }

        $post_id = (int) $posts[0];
        $raw = get_post_meta( $post_id, '_gcrev_kwr_competitor_data', true );
        $data = json_decode( (string) $raw, true );
        if ( ! is_array( $data ) ) { return []; }

        $out = [];
        foreach ( array_slice( $data, 0, 5 ) as $c ) {
            if ( ! is_array( $c ) || ( $c['status'] ?? '' ) !== 'ok' ) { continue; }
            $h = $c['headings'] ?? [ 'h1' => [], 'h2' => [], 'h3' => [] ];
            $out[] = [
                'url'              => (string) ( $c['url'] ?? '' ),
                'note'             => (string) ( $c['note'] ?? '' ),
                'title'            => mb_substr( (string) ( $c['title'] ?? '' ), 0, 120 ),
                'meta_description' => mb_substr( (string) ( $c['meta_description'] ?? '' ), 0, 200 ),
                'h1'               => array_slice( array_map( fn( $v ) => mb_substr( (string) $v, 0, 80 ), (array) ( $h['h1'] ?? [] ) ), 0, 3 ),
                'h2'               => array_slice( array_map( fn( $v ) => mb_substr( (string) $v, 0, 80 ), (array) ( $h['h2'] ?? [] ) ), 0, 10 ),
                'h3'               => array_slice( array_map( fn( $v ) => mb_substr( (string) $v, 0, 80 ), (array) ( $h['h3'] ?? [] ) ), 0, 10 ),
                'body_excerpt'     => mb_substr( (string) ( $c['body_excerpt'] ?? '' ), 0, 500 ),
            ];
        }
        return $out;
    }

    private function call_ai_for_actions( array $context, int $user_id ): array {
        $business = $context['business'] ?? [];
        $industry = trim( ( $business['industry'] ?? '' ) . ' ' . ( $business['industry_detail'] ?? '' ) . ' ' . ( $business['industry_sub'] ?? '' ) );
        if ( $industry === '' ) { $industry = '（未設定）'; }
        $area            = $business['area'] ?? '';
        $site_url        = $business['site_url'] ?? '';
        $business_type   = $business['business_type'] ?? '';
        $stage           = $business['stage'] ?? '';
        $main_conversions = $business['main_conversions'] ?? '';

        // 月次レポート方針
        $policy = $context['report_policy'] ?? [];
        $policy_lines = '';
        $policy_map = [
            'report_issue'            => '課題',
            'report_goal_main'        => '主目標',
            'report_goal_monthly'     => '月次目標',
            'report_focus_numbers'    => '重視する数値',
            'report_current_state'    => '現状',
            'report_additional_notes' => '追加事項',
        ];
        foreach ( $policy_map as $k => $label ) {
            $v = trim( (string) ( $policy[ $k ] ?? '' ) );
            if ( $v !== '' ) { $policy_lines .= "- {$label}: {$v}\n"; }
        }
        if ( $policy_lines === '' ) { $policy_lines = "（未設定）\n"; }

        // ペルソナ
        $persona = $context['persona'] ?? [];
        $persona_lines = '';
        if ( trim( (string) ( $persona['one_liner'] ?? '' ) ) !== '' ) {
            $persona_lines .= "- 一行要約: {$persona['one_liner']}\n";
        }
        if ( trim( (string) ( $persona['detail_text'] ?? '' ) ) !== '' ) {
            $persona_lines .= "- 詳細: " . mb_substr( (string) $persona['detail_text'], 0, 400 ) . "\n";
        }
        foreach ( [ 'age_ranges' => '年齢層', 'genders' => '性別', 'attributes' => '属性', 'decision_factors' => '決定要因' ] as $k => $label ) {
            $v = trim( (string) ( $persona[ $k ] ?? '' ) );
            if ( $v !== '' ) { $persona_lines .= "- {$label}: {$v}\n"; }
        }
        if ( $persona_lines === '' ) { $persona_lines = "（未設定）\n"; }

        // 順位変動を簡潔にまとめる
        $rank_summary = '';
        foreach ( array_slice( $context['rank_alerts'] ?? [], 0, 10 ) as $alert ) {
            $rank_summary .= sprintf(
                "- %s: %s → %s (%s)\n",
                $alert['keyword'], $alert['prev_rank_label'], $alert['curr_rank_label'], $alert['severity']
            );
        }
        if ( $rank_summary === '' ) { $rank_summary = "（データなし）\n"; }

        // GA4
        $ga4 = $context['ga4'] ?? [];
        $ga4_summary = sprintf(
            "セッション: %s, ユーザー: %s, PV: %s, CV: %s, 平均滞在: %s秒",
            $ga4['sessions'] ?? 0, $ga4['users'] ?? 0,
            $ga4['pageviews'] ?? 0, $ga4['conversions'] ?? 0,
            $ga4['avg_duration'] ?? 0
        );

        // GSCキーワード
        $gsc_lines = '';
        foreach ( array_slice( $context['gsc_keywords'] ?? [], 0, 15 ) as $kw ) {
            $gsc_lines .= sprintf(
                "- %s (表示: %s, クリック: %s, 順位: %s)\n",
                $kw['query'] ?? '', $kw['impressions'] ?? 0, $kw['clicks'] ?? 0,
                round( (float)( $kw['_position'] ?? $kw['position'] ?? 0 ), 1 )
            );
        }
        if ( $gsc_lines === '' ) { $gsc_lines = "（データなし）\n"; }

        // ページ診断 + Clarity
        $page_lines = '';
        foreach ( array_slice( $context['page_analysis'] ?? [], 0, 10 ) as $p ) {
            $page_lines .= "- URL: {$p['url']}\n";
            if ( ! empty( $p['title'] ) )   { $page_lines .= "  タイトル: {$p['title']}\n"; }
            if ( ! empty( $p['type'] ) )    { $page_lines .= "  種類: {$p['type']}"; }
            if ( ! empty( $p['purpose'] ) ) { $page_lines .= " / 目的: {$p['purpose']}"; }
            if ( ! empty( $p['cta'] ) )     { $page_lines .= " / CTA: {$p['cta']}"; }
            $page_lines .= "\n";
            if ( ! empty( $p['ai_summary'] ) )  { $page_lines .= "  AI要約: {$p['ai_summary']}\n"; }
            if ( ! empty( $p['ai_insights'] ) ) { $page_lines .= "  AI所見: {$p['ai_insights']}\n"; }
            if ( ! empty( $p['clarity'] ) ) {
                $c = $p['clarity'];
                $page_lines .= sprintf(
                    "  Clarity(30日): セッション%d, PV%d, スクロール%s%%, 滞在%s秒, デッドクリック%d, レージクリック%d, エラー%d\n",
                    $c['sessions'], $c['page_views'],
                    $c['scroll_depth'] !== null ? (string) $c['scroll_depth'] : '—',
                    $c['engagement_time'] !== null ? (string) $c['engagement_time'] : '—',
                    $c['dead_click'], $c['rage_click'], $c['error_click']
                );
            }
        }
        if ( $page_lines === '' ) { $page_lines = "（ページ診断未実施）\n"; }

        // MEO（GBP）ダッシュボード
        $meo_lines = '';
        $meo = $context['meo'] ?? [];
        if ( ! empty( $meo['metrics'] ) ) {
            $m = $meo['metrics'];
            $p = $meo['previous'] ?? [];
            $meo_lines .= "- 期間: {$meo['period_label']}\n";
            $meo_lines .= sprintf(
                "- 表示回数合計: %d (モバイル%d / デスクトップ%d)\n",
                $m['total_impressions'], $m['mobile_impressions'], $m['desktop_impressions']
            );
            $meo_lines .= sprintf(
                "- 電話タップ: %d (前期間 %d) / ルート検索: %d (前期間 %d) / サイト訪問: %d (前期間 %d)\n",
                $m['call_clicks'], $p['call_clicks'] ?? 0,
                $m['direction_clicks'], $p['direction_clicks'] ?? 0,
                $m['website_clicks'], $p['website_clicks'] ?? 0
            );
            if ( ! empty( $meo['top_keywords'] ) ) {
                $meo_lines .= "- 検索語句トップ:\n";
                foreach ( $meo['top_keywords'] as $k ) {
                    $meo_lines .= "  ・{$k['keyword']} ({$k['count']}回)\n";
                }
            }
        }
        if ( $meo_lines === '' ) { $meo_lines = "（MEO未連携またはデータ無し）\n"; }

        // 競合サイト内容
        $comp_lines = '';
        foreach ( $context['competitors'] ?? [] as $c ) {
            $comp_lines .= "- URL: {$c['url']}\n";
            if ( ! empty( $c['note'] ) )             { $comp_lines .= "  メモ: {$c['note']}\n"; }
            if ( ! empty( $c['title'] ) )            { $comp_lines .= "  タイトル: {$c['title']}\n"; }
            if ( ! empty( $c['meta_description'] ) ) { $comp_lines .= "  メタ: {$c['meta_description']}\n"; }
            if ( ! empty( $c['h1'] ) )               { $comp_lines .= "  H1: " . implode( ' / ', $c['h1'] ) . "\n"; }
            if ( ! empty( $c['h2'] ) )               { $comp_lines .= "  H2: " . implode( ' / ', $c['h2'] ) . "\n"; }
            if ( ! empty( $c['h3'] ) )               { $comp_lines .= "  H3: " . implode( ' / ', $c['h3'] ) . "\n"; }
            if ( ! empty( $c['body_excerpt'] ) )     { $comp_lines .= "  本文抜粋: {$c['body_excerpt']}\n"; }
        }
        if ( $comp_lines === '' ) { $comp_lines = "（競合参考URL未登録）\n"; }

        // 記事数
        $article_count = count( $context['recent_articles'] ?? [] );

        $prompt = <<<PROMPT
あなたは中小企業のWeb集客コンサルタントです。
以下に示す **このクライアント固有の実データ** のみを根拠に、具体的で実行可能な改善アクションを5件以内でJSON配列として出力してください。

【最重要ルール — 事実の捏造禁止】
- 下記の各セクションで **明示的に与えられている情報のみ** を根拠にすること。
- 数値の比較・断定は、渡されたデータに裏付けがある場合のみ許可。例えば:
  - ✅ GSCキーワード欄に「松山 ホームページ制作 (表示:500, 順位:8.3)」がある → 「順位が8位前後で伸び悩んでいる」と書いてよい
  - ✅ ページ診断欄に「Clarity: レージクリック12」がある → 「〇〇ページでレージクリックが12件発生しておりUX問題の可能性があります」と書いてよい
  - ✅ 競合サイト欄に該当URLのH2見出しが列挙されている → 「競合は〇〇〇という見出しで情報を提供している」と書いてよい
  - ❌ データ欄にないのに「あなたのサイトは◯◯件」「競合は平均◯本」等の数値を創作するのは禁止
- ページ診断・Clarity・競合 のいずれかが「未実施/未登録」と書かれている場合、そのセクションに基づく断定はしないこと。

【アクションのgrounding（具体性）の優先度】
- 以下の順でより具体的なアクションを優先:
  1. 順位変動で下落キーワードがあれば、該当キーワードのリライト/記事追加/内部リンク補強
  2. Clarityでレージクリック・デッドクリック・離脱の兆候があるページの改善
  3. 競合サイトの見出し構成と比較して不足している情報カテゴリの補充
  4. ペルソナ・主要CV・月次レポート方針に沿った CV 導線強化
  5. 一般的 SEO ベストプラクティス（最後の手段）

【出力件数・重複ルール】
- 出力は5件以内、6件以上は禁止
- (action_type, target_keyword) の組合せは配列内でユニーク
- target_keyword が空の汎用アクション（internal_link / meta_fix / page_speed など）は配列内で高々1件
- target_keyword を指定する場合は、下記「順位変動」「GSCキーワード」に含まれるものだけ。該当なしなら空文字

【形式ルール】
- title は「〜してください」の命令形、数量・対象を含める（例「松山ホームページ制作のサービスページを1,500文字以上リライトしてください」）
- reason は1文、上記のデータ根拠を明記
- expected_effect は1文。楽観的断言は避け「〜が期待できます」「〜につながります」程度
- comparison フィールドは出力しない

=== 入力データ ===

【クライアント基本情報】
業種: {$industry}
地域: {$area}
事業タイプ: {$business_type} / ステージ: {$stage}
主要CV: {$main_conversions}
サイトURL: {$site_url}

【月次レポート方針（クライアントが重視していること）】
{$policy_lines}
【ペルソナ】
{$persona_lines}
【順位変動（直近2回の比較）】
{$rank_summary}
【アクセスデータ（GA4 直近30日）】
{$ga4_summary}

【GSCキーワード（直近）】
{$gsc_lines}
【記事公開状況】
直近60日で{$article_count}本公開

【ページ診断＋Clarity行動データ】
{$page_lines}
【MEO（Googleビジネスプロフィール）実績】
{$meo_lines}
【競合サイト内容（ユーザー登録の参考URL解析）】
{$comp_lines}
=== 出力形式 ===
action_type: article_create / rewrite / internal_link / meo_post / meta_fix / page_speed / ux_fix / cv_improvement
priority: high(最大2個) / medium / low
JSON配列のみ出力。コードブロック記法禁止。最大5件。

[{"action_type":"rewrite","priority":"high","title":"松山ホームページ制作のサービスページを2,000文字以上にリライトしてください","reason":"当該キーワードの順位が19位→34位に下落しており、競合サイトのH2が10項目以上でコンテンツ量に差が出ています","target_keyword":"松山 ホームページ制作","target_url":"","quantity":1,"unit":"ページ","expected_effect":"対象キーワードの順位改善と検索表示回数の増加が期待できます"}]
PROMPT;

        try {
            $response = $this->ai->call_gemini_api( $prompt, [
                'temperature'     => 0.3,
                'maxOutputTokens' => 8192,
            ] );

            $parsed = $this->parse_ai_json( $response );
            if ( is_array( $parsed ) && ! empty( $parsed ) ) {
                $deduped = $this->dedupe_and_cap_actions( $parsed );
                $this->log( "AI parse success: raw=" . count( $parsed ) . " deduped=" . count( $deduped ) );
                return $deduped;
            }

            $this->log( "AI JSON parse failed. Raw: " . substr( $response, 0, 500 ) );

        } catch ( \Throwable $e ) {
            $this->log( "call_ai_for_actions ERROR: " . $e->getMessage() );
        }

        return [];
    }

    /**
     * AIが生成した重複・過剰アクションを除去
     *
     * ルール:
     * - (action_type, target_keyword) の組合せで重複排除
     * - 同じキーの場合、priority が高い方を優先、次いで quantity が大きい方を優先
     * - 全体を $max 件までに切り詰める
     */
    private function dedupe_and_cap_actions( array $actions, int $max = 5 ): array {
        $prio_rank = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
        $seen = [];

        foreach ( $actions as $a ) {
            if ( ! is_array( $a ) ) { continue; }
            $type = (string) ( $a['action_type'] ?? '' );
            $kw   = trim( (string) ( $a['target_keyword'] ?? '' ) );
            if ( $type === '' ) { continue; }
            $key = $type . '|' . $kw;

            if ( ! isset( $seen[ $key ] ) ) {
                $seen[ $key ] = $a;
                continue;
            }

            $existing = $seen[ $key ];
            $new_prio = $prio_rank[ $a['priority'] ?? 'medium' ] ?? 1;
            $cur_prio = $prio_rank[ $existing['priority'] ?? 'medium' ] ?? 1;

            if ( $new_prio < $cur_prio ) {
                $seen[ $key ] = $a;
            } elseif ( $new_prio === $cur_prio
                    && (int) ( $a['quantity'] ?? 0 ) > (int) ( $existing['quantity'] ?? 0 ) ) {
                $seen[ $key ] = $a;
            }
        }

        // priority 順に並べてから上限適用
        $result = array_values( $seen );
        usort( $result, function ( $a, $b ) use ( $prio_rank ) {
            $pa = $prio_rank[ $a['priority'] ?? 'medium' ] ?? 1;
            $pb = $prio_rank[ $b['priority'] ?? 'medium' ] ?? 1;
            return $pa <=> $pb;
        } );

        return array_slice( $result, 0, $max );
    }

    private function get_fallback_actions(): array {
        return [
            [
                'action_type'     => 'article_create',
                'priority'        => 'high',
                'title'           => 'コラム記事を2本追加してください',
                'reason'          => 'コンテンツ量を増やすことで、検索でヒットするキーワードが増えます',
                'target_keyword'  => '',
                'target_url'      => '',
                'quantity'        => 2,
                'unit'            => '本',
                'expected_effect' => '2〜4週間で新しいキーワードからの流入が見込めます',
                'guide_text'      => '',
                'comparison'      => [ 'self' => '', 'competitor_avg' => '' ],
            ],
            [
                'action_type'     => 'rewrite',
                'priority'        => 'high',
                'title'           => '主要ページを1ページリライトしてください',
                'reason'          => '既存ページの情報量を増やすことで、順位改善が期待できます',
                'target_keyword'  => '',
                'target_url'      => '',
                'quantity'        => 1,
                'unit'            => 'ページ',
                'expected_effect' => '1〜3週間で対象キーワードの順位が改善します',
                'guide_text'      => '',
                'comparison'      => [ 'self' => '', 'competitor_avg' => '' ],
            ],
            [
                'action_type'     => 'meo_post',
                'priority'        => 'medium',
                'title'           => 'Googleビジネスプロフィールに1件投稿してください',
                'reason'          => '定期的な投稿でマップ検索の表示回数が増えます',
                'target_keyword'  => '',
                'target_url'      => '',
                'quantity'        => 1,
                'unit'            => '回',
                'expected_effect' => 'マップ検索での露出が増加します',
                'guide_text'      => '',
                'comparison'      => [ 'self' => '', 'competitor_avg' => '' ],
            ],
            [
                'action_type'     => 'internal_link',
                'priority'        => 'medium',
                'title'           => '内部リンクを3箇所追加してください',
                'reason'          => 'ページ同士をつなぐことで、サイト全体の評価が上がります',
                'target_keyword'  => '',
                'target_url'      => '',
                'quantity'        => 3,
                'unit'            => '箇所',
                'expected_effect' => 'サイト全体の評価向上と回遊率改善が見込めます',
                'guide_text'      => '',
                'comparison'      => [ 'self' => '', 'competitor_avg' => '' ],
            ],
            [
                'action_type'     => 'meta_fix',
                'priority'        => 'low',
                'title'           => 'ページタイトルと説明文を見直してください',
                'reason'          => '検索結果でのクリック率を上げるために重要です',
                'target_keyword'  => '',
                'target_url'      => '',
                'quantity'        => 1,
                'unit'            => 'ページ',
                'expected_effect' => 'クリック率が改善し、アクセスが増加します',
                'guide_text'      => '',
                'comparison'      => [ 'self' => '', 'competitor_avg' => '' ],
            ],
        ];
    }

    private function format_actions( array $rows ): array {
        $formatted = [];
        foreach ( $rows as $row ) {
            $formatted[] = [
                'id'                     => (int) $row['id'],
                'action_type'            => $row['action_type'],
                'priority'               => $row['priority'],
                'title'                  => $row['title'],
                'reason'                 => $row['reason'],
                'target_keyword'         => $row['target_keyword'] ?? '',
                'target_url'             => $row['target_url'] ?? '',
                'quantity'               => (int)( $row['quantity'] ?? 0 ),
                'unit'                   => $row['unit'] ?? '',
                'expected_effect'        => $row['expected_effect'] ?? '',
                'comparison_self'        => $row['comparison_self'] ?? '',
                'comparison_competitor'  => $row['comparison_competitor'] ?? '',
                'guide_text'             => $row['guide_text'] ?? '',
                'is_auto_executable'     => (bool)(int) $row['is_auto_executable'],
                'status'                 => $row['status'],
                'completed_at'           => $row['completed_at'],
            ];
        }
        return $formatted;
    }

    /* ==================================================================
       C. 順位変動アラート
       ================================================================== */

    private function build_rank_alerts( int $user_id ): array {
        global $wpdb;
        $res_table = $wpdb->prefix . 'gcrev_rank_results';
        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';

        $dates = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT fetch_date FROM {$res_table}
             WHERE user_id = %d ORDER BY fetch_date DESC LIMIT 2",
            $user_id
        ) );

        if ( count( $dates ) < 2 ) { return []; }

        $latest = $dates[0];
        $prev   = $dates[1];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT k.keyword, k.search_volume,
                    curr.rank_group AS curr_rank, curr.is_ranked AS curr_ranked, curr.found_url AS curr_url,
                    prev.rank_group AS prev_rank, prev.is_ranked AS prev_ranked
             FROM {$kw_table} k
             LEFT JOIN {$res_table} curr
                 ON curr.keyword_id = k.id AND curr.user_id = k.user_id
                 AND curr.fetch_date = %s AND curr.device = 'desktop'
             LEFT JOIN {$res_table} prev
                 ON prev.keyword_id = k.id AND prev.user_id = k.user_id
                 AND prev.fetch_date = %s AND prev.device = 'desktop'
             WHERE k.user_id = %d AND k.enabled = 1
             ORDER BY k.sort_order ASC, k.id ASC",
            $latest, $prev, $user_id
        ), ARRAY_A );

        $alerts = [];
        foreach ( $rows as $r ) {
            $curr_ranked = (int)( $r['curr_ranked'] ?? 0 );
            $prev_ranked = (int)( $r['prev_ranked'] ?? 0 );
            $curr_rank   = (int)( $r['curr_rank'] ?? 0 );
            $prev_rank   = (int)( $r['prev_rank'] ?? 0 );

            // ラベル
            $curr_label = $curr_ranked ? $curr_rank . '位' : '圏外';
            $prev_label = $prev_ranked ? $prev_rank . '位' : '圏外';

            // 変動量と深刻度
            if ( $curr_ranked && ! $prev_ranked ) {
                $change   = 'new';
                $severity = 'good';
            } elseif ( ! $curr_ranked && $prev_ranked ) {
                $change   = 'dropped';
                $severity = 'danger';
            } elseif ( $curr_ranked && $prev_ranked ) {
                $diff = $prev_rank - $curr_rank;
                if ( $diff >= 10 )      { $change = $diff; $severity = 'good'; }
                elseif ( $diff >= 3 )   { $change = $diff; $severity = 'good'; }
                elseif ( $diff <= -10 ) { $change = $diff; $severity = 'danger'; }
                elseif ( $diff <= -3 )  { $change = $diff; $severity = 'warning'; }
                else                    { $change = $diff; $severity = 'stable'; }
            } else {
                $change   = 0;
                $severity = 'stable';
            }

            $alerts[] = [
                'keyword'         => $r['keyword'],
                'search_volume'   => (int)( $r['search_volume'] ?? 0 ),
                'prev_rank'       => $prev_rank,
                'curr_rank'       => $curr_rank,
                'prev_rank_label' => $prev_label,
                'curr_rank_label' => $curr_label,
                'change'          => $change,
                'severity'        => $severity,
                'page_url'        => $r['curr_url'] ?? '',
            ];
        }

        // 深刻度でソート: danger > warning > good > stable
        $severity_order = [ 'danger' => 0, 'warning' => 1, 'good' => 2, 'stable' => 3 ];
        usort( $alerts, function ( $a, $b ) use ( $severity_order ) {
            return ( $severity_order[ $a['severity'] ] ?? 9 ) <=> ( $severity_order[ $b['severity'] ] ?? 9 );
        } );

        return $alerts;
    }

    /* ==================================================================
       D. 進捗トラッカー
       ================================================================== */

    private function build_progress( int $user_id, string $action_month ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_execution_actions';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT action_type, status FROM {$table} WHERE user_id = %d AND action_month = %s",
            $user_id, $action_month
        ), ARRAY_A );

        $total     = count( $rows );
        $completed = 0;
        $by_type   = [];

        foreach ( $rows as $r ) {
            $type = $r['action_type'];
            if ( ! isset( $by_type[ $type ] ) ) {
                $by_type[ $type ] = [ 'total' => 0, 'completed' => 0 ];
            }
            $by_type[ $type ]['total']++;
            if ( $r['status'] === 'completed' ) {
                $completed++;
                $by_type[ $type ]['completed']++;
            }
        }

        // タイプラベル
        $type_labels = [
            'article_create' => '記事作成',
            'rewrite'        => 'リライト',
            'internal_link'  => '内部リンク',
            'meo_post'       => 'MEO投稿',
            'meta_fix'       => 'メタ情報修正',
            'page_speed'     => '表示速度改善',
        ];

        $by_type_formatted = [];
        foreach ( $by_type as $type => $counts ) {
            $by_type_formatted[] = [
                'type'      => $type,
                'label'     => $type_labels[ $type ] ?? $type,
                'total'     => $counts['total'],
                'completed' => $counts['completed'],
            ];
        }

        return [
            'overall' => [
                'total'     => $total,
                'completed' => $completed,
                'rate'      => $total > 0 ? round( $completed / $total, 2 ) : 0,
            ],
            'by_type' => $by_type_formatted,
        ];
    }

    /* ==================================================================
       E. 原因分析
       ================================================================== */

    /**
     * キャッシュ済み原因分析のみ返す（なければ null）
     */
    private function get_cached_root_cause( int $user_id ): ?array {
        $cached = get_transient( "gcrev_exec_rootcause_{$user_id}" );
        return is_array( $cached ) ? $cached : null;
    }

    private function get_or_generate_root_cause( int $user_id, array $rank_alerts ): array {
        $cache_key = "gcrev_exec_rootcause_{$user_id}";
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) { return $cached; }

        // 順位下落がない場合はスキップ
        $down_alerts = array_filter( $rank_alerts, fn( $a ) => in_array( $a['severity'], [ 'danger', 'warning' ] ) );
        if ( empty( $down_alerts ) ) {
            $result = [
                'bullets' => [
                    [ 'title' => '大きな順位変動はありません', 'detail' => '現在の順位は安定しています。引き続きコンテンツの充実を続けましょう。' ],
                ],
                'generated_at' => current_time( 'mysql' ),
            ];
            set_transient( $cache_key, $result, self::ANALYSIS_CACHE_TTL );
            return $result;
        }

        // AI分析
        $alert_summary = '';
        foreach ( array_slice( $down_alerts, 0, 5 ) as $a ) {
            $alert_summary .= sprintf(
                "- %s: %s → %s\n",
                $a['keyword'], $a['prev_rank_label'], $a['curr_rank_label']
            );
        }

        $prompt = <<<PROMPT
順位下落の原因を3つ、JSON配列で出力せよ。コードブロック記法(```)禁止。

{$alert_summary}

[{"title":"原因(15字以内)","detail":"説明(30字以内)"}]
PROMPT;

        try {
            $response = $this->ai->call_gemini_api( $prompt, [
                'temperature'     => 0.3,
                'maxOutputTokens' => 2048,
            ] );

            $parsed = $this->parse_ai_json( $response );

            if ( is_array( $parsed ) && ! empty( $parsed ) ) {
                $result = [
                    'bullets'      => $parsed,
                    'generated_at' => current_time( 'mysql' ),
                ];
                set_transient( $cache_key, $result, self::ANALYSIS_CACHE_TTL );
                return $result;
            }
        } catch ( \Throwable $e ) {
            $this->log( "root_cause AI ERROR: " . $e->getMessage() );
        }

        // フォールバック
        $result = [
            'bullets' => [
                [ 'title' => '分析を実行できませんでした', 'detail' => 'しばらく時間をおいて再度お試しください。' ],
            ],
            'generated_at' => current_time( 'mysql' ),
        ];
        set_transient( $cache_key, $result, 3600 );
        return $result;
    }

    /* ==================================================================
       アクション操作
       ================================================================== */

    public function get_action( int $user_id, int $action_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_execution_actions';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
            $action_id, $user_id
        ), ARRAY_A );
        return $row ?: null;
    }

    public function update_action_status( int $action_id, string $status ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_execution_actions';

        $data = [
            'status'     => $status,
            'updated_at' => current_time( 'mysql' ),
        ];
        if ( $status === 'completed' ) {
            $data['completed_at'] = current_time( 'mysql' );
        }

        $result = $wpdb->update( $table, $data, [ 'id' => $action_id ], [ '%s', '%s', '%s' ], [ '%d' ] );
        return $result !== false;
    }

    public function complete_action( int $user_id, int $action_id ): array {
        $action = $this->get_action( $user_id, $action_id );
        if ( ! $action ) {
            return [ 'success' => false, 'error' => 'アクションが見つかりません。' ];
        }

        $this->update_action_status( $action_id, 'completed' );
        return [ 'success' => true, 'message' => '完了しました。' ];
    }

    public function skip_action( int $user_id, int $action_id ): array {
        $action = $this->get_action( $user_id, $action_id );
        if ( ! $action ) {
            return [ 'success' => false, 'error' => 'アクションが見つかりません。' ];
        }

        $this->update_action_status( $action_id, 'skipped' );
        return [ 'success' => true, 'message' => 'スキップしました。' ];
    }

    public function revert_action( int $user_id, int $action_id ): array {
        $action = $this->get_action( $user_id, $action_id );
        if ( ! $action ) {
            return [ 'success' => false, 'error' => 'アクションが見つかりません。' ];
        }
        if ( $action['status'] === 'pending' ) {
            return [ 'success' => false, 'error' => 'このアクションは未着手です。' ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_execution_actions';
        $wpdb->update( $table, [
            'status'       => 'pending',
            'completed_at' => null,
            'updated_at'   => current_time( 'mysql' ),
        ], [ 'id' => $action_id ], [ '%s', '%s', '%s' ], [ '%d' ] );

        return [ 'success' => true, 'message' => '未着手に戻しました。' ];
    }

    public function refresh_actions( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_execution_actions';
        $tz    = wp_timezone();
        $now   = new \DateTimeImmutable( 'now', $tz );
        $action_month = $now->format( 'Y-m' );

        // pending のみ削除（completed/skipped は保持）
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE user_id = %d AND action_month = %s AND status = 'pending'",
            $user_id, $action_month
        ) );

        // Transient もクリア
        delete_transient( "gcrev_exec_rootcause_{$user_id}" );
        delete_transient( "gcrev_exec_score_{$user_id}" );

        // 再生成
        $context = $this->collect_context_for_ai( $user_id );
        $new_actions = $this->call_ai_for_actions( $context, $user_id );

        if ( empty( $new_actions ) ) {
            $new_actions = $this->get_fallback_actions();
        }

        $now_str = current_time( 'mysql' );
        $sort    = 100;

        // テーブル存在チェック
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( ! $table_exists ) {
            $this->log( "TABLE NOT FOUND: {$table} — running dbDelta" );
            gcrev_execution_actions_create_table();
            $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
            $this->log( "After dbDelta: table_exists=" . ( $table_exists ? 'YES' : 'NO' ) );
        }

        $inserted = 0;
        foreach ( $new_actions as $action ) {
            $result = $wpdb->insert( $table, [
                'user_id'               => $user_id,
                'action_month'            => $action_month,
                'action_type'           => sanitize_text_field( $action['action_type'] ?? 'article_create' ),
                'priority'              => sanitize_text_field( $action['priority'] ?? 'medium' ),
                'title'                 => sanitize_text_field( $action['title'] ?? '' ),
                'reason'                => sanitize_text_field( $action['reason'] ?? '' ),
                'target_keyword'        => sanitize_text_field( $action['target_keyword'] ?? '' ),
                'target_url'            => esc_url_raw( $action['target_url'] ?? '' ),
                'quantity'              => absint( $action['quantity'] ?? 0 ),
                'unit'                  => sanitize_text_field( $action['unit'] ?? '' ),
                'expected_effect'       => sanitize_text_field( $action['expected_effect'] ?? '' ),
                'comparison_self'       => sanitize_text_field( $action['comparison']['self'] ?? '' ),
                'comparison_competitor' => sanitize_text_field( $action['comparison']['competitor_avg'] ?? '' ),
                'guide_text'            => wp_kses_post( $action['guide_text'] ?? '' ),
                'is_auto_executable'    => in_array( $action['action_type'], [ 'article_create', 'meo_post' ], true ) ? 1 : 0,
                'status'                => 'pending',
                'sort_order'            => $sort++,
                'created_at'            => $now_str,
                'updated_at'            => $now_str,
            ] );
            if ( $result === false ) {
                $this->log( "INSERT FAILED: " . $wpdb->last_error );
            } else {
                $inserted++;
            }
        }
        $this->log( "refresh_actions: inserted={$inserted}/" . count( $new_actions ) . " actions" );

        // フルダッシュボードデータを返す
        return $this->get_dashboard( $user_id );
    }

    /* ==================================================================
       ユーティリティ
       ================================================================== */

    /**
     * AI応答からJSON配列をパース（切り詰め対応）
     */
    private function parse_ai_json( string $raw ): ?array {
        $text = trim( $raw );

        // コードブロック記法を除去
        $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
        $text = preg_replace( '/\s*```\s*$/', '', $text );
        $text = trim( $text );

        // 1. そのままパース
        $parsed = json_decode( $text, true );
        if ( is_array( $parsed ) && ! empty( $parsed ) ) {
            return $parsed;
        }

        // 2. 切り詰められたJSONの修復: 最後の完全なオブジェクト `}` までで閉じる
        //    パターン: [..., {...}, {...}, {途中で切れ ← ここを除去して ] で閉じる
        $last_complete = strrpos( $text, '}' );
        if ( $last_complete !== false ) {
            $repaired = substr( $text, 0, $last_complete + 1 );
            // 末尾に ] がなければ追加
            $repaired = rtrim( $repaired, " ,\n\r\t" );
            if ( substr( $repaired, -1 ) !== ']' ) {
                $repaired .= ']';
            }
            $parsed = json_decode( $repaired, true );
            if ( is_array( $parsed ) && ! empty( $parsed ) ) {
                $this->log( "JSON repaired successfully (" . count( $parsed ) . " items)" );
                return $parsed;
            }
        }

        return null;
    }

    private function log( string $msg ): void {
        file_put_contents(
            self::DEBUG_LOG,
            date( 'Y-m-d H:i:s' ) . " {$msg}\n",
            FILE_APPEND
        );
    }
}
