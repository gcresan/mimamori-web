<?php
// FILE: inc/gcrev-api/admin/class-prefetch-management-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Prefetch_Management_Page') ) { return; }

/**
 * Gcrev_Prefetch_Management_Page
 *
 * WordPress管理画面に「みまもりウェブ > データ取得管理」ページを追加する。
 * - Cronステータス表示
 * - クライアント別プリフェッチ状況一覧
 * - 手動取得・接続確認
 * - エラー表示
 *
 * @package Mimamori_Web
 * @since   2.0.0
 */
class Gcrev_Prefetch_Management_Page {

    /** メニュースラッグ */
    private const MENU_SLUG = 'gcrev-prefetch-management';

    /** nonce */
    private const NONCE_ACTION = 'gcrev_prefetch_mgmt_action';
    private const NONCE_FIELD  = '_gcrev_prefetch_mgmt_nonce';

    /** 期間ラベルマップ */
    private const PERIOD_LABELS = [
        'last30'        => '直近30日',
        'last90'        => '過去90日',
        'previousMonth' => '前月',
        'twoMonthsAgo'  => '前々月',
        'last180'       => '過去半年',
        'last365'       => '過去1年',
    ];

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
            'データ取得管理 - みまもりウェブ',
            "\xF0\x9F\x93\xA6 データ取得管理", // 📦
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

        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
            return;
        }
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ),
            self::NONCE_ACTION
        ) ) {
            return;
        }

        $action  = isset( $_POST['gcrev_action'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_action'] ) ) : '';
        $user_id = isset( $_POST['gcrev_target_user'] ) ? absint( $_POST['gcrev_target_user'] ) : 0;
        $period  = isset( $_POST['gcrev_target_period'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_target_period'] ) ) : '';

        $api = new Gcrev_Insight_API( false );

        switch ( $action ) {
            case 'fetch_single_period':
                if ( $user_id > 0 && $period ) {
                    $api->manual_fetch_for_user( $user_id, $period );
                    $this->redirect_with_notice( 'single_ok', $user_id );
                }
                break;

            case 'fetch_all_periods':
                if ( $user_id > 0 ) {
                    $all_periods = [ 'last30', 'last90', 'previousMonth', 'twoMonthsAgo', 'last180', 'last365' ];
                    foreach ( $all_periods as $p ) {
                        $api->manual_fetch_for_user( $user_id, $p );
                    }
                    $this->redirect_with_notice( 'all_ok', $user_id );
                }
                break;

            case 'fetch_all_daily':
                // 全クライアント: 日次データ再取得（バックグラウンド）
                wp_schedule_single_event( time() + 5, 'gcrev_prefetch_chunk_event', [ 0, 5 ] );
                $this->redirect_with_notice( 'daily_scheduled' );
                break;

            case 'fetch_all_monthly':
                // 全クライアント: 月次データ再取得（バックグラウンド）
                wp_schedule_single_event( time() + 5, 'gcrev_monthly_prefetch_chunk_event', [ 0, 5 ] );
                $this->redirect_with_notice( 'monthly_scheduled' );
                break;

            case 'connection_check':
                if ( $user_id > 0 ) {
                    $result = $api->check_api_connection( $user_id );
                    $status = ( $result['ga4'] && $result['gsc'] ) ? 'conn_ok' : 'conn_fail';
                    $this->redirect_with_notice( $status, $user_id );
                }
                break;
        }
    }

    /**
     * PRG パターンでリダイレクト
     */
    private function redirect_with_notice( string $notice, int $user_id = 0 ): void {
        $url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&notice=' . $notice );
        if ( $user_id > 0 ) {
            $url .= '&uid=' . $user_id;
        }
        wp_safe_redirect( $url );
        exit;
    }

    // =========================================================
    // ページ描画
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $notice  = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : '';
        $uid     = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;

        // データ取得
        $api      = new Gcrev_Insight_API( false );
        $statuses = $api->get_prefetch_statuses();

        // ステータスをユーザーID × 期間でインデックス化
        $status_map = [];
        foreach ( $statuses as $s ) {
            $status_map[ (int) $s->user_id ][ $s->period ] = $s;
        }

        // クライアント一覧取得
        $users = get_users( [
            'role__not_in' => [ 'administrator' ],
            'orderby'      => 'display_name',
            'order'         => 'ASC',
            'fields'        => [ 'ID', 'display_name' ],
        ] );

        // エラー一覧
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_prefetch_status';
        $errors = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY fetched_at DESC LIMIT 50",
                'error'
            )
        );

        ?>
        <div class="wrap">
            <h1>📦 データ取得管理</h1>

            <?php $this->render_notices( $notice, $uid ); ?>

            <?php $this->render_cron_status(); ?>

            <?php $this->render_bulk_actions(); ?>

            <?php $this->render_client_table( $users, $status_map ); ?>

            <?php $this->render_error_log( $errors ); ?>
        </div>

        <style>
            .gcrev-prefetch-wrap { margin-top: 20px; }
            .gcrev-status-cards { display: flex; gap: 20px; margin: 15px 0; flex-wrap: wrap; }
            .gcrev-status-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 16px 20px; min-width: 280px; flex: 1; }
            .gcrev-status-card h3 { margin: 0 0 10px; font-size: 15px; }
            .gcrev-status-card .value { font-size: 14px; color: #444; }
            .gcrev-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
            .gcrev-badge-success { background: #d4edda; color: #155724; }
            .gcrev-badge-error { background: #f8d7da; color: #721c24; }
            .gcrev-badge-stale { background: #fff3cd; color: #856404; }
            .gcrev-badge-none { background: #e2e3e5; color: #383d41; }
            .gcrev-badge-locked { background: #cce5ff; color: #004085; }
            .gcrev-client-table { width: 100%; border-collapse: collapse; background: #fff; }
            .gcrev-client-table th, .gcrev-client-table td { padding: 8px 10px; border: 1px solid #ddd; font-size: 13px; text-align: center; }
            .gcrev-client-table th { background: #f1f1f1; font-weight: 600; }
            .gcrev-client-table td:first-child, .gcrev-client-table td:nth-child(2) { text-align: left; }
            .gcrev-client-table .actions-cell { white-space: nowrap; }
            .gcrev-bulk-actions { margin: 20px 0; display: flex; gap: 10px; flex-wrap: wrap; }
            .gcrev-bulk-actions form { display: inline; }
            .gcrev-error-table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 10px; }
            .gcrev-error-table th, .gcrev-error-table td { padding: 8px 10px; border: 1px solid #ddd; font-size: 12px; }
            .gcrev-error-table th { background: #f8d7da; }
            .gcrev-section { margin: 25px 0; }
            .gcrev-section h2 { font-size: 16px; border-bottom: 2px solid #568184; padding-bottom: 6px; }
        </style>
        <?php
    }

    // =========================================================
    // 通知メッセージ
    // =========================================================

    private function render_notices( string $notice, int $uid ): void {
        if ( ! $notice ) return;

        $messages = [
            'single_ok'        => '指定期間のデータを取得しました。',
            'all_ok'           => '全期間のデータ取得を完了しました。',
            'daily_scheduled'  => '全クライアント日次データ取得をバックグラウンドでスケジュールしました。',
            'monthly_scheduled' => '全クライアント月次データ取得をバックグラウンドでスケジュールしました。',
            'conn_ok'          => 'API接続テスト成功（GA4/GSC）。',
            'conn_fail'        => 'API接続テストに失敗しました。設定を確認してください。',
        ];

        $type = in_array( $notice, [ 'conn_fail' ], true ) ? 'error' : 'success';
        $msg  = $messages[ $notice ] ?? '操作を完了しました。';
        if ( $uid > 0 ) {
            $user = get_userdata( $uid );
            $msg = ( $user ? esc_html( $user->display_name ) : "ID:{$uid}" ) . ' — ' . $msg;
        }

        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
    }

    // =========================================================
    // セクション A: Cronステータスカード
    // =========================================================

    private function render_cron_status(): void {
        ?>
        <div class="gcrev-section">
            <h2>⏱ Cronステータス</h2>
            <div class="gcrev-status-cards">
                <?php
                $this->render_cron_card( '日次プリフェッチ', 'gcrev_prefetch_daily_event', 'gcrev_lock_prefetch' );
                $this->render_cron_card( '月次プリフェッチ', 'gcrev_monthly_data_prefetch_event', 'gcrev_lock_monthly_prefetch' );
                ?>
            </div>
        </div>
        <?php
    }

    private function render_cron_card( string $title, string $hook, string $lock_key ): void {
        $next_ts = wp_next_scheduled( $hook );
        $locked  = (bool) get_transient( $lock_key );

        ?>
        <div class="gcrev-status-card">
            <h3><?php echo esc_html( $title ); ?></h3>
            <div class="value">
                次回実行:
                <?php
                if ( $next_ts ) {
                    $tz = wp_timezone();
                    $dt = ( new DateTimeImmutable( '@' . $next_ts ) )->setTimezone( $tz );
                    echo esc_html( $dt->format( 'Y-m-d H:i:s' ) );
                } else {
                    echo '<span style="color:#999;">未スケジュール</span>';
                }
                ?>
            </div>
            <div class="value" style="margin-top:6px;">
                ロック:
                <?php if ( $locked ): ?>
                    <span class="gcrev-badge gcrev-badge-locked">実行中</span>
                <?php else: ?>
                    <span class="gcrev-badge gcrev-badge-success">解放</span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // =========================================================
    // セクション C: 一括操作
    // =========================================================

    private function render_bulk_actions(): void {
        ?>
        <div class="gcrev-section">
            <h2>🔄 一括操作</h2>
            <div class="gcrev-bulk-actions">
                <?php $this->render_bulk_button( 'fetch_all_daily', '全クライアント: 日次データ再取得', 'button button-secondary' ); ?>
                <?php $this->render_bulk_button( 'fetch_all_monthly', '全クライアント: 月次データ再取得', 'button button-secondary' ); ?>
            </div>
            <p class="description">※ 一括操作はバックグラウンドで実行されます。完了まで数分〜数十分かかる場合があります。</p>
        </div>
        <?php
    }

    private function render_bulk_button( string $action, string $label, string $class ): void {
        ?>
        <form method="post">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <input type="hidden" name="gcrev_action" value="<?php echo esc_attr( $action ); ?>">
            <button type="submit" class="<?php echo esc_attr( $class ); ?>"
                    onclick="return confirm('<?php echo esc_js( $label . 'を実行しますか？' ); ?>');">
                <?php echo esc_html( $label ); ?>
            </button>
        </form>
        <?php
    }

    // =========================================================
    // セクション B: クライアント一覧テーブル
    // =========================================================

    private function render_client_table( array $users, array $status_map ): void {
        $periods = array_keys( self::PERIOD_LABELS );

        ?>
        <div class="gcrev-section">
            <h2>👥 クライアント別取得状況</h2>
            <table class="gcrev-client-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>クライアント名</th>
                        <th>GA4</th>
                        <th>GSC</th>
                        <?php foreach ( self::PERIOD_LABELS as $label ): ?>
                            <th><?php echo esc_html( $label ); ?></th>
                        <?php endforeach; ?>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $users ) ): ?>
                        <tr><td colspan="<?php echo 5 + count( $periods ); ?>" style="text-align:center;padding:20px;">クライアントが見つかりません</td></tr>
                    <?php else: ?>
                        <?php foreach ( $users as $user ): ?>
                            <?php $this->render_client_row( $user, $status_map, $periods ); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_client_row( $user, array $status_map, array $periods ): void {
        $uid = (int) $user->ID;
        $has_ga4 = (bool) get_user_meta( $uid, 'gcrev_ga4_property_id', true );
        $has_gsc = (bool) get_user_meta( $uid, 'gcrev_gsc_url', true );

        ?>
        <tr>
            <td><?php echo esc_html( $uid ); ?></td>
            <td><?php echo esc_html( $user->display_name ); ?></td>
            <td><?php echo $has_ga4 ? '✓' : '<span style="color:#ccc;">✕</span>'; ?></td>
            <td><?php echo $has_gsc ? '✓' : '<span style="color:#ccc;">✕</span>'; ?></td>
            <?php foreach ( $periods as $period ): ?>
                <td>
                    <?php
                    $s = $status_map[ $uid ][ $period ] ?? null;
                    if ( ! $s ) {
                        echo '<span class="gcrev-badge gcrev-badge-none">未取得</span>';
                    } elseif ( $s->status === 'error' ) {
                        echo '<span class="gcrev-badge gcrev-badge-error">エラー</span>';
                        echo '<br><small>' . esc_html( substr( $s->fetched_at, 5, 11 ) ) . '</small>';
                    } else {
                        // TTL 切れチェック
                        $fetched = strtotime( $s->fetched_at );
                        $is_monthly = Gcrev_Date_Helper::is_monthly_fixed_period( $period );
                        $ttl = $is_monthly ? 3024000 : ( $period === 'last90' ? 172800 : 86400 );
                        $stale = ( time() - $fetched ) > $ttl;

                        if ( $stale ) {
                            echo '<span class="gcrev-badge gcrev-badge-stale">TTL切れ</span>';
                        } else {
                            echo '<span class="gcrev-badge gcrev-badge-success">成功</span>';
                        }
                        echo '<br><small>' . esc_html( substr( $s->fetched_at, 5, 11 ) ) . '</small>';
                    }
                    ?>
                </td>
            <?php endforeach; ?>
            <td class="actions-cell">
                <!-- 全期間取得 -->
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="gcrev_action" value="fetch_all_periods">
                    <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $uid ); ?>">
                    <button type="submit" class="button button-small" title="全期間取得">📥 全取得</button>
                </form>
                <!-- 接続確認 -->
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="gcrev_action" value="connection_check">
                    <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $uid ); ?>">
                    <button type="submit" class="button button-small" title="接続確認">🔌 確認</button>
                </form>
            </td>
        </tr>
        <?php
    }

    // =========================================================
    // セクション D: エラーログ
    // =========================================================

    private function render_error_log( array $errors ): void {
        ?>
        <div class="gcrev-section">
            <h2>⚠️ エラーログ（直近50件）</h2>
            <?php if ( empty( $errors ) ): ?>
                <p style="color:#666;">エラーはありません。</p>
            <?php else: ?>
                <table class="gcrev-error-table">
                    <thead>
                        <tr>
                            <th>日時</th>
                            <th>ユーザーID</th>
                            <th>期間</th>
                            <th>データ種別</th>
                            <th>ソース</th>
                            <th>エラー内容</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $errors as $e ): ?>
                            <tr>
                                <td><?php echo esc_html( $e->fetched_at ); ?></td>
                                <td><?php echo esc_html( $e->user_id ); ?></td>
                                <td><?php echo esc_html( self::PERIOD_LABELS[ $e->period ] ?? $e->period ); ?></td>
                                <td><?php echo esc_html( $e->data_type ); ?></td>
                                <td><?php echo esc_html( $e->source ); ?></td>
                                <td><?php echo esc_html( $e->error_message ?: '-' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
