<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Bootstrap' ) ) { return; }

/**
 * Mimamori_Bot_Bootstrap
 * 全フック登録のエントリポイント。
 */
class Mimamori_Bot_Bootstrap {

	public static function init(): void {
		add_action( 'plugins_loaded',  [ __CLASS__, 'on_plugins_loaded' ] );
		add_action( 'rest_api_init',   [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'admin_menu',      [ __CLASS__, 'register_admin_menu' ] );
		add_action( 'admin_init',      [ __CLASS__, 'maybe_upgrade_db' ] );

		// 公開Widget loader (テナント独自JSを直接配信する場合に備え、現状は静的ファイル直リンクのみ)
		// CORSプリフライト対応は class-public-api.php で per-route 処理
	}

	public static function on_plugins_loaded(): void {
		load_plugin_textdomain( 'mimamori-chatbot', false, dirname( plugin_basename( MIMAMORI_BOT_FILE ) ) . '/languages' );
	}

	public static function register_rest_routes(): void {
		Mimamori_Bot_Public_API::register_routes();
	}

	public static function register_admin_menu(): void {
		Mimamori_Bot_Admin_Menu::register();
	}

	/**
	 * バージョン差分があれば dbDelta を再走させる
	 */
	public static function maybe_upgrade_db(): void {
		$installed = get_option( 'mimamori_bot_db_version', '0.0.0' );
		if ( version_compare( $installed, MIMAMORI_BOT_VERSION, '<' ) ) {
			Mimamori_Bot_Installer::install();
			update_option( 'mimamori_bot_db_version', MIMAMORI_BOT_VERSION, false );
		}
	}
}
