<?php
/*
Template Name: お問い合わせ一覧
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = mimamori_get_view_user_object();
$user_id      = mimamori_get_view_user_id();

// 表示するのは「閲覧主体ユーザー（管理者なら自分自身、view-as 中ならその対象クライアント、
// 一般ログインなら自分）」のメタに保存された設定のみ。他クライアントの個人情報は
// REST 側 resolve_target_user_id() が user_id 改ざんをブロックするため漏れない。

// 設定が無い場合の案内（管理画面への誘導）
$inquiries_endpoint_set = false;
if ( class_exists( 'Mimamori_Inquiries_Fetcher' ) ) {
    $inquiries_endpoint_set = ( Mimamori_Inquiries_Fetcher::get_endpoint( $user_id ) !== '' );
}
if ( ! $inquiries_endpoint_set ) {
    set_query_var( 'gcrev_page_title', 'お問い合わせ一覧' );
    set_query_var( 'gcrev_page_subtitle', '契約サイトの問い合わせ取得設定が未登録です。' );
    set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'お問い合わせ一覧' ) );
    get_header();
    $is_admin = current_user_can( 'manage_options' );
    ?>
    <div style="max-width:760px;margin:40px auto;padding:32px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;">
        <h2 style="margin-top:0;">📋 まずは取得設定を登録してください</h2>
        <p>この一覧を表示するには、お使いの契約サイト（コーポレートサイト等）に「みまもりウェブ 問い合わせ集計API」プラグインを導入し、URL とトークンを登録する必要があります。</p>
        <?php if ( $is_admin ) : ?>
            <p>管理画面の「<a href="<?php echo esc_url( admin_url( 'admin.php?page=gcrev-inquiries-settings' ) ); ?>"><strong>みまもりウェブ → ✉️ 問い合わせ取得</strong></a>」から、対象ユーザーを選んで設定してください。</p>
            <p style="color:#6b7280;font-size:13px;">他のクライアントの問い合わせを確認したい場合は、画面右上の「事業者ビュー切替」で対象クライアントになりきった状態でこのページにアクセスしてください。</p>
        <?php else : ?>
            <p>サポート担当者にご連絡いただくか、<a href="<?php echo esc_url( home_url( '/inquiry/' ) ); ?>">お問い合わせ</a>ページからご相談ください。</p>
        <?php endif; ?>
    </div>
    <?php
    get_footer();
    exit;
}

set_query_var( 'gcrev_page_title', 'お問い合わせ一覧' );
set_query_var( 'gcrev_page_subtitle', '契約サイトに届いた問い合わせ・見学会申込みを月別に確認できます（AIで分類済み）。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'お問い合わせ一覧' ) );

get_header();
?>

<style>
.iq-wrap { max-width: 1280px; margin: 0 auto; padding: 16px; }
.iq-header { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
.iq-controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.iq-controls select, .iq-controls button { padding:8px 14px; border:1px solid #d0d5dd; border-radius:8px; font-size:13px; background:#fff; cursor:pointer; }
.iq-controls button.primary { background:#1a73e8; color:#fff; border-color:#1a73e8; }
.iq-summary { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; margin-bottom:16px; display:flex; gap:24px; flex-wrap:wrap; }
.iq-summary .stat { font-size:13px; color:#475569; }
.iq-summary .stat strong { font-size:18px; color:#0f172a; margin-left:6px; }
.iq-table { width:100%; border-collapse:collapse; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.iq-table th, .iq-table td { padding:12px 14px; text-align:left; vertical-align:top; border-bottom:1px solid #f1f5f9; }
.iq-table thead th { background:#fffbeb; font-size:13px; color:#374151; font-weight:600; }
.iq-table tbody tr.invalid { background:#fafafa; color:#9ca3af; }
.iq-cat-badge { display:inline-block; padding:3px 10px; border-radius:6px; font-size:12px; font-weight:600; white-space:nowrap; }
.iq-cat-inquiry { background:#fed7aa; color:#7c2d12; }
.iq-cat-event { background:#bbf7d0; color:#14532d; }
.iq-cat-recruit { background:#bfdbfe; color:#1e3a8a; }
.iq-cat-sales { background:#fecaca; color:#7f1d1d; }
.iq-cat-unsubscribe { background:#e9d5ff; color:#581c87; }
.iq-cat-spam { background:#d1d5db; color:#1f2937; }
.iq-cat-other { background:#e5e7eb; color:#374151; }
.iq-tag { display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; background:#e5f3ff; color:#0c4a6e; margin-left:4px; }
.iq-detail { margin-top:8px; padding:8px 10px; background:#f9fafb; border-radius:6px; font-size:12px; color:#6b7280; white-space:pre-wrap; font-family:inherit; max-height:300px; overflow:auto; }
.iq-loading, .iq-empty { padding:40px; text-align:center; color:#6b7280; }
.iq-error { padding:16px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; color:#991b1b; }
@media (max-width: 768px) {
    .iq-table thead { display:none; }
    .iq-table tr { display:block; padding:12px; border-bottom:8px solid #f1f5f9; }
    .iq-table td { display:block; border:none; padding:4px 0; }
    .iq-table td::before { content: attr(data-label) "："; font-weight:600; color:#475569; margin-right:6px; }
}
</style>

<div class="iq-wrap">
    <div class="iq-header">
        <div class="iq-controls">
            <label for="iq-month">期間:</label>
            <select id="iq-month"><option value="">読み込み中…</option></select>
            <label><input type="checkbox" id="iq-valid-only" checked> 有効のみ表示</label>
            <button id="iq-refresh">🔄 当月の最新を再取得</button>
        </div>
    </div>

    <div id="iq-bootstrap" class="iq-loading" style="display:none;"></div>
    <div id="iq-summary" class="iq-summary" style="display:none;"></div>
    <div id="iq-content"><div class="iq-loading">読み込み中…</div></div>
</div>

<script>
(function() {
    const restBase = <?php echo wp_json_encode( esc_url_raw( rest_url( 'mimamori/v1/' ) ) ); ?>;
    const nonce    = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    const userId   = <?php echo (int) $user_id; ?>;
    const monthSel = document.getElementById('iq-month');
    const validOnly = document.getElementById('iq-valid-only');
    const refreshBtn = document.getElementById('iq-refresh');
    const summary = document.getElementById('iq-summary');
    const content = document.getElementById('iq-content');
    const bootstrap = document.getElementById('iq-bootstrap');

    function api(path, params = {}) {
        const url = new URL( restBase + path );
        Object.entries(params).forEach(([k,v]) => url.searchParams.set(k, v));
        return fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        }).then(r => r.json());
    }

    function categoryClass(cat) {
        const map = {
            'お問い合わせ・資料請求':'iq-cat-inquiry',
            '見学会・イベント':'iq-cat-event',
            '採用・求人':'iq-cat-recruit',
            '営業':'iq-cat-sales',
            '配信停止':'iq-cat-unsubscribe',
            'SPAM':'iq-cat-spam',
            'その他':'iq-cat-other',
        };
        return map[cat] || 'iq-cat-other';
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    function renderItems(items, includeExcluded) {
        if (!items || items.length === 0) {
            content.innerHTML = '<div class="iq-empty">📭 問い合わせ情報がまだありません。</div>';
            return;
        }
        const filtered = includeExcluded ? items : items.filter(it => it.ai_valid);
        if (filtered.length === 0) {
            content.innerHTML = '<div class="iq-empty">📭 有効な問い合わせはまだありません。</div>';
            return;
        }
        let html = '<table class="iq-table"><thead><tr>'
            + '<th style="width:40px;">#</th>'
            + '<th style="width:110px;">日付</th>'
            + '<th style="width:140px;">種別</th>'
            + '<th style="width:140px;">お名前</th>'
            + '<th style="width:140px;">地域</th>'
            + '<th>内容</th>'
            + '</tr></thead><tbody>';
        filtered.forEach((it, i) => {
            const cat = it.ai_category || 'その他';
            const valid = !!it.ai_valid;
            const dateOnly = (it.date || '').slice(0,10);
            const tagsHtml = (it.ai_tags || []).map(t => `<span class="iq-tag">${escapeHtml(t)}</span>`).join('');
            html += `<tr class="${valid ? '' : 'invalid'}">`
                + `<td data-label="No">${i+1}</td>`
                + `<td data-label="日付">${escapeHtml(dateOnly)}</td>`
                + `<td data-label="種別"><span class="iq-cat-badge ${categoryClass(cat)}">${escapeHtml(cat)}</span></td>`
                + `<td data-label="お名前">${escapeHtml(it.name || '')}</td>`
                + `<td data-label="地域">${escapeHtml(it.ai_region || '—')}</td>`
                + `<td data-label="内容"><details><summary>${escapeHtml(it.ai_summary || (it.message || '').slice(0,140))} ${tagsHtml}</summary>`
                + `<div class="iq-detail">`
                + `<strong>メール:</strong> ${escapeHtml(it.email || '')}<br>`
                + `<strong>送信元:</strong> ${escapeHtml(it.source || '')}<br><br>`
                + escapeHtml(it.message || '')
                + `</div></details></td>`
                + `</tr>`;
        });
        html += '</tbody></table>';
        content.innerHTML = html;
    }

    function loadMonth(ym, force = false) {
        if (!ym) return;
        const [y, m] = ym.split('-');
        content.innerHTML = '<div class="iq-loading">📡 取得中…</div>';
        summary.style.display = 'none';

        const params = { year: y, month: parseInt(m,10), user_id: userId, include_excluded: 1 };
        if (force) params.force = 1;
        api('inquiries/list', params).then(res => {
            if (!res.success) {
                content.innerHTML = `<div class="iq-error">取得に失敗: ${escapeHtml(res.message || '')}</div>`;
                return;
            }
            const items = res.items || [];
            const validCount = items.filter(it => it.ai_valid).length;
            const excludedCount = items.length - validCount;
            const byCategory = {};
            items.forEach(it => {
                const c = it.ai_category || 'その他';
                byCategory[c] = (byCategory[c] || 0) + 1;
            });
            let summaryHtml = `<span class="stat">合計<strong>${items.length}</strong></span>`
                + `<span class="stat" style="color:#1e8e3e;">有効<strong>${validCount}</strong></span>`
                + `<span class="stat" style="color:#b00;">除外<strong>${excludedCount}</strong></span>`;
            Object.entries(byCategory).forEach(([cat, cnt]) => {
                summaryHtml += `<span class="stat"><span class="iq-cat-badge ${categoryClass(cat)}">${escapeHtml(cat)}</span><strong>${cnt}</strong></span>`;
            });
            summary.innerHTML = summaryHtml;
            summary.style.display = 'flex';
            renderItems(items, !validOnly.checked ? true : false);
            content.dataset.items = JSON.stringify(items);
        }).catch(err => {
            content.innerHTML = `<div class="iq-error">通信エラー: ${escapeHtml(err.message || err)}</div>`;
        });
    }

    function rerenderFromCache() {
        const cached = content.dataset.items;
        if (!cached) return;
        try {
            const items = JSON.parse(cached);
            renderItems(items, !validOnly.checked);
        } catch(_) {}
    }

    // 未キャッシュの月を順次取得（自動初回ブートストラップ）
    async function bootstrapUncached(months) {
        const uncached = months.filter(m => !m.is_cached);
        if (uncached.length === 0) return;
        bootstrap.style.display = 'block';
        for (let i = 0; i < uncached.length; i++) {
            const m = uncached[i];
            bootstrap.textContent = `🤖 初回データ準備中… ${m.year_month} を AI 分類中（${i+1}/${uncached.length}）`;
            const [y, mo] = m.year_month.split('-');
            try {
                await api('inquiries/list', {
                    year: y, month: parseInt(mo,10), user_id: userId,
                    include_excluded: 1
                });
            } catch(_) {}
        }
        bootstrap.style.display = 'none';
    }

    // 当月の年月（ブラウザ時刻ベース）
    function getCurrentYM() {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}`;
    }

    function renderMonthOptions(months) {
        if (months.length === 0) {
            monthSel.innerHTML = '<option value="">— データなし —</option>';
            return;
        }
        monthSel.innerHTML = months.map(m => {
            const cur = m.is_current ? '（当月）' : '';
            const cached = m.is_cached ? '✓' : '⏳';
            return `<option value="${m.year_month}" data-current="${m.is_current?1:0}" data-cached="${m.is_cached?1:0}">${cached} ${m.year_month}${cur}（合計${m.total} / 有効${m.valid_count}）</option>`;
        }).join('');
    }

    // 月リスト取得 → 未キャッシュ月を一括取得 → 月リスト再読込 → 最新月を表示
    async function init() {
        let res = await api('inquiries/months', { user_id: userId });
        let months = (res && res.months) || [];
        if (months.length === 0) {
            monthSel.innerHTML = '<option value="">— データなし —</option>';
            content.innerHTML = '<div class="iq-empty">📭 問い合わせ情報がまだありません。<br><br>「🔄 当月の最新を再取得」を押して当月分を取得するか、管理画面の「問い合わせ取得設定」で過去分の取得を実行してください。</div>';
            return;
        }
        renderMonthOptions(months);

        // 未キャッシュ月を自動取得
        await bootstrapUncached(months);

        // 取得後にもう一度月リストを取り直して is_cached を反映
        res = await api('inquiries/months', { user_id: userId });
        months = (res && res.months) || months;
        renderMonthOptions(months);

        // 最新月（先頭）を表示
        if (monthSel.value) loadMonth(monthSel.value);
    }

    // 「当月の最新を再取得」: 常時表示。当月レコードがまだ無くても新規作成して表示する
    async function refreshCurrentMonth() {
        const currentYM = getCurrentYM();
        const [y, m] = currentYM.split('-');
        bootstrap.style.display = 'block';
        bootstrap.textContent = '🤖 当月の最新を取得中…（数秒かかります）';
        summary.style.display = 'none';
        content.innerHTML = '';
        try {
            const res = await api('inquiries/list', {
                year: y, month: parseInt(m, 10), user_id: userId,
                include_excluded: 1, force: 1
            });
            // 月リストを再取得して当月オプションを反映
            const monthsRes = await api('inquiries/months', { user_id: userId });
            const months = (monthsRes && monthsRes.months) || [];
            renderMonthOptions(months);
            // 当月を選択
            if (Array.from(monthSel.options).some(o => o.value === currentYM)) {
                monthSel.value = currentYM;
            }
            bootstrap.style.display = 'none';

            if (!res.success) {
                content.innerHTML = `<div class="iq-error">取得に失敗: ${escapeHtml(res.message || '')}</div>`;
                return;
            }
            // そのまま renderItems / summary を当月の結果で表示
            const items = res.items || [];
            content.dataset.items = JSON.stringify(items);
            if (items.length === 0) {
                summary.style.display = 'none';
                content.innerHTML = '<div class="iq-empty">📭 当月の問い合わせ情報がまだありません。</div>';
                return;
            }
            // サマリー再構築
            const validCount = items.filter(it => it.ai_valid).length;
            const excludedCount = items.length - validCount;
            const byCategory = {};
            items.forEach(it => {
                const c = it.ai_category || 'その他';
                byCategory[c] = (byCategory[c] || 0) + 1;
            });
            let summaryHtml = `<span class="stat">合計<strong>${items.length}</strong></span>`
                + `<span class="stat" style="color:#1e8e3e;">有効<strong>${validCount}</strong></span>`
                + `<span class="stat" style="color:#b00;">除外<strong>${excludedCount}</strong></span>`;
            Object.entries(byCategory).forEach(([cat, cnt]) => {
                summaryHtml += `<span class="stat"><span class="iq-cat-badge ${categoryClass(cat)}">${escapeHtml(cat)}</span><strong>${cnt}</strong></span>`;
            });
            summary.innerHTML = summaryHtml;
            summary.style.display = 'flex';
            renderItems(items, !validOnly.checked);
        } catch (err) {
            bootstrap.style.display = 'none';
            content.innerHTML = `<div class="iq-error">通信エラー: ${escapeHtml(err.message || err)}</div>`;
        }
    }

    monthSel.addEventListener('change', () => loadMonth(monthSel.value));
    refreshBtn.addEventListener('click', refreshCurrentMonth);
    validOnly.addEventListener('change', rerenderFromCache);

    init();
})();
</script>

<?php get_footer();
