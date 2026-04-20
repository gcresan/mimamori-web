<?php
// FILE: inc/gcrev-api/utils/class-path-filter.php

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Path_Filter') ) { return; }

/**
 * Gcrev_Path_Filter
 *
 * クライアント設定の「解析対象URL条件」「解析除外URL条件」を共通ロジックで扱う。
 * GA4 / GSC / その他のページ単位データソースで同じ判定ルールを使うためのユーティリティ。
 *
 * 判定優先順位:
 *   1. 除外条件に一致 → 除外
 *   2. 対象条件が設定されていて、いずれにも一致しない → 除外
 *   3. それ以外 → 対象（デフォルト対象）
 *
 * @package Mimamori_Web
 * @since   2.0.0
 */
class Gcrev_Path_Filter {

    /**
     * user_meta から include / exclude 配列を取得する。
     */
    public static function get_user_filters( int $user_id ): array {
        $include = get_user_meta( $user_id, '_gcrev_include_paths', true );
        $exclude = get_user_meta( $user_id, '_gcrev_exclude_paths', true );

        return [
            'include' => self::normalize_list( $include ),
            'exclude' => self::normalize_list( $exclude ),
        ];
    }

    /**
     * フィルタが何かしら設定されているか
     */
    public static function has_filters( int $user_id ): bool {
        $f = self::get_user_filters( $user_id );
        return ! empty( $f['include'] ) || ! empty( $f['exclude'] );
    }

    /**
     * フルURL or 相対パス文字列から path 部分（"/foo/bar"）を抽出する。
     *  - "https://example.com/media/article" → "/media/article"
     *  - "/media/article?utm=1#x"            → "/media/article"
     *  - ""                                  → "/"
     */
    public static function extract_path( string $page_url ): string {
        $page_url = trim( $page_url );
        if ( $page_url === '' ) return '/';

        // 既に絶対パス
        if ( $page_url[0] === '/' ) {
            $q = strpos( $page_url, '?' );
            if ( $q !== false ) { $page_url = substr( $page_url, 0, $q ); }
            $h = strpos( $page_url, '#' );
            if ( $h !== false ) { $page_url = substr( $page_url, 0, $h ); }
            return $page_url === '' ? '/' : $page_url;
        }

        $parsed = wp_parse_url( $page_url );
        $path   = isset( $parsed['path'] ) && $parsed['path'] !== '' ? $parsed['path'] : '/';
        return $path;
    }

    /**
     * page URL（フルURL or path）が include/exclude 条件を通過するか判定する。
     *
     * @param string $page_url
     * @param array  $include 対象条件（前方一致 path プレフィックス配列）
     * @param array  $exclude 除外条件（前方一致 path プレフィックス配列）
     * @return bool true = 集計対象に含める / false = 除外
     */
    public static function matches( string $page_url, array $include, array $exclude ): bool {
        $path = self::extract_path( $page_url );

        $include = self::normalize_list( $include );
        $exclude = self::normalize_list( $exclude );

        // 1. 除外を最優先
        foreach ( $exclude as $ex ) {
            if ( self::path_starts_with( $path, $ex ) ) {
                return false;
            }
        }

        // 2. 対象条件が設定されていれば、いずれかに一致する必要がある
        if ( ! empty( $include ) ) {
            foreach ( $include as $in ) {
                if ( self::path_starts_with( $path, $in ) ) {
                    return true;
                }
            }
            return false;
        }

        // 3. デフォルト対象
        return true;
    }

    /**
     * フィルタ状態に応じたキャッシュキーサフィックスを返す。
     *  - フィルタなし: ''
     *  - フィルタあり: '_pf' + 8桁ハッシュ（条件内容に応じて変化）
     *
     * 既存の '_jp' / '_ex' といったサフィックスとは独立して使える。
     */
    public static function cache_suffix( int $user_id ): string {
        $f = self::get_user_filters( $user_id );
        if ( empty( $f['include'] ) && empty( $f['exclude'] ) ) {
            return '';
        }
        sort( $f['include'] );
        sort( $f['exclude'] );
        $hash = substr( md5( wp_json_encode( $f, JSON_UNESCAPED_UNICODE ) ), 0, 8 );
        return '_pf' . $hash;
    }

    /**
     * GSC 由来のキャッシュ（キーワード/ダッシュボード/レポート等）を一括削除する。
     * パス条件変更時の再同期に使用。
     */
    public static function purge_user_caches( int $user_id ): int {
        global $wpdb;

        $patterns = [
            'gcrev_keywords_'      . $user_id . '_%',
            'gcrev_dash_'          . $user_id . '_%',
            'gcrev_dash_bydate_v2_'. $user_id . '_%',
            'gcrev_kpi_'           . $user_id . '_%',
            'gcrev_trend_'         . $user_id . '_%',
            'gcrev_trend_daily_'   . $user_id . '_%',
            'gcrev_page_'          . $user_id . '_%',
            'gcrev_source_'        . $user_id . '_%',
            'gcrev_region_'        . $user_id . '_%',
            'gcrev_report_'        . $user_id . '_%',
        ];

        $deleted = 0;
        foreach ( $patterns as $pat ) {
            $like_value   = $wpdb->esc_like( '_transient_'         . $pat ) . '%';
            $like_timeout = $wpdb->esc_like( '_transient_timeout_' . $pat ) . '%';
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like_value,
                $like_timeout
            ) );
        }

        return $deleted;
    }

    // -----------------------------------------------------------
    // 内部ヘルパー
    // -----------------------------------------------------------

    /**
     * path プレフィックス比較。先頭スラッシュなしで指定されても先頭スラッシュ付きで一致を許す。
     */
    private static function path_starts_with( string $path, string $prefix ): bool {
        $prefix = trim( $prefix );
        if ( $prefix === '' ) return false;
        if ( $prefix[0] !== '/' ) {
            $prefix = '/' . $prefix;
        }
        return strpos( $path, $prefix ) === 0;
    }

    /**
     * 配列を正規化: 文字列化 → trim → 空除外 → 値配列再採番
     */
    private static function normalize_list( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $out = [];
        foreach ( $raw as $v ) {
            $s = trim( (string) $v );
            if ( $s !== '' ) {
                $out[] = $s;
            }
        }
        return array_values( array_unique( $out ) );
    }
}
