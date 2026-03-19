<?php
/*
Template Name: 投稿管理
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$user_id       = get_current_user_id();
$gbp_connected = (bool) get_user_meta( $user_id, '_gcrev_gbp_location_id', true );
$location_name = get_user_meta( $user_id, '_gcrev_gbp_location_name', true ) ?: '';

set_query_var( 'gcrev_page_title', '投稿管理' );
set_query_var( 'gcrev_page_subtitle', 'Googleビジネスプロフィールの投稿を管理します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '投稿管理', 'MEO' ) );

get_header();
wp_enqueue_media();
?>

<style>
/* ===== GBP Posts — gp- prefix ===== */
.gp-not-connected { text-align:center; padding:60px 20px; }
.gp-not-connected p { font-size:14px; color:#64748b; margin:8px 0; }

.gp-summary { display:flex; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
.gp-summary-card { flex:1; min-width:140px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 20px; }
.gp-summary-card .gp-sc-label { font-size:12px; color:#64748b; margin-bottom:4px; }
.gp-summary-card .gp-sc-value { font-size:28px; font-weight:700; color:#1e293b; }
.gp-summary-card.gp-sc-failed .gp-sc-value { color:#dc2626; }
.gp-summary-card.gp-sc-scheduled .gp-sc-value { color:#2563eb; }

.gp-action-bar { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
.gp-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; font-size:13px; cursor:pointer; transition:all .15s; font-family:inherit; }
.gp-btn:hover { background:#f1f5f9; }
.gp-btn-primary { background:#3b6b5e; color:#fff; border-color:#3b6b5e; }
.gp-btn-primary:hover { background:#2d5349; }
.gp-btn-danger { color:#dc2626; border-color:#fecaca; }
.gp-btn-danger:hover { background:#fef2f2; }
.gp-btn:disabled { opacity:.5; cursor:not-allowed; }

.gp-filters { display:flex; align-items:center; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
.gp-filter-btn { padding:6px 14px; border-radius:20px; border:1px solid #e2e8f0; background:#fff; font-size:12px; cursor:pointer; transition:all .15s; font-family:inherit; }
.gp-filter-btn:hover { background:#f1f5f9; }
.gp-filter-btn.active { background:#3b6b5e; color:#fff; border-color:#3b6b5e; }
.gp-sort-select { margin-left:auto; padding:6px 10px; border-radius:8px; border:1px solid #e2e8f0; font-size:12px; font-family:inherit; }
.gp-refresh-btn { padding:6px 12px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; cursor:pointer; font-size:12px; font-family:inherit; }
.gp-refresh-btn:hover { background:#f1f5f9; }

.gp-post-list { display:flex; flex-direction:column; gap:12px; }
.gp-post-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; position:relative; }
.gp-post-card.gp-status-failed { border-left:4px solid #dc2626; }
.gp-post-card.gp-status-scheduled { border-left:4px solid #2563eb; }
.gp-post-card.gp-status-posted { border-left:4px solid #16a34a; }
.gp-post-card.gp-status-draft { border-left:4px solid #94a3b8; }
.gp-post-card.gp-status-cancelled { border-left:4px solid #9ca3af; opacity:.7; }

.gp-card-header { display:flex; align-items:center; gap:10px; margin-bottom:8px; flex-wrap:wrap; }
.gp-status-badge { display:inline-block; padding:2px 10px; border-radius:12px; font-size:11px; font-weight:600; }
.gp-badge-draft { background:#f1f5f9; color:#64748b; }
.gp-badge-scheduled { background:#dbeafe; color:#1d4ed8; }
.gp-badge-posted { background:#dcfce7; color:#15803d; }
.gp-badge-failed { background:#fee2e2; color:#dc2626; }
.gp-badge-cancelled { background:#f3f4f6; color:#6b7280; }
.gp-card-title { font-size:14px; font-weight:600; color:#1e293b; }
.gp-card-date { font-size:11px; color:#94a3b8; margin-left:auto; white-space:nowrap; }

.gp-card-body { display:flex; gap:16px; }
.gp-card-thumb { width:80px; height:80px; border-radius:8px; object-fit:cover; flex-shrink:0; border:1px solid #e2e8f0; }
.gp-card-content { flex:1; min-width:0; }
.gp-card-summary { font-size:13px; color:#334155; line-height:1.6; margin-bottom:6px; word-break:break-word; white-space:pre-wrap; }
.gp-card-meta { font-size:11px; color:#94a3b8; }
.gp-card-meta span { margin-right:12px; }
.gp-card-error { font-size:12px; color:#dc2626; margin-top:6px; background:#fef2f2; padding:6px 10px; border-radius:6px; }

.gp-card-actions { display:flex; gap:6px; margin-top:12px; flex-wrap:wrap; }
.gp-card-actions .gp-btn { padding:4px 10px; font-size:11px; }

.gp-pagination { display:flex; justify-content:center; gap:4px; margin-top:20px; }
.gp-page-btn { padding:6px 12px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; cursor:pointer; font-size:12px; font-family:inherit; }
.gp-page-btn:hover { background:#f1f5f9; }
.gp-page-btn.active { background:#3b6b5e; color:#fff; border-color:#3b6b5e; }

/* Modal */
.gp-modal-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,.4); z-index:9999; display:flex; align-items:center; justify-content:center; }
.gp-modal { background:#fff; border-radius:16px; width:90%; max-width:640px; max-height:90vh; display:flex; flex-direction:column; }
.gp-modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px 24px; border-bottom:1px solid #e2e8f0; }
.gp-modal-header h3 { font-size:16px; font-weight:700; margin:0; }
.gp-modal-close { background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8; line-height:1; }
.gp-modal-body { padding:24px; overflow-y:auto; flex:1; }
.gp-modal-footer { padding:16px 24px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px; }

.gp-form-group { margin-bottom:16px; }
.gp-form-group label { display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:4px; }
.gp-form-group .required { color:#dc2626; }
.gp-form-group input, .gp-form-group select, .gp-form-group textarea { width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; font-family:inherit; box-sizing:border-box; }
.gp-form-group textarea { resize:vertical; }
.gp-form-row { display:flex; gap:12px; }
.gp-form-row .gp-form-group { flex:1; }
.gp-char-count { font-size:11px; color:#94a3b8; text-align:right; display:block; margin-top:2px; }

.gp-radio-group { display:flex; gap:16px; }
.gp-radio-group label { display:flex; align-items:center; gap:4px; font-size:13px; font-weight:400; cursor:pointer; }
.gp-radio-group input[type="radio"] { margin:0; width:auto; }

.gp-image-upload { display:flex; align-items:center; gap:12px; }
.gp-image-preview { position:relative; display:inline-block; }
.gp-image-preview img { max-width:120px; max-height:80px; border-radius:8px; border:1px solid #e2e8f0; }
.gp-btn-remove { position:absolute; top:-6px; right:-6px; width:20px; height:20px; border-radius:50%; background:#dc2626; color:#fff; border:none; cursor:pointer; font-size:12px; line-height:1; display:flex; align-items:center; justify-content:center; }

/* CSV */
.gp-csv-info { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:16px; font-size:12px; color:#475569; line-height:1.7; }
.gp-csv-info code { background:#e2e8f0; padding:1px 4px; border-radius:3px; font-size:11px; }
.gp-csv-preview-table { width:100%; border-collapse:collapse; font-size:11px; margin-top:12px; }
.gp-csv-preview-table th, .gp-csv-preview-table td { padding:6px 8px; border:1px solid #e2e8f0; text-align:left; }
.gp-csv-preview-table th { background:#f1f5f9; font-weight:600; }
.gp-csv-errors { margin-top:12px; }
.gp-csv-error-item { font-size:12px; color:#dc2626; padding:4px 0; }
.gp-csv-result { margin-top:12px; font-size:13px; }
.gp-csv-result .success { color:#16a34a; font-weight:600; }
.gp-csv-result .error { color:#dc2626; font-weight:600; }

/* Toast */
.gp-toast { position:fixed; bottom:24px; right:24px; padding:12px 20px; border-radius:10px; font-size:13px; color:#fff; z-index:10000; opacity:0; transition:opacity .3s; pointer-events:none; }
.gp-toast.success { background:#16a34a; }
.gp-toast.error { background:#dc2626; }
.gp-toast.show { opacity:1; }

/* Tabs */
.gp-tabs { display:flex; gap:0; margin-bottom:20px; border-bottom:2px solid #e2e8f0; }
.gp-tab { padding:10px 20px; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; background:none; border-top:none; border-left:none; border-right:none; font-family:inherit; }
.gp-tab:hover { color:#1e293b; }
.gp-tab.active { color:#3b6b5e; border-bottom-color:#3b6b5e; }
.gp-tab-content { display:none; }
.gp-tab-content.active { display:block; }

/* GBP remote posts */
.gp-gbp-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 20px; margin-bottom:10px; border-left:4px solid #f59e0b; }
.gp-gbp-card .gp-card-header { display:flex; align-items:center; gap:10px; margin-bottom:6px; flex-wrap:wrap; }
.gp-gbp-state { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; background:#fef3c7; color:#92400e; }
.gp-gbp-state.live { background:#dcfce7; color:#15803d; }
.gp-gbp-link { font-size:11px; color:#2563eb; text-decoration:none; margin-left:auto; }
.gp-gbp-link:hover { text-decoration:underline; }

.gp-empty { text-align:center; padding:48px 20px; color:#94a3b8; }
.gp-empty p { font-size:14px; }

.gp-loading { text-align:center; padding:40px; color:#94a3b8; }
.spinner-sm { display:inline-block; width:14px; height:14px; border:2px solid currentColor; border-right-color:transparent; border-radius:50%; animation:gp-spin .6s linear infinite; }
@keyframes gp-spin { to { transform:rotate(360deg); } }

@media (max-width:768px) {
    .gp-summary { flex-direction:column; }
    .gp-form-row { flex-direction:column; }
    .gp-card-body { flex-direction:column; }
    .gp-card-thumb { width:100%; height:auto; max-height:160px; }
    .gp-modal { width:95%; max-width:none; }
    .gp-radio-group { flex-direction:column; gap:8px; }
    .gp-card-date { margin-left:0; }
}
</style>

<div class="content-area">

<?php if ( ! $gbp_connected ) : ?>
    <div class="gp-not-connected">
        <p style="font-size:48px;">📡</p>
        <h2 style="font-size:18px; color:#1e293b; margin-bottom:8px;">Googleビジネスプロフィールが未接続です</h2>
        <p>投稿管理を利用するには、まず<a href="<?php echo esc_url( home_url( '/meo/meo-dashboard/' ) ); ?>">MEOダッシュボード</a>からGBP連携を行ってください。</p>
    </div>
<?php else : ?>

    <!-- サマリー -->
    <div class="gp-summary" id="summaryArea">
        <div class="gp-summary-card"><div class="gp-sc-label">全投稿数</div><div class="gp-sc-value" id="smTotal">-</div></div>
        <div class="gp-summary-card"><div class="gp-sc-label">下書き</div><div class="gp-sc-value" id="smDraft">-</div></div>
        <div class="gp-summary-card gp-sc-scheduled"><div class="gp-sc-label">予約中</div><div class="gp-sc-value" id="smScheduled">-</div></div>
        <div class="gp-summary-card"><div class="gp-sc-label">投稿済み</div><div class="gp-sc-value" id="smPosted">-</div></div>
        <div class="gp-summary-card gp-sc-failed"><div class="gp-sc-label">失敗</div><div class="gp-sc-value" id="smFailed">-</div></div>
    </div>

    <!-- タブ切り替え -->
    <div class="gp-tabs">
        <button class="gp-tab active" data-tab="local">📝 投稿管理</button>
        <button class="gp-tab" data-tab="gbp">🌐 Google上の投稿</button>
    </div>

    <!-- ===== ローカル投稿タブ ===== -->
    <div class="gp-tab-content active" id="tabLocal">

    <!-- アクションバー -->
    <div class="gp-action-bar">
        <button class="gp-btn gp-btn-primary" id="newPostBtn">＋ 新規投稿</button>
        <button class="gp-btn" id="csvImportBtn">📄 CSV一括取込</button>
        <button class="gp-btn" id="csvTemplateBtn">⬇ テンプレートDL</button>
    </div>

    <!-- フィルタ -->
    <div class="gp-filters">
        <button class="gp-filter-btn active" data-filter="all">すべて</button>
        <button class="gp-filter-btn" data-filter="draft">下書き</button>
        <button class="gp-filter-btn" data-filter="scheduled">予約中</button>
        <button class="gp-filter-btn" data-filter="posted">投稿済み</button>
        <button class="gp-filter-btn" data-filter="failed">失敗</button>
        <select class="gp-sort-select" id="sortSelect">
            <option value="newest">新しい順</option>
            <option value="oldest">古い順</option>
            <option value="scheduled_asc">予約日時順</option>
        </select>
        <button class="gp-refresh-btn" id="refreshBtn">🔄 更新</button>
    </div>

    <!-- 投稿一覧 -->
    <div id="postList" class="gp-post-list">
        <div class="gp-loading"><span class="spinner-sm"></span> 読み込み中...</div>
    </div>

    <!-- ページネーション -->
    <div id="paginationWrap" class="gp-pagination" style="display:none;"></div>

    </div><!-- /tabLocal -->

    <!-- ===== Google上の投稿タブ ===== -->
    <div class="gp-tab-content" id="tabGbp">
        <p style="font-size:13px; color:#64748b; margin-bottom:16px;">GBP管理画面から直接投稿した記事を含む、Google上の全投稿を表示しています。</p>
        <div id="gbpPostList" class="gp-post-list">
            <div class="gp-loading"><span class="spinner-sm"></span> Google投稿を取得中...</div>
        </div>
        <div id="gbpLoadMoreWrap" style="text-align:center; margin-top:16px; display:none;">
            <button class="gp-btn" id="gbpLoadMoreBtn">もっと読み込む</button>
        </div>
    </div><!-- /tabGbp -->

    <!-- 新規/編集モーダル -->
    <div class="gp-modal-overlay" id="postModal" style="display:none;">
        <div class="gp-modal">
            <div class="gp-modal-header">
                <h3 id="modalTitle">新規投稿</h3>
                <button class="gp-modal-close" id="modalClose">&times;</button>
            </div>
            <div class="gp-modal-body">
                <div class="gp-form-group">
                    <label>管理用タイトル</label>
                    <input type="text" id="postTitle" placeholder="例: 4月第1週の投稿">
                </div>
                <div class="gp-form-group">
                    <label>投稿種類</label>
                    <select id="postTopicType">
                        <option value="STANDARD">通常</option>
                        <option value="EVENT">イベント</option>
                        <option value="OFFER">特典</option>
                    </select>
                </div>
                <div class="gp-form-group">
                    <label>投稿本文 <span class="required">*</span></label>
                    <textarea id="postSummary" rows="5" maxlength="1500" placeholder="投稿内容を入力してください"></textarea>
                    <span class="gp-char-count"><span id="summaryCount">0</span>/1500</span>
                </div>

                <div id="eventFields" style="display:none;">
                    <div class="gp-form-group">
                        <label>イベントタイトル <span class="required">*</span></label>
                        <input type="text" id="eventTitle">
                    </div>
                    <div class="gp-form-row">
                        <div class="gp-form-group"><label>開始日時</label><input type="datetime-local" id="eventStart"></div>
                        <div class="gp-form-group"><label>終了日時</label><input type="datetime-local" id="eventEnd"></div>
                    </div>
                </div>

                <div class="gp-form-row">
                    <div class="gp-form-group">
                        <label>ボタン（CTA）</label>
                        <select id="postCtaType">
                            <option value="">なし</option>
                            <option value="LEARN_MORE">詳細</option>
                            <option value="BOOK">予約</option>
                            <option value="ORDER">注文</option>
                            <option value="SHOP">購入</option>
                            <option value="SIGN_UP">登録</option>
                            <option value="CALL">電話</option>
                        </select>
                    </div>
                    <div class="gp-form-group" id="ctaUrlWrap" style="display:none;">
                        <label>ボタンリンクURL</label>
                        <input type="url" id="postCtaUrl" placeholder="https://...">
                    </div>
                </div>

                <div class="gp-form-group">
                    <label>画像</label>
                    <div class="gp-image-upload">
                        <button type="button" class="gp-btn" id="selectImageBtn">画像を選択</button>
                        <div class="gp-image-preview" id="imagePreview" style="display:none;">
                            <img id="imagePreviewImg" src="">
                            <button type="button" class="gp-btn-remove" id="removeImageBtn">&times;</button>
                        </div>
                    </div>
                    <input type="hidden" id="imageAttachmentId" value="">
                    <input type="hidden" id="imageUrl" value="">
                </div>

                <div class="gp-form-group">
                    <label>投稿タイミング</label>
                    <div class="gp-radio-group">
                        <label><input type="radio" name="postAction" value="draft" checked> 下書き保存</label>
                        <label><input type="radio" name="postAction" value="schedule"> 予約投稿</label>
                        <label><input type="radio" name="postAction" value="post_now"> 今すぐ投稿</label>
                    </div>
                </div>
                <div class="gp-form-group" id="scheduleWrap" style="display:none;">
                    <label>予約日時 <span class="required">*</span></label>
                    <input type="datetime-local" id="scheduledAt">
                </div>
            </div>
            <div class="gp-modal-footer">
                <button class="gp-btn" id="modalCancelBtn">キャンセル</button>
                <button class="gp-btn gp-btn-primary" id="modalSubmitBtn">保存</button>
            </div>
        </div>
    </div>

    <!-- CSVモーダル -->
    <div class="gp-modal-overlay" id="csvModal" style="display:none;">
        <div class="gp-modal">
            <div class="gp-modal-header">
                <h3>CSV一括取込</h3>
                <button class="gp-modal-close" id="csvModalClose">&times;</button>
            </div>
            <div class="gp-modal-body">
                <div class="gp-csv-info">
                    <strong>CSVフォーマット（UTF-8）</strong><br>
                    列: <code>投稿日時</code>, <code>種類</code>, <code>本文</code>, <code>CTAタイプ</code>, <code>CTAリンク</code>, <code>イベントタイトル</code>, <code>イベント開始</code>, <code>イベント終了</code>, <code>画像URL</code><br><br>
                    ・投稿日時: <code>2026-04-01 10:00</code> 形式（空欄で下書き登録）<br>
                    ・種類: <code>STANDARD</code>（空欄可）/ <code>EVENT</code> / <code>OFFER</code><br>
                    ・CTAタイプ: <code>LEARN_MORE</code> / <code>BOOK</code> / <code>ORDER</code> / <code>SHOP</code> / <code>SIGN_UP</code> / <code>CALL</code><br>
                    ・1回の取込は最大100件まで
                </div>
                <div class="gp-form-group">
                    <label>CSVファイル</label>
                    <input type="file" id="csvFileInput" accept=".csv">
                </div>
                <div id="csvPreviewArea" style="display:none;"></div>
            </div>
            <div class="gp-modal-footer">
                <button class="gp-btn" id="csvPreviewBtn" disabled>プレビュー</button>
                <button class="gp-btn gp-btn-primary" id="csvSubmitBtn" style="display:none;">取込実行</button>
            </div>
        </div>
    </div>

<?php endif; ?>
</div>

<!-- Toast -->
<div class="gp-toast" id="gpToast"></div>

<?php if ( $gbp_connected ) : ?>
<script>
(function(){
    'use strict';
    var restBase = '<?php echo esc_url_raw( rest_url( 'gcrev/v1/' ) ); ?>';
    var nonce    = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';

    var currentFilter = 'all';
    var currentSort   = 'newest';
    var currentPage   = 1;
    var totalPages    = 1;
    var editingPostId = null;
    var mediaFrame    = null;

    // ===== Utilities =====
    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div'); d.textContent = s; return d.innerHTML;
    }
    function showToast(msg, type) {
        var t = document.getElementById('gpToast');
        t.textContent = msg;
        t.className = 'gp-toast ' + (type || 'success') + ' show';
        setTimeout(function(){ t.classList.remove('show'); }, 3500);
    }
    function formatDate(d) {
        if (!d) return '-';
        return d.replace(/-/g, '/').replace('T', ' ').substring(0, 16);
    }
    function fetchJson(url, opts) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        opts.headers = Object.assign({ 'X-WP-Nonce': nonce }, opts.headers || {});
        return fetch(url, opts).then(function(r) { return r.json(); });
    }

    // ===== Summary =====
    function loadSummary() {
        fetchJson(restBase + 'meo/posts/summary').then(function(data) {
            if (!data.success) return;
            var s = data.summary;
            document.getElementById('smTotal').textContent = s.total;
            document.getElementById('smDraft').textContent = s.draft;
            document.getElementById('smScheduled').textContent = s.scheduled;
            document.getElementById('smPosted').textContent = s.posted;
            document.getElementById('smFailed').textContent = s.failed;
        });
    }

    // ===== Post List =====
    function loadPosts() {
        var list = document.getElementById('postList');
        list.innerHTML = '<div class="gp-loading"><span class="spinner-sm"></span> 読み込み中...</div>';
        var url = restBase + 'meo/posts?status=' + encodeURIComponent(currentFilter)
            + '&sort=' + encodeURIComponent(currentSort)
            + '&page=' + currentPage + '&per_page=20';
        fetchJson(url).then(function(data) {
            if (!data.success) { list.innerHTML = '<div class="gp-empty"><p>取得に失敗しました。</p></div>'; return; }
            totalPages = data.pages || 1;
            if (!data.posts || data.posts.length === 0) {
                list.innerHTML = '<div class="gp-empty"><p>投稿がありません。</p></div>';
                document.getElementById('paginationWrap').style.display = 'none';
                return;
            }
            var html = '';
            data.posts.forEach(function(p) { html += renderPostCard(p); });
            list.innerHTML = html;
            renderPagination();
        });
    }

    function renderPostCard(p) {
        var badgeClass = 'gp-badge-' + p.status;
        var statusLabels = { draft: '下書き', scheduled: '予約中', posted: '投稿済み', failed: '失敗', cancelled: 'キャンセル' };
        var topicLabels = { STANDARD: '通常', EVENT: 'イベント', OFFER: '特典' };

        var dateLabel = '';
        if (p.status === 'scheduled' && p.scheduled_at) dateLabel = '予約: ' + formatDate(p.scheduled_at);
        else if (p.status === 'posted' && p.posted_at) dateLabel = '投稿: ' + formatDate(p.posted_at);
        else dateLabel = '作成: ' + formatDate(p.created_at);

        var titleText = p.title || (p.summary ? p.summary.substring(0, 30) + (p.summary.length > 30 ? '…' : '') : '（タイトルなし）');
        var summaryText = p.summary ? (p.summary.length > 150 ? p.summary.substring(0, 150) + '…' : p.summary) : '';

        var imgHtml = '';
        var imgUrl = p.image_url || '';
        if (imgUrl) imgHtml = '<img class="gp-card-thumb" src="' + escHtml(imgUrl) + '" alt="">';

        var metaHtml = '<span>' + escHtml(topicLabels[p.topic_type] || p.topic_type) + '</span>';
        if (p.cta_type) metaHtml += '<span>CTA: ' + escHtml(p.cta_type) + '</span>';
        if (p.csv_import == 1) metaHtml += '<span>📄 CSV取込</span>';

        var errorHtml = '';
        if (p.status === 'failed' && p.error_message) {
            errorHtml = '<div class="gp-card-error">⚠ ' + escHtml(p.error_message) + '</div>';
        }

        var actions = '';
        actions += '<button class="gp-btn" data-action="edit" data-post-id="' + p.id + '">編集</button>';
        actions += '<button class="gp-btn" data-action="duplicate" data-post-id="' + p.id + '">複製</button>';
        if (p.status === 'draft' || p.status === 'scheduled') {
            actions += '<button class="gp-btn gp-btn-primary" data-action="post-now" data-post-id="' + p.id + '">今すぐ投稿</button>';
        }
        if (p.status === 'scheduled') {
            actions += '<button class="gp-btn" data-action="cancel" data-post-id="' + p.id + '">キャンセル</button>';
        }
        if (p.status === 'failed') {
            actions += '<button class="gp-btn gp-btn-primary" data-action="retry" data-post-id="' + p.id + '">再実行</button>';
        }
        actions += '<button class="gp-btn gp-btn-danger" data-action="delete" data-post-id="' + p.id + '">削除</button>';

        return '<div class="gp-post-card gp-status-' + p.status + '">'
            + '<div class="gp-card-header">'
            +   '<span class="gp-status-badge ' + badgeClass + '">' + escHtml(statusLabels[p.status] || p.status) + '</span>'
            +   '<span class="gp-card-title">' + escHtml(titleText) + '</span>'
            +   '<span class="gp-card-date">' + escHtml(dateLabel) + '</span>'
            + '</div>'
            + '<div class="gp-card-body">'
            +   imgHtml
            +   '<div class="gp-card-content">'
            +     '<div class="gp-card-summary">' + escHtml(summaryText) + '</div>'
            +     '<div class="gp-card-meta">' + metaHtml + '</div>'
            +     errorHtml
            +   '</div>'
            + '</div>'
            + '<div class="gp-card-actions">' + actions + '</div>'
            + '</div>';
    }

    function renderPagination() {
        var wrap = document.getElementById('paginationWrap');
        if (totalPages <= 1) { wrap.style.display = 'none'; return; }
        wrap.style.display = 'flex';
        var html = '';
        if (currentPage > 1) html += '<button class="gp-page-btn" data-page="' + (currentPage - 1) + '">&lt;</button>';
        for (var i = 1; i <= totalPages; i++) {
            if (totalPages > 7 && Math.abs(i - currentPage) > 2 && i !== 1 && i !== totalPages) {
                if (i === currentPage - 3 || i === currentPage + 3) html += '<span style="padding:6px">…</span>';
                continue;
            }
            html += '<button class="gp-page-btn' + (i === currentPage ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }
        if (currentPage < totalPages) html += '<button class="gp-page-btn" data-page="' + (currentPage + 1) + '">&gt;</button>';
        wrap.innerHTML = html;
    }

    // ===== Modal =====
    function openNewModal() {
        editingPostId = null;
        document.getElementById('modalTitle').textContent = '新規投稿';
        document.getElementById('postTitle').value = '';
        document.getElementById('postTopicType').value = 'STANDARD';
        document.getElementById('postSummary').value = '';
        document.getElementById('summaryCount').textContent = '0';
        document.getElementById('eventTitle').value = '';
        document.getElementById('eventStart').value = '';
        document.getElementById('eventEnd').value = '';
        document.getElementById('postCtaType').value = '';
        document.getElementById('postCtaUrl').value = '';
        document.getElementById('imageAttachmentId').value = '';
        document.getElementById('imageUrl').value = '';
        document.getElementById('imagePreview').style.display = 'none';
        document.querySelector('input[name="postAction"][value="draft"]').checked = true;
        document.getElementById('scheduledAt').value = '';
        toggleTopicFields();
        toggleCtaUrl();
        toggleSchedule();
        document.getElementById('postModal').style.display = 'flex';
    }

    function openEditModal(postId) {
        fetchJson(restBase + 'meo/posts?status=all&per_page=50&page=1').then(function(data) {
            if (!data.success) return;
            var post = null;
            data.posts.forEach(function(p) { if (parseInt(p.id) === postId) post = p; });
            if (!post) { showToast('投稿が見つかりません。', 'error'); return; }
            editingPostId = postId;
            document.getElementById('modalTitle').textContent = '投稿を編集';
            document.getElementById('postTitle').value = post.title || '';
            document.getElementById('postTopicType').value = post.topic_type || 'STANDARD';
            document.getElementById('postSummary').value = post.summary || '';
            document.getElementById('summaryCount').textContent = (post.summary || '').length;
            document.getElementById('eventTitle').value = post.event_title || '';
            document.getElementById('eventStart').value = (post.event_start || '').substring(0, 16).replace(' ', 'T');
            document.getElementById('eventEnd').value = (post.event_end || '').substring(0, 16).replace(' ', 'T');
            document.getElementById('postCtaType').value = post.cta_type || '';
            document.getElementById('postCtaUrl').value = post.cta_url || '';
            document.getElementById('imageAttachmentId').value = post.image_attachment_id || '';
            document.getElementById('imageUrl').value = post.image_url || '';
            if (post.image_url) {
                document.getElementById('imagePreviewImg').src = post.image_url;
                document.getElementById('imagePreview').style.display = 'inline-block';
            } else {
                document.getElementById('imagePreview').style.display = 'none';
            }
            if (post.status === 'scheduled') {
                document.querySelector('input[name="postAction"][value="schedule"]').checked = true;
                document.getElementById('scheduledAt').value = (post.scheduled_at || '').substring(0, 16).replace(' ', 'T');
            } else {
                document.querySelector('input[name="postAction"][value="draft"]').checked = true;
                document.getElementById('scheduledAt').value = '';
            }
            toggleTopicFields();
            toggleCtaUrl();
            toggleSchedule();
            document.getElementById('postModal').style.display = 'flex';
        });
    }

    function closeModal() {
        document.getElementById('postModal').style.display = 'none';
        editingGbpName = null;
        // 投稿タイミングラジオを再表示
        document.querySelectorAll('input[name="postAction"]').forEach(function(r){ r.closest('label').style.display = ''; });
    }

    function submitPost() {
        var btn = document.getElementById('modalSubmitBtn');
        var origText = btn.textContent;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-sm"></span> 処理中...';

        var action = document.querySelector('input[name="postAction"]:checked').value;
        var body = {
            action: action,
            title: document.getElementById('postTitle').value,
            summary: document.getElementById('postSummary').value,
            topic_type: document.getElementById('postTopicType').value,
            cta_type: document.getElementById('postCtaType').value,
            cta_url: document.getElementById('postCtaUrl').value,
            event_title: document.getElementById('eventTitle').value,
            event_start: (document.getElementById('eventStart').value || '').replace('T', ' '),
            event_end: (document.getElementById('eventEnd').value || '').replace('T', ' '),
            image_attachment_id: document.getElementById('imageAttachmentId').value,
            image_url: document.getElementById('imageUrl').value,
            scheduled_at: (document.getElementById('scheduledAt').value || '').replace('T', ' '),
        };

        var url, method;
        if (editingGbpName) {
            // GBP上の投稿を直接更新
            body.gbp_name = editingGbpName;
            url = restBase + 'meo/posts/gbp-update';
            method = 'POST';
        } else if (editingPostId) {
            url = restBase + 'meo/posts/' + editingPostId;
            method = 'POST';
        } else {
            url = restBase + 'meo/posts';
            method = 'POST';
        }

        fetchJson(url, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function(data) {
            btn.disabled = false;
            btn.textContent = origText;
            if (data.success) {
                showToast(data.message || '保存しました。');
                closeModal();
                loadSummary();
                loadPosts();
                if (gbpPostsLoaded) { gbpPostsLoaded = false; loadGbpPosts(); }
            } else {
                showToast(data.message || 'エラーが発生しました。', 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.textContent = origText;
            showToast('通信エラーが発生しました。', 'error');
        });
    }

    // ===== Actions =====
    function performAction(actionName, postId, confirmMsg) {
        if (confirmMsg && !confirm(confirmMsg)) return;
        fetchJson(restBase + 'meo/posts/' + postId + '/' + actionName, { method: 'POST' }).then(function(data) {
            showToast(data.message || (data.success ? '完了しました。' : 'エラー'), data.success ? 'success' : 'error');
            loadSummary();
            loadPosts();
        });
    }

    // ===== Image Picker =====
    function openMediaPicker() {
        if (!mediaFrame) {
            mediaFrame = wp.media({ title: '画像を選択', multiple: false, library: { type: 'image' } });
            mediaFrame.on('select', function() {
                var att = mediaFrame.state().get('selection').first().toJSON();
                document.getElementById('imageAttachmentId').value = att.id;
                var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                document.getElementById('imageUrl').value = att.url;
                document.getElementById('imagePreviewImg').src = url;
                document.getElementById('imagePreview').style.display = 'inline-block';
            });
        }
        mediaFrame.open();
    }

    // ===== Toggle helpers =====
    function toggleTopicFields() {
        var v = document.getElementById('postTopicType').value;
        document.getElementById('eventFields').style.display = v === 'EVENT' ? 'block' : 'none';
    }
    function toggleCtaUrl() {
        document.getElementById('ctaUrlWrap').style.display = document.getElementById('postCtaType').value ? 'block' : 'none';
    }
    function toggleSchedule() {
        var v = document.querySelector('input[name="postAction"]:checked').value;
        document.getElementById('scheduleWrap').style.display = v === 'schedule' ? 'block' : 'none';
    }

    // ===== CSV =====
    function openCsvModal() {
        document.getElementById('csvFileInput').value = '';
        document.getElementById('csvPreviewArea').style.display = 'none';
        document.getElementById('csvPreviewArea').innerHTML = '';
        document.getElementById('csvPreviewBtn').disabled = true;
        document.getElementById('csvSubmitBtn').style.display = 'none';
        document.getElementById('csvModal').style.display = 'flex';
    }

    function previewCsv() {
        var fileInput = document.getElementById('csvFileInput');
        if (!fileInput.files.length) return;
        var fd = new FormData();
        fd.append('file', fileInput.files[0]);
        fd.append('preview', '1');

        var btn = document.getElementById('csvPreviewBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-sm"></span> 確認中...';

        fetch(restBase + 'meo/posts/csv-import', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce },
            body: fd
        }).then(function(r){ return r.json(); }).then(function(data) {
            btn.disabled = false;
            btn.textContent = 'プレビュー';
            var area = document.getElementById('csvPreviewArea');
            area.style.display = 'block';

            var html = '<div class="gp-csv-result">有効: <span class="success">' + (data.valid_count || 0) + '件</span> / エラー: <span class="error">' + (data.error_count || 0) + '件</span></div>';

            if (data.errors && data.errors.length > 0) {
                html += '<div class="gp-csv-errors">';
                data.errors.forEach(function(e) {
                    html += '<div class="gp-csv-error-item">行' + e.line + ': ' + escHtml(e.reason) + '</div>';
                });
                html += '</div>';
            }

            if (data.preview_rows && data.preview_rows.length > 0) {
                html += '<table class="gp-csv-preview-table"><tr><th>投稿日時</th><th>種類</th><th>本文</th><th>CTA</th></tr>';
                data.preview_rows.forEach(function(r) {
                    var sumShort = (r.summary || '').substring(0, 40) + ((r.summary || '').length > 40 ? '…' : '');
                    html += '<tr><td>' + escHtml(r.scheduled_at || '(下書き)') + '</td><td>' + escHtml(r.topic_type) + '</td><td>' + escHtml(sumShort) + '</td><td>' + escHtml(r.cta_type || '-') + '</td></tr>';
                });
                html += '</table>';
            }

            area.innerHTML = html;

            if (data.valid_count > 0) {
                document.getElementById('csvSubmitBtn').style.display = 'inline-flex';
            }
        }).catch(function() {
            btn.disabled = false;
            btn.textContent = 'プレビュー';
            showToast('プレビューに失敗しました。', 'error');
        });
    }

    function submitCsv() {
        var fileInput = document.getElementById('csvFileInput');
        if (!fileInput.files.length) return;
        var fd = new FormData();
        fd.append('file', fileInput.files[0]);

        var btn = document.getElementById('csvSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-sm"></span> 取込中...';

        fetch(restBase + 'meo/posts/csv-import', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce },
            body: fd
        }).then(function(r){ return r.json(); }).then(function(data) {
            btn.disabled = false;
            btn.textContent = '取込実行';
            if (data.success) {
                showToast(data.message || data.imported_count + '件を登録しました。');
                document.getElementById('csvModal').style.display = 'none';
                loadSummary();
                loadPosts();
            } else {
                showToast(data.message || '取込に失敗しました。', 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.textContent = '取込実行';
            showToast('通信エラーが発生しました。', 'error');
        });
    }

    function downloadCsvTemplate() {
        window.location.href = restBase + 'meo/posts/csv-template?_wpnonce=' + encodeURIComponent(nonce);
    }

    // ===== Event Listeners =====

    // Post list actions (delegation)
    document.getElementById('postList').addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var action = btn.dataset.action;
        var postId = parseInt(btn.dataset.postId, 10);
        if (action === 'edit') openEditModal(postId);
        else if (action === 'delete') performAction('delete', postId, 'この投稿を削除しますか？');
        else if (action === 'duplicate') performAction('duplicate', postId);
        else if (action === 'post-now') performAction('post-now', postId, 'この投稿を今すぐGoogleに投稿しますか？');
        else if (action === 'retry') performAction('retry', postId);
        else if (action === 'cancel') performAction('cancel', postId, '予約をキャンセルしますか？');
    });

    // Pagination
    document.getElementById('paginationWrap').addEventListener('click', function(e) {
        var btn = e.target.closest('[data-page]');
        if (!btn) return;
        currentPage = parseInt(btn.dataset.page, 10);
        loadPosts();
    });

    // Filters
    document.querySelector('.gp-filters').addEventListener('click', function(e) {
        var btn = e.target.closest('.gp-filter-btn');
        if (!btn) return;
        document.querySelectorAll('.gp-filter-btn').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
        currentFilter = btn.dataset.filter;
        currentPage = 1;
        loadPosts();
    });

    // Sort
    document.getElementById('sortSelect').addEventListener('change', function() {
        currentSort = this.value;
        currentPage = 1;
        loadPosts();
    });

    // Refresh
    document.getElementById('refreshBtn').addEventListener('click', function() {
        loadSummary();
        loadPosts();
    });

    // New post
    document.getElementById('newPostBtn').addEventListener('click', openNewModal);
    document.getElementById('modalClose').addEventListener('click', closeModal);
    document.getElementById('modalCancelBtn').addEventListener('click', closeModal);
    document.getElementById('modalSubmitBtn').addEventListener('click', submitPost);

    // Modal overlay click
    document.getElementById('postModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Topic type toggle
    document.getElementById('postTopicType').addEventListener('change', toggleTopicFields);
    // CTA toggle
    document.getElementById('postCtaType').addEventListener('change', toggleCtaUrl);
    // Schedule toggle
    document.querySelectorAll('input[name="postAction"]').forEach(function(r) {
        r.addEventListener('change', toggleSchedule);
    });
    // Char count
    document.getElementById('postSummary').addEventListener('input', function() {
        document.getElementById('summaryCount').textContent = this.value.length;
    });
    // Image picker
    document.getElementById('selectImageBtn').addEventListener('click', openMediaPicker);
    document.getElementById('removeImageBtn').addEventListener('click', function() {
        document.getElementById('imageAttachmentId').value = '';
        document.getElementById('imageUrl').value = '';
        document.getElementById('imagePreview').style.display = 'none';
    });

    // CSV
    document.getElementById('csvImportBtn').addEventListener('click', openCsvModal);
    document.getElementById('csvModalClose').addEventListener('click', function() {
        document.getElementById('csvModal').style.display = 'none';
    });
    document.getElementById('csvModal').addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
    document.getElementById('csvFileInput').addEventListener('change', function() {
        document.getElementById('csvPreviewBtn').disabled = !this.files.length;
        document.getElementById('csvSubmitBtn').style.display = 'none';
        document.getElementById('csvPreviewArea').style.display = 'none';
    });
    document.getElementById('csvPreviewBtn').addEventListener('click', previewCsv);
    document.getElementById('csvSubmitBtn').addEventListener('click', submitCsv);
    document.getElementById('csvTemplateBtn').addEventListener('click', downloadCsvTemplate);

    // ===== Tabs =====
    var gbpPostsLoaded = false;
    var gbpNextPageToken = null;

    document.querySelector('.gp-tabs').addEventListener('click', function(e) {
        var tab = e.target.closest('.gp-tab');
        if (!tab) return;
        var target = tab.dataset.tab;
        document.querySelectorAll('.gp-tab').forEach(function(t){ t.classList.remove('active'); });
        document.querySelectorAll('.gp-tab-content').forEach(function(c){ c.classList.remove('active'); });
        tab.classList.add('active');
        document.getElementById(target === 'gbp' ? 'tabGbp' : 'tabLocal').classList.add('active');
        if (target === 'gbp' && !gbpPostsLoaded) {
            loadGbpPosts();
        }
    });

    // ===== GBP Remote Posts =====
    function loadGbpPosts(append) {
        var list = document.getElementById('gbpPostList');
        if (!append) list.innerHTML = '<div class="gp-loading"><span class="spinner-sm"></span> Google投稿を取得中...</div>';

        var url = restBase + 'meo/posts/gbp-list';
        if (gbpNextPageToken && append) url += '?page_token=' + encodeURIComponent(gbpNextPageToken);

        fetchJson(url).then(function(data) {
            gbpPostsLoaded = true;
            if (!data.success) {
                list.innerHTML = '<div class="gp-empty"><p>⚠ ' + escHtml(data.message || '取得に失敗しました。') + '</p></div>';
                return;
            }
            if (!data.posts || data.posts.length === 0) {
                if (!append) list.innerHTML = '<div class="gp-empty"><p>Google上に投稿がありません。</p></div>';
                return;
            }

            gbpNextPageToken = data.nextPageToken || null;
            var html = append ? list.innerHTML : '';
            data.posts.forEach(function(p) { html += renderGbpCard(p); });
            list.innerHTML = html;

            var loadMore = document.getElementById('gbpLoadMoreWrap');
            loadMore.style.display = gbpNextPageToken ? 'block' : 'none';
        }).catch(function() {
            list.innerHTML = '<div class="gp-empty"><p>通信エラーが発生しました。</p></div>';
        });
    }

    function renderGbpCard(p) {
        var stateLabels = { LIVE: '公開中', REJECTED: '却下', PROCESSING: '処理中' };
        var stateLabel = stateLabels[p.state] || p.state || '不明';
        var stateClass = p.state === 'LIVE' ? 'live' : '';
        var topicLabels = { STANDARD: '通常', EVENT: 'イベント', OFFER: '特典', ALERT: 'アラート' };
        var summaryText = p.summary ? (p.summary.length > 200 ? p.summary.substring(0, 200) + '…' : p.summary) : '';
        var createDate = p.create_time ? formatDate(p.create_time) : '-';

        var imgHtml = '';
        if (p.image_url) imgHtml = '<img class="gp-card-thumb" src="' + escHtml(p.image_url) + '" alt="">';

        var metaHtml = '<span>' + escHtml(topicLabels[p.topic_type] || p.topic_type) + '</span>';
        if (p.cta_type) metaHtml += '<span>CTA: ' + escHtml(p.cta_type) + '</span>';
        if (p.event_title) metaHtml += '<span>🎪 ' + escHtml(p.event_title) + '</span>';

        var linkHtml = '';
        if (p.search_url) linkHtml = '<a class="gp-gbp-link" href="' + escHtml(p.search_url) + '" target="_blank" rel="noopener">Googleで見る ↗</a>';

        var actionsHtml = '<div class="gp-card-actions">'
            + '<button class="gp-btn" data-gbp-action="edit" data-gbp-post=\'' + escHtml(JSON.stringify(p)) + '\'>編集</button>'
            + '<button class="gp-btn gp-btn-danger" data-gbp-action="delete" data-gbp-name="' + escHtml(p.gbp_name) + '">削除</button>'
            + '</div>';

        return '<div class="gp-gbp-card">'
            + '<div class="gp-card-header">'
            +   '<span class="gp-gbp-state ' + stateClass + '">' + escHtml(stateLabel) + '</span>'
            +   '<span class="gp-card-date">' + escHtml(createDate) + '</span>'
            +   linkHtml
            + '</div>'
            + '<div class="gp-card-body">'
            +   imgHtml
            +   '<div class="gp-card-content">'
            +     '<div class="gp-card-summary">' + escHtml(summaryText) + '</div>'
            +     '<div class="gp-card-meta">' + metaHtml + '</div>'
            +   '</div>'
            + '</div>'
            + actionsHtml
            + '</div>';
    }

    document.getElementById('gbpLoadMoreBtn').addEventListener('click', function() {
        if (gbpNextPageToken) loadGbpPosts(true);
    });

    // GBP投稿の編集・削除
    var editingGbpName = null;

    document.getElementById('gbpPostList').addEventListener('click', function(e) {
        var btn = e.target.closest('[data-gbp-action]');
        if (!btn) return;
        var action = btn.dataset.gbpAction;

        if (action === 'edit') {
            var postData = JSON.parse(btn.dataset.gbpPost);
            openGbpEditModal(postData);
        } else if (action === 'delete') {
            var gbpName = btn.dataset.gbpName;
            if (!confirm('この投稿をGoogleから削除しますか？この操作は元に戻せません。')) return;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-sm"></span>';
            fetchJson(restBase + 'meo/posts/gbp-delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ gbp_name: gbpName })
            }).then(function(data) {
                showToast(data.message || (data.success ? '削除しました。' : 'エラー'), data.success ? 'success' : 'error');
                if (data.success) {
                    gbpPostsLoaded = false;
                    loadGbpPosts();
                } else {
                    btn.disabled = false;
                    btn.textContent = '削除';
                }
            });
        }
    });

    function openGbpEditModal(p) {
        editingPostId = null;
        editingGbpName = p.gbp_name;
        document.getElementById('modalTitle').textContent = 'Google投稿を編集';
        document.getElementById('postTitle').value = '';
        document.getElementById('postTopicType').value = p.topic_type || 'STANDARD';
        document.getElementById('postSummary').value = p.summary || '';
        document.getElementById('summaryCount').textContent = (p.summary || '').length;
        document.getElementById('eventTitle').value = p.event_title || '';
        document.getElementById('eventStart').value = '';
        document.getElementById('eventEnd').value = '';
        document.getElementById('postCtaType').value = p.cta_type || '';
        document.getElementById('postCtaUrl').value = p.cta_url || '';
        document.getElementById('imageAttachmentId').value = '';
        document.getElementById('imageUrl').value = p.image_url || '';
        if (p.image_url) {
            document.getElementById('imagePreviewImg').src = p.image_url;
            document.getElementById('imagePreview').style.display = 'inline-block';
        } else {
            document.getElementById('imagePreview').style.display = 'none';
        }
        // GBP編集では投稿タイミング選択を非表示
        document.querySelectorAll('input[name="postAction"]').forEach(function(r){ r.closest('label').style.display = 'none'; });
        document.getElementById('scheduleWrap').style.display = 'none';
        toggleTopicFields();
        toggleCtaUrl();
        document.getElementById('postModal').style.display = 'flex';
    }

    // ===== Init =====
    loadSummary();
    loadPosts();
})();
</script>
<?php endif; ?>

<?php get_footer(); ?>
