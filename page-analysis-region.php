<?php
/*
Template Name: 地域別アクセス
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', '見ている人の場所');
set_query_var('gcrev_page_subtitle', 'どの地域の人に見られているかが分かります。');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('見ている人の場所', 'ホームページ'));

get_header();

// REST API用のnonce生成
$rest_nonce = wp_create_nonce('wp_rest');
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
/* page-analysis-region — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */

/* ===== 地域別推移 詳細パネル ===== */
.region-trend-panel {
    display: none;
    margin-top: 16px;
    padding: 20px 24px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    animation: regionTrendSlideIn 0.25s ease-out;
}
.region-trend-panel.is-open {
    display: block;
}
@keyframes regionTrendSlideIn {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.region-trend-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}
.region-trend-header h4 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: #2C3E40;
}
.region-trend-close {
    background: none;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    width: 28px;
    height: 28px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #64748b;
    font-size: 14px;
    transition: background 0.15s, color 0.15s;
}
.region-trend-close:hover {
    background: #f1f5f9;
    color: #334155;
}
.region-trend-chart-wrap {
    position: relative;
    height: 260px;
}
.region-trend-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 200px;
    color: #94a3b8;
    font-size: 14px;
}
.region-trend-loading .mini-spinner {
    display: inline-block;
    width: 18px;
    height: 18px;
    border: 2px solid #e2e8f0;
    border-top-color: #568184;
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
    margin-right: 8px;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
.region-trend-empty {
    text-align: center;
    padding: 40px 0;
    color: #94a3b8;
    font-size: 14px;
}

/* ===== クリックヒント ===== */
.chart-click-hint {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #94a3b8;
    font-weight: 400;
    transition: color 0.2s;
}
.chart-click-hint .hint-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: rgba(122,163,166,0.1);
    color: #568184;
    font-size: 10px;
    flex-shrink: 0;
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
// 期間セレクター（共通モジュール）
set_query_var('gcrev_period_selector', [
  'id' => 'region-period',
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
set_query_var('analysis_help_key', 'region');
get_template_part('template-parts/analysis-help');
?>
    <!-- 📊 地域別アクセス TOP10 -->
    <div class="chart-section">
        <div class="chart-header">
            <h3 class="chart-title">📊 地域別アクセス TOP10</h3>
            <span class="chart-click-hint">
                <span class="hint-icon">↗</span>グラフをクリックで12ヶ月推移を表示
            </span>
        </div>
        <div class="chart-container">
            <canvas id="regionTop10Chart"></canvas>
        </div>

        <!-- 地域別 12ヶ月推移パネル（クリックで展開） -->
        <div class="region-trend-panel" id="regionTrendPanel">
            <div class="region-trend-header">
                <h4 id="regionTrendTitle">—</h4>
                <button class="region-trend-close" id="regionTrendClose" title="閉じる">✕</button>
            </div>
            <div id="regionTrendBody">
                <!-- loading / chart / empty がここに入る -->
            </div>
        </div>
    </div>

    <!-- 📋 地域別詳細データ TOP20 -->
    <div class="chart-section">
        <div class="chart-header">
            <h3 class="chart-title">📋 地域別詳細データ TOP20</h3>
            <div class="chart-actions">
                <button class="chart-btn" onclick="exportTableData()">📥 CSVエクスポート</button>
            </div>
        </div>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>順位</th>
                    <th>地域</th>
                    <th>セッション</th>
                    <th>割合</th>
                    <th>PV</th>
                    <th>平均滞在時間</th>
                    <th>直帰率</th>
                    <th>ゴール数</th>
                    <th>達成率</th>
                    <th>変動</th>
                    <th>シェア</th>
                </tr>
            </thead>
            <tbody id="regionTableBody">
                <tr>
                    <td colspan="11" style="text-align: center; padding: 24px; color: #888888;">
                        データを読み込んでいます...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
// ===== グローバル変数 =====
const REST_NONCE = '<?php echo esc_js($rest_nonce); ?>';
const REST_URL = '<?php echo esc_url(rest_url('gcrev/v1/analysis/region')); ?>';
const REST_TREND_URL = '<?php echo esc_url(rest_url('gcrev/v1/analysis/region-trend')); ?>';

let currentData = null;
let currentPeriod = 'prev-month';
let top10Chart = null;
let trendChart = null;
let selectedAreaIndex = -1;   // 選択中の棒インデックス（-1 = 未選択）
let selectedAreaEnName = '';   // 選択中の地域名（英語：API送信用）

// ===== ページ初期化 =====
document.addEventListener('DOMContentLoaded', function() {
    console.log('地域別アクセスページ初期化');
    
    // DOMが完全に準備できるまで少し待つ
    setTimeout(function() {
        // 常に前月で開く
        currentPeriod = 'prev-month';

        // 期間ボタンのイベントリスナー設定
        setupPeriodButtons();

        // 推移パネル閉じるボタン
        const closeBtn = document.getElementById('regionTrendClose');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                selectedAreaIndex = -1;
                selectedAreaEnName = '';
                updateBarStyles(-1);
                closeRegionTrendPanel();
            });
        }

        // 初回データ取得
        loadRegionData(currentPeriod);
    }, 100);
});

/**
 * 期間の妥当性チェック
 */
function isValidPeriod(period) {
    const validPeriods = ['last30', 'prev-month', 'prev-prev-month', 'last90', 'last180', 'last365'];
    return validPeriods.includes(period);
}

/**
 * 期間セレクターの状態更新
 */
function updatePeriodSelector(period) {
    const buttons = document.querySelectorAll('#region-period .period-btn');
    buttons.forEach(btn => {
        // data-period または data-value をチェック
        const btnPeriod = btn.dataset.period || btn.dataset.value;
        if (btnPeriod === period) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
}

/**
 * 期間ボタンのイベント設定
 */
/**
 * 期間ボタンのイベント設定
 */
function setupPeriodButtons() {
    // 複数のセレクタパターンを試す
    const selectors = [
        '#region-period .period-btn',
        '#region-period button',
        '.period-selector .period-btn',
        '.period-selector button',
        '[data-period]',
        '.period-btn'
    ];
    
    let buttons = [];
    let usedSelector = '';
    
    for (const selector of selectors) {
        const found = document.querySelectorAll(selector);
        if (found.length > 0) {
            buttons = found;
            usedSelector = selector;
            break;
        }
    }
    
    console.log('使用したセレクタ:', usedSelector);
    console.log('期間ボタン数:', buttons.length);
    
    if (buttons.length === 0) {
        console.error('期間ボタンが見つかりません');
        return;
    }
    
    buttons.forEach((btn, index) => {
        // data-value または data-period を確認
        const period = btn.dataset.value || btn.dataset.period || btn.getAttribute('data-value') || btn.getAttribute('data-period');
        
        console.log(`ボタン${index}:`, {
            textContent: btn.textContent.trim(),
            'dataset.value': btn.dataset.value,
            'dataset.period': btn.dataset.period,
            'getAttribute(data-value)': btn.getAttribute('data-value'),
            'getAttribute(data-period)': btn.getAttribute('data-period'),
            className: btn.className,
            outerHTML: btn.outerHTML.substring(0, 100)
        });
        
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const clickedPeriod = this.dataset.value || this.dataset.period || this.getAttribute('data-value') || this.getAttribute('data-period');
            
            console.log('クリックされた期間:', clickedPeriod);
            
            if (!clickedPeriod || clickedPeriod === 'undefined') {
                console.error('period が undefined です');
                console.error('ボタンのHTML:', this.outerHTML);
                return;
            }
            
            if (clickedPeriod !== currentPeriod) {
                currentPeriod = clickedPeriod;
                updatePeriodSelector(clickedPeriod);
                
                // URLパラメータ更新
                const url = new URL(window.location);
                url.searchParams.set('period', clickedPeriod);
                window.history.pushState({}, '', url);
                
                // データ再取得
                loadRegionData(clickedPeriod);
            }
        });
    });
}

/**
 * 地域別データ取得
 */
async function loadRegionData(period) {
    console.log(`地域別データ取得開始: ${period}`);
    showLoading();
    
    try {
        const url = `${REST_URL}?period=${period}`;
        console.log('リクエストURL:', url);
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': REST_NONCE
            },
            credentials: 'same-origin'
        });
        
        console.log('レスポンスステータス:', response.status);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.error('エラーレスポンス:', errorText);
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        console.log('地域別データ取得成功:', data);
        
        currentData = data;
        
        // 期間表示更新
        updatePeriodDisplay(data.period_display || '期間不明');
        
        // 各セクション更新
        updateTop10Chart(data);
        updateRegionTable(data);
        
    } catch (error) {
        console.error('地域別データ取得エラー:', error);
        showError('データの取得に失敗しました。再度お試しください。');
    } finally {
        hideLoading();
    }
}

/**
 * 期間表示更新
 */
function updatePeriodDisplay(displayText) {
    const elem = document.getElementById('periodDisplay');
    if (!elem) return;
    elem.innerHTML =
      '<div class="period-item">' +
        '<span class="period-label-v2">&#x1F4C5; 分析対象期間：</span>' +
        '<span class="period-value">' + (displayText || '-') + '</span>' +
      '</div>';
}

let regionMapChart = null;

/**
 * TOP10チャート更新
 */
function updateTop10Chart(data) {
    const { regions_detail = [] } = data;

    // TOP10を取得
    const top10 = regions_detail.slice(0, 10);

    if (top10.length === 0) {
        document.getElementById('regionTop10Chart').parentElement.innerHTML =
            '<div class="chart-placeholder">データがありません</div>';
        return;
    }

    // 地域名を日本語に変換（表示用）
    const labels = top10.map(r => convertRegionNameToJapanese(r.region || '不明'));
    // 英語名を保持（API送信用）
    const enNames = top10.map(r => r.region || '');
    const sessions = top10.map(r => r.sessions || 0);

    const ctx = document.getElementById('regionTop10Chart');

    // 既存チャート破棄
    if (top10Chart) {
        top10Chart.destroy();
    }

    // 選択状態リセット
    selectedAreaIndex = -1;
    selectedAreaEnName = '';
    closeRegionTrendPanel();

    // 背景色の配列を生成（選択状態で色が変わる）
    const defaultBg = 'rgba(86, 129, 132, 0.8)';
    const defaultBorder = '#568184';

    top10Chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'セッション数',
                data: sessions,
                backgroundColor: Array(top10.length).fill(defaultBg),
                borderColor: Array(top10.length).fill(defaultBorder),
                borderWidth: Array(top10.length).fill(1),
                // 英語名をカスタムデータとして保持
                enNames: enNames
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            // ホバー時にポインターカーソルを表示
            onHover: function(event, elements) {
                const canvas = event.native ? event.native.target : event.chart.canvas;
                canvas.style.cursor = elements.length > 0 ? 'pointer' : 'default';
            },
            // 棒クリック時のイベント
            onClick: function(event, elements) {
                if (elements.length === 0) return;
                const idx = elements[0].index;
                handleBarClick(idx, enNames[idx], labels[idx]);
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'セッション: ' + formatNumber(context.parsed.x);
                        },
                        afterLabel: function() {
                            return 'クリックで直近12ヶ月の推移を表示';
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: '#f3f4f6' },
                    ticks: {
                        font: { size: 11 },
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    }
                },
                y: {
                    grid: { display: false },
                    ticks: {
                        font: { size: 12 }
                    }
                }
            }
        }
    });
}

/**
 * 棒の選択スタイルを更新
 */
function updateBarStyles(activeIndex) {
    if (!top10Chart) return;
    const ds = top10Chart.data.datasets[0];
    const count = ds.data.length;
    for (let i = 0; i < count; i++) {
        if (i === activeIndex) {
            ds.backgroundColor[i] = 'rgba(78, 138, 107, 1)';
            ds.borderColor[i]     = '#3D5A42';
            ds.borderWidth[i]     = 2;
        } else {
            ds.backgroundColor[i] = activeIndex >= 0 ? 'rgba(86, 129, 132, 0.45)' : 'rgba(86, 129, 132, 0.8)';
            ds.borderColor[i]     = '#568184';
            ds.borderWidth[i]     = 1;
        }
    }
    top10Chart.update('none');
}

/**
 * 棒クリック時のハンドラ
 */
function handleBarClick(index, enName, jaName) {
    // 同じ棒を再クリック → 閉じる
    if (selectedAreaIndex === index) {
        selectedAreaIndex = -1;
        selectedAreaEnName = '';
        updateBarStyles(-1);
        closeRegionTrendPanel();
        return;
    }
    // 新しい棒を選択
    selectedAreaIndex = index;
    selectedAreaEnName = enName;
    updateBarStyles(index);
    openRegionTrendPanel(enName, jaName);
}

/**
 * 推移パネルを開いてデータ取得
 */
async function openRegionTrendPanel(enName, jaName) {
    const panel = document.getElementById('regionTrendPanel');
    const title = document.getElementById('regionTrendTitle');
    const body  = document.getElementById('regionTrendBody');

    title.textContent = jaName + ' の12ヶ月推移';
    body.innerHTML = '<div class="region-trend-loading"><span class="mini-spinner"></span>読み込み中…</div>';
    panel.classList.add('is-open');

    try {
        const params = new URLSearchParams({
            area: enName,
            months: '12',
            metric: 'sessions'
        });
        const resp = await fetch(`${REST_TREND_URL}?${params}`, {
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
            credentials: 'same-origin'
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();

        if (!data.success || !data.labels || data.labels.length === 0) {
            body.innerHTML = '<div class="region-trend-empty">この地域のデータがありません</div>';
            return;
        }
        renderTrendChart(body, data.labels, data.values, jaName);
    } catch (err) {
        console.error('推移データ取得エラー:', err);
        body.innerHTML = '<div class="region-trend-empty">データの取得に失敗しました</div>';
    }
}

/**
 * 推移グラフ描画
 */
function renderTrendChart(container, labels, values, jaName) {
    // YYYY-MM → M月 表記
    const dispLabels = labels.map(l => {
        const m = parseInt(l.split('-')[1], 10);
        return m + '月';
    });

    container.innerHTML = '<div class="region-trend-chart-wrap"><canvas id="regionTrendCanvas"></canvas></div>';
    const ctx = document.getElementById('regionTrendCanvas');

    if (trendChart) {
        trendChart.destroy();
    }

    trendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: dispLabels,
            datasets: [{
                label: jaName + ' セッション',
                data: values,
                borderColor: '#568184',
                backgroundColor: 'rgba(86, 129, 132, 0.15)',
                fill: true,
                tension: 0.3,
                pointRadius: 4,
                pointBackgroundColor: '#568184',
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: function(items) {
                            // YYYY-MM のフル表記
                            return labels[items[0].dataIndex];
                        },
                        label: function(context) {
                            return 'セッション: ' + formatNumber(context.parsed.y);
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: '#f3f4f6' },
                    ticks: {
                        font: { size: 11 },
                        callback: function(v) { return formatNumber(v); }
                    }
                }
            }
        }
    });
}

/**
 * 推移パネルを閉じる
 */
function closeRegionTrendPanel() {
    const panel = document.getElementById('regionTrendPanel');
    if (panel) panel.classList.remove('is-open');
    if (trendChart) {
        trendChart.destroy();
        trendChart = null;
    }
}

/**
 * 地域別テーブル更新
 */
function updateRegionTable(data) {
    const { regions_detail = [] } = data;
    const tbody = document.getElementById('regionTableBody');
    
    if (regions_detail.length === 0) {
        tbody.innerHTML = '<tr><td colspan="11" style="text-align: center; padding: 24px; color: #888888;">データがありません</td></tr>';
        return;
    }
    
    // 総セッション数を計算
    const totalSessions = regions_detail.reduce((sum, r) => sum + (r.sessions || 0), 0);
    
    // TOP20を取得して表示
    const top20 = regions_detail.slice(0, 20);
    
    const rows = top20.map((region, index) => {
        const rank = index + 1;
        const rankClass = rank <= 5 ? 'top5' : '';
        
        // 割合計算
        const sharePercent = totalSessions > 0 ? (region.sessions / totalSessions * 100) : 0;
        
        // 変動データ（前期比）
        const change = region.change || 0;
        let changeBadge = '';
        if (change > 0) {
            changeBadge = `<span class="trend-badge up">↑ ${formatPercent(Math.abs(change))}</span>`;
        } else if (change < 0) {
            changeBadge = `<span class="trend-badge down">↓ ${formatPercent(Math.abs(change))}</span>`;
        } else {
            changeBadge = `<span class="trend-badge neutral">—</span>`;
        }
        
        return `
            <tr>
                <td><span class="rank-badge ${rankClass}">${rank}</span></td>
                <td style="font-weight: 600;">${escapeHtml(convertRegionNameToJapanese(region.region || '不明'))}</td>
                <td>${formatNumber(region.sessions)}</td>
                <td>${formatPercent(sharePercent)}</td>
                <td>${formatNumber(region.pageViews)}</td>
                <td>${formatDuration(region.avgDuration)}</td>
                <td>${formatPercent(region.bounceRate)}</td>
                <td>${formatNumber(region.conversions)}</td>
                <td>${formatPercent(region.cvr)}</td>
                <td>${changeBadge}</td>
                <td>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${sharePercent.toFixed(1)}%;"></div>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = rows;
}

// ===== ユーティリティ関数 =====

/**
 * 地域名を日本語に変換
 */
function convertRegionNameToJapanese(regionName) {
    // 日本の都道府県マッピング（英語 → 日本語）
    const regionMap = {
        // 北海道・東北
        'Hokkaido': '北海道',
        'Aomori': '青森県',
        'Iwate': '岩手県',
        'Miyagi': '宮城県',
        'Akita': '秋田県',
        'Yamagata': '山形県',
        'Fukushima': '福島県',
        // 関東
        'Ibaraki': '茨城県',
        'Tochigi': '栃木県',
        'Gunma': '群馬県',
        'Saitama': '埼玉県',
        'Chiba': '千葉県',
        'Tokyo': '東京都',
        'Kanagawa': '神奈川県',
        // 中部
        'Niigata': '新潟県',
        'Toyama': '富山県',
        'Ishikawa': '石川県',
        'Fukui': '福井県',
        'Yamanashi': '山梨県',
        'Nagano': '長野県',
        'Gifu': '岐阜県',
        'Shizuoka': '静岡県',
        'Aichi': '愛知県',
        // 近畿
        'Mie': '三重県',
        'Shiga': '滋賀県',
        'Kyoto': '京都府',
        'Osaka': '大阪府',
        'Hyogo': '兵庫県',
        'Nara': '奈良県',
        'Wakayama': '和歌山県',
        // 中国
        'Tottori': '鳥取県',
        'Shimane': '島根県',
        'Okayama': '岡山県',
        'Hiroshima': '広島県',
        'Yamaguchi': '山口県',
        // 四国
        'Tokushima': '徳島県',
        'Kagawa': '香川県',
        'Ehime': '愛媛県',
        'Kochi': '高知県',
        // 九州・沖縄
        'Fukuoka': '福岡県',
        'Saga': '佐賀県',
        'Nagasaki': '長崎県',
        'Kumamoto': '熊本県',
        'Oita': '大分県',
        'Miyazaki': '宮崎県',
        'Kagoshima': '鹿児島県',
        'Okinawa': '沖縄県',
        // その他の地域（英語のまま）
        'Kansai': '関西',
        'Kanto': '関東',
        'Tohoku': '東北',
        'Chubu': '中部',
        'Chugoku': '中国',
        'Shikoku': '四国',
        'Kyushu': '九州',
        // 中国の省・直轄市・自治区（34省級行政区）
        // 直轄市
        'Beijing': '北京市',
        'Shanghai': '上海市',
        'Tianjin': '天津市',
        'Chongqing': '重慶市',
        // 省
        'Hebei': '河北省',
        'Shanxi': '山西省',
        'Liaoning': '遼寧省',
        'Jilin': '吉林省',
        'Heilongjiang': '黒竜江省',
        'Jiangsu': '江蘇省',
        'Zhejiang': '浙江省',
        'Anhui': '安徽省',
        'Fujian': '福建省',
        'Jiangxi': '江西省',
        'Shandong': '山東省',
        'Henan': '河南省',
        'Hubei': '湖北省',
        'Hunan': '湖南省',
        'Guangdong': '広東省',
        'Hainan': '海南省',
        'Sichuan': '四川省',
        'Guizhou': '貴州省',
        'Yunnan': '雲南省',
        'Shaanxi': '陝西省',
        'Gansu': '甘粛省',
        'Qinghai': '青海省',
        'Taiwan': '台湾省',
        // 自治区
        'Inner Mongolia': '内モンゴル自治区',
        'Guangxi': '広西チワン族自治区',
        'Tibet': 'チベット自治区',
        'Ningxia': '寧夏回族自治区',
        'Xinjiang': '新疆ウイグル自治区',
        // 特別行政区
        'Hong Kong': '香港',
        'Macau': 'マカオ',
        // 国外（主要国）
        'United States': 'アメリカ',
        'China': '中国',
        'South Korea': '韓国',
        'Singapore': 'シンガポール',
        'Thailand': 'タイ',
        'Vietnam': 'ベトナム',
        'Philippines': 'フィリピン',
        'Indonesia': 'インドネシア',
        'Malaysia': 'マレーシア',
        'India': 'インド',
        'Australia': 'オーストラリア',
        'United Kingdom': 'イギリス',
        'Germany': 'ドイツ',
        'France': 'フランス',
        'Canada': 'カナダ',
        'Brazil': 'ブラジル',
        'Russia': 'ロシア',
        'Italy': 'イタリア',
        'Spain': 'スペイン',
        'Netherlands': 'オランダ',
        'Switzerland': 'スイス'
    };
    
    // マッピングに存在すれば日本語に変換、なければそのまま返す
    return regionMap[regionName] || regionName;
}

/**
 * 数値フォーマット（カンマ区切り）
 */
function formatNumber(num) {
    if (num === null || num === undefined) return '—';
    return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * パーセント表記
 */
function formatPercent(num) {
    if (num === null || num === undefined) return '—';
    return num.toFixed(1) + '%';
}

/**
 * 秒数を mm:ss 形式に変換
 */
function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) return '—';
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
    return String(text).replace(/[&<>"']/g, m => map[m]);
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
 * エラー表示
 */
function showError(message) {
    alert(message);
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
    if (!currentData || !currentData.regions_detail) {
        alert('エクスポートするデータがありません');
        return;
    }
    
    // CSV生成
    const headers = ['順位', '地域', 'セッション', '割合', 'PV', '平均滞在時間', '直帰率', 'ゴール数', '達成率', '変動'];
    const totalSessions = currentData.regions_detail.reduce((sum, r) => sum + (r.sessions || 0), 0);
    
    const rows = currentData.regions_detail.slice(0, 20).map((region, index) => {
        const sharePercent = totalSessions > 0 ? (region.sessions / totalSessions * 100) : 0;
        return [
            index + 1,
            convertRegionNameToJapanese(region.region || '不明'),
            region.sessions || 0,
            sharePercent.toFixed(1) + '%',
            region.pageViews || 0,
            formatDuration(region.avgDuration),
            formatPercent(region.bounceRate),
            region.conversions || 0,
            formatPercent(region.cvr),
            (region.change || 0).toFixed(1) + '%'
        ];
    });
    
    let csv = headers.join(',') + '\n';
    rows.forEach(row => {
        csv += row.join(',') + '\n';
    });
    
    // ダウンロード
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'region-analysis-' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php get_footer(); ?>
