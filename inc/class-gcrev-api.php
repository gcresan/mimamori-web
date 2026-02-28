<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

/**
 * Composer autoload — 環境に応じたパスで読み込む
 *
 * 優先順:
 *   1. wp-config.php の GCREV_VENDOR_PATH 定数
 *   2. テーマ親ディレクトリの vendor/ （開発環境用フォールバック）
 *
 * 見つからない場合は error_log に記録し wp_die() で停止する。
 */
$gcrev_autoload_path = '';

if ( defined( 'GCREV_VENDOR_PATH' ) && GCREV_VENDOR_PATH !== '' ) {
    $gcrev_autoload_path = rtrim( GCREV_VENDOR_PATH, '/' ) . '/autoload.php';
} else {
    // フォールバック: テーマの2階層上に vendor/ がある想定
    $gcrev_autoload_path = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
}

if ( ! file_exists( $gcrev_autoload_path ) ) {
    $gcrev_err = sprintf(
        '[GCREV] vendor/autoload.php が見つかりません: %s — wp-config.php に define(\'GCREV_VENDOR_PATH\', \'/path/to/vendor\'); を追加してください。',
        $gcrev_autoload_path
    );
    error_log( $gcrev_err );
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        WP_CLI::error( $gcrev_err );
    }
    wp_die(
        esc_html( $gcrev_err ),
        'GCREV 設定エラー',
        [ 'response' => 500 ]
    );
}

require_once $gcrev_autoload_path;
unset( $gcrev_autoload_path, $gcrev_err );

use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\OrderBy;

use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\FilterExpressionList;
use Google\Analytics\Data\V1beta\StringFilter;

use Google\Client as GoogleClient;
use Google\Service\Webmasters as GoogleWebmasters;
use Google\Service\Webmasters\SearchAnalyticsQueryRequest;

class Gcrev_Insight_API {

    // Step1-2: utils / modules への委譲用プロパティ
    private Gcrev_Config      $config;
    private Gcrev_Date_Helper $dates;
    private Gcrev_AI_Client   $ai;
    private Gcrev_GA4_Fetcher $ga4;
    private Gcrev_GSC_Fetcher $gsc;

    // Step3: Report Repository / Generator
    private Gcrev_Report_Repository $repo;
    private Gcrev_Report_Generator  $generator;

    // Step4: Highlights
    private Gcrev_Highlights $highlights_mod;

    // Step4.5: Monthly Report Service
    private Gcrev_Monthly_Report_Service $report_service;

    // Step5: Dashboard Service（ここに追加）
    private Gcrev_Dashboard_Service $dashboard_service;

    private string $service_account_path = '';

    // ===== キャッシュ設定 =====
    private const DASHBOARD_CACHE_TTL  = 86400;   // 24h
    private const PREFETCH_SLEEP_US    = 100000;  // 0.1s
    public  const PREFETCH_CHUNK_LIMIT = 5;

    private int $dashboard_cache_ttl = self::DASHBOARD_CACHE_TTL;

    /** @var array<string, array> リクエスト内 get_effective_cv_monthly キャッシュ */
    private array $effective_cv_cache = [];

    /** @var array<string, bool> テーブル存在チェックキャッシュ */
    private array $table_exists_cache = [];

    // ===== レポート・セクション生成設定 =====
    // レポートキャッシュTTL（秒）
    private const REPORT_CACHE_TTL     = 3600;

    public function __construct(bool $register_routes = true) {
        // Step1-2: utils / modules インスタンス化（Cron等 register_routes=false でも必要）
        $this->config = new Gcrev_Config();
        $this->dates  = new Gcrev_Date_Helper();
        $this->service_account_path = $this->config->get_service_account_path();
        $this->ai     = new Gcrev_AI_Client($this->config);
        $this->ga4    = new Gcrev_GA4_Fetcher($this->config);
        $this->gsc    = new Gcrev_GSC_Fetcher($this->config);

        // Step3: Report Repository / Generator
        $this->repo      = new Gcrev_Report_Repository($this->config);
        $this->generator = new Gcrev_Report_Generator($this->config, $this->ai, $this->ga4, $this->repo);

        // Step4: Highlights（循環依存解消済み - Generator不要）
        $this->highlights_mod = new Gcrev_Highlights($this->config);

        // Step4.5: Monthly Report Service
        $this->report_service = new Gcrev_Monthly_Report_Service($this->highlights_mod);

        // Step5（これを追加）
        $this->dashboard_service = new Gcrev_Dashboard_Service($this->report_service);

        if ($register_routes) {
            add_action('rest_api_init', [ $this, 'register_routes' ]);
        }
    }

    public function register_routes(): void {

        register_rest_route('gcrev_insights/v1', '/dashboard', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_dashboard_data' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        register_rest_route('gcrev_insights/v1', '/kpi', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_kpi_data' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        register_rest_route('gcrev_insights/v1', '/clear-cache', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'clear_all_cache' ],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        // 自分のキャッシュのみ削除（ログインユーザー用）
        register_rest_route('gcrev_insights/v1', '/clear-my-cache', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'clear_my_cache' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        register_rest_route('gcrev_insights/v1', '/save-client-info', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_client_info' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        // 生成回数取得エンドポイント
        register_rest_route('gcrev_insights/v1', '/report/generation-count', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_generation_count' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        // レポート生成（Multi-pass セクション単位生成に変更）
        register_rest_route('gcrev_insights/v1', '/generate-report', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_report' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // レポート生成回数リセット（管理者のみ）
        register_rest_route('gcrev_insights/v1', '/report/reset-generation-count', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'reset_generation_count' ],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
        // === 新規追加：レポート履歴関連のREST API ===
        register_rest_route('gcrev_insights/v1', '/report/current', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_current_report' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        register_rest_route('gcrev_insights/v1', '/report/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_report_history' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        register_rest_route('gcrev_insights/v1', '/report/(?P<report_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_report_by_id' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // ===== v2ダッシュボード用KPI取得 =====
        register_rest_route('gcrev/v1', '/dashboard/kpi', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_dashboard_kpi' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'period' => [
                    'required'          => false,
                    'default'           => 'prev-month',
                    'validate_callback' => function($param) {
                        return in_array($param, ['last30', 'last90', 'last180', 'last365', 'prev-month', 'prev-prev-month']);
                    }
                ],
                // [CACHE_FIRST] last180/last365 用キャッシュ優先フラグ
                'cache_first' => [
                    'required'          => false,
                    'default'           => '0',
                    'validate_callback' => function($param) {
                        return in_array($param, ['0', '1']);
                    }
                ]
            ]
        ]);

        // ===== 流入元分析用エンドポイント =====
        register_rest_route('gcrev/v1', '/analysis/source', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_source_analysis' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'period' => [
                    'required'          => false,
                    'default'           => 'previousMonth',
                    'validate_callback' => function($param) {
                        return in_array($param, ['last30', 'last90', 'last180', 'last365', 'previousMonth', 'twoMonthsAgo', 'prev-month', 'prev-prev-month']);
                    }
                ]
            ]
        ]);
        // ===== 地域別分析用エンドポイント =====
        register_rest_route('gcrev/v1', '/analysis/region', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_region_analysis' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'period' => [
                    'required'          => false,
                    'default'           => 'prev-month',
                    'validate_callback' => function($param) {
                        return in_array($param, ['last30', 'last90', 'last180', 'last365', 'prev-month', 'prev-prev-month']);
                    }
                ]
            ]
        ]);

        // ===== 地域別 月別推移エンドポイント =====
        register_rest_route('gcrev/v1', '/analysis/region-trend', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_region_trend' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'area' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'months' => [
                    'required'          => false,
                    'default'           => 12,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && (int)$param >= 1 && (int)$param <= 24;
                    },
                    'sanitize_callback' => 'absint',
                ],
                'metric' => [
                    'required'          => false,
                    'default'           => 'sessions',
                    'validate_callback' => function($param) {
                        return in_array($param, ['sessions', 'screenPageViews', 'totalUsers', 'conversions']);
                    },
                ],
            ],
        ]);

        register_rest_route('gcrev/v1', '/analysis/page', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_page_analysis' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'period' => [
                    'required'          => false,
                    'default'           => 'prev-month',
                    'validate_callback' => function($param) {
                        return in_array($param, ['last30', 'last90', 'last180', 'last365', 'prev-month', 'prev-prev-month']);
                    }
                ]
            ]
        ]);

        // ===== キーワード分析用エンドポイント =====
        register_rest_route('gcrev/v1', '/analysis/keywords', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_keyword_analysis' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'period' => [
                    'required'          => false,
                    'default'           => 'prev-month',
                    'validate_callback' => function($param) {
                        return in_array($param, ['last30', 'last90', 'last180', 'last365', 'prev-month', 'prev-prev-month']);
                    }
                ]
            ]
        ]);

        // ===== MEOダッシュボード用エンドポイント =====
        register_rest_route('gcrev/v1', '/meo/dashboard', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_meo_dashboard' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'period' => [
                    'required'          => false,
                    'default'           => 'prev-month',
                    'validate_callback' => function($param) {
                        return in_array($param, ['last30', 'prev-month']);
                    }
                ]
            ]
        ]);

        // ===== MEOロケーション登録エンドポイント =====
        register_rest_route('gcrev/v1', '/meo/location', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_meo_location' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // ===== MEOロケーションID手動設定エンドポイント =====
        register_rest_route('gcrev/v1', '/meo/location-id', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_set_meo_location_id' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // ===== GBPロケーション一覧取得エンドポイント =====
        register_rest_route('gcrev/v1', '/meo/gbp-locations', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_gbp_locations' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // ===== GBPロケーション選択・保存エンドポイント =====
        register_rest_route('gcrev/v1', '/meo/select-location', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_select_gbp_location' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // ===== v2ダッシュボード用レポート生成 =====
        register_rest_route('gcrev/v1', '/report/generate-manual', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_generate_report_manual' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'year' => [
                    'required'          => false,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param >= 2020 && $param <= 2100;
                    }
                ],
                'month' => [
                    'required'          => false,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param >= 1 && $param <= 12;
                    }
                ]
            ]
        ]);

        // ===== 前々月データ存在チェック =====
        register_rest_route('gcrev/v1', '/report/check-prev2-data', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_check_prev2_data' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // ===== 実質CV REST API =====
        register_rest_route('gcrev_insights/v1', '/actual-cv', [
            ['methods'=>'GET',  'callback'=>[$this,'rest_get_actual_cv'], 'permission_callback'=>[$this->config,'check_permission']],
            ['methods'=>'POST', 'callback'=>[$this,'rest_save_actual_cv'], 'permission_callback'=>[$this->config,'check_permission']],
        ]);
        register_rest_route('gcrev_insights/v1', '/actual-cv/users', [
            'methods'=>'GET', 'callback'=>[$this,'rest_get_actual_cv_users'],
            'permission_callback'=>function(){ return current_user_can('manage_options'); },
        ]);
        register_rest_route('gcrev_insights/v1', '/actual-cv/routes', [
            ['methods'=>'GET',  'callback'=>[$this,'rest_get_cv_routes'], 'permission_callback'=>[$this->config,'check_permission']],
            ['methods'=>'POST', 'callback'=>[$this,'rest_save_cv_routes'], 'permission_callback'=>[$this->config,'check_permission']],
        ]);
        register_rest_route('gcrev_insights/v1', '/ga4-key-events', [
            'methods'=>'GET', 'callback'=>[$this,'rest_get_ga4_key_events'], 'permission_callback'=>[$this->config,'check_permission'],
        ]);

        // ===== CV分析ページ用エンドポイント =====
        register_rest_route('gcrev/v1', '/analysis/cv', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_cv_analysis' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'period' => [
                    'required'          => false,
                    'default'           => 'prev-month',
                    'validate_callback' => function($param) {
                        return in_array($param, ['last30', 'last90', 'last180', 'last365', 'prev-month', 'prev-prev-month']);
                    }
                ]
            ]
        ]);

        // ===== ダッシュボードKPIトレンド用エンドポイント =====
        register_rest_route('gcrev/v1', '/dashboard/trends', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_metric_trend' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'metric' => [
                    'required'          => true,
                    'validate_callback' => function($param) {
                        return in_array($param, ['sessions', 'cv', 'meo']);
                    }
                ]
            ]
        ]);

        // ===== ダッシュボードドリルダウン用エンドポイント =====
        register_rest_route('gcrev/v1', '/dashboard/drilldown', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_dashboard_drilldown' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'month' => [
                    'required'          => true,
                    'validate_callback' => function($param) {
                        return preg_match('/^\d{4}-\d{2}$/', $param);
                    }
                ],
                'type'  => [
                    'required'          => true,
                    'validate_callback' => function($param) {
                        return in_array($param, ['region', 'page', 'source']);
                    }
                ],
                'metric' => [
                    'required' => false,
                    'default'  => 'sessions',
                ]
            ]
        ]);

        // ===== CVログ精査 REST API =====
        register_rest_route('gcrev/v1', '/cv-review', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_cv_review' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'month' => [
                    'required'          => true,
                    'validate_callback' => function($param) {
                        return preg_match('/^\d{4}-\d{2}$/', $param);
                    }
                ]
            ]
        ]);
        register_rest_route('gcrev/v1', '/cv-review/update', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_update_cv_review' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/cv-review/bulk-update', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_bulk_update_cv_review' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
    }

    // =========================================================
    // キャッシュ
    // =========================================================
    private function cache_key_dashboard(int $user_id, string $range): string {
        return "gcrev_dash_{$user_id}_{$range}";
    }

    private function dashboard_cache_get(int $user_id, string $range): ?array {
        $key = $this->cache_key_dashboard($user_id, $range);
        $cached = get_transient($key);
        if ($cached === false || $cached === null) return null;
        return is_array($cached) ? $cached : null;
    }

    private function dashboard_cache_set(int $user_id, string $range, array $data): void {
        $key = $this->cache_key_dashboard($user_id, $range);
        // [CACHE_FIRST] last90/last180/last365 は 48h キャッシュ（重いため長めに保持）
        $ttl = in_array($range, ['last90', 'last180', 'last365'], true)
            ? 172800   // 48h
            : $this->dashboard_cache_ttl;  // 24h（既存）
        set_transient($key, $data, $ttl);
    }

    /**
     * Effective CVトランジェントキャッシュを一括クリア
     * functions.php の gcrev_invalidate_user_cv_cache() に依存しない独立クリア
     */
    private function invalidate_effective_cv_transients(int $user_id): void {
        global $wpdb;
        $like         = $wpdb->esc_like("_transient_gcrev_effcv_{$user_id}_") . '%';
        $like_timeout = $wpdb->esc_like("_transient_timeout_gcrev_effcv_{$user_id}_") . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like, $like_timeout
        ));
        // インスタンスキャッシュもクリア
        $this->effective_cv_cache = [];
    }

    /**
     * レポートキャッシュキーの生成
     * period / site / user を含む
     */
    private function build_report_cache_key(int $user_id, array $client_info, array $prev_data, array $two_data): string {
        $site_hash = md5((string)($client_info['site_url'] ?? ''));
        $prev_period = $prev_data['current_period']['display'] ?? ($prev_data['current_period'] ?? 'unknown');
        $two_period  = $two_data['current_period']['display']  ?? ($two_data['current_period']  ?? 'unknown');
        
        // 出力モードをキャッシュキーに含める
        $output_mode = $client_info['output_mode'] ?? 'normal';

        $payload = wp_json_encode([
            'client_info' => $client_info,
            'prev_period' => $prev_period,
            'two_period'  => $two_period,
            'output_mode' => $output_mode,  // モードを追加
        ], JSON_UNESCAPED_UNICODE);

        return "gcrev_report_{$user_id}_{$output_mode}_{$site_hash}_" . md5($payload);
    }

    /**
     * 管理者専用：全キャッシュ削除
     */
    /**
     * 全 gcrev_ transient プレフィックス一覧（一元管理）
     */
    public static function get_all_cache_prefixes(): array {
        return [
            'gcrev_dash_',
            'gcrev_report_',
            'gcrev_effcv_',
            'gcrev_phone_tap_',
            'gcrev_ga4cv_',
            'gcrev_ga4kevt_daily_',
            'gcrev_ga4_kevt_list_',
            'gcrev_source_',
            'gcrev_region_',
            'gcrev_region_trend_',
            'gcrev_page_',
            'gcrev_keywords_',
            'gcrev_meo_',
            'gcrev_cv_analysis_',
            'gcrev_trend_',
            'gcrev_beginner_rw_',
            'gcrev_title_',
        ];
    }

    /**
     * REST: 全ユーザーのキャッシュ削除（管理者限定）
     */
    public function clear_all_cache(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $deleted = 0;

        foreach (self::get_all_cache_prefixes() as $prefix) {
            $deleted += (int)$wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like('_transient_' . $prefix) . '%',
                    $wpdb->esc_like('_transient_timeout_' . $prefix) . '%'
                )
            );
        }

        error_log("[GCREV] Cache cleared (ALL): {$deleted} transients deleted");

        return new WP_REST_Response([
            'success' => true,
            'deleted' => $deleted,
            'message' => "{$deleted}件のキャッシュを削除しました",
        ], 200);
    }

    /**
     * REST: 自分のキャッシュのみ削除（ログインユーザー用）
     */
    public function clear_my_cache(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $user_id = get_current_user_id();
        if (!$user_id) {
            return new WP_REST_Response(['error' => 'ログインが必要です'], 401);
        }

        $deleted = 0;

        // user_id を含むプレフィックスでフィルタ
        foreach (self::get_all_cache_prefixes() as $prefix) {
            $user_prefix = $prefix . $user_id . '_';
            $deleted += (int)$wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like('_transient_' . $user_prefix) . '%',
                    $wpdb->esc_like('_transient_timeout_' . $user_prefix) . '%'
                )
            );
        }

        error_log("[GCREV] Cache cleared (user={$user_id}): {$deleted} transients deleted");

        return new WP_REST_Response([
            'success' => true,
            'deleted' => $deleted,
            'message' => "{$deleted}件のキャッシュを削除しました",
        ], 200);
    }

    // =========================================================
    // REST: /dashboard
    // =========================================================
    public function get_dashboard_data(WP_REST_Request $request): WP_REST_Response {

        $user_id = get_current_user_id();
        $range   = (string)($request->get_param('range') ?: 'last30');

        $cached = $this->dashboard_cache_get($user_id, $range);
        if ($cached) {
            
            // キャッシュに流入元データがない場合は追加してキャッシュ更新
            if (!isset($cached['channels_summary'])) {
                try {
                    $config = $this->config->get_user_config($user_id);
                    $dates = $this->dates->get_date_range($range);
                    $comparison = $this->dates->get_comparison_range($dates['start'], $dates['end']);

                    $source_current = $this->ga4->fetch_source_data_from_ga4($config['ga4_id'], $dates['start'], $dates['end']);
                    $source_previous = $this->ga4->fetch_source_data_from_ga4($config['ga4_id'], $comparison['start'], $comparison['end']);
                    $source_analysis = $this->format_source_analysis_data($source_current, $source_previous);

                    $cached['channels_summary'] = $source_analysis['channels_summary'] ?? [];
                    $cached['sources_detail'] = $source_analysis['sources_detail'] ?? [];
                    $cached['channels_daily_series'] = $source_analysis['channels_daily_series'] ?? [];

                    // キャッシュに書き戻す（次回以降GA4 API不要）
                    $this->dashboard_cache_set($user_id, $range, $cached);
                } catch (\Exception $e) {
                    error_log("[GCREV] ERROR adding source data to cache: " . $e->getMessage());
                    $cached['channels_summary'] = [];
                    $cached['sources_detail'] = [];
                    $cached['channels_daily_series'] = [];
                }
            }
            
            return new WP_REST_Response([
                'success' => true,
                'data'    => $cached,
                'cached'  => true,
            ], 200);
        }

        try {
            $config = $this->config->get_user_config($user_id);

            $data   = $this->fetch_dashboard_data_internal($config, $range);

            $this->dashboard_cache_set($user_id, $range, $data);

            return new WP_REST_Response([
                'success' => true,
                'data'    => $data,
                'cached'  => false,
            ], 200);

        } catch (\Google\Service\Exception $e) {
            // Google API 固有のエラー（HTTP ステータス + 詳細付き）
            $status  = $e->getCode() ?: 500;
            $errors  = $e->getErrors();
            $reason  = ! empty( $errors[0]['reason'] ) ? $errors[0]['reason'] : 'unknown';
            $domain  = ! empty( $errors[0]['domain'] ) ? $errors[0]['domain'] : 'unknown';
            error_log( sprintf(
                '[GCREV] REST /dashboard Google API ERROR — user=%d, range=%s, HTTP %d, reason=%s, domain=%s, message=%s',
                $user_id, $range, $status, $reason, $domain, mb_substr( $e->getMessage(), 0, 500 )
            ) );

            // 管理者にはやや詳細、一般ユーザーには汎用文言
            if ( current_user_can( 'manage_options' ) ) {
                $front_msg = sprintf( 'Google API エラー (HTTP %d / %s): %s', $status, $reason, mb_substr( $e->getMessage(), 0, 200 ) );
            } else {
                $front_msg = 'データの取得に失敗しました。しばらくしてからお試しください。';
            }
            return new WP_REST_Response([
                'success' => false,
                'message' => $front_msg,
            ], 500);

        } catch (\Exception $e) {
            error_log( sprintf(
                '[GCREV] REST /dashboard ERROR — user=%d, range=%s, class=%s, message=%s',
                $user_id, $range, get_class( $e ), mb_substr( $e->getMessage(), 0, 500 )
            ) );

            if ( current_user_can( 'manage_options' ) ) {
                $front_msg = 'エラー: ' . mb_substr( $e->getMessage(), 0, 200 );
            } else {
                $front_msg = 'データの取得に失敗しました。しばらくしてからお試しください。';
            }
            return new WP_REST_Response([
                'success' => false,
                'message' => $front_msg,
            ], 500);
        }
    }

    // =========================================================
    // REST: /kpi
    // =========================================================
    public function get_kpi_data(WP_REST_Request $request): WP_REST_Response {

        $user_id = get_current_user_id();

        try {
            $config = $this->config->get_user_config($user_id);
            $dates  = $this->dates->get_kpi_dates();

            $trends = $this->ga4->fetch_kpi_trends($config, $dates);

            return new WP_REST_Response([
                'success' => true,
                'data'    => $trends,
            ], 200);

        } catch (\Exception $e) {
            error_log("[GCREV] REST /kpi ERROR (user_id={$user_id}): " . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================
    // Prefetch処理（Cronから呼ばれる）
    // =========================================================
    public function prefetch_chunk(int $offset, int $limit): void {

        // ─── 重複実行防止ロック（最初のチャンクのみ設定） ───
        if ( $offset === 0 ) {
            if ( get_transient( self::LOCK_PREFETCH ) ) {
                error_log( '[GCREV] prefetch_chunk: LOCKED, skipping duplicate run' );
                if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
                    $locked_id = Gcrev_Cron_Logger::start( 'prefetch', [ 'note' => 'locked' ] );
                    Gcrev_Cron_Logger::finish( $locked_id, 'locked' );
                }
                return;
            }
            set_transient( self::LOCK_PREFETCH, 1, self::LOCK_TTL );
            // Cron Logger: ジョブ開始
            if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
                $log_id = Gcrev_Cron_Logger::start( 'prefetch', [ 'chunk_limit' => $limit ] );
                set_transient( 'gcrev_current_prefetch_log_id', $log_id, self::LOCK_TTL );
            }
        }

        // Cron Logger: log_id を取得
        $log_id = class_exists( 'Gcrev_Cron_Logger' ) ? (int) get_transient( 'gcrev_current_prefetch_log_id' ) : 0;

        error_log("[GCREV] prefetch_chunk START: offset={$offset}, limit={$limit}");

        $users = get_users([
            'number' => $limit,
            'offset' => $offset,
            'fields' => ['ID'],
        ]);

        if (empty($users)) {
            error_log("[GCREV] prefetch_chunk: No users found (offset={$offset}). Stopping.");
            if ( $log_id > 0 ) {
                Gcrev_Cron_Logger::finish( $log_id, 'success' );
                delete_transient( 'gcrev_current_prefetch_log_id' );
            }
            return;
        }

        $ranges = ['last30', 'last90', 'last180', 'last365', 'previousMonth', 'twoMonthsAgo'];

        foreach ($users as $u) {
            $user_id = (int)$u->ID;
            error_log("[GCREV] Prefetch processing: user_id={$user_id}");
            $user_had_error = false;

            try {
                $config = $this->config->get_user_config($user_id);
            } catch (\Exception $e) {
                error_log("[GCREV] Prefetch SKIP user_id={$user_id}: " . $e->getMessage());
                if ( $log_id > 0 ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'skip', $e->getMessage() );
                }
                continue;
            }

            // --- (1) ダッシュボード／デバイスキャッシュ（既存） ---
            foreach ($ranges as $range) {
                $cached = $this->dashboard_cache_get($user_id, $range);
                if ($cached) {
                    error_log("[GCREV] Prefetch SKIP user_id={$user_id}, range={$range}: already cached");
                    continue;
                }

                try {
                    $data = $this->fetch_dashboard_data_internal($config, $range);
                    $this->dashboard_cache_set($user_id, $range, $data);
                    error_log("[GCREV] Prefetch SUCCESS user_id={$user_id}, range={$range}");
                } catch (\Exception $e) {
                    error_log("[GCREV] Prefetch ERROR user_id={$user_id}, range={$range}: " . $e->getMessage());
                    $user_had_error = true;
                }

                usleep(self::PREFETCH_SLEEP_US);
            }

            // --- (2) 各分析ページキャッシュ（追加） ---
            // period-selector が使う値（prev-month / last30）を対象にプリフェッチ
            $analysis_periods = ['prev-month', 'last30'];

            foreach ($analysis_periods as $period) {
                $this->prefetch_analysis_caches($user_id, $config, $period);
            }

            // --- (3) KPIトレンド（過去12ヶ月推移）キャッシュ ---
            foreach (['sessions', 'cv', 'meo'] as $trend_metric) {
                $trend_cache_key = "gcrev_trend_{$user_id}_{$trend_metric}_" . date('Y-m');
                if (get_transient($trend_cache_key) !== false) {
                    error_log("[GCREV] Prefetch SKIP trend user_id={$user_id}, metric={$trend_metric}: already cached");
                    continue;
                }
                try {
                    $this->get_monthly_metric_trend($user_id, $trend_metric, 12);
                    error_log("[GCREV] Prefetch trend SUCCESS user_id={$user_id}, metric={$trend_metric}");
                } catch (\Exception $e) {
                    error_log("[GCREV] Prefetch trend ERROR user_id={$user_id}, metric={$trend_metric}: " . $e->getMessage());
                    $user_had_error = true;
                }
                usleep(self::PREFETCH_SLEEP_US);
            }

            // Cron Logger: ユーザー単位の結果を記録
            if ( $log_id > 0 ) {
                Gcrev_Cron_Logger::log_user(
                    $log_id,
                    $user_id,
                    $user_had_error ? 'error' : 'success',
                    $user_had_error ? 'Some ranges had errors' : null
                );
            }
        }

        $next_offset = $offset + $limit;
        $next_users  = get_users(['number' => 1, 'offset' => $next_offset, 'fields' => ['ID']]);

        if (!empty($next_users)) {
            wp_schedule_single_event(time() + 10, 'gcrev_prefetch_chunk_event', [$next_offset, $limit]);
            error_log("[GCREV] Scheduled next prefetch_chunk: offset={$next_offset}");
        } else {
            delete_transient( self::LOCK_PREFETCH );
            error_log("[GCREV] Prefetch DONE. No more users.");
            // Cron Logger: ジョブ完了
            if ( $log_id > 0 ) {
                Gcrev_Cron_Logger::finish( $log_id, 'success' );
                delete_transient( 'gcrev_current_prefetch_log_id' );
            }
        }
    }

    // =========================================================
    // スロット別プリフェッチ処理（Phase 3: 時間帯分散用）
    // =========================================================

    /**
     * 特定スロットのユーザーをプリフェッチする。
     *
     * @param int $slot   スロット番号
     * @param int $offset チャンクオフセット
     * @param int $limit  チャンクサイズ
     */
    public function prefetch_chunk_for_slot( int $slot, int $offset, int $limit ): void {

        $lock_key     = "gcrev_lock_prefetch_slot_{$slot}";
        $log_id_key   = "gcrev_current_prefetch_slot_{$slot}_log_id";
        $chunk_hook   = "gcrev_prefetch_chunk_slot_{$slot}_event";

        // ─── 重複実行防止ロック（最初のチャンクのみ設定） ───
        if ( $offset === 0 ) {
            if ( get_transient( $lock_key ) ) {
                error_log( "[GCREV] prefetch_slot_{$slot}: LOCKED, skipping" );
                if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
                    $locked_id = Gcrev_Cron_Logger::start( "prefetch_slot_{$slot}", [ 'note' => 'locked' ] );
                    Gcrev_Cron_Logger::finish( $locked_id, 'locked' );
                }
                return;
            }
            set_transient( $lock_key, 1, self::LOCK_TTL );

            if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
                $log_id = Gcrev_Cron_Logger::start( "prefetch_slot_{$slot}", [ 'slot' => $slot, 'chunk_limit' => $limit ] );
                set_transient( $log_id_key, $log_id, self::LOCK_TTL );
            }
        }

        $log_id = class_exists( 'Gcrev_Cron_Logger' ) ? (int) get_transient( $log_id_key ) : 0;

        error_log( "[GCREV] prefetch_slot_{$slot} START: offset={$offset}, limit={$limit}" );

        // スロット別ユーザー取得
        if ( ! class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            error_log( "[GCREV] prefetch_slot_{$slot}: Gcrev_Prefetch_Scheduler not loaded, aborting" );
            return;
        }

        $user_ids = Gcrev_Prefetch_Scheduler::get_users_for_slot( $slot, $limit, $offset );

        if ( empty( $user_ids ) ) {
            error_log( "[GCREV] prefetch_slot_{$slot}: No users at offset={$offset}. DONE." );
            delete_transient( $lock_key );
            if ( $log_id > 0 ) {
                Gcrev_Cron_Logger::finish( $log_id, 'success' );
                delete_transient( $log_id_key );
            }
            return;
        }

        $ranges = ['last30', 'last90', 'last180', 'last365', 'previousMonth', 'twoMonthsAgo'];

        foreach ( $user_ids as $user_id ) {
            error_log( "[GCREV] Prefetch slot_{$slot} processing: user_id={$user_id}" );
            $user_had_error = false;

            try {
                $config = $this->config->get_user_config( $user_id );
            } catch ( \Exception $e ) {
                error_log( "[GCREV] Prefetch slot_{$slot} SKIP user_id={$user_id}: " . $e->getMessage() );
                if ( $log_id > 0 ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'skip', $e->getMessage() );
                }
                continue;
            }

            // --- (1) ダッシュボード ---
            foreach ( $ranges as $range ) {
                $cached = $this->dashboard_cache_get( $user_id, $range );
                if ( $cached ) {
                    continue;
                }
                try {
                    $data = $this->fetch_dashboard_data_internal( $config, $range );
                    $this->dashboard_cache_set( $user_id, $range, $data );
                } catch ( \Exception $e ) {
                    error_log( "[GCREV] Prefetch slot_{$slot} ERROR user_id={$user_id}, range={$range}: " . $e->getMessage() );
                    $user_had_error = true;
                }
                usleep( self::PREFETCH_SLEEP_US );
            }

            // --- (2) 分析ページ ---
            foreach ( ['prev-month', 'last30'] as $period ) {
                $this->prefetch_analysis_caches( $user_id, $config, $period );
            }

            // --- (3) KPIトレンド ---
            foreach ( ['sessions', 'cv', 'meo'] as $trend_metric ) {
                $trend_cache_key = "gcrev_trend_{$user_id}_{$trend_metric}_" . date( 'Y-m' );
                if ( get_transient( $trend_cache_key ) !== false ) {
                    continue;
                }
                try {
                    $this->get_monthly_metric_trend( $user_id, $trend_metric, 12 );
                } catch ( \Exception $e ) {
                    error_log( "[GCREV] Prefetch slot_{$slot} trend ERROR user_id={$user_id}: " . $e->getMessage() );
                    $user_had_error = true;
                }
                usleep( self::PREFETCH_SLEEP_US );
            }

            if ( $log_id > 0 ) {
                Gcrev_Cron_Logger::log_user(
                    $log_id,
                    $user_id,
                    $user_had_error ? 'error' : 'success',
                    $user_had_error ? 'Some ranges had errors' : null
                );
            }
        }

        // ─── 次チャンクのスケジュール ───
        $next_offset = $offset + $limit;

        if ( Gcrev_Prefetch_Scheduler::has_more_users( $slot, $next_offset ) ) {
            wp_schedule_single_event( time() + 10, $chunk_hook, [ $slot, $next_offset, $limit ] );
            error_log( "[GCREV] prefetch_slot_{$slot}: Scheduled next chunk offset={$next_offset}" );
        } else {
            delete_transient( $lock_key );
            error_log( "[GCREV] prefetch_slot_{$slot}: DONE." );
            if ( $log_id > 0 ) {
                Gcrev_Cron_Logger::finish( $log_id, 'success' );
                delete_transient( $log_id_key );
            }
        }
    }

    /**
     * 各分析ページ（流入元・地域・ページ・キーワード）のキャッシュをプリフェッチ
     * 既存の REST コールバック内部と同一のキャッシュキー／ロジックを使用
     */
    public function prefetch_analysis_caches(int $user_id, array $config, string $period): void {

        $ga4_id  = $config['ga4_id'];
        $gsc_url = $config['gsc_url'] ?? '';

        $dates      = $this->dates->calculate_period_dates($period);
        $comparison  = $this->dates->calculate_comparison_dates($period);
        $date_hash   = md5("{$dates['start']}_{$dates['end']}");

        // source 用の period 正規化（get_source_analysis は previousMonth/twoMonthsAgo を期待）
        $source_period = $period;
        if ($period === 'prev-month')      $source_period = 'previousMonth';
        elseif ($period === 'prev-prev-month') $source_period = 'twoMonthsAgo';

        // ----- 流入元 -----
        $source_dates = $this->dates->get_date_range($source_period);
        $source_hash  = md5("{$source_dates['start']}_{$source_dates['end']}");
        $key_source   = "gcrev_source_{$user_id}_{$source_period}_{$source_hash}";
        if (get_transient($key_source) === false) {
            try {
                // get_source_analysis は内部でキャッシュ保存するので呼ぶだけでOK
                $this->get_source_analysis($source_period, $user_id);
                error_log("[GCREV] Prefetch SOURCE OK: user={$user_id}, period={$period}");
            } catch (\Exception $e) {
                error_log("[GCREV] Prefetch SOURCE ERR: user={$user_id}, period={$period}: " . $e->getMessage());
            }
            usleep(self::PREFETCH_SLEEP_US);
        }

        // ----- 地域 -----
        $key_region = "gcrev_region_{$user_id}_{$period}_{$date_hash}";
        if (get_transient($key_region) === false) {
            try {
                $regions       = $this->ga4->fetch_region_details($ga4_id, $dates['start'], $dates['end']);
                $regions_prev  = $this->ga4->fetch_region_details($ga4_id, $comparison['start'], $comparison['end']);
                $regions_result = $this->calculate_region_changes($regions, $regions_prev);
                set_transient($key_region, [
                    'success'        => true,
                    'period'         => $period,
                    'period_display' => $dates['display'] ?? '',
                    'regions_detail' => $regions_result,
                ], 86400);
                error_log("[GCREV] Prefetch REGION OK: user={$user_id}, period={$period}");
            } catch (\Exception $e) {
                error_log("[GCREV] Prefetch REGION ERR: user={$user_id}, period={$period}: " . $e->getMessage());
            }
            usleep(self::PREFETCH_SLEEP_US);
        }

        // ----- ページ分析 -----
        $key_page = "gcrev_page_{$user_id}_{$period}_{$date_hash}";
        if (get_transient($key_page) === false) {
            try {
                $pages      = $this->ga4->fetch_page_details($ga4_id, $dates['start'], $dates['end'], $gsc_url);
                $pages_prev = $this->ga4->fetch_page_details($ga4_id, $comparison['start'], $comparison['end'], $gsc_url);
                $pages_result = $this->calculate_page_changes($pages, $pages_prev);
                set_transient($key_page, [
                    'success' => true,
                    'data'    => [
                        'pages_detail'        => $pages_result,
                        'current_period'      => ['start' => $dates['start'], 'end' => $dates['end']],
                        'comparison_period'    => ['start' => $comparison['start'], 'end' => $comparison['end']],
                        'current_range_label'  => str_replace('-', '/', $dates['start']) . ' 〜 ' . str_replace('-', '/', $dates['end']),
                        'compare_range_label'  => str_replace('-', '/', $comparison['start']) . ' 〜 ' . str_replace('-', '/', $comparison['end']),
                    ],
                ], 86400);
                error_log("[GCREV] Prefetch PAGE OK: user={$user_id}, period={$period}");
            } catch (\Exception $e) {
                error_log("[GCREV] Prefetch PAGE ERR: user={$user_id}, period={$period}: " . $e->getMessage());
            }
            usleep(self::PREFETCH_SLEEP_US);
        }

        // ----- キーワード -----
        $key_kw = "gcrev_keywords_{$user_id}_{$period}_{$date_hash}";
        if (get_transient($key_kw) === false) {
            try {
                $current_gsc = $this->gsc->fetch_gsc_data($gsc_url, $dates['start'], $dates['end']);
                $prev_gsc    = $this->gsc->fetch_gsc_data($gsc_url, $comparison['start'], $comparison['end']);
                $kw_result   = $this->calculate_keyword_changes(
                    $current_gsc['keywords'] ?? [],
                    $prev_gsc['keywords'] ?? []
                );
                set_transient($key_kw, [
                    'success' => true,
                    'data'    => [
                        'keywords_detail'     => $kw_result,
                        'gsc_total'           => $current_gsc['total'] ?? [],
                        'current_period'      => ['start' => $dates['start'], 'end' => $dates['end']],
                        'comparison_period'    => ['start' => $comparison['start'], 'end' => $comparison['end']],
                        'current_range_label'  => str_replace('-', '/', $dates['start']) . ' 〜 ' . str_replace('-', '/', $dates['end']),
                        'compare_range_label'  => str_replace('-', '/', $comparison['start']) . ' 〜 ' . str_replace('-', '/', $comparison['end']),
                    ],
                ], 86400);
                error_log("[GCREV] Prefetch KEYWORD OK: user={$user_id}, period={$period}");
            } catch (\Exception $e) {
                error_log("[GCREV] Prefetch KEYWORD ERR: user={$user_id}, period={$period}: " . $e->getMessage());
            }
            usleep(self::PREFETCH_SLEEP_US);
        }
    }

    // =========================================================
    // 内部ヘルパー: ダッシュボードデータ取得
    // =========================================================
    private function fetch_dashboard_data_internal(array $config, string $range): array {

        $ga4_id  = $config['ga4_id'];
        $gsc_url = $config['gsc_url'];
        $site_url = $config['site_url'] ?? '';

        $dates = $this->dates->get_date_range($range);

        // 比較期間（同日数の直前期間）
        $comparison = $this->dates->get_comparison_range($dates['start'], $dates['end']);

        // GA4 / GSC 取得
        $ga4_pages = $this->ga4->fetch_ga4_data($ga4_id, $dates['start'], $dates['end'], $site_url);

        $ga4_summary = $this->ga4->fetch_ga4_summary($ga4_id, $dates['start'], $dates['end']);
        $ga4_prev    = $this->ga4->fetch_ga4_summary($ga4_id, $comparison['start'], $comparison['end']);

        $daily = $this->ga4->fetch_ga4_daily_series($ga4_id, $dates['start'], $dates['end']);

        $devices = $this->ga4->fetch_ga4_breakdown($ga4_id, $dates['start'], $dates['end'], 'deviceCategory', 'sessions');
        $devices_detail      = $this->ga4->fetch_device_details($ga4_id, $dates['start'], $dates['end']);
        $devices_prev_detail = $this->ga4->fetch_device_details($ga4_id, $comparison['start'], $comparison['end']);
        $devices_daily_series = $this->ga4->fetch_device_daily_series($ga4_id, $dates['start'], $dates['end']);

        $medium  = $this->ga4->fetch_ga4_breakdown($ga4_id, $dates['start'], $dates['end'], 'sessionMedium', 'sessions');
        $geo     = $this->ga4->fetch_ga4_breakdown($ga4_id, $dates['start'], $dates['end'], 'city', 'sessions');
        
        $geo_region = $this->ga4->fetch_ga4_breakdown($ga4_id, $dates['start'], $dates['end'], 'region', 'sessions');
        $age     = $this->ga4->fetch_ga4_age_breakdown($ga4_id, $dates['start'], $dates['end']);
        $age_demographics      = $this->ga4->fetch_age_demographics($ga4_id, $dates['start'], $dates['end']);
        $age_prev_demographics = $this->ga4->fetch_age_demographics($ga4_id, $comparison['start'], $comparison['end']);

        // 性別 × 年齢（Google Signals 必須）
        $gender_age_cross = $this->ga4->fetch_ga4_gender_age_cross(
            $ga4_id,
            $dates['start'],
            $dates['end']
        );


        // 年齢別データに前期比を追加
        if (!empty($age_demographics) && !empty($age_prev_demographics)) {
            foreach ($age_demographics as &$current) {
                $age_range = $current['age_range'];
                // 前期の同じ年齢層を探す
                foreach ($age_prev_demographics as $prev) {
                    if ($prev['age_range'] === $age_range) {
                        $current_sessions = $current['sessions'];
                        $prev_sessions = $prev['sessions'];
                        if ($prev_sessions > 0) {
                            $change_percent = (($current_sessions - $prev_sessions) / $prev_sessions) * 100.0;
                            $current['change_percent'] = round($change_percent, 1);
                        } else {
                            $current['change_percent'] = 0.0;
                        }
                        break;
                    }
                }
                // 前期にデータがない場合は0
                if (!isset($current['change_percent'])) {
                    $current['change_percent'] = 0.0;
                }
            }
            unset($current);
        }


        $gsc_data = $this->gsc->fetch_gsc_data($gsc_url, $dates['start'], $dates['end']);

        // 変化率（%）
        $trends = $this->build_trends($ga4_summary, $ga4_prev);

        // 流入元詳細データ（流入元ページ用）
        $source_analysis = [
            'channels_summary' => [],
            'sources_detail' => [],
            'channels_daily_series' => [],
        ];
        
        try {
            error_log("[GCREV] Fetching source data for range: {$dates['start']} to {$dates['end']}");
            $source_current = $this->ga4->fetch_source_data_from_ga4($ga4_id, $dates['start'], $dates['end']);
            $source_previous = $this->ga4->fetch_source_data_from_ga4($ga4_id, $comparison['start'], $comparison['end']);
            $source_analysis = $this->format_source_analysis_data($source_current, $source_previous);
            error_log("[GCREV] Source data fetched successfully: " . count($source_analysis['channels_summary'] ?? []) . " channels");
        } catch (\Exception $e) {
            error_log("[GCREV] ERROR fetching source data: " . $e->getMessage());
            // エラー時は空配列のまま（他のデータは正常に返す）
        }

        // ダッシュボードJSが期待する「フラット構造」＋ 既存の ga4/gsc ネスト構造（レポート側互換）
        return [
            // 表示用期間
            'current_period'    => [
                'start'   => $dates['start'],
                'end'     => $dates['end'],
                'display' => $dates['display'],
            ],
            'comparison_period' => [
                'start'   => $comparison['start'],
                'end'     => $comparison['end'],
                'display' => $comparison['display'],
            ],

            // KPI（ダッシュボード用）
            'pageViews'      => $ga4_summary['pageViews'],
            'sessions'       => $ga4_summary['sessions'],
            'users'          => $ga4_summary['users'],
            'newUsers'       => $ga4_summary['newUsers'],
            'returningUsers' => $ga4_summary['returningUsers'],
            'avgDuration'    => $ga4_summary['avgDuration'],
            'conversions'    => $ga4_summary['conversions'] ?? '0',

            // 増減（前期比）
            'trends' => $trends,

            // 日次スパークライン
            'daily' => $daily,

            // 内訳（円グラフ/表）
            'devices' => $devices,
            'devices_detail'       => $devices_detail,
            'devices_prev_detail'  => $devices_prev_detail,
            'devices_daily_series' => $devices_daily_series,
            'medium'  => $medium,
            'geo'     => $geo,
            'geo_region' => $geo_region,
            'age'     => $age,
            'age_demographics'      => $age_demographics,
            'age_prev_demographics' => $age_prev_demographics,
            'gender_age_cross'      => $gender_age_cross,

            // 流入元詳細（流入元ページ用）
            'channels_summary'       => $source_analysis['channels_summary'] ?? [],
            'sources_detail'         => $source_analysis['sources_detail'] ?? [],
            'channels_daily_series'  => $source_analysis['channels_daily_series'] ?? [],

            // ランキング（表）
            'pages'    => $ga4_pages['pages'] ?? [],
            'keywords' => $gsc_data['keywords'] ?? [],

            // 既存（レポート側が利用）
            'ga4' => [
                'total' => $ga4_summary,
                'pages' => $ga4_pages['pages'] ?? [],
            ],
            'gsc' => $gsc_data,
        ];
    }

    // =========================================================
    // 前々月データ存在チェック（共通ヘルパー）
    // =========================================================

    /**
     * GA4 プロパティに前々月データが存在するかを判定する。
     *
     * fetch_dashboard_data_internal('twoMonthsAgo') を呼び、
     * 最低限の KPI（sessions / pageViews）が取得できるかを確認する。
     *
     * @param  int $user_id ユーザーID
     * @return array{available: bool, reason?: string, period?: array}
     */
    public function has_prev2_data(int $user_id): array {
        try {
            $config = $this->config->get_user_config($user_id);
            if (empty($config['ga4_id'])) {
                return [
                    'available' => false,
                    'reason'    => 'GA4プロパティが設定されていません',
                ];
            }

            $two_data = $this->fetch_dashboard_data_internal($config, 'twoMonthsAgo');

            $sessions   = (int) ($two_data['ga4']['total']['sessions']  ?? 0);
            $page_views = (int) ($two_data['ga4']['total']['pageViews'] ?? 0);
            $is_zero    = ($sessions === 0 && $page_views === 0);

            return [
                'available' => true,
                'is_zero'   => $is_zero,
                'period'    => $two_data['current_period'] ?? null,
            ];

        } catch (\Throwable $e) {
            error_log("[GCREV] has_prev2_data: ERROR user_id={$user_id}: " . $e->getMessage());
            return [
                'available' => false,
                'reason'    => '前々月データの取得に失敗しました: ' . $e->getMessage(),
            ];
        }
    }

    // =========================================================
    // 前期比の増減（%）オブジェクトを生成
    // =========================================================
    private function build_trends(array $current, array $prev): array {

        $calc = function (int|float $cur, int|float $prv): array {
            // より安全なゼロチェック（0, 0.0, null, 極小値すべて対応）
            if (empty($prv) || abs($prv) < 0.0001) {
                if (empty($cur) || abs($cur) < 0.0001) {
                    return ['value' => 0, 'text' => '±0.0%', 'color' => '#666'];
                }
                return ['value' => 100, 'text' => '+∞', 'color' => '#3b82f6'];
            }

            $val = (($cur - $prv) / $prv) * 100.0;
            $text = ($val > 0 ? '+' : '') . number_format($val, 1) . '%';

            $color = '#666';
            if ($val > 0) $color = '#3b82f6';
            if ($val < 0) $color = '#ef4444';

            return ['value' => $val, 'text' => $text, 'color' => $color];
        };

        return [
            'pageViews'      => $calc((float)($current['_pageViews'] ?? 0), (float)($prev['_pageViews'] ?? 0)),
            'sessions'       => $calc((float)($current['_sessions'] ?? 0), (float)($prev['_sessions'] ?? 0)),
            'users'          => $calc((float)($current['_users'] ?? 0), (float)($prev['_users'] ?? 0)),
            'newUsers'       => $calc((float)($current['_newUsers'] ?? 0), (float)($prev['_newUsers'] ?? 0)),
            'returningUsers' => $calc((float)($current['_returningUsers'] ?? 0), (float)($prev['_returningUsers'] ?? 0)),
            'avgDuration'    => $calc((float)($current['_avgDuration'] ?? 0), (float)($prev['_avgDuration'] ?? 0)),
            'conversions'    => $calc((float)($current['_conversions'] ?? 0), (float)($prev['_conversions'] ?? 0)),
        ];
    }


    // =========================================================
    // クライアント情報の保存
    // =========================================================
    public function save_client_info(WP_REST_Request $request): WP_REST_Response {

        $user_id = get_current_user_id();
        $params  = $request->get_json_params();

        update_user_meta($user_id, 'report_site_url',         esc_url_raw($params['site_url'] ?? ''));
        update_user_meta($user_id, 'report_target',           sanitize_text_field($params['target'] ?? ''));
        update_user_meta($user_id, 'report_issue',            sanitize_text_field($params['issue'] ?? ''));
        update_user_meta($user_id, 'report_goal_monthly',     sanitize_text_field($params['goal_monthly'] ?? ''));
        update_user_meta($user_id, 'report_focus_numbers',    sanitize_text_field($params['focus_numbers'] ?? ''));
        update_user_meta($user_id, 'report_current_state',    sanitize_text_field($params['current_state'] ?? ''));
        update_user_meta($user_id, 'report_goal_main',        sanitize_text_field($params['goal_main'] ?? ''));
        update_user_meta($user_id, 'report_additional_notes', sanitize_text_field($params['additional_notes'] ?? ''));
        
        // 出力モードの保存
        $output_mode = $params['output_mode'] ?? 'normal';
        if (!in_array($output_mode, ['normal', 'easy'], true)) {
            $output_mode = 'normal';
        }
        update_user_meta($user_id, 'report_output_mode', $output_mode);

        error_log("[GCREV] Client info saved for user_id={$user_id}, output_mode={$output_mode}");

        return new WP_REST_Response([
            'success' => true,
            'message' => 'クライアント情報を保存しました',
        ], 200);
    }
    /**
     * 今月のレポート生成回数を取得
     */
    public function get_generation_count(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        
        // 前月の年月を取得（レポートは前月分として保存されるため）
        $tz = wp_timezone();
        $now = new DateTime('now', $tz);
        $prev_month = new DateTime('first day of last month', $tz);
        $year_month = $prev_month->format('Y-m');
        
        error_log("[GCREV] get_generation_count: user_id={$user_id}, year_month={$year_month}");
        
        // 前月生成されたレポート数を取得
        $args = [
            'post_type'      => 'gcrev_report',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'meta_query'     => [
                [
                    'key'     => '_gcrev_year_month',
                    'value'   => $year_month,
                    'compare' => '='
                ]
            ],
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ];
        
        $query = new WP_Query($args);
        $current_count = $query->found_posts;
        
        error_log("[GCREV] get_generation_count: found {$current_count} reports for year_month={$year_month}");
        
        // 上限（プラン別に変更可能）
        $max_count = 10;
        $remaining = max(0, $max_count - $current_count);
        
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'current_count' => $current_count,
                'max_count'     => $max_count,
                'remaining'     => $remaining
            ]
        ], 200);
    }
    // =========================================================
    // レポート生成（Multi-pass）
    // =========================================================
    public function generate_report(WP_REST_Request $request): WP_REST_Response {

        $user_id = get_current_user_id();
        $params  = $request->get_json_params();

        $prev_data   = $params['previous_month'] ?? null;
        $two_data    = $params['two_months_ago'] ?? null;
        $client_info = $params['client_info']    ?? [];
        $year_month  = $params['year_month']     ?? null;

        if (!is_array($prev_data) || !is_array($two_data) || !is_array($client_info)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'リクエストデータが不正です',
            ], 400);
        }

        try {
            error_log("[GCREV] Generating new report for user_id={$user_id}");

            // -------------------------------------------------
            // 追加仕様：ターゲットエリア（都道府県）の判定
            // - client_info['target'] に「全国」が含まれる場合は null
            // - それ以外で都道府県名が含まれる場合、その都道府県名
            // -------------------------------------------------
            $target_area = $this->generator->detect_target_area((string)($client_info['target'] ?? ''));

            // -------------------------------------------------
            // 追加仕様：Key events 取得（お問い合わせ / 資料DL 等）
            // ※ページ側の構造は一切変更せず、レポートHTMLだけで反映
            // -------------------------------------------------
            $config = $this->config->get_user_config($user_id);
            $ga4_id = $config['ga4_id'];

            $prev_start = (string)($prev_data['current_period']['start'] ?? '');
            $prev_end   = (string)($prev_data['current_period']['end']   ?? '');
            $two_start  = (string)($two_data['current_period']['start']  ?? '');
            $two_end    = (string)($two_data['current_period']['end']    ?? '');

            $prev_key_events = ($prev_start && $prev_end) ? $this->ga4->fetch_ga4_key_events($ga4_id, $prev_start, $prev_end) : [];
            $two_key_events  = ($two_start  && $two_end)  ? $this->ga4->fetch_ga4_key_events($ga4_id, $two_start,  $two_end)  : [];

            $selected_events = $this->ga4->select_report_key_events($prev_key_events, $two_key_events);
            // ['contact' => ['name'=>..., 'label'=>...], 'download' => [...], 'others'=>[]]
            // -------------------------------------------------
            // 追加：実質CV判定 → client_info に注入（AIレポート生成で使用）
            // -------------------------------------------------
            $report_year_month = $year_month;
            if (empty($report_year_month) && $prev_start) {
                $report_year_month = substr($prev_start, 0, 7);
            }
            if (!empty($report_year_month)) {
                $eff = $this->get_effective_cv_monthly($report_year_month, $user_id);
                $client_info['effective_cv'] = [
                    'source'     => $eff['source'],
                    'cv'         => $eff['total'],
                    'has_actual' => ($eff['source'] !== 'ga4'),
                    'detail'     => $eff['breakdown_manual'] ?? [],
                    'components' => $eff['components'],
                    'daily'      => $eff['daily'],
                ];
                error_log("[GCREV] generate_report: effective_cv source={$eff['source']}, total={$eff['total']}");
            }
            // セクション単位（既存Multi-pass）で “文章部” を生成
            $sections_html = $this->generator->generate_report_multi_pass($prev_data, $two_data, $client_info, $target_area);

            // 最終HTML：sample_report.html のレイアウトに合わせて組み立て
            $report_html = $this->generator->build_sample_layout_report_html(
                $prev_data,
                $two_data,
                $client_info,
                $sections_html,
                $target_area,
                $selected_events,
                $prev_key_events,
                $two_key_events
            );


            // === 初心者向けモード：全文リライト（Markdown出力） ===
            $beginner_markdown = '';
            $is_easy_mode = (($client_info['output_mode'] ?? 'normal') === 'easy');
            if ($is_easy_mode) {
                $rw_year_month = $year_month;
                if (empty($rw_year_month) && $prev_start) {
                    $rw_year_month = substr($prev_start, 0, 7);
                }
                if (!empty($rw_year_month)) {
                    $beginner_markdown = $this->generator->rewrite_report_for_beginner(
                        $sections_html,
                        $target_area,
                        $user_id,
                        $rw_year_month
                    );
                }
                if ($beginner_markdown !== '') {
                    error_log("[GCREV] generate_report: beginner_markdown generated, length=" . mb_strlen($beginner_markdown));
                }
            }

            // === 手動生成時も履歴保存 ===
            $highlights = $this->highlights_mod->extract_highlights_from_html($report_html, $user_id);
            $this->repo->save_report_to_history($user_id, $report_html, $client_info, 'manual', $year_month, $highlights, $beginner_markdown);

            // === インフォグラフィックJSON生成・保存 ===
            try {
                $infographic = $this->generate_infographic_json($prev_data, $two_data, $client_info, $user_id);
                if ($infographic) {
                    // year_month が 'YYYY-MM' 形式で来る場合をパース
                    $ig_year  = $year_month ? (int)substr($year_month, 0, 4) : 0;
                    $ig_month = $year_month ? (int)substr($year_month, 5, 2) : 0;
                    if ($ig_year > 0 && $ig_month > 0) {
                        $this->save_monthly_infographic($ig_year, $ig_month, $user_id, $infographic);
                        error_log("[GCREV] generate_report: Infographic saved for user_id={$user_id}, {$year_month}");
                    } else {
                        // year_month不明の場合は前月を使用
                        $tz_ig = wp_timezone();
                        $prev_dt = new DateTimeImmutable('first day of last month', $tz_ig);
                        $this->save_monthly_infographic((int)$prev_dt->format('Y'), (int)$prev_dt->format('n'), $user_id, $infographic);
                        error_log("[GCREV] generate_report: Infographic saved (fallback prev month) for user_id={$user_id}");
                    }
                }
            } catch (\Exception $ig_e) {
                error_log("[GCREV] generate_report: Infographic error: " . $ig_e->getMessage());
            }

            $response_data = [
                'success'     => true,
                'report_html' => $report_html,
            ];
            if ($beginner_markdown !== '') {
                $response_data['beginner_markdown'] = $beginner_markdown;
            }

            return new WP_REST_Response($response_data, 200);

        } catch (\Exception $e) {
            error_log("[GCREV] Report generation ERROR for user_id={$user_id}: " . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * REST: 当月の最新レポート取得
     */
    public function get_current_report(WP_REST_Request $request): WP_REST_Response {

        $user_id = get_current_user_id();
        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $year_month = $now->format('Y-m');

        $args = [
            'post_type'      => 'gcrev_report',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_gcrev_user_id',
                    'value' => $user_id,
                ],
                [
                    'key'   => '_gcrev_year_month',
                    'value' => $year_month,
                ],
                [
                    'key'   => '_gcrev_is_current',
                    'value' => 1,
                ],
            ],
        ];

        $posts = get_posts($args);

        if (empty($posts)) {
            return new WP_REST_Response([
                'success' => true,
                'report'  => null,
            ], 200);
        }

        $post = $posts[0];
        $report = $this->repo->format_report_data($post);

        return new WP_REST_Response([
            'success' => true,
            'report'  => $report,
        ], 200);
    }

    /**
     * REST: レポート履歴一覧取得（最新12件）
     */
    public function get_report_history(WP_REST_Request $request): WP_REST_Response {

        $user_id = get_current_user_id();

        $args = [
            'post_type'      => 'gcrev_report',
            'posts_per_page' => 12,
            'orderby'        => 'meta_value',
            'meta_key'       => '_gcrev_created_at',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'   => '_gcrev_user_id',
                    'value' => $user_id,
                ],
            ],
        ];

        $posts = get_posts($args);

        $reports = array_map([$this->repo, 'format_report_data'], $posts);

        return new WP_REST_Response([
            'success' => true,
            'reports' => $reports,
        ], 200);
    }

    /**
     * REST: 特定レポート取得
     */
    public function get_report_by_id(WP_REST_Request $request): WP_REST_Response {

        $user_id   = get_current_user_id();
        $report_id = (int) $request->get_param('report_id');

        $post = get_post($report_id);

        if (!$post || $post->post_type !== 'gcrev_report') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'レポートが見つかりません',
            ], 404);
        }

        // 権限チェック：本人のレポートのみ
        $owner_id = (int) get_post_meta($post->ID, '_gcrev_user_id', true);
        if ($owner_id !== $user_id) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'アクセス権限がありません',
            ], 403);
        }

        $report = $this->repo->format_report_data($post);

        return new WP_REST_Response([
            'success' => true,
            'report'  => $report,
        ], 200);
    }

    /**
     * === Cronから呼ばれる：毎月1日に全ユーザーの当月ドラフトを自動生成 ===
     * 
     * 注意：実際は毎日Cronを動かし、日付チェックで1日のみ実行する設計
     */
    /** Transientロック: 重複実行防止 */
    private const LOCK_REPORT_GEN      = 'gcrev_lock_report_gen';
    private const LOCK_REPORT_FINALIZE = 'gcrev_lock_report_finalize';
    private const LOCK_PREFETCH        = 'gcrev_lock_prefetch';
    private const LOCK_TTL             = 7200; // 2時間（チャンク処理の全完了を待つため）

    /** レポート生成チャンクサイズ（重い処理なのでプリフェッチより少なく） */
    public const REPORT_CHUNK_LIMIT = 3;

    /**
     * === Cronから呼ばれる：毎月1日にチャンク生成を起動 ===
     *
     * 全ユーザーを直列処理せず、REPORT_CHUNK_LIMIT ずつ
     * 自己チェーンで分割実行する（prefetch_chunk と同パターン）。
     */
    public function auto_generate_monthly_reports(): void {

        // ─── 重複実行防止ロック ───
        if ( get_transient( self::LOCK_REPORT_GEN ) ) {
            error_log( '[GCREV] auto_generate_monthly_reports: LOCKED, skipping duplicate run' );
            if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
                $locked_id = Gcrev_Cron_Logger::start( 'report_generate', [ 'note' => 'locked' ] );
                Gcrev_Cron_Logger::finish( $locked_id, 'locked' );
            }
            return;
        }
        set_transient( self::LOCK_REPORT_GEN, 1, self::LOCK_TTL );

        $tz  = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );

        // 1日以外は何もしない（冪等性）
        if ( (int) $now->format( 'd' ) !== 1 ) {
            error_log( '[GCREV] auto_generate_monthly_reports: Not 1st of month, skipping.' );
            delete_transient( self::LOCK_REPORT_GEN );
            return;
        }

        // Cron Logger: ジョブ開始
        if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
            $log_id = Gcrev_Cron_Logger::start( 'report_generate', [ 'chunk_limit' => self::REPORT_CHUNK_LIMIT ] );
            set_transient( 'gcrev_current_report_gen_log_id', $log_id, self::LOCK_TTL );
        }

        error_log( '[GCREV] auto_generate_monthly_reports: START on ' . $now->format( 'Y-m-d' ) . ' — scheduling chunk(0)' );

        // 最初のチャンクをスケジュール（10秒後）
        wp_schedule_single_event(
            time() + 10,
            'gcrev_monthly_report_generate_chunk_event',
            [ 0, self::REPORT_CHUNK_LIMIT ]
        );
    }

    /**
     * レポート生成チャンク処理
     *
     * prefetch_chunk() と同じ自己チェーンパターン。
     * REPORT_CHUNK_LIMIT 人分のレポートを生成し、
     * まだ残りユーザーがいれば次のチャンクをスケジュールする。
     *
     * @param int $offset ユーザーリストのオフセット
     * @param int $limit  チャンクサイズ
     */
    public function report_generate_chunk( int $offset, int $limit ): void {

        // Cron Logger: log_id を取得
        $log_id = class_exists( 'Gcrev_Cron_Logger' ) ? (int) get_transient( 'gcrev_current_report_gen_log_id' ) : 0;

        error_log( "[GCREV] report_generate_chunk START: offset={$offset}, limit={$limit}" );

        $tz         = wp_timezone();
        $now        = new DateTimeImmutable( 'now', $tz );
        $year_month = $now->format( 'Y-m' );

        $users = get_users( [
            'number' => $limit,
            'offset' => $offset,
            'fields' => ['ID'],
        ] );

        if ( empty( $users ) ) {
            delete_transient( self::LOCK_REPORT_GEN );
            error_log( "[GCREV] report_generate_chunk: No users at offset={$offset}. DONE." );
            if ( $log_id > 0 ) {
                Gcrev_Cron_Logger::finish( $log_id, 'success' );
                delete_transient( 'gcrev_current_report_gen_log_id' );
            }
            return;
        }

        foreach ( $users as $u ) {
            $user_id = (int) $u->ID;

            // 当月レポートが既に存在するかチェック
            $existing = $this->repo->get_reports_by_month( $user_id, $year_month );
            if ( ! empty( $existing ) ) {
                error_log( "[GCREV] auto_generate: user_id={$user_id} already has report for {$year_month}, skipping." );
                if ( $log_id > 0 ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'skip', 'Report already exists' );
                }
                continue;
            }

            // クライアント情報取得
            $client_info = [
                'site_url'      => get_user_meta( $user_id, 'report_site_url', true ),
                'target'        => get_user_meta( $user_id, 'report_target', true ),
                'issue'         => get_user_meta( $user_id, 'report_issue', true ),
                'goal_monthly'  => get_user_meta( $user_id, 'report_goal_monthly', true ),
                'focus_numbers' => get_user_meta( $user_id, 'report_focus_numbers', true ),
                'current_state' => get_user_meta( $user_id, 'report_current_state', true ),
                'goal_main'     => get_user_meta( $user_id, 'report_goal_main', true ),
            ];

            // 必須項目チェック
            if ( empty( $client_info['site_url'] ) || empty( $client_info['target'] ) ) {
                error_log( "[GCREV] auto_generate: user_id={$user_id} missing client info, skipping." );
                if ( $log_id > 0 ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'skip', 'Missing client info' );
                }
                continue;
            }

            // GA4プロパティ設定チェック
            $prev2_check = $this->has_prev2_data( $user_id );
            if ( ! $prev2_check['available'] ) {
                error_log( "[GCREV] auto_generate: user_id={$user_id} GA4_NOT_READY, skipping: " . ( $prev2_check['reason'] ?? '' ) );
                if ( $log_id > 0 ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'skip', 'GA4 not ready: ' . ( $prev2_check['reason'] ?? '' ) );
                }
                continue;
            }

            try {
                // 前月・前々月データ取得
                $config    = $this->config->get_user_config( $user_id );
                $prev_data = $this->fetch_dashboard_data_internal( $config, 'previousMonth' );
                $two_data  = $this->fetch_dashboard_data_internal( $config, 'twoMonthsAgo' );

                // 実質CV注入
                $auto_ym  = ( new DateTimeImmutable( 'first day of last month', $tz ) )->format( 'Y-m' );
                $eff_auto = $this->get_effective_cv_monthly( $auto_ym, $user_id );
                $client_info['effective_cv'] = [
                    'source'     => $eff_auto['source'],
                    'cv'         => $eff_auto['total'],
                    'has_actual' => ( $eff_auto['source'] !== 'ga4' ),
                    'detail'     => $eff_auto['breakdown_manual'] ?? [],
                    'components' => $eff_auto['components'],
                ];

                $report_html = $this->generator->generate_report_multi_pass( $prev_data, $two_data, $client_info );

                // === 初心者向けモード：全文リライト（Markdown出力） ===
                $auto_beginner_md = '';
                if ( ( $client_info['output_mode'] ?? 'normal' ) === 'easy' ) {
                    $auto_beginner_md = $this->generator->rewrite_report_for_beginner(
                        $report_html,
                        null,
                        $user_id,
                        $auto_ym
                    );
                }

                // 保存（source=auto）
                $highlights = $this->highlights_mod->extract_highlights_from_html( $report_html, $user_id );
                $this->repo->save_report_to_history( $user_id, $report_html, $client_info, 'auto', null, $highlights, $auto_beginner_md );

                // === インフォグラフィックJSON生成・保存 ===
                try {
                    $infographic = $this->generate_infographic_json( $prev_data, $two_data, $client_info, $user_id );
                    if ( $infographic ) {
                        $prev_dt  = new DateTimeImmutable( 'first day of last month', $tz );
                        $ig_year  = (int) $prev_dt->format( 'Y' );
                        $ig_month = (int) $prev_dt->format( 'n' );
                        $this->save_monthly_infographic( $ig_year, $ig_month, $user_id, $infographic );
                        error_log( "[GCREV] auto_generate: Infographic saved for user_id={$user_id}" );
                    } else {
                        error_log( "[GCREV] auto_generate: Infographic generation returned null for user_id={$user_id}" );
                    }
                } catch ( \Exception $ig_e ) {
                    error_log( "[GCREV] auto_generate: Infographic error for user_id={$user_id}: " . $ig_e->getMessage() );
                }

                error_log( "[GCREV] auto_generate: SUCCESS user_id={$user_id}, year_month={$year_month}" );
                if ( $log_id > 0 ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'success' );
                }

            } catch ( \Exception $e ) {
                error_log( "[GCREV] auto_generate: ERROR user_id={$user_id}: " . $e->getMessage() );
                if ( $log_id > 0 ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'error', $e->getMessage() );
                }
            }
        }

        // ─── 次チャンクのスケジュール ───
        $next_offset = $offset + $limit;
        $next_users  = get_users( [ 'number' => 1, 'offset' => $next_offset, 'fields' => ['ID'] ] );

        if ( ! empty( $next_users ) ) {
            wp_schedule_single_event(
                time() + 10,
                'gcrev_monthly_report_generate_chunk_event',
                [ $next_offset, $limit ]
            );
            error_log( "[GCREV] report_generate_chunk: Scheduled next chunk offset={$next_offset}" );
        } else {
            delete_transient( self::LOCK_REPORT_GEN );
            error_log( '[GCREV] report_generate_chunk: All users processed. DONE.' );
            if ( $log_id > 0 ) {
                Gcrev_Cron_Logger::finish( $log_id, 'success' );
                delete_transient( 'gcrev_current_report_gen_log_id' );
            }
        }
    }

    /**
     * === Cronから呼ばれる：月末に未確定レポートを自動確定 ===
     *
     * 注意：実際は毎日Cronを動かし、月末かどうかチェックして実行
     */
    public function auto_finalize_monthly_reports(): void {

        // ─── 重複実行防止ロック ───
        if ( get_transient( self::LOCK_REPORT_FINALIZE ) ) {
            error_log( '[GCREV] auto_finalize: LOCKED, skipping duplicate run' );
            if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
                $locked_id = Gcrev_Cron_Logger::start( 'report_finalize', [ 'note' => 'locked' ] );
                Gcrev_Cron_Logger::finish( $locked_id, 'locked' );
            }
            return;
        }
        set_transient( self::LOCK_REPORT_FINALIZE, 1, self::LOCK_TTL );

        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);

        // 月末判定（翌日が1日かどうか）
        $tomorrow = $now->add(new DateInterval('P1D'));
        if ((int)$tomorrow->format('d') !== 1) {
            error_log("[GCREV] auto_finalize: Not end of month, skipping.");
            delete_transient( self::LOCK_REPORT_FINALIZE );
            return;
        }

        // Cron Logger: ジョブ開始
        $log_id = 0;
        if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
            $log_id = Gcrev_Cron_Logger::start( 'report_finalize' );
        }

        error_log("[GCREV] auto_finalize_monthly_reports: START on " . $now->format('Y-m-d'));

        $year_month = $now->format('Y-m');

        // 全ユーザー取得
        $users = get_users(['fields' => ['ID']]);

        foreach ($users as $u) {
            $user_id = (int) $u->ID;

            // 当月の最新レポート（is_current=1）取得
            $args = [
                'post_type'      => 'gcrev_report',
                'posts_per_page' => 1,
                'meta_query'     => [
                    [
                        'key'   => '_gcrev_user_id',
                        'value' => $user_id,
                    ],
                    [
                        'key'   => '_gcrev_year_month',
                        'value' => $year_month,
                    ],
                    [
                        'key'   => '_gcrev_is_current',
                        'value' => 1,
                    ],
                ],
            ];

            $posts = get_posts($args);
            if (empty($posts)) {
                error_log("[GCREV] auto_finalize: user_id={$user_id} has no current report for {$year_month}, skipping.");
                if ( $log_id > 0 ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'skip', 'No current report' );
                }
                continue;
            }

            $post = $posts[0];
            $state = get_post_meta($post->ID, '_gcrev_report_state', true);

            // 既にfinalなら何もしない
            if ($state === 'final') {
                error_log("[GCREV] auto_finalize: user_id={$user_id} report already final, skipping.");
                if ( $log_id > 0 ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'skip', 'Already final' );
                }
                continue;
            }

            // 確定処理
            update_post_meta($post->ID, '_gcrev_report_state', 'final');
            update_post_meta($post->ID, '_gcrev_finalized_at', $now->format('Y-m-d H:i:s'));

            error_log("[GCREV] auto_finalize: SUCCESS user_id={$user_id}, post_id={$post->ID}, year_month={$year_month}");
            if ( $log_id > 0 ) {
                Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'success' );
            }
        }

        delete_transient( self::LOCK_REPORT_FINALIZE );
        error_log("[GCREV] auto_finalize_monthly_reports: DONE");

        // Cron Logger: ジョブ完了
        if ( $log_id > 0 ) {
            Gcrev_Cron_Logger::finish( $log_id, 'success' );
        }
    }
    // =========================================================
    // v2ダッシュボード統合用メソッド（2881行目の直後に追加）
    // =========================================================

    /**
     * v2ダッシュボード用KPI取得
     * 既存のget_dashboard_dataを活用
     * 
     * @param string $period 期間: last30, prev-month, prev-prev-month
     * @param int|null $user_id ユーザーID
     * @return array KPIデータ
     */
    /**
     * v2ダッシュボード用KPI取得
     *
     * [CACHE_FIRST] cache_first=1 の場合（last180/last365 想定）:
     *   1) transient を最初にチェック → あれば即 return（APIコール完全スキップ）
     *   2) 無い場合のみ API 取得 → 取得後 transient に保存 → return
     *   → キャッシュがあるのに API に行くルートは完全に排除される
     */
    public function get_dashboard_kpi(string $period = 'prev-month', ?int $user_id = null, int $cache_first = 0): array {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        // 期間変換
        $range_map = [
            'last30'         => 'last30',
            'last90'         => 'last90',
            'last180'        => 'last180',
            'last365'        => 'last365',
            'prev-month'     => 'previousMonth',
            'prev-prev-month'=> 'twoMonthsAgo'
        ];

        $range = $range_map[$period] ?? 'previousMonth';

        // --- [CACHE_FIRST] 長期間の場合：明示的にキャッシュ優先パスを通る ---
        // last90/last180/last365 は自動的に cache_first=1 扱いにする（JS側が付け忘れても安全）
        if ($cache_first || in_array($period, ['last90', 'last180', 'last365'], true)) {
            $cached = $this->dashboard_cache_get($user_id, $range);
            if ($cached !== null) {
                $cached = $this->inject_effective_cv_into_kpi($cached, $period, $user_id);
                return $cached;
            }
        }

        // cache_first 明示指定でキャッシュが空 → 重い API 呼び出しをスキップし空を返す
        // （呼び出し元の JS 側で非同期フォールバック取得する）
        if ( $cache_first ) {
            return [];
        }

        // 既存メソッドでデータ取得（WP_REST_Requestをモック）
        // ※ get_dashboard_data 内部にもキャッシュチェックがあるが、
        //    上の [CACHE_FIRST] ブロックで先にチェック済みなので
        //    ここに到達 = キャッシュ無し確定。二重チェックは安全側に倒す。
        $request = new WP_REST_Request('GET', '/gcrev_insights/v1/dashboard');
        $request->set_param('range', $range);
        
        $response = $this->get_dashboard_data($request);
        
        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            $result = $data['data'] ?? [];

            // [CACHE_FIRST] フォールバック取得時：長期間は追加でキャッシュ保存を保証
            if (in_array($period, ['last180', 'last365'], true) && !empty($result)) {
                $this->dashboard_cache_set($user_id, $range, $result);
            }

            $result = $this->inject_effective_cv_into_kpi($result, $period, $user_id);
            return $result;
        }
        return [];
    }

    /**
     * KPIレスポンスに実質CVデータを注入する
     * period が月単位（prev-month/prev-prev-month）の場合のみ適用
     */
    private function inject_effective_cv_into_kpi(array $data, string $period, int $user_id): array {
        $year_month = $this->period_to_year_month($period);
        if ($year_month === null) {
            return $data;
        }

        $effective = $this->get_effective_cv_monthly($year_month, $user_id);

        $data['conversions']  = (string)$effective['total'];
        $data['cv_source']    = $effective['source']; // 'hybrid' | 'ga4'
        $data['cv_detail']    = $effective['breakdown_manual'] ?? [];
        $data['ga4_cv']       = $effective['components']['ga4_total'];
        $data['effective_cv'] = $effective; // 全データ（テンプレート・スコア計算で利用）

        // --- CV増減（trends.conversions）も実質CVベースで再計算 ---
        $comp_period = ($period === 'prev-month' || $period === 'previousMonth')
            ? 'prev-prev-month'
            : null;
        $comp_ym = $comp_period ? $this->period_to_year_month($comp_period) : null;

        if ($comp_ym !== null) {
            $comp_effective = $this->get_effective_cv_monthly($comp_ym, $user_id);
            $cur = (float)$effective['total'];
            $prv = (float)$comp_effective['total'];

            if (abs($prv) < 0.0001) {
                $trend = (abs($cur) < 0.0001)
                    ? ['value' => 0, 'text' => '±0.0%', 'color' => '#666']
                    : ['value' => 100, 'text' => '+∞', 'color' => '#3b82f6'];
            } else {
                $val  = (($cur - $prv) / $prv) * 100.0;
                $text = ($val > 0 ? '+' : '') . number_format($val, 1) . '%';
                $color = $val > 0 ? '#3b82f6' : ($val < 0 ? '#ef4444' : '#666');
                $trend = ['value' => $val, 'text' => $text, 'color' => $color];
            }

            if (!isset($data['trends'])) {
                $data['trends'] = [];
            }
            $data['trends']['conversions'] = $trend;
        }

        return $data;
    }

    /**
     * 月次レポート取得（v2ダッシュボード用）
     * HTMLからテキスト抽出版
     * 
     * @param int $year 年
     * @param int $month 月
     * @param int|null $user_id ユーザーID
     * @return array|null レポートデータ
     */
    public function get_monthly_ai_report(int $year, int $month, ?int $user_id = null): ?array {
        return $this->report_service->get_monthly_ai_report($year, $month, $user_id);
    }


    /**
     * 月次レポート手動生成（v2ダッシュボード用）
     * 
     * @param int|null $user_id ユーザーID
     * @param int|null $year 年
     * @param int|null $month 月
     * @return array 生成結果
     */
    public function generate_monthly_report_manual(?int $user_id = null, ?int $year = null, ?int $month = null): array {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        // デフォルトは前月
        if (!$year || !$month) {
            $tz = wp_timezone();
            $prev = new DateTimeImmutable('first day of last month', $tz);
            $year = intval($prev->format('Y'));
            $month = intval($prev->format('n'));
        }

        $year_month = sprintf('%04d-%02d', $year, $month);

        error_log("[GCREV] generate_monthly_report_manual: user_id={$user_id}, year_month={$year_month}");

        // 既存レポート数確認
        $args = [
            'post_type'      => 'gcrev_report',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => '_gcrev_user_id',
                    'value' => $user_id,
                ],
                [
                    'key'   => '_gcrev_year_month',
                    'value' => $year_month,
                ],
            ],
        ];

        $existing = get_posts($args);
        $current_version = count($existing);

        // 10回上限チェック
        if ($current_version >= 10) {
            return [
                'success' => false,
                'message' => 'このレポートは生成上限（10回）に達しています'
            ];
        }

        // クライアント情報取得
        $client_info = [
            'site_url'      => get_user_meta($user_id, 'report_site_url', true),
            'target'        => get_user_meta($user_id, 'report_target', true),
            'issue'         => get_user_meta($user_id, 'report_issue', true),
            'goal_monthly'  => get_user_meta($user_id, 'report_goal_monthly', true),
            'focus_numbers' => get_user_meta($user_id, 'report_focus_numbers', true),
            'current_state' => get_user_meta($user_id, 'report_current_state', true),
            'goal_main'     => get_user_meta($user_id, 'report_goal_main', true),
        ];

        if (empty($client_info['site_url'])) {
            return [
                'success' => false,
                'message' => 'クライアント情報が未設定です'
            ];
        }

        // -------------------------------------------------
        // GA4プロパティ設定チェック
        // -------------------------------------------------
        $prev2_check = $this->has_prev2_data($user_id);
        if (!$prev2_check['available']) {
            error_log("[GCREV] generate_monthly_report_manual: GA4_NOT_READY for user_id={$user_id}: " . ($prev2_check['reason'] ?? ''));
            return [
                'success' => false,
                'code'    => 'NO_PREV2_DATA',
                'message' => $prev2_check['reason'] ?? 'GA4プロパティの設定を確認してください。',
            ];
        }

        try {
            // 既存のgenerate_reportロジックを呼び出し
            $config = $this->config->get_user_config($user_id);

            // 前月・前々月データ取得
            $prev_data = $this->fetch_dashboard_data_internal($config, 'previousMonth');
            $two_data = $this->fetch_dashboard_data_internal($config, 'twoMonthsAgo');

            // ターゲットエリア判定
            $target_area = $this->generator->detect_target_area((string)($client_info['target'] ?? ''));

            // -------------------------------------------------
            // 追加：実質CV判定 → client_info に注入（AIレポート生成で使用）
            // -------------------------------------------------
            if (!empty($year_month)) {
                $eff = $this->get_effective_cv_monthly($year_month, $user_id);
                $client_info['effective_cv'] = [
                    'source'     => $eff['source'],
                    'cv'         => $eff['total'],
                    'has_actual' => ($eff['source'] !== 'ga4'),
                    'detail'     => $eff['breakdown_manual'] ?? [],
                    'components' => $eff['components'],
                    'daily'      => $eff['daily'],
                ];
                error_log("[GCREV] generate_monthly_report_manual: effective_cv source={$eff['source']}, total={$eff['total']}");
            }

            // レポートHTML生成
            $sections_html = $this->generator->generate_report_multi_pass($prev_data, $two_data, $client_info, $target_area);

            // Key events取得
            $ga4_id = $config['ga4_id'];
            $prev_start = (string)($prev_data['current_period']['start'] ?? '');
            $prev_end = (string)($prev_data['current_period']['end'] ?? '');
            $two_start = (string)($two_data['current_period']['start'] ?? '');
            $two_end = (string)($two_data['current_period']['end'] ?? '');

            $prev_key_events = ($prev_start && $prev_end) ? $this->ga4->fetch_ga4_key_events($ga4_id, $prev_start, $prev_end) : [];
            $two_key_events = ($two_start && $two_end) ? $this->ga4->fetch_ga4_key_events($ga4_id, $two_start, $two_end) : [];
            $selected_events = $this->ga4->select_report_key_events($prev_key_events, $two_key_events);

            // 最終HTML組み立て
            $report_html = $this->generator->build_sample_layout_report_html(
                $prev_data,
                $two_data,
                $client_info,
                $sections_html,
                $target_area,
                $selected_events,
                $prev_key_events,
                $two_key_events
            );

            // === 初心者向けモード：全文リライト（Markdown出力） ===
            $beginner_markdown2 = '';
            if (($client_info['output_mode'] ?? 'normal') === 'easy' && !empty($year_month)) {
                $beginner_markdown2 = $this->generator->rewrite_report_for_beginner(
                    $sections_html,
                    $target_area,
                    $user_id,
                    $year_month
                );
            }

            // 履歴保存
            $highlights = $this->highlights_mod->extract_highlights_from_html($report_html, $user_id);
            $this->repo->save_report_to_history($user_id, $report_html, $client_info, 'manual', $year_month, $highlights, $beginner_markdown2);

            // === インフォグラフィックJSON生成・保存 ===
            try {
                $infographic = $this->generate_infographic_json($prev_data, $two_data, $client_info, $user_id);
                if ($infographic) {
                    $this->save_monthly_infographic($year, $month, $user_id, $infographic);
                    error_log("[GCREV] generate_monthly_report_manual: Infographic saved for user_id={$user_id}, {$year}-{$month}");
                } else {
                    error_log("[GCREV] generate_monthly_report_manual: Infographic generation returned null for user_id={$user_id}");
                }
            } catch (\Exception $ig_e) {
                // インフォグラフィック失敗はレポート本体には影響させない
                error_log("[GCREV] generate_monthly_report_manual: Infographic error: " . $ig_e->getMessage());
            }

            $result = [
                'success' => true,
                'message' => 'レポートを生成しました',
                'version' => $current_version + 1,
            ];
            if ($beginner_markdown2 !== '') {
                $result['beginner_markdown'] = $beginner_markdown2;
            }

            return $result;

        } catch (Exception $e) {
            error_log("[GCREV] generate_monthly_report_manual: Error - " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'レポートの生成に失敗しました: ' . $e->getMessage()
            ];
        }
    }

    /**
     * REST: v2ダッシュボード用KPI取得
     */
    public function rest_get_dashboard_kpi(WP_REST_Request $request): WP_REST_Response {
        $period      = $request->get_param('period') ?? 'prev-month';
        $cache_first = $request->get_param('cache_first') ?? '0';
        $user_id     = get_current_user_id();

        try {
            $kpi_data = $this->get_dashboard_kpi($period, $user_id, (int)$cache_first);

            return new WP_REST_Response([
                'success' => true,
                'data'    => $kpi_data,
            ], 200);

        } catch (\Exception $e) {
            error_log("[GCREV] REST get_dashboard_kpi ERROR: " . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * REST: v2ダッシュボード用レポート手動生成
     */
    public function rest_generate_report_manual(WP_REST_Request $request): WP_REST_Response {
        $year = $request->get_param('year');
        $month = $request->get_param('month');
        $user_id = get_current_user_id();

        error_log("[GCREV] REST generate_report_manual: user_id={$user_id}, year={$year}, month={$month}");

        try {
            $result = $this->generate_monthly_report_manual($user_id, $year, $month);

            if ($result['success']) {
                return new WP_REST_Response($result, 200);
            } else {
                return new WP_REST_Response($result, 400);
            }

        } catch (\Exception $e) {
            error_log("[GCREV] REST generate_report_manual ERROR: " . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * REST: 前々月データ存在チェック
     */
    public function rest_check_prev2_data(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $result  = $this->has_prev2_data($user_id);

        return new WP_REST_Response([
            'success'   => true,
            'available' => $result['available'],
            'is_zero'   => $result['is_zero'] ?? false,
            'reason'    => $result['reason'] ?? null,
            'period'    => $result['period'] ?? null,
            'code'      => $result['available'] ? null : 'NO_PREV2_DATA',
        ], 200);
    }

    /**
     * REST: 流入元分析データ取得
     */
    public function rest_get_source_analysis(WP_REST_Request $request): WP_REST_Response {
        $period = $request->get_param('period') ?? 'previousMonth';
        // period-selector互換: 短い値を既存の内部表現に正規化
        if ($period === 'prev-month') {
            $period = 'previousMonth';
        } elseif ($period === 'prev-prev-month') {
            $period = 'twoMonthsAgo';
        }
        $user_id = get_current_user_id();

        error_log("[GCREV] REST get_source_analysis: user_id={$user_id}, period={$period}");

        try {
            $source_data = $this->get_source_analysis($period, $user_id);

            // レスポンスのトップ階層にも期間情報を配置（キャッシュHIT時も表示できるように）
            return new WP_REST_Response([
                'success'            => true,
                'data'               => $source_data,
                'current_period'     => $source_data['current_period'] ?? null,
                'comparison_period'  => $source_data['comparison_period'] ?? null,
                'current_range_label'=> $source_data['current_range_label'] ?? null,
                'compare_range_label'=> $source_data['compare_range_label'] ?? null,
            ], 200);

        } catch (\Exception $e) {
            error_log("[GCREV] REST get_source_analysis ERROR: " . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * REST API: 地域別アクセス分析データ取得
     */
    public function rest_get_region_analysis(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $user_id = get_current_user_id();
            if (!$user_id) {
                return new \WP_REST_Response(['error' => 'ログインが必要です'], 401);
            }

            $period = $request->get_param('period') ?? 'prev-month';
            
            // ユーザー設定取得
            $config = $this->config->get_user_config($user_id);
            $ga4_id = $config['ga4_id'];
            
            // 期間計算
            $dates = $this->dates->calculate_period_dates($period);
            $comparison = $this->dates->calculate_comparison_dates($period);

            // キャッシュ
            $cache_key = "gcrev_region_{$user_id}_{$period}_" . md5("{$dates['start']}_{$dates['end']}");
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                error_log("[GCREV] Region analysis cache HIT: {$cache_key}");
                return new \WP_REST_Response($cached, 200);
            }

            // 地域別データ取得
            $regions_detail = $this->ga4->fetch_region_details($ga4_id, $dates['start'], $dates['end']);
            $regions_prev_detail = $this->ga4->fetch_region_details($ga4_id, $comparison['start'], $comparison['end']);
            
            // 前期比の変動を計算
            $regions_with_change = $this->calculate_region_changes($regions_detail, $regions_prev_detail);
            
            $result = [
                'success'         => true,
                'period'          => $period,
                'period_display'  => $dates['display'] ?? '',
                'regions_detail'  => $regions_with_change,
            ];

            // キャッシュ保存（24時間）
            set_transient($cache_key, $result, 86400);

            return new \WP_REST_Response($result, 200);
            
        } catch (\Exception $e) {
            error_log('地域別分析エラー: ' . $e->getMessage());
            return new \WP_REST_Response([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * REST API: 地域別 月別推移データ取得（直近12ヶ月）
     */
    public function rest_get_region_trend(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $user_id = get_current_user_id();
            if (!$user_id) {
                return new \WP_REST_Response(['error' => 'ログインが必要です'], 401);
            }

            $area   = $request->get_param('area');
            $months = (int)($request->get_param('months') ?? 12);
            $metric = $request->get_param('metric') ?? 'sessions';

            // ユーザー設定取得
            $config = $this->config->get_user_config($user_id);
            $ga4_id = $config['ga4_id'];

            // キャッシュキー
            $cache_key = sprintf(
                'gcrev_region_trend_%d_%s_%d_%s',
                $user_id,
                md5($area),
                $months,
                $metric
            );
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                error_log("[GCREV] Region trend cache HIT: {$cache_key}");
                return new \WP_REST_Response($cached, 200);
            }

            // GA4から月別データ取得
            $trend = $this->ga4->fetch_region_monthly_trend($ga4_id, $area, $metric, $months);

            $result = [
                'success' => true,
                'area'    => $area,
                'metric'  => $metric,
                'months'  => $months,
                'labels'  => $trend['labels'],
                'values'  => $trend['values'],
            ];

            // キャッシュ保存（24時間）
            set_transient($cache_key, $result, 86400);

            return new \WP_REST_Response($result, 200);

        } catch (\Exception $e) {
            error_log('[GCREV] Region trend error: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * REST API: ページ分析データ取得
     * パターン: rest_get_region_analysis と同一
     */
    public function rest_get_page_analysis(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $user_id = get_current_user_id();
            if (!$user_id) {
                return new \WP_REST_Response(['error' => 'ログインが必要です'], 401);
            }

            $period = $request->get_param('period') ?? 'prev-month';

            // ユーザー設定取得
            $config = $this->config->get_user_config($user_id);
            $ga4_id = $config['ga4_id'];
            $site_url = $config['gsc_url'] ?? '';

            // 期間計算（region と同じヘルパー利用）
            $dates = $this->dates->calculate_period_dates($period);
            $comparison = $this->dates->calculate_comparison_dates($period);

            // キャッシュ
            $cache_key = "gcrev_page_{$user_id}_{$period}_" . md5("{$dates['start']}_{$dates['end']}");
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                error_log("[GCREV] Page analysis cache HIT: {$cache_key}");
                return new \WP_REST_Response($cached, 200);
            }

            // 現在期間のページ別詳細データ
            $pages_detail = $this->ga4->fetch_page_details($ga4_id, $dates['start'], $dates['end'], $site_url);
            // 比較期間のページ別PVデータ（変動計算用）
            $pages_prev_detail = $this->ga4->fetch_page_details($ga4_id, $comparison['start'], $comparison['end'], $site_url);

            // PV変動を計算
            $pages_with_change = $this->calculate_page_changes($pages_detail, $pages_prev_detail);

            $result = [
                'success'           => true,
                'data'              => [
                    'pages_detail'      => $pages_with_change,
                    'current_period'    => [
                        'start' => $dates['start'],
                        'end'   => $dates['end'],
                    ],
                    'comparison_period' => [
                        'start' => $comparison['start'],
                        'end'   => $comparison['end'],
                    ],
                    'current_range_label' => str_replace('-', '/', $dates['start']) . ' 〜 ' . str_replace('-', '/', $dates['end']),
                    'compare_range_label' => str_replace('-', '/', $comparison['start']) . ' 〜 ' . str_replace('-', '/', $comparison['end']),
                ],
            ];

            // キャッシュ保存（24時間）
            set_transient($cache_key, $result, 86400);

            return new \WP_REST_Response($result, 200);

        } catch (\Exception $e) {
            error_log('[GCREV] ページ分析エラー: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * REST API: キーワード分析データ取得
     * パターン: rest_get_page_analysis と同一
     * データソース: Google Search Console（既存の fetch_gsc_data を利用）
     */
    public function rest_get_keyword_analysis(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $user_id = get_current_user_id();
            if (!$user_id) {
                return new \WP_REST_Response(['error' => 'ログインが必要です'], 401);
            }

            $period = $request->get_param('period') ?? 'prev-month';

            // ユーザー設定取得
            $config = $this->config->get_user_config($user_id);
            $gsc_url = $config['gsc_url'];

            // 期間計算（page / region と同じヘルパー利用）
            $dates = $this->dates->calculate_period_dates($period);
            $comparison = $this->dates->calculate_comparison_dates($period);

            // キャッシュ
            $cache_key = "gcrev_keywords_{$user_id}_{$period}_" . md5("{$dates['start']}_{$dates['end']}");
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                error_log("[GCREV] Keyword analysis cache HIT: {$cache_key}");
                return new \WP_REST_Response($cached, 200);
            }

            // 現在期間のキーワードデータ（既存の fetch_gsc_data を利用）
            $current_gsc = $this->gsc->fetch_gsc_data($gsc_url, $dates['start'], $dates['end']);
            // 比較期間のキーワードデータ（順位変動計算用）
            $prev_gsc = $this->gsc->fetch_gsc_data($gsc_url, $comparison['start'], $comparison['end']);

            // 順位変動を計算
            $keywords_with_change = $this->calculate_keyword_changes(
                $current_gsc['keywords'] ?? [],
                $prev_gsc['keywords'] ?? []
            );

            $result = [
                'success'           => true,
                'data'              => [
                    'keywords_detail'   => $keywords_with_change,
                    'gsc_total'         => $current_gsc['total'] ?? [],
                    'current_period'    => [
                        'start' => $dates['start'],
                        'end'   => $dates['end'],
                    ],
                    'comparison_period' => [
                        'start' => $comparison['start'],
                        'end'   => $comparison['end'],
                    ],
                    'current_range_label' => str_replace('-', '/', $dates['start']) . ' 〜 ' . str_replace('-', '/', $dates['end']),
                    'compare_range_label' => str_replace('-', '/', $comparison['start']) . ' 〜 ' . str_replace('-', '/', $comparison['end']),
                ],
            ];

            // キャッシュ保存（24時間）
            set_transient($cache_key, $result, 86400);

            return new \WP_REST_Response($result, 200);

        } catch (\Exception $e) {
            error_log('[GCREV] キーワード分析エラー: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * REST: MEOロケーション情報の保存
     * ユーザーが入力した住所・場所名・検索範囲を user_meta に保存し、
     * GBP API を使ってロケーションIDを自動取得する
     */
    public function rest_save_meo_location(\WP_REST_Request $request): \WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_REST_Response(['success' => false, 'message' => 'ログインが必要です'], 401);
        }

        $params  = $request->get_json_params();
        $name    = sanitize_text_field($params['name'] ?? '');
        $address = sanitize_text_field($params['address'] ?? '');
        $radius  = (int) ($params['radius'] ?? 1000);

        if (empty($address)) {
            return new \WP_REST_Response(['success' => false, 'message' => '住所は必須です'], 400);
        }

        if ($radius < 100) $radius = 100;
        if ($radius > 50000) $radius = 50000;

        // user_meta に保存
        update_user_meta($user_id, '_gcrev_gbp_location_name',    $name);
        update_user_meta($user_id, '_gcrev_gbp_location_address', $address);
        update_user_meta($user_id, '_gcrev_gbp_location_radius',  $radius);

        // ロケーションIDはGBP OAuth接続時に自動取得済みの場合そのまま使う
        // 未取得の場合はGBP Performance APIで直接テストして検証する
        $existing_loc_id = get_user_meta($user_id, '_gcrev_gbp_location_id', true);
        if (empty($existing_loc_id) || strpos($existing_loc_id, 'pending_') === 0) {
            // 既存IDなし → pending として保存
            update_user_meta($user_id, '_gcrev_gbp_location_id', 'pending_' . $user_id);
        }

        error_log("[GCREV][GBP] Location saved: user_id={$user_id}, name={$name}, address={$address}, radius={$radius}");

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'ロケーションを登録しました',
        ], 200);
    }

    /**
     * REST: MEOロケーションIDの手動設定
     */
    public function rest_set_meo_location_id(\WP_REST_Request $request): \WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_REST_Response(['success' => false, 'message' => 'ログインが必要です'], 401);
        }

        $params      = $request->get_json_params();
        $location_id = sanitize_text_field($params['location_id'] ?? '');

        if (empty($location_id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'ロケーションIDは必須です'], 400);
        }

        // 数字のみの場合は locations/ プレフィックスを付与
        if (preg_match('/^\d+$/', $location_id)) {
            $location_id = 'locations/' . $location_id;
        }

        // locations/ 形式であることを確認
        if (strpos($location_id, 'locations/') !== 0) {
            return new \WP_REST_Response(['success' => false, 'message' => 'ロケーションIDの形式が正しくありません'], 400);
        }

        // GBP Performance API で疎通テスト
        $access_token = $this->gbp_get_access_token($user_id);
        $test_ok = false;
        if (!empty($access_token)) {
            $test_url = "https://businessprofileperformance.googleapis.com/v1/{$location_id}:fetchMultiDailyMetricsTimeSeries";
            $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
            $start = $today->modify('-7 days');
            $test_body = [
                'dailyMetrics' => ['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'],
                'dailyRange'   => [
                    'startDate' => ['year' => (int)$start->format('Y'), 'month' => (int)$start->format('n'), 'day' => (int)$start->format('j')],
                    'endDate'   => ['year' => (int)$today->format('Y'), 'month' => (int)$today->format('n'), 'day' => (int)$today->format('j')],
                ],
            ];
            $test_response = wp_remote_post($test_url, [
                'headers' => ['Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json'],
                'body'    => wp_json_encode($test_body),
                'timeout' => 15,
            ]);
            $test_status = wp_remote_retrieve_response_code($test_response);
            $test_ok     = ($test_status === 200);
            error_log("[GCREV][GBP] Location ID test: {$location_id} → HTTP {$test_status}");
        }

        update_user_meta($user_id, '_gcrev_gbp_location_id', $location_id);

        // MEOキャッシュ削除
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '%gcrev_meo_' . $user_id . '%'
        ));

        return new \WP_REST_Response([
            'success'  => true,
            'message'  => $test_ok ? 'ロケーションIDを設定しました（API接続テスト成功）' : 'ロケーションIDを保存しました（API接続テストに失敗しました。IDが正しいか確認してください）',
            'verified' => $test_ok,
        ], 200);
    }

    // =========================================================
    // GBP ロケーション自動取得
    // =========================================================

    /**
     * GBP Account Management / Business Information API からロケーション一覧を取得
     *
     * @param int $user_id
     * @return array ['success' => bool, 'locations' => array, 'message' => string]
     */
    public function gbp_list_locations(int $user_id): array {
        $access_token = $this->gbp_get_access_token($user_id);
        if (empty($access_token)) {
            return ['success' => false, 'locations' => [], 'message' => 'アクセストークンを取得できません。再接続してください。'];
        }

        // Step 1: アカウント一覧取得
        $accounts_url  = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';
        $accounts_resp = wp_remote_get($accounts_url, [
            'headers' => ['Authorization' => 'Bearer ' . $access_token],
            'timeout' => 30,
        ]);

        if (is_wp_error($accounts_resp)) {
            $err = $accounts_resp->get_error_message();
            error_log("[GCREV][GBP] Accounts API error: {$err}");
            return ['success' => false, 'locations' => [], 'message' => 'Google API接続エラー: ' . $err];
        }

        $accounts_status = wp_remote_retrieve_response_code($accounts_resp);
        if ($accounts_status !== 200) {
            $accounts_body = wp_remote_retrieve_body($accounts_resp);
            error_log("[GCREV][GBP] Accounts API HTTP {$accounts_status}: {$accounts_body}");
            return ['success' => false, 'locations' => [], 'message' => "アカウント一覧の取得に失敗しました（HTTP {$accounts_status}）。Google Cloud Console で My Business Account Management API が有効か確認してください。"];
        }

        $accounts_data = json_decode(wp_remote_retrieve_body($accounts_resp), true);
        $accounts      = $accounts_data['accounts'] ?? [];

        if (empty($accounts)) {
            error_log("[GCREV][GBP] No accounts found for user_id={$user_id}");
            return ['success' => true, 'locations' => [], 'message' => 'Googleビジネスプロフィールのアカウントが見つかりません。'];
        }

        // Step 2: 各アカウントのロケーション一覧取得
        $all_locations = [];
        foreach ($accounts as $account) {
            $account_name = $account['name'] ?? '';
            if (empty($account_name)) {
                continue;
            }

            $locations_url  = "https://mybusinessbusinessinformation.googleapis.com/v1/{$account_name}/locations";
            $locations_url .= '?' . http_build_query(['readMask' => 'name,title,storefrontAddress'], '', '&');

            $locations_resp = wp_remote_get($locations_url, [
                'headers' => ['Authorization' => 'Bearer ' . $access_token],
                'timeout' => 30,
            ]);

            if (is_wp_error($locations_resp)) {
                error_log("[GCREV][GBP] Locations API error for {$account_name}: " . $locations_resp->get_error_message());
                continue;
            }

            $loc_status = wp_remote_retrieve_response_code($locations_resp);
            if ($loc_status !== 200) {
                $loc_body = wp_remote_retrieve_body($locations_resp);
                error_log("[GCREV][GBP] Locations API HTTP {$loc_status} for {$account_name}: {$loc_body}");
                continue;
            }

            $loc_data   = json_decode(wp_remote_retrieve_body($locations_resp), true);
            $locations  = $loc_data['locations'] ?? [];

            foreach ($locations as $loc) {
                $loc_name = $loc['name'] ?? '';
                if (empty($loc_name)) {
                    continue;
                }

                // 住所を結合
                $addr_parts = [];
                $addr       = $loc['storefrontAddress'] ?? [];
                if (!empty($addr['regionCode']) && $addr['regionCode'] !== 'JP') {
                    $addr_parts[] = $addr['regionCode'];
                }
                if (!empty($addr['administrativeArea'])) {
                    $addr_parts[] = $addr['administrativeArea'];
                }
                if (!empty($addr['locality'])) {
                    $addr_parts[] = $addr['locality'];
                }
                foreach (($addr['addressLines'] ?? []) as $line) {
                    $addr_parts[] = $line;
                }
                $address_str = implode('', $addr_parts);

                $all_locations[] = [
                    'location_id' => $loc_name,
                    'title'       => $loc['title'] ?? '',
                    'address'     => $address_str,
                ];
            }
        }

        error_log("[GCREV][GBP] Found " . count($all_locations) . " location(s) for user_id={$user_id}");

        return [
            'success'   => true,
            'locations' => $all_locations,
            'message'   => empty($all_locations) ? 'ロケーションが見つかりません。Googleビジネスプロフィールに店舗が登録されているか確認してください。' : '',
        ];
    }

    /**
     * REST: GBPロケーション一覧を取得
     */
    public function rest_get_gbp_locations(\WP_REST_Request $request): \WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_REST_Response(['success' => false, 'message' => 'ログインが必要です'], 401);
        }

        $result = $this->gbp_list_locations($user_id);

        return new \WP_REST_Response($result, $result['success'] ? 200 : 500);
    }

    /**
     * REST: GBPロケーションを選択して保存
     */
    public function rest_select_gbp_location(\WP_REST_Request $request): \WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_REST_Response(['success' => false, 'message' => 'ログインが必要です'], 401);
        }

        $params      = $request->get_json_params();
        $location_id = sanitize_text_field($params['location_id'] ?? '');
        $title       = sanitize_text_field($params['title'] ?? '');
        $address     = sanitize_text_field($params['address'] ?? '');

        if (empty($location_id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'ロケーションIDは必須です'], 400);
        }

        // locations/ 形式であることを確認
        if (strpos($location_id, 'locations/') !== 0) {
            return new \WP_REST_Response(['success' => false, 'message' => 'ロケーションIDの形式が正しくありません'], 400);
        }

        // GBP Performance API で疎通テスト
        $access_token = $this->gbp_get_access_token($user_id);
        $test_ok = false;
        if (!empty($access_token)) {
            $test_url  = "https://businessprofileperformance.googleapis.com/v1/{$location_id}:fetchMultiDailyMetricsTimeSeries";
            $today     = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
            $start     = $today->modify('-7 days');
            $test_body = [
                'dailyMetrics' => ['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'],
                'dailyRange'   => [
                    'startDate' => ['year' => (int) $start->format('Y'), 'month' => (int) $start->format('n'), 'day' => (int) $start->format('j')],
                    'endDate'   => ['year' => (int) $today->format('Y'), 'month' => (int) $today->format('n'), 'day' => (int) $today->format('j')],
                ],
            ];
            $test_response = wp_remote_post($test_url, [
                'headers' => ['Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json'],
                'body'    => wp_json_encode($test_body),
                'timeout' => 15,
            ]);
            $test_status = wp_remote_retrieve_response_code($test_response);
            $test_ok     = ($test_status === 200);
            error_log("[GCREV][GBP] Select location test: {$location_id} → HTTP {$test_status}");
        }

        // user_meta に保存
        update_user_meta($user_id, '_gcrev_gbp_location_id',      $location_id);
        update_user_meta($user_id, '_gcrev_gbp_location_name',    $title);
        update_user_meta($user_id, '_gcrev_gbp_location_address', $address);

        // 検索範囲が未設定ならデフォルト
        $existing_radius = (int) get_user_meta($user_id, '_gcrev_gbp_location_radius', true);
        if ($existing_radius < 100) {
            update_user_meta($user_id, '_gcrev_gbp_location_radius', 1000);
        }

        // MEOキャッシュ削除
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '%gcrev_meo_' . $user_id . '%'
        ));

        error_log("[GCREV][GBP] Location selected: user_id={$user_id}, location_id={$location_id}, title={$title}");

        return new \WP_REST_Response([
            'success'  => true,
            'message'  => $test_ok ? 'ロケーションを設定しました（API接続テスト成功）' : 'ロケーションを保存しました（API接続テストに失敗しました。データ取得にはBusiness Profile Performance APIの有効化が必要です）',
            'verified' => $test_ok,
        ], 200);
    }


    /**
     * REST: MEOダッシュボードデータ取得
     * パターン: rest_get_keyword_analysis と同一（キャッシュ・期間計算・エラーハンドリング）
     */
    public function rest_get_meo_dashboard(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $user_id = get_current_user_id();
            if (!$user_id) {
                return new \WP_REST_Response(['success' => false, 'message' => 'ログインが必要です'], 401);
            }

            $period = $request->get_param('period') ?? 'prev-month';

            $location_id = get_user_meta($user_id, '_gcrev_gbp_location_id', true);
            if (empty($location_id)) {
                return new \WP_REST_Response(['success' => false, 'message' => 'GBPロケーションが未設定です'], 400);
            }

            // pending_ IDの場合はGBP API呼び出し不可 → 空データで正常返却
            $is_pending = (strpos($location_id, 'pending_') === 0);

            $access_token = $this->gbp_get_access_token($user_id);
            if (empty($access_token) && !$is_pending) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'GBPアクセストークンの取得に失敗しました。再認証が必要です。'
                ], 401);
            }

            // 期間計算（既存ヘルパー利用）
            $dates      = $this->dates->calculate_period_dates($period);
            $comparison = $this->dates->calculate_comparison_dates($period);

            // キャッシュ（既存パターンと同一）
            $date_hash = md5("{$dates['start']}_{$dates['end']}");
            $cache_key = "gcrev_meo_{$user_id}_{$period}_{$date_hash}";
            $cached    = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                error_log("[GCREV][MEO] Cache HIT: {$cache_key}");
                return new \WP_REST_Response($cached, 200);
            }

            // GBP Performance API
            if ($is_pending) {
                // pending_ IDの場合は空データで返却（ダッシュボードUIは表示する）
                $current_metrics = $this->gbp_empty_metrics();
                $prev_metrics    = $this->gbp_empty_metrics();
                $daily_metrics   = [];
                $keywords        = [];
                error_log("[GCREV][MEO] Pending location, returning empty data: user_id={$user_id}");
            } else {
                $current_metrics = $this->gbp_fetch_performance_metrics($access_token, $location_id, $dates['start'], $dates['end']);
                $prev_metrics    = $this->gbp_fetch_performance_metrics($access_token, $location_id, $comparison['start'], $comparison['end']);
                $daily_metrics   = $this->gbp_fetch_daily_metrics($access_token, $location_id, $dates['start'], $dates['end']);

                // 検索キーワード
                $current_kw = $this->gbp_fetch_search_keywords($access_token, $location_id, $dates['start'], $dates['end']);
                $prev_kw    = $this->gbp_fetch_search_keywords($access_token, $location_id, $comparison['start'], $comparison['end']);
                $keywords   = $this->gbp_merge_keyword_changes($current_kw, $prev_kw);
            }

            $result = [
                'success'             => true,
                'metrics'             => $current_metrics,
                'metrics_previous'    => $prev_metrics,
                'daily_metrics'       => $daily_metrics,
                'search_keywords'     => $keywords,
                'current_range_label' => str_replace('-', '/', $dates['start']) . ' 〜 ' . str_replace('-', '/', $dates['end']),
                'compare_range_label' => str_replace('-', '/', $comparison['start']) . ' 〜 ' . str_replace('-', '/', $comparison['end']),
                'current_period'      => ['start' => $dates['start'], 'end' => $dates['end']],
                'comparison_period'   => ['start' => $comparison['start'], 'end' => $comparison['end']],
            ];

            set_transient($cache_key, $result, 86400);
            error_log("[GCREV][MEO] Data fetched: user_id={$user_id}, period={$period}");
            return new \WP_REST_Response($result, 200);

        } catch (\Exception $e) {
            error_log("[GCREV][MEO] Error: " . $e->getMessage());
            return new \WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ページ別データに前期比の PV 変動を追加
     * パターン: calculate_region_changes と同一
     */
    private function calculate_page_changes(array $current, array $previous): array {
        // 前期データをパスでマップ化
        $prev_map = [];
        foreach ($previous as $page) {
            $prev_map[$page['page']] = $page;
        }

        $result = [];
        foreach ($current as $page) {
            $path = $page['page'];

            if (isset($prev_map[$path])) {
                $prev_pv = $prev_map[$path]['pageViews'];
                if ($prev_pv > 0) {
                    $page['pvChange'] = round((($page['pageViews'] - $prev_pv) / $prev_pv) * 100.0, 1);
                } else {
                    $page['pvChange'] = 0.0;
                }
            } else {
                // 前期にデータがない→新規ページ
                $page['pvChange'] = null;
            }

            $result[] = $page;
        }

        return $result;
    }

    /**
     * キーワードデータに前期比の掲載順位変動を追加
     * パターン: calculate_page_changes と同一
     */
    private function calculate_keyword_changes(array $current, array $previous): array {
        // 前期データをクエリでマップ化
        $prev_map = [];
        foreach ($previous as $kw) {
            $prev_map[$kw['query']] = $kw;
        }

        $result = [];
        foreach ($current as $kw) {
            $query = $kw['query'];

            if (isset($prev_map[$query])) {
                $prev_pos = $prev_map[$query]['_position'] ?? 0;
                $curr_pos = $kw['_position'] ?? 0;
                if ($prev_pos > 0 && $curr_pos > 0) {
                    // 順位変動: 前期順位 - 今期順位 （マイナス=悪化、プラス=改善ではなく）
                    // positionChange > 0 → 順位が下がった（悪化）
                    // positionChange < 0 → 順位が上がった（改善）
                    $kw['positionChange'] = round($curr_pos - $prev_pos, 1);
                } else {
                    $kw['positionChange'] = 0.0;
                }
            } else {
                // 前期にデータがない→新規キーワード
                $kw['positionChange'] = null;
            }

            $result[] = $kw;
        }

        return $result;
    }

    /**
     * 地域別データに前期比の変動を追加
     */
    private function calculate_region_changes(array $current, array $previous): array {
        // 前期データをマップ化（地域名をキーに）
        $prev_map = [];
        foreach ($previous as $region) {
            $prev_map[$region['region']] = $region;
        }
        
        // 現期データに変動を追加
        $result = [];
        foreach ($current as $region) {
            $region_name = $region['region'];
            
            // 前期データがあれば変動を計算
            if (isset($prev_map[$region_name])) {
                $prev_sessions = $prev_map[$region_name]['sessions'];
                
                if ($prev_sessions > 0) {
                    $change_percent = (($region['sessions'] - $prev_sessions) / $prev_sessions) * 100.0;
                    $region['change'] = $change_percent;
                } else {
                    $region['change'] = 0.0;
                }
            } else {
                // 前期にデータがない場合は新規
                $region['change'] = 0.0;
            }
            
            $result[] = $region;
        }
        
        return $result;
    }

    /**
     * 流入元分析データ取得
     */
    private function get_source_analysis(string $period, int $user_id): array {
        $config = $this->config->get_user_config($user_id);
        $ga4_id = $config['ga4_id'];
        
        // 既存のget_date_rangeメソッドを使用
        $dates = $this->dates->get_date_range($period);
        $start_date = $dates['start'];
        $end_date = $dates['end'];
        
        // 比較期間（既存のget_comparison_rangeメソッドを使用）
        $comparison = $this->dates->get_comparison_range($start_date, $end_date);
        $prev_start_date = $comparison['start'];
        $prev_end_date = $comparison['end'];
        
        error_log("[GCREV] get_source_analysis: period={$period}, range={$start_date} to {$end_date}");
        
        // キャッシュキー
        $cache_key = "gcrev_source_{$user_id}_{$period}_" . md5("{$start_date}_{$end_date}");
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            error_log("[GCREV] Cache HIT: {$cache_key}");
            
            // キャッシュが古い形式（期間情報なし）の場合でも、ここで追加する
            if (!isset($cached['current_period'])) {
                $cached['current_period'] = [
                    'start' => $start_date,
                    'end'   => $end_date,
                ];
                $cached['comparison_period'] = [
                    'start' => $prev_start_date,
                    'end'   => $prev_end_date,
                ];
                // ラベル形式: YYYY/MM/DD 〜 YYYY/MM/DD
                $cached['current_range_label'] = str_replace('-', '/', $start_date) . ' 〜 ' . str_replace('-', '/', $end_date);
                $cached['compare_range_label'] = str_replace('-', '/', $prev_start_date) . ' 〜 ' . str_replace('-', '/', $prev_end_date);
            }
            
            return $cached;
        }
        
        try {
            // GA4データ取得
            $current_data = $this->ga4->fetch_source_data_from_ga4($ga4_id, $start_date, $end_date);
            $previous_data = $this->ga4->fetch_source_data_from_ga4($ga4_id, $prev_start_date, $prev_end_date);
            
            // データ整形
            $result = $this->format_source_analysis_data($current_data, $previous_data);
            
            // 期間情報を追加（period-display.js互換形式）
            $result['current_period'] = [
                'start' => $start_date,
                'end'   => $end_date,
            ];
            $result['comparison_period'] = [
                'start' => $prev_start_date,
                'end'   => $prev_end_date,
            ];
            
            // ラベル形式: YYYY/MM/DD 〜 YYYY/MM/DD
            $result['current_range_label'] = str_replace('-', '/', $start_date) . ' 〜 ' . str_replace('-', '/', $end_date);
            $result['compare_range_label'] = str_replace('-', '/', $prev_start_date) . ' 〜 ' . str_replace('-', '/', $prev_end_date);
            
            // キャッシュ保存（24時間）
            set_transient($cache_key, $result, 86400);
            
            return $result;
            
        } catch (\Exception $e) {
            error_log("[GCREV] get_source_analysis ERROR: " . $e->getMessage());
            throw $e;
        }
    }


    /**
     * 流入元データ整形
     */
    private function format_source_analysis_data(array $current, array $previous): array {
        // 総セッション数計算
        $total_sessions = array_sum(array_column($current['channels'], 'sessions'));
        $prev_total_sessions = array_sum(array_column($previous['channels'], 'sessions'));
        
        // チャネルサマリー整形
        $channels_summary = [];
        foreach ($current['channels'] as $channel) {
            $prev_channel = $this->find_channel_data($previous['channels'], $channel['channel']);
            $prev_sessions = $prev_channel['sessions'] ?? 0;
            
            $change_percent = 0;
            if ($prev_sessions > 0) {
                $change_percent = (($channel['sessions'] - $prev_sessions) / $prev_sessions) * 100;
            }
            
            $share = $total_sessions > 0 ? ($channel['sessions'] / $total_sessions * 100) : 0;
            
            $channels_summary[] = [
                'channel' => $channel['channel'],
                'sessions' => $channel['sessions'],
                'share' => $share,
                'change_percent' => $change_percent,
            ];
        }
        
        // 日別推移をChart.js形式に整形
        $daily_series = $this->format_daily_series_for_chart($current['daily_series']);
        
        return [
            'channels_summary' => $channels_summary,
            'sources_detail' => $current['sources'],
            'channels_daily_series' => $daily_series,
            'period_info' => [
                'total_sessions' => $total_sessions,
                'prev_total_sessions' => $prev_total_sessions,
            ]
        ];
    }

    /**
     * チャネルデータ検索
     */
    private function find_channel_data(array $channels, string $target_channel): array {
        foreach ($channels as $channel) {
            if ($channel['channel'] === $target_channel) {
                return $channel;
            }
        }
        return ['sessions' => 0];
    }

    /**
     * 日別データをChart.js形式に変換
     */
    private function format_daily_series_for_chart(array $daily_data): array {
        if (empty($daily_data)) {
            return ['labels' => [], 'datasets' => []];
        }
        
        // 日付ラベル作成
        $labels = [];
        foreach (array_keys($daily_data) as $date) {
            // YYYY-MM-DD -> M/D形式
            // ハイフンの有無をチェックして対応
            if (strpos($date, '-') !== false) {
                // YYYY-MM-DD 形式
                $parts = explode('-', $date);
                $formatted = ltrim($parts[1], '0') . '/' . ltrim($parts[2], '0');
            } else {
                // YYYYMMDD 形式（後方互換）
                $formatted = ltrim(substr($date, 4, 2), '0') . '/' . ltrim(substr($date, 6, 2), '0');
            }
            $labels[] = $formatted;
        }
        
        // チャネル名を収集
        $all_channels = [];
        foreach ($daily_data as $date_data) {
            foreach (array_keys($date_data) as $channel) {
                if (!in_array($channel, $all_channels)) {
                    $all_channels[] = $channel;
                }
            }
        }
        
        // 各チャネルのデータセット作成
        $datasets = [];
        foreach ($all_channels as $channel) {
            $data = [];
            foreach ($daily_data as $date_data) {
                $data[] = $date_data[$channel] ?? 0;
            }
            
            $datasets[] = [
                'label' => $channel,
                'data' => $data,
            ];
        }
        
        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }
    /**
     * レポート生成回数をリセット（管理者のみ）
     */
    public function reset_generation_count(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        
        // 前月の年月を取得（レポートは前月分として保存されるため）
        $tz = wp_timezone();
        $now = new DateTime('now', $tz);
        $prev_month = new DateTime('first day of last month', $tz);
        $year_month = $prev_month->format('Y-m');
        
        error_log("[GCREV] reset_generation_count: user_id={$user_id}, year_month={$year_month}");
        
        // 前月のレポートを削除（生成回数をリセット）
        $args = [
            'post_type'      => 'gcrev_report',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => '_gcrev_user_id',
                    'value' => $user_id,
                ],
                [
                    'key'   => '_gcrev_year_month',
                    'value' => $year_month,
                ]
            ]
        ];
        
        $report_ids = get_posts($args);
        $deleted_count = 0;
        
        foreach ($report_ids as $report_id) {
            wp_delete_post($report_id, true);
            $deleted_count++;
        }
        
        // レポートキャッシュも削除
        global $wpdb;
        $cache_deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_gcrev_report_%' 
             OR option_name LIKE '_transient_timeout_gcrev_report_%'"
        );
        
        error_log("[GCREV] Generation count reset complete: {$deleted_count} reports + {$cache_deleted} cache entries deleted for user {$user_id}, year_month={$year_month}");
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'レポートキャッシュと生成回数をリセットしました',
            'data' => [
                'deleted_reports' => $deleted_count,
                'deleted_cache' => $cache_deleted,
                'year_month' => $year_month
            ]
        ], 200);
} 
    /**
     * ダッシュボード用：月次ハイライト3項目を取得
     * テンプレート（page-dashboard.php）から呼ばれる公開メソッド
     *
     * @param int      $year    対象年
     * @param int      $month   対象月
     * @param int|null $user_id ユーザーID
     * @return array ['most_important'=>string, 'top_issue'=>string, 'opportunity'=>string]
     */
    public function get_monthly_highlights(int $year, int $month, ?int $user_id = null): array {
        return $this->report_service->get_monthly_highlights($year, $month, $user_id);
    }


    /**
     * ダッシュボード表示用 payload（テンプレから使用）
     * - 見た目を変えないため、payload形式は固定
     */
    public function get_dashboard_payload(int $year, int $month, int $user_id, ?array $infographic = null): array {
        return $this->dashboard_service->get_payload($year, $month, $user_id, $infographic);
    }

    // =========================================================
    // GBP (Google Business Profile) 連携メソッド
    // =========================================================

    /**
     * GBP接続状態を取得
     *
     * @param int $user_id
     * @return array ['connected' => bool, 'needs_reauth' => bool]
     */
    public function gbp_get_connection_status(int $user_id): array {
        $refresh_token = Gcrev_Crypto::decrypt( get_user_meta($user_id, '_gcrev_gbp_refresh_token', true) );

        // refresh_tokenが無い → 未接続
        if (empty($refresh_token)) {
            return ['connected' => false, 'needs_reauth' => false];
        }

        // access_tokenの有効期限チェック
        $expires = (int) get_user_meta($user_id, '_gcrev_gbp_token_expires', true);
        if ($expires > 0 && $expires < time()) {
            // 期限切れ → リフレッシュ試行
            $refreshed = $this->gbp_refresh_access_token($user_id);
            if (!$refreshed) {
                return ['connected' => true, 'needs_reauth' => true];
            }
        }

        return ['connected' => true, 'needs_reauth' => false];
    }

    /**
     * GBP OAuth認可URLを生成
     *
     * @param int $user_id
     * @return string 認可URL
     */
    public function gbp_get_auth_url(int $user_id): string {
        $client_id    = $this->config->get_gbp_client_id();
        $redirect_uri = home_url('/meo/gbp-oauth-callback/');

        if ($client_id === '') {
            error_log('[GCREV][GBP] Missing client_id');
            return '';
        }

        $state = wp_generate_password(32, false);
        update_user_meta($user_id, '_gcrev_gbp_oauth_state', $state);

        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/business.manage',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }


    /**
     * OAuthコールバック：認可コードをトークンに交換して保存
     *
     * @param int    $user_id
     * @param string $code   認可コード
     * @param string $state  CSRF検証用state
     * @return array ['success' => bool, 'message' => string]
     */
    public function gbp_exchange_code_and_store_tokens(int $user_id, string $code, string $state): array {
        // state検証
        $saved_state = get_user_meta($user_id, '_gcrev_gbp_oauth_state', true);
        if (empty($saved_state) || !hash_equals($saved_state, $state)) {
            error_log("[GCREV][GBP] State mismatch: user_id={$user_id}");
            return ['success' => false, 'message' => '不正なリクエストです（state不一致）。もう一度お試しください。'];
        }

        // state使い捨て
        delete_user_meta($user_id, '_gcrev_gbp_oauth_state');

        $client_id     = $this->config->get_gbp_client_id();
        $client_secret = $this->config->get_gbp_client_secret();
        $redirect_uri  = home_url('/meo/gbp-oauth-callback/');

        if ($client_id === '' || $client_secret === '') {
            return ['success' => false, 'message' => 'GBP OAuth設定（Client ID/Secret）が未設定です。'];
        }


        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log("[GCREV][GBP] Token exchange WP_Error: " . $response->get_error_message());
            return ['success' => false, 'message' => 'Google APIとの通信に失敗しました。'];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            $error_desc = $body['error_description'] ?? ($body['error'] ?? 'unknown');
            error_log("[GCREV][GBP] Token exchange failed: {$error_desc}");
            return ['success' => false, 'message' => 'トークンの取得に失敗しました: ' . $error_desc];
        }

        // user_metaに保存（暗号化）
        update_user_meta($user_id, '_gcrev_gbp_access_token',  Gcrev_Crypto::encrypt($body['access_token']));
        update_user_meta($user_id, '_gcrev_gbp_token_expires', time() + (int)($body['expires_in'] ?? 3600));

        if (!empty($body['refresh_token'])) {
            update_user_meta($user_id, '_gcrev_gbp_refresh_token', Gcrev_Crypto::encrypt($body['refresh_token']));
        }

        error_log("[GCREV][GBP] Tokens stored (encrypted) for user_id={$user_id}");
        return ['success' => true, 'message' => ''];
    }

    /**
     * アクセストークンをリフレッシュ
     *
     * @param int $user_id
     * @return bool 成功ならtrue
     */
    private function gbp_refresh_access_token(int $user_id): bool {
        $refresh_token_raw = get_user_meta($user_id, '_gcrev_gbp_refresh_token', true);
        $refresh_token     = Gcrev_Crypto::decrypt($refresh_token_raw);
        if (empty($refresh_token)) {
            return false;
        }

        $client_id     = $this->config->get_gbp_client_id();
        $client_secret = $this->config->get_gbp_client_secret();

        if ($client_id === '' || $client_secret === '') {
            error_log('[GCREV][GBP] Missing client_id/secret');
            return false;
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'refresh_token' => $refresh_token,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'grant_type'    => 'refresh_token',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log("[GCREV][GBP] Refresh WP_Error: " . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            error_log("[GCREV][GBP] Refresh failed: " . ($body['error'] ?? 'unknown'));
            return false;
        }

        update_user_meta($user_id, '_gcrev_gbp_access_token',  Gcrev_Crypto::encrypt($body['access_token']));
        update_user_meta($user_id, '_gcrev_gbp_token_expires', time() + (int)($body['expires_in'] ?? 3600));

        // Google がリフレッシュ時に新しい refresh_token を返す場合がある（ローテーション対応）
        if ( ! empty( $body['refresh_token'] ) ) {
            update_user_meta( $user_id, '_gcrev_gbp_refresh_token', Gcrev_Crypto::encrypt( $body['refresh_token'] ) );
        }

        error_log("[GCREV][GBP] Token refreshed (encrypted) for user_id={$user_id}");
        return true;
    }

    /**
     * 有効なアクセストークンを取得（必要に応じてリフレッシュ）
     *
     * @param int $user_id
     * @return string|null アクセストークン（取得不可ならnull）
     */
    public function gbp_get_access_token(int $user_id): ?string {
        $expires = (int) get_user_meta($user_id, '_gcrev_gbp_token_expires', true);

        // 有効期限内ならそのまま返す（5分マージン）
        if ($expires > time() + 300) {
            $token = Gcrev_Crypto::decrypt( get_user_meta($user_id, '_gcrev_gbp_access_token', true) );
            if (!empty($token)) {
                return $token;
            }
        }

        // リフレッシュ
        if ($this->gbp_refresh_access_token($user_id)) {
            return Gcrev_Crypto::decrypt( get_user_meta($user_id, '_gcrev_gbp_access_token', true) ) ?: null;
        }

        return null;
    }

    // =========================================================
    // GBP Performance API: MEOダッシュボード用
    // =========================================================

    /**
     * GBP Performance API からパフォーマンス指標を一括取得・集計
     */
    private function gbp_fetch_performance_metrics(
        string $access_token, string $location_id, string $start_date, string $end_date
        ): array {
        if (strpos($location_id, 'locations/') !== 0) {
            $location_id = 'locations/' . $location_id;
        }

        $url = "https://businessprofileperformance.googleapis.com/v1/{$location_id}:fetchMultiDailyMetricsTimeSeries";

        $body = [
            'dailyMetrics' => [
                'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
                'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
                'CALL_CLICKS',
                'WEBSITE_CLICKS',
                'BUSINESS_DIRECTION_REQUESTS',
                'BUSINESS_BOOKINGS',
            ],
            'dailyRange' => [
                'startDate' => $this->gbp_date_obj($start_date),
                'endDate'   => $this->gbp_date_obj($end_date),
            ],
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log("[GCREV][GBP] Performance API error: " . $response->get_error_message());
            return $this->gbp_empty_metrics();
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            $body_text = wp_remote_retrieve_body($response);
            error_log("[GCREV][GBP] Performance API HTTP {$status}: {$body_text}");
            return $this->gbp_empty_metrics();
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $this->gbp_aggregate_metrics($data);
    }

    /**
     * GBP Performance API から日別データを取得
     */
    private function gbp_fetch_daily_metrics(
        string $access_token, string $location_id, string $start_date, string $end_date
        ): array {
        if (strpos($location_id, 'locations/') !== 0) {
            $location_id = 'locations/' . $location_id;
        }

        $url = "https://businessprofileperformance.googleapis.com/v1/{$location_id}:fetchMultiDailyMetricsTimeSeries";

        $body = [
            'dailyMetrics' => [
                'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
                'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
                'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
            ],
            'dailyRange' => [
                'startDate' => $this->gbp_date_obj($start_date),
                'endDate'   => $this->gbp_date_obj($end_date),
            ],
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $this->gbp_build_daily_series($data);
    }

    /**
     * GBP Search Keywords API からキーワードデータを取得
     * ※月単位のみ利用可能（Google仕様）
     */
    private function gbp_fetch_search_keywords(
        string $access_token, string $location_id, string $start_date, string $end_date
        ): array {
        if (strpos($location_id, 'locations/') !== 0) {
            $location_id = 'locations/' . $location_id;
        }

        $start_obj = new \DateTimeImmutable($start_date);
        $end_obj   = new \DateTimeImmutable($end_date);

        $url = "https://businessprofileperformance.googleapis.com/v1/{$location_id}/searchkeywords/impressions/monthly";
        $params = [
            'monthlyRange.startMonth.year'  => (int) $start_obj->format('Y'),
            'monthlyRange.startMonth.month' => (int) $start_obj->format('n'),
            'monthlyRange.endMonth.year'    => (int) $end_obj->format('Y'),
            'monthlyRange.endMonth.month'   => (int) $end_obj->format('n'),
            'pageSize'                      => 10,
        ];

        $response = wp_remote_get($url . '?' . http_build_query($params), [
            'headers' => ['Authorization' => 'Bearer ' . $access_token],
            'timeout' => 30,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $result   = json_decode(wp_remote_retrieve_body($response), true);
        $keywords = [];

        foreach (($result['searchKeywordsCounts'] ?? []) as $item) {
            $keywords[] = [
                'keyword'     => $item['searchKeyword'] ?? '',
                'impressions' => (int) ($item['insightsValue']['value'] ?? 0),
            ];
        }

        usort($keywords, function($a, $b) { return $b['impressions'] - $a['impressions']; });
        return $keywords;
    }

    /**
     * キーワードの前期比をマージ
     */
    private function gbp_merge_keyword_changes(array $current, array $previous): array {
        $prev_map = [];
        foreach ($previous as $kw) {
            $prev_map[$kw['keyword']] = $kw['impressions'];
        }
        foreach ($current as &$kw) {
            $kw['prev_impressions'] = $prev_map[$kw['keyword']] ?? null;
        }
        unset($kw);
        return $current;
    }

    // ===== GBP ヘルパー =====

    /**
     * YYYY-MM-DD → Google API用 {year, month, day} 変換
     */
    private function gbp_date_obj(string $date): array {
        $dt = new \DateTimeImmutable($date);
        return [
            'year'  => (int) $dt->format('Y'),
            'month' => (int) $dt->format('n'),
            'day'   => (int) $dt->format('j'),
        ];
    }

    /**
     * 空メトリクスを返す（エラー時フォールバック）
     */
    private function gbp_empty_metrics(): array {
        return [
            'search_impressions'  => 0,
            'map_impressions'     => 0,
            'total_impressions'   => 0,
            'photo_views'         => 0,
            'call_clicks'         => 0,
            'direction_clicks'    => 0,
            'website_clicks'      => 0,
            'booking_clicks'      => 0,
        ];
    }

    /**
     * fetchMultiDailyMetricsTimeSeries の結果を集計
     */
    private function gbp_aggregate_metrics(array $api_response): array {
        $totals = $this->gbp_empty_metrics();

        // メトリクス名 → 出力キーのマッピング
        $metric_map = [
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => 'search_impressions',
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'  => 'search_impressions',
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS'   => 'map_impressions',
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS'    => 'map_impressions',
            'CALL_CLICKS'                         => 'call_clicks',
            'WEBSITE_CLICKS'                      => 'website_clicks',
            'BUSINESS_DIRECTION_REQUESTS'         => 'direction_clicks',
            'BUSINESS_BOOKINGS'                   => 'booking_clicks',
        ];

        foreach (($api_response['multiDailyMetricTimeSeries'] ?? []) as $series) {
            $metric_name = $series['dailyMetric'] ?? '';
            $target_key  = $metric_map[$metric_name] ?? null;
            if (!$target_key) continue;

            foreach (($series['dailyMetricTimeSeries']['timeSeries']['datedValues'] ?? []) as $dv) {
                $totals[$target_key] += (int) ($dv['value'] ?? 0);
            }
        }

        $totals['total_impressions'] = $totals['search_impressions'] + $totals['map_impressions'];
        return $totals;
    }

    /**
     * 日別時系列データを構築（Chart.js用）
     */
    private function gbp_build_daily_series(array $api_response): array {
        $daily = []; // date => [search_impressions, map_impressions]

        $metric_type_map = [
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => 'search',
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'  => 'search',
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS'   => 'map',
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS'    => 'map',
        ];

        foreach (($api_response['multiDailyMetricTimeSeries'] ?? []) as $series) {
            $metric_name = $series['dailyMetric'] ?? '';
            $type        = $metric_type_map[$metric_name] ?? null;
            if (!$type) continue;

            foreach (($series['dailyMetricTimeSeries']['timeSeries']['datedValues'] ?? []) as $dv) {
                $date_obj = $dv['date'] ?? [];
                $date_str = sprintf('%04d-%02d-%02d', $date_obj['year'] ?? 0, $date_obj['month'] ?? 0, $date_obj['day'] ?? 0);
                $value    = (int) ($dv['value'] ?? 0);

                if (!isset($daily[$date_str])) {
                    $daily[$date_str] = ['date' => $date_str, 'search_impressions' => 0, 'map_impressions' => 0];
                }

                if ($type === 'search') {
                    $daily[$date_str]['search_impressions'] += $value;
                } else {
                    $daily[$date_str]['map_impressions'] += $value;
                }
            }
        }

        // 日付順にソート
        ksort($daily);
        return array_values($daily);
    }


    // =========================================================
    // インフォグラフィック（月次サマリーJSON）
    // =========================================================

    /**
     * 全角文字幅ベースで文字列を切り詰める
     * mb_strwidth: 全角=2, 半角=1
     *
     * @param string $str       対象文字列
     * @param int    $max_width 最大幅（全角換算の文字数 × 2）
     * @return string
     */
    private function truncate_zenkaku(string $str, int $max_width): string {
        $str = trim($str);
        if (mb_strwidth($str, 'UTF-8') <= $max_width) {
            return $str;
        }
        return mb_strimwidth($str, 0, $max_width, '', 'UTF-8');
    }

    /**
     * インフォグラフィックJSONをuser_metaに保存
     *
     * メタキー: gcrev_infographic_YYYYMM
     *
     * @param int   $year    対象年
     * @param int   $month   対象月
     * @param int   $user_id ユーザーID
     * @param array $data    インフォグラフィックJSON配列
     * @return bool
     */
    public function save_monthly_infographic(int $year, int $month, int $user_id, array $data): bool {
        // サーバ側でも文字数制約を念押し
        // action: 全角13文字 = mb_strwidth 26
        // summary: 全角80文字 = mb_strwidth 160
        if (isset($data['action']) && is_string($data['action'])) {
            $data['action'] = $this->truncate_zenkaku($data['action'], 26);
        }
        if (isset($data['summary']) && is_string($data['summary'])) {
            $data['summary'] = $this->truncate_zenkaku($data['summary'], 160);
        }

        $meta_key = sprintf('gcrev_infographic_%04d%02d', $year, $month);
        $json_str = wp_json_encode($data, JSON_UNESCAPED_UNICODE);

        $result = update_user_meta($user_id, $meta_key, $json_str);

        error_log("[GCREV] save_monthly_infographic: user_id={$user_id}, key={$meta_key}, result=" . var_export($result, true));

        // update_user_meta: 新規=meta_id(int), 更新=true, 失敗=false
        return $result !== false;
    }

    /**
     * 保存済みインフォグラフィックJSONを取得
     *
     * @param int $year
     * @param int $month
     * @param int $user_id
     * @return array|null 見つからない/パース失敗時はnull
     */
    public function get_monthly_infographic(int $year, int $month, int $user_id): ?array {
        $meta_key = sprintf('gcrev_infographic_%04d%02d', $year, $month);
        $raw = get_user_meta($user_id, $meta_key, true);

        if (empty($raw) || !is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    // =========================================================
    // ヘルススコア計算（PHP固定ロジック、AI不使用）
    // =========================================================

    /**
     * 前月比の変化率を計算（%）
     *
     * @param float $curr 当月値
     * @param float $prev 前月値
     * @return float 変化率（%）
     */
    private function calc_pct_change(float $curr, float $prev): float {
        if ($prev == 0.0) {
            return ($curr == 0.0) ? 0.0 : 100.0;
        }
        return (($curr - $prev) / $prev) * 100.0;
    }

    /**
     * 変化率を段階点に変換
     *
     * +15%以上 → 25点, +5〜+14% → 20点, -4〜+4% → 15点, -5〜-14% → 8点, -15%以下 → 0点
     *
     * @param float $pct       変化率（%）
     * @param int   $max_points 満点（デフォルト25）
     * @return int
     */
    private function pct_to_points(float $pct, int $max_points = 25): int {
        if ($pct >= 15.0) return $max_points;       // 25
        if ($pct >= 5.0)  return (int)($max_points * 0.8);  // 20
        if ($pct >= -4.0) return (int)($max_points * 0.6);  // 15
        if ($pct >= -14.0) return (int)($max_points * 0.32); // 8
        return 0;
    }

    /**
     * スコアからステータス文言を決定
     *
     * @param int $score 0〜100
     * @return string
     */
    private function score_to_status(int $score): string {
        if ($score >= 75) return '安定しています';
        if ($score >= 50) return '改善傾向です';
        return '要注意です';
    }

    /**
     * 月次ヘルススコアを計算
     *
     * 4観点（流入/成果/検索/地図）×各25点＝100点満点
     *
     * @param array $curr 当月の指標（キー: traffic, cv, gsc, meo）
     * @param array $prev 前月の指標（同上）
     * @param array $opt  オプション（将来の業種別差し替え用、現在は未使用）
     * @return array {score:int, status:string, breakdown:array}
     */
    public function calc_monthly_health_score(array $curr, array $prev, array $opt = []): array {
        $dimensions = [
            'traffic' => ['label' => 'サイトに来た人の数',       'max' => 25],
            'cv'      => ['label' => '問い合わせ・申込み',      'max' => 25],
            'gsc'     => ['label' => '検索結果からクリックされた数', 'max' => 25],
            'meo'     => ['label' => '地図検索からの表示数',     'max' => 25],
        ];

        $breakdown = [];
        $total = 0;

        foreach ($dimensions as $key => $dim) {
            $c = (float)($curr[$key] ?? 0);
            $p = (float)($prev[$key] ?? 0);

            $pct    = $this->calc_pct_change($c, $p);
            $points = $this->pct_to_points($pct, $dim['max']);

            // ★ 共通ルール：当月実数が0なら、その観点は必ず0点
            if ((int)$c === 0) {
                $points = 0;
                $pct    = 0; // 表示を落ち着かせたい場合（任意）
            }

            $breakdown[$key] = [
                'label'  => $dim['label'],
                'curr'   => $c,
                'prev'   => $p,
                'pct'    => round($pct, 1),
                'points' => $points,
                'max'    => $dim['max'],
            ];
            $total += $points;
        }

        $score = max(0, min(100, $total));

        return [
            'score'     => $score,
            'status'    => $this->score_to_status($score),
            'breakdown' => $breakdown,
        ];
    }


    /**
     * prev_data / two_data（fetch_dashboard_data_internal の返り値）から
     * ヘルススコア計算用の指標配列を抽出する
     *
     * @param array $data      fetch_dashboard_data_internal の返り値
     * @param int   $user_id   MEOデータ取得用
     * @param array $meo_metrics MEOメトリクス（外部から渡す場合）
     * @return array ['traffic'=>int, 'cv'=>int, 'gsc'=>int, 'meo'=>int]
     */
    private function extract_score_metrics(array $data, int $user_id = 0, array $meo_metrics = []): array {
        // A) 流入：セッション数
        // ※ number_format() 済み文字列（"5,717"）が来るためカンマ除去してからキャスト
        $traffic = (int)str_replace(',', '', (string)($data['sessions'] ?? '0'));

        // B) 成果：CV数 ← effective_cv 優先
        if (isset($data['effective_cv']) && ($data['effective_cv']['source'] ?? 'ga4') !== 'ga4') {
            $cv = (int)$data['effective_cv']['total'];
        } else {
            $cv_raw = $data['key_events_total']
                ?? $data['conversions']
                ?? $data['ga4']['total']['keyEvents']
                ?? 0;
            $cv = (int)str_replace(',', '', (string)$cv_raw);
        }

        // C) 検索：GSC clicks → impressions フォールバック
        $gsc_total = $data['gsc']['total'] ?? [];
        $gsc = (int)str_replace(',', '', (string)($gsc_total['clicks'] ?? $gsc_total['impressions'] ?? '0'));

        // D) 地図：MEO表示回数
        $meo = (int)($meo_metrics['total_impressions'] ?? 0);

        return [
            'traffic' => $traffic,
            'cv'      => $cv,
            'gsc'     => $gsc,
            'meo'     => $meo,
        ];
    }

    /**
     * MEOメトリクスを安全に取得（GBP接続済みユーザーのみ）
     *
     * @param int    $user_id
     * @param string $start_date YYYY-MM-DD
     * @param string $end_date   YYYY-MM-DD
     * @return array gbp_empty_metrics() 互換
     */
    private function fetch_meo_metrics_safe(int $user_id, string $start_date, string $end_date): array {
        try {
            $location_id = (string)get_user_meta($user_id, '_gcrev_gbp_location_id', true);
            if (empty($location_id) || strpos($location_id, 'pending') === 0) {
                return $this->gbp_empty_metrics();
            }
            $access_token = $this->gbp_get_access_token($user_id);
            if (empty($access_token)) {
                return $this->gbp_empty_metrics();
            }
            return $this->gbp_fetch_performance_metrics($access_token, $location_id, $start_date, $end_date);
        } catch (\Exception $e) {
            error_log("[GCREV] fetch_meo_metrics_safe: Error user_id={$user_id}: " . $e->getMessage());
            return $this->gbp_empty_metrics();
        }
    }

    // =========================================================
    // インフォグラフィックJSON 生成（score=PHP計算、summary/action=AI）
    // =========================================================

    /**
     * AIにインフォグラフィックの summary / action のみを生成させる
     *
     * score / status はPHPで事前計算済みの値を使う（AIには計算させない）
     *
     * @param array $prev_data   前月GA4/GSCデータ
     * @param array $two_data    前々月GA4/GSCデータ
     * @param array $client_info クライアント情報
     * @param int   $user_id     ユーザーID（MEOデータ取得用）
     * @return array|null パース成功時は配列、失敗時はnull
     */
    public function generate_infographic_json(array $prev_data, array $two_data, array $client_info, int $user_id = 0): ?array {

        // ===== 1) MEOデータ取得 =====
        $prev_start = (string)($prev_data['current_period']['start'] ?? '');
        $prev_end   = (string)($prev_data['current_period']['end']   ?? '');
        $two_start  = (string)($two_data['current_period']['start']  ?? '');
        $two_end    = (string)($two_data['current_period']['end']    ?? '');

        $meo_curr = ($user_id > 0 && $prev_start && $prev_end)
            ? $this->fetch_meo_metrics_safe($user_id, $prev_start, $prev_end)
            : $this->gbp_empty_metrics();
        $meo_prev = ($user_id > 0 && $two_start && $two_end)
            ? $this->fetch_meo_metrics_safe($user_id, $two_start, $two_end)
            : $this->gbp_empty_metrics();

        // ===== 2) スコア計算用指標を抽出 =====
        $curr_metrics = $this->extract_score_metrics($prev_data, $user_id, $meo_curr);
        $prev_metrics = $this->extract_score_metrics($two_data,  $user_id, $meo_prev);

        // ===== 3) ヘルススコア計算（PHP固定ロジック） =====
        $health = $this->calc_monthly_health_score($curr_metrics, $prev_metrics);

        error_log("[GCREV] generate_infographic_json: Health score calculated: score={$health['score']}, status={$health['status']}");
        error_log("[GCREV] generate_infographic_json: Metrics curr=" . wp_json_encode($curr_metrics) . " prev=" . wp_json_encode($prev_metrics));

        // ===== 4) KPI実数値と差分 =====
        // 実質CVがclient_infoに含まれている場合はそれを優先
        $effective_cv_info = $client_info['effective_cv'] ?? null;
        $cv_value = $curr_metrics['cv'];
        $cv_source = 'ga4';
        if ($effective_cv_info && !empty($effective_cv_info['source']) && $effective_cv_info['source'] !== 'ga4') {
            $cv_value = $effective_cv_info['cv'] ?? $cv_value;
            $cv_source = $effective_cv_info['source']; // 'hybrid' etc.
            error_log("[GCREV] generate_infographic_json: Using {$cv_source} CV={$cv_value} instead of GA4 CV={$curr_metrics['cv']}");
        }

        $kpi = [
            'visits' => [
                'value' => $curr_metrics['traffic'],
                'diff'  => $curr_metrics['traffic'] - $prev_metrics['traffic'],
            ],
            'cv' => [
                'value'  => $cv_value,
                'diff'   => $cv_value - $prev_metrics['cv'],
                'source' => $cv_source,
            ],
            'meo' => [
                'value' => $curr_metrics['meo'],
                'diff'  => $curr_metrics['meo'] - $prev_metrics['meo'],
            ],
        ];

        // ===== 5) 前月スコアとの差分（前月インフォグラフィックがあれば） =====
        $score_diff = 0;
        // score_diff は保存後に呼び出し側で上書き可能だが、ここでは0をデフォルトにする

        // ===== 6) AI で summary / action のみ生成 =====
        $summary = '';
        $action  = '';

        try {
            $ai_result = $this->generate_summary_and_action($prev_data, $two_data, $client_info, $health);
            if ($ai_result) {
                $summary = $ai_result['summary'] ?? '';
                $action  = $ai_result['action']  ?? '';
            }
        } catch (\Exception $e) {
            error_log("[GCREV] generate_infographic_json: AI summary/action error: " . $e->getMessage());
            // AI失敗でもスコアは保存する（summary/actionは空になる）
        }

        // ===== 7) 最終JSON組み立て =====
        return [
            'score'      => $health['score'],
            'score_diff' => $score_diff,
            'status'     => $health['status'],
            'kpi'        => $kpi,
            'summary'    => $summary,
            'action'     => $action,
            'breakdown'  => $health['breakdown'],
        ];
    }

    /**
     * AIに summary と action のみを生成させる（score/statusは渡さない）
     *
     * @param array $prev_data   前月データ
     * @param array $two_data    前々月データ
     * @param array $client_info クライアント情報
     * @param array $health      calc_monthly_health_score の結果
     * @return array|null ['summary'=>string, 'action'=>string]
     */
    private function generate_summary_and_action(array $prev_data, array $two_data, array $client_info, array $health): ?array {

        // ※ number_format() 済み文字列（"5,717"）が来るためカンマ除去してからキャスト
        $prev_sessions  = (int)str_replace(',', '', (string)($prev_data['sessions']  ?? '0'));
        $two_sessions   = (int)str_replace(',', '', (string)($two_data['sessions']   ?? '0'));
        $prev_users     = (int)str_replace(',', '', (string)($prev_data['users']     ?? '0'));
        $two_users      = (int)str_replace(',', '', (string)($two_data['users']      ?? '0'));
        $prev_pageviews = (int)str_replace(',', '', (string)($prev_data['pageViews'] ?? '0'));
        $two_pageviews  = (int)str_replace(',', '', (string)($two_data['pageViews']  ?? '0'));

        $score  = $health['score'];
        $status = $health['status'];

        // breakdown から各観点の変化率を取得
        $bd = $health['breakdown'];
        $traffic_pct = $bd['traffic']['pct'] ?? 0;
        $cv_pct      = $bd['cv']['pct']      ?? 0;
        $gsc_pct     = $bd['gsc']['pct']     ?? 0;
        $meo_pct     = $bd['meo']['pct']     ?? 0;

        $goal_main     = (string)($client_info['goal_main']     ?? '');
        $focus_numbers = (string)($client_info['focus_numbers'] ?? '');
        $issue         = (string)($client_info['issue']         ?? '');
        // 実質CV情報をプロンプトに追加
        $effective_cv_info = $client_info['effective_cv'] ?? null;
        $cv_prompt_note = '';
        if ($effective_cv_info && ($effective_cv_info['source'] ?? 'ga4') !== 'ga4') {
            $cv_total_eff = $effective_cv_info['cv'];
            $manual_only  = $effective_cv_info['components']['manual_total'] ?? 0;
            $ga4_only     = $effective_cv_info['components']['ga4_total'] ?? 0;
            $detail_parts = [];
            foreach (($effective_cv_info['detail'] ?? []) as $route => $count) {
                $detail_parts[] = "{$route}:{$count}件";
            }
            $detail_str = implode('、', $detail_parts);
            $cv_prompt_note = <<<CVNOTE

CV情報（GA4+手動ハイブリッド）:
  CV合計: {$cv_total_eff}件
  内訳 - 手動入力イベント: {$manual_only}件（{$detail_str}）
  内訳 - GA4キーイベント（自動）: {$ga4_only}件
  ※CVに言及する場合は、このハイブリッド合計値を前提にしてください。
  ※一部のキーイベントは手動入力値で上書きされています。
CVNOTE;
        }
        
        $prompt = <<<PROMPT
あなたは日本の中小企業向けWeb解析アドバイザーです。
以下のデータに基づき、JSONのみを出力してください。説明文は一切不要です。

ヘルススコア: {$score}点（{$status}）
各観点の前月比:
  流入(セッション): {$traffic_pct}%
  成果(CV): {$cv_pct}%
  検索(GSC): {$gsc_pct}%
  地図(MEO): {$meo_pct}%

数値情報:
  セッション: {$prev_sessions}（前々月: {$two_sessions}）
  ユーザー: {$prev_users}（前々月: {$two_users}）
  PV: {$prev_pageviews}（前々月: {$two_pageviews}）
クライアント目標: {$goal_main}
重点数値: {$focus_numbers}
現状の課題: {$issue}

出力するJSON（このフォーマット厳守）:
{
  "summary": "サイトの現状を2文以内で説明。専門用語禁止。初心者向け。全角80文字以内。",
  "action": "全角13文字以内。体言止め。アイコン・記号・句読点なし。単体で意味が通るネクストアクション1つ"
}

ルール:
- JSON以外を出力しない（コードブロック記号も不要）
- summaryは全角80文字以内、2文まで
- actionは全角13文字以内、体言止め、記号なし
- scoreやstatusは出力しない（PHP側で計算済み）
PROMPT;

        // AIクライアント呼び出し
        $ai_method = null;
        $candidate_methods = ['call_gemini_api', 'generate_text', 'send_prompt', 'generate_content', 'ask', 'call'];
        foreach ($candidate_methods as $m) {
            if (method_exists($this->ai, $m)) {
                $ai_method = $m;
                break;
            }
        }

        if ($ai_method === null) {
            $available = implode(', ', get_class_methods($this->ai));
            error_log("[GCREV] generate_summary_and_action: AI client has no known method. Available: " . $available);
            return null;
        }

        error_log("[GCREV] generate_summary_and_action: Using AI method '{$ai_method}'");

        $raw_response = $this->ai->{$ai_method}($prompt);

        if (empty($raw_response) || !is_string($raw_response)) {
            error_log("[GCREV] generate_summary_and_action: AI returned empty/non-string. Type=" . gettype($raw_response));
            return null;
        }

        error_log("[GCREV] generate_summary_and_action: AI response length=" . strlen($raw_response));

        // コードブロック除去
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($raw_response));
        $cleaned = preg_replace('/\s*```\s*$/', '', $cleaned);

        $decoded = json_decode($cleaned, true);

        if (!is_array($decoded)) {
            error_log("[GCREV] generate_summary_and_action: JSON parse failed. Raw: " . mb_substr($raw_response, 0, 500));
            return null;
        }

        error_log("[GCREV] generate_summary_and_action: SUCCESS");

        return [
            'summary' => (string)($decoded['summary'] ?? ''),
            'action'  => (string)($decoded['action']  ?? ''),
        ];
    }

    // =========================================================
    // CV Routes（フォーム最大5種 設定管理）
    // =========================================================

    /**
     * seed_cv_routes_for_user: ハイブリッドモードでは自動シードしない（no-op）
     */
    public function seed_cv_routes_for_user(int $user_id): void {
        // ハイブリッドCV方式ではデフォルトルートのシードは行わない
        // ユーザーが設定画面でGA4キーイベントを手動追加する
    }

    public function get_cv_routes(int $user_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_cv_routes';

        if (!$this->table_exists($table)) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT route_key, label, enabled, sort_order FROM {$table} WHERE user_id = %d ORDER BY sort_order ASC",
            $user_id
        ), ARRAY_A);

        if (empty($rows)) return [];

        foreach ($rows as &$row) {
            $row['enabled'] = (int)$row['enabled'];
            $row['sort_order'] = (int)$row['sort_order'];
        }
        unset($row);

        return $rows;
    }

    private function get_cv_routes_all_default(): array {
        return [];
    }

    public function get_enabled_cv_routes(int $user_id): array {
        return array_values(array_filter($this->get_cv_routes($user_id), fn($r) =>
            (int)$r['enabled'] === 1
        ));
    }

    // =========================================================
    // Effective CV 共通メソッド（★全画面が参照する単一ソース★）
    // =========================================================

    /**
     * 実質CV日別合計（DB: wp_gcrev_actual_cvs）
     */
    public function get_actual_cv_daily_totals(string $year_month, int $user_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_actual_cvs';
        $dt = new \DateTime($year_month . '-01');
        $days = (int)$dt->format('t');
        $start = $year_month . '-01';
        $end   = $year_month . '-' . str_pad((string)$days, 2, '0', STR_PAD_LEFT);

        $daily = [];
        for ($d = 1; $d <= $days; $d++) {
            $daily[$year_month . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT)] = 0;
        }
        if (!$this->table_exists($table)) return $daily;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cv_date, SUM(cv_count) AS day_total FROM {$table}
             WHERE user_id=%d AND cv_date BETWEEN %s AND %s GROUP BY cv_date",
            $user_id, $start, $end
        ), ARRAY_A);
        foreach ($rows as $row) {
            if (isset($daily[$row['cv_date']])) $daily[$row['cv_date']] = (int)$row['day_total'];
        }
        return $daily;
    }

    /**
     * GA4電話タップ（キーイベント）日別取得
     * phone_event_name は user_meta '_gcrev_phone_event_name'（未設定時: phone_tap）
     */
    public function get_ga4_phone_tap_daily(string $year_month, int $user_id): array {
        $dt = new \DateTime($year_month . '-01');
        $days = (int)$dt->format('t');
        $start = $year_month . '-01';
        $end   = $year_month . '-' . str_pad((string)$days, 2, '0', STR_PAD_LEFT);

        $daily = [];
        for ($d = 1; $d <= $days; $d++) {
            $daily[$year_month . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT)] = 0;
        }

        // キャッシュ確認（6時間）
        $cache_key = "gcrev_phone_tap_{$user_id}_{$year_month}";
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        try {
            $config = $this->config->get_user_config($user_id);
            $ga4_id = $config['ga4_id'];
            $phone_event = get_user_meta($user_id, '_gcrev_phone_event_name', true) ?: 'phone_tap';

            $client = new BetaAnalyticsDataClient(['credentials' => $this->service_account_path]);
            $request = new RunReportRequest();
            $request->setProperty('properties/' . $ga4_id);
            $request->setDateRanges([(new DateRange())->setStartDate($start)->setEndDate($end)]);
            $request->setDimensions([
                (new Dimension())->setName('date'),
                (new Dimension())->setName('eventName'),
            ]);
            $request->setMetrics([(new Metric())->setName('eventCount')]);

            $response = $client->runReport($request);
            foreach ($response->getRows() as $row) {
                $dims = $row->getDimensionValues();
                $dateRaw   = $dims[0]->getValue();
                $eventName = $dims[1]->getValue();

                if ($eventName !== $phone_event) continue;

                $dk = substr($dateRaw, 0, 4) . '-' . substr($dateRaw, 4, 2) . '-' . substr($dateRaw, 6, 2);
                if (isset($daily[$dk])) {
                    $daily[$dk] += (int)$row->getMetricValues()[0]->getValue();
                }
            }
            // キャッシュ保存（6時間）
            set_transient($cache_key, $daily, 6 * HOUR_IN_SECONDS);
        } catch (\Exception $e) {
            error_log("[GCREV] get_ga4_phone_tap_daily ERROR: " . $e->getMessage());
        }
        return $daily;
    }

    /**
     * GA4 CV日別合計（fallback用）
     */
    public function get_ga4_cv_daily_totals(string $year_month, int $user_id): array {
        $dt = new \DateTime($year_month . '-01');
        $days = (int)$dt->format('t');
        $start = $year_month . '-01';
        $end   = $year_month . '-' . str_pad((string)$days, 2, '0', STR_PAD_LEFT);

        $daily = [];
        for ($d = 1; $d <= $days; $d++) {
            $daily[$year_month . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT)] = 0;
        }

        // キャッシュ確認（6時間）
        $cache_key = "gcrev_ga4cv_{$user_id}_{$year_month}";
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        try {
            $config = $this->config->get_user_config($user_id);
            $client = new BetaAnalyticsDataClient(['credentials' => $this->service_account_path]);
            $request = new RunReportRequest();
            $request->setProperty('properties/' . $config['ga4_id']);
            $request->setDateRanges([(new DateRange())->setStartDate($start)->setEndDate($end)]);
            $request->setDimensions([(new Dimension())->setName('date')]);
            $request->setMetrics([(new Metric())->setName('keyEvents')]);

            foreach ($client->runReport($request)->getRows() as $row) {
                $dr = $row->getDimensionValues()[0]->getValue();
                $dk = substr($dr,0,4).'-'.substr($dr,4,2).'-'.substr($dr,6,2);
                if (isset($daily[$dk])) $daily[$dk] = (int)$row->getMetricValues()[0]->getValue();
            }
            // キャッシュ保存（6時間）
            set_transient($cache_key, $daily, 6 * HOUR_IN_SECONDS);
        } catch (\Exception $e) {
            error_log("[GCREV] get_ga4_cv_daily_totals ERROR: " . $e->getMessage());
        }
        return $daily;
    }

    /**
     * GA4キーイベント日別取得（キャッシュ付きラッパー）
     * @return array [eventName => [YYYY-MM-DD => count, ...], ...]
     */
    public function get_ga4_key_events_daily(string $year_month, int $user_id): array {
        $cache_key = "gcrev_ga4kevt_daily_{$user_id}_{$year_month}";
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $dt = new \DateTime($year_month . '-01');
        $days = (int)$dt->format('t');
        $start = $year_month . '-01';
        $end   = $year_month . '-' . str_pad((string)$days, 2, '0', STR_PAD_LEFT);

        $result = [];
        try {
            $config = $this->config->get_user_config($user_id);
            $result = $this->ga4->fetch_ga4_key_events_daily($config['ga4_id'], $start, $end);
            set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
        } catch (\Exception $e) {
            error_log("[GCREV] get_ga4_key_events_daily ERROR: " . $e->getMessage());
        }
        return $result;
    }

    /**
     * テーブル存在チェック（リクエスト内キャッシュ付き）
     */
    private function table_exists(string $table): bool {
        if (isset($this->table_exists_cache[$table])) {
            return $this->table_exists_cache[$table];
        }
        global $wpdb;
        $exists = (bool)$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        $this->table_exists_cache[$table] = $exists;
        return $exists;
    }

    /**
     * 特定ルートの手動CV日別データ取得
     * null = 未入力（GA4値を使う）、int = 手動入力値（0含む）
     */
    private function get_actual_cv_daily_for_route(string $year_month, int $user_id, string $route): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_actual_cvs';
        $dt = new \DateTime($year_month . '-01');
        $days = (int)$dt->format('t');
        $start = $year_month . '-01';
        $end   = $year_month . '-' . str_pad((string)$days, 2, '0', STR_PAD_LEFT);

        $daily = [];
        for ($d = 1; $d <= $days; $d++) {
            $daily[$year_month . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT)] = null;
        }
        if (!$this->table_exists($table)) return $daily;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cv_date, cv_count FROM {$table}
             WHERE user_id=%d AND route=%s AND cv_date BETWEEN %s AND %s",
            $user_id, $route, $start, $end
        ), ARRAY_A);
        foreach ($rows as $row) {
            // MySQL返却値の不可視文字を除去するため日付を正規化
            $date_key = date('Y-m-d', strtotime($row['cv_date']));
            if (array_key_exists($date_key, $daily)) {
                $daily[$date_key] = (int)$row['cv_count'];
            }
        }
        return $daily;
    }

    /**
     * 特定ルートに手動CVデータが存在するか
     */
    private function check_has_actual_cv_for_route(string $year_month, int $user_id, string $route): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_actual_cvs';
        if (!$this->table_exists($table)) return false;
        $start = $year_month . '-01';
        $dt = new \DateTime($start);
        $dt->modify('last day of this month');
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND route=%s AND cv_date BETWEEN %s AND %s",
            $user_id, $route, $start, $dt->format('Y-m-d')
        )) > 0;
    }

    /**
     * ★★★ get_effective_cv_monthly ★★★
     * 全画面・全処理が参照する唯一のCV計算メソッド
     *
     * ハイブリッド方式:
     * - オーバーライド設定なし → pure GA4（全キーイベント合計）
     * - オーバーライド設定あり → 手動値優先イベント + GA4残りイベント
     */
    public function get_effective_cv_monthly(string $year_month, int $user_id): array {
        // インスタンスキャッシュ（同一リクエスト内の重複計算を防止）
        $cache_key = "{$year_month}_{$user_id}";
        if (isset($this->effective_cv_cache[$cache_key])) {
            return $this->effective_cv_cache[$cache_key];
        }

        // トランジェントキャッシュ（リクエスト間で共有、2時間）
        $transient_key = "gcrev_effcv_{$user_id}_{$year_month}";
        $transient = get_transient($transient_key);
        if ($transient !== false && is_array($transient)) {
            $this->effective_cv_cache[$cache_key] = $transient;
            return $transient;
        }

        $dt = new \DateTime($year_month . '-01');
        $days = (int)$dt->format('t');

        // 空の日別配列を準備
        $empty_daily = [];
        for ($d = 1; $d <= $days; $d++) {
            $empty_daily[$year_month . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT)] = 0;
        }

        $override_routes = $this->get_enabled_cv_routes($user_id);
        $override_keys = array_column($override_routes, 'route_key');

        // オーバーライド設定なし → pure GA4
        if (empty($override_keys)) {
            $ga4_daily = $this->get_ga4_cv_daily_totals($year_month, $user_id);
            $result = [
                'source'           => 'ga4',
                'total'            => array_sum($ga4_daily),
                'daily'            => $ga4_daily,
                'components'       => [
                    'manual_total' => 0,
                    'ga4_total'    => array_sum($ga4_daily),
                ],
                'breakdown_manual' => [],
                'has_overrides'    => false,
            ];

            // CVレビュー結果があれば上書き
            $reviewed = $this->get_reviewed_cv_data($year_month, $user_id);
            if ($reviewed !== null) {
                $result['source'] = 'reviewed';
                $result['total']  = $reviewed['total'];
                // 日別データをレビュー結果でマージ
                $result['daily'] = array_merge($empty_daily, $reviewed['daily']);
                $result['components']['reviewed_total'] = $reviewed['total'];
            }

            $this->effective_cv_cache[$cache_key] = $result;
            set_transient($transient_key, $result, 2 * HOUR_IN_SECONDS);
            return $result;
        }

        // ハイブリッド: GA4イベント日別 + 手動オーバーライド（日単位で整合）
        $ga4_events_daily = $this->get_ga4_key_events_daily($year_month, $user_id);

        $daily = $empty_daily;
        $manual_total = 0;
        $ga4_only_total = 0;
        $breakdown_manual = [];
        $has_manual_data = false;

        // 「設定イベントのみ」フラグ（セクション1・2 両方で参照）
        $only_configured = (bool)get_user_meta($user_id, '_gcrev_cv_only_configured', true);
        $phone_event = get_user_meta($user_id, '_gcrev_phone_event_name', true) ?: '';

        // 1) オーバーライドイベントの処理
        foreach ($override_keys as $event_name) {
            $manual_daily = $this->get_actual_cv_daily_for_route($year_month, $user_id, $event_name);
            $ga4_event_daily = $ga4_events_daily[$event_name] ?? [];

            // このルートに手動データが1件でもあるか判定
            $route_has_manual = false;
            foreach ($manual_daily as $v) {
                if ($v !== null) { $route_has_manual = true; break; }
            }

            $route_manual_sum = 0;
            $route_ga4_sum = 0;

            foreach ($empty_daily as $date => $_) {
                if ($route_has_manual) {
                    // ルートに手動データあり → 手動値のみ使用（未入力日は0）
                    $manual_val = $manual_daily[$date] ?? null;
                    if ($manual_val !== null) {
                        $daily[$date] += $manual_val;
                        $route_manual_sum += $manual_val;
                    }
                } elseif (!$only_configured) {
                    // ルートに手動データなし & 設定イベントのみOFF → GA4値を使用
                    $ga4_val = $ga4_event_daily[$date] ?? 0;
                    $daily[$date] += $ga4_val;
                    $route_ga4_sum += $ga4_val;
                }
                // only_configured ON & 手動データなし → 0（GA4フォールバックなし）
            }

            if ($route_has_manual) {
                $has_manual_data = true;
                $manual_total += $route_manual_sum;
                $breakdown_manual[$event_name] = $route_manual_sum;
            } else {
                $ga4_only_total += $route_ga4_sum;
            }
        }

        // 2) 非オーバーライドGA4イベントの合算

        foreach ($ga4_events_daily as $event_name => $dates) {
            if (in_array($event_name, $override_keys, true)) continue;

            // 「設定イベントのみ」ON時: 電話タップ以外のGA4イベントはスキップ
            if ($only_configured && $event_name !== $phone_event) continue;

            foreach ($dates as $date => $val) {
                if (isset($daily[$date])) {
                    $daily[$date] += $val;
                }
            }
            $ga4_only_total += array_sum($dates);
        }

        $source = $has_manual_data ? 'hybrid' : 'ga4';

        $result = [
            'source'           => $source,
            'total'            => array_sum($daily),
            'daily'            => $daily,
            'components'       => [
                'manual_total' => $manual_total,
                'ga4_total'    => $ga4_only_total,
            ],
            'breakdown_manual' => $breakdown_manual,
            'has_overrides'    => true,
        ];

        // CVレビュー結果があれば上書き
        $reviewed = $this->get_reviewed_cv_data($year_month, $user_id);
        if ($reviewed !== null) {
            $result['source'] = 'reviewed';
            $result['total']  = $reviewed['total'];
            // 日別データをレビュー結果でマージ
            $result['daily'] = array_merge($empty_daily, $reviewed['daily']);
            $result['components']['reviewed_total'] = $reviewed['total'];
        }

        $this->effective_cv_cache[$cache_key] = $result;
        set_transient($transient_key, $result, 2 * HOUR_IN_SECONDS);
        return $result;
    }

    /**
     * CVログ精査テーブルから有効CV数を取得
     *
     * gcrev_cv_review で status=1（有効CV）の行数をカウント。
     * 1行＝1CV（event_count は常に1）。date_hour_minute から日別集計も行う。
     *
     * @return array|null レビューデータがなければ null。あれば ['total'=>int, 'daily'=>[date=>count]]
     */
    private function get_reviewed_cv_data(string $year_month, int $user_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_cv_review';

        if (!$this->table_exists($table)) {
            return null;
        }

        // この月にレビュー済み（status!=0）の行が1件でもあるか
        $review_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND year_month = %s AND status != 0",
            $user_id, $year_month
        ));

        if ($review_count === 0) {
            return null; // レビュー未実施
        }

        // status=1（有効CV）の行を取得
        $valid_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT date_hour_minute FROM {$table} WHERE user_id = %d AND year_month = %s AND status = 1",
            $user_id, $year_month
        ), ARRAY_A);

        // 日別集計: "202601150930" → "2026-01-15"
        $daily = [];
        foreach ($valid_rows as $row) {
            $dhm = $row['date_hour_minute'];
            if (strlen($dhm) >= 8) {
                $date = substr($dhm, 0, 4) . '-' . substr($dhm, 4, 2) . '-' . substr($dhm, 6, 2);
                $daily[$date] = ($daily[$date] ?? 0) + 1;
            }
        }

        return [
            'total' => count($valid_rows),
            'daily' => $daily,
        ];
    }

    private function check_has_actual_cv(string $year_month, int $user_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_actual_cvs';
        if (!$this->table_exists($table)) return false;
        $start = $year_month . '-01';
        $dt = new \DateTime($start);
        $dt->modify('last day of this month');
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND cv_date BETWEEN %s AND %s",
            $user_id, $start, $dt->format('Y-m-d')
        )) > 0;
    }

    private function get_actual_cv_route_breakdown(string $year_month, int $user_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_actual_cvs';
        if (!$this->table_exists($table)) return [];
        $start = $year_month . '-01';
        $dt = new \DateTime($start);
        $dt->modify('last day of this month');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT route, SUM(cv_count) AS rt FROM {$table}
             WHERE user_id=%d AND cv_date BETWEEN %s AND %s GROUP BY route",
            $user_id, $start, $dt->format('Y-m-d')
        ), ARRAY_A);
        $b = [];
        foreach ($rows as $r) $b[$r['route']] = (int)$r['rt'];
        return $b;
    }

    // =========================================================
    // CV Routes REST エンドポイント
    // =========================================================

    public function rest_get_cv_routes(WP_REST_Request $request): WP_REST_Response {
        $user_id = (int)($request->get_param('user_id') ?? get_current_user_id());
        if (!current_user_can('manage_options') && $user_id !== get_current_user_id()) {
            $user_id = get_current_user_id();
        }
        return new WP_REST_Response([
            'success' => true,
            'data'    => $this->get_cv_routes($user_id),
            'cv_only_configured' => (bool)get_user_meta($user_id, '_gcrev_cv_only_configured', true),
            'phone_event_name'   => get_user_meta($user_id, '_gcrev_phone_event_name', true) ?: '',
        ], 200);
    }

    public function rest_save_cv_routes(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $params  = $request->get_json_params();
        $user_id = (int)($params['user_id'] ?? get_current_user_id());
        $routes  = $params['routes'] ?? [];
        if (!is_array($routes)) return new WP_REST_Response(['success'=>false,'message'=>'不正なデータ'], 400);

        if (!current_user_can('manage_options') && $user_id !== get_current_user_id()) {
            $user_id = get_current_user_id();
        }

        // 最大10件バリデーション
        if (count($routes) > 10) {
            return new WP_REST_Response(['success'=>false,'message'=>'キーイベントは最大10件まで設定できます'], 400);
        }

        // 設定済みイベントのみ使用フラグ & 電話タップイベント名
        if (isset($params['cv_only_configured'])) {
            update_user_meta($user_id, '_gcrev_cv_only_configured', $params['cv_only_configured'] ? '1' : '');
        }
        if (isset($params['phone_event_name'])) {
            update_user_meta($user_id, '_gcrev_phone_event_name', sanitize_text_field($params['phone_event_name']));
        }

        error_log("[GCREV] rest_save_cv_routes: START user_id={$user_id}, routes_count=" . count($routes));

        $table = $wpdb->prefix . 'gcrev_cv_routes';

        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            error_log("[GCREV] rest_save_cv_routes: table {$table} does not exist");
            return new WP_REST_Response(['success'=>false,'message'=>'テーブルが見つかりません'], 500);
        }

        // 送信されたルートキーを収集
        $sent_keys = [];
        foreach ($routes as $r) {
            $rk = sanitize_text_field($r['route_key'] ?? '');
            if ($rk !== '') $sent_keys[] = $rk;
        }

        // このユーザーの既存ルートのうち、送信リストにないものを削除
        $existing_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT route_key FROM {$table} WHERE user_id = %d",
            $user_id
        ));
        foreach ($existing_keys as $ek) {
            if (!in_array($ek, $sent_keys, true)) {
                $wpdb->delete($table, ['user_id' => $user_id, 'route_key' => $ek], ['%d', '%s']);
                error_log("[GCREV] rest_save_cv_routes: DELETED rk={$ek} for user_id={$user_id}");
            }
        }

        $updated = 0;
        $errors = [];
        foreach ($routes as $r) {
            $rk = sanitize_text_field($r['route_key'] ?? '');
            if (empty($rk)) continue;

            $label   = sanitize_text_field($r['label'] ?? '');
            $enabled = 1; // ハイブリッドモードでは追加=有効
            $order   = (int)($r['sort_order'] ?? 0);

            // 既存チェック
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d AND route_key = %s",
                $user_id, $rk
            ));

            if ($exists) {
                $res = $wpdb->update($table,
                    ['label' => $label, 'enabled' => $enabled, 'sort_order' => $order],
                    ['id' => (int)$exists],
                    ['%s', '%d', '%d'],
                    ['%d']
                );
                if ($res === false) {
                    $err = $wpdb->last_error;
                    error_log("[GCREV] rest_save_cv_routes: UPDATE FAILED rk={$rk}, error={$err}");
                    $errors[] = "UPDATE {$rk}: {$err}";
                }
            } else {
                $res = $wpdb->insert($table, [
                    'user_id' => $user_id, 'route_key' => $rk,
                    'label' => $label, 'enabled' => $enabled, 'sort_order' => $order,
                ], ['%d','%s','%s','%d','%d']);
                if ($res === false) {
                    $err = $wpdb->last_error;
                    error_log("[GCREV] rest_save_cv_routes: INSERT FAILED rk={$rk}, error={$err}");
                    $errors[] = "INSERT {$rk}: {$err}";
                }
            }
            $updated++;
        }

        error_log("[GCREV] rest_save_cv_routes: DONE user_id={$user_id}, updated={$updated}, errors=" . count($errors));

        // Effective CVトランジェントキャッシュを独立クリア（ルート変更は全月に影響）
        $this->invalidate_effective_cv_transients($user_id);
        // ダッシュボード・レポート等のキャッシュも包括的に無効化
        if (function_exists('gcrev_invalidate_user_cv_cache')) {
            gcrev_invalidate_user_cv_cache($user_id);
        }

        if (!empty($errors)) {
            return new WP_REST_Response([
                'success' => true,
                'data'    => ['updated' => $updated, 'errors' => $errors],
                'message' => 'DBエラーあり: ' . implode('; ', $errors),
            ], 200);
        }

        return new WP_REST_Response(['success'=>true,'data'=>['updated'=>$updated]], 200);
    }

    /**
     * REST: GA4キーイベント一覧取得（設定画面のdatalist用）
     */
    public function rest_get_ga4_key_events(WP_REST_Request $request): WP_REST_Response {
        $user_id = (int)($request->get_param('user_id') ?? get_current_user_id());
        if (!current_user_can('manage_options') && $user_id !== get_current_user_id()) {
            $user_id = get_current_user_id();
        }
        $month = sanitize_text_field($request->get_param('month') ?? date('Y-m'));

        $dt = new \DateTime($month . '-01');
        $days = (int)$dt->format('t');
        $start = $month . '-01';
        $end   = $month . '-' . str_pad((string)$days, 2, '0', STR_PAD_LEFT);

        // 6時間キャッシュ
        $cache_key = "gcrev_ga4_kevt_list_{$user_id}_{$month}";
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return new WP_REST_Response(['success' => true, 'data' => $cached], 200);
        }

        try {
            $config = $this->config->get_user_config($user_id);
            $events = $this->ga4->fetch_ga4_key_events($config['ga4_id'], $start, $end);
            set_transient($cache_key, $events, 6 * HOUR_IN_SECONDS);
            return new WP_REST_Response(['success' => true, 'data' => $events], 200);
        } catch (\Exception $e) {
            error_log("[GCREV] rest_get_ga4_key_events ERROR: " . $e->getMessage());
            return new WP_REST_Response(['success' => false, 'message' => 'GA4取得エラー'], 500);
        }
    }

    /**
     * REST: 実質CV取得（routes対応版）
     */
    public function rest_get_actual_cv(WP_REST_Request $request): WP_REST_Response {
        $month   = sanitize_text_field($request->get_param('month') ?? date('Y-m'));
        $user_id = (int)($request->get_param('user_id') ?? get_current_user_id());
        if (!current_user_can('manage_options') && $user_id !== get_current_user_id()) {
            $user_id = get_current_user_id();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_actual_cvs';
        $dt = new \DateTime($month . '-01');
        $days = (int)$dt->format('t');
        $start = $month . '-01';
        $end = $month . '-' . str_pad((string)$days, 2, '0', STR_PAD_LEFT);

        $rows = [];
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT cv_date, route, cv_count FROM {$table}
                 WHERE user_id=%d AND cv_date BETWEEN %s AND %s ORDER BY cv_date ASC",
                $user_id, $start, $end
            ), ARRAY_A);
        }

        $enabled_routes = $this->get_enabled_cv_routes($user_id);
        $route_keys = array_column($enabled_routes, 'route_key');

        $items = [];
        for ($d = 1; $d <= $days; $d++) {
            $dk = $month . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            $items[$dk] = [];
            foreach ($route_keys as $rk) $items[$dk][$rk] = null;
        }
        foreach ($rows as $row) {
            if (isset($items[$row['cv_date']]) && in_array($row['route'], $route_keys, true)) {
                $items[$row['cv_date']][$row['route']] = (int)$row['cv_count'];
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['items' => $items, 'routes' => $enabled_routes],
        ], 200);
    }

    /**
     * REST: 実質CV保存
     */
    public function rest_save_actual_cv(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $params  = $request->get_json_params();
        $user_id = (int)($params['user_id'] ?? get_current_user_id());
        $month   = sanitize_text_field($params['month'] ?? date('Y-m'));
        $items   = $params['items'] ?? [];

        if (!current_user_can('manage_options') && $user_id !== get_current_user_id()) {
            $user_id = get_current_user_id();
        }

        if (!is_array($items)) {
            return new WP_REST_Response(['success' => false, 'message' => '不正なデータ'], 400);
        }

        $table = $wpdb->prefix . 'gcrev_actual_cvs';
        // テーブル存在確認
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            return new WP_REST_Response(['success' => false, 'message' => 'テーブルが見つかりません'], 500);
        }

        $saved = 0;
        $deleted = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $date  = sanitize_text_field($item['date'] ?? '');
            $route = sanitize_text_field($item['route'] ?? '');
            $count = $item['count'] ?? null;

            if (empty($date) || empty($route) || $route === 'phone') {
                $skipped++;
                continue;
            }

            if ($count === null) {
                // null = 未入力 → 削除
                $del = $wpdb->delete($table, [
                    'user_id' => $user_id,
                    'cv_date' => $date,
                    'route'   => $route,
                ], ['%d', '%s', '%s']);
                if ($del) $deleted++;
            } else {
                $count = max(0, min(99, (int)$count));
                // UPSERT: INSERT ON DUPLICATE KEY UPDATE
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE user_id=%d AND cv_date=%s AND route=%s",
                    $user_id, $date, $route
                ));
                if ($existing) {
                    $wpdb->update($table,
                        ['cv_count' => $count],
                        ['id' => $existing],
                        ['%d'], ['%d']
                    );
                } else {
                    $wpdb->insert($table, [
                        'user_id'  => $user_id,
                        'cv_date'  => $date,
                        'route'    => $route,
                        'cv_count' => $count,
                    ], ['%d', '%s', '%s', '%d']);
                }
                $saved++;
            }
        }

        // キャッシュクリア（KPI・CV再計算のため）
        $ym_parts = explode('-', $month);
        if (count($ym_parts) === 2) {
            delete_transient("gcrev_dash_{$user_id}_previousMonth");
            delete_transient("gcrev_dash_{$user_id}_twoMonthsAgo");
            // GA4 CVキャッシュもクリア
            delete_transient("gcrev_phone_tap_{$user_id}_{$month}");
            delete_transient("gcrev_ga4cv_{$user_id}_{$month}");
            // GA4キーイベント日別キャッシュもクリア（effective CV再計算用）
            delete_transient("gcrev_ga4kevt_daily_{$user_id}_{$month}");
            // 前月分もクリア（比較用）
            $prev_dt = new \DateTime($month . '-01');
            $prev_dt->modify('-1 month');
            $prev_ym = $prev_dt->format('Y-m');
            delete_transient("gcrev_phone_tap_{$user_id}_{$prev_ym}");
            delete_transient("gcrev_ga4cv_{$user_id}_{$prev_ym}");
            delete_transient("gcrev_ga4kevt_daily_{$user_id}_{$prev_ym}");
        }
        // Effective CVトランジェントキャッシュを独立クリア（functions.phpに依存しない）
        $this->invalidate_effective_cv_transients($user_id);
        // ダッシュボード・レポートキャッシュを包括的に無効化
        if (function_exists('gcrev_invalidate_user_cv_cache')) {
            gcrev_invalidate_user_cv_cache($user_id);
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['saved' => $saved, 'deleted' => $deleted],
        ], 200);
    }

    /**
     * REST: 実質CV入力対象ユーザー一覧（admin用）
     */
    public function rest_get_actual_cv_users(WP_REST_Request $request): WP_REST_Response {
        $users = get_users([
            'role__in' => ['administrator', 'editor', 'subscriber'],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ]);

        $data = [];
        foreach ($users as $u) {
            // gcrev設定があるユーザーのみ
            $config = get_user_meta($u->ID, '_gcrev_config', true);
            if (!empty($config)) {
                $data[] = [
                    'id'   => $u->ID,
                    'name' => $u->display_name,
                ];
            }
        }

        return new WP_REST_Response(['success' => true, 'data' => $data], 200);
    }

    // =========================================================
    // 実質CV（Actual CV）集計・CVソース判定
    // =========================================================

    /**
     * 実質CV の月次合計を取得
     */
    public function get_actual_cv_month_totals(string $year_month, int $user_id): array {
        global $wpdb;

        $table = $wpdb->prefix . 'gcrev_actual_cvs';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table)
        );
        if (!$table_exists) {
            return ['has_any' => false, 'total' => 0, 'detail' => []];
        }

        $start = $year_month . '-01';
        $dt = new \DateTime($start);
        $dt->modify('last day of this month');
        $end = $dt->format('Y-m-d');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT route, SUM(cv_count) AS route_total, COUNT(*) AS row_count
                 FROM {$table}
                 WHERE user_id = %d AND cv_date BETWEEN %s AND %s
                 GROUP BY route",
                $user_id, $start, $end
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return ['has_any' => false, 'total' => 0, 'detail' => []];
        }

        $detail = [];
        $total  = 0;
        foreach ($rows as $row) {
            $route = $row['route'];
            $val   = (int)$row['route_total'];
            $detail[$route] = $val;
            $total += $val;
        }

        return [
            'has_any' => true,
            'total'   => $total,
            'detail'  => $detail,
        ];
    }

    /**
     * CVソース判定 - ハイブリッド方式のサマリー
     * get_effective_cv_monthly() を利用
     */
    public function get_effective_cv_summary(string $year_month, int $user_id, ?int $ga4_cv = null): array {
        $eff = $this->get_effective_cv_monthly($year_month, $user_id);
        return [
            'source'     => $eff['source'],
            'cv'         => $eff['total'],
            'detail'     => $eff['breakdown_manual'] ?? [],
            'has_actual' => ($eff['source'] !== 'ga4'),
            'ga4_cv'     => $eff['components']['ga4_total'] ?? ($ga4_cv ?? 0),
        ];
    }

    /**
     * 期間文字列から year_month を推定するヘルパー
     */
    private function period_to_year_month(string $period): ?string {
        $tz = wp_timezone();
        switch ($period) {
            case 'prev-month':
            case 'previousMonth':
                $dt = new \DateTimeImmutable('first day of last month', $tz);
                return $dt->format('Y-m');
            case 'prev-prev-month':
            case 'twoMonthsAgo':
                $dt = new \DateTimeImmutable('first day of 2 months ago', $tz);
                return $dt->format('Y-m');
            default:
                return null;
        }
    }

    // =========================================================
    // CV分析ページ用エンドポイント
    // =========================================================

    /**
     * REST: CV分析ページ用データ取得
     * - effective CV（実質CV優先）
     * - デバイス別 × CV
     * - 流入元別 × CV
     * - 年齢別 × CV
     * - 地域別 × CV
     * - ページ別 × CV
     * - キーワード × CV（GSC）
     */
    public function rest_get_cv_analysis(WP_REST_Request $request): WP_REST_Response {
        $period  = $request->get_param('period') ?? 'prev-month';
        $user_id = get_current_user_id();

        error_log("[GCREV] REST get_cv_analysis: user_id={$user_id}, period={$period}");

        try {
            $config  = $this->config->get_user_config($user_id);
            $ga4_id  = $config['ga4_id'];

            // 期間計算
            $dates      = $this->dates->calculate_period_dates($period);
            $comparison = $this->dates->calculate_comparison_dates($period);

            $start      = $dates['start'];
            $end        = $dates['end'];
            $comp_start = $comparison['start'];
            $comp_end   = $comparison['end'];

            // キャッシュ
            $cache_key = "gcrev_cv_analysis_{$user_id}_{$period}_" . md5("{$start}_{$end}");
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                // キャッシュでもeffective CVだけ再計算（入力変更即時反映のため）
                $year_month = $this->period_to_year_month($period);
                if ($year_month) {
                    $eff = $this->get_effective_cv_monthly($year_month, $user_id);
                    $cached['data']['effective_cv'] = $eff;

                    // 再配分も再計算（手動CV変更の即時反映）
                    $ct = $eff['total'] ?? 0;
                    $cached['data']['device_realloc']  = $this->build_cv_dimension_payload($cached['data']['device_cv']  ?? [], $ct);
                    $cached['data']['source_realloc']  = $this->build_cv_dimension_payload($cached['data']['source_cv']  ?? [], $ct);
                    $cached['data']['age_realloc']     = $this->build_cv_dimension_payload($cached['data']['age_cv']     ?? [], $ct);
                    $cached['data']['region_realloc']  = $this->build_cv_dimension_payload($cached['data']['region_cv']  ?? [], $ct);
                    $cached['data']['page_realloc']    = $this->build_cv_dimension_payload($cached['data']['page_cv']    ?? [], $ct);

                    $comp_ym = $this->get_comparison_year_month($period);
                    if ($comp_ym) {
                        $comp_eff = $this->get_effective_cv_monthly($comp_ym, $user_id);
                        $cached['data']['effective_cv_prev'] = $comp_eff;

                        $cp = $comp_eff['total'] ?? 0;
                        $cached['data']['device_realloc_prev'] = $this->build_cv_dimension_payload($cached['data']['device_cv_prev'] ?? [], $cp);
                        $cached['data']['source_realloc_prev'] = $this->build_cv_dimension_payload($cached['data']['source_cv_prev'] ?? [], $cp);
                    }
                }
                error_log("[GCREV] CV analysis cache HIT: {$cache_key}");
                return new WP_REST_Response($cached, 200);
            }

            // ===== GA4 Data API クライアント =====
            $client   = new BetaAnalyticsDataClient(['credentials' => $this->service_account_path]);
            $property = 'properties/' . $ga4_id;

            // ===== 1. effective CV（実質CV優先） =====
            $year_month        = $this->period_to_year_month($period);
            $effective_cv      = $year_month ? $this->get_effective_cv_monthly($year_month, $user_id) : null;
            $comp_ym           = $this->get_comparison_year_month($period);
            $effective_cv_prev = $comp_ym ? $this->get_effective_cv_monthly($comp_ym, $user_id) : null;

            // ===== 2. デバイス別 × CV =====
            $device_cv      = $this->fetch_cv_by_dimension($client, $property, 'deviceCategory', $start, $end);
            $device_cv_prev = $this->fetch_cv_by_dimension($client, $property, 'deviceCategory', $comp_start, $comp_end);

            // ===== 3. 流入元別 × CV =====
            $source_cv      = $this->fetch_cv_by_dimension($client, $property, 'sessionDefaultChannelGroup', $start, $end);
            $source_cv_prev = $this->fetch_cv_by_dimension($client, $property, 'sessionDefaultChannelGroup', $comp_start, $comp_end);

            // ===== 4. 年齢別 × CV =====
            $age_cv = $this->fetch_cv_by_dimension($client, $property, 'userAgeBracket', $start, $end);

            // ===== 5. 地域別 × CV =====
            $region_cv = $this->fetch_cv_by_dimension($client, $property, 'city', $start, $end);

            // ===== 6. ページ別 × CV =====
            $page_cv = $this->fetch_cv_by_dimension($client, $property, 'pagePath', $start, $end);

            // ===== 7. キーワード × CV（GSCデータ） =====
            $keywords_data = $this->fetch_gsc_keywords_for_cv($config, $start, $end);

            // ===== 8. CV日別推移（effective CVベース） =====
            $cv_daily      = $effective_cv['daily'] ?? [];
            $cv_daily_prev = $effective_cv_prev['daily'] ?? [];

            // ===== 9. 確定CV × GA4分布 再配分 =====
            $confirmed_total = $effective_cv['total'] ?? 0;
            $device_realloc  = $this->build_cv_dimension_payload($device_cv,  $confirmed_total);
            $source_realloc  = $this->build_cv_dimension_payload($source_cv,  $confirmed_total);
            $age_realloc     = $this->build_cv_dimension_payload($age_cv,     $confirmed_total);
            $region_realloc  = $this->build_cv_dimension_payload($region_cv,  $confirmed_total);
            $page_realloc    = $this->build_cv_dimension_payload($page_cv,    $confirmed_total);
            // 前期比較用
            $confirmed_prev       = $effective_cv_prev['total'] ?? 0;
            $device_realloc_prev  = $this->build_cv_dimension_payload($device_cv_prev,  $confirmed_prev);
            $source_realloc_prev  = $this->build_cv_dimension_payload($source_cv_prev,  $confirmed_prev);

            // ===== 結果組み立て =====
            $result = [
                'success' => true,
                'data'    => [
                    // 期間情報
                    'current_period'       => ['start' => $start, 'end' => $end],
                    'comparison_period'    => ['start' => $comp_start, 'end' => $comp_end],
                    'current_range_label'  => str_replace('-', '/', $start) . ' 〜 ' . str_replace('-', '/', $end),
                    'compare_range_label'  => str_replace('-', '/', $comp_start) . ' 〜 ' . str_replace('-', '/', $comp_end),

                    // CV総合
                    'effective_cv'         => $effective_cv,
                    'effective_cv_prev'    => $effective_cv_prev,

                    // 各軸 × CV
                    'device_cv'            => $device_cv,
                    'device_cv_prev'       => $device_cv_prev,
                    'source_cv'            => $source_cv,
                    'source_cv_prev'       => $source_cv_prev,
                    'age_cv'               => $age_cv,
                    'region_cv'            => $region_cv,
                    'page_cv'              => $page_cv,
                    'keywords_data'        => $keywords_data,

                    // CV日別推移
                    'cv_daily'             => $cv_daily,
                    'cv_daily_prev'        => $cv_daily_prev,

                    // 再配分データ（確定CV × GA4分布 按分モデル）
                    'device_realloc'       => $device_realloc,
                    'source_realloc'       => $source_realloc,
                    'age_realloc'          => $age_realloc,
                    'region_realloc'       => $region_realloc,
                    'page_realloc'         => $page_realloc,
                    'device_realloc_prev'  => $device_realloc_prev,
                    'source_realloc_prev'  => $source_realloc_prev,
                ],
            ];

            // キャッシュ保存（12時間 - effective CVは動的再計算するため短めに）
            set_transient($cache_key, $result, 43200);

            return new WP_REST_Response($result, 200);

        } catch (\Exception $e) {
            error_log("[GCREV] REST get_cv_analysis ERROR: " . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GA4 次元別 × keyEvents 取得（汎用）
     * sessionCount も同時取得してCV率を計算可能に
     */
    private function fetch_cv_by_dimension(
        BetaAnalyticsDataClient $client,
        string $property,
        string $dimension_name,
        string $start,
        string $end
    ): array {
        try {
            $request = new RunReportRequest();
            $request->setProperty($property);
            $request->setDateRanges([
                (new DateRange())->setStartDate($start)->setEndDate($end)
            ]);
            $request->setDimensions([
                (new Dimension())->setName($dimension_name)
            ]);
            $request->setMetrics([
                (new Metric())->setName('sessions'),
                (new Metric())->setName('totalUsers'),
                (new Metric())->setName('keyEvents'),
                (new Metric())->setName('screenPageViews'),
            ]);
            // セッション数で降順ソート
            $request->setOrderBys([
                (new OrderBy())->setMetric(
                    (new OrderBy\MetricOrderBy())->setMetricName('sessions')
                )->setDesc(true)
            ]);

            $response = $client->runReport($request);
            $items = [];

            foreach ($response->getRows() as $row) {
                $dim_value = $row->getDimensionValues()[0]->getValue();
                $metrics   = $row->getMetricValues();

                $sessions   = (int)$metrics[0]->getValue();
                $users      = (int)$metrics[1]->getValue();
                $key_events = (int)$metrics[2]->getValue();
                $pageviews  = (int)$metrics[3]->getValue();

                $items[] = [
                    'dimension'  => $dim_value,
                    'sessions'   => $sessions,
                    'users'      => $users,
                    'keyEvents'  => $key_events,
                    'pageviews'  => $pageviews,
                    'cvr'        => $sessions > 0 ? round($key_events / $sessions * 100, 2) : 0,
                ];
            }

            return $items;

        } catch (\Exception $e) {
            error_log("[GCREV] fetch_cv_by_dimension({$dimension_name}) ERROR: " . $e->getMessage());
            return [];
        }
    }

    /**
     * GSCキーワードデータ取得（CV分析用）
     */
    private function fetch_gsc_keywords_for_cv(array $config, string $start, string $end): array {
        try {
            $gsc_url = $config['gsc_url'] ?? '';
            if (empty($gsc_url)) return [];

            $g_client = new GoogleClient();
            $g_client->setAuthConfig($this->service_account_path);
            $g_client->addScope(GoogleWebmasters::WEBMASTERS_READONLY);

            $webmasters = new GoogleWebmasters($g_client);
            $query_request = new SearchAnalyticsQueryRequest();
            $query_request->setStartDate($start);
            $query_request->setEndDate($end);
            $query_request->setDimensions(['query']);
            $query_request->setRowLimit(20);

            $response = $webmasters->searchanalytics->query($gsc_url, $query_request);
            $keywords = [];

            foreach ($response->getRows() as $row) {
                $keywords[] = [
                    'keyword'     => $row->getKeys()[0],
                    'clicks'      => (int)$row->getClicks(),
                    'impressions' => (int)$row->getImpressions(),
                    'ctr'         => round($row->getCtr() * 100, 2),
                    'position'    => round($row->getPosition(), 1),
                ];
            }

            return $keywords;

        } catch (\Exception $e) {
            error_log("[GCREV] fetch_gsc_keywords_for_cv ERROR: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 比較期間のyear_monthを取得するヘルパー
     */
    private function get_comparison_year_month(string $period): ?string {
        $tz = wp_timezone();
        switch ($period) {
            case 'prev-month':
            case 'previousMonth':
                $dt = new \DateTimeImmutable('first day of 2 months ago', $tz);
                return $dt->format('Y-m');
            case 'prev-prev-month':
            case 'twoMonthsAgo':
                $dt = new \DateTimeImmutable('first day of 3 months ago', $tz);
                return $dt->format('Y-m');
            default:
                return null;
        }
    }

    // =========================================================
    // CV再配分ロジック（確定CV × GA4分布 按分モデル）
    // =========================================================

    /**
     * GA4 keyEvents数 → 比率マップに変換（合計1.0）
     *
     * @param  array $ga4_rows  fetch_cv_by_dimension() の戻り値
     * @return array  [ dimension_label => float ratio, ... ]  合計0 → 空配列
     */
    private function calc_ratio_map(array $ga4_rows): array {
        $total_ke = 0;
        foreach ($ga4_rows as $row) {
            $total_ke += (int)($row['keyEvents'] ?? 0);
        }
        if ($total_ke === 0) {
            return [];
        }
        $map = [];
        foreach ($ga4_rows as $row) {
            $label = $row['dimension'] ?? '(unknown)';
            $map[$label] = (int)($row['keyEvents'] ?? 0) / $total_ke;
        }
        return $map;
    }

    /**
     * 最大剰余法（ハミルトン法）で整数按分
     * 保証: array_sum(result) === $total
     *
     * @param  int   $total      按分する合計値（確定CV）
     * @param  array $ratio_map  [ label => float ratio ]  合計≒1.0
     * @return array             [ label => int allocated ]
     */
    private function allocate_largest_remainder(int $total, array $ratio_map): array {
        if (empty($ratio_map) || $total <= 0) {
            // 比率マップが空 or 合計0以下 → 全て0
            $result = [];
            foreach ($ratio_map as $label => $_) {
                $result[$label] = 0;
            }
            return $result;
        }

        $floors     = [];
        $remainders = [];
        $sum_floors = 0;

        foreach ($ratio_map as $label => $ratio) {
            $exact      = $ratio * $total;
            $f          = (int)floor($exact);
            $floors[$label]     = $f;
            $remainders[$label] = $exact - $f;
            $sum_floors += $f;
        }

        // 不足分を剰余の大きい順に+1
        $deficit = $total - $sum_floors;
        arsort($remainders);
        $i = 0;
        foreach ($remainders as $label => $_) {
            if ($i >= $deficit) break;
            $floors[$label]++;
            $i++;
        }

        return $floors;
    }

    /**
     * GA4ディメンション行 + 確定CV合計 → 再配分ペイロード生成
     *
     * @param  array $ga4_rows        fetch_cv_by_dimension() の戻り値
     * @param  int   $confirmed_total 確定CV合計
     * @return array  status / confirmed_total / ga4_total / confidence_ratio / rows
     */
    private function build_cv_dimension_payload(array $ga4_rows, int $confirmed_total): array {
        // GA4合計
        $ga4_total = 0;
        foreach ($ga4_rows as $row) {
            $ga4_total += (int)($row['keyEvents'] ?? 0);
        }

        // データなし
        if (empty($ga4_rows)) {
            return [
                'status'           => 'no_data',
                'confirmed_total'  => $confirmed_total,
                'ga4_total'        => 0,
                'confidence_ratio' => null,
                'rows'             => [],
            ];
        }

        // GA4 keyEvents合計が0 → フォールバック（按分不可）
        if ($ga4_total === 0) {
            $fallback_rows = [];
            foreach ($ga4_rows as $row) {
                $fallback_rows[] = [
                    'label'             => $row['dimension'] ?? '(unknown)',
                    'sessions'          => (int)($row['sessions'] ?? 0),
                    'users'             => (int)($row['users'] ?? 0),
                    'pageviews'         => (int)($row['pageviews'] ?? 0),
                    'ga4_count'         => 0,
                    'ga4_ratio'         => 0.0,
                    'reallocated_count' => 0,
                    'reallocated_cvr'   => 0.0,
                ];
            }
            return [
                'status'           => 'fallback_ga4',
                'confirmed_total'  => $confirmed_total,
                'ga4_total'        => 0,
                'confidence_ratio' => null,
                'rows'             => $fallback_rows,
            ];
        }

        // 正常パス: 比率算出 → 最大剰余法で按分
        $ratio_map = $this->calc_ratio_map($ga4_rows);
        $allocated = $this->allocate_largest_remainder($confirmed_total, $ratio_map);

        $rows = [];
        foreach ($ga4_rows as $row) {
            $label    = $row['dimension'] ?? '(unknown)';
            $sessions = (int)($row['sessions'] ?? 0);
            $ke       = (int)($row['keyEvents'] ?? 0);
            $alloc    = $allocated[$label] ?? 0;

            $rows[] = [
                'label'             => $label,
                'sessions'          => $sessions,
                'users'             => (int)($row['users'] ?? 0),
                'pageviews'         => (int)($row['pageviews'] ?? 0),
                'ga4_count'         => $ke,
                'ga4_ratio'         => round($ke / $ga4_total, 4),
                'reallocated_count' => $alloc,
                'reallocated_cvr'   => $sessions > 0 ? round($alloc / $sessions * 100, 2) : 0.0,
            ];
        }

        return [
            'status'           => 'ok',
            'confirmed_total'  => $confirmed_total,
            'ga4_total'        => $ga4_total,
            'confidence_ratio' => $ga4_total > 0 ? round($confirmed_total / $ga4_total, 4) : null,
            'rows'             => $rows,
        ];
    }

    // =========================================================
    // ダッシュボードKPIトレンド（過去12ヶ月推移）
    // =========================================================

    /**
     * REST callback: /gcrev/v1/dashboard/trends?metric=sessions|cv|meo
     */
    public function rest_get_metric_trend(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $user_id = get_current_user_id();
            if (!$user_id) {
                return new \WP_REST_Response(['success' => false, 'message' => 'ログインが必要です'], 401);
            }

            $metric = $request->get_param('metric');
            $result = $this->get_monthly_metric_trend($user_id, $metric, 12);

            return new \WP_REST_Response($result, 200);

        } catch (\Exception $e) {
            error_log("[GCREV][Trend] Error: " . $e->getMessage());
            return new \WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * REST callback: /gcrev/v1/dashboard/drilldown?month=YYYY-MM&type=region|page|source
     * 指定月の内訳データ（TOP10）を返す
     */
    public function rest_get_dashboard_drilldown(\WP_REST_Request $request): \WP_REST_Response {
        try {
            $user_id = get_current_user_id();
            if ( ! $user_id ) {
                return new \WP_REST_Response(['success' => false, 'message' => 'ログインが必要です'], 401);
            }

            $month  = $request->get_param('month');    // "2026-01"
            $type   = $request->get_param('type');     // "region"|"page"|"source"
            $metric = $request->get_param('metric') ?? 'sessions';

            $config  = $this->config->get_user_config( $user_id );
            $ga4_id  = $config['ga4_id'] ?? '';
            if ( empty( $ga4_id ) ) {
                return new \WP_REST_Response(['success' => false, 'message' => 'GA4 未設定'], 400);
            }

            // 月 → 日付範囲
            $start = $month . '-01';
            $end   = date('Y-m-t', strtotime( $start ));

            // キャッシュ（24時間）
            $cache_key = "gcrev_drilldown_{$user_id}_{$month}_{$type}";
            $cached = get_transient( $cache_key );
            if ( $cached !== false && is_array( $cached ) ) {
                return new \WP_REST_Response( $cached, 200 );
            }

            $items = [];

            // チャネル名 日本語マップ
            $channel_ja = [
                'Organic Search'   => '検索（自然）',
                'Direct'           => '直接',
                'Referral'         => '他サイト',
                'Organic Social'   => 'SNS',
                'Paid Search'      => '検索（広告）',
                'Paid Social'      => 'SNS広告',
                'Email'            => 'メール',
                'Display'          => 'ディスプレイ広告',
                'Organic Maps'     => '地図検索',
                'Organic Shopping' => 'ショッピング',
                'Unassigned'       => '不明',
                'Cross-network'    => 'クロスネットワーク',
                'Affiliates'       => 'アフィリエイト',
                '(other)'         => 'その他',
            ];

            // 指標名 日本語マップ
            $metric_ja = [
                'sessions'  => 'セッション数',
                'users'     => 'ユーザー数',
                'pageViews' => '表示回数',
            ];

            // 都道府県・地域名 英→日マップ（GA4は英語で返すため）
            $region_ja = [
                'Hokkaido'  => '北海道',   'Aomori'    => '青森県',
                'Iwate'     => '岩手県',   'Miyagi'    => '宮城県',
                'Akita'     => '秋田県',   'Yamagata'  => '山形県',
                'Fukushima' => '福島県',   'Ibaraki'   => '茨城県',
                'Tochigi'   => '栃木県',   'Gunma'     => '群馬県',
                'Saitama'   => '埼玉県',   'Chiba'     => '千葉県',
                'Tokyo'     => '東京都',   'Kanagawa'  => '神奈川県',
                'Niigata'   => '新潟県',   'Toyama'    => '富山県',
                'Ishikawa'  => '石川県',   'Fukui'     => '福井県',
                'Yamanashi' => '山梨県',   'Nagano'    => '長野県',
                'Gifu'      => '岐阜県',   'Shizuoka'  => '静岡県',
                'Aichi'     => '愛知県',   'Mie'       => '三重県',
                'Shiga'     => '滋賀県',   'Kyoto'     => '京都府',
                'Osaka'     => '大阪府',   'Hyogo'     => '兵庫県',
                'Nara'      => '奈良県',   'Wakayama'  => '和歌山県',
                'Tottori'   => '鳥取県',   'Shimane'   => '島根県',
                'Okayama'   => '岡山県',   'Hiroshima' => '広島県',
                'Yamaguchi' => '山口県',   'Tokushima' => '徳島県',
                'Kagawa'    => '香川県',   'Ehime'     => '愛媛県',
                'Kochi'     => '高知県',   'Fukuoka'   => '福岡県',
                'Saga'      => '佐賀県',   'Nagasaki'  => '長崎県',
                'Kumamoto'  => '熊本県',   'Oita'      => '大分県',
                'Miyazaki'  => '宮崎県',   'Kagoshima' => '鹿児島県',
                'Okinawa'   => '沖縄県',
            ];

            switch ( $type ) {
                case 'region':
                    $raw = $this->ga4->fetch_region_details( $ga4_id, $start, $end );
                    $raw = array_slice( $raw, 0, 10 );
                    foreach ( $raw as $r ) {
                        $region_name = $r['region'] ?: '';
                        if ( $region_name === '' || $region_name === '(not set)' ) {
                            $region_name = '（不明）';
                        } else {
                            $region_name = $region_ja[ $region_name ] ?? $region_name;
                        }
                        $items[] = [
                            'label' => $region_name,
                            'value' => (int) ( $r[ $metric ] ?? $r['sessions'] ),
                        ];
                    }
                    break;

                case 'page':
                    $site_url = $config['gsc_url'] ?? '';
                    $raw = $this->ga4->fetch_page_details( $ga4_id, $start, $end, $site_url );
                    $raw = array_slice( $raw, 0, 10 );
                    foreach ( $raw as $p ) {
                        $display = $p['title'] ?: $p['page'];
                        if ( $display === '(not set)' || $display === '' ) {
                            $display = '（不明なページ）';
                        }
                        if ( mb_strlen( $display ) > 30 ) {
                            $display = mb_substr( $display, 0, 30 ) . '…';
                        }
                        $items[] = [
                            'label' => $display,
                            'value' => (int) ( $p['pageViews'] ?? 0 ),
                        ];
                    }
                    $metric = 'pageViews';
                    break;

                case 'source':
                    $raw = $this->ga4->fetch_source_data_from_ga4( $ga4_id, $start, $end );
                    $channels = array_slice( $raw['channels'] ?? [], 0, 10 );
                    foreach ( $channels as $ch ) {
                        $ch_name = $ch['channel'] ?: '（不明）';
                        $items[] = [
                            'label' => $channel_ja[ $ch_name ] ?? $ch_name,
                            'value' => (int) ( $ch[ $metric ] ?? $ch['sessions'] ),
                        ];
                    }
                    break;
            }

            $metric_label = $metric_ja[ $metric ] ?? $metric;

            $result = [
                'success'      => true,
                'month'        => $month,
                'type'         => $type,
                'metric'       => $metric,
                'metric_label' => $metric_label,
                'items'        => $items,
            ];

            set_transient( $cache_key, $result, 86400 );
            return new \WP_REST_Response( $result, 200 );

        } catch ( \Exception $e ) {
            error_log( '[GCREV][Drilldown] Error: ' . $e->getMessage() );
            return new \WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 過去N ヶ月の月別指標値を返す
     *
     * @param int    $user_id
     * @param string $metric  sessions | cv | meo
     * @param int    $months  取得月数（デフォルト12）
     * @return array  ['success'=>true, 'metric'=>..., 'labels'=>[...], 'values'=>[...]]
     */
    public function get_monthly_metric_trend(int $user_id, string $metric, int $months = 12): array {

        // キャッシュ（cronが毎日更新するため24時間）
        $current_month = date('Y-m');
        $cache_key = "gcrev_trend_{$user_id}_{$metric}_{$current_month}";
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        // 過去N ヶ月のYYYY-MM配列（古い順）
        $labels = [];
        $now = new \DateTime('first day of this month');
        for ($i = $months - 1; $i >= 0; $i--) {
            $dt = clone $now;
            $dt->modify("-{$i} months");
            $labels[] = $dt->format('Y-m');
        }

        $values = [];

        switch ($metric) {
            case 'sessions':
                $config = $this->config->get_user_config($user_id);
                $ga4_id = $config['ga4_id'] ?? '';
                if (empty($ga4_id)) {
                    $values = array_fill(0, $months, 0);
                    break;
                }
                foreach ($labels as $ym) {
                    $start = $ym . '-01';
                    $end   = date('Y-m-t', strtotime($start));
                    try {
                        $summary = $this->ga4->fetch_ga4_summary($ga4_id, $start, $end);
                        $values[] = $summary['_sessions'] ?? 0;
                    } catch (\Exception $e) {
                        error_log("[GCREV][Trend] GA4 sessions error ({$ym}): " . $e->getMessage());
                        $values[] = 0;
                    }
                }
                break;

            case 'cv':
                foreach ($labels as $ym) {
                    $cv = $this->get_effective_cv_monthly($ym, $user_id);
                    $values[] = $cv['total'] ?? 0;
                }
                break;

            case 'meo':
                $location_id  = get_user_meta($user_id, '_gcrev_gbp_location_id', true);
                $is_pending   = (!empty($location_id) && strpos($location_id, 'pending_') === 0);
                $access_token = '';
                if (!empty($location_id) && !$is_pending) {
                    $access_token = $this->gbp_get_access_token($user_id);
                }
                if (empty($location_id) || empty($access_token) || $is_pending) {
                    $values = array_fill(0, $months, 0);
                    break;
                }
                foreach ($labels as $ym) {
                    $start = $ym . '-01';
                    $end   = date('Y-m-t', strtotime($start));
                    try {
                        $m = $this->gbp_fetch_performance_metrics($access_token, $location_id, $start, $end);
                        $values[] = ($m['search_impressions'] ?? 0) + ($m['map_impressions'] ?? 0);
                    } catch (\Exception $e) {
                        error_log("[GCREV][Trend] MEO error ({$ym}): " . $e->getMessage());
                        $values[] = 0;
                    }
                }
                break;

            default:
                $values = array_fill(0, $months, 0);
        }

        $result = [
            'success' => true,
            'metric'  => $metric,
            'labels'  => $labels,
            'values'  => $values,
        ];

        // TTL: 24時間（cronが毎日更新）
        set_transient($cache_key, $result, DAY_IN_SECONDS);

        return $result;
    }

    // =========================================================
    // CVログ精査
    // =========================================================

    /**
     * GET /gcrev/v1/cv-review?month=YYYY-MM
     * GA4 CVイベント詳細を取得し、DB保存済みステータスとマージ
     */
    public function rest_get_cv_review(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $month   = sanitize_text_field($request->get_param('month'));

        // CVイベント名を取得
        $routes = $this->get_enabled_cv_routes($user_id);
        $event_names = array_map(fn($r) => $r['route_key'], $routes);

        // 電話タップイベントも追加
        $phone_event = get_user_meta($user_id, '_gcrev_phone_event_name', true);
        if ($phone_event && !in_array($phone_event, $event_names, true)) {
            $event_names[] = $phone_event;
        }

        if (empty($event_names)) {
            return new WP_REST_Response([
                'success' => true,
                'rows'    => [],
                'total_events' => 0,
                'message' => 'CVイベントが設定されていません。CV設定ページから設定してください。',
            ], 200);
        }

        // Transientキャッシュ（30分）
        $cache_key = "gcrev_cvreview_{$user_id}_{$month}";
        $cached = get_transient($cache_key);

        if ($cached !== false && is_array($cached)) {
            // キャッシュヒットでも重複統合 + DB statusマージを適用
            $deduped = $this->dedup_cv_review_rows($cached['rows']);
            $merged  = $this->merge_cv_review_statuses($deduped, $user_id, $month);
            return new WP_REST_Response([
                'success'      => true,
                'rows'         => $merged,
                'total_events' => $cached['total_events'],
            ], 200);
        }

        try {
            $config = $this->config->get_user_config($user_id);
            $ga4_id = $config['ga4_id'];

            // 月の開始日・終了日
            $dt = new \DateTime($month . '-01');
            $start = $dt->format('Y-m-d');
            $end   = $dt->format('Y-m-t');

            $result = $this->ga4->fetch_cv_event_detail($ga4_id, $start, $end, $event_names);

            // 同一 dateHourMinute + eventName の行を統合
            $result['rows'] = $this->dedup_cv_review_rows($result['rows']);

            // キャッシュ保存（統合済みGA4データ）
            set_transient($cache_key, $result, 1800); // 30分

            // DBステータスマージ
            $merged = $this->merge_cv_review_statuses($result['rows'], $user_id, $month);

            return new WP_REST_Response([
                'success'      => true,
                'rows'         => $merged,
                'total_events' => $result['total_events'],
            ], 200);

        } catch (\Throwable $e) {
            error_log('[GCREV] rest_get_cv_review error: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'GA4データの取得に失敗しました: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /gcrev/v1/cv-review/update
     * 1行のステータス・メモを更新（UPSERT）
     */
    public function rest_update_cv_review(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $params  = $request->get_json_params();
        $user_id = get_current_user_id();

        $month           = sanitize_text_field($params['month'] ?? '');
        $row_hash        = sanitize_text_field($params['row_hash'] ?? '');
        $status          = isset($params['status']) ? (int)$params['status'] : null;
        $memo            = isset($params['memo']) ? sanitize_text_field($params['memo']) : null;
        $event_name      = sanitize_text_field($params['event_name'] ?? '');
        $date_hour_minute = sanitize_text_field($params['date_hour_minute'] ?? '');
        $page_path       = sanitize_text_field($params['page_path'] ?? '');
        $source_medium   = sanitize_text_field($params['source_medium'] ?? '');
        $device_category = sanitize_text_field($params['device_category'] ?? '');
        $country         = sanitize_text_field($params['country'] ?? '');
        $event_count     = isset($params['event_count']) ? (int)$params['event_count'] : 1;

        // バリデーション
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return new WP_REST_Response(['success' => false, 'message' => '月の形式が不正です'], 400);
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $row_hash)) {
            return new WP_REST_Response(['success' => false, 'message' => 'ハッシュが不正です'], 400);
        }
        if ($status !== null && !in_array($status, [0, 1, 2], true)) {
            return new WP_REST_Response(['success' => false, 'message' => 'ステータスが不正です'], 400);
        }

        $table = $wpdb->prefix . 'gcrev_cv_review';

        // テーブル存在チェック — 無ければ作成
        $this->ensure_cv_review_table($table);

        $now   = current_time('mysql');

        // 既存行チェック
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND year_month = %s AND row_hash = %s",
            $user_id, $month, $row_hash
        ));

        if ($existing_id) {
            // UPDATE
            $update_data = ['updated_by' => $user_id, 'updated_at' => $now];
            $update_format = ['%d', '%s'];

            if ($status !== null) {
                $update_data['status'] = $status;
                $update_format[] = '%d';
            }
            if ($memo !== null) {
                $update_data['memo'] = $memo;
                $update_format[] = '%s';
            }

            $wpdb->update($table, $update_data, ['id' => (int)$existing_id], $update_format, ['%d']);
        } else {
            // INSERT
            $wpdb->insert($table, [
                'user_id'          => $user_id,
                'year_month'       => $month,
                'row_hash'         => $row_hash,
                'event_name'       => $event_name,
                'date_hour_minute' => $date_hour_minute,
                'page_path'        => $page_path,
                'source_medium'    => $source_medium,
                'device_category'  => $device_category,
                'country'          => $country,
                'event_count'      => $event_count,
                'status'           => $status ?? 0,
                'memo'             => $memo ?? '',
                'updated_by'       => $user_id,
                'updated_at'       => $now,
            ], ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%d','%s']);
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * POST /gcrev/v1/cv-review/bulk-update
     * 複数行の一括ステータス更新
     */
    public function rest_bulk_update_cv_review(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $params  = $request->get_json_params();
        $user_id = get_current_user_id();

        $month = sanitize_text_field($params['month'] ?? '');
        $items = $params['items'] ?? [];

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return new WP_REST_Response(['success' => false, 'message' => '月の形式が不正です'], 400);
        }
        if (!is_array($items) || empty($items)) {
            return new WP_REST_Response(['success' => false, 'message' => '更新データがありません'], 400);
        }

        $table = $wpdb->prefix . 'gcrev_cv_review';

        // テーブル存在チェック — 無ければ作成
        $this->ensure_cv_review_table($table);
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'テーブル作成に失敗しました: ' . $wpdb->last_error,
            ], 500);
        }

        $now     = current_time('mysql');
        $updated = 0;
        $errors  = 0;

        foreach ($items as $item) {
            $row_hash = sanitize_text_field($item['row_hash'] ?? '');
            $status   = isset($item['status']) ? (int)$item['status'] : 0;
            $memo     = sanitize_text_field($item['memo'] ?? '');

            if (!preg_match('/^[a-f0-9]{32}$/', $row_hash)) continue;
            if (!in_array($status, [0, 1, 2], true)) continue;

            // 既存行チェック
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d AND year_month = %s AND row_hash = %s",
                $user_id, $month, $row_hash
            ));

            if ($existing_id) {
                $result = $wpdb->update($table,
                    ['status' => $status, 'memo' => $memo, 'updated_by' => $user_id, 'updated_at' => $now],
                    ['id' => (int)$existing_id],
                    ['%d', '%s', '%d', '%s'],
                    ['%d']
                );
                if ($result === false) {
                    error_log("[GCREV] cv-review UPDATE failed: hash={$row_hash}, error=" . $wpdb->last_error);
                    $errors++;
                } else {
                    $updated++;
                }
            } else {
                // ディメンション値を含める
                $event_name      = sanitize_text_field($item['event_name'] ?? '');
                $date_hour_minute = sanitize_text_field($item['date_hour_minute'] ?? '');
                $page_path       = sanitize_text_field($item['page_path'] ?? '');
                $source_medium   = sanitize_text_field($item['source_medium'] ?? '');
                $device_category = sanitize_text_field($item['device_category'] ?? '');
                $country         = sanitize_text_field($item['country'] ?? '');
                $event_count     = isset($item['event_count']) ? (int)$item['event_count'] : 1;

                $result = $wpdb->insert($table, [
                    'user_id'          => $user_id,
                    'year_month'       => $month,
                    'row_hash'         => $row_hash,
                    'event_name'       => $event_name,
                    'date_hour_minute' => $date_hour_minute,
                    'page_path'        => $page_path,
                    'source_medium'    => $source_medium,
                    'device_category'  => $device_category,
                    'country'          => $country,
                    'event_count'      => $event_count,
                    'status'           => $status,
                    'memo'             => $memo,
                    'updated_by'       => $user_id,
                    'updated_at'       => $now,
                ], ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%d','%s']);
                if ($result === false) {
                    error_log("[GCREV] cv-review INSERT failed: hash={$row_hash}, error=" . $wpdb->last_error);
                    $errors++;
                } else {
                    $updated++;
                }
            }
        }

        // 有効CV保存後はダッシュボード系キャッシュも無効化
        if ($updated > 0) {
            delete_transient("gcrev_effcv_{$user_id}_{$month}");
            delete_transient("gcrev_dash_{$user_id}_previousMonth");
            delete_transient("gcrev_dash_{$user_id}_twoMonthsAgo");
        }

        if ($errors > 0 && $updated === 0) {
            return new WP_REST_Response([
                'success' => false,
                'message' => "保存に失敗しました（{$errors}件のエラー）",
            ], 500);
        }

        return new WP_REST_Response(['success' => true, 'updated' => $updated, 'errors' => $errors], 200);
    }

    /**
     * gcrev_cv_review テーブルが存在しなければ直接 CREATE TABLE する
     */
    private function ensure_cv_review_table(string $table = ''): void {
        global $wpdb;
        if (!$table) {
            $table = $wpdb->prefix . 'gcrev_cv_review';
        }
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            return;
        }
        error_log('[GCREV] ensure_cv_review_table: creating ' . $table);
        $charset_collate = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            year_month VARCHAR(7) NOT NULL,
            row_hash VARCHAR(32) NOT NULL,
            event_name VARCHAR(100) NOT NULL DEFAULT '',
            date_hour_minute VARCHAR(12) NOT NULL DEFAULT '',
            page_path VARCHAR(500) NOT NULL DEFAULT '',
            source_medium VARCHAR(200) NOT NULL DEFAULT '',
            device_category VARCHAR(50) NOT NULL DEFAULT '',
            country VARCHAR(100) NOT NULL DEFAULT '',
            event_count INT NOT NULL DEFAULT 1,
            status TINYINT(1) NOT NULL DEFAULT 0,
            memo VARCHAR(500) NULL,
            updated_by BIGINT(20) UNSIGNED NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_month_hash (user_id, year_month, row_hash),
            KEY user_month (user_id, year_month),
            KEY status (status)
        ) {$charset_collate}");
        if ($wpdb->last_error) {
            error_log('[GCREV] ensure_cv_review_table error: ' . $wpdb->last_error);
        }
    }

    /**
     * 同一 dateHourMinute + eventName の行を統合（1CV としてカウント）
     *
     * GA4 は pagePath / sourceMedium 等が異なると別行で返すが、
     * 同じ分に発生した同一イベントは 1回のCV として扱う。
     * event_count は常に 1 にし、page_path は "/" より具体的なパスを優先する。
     * row_hash は md5(eventName + dateHourMinute) で再生成する。
     */
    private function dedup_cv_review_rows(array $rows): array {
        $grouped = [];

        foreach ($rows as $row) {
            $key = $row['event_name'] . '_' . $row['date_hour_minute'];

            if (!isset($grouped[$key])) {
                $row['row_hash']    = md5($row['event_name'] . $row['date_hour_minute']);
                $row['event_count'] = 1; // 常に1CV
                $grouped[$key]      = $row;
            } else {
                // page_path: "/" だけより具体的なパスを優先
                $current_page = $grouped[$key]['page_path'];
                $new_page     = $row['page_path'];
                if ($current_page === '/' && $new_page !== '/') {
                    $grouped[$key]['page_path'] = $new_page;
                } elseif ($current_page !== '/' && $new_page !== '/' && $new_page !== $current_page) {
                    // 両方具体的なら長い方（より詳細なパス）を優先
                    if (strlen($new_page) > strlen($current_page)) {
                        $grouped[$key]['page_path'] = $new_page;
                    }
                }

                // source_medium: 空や (direct) / (none) より具体的なものを優先
                $current_src = $grouped[$key]['source_medium'];
                $new_src     = $row['source_medium'];
                if (strpos($current_src, '(direct)') !== false && strpos($new_src, '(direct)') === false && $new_src !== '') {
                    $grouped[$key]['source_medium'] = $new_src;
                }
            }
        }

        return array_values($grouped);
    }

    /**
     * GA4行データにDB保存済みのstatus/memoをマージ
     */
    private function merge_cv_review_statuses(array $rows, int $user_id, string $month): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_cv_review';

        // テーブル存在チェック
        if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table))) {
            // テーブル未作成: status=0, memo='' で返す
            return array_map(function($row) {
                $row['status'] = 0;
                $row['memo']   = '';
                return $row;
            }, $rows);
        }

        // このユーザー・月のレビュー済みデータを全取得
        $saved = $wpdb->get_results($wpdb->prepare(
            "SELECT row_hash, status, memo FROM {$table} WHERE user_id = %d AND year_month = %s",
            $user_id, $month
        ), ARRAY_A);

        $saved_map = [];
        foreach ($saved as $s) {
            $saved_map[$s['row_hash']] = $s;
        }

        // マージ
        return array_map(function($row) use ($saved_map) {
            $hash = $row['row_hash'];
            if (isset($saved_map[$hash])) {
                $row['status'] = (int)$saved_map[$hash]['status'];
                $row['memo']   = $saved_map[$hash]['memo'] ?? '';
            } else {
                $row['status'] = 0;
                $row['memo']   = '';
            }
            return $row;
        }, $rows);
    }

    } // class Gcrev_Insight_API の閉じ括弧

    new Gcrev_Insight_API();

    // =========================================================
    // CPT登録（classの外）
    // =========================================================
    add_action('init', function() {
        register_post_type('gcrev_report', [
            'labels' => [
                'name'          => '月次レポート',
                'singular_name' => '月次レポート',
            ],
            'public'              => false,
            'show_ui'             => false, // 管理画面に表示しない
            'has_archive'         => false,
            'rewrite'             => false,
            'supports'            => ['title'],
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ]);
    });

