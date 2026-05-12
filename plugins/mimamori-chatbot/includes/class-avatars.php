<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_Bot_Avatars' ) ) { return; }

/**
 * Mimamori_Bot_Avatars
 *
 * アシスタント発言の左側 / チャットヘッダーに表示する担当アイコンのプリセット集。
 *
 * 値は preset key (文字列) を tenant に保存する。表示時は get_svg() で SVG を取り出し、
 * iframe 内で inline で埋め込んで currentColor をテーマカラーに連動させる。
 *
 * SVG は stroke ベース (24x24 viewBox, currentColor) で統一 — 円形背景の中に置く想定。
 */
class Mimamori_Bot_Avatars {

	/**
	 * @return array<string, array{label:string, svg:string}>
	 */
	public static function presets(): array {
		// 共通 svg ヘッダ — 表記簡略化のため
		$base = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="100%" height="100%"';
		return [
			'person' => [
				'label' => '👤 スタッフ',
				'svg'   => '<svg ' . $base . '>'
					. '<circle cx="12" cy="9" r="3.6"/>'
					. '<path d="M4.5 20c0-4.2 3.5-6.5 7.5-6.5s7.5 2.3 7.5 6.5"/>'
					. '</svg>',
			],
			'support-female' => [
				'label' => '👩 女性スタッフ',
				'svg'   => '<svg ' . $base . '>'
					. '<circle cx="12" cy="10" r="4"/>'
					. '<path d="M6 9c0-3.3 2.7-6 6-6s6 2.7 6 6"/>'
					. '<path d="M7 11c-0.6 1.8-0.6 3.2 0 4.5M17 11c0.6 1.8 0.6 3.2 0 4.5"/>'
					. '<path d="M4.5 21c0-4.2 3.5-6.5 7.5-6.5s7.5 2.3 7.5 6.5"/>'
					. '</svg>',
			],
			'support-male' => [
				'label' => '👨 男性スタッフ',
				'svg'   => '<svg ' . $base . '>'
					. '<circle cx="12" cy="10" r="4"/>'
					. '<path d="M7.5 8.5c0-3 2-5 4.5-5s4.5 2 4.5 5"/>'
					. '<path d="M4.5 21c0-4.2 3.5-6.5 7.5-6.5s7.5 2.3 7.5 6.5"/>'
					. '</svg>',
			],
			'person-headset' => [
				'label' => '🎧 オペレーター',
				'svg'   => '<svg ' . $base . '>'
					. '<path d="M5 14v-3a7 7 0 0 1 14 0v3"/>'
					. '<rect x="3.5" y="13.5" width="3.5" height="6" rx="1.2"/>'
					. '<rect x="17" y="13.5" width="3.5" height="6" rx="1.2"/>'
					. '<path d="M19 19.5a4 4 0 0 1-4 3.5h-2"/>'
					. '</svg>',
			],
			'robot' => [
				'label' => '🤖 ロボット',
				'svg'   => '<svg ' . $base . '>'
					. '<rect x="4.5" y="7" width="15" height="12.5" rx="2.5"/>'
					. '<path d="M12 3v4"/>'
					. '<circle cx="10" cy="13" r="0.9" fill="currentColor"/>'
					. '<circle cx="14" cy="13" r="0.9" fill="currentColor"/>'
					. '<path d="M9.5 17h5"/>'
					. '<path d="M3 12v3M21 12v3"/>'
					. '</svg>',
			],
			'cat' => [
				'label' => '🐱 ネコ',
				'svg'   => '<svg ' . $base . '>'
					. '<path d="M5 8.5 L4 3.5 L9 7"/>'
					. '<path d="M19 8.5 L20 3.5 L15 7"/>'
					. '<circle cx="12" cy="13.5" r="6.5"/>'
					. '<circle cx="9.5" cy="12.5" r="0.9" fill="currentColor"/>'
					. '<circle cx="14.5" cy="12.5" r="0.9" fill="currentColor"/>'
					. '<path d="M11 15.5l1 0.7 1-0.7"/>'
					. '<path d="M7 14h-2M19 14h-2"/>'
					. '</svg>',
			],
			'dog' => [
				'label' => '🐶 イヌ',
				'svg'   => '<svg ' . $base . '>'
					. '<path d="M6 5 L4 9.5 L5 12"/>'
					. '<path d="M18 5 L20 9.5 L19 12"/>'
					. '<circle cx="12" cy="14" r="6.5"/>'
					. '<circle cx="9.5" cy="13" r="0.9" fill="currentColor"/>'
					. '<circle cx="14.5" cy="13" r="0.9" fill="currentColor"/>'
					. '<circle cx="12" cy="15.5" r="0.7" fill="currentColor"/>'
					. '<path d="M11 17h2"/>'
					. '</svg>',
			],
			'rabbit' => [
				'label' => '🐰 ウサギ',
				'svg'   => '<svg ' . $base . '>'
					. '<path d="M8.5 8.5C8.5 5 8 2.5 7 2.5S5.5 5 6.5 9"/>'
					. '<path d="M15.5 8.5C15.5 5 16 2.5 17 2.5s1.5 2.5 0.5 6.5"/>'
					. '<circle cx="12" cy="14.5" r="6"/>'
					. '<circle cx="9.5" cy="13.5" r="0.9" fill="currentColor"/>'
					. '<circle cx="14.5" cy="13.5" r="0.9" fill="currentColor"/>'
					. '<path d="M11.2 16.5l0.8 0.6 0.8-0.6"/>'
					. '</svg>',
			],
		];
	}

	public static function get_svg( string $key ): string {
		$presets = self::presets();
		return isset( $presets[ $key ] ) ? $presets[ $key ]['svg'] : '';
	}

	public static function is_valid_key( string $key ): bool {
		if ( $key === '' ) return true; // 空 = アイコンなし
		return array_key_exists( $key, self::presets() );
	}
}
