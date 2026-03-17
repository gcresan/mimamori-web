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
 * @package Mimamori_Web
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

        // ==========================================================
        // お問い合わせメール設定セクション
        // ==========================================================
        $inquiry_section = 'gcrev_inquiry_email_section';

        register_setting( self::OPTION_GROUP, 'gcrev_inquiry_admin_email', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => '',
        ] );
        register_setting( self::OPTION_GROUP, 'gcrev_inquiry_reply_subject', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '【みまもりウェブ】お問い合わせを受け付けました',
        ] );
        register_setting( self::OPTION_GROUP, 'gcrev_inquiry_reply_body', [
            'type'              => 'string',
            'sanitize_callback' => 'wp_kses_post',
            'default'           => '',
        ] );
        register_setting( self::OPTION_GROUP, 'gcrev_inquiry_reply_footer', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ] );

        add_settings_section(
            $inquiry_section,
            'お問い合わせメール設定',
            [ $this, 'render_inquiry_section_description' ],
            self::MENU_SLUG
        );

        add_settings_field(
            'gcrev_inquiry_admin_email',
            '管理者通知の送信先',
            [ $this, 'render_inquiry_admin_email_field' ],
            self::MENU_SLUG,
            $inquiry_section
        );
        add_settings_field(
            'gcrev_inquiry_reply_subject',
            '自動返信メールの件名',
            [ $this, 'render_inquiry_reply_subject_field' ],
            self::MENU_SLUG,
            $inquiry_section
        );
        add_settings_field(
            'gcrev_inquiry_reply_body',
            '自動返信メールの本文',
            [ $this, 'render_inquiry_reply_body_field' ],
            self::MENU_SLUG,
            $inquiry_section
        );
        add_settings_field(
            'gcrev_inquiry_reply_footer',
            '自動返信メールのフッター',
            [ $this, 'render_inquiry_reply_footer_field' ],
            self::MENU_SLUG,
            $inquiry_section
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
    // お問い合わせメール設定フィールド
    // =========================================================

    public function render_inquiry_section_description(): void {
        echo '<p>お問い合わせフォームから送信された際の、管理者通知メールと自動返信メールの設定です。</p>';
    }

    public function render_inquiry_admin_email_field(): void {
        $val     = get_option( 'gcrev_inquiry_admin_email', '' );
        $default = get_option( 'admin_email', '' );
        echo '<input type="email" name="gcrev_inquiry_admin_email" value="' . esc_attr( $val ) . '" class="regular-text" />';
        echo '<p class="description">空欄の場合はWordPress管理者メール (' . esc_html( $default ) . ') に送信されます。</p>';
    }

    public function render_inquiry_reply_subject_field(): void {
        $val = get_option( 'gcrev_inquiry_reply_subject', '【みまもりウェブ】お問い合わせを受け付けました' );
        echo '<input type="text" name="gcrev_inquiry_reply_subject" value="' . esc_attr( $val ) . '" class="regular-text" />';
    }

    public function render_inquiry_reply_body_field(): void {
        $default = "{name} 様\n\nお問い合わせいただきありがとうございます。\n以下の内容で受け付けました。\n\n---\n問い合わせ種別: {type}\nお問い合わせ内容:\n{message}\n---";
        $val = get_option( 'gcrev_inquiry_reply_body', '' );
        if ( empty( $val ) ) {
            $val = $default;
        }
        echo '<textarea name="gcrev_inquiry_reply_body" rows="10" cols="60" class="large-text">' . esc_textarea( $val ) . '</textarea>';
        echo '<p class="description">使用可能なプレースホルダー: <code>{name}</code>（送信者名）、<code>{type}</code>（問い合わせ種別）、<code>{message}</code>（問い合わせ内容）</p>';
    }

    public function render_inquiry_reply_footer_field(): void {
        $default = "内容を確認のうえ、順次ご案内いたします。\n通常2〜3営業日以内にご連絡いたします。";
        $val = get_option( 'gcrev_inquiry_reply_footer', '' );
        if ( empty( $val ) ) {
            $val = $default;
        }
        echo '<textarea name="gcrev_inquiry_reply_footer" rows="4" cols="60" class="large-text">' . esc_textarea( $val ) . '</textarea>';
        echo '<p class="description">本文の後に追加されるフッターテキストです。末尾に「※このメールは自動送信です。みまもりウェブ」が自動付与されます。</p>';
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
    // セクション個別描画ヘルパー
    // =========================================================

    /**
     * 指定セクションだけを描画する（do_settings_sections の単一セクション版）。
     *
     * @param string $page    ページスラッグ
     * @param string $section セクションID
     */
    private static function render_single_section( string $page, string $section ): void {
        global $wp_settings_sections, $wp_settings_fields;

        if ( ! isset( $wp_settings_sections[ $page ][ $section ] ) ) {
            return;
        }
        $sec = $wp_settings_sections[ $page ][ $section ];

        if ( $sec['title'] ) {
            echo '<h2>' . esc_html( $sec['title'] ) . '</h2>';
        }
        if ( $sec['callback'] ) {
            call_user_func( $sec['callback'], $sec );
        }

        if ( ! isset( $wp_settings_fields[ $page ][ $section ] ) ) {
            return;
        }
        echo '<table class="form-table" role="presentation">';
        do_settings_fields( $page, $section );
        echo '</table>';
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
                <?php settings_fields( self::OPTION_GROUP ); ?>

                <?php // ── Cronエラー通知セクション ── ?>
                <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:16px 24px 24px; margin-bottom:30px;">
                    <?php self::render_single_section( self::MENU_SLUG, self::SECTION_ID ); ?>

                    <hr style="margin:24px 0 16px;" />
                    <h3 style="margin:0 0 8px;">テスト送信</h3>
                    <p style="margin:0 0 8px;">現在の設定でテスト通知メールを送信します。</p>
                </div>

                <?php // ── お問い合わせメール設定セクション ── ?>
                <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:16px 24px 24px;">
                    <?php self::render_single_section( self::MENU_SLUG, 'gcrev_inquiry_email_section' ); ?>
                </div>

                <?php submit_button( '設定を保存' ); ?>
            </form>

            <?php // テスト送信は別フォーム（nonce別） ?>
            <form method="post" style="margin-top:-20px;">
                <?php wp_nonce_field( 'gcrev_test_notification_nonce' ); ?>
                <input type="hidden" name="gcrev_test_notification" value="1" />
                <?php submit_button( 'Cronエラー テスト通知を送信', 'secondary' ); ?>
            </form>
        </div>
        <?php
    }
}
