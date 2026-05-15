<?php
// FILE: inc/gcrev-api/modules/social/class-meta-client.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Meta_Client') ) { return; }

/**
 * Gcrev_Meta_Client
 *
 * Meta Graph API クライアント。
 * 1セットの長期アクセストークンで Facebook ページ / Instagram Business / Threads の
 * 投稿を一括処理する。
 *
 * Graph API バージョン: v21.0
 *
 * 必要権限スコープ:
 *   - pages_show_list
 *   - pages_read_engagement
 *   - pages_manage_posts
 *   - instagram_basic
 *   - instagram_content_publish
 *   - business_management
 *   - threads_basic                   (Threads API 用)
 *   - threads_content_publish         (Threads API 用)
 *
 * トークン格納先（user_meta, 暗号化）:
 *   _gcrev_meta_access_token        — long-lived user access token (60日)
 *   _gcrev_meta_token_expires       — UNIX秒
 *   _gcrev_meta_fb_user_id          — Facebook ユーザー ID（データ削除コールバックで照合）
 *   _gcrev_meta_fb_page_id          — Facebook ページ ID
 *   _gcrev_meta_fb_page_token       — ページアクセストークン（暗号化, 長期）
 *   _gcrev_meta_ig_user_id          — Instagram Business User ID
 *   _gcrev_meta_threads_user_id     — Threads User ID
 *   _gcrev_meta_oauth_state         — CSRF state（一時）
 *   _gcrev_meta_account_label       — 表示用ラベル（ページ名）
 *
 * @package Mimamori_Web
 * @since   2.2.0
 */
class Gcrev_Meta_Client {

    const GRAPH_API_VERSION = 'v21.0';
    const GRAPH_BASE        = 'https://graph.facebook.com';
    const THREADS_API_BASE  = 'https://graph.threads.net';
    const OAUTH_DIALOG      = 'https://www.facebook.com/v21.0/dialog/oauth';

    /**
     * OAuth 認可スコープ（Facebook ログイン）
     */
    const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'instagram_basic',
        'instagram_content_publish',
        'business_management',
    ];

    /**
     * Meta アプリの client_id を取得
     */
    public static function get_app_id(): string {
        return (string) get_option('gcrev_meta_app_id', '');
    }

    /**
     * Meta アプリの client_secret を取得
     */
    public static function get_app_secret(): string {
        return (string) get_option('gcrev_meta_app_secret', '');
    }

    /**
     * OAuth コールバック URL
     */
    public static function get_redirect_uri(): string {
        return home_url('/social/meta-oauth-callback/');
    }

    /**
     * 認可 URL を生成
     */
    public static function build_auth_url(int $user_id): string {
        $app_id = self::get_app_id();
        if ( $app_id === '' ) {
            return '';
        }

        $state = wp_generate_password(32, false);
        update_user_meta($user_id, '_gcrev_meta_oauth_state', $state);

        $params = [
            'client_id'     => $app_id,
            'redirect_uri'  => self::get_redirect_uri(),
            'state'         => $state,
            'response_type' => 'code',
            'scope'         => implode(',', self::SCOPES),
        ];
        return self::OAUTH_DIALOG . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 認可コードを長期トークンに交換して保存。
     * 続けて Facebook ページ・Instagram Business・Threads の ID を解決。
     */
    public static function exchange_code_and_store(int $user_id, string $code, string $state): array {
        $saved = (string) get_user_meta($user_id, '_gcrev_meta_oauth_state', true);
        if ( $saved === '' || ! hash_equals($saved, $state) ) {
            return ['success' => false, 'message' => 'state不一致。再度お試しください。'];
        }
        delete_user_meta($user_id, '_gcrev_meta_oauth_state');

        $app_id     = self::get_app_id();
        $app_secret = self::get_app_secret();
        if ( $app_id === '' || $app_secret === '' ) {
            return ['success' => false, 'message' => 'Meta アプリ ID / Secret が未設定です（管理画面で設定してください）。'];
        }

        // 1) code → short-lived user token
        $short = self::http_get(self::GRAPH_BASE . '/' . self::GRAPH_API_VERSION . '/oauth/access_token', [
            'client_id'     => $app_id,
            'client_secret' => $app_secret,
            'redirect_uri'  => self::get_redirect_uri(),
            'code'          => $code,
        ]);
        if ( ! $short['ok'] || empty($short['body']['access_token']) ) {
            return ['success' => false, 'message' => '短期トークン取得失敗: ' . self::error_text($short)];
        }
        $short_token = (string) $short['body']['access_token'];

        // 2) short → long-lived user token (60日)
        $long = self::http_get(self::GRAPH_BASE . '/' . self::GRAPH_API_VERSION . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $app_id,
            'client_secret'     => $app_secret,
            'fb_exchange_token' => $short_token,
        ]);
        if ( ! $long['ok'] || empty($long['body']['access_token']) ) {
            return ['success' => false, 'message' => '長期トークン取得失敗: ' . self::error_text($long)];
        }
        $user_token   = (string) $long['body']['access_token'];
        $expires_in   = (int) ($long['body']['expires_in'] ?? (60 * 24 * 3600));

        update_user_meta($user_id, '_gcrev_meta_access_token',  Gcrev_Crypto::encrypt($user_token));
        update_user_meta($user_id, '_gcrev_meta_token_expires', time() + $expires_in);

        // 2.5) Facebook ユーザー ID を保存（データ削除コールバックで signed_request の
        //       user_id とマッチングするために必要）
        $me = self::http_get(self::GRAPH_BASE . '/' . self::GRAPH_API_VERSION . '/me', [
            'access_token' => $user_token,
            'fields'       => 'id',
        ]);
        if ( $me['ok'] && ! empty($me['body']['id']) ) {
            update_user_meta($user_id, '_gcrev_meta_fb_user_id', (string) $me['body']['id']);
        }

        // 3) ユーザーが管理するページ一覧 → 1つ目を選択（複数あれば後でUIで切替）
        $pages = self::http_get(self::GRAPH_BASE . '/' . self::GRAPH_API_VERSION . '/me/accounts', [
            'access_token' => $user_token,
            'fields'       => 'id,name,access_token,instagram_business_account',
        ]);
        if ( ! $pages['ok'] || empty($pages['body']['data']) ) {
            return ['success' => true, 'message' => 'ユーザーは認証されましたが、管理しているFacebookページが見つかりません。'];
        }

        $page = $pages['body']['data'][0];
        $page_id    = (string) ($page['id'] ?? '');
        $page_token = (string) ($page['access_token'] ?? '');
        $page_name  = (string) ($page['name'] ?? '');
        $ig_user_id = (string) ($page['instagram_business_account']['id'] ?? '');

        if ( $page_id !== '' ) {
            update_user_meta($user_id, '_gcrev_meta_fb_page_id',    $page_id);
            update_user_meta($user_id, '_gcrev_meta_fb_page_token', Gcrev_Crypto::encrypt($page_token));
            update_user_meta($user_id, '_gcrev_meta_account_label', $page_name);
        }
        if ( $ig_user_id !== '' ) {
            update_user_meta($user_id, '_gcrev_meta_ig_user_id', $ig_user_id);
        }

        // 4) Threads ユーザー ID 取得（権限が付与されている場合のみ）
        $threads = self::http_get(self::THREADS_API_BASE . '/' . self::GRAPH_API_VERSION . '/me', [
            'access_token' => $user_token,
            'fields'       => 'id,username',
        ]);
        if ( $threads['ok'] && ! empty($threads['body']['id']) ) {
            update_user_meta($user_id, '_gcrev_meta_threads_user_id', (string) $threads['body']['id']);
        }

        return ['success' => true, 'message' => ''];
    }

    /**
     * 接続を完全に解除（user_meta を削除）
     */
    public static function disconnect(int $user_id): void {
        $keys = [
            '_gcrev_meta_access_token',
            '_gcrev_meta_token_expires',
            '_gcrev_meta_fb_user_id',
            '_gcrev_meta_fb_page_id',
            '_gcrev_meta_fb_page_token',
            '_gcrev_meta_ig_user_id',
            '_gcrev_meta_threads_user_id',
            '_gcrev_meta_account_label',
            '_gcrev_meta_oauth_state',
        ];
        foreach ($keys as $k) {
            delete_user_meta($user_id, $k);
        }
    }

    /**
     * 接続状態を取得（UI用）
     */
    public static function get_connection_status(int $user_id): array {
        $expires = (int) get_user_meta($user_id, '_gcrev_meta_token_expires', true);
        $has_user_token = (bool) get_user_meta($user_id, '_gcrev_meta_access_token', true);
        return [
            'connected'        => $has_user_token && $expires > time(),
            'expires_at'       => $expires,
            'account_label'    => (string) get_user_meta($user_id, '_gcrev_meta_account_label', true),
            'fb_page_id'       => (string) get_user_meta($user_id, '_gcrev_meta_fb_page_id', true),
            'ig_user_id'       => (string) get_user_meta($user_id, '_gcrev_meta_ig_user_id', true),
            'threads_user_id'  => (string) get_user_meta($user_id, '_gcrev_meta_threads_user_id', true),
        ];
    }

    // ===================================================================
    // 投稿: Facebook ページ
    // ===================================================================

    /**
     * Facebook ページに投稿。
     * media_url があれば画像投稿、無ければテキスト投稿。
     *
     * @return array ['success'=>bool, 'platform_post_id'=>string, 'message'=>string]
     */
    public static function post_to_facebook(int $user_id, string $body, string $media_url = '', string $link_url = ''): array {
        $page_id    = (string) get_user_meta($user_id, '_gcrev_meta_fb_page_id', true);
        $page_token = Gcrev_Crypto::decrypt( (string) get_user_meta($user_id, '_gcrev_meta_fb_page_token', true) );
        if ( $page_id === '' || $page_token === '' ) {
            return ['success' => false, 'platform_post_id' => '', 'message' => 'Facebookページが未接続です。'];
        }

        if ( $media_url !== '' ) {
            $endpoint = self::GRAPH_BASE . '/' . self::GRAPH_API_VERSION . "/{$page_id}/photos";
            $params = [
                'access_token' => $page_token,
                'url'          => $media_url,
                'caption'      => $body,
            ];
        } else {
            $endpoint = self::GRAPH_BASE . '/' . self::GRAPH_API_VERSION . "/{$page_id}/feed";
            $params = [
                'access_token' => $page_token,
                'message'      => $body,
            ];
            if ( $link_url !== '' ) {
                $params['link'] = $link_url;
            }
        }

        $res = self::http_post($endpoint, $params);
        if ( ! $res['ok'] ) {
            self::log('fb_post', 'ERROR', $res);
            return ['success' => false, 'platform_post_id' => '', 'message' => 'Facebook投稿失敗: ' . self::error_text($res)];
        }

        $post_id = (string) ($res['body']['post_id'] ?? $res['body']['id'] ?? '');
        return ['success' => true, 'platform_post_id' => $post_id, 'message' => ''];
    }

    // ===================================================================
    // 投稿: Instagram Business（2ステップ）
    // ===================================================================

    /**
     * Instagram Business に画像投稿。
     * 画像必須（IG Graph API はテキストのみ投稿不可）。
     */
    public static function post_to_instagram(int $user_id, string $body, string $media_url): array {
        $ig_user_id = (string) get_user_meta($user_id, '_gcrev_meta_ig_user_id', true);
        $page_token = Gcrev_Crypto::decrypt( (string) get_user_meta($user_id, '_gcrev_meta_fb_page_token', true) );
        if ( $ig_user_id === '' || $page_token === '' ) {
            return ['success' => false, 'platform_post_id' => '', 'message' => 'Instagramビジネスアカウントが未接続です。'];
        }
        if ( $media_url === '' ) {
            return ['success' => false, 'platform_post_id' => '', 'message' => 'Instagramは画像（または動画）が必須です。'];
        }

        // Step 1: メディアコンテナ作成
        $create = self::http_post(
            self::GRAPH_BASE . '/' . self::GRAPH_API_VERSION . "/{$ig_user_id}/media",
            [
                'access_token' => $page_token,
                'image_url'    => $media_url,
                'caption'      => $body,
            ]
        );
        if ( ! $create['ok'] || empty($create['body']['id']) ) {
            self::log('ig_create', 'ERROR', $create);
            return ['success' => false, 'platform_post_id' => '', 'message' => 'IGメディア作成失敗: ' . self::error_text($create)];
        }
        $creation_id = (string) $create['body']['id'];

        // Step 2: 公開（少し待ってから — IG が画像をフェッチする時間）
        sleep(2);

        $publish = self::http_post(
            self::GRAPH_BASE . '/' . self::GRAPH_API_VERSION . "/{$ig_user_id}/media_publish",
            [
                'access_token' => $page_token,
                'creation_id'  => $creation_id,
            ]
        );
        if ( ! $publish['ok'] || empty($publish['body']['id']) ) {
            self::log('ig_publish', 'ERROR', $publish);
            return ['success' => false, 'platform_post_id' => '', 'message' => 'IG公開失敗: ' . self::error_text($publish)];
        }

        return ['success' => true, 'platform_post_id' => (string) $publish['body']['id'], 'message' => ''];
    }

    // ===================================================================
    // 投稿: Threads（2ステップ）
    // ===================================================================

    /**
     * Threads に投稿。テキスト単独可。画像URLがあれば画像投稿。
     */
    public static function post_to_threads(int $user_id, string $body, string $media_url = ''): array {
        $threads_user_id = (string) get_user_meta($user_id, '_gcrev_meta_threads_user_id', true);
        $user_token      = Gcrev_Crypto::decrypt( (string) get_user_meta($user_id, '_gcrev_meta_access_token', true) );
        if ( $threads_user_id === '' || $user_token === '' ) {
            return ['success' => false, 'platform_post_id' => '', 'message' => 'Threadsが未接続です（threads_*権限が必要）。'];
        }

        // Step 1: メディアコンテナ作成
        $params = [
            'access_token' => $user_token,
            'media_type'   => $media_url !== '' ? 'IMAGE' : 'TEXT',
            'text'         => $body,
        ];
        if ( $media_url !== '' ) {
            $params['image_url'] = $media_url;
        }

        $create = self::http_post(
            self::THREADS_API_BASE . '/' . self::GRAPH_API_VERSION . "/{$threads_user_id}/threads",
            $params
        );
        if ( ! $create['ok'] || empty($create['body']['id']) ) {
            self::log('threads_create', 'ERROR', $create);
            return ['success' => false, 'platform_post_id' => '', 'message' => 'Threadsコンテナ作成失敗: ' . self::error_text($create)];
        }
        $creation_id = (string) $create['body']['id'];

        sleep(2);

        // Step 2: 公開
        $publish = self::http_post(
            self::THREADS_API_BASE . '/' . self::GRAPH_API_VERSION . "/{$threads_user_id}/threads_publish",
            [
                'access_token'      => $user_token,
                'creation_id'       => $creation_id,
            ]
        );
        if ( ! $publish['ok'] || empty($publish['body']['id']) ) {
            self::log('threads_publish', 'ERROR', $publish);
            return ['success' => false, 'platform_post_id' => '', 'message' => 'Threads公開失敗: ' . self::error_text($publish)];
        }

        return ['success' => true, 'platform_post_id' => (string) $publish['body']['id'], 'message' => ''];
    }

    // ===================================================================
    // HTTP ユーティリティ
    // ===================================================================

    private static function http_get(string $url, array $params): array {
        $full = $url . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $res = wp_remote_get($full, ['timeout' => 30]);
        return self::parse_response($res);
    }

    private static function http_post(string $url, array $params): array {
        $res = wp_remote_post($url, [
            'body'    => $params,
            'timeout' => 60,
        ]);
        return self::parse_response($res);
    }

    private static function parse_response($res): array {
        if ( is_wp_error($res) ) {
            return ['ok' => false, 'code' => 0, 'body' => [], 'error' => $res->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);
        $body = json_decode($raw, true);
        if ( ! is_array($body) ) {
            $body = [];
        }
        return [
            'ok'    => ($code >= 200 && $code < 300),
            'code'  => $code,
            'body'  => $body,
            'error' => $body['error']['message'] ?? '',
            'raw'   => substr($raw, 0, 500),
        ];
    }

    private static function error_text(array $res): string {
        if ( ! empty($res['error']) ) { return (string) $res['error']; }
        if ( ! empty($res['body']['error']['message']) ) { return (string) $res['body']['error']['message']; }
        return 'HTTP ' . ($res['code'] ?? 0);
    }

    private static function log(string $tag, string $level, array $res): void {
        $line = date('Y-m-d H:i:s') . " [{$level}] meta/{$tag}: code=" . ($res['code'] ?? 0)
              . ' err=' . ($res['error'] ?? '') . ' raw=' . ($res['raw'] ?? '') . "\n";
        @file_put_contents('/tmp/gcrev_social_debug.log', $line, FILE_APPEND);
    }
}
