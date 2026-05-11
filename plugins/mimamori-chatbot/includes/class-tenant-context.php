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
}
