<?php
// FILE: inc/gcrev-api/admin/class-screenshot-settings-page.php

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Screenshot_Settings_Page' ) ) { return; }

/**
 * Gcrev_Screenshot_Settings_Page
 *
 * ページ分析の自動キャプチャに使う外部スクショAPI（ScreenshotOne）の設定。
 * アクセスキーは Gcrev_Crypto で暗号化して保存する。
 *
 * 保存先: option 'gcrev_screenshot_settings'（配列）
 *   enabled, access_key(暗号化), pc_width, mobile_width, format
 *
 * @package Mimamori_Web
 */
class Gcrev_Screenshot_Settings_Page {

    private const MENU_SLUG    = 'gcrev-screenshot-settings';
    private const OPTION_GROUP = 'gcrev_screenshot_settings_group';
    private const OPTION_KEY   = 'gcrev_screenshot_settings';
    private const SECTION_ID   = 'gcrev_screenshot_section';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'handle_test' ] );
    }

    public function add_menu_page(): void {
        if ( empty( $GLOBALS['admin_page_hooks']['gcrev-insight'] ) ) {
            add_menu_page( 'みまもりウェブ', 'みまもりウェブ', 'manage_options', 'gcrev-insight', '__return_null', 'dashicons-chart-area', 30 );
        }
        add_submenu_page(
            'gcrev-insight',
            'スクショAPI設定 - みまもりウェブ',
            "\xF0\x9F\x93\xB7 スクショAPI設定",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( self::OPTION_GROUP, self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
            'default'           => [],
        ] );

        add_settings_section(
            self::SECTION_ID,
            'ScreenshotOne 連携（ページ画像の自動キャプチャ）',
            static function () {
                echo '<p>ページ分析の「自動取得」「月次自動キャプチャ」で使う ScreenshotOne のアクセスキーを設定します。';
                echo '<a href="https://screenshotone.com/" target="_blank" rel="noopener">screenshotone.com</a> でアカウント作成後、ダッシュボードの Access key を入力してください。</p>';
                echo '<p class="description">※ wp-config.php に <code>GCREV_SCREENSHOT_API_PC</code> 等を定義している場合はそちらが優先されます（上級者・他社プロバイダ用）。</p>';
            },
            self::MENU_SLUG
        );

        add_settings_field(
            self::OPTION_KEY,
            'ScreenshotOne 設定',
            [ $this, 'render_fields' ],
            self::MENU_SLUG,
            self::SECTION_ID
        );
    }

    /**
     * 保存時のサニタイズ。アクセスキーは暗号化して格納する。
     */
    public function sanitize_settings( $input ): array {
        $existing = get_option( self::OPTION_KEY, [] );
        $existing = is_array( $existing ) ? $existing : [];

        $out = [];
        $out['enabled']         = empty( $input['enabled'] ) ? '0' : '1';
        $out['monthly_enabled'] = empty( $input['monthly_enabled'] ) ? '0' : '1';
        $out['format']       = ( isset( $input['format'] ) && $input['format'] === 'png' ) ? 'png' : 'jpg';
        $out['pc_width']          = max( 320, min( 2560, absint( $input['pc_width'] ?? 1280 ) ?: 1280 ) );
        $out['mobile_width']      = max( 320, min( 1280, absint( $input['mobile_width'] ?? 390 ) ?: 390 ) );
        $out['pc_max_height']     = max( 2000, min( 30000, absint( $input['pc_max_height'] ?? 12000 ) ?: 12000 ) );
        $out['mobile_max_height'] = max( 2000, min( 30000, absint( $input['mobile_max_height'] ?? 20000 ) ?: 20000 ) );
        $out['delay']             = max( 0, min( 15, isset( $input['delay'] ) ? absint( $input['delay'] ) : 3 ) );

        // アクセスキー: 削除指定 → 空 / 新規入力（マスクでない）→ 暗号化 / それ以外 → 既存維持
        if ( ! empty( $input['clear_key'] ) ) {
            $out['access_key'] = '';
        } else {
            $new = isset( $input['access_key'] ) ? trim( wp_unslash( (string) $input['access_key'] ) ) : '';
            if ( $new !== '' && strpos( $new, '*' ) === false ) {
                $out['access_key'] = class_exists( 'Gcrev_Crypto' ) ? Gcrev_Crypto::encrypt( $new ) : $new;
            } else {
                $out['access_key'] = (string) ( $existing['access_key'] ?? '' );
            }
        }

        return $out;
    }

    public function render_fields(): void {
        $s = get_option( self::OPTION_KEY, [] );
        $s = is_array( $s ) ? $s : [];

        $enabled = ! empty( $s['enabled'] );
        $monthly = array_key_exists( 'monthly_enabled', $s ) ? ! empty( $s['monthly_enabled'] ) : true; // 既定ON
        $format  = ( ( $s['format'] ?? 'jpg' ) === 'png' ) ? 'png' : 'jpg';
        $pc_w    = (int) ( $s['pc_width'] ?? 1280 ) ?: 1280;
        $sp_w    = (int) ( $s['mobile_width'] ?? 390 ) ?: 390;
        $pc_h    = (int) ( $s['pc_max_height'] ?? 12000 ) ?: 12000;
        $sp_h    = (int) ( $s['mobile_max_height'] ?? 20000 ) ?: 20000;
        $delay   = isset( $s['delay'] ) ? (int) $s['delay'] : 3;

        // 既存キーのマスク表示（復号して先頭4＋末尾4のみ）
        $key_mask = '';
        $key_enc  = (string) ( $s['access_key'] ?? '' );
        if ( $key_enc !== '' ) {
            $plain = class_exists( 'Gcrev_Crypto' ) ? Gcrev_Crypto::decrypt( $key_enc ) : $key_enc;
            $key_mask = strlen( $plain ) > 8
                ? substr( $plain, 0, 4 ) . str_repeat( '*', 8 ) . substr( $plain, -4 )
                : str_repeat( '*', 8 );
        }

        $n = self::OPTION_KEY;
        echo '<table class="form-table" role="presentation" style="margin:0;">';

        echo '<tr><th>有効化</th><td><label>';
        echo '<input type="hidden" name="' . esc_attr( $n ) . '[enabled]" value="0" />';
        echo '<input type="checkbox" name="' . esc_attr( $n ) . '[enabled]" value="1" ' . checked( $enabled, true, false ) . ' /> 自動キャプチャを有効にする';
        echo '</label><p class="description">オフにすると、月次の自動キャプチャも手動の取得ボタンも使えなくなります（手動アップロードは可）。</p></td></tr>';

        echo '<tr><th>毎月の自動キャプチャ</th><td><label>';
        echo '<input type="hidden" name="' . esc_attr( $n ) . '[monthly_enabled]" value="0" />';
        echo '<input type="checkbox" name="' . esc_attr( $n ) . '[monthly_enabled]" value="1" ' . checked( $monthly, true, false ) . ' /> 毎月1日に自動でキャプチャを取得する';
        echo '</label><p class="description">オフにすると<strong>毎月の自動取得だけ</strong>を停止します（画面の「おすすめページを自動設定」「PC・スマホを自動取得」ボタンは引き続き使えます）。</p></td></tr>';

        echo '<tr><th>アクセスキー</th><td>';
        echo '<input type="text" name="' . esc_attr( $n ) . '[access_key]" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr( $key_mask !== '' ? '設定済み: ' . $key_mask . '（変更する場合のみ入力）' : 'ScreenshotOne の Access key を入力' ) . '" />';
        if ( $key_mask !== '' ) {
            echo '<p><label><input type="checkbox" name="' . esc_attr( $n ) . '[clear_key]" value="1" /> 保存済みのキーを削除する</label></p>';
        }
        echo '<p class="description">暗号化して保存されます。空欄のまま保存すると既存のキーを維持します。</p>';
        echo '</td></tr>';

        echo '<tr><th>画像形式</th><td><select name="' . esc_attr( $n ) . '[format]">';
        echo '<option value="jpg" ' . selected( $format, 'jpg', false ) . '>JPEG（軽量・推奨）</option>';
        echo '<option value="png" ' . selected( $format, 'png', false ) . '>PNG</option>';
        echo '</select></td></tr>';

        echo '<tr><th>PC表示幅(px)</th><td><input type="number" name="' . esc_attr( $n ) . '[pc_width]" value="' . esc_attr( (string) $pc_w ) . '" class="small-text" min="320" max="2560" /></td></tr>';
        echo '<tr><th>スマホ表示幅(px)</th><td><input type="number" name="' . esc_attr( $n ) . '[mobile_width]" value="' . esc_attr( (string) $sp_w ) . '" class="small-text" min="320" max="1280" /><p class="description">※スマホはiPhoneプリセットを使うため、幅は参考値です。</p></td></tr>';
        echo '<tr><th>PCの高さ上限(px)</th><td><input type="number" name="' . esc_attr( $n ) . '[pc_max_height]" value="' . esc_attr( (string) $pc_h ) . '" class="small-text" min="2000" max="30000" step="1000" /></td></tr>';
        echo '<tr><th>スマホの高さ上限(px)</th><td><input type="number" name="' . esc_attr( $n ) . '[mobile_max_height]" value="' . esc_attr( (string) $sp_h ) . '" class="small-text" min="2000" max="30000" step="1000" /><p class="description">縦長ページの撮影が見切れる場合は上げてください（大きすぎると生成失敗・低速化の恐れ）。スマホは縦に長くなるため初期値を高めにしています。</p></td></tr>';
        echo '<tr><th>読み込み待機(秒)</th><td><input type="number" name="' . esc_attr( $n ) . '[delay]" value="' . esc_attr( (string) $delay ) . '" class="small-text" min="0" max="15" /><p class="description">YouTube等の埋め込み・遅延読み込みコンテンツが空白になる場合は数値を上げてください（撮影時間は延びます）。</p></td></tr>';

        echo '</table>';
    }

    /**
     * 接続テスト（example.com を実際にキャプチャして確認）。保存後に実行すること。
     */
    public function handle_test(): void {
        if ( ! isset( $_POST['gcrev_screenshot_test'] ) ) { return; }
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        check_admin_referer( 'gcrev_screenshot_test_nonce' );

        $module = dirname( __DIR__ ) . '/modules/class-screenshot-client.php';
        if ( ! class_exists( 'Gcrev_Screenshot_Client' ) && file_exists( $module ) ) {
            require_once $module;
        }
        if ( ! class_exists( 'Gcrev_Screenshot_Client' ) || ! Gcrev_Screenshot_Client::is_configured( 'pc' ) ) {
            add_settings_error( 'gcrev_screenshot', 'test', 'PC用の設定が見つかりません。アクセスキーを入力・保存してからテストしてください。', 'error' );
            return;
        }

        $res = Gcrev_Screenshot_Client::capture( 'https://example.com', 'pc' );
        if ( ! empty( $res['ok'] ) ) {
            add_settings_error( 'gcrev_screenshot', 'test',
                sprintf( '接続成功: example.com のキャプチャを取得できました（%s, %d KB）。', $res['mime'], (int) round( strlen( $res['bytes'] ) / 1024 ) ),
                'success'
            );
        } else {
            add_settings_error( 'gcrev_screenshot', 'test', '接続失敗: ' . ( $res['error'] ?? '不明なエラー' ), 'error' );
        }
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        ?>
        <div class="wrap">
            <h1>スクショAPI設定（ページ分析の自動キャプチャ）</h1>
            <?php settings_errors( 'gcrev_screenshot' ); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::MENU_SLUG );
                submit_button( '設定を保存' );
                ?>
            </form>

            <hr />
            <h2>接続テスト</h2>
            <p>保存済みの設定で <code>https://example.com</code> を実際にキャプチャして接続を確認します。<strong>先に「設定を保存」してから</strong>実行してください。</p>
            <form method="post">
                <?php wp_nonce_field( 'gcrev_screenshot_test_nonce' ); ?>
                <input type="hidden" name="gcrev_screenshot_test" value="1" />
                <?php submit_button( '接続テストを実行', 'secondary' ); ?>
            </form>
        </div>
        <?php
    }
}
