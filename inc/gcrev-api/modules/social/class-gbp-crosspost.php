<?php
// FILE: inc/gcrev-api/modules/social/class-gbp-crosspost.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Gbp_Crosspost') ) { return; }

/**
 * Gcrev_Gbp_Crosspost
 *
 * GBP 投稿に紐付く Facebook / Instagram 同時投稿の発火ヘルパー。
 *
 * 仕様:
 *   - GBP 投稿が成功した直後に呼ばれる（即時投稿 / 予約投稿 Cron どちらからも）
 *   - 利用者が GBP 投稿モーダルで明示的にチェックを入れた場合のみ発火
 *     （crosspost_facebook = 1 / crosspost_instagram = 1）
 *   - Instagram は画像必須（画像が無い場合は instagram プラットフォームを除外）
 *   - 既存の Gcrev_Social_Poster を経由するため、結果は gcrev_social_posts.platform_results
 *     に JSON として残る
 *
 * Meta App Review における説明:
 *   "The app never publishes to Facebook or Instagram without the user's explicit
 *    selection (checkbox) at the time of creating their Google Business Profile post."
 *
 * @package Mimamori_Web
 * @since   2.3.0
 */
class Gcrev_Gbp_Crosspost {

    /**
     * GBP 投稿レコードを受け取り、必要なら同時投稿を実行する。
     *
     * @param array $gbp_post  gcrev_gbp_posts の 1 行（連想配列）
     * @return array {
     *   triggered: bool,                // 同時投稿の対象かどうか
     *   social_post_id: int,            // 紐付けた gcrev_social_posts.id（0 は未作成）
     *   platforms: string[],            // 実行対象プラットフォーム（image 不足で除外されたものは含まない）
     *   skipped: array<string,string>,  // 除外プラットフォームと理由 ['instagram' => '画像が...']
     *   results: array,                 // Gcrev_Social_Poster::execute() の platform_results
     *   status: string,                 // 'posted' | 'partial' | 'failed' | 'not_triggered' | 'no_target'
     *   message: string,                // 人間向けサマリー
     * }
     */
    public static function trigger( array $gbp_post ): array {
        $base = [
            'triggered'      => false,
            'social_post_id' => 0,
            'platforms'      => [],
            'skipped'        => [],
            'results'        => [],
            'status'         => 'not_triggered',
            'message'        => '',
        ];

        $user_id     = (int) ( $gbp_post['user_id'] ?? 0 );
        $want_fb     = ! empty( $gbp_post['crosspost_facebook'] );
        $want_ig     = ! empty( $gbp_post['crosspost_instagram'] );
        $body        = (string) ( $gbp_post['summary'] ?? '' );
        $image_url   = (string) ( $gbp_post['image_url'] ?? '' );

        if ( $user_id <= 0 || ( ! $want_fb && ! $want_ig ) ) {
            return $base;
        }
        if ( ! class_exists( 'Gcrev_Social_Poster' ) || ! class_exists( 'Gcrev_Meta_Client' ) ) {
            $base['status']  = 'failed';
            $base['message'] = 'Meta 連携モジュールが読み込まれていません。';
            return $base;
        }

        // 接続状態に応じてプラットフォームを絞る。未接続なら除外。
        $connected = Gcrev_Social_Poster::get_connected_platforms( $user_id );
        $platforms = [];
        $skipped   = [];

        if ( $want_fb ) {
            if ( ! empty( $connected['facebook'] ) ) {
                $platforms[] = 'facebook';
            } else {
                $skipped['facebook'] = 'Facebook ページが未接続のため、同時投稿をスキップしました。';
            }
        }
        if ( $want_ig ) {
            if ( $image_url === '' ) {
                // Instagram は画像必須
                $skipped['instagram'] = 'Instagram は画像が必要なため、同時投稿をスキップしました。';
            } elseif ( empty( $connected['instagram'] ) ) {
                $skipped['instagram'] = 'Instagram ビジネスアカウントが未接続のため、同時投稿をスキップしました。';
            } else {
                $platforms[] = 'instagram';
            }
        }

        if ( empty( $platforms ) ) {
            $base['triggered'] = true;
            $base['skipped']   = $skipped;
            $base['status']    = 'no_target';
            $base['message']   = '同時投稿先がありません。';
            return $base;
        }

        // gcrev_social_posts に「実行待ち」レコードを作成 → 即時 execute → 結果を保存
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_social_posts';
        $now   = current_time( 'mysql' );

        $row = [
            'user_id'            => $user_id,
            'body'               => $body,
            'media_url'          => $image_url ?: null,
            'media_type'         => $image_url !== '' ? 'image' : null,
            'link_url'           => null,
            'platforms'          => wp_json_encode( $platforms, JSON_UNESCAPED_UNICODE ),
            'platform_overrides' => null,
            'platform_results'   => null,
            'status'             => 'posting',
            'scheduled_at'       => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
        $wpdb->insert( $table, $row );
        $social_post_id = (int) $wpdb->insert_id;

        if ( $social_post_id <= 0 ) {
            $base['triggered'] = true;
            $base['skipped']   = $skipped;
            $base['status']    = 'failed';
            $base['message']   = '同時投稿レコードの作成に失敗しました。';
            return $base;
        }

        // execute は社員別ディスパッチ（Gcrev_Social_Poster::execute は status を返す）
        $row['id'] = $social_post_id;
        $exec      = Gcrev_Social_Poster::execute( $row );

        $update = [
            'status'           => $exec['status'],
            'platform_results' => wp_json_encode( $exec['platform_results'], JSON_UNESCAPED_UNICODE ),
            'error_message'    => $exec['error_message'] ?: null,
            'updated_at'       => current_time( 'mysql' ),
        ];
        if ( in_array( $exec['status'], [ 'posted', 'partial' ], true ) ) {
            $update['posted_at'] = current_time( 'mysql' );
        }
        $wpdb->update( $table, $update, [ 'id' => $social_post_id ] );

        // 人間向けサマリー
        $summary_parts = [];
        foreach ( $exec['platform_results'] as $platform => $r ) {
            $label = self::platform_label( $platform );
            $summary_parts[] = $label . '：' . ( ( $r['status'] ?? '' ) === 'success' ? '投稿しました' : '投稿失敗' );
        }
        foreach ( $skipped as $platform => $reason ) {
            $summary_parts[] = self::platform_label( $platform ) . '：' . $reason;
        }

        return [
            'triggered'      => true,
            'social_post_id' => $social_post_id,
            'platforms'      => $platforms,
            'skipped'        => $skipped,
            'results'        => $exec['platform_results'],
            'status'         => $exec['status'],
            'message'        => implode( ' / ', $summary_parts ),
        ];
    }

    /**
     * gcrev_social_posts.id から GBP 投稿用に platform_results を取得し、
     * UI 向けサマリー配列に整形して返す（履歴表示で使う）。
     *
     * @param int $social_post_id
     * @return array<string, array{status:string, message:string, platform_post_id:string}>
     */
    public static function get_results_summary( int $social_post_id ): array {
        if ( $social_post_id <= 0 ) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_social_posts';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT platform_results FROM {$table} WHERE id = %d", $social_post_id
        ), ARRAY_A );
        if ( ! $row || empty( $row['platform_results'] ) ) {
            return [];
        }
        $decoded = json_decode( (string) $row['platform_results'], true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }
        $out = [];
        foreach ( $decoded as $platform => $r ) {
            $out[ $platform ] = [
                'status'           => (string) ( $r['status'] ?? 'unknown' ),
                'message'          => (string) ( $r['message'] ?? '' ),
                'platform_post_id' => (string) ( $r['platform_post_id'] ?? '' ),
            ];
        }
        return $out;
    }

    private static function platform_label( string $platform ): string {
        switch ( $platform ) {
            case 'facebook':  return 'Facebookページ';
            case 'instagram': return 'Instagram';
            case 'threads':   return 'Threads';
            case 'line':      return 'LINE';
            default:          return $platform;
        }
    }
}
