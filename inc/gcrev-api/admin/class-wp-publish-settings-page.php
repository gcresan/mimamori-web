<?php
// FILE: inc/gcrev-api/admin/class-wp-publish-settings-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }
if ( class_exists('Gcrev_WP_Publish_Settings_Page') ) { return; }

/**
 * WordPress投稿連携の管理画面設定ページ。
 * クライアントごとの設定を user_meta に保存する。
 */
class Gcrev_WP_Publish_Settings_Page {

    private const MENU_SLUG = 'gcrev-wp-publish';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'handle_save' ] );
    }

    public function add_menu_page(): void {
        if ( empty( $GLOBALS['admin_page_hooks']['gcrev-insight'] ) ) {
            add_menu_page( 'みまもりウェブ', 'みまもりウェブ', 'manage_options', 'gcrev-insight', '__return_null', 'dashicons-chart-area', 30 );
        }
        add_submenu_page(
            'gcrev-insight',
            'WordPress投稿連携 - みまもりウェブ',
            '📤 WordPress投稿連携',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function handle_save(): void {
        if ( ! isset( $_POST['gcrev_wp_publish_nonce'] ) ) { return; }
        if ( ! wp_verify_nonce( $_POST['gcrev_wp_publish_nonce'], 'gcrev_wp_publish_save' ) ) { return; }
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $user_id = isset( $_POST['target_user_id'] ) ? absint( $_POST['target_user_id'] ) : 0;
        if ( $user_id <= 0 ) { return; }

        require_once __DIR__ . '/../modules/class-wp-publish-client.php';
        Gcrev_WP_Publish_Client::save_settings( $user_id, [
            'enabled'          => ! empty( $_POST['wp_publish_enabled'] ),
            'site_url'         => $_POST['wp_publish_site_url'] ?? '',
            'username'         => $_POST['wp_publish_username'] ?? '',
            'app_password'     => $_POST['wp_publish_app_password'] ?? '',
            'default_status'   => $_POST['wp_publish_default_status'] ?? 'draft',
            'default_category' => $_POST['wp_publish_default_category'] ?? 0,
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&user_id=' . $user_id . '&saved=1' ) );
        exit;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        require_once __DIR__ . '/../modules/class-wp-publish-client.php';

        $users = get_users( [ 'fields' => [ 'ID', 'user_login', 'display_name' ] ] );
        $selected_user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $saved = isset( $_GET['saved'] );
        $settings = $selected_user_id > 0 ? Gcrev_WP_Publish_Client::get_settings( $selected_user_id ) : null;

        ?>
        <div class="wrap">
            <h1>📤 WordPress投稿連携</h1>
            <p>ライティング機能で生成した記事を、クライアントのWordPressサイトに下書き保存できます。</p>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
            <?php endif; ?>

            <!-- ユーザー選択 -->
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:20px;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
                <label><strong>クライアント:</strong>
                    <select name="user_id" onchange="this.form.submit()">
                        <option value="">選択してください</option>
                        <?php foreach ( $users as $u ) : ?>
                            <option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $selected_user_id, $u->ID ); ?>>
                                <?php echo esc_html( $u->display_name ?: $u->user_login ); ?> (ID: <?php echo esc_html( $u->ID ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>

            <?php if ( $selected_user_id > 0 && $settings !== null ) : ?>
                <form method="post" style="max-width:700px;">
                    <?php wp_nonce_field( 'gcrev_wp_publish_save', 'gcrev_wp_publish_nonce' ); ?>
                    <input type="hidden" name="target_user_id" value="<?php echo esc_attr( $selected_user_id ); ?>">

                    <table class="form-table">
                        <tr>
                            <th>連携を有効にする</th>
                            <td>
                                <label><input type="checkbox" name="wp_publish_enabled" value="1" <?php checked( $settings['enabled'] ); ?>> 有効</label>
                            </td>
                        </tr>
                        <tr>
                            <th>投稿先サイトURL</th>
                            <td>
                                <input type="url" name="wp_publish_site_url" value="<?php echo esc_attr( $settings['site_url'] ); ?>" class="regular-text" placeholder="https://example.com">
                                <p class="description">WordPressサイトのURLを入力してください（末尾の / は不要）</p>
                            </td>
                        </tr>
                        <tr>
                            <th>ユーザー名</th>
                            <td>
                                <input type="text" name="wp_publish_username" value="<?php echo esc_attr( $settings['username'] ); ?>" class="regular-text" placeholder="admin">
                            </td>
                        </tr>
                        <tr>
                            <th>アプリケーションパスワード</th>
                            <td>
                                <input type="password" name="wp_publish_app_password" value="" class="regular-text" placeholder="<?php echo $settings['app_password'] ? '（設定済み — 変更する場合のみ入力）' : ''; ?>">
                                <p class="description">WordPressの「ユーザー > プロフィール > アプリケーションパスワード」で生成したパスワードを入力してください</p>
                            </td>
                        </tr>
                        <tr>
                            <th>投稿ステータス</th>
                            <td>
                                <select name="wp_publish_default_status">
                                    <option value="draft" <?php selected( $settings['default_status'], 'draft' ); ?>>下書き</option>
                                    <option value="pending" <?php selected( $settings['default_status'], 'pending' ); ?>>レビュー待ち</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>デフォルトカテゴリID</th>
                            <td>
                                <input type="number" name="wp_publish_default_category" value="<?php echo esc_attr( $settings['default_category'] ); ?>" min="0" style="width:100px;">
                                <p class="description">0 = カテゴリなし（投稿先のデフォルトカテゴリが使われます）</p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( '設定を保存' ); ?>
                </form>

                <!-- 接続テスト -->
                <?php if ( $settings['site_url'] && $settings['username'] ) : ?>
                <div style="margin-top:20px;padding:16px;background:#f9f9f9;border:1px solid #ddd;border-radius:6px;max-width:700px;">
                    <h3 style="margin-top:0;">🔌 接続テスト</h3>
                    <p>設定した接続情報でWordPressに接続できるかテストします。</p>
                    <button type="button" id="gcrevTestWpConnection" class="button button-secondary">接続テストを実行</button>
                    <div id="gcrevTestResult" style="margin-top:10px;"></div>
                </div>
                <script>
                document.getElementById('gcrevTestWpConnection').addEventListener('click', function() {
                    var btn = this;
                    var result = document.getElementById('gcrevTestResult');
                    btn.disabled = true;
                    btn.textContent = 'テスト中…';
                    result.innerHTML = '';
                    fetch('<?php echo esc_url( rest_url( 'gcrev/v1/wp-publish/test-connection' ) ); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>' },
                        body: JSON.stringify({ user_id: <?php echo $selected_user_id; ?> })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        btn.disabled = false;
                        btn.textContent = '接続テストを実行';
                        if (d.success) {
                            result.innerHTML = '<div style="color:#4E8A6B;font-weight:600;">✅ ' + d.message + '</div>';
                        } else {
                            result.innerHTML = '<div style="color:#C95A4F;font-weight:600;">❌ ' + (d.error || 'エラー') + '</div>';
                        }
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.textContent = '接続テストを実行';
                        result.innerHTML = '<div style="color:#C95A4F;">通信エラーが発生しました</div>';
                    });
                });
                </script>
                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }
}
