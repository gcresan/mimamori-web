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

    // Step6: DataForSEO Client（順位トラッキング）
    private ?Gcrev_DataForSEO_Client $dataforseo = null;

    private string $service_account_path = '';

    // ===== キャッシュ設定 =====
    private const DASHBOARD_CACHE_TTL  = 86400;   // 24h
    private const PREFETCH_SLEEP_US    = 100000;  // 0.1s
    public  const PREFETCH_CHUNK_LIMIT = 5;
    private const MEO_FETCH_CHUNK_LIMIT = 5;

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

        // Step6: DataForSEO（定数が未設定でもインスタンスは作成、API呼び出し時にチェック）
        if ( class_exists( 'Gcrev_DataForSEO_Client' ) ) {
            $this->dataforseo = new Gcrev_DataForSEO_Client( $this->config );
        }

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
        register_rest_route('gcrev_insights/v1', '/save-client-settings', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'save_client_settings' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev_insights/v1', '/generate-persona', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_persona' ],
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
                ],
                // 任意の日付範囲指定（月次レポートの過去閲覧時に使用）
                'start_date' => [
                    'required'          => false,
                    'validate_callback' => function($param) {
                        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $param);
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'end_date' => [
                    'required'          => false,
                    'validate_callback' => function($param) {
                        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $param);
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ],
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

        // ===== MEO順位データ取得（DataForSEO Maps/Local Finder SERP） =====
        register_rest_route('gcrev/v1', '/meo/rankings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_meo_rankings' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
            'args'                => [
                'keyword_id' => [
                    'required' => false,
                    'default'  => 0,
                    'type'     => 'integer',
                ],
                'device' => [
                    'required' => false,
                    'default'  => 'mobile',
                    'validate_callback' => function( $v ) {
                        return in_array( $v, [ 'mobile', 'desktop' ], true );
                    },
                ],
                'force' => [
                    'required' => false,
                    'default'  => 0,
                    'type'     => 'integer',
                ],
                'radius' => [
                    'required'          => false,
                    'default'           => 0,
                    'type'              => 'integer',
                    'validate_callback' => function( $v ) {
                        $v = (int) $v;
                        return $v === 0 || in_array( $v, [ 500, 1000, 3000, 5000, 10000 ], true );
                    },
                ],
            ],
        ]);

        // MEO 履歴（週次推移）
        register_rest_route('gcrev/v1', '/meo/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_meo_history' ],
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
                ],
                'view' => [
                    'required'          => false,
                    'default'           => 'monthly',
                    'validate_callback' => function($param) {
                        return in_array($param, ['monthly', 'daily']);
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
                        // YYYY-MM（月）または YYYY-MM-DD（日）を受け付ける
                        return preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $param);
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
                    'validate_callback' => function($param) {
                        return in_array($param, ['sessions', 'cv', 'meo']);
                    }
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

        // =========================================================
        // 順位トラッキング（Rank Tracker）
        // =========================================================
        register_rest_route('gcrev/v1', '/rank-tracker/rankings', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_rank_tracker_rankings' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/rank-tracker/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_rank_tracker_history' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/rank-tracker/keywords', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_rank_tracker_keywords' ],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
        register_rest_route('gcrev/v1', '/rank-tracker/keywords', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_rank_tracker_keyword' ],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
        register_rest_route('gcrev/v1', '/rank-tracker/keywords/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'rest_delete_rank_tracker_keyword' ],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
        register_rest_route('gcrev/v1', '/rank-tracker/fetch', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_fetch_rank_tracker' ],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
        // 管理者用：検索ボリューム・SEO難易度の手動取得
        register_rest_route('gcrev/v1', '/rank-tracker/fetch-metrics', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_fetch_rank_tracker_metrics' ],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        // ユーザー向けキーワード管理（自分のキーワードのみ）
        register_rest_route('gcrev/v1', '/rank-tracker/my-keywords', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_my_keywords' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/rank-tracker/my-keywords', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_my_keyword' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/rank-tracker/my-keywords/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'rest_delete_my_keyword' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/rank-tracker/my-keywords/(?P<id>\d+)/fetch', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_fetch_my_keyword_rank' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/rank-tracker/monthly-trend', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_rank_tracker_monthly_trend' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // 全キーワード一括取得（ユーザー向け）
        register_rest_route('gcrev/v1', '/rank-tracker/fetch-all', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_fetch_all_my_keywords' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // SERP 上位サイト取得（ユーザー向け）
        register_rest_route('gcrev/v1', '/rank-tracker/serp-top', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_serp_top' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // キーワード並び替え（ユーザー向け）
        register_rest_route('gcrev/v1', '/rank-tracker/reorder', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_reorder_my_keywords' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // =========================================================
        // AIOスコア（AI検索最適化スコア）
        // =========================================================
        register_rest_route('gcrev/v1', '/aio/summary', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_aio_summary' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/aio/keyword-detail', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_aio_keyword_detail' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/aio/run', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_run_aio_check' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/aio/run-keyword', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_run_aio_keyword' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/aio/my-keywords', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_aio_my_keywords' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/aio/my-keywords', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_aio_keyword' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/aio/my-keywords/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'rest_delete_aio_keyword' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        // AIレポート統合データ
        register_rest_route('gcrev/v1', '/aio/report', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_aio_report' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        // サイト診断
        register_rest_route('gcrev/v1', '/aio/site-diagnosis', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_aio_site_diagnosis' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/aio/site-diagnosis', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_aio_site_diagnosis' ],
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ]);
        // サイト診断実行
        register_rest_route('gcrev/v1', '/aio/run-diagnosis', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_run_aio_site_diagnosis' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        // 管理者用: AIOキーワード管理
        register_rest_route('gcrev/v1', '/aio/keywords', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_aio_keywords_admin' ],
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ]);
        register_rest_route('gcrev/v1', '/aio/keywords', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_save_aio_keyword_admin' ],
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ]);

        // =============================================
        // SEO対策
        // =============================================
        register_rest_route('gcrev/v1', '/seo/report', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_seo_report' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/seo/run-diagnosis', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_run_seo_diagnosis' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // =========================================================
        // 口コミ投稿支援（公開エンドポイント）
        // =========================================================
        register_rest_route('gcrev/v1', '/review/generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_generate_review' ],
            'permission_callback' => [ $this, 'check_review_rate_limit' ],
        ]);

        // =========================================================
        // アンケート管理（ログインユーザー向け）
        // =========================================================
        register_rest_route('gcrev/v1', '/survey/list', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_survey_list' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/detail', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_survey_detail' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/save', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_survey_save' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/delete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_survey_delete' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/question/save', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_survey_question_save' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/question/delete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_survey_question_delete' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/question/reorder', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_survey_question_reorder' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);

        // =========================================================
        // アンケート回答管理・AI生成・集計・分析
        // =========================================================
        register_rest_route('gcrev/v1', '/survey/responses', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_survey_responses' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/response/detail', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_survey_response_detail' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/response/status', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_survey_response_status' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/response/notes', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_survey_response_notes' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/ai-generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_survey_ai_generate' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/ai-generations', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_survey_ai_generations' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/ai-generation/status', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_survey_ai_generation_status' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/analytics', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_survey_analytics' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
        register_rest_route('gcrev/v1', '/survey/analysis', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_survey_analysis' ],
            'permission_callback' => [ $this->config, 'check_permission' ],
        ]);
    }

    // =========================================================
    // キャッシュ
    // =========================================================
    /**
     * 海外アクセス除外設定の判定
     */
    private function is_exclude_foreign( int $user_id ): bool {
        return get_user_meta( $user_id, 'report_exclude_foreign', true ) === '1';
    }

    /**
     * 海外アクセス除外が有効ならGA4国フィルタを設定し、状態を返す。
     * 呼び出し後は必ず restore_country_filter() で元に戻すこと。
     *
     * 再入可能: 外側の呼び出しで既にフィルタが設定済みの場合は false を返し、
     * restore_country_filter(false) で解除されないようにする。
     */
    private function maybe_set_country_filter( int $user_id ): bool {
        if ( $this->ga4->has_country_filter() ) {
            return false; // 外側で既に設定済み — 内側では解除しない
        }
        if ( $this->is_exclude_foreign( $user_id ) ) {
            $this->ga4->set_country_filter( 'Japan' );
            return true;
        }
        return false;
    }

    /**
     * 国フィルタを解除する（maybe_set_country_filter の対）
     */
    private function restore_country_filter( bool $was_set ): void {
        if ( $was_set ) {
            $this->ga4->set_country_filter( null );
        }
    }

    private function cache_key_dashboard(int $user_id, string $range): string {
        $suffix = $this->ga4->has_country_filter() ? '_jp' : '';
        return "gcrev_dash_{$user_id}_{$range}{$suffix}";
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

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

        try {
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
        } finally {
            $this->restore_country_filter( $filter_set );
        }
    }

    // =========================================================
    // REST: /kpi
    // =========================================================
    public function get_kpi_data(WP_REST_Request $request): WP_REST_Response {

        $user_id = get_current_user_id();

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

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
        } finally {
            $this->restore_country_filter( $filter_set );
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

        // 月次戦略情報のみ保存（site_url / target はクライアント設定へ移管済み）
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

        // 海外アクセス除外設定の保存
        $exclude_foreign = ($params['exclude_foreign'] ?? '') === '1' ? '1' : '';
        update_user_meta($user_id, 'report_exclude_foreign', $exclude_foreign);

        error_log("[GCREV] Client info saved for user_id={$user_id}, output_mode={$output_mode}, exclude_foreign={$exclude_foreign}");

        return new WP_REST_Response([
            'success' => true,
            'message' => '月次レポート設定を保存しました',
        ], 200);
    }

    /**
     * クライアント固定設定を保存
     */
    public function save_client_settings(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();
        $params  = $request->get_json_params();

        // URL バリデーション
        $site_url = esc_url_raw($params['site_url'] ?? '');
        if (empty($site_url)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => '対象サイトURLは必須です',
            ], 400);
        }

        // 商圏タイプ バリデーション
        $valid_area_types = ['nationwide', 'prefecture', 'city', 'custom', ''];
        $area_type = sanitize_text_field($params['area_type'] ?? '');
        if (!in_array($area_type, $valid_area_types, true)) {
            $area_type = '';
        }

        update_user_meta($user_id, 'gcrev_client_site_url',      $site_url);
        update_user_meta($user_id, 'gcrev_client_area_type',      $area_type);
        update_user_meta($user_id, 'gcrev_client_area_pref',      sanitize_text_field($params['area_pref'] ?? ''));
        update_user_meta($user_id, 'gcrev_client_area_city',      sanitize_text_field($params['area_city'] ?? ''));
        update_user_meta($user_id, 'gcrev_client_area_custom',    sanitize_text_field($params['area_custom'] ?? ''));

        // 商圏（市区町村）変更時：手動MEO座標が未設定ならMEOキャッシュを無効化
        // （自動検出の市区町村中心部が変わるため）
        $manual_lat = get_user_meta( $user_id, '_gcrev_meo_lat', true );
        if ( empty( $manual_lat ) ) {
            global $wpdb;
            $like = $wpdb->esc_like( '_transient_gcrev_meo_rank_' . $user_id ) . '%';
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            ) );
            $like_timeout = $wpdb->esc_like( '_transient_timeout_gcrev_meo_rank_' . $user_id ) . '%';
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like_timeout
            ) );
        }

        // 業種（大分類）— 64文字以内
        $category = sanitize_text_field($params['industry_category'] ?? '');
        if (mb_strlen($category) > 64) { $category = mb_substr($category, 0, 64); }
        update_user_meta($user_id, 'gcrev_client_industry_category', $category);

        // 業態（小分類）— 配列、各 value 64文字以内
        $subcategory_raw = $params['industry_subcategory'] ?? [];
        $subcategory = [];
        if (is_array($subcategory_raw)) {
            foreach ($subcategory_raw as $v) {
                $v = sanitize_text_field($v);
                if ($v !== '' && mb_strlen($v) <= 64) {
                    $subcategory[] = $v;
                }
            }
        }
        update_user_meta($user_id, 'gcrev_client_industry_subcategory', $subcategory);

        // 詳細 — 160文字以内
        $detail = sanitize_text_field($params['industry_detail'] ?? '');
        if (mb_strlen($detail) > 160) { $detail = mb_substr($detail, 0, 160); }
        update_user_meta($user_id, 'gcrev_client_industry_detail', $detail);

        update_user_meta($user_id, 'gcrev_client_business_type',  sanitize_text_field($params['business_type'] ?? ''));

        // --- 成長ステージ（200文字以内） ---
        $stage = sanitize_text_field( $params['stage'] ?? '' );
        if ( mb_strlen( $stage ) > 200 ) { $stage = mb_substr( $stage, 0, 200 ); }
        update_user_meta( $user_id, 'gcrev_client_stage', $stage );

        // --- ゴール種別（300文字以内） ---
        $main_conversions = sanitize_text_field( $params['main_conversions'] ?? '' );
        if ( mb_strlen( $main_conversions ) > 300 ) { $main_conversions = mb_substr( $main_conversions, 0, 300 ); }
        update_user_meta( $user_id, 'gcrev_client_main_conversions', $main_conversions );

        // --- ペルソナ: 配列フィールド（年齢層 / 性別 / 属性 / 意思決定） ---
        $persona_array_fields = [
            'persona_age_ranges'       => 'gcrev_client_persona_age_ranges',
            'persona_genders'          => 'gcrev_client_persona_genders',
            'persona_attributes'       => 'gcrev_client_persona_attributes',
            'persona_decision_factors' => 'gcrev_client_persona_decision_factors',
        ];
        foreach ($persona_array_fields as $param_key => $meta_key) {
            $raw = $params[$param_key] ?? [];
            $clean = [];
            if (is_array($raw)) {
                foreach ($raw as $v) {
                    $v = sanitize_text_field($v);
                    if ($v !== '' && mb_strlen($v) <= 64) {
                        $clean[] = $v;
                    }
                }
            }
            update_user_meta($user_id, $meta_key, $clean);
        }

        // ペルソナ: ひとこと（200文字）
        $one_liner = sanitize_text_field($params['persona_one_liner'] ?? '');
        if (mb_strlen($one_liner) > 200) { $one_liner = mb_substr($one_liner, 0, 200); }
        update_user_meta($user_id, 'gcrev_client_persona_one_liner', $one_liner);

        // ペルソナ: 詳細テキスト（4000文字、改行保持）
        $detail_text = sanitize_textarea_field($params['persona_detail_text'] ?? '');
        if (mb_strlen($detail_text) > 4000) { $detail_text = mb_substr($detail_text, 0, 4000); }
        update_user_meta($user_id, 'gcrev_client_persona_detail_text', $detail_text);

        // ペルソナ: 参考URL（最大5件）
        $ref_urls_raw = $params['persona_reference_urls'] ?? [];
        $ref_urls = [];
        if (is_array($ref_urls_raw)) {
            $count = 0;
            foreach ($ref_urls_raw as $item) {
                if ($count >= 5) break;
                if (!is_array($item)) continue;
                $url  = esc_url_raw($item['url'] ?? '');
                $note = sanitize_text_field($item['note'] ?? '');
                if (empty($url)) continue;
                if (mb_strlen($url) > 2048)  { $url  = mb_substr($url, 0, 2048); }
                if (mb_strlen($note) > 120)  { $note = mb_substr($note, 0, 120); }
                $ref_urls[] = ['url' => $url, 'note' => $note];
                $count++;
            }
        }
        update_user_meta($user_id, 'gcrev_client_persona_reference_urls', $ref_urls);

        error_log("[GCREV] Client settings saved for user_id={$user_id}, site_url={$site_url}");

        return new WP_REST_Response([
            'success' => true,
            'message' => 'クライアント設定を保存しました',
        ], 200);
    }

    /**
     * AI で詳細ペルソナを生成
     */
    public function generate_persona(WP_REST_Request $request): WP_REST_Response {
        $user_id = get_current_user_id();

        // 連打防止（30秒）
        $throttle_key = 'gcrev_persona_gen_' . $user_id;
        if (get_transient($throttle_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => '連続生成はできません。30秒後に再度お試しください。',
            ], 429);
        }
        set_transient($throttle_key, 1, 30);

        $params  = $request->get_json_params();
        $context = $params['context'] ?? [];

        // コンテキスト情報の組み立て
        $industry_category    = sanitize_text_field($context['industry_category'] ?? '');
        $industry_subcategory = $context['industry_subcategory'] ?? [];
        $industry_label       = sanitize_text_field($context['industry_label'] ?? '');
        $age_ranges           = $context['persona_age_ranges'] ?? [];
        $genders              = $context['persona_genders'] ?? [];
        $attributes           = $context['persona_attributes'] ?? [];
        $decision_factors     = $context['persona_decision_factors'] ?? [];
        $one_liner            = sanitize_text_field($context['persona_one_liner'] ?? '');
        $ref_urls             = $context['reference_urls'] ?? [];
        $extra                = $context['extra'] ?? [];

        // プロンプト構築
        $prompt_parts = [];
        $prompt_parts[] = "あなたはWeb集客・マーケティングのペルソナ設計の専門家です。";
        $prompt_parts[] = "以下の情報をもとに、この事業者が想定すべき「典型的な見込み客ペルソナ」を作成してください。";
        $prompt_parts[] = "";

        // 業種・業態
        if ($industry_label) {
            $prompt_parts[] = "【業種・業態】" . $industry_label;
        }

        // 簡易ペルソナ情報
        if (!empty($age_ranges)) {
            $prompt_parts[] = "【想定年齢層】" . implode(', ', array_map('sanitize_text_field', $age_ranges));
        }
        if (!empty($genders)) {
            $prompt_parts[] = "【想定性別】" . implode(', ', array_map('sanitize_text_field', $genders));
        }
        if (!empty($attributes)) {
            $prompt_parts[] = "【ターゲット属性】" . implode(', ', array_map('sanitize_text_field', $attributes));
        }
        if (!empty($decision_factors)) {
            $prompt_parts[] = "【検討・意思決定の特徴】" . implode(', ', array_map('sanitize_text_field', $decision_factors));
        }
        if ($one_liner) {
            $prompt_parts[] = "【ひとこと補足】" . $one_liner;
        }

        // 参考URL
        if (!empty($ref_urls) && is_array($ref_urls)) {
            $url_lines = [];
            foreach ($ref_urls as $idx => $ru) {
                if (!is_array($ru)) continue;
                $u = sanitize_text_field($ru['url'] ?? '');
                $n = sanitize_text_field($ru['note'] ?? '');
                if ($u) {
                    $url_lines[] = ($idx + 1) . ". " . $u . ($n ? " （意図: {$n}）" : '');
                }
            }
            if (!empty($url_lines)) {
                $prompt_parts[] = "【参考URL】";
                $prompt_parts[] = implode("\n", $url_lines);
            }
        }

        // 追加情報
        if (!empty($extra) && is_array($extra)) {
            $extra_lines = [];
            $extra_labels = [
                'service'             => '主なサービス・商品',
                'price_range'         => '価格帯',
                'area'                => '対応エリア',
                'competitor_features' => '競合との違い・強み',
                'avoid_notes'         => '避けたい表現・方針',
            ];
            foreach ($extra_labels as $key => $label) {
                $val = sanitize_text_field($extra[$key] ?? '');
                if ($val) {
                    $extra_lines[] = "・{$label}: {$val}";
                }
            }
            if (!empty($extra_lines)) {
                $prompt_parts[] = "";
                $prompt_parts[] = "【追加情報】";
                $prompt_parts[] = implode("\n", $extra_lines);
            }
        }

        $prompt_parts[] = "";
        $prompt_parts[] = "---";
        $prompt_parts[] = "以下のフォーマットで、800〜1600字程度の詳細ペルソナを出力してください。";
        $prompt_parts[] = "テンプレート的な表現は避け、この業種・条件に固有の具体的な人物像を描写してください。";
        $prompt_parts[] = "";
        $prompt_parts[] = "■ 基本プロフィール";
        $prompt_parts[] = "年齢・性別・職業・家族構成・居住エリアなど";
        $prompt_parts[] = "";
        $prompt_parts[] = "■ 日常と課題";
        $prompt_parts[] = "普段の生活・仕事の中で感じている不満や課題";
        $prompt_parts[] = "";
        $prompt_parts[] = "■ 情報収集の行動パターン";
        $prompt_parts[] = "どのように検索し、比較し、意思決定するか";
        $prompt_parts[] = "";
        $prompt_parts[] = "■ このサービスに求めること";
        $prompt_parts[] = "期待する価値、不安に思うこと、決め手になる要素";
        $prompt_parts[] = "";
        $prompt_parts[] = "■ 響くメッセージ・表現";
        $prompt_parts[] = "この人に刺さるキーワードや訴求ポイント";

        $prompt = implode("\n", $prompt_parts);

        try {
            $result = $this->ai->call_gemini_api($prompt);

            if (empty($result)) {
                delete_transient($throttle_key);
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'AIからの応答が空でした。時間をおいて再度お試しください。',
                ], 500);
            }

            return new WP_REST_Response([
                'success'        => true,
                'generated_text' => $result,
                'generated_at'   => current_time('Y-m-d H:i:s'),
            ], 200);

        } catch (\Exception $e) {
            delete_transient($throttle_key);
            error_log("[GCREV] Persona generation failed for user_id={$user_id}: " . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'ペルソナ生成中にエラーが発生しました: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * AIレポート生成用のクライアント情報を構築（クライアント設定 + 月次設定を統合）
     *
     * @param int $user_id ユーザーID
     * @return array client_info 配列
     */
    private function build_client_info_for_report(int $user_id): array {
        $client = gcrev_get_client_settings($user_id);

        return [
            'site_url'         => $client['site_url'],
            'area_label'       => gcrev_get_client_area_label($client),
            'industry'         => $client['industry'],
            'business_type'    => $client['business_type'],
            'issue'            => get_user_meta($user_id, 'report_issue', true),
            'goal_monthly'     => get_user_meta($user_id, 'report_goal_monthly', true),
            'focus_numbers'    => get_user_meta($user_id, 'report_focus_numbers', true),
            'current_state'    => get_user_meta($user_id, 'report_current_state', true),
            'goal_main'        => get_user_meta($user_id, 'report_goal_main', true),
            'additional_notes'  => get_user_meta($user_id, 'report_additional_notes', true),
            'output_mode'       => get_user_meta($user_id, 'report_output_mode', true) ?: 'normal',
            'exclude_foreign'   => get_user_meta($user_id, 'report_exclude_foreign', true) === '1',
        ];
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
        $year_month  = $params['year_month']     ?? null;

        if (!is_array($prev_data) || !is_array($two_data)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'リクエストデータが不正です',
            ], 400);
        }

        // クライアント設定 + 月次設定をサーバー側で統合構築
        $client_info = $this->build_client_info_for_report($user_id);

        // 海外アクセス除外：KPI・CV双方にフィルタ適用（finally で解除）
        $report_filter_set = ! empty( $client_info['exclude_foreign'] );
        if ( $report_filter_set ) {
            try {
                $config = $this->config->get_user_config( $user_id );
                $this->ga4->set_country_filter( 'Japan' );
                $prev_data = $this->fetch_dashboard_data_internal( $config, 'previousMonth' );
                $two_data  = $this->fetch_dashboard_data_internal( $config, 'twoMonthsAgo' );
            } catch ( \Throwable $e ) {
                $this->ga4->set_country_filter( null );
                error_log( '[GCREV] generate_report: country filter refetch failed: ' . $e->getMessage() );
                $report_filter_set = false;
            }
        }

        try {
            error_log("[GCREV] Generating new report for user_id={$user_id}");

            // ターゲットエリア（都道府県）の判定 — クライアント設定から取得
            $client_settings = gcrev_get_client_settings($user_id);
            $target_area = gcrev_detect_area_from_client_settings($client_settings);

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
            $saved_post_id = $this->repo->save_report_to_history($user_id, $report_html, $client_info, 'manual', $year_month, $highlights, $beginner_markdown);

            // === KPIスナップショット保存 ===
            if ( $saved_post_id > 0 ) {
                $this->save_kpi_snapshot_for_report( $saved_post_id, $prev_data, $user_id, $year_month );
            }

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
        } finally {
            $this->restore_country_filter( $report_filter_set );
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

            // クライアント情報取得（クライアント設定 + 月次設定を統合）
            $client_info = $this->build_client_info_for_report( $user_id );

            // 必須項目チェック（クライアント設定のサイトURL）
            if ( empty( $client_info['site_url'] ) ) {
                error_log( "[GCREV] auto_generate: user_id={$user_id} missing client site_url, skipping." );
                if ( $log_id > 0 ) {
                    Gcrev_Cron_Logger::log_user( $log_id, $user_id, 'skip', 'Missing client site_url' );
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

                // 海外アクセス除外フィルタ（CV取得まで維持、finally で解除）
                $exclude_foreign_auto = get_user_meta( $user_id, 'report_exclude_foreign', true ) === '1';
                if ( $exclude_foreign_auto ) {
                    $this->ga4->set_country_filter( 'Japan' );
                }

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

                // ターゲットエリア判定（クライアント設定から）
                $auto_client_settings = gcrev_get_client_settings( $user_id );
                $auto_target_area = gcrev_detect_area_from_client_settings( $auto_client_settings );

                $report_html = $this->generator->generate_report_multi_pass( $prev_data, $two_data, $client_info, $auto_target_area );

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
                $saved_post_id = $this->repo->save_report_to_history( $user_id, $report_html, $client_info, 'auto', null, $highlights, $auto_beginner_md );

                // === KPIスナップショット保存 ===
                if ( $saved_post_id > 0 ) {
                    $this->save_kpi_snapshot_for_report( $saved_post_id, $prev_data, $user_id, $auto_ym );
                }

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
            } finally {
                // 海外アクセス除外フィルタ解除（次ユーザーへの影響を防止）
                if ( $exclude_foreign_auto ) {
                    $this->ga4->set_country_filter( null );
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

        // 海外アクセス除外フィルタ（inject_effective_cv_into_kpi まで維持）
        $filter_set = $this->maybe_set_country_filter( $user_id );

        try {

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

        } finally {
            $this->restore_country_filter( $filter_set );
        }
    }

    /**
     * 任意の日付範囲でKPIデータを取得する（月次レポートの過去閲覧時に使用）
     *
     * @param string $start_date 開始日 (Y-m-d)
     * @param string $end_date   終了日 (Y-m-d)
     * @param int    $user_id    ユーザーID
     * @return array KPIデータ
     */
    public function get_dashboard_kpi_by_dates( string $start_date, string $end_date, int $user_id ): array {
        // --- Transient キャッシュ（12+ API呼び出しを回避）---
        $exclude_foreign = get_user_meta( $user_id, 'report_exclude_foreign', true );
        $filter_suffix   = $exclude_foreign ? '_filtered' : '';
        $cache_key       = "gcrev_dash_bydate_{$user_id}_{$start_date}_{$end_date}{$filter_suffix}";
        $cached          = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            return $cached;
        }

        $config = $this->config->get_user_config( $user_id );

        $ga4_id  = $config['ga4_id'];
        $gsc_url = $config['gsc_url'];
        $site_url = $config['site_url'] ?? '';

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

        try {

        // 比較期間（同日数の直前期間）
        $comparison = $this->dates->get_comparison_range( $start_date, $end_date );

        // GA4 データ取得
        $ga4_pages   = $this->ga4->fetch_ga4_data( $ga4_id, $start_date, $end_date, $site_url );
        $ga4_summary = $this->ga4->fetch_ga4_summary( $ga4_id, $start_date, $end_date );
        $ga4_prev    = $this->ga4->fetch_ga4_summary( $ga4_id, $comparison['start'], $comparison['end'] );
        $daily       = $this->ga4->fetch_ga4_daily_series( $ga4_id, $start_date, $end_date );

        $devices    = $this->ga4->fetch_ga4_breakdown( $ga4_id, $start_date, $end_date, 'deviceCategory', 'sessions' );
        $medium     = $this->ga4->fetch_ga4_breakdown( $ga4_id, $start_date, $end_date, 'sessionMedium', 'sessions' );
        $geo_region = $this->ga4->fetch_ga4_breakdown( $ga4_id, $start_date, $end_date, 'region', 'sessions' );
        $age        = $this->ga4->fetch_ga4_age_breakdown( $ga4_id, $start_date, $end_date );

        $gsc_data = $this->gsc->fetch_gsc_data( $gsc_url, $start_date, $end_date );
        $trends   = $this->build_trends( $ga4_summary, $ga4_prev );

        // 流入元分析データ
        $source_analysis = [ 'channels_summary' => [], 'sources_detail' => [], 'channels_daily_series' => [] ];
        try {
            $source_current  = $this->ga4->fetch_source_data_from_ga4( $ga4_id, $start_date, $end_date );
            $source_previous = $this->ga4->fetch_source_data_from_ga4( $ga4_id, $comparison['start'], $comparison['end'] );
            $source_analysis = $this->format_source_analysis_data( $source_current, $source_previous );
        } catch ( \Exception $e ) {
            error_log( '[GCREV] get_dashboard_kpi_by_dates source error: ' . $e->getMessage() );
        }

        $result = [
            'current_period' => [
                'start' => $start_date,
                'end'   => $end_date,
            ],
            'comparison_period' => [
                'start' => $comparison['start'],
                'end'   => $comparison['end'],
            ],

            'pageViews'      => $ga4_summary['pageViews'],
            'sessions'       => $ga4_summary['sessions'],
            'users'          => $ga4_summary['users'],
            'newUsers'       => $ga4_summary['newUsers'],
            'returningUsers' => $ga4_summary['returningUsers'],
            'avgDuration'    => $ga4_summary['avgDuration'],
            'conversions'    => $ga4_summary['conversions'] ?? '0',

            'trends'  => $trends,
            'daily'   => $daily,
            'devices' => $devices,
            'medium'  => $medium,
            'geo_region' => $geo_region,
            'age'     => $age,
            'pages'   => $ga4_pages['pages'] ?? [],
            'keywords' => $gsc_data['keywords'] ?? [],

            'channels_summary'      => $source_analysis['channels_summary'] ?? [],
            'sources_detail'        => $source_analysis['sources_detail'] ?? [],
            'channels_daily_series' => $source_analysis['channels_daily_series'] ?? [],
        ];

        // 実質CVの注入（月単位判定: 月初～月末の範囲であれば適用）
        $tz = wp_timezone();
        $start_dt = new \DateTimeImmutable( $start_date, $tz );
        $end_dt   = new \DateTimeImmutable( $end_date, $tz );
        if ( $start_dt->format('d') === '01' && $end_dt->format('Y-m-d') === $end_dt->format('Y-m-t') ) {
            $year_month = $start_dt->format('Y-m');
            $effective  = $this->get_effective_cv_monthly( $year_month, $user_id );

            $result['conversions'] = (string) $effective['total'];
            $result['cv_source']   = $effective['source'];
            $result['cv_detail']   = $effective['breakdown_manual'] ?? [];
            $result['ga4_cv']      = $effective['components']['ga4_total'];
            $result['effective_cv'] = $effective;

            // CV 増減（前月比）
            $comp_ym = $start_dt->modify( 'first day of last month' )->format('Y-m');
            $comp_effective = $this->get_effective_cv_monthly( $comp_ym, $user_id );
            $cur = (float) $effective['total'];
            $prv = (float) $comp_effective['total'];

            if ( abs( $prv ) < 0.0001 ) {
                $cv_trend = ( abs( $cur ) < 0.0001 )
                    ? [ 'value' => 0, 'text' => '±0.0%', 'color' => '#666' ]
                    : [ 'value' => 100, 'text' => '+∞', 'color' => '#3b82f6' ];
            } else {
                $val  = ( ( $cur - $prv ) / $prv ) * 100.0;
                $text = ( $val > 0 ? '+' : '' ) . number_format( $val, 1 ) . '%';
                $color = $val > 0 ? '#3b82f6' : ( $val < 0 ? '#ef4444' : '#666' );
                $cv_trend = [ 'value' => $val, 'text' => $text, 'color' => $color ];
            }
            if ( ! isset( $result['trends'] ) ) {
                $result['trends'] = [];
            }
            $result['trends']['conversions'] = $cv_trend;
        }

        // キャッシュ保存（24時間）
        set_transient( $cache_key, $result, 24 * HOUR_IN_SECONDS );

        return $result;

        } finally {
            $this->restore_country_filter( $filter_set );
        }
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

        // クライアント情報取得（クライアント設定 + 月次設定を統合）
        $client_info = $this->build_client_info_for_report($user_id);

        if (empty($client_info['site_url'])) {
            return [
                'success' => false,
                'message' => 'クライアント設定のサイトURLが未設定です'
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

            // 海外アクセス除外フィルタ（CV取得まで維持、finally で解除）
            $exclude_foreign_manual = !empty($client_info['exclude_foreign']);
            if ($exclude_foreign_manual) {
                $this->ga4->set_country_filter('Japan');
            }

            // 前月・前々月データ取得
            $prev_data = $this->fetch_dashboard_data_internal($config, 'previousMonth');
            $two_data = $this->fetch_dashboard_data_internal($config, 'twoMonthsAgo');

            // ターゲットエリア判定（クライアント設定から）
            $manual_client_settings = gcrev_get_client_settings($user_id);
            $target_area = gcrev_detect_area_from_client_settings($manual_client_settings);

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
            $saved_post_id = $this->repo->save_report_to_history($user_id, $report_html, $client_info, 'manual', $year_month, $highlights, $beginner_markdown2);

            // === KPIスナップショット保存 ===
            if ( $saved_post_id > 0 ) {
                $this->save_kpi_snapshot_for_report( $saved_post_id, $prev_data, $user_id, $year_month );
            }

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
        } finally {
            // 海外アクセス除外フィルタ解除
            if ( $exclude_foreign_manual ) {
                $this->ga4->set_country_filter( null );
            }
        }
    }

    /**
     * REST: v2ダッシュボード用KPI取得
     */
    public function rest_get_dashboard_kpi(WP_REST_Request $request): WP_REST_Response {
        $period      = $request->get_param('period') ?? 'prev-month';
        $cache_first = $request->get_param('cache_first') ?? '0';
        $start_date  = $request->get_param('start_date');
        $end_date    = $request->get_param('end_date');
        $user_id     = get_current_user_id();

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

        try {
            // start_date / end_date が指定されている場合は日付範囲で取得
            if ( $start_date && $end_date ) {
                $kpi_data = $this->get_dashboard_kpi_by_dates( $start_date, $end_date, $user_id );
            } else {
                $kpi_data = $this->get_dashboard_kpi($period, $user_id, (int)$cache_first);
            }

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
        } finally {
            $this->restore_country_filter( $filter_set );
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

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

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
        } finally {
            $this->restore_country_filter( $filter_set );
        }
    }
    /**
     * REST API: 地域別アクセス分析データ取得
     */
    public function rest_get_region_analysis(\WP_REST_Request $request): \WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_REST_Response(['error' => 'ログインが必要です'], 401);
        }

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

        try {
            $period = $request->get_param('period') ?? 'prev-month';

            // ユーザー設定取得
            $config = $this->config->get_user_config($user_id);
            $ga4_id = $config['ga4_id'];

            // 期間計算
            $dates = $this->dates->calculate_period_dates($period);
            $comparison = $this->dates->calculate_comparison_dates($period);

            // キャッシュ
            $filter_sfx = $this->ga4->has_country_filter() ? '_jp' : '';
            $cache_key = "gcrev_region_{$user_id}_{$period}_" . md5("{$dates['start']}_{$dates['end']}") . $filter_sfx;
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
        } finally {
            $this->restore_country_filter( $filter_set );
        }
    }

    /**
     * REST API: 地域別 月別推移データ取得（直近12ヶ月）
     */
    public function rest_get_region_trend(\WP_REST_Request $request): \WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_REST_Response(['error' => 'ログインが必要です'], 401);
        }

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

        try {

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
        } finally {
            $this->restore_country_filter( $filter_set );
        }
    }

    /**
     * REST API: ページ分析データ取得
     * パターン: rest_get_region_analysis と同一
     */
    public function rest_get_page_analysis(\WP_REST_Request $request): \WP_REST_Response {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_REST_Response(['error' => 'ログインが必要です'], 401);
        }

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

        try {
            $period = $request->get_param('period') ?? 'prev-month';

            // ユーザー設定取得
            $config = $this->config->get_user_config($user_id);
            $ga4_id = $config['ga4_id'];
            $site_url = $config['gsc_url'] ?? '';

            // 期間計算（region と同じヘルパー利用）
            $dates = $this->dates->calculate_period_dates($period);
            $comparison = $this->dates->calculate_comparison_dates($period);

            // キャッシュ
            $filter_sfx = $this->ga4->has_country_filter() ? '_jp' : '';
            $cache_key = "gcrev_page_{$user_id}_{$period}_" . md5("{$dates['start']}_{$dates['end']}") . $filter_sfx;
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
        } finally {
            $this->restore_country_filter( $filter_set );
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

            // ページネーション対応でロケーション全件取得
            $next_page_token = null;
            do {
                $query_params = ['readMask' => 'name,title,storefrontAddress', 'pageSize' => 100];
                if ($next_page_token !== null) {
                    $query_params['pageToken'] = $next_page_token;
                }

                $locations_url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$account_name}/locations"
                               . '?' . http_build_query($query_params, '', '&');

                $locations_resp = wp_remote_get($locations_url, [
                    'headers' => ['Authorization' => 'Bearer ' . $access_token],
                    'timeout' => 30,
                ]);

                if (is_wp_error($locations_resp)) {
                    error_log("[GCREV][GBP] Locations API error for {$account_name}: " . $locations_resp->get_error_message());
                    break;
                }

                $loc_status = wp_remote_retrieve_response_code($locations_resp);
                if ($loc_status !== 200) {
                    $loc_body = wp_remote_retrieve_body($locations_resp);
                    error_log("[GCREV][GBP] Locations API HTTP {$loc_status} for {$account_name}: {$loc_body}");
                    break;
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

                $next_page_token = $loc_data['nextPageToken'] ?? null;
            } while ($next_page_token !== null);
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
            $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
            $start = $today->modify('-7 days');
            $test_params = [
                'dailyMetric'                  => 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
                'dailyRange.startDate.year'    => (int) $start->format('Y'),
                'dailyRange.startDate.month'   => (int) $start->format('n'),
                'dailyRange.startDate.day'     => (int) $start->format('j'),
                'dailyRange.endDate.year'      => (int) $today->format('Y'),
                'dailyRange.endDate.month'     => (int) $today->format('n'),
                'dailyRange.endDate.day'       => (int) $today->format('j'),
            ];
            $test_url = "https://businessprofileperformance.googleapis.com/v1/{$location_id}:getDailyMetricsTimeSeries?"
                      . http_build_query($test_params, '', '&');
            $test_response = wp_remote_get($test_url, [
                'headers' => ['Authorization' => 'Bearer ' . $access_token],
                'timeout' => 15,
            ]);
            $test_status = wp_remote_retrieve_response_code($test_response);
            $test_ok     = ($test_status === 200);
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

                // 検索キーワード（直近6ヶ月の月別時系列）
                $keywords = $this->gbp_fetch_keywords_monthly_series($access_token, $location_id, 6);
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
     * GET /meo/rankings — マップ順位ページ用（DataForSEO Maps/Local Finder SERP）
     *
     * ユーザーのキーワードでリアルタイムにMaps SERP / Local Finder SERPを取得し、
     * 自社店舗の順位・店舗情報・競合比較を返す。
     */
    public function rest_get_meo_rankings( \WP_REST_Request $request ): \WP_REST_Response {
        try {
            $user_id = get_current_user_id();
            if ( ! $user_id ) {
                return new \WP_REST_Response( [ 'success' => false, 'message' => 'ログインが必要です' ], 401 );
            }

            if ( ! Gcrev_DataForSEO_Client::is_configured() ) {
                return new \WP_REST_Response( [ 'success' => false, 'message' => 'DataForSEO API が未設定です' ], 400 );
            }

            $device       = sanitize_text_field( $request->get_param( 'device' ) ?: 'mobile' );
            $keyword_id   = absint( $request->get_param( 'keyword_id' ) );
            $force        = absint( $request->get_param( 'force' ) );
            $radius_param = absint( $request->get_param( 'radius' ) );

            global $wpdb;
            $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';

            $all_keywords = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, keyword, target_domain, location_code, language_code
                 FROM {$kw_table}
                 WHERE user_id = %d AND enabled = 1
                 ORDER BY sort_order ASC, id ASC",
                $user_id
            ), ARRAY_A );

            if ( empty( $all_keywords ) ) {
                return new \WP_REST_Response( [
                    'success'  => false,
                    'message'  => 'キーワードが登録されていません',
                    'keywords' => [],
                ], 200 );
            }

            $selected_kw = null;
            if ( $keyword_id > 0 ) {
                foreach ( $all_keywords as $kw ) {
                    if ( (int) $kw['id'] === $keyword_id ) {
                        $selected_kw = $kw;
                        break;
                    }
                }
            }
            if ( ! $selected_kw ) {
                $selected_kw = $all_keywords[0];
            }

            $kw_id         = (int) $selected_kw['id'];
            $keyword_text  = $selected_kw['keyword'];
            $target_domain = $selected_kw['target_domain'];
            $location_code = (int) ( $selected_kw['location_code'] ?: 2392 );
            $language_code = $selected_kw['language_code'] ?: 'ja';

            // 座標モード判定
            $meo_lat     = (string) get_user_meta( $user_id, '_gcrev_meo_lat', true );
            $meo_lng     = (string) get_user_meta( $user_id, '_gcrev_meo_lng', true );
            $meo_address = (string) get_user_meta( $user_id, '_gcrev_meo_address', true );
            $meo_radius  = (int) get_user_meta( $user_id, '_gcrev_meo_radius', true ) ?: 1000;

            // 市区町村中心部の自動検出（手動座標が未設定時）
            $location_source = 'manual';
            if ( $meo_lat === '' || $meo_lng === '' ) {
                if ( class_exists( 'Gcrev_City_Coordinates' ) ) {
                    $city_coords = Gcrev_City_Coordinates::get_for_user( $user_id );
                    if ( $city_coords ) {
                        $meo_lat         = (string) $city_coords['lat'];
                        $meo_lng         = (string) $city_coords['lng'];
                        $meo_address     = $city_coords['label'];
                        $meo_radius      = Gcrev_City_Coordinates::DEFAULT_RADIUS;
                        $location_source = 'city_center';
                    }
                }
            }

            $effective_radius = ( $radius_param > 0 ) ? $radius_param : $meo_radius;

            $use_coordinate      = ( $meo_lat !== '' && $meo_lng !== '' );
            $location_coordinate = '';
            $zoom                = Gcrev_DataForSEO_Client::radius_to_zoom( $effective_radius );

            if ( $use_coordinate ) {
                $location_coordinate = Gcrev_DataForSEO_Client::build_coordinate_string(
                    (float) $meo_lat,
                    (float) $meo_lng,
                    $zoom
                );

                if ( $location_source === 'manual' && $radius_param > 0 && $radius_param !== $meo_radius ) {
                    update_user_meta( $user_id, '_gcrev_meo_radius', $radius_param );
                }
            }

            if ( ! $use_coordinate ) {
                $location_source = 'location_code';
            }

            // Transient キャッシュ
            $cache_suffix = $use_coordinate ? "_{$zoom}" : '';
            $cache_key    = "gcrev_meo_rank_{$user_id}_{$kw_id}_{$device}{$cache_suffix}";
            if ( ! $force ) {
                $cached = get_transient( $cache_key );
                if ( $cached !== false && is_array( $cached ) ) {
                    $cached['keywords'] = $this->meo_build_keyword_list( $all_keywords, $kw_id );

                    // キャッシュヒットでも、今週の履歴が未保存なら保存
                    $this->meo_save_to_history(
                        $user_id, $kw_id, $device,
                        $cached['maps']['rank'] ?? null,
                        $cached['local_finder']['rank'] ?? null,
                        $cached['maps']['store'] ?? null,
                        $cached['maps']['competitors'] ?? []
                    );

                    return new \WP_REST_Response( $cached, 200 );
                }
            }

            // DataForSEO API 呼び出し
            $maps_items   = $this->dataforseo->fetch_maps_serp( $keyword_text, $device, $location_code, $language_code, $location_coordinate );
            $finder_items = $this->dataforseo->fetch_local_finder_serp( $keyword_text, $device, $location_code, $language_code, $location_coordinate );

            // Maps データ処理
            $maps_rank  = null;
            $store_data = null;
            $competitors = [];

            if ( ! is_wp_error( $maps_items ) && is_array( $maps_items ) ) {
                $my_biz = $this->dataforseo->find_business_in_maps_results( $maps_items, $target_domain );

                if ( $my_biz ) {
                    $maps_rank  = (int) ( $my_biz['rank_group'] ?? 0 ) ?: null;
                    $store_data = $this->meo_extract_store_info( $my_biz );
                }

                $comp_count = 0;
                foreach ( $maps_items as $item ) {
                    if ( $comp_count >= 10 ) break;
                    $item_type = $item['type'] ?? '';
                    if ( $item_type !== 'maps_search' && $item_type !== 'maps_paid' ) {
                        if ( empty( $item['title'] ) ) continue;
                    }

                    $item_domain = $item['domain'] ?? '';
                    $normalized_item = preg_replace( '/^www\./i', '', strtolower( $item_domain ) );
                    $normalized_target = preg_replace( '/^www\./i', '', strtolower( $target_domain ) );
                    $is_self = ( $normalized_item !== '' && $normalized_item === $normalized_target );

                    $rating_obj = $item['rating'] ?? [];
                    $competitors[] = [
                        'title'         => $item['title'] ?? '',
                        'rank'          => (int) ( $item['rank_group'] ?? 0 ) ?: null,
                        'rating'        => $rating_obj['value'] ?? null,
                        'reviews_count' => $rating_obj['votes_count'] ?? 0,
                        'is_self'       => $is_self,
                    ];
                    $comp_count++;
                }
            } else {
                $maps_error = is_wp_error( $maps_items ) ? $maps_items->get_error_message() : '';
                file_put_contents( '/tmp/gcrev_meo_debug.log',
                    date( 'Y-m-d H:i:s' ) . " Maps SERP error for '{$keyword_text}': {$maps_error}\n",
                    FILE_APPEND
                );
            }

            // Local Finder データ処理
            $finder_rank  = null;
            $finder_total = 0;

            if ( ! is_wp_error( $finder_items ) && is_array( $finder_items ) ) {
                $finder_total = count( $finder_items );
                $my_finder = $this->dataforseo->find_business_in_maps_results( $finder_items, $target_domain );
                if ( $my_finder ) {
                    $finder_rank = (int) ( $my_finder['rank_group'] ?? 0 ) ?: null;
                }
            } else {
                $finder_error = is_wp_error( $finder_items ) ? $finder_items->get_error_message() : '';
                file_put_contents( '/tmp/gcrev_meo_debug.log',
                    date( 'Y-m-d H:i:s' ) . " Local Finder error for '{$keyword_text}': {$finder_error}\n",
                    FILE_APPEND
                );
            }

            // 地域ラベル
            if ( $use_coordinate ) {
                $region_label = $meo_address ?: ( round( (float) $meo_lat, 4 ) . ', ' . round( (float) $meo_lng, 4 ) );
            } else {
                $region_label = $this->meo_get_location_label( $location_code );
            }

            $tz  = wp_timezone();
            $now = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

            $result = [
                'success'      => true,
                'keyword'      => $keyword_text,
                'device'       => $device,
                'region'       => $region_label,
                'location'     => [
                    'mode'          => $use_coordinate ? 'coordinate' : 'location_code',
                    'source'        => $location_source,
                    'lat'           => $use_coordinate ? (float) $meo_lat : null,
                    'lng'           => $use_coordinate ? (float) $meo_lng : null,
                    'address'       => $use_coordinate ? $meo_address : '',
                    'radius'        => $use_coordinate ? $effective_radius : null,
                    'radius_label'  => $use_coordinate ? Gcrev_DataForSEO_Client::zoom_to_radius_label( $zoom ) : null,
                    'zoom'          => $use_coordinate ? $zoom : null,
                    'location_code' => $use_coordinate ? null : $location_code,
                ],
                'radius_options' => Gcrev_DataForSEO_Client::get_radius_options(),
                'maps'         => [
                    'rank'          => $maps_rank,
                    'total_results' => is_array( $maps_items ) ? count( $maps_items ) : 0,
                    'store'         => $store_data,
                    'competitors'   => $competitors,
                ],
                'local_finder' => [
                    'rank'          => $finder_rank,
                    'total_results' => $finder_total,
                ],
                'keywords'  => $this->meo_build_keyword_list( $all_keywords, $kw_id ),
                'cached_at' => $now,
            ];

            set_transient( $cache_key, $result, 86400 );

            // 週次履歴テーブルにも保存（gcrev_meo_results — UPSERT）
            $this->meo_save_to_history( $user_id, $kw_id, $device, $maps_rank, $finder_rank, $store_data, $competitors );

            return new \WP_REST_Response( $result, 200 );

        } catch ( \Exception $e ) {
            file_put_contents( '/tmp/gcrev_meo_debug.log',
                date( 'Y-m-d H:i:s' ) . " MEO Rankings Error: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * MEO ランキングデータを gcrev_meo_results テーブルに保存（UPSERT）
     *
     * 同一 (user_id, keyword_id, device, iso_year_week) は上書きされる。
     * ページ閲覧ごとにリアルタイムデータが蓄積され、週次推移テーブルに反映される。
     */
    private function meo_save_to_history(
        int $user_id,
        int $keyword_id,
        string $device,
        ?int $maps_rank,
        ?int $finder_rank,
        ?array $store_data,
        array $competitors
    ): void {
        global $wpdb;

        $meo_table = $wpdb->prefix . 'gcrev_meo_results';

        // テーブル存在チェック
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $meo_table
        ) );
        if ( ! $table_exists ) {
            return;
        }

        $tz  = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );

        $iso_year_week = $now->format( 'o-\WW' );
        $fetch_date    = $now->format( 'Y-m-d' );
        $fetched_at    = $now->format( 'Y-m-d H:i:s' );

        // 今週のデータが既にあればスキップ（キャッシュヒット時の重複書き込み防止）
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$meo_table}
             WHERE user_id = %d AND keyword_id = %d AND device = %s AND iso_year_week = %s
             LIMIT 1",
            $user_id, $keyword_id, $device, $iso_year_week
        ) );
        if ( $exists ) {
            return;
        }

        // 店舗情報から rating / reviews_count を抽出
        $rating        = null;
        $reviews_count = null;
        if ( $store_data ) {
            $rating        = isset( $store_data['rating'] ) ? (float) $store_data['rating'] : null;
            $reviews_count = isset( $store_data['reviews_count'] ) ? (int) $store_data['reviews_count'] : null;
        }

        $wpdb->replace(
            $meo_table,
            [
                'user_id'          => $user_id,
                'keyword_id'       => $keyword_id,
                'device'           => $device,
                'maps_rank'        => $maps_rank,
                'finder_rank'      => $finder_rank,
                'rating'           => $rating,
                'reviews_count'    => $reviews_count,
                'store_data'       => $store_data ? wp_json_encode( $store_data, JSON_UNESCAPED_UNICODE ) : null,
                'competitors_data' => ! empty( $competitors ) ? wp_json_encode( $competitors, JSON_UNESCAPED_UNICODE ) : null,
                'iso_year_week'    => $iso_year_week,
                'fetch_date'       => $fetch_date,
                'fetched_at'       => $fetched_at,
                'created_at'       => $fetched_at,
            ],
            [ '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    // =========================================================
    // MEO 週次自動フェッチ（Cron: 毎週月曜 04:30）
    // =========================================================

    /**
     * MEO 週次フェッチ — エントリポイント（Cron から呼ばれる）
     *
     * 全ユーザーのキーワードについて Maps SERP / Local Finder SERP を取得し、
     * gcrev_meo_results テーブルに保存する。チャンク処理で自己チェーン。
     */
    public function auto_fetch_meo_rankings(): void {
        $lock_key = 'gcrev_lock_meo_fetch';
        $lock_ttl = 7200; // 2h

        if ( get_transient( $lock_key ) ) {
            file_put_contents( '/tmp/gcrev_meo_debug.log',
                date( 'Y-m-d H:i:s' ) . " [MEO] Lock is active, skipping\n", FILE_APPEND );
            return;
        }
        set_transient( $lock_key, 1, $lock_ttl );

        // Cron ログ開始（log_id をチャンク間で引き継ぐためTransientに保存）
        if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
            $log_id = Gcrev_Cron_Logger::start( 'meo_fetch' );
            set_transient( 'gcrev_meo_fetch_log_id', $log_id, 7200 );
        }

        file_put_contents( '/tmp/gcrev_meo_debug.log',
            date( 'Y-m-d H:i:s' ) . " [MEO] Starting weekly MEO fetch\n", FILE_APPEND );

        wp_schedule_single_event( time() + 5, 'gcrev_meo_fetch_chunk_event', [ 0, self::MEO_FETCH_CHUNK_LIMIT ] );
    }

    /**
     * MEO チャンクフェッチ — ユーザー単位でチャンク処理
     */
    public function meo_fetch_chunk( int $offset, int $limit ): void {
        global $wpdb;

        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $meo_table = $wpdb->prefix . 'gcrev_meo_results';

        // キーワード登録済みユーザーを取得（チャンク）
        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$kw_table}
             WHERE enabled = 1 AND target_domain != ''
             ORDER BY user_id ASC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        if ( empty( $user_ids ) ) {
            delete_transient( 'gcrev_lock_meo_fetch' );
            $log_id = (int) get_transient( 'gcrev_meo_fetch_log_id' );
            if ( $log_id > 0 && class_exists( 'Gcrev_Cron_Logger' ) ) {
                Gcrev_Cron_Logger::finish( $log_id );
                delete_transient( 'gcrev_meo_fetch_log_id' );
            }
            file_put_contents( '/tmp/gcrev_meo_debug.log',
                date( 'Y-m-d H:i:s' ) . " [MEO] Weekly MEO fetch completed\n", FILE_APPEND );
            return;
        }

        if ( ! $this->dataforseo || ! Gcrev_DataForSEO_Client::is_configured() ) {
            file_put_contents( '/tmp/gcrev_meo_debug.log',
                date( 'Y-m-d H:i:s' ) . " [MEO] DataForSEO not configured, aborting\n", FILE_APPEND );
            delete_transient( 'gcrev_lock_meo_fetch' );
            return;
        }

        $tz       = wp_timezone();
        $now_dt   = new \DateTimeImmutable( 'now', $tz );
        $iso_week = $now_dt->format( 'o-\WW' );

        foreach ( $user_ids as $uid ) {
            $uid = (int) $uid;

            // 座標情報の取得（手動 or 自動検出）
            $meo_lat = (string) get_user_meta( $uid, '_gcrev_meo_lat', true );
            $meo_lng = (string) get_user_meta( $uid, '_gcrev_meo_lng', true );
            $meo_radius = (int) get_user_meta( $uid, '_gcrev_meo_radius', true ) ?: 1000;

            if ( $meo_lat === '' || $meo_lng === '' ) {
                if ( class_exists( 'Gcrev_City_Coordinates' ) ) {
                    $city_coords = Gcrev_City_Coordinates::get_for_user( $uid );
                    if ( $city_coords ) {
                        $meo_lat    = (string) $city_coords['lat'];
                        $meo_lng    = (string) $city_coords['lng'];
                        $meo_radius = Gcrev_City_Coordinates::DEFAULT_RADIUS;
                    }
                }
            }

            // 座標が得られなければスキップ
            if ( $meo_lat === '' || $meo_lng === '' ) {
                continue;
            }

            $keywords = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$kw_table}
                 WHERE user_id = %d AND enabled = 1 AND target_domain != ''
                 ORDER BY sort_order ASC, id ASC",
                $uid
            ), ARRAY_A );

            foreach ( $keywords as $kw ) {
                $kw_id = (int) $kw['id'];

                // 週重複チェック
                $already = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$meo_table}
                     WHERE keyword_id = %d AND iso_year_week = %s",
                    $kw_id, $iso_week
                ) );

                if ( (int) $already > 0 ) {
                    continue;
                }

                $target_domain = $kw['target_domain'];
                $location_code = (int) ( $kw['location_code'] ?: 2392 );
                $zoom = Gcrev_DataForSEO_Client::radius_to_zoom( $meo_radius );
                $coordinate_str = Gcrev_DataForSEO_Client::build_coordinate_string(
                    (float) $meo_lat, (float) $meo_lng, $zoom
                );

                // mobile と desktop の両方で取得
                foreach ( ['mobile', 'desktop'] as $device ) {
                    $maps_items = $this->dataforseo->fetch_maps_serp(
                        $kw['keyword'], $device, $location_code,
                        $kw['language_code'] ?? 'ja', $coordinate_str
                    );

                    $maps_rank   = null;
                    $store       = null;
                    $competitors = [];

                    if ( ! is_wp_error( $maps_items ) && is_array( $maps_items ) ) {
                        $my_biz = $this->dataforseo->find_business_in_maps_results( $maps_items, $target_domain );
                        if ( $my_biz ) {
                            $maps_rank = (int) ( $my_biz['rank_group'] ?? 0 ) ?: null;
                            $store     = $this->meo_extract_store_info( $my_biz );
                        }
                        $comp_count = 0;
                        foreach ( $maps_items as $item ) {
                            if ( $comp_count >= 10 ) break;
                            if ( empty( $item['title'] ) ) continue;
                            $item_domain = $item['domain'] ?? '';
                            $norm_item   = preg_replace( '/^www\./i', '', strtolower( $item_domain ) );
                            $norm_target = preg_replace( '/^www\./i', '', strtolower( $target_domain ) );
                            $is_self = ( $norm_item !== '' && $norm_item === $norm_target );
                            $rating_obj = $item['rating'] ?? [];
                            $competitors[] = [
                                'title'         => $item['title'] ?? '',
                                'rank'          => (int) ( $item['rank_group'] ?? 0 ) ?: null,
                                'rating'        => $rating_obj['value'] ?? null,
                                'reviews_count' => $rating_obj['votes_count'] ?? 0,
                                'is_self'       => $is_self,
                            ];
                            $comp_count++;
                        }
                    }

                    $finder_items = $this->dataforseo->fetch_local_finder_serp(
                        $kw['keyword'], $device, $location_code,
                        $kw['language_code'] ?? 'ja', $coordinate_str
                    );

                    $finder_rank = null;
                    if ( ! is_wp_error( $finder_items ) && is_array( $finder_items ) ) {
                        $my_finder = $this->dataforseo->find_business_in_maps_results( $finder_items, $target_domain );
                        if ( $my_finder ) {
                            $finder_rank = (int) ( $my_finder['rank_group'] ?? 0 ) ?: null;
                        }
                    }

                    $rating_val   = $store['rating'] ?? null;
                    $reviews_cnt  = $store['reviews_count'] ?? null;

                    $this->meo_save_result_row(
                        $uid, $kw_id, $device,
                        $maps_rank, $finder_rank,
                        $rating_val !== null ? (float) $rating_val : null,
                        $reviews_cnt !== null ? (int) $reviews_cnt : null,
                        $store, $competitors,
                        $iso_week
                    );
                }
            }

            $log_id = (int) get_transient( 'gcrev_meo_fetch_log_id' );
            if ( $log_id > 0 && class_exists( 'Gcrev_Cron_Logger' ) ) {
                Gcrev_Cron_Logger::log_user( $log_id, $uid, 'ok' );
            }
        }

        // 次のチャンク
        $next_offset = $offset + $limit;
        wp_schedule_single_event( time() + 10, 'gcrev_meo_fetch_chunk_event', [ $next_offset, $limit ] );
        file_put_contents( '/tmp/gcrev_meo_debug.log',
            date( 'Y-m-d H:i:s' ) . " [MEO] Chunk done (offset={$offset}), next scheduled at offset={$next_offset}\n",
            FILE_APPEND );
    }

    /**
     * MEO 結果を1行保存（INSERT ... ON DUPLICATE KEY UPDATE）
     */
    private function meo_save_result_row(
        int $user_id,
        int $keyword_id,
        string $device,
        ?int $maps_rank,
        ?int $finder_rank,
        ?float $rating,
        ?int $reviews_count,
        ?array $store_data,
        ?array $competitors_data,
        string $iso_week
    ): bool {
        global $wpdb;

        $table  = $wpdb->prefix . 'gcrev_meo_results';
        $tz     = wp_timezone();
        $now_dt = new \DateTimeImmutable( 'now', $tz );
        $now    = $now_dt->format( 'Y-m-d H:i:s' );
        $date   = $now_dt->format( 'Y-m-d' );

        $store_json = $store_data ? wp_json_encode( $store_data, JSON_UNESCAPED_UNICODE ) : null;
        $comp_json  = $competitors_data ? wp_json_encode( $competitors_data, JSON_UNESCAPED_UNICODE ) : null;

        $maps_rank_sql   = $maps_rank !== null ? $wpdb->prepare( '%d', $maps_rank ) : 'NULL';
        $finder_rank_sql = $finder_rank !== null ? $wpdb->prepare( '%d', $finder_rank ) : 'NULL';
        $rating_sql      = $rating !== null ? $wpdb->prepare( '%f', $rating ) : 'NULL';
        $reviews_sql     = $reviews_count !== null ? $wpdb->prepare( '%d', $reviews_count ) : 'NULL';
        $store_sql       = $store_json !== null ? $wpdb->prepare( '%s', $store_json ) : 'NULL';
        $comp_sql        = $comp_json !== null ? $wpdb->prepare( '%s', $comp_json ) : 'NULL';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
             (user_id, keyword_id, device, maps_rank, finder_rank, rating, reviews_count,
              store_data, competitors_data, iso_year_week, fetch_date, fetched_at, created_at)
             VALUES (%d, %d, %s, {$maps_rank_sql}, {$finder_rank_sql}, {$rating_sql}, {$reviews_sql},
                     {$store_sql}, {$comp_sql}, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
              maps_rank        = VALUES(maps_rank),
              finder_rank      = VALUES(finder_rank),
              rating           = VALUES(rating),
              reviews_count    = VALUES(reviews_count),
              store_data       = VALUES(store_data),
              competitors_data = VALUES(competitors_data),
              fetched_at       = VALUES(fetched_at)",
            $user_id,
            $keyword_id,
            $device,
            $iso_week,
            $date,
            $now,
            $now
        );

        $result = $wpdb->query( $sql );
        return $result !== false;
    }

    /**
     * Maps SERP アイテムから店舗情報を抽出
     */
    private function meo_extract_store_info( array $item ): array {
        $rating_obj = $item['rating'] ?? [];
        $rating_dist_raw = $item['rating_distribution'] ?? [];

        $rating_dist = [];
        if ( is_array( $rating_dist_raw ) ) {
            for ( $i = 1; $i <= 5; $i++ ) {
                $rating_dist[ (string) $i ] = (int) ( $rating_dist_raw[ $i ] ?? $rating_dist_raw[ (string) $i ] ?? 0 );
            }
        }

        $work_hours_text = '';
        $work_hours = $item['work_hours'] ?? [];
        if ( ! empty( $work_hours['timetable'] ) && is_array( $work_hours['timetable'] ) ) {
            foreach ( $work_hours['timetable'] as $day => $slots ) {
                if ( ! empty( $slots ) && is_array( $slots ) ) {
                    $slot = $slots[0] ?? [];
                    $open  = $slot['open']['hour'] ?? '';
                    $close = $slot['close']['hour'] ?? '';
                    if ( $open !== '' && $close !== '' ) {
                        $open_min  = str_pad( (string) ( $slot['open']['minute'] ?? 0 ), 2, '0', STR_PAD_LEFT );
                        $close_min = str_pad( (string) ( $slot['close']['minute'] ?? 0 ), 2, '0', STR_PAD_LEFT );
                        $work_hours_text = "{$open}:{$open_min}-{$close}:{$close_min}";
                        break;
                    }
                }
            }
        }
        if ( empty( $work_hours_text ) && ! empty( $work_hours['current_status'] ) ) {
            $work_hours_text = $work_hours['current_status'];
        }

        $cid = $item['cid'] ?? '';
        $maps_url = $cid ? 'https://www.google.com/maps?cid=' . $cid : '';

        return [
            'title'               => $item['title'] ?? '',
            'category'            => $item['category'] ?? '',
            'address'             => $item['address'] ?? '',
            'phone'               => $item['phone'] ?? '',
            'rating'              => $rating_obj['value'] ?? null,
            'reviews_count'       => (int) ( $rating_obj['votes_count'] ?? 0 ),
            'rating_distribution' => $rating_dist,
            'work_hours'          => $work_hours_text,
            'url'                 => $item['url'] ?? '',
            'cid'                 => $cid,
            'maps_url'            => $maps_url,
        ];
    }

    /**
     * キーワード一覧をセレクター用に整形
     */
    private function meo_build_keyword_list( array $all_keywords, int $selected_id ): array {
        $list = [];
        foreach ( $all_keywords as $kw ) {
            $list[] = [
                'id'       => (int) $kw['id'],
                'keyword'  => $kw['keyword'],
                'selected' => ( (int) $kw['id'] === $selected_id ),
            ];
        }
        return $list;
    }

    /**
     * location_code から地域ラベルを返す
     */
    private function meo_get_location_label( int $code ): string {
        $map = [
            2392    => '日本（広域）',
            1009283 => '東京都',
            1009303 => '大阪府',
            1009269 => '愛知県',
            1009280 => '福岡県',
            1009275 => '北海道',
            1009271 => '愛媛県',
        ];
        return $map[ $code ] ?? '指定地域';
    }

    /**
     * GET /meo/history — 直近6週分の MEO 計測履歴
     */
    public function rest_get_meo_history( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id = get_current_user_id();

        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $meo_table = $wpdb->prefix . 'gcrev_meo_results';

        $tz  = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );

        $weeks = [];
        for ( $i = 5; $i >= 0; $i-- ) {
            $dt = $now->modify( "-{$i} weeks" );
            $weeks[] = $dt->format( 'o-\WW' );
        }
        $week_labels = array_map( 'gcrev_week_label', $weeks );

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword FROM {$kw_table}
             WHERE user_id = %d AND enabled = 1
             ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        if ( empty( $keywords ) ) {
            return new \WP_REST_Response([
                'success' => true,
                'data'    => [
                    'keywords'    => [],
                    'weeks'       => $weeks,
                    'week_labels' => $week_labels,
                    'latest'      => null,
                ],
            ]);
        }

        $keyword_ids  = array_column( $keywords, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $keyword_ids ), '%d' ) );

        // meo_results テーブルが存在するか確認（テーブルは残置されているが将来DROPされる可能性あり）
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $wpdb->prefix . 'gcrev_meo_results'
        ) );

        if ( ! $table_exists ) {
            // テーブルが存在しない場合は空データを返す
            $output = [];
            foreach ( $keywords as $kw ) {
                $output[] = [
                    'keyword_id' => (int) $kw['id'],
                    'keyword'    => $kw['keyword'],
                    'weekly'     => [ 'mobile' => [], 'desktop' => [] ],
                ];
            }
            return new \WP_REST_Response([
                'success' => true,
                'data'    => [
                    'keywords'    => $output,
                    'weeks'       => $weeks,
                    'week_labels' => $week_labels,
                    'latest'      => null,
                ],
            ]);
        }

        $week_placeholders = implode( ',', array_fill( 0, count( $weeks ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT keyword_id, device, maps_rank, finder_rank, rating, reviews_count,
                    store_data, competitors_data, iso_year_week, fetched_at
             FROM {$meo_table}
             WHERE user_id = %d
               AND keyword_id IN ({$placeholders})
               AND iso_year_week IN ({$week_placeholders})
             ORDER BY fetched_at DESC",
            ...array_merge( [ $user_id ], $keyword_ids, $weeks )
        ), ARRAY_A );

        $grouped = [];
        foreach ( $rows as $r ) {
            $gkey = $r['keyword_id'] . '_' . $r['device'] . '_' . $r['iso_year_week'];
            if ( ! isset( $grouped[ $gkey ] ) ) {
                $grouped[ $gkey ] = $r;
            }
        }

        $latest_week = end( $weeks );
        $latest_data = null;

        $output = [];
        foreach ( $keywords as $kw ) {
            $kw_id = (int) $kw['id'];
            $entry = [
                'keyword_id' => $kw_id,
                'keyword'    => $kw['keyword'],
                'weekly'     => [ 'mobile' => [], 'desktop' => [] ],
            ];

            foreach ( ['mobile', 'desktop'] as $device ) {
                foreach ( $weeks as $week ) {
                    $gkey = $kw_id . '_' . $device . '_' . $week;
                    $r    = $grouped[ $gkey ] ?? null;

                    if ( $r ) {
                        $entry['weekly'][ $device ][ $week ] = [
                            'maps_rank'   => $r['maps_rank'] !== null ? (int) $r['maps_rank'] : null,
                            'finder_rank' => $r['finder_rank'] !== null ? (int) $r['finder_rank'] : null,
                            'rating'      => $r['rating'] !== null ? (float) $r['rating'] : null,
                            'reviews'     => $r['reviews_count'] !== null ? (int) $r['reviews_count'] : null,
                        ];

                        if ( $week === $latest_week && $device === 'mobile' && $r['store_data'] && ! $latest_data ) {
                            $latest_data = [
                                'store'       => json_decode( $r['store_data'], true ),
                                'competitors' => $r['competitors_data'] ? json_decode( $r['competitors_data'], true ) : [],
                                'maps_rank'   => $r['maps_rank'] !== null ? (int) $r['maps_rank'] : null,
                                'finder_rank' => $r['finder_rank'] !== null ? (int) $r['finder_rank'] : null,
                            ];
                        }
                    } else {
                        $entry['weekly'][ $device ][ $week ] = null;
                    }
                }
            }

            $output[] = $entry;
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'keywords'    => $output,
                'weeks'       => $weeks,
                'week_labels' => $week_labels,
                'latest'      => $latest_data,
            ],
        ]);
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
        $filter_sfx = $this->ga4->has_country_filter() ? '_jp' : '';
        $cache_key = "gcrev_source_{$user_id}_{$period}_" . md5("{$start_date}_{$end_date}") . $filter_sfx;
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

        $metrics = [
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
            'CALL_CLICKS',
            'WEBSITE_CLICKS',
            'BUSINESS_DIRECTION_REQUESTS',
            'BUSINESS_FOOD_MENU_CLICKS',
        ];

        // 各メトリクスを複数キーに加算するマッピング
        $metric_map = [
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => ['desktop_impressions'],
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'  => ['mobile_impressions'],
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS'   => ['desktop_impressions'],
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS'    => ['mobile_impressions'],
            'CALL_CLICKS'                         => ['call_clicks'],
            'WEBSITE_CLICKS'                      => ['website_clicks'],
            'BUSINESS_DIRECTION_REQUESTS'         => ['direction_clicks'],
            'BUSINESS_FOOD_MENU_CLICKS'           => ['menu_clicks'],
        ];

        $start_obj = $this->gbp_date_obj($start_date);
        $end_obj   = $this->gbp_date_obj($end_date);

        $totals = $this->gbp_empty_metrics();

        foreach ($metrics as $metric) {
            $params = [
                'dailyMetric'                  => $metric,
                'dailyRange.startDate.year'    => $start_obj['year'],
                'dailyRange.startDate.month'   => $start_obj['month'],
                'dailyRange.startDate.day'     => $start_obj['day'],
                'dailyRange.endDate.year'      => $end_obj['year'],
                'dailyRange.endDate.month'     => $end_obj['month'],
                'dailyRange.endDate.day'       => $end_obj['day'],
            ];

            $url = "https://businessprofileperformance.googleapis.com/v1/{$location_id}:getDailyMetricsTimeSeries?"
                 . http_build_query($params, '', '&');

            $response = wp_remote_get($url, [
                'headers' => ['Authorization' => 'Bearer ' . $access_token],
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            $status   = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);

            if ($status !== 200) {
                continue;
            }

            $data        = json_decode($raw_body, true);
            $target_keys = $metric_map[$metric] ?? [];

            foreach (($data['timeSeries']['datedValues'] ?? []) as $dv) {
                $val = (int) ($dv['value'] ?? 0);
                foreach ($target_keys as $key) {
                    $totals[$key] += $val;
                }
            }
        }

        $totals['total_impressions'] = $totals['mobile_impressions'] + $totals['desktop_impressions'];

        return $totals;
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

        $metrics = [
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => 'search',
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'  => 'search',
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS'   => 'map',
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS'    => 'map',
        ];

        $start_obj = $this->gbp_date_obj($start_date);
        $end_obj   = $this->gbp_date_obj($end_date);
        $daily     = []; // date => [search_impressions, map_impressions]

        foreach ($metrics as $metric => $type) {
            $params = [
                'dailyMetric'                  => $metric,
                'dailyRange.startDate.year'    => $start_obj['year'],
                'dailyRange.startDate.month'   => $start_obj['month'],
                'dailyRange.startDate.day'     => $start_obj['day'],
                'dailyRange.endDate.year'      => $end_obj['year'],
                'dailyRange.endDate.month'     => $end_obj['month'],
                'dailyRange.endDate.day'       => $end_obj['day'],
            ];

            $url = "https://businessprofileperformance.googleapis.com/v1/{$location_id}:getDailyMetricsTimeSeries?"
                 . http_build_query($params, '', '&');

            $response = wp_remote_get($url, [
                'headers' => ['Authorization' => 'Bearer ' . $access_token],
                'timeout' => 15,
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                continue;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);

            foreach (($data['timeSeries']['datedValues'] ?? []) as $dv) {
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

        ksort($daily);
        return array_values($daily);
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

        $result = json_decode(wp_remote_retrieve_body($response), true);
        $keywords = [];

        foreach (($result['searchKeywordsCounts'] ?? []) as $item) {
            $impressions = (int) ($item['insightsValue']['value'] ?? $item['insightsValue']['threshold'] ?? 0);
            $keywords[] = [
                'keyword'     => $item['searchKeyword'] ?? '',
                'impressions' => $impressions,
            ];
        }

        usort($keywords, function($a, $b) { return $b['impressions'] - $a['impressions']; });
        return $keywords;
    }

    /**
     * 直近Nヶ月の検索キーワードを月別に取得し、時系列テーブル用データを返す
     *
     * @return array ['months' => ['2025/08', ...], 'keywords' => [['keyword' => '...', 'monthly' => [count, ...], 'total' => int], ...]]
     */
    private function gbp_fetch_keywords_monthly_series(
        string $access_token, string $location_id, int $months = 6
    ): array {
        $now = new \DateTimeImmutable('first day of last month', wp_timezone());
        $start = $now->modify('-' . ($months - 1) . ' months');

        // 月ラベル生成
        $month_labels = [];
        for ($i = 0; $i < $months; $i++) {
            $month_labels[] = $start->modify("+{$i} months")->format('Y/m');
        }

        // location_id 正規化
        if (strpos($location_id, 'locations/') !== 0) {
            $location_id = 'locations/' . $location_id;
        }

        // 6ヶ月分を一括取得（ページネーション対応）
        $url = "https://businessprofileperformance.googleapis.com/v1/{$location_id}/searchkeywords/impressions/monthly";
        $base_params = [
            'monthlyRange.startMonth.year'  => (int) $start->format('Y'),
            'monthlyRange.startMonth.month' => (int) $start->format('n'),
            'monthlyRange.endMonth.year'    => (int) $now->format('Y'),
            'monthlyRange.endMonth.month'   => (int) $now->format('n'),
            'pageSize'                      => 100,
        ];

        $all_items = [];
        $page_token = null;

        do {
            $params = $base_params;
            if ($page_token) {
                $params['pageToken'] = $page_token;
            }

            $response = wp_remote_get($url . '?' . http_build_query($params), [
                'headers' => ['Authorization' => 'Bearer ' . $access_token],
                'timeout' => 30,
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                $code = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
                file_put_contents('/tmp/gcrev_kw_debug.log',
                    date('Y-m-d H:i:s') . " keywords_monthly_series ERROR: {$code}\n" .
                    wp_remote_retrieve_body($response) . "\n",
                    FILE_APPEND
                );
                break;
            }

            $result = json_decode(wp_remote_retrieve_body($response), true);
            $items  = $result['searchKeywordsCounts'] ?? [];
            $all_items = array_merge($all_items, $items);
            $page_token = $result['nextPageToken'] ?? null;
        } while ($page_token);

        if (empty($all_items)) {
            file_put_contents('/tmp/gcrev_kw_debug.log',
                date('Y-m-d H:i:s') . " keywords_monthly_series: 0 items. params=" . wp_json_encode($base_params) . "\n",
                FILE_APPEND
            );
            return ['months' => $month_labels, 'keywords' => []];
        }

        // キーワード×月ごとに集計
        // API returns: searchKeywordsCounts[].searchKeyword, .insightsValue.value/.threshold
        // Each item corresponds to one keyword for ONE month (the API returns
        // separate entries per keyword per month within the range).
        // しかし月情報がレスポンスに含まれない場合がある → 月単位でAPIを呼ぶフォールバック
        $monthly_data = []; // month_key => [keyword => impressions]
        foreach ($month_labels as $ml) {
            $monthly_data[$ml] = [];
        }

        // Check if items have month info
        $has_month_info = isset($all_items[0]['searchMonth']);

        if ($has_month_info) {
            // APIが月情報を返す場合
            foreach ($all_items as $item) {
                $kw = $item['searchKeyword'] ?? '';
                $imp = (int) ($item['insightsValue']['value'] ?? $item['insightsValue']['threshold'] ?? 0);
                $m = $item['searchMonth'] ?? [];
                if (!empty($m['year']) && !empty($m['month'])) {
                    $mk = sprintf('%04d/%02d', $m['year'], $m['month']);
                    if (isset($monthly_data[$mk])) {
                        $monthly_data[$mk][$kw] = ($monthly_data[$mk][$kw] ?? 0) + $imp;
                    }
                }
            }
        } else {
            // 月情報がない場合 → 月別に個別APIコール
            foreach ($month_labels as $ml) {
                $parts = explode('/', $ml);
                $y = (int) $parts[0];
                $m = (int) $parts[1];
                $month_start = sprintf('%04d-%02d-01', $y, $m);
                $month_end   = date('Y-m-t', strtotime($month_start));

                $kw_data = $this->gbp_fetch_search_keywords(
                    $access_token, $location_id, $month_start, $month_end
                );
                foreach ($kw_data as $kw) {
                    $monthly_data[$ml][$kw['keyword']] = $kw['impressions'];
                }
            }
        }

        // 全キーワードを集約
        $all_keywords = [];
        foreach ($monthly_data as $map) {
            foreach (array_keys($map) as $kw) {
                $all_keywords[$kw] = true;
            }
        }

        // キーワードごとに月別データと合計を組み立て
        $rows = [];
        foreach (array_keys($all_keywords) as $kw) {
            $monthly = [];
            $total   = 0;
            foreach ($month_labels as $ml) {
                $val = $monthly_data[$ml][$kw] ?? 0;
                $monthly[] = $val;
                $total += $val;
            }
            $rows[] = [
                'keyword' => $kw,
                'monthly' => $monthly,
                'total'   => $total,
            ];
        }

        // 合計降順でソート
        usort($rows, function($a, $b) { return $b['total'] - $a['total']; });

        return [
            'months'   => $month_labels,
            'keywords' => $rows,
        ];
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
            'total_impressions'   => 0,
            'mobile_impressions'  => 0,
            'desktop_impressions' => 0,
            'call_clicks'         => 0,
            'direction_clicks'    => 0,
            'website_clicks'      => 0,
            'menu_clicks'         => 0,
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

        foreach (($api_response['multiDailyMetricTimeSeries'] ?? []) as $multi) {
            foreach (($multi['dailyMetricTimeSeries'] ?? []) as $series) {
                $metric_name = $series['dailyMetric'] ?? '';
                $target_key  = $metric_map[$metric_name] ?? null;
                if (!$target_key) continue;

                foreach (($series['timeSeries']['datedValues'] ?? []) as $dv) {
                    $totals[$target_key] += (int) ($dv['value'] ?? 0);
                }
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

        foreach (($api_response['multiDailyMetricTimeSeries'] ?? []) as $multi) {
            foreach (($multi['dailyMetricTimeSeries'] ?? []) as $series) {
                $metric_name = $series['dailyMetric'] ?? '';
                $type        = $metric_type_map[$metric_name] ?? null;
                if (!$type) continue;

                foreach (($series['timeSeries']['datedValues'] ?? []) as $dv) {
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
        if ($score >= 70) return '安定しています';
        if ($score >= 50) return '改善傾向です';
        if ($score >= 35) return 'もう少しです';
        return '要注意です';
    }

    // ---------------------------------------------------------
    // スコアリング v2 — 4コンポーネント制ヘルパー
    // ---------------------------------------------------------

    /**
     * 過去3〜6ヶ月のインフォグラフィックから各観点の中央値を算出
     *
     * @param int $user_id   ユーザーID
     * @param int $year      対象年
     * @param int $month     対象月
     * @param int $lookback  遡る月数（デフォルト6）
     * @return array|null    ['traffic'=>float, ...] or null（3ヶ月未満の場合）
     */
    private function get_historical_medians(int $user_id, int $year, int $month, int $lookback = 6): ?array {
        $values = ['traffic' => [], 'cv' => [], 'gsc' => [], 'meo' => []];
        $dt = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), wp_timezone());

        for ($i = 1; $i <= $lookback; $i++) {
            $past = $dt->modify("-{$i} months");
            $ig = $this->get_monthly_infographic((int)$past->format('Y'), (int)$past->format('n'), $user_id);
            if (!$ig || empty($ig['breakdown'])) {
                continue;
            }
            foreach (['traffic', 'cv', 'gsc', 'meo'] as $key) {
                if (isset($ig['breakdown'][$key]['curr'])) {
                    $values[$key][] = (float)$ig['breakdown'][$key]['curr'];
                }
            }
        }

        // 最低3ヶ月分のデータが必要
        $min_count = min(array_map('count', $values));
        if ($min_count < 3) {
            return null;
        }

        $medians = [];
        foreach ($values as $key => $arr) {
            sort($arr);
            $n = count($arr);
            $medians[$key] = ($n % 2 === 0)
                ? ($arr[$n / 2 - 1] + $arr[$n / 2]) / 2
                : $arr[(int)floor($n / 2)];
        }
        return $medians;
    }

    /**
     * Achievement（実績）コンポーネント — 中央値比較（50pt満点）
     *
     * 各観点12.5pt × 4 = 50pt
     * 中央値以上なら満点、以下なら比率に応じて減点
     * 中央値データがない場合は前月比フォールバック
     *
     * @return array ['points'=>int, 'details'=>array]
     */
    private function calc_achievement_component(array $curr, ?array $medians, array $prev): array {
        $details = [];
        $total   = 0.0;
        $per_dim = 12.5;

        if ($medians === null) {
            // フォールバック: 前月比ベースで柔らかめに配点
            foreach (['traffic', 'cv', 'gsc', 'meo'] as $key) {
                $c = (float)($curr[$key] ?? 0);
                $p = (float)($prev[$key] ?? 0);
                if ((int)$c === 0) {
                    $details[$key] = ['points' => 0, 'max' => $per_dim, 'ratio' => null, 'fallback' => true];
                    continue;
                }
                $pct = $this->calc_pct_change($c, $p);
                if ($pct >= 5.0)       $pts = $per_dim;      // 12.5
                elseif ($pct >= -5.0)  $pts = $per_dim * 0.86; // ~10.75
                elseif ($pct >= -15.0) $pts = $per_dim * 0.7;  // ~8.75
                else                   $pts = $per_dim * 0.5;  // 6.25
                $total += $pts;
                $details[$key] = ['points' => round($pts, 1), 'max' => $per_dim, 'ratio' => null, 'fallback' => true];
            }
        } else {
            foreach (['traffic', 'cv', 'gsc', 'meo'] as $key) {
                $c = (float)($curr[$key] ?? 0);
                $m = (float)($medians[$key] ?? 0);
                if ((int)$c === 0) {
                    $details[$key] = ['points' => 0, 'max' => $per_dim, 'ratio' => 0];
                    continue;
                }
                if ($m <= 0) {
                    // median=0, curr>0 → 満点
                    $total += $per_dim;
                    $details[$key] = ['points' => $per_dim, 'max' => $per_dim, 'ratio' => null];
                    continue;
                }
                $ratio = $c / $m;
                if ($ratio >= 1.0)      $pts = $per_dim;       // 12.5
                elseif ($ratio >= 0.8)  $pts = $per_dim * 0.8;  // 10
                elseif ($ratio >= 0.6)  $pts = $per_dim * 0.6;  // 7.5
                elseif ($ratio >= 0.4)  $pts = $per_dim * 0.4;  // 5
                else                    $pts = $per_dim * 0.2;  // 2.5
                $total += $pts;
                $details[$key] = ['points' => round($pts, 1), 'max' => $per_dim, 'ratio' => round($ratio, 2)];
            }
        }

        return [
            'points'  => (int)round($total),
            'max'     => 50,
            'label'   => '実績（中央値比較）',
            'details' => $details,
        ];
    }

    /**
     * Growth（成長）コンポーネント — 前月比（30pt満点）
     *
     * ±5%以内のデッドゾーンは満点扱い（現状維持を評価）
     * 各観点7.5pt × 4 = 30pt
     *
     * @return array ['points'=>int, 'details'=>array]
     */
    private function calc_growth_component(array $curr, array $prev): array {
        $details = [];
        $total   = 0.0;
        $per_dim = 7.5;

        foreach (['traffic', 'cv', 'gsc', 'meo'] as $key) {
            $c = (float)($curr[$key] ?? 0);
            $p = (float)($prev[$key] ?? 0);
            if ((int)$c === 0) {
                $details[$key] = ['points' => 0, 'max' => $per_dim, 'pct' => 0, 'zone' => 'zero'];
                continue;
            }
            $pct = $this->calc_pct_change($c, $p);
            if ($pct > 10.0) {
                $pts = $per_dim;       // 7.5
                $zone = 'high';
            } elseif ($pct > 5.0) {
                $pts = $per_dim * 0.93; // ~7.0
                $zone = 'mid';
            } elseif ($pct >= -5.0) {
                $pts = $per_dim;       // 7.5 — デッドゾーン = 満点
                $zone = 'dead';
            } elseif ($pct >= -15.0) {
                $pts = $per_dim * 0.53; // ~4.0
                $zone = 'soft_decline';
            } else {
                $pts = $per_dim * 0.13; // ~1.0
                $zone = 'hard_decline';
            }
            $total += $pts;
            $details[$key] = ['points' => round($pts, 1), 'max' => $per_dim, 'pct' => round($pct, 1), 'zone' => $zone];
        }

        return [
            'points'  => (int)round($total),
            'max'     => 30,
            'label'   => '成長（前月比）',
            'details' => $details,
        ];
    }

    /**
     * Stability（安定性）コンポーネント — 急落ペナルティ（10pt満点）
     *
     * -20%超の急落がなければ満点
     *
     * @return array ['points'=>int, 'drops'=>int]
     */
    private function calc_stability_component(array $curr, array $prev): array {
        $drops   = 0;
        $has_any = false;
        $details = [];

        foreach (['traffic', 'cv', 'gsc', 'meo'] as $key) {
            $c = (float)($curr[$key] ?? 0);
            $p = (float)($prev[$key] ?? 0);
            if ((int)$c === 0) {
                $details[$key] = ['pct' => 0, 'drop' => false, 'zero' => true];
                continue;
            }
            $has_any = true;
            $pct = $this->calc_pct_change($c, $p);
            $is_drop = $pct < -20.0;
            if ($is_drop) {
                $drops++;
            }
            $details[$key] = ['pct' => round($pct, 1), 'drop' => $is_drop, 'zero' => false];
        }

        if (!$has_any) {
            $points = 0;
        } elseif ($drops === 0) {
            $points = 10;
        } elseif ($drops === 1) {
            $points = 6;
        } else {
            $points = 2;
        }

        return [
            'points'  => $points,
            'max'     => 10,
            'label'   => '安定性',
            'drops'   => $drops,
            'details' => $details,
        ];
    }

    /**
     * Action Bonus（行動ボーナス）コンポーネント — 設定充実度（10pt満点）
     *
     * クライアント設定・月次レポート設定の入力状況を評価
     * 各項目2pt × 5 = 10pt
     *
     * @return array ['points'=>int, 'checks'=>array]
     */
    private function calc_action_bonus_component(int $user_id): array {
        $checks = [];
        $points = 0;

        $client = gcrev_get_client_settings($user_id);

        $items = [
            ['key' => 'site_url',            'label' => 'サイトURL',   'value' => $client['site_url'] ?? ''],
            ['key' => 'area_type',           'label' => '商圏',       'value' => $client['area_type'] ?? ''],
            ['key' => 'industry_category',   'label' => '業種',       'value' => $client['industry_category'] ?? ''],
            ['key' => 'report_issue',        'label' => '今月の課題',  'value' => get_user_meta($user_id, 'report_issue', true)],
            ['key' => 'report_goal_monthly', 'label' => '今月の目標',  'value' => get_user_meta($user_id, 'report_goal_monthly', true)],
        ];

        foreach ($items as $item) {
            $ok = !empty($item['value']);
            if ($ok) {
                $points += 2;
            }
            $checks[] = ['label' => $item['label'], 'ok' => $ok];
        }

        return [
            'points' => min(10, $points),
            'max'    => 10,
            'label'  => '行動ボーナス',
            'checks' => $checks,
        ];
    }

    /**
     * 月次ヘルススコアを計算（v2: 4コンポーネント制）
     *
     * Achievement(50) + Growth(30) + Stability(10) + ActionBonus(10) = 100点
     * フロア: 最低35点（全指標0の場合は0点）
     *
     * @param array $curr    当月の指標（キー: traffic, cv, gsc, meo）
     * @param array $prev    前月の指標（同上）
     * @param array $opt     オプション（将来用、現在は未使用）
     * @param int   $user_id ユーザーID（0=中央値/ActionBonus無効）
     * @param int   $year    対象年（0=中央値無効）
     * @param int   $month   対象月（0=中央値無効）
     * @return array {score:int, status:string, breakdown:array, components:array}
     */
    public function calc_monthly_health_score(array $curr, array $prev, array $opt = [], int $user_id = 0, int $year = 0, int $month = 0): array {

        // --- 1) 後方互換 breakdown（旧形式: 4観点 × curr/prev/pct/points/max） ---
        $dimensions = [
            'traffic' => ['label' => 'サイトに来た人の数',       'max' => 25],
            'cv'      => ['label' => 'ゴール（問い合わせ・申込みなど）', 'max' => 25],
            'gsc'     => ['label' => '検索結果からクリックされた数', 'max' => 25],
            'meo'     => ['label' => '地図検索からの表示数',     'max' => 25],
        ];
        $breakdown = [];
        foreach ($dimensions as $key => $dim) {
            $c = (float)($curr[$key] ?? 0);
            $p = (float)($prev[$key] ?? 0);
            $pct    = $this->calc_pct_change($c, $p);
            $points = $this->pct_to_points($pct, $dim['max']);
            if ((int)$c === 0) {
                $points = 0;
                $pct    = 0;
            }
            $breakdown[$key] = [
                'label'  => $dim['label'],
                'curr'   => $c,
                'prev'   => $p,
                'pct'    => round($pct, 1),
                'points' => $points,
                'max'    => $dim['max'],
            ];
        }

        // --- 2) 4コンポーネント計算 ---
        $medians = ($user_id > 0 && $year > 0 && $month > 0)
            ? $this->get_historical_medians($user_id, $year, $month)
            : null;

        $achievement = $this->calc_achievement_component($curr, $medians, $prev);
        $growth      = $this->calc_growth_component($curr, $prev);
        $stability   = $this->calc_stability_component($curr, $prev);
        $action      = ($user_id > 0)
            ? $this->calc_action_bonus_component($user_id)
            : ['points' => 0, 'max' => 10, 'label' => '行動ボーナス', 'checks' => []];

        $raw_total = $achievement['points'] + $growth['points'] + $stability['points'] + $action['points'];

        // --- 3) フロア適用（最低35点）。全観点0なら0点 ---
        $has_any_data = false;
        foreach (['traffic', 'cv', 'gsc', 'meo'] as $k) {
            if ((int)($curr[$k] ?? 0) > 0) {
                $has_any_data = true;
                break;
            }
        }
        $score = $has_any_data ? max(35, min(100, $raw_total)) : 0;

        $components = [
            'achievement' => $achievement,
            'growth'      => $growth,
            'stability'   => $stability,
            'action'      => $action,
        ];

        return [
            'score'      => $score,
            'status'     => $this->score_to_status($score),
            'breakdown'  => $breakdown,
            'components' => $components,
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
        // Transientキャッシュ（6時間）
        $cache_key = "gcrev_meo_perf_{$user_id}_{$start_date}_{$end_date}";
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        try {
            $location_id = (string)get_user_meta($user_id, '_gcrev_gbp_location_id', true);
            if (empty($location_id) || strpos($location_id, 'pending') === 0) {
                return $this->gbp_empty_metrics();
            }
            $access_token = $this->gbp_get_access_token($user_id);
            if (empty($access_token)) {
                return $this->gbp_empty_metrics();
            }
            $metrics = $this->gbp_fetch_performance_metrics($access_token, $location_id, $start_date, $end_date);
            set_transient($cache_key, $metrics, 6 * HOUR_IN_SECONDS);
            return $metrics;
        } catch (\Exception $e) {
            error_log("[GCREV] fetch_meo_metrics_safe: Error user_id={$user_id}: " . $e->getMessage());
            return $this->gbp_empty_metrics();
        }
    }

    /**
     * MEO合計表示回数を安全に取得（テンプレートから利用可能な公開ラッパー）
     *
     * @param int    $user_id
     * @param string $start YYYY-MM-DD
     * @param string $end   YYYY-MM-DD
     * @return int total_impressions（取得失敗時は0）
     */
    public function get_meo_total_impressions(int $user_id, string $start, string $end): int {
        $metrics = $this->fetch_meo_metrics_safe($user_id, $start, $end);
        return (int)($metrics['total_impressions'] ?? 0);
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

        // ===== 3) 対象年月をパース（中央値取得・score_diff用） =====
        $ig_year  = 0;
        $ig_month = 0;
        if ($prev_start !== '') {
            $ig_year  = (int)substr($prev_start, 0, 4);
            $ig_month = (int)substr($prev_start, 5, 2);
        }

        // ===== 4) ヘルススコア計算（v2: 4コンポーネント制） =====
        $health = $this->calc_monthly_health_score($curr_metrics, $prev_metrics, [], $user_id, $ig_year, $ig_month);

        error_log("[GCREV] generate_infographic_json: Health score calculated: score={$health['score']}, status={$health['status']}");
        error_log("[GCREV] generate_infographic_json: Metrics curr=" . wp_json_encode($curr_metrics) . " prev=" . wp_json_encode($prev_metrics));

        // ===== 5) KPI実数値と差分 =====
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

        // ===== 6) 前月スコアとの差分（score_diff） =====
        $score_diff = 0;
        if ($ig_year > 0 && $ig_month > 0 && $user_id > 0) {
            try {
                $prev_ig_dt = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $ig_year, $ig_month), wp_timezone()))
                    ->modify('-1 month');
                $prev_ig = $this->get_monthly_infographic(
                    (int)$prev_ig_dt->format('Y'),
                    (int)$prev_ig_dt->format('n'),
                    $user_id
                );
                if ($prev_ig && isset($prev_ig['score'])) {
                    $score_diff = $health['score'] - (int)$prev_ig['score'];
                }
            } catch (\Exception $sd_e) {
                error_log("[GCREV] generate_infographic_json: score_diff error: " . $sd_e->getMessage());
            }
        }

        // ===== 7) AI で summary / action のみ生成 =====
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

        // ===== 8) 最終JSON組み立て =====
        return [
            'score'      => $health['score'],
            'score_diff' => $score_diff,
            'status'     => $health['status'],
            'kpi'        => $kpi,
            'summary'    => $summary,
            'action'     => $action,
            'breakdown'  => $health['breakdown'],
            'components' => $health['components'] ?? null,
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

        // 国フィルタ状態をキャッシュキーに反映
        $filter_suffix = $this->ga4->has_country_filter() ? '_jp' : '';
        $cache_key = "gcrev_ga4cv_{$user_id}_{$year_month}{$filter_suffix}";
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

            // 国フィルタ適用（GA4 Fetcher と同じフィルタを使用）
            $country_filter = $this->ga4->get_country_filter();
            if ( $country_filter !== null ) {
                $request->setDimensionFilter( $country_filter );
            }

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
        $filter_suffix = $this->ga4->has_country_filter() ? '_jp' : '';
        $cache_key = "gcrev_ga4kevt_daily_{$user_id}_{$year_month}{$filter_suffix}";
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
        // INFORMATION_SCHEMA で正確に確認（SHOW TABLES LIKE の _ ワイルドカード問題を回避）
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ));
        $exists = (bool)$found;
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
        // 国フィルタ状態をキャッシュキーに反映
        $filter_suffix = $this->ga4->has_country_filter() ? '_jp' : '';

        // インスタンスキャッシュ（同一リクエスト内の重複計算を防止）
        $cache_key = "{$year_month}_{$user_id}{$filter_suffix}";
        if (isset($this->effective_cv_cache[$cache_key])) {
            return $this->effective_cv_cache[$cache_key];
        }

        // トランジェントキャッシュ（リクエスト間で共有、2時間）
        $transient_key = "gcrev_effcv_{$user_id}_{$year_month}{$filter_suffix}";
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
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND `year_month` = %s AND status != 0",
            $user_id, $year_month
        ));

        if ($review_count === 0) {
            return null; // レビュー未実施
        }

        // status=1（有効CV）の行を取得
        $valid_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT date_hour_minute FROM {$table} WHERE user_id = %d AND `year_month` = %s AND status = 1",
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

    /**
     * source_medium 文字列 → 日本語チャネル名にマッピング
     * cv_review の source_medium は "google / organic" 形式
     */
    private function map_source_medium_to_channel(string $source_medium): string {
        $parts  = array_map( 'trim', explode( '/', $source_medium, 2 ) );
        $source = strtolower( $parts[0] ?? '' );
        $medium = strtolower( $parts[1] ?? '' );

        if ( $source === '(direct)' && ( $medium === '(none)' || $medium === '(not set)' || $medium === '' ) ) {
            return '直接';
        }
        if ( $medium === 'organic' ) {
            return '検索（自然）';
        }
        if ( in_array( $medium, [ 'cpc', 'ppc', 'paid', 'paidsearch' ], true ) ) {
            return '検索（広告）';
        }
        if ( in_array( $medium, [ 'social', 'social-network', 'social_network' ], true ) ) {
            return 'SNS';
        }
        if ( $medium === 'referral' ) {
            return '他サイト';
        }
        if ( $medium === 'email' ) {
            return 'メール';
        }
        if ( in_array( $medium, [ 'display', 'cpm', 'banner' ], true ) ) {
            return 'ディスプレイ広告';
        }
        if ( $source === '(not set)' || ( $source === '' && $medium === '' ) ) {
            return '（不明）';
        }
        // 地図検索
        if ( strpos( $source, 'google' ) !== false && strpos( $medium, 'maps' ) !== false ) {
            return '地図検索';
        }

        return trim( $source_medium );
    }

    /**
     * 確定CVドリルダウン内訳を取得
     *
     * 優先順:
     *   1) cv_review (status=1) に直接データがあればそれを使用
     *   2) なければ GA4 ディメンションデータを確定CV合計で按分（再配分）
     *
     * @return array  [ items => [...], metric_key => string ]
     */
    private function get_confirmed_cv_drilldown(
        int    $user_id,
        string $year_month,
        string $type,
        array  $region_ja,
        string $ga4_id,
        string $start,
        string $end,
        string $site_url = ''
    ): array {
        global $wpdb;
        $review_table = $wpdb->prefix . 'gcrev_cv_review';
        $items = [];

        // ── 確定CV合計を取得（effective CV = ダッシュボードのゴール数と同一値）──
        $eff = $this->get_effective_cv_monthly( $year_month, $user_id );
        $confirmed_total = (int) ( $eff['total'] ?? 0 );
        if ( $confirmed_total === 0 ) {
            return [ 'items' => [], 'metric_key' => 'conversions' ];
        }

        // ── cv_review にレビュー済みデータがあるか判定 ──
        $has_review = false;
        $review_count = 0;
        if ( $this->table_exists( $review_table ) ) {
            $review_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$review_table}
                 WHERE user_id = %d AND year_month = %s AND status = 1",
                $user_id, $year_month
            ) );
            $has_review = ( $review_count > 0 );
        }

        // 除外パターン（入口ページ用）
        $page_exclude = [ '/thanks', '/confirm', '/complete', '/finish', '/done' ];

        switch ( $type ) {

            // ══════════════════════════════════════
            // 地域（region）
            // cv_review は country のみのため、常に GA4 地域データで按分
            // ══════════════════════════════════════
            case 'region':
                $raw = $this->ga4->fetch_region_details( $ga4_id, $start, $end );
                $total_ga4_cv = 0;
                foreach ( $raw as $r ) {
                    $total_ga4_cv += (int) ( $r['conversions'] ?? 0 );
                }
                if ( $total_ga4_cv <= 0 ) {
                    // GA4 にもコンバージョンがない場合は sessions で按分
                    $total_ga4_cv = 0;
                    foreach ( $raw as $r ) { $total_ga4_cv += (int) ( $r['sessions'] ?? 0 ); }
                    $weight_key = 'sessions';
                } else {
                    $weight_key = 'conversions';
                }
                if ( $total_ga4_cv <= 0 ) break;

                $items = $this->reallocate_to_items( $raw, 'region', $weight_key, $total_ga4_cv, $confirmed_total, $region_ja );
                break;

            // ══════════════════════════════════════
            // 入口ページ（page）
            // ══════════════════════════════════════
            case 'page':
                if ( $has_review ) {
                    // ── cv_review 直接集計 ──
                    $exclude_sql  = '';
                    $prepare_args = [ $user_id, $year_month ];
                    foreach ( $page_exclude as $pat ) {
                        $exclude_sql    .= ' AND page_path NOT LIKE %s';
                        $prepare_args[] = '%' . $wpdb->esc_like( $pat ) . '%';
                    }
                    $rows = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT page_path, SUM(event_count) AS cv_count
                             FROM {$review_table}
                             WHERE user_id = %d AND year_month = %s AND status = 1
                             {$exclude_sql}
                             GROUP BY page_path
                             HAVING cv_count > 0
                             ORDER BY cv_count DESC
                             LIMIT 10",
                            ...$prepare_args
                        ),
                        ARRAY_A
                    );
                    // サイトURL正規化（末尾スラッシュ除去）
                    $base_url = $site_url ? rtrim( $site_url, '/' ) : '';
                    foreach ( $rows as $row ) {
                        $path = $row['page_path'] ?: '';
                        if ( $path === '' || $path === '(not set)' ) {
                            $display = '（不明なページ）';
                        } elseif ( $base_url !== '' ) {
                            $display = $base_url . $path;
                        } else {
                            $display = $path;
                        }
                        if ( mb_strlen( $display ) > 60 ) {
                            $display = mb_substr( $display, 0, 60 ) . '…';
                        }
                        $items[] = [ 'label' => $display, 'value' => (int) $row['cv_count'] ];
                    }
                } else {
                    // ── GA4 pagePath × keyEvents を確定CV合計で按分 ──
                    $client   = new BetaAnalyticsDataClient(['credentials' => $this->service_account_path]);
                    $property = 'properties/' . $ga4_id;
                    $ga4_rows = $this->fetch_cv_by_dimension( $client, $property, 'pagePath', $start, $end );

                    // 除外パターンでフィルタ
                    $ga4_rows = array_filter( $ga4_rows, function( $row ) use ( $page_exclude ) {
                        $path = strtolower( $row['dimension'] ?? '' );
                        foreach ( $page_exclude as $pat ) {
                            if ( strpos( $path, $pat ) !== false ) return false;
                        }
                        return true;
                    });
                    $ga4_rows = array_values( $ga4_rows );

                    // keyEvents で降順ソートし上位30件を按分対象に
                    usort( $ga4_rows, function( $a, $b ) {
                        return ( $b['keyEvents'] ?? 0 ) - ( $a['keyEvents'] ?? 0 );
                    });
                    $ga4_rows = array_slice( $ga4_rows, 0, 30 );

                    $total_ke = 0;
                    foreach ( $ga4_rows as $r ) { $total_ke += (int) ( $r['keyEvents'] ?? 0 ); }

                    if ( $total_ke > 0 ) {
                        $items = $this->reallocate_to_items( $ga4_rows, 'dimension', 'keyEvents', $total_ke, $confirmed_total, null, 30, $site_url );
                    } else {
                        // keyEvents がすべて0の場合は sessions で按分
                        $total_sess = 0;
                        foreach ( $ga4_rows as $r ) { $total_sess += (int) ( $r['sessions'] ?? 0 ); }
                        if ( $total_sess > 0 ) {
                            $items = $this->reallocate_to_items( $ga4_rows, 'dimension', 'sessions', $total_sess, $confirmed_total, null, 30, $site_url );
                        }
                    }
                }
                break;

            // ══════════════════════════════════════
            // 流入元（source）
            // ══════════════════════════════════════
            case 'source':
                if ( $has_review ) {
                    // ── cv_review 直接集計 ──
                    $rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT source_medium, SUM(event_count) AS cv_count
                         FROM {$review_table}
                         WHERE user_id = %d AND year_month = %s AND status = 1
                         GROUP BY source_medium
                         ORDER BY cv_count DESC",
                        $user_id, $year_month
                    ), ARRAY_A );

                    $channel_totals = [];
                    foreach ( $rows as $row ) {
                        $ch = $this->map_source_medium_to_channel( $row['source_medium'] ?? '' );
                        $channel_totals[ $ch ] = ( $channel_totals[ $ch ] ?? 0 ) + (int) $row['cv_count'];
                    }
                    arsort( $channel_totals );
                    foreach ( array_slice( $channel_totals, 0, 10, true ) as $ch => $cnt ) {
                        if ( $cnt > 0 ) {
                            $items[] = [ 'label' => $ch, 'value' => $cnt ];
                        }
                    }
                } else {
                    // ── GA4 チャネルグループ × conversions を確定CV合計で按分 ──
                    $raw      = $this->ga4->fetch_source_data_from_ga4( $ga4_id, $start, $end );
                    $channels = $raw['channels'] ?? [];

                    $total_ga4_cv = 0;
                    foreach ( $channels as $ch ) {
                        $total_ga4_cv += (int) ( $ch['conversions'] ?? 0 );
                    }
                    if ( $total_ga4_cv <= 0 ) {
                        // conversions がない場合は sessions で按分
                        foreach ( $channels as $ch ) { $total_ga4_cv += (int) ( $ch['sessions'] ?? 0 ); }
                        $weight_key = 'sessions';
                    } else {
                        $weight_key = 'conversions';
                    }
                    if ( $total_ga4_cv > 0 ) {
                        $items = $this->reallocate_to_items( $channels, 'channel', $weight_key, $total_ga4_cv, $confirmed_total, null );
                    }
                }
                break;
        }

        return [ 'items' => $items, 'metric_key' => 'conversions' ];
    }

    /**
     * 最大剰余法で confirmed_total を GA4 ディメンション配列に按分し、
     * ドリルダウン用の items 配列を返すユーティリティ
     *
     * @param array       $rows           GA4 行データ
     * @param string      $label_key      ラベルに使うキー名（'region','dimension','channel'）
     * @param string      $weight_key     重みに使うキー名（'conversions','keyEvents','sessions'）
     * @param int         $weight_total   重みの合計値
     * @param int         $confirmed_total 按分する確定CV合計
     * @param array|null  $label_map      ラベル変換マップ（region_ja 等）
     * @param int         $max_rows       按分対象の最大行数
     * @return array      items 配列 [['label'=>..., 'value'=>...], ...]
     */
    private function reallocate_to_items(
        array  $rows,
        string $label_key,
        string $weight_key,
        int    $weight_total,
        int    $confirmed_total,
        ?array $label_map = null,
        int    $max_rows  = 50,
        string $site_url  = ''
    ): array {
        if ( $weight_total <= 0 || $confirmed_total <= 0 ) return [];

        // 日本語チャネルマップ（source 用）
        $channel_ja = [
            'Organic Search' => '検索（自然）', 'Direct' => '直接',
            'Referral' => '他サイト', 'Organic Social' => 'SNS',
            'Paid Search' => '検索（広告）', 'Paid Social' => 'SNS広告',
            'Email' => 'メール', 'Display' => 'ディスプレイ広告',
            'Organic Maps' => '地図検索', 'Organic Shopping' => 'ショッピング',
            'Unassigned' => '不明', 'Cross-network' => 'クロスネットワーク',
            'Affiliates' => 'アフィリエイト', '(other)' => 'その他',
        ];

        // 比率マップ構築
        $ratio_map = [];
        foreach ( array_slice( $rows, 0, $max_rows ) as $r ) {
            $w = (int) ( $r[ $weight_key ] ?? 0 );
            if ( $w <= 0 ) continue;

            $raw_label = $r[ $label_key ] ?? '';
            if ( $raw_label === '' || $raw_label === '(not set)' ) {
                $name = '（不明）';
            } elseif ( $label_key === 'region' && $label_map ) {
                $name = $label_map[ $raw_label ] ?? $raw_label;
            } elseif ( $label_key === 'channel' ) {
                $name = $channel_ja[ $raw_label ] ?? $raw_label;
            } elseif ( $label_key === 'dimension' ) {
                $base_url = $site_url ? rtrim( $site_url, '/' ) : '';
                if ( $base_url !== '' ) {
                    $name = $base_url . $raw_label;
                } else {
                    $name = $raw_label;
                }
                if ( mb_strlen( $name ) > 60 ) {
                    $name = mb_substr( $name, 0, 60 ) . '…';
                }
            } else {
                $name = $raw_label;
            }

            $ratio_map[ $name ] = ( $ratio_map[ $name ] ?? 0 ) + ( $w / $weight_total );
        }

        if ( empty( $ratio_map ) ) return [];

        // 最大剰余法で按分
        $floor_total = 0;
        $remainders  = [];
        foreach ( $ratio_map as $name => $ratio ) {
            $exact = $confirmed_total * $ratio;
            $floor = (int) floor( $exact );
            $floor_total += $floor;
            $remainders[ $name ] = [ 'floor' => $floor, 'rem' => $exact - $floor ];
        }
        $leftover = $confirmed_total - $floor_total;
        uasort( $remainders, function( $a, $b ) { return $b['rem'] <=> $a['rem']; } );
        foreach ( $remainders as &$rd ) {
            $rd['final'] = $rd['floor'];
            if ( $leftover > 0 ) { $rd['final']++; $leftover--; }
        }
        unset( $rd );

        // items 構築（0 除外、降順、上位10件）
        $temp = [];
        foreach ( $remainders as $name => $data ) {
            if ( $data['final'] > 0 ) {
                $temp[] = [ 'label' => $name, 'value' => $data['final'] ];
            }
        }
        usort( $temp, function( $a, $b ) { return $b['value'] - $a['value']; } );
        return array_slice( $temp, 0, 10 );
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

        // 最大20件バリデーション
        if (count($routes) > 20) {
            return new WP_REST_Response(['success'=>false,'message'=>'ゴールは最大20件まで設定できます'], 400);
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

        if (!$this->table_exists($table)) {
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
     * REST: GA4キーイベント一覧取得（設定画面サジェスト用）
     *
     * 3段階で候補を収集し突合:
     *   1) Admin API → キーイベント定義一覧（確実）
     *   2) Data API keyEvents → キーイベント発火実績（当月〜過去90日）
     *   3) Data API eventCount → 全イベント一覧（フォールバック）
     *
     * フォールバックキャッシュ（wp_options）で取得失敗時も空にしない。
     */
    public function rest_get_ga4_key_events(WP_REST_Request $request): WP_REST_Response {
        $user_id = (int)($request->get_param('user_id') ?? get_current_user_id());
        if (!current_user_can('manage_options') && $user_id !== get_current_user_id()) {
            $user_id = get_current_user_id();
        }

        // --- キャッシュキー（v3: Throwable catch + Admin API タイムアウト修正版） ---
        $cache_key    = "gcrev_ga4_kevt_v3_{$user_id}";
        $fallback_key = "gcrev_ga4_kevt_fb3_{$user_id}";
        $ts_key       = "gcrev_ga4_kevt_ts3_{$user_id}";

        // --- Transient キャッシュ確認（6h） ---
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached) && !empty($cached)) {
            $first = reset($cached);
            if (is_array($first) && isset($first['name'])) {
                return new WP_REST_Response([
                    'success'    => true,
                    'source'     => 'cache',
                    'events'     => $cached,
                    'fetched_at' => get_option($ts_key, ''),
                ], 200);
            }
        }

        try {
            $config      = $this->config->get_user_config($user_id);
            $property_id = $config['ga4_id'];

            // 日付範囲: 過去90日（月初でも候補が出るように）
            $today = new \DateTime('now', wp_timezone());
            $end   = $today->format('Y-m-d');
            $start = (clone $today)->modify('-90 days')->format('Y-m-d');

            // ========================================
            // 1) Admin API: キーイベント定義一覧
            //    GA4プロパティに登録された全キーイベント（発火有無に関係なく）
            // ========================================
            $key_event_defs = [];
            try {
                $defs = $this->ga4->fetch_ga4_key_event_definitions($property_id);
                foreach ($defs as $d) {
                    $key_event_defs[$d['event_name']] = true;
                }
                error_log("[GCREV] Admin API key event defs OK: " . count($key_event_defs) . " events");
            } catch (\Throwable $e) {
                error_log("[GCREV] Admin API key event defs FAILED (non-fatal): " . get_class($e) . ": " . $e->getMessage());
            }

            // ========================================
            // 2) Data API: keyEvents メトリクス（過去90日）
            //    実際にキーイベントとして発火したもの + 件数
            // ========================================
            $key_event_counts = [];
            try {
                $key_event_counts = $this->ga4->fetch_ga4_key_events($property_id, $start, $end);
                error_log("[GCREV] Data API keyEvents OK: " . count($key_event_counts) . " events");
            } catch (\Throwable $e) {
                error_log("[GCREV] Data API keyEvents FAILED (non-fatal): " . get_class($e) . ": " . $e->getMessage());
            }

            // ========================================
            // 3) Data API: eventCount（全イベント一覧 + 発火回数）
            //    Admin API が使えない場合でも候補を提供するために常に取得
            // ========================================
            $all_event_counts = [];
            try {
                $all_event_counts = $this->ga4->fetch_ga4_all_event_names($property_id, $start, $end);
                error_log("[GCREV] Data API eventCount OK: " . count($all_event_counts) . " events");
            } catch (\Throwable $e) {
                error_log("[GCREV] Data API eventCount FAILED (non-fatal): " . get_class($e) . ": " . $e->getMessage());
            }

            // ========================================
            // 4) 突合・マージ
            //    キーイベント判定の優先度:
            //      a) Admin API に定義あり → 確実にキーイベント
            //      b) keyEvents メトリクスで値あり → キーイベント
            //      c) どちらにもない → 通常イベント
            // ========================================
            $seen   = [];
            $merged = [];

            // キーイベント名のセット（Admin API + keyEvents 結果を統合）
            $is_key = $key_event_defs; // Admin API 分
            foreach ($key_event_counts as $name => $_) {
                $is_key[$name] = true;  // keyEvents に出る = キーイベント
            }

            // 4a) キーイベント（Admin API 定義 + keyEvents 発火）を先に追加
            foreach ($is_key as $name => $_) {
                $count = 0;
                if (isset($key_event_counts[$name])) {
                    $count = (int) $key_event_counts[$name];
                } elseif (isset($all_event_counts[$name])) {
                    $count = (int) $all_event_counts[$name];
                }
                $merged[] = [
                    'name'         => $name,
                    'is_key_event' => true,
                    'count'        => $count,
                ];
                $seen[$name] = true;
            }

            // 4b) その他の全イベント（キーイベント以外）
            foreach ($all_event_counts as $name => $count) {
                if (!isset($seen[$name])) {
                    $merged[] = [
                        'name'         => $name,
                        'is_key_event' => false,
                        'count'        => (int) $count,
                    ];
                    $seen[$name] = true;
                }
            }

            // ソート: is_key_event=true を先頭、その中は count 降順
            usort($merged, function ($a, $b) {
                if ($a['is_key_event'] !== $b['is_key_event']) {
                    return $b['is_key_event'] <=> $a['is_key_event'];
                }
                return $b['count'] <=> $a['count'];
            });

            $now = (new \DateTime('now', wp_timezone()))->format('c');
            set_transient($cache_key, $merged, 6 * HOUR_IN_SECONDS);
            update_option($fallback_key, $merged, false);
            update_option($ts_key, $now, false);

            return new WP_REST_Response([
                'success'    => true,
                'source'     => 'live',
                'events'     => $merged,
                'fetched_at' => $now,
            ], 200);

        } catch (\Throwable $e) {
            error_log("[GCREV] rest_get_ga4_key_events ERROR: " . get_class($e) . ": " . $e->getMessage());

            // フォールバック: 最後に成功したキャッシュを返す
            $fallback = get_option($fallback_key, []);
            if (!empty($fallback) && is_array($fallback)) {
                // 旧形式チェック
                $first = reset($fallback);
                if (is_array($first) && isset($first['name'])) {
                    return new WP_REST_Response([
                        'success'    => true,
                        'source'     => 'stale-cache',
                        'events'     => $fallback,
                        'fetched_at' => get_option($ts_key, ''),
                    ], 200);
                }
            }

            return new WP_REST_Response([
                'success' => false,
                'message' => 'GA4取得エラー',
                'events'  => [],
            ], 500);
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
        if ($this->table_exists($table)) {
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
        if (!$this->table_exists($table)) {
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

        if (!$this->table_exists($table)) {
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
     * レポート保存後にKPIスナップショットを保存する
     *
     * fetch_dashboard_data_internal() の結果（$prev_data）から
     * KPIカード＋集客分析カードに必要なデータを抽出して保存する。
     *
     * @param int    $post_id    保存されたレポートのpost ID
     * @param array  $prev_data  fetch_dashboard_data_internal() の結果
     * @param int    $user_id    ユーザーID
     * @param string|null $year_month 対象年月 (Y-m)
     */
    private function save_kpi_snapshot_for_report( int $post_id, array $prev_data, int $user_id, ?string $year_month ): void {
        try {
            $snapshot = [
                'pageViews'      => $prev_data['pageViews'] ?? 0,
                'sessions'       => $prev_data['sessions'] ?? 0,
                'users'          => $prev_data['users'] ?? 0,
                'newUsers'       => $prev_data['newUsers'] ?? 0,
                'returningUsers' => $prev_data['returningUsers'] ?? 0,
                'avgDuration'    => $prev_data['avgDuration'] ?? 0,
                'conversions'    => $prev_data['conversions'] ?? '0',
                'trends'         => $prev_data['trends'] ?? [],
                'daily'          => $prev_data['daily'] ?? [],
                'devices'        => $prev_data['devices'] ?? [],
                'medium'         => $prev_data['medium'] ?? [],
                'geo_region'     => $prev_data['geo_region'] ?? [],
                'age'            => $prev_data['age'] ?? [],
                'pages'          => $prev_data['pages'] ?? [],
                'keywords'       => $prev_data['keywords'] ?? [],
            ];

            // 実質CVがある場合は注入
            if ( $year_month ) {
                $eff = $this->get_effective_cv_monthly( $year_month, $user_id );
                $snapshot['conversions'] = (string) $eff['total'];
                $snapshot['cv_source']   = $eff['source'];
                $snapshot['effective_cv'] = $eff;

                // CV 増減
                $tz = wp_timezone();
                $comp_ym = ( new \DateTimeImmutable( $year_month . '-01', $tz ) )
                    ->modify( 'first day of last month' )
                    ->format( 'Y-m' );
                $comp_eff = $this->get_effective_cv_monthly( $comp_ym, $user_id );
                $cur = (float) $eff['total'];
                $prv = (float) $comp_eff['total'];
                if ( abs( $prv ) < 0.0001 ) {
                    $cv_trend = ( abs( $cur ) < 0.0001 )
                        ? [ 'value' => 0, 'text' => '±0.0%', 'color' => '#666' ]
                        : [ 'value' => 100, 'text' => '+∞', 'color' => '#3b82f6' ];
                } else {
                    $val   = ( ( $cur - $prv ) / $prv ) * 100.0;
                    $text  = ( $val > 0 ? '+' : '' ) . number_format( $val, 1 ) . '%';
                    $color = $val > 0 ? '#3b82f6' : ( $val < 0 ? '#ef4444' : '#666' );
                    $cv_trend = [ 'value' => $val, 'text' => $text, 'color' => $color ];
                }
                $snapshot['trends']['conversions'] = $cv_trend;
            }

            // スナップショットバージョン・保存日時
            $snapshot['snapshot_version']  = 1;
            $snapshot['snapshot_saved_at'] = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );

            $this->repo->save_kpi_snapshot( $post_id, $snapshot );
        } catch ( \Throwable $e ) {
            error_log( "[GCREV] save_kpi_snapshot_for_report error: " . $e->getMessage() );
        }
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

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

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
            $filter_sfx = $this->ga4->has_country_filter() ? '_jp' : '';
            $cache_key = "gcrev_cv_analysis_{$user_id}_{$period}_" . md5("{$start}_{$end}") . $filter_sfx;
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
        } finally {
            $this->restore_country_filter( $filter_set );
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

            // 国フィルタ適用
            $country_filter = $this->ga4->get_country_filter();
            if ( $country_filter !== null ) {
                $request->setDimensionFilter( $country_filter );
            }

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
        $user_id = get_current_user_id();
        if (!$user_id) {
            return new \WP_REST_Response(['success' => false, 'message' => 'ログインが必要です'], 401);
        }

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

        try {
            $metric = $request->get_param('metric');
            $view   = $request->get_param('view') ?? 'monthly';

            if ($view === 'daily') {
                $result = $this->get_daily_metric_trend($user_id, $metric, 30);
            } else {
                $result = $this->get_monthly_metric_trend($user_id, $metric, 12);
            }

            return new \WP_REST_Response($result, 200);

        } catch (\Exception $e) {
            error_log("[GCREV][Trend] Error: " . $e->getMessage());
            return new \WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        } finally {
            $this->restore_country_filter( $filter_set );
        }
    }

    /**
     * REST callback: /gcrev/v1/dashboard/drilldown?month=YYYY-MM&type=region|page|source
     * 指定月の内訳データ（TOP10）を返す
     */
    public function rest_get_dashboard_drilldown(\WP_REST_Request $request): \WP_REST_Response {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new \WP_REST_Response(['success' => false, 'message' => 'ログインが必要です'], 401);
        }

        // 海外アクセス除外フィルタ
        $filter_set = $this->maybe_set_country_filter( $user_id );

        try {
            $month  = $request->get_param('month');    // "2026-01" or "2026-01-15"
            $type   = $request->get_param('type');     // "region"|"page"|"source"
            $metric = $request->get_param('metric') ?? 'sessions';

            $config  = $this->config->get_user_config( $user_id );
            $ga4_id  = $config['ga4_id'] ?? '';
            if ( empty( $ga4_id ) ) {
                return new \WP_REST_Response(['success' => false, 'message' => 'GA4 未設定'], 400);
            }

            // 日付範囲の決定: YYYY-MM-DD なら1日、YYYY-MM なら月全体
            $is_daily = (strlen($month) === 10); // "2026-01-15" = 10文字
            if ($is_daily) {
                $start = $month;
                $end   = $month;
            } else {
                $start = $month . '-01';
                $end   = date('Y-m-t', strtotime( $start ));
            }

            // MEO は region のみ対応（page/source は無意味）
            if ( $metric === 'meo' && $type !== 'region' ) {
                return new \WP_REST_Response([
                    'success'      => true,
                    'month'        => $month,
                    'type'         => $type,
                    'metric'       => $metric,
                    'metric_label' => '',
                    'items'        => [],
                ], 200);
            }

            // キャッシュ（24時間）— metric ごとに分離
            // v5: ページラベル絶対URL化に伴い旧キャッシュ無効化
            $filter_sfx = $this->ga4->has_country_filter() ? '_jp' : '';
            $cache_key = "gcrev_dd6_{$user_id}_{$month}_{$type}_{$metric}{$filter_sfx}";
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
                'sessions'    => 'セッション数',
                'users'       => 'ユーザー数',
                'pageViews'   => '表示回数',
                'cv'          => 'ゴール数',
                'conversions' => 'ゴール数',
                'keyEvents'   => 'ゴール数',
                'meo'         => 'セッション数',
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

            // ── ゴール数（CV）は確定CVデータ（cv_review status=1）を使用 ──
            $site_url = $config['gsc_url'] ?? '';
            if ( $metric === 'cv' ) {
                $cv_result = $this->get_confirmed_cv_drilldown(
                    $user_id, $month, $type, $region_ja, $ga4_id, $start, $end, $site_url
                );
                $items  = $cv_result['items'];
                $metric = $cv_result['metric_key'];
            } else {
                // ── 訪問数 / MEO は従来どおりGA4データを使用 ──
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
                                'value' => (int) ( $r['sessions'] ?? 0 ),
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
                                'value' => (int) ( $ch['sessions'] ?? 0 ),
                            ];
                        }
                        break;
                }
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

            // items がある場合のみキャッシュ（空結果はキャッシュしない → 再試行可能に）
            if ( ! empty( $items ) ) {
                set_transient( $cache_key, $result, 86400 );
            }
            return new \WP_REST_Response( $result, 200 );

        } catch ( \Exception $e ) {
            error_log( '[GCREV][Drilldown] Error: ' . $e->getMessage() );
            return new \WP_REST_Response(['success' => false, 'message' => $e->getMessage()], 500);
        } finally {
            $this->restore_country_filter( $filter_set );
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
        $filter_sfx = $this->ga4->has_country_filter() ? '_jp' : '';
        $cache_key = "gcrev_trend_{$user_id}_{$metric}_{$current_month}{$filter_sfx}";
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
                // 12ヶ月分を一括取得して月ごとに集計（API呼び出し4回で済む）
                $range_start = $labels[0] . '-01';
                $range_end   = date('Y-m-t', strtotime($labels[count($labels) - 1] . '-01'));
                try {
                    $daily_rows = $this->gbp_fetch_daily_metrics($access_token, $location_id, $range_start, $range_end);
                    // 月別に集計
                    $monthly_totals = [];
                    foreach ($daily_rows as $row) {
                        $ym = substr($row['date'], 0, 7); // YYYY-MM
                        if (!isset($monthly_totals[$ym])) {
                            $monthly_totals[$ym] = 0;
                        }
                        $monthly_totals[$ym] += (int)($row['search_impressions'] ?? 0) + (int)($row['map_impressions'] ?? 0);
                    }
                    foreach ($labels as $ym) {
                        $values[] = $monthly_totals[$ym] ?? 0;
                    }
                } catch (\Exception $e) {
                    file_put_contents('/tmp/gcrev_gbp_debug.log',
                        date('Y-m-d H:i:s') . " [Trend] MEO monthly error: " . $e->getMessage() . "\n",
                        FILE_APPEND
                    );
                    $values = array_fill(0, $months, 0);
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

    /**
     * 直近N日の日別メトリクストレンドを返す
     *
     * @param int    $user_id
     * @param string $metric  'sessions' | 'cv' | 'meo'
     * @param int    $days    日数（デフォルト30）
     * @return array {success, metric, labels, values, view}
     */
    public function get_daily_metric_trend(int $user_id, string $metric, int $days = 30): array {

        $today_str = date('Y-m-d');
        $filter_sfx = (isset($this->ga4) && $this->ga4->has_country_filter()) ? '_jp' : '';
        $cache_key = "gcrev_trend_daily_{$user_id}_{$metric}_{$today_str}{$filter_sfx}";
        $cached = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $tz = wp_timezone();
        $end   = new \DateTimeImmutable('yesterday', $tz);
        $start = $end->sub(new \DateInterval('P' . ($days - 1) . 'D'));

        $start_str = $start->format('Y-m-d');
        $end_str   = $end->format('Y-m-d');

        // 日付ラベル配列（YYYY-MM-DD）
        $labels = [];
        $dt = $start;
        while ($dt <= $end) {
            $labels[] = $dt->format('Y-m-d');
            $dt = $dt->add(new \DateInterval('P1D'));
        }

        $values = [];

        switch ($metric) {
            case 'sessions':
                $config = $this->config->get_user_config($user_id);
                $ga4_id = $config['ga4_id'] ?? '';
                if (empty($ga4_id)) {
                    $values = array_fill(0, $days, 0);
                    break;
                }
                try {
                    $daily = $this->ga4->fetch_ga4_daily_series($ga4_id, $start_str, $end_str);
                    $sess_data = $daily['sessions'] ?? [];
                    $sess_labels = $sess_data['labels'] ?? [];
                    $sess_values = $sess_data['values'] ?? [];
                    // YYYYMMDD → index map
                    $sess_map = [];
                    foreach ($sess_labels as $i => $lbl) {
                        $sess_map[$lbl] = $sess_values[$i] ?? 0;
                    }
                    foreach ($labels as $d) {
                        $key = str_replace('-', '', $d);
                        $values[] = (int)($sess_map[$key] ?? 0);
                    }
                } catch (\Exception $e) {
                    error_log("[GCREV][DailyTrend] sessions error: " . $e->getMessage());
                    $values = array_fill(0, $days, 0);
                }
                break;

            case 'cv':
                $config = $this->config->get_user_config($user_id);
                $ga4_id = $config['ga4_id'] ?? '';
                if (empty($ga4_id)) {
                    $values = array_fill(0, $days, 0);
                    break;
                }
                try {
                    $daily = $this->ga4->fetch_ga4_daily_series($ga4_id, $start_str, $end_str);
                    $conv_data = $daily['conversions'] ?? [];
                    $conv_labels = $conv_data['labels'] ?? [];
                    $conv_values = $conv_data['values'] ?? [];
                    $conv_map = [];
                    foreach ($conv_labels as $i => $lbl) {
                        $conv_map[$lbl] = $conv_values[$i] ?? 0;
                    }
                    foreach ($labels as $d) {
                        $key = str_replace('-', '', $d);
                        $values[] = (int)($conv_map[$key] ?? 0);
                    }
                } catch (\Exception $e) {
                    error_log("[GCREV][DailyTrend] cv error: " . $e->getMessage());
                    $values = array_fill(0, $days, 0);
                }
                break;

            case 'meo':
                $values = array_fill(0, $days, 0);
                $location_id = get_user_meta($user_id, '_gcrev_gbp_location_id', true);
                if (!empty($location_id) && strpos($location_id, 'pending_') !== 0) {
                    $access_token = $this->gbp_get_access_token($user_id);
                    if (!empty($access_token)) {
                        try {
                            $daily = $this->gbp_fetch_daily_metrics($access_token, $location_id, $start_str, $end_str);
                            $daily_map = [];
                            foreach ($daily as $d) {
                                $daily_map[$d['date']] = ($d['search_impressions'] ?? 0) + ($d['map_impressions'] ?? 0);
                            }
                            $values = [];
                            foreach ($labels as $d) {
                                $values[] = (int)($daily_map[$d] ?? 0);
                            }
                        } catch (\Exception $e) {
                            error_log("[GCREV][DailyTrend] meo error: " . $e->getMessage());
                            $values = array_fill(0, $days, 0);
                        }
                    }
                }
                break;

            default:
                $values = array_fill(0, $days, 0);
        }

        $result = [
            'success' => true,
            'metric'  => $metric,
            'labels'  => $labels,
            'values'  => $values,
            'view'    => 'daily',
        ];

        // TTL: 6時間
        set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);

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
                'message' => 'ゴールが設定されていません。ゴールの数え方設定ページから設定してください。',
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
        $table_error = $this->ensure_cv_review_table($table);
        if ($table_error !== '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'テーブル作成に失敗しました: ' . $table_error,
            ], 500);
        }

        $now   = current_time('mysql');

        // 既存行チェック
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND `year_month` = %s AND row_hash = %s",
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
            // INSERT — $wpdb->insert() は自動でバッククォート付与
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

        // CV変更後はダッシュボード系キャッシュを無効化
        delete_transient("gcrev_effcv_{$user_id}_{$month}");
        delete_transient("gcrev_dash_{$user_id}_previousMonth");
        delete_transient("gcrev_dash_{$user_id}_twoMonthsAgo");
        delete_transient("gcrev_trend_{$user_id}_cv_" . date('Y-m'));

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
        $table_error = $this->ensure_cv_review_table($table);
        if ($table_error !== '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'テーブル作成に失敗しました: ' . $table_error,
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
                "SELECT id FROM {$table} WHERE user_id = %d AND `year_month` = %s AND row_hash = %s",
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
            delete_transient("gcrev_trend_{$user_id}_cv_" . date('Y-m'));
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
     * gcrev_cv_review テーブルが存在しなければ作成する
     *
     * @return string エラーメッセージ（空文字列=成功）
     */
    private function ensure_cv_review_table(string $table = ''): string {
        global $wpdb;
        if (!$table) {
            $table = $wpdb->prefix . 'gcrev_cv_review';
        }

        // INFORMATION_SCHEMA で確認（SHOW TABLES LIKE + prepare の _ ワイルドカード問題を回避）
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ));
        if ($found) {
            return '';
        }

        error_log('[GCREV] ensure_cv_review_table: creating ' . $table);

        $charset_collate = $wpdb->get_charset_collate();
        $sql_body = "(
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            `year_month` VARCHAR(7) NOT NULL,
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
            PRIMARY KEY  (id),
            UNIQUE KEY user_month_hash (user_id, `year_month`, row_hash),
            KEY user_month (user_id, `year_month`),
            KEY status (status)
        ) {$charset_collate};";

        // 1) dbDelta() で試行
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} {$sql_body}");

        // 再確認
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ));
        if ($found) {
            error_log('[GCREV] ensure_cv_review_table: created via dbDelta');
            // table_exists キャッシュを更新
            $this->table_exists_cache[$table] = true;
            return '';
        }

        // 2) dbDelta 失敗 → 直接 CREATE TABLE で再試行
        error_log('[GCREV] ensure_cv_review_table: dbDelta failed, trying direct CREATE TABLE');
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} {$sql_body}");
        $create_error = $wpdb->last_error;

        // 最終確認
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $table
        ));
        if ($found) {
            error_log('[GCREV] ensure_cv_review_table: created via direct CREATE TABLE');
            // table_exists キャッシュを更新
            $this->table_exists_cache[$table] = true;
            return '';
        }

        $error = $create_error ?: 'テーブル作成後も INFORMATION_SCHEMA に見つかりません';
        error_log('[GCREV] ensure_cv_review_table FAILED: ' . $error);
        return $error;
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

        // テーブル存在チェック（INFORMATION_SCHEMA で正確に確認）
        if (!$this->table_exists($table)) {
            // テーブル未作成: status=0, memo='' で返す
            return array_map(function($row) {
                $row['status'] = 0;
                $row['memo']   = '';
                return $row;
            }, $rows);
        }

        // このユーザー・月のレビュー済みデータを全取得
        $saved = $wpdb->get_results($wpdb->prepare(
            "SELECT row_hash, status, memo FROM {$table} WHERE user_id = %d AND `year_month` = %s",
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

    // =========================================================
    // 順位トラッキング — REST コールバック
    // =========================================================

    /**
     * GET /rank-tracker/rankings
     * ログインユーザーの最新キーワード順位一覧（前回比 + 直近7日分含む）
     */
    public function rest_get_rank_tracker_rankings( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id = get_current_user_id();

        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_rank_results';

        $tz  = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );

        // 直近6週分の ISO week 配列を生成（古い順）
        $weeks = [];
        for ( $i = 5; $i >= 0; $i-- ) {
            $dt = $now->modify( "-{$i} weeks" );
            $weeks[] = $dt->format( 'o-\WW' );  // e.g. '2025-W10'
        }

        // 週ラベル: gcrev_week_label() で「3/1週」形式
        $week_labels = array_map( 'gcrev_week_label', $weeks );

        // 有効キーワード取得（検索ボリューム・上がりやすさスコア含む）
        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword, target_domain, location_code, memo,
                    search_volume, keyword_difficulty, competition,
                    opportunity_score, opportunity_reasons
             FROM {$kw_table}
             WHERE user_id = %d AND enabled = 1
             ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        if ( empty( $keywords ) ) {
            return new \WP_REST_Response([
                'success' => true,
                'data'    => [
                    'keywords'    => [],
                    'weeks'       => $weeks,
                    'week_labels' => $week_labels,
                    'summary'     => $this->rank_tracker_empty_summary_v2(),
                ],
            ]);
        }

        $keyword_ids = array_column( $keywords, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $keyword_ids ), '%d' ) );

        // 直近45日分のすべての結果を一括取得（6週 = 42日 + バッファ）
        $since = $now->modify( '-45 days' )->format( 'Y-m-d 00:00:00' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $all_results = $wpdb->get_results( $wpdb->prepare(
            "SELECT keyword_id, device, rank_group, is_ranked, iso_year_week, fetched_at
             FROM {$res_table}
             WHERE keyword_id IN ({$placeholders}) AND fetched_at >= %s
             ORDER BY fetched_at DESC",
            ...array_merge( $keyword_ids, [ $since ] )
        ), ARRAY_A );

        // keyword_id × device でグループ化
        $results_by_kw = [];
        foreach ( $all_results as $r ) {
            $key = $r['keyword_id'] . '_' . $r['device'];
            $results_by_kw[ $key ][] = $r;
        }

        $output = [];

        foreach ( $keywords as $kw ) {
            $kw_id = (int) $kw['id'];

            // データ組み立て
            $entry = [
                'keyword_id'          => $kw_id,
                'keyword'             => $kw['keyword'],
                'memo'                => $kw['memo'] ?? '',
                'search_volume'       => $kw['search_volume'] !== null ? (int) $kw['search_volume'] : null,
                'keyword_difficulty'  => $kw['keyword_difficulty'] !== null ? (int) $kw['keyword_difficulty'] : null,
                'competition'         => $kw['competition'] ?? null,
                'opportunity_score'   => $kw['opportunity_score'] !== null ? (int) $kw['opportunity_score'] : null,
                'opportunity_reasons' => $kw['opportunity_reasons'] ? json_decode( $kw['opportunity_reasons'], true ) : null,
                'desktop'             => null,
                'mobile'              => null,
                'weekly'              => [ 'desktop' => [], 'mobile' => [] ],
                'fetched_at'          => null,
            ];

            foreach ( ['desktop', 'mobile'] as $device ) {
                $key     = $kw_id . '_' . $device;
                $history = $results_by_kw[ $key ] ?? [];
                // history は fetched_at DESC 順

                // 最新
                $l = ! empty( $history ) ? $history[0] : null;

                // 前回（最新と異なる iso_year_week のもの）
                $p = null;
                if ( $l ) {
                    $latest_week = $l['iso_year_week'] ?? '';
                    foreach ( $history as $h ) {
                        $h_week = $h['iso_year_week'] ?? '';
                        if ( $h_week !== '' && $h_week !== $latest_week ) {
                            $p = $h;
                            break;
                        }
                    }
                }

                if ( ! $l ) {
                    $entry[ $device ] = null;
                } else {
                    $rank_group = $l['is_ranked'] ? (int) $l['rank_group'] : null;
                    $change     = null;

                    if ( $p && $l['is_ranked'] && $p['is_ranked'] ) {
                        $change = (int) $p['rank_group'] - (int) $l['rank_group'];
                    } elseif ( $p && $l['is_ranked'] && ! $p['is_ranked'] ) {
                        $change = 999;
                    } elseif ( $p && ! $l['is_ranked'] && $p['is_ranked'] ) {
                        $change = -999;
                    }

                    $entry[ $device ] = [
                        'rank_group' => $rank_group,
                        'change'     => $change,
                        'is_ranked'  => (bool) $l['is_ranked'],
                    ];

                    if ( ! $entry['fetched_at'] && ! empty( $l['fetched_at'] ) ) {
                        $entry['fetched_at'] = substr( $l['fetched_at'], 0, 10 );
                    }
                }

                // 直近6週分の weekly データ（forward-fill）
                $last_found = null;
                foreach ( $weeks as $week ) {
                    // この週に該当するデータを探す（history は DESC 順）
                    $found = null;
                    foreach ( $history as $h ) {
                        if ( ( $h['iso_year_week'] ?? '' ) === $week ) {
                            $found = $h;
                            break; // DESC 順なので最初にマッチしたものがその週の最新
                        }
                    }

                    // forward-fill: この週のデータがなければ直前のデータを使う
                    if ( ! $found && $last_found ) {
                        $found = $last_found;
                    }

                    if ( $found ) {
                        $entry['weekly'][ $device ][ $week ] = [
                            'rank'      => $found['is_ranked'] ? (int) $found['rank_group'] : null,
                            'is_ranked' => (bool) $found['is_ranked'],
                        ];
                        $last_found = $found;
                    } else {
                        $entry['weekly'][ $device ][ $week ] = null;
                    }
                }
            }

            $output[] = $entry;
        }

        // サマリー: 順位帯別件数（mobile / desktop 両方計算）
        $summary = $this->rank_tracker_build_summary_v2( $output );

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'keywords'    => $output,
                'weeks'       => $weeks,
                'week_labels' => $week_labels,
                'summary'     => $summary,
            ],
        ]);
    }

    /**
     * 順位帯別サマリーを構築（v2: 両デバイス分）
     */
    private function rank_tracker_build_summary_v2( array $keywords ): array {
        $summary = [
            'mobile'  => [ 'rank_1_3' => 0, 'rank_4_10' => 0, 'rank_11_20' => 0, 'rank_out' => 0 ],
            'desktop' => [ 'rank_1_3' => 0, 'rank_4_10' => 0, 'rank_11_20' => 0, 'rank_out' => 0 ],
        ];
        foreach ( $keywords as $kw ) {
            foreach ( ['mobile', 'desktop'] as $device ) {
                $d = $kw[ $device ];
                if ( ! $d || ! $d['is_ranked'] ) {
                    $summary[ $device ]['rank_out']++;
                } elseif ( $d['rank_group'] <= 3 ) {
                    $summary[ $device ]['rank_1_3']++;
                } elseif ( $d['rank_group'] <= 10 ) {
                    $summary[ $device ]['rank_4_10']++;
                } elseif ( $d['rank_group'] <= 20 ) {
                    $summary[ $device ]['rank_11_20']++;
                } else {
                    $summary[ $device ]['rank_out']++;
                }
            }
        }
        return $summary;
    }

    /**
     * 空のサマリー（v2）
     */
    private function rank_tracker_empty_summary_v2(): array {
        return [
            'mobile'  => [ 'rank_1_3' => 0, 'rank_4_10' => 0, 'rank_11_20' => 0, 'rank_out' => 0 ],
            'desktop' => [ 'rank_1_3' => 0, 'rank_4_10' => 0, 'rank_11_20' => 0, 'rank_out' => 0 ],
        ];
    }

    /**
     * GET /rank-tracker/history
     * キーワード別の週次推移データ
     */
    public function rest_get_rank_tracker_history( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id    = get_current_user_id();
        $keyword_id = absint( $request->get_param('keyword_id') ?? 0 );
        $range      = sanitize_text_field( $request->get_param('range') ?? '12w' );

        $res_table = $wpdb->prefix . 'gcrev_rank_results';
        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';

        // キーワードが自分のものか確認
        $kw = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, keyword FROM {$kw_table} WHERE id = %d AND user_id = %d",
            $keyword_id, $user_id
        ), ARRAY_A );

        if ( ! $kw ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'キーワードが見つかりません。' ], 404 );
        }

        // 週数からデータ取得期間計算
        $weeks = $this->rank_tracker_parse_range( $range );
        $tz    = wp_timezone();
        $since = ( new \DateTimeImmutable( "now", $tz ) )->modify( "-{$weeks} weeks" )->format( 'Y-m-d H:i:s' );

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT device, rank_group, rank_absolute, is_ranked, iso_year_week, fetched_at
             FROM {$res_table}
             WHERE keyword_id = %d AND fetched_at >= %s
             ORDER BY fetched_at ASC",
            $keyword_id, $since
        ), ARRAY_A );

        // デバイス × 週でグルーピング
        $history = [ 'desktop' => [], 'mobile' => [] ];
        foreach ( $results as $r ) {
            $device = $r['device'];
            $week   = $r['iso_year_week'];
            $history[ $device ][ $week ] = [
                'rank_group'    => $r['is_ranked'] ? (int) $r['rank_group'] : null,
                'rank_absolute' => $r['is_ranked'] ? (int) $r['rank_absolute'] : null,
                'is_ranked'     => (bool) $r['is_ranked'],
                'fetched_at'    => $r['fetched_at'],
            ];
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'keyword' => $kw['keyword'],
                'history' => $history,
            ],
        ]);
    }

    /**
     * GET /rank-tracker/monthly-trend
     * 半年間（6ヶ月）の月別順位推移を返す。各月の最終週のデータを代表値とする。
     */
    public function rest_get_rank_tracker_monthly_trend( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id    = get_current_user_id();
        $keyword_id = absint( $request->get_param( 'keyword_id' ) ?? 0 );

        if ( $keyword_id <= 0 ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'keyword_id が必要です。' ], 400 );
        }

        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_rank_results';

        // キーワードが本人のものか確認
        $kw = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, keyword FROM {$kw_table} WHERE id = %d AND user_id = %d",
            $keyword_id, $user_id
        ), ARRAY_A );

        if ( ! $kw ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'キーワードが見つかりません。' ], 404 );
        }

        // 6ヶ月前の月初を起点とする
        $tz    = wp_timezone();
        $since = ( new \DateTimeImmutable( 'first day of -5 months', $tz ) )->format( 'Y-m-01' );

        // 各月×デバイスの最終データを取得（自己結合）
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.device,
                    DATE_FORMAT(r.fetched_at, '%%Y-%%m') AS ym,
                    r.rank_group,
                    r.is_ranked
             FROM {$res_table} r
             INNER JOIN (
                 SELECT device,
                        DATE_FORMAT(fetched_at, '%%Y-%%m') AS ym,
                        MAX(fetched_at) AS max_fetched
                 FROM {$res_table}
                 WHERE keyword_id = %d AND fetched_at >= %s
                 GROUP BY device, DATE_FORMAT(fetched_at, '%%Y-%%m')
             ) latest ON r.device = latest.device
                      AND DATE_FORMAT(r.fetched_at, '%%Y-%%m') = latest.ym
                      AND r.fetched_at = latest.max_fetched
             WHERE r.keyword_id = %d AND r.fetched_at >= %s
             ORDER BY r.fetched_at ASC",
            $keyword_id, $since, $keyword_id, $since
        ), ARRAY_A );

        // 6ヶ月分のラベル配列を生成
        $months = [];
        $dt     = new \DateTimeImmutable( $since, $tz );
        for ( $i = 0; $i < 6; $i++ ) {
            $months[] = $dt->modify( "+{$i} months" )->format( 'Y-m' );
        }

        // デバイス × 月でグルーピング
        $trend = [ 'desktop' => [], 'mobile' => [] ];
        foreach ( $results as $r ) {
            $device = $r['device'];
            $ym     = $r['ym'];
            $trend[ $device ][ $ym ] = [
                'rank'      => $r['is_ranked'] ? (int) $r['rank_group'] : null,
                'is_ranked' => (bool) $r['is_ranked'],
            ];
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'keyword' => $kw['keyword'],
                'months'  => $months,
                'trend'   => $trend,
            ],
        ]);
    }

    /**
     * GET /rank-tracker/keywords（管理者用）
     */
    public function rest_get_rank_tracker_keywords( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id = absint( $request->get_param('user_id') ?? 0 );
        if ( $user_id <= 0 ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'user_id が必要です。' ], 400 );
        }

        $table = $wpdb->prefix . 'gcrev_rank_keywords';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        return new \WP_REST_Response( [ 'success' => true, 'data' => $rows ] );
    }

    /**
     * POST /rank-tracker/keywords（管理者用 — 追加/更新）
     */
    public function rest_save_rank_tracker_keyword( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $table   = $wpdb->prefix . 'gcrev_rank_keywords';
        $user_id = absint( $request->get_param('user_id') ?? 0 );
        $id      = absint( $request->get_param('id') ?? 0 );

        if ( $user_id <= 0 ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'user_id が必要です。' ], 400 );
        }

        $keyword       = sanitize_text_field( $request->get_param('keyword') ?? '' );
        $target_domain = sanitize_text_field( $request->get_param('target_domain') ?? '' );
        $location_code = absint( $request->get_param('location_code') ?? 2392 );
        $language_code = sanitize_text_field( $request->get_param('language_code') ?? 'ja' );
        $enabled       = absint( $request->get_param('enabled') ?? 1 );
        $sort_order    = absint( $request->get_param('sort_order') ?? 0 );
        $memo          = sanitize_text_field( $request->get_param('memo') ?? '' );

        if ( $keyword === '' || $target_domain === '' ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'keyword と target_domain は必須です。' ], 400 );
        }

        $tz  = wp_timezone();
        $now = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

        if ( $id > 0 ) {
            // 更新
            $wpdb->update( $table, [
                'keyword'       => $keyword,
                'target_domain' => $target_domain,
                'location_code' => $location_code,
                'language_code' => $language_code,
                'enabled'       => $enabled ? 1 : 0,
                'sort_order'    => $sort_order,
                'memo'          => $memo,
                'updated_at'    => $now,
            ], [ 'id' => $id, 'user_id' => $user_id ], null, [ '%d', '%d' ] );
        } else {
            // 新規
            $wpdb->insert( $table, [
                'user_id'       => $user_id,
                'keyword'       => $keyword,
                'target_domain' => $target_domain,
                'location_code' => $location_code,
                'language_code' => $language_code,
                'enabled'       => $enabled ? 1 : 0,
                'sort_order'    => $sort_order,
                'memo'          => $memo,
                'created_at'    => $now,
                'updated_at'    => $now,
            ] );
            $id = (int) $wpdb->insert_id;
        }

        return new \WP_REST_Response( [ 'success' => true, 'data' => [ 'id' => $id ] ] );
    }

    /**
     * DELETE /rank-tracker/keywords/{id}（管理者用）
     */
    public function rest_delete_rank_tracker_keyword( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $id    = absint( $request['id'] );
        $table = $wpdb->prefix . 'gcrev_rank_keywords';

        $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

        if ( $deleted ) {
            // 関連する結果データも削除
            $res_table = $wpdb->prefix . 'gcrev_rank_results';
            $wpdb->delete( $res_table, [ 'keyword_id' => $id ], [ '%d' ] );
        }

        return new \WP_REST_Response( [ 'success' => (bool) $deleted ] );
    }

    /**
     * POST /rank-tracker/fetch（管理者用 — 手動フェッチ）
     */
    public function rest_fetch_rank_tracker( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->dataforseo ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'DataForSEO クライアントが利用できません。' ], 500 );
        }

        if ( ! Gcrev_DataForSEO_Client::is_configured() ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'DataForSEO API が未設定です。' ], 400 );
        }

        $keyword_id = absint( $request->get_param('keyword_id') ?? 0 );

        if ( $keyword_id <= 0 ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'keyword_id が必要です。' ], 400 );
        }

        global $wpdb;
        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';
        $kw = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$kw_table} WHERE id = %d",
            $keyword_id
        ), ARRAY_A );

        if ( ! $kw ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'キーワードが見つかりません。' ], 404 );
        }

        $results = $this->dataforseo->fetch_rankings_for_keyword(
            $kw['keyword'],
            $kw['target_domain'],
            (int) $kw['location_code'],
            $kw['language_code']
        );

        // DB に保存
        $saved = $this->save_rank_results( $kw, $results );

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'keyword' => $kw['keyword'],
                'results' => $results,
                'saved'   => $saved,
            ],
        ]);
    }

    // =========================================================
    // 順位トラッキング — Cron
    // =========================================================

    /** 順位フェッチチャンク制限 */
    public const RANK_FETCH_CHUNK_LIMIT = 5;

    /**
     * 日次自動取得のエントリポイント（Cron コールバック）
     */
    public function auto_fetch_rankings(): void {
        $lock_key = 'gcrev_lock_rank_fetch';
        $lock_ttl = 7200; // 2h

        // ロック取得
        if ( get_transient( $lock_key ) ) {
            error_log( '[GCREV][RankTracker] Lock is active, skipping' );
            return;
        }
        set_transient( $lock_key, 1, $lock_ttl );

        // Cron ログ開始（log_id をチャンク間で引き継ぐためTransientに保存）
        if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
            $log_id = Gcrev_Cron_Logger::start( 'rank_fetch' );
            set_transient( 'gcrev_rank_fetch_log_id', $log_id, 7200 );
        }

        global $wpdb;
        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';

        // 事前見積もり
        $total_keywords = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$kw_table} WHERE enabled = 1 AND target_domain != ''"
        );
        $api_calls = $total_keywords * 2; // desktop + mobile
        error_log( "[GCREV][RankTracker] Starting daily fetch: {$total_keywords} keywords, estimated {$api_calls} API calls" );

        // チャンク処理開始
        wp_schedule_single_event( time() + 5, 'gcrev_rank_fetch_chunk_event', [ 0, self::RANK_FETCH_CHUNK_LIMIT ] );
    }

    /**
     * チャンク処理コールバック（5ユーザーずつ）
     */
    public function rank_fetch_chunk( int $offset, int $limit ): void {
        global $wpdb;

        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_rank_results';

        // 有効キーワードを持つユーザーを取得（チャンク）
        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$kw_table}
             WHERE enabled = 1 AND target_domain != ''
             ORDER BY user_id ASC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        if ( empty( $user_ids ) ) {
            // 完了
            delete_transient( 'gcrev_lock_rank_fetch' );
            $log_id = (int) get_transient( 'gcrev_rank_fetch_log_id' );
            if ( $log_id > 0 && class_exists( 'Gcrev_Cron_Logger' ) ) {
                Gcrev_Cron_Logger::finish( $log_id );
                delete_transient( 'gcrev_rank_fetch_log_id' );
            }
            error_log( '[GCREV][RankTracker] Daily fetch completed' );
            return;
        }

        if ( ! $this->dataforseo || ! Gcrev_DataForSEO_Client::is_configured() ) {
            error_log( '[GCREV][RankTracker] DataForSEO not configured, aborting' );
            delete_transient( 'gcrev_lock_rank_fetch' );
            return;
        }

        $tz          = wp_timezone();
        $now         = new \DateTimeImmutable( 'now', $tz );
        $fetch_date  = $now->format( 'Y-m-d' );

        foreach ( $user_ids as $uid ) {
            $uid = (int) $uid;

            $keywords = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$kw_table}
                 WHERE user_id = %d AND enabled = 1 AND target_domain != ''
                 ORDER BY sort_order ASC, id ASC",
                $uid
            ), ARRAY_A );

            foreach ( $keywords as $kw ) {
                $kw_id = (int) $kw['id'];

                // 日次重複チェック（当日分が既にあればスキップ）
                $already = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$res_table}
                     WHERE keyword_id = %d AND fetch_date = %s",
                    $kw_id, $fetch_date
                ) );

                if ( (int) $already > 0 ) {
                    continue; // 本日分は取得済み
                }

                // DataForSEO API 呼び出し
                $results = $this->dataforseo->fetch_rankings_for_keyword(
                    $kw['keyword'],
                    $kw['target_domain'],
                    (int) $kw['location_code'],
                    $kw['language_code']
                );

                $this->save_rank_results( $kw, $results );
            }

            $log_id = (int) get_transient( 'gcrev_rank_fetch_log_id' );
            if ( $log_id > 0 && class_exists( 'Gcrev_Cron_Logger' ) ) {
                Gcrev_Cron_Logger::log_user( $log_id, $uid, 'ok' );
            }
        }

        // 次のチャンクをスケジュール
        $next_offset = $offset + $limit;
        wp_schedule_single_event( time() + 10, 'gcrev_rank_fetch_chunk_event', [ $next_offset, $limit ] );
        error_log( "[GCREV][RankTracker] Chunk done (offset={$offset}), next scheduled at offset={$next_offset}" );
    }

    // =========================================================
    // 順位トラッキング — ヘルパー
    // =========================================================

    /**
     * 取得結果を DB に保存
     *
     * @param array  $kw         キーワードレコード
     * @param array  $results    fetch_rankings_for_keyword() の返り値
     * @param string $fetch_mode 'weekly_batch' | 'manual_live'
     * @return array 保存結果
     */
    private function save_rank_results( array $kw, array $results, string $fetch_mode = 'weekly_batch' ): array {
        global $wpdb;

        $res_table   = $wpdb->prefix . 'gcrev_rank_results';
        $tz          = wp_timezone();
        $now_dt      = new \DateTimeImmutable( 'now', $tz );
        $now         = $now_dt->format( 'Y-m-d H:i:s' );
        $iso_week    = $now_dt->format( 'o-W' );
        $fetch_date  = $now_dt->format( 'Y-m-d' );

        $saved = [];

        foreach ( ['desktop', 'mobile'] as $device ) {
            $r = $results[ $device ] ?? null;
            if ( ! $r || isset( $r['error'] ) ) {
                $saved[ $device ] = false;
                continue;
            }

            $rg  = $r['is_ranked'] ? $wpdb->prepare( '%d', $r['rank_group'] ) : 'NULL';
            $ra  = $r['is_ranked'] ? $wpdb->prepare( '%d', $r['rank_absolute'] ) : 'NULL';
            $url = $r['found_url'] !== null ? $wpdb->prepare( '%s', $r['found_url'] ) : "''";
            $dom = $r['found_domain'] !== null ? $wpdb->prepare( '%s', $r['found_domain'] ) : "''";

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare(
                "INSERT INTO {$res_table}
                 (keyword_id, user_id, device, rank_group, rank_absolute, found_url, found_domain,
                  is_ranked, serp_type, api_source, fetch_mode, iso_year_week, fetch_date, fetched_at, created_at)
                 VALUES (%d, %d, %s, {$rg}, {$ra}, {$url}, {$dom}, %d, %s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                  rank_group    = VALUES(rank_group),
                  rank_absolute = VALUES(rank_absolute),
                  found_url     = VALUES(found_url),
                  found_domain  = VALUES(found_domain),
                  is_ranked     = VALUES(is_ranked),
                  fetch_mode    = VALUES(fetch_mode),
                  fetched_at    = VALUES(fetched_at)",
                (int) $kw['id'],
                (int) $kw['user_id'],
                $device,
                $r['is_ranked'] ? 1 : 0,
                $r['serp_type'] ?? 'organic',
                'dataforseo',
                $fetch_mode,
                $iso_week,
                $fetch_date,
                $now,
                $now
            );

            $result = $wpdb->query( $sql );
            $saved[ $device ] = $result !== false;
        }

        return $saved;
    }

    /**
     * 範囲文字列を週数に変換
     */
    private function rank_tracker_parse_range( string $range ): int {
        $map = [
            '4w'  => 4,
            '8w'  => 8,
            '12w' => 12,
            '26w' => 26,  // 半年
            '52w' => 52,  // 1年
        ];
        return $map[ $range ] ?? 12;
    }


    /**
     * 空のサマリー
     */
    private function rank_tracker_empty_summary(): array {
        return [
            'total'       => 0,
            'avg_desktop' => null,
            'avg_mobile'  => null,
            'improved'    => 0,
        ];
    }

    /**
     * クライアント設定からドメインを解決する（旧キー含むフォールバック）
     *
     * 優先順:
     *  1. gcrev_client_site_url（新クライアント設定）
     *  2. report_site_url（旧レポート設定）
     *  3. weisite_url（レガシー WP-Members）
     *
     * URL からドメイン部分のみを抽出して返す。
     *
     * @param int $user_id ユーザーID
     * @return string ドメイン文字列（取得できない場合は空文字）
     */
    private function rank_tracker_resolve_domain( int $user_id ): string {
        $url = get_user_meta( $user_id, 'gcrev_client_site_url', true );

        if ( empty( $url ) ) {
            $url = get_user_meta( $user_id, 'report_site_url', true );
        }
        if ( empty( $url ) ) {
            $url = get_user_meta( $user_id, 'weisite_url', true );
        }
        if ( empty( $url ) ) {
            return '';
        }

        $url = trim( $url );

        // URL からドメイン部分を抽出（https://example.com/path → example.com）
        $parsed = wp_parse_url( $url );
        if ( ! empty( $parsed['host'] ) ) {
            return strtolower( $parsed['host'] );
        }

        // スキームがない場合（example.com/path のようなケース）
        $parsed = wp_parse_url( 'https://' . $url );
        if ( ! empty( $parsed['host'] ) ) {
            return strtolower( $parsed['host'] );
        }

        // パースできない場合はそのまま返す
        return strtolower( $url );
    }

    // =========================================================
    // 順位トラッキング — ユーザー向けキーワード管理 REST
    // =========================================================

    /** ユーザーあたりキーワード上限 */
    private const RANK_KEYWORD_LIMIT = 5;

    /**
     * GET /rank-tracker/my-keywords
     * ログインユーザー自身のキーワード一覧（上限情報 + 手動取得 quota 付き）
     */
    public function rest_get_my_keywords( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id   = get_current_user_id();
        $table     = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_rank_results';
        $is_admin  = current_user_can( 'manage_options' );

        $tz    = wp_timezone();
        $today = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword, target_domain, location_code, enabled, aio_enabled, memo,
                    search_volume, keyword_difficulty, competition, cpc,
                    volume_fetched_at, difficulty_fetched_at,
                    opportunity_score, opportunity_reasons, opportunity_fetched_at,
                    created_at, updated_at
             FROM {$table}
             WHERE user_id = %d
             ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        // 本日の手動取得済みキーワード ID 一覧を事前取得
        $fetched_today_ids = [];
        if ( ! $is_admin ) {
            $fetched_rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT keyword_id FROM {$res_table}
                 WHERE user_id = %d AND DATE(fetched_at) = %s AND fetch_mode = 'manual_live'",
                $user_id, $today
            ) );
            $fetched_today_ids = array_map( 'intval', $fetched_rows );
        }

        // per-user: 本日の手動取得使用数
        $daily_used = $is_admin ? 0 : count( $fetched_today_ids );

        // 各キーワードの最新順位も取得
        foreach ( $rows as &$row ) {
            $row['id']          = (int) $row['id'];
            $row['enabled']     = (bool) $row['enabled'];
            $row['aio_enabled'] = (bool) ( $row['aio_enabled'] ?? 0 );
            $row['search_volume']       = $row['search_volume'] !== null ? (int) $row['search_volume'] : null;
            $row['keyword_difficulty']  = $row['keyword_difficulty'] !== null ? (int) $row['keyword_difficulty'] : null;
            $row['cpc']                 = $row['cpc'] !== null ? (float) $row['cpc'] : null;
            $row['opportunity_score']   = $row['opportunity_score'] !== null ? (int) $row['opportunity_score'] : null;
            $row['opportunity_reasons'] = ! empty( $row['opportunity_reasons'] ) ? json_decode( $row['opportunity_reasons'], true ) : null;

            foreach ( ['desktop', 'mobile'] as $device ) {
                $latest = $wpdb->get_row( $wpdb->prepare(
                    "SELECT rank_group, is_ranked, fetched_at
                     FROM {$res_table}
                     WHERE keyword_id = %d AND device = %s
                     ORDER BY fetched_at DESC LIMIT 1",
                    $row['id'], $device
                ), ARRAY_A );

                $row[ "latest_{$device}" ] = $latest ? [
                    'rank_group' => $latest['is_ranked'] ? (int) $latest['rank_group'] : null,
                    'is_ranked'  => (bool) $latest['is_ranked'],
                    'fetched_at' => substr( $latest['fetched_at'], 0, 10 ),
                ] : null;
            }

            // 手動取得可否
            $manual_fetched_today = in_array( $row['id'], $fetched_today_ids, true );
            $row['manual_fetched_today'] = $manual_fetched_today;
            $row['can_manual_fetch'] = $row['enabled']
                && ( $is_admin || ( ! $manual_fetched_today && $daily_used < self::MANUAL_FETCH_DAILY_LIMIT ) );
        }
        unset( $row );

        $count = count( $rows );
        $default_domain = $this->rank_tracker_resolve_domain( $user_id );

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'keywords'       => $rows,
                'count'          => $count,
                'limit'          => self::RANK_KEYWORD_LIMIT,
                'can_add'        => $count < self::RANK_KEYWORD_LIMIT,
                'default_domain' => $default_domain,
                'manual_fetch_limit' => [
                    'daily_used'      => $daily_used,
                    'daily_limit'     => self::MANUAL_FETCH_DAILY_LIMIT,
                    'daily_remaining' => $is_admin ? 999 : max( 0, self::MANUAL_FETCH_DAILY_LIMIT - $daily_used ),
                    'is_admin'        => $is_admin,
                ],
            ],
        ]);
    }

    /**
     * POST /rank-tracker/my-keywords
     * ログインユーザーのキーワード追加/更新（5件制限付き）
     */
    public function rest_save_my_keyword( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id = get_current_user_id();
        $table   = $wpdb->prefix . 'gcrev_rank_keywords';

        $id            = absint( $request->get_param('id') ?? 0 );
        $keyword       = sanitize_text_field( $request->get_param('keyword') ?? '' );
        $target_domain = sanitize_text_field( $request->get_param('target_domain') ?? '' );
        $location_code = absint( $request->get_param('location_code') ?? 2392 );
        $memo          = sanitize_text_field( $request->get_param('memo') ?? '' );
        $enabled       = $request->get_param('enabled');

        // keyword は必須
        if ( $keyword === '' ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'キーワードを入力してください。' ], 400 );
        }

        // target_domain: 未指定時はクライアント設定から自動取得（旧キー含むフォールバック）
        if ( $target_domain === '' ) {
            $target_domain = $this->rank_tracker_resolve_domain( $user_id );
        }
        if ( $target_domain === '' ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => '対象ドメインが設定されていません。管理者にお問い合わせください。' ], 400 );
        }

        $tz  = wp_timezone();
        $now = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

        if ( $id > 0 ) {
            // 更新: 自分のキーワードか確認
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
                $id, $user_id
            ) );
            if ( ! $existing ) {
                return new \WP_REST_Response( [ 'success' => false, 'message' => 'キーワードが見つかりません。' ], 404 );
            }

            // ユーザーは keyword と memo のみ編集可能（target_domain / location_code は変更不可）
            $update_data = [
                'keyword'    => $keyword,
                'memo'       => $memo,
                'updated_at' => $now,
            ];

            // enabled の切り替え（明示的に渡された場合のみ）
            if ( $enabled !== null ) {
                $update_data['enabled'] = $enabled ? 1 : 0;
            }

            $wpdb->update( $table, $update_data, [ 'id' => $id, 'user_id' => $user_id ] );

            return new \WP_REST_Response( [ 'success' => true, 'data' => [ 'id' => $id ] ] );
        }

        // 新規追加: 件数チェック
        $current_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user_id
        ) );

        if ( $current_count >= self::RANK_KEYWORD_LIMIT ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'キーワードは最大' . self::RANK_KEYWORD_LIMIT . '件まで登録できます。不要なキーワードを削除してから追加してください。',
            ], 400 );
        }

        // 重複チェック
        $dup = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d AND keyword = %s AND location_code = %d",
            $user_id, $keyword, $location_code
        ) );
        if ( (int) $dup > 0 ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'このキーワードは既に登録されています。' ], 400 );
        }

        $wpdb->insert( $table, [
            'user_id'       => $user_id,
            'keyword'       => $keyword,
            'target_domain' => $target_domain,
            'location_code' => $location_code,
            'language_code' => 'ja',
            'enabled'       => 1,
            'sort_order'    => 0,
            'memo'          => $memo,
            'created_at'    => $now,
            'updated_at'    => $now,
        ] );

        $new_id = (int) $wpdb->insert_id;

        // 新規追加時: 検索ボリューム・SEO難易度を自動取得
        if ( $new_id > 0 && $this->dataforseo && Gcrev_DataForSEO_Client::is_configured() ) {
            $this->auto_fetch_keyword_metrics_single( $new_id, $keyword, $location_code, 'ja' );
        }

        return new \WP_REST_Response( [ 'success' => true, 'data' => [ 'id' => $new_id ] ] );
    }

    /**
     * 単一キーワードの検索ボリューム・SEO難易度を自動取得
     *
     * @param int    $keyword_id    キーワードID
     * @param string $keyword_text  キーワードテキスト
     * @param int    $location_code ロケーションコード
     * @param string $language_code 言語コード
     */
    private function auto_fetch_keyword_metrics_single( int $keyword_id, string $keyword_text, int $location_code, string $language_code ): void {
        global $wpdb;
        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';
        $tz  = wp_timezone();
        $now = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

        // 検索ボリューム取得
        $vol_data = $this->dataforseo->fetch_search_volume( [ $keyword_text ], $location_code, $language_code );
        if ( ! is_wp_error( $vol_data ) && isset( $vol_data[ $keyword_text ] ) ) {
            $v = $vol_data[ $keyword_text ];
            $wpdb->update( $kw_table, [
                'search_volume'     => $v['search_volume'],
                'competition'       => $v['competition'],
                'cpc'               => $v['cpc'],
                'volume_fetched_at' => $now,
                'updated_at'        => $now,
            ], [ 'id' => $keyword_id ] );
        }

        // SEO難易度取得（keyword_overview/live, 国レベル 2392）
        $diff_data = $this->dataforseo->fetch_keyword_difficulty( [ $keyword_text ], 2392, $language_code );
        if ( ! is_wp_error( $diff_data ) && isset( $diff_data[ $keyword_text ] ) ) {
            $d = $diff_data[ $keyword_text ];
            $wpdb->update( $kw_table, [
                'keyword_difficulty'    => $d['keyword_difficulty'],
                'difficulty_fetched_at' => $now,
                'updated_at'            => $now,
            ], [ 'id' => $keyword_id ] );
        }
    }

    /**
     * DELETE /rank-tracker/my-keywords/{id}
     * ログインユーザー自身のキーワードを削除（結果データも削除）
     */
    public function rest_delete_my_keyword( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        $user_id = get_current_user_id();
        $id      = absint( $request['id'] );
        $table   = $wpdb->prefix . 'gcrev_rank_keywords';

        // 自分のキーワードか確認
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
            $id, $user_id
        ) );
        if ( ! $existing ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'キーワードが見つかりません。' ], 404 );
        }

        $wpdb->delete( $table, [ 'id' => $id, 'user_id' => $user_id ] );

        // 関連する結果データも削除
        $res_table = $wpdb->prefix . 'gcrev_rank_results';
        $wpdb->delete( $res_table, [ 'keyword_id' => $id ], [ '%d' ] );

        return new \WP_REST_Response( [ 'success' => true ] );
    }

    /** 一般ユーザーの手動取得 日次上限 */
    private const MANUAL_FETCH_DAILY_LIMIT = 5;

    /**
     * POST /rank-tracker/my-keywords/{id}/fetch
     * ログインユーザーが自分のキーワードの順位を手動 Live 取得
     *
     * レート制限:
     *  - per-keyword: 1日1回（manual_live のみカウント）
     *  - per-user:    1日5回（manual_live のみカウント）
     *  - 管理者:      制限なし
     */
    public function rest_fetch_my_keyword_rank( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->dataforseo || ! Gcrev_DataForSEO_Client::is_configured() ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => '順位取得サービスが利用できません。管理者にお問い合わせください。' ], 503 );
        }

        global $wpdb;
        $user_id   = get_current_user_id();
        $id        = absint( $request['id'] );
        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_rank_results';
        $is_admin  = current_user_can( 'manage_options' );

        // 自分のキーワードか確認
        $kw = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$kw_table} WHERE id = %d AND user_id = %d",
            $id, $user_id
        ), ARRAY_A );

        if ( ! $kw ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'キーワードが見つかりません。' ], 404 );
        }

        // 停止中キーワードは取得不可
        if ( ! (bool) $kw['enabled'] ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => '停止中のキーワードは取得できません。' ], 400 );
        }

        $tz    = wp_timezone();
        $today = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );

        // --- レート制限（管理者はバイパス） ---
        if ( ! $is_admin ) {
            // per-keyword: このキーワードは本日すでに手動取得済みか
            $kw_today = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$res_table}
                 WHERE keyword_id = %d AND DATE(fetched_at) = %s AND fetch_mode = 'manual_live'",
                $id, $today
            ) );
            if ( $kw_today > 0 ) {
                return new \WP_REST_Response( [ 'success' => false, 'message' => 'このキーワードは本日すでに手動取得済みです。' ], 429 );
            }

            // per-user: 本日の手動取得回数
            $user_today = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT keyword_id) FROM {$res_table}
                 WHERE user_id = %d AND DATE(fetched_at) = %s AND fetch_mode = 'manual_live'",
                $user_id, $today
            ) );
            if ( $user_today >= self::MANUAL_FETCH_DAILY_LIMIT ) {
                return new \WP_REST_Response( [
                    'success' => false,
                    'message' => sprintf( '本日の手動取得上限（%d回/日）に達しました。', self::MANUAL_FETCH_DAILY_LIMIT ),
                ], 429 );
            }
        }

        // DataForSEO API 呼び出し（desktop + mobile）
        $results = $this->dataforseo->fetch_rankings_for_keyword(
            $kw['keyword'],
            $kw['target_domain'],
            (int) $kw['location_code'],
            $kw['language_code']
        );

        // DB に保存（fetch_mode = 'manual_live'）
        $saved = $this->save_rank_results( $kw, $results, 'manual_live' );

        // ログ出力
        $desktop_rank = isset( $results['desktop']['rank_group'] ) ? $results['desktop']['rank_group'] : '圏外';
        $mobile_rank  = isset( $results['mobile']['rank_group'] )  ? $results['mobile']['rank_group']  : '圏外';
        error_log( sprintf(
            '[GCREV][RankTracker][ManualFetch] user=%d keyword_id=%d keyword="%s" desktop=%s mobile=%s',
            $user_id, $id, $kw['keyword'], $desktop_rank, $mobile_rank
        ) );

        // 現在のユーザー日次使用数を算出（今回分を含む）
        $daily_used = $is_admin ? 0 : (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT keyword_id) FROM {$res_table}
             WHERE user_id = %d AND DATE(fetched_at) = %s AND fetch_mode = 'manual_live'",
            $user_id, $today
        ) );

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'keyword' => $kw['keyword'],
                'results' => $results,
                'saved'   => $saved,
                'rate_limit' => [
                    'daily_used'      => $daily_used,
                    'daily_limit'     => self::MANUAL_FETCH_DAILY_LIMIT,
                    'daily_remaining' => $is_admin ? 999 : max( 0, self::MANUAL_FETCH_DAILY_LIMIT - $daily_used ),
                    'is_admin'        => $is_admin,
                ],
            ],
        ]);
    }

    /**
     * POST /rank-tracker/fetch-metrics（管理者用 — 検索ボリューム + SEO難易度 手動取得）
     */
    public function rest_fetch_rank_tracker_metrics( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->dataforseo ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'DataForSEO クライアントが利用できません。' ], 500 );
        }

        if ( ! Gcrev_DataForSEO_Client::is_configured() ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'DataForSEO API が未設定です。' ], 400 );
        }

        $user_id = absint( $request->get_param('user_id') ?? 0 );
        if ( $user_id <= 0 ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'user_id が必要です。' ], 400 );
        }

        global $wpdb;
        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword, location_code, language_code
             FROM {$kw_table}
             WHERE user_id = %d AND enabled = 1
             ORDER BY id ASC",
            $user_id
        ), ARRAY_A );

        if ( empty( $keywords ) ) {
            return new \WP_REST_Response( [ 'success' => true, 'data' => [ 'updated' => 0 ] ] );
        }

        $kw_texts = array_column( $keywords, 'keyword' );
        $loc      = (int) $keywords[0]['location_code'];
        $lang     = $keywords[0]['language_code'] ?: 'ja';

        $tz  = wp_timezone();
        $now = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );
        $updated = 0;

        // 検索ボリューム取得
        $vol_data = $this->dataforseo->fetch_search_volume( $kw_texts, $loc, $lang );
        if ( ! is_wp_error( $vol_data ) ) {
            foreach ( $keywords as $kw ) {
                $v = $vol_data[ $kw['keyword'] ] ?? null;
                if ( $v ) {
                    $wpdb->update( $kw_table, [
                        'search_volume'     => $v['search_volume'],
                        'competition'       => $v['competition'],
                        'cpc'               => $v['cpc'],
                        'volume_fetched_at' => $now,
                        'updated_at'        => $now,
                    ], [ 'id' => (int) $kw['id'] ] );
                    $updated++;
                }
            }
        }

        // SEO難易度取得（Labs APIは地域コード非対応の場合があるため国レベル 2392 を使用）
        $diff_data = $this->dataforseo->fetch_keyword_difficulty( $kw_texts, 2392, $lang );
        if ( ! is_wp_error( $diff_data ) ) {
            foreach ( $keywords as $kw ) {
                $d = $diff_data[ $kw['keyword'] ] ?? null;
                if ( $d ) {
                    $wpdb->update( $kw_table, [
                        'keyword_difficulty'    => $d['keyword_difficulty'],
                        'difficulty_fetched_at' => $now,
                        'updated_at'            => $now,
                    ], [ 'id' => (int) $kw['id'] ] );
                }
            }
        } else {
            error_log( '[GCREV][KeywordMetrics] rest_fetch_metrics: keyword_difficulty error (loc:2392): ' . $diff_data->get_error_message() );
        }

        return new \WP_REST_Response( [ 'success' => true, 'data' => [ 'updated' => $updated ] ] );
    }

    // =========================================================
    // 順位トラッキング — 月次キーワード指標 Cron
    // =========================================================

    /** キーワード指標チャンク制限 */
    public const KEYWORD_METRICS_CHUNK_LIMIT = 5;

    /**
     * 月次キーワード指標取得のエントリポイント（検索ボリューム + SEO難易度）
     */
    public function auto_fetch_keyword_metrics(): void {
        $lock_key = 'gcrev_lock_keyword_metrics';
        $lock_ttl = 7200; // 2h

        if ( get_transient( $lock_key ) ) {
            error_log( '[GCREV][KeywordMetrics] Lock is active, skipping' );
            return;
        }
        set_transient( $lock_key, 1, $lock_ttl );

        // Cron ログ開始（log_id をチャンク間で引き継ぐためTransientに保存）
        if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
            $log_id = Gcrev_Cron_Logger::start( 'keyword_metrics' );
            set_transient( 'gcrev_keyword_metrics_log_id', $log_id, 7200 );
        }

        error_log( '[GCREV][KeywordMetrics] Starting monthly keyword metrics fetch' );

        // チャンク処理開始
        wp_schedule_single_event( time() + 5, 'gcrev_keyword_metrics_chunk_event', [ 0, self::KEYWORD_METRICS_CHUNK_LIMIT ] );
    }

    /**
     * キーワード指標チャンク処理（5ユーザーずつ）
     */
    public function keyword_metrics_chunk( int $offset, int $limit ): void {
        global $wpdb;

        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';

        // 有効キーワードを持つユーザーを取得（チャンク）
        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$kw_table}
             WHERE enabled = 1
             ORDER BY user_id ASC
             LIMIT %d OFFSET %d",
            $limit, $offset
        ) );

        if ( empty( $user_ids ) ) {
            // 完了
            delete_transient( 'gcrev_lock_keyword_metrics' );
            $log_id = (int) get_transient( 'gcrev_keyword_metrics_log_id' );
            if ( $log_id > 0 && class_exists( 'Gcrev_Cron_Logger' ) ) {
                Gcrev_Cron_Logger::finish( $log_id );
                delete_transient( 'gcrev_keyword_metrics_log_id' );
            }
            error_log( '[GCREV][KeywordMetrics] Monthly metrics fetch completed' );
            return;
        }

        if ( ! $this->dataforseo || ! Gcrev_DataForSEO_Client::is_configured() ) {
            error_log( '[GCREV][KeywordMetrics] DataForSEO not configured, aborting' );
            delete_transient( 'gcrev_lock_keyword_metrics' );
            return;
        }

        $tz  = wp_timezone();
        $now = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );
        $current_month = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m' );

        foreach ( $user_ids as $uid ) {
            $uid = (int) $uid;

            $keywords = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, keyword, location_code, language_code, volume_fetched_at
                 FROM {$kw_table}
                 WHERE user_id = %d AND enabled = 1
                 ORDER BY id ASC",
                $uid
            ), ARRAY_A );

            if ( empty( $keywords ) ) {
                continue;
            }

            // 今月既に取得済みのキーワードを除外
            $to_fetch = [];
            $skipped  = 0;
            foreach ( $keywords as $kw ) {
                if ( ! empty( $kw['volume_fetched_at'] ) && substr( $kw['volume_fetched_at'], 0, 7 ) === $current_month ) {
                    $skipped++;
                    continue;
                }
                $to_fetch[] = $kw;
            }

            if ( empty( $to_fetch ) ) {
                error_log( "[GCREV][KeywordMetrics] User {$uid}: all {$skipped} keywords already fetched this month" );
                continue;
            }

            $kw_texts = array_column( $to_fetch, 'keyword' );
            $loc      = (int) $to_fetch[0]['location_code'];
            $lang     = $to_fetch[0]['language_code'] ?: 'ja';

            $fetched_count = 0;

            // 検索ボリューム
            $vol_data = $this->dataforseo->fetch_search_volume( $kw_texts, $loc, $lang );
            if ( ! is_wp_error( $vol_data ) ) {
                foreach ( $to_fetch as $kw ) {
                    $v = $vol_data[ $kw['keyword'] ] ?? null;
                    if ( $v ) {
                        $wpdb->update( $kw_table, [
                            'search_volume'     => $v['search_volume'],
                            'competition'       => $v['competition'],
                            'cpc'               => $v['cpc'],
                            'volume_fetched_at' => $now,
                            'updated_at'        => $now,
                        ], [ 'id' => (int) $kw['id'] ] );
                        $fetched_count++;
                    }
                }
            } else {
                error_log( "[GCREV][KeywordMetrics] User {$uid}: search_volume error: " . $vol_data->get_error_message() );
            }

            // SEO難易度（Labs APIは地域コード非対応の場合があるため国レベル 2392 を使用）
            $diff_data = $this->dataforseo->fetch_keyword_difficulty( $kw_texts, 2392, $lang );
            if ( ! is_wp_error( $diff_data ) ) {
                foreach ( $to_fetch as $kw ) {
                    $d = $diff_data[ $kw['keyword'] ] ?? null;
                    if ( $d ) {
                        $wpdb->update( $kw_table, [
                            'keyword_difficulty'    => $d['keyword_difficulty'],
                            'difficulty_fetched_at' => $now,
                            'updated_at'            => $now,
                        ], [ 'id' => (int) $kw['id'] ] );
                    }
                }
            } else {
                error_log( "[GCREV][KeywordMetrics] User {$uid}: keyword_difficulty error (loc:2392): " . $diff_data->get_error_message() );
            }

            error_log( "[GCREV][KeywordMetrics] User {$uid}: fetched={$fetched_count}, skipped_this_month={$skipped}" );

            $log_id = (int) get_transient( 'gcrev_keyword_metrics_log_id' );
            if ( $log_id > 0 && class_exists( 'Gcrev_Cron_Logger' ) ) {
                Gcrev_Cron_Logger::log_user( $log_id, $uid, 'ok' );
            }
        }

        // 次のチャンクをスケジュール
        $next_offset = $offset + $limit;
        wp_schedule_single_event( time() + 10, 'gcrev_keyword_metrics_chunk_event', [ $next_offset, $limit ] );
        error_log( "[GCREV][KeywordMetrics] Chunk done (offset={$offset}), next scheduled at offset={$next_offset}" );
    }

    // =========================================================
    // 順位トラッキング — 全キーワード一括取得
    // =========================================================

    /**
     * POST /rank-tracker/fetch-all
     * ログインユーザーの有効キーワードすべてを一括で順位取得
     */
    public function rest_fetch_all_my_keywords( \WP_REST_Request $request ): \WP_REST_Response {
        file_put_contents( '/tmp/gcrev_rank_debug.log',
            date( 'Y-m-d H:i:s' ) . " [fetch_all] START user=" . get_current_user_id() . "\n", FILE_APPEND );

        if ( ! $this->dataforseo || ! Gcrev_DataForSEO_Client::is_configured() ) {
            file_put_contents( '/tmp/gcrev_rank_debug.log',
                date( 'Y-m-d H:i:s' ) . " [fetch_all] DataForSEO not configured\n", FILE_APPEND );
            return new \WP_REST_Response( [ 'success' => false, 'message' => '順位取得サービスが利用できません。' ], 503 );
        }

        global $wpdb;
        $user_id   = get_current_user_id();
        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_rank_results';
        $is_admin  = current_user_can( 'manage_options' );
        $tz        = wp_timezone();
        $today     = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );

        // レート制限: 一括取得は1日1回（管理者は無制限）
        if ( ! $is_admin ) {
            $bulk_key = 'gcrev_bulk_fetch_' . $user_id . '_' . $today;
            if ( get_transient( $bulk_key ) ) {
                return new \WP_REST_Response( [
                    'success' => false,
                    'message' => '本日の一括取得は既に実行済みです。翌日にお試しください。',
                ], 429 );
            }
        }

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$kw_table}
             WHERE user_id = %d AND enabled = 1 AND target_domain != ''
             ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        if ( empty( $keywords ) ) {
            return new \WP_REST_Response( [ 'success' => true, 'data' => [ 'fetched' => 0, 'results' => [] ] ] );
        }

        $results_all = [];
        $fetched     = 0;

        foreach ( $keywords as $kw ) {
            file_put_contents( '/tmp/gcrev_rank_debug.log',
                date( 'Y-m-d H:i:s' ) . " [fetch_all] Fetching kw_id={$kw['id']} keyword={$kw['keyword']}\n", FILE_APPEND );

            $results = $this->dataforseo->fetch_rankings_for_keyword(
                $kw['keyword'],
                $kw['target_domain'],
                (int) $kw['location_code'],
                $kw['language_code']
            );

            file_put_contents( '/tmp/gcrev_rank_debug.log',
                date( 'Y-m-d H:i:s' ) . " [fetch_all] Got results kw_id={$kw['id']}"
                . " desktop=" . ( $results['desktop']['rank_group'] ?? 'null' )
                . " mobile=" . ( $results['mobile']['rank_group'] ?? 'null' ) . "\n", FILE_APPEND );

            $saved = $this->save_rank_results( $kw, $results, 'manual_live' );
            $fetched++;

            // 検索ボリュームが未取得、または30日以上経過したキーワードは自動補完
            $needs_volume = $kw['search_volume'] === null
                || empty( $kw['volume_fetched_at'] )
                || strtotime( $kw['volume_fetched_at'] ) < strtotime( '-30 days' );
            if ( $needs_volume ) {
                $this->auto_fetch_keyword_metrics_single(
                    (int) $kw['id'],
                    $kw['keyword'],
                    (int) $kw['location_code'],
                    $kw['language_code'] ?: 'ja'
                );
            }

            // 上がりやすさスコア: SERP items から算出（7日キャッシュ）
            $needs_opportunity = empty( $kw['opportunity_fetched_at'] )
                || strtotime( $kw['opportunity_fetched_at'] ) < strtotime( '-7 days' );
            if ( $needs_opportunity && ! empty( $results['serp_items'] ) && class_exists( 'Gcrev_Opportunity_Scorer' ) ) {
                $opp = Gcrev_Opportunity_Scorer::calculate( $results['serp_items'], $kw['keyword'] );
                if ( $opp['score'] !== null ) {
                    $tz_opp  = wp_timezone();
                    $now_opp = ( new \DateTimeImmutable( 'now', $tz_opp ) )->format( 'Y-m-d H:i:s' );
                    $wpdb->update( $kw_table, [
                        'opportunity_score'      => $opp['score'],
                        'opportunity_reasons'    => wp_json_encode( $opp['reasons'], JSON_UNESCAPED_UNICODE ),
                        'opportunity_fetched_at' => $now_opp,
                        'updated_at'             => $now_opp,
                    ], [ 'id' => (int) $kw['id'] ] );
                }
            }

            $results_all[] = [
                'keyword_id' => (int) $kw['id'],
                'keyword'    => $kw['keyword'],
                'desktop'    => $results['desktop'] ?? null,
                'mobile'     => $results['mobile'] ?? null,
                'saved'      => $saved,
            ];
        }

        // 一括取得済みフラグ
        if ( ! $is_admin ) {
            set_transient( 'gcrev_bulk_fetch_' . $user_id . '_' . $today, 1, DAY_IN_SECONDS );
        }

        file_put_contents( '/tmp/gcrev_rank_debug.log',
            date( 'Y-m-d H:i:s' ) . " [fetch_all] DONE fetched={$fetched}\n", FILE_APPEND );

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'fetched' => $fetched,
                'results' => $results_all,
            ],
        ]);
    }

    // =========================================================
    // 順位トラッキング — SERP 上位サイト取得
    // =========================================================

    /**
     * GET /rank-tracker/serp-top
     * 指定キーワードの SERP 上位15件を返す（キャッシュ付き）
     */
    public function rest_get_serp_top( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->dataforseo || ! Gcrev_DataForSEO_Client::is_configured() ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => '順位取得サービスが利用できません。' ], 503 );
        }

        global $wpdb;
        $user_id    = get_current_user_id();
        $keyword_id = absint( $request->get_param('keyword_id') ?? 0 );
        $device     = sanitize_text_field( $request->get_param('device') ?? 'mobile' );

        if ( ! in_array( $device, ['desktop', 'mobile'], true ) ) {
            $device = 'mobile';
        }
        if ( $keyword_id <= 0 ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'keyword_id が必要です。' ], 400 );
        }

        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';
        $kw = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, keyword, location_code, language_code FROM {$kw_table}
             WHERE id = %d AND user_id = %d",
            $keyword_id, $user_id
        ), ARRAY_A );

        if ( ! $kw ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'キーワードが見つかりません。' ], 404 );
        }

        // キャッシュチェック（1時間）
        $cache_key = "gcrev_serp_top_{$keyword_id}_{$device}";
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return new \WP_REST_Response( [ 'success' => true, 'data' => $cached ] );
        }

        // SERP 取得
        $items = $this->dataforseo->fetch_serp(
            $kw['keyword'],
            $device,
            (int) $kw['location_code'],
            $kw['language_code'] ?: 'ja'
        );

        if ( is_wp_error( $items ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'SERP データの取得に失敗しました: ' . $items->get_error_message(),
            ], 500 );
        }

        // 上位15件の organic 結果を抽出
        $top = [];
        $count = 0;
        foreach ( $items as $item ) {
            if ( $count >= 15 ) {
                break;
            }
            $type = $item['type'] ?? '';
            if ( $type !== 'organic' ) {
                continue;
            }
            $top[] = [
                'rank'        => (int) ( $item['rank_group'] ?? $count + 1 ),
                'title'       => $item['title'] ?? '',
                'url'         => $item['url'] ?? '',
                'domain'      => $item['domain'] ?? '',
                'description' => $item['description'] ?? '',
            ];
            $count++;
        }

        $data = [
            'keyword' => $kw['keyword'],
            'device'  => $device,
            'items'   => $top,
        ];

        // 1時間キャッシュ
        set_transient( $cache_key, $data, HOUR_IN_SECONDS );

        return new \WP_REST_Response( [ 'success' => true, 'data' => $data ] );
    }

    // =========================================================
    // 順位トラッキング — キーワード並び替え
    // =========================================================

    /**
     * POST /rank-tracker/reorder
     * キーワードの並び順を更新
     */
    public function rest_reorder_my_keywords( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $user_id = get_current_user_id();
        $table   = $wpdb->prefix . 'gcrev_rank_keywords';

        $keyword_id = absint( $request->get_param('keyword_id') ?? 0 );
        $direction  = sanitize_text_field( $request->get_param('direction') ?? '' );

        if ( $keyword_id <= 0 || ! in_array( $direction, ['up', 'down'], true ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => '不正なパラメータです。' ], 400 );
        }

        // 自分のキーワード一覧取得（現在の並び順）
        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sort_order FROM {$table}
             WHERE user_id = %d
             ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        $idx = null;
        foreach ( $keywords as $i => $kw ) {
            if ( (int) $kw['id'] === $keyword_id ) {
                $idx = $i;
                break;
            }
        }
        if ( $idx === null ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'キーワードが見つかりません。' ], 404 );
        }

        $swap_idx = $direction === 'up' ? $idx - 1 : $idx + 1;
        if ( $swap_idx < 0 || $swap_idx >= count( $keywords ) ) {
            return new \WP_REST_Response( [ 'success' => true ] ); // 端なので何もしない
        }

        // sort_order を連番で振り直し（swap 後）
        $ids = array_column( $keywords, 'id' );
        $tmp = $ids[ $idx ];
        $ids[ $idx ] = $ids[ $swap_idx ];
        $ids[ $swap_idx ] = $tmp;

        foreach ( $ids as $order => $id ) {
            $wpdb->update( $table, [ 'sort_order' => $order ], [ 'id' => (int) $id, 'user_id' => $user_id ] );
        }

        return new \WP_REST_Response( [ 'success' => true ] );
    }

    // =========================================================
    // AIOスコア REST コールバック
    // =========================================================

    /**
     * GET /aio/summary
     * ログインユーザーの AIO スコアサマリーを返す
     */
    public function rest_get_aio_summary( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        try {
            $service = new Gcrev_AIO_Service( $this->config );
            $data    = $service->get_results_summary( $user_id );
            return new \WP_REST_Response( [ 'success' => true, 'data' => $data ] );
        } catch ( \Exception $e ) {
            error_log( '[GCREV][AIO] rest_get_aio_summary error: ' . $e->getMessage() );
            return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * GET /aio/keyword-detail?keyword_id=N
     * キーワード別の詳細データ（アコーディオン展開用）
     */
    public function rest_get_aio_keyword_detail( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id    = get_current_user_id();
        $keyword_id = absint( $request->get_param( 'keyword_id' ) );

        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_rank_keywords';
        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE id = %d", $keyword_id
        ) );

        if ( $owner !== $user_id && ! current_user_can( 'manage_options' ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => '権限がありません' ], 403 );
        }

        try {
            $service = new Gcrev_AIO_Service( $this->config );
            $data    = $service->get_keyword_detail( $keyword_id );
            return new \WP_REST_Response( [ 'success' => true, 'data' => $data ] );
        } catch ( \Exception $e ) {
            error_log( '[GCREV][AIO] rest_get_aio_keyword_detail error: ' . $e->getMessage() );
            return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * POST /aio/run
     * 全 AIO 有効キーワードを一括計測
     */
    public function rest_run_aio_check( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id  = get_current_user_id();
        $lock_key = "gcrev_lock_aio_{$user_id}";

        if ( get_transient( $lock_key ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => '計測が進行中です。しばらくお待ちください。',
            ] );
        }
        set_transient( $lock_key, 1, 2 * HOUR_IN_SECONDS );

        try {
            $service = new Gcrev_AIO_Service( $this->config );
            $results = $service->run_all_keywords( $user_id );
            return new \WP_REST_Response( [ 'success' => true, 'data' => $results ] );
        } catch ( \Throwable $e ) {
            error_log( '[GCREV][AIO] rest_run_aio_check error: ' . $e->getMessage() );
            return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * POST /aio/run-keyword
     * 単一キーワードの AIO 計測
     */
    public function rest_run_aio_keyword( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id    = get_current_user_id();
        $keyword_id = absint( $request->get_param( 'keyword_id' ) );

        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_rank_keywords';
        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE id = %d", $keyword_id
        ) );

        if ( $owner !== $user_id && ! current_user_can( 'manage_options' ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => '権限がありません' ], 403 );
        }

        // provider パラメータ指定時は単一プロバイダーのみ実行（タイムアウト対策）
        $provider  = sanitize_text_field( $request->get_param( 'provider' ) ?? '' );
        $providers = $provider !== '' ? [ $provider ] : [];

        try {
            $service = new Gcrev_AIO_Service( $this->config );
            $result  = $service->run_aio_check( $keyword_id, $providers );
            return new \WP_REST_Response( [ 'success' => true, 'data' => $result ] );
        } catch ( \Throwable $e ) {
            error_log( '[GCREV][AIO] rest_run_aio_keyword error: ' . $e->getMessage() );
            return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * GET /aio/my-keywords
     * ログインユーザーの AIO 有効キーワード一覧
     */
    public function rest_get_aio_my_keywords( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $user_id  = get_current_user_id();
        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword, target_domain, location_code, enabled, aio_enabled, sort_order, memo, created_at
             FROM {$kw_table}
             WHERE user_id = %d
             ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        return new \WP_REST_Response( [ 'success' => true, 'data' => $keywords ?: [] ] );
    }

    /**
     * POST /aio/my-keywords
     * AIO 有効/無効切替 { keyword_id, aio_enabled }
     */
    public function rest_save_aio_keyword( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $user_id    = get_current_user_id();
        $keyword_id = absint( $request->get_param( 'keyword_id' ) );
        $aio_on     = (int) $request->get_param( 'aio_enabled' );

        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';

        $affected = $wpdb->update(
            $kw_table,
            [ 'aio_enabled' => $aio_on ? 1 : 0 ],
            [ 'id' => $keyword_id, 'user_id' => $user_id ],
            [ '%d' ],
            [ '%d', '%d' ]
        );

        if ( $affected === false ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => '更新に失敗しました' ], 500 );
        }

        return new \WP_REST_Response( [ 'success' => true ] );
    }

    /**
     * DELETE /aio/my-keywords/{id}
     * AIO を無効にする（キーワード自体は削除しない）
     */
    public function rest_delete_aio_keyword( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $user_id    = get_current_user_id();
        $keyword_id = absint( $request['id'] );

        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';

        $wpdb->update(
            $kw_table,
            [ 'aio_enabled' => 0 ],
            [ 'id' => $keyword_id, 'user_id' => $user_id ],
            [ '%d' ],
            [ '%d', '%d' ]
        );

        return new \WP_REST_Response( [ 'success' => true ] );
    }

    /**
     * GET /aio/keywords?user_id=N （管理者用）
     */
    public function rest_get_aio_keywords_admin( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $user_id  = absint( $request->get_param( 'user_id' ) );
        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';

        if ( ! $user_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'user_id が必要です' ], 400 );
        }

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword, target_domain, enabled, aio_enabled, sort_order
             FROM {$kw_table}
             WHERE user_id = %d
             ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        // 会社名・別名も返す
        $service      = new Gcrev_AIO_Service( $this->config );
        $company_name = $service->get_company_name( $user_id );
        $aliases      = $service->get_company_aliases( $user_id );

        return new \WP_REST_Response( [
            'success' => true,
            'data'    => [
                'keywords'     => $keywords ?: [],
                'company_name' => $company_name,
                'aliases'      => $aliases,
            ],
        ] );
    }

    /**
     * POST /aio/keywords （管理者用）
     * { user_id, keyword_id, aio_enabled } or { user_id, aliases: [...] }
     */
    public function rest_save_aio_keyword_admin( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;
        $user_id = absint( $request->get_param( 'user_id' ) );

        if ( ! $user_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'user_id が必要です' ], 400 );
        }

        // 別名更新
        $aliases = $request->get_param( 'aliases' );
        if ( $aliases !== null ) {
            if ( is_string( $aliases ) ) {
                $aliases = json_decode( $aliases, true );
            }
            if ( is_array( $aliases ) ) {
                $clean = array_values( array_filter( array_map( 'sanitize_text_field', $aliases ) ) );
                update_user_meta( $user_id, 'gcrev_aio_company_aliases', wp_json_encode( $clean, JSON_UNESCAPED_UNICODE ) );
            }
        }

        // キーワード AIO 切替
        $keyword_id = absint( $request->get_param( 'keyword_id' ) );
        if ( $keyword_id ) {
            $aio_on   = (int) $request->get_param( 'aio_enabled' );
            $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';
            $wpdb->update(
                $kw_table,
                [ 'aio_enabled' => $aio_on ? 1 : 0 ],
                [ 'id' => $keyword_id, 'user_id' => $user_id ],
                [ '%d' ],
                [ '%d', '%d' ]
            );
        }

        return new \WP_REST_Response( [ 'success' => true ] );
    }

    // =========================================================
    // AIレポート統合エンドポイント
    // =========================================================

    /**
     * GET /aio/report — AIレポート統合データ
     */
    public function rest_get_aio_report( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        // 管理者は別ユーザーを指定可
        if ( current_user_can( 'manage_options' ) && $request->get_param( 'user_id' ) ) {
            $user_id = absint( $request->get_param( 'user_id' ) );
        }

        try {
            $service = new Gcrev_AIO_Service( $this->config );
            $data    = $service->get_report_data( $user_id );
            return new \WP_REST_Response( [ 'success' => true, 'data' => $data ] );
        } catch ( \Throwable $e ) {
            error_log( '[GCREV][AIO] rest_get_aio_report error: ' . $e->getMessage() );
            return new \WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * GET /aio/site-diagnosis — サイト診断データ取得
     */
    public function rest_get_aio_site_diagnosis( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        if ( current_user_can( 'manage_options' ) && $request->get_param( 'user_id' ) ) {
            $user_id = absint( $request->get_param( 'user_id' ) );
        }

        $service   = new Gcrev_AIO_Service( $this->config );
        $diagnosis = $service->get_site_diagnosis( $user_id );
        return new \WP_REST_Response( [ 'success' => true, 'data' => $diagnosis ] );
    }

    /**
     * POST /aio/site-diagnosis — サイト診断データ保存（管理者のみ）
     */
    public function rest_save_aio_site_diagnosis( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = absint( $request->get_param( 'user_id' ) );
        if ( ! $user_id ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'user_id は必須です' ], 400 );
        }

        $items = $request->get_param( 'items' );
        if ( ! is_array( $items ) || empty( $items ) ) {
            return new \WP_REST_Response( [ 'success' => false, 'message' => 'items は必須です' ], 400 );
        }

        $service = new Gcrev_AIO_Service( $this->config );
        $ok      = $service->save_site_diagnosis( $user_id, $items );

        return new \WP_REST_Response( [ 'success' => $ok ] );
    }

    /**
     * POST /aio/run-diagnosis — サイト診断を実行（クロール→解析→保存）
     */
    public function rest_run_aio_site_diagnosis( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        if ( current_user_can( 'manage_options' ) && $request->get_param( 'user_id' ) ) {
            $user_id = absint( $request->get_param( 'user_id' ) );
        }

        // レート制限（1時間に1回）
        $lock_key = 'gcrev_diag_lock_' . $user_id;
        if ( get_transient( $lock_key ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => '診断は1時間に1回のみ実行できます。しばらくお待ちください。',
            ], 429 );
        }
        set_transient( $lock_key, 1, HOUR_IN_SECONDS );

        // タイムアウト延長
        @set_time_limit( 120 );

        try {
            $service = new Gcrev_AIO_Service( $this->config );
            $result  = $service->run_site_diagnosis( $user_id );
            return new \WP_REST_Response( [ 'success' => true, 'data' => $result ] );
        } catch ( \Throwable $e ) {
            delete_transient( $lock_key );
            error_log( '[GCREV][AIO] run_site_diagnosis error: ' . $e->getMessage() );
            return new \WP_REST_Response( [
                'success' => false,
                'message' => '診断中にエラーが発生しました: ' . esc_html( $e->getMessage() ),
            ], 500 );
        }
    }

    // =========================================================
    // SEO対策
    // =========================================================

    /**
     * SEO診断結果を取得
     */
    public function rest_get_seo_report( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        if ( current_user_can( 'manage_options' ) && $request->get_param( 'user_id' ) ) {
            $user_id = absint( $request->get_param( 'user_id' ) );
        }

        try {
            $checker = new Gcrev_SEO_Checker();
            $data    = $checker->get_diagnosis( $user_id );
            if ( ! $data ) {
                return new \WP_REST_Response( [
                    'success' => true,
                    'data'    => null,
                    'message' => 'まだ診断が実行されていません。',
                ] );
            }
            return new \WP_REST_Response( [ 'success' => true, 'data' => $data ] );
        } catch ( \Throwable $e ) {
            error_log( '[GCREV][SEO] get_report error: ' . $e->getMessage() );
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'データ取得中にエラーが発生しました。',
            ], 500 );
        }
    }

    /**
     * SEO診断を実行
     */
    public function rest_run_seo_diagnosis( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        if ( current_user_can( 'manage_options' ) && $request->get_param( 'user_id' ) ) {
            $user_id = absint( $request->get_param( 'user_id' ) );
        }

        // レート制限（テスト中のため一時無効化）
        $lock_key = 'gcrev_seo_diag_lock_' . $user_id;
        delete_transient( $lock_key ); // 既存ロック解除
        // if ( get_transient( $lock_key ) ) {
        //     return new \WP_REST_Response( [
        //         'success' => false,
        //         'message' => 'SEO診断は1時間に1回のみ実行できます。しばらくお待ちください。',
        //     ], 429 );
        // }
        // set_transient( $lock_key, 1, HOUR_IN_SECONDS );

        // タイムアウト延長（クロールに時間がかかるため）
        @set_time_limit( 180 );

        try {
            $checker = new Gcrev_SEO_Checker( $this->ai );
            $run_data = $checker->run_diagnosis( $user_id );
            // 比較データ付きで返す
            $result = $checker->get_diagnosis( $user_id );
            // get_diagnosis が null の場合は run_diagnosis の結果を直接返す
            if ( ! $result ) {
                $run_data['comparison']   = null;
                $run_data['historyCount'] = 0;
                $result = $run_data;
            }
            return new \WP_REST_Response( [ 'success' => true, 'data' => $result ] );
        } catch ( \Throwable $e ) {
            // delete_transient( $lock_key );
            error_log( '[GCREV][SEO] run_diagnosis error: ' . $e->getMessage() );
            return new \WP_REST_Response( [
                'success' => false,
                'message' => '診断中にエラーが発生しました: ' . esc_html( $e->getMessage() ),
            ], 500 );
        }
    }

    // =========================================================
    // 口コミ投稿支援
    // =========================================================

    /**
     * レートリミットチェック（公開エンドポイント保護）
     * 1時間あたり10回/IPに制限
     */
    public function check_review_rate_limit(\WP_REST_Request $request): bool {
        $ip  = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = 'gcrev_review_rate_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= 10) {
            return false;
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return true;
    }

    /**
     * 口コミ参考文を生成する（公開エンドポイント）
     *
     * POST gcrev/v1/review/generate
     * Body（トークン方式）: { survey_token, answers: [{question_id, question, answer}] }
     * Body（レガシー方式）: { answers: [{question, answer}], user_id: int }
     */
    public function rest_generate_review(\WP_REST_Request $request): \WP_REST_Response {
        try {
            global $wpdb;
            $params       = $request->get_json_params();
            $answers      = $params['answers'] ?? [];
            $survey_token = sanitize_text_field($params['survey_token'] ?? '');
            $user_id      = absint($params['user_id'] ?? 0);

            if (empty($answers) || !is_array($answers)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => '回答データが不足しています。',
                ], 400);
            }

            // トークン方式: DBからアンケート情報を取得
            $survey_id      = 0;
            $survey_user_id = 0;
            $business_name  = '';

            if (!empty($survey_token)) {
                $t_surveys = $wpdb->prefix . 'gcrev_surveys';
                $survey = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$t_surveys} WHERE token = %s AND status = 'published'",
                    $survey_token
                ));
                if (!$survey) {
                    return new \WP_REST_Response([
                        'success' => false,
                        'message' => 'アンケートが見つかりません。',
                    ], 404);
                }
                $survey_id      = (int) $survey->id;
                $survey_user_id = (int) $survey->user_id;
                $user_id        = $survey_user_id;
                $business_name  = function_exists('gcrev_get_business_name')
                    ? gcrev_get_business_name($survey_user_id)
                    : (get_user_meta($survey_user_id, 'gcrev_business_name', true) ?: '');
            } elseif ($user_id > 0) {
                // レガシー方式
                $business_name = function_exists('gcrev_get_business_name')
                    ? gcrev_get_business_name($user_id)
                    : (get_user_meta($user_id, 'gcrev_business_name', true) ?: '');
            }

            // 回答をプロンプト用テキストに整形
            $answer_text = $this->format_review_answers($answers);
            if (empty($answer_text)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => '有効な回答がありません。入力内容をご確認ください。',
                ], 400);
            }

            // AIプロンプト構築 → Gemini API 呼び出し
            $prompt       = $this->build_review_prompt($answer_text, $business_name);
            $raw_response = $this->ai->call_gemini_api($prompt);

            // JSONパース
            $parsed = $this->parse_review_response($raw_response);
            if ($parsed === null) {
                file_put_contents('/tmp/gcrev_review_debug.log',
                    date('Y-m-d H:i:s') . " JSON parse failed. raw=" . substr($raw_response, 0, 500) . "\n",
                    FILE_APPEND
                );
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => '口コミ案の作成に失敗しました。もう一度お試しください。',
                ], 500);
            }

            // 回答をDBに保存（トークン方式の場合のみ）
            if ($survey_id > 0) {
                $extra = [
                    'respondent_name' => sanitize_text_field($params['respondent_name'] ?? ''),
                    'consent_ai'      => ! empty($params['consent_ai']),
                    'consent_review'  => ! empty($params['consent_review']),
                ];
                $this->save_survey_response($wpdb, $survey_id, $survey_user_id, $answers, $parsed, $extra);
            }

            return new \WP_REST_Response([
                'success'       => true,
                'short_review'  => $parsed['short_review'],
                'normal_review' => $parsed['normal_review'],
            ], 200);

        } catch (\Exception $e) {
            file_put_contents('/tmp/gcrev_review_debug.log',
                date('Y-m-d H:i:s') . " review generate error: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return new \WP_REST_Response([
                'success' => false,
                'message' => '口コミ案の作成に失敗しました。少し時間をおいてもう一度お試しください。',
            ], 500);
        }
    }

    /**
     * アンケート回答をDBに保存
     */
    private function save_survey_response(\wpdb $wpdb, int $survey_id, int $user_id, array $answers, array $parsed, array $extra = []): void {
        $t_responses = $wpdb->prefix . 'gcrev_survey_responses';
        $t_answers   = $wpdb->prefix . 'gcrev_survey_response_answers';
        $t_ai_gen    = $wpdb->prefix . 'gcrev_survey_ai_generations';
        $now         = current_time('mysql');

        $wpdb->insert($t_responses, [
            'survey_id'       => $survey_id,
            'user_id'         => $user_id,
            'respondent_name' => sanitize_text_field($extra['respondent_name'] ?? ''),
            'short_review'    => $parsed['short_review'],
            'normal_review'   => $parsed['normal_review'],
            'status'          => 'new',
            'consent_ai'      => ! empty($extra['consent_ai']) ? 1 : 0,
            'consent_review'  => ! empty($extra['consent_review']) ? 1 : 0,
            'created_at'      => $now,
        ], ['%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s']);

        $response_id = (int) $wpdb->insert_id;
        if ($response_id <= 0) return;

        foreach ($answers as $item) {
            $question_id = absint($item['question_id'] ?? 0);
            $answer      = $item['answer'] ?? '';

            $answer_json = is_array($answer)
                ? wp_json_encode($answer, JSON_UNESCAPED_UNICODE)
                : sanitize_textarea_field($answer);

            $wpdb->insert($t_answers, [
                'response_id' => $response_id,
                'question_id' => $question_id,
                'answer'      => $answer_json,
            ], ['%d', '%d', '%s']);
        }

        // AI生成文を ai_generations テーブルにも保存
        if ( ! empty($parsed['short_review']) ) {
            $wpdb->insert($t_ai_gen, [
                'response_id'    => $response_id,
                'survey_id'      => $survey_id,
                'user_id'        => $user_id,
                'generated_text' => $parsed['short_review'],
                'review_type'    => 'short',
                'version'        => 1,
                'status'         => 'generated',
                'created_at'     => $now,
            ], ['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s']);
        }
        if ( ! empty($parsed['normal_review']) ) {
            $wpdb->insert($t_ai_gen, [
                'response_id'    => $response_id,
                'survey_id'      => $survey_id,
                'user_id'        => $user_id,
                'generated_text' => $parsed['normal_review'],
                'review_type'    => 'normal',
                'version'        => 1,
                'status'         => 'generated',
                'created_at'     => $now,
            ], ['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s']);
        }
    }

    /**
     * アンケート回答をプロンプト用テキストに整形
     */
    private function format_review_answers(array $answers): string {
        $lines = [];
        foreach ($answers as $item) {
            $question = sanitize_text_field($item['question'] ?? '');
            $answer   = $item['answer'] ?? '';

            if (empty($question)) continue;

            if (is_array($answer)) {
                $answer = array_map('sanitize_text_field', $answer);
                $answer = array_filter($answer);
                if (empty($answer)) continue;
                $lines[] = "【{$question}】\n" . implode('、', $answer);
            } else {
                $answer = sanitize_textarea_field($answer);
                if (trim($answer) === '') continue;
                $lines[] = "【{$question}】\n{$answer}";
            }
        }
        return implode("\n\n", $lines);
    }

    /**
     * 口コミ生成用AIプロンプトを構築
     */
    private function build_review_prompt(string $answer_text, string $business_name = ''): string {
        $business_part = $business_name
            ? "対象のサービス・店舗名: {$business_name}\n"
            : '';

        return <<<PROMPT
あなたは、実際に利用した顧客の視点でGoogle口コミの参考文を作成するアシスタントです。

## 目的
以下のアンケート回答をもとに、Google口コミとして投稿できる参考文を2パターン作成してください。
これは自動投稿用ではなく、顧客本人が修正して使うための下書きです。

{$business_part}
## アンケート回答内容
{$answer_text}

## 出力ルール

### short_review（80〜120文字程度）
- 短く簡潔にまとめた口コミ文

### normal_review（120〜180文字程度）
- もう少し詳しく書いた口コミ文

### 共通の文体ルール
- 実際のアンケート回答内容から逸脱しないこと
- 回答にない情報や体験を勝手に補わないこと
- 誇張しすぎない。不自然に褒めすぎない
- テンプレート感を抑え、自然な日本語にする
- 実体験に基づいた口コミに見える文体にする
- 営業色が強すぎる表現は避ける
- Google口コミとして違和感のない文体にする
- 「です・ます」調で統一する
- 星評価（★）は含めない

## 出力形式
以下のJSON形式のみを出力してください。説明文やマークダウンは不要です。

```json
{
  "short_review": "短めの口コミ文",
  "normal_review": "標準の口コミ文"
}
```
PROMPT;
    }

    /**
     * AI応答からJSON（short_review / normal_review）をパース
     */
    private function parse_review_response(string $raw): ?array {
        // マークダウンのコードブロックを除去
        $cleaned = preg_replace('/```(?:json)?\s*/i', '', $raw);
        $cleaned = preg_replace('/```\s*/', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);
        if (!is_array($parsed)) {
            // フォールバック: JSONブロックを正規表現で抽出
            if (preg_match('/\{[^{}]*"short_review"\s*:\s*"[^"]*"[^{}]*"normal_review"\s*:\s*"[^"]*"[^{}]*\}/s', $raw, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }

        if (!is_array($parsed) || empty($parsed['short_review']) || empty($parsed['normal_review'])) {
            return null;
        }

        return [
            'short_review'  => sanitize_textarea_field($parsed['short_review']),
            'normal_review' => sanitize_textarea_field($parsed['normal_review']),
        ];
    }

    // =========================================================
    // アンケート管理 REST API コールバック
    // =========================================================

    private const SURVEY_LIMIT = 3;

    /**
     * アンケートへのアクセス権チェック
     */
    private function can_access_survey(int $survey_id, int $user_id): bool {
        if ($survey_id <= 0) return false;
        if (current_user_can('manage_options')) return true;
        global $wpdb;
        $owner = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}gcrev_surveys WHERE id = %d",
            $survey_id
        ));
        return $owner === $user_id;
    }

    /**
     * GET survey/list — 自分のアンケート一覧
     */
    public function rest_survey_list(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $user_id  = get_current_user_id();
        $is_admin = current_user_can('manage_options');
        $t_surveys   = $wpdb->prefix . 'gcrev_surveys';
        $t_questions = $wpdb->prefix . 'gcrev_survey_questions';

        $where = $is_admin ? '1=1' : $wpdb->prepare('s.user_id = %d', $user_id);

        $surveys = $wpdb->get_results(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM {$t_questions} q WHERE q.survey_id = s.id AND q.is_active = 1) AS question_count
             FROM {$t_surveys} s
             WHERE {$where}
             ORDER BY s.updated_at DESC"
        );

        $form_url = home_url('/review-form/');
        $items = [];
        foreach ($surveys as $s) {
            $items[] = [
                'id'             => (int) $s->id,
                'title'          => $s->title,
                'status'         => $s->status,
                'question_count' => (int) $s->question_count,
                'token'          => $s->token,
                'public_url'     => $s->status === 'published' ? $form_url . '?t=' . $s->token : '',
                'updated_at'     => $s->updated_at,
                'user_id'        => (int) $s->user_id,
            ];
        }

        $count = $is_admin
            ? count($items)
            : (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t_surveys} WHERE user_id = %d", $user_id
              ));

        return new \WP_REST_Response([
            'surveys'  => $items,
            'count'    => $count,
            'limit'    => self::SURVEY_LIMIT,
            'is_admin' => $is_admin,
        ], 200);
    }

    /**
     * GET survey/detail — アンケート詳細 + 質問一覧
     */
    public function rest_survey_detail(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $survey_id = absint($request->get_param('id'));
        $user_id   = get_current_user_id();

        if (!$this->can_access_survey($survey_id, $user_id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        $t_surveys   = $wpdb->prefix . 'gcrev_surveys';
        $t_questions = $wpdb->prefix . 'gcrev_survey_questions';

        $survey = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_surveys} WHERE id = %d", $survey_id
        ));
        if (!$survey) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アンケートが見つかりません。'], 404);
        }

        $questions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t_questions} WHERE survey_id = %d ORDER BY sort_order ASC, id ASC",
            $survey_id
        ));

        $q_items = [];
        foreach ($questions as $q) {
            $opts = [];
            if (!empty($q->options)) {
                $decoded = json_decode($q->options, true);
                if (is_array($decoded)) $opts = $decoded;
            }
            $q_items[] = [
                'id'          => (int) $q->id,
                'type'        => $q->type,
                'label'       => $q->label,
                'description' => $q->description,
                'placeholder' => $q->placeholder,
                'options'     => $opts,
                'required'    => (bool) $q->required,
                'sort_order'  => (int) $q->sort_order,
                'is_active'   => (bool) $q->is_active,
            ];
        }

        $form_url = home_url('/review-form/');
        return new \WP_REST_Response([
            'survey' => [
                'id'                => (int) $survey->id,
                'title'             => $survey->title,
                'description'       => $survey->description,
                'google_review_url' => $survey->google_review_url,
                'status'            => $survey->status,
                'token'             => $survey->token,
                'created_at'        => $survey->created_at,
                'updated_at'        => $survey->updated_at,
            ],
            'questions'  => $q_items,
            'public_url' => $survey->status === 'published' ? $form_url . '?t=' . $survey->token : '',
        ], 200);
    }

    /**
     * POST survey/save — アンケート作成/更新
     */
    public function rest_survey_save(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $params  = $request->get_json_params();
        $user_id = get_current_user_id();
        $id      = absint($params['id'] ?? 0);

        $title             = sanitize_text_field($params['title'] ?? '');
        $description       = sanitize_textarea_field($params['description'] ?? '');
        $google_review_url = esc_url_raw($params['google_review_url'] ?? '');
        $status            = sanitize_text_field($params['status'] ?? 'draft');

        if (empty($title)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'タイトルは必須です。'], 400);
        }
        if (!in_array($status, ['draft', 'published'], true)) {
            $status = 'draft';
        }

        $t_surveys = $wpdb->prefix . 'gcrev_surveys';
        $now = current_time('mysql');

        if ($id > 0) {
            // 更新
            if (!$this->can_access_survey($id, $user_id)) {
                return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
            }
            $wpdb->update($t_surveys, [
                'title'             => $title,
                'description'       => $description,
                'google_review_url' => $google_review_url,
                'status'            => $status,
                'updated_at'        => $now,
            ], ['id' => $id], ['%s', '%s', '%s', '%s', '%s'], ['%d']);
        } else {
            // 新規作成 — 上限チェック
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t_surveys} WHERE user_id = %d", $user_id
            ));
            if ($count >= self::SURVEY_LIMIT) {
                return new \WP_REST_Response([
                    'success' => false,
                    'message' => 'アンケートは最大' . self::SURVEY_LIMIT . '件まで作成できます。',
                    'count'   => $count,
                    'limit'   => self::SURVEY_LIMIT,
                ], 400);
            }

            $token = wp_generate_password(32, false);
            $wpdb->insert($t_surveys, [
                'user_id'           => $user_id,
                'title'             => $title,
                'description'       => $description,
                'google_review_url' => $google_review_url,
                'token'             => $token,
                'status'            => $status,
                'created_at'        => $now,
                'updated_at'        => $now,
            ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
            $id = (int) $wpdb->insert_id;
        }

        return new \WP_REST_Response([
            'success'   => true,
            'survey_id' => $id,
        ], 200);
    }

    /**
     * POST survey/delete — アンケート削除
     */
    public function rest_survey_delete(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $params    = $request->get_json_params();
        $survey_id = absint($params['id'] ?? 0);
        $user_id   = get_current_user_id();

        if (!$this->can_access_survey($survey_id, $user_id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        $t_surveys   = $wpdb->prefix . 'gcrev_surveys';
        $t_questions = $wpdb->prefix . 'gcrev_survey_questions';
        $t_responses = $wpdb->prefix . 'gcrev_survey_responses';
        $t_answers   = $wpdb->prefix . 'gcrev_survey_response_answers';
        $t_ai_gen    = $wpdb->prefix . 'gcrev_survey_ai_generations';

        // カスケード削除: 回答 → 回答詳細 + AI生成文
        $response_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$t_responses} WHERE survey_id = %d", $survey_id
        ));
        if ( ! empty($response_ids) ) {
            $placeholders = implode(',', array_fill(0, count($response_ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$t_answers} WHERE response_id IN ({$placeholders})",
                ...$response_ids
            ));
        }
        $wpdb->delete($t_ai_gen, ['survey_id' => $survey_id], ['%d']);
        $wpdb->delete($t_responses, ['survey_id' => $survey_id], ['%d']);
        $wpdb->delete($t_questions, ['survey_id' => $survey_id], ['%d']);
        $wpdb->delete($t_surveys, ['id' => $survey_id], ['%d']);

        return new \WP_REST_Response(['success' => true], 200);
    }

    /**
     * POST survey/question/save — 質問追加/更新
     */
    public function rest_survey_question_save(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $params    = $request->get_json_params();
        $survey_id = absint($params['survey_id'] ?? 0);
        $q_id      = absint($params['id'] ?? 0);
        $user_id   = get_current_user_id();

        if (!$this->can_access_survey($survey_id, $user_id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        $label = sanitize_text_field($params['label'] ?? '');
        if (empty($label)) {
            return new \WP_REST_Response(['success' => false, 'message' => '質問文は必須です。'], 400);
        }

        $type        = sanitize_text_field($params['type'] ?? 'textarea');
        $valid_types = ['textarea', 'radio', 'checkbox', 'text', 'select'];
        if (!in_array($type, $valid_types, true)) {
            $type = 'textarea';
        }

        $description = sanitize_text_field($params['description'] ?? '');
        $placeholder = sanitize_text_field($params['placeholder'] ?? '');
        $required    = !empty($params['required']) ? 1 : 0;
        $sort_order  = absint($params['sort_order'] ?? 0);
        $is_active   = isset($params['is_active']) ? (int) (bool) $params['is_active'] : 1;

        // options処理
        $options_raw = $params['options'] ?? [];
        if (is_array($options_raw)) {
            $options_raw = array_map('sanitize_text_field', $options_raw);
            $options_raw = array_values(array_filter($options_raw, function($v) { return $v !== ''; }));
        } else {
            $options_raw = [];
        }
        $options_json = !empty($options_raw) ? wp_json_encode($options_raw, JSON_UNESCAPED_UNICODE) : '';

        $t_questions = $wpdb->prefix . 'gcrev_survey_questions';
        $t_surveys   = $wpdb->prefix . 'gcrev_surveys';

        $data = [
            'survey_id'   => $survey_id,
            'type'        => $type,
            'label'       => $label,
            'description' => $description,
            'placeholder' => $placeholder,
            'options'     => $options_json,
            'required'    => $required,
            'sort_order'  => $sort_order,
            'is_active'   => $is_active,
        ];
        $format = ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d'];

        if ($q_id > 0) {
            $wpdb->update($t_questions, $data, ['id' => $q_id, 'survey_id' => $survey_id], $format, ['%d', '%d']);
        } else {
            $wpdb->insert($t_questions, $data, $format);
            $q_id = (int) $wpdb->insert_id;
        }

        // survey の updated_at を更新
        $wpdb->update($t_surveys, ['updated_at' => current_time('mysql')], ['id' => $survey_id], ['%s'], ['%d']);

        return new \WP_REST_Response([
            'success'     => true,
            'question_id' => $q_id,
        ], 200);
    }

    /**
     * POST survey/question/delete — 質問削除
     */
    public function rest_survey_question_delete(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $params      = $request->get_json_params();
        $survey_id   = absint($params['survey_id'] ?? 0);
        $question_id = absint($params['question_id'] ?? 0);
        $user_id     = get_current_user_id();

        if (!$this->can_access_survey($survey_id, $user_id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        $t_questions = $wpdb->prefix . 'gcrev_survey_questions';
        $t_surveys   = $wpdb->prefix . 'gcrev_surveys';

        $wpdb->delete($t_questions, ['id' => $question_id, 'survey_id' => $survey_id], ['%d', '%d']);
        $wpdb->update($t_surveys, ['updated_at' => current_time('mysql')], ['id' => $survey_id], ['%s'], ['%d']);

        return new \WP_REST_Response(['success' => true], 200);
    }

    /**
     * POST survey/question/reorder — 質問並び順一括更新
     */
    public function rest_survey_question_reorder(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $params    = $request->get_json_params();
        $survey_id = absint($params['survey_id'] ?? 0);
        $order     = $params['order'] ?? [];
        $user_id   = get_current_user_id();

        if (!$this->can_access_survey($survey_id, $user_id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        if (!is_array($order)) {
            return new \WP_REST_Response(['success' => false, 'message' => '並び順データが不正です。'], 400);
        }

        $t_questions = $wpdb->prefix . 'gcrev_survey_questions';
        $t_surveys   = $wpdb->prefix . 'gcrev_surveys';

        foreach ($order as $item) {
            $qid   = absint($item['id'] ?? 0);
            $sort   = absint($item['sort_order'] ?? 0);
            if ($qid <= 0) continue;
            $wpdb->update(
                $t_questions,
                ['sort_order' => $sort],
                ['id' => $qid, 'survey_id' => $survey_id],
                ['%d'],
                ['%d', '%d']
            );
        }

        $wpdb->update($t_surveys, ['updated_at' => current_time('mysql')], ['id' => $survey_id], ['%s'], ['%d']);

        return new \WP_REST_Response(['success' => true], 200);
    }

    // =========================================================
    // アンケート回答管理 REST API
    // =========================================================

    /**
     * GET survey/responses — 回答一覧（フィルタ・ページネーション）
     */
    public function rest_survey_responses(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $user_id   = get_current_user_id();
        $is_admin  = current_user_can('manage_options');
        $t_resp    = $wpdb->prefix . 'gcrev_survey_responses';
        $t_surv    = $wpdb->prefix . 'gcrev_surveys';
        $t_ai_gen  = $wpdb->prefix . 'gcrev_survey_ai_generations';

        $survey_id = absint($request->get_param('survey_id'));
        $status    = sanitize_text_field($request->get_param('status') ?? '');
        $ai_status = sanitize_text_field($request->get_param('ai_status') ?? '');
        $keyword   = sanitize_text_field($request->get_param('keyword') ?? '');
        $date_from = sanitize_text_field($request->get_param('date_from') ?? '');
        $date_to   = sanitize_text_field($request->get_param('date_to') ?? '');
        $page      = max(1, absint($request->get_param('page') ?? 1));
        $per_page  = min(100, max(1, absint($request->get_param('per_page') ?? 20)));

        $where = [];
        $args  = [];

        if ( ! $is_admin ) {
            $where[] = 's.user_id = %d';
            $args[]  = $user_id;
        }
        if ($survey_id > 0) {
            $where[] = 'r.survey_id = %d';
            $args[]  = $survey_id;
        }
        if (in_array($status, ['new', 'reviewed', 'utilized'], true)) {
            $where[] = 'r.status = %s';
            $args[]  = $status;
        }
        if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $where[] = 'r.created_at >= %s';
            $args[]  = $date_from . ' 00:00:00';
        }
        if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $where[] = 'r.created_at <= %s';
            $args[]  = $date_to . ' 23:59:59';
        }
        if ($keyword !== '') {
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            $where[] = '(r.respondent_name LIKE %s OR r.admin_notes LIKE %s)';
            $args[]  = $like;
            $args[]  = $like;
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $count_sql = "SELECT COUNT(DISTINCT r.id) FROM {$t_resp} r INNER JOIN {$t_surv} s ON r.survey_id = s.id {$where_sql}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, ...$args)) : $wpdb->get_var($count_sql));

        // Data
        $offset   = ($page - 1) * $per_page;
        $data_sql = "SELECT r.*, s.title AS survey_title,
                     (SELECT COUNT(*) FROM {$t_ai_gen} g WHERE g.response_id = r.id) AS ai_count
                     FROM {$t_resp} r
                     INNER JOIN {$t_surv} s ON r.survey_id = s.id
                     {$where_sql}
                     ORDER BY r.created_at DESC
                     LIMIT %d OFFSET %d";
        $data_args = array_merge($args, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($data_sql, ...$data_args));

        // ai_status フィルタ（後処理）
        if ($ai_status !== '') {
            $rows = array_values(array_filter($rows, function($r) use ($ai_status) {
                if ($ai_status === 'has_generated') return (int) $r->ai_count > 0;
                if ($ai_status === 'none') return (int) $r->ai_count === 0;
                return true;
            }));
        }

        $responses = array_map(function($r) {
            return [
                'id'              => (int) $r->id,
                'survey_id'       => (int) $r->survey_id,
                'survey_title'    => $r->survey_title,
                'respondent_name' => $r->respondent_name,
                'status'          => $r->status ?: 'new',
                'consent_ai'      => (bool) $r->consent_ai,
                'consent_review'  => (bool) $r->consent_review,
                'ai_count'        => (int) $r->ai_count,
                'created_at'      => $r->created_at,
            ];
        }, $rows);

        return new \WP_REST_Response([
            'responses'   => $responses,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => max(1, (int) ceil($total / $per_page)),
        ], 200);
    }

    /**
     * GET survey/response/detail — 回答詳細（回答+質問+AI生成文）
     */
    public function rest_survey_response_detail(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $response_id = absint($request->get_param('response_id'));
        $user_id     = get_current_user_id();

        $t_resp    = $wpdb->prefix . 'gcrev_survey_responses';
        $t_surv    = $wpdb->prefix . 'gcrev_surveys';
        $t_ans     = $wpdb->prefix . 'gcrev_survey_response_answers';
        $t_q       = $wpdb->prefix . 'gcrev_survey_questions';
        $t_ai_gen  = $wpdb->prefix . 'gcrev_survey_ai_generations';

        $resp = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, s.title AS survey_title FROM {$t_resp} r INNER JOIN {$t_surv} s ON r.survey_id = s.id WHERE r.id = %d",
            $response_id
        ));
        if (!$resp) {
            return new \WP_REST_Response(['success' => false, 'message' => '回答が見つかりません。'], 404);
        }
        if (!$this->can_access_survey((int) $resp->survey_id, $user_id)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        // 質問 + 回答
        $answers = $wpdb->get_results($wpdb->prepare(
            "SELECT a.question_id, a.answer, q.label AS question_label, q.type AS question_type
             FROM {$t_ans} a
             LEFT JOIN {$t_q} q ON a.question_id = q.id
             WHERE a.response_id = %d
             ORDER BY q.sort_order ASC, a.id ASC",
            $response_id
        ));

        $answer_list = array_map(function($a) {
            $answer_val = $a->answer;
            $decoded = json_decode($answer_val, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $answer_val = $decoded;
            }
            return [
                'question_id'    => (int) $a->question_id,
                'question_label' => $a->question_label ?: '(削除された質問)',
                'question_type'  => $a->question_type ?: 'text',
                'answer'         => $answer_val,
            ];
        }, $answers);

        // AI生成文
        $ai_gens = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t_ai_gen} WHERE response_id = %d ORDER BY created_at DESC",
            $response_id
        ));
        $ai_list = array_map(function($g) {
            return [
                'id'             => (int) $g->id,
                'generated_text' => $g->generated_text,
                'review_type'    => $g->review_type,
                'version'        => (int) $g->version,
                'status'         => $g->status,
                'generation_params' => $g->generation_params ? json_decode($g->generation_params, true) : null,
                'created_at'     => $g->created_at,
            ];
        }, $ai_gens);

        return new \WP_REST_Response([
            'success'  => true,
            'response' => [
                'id'              => (int) $resp->id,
                'survey_id'       => (int) $resp->survey_id,
                'survey_title'    => $resp->survey_title,
                'respondent_name' => $resp->respondent_name,
                'status'          => $resp->status ?: 'new',
                'consent_ai'      => (bool) $resp->consent_ai,
                'consent_review'  => (bool) $resp->consent_review,
                'admin_notes'     => $resp->admin_notes ?: '',
                'created_at'      => $resp->created_at,
            ],
            'answers'      => $answer_list,
            'ai_generations' => $ai_list,
        ], 200);
    }

    /**
     * POST survey/response/status — 回答ステータス更新
     */
    public function rest_survey_response_status(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $params      = $request->get_json_params();
        $response_id = absint($params['response_id'] ?? 0);
        $new_status  = sanitize_text_field($params['status'] ?? '');
        $user_id     = get_current_user_id();

        if ( ! in_array($new_status, ['new', 'reviewed', 'utilized'], true) ) {
            return new \WP_REST_Response(['success' => false, 'message' => '無効なステータスです。'], 400);
        }

        $t_resp = $wpdb->prefix . 'gcrev_survey_responses';
        $survey_id = (int) $wpdb->get_var($wpdb->prepare("SELECT survey_id FROM {$t_resp} WHERE id = %d", $response_id));
        if ( ! $this->can_access_survey($survey_id, $user_id) ) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        $wpdb->update($t_resp, ['status' => $new_status], ['id' => $response_id], ['%s'], ['%d']);

        return new \WP_REST_Response(['success' => true], 200);
    }

    /**
     * POST survey/response/notes — 管理メモ更新
     */
    public function rest_survey_response_notes(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $params      = $request->get_json_params();
        $response_id = absint($params['response_id'] ?? 0);
        $notes       = sanitize_textarea_field($params['notes'] ?? '');
        $user_id     = get_current_user_id();

        $t_resp = $wpdb->prefix . 'gcrev_survey_responses';
        $survey_id = (int) $wpdb->get_var($wpdb->prepare("SELECT survey_id FROM {$t_resp} WHERE id = %d", $response_id));
        if ( ! $this->can_access_survey($survey_id, $user_id) ) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        $wpdb->update($t_resp, ['admin_notes' => $notes], ['id' => $response_id], ['%s'], ['%d']);

        return new \WP_REST_Response(['success' => true], 200);
    }

    // =========================================================
    // アンケートAI生成管理 REST API
    // =========================================================

    /**
     * POST survey/ai-generate — 既存回答からAI口コミ文を再生成
     */
    public function rest_survey_ai_generate(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $params      = $request->get_json_params();
        $response_id = absint($params['response_id'] ?? 0);
        $tone        = sanitize_text_field($params['tone'] ?? '');
        $emphasis    = sanitize_text_field($params['emphasis'] ?? '');
        $user_id     = get_current_user_id();

        $t_resp   = $wpdb->prefix . 'gcrev_survey_responses';
        $t_ans    = $wpdb->prefix . 'gcrev_survey_response_answers';
        $t_q      = $wpdb->prefix . 'gcrev_survey_questions';
        $t_ai_gen = $wpdb->prefix . 'gcrev_survey_ai_generations';

        $resp = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_resp} WHERE id = %d", $response_id));
        if ( ! $resp ) {
            return new \WP_REST_Response(['success' => false, 'message' => '回答が見つかりません。'], 404);
        }
        if ( ! $this->can_access_survey((int) $resp->survey_id, $user_id) ) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        // 回答データを再構築
        $raw_answers = $wpdb->get_results($wpdb->prepare(
            "SELECT a.answer, q.label AS question FROM {$t_ans} a LEFT JOIN {$t_q} q ON a.question_id = q.id WHERE a.response_id = %d ORDER BY q.sort_order ASC",
            $response_id
        ));

        $answers = [];
        foreach ($raw_answers as $ra) {
            $answer = $ra->answer;
            $decoded = json_decode($answer, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $answer = $decoded;
            }
            $answers[] = ['question' => $ra->question ?: '', 'answer' => $answer];
        }

        $answer_text = $this->format_review_answers($answers);
        if (empty($answer_text)) {
            return new \WP_REST_Response(['success' => false, 'message' => '有効な回答データがありません。'], 400);
        }

        $business_name = function_exists('gcrev_get_business_name')
            ? gcrev_get_business_name((int) $resp->user_id)
            : (get_user_meta((int) $resp->user_id, 'gcrev_business_name', true) ?: '');

        $prompt = $this->build_review_prompt($answer_text, $business_name);

        // トーン・強調指定がある場合、プロンプトに追加
        if ($tone || $emphasis) {
            $extra_instructions = "\n\n## 追加指示\n";
            if ($tone) $extra_instructions .= "- 口調: {$tone}\n";
            if ($emphasis) $extra_instructions .= "- 強調ポイント: {$emphasis}\n";
            $prompt .= $extra_instructions;
        }

        try {
            $raw_response = $this->ai->call_gemini_api($prompt);
            $parsed = $this->parse_review_response($raw_response);

            if ($parsed === null) {
                file_put_contents('/tmp/gcrev_review_debug.log',
                    date('Y-m-d H:i:s') . " AI re-generate parse failed. raw=" . substr($raw_response, 0, 500) . "\n",
                    FILE_APPEND
                );
                return new \WP_REST_Response(['success' => false, 'message' => '口コミ案の生成に失敗しました。'], 500);
            }

            $now = current_time('mysql');
            $gen_params = wp_json_encode([
                'tone'     => $tone,
                'emphasis' => $emphasis,
                'model'    => $this->config->get_gemini_model(),
            ], JSON_UNESCAPED_UNICODE);

            $results = [];
            foreach (['short' => 'short_review', 'normal' => 'normal_review'] as $type => $key) {
                if (empty($parsed[$key])) continue;
                $max_ver = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(MAX(version), 0) FROM {$t_ai_gen} WHERE response_id = %d AND review_type = %s",
                    $response_id, $type
                ));
                $wpdb->insert($t_ai_gen, [
                    'response_id'      => $response_id,
                    'survey_id'        => (int) $resp->survey_id,
                    'user_id'          => (int) $resp->user_id,
                    'generated_text'   => $parsed[$key],
                    'review_type'      => $type,
                    'version'          => $max_ver + 1,
                    'status'           => 'generated',
                    'generation_params' => $gen_params,
                    'created_at'       => $now,
                ], ['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']);
                $results[] = [
                    'id'             => (int) $wpdb->insert_id,
                    'review_type'    => $type,
                    'generated_text' => $parsed[$key],
                    'version'        => $max_ver + 1,
                ];
            }

            return new \WP_REST_Response(['success' => true, 'generations' => $results], 200);

        } catch (\Exception $e) {
            file_put_contents('/tmp/gcrev_review_debug.log',
                date('Y-m-d H:i:s') . " AI re-generate error: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return new \WP_REST_Response(['success' => false, 'message' => '口コミ案の生成に失敗しました。'], 500);
        }
    }

    /**
     * GET survey/ai-generations — AI生成履歴一覧
     */
    public function rest_survey_ai_generations(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $user_id   = get_current_user_id();
        $is_admin  = current_user_can('manage_options');
        $t_ai_gen  = $wpdb->prefix . 'gcrev_survey_ai_generations';
        $t_surv    = $wpdb->prefix . 'gcrev_surveys';
        $t_resp    = $wpdb->prefix . 'gcrev_survey_responses';

        $survey_id = absint($request->get_param('survey_id'));
        $status    = sanitize_text_field($request->get_param('status') ?? '');
        $date_from = sanitize_text_field($request->get_param('date_from') ?? '');
        $date_to   = sanitize_text_field($request->get_param('date_to') ?? '');
        $page      = max(1, absint($request->get_param('page') ?? 1));
        $per_page  = min(100, max(1, absint($request->get_param('per_page') ?? 20)));

        $where = [];
        $args  = [];

        if ( ! $is_admin ) {
            $where[] = 'g.user_id = %d';
            $args[]  = $user_id;
        }
        if ($survey_id > 0) {
            $where[] = 'g.survey_id = %d';
            $args[]  = $survey_id;
        }
        if (in_array($status, ['generated', 'adopted', 'rejected', 'posted'], true)) {
            $where[] = 'g.status = %s';
            $args[]  = $status;
        }
        if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $where[] = 'g.created_at >= %s';
            $args[]  = $date_from . ' 00:00:00';
        }
        if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $where[] = 'g.created_at <= %s';
            $args[]  = $date_to . ' 23:59:59';
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count_sql = "SELECT COUNT(*) FROM {$t_ai_gen} g {$where_sql}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, ...$args)) : $wpdb->get_var($count_sql));

        $offset   = ($page - 1) * $per_page;
        $data_sql = "SELECT g.*, s.title AS survey_title, r.respondent_name
                     FROM {$t_ai_gen} g
                     INNER JOIN {$t_surv} s ON g.survey_id = s.id
                     LEFT JOIN {$t_resp} r ON g.response_id = r.id
                     {$where_sql}
                     ORDER BY g.created_at DESC
                     LIMIT %d OFFSET %d";
        $data_args = array_merge($args, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($data_sql, ...$data_args));

        $generations = array_map(function($g) {
            return [
                'id'              => (int) $g->id,
                'response_id'     => (int) $g->response_id,
                'survey_id'       => (int) $g->survey_id,
                'survey_title'    => $g->survey_title,
                'respondent_name' => $g->respondent_name ?: '',
                'generated_text'  => $g->generated_text,
                'review_type'     => $g->review_type,
                'version'         => (int) $g->version,
                'status'          => $g->status,
                'created_at'      => $g->created_at,
            ];
        }, $rows);

        return new \WP_REST_Response([
            'generations' => $generations,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => max(1, (int) ceil($total / $per_page)),
        ], 200);
    }

    /**
     * POST survey/ai-generation/status — AI生成文ステータス変更
     */
    public function rest_survey_ai_generation_status(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $params        = $request->get_json_params();
        $generation_id = absint($params['generation_id'] ?? 0);
        $new_status    = sanitize_text_field($params['status'] ?? '');
        $user_id       = get_current_user_id();

        if ( ! in_array($new_status, ['generated', 'adopted', 'rejected', 'posted'], true) ) {
            return new \WP_REST_Response(['success' => false, 'message' => '無効なステータスです。'], 400);
        }

        $t_ai_gen = $wpdb->prefix . 'gcrev_survey_ai_generations';
        $gen = $wpdb->get_row($wpdb->prepare("SELECT survey_id FROM {$t_ai_gen} WHERE id = %d", $generation_id));
        if ( ! $gen || ! $this->can_access_survey((int) $gen->survey_id, $user_id) ) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        $wpdb->update($t_ai_gen, ['status' => $new_status], ['id' => $generation_id], ['%s'], ['%d']);

        return new \WP_REST_Response(['success' => true], 200);
    }

    // =========================================================
    // アンケート集計・分析 REST API
    // =========================================================

    /**
     * GET survey/analytics — 集計データ
     */
    public function rest_survey_analytics(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $user_id   = get_current_user_id();
        $survey_id = absint($request->get_param('survey_id'));
        $date_from = sanitize_text_field($request->get_param('date_from') ?? '');
        $date_to   = sanitize_text_field($request->get_param('date_to') ?? '');

        if ($survey_id <= 0) {
            return new \WP_REST_Response(['success' => false, 'message' => 'survey_id は必須です。'], 400);
        }
        if ( ! $this->can_access_survey($survey_id, $user_id) ) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        $t_resp   = $wpdb->prefix . 'gcrev_survey_responses';
        $t_ans    = $wpdb->prefix . 'gcrev_survey_response_answers';
        $t_q      = $wpdb->prefix . 'gcrev_survey_questions';
        $t_ai_gen = $wpdb->prefix . 'gcrev_survey_ai_generations';

        $date_where = '';
        $date_args  = [$survey_id];
        if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
            $date_where .= ' AND r.created_at >= %s';
            $date_args[] = $date_from . ' 00:00:00';
        }
        if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            $date_where .= ' AND r.created_at <= %s';
            $date_args[] = $date_to . ' 23:59:59';
        }

        // サマリ
        $total_responses = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_resp} r WHERE r.survey_id = %d {$date_where}",
            ...$date_args
        ));
        $by_status = $wpdb->get_results($wpdb->prepare(
            "SELECT r.status, COUNT(*) AS cnt FROM {$t_resp} r WHERE r.survey_id = %d {$date_where} GROUP BY r.status",
            ...$date_args
        ));
        $status_map = [];
        foreach ($by_status as $row) {
            $status_map[$row->status ?: 'new'] = (int) $row->cnt;
        }

        // AI統計
        $ai_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT g.status, COUNT(*) AS cnt FROM {$t_ai_gen} g WHERE g.survey_id = %d GROUP BY g.status",
            $survey_id
        ));
        $ai_map = [];
        $ai_total = 0;
        foreach ($ai_stats as $row) {
            $ai_map[$row->status] = (int) $row->cnt;
            $ai_total += (int) $row->cnt;
        }

        // 質問別回答分布
        $questions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t_q} WHERE survey_id = %d AND is_active = 1 ORDER BY sort_order ASC",
            $survey_id
        ));

        $question_stats = [];
        foreach ($questions as $q) {
            $answers = $wpdb->get_col($wpdb->prepare(
                "SELECT a.answer FROM {$t_ans} a INNER JOIN {$t_resp} r ON a.response_id = r.id WHERE a.question_id = %d AND r.survey_id = %d {$date_where}",
                ...array_merge([(int) $q->id], $date_args)
            ));

            $dist = [];
            $total_ans = count($answers);
            if (in_array($q->type, ['checkbox', 'radio'], true)) {
                foreach ($answers as $ans_raw) {
                    $decoded = json_decode($ans_raw, true);
                    $values = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [$ans_raw];
                    foreach ($values as $v) {
                        $v = trim($v);
                        if ($v === '') continue;
                        $dist[$v] = ($dist[$v] ?? 0) + 1;
                    }
                }
                arsort($dist);
            }

            $question_stats[] = [
                'question_id'   => (int) $q->id,
                'label'         => $q->label,
                'type'          => $q->type,
                'total_answers' => $total_ans,
                'distribution'  => $dist,
            ];
        }

        // 回答推移（月別）
        $trend = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(r.created_at, '%%Y-%%m') AS month, COUNT(*) AS cnt
             FROM {$t_resp} r WHERE r.survey_id = %d {$date_where}
             GROUP BY month ORDER BY month ASC",
            ...$date_args
        ));

        return new \WP_REST_Response([
            'success' => true,
            'summary' => [
                'total_responses' => $total_responses,
                'by_status'       => $status_map,
                'ai_total'        => $ai_total,
                'ai_by_status'    => $ai_map,
            ],
            'questions' => $question_stats,
            'trend'     => array_map(function($t) { return ['month' => $t->month, 'count' => (int) $t->cnt]; }, $trend),
        ], 200);
    }

    /**
     * GET survey/analysis — 分析データ（Gemini AIで分析）
     */
    public function rest_survey_analysis(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $user_id   = get_current_user_id();
        $survey_id = absint($request->get_param('survey_id'));

        if ($survey_id <= 0) {
            return new \WP_REST_Response(['success' => false, 'message' => 'survey_id は必須です。'], 400);
        }
        if ( ! $this->can_access_survey($survey_id, $user_id) ) {
            return new \WP_REST_Response(['success' => false, 'message' => 'アクセス権がありません。'], 403);
        }

        $t_resp   = $wpdb->prefix . 'gcrev_survey_responses';
        $t_ans    = $wpdb->prefix . 'gcrev_survey_response_answers';
        $t_q      = $wpdb->prefix . 'gcrev_survey_questions';
        $t_ai_gen = $wpdb->prefix . 'gcrev_survey_ai_generations';

        // キャッシュチェック
        $cache_key = "gcrev_survey_analysis_{$survey_id}";
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return new \WP_REST_Response(['success' => true, 'analysis' => $cached, 'cached' => true], 200);
        }

        // 回答データ収集
        $response_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t_resp} WHERE survey_id = %d", $survey_id
        ));

        if ($response_count === 0) {
            return new \WP_REST_Response(['success' => true, 'analysis' => null, 'message' => '回答データがまだありません。'], 200);
        }

        // 全回答テキストを集約
        $all_answers = $wpdb->get_results($wpdb->prepare(
            "SELECT q.label AS question, a.answer, q.type AS question_type
             FROM {$t_ans} a
             INNER JOIN {$t_resp} r ON a.response_id = r.id
             LEFT JOIN {$t_q} q ON a.question_id = q.id
             WHERE r.survey_id = %d
             ORDER BY q.sort_order ASC",
            $survey_id
        ));

        // 質問ごとにグルーピング
        $grouped = [];
        foreach ($all_answers as $row) {
            $q_label = $row->question ?: '(不明)';
            if (!isset($grouped[$q_label])) {
                $grouped[$q_label] = ['type' => $row->question_type, 'answers' => []];
            }
            $decoded = json_decode($row->answer, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $grouped[$q_label]['answers'][] = implode('、', $decoded);
            } else {
                $grouped[$q_label]['answers'][] = $row->answer;
            }
        }

        // AI生成統計
        $ai_adoption = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS cnt FROM {$t_ai_gen} WHERE survey_id = %d GROUP BY status",
            $survey_id
        ));

        // プロンプト構築
        $summary_text = "アンケート回答数: {$response_count}件\n\n";
        foreach ($grouped as $q_label => $data) {
            $sample = array_slice($data['answers'], 0, 50);
            $summary_text .= "【{$q_label}】（{$data['type']}型, 回答数: " . count($data['answers']) . "件）\n";
            $summary_text .= implode("\n", $sample) . "\n\n";
        }

        $ai_stats_text = '';
        foreach ($ai_adoption as $row) {
            $ai_stats_text .= "{$row->status}: {$row->cnt}件 / ";
        }

        $prompt = <<<PROMPT
あなたは口コミ・アンケート分析の専門家です。
以下のアンケート回答データを分析し、ビジネス改善に役立つインサイトを提供してください。

## アンケート回答データ
{$summary_text}

## AI口コミ生成統計
{$ai_stats_text}

## 分析して欲しい内容
1. よく選ばれている回答の傾向（satisfaction_trends）
2. 顧客が満足しているポイントTOP5（satisfaction_points）
3. 改善の余地がありそうなポイント（improvement_areas）
4. 自由記述でよく出てくるキーワードTOP10（frequent_keywords）
5. 口コミとして使いやすい内容の候補3つ（review_candidates）
6. 総合コメント（overall_comment）

## 出力形式
以下のJSON形式のみを出力してください。
```json
{
  "satisfaction_trends": ["傾向1", "傾向2", ...],
  "satisfaction_points": ["ポイント1", "ポイント2", ...],
  "improvement_areas": ["改善点1", "改善点2", ...],
  "frequent_keywords": ["キーワード1", "キーワード2", ...],
  "review_candidates": ["候補文1", "候補文2", "候補文3"],
  "overall_comment": "総合的な分析コメント"
}
```
PROMPT;

        try {
            $raw = $this->ai->call_gemini_api($prompt);
            $cleaned = preg_replace('/```(?:json)?\s*/i', '', $raw);
            $cleaned = preg_replace('/```\s*/', '', $cleaned);
            $cleaned = trim($cleaned);
            $analysis = json_decode($cleaned, true);

            if (!is_array($analysis)) {
                file_put_contents('/tmp/gcrev_review_debug.log',
                    date('Y-m-d H:i:s') . " analysis parse failed: " . substr($raw, 0, 500) . "\n",
                    FILE_APPEND
                );
                return new \WP_REST_Response(['success' => false, 'message' => '分析結果の解析に失敗しました。'], 500);
            }

            // 1時間キャッシュ
            set_transient($cache_key, $analysis, HOUR_IN_SECONDS);

            return new \WP_REST_Response(['success' => true, 'analysis' => $analysis, 'cached' => false], 200);

        } catch (\Exception $e) {
            file_put_contents('/tmp/gcrev_review_debug.log',
                date('Y-m-d H:i:s') . " analysis error: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return new \WP_REST_Response(['success' => false, 'message' => '分析の実行に失敗しました。'], 500);
        }
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

