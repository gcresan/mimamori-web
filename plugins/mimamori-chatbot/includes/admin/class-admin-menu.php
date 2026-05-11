<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Admin_Menu' ) ) { return; }

/**
 * Mimamori_Bot_Admin_Menu
 *
 * 管理画面メニュー登録。
 * 既存テーマの gcrev-insight 親メニューがあればその配下、なければトップレベルに配置。
 */
class Mimamori_Bot_Admin_Menu {

	const PAGE_SLUG           = 'mimamori-chatbot';
	const PAGE_SLUG_KNOWLEDGE = 'mimamori-chatbot-knowledge';
	const PAGE_SLUG_FAQ       = 'mimamori-chatbot-faq';
	const PAGE_SLUG_HISTORY   = 'mimamori-chatbot-history';
	const PAGE_SLUG_ANALYTICS = 'mimamori-chatbot-analytics';

	public static function register(): void {
		global $menu;
		$parent_exists = false;
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && $item[2] === 'gcrev-insight' ) {
					$parent_exists = true;
					break;
				}
			}
		}

		if ( $parent_exists ) {
			$parent = 'gcrev-insight';
			add_submenu_page( $parent, 'チャットボット 設定',     'チャットボット',         'read', self::PAGE_SLUG,           [ 'Mimamori_Bot_Settings_Page',  'render' ] );
			add_submenu_page( $parent, 'チャットボット ナレッジ', '└ ナレッジ',           'read', self::PAGE_SLUG_KNOWLEDGE, [ 'Mimamori_Bot_Knowledge_Page', 'render' ] );
			add_submenu_page( $parent, 'チャットボット FAQ',      '└ FAQ',                'read', self::PAGE_SLUG_FAQ,       [ 'Mimamori_Bot_Faq_Page',       'render' ] );
			add_submenu_page( $parent, 'チャットボット 履歴',     '└ 履歴',                'read', self::PAGE_SLUG_HISTORY,   [ 'Mimamori_Bot_History_Page',   'render' ] );
			add_submenu_page( $parent, 'チャットボット 分析',     '└ 分析',                'read', self::PAGE_SLUG_ANALYTICS, [ 'Mimamori_Bot_Analytics_Page', 'render' ] );
		} else {
			add_menu_page(
				'みまもりChatbot',
				'みまもりChatbot',
				'read',
				self::PAGE_SLUG,
				[ 'Mimamori_Bot_Settings_Page', 'render' ],
				'dashicons-format-chat',
				58
			);
			add_submenu_page( self::PAGE_SLUG, 'チャットボット 設定',     '設定',     'read', self::PAGE_SLUG,           [ 'Mimamori_Bot_Settings_Page',  'render' ] );
			add_submenu_page( self::PAGE_SLUG, 'チャットボット ナレッジ', 'ナレッジ', 'read', self::PAGE_SLUG_KNOWLEDGE, [ 'Mimamori_Bot_Knowledge_Page', 'render' ] );
			add_submenu_page( self::PAGE_SLUG, 'チャットボット FAQ',      'FAQ',      'read', self::PAGE_SLUG_FAQ,       [ 'Mimamori_Bot_Faq_Page',       'render' ] );
			add_submenu_page( self::PAGE_SLUG, 'チャットボット 履歴',     '履歴',     'read', self::PAGE_SLUG_HISTORY,   [ 'Mimamori_Bot_History_Page',   'render' ] );
			add_submenu_page( self::PAGE_SLUG, 'チャットボット 分析',     '分析',     'read', self::PAGE_SLUG_ANALYTICS, [ 'Mimamori_Bot_Analytics_Page', 'render' ] );
		}
	}
}
