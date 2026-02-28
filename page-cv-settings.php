<?php
/*
Template Name: CV設定
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'CV設定');

// パンくず設定
$breadcrumb = '<a href="' . esc_url(home_url()) . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="' . esc_url(home_url('/analysis/')) . '">分析</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<strong>CV設定</strong>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

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
    box-shadow: 0 0 0 2px rgba(61, 107, 110, 0.12);
}
.cv-routes-table input[data-field="route_key"] {
    font-family: monospace;
}

.cv-routes-count {
    font-size: 12px;
    color: #666666;
    margin-left: 8px;
}

.phone-event-note {
    font-size: 12px;
    color: var(--mw-text-secondary);
    margin-top: 4px;
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">

    <!-- キーイベント設定 -->
    <div class="settings-card">
        <h2>
            <span>⚙️</span>
            <span>キーイベント設定</span>
        </h2>
        <p class="cv-settings-description">
            お問い合わせとしてカウントするGA4イベント（キーイベント）を設定します。
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
                <button type="button" class="btn-outline" id="btn-add-cv-route" data-gcrev-ignore-unsaved="1" style="font-size:13px;">＋ キーイベントを追加</button>
                <span id="cv-routes-count" class="cv-routes-count"></span>
            </div>

            <div class="form-group">
                <label for="cv-only-configured" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="cv-only-configured" data-gcrev-ignore-unsaved="1">
                    <span>設定したキーイベント以外はCV分析に含めない</span>
                </label>
            </div>

            <div class="form-group" id="phone-event-row" style="display:none;">
                <label for="phone-event-name">電話タップのGA4イベント名（常に加算）</label>
                <input type="text" id="phone-event-name" list="ga4-key-events-list" placeholder="例: phone_tap" data-gcrev-ignore-unsaved="1">
                <small class="phone-event-note">上のチェックがONでも、ここで指定した電話タップイベントは常にCV合計に加算されます</small>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" id="btn-save-cv-routes" data-gcrev-ignore-unsaved="1">💾 設定を保存</button>
            </div>

            <datalist id="ga4-key-events-list"></datalist>
        </div>
    </div>

</div>

<script>
// ===== グローバル変数 =====
const restBase = '<?php echo esc_js(trailingslashit(rest_url('gcrev_insights/v1'))); ?>';
const wpNonce  = '<?php echo wp_create_nonce('wp_rest'); ?>';
const userId   = <?php echo (int) $user_id; ?>;

// GA4キーイベント候補
let GA4_KEY_EVENTS = {};

// 最大ルート数
const MAX_ROUTES = 5;

// ===== ページ読み込み時の初期化 =====
document.addEventListener('DOMContentLoaded', function() {
    initCvRoutesUI();
});

// --- Dirty tracking: 変更があったらボタンを青くする ---
function markDirty(btnId) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.style.backgroundColor = '#3D6B6E';
    btn.style.borderColor = '#3D6B6E';
    btn.style.color = '#fff';
}
function markClean(btnId) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.style.backgroundColor = '';
    btn.style.borderColor = '';
    btn.style.color = '';
}

// ===== GA4キーイベント候補を取得してdatalist生成 =====
async function fetchGa4KeyEvents() {
    try {
        const res = await fetch(restBase + 'ga4-key-events?user_id=' + userId + '&_=' + Date.now(), {
            headers: { 'X-WP-Nonce': wpNonce }
        });
        if (!res.ok) return;
        const json = await res.json();
        if (json.success && json.data) {
            GA4_KEY_EVENTS = json.data;
            const dl = document.getElementById('ga4-key-events-list');
            if (dl) {
                dl.innerHTML = '';
                Object.keys(GA4_KEY_EVENTS).forEach(name => {
                    const opt = document.createElement('option');
                    opt.value = name;
                    opt.textContent = name + ' (' + GA4_KEY_EVENTS[name] + '件)';
                    dl.appendChild(opt);
                });
            }
        }
    } catch (e) {
        console.error('GA4 key events load error', e);
    }
}

// ===== ルート設定UIの初期化 =====
async function initCvRoutesUI() {
    await fetchGa4KeyEvents();
    try {
        const res = await fetch(restBase + 'actual-cv/routes?_=' + Date.now(), {
            headers: { 'X-WP-Nonce': wpNonce }
        });
        if (!res.ok) return;
        const json = await res.json();
        if (json.success && Array.isArray(json.data)) {
            renderCvRoutesEditor(json.data);
            updateRoutesCount();
        }
        // チェックボックス・電話タップ設定の復元
        const chk = document.getElementById('cv-only-configured');
        const phoneRow = document.getElementById('phone-event-row');
        const phoneInput = document.getElementById('phone-event-name');
        if (chk) {
            chk.checked = !!json.cv_only_configured;
            if (phoneRow) phoneRow.style.display = chk.checked ? 'block' : 'none';
            chk.addEventListener('change', () => {
                if (phoneRow) phoneRow.style.display = chk.checked ? 'block' : 'none';
                markDirty('btn-save-cv-routes');
            });
        }
        if (phoneInput) {
            phoneInput.value = json.phone_event_name || '';
            phoneInput.addEventListener('input', () => markDirty('btn-save-cv-routes'));
        }
    } catch (e) {
        console.error('CV routes load error', e);
    }
}

// ===== ルートエディタ描画 =====
function renderCvRoutesEditor(routes) {
    const tbody = document.getElementById('cv-routes-rows');
    if (!tbody) return;
    tbody.innerHTML = '';
    routes.forEach((r, i) => {
        addRouteRow(r.route_key, r.label, i + 1);
    });
    markClean('btn-save-cv-routes');
}

// ===== 1行追加 =====
function addRouteRow(eventName, label, order) {
    const tbody = document.getElementById('cv-routes-rows');
    if (!tbody) return;
    const currentCount = tbody.querySelectorAll('tr').length;
    if (currentCount >= MAX_ROUTES) {
        alert('キーイベントは最大' + MAX_ROUTES + '件まで設定できます');
        return;
    }
    const tr = document.createElement('tr');
    tr.draggable = true;
    tr.innerHTML =
        '<td class="drag-handle" title="ドラッグで並べ替え">⠿</td>' +
        '<td><input type="text" list="ga4-key-events-list" value="' + escAttr(eventName || '') + '" data-field="route_key" placeholder="GA4イベント名を入力..." data-gcrev-ignore-unsaved="1" style="font-family:monospace;font-size:13px;"></td>' +
        '<td><input type="text" value="' + escAttr(label || '') + '" data-field="label" placeholder="表示ラベル" data-gcrev-ignore-unsaved="1"></td>' +
        '<td style="text-align:center;"><button type="button" class="btn-remove-route" style="background:none;border:none;cursor:pointer;font-size:16px;color:#C0392B;" title="削除">✕</button></td>';

    // 変更検知
    tr.querySelectorAll('input').forEach(inp => {
        inp.addEventListener('change', () => markDirty('btn-save-cv-routes'));
        inp.addEventListener('input', () => markDirty('btn-save-cv-routes'));
    });

    // 削除ボタン
    tr.querySelector('.btn-remove-route').addEventListener('click', () => {
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
let dragSrcRow = null;

function setupRowDragEvents(tr) {
    tr.addEventListener('dragstart', (e) => {
        dragSrcRow = tr;
        tr.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    tr.addEventListener('dragend', () => {
        tr.classList.remove('dragging');
        document.querySelectorAll('#cv-routes-rows tr.drag-over').forEach(r => r.classList.remove('drag-over'));
        dragSrcRow = null;
    });
    tr.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (dragSrcRow && dragSrcRow !== tr) {
            tr.classList.add('drag-over');
        }
    });
    tr.addEventListener('dragleave', () => {
        tr.classList.remove('drag-over');
    });
    tr.addEventListener('drop', (e) => {
        e.preventDefault();
        tr.classList.remove('drag-over');
        if (!dragSrcRow || dragSrcRow === tr) return;
        const tbody = tr.parentNode;
        const rows = [...tbody.querySelectorAll('tr')];
        const fromIdx = rows.indexOf(dragSrcRow);
        const toIdx = rows.indexOf(tr);
        if (fromIdx < toIdx) {
            tbody.insertBefore(dragSrcRow, tr.nextSibling);
        } else {
            tbody.insertBefore(dragSrcRow, tr);
        }
        markDirty('btn-save-cv-routes');
    });
}

// ===== カウンター更新 =====
function updateRoutesCount() {
    const tbody = document.getElementById('cv-routes-rows');
    const counter = document.getElementById('cv-routes-count');
    if (!tbody || !counter) return;
    const count = tbody.querySelectorAll('tr').length;
    counter.textContent = count + ' / ' + MAX_ROUTES + ' 件';
    const addBtn = document.getElementById('btn-add-cv-route');
    if (addBtn) addBtn.disabled = count >= MAX_ROUTES;
}

// ===== 追加ボタン =====
document.getElementById('btn-add-cv-route')?.addEventListener('click', () => {
    addRouteRow('', '', 0);
    markDirty('btn-save-cv-routes');
});

// ===== 保存ボタン =====
document.getElementById('btn-save-cv-routes')?.addEventListener('click', async () => {
    const rows = document.querySelectorAll('#cv-routes-rows tr');
    const routes = [];
    let hasError = false;

    rows.forEach((tr, i) => {
        const rkInput = tr.querySelector('input[data-field="route_key"]');
        const li = tr.querySelector('input[data-field="label"]');
        if (!rkInput) return;
        const rk = rkInput.value.trim();
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

    const btn = document.getElementById('btn-save-cv-routes');
    const origText = btn.textContent;
    btn.textContent = '保存中...';
    btn.disabled = true;

    try {
        const res = await fetch(restBase + 'actual-cv/routes', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce },
            body: JSON.stringify({
                user_id: userId,
                routes: routes,
                cv_only_configured: !!document.getElementById('cv-only-configured')?.checked,
                phone_event_name: (document.getElementById('phone-event-name')?.value || '').trim(),
            }),
            cache: 'no-store'
        });

        if (!res.ok) {
            const errText = await res.text();
            console.error('[GCREV] Save routes HTTP error:', res.status, errText);
            btn.textContent = '❌ HTTP ' + res.status;
            setTimeout(() => { btn.textContent = origText; }, 3000);
            return;
        }

        const json = await res.json();
        if (json.success) {
            btn.textContent = '✅ 保存完了';
            markClean('btn-save-cv-routes');
            // 設定を再読み込み
            await initCvRoutesUI();
            setTimeout(() => { btn.textContent = origText; }, 1500);
        } else {
            btn.textContent = '❌ ' + (json.message || '保存失敗');
            setTimeout(() => { btn.textContent = origText; }, 3000);
        }
    } catch (e) {
        console.error('[GCREV] Save routes error:', e);
        btn.textContent = '❌ エラー';
        setTimeout(() => { btn.textContent = origText; }, 2000);
    } finally {
        btn.disabled = false;
    }
});

// ===== ユーティリティ: HTML属性エスケープ =====
function escAttr(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML.replace(/"/g, '&quot;');
}
</script>

<?php get_footer(); ?>
