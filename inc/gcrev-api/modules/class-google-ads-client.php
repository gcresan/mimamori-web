<?php
// FILE: inc/gcrev-api/modules/class-google-ads-client.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Google_Ads_Client') ) { return; }

/**
 * Gcrev_Google_Ads_Client
 *
 * Google Ads API 通信クライアント。
 * OAuth2 認証 → REST API 経由で Google Ads API を利用する。
 *
 * 認証: wp-config.php の定数
 *   GOOGLE_ADS_DEVELOPER_TOKEN  … 開発者トークン
 *   GOOGLE_ADS_CLIENT_ID        … OAuth2 クライアントID
 *   GOOGLE_ADS_CLIENT_SECRET    … OAuth2 クライアントシークレット
 *   GOOGLE_ADS_REFRESH_TOKEN    … OAuth2 リフレッシュトークン
 *   GOOGLE_ADS_LOGIN_CUSTOMER_ID … MCC アカウントID
 *   GOOGLE_ADS_CUSTOMER_ID       … 対象広告アカウントID
 *
 * @package Mimamori_Web
 * @since   2.6.0
 */
class Gcrev_Google_Ads_Client {

    /** Google Ads REST API ベース URL */
    private const API_BASE = 'https://googleads.googleapis.com';

    /** API バージョン */
    private const API_VERSION = 'v23';

    /** OAuth2 トークンエンドポイント */
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** HTTP タイムアウト（秒） */
    private const HTTP_TIMEOUT = 30;

    /** デバッグログファイル */
    private const DEBUG_LOG = '/tmp/gcrev_google_ads_debug.log';

    /** アクセストークンの transient キー */
    private const TOKEN_TRANSIENT = 'gcrev_gads_access_token';

    // =========================================================
    // 設定確認
    // =========================================================

    /**
     * API 認証情報が設定されているか
     */
    public static function is_configured(): bool {
        return defined('GOOGLE_ADS_DEVELOPER_TOKEN')
            && defined('GOOGLE_ADS_CLIENT_ID')
            && defined('GOOGLE_ADS_CLIENT_SECRET')
            && defined('GOOGLE_ADS_REFRESH_TOKEN')
            && defined('GOOGLE_ADS_LOGIN_CUSTOMER_ID')
            && defined('GOOGLE_ADS_CUSTOMER_ID')
            && GOOGLE_ADS_DEVELOPER_TOKEN !== ''
            && GOOGLE_ADS_CLIENT_ID !== ''
            && GOOGLE_ADS_CLIENT_SECRET !== ''
            && GOOGLE_ADS_REFRESH_TOKEN !== ''
            && GOOGLE_ADS_LOGIN_CUSTOMER_ID !== ''
            && GOOGLE_ADS_CUSTOMER_ID !== '';
    }

    /**
     * 設定不足の項目一覧を返す
     */
    public static function get_missing_config(): array {
        $required = [
            'GOOGLE_ADS_DEVELOPER_TOKEN',
            'GOOGLE_ADS_CLIENT_ID',
            'GOOGLE_ADS_CLIENT_SECRET',
            'GOOGLE_ADS_REFRESH_TOKEN',
            'GOOGLE_ADS_LOGIN_CUSTOMER_ID',
            'GOOGLE_ADS_CUSTOMER_ID',
        ];
        $missing = [];
        foreach ( $required as $const ) {
            if ( ! defined( $const ) || constant( $const ) === '' ) {
                $missing[] = $const;
            }
        }
        return $missing;
    }

    // =========================================================
    // アクセストークン管理
    // =========================================================

    /**
     * OAuth2 アクセストークンを取得（キャッシュあり）
     *
     * @return string|WP_Error
     */
    public function get_access_token() {
        // transient キャッシュ確認
        $cached = get_transient( self::TOKEN_TRANSIENT );
        if ( $cached !== false ) {
            return $cached;
        }

        return $this->refresh_access_token();
    }

    /**
     * refresh_token を使ってアクセストークンを再取得
     *
     * @return string|WP_Error
     */
    public function refresh_access_token() {
        $this->log( 'refresh_access_token: start' );

        $response = wp_remote_post( self::TOKEN_URL, [
            'timeout' => self::HTTP_TIMEOUT,
            'body'    => [
                'client_id'     => GOOGLE_ADS_CLIENT_ID,
                'client_secret' => GOOGLE_ADS_CLIENT_SECRET,
                'refresh_token' => GOOGLE_ADS_REFRESH_TOKEN,
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if ( is_wp_error( $response ) ) {
            $msg = 'Token refresh failed: ' . $response->get_error_message();
            $this->log( $msg );
            return new \WP_Error( 'token_refresh_failed', $msg );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 || empty( $data['access_token'] ) ) {
            $error_desc = $data['error_description'] ?? ( $data['error'] ?? 'Unknown error' );
            $msg = "Token refresh HTTP {$code}: {$error_desc}";
            $this->log( $msg );
            return new \WP_Error( 'token_refresh_error', $msg );
        }

        $access_token = $data['access_token'];
        $expires_in   = intval( $data['expires_in'] ?? 3500 );

        // 有効期限の 60 秒前にキャッシュ切れにする
        set_transient( self::TOKEN_TRANSIENT, $access_token, max( $expires_in - 60, 60 ) );

        $this->log( 'refresh_access_token: success, expires_in=' . $expires_in );
        return $access_token;
    }

    // =========================================================
    // 疎通確認
    // =========================================================

    /**
     * Google Ads API 疎通テスト
     *
     * 以下を順番に実行:
     *   1. アクセストークン再取得
     *   2. listAccessibleCustomers
     *   3. 対象 customer のアカウント情報取得
     *
     * @return array { success: bool, steps: array, error?: string }
     */
    public function test_connection(): array {
        $steps = [];

        // Step 1: アクセストークン
        $this->log( 'test_connection: step1 — token refresh' );
        $token = $this->refresh_access_token();
        if ( is_wp_error( $token ) ) {
            $steps[] = [
                'step'    => 'token_refresh',
                'success' => false,
                'message' => $token->get_error_message(),
            ];
            return [
                'success' => false,
                'steps'   => $steps,
                'error'   => 'OAuth トークンの更新に失敗しました: ' . $token->get_error_message(),
            ];
        }
        $steps[] = [
            'step'    => 'token_refresh',
            'success' => true,
            'message' => 'アクセストークンの取得に成功しました',
        ];

        // Step 2: listAccessibleCustomers
        $this->log( 'test_connection: step2 — listAccessibleCustomers' );
        $customers_result = $this->list_accessible_customers( $token );
        if ( is_wp_error( $customers_result ) ) {
            $steps[] = [
                'step'    => 'list_accessible_customers',
                'success' => false,
                'message' => $customers_result->get_error_message(),
            ];
            return [
                'success' => false,
                'steps'   => $steps,
                'error'   => 'アクセス可能なアカウント一覧の取得に失敗しました: ' . $customers_result->get_error_message(),
            ];
        }
        $steps[] = [
            'step'    => 'list_accessible_customers',
            'success' => true,
            'message' => 'アクセス可能アカウント: ' . count( $customers_result ) . ' 件',
            'data'    => $customers_result,
        ];

        // Step 3: 対象 customer のアカウント情報取得
        $customer_id = $this->normalize_customer_id( GOOGLE_ADS_CUSTOMER_ID );
        $this->log( "test_connection: step3 — getCustomerInfo for {$customer_id}" );
        $customer_info = $this->get_customer_info( $token, $customer_id );
        if ( is_wp_error( $customer_info ) ) {
            $steps[] = [
                'step'    => 'get_customer_info',
                'success' => false,
                'message' => $customer_info->get_error_message(),
            ];
            return [
                'success' => false,
                'steps'   => $steps,
                'error'   => "対象アカウント ({$customer_id}) の情報取得に失敗しました: " . $customer_info->get_error_message(),
            ];
        }
        $steps[] = [
            'step'    => 'get_customer_info',
            'success' => true,
            'message' => 'アカウント情報を取得しました',
            'data'    => $customer_info,
        ];

        $summary = [
            'login_customer_id'   => $this->normalize_customer_id( GOOGLE_ADS_LOGIN_CUSTOMER_ID ),
            'customer_id'         => $customer_id,
            'customer_name'       => $customer_info['descriptive_name'] ?? '(不明)',
            'currency'            => $customer_info['currency_code'] ?? '',
            'timezone'            => $customer_info['time_zone'] ?? '',
            'accessible_accounts' => count( $customers_result ),
        ];

        $this->log( 'test_connection: all steps passed — ' . wp_json_encode( $summary, JSON_UNESCAPED_UNICODE ) );

        return [
            'success' => true,
            'steps'   => $steps,
            'summary' => $summary,
        ];
    }

    // =========================================================
    // API 呼び出し
    // =========================================================

    /**
     * listAccessibleCustomers — アクセス可能なアカウント一覧
     *
     * @param  string $access_token
     * @return array|WP_Error  customers/XXXXXXXXXX 形式の配列
     */
    public function list_accessible_customers( string $access_token ) {
        $url = self::API_BASE . '/' . self::API_VERSION . '/customers:listAccessibleCustomers';

        $response = wp_remote_get( $url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => $this->build_headers( $access_token ),
        ]);

        if ( is_wp_error( $response ) ) {
            $msg = 'listAccessibleCustomers HTTP error: ' . $response->get_error_message();
            $this->log( $msg );
            return new \WP_Error( 'api_error', $msg );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $error_detail = $this->parse_api_error( $body );
            $msg = "listAccessibleCustomers HTTP {$code}: {$error_detail}";
            $this->log( $msg );
            return new \WP_Error( 'api_error', $msg );
        }

        $data = json_decode( $body, true );
        $resource_names = $data['resourceNames'] ?? [];

        $this->log( 'listAccessibleCustomers: ' . count( $resource_names ) . ' accounts found' );
        return $resource_names;
    }

    /**
     * 対象 customer のアカウント情報を取得（GAQL query）
     *
     * @param  string $access_token
     * @param  string $customer_id  ハイフンなし数字のみ
     * @return array|WP_Error
     */
    public function get_customer_info( string $access_token, string $customer_id ) {
        $query = 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.time_zone, customer.status FROM customer LIMIT 1';

        $result = $this->search_query( $access_token, $customer_id, $query );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( empty( $result ) ) {
            return new \WP_Error( 'no_data', 'Customer 情報が返されませんでした' );
        }

        $customer = $result[0]['customer'] ?? [];
        return [
            'id'               => $customer['id'] ?? '',
            'descriptive_name' => $customer['descriptiveName'] ?? '',
            'currency_code'    => $customer['currencyCode'] ?? '',
            'time_zone'        => $customer['timeZone'] ?? '',
            'status'           => $customer['status'] ?? '',
        ];
    }

    /**
     * GoogleAdsService.SearchStream — GAQL 実行
     *
     * @param  string $access_token
     * @param  string $customer_id  ハイフンなし数字のみ
     * @param  string $query        GAQL クエリ
     * @return array|WP_Error       results 配列
     */
    public function search_query( string $access_token, string $customer_id, string $query ) {
        $url = self::API_BASE . '/' . self::API_VERSION
             . '/customers/' . $customer_id . '/googleAds:searchStream';

        $response = wp_remote_post( $url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => array_merge(
                $this->build_headers( $access_token ),
                [ 'Content-Type' => 'application/json' ]
            ),
            'body'    => wp_json_encode( [ 'query' => $query ] ),
        ]);

        if ( is_wp_error( $response ) ) {
            $msg = 'searchStream HTTP error: ' . $response->get_error_message();
            $this->log( $msg );
            return new \WP_Error( 'api_error', $msg );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $error_detail = $this->parse_api_error( $body );
            $msg = "searchStream HTTP {$code}: {$error_detail}";
            $this->log( $msg );
            return new \WP_Error( 'api_error', $msg );
        }

        $data = json_decode( $body, true );

        // searchStream は配列を返す（各要素に results[] がある）
        $results = [];
        if ( is_array( $data ) ) {
            foreach ( $data as $batch ) {
                if ( ! empty( $batch['results'] ) && is_array( $batch['results'] ) ) {
                    $results = array_merge( $results, $batch['results'] );
                }
            }
        }

        return $results;
    }

    // =========================================================
    // Keyword Planner（今後実装予定 — スタブ）
    // =========================================================

    /**
     * KeywordPlanIdeaService — キーワード候補取得
     *
     * Basic Access 承認後に実装。現時点ではスタブ。
     *
     * @param  string $access_token
     * @param  string $customer_id
     * @param  array  $seed_keywords
     * @param  string $language_code  例: 'languageConstants/1005' (日本語)
     * @param  array  $geo_targets    例: ['geoTargetConstants/2392'] (日本)
     * @return array|WP_Error
     */
    public function generate_keyword_ideas( string $access_token, string $customer_id, array $seed_keywords, string $language_code = 'languageConstants/1005', array $geo_targets = [ 'geoTargetConstants/2392' ] ) {
        $url = self::API_BASE . '/' . self::API_VERSION
             . '/customers/' . $customer_id . ':generateKeywordIdeas';

        $payload = [
            'language'           => $language_code,
            'geoTargetConstants' => $geo_targets,
            'keywordSeed'        => [
                'keywords' => array_slice( $seed_keywords, 0, 20 ), // 最大 20
            ],
        ];

        $response = wp_remote_post( $url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => array_merge(
                $this->build_headers( $access_token ),
                [ 'Content-Type' => 'application/json' ]
            ),
            'body'    => wp_json_encode( $payload ),
        ]);

        if ( is_wp_error( $response ) ) {
            $msg = 'generateKeywordIdeas HTTP error: ' . $response->get_error_message();
            $this->log( $msg );
            return new \WP_Error( 'api_error', $msg );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            $error_detail = $this->parse_api_error( $body );
            $msg = "generateKeywordIdeas HTTP {$code}: {$error_detail}";
            $this->log( $msg );
            return new \WP_Error( 'api_error', $msg );
        }

        $data = json_decode( $body, true );
        return $data['results'] ?? [];
    }

    // =========================================================
    // ヘルパー
    // =========================================================

    /**
     * API リクエスト共通ヘッダー
     */
    private function build_headers( string $access_token ): array {
        $headers = [
            'Authorization'  => 'Bearer ' . $access_token,
            'developer-token' => GOOGLE_ADS_DEVELOPER_TOKEN,
        ];

        $login_id = $this->normalize_customer_id( GOOGLE_ADS_LOGIN_CUSTOMER_ID );
        if ( $login_id !== '' ) {
            $headers['login-customer-id'] = $login_id;
        }

        return $headers;
    }

    /**
     * customer_id をハイフンなし数字のみに正規化
     */
    private function normalize_customer_id( string $id ): string {
        return preg_replace( '/[^0-9]/', '', $id );
    }

    /**
     * API エラーレスポンスからメッセージを抽出
     */
    private function parse_api_error( string $body ): string {
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            return substr( $body, 0, 300 );
        }

        // Google Ads API エラーフォーマット
        if ( ! empty( $data['error']['message'] ) ) {
            $msg = $data['error']['message'];
            // 詳細エラーがあれば追加
            if ( ! empty( $data['error']['details'] ) ) {
                foreach ( $data['error']['details'] as $detail ) {
                    if ( ! empty( $detail['errors'] ) ) {
                        foreach ( $detail['errors'] as $err ) {
                            $error_code = $err['errorCode'] ?? [];
                            if ( is_array( $error_code ) ) {
                                $error_code = implode( '.', array_values( $error_code ) );
                            }
                            $msg .= " [{$error_code}]";
                        }
                    }
                }
            }
            return $msg;
        }

        // 一般的なエラーフォーマット
        if ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
            return $data['error'] . ( ! empty( $data['error_description'] ) ? ': ' . $data['error_description'] : '' );
        }

        return substr( $body, 0, 300 );
    }

    /**
     * デバッグログ
     */
    private function log( string $message ): void {
        file_put_contents(
            self::DEBUG_LOG,
            date('Y-m-d H:i:s') . " [GoogleAds] {$message}\n",
            FILE_APPEND
        );
    }
}
