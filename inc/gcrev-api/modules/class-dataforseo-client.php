<?php
// FILE: inc/gcrev-api/modules/class-dataforseo-client.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_DataForSEO_Client') ) { return; }

/**
 * Gcrev_DataForSEO_Client
 *
 * DataForSEO API 通信クライアント。
 * Google Organic Search (Live/Advanced) を利用して
 * 指定キーワードの SERP 順位を取得する。
 *
 * 認証: wp-config.php の DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD 定数
 * レート制限: Gcrev_Rate_Limiter（30回/分）
 *
 * @package Mimamori_Web
 * @since   2.5.0
 */
class Gcrev_DataForSEO_Client {

    /** API ベース URL */
    private const API_BASE = 'https://api.dataforseo.com/v3';

    /** レートリミット（回/分） */
    private const RATE_LIMIT_PER_MINUTE = 30;

    /** HTTP タイムアウト（秒） */
    private const HTTP_TIMEOUT = 60;

    /** @var Gcrev_Config */
    private Gcrev_Config $config;

    /**
     * @param Gcrev_Config $config
     */
    public function __construct( Gcrev_Config $config ) {
        $this->config = $config;
    }

    // =========================================================
    // 設定確認
    // =========================================================

    /**
     * API 認証情報が設定されているか
     */
    public static function is_configured(): bool {
        return defined('DATAFORSEO_LOGIN')
            && defined('DATAFORSEO_PASSWORD')
            && DATAFORSEO_LOGIN !== ''
            && DATAFORSEO_PASSWORD !== '';
    }

    // =========================================================
    // API 疎通テスト
    // =========================================================

    /**
     * 最小限のリクエストで API 接続をテストする
     *
     * @return array{success: bool, message: string, balance?: float}
     */
    public function test_connection(): array {
        if ( ! self::is_configured() ) {
            return [
                'success' => false,
                'message' => 'DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD が wp-config.php に未設定です。',
            ];
        }

        // depth=10 の小さいリクエストで疎通確認
        $post_data = [
            [
                'keyword'       => 'test',
                'location_code' => 1009312, // Japan
                'language_code' => 'ja',
                'device'        => 'desktop',
                'os'            => 'windows',
                'depth'         => 10,
            ]
        ];

        $response = $this->api_request( '/serp/google/organic/live/advanced', $post_data );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $status_code = (int) ( $response['status_code'] ?? 0 );
        if ( $status_code === 20000 ) {
            $cost = $response['cost'] ?? 0;
            return [
                'success' => true,
                'message' => 'API 接続成功（テストリクエスト cost: ' . $cost . '）',
            ];
        }

        return [
            'success' => false,
            'message' => 'API エラー: ' . ( $response['status_message'] ?? 'unknown' ),
        ];
    }

    // =========================================================
    // SERP 取得
    // =========================================================

    /**
     * 指定キーワード・デバイスの SERP データを取得
     *
     * @param string $keyword       検索キーワード
     * @param string $device        'desktop' or 'mobile'
     * @param int    $location_code ロケーションコード（デフォルト: Japan 1009312）
     * @param string $language_code 言語コード（デフォルト: 'ja'）
     * @return array SERP items 配列。失敗時は WP_Error
     */
    public function fetch_serp( string $keyword, string $device = 'desktop', int $location_code = 1009312, string $language_code = 'ja' ) {
        if ( ! self::is_configured() ) {
            return new \WP_Error( 'not_configured', 'DataForSEO API が未設定です。' );
        }

        // レートリミット
        if ( class_exists( 'Gcrev_Rate_Limiter' ) ) {
            Gcrev_Rate_Limiter::check_and_wait( 'dataforseo', self::RATE_LIMIT_PER_MINUTE );
        }

        $post_data = [
            [
                'keyword'       => $keyword,
                'location_code' => $location_code,
                'language_code' => $language_code,
                'device'        => $device,
                'os'            => $device === 'desktop' ? 'windows' : 'android',
                'depth'         => 100,
            ]
        ];

        $response = $this->api_request( '/serp/google/organic/live/advanced', $post_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = (int) ( $response['status_code'] ?? 0 );
        if ( $status_code !== 20000 ) {
            $msg = $response['status_message'] ?? 'Unknown error';
            error_log( "[GCREV][DataForSEO] API error: {$msg} (code: {$status_code})" );
            return new \WP_Error( 'api_error', $msg );
        }

        $tasks = $response['tasks'] ?? [];
        if ( empty( $tasks ) || empty( $tasks[0]['result'] ) ) {
            return new \WP_Error( 'no_result', 'API 応答にデータが含まれていません。' );
        }

        $result = $tasks[0]['result'][0];
        return $result['items'] ?? [];
    }

    // =========================================================
    // キーワード別順位取得（desktop + mobile）
    // =========================================================

    /**
     * キーワードの desktop / mobile 両方の順位を取得する
     *
     * @param string $keyword       検索キーワード
     * @param string $target_domain ターゲットドメイン
     * @param int    $location_code ロケーションコード
     * @param string $language_code 言語コード
     * @return array{desktop: ?array, mobile: ?array} デバイスごとの順位情報
     */
    public function fetch_rankings_for_keyword(
        string $keyword,
        string $target_domain,
        int    $location_code = 1009312,
        string $language_code = 'ja'
    ): array {
        $results = [ 'desktop' => null, 'mobile' => null ];

        foreach ( ['desktop', 'mobile'] as $device ) {
            $items = $this->fetch_serp( $keyword, $device, $location_code, $language_code );

            if ( is_wp_error( $items ) ) {
                error_log( "[GCREV][DataForSEO] fetch_serp failed for '{$keyword}' ({$device}): " . $items->get_error_message() );
                $results[ $device ] = [
                    'is_ranked'     => false,
                    'rank_group'    => null,
                    'rank_absolute' => null,
                    'found_url'     => null,
                    'found_domain'  => null,
                    'serp_type'     => 'organic',
                    'error'         => $items->get_error_message(),
                ];
                continue;
            }

            $match = $this->find_domain_in_results( $items, $target_domain );

            if ( $match ) {
                $results[ $device ] = [
                    'is_ranked'     => true,
                    'rank_group'    => (int) $match['rank_group'],
                    'rank_absolute' => (int) $match['rank_absolute'],
                    'found_url'     => $match['url'] ?? null,
                    'found_domain'  => $match['domain'] ?? null,
                    'serp_type'     => $match['type'] ?? 'organic',
                ];
            } else {
                $results[ $device ] = [
                    'is_ranked'     => false,
                    'rank_group'    => null,
                    'rank_absolute' => null,
                    'found_url'     => null,
                    'found_domain'  => null,
                    'serp_type'     => 'organic',
                ];
            }
        }

        return $results;
    }

    // =========================================================
    // ドメインマッチ
    // =========================================================

    /**
     * SERP 結果からターゲットドメインにマッチするアイテムを検索
     *
     * www. / 非www. 対応。organic タイプを優先。
     *
     * @param array  $items         SERP items 配列
     * @param string $target_domain ターゲットドメイン（例: 'example.com'）
     * @return array|null マッチしたアイテム（rank_group, rank_absolute, url, domain, type）
     */
    public function find_domain_in_results( array $items, string $target_domain ): ?array {
        // www. を除いた正規化ドメイン
        $normalized_target = preg_replace( '/^www\./i', '', strtolower( $target_domain ) );

        foreach ( $items as $item ) {
            if ( empty( $item['domain'] ) ) {
                continue;
            }

            $item_domain = preg_replace( '/^www\./i', '', strtolower( $item['domain'] ) );

            if ( $item_domain === $normalized_target ) {
                return [
                    'rank_group'    => $item['rank_group'] ?? null,
                    'rank_absolute' => $item['rank_absolute'] ?? null,
                    'url'           => $item['url'] ?? null,
                    'domain'        => $item['domain'] ?? null,
                    'type'          => $item['type'] ?? 'organic',
                ];
            }
        }

        return null;
    }

    // =========================================================
    // HTTP リクエスト（内部）
    // =========================================================

    /**
     * DataForSEO API へ HTTP POST リクエストを送信
     *
     * @param string $endpoint エンドポイントパス
     * @param array  $post_data リクエストボディ
     * @return array|WP_Error レスポンスボディ（デコード済み）
     */
    private function api_request( string $endpoint, array $post_data ) {
        $url = self::API_BASE . $endpoint;

        $auth = base64_encode( DATAFORSEO_LOGIN . ':' . DATAFORSEO_PASSWORD );

        $response = wp_remote_post( $url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $post_data, JSON_UNESCAPED_UNICODE ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[GCREV][DataForSEO] HTTP error: ' . $response->get_error_message() );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body, true );

        if ( $status_code === 401 || $status_code === 403 ) {
            error_log( '[GCREV][DataForSEO] Authentication failed (HTTP ' . $status_code . ')' );
            return new \WP_Error( 'auth_failed', 'DataForSEO 認証に失敗しました。ログイン情報を確認してください。' );
        }

        if ( $status_code >= 400 ) {
            $msg = $decoded['status_message'] ?? "HTTP {$status_code}";
            error_log( "[GCREV][DataForSEO] HTTP error {$status_code}: {$msg}" );
            return new \WP_Error( 'http_error', $msg );
        }

        if ( ! is_array( $decoded ) ) {
            error_log( '[GCREV][DataForSEO] Invalid JSON response' );
            return new \WP_Error( 'json_error', 'API レスポンスの JSON 解析に失敗しました。' );
        }

        return $decoded;
    }
}
