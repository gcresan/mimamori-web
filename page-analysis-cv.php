<?php
/*
Template Name: ゴール分析
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'ゴール分析');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('ゴール分析', '集客のようす'));

get_header();
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
/* page-analysis-cv — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */
</style>

<!-- コンテンツエリア -->
<div class="content-area">
    <!-- ローディングオーバーレイ -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>ゴール分析データを取得中...</p>
        </div>
    </div>

    <!-- 期間表示 -->
    <div class="period-display" id="periodDisplay">データを読み込み中...</div>

    <!-- このページの見方（初心者向け） -->
<?php
set_query_var('analysis_help_key', 'cv');
get_template_part('template-parts/analysis-help');
?>

    <!-- 分析手法の説明（折りたたみ） -->
    <details class="cv-methodology-details">
        <summary>この分析の見方・計算方法</summary>
        <div class="cv-methodology-content">
            <strong>「ゴール」ってなに？</strong>
            <p>ゴールとは、このホームページで「達成したい成果」を示す指標です。問い合わせ・電話・予約・フォーム送信などが含まれます。<br>
            このページでは、その成果が<strong>どこから来た人によるものか</strong>を分析しています。</p>

            <strong>なぜGA4（Googleアナリティクス）の数字と違うの？</strong>
            <p>GA4の自動計測は便利ですが、実際の問い合わせ件数とズレることがよくあります。<br>
            たとえば、同じ人が2回フォームを送信したり、ロボットのアクセスが混ざったりするためです。</p>

            <strong>このページの数字はどう計算しているの？</strong>
            <ul>
                <li>まず、実際に達成されたゴール件数（手動で入力した確定値）を「正しい数」として使います</li>
                <li>次に「検索から何%、SNSから何%」というGA4の割合だけを借りて、確定値を振り分けます</li>
                <li>そのため、<strong>各項目の合計は実際のゴール件数とピッタリ一致</strong>します</li>
            </ul>
            <div class="cv-methodology-caveat">
                手動のゴール入力がまだの場合や、GA4のデータが取れない場合は、GA4の数値をそのまま表示します。
            </div>
        </div>
    </details>

    <!-- ② CVサマリー -->
    <div class="cv-summary-grid" id="cvSummaryGrid">
        <div class="cv-summary-card">
            <div class="cv-summary-label">今月のゴール達成数</div>
            <div class="cv-summary-value" id="cvTotalValue">-<span class="cv-summary-unit">件</span></div>
            <div class="cv-summary-change neutral" id="cvTotalChange">-</div>
            <div class="cv-summary-comment" id="cvTotalComment">データを読み込み中...</div>
        </div>
        <div class="cv-summary-card">
            <div class="cv-summary-label">ゴール達成率</div>
            <div class="cv-summary-value" id="cvRateValue">-<span class="cv-summary-unit">%</span></div>
            <div class="cv-summary-change neutral" id="cvRateChange">-</div>
            <div class="cv-summary-comment" id="cvRateComment">データを読み込み中...</div>
        </div>
        <div class="cv-summary-card highlight" id="cvBestCard">
            <div class="cv-summary-label">最もゴール達成に貢献した項目</div>
            <div class="cv-summary-value" id="cvBestValue" style="font-size: 32px;">-</div>
            <div class="cv-summary-change neutral" id="cvBestBadge">-</div>
            <div class="cv-summary-comment" id="cvBestComment">データを読み込み中...</div>
        </div>
    </div>

    <!-- CV構成比較（手動オーバーライド時のみ表示） -->
    <div class="cv-compare-box" id="cvCompareBox" style="display:none;">
        <div class="cv-compare-item">
            <div class="cv-compare-item-label">📝 手動入力ゴール</div>
            <div class="cv-compare-item-value" id="cvActualTotal">-</div>
        </div>
        <div class="cv-compare-divider"></div>
        <div class="cv-compare-item">
            <div class="cv-compare-item-label">📊 GA4計測ゴール</div>
            <div class="cv-compare-item-value" id="cvPhoneTotal">-</div>
        </div>
        <div class="cv-compare-divider"></div>
        <div class="cv-compare-item">
            <div class="cv-compare-item-label">🎯 確定ゴール合計</div>
            <div class="cv-compare-item-value" id="cvEffectiveTotal" style="color:#568184;">-</div>
        </div>
    </div>

    <!-- ③ 流入元別 × CV分析 -->
    <section class="cv-section" id="sourceSection">
        <div class="cv-analysis-card">
            <div class="cv-analysis-header">
                <div class="cv-analysis-title">
                    🔍 見つけたきっかけ別 × ゴール分析
                    <span class="cv-analysis-badge important">重要</span>
                </div>
                <div class="cv-tab-toggle" id="sourceTabToggle" style="display:none;">
                    <button class="cv-tab-btn active" data-mode="realloc">再配分ゴール</button>
                    <button class="cv-tab-btn" data-mode="ga4">GA4値（参考）</button>
                </div>
            </div>
            <div id="sourceReallocWarning"></div>
            <div class="cv-chart-area">
                <div class="cv-chart-container">
                    <canvas id="sourceCvChart"></canvas>
                </div>
            </div>
            <table class="cv-data-table" id="sourceCvTable">
                <thead>
                    <tr>
                        <th>見つけたきっかけ</th>
                        <th class="number">セッション数</th>
                        <th class="number">ゴール達成数 <span class="help-icon" data-tip="確定ゴール数をGA4の比率で按分した値です">?</span></th>
                        <th class="number">ゴール達成率</th>
                    </tr>
                </thead>
                <tbody id="sourceCvTableBody">
                    <tr><td colspan="4" style="text-align:center;padding:24px;color:#888888;">読み込み中...</td></tr>
                </tbody>
            </table>
            <div class="cv-insight-box" id="sourceCvInsight" style="display:none;">
                <div class="cv-insight-box-title">💡 気づき</div>
                <div class="cv-insight-box-content" id="sourceCvInsightText"></div>
            </div>
        </div>
    </section>

    <!-- ④ デバイス別 × CV分析 -->
    <section class="cv-section" id="deviceSection">
        <div class="cv-analysis-card">
            <div class="cv-analysis-header">
                <div class="cv-analysis-title">
                    📱 デバイス別 × ゴール分析
                    <span class="cv-analysis-badge recommend">改善ポイント</span>
                </div>
                <div class="cv-tab-toggle" id="deviceTabToggle" style="display:none;">
                    <button class="cv-tab-btn active" data-mode="realloc">再配分ゴール</button>
                    <button class="cv-tab-btn" data-mode="ga4">GA4値（参考）</button>
                </div>
            </div>
            <div id="deviceReallocWarning"></div>
            <div class="cv-chart-area">
                <div class="cv-chart-container">
                    <canvas id="deviceCvChart"></canvas>
                </div>
            </div>
            <div class="cv-data-grid" id="deviceCvGrid">
                <div class="cv-data-item">
                    <div class="cv-data-item-label">データを読み込み中...</div>
                    <div class="cv-data-item-value">-</div>
                </div>
            </div>
            <div class="cv-insight-box" id="deviceCvInsight" style="display:none;">
                <div class="cv-insight-box-title">💡 気づき</div>
                <div class="cv-insight-box-content" id="deviceCvInsightText"></div>
            </div>
        </div>
    </section>

</div>

<script>
// ===== グローバル変数 =====
let deviceCvChart = null;
let sourceCvChart = null;
let currentCvData = null;
let currentPeriod = null;

// ===== ユーティリティ: フォールバック警告 =====
function renderFallbackWarning(containerId, realloc) {
    const el = document.getElementById(containerId);
    if (!el) return;
    if (realloc && realloc.status === 'fallback_ga4') {
        el.innerHTML = '<div class="cv-realloc-warning">GA4のゴールデータが0件のため按分ができません。GA4の値をそのまま表示しています。</div>';
    } else {
        el.innerHTML = '';
    }
}

// ===== ユーティリティ: タブ切替セットアップ =====
function setupTabToggle(toggleId, onRealloc, onGa4) {
    const toggle = document.getElementById(toggleId);
    if (!toggle) return;
    toggle.style.display = 'inline-flex';
    const btns = toggle.querySelectorAll('.cv-tab-btn');
    btns.forEach(btn => {
        btn.addEventListener('click', function() {
            btns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            if (this.dataset.mode === 'realloc') onRealloc();
            else onGa4();
        });
    });
}

// ===== 初期化：前月データを固定で取得 =====
loadCvData('prev-month');

// ===== データ取得 =====
async function loadCvData(period) {
    currentPeriod = period;
    showLoading();

    try {
        const apiUrl = '<?php echo esc_url(rest_url('gcrev/v1/analysis/cv')); ?>?period=' + encodeURIComponent(period);
        const response = await fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'
            },
            credentials: 'same-origin'
        });
        const result = await response.json();

        if (!result.success) {
            throw new Error(result.message || 'ゴール分析データの取得に失敗しました');
        }

        currentCvData = result.data;

        // 期間表示更新
        updatePeriodDisplay(currentCvData);

        // UI更新（セクション順: 流入元→デバイス）
        renderCvSummary(currentCvData);
        renderCvCompare(currentCvData);
        renderSourceCv(currentCvData);
        renderDeviceCv(currentCvData);

    } catch (error) {
        console.error('CV分析データ取得エラー:', error);
        alert('ゴール分析データの取得に失敗しました。もう一度お試しください。');
    } finally {
        hideLoading();
    }
}

// ===== 期間表示 =====
function updatePeriodDisplay(data) {
    if (window.GCREV && typeof window.GCREV.updatePeriodDisplay === 'function') {
        window.GCREV.updatePeriodDisplay(data, { periodDisplayId: 'periodDisplay' });
        return;
    }
    const el = document.getElementById('periodDisplay');
    if (!el || !data || !data.current_period) return;
    const c = data.current_period;
    const p = data.comparison_period;
    const fmt = (s, e) => (!s || !e) ? '-' : s.replace(/-/g, '/') + ' 〜 ' + e.replace(/-/g, '/');
    let html =
      '<div class="period-item">' +
        '<span class="period-label-v2">&#x1F4C5; 分析対象期間：</span>' +
        '<span class="period-value">' + fmt(c.start, c.end) + '</span>' +
      '</div>';
    if (p) html +=
      '<div class="period-divider"></div>' +
      '<div class="period-item">' +
        '<span class="period-label-v2">&#x1F4CA; 比較期間：</span>' +
        '<span class="period-value">' + fmt(p.start, p.end) + '</span>' +
      '</div>';
    el.innerHTML = html;
}

// ===== ② CVサマリー =====
function renderCvSummary(data) {
    const eff  = data.effective_cv || {};
    const prev = data.effective_cv_prev || {};
    const total    = eff.total || 0;
    const prevTotal = prev.total || 0;
    const source   = eff.source || 'ga4';

    // CV数
    document.getElementById('cvTotalValue').innerHTML = fmtNum(total) + '<span class="cv-summary-unit">件</span>';
    const changeVal = prevTotal > 0 ? ((total - prevTotal) / prevTotal * 100) : 0;
    const changeEl = document.getElementById('cvTotalChange');
    if (changeVal > 0) {
        changeEl.className = 'cv-summary-change up';
        changeEl.textContent = '↑ +' + changeVal.toFixed(1) + '% (前期比)';
    } else if (changeVal < 0) {
        changeEl.className = 'cv-summary-change down';
        changeEl.textContent = '↓ ' + changeVal.toFixed(1) + '% (前期比)';
    } else {
        changeEl.className = 'cv-summary-change neutral';
        changeEl.textContent = '± 0% (前期比)';
    }
    const commentEl = document.getElementById('cvTotalComment');
    if (changeVal > 10) commentEl.textContent = '前期から大幅に増加。施策の効果が表れています';
    else if (changeVal > 0) commentEl.textContent = '前期からやや増加。良い傾向を維持しています';
    else if (changeVal < -10) commentEl.textContent = '前期から減少傾向。原因の分析が必要です';
    else if (changeVal < 0) commentEl.textContent = '微減です。大きな問題ではないですが注意が必要です';
    else commentEl.textContent = '前期と同水準で推移しています';

    // CV率（全体のセッション数からCV率を計算）
    const allDevices = data.device_cv || [];
    const totalSessions = allDevices.reduce((s, d) => s + (d.sessions || 0), 0);
    const cvr = totalSessions > 0 ? (total / totalSessions * 100) : 0;
    document.getElementById('cvRateValue').innerHTML = cvr.toFixed(1) + '<span class="cv-summary-unit">%</span>';

    // CV率の前期比
    const prevSessions = (data.device_cv_prev || []).reduce((s, d) => s + (d.sessions || 0), 0);
    const prevCvr = prevSessions > 0 ? (prevTotal / prevSessions * 100) : 0;
    const cvrDiff = cvr - prevCvr;
    const cvrChangeEl = document.getElementById('cvRateChange');
    if (cvrDiff > 0) {
        cvrChangeEl.className = 'cv-summary-change up';
        cvrChangeEl.textContent = '↑ +' + cvrDiff.toFixed(1) + 'pt';
    } else if (cvrDiff < 0) {
        cvrChangeEl.className = 'cv-summary-change down';
        cvrChangeEl.textContent = '↓ ' + cvrDiff.toFixed(1) + 'pt';
    } else {
        cvrChangeEl.className = 'cv-summary-change neutral';
        cvrChangeEl.textContent = '± 0pt';
    }
    document.getElementById('cvRateComment').textContent =
        cvr >= 3 ? '業界平均を上回る良好な数値です' :
        cvr >= 1 ? '業界平均程度の数値です' :
        'ゴール達成率改善の余地があります';

    // 最もCV貢献した項目
    const bestSource = findBestCvSource(data);
    document.getElementById('cvBestValue').textContent = bestSource.label;
    const bestBadge = document.getElementById('cvBestBadge');
    bestBadge.className = 'cv-summary-change up';
    bestBadge.style.background = '#568184';
    bestBadge.style.color = '#fff';
    bestBadge.textContent = 'ゴール達成率 ' + bestSource.cvr.toFixed(1) + '%';
    document.getElementById('cvBestComment').textContent = bestSource.comment;
}

function findBestCvSource(data) {
    // 流入元の中でCV率最高を探す（再配分データ使用、最低セッション10以上）
    const sourceRows = (data.source_realloc || {}).rows || [];
    let best = { label: '-', cvr: 0, comment: '' };
    sourceRows.forEach(s => {
        const cvr = s.reallocated_cvr || 0;
        if (s.sessions >= 10 && cvr > best.cvr) {
            best = {
                label: translateChannel(s.label),
                cvr: cvr,
                comment: translateChannel(s.label) + '経由は高いゴール達成率を実現'
            };
        }
    });
    if (best.cvr === 0) {
        // デバイスから探す
        const deviceRows = (data.device_realloc || {}).rows || [];
        deviceRows.forEach(d => {
            const cvr = d.reallocated_cvr || 0;
            if (d.sessions >= 10 && cvr > best.cvr) {
                best = {
                    label: translateDevice(d.label),
                    cvr: cvr,
                    comment: translateDevice(d.label) + 'からのゴール達成率が最も高い'
                };
            }
        });
    }
    return best;
}

// ===== CV比較ボックス =====
function renderCvCompare(data) {
    const eff = data.effective_cv || {};
    if (eff.source === 'ga4' || !eff.source) {
        document.getElementById('cvCompareBox').style.display = 'none';
        return;
    }
    document.getElementById('cvCompareBox').style.display = 'flex';
    const comp = eff.components || {};
    document.getElementById('cvActualTotal').textContent = fmtNum(comp.manual_total || comp.actual_total || 0) + '件';
    document.getElementById('cvPhoneTotal').textContent = fmtNum(comp.ga4_total || comp.phone_total || 0) + '件';
    document.getElementById('cvEffectiveTotal').textContent = fmtNum(eff.total || 0) + '件';
}

// ===== ④ デバイス別 × CV =====
function renderDeviceCv(data) {
    const realloc = data.device_realloc || {};
    const rows = realloc.rows || [];
    const devices = data.device_cv || [];

    renderFallbackWarning('deviceReallocWarning', realloc);

    function renderGrid(useRealloc) {
        const icons = { 'mobile': '📱', 'desktop': '💻', 'tablet': '📟' };
        const colors = { 'mobile': '#568184', 'desktop': '#4E8A6B', 'tablet': '#D4A842' };

        const deviceData = useRealloc ? rows.map(r => ({
            dimension: r.label,
            label: translateDevice(r.label),
            allocatedCv: r.reallocated_count,
            cvr: r.reallocated_cvr,
            sessions: r.sessions,
            ga4_count: r.ga4_count,
        })) : devices.map(d => ({
            dimension: d.dimension,
            label: translateDevice(d.dimension),
            allocatedCv: d.keyEvents || 0,
            cvr: d.cvr || 0,
            sessions: d.sessions,
            ga4_count: d.keyEvents || 0,
        }));

        const gridHtml = deviceData.map(d => {
            const icon = icons[d.dimension.toLowerCase()] || '📱';
            const color = colors[d.dimension.toLowerCase()] || '#568184';
            return `
                <div class="cv-data-item" style="border-left-color:${color}">
                    <div class="cv-data-item-label">${icon} ${d.label}</div>
                    <div class="cv-data-item-value">${fmtNum(d.allocatedCv)}件</div>
                    <div class="cv-data-item-sub">ゴール達成率: ${d.cvr.toFixed(1)}% | セッション: ${fmtNum(d.sessions)}</div>
                </div>
            `;
        }).join('');
        document.getElementById('deviceCvGrid').innerHTML = gridHtml || '<div class="cv-data-item"><div class="cv-data-item-label">データがありません</div></div>';
        renderDeviceCvChart(deviceData);
    }

    renderGrid(true);

    // タブ切替
    setupTabToggle('deviceTabToggle', () => renderGrid(true), () => renderGrid(false));

    // インサイト
    const insightEl = document.getElementById('deviceCvInsight');
    const deviceData = rows.map(r => ({
        dimension: r.label,
        cvr: r.reallocated_cvr,
        sessions: r.sessions,
    }));
    if (deviceData.length > 0) {
        insightEl.style.display = 'block';
        const mobile = deviceData.find(d => d.dimension.toLowerCase() === 'mobile');
        const desktop = deviceData.find(d => d.dimension.toLowerCase() === 'desktop');
        let insight = '';
        if (mobile && desktop) {
            const totalSessions = deviceData.reduce((s,d) => s + d.sessions, 0) || 1;
            const mobileShare = mobile.sessions / totalSessions * 100;
            if (mobileShare > 50 && mobile.cvr < desktop.cvr) {
                insight = `スマホ流入が全体の${mobileShare.toFixed(0)}%を占めますがゴール達成率はPCより低い状態です。スマホでの電話ボタン常時表示やフォーム入力の簡略化でゴール達成率向上が期待できます。`;
            } else if (mobile.cvr > desktop.cvr) {
                insight = `スマホのゴール達成率がPCを上回っています。モバイルファーストの施策が功を奏しています。`;
            } else {
                insight = `PC・スマホともにゴール達成率は同等です。各デバイスに適したCTA配置で更なる改善が見込めます。`;
            }
        } else {
            insight = 'デバイス別のゴール分析結果です。各デバイスに適したCTA配置でゴール達成率改善が見込めます。';
        }
        document.getElementById('deviceCvInsightText').textContent = insight;
    } else {
        insightEl.style.display = 'none';
    }
}

function renderDeviceCvChart(deviceData) {
    const ctx = document.getElementById('deviceCvChart');
    if (deviceCvChart) deviceCvChart.destroy();

    const labels = deviceData.map(d => d.label);
    const cvData = deviceData.map(d => d.allocatedCv);
    const cvrData = deviceData.map(d => d.cvr);
    const bgColors = deviceData.map(d => {
        const c = { 'mobile': '#568184', 'desktop': '#4E8A6B', 'tablet': '#D4A842' };
        return c[d.dimension.toLowerCase()] || '#888888';
    });

    deviceCvChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'ゴール達成数',
                    data: cvData,
                    backgroundColor: bgColors,
                    borderRadius: 8,
                    yAxisID: 'y',
                },
                {
                    label: 'ゴール達成率(%)',
                    data: cvrData,
                    type: 'line',
                    borderColor: '#C95A4F',
                    backgroundColor: 'rgba(201,90,79,0.12)',
                    pointBackgroundColor: '#C95A4F',
                    tension: 0.4,
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 12, padding: 15, font: { size: 13, weight: '600' } } },
            },
            scales: {
                y:  { beginAtZero: true, position: 'left',  title: { display: true, text: 'ゴール達成数' }, grid: { color: '#f3f4f6' } },
                y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'ゴール達成率(%)' }, grid: { drawOnChartArea: false } },
            }
        }
    });
}

// ===== ③ 流入元別 × CV =====
function renderSourceCv(data) {
    const realloc = data.source_realloc || {};
    const rows = realloc.rows || [];
    const sources = data.source_cv || [];

    renderFallbackWarning('sourceReallocWarning', realloc);

    function renderTable(useRealloc) {
        let maxCvr = 0;
        const sourceData = useRealloc ? rows.map(r => {
            const cvr = r.reallocated_cvr || 0;
            if (cvr > maxCvr && r.sessions >= 10) maxCvr = cvr;
            return { dimension: r.label, label: translateChannel(r.label), allocatedCv: r.reallocated_count, cvr, sessions: r.sessions, ga4_count: r.ga4_count };
        }).sort((a, b) => b.allocatedCv - a.allocatedCv) : sources.map(s => {
            const cvr = s.cvr || 0;
            if (cvr > maxCvr && s.sessions >= 10) maxCvr = cvr;
            return { dimension: s.dimension, label: translateChannel(s.dimension), allocatedCv: s.keyEvents || 0, cvr, sessions: s.sessions, ga4_count: s.keyEvents || 0 };
        }).sort((a, b) => b.allocatedCv - a.allocatedCv);

        const tbody = document.getElementById('sourceCvTableBody');
        if (sourceData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#888888;">データがありません</td></tr>';
            return;
        }

        tbody.innerHTML = sourceData.map(s => {
            const hl = s.cvr >= maxCvr * 0.8 && s.cvr > 0 && s.sessions >= 10 ? ' class="row-highlight"' : '';
            return `<tr${hl}>
                <td>${channelIcon(s.dimension)} ${esc(s.label)}</td>
                <td class="number">${fmtNum(s.sessions)}</td>
                <td class="number">${fmtNum(s.allocatedCv)}</td>
                <td class="number">${s.cvr.toFixed(2)}%</td>
            </tr>`;
        }).join('');

        renderSourceCvChart(sourceData.slice(0, 8));
    }

    renderTable(true);

    // タブ切替
    setupTabToggle('sourceTabToggle', () => renderTable(true), () => renderTable(false));

    // インサイト（再配分データで判定）
    const sourceData = rows.map(r => ({
        label: translateChannel(r.label),
        cvr: r.reallocated_cvr || 0,
        sessions: r.sessions,
    }));
    const best = sourceData.filter(s => s.sessions >= 10).sort((a,b) => b.cvr - a.cvr)[0];
    if (best) {
        document.getElementById('sourceCvInsight').style.display = 'block';
        document.getElementById('sourceCvInsightText').textContent =
            `${best.label}経由はゴール達成率${best.cvr.toFixed(2)}%ともっとも効率が良い経路です。この「見つけたきっかけ」を強化すると、効率的なゴール獲得が期待できます。`;
    }
}

function renderSourceCvChart(sourceData) {
    const ctx = document.getElementById('sourceCvChart');
    if (sourceCvChart) sourceCvChart.destroy();

    const bgColors = sourceData.map((_, i) => {
        const palette = ['#568184','#4E8A6B','#A68B5B','#C95A4F','#8B7BAA','#5B8A8E','#6B9E72','#6BAA5E'];
        return palette[i % palette.length];
    });

    sourceCvChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: sourceData.map(s => s.label),
            datasets: [
                {
                    label: 'ゴール達成数',
                    data: sourceData.map(s => s.allocatedCv),
                    backgroundColor: bgColors,
                    borderRadius: 8,
                    yAxisID: 'y',
                },
                {
                    label: 'ゴール達成率(%)',
                    data: sourceData.map(s => s.cvr),
                    type: 'line',
                    borderColor: '#C95A4F',
                    pointBackgroundColor: '#C95A4F',
                    tension: 0.4,
                    yAxisID: 'y1',
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { boxWidth: 12, padding: 15, font: { size: 13, weight: '600' } } } },
            scales: {
                y:  { beginAtZero: true, position: 'left',  title: { display: true, text: 'ゴール達成数' }, grid: { color: '#f3f4f6' } },
                y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'ゴール達成率(%)' }, grid: { drawOnChartArea: false } },
            }
        }
    });
}

// ===== ユーティリティ =====
function fmtNum(num) {
    if (num === null || num === undefined) return '-';
    return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function esc(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function translateDevice(d) {
    const map = { 'mobile': 'スマホ', 'desktop': 'PC', 'tablet': 'タブレット' };
    return map[(d || '').toLowerCase()] || d;
}

function translateChannel(ch) {
    const map = {
        'Organic Search':    '検索（自然）',
        'Direct':            '直接',
        'Organic Social':    'SNS',
        'Paid Social':       'SNS広告',
        'Paid Search':       '検索（広告）',
        'Referral':          '他サイト',
        'Email':             'メール',
        'Display':           'ディスプレイ広告',
        'Organic Maps':      '地図検索',
        'Organic Shopping':  'ショッピング',
        'Unassigned':        '不明',
        'Cross-network':     'クロスネットワーク',
        'Affiliates':        'アフィリエイト',
        '(other)':           'その他',
    };
    return map[ch] || ch;
}

function channelIcon(ch) {
    const map = {
        'Organic Search':   '🔍',
        'Direct':           '🔗',
        'Organic Social':   '📱',
        'Paid Social':      '📱',
        'Paid Search':      '💰',
        'Referral':         '🔗',
        'Email':            '✉️',
        'Display':          '🖼️',
        'Organic Maps':     '📍',
    };
    return map[ch] || '📊';
}

function showLoading() {
    const o = document.getElementById('loadingOverlay');
    if (o) o.classList.add('active');
}
function hideLoading() {
    const o = document.getElementById('loadingOverlay');
    if (o) o.classList.remove('active');
}
</script>

<?php get_footer(); ?>
