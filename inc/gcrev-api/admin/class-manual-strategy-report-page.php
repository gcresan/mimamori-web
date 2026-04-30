<?php
// FILE: inc/gcrev-api/admin/class-manual-strategy-report-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Manual_Strategy_Report_Page') ) { return; }

/**
 * Gcrev_Manual_Strategy_Report_Page
 *
 * 「みまもりウェブ > 戦略レポート（手動アップロード）」管理画面。
 * クライアントごとに「簡易版HTML」「詳細版HTML」の Media Library 添付ファイル ID を
 * user_meta に保存する。/strategy-report/ ページが手動アップロードを優先表示する。
 *
 * @package Mimamori_Web
 */
class Gcrev_Manual_Strategy_Report_Page {

    private const MENU_SLUG  = 'gcrev-manual-strategy-report';
    private const META_SIMPLE = '_mimamori_strategy_simple_html_id';
    private const META_DETAIL = '_mimamori_strategy_detail_html_id';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
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
            '戦略レポート（手動）- みまもりウェブ',
            "\xF0\x9F\x93\x84 戦略レポート（手動）", // 📄
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }
        // Media Library モーダルを使えるようにする
        wp_enqueue_media();
    }

    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( ! isset( $_POST['_gcrev_manual_strategy_nonce'] ) ) return;
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['_gcrev_manual_strategy_nonce'] ) ),
            'gcrev_manual_strategy_action'
        ) ) return;

        $action  = isset( $_POST['gcrev_action'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_action'] ) ) : '';
        $user_id = isset( $_POST['gcrev_target_user'] ) ? absint( $_POST['gcrev_target_user'] ) : 0;
        if ( $user_id <= 0 ) return;

        $msg = '';
        if ( $action === 'save' ) {
            $simple_id = isset( $_POST['gcrev_simple_id'] ) ? absint( $_POST['gcrev_simple_id'] ) : 0;
            $detail_id = isset( $_POST['gcrev_detail_id'] ) ? absint( $_POST['gcrev_detail_id'] ) : 0;

            if ( $simple_id > 0 && ! $this->is_valid_html_attachment( $simple_id ) ) {
                $msg = 'invalid_simple';
            } elseif ( $detail_id > 0 && ! $this->is_valid_html_attachment( $detail_id ) ) {
                $msg = 'invalid_detail';
            } else {
                if ( $simple_id > 0 ) {
                    update_user_meta( $user_id, self::META_SIMPLE, $simple_id );
                } else {
                    delete_user_meta( $user_id, self::META_SIMPLE );
                }
                if ( $detail_id > 0 ) {
                    update_user_meta( $user_id, self::META_DETAIL, $detail_id );
                } else {
                    delete_user_meta( $user_id, self::META_DETAIL );
                }
                $msg = 'saved';
            }
        } elseif ( $action === 'clear' ) {
            delete_user_meta( $user_id, self::META_SIMPLE );
            delete_user_meta( $user_id, self::META_DETAIL );
            $msg = 'cleared';
        }

        $redirect = add_query_arg(
            [
                'page'    => self::MENU_SLUG,
                'msg'     => $msg,
                'user_id' => $user_id,
            ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    private function is_valid_html_attachment( int $att_id ): bool {
        $mime = get_post_mime_type( $att_id );
        // text/html, または .html の URL を許可
        if ( $mime === 'text/html' ) return true;
        $url = wp_get_attachment_url( $att_id );
        if ( $url && preg_match( '/\.html?$/i', $url ) ) return true;
        return false;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '権限がありません' );
        }

        $msg_code = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
        $msg_user = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $msg_html = '';
        if ( $msg_code === 'saved' ) {
            $msg_html = '<div class="notice notice-success is-dismissible"><p>保存しました（user_id=' . $msg_user . '）</p></div>';
        } elseif ( $msg_code === 'cleared' ) {
            $msg_html = '<div class="notice notice-success is-dismissible"><p>解除しました（user_id=' . $msg_user . '）</p></div>';
        } elseif ( $msg_code === 'invalid_simple' || $msg_code === 'invalid_detail' ) {
            $msg_html = '<div class="notice notice-error"><p>HTMLファイルではない添付IDが指定されました（' . esc_html( $msg_code ) . '）</p></div>';
        }

        // ユーザー一覧（指定なら最新50件、検索があれば絞り込み）
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $args = [
            'number'  => 100,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ];
        if ( $search !== '' ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
        }
        $users = get_users( $args );

        ?>
        <div class="wrap">
            <h1>📄 戦略レポート（手動アップロード）</h1>
            <p>クライアントごとに「簡易版HTML」「詳細版HTML」を割り当てます。設定すると、そのユーザーの <code>/strategy-report/</code> は手動アップロードのレポートを表示します（AI生成より優先）。</p>
            <ol style="background:#f6f7f7;padding:14px 30px;border-radius:6px;">
                <li>事前に「メディアライブラリ」へ HTML ファイルをアップロードしてください（拡張子 .html 必須）。</li>
                <li>下のテーブルで対象クライアントの行から「📁 ファイルを選択」を押し、メディアライブラリで対象 HTML を選択。</li>
                <li>「保存」ボタンで反映。詳細レポートは新ページ <code>/strategy-report-detail/</code> で配信されます（リンクは自動書換）。</li>
            </ol>

            <?php echo $msg_html; ?>

            <form method="get" style="margin:16px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="ユーザー名・メールで検索">
                <input type="submit" class="button" value="検索">
                <?php if ( $search !== '' ) : ?>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">クリア</a>
                <?php endif; ?>
            </form>

            <table class="wp-list-table widefat fixed striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>ユーザー</th>
                        <th>📋 簡易版HTML</th>
                        <th>📊 詳細版HTML</th>
                        <th style="width:160px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $users as $u ) :
                    $simple_id = (int) get_user_meta( $u->ID, self::META_SIMPLE, true );
                    $detail_id = (int) get_user_meta( $u->ID, self::META_DETAIL, true );
                    $simple_url = $simple_id ? wp_get_attachment_url( $simple_id ) : '';
                    $detail_url = $detail_id ? wp_get_attachment_url( $detail_id ) : '';
                    $simple_name = $simple_url ? basename( wp_parse_url( $simple_url, PHP_URL_PATH ) ?: '' ) : '';
                    $detail_name = $detail_url ? basename( wp_parse_url( $detail_url, PHP_URL_PATH ) ?: '' ) : '';
                ?>
                    <tr>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">
                            <?php wp_nonce_field( 'gcrev_manual_strategy_action', '_gcrev_manual_strategy_nonce' ); ?>
                            <input type="hidden" name="gcrev_target_user" value="<?php echo (int) $u->ID; ?>">
                            <input type="hidden" name="gcrev_action" value="save">
                            <input type="hidden" class="js-simple-id" name="gcrev_simple_id" value="<?php echo (int) $simple_id; ?>">
                            <input type="hidden" class="js-detail-id" name="gcrev_detail_id" value="<?php echo (int) $detail_id; ?>">

                            <td><?php echo (int) $u->ID; ?></td>
                            <td>
                                <strong><?php echo esc_html( $u->display_name ); ?></strong><br>
                                <span style="color:#666;font-size:12px;"><?php echo esc_html( $u->user_login ); ?> / <?php echo esc_html( $u->user_email ); ?></span>
                            </td>
                            <td>
                                <button type="button" class="button js-pick-simple">📁 ファイルを選択</button>
                                <div class="js-simple-info" style="margin-top:6px;font-size:12px;">
                                    <?php if ( $simple_url ) : ?>
                                        <a href="<?php echo esc_url( $simple_url ); ?>" target="_blank" rel="noopener">📋 <?php echo esc_html( $simple_name ); ?></a>
                                    <?php else : ?>
                                        <span style="color:#999;">未設定</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <button type="button" class="button js-pick-detail">📁 ファイルを選択</button>
                                <div class="js-detail-info" style="margin-top:6px;font-size:12px;">
                                    <?php if ( $detail_url ) : ?>
                                        <a href="<?php echo esc_url( $detail_url ); ?>" target="_blank" rel="noopener">📊 <?php echo esc_html( $detail_name ); ?></a>
                                    <?php else : ?>
                                        <span style="color:#999;">未設定</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <button type="submit" class="button button-primary">保存</button>
                                <?php if ( $simple_id || $detail_id ) : ?>
                                    <button type="submit" class="button" formnovalidate
                                            onclick="this.form.querySelector('[name=gcrev_action]').value='clear';return confirm('このユーザーの手動レポート設定を解除しますか？');">解除</button>
                                <?php endif; ?>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $users ) ) : ?>
                    <tr><td colspan="5" style="text-align:center;color:#999;padding:24px;">ユーザーが見つかりませんでした</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        jQuery(function($) {
            function bindPicker(btnSelector, hiddenSelector, infoSelector, kindLabel) {
                $(document).on('click', btnSelector, function(e) {
                    e.preventDefault();
                    var $row    = $(this).closest('tr');
                    var $hidden = $row.find(hiddenSelector);
                    var $info   = $row.find(infoSelector);

                    var frame = wp.media({
                        title: kindLabel + ' のHTMLファイルを選択',
                        multiple: false,
                        library: { type: ['text/html'] }
                    });
                    frame.on('select', function() {
                        var att = frame.state().get('selection').first().toJSON();
                        if (att && att.id) {
                            $hidden.val(att.id);
                            var name = att.filename || att.title || ('attachment ' + att.id);
                            $info.html('<a href="' + att.url + '" target="_blank" rel="noopener">' + name + '</a>');
                        }
                    });
                    frame.open();
                });
            }
            bindPicker('.js-pick-simple', '.js-simple-id', '.js-simple-info', '簡易版');
            bindPicker('.js-pick-detail', '.js-detail-id', '.js-detail-info', '詳細版');
        });
        </script>
        <?php
    }

    /**
     * 静的ヘルパー: ユーザーの手動レポート添付IDを取得
     *
     * @return array{ simple: int, detail: int }
     */
    public static function get_for_user( int $user_id ): array {
        return [
            'simple' => (int) get_user_meta( $user_id, self::META_SIMPLE, true ),
            'detail' => (int) get_user_meta( $user_id, self::META_DETAIL, true ),
        ];
    }

    /**
     * ユーザーの手動レポート（指定種別）を直接ブラウザに出力する。
     * 認証済みかつ自分の添付であることを確認。簡易版は詳細版へのリンクを書き換える。
     *
     * 戻り値: true = 出力した（呼び出し側は exit すべき）／ false = 設定なし
     */
    public static function serve_for_current_user( string $kind ): bool {
        if ( ! in_array( $kind, [ 'simple', 'detail' ], true ) ) return false;
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( $_SERVER['REQUEST_URI'] ?? home_url('/') ) );
            exit;
        }
        $user_id = get_current_user_id();
        $ids = self::get_for_user( $user_id );
        $att_id = (int) ( $kind === 'simple' ? $ids['simple'] : $ids['detail'] );
        if ( $att_id <= 0 ) return false;

        $file = get_attached_file( $att_id );
        if ( ! $file || ! is_readable( $file ) ) return false;

        $html = file_get_contents( $file );
        if ( $html === false ) return false;

        // 簡易版の場合: 詳細版HTMLへのリンクを /strategy-report-detail/ に書き換え
        if ( $kind === 'simple' && $ids['detail'] > 0 ) {
            $detail_route = home_url( '/strategy-report-detail/' );
            // href="...something.html(#anchor)?" の相対 .html/.htm リンクを全て書換
            // 絶対 URL（http://...）と protocol-relative（//...）は除外
            $html = preg_replace_callback(
                '#href=(["\'])([^"\']+?\.html?)((?:#[^"\']*)?)\1#i',
                function ( $m ) use ( $detail_route ) {
                    $url = $m[2];
                    if ( preg_match( '#^https?://#i', $url ) ) return $m[0];
                    if ( strpos( $url, '//' ) === 0 ) return $m[0];
                    $anchor = $m[3];
                    return 'href=' . $m[1] . $detail_route . $anchor . $m[1];
                },
                $html
            );
        }

        // ヘッダー（テーマを通さず素のHTMLを出力）
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex, nofollow', true );
        echo $html;
        return true;
    }
}
