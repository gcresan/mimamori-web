<?php
/**
 * Gcrev_MEO_Diagnostic_Service
 *
 * GBPプロフィール診断ロジック:
 *   1. GBP API / DB からデータ収集
 *   2. カテゴリ別スコアリング（基本情報/投稿/写真/レビュー）
 *   3. Gemini による AI 総評生成
 *   4. CPT (gcrev_report) に保存
 *
 * @package Mimamori_Web
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Gcrev_MEO_Diagnostic_Service' ) ) { return; }

class Gcrev_MEO_Diagnostic_Service {

    // =========================================================
    // 定数 — スコアリング設定（チューニング可能）
    // =========================================================

    /** カテゴリ加重 */
    private const WEIGHTS = [
        'basic_info' => 0.25,
        'posts'      => 0.25,
        'photos'     => 0.20,
        'reviews'    => 0.30,
    ];

    /** グレード変換テーブル */
    private const GRADE_MAP = [
        90 => 'A',
        75 => 'B',
        60 => 'C',
        40 => 'D',
        0  => 'E',
    ];

    private const LOG_FILE = '/tmp/gcrev_meo_diag_debug.log';

    // =========================================================
    // プロパティ
    // =========================================================

    private Gcrev_Config            $config;
    private Gcrev_AI_Client         $ai;
    private Gcrev_Report_Repository $repo;
    private Gcrev_Insight_API       $api;

    // =========================================================
    // コンストラクタ
    // =========================================================

    public function __construct(
        Gcrev_Config            $config,
        Gcrev_AI_Client         $ai,
        Gcrev_Report_Repository $repo,
        Gcrev_Insight_API       $api
    ) {
        $this->config = $config;
        $this->ai     = $ai;
        $this->repo   = $repo;
        $this->api    = $api;
    }

    // =========================================================
    // 公開メソッド
    // =========================================================

    /**
     * 診断を実行し CPT に保存して結果を返す
     */
    public function run_diagnostic( int $user_id ): array {
        $this->log( "run_diagnostic START user_id={$user_id}" );

        // GBP接続チェック
        $location_id = get_user_meta( $user_id, '_gcrev_gbp_location_id', true );
        if ( empty( $location_id ) || strpos( $location_id, 'pending_' ) === 0 ) {
            return [
                'success' => false,
                'message' => 'GBPロケーションが設定されていません。MEOダッシュボードからGoogleアカウントを接続してください。',
            ];
        }

        // --- データ収集 ---
        $business_info = $this->collect_business_info( $user_id );
        $reviews_data  = $this->collect_reviews( $user_id );
        $posts_data    = $this->collect_posts( $user_id );
        $photos_data   = $this->collect_photos( $user_id );
        $rankings_data = $this->collect_rankings( $user_id );
        $aio_data      = $this->collect_aio_scores( $user_id );
        $keywords_data = $this->collect_keywords( $user_id );

        // --- スコアリング ---
        $score_basic   = $this->score_basic_info( $business_info );
        $score_posts   = $this->score_posts( $posts_data, $keywords_data );
        $score_photos  = $this->score_photos( $photos_data );
        $score_reviews = $this->score_reviews( $reviews_data );

        $categories = [
            'basic_info' => $score_basic,
            'posts'      => $score_posts,
            'photos'     => $score_photos,
            'reviews'    => $score_reviews,
        ];

        $overall = $this->compute_overall( $categories );

        // --- レビューサマリー ---
        $review_summary = $this->build_review_summary( $reviews_data );

        // --- 優先キーワード ---
        $priority_keywords = $this->build_priority_keywords( $rankings_data, $keywords_data );

        // --- AIOスコア整形 ---
        $aio_scores = $this->build_aio_scores( $aio_data, $keywords_data );

        // --- AI総評生成 ---
        $ai_commentary = $this->generate_ai_commentary( $categories, $overall, $review_summary, $priority_keywords );

        // --- データ構築 ---
        $diagnostic_data = [
            'report_type'        => 'meo_diagnostic',
            'diagnostic_date'    => current_time( 'Y-m-d' ),
            'overall_score'      => $overall['score'],
            'overall_grade'      => $overall['grade'],
            'categories'         => $categories,
            'summary_text'       => $ai_commentary['summary'] ?? '',
            'good_points'        => $ai_commentary['good_points'] ?? [],
            'improvement_points' => $ai_commentary['improvement_points'] ?? [],
            'recommendations'    => $ai_commentary['recommendations'] ?? [],
            'priority_keywords'  => $priority_keywords,
            'aio_scores'         => $aio_scores,
            'review_summary'     => $review_summary,
            'snapshot'           => [
                'business_info' => $business_info,
                'posts_count'   => count( $posts_data ),
                'photos_count'  => count( $photos_data ),
                'reviews_count' => count( $reviews_data['reviews'] ?? [] ),
            ],
        ];

        // --- CPT保存 ---
        $post_id = $this->save_diagnostic( $user_id, $diagnostic_data );

        $this->log( "run_diagnostic DONE user_id={$user_id} post_id={$post_id} score={$overall['score']} grade={$overall['grade']}" );

        $diagnostic_data['id'] = $post_id;
        return [
            'success' => true,
            'data'    => $diagnostic_data,
        ];
    }

    /**
     * 最新の診断レポートを取得
     */
    public function get_latest( int $user_id ): ?array {
        $posts = $this->query_diagnostic_posts( $user_id, 1 );
        if ( empty( $posts ) ) {
            return null;
        }
        return $this->format_diagnostic_post( $posts[0] );
    }

    /**
     * 診断履歴一覧
     */
    public function get_history( int $user_id, int $limit = 10 ): array {
        $posts  = $this->query_diagnostic_posts( $user_id, $limit );
        $result = [];
        foreach ( $posts as $p ) {
            $result[] = $this->format_diagnostic_post( $p );
        }
        return $result;
    }

    /**
     * 特定の診断レポートを取得（IDベース）
     */
    public function get_by_id( int $post_id, int $user_id ): ?array {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'gcrev_report' ) {
            return null;
        }
        $type = get_post_meta( $post_id, '_gcrev_report_type', true );
        if ( $type !== 'meo_diagnostic' ) {
            return null;
        }
        // 所有権チェック（管理者はスキップ）
        $owner_id = (int) get_post_meta( $post_id, '_gcrev_user_id', true );
        if ( $owner_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
            return null;
        }
        return $this->format_diagnostic_post( $post );
    }

    // =========================================================
    // データ収集（private）
    // =========================================================

    private function collect_business_info( int $user_id ): array {
        $access_token = $this->api->gbp_get_access_token( $user_id );
        if ( empty( $access_token ) ) {
            $this->log( "collect_business_info: no access token user_id={$user_id}" );
            return $this->fallback_business_info( $user_id );
        }

        $account_name = $this->api->gbp_get_account_for_location( $user_id );
        $location_id  = get_user_meta( $user_id, '_gcrev_gbp_location_id', true );
        if ( empty( $account_name ) || empty( $location_id ) ) {
            return $this->fallback_business_info( $user_id );
        }

        $read_mask = implode( ',', [
            'name', 'title', 'categories', 'storefrontAddress', 'websiteUri',
            'regularHours', 'specialHours', 'phoneNumbers', 'profile',
            'openInfo', 'serviceArea', 'metadata', 'latlng',
        ] );

        $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$account_name}/{$location_id}"
             . '?readMask=' . $read_mask;

        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $code = is_wp_error( $response )
                ? $response->get_error_message()
                : wp_remote_retrieve_response_code( $response );
            $this->log( "collect_business_info API ERROR: code={$code}" );
            return $this->fallback_business_info( $user_id );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body ?: $this->fallback_business_info( $user_id );
    }

    /**
     * GBP API にアクセスできない場合のフォールバック（user_meta から構築）
     */
    private function fallback_business_info( int $user_id ): array {
        return [
            'title'            => get_user_meta( $user_id, '_gcrev_gbp_location_name', true ) ?: '',
            'storefrontAddress' => [ 'addressLines' => [ get_user_meta( $user_id, '_gcrev_gbp_location_address', true ) ?: '' ] ],
            '_fallback'        => true,
        ];
    }

    private function collect_reviews( int $user_id ): array {
        $result = $this->api->gbp_fetch_reviews( $user_id );
        if ( ! is_array( $result ) || ( isset( $result['success'] ) && $result['success'] === false ) ) {
            return [ 'reviews' => [], 'averageRating' => 0, 'totalReviewCount' => 0 ];
        }
        return $result;
    }

    private function collect_posts( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_gbp_posts';

        if ( ! $this->table_exists( $table ) ) {
            return [];
        }

        $cutoff = ( new \DateTimeImmutable( '-90 days', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND created_at >= %s ORDER BY created_at DESC",
            $user_id,
            $cutoff
        ), ARRAY_A );

        return $rows ?: [];
    }

    private function collect_photos( int $user_id ): array {
        $access_token = $this->api->gbp_get_access_token( $user_id );
        if ( empty( $access_token ) ) {
            return [];
        }

        $account_name = $this->api->gbp_get_account_for_location( $user_id );
        $location_id  = get_user_meta( $user_id, '_gcrev_gbp_location_id', true );
        if ( empty( $account_name ) || empty( $location_id ) ) {
            return [];
        }

        $url = "https://mybusinessbusinessinformation.googleapis.com/v1/{$account_name}/{$location_id}/media"
             . '?pageSize=100';

        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $code = is_wp_error( $response )
                ? $response->get_error_message()
                : wp_remote_retrieve_response_code( $response );
            $this->log( "collect_photos API ERROR: code={$code}" );
            return [];
        }

        $body  = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['mediaItems'] ?? [];
    }

    private function collect_rankings( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_meo_results';
        if ( ! $this->table_exists( $table ) ) {
            return [];
        }

        // 最新日のデータを取得
        $latest_date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(fetch_date) FROM {$table} WHERE user_id = %d",
            $user_id
        ) );

        if ( ! $latest_date ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND fetch_date = %s",
            $user_id,
            $latest_date
        ), ARRAY_A );

        return $rows ?: [];
    }

    private function collect_aio_scores( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_aio_results';
        if ( ! $this->table_exists( $table ) ) {
            return [];
        }

        // 最新日のデータ（各キーワード・各プロバイダ）
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.* FROM {$table} a
             INNER JOIN (
                 SELECT keyword_id, provider, MAX(fetched_at) AS max_at
                 FROM {$table}
                 WHERE user_id = %d AND status LIKE 'success%%'
                 GROUP BY keyword_id, provider
             ) b ON a.keyword_id = b.keyword_id AND a.provider = b.provider AND a.fetched_at = b.max_at
             WHERE a.user_id = %d",
            $user_id,
            $user_id
        ), ARRAY_A );

        return $rows ?: [];
    }

    private function collect_keywords( int $user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_rank_keywords';
        if ( ! $this->table_exists( $table ) ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND enabled = 1 ORDER BY id ASC",
            $user_id
        ), ARRAY_A );

        return $rows ?: [];
    }

    // =========================================================
    // スコアリング
    // =========================================================

    private function score_basic_info( array $info ): array {
        $items = [];
        $total = 0;
        $max_total = 100;

        // 店舗名 (10)
        $has_title = ! empty( $info['title'] );
        $items[] = $this->make_item( 'business_name', '店舗名', 'ビジネス名が設定されているか', $has_title ? 10 : 0, 10, $has_title ? '設定済み' : '未設定' );
        $total += $has_title ? 10 : 0;

        // メインカテゴリ (10)
        $has_cat = ! empty( $info['categories']['primaryCategory']['displayName'] ?? '' );
        $cat_name = $info['categories']['primaryCategory']['displayName'] ?? '';
        $items[] = $this->make_item( 'primary_category', 'メインカテゴリ', 'サービス内容に適したカテゴリが設定されているか', $has_cat ? 10 : 0, 10, $has_cat ? $cat_name : '未設定' );
        $total += $has_cat ? 10 : 0;

        // 追加カテゴリ (5)
        $add_cats = $info['categories']['additionalCategories'] ?? [];
        $add_count = count( $add_cats );
        $add_score = $add_count >= 2 ? 5 : ( $add_count === 1 ? 3 : 0 );
        $items[] = $this->make_item( 'additional_categories', '追加カテゴリ', '複数カテゴリで発見されやすくしているか', $add_score, 5, $add_count . '個設定' );
        $total += $add_score;

        // ビジネス説明 (10)
        $desc = $info['profile']['description'] ?? '';
        $desc_len = mb_strlen( $desc );
        $desc_score = $desc_len >= 100 ? 10 : ( $desc_len > 0 ? 5 : 0 );
        $items[] = $this->make_item( 'description', 'ビジネス説明', 'ビジネスの説明が十分に記述されているか', $desc_score, 10, $desc_len > 0 ? $desc_len . '文字' : '未設定' );
        $total += $desc_score;

        // 住所 (10)
        $addr = $info['storefrontAddress'] ?? [];
        $has_addr = ! empty( $addr['addressLines'][0] ?? '' ) || ! empty( $addr['locality'] ?? '' );
        $items[] = $this->make_item( 'address', '住所', '住所が正しく設定されているか', $has_addr ? 10 : 0, 10, $has_addr ? '設定済み' : '未設定' );
        $total += $has_addr ? 10 : 0;

        // 電話番号 (10)
        $phones = $info['phoneNumbers'] ?? [];
        $has_phone = ! empty( $phones['primaryPhone'] ?? '' );
        $items[] = $this->make_item( 'phone', '電話番号', '電話番号が設定されているか', $has_phone ? 10 : 0, 10, $has_phone ? '設定済み' : '未設定' );
        $total += $has_phone ? 10 : 0;

        // 営業時間 (10)
        $hours = $info['regularHours'] ?? [];
        $has_hours = ! empty( $hours['periods'] ?? [] );
        $items[] = $this->make_item( 'business_hours', '営業時間設定', '営業時間が設定されているか', $has_hours ? 10 : 0, 10, $has_hours ? '設定済み' : '未設定' );
        $total += $has_hours ? 10 : 0;

        // WebサイトURL (10)
        $has_url = ! empty( $info['websiteUri'] ?? '' );
        $items[] = $this->make_item( 'website_url', 'WebサイトURL', 'ウェブサイトURLが設定されているか', $has_url ? 10 : 0, 10, $has_url ? '設定済み' : '未設定' );
        $total += $has_url ? 10 : 0;

        // 開業日 (5)
        $has_open = ! empty( $info['openInfo']['openingDate'] ?? [] );
        $items[] = $this->make_item( 'opening_date', '開業日', '開業日が設定されているか', $has_open ? 5 : 0, 5, $has_open ? '設定済み' : '未設定' );
        $total += $has_open ? 5 : 0;

        // サービス提供地域 (5)
        $has_area = ! empty( $info['serviceArea'] ?? [] );
        $items[] = $this->make_item( 'service_area', 'サービス提供地域', 'サービス提供地域が設定されているか', $has_area ? 5 : 0, 5, $has_area ? '設定済み' : '未設定' );
        $total += $has_area ? 5 : 0;

        // 属性 (15)  — フォールバック時はスキップ
        if ( empty( $info['_fallback'] ) ) {
            $attr_count = count( $info['attributes'] ?? [] );
            $attr_score = $attr_count >= 5 ? 15 : ( $attr_count >= 3 ? 10 : ( $attr_count >= 1 ? 5 : 0 ) );
            $items[] = $this->make_item( 'attributes', '属性', 'Wi-Fi、駐車場などの属性情報が設定されているか', $attr_score, 15, $attr_count . '個設定' );
            $total += $attr_score;
        } else {
            $max_total -= 15; // フォールバック時は属性を除外
        }

        $score = $max_total > 0 ? (int) round( ( $total / $max_total ) * 100 ) : 0;

        return [
            'score'  => $score,
            'grade'  => $this->score_to_grade( $score ),
            'weight' => self::WEIGHTS['basic_info'],
            'items'  => $items,
        ];
    }

    private function score_posts( array $posts, array $keywords ): array {
        $items = [];
        $total = 0;

        $post_count = count( $posts );
        $posted = array_filter( $posts, fn( $p ) => ( $p['status'] ?? '' ) === 'posted' );
        $posted_count = count( $posted );

        // 投稿数(90日) (25)
        if ( $posted_count >= 12 )      $s = 25;
        elseif ( $posted_count >= 8 )   $s = 20;
        elseif ( $posted_count >= 4 )   $s = 15;
        elseif ( $posted_count >= 1 )   $s = 5;
        else                            $s = 0;
        $items[] = $this->make_item( 'post_count', '投稿数（90日間）', '過去90日間の投稿数が十分か', $s, 25, $posted_count . '件' );
        $total += $s;

        // 投稿頻度 (20)
        if ( $posted_count >= 12 )      $s = 20;
        elseif ( $posted_count >= 6 )   $s = 10;
        elseif ( $posted_count >= 3 )   $s = 5;
        else                            $s = 0;
        $freq_label = $posted_count >= 12 ? '週1以上' : ( $posted_count >= 6 ? '隔週' : ( $posted_count >= 3 ? '月1程度' : '不足' ) );
        $items[] = $this->make_item( 'post_frequency', '投稿頻度', '定期的に投稿されているか', $s, 20, $freq_label );
        $total += $s;

        // 最終投稿日 (20)
        $latest_posted_at = null;
        foreach ( $posted as $p ) {
            $at = $p['posted_at'] ?? $p['created_at'] ?? '';
            if ( $at && ( ! $latest_posted_at || $at > $latest_posted_at ) ) {
                $latest_posted_at = $at;
            }
        }
        $days_since = $latest_posted_at
            ? (int) ( ( time() - strtotime( $latest_posted_at ) ) / 86400 )
            : 999;
        if ( $days_since <= 7 )       $s = 20;
        elseif ( $days_since <= 14 )  $s = 15;
        elseif ( $days_since <= 30 )  $s = 10;
        elseif ( $days_since <= 60 )  $s = 5;
        else                          $s = 0;
        $recency_label = $latest_posted_at ? $days_since . '日前' : '投稿なし';
        $items[] = $this->make_item( 'post_recency', '最終投稿日', '直近の投稿があるか', $s, 20, $recency_label );
        $total += $s;

        // 画像付き率 (20)
        $with_image = 0;
        foreach ( $posted as $p ) {
            if ( ! empty( $p['image_url'] ) || ! empty( $p['image_attachment_id'] ) ) {
                $with_image++;
            }
        }
        $img_rate = $posted_count > 0 ? $with_image / $posted_count : 0;
        $s = (int) round( $img_rate * 20 );
        $items[] = $this->make_item( 'post_images', '画像付き投稿', '投稿に画像が含まれているか', $s, 20, round( $img_rate * 100 ) . '%' );
        $total += $s;

        // キーワード使用 (15)
        $kw_used = 0;
        $kw_list = array_map( fn( $k ) => mb_strtolower( $k['keyword'] ?? '' ), $keywords );
        foreach ( $posted as $p ) {
            $text = mb_strtolower( ( $p['title'] ?? '' ) . ' ' . ( $p['summary'] ?? '' ) );
            foreach ( $kw_list as $kw ) {
                if ( $kw !== '' && mb_strpos( $text, $kw ) !== false ) {
                    $kw_used++;
                    break;
                }
            }
        }
        $kw_rate = $posted_count > 0 ? $kw_used / $posted_count : 0;
        $s = (int) round( $kw_rate * 15 );
        $items[] = $this->make_item( 'post_keywords', 'キーワード使用', '投稿にトラッキングキーワードが含まれているか', $s, 15, $kw_used . '件の投稿で使用' );
        $total += $s;

        return [
            'score'  => $total,
            'grade'  => $this->score_to_grade( $total ),
            'weight' => self::WEIGHTS['posts'],
            'items'  => $items,
        ];
    }

    private function score_photos( array $photos ): array {
        $items = [];
        $total = 0;

        $photo_count = count( $photos );

        // 総枚数 (25)
        if ( $photo_count >= 10 )      $s = 25;
        elseif ( $photo_count >= 5 )   $s = 15;
        elseif ( $photo_count >= 1 )   $s = 5;
        else                           $s = 0;
        $items[] = $this->make_item( 'photo_count', '写真枚数', '十分な写真が登録されているか', $s, 25, $photo_count . '枚' );
        $total += $s;

        // カテゴリ分類
        $categories_found = [];
        $has_logo  = false;
        $has_cover = false;
        $newest_create = null;

        foreach ( $photos as $photo ) {
            $cat = $photo['locationAssociation']['category'] ?? $photo['mediaFormat'] ?? '';
            if ( $cat === 'LOGO' ) $has_logo = true;
            if ( $cat === 'COVER' ) $has_cover = true;
            if ( in_array( $cat, [ 'EXTERIOR', 'INTERIOR', 'PRODUCT', 'AT_WORK', 'FOOD_AND_DRINK' ], true ) ) {
                $categories_found[ $cat ] = true;
            }
            $created = $photo['createTime'] ?? '';
            if ( $created && ( ! $newest_create || $created > $newest_create ) ) {
                $newest_create = $created;
            }
        }

        // ロゴ (15)
        $items[] = $this->make_item( 'photo_logo', 'ロゴ写真', 'ロゴ写真が設定されているか', $has_logo ? 15 : 0, 15, $has_logo ? '設定済み' : '未設定' );
        $total += $has_logo ? 15 : 0;

        // カバー (15)
        $items[] = $this->make_item( 'photo_cover', 'カバー写真', 'カバー写真が設定されているか', $has_cover ? 15 : 0, 15, $has_cover ? '設定済み' : '未設定' );
        $total += $has_cover ? 15 : 0;

        // カテゴリ多様性 (25)
        $diversity = count( $categories_found );
        $div_score = min( $diversity * 5, 25 );
        $items[] = $this->make_item( 'photo_diversity', 'カテゴリ多様性', '外観・内観・商品・スタッフなど多様な写真があるか', $div_score, 25, $diversity . 'カテゴリ' );
        $total += $div_score;

        // 直近追加 (20)
        if ( $newest_create ) {
            $days_since = (int) ( ( time() - strtotime( $newest_create ) ) / 86400 );
        } else {
            $days_since = 999;
        }
        if ( $days_since <= 30 )       $s = 20;
        elseif ( $days_since <= 90 )   $s = 10;
        elseif ( $days_since <= 180 )  $s = 5;
        else                           $s = 0;
        $items[] = $this->make_item( 'photo_recency', '直近の写真追加', '最近写真を追加しているか', $s, 20, $days_since < 999 ? $days_since . '日前' : '未取得' );
        $total += $s;

        return [
            'score'  => $total,
            'grade'  => $this->score_to_grade( $total ),
            'weight' => self::WEIGHTS['photos'],
            'items'  => $items,
        ];
    }

    private function score_reviews( array $reviews_data ): array {
        $items = [];
        $total = 0;

        $reviews_list = $reviews_data['reviews'] ?? [];
        $avg_rating   = (float) ( $reviews_data['averageRating'] ?? 0 );
        $total_count  = (int) ( $reviews_data['totalReviewCount'] ?? count( $reviews_list ) );

        // 平均評価 (25)
        $s = (int) round( ( $avg_rating / 5 ) * 25 );
        $items[] = $this->make_item( 'review_rating', '平均評価', '全体の評価が高いか', $s, 25, $avg_rating > 0 ? number_format( $avg_rating, 1 ) : '未取得' );
        $total += $s;

        // 口コミ数 (20)
        if ( $total_count >= 50 )      $s = 20;
        elseif ( $total_count >= 20 )  $s = 15;
        elseif ( $total_count >= 10 )  $s = 10;
        elseif ( $total_count >= 5 )   $s = 5;
        elseif ( $total_count >= 1 )   $s = 2;
        else                           $s = 0;
        $items[] = $this->make_item( 'review_count', '口コミ件数', '口コミの総件数が十分か', $s, 20, $total_count . '件' );
        $total += $s;

        // 返信率 (25)
        $replied = 0;
        foreach ( $reviews_list as $r ) {
            if ( ! empty( $r['reviewReply'] ) ) {
                $replied++;
            }
        }
        $reply_rate = $total_count > 0 ? $replied / $total_count : 0;
        $s = (int) round( $reply_rate * 25 );
        $items[] = $this->make_item( 'review_reply_rate', '口コミ返信率', '口コミに返信しているか', $s, 25, round( $reply_rate * 100 ) . '%' );
        $total += $s;

        // 返信速度 (15) — 簡易判定
        $quick_replies = 0;
        foreach ( $reviews_list as $r ) {
            if ( ! empty( $r['reviewReply'] ) ) {
                $review_time = strtotime( $r['createTime'] ?? '' );
                $reply_time  = strtotime( $r['reviewReply']['updateTime'] ?? '' );
                if ( $review_time && $reply_time && ( $reply_time - $review_time ) < 259200 ) { // 3日以内
                    $quick_replies++;
                }
            }
        }
        $quick_rate = $replied > 0 ? $quick_replies / $replied : 0;
        $s = (int) round( $quick_rate * 15 );
        $items[] = $this->make_item( 'review_reply_speed', '返信速度', '口コミに迅速に返信しているか（3日以内）', $s, 15, round( $quick_rate * 100 ) . '%が3日以内' );
        $total += $s;

        // 直近口コミ (15)
        $latest_review_time = null;
        foreach ( $reviews_list as $r ) {
            $t = $r['createTime'] ?? '';
            if ( $t && ( ! $latest_review_time || $t > $latest_review_time ) ) {
                $latest_review_time = $t;
            }
        }
        $days_since = $latest_review_time
            ? (int) ( ( time() - strtotime( $latest_review_time ) ) / 86400 )
            : 999;
        if ( $days_since <= 30 )       $s = 15;
        elseif ( $days_since <= 60 )   $s = 10;
        elseif ( $days_since <= 90 )   $s = 5;
        else                           $s = 0;
        $items[] = $this->make_item( 'review_recency', '直近の口コミ', '最近口コミが投稿されているか', $s, 15, $days_since < 999 ? $days_since . '日前' : '口コミなし' );
        $total += $s;

        return [
            'score'  => $total,
            'grade'  => $this->score_to_grade( $total ),
            'weight' => self::WEIGHTS['reviews'],
            'items'  => $items,
        ];
    }

    private function compute_overall( array $categories ): array {
        $weighted_sum = 0;
        foreach ( $categories as $key => $cat ) {
            $weighted_sum += $cat['score'] * ( self::WEIGHTS[ $key ] ?? 0 );
        }
        $score = (int) round( $weighted_sum );

        return [
            'score' => $score,
            'grade' => $this->score_to_grade( $score ),
        ];
    }

    // =========================================================
    // レビューサマリー・キーワード・AIO整形
    // =========================================================

    private function build_review_summary( array $reviews_data ): array {
        $reviews = $reviews_data['reviews'] ?? [];
        $avg     = (float) ( $reviews_data['averageRating'] ?? 0 );
        $total   = (int) ( $reviews_data['totalReviewCount'] ?? count( $reviews ) );

        $dist = [ '5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0 ];
        foreach ( $reviews as $r ) {
            $star = $r['starRating'] ?? '';
            $map  = [ 'FIVE' => '5', 'FOUR' => '4', 'THREE' => '3', 'TWO' => '2', 'ONE' => '1' ];
            $key  = $map[ $star ] ?? '';
            if ( $key !== '' ) {
                $dist[ $key ]++;
            }
        }

        $replied = 0;
        foreach ( $reviews as $r ) {
            if ( ! empty( $r['reviewReply'] ) ) {
                $replied++;
            }
        }

        return [
            'average_rating' => $avg,
            'total'          => $total,
            'distribution'   => $dist,
            'reply_rate'     => $total > 0 ? round( ( $replied / $total ) * 100, 1 ) : 0,
        ];
    }

    private function build_priority_keywords( array $rankings, array $keywords ): array {
        $kw_map = [];
        foreach ( $keywords as $kw ) {
            $kw_map[ (int) $kw['id'] ] = $kw['keyword'] ?? '';
        }

        $result = [];
        $seen   = [];
        foreach ( $rankings as $r ) {
            $kw_id = (int) ( $r['keyword_id'] ?? 0 );
            if ( isset( $seen[ $kw_id ] ) || ! isset( $kw_map[ $kw_id ] ) ) {
                continue;
            }
            $seen[ $kw_id ] = true;
            $result[] = [
                'keyword'     => $kw_map[ $kw_id ],
                'device'      => $r['device'] ?? 'mobile',
                'maps_rank'   => $r['maps_rank'] !== null ? (int) $r['maps_rank'] : null,
                'finder_rank' => $r['finder_rank'] !== null ? (int) $r['finder_rank'] : null,
            ];
        }

        // ランキングデータがないキーワードも追加
        foreach ( $kw_map as $id => $kw ) {
            if ( ! isset( $seen[ $id ] ) ) {
                $result[] = [
                    'keyword'     => $kw,
                    'device'      => 'mobile',
                    'maps_rank'   => null,
                    'finder_rank' => null,
                ];
            }
        }

        return $result;
    }

    private function build_aio_scores( array $aio_data, array $keywords ): array {
        $kw_map = [];
        foreach ( $keywords as $kw ) {
            $kw_map[ (int) $kw['id'] ] = $kw['keyword'] ?? '';
        }

        // キーワード × プロバイダ でグルーピング
        $grouped = [];
        foreach ( $aio_data as $row ) {
            $kw_id    = (int) ( $row['keyword_id'] ?? 0 );
            $provider = $row['provider'] ?? '';
            $kw_name  = $kw_map[ $kw_id ] ?? '';
            if ( $kw_name === '' ) continue;

            if ( ! isset( $grouped[ $kw_name ] ) ) {
                $grouped[ $kw_name ] = [ 'keyword' => $kw_name ];
            }
            // 同じキーワード×プロバイダの全質問のスコア平均
            if ( ! isset( $grouped[ $kw_name ][ $provider . '_scores' ] ) ) {
                $grouped[ $kw_name ][ $provider . '_scores' ] = [];
            }
            $grouped[ $kw_name ][ $provider . '_scores' ][] = (float) ( $row['self_score'] ?? 0 );
        }

        $result = [];
        foreach ( $grouped as $g ) {
            $entry = [ 'keyword' => $g['keyword'] ];
            foreach ( [ 'chatgpt', 'gemini', 'google_ai' ] as $prov ) {
                $scores = $g[ $prov . '_scores' ] ?? [];
                $entry[ $prov ] = count( $scores ) > 0
                    ? (int) round( ( array_sum( $scores ) / count( $scores ) ) * 100 )
                    : null;
            }
            $result[] = $entry;
        }

        return $result;
    }

    // =========================================================
    // AI総評生成
    // =========================================================

    private function generate_ai_commentary( array $categories, array $overall, array $review_summary, array $priority_kw ): array {
        // 問題項目を収集
        $issues = [];
        foreach ( $categories as $cat_key => $cat ) {
            foreach ( $cat['items'] as $item ) {
                if ( $item['score'] < $item['max'] * 0.5 ) {
                    $issues[] = $item['label'] . '（' . $item['detail'] . '）';
                }
            }
        }

        $kw_summary = '';
        foreach ( array_slice( $priority_kw, 0, 5 ) as $kw ) {
            $rank_str = $kw['maps_rank'] !== null ? $kw['maps_rank'] . '位' : '圏外/未取得';
            $kw_summary .= "- {$kw['keyword']}: マップ順位 {$rank_str}\n";
        }

        $cat_labels = [
            'basic_info' => '基本情報',
            'posts'      => '投稿',
            'photos'     => '写真',
            'reviews'    => 'レビュー',
        ];

        $cat_text = '';
        foreach ( $categories as $key => $cat ) {
            $cat_text .= "- {$cat_labels[$key]}: {$cat['score']}点/{$cat['grade']}\n";
        }

        $prompt = <<<PROMPT
あなたはMEO（Googleビジネスプロフィール最適化）の専門コンサルタントです。
以下のGBPプロフィール診断結果をもとに、クライアント向けの分かりやすい総合評価を作成してください。

## 診断結果
総合スコア: {$overall['score']}点 / グレード: {$overall['grade']}

## カテゴリ別スコア
{$cat_text}

## 主な課題
{$issues_text}

## 口コミ状況
平均評価: {$review_summary['average_rating']}
口コミ数: {$review_summary['total']}件
返信率: {$review_summary['reply_rate']}%

## キーワード順位
{$kw_summary}

## 出力形式
以下のJSON形式で出力してください。他のテキストは不要です。
```json
{
  "summary": "3〜5文の総合評価テキスト。現状の評価、主な強み、改善が必要な点を含む。",
  "good_points": ["良い点1", "良い点2", "良い点3"],
  "improvement_points": ["改善点1", "改善点2", "改善点3"],
  "recommendations": [
    {"priority": "high", "title": "最優先の改善項目", "description": "具体的な改善手順を2-3文で説明"},
    {"priority": "medium", "title": "次に改善すべき項目", "description": "具体的な改善手順を2-3文で説明"},
    {"priority": "low", "title": "将来的に改善する項目", "description": "具体的な改善手順を2-3文で説明"}
  ]
}
```

注意事項:
- 専門用語を避け、分かりやすい日本語で書く
- ダメ出しだけでなく、良い点も必ず含める
- 改善の優先順位を明確にする
- 具体的な次のアクションが分かるように書く
PROMPT;

        // issues_text を組み立て
        $issues_text = empty( $issues ) ? '特になし' : implode( "\n", array_map( fn( $i ) => "- {$i}", $issues ) );
        $prompt = str_replace( '{$issues_text}', $issues_text, $prompt );

        try {
            $response = $this->ai->call_gemini_api( $prompt, [
                'temperature'      => 0.3,
                'maxOutputTokens'  => 2048,
            ] );

            // JSON抽出
            $text = is_array( $response ) ? ( $response['text'] ?? '' ) : (string) $response;
            // コードブロック除去
            $text = preg_replace( '/```json\s*/i', '', $text );
            $text = preg_replace( '/```\s*$/', '', $text );
            $text = trim( $text );

            $parsed = json_decode( $text, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
                return $parsed;
            }

            $this->log( 'AI commentary JSON parse failed: ' . substr( $text, 0, 300 ) );
        } catch ( \Exception $e ) {
            $this->log( 'AI commentary error: ' . $e->getMessage() );
        }

        // フォールバック
        return $this->fallback_commentary( $categories, $overall );
    }

    /**
     * AI生成失敗時のルールベースフォールバック
     */
    private function fallback_commentary( array $categories, array $overall ): array {
        $good  = [];
        $bad   = [];

        foreach ( $categories as $key => $cat ) {
            $label = [ 'basic_info' => '基本情報', 'posts' => '投稿', 'photos' => '写真', 'reviews' => 'レビュー' ][ $key ] ?? $key;
            if ( $cat['score'] >= 75 ) {
                $good[] = "{$label}のスコアが{$cat['score']}点と良好です。";
            }
            if ( $cat['score'] < 50 ) {
                $bad[] = "{$label}（{$cat['score']}点）の改善が優先です。";
            }
        }

        return [
            'summary'            => "GBPプロフィールの総合スコアは{$overall['score']}点（{$overall['grade']}ランク）です。" . ( empty( $bad ) ? '全体的に良好な状態です。' : '一部改善が必要な項目があります。' ),
            'good_points'        => $good ?: [ '診断を実行しました。' ],
            'improvement_points' => $bad ?: [ '現時点で大きな課題は見つかりませんでした。' ],
            'recommendations'    => [],
        ];
    }

    // =========================================================
    // 保存・読み込み
    // =========================================================

    private function save_diagnostic( int $user_id, array $data ): int {
        $tz  = wp_timezone();
        $now = new \DateTimeImmutable( 'now', $tz );

        $display_name = get_userdata( $user_id )->display_name ?? '';
        $post_data = [
            'post_type'   => 'gcrev_report',
            'post_title'  => sprintf( '%s %s様 MEO診断レポート', $data['diagnostic_date'], $display_name ),
            'post_status' => 'publish',
            'post_author' => $user_id,
        ];

        $post_id = wp_insert_post( $post_data );
        if ( is_wp_error( $post_id ) || $post_id === 0 ) {
            $this->log( "save_diagnostic FAILED user_id={$user_id}" );
            return 0;
        }

        update_post_meta( $post_id, '_gcrev_user_id', $user_id );
        update_post_meta( $post_id, '_gcrev_report_type', 'meo_diagnostic' );
        update_post_meta( $post_id, '_gcrev_diagnostic_json', wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $post_id, '_gcrev_overall_score', $data['overall_score'] );
        update_post_meta( $post_id, '_gcrev_overall_grade', $data['overall_grade'] );
        update_post_meta( $post_id, '_gcrev_created_at', $now->format( 'Y-m-d H:i:s' ) );

        return $post_id;
    }

    private function query_diagnostic_posts( int $user_id, int $limit ): array {
        return get_posts( [
            'post_type'      => 'gcrev_report',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                [ 'key' => '_gcrev_user_id', 'value' => $user_id, 'type' => 'NUMERIC' ],
                [ 'key' => '_gcrev_report_type', 'value' => 'meo_diagnostic' ],
            ],
        ] );
    }

    private function format_diagnostic_post( \WP_Post $post ): array {
        $json_str = get_post_meta( $post->ID, '_gcrev_diagnostic_json', true );
        $data     = json_decode( $json_str ?: '{}', true ) ?: [];

        $data['id']         = $post->ID;
        $data['created_at'] = get_post_meta( $post->ID, '_gcrev_created_at', true ) ?: $post->post_date;

        return $data;
    }

    // =========================================================
    // ヘルパー
    // =========================================================

    private function make_item( string $key, string $label, string $description, int $score, int $max, string $detail ): array {
        if ( $score >= $max ) {
            $status = 'ok';
        } elseif ( $score >= $max * 0.5 ) {
            $status = 'warning';
        } elseif ( $score > 0 ) {
            $status = 'caution';
        } else {
            $status = 'critical';
        }

        return [
            'key'         => $key,
            'label'       => $label,
            'description' => $description,
            'status'      => $status,
            'score'       => $score,
            'max'         => $max,
            'detail'      => $detail,
        ];
    }

    private function score_to_grade( int $score ): string {
        foreach ( self::GRADE_MAP as $threshold => $grade ) {
            if ( $score >= $threshold ) {
                return $grade;
            }
        }
        return 'E';
    }

    private function table_exists( string $table ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    private function log( string $message ): void {
        file_put_contents(
            self::LOG_FILE,
            date( 'Y-m-d H:i:s' ) . ' [MEO_DIAG] ' . $message . "\n",
            FILE_APPEND
        );
    }
}
