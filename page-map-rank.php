<?php
/*
Template Name: マップ順位
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'マップ順位');
set_query_var('gcrev_page_subtitle', 'Googleマップやローカル検索での表示順位を確認できます。');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('マップ順位', '検索順位チェック'));

// Maps用ドメイン
$maps_domain = get_user_meta( $user_id, '_gcrev_maps_domain', true ) ?: '';

get_header();
?>

<style>
/* ============================================================
   page-map-rank v2 — Multi-keyword list layout
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

/* --- Help lead --- */
.rt-help {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 20px;
    line-height: 1.6;
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
.rt-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
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
    margin-bottom: 2px;
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

/* Meta cells */
.rt-meta-rating { font-size: 13px; color: #f59e0b; }
.rt-meta-reviews { font-size: 13px; color: #6b7280; }

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

/* --- Loading --- */
.rt-loading {
    text-align: center;
    padding: 40px;
    color: #9ca3af;
    font-size: 14px;
}

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
    padding: 24px;
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

/* ============================================================
   MEO-specific styles (conditions bar, modal content)
   ============================================================ */

/* Measurement conditions row */
.meo-conditions {
    display: flex; align-items: flex-start; gap: 24px; margin-bottom: 24px; flex-wrap: wrap;
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 20px;
}
.meo-condition-group { display: flex; flex-direction: column; gap: 4px; }
.meo-condition-label {
    font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;
}
.meo-condition-value { font-size: 13px; font-weight: 600; color: #1a1a1a; }

/* Device toggle */
.meo-device-toggle {
    display: inline-flex; background: #f2f4f7; border-radius: 8px; padding: 3px;
}
.meo-device-btn {
    padding: 6px 18px; border: none; border-radius: 6px; font-size: 13px; font-weight: 500;
    cursor: pointer; background: transparent; color: #667085; transition: all 0.2s;
}
.meo-device-btn.active {
    background: #fff; color: #1a1a1a; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

/* GBP domain badge */
.meo-gbp-badge {
    display: inline-block; font-size: 9px; font-weight: 700; color: #568184;
    background: #e8f4f5; border: 1px solid #c5dfe0; border-radius: 4px;
    padding: 1px 5px; letter-spacing: 0.5px;
}

/* Radius selector */
.meo-radius-group { display: flex; flex-direction: column; gap: 4px; }
.meo-radius-label {
    font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;
}
.meo-radius-select {
    font-size: 13px; color: #344054; border: 1px solid #d0d5dd; border-radius: 8px;
    padding: 5px 10px; background: #fff; cursor: pointer; max-width: 120px; font-weight: 500;
}

/* Store card (modal content) */
.meo-store-card {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 20px; margin-bottom: 20px;
}
.meo-store-card__title {
    font-size: 14px; font-weight: 700; color: #1a1a1a; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.meo-store-grid {
    display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; font-size: 13px;
}
.meo-store-label { color: #6b7280; font-weight: 500; white-space: nowrap; }
.meo-store-value { color: #1a1a1a; }
.meo-store-link {
    display: inline-flex; align-items: center; gap: 4px; font-size: 12px;
    color: #568184; text-decoration: none; margin-top: 12px;
}
.meo-store-link:hover { text-decoration: underline; }

/* Reviews card (modal content) */
.meo-reviews-card {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 20px; margin-bottom: 20px;
}
.meo-reviews-card__title {
    font-size: 14px; font-weight: 700; color: #1a1a1a; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.meo-reviews-summary {
    display: flex; align-items: center; gap: 16px; margin-bottom: 16px; flex-wrap: wrap;
}
.meo-reviews-big-rating { font-size: 28px; font-weight: 700; color: #1a1a1a; }
.meo-reviews-stars { font-size: 18px; color: #f59e0b; letter-spacing: 1px; }
.meo-reviews-count { font-size: 13px; color: #6b7280; }
.meo-rating-bars { max-width: 360px; }
.meo-rating-bar-row {
    display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 12px; color: #6b7280;
}
.meo-rating-bar-label { width: 24px; text-align: right; flex-shrink: 0; }
.meo-rating-bar-track {
    flex: 1; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;
}
.meo-rating-bar-fill {
    height: 100%; background: #f59e0b; border-radius: 4px; transition: width 0.3s;
}
.meo-rating-bar-count { width: 32px; text-align: right; flex-shrink: 0; font-size: 11px; color: #9ca3af; }

/* Competitor table (modal content) */
.meo-competitor-wrap {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;
    margin-bottom: 10px;
}
.meo-competitor-title {
    font-size: 14px; font-weight: 700; color: #1a1a1a; padding: 16px 16px 12px;
    display: flex; align-items: center; gap: 6px;
}
.meo-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.meo-competitor-table { width: 100%; border-collapse: collapse; }
.meo-competitor-table th {
    background: #f9fafb; font-size: 11px; font-weight: 600; color: #6b7280;
    padding: 10px 14px; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.meo-competitor-table td {
    padding: 12px 14px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #1a1a1a;
    vertical-align: middle;
}
.meo-competitor-table tr:last-child td { border-bottom: none; }
.meo-self-row td {
    background: #f0fdf4; font-weight: 600;
}
.meo-self-row td:first-child {
    border-left: 3px solid #568184;
}
.meo-self-badge {
    display: inline-block; font-size: 9px; color: #568184; background: #e8f4f5;
    border: 1px solid #c5dfe0; border-radius: 4px; padding: 1px 5px; margin-left: 4px;
    font-weight: 600;
}
.meo-stars-sm { font-size: 12px; color: #f59e0b; }

/* States */
.meo-loading { text-align: center; padding: 32px; color: #9ca3af; font-size: 13px; }
.meo-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }
.meo-empty__icon { font-size: 32px; margin-bottom: 8px; }
.meo-empty__text { font-size: 14px; color: #6b7280; }

/* Responsive */
@media (max-width: 768px) {
    .rt-header { flex-direction: column; align-items: flex-start; }
    .rt-table-wrap { overflow-x: auto; }
    .meo-conditions { flex-direction: column; gap: 12px; padding: 12px 14px; }
    .meo-store-grid { grid-template-columns: 1fr; gap: 4px; }
    .meo-store-label { font-weight: 600; }
    .meo-reviews-summary { flex-direction: column; align-items: flex-start; }
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">

    <!-- Header -->
    <div class="rt-header">
        <div class="rt-header__title">
            <span>&#x1F4CD;</span> マップ順位
        </div>
        <div class="rt-header__actions">
            <button class="rt-btn rt-btn--primary" id="meoFetchAllBtn" type="button">
                <span class="rt-btn__icon">&#x21BB;</span>
                最新の情報を見る
            </button>
        </div>
    </div>

    <!-- Help -->
    <div class="rt-help">
        Googleマップやローカル検索で、あなたのお店が<strong>何番目に表示されるか</strong>、
        口コミの状況、近くの競合との比較をまとめています。
    </div>

    <!-- 計測条件エリア -->
    <div class="meo-conditions" id="meoConditions">
        <!-- デバイス -->
        <div class="meo-condition-group">
            <span class="meo-condition-label">表示デバイス</span>
            <div class="meo-device-toggle" id="meoDeviceToggle">
                <button class="meo-device-btn active" data-device="mobile">スマホ</button>
                <button class="meo-device-btn" data-device="desktop">PC</button>
            </div>
        </div>
        <!-- 基準地点 -->
        <div class="meo-condition-group" id="meoRegionGroup">
            <span class="meo-condition-label">基準地点</span>
            <span class="meo-condition-value" id="meoRegion">読み込み中...</span>
        </div>
        <!-- 半径（座標モード時のみ表示） -->
        <div class="meo-radius-group" id="meoRadiusGroup" style="display:none;">
            <span class="meo-radius-label">半径</span>
            <select class="meo-radius-select" id="meoRadiusSelect"></select>
        </div>
<?php if ( $maps_domain !== '' ) : ?>
        <!-- 対象ドメイン（GBPドメイン） -->
        <div class="meo-condition-group">
            <span class="meo-condition-label">対象ドメイン（GBPドメイン）</span>
            <span class="meo-condition-value"><?php echo esc_html( $maps_domain ); ?></span>
        </div>
<?php endif; ?>
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
    <div id="meoTableWrap">
        <div class="meo-loading" id="meoLoading">データを取得中...</div>
        <div class="meo-empty" id="meoEmpty" style="display:none;">
            <div class="meo-empty__icon">&#x1F4CD;</div>
            <div class="meo-empty__text">MEOデータがまだありません</div>
            <div style="color:#9ca3af; font-size:12px; margin-top:6px;">
                <a href="<?php echo esc_url( home_url( '/rank-tracker/' ) ); ?>" style="color:#568184;">自然検索順位</a>ページでキーワードを登録すると、Googleマップでの順位も確認できます。
            </div>
        </div>
        <div class="rt-table-wrap" id="meoTableContainer" style="display:none;">
            <div class="rt-table-scroll">
                <table class="rt-table" id="meoTable">
                    <thead id="meoTableHead"></thead>
                    <tbody id="meoTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /.content-area -->

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

<!-- Detail modal (store + reviews + competitors) -->
<div class="rt-modal-overlay" id="meoDetailModal">
    <div class="rt-modal">
        <div class="rt-modal__header">
            <div class="rt-modal__title" id="meoDetailModalTitle">詳細情報</div>
            <button class="rt-modal__close" id="meoDetailCloseBtn">&times;</button>
        </div>
        <div class="rt-modal__body" id="meoDetailModalBody">
            <div class="rt-loading">読み込み中...</div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="rt-toast" id="meoToast"></div>

<?php get_footer(); ?>

<script>
/* ============================================================
   MEO Section v2 — Multi-keyword list
   ============================================================ */
(function() {
    'use strict';

    var restBase = '<?php echo esc_url( rest_url( 'gcrev/v1/' ) ); ?>';
    var nonce    = '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>';

    // State
    var currentDevice = 'mobile';
    var meoData       = null; // Full API response
    var keywordsList  = [];   // keyword objects from API
    var dayLabels     = [];
    var dayKeys       = [];
    var summaryData   = {};

    // DOM refs
    var loadingEl     = document.getElementById('meoLoading');
    var emptyEl       = document.getElementById('meoEmpty');
    var tableContainer= document.getElementById('meoTableContainer');
    var thead         = document.getElementById('meoTableHead');
    var tbody         = document.getElementById('meoTableBody');
    var regionEl      = document.getElementById('meoRegion');
    var radiusGroup   = document.getElementById('meoRadiusGroup');
    var radiusSelect  = document.getElementById('meoRadiusSelect');

    // =========================================================
    // Init
    // =========================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Device toggle
        var deviceToggle = document.getElementById('meoDeviceToggle');
        if (deviceToggle) {
            deviceToggle.addEventListener('click', function(e) {
                var btn = e.target.closest('.meo-device-btn');
                if (!btn || btn.classList.contains('active')) return;
                deviceToggle.querySelectorAll('.meo-device-btn').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                currentDevice = btn.dataset.device;
                renderSummary();
                renderTable();
            });
        }

        // Fetch all button
        var fetchAllBtn = document.getElementById('meoFetchAllBtn');
        if (fetchAllBtn) {
            fetchAllBtn.addEventListener('click', fetchAllKeywords);
        }

        // Detail modal close
        var closeBtn = document.getElementById('meoDetailCloseBtn');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeDetailModal);
        }
        document.addEventListener('click', function(e) {
            if (e.target.id === 'meoDetailModal') closeDetailModal();
        });

        // Radius change
        if (radiusSelect) {
            radiusSelect.addEventListener('change', function() {
                // Re-fetch with new radius — reload all data
                fetchMeoData();
            });
        }

        // Initial load
        fetchMeoData();
    });

    // =========================================================
    // Fetch data (single API call)
    // =========================================================
    function fetchMeoData() {
        showState('loading');

        fetch(restBase + 'meo/history', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(json) {
            if (json.success && json.data) {
                meoData      = json.data;
                keywordsList = json.data.keywords || [];
                dayLabels    = json.data.day_labels || [];
                dayKeys      = json.data.days || [];
                summaryData  = json.data.summary || {};

                // Location info
                renderLocation(json.data.location);

                if (keywordsList.length === 0) {
                    showState('empty');
                    return;
                }

                showState('table');
                renderSummary();
                renderTable();
            } else {
                showState('empty');
            }
        })
        .catch(function(err) {
            console.error('[GCREV][MEO]', err);
            showState('empty');
        });
    }

    // =========================================================
    // Location rendering
    // =========================================================
    function renderLocation(loc) {
        if (!loc || !regionEl) return;

        if (loc.mode === 'coordinate') {
            if (loc.source === 'city_center') {
                regionEl.innerHTML = escHtml(loc.address || '')
                    + ' <span style="font-size:11px;color:#999;font-weight:400;">(自動設定)</span>';
            } else {
                regionEl.textContent = loc.address || (loc.lat + ', ' + loc.lng);
            }
            // Show radius selector if we have coordinate mode
            if (radiusGroup) radiusGroup.style.display = '';
        } else {
            regionEl.textContent = loc.address || '未設定';
            if (radiusGroup) radiusGroup.style.display = 'none';
        }
    }

    // =========================================================
    // Summary cards
    // =========================================================
    function renderSummary() {
        var s = summaryData[currentDevice] || { rank_1_3: 0, rank_4_10: 0, rank_11_20: 0, rank_out: 0 };
        setSummaryText('summary13', s.rank_1_3);
        setSummaryText('summary410', s.rank_4_10);
        setSummaryText('summary1120', s.rank_11_20);
        setSummaryText('summaryOut', s.rank_out);
    }

    function setSummaryText(id, val) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = val + '<span class="rt-summary-card__unit">件</span>';
    }

    // =========================================================
    // Keywords table
    // =========================================================
    function renderTable() {
        if (!keywordsList || keywordsList.length === 0) {
            showState('empty');
            return;
        }

        // Build header
        var hHtml = '<tr>';
        hHtml += '<th>キーワード</th>';
        hHtml += '<th>マップ順位</th>';
        hHtml += '<th>地域順位</th>';
        hHtml += '<th>評価</th>';
        hHtml += '<th>口コミ</th>';
        for (var d = 0; d < dayLabels.length; d++) {
            hHtml += '<th style="text-align:center;">' + dayLabels[d] + '</th>';
        }
        hHtml += '<th>操作</th>';
        hHtml += '</tr>';
        thead.innerHTML = hHtml;

        // Build body
        var html = '';
        for (var i = 0; i < keywordsList.length; i++) {
            var kw = keywordsList[i];
            var cur = kw.current ? kw.current[currentDevice] : null;
            var daily = kw.daily ? kw.daily[currentDevice] : {};

            // Determine accent color based on maps_rank
            var accent = getAccentClass(cur);

            html += '<tr>';

            // Keyword name
            html += '<td>';
            html += '<div class="rt-rank-accent ' + accent + '"></div>';
            html += '<div class="rt-kw-name">' + escHtml(kw.keyword) + '</div>';
            html += '</td>';

            // Maps rank (current)
            html += '<td>';
            html += formatCurrentRank(cur, 'maps_rank');
            html += '</td>';

            // Finder rank (current)
            html += '<td>';
            html += formatSimpleRank(cur, 'finder_rank');
            html += '</td>';

            // Rating
            html += '<td>';
            if (cur && cur.rating != null) {
                html += '<span class="rt-meta-rating">' + meoStarsMini(cur.rating) + '</span> '
                      + '<span style="font-size:13px;">' + parseFloat(cur.rating).toFixed(1) + '</span>';
            } else {
                html += '<span class="rt-rank--na">-</span>';
            }
            html += '</td>';

            // Reviews count
            html += '<td>';
            if (cur && cur.reviews != null) {
                html += '<span class="rt-meta-reviews">' + cur.reviews + '件</span>';
            } else {
                html += '<span class="rt-rank--na">-</span>';
            }
            html += '</td>';

            // Daily columns (7 days) — maps_rank
            for (var d = 0; d < dayKeys.length; d++) {
                var dayData = daily ? daily[dayKeys[d]] : null;
                html += '<td class="rt-daily">' + formatDailyMapsRank(dayData) + '</td>';
            }

            // Actions
            html += '<td>';
            html += '<button class="rt-action-link" data-keyword-id="' + kw.keyword_id + '" onclick="meoOpenDetail(' + kw.keyword_id + ')">';
            html += '<span class="rt-action-link__icon">&#x1F50D;</span> 詳細を見る';
            html += '</button>';
            html += '</td>';

            html += '</tr>';
        }

        tbody.innerHTML = html;
    }

    // =========================================================
    // Format helpers
    // =========================================================
    function getAccentClass(cur) {
        if (!cur || !cur.is_ranked || cur.maps_rank == null) return 'rt-rank-accent--red';
        var r = cur.maps_rank;
        if (r <= 3) return 'rt-rank-accent--gold';
        if (r <= 10) return 'rt-rank-accent--blue';
        if (r <= 20) return 'rt-rank-accent--green';
        return 'rt-rank-accent--red';
    }

    function formatCurrentRank(cur, key) {
        if (!cur) return '<span class="rt-rank--na">-</span>';
        var rank = cur[key];
        if (rank == null) return '<span class="rt-rank--out">圏外</span>';

        var html = '<span class="rt-rank">' + rank + '<span class="rt-rank-unit">位</span></span>';

        // Change indicator (only for maps_rank)
        if (key === 'maps_rank' && cur.change != null) {
            if (cur.change === 0) {
                html += '<div class="rt-rank-change rt-rank-change--same">&#x2192;</div>';
            } else {
                html += '<div class="rt-rank-change ' + (cur.change > 0 ? 'rt-rank-change--up' : 'rt-rank-change--down') + '">';
                if (cur.change === 999) {
                    html += '&#x2191; NEW';
                } else if (cur.change === -999) {
                    html += '&#x2193; 圏外';
                } else if (cur.change > 0) {
                    html += '&#x2191; ' + cur.change;
                } else {
                    html += '&#x2193; ' + Math.abs(cur.change);
                }
                html += '</div>';
            }
        }
        return html;
    }

    function formatSimpleRank(cur, key) {
        if (!cur) return '<span class="rt-rank--na">-</span>';
        var rank = cur[key];
        if (rank == null) return '<span class="rt-rank--out">圏外</span>';
        return '<span class="rt-rank">' + rank + '<span class="rt-rank-unit">位</span></span>';
    }

    function formatDailyMapsRank(dayData) {
        if (!dayData) return '<span class="rt-daily--na">-</span>';
        if (dayData.maps_rank == null) return '<span class="rt-daily--out">圏外</span>';
        return dayData.maps_rank + '位';
    }

    function meoStarsMini(val) {
        var r = Math.round(parseFloat(val) || 0);
        var s = '';
        for (var i = 1; i <= 5; i++) s += (i <= r) ? '\u2605' : '\u2606';
        return s;
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // =========================================================
    // State management
    // =========================================================
    function showState(state) {
        loadingEl.style.display = state === 'loading' ? '' : 'none';
        emptyEl.style.display   = state === 'empty' ? '' : 'none';
        tableContainer.style.display = state === 'table' ? '' : 'none';
    }

    // =========================================================
    // Detail modal
    // =========================================================
    window.meoOpenDetail = function(keywordId) {
        var modal = document.getElementById('meoDetailModal');
        var titleEl = document.getElementById('meoDetailModalTitle');
        var bodyEl = document.getElementById('meoDetailModalBody');
        if (!modal) return;

        // Find keyword name
        var kwName = '';
        for (var i = 0; i < keywordsList.length; i++) {
            if (keywordsList[i].keyword_id === keywordId) {
                kwName = keywordsList[i].keyword;
                break;
            }
        }
        titleEl.textContent = '「' + kwName + '」の詳細情報';
        bodyEl.innerHTML = '<div class="rt-loading">データを取得中...</div>';
        modal.classList.add('active');

        // Fetch detail data from meo/rankings
        var url = restBase + 'meo/rankings?device=' + encodeURIComponent(currentDevice)
                + '&keyword_id=' + encodeURIComponent(keywordId);

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (data && data.success !== false) {
                renderDetailModal(data, bodyEl);
            } else {
                bodyEl.innerHTML = '<div class="rt-loading" style="color:#ef4444;">' + escHtml(data.message || 'データの取得に失敗しました。') + '</div>';
            }
        })
        .catch(function(err) {
            console.error('[GCREV][MEO Detail]', err);
            bodyEl.innerHTML = '<div class="rt-loading" style="color:#ef4444;">通信エラーが発生しました。</div>';
        });
    };

    function renderDetailModal(data, bodyEl) {
        var html = '';

        // Store info
        if (data.maps && data.maps.store) {
            html += renderStoreHtml(data.maps.store);
            html += renderReviewsHtml(data.maps.store);
        }

        // Competitors
        if (data.maps && data.maps.competitors && data.maps.competitors.length > 0) {
            html += renderCompetitorsHtml(data.maps.competitors);
        }

        if (html === '') {
            html = '<div class="rt-loading" style="color:#9ca3af;">詳細データがありません。「最新の情報を見る」でデータを取得してください。</div>';
        }

        bodyEl.innerHTML = html;
    }

    function renderStoreHtml(store) {
        var rows = [];
        if (store.title)    rows.push(['店舗名', escHtml(store.title)]);
        if (store.category) rows.push(['カテゴリ', escHtml(store.category)]);
        if (store.address)  rows.push(['住所', escHtml(store.address)]);
        if (store.phone)    rows.push(['電話番号', escHtml(store.phone)]);
        if (store.work_hours) rows.push(['営業時間', escHtml(store.work_hours)]);

        if (rows.length === 0) return '';

        var html = '<div class="meo-store-card">'
                 + '<div class="meo-store-card__title">\uD83C\uDFEA 店舗情報</div>'
                 + '<div class="meo-store-grid">';
        rows.forEach(function(r) {
            html += '<div class="meo-store-label">' + r[0] + '</div>'
                  + '<div class="meo-store-value">' + r[1] + '</div>';
        });
        html += '</div>';

        if (store.maps_url) {
            html += '<a href="' + escHtml(store.maps_url) + '" target="_blank" rel="noopener" class="meo-store-link">'
                  + 'Googleマップで見る \u2192</a>';
        }

        html += '</div>';
        return html;
    }

    function renderReviewsHtml(store) {
        if (store.rating == null) return '';

        var rating = parseFloat(store.rating) || 0;
        var total = store.reviews_count || 0;
        var dist = store.rating_distribution || {};

        var stars = '';
        for (var i = 1; i <= 5; i++) {
            stars += (i <= Math.round(rating)) ? '\u2605' : '\u2606';
        }

        var barsHtml = '';
        for (var s = 5; s >= 1; s--) {
            var cnt = parseInt(dist[s] || dist[String(s)] || 0, 10);
            var pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
            barsHtml += '<div class="meo-rating-bar-row">'
                      + '<div class="meo-rating-bar-label">' + s + '\u2605</div>'
                      + '<div class="meo-rating-bar-track"><div class="meo-rating-bar-fill" style="width:' + pct + '%"></div></div>'
                      + '<div class="meo-rating-bar-count">' + cnt + '件</div>'
                      + '</div>';
        }

        return '<div class="meo-reviews-card">'
             + '<div class="meo-reviews-card__title">\uD83D\uDCAC 口コミの状況</div>'
             + '<div class="meo-reviews-summary">'
             + '<span class="meo-reviews-big-rating">' + rating.toFixed(1) + '</span>'
             + '<span class="meo-reviews-stars">' + stars + '</span>'
             + '<span class="meo-reviews-count">' + total + '件の口コミ</span>'
             + '</div>'
             + '<div class="meo-rating-bars">' + barsHtml + '</div>'
             + '</div>';
    }

    function renderCompetitorsHtml(competitors) {
        var html = '<div class="meo-competitor-wrap">'
                 + '<div class="meo-competitor-title">\uD83C\uDFC6 近くの競合との比較</div>'
                 + '<div class="meo-table-scroll">'
                 + '<table class="meo-competitor-table">'
                 + '<thead><tr>'
                 + '<th>店舗名</th><th>マップ順位</th><th>評価</th><th>口コミ数</th>'
                 + '</tr></thead><tbody>';

        competitors.forEach(function(c) {
            var rowCls = c.is_self ? ' class="meo-self-row"' : '';
            var name = escHtml(c.title || '');
            if (c.is_self) name += '<span class="meo-self-badge">自社</span>';

            var rank = c.rank ? c.rank + '位' : '圏外';
            var rating = c.rating != null
                ? '<span class="meo-stars-sm">' + meoStarsMini(c.rating) + '</span> ' + parseFloat(c.rating).toFixed(1)
                : '-';
            var reviews = c.reviews_count != null ? c.reviews_count + '件' : '-';

            html += '<tr' + rowCls + '>'
                  + '<td>' + name + '</td>'
                  + '<td>' + rank + '</td>'
                  + '<td>' + rating + '</td>'
                  + '<td>' + reviews + '</td>'
                  + '</tr>';
        });

        html += '</tbody></table></div></div>';
        return html;
    }

    function closeDetailModal() {
        var modal = document.getElementById('meoDetailModal');
        if (modal) modal.classList.remove('active');
    }

    // =========================================================
    // Fetch all keywords (bulk)
    // =========================================================
    function fetchAllKeywords() {
        if (!keywordsList || keywordsList.length === 0) {
            // If no data yet, try a single fetch first
            fetchMeoData();
            return;
        }

        var btn = document.getElementById('meoFetchAllBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="rt-btn__icon">&#x22EF;</span> 最新情報を取得中...';

        var kwCount = keywordsList.length;
        showProgress(true, kwCount);

        var completed = 0;
        var errors = 0;

        // Sequential fetch for each keyword
        function fetchNext(index) {
            if (index >= keywordsList.length) {
                // All done
                showProgressComplete(completed);
                setTimeout(function() {
                    showProgress(false);
                    btn.disabled = false;
                    btn.innerHTML = '<span class="rt-btn__icon">&#x21BB;</span> 最新の情報を見る';
                    showToast(completed + '件のキーワードの最新マップ順位を取得しました。');
                    fetchMeoData(); // Reload table
                }, 1200);
                return;
            }

            var kw = keywordsList[index];
            updateProgressText(index + 1, kwCount, kw.keyword);

            var url = restBase + 'meo/rankings?device=' + encodeURIComponent(currentDevice)
                    + '&keyword_id=' + encodeURIComponent(kw.keyword_id)
                    + '&force=1';

            fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce }
            })
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (data && data.success !== false) {
                    completed++;
                } else {
                    errors++;
                }
                updateProgressBar(index + 1, kwCount);
                fetchNext(index + 1);
            })
            .catch(function() {
                errors++;
                updateProgressBar(index + 1, kwCount);
                fetchNext(index + 1);
            });
        }

        fetchNext(0);
    }

    // =========================================================
    // Progress overlay
    // =========================================================
    function showProgress(show, kwCount) {
        var overlay = document.getElementById('progressOverlay');
        if (!overlay) return;
        if (show) {
            var titleEl = document.getElementById('progressTitle');
            var textEl = document.getElementById('progressText');
            var subEl = document.getElementById('progressSub');
            var barEl = document.getElementById('progressBar');
            if (titleEl) titleEl.textContent = '最新のマップ順位を取得中...';
            if (textEl) textEl.textContent = kwCount + '件のキーワードのマップ順位を取得します...';
            if (subEl) subEl.textContent = '1キーワードあたり数秒かかります。しばらくお待ちください。';
            if (barEl) {
                barEl.style.width = '0%';
                barEl.classList.remove('rt-progress-bar--indeterminate');
            }
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    }

    function updateProgressBar(current, total) {
        var barEl = document.getElementById('progressBar');
        if (barEl) {
            var pct = Math.round((current / total) * 100);
            barEl.style.width = pct + '%';
        }
    }

    function updateProgressText(current, total, keyword) {
        var textEl = document.getElementById('progressText');
        if (textEl) textEl.textContent = '「' + keyword + '」を取得中... (' + current + '/' + total + ')';
    }

    function showProgressComplete(count) {
        var titleEl = document.getElementById('progressTitle');
        var textEl = document.getElementById('progressText');
        var subEl = document.getElementById('progressSub');
        var barEl = document.getElementById('progressBar');
        if (titleEl) titleEl.textContent = '取得完了!';
        if (textEl) textEl.textContent = count + '件のキーワードの最新マップ順位を取得しました。';
        if (subEl) subEl.textContent = '';
        if (barEl) barEl.style.width = '100%';
    }

    // =========================================================
    // Toast
    // =========================================================
    function showToast(msg, type) {
        var toast = document.getElementById('meoToast');
        if (!toast) return;
        toast.textContent = msg;
        toast.className = 'rt-toast' + (type === 'error' ? ' rt-toast--error' : '');
        setTimeout(function() { toast.classList.add('show'); }, 10);
        setTimeout(function() { toast.classList.remove('show'); }, 4000);
    }

})();
</script>
