<?php
/*
Template Name: デバイス別アクセス
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'スマホとパソコンの割合');
set_query_var('gcrev_page_subtitle', 'スマホとパソコン、どちらで見られているかが分かります。');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('スマホとパソコンの割合', '集客のようす'));

get_header();
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
/* page-analysis-device — Page-specific overrides only */
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
  'id' => 'device-period',
  'items' => [
    ['value' => 'last30',     'label' => '直近30日'],
    ['value' => 'prev-month',      'label' => '前月'],
    ['value' => 'prev-prev-month', 'label' => '前々月'],
    ['value' => 'last90',          'label' => '過去90日'],
    ['value' => 'last180',    'label' => '過去半年'],
    ['value' => 'last365',    'label' => '過去1年'],
  ],
  'default' => 'prev-month',
]);

get_template_part('template-parts/period-selector');

?>
    <!-- 期間表示 -->
    <div class="period-display" id="periodDisplay">
        分析対象期間を選択してください
    </div>

    <!-- このページの見方（初心者向け） -->
<?php
set_query_var('analysis_help_key', 'device');
get_template_part('template-parts/analysis-help');
?>
    <!-- サマリーカード -->
    <div class="summary-cards" id="summaryCards">
        <!-- Mobile -->
        <div class="summary-card">
            <div class="summary-card-header">
                <div>
                    <div class="summary-card-title">Mobile</div>
                    <div class="summary-card-value" id="mobileSessions">-</div>
                    <div class="summary-card-change neutral" id="mobileChange">
                        <span>-</span>
                        <span>-</span>
                    </div>
                </div>
                <div class="summary-card-icon" style="background: rgba(61,107,110,0.1); color: #3D6B6E;">📱</div>
            </div>
            <div style="font-size: 14px; color: #555555; margin-top: 12px;">
                全体の <strong style="color: #333333;" id="mobileShare">-</strong> を占める
            </div>
            <div class="progress-bar">
                <div class="progress-fill mobile" id="mobileProgress" style="width: 0%;"></div>
            </div>
        </div>

        <!-- Desktop -->
        <div class="summary-card">
            <div class="summary-card-header">
                <div>
                    <div class="summary-card-title">Desktop</div>
                    <div class="summary-card-value" id="desktopSessions">-</div>
                    <div class="summary-card-change neutral" id="desktopChange">
                        <span>-</span>
                        <span>-</span>
                    </div>
                </div>
                <div class="summary-card-icon" style="background: rgba(61,139,110,0.1); color: #3D8B6E;">💻</div>
            </div>
            <div style="font-size: 14px; color: #555555; margin-top: 12px;">
                全体の <strong style="color: #333333;" id="desktopShare">-</strong> を占める
            </div>
            <div class="progress-bar">
                <div class="progress-fill desktop" id="desktopProgress" style="width: 0%;"></div>
            </div>
        </div>

        <!-- Tablet -->
        <div class="summary-card">
            <div class="summary-card-header">
                <div>
                    <div class="summary-card-title">Tablet</div>
                    <div class="summary-card-value" id="tabletSessions">-</div>
                    <div class="summary-card-change neutral" id="tabletChange">
                        <span>-</span>
                        <span>-</span>
                    </div>
                </div>
                <div class="summary-card-icon" style="background: rgba(212,168,66,0.12); color: #D4A842;">📲</div>
            </div>
            <div style="font-size: 14px; color: #555555; margin-top: 12px;">
                全体の <strong style="color: #333333;" id="tabletShare">-</strong> を占める
            </div>
            <div class="progress-bar">
                <div class="progress-fill tablet" id="tabletProgress" style="width: 0%;"></div>
            </div>
        </div>
    </div>

    <!-- デバイス別推移チャート -->
    <div class="chart-section">
        <div class="chart-header">
            <h3 class="chart-title">📊 デバイス別推移</h3>

        </div>
        <div class="chart-container">
            <canvas id="trendChart"></canvas>
        </div>
    </div>

    <!-- デバイス別シェア円グラフ -->
    <div class="chart-section">
        <div class="chart-header">
            <h3 class="chart-title">🥧 デバイス別シェア</h3>

        </div>
        <div class="chart-container">
            <canvas id="shareChart"></canvas>
        </div>
    </div>

    <!-- 詳細データテーブル -->
    <div class="chart-section">
        <div class="chart-header">
            <h3 class="chart-title">📋 デバイス別詳細データ</h3>
            <div class="chart-actions">
                <button class="chart-btn" onclick="exportTableData()">📥 CSVエクスポート</button>
            </div>
        </div>

        <table class="data-table" id="detailTable">
            <thead>
                <tr>
                    <th>デバイス</th>
                    <th>セッション</th>
                    <th>ユーザー</th>
                    <th>PV</th>
                    <th>平均滞在時間</th>
                    <th>直帰率</th>
                    <th>ゴール数</th>
                    <th>達成率</th>
                </tr>
            </thead>
            <tbody id="detailTableBody">
                <tr>
                    <td colspan="8" style="text-align: center; padding: 24px; color: #888888;">
                        データを読み込み中...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
// グローバル変数
let trendChart = null;
let shareChart = null;
let currentData = null;

// 初期化（period-selector モジュール連携）
// - period-selector.js が active 制御と localStorage 保存、gcrev:periodChange 発火を担当
// - このページは「イベント受け取り→API取得→表示更新」だけ行う
let currentPeriod = null;

(function bindPeriodSelector() {
    const selectorId = 'device-period';
    const selectorEl = document.getElementById(selectorId);

    const initialPeriod = 'prev-month';

    // 期間変更イベント（period-selector.js から発火）
    if (selectorEl) {
        selectorEl.addEventListener('gcrev:periodChange', function(e) {
            const period = e.detail && e.detail.period ? e.detail.period : null;
            if (!period) return;
            if (period === currentPeriod) return;
            loadData(period);
        });
    }

    // 初回読み込み（イベント取りこぼし対策・二重読み込みは currentPeriod でガード）
    loadData(initialPeriod);
})();

/**
 * データ取得とUI更新
 */
async function loadData(period) {
    currentPeriod = period;
    showLoading();
    
    try {
const apiUrl = '<?php echo rest_url("gcrev/v1/dashboard/kpi"); ?>?period=' + period;
const response = await fetch(apiUrl, {
    method: 'GET',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
    },
    credentials: 'same-origin'
});
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.message || 'データ取得に失敗しました');
        }
        
        currentData = result.data;
        
        // 期間表示更新
        updatePeriodDisplay(currentData);
        updatePeriodRangeFromData(currentData, 'device-period');
        
        // UI更新
        updateSummaryCards(currentData);
        updateTrendChart(currentData);
        updateShareChart(currentData);
        updateDetailTable(currentData);
        
    } catch (error) {
        console.error('データ取得エラー:', error);
        alert('データの取得に失敗しました。もう一度お試しください。');
    } finally {
        hideLoading();
    }
}

/**
 * 期間表示を更新（共通モジュールへ委譲）
 * - #periodDisplay の表示形式は維持
 * - data.current_period / data.comparison_period を想定（後方互換）
 */
function updatePeriodDisplay(data) {
    if (window.GCREV && typeof window.GCREV.updatePeriodDisplay === 'function') {
        window.GCREV.updatePeriodDisplay(data, { periodDisplayId: 'periodDisplay' });
        return;
    }

    // フォールバック（共通モジュール未読込でも表示を崩さない）
    const periodDisplay = document.getElementById('periodDisplay');
    if (!periodDisplay || !data || !data.current_period) return;

    const current = data.current_period;
    const comparison = data.comparison_period;

    const fmt = (start, end) => {
        if (!start || !end) return '-';
        return String(start).replace(/-/g, '/') + ' 〜 ' + String(end).replace(/-/g, '/');
    };

    let html = '<strong>分析対象期間：</strong>' + fmt(current.start, current.end);

    if (comparison) {
        html += ' <span style="margin: 0 8px; color: #888888;">|</span> ' +
                '<strong>比較期間：</strong>' + fmt(comparison.start, comparison.end);
    }

    periodDisplay.innerHTML = html;
}

/**
 * period-selector の下部レンジ表示を更新
 * - APIの current_range_label / compare_range_label があれば優先
 * - なければ current_period / comparison_period から生成
 */
function updatePeriodRangeFromData(data, selectorId) {
    if (!window.GCREV || typeof window.GCREV.updatePeriodRange !== 'function' || !data) return;

    const currentLabel =
        data.current_range_label ||
        (data.current_period ? formatRangeLabel(data.current_period.start, data.current_period.end) : '');

    const compareLabel =
        data.compare_range_label ||
        data.comparison_range_label ||
        (data.comparison_period ? formatRangeLabel(data.comparison_period.start, data.comparison_period.end) : '');

    window.GCREV.updatePeriodRange(selectorId, currentLabel, compareLabel);
}

function formatRangeLabel(start, end) {
    if (!start || !end) return '';
    return String(start).replace(/-/g, '/') + ' 〜 ' + String(end).replace(/-/g, '/');
}


/**
 * サマリーカード更新
 */
function updateSummaryCards(data) {
    const { devices_detail = [], devices_prev_detail = [] } = data;
    
    // デバイスごとのマッピング
    const deviceMap = {
        mobile: {},
        desktop: {},
        tablet: {}
    };
    
    const prevDeviceMap = {
        mobile: {},
        desktop: {},
        tablet: {}
    };
    
    // 現在期間のデータ
    devices_detail.forEach(device => {
        const key = device.device.toLowerCase();
        if (deviceMap[key] !== undefined) {
            deviceMap[key] = device;
        }
    });
    
    // 比較期間のデータ
    devices_prev_detail.forEach(device => {
        const key = device.device.toLowerCase();
        if (prevDeviceMap[key] !== undefined) {
            prevDeviceMap[key] = device;
        }
    });
    
    // 各デバイスのカード更新
    updateDeviceCard('mobile', deviceMap.mobile, prevDeviceMap.mobile);
    updateDeviceCard('desktop', deviceMap.desktop, prevDeviceMap.desktop);
    updateDeviceCard('tablet', deviceMap.tablet, prevDeviceMap.tablet);
}

/**
 * 個別デバイスカード更新
 */
function updateDeviceCard(deviceType, current, prev) {
    const sessions = current.sessions || 0;
    const prevSessions = prev.sessions || 0;
    const share = current.share || 0;
    
    // 前期比計算
    let changePercent = 0;
    let changeClass = 'neutral';
    let changeSymbol = '-';
    
    if (prevSessions > 0) {
        changePercent = ((sessions - prevSessions) / prevSessions) * 100;
        if (changePercent > 0) {
            changeClass = 'positive';
            changeSymbol = '▲';
        } else if (changePercent < 0) {
            changeClass = 'negative';
            changeSymbol = '▼';
        }
    }
    
    // DOM更新
    document.getElementById(`${deviceType}Sessions`).textContent = formatNumber(sessions);
    document.getElementById(`${deviceType}Share`).textContent = formatPercent(share);
    document.getElementById(`${deviceType}Progress`).style.width = `${share}%`;
    
    const changeEl = document.getElementById(`${deviceType}Change`);
    changeEl.className = `summary-card-change ${changeClass}`;
    changeEl.innerHTML = `
        <span>${changeSymbol}</span>
        <span>${formatPercent(Math.abs(changePercent))}</span>
    `;
}

/**
 * 推移チャート更新
 */
function updateTrendChart(data) {
    const { devices_daily_series = {} } = data;
    const { labels = [], mobile = [], desktop = [], tablet = [] } = devices_daily_series;
    
    const ctx = document.getElementById('trendChart');
    
    // 既存チャート破棄
    if (trendChart) {
        trendChart.destroy();
    }
    
    trendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Mobile',
                    data: mobile,
                    borderColor: '#3D6B6E',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Desktop',
                    data: desktop,
                    borderColor: '#3D8B6E',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Tablet',
                    data: tablet,
                    borderColor: '#D4A842',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    tension: 0.4,
                    fill: true
                }
            ]
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
                            return context.dataset.label + ': ' + formatNumber(context.parsed.y);
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
    const { devices_detail = [] } = data;
    
    const labels = [];
    const sessions = [];
    const colors = ['#3D6B6E', '#3D8B6E', '#D4A842'];
    
    devices_detail.forEach(device => {
        const name = getDeviceLabel(device.device);
        labels.push(name);
        sessions.push(device.sessions || 0);
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
                            return context.label + ': ' + formatNumber(value) + ' (' + percent + '%)';
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
    const { devices_detail = [] } = data;
    const tbody = document.getElementById('detailTableBody');
    
    if (devices_detail.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 24px; color: #888888;">データがありません</td></tr>';
        return;
    }
    
    const rows = devices_detail.map(device => {
        const icon = getDeviceIcon(device.device);
        const label = getDeviceLabel(device.device);
        
        return `
            <tr>
                <td><span class="device-icon">${icon} ${label}</span></td>
                <td>${formatNumber(device.sessions)}</td>
                <td>${formatNumber(device.users)}</td>
                <td>${formatNumber(device.pageViews)}</td>
                <td>${formatDuration(device.avgDuration)}</td>
                <td>${formatPercent(device.bounceRate)}</td>
                <td>${formatNumber(device.conversions)}</td>
                <td>${formatPercent(device.cvr)}</td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = rows;
}

// ===== ユーティリティ関数 =====

/**
 * デバイスアイコン取得
 */
function getDeviceIcon(device) {
    const map = {
        'mobile': '📱',
        'desktop': '💻',
        'tablet': '📲'
    };
    return map[device.toLowerCase()] || '📱';
}

/**
 * デバイスラベル取得
 */
function getDeviceLabel(device) {
    const map = {
        'mobile': 'Mobile',
        'desktop': 'Desktop',
        'tablet': 'Tablet'
    };
    return map[device.toLowerCase()] || device;
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
 * テーブルデータCSVエクスポート（仮実装）
 */
function exportTableData() {
    if (!currentData || !currentData.devices_detail) {
        alert('エクスポートするデータがありません');
        return;
    }
    
    // CSV生成
    const headers = ['デバイス', 'セッション', 'ユーザー', 'PV', '平均滞在時間', '直帰率', 'ゴール数', '達成率'];
    const rows = currentData.devices_detail.map(device => [
        getDeviceLabel(device.device),
        device.sessions,
        device.users,
        device.pageViews,
        formatDuration(device.avgDuration),
        formatPercent(device.bounceRate),
        device.conversions,
        formatPercent(device.cvr)
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
    link.setAttribute('download', 'device-analysis-' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php get_footer(); ?>
