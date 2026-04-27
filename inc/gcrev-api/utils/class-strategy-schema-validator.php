<?php
// FILE: inc/gcrev-api/utils/class-strategy-schema-validator.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Strategy_Schema_Validator' ) ) { return; }

/**
 * Gcrev_Strategy_Schema_Validator
 *
 * 戦略JSON（schema_version "1.0"）のスキーマ検証 + 正規化。
 *
 * - PDF抽出（Claude Vision）と手動入力のどちらでも同じスキーマを通す
 * - 必須フィールドの欠落・型違反を検出して errors を返す
 * - 軽微な揺れ（null・空文字・配列でない値）は normalize で吸収
 *
 * 設計上の構造は CLAUDE.md / 戦略連動レポート設計書 §3 を参照。
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Gcrev_Strategy_Schema_Validator {

	public const SCHEMA_VERSION = '1.0';

	/**
	 * 検証 + 正規化を一度に行う。
	 *
	 * @param array $raw 任意の連想配列（外部から来た JSON を json_decode したもの）
	 * @return array { valid: bool, errors: string[], normalized: array }
	 */
	public static function validate( array $raw ): array {
		$errors     = [];
		$normalized = self::normalize( $raw );

		// --- meta ---
		if ( empty( $normalized['meta']['client_name'] ) ) {
			$errors[] = 'meta.client_name は必須です。';
		}
		if ( empty( $normalized['meta']['effective_from'] ) ) {
			$errors[] = 'meta.effective_from は必須です（YYYY-MM-DD）。';
		} elseif ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $normalized['meta']['effective_from'] ) ) {
			$errors[] = 'meta.effective_from は YYYY-MM-DD 形式である必要があります。';
		}

		// --- core 5 fields ---
		if ( $normalized['target'] === '' ) {
			$errors[] = 'target は必須です。';
		}
		if ( $normalized['strategy'] === '' ) {
			$errors[] = 'strategy は必須です。';
		}
		if ( $normalized['conversion_path'] === '' ) {
			$errors[] = 'conversion_path は必須です。';
		}
		if ( empty( $normalized['issues'] ) ) {
			$errors[] = 'issues は最低1件必要です。';
		}
		if ( empty( $normalized['value_proposition'] ) ) {
			$errors[] = 'value_proposition は最低1件必要です。';
		}

		// --- 拡張フィールド: 軽い形チェックのみ（空はOK）---
		foreach ( $normalized['competitors'] as $i => $c ) {
			if ( empty( $c['name'] ) ) {
				$errors[] = "competitors[{$i}].name が空です。";
			}
			if ( ! in_array( $c['type'] ?? '', [ 'peer', 'rival_major', 'rival_local', '' ], true ) ) {
				$errors[] = "competitors[{$i}].type は peer / rival_major / rival_local のいずれかにしてください。";
			}
		}
		foreach ( $normalized['customer_journey'] as $i => $stage ) {
			if ( empty( $stage['stage'] ) ) {
				$errors[] = "customer_journey[{$i}].stage が空です。";
			}
		}
		foreach ( $normalized['site_map_priorities'] as $i => $p ) {
			if ( empty( $p['path'] ) ) {
				$errors[] = "site_map_priorities[{$i}].path が空です。";
			}
		}

		return [
			'valid'      => empty( $errors ),
			'errors'     => $errors,
			'normalized' => $normalized,
		];
	}

	/**
	 * 入力を期待スキーマに沿って正規化する。
	 * 欠けているキーは空配列・空文字で埋める（保存時に NOT NULL 違反にならないようにする）。
	 */
	public static function normalize( array $raw ): array {
		$str = static fn( $k, $default = '' ) => is_string( $k ) ? trim( $k ) : (string) $default;
		$arr = static fn( $k ) => is_array( $k ) ? array_values( array_filter( $k, static fn( $v ) => $v !== null && $v !== '' ) ) : [];

		$meta = is_array( $raw['meta'] ?? null ) ? $raw['meta'] : [];

		$normalized = [
			'schema_version' => self::SCHEMA_VERSION,
			'meta' => [
				'client_name'     => $str( $meta['client_name']     ?? '' ),
				'site_url'        => $str( $meta['site_url']        ?? '' ),
				'extracted_from'  => $str( $meta['extracted_from']  ?? '' ),
				'effective_from'  => $str( $meta['effective_from']  ?? '' ),
			],

			// core
			'target'            => $str( $raw['target']           ?? '' ),
			'issues'            => array_values( array_map( 'strval', $arr( $raw['issues']            ?? [] ) ) ),
			'strategy'          => $str( $raw['strategy']         ?? '' ),
			'value_proposition' => array_values( array_map( 'strval', $arr( $raw['value_proposition'] ?? [] ) ) ),
			'conversion_path'   => $str( $raw['conversion_path']  ?? '' ),

			// 拡張: 3C
			'company_strengths' => [
				'design_function' => array_values( array_map( 'strval', $arr( $raw['company_strengths']['design_function'] ?? [] ) ) ),
				'support_trust'   => array_values( array_map( 'strval', $arr( $raw['company_strengths']['support_trust']   ?? [] ) ) ),
				'economy_eco'     => array_values( array_map( 'strval', $arr( $raw['company_strengths']['economy_eco']     ?? [] ) ) ),
			],
			'competitors' => self::normalize_competitors( $raw['competitors'] ?? [] ),
			'differentiation_axes' => array_values( array_map( 'strval', $arr( $raw['differentiation_axes'] ?? [] ) ) ),

			// 拡張: 顧客
			'customer_segments' => self::normalize_customer_segments( $raw['customer_segments'] ?? [] ),
			'customer_journey'  => self::normalize_customer_journey( $raw['customer_journey'] ?? [] ),

			// 拡張: HP構造
			'site_map_priorities' => self::normalize_site_map_priorities( $raw['site_map_priorities'] ?? [] ),
		];

		return $normalized;
	}

	private static function normalize_competitors( $raw ): array {
		if ( ! is_array( $raw ) ) return [];
		$out = [];
		foreach ( $raw as $c ) {
			if ( ! is_array( $c ) ) continue;
			$out[] = [
				'name'  => isset( $c['name'] )  ? trim( (string) $c['name'] )  : '',
				'url'   => isset( $c['url'] )   ? esc_url_raw( (string) $c['url'] ) : '',
				'type'  => isset( $c['type'] )  ? (string) $c['type'] : '',
				'notes' => isset( $c['notes'] ) ? (string) $c['notes'] : '',
			];
		}
		return $out;
	}

	private static function normalize_customer_segments( $raw ): array {
		$default_segment = static fn( string $label ) => [
			'label'   => $label,
			'channel' => '',
			'kpi'     => [],
		];
		if ( ! is_array( $raw ) ) {
			return [
				'potential'   => $default_segment( '潜在層' ),
				'semi_active' => $default_segment( '準顕在層' ),
				'active'      => $default_segment( '顕在層' ),
			];
		}
		$pick = static function ( $seg, string $fallback_label ) use ( $default_segment ) {
			if ( ! is_array( $seg ) ) return $default_segment( $fallback_label );
			return [
				'label'   => isset( $seg['label'] ) && $seg['label'] !== '' ? (string) $seg['label'] : $fallback_label,
				'channel' => isset( $seg['channel'] ) ? (string) $seg['channel'] : '',
				'kpi'     => array_values( array_map( 'strval', is_array( $seg['kpi'] ?? null ) ? $seg['kpi'] : [] ) ),
			];
		};
		return [
			'potential'   => $pick( $raw['potential']   ?? null, '潜在層' ),
			'semi_active' => $pick( $raw['semi_active'] ?? null, '準顕在層' ),
			'active'      => $pick( $raw['active']      ?? null, '顕在層' ),
		];
	}

	private static function normalize_customer_journey( $raw ): array {
		if ( ! is_array( $raw ) ) return [];
		$out = [];
		foreach ( $raw as $stage ) {
			if ( ! is_array( $stage ) ) continue;
			$out[] = [
				'stage'     => isset( $stage['stage'] ) ? (string) $stage['stage'] : '',
				'pains'     => array_values( array_map( 'strval', is_array( $stage['pains']     ?? null ) ? $stage['pains']     : [] ) ),
				'messaging' => array_values( array_map( 'strval', is_array( $stage['messaging'] ?? null ) ? $stage['messaging'] : [] ) ),
			];
		}
		return $out;
	}

	private static function normalize_site_map_priorities( $raw ): array {
		if ( ! is_array( $raw ) ) return [];
		$out = [];
		foreach ( $raw as $p ) {
			if ( ! is_array( $p ) ) continue;
			$out[] = [
				'path'      => isset( $p['path'] ) ? (string) $p['path'] : '',
				'role'      => isset( $p['role'] ) ? (string) $p['role'] : '',
				'kpi_focus' => array_values( array_map( 'strval', is_array( $p['kpi_focus'] ?? null ) ? $p['kpi_focus'] : [] ) ),
			];
		}
		return $out;
	}

	/**
	 * 空のテンプレート（手動入力フォームの初期値・PDF未取込時のプレースホルダ用）
	 */
	public static function empty_template(): array {
		return self::normalize( [] );
	}
}
