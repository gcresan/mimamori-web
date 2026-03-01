<?php
/*
Template Name: CVログ精査
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

// パンくず設定
set_query_var('gcrev_page_title', '問い合わせの実数調整');
$breadcrumb = '<a href="' . esc_url(home_url()) . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="' . esc_url(home_url('/dashboard/')) . '">全体のようす</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<strong>問い合わせの実数調整</strong>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

get_header();
?>

<style>
/* page-cv-review — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */

/* CV Review Summary */
.cv-review-summary { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.summary-card { flex:1; min-width:120px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; text-align:center; }
.summary-label { font-size:12px; color:#64748b; margin-bottom:4px; }
.summary-value { font-size:24px; font-weight:700; color:#1e293b; }
.summary-valid { color:#16a34a; }
.summary-excluded { color:#dc2626; }
.summary-pending { color:#f59e0b; }

/* Toolbar */
.cv-review-toolbar { display:flex; align-items:center; gap:12px; margin-bottom:16px; padding:12px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; flex-wrap:wrap; }
.toolbar-left, .toolbar-center, .toolbar-right { display:flex; align-items:center; gap:8px; }
.toolbar-right { margin-left:auto; }
.btn-month-nav { background:#fff; border:1px solid #cbd5e1; border-radius:4px; padding:4px 10px; cursor:pointer; font-size:14px; }
.btn-month-nav:hover { background:#f1f5f9; }
#cvReviewMonth { border:1px solid #cbd5e1; border-radius:4px; padding:6px 10px; font-size:14px; }
.btn-load { background:#2563eb; color:#fff; border:none; border-radius:4px; padding:6px 16px; cursor:pointer; font-size:14px; }
.btn-load:hover { background:#1d4ed8; }
#filterStatus, #bulkAction { border:1px solid #cbd5e1; border-radius:4px; padding:6px 8px; font-size:13px; }
.btn-bulk { background:#6366f1; color:#fff; border:none; border-radius:4px; padding:6px 14px; cursor:pointer; font-size:13px; }
.btn-bulk:disabled { opacity:0.5; cursor:not-allowed; }

/* Table */
.cv-review-table-wrap { overflow-x:auto; }
.cv-review-table { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
.cv-review-table th { background:#f8fafc; border-bottom:2px solid #e2e8f0; padding:10px 8px; text-align:left; font-weight:600; color:#475569; white-space:nowrap; }
.cv-review-table td { border-bottom:1px solid #f1f5f9; padding:8px; vertical-align:middle; }
.cv-review-table tr:hover { background:#f8fafc; }
.cv-review-table tr.row-group { background:#fffbeb; }
.cv-review-table tr.status-valid { border-left:3px solid #16a34a; }
.cv-review-table tr.status-excluded { border-left:3px solid #dc2626; opacity:0.7; }
.col-check { width:32px; text-align:center; }
.col-datetime { width:130px; white-space:nowrap; }
.col-event { max-width:150px; }
.col-count { width:50px; text-align:center; }
.col-page { max-width:200px; }
.col-source { max-width:160px; }
.col-device { width:70px; }
.col-country { width:60px; }
.col-status { width:100px; }
.col-memo { min-width:120px; }
.col-page, .col-source { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
td .status-select { width:100%; border:1px solid #e2e8f0; border-radius:4px; padding:4px; font-size:12px; }
td .memo-input { width:100%; border:1px solid #e2e8f0; border-radius:4px; padding:4px 6px; font-size:12px; }
.badge-group { display:inline-block; background:#fbbf24; color:#78350f; font-size:11px; padding:1px 6px; border-radius:10px; font-weight:600; }

/* Save bar */
.cv-save-bar { display:flex; align-items:center; gap:12px; padding:12px 16px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; margin:12px 0; }
.cv-save-bar.has-changes { background:#fffbeb; border-color:#fde68a; }
.btn-save-cv { background:#16a34a; color:#fff; border:none; border-radius:6px; padding:10px 28px; cursor:pointer; font-size:15px; font-weight:600; transition:all 0.2s; }
.btn-save-cv:hover { background:#15803d; }
.btn-save-cv:disabled { opacity:0.5; cursor:not-allowed; }
.btn-save-cv.has-changes { background:#f59e0b; animation:pulse-save 1.5s ease-in-out infinite; }
.btn-save-cv.has-changes:hover { background:#d97706; }
@keyframes pulse-save { 0%,100% { box-shadow:0 0 0 0 rgba(245,158,11,0.4); } 50% { box-shadow:0 0 0 8px rgba(245,158,11,0); } }
.save-bar-info { font-size:13px; color:#64748b; }
.save-bar-info strong { color:#f59e0b; }
.cv-review-table tr.row-dirty { background:#fefce8 !important; }
.cv-review-table tr.row-dirty td:first-child { position:relative; }
.cv-review-table tr.row-dirty td:first-child::after { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:#f59e0b; }

/* Spinner */
.spinner { width:32px; height:32px; border:3px solid #e2e8f0; border-top:3px solid #2563eb; border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto 12px; }
@keyframes spin { to { transform:rotate(360deg); } }

/* Responsive */
@media (max-width:768px) {
  .cv-review-summary { flex-direction:row; flex-wrap:wrap; }
  .summary-card { min-width:calc(50% - 8px); flex:unset; }
  .cv-review-toolbar { flex-direction:column; align-items:stretch; }
  .toolbar-right { margin-left:0; }
  .cv-save-bar { flex-direction:column; text-align:center; }
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">

    <!-- サマリーカード -->
    <div class="cv-review-summary" id="cvReviewSummary">
        <div class="summary-card"><div class="summary-label">GA4 合計</div><div class="summary-value" id="summaryTotal">-</div></div>
        <div class="summary-card"><div class="summary-label">有効CV</div><div class="summary-value summary-valid" id="summaryValid">-</div></div>
        <div class="summary-card"><div class="summary-label">除外</div><div class="summary-value summary-excluded" id="summaryExcluded">-</div></div>
        <div class="summary-card"><div class="summary-label">未判定</div><div class="summary-value summary-pending" id="summaryPending">-</div></div>
        <div class="summary-card"><div class="summary-label">グループ数</div><div class="summary-value" id="summaryGroups">-</div></div>
    </div>

    <!-- ツールバー -->
    <div class="cv-review-toolbar">
        <div class="toolbar-left">
            <button type="button" id="btnPrevMonth" class="btn-month-nav">&#9664;</button>
            <input type="month" id="cvReviewMonth" value="">
            <button type="button" id="btnNextMonth" class="btn-month-nav">&#9654;</button>
            <button type="button" id="btnLoadData" class="btn-load">読み込む</button>
        </div>
        <div class="toolbar-center">
            <select id="filterStatus">
                <option value="all">すべて</option>
                <option value="0">未判定</option>
                <option value="1">有効CV</option>
                <option value="2">除外</option>
            </select>
        </div>
        <div class="toolbar-right">
            <label><input type="checkbox" id="checkAll"> 全選択</label>
            <select id="bulkAction">
                <option value="">一括操作...</option>
                <option value="1">&rarr; 有効CV</option>
                <option value="2">&rarr; 除外</option>
                <option value="0">&rarr; 未判定に戻す</option>
            </select>
            <button type="button" id="btnBulkApply" class="btn-bulk" disabled>適用</button>
        </div>
    </div>

    <!-- 保存バー（上部） -->
    <div class="cv-save-bar" id="saveBarTop" style="display:none;">
        <button type="button" class="btn-save-cv" id="btnSaveTop">保存する</button>
        <span class="save-bar-info" id="saveInfoTop">変更はありません</span>
    </div>

    <!-- データテーブル -->
    <div class="cv-review-table-wrap">
        <div id="cvReviewMessage" style="display:none;"></div>
        <div id="cvReviewLoading" style="display:none; text-align:center; padding:40px;">
            <div class="spinner"></div>
            <p>GA4データを取得中...</p>
        </div>
        <table class="cv-review-table" id="cvReviewTable" style="display:none;">
            <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" id="thCheckAll"></th>
                    <th class="col-datetime">日時</th>
                    <th class="col-event">イベント名</th>
                    <th class="col-count">件数</th>
                    <th class="col-page">ページ</th>
                    <th class="col-source">流入元</th>
                    <th class="col-device">デバイス</th>
                    <th class="col-country">国</th>
                    <th class="col-status">判定</th>
                    <th class="col-memo">メモ</th>
                </tr>
            </thead>
            <tbody id="cvReviewBody"></tbody>
        </table>
    </div>

    <!-- 保存バー（下部） -->
    <div class="cv-save-bar" id="saveBarBottom" style="display:none;">
        <button type="button" class="btn-save-cv" id="btnSaveBottom">保存する</button>
        <span class="save-bar-info" id="saveInfoBottom">変更はありません</span>
    </div>

    <!-- CVイベント未設定メッセージ -->
    <div id="cvNoConfig" style="display:none;">
        <div style="background:#fef3c7; border:1px solid #f59e0b; border-radius:8px; padding:24px; text-align:center;">
            <p style="margin:0 0 12px; font-size:16px; font-weight:600; color:#92400e;">CVイベントが設定されていません</p>
            <p style="margin:0;"><a href="<?php echo esc_url(home_url('/analysis/cv-settings/')); ?>" style="color:#2563eb; font-weight:600;">CV設定ページ</a>から設定してください。</p>
        </div>
    </div>

</div>

<script>
(function(){
    'use strict';

    var API_BASE = '<?php echo esc_js(trailingslashit(rest_url('gcrev/v1'))); ?>';
    var NONCE    = '<?php echo wp_create_nonce("wp_rest"); ?>';

    // State
    var allRows = [];
    var currentMonth = '';
    var checkedHashes = new Set();
    var dirtyHashes = new Set();        // 変更のある行のhash
    var originalStatuses = {};          // 読み込み時のstatus/memo保持

    // Elements
    var elMonth      = document.getElementById('cvReviewMonth');
    var elBody       = document.getElementById('cvReviewBody');
    var elTable      = document.getElementById('cvReviewTable');
    var elLoading    = document.getElementById('cvReviewLoading');
    var elMessage    = document.getElementById('cvReviewMessage');
    var elNoConfig   = document.getElementById('cvNoConfig');
    var elFilter     = document.getElementById('filterStatus');
    var elCheckAll   = document.getElementById('checkAll');
    var elThCheckAll = document.getElementById('thCheckAll');
    var elBulkAction = document.getElementById('bulkAction');
    var elBulkApply  = document.getElementById('btnBulkApply');

    // Save bar elements
    var elSaveBarTop    = document.getElementById('saveBarTop');
    var elSaveBarBottom = document.getElementById('saveBarBottom');
    var elBtnSaveTop    = document.getElementById('btnSaveTop');
    var elBtnSaveBottom = document.getElementById('btnSaveBottom');
    var elSaveInfoTop   = document.getElementById('saveInfoTop');
    var elSaveInfoBottom = document.getElementById('saveInfoBottom');

    // Summary elements
    var sumTotal    = document.getElementById('summaryTotal');
    var sumValid    = document.getElementById('summaryValid');
    var sumExcluded = document.getElementById('summaryExcluded');
    var sumPending  = document.getElementById('summaryPending');
    var sumGroups   = document.getElementById('summaryGroups');

    // Init: set month to previous month
    function init() {
        var now = new Date();
        now.setMonth(now.getMonth() - 1);
        var y = now.getFullYear();
        var m = String(now.getMonth() + 1).padStart(2, '0');
        currentMonth = y + '-' + m;
        elMonth.value = currentMonth;

        // Load data
        loadData();
    }

    // Month navigation
    document.getElementById('btnPrevMonth').addEventListener('click', function() {
        var d = new Date(currentMonth + '-01');
        d.setMonth(d.getMonth() - 1);
        currentMonth = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
        elMonth.value = currentMonth;
        loadData();
    });
    document.getElementById('btnNextMonth').addEventListener('click', function() {
        var d = new Date(currentMonth + '-01');
        d.setMonth(d.getMonth() + 1);
        currentMonth = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
        elMonth.value = currentMonth;
        loadData();
    });
    elMonth.addEventListener('change', function() {
        currentMonth = elMonth.value;
    });
    document.getElementById('btnLoadData').addEventListener('click', function() {
        currentMonth = elMonth.value;
        loadData();
    });

    // Load data from REST API
    async function loadData() {
        elLoading.style.display = 'block';
        elTable.style.display = 'none';
        elMessage.style.display = 'none';
        elNoConfig.style.display = 'none';
        elSaveBarTop.style.display = 'none';
        elSaveBarBottom.style.display = 'none';
        checkedHashes.clear();
        dirtyHashes.clear();
        originalStatuses = {};
        updateBulkState();

        try {
            var fetchUrl = API_BASE + 'cv-review?month=' + encodeURIComponent(currentMonth);
            console.log('[CV Review] Fetching:', fetchUrl);
            var res = await fetch(fetchUrl, {
                headers: { 'X-WP-Nonce': NONCE }
            });
            if (!res.ok) {
                var errText = await res.text();
                console.error('[CV Review] HTTP error', res.status, errText.substring(0, 1000));
                var errMsg = 'APIエラー (HTTP ' + res.status + ')';
                try {
                    var errJson = JSON.parse(errText);
                    if (errJson.message) errMsg += ': ' + errJson.message;
                } catch(_) {}
                showMessage(errMsg, 'error');
                elLoading.style.display = 'none';
                return;
            }
            var data = await res.json();

            elLoading.style.display = 'none';

            if (!data.success) {
                showMessage(data.message || 'エラーが発生しました', 'error');
                return;
            }

            if (data.message && data.rows.length === 0) {
                // CVイベント未設定
                elNoConfig.style.display = 'block';
                updateSummary([]);
                return;
            }

            allRows = data.rows || [];

            // 読み込み時の状態を保持（変更検知用）
            allRows.forEach(function(r) {
                originalStatuses[r.row_hash] = { status: r.status, memo: r.memo || '' };
            });

            renderTable();
            updateSummary(allRows);
            elTable.style.display = 'table';
            elSaveBarTop.style.display = 'flex';
            elSaveBarBottom.style.display = 'flex';
            updateSaveBar();

        } catch(e) {
            elLoading.style.display = 'none';
            showMessage('通信エラー: ' + e.message, 'error');
        }
    }

    function showMessage(msg, type) {
        elMessage.textContent = msg;
        elMessage.style.display = 'block';
        elMessage.style.padding = '16px';
        elMessage.style.borderRadius = '8px';
        if (type === 'error') {
            elMessage.style.background = '#fef2f2';
            elMessage.style.color = '#991b1b';
            elMessage.style.border = '1px solid #fecaca';
        } else {
            elMessage.style.background = '#f0fdf4';
            elMessage.style.color = '#166534';
            elMessage.style.border = '1px solid #bbf7d0';
        }
    }

    // Format dateHourMinute: "202601150930" -> "2026/01/15 09:30"
    function formatDateTime(dhm) {
        if (!dhm || dhm.length < 12) return dhm || '';
        return dhm.substring(0,4) + '/' + dhm.substring(4,6) + '/' + dhm.substring(6,8) + ' ' + dhm.substring(8,10) + ':' + dhm.substring(10,12);
    }

    // Render table
    function renderTable() {
        var filterVal = elFilter.value;
        elBody.innerHTML = '';

        var filtered = filterVal === 'all'
            ? allRows
            : allRows.filter(function(r) { return r.status === parseInt(filterVal); });

        filtered.forEach(function(row) {
            var tr = document.createElement('tr');
            var isGroup = row.event_count > 1;

            // Row class
            if (isGroup) tr.classList.add('row-group');
            if (row.status === 1) tr.classList.add('status-valid');
            if (row.status === 2) tr.classList.add('status-excluded');

            // Checkbox
            var tdCheck = document.createElement('td');
            tdCheck.className = 'col-check';
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.dataset.hash = row.row_hash;
            cb.checked = checkedHashes.has(row.row_hash);
            cb.addEventListener('change', function() {
                if (cb.checked) checkedHashes.add(row.row_hash);
                else checkedHashes.delete(row.row_hash);
                updateBulkState();
            });
            tdCheck.appendChild(cb);
            tr.appendChild(tdCheck);

            // DateTime
            var tdDt = document.createElement('td');
            tdDt.className = 'col-datetime';
            tdDt.textContent = formatDateTime(row.date_hour_minute);
            tr.appendChild(tdDt);

            // Event name
            var tdEv = document.createElement('td');
            tdEv.className = 'col-event';
            tdEv.textContent = row.event_name;
            tr.appendChild(tdEv);

            // Count
            var tdCnt = document.createElement('td');
            tdCnt.className = 'col-count';
            if (isGroup) {
                var badge = document.createElement('span');
                badge.className = 'badge-group';
                badge.textContent = row.event_count + '件';
                tdCnt.appendChild(badge);
            } else {
                tdCnt.textContent = '1';
            }
            tr.appendChild(tdCnt);

            // Page
            var tdPage = document.createElement('td');
            tdPage.className = 'col-page';
            tdPage.title = row.page_path;
            tdPage.textContent = row.page_path;
            tr.appendChild(tdPage);

            // Source
            var tdSrc = document.createElement('td');
            tdSrc.className = 'col-source';
            tdSrc.title = row.source_medium;
            tdSrc.textContent = row.source_medium;
            tr.appendChild(tdSrc);

            // Device
            var tdDev = document.createElement('td');
            tdDev.className = 'col-device';
            tdDev.textContent = row.device_category;
            tr.appendChild(tdDev);

            // Country
            var tdCountry = document.createElement('td');
            tdCountry.className = 'col-country';
            tdCountry.textContent = row.country;
            tr.appendChild(tdCountry);

            // Status select
            var tdStatus = document.createElement('td');
            tdStatus.className = 'col-status';
            var sel = document.createElement('select');
            sel.className = 'status-select';
            [{v:0,l:'未判定'},{v:1,l:'有効CV'},{v:2,l:'除外'}].forEach(function(o) {
                var opt = document.createElement('option');
                opt.value = o.v;
                opt.textContent = o.l;
                if (o.v === row.status) opt.selected = true;
                sel.appendChild(opt);
            });
            sel.addEventListener('change', function() {
                var newStatus = parseInt(sel.value);
                row.status = newStatus;
                markDirty(row.row_hash);
                // Update row class
                tr.classList.remove('status-valid', 'status-excluded');
                tr.classList.add('row-dirty');
                if (newStatus === 1) tr.classList.add('status-valid');
                if (newStatus === 2) tr.classList.add('status-excluded');
                updateSummary(allRows);
                updateSaveBar();
            });
            tdStatus.appendChild(sel);
            tr.appendChild(tdStatus);

            // Memo
            var tdMemo = document.createElement('td');
            tdMemo.className = 'col-memo';
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'memo-input';
            inp.value = row.memo || '';
            inp.placeholder = 'メモ...';
            inp.addEventListener('blur', function() {
                if (inp.value !== (row.memo || '')) {
                    row.memo = inp.value;
                    markDirty(row.row_hash);
                    tr.classList.add('row-dirty');
                    updateSaveBar();
                }
            });
            tdMemo.appendChild(inp);
            tr.appendChild(tdMemo);

            elBody.appendChild(tr);
        });
    }

    // Filter change
    elFilter.addEventListener('change', renderTable);

    // Check all
    function handleCheckAll(checked) {
        checkedHashes.clear();
        if (checked) {
            var filterVal = elFilter.value;
            var filtered = filterVal === 'all'
                ? allRows
                : allRows.filter(function(r) { return r.status === parseInt(filterVal); });
            filtered.forEach(function(r) { checkedHashes.add(r.row_hash); });
        }
        // Update checkboxes
        elBody.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
            cb.checked = checked;
        });
        elCheckAll.checked = checked;
        elThCheckAll.checked = checked;
        updateBulkState();
    }
    elCheckAll.addEventListener('change', function() { handleCheckAll(elCheckAll.checked); });
    elThCheckAll.addEventListener('change', function() { handleCheckAll(elThCheckAll.checked); });

    // Bulk state
    function updateBulkState() {
        elBulkApply.disabled = checkedHashes.size === 0 || !elBulkAction.value;
    }
    elBulkAction.addEventListener('change', updateBulkState);

    // Bulk apply（ローカル反映のみ、保存は保存ボタンで）
    elBulkApply.addEventListener('click', function() {
        var newStatus = parseInt(elBulkAction.value);
        if (isNaN(newStatus)) return;

        var changed = 0;
        allRows.forEach(function(r) {
            if (checkedHashes.has(r.row_hash)) {
                r.status = newStatus;
                markDirty(r.row_hash);
                changed++;
            }
        });

        if (changed > 0) {
            checkedHashes.clear();
            renderTable();
            updateSummary(allRows);
            updateSaveBar();
            showMessage(changed + '件の判定を変更しました。「保存する」ボタンで保存してください。', 'success');
        }
    });

    // =========================================================
    // 変更管理 & 保存
    // =========================================================

    // 変更をマーク
    function markDirty(hash) {
        dirtyHashes.add(hash);
    }

    // 保存バーの表示更新
    function updateSaveBar() {
        var count = dirtyHashes.size;
        var hasChanges = count > 0;
        var label = hasChanges
            ? '<strong>' + count + '件</strong>の変更があります'
            : '✅ すべて保存済みです';

        elSaveInfoTop.innerHTML = label;
        elSaveInfoBottom.innerHTML = label;

        [elSaveBarTop, elSaveBarBottom].forEach(function(bar) {
            if (hasChanges) bar.classList.add('has-changes');
            else bar.classList.remove('has-changes');
        });

        [elBtnSaveTop, elBtnSaveBottom].forEach(function(btn) {
            btn.disabled = !hasChanges;
            btn.textContent = hasChanges ? '保存する（' + count + '件）' : '保存する';
            if (hasChanges) btn.classList.add('has-changes');
            else btn.classList.remove('has-changes');
        });
    }

    // 全変更を一括保存
    async function saveAllChanges() {
        if (dirtyHashes.size === 0) return;

        var items = allRows
            .filter(function(r) { return dirtyHashes.has(r.row_hash); })
            .map(function(r) {
                return {
                    row_hash: r.row_hash,
                    status: r.status,
                    memo: r.memo || '',
                    event_name: r.event_name,
                    date_hour_minute: r.date_hour_minute,
                    page_path: r.page_path,
                    source_medium: r.source_medium,
                    device_category: r.device_category,
                    country: r.country,
                    event_count: r.event_count
                };
            });

        if (items.length === 0) return;

        // ボタンを処理中に
        [elBtnSaveTop, elBtnSaveBottom].forEach(function(btn) {
            btn.disabled = true;
            btn.textContent = '保存中...';
            btn.classList.remove('has-changes');
        });

        try {
            var res = await fetch(API_BASE + 'cv-review/bulk-update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body: JSON.stringify({ month: currentMonth, items: items })
            });

            if (!res.ok) {
                var errText = await res.text();
                console.error('[CV Review] Save HTTP error', res.status, errText.substring(0, 1000));
                var errMsg = '保存に失敗しました (HTTP ' + res.status + ')';
                try {
                    var errJson = JSON.parse(errText);
                    if (errJson.message) errMsg = errJson.message;
                } catch(_) {}
                showMessage(errMsg, 'error');
                return;
            }

            var data = await res.json();

            if (data.success) {
                // originalStatuses を現在の状態に更新
                allRows.forEach(function(r) {
                    if (dirtyHashes.has(r.row_hash)) {
                        originalStatuses[r.row_hash] = { status: r.status, memo: r.memo || '' };
                    }
                });
                dirtyHashes.clear();
                renderTable();
                updateSaveBar();
                var msg = '✅ ' + data.updated + '件の変更を保存しました';
                if (data.errors > 0) msg += '（' + data.errors + '件エラー）';
                showMessage(msg, 'success');
            } else {
                showMessage('保存に失敗しました: ' + (data.message || '不明なエラー'), 'error');
            }
        } catch(e) {
            console.error('[CV Review] Save failed:', e);
            showMessage('保存に失敗しました: ' + e.message, 'error');
        }

        [elBtnSaveTop, elBtnSaveBottom].forEach(function(btn) {
            btn.disabled = false;
        });
        updateSaveBar();
    }

    // 保存ボタンのイベント
    elBtnSaveTop.addEventListener('click', saveAllChanges);
    elBtnSaveBottom.addEventListener('click', saveAllChanges);

    // ページ離脱時の未保存警告
    window.addEventListener('beforeunload', function(e) {
        if (dirtyHashes.size > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Update summary cards
    function updateSummary(rows) {
        var total = rows.reduce(function(sum, r) { return sum + r.event_count; }, 0);
        var valid = rows.filter(function(r) { return r.status === 1; }).reduce(function(sum, r) { return sum + r.event_count; }, 0);
        var excluded = rows.filter(function(r) { return r.status === 2; }).reduce(function(sum, r) { return sum + r.event_count; }, 0);
        var pending = rows.filter(function(r) { return r.status === 0; }).reduce(function(sum, r) { return sum + r.event_count; }, 0);

        sumTotal.textContent = total.toLocaleString();
        sumValid.textContent = valid.toLocaleString();
        sumExcluded.textContent = excluded.toLocaleString();
        sumPending.textContent = pending.toLocaleString();
        sumGroups.textContent = rows.length.toLocaleString();
    }

    // Init
    document.addEventListener('DOMContentLoaded', init);
})();
</script>

<?php get_footer(); ?>
