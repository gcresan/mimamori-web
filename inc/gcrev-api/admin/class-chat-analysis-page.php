<?php
// FILE: inc/gcrev-api/admin/class-chat-analysis-page.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Chat_Analysis_Page' ) ) { return; }

/**
 * Gcrev_Chat_Analysis_Page
 *
 * 管理画面「みまもりウェブ > 会話ログ・ニーズ分析」ページ。
 *
 * タブ構成:
 *   A. ログ一覧（フィルタ + CSV エクスポート）
 *   B. 分析レポート（Gemini で生成したニーズ分析の一覧 + 手動生成ボタン）
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Gcrev_Chat_Analysis_Page {

    private const MENU_SLUG  = 'gcrev-chat-analysis';
    private const NONCE_KEY  = 'gcrev_chat_analysis_action';
    private const PAGE_SIZE  = 50;

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_post_gcrev_chat_generate_report', [ $this, 'handle_generate_report' ] );
        add_action( 'admin_post_gcrev_chat_delete_report',   [ $this, 'handle_delete_report' ] );
        add_action( 'admin_post_gcrev_chat_export_csv',      [ $this, 'handle_export_csv' ] );
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
            '会話ログ・ニーズ分析 - みまもりウェブ',
            "\xF0\x9F\x92\xAC 会話ログ・ニーズ分析",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================
    // Main render
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'logs';
        if ( ! in_array( $tab, [ 'logs', 'reports', 'report_detail' ], true ) ) {
            $tab = 'logs';
        }

        $this->render_inline_styles();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html( "\xF0\x9F\x92\xAC 会話ログ・ニーズ分析" ) . '</h1>';

        $this->render_flash_notice();
        $this->render_tabs( $tab );

        switch ( $tab ) {
            case 'reports':
                $this->render_reports_tab();
                break;
            case 'report_detail':
                $this->render_report_detail_tab();
                break;
            case 'logs':
            default:
                $this->render_logs_tab();
                break;
        }

        echo '</div>';
    }

    private function render_tabs( string $active ): void {
        $tabs = [
            'logs'    => 'ログ一覧',
            'reports' => '分析レポート',
        ];
        echo '<h2 class="nav-tab-wrapper" style="margin-bottom:20px;">';
        foreach ( $tabs as $key => $label ) {
            $class = 'nav-tab' . ( $active === $key ? ' nav-tab-active' : '' );
            $url   = add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => $key ], admin_url( 'admin.php' ) );
            printf( '<a href="%s" class="%s">%s</a>', esc_url( $url ), esc_attr( $class ), esc_html( $label ) );
        }
        echo '</h2>';
    }

    private function render_flash_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $msg = isset( $_GET['gcrev_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['gcrev_msg'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $type = isset( $_GET['gcrev_type'] ) ? sanitize_text_field( wp_unslash( $_GET['gcrev_type'] ) ) : 'success';
        if ( $msg === '' ) { return; }
        $class = $type === 'error' ? 'notice-error' : 'notice-success';
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr( $class ),
            esc_html( $msg )
        );
    }

    // =========================================================
    // Tab: ログ一覧
    // =========================================================

    private function render_logs_tab(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $filter_user    = isset( $_GET['flt_user'] ) ? (int) $_GET['flt_user'] : 0;
        $filter_intent  = isset( $_GET['flt_intent'] ) ? sanitize_text_field( wp_unslash( $_GET['flt_intent'] ) ) : '';
        $filter_from    = isset( $_GET['flt_from'] ) ? sanitize_text_field( wp_unslash( $_GET['flt_from'] ) ) : '';
        $filter_to      = isset( $_GET['flt_to'] ) ? sanitize_text_field( wp_unslash( $_GET['flt_to'] ) ) : '';
        $filter_keyword = isset( $_GET['flt_q'] ) ? sanitize_text_field( wp_unslash( $_GET['flt_q'] ) ) : '';
        $page_num       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        // phpcs:enable

        [ $rows, $total, $intents ] = $this->query_logs( $filter_user, $filter_intent, $filter_from, $filter_to, $filter_keyword, $page_num );

        ?>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="gcrev-filter-bar">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
            <input type="hidden" name="tab" value="logs">

            <label>ユーザーID
                <input type="number" name="flt_user" value="<?php echo $filter_user > 0 ? esc_attr( (string) $filter_user ) : ''; ?>" placeholder="全員" style="width:90px;">
            </label>

            <label>Intent
                <select name="flt_intent">
                    <option value="">全て</option>
                    <?php foreach ( $intents as $intent ) : ?>
                        <option value="<?php echo esc_attr( $intent ); ?>" <?php selected( $filter_intent, $intent ); ?>><?php echo esc_html( $intent ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>期間
                <input type="date" name="flt_from" value="<?php echo esc_attr( $filter_from ); ?>"> 〜
                <input type="date" name="flt_to"   value="<?php echo esc_attr( $filter_to ); ?>">
            </label>

            <label>キーワード
                <input type="search" name="flt_q" value="<?php echo esc_attr( $filter_keyword ); ?>" placeholder="質問・応答を検索" style="width:200px;">
            </label>

            <button type="submit" class="button button-primary">絞り込み</button>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'logs' ], admin_url( 'admin.php' ) ) ); ?>" class="button">クリア</a>
        </form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
            <?php wp_nonce_field( self::NONCE_KEY ); ?>
            <input type="hidden" name="action" value="gcrev_chat_export_csv">
            <input type="hidden" name="flt_user"   value="<?php echo esc_attr( (string) $filter_user ); ?>">
            <input type="hidden" name="flt_intent" value="<?php echo esc_attr( $filter_intent ); ?>">
            <input type="hidden" name="flt_from"   value="<?php echo esc_attr( $filter_from ); ?>">
            <input type="hidden" name="flt_to"     value="<?php echo esc_attr( $filter_to ); ?>">
            <input type="hidden" name="flt_q"      value="<?php echo esc_attr( $filter_keyword ); ?>">
            <button type="submit" class="button"><?php echo esc_html( "\xE2\xAC\x87" ); ?> 現在の絞り込みで CSV エクスポート</button>
        </form>

        <p style="margin:8px 0 12px; color:#555;">合計 <strong><?php echo (int) $total; ?></strong> 件</p>

        <table class="widefat striped gcrev-logs-table">
            <thead>
                <tr>
                    <th style="width:140px;">日時</th>
                    <th style="width:60px;">User</th>
                    <th style="width:130px;">Intent</th>
                    <th style="width:110px;">Page</th>
                    <th>質問</th>
                    <th style="width:60px;">詳細</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6">該当するログがありません。</td></tr>
                <?php else : foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['created_at'] ); ?></td>
                        <td><?php echo (int) $row['user_id']; ?></td>
                        <td><?php echo esc_html( $row['intent'] ?: '—' ); ?></td>
                        <td><?php echo esc_html( $row['page_type'] ?: '—' ); ?></td>
                        <td><?php echo esc_html( $this->truncate( (string) $row['message'], 100 ) ); ?></td>
                        <td>
                            <button type="button" class="button-link gcrev-log-detail"
                                    data-log='<?php echo esc_attr( wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) ); ?>'>表示</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php $this->render_pagination( $total, $page_num ); ?>

        <?php $this->render_log_detail_modal(); ?>
        <?php $this->render_logs_js(); ?>
        <?php
    }

    private function render_pagination( int $total, int $current ): void {
        $total_pages = (int) ceil( $total / self::PAGE_SIZE );
        if ( $total_pages <= 1 ) { return; }

        $base = add_query_arg( array_merge( $_GET, [ 'paged' => '%#%' ] ), admin_url( 'admin.php' ) );
        $html = paginate_links( [
            'base'      => $base,
            'format'    => '',
            'current'   => $current,
            'total'     => $total_pages,
            'prev_text' => '«',
            'next_text' => '»',
        ] );
        if ( $html ) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . $html . '</div></div>';
        }
    }

    private function render_log_detail_modal(): void {
        ?>
        <div id="gcrev-log-modal" style="display:none;">
            <div class="gcrev-modal-backdrop"></div>
            <div class="gcrev-modal-body">
                <button type="button" class="gcrev-modal-close">×</button>
                <h2>ログ詳細</h2>
                <div id="gcrev-log-modal-content"></div>
            </div>
        </div>
        <?php
    }

    private function render_logs_js(): void {
        ?>
        <script>
        (function(){
          var modal = document.getElementById('gcrev-log-modal');
          var content = document.getElementById('gcrev-log-modal-content');
          if (!modal || !content) return;

          function render(log){
            var parts = [];
            var rows = [
              ['日時', log.created_at],
              ['ユーザーID', log.user_id],
              ['Intent', log.intent || '—'],
              ['Page Type', log.page_type || '—'],
              ['Page URL', log.page_url || '—'],
              ['Quick Prompt', log.is_quick_prompt == 1 ? 'yes' : ''],
              ['Followup', log.is_followup == 1 ? 'yes' : ''],
              ['Param Gate', log.param_gate || ''],
              ['Model', log.model || ''],
              ['Error', log.error_message || ''],
            ];
            parts.push('<table class="widefat striped"><tbody>');
            rows.forEach(function(r){
              if (r[1] !== '' && r[1] !== null && r[1] !== undefined) {
                parts.push('<tr><th style="width:120px;">' + r[0] + '</th><td>' + escapeHtml(String(r[1])) + '</td></tr>');
              }
            });
            parts.push('</tbody></table>');
            parts.push('<h3>質問</h3><pre style="white-space:pre-wrap;background:#f6f7f7;padding:10px;border-radius:4px;">' + escapeHtml(log.message || '') + '</pre>');
            if (log.response) {
              parts.push('<h3>AI 応答（raw）</h3><pre style="white-space:pre-wrap;background:#f6f7f7;padding:10px;border-radius:4px;max-height:400px;overflow:auto;">' + escapeHtml(log.response) + '</pre>');
            }
            content.innerHTML = parts.join('');
          }

          function escapeHtml(s){
            return String(s).replace(/[&<>"']/g, function(c){
              return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
          }

          document.querySelectorAll('.gcrev-log-detail').forEach(function(btn){
            btn.addEventListener('click', function(){
              try {
                var data = JSON.parse(btn.getAttribute('data-log'));
                render(data);
                modal.style.display = 'block';
              } catch(e) { console.error(e); }
            });
          });

          modal.addEventListener('click', function(e){
            if (e.target === modal || e.target.classList.contains('gcrev-modal-backdrop') || e.target.classList.contains('gcrev-modal-close')) {
              modal.style.display = 'none';
            }
          });
        })();
        </script>
        <?php
    }

    // =========================================================
    // Tab: 分析レポート
    // =========================================================

    private function render_reports_tab(): void {
        $reports = get_posts( [
            'post_type'      => 'mimamori_chat_report',
            'post_status'    => 'publish',
            'posts_per_page' => 30,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        ?>
        <div class="gcrev-card">
            <h2>分析レポートを生成</h2>
            <p style="color:#555;">直近の会話ログを Gemini で分析し、ユーザーのニーズ・改善案を JSON レポートとして保存します。</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::NONCE_KEY ); ?>
                <input type="hidden" name="action" value="gcrev_chat_generate_report">
                <label>対象期間：
                    <select name="days">
                        <option value="7">直近 7日</option>
                        <option value="30" selected>直近 30日</option>
                        <option value="60">直近 60日</option>
                        <option value="90">直近 90日</option>
                    </select>
                </label>
                &nbsp;
                <label>対象ユーザー：
                    <input type="number" name="user_id" value="0" style="width:90px;"> (0=全員)
                </label>
                &nbsp;
                <button type="submit" class="button button-primary"><?php echo esc_html( "\xF0\x9F\xA4\x96" ); ?> レポート生成</button>
            </form>
        </div>

        <h2 style="margin-top:30px;">生成済みレポート</h2>
        <?php if ( empty( $reports ) ) : ?>
            <p>まだレポートが生成されていません。</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:160px;">生成日時</th>
                        <th style="width:80px;">期間</th>
                        <th style="width:100px;">対象</th>
                        <th style="width:80px;">件数</th>
                        <th>サマリー</th>
                        <th style="width:160px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $reports as $report ) :
                        $days  = (int) get_post_meta( $report->ID, '_chat_report_days', true );
                        $uid   = (int) get_post_meta( $report->ID, '_chat_report_user_id', true );
                        $count = (int) get_post_meta( $report->ID, '_chat_report_log_count', true );
                        $summary = (string) get_post_meta( $report->ID, '_chat_report_summary', true );
                        $detail_url = add_query_arg( [
                            'page'   => self::MENU_SLUG,
                            'tab'    => 'report_detail',
                            'report' => $report->ID,
                        ], admin_url( 'admin.php' ) );
                        ?>
                        <tr>
                            <td><?php echo esc_html( get_the_date( 'Y-m-d H:i', $report ) ); ?></td>
                            <td><?php echo (int) $days; ?>日</td>
                            <td><?php echo $uid > 0 ? 'user=' . (int) $uid : '全体'; ?></td>
                            <td><?php echo (int) $count; ?></td>
                            <td><?php echo esc_html( $this->truncate( $summary, 120 ) ); ?></td>
                            <td>
                                <a class="button button-primary" href="<?php echo esc_url( $detail_url ); ?>">詳細</a>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( self::NONCE_KEY ); ?>
                                    <input type="hidden" name="action" value="gcrev_chat_delete_report">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $report->ID; ?>">
                                    <button type="submit" class="button" onclick="return confirm('削除しますか？');">削除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }

    private function render_report_detail_tab(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $report_id = isset( $_GET['report'] ) ? (int) $_GET['report'] : 0;
        $report = $report_id > 0 ? get_post( $report_id ) : null;
        if ( ! $report || $report->post_type !== 'mimamori_chat_report' ) {
            echo '<p>レポートが見つかりません。</p>';
            return;
        }

        $analysis = json_decode( (string) $report->post_content, true );
        if ( ! is_array( $analysis ) ) { $analysis = []; }

        $back = add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'reports' ], admin_url( 'admin.php' ) );
        $days  = (int) get_post_meta( $report_id, '_chat_report_days', true );
        $uid   = (int) get_post_meta( $report_id, '_chat_report_user_id', true );
        $count = (int) get_post_meta( $report_id, '_chat_report_log_count', true );

        ?>
        <p><a href="<?php echo esc_url( $back ); ?>" class="button">← 一覧に戻る</a></p>
        <h2><?php echo esc_html( $report->post_title ); ?></h2>
        <p style="color:#555;">対象期間: 直近 <?php echo (int) $days; ?> 日 ／ 対象: <?php echo $uid > 0 ? 'user=' . (int) $uid : '全ユーザー'; ?> ／ ログ件数: <?php echo (int) $count; ?> 件</p>

        <?php if ( ! empty( $analysis['summary'] ) ) : ?>
            <div class="gcrev-card">
                <h3><?php echo esc_html( "\xF0\x9F\x93\x8C" ); ?> 総括</h3>
                <p style="font-size:15px;line-height:1.7;"><?php echo esc_html( (string) $analysis['summary'] ); ?></p>
            </div>
        <?php endif; ?>

        <?php $this->render_analysis_section( 'top_needs', "\xF0\x9F\x94\x8D ユーザーが知りたがっていること (top needs)", $analysis, [
            'theme'              => 'テーマ',
            'count'              => '件数',
            'description'        => '説明',
            'example_questions'  => '代表質問',
        ] ); ?>

        <?php $this->render_analysis_section( 'unresolved_issues', "\xE2\x9A\xA0\xEF\xB8\x8F 未解決・困りごと", $analysis, [
            'issue'              => '課題',
            'count'              => '件数',
            'description'        => '説明',
            'example_questions'  => '代表質問',
        ] ); ?>

        <?php $this->render_analysis_section( 'page_hotspots', "\xF0\x9F\x93\x8D ページ別ホットスポット", $analysis, [
            'page_type'   => 'ページ',
            'count'       => '件数',
            'observation' => '観察',
        ] ); ?>

        <?php $this->render_analysis_section( 'improvement_suggestions', "\xF0\x9F\x92\xA1 改善提案", $analysis, [
            'priority'   => '優先度',
            'target'     => '対象',
            'suggestion' => '提案',
            'rationale'  => '根拠',
        ] ); ?>

        <?php if ( ! empty( $analysis['quality_flags'] ) && is_array( $analysis['quality_flags'] ) ) : ?>
            <div class="gcrev-card">
                <h3><?php echo esc_html( "\xF0\x9F\x9A\xA9" ); ?> 品質フラグ</h3>
                <ul>
                    <?php foreach ( $analysis['quality_flags'] as $flag ) : ?>
                        <li><?php echo esc_html( (string) $flag ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <details style="margin-top:30px;">
            <summary>生データ (JSON)</summary>
            <pre style="background:#f6f7f7;padding:10px;border-radius:4px;max-height:500px;overflow:auto;font-size:12px;"><?php echo esc_html( (string) $report->post_content ); ?></pre>
        </details>
        <?php
    }

    private function render_analysis_section( string $key, string $title, array $analysis, array $columns ): void {
        $items = $analysis[ $key ] ?? null;
        if ( ! is_array( $items ) || empty( $items ) ) { return; }

        echo '<div class="gcrev-card">';
        echo '<h3>' . esc_html( $title ) . '</h3>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ( $columns as $label ) {
            echo '<th>' . esc_html( $label ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            echo '<tr>';
            foreach ( array_keys( $columns ) as $col_key ) {
                $val = $item[ $col_key ] ?? '';
                echo '<td>';
                if ( is_array( $val ) ) {
                    echo '<ul style="margin:0 0 0 16px;">';
                    foreach ( $val as $v ) {
                        echo '<li>' . esc_html( (string) $v ) . '</li>';
                    }
                    echo '</ul>';
                } else {
                    echo esc_html( (string) $val );
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    // =========================================================
    // Action handlers
    // =========================================================

    public function handle_generate_report(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '権限がありません。' ); }
        check_admin_referer( self::NONCE_KEY );

        $days    = isset( $_POST['days'] ) ? (int) $_POST['days'] : 30;
        $user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

        if ( ! class_exists( 'Gcrev_Chat_Analysis_Service' ) ) {
            require_once dirname( __DIR__ ) . '/modules/class-chat-analysis-service.php';
        }

        @set_time_limit( 180 );
        $service = new Gcrev_Chat_Analysis_Service();
        $result  = $service->generate_report( $days, $user_id );

        $this->redirect_with_message(
            [ 'tab' => 'reports' ],
            $result['message'],
            $result['success'] ? 'success' : 'error'
        );
    }

    public function handle_delete_report(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '権限がありません。' ); }
        check_admin_referer( self::NONCE_KEY );

        $report_id = isset( $_POST['report_id'] ) ? (int) $_POST['report_id'] : 0;
        if ( $report_id > 0 ) {
            $post = get_post( $report_id );
            if ( $post && $post->post_type === 'mimamori_chat_report' ) {
                wp_delete_post( $report_id, true );
            }
        }

        $this->redirect_with_message( [ 'tab' => 'reports' ], 'レポートを削除しました。', 'success' );
    }

    public function handle_export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '権限がありません。' ); }
        check_admin_referer( self::NONCE_KEY );

        $filter_user    = isset( $_POST['flt_user'] ) ? (int) $_POST['flt_user'] : 0;
        $filter_intent  = isset( $_POST['flt_intent'] ) ? sanitize_text_field( wp_unslash( $_POST['flt_intent'] ) ) : '';
        $filter_from    = isset( $_POST['flt_from'] ) ? sanitize_text_field( wp_unslash( $_POST['flt_from'] ) ) : '';
        $filter_to      = isset( $_POST['flt_to'] ) ? sanitize_text_field( wp_unslash( $_POST['flt_to'] ) ) : '';
        $filter_keyword = isset( $_POST['flt_q'] ) ? sanitize_text_field( wp_unslash( $_POST['flt_q'] ) ) : '';

        [ $rows, $total, $_intents ] = $this->query_logs( $filter_user, $filter_intent, $filter_from, $filter_to, $filter_keyword, 1, 10000 );

        $filename = 'chat_logs_' . date( 'Ymd_His' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        // Excel 用 BOM
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, [
            'id', 'created_at', 'user_id', 'intent', 'page_type', 'page_url',
            'is_quick_prompt', 'is_followup', 'param_gate', 'model', 'error_message',
            'message', 'response',
        ] );
        foreach ( $rows as $row ) {
            fputcsv( $out, [
                $row['id'],
                $row['created_at'],
                $row['user_id'],
                $row['intent'],
                $row['page_type'],
                $row['page_url'],
                $row['is_quick_prompt'],
                $row['is_followup'],
                $row['param_gate'],
                $row['model'],
                $row['error_message'],
                $row['message'],
                $row['response'],
            ] );
        }
        fclose( $out );
        exit;
    }

    // =========================================================
    // DB queries
    // =========================================================

    /**
     * @return array{0:array<int,array>,1:int,2:array<int,string>}
     */
    private function query_logs(
        int    $user_id,
        string $intent,
        string $from,
        string $to,
        string $keyword,
        int    $page_num,
        int    $per_page = 0
    ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_chat_logs';

        if ( $per_page <= 0 ) { $per_page = self::PAGE_SIZE; }

        $where  = [ '1=1' ];
        $params = [];

        if ( $user_id > 0 ) {
            $where[] = 'user_id = %d';
            $params[] = $user_id;
        }
        if ( $intent !== '' ) {
            $where[] = 'intent = %s';
            $params[] = $intent;
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
            $where[] = 'created_at >= %s';
            $params[] = $from . ' 00:00:00';
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            $where[] = 'created_at <= %s';
            $params[] = $to . ' 23:59:59';
        }
        if ( $keyword !== '' ) {
            $where[] = '(message LIKE %s OR response LIKE %s)';
            $like = '%' . $wpdb->esc_like( $keyword ) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $where_sql = implode( ' AND ', $where );

        // 件数
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = $params
            ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
            : (int) $wpdb->get_var( $count_sql );

        // データ
        $offset = ( $page_num - 1 ) * $per_page;
        $data_sql = "SELECT id, user_id, session_id, role, message, response, intent, page_type,
                            page_url, is_followup, is_quick_prompt, param_gate, model, error_message,
                            created_at
                     FROM {$table}
                     WHERE {$where_sql}
                     ORDER BY created_at DESC
                     LIMIT %d OFFSET %d";
        $data_params = array_merge( $params, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_params ), ARRAY_A );
        if ( ! is_array( $rows ) ) { $rows = []; }

        // Intent 候補（直近データから抽出）
        $intents = $wpdb->get_col(
            "SELECT DISTINCT intent FROM {$table}
             WHERE intent != '' AND intent IS NOT NULL
             ORDER BY intent ASC LIMIT 50"
        );
        if ( ! is_array( $intents ) ) { $intents = []; }

        return [ $rows, $total, $intents ];
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function truncate( string $text, int $len ): string {
        if ( mb_strlen( $text ) <= $len ) { return $text; }
        return mb_substr( $text, 0, $len ) . '…';
    }

    private function redirect_with_message( array $args, string $message, string $type = 'success' ): void {
        $url = add_query_arg( array_merge( [
            'page'       => self::MENU_SLUG,
            'gcrev_msg'  => $message,
            'gcrev_type' => $type,
        ], $args ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }

    private function render_inline_styles(): void {
        ?>
        <style>
        .gcrev-filter-bar { padding:12px; background:#fff; border:1px solid #ddd; border-radius:4px; margin:12px 0; display:flex; gap:14px; flex-wrap:wrap; align-items:center; }
        .gcrev-filter-bar label { font-size:12px; color:#333; }
        .gcrev-card { background:#fff; border:1px solid #e0e0e0; border-radius:4px; padding:16px 20px; margin:16px 0; }
        .gcrev-card h2, .gcrev-card h3 { margin-top:0; }
        .gcrev-logs-table { margin-top:12px; }
        #gcrev-log-modal { position:fixed; top:0; left:0; width:100%; height:100%; z-index:100000; }
        #gcrev-log-modal .gcrev-modal-backdrop { position:absolute; inset:0; background:rgba(0,0,0,0.5); }
        #gcrev-log-modal .gcrev-modal-body { position:relative; margin:40px auto; max-width:900px; background:#fff; padding:30px; border-radius:8px; max-height:85vh; overflow:auto; }
        #gcrev-log-modal .gcrev-modal-close { position:absolute; top:10px; right:14px; background:none; border:none; font-size:24px; cursor:pointer; }
        </style>
        <?php
    }
}
