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
    public function __construct(
        string $service_account_path = '/home/gcrev/secrets/gcrev-insight-fd0cc85fabe2.json'
    ) {
        $this->service_account_path = $service_account_path;
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

    /**
     * サービスアカウント JSON から GCP プロジェクト ID を取得
     *
     * @return string プロジェクトID（取得できない場合は空文字）
     */
    public function get_gcp_project_id(): string {

        if (!is_readable($this->service_account_path)) return '';

        $json = file_get_contents($this->service_account_path);
        if ($json === false) return '';

        $data = json_decode($json, true);
        if (!is_array($data)) return '';

        return (string)($data['project_id'] ?? '');
    }

    /**
     * Gemini API のロケーション（リージョン）を返す
     * 環境変数 GEMINI_LOCATION が設定されていればそれを使用
     *
     * @return string
     */
    public function get_gemini_location(): string {
        $loc = getenv('GEMINI_LOCATION');
        return ($loc !== false && $loc !== '') ? (string)$loc : 'us-central1';
    }

    /**
     * Gemini のモデル名を返す
     * 環境変数 GEMINI_MODEL が設定されていればそれを使用
     *
     * @return string
     */
    public function get_gemini_model(): string {
        $model = getenv('GEMINI_MODEL');
        return ($model !== false && $model !== '') ? (string)$model : 'gemini-2.5-flash';
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
