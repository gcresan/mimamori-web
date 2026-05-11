<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Rag_Retriever' ) ) { return; }

/**
 * Mimamori_Bot_Rag_Retriever
 *
 * Phase 2a: PHP側スコアリングのみのシンプルRAG。
 *   - クエリを 2-gram (mb_substr) に分解
 *   - チャンク/FAQをテナント全件取得 (500行未満想定)
 *   - mb_substr_count でマッチ数を数えてスコア化
 *   - 上位を返す
 *
 * Phase 2b で埋め込みベクトルとハイブリッド化する想定。
 */
class Mimamori_Bot_Rag_Retriever {

	private const NGRAM_SIZE       = 2;
	private const MAX_TOKENS       = 20;
	private const STOP_CHARS_REGEX = '/[\s　、。！？!?・,.]/u';

	/**
	 * @return array{chunks:array<array>, faqs:array<array>}
	 */
	public function retrieve( int $tenant_id, string $query, int $top_chunks = 4, int $top_faqs = 2 ): array {
		$tokens = $this->tokenize( $query );
		if ( empty( $tokens ) ) {
			return [ 'chunks' => [], 'faqs' => [] ];
		}
		$chunks = $this->retrieve_chunks( $tenant_id, $tokens, $top_chunks );
		$faqs   = $this->retrieve_faqs( $tenant_id, $tokens, $top_faqs );

		Mimamori_Bot_Logger::info( 'retriever: hit', [
			'tenant_id'   => $tenant_id,
			'tokens'      => count( $tokens ),
			'chunks_hit'  => count( $chunks ),
			'faqs_hit'    => count( $faqs ),
		] );
		return [ 'chunks' => $chunks, 'faqs' => $faqs ];
	}

	/**
	 * クエリを 2-gram トークン列に変換。
	 * 助詞・句読点・空白は分割境界として扱い、それ以外の連続文字列を 2-gram スライス。
	 *
	 * @return string[] unique 2-gram tokens
	 */
	public function tokenize( string $query ): array {
		$query = trim( $query );
		if ( $query === '' ) return [];

		// 区切り文字で分解 → 各セグメント内で 2-gram
		$segments = preg_split( self::STOP_CHARS_REGEX, $query ) ?: [];
		$tokens   = [];
		foreach ( $segments as $seg ) {
			$len = mb_strlen( $seg );
			if ( $len < self::NGRAM_SIZE ) {
				if ( $len > 0 ) $tokens[] = $seg;
				continue;
			}
			for ( $i = 0; $i <= $len - self::NGRAM_SIZE; $i++ ) {
				$tokens[] = mb_substr( $seg, $i, self::NGRAM_SIZE );
			}
		}
		$tokens = array_values( array_unique( array_filter( $tokens, static fn( $t ) => $t !== '' ) ) );
		if ( count( $tokens ) > self::MAX_TOKENS ) {
			$tokens = array_slice( $tokens, 0, self::MAX_TOKENS );
		}
		return $tokens;
	}

	/**
	 * @param string[] $tokens
	 * @return array<int,array{id:int,knowledge_id:int,chunk_index:int,content:string,score:int}>
	 */
	private function retrieve_chunks( int $tenant_id, array $tokens, int $top_k ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_knowledge_chunks();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, knowledge_id, chunk_index, content
			   FROM {$table}
			  WHERE tenant_id = %d
			  ORDER BY id DESC
			  LIMIT 1000",  // テナント上限ガード
			$tenant_id
		), ARRAY_A );
		if ( ! $rows ) return [];

		$scored = [];
		foreach ( $rows as $row ) {
			$score = 0;
			foreach ( $tokens as $t ) {
				$score += mb_substr_count( $row['content'], $t );
			}
			if ( $score > 0 ) {
				$row['id']           = (int) $row['id'];
				$row['knowledge_id'] = (int) $row['knowledge_id'];
				$row['chunk_index']  = (int) $row['chunk_index'];
				$row['score']        = $score;
				$scored[]            = $row;
			}
		}
		usort( $scored, static fn( $a, $b ) => $b['score'] - $a['score'] );
		return array_slice( $scored, 0, $top_k );
	}

	/**
	 * @param string[] $tokens
	 * @return array<int,array{id:int,question:string,answer:string,score:int}>
	 */
	private function retrieve_faqs( int $tenant_id, array $tokens, int $top_k ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, question, answer, priority
			   FROM {$table}
			  WHERE tenant_id = %d AND status = 'active'
			  LIMIT 500",
			$tenant_id
		), ARRAY_A );
		if ( ! $rows ) return [];

		$scored = [];
		foreach ( $rows as $row ) {
			$score = 0;
			$haystack = $row['question'] . "\n" . $row['answer'];
			foreach ( $tokens as $t ) {
				// 質問側のヒットは2倍重み付け
				$score += mb_substr_count( $row['question'], $t ) * 2;
				$score += mb_substr_count( $row['answer'], $t );
			}
			$score += (int) ( $row['priority'] ?? 0 ); // priority を加点
			if ( $score > 0 ) {
				$scored[] = [
					'id'       => (int) $row['id'],
					'question' => (string) $row['question'],
					'answer'   => (string) $row['answer'],
					'score'    => $score,
				];
			}
		}
		usort( $scored, static fn( $a, $b ) => $b['score'] - $a['score'] );
		return array_slice( $scored, 0, $top_k );
	}

	/**
	 * LLM 用 system プロンプト末尾に挿入するコンテキスト文字列を構築する。
	 * 空文字を返す = 該当なし。
	 */
	public function build_context_block( array $retrieved ): string {
		$blocks = [];
		if ( ! empty( $retrieved['chunks'] ) ) {
			$lines = [ "KNOWLEDGE (利用者の質問に関連するナレッジ。回答時はこの内容のみを根拠とすること):" ];
			foreach ( $retrieved['chunks'] as $c ) {
				$lines[] = sprintf( '[#k%d] %s', $c['id'], $c['content'] );
			}
			$blocks[] = implode( "\n", $lines );
		}
		if ( ! empty( $retrieved['faqs'] ) ) {
			$lines = [ "FAQ (高精度な過去回答):" ];
			foreach ( $retrieved['faqs'] as $f ) {
				$lines[] = sprintf( "[#f%d]\nQ: %s\nA: %s", $f['id'], $f['question'], $f['answer'] );
			}
			$blocks[] = implode( "\n", $lines );
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * messages.knowledge_refs に保存する JSON 形式の出典配列。
	 */
	public function build_refs_payload( array $retrieved ): string {
		$out = [
			'chunks' => array_map( static fn( $c ) => [ 'id' => $c['id'], 'knowledge_id' => $c['knowledge_id'], 'score' => $c['score'] ], $retrieved['chunks'] ?? [] ),
			'faqs'   => array_map( static fn( $f ) => [ 'id' => $f['id'], 'score' => $f['score'] ], $retrieved['faqs'] ?? [] ),
		];
		return (string) wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
