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

	/** Reciprocal Rank Fusion の定数 (Cormack et al. 2009) */
	private const RRF_K = 60;

	/** ハイブリッド時の各ソースから取る件数 (RRF前) */
	private const VECTOR_TOPN = 12;
	private const LEXICAL_TOPN = 12;

	/**
	 * @return array{chunks:array<array>, faqs:array<array>}
	 */
	public function retrieve( int $tenant_id, string $query, int $top_chunks = 4, int $top_faqs = 2 ): array {
		$tokens = $this->tokenize( $query );
		if ( empty( $tokens ) ) {
			return [ 'chunks' => [], 'faqs' => [] ];
		}

		// クエリ埋め込み (失敗時は null → 2-gram のみで動作)
		$embedder  = new Mimamori_Bot_Embedder();
		$query_emb_blob = $embedder->embed_query_cached( $query );
		$query_emb = $query_emb_blob ? Mimamori_Bot_Embedder::unpack_blob( $query_emb_blob ) : null;

		$chunks = $this->retrieve_chunks_hybrid( $tenant_id, $tokens, $query_emb, $top_chunks );
		$faqs   = $this->retrieve_faqs_hybrid(   $tenant_id, $tokens, $query_emb, $top_faqs );

		Mimamori_Bot_Logger::info( 'retriever: hit', [
			'tenant_id'   => $tenant_id,
			'tokens'      => count( $tokens ),
			'vector'      => $query_emb !== null,
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
	 * チャンクをハイブリッド検索する。
	 *   - ベクタ: クエリ埋め込みと chunks.embedding の内積 (L2正規化済前提) で top-N
	 *   - 字面 : 2-gram トークンの mb_substr_count スコアで top-N
	 *   - RRF で融合 → top_k 返却
	 *
	 * @param string[]    $tokens
	 * @param float[]|null $query_emb
	 * @return array<int,array{id:int,knowledge_id:int,chunk_index:int,content:string,score:float,sources:string[]}>
	 */
	private function retrieve_chunks_hybrid( int $tenant_id, array $tokens, ?array $query_emb, int $top_k ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_knowledge_chunks();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, knowledge_id, chunk_index, content, embedding
			   FROM {$table}
			  WHERE tenant_id = %d
			  ORDER BY id DESC
			  LIMIT 1000",
			$tenant_id
		), ARRAY_A );
		if ( ! $rows ) return [];

		$lexical = [];
		$vector  = [];
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			// 字面スコア
			$lex = 0;
			foreach ( $tokens as $t ) {
				$lex += mb_substr_count( $row['content'], $t );
			}
			if ( $lex > 0 ) {
				$lexical[] = [ 'id' => $id, 'score' => $lex ];
			}

			// ベクタスコア
			if ( $query_emb !== null && ! empty( $row['embedding'] ) ) {
				$chunk_emb = Mimamori_Bot_Embedder::unpack_blob( (string) $row['embedding'] );
				if ( is_array( $chunk_emb ) ) {
					$sim = Mimamori_Bot_Embedder::cosine_sim_normalized( $query_emb, $chunk_emb );
					if ( $sim > 0.20 ) { // 低類似はノイズ除外
						$vector[] = [ 'id' => $id, 'score' => $sim ];
					}
				}
			}
		}

		usort( $lexical, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		usort( $vector,  static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		$lexical = array_slice( $lexical, 0, self::LEXICAL_TOPN );
		$vector  = array_slice( $vector,  0, self::VECTOR_TOPN );

		// RRF
		$fused = self::rrf( [ 'lex' => $lexical, 'vec' => $vector ] );
		if ( empty( $fused ) ) return [];

		// 上位 top_k だけ rows から再構築
		$rows_by_id = [];
		foreach ( $rows as $r ) {
			$rows_by_id[ (int) $r['id'] ] = $r;
		}
		$out = [];
		foreach ( array_slice( $fused, 0, $top_k, true ) as $id => $entry ) {
			if ( ! isset( $rows_by_id[ $id ] ) ) continue;
			$r = $rows_by_id[ $id ];
			$out[] = [
				'id'           => (int) $r['id'],
				'knowledge_id' => (int) $r['knowledge_id'],
				'chunk_index'  => (int) $r['chunk_index'],
				'content'      => (string) $r['content'],
				'score'        => $entry['score'],
				'sources'      => $entry['sources'],
			];
		}
		return $out;
	}

	/**
	 * FAQ をハイブリッド検索する。
	 *
	 * @param string[]    $tokens
	 * @param float[]|null $query_emb
	 * @return array<int,array{id:int,question:string,answer:string,score:float,sources:string[]}>
	 */
	private function retrieve_faqs_hybrid( int $tenant_id, array $tokens, ?array $query_emb, int $top_k ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, question, answer, priority, embedding
			   FROM {$table}
			  WHERE tenant_id = %d AND status = 'active'
			  LIMIT 500",
			$tenant_id
		), ARRAY_A );
		if ( ! $rows ) return [];

		$lexical = [];
		$vector  = [];
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			$lex = 0;
			foreach ( $tokens as $t ) {
				$lex += mb_substr_count( $row['question'], $t ) * 2;
				$lex += mb_substr_count( $row['answer'],   $t );
			}
			$lex += (int) ( $row['priority'] ?? 0 );
			if ( $lex > 0 ) {
				$lexical[] = [ 'id' => $id, 'score' => $lex ];
			}

			if ( $query_emb !== null && ! empty( $row['embedding'] ) ) {
				$faq_emb = Mimamori_Bot_Embedder::unpack_blob( (string) $row['embedding'] );
				if ( is_array( $faq_emb ) ) {
					$sim = Mimamori_Bot_Embedder::cosine_sim_normalized( $query_emb, $faq_emb );
					if ( $sim > 0.20 ) {
						$vector[] = [ 'id' => $id, 'score' => $sim ];
					}
				}
			}
		}

		usort( $lexical, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		usort( $vector,  static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		$lexical = array_slice( $lexical, 0, self::LEXICAL_TOPN );
		$vector  = array_slice( $vector,  0, self::VECTOR_TOPN );

		$fused = self::rrf( [ 'lex' => $lexical, 'vec' => $vector ] );
		if ( empty( $fused ) ) return [];

		$rows_by_id = [];
		foreach ( $rows as $r ) {
			$rows_by_id[ (int) $r['id'] ] = $r;
		}
		$out = [];
		foreach ( array_slice( $fused, 0, $top_k, true ) as $id => $entry ) {
			if ( ! isset( $rows_by_id[ $id ] ) ) continue;
			$r = $rows_by_id[ $id ];
			$out[] = [
				'id'       => (int) $r['id'],
				'question' => (string) $r['question'],
				'answer'   => (string) $r['answer'],
				'score'    => $entry['score'],
				'sources'  => $entry['sources'],
			];
		}
		return $out;
	}

	/**
	 * Reciprocal Rank Fusion: 複数のランキングを RRF スコアで融合し、
	 *   id => ['score' => float, 'sources' => string[]] を score 降順で返す。
	 *
	 * @param array<string,array<int,array{id:int,score:float|int}>> $lists  source_name => ranked list (sorted desc)
	 * @return array<int,array{score:float,sources:string[]}>  key=id, sorted desc by score
	 */
	private static function rrf( array $lists ): array {
		$out = [];
		foreach ( $lists as $source => $list ) {
			$rank = 0;
			foreach ( $list as $row ) {
				$id = (int) $row['id'];
				$contrib = 1.0 / ( self::RRF_K + $rank + 1 );
				if ( ! isset( $out[ $id ] ) ) {
					$out[ $id ] = [ 'score' => 0.0, 'sources' => [] ];
				}
				$out[ $id ]['score']    += $contrib;
				$out[ $id ]['sources'][] = $source;
				$rank++;
			}
		}
		uasort( $out, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return $out;
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
