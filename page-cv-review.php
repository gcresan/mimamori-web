<?php
/*
Template Name: ゴール関連設定
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = mimamori_get_view_user_id();

// パンくず設定
set_query_var('gcrev_page_title', 'ゴール関連設定');
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('ゴール関連設定', '各種設定'));

get_header();
?>

<style>
/* ===== Goal Tabs ===== */
.goal-tabs {
    display: flex;
    gap: 0;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 24px;
}
.goal-tab {
    background: none;
    border: none;
    padding: 12px 24px;
    font-size: 14px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: color 0.15s, border-color 0.15s;
    white-space: nowrap;
}
.goal-tab:hover {
    color: #334155;
}
.goal-tab.active {
    color: #568184;
    border-bottom-color: #568184;
}

/* ===== Tab 1: CV Review ===== */

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
.btn-load { background:#568184; color:#fff; border:none; border-radius:4px; padding:6px 16px; cursor:pointer; font-size:14px; }
.btn-load:hover { background:#476C6F; }
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
.col-label { max-width:140px; font-weight:600; }
.col-event { max-width:150px; }
.col-count { width:50px; text-align:center; }
.col-page { max-width:200px; }
.col-source { max-width:160px; }
.col-device { width:70px; }
.col-country { width:60px; }
.col-status { width:100px; }
.col-label, .col-event, .col-page, .col-source { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
td .status-select { width:100%; border:1px solid #e2e8f0; border-radius:4px; padding:4px; font-size:12px; }
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
.spinner { width:32px; height:32px; border:3px solid #e2e8f0; border-top:3px solid #568184; border-radius:50%; animation:spin 0.8s linear infinite; margin:0 auto 12px; }
@keyframes spin { to { transform:rotate(360deg); } }

/* ===== Tab 2: CV Settings ===== */

.cv-settings-description {
    font-size: 14px;
    color: var(--mw-text-secondary);
    margin-bottom: 20px;
    line-height: 1.7;
}

.cv-routes-table .drag-handle {
    cursor: grab;
    text-align: center;
    color: #aaa;
    font-size: 18px;
    user-select: none;
}
.cv-routes-table .drag-handle:active {
    cursor: grabbing;
}
.cv-routes-table tr.dragging {
    opacity: 0.4;
}
.cv-routes-table tr.drag-over {
    border-top: 2px solid var(--mw-primary-blue);
}

.cv-routes-table input[type="text"] {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--mw-border-light);
    border-radius: 6px;
    font-size: 13px;
    box-sizing: border-box;
    transition: border-color 0.2s;
}
.cv-routes-table input[type="text"]:focus {
    border-color: var(--mw-primary-blue);
    outline: none;
    box-shadow: 0 0 0 2px rgba(86, 129, 132, 0.12);
}
.cv-routes-table input[data-field="route_key"] {
    font-family: monospace;
}

.cv-routes-count {
    font-size: 12px;
    color: #666666;
    margin-left: 8px;
}

/* ===== サジェストUI ===== */
.suggest-wrapper {
    position: relative;
}
.suggest-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 100;
    background: #fff;
    border: 1px solid var(--mw-border-light);
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    max-height: 220px;
    overflow-y: auto;
    display: none;
    margin-top: 2px;
}
.suggest-dropdown.open {
    display: block;
}
.suggest-item {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
}
.suggest-item:hover,
.suggest-item.highlighted {
    background: #f0f4f5;
}
.suggest-item .event-name {
    font-family: monospace;
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.suggest-item .suggest-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}
.suggest-item .key-badge {
    font-size: 10px;
    color: #568184;
    background: #e8f0f0;
    padding: 2px 6px;
    border-radius: 4px;
    white-space: nowrap;
    font-weight: 600;
}
.suggest-item .event-count {
    font-size: 11px;
    color: #999;
    white-space: nowrap;
}
.suggest-empty {
    padding: 8px 12px;
    color: #999;
    font-size: 12px;
}
.suggest-spinner {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 11px;
    color: #999;
    pointer-events: none;
}
.suggest-error-msg {
    font-size: 12px;
    color: #C0392B;
    margin-top: 4px;
}

/* Responsive */
@media (max-width:768px) {
  .goal-tabs { overflow-x: auto; }
  .goal-tab { padding: 10px 16px; font-size: 13px; }
  .cv-review-summary { flex-direction:row; flex-wrap:wrap; }
  .summary-card { min-width:calc(50% - 8px); flex:unset; }
  .cv-review-toolbar { flex-direction:column; align-items:stretch; }
  .toolbar-right { margin-left:0; }
  .cv-save-bar { flex-direction:column; text-align:center; }
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">

    <!-- タブナビゲーション -->
    <div class="goal-tabs">
        <button type="button" class="goal-tab active" data-tab="review">ゴールの確認（手動調整）</button>
        <button type="button" class="goal-tab" data-tab="settings">ゴールの数え方設定</button>
    </div>

    <!-- ===== Tab 1: ゴールの確認（手動調整） ===== -->
    <div class="goal-tab-content" id="tab-review">

        <!-- サマリーカード -->
        <div class="cv-review-summary" id="cvReviewSummary">
            <div class="summary-card"><div class="summary-label">GA4 合計</div><div class="summary-value" id="summaryTotal">-</div></div>
            <div class="summary-card"><div class="summary-label">有効</div><div class="summary-value summary-valid" id="summaryValid">-</div></div>
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
                    <option value="1">有効</option>
                    <option value="2">除外</option>
                </select>
            </div>
            <div class="toolbar-right">
                <label><input type="checkbox" id="checkAll"> 全選択</label>
                <select id="bulkAction">
                    <option value="">一括操作...</option>
                    <option value="1">&rarr; 有効</option>
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
                        <th class="col-label">表示ラベル</th>
                        <th class="col-event">イベント名</th>
                        <th class="col-count">件数</th>
                        <th class="col-page">ページ</th>
                        <th class="col-source">流入元</th>
                        <th class="col-device">デバイス</th>
                        <th class="col-country">国</th>
                        <th class="col-status">判定</th>
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
                <p style="margin:0 0 12px; font-size:16px; font-weight:600; color:#92400e;">ゴールが設定されていません</p>
                <p style="margin:0;"><a href="javascript:void(0)" id="linkToSettingsTab" style="color:#568184; font-weight:600;">ゴールの数え方設定</a>から設定してください。</p>
            </div>
        </div>

    </div>

    <!-- ===== Tab 2: ゴールの数え方設定 ===== -->
    <div class="goal-tab-content" id="tab-settings" style="display:none;">

        <!-- キーイベント設定 -->
        <div class="settings-card">
            <h2>
                <span>ゴール設定</span>
            </h2>
            <p class="cv-settings-description">
                ゴールとは、このホームページで「達成したい成果」を示す指標です。問い合わせだけでなく、電話タップやページ到達などもゴールとして設定できます。ここでは、ゴールとしてカウントするGA4イベントを設定します。
            </p>

            <input type="hidden" id="cv-settings-user" value="<?php echo esc_attr($user_id); ?>">

            <div id="cv-routes-editor">
                <table class="actual-cv-table cv-routes-table" style="margin-bottom:16px;">
                    <thead>
                        <tr>
                            <th style="width:36px;"></th>
                            <th>GA4イベント名</th>
                            <th>表示ラベル</th>
                            <th style="width:60px;">削除</th>
                        </tr>
                    </thead>
                    <tbody id="cv-routes-rows"></tbody>
                </table>
                <div style="margin-bottom:16px;">
                    <button type="button" class="btn-outline" id="btn-add-cv-route" data-gcrev-ignore-unsaved="1" style="font-size:13px;">+ ゴールを追加</button>
                    <span id="cv-routes-count" class="cv-routes-count"></span>
                </div>

                <div class="form-group">
                    <label for="cv-only-configured" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                        <input type="checkbox" id="cv-only-configured" data-gcrev-ignore-unsaved="1">
                        <span>設定したゴール以外はゴール分析に含めない</span>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" id="btn-save-cv-routes" data-gcrev-ignore-unsaved="1">設定を保存</button>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
// ===== タブ切り替え =====
(function() {
    'use strict';

    var tabs = document.querySelectorAll('.goal-tab');
    var contents = document.querySelectorAll('.goal-tab-content');

    function switchTab(tabId) {
        tabs.forEach(function(t) {
            t.classList.toggle('active', t.dataset.tab === tabId);
        });
        contents.forEach(function(c) {
            c.style.display = c.id === 'tab-' + tabId ? '' : 'none';
        });
        history.replaceState(null, '', '#' + tabId);
        // レビュータブに切り替えた際にデータを再取得
        if (tabId === 'review') {
            document.dispatchEvent(new CustomEvent('gcrev:goalSettingsChanged'));
        }
    }

    tabs.forEach(function(t) {
        t.addEventListener('click', function() {
            switchTab(t.dataset.tab);
        });
    });

    // "ゴールの数え方設定" リンク（未設定メッセージ内）
    var linkToSettings = document.getElementById('linkToSettingsTab');
    if (linkToSettings) {
        linkToSettings.addEventListener('click', function(e) {
            e.preventDefault();
            switchTab('settings');
        });
    }

    // URL hash で初期タブを決定
    var hash = location.hash.replace('#', '');
    if (hash === 'settings') {
        switchTab('settings');
    }
})();

// ===== Tab 1: ゴールの確認（手動調整） =====
(function(){
    'use strict';

    var API_BASE = '<?php echo esc_js(trailingslashit(rest_url('gcrev/v1'))); ?>';
    var NONCE    = '<?php echo wp_create_nonce("wp_rest"); ?>';

    // State
    var allRows = [];
    var currentMonth = '';
    var checkedHashes = new Set();
    var dirtyHashes = new Set();
    var originalStatuses = {};

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

            // Display label (from cv_routes.label; falls back to event_name)
            var tdLabel = document.createElement('td');
            tdLabel.className = 'col-label';
            var labelText = row.label || row.event_name || '';
            tdLabel.title = labelText;
            tdLabel.textContent = labelText;
            tr.appendChild(tdLabel);

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
            [{v:0,l:'未判定'},{v:1,l:'有効'},{v:2,l:'除外'}].forEach(function(o) {
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

    // Bulk apply
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

    // 変更管理 & 保存
    function markDirty(hash) {
        dirtyHashes.add(hash);
    }

    function updateSaveBar() {
        var count = dirtyHashes.size;
        var hasChanges = count > 0;
        var label = hasChanges
            ? '<strong>' + count + '件</strong>の変更があります'
            : 'すべて保存済みです';

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
                allRows.forEach(function(r) {
                    if (dirtyHashes.has(r.row_hash)) {
                        originalStatuses[r.row_hash] = { status: r.status, memo: r.memo || '' };
                    }
                });
                dirtyHashes.clear();
                renderTable();
                updateSaveBar();
                var msg = data.updated + '件の変更を保存しました';
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

    // ゴール設定変更時にレビューデータを再取得
    document.addEventListener('gcrev:goalSettingsChanged', function() {
        loadData();
    });
})();

// ===== Tab 2: ゴールの数え方設定 =====

// グローバル変数
const restBase = '<?php echo esc_js(trailingslashit(rest_url('gcrev_insights/v1'))); ?>';
const wpNonce  = '<?php echo wp_create_nonce('wp_rest'); ?>';
const userId   = <?php echo (int) $user_id; ?>;

// 最大ルート数
const MAX_ROUTES = 20;

// GA4イベント候補
var GA4_EVENTS_CACHE = [];
var ga4EventsLoading = false;
var ga4EventsError   = false;

// ページ読み込み時の初期化
document.addEventListener('DOMContentLoaded', function() {
    initCvRoutesUI();
});

// Dirty tracking
function markCvSettingsDirty(btnId) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.style.backgroundColor = '#568184';
    btn.style.borderColor = '#568184';
    btn.style.color = '#fff';
}
function markCvSettingsClean(btnId) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.style.backgroundColor = '';
    btn.style.borderColor = '';
    btn.style.color = '';
}

// GA4イベント候補取得
// サーバー側のコールドキャッシュ時は GA4 API 呼び出しで 60秒以上かかるため、
// タイムアウトは 120秒、リトライは 1回のみ（サーバー側にロックがあるので同時多発させない）。
async function fetchGa4Events(retries) {
    if (typeof retries === 'undefined') retries = 2;
    ga4EventsLoading = true;
    ga4EventsError   = false;
    updateAllSpinners(true);

    for (var attempt = 0; attempt < retries; attempt++) {
        try {
            var controller = new AbortController();
            var tid = setTimeout(function() { controller.abort(); }, 120000); // 120秒

            var res = await fetch(
                restBase + 'ga4-key-events?user_id=' + userId + '&_=' + Date.now(),
                { headers: { 'X-WP-Nonce': wpNonce }, signal: controller.signal }
            );
            clearTimeout(tid);

            if (!res.ok) throw new Error('HTTP ' + res.status);

            var json = await res.json();
            if (json.success && Array.isArray(json.events)) {
                // processing（ライブフェッチ中・fallback 無し）の場合は短時間後にもう一度取得を試みる
                if (json.source === 'processing' && json.events.length === 0 && attempt < retries - 1) {
                    console.info('[GCREV] GA4 events still processing, will retry after wait');
                    await new Promise(function(r) { setTimeout(r, 8000); });
                    continue;
                }
                GA4_EVENTS_CACHE = json.events;
                ga4EventsLoading = false;
                ga4EventsError   = false;
                updateAllSpinners(false);
                clearSuggestErrors();
                return;
            }
            throw new Error('Unexpected response');
        } catch (e) {
            console.warn('[GCREV] GA4 events fetch attempt ' + (attempt + 1) + ' failed:', e.message);
            if (attempt < retries - 1) {
                await new Promise(function(r) { setTimeout(r, 3000); });
            }
        }
    }
    ga4EventsLoading = false;
    ga4EventsError   = true;
    updateAllSpinners(false);
    showSuggestErrors();
}

function updateAllSpinners(show) {
    document.querySelectorAll('.suggest-spinner').forEach(function(el) {
        el.style.display = show ? 'inline' : 'none';
    });
}

function showSuggestErrors() {
    document.querySelectorAll('.suggest-wrapper').forEach(function(w) {
        if (!w.querySelector('.suggest-error-msg')) {
            var msg = document.createElement('div');
            msg.className = 'suggest-error-msg';
            msg.textContent = '候補の取得に失敗しました。しばらくして再試行してください。';
            w.parentNode.insertBefore(msg, w.nextSibling);
        }
    });
}
function clearSuggestErrors() {
    document.querySelectorAll('.suggest-error-msg').forEach(function(el) { el.remove(); });
}

function attachSuggest(input) {
    var wrapper = input.closest('.suggest-wrapper');
    if (!wrapper) return;

    var dropdown = wrapper.querySelector('.suggest-dropdown');
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.className = 'suggest-dropdown';
        wrapper.appendChild(dropdown);
    }

    var highlightIdx = -1;

    function renderDropdown(filter) {
        dropdown.innerHTML = '';
        highlightIdx = -1;

        if (ga4EventsLoading) {
            dropdown.innerHTML = '<div class="suggest-empty">読み込み中…</div>';
            dropdown.classList.add('open');
            return;
        }

        var usedNames = {};
        document.querySelectorAll('#cv-routes-rows input[data-field="route_key"]').forEach(function(inp) {
            if (inp !== input) {
                var v = inp.value.trim();
                if (v) usedNames[v] = true;
            }
        });

        var items = GA4_EVENTS_CACHE.filter(function(e) {
            return !usedNames[e.name];
        });
        if (filter) {
            var f = filter.toLowerCase();
            items = items.filter(function(e) { return e.name.toLowerCase().indexOf(f) !== -1; });
        }

        if (items.length === 0) {
            var emptyText = GA4_EVENTS_CACHE.length === 0
                ? (ga4EventsError ? '候補の取得に失敗しました' : '候補なし')
                : '一致する候補がありません';
            dropdown.innerHTML = '<div class="suggest-empty">' + emptyText + '</div>';
            dropdown.classList.add('open');
            return;
        }

        items.forEach(function(ev, i) {
            var div = document.createElement('div');
            div.className = 'suggest-item';
            div.dataset.index = i;

            var nameSpan = document.createElement('span');
            nameSpan.className = 'event-name';
            nameSpan.textContent = ev.name;
            div.appendChild(nameSpan);

            var metaSpan = document.createElement('span');
            metaSpan.className = 'suggest-meta';
            if (ev.is_key_event) {
                var badge = document.createElement('span');
                badge.className = 'key-badge';
                badge.textContent = 'ゴール';
                metaSpan.appendChild(badge);
            }
            if (ev.count > 0) {
                var cnt = document.createElement('span');
                cnt.className = 'event-count';
                cnt.textContent = ev.count + '件';
                metaSpan.appendChild(cnt);
            }
            div.appendChild(metaSpan);

            div.addEventListener('mousedown', function(e) {
                e.preventDefault();
                input.value = ev.name;
                dropdown.classList.remove('open');
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });

            dropdown.appendChild(div);
        });

        dropdown.classList.add('open');
    }

    input.addEventListener('focus', function() { renderDropdown(input.value); });
    input.addEventListener('input', function() { renderDropdown(input.value); });
    input.addEventListener('blur', function() {
        setTimeout(function() { dropdown.classList.remove('open'); }, 200);
    });
    input.addEventListener('keydown', function(e) {
        var allItems = dropdown.querySelectorAll('.suggest-item');
        if (!allItems.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlightIdx = Math.min(highlightIdx + 1, allItems.length - 1);
            allItems.forEach(function(el, i) { el.classList.toggle('highlighted', i === highlightIdx); });
            if (allItems[highlightIdx]) allItems[highlightIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlightIdx = Math.max(highlightIdx - 1, 0);
            allItems.forEach(function(el, i) { el.classList.toggle('highlighted', i === highlightIdx); });
        } else if (e.key === 'Enter' && highlightIdx >= 0 && allItems[highlightIdx]) {
            e.preventDefault();
            var selectedName = allItems[highlightIdx].querySelector('.event-name');
            input.value = selectedName ? selectedName.textContent : '';
            dropdown.classList.remove('open');
            input.dispatchEvent(new Event('change', { bubbles: true }));
        } else if (e.key === 'Escape') {
            dropdown.classList.remove('open');
        }
    });
}

// ルート設定UIの初期化
async function initCvRoutesUI() {
    fetchGa4Events();

    try {
        var res = await fetch(restBase + 'actual-cv/routes?_=' + Date.now(), {
            headers: { 'X-WP-Nonce': wpNonce }
        });
        if (!res.ok) return;
        var json = await res.json();
        if (json.success && Array.isArray(json.data)) {
            renderCvRoutesEditor(json.data);
            updateRoutesCount();
        }
        var chk = document.getElementById('cv-only-configured');
        if (chk) {
            chk.checked = (json.cv_only_configured == null) ? true : !!json.cv_only_configured;
            chk.addEventListener('change', function() {
                markCvSettingsDirty('btn-save-cv-routes');
            });
        }
    } catch (e) {
        console.error('CV routes load error', e);
    }
}

function renderCvRoutesEditor(routes) {
    var tbody = document.getElementById('cv-routes-rows');
    if (!tbody) return;
    tbody.innerHTML = '';
    routes.forEach(function(r, i) {
        addRouteRow(r.route_key, r.label, i + 1);
    });
    markCvSettingsClean('btn-save-cv-routes');
}

function addRouteRow(eventName, label, order) {
    var tbody = document.getElementById('cv-routes-rows');
    if (!tbody) return;
    var currentCount = tbody.querySelectorAll('tr').length;
    if (currentCount >= MAX_ROUTES) {
        alert('ゴールは最大' + MAX_ROUTES + '件まで設定できます');
        return;
    }
    var tr = document.createElement('tr');
    tr.draggable = true;
    tr.innerHTML =
        '<td class="drag-handle" title="ドラッグで並べ替え">&#10495;</td>' +
        '<td><div class="suggest-wrapper">' +
            '<input type="text" value="' + escAttr(eventName || '') + '" data-field="route_key" placeholder="GA4イベント名を入力..." data-gcrev-ignore-unsaved="1" style="font-family:monospace;font-size:13px;" autocomplete="off">' +
            '<span class="suggest-spinner" style="' + (ga4EventsLoading ? '' : 'display:none;') + '">読み込み中…</span>' +
        '</div></td>' +
        '<td><input type="text" value="' + escAttr(label || '') + '" data-field="label" placeholder="表示ラベル" data-gcrev-ignore-unsaved="1"></td>' +
        '<td style="text-align:center;"><button type="button" class="btn-remove-route" style="background:none;border:none;cursor:pointer;font-size:16px;color:#C0392B;" title="削除">&times;</button></td>';

    var rkInput = tr.querySelector('input[data-field="route_key"]');
    if (rkInput) { attachSuggest(rkInput); }

    tr.querySelectorAll('input').forEach(function(inp) {
        inp.addEventListener('change', function() { markCvSettingsDirty('btn-save-cv-routes'); });
        inp.addEventListener('input', function() { markCvSettingsDirty('btn-save-cv-routes'); });
    });

    tr.querySelector('.btn-remove-route').addEventListener('click', function() {
        tr.remove();
        markCvSettingsDirty('btn-save-cv-routes');
        updateRoutesCount();
    });

    setupRowDragEvents(tr);

    tbody.appendChild(tr);
    updateRoutesCount();
}

// ドラッグ＆ドロップ並べ替え
var dragSrcRow = null;

function setupRowDragEvents(tr) {
    tr.addEventListener('dragstart', function(e) {
        dragSrcRow = tr;
        tr.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    tr.addEventListener('dragend', function() {
        tr.classList.remove('dragging');
        document.querySelectorAll('#cv-routes-rows tr.drag-over').forEach(function(r) { r.classList.remove('drag-over'); });
        dragSrcRow = null;
    });
    tr.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (dragSrcRow && dragSrcRow !== tr) {
            tr.classList.add('drag-over');
        }
    });
    tr.addEventListener('dragleave', function() {
        tr.classList.remove('drag-over');
    });
    tr.addEventListener('drop', function(e) {
        e.preventDefault();
        tr.classList.remove('drag-over');
        if (!dragSrcRow || dragSrcRow === tr) return;
        var parentTbody = tr.parentNode;
        var rows = Array.from(parentTbody.querySelectorAll('tr'));
        var fromIdx = rows.indexOf(dragSrcRow);
        var toIdx = rows.indexOf(tr);
        if (fromIdx < toIdx) {
            parentTbody.insertBefore(dragSrcRow, tr.nextSibling);
        } else {
            parentTbody.insertBefore(dragSrcRow, tr);
        }
        markCvSettingsDirty('btn-save-cv-routes');
    });
}

function updateRoutesCount() {
    var tbody = document.getElementById('cv-routes-rows');
    var counter = document.getElementById('cv-routes-count');
    if (!tbody || !counter) return;
    var count = tbody.querySelectorAll('tr').length;
    counter.textContent = count + ' / ' + MAX_ROUTES + ' 件';
    var addBtn = document.getElementById('btn-add-cv-route');
    if (addBtn) addBtn.disabled = count >= MAX_ROUTES;
}

// 追加ボタン
document.getElementById('btn-add-cv-route')?.addEventListener('click', function() {
    addRouteRow('', '', 0);
    markCvSettingsDirty('btn-save-cv-routes');
});

// 保存ボタン
document.getElementById('btn-save-cv-routes')?.addEventListener('click', async function() {
    var rows = document.querySelectorAll('#cv-routes-rows tr');
    var routes = [];
    var hasError = false;

    rows.forEach(function(tr, i) {
        var rkInput = tr.querySelector('input[data-field="route_key"]');
        var li = tr.querySelector('input[data-field="label"]');
        if (!rkInput) return;
        var rk = rkInput.value.trim();
        if (!rk) { hasError = true; return; }
        routes.push({
            route_key: rk,
            label: (li ? li.value.trim() : '') || rk,
            enabled: 1,
            sort_order: i + 1
        });
    });

    if (hasError) {
        alert('GA4イベント名が空の行があります。入力するか、行を削除してください。');
        return;
    }

    var btn = document.getElementById('btn-save-cv-routes');
    var origText = btn.textContent;
    btn.textContent = '保存中...';
    btn.disabled = true;

    try {
        var res = await fetch(restBase + 'actual-cv/routes', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce },
            body: JSON.stringify({
                user_id: userId,
                routes: routes,
                cv_only_configured: !!document.getElementById('cv-only-configured')?.checked,
            }),
            cache: 'no-store'
        });

        if (!res.ok) {
            var errText = await res.text();
            console.error('[GCREV] Save routes HTTP error:', res.status, errText);
            btn.textContent = 'HTTP ' + res.status;
            setTimeout(function() { btn.textContent = origText; }, 3000);
            return;
        }

        var json = await res.json();
        if (json.success) {
            btn.textContent = '保存完了';
            markCvSettingsClean('btn-save-cv-routes');
            await initCvRoutesUI();
            // ゴール確認タブのデータも再取得させる
            document.dispatchEvent(new CustomEvent('gcrev:goalSettingsChanged'));
            setTimeout(function() { btn.textContent = origText; }, 1500);
        } else {
            btn.textContent = (json.message || '保存失敗');
            setTimeout(function() { btn.textContent = origText; }, 3000);
        }
    } catch (e) {
        console.error('[GCREV] Save routes error:', e);
        btn.textContent = 'エラー';
        setTimeout(function() { btn.textContent = origText; }, 2000);
    } finally {
        btn.disabled = false;
    }
});

// HTML属性エスケープ
function escAttr(str) {
    if (!str) return '';
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML.replace(/"/g, '&quot;');
}
</script>

<?php get_footer(); ?>
