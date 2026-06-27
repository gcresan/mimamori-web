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
        add_action( 'admin_init', [ $this, 'handle_test_mimamori_notification' ] );
        add_action( 'admin_init', [ $this, 'handle_test_mimamori_real' ] );
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
        // レポート生成完了通知セクション
        // ==========================================================
        $report_section = 'gcrev_report_notify_section';

        register_setting( self::OPTION_GROUP, 'gcrev_report_notify_enabled', [
            'type'              => 'string',
            'sanitize_callback' => static function ( $v ) { return $v === '1' ? '1' : '0'; },
            'default'           => '1',
        ] );
        register_setting( self::OPTION_GROUP, 'gcrev_report_notify_recipient', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => '',
        ] );

        add_settings_section(
            $report_section,
            'レポート生成完了通知',
            static function () {
                echo '<p>毎月1日に月次レポートの自動生成が完了した際、指定のメールアドレスに完了通知を送信します。</p>';
            },
            self::MENU_SLUG
        );

        add_settings_field(
            'gcrev_report_notify_enabled',
            '通知を有効にする',
            [ $this, 'render_report_notify_enabled_field' ],
            self::MENU_SLUG,
            $report_section
        );
        add_settings_field(
            'gcrev_report_notify_recipient',
            '送信先メールアドレス',
            [ $this, 'render_report_notify_recipient_field' ],
            self::MENU_SLUG,
            $report_section
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

        // ==========================================================
        // みまもりアラート設定セクション（クライアント向け自動通知）
        // ==========================================================
        $alert_section = 'gcrev_mimamori_alert_section';

        register_setting( self::OPTION_GROUP, 'mimamori_alert_settings', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_alert_settings' ],
            'default'           => [],
        ] );

        add_settings_section(
            $alert_section,
            'みまもりアラート設定',
            static function () {
                echo '<p>クライアント向けの自動通知（みまもりアラート／週次便／AI改善提案）の閾値・上限を設定します。空欄はデフォルト値が使われます。</p>';
            },
            self::MENU_SLUG
        );

        add_settings_field(
            'mimamori_alert_settings',
            '閾値・上限',
            [ $this, 'render_alert_settings_fields' ],
            self::MENU_SLUG,
            $alert_section
        );
    }

    /**
     * みまもりアラート設定のサニタイズ。
     */
    public function sanitize_alert_settings( $input ): array {
        if ( ! is_array( $input ) ) { return []; }
        $out = [];
        // 負値を許容する項目（急減閾値）と正の整数のみの項目を分けて処理
        if ( isset( $input['drop_threshold_pct'] ) && $input['drop_threshold_pct'] !== '' ) {
            $out['drop_threshold_pct'] = max( -100, min( 0, (int) $input['drop_threshold_pct'] ) );
        }
        foreach ( [ 'surge_threshold_pct', 'min_weekly_sessions', 'cv_stall_days', 'cv_lookback_days',
                    'ssl_warn_days', 'cooldown_days', 'weekly_alert_limit',
                    'suggest_monthly_max', 'suggest_dedup_days' ] as $key ) {
            if ( isset( $input[ $key ] ) && $input[ $key ] !== '' ) {
                $out[ $key ] = absint( $input[ $key ] );
            }
        }
        return $out;
    }

    // =========================================================
    // フィールド描画
    // =========================================================

    /**
     * みまもりアラート設定の入力フィールド群。
     */
    public function render_alert_settings_fields(): void {
        $module = dirname( __DIR__ ) . '/modules/class-mimamori-notification-service.php';
        if ( ! class_exists( 'Mimamori_Notification_Service' ) && file_exists( $module ) ) {
            require_once $module;
        }
        $defaults = class_exists( 'Mimamori_Notification_Service' )
            ? Mimamori_Notification_Service::get_settings()
            : [];
        $saved = get_option( 'mimamori_alert_settings', [] );
        $saved = is_array( $saved ) ? $saved : [];

        $fields = [
            'drop_threshold_pct'  => 'アクセス急減の閾値（前週比%・負の値）',
            'surge_threshold_pct' => 'アクセス急増の閾値（前週比%）',
            'min_weekly_sessions' => '母数条件: 前週の最低訪問数',
            'cv_stall_days'       => 'CV停滞の判定日数',
            'cv_lookback_days'    => 'CV停滞の実績参照日数',
            'ssl_warn_days'       => 'SSL期限警告の残日数',
            'cooldown_days'       => '同一アラートのクールダウン（日）',
            'weekly_alert_limit'  => '週あたり通知上限（通）',
            'suggest_monthly_max' => 'AI改善提案の月間上限（通）',
            'suggest_dedup_days'  => '同一提案の再送禁止期間（日）',
        ];

        echo '<table class="form-table" role="presentation" style="margin:0;">';
        foreach ( $fields as $key => $label ) {
            $value       = $saved[ $key ] ?? '';
            $placeholder = isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
            echo '<tr>';
            echo '<td style="padding:4px 8px 4px 0;">' . esc_html( $label ) . '</td>';
            echo '<td style="padding:4px 0;"><input type="number" name="mimamori_alert_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" class="small-text" /></td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<p class="description">空欄の項目はデフォルト値（プレースホルダー表示）が使われます。</p>';
    }

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
    // レポート生成完了通知フィールド
    // =========================================================

    public function render_report_notify_enabled_field(): void {
        $val = get_option( 'gcrev_report_notify_enabled', '1' );
        echo '<label>';
        echo '<input type="checkbox" name="gcrev_report_notify_enabled" value="1" ' . checked( $val, '1', false ) . ' />';
        echo ' 有効';
        echo '</label>';
    }

    public function render_report_notify_recipient_field(): void {
        $val      = get_option( 'gcrev_report_notify_recipient', '' );
        $fallback = get_option( 'gcrev_notify_recipient', '' );
        $default  = $fallback !== '' ? $fallback : get_option( 'admin_email', '' );
        echo '<input type="email" name="gcrev_report_notify_recipient" value="' . esc_attr( $val ) . '" class="regular-text" />';
        echo '<p class="description">空欄の場合は ' . esc_html( $default ) . ' に送信されます。</p>';
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

    /**
     * みまもり通知（アラート / 週次便 / AI改善提案）のテスト送信ハンドラ。
     * ダミーデータで本文レイアウトを確認する用途。
     */
    public function handle_test_mimamori_notification(): void {
        if ( ! isset( $_POST['gcrev_test_mimamori_notify'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'gcrev_test_mimamori_notify_nonce' );

        $kind = isset( $_POST['mimamori_test_kind'] ) ? sanitize_text_field( wp_unslash( $_POST['mimamori_test_kind'] ) ) : '';
        if ( ! in_array( $kind, [ 'alert', 'digest', 'suggest', 'report' ], true ) ) {
            add_settings_error( 'gcrev_notification', 'mm_test_kind', '通知種別を選択してください。', 'error' );
            return;
        }

        // 送信先: 未入力・不正ならログイン中の管理者宛
        $recipient = isset( $_POST['mimamori_test_recipient'] ) ? sanitize_email( wp_unslash( $_POST['mimamori_test_recipient'] ) ) : '';
        if ( ! is_email( $recipient ) ) {
            $current   = wp_get_current_user();
            $recipient = ( $current && is_email( $current->user_email ) ) ? $current->user_email : get_option( 'admin_email' );
        }

        $with_analysis = ( isset( $_POST['mimamori_test_plan'] ) && $_POST['mimamori_test_plan'] === 'facts' ) ? false : true;

        $module = dirname( __DIR__ ) . '/modules/class-mimamori-notification-service.php';
        if ( ! class_exists( 'Mimamori_Notification_Service' ) && file_exists( $module ) ) {
            require_once $module;
        }
        if ( ! class_exists( 'Mimamori_Notification_Service' ) ) {
            add_settings_error( 'gcrev_notification', 'mm_test_nocls', '通知サービスが見つかりません。', 'error' );
            return;
        }

        $service = new Mimamori_Notification_Service();
        $result  = $service->send_test_email( $kind, $recipient, [
            'with_analysis' => $with_analysis,
            'link_uid'      => get_current_user_id(),
        ] );

        add_settings_error(
            'gcrev_notification',
            'mm_test_result',
            $result['message'],
            $result['ok'] ? 'success' : 'error'
        );
    }

    /**
     * みまもり通知（アラート / 週次便 / AI改善提案）の実データテスト送信ハンドラ。
     * 選択した対象クライアントの実データから本番同等の本文を組み立て、
     * 送信先だけ指定アドレスに差し替えて送る（送信履歴・上限は更新しない）。
     */
    public function handle_test_mimamori_real(): void {
        if ( ! isset( $_POST['gcrev_test_mimamori_real'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'gcrev_test_mimamori_real_nonce' );

        $kind = isset( $_POST['mm_real_kind'] ) ? sanitize_text_field( wp_unslash( $_POST['mm_real_kind'] ) ) : '';
        if ( ! in_array( $kind, [ 'alert', 'digest', 'suggest', 'report' ], true ) ) {
            add_settings_error( 'gcrev_notification', 'mm_real_kind', '通知種別を選択してください。', 'error' );
            return;
        }

        $target_uid = isset( $_POST['mm_real_target'] ) ? absint( $_POST['mm_real_target'] ) : 0;
        if ( $target_uid <= 0 ) {
            add_settings_error( 'gcrev_notification', 'mm_real_target', '対象クライアントを選択してください。', 'error' );
            return;
        }

        // 送信先: 未入力・不正ならログイン中の管理者宛
        $recipient = isset( $_POST['mm_real_recipient'] ) ? sanitize_email( wp_unslash( $_POST['mm_real_recipient'] ) ) : '';
        if ( ! is_email( $recipient ) ) {
            $current   = wp_get_current_user();
            $recipient = ( $current && is_email( $current->user_email ) ) ? $current->user_email : get_option( 'admin_email' );
        }

        $module = dirname( __DIR__ ) . '/modules/class-mimamori-notification-service.php';
        if ( ! class_exists( 'Mimamori_Notification_Service' ) && file_exists( $module ) ) {
            require_once $module;
        }
        if ( ! class_exists( 'Mimamori_Notification_Service' ) ) {
            add_settings_error( 'gcrev_notification', 'mm_real_nocls', '通知サービスが見つかりません。', 'error' );
            return;
        }

        $service = new Mimamori_Notification_Service();
        $result  = $service->send_real_test_email( $kind, $target_uid, $recipient );

        add_settings_error(
            'gcrev_notification',
            'mm_real_result',
            $result['message'],
            $result['ok'] ? 'success' : 'error'
        );
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

                <?php // ── レポート生成完了通知セクション ── ?>
                <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:16px 24px 24px; margin-bottom:30px;">
                    <?php self::render_single_section( self::MENU_SLUG, 'gcrev_report_notify_section' ); ?>
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

            <?php // ── みまもり通知 テスト送信（アラート / 週次便 / AI改善提案） ── ?>
            <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:16px 24px 24px; margin-top:10px;">
                <h2 style="margin-top:0;">みまもり通知 テスト送信</h2>
                <p>みまもりアラート・週次便・AI改善提案・月次レポート完成通知の各メールを、<strong>ダミーデータ</strong>でテスト送信します（実データやAI分析は含まれません。文面・レイアウトの確認用です）。</p>
                <form method="post">
                    <?php wp_nonce_field( 'gcrev_test_mimamori_notify_nonce' ); ?>
                    <input type="hidden" name="gcrev_test_mimamori_notify" value="1" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="mimamori_test_kind">通知種別</label></th>
                            <td>
                                <select name="mimamori_test_kind" id="mimamori_test_kind">
                                    <option value="alert">みまもりアラート（異常検知）</option>
                                    <option value="digest">みまもり週次便</option>
                                    <option value="suggest">AI改善提案通知</option>
                                    <option value="report">月次レポート完成通知</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mimamori_test_plan">プラン別の見え方</label></th>
                            <td>
                                <select name="mimamori_test_plan" id="mimamori_test_plan">
                                    <option value="analysis">AI改善提案プラン以上（分析・AIチャット導線あり）</option>
                                    <option value="facts">見える化プラン（事実のみ・アップグレード案内）</option>
                                </select>
                                <p class="description">みまもりアラート・週次便で本文が変わります。AI改善提案は常にAI改善提案プラン向けの内容です。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mimamori_test_recipient">送信先メールアドレス</label></th>
                            <td>
                                <input type="email" name="mimamori_test_recipient" id="mimamori_test_recipient"
                                       value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" />
                                <p class="description">空欄または不正な場合は、ログイン中の管理者メール宛に送信します。</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'みまもり通知をテスト送信', 'secondary' ); ?>
                </form>
            </div>

            <?php
            // ── みまもり通知 実データ テスト送信（顧客を選択） ──
            $mm_module = dirname( __DIR__ ) . '/modules/class-mimamori-notification-service.php';
            if ( ! class_exists( 'Mimamori_Notification_Service' ) && file_exists( $mm_module ) ) {
                require_once $mm_module;
            }
            $mm_targets = [];
            if ( class_exists( 'Mimamori_Notification_Service' ) ) {
                $mm_service = new Mimamori_Notification_Service();
                $mm_targets = $mm_service->get_test_target_users();
            }
            $mm_admin_email = wp_get_current_user()->user_email;
            ?>
            <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:16px 24px 24px; margin-top:20px;">
                <h2 style="margin-top:0;">みまもり通知 実データ テスト送信（顧客を選択）</h2>
                <p>選択した<strong>クライアントの実データ</strong>から本番と同じ本文を組み立て、指定アドレスに送信します。送信先だけ差し替えるため、<strong>クライアント本人には届きません</strong>。送信履歴・上限カウントは更新しません。プラン別の見え方は対象クライアントの実際のプランに従います。</p>
                <ul style="margin:0 0 12px 1.2em; color:#646970; font-size:13px; list-style:disc;">
                    <li><strong>みまもり週次便</strong>: いつでも送信できます（実際の訪問数・お問い合わせ数が入ります）。</li>
                    <li><strong>みまもりアラート</strong>: 実データ上で異常が検知されている顧客のみ送信できます（異常がなければ送信されません）。</li>
                    <li><strong>AI改善提案</strong>: AI（Gemini）を呼び出すため数秒かかり、AI改善提案プラン以上かつ「優先度: 高」の提案がある場合のみ送信されます。</li>
                    <li><strong>月次レポート完成通知</strong>: いつでも送信できます（前月分を対象に、実際のレポート公開時と同じ文面で届きます）。</li>
                </ul>
                <?php if ( empty( $mm_targets ) ) : ?>
                    <p style="color:#b32d2e;">対象クライアントが見つかりません（自動通知の対象ユーザーが存在しません）。</p>
                <?php else : ?>
                <form method="post">
                    <?php wp_nonce_field( 'gcrev_test_mimamori_real_nonce' ); ?>
                    <input type="hidden" name="gcrev_test_mimamori_real" value="1" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="mm_real_kind">通知種別</label></th>
                            <td>
                                <select name="mm_real_kind" id="mm_real_kind">
                                    <option value="digest">みまもり週次便</option>
                                    <option value="alert">みまもりアラート（異常検知）</option>
                                    <option value="suggest">AI改善提案通知</option>
                                    <option value="report">月次レポート完成通知</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mm_real_target">対象クライアント</label></th>
                            <td>
                                <select name="mm_real_target" id="mm_real_target" class="regular-text">
                                    <?php foreach ( $mm_targets as $t ) : ?>
                                        <option value="<?php echo esc_attr( $t['id'] ); ?>"><?php echo esc_html( $t['label'] ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">このクライアントの実データからメール本文を組み立てます。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mm_real_recipient">送信先メールアドレス</label></th>
                            <td>
                                <input type="email" name="mm_real_recipient" id="mm_real_recipient"
                                       value="<?php echo esc_attr( $mm_admin_email ); ?>" class="regular-text" />
                                <p class="description">空欄または不正な場合は、ログイン中の管理者メール宛に送信します。</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( '実データでテスト送信', 'secondary' ); ?>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
