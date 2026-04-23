<?php
// FILE: inc/gcrev-api/utils/class-qa-user-question-pool.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Mimamori_QA_User_Question_Pool' ) ) { return; }

/**
 * Mimamori_QA_User_Question_Pool
 *
 * 実ユーザーのチャットログから QA バッチ用の質問を抽出・管理する。
 *
 * 流れ:
 *   1. ingest_from_chat_logs()  … `gcrev_chat_logs` から候補質問を pending で登録
 *   2. 管理者が管理画面で approve / reject
 *   3. QA Runner が get_approved() で approved 質問を取得し、合成質問と混ぜて実行
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Mimamori_QA_User_Question_Pool {

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_ERROR       = 'error';         // AI応答エラー
    public const SOURCE_PARAM_GATE  = 'param_gate';    // 確認質問で終了
    public const SOURCE_FOLLOWUP    = 'followup';      // 追加説明が必要
    public const SOURCE_FREQUENT    = 'frequent';      // 同一/類似の頻出質問
    public const SOURCE_NORMAL      = 'normal';        // 通常

    private const MAX_MESSAGE_LENGTH = 500;

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'gcrev_qa_question_pool';
    }

    public static function create_table(): void {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            message_hash CHAR(40) NOT NULL,
            message VARCHAR(500) NOT NULL,
            category VARCHAR(32) NOT NULL DEFAULT 'general',
            page_type VARCHAR(64) NOT NULL DEFAULT 'unknown',
            source_log_id BIGINT(20) UNSIGNED NULL,
            source_reason VARCHAR(32) NOT NULL DEFAULT 'normal',
            occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            admin_notes VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            reviewed_at DATETIME NULL,
            reviewed_by BIGINT(20) UNSIGNED NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY message_hash (message_hash),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // =========================================================
    // Ingest
    // =========================================================

    /**
     * チャットログから候補質問を取り込む。
     *
     * 既に pool に入っている message（approved/rejected/pending 問わず）はスキップし、
     * 出現回数だけ +1 する。
     *
     * @param  int $days        対象期間
     * @param  int $max_ingest  新規追加の上限
     * @return array{ inserted:int, updated:int, skipped:int, total_scanned:int }
     */
    public static function ingest_from_chat_logs( int $days = 7, int $max_ingest = 50 ): array {
        global $wpdb;

        $days       = max( 1, min( 90, $days ) );
        $max_ingest = max( 1, min( 500, $max_ingest ) );

        $chat_logs_table = $wpdb->prefix . 'gcrev_chat_logs';
        $pool_table      = self::table_name();

        if ( ! self::table_exists( $chat_logs_table ) ) {
            return [ 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'total_scanned' => 0 ];
        }

        $cutoff = ( new DateTimeImmutable( 'now', wp_timezone() ) )
            ->modify( "-{$days} days" )
            ->format( 'Y-m-d H:i:s' );

        // Quick Prompt（UIワンクリック）は実質ランダムなので除外
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, message, intent, page_type, param_gate, is_followup, error_message
             FROM {$chat_logs_table}
             WHERE created_at >= %s
               AND is_quick_prompt = 0
               AND message != ''
             ORDER BY created_at DESC",
            $cutoff
        ), ARRAY_A );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return [ 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'total_scanned' => 0 ];
        }

        // メッセージ単位で集計
        $buckets = [];
        foreach ( $rows as $row ) {
            $normalized = self::normalize_message( (string) $row['message'] );
            if ( $normalized === '' ) { continue; }
            $hash = sha1( $normalized );

            if ( ! isset( $buckets[ $hash ] ) ) {
                $buckets[ $hash ] = [
                    'hash'      => $hash,
                    'message'   => mb_substr( $normalized, 0, self::MAX_MESSAGE_LENGTH ),
                    'count'     => 0,
                    'source_log_id' => (int) $row['id'],
                    'page_type' => (string) $row['page_type'],
                    'reasons'   => [],
                ];
            }
            $buckets[ $hash ]['count']++;

            $reasons = &$buckets[ $hash ]['reasons'];
            if ( ! empty( $row['error_message'] ) )   { $reasons[ self::SOURCE_ERROR ] = true; }
            if ( ! empty( $row['param_gate'] ) )      { $reasons[ self::SOURCE_PARAM_GATE ] = true; }
            if ( ! empty( $row['is_followup'] ) )     { $reasons[ self::SOURCE_FOLLOWUP ] = true; }
            unset( $reasons );
        }

        // 優先度順にソート: 未解決を示すシグナルが強いもの > 頻度
        $priority_order = [
            self::SOURCE_ERROR      => 4,
            self::SOURCE_PARAM_GATE => 3,
            self::SOURCE_FOLLOWUP   => 2,
            self::SOURCE_FREQUENT   => 1,
            self::SOURCE_NORMAL     => 0,
        ];

        $candidates = array_values( $buckets );
        usort( $candidates, static function ( $a, $b ) use ( $priority_order ) {
            $pa = self::priority_score( $a, $priority_order );
            $pb = self::priority_score( $b, $priority_order );
            if ( $pa !== $pb ) { return $pb - $pa; }
            return $b['count'] - $a['count'];
        } );

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $now      = current_time( 'mysql' );

        foreach ( $candidates as $c ) {
            if ( $inserted >= $max_ingest ) { break; }

            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, occurrence_count, status FROM {$pool_table} WHERE message_hash = %s",
                $c['hash']
            ) );

            if ( $existing ) {
                $wpdb->update(
                    $pool_table,
                    [ 'occurrence_count' => (int) $existing->occurrence_count + (int) $c['count'] ],
                    [ 'id' => (int) $existing->id ],
                    [ '%d' ],
                    [ '%d' ]
                );
                $updated++;
                continue;
            }

            // 新規
            $reason = self::pick_primary_reason( $c['reasons'], $c['count'] );
            $category  = self::classify_category( $c['message'] );
            $page_type = self::normalize_page_type( (string) $c['page_type'] );

            $result = $wpdb->insert(
                $pool_table,
                [
                    'message_hash'     => $c['hash'],
                    'message'          => $c['message'],
                    'category'         => $category,
                    'page_type'        => $page_type,
                    'source_log_id'    => $c['source_log_id'],
                    'source_reason'    => $reason,
                    'occurrence_count' => (int) $c['count'],
                    'status'           => self::STATUS_PENDING,
                    'created_at'       => $now,
                ],
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
            );

            if ( $result ) {
                $inserted++;
            } else {
                $skipped++;
            }
        }

        return [
            'inserted'      => $inserted,
            'updated'       => $updated,
            'skipped'       => $skipped,
            'total_scanned' => count( $rows ),
        ];
    }

    // =========================================================
    // Query API
    // =========================================================

    /**
     * @param  int $limit 最大件数
     * @return array<int,array> QA Generator が扱える形式（id / category / message / page_type / current_page / history / section_context）
     */
    public static function get_approved_questions( int $limit = 20 ): array {
        global $wpdb;
        $table = self::table_name();
        if ( ! self::table_exists( $table ) ) { return []; }

        $limit = max( 1, min( 500, $limit ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, message, category, page_type
             FROM {$table}
             WHERE status = %s
             ORDER BY occurrence_count DESC, id ASC
             LIMIT %d",
            self::STATUS_APPROVED,
            $limit
        ), ARRAY_A );

        if ( ! is_array( $rows ) ) { return []; }

        $page_urls = [
            'report_dashboard' => [ 'url' => 'https://dev.mimamori-web.jp/dashboard/', 'title' => 'ダッシュボード | みまもりウェブ' ],
            'analysis_detail'  => [ 'url' => 'https://dev.mimamori-web.jp/analysis/',  'title' => 'アクセス解析 | みまもりウェブ' ],
            'unknown'          => [ 'url' => 'https://dev.mimamori-web.jp/',           'title' => 'みまもりウェブ' ],
        ];

        $out = [];
        foreach ( $rows as $i => $row ) {
            $page_type = $row['page_type'] ?: 'unknown';
            if ( ! isset( $page_urls[ $page_type ] ) ) { $page_type = 'unknown'; }

            $out[] = [
                'id'              => sprintf( 'pool_%03d', (int) $row['id'] ),
                'category'        => $row['category'] ?: 'general',
                'message'         => (string) $row['message'],
                'page_type'       => $page_type,
                'current_page'    => $page_urls[ $page_type ],
                'history'         => [],
                'section_context' => null,
                '_source'         => 'user_pool',
                '_pool_id'        => (int) $row['id'],
            ];
        }
        return $out;
    }

    public static function count_by_status(): array {
        global $wpdb;
        $table = self::table_name();
        if ( ! self::table_exists( $table ) ) {
            return [ self::STATUS_PENDING => 0, self::STATUS_APPROVED => 0, self::STATUS_REJECTED => 0 ];
        }
        $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
        $counts = [ self::STATUS_PENDING => 0, self::STATUS_APPROVED => 0, self::STATUS_REJECTED => 0 ];
        foreach ( (array) $rows as $r ) {
            $s = (string) $r['status'];
            if ( isset( $counts[ $s ] ) ) { $counts[ $s ] = (int) $r['c']; }
        }
        return $counts;
    }

    /**
     * @return array<int,object>
     */
    public static function list_by_status( string $status, int $limit = 200 ): array {
        global $wpdb;
        $table = self::table_name();
        if ( ! self::table_exists( $table ) ) { return []; }

        $limit = max( 1, min( 500, $limit ) );

        if ( $status === 'all' ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
                $limit
            ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY occurrence_count DESC, id DESC LIMIT %d",
                $status,
                $limit
            ) );
        }
        return is_array( $rows ) ? $rows : [];
    }

    public static function set_status( int $id, string $status, int $reviewer_id = 0, string $notes = '' ): bool {
        if ( ! in_array( $status, [ self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_PENDING ], true ) ) {
            return false;
        }
        global $wpdb;
        $table = self::table_name();

        $data = [
            'status'      => $status,
            'reviewed_at' => current_time( 'mysql' ),
            'reviewed_by' => $reviewer_id > 0 ? $reviewer_id : null,
        ];
        $format = [ '%s', '%s', '%d' ];

        if ( $notes !== '' ) {
            $data['admin_notes'] = mb_substr( $notes, 0, 500 );
            $format[] = '%s';
        }

        return (bool) $wpdb->update( $table, $data, [ 'id' => $id ], $format, [ '%d' ] );
    }

    public static function bulk_update_status( array $ids, string $status, int $reviewer_id = 0 ): int {
        $updated = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id <= 0 ) { continue; }
            if ( self::set_status( $id, $status, $reviewer_id ) ) {
                $updated++;
            }
        }
        return $updated;
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $table = self::table_name();
        return (bool) $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
    }

    // =========================================================
    // Helpers
    // =========================================================

    private static function normalize_message( string $raw ): string {
        $s = preg_replace( '/\s+/u', ' ', trim( $raw ) );
        return (string) $s;
    }

    private static function priority_score( array $bucket, array $priority_order ): int {
        if ( empty( $bucket['reasons'] ) ) {
            return $bucket['count'] >= 3 ? $priority_order[ self::SOURCE_FREQUENT ] : $priority_order[ self::SOURCE_NORMAL ];
        }
        $max = 0;
        foreach ( array_keys( $bucket['reasons'] ) as $r ) {
            $max = max( $max, $priority_order[ $r ] ?? 0 );
        }
        return $max;
    }

    private static function pick_primary_reason( array $reasons, int $count ): string {
        if ( isset( $reasons[ self::SOURCE_ERROR ] ) )      { return self::SOURCE_ERROR; }
        if ( isset( $reasons[ self::SOURCE_PARAM_GATE ] ) ) { return self::SOURCE_PARAM_GATE; }
        if ( isset( $reasons[ self::SOURCE_FOLLOWUP ] ) )   { return self::SOURCE_FOLLOWUP; }
        if ( $count >= 3 )                                  { return self::SOURCE_FREQUENT; }
        return self::SOURCE_NORMAL;
    }

    /**
     * メッセージからカテゴリを粗く推定する（QA Generator と同じカテゴリ空間）。
     */
    private static function classify_category( string $message ): string {
        $m = mb_strtolower( $message );

        if ( preg_match( '/前月|先月|前年|比較|比べ|対前/u', $m ) ) {
            return 'comparison';
        }
        if ( preg_match( '/理由|なぜ|原因|下がった|増えた|減った|変化|傾向|トレンド/u', $m ) ) {
            return 'trend';
        }
        if ( preg_match( '/ページ|LP|ランディング|URL|記事/u', $m ) ) {
            return 'page';
        }
        if ( preg_match( '/数|件|%|率|比率|セッション|ユーザー|PV|表示|クリック|流入|アクセス/u', $m ) ) {
            return 'kpi';
        }
        return 'general';
    }

    private static function normalize_page_type( string $page_type ): string {
        $valid = [ 'report_dashboard', 'analysis_detail', 'unknown' ];
        return in_array( $page_type, $valid, true ) ? $page_type : 'unknown';
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table;
    }
}
