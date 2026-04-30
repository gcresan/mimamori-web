<?php
// FILE: inc/gcrev-api/admin/class-manual-strategy-report-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Manual_Strategy_Report_Page') ) { return; }

/**
 * Gcrev_Manual_Strategy_Report_Page
 *
 * 「みまもりウェブ > 戦略レポート（手動）」管理画面。
 * クライアントごとに「概要版/詳細版」HTML レポートを複数バージョン保存できる。
 *
 * 保存形式: user_meta `_mimamori_strategy_reports` = JSON 配列
 *   [
 *     {
 *       'id'         : 'rxx...' (英数字8文字),
 *       'label'      : '2026年4月版' (任意),
 *       'period'     : '2026-04'   (任意, 並べ替え用),
 *       'simple_id'  : <attachment_id> (int),
 *       'detail_id'  : <attachment_id> (int),
 *       'created_at' : 'YYYY-MM-DD HH:MM:SS',
 *       'updated_at' : 'YYYY-MM-DD HH:MM:SS',
 *     }, ...
 *   ]
 *
 * @package Mimamori_Web
 */
class Gcrev_Manual_Strategy_Report_Page {

    private const MENU_SLUG          = 'gcrev-manual-strategy-report';
    private const META_VERSIONS      = '_mimamori_strategy_reports';
    private const META_LEGACY_SIMPLE = '_mimamori_strategy_simple_html_id';
    private const META_LEGACY_DETAIL = '_mimamori_strategy_detail_html_id';

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
            "\xF0\x9F\x93\x84 戦略レポート（手動）",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) return;
        wp_enqueue_media();
    }

    // =========================================================
    // データアクセス
    // =========================================================

    /**
     * バージョン一覧を取得（period 降順 → created_at 降順）
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_versions( int $user_id ): array {
        $raw = get_user_meta( $user_id, self::META_VERSIONS, true );
        $versions = is_array( $raw ) ? $raw : [];

        // legacy 移行: バージョン未保存で旧キーがあれば1つ作る（破壊しない）
        if ( empty( $versions ) ) {
            $legacy_simple = (int) get_user_meta( $user_id, self::META_LEGACY_SIMPLE, true );
            $legacy_detail = (int) get_user_meta( $user_id, self::META_LEGACY_DETAIL, true );
            if ( $legacy_simple > 0 || $legacy_detail > 0 ) {
                $now = current_time( 'mysql' );
                $versions = [
                    [
                        'id'         => self::generate_id(),
                        'label'      => '初版',
                        'period'     => '',
                        'simple_id'  => $legacy_simple,
                        'detail_id'  => $legacy_detail,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ];
                update_user_meta( $user_id, self::META_VERSIONS, $versions );
            }
        }

        usort( $versions, function ( $a, $b ) {
            $pa = (string) ( $a['period'] ?? '' );
            $pb = (string) ( $b['period'] ?? '' );
            if ( $pa !== $pb ) return strcmp( $pb, $pa ); // period 降順
            $ca = (string) ( $a['created_at'] ?? '' );
            $cb = (string) ( $b['created_at'] ?? '' );
            return strcmp( $cb, $ca ); // created_at 降順
        } );

        return $versions;
    }

    /**
     * 指定 ID のバージョンを取得
     */
    public static function get_version( int $user_id, string $ver_id ): ?array {
        if ( $ver_id === '' ) return null;
        $versions = self::get_versions( $user_id );
        foreach ( $versions as $v ) {
            if ( ( $v['id'] ?? '' ) === $ver_id ) return $v;
        }
        return null;
    }

    /**
     * 最新バージョンを取得（period/created_at が最新のもの）
     */
    public static function get_latest( int $user_id ): ?array {
        $versions = self::get_versions( $user_id );
        return ! empty( $versions ) ? $versions[0] : null;
    }

    private static function save_versions( int $user_id, array $versions ): void {
        // 値の正規化
        $versions = array_values( array_filter( $versions, function ( $v ) {
            return is_array( $v ) && ! empty( $v['id'] );
        } ) );
        update_user_meta( $user_id, self::META_VERSIONS, $versions );
    }

    private static function generate_id(): string {
        return 'r' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
    }

    // =========================================================
    // POSTアクション処理
    // =========================================================

    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( ! isset( $_POST['_gcrev_manual_strategy_nonce'] ) ) return;
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['_gcrev_manual_strategy_nonce'] ) ),
            'gcrev_manual_strategy_action'
        ) ) return;

        $action  = isset( $_POST['gcrev_action'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_action'] ) ) : '';
        $user_id = isset( $_POST['gcrev_target_user'] ) ? absint( $_POST['gcrev_target_user'] ) : 0;
        if ( $user_id <= 0 ) {
            $this->redirect_with_msg( 'no_user', 0 );
            return;
        }

        $simple_id = isset( $_POST['gcrev_simple_id'] ) ? absint( $_POST['gcrev_simple_id'] ) : 0;
        $detail_id = isset( $_POST['gcrev_detail_id'] ) ? absint( $_POST['gcrev_detail_id'] ) : 0;
        $label     = isset( $_POST['gcrev_label'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_label'] ) ) : '';
        $period    = isset( $_POST['gcrev_period'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_period'] ) ) : '';
        $ver_id    = isset( $_POST['gcrev_ver_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_ver_id'] ) ) : '';

        // バリデーション
        if ( in_array( $action, [ 'add', 'update' ], true ) ) {
            if ( $simple_id <= 0 && $detail_id <= 0 ) {
                $this->redirect_with_msg( 'no_files', $user_id );
                return;
            }
            if ( $simple_id > 0 && ! $this->is_valid_html_attachment( $simple_id ) ) {
                $this->redirect_with_msg( 'invalid_simple', $user_id );
                return;
            }
            if ( $detail_id > 0 && ! $this->is_valid_html_attachment( $detail_id ) ) {
                $this->redirect_with_msg( 'invalid_detail', $user_id );
                return;
            }
            if ( $period !== '' && ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $period ) ) {
                $this->redirect_with_msg( 'invalid_period', $user_id );
                return;
            }
        }

        $versions = self::get_versions( $user_id );
        $now = current_time( 'mysql' );

        if ( $action === 'add' ) {
            $versions[] = [
                'id'         => self::generate_id(),
                'label'      => $label,
                'period'     => $period,
                'simple_id'  => $simple_id,
                'detail_id'  => $detail_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            self::save_versions( $user_id, $versions );
            $this->redirect_with_msg( 'added', $user_id );
            return;
        }

        if ( $action === 'update' ) {
            $found = false;
            foreach ( $versions as &$v ) {
                if ( ( $v['id'] ?? '' ) === $ver_id ) {
                    $v['label']      = $label;
                    $v['period']     = $period;
                    $v['simple_id']  = $simple_id;
                    $v['detail_id']  = $detail_id;
                    $v['updated_at'] = $now;
                    $found = true;
                    break;
                }
            }
            unset( $v );
            if ( ! $found ) {
                $this->redirect_with_msg( 'not_found', $user_id );
                return;
            }
            self::save_versions( $user_id, $versions );
            $this->redirect_with_msg( 'updated', $user_id );
            return;
        }

        if ( $action === 'delete' ) {
            $versions = array_values( array_filter( $versions, function ( $v ) use ( $ver_id ) {
                return ( $v['id'] ?? '' ) !== $ver_id;
            } ) );
            self::save_versions( $user_id, $versions );
            $this->redirect_with_msg( 'deleted', $user_id );
            return;
        }
    }

    private function redirect_with_msg( string $msg, int $user_id ): void {
        $args = [ 'page' => self::MENU_SLUG, 'msg' => $msg ];
        if ( $user_id > 0 ) $args['user_id'] = $user_id;
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * ユーザー検索: user_login/email/display_name と report_company_name 両方で OR 検索。
     * WP_User_Query は search と meta_query の OR を直接サポートしないので、
     * 2回クエリして結果を ID ベースでマージする。
     *
     * @return WP_User[]
     */
    private function search_users_inclusive( array $base_args, string $term ): array {
        // 1) ログイン名/メール/display_name
        $user_args = $base_args;
        unset( $user_args['meta_query'] );
        $by_user = get_users( $user_args );

        // 2) 事業者名 user_meta
        $meta_args = $base_args;
        unset( $meta_args['search'], $meta_args['search_columns'] );
        $by_meta = get_users( $meta_args );

        $merged = [];
        foreach ( array_merge( $by_user, $by_meta ) as $u ) {
            $merged[ (int) $u->ID ] = $u;
        }
        // display_name で並べ替え
        usort( $merged, function ( $a, $b ) {
            return strcmp( (string) $a->display_name, (string) $b->display_name );
        } );
        return $merged;
    }

    private function is_valid_html_attachment( int $att_id ): bool {
        if ( $att_id <= 0 ) return false;
        $mime = get_post_mime_type( $att_id );
        if ( $mime === 'text/html' ) return true;
        $url = wp_get_attachment_url( $att_id );
        if ( $url && preg_match( '/\.html?$/i', $url ) ) return true;
        return false;
    }

    // =========================================================
    // 画面描画
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '権限がありません' );

        $msg_code = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
        $sel_user = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $msg_html = $this->msg_to_html( $msg_code );
        $search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        // ユーザー一覧
        $args = [ 'number' => 200, 'orderby' => 'display_name', 'order' => 'ASC' ];
        if ( $search !== '' ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
            // 事業者名 (report_company_name) でも検索できるよう、meta_query を併用
            $args['meta_query'] = [
                [
                    'key'     => 'report_company_name',
                    'value'   => $search,
                    'compare' => 'LIKE',
                ],
            ];
        }
        $users = $search !== '' ? $this->search_users_inclusive( $args, $search ) : get_users( $args );

        // 事業者名で並べ替え（取得できない場合は display_name にフォールバック）
        usort( $users, function ( $a, $b ) {
            $na = function_exists( 'gcrev_get_business_name' )
                ? (string) gcrev_get_business_name( $a->ID )
                : '';
            $nb = function_exists( 'gcrev_get_business_name' )
                ? (string) gcrev_get_business_name( $b->ID )
                : '';
            if ( $na === '' ) $na = (string) $a->display_name;
            if ( $nb === '' ) $nb = (string) $b->display_name;
            return strcmp( $na, $nb );
        } );

        ?>
        <div class="wrap">
            <h1>📄 戦略レポート（手動アップロード）</h1>
            <p>クライアントごとに「概要版」「詳細版」HTML を <strong>複数バージョン</strong> 保存できます。アップロードを重ねるたびに履歴として残り、最新版が <code>/strategy-report/</code> に表示されます。</p>
            <ol style="background:#f6f7f7;padding:14px 30px;border-radius:6px;line-height:1.8;">
                <li>事前にメディアライブラリへ HTML ファイルをアップロード（拡張子 .html 必須）</li>
                <li>下のクライアント行で「➕ 新規バージョンを追加」を押し、フォームでファイル・対象月・ラベルを指定して保存</li>
                <li>クライアントは <code>/strategy-report/</code> で <strong>最新版</strong> を閲覧、過去版は <code>/strategy-report-history/</code> から閲覧可能</li>
            </ol>

            <?php echo $msg_html; ?>

            <form method="get" style="margin:16px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="事業者名・ユーザー名・メールで検索">
                <input type="submit" class="button" value="検索">
                <?php if ( $search !== '' ) : ?>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">クリア</a>
                <?php endif; ?>
            </form>

            <?php foreach ( $users as $u ) :
                $versions = self::get_versions( $u->ID );
                $is_open  = ( $sel_user === $u->ID );
                $business = function_exists( 'gcrev_get_business_name' )
                    ? (string) gcrev_get_business_name( $u->ID )
                    : '';
                if ( $business === '' ) $business = $u->display_name;
            ?>
                <details class="card" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;margin-bottom:14px;" <?php echo $is_open ? 'open' : ''; ?>>
                    <summary style="padding:12px 16px;cursor:pointer;display:flex;align-items:center;gap:14px;">
                        <strong style="font-size:14px;">
                            <?php echo esc_html( $business ); ?>
                        </strong>
                        <?php if ( $business !== $u->display_name && $u->display_name !== '' ) : ?>
                            <span style="color:#888;font-size:12px;">
                                （<?php echo esc_html( $u->display_name ); ?>）
                            </span>
                        <?php endif; ?>
                        <span style="color:#666;font-size:12px;">
                            <?php echo esc_html( $u->user_login ); ?> / <?php echo esc_html( $u->user_email ); ?>
                        </span>
                        <span style="margin-left:auto;font-size:12px;color:#666;">
                            ID: <?php echo (int) $u->ID; ?> / <?php echo count( $versions ); ?> 件
                        </span>
                    </summary>
                    <div style="padding:6px 16px 18px;">
                        <?php $this->render_versions_table( $u->ID, $versions ); ?>

                        <h3 style="margin-top:24px;">➕ 新規バージョンを追加</h3>
                        <?php $this->render_form( $u->ID, null ); ?>
                    </div>
                </details>
            <?php endforeach; ?>

            <?php if ( empty( $users ) ) : ?>
                <p style="text-align:center;color:#999;padding:24px;">ユーザーが見つかりませんでした</p>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($) {
            $(document).on('click', '.js-pick-file', function(e) {
                e.preventDefault();
                var $btn   = $(this);
                var target = $btn.data('target'); // 'simple' or 'detail'
                var $form  = $btn.closest('form');
                var $hidden = $form.find('input.js-' + target + '-id');
                var $info   = $form.find('.js-' + target + '-info');

                var frame = wp.media({
                    title: (target === 'simple' ? '概要版' : '詳細版') + ' のHTMLファイルを選択',
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
            $(document).on('click', '.js-clear-file', function(e) {
                e.preventDefault();
                var $btn   = $(this);
                var target = $btn.data('target');
                var $form  = $btn.closest('form');
                $form.find('input.js-' + target + '-id').val('');
                $form.find('.js-' + target + '-info').html('<span style="color:#999;">未設定</span>');
            });
        });
        </script>
        <?php
    }

    private function render_versions_table( int $user_id, array $versions ): void {
        if ( empty( $versions ) ) {
            echo '<p style="color:#999;">まだバージョンが登録されていません。下のフォームから追加してください。</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th style="width:120px;">対象月</th>
                    <th>ラベル</th>
                    <th>概要版</th>
                    <th>詳細版</th>
                    <th style="width:140px;">作成 / 更新</th>
                    <th style="width:200px;">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $versions as $idx => $v ) :
                $simple_url = ! empty( $v['simple_id'] ) ? wp_get_attachment_url( (int) $v['simple_id'] ) : '';
                $detail_url = ! empty( $v['detail_id'] ) ? wp_get_attachment_url( (int) $v['detail_id'] ) : '';
                $simple_name = $simple_url ? basename( wp_parse_url( $simple_url, PHP_URL_PATH ) ?: '' ) : '';
                $detail_name = $detail_url ? basename( wp_parse_url( $detail_url, PHP_URL_PATH ) ?: '' ) : '';
                $is_latest = ( $idx === 0 );
            ?>
                <tr>
                    <td>
                        <?php echo esc_html( $v['period'] ?? '' ); ?>
                        <?php if ( $is_latest ) : ?>
                            <br><span style="display:inline-block;padding:1px 6px;border-radius:3px;background:#27ae60;color:#fff;font-size:10px;font-weight:600;margin-top:2px;">最新</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $v['label'] ?? '' ); ?></td>
                    <td>
                        <?php if ( $simple_url ) : ?>
                            <a href="<?php echo esc_url( $simple_url ); ?>" target="_blank" rel="noopener">📋 <?php echo esc_html( $simple_name ); ?></a>
                        <?php else : ?>
                            <span style="color:#999;">未設定</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $detail_url ) : ?>
                            <a href="<?php echo esc_url( $detail_url ); ?>" target="_blank" rel="noopener">📊 <?php echo esc_html( $detail_name ); ?></a>
                        <?php else : ?>
                            <span style="color:#999;">未設定</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:#666;">
                        作成: <?php echo esc_html( $v['created_at'] ?? '' ); ?><br>
                        更新: <?php echo esc_html( $v['updated_at'] ?? '' ); ?>
                    </td>
                    <td>
                        <details>
                            <summary class="button button-small">編集</summary>
                            <div style="padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;margin-top:6px;">
                                <?php $this->render_form( $user_id, $v ); ?>
                            </div>
                        </details>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" style="display:inline;margin-top:6px;">
                            <?php wp_nonce_field( 'gcrev_manual_strategy_action', '_gcrev_manual_strategy_nonce' ); ?>
                            <input type="hidden" name="gcrev_target_user" value="<?php echo (int) $user_id; ?>">
                            <input type="hidden" name="gcrev_action" value="delete">
                            <input type="hidden" name="gcrev_ver_id" value="<?php echo esc_attr( $v['id'] ?? '' ); ?>">
                            <button type="submit" class="button button-small button-link-delete"
                                    onclick="return confirm('このバージョンを削除しますか？（添付ファイル自体は削除されません）');">削除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_form( int $user_id, ?array $v ): void {
        $is_edit   = ( $v !== null );
        $simple_id = $is_edit ? (int) ( $v['simple_id'] ?? 0 ) : 0;
        $detail_id = $is_edit ? (int) ( $v['detail_id'] ?? 0 ) : 0;
        $label     = $is_edit ? (string) ( $v['label'] ?? '' ) : '';
        $period    = $is_edit ? (string) ( $v['period'] ?? '' ) : '';
        $ver_id    = $is_edit ? (string) ( $v['id'] ?? '' ) : '';
        $simple_url = $simple_id ? wp_get_attachment_url( $simple_id ) : '';
        $detail_url = $detail_id ? wp_get_attachment_url( $detail_id ) : '';
        $simple_name = $simple_url ? basename( wp_parse_url( $simple_url, PHP_URL_PATH ) ?: '' ) : '';
        $detail_name = $detail_url ? basename( wp_parse_url( $detail_url, PHP_URL_PATH ) ?: '' ) : '';
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" style="display:grid;grid-template-columns:auto 1fr;gap:8px 14px;align-items:center;max-width:760px;">
            <?php wp_nonce_field( 'gcrev_manual_strategy_action', '_gcrev_manual_strategy_nonce' ); ?>
            <input type="hidden" name="gcrev_target_user" value="<?php echo (int) $user_id; ?>">
            <input type="hidden" name="gcrev_action" value="<?php echo $is_edit ? 'update' : 'add'; ?>">
            <?php if ( $is_edit ) : ?>
                <input type="hidden" name="gcrev_ver_id" value="<?php echo esc_attr( $ver_id ); ?>">
            <?php endif; ?>
            <input type="hidden" class="js-simple-id" name="gcrev_simple_id" value="<?php echo (int) $simple_id; ?>">
            <input type="hidden" class="js-detail-id" name="gcrev_detail_id" value="<?php echo (int) $detail_id; ?>">

            <label style="font-weight:600;">対象月</label>
            <span><input type="text" name="gcrev_period" value="<?php echo esc_attr( $period ); ?>" placeholder="2026-04" pattern="\d{4}-(0[1-9]|1[0-2])" style="width:120px;"> <span style="color:#666;font-size:12px;">YYYY-MM 形式（並び順に使用、空でも可）</span></span>

            <label style="font-weight:600;">ラベル</label>
            <input type="text" name="gcrev_label" value="<?php echo esc_attr( $label ); ?>" placeholder="例: 2026年4月リニューアル報告" style="width:100%;">

            <label style="font-weight:600;">📋 概要版HTML</label>
            <span>
                <button type="button" class="button js-pick-file" data-target="simple">📁 ファイルを選択</button>
                <button type="button" class="button js-clear-file" data-target="simple">解除</button>
                <span class="js-simple-info" style="margin-left:8px;font-size:12px;">
                    <?php if ( $simple_url ) : ?>
                        <a href="<?php echo esc_url( $simple_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $simple_name ); ?></a>
                    <?php else : ?>
                        <span style="color:#999;">未設定</span>
                    <?php endif; ?>
                </span>
            </span>

            <label style="font-weight:600;">📊 詳細版HTML</label>
            <span>
                <button type="button" class="button js-pick-file" data-target="detail">📁 ファイルを選択</button>
                <button type="button" class="button js-clear-file" data-target="detail">解除</button>
                <span class="js-detail-info" style="margin-left:8px;font-size:12px;">
                    <?php if ( $detail_url ) : ?>
                        <a href="<?php echo esc_url( $detail_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $detail_name ); ?></a>
                    <?php else : ?>
                        <span style="color:#999;">未設定</span>
                    <?php endif; ?>
                </span>
            </span>

            <span></span>
            <span><button type="submit" class="button button-primary"><?php echo $is_edit ? '更新する' : '➕ このバージョンを追加'; ?></button></span>
        </form>
        <?php
    }

    private function msg_to_html( string $code ): string {
        $map = [
            'added'          => [ 'success', 'バージョンを追加しました' ],
            'updated'        => [ 'success', 'バージョンを更新しました' ],
            'deleted'        => [ 'success', 'バージョンを削除しました' ],
            'invalid_simple' => [ 'error',   '概要版に HTML 以外のファイルが指定されました' ],
            'invalid_detail' => [ 'error',   '詳細版に HTML 以外のファイルが指定されました' ],
            'invalid_period' => [ 'error',   '対象月は YYYY-MM 形式で入力してください' ],
            'no_files'       => [ 'error',   '概要版または詳細版のいずれかは指定してください' ],
            'no_user'        => [ 'error',   '対象ユーザーが指定されていません' ],
            'not_found'      => [ 'error',   '対象バージョンが見つかりませんでした' ],
        ];
        if ( ! isset( $map[ $code ] ) ) return '';
        [ $type, $msg ] = $map[ $code ];
        return '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }

    // =========================================================
    // フロント配信
    // =========================================================

    /**
     * ユーザーの手動レポートをブラウザに直接出力。
     *   $kind: 'simple' or 'detail'
     *   $ver_id: '' = 最新, それ以外 = 該当バージョン
     *
     * 戻り値: true = 出力した（呼び出し側は exit すべき）／ false = 設定なし
     */
    public static function serve_for_current_user( string $kind, string $ver_id = '' ): bool {
        $log = function ( string $msg ) {
            file_put_contents(
                '/tmp/gcrev_strategy_report_debug.log',
                date( 'Y-m-d H:i:s' ) . ' ' . $msg . "\n",
                FILE_APPEND
            );
        };

        if ( ! in_array( $kind, [ 'simple', 'detail' ], true ) ) {
            $log( "[serve] invalid kind={$kind}" );
            return false;
        }
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( $_SERVER['REQUEST_URI'] ?? home_url('/') ) );
            exit;
        }
        $user_id = get_current_user_id();
        $log( "[serve] user_id={$user_id} kind={$kind} ver_id='{$ver_id}'" );

        $version = $ver_id !== ''
            ? self::get_version( $user_id, $ver_id )
            : self::get_latest( $user_id );

        if ( ! $version ) {
            $log( "[serve] no version for user_id={$user_id}" );
            return false;
        }

        $att_id = (int) ( $kind === 'simple' ? ( $version['simple_id'] ?? 0 ) : ( $version['detail_id'] ?? 0 ) );
        if ( $att_id <= 0 ) {
            $log( "[serve] att_id=0 for kind={$kind}" );
            return false;
        }

        $file = get_attached_file( $att_id );
        if ( ! $file || ! is_readable( $file ) ) {
            $log( "[serve] file not readable att_id={$att_id} file=" . var_export( $file, true ) );
            return false;
        }

        $html = file_get_contents( $file );
        if ( $html === false ) {
            $log( "[serve] file_get_contents FAILED file={$file}" );
            return false;
        }
        $log( "[serve] OK att_id={$att_id} bytes=" . strlen( $html ) );

        // 概要版の場合: 詳細版HTMLへの相対リンクを /strategy-report-detail/?ver=... に書換
        if ( $kind === 'simple' && (int) ( $version['detail_id'] ?? 0 ) > 0 ) {
            $detail_route = home_url( '/strategy-report-detail/' );
            $ver_param    = ! empty( $version['id'] ) ? '?ver=' . rawurlencode( $version['id'] ) : '';

            // シンプルな regex で href 値だけ抜き、判定は PHP 側で行う
            // （複雑な regex は PCRE backtrack/JIT stack limit で失敗することがある）
            $rewritten = preg_replace_callback(
                '/href\s*=\s*(["\'])([^"\'\s]+)\1/i',
                function ( $m ) use ( $detail_route, $ver_param ) {
                    $url = $m[2];
                    // 絶対URL/protocol-relative はスキップ
                    if ( strncasecmp( $url, 'http://', 7 ) === 0 ) return $m[0];
                    if ( strncasecmp( $url, 'https://', 8 ) === 0 ) return $m[0];
                    if ( strpos( $url, '//' ) === 0 ) return $m[0];
                    // .html / .htm（アンカー付き含む）以外はスキップ
                    $hash_pos = strpos( $url, '#' );
                    $path     = $hash_pos !== false ? substr( $url, 0, $hash_pos ) : $url;
                    $anchor   = $hash_pos !== false ? substr( $url, $hash_pos ) : '';
                    if ( ! preg_match( '/\.html?$/i', $path ) ) return $m[0];
                    return 'href=' . $m[1] . $detail_route . $ver_param . $anchor . $m[1];
                },
                $html
            );
            if ( is_string( $rewritten ) ) {
                $html = $rewritten;
            } else {
                $log( '[serve] preg_replace_callback returned non-string (PCRE error code: ' . preg_last_error() . '), keep original html' );
            }
        }

        // iframe 埋込時は親ページに高さを伝えるためのスクリプトを注入
        // （テーマ内表示で iframe の高さをコンテンツに合わせるため）
        $resize_script = '<script>(function(){'
            . 'function send(){var h=Math.max(document.documentElement.scrollHeight,document.body.scrollHeight);'
            . 'try{if(window.parent&&window.parent!==window){window.parent.postMessage({type:"mimamori-report-height",height:h},"*");}}catch(e){}}'
            . 'window.addEventListener("load",send);window.addEventListener("resize",send);'
            . 'if(window.ResizeObserver){var ro=new ResizeObserver(send);ro.observe(document.body);}'
            . 'setInterval(send,1500);'
            . '})();</script>';

        // フローティングナビ: iframe 埋込時はテーマのサイドバー/ヘッダーで足りるので非表示
        $is_embed_request = isset( $_GET['embed'] ) && $_GET['embed'] === '1';
        $nav = $is_embed_request ? '' : self::build_floating_nav( $kind );

        $injection = $resize_script . $nav;

        if ( $injection !== '' && is_string( $html ) ) {
            if ( stripos( $html, '</body>' ) !== false ) {
                $replaced = preg_replace( '#</body>#i', $injection . '</body>', $html, 1 );
                $html = is_string( $replaced ) ? $replaced : ( $html . $injection );
            } else {
                $html .= $injection;
            }
        }

        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex, nofollow', true );
        echo $html;
        return true;
    }

    private static function build_floating_nav( string $kind ): string {
        $history_url   = esc_url( home_url( '/strategy-report-history/' ) );
        $dashboard_url = esc_url( home_url( '/dashboard/' ) );
        $back_label    = $kind === 'detail' ? '概要版に戻る' : '過去のレポート';
        $back_href     = $kind === 'detail' ? esc_url( home_url( '/strategy-report/' ) ) : $history_url;

        $css = '<style>'
            . '.mw-report-nav{position:fixed;right:20px;bottom:20px;display:flex;flex-direction:column;gap:8px;z-index:9999;font-family:system-ui,-apple-system,"Helvetica Neue",sans-serif;}'
            . '.mw-report-nav a{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;background:#1a1a1a;color:#fff;text-decoration:none;border-radius:24px;font-size:13px;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.18);transition:all .15s;}'
            . '.mw-report-nav a:hover{background:#333;transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,0.22);}'
            . '.mw-report-nav a.mw-report-nav__sub{background:#fff;color:#333;border:1px solid #ddd;}'
            . '.mw-report-nav a.mw-report-nav__sub:hover{background:#f5f5f5;}'
            . '@media print{.mw-report-nav{display:none;}}'
            . '</style>';

        $html = '<div class="mw-report-nav" aria-hidden="false">'
            . '<a href="' . $back_href . '">📚 ' . esc_html( $back_label ) . '</a>'
            . '<a class="mw-report-nav__sub" href="' . $dashboard_url . '">🏠 ダッシュボード</a>'
            . '</div>';

        return $css . $html;
    }
}
