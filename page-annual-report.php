<?php
/*
Template Name: 年次レポート
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$user_id = get_current_user_id();

// デフォルト年: 前年
$default_year = (int) date('Y') - 1;
$current_year_param = isset($_GET['year']) ? absint($_GET['year']) : $default_year;

set_query_var('gcrev_page_title', '年次レポート');
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('年次レポート'));

get_header();
?>

<style>
/* =============================================
   page-annual-report — Page-specific styles
   ============================================= */

/* --- Year Selector --- */
.ar-year-selector {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}
.ar-year-selector label {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-secondary, #384D50);
}
.ar-year-selector select {
    padding: 8px 32px 8px 14px;
    font-size: 15px;
    font-weight: 600;
    color: var(--mw-text-primary, #263335);
    background: var(--mw-bg-primary, #FFFFFF);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: 10px;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23384D50' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    transition: border-color 0.15s;
}
.ar-year-selector select:hover {
    border-color: var(--mw-primary-blue, #568184);
}

/* --- Period Info Bar --- */
.ar-period-bar {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    padding: 14px 20px;
    background: var(--mw-bg-secondary, #F5F8F8);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: 12px;
    margin-bottom: 32px;
    font-size: 13px;
    color: var(--mw-text-secondary, #384D50);
}
.ar-period-bar strong {
    color: var(--mw-text-primary, #263335);
    font-size: 14px;
}

/* --- KPI Grid (page-site-dashboard.php と同じ定義) --- */
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

/* --- Section Titles --- */
.ar-section-title {
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
.ar-section-subtitle {
    font-size: 13px;
    font-weight: 400;
    color: var(--mw-text-tertiary, #5D6E70);
    margin: -10px 0 20px 0;
}

/* --- Chart Section --- */
.ar-chart-section {
    background: var(--mw-bg-primary, #FFFFFF);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-md, 16px);
    padding: 24px;
    margin-bottom: 32px;
}
.ar-chart-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--mw-bg-tertiary, #E6EEF0);
    padding-bottom: 0;
}
.ar-chart-tab {
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    color: var(--mw-text-tertiary, #5D6E70);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    transition: all 0.15s;
    margin-bottom: -1px;
}
.ar-chart-tab:hover {
    color: var(--mw-text-primary, #263335);
}
.ar-chart-tab.active {
    color: var(--mw-primary-blue, #568184);
    border-bottom-color: var(--mw-primary-blue, #568184);
}
.ar-chart-canvas-wrap {
    position: relative;
    height: 320px;
}
.ar-chart-canvas-wrap canvas {
    width: 100% !important;
    height: 100% !important;
}

/* --- Analysis Grid --- */
.ar-analysis-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.ar-analysis-card {
    background: var(--mw-bg-primary, #FFFFFF);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-md, 16px);
    padding: 24px;
    transition: all 0.25s ease;
    min-width: 0;
    overflow: hidden;
}
.ar-analysis-card:hover {
    box-shadow: var(--mw-shadow-float, 0 8px 24px rgba(0,0,0,0.07));
    border-color: var(--mw-border-medium, #AEBCBE);
}
.ar-analysis-card-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* --- Channel (Doughnut) Layout --- */
.ar-channel-layout {
    display: flex;
    align-items: center;
    gap: 24px;
}
.ar-channel-chart-wrap {
    flex-shrink: 0;
    width: 160px;
    height: 160px;
}
.ar-channel-list {
    flex: 1;
    list-style: none;
    padding: 0;
    margin: 0;
}
.ar-channel-list-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--mw-bg-tertiary, #E6EEF0);
    font-size: 14px;
}
.ar-channel-list-item:last-child {
    border-bottom: none;
}
.ar-channel-label {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--mw-text-primary, #263335);
    font-weight: 500;
}
.ar-channel-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
.ar-channel-value {
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
}

/* --- Ranking List --- */
.ar-ranking-items {
    list-style: none;
    padding: 0;
    margin: 0;
}
.ar-ranking-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid var(--mw-bg-tertiary, #E6EEF0);
}
.ar-ranking-item:last-child {
    border-bottom: none;
}
.ar-ranking-num {
    font-size: 14px;
    font-weight: 700;
    color: var(--mw-primary-blue, #568184);
    flex-shrink: 0;
    min-width: 24px;
    text-align: center;
}
.ar-ranking-label {
    font-size: 14px;
    color: var(--mw-text-primary, #263335);
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}
.ar-ranking-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
    flex-shrink: 0;
    text-align: right;
}

/* --- Keyword table --- */
.ar-keyword-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.ar-keyword-table thead th {
    text-align: left;
    font-weight: 600;
    color: var(--mw-text-tertiary, #5D6E70);
    padding: 8px 6px;
    border-bottom: 2px solid var(--mw-bg-tertiary, #E6EEF0);
    font-size: 12px;
    white-space: nowrap;
}
.ar-keyword-table thead th:first-child {
    width: 30px;
    text-align: center;
}
.ar-keyword-table tbody td {
    padding: 10px 6px;
    border-bottom: 1px solid var(--mw-bg-tertiary, #E6EEF0);
    color: var(--mw-text-primary, #263335);
}
.ar-keyword-table tbody tr:last-child td {
    border-bottom: none;
}
.ar-keyword-table .ar-kw-num {
    text-align: center;
    font-weight: 700;
    color: var(--mw-primary-blue, #568184);
}
.ar-keyword-table .ar-kw-query {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ar-keyword-table .ar-kw-right {
    text-align: right;
    font-weight: 600;
}

/* --- AI Summary placeholder --- */
.ar-ai-placeholder {
    background: var(--mw-bg-secondary, #F5F8F8);
    border: 1px dashed var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-md, 16px);
    padding: 32px;
    text-align: center;
    color: var(--mw-text-tertiary, #5D6E70);
    font-size: 14px;
    line-height: 1.8;
}

/* --- AI Summary content --- */
.ar-ai-content {
    background: linear-gradient(135deg, #FAFCFC 0%, #F5F8F8 100%);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-md, 16px);
    padding: 32px 36px;
    line-height: 2.0;
    color: var(--mw-text-primary, #263335);
    font-size: 14.5px;
}
.ar-ai-section {
    margin-bottom: 24px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--mw-bg-tertiary, #E6EEF0);
}
.ar-ai-section:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}
.ar-ai-heading {
    font-size: 15px;
    font-weight: 700;
    color: var(--mw-primary-blue, #568184);
    margin: 0 0 10px 0;
    padding: 6px 12px;
    background: rgba(86, 129, 132, 0.06);
    border-radius: 8px;
    border-left: 3px solid var(--mw-primary-blue, #568184);
}
.ar-ai-paragraph {
    margin: 0 0 8px 0;
    padding-left: 4px;
}
.ar-ai-paragraph:last-child {
    margin-bottom: 0;
}
/* 数値の強調 */
.ar-ai-content .ar-ai-num {
    font-weight: 700;
    color: var(--mw-primary-blue, #568184);
}
/* ポジティブ/ネガティブ */
.ar-ai-content .ar-ai-positive {
    color: #2563EB;
    font-weight: 600;
}
.ar-ai-content .ar-ai-negative {
    color: #DC2626;
    font-weight: 600;
}

/* --- KPI supplement label --- */
.kpi-supplement {
    font-size: 11px;
    color: var(--mw-text-tertiary, #5D6E70);
    font-weight: 500;
    margin-top: 2px;
}

/* --- Empty state --- */
.ar-empty {
    font-size: 14px;
    color: var(--mw-text-secondary, #384D50);
    text-align: center;
    padding: 24px 0;
}

/* --- Error state --- */
.ar-error-message {
    background: #FEF2F2;
    border: 1px solid #FECACA;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    color: #991B1B;
    font-size: 14px;
    margin-bottom: 24px;
    display: none;
}

/* --- Responsive --- */
@media (max-width: 1024px) {
    .ar-analysis-grid {
        grid-template-columns: 1fr;
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
    .ar-channel-layout {
        flex-direction: column;
    }
    .ar-chart-canvas-wrap {
        height: 240px;
    }
    .ar-chart-tabs {
        flex-wrap: wrap;
    }
    .ar-keyword-table {
        font-size: 12px;
    }
}
@media (max-width: 480px) {
    .sd-kpi-grid {
        grid-template-columns: 1fr;
    }
}

/* --- Print --- */
@media print {
    .ar-year-selector,
    .loading-overlay,
    .site-header,
    .sidebar,
    .ar-print-btn {
        display: none !important;
    }
    .ar-chart-section,
    .ar-analysis-card {
        break-inside: avoid;
    }
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">
    <!-- 印刷ボタン -->
    <div style="display:flex; justify-content:flex-end; margin-bottom:12px;">
        <button type="button" class="ar-print-btn" onclick="window.print()"
                style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border:1px solid var(--mw-border-light,#C3CED0); border-radius:8px; background:var(--mw-bg-primary,#fff); color:var(--mw-text-secondary,#384D50); font-size:13px; font-weight:600; cursor:pointer; transition:all 0.15s;"
                onmouseover="this.style.background='var(--mw-bg-secondary,#F5F8F8)'"
                onmouseout="this.style.background='var(--mw-bg-primary,#fff)'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            印刷
        </button>
    </div>

    <!-- 年選択UI -->
    <div class="ar-year-selector">
        <label for="arYearSelect">表示する年:</label>
        <select id="arYearSelect">
            <?php
            $this_year = (int) date('Y');
            for ($y = $this_year; $y >= 2020; $y--) {
                $selected = ($y === $current_year_param) ? ' selected' : '';
                echo '<option value="' . esc_attr($y) . '"' . $selected . '>' . esc_html($y) . '年</option>';
            }
            ?>
        </select>
    </div>

    <!-- 対象期間バー -->
    <div class="ar-period-bar" id="arPeriodBar">
        <span>分析対象期間: <strong id="arPeriodCurrent">-</strong></span>
        <span style="display:inline-block;width:1px;height:16px;background:var(--mw-border-light,#C3CED0);vertical-align:middle;"></span>
        <span>比較期間: <strong id="arPeriodPrev">-</strong></span>
    </div>

    <!-- エラーメッセージ -->
    <div class="ar-error-message" id="arError"></div>

    <!-- ローディングオーバーレイ -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>データを取得中...</p>
        </div>
    </div>

    <!-- KPIサマリーカード -->
    <h2 class="ar-section-title">📊 年間パフォーマンス</h2>
    <p class="ar-section-subtitle">選択した年の主要指標をまとめています。</p>
    <div class="sd-kpi-grid" id="arKpiGrid">
        <!-- JS で描画 -->
    </div>

    <!-- 月別推移グラフ -->
    <h2 class="ar-section-title">📈 月別推移</h2>
    <p class="ar-section-subtitle">各月の数値を折れ線グラフで確認できます。前年のデータと比較できます。</p>
    <div class="ar-chart-section">
        <div class="ar-chart-tabs" id="arChartTabs">
            <button type="button" class="ar-chart-tab active" data-metric="pageViews">見られた回数</button>
            <button type="button" class="ar-chart-tab" data-metric="sessions">訪問回数</button>
            <button type="button" class="ar-chart-tab" data-metric="users">ユーザー数</button>
            <button type="button" class="ar-chart-tab" data-metric="conversions">ゴール数</button>
        </div>
        <div class="ar-chart-canvas-wrap">
            <canvas id="arTrendChart"></canvas>
        </div>
    </div>

    <!-- 分析カードグリッド -->
    <h2 class="ar-section-title">📋 年間分析</h2>
    <p class="ar-section-subtitle">流入元・人気ページ・検索ワードの年間ランキングです。</p>
    <div class="ar-analysis-grid">
        <!-- 流入元の年間まとめ -->
        <div class="ar-analysis-card">
            <div class="ar-analysis-card-title">🌐 流入元の年間まとめ</div>
            <div id="arChannels">
                <div class="ar-empty">データを読み込んでいます...</div>
            </div>
        </div>

        <!-- よく見られたページ TOP10 -->
        <div class="ar-analysis-card">
            <div class="ar-analysis-card-title">📄 よく見られたページ TOP10</div>
            <div id="arPages">
                <div class="ar-empty">データを読み込んでいます...</div>
            </div>
        </div>
    </div>

    <!-- 検索ワード TOP10 -->
    <div class="ar-analysis-grid" style="grid-template-columns: 1fr;">
        <div class="ar-analysis-card">
            <div class="ar-analysis-card-title">🔍 検索ワード TOP10</div>
            <div id="arKeywords">
                <div class="ar-empty">データを読み込んでいます...</div>
            </div>
        </div>
    </div>

    <!-- 年間総括コメント -->
    <h2 class="ar-section-title">📝 年間総括</h2>
    <div id="arAiSummary">
        <div class="ar-ai-placeholder">
            <p style="font-size:20px; margin-bottom:8px;">🔮</p>
            <p>AIによる年間振り返りコメントは現在準備中です。</p>
            <p style="font-size:12px; margin-top:8px;">今後のアップデートでご利用いただけるようになります。</p>
        </div>
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
    var selectedYear = <?php echo (int) $current_year_param; ?>;

    // KPI定義
    var kpiDefs = [
        { key: 'pageViews',      monthlyKey: 'pageViews',   label: '見られた回数',       emoji: '👁️', bg: 'rgba(86,129,132,0.08)',  color: '#568184', format: 'number',   supplement: '年間合計' },
        { key: 'sessions',       monthlyKey: 'sessions',    label: '訪問回数',           emoji: '🎯', bg: 'rgba(212,168,66,0.12)', color: '#D4A842', format: 'number',   supplement: '年間合計' },
        { key: 'users',          monthlyKey: 'users',       label: '見に来た人の数',     emoji: '👥', bg: 'rgba(78,138,107,0.10)', color: '#4E8A6B', format: 'number',   supplement: '年間合計' },
        { key: 'newUsers',       monthlyKey: 'newUsers',    label: 'はじめての人の数',   emoji: '✨', bg: 'rgba(122,163,166,0.10)', color: '#7AA3A6', format: 'number',   supplement: '年間合計' },
        { key: 'returningUsers', monthlyKey: null,          label: 'また来てくれた人',   emoji: '🔄', bg: 'rgba(156,138,90,0.10)', color: '#9C8A5A', format: 'number',   supplement: '年間合計' },
        { key: 'avgDuration',    monthlyKey: 'duration',    label: '平均滞在時間',       emoji: '⏱️', bg: 'rgba(139,107,97,0.10)', color: '#8B6B61', format: 'duration', supplement: '年間平均' },
        { key: 'conversions',    monthlyKey: 'conversions', label: 'ゴール数',           emoji: '🎯', bg: 'rgba(224,123,84,0.10)', color: '#E07B54', format: 'number',   supplement: '年間合計' }
    ];

    // チャート色
    var chartColors = {
        pageViews:   { main: '#568184', light: 'rgba(86,129,132,0.1)' },
        sessions:    { main: '#D4A842', light: 'rgba(212,168,66,0.1)' },
        users:       { main: '#4E8A6B', light: 'rgba(78,138,107,0.1)' },
        conversions: { main: '#E07B54', light: 'rgba(224,123,84,0.1)' }
    };
    var channelColors = ['#568184', '#7AA3A6', '#D4A842', '#4E8A6B', '#E07B54', '#9C8A5A', '#8B6B61', '#B5574B'];

    // 月ラベル
    var monthLabels = ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];

    // Chart.js インスタンス
    var trendChart = null;
    var channelChart = null;
    var currentMetric = 'pageViews';
    var cachedData = null;

    // =============================================
    // ユーティリティ
    // =============================================
    function parseFormattedNumber(s) {
        if (typeof s === 'number') return s;
        if (typeof s !== 'string') return 0;
        return parseInt(s.replace(/,/g, ''), 10) || 0;
    }
    function formatNumber(n) {
        var num = parseFormattedNumber(n);
        if (isNaN(num)) return '-';
        return num.toLocaleString('ja-JP');
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
    function showLoading() {
        var el = document.getElementById('loadingOverlay');
        if (el) el.classList.add('active');
    }
    function hideLoading() {
        var el = document.getElementById('loadingOverlay');
        if (el) el.classList.remove('active');
    }
    function showError(msg) {
        var el = document.getElementById('arError');
        if (el) {
            el.innerHTML = escapeHtml(msg).replace(/\n/g, '<br>');
            el.style.display = 'block';
        }
        hideLoading();
    }
    function hideError() {
        var el = document.getElementById('arError');
        if (el) el.style.display = 'none';
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
    // 期間バー更新
    // =============================================
    function updatePeriodBar(data) {
        var curEl = document.getElementById('arPeriodCurrent');
        var prevEl = document.getElementById('arPeriodPrev');
        if (curEl && data.period) {
            var p = data.period;
            curEl.textContent = data.year + '年1月1日 〜 12月31日';
        }
        if (prevEl && data.prev_year) {
            prevEl.textContent = (data.year - 1) + '年';
        }
    }

    // =============================================
    // KPIカード描画
    // =============================================
    function renderKPI(data) {
        var grid = document.getElementById('arKpiGrid');
        if (!grid) return;

        var kpi = data.kpi || {};
        var trends = data.trends || {};
        var monthly = data.monthly || {};

        var html = '';
        for (var i = 0; i < kpiDefs.length; i++) {
            var def = kpiDefs[i];
            var rawVal = kpi[def.key];
            var displayVal = formatValue(rawVal, def.format);

            // トレンド
            var trend = trends[def.key] || {};
            var trendVal = parseFloat(trend.value) || 0;
            var trendText = trend.text || '±0.0%';
            var trendClass = trendVal > 0 ? 'positive' : (trendVal < 0 ? 'negative' : 'neutral');

            // スパークライン（月別データから）
            var sparkHtml = '';
            if (def.monthlyKey && monthly[def.monthlyKey]) {
                sparkHtml = renderSparkline(monthly[def.monthlyKey], def.color);
            }

            html += '<div class="kpi-card">' +
                '<div class="kpi-card-header">' +
                    '<span class="kpi-title">' + escapeHtml(def.label) + '</span>' +
                    '<div class="kpi-icon" style="background:' + def.bg + ';">' + def.emoji + '</div>' +
                '</div>' +
                '<div class="kpi-value">' + displayVal + '</div>' +
                '<div class="kpi-supplement">' + escapeHtml(def.supplement) + '</div>' +
                '<div class="kpi-change ' + trendClass + '"><span>前年比 ' + escapeHtml(trendText) + '</span></div>' +
                '<div class="kpi-sparkline">' + sparkHtml + '</div>' +
            '</div>';
        }

        grid.innerHTML = html;
    }

    // =============================================
    // 月別推移グラフ（Chart.js）
    // =============================================
    function renderTrendChart(data) {
        var canvas = document.getElementById('arTrendChart');
        if (!canvas) return;

        var monthly = data.monthly || {};
        var monthlyPrev = data.monthly_prev || {};
        var metricData = monthly[currentMetric] || [];
        var metricDataPrev = monthlyPrev[currentMetric] || [];
        var colorDef = chartColors[currentMetric] || chartColors.pageViews;

        // メトリック名マッピング
        var metricLabels = {
            pageViews: '見られた回数',
            sessions: '訪問回数',
            users: 'ユーザー数',
            conversions: 'ゴール数'
        };

        if (trendChart) {
            trendChart.destroy();
            trendChart = null;
        }

        trendChart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: monthLabels,
                datasets: [
                    {
                        label: data.year + '年 ' + (metricLabels[currentMetric] || currentMetric),
                        data: metricData,
                        borderColor: colorDef.main,
                        backgroundColor: colorDef.light,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: colorDef.main
                    },
                    {
                        label: (data.year - 1) + '年 ' + (metricLabels[currentMetric] || currentMetric),
                        data: metricDataPrev,
                        borderColor: 'rgba(150,150,150,0.5)',
                        backgroundColor: 'transparent',
                        borderWidth: 1.5,
                        borderDash: [6, 4],
                        fill: false,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: 'rgba(150,150,150,0.5)'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 16,
                            font: { size: 12 }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(38,51,53,0.9)',
                        titleFont: { size: 13 },
                        bodyFont: { size: 13 },
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(ctx) {
                                var val = ctx.parsed.y;
                                if (currentMetric === 'duration') {
                                    return ctx.dataset.label + ': ' + formatDuration(val);
                                }
                                return ctx.dataset.label + ': ' + formatNumber(val);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { size: 12 },
                            color: '#5D6E70'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(195,206,208,0.4)',
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 12 },
                            color: '#5D6E70',
                            callback: function(val) {
                                return formatNumber(val);
                            }
                        }
                    }
                }
            }
        });
    }

    // タブ切り替え
    var tabsContainer = document.getElementById('arChartTabs');
    if (tabsContainer) {
        tabsContainer.addEventListener('click', function(e) {
            var tab = e.target.closest('.ar-chart-tab');
            if (!tab) return;

            var metric = tab.getAttribute('data-metric');
            if (!metric || metric === currentMetric) return;

            currentMetric = metric;

            // アクティブ状態切り替え
            tabsContainer.querySelectorAll('.ar-chart-tab').forEach(function(t) {
                t.classList.remove('active');
            });
            tab.classList.add('active');

            // 再描画
            if (cachedData) {
                renderTrendChart(cachedData);
            }
        });
    }

    // =============================================
    // 流入元ドーナツ
    // =============================================
    // 流入元チャネル名の日本語マッピング
    var channelNameMap = {
        'Organic Search':  '自然検索（Organic Search）',
        'Direct':          '直接アクセス（Direct）',
        'Referral':        '外部サイト経由（Referral）',
        'Organic Social':  'SNS（Organic Social）',
        'Paid Search':     '検索広告（Paid Search）',
        'Display':         'ディスプレイ広告（Display）',
        'Email':           'メール（Email）',
        'Paid Social':     'SNS広告（Paid Social）',
        'Organic Video':   '動画（Organic Video）',
        'Unassigned':      'その他（Unassigned）',
        '(Other)':         'その他',
    };
    function translateChannel(name) {
        return channelNameMap[name] || name;
    }

    function renderChannels(data) {
        var container = document.getElementById('arChannels');
        if (!container) return;

        var channels = data.channels_summary || [];
        if (channels.length === 0) {
            container.innerHTML = '<div class="ar-empty">データがありません</div>';
            return;
        }

        // ドーナツチャート + リスト
        var html = '<div class="ar-channel-layout">' +
            '<div class="ar-channel-chart-wrap"><canvas id="arChannelChart"></canvas></div>' +
            '<ul class="ar-channel-list">';

        for (var i = 0; i < channels.length; i++) {
            var ch = channels[i];
            var color = channelColors[i % channelColors.length];
            var label = translateChannel(ch.channel);
            html += '<li class="ar-channel-list-item">' +
                '<span class="ar-channel-label"><span class="ar-channel-dot" style="background:' + color + ';"></span>' + escapeHtml(label) + '</span>' +
                '<span class="ar-channel-value">' + parseFloat(ch.percentage || 0).toFixed(1) + '%</span>' +
            '</li>';
        }

        html += '</ul></div>';
        container.innerHTML = html;

        // Chart.js ドーナツ
        var dCanvas = document.getElementById('arChannelChart');
        if (!dCanvas) return;

        if (channelChart) {
            channelChart.destroy();
            channelChart = null;
        }

        channelChart = new Chart(dCanvas, {
            type: 'doughnut',
            data: {
                labels: channels.map(function(c) { return translateChannel(c.channel); }),
                datasets: [{
                    data: channels.map(function(c) { return c.sessions || 0; }),
                    backgroundColor: channelColors.slice(0, channels.length),
                    borderWidth: 2,
                    borderColor: '#FFFFFF'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '60%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(38,51,53,0.9)',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(ctx) {
                                return ctx.label + ': ' + formatNumber(ctx.raw) + '回';
                            }
                        }
                    }
                }
            }
        });
    }

    // =============================================
    // ページランキング
    // =============================================
    function renderPages(data) {
        var container = document.getElementById('arPages');
        if (!container) return;

        var pages = (data.pages || []).slice(0, 10);
        if (pages.length === 0) {
            container.innerHTML = '<div class="ar-empty">データがありません</div>';
            return;
        }

        var html = '<ul class="ar-ranking-items">';
        for (var i = 0; i < pages.length; i++) {
            var p = pages[i];
            var displayTitle = p.title || p.pagePath || p.page || '-';
            var pagePath = p.pagePath || p.page || '';
            html += '<li class="ar-ranking-item">' +
                '<span class="ar-ranking-num">' + (i + 1) + '</span>' +
                '<span class="ar-ranking-label" title="' + escapeHtml(pagePath) + '">' + escapeHtml(displayTitle) + '</span>' +
                '<span class="ar-ranking-value">' + formatNumber(p.pageViews) + ' PV</span>' +
            '</li>';
        }
        html += '</ul>';
        container.innerHTML = html;
    }

    // =============================================
    // キーワードランキング
    // =============================================
    function renderKeywords(data) {
        var container = document.getElementById('arKeywords');
        if (!container) return;

        var keywords = (data.keywords || []).slice(0, 10);
        if (keywords.length === 0) {
            container.innerHTML = '<div class="ar-empty">データがありません</div>';
            return;
        }

        var html = '<table class="ar-keyword-table">' +
            '<thead><tr>' +
                '<th>#</th>' +
                '<th>検索ワード</th>' +
                '<th style="text-align:right;">クリック</th>' +
                '<th style="text-align:right;">表示回数</th>' +
                '<th style="text-align:right;">CTR</th>' +
                '<th style="text-align:right;">平均順位</th>' +
            '</tr></thead><tbody>';

        for (var i = 0; i < keywords.length; i++) {
            var kw = keywords[i];
            html += '<tr>' +
                '<td class="ar-kw-num">' + (i + 1) + '</td>' +
                '<td class="ar-kw-query">' + escapeHtml(kw.query || '-') + '</td>' +
                '<td class="ar-kw-right">' + escapeHtml(String(kw.clicks || '0')) + '</td>' +
                '<td class="ar-kw-right">' + escapeHtml(String(kw.impressions || '0')) + '</td>' +
                '<td class="ar-kw-right">' + escapeHtml(String(kw.ctr || '-')) + '</td>' +
                '<td class="ar-kw-right">' + escapeHtml(String(kw.position || '-')) + '</td>' +
            '</tr>';
        }

        html += '</tbody></table>';
        container.innerHTML = html;
    }

    // =============================================
    // AI年間総括コメント
    // =============================================
    function renderAiSummary(data) {
        var container = document.getElementById('arAiSummary');
        if (!container) return;

        var summary = data.ai_summary;
        if (!summary) {
            container.innerHTML = '<div class="ar-ai-placeholder">' +
                '<p style="font-size:20px; margin-bottom:8px;">🔮</p>' +
                '<p>AIによる年間振り返りコメントを生成できませんでした。</p>' +
                '<p style="margin-top:12px;"><button type="button" onclick="if(window._arLoadReport){this.disabled=true;this.innerHTML=\'⏳ 生成中...\';window._arLoadReport(window._arGetYear(),true);}" ' +
                'style="padding:8px 20px; border:1px solid var(--mw-border-light,#C3CED0); border-radius:8px; background:#fff; color:var(--mw-text-secondary,#384D50); font-size:13px; font-weight:600; cursor:pointer;">🔄 再生成する</button></p>' +
            '</div>';
            return;
        }

        // プレーンテキストをHTML化
        // ■見出しでセクション分割、数値を太字強調、増減を色分け
        function highlightText(text) {
            var escaped = escapeHtml(text);
            // 数値（カンマ付き含む）+ 単位を強調
            escaped = escaped.replace(/(\d[\d,]*\.?\d*)(回|%|人|件|秒|PV|ページ|倍|ポイント|円)/g,
                '<span class="ar-ai-num">$1$2</span>');
            // +XX% / -XX% を色分け
            escaped = escaped.replace(/(\+\d[\d,]*\.?\d*%)/g, '<span class="ar-ai-positive">$1</span>');
            escaped = escaped.replace(/(\-\d[\d,]*\.?\d*%)/g, '<span class="ar-ai-negative">$1</span>');
            // 前年比XX%増 / 減
            escaped = escaped.replace(/(前年比?\d[\d,]*\.?\d*%増)/g, '<span class="ar-ai-positive">$1</span>');
            escaped = escaped.replace(/(前年比?\d[\d,]*\.?\d*%減)/g, '<span class="ar-ai-negative">$1</span>');
            return escaped;
        }

        var html = '<div class="ar-ai-content">';
        var lines = summary.split('\n');
        var inSection = false;

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].trim();
            if (!line) continue;

            if (line.indexOf('■') === 0) {
                if (inSection) html += '</div>'; // 前のセクションを閉じる
                html += '<div class="ar-ai-section">';
                html += '<h4 class="ar-ai-heading">' + escapeHtml(line.replace(/^■\s*/, '')) + '</h4>';
                inSection = true;
            } else {
                html += '<p class="ar-ai-paragraph">' + highlightText(line) + '</p>';
            }
        }
        if (inSection) html += '</div>';
        html += '</div>';
        container.innerHTML = html;
    }

    // =============================================
    // データ取得 & 描画
    // =============================================
    function loadAnnualReport(year, refresh) {
        showLoading();
        hideError();

        var url = restBase + 'annual-report?year=' + year;
        if (refresh) url += '&refresh=1';

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (!json.success) {
                var code = json.code || '';
                var msg  = json.message || 'データの取得に失敗しました。';
                if (code === 'NO_GA4') {
                    msg = '⚠️ GA4プロパティが設定されていません。\n先にクライアント設定でGA4連携を完了してください。';
                } else if (code === 'FUTURE_YEAR') {
                    msg = '📅 ' + year + '年のデータはまだありません。';
                } else if (code === 'INSUFFICIENT_DATA') {
                    msg = '📊 ' + year + '年はまだ1年分のデータが揃っていないため、年次レポートを作成できません。\nデータが蓄積されるまでお待ちください。';
                }
                showError(msg);
                return;
            }

            cachedData = json.data;

            updatePeriodBar(json.data);
            renderKPI(json.data);
            renderTrendChart(json.data);
            renderChannels(json.data);
            renderPages(json.data);
            renderKeywords(json.data);
            renderAiSummary(json.data);
            hideLoading();
        })
        .catch(function(e) {
            console.error('Annual report fetch error:', e);
            showError('データの取得に失敗しました。しばらく待ってからお試しください。');
        });
    }

    // =============================================
    // 年選択
    // =============================================
    var yearSelect = document.getElementById('arYearSelect');
    if (yearSelect) {
        yearSelect.addEventListener('change', function() {
            selectedYear = parseInt(this.value, 10);
            history.replaceState(null, '', '?year=' + selectedYear);
            loadAnnualReport(selectedYear);
        });
    }

    // グローバルに公開（再生成ボタン等から呼べるようにする）
    window._arLoadReport = loadAnnualReport;
    window._arGetYear = function() { return selectedYear; };

    // 初回ロード
    loadAnnualReport(selectedYear);

})();
</script>

<?php get_footer(); ?>
