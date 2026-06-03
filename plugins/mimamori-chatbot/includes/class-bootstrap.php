<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Bootstrap' ) ) { return; }

/**
 * Mimamori_Bot_Bootstrap
 * 全フック登録のエントリポイント。
 */
class Mimamori_Bot_Bootstrap {

	/** init() が複数回呼ばれないようガード */
	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) return;
		self::$initialized = true;

		add_action( 'plugins_loaded',  [ __CLASS__, 'on_plugins_loaded' ] );
		add_action( 'rest_api_init',   [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'admin_menu',      [ __CLASS__, 'register_admin_menu' ] );
		add_action( 'admin_init',      [ __CLASS__, 'maybe_upgrade_db' ] );

		// 週次cron: スターター提案の自動再生成
		add_filter( 'cron_schedules',                    [ __CLASS__, 'add_weekly_schedule' ] );
		add_action( 'init',                              [ __CLASS__, 'schedule_starter_cron' ] );
		add_action( 'mimamori_bot_refresh_starters',     [ __CLASS__, 'refresh_all_starter_suggestions' ] );

		// 子ファイル末尾の static method 呼び出しが PHP-FPM 環境で実行されない問題への workaround
		// 確実に動作する Bootstrap::init() から明示的に呼び出す
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
		if ( class_exists( 'Mimamori_Bot_Admin_API' ) ) {
			Mimamori_Bot_Admin_API::register_routes();
		}
	}

	public static function register_admin_menu(): void {
		Mimamori_Bot_Admin_Menu::register();
	}

	/**
	 * バージョン差分があれば dbDelta を再走 + 固定ページ自動作成。
	 * バージョン一致時は何もしない (毎リクエストの実行を避ける)。
	 */
	public static function maybe_upgrade_db(): void {
		$installed = get_option( 'mimamori_bot_db_version', '0.0.0' );

		if ( version_compare( $installed, MIMAMORI_BOT_VERSION, '<' ) ) {
			Mimamori_Bot_Installer::install();
			update_option( 'mimamori_bot_db_version', MIMAMORI_BOT_VERSION, false );
			// バージョン上がった時だけ固定ページの再確認
			self::ensure_chatbot_page();
			return;
		}

		// 固定ページ未作成フラグが立っているときだけチェック (初回 + 削除後の救済)
		if ( ! get_option( 'mimamori_bot_chatbot_page_id', 0 ) ) {
			self::ensure_chatbot_page();
		}
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

	/**
	 * WordPress 標準にない 'weekly' スケジュールを登録。
	 */
	public static function add_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['mb_weekly'] ) ) {
			$schedules['mb_weekly'] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => '週1回 (チャットボット用)',
			];
		}
		return $schedules;
	}

	/**
	 * 週次cronイベントを登録 (未登録時のみ)。
	 * 実行時刻は WordPress の wp_schedule_event に任せる (登録時刻を基準に1週間隔)。
	 */
	public static function schedule_starter_cron(): void {
		if ( ! wp_next_scheduled( 'mimamori_bot_refresh_starters' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'mb_weekly', 'mimamori_bot_refresh_starters' );
			Mimamori_Bot_Logger::info( 'cron: starter refresh scheduled (mb_weekly)' );
		}
	}

	/**
	 * 全 active テナントを巡って AI スターター提案を再生成する。
	 * 1回の呼び出しでテナント数 × OpenAI API 1コール 発生するため、運営者は規模に応じて間引き判断する。
	 */
	public static function refresh_all_starter_suggestions(): void {
		if ( ! class_exists( 'Mimamori_Bot_Tenant_Repository' ) ) return;
		$tenants = Mimamori_Bot_Tenant_Repository::list_all( 500 );
		$ran = 0;
		foreach ( $tenants as $t ) {
			if ( ( $t['status'] ?? '' ) !== 'active' ) continue;
			// テナント所有者がお試し終了かつ未払いの場合は、OpenAI課金を伴うサジェスト生成をスキップ
			$owner_id = (int) ( $t['user_id'] ?? 0 );
			if ( $owner_id > 0 && function_exists( 'gcrev_user_api_enabled' ) && ! gcrev_user_api_enabled( $owner_id ) ) continue;
			try {
				Mimamori_Bot_Faq_Repository::compute_starter_suggestions( (int) $t['id'] );
				$ran++;
			} catch ( \Throwable $e ) {
				Mimamori_Bot_Logger::warn( 'cron: starter regen failed', [
					'tenant_id' => $t['id'] ?? 0,
					'err'       => $e->getMessage(),
				] );
			}
		}
		Mimamori_Bot_Logger::info( 'cron: starter refresh done', [ 'tenants' => $ran ] );
	}
}
