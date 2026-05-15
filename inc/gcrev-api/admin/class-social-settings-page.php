<?php
// FILE: inc/gcrev-api/admin/class-social-settings-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Social_Settings_Page') ) { return; }

/**
 * Gcrev_Social_Settings_Page
 *
 * 管理画面「みまもりウェブ > SNS連携設定」。
 *
 * 保存するもの:
 *   - Meta（Facebook/Instagram/Threads）の App ID / App Secret  → wp_options
 *
 * 表示するもの:
 *   - 各ユーザーの接続状況（Meta + LINE）
 *
 * LINE はユーザーごとにチャネルアクセストークンを貼り付ける方式のため、
 * 管理画面側にはグローバル設定なし。
 *
 * @package Mimamori_Web
 * @since   2.2.0
 */
class Gcrev_Social_Settings_Page {

    private const MENU_SLUG    = 'gcrev-social-settings';
    private const OPTION_GROUP = 'gcrev_social_settings_group';
    private const SECTION_ID   = 'gcrev_social_meta_section';

    public function register(): void {
        add_action('admin_menu', [ $this, 'add_menu_page' ]);
        add_action('admin_init', [ $this, 'register_settings' ]);
    }

    public function add_menu_page(): void {
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
            'SNS連携設定 - みまもりウェブ',
            '📱 SNS連携設定',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        register_setting(self::OPTION_GROUP, 'gcrev_meta_app_id', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);
        register_setting(self::OPTION_GROUP, 'gcrev_meta_app_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        add_settings_section(
            self::SECTION_ID,
            'Meta（Facebook / Instagram / Threads）アプリ設定',
            [ $this, 'render_section_description' ],
            self::MENU_SLUG
        );
        add_settings_field(
            'gcrev_meta_app_id',
            'App ID',
            [ $this, 'render_field_app_id' ],
            self::MENU_SLUG,
            self::SECTION_ID
        );
        add_settings_field(
            'gcrev_meta_app_secret',
            'App Secret',
            [ $this, 'render_field_app_secret' ],
            self::MENU_SLUG,
            self::SECTION_ID
        );
    }

    public function render_page(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die('権限がありません。');
        }

        $redirect_uri = home_url('/social/meta-oauth-callback/');
        ?>
        <div class="wrap">
            <h1 style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:28px;">📱</span> SNS連携設定
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

            <!-- Meta 設定ガイド -->
            <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:20px 24px; max-width:760px;">
                <h3 style="margin:0 0 12px; font-size:16px; color:#0369a1;">📋 Meta for Developers での設定手順</h3>
                <ol style="margin:0; padding-left:20px; color:#334155; line-height:2;">
                    <li><a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">Meta for Developers &gt; マイアプリ</a> でアプリを新規作成（タイプ: ビジネス）</li>
                    <li>製品を追加：<strong>Facebook Login for Business</strong> / <strong>Instagram</strong> / <strong>Threads</strong></li>
                    <li>「Facebook Login for Business」設定で、有効な OAuth リダイレクト URI に以下を追加：
                        <div style="margin:8px 0;">
                            <code id="meta-redirect-uri"
                                  style="background:#e0f2fe; padding:8px 14px; border-radius:4px; font-size:13px; display:inline-block; cursor:pointer;"
                                  onclick="copyMetaRedirectUri()" title="クリックでコピー">
                                <?php echo esc_html($redirect_uri); ?>
                            </code>
                            <span id="meta-copy-feedback" style="color:#059669; font-size:12px; margin-left:8px; display:none;">✅ コピーしました</span>
                        </div>
                    </li>
                    <li>必要なアクセス許可（権限）として以下をリクエスト（App Review で Advanced Access を申請）：
                        <ul style="margin:4px 0 0 16px; list-style:disc;">
                            <li><code>pages_show_list</code> / <code>pages_read_engagement</code> / <code>pages_manage_posts</code></li>
                            <li><code>instagram_basic</code> / <code>instagram_content_publish</code></li>
                            <li><code>threads_basic</code> / <code>threads_content_publish</code>（Threads製品を追加した場合）</li>
                        </ul>
                        <p style="margin:4px 0 0 16px; font-size:12px; color:#666;">
                            ※ <code>business_management</code> は本実装では使用しません（投稿は Page Access Token で行うため）。
                        </p>
                    </li>
                    <li>「アプリの設定 &gt; ベーシック」から App ID / App Secret をコピーして上のフォームに入力</li>
                    <li>本番利用するにはアプリレビュー申請が必要（pages_manage_posts, instagram_content_publish 等は審査対象）</li>
                </ol>
            </div>

            <script>
            function copyMetaRedirectUri() {
                var uri = document.getElementById('meta-redirect-uri').textContent.trim();
                navigator.clipboard.writeText(uri).then(function() {
                    var fb = document.getElementById('meta-copy-feedback');
                    fb.style.display = 'inline';
                    setTimeout(function() { fb.style.display = 'none'; }, 2000);
                });
            }
            </script>

            <hr style="margin: 32px 0;">

            <!-- LINE 設定ガイド -->
            <div style="background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:20px 24px; max-width:760px;">
                <h3 style="margin:0 0 12px; font-size:16px; color:#047857;">📋 LINE Messaging API の設定手順</h3>
                <ol style="margin:0; padding-left:20px; color:#334155; line-height:2;">
                    <li><a href="https://developers.line.biz/console/" target="_blank" rel="noopener">LINE Developers コンソール</a> でプロバイダー＆Messaging API チャネルを作成</li>
                    <li>「Messaging API設定」タブで <strong>長期のチャネルアクセストークン</strong> を発行</li>
                    <li>各クライアントが <strong>「SNS連携」ページ</strong>（フロントエンド）でトークンを貼り付け</li>
                    <li>LINE 公式アカウントの「応答設定」で「Webhook」「あいさつメッセージ」等を必要に応じて構成</li>
                </ol>
                <p style="margin:12px 0 0; color:#475569; font-size:13px;">
                    ※ LINE はクライアント自身のチャネルへ送信するため、グローバルな App ID 設定は不要です。
                </p>
            </div>

            <hr style="margin: 32px 0;">

            <!-- 接続状態 -->
            <div style="max-width:920px;">
                <h3 style="font-size:16px; color:#1e293b;">🔌 ユーザー別 SNS接続状態</h3>
                <?php $this->render_connection_status_table(); ?>
            </div>
        </div>
        <?php
    }

    public function render_section_description(): void {
        echo '<p style="color:#64748b;">Meta for Developers で作成したアプリの ID / Secret を入力してください。Facebook / Instagram / Threads はすべて1つの Meta アプリで動作します。</p>';
    }

    public function render_field_app_id(): void {
        $value = get_option('gcrev_meta_app_id', '');
        ?>
        <input type="text"
               name="gcrev_meta_app_id"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               placeholder="000000000000000"
               style="width:100%; max-width:560px;"
               autocomplete="off">
        <p class="description">Meta for Developers の「アプリの設定 &gt; ベーシック」にある App ID。</p>
        <?php
    }

    public function render_field_app_secret(): void {
        $value = get_option('gcrev_meta_app_secret', '');
        $has   = ! empty($value);
        ?>
        <div style="position:relative; max-width:560px;">
            <input type="<?php echo $has ? 'password' : 'text'; ?>"
                   id="gcrev_meta_app_secret"
                   name="gcrev_meta_app_secret"
                   value="<?php echo esc_attr($value); ?>"
                   class="regular-text"
                   placeholder="••••••••••••••••"
                   style="width:100%; padding-right:44px;"
                   autocomplete="off">
            <button type="button" onclick="
                var f=document.getElementById('gcrev_meta_app_secret');
                f.type = (f.type==='password') ? 'text' : 'password';
            " style="position:absolute; right:4px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:18px;">👁️</button>
        </div>
        <p class="description">
            <?php if ($has): ?><span style="color:#059669;">✅ 設定済み</span> — <?php endif; ?>
            Meta for Developers のアプリ設定からコピー。
        </p>
        <?php
    }

    /**
     * 接続済みユーザー一覧
     */
    private function render_connection_status_table(): void {
        // Meta または LINE のどちらかでも接続しているユーザーを集める
        $users = get_users([
            'meta_query' => [
                'relation' => 'OR',
                [ 'key' => '_gcrev_meta_access_token', 'compare' => 'EXISTS' ],
                [ 'key' => '_gcrev_line_channel_token','compare' => 'EXISTS' ],
            ],
            'fields' => ['ID', 'display_name', 'user_email'],
        ]);

        if ( empty($users) ) {
            echo '<p style="color:#94a3b8; margin-top:8px;">まだSNS接続済みのユーザーはいません。</p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:920px; margin-top:12px;">
            <thead>
                <tr>
                    <th>ユーザー</th>
                    <th>FB</th>
                    <th>IG</th>
                    <th>Threads</th>
                    <th>LINE</th>
                    <th>Meta 期限</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u):
                $meta = Gcrev_Meta_Client::get_connection_status((int) $u->ID);
                $line = Gcrev_LINE_Client::get_connection_status((int) $u->ID);
                $expires_str = $meta['expires_at'] > 0 ? wp_date('Y/m/d H:i', $meta['expires_at']) : '—';
                $expired = $meta['expires_at'] > 0 && $meta['expires_at'] <= time();
            ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( function_exists('gcrev_get_business_name') ? gcrev_get_business_name((int) $u->ID) : $u->display_name ); ?></strong><br>
                        <span style="color:#94a3b8; font-size:11px;"><?php echo esc_html($u->user_email); ?></span>
                    </td>
                    <td><?php echo $meta['fb_page_id'] !== '' ? '✅' : '—'; ?></td>
                    <td><?php echo $meta['ig_user_id'] !== '' ? '✅' : '—'; ?></td>
                    <td><?php echo $meta['threads_user_id'] !== '' ? '✅' : '—'; ?></td>
                    <td><?php echo $line['connected'] ? '✅' : '—'; ?></td>
                    <td>
                        <?php echo esc_html($expires_str); ?>
                        <?php if ($expired): ?> <span style="color:#d97706;">⚠️ 要更新</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
