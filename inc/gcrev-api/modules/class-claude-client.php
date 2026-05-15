<?php
// FILE: inc/gcrev-api/modules/class-claude-client.php

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Claude_Client') ) { return; }

/**
 * Gcrev_Claude_Client
 *
 * Anthropic Claude (Messages API) への通信を担当する。
 * 月次レポート生成で Claude を使う際に利用。
 *
 * 責務:
 *   - Anthropic Messages API の呼び出し
 *   - リトライ・エラーハンドリング
 *   - トークン使用量の取得
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Gcrev_Claude_Client {

    private const API_ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION  = '2023-06-01';
    private const LOG_FILE     = '/tmp/gcrev_claude_debug.log';
    private const MAX_RETRIES  = 2;

    /** @var Gcrev_Config */
    private Gcrev_Config $config;

    public function __construct( Gcrev_Config $config ) {
        $this->config = $config;
    }

    /**
     * Claude Messages API にリクエスト
     *
     * @param  string $system_prompt システムプロンプト
     * @param  string $user_prompt   ユーザープロンプト
     * @param  array  $options       {
     *   @type float  $temperature  温度（default: 0.4）
     *   @type int    $max_tokens   最大出力トークン（default: 8000）
     *   @type string $model        モデル名（default: config）
     *   @type int    $timeout      タイムアウト秒（default: 180）
     * }
     * @return array {
     *   @type string $text              レスポンステキスト
     *   @type int    $prompt_tokens     入力トークン数
     *   @type int    $completion_tokens 出力トークン数
     *   @type string $stop_reason       停止理由
     *   @type string $model             使用モデル
     *   @type float  $elapsed_seconds   所要時間
     * }
     * @throws \Exception API通信エラーまたは応答が空の場合
     */
    public function call_messages_api( string $system_prompt, string $user_prompt, array $options = [] ): array {

        $api_key = $this->config->get_anthropic_api_key();
        if ( $api_key === '' ) {
            throw new \Exception( 'ANTHROPIC_API_KEY が設定されていません。wp-config.php に define(\'ANTHROPIC_API_KEY\', \'sk-ant-...\'); を追加してください。' );
        }

        $model       = $options['model']       ?? $this->config->get_claude_model();
        $temperature = $options['temperature'] ?? 0.4;
        $max_tokens  = $options['max_tokens']  ?? 8000;
        $timeout     = $options['timeout']     ?? 180;

        $body = [
            'model'       => $model,
            'max_tokens'  => (int) $max_tokens,
            'temperature' => (float) $temperature,
            'system'      => $system_prompt,
            'messages'    => [
                [ 'role' => 'user', 'content' => $user_prompt ],
            ],
        ];

        $request_body = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );

        $this->log( sprintf(
            '[Claude] Request: model=%s, system_len=%d, user_len=%d, max_tokens=%d',
            $model,
            mb_strlen( $system_prompt ),
            mb_strlen( $user_prompt ),
            (int) $max_tokens
        ) );

        $start_time = microtime( true );
        $status     = 0;
        $raw        = '';

        // リトライループ（429 / 529 対応）
        for ( $attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++ ) {
            if ( $attempt > 0 ) {
                $wait = (int) pow( 2, $attempt ) * 15; // 30秒, 60秒
                $this->log( "[Claude] Retry {$attempt}/" . self::MAX_RETRIES . " after {$wait}s (last status={$status})" );
                sleep( $wait );
            }

            $response = wp_remote_post( self::API_ENDPOINT, [
                'headers' => [
                    'x-api-key'         => $api_key,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'      => 'application/json; charset=utf-8',
                ],
                'body'    => $request_body,
                'timeout' => (int) $timeout,
            ] );

            if ( is_wp_error( $response ) ) {
                $err = $response->get_error_message();
                $this->log( "[Claude] WP_Error: {$err}" );
                throw new \Exception( 'Claude API 通信エラー: ' . $err );
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $raw    = (string) wp_remote_retrieve_body( $response );

            // 429 (rate limit) / 529 (overloaded) → リトライ
            if ( ( $status === 429 || $status === 529 ) && $attempt < self::MAX_RETRIES ) {
                continue;
            }

            break;
        }

        $elapsed = round( microtime( true ) - $start_time, 2 );

        // HTTP エラーチェック
        if ( $status < 200 || $status >= 300 ) {
            $json_err = json_decode( $raw, true );
            $msg = is_array( $json_err ) && isset( $json_err['error']['message'] )
                ? (string) $json_err['error']['message']
                : "HTTP {$status}";
            $this->log( "[Claude] API Error HTTP {$status}: " . substr( $raw, 0, 500 ) );
            throw new \Exception( "Claude API エラー (HTTP {$status}): {$msg}" );
        }

        // レスポンスパース
        $json = json_decode( $raw, true );
        if ( ! is_array( $json ) ) {
            $this->log( '[Claude] JSON parse failed: ' . substr( $raw, 0, 300 ) );
            throw new \Exception( 'Claude API レスポンスの JSON パースに失敗しました。' );
        }

        // content は配列形式: [{"type":"text", "text":"..."}, ...]
        $text = '';
        if ( isset( $json['content'] ) && is_array( $json['content'] ) ) {
            foreach ( $json['content'] as $block ) {
                if ( isset( $block['type'], $block['text'] ) && $block['type'] === 'text' ) {
                    $text .= (string) $block['text'];
                }
            }
        }

        if ( $text === '' ) {
            $stop = $json['stop_reason'] ?? 'UNKNOWN';
            $this->log( "[Claude] Empty response, stop_reason={$stop}, raw=" . substr( $raw, 0, 500 ) );
            throw new \Exception( 'Claude の応答が空でした。stop_reason=' . $stop );
        }

        $usage = $json['usage'] ?? [];
        $result = [
            'text'              => $text,
            'prompt_tokens'     => (int) ( $usage['input_tokens']  ?? 0 ),
            'completion_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
            'stop_reason'       => (string) ( $json['stop_reason'] ?? 'unknown' ),
            'model'             => (string) ( $json['model'] ?? $model ),
            'elapsed_seconds'   => $elapsed,
        ];

        $this->log( sprintf(
            '[Claude] Response: elapsed=%.1fs, in=%d, out=%d, stop=%s, model=%s',
            $elapsed,
            $result['prompt_tokens'],
            $result['completion_tokens'],
            $result['stop_reason'],
            $result['model']
        ) );

        if ( $result['stop_reason'] === 'max_tokens' ) {
            $this->log( '[Claude] WARNING: stop_reason=max_tokens — output may be truncated' );
        }

        return $result;
    }

    /**
     * ログ出力（KUSANAGI 環境で error_log() が効かないため file_put_contents で）
     */
    private function log( string $message ): void {
        file_put_contents(
            self::LOG_FILE,
            date( 'Y-m-d H:i:s' ) . ' ' . $message . "\n",
            FILE_APPEND
        );
    }
}
