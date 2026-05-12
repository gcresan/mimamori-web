<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Tenant_Context' ) ) { return; }

/**
 * Mimamori_Bot_Tenant_Context
 *
 * 1リクエストを通じて「現在のテナント」を保持する Singleton。
 * すべてのDBクエリは必ず current() 経由で取得した tenant_id を WHERE に含めること。
 *
 * 使い方:
 *   Mimamori_Bot_Tenant_Context::set( $tenant );  // 認証通過後
 *   $tenant = Mimamori_Bot_Tenant_Context::current(); // 以降どこからでも
 */
class Mimamori_Bot_Tenant_Context {

	/** @var array|null  認証通過後のテナントレコード (連想配列) */
	private static $tenant = null;

	public static function set( array $tenant ): void {
		self::$tenant = $tenant;
	}

	public static function reset(): void {
		self::$tenant = null;
	}

	/**
	 * @return array|null
	 */
	public static function current(): ?array {
		return self::$tenant;
	}

	public static function id(): int {
		return self::$tenant ? (int) self::$tenant['id'] : 0;
	}

	public static function require_current(): array {
		if ( self::$tenant === null ) {
			throw new RuntimeException( 'TenantContext not set — auth middleware was bypassed?' );
		}
		return self::$tenant;
	}

	/**
	 * 管理画面向け: 現在の WP ユーザーに対するアクティブテナントを解決する。
	 *
	 * 運営者 (manage_options):
	 *   1. $_GET['tenant_id'] があればそれを使い user_meta に永続化
	 *   2. 無ければ user_meta 'mimamori_bot_active_tenant_id' を参照
	 *   3. それも無ければ最初のテナント
	 * 一般ユーザー:
	 *   - 自分が所有する最初のテナント (Mimamori_Bot_Tenant_Repository::find_for_user)
	 *
	 * @return array|null  テナントレコード (hydrate済) or null
	 */
	public static function resolve_active_for_user( int $user_id ): ?array {
		$is_admin = user_can( $user_id, 'manage_options' );

		if ( $is_admin ) {
			// 1. クエリ ?tenant_id=
			$requested = isset( $_GET['tenant_id'] ) ? absint( $_GET['tenant_id'] ) : 0;
			if ( $requested > 0 ) {
				$t = Mimamori_Bot_Tenant_Repository::find( $requested );
				if ( $t ) {
					update_user_meta( $user_id, 'mimamori_bot_active_tenant_id', $t['id'] );
					return $t;
				}
			}
			// 2. user_meta
			$stored = (int) get_user_meta( $user_id, 'mimamori_bot_active_tenant_id', true );
			if ( $stored > 0 ) {
				$t = Mimamori_Bot_Tenant_Repository::find( $stored );
				if ( $t ) {
					return $t;
				}
			}
			// 3. fallback: 全テナントの先頭
			$all = Mimamori_Bot_Tenant_Repository::list_all( 1 );
			return $all[0] ?? null;
		}

		// 一般ユーザー: 自分のテナントのみ
		return Mimamori_Bot_Tenant_Repository::find_for_user( $user_id );
	}

	/**
	 * 管理画面: 切替元として、現ユーザーが選択可能なテナント一覧を返す。
	 * 運営者 -> 全テナント / 一般ユーザー -> 自分所有のテナントのみ。
	 *
	 * @return array<int,array>
	 */
	public static function list_accessible_for_user( int $user_id ): array {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return Mimamori_Bot_Tenant_Repository::list_all();
		}
		return Mimamori_Bot_Tenant_Repository::list_for_user( $user_id );
	}

	/**
	 * 管理画面ヘッダ: テナント切替ドロップダウンを描画する。
	 * 切替可能なテナントが2件以上 (または運営者) の時のみ表示する。
	 */
	public static function render_switcher( int $user_id, string $current_page_slug, ?array $active_tenant ): void {
		$accessible = self::list_accessible_for_user( $user_id );
		$is_admin   = user_can( $user_id, 'manage_options' );

		if ( ! $is_admin && count( $accessible ) <= 1 ) {
			return; // 一般ユーザーで1テナントなら切替UI不要
		}

		echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2563eb;padding:10px 14px;margin:10px 0 16px;border-radius:4px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">';
		echo '<strong style="color:#1f2937">現在管理中:</strong>';

		if ( ! empty( $accessible ) ) {
			echo '<form method="GET" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="display:flex;align-items:center;gap:8px;margin:0">';
			echo '<input type="hidden" name="page" value="' . esc_attr( $current_page_slug ) . '">';
			echo '<select name="tenant_id" onchange="this.form.submit()">';
			foreach ( $accessible as $t ) {
				$tid = (int) $t['id'];
				$sel = ( $active_tenant && (int) $active_tenant['id'] === $tid ) ? ' selected' : '';
				echo '<option value="' . esc_attr( (string) $tid ) . '"' . $sel . '>' . esc_html( $t['name'] . ' (' . $t['slug'] . ')' ) . '</option>';
			}
			echo '</select>';
			echo '</form>';
		} else {
			echo '<span style="color:#9ca3af">テナント未作成</span>';
		}

		if ( $is_admin ) {
			$count = count( $accessible );
			echo '<span style="color:#6b7280;font-size:12px">(運営者表示: ' . esc_html( (string) $count ) . ' 件のテナント)</span>';
		}
		echo '</div>';
	}
}
