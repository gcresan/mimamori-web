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

// 表示モード判定:
//   - inquiries: 問い合わせ集計プラグイン連携あり (AI 分類 + フォーム本文取得)
//   - ga4      : 未連携 → GA4 キーイベント (wp_gcrev_cv_routes 設定) を一覧表示
$inquiries_endpoint_set = false;
if ( class_exists( 'Mimamori_Inquiries_Fetcher' ) ) {
    $inquiries_endpoint_set = ( Mimamori_Inquiries_Fetcher::get_endpoint( $user_id ) !== '' );
}
$page_mode = $inquiries_endpoint_set ? 'inquiries' : 'ga4';

// 設定済み GA4 キーイベント数を確認 (ga4 モード時、設定ゼロなら誘導案内)
$ga4_routes_count = 0;
if ( $page_mode === 'ga4' && class_exists( 'Gcrev_Insight_API' ) ) {
    try {
        $tmp_api = new Gcrev_Insight_API( false );
        $ga4_routes_count = count( $tmp_api->get_enabled_cv_routes( $user_id ) );
        if ( get_user_meta( $user_id, '_gcrev_phone_event_name', true ) ) {
            $ga4_routes_count++;
        }
    } catch ( \Throwable $e ) { /* fail-safe: 0 のまま */ }
}

// ゴール未設定 (プラグインも未連携 かつ GA4 キーイベントも未設定) → 設定誘導
if ( $page_mode === 'ga4' && $ga4_routes_count === 0 ) {
    set_query_var( 'gcrev_page_title', 'お問い合わせ一覧' );
    set_query_var( 'gcrev_page_subtitle', 'ゴール (問い合わせ) 集計に必要な設定が未登録です。' );
    set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'お問い合わせ一覧' ) );
    get_header();
    $is_admin = current_user_can( 'manage_options' );
    ?>
    <div style="max-width:760px;margin:40px auto;padding:32px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;">
        <h2 style="margin-top:0;">📋 まずはゴール集計の設定を登録してください</h2>
        <p>このページを表示するには、以下のどちらかを設定してください:</p>
        <ul style="line-height:1.9;">
            <li><strong>(A) フォーム本文も含めた詳細一覧</strong>: 契約サイトに「みまもりウェブ 問い合わせ集計API」プラグインを導入し、URL とトークンを登録</li>
            <li><strong>(B) GA4 キーイベントベースの簡易一覧</strong>: ゴール設定画面で集計対象の GA4 イベントを登録</li>
        </ul>
        <?php if ( $is_admin ) : ?>
            <p>管理画面の「<a href="<?php echo esc_url( admin_url( 'admin.php?page=gcrev-inquiries-settings' ) ); ?>"><strong>みまもりウェブ → ✉️ 問い合わせ取得</strong></a>」、または「<a href="<?php echo esc_url( home_url( '/analysis/cv-review/' ) ); ?>"><strong>ゴール設定ページ</strong></a>」から設定してください。</p>
            <p style="color:#6b7280;font-size:13px;">他のクライアントを確認したい場合は、画面右上の「事業者ビュー切替」で対象クライアントになりきった状態でアクセスしてください。</p>
        <?php else : ?>
            <p>サポート担当者にご連絡いただくか、<a href="<?php echo esc_url( home_url( '/inquiry/' ) ); ?>">お問い合わせ</a>ページからご相談ください。</p>
        <?php endif; ?>
    </div>
    <?php
    get_footer();
    exit;
}

set_query_var( 'gcrev_page_title', 'お問い合わせ一覧' );
if ( $page_mode === 'inquiries' ) {
    set_query_var( 'gcrev_page_subtitle', '契約サイトに届いた問い合わせ・見学会申込みを月別に確認できます（AIで分類済み）。' );
} else {
    set_query_var( 'gcrev_page_subtitle', 'GA4 で計測したゴール達成イベント (問い合わせ・電話タップ等) を月別に確認できます。' );
}
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'お問い合わせ一覧' ) );

get_header();
?>

<style>
.iq-wrap { max-width: 1280px; margin: 0 auto; padding: 16px; }
.iq-header { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
.iq-controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.iq-controls select, .iq-controls button { padding:8px 14px; border:1px solid #d0d5dd; border-radius:8px; font-size:13px; background:#fff; cursor:pointer; }
.iq-controls button.primary { background:#1a73e8; color:#fff; border-color:#1a73e8; }
.iq-summary { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.iq-summary .stat { font-size:13px; color:#475569; }
.iq-summary .stat strong { font-size:18px; color:#0f172a; margin-left:6px; }
/* クリック可能なフィルタタブ (合計/有効/除外) */
.iq-summary .iq-filter {
    cursor:pointer; user-select:none; padding:6px 14px; border-radius:8px;
    border:1px solid transparent; background:#fff; transition: all .15s ease;
}
.iq-summary .iq-filter:hover { background:#eef2f7; border-color:#cbd5e1; }
/* 非アクティブ時のラベル色（タブごとに色分け） */
.iq-summary .iq-filter.iq-filter-valid    { color:#1e8e3e; }
.iq-summary .iq-filter.iq-filter-excluded { color:#b00; }
/* アクティブ時: 背景色 + 白文字 (inline style と衝突しないよう CSS のみで完結) */
.iq-summary .iq-filter.active { background:#1a73e8; color:#fff !important; border-color:#1a73e8; }
.iq-summary .iq-filter.active strong { color:#fff !important; }
.iq-summary .iq-filter.iq-filter-valid.active    { background:#16a34a; border-color:#16a34a; }
.iq-summary .iq-filter.iq-filter-excluded.active { background:#dc2626; border-color:#dc2626; }
.iq-summary .iq-divider { width:1px; align-self:stretch; background:#e2e8f0; }
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
.iq-actions { display:flex; gap:6px; flex-wrap:wrap; }
.iq-actions button {
    font-size:11px; padding:4px 10px; border-radius:6px; cursor:pointer;
    border:1px solid #d1d5db; background:#fff; color:#374151;
    transition: background-color .15s ease, border-color .15s ease;
}
.iq-actions button:hover { background:#f9fafb; border-color:#9ca3af; }
.iq-actions button.iq-btn-exclude { color:#b91c1c; border-color:#fca5a5; }
.iq-actions button.iq-btn-exclude:hover { background:#fef2f2; }
.iq-actions button.iq-btn-validate { color:#15803d; border-color:#86efac; }
.iq-actions button.iq-btn-validate:hover { background:#f0fdf4; }
.iq-actions button.iq-btn-reset { color:#6b7280; border-color:#e5e7eb; font-size:10px; }
.iq-manual-mark {
    display:inline-block; padding:1px 6px; border-radius:4px;
    font-size:10px; font-weight:600; margin-left:4px;
}
.iq-manual-mark.valid    { background:#dcfce7; color:#166534; }
.iq-manual-mark.excluded { background:#fee2e2; color:#991b1b; }
@media (max-width: 768px) {
    .iq-table thead { display:none; }
    .iq-table tr { display:block; padding:12px; border-bottom:8px solid #f1f5f9; }
    .iq-table td { display:block; border:none; padding:4px 0; }
    .iq-table td::before { content: attr(data-label) "："; font-weight:600; color:#475569; margin-right:6px; }
}
</style>

<div class="iq-wrap" data-mode="<?php echo esc_attr( $page_mode ); ?>">
    <?php if ( $page_mode === 'ga4' ) : ?>
        <div style="background:#eef6ff;border:1px solid #bdd7f5;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#1e40af;line-height:1.7;">
            <strong>📊 GA4 ベース表示中</strong><br>
            問い合わせ集計プラグイン未連携のため、GA4 のキーイベント（ゴール設定で登録されたイベント）を表示しています。フォーム本文・送信者情報は取得できませんが、「✕ 除外」を押すとそのゴールはダッシュボードの集計から除外されます。プラグインを導入すると AI による SPAM・営業の自動除外や本文確認が可能になります。
        </div>
    <?php endif; ?>
    <div class="iq-header">
        <div class="iq-controls">
            <label for="iq-month">期間:</label>
            <select id="iq-month"><option value="">読み込み中…</option></select>
            <?php if ( $page_mode === 'inquiries' ) : ?>
                <button id="iq-refresh">🔄 当月の最新を再取得</button>
            <?php endif; ?>
        </div>
    </div>

    <div id="iq-bootstrap" class="iq-loading" style="display:none;"></div>
    <div id="iq-summary" class="iq-summary" style="display:none;"></div>
    <div id="iq-content"><div class="iq-loading">読み込み中…</div></div>
</div>

<script>
(function() {
    const MODE     = <?php echo wp_json_encode( $page_mode ); ?>; // 'inquiries' | 'ga4'
    const restMimamori = <?php echo wp_json_encode( esc_url_raw( rest_url( 'mimamori/v1/' ) ) ); ?>;
    const restGcrev    = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gcrev/v1/' ) ) ); ?>;
    const restBase = MODE === 'ga4' ? restGcrev : restMimamori;
    const nonce    = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    const userId   = <?php echo (int) $user_id; ?>;
    const monthSel = document.getElementById('iq-month');
    const refreshBtn = document.getElementById('iq-refresh'); // GA4モードでは null
    const summary = document.getElementById('iq-summary');
    const content = document.getElementById('iq-content');
    const bootstrap = document.getElementById('iq-bootstrap');

    // 表示フィルタ: 'valid' (有効のみ・既定) / 'excluded' (除外のみ・復活操作用) / 'all'
    let _filter = 'valid';

    function api(path, params = {}, baseOverride = null) {
        const url = new URL( (baseOverride || restBase) + path );
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

    // モードによる「有効」判定の違いを吸収:
    //   inquiries モード: effective_valid (AI + 手動 + キーワード除外を反映)
    //   ga4 モード      : status !== 2 (status: 0=未判定, 1=有効, 2=除外)
    function isEffectiveValid(it) {
        if (MODE === 'ga4') {
            return (typeof it.status === 'number') ? (it.status !== 2) : true;
        }
        return ('effective_valid' in it) ? !!it.effective_valid : !!it.ai_valid;
    }

    function renderItems(items) {
        if (MODE === 'ga4') { return renderItemsGa4(items); }
        if (!items || items.length === 0) {
            content.innerHTML = '<div class="iq-empty">📭 問い合わせ情報がまだありません。</div>';
            return;
        }
        // 現在のフィルタ (_filter) に応じて表示対象を絞り込む
        let filtered;
        let emptyMsg;
        if (_filter === 'valid') {
            filtered = items.filter(isEffectiveValid);
            emptyMsg = '📭 有効な問い合わせはまだありません。';
        } else if (_filter === 'excluded') {
            filtered = items.filter(it => !isEffectiveValid(it));
            emptyMsg = '✅ 除外された問い合わせはありません。';
        } else {
            filtered = items.slice();
            emptyMsg = '📭 問い合わせ情報がまだありません。';
        }
        if (filtered.length === 0) {
            content.innerHTML = `<div class="iq-empty">${emptyMsg}</div>`;
            return;
        }
        // 時系列順に並び替え（古い→新しい）
        filtered.sort((a, b) => String(a.date || '').localeCompare(String(b.date || '')));

        let html = '<table class="iq-table"><thead><tr>'
            + '<th style="width:40px;">#</th>'
            + '<th style="width:160px;">日時</th>'
            + '<th style="width:140px;">種別</th>'
            + '<th style="width:140px;">お名前</th>'
            + '<th style="width:120px;">地域</th>'
            + '<th>内容</th>'
            + '<th style="width:130px;">操作</th>'
            + '</tr></thead><tbody>';
        filtered.forEach((it, i) => {
            const cat = it.ai_category || 'その他';
            const valid = isEffectiveValid(it);
            const manual = it.manual_status || null; // 'valid' | 'excluded' | null
            const fullDate = String(it.date || '');
            const dateDisp = fullDate.slice(0,10);
            const timeDisp = fullDate.slice(11,16); // HH:MM
            const tagsHtml = (it.ai_tags || []).map(t => `<span class="iq-tag">${escapeHtml(t)}</span>`).join('');
            const ikey = it.inquiry_key || '';

            // 操作ボタン: 現状状態に応じて表示を変える
            let actionHtml = '<div class="iq-actions">';
            if (valid) {
                actionHtml += `<button class="iq-btn-exclude" data-act="exclude" data-key="${escapeHtml(ikey)}">✕ 除外</button>`;
            } else {
                // 除外状態 → 「復活」ボタン (ユーザーにとって意味が明確)
                actionHtml += `<button class="iq-btn-validate" data-act="valid" data-key="${escapeHtml(ikey)}" title="有効に戻す">✓ 復活</button>`;
            }
            if (manual) {
                actionHtml += `<button class="iq-btn-reset" data-act="auto" data-key="${escapeHtml(ikey)}" title="AI判定に戻す">↺ AI判定</button>`;
            }
            actionHtml += '</div>';

            // 手動マーク
            let manualMark = '';
            if (manual === 'valid')    manualMark = '<span class="iq-manual-mark valid">手動有効</span>';
            if (manual === 'excluded') manualMark = '<span class="iq-manual-mark excluded">手動除外</span>';

            html += `<tr class="${valid ? '' : 'invalid'}" data-key="${escapeHtml(ikey)}">`
                + `<td data-label="No">${i+1}</td>`
                + `<td data-label="日時">${escapeHtml(dateDisp)}<br><span style="font-size:11px;color:#6b7280;">${escapeHtml(timeDisp)}</span></td>`
                + `<td data-label="種別"><span class="iq-cat-badge ${categoryClass(cat)}">${escapeHtml(cat)}</span>${manualMark}</td>`
                + `<td data-label="お名前">${escapeHtml(it.name || '')}</td>`
                + `<td data-label="地域">${escapeHtml(it.ai_region || '—')}</td>`
                + `<td data-label="内容"><details><summary>${escapeHtml(it.ai_summary || (it.message || '').slice(0,140))} ${tagsHtml}</summary>`
                + `<div class="iq-detail">`
                + `<strong>受信日時:</strong> ${escapeHtml(fullDate)}<br>`
                + `<strong>メール:</strong> ${escapeHtml(it.email || '')}<br>`
                + `<strong>送信元:</strong> ${escapeHtml(it.source || '')}<br><br>`
                + escapeHtml(it.message || '')
                + `</div></details></td>`
                + `<td data-label="操作">${actionHtml}</td>`
                + `</tr>`;
        });
        html += '</tbody></table>';
        content.innerHTML = html;

        // 操作ボタンへのイベント委譲
        content.querySelectorAll('.iq-actions button').forEach(btn => {
            btn.addEventListener('click', onOverrideClick);
        });
    }

    // GA4 モード用の行レンダラ
    // 各行 = cv-review REST が返す GA4 イベント1件
    //   { row_hash, event_name, date_hour_minute, page_path, source_medium,
    //     device_category, country, event_count, status, label }
    function renderItemsGa4(rows) {
        if (!rows || rows.length === 0) {
            content.innerHTML = '<div class="iq-empty">📭 この期間のゴール達成はまだありません。</div>';
            return;
        }
        let filtered;
        let emptyMsg;
        if (_filter === 'valid') {
            filtered = rows.filter(isEffectiveValid);
            emptyMsg = '📭 この期間に有効なゴール達成はまだありません。';
        } else if (_filter === 'excluded') {
            filtered = rows.filter(r => !isEffectiveValid(r));
            emptyMsg = '✅ 除外されたゴールはありません。';
        } else {
            filtered = rows.slice();
            emptyMsg = '📭 この期間のゴール達成はまだありません。';
        }
        if (filtered.length === 0) {
            content.innerHTML = `<div class="iq-empty">${emptyMsg}</div>`;
            return;
        }
        // 時系列順 (古い→新しい)
        filtered.sort((a, b) => String(a.date_hour_minute || '').localeCompare(String(b.date_hour_minute || '')));

        // date_hour_minute は "YYYYMMDDHHMM" 形式
        const formatDhm = (dhm) => {
            const s = String(dhm || '');
            if (s.length < 12) return s;
            return `${s.slice(0,4)}-${s.slice(4,6)}-${s.slice(6,8)} ${s.slice(8,10)}:${s.slice(10,12)}`;
        };

        let html = '<table class="iq-table"><thead><tr>'
            + '<th style="width:40px;">#</th>'
            + '<th style="width:170px;">日時</th>'
            + '<th style="width:160px;">ゴール</th>'
            + '<th>ページ</th>'
            + '<th style="width:160px;">流入元</th>'
            + '<th style="width:80px;">デバイス</th>'
            + '<th style="width:130px;">操作</th>'
            + '</tr></thead><tbody>';

        const deviceLabel = (d) => ({ 'mobile':'📱 スマホ', 'desktop':'💻 PC', 'tablet':'📟 タブレット' })[d] || (d || '—');

        filtered.forEach((r, i) => {
            const valid = isEffectiveValid(r);
            const dt = formatDhm(r.date_hour_minute);
            const dateDisp = dt.slice(0,10);
            const timeDisp = dt.slice(11,16);
            const eventLabel = String(r.label || r.event_name || '');
            const pagePath  = String(r.page_path || '/');
            const srcMed    = String(r.source_medium || '');
            const dev       = String(r.device_category || '');
            const evCount   = Number(r.event_count || 1);
            const rowHash   = String(r.row_hash || '');

            // 操作ボタン
            let actionHtml = '<div class="iq-actions">';
            if (valid) {
                actionHtml += `<button class="iq-btn-exclude" data-act="exclude" data-key="${escapeHtml(rowHash)}">✕ 除外</button>`;
            } else {
                actionHtml += `<button class="iq-btn-validate" data-act="valid" data-key="${escapeHtml(rowHash)}" title="ゴールに戻す">✓ 復活</button>`;
            }
            actionHtml += '</div>';

            // ステータス表示
            let statusMark = '';
            if (r.status === 1) statusMark = '<span class="iq-manual-mark valid">手動有効</span>';
            if (r.status === 2) statusMark = '<span class="iq-manual-mark excluded">除外済み</span>';

            html += `<tr class="${valid ? '' : 'invalid'}" data-key="${escapeHtml(rowHash)}">`
                + `<td data-label="No">${i+1}</td>`
                + `<td data-label="日時">${escapeHtml(dateDisp)}<br><span style="font-size:11px;color:#6b7280;">${escapeHtml(timeDisp)}</span></td>`
                + `<td data-label="ゴール"><span class="iq-cat-badge iq-cat-inquiry">${escapeHtml(eventLabel)}</span>${evCount > 1 ? ` <span class="iq-tag">×${evCount}</span>` : ''}${statusMark}</td>`
                + `<td data-label="ページ" style="word-break:break-all;font-size:12px;">${escapeHtml(pagePath)}</td>`
                + `<td data-label="流入元" style="font-size:12px;">${escapeHtml(srcMed)}</td>`
                + `<td data-label="デバイス">${escapeHtml(deviceLabel(dev))}</td>`
                + `<td data-label="操作">${actionHtml}</td>`
                + `</tr>`;
        });
        html += '</tbody></table>';
        content.innerHTML = html;

        content.querySelectorAll('.iq-actions button').forEach(btn => {
            btn.addEventListener('click', onOverrideClick);
        });
    }

    // ボタンクリック時の処理 — 即座にローカル状態更新 + サーバー POST
    async function onOverrideClick(ev) {
        if (MODE === 'ga4') { return onOverrideClickGa4(ev); }
        const btn = ev.currentTarget;
        const key = btn.getAttribute('data-key');
        const act = btn.getAttribute('data-act'); // 'exclude' | 'valid' | 'auto'
        if (!key) return;

        const ym = monthSel.value;
        if (!ym) return;

        // ローカル更新: items 配列内の該当 item を変更
        const cached = content.dataset.items;
        if (!cached) return;
        let items;
        try { items = JSON.parse(cached); } catch(_) { return; }
        const idx = items.findIndex(it => (it.inquiry_key || '') === key);
        if (idx < 0) return;

        const apiStatus = (act === 'exclude') ? 'excluded' : (act === 'valid' ? 'valid' : 'auto');

        // ボタンを一時的に無効化
        btn.disabled = true;
        const origText = btn.textContent;
        btn.textContent = '...';

        try {
            const res = await fetch(restBase + 'inquiries/override', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: userId,
                    year_month: ym,
                    inquiry_key: key,
                    manual_status: apiStatus,
                }),
            });
            const json = await res.json();
            if (!res.ok || !json.success) {
                alert(json.message || '更新に失敗しました');
                btn.disabled = false;
                btn.textContent = origText;
                return;
            }
        } catch (e) {
            alert('通信エラー: ' + (e && e.message ? e.message : e));
            btn.disabled = false;
            btn.textContent = origText;
            return;
        }

        // ローカル状態を更新
        if (apiStatus === 'auto') {
            items[idx].manual_status   = null;
            items[idx].effective_valid = !!items[idx].ai_valid;
        } else {
            items[idx].manual_status   = apiStatus;
            items[idx].effective_valid = (apiStatus === 'valid');
        }
        content.dataset.items = JSON.stringify(items);

        // 集計バーと一覧を再描画
        renderSummary(items);
        renderItems(items);
    }

    // GA4 モード用の override クリック
    async function onOverrideClickGa4(ev) {
        const btn = ev.currentTarget;
        const rowHash = btn.getAttribute('data-key');
        const act = btn.getAttribute('data-act'); // 'exclude' | 'valid'
        if (!rowHash) return;

        const ym = monthSel.value;
        if (!ym) return;

        const cached = content.dataset.items;
        if (!cached) return;
        let rows;
        try { rows = JSON.parse(cached); } catch(_) { return; }
        const idx = rows.findIndex(r => (r.row_hash || '') === rowHash);
        if (idx < 0) return;

        // status: 1=有効, 2=除外
        const newStatus = (act === 'exclude') ? 2 : 1;

        btn.disabled = true;
        const origText = btn.textContent;
        btn.textContent = '...';

        try {
            const r0 = rows[idx];
            const res = await fetch(restGcrev + 'cv-review/update', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    month: ym,
                    row_hash: rowHash,
                    status: newStatus,
                    // 新規 INSERT 時に必要となる行情報も送る
                    event_name:       r0.event_name || '',
                    date_hour_minute: r0.date_hour_minute || '',
                    page_path:        r0.page_path || '',
                    source_medium:    r0.source_medium || '',
                    device_category:  r0.device_category || '',
                    country:          r0.country || '',
                    event_count:      Number(r0.event_count || 1),
                }),
            });
            const json = await res.json();
            if (!res.ok || !json.success) {
                alert(json.message || '更新に失敗しました');
                btn.disabled = false;
                btn.textContent = origText;
                return;
            }
        } catch (e) {
            alert('通信エラー: ' + (e && e.message ? e.message : e));
            btn.disabled = false;
            btn.textContent = origText;
            return;
        }

        rows[idx].status = newStatus;
        content.dataset.items = JSON.stringify(rows);
        renderSummary(rows);
        renderItems(rows);
    }

    function renderSummary(items) {
        const validCount = items.filter(isEffectiveValid).length;
        const excludedCount = items.length - validCount;
        // カテゴリ集計はモードで異なる:
        //   inquiries: AI 分類 (ai_category)
        //   ga4      : ゴール設定で付けたラベル (label) or イベント名
        const byCategory = {};
        items.forEach(it => {
            let c;
            if (MODE === 'ga4') {
                c = String(it.label || it.event_name || 'その他');
            } else {
                c = it.ai_category || 'その他';
            }
            byCategory[c] = (byCategory[c] || 0) + 1;
        });
        // クリック可能なフィルタタブ (合計 / 有効 / 除外)
        // 色は CSS クラス (.iq-filter-valid / .iq-filter-excluded) で付けて、
        // アクティブ時に白文字へ綺麗に切り替わるようにする
        const cls = (key) => 'stat iq-filter iq-filter-' + key + (_filter === key ? ' active' : '');
        let summaryHtml = `<span class="${cls('all')}" data-filter="all" title="すべて表示">合計<strong>${items.length}</strong></span>`
            + `<span class="${cls('valid')}" data-filter="valid" title="有効のみ表示">有効<strong>${validCount}</strong></span>`
            + `<span class="${cls('excluded')}" data-filter="excluded" title="除外したものを一覧表示（ここから復活できます）">除外<strong>${excludedCount}</strong></span>`
            + `<span class="iq-divider"></span>`;
        Object.entries(byCategory).forEach(([cat, cnt]) => {
            summaryHtml += `<span class="stat"><span class="iq-cat-badge ${categoryClass(cat)}">${escapeHtml(cat)}</span><strong>${cnt}</strong></span>`;
        });
        summary.innerHTML = summaryHtml;
        summary.style.display = 'flex';

        // フィルタタブのクリックイベント
        summary.querySelectorAll('.iq-filter').forEach(el => {
            el.addEventListener('click', () => {
                const f = el.getAttribute('data-filter');
                if (!f || f === _filter) return;
                _filter = f;
                rerenderFromCache();
            });
        });

        // 月選択ドロップダウンの該当option表示も更新（合計X / 有効Y）
        const ym = monthSel.value;
        if (ym) {
            const opt = monthSel.querySelector(`option[value="${ym}"]`);
            if (opt) {
                const cur = opt.dataset.current === '1' ? '（当月）' : '';
                opt.textContent = `✓ ${ym}${cur}（合計${items.length} / 有効${validCount}）`;
            }
        }
    }

    function loadMonth(ym, force = false) {
        if (!ym) return;
        if (MODE === 'ga4') { return loadMonthGa4(ym); }
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
            content.dataset.items = JSON.stringify(items);
            renderSummary(items);
            renderItems(items);
        }).catch(err => {
            content.innerHTML = `<div class="iq-error">通信エラー: ${escapeHtml(err.message || err)}</div>`;
        });
    }

    // GA4 モード: cv-review エンドポイントから当月分の GA4 イベントを取得
    function loadMonthGa4(ym) {
        content.innerHTML = '<div class="iq-loading">📡 GA4 から取得中…</div>';
        summary.style.display = 'none';

        api('cv-review', { month: ym }).then(res => {
            if (!res || !res.success) {
                content.innerHTML = `<div class="iq-error">取得に失敗: ${escapeHtml((res && res.message) || '')}</div>`;
                return;
            }
            const rows = res.rows || [];
            content.dataset.items = JSON.stringify(rows);
            renderSummary(rows);
            renderItems(rows);
        }).catch(err => {
            content.innerHTML = `<div class="iq-error">通信エラー: ${escapeHtml(err.message || err)}</div>`;
        });
    }

    function rerenderFromCache() {
        const cached = content.dataset.items;
        if (!cached) return;
        try {
            const items = JSON.parse(cached);
            // タブの active 状態も更新するため renderSummary も呼ぶ
            renderSummary(items);
            renderItems(items);
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

    // GA4 モード用: 直近12ヶ月を静的に列挙して月選択肢を作る
    function renderMonthOptionsGa4() {
        const tz = new Date();
        const opts = [];
        for (let i = 0; i < 12; i++) {
            const d = new Date(tz.getFullYear(), tz.getMonth() - i, 1);
            const ym = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
            const isCurrent = (i === 0);
            const cur = isCurrent ? '（当月）' : '';
            opts.push(`<option value="${ym}" data-current="${isCurrent?1:0}">${ym}${cur}</option>`);
        }
        monthSel.innerHTML = opts.join('');
    }

    // 月リスト取得 → 未キャッシュ月を一括取得 → 月リスト再読込 → 最新月を表示
    async function init() {
        if (MODE === 'ga4') { return initGa4(); }
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

    // GA4 モード用 init: 静的月リスト + 最新月を表示するだけ
    async function initGa4() {
        renderMonthOptionsGa4();
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
            // 共通の renderSummary / renderItems を使うことでフィルタタブも同期
            renderSummary(items);
            renderItems(items);
        } catch (err) {
            bootstrap.style.display = 'none';
            content.innerHTML = `<div class="iq-error">通信エラー: ${escapeHtml(err.message || err)}</div>`;
        }
    }

    monthSel.addEventListener('change', () => loadMonth(monthSel.value));
    if (refreshBtn) {
        refreshBtn.addEventListener('click', refreshCurrentMonth);
    }

    init();
})();
</script>

<?php get_footer();
