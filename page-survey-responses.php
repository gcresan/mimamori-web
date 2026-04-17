<?php
/*
Template Name: 回答履歴
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

mimamori_guard_meo_access();

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

set_query_var( 'gcrev_page_title', '回答履歴' );
set_query_var( 'gcrev_page_subtitle', 'アンケートの回答内容を確認・管理できます。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '回答履歴', 'MEO' ) );

get_header();
?>

<style>
/* ===== page-survey-responses — Page-specific styles ===== */

.sv-filter-bar {
    display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end;
}
.sv-filter-select, .sv-filter-input {
    padding: 8px 12px; font-size: 13px; font-family: inherit;
    border: 1.5px solid #e5e7eb; border-radius: 8px; background: #f9fafb;
    transition: border-color 0.15s; min-width: 0;
}
.sv-filter-select:focus, .sv-filter-input:focus {
    outline: none; border-color: var(--mw-primary-blue, #568184); background: #fff;
}
.sv-filter-select { min-width: 140px; }
.sv-filter-input { min-width: 100px; }
.sv-filter-input[type="date"] { min-width: 130px; }
.sv-filter-btn {
    padding: 8px 20px; font-size: 13px; font-weight: 600;
    background: var(--mw-primary-blue, #568184); color: #fff;
    border: none; border-radius: 8px; cursor: pointer; transition: all 0.25s ease;
    white-space: nowrap;
}
.sv-filter-btn:hover { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.sv-filter-btn:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.sv-filter-btn:focus-visible { outline: 2px solid var(--mw-primary-blue, #568184); outline-offset: 2px; }

/* Form card (reuse) */
.sv-form-card {
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    padding: 24px; margin-bottom: 20px;
}

/* Response table */
.sv-resp-table {
    width: 100%; border-collapse: collapse; font-size: 14px;
}
.sv-resp-table th {
    text-align: left; font-size: 12px; font-weight: 600; color: #6b7280;
    padding: 8px 10px; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.sv-resp-table td {
    padding: 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle;
}
.sv-resp-table tr:last-child td { border-bottom: none; }
.sv-resp-table tbody tr { transition: background 0.1s; }
.sv-resp-table tbody tr:hover { background: #f9fafb; }
.sv-resp-date { font-size: 13px; color: #6b7280; white-space: nowrap; }
.sv-resp-survey { font-size: 13px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sv-resp-respondent { font-size: 13px; }
.sv-resp-actions { display: flex; gap: 6px; align-items: center; }

/* Status badge */
.sv-status-badge {
    display: inline-block; font-size: 11px; font-weight: 600;
    padding: 2px 10px; border-radius: 20px; white-space: nowrap;
}
.sv-status-new { background: #f3f4f6; color: #6b7280; }
.sv-status-reviewed { background: #dbeafe; color: #1d4ed8; }
.sv-status-utilized { background: #d1fae5; color: #065f46; }

/* AI badge */
.sv-ai-badge {
    display: inline-block; font-size: 11px; font-weight: 600;
    padding: 2px 8px; border-radius: 4px; white-space: nowrap;
}
.sv-ai-badge-yes { background: #dbeafe; color: #1d4ed8; }
.sv-ai-badge-no { background: #f3f4f6; color: #9ca3af; }

/* Detail button */
.sv-btn-detail {
    padding: 5px 12px; font-size: 12px; font-weight: 600;
    background: #fff; color: var(--mw-primary-blue, #568184);
    border: 1.5px solid var(--mw-primary-blue, #568184); border-radius: 6px;
    cursor: pointer; transition: background 0.15s; white-space: nowrap;
}
.sv-btn-detail:hover { background: #f0f7f7; }

/* Status change select in table */
.sv-status-select {
    padding: 4px 8px; font-size: 12px; border: 1px solid #e5e7eb;
    border-radius: 6px; background: #fff; cursor: pointer;
}

/* Pagination */
.sv-pagination {
    display: flex; gap: 4px; justify-content: center; align-items: center;
    padding: 16px 0 0; flex-wrap: wrap;
}
.sv-page-btn {
    padding: 6px 12px; font-size: 13px; font-weight: 600;
    background: #fff; color: #374151; border: 1px solid #e5e7eb; border-radius: 6px;
    cursor: pointer; transition: background 0.15s;
}
.sv-page-btn:hover { background: #f9fafb; }
.sv-page-btn.active {
    background: var(--mw-primary-blue, #568184); color: #fff;
    border-color: var(--mw-primary-blue, #568184);
}
.sv-page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.sv-page-info { font-size: 13px; color: #6b7280; margin: 0 8px; }

/* Modal overlay */
.sv-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 9000;
    background: rgba(0,0,0,0.4); align-items: center; justify-content: center;
}
.sv-modal-overlay.show { display: flex; }
.sv-modal {
    background: #fff; border-radius: 12px; padding: 24px; width: 90%; max-width: 700px;
    max-height: 85vh; overflow-y: auto; box-shadow: 0 8px 30px rgba(0,0,0,0.15);
}
.sv-modal-title {
    font-size: 16px; font-weight: 700; margin-bottom: 16px;
    color: var(--mw-text-heading, #1A2F33);
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

/* Detail sections */
.sv-detail-section {
    margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f3f4f6;
}
.sv-detail-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.sv-detail-section-title {
    font-size: 14px; font-weight: 700; color: var(--mw-text-heading, #1A2F33);
    margin-bottom: 10px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;
}
.sv-detail-meta {
    display: flex; gap: 16px; flex-wrap: wrap; font-size: 13px; color: #6b7280; margin-bottom: 12px;
}
.sv-detail-meta-item { display: flex; align-items: center; gap: 4px; }

/* Answer item */
.sv-answer-item {
    background: #f9fafb; border-radius: 8px; padding: 12px; margin-bottom: 8px;
}
.sv-answer-item:last-child { margin-bottom: 0; }
.sv-answer-q {
    font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 4px;
}
.sv-answer-a {
    font-size: 14px; color: var(--mw-text-primary, #263335); line-height: 1.6;
}

/* AI generation item */
.sv-ai-gen-item {
    background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px;
    padding: 12px; margin-bottom: 8px;
}
.sv-ai-gen-item:last-child { margin-bottom: 0; }
.sv-ai-gen-header {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;
}
.sv-ai-gen-type-badge {
    font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px;
    background: #e0e7ff; color: #3730a3;
}
.sv-ai-gen-status-badge {
    font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px;
}
.sv-ai-gen-status-generated { background: #fef3c7; color: #92400e; }
.sv-ai-gen-status-adopted { background: #d1fae5; color: #065f46; }
.sv-ai-gen-status-rejected { background: #fee2e2; color: #991b1b; }
.sv-ai-gen-status-posted { background: #dbeafe; color: #1d4ed8; }
.sv-ai-gen-version { font-size: 11px; color: #9ca3af; }
.sv-ai-gen-text {
    font-size: 14px; color: var(--mw-text-primary, #263335); line-height: 1.6;
    white-space: pre-wrap;
}
.sv-ai-gen-actions {
    display: flex; gap: 6px; margin-top: 8px;
}
.sv-ai-gen-btn {
    padding: 4px 12px; font-size: 12px; font-weight: 600; border-radius: 4px;
    cursor: pointer; border: 1px solid; background: #fff;
}
.sv-ai-gen-btn-adopt { color: #059669; border-color: #059669; }
.sv-ai-gen-btn-adopt:hover { background: #f0fdf4; }
.sv-ai-gen-btn-reject { color: #dc2626; border-color: #fca5a5; }
.sv-ai-gen-btn-reject:hover { background: #fef2f2; }

/* Notes area */
.sv-notes-area {
    width: 100%; padding: 10px 12px; font-size: 14px; font-family: inherit;
    border: 1.5px solid #e5e7eb; border-radius: 8px; background: #f9fafb;
    min-height: 80px; resize: vertical; line-height: 1.6;
    transition: border-color 0.15s;
}
.sv-notes-area:focus {
    outline: none; border-color: var(--mw-primary-blue, #568184); background: #fff;
}
.sv-btn-save-notes {
    padding: 6px 16px; font-size: 13px; font-weight: 600;
    background: var(--mw-primary-blue, #568184); color: #fff;
    border: none; border-radius: 6px; cursor: pointer; margin-top: 8px;
    transition: all 0.25s ease;
}
.sv-btn-save-notes:hover { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.sv-btn-save-notes:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.sv-btn-save-notes:focus-visible { outline: 2px solid var(--mw-primary-blue, #568184); outline-offset: 2px; }
.sv-btn-save-notes:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

/* Detail status change */
.sv-detail-status-row {
    display: flex; gap: 8px; align-items: center;
}
.sv-detail-status-select {
    padding: 6px 12px; font-size: 13px; border: 1.5px solid #e5e7eb;
    border-radius: 8px; background: #f9fafb; cursor: pointer;
}
.sv-detail-status-select:focus {
    outline: none; border-color: var(--mw-primary-blue, #568184);
}
.sv-btn-status-save {
    padding: 6px 14px; font-size: 13px; font-weight: 600;
    background: var(--mw-primary-blue, #568184); color: #fff;
    border: none; border-radius: 6px; cursor: pointer; transition: all 0.25s ease;
}
.sv-btn-status-save:hover { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.sv-btn-status-save:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.sv-btn-status-save:focus-visible { outline: 2px solid var(--mw-primary-blue, #568184); outline-offset: 2px; }

/* Empty / Loading */
.sv-empty {
    text-align: center; padding: 48px 20px; color: #888;
}
.sv-empty-icon { font-size: 40px; margin-bottom: 12px; opacity: 0.5; }
.sv-empty-text { font-size: 15px; }
.sv-loading {
    text-align: center; padding: 40px; color: #9ca3af;
}

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

@media (max-width: 768px) {
    .sv-filter-bar { flex-direction: column; }
    .sv-filter-select, .sv-filter-input { width: 100%; }
    .sv-filter-btn { width: 100%; }
    .sv-resp-table { font-size: 13px; }
    .sv-resp-table th, .sv-resp-table td { padding: 8px 6px; }
    .sv-resp-survey { max-width: 120px; }
    .sv-modal { padding: 16px; }
    .sv-ai-gen-actions { flex-wrap: wrap; }
    .sv-detail-status-row { flex-wrap: wrap; }
}
</style>

<div class="content-area">

    <!-- Filter bar -->
    <div class="sv-form-card">
        <div class="sv-filter-bar">
            <select id="sv-filter-survey" class="sv-filter-select">
                <option value="0">すべてのアンケート</option>
            </select>
            <select id="sv-filter-status" class="sv-filter-select">
                <option value="">すべてのステータス</option>
                <option value="new">未対応</option>
                <option value="reviewed">確認済み</option>
                <option value="utilized">活用済み</option>
            </select>
            <select id="sv-filter-ai" class="sv-filter-select">
                <option value="">AI生成状態</option>
                <option value="has_generated">AI生成あり</option>
                <option value="none">AI生成なし</option>
            </select>
            <input type="date" id="sv-filter-from" class="sv-filter-input" title="開始日">
            <input type="date" id="sv-filter-to" class="sv-filter-input" title="終了日">
            <input type="text" id="sv-filter-keyword" class="sv-filter-input" placeholder="キーワード検索" style="min-width:150px;">
            <button type="button" id="sv-filter-btn" class="sv-filter-btn">検索</button>
        </div>
    </div>

    <!-- Response table -->
    <div class="sv-form-card" style="margin-top:12px;">
        <div id="sv-resp-container">
            <div class="sv-loading">読み込み中...</div>
        </div>
        <div class="sv-pagination" id="sv-pagination"></div>
    </div>

</div>

<!-- Detail modal -->
<div class="sv-modal-overlay" id="sv-detail-modal">
    <div class="sv-modal" style="max-width:700px;">
        <div class="sv-modal-title" id="sv-detail-title">回答詳細</div>
        <div id="sv-detail-body"></div>
        <div class="sv-modal-actions">
            <button type="button" class="sv-btn-secondary" id="sv-detail-close">閉じる</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="sv-toast" id="sv-toast"></div>

<script>
(function() {
    'use strict';

    var API_BASE = <?php echo wp_json_encode( rest_url( 'gcrev/v1/survey/' ) ); ?>;
    var WP_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

    // =====================================================
    // DOM
    // =====================================================
    var respContainer = document.getElementById('sv-resp-container');
    var paginationEl  = document.getElementById('sv-pagination');
    var detailModal   = document.getElementById('sv-detail-modal');
    var detailBody    = document.getElementById('sv-detail-body');
    var detailTitle   = document.getElementById('sv-detail-title');
    var toastEl       = document.getElementById('sv-toast');

    var currentPage = 1;
    var perPage     = 20;

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

    // =====================================================
    // Status / AI labels
    // =====================================================
    var statusLabels = { 'new': '未対応', 'reviewed': '確認済み', 'utilized': '活用済み' };
    var statusClasses = { 'new': 'sv-status-new', 'reviewed': 'sv-status-reviewed', 'utilized': 'sv-status-utilized' };
    var aiGenStatusLabels = { 'generated': '生成済み', 'adopted': '採用', 'rejected': '不採用', 'posted': '投稿済み' };
    var aiGenStatusClasses = {
        'generated': 'sv-ai-gen-status-generated',
        'adopted': 'sv-ai-gen-status-adopted',
        'rejected': 'sv-ai-gen-status-rejected',
        'posted': 'sv-ai-gen-status-posted'
    };

    // =====================================================
    // Load survey filter dropdown
    // =====================================================
    function loadSurveyFilter() {
        apiGet('list').then(function(data) {
            var sel = document.getElementById('sv-filter-survey');
            sel.innerHTML = '<option value="0">すべてのアンケート</option>';
            (data.surveys || []).forEach(function(s) {
                sel.innerHTML += '<option value="' + s.id + '">' + esc(s.title) + '</option>';
            });
        });
    }

    // =====================================================
    // Load responses
    // =====================================================
    function loadResponses(page) {
        currentPage = page || 1;
        respContainer.innerHTML = '<div class="sv-loading">読み込み中...</div>';
        paginationEl.innerHTML = '';

        var params = new URLSearchParams({
            survey_id: document.getElementById('sv-filter-survey').value,
            status:    document.getElementById('sv-filter-status').value,
            ai_status: document.getElementById('sv-filter-ai').value,
            date_from: document.getElementById('sv-filter-from').value,
            date_to:   document.getElementById('sv-filter-to').value,
            keyword:   document.getElementById('sv-filter-keyword').value,
            page:      currentPage,
            per_page:  perPage
        });

        apiGet('responses?' + params.toString()).then(function(data) {
            renderResponseTable(data);
        }).catch(function() {
            respContainer.innerHTML = '<div class="sv-empty"><div class="sv-empty-text">読み込みに失敗しました。</div></div>';
        });
    }

    // =====================================================
    // Render response table
    // =====================================================
    function renderResponseTable(data) {
        var responses = data.responses || [];
        var total      = data.total || 0;
        var totalPages = data.total_pages || 1;

        if (responses.length === 0) {
            respContainer.innerHTML =
                '<div class="sv-empty">' +
                '<div class="sv-empty-icon">&#128196;</div>' +
                '<div class="sv-empty-text">回答が見つかりませんでした。</div>' +
                '</div>';
            paginationEl.innerHTML = '';
            return;
        }

        var html = '<table class="sv-resp-table"><thead><tr>';
        html += '<th>日時</th>';
        html += '<th>アンケート名</th>';
        html += '<th>回答者</th>';
        html += '<th>ステータス</th>';
        html += '<th>AI</th>';
        html += '<th>操作</th>';
        html += '</tr></thead><tbody>';

        responses.forEach(function(r) {
            var st = r.status || 'new';
            var aiCount = r.ai_generation_count || 0;
            var dateStr = r.created_at ? r.created_at.substring(0, 16).replace('T', ' ') : '-';

            html += '<tr>';
            html += '<td class="sv-resp-date">' + esc(dateStr) + '</td>';
            html += '<td class="sv-resp-survey" title="' + esc(r.survey_title || '') + '">' + esc(r.survey_title || '-') + '</td>';
            html += '<td class="sv-resp-respondent">' + esc(r.respondent_name || '匿名') + '</td>';
            html += '<td><span class="sv-status-badge ' + (statusClasses[st] || 'sv-status-new') + '">' + (statusLabels[st] || st) + '</span></td>';
            html += '<td>';
            if (aiCount > 0) {
                html += '<span class="sv-ai-badge sv-ai-badge-yes">AI ' + aiCount + '件</span>';
            } else {
                html += '<span class="sv-ai-badge sv-ai-badge-no">なし</span>';
            }
            html += '</td>';
            html += '<td class="sv-resp-actions">';
            html += '<button class="sv-btn-detail" data-id="' + r.id + '">詳細</button>';
            html += '<select class="sv-status-select" data-id="' + r.id + '">';
            html += '<option value="new"' + (st === 'new' ? ' selected' : '') + '>未対応</option>';
            html += '<option value="reviewed"' + (st === 'reviewed' ? ' selected' : '') + '>確認済み</option>';
            html += '<option value="utilized"' + (st === 'utilized' ? ' selected' : '') + '>活用済み</option>';
            html += '</select>';
            html += '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        respContainer.innerHTML = html;

        // Bind detail buttons
        respContainer.querySelectorAll('.sv-btn-detail').forEach(function(btn) {
            btn.addEventListener('click', function() {
                showDetail(parseInt(btn.dataset.id));
            });
        });

        // Bind status selects
        respContainer.querySelectorAll('.sv-status-select').forEach(function(sel) {
            sel.addEventListener('change', function() {
                updateStatus(parseInt(sel.dataset.id), sel.value);
            });
        });

        // Pagination
        renderPagination(total, totalPages);
    }

    // =====================================================
    // Render pagination
    // =====================================================
    function renderPagination(total, totalPages) {
        if (totalPages <= 1) { paginationEl.innerHTML = ''; return; }

        var html = '';

        // Previous
        html += '<button class="sv-page-btn" data-page="' + (currentPage - 1) + '"' +
            (currentPage <= 1 ? ' disabled' : '') + '>&laquo; 前へ</button>';

        // Page numbers — max 7 visible
        var startPage = Math.max(1, currentPage - 3);
        var endPage   = Math.min(totalPages, startPage + 6);
        if (endPage - startPage < 6) {
            startPage = Math.max(1, endPage - 6);
        }

        if (startPage > 1) {
            html += '<button class="sv-page-btn" data-page="1">1</button>';
            if (startPage > 2) {
                html += '<span class="sv-page-info">...</span>';
            }
        }

        for (var i = startPage; i <= endPage; i++) {
            html += '<button class="sv-page-btn' + (i === currentPage ? ' active' : '') +
                '" data-page="' + i + '">' + i + '</button>';
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += '<span class="sv-page-info">...</span>';
            }
            html += '<button class="sv-page-btn" data-page="' + totalPages + '">' + totalPages + '</button>';
        }

        // Next
        html += '<button class="sv-page-btn" data-page="' + (currentPage + 1) + '"' +
            (currentPage >= totalPages ? ' disabled' : '') + '>次へ &raquo;</button>';

        // Total
        html += '<span class="sv-page-info">全 ' + total + ' 件</span>';

        paginationEl.innerHTML = html;

        // Bind page buttons
        paginationEl.querySelectorAll('.sv-page-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (btn.disabled) return;
                loadResponses(parseInt(btn.dataset.page));
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    }

    // =====================================================
    // Show detail modal
    // =====================================================
    function showDetail(responseId) {
        detailBody.innerHTML = '<div class="sv-loading">読み込み中...</div>';
        detailTitle.textContent = '回答詳細';
        detailModal.classList.add('show');

        apiGet('response/detail?response_id=' + responseId).then(function(data) {
            if (!data.response) {
                detailBody.innerHTML = '<div class="sv-empty-text">データの取得に失敗しました。</div>';
                return;
            }
            renderDetail(data);
        }).catch(function() {
            detailBody.innerHTML = '<div class="sv-empty-text">読み込みに失敗しました。</div>';
        });
    }

    function renderDetail(data) {
        var r = data.response;
        var answers = data.answers || [];
        var aiGens  = data.ai_generations || [];
        var st = r.status || 'new';
        var dateStr = r.created_at ? r.created_at.substring(0, 16).replace('T', ' ') : '-';

        detailTitle.textContent = '回答詳細 — ' + esc(r.survey_title || '');

        var html = '';

        // Meta info
        html += '<div class="sv-detail-section">';
        html += '<div class="sv-detail-meta">';
        html += '<div class="sv-detail-meta-item"><strong>回答日時:</strong> ' + esc(dateStr) + '</div>';
        html += '<div class="sv-detail-meta-item"><strong>回答者:</strong> ' + esc(r.respondent_name || '匿名') + '</div>';
        if (r.respondent_email) {
            html += '<div class="sv-detail-meta-item"><strong>メール:</strong> ' + esc(r.respondent_email) + '</div>';
        }
        html += '</div>';

        // Status changer
        html += '<div class="sv-detail-status-row">';
        html += '<strong style="font-size:13px;">ステータス:</strong> ';
        html += '<select class="sv-detail-status-select" id="sv-detail-status-sel">';
        html += '<option value="new"' + (st === 'new' ? ' selected' : '') + '>未対応</option>';
        html += '<option value="reviewed"' + (st === 'reviewed' ? ' selected' : '') + '>確認済み</option>';
        html += '<option value="utilized"' + (st === 'utilized' ? ' selected' : '') + '>活用済み</option>';
        html += '</select>';
        html += '<button class="sv-btn-status-save" id="sv-detail-status-save" data-id="' + r.id + '">変更</button>';
        html += '</div>';
        html += '</div>';

        // Answers
        html += '<div class="sv-detail-section">';
        html += '<div class="sv-detail-section-title">回答内容</div>';
        if (answers.length === 0) {
            html += '<div style="color:#9ca3af;font-size:14px;">回答データがありません。</div>';
        } else {
            answers.forEach(function(a) {
                html += '<div class="sv-answer-item">';
                html += '<div class="sv-answer-q">Q. ' + esc(a.question_label || '') + '</div>';
                var answerText = '';
                if (Array.isArray(a.answer)) {
                    answerText = a.answer.join(', ');
                } else {
                    answerText = a.answer || '';
                }
                html += '<div class="sv-answer-a">' + esc(answerText) + '</div>';
                html += '</div>';
            });
        }
        html += '</div>';

        // AI generations
        html += '<div class="sv-detail-section">';
        html += '<div class="sv-detail-section-title">AI生成口コミ</div>';
        if (aiGens.length === 0) {
            html += '<div style="color:#9ca3af;font-size:14px;">AI生成はまだありません。</div>';
        } else {
            aiGens.forEach(function(g) {
                var gst = g.status || 'generated';
                html += '<div class="sv-ai-gen-item">';
                html += '<div class="sv-ai-gen-header">';
                if (g.type) {
                    html += '<span class="sv-ai-gen-type-badge">' + esc(g.type) + '</span>';
                }
                html += '<span class="sv-ai-gen-status-badge ' + (aiGenStatusClasses[gst] || '') + '">' +
                    (aiGenStatusLabels[gst] || gst) + '</span>';
                if (g.version) {
                    html += '<span class="sv-ai-gen-version">v' + g.version + '</span>';
                }
                html += '</div>';
                html += '<div class="sv-ai-gen-text">' + esc(g.generated_text || '') + '</div>';
                if (gst === 'generated') {
                    html += '<div class="sv-ai-gen-actions">';
                    html += '<button class="sv-ai-gen-btn sv-ai-gen-btn-adopt" data-gid="' + g.id + '" data-action="adopted">採用</button>';
                    html += '<button class="sv-ai-gen-btn sv-ai-gen-btn-reject" data-gid="' + g.id + '" data-action="rejected">不採用</button>';
                    html += '</div>';
                }
                html += '</div>';
            });
        }
        html += '</div>';

        // Admin notes
        html += '<div class="sv-detail-section">';
        html += '<div class="sv-detail-section-title">管理メモ</div>';
        html += '<textarea class="sv-notes-area" id="sv-detail-notes" placeholder="この回答に対するメモを入力...">' + esc(r.admin_notes || '') + '</textarea>';
        html += '<button class="sv-btn-save-notes" id="sv-detail-notes-save" data-id="' + r.id + '">メモを保存</button>';
        html += '</div>';

        detailBody.innerHTML = html;

        // Bind detail events
        document.getElementById('sv-detail-status-save').addEventListener('click', function() {
            var newStatus = document.getElementById('sv-detail-status-sel').value;
            updateStatus(parseInt(this.dataset.id), newStatus, true);
        });

        document.getElementById('sv-detail-notes-save').addEventListener('click', function() {
            saveNotes(parseInt(this.dataset.id));
        });

        detailBody.querySelectorAll('.sv-ai-gen-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                updateAiStatus(parseInt(btn.dataset.gid), btn.dataset.action, r.id);
            });
        });
    }

    // =====================================================
    // Update response status
    // =====================================================
    function updateStatus(responseId, status, fromDetail) {
        apiPost('response/status', { response_id: responseId, status: status }).then(function(res) {
            if (res.success) {
                toast('ステータスを更新しました');
                if (fromDetail) {
                    // Refresh list in background
                    loadResponses(currentPage);
                } else {
                    // Already in list, just show toast
                }
            } else {
                toast(res.message || 'ステータスの更新に失敗しました', 'error');
            }
        }).catch(function() {
            toast('ステータスの更新に失敗しました', 'error');
        });
    }

    // =====================================================
    // Save admin notes
    // =====================================================
    function saveNotes(responseId) {
        var notes = document.getElementById('sv-detail-notes').value;
        var btn   = document.getElementById('sv-detail-notes-save');
        btn.disabled = true;
        btn.textContent = '保存中...';

        apiPost('response/notes', { response_id: responseId, notes: notes }).then(function(res) {
            btn.disabled = false;
            btn.textContent = 'メモを保存';
            if (res.success) {
                toast('メモを保存しました');
            } else {
                toast(res.message || 'メモの保存に失敗しました', 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.textContent = 'メモを保存';
            toast('メモの保存に失敗しました', 'error');
        });
    }

    // =====================================================
    // Update AI generation status
    // =====================================================
    function updateAiStatus(genId, status, responseId) {
        apiPost('ai-generation/status', { generation_id: genId, status: status }).then(function(res) {
            if (res.success) {
                toast(status === 'adopted' ? '採用しました' : '不採用にしました');
                // Refresh detail
                showDetail(responseId);
            } else {
                toast(res.message || '更新に失敗しました', 'error');
            }
        }).catch(function() {
            toast('更新に失敗しました', 'error');
        });
    }

    // =====================================================
    // Events
    // =====================================================
    document.getElementById('sv-filter-btn').addEventListener('click', function() {
        loadResponses(1);
    });

    // Enter key in keyword field
    document.getElementById('sv-filter-keyword').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { loadResponses(1); }
    });

    // Close detail modal
    document.getElementById('sv-detail-close').addEventListener('click', function() {
        detailModal.classList.remove('show');
    });
    detailModal.addEventListener('click', function(e) {
        if (e.target === detailModal) detailModal.classList.remove('show');
    });

    // =====================================================
    // Init
    // =====================================================
    loadSurveyFilter();
    loadResponses(1);
})();
</script>

<?php get_footer(); ?>
