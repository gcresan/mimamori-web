<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Embedder' ) ) { return; }

/**
 * Mimamori_Bot_Embedder
 *
 * OpenAI Embeddings API 連携 + ベクトル類似度計算。
 *
 *   - モデル: text-embedding-3-small (1536次元)
 *   - 保存形式: pack('g*', $floats) で little-endian float32 1536個 = 6,144 bytes / chunk
 *   - L2正規化を保存時に適用するため、検索時のコサイン類似度は内積のみで計算可能
 *
 * バッチ: 一度の API 呼び出しで最大 100 件の input を embedding 化する。
 *
 * 失敗時の方針: 例外を投げず WP_Error を返すか null を返す。
 * 埋め込みが取れなくてもナレッジ自体は保存され、retrieve は 2-gram にフォールバックする。
 */
class Mimamori_Bot_Embedder {

	public const DEFAULT_MODEL = 'text-embedding-3-small';
	public const DIMENSIONS    = 1536;
	private const ENDPOINT     = 'https://api.openai.com/v1/embeddings';
	private const BATCH_SIZE   = 64;
	private const TIMEOUT      = 60;

	/**
	 * 単一テキストを埋め込む。L2正規化済みの BLOB を返す。
	 *
	 * @return string|null  BLOB or null
	 */
	public function embed_one( string $text ): ?string {
		$results = $this->embed_batch( [ $text ] );
		return $results[0] ?? null;
	}

	/**
	 * 複数テキストをバッチで埋め込む。インデックス順を保ったまま L2正規化済み BLOB 配列を返す。
	 * 取得失敗した要素は null になる。
	 *
	 * @param string[] $texts
	 * @return array<int, string|null>
	 */
	public function embed_batch( array $texts ): array {
		$result = array_fill( 0, count( $texts ), null );
		if ( empty( $texts ) ) return $result;

		$api_key = $this->resolve_api_key();
		if ( $api_key === '' ) {
			Mimamori_Bot_Logger::warn( 'embedder: no API key, skipping embedding generation' );
			return $result;
		}

		$keys   = array_keys( $texts );
		$chunks = array_chunk( $keys, self::BATCH_SIZE );
		foreach ( $chunks as $batch_keys ) {
			$batch_inputs = [];
			foreach ( $batch_keys as $k ) {
				// API は空文字を許可しない
				$t = trim( (string) $texts[ $k ] );
				$batch_inputs[ $k ] = $t === '' ? '(empty)' : $t;
			}
			$payload = [
				'model'           => self::DEFAULT_MODEL,
				'input'           => array_values( $batch_inputs ),
				'encoding_format' => 'float',
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
				Mimamori_Bot_Logger::error( 'embedder: http error', [
					'msg'     => $response->get_error_message(),
					'latency' => $latency,
				] );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			if ( $code < 200 || $code >= 300 ) {
				Mimamori_Bot_Logger::error( 'embedder: bad status', [
					'code'    => $code,
					'body'    => substr( $body, 0, 400 ),
					'latency' => $latency,
				] );
				continue;
			}
			$json = json_decode( $body, true );
			if ( ! isset( $json['data'] ) || ! is_array( $json['data'] ) ) {
				Mimamori_Bot_Logger::error( 'embedder: malformed response', [ 'body' => substr( $body, 0, 200 ) ] );
				continue;
			}
			// data[i].index は呼び出し時の配列順 (0..N-1)
			foreach ( $json['data'] as $item ) {
				$idx_in_batch = (int) ( $item['index'] ?? -1 );
				if ( $idx_in_batch < 0 || ! isset( $batch_keys[ $idx_in_batch ] ) ) continue;
				$floats = $item['embedding'] ?? null;
				if ( ! is_array( $floats ) || count( $floats ) !== self::DIMENSIONS ) continue;
				$floats = self::l2_normalize( $floats );
				$result[ $batch_keys[ $idx_in_batch ] ] = pack( 'g*', ...array_map( 'floatval', $floats ) );
			}
			Mimamori_Bot_Logger::info( 'embedder: batch ok', [
				'count'   => count( $batch_keys ),
				'latency' => $latency,
			] );
		}
		return $result;
	}

	/**
	 * BLOB を float[] にデコード。
	 *
	 * @return float[]|null  1536次元配列 (0-indexed)
	 */
	public static function unpack_blob( ?string $blob ): ?array {
		if ( $blob === null || $blob === '' ) return null;
		if ( strlen( $blob ) !== self::DIMENSIONS * 4 ) {
			return null;
		}
		$arr = unpack( 'g*', $blob );
		if ( ! is_array( $arr ) ) return null;
		// unpack returns 1-indexed array; reindex to 0
		return array_values( $arr );
	}

	/**
	 * 既に L2 正規化されている前提でのコサイン類似度（= 内積）。
	 */
	public static function cosine_sim_normalized( array $a, array $b ): float {
		$n = min( count( $a ), count( $b ) );
		$dot = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
		}
		return $dot;
	}

	private static function l2_normalize( array $v ): array {
		$sum = 0.0;
		foreach ( $v as $x ) { $sum += $x * $x; }
		$norm = sqrt( $sum );
		if ( $norm < 1e-12 ) return $v;
		$out = [];
		foreach ( $v as $x ) { $out[] = $x / $norm; }
		return $out;
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

	/**
	 * 検索クエリの埋め込みを transient に短期キャッシュする。
	 * 同一質問の連投時に API コール節約。
	 */
	public function embed_query_cached( string $query ): ?string {
		$query = trim( $query );
		if ( $query === '' ) return null;
		$key = 'mb_qe_' . md5( $query );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}
		$blob = $this->embed_one( $query );
		if ( $blob !== null ) {
			set_transient( $key, $blob, 600 ); // 10分
		}
		return $blob;
	}
}
