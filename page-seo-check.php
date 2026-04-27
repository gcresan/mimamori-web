<?php
/**
 * Template Name: SEO対策
 * Description: サイトの基本的なSEO状態を診断し、改善ポイントを整理するページ
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

if ( ! mimamori_can_access_seo_check() ) {
    wp_safe_redirect( home_url( '/dashboard/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'SEO診断' );
set_query_var( 'gcrev_page_subtitle', 'サイトの基本的なSEO状態を診断し、改善ポイントを整理します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'SEO診断', '各種診断' ) );

get_header();
?>
<style>
/* =========================================================
   SEO対策 — スタイル
   ========================================================= */

/* サマリーカード */
.seo-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}
.seo-summary-card {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 24px;
    text-align: center;
    transition: box-shadow 0.2s, transform 0.2s;
}
.seo-summary-card:hover {
    box-shadow: var(--mw-shadow-float);
    transform: translateY(-1px);
}
.seo-summary-card--clickable {
    cursor: pointer;
    position: relative;
}
.seo-summary-card--clickable::after {
    content: '詳細を見る ›';
    display: block;
    font-size: 11px;
    color: var(--mw-primary-blue);
    margin-top: 8px;
    font-weight: 600;
    opacity: 0.85;
}
.seo-summary-card--clickable:hover {
    border-color: var(--mw-primary-blue);
}
.seo-summary-card--clickable:focus-visible {
    outline: 2px solid var(--mw-primary-blue);
    outline-offset: 2px;
}
.seo-summary-card--disabled {
    cursor: default;
    opacity: 0.85;
}
.seo-summary-card--disabled::after { display: none; }
.seo-summary-card__label {
    font-size: 13px;
    color: var(--mw-text-tertiary);
    margin-bottom: 8px;
}
.seo-summary-card__value {
    font-size: 36px;
    font-weight: 700;
    color: var(--mw-text-heading);
    line-height: 1.2;
}
.seo-summary-card__value--accent { color: var(--mw-primary-blue); }
.seo-summary-card__value--critical { color: #C95A4F; }
.seo-summary-card__value--warning { color: #C9A84C; }
.seo-summary-card__sub {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-top: 4px;
}
.seo-score-ring {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    position: relative;
    margin-bottom: 4px;
}
.seo-score-ring svg { position: absolute; top: 0; left: 0; transform: rotate(-90deg); }
.seo-score-ring__value {
    font-size: 28px;
    font-weight: 700;
    z-index: 1;
}
.seo-rank-label {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 4px;
}
.seo-rank-label--A { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.seo-rank-label--B { background: rgba(86,129,132,0.12); color: #568184; }
.seo-rank-label--C { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-rank-label--D { background: rgba(201,90,79,0.12); color: #C95A4F; }

/* バッジ */
.seo-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}
.seo-badge--critical { background: rgba(201,90,79,0.12); color: #C95A4F; }
.seo-badge--warning  { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-badge--ok       { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.seo-badge--none     { background: var(--mw-bg-secondary); color: var(--mw-text-tertiary); }

/* セクション */
.seo-section {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 28px;
    margin-bottom: 28px;
}
.seo-section__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.seo-section__title {
    font-size: 16px;
    font-weight: 600;
    color: var(--mw-text-heading);
    margin: 0;
}
.seo-section__note {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-top: 4px;
}

/* ボタン */
.seo-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 1px solid var(--mw-border-light);
    background: var(--mw-bg-primary);
    border-radius: 8px;
    font-size: 14px;
    color: var(--mw-text-primary);
    cursor: pointer;
    transition: background 0.15s;
}
.seo-btn:hover { background: var(--mw-bg-secondary); }
.seo-btn--primary {
    background: var(--mw-primary-blue);
    color: #fff;
    border-color: var(--mw-primary-blue);
    transition: all 0.25s ease;
}
.seo-btn--primary:hover { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.seo-btn--primary:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.seo-btn--primary:focus-visible { outline: 2px solid var(--mw-primary-blue); outline-offset: 2px; }
.seo-btn--primary:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

/* 診断グリッド */
.seo-diagnosis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 12px;
}
.seo-diagnosis-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px;
    border: 1px solid var(--mw-border-light);
    border-radius: 12px;
    background: var(--mw-bg-primary);
    transition: box-shadow 0.15s;
}
.seo-diagnosis-item:hover { box-shadow: var(--mw-shadow-soft); }
.seo-diagnosis-item--highlight {
    box-shadow: 0 0 0 2px var(--mw-primary-blue), var(--mw-shadow-float);
    transition: box-shadow 0.4s;
}
.seo-diagnosis-grid--filter-critical .seo-diagnosis-item:not([data-status="critical"]) { display: none; }
.seo-diagnosis-grid--filter-caution  .seo-diagnosis-item:not([data-status="caution"])  { display: none; }
.seo-diagnosis-filter-bar {
    display: none;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    margin-bottom: 14px;
    background: rgba(86,129,132,0.08);
    border: 1px solid rgba(86,129,132,0.2);
    border-radius: 10px;
    font-size: 13px;
    color: var(--mw-text-primary);
}
.seo-diagnosis-filter-bar.is-active { display: flex; }
.seo-diagnosis-filter-bar__label { font-weight: 600; }
.seo-diagnosis-filter-bar__clear {
    margin-left: auto;
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: 6px;
    padding: 4px 12px;
    font-size: 12px;
    cursor: pointer;
    color: var(--mw-text-secondary);
}
.seo-diagnosis-filter-bar__clear:hover {
    border-color: var(--mw-primary-blue);
    color: var(--mw-primary-blue);
}
.seo-diagnosis-empty-msg {
    padding: 32px 16px;
    text-align: center;
    font-size: 13px;
    color: var(--mw-text-tertiary);
    background: var(--mw-bg-secondary);
    border-radius: 10px;
    grid-column: 1 / -1;
}
.seo-diagnosis-item__icon {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
}
.seo-diagnosis-item__icon--ok       { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.seo-diagnosis-item__icon--caution  { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-diagnosis-item__icon--critical { background: rgba(201,90,79,0.12); color: #C95A4F; }
.seo-diagnosis-item__body { flex: 1; min-width: 0; }
.seo-diagnosis-item__label {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-primary);
    margin-bottom: 2px;
}
.seo-diagnosis-item__score {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-bottom: 4px;
}
.seo-diagnosis-item__findings {
    font-size: 13px;
    color: var(--mw-text-secondary);
    line-height: 1.6;
    margin: 0;
    padding: 0;
    list-style: none;
}
.seo-diagnosis-item__findings li { padding: 1px 0; }
.seo-diagnosis-item__findings li::before {
    content: '・';
    color: var(--mw-text-tertiary);
}
.seo-url-cell__title {
    font-size: 13px;
    font-weight: 600;
    color: var(--mw-text-primary);
    line-height: 1.45;
    margin-bottom: 4px;
    word-break: break-word;
}
.seo-url-cell__path {
    font-size: 11px;
    color: var(--mw-text-tertiary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 260px;
}
.seo-diagnosis-item__urls {
    margin-top: 6px;
    font-size: 11px;
    color: var(--mw-text-tertiary);
    line-height: 1.5;
    word-break: break-all;
    max-height: 4.5em;
    overflow-y: auto;
}

/* 全体評価 */
.seo-assessment { line-height: 1.7; }
.seo-assessment__summary {
    font-size: 14px;
    color: var(--mw-text-primary);
    margin-bottom: 20px;
    padding: 16px;
    background: var(--mw-bg-secondary);
    border-radius: 10px;
}
.seo-assessment__group { margin-bottom: 16px; }
.seo-assessment__group-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-primary);
    margin-bottom: 8px;
}
.seo-assessment__list {
    list-style: none;
    padding: 0;
    margin: 0;
    font-size: 13px;
    color: var(--mw-text-secondary);
}
.seo-assessment__list li {
    padding: 4px 0;
    padding-left: 20px;
    position: relative;
}
.seo-assessment__list li::before {
    position: absolute;
    left: 0;
}
.seo-assessment__list--good li::before { content: '✓'; color: #4E8A6B; font-weight: 600; }
.seo-assessment__list--improve li::before { content: '!'; color: #C95A4F; font-weight: 700; }

/* 問題URLテーブル */
.seo-issues-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.seo-issues-table thead {
    background: var(--mw-bg-secondary);
    border-bottom: 2px solid var(--mw-border-light);
}
.seo-issues-table th {
    padding: 10px 14px;
    font-weight: 600;
    color: var(--mw-text-secondary);
    font-size: 12px;
    text-align: left;
    white-space: nowrap;
}
.seo-issues-table th[data-sort] {
    cursor: pointer;
    user-select: none;
    transition: color .15s;
}
.seo-issues-table th[data-sort]:hover {
    color: var(--mw-text-primary, #333);
}
.seo-issues-table th .sort-icon {
    display: inline-block;
    margin-left: 4px;
    font-size: 10px;
    opacity: .35;
    transition: opacity .15s;
}
.seo-issues-table th.sort-active .sort-icon {
    opacity: 1;
    color: var(--mw-primary-blue, #4A90A4);
}
.seo-issues-table td {
    padding: 14px 14px;
    border-bottom: 1px solid var(--mw-border-light);
    vertical-align: top;
    line-height: 1.5;
}
.seo-issues-table tbody tr:hover { background: var(--mw-bg-secondary); }
.seo-issues-table tbody tr.seo-issues-same-page td {
    border-top: 1px dashed var(--mw-border-light);
}
.seo-issues-table .seo-url-cell {
    min-width: 180px;
    max-width: 300px;
    color: var(--mw-primary-blue);
    font-weight: 500;
}
.seo-priority-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.seo-priority-badge--high   { background: rgba(201,90,79,0.12); color: #C95A4F; }
.seo-priority-badge--medium { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-priority-badge--low    { background: rgba(86,129,132,0.12); color: #568184; }

/* アクションカード */
.seo-actions-list { display: flex; flex-direction: column; gap: 12px; }
.seo-action-card {
    display: flex;
    gap: 14px;
    padding: 16px;
    border: 1px solid var(--mw-border-light);
    border-radius: 12px;
    background: var(--mw-bg-primary);
    border-left: 4px solid transparent;
}
.seo-action-card--high   { border-left-color: #C95A4F; }
.seo-action-card--medium { border-left-color: #C9A84C; }
.seo-action-card--low    { border-left-color: var(--mw-primary-teal); }
.seo-action-card__priority {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
}
.seo-action-card__priority--high   { background: #C95A4F; }
.seo-action-card__priority--medium { background: #C9A84C; }
.seo-action-card__priority--low    { background: var(--mw-primary-teal); }
.seo-action-card__body { flex: 1; }
.seo-action-card__title { font-size: 14px; font-weight: 600; color: var(--mw-text-heading); margin-bottom: 4px; }
.seo-action-card__desc  { font-size: 13px; color: var(--mw-text-secondary); line-height: 1.5; }

/* キーワード最適化 */
.seo-kw-grid { display: flex; flex-direction: column; gap: 16px; }
.seo-kw-card {
    border: 1px solid var(--mw-border-light);
    border-radius: 12px;
    padding: 20px;
    background: var(--mw-bg-primary);
}
.seo-kw-card__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    gap: 8px;
}
.seo-kw-card__keyword {
    font-size: 15px;
    font-weight: 600;
    color: var(--mw-text-heading);
}
.seo-kw-card__placements {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.seo-kw-placement {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}
.seo-kw-placement--found { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.seo-kw-placement--missing { background: rgba(201,90,79,0.08); color: #C95A4F; }
.seo-kw-card__pages {
    font-size: 13px;
    color: var(--mw-text-secondary);
    line-height: 1.7;
}
.seo-kw-card__ai {
    margin-top: 12px;
    padding: 12px 16px;
    background: var(--mw-bg-secondary);
    border-radius: 8px;
    font-size: 13px;
    color: var(--mw-text-secondary);
    line-height: 1.6;
}
.seo-kw-card__ai strong { color: var(--mw-text-primary); }
.seo-kw-card__ai ul { margin: 4px 0 0; padding-left: 16px; }
.seo-kw-card__ai li { margin-bottom: 2px; }
.seo-kw-empty {
    text-align: center;
    padding: 32px 24px;
    color: var(--mw-text-tertiary);
    font-size: 14px;
    background: var(--mw-bg-secondary);
    border-radius: 8px;
    line-height: 1.8;
}
.seo-kw-empty a {
    color: var(--mw-brand);
    text-decoration: none;
    font-weight: 600;
}
.seo-kw-empty a:hover { text-decoration: underline; }

/* プログレス */
.seo-progress {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
}
.seo-progress.active { display: flex; }
.seo-progress__inner {
    background: #fff;
    border-radius: 16px;
    padding: 40px;
    text-align: center;
    min-width: 300px;
}
.seo-progress__spinner {
    width: 40px; height: 40px;
    margin: 0 auto 16px;
    border: 3px solid var(--mw-border-light);
    border-top-color: var(--mw-primary-teal);
    border-radius: 50%;
    animation: seo-spin 0.8s linear infinite;
}
@keyframes seo-spin { to { transform: rotate(360deg); } }
.seo-progress__text { font-size: 14px; color: var(--mw-text-secondary); }

/* トースト */
.seo-toast {
    position: fixed;
    bottom: 24px;
    left: 24px;
    padding: 12px 20px;
    border-radius: 10px;
    background: #1A2F33;
    color: #fff;
    font-size: 14px;
    z-index: 10000;
    opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
}
.seo-toast.active { opacity: 1; pointer-events: auto; }
.seo-toast--error { background: #C95A4F; }

/* 比較バー */
.seo-comparison-bar {
    display: flex;
    gap: 16px;
    padding: 12px 16px;
    background: var(--mw-bg-secondary);
    border: 1px solid var(--mw-border-light);
    border-radius: 8px;
    margin-bottom: 24px;
    font-size: 13px;
    color: var(--mw-text-secondary);
    align-items: center;
    flex-wrap: wrap;
}
.seo-comparison-bar__item {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* スコアデルタ */
.seo-score-delta {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    font-size: 13px;
    font-weight: 600;
    margin-top: 4px;
}
.seo-score-delta--up { color: #4E8A6B; }
.seo-score-delta--down { color: #C95A4F; }
.seo-score-delta--same { color: var(--mw-text-tertiary); }
.seo-score-delta--sm { font-size: 11px; margin-top: 0; }

/* 空状態 */
.seo-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--mw-text-tertiary);
    font-size: 14px;
}

/* テーブルラッパー */
.seo-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* レスポンシブ */
@media (max-width: 768px) {
    .seo-summary-cards { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .seo-summary-card { padding: 16px; }
    .seo-summary-card__value { font-size: 28px; }
    .seo-diagnosis-grid { grid-template-columns: 1fr; }
    .seo-issues-table { font-size: 12px; }
    .seo-issues-table th, .seo-issues-table td { padding: 10px 8px; }
    .seo-issues-table .seo-url-cell { min-width: 140px; max-width: 220px; }
    .seo-url-cell__path { max-width: 180px; }
    .seo-section { padding: 20px 16px; }
    .seo-kw-card__header { flex-direction: column; align-items: flex-start; }
    .seo-section__header { flex-direction: column; align-items: flex-start; gap: 12px; }
}
</style>

<div class="content-area">

    <!-- プログレスオーバーレイ -->
    <div class="seo-progress" id="seoProgress">
        <div class="seo-progress__inner">
            <div class="seo-progress__spinner"></div>
            <div class="seo-progress__text" id="seoProgressText">診断中...</div>
        </div>
    </div>

    <!-- トースト -->
    <div class="seo-toast" id="seoToast"></div>

    <!-- ===== Section 1: サマリー ===== -->
    <div class="seo-summary-cards" id="seoSummary" style="display:none;"></div>

    <!-- ===== 前回比較バー ===== -->
    <div id="seoComparisonBar" style="display:none;"></div>

    <!-- ===== Section 2: SEO診断カード一覧 ===== -->
    <div class="seo-section" id="seoDiagnosisSection" style="display:none;">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">🔍 SEO診断チェック</h2>
                <div class="seo-section__note">タイトル・description・見出し・画像alt・内部リンク・技術設定などを確認します</div>
            </div>
        </div>
        <div class="seo-diagnosis-filter-bar" id="seoDiagnosisFilterBar">
            <span class="seo-diagnosis-filter-bar__label" id="seoDiagnosisFilterLabel"></span>
            <button type="button" class="seo-diagnosis-filter-bar__clear" id="seoDiagnosisFilterClear">すべて表示</button>
        </div>
        <div class="seo-diagnosis-grid" id="seoDiagnosisGrid"></div>
    </div>

    <!-- ===== Section 3: 診断結果の概要 ===== -->
    <div class="seo-section" id="seoAssessmentSection" style="display:none;">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">📝 全体評価</h2>
                <div class="seo-section__note">診断結果を総合的にまとめた評価です</div>
            </div>
        </div>
        <div id="seoAssessmentContent"></div>
    </div>

    <!-- ===== Section 3.5: キーワード最適化 ===== -->
    <div class="seo-section" id="seoKeywordSection" style="display:none;">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">🔑 キーワード最適化</h2>
                <div class="seo-section__note">登録したターゲットキーワードがサイト内で適切に使われているかを確認します</div>
            </div>
        </div>
        <div id="seoKeywordContent"></div>
    </div>

    <!-- ===== Section 4: 問題URL一覧 ===== -->
    <div class="seo-section" id="seoIssuesSection" style="display:none;">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">📋 ページ別の問題一覧</h2>
                <div class="seo-section__note">対象ページごとに検出された問題と改善候補です</div>
            </div>
        </div>
        <div class="seo-table-wrap" id="seoIssuesContent"></div>
    </div>

    <!-- ===== Section 5: 改善アクション提案 ===== -->
    <div class="seo-section" id="seoActionsSection" style="display:none;">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">💡 改善アクション提案</h2>
                <div class="seo-section__note">診断結果をもとに、優先度の高い改善ポイントを提案します</div>
            </div>
        </div>
        <div id="seoActionsContent"></div>
    </div>

    <!-- ===== 未診断時の表示 ===== -->
    <div class="seo-section" id="seoEmptyState" style="display:none;">
        <div style="text-align:center;padding:60px 20px;">
            <div style="font-size:48px;margin-bottom:16px;">🔍</div>
            <div style="font-size:16px;font-weight:600;color:var(--mw-text-heading);margin-bottom:8px;">まだSEO診断が実行されていません</div>
            <div style="font-size:14px;color:var(--mw-text-secondary);margin-bottom:24px;">「診断する」ボタンを押すと、サイトのSEO状態をチェックします。</div>
            <button class="seo-btn seo-btn--primary" onclick="window.runSeoCheck()" style="font-size:15px;padding:12px 28px;">
                診断する
            </button>
        </div>
    </div>

</div><!-- .content-area -->

<script>
(function() {
    'use strict';

    var wpNonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';

    // セクション要素ID
    var SECTION_IDS = ['seoSummary','seoComparisonBar','seoDiagnosisSection','seoKeywordSection','seoAssessmentSection','seoIssuesSection','seoActionsSection'];

    /* =================================================================
       ユーティリティ
       ================================================================= */
    function esc(str) {
        if (str === null || str === undefined) return '';
        var d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    function showToast(msg, isError) {
        var el = document.getElementById('seoToast');
        el.textContent = msg;
        el.className = 'seo-toast active' + (isError ? ' seo-toast--error' : '');
        setTimeout(function() { el.className = 'seo-toast'; }, 4000);
    }

    function showProgress(msg) {
        document.getElementById('seoProgressText').textContent = msg || '診断中...';
        document.getElementById('seoProgress').classList.add('active');
    }

    function hideProgress() {
        document.getElementById('seoProgress').classList.remove('active');
    }

    function getScoreColor(score, max) {
        var pct = (score / max) * 100;
        if (pct >= 80) return '#4E8A6B';
        if (pct >= 50) return '#C9A84C';
        return '#C95A4F';
    }

    function getRankColor(rank) {
        var map = { A: '#4E8A6B', B: '#568184', C: '#C9A84C', D: '#C95A4F' };
        return map[rank] || '#568184';
    }

    function deltaHtml(delta, small) {
        if (delta === null || delta === undefined || delta === 0) return '';
        var cls = delta > 0 ? 'up' : 'down';
        var arrow = delta > 0 ? '↑' : '↓';
        var sign = delta > 0 ? '+' : '';
        var sm = small ? ' seo-score-delta--sm' : '';
        return '<span class="seo-score-delta seo-score-delta--' + cls + sm + '">' + arrow + sign + delta + '</span>';
    }

    var STATUS_ICONS = { ok: '✓', caution: '△', critical: '✕' };
    var PRIORITY_LABELS = { high: '高', medium: '中', low: '低' };

    function showSections(visible) {
        SECTION_IDS.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.style.display = visible ? '' : 'none';
        });
        var empty = document.getElementById('seoEmptyState');
        if (empty) empty.style.display = visible ? 'none' : '';
    }

    /* =================================================================
       初期化 — APIからデータ取得
       ================================================================= */
    document.addEventListener('DOMContentLoaded', function() {
        var clearBtn = document.getElementById('seoDiagnosisFilterClear');
        if (clearBtn) clearBtn.addEventListener('click', clearDiagnosisFilter);
        fetchReport();
    });

    function fetchReport() {
        // 初回読み込み時は全セクション非表示のまま（ちらつき防止）
        // showSections() は API レスポンス後にのみ呼ぶ
        fetch('/wp-json/gcrev/v1/seo/report', {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success && json.data) {
                showSections(true);
                renderAll(json.data);
            } else {
                showSections(false);
            }
        })
        .catch(function(err) {
            console.error('[SEO]', err);
            showSections(false);
        });
    }

    function renderAll(data) {
        var comp = data.comparison || null;
        var kwData = data.keywordAnalysis || null;
        renderSummary(data.siteSummary, comp, kwData);
        renderComparisonBar(comp);
        renderDiagnosis(data.seoChecks, comp);
        renderAssessment(data.overallAssessment);
        renderKeywords(kwData);
        renderIssues(data.issuePages);
        renderActions(data.recommendations);
    }

    /* =================================================================
       前回比較バー
       ================================================================= */
    function renderComparisonBar(comp) {
        var el = document.getElementById('seoComparisonBar');
        if (!el) return;
        if (!comp) { el.style.display = 'none'; return; }
        el.style.display = '';
        var html = '<div class="seo-comparison-bar">';
        html += '<div class="seo-comparison-bar__item">📊 前回診断: ' + esc(comp.previousDate) + '</div>';
        html += '<div class="seo-comparison-bar__item">スコア変動: ' + deltaHtml(comp.totalScoreDelta) + (comp.totalScoreDelta === 0 ? '<span class="seo-score-delta seo-score-delta--same">→ 変動なし</span>' : '') + '</div>';
        html += '<div class="seo-comparison-bar__item" style="color:#4E8A6B;">改善: ' + esc(comp.improvedCount) + '項目</div>';
        html += '<div class="seo-comparison-bar__item" style="color:#C95A4F;">悪化: ' + esc(comp.worsenedCount) + '項目</div>';
        html += '</div>';
        el.innerHTML = html;
    }

    /* =================================================================
       Section 1: サマリー
       ================================================================= */
    function renderSummary(s, comp, kwData) {
        var scoreColor = getRankColor(s.rank);
        var circumference = 2 * Math.PI * 36;
        var offset = circumference - (s.totalScore / 100) * circumference;

        function clickAttrs(action, enabled) {
            if (!enabled) return ' class="seo-summary-card seo-summary-card--disabled"';
            return ' class="seo-summary-card seo-summary-card--clickable" tabindex="0" role="button" data-card-action="' + action + '"';
        }

        var hasCritical = (s.criticalCount || 0) > 0;
        var hasWarning  = (s.warningCount  || 0) > 0;
        var hasKeywords = !!(kwData && kwData.keywords && kwData.keywords.length > 0);

        var html = '';
        html += '<div' + clickAttrs('assessment', true) + '>';
        html += '  <div class="seo-summary-card__label">SEO総合スコア</div>';
        html += '  <div class="seo-score-ring">';
        html += '    <svg width="80" height="80" viewBox="0 0 80 80">';
        html += '      <circle cx="40" cy="40" r="36" stroke="var(--mw-border-light)" stroke-width="6" fill="none"/>';
        html += '      <circle cx="40" cy="40" r="36" stroke="' + esc(scoreColor) + '" stroke-width="6" fill="none"';
        html += '        stroke-dasharray="' + circumference + '" stroke-dashoffset="' + offset + '" stroke-linecap="round"/>';
        html += '    </svg>';
        html += '    <span class="seo-score-ring__value" style="color:' + esc(scoreColor) + '">' + esc(s.totalScore) + '</span>';
        html += '  </div>';
        html += '  <div><span class="seo-rank-label seo-rank-label--' + esc(s.rank) + '">' + esc(s.rank) + 'ランク</span></div>';
        if (comp && comp.totalScoreDelta !== null && comp.totalScoreDelta !== 0) {
            html += '  <div>' + deltaHtml(comp.totalScoreDelta) + '点</div>';
        }
        html += '</div>';

        html += '<div' + clickAttrs('critical', hasCritical) + '>';
        html += '  <div class="seo-summary-card__label">致命的な問題</div>';
        html += '  <div class="seo-summary-card__value seo-summary-card__value--critical">' + esc(s.criticalCount) + '<span style="font-size:16px;font-weight:400;">件</span></div>';
        html += '  <div class="seo-summary-card__sub">' + (hasCritical ? '該当の診断項目を表示' : '早急に対応が必要な項目はありません') + '</div>';
        html += '</div>';

        html += '<div' + clickAttrs('caution', hasWarning) + '>';
        html += '  <div class="seo-summary-card__label">要改善項目</div>';
        html += '  <div class="seo-summary-card__value seo-summary-card__value--warning">' + esc(s.warningCount) + '<span style="font-size:16px;font-weight:400;">件</span></div>';
        html += '  <div class="seo-summary-card__sub">' + (hasWarning ? '該当の診断項目を表示' : '改善が必要な項目はありません') + '</div>';
        html += '</div>';

        html += '<div' + clickAttrs('issues', true) + '>';
        html += '  <div class="seo-summary-card__label">診断対象ページ</div>';
        html += '  <div class="seo-summary-card__value">' + esc(s.pageCount) + '<span style="font-size:16px;font-weight:400;">ページ</span></div>';
        html += '  <div class="seo-summary-card__sub">ページ別の問題一覧へ</div>';
        html += '</div>';

        if (hasKeywords) {
            html += '<div' + clickAttrs('keywords', true) + '>';
            html += '  <div class="seo-summary-card__label">ターゲットキーワード</div>';
            html += '  <div class="seo-summary-card__value seo-summary-card__value--accent">' + esc(kwData.keywords.length) + '<span style="font-size:16px;font-weight:400;">件</span></div>';
            html += '  <div class="seo-summary-card__sub">キーワード最適化を確認</div>';
            html += '</div>';
        }

        html += '<div class="seo-summary-card seo-summary-card--disabled">';
        html += '  <div class="seo-summary-card__label">最終診断日時</div>';
        html += '  <div class="seo-summary-card__value" style="font-size:18px;">' + esc(s.lastCheckedAt) + '</div>';
        html += '  <div class="seo-summary-card__sub">';
        html += '    <button class="seo-btn seo-btn--primary" onclick="window.runSeoCheck()" style="margin-top:8px;font-size:13px;padding:6px 14px;">再診断する</button>';
        html += '  </div>';
        html += '</div>';

        var summaryEl = document.getElementById('seoSummary');
        summaryEl.innerHTML = html;
        bindSummaryCardClicks(summaryEl);
    }

    /* =================================================================
       サマリーカードのクリック → 該当セクションへスクロール / フィルタ
       ================================================================= */
    function bindSummaryCardClicks(root) {
        var cards = root.querySelectorAll('.seo-summary-card--clickable');
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            card.addEventListener('click', handleSummaryCardAction);
            card.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleSummaryCardAction.call(this, e);
                }
            });
        }
    }

    function handleSummaryCardAction(e) {
        var action = this.getAttribute('data-card-action');
        switch (action) {
            case 'critical': applyDiagnosisFilter('critical'); break;
            case 'caution':  applyDiagnosisFilter('caution');  break;
            case 'issues':     scrollToSection('seoIssuesSection');     break;
            case 'keywords':   scrollToSection('seoKeywordSection');    break;
            case 'assessment': scrollToSection('seoAssessmentSection'); break;
        }
    }

    function scrollToSection(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function applyDiagnosisFilter(status) {
        var grid = document.getElementById('seoDiagnosisGrid');
        var bar  = document.getElementById('seoDiagnosisFilterBar');
        var lbl  = document.getElementById('seoDiagnosisFilterLabel');
        var section = document.getElementById('seoDiagnosisSection');
        if (!grid || !bar || !lbl) return;

        grid.classList.remove('seo-diagnosis-grid--filter-critical', 'seo-diagnosis-grid--filter-caution');
        grid.classList.add('seo-diagnosis-grid--filter-' + status);

        lbl.textContent = (status === 'critical' ? '🚨 致命的な問題のみ表示中' : '⚠️ 要改善項目のみ表示中');
        bar.classList.add('is-active');

        if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // 該当アイテムを軽くハイライト
        var items = grid.querySelectorAll('.seo-diagnosis-item[data-status="' + status + '"]');
        for (var i = 0; i < items.length; i++) {
            (function(it) {
                it.classList.add('seo-diagnosis-item--highlight');
                setTimeout(function() { it.classList.remove('seo-diagnosis-item--highlight'); }, 1400);
            })(items[i]);
        }
    }

    function clearDiagnosisFilter() {
        var grid = document.getElementById('seoDiagnosisGrid');
        var bar  = document.getElementById('seoDiagnosisFilterBar');
        if (grid) grid.classList.remove('seo-diagnosis-grid--filter-critical', 'seo-diagnosis-grid--filter-caution');
        if (bar)  bar.classList.remove('is-active');
    }

    /* =================================================================
       Section 2: 診断カード一覧
       ================================================================= */
    function renderDiagnosis(checks, comp) {
        var perCheck = (comp && comp.perCheck) ? comp.perCheck : {};
        var html = '';
        checks.forEach(function(c) {
            var iconClass = 'seo-diagnosis-item__icon--' + c.status;
            var icon = STATUS_ICONS[c.status] || '?';
            var scoreColor = getScoreColor(c.score, c.maxScore);

            // per-check delta
            var checkDelta = perCheck[c.key] ? perCheck[c.key].delta : null;

            html += '<div class="seo-diagnosis-item" data-status="' + esc(c.status) + '">';
            html += '  <div class="seo-diagnosis-item__icon ' + iconClass + '">' + esc(icon) + '</div>';
            html += '  <div class="seo-diagnosis-item__body">';
            html += '    <div class="seo-diagnosis-item__label">' + esc(c.label) + '</div>';
            html += '    <div class="seo-diagnosis-item__score" style="color:' + scoreColor + '">' + esc(c.score) + ' / ' + esc(c.maxScore);
            if (checkDelta !== null && checkDelta !== 0) {
                html += ' ' + deltaHtml(checkDelta, true);
            }
            html += '</div>';

            if (c.findings && c.findings.length) {
                html += '<ul class="seo-diagnosis-item__findings">';
                c.findings.forEach(function(f) { html += '<li>' + esc(f) + '</li>'; });
                html += '</ul>';
            }
            if (c.affectedUrls && c.affectedUrls.length) {
                var decoded = c.affectedUrls.map(function(u) {
                    try { u = decodeURI(u); } catch(e) {}
                    // パス部分のみ表示（ドメイン除去）
                    return u.replace(/^https?:\/\/[^\/]+/, '') || '/';
                });
                html += '<div class="seo-diagnosis-item__urls">対象: ' + decoded.map(function(p) { return esc(p); }).join(', ') + '</div>';
            }
            html += '  </div></div>';
        });
        document.getElementById('seoDiagnosisGrid').innerHTML = html;
        clearDiagnosisFilter();
    }

    /* =================================================================
       Section 3: 全体評価
       ================================================================= */
    function renderAssessment(a) {
        var html = '<div class="seo-assessment">';
        html += '<div class="seo-assessment__summary">' + esc(a.summary) + '</div>';

        html += '<div class="seo-assessment__group"><div class="seo-assessment__group-title">👍 良いところ</div>';
        html += '<ul class="seo-assessment__list seo-assessment__list--good">';
        a.goodPoints.forEach(function(p) { html += '<li>' + esc(p) + '</li>'; });
        html += '</ul></div>';

        html += '<div class="seo-assessment__group"><div class="seo-assessment__group-title">👎 改善が必要なところ</div>';
        html += '<ul class="seo-assessment__list seo-assessment__list--improve">';
        a.improvementPoints.forEach(function(p) { html += '<li>' + esc(p) + '</li>'; });
        html += '</ul></div></div>';

        document.getElementById('seoAssessmentContent').innerHTML = html;
    }

    /* =================================================================
       Section 4: 問題URL一覧
       ================================================================= */
    /* --- Issues テーブル ソート状態 --- */
    var _issuesData = [];
    var _issuesSortKey = 'priority'; // デフォルト: 重要度順
    var _issuesSortAsc = true;

    function renderIssues(pages) {
        if (!pages || !pages.length) {
            document.getElementById('seoIssuesContent').innerHTML = '<div class="seo-empty">問題は検出されませんでした</div>';
            return;
        }
        _issuesData = pages;
        _renderIssuesTable();
    }

    function _sortIssues(data, key, asc) {
        var priorityOrder = { high: 0, medium: 1, low: 2 };
        var sorted = data.slice();
        sorted.sort(function(a, b) {
            var va, vb;
            if (key === 'page') {
                va = (a.pageTitle || a.url || '').toLowerCase();
                vb = (b.pageTitle || b.url || '').toLowerCase();
            } else if (key === 'category') {
                va = (a.issueType || '').toLowerCase();
                vb = (b.issueType || '').toLowerCase();
            } else { /* priority */
                va = priorityOrder[a.priority] !== undefined ? priorityOrder[a.priority] : 9;
                vb = priorityOrder[b.priority] !== undefined ? priorityOrder[b.priority] : 9;
            }
            if (va < vb) return asc ? -1 : 1;
            if (va > vb) return asc ? 1 : -1;
            /* 同値のとき副ソート: ページ → カテゴリ → 重要度 */
            if (key !== 'page') {
                var pa = (a.pageTitle || a.url || '').toLowerCase();
                var pb = (b.pageTitle || b.url || '').toLowerCase();
                if (pa < pb) return -1;
                if (pa > pb) return 1;
            }
            if (key !== 'priority') {
                var da = priorityOrder[a.priority] !== undefined ? priorityOrder[a.priority] : 9;
                var db = priorityOrder[b.priority] !== undefined ? priorityOrder[b.priority] : 9;
                return da - db;
            }
            return 0;
        });
        return sorted;
    }

    function _renderIssuesTable() {
        var sorted = _sortIssues(_issuesData, _issuesSortKey, _issuesSortAsc);
        var cols = [
            { key: 'page',     label: 'ページ' },
            { key: 'category', label: '問題カテゴリ' },
            { key: null,       label: '問題内容' },
            { key: 'priority', label: '重要度' },
            { key: null,       label: '改善候補' }
        ];
        var html = '<table class="seo-issues-table"><thead><tr>';
        cols.forEach(function(c) {
            if (c.key) {
                var active = _issuesSortKey === c.key ? ' sort-active' : '';
                var arrow = _issuesSortKey === c.key ? (_issuesSortAsc ? '▲' : '▼') : '▲';
                html += '<th data-sort="' + c.key + '" class="' + active + '">'
                     + c.label + '<span class="sort-icon">' + arrow + '</span></th>';
            } else {
                html += '<th>' + c.label + '</th>';
            }
        });
        html += '</tr></thead><tbody>';

        var prevUrl = null;
        sorted.forEach(function(p) {
            var urlDecoded = p.url;
            try { urlDecoded = decodeURI(p.url); } catch(e) {}
            var titleText = p.pageTitle || '';
            var sameAsPrev = (_issuesSortKey === 'page' && p.url === prevUrl);
            prevUrl = p.url;

            html += '<tr' + (sameAsPrev ? ' class="seo-issues-same-page"' : '') + '>';
            html += '<td class="seo-url-cell" title="' + esc(urlDecoded) + '">';
            if (titleText) {
                html += '<div class="seo-url-cell__title">' + esc(titleText) + '</div>';
                html += '<div class="seo-url-cell__path">' + esc(urlDecoded) + '</div>';
            } else {
                html += '<div class="seo-url-cell__title">' + esc(urlDecoded) + '</div>';
            }
            html += '</td>';
            html += '<td>' + esc(p.issueType) + '</td>';
            html += '<td>' + esc(p.issueDetail) + '</td>';
            html += '<td><span class="seo-priority-badge seo-priority-badge--' + esc(p.priority) + '">' + esc(PRIORITY_LABELS[p.priority] || p.priority) + '</span></td>';
            html += '<td style="font-size:12px;color:var(--mw-text-secondary);max-width:250px;">' + esc(p.suggestion) + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('seoIssuesContent').innerHTML = html;

        /* ヘッダーのクリックイベント */
        document.querySelectorAll('.seo-issues-table th[data-sort]').forEach(function(th) {
            th.addEventListener('click', function() {
                var key = this.getAttribute('data-sort');
                if (_issuesSortKey === key) {
                    _issuesSortAsc = !_issuesSortAsc;
                } else {
                    _issuesSortKey = key;
                    _issuesSortAsc = true;
                }
                _renderIssuesTable();
            });
        });
    }

    /* =================================================================
       Section 5: 改善アクション提案
       ================================================================= */
    function renderActions(recs) {
        if (!recs || !recs.length) {
            document.getElementById('seoActionsContent').innerHTML = '<div class="seo-empty">提案はありません</div>';
            return;
        }
        // 注意: priorityOrder['high'] === 0 のため (val || 9) は 0 を 9 に化けさせて
        // high が末尾に来てしまう。値が undefined のときだけ 9 にフォールバックする。
        var priorityOrder = { high: 0, medium: 1, low: 2 };
        var rank = function(p) { var v = priorityOrder[p]; return v === undefined ? 9 : v; };
        var sorted = recs.slice().sort(function(a, b) {
            return rank(a.priority) - rank(b.priority);
        });
        var html = '<div class="seo-actions-list">';
        sorted.forEach(function(r) {
            html += '<div class="seo-action-card seo-action-card--' + esc(r.priority) + '">';
            html += '<div class="seo-action-card__priority seo-action-card__priority--' + esc(r.priority) + '">' + esc(PRIORITY_LABELS[r.priority] || '?') + '</div>';
            html += '<div class="seo-action-card__body">';
            html += '<div class="seo-action-card__title">' + esc(r.title) + '</div>';
            html += '<div class="seo-action-card__desc">' + esc(r.description) + '</div>';
            html += '</div></div>';
        });
        html += '</div>';
        document.getElementById('seoActionsContent').innerHTML = html;
    }

    /* =================================================================
       Section 3.5: キーワード最適化
       ================================================================= */
    function renderKeywords(kwData) {
        var section = document.getElementById('seoKeywordSection');
        var content = document.getElementById('seoKeywordContent');
        if (!section || !content) return;

        // キーワード分析データがない場合は非表示
        if (!kwData) {
            section.style.display = 'none';
            return;
        }

        section.style.display = '';

        // キーワード未登録
        if (!kwData.keywords || kwData.keywords.length === 0) {
            content.innerHTML = '<div class="seo-kw-empty">' +
                '🔑 ターゲットキーワードが登録されていません。<br>' +
                '<a href="' + esc('/rank-tracker/') + '">順位トラッキング</a>でキーワードを設定すると、キーワード最適化の診断が利用できます。' +
                '</div>';
            return;
        }

        var coverage = kwData.coverage || [];
        var aiAnalysis = kwData.aiAnalysis || [];

        // AI分析をキーワード名でマッピング
        var aiMap = {};
        aiAnalysis.forEach(function(a) {
            if (a && a.keyword) aiMap[a.keyword] = a;
        });

        var PLACEMENT_LABELS = {
            hasTitle: 'title',
            hasH1: 'h1',
            hasDesc: 'description',
            hasH2: 'h2',
            hasBody: '本文'
        };
        var PLACEMENT_KEYS = ['hasTitle', 'hasH1', 'hasDesc', 'hasH2', 'hasBody'];

        var html = '<div class="seo-kw-grid">';

        coverage.forEach(function(kw) {
            var ai = aiMap[kw.keyword] || null;

            html += '<div class="seo-kw-card">';

            // ヘッダー: キーワード名 + マッチ数
            html += '<div class="seo-kw-card__header">';
            html += '<span class="seo-kw-card__keyword">' + esc(kw.keyword) + '</span>';
            var matchBadgeClass = kw.matchCount > 0 ? 'seo-badge--ok' : 'seo-badge--critical';
            html += '<span class="seo-badge ' + matchBadgeClass + '">' + esc(kw.matchCount) + '箇所で検出</span>';
            html += '</div>';

            // 配置バッジ
            html += '<div class="seo-kw-card__placements">';
            PLACEMENT_KEYS.forEach(function(key) {
                var found = kw[key] || false;
                var cls = found ? 'seo-kw-placement--found' : 'seo-kw-placement--missing';
                var mark = found ? '✓' : '✕';
                html += '<span class="seo-kw-placement ' + cls + '">' + mark + ' ' + esc(PLACEMENT_LABELS[key]) + '</span>';
            });
            html += '</div>';

            // マッチしたページ一覧
            if (kw.matches && kw.matches.length > 0) {
                html += '<div class="seo-kw-card__pages">';
                html += '<strong>検出ページ:</strong><br>';
                kw.matches.forEach(function(m) {
                    var locations = [];
                    if (m.inTitle) locations.push('title');
                    if (m.inH1) locations.push('h1');
                    if (m.inDesc) locations.push('description');
                    if (m.inH2) locations.push('h2');
                    if (m.inBody) locations.push('本文');
                    html += esc(m.url) + ' <span style="color:var(--mw-text-tertiary);font-size:11px;">(' + esc(locations.join(', ')) + ')</span><br>';
                });
                html += '</div>';
            }

            // ランクイン URL
            if (kw.foundUrl) {
                html += '<div style="margin-top:6px;font-size:12px;color:var(--mw-text-tertiary);">';
                html += '📍 検索結果でランクインしているURL: <span style="color:var(--mw-primary-blue);">' + esc(kw.foundUrl) + '</span>';
                html += '</div>';
            }

            // AI分析
            if (ai) {
                html += '<div class="seo-kw-card__ai">';
                if (ai.relevance) {
                    var relColor = ai.relevance === 'high' ? '#4E8A6B' : (ai.relevance === 'medium' ? '#C9A84C' : '#C95A4F');
                    html += '<strong>関連性:</strong> <span style="color:' + relColor + ';">' + esc(ai.relevance) + '</span>';
                    if (ai.best_page) {
                        html += ' &mdash; 最適ページ: <span style="color:var(--mw-primary-blue);">' + esc(ai.best_page) + '</span>';
                    }
                    html += '<br>';
                }
                if (ai.intent_match) {
                    html += '<strong>検索意図:</strong> ' + esc(ai.intent_match) + '<br>';
                }
                if (ai.suggestions && ai.suggestions.length > 0) {
                    html += '<strong>改善提案:</strong>';
                    html += '<ul>';
                    ai.suggestions.forEach(function(s) {
                        html += '<li>' + esc(s) + '</li>';
                    });
                    html += '</ul>';
                }
                html += '</div>';
            }

            html += '</div>'; // .seo-kw-card
        });

        html += '</div>'; // .seo-kw-grid
        content.innerHTML = html;
    }

    /* =================================================================
       診断実行 — POST /wp-json/gcrev/v1/seo/run-diagnosis
       ================================================================= */
    window.runSeoCheck = function() {
        showProgress('SEO診断を実行中... サイトをクロールしています。しばらくお待ちください。');
        fetch('/wp-json/gcrev/v1/seo/run-diagnosis', {
            method: 'POST',
            headers: {
                'X-WP-Nonce': wpNonce,
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            hideProgress();
            if (json.success && json.data) {
                showSections(true);
                renderAll(json.data);
                showToast('SEO診断が完了しました');
            } else {
                showToast(json.message || '診断に失敗しました', true);
            }
        })
        .catch(function(err) {
            hideProgress();
            console.error('[SEO]', err);
            showToast('通信エラーが発生しました', true);
        });
    };

})();
</script>

<?php get_footer(); ?>
