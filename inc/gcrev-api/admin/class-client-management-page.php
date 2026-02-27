<?php
// FILE: inc/gcrev-api/admin/class-client-management-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Client_Management_Page') ) { return; }

/**
 * Gcrev_Client_Management_Page
 *
 * WordPress管理画面に「みまもりウェブ > クライアント管理」ページを追加する。
 * - クライアント一覧表示
 * - クライアント別キャッシュ削除
 * - クライアント別レポート全削除
 *
 * @package GCREV_INSIGHT
 */
class Gcrev_Client_Management_Page {

    /** メニュースラッグ */
    private const MENU_SLUG = 'gcrev-client-management';

    /**
     * フック登録
     */
    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
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
            'クライアント管理 - みまもりウェブ',
            "\xF0\x9F\x91\xA5 クライアント管理", // 👥
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================
    // POSTアクション処理
    // =========================================================

    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // nonce 検証
        if ( ! isset( $_POST['_gcrev_client_mgmt_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['_gcrev_client_mgmt_nonce'] ) ),
            'gcrev_client_mgmt_action'
        ) ) {
            return;
        }

        $action  = isset( $_POST['gcrev_action'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_action'] ) ) : '';
        $user_id = isset( $_POST['gcrev_target_user'] ) ? absint( $_POST['gcrev_target_user'] ) : 0;

        $result_msg = '';

        switch ( $action ) {
            case 'clear_user_cache':
                if ( $user_id > 0 ) {
                    $deleted    = $this->delete_user_cache( $user_id );
                    $result_msg = "user_{$user_id}_cache_cleared_{$deleted}";
                }
                break;

            case 'delete_user_reports':
                if ( $user_id > 0 ) {
                    $deleted    = $this->delete_user_reports( $user_id );
                    $result_msg = "user_{$user_id}_reports_deleted_{$deleted}";
                }
                break;

            case 'clear_all_cache':
                $deleted    = $this->delete_all_cache();
                $result_msg = "all_cache_cleared_{$deleted}";
                break;

            default:
                return;
        }

        // リダイレクト（PRGパターン）
        wp_safe_redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'action_done' => $result_msg ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    // =========================================================
    // ページ描画
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $this->render_success_message();
        ?>
        <div class="wrap">
            <h1>👥 クライアント管理</h1>
            <p>クライアントごとのキャッシュ削除・レポート管理を行います。</p>

            <?php $this->render_bulk_actions(); ?>
            <?php $this->render_client_table(); ?>
        </div>
        <?php
    }

    /**
     * 成功メッセージ表示
     */
    private function render_success_message(): void {
        if ( ! isset( $_GET['action_done'] ) ) {
            return;
        }

        $msg = sanitize_text_field( wp_unslash( $_GET['action_done'] ) );

        // パターンマッチでメッセージ生成
        if ( preg_match( '/^user_(\d+)_cache_cleared_(\d+)$/', $msg, $m ) ) {
            $user = get_user_by( 'id', (int) $m[1] );
            $name = $user ? esc_html( $user->display_name ) : "ID:{$m[1]}";
            $text = "{$name} のキャッシュを {$m[2]} 件削除しました。";
        } elseif ( preg_match( '/^user_(\d+)_reports_deleted_(\d+)$/', $msg, $m ) ) {
            $user = get_user_by( 'id', (int) $m[1] );
            $name = $user ? esc_html( $user->display_name ) : "ID:{$m[1]}";
            $text = "{$name} のレポートを {$m[2]} 件削除しました。";
        } elseif ( preg_match( '/^all_cache_cleared_(\d+)$/', $msg, $m ) ) {
            $text = "全クライアントのキャッシュを {$m[1]} 件削除しました。";
        } else {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>✅ ' . $text . '</p></div>';
    }

    /**
     * 一括操作ボタン
     */
    private function render_bulk_actions(): void {
        ?>
        <div style="margin: 16px 0; padding: 16px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #B5574B;">
            <h3 style="margin: 0 0 8px;">⚠️ 一括操作</h3>
            <form method="post" style="display: inline;" onsubmit="return confirm('全クライアントのキャッシュを削除します。よろしいですか？');">
                <?php wp_nonce_field( 'gcrev_client_mgmt_action', '_gcrev_client_mgmt_nonce' ); ?>
                <input type="hidden" name="gcrev_action" value="clear_all_cache">
                <button type="submit" class="button" style="color: #B5574B; border-color: #B5574B;">
                    🗑 全クライアントのキャッシュを一括削除
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * クライアント一覧テーブル
     */
    private function render_client_table(): void {
        $users = get_users([
            'role__not_in' => [ 'administrator' ],
            'orderby'      => 'registered',
            'order'        => 'DESC',
        ]);

        if ( empty( $users ) ) {
            echo '<p>クライアントが登録されていません。</p>';
            return;
        }

        ?>
        <table class="widefat striped" style="margin-top: 16px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ユーザー</th>
                    <th>メール</th>
                    <th>サイトURL</th>
                    <th>状態</th>
                    <th style="text-align: center;">キャッシュ</th>
                    <th style="text-align: center;">レポート</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $users as $user ) : ?>
                <?php
                    $uid       = $user->ID;
                    $site_url  = get_user_meta( $uid, 'report_site_url', true )
                                 ?: get_user_meta( $uid, 'weisite_url', true );
                    $is_test   = ( get_user_meta( $uid, 'gcrev_test_operation', true ) === '1' );
                    $is_paid   = function_exists( 'gcrev_is_payment_active' ) ? gcrev_is_payment_active( $uid ) : false;
                    $cache_cnt = $this->get_user_cache_count( $uid );
                    $report_cnt = $this->get_user_report_count( $uid );
                ?>
                <tr>
                    <td style="color: #999;"><?php echo esc_html( $uid ); ?></td>
                    <td>
                        <strong><?php echo esc_html( $user->display_name ); ?></strong>
                    </td>
                    <td><?php echo esc_html( $user->user_email ); ?></td>
                    <td>
                        <?php if ( $site_url ) : ?>
                            <a href="<?php echo esc_url( $site_url ); ?>" target="_blank" rel="noopener noreferrer" style="font-size: 12px;">
                                <?php echo esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $site_url ) ) ); ?>
                            </a>
                        <?php else : ?>
                            <span style="color: #999;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $is_test ) : ?>
                            <span style="display:inline-block; padding:2px 8px; font-size:11px; font-weight:600; color:#c0392b; background:#fdf0ee; border:1px solid #e8c4bf; border-radius:3px;">テスト運用</span>
                        <?php elseif ( $is_paid ) : ?>
                            <span style="display:inline-block; padding:2px 8px; font-size:11px; font-weight:600; color:#3D8B6E; background:rgba(61,139,110,0.08); border-radius:3px;">利用中</span>
                        <?php else : ?>
                            <span style="display:inline-block; padding:2px 8px; font-size:11px; font-weight:600; color:#999; background:#f5f5f5; border-radius:3px;">手続中</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php if ( $cache_cnt > 0 ) : ?>
                            <span style="font-weight: 600; color: #3D6B6E;"><?php echo esc_html( $cache_cnt ); ?></span>件
                        <?php else : ?>
                            <span style="color: #999;">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php if ( $report_cnt > 0 ) : ?>
                            <span style="font-weight: 600; color: #3D6B6E;"><?php echo esc_html( $report_cnt ); ?></span>件
                        <?php else : ?>
                            <span style="color: #999;">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                        <?php if ( $cache_cnt > 0 ) : ?>
                        <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( $user->display_name ); ?> のキャッシュを削除します。よろしいですか？');">
                            <?php wp_nonce_field( 'gcrev_client_mgmt_action', '_gcrev_client_mgmt_nonce' ); ?>
                            <input type="hidden" name="gcrev_action" value="clear_user_cache">
                            <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $uid ); ?>">
                            <button type="submit" class="button button-small" title="キャッシュ削除">🔄 キャッシュ</button>
                        </form>
                        <?php endif; ?>

                        <?php if ( $report_cnt > 0 ) : ?>
                        <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( $user->display_name ); ?> のレポートをすべて削除します。この操作は取り消せません。よろしいですか？');">
                            <?php wp_nonce_field( 'gcrev_client_mgmt_action', '_gcrev_client_mgmt_nonce' ); ?>
                            <input type="hidden" name="gcrev_action" value="delete_user_reports">
                            <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $uid ); ?>">
                            <button type="submit" class="button button-small" style="color: #B5574B;" title="レポート全削除">🗑 レポート</button>
                        </form>
                        <?php endif; ?>

                        <a href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>" class="button button-small" title="ユーザー編集">✏️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top: 8px;">
            ※ キャッシュ削除: ダッシュボード・分析ページのデータキャッシュを削除します（次回アクセス時に再取得）。<br>
            ※ レポート全削除: そのクライアントの全月次レポートを完全に削除します（復元不可）。
        </p>
        <?php
    }

    // =========================================================
    // データ操作
    // =========================================================

    /**
     * 指定ユーザーのキャッシュ件数を取得
     */
    private function get_user_cache_count( int $user_id ): int {
        global $wpdb;

        $count = 0;
        foreach ( Gcrev_Insight_API::get_all_cache_prefixes() as $prefix ) {
            $count += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . $prefix . $user_id . '_' ) . '%'
            ) );
        }

        return $count;
    }

    /**
     * 指定ユーザーのレポート件数を取得
     */
    private function get_user_report_count( int $user_id ): int {
        $reports = get_posts([
            'post_type'      => 'gcrev_report',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_gcrev_user_id',
                    'value' => $user_id,
                ],
            ],
        ]);

        return count( $reports );
    }

    /**
     * 指定ユーザーのキャッシュを削除
     */
    private function delete_user_cache( int $user_id ): int {
        global $wpdb;

        $deleted = 0;
        foreach ( Gcrev_Insight_API::get_all_cache_prefixes() as $prefix ) {
            $user_prefix = $prefix . $user_id . '_';
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . $user_prefix ) . '%',
                $wpdb->esc_like( '_transient_timeout_' . $user_prefix ) . '%'
            ) );
        }

        error_log( "[GCREV] Admin: cache cleared for user {$user_id}: {$deleted} entries" );
        return $deleted;
    }

    /**
     * 指定ユーザーのレポートをすべて削除
     */
    private function delete_user_reports( int $user_id ): int {
        $reports = get_posts([
            'post_type'      => 'gcrev_report',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_gcrev_user_id',
                    'value' => $user_id,
                ],
            ],
        ]);

        $deleted = 0;
        foreach ( $reports as $report_id ) {
            if ( wp_delete_post( $report_id, true ) ) {
                $deleted++;
            }
        }

        error_log( "[GCREV] Admin: {$deleted} reports deleted for user {$user_id}" );
        return $deleted;
    }

    /**
     * 全キャッシュを削除
     */
    private function delete_all_cache(): int {
        global $wpdb;

        $deleted = 0;
        foreach ( Gcrev_Insight_API::get_all_cache_prefixes() as $prefix ) {
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . $prefix ) . '%',
                $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
            ) );
        }

        error_log( "[GCREV] Admin: ALL cache cleared: {$deleted} entries" );
        return $deleted;
    }
}
