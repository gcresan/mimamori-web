<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Public_API' ) ) { return; }

/**
 * Mimamori_Bot_Public_API
 *
 * Widget用 REST エンドポイント:
 *   POST  /mimamori-bot/v1/session
 *   POST  /mimamori-bot/v1/message
 *   POST  /mimamori-bot/v1/event
 *   GET   /mimamori-bot/v1/embed   (テナント別 iframe HTML を CSP frame-ancestors 付きで配信)
 *
 * CORS:
 *   - Origin が allowed_origins に含まれる場合のみ動的に Access-Control-Allow-Origin を返す
 *   - OPTIONS プリフライトは rest_pre_serve_request で短絡対応
 */
class Mimamori_Bot_Public_API {

	public static function register_routes(): void {
		$ns = MIMAMORI_BOT_REST_NS;

		register_rest_route( $ns, '/session', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_session' ],
			'permission_callback' => [ 'Mimamori_Bot_Auth', 'authenticate_widget' ],
			'args'                => [
				'user_agent'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'referer'      => [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
				'landing_url'  => [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
				'utm_source'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'utm_medium'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'utm_campaign' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		register_rest_route( $ns, '/message', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_message' ],
			'permission_callback' => [ 'Mimamori_Bot_Auth', 'authenticate_widget' ],
			'args'                => [
				'session_uuid' => [
					'required' => true, 'type' => 'string',
					'validate_callback' => static function ( $v ) {
						return is_string( $v ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v );
					},
				],
				'message' => [
					'required' => true, 'type' => 'string',
					'sanitize_callback' => static function ( $v ) {
						// HTMLタグを除去しつつ改行は保持 (第2引数 false)
						return is_string( $v ) ? wp_check_invalid_utf8( wp_strip_all_tags( $v, false ) ) : '';
					},
				],
				'page_url' => [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
			],
		] );

		register_rest_route( $ns, '/event', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_event' ],
			'permission_callback' => [ 'Mimamori_Bot_Auth', 'authenticate_widget' ],
			'args'                => [
				'session_uuid' => [ 'required' => true, 'type' => 'string' ],
				'type'         => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'payload'      => [ 'type' => 'object' ],
			],
		] );

		register_rest_route( $ns, '/embed', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_embed' ],
			// embed.html は GET でテナント解決＋CSP付与してHTMLを返す。
			// public_key は URL fragment 経由でフロントに渡るため、ここの permission_callback では tenant slug のみで解決する。
			'permission_callback' => '__return_true',
			'args'                => [
				'tenant' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_title' ],
			],
		] );

		add_filter( 'rest_pre_serve_request', [ __CLASS__, 'maybe_handle_cors' ], 10, 4 );
	}

	public static function handle_session( WP_REST_Request $request ) {
		$tenant = Mimamori_Bot_Tenant_Context::current();
		if ( ! $tenant ) {
			return new WP_Error( 'no_tenant', 'tenant context missing', [ 'status' => 500 ] );
		}
		$params = $request->get_json_params() ?: $request->get_params();
		// User-Agent は header からのほうが信頼できる
		if ( empty( $params['user_agent'] ) ) {
			$params['user_agent'] = $request->get_header( 'user_agent' ) ?: '';
		}
		$service = new Mimamori_Bot_Chat_Service();
		$session = $service->create_session( $tenant, $params );

		// starters: FAQ.is_starter=1 を優先、未登録ならフォールバック (汎用)
		$starters = Mimamori_Bot_Faq_Repository::list_starters( (int) $tenant['id'], 4 );
		if ( empty( $starters ) ) {
			$starters = [
				'どんなことを相談できますか？',
				'サービス内容を教えて',
				'料金の目安は？',
				'問い合わせしたい',
			];
		}

		return self::ok_response( [
			'session_uuid'      => $session['session_uuid'],
			'persona'           => $tenant['persona'] ?? null,
			'cta_url_quote'     => $tenant['cta_url_quote'] ?? null,
			'cta_url_contact'   => $tenant['cta_url_contact'] ?? null,
			'starters'          => $starters,
		] );
	}

	public static function handle_message( WP_REST_Request $request ) {
		$tenant = Mimamori_Bot_Tenant_Context::current();
		if ( ! $tenant ) {
			return new WP_Error( 'no_tenant', 'tenant context missing', [ 'status' => 500 ] );
		}
		$session_uuid = (string) $request->get_param( 'session_uuid' );
		$message      = (string) $request->get_param( 'message' );

		$service = new Mimamori_Bot_Chat_Service();
		$result  = $service->process_message( $tenant, $session_uuid, $message );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok_response( $result );
	}

	public static function handle_event( WP_REST_Request $request ) {
		$tenant       = Mimamori_Bot_Tenant_Context::current();
		$session_uuid = (string) $request->get_param( 'session_uuid' );
		$type         = (string) $request->get_param( 'type' );
		$payload      = $request->get_param( 'payload' );
		if ( ! is_array( $payload ) ) {
			$payload = [];
		}
		$service = new Mimamori_Bot_Chat_Service();
		$ok      = $service->record_event( $tenant, $session_uuid, $type, $payload );
		return self::ok_response( [ 'recorded' => $ok ] );
	}

	/**
	 * iframe 用 HTML を CSP frame-ancestors 付きで返す。
	 * REST 経由だが Content-Type を text/html にしてレスポンスを直接終了させる。
	 */
	public static function handle_embed( WP_REST_Request $request ) {
		$slug   = (string) $request->get_param( 'tenant' );
		$tenant = Mimamori_Bot_Tenant_Repository::find_by_slug( $slug );
		if ( ! $tenant || ( $tenant['status'] ?? '' ) !== 'active' ) {
			status_header( 404 );
			exit;
		}
		$origins = $tenant['allowed_origins'] ?? [];
		// frame-ancestors は scheme://host[:port] 列を半角スペース区切り。空なら 'none'。
		$fa = empty( $origins ) ? "'none'" : implode( ' ', array_map( 'esc_url_raw', $origins ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store' );
		header( "Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors {$fa}" );

		echo self::render_embed_html( $tenant );
		exit;
	}

	/**
	 * CORS プリフライト ＆ レスポンスヘッダ付与。
	 */
	public static function maybe_handle_cors( $served, $result, $request, $server ) {
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( strpos( $route, '/' . MIMAMORI_BOT_REST_NS . '/' ) !== 0 ) {
			return $served;
		}
		$origin = $request->get_header( 'origin' );
		if ( ! $origin ) {
			return $served;
		}

		// public_key を解決 → allowed_origins と照合
		$pk = $request->get_header( 'x_mimamori_public_key' ) ?: (string) $request->get_param( 'key' );
		$pk = is_string( $pk ) ? trim( $pk ) : '';
		$tenant = $pk !== '' ? Mimamori_Bot_Tenant_Repository::find_by_public_key( $pk ) : null;
		if ( $tenant && Mimamori_Bot_Auth::is_origin_allowed( $origin, $tenant['allowed_origins'] ?? [] ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Vary: Origin' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type, X-Mimamori-Public-Key' );
			header( 'Access-Control-Max-Age: 600' );
		}
		return $served;
	}

	private static function ok_response( array $data ): WP_REST_Response {
		$res = new WP_REST_Response( array_merge( [ 'ok' => true ], $data ), 200 );
		return $res;
	}

	private static function render_embed_html( array $tenant ): string {
		$tenant_slug = esc_attr( $tenant['slug'] );
		$public_key  = esc_attr( $tenant['public_key'] );
		$api_base    = esc_url( rest_url( MIMAMORI_BOT_REST_NS ) );
		$asset_base  = esc_url( MIMAMORI_BOT_URL . 'public/widget' );
		$persona     = esc_html( $tenant['name'] );

		// embed.css / embed.js は plugin assets として静的配信。
		return <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>{$persona}</title>
<link rel="stylesheet" href="{$asset_base}/embed.css">
</head>
<body>
<div id="app" data-tenant="{$tenant_slug}" data-pubkey="{$public_key}" data-api="{$api_base}"></div>
<script src="{$asset_base}/embed.js" defer></script>
</body>
</html>
HTML;
	}
}
