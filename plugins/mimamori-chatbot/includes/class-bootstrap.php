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

		// 子ファイル末尾の static method 呼び出しが PHP-FPM 環境で実行されない問題への workaround
		// 確実に動作する Bootstrap::init() から明示的に呼び出す
		Mimamori_Bot_Logger::info( 'bootstrap::init() registering admin page hooks' );
		if ( class_exists( 'Mimamori_Bot_Settings_Page' ) ) {
			Mimamori_Bot_Settings_Page::init_hooks();
		}
		if ( class_exists( 'Mimamori_Bot_Knowledge_Page' ) ) {
			Mimamori_Bot_Knowledge_Page::init_hooks();
		}
		if ( class_exists( 'Mimamori_Bot_Faq_Page' ) ) {
			Mimamori_Bot_Faq_Page::init_hooks();
		}
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
	 * バージョン差分があれば dbDelta を再走させる + 固定ページ自動作成
	 */
	public static function maybe_upgrade_db(): void {
		$installed = get_option( 'mimamori_bot_db_version', '0.0.0' );
		if ( version_compare( $installed, MIMAMORI_BOT_VERSION, '<' ) ) {
			Mimamori_Bot_Installer::install();
			update_option( 'mimamori_bot_db_version', MIMAMORI_BOT_VERSION, false );
		}
		self::ensure_chatbot_page();
	}

	/**
	 * /chatbot/ の固定ページを自動作成する (テーマに page-chatbot.php がある前提)。
	 * 既存ページがあれば再利用し、無ければ新規作成。一度成功したら option に保存して以後スキップ。
	 */
	public static function ensure_chatbot_page(): void {
		$existing_id = (int) get_option( 'mimamori_bot_chatbot_page_id', 0 );
		if ( $existing_id > 0 ) {
			// 既に作成済みかつ post が存在すれば OK
			if ( get_post( $existing_id ) ) {
				return;
			}
			// 削除されてしまっている → 作り直す
			delete_option( 'mimamori_bot_chatbot_page_id' );
		}

		// slug 'chatbot' で既に存在するかチェック
		$page = get_page_by_path( 'chatbot' );
		if ( $page ) {
			// テンプレートが未指定なら強制適用
			$tpl = get_post_meta( $page->ID, '_wp_page_template', true );
			if ( $tpl !== 'page-chatbot.php' ) {
				update_post_meta( $page->ID, '_wp_page_template', 'page-chatbot.php' );
			}
			update_option( 'mimamori_bot_chatbot_page_id', (int) $page->ID, false );
			return;
		}

		// 新規作成
		$page_id = wp_insert_post( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'チャットボット管理',
			'post_name'    => 'chatbot',
			'post_content' => '',
			'meta_input'   => [
				'_wp_page_template' => 'page-chatbot.php',
			],
		] );

		if ( ! is_wp_error( $page_id ) && $page_id > 0 ) {
			update_option( 'mimamori_bot_chatbot_page_id', (int) $page_id, false );
			Mimamori_Bot_Logger::info( 'bootstrap: /chatbot/ page created', [ 'page_id' => $page_id ] );
		} else {
			Mimamori_Bot_Logger::warn( 'bootstrap: failed to create /chatbot/ page', [
				'error' => is_wp_error( $page_id ) ? $page_id->get_error_message() : 'unknown',
			] );
		}
	}
}
