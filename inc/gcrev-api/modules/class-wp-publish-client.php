<?php
// FILE: inc/gcrev-api/modules/class-wp-publish-client.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }
if ( class_exists('Gcrev_WP_Publish_Client') ) { return; }

/**
 * 外部 WordPress サイトへの REST API 投稿クライアント。
 *
 * Application Password 認証で WP REST API v2 経由で
 * 投稿の作成・更新・接続テストを行う。
 */
class Gcrev_WP_Publish_Client {

    private const DEBUG_LOG = '/tmp/gcrev_wp_publish_debug.log';
    private const TIMEOUT   = 30;

    // =========================================================
    // 設定取得ヘルパー
    // =========================================================

    /**
     * WordPress連携設定を取得する。
     * クライアント（ユーザー）ごとに user_meta に保存。
     */
    public static function get_settings( int $user_id = 0 ): array {
        if ( $user_id <= 0 ) { $user_id = get_current_user_id(); }
        return [
            'enabled'          => get_user_meta( $user_id, 'gcrev_wp_publish_enabled', true ) === '1',
            'site_url'         => get_user_meta( $user_id, 'gcrev_wp_publish_site_url', true ) ?: '',
            'username'         => get_user_meta( $user_id, 'gcrev_wp_publish_username', true ) ?: '',
            'app_password'     => self::decrypt_password( get_user_meta( $user_id, 'gcrev_wp_publish_app_password', true ) ?: '' ),
            'default_status'   => get_user_meta( $user_id, 'gcrev_wp_publish_default_status', true ) ?: 'draft',
            'default_category' => (int) ( get_user_meta( $user_id, 'gcrev_wp_publish_default_category', true ) ?: 0 ),
        ];
    }

    /**
     * 設定を保存する。
     */
    public static function save_settings( int $user_id, array $data ): void {
        update_user_meta( $user_id, 'gcrev_wp_publish_enabled', ! empty( $data['enabled'] ) ? '1' : '0' );
        if ( isset( $data['site_url'] ) ) {
            update_user_meta( $user_id, 'gcrev_wp_publish_site_url', esc_url_raw( rtrim( $data['site_url'], '/' ) ) );
        }
        if ( isset( $data['username'] ) ) {
            update_user_meta( $user_id, 'gcrev_wp_publish_username', sanitize_text_field( $data['username'] ) );
        }
        if ( isset( $data['app_password'] ) && $data['app_password'] !== '' ) {
            update_user_meta( $user_id, 'gcrev_wp_publish_app_password', self::encrypt_password( $data['app_password'] ) );
        }
        if ( isset( $data['default_status'] ) ) {
            $status = in_array( $data['default_status'], [ 'draft', 'pending', 'publish' ], true ) ? $data['default_status'] : 'draft';
            update_user_meta( $user_id, 'gcrev_wp_publish_default_status', $status );
        }
        if ( isset( $data['default_category'] ) ) {
            update_user_meta( $user_id, 'gcrev_wp_publish_default_category', absint( $data['default_category'] ) );
        }
    }

    // =========================================================
    // 暗号化
    // =========================================================

    private static function encrypt_password( string $plain ): string {
        if ( class_exists( 'Gcrev_Crypto' ) ) {
            return Gcrev_Crypto::encrypt( $plain );
        }
        return $plain;
    }

    private static function decrypt_password( string $encrypted ): string {
        if ( $encrypted === '' ) { return ''; }
        if ( class_exists( 'Gcrev_Crypto' ) ) {
            return Gcrev_Crypto::decrypt( $encrypted );
        }
        return $encrypted;
    }

    // =========================================================
    // API通信
    // =========================================================

    /**
     * 接続テスト。
     */
    public function test_connection( string $site_url, string $username, string $app_password ): array {
        $endpoint = rtrim( $site_url, '/' ) . '/wp-json/wp/v2/users/me';

        $response = wp_remote_get( $endpoint, [
            'timeout' => self::TIMEOUT,
            'headers' => [ 'Authorization' => 'Basic ' . base64_encode( $username . ':' . $app_password ) ],
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            self::log( "test_connection: WP Error: {$msg}" );
            if ( stripos( $msg, 'timeout' ) !== false ) {
                return [ 'success' => false, 'error' => '接続がタイムアウトしました。サイトURLを確認してください。' ];
            }
            return [ 'success' => false, 'error' => '接続できませんでした: ' . $msg ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 401 || $code === 403 ) {
            return [ 'success' => false, 'error' => '認証に失敗しました。ユーザー名とアプリケーションパスワードを確認してください。' ];
        }
        if ( $code !== 200 ) {
            self::log( "test_connection: HTTP {$code} body=" . substr( wp_remote_retrieve_body( $response ), 0, 300 ) );
            return [ 'success' => false, 'error' => "サーバーからエラーが返されました（HTTP {$code}）" ];
        }

        // 投稿権限チェック
        $capabilities = $body['capabilities'] ?? [];
        if ( empty( $capabilities['edit_posts'] ) ) {
            return [ 'success' => false, 'error' => '接続は成功しましたが、このユーザーに投稿権限がありません。' ];
        }

        return [
            'success' => true,
            'message' => '接続成功！ ユーザー「' . ( $body['name'] ?? $username ) . '」で投稿できます。',
            'user_name' => $body['name'] ?? $username,
        ];
    }

    /**
     * 投稿を作成する。
     */
    public function create_post( string $site_url, string $username, string $app_password, array $payload ): array {
        $endpoint = rtrim( $site_url, '/' ) . '/wp-json/wp/v2/posts';
        self::log( "create_post: {$endpoint}" );

        $response = wp_remote_post( $endpoint, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $username . ':' . $app_password ),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
            'sslverify' => true,
        ] );

        return $this->handle_post_response( $response, 'create' );
    }

    /**
     * 既存投稿を更新する。
     */
    public function update_post( string $site_url, string $username, string $app_password, int $remote_post_id, array $payload ): array {
        $endpoint = rtrim( $site_url, '/' ) . '/wp-json/wp/v2/posts/' . $remote_post_id;
        self::log( "update_post: {$endpoint}" );

        $response = wp_remote_request( $endpoint, [
            'method'  => 'PUT',
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $username . ':' . $app_password ),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
            'sslverify' => true,
        ] );

        return $this->handle_post_response( $response, 'update' );
    }

    /**
     * カテゴリ一覧を取得する。
     */
    public function get_categories( string $site_url, string $username, string $app_password ): array {
        $endpoint = rtrim( $site_url, '/' ) . '/wp-json/wp/v2/categories?per_page=100';

        $response = wp_remote_get( $endpoint, [
            'timeout' => self::TIMEOUT,
            'headers' => [ 'Authorization' => 'Basic ' . base64_encode( $username . ':' . $app_password ) ],
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return [ 'success' => false, 'error' => "HTTP {$code}" ];
        }

        $cats = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $cats ) ) { return [ 'success' => true, 'categories' => [] ]; }

        $result = [];
        foreach ( $cats as $cat ) {
            $result[] = [ 'id' => $cat['id'], 'name' => $cat['name'], 'slug' => $cat['slug'] ];
        }
        return [ 'success' => true, 'categories' => $result ];
    }

    // =========================================================
    // メディアアップロード
    // =========================================================

    /**
     * 画像ファイルをリモート WordPress にアップロードする。
     *
     * @return array{ success: bool, media_id?: int, error?: string }
     */
    public function upload_media( string $site_url, string $username, string $app_password, string $file_path, string $filename ): array {
        $endpoint = rtrim( $site_url, '/' ) . '/wp-json/wp/v2/media';
        self::log( "upload_media: {$endpoint}, file={$filename}" );

        $mime = wp_check_filetype( $filename )['type'] ?: 'image/png';
        $file_data = file_get_contents( $file_path );
        if ( $file_data === false ) {
            return [ 'success' => false, 'error' => 'ファイルの読み込みに失敗しました。' ];
        }

        $response = wp_remote_post( $endpoint, [
            'timeout' => 60,
            'headers' => [
                'Authorization'       => 'Basic ' . base64_encode( $username . ':' . $app_password ),
                'Content-Type'        => $mime,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ],
            'body' => $file_data,
        ] );

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            self::log( "upload_media: WP_Error: {$msg}" );
            return [ 'success' => false, 'error' => '通信エラー: ' . $msg ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 201 && ! empty( $body['id'] ) ) {
            self::log( "upload_media: success, media_id=" . $body['id'] );
            return [ 'success' => true, 'media_id' => (int) $body['id'] ];
        }

        $wp_msg = $body['message'] ?? '';
        self::log( "upload_media: HTTP {$code} msg={$wp_msg}" );
        return [ 'success' => false, 'error' => "メディアアップロードに失敗しました（HTTP {$code}）" . ( $wp_msg ? ": {$wp_msg}" : '' ) ];
    }

    // =========================================================
    // Payload ビルダー
    // =========================================================

    /**
     * WordPress REST API 投稿 payload を組み立てる。
     */
    public static function build_payload( string $title, string $content, string $status = 'draft', array $options = [] ): array {
        $payload = [
            'title'   => $title,
            'content' => $content,
            'status'  => in_array( $status, [ 'draft', 'pending', 'publish' ], true ) ? $status : 'draft',
        ];

        if ( ! empty( $options['excerpt'] ) )    { $payload['excerpt']    = $options['excerpt']; }
        if ( ! empty( $options['slug'] ) )        { $payload['slug']      = $options['slug']; }
        if ( ! empty( $options['categories'] ) )  { $payload['categories'] = array_map( 'absint', (array) $options['categories'] ); }
        if ( ! empty( $options['tags'] ) )        { $payload['tags']      = array_map( 'absint', (array) $options['tags'] ); }
        if ( ! empty( $options['date'] ) )        { $payload['date']      = $options['date']; }

        return $payload;
    }

    /**
     * Markdown → WordPress 向け HTML に変換。
     */
    public static function markdown_to_wp_html( string $markdown ): string {
        // 見出し
        $html = preg_replace( '/^#### (.+)$/m', '<h4>$1</h4>', $markdown );
        $html = preg_replace( '/^### (.+)$/m', '<h3>$1</h3>', $html );
        $html = preg_replace( '/^## (.+)$/m', '<h2>$1</h2>', $html );
        $html = preg_replace( '/^# (.+)$/m', '<h1>$1</h1>', $html );
        // 太字
        $html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
        // リスト
        $html = preg_replace( '/^[-*] (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/(<li>.*<\/li>\n?)+/', "<ul>\n$0</ul>\n", $html );
        // 段落
        $html = preg_replace( '/\n{2,}/', "\n\n", $html );
        $paragraphs = explode( "\n\n", trim( $html ) );
        $result = [];
        foreach ( $paragraphs as $p ) {
            $p = trim( $p );
            if ( $p === '' ) { continue; }
            // 既にブロック要素なら囲わない
            if ( preg_match( '/^<(h[1-6]|ul|ol|li|blockquote|div|table|pre|hr)/', $p ) ) {
                $result[] = $p;
            } else {
                $result[] = '<p>' . $p . '</p>';
            }
        }
        return implode( "\n\n", $result );
    }

    // =========================================================
    // 内部ヘルパー
    // =========================================================

    private function handle_post_response( $response, string $action ): array {
        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            self::log( "{$action}: WP Error: {$msg}" );
            return [ 'success' => false, 'error' => '通信エラー: ' . $msg ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 401 || $code === 403 ) {
            return [ 'success' => false, 'error' => '認証エラー: ユーザー名またはパスワードを確認してください。' ];
        }
        if ( $code < 200 || $code >= 300 ) {
            $wp_msg = $body['message'] ?? '';
            self::log( "{$action}: HTTP {$code} msg={$wp_msg}" );
            return [ 'success' => false, 'error' => "投稿に失敗しました（HTTP {$code}）" . ( $wp_msg ? ": {$wp_msg}" : '' ) ];
        }

        $post_id  = $body['id'] ?? null;
        $post_url = $body['link'] ?? '';
        $edit_url = '';
        if ( $post_id ) {
            $edit_url = rtrim( parse_url( $post_url, PHP_URL_SCHEME ) . '://' . parse_url( $post_url, PHP_URL_HOST ), '/' )
                . '/wp-admin/post.php?post=' . $post_id . '&action=edit';
        }

        self::log( "{$action}: success post_id={$post_id}" );

        return [
            'success'        => true,
            'remote_post_id' => $post_id,
            'remote_url'     => $post_url,
            'remote_edit_url' => $edit_url,
            'status'         => $body['status'] ?? 'draft',
        ];
    }

    private static function log( string $message ): void {
        file_put_contents( self::DEBUG_LOG,
            date( 'Y-m-d H:i:s' ) . " [WP Publish] {$message}\n",
            FILE_APPEND
        );
    }
}
