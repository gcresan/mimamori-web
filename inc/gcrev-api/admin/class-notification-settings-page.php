<?php
// FILE: inc/gcrev-api/admin/class-notification-settings-page.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Notification_Settings_Page' ) ) { return; }

/**
 * Gcrev_Notification_Settings_Page
 *
 * 管理画面「みまもりウェブ > 通知設定」ページ。
 * Cron エラー通知の有効/無効、送信先、閾値を設定する。
 *
 * @package GCREV_INSIGHT
 * @since   3.0.0
 */
class Gcrev_Notification_Settings_Page {

    private const MENU_SLUG    = 'gcrev-notification-settings';
    private const OPTION_GROUP = 'gcrev_notification_settings_group';
    private const SECTION_ID   = 'gcrev_notification_section';

    /**
     * フック登録
     */
    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'handle_test_notification' ] );
    }

    // =========================================================
    // メニュー登録
    // =========================================================

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
            '通知設定 - みまもりウェブ',
            "\xF0\x9F\x94\x94 通知設定",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================
    // Settings API
    // =========================================================

    public function register_settings(): void {
        register_setting( self::OPTION_GROUP, 'gcrev_notify_enabled', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) { return $v === '1' ? '1' : '0'; },
            'default'           => '0',
        ] );

        register_setting( self::OPTION_GROUP, 'gcrev_notify_recipient', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => '',
        ] );

        register_setting( self::OPTION_GROUP, 'gcrev_notify_error_threshold', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ] );

        add_settings_section(
            self::SECTION_ID,
            'Cron エラー通知',
            static function () {
                echo '<p>Cronジョブでエラーが発生した際にメールで通知します。</p>';
            },
            self::MENU_SLUG
        );

        add_settings_field(
            'gcrev_notify_enabled',
            '通知を有効にする',
            [ $this, 'render_enabled_field' ],
            self::MENU_SLUG,
            self::SECTION_ID
        );

        add_settings_field(
            'gcrev_notify_recipient',
            '送信先メールアドレス',
            [ $this, 'render_recipient_field' ],
            self::MENU_SLUG,
            self::SECTION_ID
        );

        add_settings_field(
            'gcrev_notify_error_threshold',
            'エラー閾値',
            [ $this, 'render_threshold_field' ],
            self::MENU_SLUG,
            self::SECTION_ID
        );
    }

    // =========================================================
    // フィールド描画
    // =========================================================

    public function render_enabled_field(): void {
        $val = get_option( 'gcrev_notify_enabled', '0' );
        echo '<label>';
        echo '<input type="checkbox" name="gcrev_notify_enabled" value="1" ' . checked( $val, '1', false ) . ' />';
        echo ' 有効';
        echo '</label>';
    }

    public function render_recipient_field(): void {
        $val     = get_option( 'gcrev_notify_recipient', '' );
        $default = get_option( 'admin_email', '' );
        echo '<input type="email" name="gcrev_notify_recipient" value="' . esc_attr( $val ) . '" class="regular-text" />';
        echo '<p class="description">空欄の場合はサイト管理者メール (' . esc_html( $default ) . ') に送信されます。</p>';
    }

    public function render_threshold_field(): void {
        $val = (int) get_option( 'gcrev_notify_error_threshold', 1 );
        echo '<input type="number" name="gcrev_notify_error_threshold" value="' . esc_attr( (string) $val ) . '" min="1" max="100" class="small-text" />';
        echo '<p class="description">エラー数がこの値以上のとき通知します。</p>';
    }

    // =========================================================
    // テスト送信
    // =========================================================

    public function handle_test_notification(): void {
        if ( ! isset( $_POST['gcrev_test_notification'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'gcrev_test_notification_nonce' );

        if ( class_exists( 'Gcrev_Error_Notifier' ) ) {
            $sent = Gcrev_Error_Notifier::send_test();
            if ( $sent ) {
                add_settings_error( 'gcrev_notification', 'test_sent', 'テスト通知を送信しました。', 'success' );
            } else {
                add_settings_error( 'gcrev_notification', 'test_failed', 'テスト通知の送信に失敗しました。メール設定を確認してください。', 'error' );
            }
        }
    }

    // =========================================================
    // ページ描画
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>みまもりウェブ — 通知設定</h1>

            <?php settings_errors( 'gcrev_notification' ); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::MENU_SLUG );
                submit_button( '設定を保存' );
                ?>
            </form>

            <hr />

            <h2>テスト送信</h2>
            <p>現在の設定でテスト通知メールを送信します。</p>
            <form method="post">
                <?php wp_nonce_field( 'gcrev_test_notification_nonce' ); ?>
                <input type="hidden" name="gcrev_test_notification" value="1" />
                <?php submit_button( 'テスト通知を送信', 'secondary' ); ?>
            </form>
        </div>
        <?php
    }
}
