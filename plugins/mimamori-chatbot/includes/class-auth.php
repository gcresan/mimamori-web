<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Auth' ) ) { return; }

/**
 * Mimamori_Bot_Auth
 *
 * 公開Widget API の認証ミドルウェア。
 *
 * 認証フロー:
 *   1) X-Mimamori-Public-Key ヘッダ (または ?key= クエリ) で public_key 抽出
 *   2) TenantRepository で解決、status='active' を確認
 *   3) Origin ヘッダを allowed_origins と照合
 *      - 同origin (mimamori-web.jp) は常に許可 (iframe ケース)
 *      - 外部Origin は allowed_origins に含まれる場合のみ許可
 *   4) Rate Limit 適用
 *   5) TenantContext::set() で以降のハンドラに渡す
 */
class Mimamori_Bot_Auth {

	/**
	 * @return true|WP_Error
	 */
	public static function authenticate_widget( WP_REST_Request $request ) {
		$public_key = $request->get_header( 'x_mimamori_public_key' );
		if ( ! $public_key ) {
			$public_key = (string) $request->get_param( 'key' );
		}
		$public_key = is_string( $public_key ) ? trim( $public_key ) : '';
		if ( $public_key === '' ) {
			return new WP_Error( 'missing_key', 'public key is required', [ 'status' => 401 ] );
		}

		$tenant = Mimamori_Bot_Tenant_Repository::find_by_public_key( $public_key );
		if ( ! $tenant ) {
			Mimamori_Bot_Logger::warn( 'auth: unknown public key', [ 'key' => Mimamori_Bot_Logger::mask( $public_key ) ] );
			return new WP_Error( 'invalid_key', 'invalid public key', [ 'status' => 401 ] );
		}
		if ( ( $tenant['status'] ?? 'active' ) !== 'active' ) {
			return new WP_Error( 'tenant_suspended', 'tenant is suspended', [ 'status' => 403 ] );
		}

		// テナント所有者がお試し終了かつ未払いの場合は、ウィジェット稼働（OpenAI課金）を停止する。
		// 課金情報を公開ウィジェットに漏らさないため suspended と同じ扱い・メッセージにする。
		$owner_id = (int) ( $tenant['user_id'] ?? 0 );
		if ( $owner_id > 0 && function_exists( 'gcrev_user_api_enabled' ) && ! gcrev_user_api_enabled( $owner_id ) ) {
			Mimamori_Bot_Logger::warn( 'auth: owner payment inactive', [ 'tenant_id' => $tenant['id'], 'owner_id' => $owner_id ] );
			return new WP_Error( 'tenant_suspended', 'tenant is suspended', [ 'status' => 403 ] );
		}

		$origin = self::request_origin( $request );
		if ( ! self::is_origin_allowed( $origin, $tenant['allowed_origins'] ?? [] ) ) {
			Mimamori_Bot_Logger::warn( 'auth: origin denied', [
				'tenant_id' => $tenant['id'],
				'origin'    => $origin,
			] );
			return new WP_Error( 'origin_denied', 'origin not allowed', [ 'status' => 403 ] );
		}

		// Rate limit
		$ip_hash  = self::ip_hash();
		$rate_chk = Mimamori_Bot_Rate_Limiter::check( (int) $tenant['id'], $ip_hash, (int) $tenant['rate_limit_rpm'] );
		if ( is_wp_error( $rate_chk ) ) {
			return $rate_chk;
		}
		$budget_chk = Mimamori_Bot_Rate_Limiter::check_budget( (int) $tenant['id'], $tenant['monthly_budget_jpy'] ?? null );
		if ( is_wp_error( $budget_chk ) ) {
			return $budget_chk;
		}

		Mimamori_Bot_Tenant_Context::set( $tenant );
		return true;
	}

	public static function request_origin( WP_REST_Request $request ): string {
		$origin = $request->get_header( 'origin' );
		if ( ! $origin ) {
			$ref = $request->get_header( 'referer' );
			if ( $ref ) {
				$parts  = wp_parse_url( $ref );
				if ( isset( $parts['scheme'], $parts['host'] ) ) {
					$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
				}
			}
		}
		return is_string( $origin ) ? $origin : '';
	}

	public static function is_origin_allowed( string $origin, array $allowed_origins ): bool {
		if ( $origin === '' ) {
			// Origin ヘッダなし (curl等) は許可しない。テスト時は明示的にallowed_originsに追加する想定。
			return false;
		}
		// 同origin (API自身のホスト) は常に許可。これにより iframe→同origin API が成立する。
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$req_host  = wp_parse_url( $origin, PHP_URL_HOST );
		if ( $site_host && $req_host && strtolower( $site_host ) === strtolower( $req_host ) ) {
			return true;
		}
		foreach ( $allowed_origins as $allowed ) {
			if ( strcasecmp( rtrim( (string) $allowed, '/' ), rtrim( $origin, '/' ) ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	public static function ip_hash(): string {
		$ip = self::client_ip();
		$salt = wp_salt( 'nonce' );
		return hash( 'sha256', $ip . '|' . $salt );
	}

	public static function visitor_hash( string $user_agent ): string {
		$ip   = self::client_ip();
		$salt = wp_salt( 'nonce' );
		return hash( 'sha256', $ip . '|' . $user_agent . '|' . $salt );
	}

	private static function client_ip(): string {
		// KUSANAGI は nginx 経由なので REMOTE_ADDR で十分。X-Forwarded-For は信用しない。
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	}

	/**
	 * 管理API permission_callback 用。
	 * 現在のWPユーザーが指定tenantの所有者か確認。
	 */
	public static function authenticate_admin( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', 'login required', [ 'status' => 401 ] );
		}
		$user_id = get_current_user_id();
		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error( 'forbidden', 'insufficient permission', [ 'status' => 403 ] );
		}
		$tenant = Mimamori_Bot_Tenant_Repository::find_for_user( $user_id );
		if ( ! $tenant ) {
			return new WP_Error( 'no_tenant', 'no tenant assigned to this user', [ 'status' => 404 ] );
		}
		Mimamori_Bot_Tenant_Context::set( $tenant );
		return true;
	}
}
