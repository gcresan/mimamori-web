<?php
// FILE: inc/gcrev-api/admin/class-inquiries-settings-page.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( class_exists( 'Gcrev_Inquiries_Settings_Page' ) ) {
    return;
}

/**
 * Gcrev_Inquiries_Settings_Page
 *
 * 「みまもりウェブ > 問い合わせ取得」管理画面。
 * クライアントごとに、契約サイトの mimamori-inquiries-api プラグイン宛の
 * URL とトークンを登録する。
 *
 * @package Mimamori_Web
 */
class Gcrev_Inquiries_Settings_Page {

    private const MENU_SLUG = 'gcrev-inquiries-settings';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
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
            '問い合わせ取得設定 - みまもりウェブ',
            '✉️ 問い合わせ取得',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( empty( $_POST['_gcrev_inquiries_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['_gcrev_inquiries_nonce'] ) ),
            'gcrev_inquiries_action'
        ) ) {
            wp_die( '不正なリクエストです。' );
        }

        $action  = isset( $_POST['gcrev_action'] )      ? sanitize_text_field( wp_unslash( $_POST['gcrev_action'] ) )      : '';
        $user_id = isset( $_POST['gcrev_target_user'] ) ? absint( $_POST['gcrev_target_user'] )                            : 0;
        if ( $user_id <= 0 ) {
            return;
        }

        if ( ! class_exists( 'Mimamori_Inquiries_Fetcher' ) ) {
            return;
        }

        if ( $action === 'save_settings' ) {
            $endpoint = isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( (string) $_POST['endpoint'] ) ) : '';
            $token    = isset( $_POST['token'] )    ? trim( wp_unslash( (string) $_POST['token'] ) )                : '';
            $enabled  = ! empty( $_POST['enabled'] );

            if ( $endpoint !== '' && ! preg_match( '#^https?://#i', $endpoint ) ) {
                wp_safe_redirect( add_query_arg( [ 'updated' => 'invalid', 'user' => $user_id ], menu_page_url( self::MENU_SLUG, false ) ) );
                exit;
            }

            update_user_meta( $user_id, Mimamori_Inquiries_Fetcher::META_ENDPOINT, $endpoint );
            if ( $token !== '' ) {
                Mimamori_Inquiries_Fetcher::set_token( $user_id, $token );
            }
            update_user_meta( $user_id, Mimamori_Inquiries_Fetcher::META_ENABLED, $enabled ? 1 : 0 );

            wp_safe_redirect( add_query_arg( [ 'updated' => '1', 'user' => $user_id ], menu_page_url( self::MENU_SLUG, false ) ) );
            exit;
        }

        if ( $action === 'fetch_now' ) {
            $tz   = wp_timezone();
            $prev = ( new DateTimeImmutable( 'first day of last month', $tz ) );
            $year  = isset( $_POST['year'] )  ? absint( $_POST['year'] )  : (int) $prev->format( 'Y' );
            $month = isset( $_POST['month'] ) ? absint( $_POST['month'] ) : (int) $prev->format( 'n' );

            $fetcher = new Mimamori_Inquiries_Fetcher();
            $result  = $fetcher->fetch_and_store( $user_id, $year, $month );

            $flag = ! empty( $result['success'] ) ? 'fetched' : 'fetch_failed';
            wp_safe_redirect( add_query_arg( [
                'updated' => $flag,
                'user'    => $user_id,
                'msg'     => rawurlencode( (string) ( $result['message'] ?? '' ) ),
            ], menu_page_url( self::MENU_SLUG, false ) ) );
            exit;
        }
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '権限がありません。' );
        }
        if ( ! class_exists( 'Mimamori_Inquiries_Fetcher' ) ) {
            echo '<div class="wrap"><h1>問い合わせ取得</h1><p>Mimamori_Inquiries_Fetcher モジュールが読み込まれていません。</p></div>';
            return;
        }

        $current = isset( $_GET['user'] ) ? absint( wp_unslash( $_GET['user'] ) ) : get_current_user_id();
        $users   = get_users( [ 'fields' => [ 'ID', 'user_login', 'display_name' ], 'orderby' => 'ID' ] );

        $endpoint     = Mimamori_Inquiries_Fetcher::get_endpoint( $current );
        $token_raw    = (string) get_user_meta( $current, Mimamori_Inquiries_Fetcher::META_TOKEN, true );
        $token_saved  = ( $token_raw !== '' );
        $enabled      = Mimamori_Inquiries_Fetcher::is_enabled( $current );
        $recent       = Mimamori_Inquiries_Fetcher::get_recent( $current, 12 );

        $updated = isset( $_GET['updated'] ) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';
        $msg     = isset( $_GET['msg'] )     ? sanitize_text_field( wp_unslash( $_GET['msg'] ) )     : '';

        ?>
        <div class="wrap">
            <h1>✉️ 問い合わせ取得設定</h1>
            <p>契約サイトに導入した「みまもりウェブ 問い合わせ集計API」プラグインの URL とトークンを登録します。月初 09:30 に前月分を自動取得します。</p>

            <?php if ( $updated === '1' ) : ?>
                <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
            <?php elseif ( $updated === 'invalid' ) : ?>
                <div class="notice notice-error is-dismissible"><p>URL は http(s):// で始まる必要があります。</p></div>
            <?php elseif ( $updated === 'fetched' ) : ?>
                <div class="notice notice-success is-dismissible"><p>取得しました。</p></div>
            <?php elseif ( $updated === 'fetch_failed' ) : ?>
                <div class="notice notice-error is-dismissible"><p>取得に失敗しました。<?php echo $msg ? esc_html( '詳細: ' . $msg ) : ''; ?></p></div>
            <?php endif; ?>

            <h2>対象ユーザー</h2>
            <form method="get" style="margin-bottom:1em;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                <select name="user" onchange="this.form.submit()">
                    <?php foreach ( $users as $u ) :
                        $label = sprintf( '#%d %s (%s)', (int) $u->ID, $u->display_name, $u->user_login );
                        ?>
                        <option value="<?php echo esc_attr( (string) $u->ID ); ?>" <?php selected( $current, (int) $u->ID ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>

            <h2>API 接続設定</h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'gcrev_inquiries_action', '_gcrev_inquiries_nonce' ); ?>
                <input type="hidden" name="gcrev_action" value="save_settings" />
                <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( (string) $current ); ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="endpoint">エンドポイント URL</label></th>
                        <td>
                            <input type="url" id="endpoint" name="endpoint" value="<?php echo esc_attr( $endpoint ); ?>" class="regular-text" placeholder="https://example.com/wp-json/mimamori/v1/inquiries" />
                            <p class="description">サイトトップ URL（例: <code>https://example.com</code>）でも自動補完します。</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="token">トークン</label></th>
                        <td>
                            <input type="password" id="token" name="token" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $token_saved ? '（保存済み・再入力で上書き）' : '（未設定 — 必ず入力してください）'; ?>" />
                            <p class="description">
                                契約サイト側 wp-config.php の <code>MIMAMORI_INQUIRIES_API_TOKEN</code> と同じ値。
                                <?php if ( $token_saved ) : ?>
                                    <strong style="color:#1e8e3e;">✓ 保存済み</strong>。空のまま保存すると現在の値を維持します。
                                <?php else : ?>
                                    <strong style="color:#b00;">✗ 未保存</strong>。必ず入力してから保存してください。
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>自動取得</th>
                        <td>
                            <label><input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?> /> 月初に自動取得する</label>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary">設定を保存</button></p>
            </form>

            <h2>手動取得</h2>
            <form method="post" action="" style="background:#f6f7f7;padding:12px;border:1px solid #ddd;max-width:600px;">
                <?php wp_nonce_field( 'gcrev_inquiries_action', '_gcrev_inquiries_nonce' ); ?>
                <input type="hidden" name="gcrev_action" value="fetch_now" />
                <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( (string) $current ); ?>" />
                <?php
                $tz   = wp_timezone();
                $prev = ( new DateTimeImmutable( 'first day of last month', $tz ) );
                ?>
                <label>年: <input type="number" name="year"  value="<?php echo esc_attr( $prev->format( 'Y' ) ); ?>" min="2000" max="2100" /></label>
                &nbsp;
                <label>月: <input type="number" name="month" value="<?php echo esc_attr( $prev->format( 'n' ) ); ?>" min="1" max="12" /></label>
                &nbsp;
                <button type="submit" class="button">この月を取得</button>
            </form>

            <h2 style="margin-top:2em;">取得済みデータ（直近12ヶ月）</h2>
            <?php if ( empty( $recent ) ) : ?>
                <p>まだ取得履歴がありません。</p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:900px;">
                    <thead>
                        <tr>
                            <th>期間</th>
                            <th>合計</th>
                            <th>有効</th>
                            <th>除外</th>
                            <th>SPAM</th>
                            <th>テスト</th>
                            <th>営業</th>
                            <th>取得日時</th>
                            <th>エラー</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( array_reverse( $recent ) as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) $row['year_month'] ); ?></td>
                                <td><?php echo esc_html( (string) (int) $row['total'] ); ?></td>
                                <td><strong><?php echo esc_html( (string) (int) $row['valid_count'] ); ?></strong></td>
                                <td><?php echo esc_html( (string) (int) $row['excluded'] ); ?></td>
                                <td><?php echo esc_html( (string) (int) $row['reason_spam'] ); ?></td>
                                <td><?php echo esc_html( (string) (int) $row['reason_test'] ); ?></td>
                                <td><?php echo esc_html( (string) (int) $row['reason_sales'] ); ?></td>
                                <td><?php echo esc_html( (string) $row['fetched_at'] ); ?></td>
                                <td><?php echo $row['error_message'] ? '<span style="color:#b00">' . esc_html( (string) $row['error_message'] ) . '</span>' : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
