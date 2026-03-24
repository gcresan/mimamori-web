<?php
// FILE: inc/gcrev-api/modules/class-openai-client.php

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_OpenAI_Client') ) { return; }

/**
 * Gcrev_OpenAI_Client
 *
 * OpenAI ChatCompletion API への通信を担当する。
 * 月次レポート生成で ChatGPT を使う際に利用。
 *
 * 責務:
 *   - OpenAI Chat Completions API の呼び出し（JSON mode / テキスト mode）
 *   - リトライ・エラーハンドリング
 *   - トークン使用量の取得
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Gcrev_OpenAI_Client {

    private const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const LOG_FILE     = '/tmp/gcrev_report_debug.log';
    private const MAX_RETRIES  = 2;

    /** @var Gcrev_Config */
    private Gcrev_Config $config;

    public function __construct( Gcrev_Config $config ) {
        $this->config = $config;
    }

    // =========================================================
    // JSON mode 呼び出し（構造化出力）
    // =========================================================

    /**
     * ChatGPT に JSON mode でリクエストし、構造化レスポンスを返す
     *
     * @param  string $system_prompt システムプロンプト
     * @param  string $user_prompt   ユーザープロンプト
     * @param  array  $options       {
     *   @type float  $temperature     温度（default: 0.4）
     *   @type int    $max_tokens      最大出力トークン（default: 8192）
     *   @type string $model           モデル名（default: config設定値）
     * }
     * @return array {
     *   @type string $text              レスポンステキスト（JSON文字列）
     *   @type int    $prompt_tokens     入力トークン数
     *   @type int    $completion_tokens 出力トークン数
     *   @type string $finish_reason     停止理由
     *   @type string $model             使用モデル
     *   @type float  $elapsed_seconds   所要時間
     * }
     * @throws \Exception API通信エラーまたは応答が空の場合
     */
    public function call_chat_api( string $system_prompt, string $user_prompt, array $options = [] ): array {
        return $this->do_chat_request( $system_prompt, $user_prompt, $options, true );
    }

    // =========================================================
    // テキスト mode 呼び出し（JSON mode なし）
    // =========================================================

    /**
     * ChatGPT にテキストモードでリクエスト（JSON mode なし）
     *
     * @param  string $system_prompt システムプロンプト
     * @param  string $user_prompt   ユーザープロンプト
     * @param  array  $options       call_chat_api と同じ
     * @return string レスポンステキスト
     * @throws \Exception API通信エラーまたは応答が空の場合
     */
    public function call_chat_text( string $system_prompt, string $user_prompt, array $options = [] ): string {
        $result = $this->do_chat_request( $system_prompt, $user_prompt, $options, false );
        return $result['text'];
    }

    // =========================================================
    // 内部: API 呼び出し実装
    // =========================================================

    /**
     * @param  bool $json_mode JSON mode を有効にするか
     */
    private function do_chat_request( string $system_prompt, string $user_prompt, array $options, bool $json_mode ): array {

        $api_key = $this->config->get_openai_api_key();
        if ( $api_key === '' ) {
            throw new \Exception( 'OpenAI API キーが設定されていません。wp-config.php に define(\'OPENAI_API_KEY\', \'sk-...\'); を追加してください。' );
        }

        $model       = $options['model'] ?? $this->config->get_openai_model();
        $temperature = $options['temperature'] ?? 0.4;
        $max_tokens  = $options['max_tokens'] ?? $options['maxOutputTokens'] ?? 8192;

        $body = [
            'model'    => $model,
            'messages' => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $user_prompt ],
            ],
            'temperature' => (float) $temperature,
            'max_tokens'  => (int) $max_tokens,
        ];

        if ( $json_mode ) {
            $body['response_format'] = [ 'type' => 'json_object' ];
        }

        $request_body = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );

        $this->log( sprintf(
            '[OpenAI] Request: model=%s, json_mode=%s, system_len=%d, user_len=%d',
            $model,
            $json_mode ? 'true' : 'false',
            mb_strlen( $system_prompt ),
            mb_strlen( $user_prompt )
        ) );

        $start_time = microtime( true );
        $status     = 0;
        $raw        = '';

        // リトライループ（429 Rate Limit 対応）
        for ( $attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++ ) {
            if ( $attempt > 0 ) {
                $wait = (int) pow( 2, $attempt ) * 15; // 30秒, 60秒
                $this->log( "[OpenAI] Rate limited, retry {$attempt}/" . self::MAX_RETRIES . " after {$wait}s" );
                sleep( $wait );
            }

            $response = wp_remote_post( self::API_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json; charset=utf-8',
                ],
                'body'    => $request_body,
                'timeout' => 180,
            ] );

            if ( is_wp_error( $response ) ) {
                $err = $response->get_error_message();
                $this->log( "[OpenAI] WP_Error: {$err}" );
                throw new \Exception( 'OpenAI API 通信エラー: ' . $err );
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $raw    = (string) wp_remote_retrieve_body( $response );

            // 429 Rate Limit → リトライ
            if ( $status === 429 && $attempt < self::MAX_RETRIES ) {
                continue;
            }

            break;
        }

        $elapsed = round( microtime( true ) - $start_time, 2 );

        // HTTP エラーチェック
        if ( $status < 200 || $status >= 300 ) {
            $json_err = json_decode( $raw, true );
            $msg = $json_err['error']['message'] ?? "HTTP {$status}";
            $this->log( "[OpenAI] API Error HTTP {$status}: " . substr( $raw, 0, 500 ) );
            throw new \Exception( "OpenAI API エラー (HTTP {$status}): {$msg}" );
        }

        // レスポンスパース
        $json = json_decode( $raw, true );
        if ( ! is_array( $json ) ) {
            $this->log( '[OpenAI] JSON parse failed: ' . substr( $raw, 0, 300 ) );
            throw new \Exception( 'OpenAI API レスポンスの JSON パースに失敗しました。' );
        }

        $text = $json['choices'][0]['message']['content'] ?? '';
        if ( ! is_string( $text ) || $text === '' ) {
            $finish = $json['choices'][0]['finish_reason'] ?? 'UNKNOWN';
            $this->log( "[OpenAI] Empty response, finish_reason={$finish}" );
            throw new \Exception( 'OpenAI の応答が空でした。finish_reason=' . $finish );
        }

        $usage = $json['usage'] ?? [];
        $result = [
            'text'              => $text,
            'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
            'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
            'finish_reason'     => $json['choices'][0]['finish_reason'] ?? 'unknown',
            'model'             => $json['model'] ?? $model,
            'elapsed_seconds'   => $elapsed,
        ];

        $this->log( sprintf(
            '[OpenAI] Response: elapsed=%.1fs, prompt_tokens=%d, completion_tokens=%d, finish_reason=%s, model=%s',
            $elapsed,
            $result['prompt_tokens'],
            $result['completion_tokens'],
            $result['finish_reason'],
            $result['model']
        ) );

        // finish_reason が length の場合は警告（切り詰められた可能性）
        if ( $result['finish_reason'] === 'length' ) {
            $this->log( '[OpenAI] WARNING: finish_reason=length — output may be truncated' );
        }

        return $result;
    }

    // =========================================================
    // ログ出力
    // =========================================================

    private function log( string $message ): void {
        file_put_contents(
            self::LOG_FILE,
            date( 'Y-m-d H:i:s' ) . ' ' . $message . "\n",
            FILE_APPEND
        );
    }
}
