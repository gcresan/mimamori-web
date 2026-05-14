<?php
// FILE: inc/gcrev-api/modules/social/class-social-poster.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Social_Poster') ) { return; }

/**
 * Gcrev_Social_Poster
 *
 * gcrev_social_posts レコードを受け取り、選択された各プラットフォーム
 * （facebook / instagram / threads / line）へディスパッチする。
 *
 * 投稿結果は platform_results JSON に追記して再保存。
 * 全媒体成功 → status='posted'
 * 一部失敗   → status='partial'
 * 全失敗     → status='failed'
 *
 * @package Mimamori_Web
 * @since   2.2.0
 */
class Gcrev_Social_Poster {

    const SUPPORTED_PLATFORMS = ['facebook', 'instagram', 'threads', 'line'];

    /**
     * 投稿を即時実行。
     *
     * @param array $post  social_posts テーブルの1行（連想配列）
     * @return array ['status'=>string, 'platform_results'=>array, 'error_message'=>string]
     */
    public static function execute(array $post): array {
        $user_id   = (int) ($post['user_id'] ?? 0);
        $body_raw  = (string) ($post['body'] ?? '');
        $media_url = (string) ($post['media_url'] ?? '');
        $link_url  = (string) ($post['link_url'] ?? '');
        $platforms = self::decode_json($post['platforms'] ?? '[]');
        $overrides = self::decode_json($post['platform_overrides'] ?? '{}');
        $existing  = self::decode_json($post['platform_results'] ?? '{}');

        if ( $user_id <= 0 || empty($platforms) ) {
            return ['status' => 'failed', 'platform_results' => $existing, 'error_message' => 'user_id or platforms is missing'];
        }

        $results  = $existing;
        $success  = 0;
        $fail     = 0;
        $skipped  = 0;
        $messages = [];

        foreach ( $platforms as $platform ) {
            if ( ! in_array($platform, self::SUPPORTED_PLATFORMS, true) ) {
                continue;
            }
            // 既に成功していればスキップ（リトライ時に二重投稿を防ぐ）
            if ( isset($results[$platform]['status']) && $results[$platform]['status'] === 'success' ) {
                $skipped++;
                continue;
            }

            $platform_body = isset($overrides[$platform]['body'])
                ? (string) $overrides[$platform]['body']
                : $body_raw;

            // 媒体別文字数制限を適用
            $platform_body = self::apply_length_limit($platform, $platform_body);

            $r = self::dispatch($platform, $user_id, $platform_body, $media_url, $link_url);

            $results[$platform] = [
                'status'           => $r['success'] ? 'success' : 'failed',
                'platform_post_id' => $r['platform_post_id'] ?? '',
                'message'          => $r['message'] ?? '',
                'posted_at'        => current_time('mysql'),
            ];

            if ( $r['success'] ) {
                $success++;
            } else {
                $fail++;
                $messages[] = "[{$platform}] " . ($r['message'] ?? 'unknown');
            }
        }

        if ( $success > 0 && $fail === 0 ) {
            $status = 'posted';
        } elseif ( $success > 0 && $fail > 0 ) {
            $status = 'partial';
        } elseif ( $success === 0 && $fail > 0 ) {
            $status = 'failed';
        } else {
            $status = 'posted';  // 全スキップ（既成功）
        }

        return [
            'status'           => $status,
            'platform_results' => $results,
            'error_message'    => implode(' / ', $messages),
        ];
    }

    /**
     * 単一プラットフォームへの投稿を実行
     */
    private static function dispatch(string $platform, int $user_id, string $body, string $media_url, string $link_url): array {
        switch ( $platform ) {
            case 'facebook':
                return Gcrev_Meta_Client::post_to_facebook($user_id, $body, $media_url, $link_url);
            case 'instagram':
                return Gcrev_Meta_Client::post_to_instagram($user_id, $body, $media_url);
            case 'threads':
                return Gcrev_Meta_Client::post_to_threads($user_id, $body, $media_url);
            case 'line':
                return Gcrev_LINE_Client::broadcast($user_id, $body, $media_url);
            default:
                return ['success' => false, 'platform_post_id' => '', 'message' => "未対応プラットフォーム: {$platform}"];
        }
    }

    /**
     * 媒体別の文字数制限を適用
     */
    private static function apply_length_limit(string $platform, string $body): string {
        switch ( $platform ) {
            case 'facebook':  return mb_substr($body, 0, 8000);
            case 'instagram': return mb_substr($body, 0, 2200);
            case 'threads':   return mb_substr($body, 0, 500);
            case 'line':      return mb_substr($body, 0, 4900);
            default:          return $body;
        }
    }

    /**
     * 接続済みプラットフォーム一覧を返す
     */
    public static function get_connected_platforms(int $user_id): array {
        $meta = Gcrev_Meta_Client::get_connection_status($user_id);
        $line = Gcrev_LINE_Client::get_connection_status($user_id);

        return [
            'facebook'  => $meta['connected'] && $meta['fb_page_id'] !== '',
            'instagram' => $meta['connected'] && $meta['ig_user_id'] !== '',
            'threads'   => $meta['connected'] && $meta['threads_user_id'] !== '',
            'line'      => $line['connected'],
        ];
    }

    /**
     * 予約投稿のうち実行時刻を過ぎたものを処理
     *
     * @return array ['processed'=>int, 'errors'=>int]
     */
    public static function process_overdue_scheduled(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_social_posts';

        $now = current_time('mysql');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s AND scheduled_at IS NOT NULL AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT 20",
                'scheduled', $now
            ),
            ARRAY_A
        );

        $processed = 0;
        $errors    = 0;

        if ( empty($rows) ) {
            return ['processed' => 0, 'errors' => 0];
        }

        foreach ( $rows as $row ) {
            $result = self::execute($row);
            $update = [
                'status'           => $result['status'],
                'platform_results' => wp_json_encode($result['platform_results'], JSON_UNESCAPED_UNICODE),
                'error_message'    => $result['error_message'],
                'updated_at'       => current_time('mysql'),
            ];
            if ( $result['status'] === 'posted' || $result['status'] === 'partial' ) {
                $update['posted_at'] = current_time('mysql');
            }
            if ( $result['status'] === 'failed' ) {
                $update['retry_count'] = (int) ($row['retry_count'] ?? 0) + 1;
            }
            $wpdb->update($table, $update, ['id' => (int) $row['id']]);

            if ( $result['status'] === 'failed' ) {
                $errors++;
            } else {
                $processed++;
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * JSON カラムを配列化（壊れていれば空配列）
     */
    private static function decode_json($json): array {
        if ( is_array($json) ) { return $json; }
        if ( ! is_string($json) || $json === '' ) { return []; }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
