<?php
// FILE: inc/gcrev-api/admin/class-cv-settings-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_CV_Settings_Page') ) { return; }

/**
 * Gcrev_CV_Settings_Page
 *
 * WordPress管理画面に「みまもりウェブ > CV設定」ページを追加する。
 * ユーザーごとのGA4 CVイベント（ルート）設定を管理する。
 *
 * データストア:
 *   {prefix}gcrev_cv_routes テーブル（route_key, label, enabled, sort_order）
 *   user_meta: _gcrev_cv_only_configured, _gcrev_phone_event_name
 *
 * @package Mimamori_Web
 * @since   2.2.0
 */
class Gcrev_CV_Settings_Page {

    /** メニュースラッグ */
    private const MENU_SLUG = 'gcrev-cv-settings';

    /**
     * フック登録
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'handle_actions']);
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
            'CV設定 - みまもりウェブ',
            '📊 CV設定',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    // =========================================================
    // POSTアクション処理
    // =========================================================

    /**
     * フォーム送信を処理（PRGパターン）
     */
    public function handle_actions(): void {
        if ( ! current_user_can('manage_options') ) {
            return;
        }

        if ( empty($_POST['_gcrev_cv_settings_nonce']) ) {
            return;
        }

        if ( ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_gcrev_cv_settings_nonce'])), 'gcrev_cv_settings_action') ) {
            wp_die('不正なリクエストです。');
        }

        $action  = isset($_POST['gcrev_action']) ? sanitize_text_field(wp_unslash($_POST['gcrev_action'])) : '';
        $user_id = isset($_POST['gcrev_target_user']) ? absint($_POST['gcrev_target_user']) : 0;

        if ( $action !== 'save_cv_routes' || $user_id <= 0 ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_cv_routes';

        // --- CV イベント（textarea） ---
        $raw_events = isset($_POST['cv_events']) ? sanitize_textarea_field(wp_unslash($_POST['cv_events'])) : '';
        $lines      = explode("\n", $raw_events);
        $lines      = array_map('trim', $lines);
        $lines      = array_filter($lines, function ($v) { return $v !== ''; });
        $lines      = array_unique($lines);
        $lines      = array_values($lines);
        $lines      = array_slice($lines, 0, 5);

        // 現在のルートキー取得
        $existing_keys = $wpdb->get_col($wpdb->prepare(
            "SELECT route_key FROM {$table} WHERE user_id = %d",
            $user_id
        ));

        // 新リストに存在しないルートを削除
        $keys_to_keep = $lines;
        foreach ($existing_keys as $existing_key) {
            if ( ! in_array($existing_key, $keys_to_keep, true) ) {
                $wpdb->delete($table, [
                    'user_id'   => $user_id,
                    'route_key' => $existing_key,
                ], ['%d', '%s']);
            }
        }

        // UPSERT: 各ルートを保存
        foreach ($lines as $index => $event_name) {
            $event_name = sanitize_text_field($event_name);
            if ( $event_name === '' ) {
                continue;
            }
            $wpdb->replace($table, [
                'user_id'    => $user_id,
                'route_key'  => $event_name,
                'label'      => $event_name,
                'enabled'    => 1,
                'sort_order' => $index + 1,
            ], ['%d', '%s', '%s', '%d', '%d']);
        }

        // --- ユーザーメタ ---
        $cv_only_configured = ! empty($_POST['cv_only_configured']) ? '1' : '0';
        update_user_meta($user_id, '_gcrev_cv_only_configured', $cv_only_configured);

        $phone_event_name = isset($_POST['phone_event_name']) ? sanitize_text_field(wp_unslash($_POST['phone_event_name'])) : '';
        update_user_meta($user_id, '_gcrev_phone_event_name', $phone_event_name);

        // 問い合わせAPI 連携 ON/OFF
        $use_inquiries_api = ! empty($_POST['use_inquiries_api_cv']) ? '1' : '0';
        update_user_meta($user_id, '_gcrev_use_inquiries_api_cv', $use_inquiries_api);

        // キャッシュ無効化
        if ( function_exists('gcrev_invalidate_user_cv_cache') ) {
            gcrev_invalidate_user_cv_cache($user_id);
        }

        // PRG リダイレクト
        wp_safe_redirect(add_query_arg([
            'page'        => self::MENU_SLUG,
            'user_id'     => $user_id,
            'action_done' => 'cv_routes_saved',
        ], admin_url('admin.php')));
        exit;
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

        ?>
        <div class="wrap">
            <h1 style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:28px;">📊</span> CV設定（コンバージョン経路管理）
            </h1>

            <?php
            // 成功通知
            if ( ! empty($_GET['action_done']) && sanitize_text_field(wp_unslash($_GET['action_done'])) === 'cv_routes_saved' ) {
                echo '<div class="notice notice-success is-dismissible"><p>CV設定を保存しました。</p></div>';
            }
            ?>

            <!-- ユーザー選択 -->
            <form method="get" style="margin: 20px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                <label for="user_id" style="font-weight:600; margin-right:8px;">ユーザーを選択：</label>
                <select name="user_id" id="user_id" onchange="this.form.submit()" style="min-width:240px;">
                    <option value="">-- 選択してください --</option>
                    <?php
                    $users = get_users(['role__not_in' => ['administrator']]);
                    $selected_user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
                    foreach ($users as $user) :
                    ?>
                        <option value="<?php echo esc_attr((string) $user->ID); ?>"
                                <?php selected($user->ID, $selected_user_id); ?>>
                            <?php echo esc_html( gcrev_get_business_name( $user->ID ) ); ?>（<?php echo esc_html($user->user_email); ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php
            if ($selected_user_id > 0) {
                $this->render_cv_settings_form($selected_user_id);
            }
            ?>
        </div>
        <?php
    }

    // =========================================================
    // CV設定フォーム
    // =========================================================

    /**
     * 指定ユーザーのCV設定フォームを表示
     */
    private function render_cv_settings_form(int $user_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_cv_routes';

        // 現在のルート取得
        $routes = $wpdb->get_results($wpdb->prepare(
            "SELECT route_key, label, enabled, sort_order FROM {$table} WHERE user_id = %d ORDER BY sort_order ASC",
            $user_id
        ));

        // ユーザーメタ取得
        $cv_only_configured = get_user_meta($user_id, '_gcrev_cv_only_configured', true);
        $phone_event_name   = get_user_meta($user_id, '_gcrev_phone_event_name', true);
        $use_inquiries_api  = get_user_meta($user_id, '_gcrev_use_inquiries_api_cv', true);

        // 問い合わせAPI 連携の現状（直近12ヶ月のレコード）
        $inquiries_recent = [];
        $inquiries_endpoint_set = false;
        if ( class_exists( 'Mimamori_Inquiries_Fetcher' ) ) {
            $inquiries_recent = \Mimamori_Inquiries_Fetcher::get_recent( $user_id, 12 );
            $inquiries_endpoint_set = ( \Mimamori_Inquiries_Fetcher::get_endpoint( $user_id ) !== '' );
        }

        // textarea用テキスト生成（route_key を1行ずつ）
        $textarea_value = '';
        if ( ! empty($routes) ) {
            $route_keys     = array_map(function ($r) { return $r->route_key; }, $routes);
            $textarea_value = implode("\n", $route_keys);
        }

        $user_info = get_userdata($user_id);
        ?>
        <hr style="margin: 24px 0;">

        <h2 style="margin-bottom:16px;">
            <?php echo esc_html($user_info ? gcrev_get_business_name( $user_info->ID ) : 'ユーザーID: ' . $user_id); ?> のCV設定
        </h2>

        <form method="post" action="" style="max-width:720px;">
            <?php wp_nonce_field('gcrev_cv_settings_action', '_gcrev_cv_settings_nonce'); ?>
            <input type="hidden" name="gcrev_action" value="save_cv_routes">
            <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr((string) $user_id); ?>">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="cv_events">GA4 CVイベント名</label>
                    </th>
                    <td>
                        <textarea name="cv_events"
                                  id="cv_events"
                                  rows="6"
                                  cols="50"
                                  class="large-text code"
                                  placeholder="1行に1つGA4イベント名を入力&#10;例:&#10;form_submit&#10;contact_complete"><?php echo esc_textarea($textarea_value); ?></textarea>
                        <p class="description">最大5件。1行に1つGA4イベント名を入力してください。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">CV集計対象</th>
                    <td>
                        <label for="cv_only_configured">
                            <input type="checkbox"
                                   name="cv_only_configured"
                                   id="cv_only_configured"
                                   value="1"
                                   <?php checked($cv_only_configured, '1'); ?>>
                            設定済みイベントのみ集計する
                        </label>
                        <p class="description">チェックすると、上記で設定したイベントのみをCV集計対象にします。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="phone_event_name">電話CVイベント名</label>
                    </th>
                    <td>
                        <input type="text"
                               name="phone_event_name"
                               id="phone_event_name"
                               value="<?php echo esc_attr($phone_event_name ?: ''); ?>"
                               class="regular-text"
                               placeholder="例: phone_click">
                        <p class="description">電話クリックとして計上するGA4イベント名を指定します。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">お問い合わせ連携</th>
                    <td>
                        <label for="use_inquiries_api_cv">
                            <input type="checkbox"
                                   name="use_inquiries_api_cv"
                                   id="use_inquiries_api_cv"
                                   value="1"
                                   <?php checked($use_inquiries_api, '1'); ?>
                                   <?php disabled( ! $inquiries_endpoint_set ); ?>>
                            フォーム系CVを「お問い合わせ関連」の有効件数で上書きする
                        </label>
                        <p class="description">
                            <?php if ( ! $inquiries_endpoint_set ) : ?>
                                <span style="color:#b00;">⚠ 先に「お問い合わせ取得」ページでエンドポイントとトークンを登録してください。</span><br>
                            <?php endif; ?>
                            ON にすると、各月のCVのフォーム系（メール/フォーム送信など）を、契約サイトのお問い合わせフォームから取得した「有効お問い合わせ件数」（SPAM・テスト・営業を除外したもの）に置き換えます。<strong>電話タップ系は引き続き GA4 から取得</strong>します。<br>
                            該当月のレコードが未取得の場合は GA4 ベースの値にフォールバックします。
                        </p>

                        <?php if ( ! empty( $inquiries_recent ) ) : ?>
                            <details style="margin-top:8px;">
                                <summary style="cursor:pointer;">📊 取得済みの月次有効件数（直近12ヶ月）</summary>
                                <table class="widefat striped" style="max-width:520px; margin-top:8px;">
                                    <thead>
                                        <tr><th>期間</th><th>合計</th><th>有効</th><th>除外</th><th>取得日時</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( array_reverse( $inquiries_recent ) as $row ) : ?>
                                            <tr>
                                                <td><?php echo esc_html( (string) $row['year_month'] ); ?></td>
                                                <td><?php echo esc_html( (string) (int) $row['total'] ); ?></td>
                                                <td><strong><?php echo esc_html( (string) (int) $row['valid_count'] ); ?></strong></td>
                                                <td><?php echo esc_html( (string) (int) $row['excluded'] ); ?></td>
                                                <td style="font-size:12px;color:#666;"><?php echo esc_html( (string) $row['fetched_at'] ); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button('設定を保存'); ?>
        </form>

        <?php
        // 現在のルート一覧テーブル
        if ( ! empty($routes) ) :
        ?>
        <hr style="margin: 32px 0;">

        <h3 style="font-size:16px; color:#1e293b;">現在の登録ルート</h3>
        <table class="widefat striped" style="max-width:720px; margin-top:12px;">
            <thead>
                <tr>
                    <th style="width:60px;">順序</th>
                    <th>ルートキー</th>
                    <th>ラベル</th>
                    <th style="width:80px;">有効</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($routes as $route) : ?>
                <tr>
                    <td><?php echo esc_html((string) $route->sort_order); ?></td>
                    <td><code><?php echo esc_html($route->route_key); ?></code></td>
                    <td><?php echo esc_html($route->label); ?></td>
                    <td>
                        <?php if ( (int) $route->enabled === 1 ) : ?>
                            <span style="color:#059669; font-weight:600;">有効</span>
                        <?php else : ?>
                            <span style="color:#94a3b8;">無効</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        endif;
    }
}
