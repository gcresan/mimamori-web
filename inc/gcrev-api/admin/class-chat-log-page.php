<?php
// FILE: inc/gcrev-api/admin/class-chat-log-page.php
// =====================================================================
// 管理画面「チャット質問ログ」
//
// 全クライアントのAIチャット質問を横断で一覧する簡易ビュー。
// フィルタ: 期間 / クライアント / 起動経路（通知種別・通常）。CSVエクスポート対応。
//
// 目的: クライアントが何に迷っているかを観測し、深掘りレポート・FAQ・
//       提案通知の改善材料にする。
// =====================================================================

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Gcrev_Chat_Log_Page' ) ) { return; }

class Gcrev_Chat_Log_Page {

    private const MENU_SLUG = 'gcrev-chat-logs';
    private const PER_PAGE  = 50;

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_post_gcrev_chat_logs_csv', [ $this, 'export_csv' ] );
    }

    public function add_menu_page(): void {
        add_submenu_page(
            'gcrev-insight',
            'チャット質問ログ - みまもりウェブ',
            "\xF0\x9F\x92\xAC チャット質問ログ",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * フィルタ条件を $_GET から取得・サニタイズして返す。
     */
    private function get_filters(): array {
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
        $date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) { $date_from = ''; }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) { $date_to = ''; }

        $source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
        if ( ! in_array( $source, [ 'notify_alert', 'notify_digest', 'notify_suggest', 'normal' ], true ) ) {
            $source = '';
        }

        return [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'user_id'   => isset( $_GET['filter_user'] ) ? absint( $_GET['filter_user'] ) : 0,
            'source'    => $source,
            'paged'     => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
        ];
    }

    /**
     * フィルタ条件から WHERE 句とプレースホルダ値を組み立てる。
     *
     * @return array{where:string, values:array}
     */
    private function build_where( array $f ): array {
        $where  = [ '1=1' ];
        $values = [];

        if ( $f['date_from'] !== '' ) {
            $where[]  = 'created_at >= %s';
            $values[] = $f['date_from'] . ' 00:00:00';
        }
        if ( $f['date_to'] !== '' ) {
            $where[]  = 'created_at <= %s';
            $values[] = $f['date_to'] . ' 23:59:59';
        }
        if ( $f['user_id'] > 0 ) {
            $where[]  = 'user_id = %d';
            $values[] = $f['user_id'];
        }
        if ( $f['source'] !== '' ) {
            if ( $f['source'] === 'normal' ) {
                // 旧ログ（source未記録 = ''）も通常起動として扱う
                $where[] = "( source = 'normal' OR source = '' )";
            } else {
                $where[]  = 'source = %s';
                $values[] = $f['source'];
            }
        }
        return [ 'where' => implode( ' AND ', $where ), 'values' => $values ];
    }

    private function query_logs( array $f, int $limit, int $offset ): array {
        global $wpdb;
        $table = function_exists( 'gcrev_chat_logs_table_name' ) ? gcrev_chat_logs_table_name() : $wpdb->prefix . 'gcrev_chat_logs';
        $w     = $this->build_where( $f );

        $sql = "SELECT id, user_id, message, response, intent, page_type, source, created_at
                FROM {$table} WHERE {$w['where']}
                ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $values   = array_merge( $w['values'], [ $limit, $offset ] );
        $prepared = $wpdb->prepare( $sql, $values );
        return (array) $wpdb->get_results( $prepared, ARRAY_A );
    }

    private function count_logs( array $f ): int {
        global $wpdb;
        $table = function_exists( 'gcrev_chat_logs_table_name' ) ? gcrev_chat_logs_table_name() : $wpdb->prefix . 'gcrev_chat_logs';
        $w     = $this->build_where( $f );
        $sql   = "SELECT COUNT(*) FROM {$table} WHERE {$w['where']}";
        return (int) ( empty( $w['values'] ) ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $w['values'] ) ) );
    }

    private static function source_label( string $source ): string {
        $map = [
            'notify_alert'   => 'アラート通知',
            'notify_digest'  => '週次便',
            'notify_suggest' => '改善提案通知',
            'normal'         => '通常起動',
            ''               => '通常起動',
        ];
        return $map[ $source ] ?? $source;
    }

    // =================================================================
    // 一覧ページ
    // =================================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $f      = $this->get_filters();
        $total  = $this->count_logs( $f );
        $pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
        $paged  = min( $f['paged'], $pages );
        $logs   = $this->query_logs( $f, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

        // クライアント選択肢（ログに登場するユーザーのみ）
        global $wpdb;
        $table    = function_exists( 'gcrev_chat_logs_table_name' ) ? gcrev_chat_logs_table_name() : $wpdb->prefix . 'gcrev_chat_logs';
        $user_ids = (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table} ORDER BY user_id" );

        $csv_url = wp_nonce_url(
            add_query_arg(
                array_filter( [
                    'action'      => 'gcrev_chat_logs_csv',
                    'date_from'   => $f['date_from'],
                    'date_to'     => $f['date_to'],
                    'filter_user' => $f['user_id'] ?: null,
                    'source'      => $f['source'],
                ] ),
                admin_url( 'admin-post.php' )
            ),
            'gcrev_chat_logs_csv'
        );
        ?>
        <div class="wrap">
            <h1>💬 チャット質問ログ</h1>
            <p>全クライアントのAIチャット質問を横断で確認できます（FAQ・深掘りレポート・提案通知の改善材料）。</p>

            <form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
                <label>期間:
                    <input type="date" name="date_from" value="<?php echo esc_attr( $f['date_from'] ); ?>"> 〜
                    <input type="date" name="date_to" value="<?php echo esc_attr( $f['date_to'] ); ?>">
                </label>
                <label>クライアント:
                    <select name="filter_user">
                        <option value="">すべて</option>
                        <?php foreach ( $user_ids as $uid ) :
                            $u = get_userdata( (int) $uid );
                            $label = $u ? ( $u->display_name ?: $u->user_login ) : "user #{$uid}";
                        ?>
                        <option value="<?php echo esc_attr( $uid ); ?>" <?php selected( $f['user_id'], (int) $uid ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>起動経路:
                    <select name="source">
                        <option value="">すべて</option>
                        <option value="notify_alert" <?php selected( $f['source'], 'notify_alert' ); ?>>アラート通知</option>
                        <option value="notify_digest" <?php selected( $f['source'], 'notify_digest' ); ?>>週次便</option>
                        <option value="notify_suggest" <?php selected( $f['source'], 'notify_suggest' ); ?>>改善提案通知</option>
                        <option value="normal" <?php selected( $f['source'], 'normal' ); ?>>通常起動</option>
                    </select>
                </label>
                <button type="submit" class="button">絞り込む</button>
                <a href="<?php echo esc_url( $csv_url ); ?>" class="button">CSVエクスポート</a>
                <span style="color:#666;">全 <?php echo esc_html( number_format( $total ) ); ?> 件</span>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:130px;">日時</th>
                        <th style="width:140px;">クライアント</th>
                        <th style="width:110px;">起動経路</th>
                        <th>質問内容</th>
                        <th style="width:110px;">意図分類</th>
                        <th style="width:110px;">ページ種別</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $logs ) ) : ?>
                    <tr><td colspan="6">該当するログがありません。</td></tr>
                <?php else : foreach ( $logs as $row ) :
                    $u = get_userdata( (int) $row['user_id'] );
                ?>
                    <tr>
                        <td><?php echo esc_html( $row['created_at'] ); ?></td>
                        <td><?php echo esc_html( $u ? ( $u->display_name ?: $u->user_login ) : "user #{$row['user_id']}" ); ?></td>
                        <td><?php echo esc_html( self::source_label( (string) $row['source'] ) ); ?></td>
                        <td><?php echo esc_html( mb_substr( (string) $row['message'], 0, 120 ) ); ?></td>
                        <td><?php echo esc_html( (string) $row['intent'] ); ?></td>
                        <td><?php echo esc_html( (string) $row['page_type'] ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ( $pages > 1 ) : ?>
            <div class="tablenav"><div class="tablenav-pages">
                <?php
                echo paginate_links( [
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $paged,
                    'total'   => $pages,
                ] );
                ?>
            </div></div>
            <?php endif; ?>
        </div>
        <?php
    }

    // =================================================================
    // CSVエクスポート
    // =================================================================

    public function export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '権限がありません。' );
        }
        check_admin_referer( 'gcrev_chat_logs_csv' );

        $f    = $this->get_filters();
        $logs = $this->query_logs( $f, 10000, 0 );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="mimamori-chat-logs-' . wp_date( 'Ymd-His' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        // Excel で文字化けしないよう BOM を付与
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, [ '日時', 'ユーザーID', 'クライアント名', '起動経路', '質問内容', 'AI応答', '意図分類', 'ページ種別' ] );

        foreach ( $logs as $row ) {
            $u = get_userdata( (int) $row['user_id'] );
            fputcsv( $out, [
                $row['created_at'],
                $row['user_id'],
                $u ? ( $u->display_name ?: $u->user_login ) : '',
                self::source_label( (string) $row['source'] ),
                (string) $row['message'],
                (string) $row['response'],
                (string) $row['intent'],
                (string) $row['page_type'],
            ] );
        }
        fclose( $out );
        exit;
    }
}
