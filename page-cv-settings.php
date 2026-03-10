<?php
/*
Template Name: ゴール設定
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'ゴールの数え方設定');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('ゴールの数え方設定', '各種設定'));

get_header();
?>

<style>
/* page-cv-settings — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */

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
</style>

<!-- コンテンツエリア -->
<div class="content-area">

    <!-- キーイベント設定 -->
    <div class="settings-card">
        <h2>
            <span>⚙️</span>
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
                <button type="button" class="btn-outline" id="btn-add-cv-route" data-gcrev-ignore-unsaved="1" style="font-size:13px;">＋ ゴールを追加</button>
                <span id="cv-routes-count" class="cv-routes-count"></span>
            </div>

            <div class="form-group">
                <label for="cv-only-configured" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="cv-only-configured" data-gcrev-ignore-unsaved="1">
                    <span>設定したゴール以外はゴール分析に含めない</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" id="btn-save-cv-routes" data-gcrev-ignore-unsaved="1">💾 設定を保存</button>
            </div>
        </div>
    </div>

</div>

<script>
// ===== グローバル変数 =====
const restBase = '<?php echo esc_js(trailingslashit(rest_url('gcrev_insights/v1'))); ?>';
const wpNonce  = '<?php echo wp_create_nonce('wp_rest'); ?>';
const userId   = <?php echo (int) $user_id; ?>;

// 最大ルート数
const MAX_ROUTES = 20;

// ===== GA4イベント候補（拡張版） =====
var GA4_EVENTS_CACHE = [];
var ga4EventsLoading = false;
var ga4EventsError   = false;

// ===== ページ読み込み時の初期化 =====
document.addEventListener('DOMContentLoaded', function() {
    initCvRoutesUI();
});

// --- Dirty tracking: 変更があったらボタンを青くする ---
function markDirty(btnId) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.style.backgroundColor = '#568184';
    btn.style.borderColor = '#568184';
    btn.style.color = '#fff';
}
function markClean(btnId) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.style.backgroundColor = '';
    btn.style.borderColor = '';
    btn.style.color = '';
}

// ===== GA4イベント候補取得（指数バックオフリトライ付き） =====
async function fetchGa4Events(retries) {
    if (typeof retries === 'undefined') retries = 3;
    ga4EventsLoading = true;
    ga4EventsError   = false;
    updateAllSpinners(true);

    for (var attempt = 0; attempt < retries; attempt++) {
        try {
            var controller = new AbortController();
            var tid = setTimeout(function() { controller.abort(); }, 10000);

            var res = await fetch(
                restBase + 'ga4-key-events?user_id=' + userId + '&_=' + Date.now(),
                { headers: { 'X-WP-Nonce': wpNonce }, signal: controller.signal }
            );
            clearTimeout(tid);

            if (!res.ok) throw new Error('HTTP ' + res.status);

            var json = await res.json();
            if (json.success && Array.isArray(json.events)) {
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
                await new Promise(function(r) { setTimeout(r, 1000 * Math.pow(2, attempt)); });
            }
        }
    }
    // 全リトライ失敗
    ga4EventsLoading = false;
    ga4EventsError   = true;
    updateAllSpinners(false);
    showSuggestErrors();
}

// ===== スピナー表示制御 =====
function updateAllSpinners(show) {
    document.querySelectorAll('.suggest-spinner').forEach(function(el) {
        el.style.display = show ? 'inline' : 'none';
    });
}

// ===== エラーメッセージ表示 =====
function showSuggestErrors() {
    // 既存のエラーがなければ追加
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

// ===== カスタムサジェストUI =====
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

        // 既に設定済みのイベント名を収集（この入力欄自身の値は除く）
        var usedNames = {};
        document.querySelectorAll('#cv-routes-rows input[data-field="route_key"]').forEach(function(inp) {
            if (inp !== input) {
                var v = inp.value.trim();
                if (v) usedNames[v] = true;
            }
        });

        var items = GA4_EVENTS_CACHE.filter(function(e) {
            return !usedNames[e.name];  // 設定済みを除外
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

// ===== ルート設定UIの初期化 =====
async function initCvRoutesUI() {
    // GA4イベント候補をバックグラウンドで取得開始
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
        // チェックボックスの復元（デフォルト: ON）
        var chk = document.getElementById('cv-only-configured');
        if (chk) {
            chk.checked = (json.cv_only_configured == null) ? true : !!json.cv_only_configured;
            chk.addEventListener('change', function() {
                markDirty('btn-save-cv-routes');
            });
        }
    } catch (e) {
        console.error('CV routes load error', e);
    }
}

// ===== ルートエディタ描画 =====
function renderCvRoutesEditor(routes) {
    var tbody = document.getElementById('cv-routes-rows');
    if (!tbody) return;
    tbody.innerHTML = '';
    routes.forEach(function(r, i) {
        addRouteRow(r.route_key, r.label, i + 1);
    });
    markClean('btn-save-cv-routes');
}

// ===== 1行追加 =====
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
        '<td class="drag-handle" title="ドラッグで並べ替え">⠿</td>' +
        '<td><div class="suggest-wrapper">' +
            '<input type="text" value="' + escAttr(eventName || '') + '" data-field="route_key" placeholder="GA4イベント名を入力..." data-gcrev-ignore-unsaved="1" style="font-family:monospace;font-size:13px;" autocomplete="off">' +
            '<span class="suggest-spinner" style="' + (ga4EventsLoading ? '' : 'display:none;') + '">読み込み中…</span>' +
        '</div></td>' +
        '<td><input type="text" value="' + escAttr(label || '') + '" data-field="label" placeholder="表示ラベル" data-gcrev-ignore-unsaved="1"></td>' +
        '<td style="text-align:center;"><button type="button" class="btn-remove-route" style="background:none;border:none;cursor:pointer;font-size:16px;color:#C0392B;" title="削除">✕</button></td>';

    // サジェストUIをアタッチ
    var rkInput = tr.querySelector('input[data-field="route_key"]');
    if (rkInput) { attachSuggest(rkInput); }

    // 変更検知
    tr.querySelectorAll('input').forEach(function(inp) {
        inp.addEventListener('change', function() { markDirty('btn-save-cv-routes'); });
        inp.addEventListener('input', function() { markDirty('btn-save-cv-routes'); });
    });

    // 削除ボタン
    tr.querySelector('.btn-remove-route').addEventListener('click', function() {
        tr.remove();
        markDirty('btn-save-cv-routes');
        updateRoutesCount();
    });

    // ドラッグ&ドロップ
    setupRowDragEvents(tr);

    tbody.appendChild(tr);
    updateRoutesCount();
}

// ===== ドラッグ＆ドロップ並べ替え =====
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
        markDirty('btn-save-cv-routes');
    });
}

// ===== カウンター更新 =====
function updateRoutesCount() {
    var tbody = document.getElementById('cv-routes-rows');
    var counter = document.getElementById('cv-routes-count');
    if (!tbody || !counter) return;
    var count = tbody.querySelectorAll('tr').length;
    counter.textContent = count + ' / ' + MAX_ROUTES + ' 件';
    var addBtn = document.getElementById('btn-add-cv-route');
    if (addBtn) addBtn.disabled = count >= MAX_ROUTES;
}

// ===== 追加ボタン =====
document.getElementById('btn-add-cv-route')?.addEventListener('click', function() {
    addRouteRow('', '', 0);
    markDirty('btn-save-cv-routes');
});

// ===== 保存ボタン =====
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
            btn.textContent = '❌ HTTP ' + res.status;
            setTimeout(function() { btn.textContent = origText; }, 3000);
            return;
        }

        var json = await res.json();
        if (json.success) {
            btn.textContent = '✅ 保存完了';
            markClean('btn-save-cv-routes');
            // 設定を再読み込み
            await initCvRoutesUI();
            setTimeout(function() { btn.textContent = origText; }, 1500);
        } else {
            btn.textContent = '❌ ' + (json.message || '保存失敗');
            setTimeout(function() { btn.textContent = origText; }, 3000);
        }
    } catch (e) {
        console.error('[GCREV] Save routes error:', e);
        btn.textContent = '❌ エラー';
        setTimeout(function() { btn.textContent = origText; }, 2000);
    } finally {
        btn.disabled = false;
    }
});

// ===== ユーティリティ: HTML属性エスケープ =====
function escAttr(str) {
    if (!str) return '';
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML.replace(/"/g, '&quot;');
}
</script>

<?php get_footer(); ?>
