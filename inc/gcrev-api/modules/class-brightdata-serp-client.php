<?php
// FILE: inc/gcrev-api/modules/class-brightdata-serp-client.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Gcrev_Brightdata_Serp_Client' ) ) { return; }

/**
 * Bright Data SERP API クライアント
 *
 * Google 検索結果（AI Overview 含む）を構造化 JSON で取得する。
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Gcrev_Brightdata_Serp_Client {

    // =========================================================
    // 定数
    // =========================================================

    private const API_URL     = 'https://api.brightdata.com/request';
    private const TIMEOUT     = 90;
    private const LOG_FILE    = '/tmp/gcrev_brightdata_debug.log';

    /** リトライ待機時間（秒） */
    private const RETRY_DELAYS = [ 120, 600, 1800 ];

    // =========================================================
    // 設定チェック
    // =========================================================

    /**
     * API トークンが設定されているか
     */
    public static function is_configured(): bool {
        return defined( 'BRIGHTDATA_API_TOKEN' ) && BRIGHTDATA_API_TOKEN !== ''
            && defined( 'BRIGHTDATA_ZONE' )      && BRIGHTDATA_ZONE !== '';
    }

    // =========================================================
    // SERP 取得
    // =========================================================

    /**
     * Google 検索 SERP を取得（AI Overview 含む）
     *
     * @param string $keyword 検索キーワード
     * @param array  $options {
     *     @type string $region  国コード（デフォルト 'jp'）
     *     @type string $language 言語コード（デフォルト 'ja'）
     *     @type string $device  'desktop' | 'mobile'（デフォルト 'desktop'）
     * }
     * @return array {
     *     @type bool        $success
     *     @type array|null  $data    パース済み SERP JSON
     *     @type string|null $error   エラーメッセージ
     * }
     */
    public function fetch_serp( string $keyword, array $options = [] ): array {
        if ( ! self::is_configured() ) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'Bright Data API credentials not configured',
            ];
        }

        $region   = $options['region']   ?? 'jp';
        $language = $options['language'] ?? 'ja';
        $device   = $options['device']   ?? 'desktop';

        // Google 検索 URL を構築
        $search_url = sprintf(
            'https://www.google.com/search?q=%s&gl=%s&hl=%s&brd_json=1&brd_ai_overview=2',
            rawurlencode( $keyword ),
            rawurlencode( $region ),
            rawurlencode( $language )
        );

        if ( $device === 'mobile' ) {
            $search_url .= '&brd_mobile=1';
        }

        $payload = [
            'zone'   => defined( 'BRIGHTDATA_ZONE' ) ? (string) BRIGHTDATA_ZONE : '',
            'url'    => $search_url,
            'format' => 'raw',
        ];

        // リトライループ（初回 + 最大3回リトライ = 合計4回）
        $max_attempts = 1 + count( self::RETRY_DELAYS );
        $last_error   = '';

        for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
            if ( $attempt > 0 ) {
                $delay = self::RETRY_DELAYS[ $attempt - 1 ];
                self::log( "Retry #{$attempt} for '{$keyword}' after {$delay}s" );
                sleep( $delay );
            }

            $result = $this->do_request( $payload );

            if ( $result['success'] ) {
                return $result;
            }

            $last_error = $result['error'] ?? 'Unknown error';
            self::log( "Attempt #{$attempt}: {$last_error}" );

            // 認証エラーはリトライしない
            if ( strpos( $last_error, '401' ) !== false || strpos( $last_error, '403' ) !== false ) {
                break;
            }
        }

        return [
            'success' => false,
            'data'    => null,
            'error'   => "All {$max_attempts} attempts failed: {$last_error}",
        ];
    }

    /**
     * 接続テスト（簡単なクエリで API が応答するか確認）
     *
     * @return array { success: bool, message: string }
     */
    public function test_connection(): array {
        if ( ! self::is_configured() ) {
            return [ 'success' => false, 'message' => 'API credentials not configured' ];
        }

        $search_url = 'https://www.google.com/search?q=test&gl=us&hl=en&brd_json=1';
        $payload = [
            'zone'   => defined( 'BRIGHTDATA_ZONE' ) ? (string) BRIGHTDATA_ZONE : '',
            'url'    => $search_url,
            'format' => 'raw',
        ];

        $result = $this->do_request( $payload );

        if ( $result['success'] ) {
            return [ 'success' => true, 'message' => 'Connection successful' ];
        }

        return [ 'success' => false, 'message' => $result['error'] ?? 'Connection failed' ];
    }

    // =========================================================
    // 内部
    // =========================================================

    /**
     * HTTP リクエスト実行
     */
    private function do_request( array $payload ): array {
        $token = defined( 'BRIGHTDATA_API_TOKEN' ) ? (string) BRIGHTDATA_API_TOKEN : '';

        $response = wp_remote_post( self::API_URL, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
        ] );

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            self::log( "WP_Error: {$msg}" );
            return [ 'success' => false, 'data' => null, 'error' => "WP_Error: {$msg}" ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            self::log( "HTTP {$code}: " . substr( $body, 0, 500 ) );
            return [
                'success' => false,
                'data'    => null,
                'error'   => "HTTP {$code}: " . substr( $body, 0, 200 ),
            ];
        }

        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            self::log( 'Invalid JSON response: ' . substr( $body, 0, 500 ) );
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'Invalid JSON response',
            ];
        }

        return [ 'success' => true, 'data' => $data, 'error' => null ];
    }

    /**
     * デバッグログ出力
     */
    private static function log( string $message ): void {
        file_put_contents(
            self::LOG_FILE,
            date( 'Y-m-d H:i:s' ) . " [BrightData] {$message}\n",
            FILE_APPEND
        );
    }
}
