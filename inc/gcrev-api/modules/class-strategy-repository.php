<?php
// FILE: inc/gcrev-api/modules/class-strategy-repository.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Strategy_Repository' ) ) { return; }

/**
 * Gcrev_Strategy_Repository
 *
 * クライアント戦略マスター（{prefix}gcrev_client_strategy）の CRUD を担当。
 *
 * バージョン運用ポリシー:
 *   - draft       … 作業中。1ユーザー複数あってよい
 *   - active      … 有効。1ユーザー1件のみ。新規 active 化で旧 active は archived に降格
 *   - archived    … 過去版。effective_until がセットされる
 *
 * 月次レポート生成時は get_active_for_month() で「対象月時点で有効だった戦略」を引く
 * （未来日に対して新しい active を切れるよう effective_from を尊重する）。
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Gcrev_Strategy_Repository {

	private function table(): string {
		return Gcrev_Strategy_Tables::client_strategy_table();
	}

	// =========================================================
	// 取得系
	// =========================================================

	/**
	 * ユーザーの「現在有効な」戦略を返す（active かつ effective_from <= 今日）。
	 *
	 * @return array|null 行配列（strategy_json は配列にデコード済み）
	 */
	public function get_active( int $user_id ): ?array {
		global $wpdb;
		$today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table()}
			 WHERE user_id = %d
			   AND status = 'active'
			   AND effective_from <= %s
			   AND ( effective_until IS NULL OR effective_until >= %s )
			 ORDER BY effective_from DESC, id DESC
			 LIMIT 1",
			$user_id,
			$today,
			$today
		), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * 指定 year_month（YYYY-MM）時点で有効だった戦略を返す。
	 * 月次レポート生成のメインエントリポイント。
	 */
	public function get_active_for_month( int $user_id, string $year_month ): ?array {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $year_month ) ) {
			return null;
		}
		global $wpdb;
		// 対象月の月末日で判定（その月の途中で改定されていれば、月末時点での active を採用）
		$month_end = ( new \DateTimeImmutable( $year_month . '-01', wp_timezone() ) )
			->modify( 'last day of this month' )
			->format( 'Y-m-d' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table()}
			 WHERE user_id = %d
			   AND status IN ('active','archived')
			   AND effective_from <= %s
			   AND ( effective_until IS NULL OR effective_until >= %s )
			 ORDER BY effective_from DESC, id DESC
			 LIMIT 1",
			$user_id,
			$month_end,
			$month_end
		), ARRAY_A );

		return $row ? $this->hydrate( $row ) : null;
	}

	/** ID 指定で1件取得 */
	public function get_by_id( int $strategy_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1",
			$strategy_id
		), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * バージョン履歴一覧（新しい順）
	 */
	public function get_versions( int $user_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, version, status, source_type, effective_from, effective_until, created_at
			 FROM {$this->table()}
			 WHERE user_id = %d
			 ORDER BY version DESC, id DESC
			 LIMIT %d",
			$user_id,
			$limit
		), ARRAY_A );
		return $rows ?: [];
	}

	/** ユーザーの最新 draft を1件返す（編集中の下書き復元用） */
	public function get_latest_draft( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table()}
			 WHERE user_id = %d AND status = 'draft'
			 ORDER BY id DESC
			 LIMIT 1",
			$user_id
		), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	// =========================================================
	// 保存系
	// =========================================================

	/**
	 * 新規バージョンを作成する（draft または active で保存）。
	 *
	 * @param int    $user_id
	 * @param array  $strategy_json    Schema_Validator で normalize 済みの配列
	 * @param string $status           'draft' | 'active'
	 * @param string $source_type      'manual' | 'pdf' | 'pdf_edited'
	 * @param int    $created_by       操作した WP ユーザーID
	 * @param int|null $source_file_id 添付ファイルID（PDF原本）
	 * @return int 作成された戦略行ID
	 */
	public function create_version(
		int $user_id,
		array $strategy_json,
		string $status,
		string $source_type,
		int $created_by,
		?int $source_file_id = null
	): int {
		if ( ! in_array( $status, [ 'draft', 'active' ], true ) ) {
			throw new \InvalidArgumentException( 'status must be draft or active' );
		}
		if ( ! in_array( $source_type, [ 'manual', 'pdf', 'pdf_edited' ], true ) ) {
			throw new \InvalidArgumentException( 'invalid source_type' );
		}

		global $wpdb;
		$now           = current_time( 'mysql', false );
		$next_version  = $this->next_version_for_user( $user_id );
		$effective_from = $strategy_json['meta']['effective_from'] ?? '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $effective_from ) ) {
			$effective_from = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		}

		// active で保存する場合は、先に旧 active を archived に降格
		if ( $status === 'active' ) {
			$this->archive_active_for_user( $user_id, $effective_from );
		}

		$wpdb->insert(
			$this->table(),
			[
				'user_id'         => $user_id,
				'version'         => $next_version,
				'status'          => $status,
				'source_type'     => $source_type,
				'source_file_id'  => $source_file_id,
				'strategy_json'   => wp_json_encode( $strategy_json, JSON_UNESCAPED_UNICODE ),
				'effective_from'  => $effective_from,
				'effective_until' => null,
				'created_by'      => $created_by,
				'created_at'      => $now,
				'updated_at'      => $now,
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * draft 行を上書き保存する（同じ id を維持）。
	 */
	public function update_draft( int $strategy_id, array $strategy_json ): bool {
		global $wpdb;
		$now = current_time( 'mysql', false );

		// 念のため status='draft' のものだけ更新
		$updated = $wpdb->update(
			$this->table(),
			[
				'strategy_json' => wp_json_encode( $strategy_json, JSON_UNESCAPED_UNICODE ),
				'updated_at'    => $now,
			],
			[ 'id' => $strategy_id, 'status' => 'draft' ],
			[ '%s', '%s' ],
			[ '%d', '%s' ]
		);
		return $updated !== false && $updated > 0;
	}

	/**
	 * draft を active に昇格させる。
	 * 旧 active は archived に降格（effective_until = 新版 effective_from の前日）。
	 */
	public function activate_draft( int $strategy_id ): bool {
		global $wpdb;
		$row = $this->get_by_id( $strategy_id );
		if ( ! $row || $row['status'] !== 'draft' ) {
			return false;
		}
		$user_id        = (int) $row['user_id'];
		$effective_from = (string) $row['effective_from'];

		$this->archive_active_for_user( $user_id, $effective_from );

		$now = current_time( 'mysql', false );
		$updated = $wpdb->update(
			$this->table(),
			[ 'status' => 'active', 'updated_at' => $now ],
			[ 'id' => $strategy_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		return $updated !== false;
	}

	/**
	 * draft を物理削除する（active / archived は削除させない — 監査痕跡を残す）
	 */
	public function delete_draft( int $strategy_id ): bool {
		global $wpdb;
		$deleted = $wpdb->delete(
			$this->table(),
			[ 'id' => $strategy_id, 'status' => 'draft' ],
			[ '%d', '%s' ]
		);
		return $deleted !== false && $deleted > 0;
	}

	// =========================================================
	// 内部ヘルパー
	// =========================================================

	private function next_version_for_user( int $user_id ): int {
		global $wpdb;
		$max = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(version) FROM {$this->table()} WHERE user_id = %d",
			$user_id
		) );
		return $max + 1;
	}

	/**
	 * ユーザーの現在 active な戦略を archived に降格させる。
	 * effective_until は「新 active の effective_from の前日」をセット。
	 */
	private function archive_active_for_user( int $user_id, string $new_effective_from ): void {
		global $wpdb;
		$prev_day = ( new \DateTimeImmutable( $new_effective_from, wp_timezone() ) )
			->modify( '-1 day' )
			->format( 'Y-m-d' );

		$wpdb->update(
			$this->table(),
			[
				'status'          => 'archived',
				'effective_until' => $prev_day,
				'updated_at'      => current_time( 'mysql', false ),
			],
			[ 'user_id' => $user_id, 'status' => 'active' ],
			[ '%s', '%s', '%s' ],
			[ '%d', '%s' ]
		);
	}

	/**
	 * DB 行を呼び出し側に返す形に整える（strategy_json はデコード済み配列にする）
	 */
	private function hydrate( array $row ): array {
		$decoded = is_string( $row['strategy_json'] )
			? json_decode( $row['strategy_json'], true )
			: ( is_array( $row['strategy_json'] ) ? $row['strategy_json'] : [] );

		$row['strategy_json']     = is_array( $decoded ) ? $decoded : [];
		$row['id']                = (int) $row['id'];
		$row['user_id']           = (int) $row['user_id'];
		$row['version']           = (int) $row['version'];
		$row['source_file_id']    = isset( $row['source_file_id'] ) ? (int) $row['source_file_id'] : null;
		$row['created_by']        = (int) $row['created_by'];
		return $row;
	}
}
