<?php
/*
Template Name: MEOレポート
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

mimamori_guard_meo_access();

if ( ! class_exists( 'Gcrev_Meo_Report_Service' ) ) {
    require_once get_template_directory() . '/inc/gcrev-api/modules/class-meo-report-service.php';
}

$current_user = mimamori_get_view_user_object();
$user_id      = mimamori_get_view_user_id();
$tz           = wp_timezone();
$now          = new DateTimeImmutable( 'now', $tz );

// 月選択 (デフォルト: 前月。月初〜10日のうちは前月レポートを見たいことが多いため)
$year  = isset( $_GET['year'] )  ? max( 2024, min( 2099, absint( $_GET['year'] ) ) )  : (int) $now->format( 'Y' );
$month = isset( $_GET['month'] ) ? max( 1, min( 12, absint( $_GET['month'] ) ) ) : (int) $now->format( 'n' );

// 既定: ユーザーがアクセスした時点で前月 (1日〜10日のあいだは前月、それ以降は当月)
if ( ! isset( $_GET['year'] ) && ! isset( $_GET['month'] ) ) {
    $day = (int) $now->format( 'j' );
    if ( $day <= 10 ) {
        $prev = $now->modify( 'first day of last month' );
        $year  = (int) $prev->format( 'Y' );
        $month = (int) $prev->format( 'n' );
    }
}

$report = Gcrev_Meo_Report_Service::build_report( $user_id, $year, $month );

set_query_var( 'gcrev_page_title', 'MEOレポート — ' . esc_html( $report['period']['label'] ) );
set_query_var( 'gcrev_page_subtitle', '表示回数・行動数・クチコミ・順位・キーワード分析を前月比で確認できます。' );
set_query_var( 'gcrev_breadcrumb', function_exists( 'gcrev_breadcrumb' ) ? gcrev_breadcrumb( 'MEOレポート', 'MEO' ) : '' );

get_header();

$m  = $report['metrics'];
$mp = $report['metrics_prev'];

// 前月比計算ヘルパー
$render_pct = static function ( int $cur, int $prev ): string {
    if ( $prev === 0 && $cur === 0 ) {
        return '<span class="mr-pct mr-pct-zero">—</span>';
    }
    if ( $prev === 0 ) {
        return '<span class="mr-pct mr-pct-up">↑ NEW</span>';
    }
    $pct = round( ( $cur - $prev ) * 100 / $prev, 1 );
    if ( $pct > 0 ) {
        return '<span class="mr-pct mr-pct-up">↑ +' . esc_html( (string) $pct ) . '%</span>';
    }
    if ( $pct < 0 ) {
        return '<span class="mr-pct mr-pct-down">↓ ' . esc_html( (string) $pct ) . '%</span>';
    }
    return '<span class="mr-pct mr-pct-zero">→ 0%</span>';
};
?>

<style>
.mr-wrap { max-width: 1080px; margin: 0 auto; padding: 16px; }

/* ヘッダー操作バー */
.mr-toolbar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:24px; }
.mr-month-select { display:flex; gap:8px; align-items:center; }
.mr-month-select select { padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; background:#fff; }
.mr-actions { display:flex; gap:8px; }
.mr-btn { padding:8px 18px; border:1px solid #d1d5db; border-radius:6px; background:#fff; color:#374151; font-size:14px; font-weight:500; cursor:pointer; text-decoration:none; display:inline-block; }
.mr-btn-primary { background:#1a73e8; color:#fff; border-color:#1a73e8; }
.mr-btn-primary:hover { background:#1557b0; }
.mr-btn:hover { background:#f9fafb; }

/* セクション */
.mr-section { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:24px 28px; margin-bottom:20px; }
.mr-section h2 { margin:0 0 16px; font-size:18px; font-weight:600; color:#0f172a; padding-bottom:10px; border-bottom:2px solid #e5e7eb; }
.mr-section h3 { margin:18px 0 10px; font-size:14px; font-weight:600; color:#334155; }

/* KPI グリッド */
.mr-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; }
.mr-kpi { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; }
.mr-kpi-label { font-size:12px; color:#64748b; margin-bottom:6px; }
.mr-kpi-value { font-size:28px; font-weight:700; color:#0f172a; line-height:1.1; }
.mr-kpi-prev { font-size:11px; color:#94a3b8; margin-top:4px; }
.mr-pct { display:inline-block; padding:2px 8px; border-radius:6px; font-size:11px; font-weight:600; margin-left:6px; }
.mr-pct-up { background:#d1fae5; color:#047857; }
.mr-pct-down { background:#fee2e2; color:#b91c1c; }
.mr-pct-zero { background:#f1f5f9; color:#64748b; }

/* テーブル */
.mr-table { width:100%; border-collapse:collapse; font-size:13px; }
.mr-table th, .mr-table td { padding:8px 12px; text-align:left; border-bottom:1px solid #e5e7eb; }
.mr-table thead th { background:#f8fafc; font-weight:600; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.3px; }
.mr-table td.num, .mr-table th.num { text-align:right; font-variant-numeric:tabular-nums; }

/* 印刷スタイル */
@media print {
    body, .content-area { background:#fff !important; }
    .mr-toolbar, .header, .sidebar, .breadcrumb, #wpadminbar, .footer-area { display:none !important; }
    .mr-wrap { max-width:100%; padding:0; }
    .mr-section { box-shadow:none; border:1px solid #ccc; page-break-inside:avoid; margin-bottom:14px; padding:14px 18px; }
    .mr-section h2 { font-size:16px; }
    .mr-kpi-value { font-size:22px; }
    .mr-table { font-size:11px; }
    .mr-table th, .mr-table td { padding:5px 8px; }
    @page { margin: 14mm; }
}
</style>

<div class="content-area">
<div class="mr-wrap">

    <div class="mr-toolbar">
        <form method="GET" class="mr-month-select">
            <label for="year">期間:</label>
            <select id="year" name="year" onchange="this.form.submit()">
                <?php for ( $y = (int) $now->format('Y'); $y >= 2024; $y-- ) : ?>
                    <option value="<?php echo esc_attr( (string) $y ); ?>"<?php selected( $year, $y ); ?>><?php echo esc_html( (string) $y ); ?>年</option>
                <?php endfor; ?>
            </select>
            <select id="month" name="month" onchange="this.form.submit()">
                <?php for ( $mn = 1; $mn <= 12; $mn++ ) : ?>
                    <option value="<?php echo esc_attr( (string) $mn ); ?>"<?php selected( $month, $mn ); ?>><?php echo esc_html( (string) $mn ); ?>月</option>
                <?php endfor; ?>
            </select>
        </form>
        <div class="mr-actions">
            <button type="button" class="mr-btn mr-btn-primary" onclick="window.print()">🖨 印刷 / PDF出力</button>
            <a class="mr-btn" href="<?php echo esc_url( add_query_arg([
                'action' => 'gcrev_meo_report_csv',
                'year'   => $year,
                'month'  => $month,
                '_wpnonce' => wp_create_nonce( 'gcrev_meo_report_csv' ),
            ], admin_url( 'admin-post.php' ) ) ); ?>">📥 CSVダウンロード</a>
        </div>
    </div>

    <!-- 表示回数 / 行動数 -->
    <div class="mr-section">
        <h2>📊 表示回数 (<?php echo esc_html( $report['period']['label'] ); ?>)</h2>
        <div class="mr-kpi-grid">
            <?php
            $cards = [
                [ '📱 モバイル', $m['mobile_impressions'],  $mp['mobile_impressions'] ],
                [ '💻 デスクトップ', $m['desktop_impressions'], $mp['desktop_impressions'] ],
                [ '🔍 検索経由', $m['search_impressions'], $mp['search_impressions'] ],
                [ '🗺 マップ経由', $m['map_impressions'], $mp['map_impressions'] ],
            ];
            foreach ( $cards as [ $label, $cur, $prev ] ) :
            ?>
                <div class="mr-kpi">
                    <div class="mr-kpi-label"><?php echo esc_html( $label ); ?></div>
                    <div class="mr-kpi-value"><?php echo esc_html( number_format( (int) $cur ) ); ?></div>
                    <div class="mr-kpi-prev">前月: <?php echo esc_html( number_format( (int) $prev ) ); ?> <?php echo $render_pct( (int) $cur, (int) $prev ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="mr-section">
        <h2>👆 行動数</h2>
        <div class="mr-kpi-grid">
            <?php
            $action_cards = [
                [ '🌐 ウェブサイト', $m['website_clicks'],   $mp['website_clicks'] ],
                [ '📞 電話',         $m['call_clicks'],      $mp['call_clicks'] ],
                [ '🚗 ルート',       $m['direction_clicks'], $mp['direction_clicks'] ],
            ];
            foreach ( $action_cards as [ $label, $cur, $prev ] ) :
            ?>
                <div class="mr-kpi">
                    <div class="mr-kpi-label"><?php echo esc_html( $label ); ?></div>
                    <div class="mr-kpi-value"><?php echo esc_html( number_format( (int) $cur ) ); ?></div>
                    <div class="mr-kpi-prev">前月: <?php echo esc_html( number_format( (int) $prev ) ); ?> <?php echo $render_pct( (int) $cur, (int) $prev ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- クチコミ -->
    <div class="mr-section">
        <h2>⭐ クチコミ</h2>
        <div class="mr-kpi-grid">
            <div class="mr-kpi">
                <div class="mr-kpi-label">総クチコミ数</div>
                <div class="mr-kpi-value"><?php echo esc_html( number_format( (int) $report['reviews']['count'] ) ); ?></div>
                <div class="mr-kpi-prev">累計</div>
            </div>
            <div class="mr-kpi">
                <div class="mr-kpi-label">平均評価</div>
                <div class="mr-kpi-value"><?php echo esc_html( number_format( (float) $report['reviews']['average_rating'], 2 ) ); ?> <span style="font-size:18px;color:#fbbf24">★</span></div>
                <div class="mr-kpi-prev">5点満点</div>
            </div>
        </div>
    </div>

    <!-- 順位 -->
    <div class="mr-section">
        <h2>📈 順位チェック</h2>
        <?php if ( empty( $report['ranks'] ) ) : ?>
            <p style="color:#94a3b8">登録キーワードがありません。</p>
        <?php else : ?>
            <table class="mr-table">
                <thead><tr><th>キーワード</th><th class="num">今月最新順位</th><th class="num">前月最新順位</th><th class="num">変動</th><th>取得日</th></tr></thead>
                <tbody>
                <?php foreach ( $report['ranks'] as $r ) :
                    $cur  = $r['rank'];
                    $prev = $r['rank_prev'];
                    if ( $cur !== null && $prev !== null ) {
                        $delta = $prev - $cur; // 順位は小さいほど良い
                        $delta_html = $delta > 0
                            ? '<span class="mr-pct mr-pct-up">↑ ' . esc_html( (string) $delta ) . '位上昇</span>'
                            : ( $delta < 0 ? '<span class="mr-pct mr-pct-down">↓ ' . esc_html( (string) abs( $delta ) ) . '位下降</span>' : '<span class="mr-pct mr-pct-zero">→ 変動なし</span>' );
                    } else {
                        $delta_html = '<span class="mr-pct mr-pct-zero">—</span>';
                    }
                ?>
                    <tr>
                        <td><?php echo esc_html( $r['keyword'] ); ?></td>
                        <td class="num"><?php echo $cur  !== null ? esc_html( (string) $cur ) . '位'  : '<span style="color:#9ca3af">圏外</span>'; ?></td>
                        <td class="num"><?php echo $prev !== null ? esc_html( (string) $prev ) . '位' : '<span style="color:#9ca3af">圏外</span>'; ?></td>
                        <td class="num"><?php echo $delta_html; ?></td>
                        <td><?php echo esc_html( (string) ( $r['fetched_date'] ?? '—' ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- キーワード分析 -->
    <div class="mr-section">
        <h2>🔎 GBP 検索キーワード TOP20</h2>
        <?php if ( empty( $report['keywords'] ) ) : ?>
            <p style="color:#94a3b8">データなし (GBP 検索キーワード未取得 or 期間内データなし)</p>
        <?php else : ?>
            <table class="mr-table">
                <thead><tr><th>#</th><th>キーワード</th><th class="num">回数</th></tr></thead>
                <tbody>
                <?php foreach ( $report['keywords'] as $i => $k ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
                        <td><?php echo esc_html( (string) $k['keyword'] ); ?></td>
                        <td class="num"><?php echo esc_html( number_format( (int) $k['value'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( ! empty( $report['keywords_diff'] ) ) : ?>
            <h3>🚀 増加キーワード TOP10 (前月比)</h3>
            <table class="mr-table">
                <thead><tr><th>#</th><th>キーワード</th><th class="num">前月</th><th class="num">今月</th><th class="num">増加</th></tr></thead>
                <tbody>
                <?php foreach ( $report['keywords_diff'] as $i => $d ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
                        <td><?php echo esc_html( (string) $d['keyword'] ); ?></td>
                        <td class="num"><?php echo esc_html( number_format( (int) $d['value_prev'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( number_format( (int) $d['value'] ) ); ?></td>
                        <td class="num"><span class="mr-pct mr-pct-up">+<?php echo esc_html( number_format( (int) $d['delta'] ) ); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>
</div>

<?php get_footer(); ?>
