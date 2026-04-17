<?php
/*
Template Name: サイトダッシュボード
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'サイトダッシュボード');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('サイトダッシュボード', 'ホームページ'));

get_header();
?>

<style>
/* =============================================
   page-site-dashboard — Page-specific styles
   All shared styles are in css/dashboard-redesign.css
   ============================================= */

/* --- Data Coverage Notice --- */
.gcrev-notice-nodata {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 8px;
    padding: 12px 16px;
    margin: 12px 0;
    font-size: 14px;
    color: #856404;
    display: flex;
    align-items: center;
    gap: 8px;
}
.gcrev-notice-nodata .notice-icon { font-size: 16px; flex-shrink: 0; }

/* --- Section Titles --- */
.sd-section-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
    margin: 0 0 16px 0;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--mw-bg-tertiary, #E6EEF0);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* --- KPI Summary Cards Grid --- */
/* Uses shared .kpi-card styles from dashboard-redesign.css */
.sd-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 40px;
}
.sd-kpi-grid .kpi-card {
    padding: 20px 24px;
}
.sd-kpi-grid .kpi-value {
    font-size: 32px;
}
.sd-kpi-grid .kpi-sparkline {
    margin-top: 10px;
    height: 32px;
    display: flex;
    align-items: center;
}
.sd-kpi-grid .kpi-sparkline svg {
    width: 100%;
    height: 32px;
}

/* --- Analysis Cards Grid (3 columns) --- */
.sd-analysis-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.sd-analysis-card {
    background: var(--mw-bg-primary, #FFFFFF);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-md, 16px);
    padding: 24px;
    transition: all 0.25s ease;
    min-width: 0;
    overflow: hidden;
}
.sd-analysis-card:hover {
    box-shadow: var(--mw-shadow-float, 0 8px 24px rgba(0,0,0,0.07));
    border-color: var(--mw-border-medium, #AEBCBE);
}

.sd-analysis-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.sd-analysis-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
    display: flex;
    align-items: center;
    gap: 6px;
}

.sd-analysis-link {
    font-size: 13px;
    color: var(--mw-primary-blue, #568184);
    text-decoration: none;
    font-weight: 600;
    white-space: nowrap;
    transition: color 0.15s;
}
.sd-analysis-link:hover {
    text-decoration: underline;
    color: #476C6F;
}

/* --- Chart cards (top row) --- */
.sd-chart-area {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-bottom: 16px;
    min-height: 120px;
}

/* Donut chart */
.sd-donut-wrap {
    position: relative;
    width: 120px;
    height: 120px;
}
.sd-donut-wrap svg {
    width: 120px;
    height: 120px;
    transform: rotate(-90deg);
}

/* Horizontal bar chart */
.sd-hbar-chart {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.sd-hbar-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.sd-hbar-label {
    font-size: 12px;
    color: var(--mw-text-secondary, #384D50);
    width: 60px;
    text-align: right;
    flex-shrink: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sd-hbar-bar-wrap {
    flex: 1;
    height: 20px;
    background: var(--mw-bg-tertiary, #E6EEF0);
    border-radius: 4px;
    overflow: hidden;
}
.sd-hbar-bar {
    height: 100%;
    border-radius: 4px;
    transition: width 0.4s ease;
}

/* Chart card list (percentage display) */
.sd-chart-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.sd-chart-list-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--mw-bg-tertiary, #E6EEF0);
}
.sd-chart-list-item:last-child {
    border-bottom: none;
}
.sd-chart-list-label {
    font-size: 14px;
    color: var(--mw-text-primary, #263335);
    font-weight: 500;
}
.sd-chart-list-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
}

/* --- Ranking cards (bottom row) --- */
.sd-ranking-items {
    list-style: none;
    padding: 0;
    margin: 0;
}
.sd-ranking-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 0;
    border-bottom: 1px solid var(--mw-bg-tertiary, #E6EEF0);
}
.sd-ranking-item:last-child {
    border-bottom: none;
}
.sd-ranking-num {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-secondary, #384D50);
    flex-shrink: 0;
    min-width: 18px;
}
.sd-ranking-label {
    font-size: 14px;
    color: var(--mw-text-primary, #263335);
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}
.sd-ranking-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
    flex-shrink: 0;
    text-align: right;
}

.sd-analysis-empty {
    font-size: 14px;
    color: var(--mw-text-secondary, #384D50);
    text-align: center;
    padding: 20px 0;
}

/* --- Insights Section --- */
.sd-insights {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 40px;
}

.sd-insight-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    background: var(--mw-bg-primary, #FFFFFF);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-sm, 12px);
    border-left: 4px solid var(--mw-primary-teal, #7AA3A6);
}

.sd-insight-icon {
    font-size: 20px;
    flex-shrink: 0;
}

.sd-insight-text {
    font-size: 14px;
    color: var(--mw-text-primary, #263335);
    line-height: 1.6;
}

/* --- KPI Card — Selectable button reset --- */
button.sd-kpi-selectable {
    font-family: inherit;
    font-size: inherit;
    line-height: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    transition: all 0.25s ease;
}
.sd-kpi-selectable:hover {
    box-shadow: 0 8px 24px rgba(0,0,0,0.07);
    border-color: var(--mw-border-medium, #AEBCBE);
    transform: translateY(-1px);
}

/* --- KPI Card — Active/Selected state --- */
.sd-kpi-selectable.is-active {
    border-color: var(--mw-primary-blue, #568184);
    border-bottom: 3px solid var(--mw-primary-blue, #568184);
    background: rgba(86, 129, 132, 0.04);
    box-shadow: 0 1px 6px rgba(0,0,0,0.03);
}
.sd-kpi-selectable.is-active .kpi-title {
    color: var(--mw-primary-blue, #568184);
}
.sd-kpi-selectable.is-active .sd-kpi-hint {
    color: var(--mw-primary-blue, #568184);
}

/* --- KPI Card — Hint text --- */
.sd-kpi-hint {
    display: block;
    font-size: 11px;
    color: #aaa;
    margin-top: 6px;
    transition: color 0.2s ease;
}

/* --- KPI Card — Focus visible (accessibility) --- */
.sd-kpi-selectable:focus-visible {
    outline: 2px solid var(--mw-primary-blue, #568184);
    outline-offset: 2px;
}

/* --- Trend Chart Section --- */
.sd-trend-chart-wrap {
    background: var(--mw-bg-primary, #FFFFFF);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-md, 16px);
    padding: 24px;
    margin-bottom: 40px;
}
.sd-trend-chart-header {
    margin-bottom: 20px;
}
.sd-trend-chart-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
}

/* --- Responsive --- */
@media (max-width: 1024px) {
    .sd-kpi-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .sd-analysis-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 768px) {
    .sd-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .sd-kpi-grid .kpi-value {
        font-size: 26px;
    }
    .sd-analysis-grid {
        grid-template-columns: 1fr;
    }
    .sd-analysis-card {
        padding: 20px;
    }
}
@media (max-width: 480px) {
    .sd-kpi-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">
    <!-- 印刷ボタン -->
    <div style="display:flex; justify-content:flex-end; margin-bottom:12px;">
        <button type="button" onclick="window.print()"
                style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border:1px solid var(--mw-border-light,#C3CED0); border-radius:8px; background:var(--mw-bg-primary,#fff); color:var(--mw-text-secondary,#384D50); font-size:13px; font-weight:600; cursor:pointer; transition:all 0.15s;"
                onmouseover="this.style.background='var(--mw-bg-secondary,#F5F8F8)'"
                onmouseout="this.style.background='var(--mw-bg-primary,#fff)'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            印刷
        </button>
    </div>

    <!-- ローディングオーバーレイ -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>データを取得中...</p>
        </div>
    </div>

    <!-- 期間セレクター -->
<?php
set_query_var('gcrev_period_selector', [
    'id' => 'sd-period',
    'items' => [
        ['value' => 'last30',          'label' => '直近30日'],
        ['value' => 'prev-month',      'label' => '前月'],
        ['value' => 'prev-prev-month', 'label' => '前々月'],
        ['value' => 'last90',          'label' => '過去90日'],
        ['value' => 'last180',         'label' => '過去半年'],
        ['value' => 'last365',         'label' => '過去1年'],
    ],
    'default' => 'last30',
]);
get_template_part('template-parts/period-selector');
?>

    <!-- 期間表示 -->
    <div class="period-display" id="periodDisplay">
        分析対象期間を選択してください
    </div>

    <!-- データ不足通知 -->
    <div id="dataNotice" class="gcrev-notice-nodata" style="display:none;">
        <span class="notice-icon">ℹ️</span>
        <span id="dataNoticeText"></span>
    </div>

    <!-- KPIサマリーカード -->
    <h2 class="sd-section-title">📊 主要指標</h2>
    <div class="sd-kpi-grid" id="sdKpiGrid">
        <!-- JS で描画 -->
    </div>

    <!-- KPIトレンドチャート（カード選択に連動） -->
    <div class="sd-trend-chart-wrap" id="sdTrendChartWrap">
        <div class="sd-trend-chart-header">
            <div class="sd-trend-chart-title" id="sdTrendChartTitle">📈 見られた回数の推移</div>
        </div>
        <div style="height: 280px;">
            <canvas id="sdTrendChart"></canvas>
        </div>
    </div>

    <!-- 分析カード -->
    <h2 class="sd-section-title">📋 分析サマリー</h2>
    <div class="sd-analysis-grid" id="sdAnalysisGrid">
        <!-- JS で描画 -->
    </div>

    <!-- 気づきエリア -->
    <h2 class="sd-section-title" id="sdInsightsTitle" style="display:none;">💡 気づき</h2>
    <div class="sd-insights" id="sdInsights">
        <!-- JS で描画 -->
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function() {
    'use strict';

    // =============================================
    // 設定
    // =============================================
    var restBase = <?php echo wp_json_encode(esc_url_raw(rest_url('gcrev/v1/'))); ?>;
    var nonce    = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
    var homeUrl  = <?php echo wp_json_encode(esc_url(home_url('/'))); ?>;

    // KPI定義（月次レポートと同じ用語・説明）
    var kpiDefs = [
        { key: 'pageViews',      dailyKey: 'pageViews',   trendKey: 'pageViews',      label: '見られた回数',         term: 'ページビュー',   tip: 'ホームページの各ページが何回見られたかの合計です。同じ人が何ページも見ると、その分だけ数が増えます。', emoji: '👁️', bg: 'rgba(86,129,132,0.08)',  color: '#568184', format: 'number' },
        { key: 'sessions',       dailyKey: 'sessions',    trendKey: 'sessions',       label: '訪問回数',             term: 'セッション',     tip: 'ホームページに誰かが来た回数です。1人が朝と夜に来たら「2回」とカウントされます。', emoji: '🎯', bg: 'rgba(212,168,66,0.12)', color: '#D4A842', format: 'number' },
        { key: 'users',          dailyKey: 'users',       trendKey: 'users',          label: '見に来た人の数',       term: 'ユーザー',       tip: 'ホームページを見に来た人数です。同じ人が何回来ても「1人」としてカウントされます。', emoji: '👥', bg: 'rgba(78,138,107,0.10)', color: '#4E8A6B', format: 'number' },
        { key: 'newUsers',       dailyKey: 'newUsers',    trendKey: 'newUsers',       label: 'はじめての人の数',     term: '新規ユーザー',   tip: 'この期間にはじめてホームページを訪れた人の数です。新しいお客様候補がどれだけ増えたかがわかります。', emoji: '✨', bg: 'rgba(122,163,166,0.10)', color: '#7AA3A6', format: 'number' },
        { key: 'returningUsers', dailyKey: 'returning',   trendKey: 'returningUsers', label: 'また来てくれた人',     term: 'リピーター',     tip: '以前にもホームページを見たことがある人の数です。多いほど「また見たい」と思われている証拠です。', emoji: '🔁', bg: 'rgba(181,87,75,0.08)',   color: '#B5574B', format: 'number' },
        { key: 'avgDuration',    dailyKey: 'duration',    trendKey: 'avgDuration',    label: 'しっかり見られた時間', term: '平均滞在時間',   tip: '訪問者がホームページに滞在した平均時間です。長いほど内容に興味を持って読んでもらえています。', emoji: '⏱️', bg: 'rgba(212,168,66,0.15)', color: '#C9A84C', format: 'duration' },
        { key: 'conversions',    dailyKey: 'conversions', trendKey: 'conversions',    label: 'ゴール数',             term: 'コンバージョン', tip: 'お問い合わせや申込みなど、ホームページの目標が達成された回数です。この数が増えると、ホームページが成果につながっています。', emoji: '🎉', bg: 'rgba(78,138,107,0.10)', color: '#4E8A6B', format: 'number' },
    ];

    // デバイス名マッピング
    var deviceNameMap = { mobile: 'モバイル', desktop: 'デスクトップ', tablet: 'タブレット' };

    // 流入元名マッピング
    var mediumNameMap = { organic: '自然検索', direct: '直接', referral: '参照元', social: 'SNS', cpc: '広告', email: 'メール', '(none)': '直接', '(not set)': '(not set)' };

    // 地域名マッピング（GA4 region → 日本語都道府県）
    var regionNameMap = {
        'Tokyo': '東京都', 'Osaka': '大阪府', 'Kanagawa': '神奈川県', 'Aichi': '愛知県',
        'Saitama': '埼玉県', 'Chiba': '千葉県', 'Hokkaido': '北海道', 'Fukuoka': '福岡県',
        'Hyogo': '兵庫県', 'Kyoto': '京都府', 'Shizuoka': '静岡県', 'Hiroshima': '広島県',
        'Miyagi': '宮城県', 'Niigata': '新潟県', 'Nagano': '長野県', 'Gifu': '岐阜県',
        'Gunma': '群馬県', 'Tochigi': '栃木県', 'Ibaraki': '茨城県', 'Okayama': '岡山県',
        'Mie': '三重県', 'Kumamoto': '熊本県', 'Kagoshima': '鹿児島県', 'Okinawa': '沖縄県',
        'Ehime': '愛媛県', 'Nara': '奈良県', 'Shiga': '滋賀県', 'Wakayama': '和歌山県',
        'Nagasaki': '長崎県', 'Oita': '大分県', 'Ishikawa': '石川県', 'Yamaguchi': '山口県',
        'Toyama': '富山県', 'Fukui': '福井県', 'Saga': '佐賀県', 'Miyazaki': '宮崎県',
        'Kochi': '高知県', 'Tokushima': '徳島県', 'Kagawa': '香川県', 'Yamanashi': '山梨県',
        'Fukushima': '福島県', 'Iwate': '岩手県', 'Akita': '秋田県', 'Aomori': '青森県',
        'Yamagata': '山形県', 'Tottori': '鳥取県', 'Shimane': '島根県'
    };

    // ドーナツチャートの色
    var donutColors = ['#3B6FB8', '#2E9960', '#E0A020'];

    // 横棒チャートの色
    var hbarColors = ['#3B6FB8', '#2E9960', '#E0A020', '#D4574E', '#7B5FB0'];

    // =============================================
    // ユーティリティ
    // =============================================
    function formatNumber(n) {
        var num = parseFormattedNumber(n);
        if (isNaN(num)) return '-';
        return num.toLocaleString('ja-JP');
    }

    function parseFormattedNumber(s) {
        if (typeof s === 'number') return s;
        if (typeof s !== 'string') return 0;
        return parseInt(s.replace(/,/g, ''), 10) || 0;
    }

    function formatDuration(sec) {
        var s = parseFloat(sec);
        if (isNaN(s) || s < 0) return '-';
        var m = Math.floor(s / 60);
        var ss = Math.floor(s % 60);
        return m + '分' + (ss < 10 ? '0' : '') + ss + '秒';
    }

    function formatValue(val, fmt) {
        if (fmt === 'duration') return formatDuration(val);
        return formatNumber(val);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function hexToRgba(hex, alpha) {
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
    }

    function showLoading() {
        var el = document.getElementById('loadingOverlay');
        if (el) el.classList.add('active');
    }
    function hideLoading() {
        var el = document.getElementById('loadingOverlay');
        if (el) el.classList.remove('active');
    }

    function mapName(name, map) {
        if (!map) return name;
        return map[name] || name;
    }

    // =============================================
    // SVG スパークライン
    // =============================================
    function renderSparkline(values, color) {
        if (!values || values.length < 2) return '';
        var w = 120, h = 28, pad = 2;
        var max = Math.max.apply(null, values);
        var min = Math.min.apply(null, values);
        var range = max - min || 1;
        var points = [];
        for (var i = 0; i < values.length; i++) {
            var x = pad + (i / (values.length - 1)) * (w - 2 * pad);
            var y = h - pad - ((values[i] - min) / range) * (h - 2 * pad);
            points.push(x.toFixed(1) + ',' + y.toFixed(1));
        }
        return '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none">' +
            '<polyline points="' + points.join(' ') + '" fill="none" stroke="' + escapeHtml(color) + '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>' +
            '</svg>';
    }

    // =============================================
    // SVG ドーナツチャート
    // =============================================
    function renderDonutChart(items, colors) {
        var total = 0;
        for (var i = 0; i < items.length; i++) total += items[i].value;
        if (total === 0) return '<div class="sd-analysis-empty">データがありません</div>';

        var size = 120, cx = 60, cy = 60, r = 45, sw = 22;
        var circumference = 2 * Math.PI * r;
        var svg = '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">';

        // 背景円
        svg += '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="none" stroke="#E6EEF0" stroke-width="' + sw + '"/>';

        var offset = 0;
        for (var i = 0; i < items.length; i++) {
            var pct = items[i].value / total;
            var dash = pct * circumference;
            var gap = circumference - dash;
            var color = colors[i % colors.length];
            svg += '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="none" ' +
                'stroke="' + color + '" stroke-width="' + sw + '" ' +
                'stroke-dasharray="' + dash.toFixed(2) + ' ' + gap.toFixed(2) + '" ' +
                'stroke-dashoffset="' + (-offset).toFixed(2) + '" ' +
                'stroke-linecap="butt"/>';
            offset += dash;
        }

        svg += '</svg>';
        return svg;
    }

    // =============================================
    // 横棒チャート
    // =============================================
    function renderHBarChart(items, colors) {
        if (items.length === 0) return '<div class="sd-analysis-empty">データがありません</div>';

        var maxVal = 0;
        for (var i = 0; i < items.length; i++) {
            if (items[i].value > maxVal) maxVal = items[i].value;
        }

        var html = '<div class="sd-hbar-chart">';
        for (var i = 0; i < items.length; i++) {
            var pct = maxVal > 0 ? (items[i].value / maxVal * 100) : 0;
            var color = colors[i % colors.length];
            html += '<div class="sd-hbar-row">' +
                '<span class="sd-hbar-label">' + escapeHtml(items[i].label) + '</span>' +
                '<div class="sd-hbar-bar-wrap"><div class="sd-hbar-bar" style="width:' + pct.toFixed(1) + '%;background:' + color + ';"></div></div>' +
            '</div>';
        }
        html += '</div>';
        return html;
    }

    // ?ボタンSVG（月次レポートと同じ）
    var infoBtnSvg = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 6.2a1.8 1.8 0 0 1 3.4.8c0 1.2-1.9 1.4-1.9 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="12" r="0.7" fill="currentColor"/></svg>';

    // =============================================
    // KPI カード描画（月次レポートと同じ構造 + クリックでグラフ切替）
    // =============================================
    var selectedKpi = kpiDefs[0].key; // デフォルト: 見られた回数

    function renderKpiCards(data) {
        var grid = document.getElementById('sdKpiGrid');
        if (!grid) return;

        var html = '';
        var daily  = data.daily  || {};
        var trends = data.trends || {};

        for (var i = 0; i < kpiDefs.length; i++) {
            var def = kpiDefs[i];
            var rawVal = data[def.key];
            var displayVal = formatValue(rawVal, def.format);

            // トレンド
            var trend = trends[def.trendKey] || {};
            var trendVal = parseFloat(trend.value) || 0;
            var trendText = trend.text || '±0.0%';
            var trendClass = trendVal > 0 ? 'positive' : (trendVal < 0 ? 'negative' : 'neutral');

            // スパークライン
            var sparkData = daily[def.dailyKey];
            var sparkHtml = '';
            if (sparkData && sparkData.values && sparkData.values.length >= 2) {
                sparkHtml = renderSparkline(sparkData.values, def.color);
            }

            var isActive = (def.key === selectedKpi);

            // 月次レポートと同じ .kpi-card 構造 + クリック可能
            html += '<button type="button" class="kpi-card sd-kpi-selectable' + (isActive ? ' is-active' : '') + '" data-kpi-key="' + escapeHtml(def.key) + '" aria-pressed="' + (isActive ? 'true' : 'false') + '">' +
                '<div class="kpi-card-header">' +
                    '<span class="kpi-title">' + escapeHtml(def.label) +
                        ' <span class="kpi-info-btn-wrap" role="button" tabindex="0" aria-label="説明を表示">' + infoBtnSvg + '</span>' +
                        '<span class="kpi-term">（' + escapeHtml(def.term) + '）</span>' +
                    '</span>' +
                    '<div class="kpi-icon" style="background:' + def.bg + ';">' + def.emoji + '</div>' +
                '</div>' +
                '<div class="kpi-info-tip">' + escapeHtml(def.tip) + '</div>' +
                '<div class="kpi-value">' + displayVal + '</div>' +
                '<div class="kpi-change ' + trendClass + '"><span>' + escapeHtml(trendText) + '</span></div>' +
                '<div class="kpi-sparkline">' + sparkHtml + '</div>' +
                '<span class="sd-kpi-hint">クリックでグラフ切替</span>' +
            '</button>';
        }

        grid.innerHTML = html;

        // ?ボタンのクリックイベント（月次レポートと同じ挙動）
        grid.querySelectorAll('.kpi-info-btn-wrap').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var card = btn.closest('.kpi-card');
                if (card) card.classList.toggle('info-open');
            });
        });

        // カード選択イベント
        grid.querySelectorAll('.sd-kpi-selectable').forEach(function(card) {
            card.addEventListener('click', function(e) {
                // ?ボタンクリック時はグラフ切替しない
                if (e.target.closest('.kpi-info-btn-wrap')) return;
                var key = card.dataset.kpiKey;
                if (!key || key === selectedKpi) return;
                selectedKpi = key;
                // アクティブ状態を更新
                grid.querySelectorAll('.sd-kpi-selectable').forEach(function(c) {
                    var isActive = (c.dataset.kpiKey === key);
                    c.classList.toggle('is-active', isActive);
                    c.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
                // グラフ再描画
                if (currentData) renderTrendChart(currentData);
            });
        });
    }

    // =============================================
    // 分析カード描画
    // =============================================
    function renderAnalysisCards(data) {
        var grid = document.getElementById('sdAnalysisGrid');
        if (!grid) return;

        var html = '';

        // --- 上段: チャート付きカード (デバイス、年齢、流入元) ---
        html += renderDeviceCard(data);
        html += renderAgeCard(data);
        html += renderMediumCard(data);

        // --- 下段: ランキングカード (地域、ページ、キーワード) ---
        html += renderRankingCard(data, {
            title: '見ている人の場所',
            emoji: '📍',
            dataKey: 'geo_region',
            labelField: 'name',
            rawValueField: 'sessions',
            nameMap: regionNameMap,
            top: 5,
            link: '/analysis/analysis-region/',
            suffix: ''
        });
        html += renderRankingCard(data, {
            title: 'よく見られているページ',
            emoji: '📄',
            dataKey: 'pages',
            labelField: 'title',
            rawValueField: 'pageViews',
            nameMap: null,
            top: 5,
            link: '/analysis/analysis-pages/',
            suffix: ''
        });
        html += renderRankingCard(data, {
            title: 'どんな言葉で探された？',
            emoji: '🔑',
            dataKey: 'keywords',
            labelField: 'query',
            rawValueField: 'clicks',
            nameMap: null,
            top: 5,
            link: '/analysis/analysis-keywords/',
            suffix: ''
        });

        grid.innerHTML = html;
    }

    // --- デバイス別アクセス (ドーナツチャート + パーセント表示) ---
    function renderDeviceCard(data) {
        var devices = data.devices || [];
        var html = '<div class="sd-analysis-card">' +
            '<div class="sd-analysis-header">' +
                '<div class="sd-analysis-title">📱 スマホとパソコンの割合</div>' +
                '<a href="' + escapeHtml(homeUrl + 'analysis/analysis-device/') + '" class="sd-analysis-link">詳細を見る →</a>' +
            '</div>';

        if (devices.length === 0) {
            html += '<div class="sd-analysis-empty">データがありません</div>';
        } else {
            // デバイスデータ: {device, count, percent}
            var items = [];
            for (var i = 0; i < devices.length; i++) {
                var d = devices[i];
                var name = mapName(d.device || d.name || '', deviceNameMap);
                var count = parseFormattedNumber(d.count || d.sessions || d.value || 0);
                var pctStr = d.percent || '';
                items.push({ label: name, value: count, percent: pctStr });
            }

            // ドーナツチャート
            html += '<div class="sd-chart-area">' + renderDonutChart(items, donutColors) + '</div>';

            // パーセントリスト
            html += '<ul class="sd-chart-list">';
            for (var i = 0; i < items.length; i++) {
                html += '<li class="sd-chart-list-item">' +
                    '<span class="sd-chart-list-label">' + escapeHtml(items[i].label) + '</span>' +
                    '<span class="sd-chart-list-value">' + escapeHtml(items[i].percent) + '</span>' +
                '</li>';
            }
            html += '</ul>';
        }

        html += '</div>';
        return html;
    }

    // --- 年齢別アクセス (横棒チャート + パーセント表示) ---
    function renderAgeCard(data) {
        var ages = data.age || [];
        var html = '<div class="sd-analysis-card">' +
            '<div class="sd-analysis-header">' +
                '<div class="sd-analysis-title">👤 見ている人の年代</div>' +
                '<a href="' + escapeHtml(homeUrl + 'analysis/analysis-age/') + '" class="sd-analysis-link">詳細を見る →</a>' +
            '</div>';

        if (ages.length === 0) {
            html += '<div class="sd-analysis-empty">データがありません</div>';
        } else {
            // 年齢データ: {name, sessions (formatted), percentage (numeric)}
            var topAges = ages.slice(0, 3);
            var items = [];
            var totalSess = 0;
            for (var i = 0; i < ages.length; i++) {
                totalSess += parseFormattedNumber(ages[i].sessions);
            }
            for (var i = 0; i < topAges.length; i++) {
                var a = topAges[i];
                var sess = parseFormattedNumber(a.sessions);
                var pct = a.percentage !== undefined ? parseFloat(a.percentage) : (totalSess > 0 ? (sess / totalSess * 100) : 0);
                items.push({ label: a.name || '-', value: sess, percent: pct.toFixed(1) + '%' });
            }

            // 横棒チャート
            html += '<div class="sd-chart-area">' + renderHBarChart(items, hbarColors) + '</div>';

            // パーセントリスト
            html += '<ul class="sd-chart-list">';
            for (var i = 0; i < items.length; i++) {
                html += '<li class="sd-chart-list-item">' +
                    '<span class="sd-chart-list-label">' + escapeHtml(items[i].label) + '</span>' +
                    '<span class="sd-chart-list-value">' + escapeHtml(items[i].percent) + '</span>' +
                '</li>';
            }
            html += '</ul>';
        }

        html += '</div>';
        return html;
    }

    // --- 見つけたきっかけ (横棒チャート + パーセント表示) ---
    function renderMediumCard(data) {
        var mediums = data.medium || [];
        var html = '<div class="sd-analysis-card">' +
            '<div class="sd-analysis-header">' +
                '<div class="sd-analysis-title">🌐 見つけたきっかけ</div>' +
                '<a href="' + escapeHtml(homeUrl + 'analysis/analysis-source/') + '" class="sd-analysis-link">詳細を見る →</a>' +
            '</div>';

        if (mediums.length === 0) {
            html += '<div class="sd-analysis-empty">データがありません</div>';
        } else {
            // 流入元データ: {medium, sessions (formatted), conversions, cvr}
            var topMediums = mediums.slice(0, 3);
            var items = [];
            var totalSess = 0;
            for (var i = 0; i < mediums.length; i++) {
                totalSess += parseFormattedNumber(mediums[i].sessions);
            }
            for (var i = 0; i < topMediums.length; i++) {
                var m = topMediums[i];
                var name = mapName(m.medium || m.name || '', mediumNameMap);
                var sess = parseFormattedNumber(m.sessions);
                var pct = totalSess > 0 ? (sess / totalSess * 100) : 0;
                items.push({ label: name, value: sess, percent: pct.toFixed(1) + '%' });
            }

            // 横棒チャート
            html += '<div class="sd-chart-area">' + renderHBarChart(items, hbarColors) + '</div>';

            // パーセントリスト
            html += '<ul class="sd-chart-list">';
            for (var i = 0; i < items.length; i++) {
                html += '<li class="sd-chart-list-item">' +
                    '<span class="sd-chart-list-label">' + escapeHtml(items[i].label) + '</span>' +
                    '<span class="sd-chart-list-value">' + escapeHtml(items[i].percent) + '</span>' +
                '</li>';
            }
            html += '</ul>';
        }

        html += '</div>';
        return html;
    }

    // --- ランキングカード (番号付きリスト + 数値) ---
    function renderRankingCard(data, def) {
        var items = data[def.dataKey] || [];
        var topItems = items.slice(0, def.top);

        var html = '<div class="sd-analysis-card">' +
            '<div class="sd-analysis-header">' +
                '<div class="sd-analysis-title">' + def.emoji + ' ' + escapeHtml(def.title) + ' TOP' + def.top + '</div>' +
                '<a href="' + escapeHtml(homeUrl + def.link.replace(/^\//, '')) + '" class="sd-analysis-link">詳細を見る →</a>' +
            '</div>';

        if (topItems.length === 0) {
            html += '<div class="sd-analysis-empty">データがありません</div>';
        } else {
            html += '<ul class="sd-ranking-items">';
            for (var i = 0; i < topItems.length; i++) {
                var item = topItems[i];
                var label = item[def.labelField] || '-';
                var rawVal = item[def.rawValueField] || item['_' + def.rawValueField] || '0';
                var val = parseFormattedNumber(rawVal);

                // 名前マッピング
                if (def.nameMap) {
                    label = mapName(label, def.nameMap);
                }

                html += '<li class="sd-ranking-item">' +
                    '<span class="sd-ranking-num">' + (i + 1) + '.</span>' +
                    '<span class="sd-ranking-label">' + escapeHtml(label) + '</span>' +
                    '<span class="sd-ranking-value">' + formatNumber(val) + '</span>' +
                '</li>';
            }
            html += '</ul>';
        }

        html += '</div>';
        return html;
    }

    // =============================================
    // 気づきエリア描画
    // =============================================
    function renderInsights(data) {
        var container = document.getElementById('sdInsights');
        var titleEl = document.getElementById('sdInsightsTitle');
        if (!container) return;

        var insights = generateInsights(data);
        if (insights.length === 0) {
            container.innerHTML = '';
            if (titleEl) titleEl.style.display = 'none';
            return;
        }

        if (titleEl) titleEl.style.display = '';

        var html = '';
        for (var i = 0; i < insights.length; i++) {
            html += '<div class="sd-insight-item">' +
                '<span class="sd-insight-icon">' + insights[i].icon + '</span>' +
                '<span class="sd-insight-text">' + escapeHtml(insights[i].text) + '</span>' +
            '</div>';
        }
        container.innerHTML = html;
    }

    function generateInsights(data) {
        var insights = [];
        var trends = data.trends || {};

        // 1. アクセス増減
        var sessTrend = trends.sessions;
        if (sessTrend) {
            var sv = parseFloat(sessTrend.value) || 0;
            if (sv > 10) {
                insights.push({ icon: '📈', text: 'アクセス数が前の期間より' + sessTrend.text + '増えています。好調です！' });
            } else if (sv < -10) {
                insights.push({ icon: '📉', text: 'アクセス数が前の期間より' + Math.abs(sv).toFixed(1) + '%減少しています。原因を確認してみましょう。' });
            }
        }

        // 2. デバイス比率
        var devices = data.devices || [];
        if (devices.length > 0) {
            var topDev = devices[0];
            var pctStr = (topDev.percent || '').replace('%', '');
            var pctNum = parseFloat(pctStr) || 0;
            if (pctNum >= 55) {
                var devLabel = mapName(topDev.device || topDev.name || '', deviceNameMap);
                insights.push({ icon: '📱', text: devLabel + 'からのアクセスが' + pctNum.toFixed(0) + '%を占めています。' + devLabel + '向けの表示を特に意識しましょう。' });
            }
        }

        // 3. 新規/リピーター比率
        var users = parseFloat(data.users) || 0;
        var newUsers = parseFloat(data.newUsers) || 0;
        if (users > 0) {
            var newRatio = (newUsers / users * 100).toFixed(0);
            if (parseInt(newRatio, 10) >= 65) {
                insights.push({ icon: '🆕', text: '訪問者の' + newRatio + '%が新しいお客さまです。新規のお客さまに見つけてもらえています。' });
            } else if (parseInt(newRatio, 10) <= 30) {
                insights.push({ icon: '🔄', text: 'リピーターの方が多く訪問しています。常連のお客さまに支持されています。' });
            }
        }

        // 4. トップキーワード
        var keywords = data.keywords || [];
        if (keywords.length > 0 && keywords[0].query) {
            insights.push({ icon: '🔍', text: '「' + keywords[0].query + '」が最も検索されているキーワードです。' });
        }

        // 5. ゴール増減
        var cvTrend = trends.conversions;
        if (cvTrend) {
            var cv = parseFloat(cvTrend.value) || 0;
            if (cv > 15) {
                insights.push({ icon: '🎯', text: 'ゴール数が前の期間より' + cvTrend.text + '増加しました。成果につながっています！' });
            } else if (cv < -15) {
                insights.push({ icon: '⚠️', text: 'ゴール数が前の期間より' + Math.abs(cv).toFixed(1) + '%減少しています。導線の見直しを検討しましょう。' });
            }
        }

        return insights.slice(0, 4);
    }

    // =============================================
    // KPIトレンドチャート（カード選択に連動）
    // =============================================
    var trendChart = null;
    var currentData = null;

    function renderTrendChart(data) {
        var daily = data.daily || {};
        var def = null;
        for (var i = 0; i < kpiDefs.length; i++) {
            if (kpiDefs[i].key === selectedKpi) { def = kpiDefs[i]; break; }
        }
        if (!def) return;

        // タイトル更新
        var titleEl = document.getElementById('sdTrendChartTitle');
        if (titleEl) titleEl.textContent = '📈 ' + def.label + 'の推移';

        var ctx = document.getElementById('sdTrendChart');
        if (!ctx) return;

        var sparkData = daily[def.dailyKey];
        if (!sparkData || !sparkData.values || sparkData.values.length === 0) {
            if (trendChart) trendChart.destroy();
            trendChart = null;
            return;
        }

        var labels = sparkData.labels || [];
        var values = sparkData.values || [];

        // ラベルを短くする (2026-03-01 → 1日)
        var shortLabels = labels.map(function(l) {
            if (l === null || l === undefined) return '';
            var s = String(l);
            var parts = s.split('-');
            if (parts.length === 3) return parseInt(parts[2]) + '日';
            return s;
        });

        if (trendChart) trendChart.destroy();

        var isDuration = (def.format === 'duration');
        var yConfig = { beginAtZero: true, ticks: { precision: 0 } };
        if (isDuration) {
            yConfig.ticks = {
                callback: function(value) {
                    var m = Math.floor(value / 60);
                    var s = Math.floor(value % 60);
                    return m + ':' + (s < 10 ? '0' : '') + s;
                }
            };
        }

        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: shortLabels,
                datasets: [{
                    label: def.label,
                    data: values,
                    borderColor: def.color,
                    backgroundColor: hexToRgba(def.color, 0.12),
                    fill: true,
                    tension: 0.3,
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(ctx) {
                                var raw = labels[ctx[0].dataIndex];
                                if (raw) {
                                    var p = String(raw).split('-');
                                    if (p.length === 3) return parseInt(p[1]) + '月' + parseInt(p[2]) + '日';
                                }
                                return raw;
                            },
                            label: isDuration ? function(context) {
                                return def.label + ': ' + formatDuration(context.parsed.y);
                            } : undefined
                        }
                    }
                },
                scales: { y: yConfig }
            }
        });
    }

    // =============================================
    // 期間表示更新
    // =============================================
    function updatePeriodDisplay(data) {
        var el = document.getElementById('periodDisplay');
        if (!el || !data) return;

        var cur = data.current_period || {};
        var cmp = data.comparison_period || {};

        var curText = cur.display || '';
        var cmpText = cmp.display || '';

        if (cur.start && cur.end) {
            curText = cur.start + ' 〜 ' + cur.end;
        }
        if (cmp.start && cmp.end) {
            cmpText = cmp.start + ' 〜 ' + cmp.end;
        }

        el.innerHTML =
            '<span style="font-size:13px;font-weight:600;color:var(--mw-text-secondary);">📅 分析対象期間：</span>' +
            '<span style="font-size:14px;font-weight:600;color:var(--mw-text-primary);">' + escapeHtml(curText) + '</span>' +
            '<span style="display:inline-block;width:1px;height:16px;background:var(--mw-border-light);margin:0 12px;vertical-align:middle;"></span>' +
            '<span style="font-size:13px;font-weight:600;color:var(--mw-text-secondary);">📊 比較期間：</span>' +
            '<span style="font-size:14px;font-weight:600;color:var(--mw-text-primary);">' + escapeHtml(cmpText) + '</span>';
    }

    // =============================================
    // データ不足通知
    // =============================================
    function checkDataNotice(data) {
        var notice = document.getElementById('dataNotice');
        var text = document.getElementById('dataNoticeText');
        if (!notice || !text) return;
        var start = data.actual_data_start;
        if (start) {
            text.textContent = 'GA4のデータは ' + start.replace(/-/g, '/') + ' からのみ利用可能です。それ以前のデータは存在しないため、短い期間と同じ数値が表示されることがあります。';
            notice.style.display = '';
        } else {
            notice.style.display = 'none';
        }
    }

    // =============================================
    // データ取得 & 描画
    // =============================================
    function loadData(period) {
        var cacheKey = 'sd_kpi_' + period;

        // キャッシュがあれば即座に描画（ローディングなし）
        var cached = window.gcrevCache && window.gcrevCache.get(cacheKey);
        if (cached) {
            currentData = cached;
            updatePeriodDisplay(cached);
            checkDataNotice(cached);
            renderKpiCards(cached);
            renderTrendChart(cached);
            renderAnalysisCards(cached);
            renderInsights(cached);
            return;
        }

        showLoading();

        fetch(restBase + 'dashboard/kpi?period=' + encodeURIComponent(period), {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                alert('データの取得に失敗しました: ' + (res.message || '不明なエラー'));
                return;
            }
            var data = res.data || res;
            currentData = data;

            // キャッシュに保存
            if (window.gcrevCache) window.gcrevCache.set(cacheKey, data);

            updatePeriodDisplay(data);
            checkDataNotice(data);
            renderKpiCards(data);
            renderTrendChart(data);
            renderAnalysisCards(data);
            renderInsights(data);
        })
        .catch(function(err) {
            console.error('Site Dashboard fetch error:', err);
            alert('データの取得に失敗しました。しばらく待ってからお試しください。');
        })
        .finally(function() {
            hideLoading();
        });
    }

    // =============================================
    // 期間セレクター連携
    // =============================================
    var selectorEl = document.getElementById('sd-period');
    if (selectorEl) {
        selectorEl.addEventListener('gcrev:periodChange', function(e) {
            var detail = e.detail || {};
            if (detail.period) {
                loadData(detail.period);
            }
        });
    }

    // 初期読み込み（period-selector.js の emit(initial) が初回発火するため、
    // selectorEl がある場合はそちらに任せる。ない場合のみ直接ロード）
    if (!selectorEl) {
        loadData('last30');
    }

})();
</script>

<?php get_footer(); ?>
