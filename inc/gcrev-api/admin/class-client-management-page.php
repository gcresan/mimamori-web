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
 * @package Mimamori_Web
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

            case 'delete_seo_history':
                if ( $user_id > 0 ) {
                    $deleted    = $this->delete_user_seo_history( $user_id );
                    $result_msg = "user_{$user_id}_seo_deleted_{$deleted}";
                }
                break;

            case 'clear_all_cache':
                $deleted    = $this->delete_all_cache();
                $result_msg = "all_cache_cleared_{$deleted}";
                break;

            case 'change_tier':
                if ( $user_id > 0 ) {
                    $new_tier   = isset( $_POST['gcrev_new_tier'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_new_tier'] ) ) : '';
                    $result_msg = $this->change_user_tier( $user_id, $new_tier );
                }
                break;

            case 'change_state':
                if ( $user_id > 0 ) {
                    $new_state  = isset( $_POST['gcrev_new_state'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_new_state'] ) ) : '';
                    $result_msg = $this->change_user_state( $user_id, $new_state );
                }
                break;

            case 'delete_client':
                if ( $user_id > 0 ) {
                    $result_msg = $this->delete_client( $user_id );
                }
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
            <p>クライアントごとのプラン・状態の変更、キャッシュ削除・レポート管理、クライアント削除を行います。</p>

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
            $name = $user ? esc_html( gcrev_get_business_name( (int) $m[1] ) ) : "ID:{$m[1]}";
            $text = "{$name} のキャッシュを {$m[2]} 件削除しました。";
        } elseif ( preg_match( '/^user_(\d+)_reports_deleted_(\d+)$/', $msg, $m ) ) {
            $user = get_user_by( 'id', (int) $m[1] );
            $name = $user ? esc_html( gcrev_get_business_name( (int) $m[1] ) ) : "ID:{$m[1]}";
            $text = "{$name} のレポートを {$m[2]} 件削除しました。";
        } elseif ( preg_match( '/^user_(\d+)_seo_deleted_(\d+)$/', $msg, $m ) ) {
            $user = get_user_by( 'id', (int) $m[1] );
            $name = $user ? esc_html( gcrev_get_business_name( (int) $m[1] ) ) : "ID:{$m[1]}";
            $text = "{$name} のSEO／AIO診断履歴を {$m[2]} 件削除しました。";
        } elseif ( preg_match( '/^all_cache_cleared_(\d+)$/', $msg, $m ) ) {
            $text = "全クライアントのキャッシュを {$m[1]} 件削除しました。";
        } elseif ( preg_match( '/^user_(\d+)_tier_changed$/', $msg, $m ) ) {
            $user = get_user_by( 'id', (int) $m[1] );
            $name = $user ? esc_html( gcrev_get_business_name( (int) $m[1] ) ) : "ID:{$m[1]}";
            $defs = function_exists( 'gcrev_get_service_tier_definitions' ) ? gcrev_get_service_tier_definitions() : [];
            $tier = $user ? (string) get_user_meta( (int) $m[1], 'gcrev_service_tier', true ) : '';
            $tier_name = $defs[ $tier ]['name'] ?? $tier;
            $text = "{$name} のプランを「" . esc_html( $tier_name ) . "」に変更しました。";
        } elseif ( preg_match( '/^user_(\d+)_state_(paid|trial|pending)$/', $msg, $m ) ) {
            $user = get_user_by( 'id', (int) $m[1] );
            $name = $user ? esc_html( gcrev_get_business_name( (int) $m[1] ) ) : "ID:{$m[1]}";
            $state_labels = [ 'paid' => '利用中（支払い済み）', 'trial' => 'お試し中', 'pending' => '手続中' ];
            $text = "{$name} の状態を「{$state_labels[ $m[2] ]}」に変更しました。";
        } elseif ( $msg === 'client_deleted' ) {
            $text = "クライアントを削除しました（ユーザー・レポート・データを完全削除）。";
        } elseif ( $msg === 'action_failed' ) {
            echo '<div class="notice notice-error is-dismissible"><p>⚠️ 操作に失敗しました。対象が管理者でないか、入力値をご確認ください。</p></div>';
            return;
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
        <div style="margin: 16px 0; padding: 16px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #C95A4F;">
            <h3 style="margin: 0 0 8px;">⚠️ 一括操作</h3>
            <form method="post" style="display: inline;" onsubmit="return confirm('全クライアントのキャッシュを削除します。よろしいですか？');">
                <?php wp_nonce_field( 'gcrev_client_mgmt_action', '_gcrev_client_mgmt_nonce' ); ?>
                <input type="hidden" name="gcrev_action" value="clear_all_cache">
                <button type="submit" class="button" style="color: #C95A4F; border-color: #C95A4F;">
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
                    <th>ティア</th>
                    <th>状態</th>
                    <th style="text-align: center;">キャッシュ</th>
                    <th style="text-align: center;">レポート</th>
                    <th style="text-align: center;">SEO診断</th>
                    <th style="text-align: center;">オプション</th>
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
                    $seo_cnt   = $this->get_user_seo_history_count( $uid );
                ?>
                <tr>
                    <td style="color: #999;"><?php echo esc_html( $uid ); ?></td>
                    <td>
                        <strong><?php echo esc_html( gcrev_get_business_name( $user->ID ) ); ?></strong>
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
                        <?php
                        $tier = function_exists( 'gcrev_get_service_tier' ) ? gcrev_get_service_tier( $uid ) : 'basic';
                        $tier_defs = function_exists( 'gcrev_get_service_tier_definitions' ) ? gcrev_get_service_tier_definitions() : [];
                        ?>
                        <form method="post" style="margin:0;">
                            <?php wp_nonce_field( 'gcrev_client_mgmt_action', '_gcrev_client_mgmt_nonce' ); ?>
                            <input type="hidden" name="gcrev_action" value="change_tier">
                            <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $uid ); ?>">
                            <select name="gcrev_new_tier" style="font-size:11px; max-width:170px;"
                                onchange="if(confirm('プランを「'+this.options[this.selectedIndex].text+'」に変更します。よろしいですか？')){this.form.submit();}else{this.value='<?php echo esc_js( $tier ); ?>';}">
                                <?php foreach ( $tier_defs as $tkey => $tdef ) :
                                    // 廃止ティアは「現在そのティア」の場合のみ選択肢に残す
                                    if ( ! empty( $tdef['deprecated'] ) && $tkey !== $tier ) { continue; }
                                ?>
                                    <option value="<?php echo esc_attr( $tkey ); ?>" <?php selected( $tier, $tkey ); ?>><?php echo esc_html( $tdef['name'] ?? $tkey ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <?php $current_state = $is_test ? 'trial' : ( $is_paid ? 'paid' : 'pending' ); ?>
                        <form method="post" style="margin:0;">
                            <?php wp_nonce_field( 'gcrev_client_mgmt_action', '_gcrev_client_mgmt_nonce' ); ?>
                            <input type="hidden" name="gcrev_action" value="change_state">
                            <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $uid ); ?>">
                            <select name="gcrev_new_state" style="font-size:11px;"
                                onchange="if(confirm('状態を「'+this.options[this.selectedIndex].text+'」に変更します。よろしいですか？')){this.form.submit();}else{this.value='<?php echo esc_js( $current_state ); ?>';}">
                                <option value="paid" <?php selected( $current_state, 'paid' ); ?>>利用中（支払い済み）</option>
                                <option value="trial" <?php selected( $current_state, 'trial' ); ?>>お試し中</option>
                                <option value="pending" <?php selected( $current_state, 'pending' ); ?>>手続中</option>
                            </select>
                        </form>
                    </td>
                    <td style="text-align: center;">
                        <?php if ( $cache_cnt > 0 ) : ?>
                            <span style="font-weight: 600; color: #568184;"><?php echo esc_html( $cache_cnt ); ?></span>件
                        <?php else : ?>
                            <span style="color: #999;">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php if ( $report_cnt > 0 ) : ?>
                            <span style="font-weight: 600; color: #568184;"><?php echo esc_html( $report_cnt ); ?></span>件
                        <?php else : ?>
                            <span style="color: #999;">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php if ( $seo_cnt > 0 ) : ?>
                            <span style="font-weight: 600; color: #568184;"><?php echo esc_html( $seo_cnt ); ?></span>件
                        <?php else : ?>
                            <span style="color: #999;">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center; white-space: nowrap;">
                        <?php
                        $chatbot_enabled = function_exists( 'mimamori_bot_is_enabled_for_user' )
                            && mimamori_bot_is_enabled_for_user( $uid );
                        $page_analysis_enabled = function_exists( 'mimamori_page_analysis_is_enabled_for_user' )
                            && mimamori_page_analysis_is_enabled_for_user( $uid );
                        ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( gcrev_get_business_name( $uid ) ); ?> の<?php echo $chatbot_enabled ? 'チャットボット機能を無効化' : 'チャットボット機能を有効化'; ?>します。よろしいですか？');">
                            <?php wp_nonce_field( 'gcrev_toggle_chatbot_feature' ); ?>
                            <input type="hidden" name="action" value="gcrev_toggle_chatbot_feature">
                            <input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $uid ); ?>">
                            <?php if ( $chatbot_enabled ) : ?>
                                <button type="submit" title="クリックで無効化" style="border:1px solid #4E8A6B;background:rgba(78,138,107,0.08);color:#4E8A6B;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">💬 ON</button>
                            <?php else : ?>
                                <button type="submit" title="クリックで有効化" style="border:1px solid #d1d5db;background:#fff;color:#9ca3af;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">💬 OFF</button>
                            <?php endif; ?>
                        </form>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-left:4px;" onsubmit="return confirm('<?php echo esc_js( gcrev_get_business_name( $uid ) ); ?> の<?php echo $page_analysis_enabled ? '現状のページ診断機能を無効化' : '現状のページ診断機能を有効化'; ?>します。よろしいですか？');">
                            <?php wp_nonce_field( 'gcrev_toggle_page_analysis_feature' ); ?>
                            <input type="hidden" name="action" value="gcrev_toggle_page_analysis_feature">
                            <input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $uid ); ?>">
                            <?php if ( $page_analysis_enabled ) : ?>
                                <button type="submit" title="クリックで無効化" style="border:1px solid #4E8A6B;background:rgba(78,138,107,0.08);color:#4E8A6B;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">📄 ON</button>
                            <?php else : ?>
                                <button type="submit" title="クリックで有効化" style="border:1px solid #d1d5db;background:#fff;color:#9ca3af;padding:3px 10px;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">📄 OFF</button>
                            <?php endif; ?>
                        </form>
                    </td>
                    <td style="white-space: nowrap;">
                        <?php if ( $cache_cnt > 0 ) : ?>
                        <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( gcrev_get_business_name( $user->ID ) ); ?> のキャッシュを削除します。よろしいですか？');">
                            <?php wp_nonce_field( 'gcrev_client_mgmt_action', '_gcrev_client_mgmt_nonce' ); ?>
                            <input type="hidden" name="gcrev_action" value="clear_user_cache">
                            <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $uid ); ?>">
                            <button type="submit" class="button button-small" style="color: #C95A4F;" title="キャッシュ削除">🗑 キャッシュ削除</button>
                        </form>
                        <?php endif; ?>

                        <?php if ( $report_cnt > 0 ) : ?>
                        <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( gcrev_get_business_name( $user->ID ) ); ?> のレポートをすべて削除します。この操作は取り消せません。よろしいですか？');">
                            <?php wp_nonce_field( 'gcrev_client_mgmt_action', '_gcrev_client_mgmt_nonce' ); ?>
                            <input type="hidden" name="gcrev_action" value="delete_user_reports">
                            <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $uid ); ?>">
                            <button type="submit" class="button button-small" style="color: #C95A4F;" title="レポート全削除">🗑 レポート</button>
                        </form>
                        <?php endif; ?>

                        <?php if ( $seo_cnt > 0 ) : ?>
                        <form method="post" style="display: inline;" onsubmit="return confirm('<?php echo esc_js( gcrev_get_business_name( $user->ID ) ); ?> のSEO／AIO診断履歴（<?php echo esc_js( (string) $seo_cnt ); ?>件）をすべて削除します。この操作は取り消せません。よろしいですか？');">
                            <?php wp_nonce_field( 'gcrev_client_mgmt_action', '_gcrev_client_mgmt_nonce' ); ?>
                            <input type="hidden" name="gcrev_action" value="delete_seo_history">
                            <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $uid ); ?>">
                            <button type="submit" class="button button-small" style="color: #C95A4F;" title="SEO／AIO診断履歴を全削除">🗑 SEO診断</button>
                        </form>
                        <?php endif; ?>

                        <a href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>" class="button button-small" title="ユーザー編集">✏️</a>

                        <form method="post" style="display: inline;" onsubmit="return confirm('【警告】<?php echo esc_js( gcrev_get_business_name( $user->ID ) ); ?> を完全に削除します。\nこのクライアントのユーザーアカウント・レポート・データがすべて削除され、復元できません。\n\n本当に削除しますか？');">
                            <?php wp_nonce_field( 'gcrev_client_mgmt_action', '_gcrev_client_mgmt_nonce' ); ?>
                            <input type="hidden" name="gcrev_action" value="delete_client">
                            <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $uid ); ?>">
                            <button type="submit" class="button button-small" style="color:#fff; background:#C95A4F; border-color:#C95A4F;" title="クライアント完全削除（復元不可）">🗑 クライアント削除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description" style="margin-top: 8px;">
            ※ プラン変更: ティアのプルダウンを選ぶと即時に変更されます（廃止プランは新規選択不可）。<br>
            ※ 状態変更: 「利用中（支払い済み）／お試し中／手続中」を切り替えます。お試し中にすると開始・終了日時が未設定なら自動設定されます。状態は通知配信（支払い済み or 契約中のみ送信）にも影響します。<br>
            ※ キャッシュ削除: ダッシュボード・分析ページのデータキャッシュを削除します（次回アクセス時に再取得）。<br>
            ※ レポート全削除: そのクライアントの全月次レポートを完全に削除します（復元不可）。<br>
            ※ SEO診断削除: そのクライアントのSEO／AIO診断履歴（最大10件）と前回比較データを完全に削除します（復元不可）。次回診断は初回扱いになります。<br>
            ※ <strong style="color:#C95A4F;">クライアント削除</strong>: そのクライアントのユーザーアカウントと投稿（レポート等）を<strong>完全に削除</strong>します（復元不可）。
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
     * プラン（ティア）変更。成功時 "user_{id}_tier_changed"、失敗時 "action_failed"。
     */
    private function change_user_tier( int $user_id, string $tier ): string {
        if ( user_can( $user_id, 'manage_options' ) ) { return 'action_failed'; }
        if ( ! function_exists( 'gcrev_get_valid_service_tiers' ) ) { return 'action_failed'; }
        if ( ! in_array( $tier, gcrev_get_valid_service_tiers(), true ) ) { return 'action_failed'; }
        // 廃止ティアへの新規変更は不可（既存ユーザーの再保存のみ許容）
        $defs = gcrev_get_service_tier_definitions();
        if ( ! empty( $defs[ $tier ]['deprecated'] )
             && get_user_meta( $user_id, 'gcrev_service_tier', true ) !== $tier ) {
            return 'action_failed';
        }
        update_user_meta( $user_id, 'gcrev_service_tier', $tier );
        return "user_{$user_id}_tier_changed";
    }

    /**
     * 状態変更（利用中=支払い済み / お試し中 / 手続中）。
     * - paid    : gcrev_payment_completed='1' かつ お試し解除
     * - trial   : gcrev_test_operation='1'（開始/終了日が未設定なら自動設定）かつ 支払い解除
     * - pending : 両方解除
     * 成功時 "user_{id}_state_{state}"、失敗時 "action_failed"。
     */
    private function change_user_state( int $user_id, string $state ): string {
        if ( user_can( $user_id, 'manage_options' ) ) { return 'action_failed'; }
        if ( ! in_array( $state, [ 'paid', 'trial', 'pending' ], true ) ) { return 'action_failed'; }

        if ( $state === 'paid' ) {
            update_user_meta( $user_id, 'gcrev_payment_completed', '1' );
            delete_user_meta( $user_id, 'gcrev_test_operation' );
        } elseif ( $state === 'trial' ) {
            delete_user_meta( $user_id, 'gcrev_payment_completed' );
            update_user_meta( $user_id, 'gcrev_test_operation', '1' );
            // 開始/終了日時が未設定なら自動設定（プロフィール保存と同じ挙動）
            $tz    = wp_timezone();
            $start = get_user_meta( $user_id, 'gcrev_trial_start', true );
            if ( empty( $start ) ) {
                $start = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );
                update_user_meta( $user_id, 'gcrev_trial_start', $start );
            }
            $end = get_user_meta( $user_id, 'gcrev_trial_end', true );
            if ( empty( $end ) ) {
                $days    = function_exists( 'gcrev_trial_default_days' ) ? (int) gcrev_trial_default_days() : 14;
                $end_obj = ( new \DateTimeImmutable( $start, $tz ) )->modify( "+{$days} days" );
                update_user_meta( $user_id, 'gcrev_trial_end', $end_obj->format( 'Y-m-d H:i:s' ) );
            }
        } else { // pending
            delete_user_meta( $user_id, 'gcrev_payment_completed' );
            delete_user_meta( $user_id, 'gcrev_test_operation' );
        }
        return "user_{$user_id}_state_{$state}";
    }

    /**
     * クライアント（WPユーザー）を完全削除する。復元不可。管理者は削除不可。
     * 投稿（レポート等）も削除される。成功時 "client_deleted"、失敗時 "action_failed"。
     */
    private function delete_client( int $user_id ): string {
        if ( user_can( $user_id, 'manage_options' ) ) { return 'action_failed'; }
        $user = get_userdata( $user_id );
        if ( ! $user ) { return 'action_failed'; }

        // 孤児transient防止のため先にキャッシュも掃除
        $this->delete_user_cache( $user_id );

        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $ok = wp_delete_user( $user_id ); // 投稿（レポート等）も削除される
        return $ok ? 'client_deleted' : 'action_failed';
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

        // トレンドv2キャッシュは prefix と user_id の間に "daily_v2_" / "v2_" が入るため
        // 上記の {prefix}{user_id}_ 照合から漏れる。明示的に削除する（全フィルタ接尾辞を含む）。
        // 例: gcrev_trend_daily_v2_29_sessions, gcrev_trend_daily_v2_29_cv_jp, gcrev_trend_v2_29_sessions_jp_ex
        foreach ( [ 'gcrev_trend_daily_v2_', 'gcrev_trend_v2_' ] as $trend_prefix ) {
            $trend_user_prefix = $trend_prefix . $user_id . '_';
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . $trend_user_prefix ) . '%',
                $wpdb->esc_like( '_transient_timeout_' . $trend_user_prefix ) . '%'
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
     * 指定ユーザーのSEO／AIO診断履歴件数を取得
     */
    private function get_user_seo_history_count( int $user_id ): int {
        if ( ! class_exists( 'Gcrev_SEO_Checker' ) ) {
            return 0;
        }
        return ( new Gcrev_SEO_Checker() )->get_history_count( $user_id );
    }

    /**
     * 指定ユーザーのSEO／AIO診断履歴をすべて削除（復元不可）
     */
    private function delete_user_seo_history( int $user_id ): int {
        if ( ! class_exists( 'Gcrev_SEO_Checker' ) ) {
            return 0;
        }
        $deleted = ( new Gcrev_SEO_Checker() )->delete_diagnosis( $user_id );

        error_log( "[GCREV] Admin: {$deleted} SEO diagnosis histories deleted for user {$user_id}" );
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
