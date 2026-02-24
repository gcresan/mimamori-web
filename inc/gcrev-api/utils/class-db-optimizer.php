<?php
// FILE: inc/gcrev-api/utils/class-db-optimizer.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_DB_Optimizer' ) ) { return; }

/**
 * Gcrev_DB_Optimizer
 *
 * wp_postmeta 等のコアテーブルに GCREV 用のインデックスを追加し、
 * 100社規模での meta_query パフォーマンスを改善する。
 *
 * 冪等: 既存インデックスがあればスキップ。
 *
 * @package GCREV_INSIGHT
 * @since   3.0.0
 */
class Gcrev_DB_Optimizer {

    /**
     * 追加するインデックスの定義
     * [ テーブルサフィックス => [ インデックス名 => カラム定義 ] ]
     */
    private const INDEXES = [
        'postmeta' => [
            'gcrev_meta_key_value_idx' => 'meta_key, meta_value(20)',
        ],
    ];

    /**
     * 定義済みのインデックスがすべて存在することを確認し、
     * 不足があれば追加する。
     */
    public static function ensure_indexes(): void {
        global $wpdb;

        foreach ( self::INDEXES as $table_suffix => $indexes ) {
            $table = $wpdb->prefix . $table_suffix;

            foreach ( $indexes as $index_name => $columns ) {
                if ( self::index_exists( $table, $index_name ) ) {
                    continue;
                }
                self::add_index( $table, $index_name, $columns );
            }
        }
    }

    /**
     * インデックスの存在チェック。
     *
     * @param  string $table      テーブル名（フルネーム）
     * @param  string $index_name インデックス名
     * @return bool
     */
    public static function index_exists( string $table, string $index_name ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table,
            $index_name
        ) );

        return $count > 0;
    }

    /**
     * インデックスを追加する。
     *
     * @param  string $table      テーブル名
     * @param  string $index_name インデックス名
     * @param  string $columns    カラム定義
     * @return bool   成功/失敗
     */
    private static function add_index( string $table, string $index_name, string $columns ): bool {
        global $wpdb;

        // テーブル名・インデックス名は定数から来るため安全
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $result = $wpdb->query(
            "ALTER TABLE `{$table}` ADD INDEX `{$index_name}` ({$columns})"
        );

        if ( $result === false ) {
            error_log( "[GCREV][DB] Failed to add index {$index_name} on {$table}: " . $wpdb->last_error );
            return false;
        }

        error_log( "[GCREV][DB] Added index {$index_name} on {$table}" );
        return true;
    }

    /**
     * 全インデックスの現在の状態を取得する（管理画面表示用）。
     *
     * @return array [ ['table' => ..., 'index' => ..., 'columns' => ..., 'exists' => bool], ... ]
     */
    public static function get_index_status(): array {
        global $wpdb;
        $result = [];

        foreach ( self::INDEXES as $table_suffix => $indexes ) {
            $table = $wpdb->prefix . $table_suffix;

            foreach ( $indexes as $index_name => $columns ) {
                $result[] = [
                    'table'   => $table,
                    'index'   => $index_name,
                    'columns' => $columns,
                    'exists'  => self::index_exists( $table, $index_name ),
                ];
            }
        }

        return $result;
    }
}
