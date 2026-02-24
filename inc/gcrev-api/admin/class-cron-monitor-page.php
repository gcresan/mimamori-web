<?php
// FILE: inc/gcrev-api/admin/class-cron-monitor-page.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Cron_Monitor_Page' ) ) { return; }

/**
 * Gcrev_Cron_Monitor_Page
 *
 * 管理画面「GCREV INSIGHT > モニター」ページ。
 * 3セクション構成:
 *   A. Cron ステータスカード（次回スケジュール・ロック・前回結果）
 *   B. 実行履歴テーブル（直近50件）
 *   C. テナント一覧（全テナントの設定・データ状況）
 *
 * @package GCREV_INSIGHT
 * @since   3.0.0
 */
class Gcrev_Cron_Monitor_Page {

    private const MENU_SLUG = 'gcrev-cron-monitor';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
    }

    // =========================================================
    // メニュー登録
    // =========================================================

    public function add_menu_page(): void {
        if ( empty( $GLOBALS['admin_page_hooks']['gcrev-insight'] ) ) {
            add_menu_page(
                'GCREV INSIGHT',
                'GCREV INSIGHT',
                'manage_options',
                'gcrev-insight',
                '__return_null',
                'dashicons-chart-area',
                30
            );
        }

        add_submenu_page(
            'gcrev-insight',
            'モニター - GCREV INSIGHT',
            "\xF0\x9F\x93\x8A モニター",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
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
            <h1>GCREV INSIGHT — モニター</h1>

            <?php $this->render_cron_status_cards(); ?>

            <hr />

            <?php $this->render_execution_history(); ?>

            <hr />

            <?php $this->render_tenant_overview(); ?>

            <?php $this->render_index_status(); ?>
        </div>
        <?php
    }

    // =========================================================
    // A. Cron ステータスカード
    // =========================================================

    private function render_cron_status_cards(): void {
        $jobs = [
            [ 'name' => 'prefetch',         'label' => 'プリフェッチ',     'hooks' => $this->get_prefetch_hooks() ],
            [ 'name' => 'report_generate',   'label' => 'レポート生成',     'hooks' => ['gcrev_monthly_report_generate_event'] ],
            [ 'name' => 'report_finalize',   'label' => 'レポート確定',     'hooks' => ['gcrev_monthly_report_finalize_event'] ],
        ];

        $latest_logs = class_exists( 'Gcrev_Cron_Logger' ) ? Gcrev_Cron_Logger::get_latest_per_job() : [];

        echo '<h2>Cron ステータス</h2>';
        echo '<div style="display:flex; gap:16px; flex-wrap:wrap;">';

        foreach ( $jobs as $job ) {
            $this->render_status_card( $job, $latest_logs );
        }

        echo '</div>';
    }

    /**
     * @param array $job
     * @param array $latest_logs
     */
    private function render_status_card( array $job, array $latest_logs ): void {
        // 次回スケジュール
        $next_scheduled = null;
        foreach ( $job['hooks'] as $hook ) {
            $ts = wp_next_scheduled( $hook );
            if ( $ts && ( $next_scheduled === null || $ts < $next_scheduled ) ) {
                $next_scheduled = $ts;
            }
        }

        // ロック状態
        $lock_keys = [
            'prefetch'        => 'gcrev_lock_prefetch',
            'report_generate' => 'gcrev_lock_report_gen',
            'report_finalize' => 'gcrev_lock_report_finalize',
        ];
        $is_locked = false;
        if ( isset( $lock_keys[ $job['name'] ] ) ) {
            $is_locked = (bool) get_transient( $lock_keys[ $job['name'] ] );
        }
        // スロット別ロックもチェック
        if ( $job['name'] === 'prefetch' && class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            $slot_count = Gcrev_Prefetch_Scheduler::get_slot_count();
            for ( $i = 0; $i < $slot_count; $i++ ) {
                if ( get_transient( "gcrev_lock_prefetch_slot_{$i}" ) ) {
                    $is_locked = true;
                    break;
                }
            }
        }

        // 最新ログ（ジョブ名のプレフィックスで検索）
        $last_log    = null;
        $log_status  = null;
        foreach ( $latest_logs as $log_name => $log ) {
            if ( strpos( $log_name, $job['name'] ) === 0 || $log_name === $job['name'] ) {
                if ( $last_log === null || $log->id > $last_log->id ) {
                    $last_log   = $log;
                    $log_status = $log->status;
                }
            }
        }

        // カード色
        $colors = [
            'success' => '#d4edda',
            'partial' => '#fff3cd',
            'error'   => '#f8d7da',
            'running' => '#cce5ff',
            'locked'  => '#e2e3e5',
        ];
        $bg = $colors[ $log_status ] ?? '#f8f9fa';

        $tz = wp_timezone();

        echo '<div style="background:' . esc_attr( $bg ) . '; border:1px solid #ccc; border-radius:8px; padding:16px; min-width:280px; flex:1;">';
        echo '<h3 style="margin-top:0;">' . esc_html( $job['label'] ) . '</h3>';

        // 次回
        echo '<p><strong>次回:</strong> ';
        if ( $next_scheduled ) {
            $dt = ( new \DateTimeImmutable( '@' . $next_scheduled ) )->setTimezone( $tz );
            echo esc_html( $dt->format( 'Y-m-d H:i' ) );
        } else {
            echo '<span style="color:#999;">未スケジュール</span>';
        }
        echo '</p>';

        // ロック
        echo '<p><strong>ロック:</strong> ';
        echo $is_locked ? '<span style="color:#dc3545;">実行中</span>' : '<span style="color:#28a745;">なし</span>';
        echo '</p>';

        // 前回結果
        echo '<p><strong>前回:</strong> ';
        if ( $last_log ) {
            $status_labels = [
                'success' => '成功',
                'partial' => '一部エラー',
                'error'   => 'エラー',
                'running' => '実行中',
                'locked'  => 'スキップ(ロック)',
            ];
            echo esc_html( $status_labels[ $log_status ] ?? $log_status );
            echo ' (' . esc_html( $last_log->started_at ) . ')';
            if ( $last_log->users_total > 0 ) {
                echo '<br />';
                echo esc_html( "成功:{$last_log->users_success} / スキップ:{$last_log->users_skipped} / エラー:{$last_log->users_error}" );
            }
        } else {
            echo '<span style="color:#999;">記録なし</span>';
        }
        echo '</p>';

        echo '</div>';
    }

    /**
     * プリフェッチ関連のフック名一覧を返す。
     */
    private function get_prefetch_hooks(): array {
        $hooks = [ 'gcrev_prefetch_daily_event' ];
        if ( class_exists( 'Gcrev_Prefetch_Scheduler' ) ) {
            $slot_count = Gcrev_Prefetch_Scheduler::get_slot_count();
            for ( $i = 0; $i < $slot_count; $i++ ) {
                $hooks[] = "gcrev_prefetch_daily_event_slot_{$i}";
            }
        }
        return $hooks;
    }

    // =========================================================
    // B. 実行履歴テーブル
    // =========================================================

    private function render_execution_history(): void {
        echo '<h2>実行履歴（直近50件）</h2>';

        if ( ! class_exists( 'Gcrev_Cron_Logger' ) ) {
            echo '<p>Cron Logger が読み込まれていません。</p>';
            return;
        }

        $logs = Gcrev_Cron_Logger::get_recent( 50 );

        if ( empty( $logs ) ) {
            echo '<p>まだ実行履歴がありません。</p>';
            return;
        }

        $status_badges = [
            'success' => '<span style="background:#28a745;color:#fff;padding:2px 8px;border-radius:4px;">成功</span>',
            'partial' => '<span style="background:#ffc107;color:#000;padding:2px 8px;border-radius:4px;">一部エラー</span>',
            'error'   => '<span style="background:#dc3545;color:#fff;padding:2px 8px;border-radius:4px;">エラー</span>',
            'running' => '<span style="background:#007bff;color:#fff;padding:2px 8px;border-radius:4px;">実行中</span>',
            'locked'  => '<span style="background:#6c757d;color:#fff;padding:2px 8px;border-radius:4px;">スキップ</span>',
        ];

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>ジョブ名</th><th>開始</th><th>終了</th><th>ステータス</th><th>成功</th><th>スキップ</th><th>エラー</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $logs as $log ) {
            echo '<tr>';
            echo '<td>' . esc_html( $log->job_name ) . '</td>';
            echo '<td>' . esc_html( $log->started_at ) . '</td>';
            echo '<td>' . esc_html( $log->finished_at ?: '-' ) . '</td>';
            echo '<td>' . ( $status_badges[ $log->status ] ?? esc_html( $log->status ) ) . '</td>';
            echo '<td>' . esc_html( (string) $log->users_success ) . '</td>';
            echo '<td>' . esc_html( (string) $log->users_skipped ) . '</td>';
            echo '<td>' . esc_html( (string) $log->users_error ) . '</td>';
            echo '</tr>';

            // エラーがある場合は詳細行を追加
            if ( (int) $log->users_error > 0 ) {
                $details = Gcrev_Cron_Logger::get_user_logs( (int) $log->id );
                $error_details = array_filter( $details, static function ( $d ) {
                    return $d->status === 'error';
                } );
                if ( ! empty( $error_details ) ) {
                    echo '<tr><td colspan="7" style="background:#fff5f5; padding-left:40px;">';
                    echo '<small>';
                    foreach ( $error_details as $d ) {
                        echo 'User ' . esc_html( (string) $d->user_id ) . ': ' . esc_html( $d->detail ?: '(詳細なし)' ) . '<br />';
                    }
                    echo '</small>';
                    echo '</td></tr>';
                }
            }
        }

        echo '</tbody></table>';
    }

    // =========================================================
    // C. テナント一覧
    // =========================================================

    private function render_tenant_overview(): void {
        echo '<h2>テナント一覧</h2>';

        $tenants = $this->get_tenant_data();

        if ( empty( $tenants ) ) {
            echo '<p>テナントが見つかりません。</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>ユーザー</th><th>メール</th><th>GA4</th><th>GSC</th><th>決済</th><th>最終プリフェッチ</th><th>当月レポート</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $tenants as $t ) {
            $user = $t['user'];
            echo '<tr>';
            echo '<td>' . esc_html( $user->display_name ) . ' (ID:' . esc_html( (string) $user->ID ) . ')</td>';
            echo '<td>' . esc_html( $user->user_email ) . '</td>';
            echo '<td>' . ( $t['ga4_configured'] ? '<span style="color:green;">&#10003;</span>' : '<span style="color:#ccc;">&mdash;</span>' ) . '</td>';
            echo '<td>' . ( $t['gsc_configured'] ? '<span style="color:green;">&#10003;</span>' : '<span style="color:#ccc;">&mdash;</span>' ) . '</td>';
            echo '<td>' . ( $t['is_paid'] ? '<span style="color:green;">有効</span>' : '<span style="color:#999;">無効</span>' ) . '</td>';

            // 最終プリフェッチ
            echo '<td>';
            if ( $t['last_prefetch'] ) {
                echo esc_html( $t['last_prefetch'] );
            } else {
                echo '<span style="color:#999;">-</span>';
            }
            echo '</td>';

            // 当月レポート
            echo '<td>';
            $state_labels = [
                'draft' => '<span style="color:#ffc107;">下書き</span>',
                'final' => '<span style="color:#28a745;">確定</span>',
            ];
            if ( $t['report_state'] ) {
                echo $state_labels[ $t['report_state'] ] ?? esc_html( $t['report_state'] );
            } else {
                echo '<span style="color:#999;">未生成</span>';
            }
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description">テナント数: ' . count( $tenants ) . '</p>';
    }

    /**
     * テナントデータを収集する。
     *
     * @return array
     */
    private function get_tenant_data(): array {
        global $wpdb;

        $users = get_users( [
            'role__not_in' => [ 'administrator' ],
            'orderby'      => 'registered',
            'order'        => 'DESC',
        ] );

        $tz            = wp_timezone();
        $current_month = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m' );
        $result        = [];

        foreach ( $users as $user ) {
            $uid = (int) $user->ID;

            // GA4/GSC 設定
            $ga4_id  = get_user_meta( $uid, 'ga4_property_id', true );
            $gsc_url = get_user_meta( $uid, 'weisite_url', true );

            // 決済
            $is_paid = function_exists( 'gcrev_is_payment_active' ) ? gcrev_is_payment_active( $uid ) : false;

            // 最終プリフェッチ
            $last_prefetch = null;
            if ( class_exists( 'Gcrev_Cron_Logger' ) ) {
                $pf_log = Gcrev_Cron_Logger::get_latest_for_user( $uid, 'prefetch%' );
                if ( $pf_log ) {
                    $last_prefetch = $pf_log->created_at;
                }
            }

            // 当月レポート
            $report_state = $wpdb->get_var( $wpdb->prepare(
                "SELECT pm3.meta_value
                 FROM {$wpdb->postmeta} pm1
                 INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
                 INNER JOIN {$wpdb->postmeta} pm3 ON pm1.post_id = pm3.post_id
                 INNER JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
                 WHERE p.post_type = 'gcrev_report'
                 AND pm1.meta_key = '_gcrev_user_id' AND pm1.meta_value = %s
                 AND pm2.meta_key = '_gcrev_year_month' AND pm2.meta_value = %s
                 AND pm3.meta_key = '_gcrev_report_state'
                 ORDER BY p.ID DESC LIMIT 1",
                (string) $uid,
                $current_month
            ) );

            $result[] = [
                'user'           => $user,
                'ga4_configured' => ! empty( $ga4_id ),
                'gsc_configured' => ! empty( $gsc_url ),
                'is_paid'        => (bool) $is_paid,
                'last_prefetch'  => $last_prefetch,
                'report_state'   => $report_state ?: null,
            ];
        }

        return $result;
    }

    // =========================================================
    // D. インデックス状態
    // =========================================================

    private function render_index_status(): void {
        if ( ! class_exists( 'Gcrev_DB_Optimizer' ) ) {
            return;
        }

        echo '<hr />';
        echo '<h2>DB インデックス</h2>';

        $indexes = Gcrev_DB_Optimizer::get_index_status();

        if ( empty( $indexes ) ) {
            echo '<p>チェック対象のインデックスがありません。</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>テーブル</th><th>インデックス名</th><th>カラム</th><th>状態</th></tr></thead>';
        echo '<tbody>';

        foreach ( $indexes as $idx ) {
            echo '<tr>';
            echo '<td>' . esc_html( $idx['table'] ) . '</td>';
            echo '<td>' . esc_html( $idx['index'] ) . '</td>';
            echo '<td>' . esc_html( $idx['columns'] ) . '</td>';
            echo '<td>' . ( $idx['exists'] ? '<span style="color:green;">&#10003; 適用済</span>' : '<span style="color:red;">&#10007; 未適用</span>' ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
