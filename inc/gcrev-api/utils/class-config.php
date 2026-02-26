<?php
// FILE: inc/gcrev-api/utils/class-config.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Config') ) { return; }

/**
 * Gcrev_Config
 *
 * 認証パス・ユーザー設定・GCP設定など、
 * 全モジュール共通で参照される「設定・認証情報」を一元管理する。
 *
 * - service_account_path の保持
 * - ユーザーごとの GA4 / GSC 設定取得
 * - REST権限チェック
 * - GCPプロジェクトID / Gemini ロケーション / モデル名の取得
 *   （API通信そのものは含まない。設定値の参照のみ）
 *
 * Dev/Prod 分離用の wp-config.php 定数:
 *   GCREV_SA_PATH           … サービスアカウント JSON パス（必須）
 *   GCREV_GCP_PROJECT_ID    … GCP プロジェクトID（省略時は SA JSON から自動取得）
 *   GCREV_GCP_LOCATION      … Vertex AI リージョン（省略時は GEMINI_LOCATION env → 'us-central1'）
 *
 * @package GCREV_INSIGHT
 * @since   2.0.0
 */
class Gcrev_Config {

    // =========================================================
    // プロパティ
    // =========================================================

    /**
     * Google サービスアカウント JSON のファイルパス
     *
     * @var string
     */
    private string $service_account_path;

    // =========================================================
    // 将来用：定数格納エリア
    // 各モジュール固有の定数は各モジュールに置くが、
    // 複数モジュールが共有する定数はここに集約する。
    // =========================================================

    // （現時点では共有定数なし — 必要に応じて追加）

    // =========================================================
    // コンストラクタ
    // =========================================================

    /**
     * @param string $service_account_path サービスアカウント JSON のパス（省略時はデフォルト）
     */
    /**
     * デフォルトのサービスアカウントパス
     *
     * 移行時は wp-config.php に define('GCREV_SA_PATH', '/path/to/sa.json'); を定義すること。
     * 空文字にしておくことで、定数未設定時にハードコードパスへ暗黙フォールバックするのを防ぐ。
     */
    private const DEFAULT_SA_PATH = '';

    /** SA ファイルが見つからなかった場合の警告フラグ */
    private bool $sa_file_missing = false;

    public function __construct( string $service_account_path = '' ) {
        if ( $service_account_path !== '' ) {
            $this->service_account_path = $service_account_path;
        } elseif ( defined( 'GCREV_SA_PATH' ) && GCREV_SA_PATH !== '' ) {
            $this->service_account_path = GCREV_SA_PATH;
        } else {
            $this->service_account_path = self::DEFAULT_SA_PATH;
        }

        // SA ファイルの存在チェック（パスが空、またはファイルが存在しない場合に警告）
        if ( $this->service_account_path === '' || ! file_exists( $this->service_account_path ) ) {
            $this->sa_file_missing = true;

            $detail = $this->service_account_path === ''
                ? 'GCREV_SA_PATH が未定義です'
                : 'ファイルが見つかりません: ' . $this->service_account_path;

            error_log( sprintf(
                '[GCREV] Google サービスアカウント JSON が利用できません（%s）。wp-config.php に define(\'GCREV_SA_PATH\', \'/path/to/sa.json\'); を追加してください。',
                $detail
            ) );

            // 管理画面に警告を表示（admin_notices は複数回呼んでも安全）
            if ( is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
                add_action( 'admin_notices', static function () use ( $detail ) {
                    printf(
                        '<div class="notice notice-error"><p><strong>[GCREV]</strong> Google サービスアカウント JSON が利用できません（%s）。<br><code>wp-config.php</code> に <code>define(\'GCREV_SA_PATH\', \'/path/to/sa.json\');</code> を追加してください。</p></div>',
                        esc_html( $detail )
                    );
                } );
            }
        }
    }

    /**
     * SA ファイルが利用可能かどうかを返す
     *
     * @return bool true = 利用可能 / false = ファイルが見つからない
     */
    public function is_sa_available(): bool {
        return ! $this->sa_file_missing;
    }

    // =========================================================
    // サービスアカウントパス
    // =========================================================

    /**
     * サービスアカウント JSON のパスを返す
     *
     * @return string
     */
    public function get_service_account_path(): string {
        return $this->service_account_path;
    }

    // =========================================================
    // REST API 権限チェック
    // =========================================================

    /**
     * REST エンドポイントの permission_callback 用
     *
     * @return bool
     */
    public function check_permission(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        // 管理者は常に許可
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        // 決済ステータスが active でなければ拒否
        if ( function_exists( 'gcrev_is_payment_active' ) ) {
            return gcrev_is_payment_active();
        }
        // 決済チェック関数が未定義の場合は安全側に倒す（fail-closed）
        return false;
    }

    // =========================================================
    // ユーザー設定の取得
    // =========================================================

    /**
     * ユーザーの GA4 プロパティID / GSC サイトURL を取得する
     *
     * @param  int   $user_id WordPress ユーザーID
     * @return array{ga4_id: string, gsc_url: string}
     * @throws \RuntimeException 設定が不足している場合
     */
    public function get_user_config(int $user_id): array {

        $ga4_id  = trim((string) get_user_meta($user_id, 'ga4_property_id', true));
        $gsc_url = trim((string) get_user_meta($user_id, 'weisite_url', true));
        $gsc_url = $gsc_url !== '' ? trailingslashit(esc_url_raw($gsc_url)) : '';

        if ($ga4_id === '' || $gsc_url === '') {
            throw new \RuntimeException(
                'GA4プロパティID または WEBサイトURL（Search Console）が未設定です。プロフィールから設定してください。'
            );
        }

        return [
            'ga4_id'  => $ga4_id,
            'gsc_url' => $gsc_url,
        ];
    }

    // =========================================================
    // GCP / Gemini 設定参照（通信は含まない）
    // =========================================================

    /** project_id のインメモリキャッシュ（SA JSON を毎回読まないため） */
    private string $cached_project_id = '';
    private bool   $project_id_resolved = false;

    /**
     * GCP プロジェクト ID を取得する
     *
     * 優先順位:
     *   1. wp-config.php 定数 GCREV_GCP_PROJECT_ID（Dev/Prod 分離用）
     *   2. サービスアカウント JSON の project_id フィールド
     *
     * @return string プロジェクトID（取得できない場合は空文字）
     */
    public function get_gcp_project_id(): string {

        // インメモリキャッシュ
        if ( $this->project_id_resolved ) {
            return $this->cached_project_id;
        }
        $this->project_id_resolved = true;

        // 1. wp-config.php 定数を最優先
        if ( defined( 'GCREV_GCP_PROJECT_ID' ) && GCREV_GCP_PROJECT_ID !== '' ) {
            $this->cached_project_id = (string) GCREV_GCP_PROJECT_ID;
            return $this->cached_project_id;
        }

        // 2. SA JSON からフォールバック
        if ( is_readable( $this->service_account_path ) ) {
            $json = file_get_contents( $this->service_account_path );
            if ( $json !== false ) {
                $data = json_decode( $json, true );
                if ( is_array( $data ) && isset( $data['project_id'] ) ) {
                    $this->cached_project_id = (string) $data['project_id'];
                }
            }
        }

        return $this->cached_project_id;
    }

    /**
     * GCP ロケーション（Vertex AI リージョン）を返す
     *
     * 優先順位:
     *   1. wp-config.php 定数 GCREV_GCP_LOCATION（Dev/Prod 分離用）
     *   2. 環境変数 GEMINI_LOCATION
     *   3. デフォルト 'us-central1'
     *
     * @return string
     */
    public function get_gemini_location(): string {
        // 1. wp-config.php 定数を最優先
        if ( defined( 'GCREV_GCP_LOCATION' ) && GCREV_GCP_LOCATION !== '' ) {
            return (string) GCREV_GCP_LOCATION;
        }
        // 2. 環境変数
        $loc = getenv( 'GEMINI_LOCATION' );
        return ( $loc !== false && $loc !== '' ) ? (string) $loc : 'us-central1';
    }

    /**
     * Gemini のモデル名を返す
     * 環境変数 GEMINI_MODEL が設定されていればそれを使用
     *
     * @return string
     */
    public function get_gemini_model(): string {
        $model = getenv( 'GEMINI_MODEL' );
        return ( $model !== false && $model !== '' ) ? (string) $model : 'gemini-2.5-flash';
    }

    /**
     * Google Business Profile OAuth の Client ID を返す
     * wp-config.php の定数があればそれを優先し、なければ wp_options を参照
     */
    public function get_gbp_client_id(): string {
        if (defined('GCREV_GBP_CLIENT_ID') && GCREV_GBP_CLIENT_ID !== '') {
            return (string) GCREV_GBP_CLIENT_ID;
        }
        return (string) get_option('gcrev_gbp_client_id', '');
    }

    /**
     * Google Business Profile OAuth の Client Secret を返す
     * wp-config.php の定数があればそれを優先し、なければ wp_options を参照
     */
    public function get_gbp_client_secret(): string {
        if (defined('GCREV_GBP_CLIENT_SECRET') && GCREV_GBP_CLIENT_SECRET !== '') {
            return (string) GCREV_GBP_CLIENT_SECRET;
        }
        return (string) get_option('gcrev_gbp_client_secret', '');
    }


    // =========================================================
    // GBP (Google Business Profile) 設定
    // =========================================================

    /**
     * GBP OAuth 設定のデフォルト値
     * wp_options に保存されている値を優先し、未設定時はここの値を返す
     *
     * option_name:
     *   gcrev_gbp_client_id     … GBP OAuth クライアントID
     *   gcrev_gbp_client_secret … GBP OAuth クライアントシークレット
     */
    private const GBP_OPTION_DEFAULTS = [
        'gbp_client_id'     => '',
        'gbp_client_secret' => '',
    ];

    // =========================================================
    // 汎用設定取得
    // =========================================================

    /**
     * 設定値を取得する汎用メソッド
     *
     * 1) GBP系キー → wp_options (gcrev_ プレフィックス付き) から取得
     * 2) 将来的に他の設定キーもここに集約可能
     *
     * @param  string $key     設定キー（例: 'gbp_client_id'）
     * @param  mixed  $default デフォルト値
     * @return mixed
     */
    public function get(string $key, mixed $default = '') {
        // GBP系: wp_options から取得
        if (array_key_exists($key, self::GBP_OPTION_DEFAULTS)) {
            $option_name = 'gcrev_' . $key;
            $value = get_option($option_name, null);
            return ($value !== null && $value !== '') ? $value : $default;
        }

        // 未知のキーはデフォルトを返す
        return $default;
    }
}
