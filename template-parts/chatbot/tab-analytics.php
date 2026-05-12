<?php
/**
 * チャットボット管理 — 分析タブ
 *
 * 変数: $tenant, $is_admin, $return_url
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
$valid_periods = [ 7, 30, 90, 365 ];
if ( ! in_array( $days, $valid_periods, true ) ) $days = 30;
$since = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days' ) );

global $wpdb;
$st = Mimamori_Bot_Installer::table_sessions();
$mt = Mimamori_Bot_Installer::table_messages();
$ft = Mimamori_Bot_Installer::table_faq();

// KPI
$total_sessions = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$st} WHERE tenant_id = %d AND started_at >= %s", (int) $tenant['id'], $since ) );
$conv_sessions  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$st} WHERE tenant_id = %d AND started_at >= %s AND message_count >= 1", (int) $tenant['id'], $since ) );
$quote_clicks   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$st} WHERE tenant_id = %d AND started_at >= %s AND quote_clicked = 1", (int) $tenant['id'], $since ) );
$contact_clicks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$st} WHERE tenant_id = %d AND started_at >= %s AND contact_clicked = 1", (int) $tenant['id'], $since ) );
$avg_msgs       = (float) $wpdb->get_var( $wpdb->prepare( "SELECT IFNULL(AVG(message_count),0) FROM {$st} WHERE tenant_id = %d AND started_at >= %s", (int) $tenant['id'], $since ) );

$msg_total = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$mt} WHERE tenant_id = %d AND created_at >= %s",
    (int) $tenant['id'], $since
) );

$cv_total_rate = $total_sessions > 0
    ? round( ( $quote_clicks + $contact_clicks ) * 100 / $total_sessions, 1 )
    : 0.0;

$cards = [
    [ 'label' => 'セッション数', 'value' => number_format( $total_sessions ), 'sub' => '対話完了 ' . number_format( $conv_sessions ) . ' 件' ],
    [ 'label' => 'CV合計率',     'value' => $cv_total_rate . ' %',           'sub' => '見積 ' . $quote_clicks . ' / 問合 ' . $contact_clicks ],
    [ 'label' => '平均発話数',   'value' => round( $avg_msgs, 1 ),           'sub' => '対話あたり' ],
    [ 'label' => '総メッセージ数','value' => number_format( $msg_total ),    'sub' => 'user + assistant' ],
];

// 運営者のみ：トークン消費・推定コストを取得して追加カードに反映
if ( $is_admin ) {
    $cost_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT SUM(tokens_in) AS in_sum, SUM(tokens_out) AS out_sum, SUM(cost_microjpy) AS cost_sum
           FROM {$mt} WHERE tenant_id = %d AND created_at >= %s",
        (int) $tenant['id'], $since
    ), ARRAY_A );
    $tokens_in  = (int) ( $cost_row['in_sum']  ?? 0 );
    $tokens_out = (int) ( $cost_row['out_sum'] ?? 0 );
    $cost_micro = (int) ( $cost_row['cost_sum'] ?? 0 );

    $cards[] = [ 'label' => 'トークン消費 [運営者のみ]', 'value' => number_format( $tokens_in + $tokens_out ), 'sub' => 'in ' . number_format( $tokens_in ) . ' / out ' . number_format( $tokens_out ) ];
    $cards[] = [ 'label' => '推定コスト [運営者のみ]',   'value' => '¥' . number_format( $cost_micro / 1000000, 2 ), 'sub' => 'JPY換算' ];
}

// 離脱分布
$drop_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT message_count, COUNT(*) AS cnt FROM {$st}
      WHERE tenant_id = %d AND started_at >= %s
      GROUP BY message_count
      ORDER BY message_count ASC LIMIT 20",
    (int) $tenant['id'], $since
), ARRAY_A );

// 人気FAQ
$top_faqs = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, question, hit_count, is_starter FROM {$ft}
      WHERE tenant_id = %d AND status = 'active' AND hit_count > 0
      ORDER BY hit_count DESC, priority DESC LIMIT 10",
    (int) $tenant['id']
), ARRAY_A );
?>

<div class="mb-card">
    <h2>分析ダッシュボード</h2>

    <div class="mb-period">
    <?php foreach ( [ 7 => '直近7日', 30 => '直近30日', 90 => '直近90日', 365 => '直近1年' ] as $d => $label ) :
        $url = add_query_arg( [ 'tab' => 'analytics', 'days' => $d ], home_url( '/chatbot/' ) );
        if ( $d === $days ) {
            echo '<strong>' . esc_html( $label ) . '</strong>';
        } else {
            echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
    endforeach; ?>
    </div>

    <div class="mb-kpi-grid">
        <?php foreach ( $cards as $c ) : ?>
            <div class="mb-kpi-card">
                <div class="mb-kpi-label"><?php echo esc_html( $c['label'] ); ?></div>
                <div class="mb-kpi-value"><?php echo esc_html( (string) $c['value'] ); ?></div>
                <div class="mb-kpi-sub"><?php echo esc_html( $c['sub'] ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
    <div class="mb-card">
        <h2>離脱分布 (発話数別)</h2>
        <?php if ( empty( $drop_rows ) ) : ?>
            <p>データなし。</p>
        <?php else :
            $max = 0;
            foreach ( $drop_rows as $r ) $max = max( $max, (int) $r['cnt'] );
            if ( $max < 1 ) $max = 1;
        ?>
            <table style="width:100%;font-size:13px"><tbody>
            <?php foreach ( $drop_rows as $r ) :
                $mc = (int) $r['message_count'];
                $cnt = (int) $r['cnt'];
                $pct = $cnt * 100 / $max;
                $label = $mc === 0 ? '0発話 (起動のみ)' : ( $mc . ' 発話' );
            ?>
                <tr>
                    <td style="padding:4px 8px;width:120px;color:#374151"><?php echo esc_html( $label ); ?></td>
                    <td style="padding:4px 0"><div class="mb-bar" style="background:#dbeafe;width:<?php echo esc_attr( (string) $pct ); ?>%"></div></td>
                    <td style="padding:4px 8px;width:60px;text-align:right;font-variant-numeric:tabular-nums"><?php echo esc_html( (string) $cnt ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <p style="font-size:11px;color:#94a3b8;margin:8px 0 0">「1発話」で離脱が多い場合は FAQ 充実度を見直すサイン。</p>
        <?php endif; ?>
    </div>

    <div class="mb-card">
        <h2>人気FAQ Top10</h2>
        <?php if ( empty( $top_faqs ) ) : ?>
            <p>まだヒットしたFAQはありません。</p>
        <?php else :
            $max = 0;
            foreach ( $top_faqs as $r ) $max = max( $max, (int) $r['hit_count'] );
            if ( $max < 1 ) $max = 1;
        ?>
            <table style="width:100%;font-size:13px"><tbody>
            <?php foreach ( $top_faqs as $r ) :
                $hc = (int) $r['hit_count'];
                $pct = $hc * 100 / $max;
            ?>
                <tr>
                    <td style="padding:4px 8px"><?php echo ( (int) $r['is_starter'] ? '★ ' : '' ) . esc_html( mb_substr( (string) $r['question'], 0, 50 ) ); ?></td>
                    <td style="padding:4px 0;width:30%"><div class="mb-bar" style="background:#fde68a;width:<?php echo esc_attr( (string) $pct ); ?>%"></div></td>
                    <td style="padding:4px 8px;width:50px;text-align:right;font-variant-numeric:tabular-nums"><?php echo esc_html( (string) $hc ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </div>
</div>
