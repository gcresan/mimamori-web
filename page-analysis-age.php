<?php
/*
Template Name: 年齢別アクセス
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', '見ている人の年代');
set_query_var('gcrev_page_subtitle', 'ホームページを見ている人の年齢層が分かります。');

// パンくず設定
$breadcrumb = '<a href="' . home_url() . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="#">ホームページ</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="' . home_url() . '/analysis/">集客分析</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<strong>見ている人の年代</strong>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

get_header();
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
/* page-analysis-age — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */
</style>

<!-- ローディングオーバーレイ -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner">
        <div class="spinner"></div>
        <div>データを読み込み中...</div>
    </div>
</div>

<div class="content-area">
    <!-- 期間セレクター -->
<?php
// 期間セレクター（共通モジュール）
set_query_var('gcrev_period_selector', [
  'id' => 'age-period',
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
set_query_var('analysis_help_key', 'age');
get_template_part('template-parts/analysis-help');
?>

    <!-- サマリーカード -->
    <div class="summary-cards" id="summaryCards">
        <!-- JavaScriptで動的生成 -->
    </div>



    <!-- 性別×年齢クロス分析 -->
    <div class="chart-section">
        <div class="chart-header">
            <h3 class="chart-title">👥 性別×年齢クロス分析</h3>
        </div>
        <div class="chart-container">
            <canvas id="genderAgeChart"></canvas>
        </div>
    </div>

<!-- 性別別 詳細データテーブル -->
<div class="chart-section">

    <div class="chart-header">
        <h3 class="chart-title">📋 性別別詳細データ</h3>
        <div class="chart-actions">
            <button class="chart-btn" onclick="exportGenderTableData()">📥 CSVエクスポート</button>
        </div>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>性別</th>
                <th>セッション</th>
                <th>割合</th>
                <th>PV</th>
                <th>平均滞在時間</th>
                <th>直帰率</th>
                <th>エンゲージメント率</th>
                <th>CV数</th>
                <th>CVR</th>
            </tr>
        </thead>
        <tbody id="genderDetailTableBody">
            <tr>
                <td colspan="9" style="text-align:center;padding:24px;color:#888888;">
                    データを読み込み中...
                </td>
            </tr>
        </tbody>
    </table>

</div>


    <!-- 詳細データテーブル -->
    <div class="chart-section">
        <div class="chart-header">
            <h3 class="chart-title">📋 年齢別詳細データ</h3>
            <div class="chart-actions">
                <button class="chart-btn" onclick="exportTableData()">📥 CSVエクスポート</button>
            </div>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>年齢層</th>
                    <th>セッション</th>
                    <th>割合</th>
                    <th>PV</th>
                    <th>平均滞在時間</th>
                    <th>直帰率</th>
                    <th>エンゲージメント率</th>
                    <th>CV数</th>
                    <th>CVR</th>
                </tr>
            </thead>
            <tbody id="detailTableBody">
                <tr>
                    <td colspan="9" style="text-align: center; padding: 24px; color: #888888;">データを読み込み中...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
// ===== グローバル変数 =====
let currentPeriod = 'prev-month';
let currentData = null;
let ageDistributionChart = null;
let genderAgeChart = null;

// ===== 初期化 =====
document.addEventListener('DOMContentLoaded', function() {
  // period-selector.js（UI制御）を初期化
  if (window.GCREV && typeof window.GCREV.initPeriodSelectors === 'function') {
    window.GCREV.initPeriodSelectors();
  }

  // このページのセレクターID
  const selectorId = 'age-period';
  const selectorEl = document.getElementById(selectorId);

  if (selectorEl) {
    selectorEl.addEventListener('gcrev:periodChange', function(e) {
      const period = e && e.detail ? e.detail.period : null;
      if (!period) return;
      if (period === currentPeriod) return;
      currentPeriod = period;
      loadData(period, selectorId);
    });
  }

  // 初期データ読み込み（常に前月）
  currentPeriod = 'prev-month';
  loadData(currentPeriod, selectorId);
});
/**

 * 期間表示を更新（共通モジュールへ委譲）
 * - 表示形式（#periodDisplay）は現状維持
 * - data.current_period / data.comparison_period を想定（後方互換）
 */
function updatePeriodDisplay(payload) {
  // 共通モジュールがあればそれを使用（後方互換のためpayloadをそのまま渡す）
  if (window.GCREV && typeof window.GCREV.updatePeriodDisplay === 'function') {
    window.GCREV.updatePeriodDisplay(payload, { periodDisplayId: 'periodDisplay' });
    return;
  }

  // フォールバック：payloadの形がページ/エンドポイントで違っても拾えるようにする
  const el = document.getElementById('periodDisplay');
  if (!el || !payload) return;

  // 1) 既存：root直下
  // 2) よくある：payload.data 直下（success wrapper）
  // 3) 将来：payload.period_meta 直下（統一案）
  const src = payload.current_period ? payload
            : (payload.data && payload.data.current_period) ? payload.data
            : payload;

  const current =
    src.current_period
    || (src.period_meta && src.period_meta.current)
    || (payload.period_meta && payload.period_meta.current)
    || null;

  const comparison =
    src.comparison_period
    || (src.period_meta && src.period_meta.compare)
    || (payload.period_meta && payload.period_meta.compare)
    || null;

  // labelがあるならそれを優先（#periodDisplayの形式は維持）
  const currentLabel =
    src.current_range_label
    || (src.period_meta && src.period_meta.current && src.period_meta.current.label)
    || (payload.current_range_label)
    || (payload.period_meta && payload.period_meta.current && payload.period_meta.current.label)
    || '';

  const compareLabel =
    src.compare_range_label
    || (src.period_meta && src.period_meta.compare && src.period_meta.compare.label)
    || (payload.compare_range_label)
    || (payload.period_meta && payload.period_meta.compare && payload.period_meta.compare.label)
    || '';

  const formatPeriod = (start, end) => {
    if (!start || !end) return '-';
    return String(start).replace(/-/g, '/') + ' 〜 ' + String(end).replace(/-/g, '/');
  };

  const currentText = currentLabel || (current ? formatPeriod(current.start, current.end) : '');
  if (!currentText) return;

  let html = '<strong>分析対象期間：</strong>' + currentText;

  // 比較期間は「ある時だけ」表示（従来通り）
  const compareText = compareLabel || (comparison ? formatPeriod(comparison.start, comparison.end) : '');
  if (compareText) {
    html += ' <span style="margin: 0 8px; color: #888888;">|</span> '
         +  '<strong>比較期間：</strong>' + compareText;
  }

  el.innerHTML = html;
}


/**
 * period-selector のレンジ表示を更新（APIレスポンスから安全に吸い上げ）
 */
function updatePeriodRangeFromData(data, selectorId) {
  if (!window.GCREV || typeof window.GCREV.updatePeriodRange !== 'function') return;
  if (!data) return;

  // APIがラベルを返している場合はそれを優先
  const currentLabel = data.current_range_label
    || (data.current_period ? (String(data.current_period.start).replace(/-/g,'/') + ' 〜 ' + String(data.current_period.end).replace(/-/g,'/')) : '');

  const compareLabel = data.compare_range_label
    || (data.comparison_period ? (String(data.comparison_period.start).replace(/-/g,'/') + ' 〜 ' + String(data.comparison_period.end).replace(/-/g,'/')) : '');

  window.GCREV.updatePeriodRange(selectorId, currentLabel, compareLabel);
}

/**
 * データ取得とUI更新（既存API方式を完全踏襲）
 */
async function loadData(period, selectorId) {
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
        
        
        // 期間表示＆レンジ表示を更新（共通）
        updatePeriodDisplay(result);
        updatePeriodRangeFromData(result, selectorId || 'age-period');
console.log('=== API Response ===');
        console.log('result:', result);
        
        if (!result.success) {
            throw new Error(result.message || 'データ取得に失敗しました');
        }
        
        currentData = result.data;
        console.log('age_demographics:', currentData.age_demographics);
        console.log('gender_age_cross:', currentData.gender_age_cross);
        
        // UI更新（年齢別専用）
        updateSummaryCards(currentData);
        updateAgeDistributionChart(currentData);
        updateDetailTable(currentData);
        updateGenderAgeChart(currentData);
        updateGenderDetailTable(currentData);

        
    } catch (error) {
        console.error('データ取得エラー:', error);
        alert('データの取得に失敗しました。もう一度お試しください。');
    } finally {
        hideLoading();
    }
}

/**
 * サマリーカード更新（HTMLテンプレートに忠実に）
 */
function updateSummaryCards(data) {
    const { age_demographics = [] } = data;
    const container = document.getElementById('summaryCards');
    
    if (!Array.isArray(age_demographics) || age_demographics.length === 0) {
        container.innerHTML = `
            <div style="grid-column: 1/-1; padding: 24px; background: #FDF0EE; border: 1px solid rgba(192,57,43,0.15); border-radius: 8px; color: #8E2B20;">
                <strong>⚠️ 年齢別データがありません</strong><br>
                <small>Google Signalsが有効になっていない可能性があります。</small>
            </div>
        `;
        return;
    }
    
    // 合計セッション数を計算
    const totalSessions = age_demographics.reduce((sum, item) => sum + (item.sessions || 0), 0);
    
    // カード生成（上位6件のみ）
    const cards = age_demographics.slice(0, 6).map(age => {
        const sessions = age.sessions || 0;
        const percent = totalSessions > 0 ? (sessions / totalSessions * 100) : 0;
        const changePercent = age.change_percent || 0;
        
        let changeClass = '';
        let changeSymbol = '';
        if (changePercent > 0) {
            changeClass = 'positive';
            changeSymbol = '▲';
        } else if (changePercent < 0) {
            changeClass = 'negative';
            changeSymbol = '▼';
        }
        
        return `
            <div class="summary-card">
                <div class="summary-card-header">${escapeHtml(age.age_range || '不明')}</div>
                <div class="summary-card-value">${formatNumber(sessions)}</div>
                ${changeClass ? `
                <div class="summary-card-change ${changeClass}">
                    <span>${changeSymbol}</span>
                    <span>${formatPercent(Math.abs(changePercent))}</span>
                </div>
                ` : ''}
                <div style="font-size: 13px; color: #666666; margin-top: 8px;">${formatPercent(percent)}</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${percent}%;"></div>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = cards;
}

/**
 * 年齢別分布チャート更新
 */
function updateAgeDistributionChart(data) {
    const { age_demographics = [] } = data;
    
    if (!Array.isArray(age_demographics) || age_demographics.length === 0) {
        console.warn('チャート用の年齢別データがありません');
        return;
    }
    
    const labels = age_demographics.map(item => item.age_range || '不明');
    const sessions = age_demographics.map(item => item.sessions || 0);
    
    const ctx = document.getElementById('ageDistributionChart');
    
    // 既存チャート破棄
    if (ageDistributionChart) {
        ageDistributionChart.destroy();
    }
    
    ageDistributionChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'セッション数',
                data: sessions,
                backgroundColor: 'rgba(59, 130, 246, 0.8)',
                borderColor: '#3D6B6E',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'セッション: ' + formatNumber(context.parsed.y);
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 12 } }
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
            }
        }
    });
}

/**
 * 詳細テーブル更新
 */

/**
 * 性別×年齢クロス分析チャート更新
 */
function updateGenderAgeChart(data) {
    console.log('updateGenderAgeChart called with:', data);
    const { gender_age_cross = [] } = data;
    
    console.log('gender_age_cross:', gender_age_cross);
    console.log('gender_age_cross length:', gender_age_cross.length);
    
    if (!Array.isArray(gender_age_cross) || gender_age_cross.length === 0) {
        console.warn('性別×年齢データがありません');
        console.warn('利用可能なデータキー:', Object.keys(data));
        
        // プレースホルダーを表示
        const canvas = document.getElementById('genderAgeChart');
        const parent = canvas.parentElement;
        parent.innerHTML = `
            <div style="padding: 80px 20px; text-align: center; color: #888888; background: #F7F8F9; border: 2px dashed #E2E6EA; border-radius: 8px;">
                <div style="font-size: 16px; margin-bottom: 12px;">⚠️ 性別×年齢データがありません</div>
                <div style="font-size: 14px; color: #666666;">
                    Google Signalsが有効になっていない可能性があります。<br>
                    GA4管理画面で「データ設定」→「データ収集」→「Googleシグナル」を有効化してください。
                </div>
            </div>
        `;
        return;
    }
    
    // ラベル（年齢層）とデータを準備
    const labels = gender_age_cross.map(item => item.age_range || item.age || '不明');
// sessions をチャート用に抽出（新形式: male.sessions / 旧形式: male）
const maleData = gender_age_cross.map(item => {
    const v = item?.male;
    return (v && typeof v === 'object') ? Number(v.sessions || 0) : Number(v || 0);
});
const femaleData = gender_age_cross.map(item => {
    const v = item?.female;
    return (v && typeof v === 'object') ? Number(v.sessions || 0) : Number(v || 0);
});
const otherData = gender_age_cross.map(item => {
    const v = item?.other ?? item?.unknown;
    return (v && typeof v === 'object') ? Number(v.sessions || 0) : Number(v || 0);
});

    
    const ctx = document.getElementById('genderAgeChart');
    
    // 既存チャート破棄
    if (genderAgeChart) {
        genderAgeChart.destroy();
    }
    
    genderAgeChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '男性',
                    data: maleData,
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderColor: '#3D6B6E',
                    borderWidth: 1
                },
                {
                    label: '女性',
                    data: femaleData,
                    backgroundColor: 'rgba(236, 72, 153, 0.8)',
                    borderColor: '#B5574B',
                    borderWidth: 1
                },
                {
                    label: 'その他',
                    data: otherData,
                    backgroundColor: 'rgba(156, 163, 175, 0.8)',
                    borderColor: '#888888',
                    borderWidth: 1
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
                        boxWidth: 15,
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
                    stacked: true,
                    grid: { display: false },
                    ticks: { font: { size: 12 } }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    grid: { color: '#f3f4f6' },
                    ticks: {
                        font: { size: 11 },
                        callback: function(value) {
                            return formatNumber(value);
                        }
                    }
                }
            }
        }
    });
}

function computeGenderSummary(genderAgeRows) {
    const rows = Array.isArray(genderAgeRows) ? genderAgeRows : [];

    const genders = [
        { key: 'male', label: '男性' },
        { key: 'female', label: '女性' },
        { key: 'other', label: 'その他' },
    ];

    // 集計器（sessions加重平均の分子も保持）
    const agg = {
        male:   { sessions: 0, pv: 0, conv: 0, durSum: 0, bounceSum: 0, engageSum: 0 },
        female: { sessions: 0, pv: 0, conv: 0, durSum: 0, bounceSum: 0, engageSum: 0 },
        other:  { sessions: 0, pv: 0, conv: 0, durSum: 0, bounceSum: 0, engageSum: 0 },
    };

    for (const r of rows) {
        for (const g of ['male','female','other']) {
            const cell = r?.[g];

            // 旧形式（male: 123 など）にも一応対応
            const sessions = (cell && typeof cell === 'object') ? Number(cell.sessions || 0) : Number(cell || 0);
            const pv       = (cell && typeof cell === 'object') ? Number(cell.pv || 0)       : 0;
            const avgSec   = (cell && typeof cell === 'object') ? Number(cell.avg_sec || 0)  : 0;
            const bounce   = (cell && typeof cell === 'object') ? Number(cell.bounce || 0)   : 0;
            const engage   = (cell && typeof cell === 'object') ? Number(cell.engage || 0)   : 0;
            const conv     = (cell && typeof cell === 'object') ? Number(cell.conv || 0)     : 0;

            agg[g].sessions += sessions;
            agg[g].pv       += pv;
            agg[g].conv     += conv;

            agg[g].durSum     += avgSec * sessions;
            agg[g].bounceSum  += bounce * sessions;
            agg[g].engageSum  += engage * sessions;
        }
    }

    const grandSessions =
        agg.male.sessions + agg.female.sessions + agg.other.sessions;

    return genders.map(({key, label}) => {
        const s = agg[key].sessions;
        const rate = grandSessions > 0 ? (s / grandSessions * 100) : 0;

        const avgSec = s > 0 ? (agg[key].durSum / s) : 0;
        const bounce = s > 0 ? (agg[key].bounceSum / s) : 0;
        const engage = s > 0 ? (agg[key].engageSum / s) : 0;

        const conv = agg[key].conv;
        const cvr = s > 0 ? (conv / s * 100) : 0;

        return { label, sessions: s, rate, pv: agg[key].pv, avg_sec: avgSec, bounce, engage, conv, cvr };
    });
}

function updateGenderDetailTable(data) {
    const tbody = document.getElementById('genderDetailTableBody');
    if (!tbody) return;

    const summary = computeGenderSummary(data.gender_age_cross);

    const totalSessions = summary.reduce((sum, r) => sum + (r.sessions || 0), 0);
    if (totalSessions === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align:center;color:#888888;">
                    データがありません
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = summary.map(r => `
        <tr>
            <td>${escapeHtml(r.label)}</td>
            <td>${formatNumber(r.sessions)}</td>
            <td>${formatPercent(r.rate)}</td>
            <td>${formatNumber(r.pv)}</td>
            <td>${formatDuration(r.avg_sec)}</td>
            <td>${formatPercent(r.bounce)}</td>
            <td>${formatPercent(r.engage)}</td>
            <td>${formatNumber(r.conv)}</td>
            <td>${formatPercent(r.cvr)}</td>
        </tr>
    `).join('');
}


function updateDetailTable(data) {
    const { age_demographics = [] } = data;
    const tbody = document.getElementById('detailTableBody');
    
    if (!Array.isArray(age_demographics) || age_demographics.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" style="text-align: center; padding: 24px;">
                    <div style="color: #888888;">データがありません</div>
                    <div style="color: #666666; font-size: 12px; margin-top: 8px;">
                        Google Signalsが有効になっていない可能性があります
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    // 合計セッション数
    const totalSessions = age_demographics.reduce((sum, item) => sum + (item.sessions || 0), 0);
    
    const rows = age_demographics.map(age => {
        const sessions = age.sessions || 0;
        const percent = totalSessions > 0 ? (sessions / totalSessions * 100) : 0;
        
        return `
            <tr>
                <td>${escapeHtml(age.age_range || '不明')}</td>
                <td>${formatNumber(sessions)}</td>
                <td>${formatPercent(percent)}</td>
                <td>${formatNumber(age.pageviews)}</td>
                <td>${formatDuration(age.avg_duration)}</td>
                <td>${formatPercent(age.bounce_rate)}</td>
                <td>${formatPercent(age.engagement_rate)}</td>
                <td>${formatNumber(age.conversions)}</td>
                <td>${formatPercent(age.cvr)}</td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = rows;
}

// ===== ユーティリティ関数 =====

/**
 * 数値フォーマット（カンマ区切り）
 */
function formatNumber(num) {
    if (num === null || num === undefined || isNaN(num)) return '—';
    return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * パーセント表記
 */
function formatPercent(num) {
    if (num === null || num === undefined || isNaN(num)) return '—';
    return num.toFixed(1) + '%';
}

/**
 * 秒数を mm:ss 形式に変換
 */
function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || isNaN(seconds)) return '—';
    const minutes = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

/**
 * HTMLエスケープ
 */
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.toString().replace(/[&<>"']/g, m => map[m]);
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
    if (!currentData || !currentData.age_demographics) {
        alert('エクスポートするデータがありません');
        return;
    }
    
    // 合計セッション数
    const totalSessions = currentData.age_demographics.reduce((sum, item) => sum + (item.sessions || 0), 0);
    
    // CSV生成
    const headers = ['年齢層', 'セッション', '割合', 'PV', '平均滞在時間', '直帰率', 'エンゲージメント率', 'CV数', 'CVR'];
    const rows = currentData.age_demographics.map(age => {
        const sessions = age.sessions || 0;
        const percent = totalSessions > 0 ? (sessions / totalSessions * 100).toFixed(1) : 0;
        
        return [
            age.age_range || '不明',
            sessions,
            percent + '%',
            age.pageviews || 0,
            formatDuration(age.avg_duration),
            formatPercent(age.bounce_rate),
            formatPercent(age.engagement_rate),
            age.conversions || 0,
            formatPercent(age.cvr)
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
    link.setAttribute('download', 'age-analysis-' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

/**
 * 性別別詳細テーブルCSVエクスポート
 */
function exportGenderTableData() {
    if (!currentData || !currentData.gender_age_cross) {
        alert('エクスポートするデータがありません');
        return;
    }

    const summary = computeGenderSummary(currentData.gender_age_cross);
    const totalSessions = summary.reduce((sum, r) => sum + (r.sessions || 0), 0);

    if (totalSessions === 0) {
        alert('エクスポートするデータがありません');
        return;
    }

    const headers = ['性別', 'セッション', '割合', 'PV', '平均滞在時間', '直帰率', 'エンゲージメント率', 'CV数', 'CVR'];
    const rows = summary.map(r => ([
        r.label,
        r.sessions,
        formatPercent(r.rate),
        r.pv,
        formatDuration(r.avg_sec),
        formatPercent(r.bounce),
        formatPercent(r.engage),
        r.conv,
        formatPercent(r.cvr),
    ]));

    let csv = headers.join(',') + '\n';
    rows.forEach(row => { csv += row.join(',') + '\n'; });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'gender-analysis-' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

</script>



<?php get_footer(); ?>