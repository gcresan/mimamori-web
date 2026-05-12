<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Admin_API' ) ) { return; }

/**
 * Mimamori_Bot_Admin_API
 *
 * クライアント管理ページ (/chatbot/) からの軽量 REST 操作。
 *   POST  /mimamori-bot-admin/v1/upload-fab-icon   FAB バナー画像アップロード
 *
 * 認証: WP Cookie + X-WP-Nonce + テナント所有者 or manage_options。
 * 保存先: wp-content/uploads/mimamori-chatbot/fab-icons/{tenant_id}/
 */
class Mimamori_Bot_Admin_API {

	/** 上限 2 MB */
	const MAX_BYTES = 2097152;

	/** 許可 MIME */
	const ALLOWED_MIMES = [
		'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
	];

	public static function register_routes(): void {
		$ns = MIMAMORI_BOT_ADMIN_REST_NS;
		register_rest_route( $ns, '/upload-fab-icon', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_upload_fab_icon' ],
			'permission_callback' => [ __CLASS__, 'can_edit_tenant' ],
			'args'                => [
				'tenant_id' => [
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	public static function can_edit_tenant( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) return false;
		$tenant_id = absint( $request->get_param( 'tenant_id' ) );
		if ( $tenant_id <= 0 ) return false;
		$tenant = Mimamori_Bot_Tenant_Repository::find( $tenant_id );
		if ( ! $tenant ) return false;
		// 運営者は全テナント編集可、それ以外は自分のテナントのみ
		if ( current_user_can( 'manage_options' ) ) return true;
		return (int) $tenant['user_id'] === get_current_user_id();
	}

	public static function handle_upload_fab_icon( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error( 'no_file', 'ファイルが指定されていません', [ 'status' => 400 ] );
		}
		$file = $files['file'];

		// PHP の upload エラー
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload_error', 'アップロードに失敗しました (code=' . (int) $file['error'] . ')', [ 'status' => 400 ] );
		}

		// サイズ
		if ( ! isset( $file['size'] ) || $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'too_big', 'ファイルサイズが大きすぎます (最大 2MB)', [ 'status' => 400 ] );
		}

		// 実 MIME 判定 (ファイル名拡張子だけでなく実体もチェック)
		$tmp_name  = $file['tmp_name'] ?? '';
		$file_name = sanitize_file_name( (string) ( $file['name'] ?? 'upload' ) );
		if ( ! $tmp_name || ! file_exists( $tmp_name ) ) {
			return new WP_Error( 'bad_file', 'ファイルが見つかりません', [ 'status' => 400 ] );
		}
		$finfo = wp_check_filetype_and_ext( $tmp_name, $file_name );
		$mime  = (string) ( $finfo['type'] ?? '' );
		if ( $mime === '' || ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
			return new WP_Error( 'bad_type', '対応していないファイル形式です (JPG / PNG / GIF / WEBP / SVG)', [ 'status' => 400 ] );
		}

		// 保存先 — uploads/mimamori-chatbot/fab-icons/{tenant_id}/
		$tenant_id = absint( $request->get_param( 'tenant_id' ) );
		$upload    = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_dir', 'アップロードディレクトリが利用できません: ' . $upload['error'], [ 'status' => 500 ] );
		}
		$rel_dir = '/mimamori-chatbot/fab-icons/' . $tenant_id;
		$dir     = $upload['basedir'] . $rel_dir;
		$url     = $upload['baseurl'] . $rel_dir;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_writable( $dir ) ) {
			return new WP_Error( 'not_writable', '保存先ディレクトリに書き込めません', [ 'status' => 500 ] );
		}

		// ファイル名: fab-{epoch}-{rand}.{ext}
		$ext = pathinfo( $file_name, PATHINFO_EXTENSION );
		$ext = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $ext );
		if ( $ext === '' ) {
			$ext_map = [
				'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
				'image/webp' => 'webp', 'image/svg+xml' => 'svg',
			];
			$ext = $ext_map[ $mime ] ?? 'jpg';
		}
		$base    = 'fab-' . time() . '-' . wp_generate_password( 6, false, false );
		$dest    = $dir . '/' . $base . '.' . $ext;
		$dest_url = $url . '/' . $base . '.' . $ext;

		if ( ! @move_uploaded_file( $tmp_name, $dest ) ) {
			return new WP_Error( 'save_failed', 'ファイルの保存に失敗しました', [ 'status' => 500 ] );
		}
		@chmod( $dest, 0644 );

		Mimamori_Bot_Logger::info( 'fab-icon uploaded', [
			'tenant_id' => $tenant_id, 'name' => basename( $dest ), 'size' => (int) $file['size'], 'mime' => $mime,
		] );

		return new WP_REST_Response( [ 'ok' => true, 'url' => $dest_url ], 200 );
	}
}
