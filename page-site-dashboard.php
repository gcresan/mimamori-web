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
.sd-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 40px;
}

.sd-kpi-card {
    background: var(--mw-bg-primary, #FFFFFF);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-md, 16px);
    padding: 20px 24px;
    transition: all 0.25s ease;
    position: relative;
}
.sd-kpi-card:hover {
    box-shadow: var(--mw-shadow-float, 0 8px 24px rgba(0,0,0,0.07));
    border-color: var(--mw-border-medium, #AEBCBE);
    transform: translateY(-1px);
}

.sd-kpi-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.sd-kpi-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}

.sd-kpi-label {
    font-size: 13px;
    color: var(--mw-text-secondary, #384D50);
    font-weight: 600;
    letter-spacing: 0.03em;
    line-height: 1.4;
}

.sd-kpi-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
    letter-spacing: -0.02em;
    line-height: 1.2;
    margin-bottom: 6px;
}

.sd-kpi-change {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 6px;
}
.sd-kpi-change.positive {
    color: #4E8A6B;
    background: rgba(82, 140, 90, 0.12);
}
.sd-kpi-change.negative {
    color: #C95A4F;
    background: rgba(201, 90, 79, 0.10);
}
.sd-kpi-change.neutral {
    color: #666;
    background: rgba(102, 102, 102, 0.08);
}

.sd-kpi-sparkline {
    margin-top: 10px;
    height: 32px;
    display: flex;
    align-items: center;
}
.sd-kpi-sparkline svg {
    width: 100%;
    height: 32px;
}

/* --- Analysis Cards Grid --- */
.sd-analysis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.sd-analysis-card {
    background: var(--mw-bg-primary, #FFFFFF);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-md, 16px);
    padding: 24px;
    transition: all 0.25s ease;
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

.sd-analysis-items {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sd-analysis-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 9px 0;
    border-bottom: 1px solid var(--mw-bg-tertiary, #E6EEF0);
}
.sd-analysis-item:last-child {
    border-bottom: none;
}

.sd-analysis-rank {
    font-size: 12px;
    font-weight: 700;
    color: var(--mw-text-secondary, #384D50);
    width: 20px;
    text-align: center;
    flex-shrink: 0;
}

.sd-analysis-item-label {
    font-size: 14px;
    color: var(--mw-text-primary, #263335);
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}

.sd-analysis-item-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-heading, #1A2F33);
    flex-shrink: 0;
    min-width: 50px;
    text-align: right;
}

.sd-analysis-bar-wrap {
    width: 60px;
    flex-shrink: 0;
}
.sd-analysis-bar {
    height: 4px;
    background: var(--mw-bg-tertiary, #E6EEF0);
    border-radius: 2px;
    overflow: hidden;
}
.sd-analysis-bar-fill {
    height: 100%;
    border-radius: 2px;
    background: var(--mw-primary-teal, #7AA3A6);
    transition: width 0.4s ease;
}

.sd-analysis-empty {
    font-size: 14px;
    color: var(--mw-text-secondary, #384D50);
    text-align: center;
    padding: 20px 0;
}

/* CV card specific */
.sd-cv-summary {
    display: flex;
    align-items: baseline;
    gap: 12px;
    margin-bottom: 12px;
}
.sd-cv-total {
    font-size: 28px;
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
}
.sd-cv-unit {
    font-size: 14px;
    color: var(--mw-text-secondary, #384D50);
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

/* --- Responsive --- */
@media (max-width: 1024px) {
    .sd-kpi-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .sd-analysis-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 768px) {
    .sd-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .sd-kpi-value {
        font-size: 26px;
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
    'default' => 'prev-month',
]);
get_template_part('template-parts/period-selector');
?>

    <!-- 期間表示 -->
    <div class="period-display" id="periodDisplay">
        分析対象期間を選択してください
    </div>

    <!-- KPIサマリーカード -->
    <h2 class="sd-section-title">📊 主要指標</h2>
    <div class="sd-kpi-grid" id="sdKpiGrid">
        <!-- JS で描画 -->
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

<script>
(function() {
    'use strict';

    // =============================================
    // 設定
    // =============================================
    var restBase = <?php echo wp_json_encode(esc_url_raw(rest_url('gcrev/v1/'))); ?>;
    var nonce    = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
    var homeUrl  = <?php echo wp_json_encode(esc_url(home_url('/'))); ?>;

    // KPI定義
    var kpiDefs = [
        { key: 'pageViews',      dailyKey: 'pageViews',   trendKey: 'pageViews',      label: '見られた回数',         emoji: '👀', bg: 'rgba(86,129,132,0.10)',  color: '#568184', format: 'number' },
        { key: 'sessions',       dailyKey: 'sessions',    trendKey: 'sessions',       label: '訪問数',               emoji: '🚶', bg: 'rgba(78,138,107,0.10)',  color: '#4E8A6B', format: 'number' },
        { key: 'users',          dailyKey: 'users',       trendKey: 'users',          label: '見に来た人の数',       emoji: '👥', bg: 'rgba(122,163,166,0.12)', color: '#7AA3A6', format: 'number' },
        { key: 'newUsers',       dailyKey: 'newUsers',    trendKey: 'newUsers',       label: 'はじめての人の数',     emoji: '🆕', bg: 'rgba(59,130,246,0.10)',  color: '#3B82F6', format: 'number' },
        { key: 'returningUsers', dailyKey: 'returning',   trendKey: 'returningUsers', label: 'まえ来てくれた人',     emoji: '🔄', bg: 'rgba(168,139,91,0.12)', color: '#A68B5B', format: 'number' },
        { key: 'avgDuration',    dailyKey: 'duration',    trendKey: 'avgDuration',    label: 'しっかり見られた時間', emoji: '⏱️', bg: 'rgba(201,168,76,0.10)',  color: '#C9A84C', format: 'duration' },
        { key: 'conversions',    dailyKey: 'conversions', trendKey: 'conversions',    label: 'ゴール数',             emoji: '🎯', bg: 'rgba(78,138,107,0.12)', color: '#4E8A6B', format: 'number' },
    ];

    // 分析カード定義
    var analysisDefs = [
        { key: 'devices',    title: 'スマホとパソコンの割合', emoji: '📱', dataKey: 'devices',    labelField: 'name',  valueField: 'sessions', top: 3, link: '/analysis/analysis-device/',   nameMap: { mobile: 'スマホ', desktop: 'パソコン', tablet: 'タブレット' } },
        { key: 'age',        title: '見ている人の年代',       emoji: '👤', dataKey: 'age',        labelField: 'range', valueField: 'sessions', top: 5, link: '/analysis/analysis-age/',      nameMap: null },
        { key: 'medium',     title: '見つけたきっかけ',       emoji: '🔍', dataKey: 'medium',     labelField: 'name',  valueField: 'sessions', top: 5, link: '/analysis/analysis-source/',   nameMap: { organic: '自然検索', direct: 'ダイレクト', referral: '他サイト', social: 'SNS', cpc: '広告', email: 'メール', '(none)': 'ダイレクト' } },
        { key: 'geo_region', title: '見ている人の場所',       emoji: '📍', dataKey: 'geo_region', labelField: 'name',  valueField: 'sessions', top: 5, link: '/analysis/analysis-region/',   nameMap: null },
        { key: 'pages',      title: 'よく見られているページ', emoji: '📄', dataKey: 'pages',      labelField: 'path',  valueField: 'sessions', top: 5, link: '/analysis/analysis-pages/',    nameMap: null },
        { key: 'keywords',   title: 'どんな言葉で探された？', emoji: '🔑', dataKey: 'keywords',   labelField: 'query', valueField: 'clicks',   top: 5, link: '/analysis/analysis-keywords/', nameMap: null },
    ];

    // =============================================
    // ユーティリティ
    // =============================================
    function formatNumber(n) {
        var num = parseInt(n, 10);
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
    // KPI カード描画
    // =============================================
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

            html += '<div class="sd-kpi-card">' +
                '<div class="sd-kpi-header">' +
                    '<div class="sd-kpi-label">' + escapeHtml(def.label) + '</div>' +
                    '<div class="sd-kpi-icon" style="background:' + def.bg + ';">' + def.emoji + '</div>' +
                '</div>' +
                '<div class="sd-kpi-value">' + displayVal + '</div>' +
                '<div class="sd-kpi-change ' + trendClass + '">' + escapeHtml(trendText) + '</div>' +
                (sparkHtml ? '<div class="sd-kpi-sparkline">' + sparkHtml + '</div>' : '') +
            '</div>';
        }

        grid.innerHTML = html;
    }

    // =============================================
    // 分析カード描画
    // =============================================
    function renderAnalysisCards(data) {
        var grid = document.getElementById('sdAnalysisGrid');
        if (!grid) return;

        var html = '';

        // 通常の分析カード
        for (var i = 0; i < analysisDefs.length; i++) {
            var def = analysisDefs[i];
            var items = data[def.dataKey] || [];
            var topItems = items.slice(0, def.top);

            html += '<div class="sd-analysis-card">' +
                '<div class="sd-analysis-header">' +
                    '<div class="sd-analysis-title">' + def.emoji + ' ' + escapeHtml(def.title) + '</div>' +
                    '<a href="' + escapeHtml(homeUrl + def.link.replace(/^\//, '')) + '" class="sd-analysis-link">詳細を見る →</a>' +
                '</div>';

            if (topItems.length === 0) {
                html += '<div class="sd-analysis-empty">データがありません</div>';
            } else {
                var maxVal = 0;
                for (var j = 0; j < topItems.length; j++) {
                    var v = parseFloat(topItems[j][def.valueField]) || 0;
                    if (v > maxVal) maxVal = v;
                }

                html += '<ul class="sd-analysis-items">';
                for (var j = 0; j < topItems.length; j++) {
                    var item = topItems[j];
                    var label = item[def.labelField] || '-';
                    var val = parseFloat(item[def.valueField]) || 0;
                    var pct = maxVal > 0 ? (val / maxVal * 100) : 0;

                    // 名前マッピング
                    if (def.nameMap && def.nameMap[label]) {
                        label = def.nameMap[label];
                    }

                    html += '<li class="sd-analysis-item">' +
                        '<span class="sd-analysis-rank">' + (j + 1) + '</span>' +
                        '<span class="sd-analysis-item-label">' + escapeHtml(label) + '</span>' +
                        '<span class="sd-analysis-item-value">' + formatNumber(val) + '</span>' +
                        '<span class="sd-analysis-bar-wrap"><div class="sd-analysis-bar"><div class="sd-analysis-bar-fill" style="width:' + pct.toFixed(1) + '%;"></div></div></span>' +
                    '</li>';
                }
                html += '</ul>';
            }

            html += '</div>';
        }

        // ゴール分析カード（特別扱い）
        html += renderCvCard(data);

        grid.innerHTML = html;
    }

    function renderCvCard(data) {
        var cvTotal = parseInt(data.conversions, 10) || 0;
        var trends = data.trends || {};
        var cvTrend = trends.conversions || {};
        var trendVal = parseFloat(cvTrend.value) || 0;
        var trendText = cvTrend.text || '±0.0%';
        var trendClass = trendVal > 0 ? 'positive' : (trendVal < 0 ? 'negative' : 'neutral');

        // effective_cv があればそれを優先
        var effCv = data.effective_cv;
        if (effCv && effCv.total !== undefined) {
            cvTotal = parseInt(effCv.total, 10) || 0;
        }

        var html = '<div class="sd-analysis-card">' +
            '<div class="sd-analysis-header">' +
                '<div class="sd-analysis-title">🎯 ゴール分析</div>' +
                '<a href="' + escapeHtml(homeUrl + 'analysis/analysis-cv/') + '" class="sd-analysis-link">詳細を見る →</a>' +
            '</div>' +
            '<div class="sd-cv-summary">' +
                '<span class="sd-cv-total">' + formatNumber(cvTotal) + '</span>' +
                '<span class="sd-cv-unit">件</span>' +
                '<span class="sd-kpi-change ' + trendClass + '">' + escapeHtml(trendText) + '</span>' +
            '</div>';

        // CV内訳（effective_cv.components がある場合）
        if (effCv && effCv.components) {
            var comps = effCv.components;
            html += '<ul class="sd-analysis-items">';
            if (comps.ga4_total !== undefined) {
                html += '<li class="sd-analysis-item">' +
                    '<span class="sd-analysis-rank">-</span>' +
                    '<span class="sd-analysis-item-label">サイト経由（GA4）</span>' +
                    '<span class="sd-analysis-item-value">' + formatNumber(comps.ga4_total) + '</span>' +
                    '<span class="sd-analysis-bar-wrap"></span>' +
                '</li>';
            }
            if (comps.manual_total !== undefined && comps.manual_total > 0) {
                html += '<li class="sd-analysis-item">' +
                    '<span class="sd-analysis-rank">-</span>' +
                    '<span class="sd-analysis-item-label">手動登録（電話等）</span>' +
                    '<span class="sd-analysis-item-value">' + formatNumber(comps.manual_total) + '</span>' +
                    '<span class="sd-analysis-bar-wrap"></span>' +
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
            var total = 0;
            for (var d = 0; d < devices.length; d++) total += (parseFloat(devices[d].sessions) || 0);
            if (total > 0) {
                var topDev = devices[0];
                var share = ((parseFloat(topDev.sessions) || 0) / total * 100).toFixed(0);
                var devLabel = topDev.name === 'mobile' ? 'スマホ' : (topDev.name === 'desktop' ? 'パソコン' : topDev.name);
                if (parseInt(share, 10) >= 55) {
                    insights.push({ icon: '📱', text: devLabel + 'からのアクセスが' + share + '%を占めています。' + devLabel + '向けの表示を特に意識しましょう。' });
                }
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
    // データ取得 & 描画
    // =============================================
    function loadData(period) {
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

            updatePeriodDisplay(data);
            renderKpiCards(data);
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
    document.addEventListener('gcrev:periodChange', function(e) {
        var detail = e.detail || {};
        if (detail.selectorId === 'sd-period') {
            loadData(detail.period);
        }
    });

    // 初期読み込み
    loadData('prev-month');

})();
</script>

<?php get_footer(); ?>
