<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Tenant_Repository' ) ) { return; }

/**
 * Mimamori_Bot_Tenant_Repository
 *
 * テナント (chatbot_tenants) CRUD。
 *
 * テナント分離方針:
 *   - 公開API は public_key を解決キーにする
 *   - 管理API は current WP user_id とテナントの user_id が一致することを照合
 *   - find_for_user() は管理API側、find_by_public_key() は公開API側専用
 */
class Mimamori_Bot_Tenant_Repository {

	/**
	 * @return array|null  連想配列、なければ null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_tenants();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id
		), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function find_by_slug( string $slug ): ?array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_tenants();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug
		), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function find_by_public_key( string $public_key ): ?array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_tenants();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE public_key = %s LIMIT 1", $public_key
		), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function find_for_user( int $user_id ): ?array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_tenants();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT 1", $user_id
		), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * 指定ユーザーが所有する全テナント (1ユーザー複数所有を将来許容するための内部API)。
	 * @return array<int,array>
	 */
	public static function list_for_user( int $user_id, int $limit = 50 ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_tenants();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d",
			$user_id, $limit
		), ARRAY_A );
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * 全テナント一覧。運営者 (manage_options) のみ呼び出すべき。
	 * @return array<int,array>
	 */
	public static function list_all( int $limit = 200 ): array {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_tenants();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY id ASC LIMIT %d", $limit
		), ARRAY_A );
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	public static function count_all(): int {
		global $wpdb;
		$table = Mimamori_Bot_Installer::table_tenants();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * 新規テナント作成。public_key / secret_key は自動生成。
	 *
	 * @return array 作成後のテナントレコード
	 */
	public static function create( int $user_id, string $slug, string $name ): array {
		global $wpdb;
		$table   = Mimamori_Bot_Installer::table_tenants();
		$pub     = Mimamori_Bot_Crypto::generate_public_key();
		$sec     = Mimamori_Bot_Crypto::generate_secret_key();
		$sec_enc = Mimamori_Bot_Crypto::encrypt( $sec );

		$result = $wpdb->insert(
			$table,
			[
				'user_id'         => $user_id,
				'slug'            => $slug,
				'name'            => $name,
				'public_key'      => $pub,
				'secret_key_enc'  => $sec_enc,
				'allowed_origins' => wp_json_encode( [], JSON_UNESCAPED_UNICODE ),
				'system_prompt'   => self::default_system_prompt(),
				'persona'         => 'assistant',
				'rate_limit_rpm'  => 60,
				'status'          => 'active',
			],
			[ '%d','%s','%s','%s','%s','%s','%s','%s','%d','%s' ]
		);
		if ( $result === false ) {
			throw new RuntimeException( 'failed to create tenant: ' . $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		Mimamori_Bot_Logger::info( 'tenant: created', [ 'id' => $id, 'slug' => $slug, 'user_id' => $user_id ] );
		return self::find( $id ); // hydrate して返す
	}

	public static function update( int $id, array $fields ): bool {
		global $wpdb;
		$allowed = [
			'name', 'allowed_origins', 'system_prompt', 'persona',
			'cta_url_quote', 'cta_url_contact', 'rate_limit_rpm',
			'monthly_budget_jpy', 'status',
		];
		$update  = [];
		$formats = [];
		foreach ( $fields as $k => $v ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				continue;
			}
			$update[ $k ] = $v;
			if ( in_array( $k, [ 'rate_limit_rpm', 'monthly_budget_jpy' ], true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}
		if ( empty( $update ) ) {
			return false;
		}
		$table  = Mimamori_Bot_Installer::table_tenants();
		$result = $wpdb->update( $table, $update, [ 'id' => $id ], $formats, [ '%d' ] );
		return $result !== false;
	}

	/**
	 * キー再発行（既存セッションを無効化する想定はないが、必要なら呼び出し側でクリア）。
	 *
	 * @return array{public_key:string, secret_key:string}
	 */
	public static function regenerate_keys( int $id ): array {
		global $wpdb;
		$table   = Mimamori_Bot_Installer::table_tenants();
		$pub     = Mimamori_Bot_Crypto::generate_public_key();
		$sec     = Mimamori_Bot_Crypto::generate_secret_key();
		$sec_enc = Mimamori_Bot_Crypto::encrypt( $sec );
		$wpdb->update(
			$table,
			[ 'public_key' => $pub, 'secret_key_enc' => $sec_enc ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		Mimamori_Bot_Logger::info( 'tenant: keys regenerated', [ 'id' => $id ] );
		return [ 'public_key' => $pub, 'secret_key' => $sec ];
	}

	/**
	 * DB行を application 用に整形（allowed_origins を配列にデコードなど）。
	 */
	private static function hydrate( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['user_id']        = (int) $row['user_id'];
		$row['rate_limit_rpm'] = (int) $row['rate_limit_rpm'];
		$row['monthly_budget_jpy'] = isset( $row['monthly_budget_jpy'] ) && $row['monthly_budget_jpy'] !== null
			? (int) $row['monthly_budget_jpy']
			: null;
		$origins = json_decode( (string) ( $row['allowed_origins'] ?? '[]' ), true );
		$row['allowed_origins'] = is_array( $origins ) ? array_values( array_filter( $origins, 'is_string' ) ) : [];
		return $row;
	}

	public static function default_system_prompt(): string {
		return <<<PROMPT
あなたは親切で簡潔なAIアシスタントです。
役割: 訪問者の質問にヒアリングし、適切な提案・案内を行う。
方針:
- ナレッジに記載のない料金・部数等の確定情報は断言しない。曖昧な場合は「担当者に確認」と返す。
- 200字以内で回答。最後に「もう少し具体的に教えてください」「お問い合わせフォームへ進む」のいずれかの行動を促す。
- 個人情報（電話番号・メールアドレス）はチャット内で受け取らない。フォームへ誘導する。
PROMPT;
	}
}
