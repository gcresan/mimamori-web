<?php
/*
Template Name: 口コミ管理
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

set_query_var( 'gcrev_page_title', '口コミ管理' );
set_query_var( 'gcrev_page_subtitle', 'Googleの口コミを一覧で管理し、AIで返信文を生成・投稿できます。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '口コミ管理', 'MEO' ) );

// GBP接続状態チェック
$gbp_location_id = get_user_meta( $user_id, '_gcrev_gbp_location_id', true );
$gbp_connected   = ! empty( $gbp_location_id ) && strpos( $gbp_location_id, 'pending_' ) !== 0;
$gbp_location_name = get_user_meta( $user_id, '_gcrev_gbp_location_name', true );

// AI返信設定
$ai_settings_raw = get_user_meta( $user_id, '_gcrev_review_ai_settings', true );
$ai_settings     = is_string( $ai_settings_raw ) ? json_decode( $ai_settings_raw, true ) : [];
if ( ! is_array( $ai_settings ) ) {
    $ai_settings = [];
}
$ai_tone     = $ai_settings['tone'] ?? 'polite';
$ai_length   = $ai_settings['length'] ?? 'standard';
$ai_policy   = $ai_settings['low_rating_policy'] ?? 'apology';
$ai_biz_name = $ai_settings['business_name'] ?? $gbp_location_name;
$ai_industry = $ai_settings['industry'] ?? '';

get_header();
?>

<style>
/* 口コミ管理ページ固有スタイル */
.rm-not-connected {
    text-align: center;
    padding: 60px 20px;
    background: var(--mw-bg-secondary, #F5F8F8);
    border-radius: var(--mw-radius-md, 16px);
    margin-bottom: 24px;
}
.rm-not-connected h2 { font-size: 20px; color: var(--mw-text-heading, #1A2F33); margin-bottom: 8px; }
.rm-not-connected p { font-size: 14px; color: #64748b; margin-bottom: 16px; }
.rm-not-connected a {
    display: inline-block; padding: 10px 24px;
    background: var(--mw-primary-blue, #568184); color: #fff;
    border-radius: 8px; text-decoration: none; font-weight: 600;
}

/* AI設定パネル */
.rm-settings-toggle {
    display: flex; align-items: center; gap: 8px;
    cursor: pointer; padding: 12px 16px;
    background: var(--mw-bg-secondary, #F5F8F8);
    border-radius: var(--mw-radius-md, 16px);
    margin-bottom: 16px; font-weight: 600; color: var(--mw-text-heading);
    border: none; width: 100%; text-align: left; font-size: 14px;
}
.rm-settings-toggle:hover { background: #e8f0f0; }
.rm-settings-toggle .toggle-arrow { transition: transform 0.2s; }
.rm-settings-toggle.open .toggle-arrow { transform: rotate(90deg); }
.rm-settings-panel {
    display: none; padding: 20px;
    background: var(--mw-bg-secondary, #F5F8F8);
    border-radius: var(--mw-radius-md, 16px);
    margin-bottom: 24px; margin-top: -8px;
}
.rm-settings-panel.open { display: block; }
.rm-settings-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 16px;
}
.rm-settings-grid label {
    display: block; font-size: 13px; font-weight: 600;
    color: var(--mw-text-primary); margin-bottom: 4px;
}
.rm-settings-grid select,
.rm-settings-grid input {
    width: 100%; padding: 8px 12px; border: 1px solid #d1d5db;
    border-radius: 8px; font-size: 14px; background: #fff;
}
.rm-settings-save {
    margin-top: 12px; padding: 8px 20px;
    background: var(--mw-primary-blue, #568184); color: #fff;
    border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px;
}
.rm-settings-save:hover { opacity: 0.9; }

/* サマリーカード */
.rm-summary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
.rm-summary-card {
    flex: 1; min-width: 140px; padding: 16px 20px;
    background: #fff; border-radius: var(--mw-radius-md, 16px);
    box-shadow: var(--mw-shadow-card, 0 2px 12px rgba(0,0,0,0.04));
}
.rm-summary-card .label { font-size: 12px; color: #64748b; margin-bottom: 4px; }
.rm-summary-card .value { font-size: 28px; font-weight: 700; color: var(--mw-text-heading); }
.rm-summary-card.unreplied .value { color: #dc2626; }
.rm-summary-card.low .value { color: #ea580c; }

/* フィルタバー */
.rm-filters {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    margin-bottom: 20px; padding: 12px 16px;
    background: #fff; border-radius: var(--mw-radius-md, 16px);
    box-shadow: var(--mw-shadow-card);
}
.rm-filter-btn {
    padding: 6px 14px; border: 1px solid #d1d5db; border-radius: 20px;
    background: #fff; cursor: pointer; font-size: 13px; color: #374151;
    transition: all 0.15s;
}
.rm-filter-btn:hover { border-color: var(--mw-primary-blue); color: var(--mw-primary-blue); }
.rm-filter-btn.active {
    background: var(--mw-primary-blue, #568184); color: #fff;
    border-color: var(--mw-primary-blue);
}
.rm-sort-select {
    margin-left: auto; padding: 6px 12px; border: 1px solid #d1d5db;
    border-radius: 8px; font-size: 13px; background: #fff;
}
.rm-refresh-btn {
    padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 8px;
    background: #fff; cursor: pointer; font-size: 13px; color: #374151;
}
.rm-refresh-btn:hover { border-color: var(--mw-primary-blue); color: var(--mw-primary-blue); }

/* 口コミカード */
.rm-review-list { display: flex; flex-direction: column; gap: 16px; }
.rm-review-card {
    background: #fff; border-radius: var(--mw-radius-md, 16px);
    box-shadow: var(--mw-shadow-card); padding: 20px 24px;
    border-left: 4px solid #10b981;
}
.rm-review-card.rating-low { border-left-color: #dc2626; background: #fef2f2; }
.rm-review-card.rating-mid { border-left-color: #f59e0b; background: #fffbeb; }

.rm-review-header {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px;
}
.rm-stars { color: #f59e0b; font-size: 16px; letter-spacing: 1px; }
.rm-reviewer { font-weight: 600; color: var(--mw-text-heading); font-size: 14px; }
.rm-date { font-size: 12px; color: #94a3b8; }
.rm-status-badge {
    padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;
}
.rm-status-badge.unreplied { background: #fef2f2; color: #dc2626; }
.rm-status-badge.draft { background: #fffbeb; color: #d97706; }
.rm-status-badge.replied { background: #ecfdf5; color: #059669; }

.rm-review-body {
    font-size: 14px; color: var(--mw-text-primary); line-height: 1.7;
    margin-bottom: 16px; white-space: pre-wrap;
}

.rm-existing-reply {
    background: #f0fdf4; border-radius: 8px; padding: 12px 16px;
    margin-bottom: 16px; font-size: 13px; color: #166534;
    border: 1px solid #bbf7d0;
}
.rm-existing-reply .reply-label {
    font-weight: 600; font-size: 12px; color: #059669; margin-bottom: 4px;
}
.rm-existing-reply .reply-text { white-space: pre-wrap; line-height: 1.6; }

/* 返信操作エリア */
.rm-reply-area { border-top: 1px solid #e5e7eb; padding-top: 16px; }
.rm-reply-actions { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.rm-btn {
    padding: 7px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: none; transition: all 0.25s ease;
    display: inline-flex; align-items: center; gap: 6px;
}
.rm-btn:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
.rm-btn:hover:not(:disabled) { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.rm-btn:active:not(:disabled) { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.rm-btn:focus-visible { outline: 2px solid var(--mw-primary-blue, #568184); outline-offset: 2px; }
.rm-btn-ai { background: #7c3aed; color: #fff; }
.rm-btn-regen { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
.rm-btn-post { background: var(--mw-primary-blue, #568184); color: #fff; }
.rm-btn-draft { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
.rm-btn .spinner-sm {
    display: inline-block; width: 14px; height: 14px;
    border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff;
    border-radius: 50%; animation: rm-spin 0.6s linear infinite;
}
.rm-btn-regen .spinner-sm, .rm-btn-draft .spinner-sm {
    border-color: rgba(0,0,0,0.2); border-top-color: #374151;
}
@keyframes rm-spin { to { transform: rotate(360deg); } }

.rm-textarea-wrap { position: relative; margin-bottom: 8px; }
.rm-textarea {
    width: 100%; min-height: 100px; padding: 12px; border: 1px solid #d1d5db;
    border-radius: 8px; font-size: 14px; line-height: 1.6; resize: vertical;
    font-family: inherit;
}
.rm-textarea:focus { outline: none; border-color: var(--mw-primary-blue); box-shadow: 0 0 0 2px rgba(86,129,132,0.15); }
.rm-char-count { font-size: 12px; color: #94a3b8; text-align: right; }

.rm-reply-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }

/* トースト */
.rm-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 10000;
    padding: 12px 20px; border-radius: 8px; font-size: 14px; font-weight: 600;
    color: #fff; opacity: 0; transform: translateY(16px);
    transition: all 0.3s;
}
.rm-toast.show { opacity: 1; transform: translateY(0); }
.rm-toast.success { background: #059669; }
.rm-toast.error { background: #dc2626; }

/* ローディング */
.rm-loading {
    text-align: center; padding: 40px; color: #64748b; font-size: 14px;
}
.rm-loading .spinner {
    display: inline-block; width: 32px; height: 32px; margin-bottom: 12px;
    border: 3px solid #e5e7eb; border-top-color: var(--mw-primary-blue);
    border-radius: 50%; animation: rm-spin 0.8s linear infinite;
}

/* もっと読み込む */
.rm-load-more {
    text-align: center; margin-top: 20px;
}
.rm-load-more-btn {
    padding: 10px 32px; background: #fff; border: 1px solid #d1d5db;
    border-radius: 8px; cursor: pointer; font-size: 14px; color: #374151;
}
.rm-load-more-btn:hover { border-color: var(--mw-primary-blue); color: var(--mw-primary-blue); }

/* 空状態 */
.rm-empty {
    text-align: center; padding: 60px 20px; color: #94a3b8;
}
.rm-empty-icon { font-size: 48px; margin-bottom: 12px; }

/* レスポンシブ */
@media (max-width: 768px) {
    .rm-summary { flex-direction: column; }
    .rm-summary-card { min-width: auto; }
    .rm-review-card { padding: 16px; }
    .rm-filters { flex-direction: column; align-items: stretch; }
    .rm-sort-select { margin-left: 0; }
    .rm-settings-grid { grid-template-columns: 1fr; }
}
</style>

<div class="content-area">

<?php if ( ! $gbp_connected ) : ?>
    <div class="rm-not-connected">
        <p style="font-size:48px; margin-bottom:16px;">📍</p>
        <h2>Googleビジネスプロフィールを接続してください</h2>
        <p>口コミを管理するには、MEOダッシュボードからGBPアカウントを接続し、<br>ロケーションを選択する必要があります。</p>
        <a href="<?php echo esc_url( home_url( '/meo-dashboard/' ) ); ?>">MEOダッシュボードへ</a>
    </div>
<?php else : ?>

<!-- AI返信設定 -->
<button class="rm-settings-toggle" id="settingsToggle" type="button">
    <span class="toggle-arrow">▶</span> AI返信設定
    <span style="font-weight:400; font-size:12px; color:#64748b; margin-left:8px;">（トーン・長さ・低評価方針）</span>
</button>
<div class="rm-settings-panel" id="settingsPanel">
    <div class="rm-settings-grid">
        <div>
            <label for="aiBizName">事業者名</label>
            <input type="text" id="aiBizName" value="<?php echo esc_attr( $ai_biz_name ); ?>" placeholder="自動検出されます">
        </div>
        <div>
            <label for="aiIndustry">業種</label>
            <input type="text" id="aiIndustry" value="<?php echo esc_attr( $ai_industry ); ?>" placeholder="例: 飲食店、美容室">
        </div>
        <div>
            <label for="aiTone">返信文のトーン</label>
            <select id="aiTone">
                <option value="polite" <?php selected( $ai_tone, 'polite' ); ?>>丁寧</option>
                <option value="friendly" <?php selected( $ai_tone, 'friendly' ); ?>>やや親しみやすい</option>
                <option value="formal" <?php selected( $ai_tone, 'formal' ); ?>>フォーマル</option>
            </select>
        </div>
        <div>
            <label for="aiLength">返信文の長さ</label>
            <select id="aiLength">
                <option value="short" <?php selected( $ai_length, 'short' ); ?>>短め</option>
                <option value="standard" <?php selected( $ai_length, 'standard' ); ?>>標準</option>
                <option value="long" <?php selected( $ai_length, 'long' ); ?>>やや丁寧長め</option>
            </select>
        </div>
        <div>
            <label for="aiPolicy">低評価口コミへの方針</label>
            <select id="aiPolicy">
                <option value="apology" <?php selected( $ai_policy, 'apology' ); ?>>お詫び重視</option>
                <option value="improvement" <?php selected( $ai_policy, 'improvement' ); ?>>改善姿勢重視</option>
                <option value="individual" <?php selected( $ai_policy, 'individual' ); ?>>個別対応案内重視</option>
            </select>
        </div>
    </div>
    <button class="rm-settings-save" id="settingsSave" type="button">設定を保存</button>
</div>

<!-- サマリーカード -->
<div class="rm-summary">
    <div class="rm-summary-card">
        <div class="label">総口コミ数</div>
        <div class="value" id="sumTotal">-</div>
    </div>
    <div class="rm-summary-card unreplied">
        <div class="label">未返信</div>
        <div class="value" id="sumUnreplied">-</div>
    </div>
    <div class="rm-summary-card">
        <div class="label">返信済み</div>
        <div class="value" id="sumReplied">-</div>
    </div>
    <div class="rm-summary-card low">
        <div class="label">低評価（★1-2）</div>
        <div class="value" id="sumLow">-</div>
    </div>
</div>

<!-- フィルタ・並び替え -->
<div class="rm-filters">
    <button class="rm-filter-btn active" data-filter="all">すべて</button>
    <button class="rm-filter-btn" data-filter="unreplied">未返信のみ</button>
    <button class="rm-filter-btn" data-filter="replied">返信済み</button>
    <button class="rm-filter-btn" data-filter="low">低評価</button>
    <button class="rm-filter-btn" data-filter="mid">中評価</button>
    <button class="rm-filter-btn" data-filter="high">高評価</button>
    <select class="rm-sort-select" id="sortSelect">
        <option value="newest">新しい順</option>
        <option value="oldest">古い順</option>
    </select>
    <button class="rm-refresh-btn" id="refreshBtn" type="button" title="最新情報を取得">🔄 更新</button>
</div>

<!-- 口コミ一覧 -->
<div id="reviewList" class="rm-review-list">
    <div class="rm-loading"><div class="spinner"></div><br>口コミを読み込み中...</div>
</div>

<!-- もっと読み込む -->
<div class="rm-load-more" id="loadMoreWrap" style="display:none;">
    <button class="rm-load-more-btn" id="loadMoreBtn" type="button">もっと読み込む</button>
</div>

<?php endif; ?>

</div><!-- .content-area -->

<!-- トースト -->
<div class="rm-toast" id="rmToast"></div>

<?php if ( $gbp_connected ) : ?>
<script>
(function(){
    'use strict';
    var restBase = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gcrev/v1/' ) ) ); ?>;
    var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

    var currentFilter = 'all';
    var currentSort   = 'newest';
    var nextPageToken = null;
    var allReviews    = [];

    // --- ユーティリティ ---
    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function showToast(msg, type) {
        var t = document.getElementById('rmToast');
        t.textContent = msg;
        t.className = 'rm-toast ' + (type || 'success') + ' show';
        setTimeout(function(){ t.classList.remove('show'); }, 3500);
    }
    function formatDate(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        return d.getFullYear() + '/' + String(d.getMonth()+1).padStart(2,'0') + '/' + String(d.getDate()).padStart(2,'0');
    }
    function starsHtml(n) {
        return '<span class="rm-stars">' + '★'.repeat(n) + '☆'.repeat(5-n) + '</span>';
    }

    // --- API呼び出し ---
    function fetchJson(url, opts) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        opts.headers = Object.assign({ 'X-WP-Nonce': nonce }, opts.headers || {});
        return fetch(url, opts).then(function(r) { return r.json(); });
    }

    // --- レビュー読み込み ---
    function loadReviews(append, pageToken) {
        if (!append) {
            document.getElementById('reviewList').innerHTML = '<div class="rm-loading"><div class="spinner"></div><br>口コミを読み込み中...</div>';
        }
        var url = restBase + 'meo/reviews?filter=' + encodeURIComponent(currentFilter)
                + '&sort=' + encodeURIComponent(currentSort)
                + (pageToken ? '&page_token=' + encodeURIComponent(pageToken) : '')
                + (append ? '' : '');
        // 初回は no_cache なしでキャッシュ利用
        fetchJson(url).then(function(data) {
            if (!data.success) {
                var msgHtml = escHtml(data.message || '').replace(/\n/g, '<br>');
                document.getElementById('reviewList').innerHTML =
                    '<div class="rm-empty"><div class="rm-empty-icon">⚠️</div><p style="line-height:1.8;max-width:600px;margin:0 auto;">' + msgHtml + '</p></div>';
                return;
            }
            var reviews = data.reviews || [];
            nextPageToken = data.nextPageToken || null;

            if (!append) {
                allReviews = reviews;
            } else {
                allReviews = allReviews.concat(reviews);
            }

            // サマリー更新
            if (data.summary) {
                document.getElementById('sumTotal').textContent = data.summary.total;
                document.getElementById('sumUnreplied').textContent = data.summary.unreplied;
                document.getElementById('sumReplied').textContent = data.summary.replied;
                document.getElementById('sumLow').textContent = data.summary.low_rating;
            }

            renderReviews(append ? allReviews : reviews, append);

            // ページネーション
            document.getElementById('loadMoreWrap').style.display = nextPageToken ? '' : 'none';
        }).catch(function(err) {
            document.getElementById('reviewList').innerHTML =
                '<div class="rm-empty"><div class="rm-empty-icon">⚠️</div><p>口コミの取得に失敗しました。</p></div>';
        });
    }

    // --- レビューカード描画 ---
    function renderReviews(reviews, append) {
        var container = document.getElementById('reviewList');
        if (!append) container.innerHTML = '';

        if (reviews.length === 0 && !append) {
            container.innerHTML = '<div class="rm-empty"><div class="rm-empty-icon">💬</div><p>口コミがありません。</p></div>';
            return;
        }

        reviews.forEach(function(r, idx) {
            if (append && idx < allReviews.length - reviews.length) return;
            var rating    = r._rating_num || 0;
            var hasReply  = r._has_reply || false;
            var draft     = r._draft;
            var ratingCls = rating <= 2 ? 'rating-low' : (rating === 3 ? 'rating-mid' : '');
            var statusCls = draft ? 'draft' : (hasReply ? 'replied' : 'unreplied');
            var statusLabel = draft ? '下書きあり' : (hasReply ? '返信済み' : '未返信');
            var reviewName = r.name || '';
            var cardId = 'rc-' + idx;

            var html = '<div class="rm-review-card ' + ratingCls + '" id="' + cardId + '">'
                + '<div class="rm-review-header">'
                + starsHtml(rating)
                + '<span class="rm-reviewer">' + escHtml(r.reviewer ? r.reviewer.displayName : '匿名') + '</span>'
                + '<span class="rm-date">' + formatDate(r.createTime) + '</span>'
                + '<span class="rm-status-badge ' + statusCls + '" data-status="' + statusCls + '">' + statusLabel + '</span>'
                + '</div>';

            // 口コミ本文
            html += '<div class="rm-review-body">' + escHtml(r.comment || '（テキストなし）') + '</div>';

            // 既存の返信
            if (hasReply) {
                html += '<div class="rm-existing-reply">'
                    + '<div class="reply-label">オーナーからの返信</div>'
                    + '<div class="reply-text">' + escHtml(r.reviewReply.comment || '') + '</div>'
                    + '</div>';
            }

            // 返信操作エリア
            var draftText = draft ? draft.draft_text : (hasReply ? r.reviewReply.comment : '');
            html += '<div class="rm-reply-area">'
                + '<div class="rm-reply-actions">'
                + '<button class="rm-btn rm-btn-ai" data-action="ai-generate" data-review=\'' + escHtml(JSON.stringify({
                    name: reviewName,
                    reviewer: r.reviewer ? r.reviewer.displayName : '投稿者',
                    rating: rating,
                    comment: r.comment || ''
                })) + '\'>✨ AIで返信文生成</button>'
                + '<button class="rm-btn rm-btn-regen" data-action="ai-regen" data-review=\'' + escHtml(JSON.stringify({
                    name: reviewName,
                    reviewer: r.reviewer ? r.reviewer.displayName : '投稿者',
                    rating: rating,
                    comment: r.comment || ''
                })) + '\' style="display:' + (draftText ? 'inline-flex' : 'none') + '">🔄 再生成</button>'
                + '</div>'
                + '<div class="rm-textarea-wrap">'
                + '<textarea class="rm-textarea" data-review-name="' + escHtml(reviewName) + '" placeholder="返信文を入力またはAIで生成してください...">' + escHtml(draftText || '') + '</textarea>'
                + '</div>'
                + '<div class="rm-reply-footer">'
                + '<span class="rm-char-count"><span class="count">' + (draftText ? draftText.length : 0) + '</span>字</span>'
                + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
                + '<button class="rm-btn rm-btn-draft" data-action="save-draft" data-review-name="' + escHtml(reviewName) + '">下書き保存</button>'
                + '<button class="rm-btn rm-btn-post" data-action="post-reply" data-review-name="' + escHtml(reviewName) + '">'
                + (hasReply ? '返信を更新' : 'Googleに返信を投稿') + '</button>'
                + '</div></div></div>';

            html += '</div>';
            container.insertAdjacentHTML('beforeend', html);
        });
    }

    // --- イベント委譲 ---
    document.getElementById('reviewList').addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.dataset.action;

        if (action === 'ai-generate' || action === 'ai-regen') {
            var reviewData = JSON.parse(btn.dataset.review);
            generateAiReply(btn, reviewData);
        } else if (action === 'post-reply') {
            var reviewName = btn.dataset.reviewName;
            postReply(btn, reviewName);
        } else if (action === 'save-draft') {
            var reviewName2 = btn.dataset.reviewName;
            saveDraft(btn, reviewName2);
        }
    });

    // テキストエリア入力時の文字数カウント
    document.getElementById('reviewList').addEventListener('input', function(e) {
        if (e.target.classList.contains('rm-textarea')) {
            var card = e.target.closest('.rm-review-card');
            var countEl = card.querySelector('.rm-char-count .count');
            if (countEl) countEl.textContent = e.target.value.length;
            // 再生成ボタン表示
            var regenBtn = card.querySelector('[data-action="ai-regen"]');
            if (regenBtn) regenBtn.style.display = e.target.value ? 'inline-flex' : 'none';
        }
    });

    // --- AI返信文生成 ---
    function generateAiReply(btn, reviewData) {
        btn.disabled = true;
        var origText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-sm"></span> 生成中...';

        fetchJson(restBase + 'meo/reviews/ai-generate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                review_name: reviewData.name,
                reviewer_name: reviewData.reviewer,
                star_rating: reviewData.rating,
                comment_text: reviewData.comment
            })
        }).then(function(data) {
            btn.disabled = false;
            btn.innerHTML = origText;
            if (data.success) {
                var card = btn.closest('.rm-review-card');
                var textarea = card.querySelector('.rm-textarea');
                textarea.value = data.reply;
                card.querySelector('.rm-char-count .count').textContent = data.reply.length;
                var regenBtn = card.querySelector('[data-action="ai-regen"]');
                if (regenBtn) regenBtn.style.display = 'inline-flex';
                // ステータス更新
                var badge = card.querySelector('.rm-status-badge');
                if (badge && badge.dataset.status === 'unreplied') {
                    badge.className = 'rm-status-badge draft';
                    badge.textContent = '下書きあり';
                    badge.dataset.status = 'draft';
                }
                showToast('返信文案を生成しました', 'success');
            } else {
                showToast(data.message || 'AI生成に失敗しました', 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.innerHTML = origText;
            showToast('通信エラーが発生しました', 'error');
        });
    }

    // --- Google返信投稿 ---
    function postReply(btn, reviewName) {
        var card = btn.closest('.rm-review-card');
        var textarea = card.querySelector('.rm-textarea');
        var comment = textarea.value.trim();
        if (!comment) {
            showToast('返信内容を入力してください', 'error');
            return;
        }
        if (!confirm('この内容でGoogleに返信を投稿します。よろしいですか？\n\n' + comment.substring(0, 200))) {
            return;
        }

        btn.disabled = true;
        var origText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-sm"></span> 投稿中...';

        fetchJson(restBase + 'meo/reviews/reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ review_name: reviewName, comment: comment })
        }).then(function(data) {
            btn.disabled = false;
            btn.innerHTML = origText;
            if (data.success) {
                showToast('返信を投稿しました', 'success');
                // UI更新: ステータス変更
                var badge = card.querySelector('.rm-status-badge');
                if (badge) {
                    badge.className = 'rm-status-badge replied';
                    badge.textContent = '返信済み';
                    badge.dataset.status = 'replied';
                }
                btn.textContent = '返信を更新';
                // 既存返信表示を更新/追加
                var existingReply = card.querySelector('.rm-existing-reply');
                if (existingReply) {
                    existingReply.querySelector('.reply-text').textContent = comment;
                } else {
                    var replyHtml = '<div class="rm-existing-reply">'
                        + '<div class="reply-label">オーナーからの返信</div>'
                        + '<div class="reply-text">' + escHtml(comment) + '</div></div>';
                    var replyArea = card.querySelector('.rm-reply-area');
                    replyArea.insertAdjacentHTML('beforebegin', replyHtml);
                }
            } else {
                showToast(data.message || '返信の投稿に失敗しました', 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.innerHTML = origText;
            showToast('通信エラーが発生しました', 'error');
        });
    }

    // --- 下書き保存 ---
    function saveDraft(btn, reviewName) {
        var card = btn.closest('.rm-review-card');
        var textarea = card.querySelector('.rm-textarea');
        var draftText = textarea.value.trim();

        btn.disabled = true;
        var origText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-sm"></span> 保存中...';

        fetchJson(restBase + 'meo/reviews/draft', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ review_name: reviewName, draft_text: draftText })
        }).then(function(data) {
            btn.disabled = false;
            btn.innerHTML = origText;
            if (data.success) {
                showToast(draftText ? '下書きを保存しました' : '下書きを削除しました', 'success');
                var badge = card.querySelector('.rm-status-badge');
                if (badge && draftText && badge.dataset.status === 'unreplied') {
                    badge.className = 'rm-status-badge draft';
                    badge.textContent = '下書きあり';
                    badge.dataset.status = 'draft';
                }
            } else {
                showToast('下書きの保存に失敗しました', 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.innerHTML = origText;
            showToast('通信エラーが発生しました', 'error');
        });
    }

    // --- フィルタボタン ---
    document.querySelectorAll('.rm-filter-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.rm-filter-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentFilter = btn.dataset.filter;
            loadReviews(false);
        });
    });

    // --- ソート変更 ---
    document.getElementById('sortSelect').addEventListener('change', function() {
        currentSort = this.value;
        loadReviews(false);
    });

    // --- 更新ボタン ---
    document.getElementById('refreshBtn').addEventListener('click', function() {
        // キャッシュ無効化して再取得
        fetchJson(restBase + 'meo/reviews?filter=' + encodeURIComponent(currentFilter)
            + '&sort=' + encodeURIComponent(currentSort) + '&no_cache=1')
        .then(function(data) {
            if (!data.success) {
                showToast(data.message || '更新に失敗しました', 'error');
                return;
            }
            allReviews = data.reviews || [];
            nextPageToken = data.nextPageToken || null;
            if (data.summary) {
                document.getElementById('sumTotal').textContent = data.summary.total;
                document.getElementById('sumUnreplied').textContent = data.summary.unreplied;
                document.getElementById('sumReplied').textContent = data.summary.replied;
                document.getElementById('sumLow').textContent = data.summary.low_rating;
            }
            renderReviews(allReviews, false);
            document.getElementById('loadMoreWrap').style.display = nextPageToken ? '' : 'none';
            showToast('口コミを更新しました', 'success');
        });
    });

    // --- もっと読み込む ---
    document.getElementById('loadMoreBtn').addEventListener('click', function() {
        if (nextPageToken) loadReviews(true, nextPageToken);
    });

    // --- AI設定トグル ---
    document.getElementById('settingsToggle').addEventListener('click', function() {
        this.classList.toggle('open');
        document.getElementById('settingsPanel').classList.toggle('open');
    });

    // --- AI設定保存 ---
    document.getElementById('settingsSave').addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        btn.textContent = '保存中...';

        fetchJson(restBase + 'meo/reviews/ai-settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tone: document.getElementById('aiTone').value,
                length: document.getElementById('aiLength').value,
                low_rating_policy: document.getElementById('aiPolicy').value,
                business_name: document.getElementById('aiBizName').value,
                industry: document.getElementById('aiIndustry').value
            })
        }).then(function(data) {
            btn.disabled = false;
            btn.textContent = '設定を保存';
            showToast(data.success ? '設定を保存しました' : '保存に失敗しました', data.success ? 'success' : 'error');
        }).catch(function() {
            btn.disabled = false;
            btn.textContent = '設定を保存';
            showToast('通信エラーが発生しました', 'error');
        });
    });

    // --- 初期読み込み ---
    loadReviews(false);
})();
</script>
<?php endif; ?>

<?php get_footer(); ?>
