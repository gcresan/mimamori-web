<?php
/*
Template Name: 流入元分析
*/


if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = mimamori_get_view_user_id();

// ページタイトル設定
set_query_var('gcrev_page_title', '見つけたきっかけ');
set_query_var('gcrev_page_subtitle', '検索・Googleマップ・SNSなど、どこから見つけられたかが分かります。');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('見つけたきっかけ', 'ホームページ'));

get_header();
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
/* page-analysis-source — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */
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
// 期間セレクター（共通モジュール）
set_query_var('gcrev_period_selector', [
  'id' => 'source-period',
  'items' => [
    ['value' => 'last30',     'label' => '直近30日'],
    ['value' => 'prev-month',      'label' => '前月'],
    ['value' => 'prev-prev-month', 'label' => '前々月'],
    ['value' => 'last90',          'label' => '過去90日'],
    ['value' => 'last180',    'label' => '過去半年'],
    ['value' => 'last365',    'label' => '過去1年'],
  ],
  'default' => 'last30',
]);

get_template_part('template-parts/period-selector');

?>
    <!-- 期間表示 -->
    <div class="period-display" id="periodDisplay">
        分析対象期間を選択してください
    </div>
    <div id="dataNotice" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;margin:12px 0;font-size:14px;color:#856404;">
        <span style="margin-right:6px;">ℹ️</span><span id="dataNoticeText"></span>
    </div>

<!-- このページの見方（初心者向け） -->
<?php
set_query_var('analysis_help_key', 'source');
get_template_part('template-parts/analysis-help');
?>


    <!-- サマリーカード -->
    <div class="summary-cards" id="summaryCards">
        <!-- カードは動的に生成 -->
    </div>

    <!-- 流入元別推移 -->
    <div class="chart-section">
        <div class="chart-header">
            <h3 class="chart-title">📊 見つけたきっかけ別の推移</h3>

        </div>
        <div class="chart-container">
            <canvas id="trendChart"></canvas>
        </div>
    </div>

    <!-- 流入元シェア円グラフ -->
    <div class="chart-section">
        <div class="chart-header">
            <h3 class="chart-title">🥧 見つけたきっかけの割合</h3>

        </div>
        <div class="chart-container">
            <canvas id="shareChart"></canvas>
        </div>
    </div>

    <!-- 参照元 TOP10 -->
    <div class="chart-section">
        <div class="chart-header">
            <h3 class="chart-title">🔗 参照元 TOP10</h3>
            <div class="chart-actions">
                <button class="chart-btn" onclick="exportTableData()">📥 CSVエクスポート</button>
            </div>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>参照元</th>
                    <th>セッション</th>
                    <th>PV</th>
                    <th>平均滞在時間</th>
                    <th>直帰率</th>
                    <th>ゴール数</th>
                    <th>達成率</th>
                </tr>
            </thead>
            <tbody id="detailTableBody">
                <tr><td colspan="7" style="text-align: center; padding: 24px; color: #888888;">データを読み込み中...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
// ===== グローバル変数 =====
let currentPeriod = 'last30';
let currentData = null;
let trendChart = null;
let shareChart = null;

/**
 * 表示名の日本語化（英語は併記してズレ防止）
 * - key は GA4 のチャネル名（API から返ってくる英語）
 */
const CHANNEL_I18N = {
    'Direct': {
        ja: '直接',
        desc: 'ブックマークやURL直接入力など、すでにホームページを知っている人の訪問です。'
    },
    'Organic Search': {
        ja: '検索（自然）',
        desc: 'GoogleやYahooの検索結果からの訪問です。SEOの成果が反映されます。'
    },
    'Referral': {
        ja: '他サイト',
        desc: '他のWebサイトや紹介記事のリンクからの訪問です。'
    },
    'Organic Social': {
        ja: 'SNS',
        desc: 'InstagramやXなどの通常投稿からの訪問です。'
    },
    'Paid Social': {
        ja: 'SNS広告',
        desc: 'Instagram広告・Facebook広告などからの訪問です。'
    },
    'Paid Search': {
        ja: '検索（広告）',
        desc: 'Google広告など、検索結果に表示される広告からの訪問です。'
    },
    'Display': {
        ja: 'ディスプレイ広告',
        desc: 'Webサイトやアプリ上の画像広告（バナー広告）からの訪問です。'
    },
    'Email': {
        ja: 'メール',
        desc: 'メールマガジンやメール内リンクからの訪問です。'
    },
    'Unassigned': {
        ja: '不明',
        desc: 'どの経路か判定できないアクセスです。'
    },
    'Cross-network': {
        ja: 'クロスネットワーク',
        desc: '自動配信型の広告など、複数のネットワークを横断した訪問です。'
    },
    'Organic Shopping': {
        ja: 'ショッピング',
        desc: 'Googleショッピングの無料掲載枠などからの訪問です。'
    },
    'Organic Maps': {
        ja: '地図検索',
        desc: 'Googleマップなど地図アプリからの訪問です。'
    },
    'Affiliates': {
        ja: 'アフィリエイト',
        desc: 'アフィリエイト（成果報酬型広告）経由の訪問です。'
    }
};

function channelJa(en) {
    return (CHANNEL_I18N[en] && CHANNEL_I18N[en].ja) ? CHANNEL_I18N[en].ja : en;
}
function channelTip(en) {
    const item = CHANNEL_I18N[en];
    if (!item) return `${en}`;
    return `${item.ja}（${en}）\n${item.desc}`;
}

// ===== 初期化 =====
// ===== 初期化 =====
const SELECTOR_ID = 'source-period';


document.addEventListener('DOMContentLoaded', function () {
    // period-selector のUI初期化
    if (window.GCREV && typeof window.GCREV.initPeriodSelectors === 'function') {
        window.GCREV.initPeriodSelectors();
    }

    // 直近30日で開く
    currentPeriod = 'last30';

    // 初回データ読み込み
    loadSourceData(currentPeriod, SELECTOR_ID);

    // 期間変更イベント（period-selector.js が発火）
// period-selector.js は selector要素に対してイベントを dispatch するため、
// document ではなく selector要素に listener を付ける（bubbles=false 対応）
const selectorEl = document.getElementById(SELECTOR_ID);

const onPeriodChange = function (e) {
    const detail = e && e.detail ? e.detail : {};
    const period = detail.period;

    if (!period || period === currentPeriod) return;

    currentPeriod = period;
    loadSourceData(period, SELECTOR_ID);
};

if (selectorEl) {
    selectorEl.addEventListener('gcrev:periodChange', onPeriodChange);
} else {
    // 予備：万が一 selector が見つからない場合（レイアウト崩れ等）
    document.addEventListener('gcrev:periodChange', function (e) {
        const detail = e && e.detail ? e.detail : {};
        const period = detail.period;
        const selectorId = detail.selectorId;
        if (selectorId !== SELECTOR_ID) return;
        onPeriodChange(e);
    });
}
});

function checkDataNotice(data) {
    var notice = document.getElementById('dataNotice');
    var text = document.getElementById('dataNoticeText');
    if (!notice || !text) return;
    var start = data && data.actual_data_start;
    if (start) {
        text.textContent = 'GA4のデータは ' + start.replace(/-/g, '/') + ' からのみ利用可能です。それ以前のデータは存在しないため、短い期間と同じ数値が表示されることがあります。';
        notice.style.display = '';
    } else {
        notice.style.display = 'none';
    }
}

/**
 * 流入元データ読み込み
 */
async function loadSourceData(period, selectorId, isRetry) {
    // キャッシュチェック（ローディングなしで即表示）
    var cacheKey = 'an_source_' + period;
    var cached = window.gcrevCache && window.gcrevCache.get(cacheKey);
    if (cached) {
        currentPeriod = period;
        currentData = cached.data;
        updatePeriodDisplay(cached._fullResult);
        checkDataNotice(currentData);
        updatePeriodRangeFromData(cached._fullResult, selectorId);
        updateSummaryCards(currentData);
        updateTrendChart(currentData);
        updateShareChart(currentData);
        updateDetailTable(currentData);
        return;
    }

    showLoading();

    try {
        // 他のページと同じ /dashboard/kpi を使用
        const apiUrl = '<?php echo rest_url("gcrev/v1/dashboard/kpi"); ?>?period=' + encodeURIComponent(period);
        const response = await fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
            },
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        // デバッグ: APIレスポンス全体を確認
        console.log('[GCREV Source] API Response:', result);
        console.log('[GCREV Source] current_period:', result.current_period);
        console.log('[GCREV Source] comparison_period:', result.comparison_period);
        console.log('[GCREV Source] data.current_period:', result.data?.current_period);
        
        if (!result.success) {
            throw new Error(result.message || 'データの取得に失敗しました');
        }
        
        // /dashboard/kpi のレスポンスから流入元データを取得
        currentData = result.data;

        // キャッシュに保存（period表示用にfullResultも保持）
        if (window.gcrevCache) window.gcrevCache.set(cacheKey, { data: currentData, _fullResult: result });

        // 期間表示・レンジ表示更新
        console.log('[GCREV Source] Calling updatePeriodDisplay with:', result);
        updatePeriodDisplay(result);
        checkDataNotice(currentData);
        updatePeriodRangeFromData(result, selectorId);

        // UI更新（データ構造は /dashboard/kpi のまま使える）
        updateSummaryCards(currentData);
        updateTrendChart(currentData);
        updateShareChart(currentData);
        updateDetailTable(currentData);
        
    } catch (error) {
        console.error('Error loading source data:', error);
        alert('データの読み込みに失敗しました: ' + error.message);
    } finally {
        hideLoading();
    }
}

/**
 * period-selector 内のアクティブボタンを切り替え（フォールバック時の整合用）
 */

/**
 * サマリーカード更新
 */
function updateSummaryCards(data) {
    console.log('[GCREV Source] updateSummaryCards called with:', data);
    console.log('[GCREV Source] channels_summary:', data.channels_summary);
    
    const { channels_summary = [] } = data;
    const container = document.getElementById('summaryCards');
    
    if (channels_summary.length === 0) {
        console.warn('[GCREV Source] No channels_summary data');
        container.innerHTML = '<p style="grid-column: 1/-1; text-align: center; padding: 24px; color: #888888;">データがありません</p>';
        return;
    }
    
    console.log('[GCREV Source] Rendering', channels_summary.length, 'channels');
    
    // チャネルアイコンとカラー設定
    const channelConfig = {
        'Direct': { icon: '🔗', bg: 'rgba(59,111,184,0.12)', color: '#3B6FB8' },
        'Organic Search': { icon: '🔍', bg: 'rgba(46,153,96,0.12)', color: '#2E9960' },
        'Referral': { icon: '🌐', bg: 'rgba(224,160,32,0.14)', color: '#E0A020' },
        'Paid Search': { icon: '💰', bg: 'rgba(212,87,78,0.10)', color: '#D4574E' },
        'Social': { icon: '📱', bg: 'rgba(123,95,176,0.12)', color: '#7B5FB0' },
        'Organic Social': { icon: '📱', bg: 'rgba(123,95,176,0.12)', color: '#7B5FB0' },
        'Email': { icon: '✉️', bg: 'rgba(23,167,155,0.12)', color: '#17A79B' },
        'Display': { icon: '🖼️', bg: 'rgba(214,107,154,0.12)', color: '#D66B9A' },
    };
    
    const cards = channels_summary.map(channel => {
        const config = channelConfig[channel.channel] || { icon: '📊', bg: '#f3f4f6', color: '#888888' };
        const changeClass = channel.change_percent > 0 ? 'positive' : channel.change_percent < 0 ? 'negative' : 'neutral';
        const changeSymbol = channel.change_percent > 0 ? '↑' : channel.change_percent < 0 ? '↓' : '→';
        
        return `
<div class="summary-card">
  <div class="summary-card-header">
    <div>
      <div class="summary-card-title">
        <span class="channel-title">
          <span class="ja-label">${escapeHtml(channelJa(channel.channel))}</span>
          <span class="en-label">${escapeHtml(channel.channel)}</span>
          <span class="help-icon" data-tip="${escapeHtml(channelTip(channel.channel))}">?</span>
        </span>
      </div>



      <div class="summary-card-value">${formatNumber(channel.sessions)}</div>
      <div class="summary-card-change ${changeClass}">
        <span>${changeSymbol}</span>
        <span>${formatPercent(Math.abs(channel.change_percent))}</span>
      </div>
    </div>
    <div class="summary-card-icon" style="background: ${config.bg}; color: ${config.color};">${config.icon}</div>
  </div>
  <div class="summary-card-detail">全体の <strong style="color: #333333;">${formatPercent(channel.share)}</strong></div>
</div>

        `;
    }).join('');
    
    container.innerHTML = cards;
}

/**
 * 推移チャート更新
 */
function updateTrendChart(data) {
    const { channels_daily_series = {} } = data;
    const { labels = [], datasets = [] } = channels_daily_series;
    
    const ctx = document.getElementById('trendChart');
    
    // 既存チャート破棄
    if (trendChart) {
        trendChart.destroy();
    }
    
    // チャネル別色設定
    const channelColors = {
        'Direct': '#3B6FB8',
        'Organic Search': '#2E9960',
        'Referral': '#E0A020',
        'Paid Search': '#D4574E',
        'Social': '#7B5FB0',
        'Organic Social': '#7B5FB0',
        'Paid Social': '#9B4DCA',
        'Email': '#17A79B',
        'Display': '#D66B9A',
        'Unassigned': '#6B7280',
        'Cross-network': '#F97316',
        'Organic Shopping': '#0EA5E9',
        'Organic Maps': '#84CC16',
        'Affiliates': '#A16207',
    };
    
    
const chartDatasets = datasets.map(ds => {
    const en = ds.label;
    const color = channelColors[en] || '#888888';
    return {
        label: channelJa(en),
        _enLabel: en,
        data: ds.data,
        borderColor: color,
        backgroundColor: color + '20',
        tension: 0.4,
        fill: true
    };
});

    
    trendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: chartDatasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        boxWidth: 12,
                        padding: 15,
                        font: { size: 13, weight: '600' }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            const en = context.dataset._enLabel || context.dataset.label;
                            return `${context.dataset.label}（${en}）：${formatNumber(context.parsed.y)}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        maxTicksLimit: 10,
                        font: { size: 11 }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: '#f3f4f6' },
                    ticks: {
                        font: { size: 11 },
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    }
                }
            },
            interaction: {
                mode: 'index',
                intersect: false
            }
        }
    });
}

/**
 * シェア円グラフ更新
 */
function updateShareChart(data) {
    const { channels_summary = [] } = data;
    
    const labels = [];
    const enLabels = [];
    const sessions = [];
    const colors = [];
    const channelColorMap = {
        'Direct': '#3B6FB8',
        'Organic Search': '#2E9960',
        'Referral': '#E0A020',
        'Paid Search': '#D4574E',
        'Social': '#7B5FB0',
        'Organic Social': '#7B5FB0',
        'Paid Social': '#9B4DCA',
        'Email': '#17A79B',
        'Display': '#D66B9A',
        'Unassigned': '#6B7280',
        'Cross-network': '#F97316',
        'Organic Shopping': '#0EA5E9',
        'Organic Maps': '#84CC16',
        'Affiliates': '#A16207',
    };

    channels_summary.forEach(channel => {
        labels.push(channelJa(channel.channel));
        enLabels.push(channel.channel);
        sessions.push(channel.sessions || 0);
        colors.push(channelColorMap[channel.channel] || '#888888');
    });
    
    const ctx = document.getElementById('shareChart');
    
    // 既存チャート破棄
    if (shareChart) {
        shareChart.destroy();
    }
    
    shareChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: sessions,
                backgroundColor: colors,
                borderColor: '#fff',
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 15,
                        padding: 15,
                        font: { size: 14, weight: '600' }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const value = context.parsed;
                            const percent = total > 0 ? (value / total * 100).toFixed(1) : 0;
                            const idx = context.dataIndex;
                            const en = (typeof enLabels !== 'undefined' && enLabels[idx]) ? enLabels[idx] : '';
                            const name = en ? `${context.label}（${en}）` : context.label;
                            return name + '：' + formatNumber(value) + ' (' + percent + '%)';
                        }
                    }
                }
            }
        }
    });
}

/**
 * 詳細テーブル更新
 */
function updateDetailTable(data) {
    const { sources_detail = [] } = data;
    const tbody = document.getElementById('detailTableBody');
    
    if (sources_detail.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 24px; color: #888888;">データがありません</td></tr>';
        return;
    }
    
    const rows = sources_detail.map(source => {
        const badge = getSourceBadge(source.source, source.medium);
        
        return `
            <tr>
                <td>${badge}</td>
                <td>${formatNumber(source.sessions)}</td>
                <td>${formatNumber(source.pageViews)}</td>
                <td>${formatDuration(source.avgDuration)}</td>
                <td>${formatPercent(source.bounceRate)}</td>
                <td>${formatNumber(source.conversions)}</td>
                <td>${formatPercent(source.cvr)}</td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = rows;
}

// ===== ユーティリティ関数 =====

/**
 * ソースバッジ取得
 */
function getSourceBadge(source, medium) {
    source = source || '(not set)';
    medium = medium || '(not set)';
    
    let icon = '🔗';
    let displayText = source;
    
    // アイコン判定
    if (source === '(direct)' || source === '(not set)') {
        icon = '🔗';
        displayText = '(direct)';
    } else if (source.includes('google')) {
        icon = medium === 'cpc' ? '💰' : '🔍';
        displayText = medium !== 'organic' && medium !== '(none)' ? `${source} / ${medium}` : source;
    } else if (source.includes('yahoo')) {
        icon = medium === 'cpc' ? '💰' : '🔍';
        displayText = medium !== 'organic' && medium !== '(none)' ? `${source} / ${medium}` : source;
    } else if (source.includes('facebook') || source.includes('twitter') || source.includes('instagram') || source.includes('linkedin')) {
        icon = '📱';
    } else if (medium === 'cpc' || medium === 'ppc') {
        icon = '💰';
        displayText = `${source} / ${medium}`;
    } else if (medium === 'referral') {
        icon = '🌐';
    } else if (medium === 'email') {
        icon = '✉️';
    }
    
    return `<span class="source-badge">${icon} ${escapeHtml(displayText)}</span>`;
}

/**
 * 数値フォーマット（カンマ区切り）
 */
function formatNumber(num) {
    if (num === null || num === undefined) return '-';
    return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * パーセント表記
 */
function formatPercent(num) {
    if (num === null || num === undefined) return '-';
    return num.toFixed(1) + '%';
}

/**
 * 秒数を mm:ss 形式に変換
 */
function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) return '-';
    const minutes = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

/**
 * HTMLエスケープ
 */
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

/**
 * ローディング表示
 */
function showLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.add('active');
}

/**
 * ローディング非表示
 */
function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.remove('active');
}

/**
 * チャートデータエクスポート（仮実装）
 */
function exportChartData(type) {
    alert(`${type}チャートのデータエクスポート機能は準備中です`);
}

/**
 * テーブルデータCSVエクスポート
 */
function exportTableData() {
    if (!currentData || !currentData.sources_detail) {
        alert('エクスポートするデータがありません');
        return;
    }
    
    // CSV生成
    const headers = ['参照元', 'セッション', 'PV', '平均滞在時間', '直帰率', 'ゴール数', '達成率'];
    const rows = currentData.sources_detail.map(source => [
        `${source.source} / ${source.medium}`,
        source.sessions,
        source.pageViews,
        formatDuration(source.avgDuration),
        formatPercent(source.bounceRate),
        source.conversions,
        formatPercent(source.cvr)
    ]);
    
    let csv = headers.join(',') + '\n';
    rows.forEach(row => {
        csv += row.join(',') + '\n';
    });
    
    // ダウンロード
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'source-analysis-' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

/**
 * 期間表示を更新（共通モジュールへ委譲 + フォールバック）
 * - 表示形式（#periodDisplay）は維持
 * - payload 直下 / payload.data 配下 / period_meta / range_label に対応
 */
function updatePeriodDisplay(payload) {
    console.log('[GCREV Source] updatePeriodDisplay called with:', payload);
    
    // 共通モジュールがあればそれを使用
    if (window.GCREV && typeof window.GCREV.updatePeriodDisplay === 'function') {
        console.log('[GCREV Source] Using GCREV.updatePeriodDisplay');
        window.GCREV.updatePeriodDisplay(payload, { periodDisplayId: 'periodDisplay' });
        return;
    }

    console.log('[GCREV Source] Using fallback period display');
    const el = document.getElementById('periodDisplay');
    if (!el) {
        console.error('[GCREV Source] periodDisplay element not found');
        return;
    }

    // 取り出し元の候補
    const meta = (payload && payload.period_meta) ||
                 (payload && payload.data && payload.data.period_meta) ||
                 null;

    // label があれば優先
    const currentLabel = (payload && payload.current_range_label) ||
                         (payload && payload.data && payload.data.current_range_label) ||
                         (meta && meta.current && meta.current.label) ||
                         null;

    const compareLabel = (payload && payload.compare_range_label) ||
                         (payload && payload.data && payload.data.compare_range_label) ||
                         (meta && meta.compare && meta.compare.label) ||
                         null;

    // start/end 形式
    const currentPeriodObj = (payload && payload.current_period) ||
                             (payload && payload.data && payload.data.current_period) ||
                             (meta && meta.current) ||
                             null;

    const comparePeriodObj = (payload && payload.comparison_period) ||
                             (payload && payload.data && payload.data.comparison_period) ||
                             (meta && meta.compare) ||
                             null;

    const fmt = (start, end) => {
        if (!start || !end) return '-';
        return String(start).replace(/-/g, '/') + ' 〜 ' + String(end).replace(/-/g, '/');
    };

    let html =
      '<div class="period-item">' +
        '<span class="period-label-v2">&#x1F4C5; 分析対象期間：</span>' +
        '<span class="period-value">' + (currentLabel || (currentPeriodObj ? fmt(currentPeriodObj.start, currentPeriodObj.end) : '-')) + '</span>' +
      '</div>';

    const hasCompare = !!(compareLabel || (comparePeriodObj && comparePeriodObj.start && comparePeriodObj.end));
    if (hasCompare) {
      html +=
        '<div class="period-divider"></div>' +
        '<div class="period-item">' +
          '<span class="period-label-v2">&#x1F4CA; 比較期間：</span>' +
          '<span class="period-value">' + (compareLabel || fmt(comparePeriodObj.start, comparePeriodObj.end)) + '</span>' +
        '</div>';
    }

    el.innerHTML = html;
}

/**
 * period-selector 内の期間レンジ表示を更新（UIは period-selector.js に委譲）
 */
function updatePeriodRangeFromData(payload, selectorId) {
    if (!(window.GCREV && typeof window.GCREV.updatePeriodRange === 'function')) return;

    const meta = (payload && payload.period_meta) ||
                 (payload && payload.data && payload.data.period_meta) ||
                 null;

    const currentLabel = (payload && payload.current_range_label) ||
                         (payload && payload.data && payload.data.current_range_label) ||
                         (meta && meta.current && meta.current.label) ||
                         null;

    const compareLabel = (payload && payload.compare_range_label) ||
                         (payload && payload.data && payload.data.compare_range_label) ||
                         (meta && meta.compare && meta.compare.label) ||
                         null;

    // ラベルが無ければ start/end から生成
    const current = (payload && payload.current_period) ||
                    (payload && payload.data && payload.data.current_period) ||
                    (meta && meta.current) ||
                    null;

    const compare = (payload && payload.comparison_period) ||
                    (payload && payload.data && payload.data.comparison_period) ||
                    (meta && meta.compare) ||
                    null;

    const fmt = (p) => {
        if (!p || !p.start || !p.end) return '';
        return String(p.start).replace(/-/g, '/') + ' 〜 ' + String(p.end).replace(/-/g, '/');
    };

    const cur = currentLabel || fmt(current);
    const cmp = compareLabel || fmt(compare);

    window.GCREV.updatePeriodRange(selectorId, cur || '', cmp || '');
}

</script>

<?php get_footer(); ?>
