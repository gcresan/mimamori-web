<?php
// FILE: inc/gcrev-api/admin/class-payment-settings-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Payment_Settings_Page') ) { return; }

/**
 * Gcrev_Payment_Settings_Page
 *
 * WordPress管理画面に「GCREV INSIGHT > 決済設定」ページを追加する。
 * - 5パターンの決済URLを wp_options に保存
 * - ユーザー別の契約タイプ・決済ステータス一覧を表示
 *
 * option_name（5パターン）:
 *   gcrev_url_installment_1y       — 分割払い決済URL（1年プラン）
 *   gcrev_url_installment_2y       — 分割払い決済URL（2年プラン）
 *   gcrev_url_subscribe_1y         — サブスク決済URL（1年プラン）
 *   gcrev_url_subscribe_2y_normal  — サブスク決済URL（2年通常）
 *   gcrev_url_subscribe_monitor    — サブスク決済URL（モニター）
 *
 * @package GCREV_INSIGHT
 */
class Gcrev_Payment_Settings_Page {

    /** メニュースラッグ */
    private const MENU_SLUG = 'gcrev-payment-settings';

    /** オプショングループ（Settings API用） */
    private const OPTION_GROUP = 'gcrev_payment_settings_group';

    /** 5つの決済URLオプション定義 */
    private const URL_OPTIONS = [
        'gcrev_url_installment_1y' => [
            'title'       => 'A) 分割払い決済URL（1年プラン）',
            'description' => '制作込み1年プランの初回分割払い決済ページURL',
            'placeholder' => 'https://example.com/pay/installment-1y',
        ],
        'gcrev_url_installment_2y' => [
            'title'       => 'B) 分割払い決済URL（2年プラン）',
            'description' => '制作込み2年プランの初回分割払い決済ページURL',
            'placeholder' => 'https://example.com/pay/installment-2y',
        ],
        'gcrev_url_subscribe_1y' => [
            'title'       => 'C) サブスク決済URL（1年プラン）',
            'description' => '1年プラン満了後の月額サブスクリプション決済URL',
            'placeholder' => 'https://example.com/pay/subscribe-1y',
        ],
        'gcrev_url_subscribe_2y_normal' => [
            'title'       => 'D) サブスク決済URL（2年・通常）',
            'description' => '2年プラン満了後（通常）の月額サブスクリプション決済URL',
            'placeholder' => 'https://example.com/pay/subscribe-2y',
        ],
        'gcrev_url_subscribe_monitor' => [
            'title'       => 'E) サブスク決済URL（モニター）',
            'description' => 'モニタープラン用の月額サブスクリプション決済URL（主に2年プラン側で使用）',
            'placeholder' => 'https://example.com/pay/subscribe-monitor',
        ],
    ];

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

    public function add_menu_page(): void {
        if ( empty( $GLOBALS['admin_page_hooks']['gcrev-insight'] ) ) {
            add_menu_page(
                'GCREV INSIGHT',
                'GCREV INSIGHT',
                'manage_options',
                'gcrev-insight',
                '__return_null',
                'dashicons-chart-area',
                30
            );
        }

        add_submenu_page(
            'gcrev-insight',
            '決済設定 - GCREV INSIGHT',
            '💳 決済設定',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================
    // Settings API 登録
    // =========================================================

    public function register_settings(): void {
        // 5つのURLオプションを登録
        foreach ( self::URL_OPTIONS as $option_name => $meta ) {
            register_setting(self::OPTION_GROUP, $option_name, [
                'type'              => 'string',
                'sanitize_callback' => 'esc_url_raw',
                'default'           => '',
            ]);
        }

        // 分割払いURLセクション
        add_settings_section(
            'gcrev_installment_urls_section',
            '分割払い決済URL',
            function () {
                echo '<p style="color:#64748b;">制作込みプランの初回分割払い決済ページURLです。申込完了画面・支払い手続き画面のStep1で使用します。</p>';
            },
            self::MENU_SLUG
        );

        // サブスクURLセクション
        add_settings_section(
            'gcrev_subscribe_urls_section',
            'サブスクリプション決済URL',
            function () {
                echo '<p style="color:#64748b;">月額サブスク決済のURL（将来用に保持）。現在は支払い手続き画面にボタン表示しません。</p>';
            },
            self::MENU_SLUG
        );

        // 各フィールドを登録
        foreach ( self::URL_OPTIONS as $option_name => $meta ) {
            $section = ( strpos($option_name, 'installment') !== false )
                ? 'gcrev_installment_urls_section'
                : 'gcrev_subscribe_urls_section';

            add_settings_field(
                $option_name,
                $meta['title'],
                [ $this, 'render_url_field' ],
                self::MENU_SLUG,
                $section,
                [ 'option_name' => $option_name, 'meta' => $meta ]
            );
        }
    }

    /**
     * 汎用URLフィールドレンダラー
     */
    public function render_url_field( array $args ): void {
        $option_name = $args['option_name'];
        $meta        = $args['meta'];
        $value       = get_option($option_name, '');
        ?>
        <input type="url"
               name="<?php echo esc_attr($option_name); ?>"
               id="<?php echo esc_attr($option_name); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               placeholder="<?php echo esc_attr($meta['placeholder']); ?>"
               style="width:100%; max-width:560px;">
        <p class="description"><?php echo esc_html($meta['description']); ?></p>
        <?php
    }

    // =========================================================
    // ページレンダリング
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die('権限がありません。');
        }
        ?>
        <div class="wrap">
            <h1 style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:28px;">💳</span> 決済設定
            </h1>

            <?php settings_errors(); ?>

            <!-- 決済URL設定フォーム -->
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::MENU_SLUG);
                submit_button('設定を保存');
                ?>
            </form>

            <hr style="margin: 32px 0;">

            <!-- ユーザー別 決済ステータス一覧 -->
            <div style="max-width:1100px;">
                <h3 style="font-size:16px; color:#1e293b;">👥 ユーザー別 決済ステータス</h3>
                <p style="color:#64748b; font-size:13px; margin-bottom:12px;">
                    各ユーザーのステータスを変更するには「ユーザー &gt; プロフィール編集」画面を開いてください。
                </p>
                <?php $this->render_user_status_table(); ?>
            </div>
        </div>
        <?php
    }

    // =========================================================
    // ユーザーステータス一覧テーブル
    // =========================================================

    private function render_user_status_table(): void {
        $users = get_users([
            'role__not_in' => ['administrator'],
            'orderby'      => 'registered',
            'order'        => 'DESC',
            'fields'       => ['ID', 'display_name', 'user_email', 'user_registered'],
        ]);

        if ( empty($users) ) {
            echo '<p style="color:#94a3b8; margin-top:8px;">対象ユーザーはまだいません。</p>';
            return;
        }

        $plan_defs = function_exists('gcrev_get_plan_definitions') ? gcrev_get_plan_definitions() : [];

        $contract_labels = [
            'with_site'    => [ 'label' => '制作込み',   'color' => '#1d4ed8', 'bg' => '#eff6ff' ],
            'insight_only' => [ 'label' => '運用のみ',   'color' => '#059669', 'bg' => '#ecfdf5' ],
        ];
        $term_labels = [ '1y' => '1年', '2y' => '2年' ];
        $sub_labels  = [ 'normal' => '通常', 'monitor' => 'モニター' ];
        ?>
        <table class="widefat striped" style="max-width:1100px; margin-top:12px; font-size:13px;">
            <thead>
                <tr>
                    <th style="width:120px;">ユーザー</th>
                    <th>メール</th>
                    <th style="width:80px;">契約</th>
                    <th style="width:80px;">契約プラン</th>
                    <th style="width:70px;">サブスク</th>
                    <th style="width:60px;">初回</th>
                    <th style="width:60px;">月額</th>
                    <th style="width:70px;">総合</th>
                    <th style="width:50px;">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user):
                $uid               = (int) $user->ID;
                $contract_type     = function_exists('gcrev_get_contract_type') ? gcrev_get_contract_type($uid) : 'with_site';
                $plan_term         = function_exists('gcrev_get_initial_plan_term') ? gcrev_get_initial_plan_term($uid) : '2y';
                $sub_type          = function_exists('gcrev_get_subscription_type') ? gcrev_get_subscription_type($uid) : 'normal';
                $init_done         = ( get_user_meta($uid, 'gcrev_initial_payment_completed', true) === '1' );
                $sub_done          = ( get_user_meta($uid, 'gcrev_subscription_payment_completed', true) === '1' );
                $is_paid           = function_exists('gcrev_is_payment_active') ? gcrev_is_payment_active($uid) : false;
                $ct_info           = isset($contract_labels[$contract_type]) ? $contract_labels[$contract_type] : $contract_labels['with_site'];
            ?>
                <tr>
                    <td><strong><?php echo esc_html($user->display_name); ?></strong></td>
                    <td><?php echo esc_html($user->user_email); ?></td>
                    <td>
                        <span style="display:inline-block; padding:1px 6px; border-radius:8px; font-size:11px; font-weight:600;
                                     color:<?php echo esc_attr($ct_info['color']); ?>;
                                     background:<?php echo esc_attr($ct_info['bg']); ?>;">
                            <?php echo esc_html($ct_info['label']); ?>
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <?php if ( $contract_type === 'insight_only' ): ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php else: ?>
                            <?php echo esc_html($term_labels[$plan_term] ?? $plan_term); ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php
                        // サブスク金額表示ロジック
                        if ( $contract_type === 'with_site' ) {
                            // 制作込み: 1年→¥16,500 / 2年→¥12,100
                            $sub_price = ( $plan_term === '1y' ) ? '¥16,500/月' : '¥12,100/月';
                        } else {
                            // 伴走運用のみ: 通常→¥16,500 / モニター→¥11,000
                            if ( $sub_type === 'monitor' ) {
                                $sub_price = '¥11,000/月 <span style="font-size:10px; color:#7c3aed;">(モニター)</span>';
                            } else {
                                $sub_price = '¥16,500/月';
                            }
                        }
                        echo wp_kses( $sub_price, [ 'span' => [ 'style' => [] ] ] );
                        ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ( $contract_type === 'with_site' ): ?>
                            <?php echo $init_done ? '<span style="color:#059669;">✅</span>' : '<span style="color:#dc2626;">—</span>'; ?>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php echo $sub_done ? '<span style="color:#059669;">✅</span>' : '<span style="color:#dc2626;">—</span>'; ?>
                    </td>
                    <td>
                        <?php if ($is_paid): ?>
                        <span style="display:inline-block; padding:1px 6px; border-radius:8px; font-size:11px; font-weight:600; color:#059669; background:#ecfdf5;">利用中</span>
                        <?php else: ?>
                        <span style="display:inline-block; padding:1px 6px; border-radius:8px; font-size:11px; font-weight:600; color:#dc2626; background:#fef2f2;">手続中</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url(get_edit_user_link($uid)); ?>#gcrev_contract_type"
                           class="button button-small" style="font-size:11px;">編集</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
