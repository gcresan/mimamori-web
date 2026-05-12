<?php
/**
 * Plugin Name: みまもりチャットボット
 * Plugin URI:  https://mimamori-web.jp/
 * Description: クライアントサイトに埋め込み可能な公開AIチャットボット。OpenAI連携 + マルチテナント対応。みまもりウェブのオプション機能。
 * Version:     0.1.3
 * Author:      みまもりウェブ
 * License:     GPL-2.0-or-later
 * Text Domain: mimamori-chatbot
 *
 * 必須定数 (wp-config.php 推奨):
 *   define( 'GCREV_ENCRYPTION_KEY', '<base64 32bytes>' );  // テナントsecret暗号化用
 *   define( 'MIMAMORI_BOT_OPENAI_KEY', 'sk-...' );          // 未設定時は OPENAI_API_KEY / option へfallback
 *
 * オプション定数:
 *   define( 'MIMAMORI_BOT_DEFAULT_MODEL', 'gpt-4o-mini' );
 *   define( 'MIMAMORI_BOT_LOG_FILE', '/tmp/gcrev_chatbot_debug.log' );
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'MIMAMORI_BOT_VERSION' ) ) {
	// 二重ロードガード
	return;
}

define( 'MIMAMORI_BOT_VERSION',                '0.7.5' );
define( 'MIMAMORI_BOT_FILE',                   __FILE__ );
define( 'MIMAMORI_BOT_PATH',                   plugin_dir_path( __FILE__ ) );
define( 'MIMAMORI_BOT_URL',                    plugin_dir_url( __FILE__ ) );
define( 'MIMAMORI_BOT_REST_NS',                'mimamori-bot/v1' );
define( 'MIMAMORI_BOT_ADMIN_REST_NS',          'mimamori-bot-admin/v1' );

if ( ! defined( 'MIMAMORI_BOT_LOG_FILE' ) ) {
	define( 'MIMAMORI_BOT_LOG_FILE', '/tmp/gcrev_chatbot_debug.log' );
}
if ( ! defined( 'MIMAMORI_BOT_DEFAULT_MODEL' ) ) {
	define( 'MIMAMORI_BOT_DEFAULT_MODEL', 'gpt-4o-mini' );
}

require_once MIMAMORI_BOT_PATH . 'includes/class-logger.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-crypto.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-installer.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-tenant-context.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-tenant-repository.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-avatars.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-rate-limiter.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-auth.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-openai-bridge.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-vision-bridge.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-embedder.php';
require_once MIMAMORI_BOT_PATH . 'includes/ingest/class-extractor.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-knowledge-repository.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-faq-repository.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-rag-retriever.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-chat-service.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-public-api.php';
require_once MIMAMORI_BOT_PATH . 'includes/admin/class-admin-menu.php';
require_once MIMAMORI_BOT_PATH . 'includes/admin/class-settings-page.php';
require_once MIMAMORI_BOT_PATH . 'includes/admin/class-knowledge-page.php';
require_once MIMAMORI_BOT_PATH . 'includes/admin/class-faq-page.php';
require_once MIMAMORI_BOT_PATH . 'includes/admin/class-history-page.php';
require_once MIMAMORI_BOT_PATH . 'includes/admin/class-analytics-page.php';
require_once MIMAMORI_BOT_PATH . 'includes/class-bootstrap.php';

register_activation_hook(   __FILE__, [ 'Mimamori_Bot_Installer', 'install' ]    );
register_deactivation_hook( __FILE__, [ 'Mimamori_Bot_Installer', 'deactivate' ] );

// uninstall_hook は static のみ受け付ける
register_uninstall_hook( __FILE__, [ 'Mimamori_Bot_Installer', 'uninstall' ] );

Mimamori_Bot_Bootstrap::init();
