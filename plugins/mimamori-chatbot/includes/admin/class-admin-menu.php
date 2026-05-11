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

	const PAGE_SLUG = 'mimamori-chatbot';

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
			add_submenu_page(
				'gcrev-insight',
				'チャットボット',
				'チャットボット',
				'read',
				self::PAGE_SLUG,
				[ 'Mimamori_Bot_Settings_Page', 'render' ]
			);
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
		}
	}
}
