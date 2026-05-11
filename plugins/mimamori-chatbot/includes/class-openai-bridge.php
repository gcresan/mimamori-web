<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_OpenAI_Bridge' ) ) { return; }

/**
 * Mimamori_Bot_OpenAI_Bridge
 *
 * OpenAI Responses API への薄いラッパ。
 * テーマ側に Gcrev_OpenAI_Client があってもプラグインの自己完結性を保つため
 * 独自に wp_remote_post で実装する（中央キーは同じものを参照）。
 *
 * APIキー解決順:
 *   1) MIMAMORI_BOT_OPENAI_KEY 定数
 *   2) Gcrev_Config::get_openai_api_key() (テーマがあれば)
 *   3) 環境変数 OPENAI_API_KEY
 *   4) WP option 'mimamori_bot_openai_key' (将来管理画面で設定可)
 */
class Mimamori_Bot_OpenAI_Bridge {

	private const ENDPOINT = 'https://api.openai.com/v1/responses';
	private const TIMEOUT  = 60;

	/**
	 * @param array $payload  Responses API パラメータ (model, input, ...)
	 * @return array|WP_Error  成功時: ['text'=>..., 'usage'=>['input_tokens','output_tokens'], 'model'=>..., 'latency_ms'=>..., 'raw'=>full_json]
	 */
	public function call( array $payload ) {
		$api_key = $this->resolve_api_key();
		if ( $api_key === '' ) {
			return new WP_Error( 'no_api_key', 'OpenAI APIキーが設定されていません', [ 'status' => 500 ] );
		}

		$started = microtime( true );
		$response = wp_remote_post( self::ENDPOINT, [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'timeout' => self::TIMEOUT,
		] );
		$latency_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			Mimamori_Bot_Logger::error( 'openai: http error', [
				'msg'        => $response->get_error_message(),
				'latency_ms' => $latency_ms,
			] );
			return new WP_Error( 'openai_http_error', 'AIサービスに接続できませんでした。しばらくしてからお試しください。', [ 'status' => 502 ] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			Mimamori_Bot_Logger::error( 'openai: bad status', [
				'code'    => $code,
				'body'    => substr( $body, 0, 500 ),
				'latency' => $latency_ms,
			] );
			return new WP_Error( 'openai_bad_status', 'AIサービスでエラーが発生しました。', [ 'status' => 502 ] );
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'openai_parse_error', 'AIサービスの応答を解釈できませんでした。', [ 'status' => 502 ] );
		}

		return [
			'text'       => $this->extract_text( $json ),
			'usage'      => [
				'input_tokens'  => (int) ( $json['usage']['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $json['usage']['output_tokens'] ?? 0 ),
			],
			'model'      => (string) ( $json['model'] ?? $payload['model'] ?? '' ),
			'latency_ms' => $latency_ms,
			'raw'        => $json,
		];
	}

	/**
	 * Responses API のレスポンス JSON から本文テキストを抽出する。
	 * output[].content[].text パスを辿る。output_text shortcut も対応。
	 */
	private function extract_text( array $json ): string {
		if ( isset( $json['output_text'] ) && is_string( $json['output_text'] ) ) {
			return $json['output_text'];
		}
		$parts = [];
		if ( isset( $json['output'] ) && is_array( $json['output'] ) ) {
			foreach ( $json['output'] as $item ) {
				if ( ! isset( $item['content'] ) || ! is_array( $item['content'] ) ) {
					continue;
				}
				foreach ( $item['content'] as $c ) {
					if ( isset( $c['text'] ) && is_string( $c['text'] ) ) {
						$parts[] = $c['text'];
					} elseif ( isset( $c['type'] ) && $c['type'] === 'output_text' && isset( $c['text'] ) ) {
						$parts[] = (string) $c['text'];
					}
				}
			}
		}
		return trim( implode( "\n", $parts ) );
	}

	private function resolve_api_key(): string {
		if ( defined( 'MIMAMORI_BOT_OPENAI_KEY' ) && constant( 'MIMAMORI_BOT_OPENAI_KEY' ) !== '' ) {
			return (string) constant( 'MIMAMORI_BOT_OPENAI_KEY' );
		}
		if ( class_exists( 'Gcrev_Config' ) ) {
			try {
				$config = new Gcrev_Config();
				$k = (string) $config->get_openai_api_key();
				if ( $k !== '' ) {
					return $k;
				}
			} catch ( \Throwable $e ) {
				// ignore, fall through
			}
		}
		$env = getenv( 'OPENAI_API_KEY' );
		if ( is_string( $env ) && $env !== '' ) {
			return $env;
		}
		$opt = (string) get_option( 'mimamori_bot_openai_key', '' );
		return $opt;
	}

	/**
	 * gpt-4o-mini の概算コスト(円/microJPY)を返す。
	 * 公開価格: input $0.150 / 1M tokens, output $0.600 / 1M tokens (2026Q1時点目安)
	 * 為替: 1 USD = 155 JPY とする (option 'mimamori_bot_jpy_per_usd' で上書き可)
	 *
	 * @return int  microJPY (円 × 1e6)
	 */
	public static function estimate_cost_microjpy( string $model, int $input_tokens, int $output_tokens ): int {
		$rate = (float) get_option( 'mimamori_bot_jpy_per_usd', 155.0 );
		switch ( $model ) {
			case 'gpt-4o':
				$in_per_m = 2.50; $out_per_m = 10.00;
				break;
			case 'gpt-4o-mini':
			default:
				$in_per_m = 0.150; $out_per_m = 0.600;
				break;
		}
		$usd = ( $input_tokens * $in_per_m + $output_tokens * $out_per_m ) / 1_000_000;
		$jpy = $usd * $rate;
		return (int) round( $jpy * 1_000_000 );
	}
}
