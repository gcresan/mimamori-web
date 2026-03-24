<?php
/*
Template Name: ページ分析（Page Analysis）
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// ページタイトル設定
set_query_var( 'gcrev_page_title', '現状のページ診断' );
set_query_var( 'gcrev_page_subtitle', '主要ページのページ画像・行動データ・AI改善案をまとめて管理できます。' );

// パンくず設定（親カテゴリとして独立 — 全体ダッシュボード直下に配置）
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '現状のページ診断' ) );

get_header();
?>

<style>
/* page-page-analysis — Page-specific styles */
.pa-action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.pa-add-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    background: #568184;
    color: #FAF9F6;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.25s ease;
}
.pa-add-btn:hover { background: #476C6F; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(47,58,74,0.12); }
.pa-count { color: #666; font-size: 14px; }

/* サムネイル */
.pa-thumb {
    width: 60px;
    height: 40px;
    object-fit: cover;
    object-position: top;
    border-radius: 4px;
    border: 1px solid #e0e0e0;
}
.pa-thumb-placeholder {
    width: 60px;
    height: 40px;
    border-radius: 4px;
    border: 1px dashed #ccc;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #aaa;
    font-size: 11px;
    background: #fafafa;
}

/* バッジ */
.pa-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 500;
}
.pa-badge--done { background: #e6f7ed; color: #1a9b4a; }
.pa-badge--warn { background: #fff8e1; color: #e6a817; }
.pa-badge--pending { background: #f0f0f0; color: #999; }
.pa-badge--type { background: #e8f0fe; color: #1967d2; }
/* ステータスカード（詳細画面上部） */
.pa-status-bar {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.pa-status-chip {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    color: #495057;
}
.pa-status-chip--done { background: #e6f7ed; border-color: #c3e6cb; color: #1a7e3e; }
.pa-status-chip--warn { background: #fff8e1; border-color: #ffe082; color: #c68a00; }
.pa-status-chip--pending { background: #f0f0f0; border-color: #ddd; color: #888; }
/* 一覧テーブル行ホバー強化 */
#paTable tbody tr { cursor: pointer; transition: background 0.15s, box-shadow 0.15s; }
#paTable tbody tr:hover { background: #f0f7f7; box-shadow: 0 1px 3px rgba(86,129,132,0.08); }
/* 最終分析日の相対表示 */
.pa-date-relative { font-size: 12px; color: #568184; font-weight: 500; }
.pa-date-absolute { font-size: 11px; color: #aaa; margin-top: 1px; }

/* 操作ボタン */
.pa-btn-sm {
    padding: 4px 10px;
    font-size: 12px;
    border-radius: 4px;
    border: 1px solid #ddd;
    background: #fff;
    cursor: pointer;
    transition: background 0.2s;
}
.pa-btn-sm:hover { background: #f5f5f5; }
.pa-btn-sm--danger { color: #e74c3c; border-color: #f5c6cb; }
.pa-btn-sm--danger:hover { background: #fef0f0; }

/* モーダル */
.pa-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}
.pa-modal-overlay.is-open { display: flex; }
.pa-modal {
    background: #fff;
    border-radius: 12px;
    padding: 28px;
    width: 480px;
    max-width: 90vw;
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
}
.pa-modal h3 {
    margin: 0 0 20px;
    font-size: 18px;
    font-weight: 600;
}
.pa-form-group {
    margin-bottom: 16px;
}
.pa-form-group label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 6px;
    color: #333;
}
.pa-form-group input,
.pa-form-group select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
}
.pa-form-group input:focus,
.pa-form-group select:focus {
    outline: none;
    border-color: #2d9cdb;
    box-shadow: 0 0 0 2px rgba(45,156,219,0.15);
}
.pa-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 20px;
}
.pa-modal-btn {
    padding: 8px 20px;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    border: 1px solid #ddd;
    background: #fff;
    transition: background 0.2s;
}
.pa-modal-btn:hover { background: #f5f5f5; }
.pa-modal-btn--primary {
    background: #2d9cdb;
    color: #fff;
    border-color: #2d9cdb;
}
.pa-modal-btn--primary:hover { background: #2188c4; }
.pa-modal-btn:disabled { opacity: 0.6; cursor: not-allowed; }

/* 詳細パネル（フルスクリーン2カラム） */
.pa-detail-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: #fff;
    z-index: 10000;
    flex-direction: column;
    overflow: hidden;
}
.pa-detail-overlay.is-open { display: flex; }
.pa-detail-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.3);
    z-index: 1000;
}
.pa-detail-backdrop.is-open { display: block; }
.pa-detail-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 24px;
    border-bottom: 1px solid #eee;
    background: #fafafa;
    flex-shrink: 0;
}
.pa-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 14px;
    font-size: 13px;
    color: #568184;
    border: 1px solid #568184;
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    transition: background 0.2s;
    white-space: nowrap;
    flex-shrink: 0;
}
.pa-back-btn:hover { background: #f0f5f5; }
.pa-detail-header h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}
.pa-detail-header-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}
/* 2カラムボディ */
.pa-detail-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    flex: 1;
    overflow: hidden;
}
.pa-detail-left {
    overflow-y: auto;
    padding: 24px;
    border-right: 1px solid #eee;
}
.pa-detail-right {
    overflow-y: auto;
    padding: 24px;
}
/* 画像デバイス切替タブ */
.pa-img-tabs {
    display: flex;
    gap: 0;
    margin-bottom: 16px;
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
}
.pa-img-tab {
    flex: 1;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    border: none;
    background: #f5f5f5;
    color: #666;
    transition: all 0.2s;
}
.pa-img-tab.is-active {
    background: #568184;
    color: #fff;
}
.pa-img-tab:hover:not(.is-active) { background: #eee; }
.pa-img-pane { display: none; }
.pa-img-pane.is-active { display: block; }
.pa-img-pane[data-img-pane="mobile"] .pa-capture-img { width: 65%; display: block; margin-left: auto; margin-right: auto; }

/* セクション見出し */
.pa-section {
    margin-bottom: 28px;
}
.pa-section-title {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid #568184;
    display: flex;
    align-items: center;
    gap: 6px;
}
.pa-section-title .pa-section-icon { font-size: 16px; }
@media (max-width: 768px) {
    .pa-detail-body { grid-template-columns: 1fr; }
    .pa-detail-left { border-right: none; border-bottom: 1px solid #eee; }
}

/* 概要セクション */
.pa-info-row {
    display: flex;
    padding: 10px 0;
    border-bottom: 1px solid #f5f5f5;
    font-size: 14px;
}
.pa-info-label {
    width: 120px;
    color: #888;
    flex-shrink: 0;
}
.pa-info-value {
    flex: 1;
    color: #333;
}
.pa-info-value a { color: #2d9cdb; text-decoration: none; }
.pa-info-value a:hover { text-decoration: underline; }

/* キャプチャセクション */
.pa-capture-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.pa-capture-box {
    border: 1px solid #eee;
    border-radius: 8px;
    padding: 16px;
}
.pa-capture-box h4 {
    margin: 0 0 12px;
    font-size: 14px;
    font-weight: 500;
    color: #555;
}
.pa-capture-img {
    width: 100%;
    border-radius: 6px;
    border: 1px solid #e0e0e0;
    margin-bottom: 12px;
}
/* 原寸プレビューモーダル */
.pa-lightbox {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 100000;
    background: rgba(0,0,0,0.8);
    align-items: flex-start;
    justify-content: center;
    overflow-y: auto;
    padding: 40px 20px;
}
.pa-lightbox.is-open { display: flex; }
.pa-lightbox img {
    max-width: 90%;
    max-height: none;
    border-radius: 8px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.5);
}
.pa-lightbox-close {
    position: fixed;
    top: 16px;
    right: 24px;
    color: #fff;
    font-size: 32px;
    cursor: pointer;
    z-index: 100001;
    background: none;
    border: none;
    line-height: 1;
}
.pa-capture-empty {
    width: 100%;
    height: 200px;
    border: 2px dashed #ddd;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #aaa;
    font-size: 13px;
    margin-bottom: 12px;
    background: #fafafa;
}
.pa-upload-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    font-size: 12px;
    border: 1px solid #2d9cdb;
    color: #2d9cdb;
    background: #fff;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s;
}
.pa-upload-btn:hover { background: #f0f8ff; }
.pa-delete-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    font-size: 12px;
    border: 1px solid #dc2626;
    color: #dc2626;
    background: #fff;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s;
    margin-left: 8px;
}
.pa-delete-btn:hover { background: #fef2f2; }

/* プレースホルダー */
.pa-placeholder {
    text-align: center;
    padding: 60px 20px;
    color: #aaa;
}
.pa-placeholder-icon { font-size: 40px; margin-bottom: 12px; }
.pa-placeholder-text { font-size: 14px; }

/* 空状態 */
.pa-empty {
    text-align: center;
    padding: 80px 20px;
    color: #999;
}
.pa-empty-icon { font-size: 48px; margin-bottom: 16px; }
.pa-empty-text { font-size: 16px; margin-bottom: 8px; }
.pa-empty-sub { font-size: 13px; color: #bbb; }

/* テーブル上書き */
.pa-table .data-table td { vertical-align: middle; }
.pa-table .data-table th { white-space: nowrap; }
.pa-table .data-table tr[data-page-id] { cursor: pointer; transition: background 0.15s; }
.pa-table .data-table tr[data-page-id]:hover { background: #f0f8ff; }

/* ===== 行動データビュー ===== */
/* Clarity リンクボタン */
.pa-clarity-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 600;
    color: #568184;
    border: 1.5px solid #568184;
    border-radius: 8px;
    text-decoration: none;
    background: #FAF9F6;
    transition: all 0.25s ease;
    white-space: nowrap;
}
.pa-clarity-link:hover { background: #568184; color: #FAF9F6; text-decoration: none; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(47,58,74,0.12); }
/* デバイスセクション */
.hm-device-section { margin-bottom: 24px; }
.hm-device-section:last-child { margin-bottom: 0; }
.hm-device-title {
    font-size: 13px; font-weight: 600; color: #1e293b;
    margin: 0 0 10px; padding-bottom: 6px;
    border-bottom: 2px solid #e2e8f0;
    display: flex; align-items: center; gap: 6px;
}
.hm-device-title .hm-device-icon { font-size: 15px; }
/* サマリー */
.hm-summary {
    padding: 14px 0 0;
    border-top: 1px solid #eee;
}
.hm-summary-title {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 10px;
}
.hm-summary-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.hm-stat {
    padding: 10px 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
}
.hm-stat-label {
    font-size: 11px;
    color: #64748b;
    margin-bottom: 2px;
}
.hm-stat-value {
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
}
.hm-stat-sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 2px;
}
.hm-no-data {
    text-align: center;
    padding: 16px;
    color: #94a3b8;
    font-size: 13px;
}
/* 概要タブ 編集モード */
.pa-overview-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-bottom: 12px;
}
.pa-overview-actions .pa-btn-sm { font-size: 13px; padding: 5px 14px; }
.pa-edit-form .pa-form-group { margin-bottom: 12px; }
.pa-edit-form .pa-form-group label { font-size: 12px; color: #64748b; margin-bottom: 4px; display: block; }
.pa-edit-form .pa-form-group input,
.pa-edit-form .pa-form-group select {
    width: 100%; padding: 6px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; box-sizing: border-box;
}
.pa-edit-form .pa-form-group input:focus,
.pa-edit-form .pa-form-group select:focus {
    outline: none; border-color: #2d9cdb; box-shadow: 0 0 0 2px rgba(45,156,219,.15);
}

/* AI改善案タブ */
.pa-ai-prereq {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.pa-ai-prereq-item {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
}
.pa-ai-prereq-item--ok { background: #e6f7ed; color: #1a9b4a; }
.pa-ai-prereq-item--ng { background: #f0f0f0; color: #999; }
/* 注意ボックス（件数不足時） */
.pa-ai-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 13px;
    line-height: 1.6;
    margin-bottom: 16px;
}
.pa-ai-notice--warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.pa-ai-notice--info { background: #f0f9ff; border: 1px solid #bae6fd; color: #0c4a6e; }
.pa-ai-notice-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
.pa-ai-notice strong { font-weight: 700; }
.pa-ai-section { margin-bottom: 24px; }
.pa-ai-section > h4 {
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 12px;
    padding-bottom: 6px;
    border-bottom: 1px solid #e2e8f0;
}
/* AI本文 全体 */
.pa-ai-body { line-height: 1.75; font-size: 14px; color: #333; }
.pa-ai-body strong { font-weight: 700; color: #1e293b; }
.pa-ai-body p { margin: 0 0 10px; }
.pa-ai-body ul, .pa-ai-body ol { margin: 0 0 10px; padding-left: 20px; }
.pa-ai-body li { margin-bottom: 3px; }
/* セクションブロック（## 見出しの塊） */
.pa-ai-block { margin-bottom: 20px; padding: 14px 16px; border-radius: 8px; border: 1px solid #e9ecef; background: #fff; }
.pa-ai-block:last-child { margin-bottom: 0; }
.pa-ai-block-title {
    font-size: 14px; font-weight: 700; color: #fff;
    margin: -14px -16px 12px; padding: 8px 16px;
    border-radius: 8px 8px 0 0; display: flex; align-items: center; gap: 6px;
}
.pa-ai-block--flow .pa-ai-block-title { background: #568184; }
.pa-ai-block--good .pa-ai-block-title { background: #2d9f6f; }
.pa-ai-block--status .pa-ai-block-title { background: #4a7fb5; }
.pa-ai-block--improve .pa-ai-block-title { background: #c57525; }
/* 各項目カード（### 小見出しの塊） */
.pa-ai-item { margin-bottom: 12px; padding: 10px 12px; border-radius: 6px; background: #f8f9fa; border-left: 3px solid #cbd5e1; }
.pa-ai-item:last-child { margin-bottom: 0; }
.pa-ai-block--good .pa-ai-item { border-left-color: #34d399; background: #f0fdf4; }
.pa-ai-block--improve .pa-ai-item { border-left-color: #f59e0b; background: #fffbeb; }
.pa-ai-item-title { font-size: 13px; font-weight: 700; color: #1e293b; margin: 0 0 4px; }
.pa-ai-item p { margin: 0; font-size: 13px; line-height: 1.7; color: #4a5568; }
.pa-ai-item ul, .pa-ai-item ol { margin: 4px 0 0; padding-left: 18px; font-size: 13px; }
.pa-ai-item li { margin-bottom: 2px; color: #4a5568; }
.pa-behavior-note { font-size: 12px; color: #94a3b8; margin: 0 0 14px; }
.pa-behavior-header {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 8px; margin-bottom: 12px;
}

/* スクロール深度オーバーレイ */
.pa-img-wrap { position: relative; overflow: hidden; margin-bottom: 12px; }
.pa-scroll-overlay {
    position: absolute; left: 0; right: 0;
    pointer-events: none; z-index: 5;
}
.pa-scroll-line {
    height: 2.5px; background: #3b82f6;
    box-shadow: 0 1px 6px rgba(59,130,246,0.5);
}
.pa-scroll-label {
    display: inline-block;
    background: #3b82f6; color: #fff;
    font-size: 10px; padding: 2px 8px;
    border-radius: 0 0 4px 4px;
    white-space: nowrap;
}
.pa-scroll-shade {
    position: absolute; left: 0; right: 0; bottom: 0;
    background: linear-gradient(to bottom, rgba(0,0,0,0), rgba(0,0,0,0.08));
    pointer-events: none; z-index: 4;
}
.pa-scroll-legend {
    font-size: 11px; color: #64748b; margin-top: 4px;
    display: flex; align-items: center; gap: 6px;
}
.pa-scroll-legend-line {
    display: inline-block; width: 16px; height: 2px;
    background: #3b82f6; border-radius: 1px;
}

/* アップロード・ローディングオーバーレイ: 詳細パネル(z-index:10000)より上に表示 */
#uploadOverlay,
#loadingOverlay {
    z-index: 10100;
}

/* アップロード進捗バー */
.pa-progress-bar {
    width: 100%;
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
}
.pa-progress-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #2d9cdb, #56b5d9);
    border-radius: 4px;
    transition: width 0.2s ease;
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">
    <!-- ローディングオーバーレイ -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p id="loadingText">データを取得中...</p>
        </div>
    </div>

    <!-- アップロード進捗オーバーレイ -->
    <div class="loading-overlay" id="uploadOverlay">
        <div class="loading-spinner" style="min-width:280px;">
            <p id="uploadText" style="margin:0 0 12px;font-weight:600;">アップロード中...</p>
            <div class="pa-progress-bar">
                <div class="pa-progress-fill" id="uploadProgressFill"></div>
            </div>
            <p id="uploadPercent" style="margin:8px 0 0;font-size:13px;color:#64748b;">0%</p>
        </div>
    </div>

    <!-- アクションバー -->
    <div class="pa-action-bar">
        <span class="pa-count" id="paCount"></span>
        <div style="display:flex;gap:8px;align-items:center;">
            <a id="paHeaderClarityLink" href="#" target="_blank" rel="noopener"
               class="pa-clarity-link" style="display:none;">
                Clarityで詳細を見る &#8599;
            </a>
            <button type="button" class="pa-add-btn" id="paAddBtn">+ ページを追加</button>
        </div>
    </div>

    <!-- 一覧テーブル -->
    <div class="table-section pa-table">
        <div id="paTableArea">
            <!-- 空状態（初期表示） -->
            <div class="pa-empty" id="paEmpty" style="display:none;">
                <div class="pa-empty-icon">&#128196;</div>
                <div class="pa-empty-text">まだページが登録されていません</div>
                <div class="pa-empty-sub">「+ ページを追加」からサイトの主要ページを登録してください</div>
            </div>

            <!-- テーブル -->
            <table class="data-table" id="paTable" style="display:none;">
                <thead>
                    <tr>
                        <th>ページ名</th>
                        <th>種別</th>
                        <th>PC</th>
                        <th>SP</th>
                        <th>状態</th>
                        <th>最終分析</th>
                    </tr>
                </thead>
                <tbody id="paTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- 追加モーダル -->
<div class="pa-modal-overlay" id="paAddModal">
    <div class="pa-modal">
        <h3>ページを追加</h3>
        <div class="pa-form-group">
            <label for="paUrl">ページURL</label>
            <input type="url" id="paUrl" placeholder="https://example.com/service/">
        </div>
        <div class="pa-form-group">
            <label for="paTitle">ページ名（空欄なら自動取得）</label>
            <input type="text" id="paTitle" placeholder="サービス紹介">
        </div>
        <div class="pa-form-group">
            <label for="paType">ページ種別</label>
            <select id="paType">
                <option value="top">トップページ</option>
                <option value="service">サービス紹介</option>
                <option value="lp">LP（ランディングページ）</option>
                <option value="contact">お問い合わせ</option>
                <option value="blog">ブログ・コラム</option>
                <option value="company">会社概要</option>
                <option value="access">アクセス</option>
                <option value="staff">スタッフ紹介</option>
                <option value="price">料金</option>
                <option value="other" selected>その他</option>
            </select>
        </div>
        <div class="pa-form-group">
            <label for="paPurpose">ページの主な目的（任意）</label>
            <input type="text" id="paPurpose" placeholder="例: サービス紹介・問い合わせ獲得">
        </div>
        <div class="pa-form-group">
            <label for="paCta">主要CTA（任意）</label>
            <input type="text" id="paCta" placeholder="例: お問い合わせフォーム">
        </div>
        <div class="pa-modal-actions">
            <button type="button" class="pa-modal-btn" id="paAddCancel">キャンセル</button>
            <button type="button" class="pa-modal-btn pa-modal-btn--primary" id="paAddSubmit">追加する</button>
        </div>
    </div>
</div>

<!-- 詳細パネル（フルスクリーン2カラム） -->
<div class="pa-detail-backdrop" id="paDetailBackdrop"></div>
<div class="pa-detail-overlay" id="paDetailPanel">
    <div class="pa-detail-header">
        <button type="button" class="pa-back-btn" id="paDetailClose">&#8592; 一覧に戻る</button>
        <h3 id="paDetailTitle">-</h3>
        <div class="pa-detail-header-actions" id="paDetailHeaderActions"></div>
    </div>
    <div class="pa-detail-body">
        <!-- 左カラム: ページ画像 -->
        <div class="pa-detail-left" id="paDetailLeft"></div>
        <!-- 右カラム: 概要 + 行動データ + AI改善案 -->
        <div class="pa-detail-right" id="paDetailRight"></div>
    </div>
</div>

<!-- 非表示ファイル入力 -->
<input type="file" id="paFileInput" accept="image/jpeg,image/png,image/webp" style="display:none;">

<!-- 原寸プレビュー -->
<div class="pa-lightbox" id="paLightbox">
    <button type="button" class="pa-lightbox-close" id="paLightboxClose">&times;</button>
    <img src="" alt="プレビュー" id="paLightboxImg">
</div>

<script>
(function() {
    'use strict';

    var API_BASE = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gcrev/v1/page-analysis/pages' ) ) ); ?>;
    var NONCE    = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

    var PAGE_TYPES = {
        top: 'トップページ', service: 'サービス', lp: 'LP', contact: 'お問い合わせ',
        blog: 'ブログ', company: '会社概要', access: 'アクセス', staff: 'スタッフ',
        price: '料金', other: 'その他'
    };

    // --- DOM ---
    var els = {
        loading:       document.getElementById('loadingOverlay'),
        count:         document.getElementById('paCount'),
        empty:         document.getElementById('paEmpty'),
        table:         document.getElementById('paTable'),
        tbody:         document.getElementById('paTableBody'),
        addBtn:        document.getElementById('paAddBtn'),
        addModal:      document.getElementById('paAddModal'),
        addCancel:     document.getElementById('paAddCancel'),
        addSubmit:     document.getElementById('paAddSubmit'),
        urlInput:      document.getElementById('paUrl'),
        titleInput:    document.getElementById('paTitle'),
        typeSelect:    document.getElementById('paType'),
        detailPanel:   document.getElementById('paDetailPanel'),
        detailBackdrop:document.getElementById('paDetailBackdrop'),
        detailTitle:   document.getElementById('paDetailTitle'),
        detailClose:   document.getElementById('paDetailClose'),
        detailLeft:    document.getElementById('paDetailLeft'),
        detailRight:   document.getElementById('paDetailRight'),
        detailActions: document.getElementById('paDetailHeaderActions'),
        fileInput:     document.getElementById('paFileInput'),
    };

    var currentDetailId = null;
    var uploadTarget = null; // { id, device }

    // --- 原寸プレビュー（ライトボックス） ---
    var lightbox = document.getElementById('paLightbox');
    var lightboxImg = document.getElementById('paLightboxImg');
    window._paLightbox = function(url) {
        lightboxImg.src = url;
        lightbox.classList.add('is-open');
    };
    document.getElementById('paLightboxClose').addEventListener('click', function() {
        lightbox.classList.remove('is-open');
        lightboxImg.src = '';
    });
    lightbox.addEventListener('click', function(e) {
        if (e.target === lightbox) {
            lightbox.classList.remove('is-open');
            lightboxImg.src = '';
        }
    });

    // --- ユーティリティ ---
    function escHtml(s) {
        if (!s) return '';
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function relativeDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        var now = new Date();
        var diffMs = now - d;
        var diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
        if (diffDays === 0) return '今日';
        if (diffDays === 1) return '昨日';
        if (diffDays < 7) return diffDays + '日前';
        if (diffDays < 30) return Math.floor(diffDays / 7) + '週間前';
        return Math.floor(diffDays / 30) + 'ヶ月前';
    }

    function showLoading() { els.loading.classList.add('active'); }
    function hideLoading() { els.loading.classList.remove('active'); }

    function apiFetch(url, options) {
        var opts = options || {};
        var headers = opts.headers || {};
        headers['X-WP-Nonce'] = NONCE;
        if (!opts.isUpload) {
            headers['Content-Type'] = 'application/json';
        }
        return fetch(url, {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: headers,
            body: opts.body || null,
        }).then(function(r) { return r.json(); });
    }

    // --- 一覧読み込み ---
    function loadPages() {
        showLoading();
        apiFetch(API_BASE).then(function(res) {
            hideLoading();
            if (!res.success) return;
            var pages = res.data.pages || [];
            renderList(pages);
            // ヘッダーのClarityリンク設定（最初のページのclarity_project_idを使用）
            initHeaderClarityLink();
        }).catch(function() {
            hideLoading();
        });
    }

    function initHeaderClarityLink() {
        var link = document.getElementById('paHeaderClarityLink');
        if (!link) return;
        // クライアント設定のClarity project ID を REST APIから取得
        fetch('<?php echo esc_url_raw( rest_url( 'gcrev/v1/clarity/settings' ) ); ?>', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': NONCE }
        }).then(function(r) { return r.json(); }).then(function(res) {
            var pid = res.clarity_project_id || (res.data && res.data.clarity_project_id);
            if (pid) {
                link.href = 'https://clarity.microsoft.com/projects/view/' + encodeURIComponent(pid) + '/dashboard';
                link.style.display = '';
            }
        }).catch(function() {});
    }

    function renderList(pages) {
        els.count.textContent = pages.length + ' ページ登録中';
        if (pages.length === 0) {
            els.empty.style.display = 'block';
            els.table.style.display = 'none';
            return;
        }
        els.empty.style.display = 'none';
        els.table.style.display = 'table';

        var html = '';
        for (var i = 0; i < pages.length; i++) {
            var p = pages[i];
            var typeName = PAGE_TYPES[p.page_type] || p.page_type;
            var pcThumb = p.screenshot_pc_url
                ? '<img src="' + escHtml(p.screenshot_pc_url) + '" class="pa-thumb" alt="PC">'
                : '<div class="pa-thumb-placeholder">未取得</div>';
            var spThumb = p.screenshot_mobile_url
                ? '<img src="' + escHtml(p.screenshot_mobile_url) + '" class="pa-thumb" alt="SP">'
                : '<div class="pa-thumb-placeholder">未取得</div>';
            // 状態判定: 分析済み > データ蓄積中 > 準備中
            var statusHtml = '';
            if (p.ai_summary) {
                statusHtml = '<span class="pa-badge pa-badge--done">分析済み</span>';
            } else if (p.clarity_data) {
                statusHtml = '<span class="pa-badge pa-badge--warn">データ蓄積中</span>';
            } else if (p.screenshot_pc_url || p.screenshot_mobile_url) {
                statusHtml = '<span class="pa-badge pa-badge--pending">準備中</span>';
            } else {
                statusHtml = '<span class="pa-badge pa-badge--pending">未設定</span>';
            }

            // 最終分析日（相対表示）
            var lastDate = p.ai_analysis_date || p.clarity_sync_date || '';
            var lastDateHtml = '';
            if (lastDate) {
                var rel = relativeDate(lastDate);
                lastDateHtml = '<div class="pa-date-relative">' + escHtml(rel) + '</div>'
                    + '<div class="pa-date-absolute">' + escHtml(lastDate.substring(0, 10)) + '</div>';
            } else {
                lastDateHtml = '<span style="font-size:12px;color:#bbb;">—</span>';
            }

            html += '<tr data-page-id="' + p.id + '">'
                + '<td><div style="font-weight:500;">' + escHtml(p.page_title || '（タイトル未取得）') + '</div>'
                + '<div style="font-size:12px;color:#999;margin-top:2px;">' + escHtml(p.page_url) + '</div></td>'
                + '<td><span class="pa-badge pa-badge--type">' + escHtml(typeName) + '</span></td>'
                + '<td>' + pcThumb + '</td>'
                + '<td>' + spThumb + '</td>'
                + '<td>' + statusHtml + '</td>'
                + '<td>' + lastDateHtml + '</td>'
                + '</tr>';
        }
        els.tbody.innerHTML = html;
    }

    // 行クリックで詳細を開く
    els.tbody.addEventListener('click', function(e) {
        var row = e.target.closest('tr[data-page-id]');
        if (row) window._paShowDetail(parseInt(row.getAttribute('data-page-id')));
    });

    // --- ページ追加 ---
    els.addBtn.addEventListener('click', function() {
        els.urlInput.value = '';
        els.titleInput.value = '';
        els.typeSelect.value = 'other';
        document.getElementById('paPurpose').value = '';
        document.getElementById('paCta').value = '';
        els.addModal.classList.add('is-open');
        els.urlInput.focus();
    });
    els.addCancel.addEventListener('click', function() {
        els.addModal.classList.remove('is-open');
    });
    els.addModal.addEventListener('click', function(e) {
        if (e.target === els.addModal) els.addModal.classList.remove('is-open');
    });

    els.addSubmit.addEventListener('click', function() {
        var url = els.urlInput.value.trim();
        if (!url) { els.urlInput.focus(); return; }
        els.addSubmit.disabled = true;
        els.addSubmit.textContent = '追加中...';
        apiFetch(API_BASE, {
            method: 'POST',
            body: JSON.stringify({
                page_url: url,
                page_title: els.titleInput.value.trim(),
                page_type: els.typeSelect.value,
                page_purpose: document.getElementById('paPurpose').value.trim(),
                page_cta: document.getElementById('paCta').value.trim(),
            }),
        }).then(function(res) {
            els.addSubmit.disabled = false;
            els.addSubmit.textContent = '追加する';
            if (res.success) {
                els.addModal.classList.remove('is-open');
                loadPages();
            } else {
                alert(res.message || 'エラーが発生しました');
            }
        }).catch(function() {
            els.addSubmit.disabled = false;
            els.addSubmit.textContent = '追加する';
            alert('通信エラーが発生しました');
        });
    });

    // --- ページ削除 ---
    window._paDeletePage = function(id) {
        if (!confirm('このページを削除しますか？')) return;
        apiFetch(API_BASE + '/' + id, { method: 'DELETE' }).then(function(res) {
            if (res.success) loadPages();
        });
    };

    // --- 詳細パネル ---
    function openDetail() {
        els.detailPanel.classList.add('is-open');
        els.detailBackdrop.classList.add('is-open');
        // モーダル操作を妨げないようAI相談FABを非表示
        var chatFab = document.querySelector('.mw-chat-fab');
        if (chatFab) chatFab.style.display = 'none';
    }
    function closeDetail() {
        els.detailPanel.classList.remove('is-open');
        els.detailBackdrop.classList.remove('is-open');
        currentDetailId = null;
        // AI相談FABを復元
        var chatFab = document.querySelector('.mw-chat-fab');
        if (chatFab) chatFab.style.display = '';
    }
    els.detailClose.addEventListener('click', closeDetail);
    els.detailBackdrop.addEventListener('click', closeDetail);

    window._paShowDetail = function(id) {
        currentDetailId = id;
        els.detailTitle.textContent = '読み込み中...';
        els.detailLeft.innerHTML = '';
        els.detailRight.innerHTML = '';
        els.detailActions.innerHTML = '';
        openDetail();

        apiFetch(API_BASE + '/' + id + '/detail').then(function(res) {
            if (!res.success) { closeDetail(); return; }
            renderDetail(res.data);
        });
    };

    function renderDetail(data) {
        els.detailTitle.textContent = data.page_title || '（タイトル未取得）';
        window._currentDetailData = data;

        // ヘッダーアクションは概要セクション内に移動
        els.detailActions.innerHTML = '';

        // ===== 左カラム: ページ画像 =====
        els.detailLeft.innerHTML = buildImageColumn(data);

        // ===== 右カラム: ステータスバー + 概要 + 行動データ + AI改善案 =====
        var rightHtml = buildStatusBar(data)
            + buildOverviewSection(data)
            + buildBehaviorSection(data)
            + '<div class="pa-section" id="paAiSection">'
            + '<h4 class="pa-section-title"><span class="pa-section-icon">&#129302;</span> AI改善案</h4>'
            + '<div id="paAiContent"></div>'
            + '</div>';
        els.detailRight.innerHTML = rightHtml;

        // 行動データ描画
        window._hmCurrentData = data;
        renderHeatmapTab(data);

        // AI改善案描画
        renderAiTab(data);
        maybeAutoGenerateAi(data);
    }

    function buildImgWithScroll(imgHtml, scrollPct) {
        if (!imgHtml.match(/pa-capture-img/)) return imgHtml;
        var overlay = '';
        if (scrollPct !== null && scrollPct > 0) {
            overlay = '<div class="pa-scroll-overlay" style="top:' + scrollPct.toFixed(1) + '%;">'
                + '<div class="pa-scroll-line"></div>'
                + '<span class="pa-scroll-label">&#9660; 平均到達位置 ' + scrollPct.toFixed(1) + '%</span>'
                + '</div>'
                + '<div class="pa-scroll-shade" style="top:' + scrollPct.toFixed(1) + '%;"></div>';
        }
        return '<div class="pa-img-wrap">' + imgHtml + overlay + '</div>';
    }

    function buildImageColumn(data) {
        var pcThumb = data.screenshot_pc_original || data.screenshot_pc_url;
        var spThumb = data.screenshot_mobile_original || data.screenshot_mobile_url;
        var pcImg = data.screenshot_pc_url
            ? '<img src="' + escHtml(pcThumb) + '" class="pa-capture-img" alt="PC版">'
            : '<div class="pa-capture-empty">画像未登録</div>';
        var spImg = data.screenshot_mobile_url
            ? '<img src="' + escHtml(spThumb) + '" class="pa-capture-img" alt="SP版">'
            : '<div class="pa-capture-empty">画像未登録</div>';

        var pcScroll = getScrollDepth(data, 'pc');
        var spScroll = getScrollDepth(data, 'mobile');

        var legendHtml = (pcScroll !== null || spScroll !== null)
            ? '<div class="pa-scroll-legend" style="margin-bottom:12px;">'
              + '<span class="pa-scroll-legend-line"></span> 青線 = 平均到達位置（この位置より下は閲覧率が下がります）'
              + '</div>' : '';

        var pcDel = data.screenshot_pc_url
            ? ' <button type="button" class="pa-delete-btn" onclick="window._paDeleteCapture(' + data.id + ', \'pc\')">削除</button>' : '';
        var spDel = data.screenshot_mobile_url
            ? ' <button type="button" class="pa-delete-btn" onclick="window._paDeleteCapture(' + data.id + ', \'mobile\')">削除</button>' : '';

        return '<div class="pa-section">'
            + '<h4 class="pa-section-title"><span class="pa-section-icon">&#128247;</span> ページ画像</h4>'
            + '<div class="pa-img-tabs">'
            + '<button type="button" class="pa-img-tab is-active" data-img-tab="pc">&#128187; PC版</button>'
            + '<button type="button" class="pa-img-tab" data-img-tab="mobile">&#128241; スマホ版</button>'
            + '</div>'
            + legendHtml
            + '<div class="pa-img-pane is-active" data-img-pane="pc">'
            + '<div class="pa-capture-box">' + buildImgWithScroll(pcImg, pcScroll)
            + '<button type="button" class="pa-upload-btn" onclick="window._paUpload(' + data.id + ', \'pc\')">画像をアップロード</button>' + pcDel + '</div>'
            + '</div>'
            + '<div class="pa-img-pane" data-img-pane="mobile">'
            + '<div class="pa-capture-box">' + buildImgWithScroll(spImg, spScroll)
            + '<button type="button" class="pa-upload-btn" onclick="window._paUpload(' + data.id + ', \'mobile\')">画像をアップロード</button>' + spDel + '</div>'
            + '</div>'
            + '</div>';
    }

    // 画像タブ切替
    els.detailLeft.addEventListener('click', function(e) {
        var tab = e.target.closest('.pa-img-tab');
        if (!tab) return;
        var target = tab.getAttribute('data-img-tab');
        var tabs = els.detailLeft.querySelectorAll('.pa-img-tab');
        var panes = els.detailLeft.querySelectorAll('.pa-img-pane');
        for (var i = 0; i < tabs.length; i++) tabs[i].classList.remove('is-active');
        for (var i = 0; i < panes.length; i++) panes[i].classList.remove('is-active');
        tab.classList.add('is-active');
        var pane = els.detailLeft.querySelector('[data-img-pane="' + target + '"]');
        if (pane) pane.classList.add('is-active');
    });

    function buildStatusBar(data) {
        // 分析状態チップ
        var statusClass = 'pa-status-chip--pending';
        var statusLabel = '未設定';
        if (data.ai_summary) { statusClass = 'pa-status-chip--done'; statusLabel = '分析済み'; }
        else if (data.clarity_data) { statusClass = 'pa-status-chip--warn'; statusLabel = 'データ蓄積中'; }
        else if (data.screenshot_pc_url || data.screenshot_mobile_url) { statusLabel = '準備中'; }

        var chips = '<span class="pa-status-chip ' + statusClass + '">' + statusLabel + '</span>';

        // 最終分析日チップ
        var lastDate = data.ai_analysis_date || data.clarity_sync_date;
        if (lastDate) {
            chips += '<span class="pa-status-chip">最終分析: ' + escHtml(relativeDate(lastDate)) + '</span>';
        }

        // 画像登録チップ
        var imgStatus = [];
        if (data.screenshot_pc_url) imgStatus.push('PC');
        if (data.screenshot_mobile_url) imgStatus.push('SP');
        if (imgStatus.length > 0) {
            chips += '<span class="pa-status-chip">画像: ' + imgStatus.join(' / ') + '</span>';
        }

        // TODO: 将来ここに改善優先度・主要課題・注目端末を追加
        // chips += '<span class="pa-status-chip pa-status-chip--warn">優先度: 高</span>';
        // chips += '<span class="pa-status-chip">課題: CTA到達前離脱</span>';

        return '<div class="pa-status-bar">' + chips + '</div>';
    }

    function buildOverviewSection(data) {
        var typeName = PAGE_TYPES[data.page_type] || data.page_type;
        return '<div class="pa-section">'
            + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">'
            + '<h4 class="pa-section-title" style="margin-bottom:0;"><span class="pa-section-icon">&#128196;</span> 概要</h4>'
            + '<div style="display:flex;gap:8px;">'
            + '<button type="button" class="pa-btn-sm" onclick="window._paEditOverview()">編集</button>'
            + '<button type="button" class="pa-btn-sm pa-btn-sm--danger" onclick="window._paDeletePage(' + data.id + ')">削除</button>'
            + '</div></div>'
            + '<div id="paOverviewDisplay">'
            + '<div class="pa-info-row"><span class="pa-info-label">URL</span><span class="pa-info-value"><a href="' + escHtml(data.page_url) + '" target="_blank" rel="noopener">' + escHtml(data.page_url) + '</a></span></div>'
            + '<div class="pa-info-row"><span class="pa-info-label">種別</span><span class="pa-info-value"><span class="pa-badge pa-badge--type">' + escHtml(typeName) + '</span></span></div>'
            + '<div class="pa-info-row"><span class="pa-info-label">主な目的</span><span class="pa-info-value">' + escHtml(data.page_purpose || '未設定') + '</span></div>'
            + '<div class="pa-info-row"><span class="pa-info-label">主要CTA</span><span class="pa-info-value">' + escHtml(data.page_cta || '未設定') + '</span></div>'
            + '<div class="pa-info-row"><span class="pa-info-label">Clarity同期</span><span class="pa-info-value">' + escHtml(data.clarity_sync_date || '未連携') + '</span></div>'
            + '<div class="pa-info-row"><span class="pa-info-label">AI改善案</span><span class="pa-info-value">' + escHtml(data.ai_analysis_date || '未生成') + '</span></div>'
            + '</div></div>';
    }

    function buildBehaviorSection(data) {
        var clarityLinkHtml = '';
        if (data.clarity_project_id) {
            clarityLinkHtml = '<a href="https://clarity.microsoft.com/projects/view/' + encodeURIComponent(data.clarity_project_id) + '/dashboard" target="_blank" rel="noopener" class="pa-clarity-link" style="padding:6px 12px;font-size:12px;">Clarityで詳細を見る &#8599;</a>';
        }
        return '<div class="pa-section">'
            + '<h4 class="pa-section-title"><span class="pa-section-icon">&#128202;</span> 行動データ ' + clarityLinkHtml + '</h4>'
            + '<p class="pa-behavior-note">このページの閲覧や読まれ方の傾向をまとめています。</p>'
            + '<div id="hmBehaviorContent"></div>'
            + '</div>';
    }

    // --- 概要タブ編集モード ---
    window._paEditOverview = function() {
        var data = window._currentDetailData;
        if (!data) return;
        var typeOptions = '';
        for (var k in PAGE_TYPES) {
            typeOptions += '<option value="' + k + '"' + (data.page_type === k ? ' selected' : '') + '>' + PAGE_TYPES[k] + '</option>';
        }
        var overviewEl = document.getElementById('paOverviewDisplay');
        if (!overviewEl) return;
        overviewEl.innerHTML = ''
            + '<div class="pa-overview-actions">'
            + '<button type="button" class="pa-btn-sm" onclick="window._paShowDetail(' + data.id + ')">キャンセル</button>'
            + '<button type="button" class="pa-btn-sm pa-modal-btn--primary" style="color:#fff;" id="paOverviewSave">保存</button>'
            + '</div>'
            + '<div class="pa-edit-form">'
            + '<div class="pa-form-group"><label>種別</label><select id="paEditType">' + typeOptions + '</select></div>'
            + '<div class="pa-form-group"><label>主な目的</label><input type="text" id="paEditPurpose" value="' + escHtml(data.page_purpose || '') + '" placeholder="例: サービス紹介・問い合わせ獲得"></div>'
            + '<div class="pa-form-group"><label>主要CTA</label><input type="text" id="paEditCta" value="' + escHtml(data.page_cta || '') + '" placeholder="例: お問い合わせフォーム"></div>'
            + '</div>';

        document.getElementById('paOverviewSave').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            btn.textContent = '保存中...';
            apiFetch(API_BASE + '/' + data.id, {
                method: 'PUT',
                body: JSON.stringify({
                    page_type: document.getElementById('paEditType').value,
                    page_purpose: document.getElementById('paEditPurpose').value.trim(),
                    page_cta: document.getElementById('paEditCta').value.trim(),
                }),
            }).then(function(res) {
                if (res.success) {
                    window._paShowDetail(data.id);
                    loadPages();
                } else {
                    alert(res.message || '保存に失敗しました');
                    btn.disabled = false;
                    btn.textContent = '保存';
                }
            });
        });
    };

    // --- AI改善案タブ ---
    var _aiGenerating = false; // 生成中フラグ（重複防止）

    function renderAiTab(data) {
        var content = document.getElementById('paAiContent');
        var hasPC = !!data.screenshot_pc_url;
        var hasSP = !!data.screenshot_mobile_url;
        var hasClarity = !!(data.clarity_data && data.clarity_data.metrics && Object.keys(data.clarity_data.metrics).length > 0);

        // データ信頼度の算出（セッション数ベース）
        var totalSessions = 0;
        if (hasClarity && data.clarity_data.site_wide && data.clarity_data.site_wide.by_device) {
            var byDev = data.clarity_data.site_wide.by_device;
            ['PC', 'Mobile'].forEach(function(dk) {
                if (byDev[dk] && byDev[dk].traffic) {
                    var t = byDev[dk].traffic;
                    totalSessions += parseInt(t.sessionsWithMetricCount || t.subTotal || t.sessionsCount || 0);
                }
            });
        }
        var reliabilityLevel = totalSessions >= 100 ? 'sufficient' : (totalSessions >= 20 ? 'reference' : 'hypothesis');
        var reliabilityLabel = reliabilityLevel === 'sufficient' ? '十分なデータあり'
            : (reliabilityLevel === 'reference' ? '参考傾向（データ少なめ）' : '参考分析（データ少なめ）');
        var reliabilityClass = reliabilityLevel === 'sufficient' ? 'pa-badge--done'
            : (reliabilityLevel === 'reference' ? 'pa-badge--warn' : 'pa-badge--pending');
        // 注意ボックスHTML
        var noticeHtml = '';
        if (reliabilityLevel === 'hypothesis') {
            noticeHtml = '<div class="pa-ai-notice pa-ai-notice--warn">'
                + '<span class="pa-ai-notice-icon">&#9888;&#65039;</span>'
                + '<div><strong>データ件数が少ないため、以下は参考分析です。</strong><br>'
                + '現時点では断定的な判断ではなく、今後データが蓄積されてから改めて分析することをお勧めします。</div></div>';
        } else if (reliabilityLevel === 'reference') {
            noticeHtml = '<div class="pa-ai-notice pa-ai-notice--info">'
                + '<span class="pa-ai-notice-icon">&#128712;</span>'
                + '<div><strong>データ件数がまだ十分ではないため、以下は参考傾向です。</strong><br>'
                + '継続的な計測により分析精度が向上します。</div></div>';
        }

        var prereqHtml = '<div class="pa-ai-prereq">'
            + '<span class="pa-ai-prereq-item ' + (hasPC ? 'pa-ai-prereq-item--ok' : 'pa-ai-prereq-item--ng') + '">'
            + (hasPC ? '&#10003;' : '&#10007;') + ' PC画像</span>'
            + '<span class="pa-ai-prereq-item ' + (hasSP ? 'pa-ai-prereq-item--ok' : 'pa-ai-prereq-item--ng') + '">'
            + (hasSP ? '&#10003;' : '&#10007;') + ' SP画像</span>'
            + '<span class="pa-ai-prereq-item ' + (hasClarity ? 'pa-ai-prereq-item--ok' : 'pa-ai-prereq-item--ng') + '">'
            + (hasClarity ? '&#10003;' : '&#10007;') + ' 行動データ</span>'
            + '<span class="pa-badge ' + reliabilityClass + '" style="margin-left:8px;font-size:11px;">'
            + reliabilityLabel + (totalSessions > 0 ? '（' + totalSessions + '件）' : '') + '</span>'
            + '</div>';

        if (data.ai_summary) {
            // 生成済み: 改善案を表示
            var bodyHtml = '<div class="pa-ai-section"><h4>AI改善案</h4>'
                + '<div class="pa-ai-body">' + formatAiText(data.ai_summary) + '</div></div>';
            if (data.ai_analysis_date) {
                bodyHtml += '<div style="font-size:12px;color:#94a3b8;margin-top:8px;">生成日: ' + escHtml(data.ai_analysis_date) + '</div>';
            }
            var btnHtml = '<div style="text-align:center;margin-top:16px;">'
                + '<button type="button" class="pa-modal-btn" id="paAiGenerate"'
                + ' onclick="window._paGenerateAi(' + data.id + ')" style="font-size:13px;">再生成する</button></div>';
            content.innerHTML = prereqHtml + noticeHtml + bodyHtml + btnHtml;
        } else if (_aiGenerating) {
            // 自動生成中: ローディング表示
            content.innerHTML = prereqHtml
                + '<div class="pa-placeholder" style="padding:40px 20px;">'
                + '<div class="loading-spinner" style="background:none;box-shadow:none;"><div class="spinner"></div></div>'
                + '<div class="pa-placeholder-text" style="margin-top:12px;">AI改善案を生成中...<br><small style="color:#94a3b8;">画像と行動データを分析しています（30秒〜1分程度）</small></div></div>';
        } else {
            // 未生成: プレースホルダー + 生成ボタン
            var canGenerate = hasPC && hasClarity; // 最低条件: PC画像 + 行動データ
            var bodyHtml = '<div class="pa-placeholder" style="padding:40px 20px;">'
                + '<div class="pa-placeholder-icon">&#129302;</div>'
                + '<div class="pa-placeholder-text">ページ画像と行動データをもとに<br>AI改善案を生成します</div></div>';
            var btnHtml = '<div style="text-align:center;margin-top:16px;">'
                + '<button type="button" class="pa-modal-btn pa-modal-btn--primary" id="paAiGenerate"'
                + (canGenerate ? '' : ' disabled')
                + ' onclick="window._paGenerateAi(' + data.id + ')">AI改善案を生成</button></div>';
            content.innerHTML = prereqHtml + bodyHtml + btnHtml;
        }
    }

    // AI改善案テキストをセクション・アイテム構造に整形
    function formatAiText(text) {
        if (!text) return '';

        // セクション種別→CSS・アイコン対応
        var sectionMap = {
            '流入背景': { cls: 'flow', icon: '&#128200;' },
            '良かった点': { cls: 'good', icon: '&#9989;' },
            '現状の見立て': { cls: 'status', icon: '&#128202;' },
            '改善提案': { cls: 'improve', icon: '&#128161;' },
        };

        var lines = text.split('\n');
        var blocks = []; // [{title, cls, icon, items:[{title?,lines:[]}]}]
        var curBlock = null;
        var curItem = null;

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            // ## セクション見出し
            var secMatch = line.match(/^##\s+(.+)$/);
            if (secMatch) {
                var secTitle = secMatch[1].replace(/\*\*/g, '');
                var meta = sectionMap[secTitle] || { cls: 'status', icon: '&#128196;' };
                curBlock = { title: secTitle, cls: meta.cls, icon: meta.icon, items: [] };
                curItem = null;
                blocks.push(curBlock);
                continue;
            }
            // ### 項目見出し
            var itemMatch = line.match(/^###\s+(.+)$/);
            if (itemMatch) {
                curItem = { title: itemMatch[1].replace(/\*\*/g, ''), lines: [] };
                if (curBlock) curBlock.items.push(curItem);
                continue;
            }
            // 通常行
            if (line.trim() === '') continue;
            if (curItem) {
                curItem.lines.push(line);
            } else if (curBlock) {
                // セクション直下の本文（項目なし）
                if (!curBlock._directLines) curBlock._directLines = [];
                curBlock._directLines.push(line);
            }
        }

        // HTML生成
        var out = '';
        for (var b = 0; b < blocks.length; b++) {
            var bl = blocks[b];
            out += '<div class="pa-ai-block pa-ai-block--' + bl.cls + '">';
            out += '<div class="pa-ai-block-title">' + bl.icon + ' ' + escHtml(bl.title) + '</div>';

            // セクション直下本文
            if (bl._directLines && bl._directLines.length) {
                out += '<div style="margin-bottom:8px;">';
                out += formatLines(bl._directLines);
                out += '</div>';
            }

            // 各項目
            for (var it = 0; it < bl.items.length; it++) {
                var item = bl.items[it];
                out += '<div class="pa-ai-item">';
                out += '<div class="pa-ai-item-title">' + formatInline(escHtml(item.title)) + '</div>';
                if (item.lines.length) {
                    out += formatLines(item.lines);
                }
                out += '</div>';
            }
            out += '</div>';
        }

        // どのセクションにも属さないテキスト（冒頭等）
        if (blocks.length === 0) {
            out = '<p>' + formatInline(escHtml(text)).replace(/\n/g, '<br>') + '</p>';
        }

        return out;
    }

    // 行配列をHTMLに（箇条書き・段落対応）
    function formatLines(lines) {
        var html = '';
        var inList = false;
        for (var i = 0; i < lines.length; i++) {
            var ln = lines[i];
            var bulletMatch = ln.match(/^\* (.+)/);
            var numMatch = ln.match(/^\d+\.\s+(.+)/);
            if (bulletMatch || numMatch) {
                if (!inList) { html += '<ul>'; inList = true; }
                html += '<li>' + formatInline(escHtml(bulletMatch ? bulletMatch[1] : numMatch[1])) + '</li>';
            } else {
                if (inList) { html += '</ul>'; inList = false; }
                html += '<p>' + formatInline(escHtml(ln)) + '</p>';
            }
        }
        if (inList) html += '</ul>';
        return html;
    }

    // インラインマークダウン（太字）
    function formatInline(s) {
        return s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    }

    // 自動生成: パネルオープン時にバックグラウンドで実行
    function maybeAutoGenerateAi(data) {
        if (_aiGenerating) return;
        if (data.ai_summary) return; // 生成済み
        var hasPC = !!data.screenshot_pc_url;
        var hasClarity = !!(data.clarity_data && data.clarity_data.metrics && Object.keys(data.clarity_data.metrics).length > 0);
        if (!hasPC || !hasClarity) return; // 前提条件不足

        _aiGenerating = true;
        renderAiTab(data); // ローディング表示に更新

        apiFetch(API_BASE + '/' + data.id + '/analyze', { method: 'POST' })
            .then(function(res) {
                _aiGenerating = false;
                if (res.success && res.ai_summary) {
                    // 生成結果でデータを更新
                    data.ai_summary = res.ai_summary;
                    data.ai_analysis_date = res.ai_analysis_date;
                    window._currentDetailData = data;
                }
                renderAiTab(data);
            }).catch(function() {
                _aiGenerating = false;
                renderAiTab(data);
            });
    }

    window._paGenerateAi = function(id) {
        _aiGenerating = true;

        var data = window._currentDetailData;
        // 再生成時は既存の結果をクリアしてローディング表示にする
        if (data) {
            data.ai_summary = null;
            data.ai_analysis_date = null;
            renderAiTab(data);
        }

        apiFetch(API_BASE + '/' + id + '/analyze', { method: 'POST' })
            .then(function(res) {
                _aiGenerating = false;
                if (res.success && res.ai_summary && data) {
                    data.ai_summary = res.ai_summary;
                    data.ai_analysis_date = res.ai_analysis_date;
                    window._currentDetailData = data;
                    renderAiTab(data);
                } else {
                    if (res.message) alert(res.message);
                    if (data) renderAiTab(data);
                }
            }).catch(function() {
                _aiGenerating = false;
                if (data) renderAiTab(data);
            });
    };

    // ===== 行動データ（PC/SP同時表示） =====

    function renderHeatmapTab(data) {
        renderBehaviorBothDevices(data);
    }

    // 0 を正しく扱うヘルパー
    function safeNum(v) {
        if (v === undefined || v === null || v === '') return null;
        return v;
    }

    function stat(label, value, sub) {
        return '<div class="hm-stat"><div class="hm-stat-label">' + escHtml(label) + '</div>'
            + '<div class="hm-stat-value">' + escHtml(String(value)) + '</div>'
            + (sub ? '<div class="hm-stat-sub">' + escHtml(sub) + '</div>' : '')
            + '</div>';
    }

    // スクロール深度をデバイス別に取得（ページ画像タブでも再利用）
    function getScrollDepth(data, device) {
        var clarity = data.clarity_data || {};
        var deviceKey = device === 'mobile' ? 'Mobile' : 'PC';
        var siteWide = clarity.site_wide || {};
        var swDev = (siteWide.by_device && (siteWide.by_device[deviceKey] || siteWide.by_device['Desktop'])) || {};
        var devMetrics = (clarity.devices && (clarity.devices[deviceKey] || clarity.devices['Desktop'])) || {};
        var scrollM = swDev.scroll_depth || devMetrics.scroll_depth || (clarity.metrics || {}).scroll_depth || {};
        var val = safeNum(scrollM.averageScrollDepth) !== null ? scrollM.averageScrollDepth
                : safeNum(scrollM.sessionsWithMetricPercentage) !== null ? scrollM.sessionsWithMetricPercentage
                : null;
        return val !== null ? parseFloat(val) : null;
    }

    function buildDeviceMetrics(device, data) {
        var clarity = data.clarity_data || {};
        var metrics = clarity.metrics || {};
        var deviceKey = device === 'mobile' ? 'Mobile' : 'PC';
        var siteWide = clarity.site_wide || {};
        var swDevice = (siteWide.by_device && (siteWide.by_device[deviceKey] || siteWide.by_device['Desktop'])) || {};
        var devMetrics = (clarity.devices && (clarity.devices[deviceKey] || clarity.devices['Desktop'])) || {};

        var scrollM = swDevice.scroll_depth || devMetrics.scroll_depth || metrics.scroll_depth || {};
        var engM    = swDevice.engagement_time || devMetrics.engagement_time || metrics.engagement_time || {};
        var dcM     = swDevice.dead_click_count || devMetrics.dead_click_count || metrics.dead_click_count || {};
        var rcM     = swDevice.rage_click_count || devMetrics.rage_click_count || metrics.rage_click_count || {};
        var tM      = swDevice.traffic || devMetrics.traffic || metrics.traffic || {};
        var ecM     = swDevice.error_click_count || devMetrics.error_click_count || metrics.error_click_count || {};

        var scrollVal = safeNum(scrollM.averageScrollDepth) !== null ? scrollM.averageScrollDepth
                      : safeNum(scrollM.sessionsWithMetricPercentage) !== null ? scrollM.sessionsWithMetricPercentage : null;
        var scrollDisplay = scrollVal !== null ? (parseFloat(scrollVal).toFixed(1) + '%') : '-';

        var engVal = safeNum(engM.activeTime) !== null ? engM.activeTime
                   : safeNum(engM.totalTime) !== null ? engM.totalTime : null;
        var engDisplay = engVal !== null ? (parseFloat(engVal).toFixed(0) + '秒') : '-';

        var dcCount = safeNum(dcM.subTotal) !== null ? dcM.subTotal : (safeNum(dcM.sessionsCount) !== null ? dcM.sessionsCount : '-');
        var rcCount = safeNum(rcM.subTotal) !== null ? rcM.subTotal : (safeNum(rcM.sessionsCount) !== null ? rcM.sessionsCount : '-');
        var ecCount = safeNum(ecM.subTotal) !== null ? ecM.subTotal : (safeNum(ecM.sessionsCount) !== null ? ecM.sessionsCount : '-');
        var sessions = safeNum(tM.sessionsCount) !== null ? tM.sessionsCount : (safeNum(tM.totalSessionCount) !== null ? tM.totalSessionCount : '-');

        return '<div class="hm-summary-grid">'
            + stat('セッション数', sessions, 'このページが見られた回数の目安です')
            + stat('スクロール深度', scrollDisplay, 'ページのどこまで読まれたかの目安')
            + stat('Dead Click', dcCount, '押しても反応がなかった回数')
            + stat('Rage Click', rcCount, '反応しないため何度も押された回数')
            + stat('エンゲージメント', engDisplay, 'ページを実際に見た・操作した時間の目安')
            + stat('Error Click', ecCount, 'クリック時に不具合が起きた可能性がある回数')
            + '</div>';
    }

    function renderBehaviorBothDevices(data) {
        var hmBehaviorContent = document.getElementById('hmBehaviorContent');
        if (!hmBehaviorContent) return;
        var clarity = data.clarity_data || {};
        var metrics = clarity.metrics || {};

        // データ期間
        var periodHtml = '';
        var syncDate = data.clarity_sync_date;
        if (syncDate) {
            var sd = new Date(syncDate.replace(' ', 'T'));
            var from = new Date(sd);
            from.setDate(from.getDate() - 3);
            var fmt = function(d) { return d.getFullYear() + '/' + (d.getMonth()+1) + '/' + d.getDate(); };
            periodHtml = '<div style="font-size:11px;color:#94a3b8;margin-bottom:14px;">'
                + '&#128197; ' + fmt(from) + ' 〜 ' + fmt(sd) + '（直近3日間） 同期: ' + escHtml(syncDate)
                + '</div>';
        }

        if (Object.keys(metrics).length === 0) {
            hmBehaviorContent.innerHTML = '<div class="hm-no-data">Clarityデータ未取得 — クライアント設定からClarity同期を実行してください</div>';
            return;
        }

        var html = periodHtml;

        // PC版セクション
        html += '<div class="hm-device-section">'
            + '<h5 class="hm-device-title"><span class="hm-device-icon">&#128187;</span> PC版</h5>'
            + buildDeviceMetrics('pc', data)
            + '</div>';

        // スマホ版セクション
        html += '<div class="hm-device-section">'
            + '<h5 class="hm-device-title"><span class="hm-device-icon">&#128241;</span> スマホ版</h5>'
            + buildDeviceMetrics('mobile', data)
            + '</div>';

        hmBehaviorContent.innerHTML = html;
    }

    // --- ファイルアップロード ---
    window._paUpload = function(id, device) {
        uploadTarget = { id: id, device: device };
        els.fileInput.value = '';
        els.fileInput.click();
    };

    els.fileInput.addEventListener('change', function() {
        if (!this.files[0] || !uploadTarget) return;
        var file = this.files[0];

        // ファイルサイズチェック（50MB上限）
        var maxSize = 50 * 1024 * 1024;
        if (file.size > maxSize) {
            alert('ファイルサイズが大きすぎます（上限: 50MB）。\n現在のサイズ: ' + Math.round(file.size / 1024 / 1024) + 'MB');
            return;
        }

        var formData = new FormData();
        formData.append('file', file);
        formData.append('device_type', uploadTarget.device);

        var fileSizeMB = (file.size / 1024 / 1024).toFixed(1);
        var uploadOverlay = document.getElementById('uploadOverlay');
        var uploadText = document.getElementById('uploadText');
        var uploadPercent = document.getElementById('uploadPercent');
        var uploadFill = document.getElementById('uploadProgressFill');

        // 進捗オーバーレイ表示
        uploadText.textContent = 'アップロード中... (' + fileSizeMB + 'MB)';
        uploadPercent.textContent = '0%';
        uploadFill.style.width = '0%';
        uploadOverlay.classList.add('active');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_BASE + '/' + uploadTarget.id + '/snapshot');
        xhr.setRequestHeader('X-WP-Nonce', NONCE);
        xhr.withCredentials = true;

        xhr.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                var pct = Math.round((e.loaded / e.total) * 100);
                uploadFill.style.width = pct + '%';
                uploadPercent.textContent = pct + '%';
                if (pct >= 100) {
                    uploadText.textContent = 'サーバーで処理中...';
                }
            }
        });

        xhr.addEventListener('load', function() {
            uploadOverlay.classList.remove('active');
            if (xhr.status >= 200 && xhr.status < 300) {
                var res;
                try { res = JSON.parse(xhr.responseText); } catch (e) { res = {}; }
                if (res.success) {
                    window._paShowDetail(uploadTarget.id);
                    loadPages();
                } else {
                    alert(res.message || 'アップロードに失敗しました');
                }
            } else {
                alert('HTTP ' + xhr.status + ': サーバーエラー（ファイルが大きすぎる可能性があります）\nファイルサイズ: ' + fileSizeMB + 'MB');
            }
        });

        xhr.addEventListener('error', function() {
            uploadOverlay.classList.remove('active');
            alert('アップロードエラー: 通信エラー\nファイルサイズ: ' + fileSizeMB + 'MB\n\nサーバーのアップロード上限を超えている可能性があります。');
        });

        xhr.send(formData);
    });

    // --- キャプチャ削除 ---
    window._paDeleteCapture = function(id, device) {
        var label = device === 'mobile' ? 'スマホ版' : 'PC版';
        if (!confirm(label + 'のキャプチャ画像を削除しますか？')) return;

        showLoading();
        fetch(API_BASE + '/' + id + '/snapshot', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': NONCE,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ device_type: device }),
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(res) {
            hideLoading();
            if (res.success) {
                window._paShowDetail(id);
                loadPages();
            } else {
                alert(res.message || '削除に失敗しました');
            }
        })
        .catch(function(err) {
            hideLoading();
            alert('削除エラー: ' + (err.message || '通信エラー'));
        });
    };

    // --- 初期化 ---
    loadPages();

})();
</script>

<?php get_footer(); ?>
