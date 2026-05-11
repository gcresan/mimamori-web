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
	 * テキストナレッジを新規登録し、チャンクに分割保存する。
	 *
	 * @return int  作成された knowledge.id
	 */
	public static function add_text( int $tenant_id, string $title, string $raw_text, ?string $category = null ): int {
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
			'source_type' => 'text',
			'raw_text'    => $raw_text,
			'metadata'    => $metadata,
			'status'      => 'ready',
			'chunk_count' => count( $chunks ),
		], [ '%d','%s','%s','%s','%s','%s','%d' ] );
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
		Mimamori_Bot_Logger::info( 'knowledge: added', [
			'tenant_id' => $tenant_id, 'id' => $kid, 'chunks' => count( $chunks ),
		] );
		return $kid;
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
