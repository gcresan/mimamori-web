<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Avatars' ) ) { return; }

/**
 * Mimamori_Bot_Avatars
 *
 * アシスタント発言の左側 / チャットヘッダーに表示する担当アイコンのプリセット集。
 *
 * 値は preset key (文字列) を tenant に保存する。表示時は get_svg() で
 * 表示用 HTML (img タグ等) を取り出し、iframe 内 / 設定UI 内で innerHTML として埋め込む。
 *
 * 画像はテーマ配下の /images/avatar/avatarN.jpg を参照する想定 (同 origin、CSP self に該当)。
 */
class Mimamori_Bot_Avatars {

	/**
	 * テーマ images ディレクトリの URL ベース。
	 */
	private static function base_url(): string {
		return trailingslashit( get_template_directory_uri() ) . 'images/avatar/';
	}

	/**
	 * @return array<string, array{label:string, img:string}>
	 */
	public static function presets(): array {
		$base = self::base_url();
		return [
			'avatar1' => [
				'label' => 'アバター1',
				'img'   => $base . 'avatar1.jpg',
			],
			'avatar2' => [
				'label' => 'アバター2',
				'img'   => $base . 'avatar2.jpg',
			],
			'avatar3' => [
				'label' => 'アバター3',
				'img'   => $base . 'avatar3.jpg',
			],
		];
	}

	/**
	 * 指定 preset の表示用 HTML を返す。
	 * 互換のため関数名は get_svg のままだが、現状は <img> タグを返す。
	 */
	public static function get_svg( string $key ): string {
		$presets = self::presets();
		if ( ! isset( $presets[ $key ] ) ) return '';
		$p = $presets[ $key ];
		if ( ! empty( $p['img'] ) ) {
			return '<img src="' . esc_url( $p['img'] ) . '" alt="" loading="lazy" decoding="async" />';
		}
		return $p['svg'] ?? '';
	}

	public static function is_valid_key( string $key ): bool {
		if ( $key === '' ) return true; // 空 = アイコンなし
		return array_key_exists( $key, self::presets() );
	}
}
