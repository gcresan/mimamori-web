<?php
/**
 * チャットボット管理 — 履歴タブ
 *
 * 変数: $tenant, $is_admin, $return_url
 */
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$st = Mimamori_Bot_Installer::table_sessions();
$mt = Mimamori_Bot_Installer::table_messages();
$et = Mimamori_Bot_Installer::table_events();

$session_uuid = isset( $_GET['session'] ) ? sanitize_text_field( (string) $_GET['session'] ) : '';

if ( $session_uuid !== '' ) {
    // 詳細表示
    $session = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$st} WHERE tenant_id = %d AND session_uuid = %s LIMIT 1",
        (int) $tenant['id'], $session_uuid
    ), ARRAY_A );

    $back_url = home_url( '/chatbot/?tab=history' );
    echo '<p><a href="' . esc_url( $back_url ) . '">← 一覧へ戻る</a></p>';

    if ( ! $session ) {
        echo '<div class="mb-card"><p>セッションが見つかりません。</p></div>';
    } else {
        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, role, content, tokens_in, tokens_out, cost_microjpy, model, latency_ms, knowledge_refs, created_at
               FROM {$mt}
              WHERE tenant_id = %d AND session_id = %d
              ORDER BY id ASC",
            (int) $tenant['id'], (int) $session['id']
        ), ARRAY_A );

        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT type, payload, created_at FROM {$et}
              WHERE tenant_id = %d AND session_id = %d
              ORDER BY id ASC",
            (int) $tenant['id'], (int) $session['id']
        ), ARRAY_A );

        echo '<div class="mb-card">';
        echo '<h2>セッション詳細</h2>';
        echo '<table class="mb-table"><tbody>';
        echo '<tr><th>UUID</th><td><code>' . esc_html( (string) $session['session_uuid'] ) . '</code></td></tr>';
        echo '<tr><th>開始</th><td>' . esc_html( (string) $session['started_at'] ) . '</td></tr>';
        echo '<tr><th>UA</th><td><code style="font-size:11px">' . esc_html( mb_substr( (string) ( $session['user_agent'] ?? '' ), 0, 200 ) ) . '</code></td></tr>';
        echo '<tr><th>ランディング</th><td><code>' . esc_html( (string) ( $session['landing_url'] ?? '' ) ) . '</code></td></tr>';
        echo '<tr><th>発話数</th><td>' . esc_html( (string) (int) $session['message_count'] ) . '</td></tr>';
        echo '<tr><th>CV</th><td>'
            . ( (int) $session['quote_clicked']   ? '✅ 見積CTAクリック ' : '' )
            . ( (int) $session['contact_clicked'] ? '✅ 問合CTAクリック'  : '' )
            . '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        echo '<div class="mb-card">';
        echo '<h2>会話</h2>';
        if ( empty( $messages ) ) {
            echo '<p>発話なし。</p>';
        } else {
            $total_cost = 0; $total_in = 0; $total_out = 0;
            foreach ( $messages as $m ) {
                $role = (string) $m['role'];
                $is_user = $role === 'user';
                $align = $is_user ? 'right' : 'left';
                $bg = $is_user ? '#dbeafe' : '#fff';
                $total_cost += (int) ( $m['cost_microjpy'] ?? 0 );
                $total_in   += (int) ( $m['tokens_in']     ?? 0 );
                $total_out  += (int) ( $m['tokens_out']    ?? 0 );
                echo '<div style="text-align:' . esc_attr( $align ) . ';margin:10px 0">';
                echo '<div style="display:inline-block;background:' . esc_attr( $bg ) . ';padding:10px 14px;border-radius:10px;border:1px solid #e5e7eb;max-width:80%;text-align:left;white-space:pre-wrap;">';
                echo '<div style="font-size:11px;color:#6b7280">' . esc_html( $role ) . ' · ' . esc_html( (string) $m['created_at'] ) . '</div>';
                echo esc_html( (string) $m['content'] );
                if ( ! empty( $m['knowledge_refs'] ) ) {
                    echo '<div style="font-size:11px;color:#6b7280;margin-top:6px">📎 引用: <code>' . esc_html( mb_substr( (string) $m['knowledge_refs'], 0, 200 ) ) . '</code></div>';
                }
                if ( ! $is_user && ( $m['tokens_in'] || $m['tokens_out'] ) ) {
                    if ( $is_admin ) {
                        $cost_jpy = (int) ( $m['cost_microjpy'] ?? 0 ) / 1000000;
                        echo '<div style="font-size:11px;color:#6b7280;margin-top:4px">' . esc_html( (string) $m['model'] )
                            . ' / in=' . esc_html( (string) $m['tokens_in'] )
                            . ' / out=' . esc_html( (string) $m['tokens_out'] )
                            . ' / ' . esc_html( (string) (int) $m['latency_ms'] ) . 'ms'
                            . ' / ¥' . number_format( $cost_jpy, 4 ) . ' <span style="color:#9ca3af">[運営者のみ]</span></div>';
                    } else {
                        echo '<div style="font-size:11px;color:#6b7280;margin-top:4px">' . esc_html( (string) (int) $m['latency_ms'] ) . 'ms</div>';
                    }
                }
                echo '</div></div>';
            }
            if ( $is_admin ) {
                $total_jpy = $total_cost / 1000000;
                echo '<p style="margin-top:16px;padding-top:12px;border-top:1px solid #e5e7eb"><strong>合計コスト:</strong> ¥' . esc_html( number_format( $total_jpy, 4 ) ) . ' (in=' . esc_html( (string) $total_in ) . ', out=' . esc_html( (string) $total_out ) . ' tokens) <span style="color:#9ca3af">[運営者のみ]</span></p>';
            }
        }
        echo '</div>';

        if ( ! empty( $events ) ) {
            echo '<div class="mb-card"><h2>イベント</h2>';
            echo '<table class="mb-table"><thead><tr><th>時刻</th><th>種別</th><th>payload</th></tr></thead><tbody>';
            foreach ( $events as $e ) {
                echo '<tr><td style="font-size:12px">' . esc_html( (string) $e['created_at'] ) . '</td><td>' . esc_html( (string) $e['type'] ) . '</td><td><code style="font-size:11px">' . esc_html( mb_substr( (string) ( $e['payload'] ?? '' ), 0, 200 ) ) . '</code></td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }
    return;
}

// 一覧表示
$per    = 30;
$page   = max( 1, isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 1 );
$offset = ( $page - 1 ) * $per;

$total = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$st} WHERE tenant_id = %d", (int) $tenant['id']
) );
$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT session_uuid, started_at, last_active_at, message_count, quote_clicked, contact_clicked, landing_url
       FROM {$st}
      WHERE tenant_id = %d
      ORDER BY started_at DESC
      LIMIT %d OFFSET %d",
    (int) $tenant['id'], $per, $offset
), ARRAY_A );
?>

<div class="mb-card">
    <h2>チャット履歴 (総 <?php echo esc_html( (string) $total ); ?> セッション)</h2>
    <?php if ( empty( $rows ) ) : ?>
        <p>まだセッションはありません。</p>
    <?php else : ?>
        <table class="mb-table">
            <thead><tr><th>開始</th><th>発話数</th><th>CV</th><th>ランディング</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $detail = add_query_arg( [ 'tab' => 'history', 'session' => $r['session_uuid'] ], home_url( '/chatbot/' ) );
            ?>
                <tr>
                    <td><?php echo esc_html( (string) $r['started_at'] ); ?></td>
                    <td><?php echo esc_html( (string) (int) $r['message_count'] ); ?></td>
                    <td><?php echo ( (int) $r['quote_clicked'] ? '✅見積 ' : '' ) . ( (int) $r['contact_clicked'] ? '✅問合 ' : '' ); ?></td>
                    <td><code style="font-size:11px"><?php echo esc_html( mb_substr( (string) ( $r['landing_url'] ?? '' ), 0, 60 ) ); ?></code></td>
                    <td><a href="<?php echo esc_url( $detail ); ?>" class="mb-btn mb-btn-secondary" style="padding:4px 12px;font-size:12px">詳細</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $total_pages = (int) ceil( $total / $per );
        if ( $total_pages > 1 ) : ?>
            <nav class="mb-pagination" aria-label="ページ送り">
            <?php for ( $i = 1; $i <= $total_pages; $i++ ) :
                $url = add_query_arg( [ 'tab' => 'history', 'p' => $i ], home_url( '/chatbot/' ) );
                if ( $i === $page ) {
                    echo '<strong>' . esc_html( (string) $i ) . '</strong>';
                } else {
                    echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a>';
                }
            endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
