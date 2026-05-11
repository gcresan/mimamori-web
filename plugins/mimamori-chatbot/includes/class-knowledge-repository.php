<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Knowledge_Repository' ) ) { return; }

/**
 * Mimamori_Bot_Knowledge_Repository
 *
 * Phase 2a: テキスト入力のナレッジ管理 + 段落単位チャンカ。
 * Phase 2b で予定: ファイル抽出 (pdf/docx/xlsx/csv/html/img) + 埋め込み生成。
 */
class Mimamori_Bot_Knowledge_Repository {

	/** チャンク1個あたりの最大文字数 (日本語1文字≒1.5トークン想定で〜800tokens程度) */
	public const MAX_CHUNK_CHARS = 500;

	/**
	 * テキストナレッジを新規登録し、チャンクに分割 + 埋め込み生成して保存する。
	 *
	 * @return int  作成された knowledge.id
	 */
	public static function add_text( int $tenant_id, string $title, string $raw_text, ?string $category = null, string $source_type = 'text', ?string $mime_type = null ): int {
		global $wpdb;
		$kt = Mimamori_Bot_Installer::table_knowledge();
		$ct = Mimamori_Bot_Installer::table_knowledge_chunks();

		$raw_text = trim( $raw_text );
		if ( $raw_text === '' ) {
			throw new InvalidArgumentException( 'raw_text is empty' );
		}
		$chunks = self::chunk( $raw_text, self::MAX_CHUNK_CHARS );

		$metadata = wp_json_encode(
			[ 'category' => $category ],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$wpdb->insert( $kt, [
			'tenant_id'   => $tenant_id,
			'title'       => mb_substr( $title, 0, 255 ),
			'source_type' => $source_type,
			'mime_type'   => $mime_type,
			'raw_text'    => $raw_text,
			'metadata'    => $metadata,
			'status'      => 'processing',
			'chunk_count' => count( $chunks ),
		], [ '%d','%s','%s','%s','%s','%s','%s','%d' ] );
		$kid = (int) $wpdb->insert_id;

		foreach ( $chunks as $i => $content ) {
			$wpdb->insert( $ct, [
				'tenant_id'     => $tenant_id,
				'knowledge_id'  => $kid,
				'chunk_index'   => $i,
				'content'       => $content,
				'token_count'   => null,
			], [ '%d','%d','%d','%s','%s' ] );
		}

		// 埋め込み生成 (失敗しても status='ready' で進める。retriever が 2-gram にフォールバック)
		self::generate_embeddings_for_knowledge( $tenant_id, $kid );

		$wpdb->update( $kt, [ 'status' => 'ready' ], [ 'id' => $kid, 'tenant_id' => $tenant_id ], [ '%s' ], [ '%d', '%d' ] );

		Mimamori_Bot_Logger::info( 'knowledge: added', [
			'tenant_id' => $tenant_id, 'id' => $kid, 'chunks' => count( $chunks ), 'source' => $source_type,
		] );
		return $kid;
	}

	/**
	 * ファイルアップロード経由でナレッジを追加する。
	 * 抽出 → add_text() に委譲 → 埋め込み生成。
	 *
	 * @param array $file_info  $_FILES['xxx'] 形式
	 */
	public static function add_from_upload( int $tenant_id, array $file_info, ?string $title_override = null, ?string $category = null ): int {
		$result = Mimamori_Bot_Extractor::extract_from_upload( $file_info );
		$title  = $title_override !== null && $title_override !== '' ? $title_override : $result['title'];
		return self::add_text(
			$tenant_id,
			$title,
			$result['raw_text'],
			$category,
			'file',
			$result['mime']
		);
	}

	/**
	 * 指定 knowledge_id 配下のチャンク embedding を (再)生成して保存する。
	 *
	 * @return int  embedding を生成・保存できたチャンク数
	 */
	public static function generate_embeddings_for_knowledge( int $tenant_id, int $knowledge_id ): int {
		global $wpdb;
		$ct = Mimamori_Bot_Installer::table_knowledge_chunks();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, content FROM {$ct}
			  WHERE tenant_id = %d AND knowledge_id = %d
			  ORDER BY chunk_index ASC",
			$tenant_id, $knowledge_id
		), ARRAY_A );
		if ( ! $rows ) return 0;

		$texts = array_map( static fn( $r ) => (string) $r['content'], $rows );
		$embedder = new Mimamori_Bot_Embedder();
		$blobs    = $embedder->embed_batch( $texts );

		$saved = 0;
		foreach ( $rows as $i => $r ) {
			$blob = $blobs[ $i ] ?? null;
			if ( $blob === null ) continue;
			$wpdb->update(
				$ct,
				[
					'embedding'         => $blob,
					'embedding_model'   => Mimamori_Bot_Embedder::DEFAULT_MODEL,
					'embedding_version' => 1,
				],
				[ 'id' => (int) $r['id'], 'tenant_id' => $tenant_id ],
				[ '%s', '%s', '%d' ],
				[ '%d', '%d' ]
			);
			$saved++;
		}
		Mimamori_Bot_Logger::info( 'knowledge: embeddings generated', [
			'tenant_id' => $tenant_id, 'id' => $knowledge_id, 'saved' => $saved, 'total' => count( $rows ),
		] );
		return $saved;
	}

	/**
	 * テナント全体の埋め込み再生成。Phase 2a で登録されたデータの後付け用。
	 * 既に embedding を持っているチャンクはスキップする。
	 *
	 * @return array{processed:int, saved:int}
	 */
	public static function reindex_tenant( int $tenant_id ): array {
		global $wpdb;
		$ct = Mimamori_Bot_Installer::table_knowledge_chunks();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, content FROM {$ct}
			  WHERE tenant_id = %d AND embedding IS NULL",
			$tenant_id
		), ARRAY_A );
		if ( ! $rows ) return [ 'processed' => 0, 'saved' => 0 ];

		$texts = array_map( static fn( $r ) => (string) $r['content'], $rows );
		$embedder = new Mimamori_Bot_Embedder();
		$blobs    = $embedder->embed_batch( $texts );

		$saved = 0;
		foreach ( $rows as $i => $r ) {
			$blob = $blobs[ $i ] ?? null;
			if ( $blob === null ) continue;
			$wpdb->update(
				$ct,
				[
					'embedding'         => $blob,
					'embedding_model'   => Mimamori_Bot_Embedder::DEFAULT_MODEL,
					'embedding_version' => 1,
				],
				[ 'id' => (int) $r['id'], 'tenant_id' => $tenant_id ],
				[ '%s', '%s', '%d' ],
				[ '%d', '%d' ]
			);
			$saved++;
		}
		Mimamori_Bot_Logger::info( 'knowledge: reindex done', [
			'tenant_id' => $tenant_id, 'processed' => count( $rows ), 'saved' => $saved,
		] );
		return [ 'processed' => count( $rows ), 'saved' => $saved ];
	}

	public static function delete( int $tenant_id, int $id ): bool {
		global $wpdb;
		$kt = Mimamori_Bot_Installer::table_knowledge();
		$ct = Mimamori_Bot_Installer::table_knowledge_chunks();
		// tenant_id 一致を必ず WHERE に含めてクロステナント削除を防ぐ
		$wpdb->delete( $ct, [ 'tenant_id' => $tenant_id, 'knowledge_id' => $id ], [ '%d', '%d' ] );
		$result = $wpdb->delete( $kt, [ 'tenant_id' => $tenant_id, 'id' => $id ], [ '%d', '%d' ] );
		Mimamori_Bot_Logger::info( 'knowledge: deleted', [ 'tenant_id' => $tenant_id, 'id' => $id ] );
		return (bool) $result;
	}

	/**
	 * @return array  hydrated rows
	 */
	public static function list_for_tenant( int $tenant_id, int $limit = 100 ): array {
		global $wpdb;
		$kt = Mimamori_Bot_Installer::table_knowledge();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, source_type, status, chunk_count, updated_at
			   FROM {$kt}
			  WHERE tenant_id = %d
			  ORDER BY updated_at DESC
			  LIMIT %d",
			$tenant_id, $limit
		), ARRAY_A );
		return $rows ?: [];
	}

	public static function find( int $tenant_id, int $id ): ?array {
		global $wpdb;
		$kt = Mimamori_Bot_Installer::table_knowledge();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$kt} WHERE tenant_id = %d AND id = %d LIMIT 1",
			$tenant_id, $id
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * 段落優先のチャンク分割。
	 *   1) 空行で段落に分割
	 *   2) 段落を結合しつつ max_chars を超えそうな時点で flush
	 *   3) 単一段落が max_chars を超える場合は句読点で分割、それでも超えるなら強制スライス
	 *
	 * @return string[]
	 */
	public static function chunk( string $text, int $max_chars = self::MAX_CHUNK_CHARS ): array {
		$text = trim( $text );
		if ( $text === '' ) return [];

		$paragraphs = preg_split( '/\R\s*\R/u', $text ) ?: [];
		$chunks  = [];
		$buffer  = '';
		$flush = static function () use ( &$buffer, &$chunks ) {
			$b = trim( $buffer );
			if ( $b !== '' ) $chunks[] = $b;
			$buffer = '';
		};

		foreach ( $paragraphs as $p ) {
			$p = trim( $p );
			if ( $p === '' ) continue;

			if ( mb_strlen( $p ) > $max_chars ) {
				if ( $buffer !== '' ) $flush();
				$sentences = preg_split( '/(?<=[。\!\?！？])/u', $p ) ?: [ $p ];
				foreach ( $sentences as $s ) {
					$s = trim( $s );
					if ( $s === '' ) continue;
					if ( mb_strlen( $s ) > $max_chars ) {
						// 強制スライス
						for ( $i = 0, $len = mb_strlen( $s ); $i < $len; $i += $max_chars ) {
							$chunks[] = mb_substr( $s, $i, $max_chars );
						}
						continue;
					}
					if ( mb_strlen( $buffer . $s ) > $max_chars ) $flush();
					$buffer .= ( $buffer === '' ? '' : '' ) . $s;
				}
				if ( $buffer !== '' ) $flush();
				continue;
			}

			if ( mb_strlen( $buffer . "\n\n" . $p ) > $max_chars && $buffer !== '' ) {
				$flush();
			}
			$buffer = $buffer === '' ? $p : $buffer . "\n\n" . $p;
		}
		if ( $buffer !== '' ) $flush();
		return $chunks;
	}
}
