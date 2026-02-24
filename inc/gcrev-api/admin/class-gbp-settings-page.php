<?php
// FILE: inc/gcrev-api/admin/class-gbp-settings-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_GBP_Settings_Page') ) { return; }

/**
 * Gcrev_GBP_Settings_Page
 *
 * WordPress管理画面に「みまもりウェブ > GBP設定」ページを追加する。
 * GBP OAuth のクライアントID / シークレットを wp_options に保存・管理する。
 *
 * option_name（class-config.php の get() と整合）:
 *   gcrev_gbp_client_id
 *   gcrev_gbp_client_secret
 *
 * @package GCREV_INSIGHT
 * @since   2.1.0
 */
class Gcrev_GBP_Settings_Page {

    /** メニュースラッグ */
    private const MENU_SLUG = 'gcrev-gbp-settings';

    /** オプショングループ（Settings API用） */
    private const OPTION_GROUP = 'gcrev_gbp_settings_group';

    /** セクションID */
    private const SECTION_ID = 'gcrev_gbp_oauth_section';

    /**
     * フック登録
     */
    public function register(): void {
        add_action('admin_menu', [ $this, 'add_menu_page' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
    }

    // =========================================================
    // メニュー登録
    // =========================================================

    /**
     * 管理メニューにページを追加
     */
    public function add_menu_page(): void {
        // トップメニュー（既に存在する場合はスキップ）
        if ( empty( $GLOBALS['admin_page_hooks']['gcrev-insight'] ) ) {
            add_menu_page(
                'みまもりウェブ',
                'みまもりウェブ',
                'manage_options',
                'gcrev-insight',
                '__return_null',
                'dashicons-chart-area',
                30
            );
        }

        add_submenu_page(
            'gcrev-insight',
            'GBP設定 - みまもりウェブ',
            '📍 GBP設定',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================
    // Settings API 登録
    // =========================================================

    /**
     * 設定フィールドを登録
     */
    public function register_settings(): void {
        register_setting(self::OPTION_GROUP, 'gcrev_gbp_client_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);
        register_setting(self::OPTION_GROUP, 'gcrev_gbp_client_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        add_settings_section(
            self::SECTION_ID,
            'Google Business Profile OAuth 設定',
            [ $this, 'render_section_description' ],
            self::MENU_SLUG
        );

        add_settings_field(
            'gcrev_gbp_client_id',
            'クライアントID',
            [ $this, 'render_field_client_id' ],
            self::MENU_SLUG,
            self::SECTION_ID
        );
        add_settings_field(
            'gcrev_gbp_client_secret',
            'クライアントシークレット',
            [ $this, 'render_field_client_secret' ],
            self::MENU_SLUG,
            self::SECTION_ID
        );
    }

    // =========================================================
    // ページレンダリング
    // =========================================================

    /**
     * 設定ページ本体
     */
    public function render_page(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die('権限がありません。');
        }

        $redirect_uri = home_url('/meo/gbp-oauth-callback/');
        ?>
        <div class="wrap">
            <h1 style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:28px;">📍</span> GBP設定（Googleビジネスプロフィール連携）
            </h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::MENU_SLUG);
                submit_button('設定を保存');
                ?>
            </form>

            <hr style="margin: 32px 0;">

            <!-- 設定ガイド -->
            <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:20px 24px; max-width:720px;">
                <h3 style="margin:0 0 12px; font-size:16px; color:#0369a1;">📋 Google Cloud Console での設定手順</h3>
                <ol style="margin:0; padding-left:20px; color:#334155; line-height:2;">
                    <li><a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console &gt; 認証情報</a> を開く</li>
                    <li>「認証情報を作成」→「OAuth クライアントID」を選択</li>
                    <li>アプリケーションの種類：<strong>ウェブアプリケーション</strong></li>
                    <li>「承認済みのリダイレクト URI」に以下を追加：
                        <div style="margin:8px 0;">
                            <code id="redirect-uri"
                                  style="background:#e0f2fe; padding:8px 14px; border-radius:4px; font-size:13px; display:inline-block; cursor:pointer;"
                                  onclick="copyRedirectUri()" title="クリックでコピー">
                                <?php echo esc_html($redirect_uri); ?>
                            </code>
                            <span id="copy-feedback" style="color:#059669; font-size:12px; margin-left:8px; display:none;">✅ コピーしました</span>
                        </div>
                    </li>
                    <li>作成後の「クライアントID」と「クライアントシークレット」を上のフォームに入力</li>
                    <li>APIライブラリで以下を有効化：
                        <ul style="margin:4px 0 0 16px; list-style:disc;">
                            <li>My Business Business Information API</li>
                            <li>Business Profile Performance API</li>
                        </ul>
                    </li>
                </ol>
            </div>

            <script>
            function copyRedirectUri() {
                var uri = document.getElementById('redirect-uri').textContent.trim();
                navigator.clipboard.writeText(uri).then(function() {
                    var fb = document.getElementById('copy-feedback');
                    fb.style.display = 'inline';
                    setTimeout(function() { fb.style.display = 'none'; }, 2000);
                });
            }
            </script>

            <hr style="margin: 32px 0;">

            <!-- 接続状態確認 -->
            <div style="max-width:720px;">
                <h3 style="font-size:16px; color:#1e293b;">🔌 ユーザー別 GBP接続状態</h3>
                <?php $this->render_connection_status_table(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * セクション説明文
     */
    public function render_section_description(): void {
        echo '<p style="color:#64748b;">Google Cloud Console で作成した OAuth 2.0 クライアントの情報を入力してください。</p>';
    }

    /**
     * クライアントIDフィールド
     */
    public function render_field_client_id(): void {
        $value = get_option('gcrev_gbp_client_id', '');
        ?>
        <input type="text"
               name="gcrev_gbp_client_id"
               id="gcrev_gbp_client_id"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               placeholder="xxxxxxxxxxxx.apps.googleusercontent.com"
               style="width:100%; max-width:560px;"
               autocomplete="off">
        <p class="description">例: 123456789-xxxxxx.apps.googleusercontent.com</p>
        <?php
    }

    /**
     * クライアントシークレットフィールド
     */
    public function render_field_client_secret(): void {
        $value = get_option('gcrev_gbp_client_secret', '');
        $has_value = ! empty($value);
        ?>
        <div style="position:relative; max-width:560px;">
            <input type="<?php echo $has_value ? 'password' : 'text'; ?>"
                   name="gcrev_gbp_client_secret"
                   id="gcrev_gbp_client_secret"
                   value="<?php echo esc_attr($value); ?>"
                   class="regular-text"
                   placeholder="GOCSPX-xxxxxxxxxxxxxx"
                   style="width:100%; padding-right:44px;"
                   autocomplete="off">
            <button type="button"
                    onclick="toggleSecretVisibility()"
                    style="position:absolute; right:4px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:18px; padding:4px 8px;"
                    title="表示/非表示を切替">
                <span id="secret-toggle-icon">👁️</span>
            </button>
        </div>
        <p class="description">
            <?php if ($has_value): ?>
                <span style="color:#059669;">✅ 設定済み</span> —
            <?php endif; ?>
            Google Cloud Console からコピーして貼り付けてください。
        </p>
        <script>
        function toggleSecretVisibility() {
            var field = document.getElementById('gcrev_gbp_client_secret');
            var icon = document.getElementById('secret-toggle-icon');
            if (field.type === 'password') {
                field.type = 'text';
                icon.textContent = '🙈';
            } else {
                field.type = 'password';
                icon.textContent = '👁️';
            }
        }
        </script>
        <?php
    }

    // =========================================================
    // 接続状態テーブル
    // =========================================================

    /**
     * GBP接続済みユーザーの一覧を表示
     */
    private function render_connection_status_table(): void {
        $connected_users = get_users([
            'meta_key'     => '_gcrev_gbp_refresh_token',
            'meta_compare' => 'EXISTS',
            'fields'       => ['ID', 'display_name', 'user_email'],
        ]);

        if ( empty($connected_users) ) {
            echo '<p style="color:#94a3b8; margin-top:8px;">まだGBP接続済みのユーザーはいません。</p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:720px; margin-top:12px;">
            <thead>
                <tr>
                    <th style="width:180px;">ユーザー</th>
                    <th>メール</th>
                    <th style="width:170px;">トークン有効期限</th>
                    <th style="width:120px;">状態</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($connected_users as $user):
                $expires    = (int) get_user_meta($user->ID, '_gcrev_gbp_token_expires', true);
                $is_valid   = ($expires > time());
                $expires_str = $expires > 0 ? wp_date('Y/m/d H:i', $expires) : '—';
            ?>
                <tr>
                    <td><strong><?php echo esc_html($user->display_name); ?></strong></td>
                    <td><?php echo esc_html($user->user_email); ?></td>
                    <td><?php echo esc_html($expires_str); ?></td>
                    <td>
                        <?php if ($is_valid): ?>
                            <span style="color:#059669; font-weight:600;">✅ 有効</span>
                        <?php else: ?>
                            <span style="color:#d97706; font-weight:600;">⚠️ 要更新</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top:8px;">
            ※ トークンは期限切れでも、ユーザーがMEOダッシュボードにアクセスした際に自動更新されます。
        </p>
        <?php
    }
}
