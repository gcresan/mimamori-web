<?php
/*
Template Name: 順位トラッキング
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', '自然検索順位');
set_query_var('gcrev_page_subtitle', '指定キーワードの Google 検索順位を確認できます。');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('自然検索順位', '検索順位チェック'));

get_header();
?>

<style>
/* ============================================================
   page-rank-tracker v2 — Clean card-based layout
   ============================================================ */

/* --- Header bar --- */
.rt-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}
.rt-header__title {
    font-size: 20px;
    font-weight: 700;
    color: #1a1a1a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.rt-header__actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.rt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border: 1px solid #d0d5dd;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    background: #fff;
    color: #344054;
    transition: all 0.15s;
    white-space: nowrap;
}
.rt-btn:hover { background: #f9fafb; border-color: #98a2b3; }
.rt-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.rt-btn--primary {
    background: #1a1a1a;
    color: #fff;
    border-color: #1a1a1a;
}
.rt-btn--primary:hover { background: #333; }
.rt-btn--primary:disabled { background: #999; border-color: #999; }
.rt-btn__icon { font-size: 15px; }

/* --- Device toggle --- */
.rt-device-toggle {
    display: inline-flex;
    background: #f2f4f7;
    border-radius: 8px;
    padding: 3px;
    margin-bottom: 20px;
}
.rt-device-btn {
    padding: 7px 20px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    background: transparent;
    color: #667085;
    transition: all 0.2s;
}
.rt-device-btn.active {
    background: #fff;
    color: #1a1a1a;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

/* --- Summary cards --- */
.rt-summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 28px;
}
@media (max-width: 768px) {
    .rt-summary-cards { grid-template-columns: repeat(2, 1fr); }
}
.rt-summary-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    border-left: 4px solid #e5e7eb;
}
.rt-summary-card--gold   { border-left-color: #f59e0b; }
.rt-summary-card--blue   { border-left-color: #3b82f6; }
.rt-summary-card--green  { border-left-color: #22c55e; }
.rt-summary-card--red    { border-left-color: #ef4444; }
.rt-summary-card__dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
.rt-summary-card__dot--gold  { background: #f59e0b; }
.rt-summary-card__dot--blue  { background: #3b82f6; }
.rt-summary-card__dot--green { background: #22c55e; }
.rt-summary-card__dot--red   { background: #ef4444; }
.rt-summary-card__label {
    font-size: 13px;
    color: #6b7280;
    flex: 1;
}
.rt-summary-card__count {
    font-size: 20px;
    font-weight: 700;
    color: #1a1a1a;
    min-width: 32px;
    text-align: right;
}
.rt-summary-card__unit {
    font-size: 12px;
    font-weight: 400;
    color: #9ca3af;
}

/* --- Rankings table --- */
.rt-table-wrap {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 32px;
}
.rt-table {
    width: 100%;
    border-collapse: collapse;
}
.rt-table th {
    background: #f9fafb;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    padding: 12px 14px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
}
.rt-table td {
    padding: 14px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #1a1a1a;
    vertical-align: middle;
}
.rt-table tr:last-child td { border-bottom: none; }
.rt-table tr:hover td { background: #fafbfc; }

/* Left accent bar */
.rt-table td:first-child {
    position: relative;
    padding-left: 20px;
}
.rt-rank-accent {
    position: absolute;
    left: 0;
    top: 8px;
    bottom: 8px;
    width: 4px;
    border-radius: 2px;
}
.rt-rank-accent--gold  { background: #f59e0b; }
.rt-rank-accent--blue  { background: #3b82f6; }
.rt-rank-accent--green { background: #22c55e; }
.rt-rank-accent--red   { background: #ef4444; }
.rt-rank-accent--gray  { background: #d1d5db; }

/* Keyword cell */
.rt-kw-name {
    font-weight: 600;
    color: #1a1a1a;
    margin-bottom: 4px;
}
.rt-kw-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.rt-kw-meta-item {
    font-size: 11px;
    color: #9ca3af;
    display: flex;
    align-items: center;
    gap: 3px;
}
.rt-kw-meta-item strong {
    color: #6b7280;
    font-weight: 600;
}

/* Rank value */
.rt-rank {
    font-weight: 700;
    font-size: 16px;
    color: #1a1a1a;
}
.rt-rank--out {
    font-size: 12px;
    font-weight: 600;
    color: #ef4444;
}
.rt-rank--na {
    font-size: 12px;
    color: #d1d5db;
}
.rt-rank-unit {
    font-size: 11px;
    font-weight: 400;
    color: #9ca3af;
}
.rt-rank-change {
    font-size: 11px;
    font-weight: 600;
    margin-top: 2px;
}
.rt-rank-change--up   { color: #16a34a; }
.rt-rank-change--down { color: #ef4444; }
.rt-rank-change--same { color: #9ca3af; }

/* Daily cell (compact) */
.rt-daily {
    font-size: 13px;
    font-weight: 500;
    text-align: center;
    min-width: 48px;
}
.rt-daily--out { color: #ef4444; font-size: 11px; }
.rt-daily--na  { color: #d1d5db; }

/* PC差分インジケーター */
.rt-pc-diff {
    display: inline-block;
    font-size: 10px;
    color: #f59e0b;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 4px;
    padding: 1px 5px;
    margin-left: 4px;
    white-space: nowrap;
}

/* 上がりやすさ目安 — 5段階ドットインジケーター */
.rt-opp-dots { display: inline-flex; gap: 2px; align-items: center; margin-right: 3px; vertical-align: middle; }
.rt-opp-dot { width: 5px; height: 5px; border-radius: 50%; background: #d1d5db; }
.rt-opp-label { font-size: 10px; }

/* Actions column */
.rt-action-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    color: #568184;
    cursor: pointer;
    text-decoration: none;
    padding: 4px 0;
    border: none;
    background: none;
    white-space: nowrap;
}
.rt-action-link:hover { color: #476C6F; text-decoration: underline; }
.rt-action-link__icon { font-size: 14px; }

/* --- Empty state --- */
.rt-empty {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
}
.rt-empty__icon { font-size: 40px; margin-bottom: 12px; }
.rt-empty__text { font-size: 15px; color: #6b7280; }

/* --- Modal --- */
.rt-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4);
    z-index: 10000;
    display: none;
    justify-content: center;
    align-items: flex-start;
    padding: 60px 20px;
    overflow-y: auto;
}
.rt-modal-overlay.active { display: flex; }
.rt-modal {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 720px;
    max-height: calc(100vh - 120px);
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.15);
}
.rt-modal__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 1;
    border-radius: 14px 14px 0 0;
}
.rt-modal__title {
    font-size: 16px;
    font-weight: 700;
    color: #1a1a1a;
}
.rt-modal__close {
    width: 32px;
    height: 32px;
    border: none;
    background: #f3f4f6;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
}
.rt-modal__close:hover { background: #e5e7eb; }
.rt-modal__body {
    padding: 0;
}

/* --- Modal toolbar (device toggle + Google link) --- */
.rt-modal__toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 24px 0;
    gap: 12px;
}
.rt-device-toggle--modal {
    margin-bottom: 0;
}
.rt-serp-google-link {
    font-size: 12.5px;
    color: var(--mw-primary-blue, #568184);
    text-decoration: none;
    white-space: nowrap;
    opacity: 0.75;
    transition: opacity 0.2s;
}
.rt-serp-google-link:hover {
    opacity: 1;
    text-decoration: underline;
}

/* --- SERP note --- */
.rt-serp-note {
    padding: 8px 24px 12px;
    font-size: 12px;
    color: #8b8f96;
    line-height: 1.7;
    border-bottom: 1px solid #f0f0f0;
}

/* SERP table inside modal */
.rt-serp-table {
    width: 100%;
    border-collapse: collapse;
}
.rt-serp-table th {
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    padding: 10px 16px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}
.rt-serp-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 13px;
    vertical-align: top;
}
.rt-serp-table tr:last-child td { border-bottom: none; }
.rt-serp-rank {
    font-weight: 700;
    font-size: 15px;
    color: #568184;
    text-align: center;
    min-width: 36px;
}
.rt-serp-title {
    font-weight: 600;
    color: #1a1a1a;
    margin-bottom: 2px;
    line-height: 1.4;
}
.rt-serp-url {
    font-size: 12px;
    color: #568184;
    word-break: break-all;
}
.rt-serp-desc {
    font-size: 12px;
    color: #9ca3af;
    margin-top: 4px;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* --- Toast --- */
.rt-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #1a1a1a;
    color: #fff;
    padding: 14px 20px;
    border-radius: 10px;
    font-size: 14px;
    z-index: 10001;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    opacity: 0;
    transform: translateY(12px);
    transition: opacity 0.3s, transform 0.3s;
    max-width: 400px;
    line-height: 1.5;
}
.rt-toast.show { opacity: 1; transform: translateY(0); }
.rt-toast--error { background: #ef4444; }

/* --- Help lead --- */
.rt-help {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 20px;
    line-height: 1.6;
}

/* --- Loading overlay --- */
.rt-loading {
    text-align: center;
    padding: 40px;
    color: #9ca3af;
    font-size: 14px;
}

/* --- Progress overlay (bulk fetch) --- */
.rt-progress-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 10002;
    display: none;
    justify-content: center;
    align-items: center;
}
.rt-progress-overlay.active { display: flex; }
.rt-progress-box {
    background: #fff;
    border-radius: 16px;
    padding: 32px 40px;
    min-width: 340px;
    max-width: 480px;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
}
.rt-progress-title {
    font-size: 16px;
    font-weight: 700;
    color: #1a1a1a;
    margin-bottom: 16px;
}
.rt-progress-bar-wrap {
    width: 100%;
    height: 10px;
    background: #e5e7eb;
    border-radius: 5px;
    overflow: hidden;
    margin-bottom: 12px;
}
.rt-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #568184, #a3c9a9);
    border-radius: 5px;
    width: 0%;
    transition: width 0.3s ease;
}
.rt-progress-bar--indeterminate {
    width: 30%;
    animation: rt-progress-slide 1.5s infinite ease-in-out;
}
@keyframes rt-progress-slide {
    0%   { transform: translateX(-100%); }
    50%  { transform: translateX(200%); }
    100% { transform: translateX(-100%); }
}
.rt-progress-text {
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 4px;
}
.rt-progress-sub {
    font-size: 12px;
    color: #9ca3af;
}

/* Responsive */
@media (max-width: 768px) {
    .rt-header { flex-direction: column; align-items: flex-start; }
    .rt-table-wrap { overflow-x: auto; }
    .rt-add-form { flex-direction: column; }
    .rt-quota { flex-direction: column; gap: 8px; }
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">
    <!-- ローディングオーバーレイ -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>データを取得中...</p>
        </div>
    </div>

    <!-- Header -->
    <div class="rt-header">
        <div class="rt-header__title">
            <span>&#x1F4C8;</span> 計測キーワードランキング
        </div>
        <div class="rt-header__actions">
            <button class="rt-btn rt-btn--primary" id="fetchAllBtn" onclick="fetchAllKeywords()">
                <span class="rt-btn__icon">&#x21BB;</span>
                最新の情報を見る
            </button>
            <button class="rt-btn" id="exportCsvBtn" onclick="exportRankCsv()" style="display:none;">
                <span class="rt-btn__icon">&#x2193;</span>
                CSV ダウンロード
            </button>
        </div>
    </div>

    <!-- Help -->
    <div class="rt-help">
        Google で検索した時に、あなたのホームページが<strong>何番目に表示されるか</strong>をチェックしています。
        数字が小さいほど上位表示されています。「<strong>圏外</strong>」は100位以内に表示されなかったことを意味します。
    </div>

    <!-- Device toggle -->
    <div class="rt-device-toggle" id="deviceToggle">
        <button class="rt-device-btn active" data-device="mobile" onclick="switchDevice('mobile')">スマホ</button>
        <button class="rt-device-btn" data-device="desktop" onclick="switchDevice('desktop')">PC</button>
    </div>

    <!-- Summary cards -->
    <div class="rt-summary-cards" id="summaryCards">
        <div class="rt-summary-card rt-summary-card--gold">
            <span class="rt-summary-card__dot rt-summary-card__dot--gold"></span>
            <span class="rt-summary-card__label">1位〜3位</span>
            <span class="rt-summary-card__count" id="summary13">0<span class="rt-summary-card__unit">件</span></span>
        </div>
        <div class="rt-summary-card rt-summary-card--blue">
            <span class="rt-summary-card__dot rt-summary-card__dot--blue"></span>
            <span class="rt-summary-card__label">4位〜10位</span>
            <span class="rt-summary-card__count" id="summary410">0<span class="rt-summary-card__unit">件</span></span>
        </div>
        <div class="rt-summary-card rt-summary-card--green">
            <span class="rt-summary-card__dot rt-summary-card__dot--green"></span>
            <span class="rt-summary-card__label">11位〜20位</span>
            <span class="rt-summary-card__count" id="summary1120">0<span class="rt-summary-card__unit">件</span></span>
        </div>
        <div class="rt-summary-card rt-summary-card--red">
            <span class="rt-summary-card__dot rt-summary-card__dot--red"></span>
            <span class="rt-summary-card__label">圏外(20位以下)</span>
            <span class="rt-summary-card__count" id="summaryOut">0<span class="rt-summary-card__unit">件</span></span>
        </div>
    </div>

    <!-- Rankings table -->
    <div id="rankTableWrap">
        <div class="rt-empty" id="rankEmptyState" style="display:none;">
            <div class="rt-empty__icon">&#x1F50D;</div>
            <div class="rt-empty__text">キーワードが登録されていません</div>
            <div style="color:#9ca3af; font-size:13px; margin-top:8px;">下の「計測キーワード」セクションからキーワードを追加すると、検索順位が表示されます。</div>
        </div>
        <div class="rt-table-wrap" id="rankTableContainer" style="display:none;">
            <table class="rt-table" id="rankTable">
                <thead id="rankTableHead"></thead>
                <tbody id="rankTableBody"></tbody>
            </table>
        </div>
    </div>

</div>

<!-- Progress overlay (bulk fetch) -->
<div class="rt-progress-overlay" id="progressOverlay">
    <div class="rt-progress-box">
        <div class="rt-progress-title" id="progressTitle">最新の順位を取得中...</div>
        <div class="rt-progress-bar-wrap">
            <div class="rt-progress-bar rt-progress-bar--indeterminate" id="progressBar"></div>
        </div>
        <div class="rt-progress-text" id="progressText">キーワードの順位を取得しています...</div>
        <div class="rt-progress-sub" id="progressSub">しばらくお待ちください</div>
    </div>
</div>

<!-- SERP Top modal -->
<div class="rt-modal-overlay" id="serpModal">
    <div class="rt-modal">
        <div class="rt-modal__header">
            <div class="rt-modal__title" id="serpModalTitle">上位ランキング</div>
            <button class="rt-modal__close" onclick="closeSerpModal()">&times;</button>
        </div>
        <div class="rt-modal__toolbar">
            <div class="rt-device-toggle rt-device-toggle--modal" id="serpDeviceToggle">
                <button class="rt-device-btn active" data-device="mobile" onclick="switchSerpDevice('mobile')">スマホ</button>
                <button class="rt-device-btn" data-device="desktop" onclick="switchSerpDevice('desktop')">PC</button>
            </div>
            <a class="rt-serp-google-link" id="serpGoogleLink" href="#" target="_blank" rel="noopener">Google検索結果を見る →</a>
        </div>
        <div class="rt-serp-note">
            順位は、指定条件で取得した参考値です。実際のGoogle検索結果は、地域・端末・検索設定・時間帯などにより異なる場合があります。
        </div>
        <div class="rt-modal__body" id="serpModalBody">
            <div class="rt-loading">読み込み中...</div>
        </div>
    </div>
</div>

<?php get_footer(); ?>

<script>
(function() {
    'use strict';

    var wpNonce = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
    var currentDevice = 'mobile'; // 初期表示はスマホ

    // Data
    var rankData = [];
    var weekLabels = [];
    var weekKeys = [];
    var summaryData = {};

    var manualFetchLimit = { daily_used: 0, daily_limit: 5, daily_remaining: 5, is_admin: false };

    // SERP Modal state
    var serpModalDevice = 'mobile';
    var serpModalKeywordId = null;

    // =========================================================
    // Init
    // =========================================================
    document.addEventListener('DOMContentLoaded', function() {
        fetchRankings();
    });

    // =========================================================
    // Device toggle
    // =========================================================
    window.switchDevice = function(device) {
        currentDevice = device;
        document.querySelectorAll('.rt-device-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.device === device);
        });
        renderSummary();
        renderTable();
    };

    // =========================================================
    // Rankings — fetch
    // =========================================================
    function fetchRankings() {
        showLoading(true);

        fetch('/wp-json/gcrev/v1/rank-tracker/rankings', {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            showLoading(false);
            if (json.success && json.data) {
                rankData = json.data.keywords || [];
                weekLabels = json.data.week_labels || [];
                weekKeys = json.data.weeks || [];
                summaryData = json.data.summary || {};
                renderSummary();
                renderTable();
            }
        })
        .catch(function(err) {
            showLoading(false);
            console.error('[RankTracker]', err);
        });
    }

    // =========================================================
    // Summary — render
    // =========================================================
    function renderSummary() {
        var s = summaryData[currentDevice] || { rank_1_3: 0, rank_4_10: 0, rank_11_20: 0, rank_out: 0 };
        setText('summary13', s.rank_1_3);
        setText('summary410', s.rank_4_10);
        setText('summary1120', s.rank_11_20);
        setText('summaryOut', s.rank_out);
    }

    function setText(id, val) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = val + '<span class="rt-summary-card__unit">件</span>';
    }

    // =========================================================
    // Rankings table — render
    // =========================================================
    function renderTable() {
        var emptyState = document.getElementById('rankEmptyState');
        var container = document.getElementById('rankTableContainer');
        var thead = document.getElementById('rankTableHead');
        var tbody = document.getElementById('rankTableBody');
        var exportBtn = document.getElementById('exportCsvBtn');

        if (!rankData || rankData.length === 0) {
            emptyState.style.display = 'block';
            container.style.display = 'none';
            exportBtn.style.display = 'none';
            return;
        }

        emptyState.style.display = 'none';
        container.style.display = 'block';
        exportBtn.style.display = 'inline-flex';

        // Build header
        var hHtml = '<tr>';
        hHtml += '<th>キーワード</th>';
        hHtml += '<th>現在</th>';
        for (var d = 0; d < weekLabels.length; d++) {
            hHtml += '<th style="text-align:center;">' + weekLabels[d] + '</th>';
        }
        hHtml += '<th>操作</th>';
        hHtml += '</tr>';
        thead.innerHTML = hHtml;

        // Build body
        var html = '';
        for (var i = 0; i < rankData.length; i++) {
            var kw = rankData[i];
            var dev = kw[currentDevice];
            var otherDev = kw[currentDevice === 'mobile' ? 'desktop' : 'mobile'];
            var weekly = kw.weekly ? kw.weekly[currentDevice] : {};

            // Determine accent color based on current rank
            var accent = getAccentClass(dev);

            html += '<tr>';

            // Keyword + volume + 上がりやすさ目安
            html += '<td>';
            html += '<div class="rt-rank-accent ' + accent + '"></div>';
            html += '<div class="rt-kw-name">' + escHtml(kw.keyword) + '</div>';
            html += '<div class="rt-kw-meta">';
            html += '<span class="rt-kw-meta-item">Vol: <strong>' + (kw.search_volume != null ? numberFormat(kw.search_volume) : '-') + '</strong></span>';
            html += '<span class="rt-kw-meta-item">上がりやすさ: ' + formatOpportunityBadge(kw.opportunity_score) + '</span>';
            html += '</div>';
            html += '</td>';

            // Current rank
            html += '<td>';
            html += formatCurrentRank(dev);
            // PC diff indicator
            if (dev && otherDev && hasBigDiff(dev, otherDev)) {
                html += '<span class="rt-pc-diff">' + (currentDevice === 'mobile' ? 'PC' : 'SP') + 'と差あり</span>';
            }
            html += '</td>';

            // Weekly columns (6 weeks)
            if (weekKeys) {
                for (var d = 0; d < weekKeys.length; d++) {
                    var weekData = weekly ? weekly[weekKeys[d]] : null;
                    html += '<td class="rt-daily">' + formatDailyRank(weekData) + '</td>';
                }
            }

            // Actions
            html += '<td>';
            html += '<button class="rt-action-link" onclick="openSerpModal(' + kw.keyword_id + ')">';
            html += '<span class="rt-action-link__icon">&#x1F4CA;</span> 上位ランキングを見る';
            html += '</button>';
            html += '</td>';

            html += '</tr>';
        }

        tbody.innerHTML = html;
    }

    // =========================================================
    // Format helpers
    // =========================================================
    function getAccentClass(dev) {
        if (!dev || !dev.is_ranked) return 'rt-rank-accent--red';
        var r = dev.rank_group;
        if (r <= 3) return 'rt-rank-accent--gold';
        if (r <= 10) return 'rt-rank-accent--blue';
        if (r <= 20) return 'rt-rank-accent--green';
        return 'rt-rank-accent--red';
    }

    function formatCurrentRank(dev) {
        if (!dev) return '<span class="rt-rank--na">-</span>';
        if (!dev.is_ranked) return '<span class="rt-rank--out">圏外</span>';
        var html = '<span class="rt-rank">' + dev.rank_group + '<span class="rt-rank-unit">位</span></span>';
        if (dev.change != null) {
            if (dev.change === 0) {
                html += '<div class="rt-rank-change rt-rank-change--same">&#x2192;</div>';
            } else {
                html += '<div class="rt-rank-change ' + (dev.change > 0 ? 'rt-rank-change--up' : 'rt-rank-change--down') + '">';
                if (dev.change === 999) {
                    html += '&#x2191; NEW';
                } else if (dev.change === -999) {
                    html += '&#x2193; 圏外';
                } else if (dev.change > 0) {
                    html += '&#x2191; ' + dev.change;
                } else {
                    html += '&#x2193; ' + Math.abs(dev.change);
                }
                html += '</div>';
            }
        }
        return html;
    }

    function formatPrevRank(dev) {
        if (!dev || dev.change == null) return '<span class="rt-rank--na">-</span>';
        // Infer previous rank from current + change
        if (dev.change === 999) return '<span class="rt-rank--out">圏外</span>';
        if (dev.change === -999 && dev.is_ranked) return '<span class="rt-rank--na">-</span>';
        if (!dev.is_ranked && dev.change === -999) {
            // Current is out, change is -999, previous was ranked (unknown exact)
            return '<span class="rt-rank--na">-</span>';
        }
        if (dev.is_ranked && dev.change != null) {
            var prev = dev.rank_group + dev.change; // change = prev - current
            if (prev > 0 && prev <= 100) {
                return '<span class="rt-rank">' + prev + '<span class="rt-rank-unit">位</span></span>';
            }
        }
        return '<span class="rt-rank--na">-</span>';
    }

    function formatDailyRank(dayData) {
        if (!dayData) return '<span class="rt-daily--na">-</span>';
        if (!dayData.is_ranked) return '<span class="rt-daily--out">圏外</span>';
        return dayData.rank + '位';
    }

    // 上がりやすさ目安 — 5段階インジケーター
    // SERP上位サイトの傾向をもとにした独自の参考指標
    function formatOpportunityBadge(val) {
        if (val == null) return '<span style="color:#d1d5db;">-</span>';
        var v = parseInt(val, 10);
        var tier, label, color;
        if (v <= 19)      { tier = 1; label = 'かなり狙いやすい'; color = '#5B9A6B'; }
        else if (v <= 39) { tier = 2; label = 'やや狙いやすい';   color = '#7B9A4C'; }
        else if (v <= 59) { tier = 3; label = 'ふつう';           color = '#C4943C'; }
        else if (v <= 79) { tier = 4; label = 'やや難しい';       color = '#C4703C'; }
        else              { tier = 5; label = '難しい';           color = '#B5574B'; }
        var dots = '';
        for (var i = 1; i <= 5; i++) {
            dots += '<span class="rt-opp-dot" style="' + (i <= tier ? 'background:' + color : '') + '"></span>';
        }
        return '<span class="rt-opp-dots">' + dots + '</span>'
             + '<span class="rt-opp-label" style="color:' + color + '">' + label + '</span>';
    }

    function hasBigDiff(a, b) {
        if (!a || !b) return false;
        if (!a.is_ranked && !b.is_ranked) return false;
        if (a.is_ranked !== b.is_ranked) return true; // one ranked, other not
        if (a.is_ranked && b.is_ranked) {
            return Math.abs(a.rank_group - b.rank_group) >= 3;
        }
        return false;
    }

    function numberFormat(n) {
        if (n == null) return '-';
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // =========================================================
    // Fetch All keywords (bulk)
    // =========================================================
    window.fetchAllKeywords = function() {
        var btn = document.getElementById('fetchAllBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="rt-btn__icon">&#x22EF;</span> 最新情報を取得中...';

        // プログレスオーバーレイ表示
        var kwCount = myKeywords.filter(function(k) { return k.enabled; }).length || '?';
        showProgress(true, kwCount);

        fetch('/wp-json/gcrev/v1/rank-tracker/fetch-all', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            btn.disabled = false;
            btn.innerHTML = '<span class="rt-btn__icon">&#x21BB;</span> 最新の情報を見る';

            if (json.success && json.data) {
                var cnt = json.data.fetched || 0;
                showProgressComplete(cnt);
                setTimeout(function() {
                    showProgress(false);
                    showToast(cnt + '件のキーワードの最新順位を取得しました。');
                    fetchRankings();
                }, 1200);
            } else {
                showProgress(false);
                showToast(json.message || '取得に失敗しました。', 'error');
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = '<span class="rt-btn__icon">&#x21BB;</span> 最新の情報を見る';
            showProgress(false);
            console.error('[FetchAll]', err);
            showToast('通信エラーが発生しました。', 'error');
        });
    };

    function showProgress(show, kwCount) {
        var overlay = document.getElementById('progressOverlay');
        if (!overlay) return;
        if (show) {
            var titleEl = document.getElementById('progressTitle');
            var textEl = document.getElementById('progressText');
            var subEl = document.getElementById('progressSub');
            var barEl = document.getElementById('progressBar');
            if (titleEl) titleEl.textContent = '最新の順位を取得中...';
            if (textEl) textEl.textContent = kwCount + '件のキーワードの順位を取得しています...';
            if (subEl) subEl.textContent = '1キーワードあたり数秒かかります。しばらくお待ちください。';
            if (barEl) {
                barEl.style.width = '0%';
                barEl.classList.add('rt-progress-bar--indeterminate');
            }
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    }

    function showProgressComplete(count) {
        var titleEl = document.getElementById('progressTitle');
        var textEl = document.getElementById('progressText');
        var subEl = document.getElementById('progressSub');
        var barEl = document.getElementById('progressBar');
        if (titleEl) titleEl.textContent = '取得完了!';
        if (textEl) textEl.textContent = count + '件のキーワードの最新順位を取得しました。';
        if (subEl) subEl.textContent = '';
        if (barEl) {
            barEl.classList.remove('rt-progress-bar--indeterminate');
            barEl.style.width = '100%';
        }
    }

    // =========================================================
    // SERP Top modal
    // =========================================================

    /** キーワードIDからrankDataのオブジェクトを探す */
    function findKeywordById(keywordId) {
        for (var i = 0; i < rankData.length; i++) {
            if (rankData[i].keyword_id == keywordId) return rankData[i];
        }
        return null;
    }

    /** モーダルタイトルを更新 */
    function updateSerpModalTitle() {
        var title = document.getElementById('serpModalTitle');
        if (!title) return;
        var kw = findKeywordById(serpModalKeywordId);
        title.textContent = (kw ? '「' + kw.keyword + '」' : '') + ' 上位ランキング (' + (serpModalDevice === 'mobile' ? 'スマホ' : 'PC') + ')';
    }

    /** Google検索リンクを更新 */
    function updateSerpGoogleLink() {
        var link = document.getElementById('serpGoogleLink');
        if (!link) return;
        var kw = findKeywordById(serpModalKeywordId);
        if (kw) {
            link.href = 'https://www.google.co.jp/search?q=' + encodeURIComponent(kw.keyword);
            link.style.display = '';
        } else {
            link.style.display = 'none';
        }
    }

    /** SERPデータをAPIから取得して表示 */
    function fetchSerpData(keywordId, device) {
        var body = document.getElementById('serpModalBody');
        if (!body) return;

        body.innerHTML = '<div class="rt-loading">上位サイトを取得中...</div>';

        fetch('/wp-json/gcrev/v1/rank-tracker/serp-top?keyword_id=' + encodeURIComponent(keywordId) + '&device=' + encodeURIComponent(device), {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) {
            if (!res.ok) {
                return res.json().then(function(errJson) {
                    throw new Error(errJson.message || 'HTTP ' + res.status);
                });
            }
            return res.json();
        })
        .then(function(json) {
            if (json.success && json.data && json.data.items) {
                body.innerHTML = buildSerpTable(json.data.items);
            } else {
                body.innerHTML = '<div class="rt-loading" style="color:#ef4444;">' + escHtml(json.message || 'データの取得に失敗しました。') + '</div>';
            }
        })
        .catch(function(err) {
            console.error('[SerpTop]', err);
            body.innerHTML = '<div class="rt-loading" style="color:#ef4444;">' + escHtml(err.message || '通信エラーが発生しました。') + '</div>';
        });
    }

    /** モーダルを開く — 親画面のデバイス状態を引き継ぐ */
    window.openSerpModal = function(keywordId) {
        var modal = document.getElementById('serpModal');
        if (!modal) { console.error('[SerpTop] Modal not found'); return; }

        // 親画面のデバイス状態を引き継ぎ
        serpModalDevice = currentDevice;
        serpModalKeywordId = keywordId;

        // モーダル内トグルを同期
        document.querySelectorAll('#serpDeviceToggle .rt-device-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.device === serpModalDevice);
        });

        updateSerpModalTitle();
        updateSerpGoogleLink();
        modal.classList.add('active');
        fetchSerpData(keywordId, serpModalDevice);
    };

    /** モーダル内のデバイス切替 */
    window.switchSerpDevice = function(device) {
        if (device === serpModalDevice) return;
        serpModalDevice = device;

        // トグルの active 切替
        document.querySelectorAll('#serpDeviceToggle .rt-device-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.device === device);
        });

        updateSerpModalTitle();
        updateSerpGoogleLink();
        fetchSerpData(serpModalKeywordId, device);
    };

    window.closeSerpModal = function() {
        document.getElementById('serpModal').classList.remove('active');
    };

    // Close on overlay click
    document.addEventListener('click', function(e) {
        if (e.target.id === 'serpModal') {
            closeSerpModal();
        }
    });

    function buildSerpTable(items) {
        if (!items || items.length === 0) {
            return '<div class="rt-loading">上位サイトのデータがありません。</div>';
        }
        var html = '<table class="rt-serp-table"><thead><tr>';
        html += '<th style="text-align:center;">順位</th>';
        html += '<th>サイト情報</th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            html += '<tr>';
            html += '<td class="rt-serp-rank">' + item.rank + '</td>';
            html += '<td>';
            html += '<div class="rt-serp-title">' + escHtml(item.title) + '</div>';
            html += '<a class="rt-serp-url" href="' + escHtml(item.url) + '" target="_blank" rel="noopener">' + escHtml(item.url) + '</a>';
            if (item.description) {
                html += '<div class="rt-serp-desc">' + escHtml(item.description) + '</div>';
            }
            html += '</td>';
            html += '</tr>';
        }

        html += '</tbody></table>';
        return html;
    }

    // =========================================================
    // CSV export
    // =========================================================
    window.exportRankCsv = function() {
        if (!rankData || rankData.length === 0) return;

        var bom = '\uFEFF';
        var headerCols = ['キーワード', '検索ボリューム', 'SEO難易度', 'スマホ順位', 'PC順位', '最終取得日'];
        var header = headerCols.join(',') + '\n';
        var rows = '';

        for (var i = 0; i < rankData.length; i++) {
            var kw = rankData[i];
            var mRank = kw.mobile ? (kw.mobile.is_ranked ? kw.mobile.rank_group : '圏外') : '未取得';
            var dRank = kw.desktop ? (kw.desktop.is_ranked ? kw.desktop.rank_group : '圏外') : '未取得';
            var vol = kw.search_volume != null ? kw.search_volume : '';
            var diff = kw.keyword_difficulty != null ? kw.keyword_difficulty : '';

            rows += '"' + escapeCsv(kw.keyword) + '",';
            rows += vol + ',';
            rows += diff + ',';
            rows += mRank + ',';
            rows += dRank + ',';
            rows += (kw.fetched_at || '') + '\n';
        }

        var blob = new Blob([bom + header + rows], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'rank-tracker-' + new Date().toISOString().slice(0, 10) + '.csv';
        link.click();
    };

    function escapeCsv(str) {
        return (str || '').replace(/"/g, '""');
    }

    // =========================================================
    // Toast
    // =========================================================
    function showToast(message, type) {
        var existing = document.querySelector('.rt-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.className = 'rt-toast' + (type === 'error' ? ' rt-toast--error' : '');
        toast.textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(function() { toast.classList.add('show'); });

        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { if (toast.parentNode) toast.remove(); }, 300);
        }, 4000);
    }

    // =========================================================
    // Utilities
    // =========================================================
    function showLoading(show) {
        var overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            if (show) overlay.classList.add('active');
            else overlay.classList.remove('active');
        }
    }
})();
</script>
