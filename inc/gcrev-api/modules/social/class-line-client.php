<?php
// FILE: inc/gcrev-api/modules/social/class-line-client.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_LINE_Client') ) { return; }

/**
 * Gcrev_LINE_Client
 *
 * LINE Messaging API クライアント。
 *
 * LINE公式アカウントへの「ブロードキャスト送信」を担当する。
 * Messaging API はチャネルアクセストークン方式のため、OAuth フローではなく
 * クライアントが LINE Developers コンソールで発行したトークンを貼り付ける。
 *
 * トークン格納先（user_meta, 暗号化）:
 *   _gcrev_line_channel_token   — チャネルアクセストークン（長期 or 短期）
 *   _gcrev_line_channel_id      — 表示用（チャネルID）
 *   _gcrev_line_basic_id        — @xxxx 形式の LINE 公式 ID（表示用）
 *
 * Endpoints:
 *   POST https://api.line.me/v2/bot/message/broadcast   — 全友だちへ送信
 *   GET  https://api.line.me/v2/bot/info                — Bot 情報取得（接続確認）
 *
 * @package Mimamori_Web
 * @since   2.2.0
 */
class Gcrev_LINE_Client {

    const API_BASE = 'https://api.line.me';

    /**
     * チャネルアクセストークンを保存（暗号化）。
     * 同時に Bot 情報を取得して接続を検証。
     *
     * @return array ['success'=>bool, 'message'=>string, 'basic_id'=>string]
     */
    public static function save_token(int $user_id, string $token, string $label = ''): array {
        $token = trim($token);
        if ( $token === '' ) {
            return ['success' => false, 'message' => 'チャネルアクセストークンが空です。', 'basic_id' => ''];
        }
        // Fail-closed: 暗号化キーが無いとトークンを平文保存することになるため、保存前に拒否
        if ( ! class_exists( 'Gcrev_Crypto' ) || ! \Gcrev_Crypto::is_available() ) {
            return ['success' => false, 'message' => '暗号化キー(GCREV_ENCRYPTION_KEY)が未設定のため、トークンを安全に保存できません。管理者にお問い合わせください。', 'basic_id' => ''];
        }

        // 接続検証 — /v2/bot/info を呼ぶ
        $info = self::call('GET', '/v2/bot/info', null, $token);
        if ( ! $info['ok'] ) {
            self::log('save_token', 'ERROR', $info);
            return [
                'success' => false,
                'message' => 'LINE接続検証失敗: ' . self::error_text($info),
                'basic_id'=> '',
            ];
        }

        $basic_id = (string) ($info['body']['basicId'] ?? '');
        $display  = $label !== '' ? $label : (string) ($info['body']['displayName'] ?? $basic_id);

        update_user_meta($user_id, '_gcrev_line_channel_token', Gcrev_Crypto::encrypt($token));
        update_user_meta($user_id, '_gcrev_line_basic_id',      $basic_id);
        update_user_meta($user_id, '_gcrev_line_channel_id',    $display);

        return ['success' => true, 'message' => '', 'basic_id' => $basic_id];
    }

    /**
     * 接続を解除
     */
    public static function disconnect(int $user_id): void {
        delete_user_meta($user_id, '_gcrev_line_channel_token');
        delete_user_meta($user_id, '_gcrev_line_basic_id');
        delete_user_meta($user_id, '_gcrev_line_channel_id');
    }

    /**
     * 接続状態取得
     */
    public static function get_connection_status(int $user_id): array {
        $has_token = (bool) get_user_meta($user_id, '_gcrev_line_channel_token', true);
        return [
            'connected'  => $has_token,
            'basic_id'   => (string) get_user_meta($user_id, '_gcrev_line_basic_id', true),
            'channel_id' => (string) get_user_meta($user_id, '_gcrev_line_channel_id', true),
        ];
    }

    /**
     * 全友だちへブロードキャスト送信。
     * media_url があれば画像メッセージを追加。
     *
     * Messaging API のメッセージは1リクエストあたり最大5件のメッセージオブジェクト。
     * 今回はテキスト1 + 画像1（あれば）の構成。
     *
     * @return array ['success'=>bool, 'message'=>string]
     */
    public static function broadcast(int $user_id, string $body, string $media_url = ''): array {
        $token = Gcrev_Crypto::decrypt( (string) get_user_meta($user_id, '_gcrev_line_channel_token', true) );
        if ( $token === '' ) {
            return ['success' => false, 'message' => 'LINEが未接続です。', 'platform_post_id' => ''];
        }

        $messages = [];

        if ( $media_url !== '' ) {
            // LINE 画像メッセージは https + jpg/png + 1024x1024 までという制約
            $messages[] = [
                'type'               => 'image',
                'originalContentUrl' => $media_url,
                'previewImageUrl'    => $media_url,
            ];
        }

        if ( $body !== '' ) {
            // LINE テキスト1通あたり 5000 文字制限
            $messages[] = [
                'type' => 'text',
                'text' => mb_substr($body, 0, 4900),
            ];
        }

        if ( empty($messages) ) {
            return ['success' => false, 'message' => '送信内容が空です。', 'platform_post_id' => ''];
        }

        $payload = ['messages' => $messages];

        $res = self::call('POST', '/v2/bot/message/broadcast', $payload, $token);
        if ( ! $res['ok'] ) {
            self::log('broadcast', 'ERROR', $res);
            return ['success' => false, 'message' => 'LINE送信失敗: ' . self::error_text($res), 'platform_post_id' => ''];
        }

        // LINE はブロードキャスト時 X-Line-Request-Id ヘッダで一意な ID を返す
        return [
            'success' => true,
            'message' => '',
            'platform_post_id' => (string) ($res['request_id'] ?? ''),
        ];
    }

    // ===================================================================
    // HTTP
    // ===================================================================

    private static function call(string $method, string $path, $body, string $token): array {
        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
        ];
        if ( $body !== null ) {
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        $res = wp_remote_request(self::API_BASE . $path, $args);

        if ( is_wp_error($res) ) {
            return ['ok' => false, 'code' => 0, 'body' => [], 'error' => $res->get_error_message(), 'request_id' => ''];
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);
        $body_parsed = json_decode($raw, true);
        if ( ! is_array($body_parsed) ) {
            $body_parsed = [];
        }
        $request_id = (string) wp_remote_retrieve_header($res, 'x-line-request-id');

        return [
            'ok'         => ($code >= 200 && $code < 300),
            'code'       => $code,
            'body'       => $body_parsed,
            'error'      => $body_parsed['message'] ?? '',
            'raw'        => substr($raw, 0, 500),
            'request_id' => $request_id,
        ];
    }

    private static function error_text(array $res): string {
        if ( ! empty($res['error']) ) { return (string) $res['error']; }
        if ( ! empty($res['body']['message']) ) { return (string) $res['body']['message']; }
        return 'HTTP ' . ($res['code'] ?? 0);
    }

    private static function log(string $tag, string $level, array $res): void {
        $line = date('Y-m-d H:i:s') . " [{$level}] line/{$tag}: code=" . ($res['code'] ?? 0)
              . ' err=' . ($res['error'] ?? '') . ' raw=' . ($res['raw'] ?? '') . "\n";
        @file_put_contents('/tmp/gcrev_social_debug.log', $line, FILE_APPEND);
    }
}
