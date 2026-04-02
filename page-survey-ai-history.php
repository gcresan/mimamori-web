<?php
/*
Template Name: AI生成履歴
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'AI生成履歴' );
set_query_var( 'gcrev_page_subtitle', 'AIが生成した口コミ文の履歴を確認・管理できます。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'AI生成履歴', 'MEO' ) );

get_header();
?>

<style>
/* ===== page-survey-ai-history — Page-specific styles ===== */

/* Filter bar */
.sv-filter-bar {
    display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    padding: 16px 20px; margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.sv-filter-group {
    display: flex; flex-direction: column; gap: 4px;
}
.sv-filter-group.grow { flex: 1; min-width: 140px; }
.sv-filter-label {
    font-size: 12px; font-weight: 600; color: #6b7280;
}
.sv-filter-select, .sv-filter-input {
    padding: 8px 10px; font-size: 13px; font-family: inherit;
    border: 1.5px solid #e5e7eb; border-radius: 6px; background: #f9fafb;
    transition: border-color 0.15s;
}
.sv-filter-select:focus, .sv-filter-input:focus {
    outline: none; border-color: var(--mw-primary-blue, #568184); background: #fff;
}
.sv-filter-btn {
    padding: 8px 16px; font-size: 13px; font-weight: 600;
    background: var(--mw-primary-blue, #568184); color: #fff;
    border: none; border-radius: 6px; cursor: pointer; transition: all 0.25s ease;
    white-space: nowrap;
}
.sv-filter-btn:hover { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.sv-filter-btn:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.sv-filter-btn:focus-visible { outline: 2px solid var(--mw-primary-blue, #568184); outline-offset: 2px; }
.sv-filter-btn-reset {
    padding: 8px 12px; font-size: 13px; font-weight: 600;
    background: #fff; color: #6b7280; border: 1.5px solid #d1d5db;
    border-radius: 6px; cursor: pointer;
    white-space: nowrap;
}
.sv-filter-btn-reset:hover { background: #f9fafb; }

/* Table card */
.sv-table-card {
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    padding: 20px; margin-bottom: 20px;
    overflow-x: auto;
}

/* Table */
.sv-gen-table {
    width: 100%; border-collapse: collapse; font-size: 14px;
}
.sv-gen-table th {
    text-align: left; font-size: 12px; font-weight: 600; color: #6b7280;
    padding: 8px 10px; border-bottom: 2px solid #e5e7eb; white-space: nowrap;
}
.sv-gen-table td {
    padding: 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle;
}
.sv-gen-table tr:last-child td { border-bottom: none; }
.sv-gen-table tbody tr:hover { background: #f9fafb; }

/* Truncated generated text */
.sv-gen-text {
    max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    font-size: 13px; color: var(--mw-text-primary, #263335); cursor: pointer;
}
.sv-gen-text:hover { color: var(--mw-primary-blue, #568184); text-decoration: underline; }

/* Type badge — short / normal */
.sv-type-badge {
    display: inline-block; font-size: 11px; font-weight: 600;
    padding: 2px 8px; border-radius: 4px;
}
.sv-type-badge-short { background: #dbeafe; color: #1d4ed8; }
.sv-type-badge-normal { background: #c7d2fe; color: #3730a3; }

/* Status badge */
.sv-status-badge {
    display: inline-block; font-size: 11px; font-weight: 600;
    padding: 3px 10px; border-radius: 20px;
}
.sv-status-generated { background: #f3f4f6; color: #6b7280; }
.sv-status-adopted { background: #d1fae5; color: #065f46; }
.sv-status-rejected { background: #fee2e2; color: #991b1b; }
.sv-status-posted { background: #dbeafe; color: #1e40af; }

/* Action buttons */
.sv-gen-actions {
    display: flex; gap: 4px; flex-wrap: nowrap;
}
.sv-gen-actions button {
    padding: 4px 10px; font-size: 12px; font-weight: 600;
    border-radius: 4px; cursor: pointer; border: 1px solid;
    background: #fff; transition: background 0.15s; white-space: nowrap;
}
.sv-act-adopt { color: #059669; border-color: #6ee7b7; }
.sv-act-adopt:hover { background: #ecfdf5; }
.sv-act-reject { color: #dc2626; border-color: #fca5a5; }
.sv-act-reject:hover { background: #fef2f2; }
.sv-act-posted { color: #2563eb; border-color: #93c5fd; }
.sv-act-posted:hover { background: #eff6ff; }
.sv-act-detail { color: var(--mw-primary-blue, #568184); border-color: var(--mw-primary-blue, #568184); }
.sv-act-detail:hover { background: #f0f7f7; }
.sv-act-regen { color: #7c3aed; border-color: #c4b5fd; }
.sv-act-regen:hover { background: #f5f3ff; }
.sv-act-regen:disabled { opacity: 0.4; cursor: not-allowed; }

/* Empty */
.sv-empty {
    text-align: center; padding: 48px 20px; color: #888;
}
.sv-empty-icon { font-size: 40px; margin-bottom: 12px; opacity: 0.5; }
.sv-empty-text { font-size: 15px; }

/* Loading */
.sv-loading {
    text-align: center; padding: 40px; color: #9ca3af;
}

/* Pagination */
.sv-pagination {
    display: flex; justify-content: center; align-items: center; gap: 4px;
    margin-top: 16px;
}
.sv-page-btn {
    min-width: 34px; height: 34px; padding: 0 8px;
    font-size: 13px; font-weight: 600;
    border: 1.5px solid #e5e7eb; border-radius: 6px;
    background: #fff; color: #374151; cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
}
.sv-page-btn:hover { background: #f0f7f7; border-color: var(--mw-primary-blue, #568184); }
.sv-page-btn.active {
    background: var(--mw-primary-blue, #568184); color: #fff;
    border-color: var(--mw-primary-blue, #568184);
}
.sv-page-btn:disabled { opacity: 0.4; cursor: default; }
.sv-page-ellipsis {
    min-width: 28px; text-align: center; font-size: 14px; color: #9ca3af;
}

/* Modal overlay */
.sv-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 9000;
    background: rgba(0,0,0,0.4); align-items: center; justify-content: center;
}
.sv-modal-overlay.show { display: flex; }
.sv-modal {
    background: #fff; border-radius: 12px; padding: 24px; width: 90%; max-width: 560px;
    max-height: 80vh; overflow-y: auto; box-shadow: 0 8px 30px rgba(0,0,0,0.15);
}
.sv-modal-title {
    font-size: 16px; font-weight: 700; margin-bottom: 16px;
    color: var(--mw-text-heading, #1A2F33);
}
.sv-modal-section {
    margin-bottom: 14px;
}
.sv-modal-label {
    font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;
}
.sv-modal-text {
    font-size: 14px; color: var(--mw-text-primary, #263335);
    line-height: 1.7; white-space: pre-wrap; word-break: break-word;
    background: #f9fafb; border-radius: 8px; padding: 12px 14px;
}
.sv-modal-meta {
    display: flex; gap: 12px; flex-wrap: wrap; font-size: 13px; color: #6b7280;
}
.sv-modal-actions {
    display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px;
}
.sv-btn-secondary {
    padding: 8px 16px; font-size: 13px; font-weight: 600;
    background: #fff; color: #374151; border: 1.5px solid #d1d5db; border-radius: 6px;
    cursor: pointer;
}
.sv-btn-secondary:hover { background: #f9fafb; }
.sv-btn-link {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 13px; color: var(--mw-primary-blue, #568184);
    text-decoration: none; cursor: pointer;
}
.sv-btn-link:hover { text-decoration: underline; }

/* Toast */
.sv-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    padding: 12px 20px; border-radius: 8px;
    font-size: 14px; font-weight: 600; color: #fff;
    opacity: 0; transform: translateY(10px);
    transition: opacity 0.3s, transform 0.3s;
    pointer-events: none;
}
.sv-toast.show { opacity: 1; transform: translateY(0); }
.sv-toast-success { background: #059669; }
.sv-toast-error { background: #dc2626; }

/* Summary bar */
.sv-summary-bar {
    display: flex; gap: 16px; flex-wrap: wrap;
    font-size: 13px; color: #6b7280; margin-bottom: 8px;
}

@media (max-width: 768px) {
    .sv-filter-bar { flex-direction: column; align-items: stretch; }
    .sv-filter-group.grow { width: 100%; }
    .sv-gen-table { font-size: 13px; }
    .sv-gen-text { max-width: 160px; }
    .sv-gen-actions { flex-wrap: wrap; }
}
</style>

<div class="content-area">

    <!-- Filter bar -->
    <div class="sv-filter-bar">
        <div class="sv-filter-group grow">
            <span class="sv-filter-label">アンケート</span>
            <select class="sv-filter-select" id="sv-filter-survey">
                <option value="">すべて</option>
            </select>
        </div>
        <div class="sv-filter-group">
            <span class="sv-filter-label">ステータス</span>
            <select class="sv-filter-select" id="sv-filter-status">
                <option value="">すべて</option>
                <option value="generated">生成済み</option>
                <option value="adopted">採用</option>
                <option value="rejected">不採用</option>
                <option value="posted">投稿済み</option>
            </select>
        </div>
        <div class="sv-filter-group">
            <span class="sv-filter-label">開始日</span>
            <input type="date" class="sv-filter-input" id="sv-filter-date-from">
        </div>
        <div class="sv-filter-group">
            <span class="sv-filter-label">終了日</span>
            <input type="date" class="sv-filter-input" id="sv-filter-date-to">
        </div>
        <button type="button" class="sv-filter-btn" id="sv-filter-apply">絞り込み</button>
        <button type="button" class="sv-filter-btn-reset" id="sv-filter-reset">リセット</button>
    </div>

    <!-- Table -->
    <div class="sv-table-card">
        <div class="sv-summary-bar" id="sv-summary-bar"></div>
        <div id="sv-table-container">
            <div class="sv-loading">読み込み中...</div>
        </div>
        <div id="sv-pagination-container"></div>
    </div>

</div>

<!-- Toast -->
<div class="sv-toast" id="sv-toast"></div>

<!-- Detail modal -->
<div class="sv-modal-overlay" id="sv-detail-modal">
    <div class="sv-modal">
        <div class="sv-modal-title">AI生成テキスト 詳細</div>
        <div id="sv-detail-body"></div>
        <div class="sv-modal-actions">
            <button type="button" class="sv-btn-secondary" id="sv-detail-close">閉じる</button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var API_BASE = <?php echo wp_json_encode( rest_url( 'gcrev/v1/survey/' ) ); ?>;
    var WP_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

    // =====================================================
    // DOM
    // =====================================================
    var tableContainer   = document.getElementById('sv-table-container');
    var paginationContainer = document.getElementById('sv-pagination-container');
    var summaryBar       = document.getElementById('sv-summary-bar');
    var toastEl          = document.getElementById('sv-toast');
    var detailModal      = document.getElementById('sv-detail-modal');
    var detailBody       = document.getElementById('sv-detail-body');

    var filterSurvey     = document.getElementById('sv-filter-survey');
    var filterStatus     = document.getElementById('sv-filter-status');
    var filterDateFrom   = document.getElementById('sv-filter-date-from');
    var filterDateTo     = document.getElementById('sv-filter-date-to');

    var currentPage = 1;
    var perPage     = 20;
    var genCache    = {}; // id -> full generation object

    var STATUS_LABELS = {
        generated: '生成済み',
        adopted:   '採用',
        rejected:  '不採用',
        posted:    '投稿済み'
    };
    var STATUS_CSS = {
        generated: 'sv-status-generated',
        adopted:   'sv-status-adopted',
        rejected:  'sv-status-rejected',
        posted:    'sv-status-posted'
    };
    var TYPE_LABELS = {
        short:  '短文',
        normal: '通常'
    };
    var TYPE_CSS = {
        short:  'sv-type-badge-short',
        normal: 'sv-type-badge-normal'
    };

    // =====================================================
    // API helpers
    // =====================================================
    function apiGet(path) {
        return fetch(API_BASE + path, {
            headers: { 'X-WP-Nonce': WP_NONCE },
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); });
    }

    function apiPost(path, body) {
        return fetch(API_BASE + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WP_NONCE },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(function(r) { return r.json(); });
    }

    function toast(msg, type) {
        toastEl.textContent = msg;
        toastEl.className = 'sv-toast sv-toast-' + (type || 'success') + ' show';
        setTimeout(function() { toastEl.classList.remove('show'); }, 3000);
    }

    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    function formatDate(dt) {
        if (!dt) return '-';
        return dt.substring(0, 10);
    }

    // =====================================================
    // Load survey filter dropdown
    // =====================================================
    function loadSurveyFilter() {
        apiGet('list').then(function(data) {
            if (!data.surveys || data.surveys.length === 0) return;
            data.surveys.forEach(function(s) {
                var opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.title;
                filterSurvey.appendChild(opt);
            });
        });
    }

    // =====================================================
    // Build query string
    // =====================================================
    function buildQuery(page) {
        var params = [];
        params.push('page=' + (page || 1));
        params.push('per_page=' + perPage);

        var sid = filterSurvey.value;
        if (sid) params.push('survey_id=' + encodeURIComponent(sid));

        var status = filterStatus.value;
        if (status) params.push('status=' + encodeURIComponent(status));

        var dfrom = filterDateFrom.value;
        if (dfrom) params.push('date_from=' + encodeURIComponent(dfrom));

        var dto = filterDateTo.value;
        if (dto) params.push('date_to=' + encodeURIComponent(dto));

        return params.join('&');
    }

    // =====================================================
    // Load generations
    // =====================================================
    function loadGenerations(page) {
        currentPage = page || 1;
        tableContainer.innerHTML = '<div class="sv-loading">読み込み中...</div>';
        paginationContainer.innerHTML = '';

        apiGet('ai-generations?' + buildQuery(currentPage)).then(function(data) {
            if (!data.success) {
                tableContainer.innerHTML = '<div class="sv-empty"><div class="sv-empty-text">読み込みに失敗しました。</div></div>';
                return;
            }
            // Cache
            if (data.items) {
                data.items.forEach(function(g) { genCache[g.id] = g; });
            }
            renderSummary(data);
            renderTable(data.items || []);
            renderPagination(data.total || 0, data.total_pages || 1);
        }).catch(function() {
            tableContainer.innerHTML = '<div class="sv-empty"><div class="sv-empty-text">読み込みに失敗しました。</div></div>';
        });
    }

    // =====================================================
    // Render summary
    // =====================================================
    function renderSummary(data) {
        var total = data.total || 0;
        summaryBar.innerHTML =
            '<span>全 <strong>' + total + '</strong> 件</span>' +
            (data.total_pages > 1 ? '<span>ページ ' + currentPage + ' / ' + data.total_pages + '</span>' : '');
    }

    // =====================================================
    // Render table
    // =====================================================
    function renderTable(items) {
        if (!items || items.length === 0) {
            tableContainer.innerHTML =
                '<div class="sv-empty">' +
                '<div class="sv-empty-icon">&#128221;</div>' +
                '<div class="sv-empty-text">AI生成履歴がありません。</div>' +
                '</div>';
            return;
        }

        var html = '<table class="sv-gen-table"><thead><tr>';
        html += '<th>生成テキスト</th>';
        html += '<th style="width:60px;">タイプ</th>';
        html += '<th>回答者</th>';
        html += '<th>アンケート</th>';
        html += '<th style="width:50px;">Ver</th>';
        html += '<th style="width:70px;">ステータス</th>';
        html += '<th style="width:90px;">生成日</th>';
        html += '<th style="width:200px;">操作</th>';
        html += '</tr></thead><tbody>';

        items.forEach(function(g) {
            var statusLabel = STATUS_LABELS[g.status] || g.status;
            var statusCss   = STATUS_CSS[g.status] || 'sv-status-generated';
            var typeLabel   = TYPE_LABELS[g.review_type] || g.review_type || '-';
            var typeCss     = TYPE_CSS[g.review_type] || 'sv-type-badge-normal';

            html += '<tr data-id="' + g.id + '">';
            html += '<td><span class="sv-gen-text" data-id="' + g.id + '" title="クリックで詳細表示">' + esc(truncate(g.generated_text, 60)) + '</span></td>';
            html += '<td><span class="sv-type-badge ' + typeCss + '">' + esc(typeLabel) + '</span></td>';
            html += '<td>' + esc(g.respondent_name || '-') + '</td>';
            html += '<td>' + esc(truncate(g.survey_title || '', 20)) + '</td>';
            html += '<td style="text-align:center;">' + (g.version || 1) + '</td>';
            html += '<td><span class="sv-status-badge ' + statusCss + '">' + esc(statusLabel) + '</span></td>';
            html += '<td>' + esc(formatDate(g.created_at)) + '</td>';
            html += '<td class="sv-gen-actions">';

            // Status toggle buttons — show only relevant ones
            if (g.status !== 'adopted') {
                html += '<button class="sv-act-adopt" data-id="' + g.id + '" data-status="adopted" title="採用する">採用</button>';
            }
            if (g.status !== 'rejected') {
                html += '<button class="sv-act-reject" data-id="' + g.id + '" data-status="rejected" title="不採用にする">不採用</button>';
            }
            if (g.status !== 'posted') {
                html += '<button class="sv-act-posted" data-id="' + g.id + '" data-status="posted" title="投稿済みにする">投稿済</button>';
            }
            if (g.response_id) {
                html += '<button class="sv-act-regen" data-rid="' + g.response_id + '" title="再生成する">再生成</button>';
            }
            html += '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        tableContainer.innerHTML = html;

        // Bind events
        tableContainer.querySelectorAll('.sv-gen-text').forEach(function(el) {
            el.addEventListener('click', function() {
                showGenDetail(parseInt(el.dataset.id));
            });
        });
        tableContainer.querySelectorAll('.sv-act-adopt, .sv-act-reject, .sv-act-posted').forEach(function(btn) {
            btn.addEventListener('click', function() {
                updateGenStatus(parseInt(btn.dataset.id), btn.dataset.status);
            });
        });
        tableContainer.querySelectorAll('.sv-act-regen').forEach(function(btn) {
            btn.addEventListener('click', function() {
                reGenerate(parseInt(btn.dataset.rid), btn);
            });
        });
    }

    // =====================================================
    // Pagination
    // =====================================================
    function renderPagination(total, totalPages) {
        if (totalPages <= 1) { paginationContainer.innerHTML = ''; return; }

        var html = '<div class="sv-pagination">';

        // Prev
        html += '<button class="sv-page-btn" data-page="' + (currentPage - 1) + '"' + (currentPage <= 1 ? ' disabled' : '') + '>&laquo;</button>';

        // Page buttons — max 7 visible
        var pages = buildPageNumbers(currentPage, totalPages, 7);
        pages.forEach(function(p) {
            if (p === '...') {
                html += '<span class="sv-page-ellipsis">...</span>';
            } else {
                html += '<button class="sv-page-btn' + (p === currentPage ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
            }
        });

        // Next
        html += '<button class="sv-page-btn" data-page="' + (currentPage + 1) + '"' + (currentPage >= totalPages ? ' disabled' : '') + '>&raquo;</button>';
        html += '</div>';

        paginationContainer.innerHTML = html;

        paginationContainer.querySelectorAll('.sv-page-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (btn.disabled) return;
                var p = parseInt(btn.dataset.page);
                if (p >= 1 && p <= totalPages) {
                    loadGenerations(p);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        });
    }

    function buildPageNumbers(current, total, maxVisible) {
        if (total <= maxVisible) {
            var arr = [];
            for (var i = 1; i <= total; i++) arr.push(i);
            return arr;
        }

        var pages = [];
        var half = Math.floor(maxVisible / 2);
        var start = Math.max(2, current - half + 1);
        var end   = Math.min(total - 1, current + half - 1);

        // Adjust range
        if (current <= half + 1) {
            end = maxVisible - 2;
        }
        if (current >= total - half) {
            start = total - maxVisible + 3;
        }

        pages.push(1);
        if (start > 2) pages.push('...');
        for (var j = start; j <= end; j++) pages.push(j);
        if (end < total - 1) pages.push('...');
        pages.push(total);

        return pages;
    }

    // =====================================================
    // Detail modal
    // =====================================================
    function showGenDetail(genId) {
        var g = genCache[genId];
        if (!g) { toast('データが見つかりません', 'error'); return; }

        var statusLabel = STATUS_LABELS[g.status] || g.status;
        var statusCss   = STATUS_CSS[g.status] || 'sv-status-generated';
        var typeLabel   = TYPE_LABELS[g.review_type] || g.review_type || '-';
        var typeCss     = TYPE_CSS[g.review_type] || 'sv-type-badge-normal';

        var html = '';
        html += '<div class="sv-modal-section">';
        html += '<div class="sv-modal-label">生成テキスト</div>';
        html += '<div class="sv-modal-text">' + esc(g.generated_text || '') + '</div>';
        html += '</div>';

        html += '<div class="sv-modal-section">';
        html += '<div class="sv-modal-meta">';
        html += '<span>タイプ: <span class="sv-type-badge ' + typeCss + '">' + esc(typeLabel) + '</span></span>';
        html += '<span>ステータス: <span class="sv-status-badge ' + statusCss + '">' + esc(statusLabel) + '</span></span>';
        html += '<span>バージョン: ' + (g.version || 1) + '</span>';
        html += '</div>';
        html += '</div>';

        html += '<div class="sv-modal-section">';
        html += '<div class="sv-modal-meta">';
        html += '<span>回答者: ' + esc(g.respondent_name || '-') + '</span>';
        html += '<span>アンケート: ' + esc(g.survey_title || '-') + '</span>';
        html += '<span>生成日: ' + esc(formatDate(g.created_at)) + '</span>';
        html += '</div>';
        html += '</div>';

        if (g.generation_params) {
            html += '<div class="sv-modal-section">';
            html += '<div class="sv-modal-label">生成パラメータ</div>';
            html += '<div class="sv-modal-text" style="font-size:12px;">';
            if (typeof g.generation_params === 'object') {
                html += esc(JSON.stringify(g.generation_params, null, 2));
            } else {
                html += esc(String(g.generation_params));
            }
            html += '</div>';
            html += '</div>';
        }

        if (g.response_id) {
            html += '<div class="sv-modal-section">';
            html += '<a class="sv-btn-link" href="' + esc(g.response_url || '#') + '" target="_blank">&#8599; 元回答を見る</a>';
            html += '</div>';
        }

        detailBody.innerHTML = html;
        detailModal.classList.add('show');
    }

    document.getElementById('sv-detail-close').addEventListener('click', function() {
        detailModal.classList.remove('show');
    });
    detailModal.addEventListener('click', function(e) {
        if (e.target === detailModal) detailModal.classList.remove('show');
    });

    // =====================================================
    // Update generation status
    // =====================================================
    function updateGenStatus(genId, newStatus) {
        var label = STATUS_LABELS[newStatus] || newStatus;
        apiPost('ai-generation/status', {
            generation_id: genId,
            status: newStatus
        }).then(function(res) {
            if (res.success) {
                toast(label + ' に変更しました');
                // Update cache
                if (genCache[genId]) genCache[genId].status = newStatus;
                loadGenerations(currentPage);
            } else {
                toast(res.message || 'ステータス変更に失敗しました', 'error');
            }
        }).catch(function() {
            toast('通信エラーが発生しました', 'error');
        });
    }

    // =====================================================
    // Re-generate
    // =====================================================
    function reGenerate(responseId, btn) {
        if (!confirm('この回答から口コミ文を再生成しますか？')) return;

        btn.disabled = true;
        var origText = btn.textContent;
        btn.textContent = '生成中...';

        apiPost('ai-generate', {
            response_id: responseId
        }).then(function(res) {
            btn.disabled = false;
            btn.textContent = origText;
            if (res.success) {
                toast('再生成が完了しました');
                loadGenerations(currentPage);
            } else {
                toast(res.message || '再生成に失敗しました', 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.textContent = origText;
            toast('通信エラーが発生しました', 'error');
        });
    }

    // =====================================================
    // Filter events
    // =====================================================
    document.getElementById('sv-filter-apply').addEventListener('click', function() {
        loadGenerations(1);
    });
    document.getElementById('sv-filter-reset').addEventListener('click', function() {
        filterSurvey.value = '';
        filterStatus.value = '';
        filterDateFrom.value = '';
        filterDateTo.value = '';
        loadGenerations(1);
    });

    // =====================================================
    // Init
    // =====================================================
    loadSurveyFilter();
    loadGenerations(1);
})();
</script>

<?php get_footer(); ?>
