<?php
/**
 * Mimamori_Updates_API
 *
 * 更新情報（リリースノート）REST API
 * - 一覧取得・未読件数・既読管理・ingest（GitHub Actions用）
 *
 * @package GCREV_INSIGHT
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( class_exists( 'Mimamori_Updates_API' ) ) {
    return;
}

class Mimamori_Updates_API {

    /** CPT スラッグ */
    private const POST_TYPE = 'mimamori_update';

    /** user_meta キー: 既読タイムスタンプ */
    private const META_LAST_SEEN = 'mimamori_updates_last_seen';

    /** 許可するカテゴリ値 */
    private const VALID_CATEGORIES = [ 'feature', 'improvement', 'fix', 'other' ];

    /**
     * フック登録
     */
    public function register(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * REST ルート登録
     */
    public function register_routes(): void {

        // GET /mimamori/v1/updates — 一覧取得
        register_rest_route( 'mimamori/v1', '/updates', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_updates' ],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
            'args' => [
                'per_page' => [
                    'required'          => false,
                    'default'           => 8,
                    'validate_callback' => function ( $p ) {
                        return is_numeric( $p ) && (int) $p >= 1 && (int) $p <= 20;
                    },
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // GET /mimamori/v1/updates/unread-count — 未読件数
        register_rest_route( 'mimamori/v1', '/updates/unread-count', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_unread_count' ],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ] );

        // POST /mimamori/v1/updates/mark-read — 既読更新
        register_rest_route( 'mimamori/v1', '/updates/mark-read', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'mark_read' ],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ] );

        // POST /mimamori/v1/updates/ingest — GitHub Actions 自動登録
        register_rest_route( 'mimamori/v1', '/updates/ingest', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'ingest_update' ],
            'permission_callback' => [ $this, 'check_ingest_token' ],
        ] );
    }

    /* ================================================================
     * Permission: ingest トークン検証
     * ================================================================ */

    /**
     * X-Mimamori-Ingest-Token ヘッダと wp-config 定数を比較
     */
    public function check_ingest_token(): bool {
        if ( ! defined( 'MIMAMORI_UPDATES_INGEST_TOKEN' ) || MIMAMORI_UPDATES_INGEST_TOKEN === '' ) {
            return false;
        }

        $header = isset( $_SERVER['HTTP_X_MIMAMORI_INGEST_TOKEN'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MIMAMORI_INGEST_TOKEN'] ) )
            : '';

        return hash_equals( (string) MIMAMORI_UPDATES_INGEST_TOKEN, $header );
    }

    /* ================================================================
     * GET /updates — 一覧取得
     * ================================================================ */

    public function get_updates( \WP_REST_Request $request ): \WP_REST_Response {
        $per_page  = absint( $request->get_param( 'per_page' ) );
        $user_id   = get_current_user_id();
        $last_seen = (int) get_user_meta( $user_id, self::META_LAST_SEEN, true );

        $posts = get_posts( [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $items = [];
        foreach ( $posts as $p ) {
            $ts = (int) get_post_time( 'U', true, $p );
            $items[] = [
                'id'        => $p->ID,
                'title'     => $p->post_title,
                'excerpt'   => wp_trim_words( wp_strip_all_tags( $p->post_content ), 40, '…' ),
                'category'  => get_post_meta( $p->ID, 'update_category', true ) ?: 'other',
                'version'   => get_post_meta( $p->ID, 'update_version', true ) ?: '',
                'date'      => get_the_date( 'Y-m-d', $p ),
                'timestamp' => $ts,
                'is_unread' => $ts > $last_seen,
            ];
        }

        return new \WP_REST_Response( [
            'items'     => $items,
            'last_seen' => $last_seen,
        ], 200 );
    }

    /* ================================================================
     * GET /updates/unread-count — 未読件数
     * ================================================================ */

    public function get_unread_count(): \WP_REST_Response {
        $user_id   = get_current_user_id();
        $last_seen = (int) get_user_meta( $user_id, self::META_LAST_SEEN, true );

        if ( $last_seen <= 0 ) {
            // 一度も見ていない → 全件が未読
            $query = new \WP_Query( [
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => -1,
            ] );
        } else {
            $query = new \WP_Query( [
                'post_type'      => self::POST_TYPE,
                'post_status'    => 'publish',
                'date_query'     => [
                    [
                        'after'     => gmdate( 'Y-m-d H:i:s', $last_seen ),
                        'inclusive' => false,
                    ],
                ],
                'fields'         => 'ids',
                'posts_per_page' => -1,
            ] );
        }

        return new \WP_REST_Response( [
            'unread_count' => $query->found_posts,
        ], 200 );
    }

    /* ================================================================
     * POST /updates/mark-read — 既読更新
     * ================================================================ */

    public function mark_read(): \WP_REST_Response {
        $user_id = get_current_user_id();
        $now     = time();
        update_user_meta( $user_id, self::META_LAST_SEEN, $now );

        return new \WP_REST_Response( [ 'marked_at' => $now ], 200 );
    }

    /* ================================================================
     * POST /updates/ingest — GitHub Actions 自動登録
     * ================================================================ */

    public function ingest_update( \WP_REST_Request $request ): \WP_REST_Response {
        $body     = $request->get_json_params();
        $title    = isset( $body['title'] )    ? sanitize_text_field( $body['title'] )    : '';
        $content  = isset( $body['content'] )  ? wp_kses_post( $body['content'] )         : '';
        $category = isset( $body['category'] ) ? sanitize_text_field( $body['category'] ) : 'improvement';
        $version  = isset( $body['version'] )  ? sanitize_text_field( $body['version'] )  : '';

        if ( $title === '' ) {
            return new \WP_REST_Response( [ 'error' => 'title is required' ], 400 );
        }

        // カテゴリのバリデーション
        if ( ! in_array( $category, self::VALID_CATEGORIES, true ) ) {
            $category = 'other';
        }

        $post_id = wp_insert_post( [
            'post_type'    => self::POST_TYPE,
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return new \WP_REST_Response( [
                'error' => $post_id->get_error_message(),
            ], 500 );
        }

        update_post_meta( $post_id, 'update_category', $category );
        if ( $version !== '' ) {
            update_post_meta( $post_id, 'update_version', $version );
        }

        return new \WP_REST_Response( [
            'post_id'  => $post_id,
            'title'    => $title,
            'category' => $category,
        ], 201 );
    }
}
