<?php
// FILE: inc/gcrev-api/admin/class-report-queue-page.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Report_Queue_Page' ) ) { return; }

/**
 * Gcrev_Report_Queue_Page
 *
 * 管理画面「みまもりウェブ > レポートキュー」ページ。
 * 月次レポート自動生成キューの監視・リトライ・手動実行を提供する。
 *
 * セクション構成:
 *   A. ステータスカード（ロック・進捗）
 *   B. キューアイテムテーブル（フィルタ・リトライ）
 *   C. ジョブ履歴一覧
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Gcrev_Report_Queue_Page {

    private const MENU_SLUG    = 'gcrev-report-queue';
    private const NONCE_ACTION = 'gcrev_report_queue_action';
    private const NONCE_FIELD  = '_gcrev_report_queue_nonce';

    /** ステータス表示ラベル */
    private const STATUS_LABELS = [
        'pending'    => '待機中',
        'processing' => '処理中',
        'success'    => '完了',
        'failed'     => '失敗',
        'skipped'    => 'スキップ',
    ];

    /** ステータス表示色 */
    private const STATUS_COLORS = [
        'pending'    => '#999',
        'processing' => '#2271b1',
        'success'    => '#00a32a',
        'failed'     => '#d63638',
        'skipped'    => '#dba617',
    ];

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
            'レポートキュー - みまもりウェブ',
            "\xF0\x9F\x93\x8B レポートキュー", // 📋
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

        $action = isset( $_POST['gcrev_action'] ) ? sanitize_text_field( wp_unslash( $_POST['gcrev_action'] ) ) : '';

        switch ( $action ) {
            case 'retry_single':
                $queue_id = isset( $_POST['queue_id'] ) ? absint( $_POST['queue_id'] ) : 0;
                if ( $queue_id > 0 && class_exists( 'Gcrev_Report_Queue' ) ) {
                    $ok = Gcrev_Report_Queue::retry( $queue_id );
                    $this->redirect_with_notice( $ok ? 'retry_ok' : 'retry_max', $queue_id );
                }
                break;

            case 'retry_all_failed':
                $job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
                if ( $job_id > 0 && class_exists( 'Gcrev_Report_Queue' ) ) {
                    $count = Gcrev_Report_Queue::retry_all_failed( $job_id );
                    $this->redirect_with_notice( 'retry_all_ok', $count );
                }
                break;

            case 'release_lock':
                delete_transient( 'gcrev_lock_report_gen' );
                delete_transient( 'gcrev_current_report_gen_log_id' );
                $this->redirect_with_notice( 'lock_released' );
                break;

            case 'trigger_generate':
                // 手動でキュー登録＆チャンク実行を開始
                $api = new Gcrev_Insight_API( false );
                $api->auto_generate_monthly_reports();
                $this->redirect_with_notice( 'generate_triggered' );
                break;

            case 'resume_processing':
                // 失敗リトライ後にチャンク処理を再開
                $job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
                if ( $job_id > 0 ) {
                    wp_schedule_single_event(
                        time() + 5,
                        'gcrev_monthly_report_generate_chunk_event',
                        [ $job_id, Gcrev_Insight_API::REPORT_CHUNK_LIMIT ]
                    );
                    $this->redirect_with_notice( 'resume_ok' );
                }
                break;
        }
    }

    /**
     * PRG リダイレクト
     */
    private function redirect_with_notice( string $notice, int $extra = 0 ): void {
        wp_safe_redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'notice' => $notice, 'extra' => $extra ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    // =========================================================
    // ページ描画
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // 通知メッセージ
        $this->render_notices();
        ?>
        <div class="wrap">
            <h1>みまもりウェブ — レポートキュー</h1>

            <?php $this->render_status_card(); ?>

            <hr />

            <?php $this->render_queue_table(); ?>

            <hr />

            <?php $this->render_job_history(); ?>
        </div>
        <?php
    }

    // =========================================================
    // 通知メッセージ
    // =========================================================

    private function render_notices(): void {
        if ( ! isset( $_GET['notice'] ) ) {
            return;
        }

        $notice = sanitize_text_field( wp_unslash( $_GET['notice'] ) );
        $extra  = isset( $_GET['extra'] ) ? absint( $_GET['extra'] ) : 0;

        $messages = [
            'retry_ok'           => 'キューアイテムをリトライ対象に戻しました。',
            'retry_max'          => 'リトライ上限に達しているため、再実行できません。',
            'retry_all_ok'       => sprintf( '%d 件の失敗アイテムをリトライ対象に戻しました。', $extra ),
            'lock_released'      => 'ロックを手動解放しました。',
            'generate_triggered' => 'レポート生成キューを開始しました。',
            'resume_ok'          => 'チャンク処理を再開スケジュールしました。',
        ];

        $msg = $messages[ $notice ] ?? '';
        if ( $msg ) {
            $type = ( $notice === 'retry_max' ) ? 'warning' : 'success';
            printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
        }
    }

    // =========================================================
    // A. ステータスカード
    // =========================================================

    private function render_status_card(): void {
        if ( ! class_exists( 'Gcrev_Report_Queue' ) ) {
            echo '<div class="notice notice-warning"><p>レポートキューテーブルが未作成です。</p></div>';
            return;
        }

        $lock_value = get_transient( 'gcrev_lock_report_gen' );
        $is_locked  = ! empty( $lock_value );
        $lock_since = is_numeric( $lock_value ) ? wp_date( 'Y-m-d H:i:s', (int) $lock_value ) : '不明';

        // 最新ジョブの年月を推定
        $tz         = wp_timezone();
        $now        = new DateTimeImmutable( 'now', $tz );
        $prev_month = $now->modify( 'first day of last month' )->format( 'Y-m' );
        $current_ym = $now->format( 'Y-m' );

        // 直近のジョブを探す（今月 or 前月）
        $job_id = Gcrev_Report_Queue::get_latest_job_id( $prev_month );
        if ( ! $job_id ) {
            $job_id = Gcrev_Report_Queue::get_latest_job_id( $current_ym );
        }

        $counts = $job_id ? Gcrev_Report_Queue::get_counts_by_status( $job_id ) : null;
        ?>
        <h2>ステータス</h2>
        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px;">

            <!-- ロック状態 -->
            <div style="background: <?php echo $is_locked ? '#fff3cd' : '#d4edda'; ?>; border: 1px solid <?php echo $is_locked ? '#ffc107' : '#28a745'; ?>; border-radius: 8px; padding: 16px 20px; min-width: 200px;">
                <strong>ロック状態:</strong>
                <?php if ( $is_locked ) : ?>
                    <span style="color: #856404;">ロック中</span>
                    <br><small>開始: <?php echo esc_html( $lock_since ); ?></small>
                    <form method="post" style="margin-top: 8px;" onsubmit="return confirm('ロックを解放しますか？');">
                        <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                        <input type="hidden" name="gcrev_action" value="release_lock" />
                        <button type="submit" class="button button-secondary button-small">ロック解放</button>
                    </form>
                <?php else : ?>
                    <span style="color: #155724;">解放済み</span>
                <?php endif; ?>
            </div>

            <!-- 進捗 -->
            <?php if ( $counts && $counts['total'] > 0 ) :
                $done       = $counts['success'] + $counts['failed'] + $counts['skipped'];
                $progress   = round( $done / $counts['total'] * 100, 1 );
                $is_running = $counts['pending'] > 0 || $counts['processing'] > 0;
            ?>
            <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 16px 20px; min-width: 350px;">
                <strong>最新ジョブ (job_id: <?php echo esc_html( (string) $job_id ); ?>)</strong>
                <div style="margin: 8px 0;">
                    <div style="background: #e9ecef; border-radius: 4px; height: 20px; overflow: hidden;">
                        <div style="background: <?php echo $is_running ? '#2271b1' : '#00a32a'; ?>; height: 100%; width: <?php echo esc_attr( (string) $progress ); ?>%; transition: width 0.3s;"></div>
                    </div>
                    <small><?php echo esc_html( (string) $progress ); ?>% (<?php echo esc_html( (string) $done ); ?>/<?php echo esc_html( (string) $counts['total'] ); ?>)</small>
                </div>
                <div style="display: flex; gap: 12px; flex-wrap: wrap; font-size: 13px;">
                    <?php foreach ( [ 'pending', 'processing', 'success', 'failed', 'skipped' ] as $s ) : ?>
                        <span style="color: <?php echo esc_attr( self::STATUS_COLORS[ $s ] ); ?>;">
                            <?php echo esc_html( self::STATUS_LABELS[ $s ] ); ?>: <strong><?php echo esc_html( (string) $counts[ $s ] ); ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>

                <?php if ( $counts['failed'] > 0 ) : ?>
                    <div style="margin-top: 10px; display: flex; gap: 8px;">
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                            <input type="hidden" name="gcrev_action" value="retry_all_failed" />
                            <input type="hidden" name="job_id" value="<?php echo esc_attr( (string) $job_id ); ?>" />
                            <button type="submit" class="button button-secondary button-small">失敗分を全てリトライ</button>
                        </form>
                        <form method="post" style="display: inline;">
                            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                            <input type="hidden" name="gcrev_action" value="resume_processing" />
                            <input type="hidden" name="job_id" value="<?php echo esc_attr( (string) $job_id ); ?>" />
                            <button type="submit" class="button button-primary button-small">チャンク処理を再開</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <?php else : ?>
            <div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 16px 20px; min-width: 200px;">
                <strong>進捗:</strong> キューなし
            </div>
            <?php endif; ?>

            <!-- 手動実行 -->
            <div style="background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 8px; padding: 16px 20px; min-width: 200px;">
                <strong>手動操作</strong>
                <form method="post" style="margin-top: 8px;" onsubmit="return confirm('レポート生成キューを開始しますか？\n毎月1日以外でも実行できます（前月分レポートを生成）。');">
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                    <input type="hidden" name="gcrev_action" value="trigger_generate" />
                    <button type="submit" class="button button-primary button-small">今すぐキュー登録＆実行</button>
                </form>
            </div>
        </div>
        <?php
    }

    // =========================================================
    // B. キューアイテムテーブル
    // =========================================================

    private function render_queue_table(): void {
        if ( ! class_exists( 'Gcrev_Report_Queue' ) ) {
            return;
        }

        // 表示対象ジョブIDの取得
        $view_job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;
        $filter      = isset( $_GET['status_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['status_filter'] ) ) : '';

        if ( ! $view_job_id ) {
            // 直近のジョブを探す
            $tz         = wp_timezone();
            $now        = new DateTimeImmutable( 'now', $tz );
            $prev_month = $now->modify( 'first day of last month' )->format( 'Y-m' );
            $view_job_id = Gcrev_Report_Queue::get_latest_job_id( $prev_month );
            if ( ! $view_job_id ) {
                $view_job_id = Gcrev_Report_Queue::get_latest_job_id( $now->format( 'Y-m' ) );
            }
        }

        if ( ! $view_job_id ) {
            echo '<h2>キューアイテム</h2><p>表示するキューデータがありません。</p>';
            return;
        }

        $status_arg = ( $filter && $filter !== 'all' ) ? $filter : null;
        $items      = Gcrev_Report_Queue::get_by_job( $view_job_id, $status_arg );

        ?>
        <h2>キューアイテム (Job #<?php echo esc_html( (string) $view_job_id ); ?>)</h2>

        <!-- フィルタ -->
        <div style="margin-bottom: 10px;">
            <?php
            $base_url = add_query_arg( [ 'page' => self::MENU_SLUG, 'job_id' => $view_job_id ], admin_url( 'admin.php' ) );
            $filters  = [ 'all' => '全て' ] + self::STATUS_LABELS;
            foreach ( $filters as $key => $label ) :
                $is_active = ( $filter === $key ) || ( $key === 'all' && empty( $filter ) );
                $url       = $key === 'all' ? $base_url : add_query_arg( 'status_filter', $key, $base_url );
            ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   style="display: inline-block; padding: 4px 12px; margin: 2px; border-radius: 4px; text-decoration: none; <?php echo $is_active ? 'background: #2271b1; color: #fff;' : 'background: #f0f0f1; color: #50575e;'; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ( empty( $items ) ) : ?>
            <p>該当するアイテムはありません。</p>
        <?php else : ?>
            <table class="widefat striped" style="max-width: 1200px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ユーザー</th>
                        <th>年月</th>
                        <th>ステータス</th>
                        <th>試行</th>
                        <th>エラー</th>
                        <th>開始</th>
                        <th>完了</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $items as $item ) :
                    $user      = get_userdata( (int) $item->user_id );
                    $user_name = $user ? $user->display_name : "ID:{$item->user_id}";
                    $color     = self::STATUS_COLORS[ $item->status ] ?? '#666';
                ?>
                    <tr>
                        <td><?php echo esc_html( (string) $item->id ); ?></td>
                        <td>
                            <?php echo esc_html( $user_name ); ?>
                            <br><small style="color: #999;">ID: <?php echo esc_html( (string) $item->user_id ); ?></small>
                        </td>
                        <td><?php echo esc_html( $item->year_month ); ?></td>
                        <td>
                            <span style="color: <?php echo esc_attr( $color ); ?>; font-weight: bold;">
                                <?php echo esc_html( self::STATUS_LABELS[ $item->status ] ?? $item->status ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $item->attempts . '/' . $item->max_attempts ); ?></td>
                        <td style="max-width: 300px; word-break: break-all;">
                            <?php if ( $item->error_message ) : ?>
                                <small style="color: #d63638;"><?php echo esc_html( mb_substr( $item->error_message, 0, 200 ) ); ?></small>
                            <?php else : ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo esc_html( $item->started_at ?: '—' ); ?></small></td>
                        <td><small><?php echo esc_html( $item->finished_at ?: '—' ); ?></small></td>
                        <td>
                            <?php if ( $item->status === 'failed' && (int) $item->attempts < (int) $item->max_attempts ) : ?>
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                                    <input type="hidden" name="gcrev_action" value="retry_single" />
                                    <input type="hidden" name="queue_id" value="<?php echo esc_attr( (string) $item->id ); ?>" />
                                    <button type="submit" class="button button-small">リトライ</button>
                                </form>
                            <?php else : ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    // =========================================================
    // C. ジョブ履歴
    // =========================================================

    private function render_job_history(): void {
        if ( ! class_exists( 'Gcrev_Report_Queue' ) ) {
            return;
        }

        $jobs = Gcrev_Report_Queue::get_job_summary_list( 20 );
        ?>
        <h2>ジョブ履歴</h2>
        <?php if ( empty( $jobs ) ) : ?>
            <p>ジョブ履歴はまだありません。</p>
        <?php else : ?>
            <table class="widefat striped" style="max-width: 900px;">
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>対象年月</th>
                        <th>登録日時</th>
                        <th>合計</th>
                        <th>完了</th>
                        <th>失敗</th>
                        <th>スキップ</th>
                        <th>残り</th>
                        <th>詳細</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $jobs as $job ) :
                    $remaining = (int) $job->cnt_pending + (int) $job->cnt_processing;
                ?>
                    <tr>
                        <td><?php echo esc_html( (string) $job->job_id ); ?></td>
                        <td><?php echo esc_html( $job->year_month ); ?></td>
                        <td><small><?php echo esc_html( $job->created_at ); ?></small></td>
                        <td><?php echo esc_html( (string) $job->total ); ?></td>
                        <td style="color: #00a32a;"><?php echo esc_html( (string) $job->cnt_success ); ?></td>
                        <td style="color: <?php echo (int) $job->cnt_failed > 0 ? '#d63638' : '#999'; ?>;">
                            <?php echo esc_html( (string) $job->cnt_failed ); ?>
                        </td>
                        <td style="color: #dba617;"><?php echo esc_html( (string) $job->cnt_skipped ); ?></td>
                        <td>
                            <?php if ( $remaining > 0 ) : ?>
                                <span style="color: #2271b1; font-weight: bold;"><?php echo esc_html( (string) $remaining ); ?></span>
                            <?php else : ?>
                                <span style="color: #999;">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'job_id' => $job->job_id ], admin_url( 'admin.php' ) ) ); ?>" class="button button-small">
                                表示
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }
}
