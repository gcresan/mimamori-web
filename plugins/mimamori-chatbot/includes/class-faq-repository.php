<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Faq_Repository' ) ) { return; }

/**
 * Mimamori_Bot_Faq_Repository
 *
 * FAQ (高優先度Q&A) の CRUD。
 *   - is_starter=1 の項目は初期表示の質問例として使われる
 *   - priority 降順で並び替え
 *   - retriever で利用された際に hit_count を増やして「よく当たるFAQ」を抽出可能に
 */
class Mimamori_Bot_Faq_Repository {

	public static function create( int $tenant_id, array $fields ): int {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$wpdb->insert( $table, self::normalize( $tenant_id, $fields ), self::formats() );
		$id = (int) $wpdb->insert_id;
		// 埋め込み生成 (question 単体で意味的類似検索する想定)
		self::regenerate_embedding( $tenant_id, $id, (string) $fields['question'] );
		Mimamori_Bot_Logger::info( 'faq: created', [ 'tenant_id' => $tenant_id, 'id' => $id ] );
		return $id;
	}

	public static function update( int $tenant_id, int $id, array $fields ): bool {
		global $wpdb;
		$table  = Mimamori_Bot_Installer::table_faq();
		$data   = self::normalize( $tenant_id, $fields );
		unset( $data['tenant_id'] ); // tenant_id は WHERE 側でしか使わない
		$result = $wpdb->update(
			$table, $data,
			[ 'id' => $id, 'tenant_id' => $tenant_id ],
			self::formats( false ),
			[ '%d', '%d' ]
		);
		// 質問が変わった可能性があるので埋め込みも再生成
		self::regenerate_embedding( $tenant_id, $id, (string) $fields['question'] );
		return $result !== false;
	}

	/**
	 * FAQ の埋め込み (question ベース) を生成・保存する。
	 */
	public static function regenerate_embedding( int $tenant_id, int $id, string $question ): void {
		$embedder = new Mimamori_Bot_Embedder();
		$blob = $embedder->embed_one( $question );
		if ( $blob === null ) return;
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$wpdb->update( $table, [ 'embedding' => $blob ],
			[ 'id' => $id, 'tenant_id' => $tenant_id ],
			[ '%s' ], [ '%d', '%d' ]
		);
	}

	/**
	 * テナント全体の FAQ 埋め込み再生成 (Phase 2a データの後付け)。
	 *
	 * @return array{processed:int, saved:int}
	 */
	public static function reindex_tenant( int $tenant_id ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, question FROM {$table}
			  WHERE tenant_id = %d AND embedding IS NULL AND status = 'active'",
			$tenant_id
		), ARRAY_A );
		if ( ! $rows ) return [ 'processed' => 0, 'saved' => 0 ];

		$texts = array_map( static fn( $r ) => (string) $r['question'], $rows );
		$embedder = new Mimamori_Bot_Embedder();
		$blobs    = $embedder->embed_batch( $texts );

		$saved = 0;
		foreach ( $rows as $i => $r ) {
			$blob = $blobs[ $i ] ?? null;
			if ( $blob === null ) continue;
			$wpdb->update( $table, [ 'embedding' => $blob ],
				[ 'id' => (int) $r['id'], 'tenant_id' => $tenant_id ],
				[ '%s' ], [ '%d', '%d' ]
			);
			$saved++;
		}
		Mimamori_Bot_Logger::info( 'faq: reindex done', [
			'tenant_id' => $tenant_id, 'processed' => count( $rows ), 'saved' => $saved,
		] );
		return [ 'processed' => count( $rows ), 'saved' => $saved ];
	}

	public static function delete( int $tenant_id, int $id ): bool {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$result = $wpdb->delete( $table, [ 'id' => $id, 'tenant_id' => $tenant_id ], [ '%d', '%d' ] );
		Mimamori_Bot_Logger::info( 'faq: deleted', [ 'tenant_id' => $tenant_id, 'id' => $id ] );
		return (bool) $result;
	}

	public static function find( int $tenant_id, int $id ): ?array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE tenant_id = %d AND id = %d LIMIT 1",
			$tenant_id, $id
		), ARRAY_A );
		return $row ?: null;
	}

	public static function list_for_tenant( int $tenant_id, int $limit = 200 ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, question, answer, category, priority, is_starter, hit_count, status, updated_at
			   FROM {$table}
			  WHERE tenant_id = %d
			  ORDER BY is_starter DESC, priority DESC, id ASC
			  LIMIT %d",
			$tenant_id, $limit
		), ARRAY_A );
		return $rows ?: [];
	}

	/**
	 * 初期表示用スターター質問を取得。
	 *   - is_starter=1 かつ status='active'
	 *   - priority 降順 → id 昇順
	 *
	 * @return array list of question strings
	 */
	public static function list_starters( int $tenant_id, int $limit = 4 ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$rows  = $wpdb->get_col( $wpdb->prepare(
			"SELECT question FROM {$table}
			  WHERE tenant_id = %d AND is_starter = 1 AND status = 'active'
			  ORDER BY priority DESC, id ASC
			  LIMIT %d",
			$tenant_id, $limit
		) );
		return $rows ?: [];
	}

	public static function bump_hit_count( int $tenant_id, int $id ): void {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_faq();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET hit_count = hit_count + 1
			  WHERE tenant_id = %d AND id = %d",
			$tenant_id, $id
		) );
	}

	private static function normalize( int $tenant_id, array $fields ): array {
		$status = isset( $fields['status'] ) ? sanitize_key( (string) $fields['status'] ) : 'active';
		if ( ! in_array( $status, [ 'active', 'draft', 'disabled' ], true ) ) {
			$status = 'active';
		}
		return [
			'tenant_id'  => $tenant_id,
			'question'   => mb_substr( (string) ( $fields['question'] ?? '' ), 0, 500 ),
			'answer'     => (string) ( $fields['answer'] ?? '' ),
			'category'   => isset( $fields['category'] ) ? mb_substr( (string) $fields['category'], 0, 80 ) : null,
			'priority'   => (int) ( $fields['priority'] ?? 0 ),
			'is_starter' => ! empty( $fields['is_starter'] ) ? 1 : 0,
			'status'     => $status,
		];
	}

	/**
	 * insert/update 用フォーマット。update 時は tenant_id を含めないので
	 * $include_tenant=false で1要素少ない配列を返す。
	 */
	private static function formats( bool $include_tenant = true ): array {
		$fmt = $include_tenant ? [ '%d' ] : [];
		return array_merge( $fmt, [ '%s', '%s', '%s', '%d', '%d', '%s' ] );
	}
}
