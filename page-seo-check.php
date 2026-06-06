<?php
/**
 * Template Name: SEO／AIO診断
 * Description: 検索エンジンとAI検索の両方を意識して、サイトの状態を6カテゴリで診断し改善ポイントを整理するページ
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

if ( ! mimamori_can_access_seo_check() ) {
    wp_safe_redirect( home_url( '/dashboard/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'SEO／AIO診断' );
set_query_var( 'gcrev_page_subtitle', '検索エンジンやAI検索で、ページ内容が正しく理解・表示・引用されやすい状態かを診断します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'SEO／AIO診断', '各種診断' ) );

$gcrev_seo_user_id = get_current_user_id();

get_header();
?>
<style>
/* =========================================================
   SEO／AIO診断 — スタイル
   ========================================================= */

/* サマリーカード */
.seo-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
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
.seo-summary-card--clickable { cursor: pointer; position: relative; }
.seo-summary-card--clickable::after {
    content: '詳細を見る ›';
    display: block;
    font-size: 11px;
    color: var(--mw-primary-blue);
    margin-top: 8px;
    font-weight: 600;
    opacity: 0.85;
}
.seo-summary-card--clickable:hover { border-color: var(--mw-primary-blue); }
.seo-summary-card--clickable:focus-visible { outline: 2px solid var(--mw-primary-blue); outline-offset: 2px; }
.seo-summary-card--disabled { cursor: default; opacity: 0.85; }
.seo-summary-card--disabled::after { display: none; }
.seo-summary-card__label { font-size: 13px; color: var(--mw-text-tertiary); margin-bottom: 8px; }
.seo-summary-card__value { font-size: 36px; font-weight: 700; color: var(--mw-text-heading); line-height: 1.2; }
.seo-summary-card__value--accent { color: var(--mw-primary-blue); }
.seo-summary-card__value--critical { color: #C95A4F; }
.seo-summary-card__value--warning { color: #C9A84C; }
.seo-summary-card__sub { font-size: 12px; color: var(--mw-text-tertiary); margin-top: 4px; }
.seo-score-ring {
    display: inline-flex; align-items: center; justify-content: center;
    width: 80px; height: 80px; border-radius: 50%; position: relative; margin-bottom: 4px;
}
.seo-score-ring svg { position: absolute; top: 0; left: 0; transform: rotate(-90deg); }
.seo-score-ring__value { font-size: 28px; font-weight: 700; z-index: 1; }

/* 参考値バッジ */
.seo-ref-badge {
    display: inline-block; padding: 1px 8px; border-radius: 4px;
    font-size: 11px; font-weight: 600; margin-top: 4px;
    background: var(--mw-bg-secondary); color: var(--mw-text-tertiary);
}

/* カテゴリ別スコアカード */
.seo-cat-scores {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}
.seo-cat-score-card {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-left: 4px solid var(--mw-border-light);
    border-radius: 12px;
    padding: 16px;
    cursor: pointer;
    transition: box-shadow .15s, transform .15s;
}
.seo-cat-score-card:hover { box-shadow: var(--mw-shadow-soft); transform: translateY(-1px); }
.seo-cat-score-card--ok       { border-left-color: #4E8A6B; }
.seo-cat-score-card--caution  { border-left-color: #C9A84C; }
.seo-cat-score-card--critical { border-left-color: #C95A4F; }
.seo-cat-score-card__label { font-size: 13px; font-weight: 600; color: var(--mw-text-primary); margin-bottom: 6px; }
.seo-cat-score-card__bar { height: 6px; border-radius: 3px; background: var(--mw-bg-secondary); overflow: hidden; margin-bottom: 8px; }
.seo-cat-score-card__bar > span { display: block; height: 100%; border-radius: 3px; }
.seo-cat-score-card__score { font-size: 22px; font-weight: 700; line-height: 1; }
.seo-cat-score-card__unit { font-size: 12px; font-weight: 400; color: var(--mw-text-tertiary); }

/* 注意書き */
.seo-disclaimer {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    line-height: 1.7;
    padding: 12px 16px;
    background: var(--mw-bg-secondary);
    border: 1px solid var(--mw-border-light);
    border-radius: 10px;
    margin-bottom: 24px;
}

/* バッジ */
.seo-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; }
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
    margin-bottom: 24px;
}
.seo-section__header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; gap: 12px; }
.seo-section__title { font-size: 16px; font-weight: 600; color: var(--mw-text-heading); margin: 0; }
.seo-section__note { font-size: 12px; color: var(--mw-text-tertiary); margin-top: 4px; }
.seo-section__score {
    flex-shrink: 0; text-align: right;
}
.seo-section__score-num { font-size: 26px; font-weight: 700; line-height: 1; }
.seo-section__score-unit { font-size: 12px; color: var(--mw-text-tertiary); }

/* ボタン */
.seo-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border: 1px solid var(--mw-border-light);
    background: var(--mw-bg-primary); border-radius: 8px; font-size: 14px;
    color: var(--mw-text-primary); cursor: pointer; transition: background 0.15s;
}
.seo-btn:hover { background: var(--mw-bg-secondary); }
.seo-btn--primary { background: var(--mw-primary-blue); color: #fff; border-color: var(--mw-primary-blue); transition: all 0.25s ease; }
.seo-btn--primary:hover { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.seo-btn--primary:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.seo-btn--primary:focus-visible { outline: 2px solid var(--mw-primary-blue); outline-offset: 2px; }
.seo-btn--primary:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

/* 診断項目（カテゴリ内） */
.seo-items { display: flex; flex-direction: column; gap: 10px; }
.seo-item {
    border: 1px solid var(--mw-border-light);
    border-radius: 12px;
    overflow: hidden;
    background: var(--mw-bg-primary);
}
.seo-item__head {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px; cursor: pointer; user-select: none;
}
.seo-item__head:hover { background: var(--mw-bg-secondary); }
.seo-item__state {
    flex-shrink: 0; width: 64px; text-align: center;
    padding: 3px 0; border-radius: 6px; font-size: 12px; font-weight: 700;
}
.seo-item__state--good { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.seo-item__state--caution { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-item__state--none { background: rgba(201,90,79,0.12); color: #C95A4F; }
.seo-item__state--pending { background: var(--mw-bg-secondary); color: var(--mw-text-tertiary); }
.seo-item__label { flex: 1; font-size: 14px; font-weight: 600; color: var(--mw-text-primary); }
.seo-item__imp {
    flex-shrink: 0; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px;
}
.seo-item__imp--high   { background: rgba(201,90,79,0.12); color: #C95A4F; }
.seo-item__imp--medium { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-item__imp--low    { background: rgba(86,129,132,0.12); color: #568184; }
.seo-item__toggle { flex-shrink: 0; font-size: 12px; color: var(--mw-text-tertiary); transition: transform .2s; }
.seo-item.is-open .seo-item__toggle { transform: rotate(180deg); }
.seo-item__detail { display: none; padding: 4px 16px 16px; border-top: 1px solid var(--mw-border-light); }
.seo-item.is-open .seo-item__detail { display: block; }
.seo-item__row { display: flex; gap: 10px; font-size: 13px; line-height: 1.6; padding: 6px 0; }
.seo-item__row-label {
    flex-shrink: 0; width: 84px; font-weight: 600; color: var(--mw-text-tertiary); font-size: 12px;
}
.seo-item__row-val { flex: 1; color: var(--mw-text-secondary); word-break: break-word; }
.seo-item__fix {
    margin-top: 2px; padding: 10px 12px; background: #1A2F33; color: #E8F0F0;
    border-radius: 8px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 12px; line-height: 1.6; white-space: pre-wrap; word-break: break-all;
}
.seo-item__urls {
    margin-top: 2px; font-size: 11px; color: var(--mw-text-tertiary); line-height: 1.6;
    word-break: break-all; max-height: 6em; overflow-y: auto;
}

/* フィルタバー */
.seo-filter-bar {
    display: none; align-items: center; gap: 12px;
    padding: 10px 14px; margin-bottom: 16px;
    background: rgba(86,129,132,0.08); border: 1px solid rgba(86,129,132,0.2);
    border-radius: 10px; font-size: 13px; color: var(--mw-text-primary);
}
.seo-filter-bar.is-active { display: flex; }
.seo-filter-bar__label { font-weight: 600; }
.seo-filter-bar__clear {
    margin-left: auto; background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light);
    border-radius: 6px; padding: 4px 12px; font-size: 12px; cursor: pointer; color: var(--mw-text-secondary);
}
.seo-filter-bar__clear:hover { border-color: var(--mw-primary-blue); color: var(--mw-primary-blue); }
.seo-cat-section[hidden] { display: none; }
body.seo-filter-critical .seo-item:not([data-status="critical"]) { display: none; }
body.seo-filter-caution  .seo-item:not([data-status="caution"])  { display: none; }

/* 全体評価 */
.seo-assessment { line-height: 1.7; }
.seo-assessment__summary { font-size: 14px; color: var(--mw-text-primary); margin-bottom: 20px; padding: 16px; background: var(--mw-bg-secondary); border-radius: 10px; }
.seo-assessment__group { margin-bottom: 16px; }
.seo-assessment__group-title { font-size: 14px; font-weight: 600; color: var(--mw-text-primary); margin-bottom: 8px; }
.seo-assessment__list { list-style: none; padding: 0; margin: 0; font-size: 13px; color: var(--mw-text-secondary); }
.seo-assessment__list li { padding: 4px 0; padding-left: 20px; position: relative; }
.seo-assessment__list li::before { position: absolute; left: 0; }
.seo-assessment__list--good li::before { content: '✓'; color: #4E8A6B; font-weight: 600; }
.seo-assessment__list--improve li::before { content: '!'; color: #C95A4F; font-weight: 700; }

/* 問題URLテーブル */
.seo-issues-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.seo-issues-table thead { background: var(--mw-bg-secondary); border-bottom: 2px solid var(--mw-border-light); }
.seo-issues-table th { padding: 10px 14px; font-weight: 600; color: var(--mw-text-secondary); font-size: 12px; text-align: left; white-space: nowrap; }
.seo-issues-table th[data-sort] { cursor: pointer; user-select: none; transition: color .15s; }
.seo-issues-table th[data-sort]:hover { color: var(--mw-text-primary, #333); }
.seo-issues-table th .sort-icon { display: inline-block; margin-left: 4px; font-size: 10px; opacity: .35; transition: opacity .15s; }
.seo-issues-table th.sort-active .sort-icon { opacity: 1; color: var(--mw-primary-blue, #4A90A4); }
.seo-issues-table td { padding: 14px 14px; border-bottom: 1px solid var(--mw-border-light); vertical-align: top; line-height: 1.5; }
.seo-issues-table tbody tr:hover { background: var(--mw-bg-secondary); }
.seo-issues-table tbody tr.seo-issues-same-page td { border-top: 1px dashed var(--mw-border-light); }
.seo-issues-table .seo-url-cell { min-width: 180px; max-width: 300px; color: var(--mw-primary-blue); font-weight: 500; }
.seo-url-cell__title { font-size: 13px; font-weight: 600; color: var(--mw-text-primary); line-height: 1.45; margin-bottom: 4px; word-break: break-word; }
.seo-url-cell__path { font-size: 11px; color: var(--mw-text-tertiary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 260px; }
.seo-priority-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.seo-priority-badge--high   { background: rgba(201,90,79,0.12); color: #C95A4F; }
.seo-priority-badge--medium { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-priority-badge--low    { background: rgba(86,129,132,0.12); color: #568184; }

/* アクションカード */
.seo-actions-list { display: flex; flex-direction: column; gap: 12px; }
.seo-action-card { display: flex; gap: 14px; padding: 16px; border: 1px solid var(--mw-border-light); border-radius: 12px; background: var(--mw-bg-primary); border-left: 4px solid transparent; }
.seo-action-card--high   { border-left-color: #C95A4F; }
.seo-action-card--medium { border-left-color: #C9A84C; }
.seo-action-card--low    { border-left-color: var(--mw-primary-teal); }
.seo-action-card__priority { flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #fff; }
.seo-action-card__priority--high   { background: #C95A4F; }
.seo-action-card__priority--medium { background: #C9A84C; }
.seo-action-card__priority--low    { background: var(--mw-primary-teal); }
.seo-action-card__body { flex: 1; }
.seo-action-card__title { font-size: 14px; font-weight: 600; color: var(--mw-text-heading); margin-bottom: 4px; }
.seo-action-card__desc  { font-size: 13px; color: var(--mw-text-secondary); line-height: 1.6; }

/* キーワード最適化 */
.seo-kw-grid { display: flex; flex-direction: column; gap: 16px; }
.seo-kw-card { border: 1px solid var(--mw-border-light); border-radius: 12px; padding: 20px; background: var(--mw-bg-primary); }
.seo-kw-card__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 8px; }
.seo-kw-card__keyword { font-size: 15px; font-weight: 600; color: var(--mw-text-heading); }
.seo-kw-card__placements { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.seo-kw-placement { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.seo-kw-placement--found { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.seo-kw-placement--missing { background: rgba(201,90,79,0.08); color: #C95A4F; }
.seo-kw-card__pages { font-size: 13px; color: var(--mw-text-secondary); line-height: 1.7; }
.seo-kw-card__ai { margin-top: 12px; padding: 12px 16px; background: var(--mw-bg-secondary); border-radius: 8px; font-size: 13px; color: var(--mw-text-secondary); line-height: 1.6; }
.seo-kw-card__ai strong { color: var(--mw-text-primary); }
.seo-kw-card__ai ul { margin: 4px 0 0; padding-left: 16px; }
.seo-kw-card__ai li { margin-bottom: 2px; }
.seo-kw-empty { text-align: center; padding: 32px 24px; color: var(--mw-text-tertiary); font-size: 14px; background: var(--mw-bg-secondary); border-radius: 8px; line-height: 1.8; }
.seo-kw-empty a { color: var(--mw-brand); text-decoration: none; font-weight: 600; }
.seo-kw-empty a:hover { text-decoration: underline; }

/* プログレス */
.seo-progress { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: none; align-items: center; justify-content: center; }
.seo-progress.active { display: flex; }
.seo-progress__inner { background: #fff; border-radius: 16px; padding: 40px; text-align: center; min-width: 300px; }
.seo-progress__spinner { width: 40px; height: 40px; margin: 0 auto 16px; border: 3px solid var(--mw-border-light); border-top-color: var(--mw-primary-teal); border-radius: 50%; animation: seo-spin 0.8s linear infinite; }
@keyframes seo-spin { to { transform: rotate(360deg); } }
.seo-progress__text { font-size: 14px; color: var(--mw-text-secondary); }

/* トースト */
.seo-toast { position: fixed; bottom: 24px; left: 24px; padding: 12px 20px; border-radius: 10px; background: #1A2F33; color: #fff; font-size: 14px; z-index: 10000; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
.seo-toast.active { opacity: 1; pointer-events: auto; }
.seo-toast--error { background: #C95A4F; }

/* 比較バー */
.seo-comparison-bar { display: flex; gap: 16px; padding: 12px 16px; background: var(--mw-bg-secondary); border: 1px solid var(--mw-border-light); border-radius: 8px; margin-bottom: 24px; font-size: 13px; color: var(--mw-text-secondary); align-items: center; flex-wrap: wrap; }
.seo-comparison-bar__item { display: inline-flex; align-items: center; gap: 4px; }
.seo-comparison-bar__warn { width: 100%; color: #C95A4F; font-weight: 600; }

/* AI評価保留の情報バナー */
.seo-ai-banner {
    padding: 12px 16px; margin-bottom: 24px;
    background: var(--mw-bg-secondary); border: 1px solid var(--mw-border-light);
    border-radius: 10px; font-size: 13px; line-height: 1.7; color: var(--mw-text-secondary);
}

/* スコアデルタ */
.seo-score-delta { display: inline-flex; align-items: center; gap: 2px; font-size: 13px; font-weight: 600; margin-top: 4px; }
.seo-score-delta--up { color: #4E8A6B; }
.seo-score-delta--down { color: #C95A4F; }
.seo-score-delta--same { color: var(--mw-text-tertiary); }
.seo-score-delta--sm { font-size: 11px; margin-top: 0; }

/* 空状態 */
.seo-empty { text-align: center; padding: 40px 20px; color: var(--mw-text-tertiary); font-size: 14px; }

/* テーブルラッパー */
.seo-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

/* レスポンシブ */
@media (max-width: 768px) {
    .seo-summary-cards { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .seo-summary-card { padding: 16px; }
    .seo-summary-card__value { font-size: 28px; }
    .seo-cat-scores { grid-template-columns: repeat(2, 1fr); }
    .seo-issues-table { font-size: 12px; }
    .seo-issues-table th, .seo-issues-table td { padding: 10px 8px; }
    .seo-issues-table .seo-url-cell { min-width: 140px; max-width: 220px; }
    .seo-url-cell__path { max-width: 180px; }
    .seo-section { padding: 20px 16px; }
    .seo-item__head { flex-wrap: wrap; }
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

    <!-- ===== ヘッダーアクション（PDF / CSVダウンロード等） ===== -->
    <div id="seoActionBar" style="display:none; justify-content:flex-end; gap:10px; margin-bottom:16px;">
        <button class="seo-btn" id="seoPdfBtn" type="button" style="display:inline-flex;">
            &#x1F4C4; PDF ダウンロード
        </button>
        <button class="seo-btn" id="seoCsvBtn" type="button" style="display:inline-flex;">
            &#x2B07;&#xFE0F; CSV ダウンロード
        </button>
    </div>

    <!-- ===== Section 1: サマリー ===== -->
    <div class="seo-summary-cards" id="seoSummary" style="display:none;"></div>

    <!-- ===== カテゴリ別スコア ===== -->
    <div class="seo-cat-scores" id="seoCatScores" style="display:none;"></div>

    <!-- ===== 注意書き（上部） ===== -->
    <div class="seo-disclaimer" id="seoDisclaimerTop" style="display:none;"></div>

    <!-- ===== AI分析未実施バナー ===== -->
    <div class="seo-ai-banner" id="seoAiBanner" style="display:none;"></div>

    <!-- ===== 前回比較バー ===== -->
    <div id="seoComparisonBar" style="display:none;"></div>

    <!-- ===== フィルタバー ===== -->
    <div class="seo-filter-bar" id="seoFilterBar">
        <span class="seo-filter-bar__label" id="seoFilterLabel"></span>
        <button type="button" class="seo-filter-bar__clear" id="seoFilterClear">すべて表示</button>
    </div>

    <!-- ===== Section 2: 6カテゴリ診断 ===== -->
    <div id="seoCategories" style="display:none;"></div>

    <!-- ===== Section 3: 全体評価 ===== -->
    <div class="seo-section" id="seoAssessmentSection" style="display:none;">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">📝 全体評価</h2>
                <div class="seo-section__note">診断結果を総合的にまとめた評価です（総合点は参考値です）</div>
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
                <div class="seo-section__note">重要度の高い項目から優先して対応してください</div>
            </div>
        </div>
        <div id="seoActionsContent"></div>
    </div>

    <!-- ===== 注意書き（下部） ===== -->
    <div class="seo-disclaimer" id="seoDisclaimerBottom" style="display:none;"></div>

    <!-- ===== 未診断時の表示 ===== -->
    <div class="seo-section" id="seoEmptyState" style="display:none;">
        <div style="text-align:center;padding:60px 20px;">
            <div style="font-size:48px;margin-bottom:16px;">🔍</div>
            <div style="font-size:16px;font-weight:600;color:var(--mw-text-heading);margin-bottom:8px;">まだSEO／AIO診断が実行されていません</div>
            <div style="font-size:14px;color:var(--mw-text-secondary);margin-bottom:24px;">「診断する」ボタンを押すと、検索エンジンとAI検索の両面からサイトの状態をチェックします。</div>
            <button class="seo-btn seo-btn--primary" onclick="window.runSeoCheck()" style="font-size:15px;padding:12px 28px;">
                診断する
            </button>
        </div>
    </div>

</div><!-- .content-area -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<?php
$gcrev_pdf_export_url  = get_stylesheet_directory_uri() . '/assets/js/gcrev-pdf-export.js';
$gcrev_pdf_export_path = get_stylesheet_directory() . '/assets/js/gcrev-pdf-export.js';
$gcrev_pdf_export_ver  = file_exists( $gcrev_pdf_export_path ) ? filemtime( $gcrev_pdf_export_path ) : '1';
?>
<script src="<?php echo esc_url( $gcrev_pdf_export_url . '?v=' . $gcrev_pdf_export_ver ); ?>" defer></script>

<script>
(function() {
    'use strict';

    var wpNonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
    // データ構造を v3（6カテゴリ）に刷新したため、旧キャッシュと混ざらないようキーを更新
    var SEO_CACHE_KEY = 'gcrev_seo_report_cache_v3_<?php echo (int) $gcrev_seo_user_id; ?>';
    var SEO_CACHE_TTL_MS = 24 * 60 * 60 * 1000;

    var SECTION_IDS = ['seoSummary','seoCatScores','seoDisclaimerTop','seoAiBanner','seoComparisonBar','seoCategories','seoKeywordSection','seoAssessmentSection','seoIssuesSection','seoActionsSection','seoDisclaimerBottom'];

    var STATE_LABELS = { good: '良好', caution: '要改善', none: '未設定', pending: '判定保留' };
    var IMP_LABELS   = { high: '高', medium: '中', low: '低' };
    var PRIORITY_LABELS = { high: '高', medium: '中', low: '低' };
    var CAT_ICONS = {
        seo_basic: '🔧', ogp: '🔗', structured: '🧩',
        ai_search: '🤖', crawl_index: '🕷️', content: '📄'
    };

    var DISCLAIMER = '本診断は、検索エンジンやAI検索がページ内容を理解しやすくするための改善ポイントを確認するものです。Google AI OverviewやChatGPT等での表示・引用を保証するものではありません。総合点は参考値です。実際の改善では、重要度の高い項目から優先して対応してください。';

    /* ================== ユーティリティ ================== */
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
    function hideProgress() { document.getElementById('seoProgress').classList.remove('active'); }

    function statusColor(status) {
        if (status === 'ok') return '#4E8A6B';
        if (status === 'caution') return '#C9A84C';
        return '#C95A4F';
    }
    function scoreColor(score) {
        if (score >= 80) return '#4E8A6B';
        if (score >= 50) return '#C9A84C';
        return '#C95A4F';
    }
    function deltaHtml(delta, small) {
        if (delta === null || delta === undefined || delta === 0) return '';
        var cls = delta > 0 ? 'up' : 'down';
        var arrow = delta > 0 ? '↑' : '↓';
        var sign = delta > 0 ? '+' : '';
        var sm = small ? ' seo-score-delta--sm' : '';
        return '<span class="seo-score-delta seo-score-delta--' + cls + sm + '">' + arrow + sign + delta + '</span>';
    }

    function showSections(visible) {
        SECTION_IDS.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.style.display = visible ? '' : 'none';
        });
        var empty = document.getElementById('seoEmptyState');
        if (empty) empty.style.display = visible ? 'none' : '';
        var actionBar = document.getElementById('seoActionBar');
        if (actionBar) actionBar.style.display = visible ? 'flex' : 'none';
    }

    var lastReportData = null;

    /* ================== 初期化 ================== */
    document.addEventListener('DOMContentLoaded', function() {
        var clearBtn = document.getElementById('seoFilterClear');
        if (clearBtn) clearBtn.addEventListener('click', clearFilter);
        var csvBtn = document.getElementById('seoCsvBtn');
        if (csvBtn) csvBtn.addEventListener('click', exportSeoCsv);
        var pdfBtn = document.getElementById('seoPdfBtn');
        if (pdfBtn) pdfBtn.addEventListener('click', exportSeoPdf);
        fetchReport();
    });

    /* ================== localStorage キャッシュ ================== */
    function loadCachedReport() {
        try {
            var raw = localStorage.getItem(SEO_CACHE_KEY);
            if (!raw) return null;
            var entry = JSON.parse(raw);
            if (!entry || !entry.data) return null;
            if (entry.savedAt && (Date.now() - entry.savedAt) > SEO_CACHE_TTL_MS) {
                localStorage.removeItem(SEO_CACHE_KEY);
                return null;
            }
            return entry.data;
        } catch (e) { return null; }
    }
    function saveCachedReport(data) {
        try { localStorage.setItem(SEO_CACHE_KEY, JSON.stringify({ savedAt: Date.now(), data: data })); } catch (e) {}
    }

    function fetchReport() {
        var cached = loadCachedReport();
        if (cached && cached.categories) {
            showSections(true);
            renderAll(cached);
        }
        fetch('/wp-json/gcrev/v1/seo/report', {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success && json.data && json.data.categories) {
                var sameAsCached = cached && cached.updated_at && json.data.updated_at && cached.updated_at === json.data.updated_at;
                if (!sameAsCached) { showSections(true); renderAll(json.data); }
                saveCachedReport(json.data);
            } else if (!cached || !cached.categories) {
                showSections(false);
            }
        })
        .catch(function(err) {
            console.error('[SEO/AIO]', err);
            if (!cached || !cached.categories) showSections(false);
        });
    }

    function renderAll(data) {
        lastReportData = data;
        var comp = data.comparison || null;
        var kwData = data.keywordAnalysis || null;
        renderSummary(data.siteSummary, comp, kwData);
        renderCatScores(data.siteSummary, comp);
        renderDisclaimer(data.disclaimer);
        renderAiBanner(data);
        renderComparisonBar(comp);
        renderCategories(data.categories);
        renderAssessment(data.overallAssessment);
        renderKeywords(kwData);
        renderIssues(data.issuePages, data.excludedUrls);
        renderActions(data.recommendations);
    }

    /* ================== AI分析未実施バナー ================== */
    function renderAiBanner(data) {
        var el = document.getElementById('seoAiBanner');
        if (!el) return;
        // aiEnabled が明示的に false の場合のみ表示（旧データは undefined）
        if (data.aiEnabled !== false) { el.style.display = 'none'; return; }
        var s = data.siteSummary || {};
        var pending = s.pendingCount || 0;
        el.style.display = '';
        // スコア比較への注意は比較バー側（modeChanged時のみ）で表示するため、ここでは
        // 「判定保留バッジが並ぶ理由」だけを簡潔に伝える
        el.innerHTML = 'ℹ️ 文章内容のAI評価が必要な' +
            (pending ? esc(pending) + '項目' : '項目') +
            'は、今回は「判定保留」としています（スコアへの影響はありません）。';
    }

    /* ================== 注意書き ================== */
    function renderDisclaimer(text) {
        var t = text || DISCLAIMER;
        var top = document.getElementById('seoDisclaimerTop');
        var bottom = document.getElementById('seoDisclaimerBottom');
        if (top) top.innerHTML = '⚠️ ' + esc(t);
        if (bottom) bottom.innerHTML = '⚠️ ' + esc(t);
    }

    /* ================== 前回比較バー ================== */
    function renderComparisonBar(comp) {
        var el = document.getElementById('seoComparisonBar');
        if (!el) return;
        if (!comp) { el.style.display = 'none'; return; }
        el.style.display = '';
        var html = '<div class="seo-comparison-bar">';
        html += '<div class="seo-comparison-bar__item">📊 前回診断: ' + esc(comp.previousDate) + '</div>';
        html += '<div class="seo-comparison-bar__item">スコア変動: ' + deltaHtml(comp.totalScoreDelta) + (comp.totalScoreDelta === 0 ? '<span class="seo-score-delta seo-score-delta--same">→ 変動なし</span>' : '') + '</div>';
        html += '<div class="seo-comparison-bar__item" style="color:#4E8A6B;">改善: ' + esc(comp.improvedCount) + 'カテゴリ</div>';
        html += '<div class="seo-comparison-bar__item" style="color:#C95A4F;">悪化: ' + esc(comp.worsenedCount) + 'カテゴリ</div>';
        if (comp.modeChanged) {
            html += '<div class="seo-comparison-bar__warn">⚠️ 前回と今回でAI分析の実施有無が異なるため、スコアの増減は診断モードの違いによるものです（サイトの変化を示すものではありません）。</div>';
        }
        html += '</div>';
        el.innerHTML = html;
    }

    /* ================== サマリー ================== */
    function renderSummary(s, comp, kwData) {
        var ring = scoreColor(s.totalScore);
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
        html += '  <div class="seo-summary-card__label">総合スコア</div>';
        html += '  <div class="seo-score-ring">';
        html += '    <svg width="80" height="80" viewBox="0 0 80 80">';
        html += '      <circle cx="40" cy="40" r="36" stroke="var(--mw-border-light)" stroke-width="6" fill="none"/>';
        html += '      <circle cx="40" cy="40" r="36" stroke="' + esc(ring) + '" stroke-width="6" fill="none"';
        html += '        stroke-dasharray="' + circumference + '" stroke-dashoffset="' + offset + '" stroke-linecap="round"/>';
        html += '    </svg>';
        html += '    <span class="seo-score-ring__value" style="color:' + esc(ring) + '">' + esc(s.totalScore) + '</span>';
        html += '  </div>';
        html += '  <div><span class="seo-ref-badge">参考値</span></div>';
        if (comp && comp.totalScoreDelta !== null && comp.totalScoreDelta !== 0) {
            html += '  <div>' + deltaHtml(comp.totalScoreDelta) + '点</div>';
        }
        html += '</div>';

        html += '<div' + clickAttrs('critical', hasCritical) + '>';
        html += '  <div class="seo-summary-card__label">致命的な問題</div>';
        html += '  <div class="seo-summary-card__value seo-summary-card__value--critical">' + esc(s.criticalCount) + '<span style="font-size:16px;font-weight:400;">件</span></div>';
        html += '  <div class="seo-summary-card__sub">' + (hasCritical ? '該当項目を表示' : '早急に対応が必要な項目はありません') + '</div>';
        html += '</div>';

        html += '<div' + clickAttrs('caution', hasWarning) + '>';
        html += '  <div class="seo-summary-card__label">要改善項目</div>';
        html += '  <div class="seo-summary-card__value seo-summary-card__value--warning">' + esc(s.warningCount) + '<span style="font-size:16px;font-weight:400;">件</span></div>';
        html += '  <div class="seo-summary-card__sub">' + (hasWarning ? '該当項目を表示' : '改善が必要な項目はありません') + '</div>';
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

    /* ================== カテゴリ別スコアカード ================== */
    function renderCatScores(s, comp) {
        var el = document.getElementById('seoCatScores');
        if (!el) return;
        var cats = (s && s.categoryScores) ? s.categoryScores : [];
        var per = (comp && comp.perCategory) ? comp.perCategory : {};
        var html = '';
        cats.forEach(function(c) {
            var col = scoreColor(c.score);
            var statusCls = c.status === 'ok' ? 'ok' : (c.status === 'caution' ? 'caution' : 'critical');
            var d = per[c.key] ? per[c.key].delta : null;
            html += '<div class="seo-cat-score-card seo-cat-score-card--' + statusCls + '" data-cat-jump="' + esc(c.key) + '" tabindex="0" role="button">';
            html += '  <div class="seo-cat-score-card__label">' + (CAT_ICONS[c.key] || '') + ' ' + esc(c.label) + '</div>';
            html += '  <div class="seo-cat-score-card__bar"><span style="width:' + esc(c.score) + '%;background:' + col + ';"></span></div>';
            html += '  <div class="seo-cat-score-card__score" style="color:' + col + '">' + esc(c.score) + '<span class="seo-cat-score-card__unit">点</span> ' + deltaHtml(d, true) + '</div>';
            html += '</div>';
        });
        el.innerHTML = html;
        var cards = el.querySelectorAll('[data-cat-jump]');
        for (var i = 0; i < cards.length; i++) {
            cards[i].addEventListener('click', function() { jumpToCategory(this.getAttribute('data-cat-jump')); });
            cards[i].addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); jumpToCategory(this.getAttribute('data-cat-jump')); }
            });
        }
    }

    function jumpToCategory(key) {
        var sec = document.getElementById('seoCat-' + key);
        if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* ================== サマリーカードのクリック ================== */
    function bindSummaryCardClicks(root) {
        var cards = root.querySelectorAll('.seo-summary-card--clickable');
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            card.addEventListener('click', handleSummaryCardAction);
            card.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handleSummaryCardAction.call(this, e); }
            });
        }
    }
    function handleSummaryCardAction(e) {
        var action = this.getAttribute('data-card-action');
        switch (action) {
            case 'critical': applyFilter('critical'); break;
            case 'caution':  applyFilter('caution');  break;
            case 'issues':     scrollToSection('seoIssuesSection');     break;
            case 'keywords':   scrollToSection('seoKeywordSection');    break;
            case 'assessment': scrollToSection('seoAssessmentSection'); break;
        }
    }
    function scrollToSection(id) {
        var el = document.getElementById(id);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* ================== フィルタ ================== */
    function applyFilter(status) {
        document.body.classList.remove('seo-filter-critical', 'seo-filter-caution');
        document.body.classList.add('seo-filter-' + status);
        var bar = document.getElementById('seoFilterBar');
        var lbl = document.getElementById('seoFilterLabel');
        if (lbl) lbl.textContent = (status === 'critical' ? '🚨 致命的な問題のみ表示中' : '⚠️ 要改善項目のみ表示中');
        if (bar) bar.classList.add('is-active');
        // 該当項目を開き、空のカテゴリは隠す
        document.querySelectorAll('.seo-cat-section').forEach(function(sec) {
            var match = sec.querySelectorAll('.seo-item[data-status="' + status + '"]');
            sec.hidden = (match.length === 0);
            match.forEach(function(it) { openItem(it, true); });
        });
        var first = document.querySelector('.seo-cat-section:not([hidden])');
        if (first) first.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    function clearFilter() {
        document.body.classList.remove('seo-filter-critical', 'seo-filter-caution');
        var bar = document.getElementById('seoFilterBar');
        if (bar) bar.classList.remove('is-active');
        document.querySelectorAll('.seo-cat-section').forEach(function(sec) { sec.hidden = false; });
    }

    /* ================== 6カテゴリ描画 ================== */
    function renderCategories(categories) {
        var wrap = document.getElementById('seoCategories');
        if (!wrap) return;
        if (!categories || !categories.length) { wrap.innerHTML = ''; return; }

        var html = '';
        categories.forEach(function(cat) {
            var col = scoreColor(cat.score);
            html += '<div class="seo-section seo-cat-section" id="seoCat-' + esc(cat.key) + '">';
            html += '  <div class="seo-section__header">';
            html += '    <div>';
            html += '      <h2 class="seo-section__title">' + (CAT_ICONS[cat.key] || '') + ' ' + esc(cat.label) + '</h2>';
            html += '      <div class="seo-section__note">' + esc(cat.desc) + '</div>';
            html += '    </div>';
            html += '    <div class="seo-section__score"><span class="seo-section__score-num" style="color:' + col + '">' + esc(cat.score) + '</span><span class="seo-section__score-unit">点</span></div>';
            html += '  </div>';
            html += '  <div class="seo-items">';
            (cat.items || []).forEach(function(it) { html += renderItem(it); });
            html += '  </div>';
            html += '</div>';
        });
        wrap.innerHTML = html;

        // 開閉トグル
        wrap.querySelectorAll('.seo-item__head').forEach(function(head) {
            head.addEventListener('click', function() {
                var item = this.closest('.seo-item');
                openItem(item, !item.classList.contains('is-open'));
            });
        });
    }

    function openItem(item, open) {
        if (!item) return;
        if (open) item.classList.add('is-open');
        else item.classList.remove('is-open');
    }

    function renderItem(it) {
        var state = it.state || 'caution';
        var imp = it.importance || 'low';
        var hasDetail = state !== 'good' || it.currentState;
        var h = '';
        h += '<div class="seo-item" data-status="' + esc(it.status) + '" data-state="' + esc(state) + '">';
        h += '  <div class="seo-item__head">';
        h += '    <span class="seo-item__state seo-item__state--' + esc(state) + '">' + esc(STATE_LABELS[state] || state) + '</span>';
        h += '    <span class="seo-item__label">' + esc(it.label) + '</span>';
        h += '    <span class="seo-item__imp seo-item__imp--' + esc(imp) + '">重要度：' + esc(IMP_LABELS[imp] || imp) + '</span>';
        h += '    <span class="seo-item__toggle">▾</span>';
        h += '  </div>';
        h += '  <div class="seo-item__detail">';
        if (it.currentState) h += row('現在の状態', esc(it.currentState));
        if (state !== 'good') {
            if (it.reason)     h += row('改善理由', esc(it.reason));
            if (it.suggestion) h += row('改善提案', esc(it.suggestion));
            if (it.fixExample) {
                h += '<div class="seo-item__row"><div class="seo-item__row-label">修正例</div><div class="seo-item__row-val"><div class="seo-item__fix">' + esc(it.fixExample) + '</div></div></div>';
            }
        }
        if (it.affectedUrls && it.affectedUrls.length) {
            var urls = it.affectedUrls.map(function(u) { try { u = decodeURI(u); } catch (e) {} return esc(u); }).join(', ');
            h += '<div class="seo-item__row"><div class="seo-item__row-label">対象</div><div class="seo-item__row-val"><div class="seo-item__urls">' + urls + '</div></div></div>';
        }
        h += '  </div>';
        h += '</div>';
        return h;
    }
    function row(label, valHtml) {
        return '<div class="seo-item__row"><div class="seo-item__row-label">' + esc(label) + '</div><div class="seo-item__row-val">' + valHtml + '</div></div>';
    }

    /* ================== 全体評価 ================== */
    function renderAssessment(a) {
        if (!a) { document.getElementById('seoAssessmentContent').innerHTML = ''; return; }
        var html = '<div class="seo-assessment">';
        html += '<div class="seo-assessment__summary">' + esc(a.summary) + '</div>';
        html += '<div class="seo-assessment__group"><div class="seo-assessment__group-title">👍 良いところ</div>';
        html += '<ul class="seo-assessment__list seo-assessment__list--good">';
        (a.goodPoints || []).forEach(function(p) { html += '<li>' + esc(p) + '</li>'; });
        html += '</ul></div>';
        html += '<div class="seo-assessment__group"><div class="seo-assessment__group-title">👎 改善が必要なところ</div>';
        html += '<ul class="seo-assessment__list seo-assessment__list--improve">';
        (a.improvementPoints || []).forEach(function(p) { html += '<li>' + esc(p) + '</li>'; });
        html += '</ul></div></div>';
        document.getElementById('seoAssessmentContent').innerHTML = html;
    }

    /* ================== 問題URL一覧 ================== */
    var _issuesData = [];
    var _issuesSortKey = 'priority';
    var _issuesSortAsc = true;

    function renderIssues(pages, excludedUrls) {
        var note = '';
        if (excludedUrls && excludedUrls.length) {
            var list = excludedUrls.map(function(u) { try { u = decodeURI(u); } catch (e) {} return esc(u); }).join(', ');
            note = '<div style="font-size:12px;color:var(--mw-text-tertiary);margin-bottom:12px;line-height:1.7;">' +
                'ℹ️ ユーティリティページ（' + list + '）はサイトマップ・検索結果・確認ページ等のため診断対象から除外しました。これらは noindex 化を推奨します。' +
                '</div>';
        }
        if (!pages || !pages.length) {
            document.getElementById('seoIssuesContent').innerHTML = note + '<div class="seo-empty">問題は検出されませんでした</div>';
            return;
        }
        _issuesData = pages;
        _renderIssuesTable();
        if (note) {
            var c = document.getElementById('seoIssuesContent');
            c.insertAdjacentHTML('afterbegin', note);
        }
    }
    function _sortIssues(data, key, asc) {
        var priorityOrder = { high: 0, medium: 1, low: 2 };
        var sorted = data.slice();
        sorted.sort(function(a, b) {
            var va, vb;
            if (key === 'page') { va = (a.pageTitle || a.url || '').toLowerCase(); vb = (b.pageTitle || b.url || '').toLowerCase(); }
            else if (key === 'category') { va = (a.issueType || '').toLowerCase(); vb = (b.issueType || '').toLowerCase(); }
            else { va = priorityOrder[a.priority] !== undefined ? priorityOrder[a.priority] : 9; vb = priorityOrder[b.priority] !== undefined ? priorityOrder[b.priority] : 9; }
            if (va < vb) return asc ? -1 : 1;
            if (va > vb) return asc ? 1 : -1;
            if (key !== 'page') { var pa = (a.pageTitle || a.url || '').toLowerCase(); var pb = (b.pageTitle || b.url || '').toLowerCase(); if (pa < pb) return -1; if (pa > pb) return 1; }
            if (key !== 'priority') { var da = priorityOrder[a.priority] !== undefined ? priorityOrder[a.priority] : 9; var db = priorityOrder[b.priority] !== undefined ? priorityOrder[b.priority] : 9; return da - db; }
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
                html += '<th data-sort="' + c.key + '" class="' + active + '">' + c.label + '<span class="sort-icon">' + arrow + '</span></th>';
            } else { html += '<th>' + c.label + '</th>'; }
        });
        html += '</tr></thead><tbody>';
        var prevUrl = null;
        sorted.forEach(function(p) {
            var urlDecoded = p.url;
            try { urlDecoded = decodeURI(p.url); } catch (e) {}
            var titleText = p.pageTitle || '';
            var sameAsPrev = (_issuesSortKey === 'page' && p.url === prevUrl);
            prevUrl = p.url;
            html += '<tr' + (sameAsPrev ? ' class="seo-issues-same-page"' : '') + '>';
            html += '<td class="seo-url-cell" title="' + esc(urlDecoded) + '">';
            if (titleText) {
                html += '<div class="seo-url-cell__title">' + esc(titleText) + '</div>';
                html += '<div class="seo-url-cell__path">' + esc(urlDecoded) + '</div>';
            } else { html += '<div class="seo-url-cell__title">' + esc(urlDecoded) + '</div>'; }
            html += '</td>';
            html += '<td>' + esc(p.issueType) + '</td>';
            html += '<td>' + esc(p.issueDetail) + '</td>';
            html += '<td><span class="seo-priority-badge seo-priority-badge--' + esc(p.priority) + '">' + esc(PRIORITY_LABELS[p.priority] || p.priority) + '</span></td>';
            html += '<td style="font-size:12px;color:var(--mw-text-secondary);max-width:250px;">' + esc(p.suggestion) + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('seoIssuesContent').innerHTML = html;
        document.querySelectorAll('.seo-issues-table th[data-sort]').forEach(function(th) {
            th.addEventListener('click', function() {
                var key = this.getAttribute('data-sort');
                if (_issuesSortKey === key) { _issuesSortAsc = !_issuesSortAsc; }
                else { _issuesSortKey = key; _issuesSortAsc = true; }
                _renderIssuesTable();
            });
        });
    }

    /* ================== 改善アクション ================== */
    function renderActions(recs) {
        if (!recs || !recs.length) {
            document.getElementById('seoActionsContent').innerHTML = '<div class="seo-empty">提案はありません</div>';
            return;
        }
        var priorityOrder = { high: 0, medium: 1, low: 2 };
        var rank = function(p) { var v = priorityOrder[p]; return v === undefined ? 9 : v; };
        var sorted = recs.slice().sort(function(a, b) { return rank(a.priority) - rank(b.priority); });
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

    /* ================== キーワード最適化 ================== */
    function renderKeywords(kwData) {
        var section = document.getElementById('seoKeywordSection');
        var content = document.getElementById('seoKeywordContent');
        if (!section || !content) return;
        if (!kwData) { section.style.display = 'none'; return; }
        section.style.display = '';

        if (!kwData.keywords || kwData.keywords.length === 0) {
            content.innerHTML = '<div class="seo-kw-empty">' +
                '🔑 ターゲットキーワードが登録されていません。<br>' +
                '<a href="' + esc('/rank-tracker/') + '">順位トラッキング</a>でキーワードを設定すると、キーワード最適化の診断が利用できます。' +
                '</div>';
            return;
        }

        var coverage = kwData.keywords || [];
        var aiAnalysis = kwData.aiAnalysis || [];
        var aiMap = {};
        (aiAnalysis || []).forEach(function(a) { if (a && a.keyword) aiMap[a.keyword] = a; });

        var PLACEMENT_LABELS = { hasTitle: 'title', hasH1: 'h1', hasDesc: 'description', hasBody: '本文' };
        var PLACEMENT_KEYS = ['hasTitle', 'hasH1', 'hasDesc', 'hasBody'];

        var html = '<div class="seo-kw-grid">';
        coverage.forEach(function(kw) {
            var ai = aiMap[kw.keyword] || null;
            html += '<div class="seo-kw-card">';
            html += '<div class="seo-kw-card__header">';
            html += '<span class="seo-kw-card__keyword">' + esc(kw.keyword) + '</span>';
            var matchBadgeClass = kw.matchCount > 0 ? 'seo-badge--ok' : 'seo-badge--critical';
            html += '<span class="seo-badge ' + matchBadgeClass + '">' + esc(kw.matchCount) + '箇所で検出</span>';
            html += '</div>';
            html += '<div class="seo-kw-card__placements">';
            PLACEMENT_KEYS.forEach(function(key) {
                var found = kw[key] || false;
                var cls = found ? 'seo-kw-placement--found' : 'seo-kw-placement--missing';
                var mark = found ? '✓' : '✕';
                html += '<span class="seo-kw-placement ' + cls + '">' + mark + ' ' + esc(PLACEMENT_LABELS[key]) + '</span>';
            });
            html += '</div>';
            if (kw.matches && kw.matches.length > 0) {
                html += '<div class="seo-kw-card__pages"><strong>検出ページ:</strong><br>';
                kw.matches.forEach(function(m) {
                    var locs = (m.placements || []).join(', ');
                    html += esc(m.url_path || m.url) + ' <span style="color:var(--mw-text-tertiary);font-size:11px;">(' + esc(locs) + ')</span><br>';
                });
                html += '</div>';
            }
            if (kw.found_url) {
                html += '<div style="margin-top:6px;font-size:12px;color:var(--mw-text-tertiary);">📍 検索結果でランクインしているURL: <span style="color:var(--mw-primary-blue);">' + esc(kw.found_url) + '</span></div>';
            }
            if (ai) {
                html += '<div class="seo-kw-card__ai">';
                if (ai.relevance) {
                    var relColor = ai.relevance === 'high' ? '#4E8A6B' : (ai.relevance === 'medium' ? '#C9A84C' : '#C95A4F');
                    html += '<strong>関連性:</strong> <span style="color:' + relColor + ';">' + esc(ai.relevance) + '</span>';
                    if (ai.best_page) html += ' &mdash; 最適ページ: <span style="color:var(--mw-primary-blue);">' + esc(ai.best_page) + '</span>';
                    html += '<br>';
                }
                if (ai.intent_match) html += '<strong>検索意図:</strong> ' + esc(ai.intent_match) + '<br>';
                if (ai.suggestions && ai.suggestions.length > 0) {
                    html += '<strong>改善提案:</strong><ul>';
                    ai.suggestions.forEach(function(s) { html += '<li>' + esc(s) + '</li>'; });
                    html += '</ul>';
                }
                html += '</div>';
            }
            html += '</div>';
        });
        html += '</div>';
        content.innerHTML = html;
    }

    /* ================== CSV エクスポート ================== */
    function csvEscape(v) {
        if (v === null || v === undefined) return '';
        var s = String(v);
        if (s.indexOf('"') !== -1 || s.indexOf(',') !== -1 || s.indexOf('\n') !== -1 || s.indexOf('\r') !== -1) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }
    function exportSeoCsv() {
        if (!lastReportData) { showToast('出力するデータがありません。', true); return; }
        var d = lastReportData;
        var lines = [];
        var pushRow = function(arr) { lines.push(arr.map(csvEscape).join(',')); };
        var pushSection = function(title) { if (lines.length > 0) lines.push(''); pushRow(['# ' + title]); };

        var s = d.siteSummary || {};
        pushSection('サマリー');
        pushRow(['項目', '値']);
        pushRow(['総合スコア（参考値）', s.totalScore == null ? '' : s.totalScore]);
        pushRow(['致命的な問題', s.criticalCount == null ? 0 : s.criticalCount]);
        pushRow(['要改善項目', s.warningCount == null ? 0 : s.warningCount]);
        pushRow(['判定保留項目（スコア対象外）', s.pendingCount == null ? 0 : s.pendingCount]);
        pushRow(['AI分析', d.aiEnabled === false ? '未実施（判定保留項目はスコア対象外）' : '実施']);
        pushRow(['診断対象ページ数', s.pageCount == null ? 0 : s.pageCount]);
        pushRow(['最終診断日時', s.lastCheckedAt || '']);
        if (d.excludedUrls && d.excludedUrls.length) {
            pushRow(['診断対象外（ユーティリティページ）', d.excludedUrls.join(' / ')]);
        }

        if (s.categoryScores && s.categoryScores.length) {
            pushSection('カテゴリ別スコア');
            pushRow(['カテゴリ', 'スコア', '状態']);
            var stLabel = { ok: '良好', caution: '要改善', critical: '要対応' };
            s.categoryScores.forEach(function(c) { pushRow([c.label || '', c.score == null ? '' : c.score, stLabel[c.status] || c.status || '']); });
        }

        if (d.categories && d.categories.length) {
            pushSection('診断項目');
            pushRow(['カテゴリ', '項目', 'ステータス', '重要度', '現在の状態', '改善理由', '改善提案', '修正例', '対象URL']);
            d.categories.forEach(function(cat) {
                (cat.items || []).forEach(function(it) {
                    var urls = (it.affectedUrls || []).map(function(u) { try { return decodeURI(u); } catch (e) { return u; } }).join(' / ');
                    pushRow([
                        cat.label || '',
                        it.label || '',
                        STATE_LABELS[it.state] || it.state || '',
                        IMP_LABELS[it.importance] || it.importance || '',
                        it.currentState || '',
                        it.reason || '',
                        it.suggestion || '',
                        it.fixExample || '',
                        urls
                    ]);
                });
            });
        }

        var a = d.overallAssessment || {};
        if (a.summary || (a.goodPoints && a.goodPoints.length) || (a.improvementPoints && a.improvementPoints.length)) {
            pushSection('全体評価');
            pushRow(['区分', '内容']);
            if (a.summary) pushRow(['総評', a.summary]);
            (a.goodPoints || []).forEach(function(p) { pushRow(['良いところ', p]); });
            (a.improvementPoints || []).forEach(function(p) { pushRow(['改善が必要なところ', p]); });
        }

        var kwData = d.keywordAnalysis || null;
        if (kwData && kwData.keywords && kwData.keywords.length) {
            var aiMap = {};
            (kwData.aiAnalysis || []).forEach(function(ai) { if (ai && ai.keyword) aiMap[ai.keyword] = ai; });
            pushSection('キーワード最適化');
            pushRow(['キーワード', '検出箇所数', 'title', 'h1', 'description', '本文', 'ランクインURL', 'AI関連性', '改善提案']);
            kwData.keywords.forEach(function(kw) {
                var ai = aiMap[kw.keyword] || {};
                pushRow([
                    kw.keyword || '',
                    kw.matchCount == null ? 0 : kw.matchCount,
                    kw.hasTitle ? '○' : '×',
                    kw.hasH1 ? '○' : '×',
                    kw.hasDesc ? '○' : '×',
                    kw.hasBody ? '○' : '×',
                    kw.found_url || '',
                    ai.relevance || '',
                    (ai.suggestions || []).join(' / ')
                ]);
            });
        }

        if (d.issuePages && d.issuePages.length) {
            var prioLabels = { high: '高', medium: '中', low: '低' };
            pushSection('ページ別問題一覧');
            pushRow(['URL', 'ページタイトル', '問題カテゴリ', '問題内容', '重要度', '改善候補']);
            d.issuePages.forEach(function(p) {
                var url = p.url || '';
                try { url = decodeURI(url); } catch (e) {}
                pushRow([url, p.pageTitle || '', p.issueType || '', p.issueDetail || '', prioLabels[p.priority] || p.priority || '', p.suggestion || '']);
            });
        }

        if (d.recommendations && d.recommendations.length) {
            var prioLabels2 = { high: '高', medium: '中', low: '低' };
            pushSection('改善アクション提案');
            pushRow(['優先度', 'タイトル', '説明']);
            d.recommendations.forEach(function(r) { pushRow([prioLabels2[r.priority] || r.priority || '', r.title || '', r.description || '']); });
        }

        var BOM = '﻿';
        var blob = new Blob([BOM + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var now = new Date();
        var pad = function(n) { return (n < 10 ? '0' : '') + n; };
        var dateStr = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
        var link = document.createElement('a');
        link.href = url;
        link.download = 'seo-aio_' + dateStr + '.csv';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        setTimeout(function() { document.body.removeChild(link); URL.revokeObjectURL(url); }, 100);
        showToast('CSVを書き出しました');
    }

    /* ================== PDF エクスポート ================== */
    function exportSeoPdf() {
        if (!lastReportData) { showToast('出力するデータがありません。', true); return; }
        if (typeof window.GCREV === 'undefined' || typeof GCREV.exportPdf !== 'function') {
            showToast('PDF生成ライブラリの読み込みに失敗しました。ページを再読み込みしてください。', true);
            return;
        }
        var pdfBtn = document.getElementById('seoPdfBtn');
        var csvBtn = document.getElementById('seoCsvBtn');
        var prevLabel = pdfBtn ? pdfBtn.innerHTML : '';
        if (pdfBtn) { pdfBtn.disabled = true; pdfBtn.innerHTML = 'PDF 生成中...'; }
        if (csvBtn) csvBtn.disabled = true;

        // フィルタを解除してから（隠れている項目もPDFに含める）
        clearFilter();

        // 先頭にタイトル見出しを付与
        var s = lastReportData.siteSummary || {};
        var titleEl = document.createElement('div');
        titleEl.innerHTML =
            '<div style="font-size:22px;font-weight:700;color:#1A2F33;margin-bottom:4px;">SEO／AIO診断レポート</div>' +
            '<div style="font-size:13px;color:#667;margin-bottom:6px;">診断日時: ' + esc(s.lastCheckedAt || '') +
            '　／　総合スコア（参考値）: ' + esc(s.totalScore != null ? s.totalScore : '') + '点</div>';

        // 結果セクションを順番にクローン対象へ（非表示のものは除外）
        var ids = ['seoSummary', 'seoCatScores', 'seoDisclaimerTop', 'seoAiBanner', 'seoComparisonBar',
                   'seoCategories', 'seoAssessmentSection', 'seoKeywordSection',
                   'seoIssuesSection', 'seoActionsSection', 'seoDisclaimerBottom'];
        var sources = [titleEl];
        ids.forEach(function(id) {
            var el = document.getElementById(id);
            if (el && el.style.display !== 'none') sources.push(el);
        });

        var stage = GCREV.buildPdfStage(sources, 1000);
        var restore = function() {
            if (stage && stage.parentNode) stage.parentNode.removeChild(stage);
            if (pdfBtn) { pdfBtn.disabled = false; pdfBtn.innerHTML = prevLabel; }
            if (csvBtn) csvBtn.disabled = false;
        };
        if (!stage) { restore(); showToast('PDFの生成に失敗しました。', true); return; }

        // PDF用の体裁: 全項目を開く / トグル矢印・ヒント疑似要素を隠す
        var pdfStyle = document.createElement('style');
        pdfStyle.textContent =
            '#gcrevPdfStage .seo-item__toggle{display:none!important;}' +
            '#gcrevPdfStage .seo-summary-card--clickable::after{display:none!important;}' +
            '#gcrevPdfStage .seo-item__head{cursor:default!important;}' +
            '#gcrevPdfStage .seo-section,#gcrevPdfStage .seo-summary-card{box-shadow:none!important;}';
        stage.insertBefore(pdfStyle, stage.firstChild);
        stage.querySelectorAll('.seo-item').forEach(function(it) { it.classList.add('is-open'); });

        var dateStr = (s.lastCheckedAt || '').replace(/[\/:\s]+/g, '-').replace(/-+$/, '') || 'report';
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                if (stage.scrollHeight < 100) {
                    restore();
                    showToast('レイアウトを取得できませんでした。ページを再読み込みしてください。', true);
                    return;
                }
                GCREV.exportPdf({
                    element:    stage,
                    filename:   'SEO_AIO診断_' + dateStr + '.pdf',
                    stageWidth: 1000
                }).then(function() {
                    restore();
                    showToast('PDFを書き出しました');
                }).catch(function(err) {
                    console.error('[SEO/AIO] PDF', err);
                    restore();
                    showToast((err && err.message) ? err.message : 'PDFの生成に失敗しました。もう一度お試しください。', true);
                });
            });
        });
    }

    /* ================== 診断実行 ================== */
    window.runSeoCheck = function() {
        showProgress('SEO／AIO診断を実行中... サイトをクロールしAIで分析しています。1〜2分ほどお待ちください。');
        fetch('/wp-json/gcrev/v1/seo/run-diagnosis', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            hideProgress();
            if (json.success && json.data) {
                clearFilter();
                showSections(true);
                renderAll(json.data);
                saveCachedReport(json.data);
                showToast('SEO／AIO診断が完了しました');
            } else {
                showToast(json.message || '診断に失敗しました', true);
            }
        })
        .catch(function(err) {
            hideProgress();
            console.error('[SEO/AIO]', err);
            showToast('通信エラーが発生しました', true);
        });
    };

})();
</script>

<?php get_footer(); ?>
