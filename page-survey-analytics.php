<?php
/*
Template Name: アンケート集計
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'アンケート集計' );
set_query_var( 'gcrev_page_subtitle', 'アンケートの回答傾向を集計・可視化します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'アンケート集計', 'MEO' ) );

get_header();
?>

<style>
/* ===== page-survey-analytics — Page-specific styles ===== */

.sv-filter-bar {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
}
.sv-filter-bar select,
.sv-filter-bar input[type="date"] {
    padding: 10px 12px; font-size: 14px; font-family: inherit;
    border: 1.5px solid #e5e7eb; border-radius: 8px; background: #f9fafb;
    transition: border-color 0.15s;
}
.sv-filter-bar select:focus,
.sv-filter-bar input[type="date"]:focus {
    outline: none; border-color: var(--mw-primary-blue, #568184); background: #fff;
}
.sv-filter-bar select { min-width: 200px; }
.sv-filter-bar .sv-btn-aggregate {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 20px;
    background: var(--mw-primary-blue, #568184); color: #fff;
    font-size: 14px; font-weight: 600; border: none; border-radius: 8px;
    cursor: pointer; transition: opacity 0.15s;
}
.sv-filter-bar .sv-btn-aggregate:hover { opacity: 0.9; }
.sv-filter-bar .sv-btn-aggregate:disabled { opacity: 0.5; cursor: not-allowed; }

/* KPI row */
.sv-kpi-row {
    display: flex; gap: 16px; flex-wrap: wrap; margin-top: 16px;
}
.sv-kpi-card {
    flex: 1; min-width: 140px;
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    padding: 20px; text-align: center;
}
.sv-kpi-value {
    font-size: 28px; font-weight: 700; color: var(--mw-primary-blue, #568184);
    line-height: 1.2;
}
.sv-kpi-label {
    font-size: 13px; color: #6b7280; margin-top: 4px;
}

/* Question chart items */
.sv-q-chart-item {
    margin-bottom: 24px; padding-bottom: 20px;
    border-bottom: 1px solid #f3f4f6;
}
.sv-q-chart-item:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.sv-q-chart-title {
    font-size: 14px; font-weight: 600; color: var(--mw-text-heading, #1A2F33);
    margin-bottom: 8px;
}
.sv-q-chart-type {
    display: inline-block; font-size: 11px; font-weight: 600;
    padding: 2px 8px; border-radius: 4px; background: #e0e7ff; color: #3730a3;
    margin-left: 8px;
}
.sv-q-chart-count {
    font-size: 13px; color: #6b7280; margin-top: 4px;
}

/* Reuse sv-form-card, sv-form-title, sv-empty from page-review-survey */
.sv-form-card {
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    padding: 24px; margin-bottom: 20px;
}
.sv-form-title {
    font-size: 16px; font-weight: 700; color: var(--mw-text-heading, #1A2F33);
    margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;
}
.sv-empty {
    text-align: center; padding: 48px 20px; color: #888;
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.sv-loading {
    text-align: center; padding: 40px; color: #9ca3af;
}

@media (max-width: 768px) {
    .sv-filter-bar { flex-direction: column; align-items: stretch; }
    .sv-filter-bar select { min-width: 0; }
    .sv-kpi-row { flex-direction: column; }
    .sv-kpi-card { min-width: 0; }
}
</style>

<div class="content-area">

    <!-- Survey selector + date filter -->
    <div class="sv-form-card">
        <div class="sv-filter-bar">
            <select id="sv-analytics-survey">
                <option value="">アンケートを選択...</option>
            </select>
            <input type="date" id="sv-analytics-from">
            <input type="date" id="sv-analytics-to">
            <button type="button" class="sv-btn-aggregate" id="sv-analytics-btn">集計する</button>
        </div>
    </div>

    <!-- Must select survey first message -->
    <div id="sv-analytics-placeholder">
        <div class="sv-empty">アンケートを選択して「集計する」を押してください。</div>
    </div>

    <!-- Analytics content (hidden until loaded) -->
    <div id="sv-analytics-content" style="display:none;">

        <!-- KPI cards -->
        <div class="sv-kpi-row">
            <div class="sv-kpi-card">
                <div class="sv-kpi-value" id="sv-kpi-total">0</div>
                <div class="sv-kpi-label">総回答数</div>
            </div>
            <div class="sv-kpi-card">
                <div class="sv-kpi-value" id="sv-kpi-ai">0</div>
                <div class="sv-kpi-label">AI生成数</div>
            </div>
            <div class="sv-kpi-card">
                <div class="sv-kpi-value" id="sv-kpi-rate">0%</div>
                <div class="sv-kpi-label">採用率</div>
            </div>
            <div class="sv-kpi-card">
                <div class="sv-kpi-value" id="sv-kpi-posted">0</div>
                <div class="sv-kpi-label">投稿済み</div>
            </div>
        </div>

        <!-- Question distributions -->
        <div class="sv-form-card" style="margin-top:16px;">
            <div class="sv-form-title">質問別回答分布</div>
            <div id="sv-question-charts"></div>
        </div>

        <!-- Response trend -->
        <div class="sv-form-card" style="margin-top:16px;">
            <div class="sv-form-title">回答推移</div>
            <div style="height:300px;"><canvas id="sv-trend-chart"></canvas></div>
        </div>

        <!-- AI status chart -->
        <div class="sv-form-card" style="margin-top:16px;">
            <div class="sv-form-title">AI生成ステータス</div>
            <div style="max-width:300px;margin:0 auto;"><canvas id="sv-ai-chart"></canvas></div>
        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
(function() {
    'use strict';

    var API_BASE = <?php echo wp_json_encode( rest_url( 'gcrev/v1/survey/' ) ); ?>;
    var WP_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

    // =====================================================
    // DOM
    // =====================================================
    var surveySelect   = document.getElementById('sv-analytics-survey');
    var dateFrom       = document.getElementById('sv-analytics-from');
    var dateTo         = document.getElementById('sv-analytics-to');
    var btnAggregate   = document.getElementById('sv-analytics-btn');
    var placeholder    = document.getElementById('sv-analytics-placeholder');
    var contentArea    = document.getElementById('sv-analytics-content');
    var questionCharts = document.getElementById('sv-question-charts');

    // Chart instances for cleanup
    var chartInstances = {};

    // =====================================================
    // API helper
    // =====================================================
    function apiGet(path) {
        return fetch(API_BASE + path, {
            headers: { 'X-WP-Nonce': WP_NONCE },
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); });
    }

    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    // =====================================================
    // Chart cleanup
    // =====================================================
    function destroyChart(key) {
        if (chartInstances[key]) {
            chartInstances[key].destroy();
            delete chartInstances[key];
        }
    }

    function destroyAllCharts() {
        Object.keys(chartInstances).forEach(function(key) {
            chartInstances[key].destroy();
        });
        chartInstances = {};
    }

    // =====================================================
    // Color palette
    // =====================================================
    var COLORS = ['#568184', '#A68B5B', '#7B8EAA', '#C95A4F', '#8B7BAA',
                  '#5B9EA6', '#B8976A', '#6B7EA0', '#D97F73', '#A190C0'];

    // =====================================================
    // Load survey filter
    // =====================================================
    function loadSurveyFilter() {
        apiGet('list').then(function(data) {
            var surveys = data.surveys || [];
            surveySelect.innerHTML = '<option value="">アンケートを選択...</option>';
            surveys.forEach(function(s) {
                var opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.title + (s.status === 'draft' ? ' (非公開)' : '');
                surveySelect.appendChild(opt);
            });
        }).catch(function() {
            surveySelect.innerHTML = '<option value="">読み込みに失敗しました</option>';
        });
    }

    // =====================================================
    // Load analytics
    // =====================================================
    function loadAnalytics() {
        var surveyId = surveySelect.value;
        if (!surveyId) {
            placeholder.style.display = 'block';
            contentArea.style.display = 'none';
            return;
        }

        btnAggregate.disabled = true;
        btnAggregate.textContent = '集計中...';
        placeholder.innerHTML = '<div class="sv-loading">集計中...</div>';
        placeholder.style.display = 'block';
        contentArea.style.display = 'none';

        var params = 'analytics?survey_id=' + encodeURIComponent(surveyId);
        if (dateFrom.value) params += '&date_from=' + encodeURIComponent(dateFrom.value);
        if (dateTo.value)   params += '&date_to=' + encodeURIComponent(dateTo.value);

        apiGet(params).then(function(data) {
            btnAggregate.disabled = false;
            btnAggregate.textContent = '集計する';

            if (!data || data.error) {
                placeholder.innerHTML = '<div class="sv-empty">' + esc(data && data.error ? data.error : 'データの取得に失敗しました。') + '</div>';
                placeholder.style.display = 'block';
                contentArea.style.display = 'none';
                return;
            }

            placeholder.style.display = 'none';
            contentArea.style.display = 'block';

            destroyAllCharts();
            renderKPI(data.summary || {});
            renderQuestionCharts(data.questions || []);
            renderTrendChart(data.trend || []);
            renderAiChart(data.summary ? data.summary.ai_by_status : {});
        }).catch(function() {
            btnAggregate.disabled = false;
            btnAggregate.textContent = '集計する';
            placeholder.innerHTML = '<div class="sv-empty">集計に失敗しました。</div>';
            placeholder.style.display = 'block';
            contentArea.style.display = 'none';
        });
    }

    // =====================================================
    // Render KPI cards
    // =====================================================
    function renderKPI(summary) {
        document.getElementById('sv-kpi-total').textContent = formatNumber(summary.total_responses || 0);
        document.getElementById('sv-kpi-ai').textContent = formatNumber(summary.ai_total || 0);

        var rate = 0;
        if (summary.ai_total > 0 && summary.ai_by_status) {
            var adopted = summary.ai_by_status.adopted || 0;
            rate = Math.round((adopted / summary.ai_total) * 100);
        }
        document.getElementById('sv-kpi-rate').textContent = rate + '%';

        var posted = (summary.ai_by_status && summary.ai_by_status.posted) ? summary.ai_by_status.posted : 0;
        document.getElementById('sv-kpi-posted').textContent = formatNumber(posted);
    }

    function formatNumber(n) {
        return Number(n).toLocaleString();
    }

    // =====================================================
    // Render question distribution charts
    // =====================================================
    function renderQuestionCharts(questions) {
        questionCharts.innerHTML = '';

        if (!questions || questions.length === 0) {
            questionCharts.innerHTML = '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:14px;">質問データがありません。</div>';
            return;
        }

        var typeLabels = { checkbox: 'チェック', radio: 'ラジオ', text: 'テキスト', textarea: 'テキストエリア' };

        questions.forEach(function(q, idx) {
            var item = document.createElement('div');
            item.className = 'sv-q-chart-item';

            var titleHtml = '<div class="sv-q-chart-title">'
                + 'Q' + (idx + 1) + '. ' + esc(q.label)
                + '<span class="sv-q-chart-type">' + (typeLabels[q.type] || q.type) + '</span>'
                + '</div>';

            if (q.type === 'text' || q.type === 'textarea') {
                // Text types: show response count only
                var count = q.response_count || 0;
                item.innerHTML = titleHtml
                    + '<div class="sv-q-chart-count">回答数: ' + formatNumber(count) + '件</div>';
            } else {
                // Checkbox/Radio: horizontal bar chart
                var canvasId = 'sv-q-chart-' + idx;
                var dist = q.distribution || {};
                var labels = Object.keys(dist);
                var values = labels.map(function(k) { return dist[k]; });

                var chartHeight = Math.max(120, labels.length * 36 + 40);
                item.innerHTML = titleHtml
                    + '<div style="height:' + chartHeight + 'px;"><canvas id="' + canvasId + '"></canvas></div>';

                questionCharts.appendChild(item);

                // Create chart after DOM insertion
                var ctx = document.getElementById(canvasId);
                if (ctx && labels.length > 0) {
                    chartInstances['q_' + idx] = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: values,
                                backgroundColor: labels.map(function(_, i) {
                                    return COLORS[i % COLORS.length];
                                }),
                                borderRadius: 4,
                                barThickness: 24
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: function(ctx) { return ctx.parsed.x + '件'; }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    ticks: { precision: 0 },
                                    grid: { color: '#f3f4f6' }
                                },
                                y: {
                                    grid: { display: false },
                                    ticks: { font: { size: 12 } }
                                }
                            }
                        }
                    });
                }
                return; // already appended
            }

            questionCharts.appendChild(item);
        });
    }

    // =====================================================
    // Render trend chart
    // =====================================================
    function renderTrendChart(trend) {
        destroyChart('trend');

        var ctx = document.getElementById('sv-trend-chart');
        if (!ctx) return;

        if (!trend || trend.length === 0) {
            ctx.parentNode.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#9ca3af;font-size:14px;">データがありません</div>';
            return;
        }

        var labels = trend.map(function(t) { return t.month || t.label; });
        var values = trend.map(function(t) { return t.count || 0; });

        chartInstances.trend = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: '回答数',
                    data: values,
                    borderColor: '#568184',
                    backgroundColor: 'rgba(86,129,132,0.1)',
                    borderWidth: 2,
                    pointRadius: 4,
                    pointBackgroundColor: '#568184',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return ctx.parsed.y + '件'; }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: '#f3f4f6' },
                        ticks: { font: { size: 12 } }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        grid: { color: '#f3f4f6' }
                    }
                }
            }
        });
    }

    // =====================================================
    // Render AI status doughnut chart
    // =====================================================
    function renderAiChart(aiByStatus) {
        destroyChart('ai');

        var ctx = document.getElementById('sv-ai-chart');
        if (!ctx) return;

        if (!aiByStatus || Object.keys(aiByStatus).length === 0) {
            ctx.parentNode.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:200px;color:#9ca3af;font-size:14px;">データがありません</div>';
            return;
        }

        var statusLabels = {
            generated: '生成済み',
            adopted:   '採用',
            rejected:  '不採用',
            posted:    '投稿済み'
        };
        var statusColors = {
            generated: '#7B8EAA',
            adopted:   '#568184',
            rejected:  '#C95A4F',
            posted:    '#A68B5B'
        };

        var keys   = Object.keys(aiByStatus);
        var labels = keys.map(function(k) { return statusLabels[k] || k; });
        var values = keys.map(function(k) { return aiByStatus[k] || 0; });
        var colors = keys.map(function(k) { return statusColors[k] || '#8B7BAA'; });

        // Skip if all zeros
        var total = values.reduce(function(a, b) { return a + b; }, 0);
        if (total === 0) {
            ctx.parentNode.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:200px;color:#9ca3af;font-size:14px;">AI生成データがありません</div>';
            return;
        }

        chartInstances.ai = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 12 }, padding: 16 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var v = ctx.parsed;
                                var pct = total > 0 ? Math.round((v / total) * 100) : 0;
                                return ctx.label + ': ' + v + '件 (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // =====================================================
    // Events
    // =====================================================
    btnAggregate.addEventListener('click', loadAnalytics);

    // =====================================================
    // Init
    // =====================================================
    loadSurveyFilter();

    // Set default date range: last 3 months
    var now = new Date();
    dateTo.value = now.toISOString().substring(0, 10);
    var threeMonthsAgo = new Date(now.getFullYear(), now.getMonth() - 3, now.getDate());
    dateFrom.value = threeMonthsAgo.toISOString().substring(0, 10);

})();
</script>

<?php get_footer(); ?>
