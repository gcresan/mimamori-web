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

/* ダウンロードボタン */
.mr-dl-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 16px; border:1px solid #d0d5dd; border-radius:8px; background:#fff; color:#344054; font-size:13px; font-weight:600; cursor:pointer; transition:all 0.15s; }
.mr-dl-btn:hover { background:#f9fafb; border-color:#98a2b3; }
.mr-dl-btn:disabled { opacity:0.55; cursor:not-allowed; }
.mr-dl-btn-primary { background:#1a73e8; color:#fff; border-color:#1a73e8; }
.mr-dl-btn-primary:hover { background:#1557b0; border-color:#1557b0; }

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
            <button type="button" id="mrCsvBtn" class="mr-dl-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                CSV ダウンロード
            </button>
            <button type="button" id="mrPdfBtn" class="mr-dl-btn mr-dl-btn-primary">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                PDF ダウンロード
            </button>
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
    <?php
    $rv             = $report['reviews'];
    $rv_delta_curr  = (int) ( $rv['delta_curr'] ?? 0 );
    $rv_delta_prev  = (int) ( $rv['delta_prev'] ?? 0 );
    $rv_curr_has    = ! empty( $rv['delta_curr_has_baseline'] );
    $rv_prev_has    = ! empty( $rv['delta_prev_has_baseline'] );
    ?>
    <div class="mr-section">
        <h2>⭐ クチコミ</h2>
        <div class="mr-kpi-grid">
            <div class="mr-kpi">
                <div class="mr-kpi-label">総クチコミ数</div>
                <div class="mr-kpi-value"><?php echo esc_html( number_format( (int) $rv['count'] ) ); ?></div>
                <div class="mr-kpi-prev">累計</div>
            </div>
            <div class="mr-kpi">
                <div class="mr-kpi-label">平均評価</div>
                <div class="mr-kpi-value"><?php echo esc_html( number_format( (float) $rv['average_rating'], 2 ) ); ?> <span style="font-size:18px;color:#fbbf24">★</span></div>
                <div class="mr-kpi-prev">5点満点</div>
            </div>
            <div class="mr-kpi">
                <div class="mr-kpi-label">今月の新規クチコミ</div>
                <?php if ( $rv_curr_has ) : ?>
                    <div class="mr-kpi-value">+<?php echo esc_html( number_format( $rv_delta_curr ) ); ?></div>
                    <div class="mr-kpi-prev">前月: +<?php echo esc_html( number_format( $rv_delta_prev ) ); ?> <?php echo $render_pct( $rv_delta_curr, $rv_delta_prev ); ?></div>
                <?php else : ?>
                    <div class="mr-kpi-value" style="color:#94a3b8;">—</div>
                    <div class="mr-kpi-prev">クチコミデータ未取得</div>
                <?php endif; ?>
            </div>
            <div class="mr-kpi">
                <div class="mr-kpi-label">前月の新規クチコミ</div>
                <?php if ( $rv_prev_has ) : ?>
                    <div class="mr-kpi-value">+<?php echo esc_html( number_format( $rv_delta_prev ) ); ?></div>
                    <div class="mr-kpi-prev"><?php echo esc_html( $report['previous']['label'] ); ?> に新たに投稿された件数</div>
                <?php else : ?>
                    <div class="mr-kpi-value" style="color:#94a3b8;">—</div>
                    <div class="mr-kpi-prev">クチコミデータ未取得</div>
                <?php endif; ?>
            </div>
        </div>
        <p style="font-size:11px;color:#94a3b8;margin:10px 0 0;">
            新規クチコミ数は、累計クチコミ件数の月末スナップショットの差分から算出しています。順位取得を開始する前の月は履歴データが無いため「+0」と表示されます。
        </p>
    </div>

    <!-- 順位 -->
    <div class="mr-section">
        <h2>📈 順位チェック</h2>
        <p style="font-size:12px; color:#64748b; margin:-6px 0 12px;">
            マップ順位 = Googleマップ単体の順位 / 地域順位 = 通常検索のローカルファインダー（3パック）順位。スマホ・自社中心の値を表示。
        </p>
        <?php if ( empty( $report['ranks'] ) ) : ?>
            <p style="color:#94a3b8">登録キーワードがありません。</p>
        <?php else :
            $rank_cell = static function ( $v ) {
                return $v !== null
                    ? '<span>' . esc_html( (string) $v ) . '位</span>'
                    : '<span style="color:#9ca3af">圏外</span>';
            };
            $delta_cell = static function ( $cur, $prev ) {
                if ( $cur === null || $prev === null ) {
                    return '<span class="mr-pct mr-pct-zero">—</span>';
                }
                $delta = $prev - $cur; // 順位は小さいほど良い
                if ( $delta > 0 ) return '<span class="mr-pct mr-pct-up">↑ ' . esc_html( (string) $delta ) . '位上昇</span>';
                if ( $delta < 0 ) return '<span class="mr-pct mr-pct-down">↓ ' . esc_html( (string) abs( $delta ) ) . '位下降</span>';
                return '<span class="mr-pct mr-pct-zero">→ 変動なし</span>';
            };
        ?>
            <table class="mr-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="vertical-align:middle;">キーワード</th>
                        <th colspan="3" style="text-align:center; border-bottom:1px dashed #cbd5e1;">マップ順位</th>
                        <th colspan="3" style="text-align:center; border-bottom:1px dashed #cbd5e1;">地域順位</th>
                        <th rowspan="2" style="vertical-align:middle;">取得日</th>
                    </tr>
                    <tr>
                        <th class="num">今月</th><th class="num">前月</th><th class="num">変動</th>
                        <th class="num">今月</th><th class="num">前月</th><th class="num">変動</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $report['ranks'] as $r ) :
                    $m_cur   = $r['maps_rank']        ?? $r['rank']      ?? null;
                    $m_prev  = $r['maps_rank_prev']   ?? $r['rank_prev'] ?? null;
                    $f_cur   = $r['finder_rank']      ?? null;
                    $f_prev  = $r['finder_rank_prev'] ?? null;
                ?>
                    <tr>
                        <td><?php echo esc_html( $r['keyword'] ); ?></td>
                        <td class="num"><?php echo $rank_cell( $m_cur ); ?></td>
                        <td class="num"><?php echo $rank_cell( $m_prev ); ?></td>
                        <td class="num"><?php echo $delta_cell( $m_cur, $m_prev ); ?></td>
                        <td class="num"><?php echo $rank_cell( $f_cur ); ?></td>
                        <td class="num"><?php echo $rank_cell( $f_prev ); ?></td>
                        <td class="num"><?php echo $delta_cell( $f_cur, $f_prev ); ?></td>
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
        <?php
        $kw_info = is_array( $report['keywords_info'] ?? null ) ? $report['keywords_info'] : [];
        $kw_requested = (string) ( $kw_info['requested'] ?? '' );
        $kw_served    = (string) ( $kw_info['served']    ?? '' );
        $kw_fallback  = ( $kw_info['has_any_data'] ?? false ) && ( $kw_info['found'] ?? false ) === false;
        ?>
        <?php if ( $kw_fallback && $kw_served !== '' ) : ?>
            <p style="margin:0 0 12px;padding:10px 14px;background:#fef3c7;border-left:3px solid #d97706;color:#92400e;font-size:13px;line-height:1.7;">
                ⚠️ <strong><?php echo esc_html( $kw_requested ); ?></strong> 月の GBP 検索キーワードはまだ取得できていません
                (Google Business Profile のパフォーマンスデータは通常 1 ヶ月前後の遅延があります)。
                直近で取得済みの <strong><?php echo esc_html( $kw_served ); ?></strong> 月のデータを参考として表示しています。
            </p>
        <?php endif; ?>
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
                        <td class="num">
                            <?php
                            $kw_val = (int) $k['value'];
                            if ( $kw_val === 0 ) {
                                echo '<span style="color:#94a3b8;">0 <span title="検索された回数がごく少なく、Googleが正確な数値を非公開にしているキーワードです" style="cursor:help;">ⓘ</span></span>';
                            } else {
                                echo esc_html( number_format( $kw_val ) );
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            // 0件のキーワードがリスト内に存在する場合のみ注釈を表示
            $has_zero_keyword = false;
            foreach ( $report['keywords'] as $k ) {
                if ( (int) ( $k['value'] ?? 0 ) === 0 ) { $has_zero_keyword = true; break; }
            }
            if ( $has_zero_keyword ) :
            ?>
            <div style="margin-top:14px;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;line-height:1.8;color:#475569;">
                <div style="font-weight:600;color:#0f172a;margin-bottom:6px;">💡 回数が「0」になっているキーワードについて</div>
                <p style="margin:0 0 8px;">
                    一覧の中に <strong>回数「0」</strong> と表示されているキーワードがあります。これは <strong>「誰も検索していない」という意味ではありません</strong>。
                </p>
                <p style="margin:0 0 8px;">
                    Google は、検索した方のプライバシーを守るため、検索回数がごく少ないキーワードについては正確な数字を公開しない仕組みになっています。たとえば「お店の名前+地名」のような少数の検索数しかないキーワードは、表示はされていても **正確な数値は非公開**となり、システム上は「0」として扱われます。
                </p>
                <p style="margin:0;">
                    つまり「0」と表示されているキーワードは、<strong>「実際には少数の検索でお店が見つけてもらえているが、Google が数字を伏せている」</strong>キーワードです。<br>
                    過去6ヶ月のうちに実際の検索実績があったキーワードのみを表示しているため、参考としてご活用ください。
                </p>
            </div>
            <?php endif; ?>
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

<?php
// JS で参照するレポートデータ（CSV 出力用）
$mr_csv_payload = [
    'period'        => $report['period'] ?? [],
    'metrics'       => $m,
    'metrics_prev'  => $mp,
    'reviews'       => $report['reviews']       ?? [],
    'ranks'         => $report['ranks']         ?? [],
    'keywords'      => $report['keywords']      ?? [],
    'keywords_diff' => $report['keywords_diff'] ?? [],
];
$mr_period_slug = sprintf( '%04d-%02d', (int) $year, (int) $month );
?>
<script type="application/json" id="mrReportData"><?php echo wp_json_encode( $mr_csv_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer></script>
<script>
(function() {
    'use strict';

    var PERIOD_SLUG  = <?php echo wp_json_encode( $mr_period_slug ); ?>;
    var PERIOD_LABEL = <?php echo wp_json_encode( (string) ( $report['period']['label'] ?? $mr_period_slug ) ); ?>;

    var dataEl = document.getElementById('mrReportData');
    var reportData = null;
    try { reportData = dataEl ? JSON.parse(dataEl.textContent || '{}') : null; }
    catch (e) { reportData = null; }

    /* ---------- CSV ---------- */
    function csvEscape(v) {
        if (v === null || v === undefined) return '';
        var s = String(v);
        if (s.indexOf('"') !== -1 || s.indexOf(',') !== -1 || s.indexOf('\n') !== -1 || s.indexOf('\r') !== -1) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    function fmtRank(v) {
        return (v === null || v === undefined || v === '') ? '圏外' : String(v) + '位';
    }

    function downloadCsv() {
        if (!reportData) {
            alert('レポートデータを読み込めませんでした。ページを再読み込みしてください。');
            return;
        }
        var d = reportData;
        var lines = [];
        var pushRow = function(arr) { lines.push(arr.map(csvEscape).join(',')); };
        var pushSection = function(title) {
            if (lines.length > 0) lines.push('');
            pushRow(['# ' + title]);
        };

        // 1) 期間
        pushSection('期間');
        pushRow(['対象月', PERIOD_LABEL]);

        // 2) 表示回数
        var m = d.metrics || {}, mp = d.metrics_prev || {};
        pushSection('表示回数');
        pushRow(['項目', '今月', '前月']);
        pushRow(['モバイル',       m.mobile_impressions  || 0, mp.mobile_impressions  || 0]);
        pushRow(['デスクトップ',   m.desktop_impressions || 0, mp.desktop_impressions || 0]);
        pushRow(['検索経由',       m.search_impressions  || 0, mp.search_impressions  || 0]);
        pushRow(['マップ経由',     m.map_impressions     || 0, mp.map_impressions     || 0]);

        // 3) 行動数
        pushSection('行動数');
        pushRow(['項目', '今月', '前月']);
        pushRow(['ウェブサイト', m.website_clicks   || 0, mp.website_clicks   || 0]);
        pushRow(['電話',         m.call_clicks      || 0, mp.call_clicks      || 0]);
        pushRow(['ルート',       m.direction_clicks || 0, mp.direction_clicks || 0]);

        // 4) クチコミ
        var rv = d.reviews || {};
        pushSection('クチコミ');
        pushRow(['項目', '値']);
        pushRow(['総クチコミ数', rv.count == null ? '' : rv.count]);
        pushRow(['平均評価',     rv.average_rating == null ? '' : Number(rv.average_rating).toFixed(2)]);
        pushRow(['今月の新規クチコミ', rv.delta_curr_has_baseline ? rv.delta_curr : '—']);
        pushRow(['前月の新規クチコミ', rv.delta_prev_has_baseline ? rv.delta_prev : '—']);

        // 5) 順位
        if (d.ranks && d.ranks.length) {
            pushSection('順位チェック');
            pushRow(['キーワード', 'マップ順位 今月', 'マップ順位 前月', '地域順位 今月', '地域順位 前月', '取得日']);
            d.ranks.forEach(function(r) {
                var mCur  = (r.maps_rank      !== undefined ? r.maps_rank      : (r.rank      !== undefined ? r.rank      : null));
                var mPrev = (r.maps_rank_prev !== undefined ? r.maps_rank_prev : (r.rank_prev !== undefined ? r.rank_prev : null));
                pushRow([
                    r.keyword || '',
                    fmtRank(mCur),
                    fmtRank(mPrev),
                    fmtRank(r.finder_rank      != null ? r.finder_rank      : null),
                    fmtRank(r.finder_rank_prev != null ? r.finder_rank_prev : null),
                    r.fetched_date || ''
                ]);
            });
        }

        // 6) GBP 検索キーワード TOP20
        if (d.keywords && d.keywords.length) {
            pushSection('GBP 検索キーワード TOP20');
            pushRow(['#', 'キーワード', '回数']);
            d.keywords.forEach(function(k, i) {
                pushRow([i + 1, k.keyword || '', k.value || 0]);
            });
        }

        // 7) 増加キーワード TOP10
        if (d.keywords_diff && d.keywords_diff.length) {
            pushSection('増加キーワード TOP10 (前月比)');
            pushRow(['#', 'キーワード', '前月', '今月', '増加']);
            d.keywords_diff.forEach(function(x, i) {
                pushRow([i + 1, x.keyword || '', x.value_prev || 0, x.value || 0, x.delta || 0]);
            });
        }

        var BOM = '\uFEFF';
        var blob = new Blob([BOM + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url;
        a.download = 'MEOレポート_' + PERIOD_SLUG + '.csv';
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        setTimeout(function() {
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }, 100);
    }

    /* ---------- PDF ---------- */
    function downloadPdf() {
        var el = document.querySelector('.mr-wrap');
        if (!el) return;
        if (typeof html2pdf === 'undefined') {
            alert('PDF生成ライブラリの読み込みに失敗しました。ページを再読み込みしてください。');
            return;
        }
        var toolbar = document.querySelector('.mr-toolbar');
        var pdfBtn  = document.getElementById('mrPdfBtn');
        var csvBtn  = document.getElementById('mrCsvBtn');
        var prevLabel = pdfBtn ? pdfBtn.innerHTML : '';

        if (toolbar) toolbar.style.display = 'none';
        if (pdfBtn) { pdfBtn.disabled = true; pdfBtn.innerHTML = 'PDF 生成中...'; }
        if (csvBtn) csvBtn.disabled = true;

        var opt = {
            margin:      [10, 8, 12, 8],
            filename:    'MEOレポート_' + PERIOD_SLUG + '.pdf',
            image:       { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 2, useCORS: true, scrollY: 0, backgroundColor: '#ffffff' },
            jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak:   { mode: ['avoid-all', 'css', 'legacy'] }
        };

        html2pdf().set(opt).from(el).save()
            .then(function() {
                if (toolbar) toolbar.style.display = '';
                if (pdfBtn)  { pdfBtn.disabled = false; pdfBtn.innerHTML = prevLabel; }
                if (csvBtn)  csvBtn.disabled = false;
            })
            .catch(function(err) {
                console.error('PDF generation failed', err);
                if (toolbar) toolbar.style.display = '';
                if (pdfBtn)  { pdfBtn.disabled = false; pdfBtn.innerHTML = prevLabel; }
                if (csvBtn)  csvBtn.disabled = false;
                alert('PDFの生成に失敗しました。もう一度お試しください。');
            });
    }

    var csvBtn = document.getElementById('mrCsvBtn');
    var pdfBtn = document.getElementById('mrPdfBtn');
    if (csvBtn) csvBtn.addEventListener('click', downloadCsv);
    if (pdfBtn) pdfBtn.addEventListener('click', downloadPdf);
})();
</script>

<?php get_footer(); ?>
