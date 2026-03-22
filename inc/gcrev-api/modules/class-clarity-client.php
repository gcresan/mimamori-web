<?php
// FILE: inc/gcrev-api/modules/class-clarity-client.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Clarity_Client') ) { return; }

/**
 * Gcrev_Clarity_Client
 *
 * Microsoft Clarity Data Export API 通信クライアント。
 * 接続テスト・ライブインサイト取得を行う。
 *
 * 認証: ユーザーごとの user_meta に保存された API トークン（暗号化済み）
 *
 * @package Mimamori_Web
 * @since   2.6.0
 */
class Gcrev_Clarity_Client {

    /** API ベース URL */
    private const API_BASE = 'https://www.clarity.ms/export-data/api/v1';

    /** HTTP タイムアウト（秒） */
    private const HTTP_TIMEOUT = 30;

    /** デバッグログファイル */
    private const LOG_FILE = '/tmp/gcrev_clarity_debug.log';

    // =========================================================
    // User Meta キー定数
    // =========================================================

    /** Clarity 連携有効フラグ */
    public const META_ENABLED           = '_gcrev_clarity_enabled';

    /** API トークン（暗号化保存） */
    public const META_API_TOKEN         = '_gcrev_clarity_api_token';

    /** プロジェクト名メモ（任意） */
    public const META_PROJECT_NAME      = '_gcrev_clarity_project_name';

    /** 接続ステータス: 'success' | 'failed' | '' */
    public const META_CONNECTION_STATUS = '_gcrev_clarity_connection_status';

    /** 最終接続確認日時 (Y-m-d H:i:s) */
    public const META_LAST_CONNECTED    = '_gcrev_clarity_last_connected_at';

    /** 最終接続メッセージ */
    public const META_LAST_MESSAGE      = '_gcrev_clarity_last_connection_message';

    // =========================================================
    // 設定読み書き
    // =========================================================

    /**
     * ユーザーの Clarity 設定を取得
     *
     * @param int $user_id
     * @return array
     */
    public static function get_settings( int $user_id ): array {
        $enabled      = get_user_meta( $user_id, self::META_ENABLED, true );
        $token_raw    = get_user_meta( $user_id, self::META_API_TOKEN, true );
        $project_name = get_user_meta( $user_id, self::META_PROJECT_NAME, true );
        $status       = get_user_meta( $user_id, self::META_CONNECTION_STATUS, true );
        $last_conn    = get_user_meta( $user_id, self::META_LAST_CONNECTED, true );
        $last_msg     = get_user_meta( $user_id, self::META_LAST_MESSAGE, true );

        // トークンマスク表示用
        $has_token  = ! empty( $token_raw );
        $token_mask = '';
        if ( $has_token ) {
            $decrypted = self::decrypt_token( $token_raw );
            if ( strlen( $decrypted ) > 8 ) {
                $token_mask = substr( $decrypted, 0, 4 ) . str_repeat( '*', 8 ) . substr( $decrypted, -4 );
            } else {
                $token_mask = '****';
            }
        }

        return [
            'clarity_enabled'           => $enabled === '1',
            'clarity_has_token'         => $has_token,
            'clarity_token_mask'        => $token_mask,
            'clarity_project_name'      => $project_name ?: '',
            'clarity_connection_status' => $status ?: '',
            'clarity_last_connected_at' => $last_conn ?: '',
            'clarity_last_message'      => $last_msg ?: '',
        ];
    }

    /**
     * Clarity 設定を保存
     *
     * @param int    $user_id
     * @param array  $data
     * @return void
     */
    public static function save_settings( int $user_id, array $data ): void {
        // enabled
        if ( isset( $data['clarity_enabled'] ) ) {
            update_user_meta( $user_id, self::META_ENABLED, $data['clarity_enabled'] ? '1' : '0' );
        }

        // API トークン（空でなければ暗号化して保存、空なら削除）
        if ( isset( $data['clarity_api_token'] ) ) {
            $token = sanitize_text_field( $data['clarity_api_token'] );
            if ( $token !== '' ) {
                $encrypted = self::encrypt_token( $token );
                update_user_meta( $user_id, self::META_API_TOKEN, $encrypted );
            } else {
                delete_user_meta( $user_id, self::META_API_TOKEN );
            }
        }

        // プロジェクト名
        if ( isset( $data['clarity_project_name'] ) ) {
            update_user_meta( $user_id, self::META_PROJECT_NAME,
                sanitize_text_field( $data['clarity_project_name'] )
            );
        }
    }

    // =========================================================
    // 接続テスト
    // =========================================================

    /**
     * 接続テストを実行
     *
     * @param int $user_id
     * @return array { success: bool, message: string, data?: array }
     */
    public static function test_connection( int $user_id ): array {
        $token_raw = get_user_meta( $user_id, self::META_API_TOKEN, true );
        if ( empty( $token_raw ) ) {
            self::save_connection_result( $user_id, 'failed', 'APIトークンが設定されていません' );
            return [ 'success' => false, 'message' => 'APIトークンが設定されていません' ];
        }

        $token = self::decrypt_token( $token_raw );
        if ( empty( $token ) ) {
            self::save_connection_result( $user_id, 'failed', 'APIトークンの復号に失敗しました' );
            return [ 'success' => false, 'message' => 'APIトークンの復号に失敗しました' ];
        }

        $url = self::API_BASE . '/project-live-insights';

        $response = wp_remote_get( $url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ] );

        // ネットワークエラー
        if ( is_wp_error( $response ) ) {
            $err_msg = $response->get_error_message();
            self::log( "CONNECTION TEST ERROR: {$err_msg}" );
            self::save_connection_result( $user_id, 'failed', 'Clarity APIへの接続に失敗しました' );
            return [ 'success' => false, 'message' => 'Clarity APIへの接続に失敗しました' ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        self::log( "CONNECTION TEST: HTTP {$code}, body=" . substr( $body, 0, 500 ) );

        // HTTP ステータス別処理
        if ( $code === 200 ) {
            $json = json_decode( $body, true );
            self::save_connection_result( $user_id, 'success', '接続に成功しました' );
            return [
                'success' => true,
                'message' => '接続に成功しました',
                'data'    => $json,
            ];
        }

        // エラーレスポンス
        $user_message = self::get_user_friendly_error( $code );
        self::save_connection_result( $user_id, 'failed', $user_message );
        return [ 'success' => false, 'message' => $user_message ];
    }

    // =========================================================
    // API呼び出しヘルパー（将来の拡張用）
    // =========================================================

    /**
     * Clarity API に GET リクエスト
     *
     * @param string $endpoint  例: '/project-live-insights'
     * @param string $token     復号済みトークン
     * @param array  $params    クエリパラメータ
     * @return array { success: bool, code: int, data: mixed, error?: string }
     */
    public static function api_get( string $endpoint, string $token, array $params = [] ): array {
        $url = self::API_BASE . $endpoint;
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            $err = $response->get_error_message();
            self::log( "API GET ERROR: endpoint={$endpoint}, error={$err}" );
            return [ 'success' => false, 'code' => 0, 'data' => null, 'error' => $err ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( $code !== 200 ) {
            self::log( "API GET FAILED: endpoint={$endpoint}, HTTP {$code}, body=" . substr( $body, 0, 500 ) );
            return [ 'success' => false, 'code' => $code, 'data' => $json, 'error' => self::get_user_friendly_error( $code ) ];
        }

        return [ 'success' => true, 'code' => 200, 'data' => $json ];
    }

    /**
     * ユーザーの復号済みトークンを取得
     *
     * @param int $user_id
     * @return string|null
     */
    public static function get_decrypted_token( int $user_id ): ?string {
        $raw = get_user_meta( $user_id, self::META_API_TOKEN, true );
        if ( empty( $raw ) ) { return null; }
        $token = self::decrypt_token( $raw );
        return $token ?: null;
    }

    // =========================================================
    // 内部ヘルパー
    // =========================================================

    /**
     * 接続結果を user meta に保存
     */
    private static function save_connection_result( int $user_id, string $status, string $message ): void {
        $now = current_time( 'Y-m-d H:i:s' );
        update_user_meta( $user_id, self::META_CONNECTION_STATUS, $status );
        update_user_meta( $user_id, self::META_LAST_CONNECTED, $now );
        update_user_meta( $user_id, self::META_LAST_MESSAGE, $message );
    }

    /**
     * HTTP ステータスコードからユーザー向けメッセージ
     */
    private static function get_user_friendly_error( int $code ): string {
        switch ( $code ) {
            case 401:
                return 'APIトークンが無効の可能性があります。トークンを再確認してください';
            case 403:
                return 'アクセス権限がありません。トークンの権限設定を確認してください';
            case 429:
                return 'APIリクエスト制限に達しました。時間をおいて再度お試しください';
            default:
                if ( $code >= 500 ) {
                    return 'Clarity側でサーバーエラーが発生しました。時間をおいて再度お試しください';
                }
                return "Clarity APIへの接続に失敗しました（HTTP {$code}）";
        }
    }

    /**
     * トークンを暗号化
     */
    private static function encrypt_token( string $token ): string {
        if ( class_exists( 'Gcrev_Crypto' ) && Gcrev_Crypto::is_available() ) {
            return Gcrev_Crypto::encrypt( $token );
        }
        return $token; // フォールバック: 平文
    }

    /**
     * トークンを復号
     */
    private static function decrypt_token( string $stored ): string {
        if ( class_exists( 'Gcrev_Crypto' ) && Gcrev_Crypto::is_available() ) {
            return Gcrev_Crypto::decrypt( $stored );
        }
        return $stored;
    }

    /**
     * デバッグログ出力
     */
    private static function log( string $message ): void {
        file_put_contents(
            self::LOG_FILE,
            date( 'Y-m-d H:i:s' ) . " [Clarity] {$message}\n",
            FILE_APPEND
        );
    }
}
