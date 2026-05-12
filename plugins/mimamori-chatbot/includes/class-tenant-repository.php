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
			// 見た目カスタマイズ
			'title_text', 'theme_primary', 'theme_on_primary',
			'fab_icon_url', 'fab_bg_color',
			'fab_offset_x', 'fab_offset_y',
			'fab_offset_x_sp', 'fab_offset_y_sp',
			// 初期メッセージ
			'welcome_message',
			// 担当アバター (preset key)
			'assistant_avatar',
			// 効果音オン/オフ
			'sound_open_enabled', 'sound_send_enabled',
			// FAB エフェクト
			'fab_rounded', 'fab_shadow',
			// FAB サイズ (≥1440px / <1440px)
			'fab_size', 'fab_size_md',
			// FAB スマホ横幅 (%)
			'fab_width_pct_sp',
		];
		$int_fields = [
			'rate_limit_rpm', 'monthly_budget_jpy',
			'fab_offset_x', 'fab_offset_y',
			'fab_offset_x_sp', 'fab_offset_y_sp',
			'sound_open_enabled', 'sound_send_enabled',
			'fab_rounded', 'fab_shadow',
			'fab_size', 'fab_size_md',
			'fab_width_pct_sp',
		];
		$update  = [];
		$formats = [];
		foreach ( $fields as $k => $v ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				continue;
			}
			$update[ $k ] = $v;
			$formats[] = in_array( $k, $int_fields, true ) ? '%d' : '%s';
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

		// 見た目カスタマイズ (NULL のときは null のまま — 表示側で default にフォールバック)
		$row['fab_offset_x'] = isset( $row['fab_offset_x'] ) ? (int) $row['fab_offset_x'] : 20;
		$row['fab_offset_y'] = isset( $row['fab_offset_y'] ) ? (int) $row['fab_offset_y'] : 20;
		// SP は NULL を保持 → 未設定なら PC 値にフォールバックさせるため
		$row['fab_offset_x_sp'] = ( isset( $row['fab_offset_x_sp'] ) && $row['fab_offset_x_sp'] !== null && $row['fab_offset_x_sp'] !== '' )
			? (int) $row['fab_offset_x_sp'] : null;
		$row['fab_offset_y_sp'] = ( isset( $row['fab_offset_y_sp'] ) && $row['fab_offset_y_sp'] !== null && $row['fab_offset_y_sp'] !== '' )
			? (int) $row['fab_offset_y_sp'] : null;
		// 効果音オン/オフ — DB に列が無い旧テナントは ON (=1) で初期化
		$row['sound_open_enabled'] = isset( $row['sound_open_enabled'] ) ? (int) $row['sound_open_enabled'] : 1;
		$row['sound_send_enabled'] = isset( $row['sound_send_enabled'] ) ? (int) $row['sound_send_enabled'] : 1;
		// FAB エフェクト — 旧テナントは ON (=現在の見た目と同じ) で初期化
		$row['fab_rounded'] = isset( $row['fab_rounded'] ) ? (int) $row['fab_rounded'] : 1;
		$row['fab_shadow']  = isset( $row['fab_shadow'] )  ? (int) $row['fab_shadow']  : 1;
		// FAB サイズ — fab_size は 56 デフォルト、_md は NULL を保持 (PC 値にフォールバック)
		$row['fab_size'] = isset( $row['fab_size'] ) ? (int) $row['fab_size'] : 56;
		$row['fab_size_md'] = ( isset( $row['fab_size_md'] ) && $row['fab_size_md'] !== null && $row['fab_size_md'] !== '' )
			? (int) $row['fab_size_md'] : null;
		// スマホ版バナー最大横幅 (%) — NULL = 自動 (制限なし)
		$row['fab_width_pct_sp'] = ( isset( $row['fab_width_pct_sp'] ) && $row['fab_width_pct_sp'] !== null && $row['fab_width_pct_sp'] !== '' )
			? (int) $row['fab_width_pct_sp'] : null;
		return $row;
	}

	/**
	 * 表示用デフォルト値を補完したテーマ設定を返す。
	 * SP 用 X/Y が未設定なら PC 値にフォールバック。
	 *
	 * @return array{title:string,primary:string,on_primary:string,fab_icon_url:string,fab_bg:string,fab_x:int,fab_y:int,fab_x_sp:int,fab_y_sp:int}
	 */
	public static function resolve_theme( array $tenant ): array {
		$primary = (string) ( $tenant['theme_primary'] ?? '' );
		if ( ! self::is_valid_color( $primary ) ) $primary = '#2563eb';
		$on_primary = (string) ( $tenant['theme_on_primary'] ?? '' );
		if ( ! self::is_valid_color( $on_primary ) ) $on_primary = '#ffffff';
		$fab_bg = (string) ( $tenant['fab_bg_color'] ?? '' );
		if ( ! self::is_valid_color( $fab_bg ) ) $fab_bg = $primary;
		$title = trim( (string) ( $tenant['title_text'] ?? '' ) );
		if ( $title === '' ) $title = 'AIアシスタント';
		$icon = (string) ( $tenant['fab_icon_url'] ?? '' );
		$fab_x = isset( $tenant['fab_offset_x'] ) ? (int) $tenant['fab_offset_x'] : 20;
		$fab_y = isset( $tenant['fab_offset_y'] ) ? (int) $tenant['fab_offset_y'] : 20;
		$fab_x_sp = ( isset( $tenant['fab_offset_x_sp'] ) && $tenant['fab_offset_x_sp'] !== null )
			? (int) $tenant['fab_offset_x_sp'] : $fab_x;
		$fab_y_sp = ( isset( $tenant['fab_offset_y_sp'] ) && $tenant['fab_offset_y_sp'] !== null )
			? (int) $tenant['fab_offset_y_sp'] : $fab_y;
		// 担当アバター — preset key 無効/未設定なら空 (= UI 側で非表示)
		$avatar_key = (string) ( $tenant['assistant_avatar'] ?? '' );
		$avatar_svg = '';
		if ( class_exists( 'Mimamori_Bot_Avatars' ) && Mimamori_Bot_Avatars::is_valid_key( $avatar_key ) ) {
			$avatar_svg = Mimamori_Bot_Avatars::get_svg( $avatar_key );
		}
		return [
			'title'        => $title,
			'primary'      => $primary,
			'on_primary'   => $on_primary,
			'fab_icon_url' => $icon,
			'fab_bg'       => $fab_bg,
			'fab_x'        => $fab_x,
			'fab_y'        => $fab_y,
			'fab_x_sp'     => $fab_x_sp,
			'fab_y_sp'     => $fab_y_sp,
			'avatar_key'   => $avatar_key,
			'avatar_svg'   => $avatar_svg,
			'sound_open'   => ! empty( $tenant['sound_open_enabled'] ),
			'sound_send'   => ! empty( $tenant['sound_send_enabled'] ),
			'fab_rounded'  => ! isset( $tenant['fab_rounded'] ) || (int) $tenant['fab_rounded'] === 1,
			'fab_shadow'   => ! isset( $tenant['fab_shadow'] )  || (int) $tenant['fab_shadow']  === 1,
			'fab_size'     => isset( $tenant['fab_size'] ) ? max( 32, min( 120, (int) $tenant['fab_size'] ) ) : 56,
			'fab_size_md'  => ( isset( $tenant['fab_size_md'] ) && $tenant['fab_size_md'] !== null )
				? max( 32, min( 120, (int) $tenant['fab_size_md'] ) )
				: ( isset( $tenant['fab_size'] ) ? max( 32, min( 120, (int) $tenant['fab_size'] ) ) : 56 ),
			'fab_width_pct_sp' => ( isset( $tenant['fab_width_pct_sp'] ) && $tenant['fab_width_pct_sp'] !== null )
				? max( 10, min( 100, (int) $tenant['fab_width_pct_sp'] ) )
				: 0, // 0 = 自動 (上限なし)
		];
	}

	public static function is_valid_color( string $v ): bool {
		return (bool) preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $v );
	}

	/**
	 * 初期メッセージのデフォルト。
	 * テナント側で welcome_message を空にした場合のフォールバックとして使用。
	 */
	public static function default_welcome_message(): string {
		return "こんにちは！👋\nサービス内容・料金・対応エリアなど、お気軽にお尋ねください。\n担当者への直接のご相談もご案内できます。";
	}

	/**
	 * 表示用に解決された初期メッセージを返す。
	 * テナントが welcome_message を設定していればそれを、空ならデフォルトを返す。
	 */
	public static function resolve_welcome_message( array $tenant ): string {
		$msg = trim( (string) ( $tenant['welcome_message'] ?? '' ) );
		return $msg !== '' ? $msg : self::default_welcome_message();
	}

	public static function default_system_prompt(): string {
		return <<<PROMPT
あなたは親切で簡潔なAIアシスタントです。
役割: 訪問者の質問にヒアリングし、サービス内容や手続きの説明、関連情報の案内を行います。
方針:
- ナレッジに記載のない料金・仕様・在庫・納期等の確定情報は断言しない。曖昧な場合は「担当者に確認」と返す。
- 200字以内で回答。最後に「もう少し具体的に教えてください」「お問い合わせフォームへ進む」のいずれかの行動を促す。
- 個人情報（電話番号・メールアドレス）はチャット内で受け取らない。フォームへ誘導する。
- ナレッジ外の話題（時事ニュース・他社の商品比較等）には深入りせず、提供サービスへ会話を戻す。
PROMPT;
	}
}
