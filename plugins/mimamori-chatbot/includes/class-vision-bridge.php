<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Vision_Bridge' ) ) { return; }

/**
 * Mimamori_Bot_Vision_Bridge
 *
 * OpenAI Responses API の image input を使った日本語OCR。
 *   - モデル: gpt-4o-mini
 *   - 入力: data URL (base64) で画像送信
 *   - 出力: 余計な前置きなしの抽出テキストのみ
 *
 * 主用途: バナー/チラシ/価格表画像 → ナレッジ化
 */
class Mimamori_Bot_Vision_Bridge {

	private const ENDPOINT = 'https://api.openai.com/v1/responses';
	private const MODEL    = 'gpt-4o-mini';
	private const TIMEOUT  = 90;

	/** OCR / 画像説明生成のための system instruction */
	private const SYSTEM_PROMPT = <<<PROMPT
あなたは画像から日本語テキストを書き起こすOCRエンジンです。
以下のルールを厳守してください:
- 画像内の日本語テキストを正確に書き起こす (改行・段落構造は維持)
- 表は「列名: 値 / 列名: 値」形式で1行ずつ書き起こし
- 図のキャプションや吹き出しも含める
- 価格・部数・電話番号等の数値は元の表記を正確に再現
- テキストが含まれない場合は、画像の主要被写体を1文で要約 (例: 「学習塾チラシのメインビジュアル。子どもが教科書を持つ写真」)
- 抽出に関するコメント・前置き・後置きは一切付けない
- 読み取れない箇所は [読取不可] と記述
PROMPT;

	/**
	 * 画像バイナリから日本語テキストを抽出する。
	 *
	 * @param string $binary  画像バイナリ
	 * @param string $mime    'image/jpeg' / 'image/png' / 'image/webp'
	 * @return array{text:string, tokens_in:int, tokens_out:int, latency_ms:int}|WP_Error
	 */
	public function extract_text( string $binary, string $mime ) {
		if ( $binary === '' ) {
			return new WP_Error( 'empty_image', '画像データが空です', [ 'status' => 400 ] );
		}
		$api_key = $this->resolve_api_key();
		if ( $api_key === '' ) {
			return new WP_Error( 'no_api_key', 'OpenAI APIキーが設定されていません', [ 'status' => 500 ] );
		}

		$b64 = base64_encode( $binary );
		$payload = [
			'model'             => self::MODEL,
			'input'             => [
				[
					'role'    => 'user',
					'content' => [
						[ 'type' => 'input_text',  'text'      => '画像から日本語テキストを書き起こしてください。' ],
						[ 'type' => 'input_image', 'image_url' => 'data:' . $mime . ';base64,' . $b64 ],
					],
				],
			],
			'instructions'      => self::SYSTEM_PROMPT,
			'temperature'       => 0.1,
			'max_output_tokens' => 1500,
			'metadata'          => [ 'feature' => 'chatbot_vision_ocr' ],
		];

		$started = microtime( true );
		$response = wp_remote_post( self::ENDPOINT, [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'timeout' => self::TIMEOUT,
		] );
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			Mimamori_Bot_Logger::error( 'vision: http error', [
				'msg'     => $response->get_error_message(),
				'latency' => $latency,
			] );
			return new WP_Error( 'vision_http', 'Vision APIに接続できませんでした', [ 'status' => 502 ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			Mimamori_Bot_Logger::error( 'vision: bad status', [
				'code' => $code, 'body' => substr( $body, 0, 400 ), 'latency' => $latency,
			] );
			return new WP_Error( 'vision_status', 'Vision APIエラー (HTTP ' . $code . ')', [ 'status' => 502 ] );
		}
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'vision_parse', 'Vision API レスポンスを解釈できませんでした', [ 'status' => 502 ] );
		}

		$text = $this->extract_response_text( $json );
		$text = trim( $text );

		if ( $text === '' ) {
			return new WP_Error( 'vision_empty', '画像からテキストを抽出できませんでした', [ 'status' => 200 ] );
		}

		Mimamori_Bot_Logger::info( 'vision: ok', [
			'latency' => $latency,
			'tokens'  => (int) ( $json['usage']['total_tokens'] ?? 0 ),
		] );

		return [
			'text'       => $text,
			'tokens_in'  => (int) ( $json['usage']['input_tokens']  ?? 0 ),
			'tokens_out' => (int) ( $json['usage']['output_tokens'] ?? 0 ),
			'latency_ms' => $latency,
		];
	}

	private function extract_response_text( array $json ): string {
		if ( isset( $json['output_text'] ) && is_string( $json['output_text'] ) ) {
			return $json['output_text'];
		}
		$parts = [];
		foreach ( $json['output'] ?? [] as $item ) {
			foreach ( $item['content'] ?? [] as $c ) {
				if ( isset( $c['text'] ) && is_string( $c['text'] ) ) {
					$parts[] = $c['text'];
				}
			}
		}
		return implode( "\n", $parts );
	}

	private function resolve_api_key(): string {
		if ( defined( 'MIMAMORI_BOT_OPENAI_KEY' ) && constant( 'MIMAMORI_BOT_OPENAI_KEY' ) !== '' ) {
			return (string) constant( 'MIMAMORI_BOT_OPENAI_KEY' );
		}
		if ( class_exists( 'Gcrev_Config' ) ) {
			try {
				$config = new Gcrev_Config();
				$k = (string) $config->get_openai_api_key();
				if ( $k !== '' ) return $k;
			} catch ( \Throwable $e ) { /* fall through */ }
		}
		$env = getenv( 'OPENAI_API_KEY' );
		if ( is_string( $env ) && $env !== '' ) return $env;
		return (string) get_option( 'mimamori_bot_openai_key', '' );
	}
}
