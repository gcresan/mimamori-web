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

            // クライアント情報（user_meta のみ）
            $client_info = get_user_meta( $user_id, 'gcrev_client_info', true );
            if ( is_array( $client_info ) ) {
                $context['business'] = [
                    'industry' => $client_info['industry'] ?? '',
                    'area'     => $client_info['area'] ?? '',
                    'site_url' => $client_info['site_url'] ?? '',
                ];
            }

        } catch ( \Throwable $e ) {
            $this->log( "collect_context_for_ai ERROR: " . $e->getMessage() );
        }

        return $context;
    }

    private function call_ai_for_actions( array $context, int $user_id ): array {
        $business  = $context['business'] ?? [];
        $industry  = $business['industry'] ?? '（不明）';
        $area      = $business['area'] ?? '';
        $site_url  = $business['site_url'] ?? '';

        // 順位変動を簡潔にまとめる
        $rank_summary = '';
        foreach ( array_slice( $context['rank_alerts'] ?? [], 0, 10 ) as $alert ) {
            $rank_summary .= sprintf(
                "- %s: %s → %s (%s)\n",
                $alert['keyword'], $alert['prev_rank_label'], $alert['curr_rank_label'], $alert['severity']
            );
        }

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

        // 記事数
        $article_count = count( $context['recent_articles'] ?? [] );

        $prompt = <<<PROMPT
SEOコンサルとして、以下データからアクション5個をJSON配列で出力せよ。

業種:{$industry} 地域:{$area} URL:{$site_url}

順位変動:
{$rank_summary}
GA4(30日):{$ga4_summary}
記事:60日で{$article_count}本

ルール:
- action_type: article_create/rewrite/internal_link/meo_post/meta_fix/page_speed
- priority: high(最大2個)/medium/low
- reason: 1文30字以内
- expected_effect: 1文20字以内
- guide_text: 空文字（不要）
- comparison: {self:"値",competitor_avg:"値"}
- JSON配列のみ出力。コードブロック記法(```)禁止

[{"action_type":"article_create","priority":"high","title":"記事を2本追加","reason":"競合月4本、御社月1本","target_keyword":"","target_url":"","quantity":2,"unit":"本","expected_effect":"検索表示増加","guide_text":"","comparison":{"self":"月1本","competitor_avg":"月4本"}}]
PROMPT;

        try {
            $response = $this->ai->call_gemini_api( $prompt, [
                'temperature'     => 0.3,
                'maxOutputTokens' => 8192,
            ] );

            $parsed = $this->parse_ai_json( $response );
            if ( is_array( $parsed ) && ! empty( $parsed ) ) {
                $this->log( "AI parse success: " . count( $parsed ) . " actions" );
                return $parsed;
            }

            $this->log( "AI JSON parse failed. Raw: " . substr( $response, 0, 500 ) );

        } catch ( \Throwable $e ) {
            $this->log( "call_ai_for_actions ERROR: " . $e->getMessage() );
        }

        return [];
    }

    private function get_fallback_actions(): array {
        return [
            [
                'action_type'     => 'article_create',
                'priority'        => 'high',
                'title'           => 'ブログ記事を2本追加する',
                'reason'          => '定期的なコンテンツ更新はSEOの基本です。まずは記事を追加しましょう。',
                'target_keyword'  => '',
                'target_url'      => '',
                'quantity'        => 2,
                'unit'            => '本',
                'expected_effect' => '新しいキーワードでの検索表示が期待できます。',
                'guide_text'      => '',
                'comparison'      => [ 'self' => '', 'competitor_avg' => '' ],
            ],
            [
                'action_type'     => 'meo_post',
                'priority'        => 'medium',
                'title'           => 'Googleビジネスプロフィールに投稿する',
                'reason'          => '定期的な投稿はマップ検索での表示に効果があります。',
                'target_keyword'  => '',
                'target_url'      => '',
                'quantity'        => 1,
                'unit'            => '回',
                'expected_effect' => 'マップ検索での露出が増加します。',
                'guide_text'      => '',
                'comparison'      => [ 'self' => '', 'competitor_avg' => '' ],
            ],
            [
                'action_type'     => 'rewrite',
                'priority'        => 'medium',
                'title'           => '既存ページを1ページリライトする',
                'reason'          => 'コンテンツの充実は順位改善の基本です。',
                'target_keyword'  => '',
                'target_url'      => '',
                'quantity'        => 1,
                'unit'            => 'ページ',
                'expected_effect' => 'ページの評価が上がり、順位改善が期待できます。',
                'guide_text'      => '<ol><li>対象ページのアクセスデータを確認する</li><li>競合上位ページの見出し構成を調べる</li><li>不足しているトピックを追加する</li><li>文字数を1.5倍以上に増やす</li></ol>',
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
