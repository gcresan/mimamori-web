<?php
/**
 * Template Name: キーワード調査
 * Description: クライアント情報やサイト内容をもとに、SEOで狙うべきキーワード候補を調査・提案します。
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

if ( ! mimamori_can_access_seo() ) {
    wp_safe_redirect( home_url( '/dashboard/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// クライアント情報取得
$client_settings = function_exists( 'gcrev_get_client_settings' )
    ? gcrev_get_client_settings( $user_id )
    : [];
$area_label = function_exists( 'gcrev_get_client_area_label' )
    ? gcrev_get_client_area_label( $client_settings )
    : '';
$biz_type_label = function_exists( 'gcrev_get_business_type_label' )
    ? gcrev_get_business_type_label( $client_settings['business_type'] ?? [] )
    : '';
$industry_label    = $client_settings['industry'] ?? '';
$industry_detail   = $client_settings['industry_detail'] ?? '';
$site_url          = $client_settings['site_url'] ?? '';
$persona_one_liner = $client_settings['persona_one_liner'] ?? '';

// 参考URL（競合候補）
$ref_urls = $client_settings['persona_reference_urls'] ?? [];
$has_competitor_urls = ! empty( $ref_urls );

set_query_var( 'gcrev_page_title', 'キーワード調査' );
set_query_var( 'gcrev_page_subtitle', 'サイト情報をもとに、SEOで狙うべきキーワード候補を調査・提案します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'キーワード調査', 'SEO' ) );

get_header();
?>
<style>
/* =========================================================
   キーワード調査 — スタイル (Phase 2)
   ========================================================= */

/* ページ固有: コンテンツ幅を広げ、右余白を解消 */
.content-area {
    max-width: none !important;
    padding: 44px 48px 64px;
}

.kwr-conditions {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 28px;
    margin-bottom: 28px;
}
.kwr-conditions__title {
    font-size: 16px; font-weight: 600;
    color: var(--mw-text-heading); margin: 0 0 20px;
}
.kwr-client-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px 24px; padding: 16px 20px;
    background: var(--mw-bg-secondary);
    border: 1px solid var(--mw-border-light);
    border-radius: 8px; margin-bottom: 20px; font-size: 13px;
}
.kwr-client-info__item-label { color: var(--mw-text-tertiary); font-size: 11px; margin-bottom: 2px; }
.kwr-client-info__item-value { color: var(--mw-text-heading); font-weight: 500; }

/* 競合URL */
.kwr-competitor-urls {
    padding: 14px 20px; background: var(--mw-bg-secondary);
    border: 1px solid var(--mw-border-light);
    border-radius: 8px; margin-bottom: 20px; font-size: 13px;
}
.kwr-competitor-urls__title { font-size: 12px; font-weight: 600; color: var(--mw-text-secondary); margin-bottom: 8px; }
.kwr-competitor-urls__list { list-style: none; padding: 0; margin: 0; }
.kwr-competitor-urls__list li { margin-bottom: 4px; display: flex; gap: 8px; align-items: baseline; }
.kwr-competitor-urls__url { color: var(--mw-primary-blue, #4A90A4); word-break: break-all; }
.kwr-competitor-urls__note { color: var(--mw-text-tertiary); font-size: 11px; }

/* 競合トグル */
.kwr-competitor-toggle { margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
.kwr-competitor-toggle label { font-size: 13px; font-weight: 500; color: var(--mw-text-secondary); cursor: pointer; }
.kwr-competitor-toggle input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }

.kwr-seeds { margin-bottom: 20px; }
.kwr-seeds__label { display: block; font-size: 13px; font-weight: 600; color: var(--mw-text-secondary); margin-bottom: 6px; }
.kwr-seeds textarea {
    width: 100%; max-width: 600px; padding: 10px 12px;
    border: 1px solid var(--mw-border-light); border-radius: 6px;
    font-size: 13px; resize: vertical;
    background: var(--mw-bg-primary); color: var(--mw-text-primary);
}

.kwr-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 24px; border: none; border-radius: 8px;
    font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.25s ease;
}
.kwr-btn--primary { background: var(--mw-primary-blue, #4A90A4); color: #fff; }
.kwr-btn--primary:hover { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.kwr-btn--primary:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.kwr-btn--primary:focus-visible { outline: 2px solid var(--mw-primary-blue, #4A90A4); outline-offset: 2px; }
.kwr-btn:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

.kwr-warning {
    padding: 10px 14px; background: rgba(201,90,79,0.08);
    border: 1px solid rgba(201,90,79,0.2); border-radius: 6px;
    color: #C95A4F; font-size: 13px; margin-bottom: 16px;
}

/* データソースバッジ */
.kwr-data-sources { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.kwr-ds-badge {
    display: inline-block; padding: 2px 10px; border-radius: 10px;
    font-size: 11px; font-weight: 600;
}
.kwr-ds-badge--ai { background: rgba(74,144,164,0.12); color: #2D7A8F; }
.kwr-ds-badge--gsc { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.kwr-ds-badge--dataforseo { background: rgba(201,168,76,0.15); color: #C9A84C; }
.kwr-ds-badge--competitor { background: rgba(201,90,79,0.1); color: #C95A4F; }
.kwr-ds-badge--kwplanner { background: rgba(66,133,244,0.12); color: #4285F4; }
.kwr-ds-badge--kwplanner-comp { background: rgba(234,67,53,0.1); color: #EA4335; }

/* トレンドインジケーター */
.kwr-trend { font-weight: 700; font-size: 14px; }
.kwr-trend-up { color: #27AE60; }
.kwr-trend-down { color: #C95A4F; }
.kwr-trend-stable { color: var(--mw-text-tertiary); }

/* 競合キーワード比較セクション — トグル */
.kwr-comp-kw-toggle {
    display: flex; align-items: center; gap: 8px;
    padding: 12px 16px; margin-top: 12px;
    background: var(--mw-bg-secondary); border: 1px solid var(--mw-border-light);
    border-radius: 8px; cursor: pointer; user-select: none; transition: background 0.15s;
}
.kwr-comp-kw-toggle:first-of-type { margin-top: 0; }
.kwr-comp-kw-toggle:hover { background: rgba(74,144,164,0.06); }
.kwr-comp-kw-toggle__arrow { font-size: 11px; color: var(--mw-text-tertiary); transition: transform 0.2s; flex-shrink: 0; }
.kwr-comp-kw-toggle__arrow.collapsed { transform: rotate(-90deg); }
.kwr-comp-kw-toggle__url { font-size: 13px; font-weight: 600; color: var(--mw-primary-blue, #4A90A4); word-break: break-all; }
.kwr-comp-kw-toggle__count { font-size: 12px; color: var(--mw-text-tertiary); margin-left: auto; white-space: nowrap; flex-shrink: 0; }
.kwr-comp-kw-body { display: none; padding: 4px 0 8px; }
.kwr-comp-kw-body.open { display: block; }
.kwr-comp-kw-table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 16px; }
.kwr-comp-kw-table th { padding: 6px 10px; text-align: left; font-weight: 600; color: var(--mw-text-secondary); font-size: 11px; background: var(--mw-bg-secondary); border-bottom: 1px solid var(--mw-border-light); }
.kwr-comp-kw-table td { padding: 6px 10px; border-bottom: 1px solid var(--mw-border-light); }
.kwr-comp-kw-table tbody tr:hover { background: var(--mw-bg-secondary); }

/* データ精度表示 */
.kwr-accuracy {
    padding: 10px 16px; border-radius: 8px; font-size: 13px;
    margin-bottom: 20px; display: none;
    background: var(--mw-bg-secondary); border: 1px solid var(--mw-border-light);
    color: var(--mw-text-secondary);
}

/* プログレス */
.kwr-progress {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5); z-index: 9999; display: none;
    align-items: center; justify-content: center;
}
.kwr-progress.active { display: flex; }
.kwr-progress__inner { background: #fff; border-radius: 16px; padding: 40px; text-align: center; min-width: 300px; }
.kwr-progress__spinner {
    width: 40px; height: 40px; margin: 0 auto 16px;
    border: 3px solid var(--mw-border-light, #e2e8f0);
    border-top-color: var(--mw-primary-teal, #4A90A4);
    border-radius: 50%; animation: kwr-spin 0.8s linear infinite;
}
@keyframes kwr-spin { to { transform: rotate(360deg); } }
.kwr-progress__text { font-size: 14px; color: var(--mw-text-secondary); }

/* トースト */
.kwr-toast {
    position: fixed; bottom: 24px; left: 24px; padding: 12px 20px;
    border-radius: 10px; background: #1A2F33; color: #fff;
    font-size: 14px; z-index: 10000; opacity: 0; transition: opacity 0.3s; pointer-events: none;
}
.kwr-toast.active { opacity: 1; pointer-events: auto; }
.kwr-toast--error { background: #C95A4F; }

/* サマリー共通 */
.kwr-summary {
    background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md); padding: 32px 32px 28px; margin-bottom: 28px;
}
.kwr-summary__title { font-size: 20px; font-weight: 700; color: var(--mw-text-heading); margin: 0; }
.kwr-summary__item { margin-bottom: 28px; padding-top: 24px; border-top: 1px solid var(--mw-border-light); }
.kwr-summary__item:first-child { border-top: none; padding-top: 0; }
.kwr-summary__item:last-child { margin-bottom: 0; }
.kwr-summary__item-title { font-size: 15px; font-weight: 700; color: var(--mw-text-heading); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; padding-left: 12px; border-left: 3px solid var(--mw-primary-blue, #568184); }
.kwr-summary--competitor .kwr-summary__item-title { border-left-color: var(--mw-accent-attention, #C9A84C); }
.kwr-summary__item-text { font-size: 14.5px; color: var(--mw-text-body, #263335); line-height: 1.9; }
.kwr-summary__item-text p { margin: 0 0 8px; }
.kwr-summary__item-text p:last-child { margin-bottom: 0; }

/* サマリー修飾子 — AI戦略 */
.kwr-summary--strategy { background: rgba(86,129,132,0.04); border-left: 4px solid var(--mw-primary-blue, #568184); border-top: 1px solid rgba(86,129,132,0.15); }
/* サマリー修飾子 — 競合分析 */
.kwr-summary--competitor { background: rgba(201,168,76,0.035); border-left: 4px solid var(--mw-accent-attention, #C9A84C); border-top: 1px solid rgba(201,168,76,0.18); }

/* ヘッダー */
.kwr-summary__header { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.kwr-summary__badge { font-size: 11px; font-weight: 700; padding: 3px 12px; border-radius: 12px; white-space: nowrap; letter-spacing: 0.02em; }
.kwr-summary__badge--strategy { background: rgba(86,129,132,0.15); color: #3D6264; }
.kwr-summary__badge--competitor { background: rgba(201,168,76,0.18); color: #7A5F1E; }
.kwr-summary__subtitle { font-size: 14px; color: var(--mw-text-secondary); margin: 0 0 24px; line-height: 1.6; }

/* 重要語強調（控えめ） */
.kwr-hl { background: rgba(86,129,132,0.08); color: inherit; padding: 1px 3px; border-radius: 3px; font-weight: 600; }
.kwr-summary--competitor .kwr-hl { background: rgba(201,168,76,0.10); }

/* 重要文ハイライト */
.kwr-key-sentence { display: block; background: rgba(86,129,132,0.04); border-left: 3px solid var(--mw-primary-blue, #568184); padding: 10px 16px; margin: 0 0 12px; border-radius: 0 6px 6px 0; font-weight: 600; color: var(--mw-text-heading); font-size: 14.5px; line-height: 1.8; }
.kwr-summary--competitor .kwr-key-sentence { background: rgba(201,168,76,0.04); border-left-color: var(--mw-accent-attention, #C9A84C); }

/* 次にやること */
.kwr-summary__actions { margin-top: 24px; padding: 20px 24px; background: var(--mw-bg-secondary, #F5F8F8); border-radius: 10px; border: 1px solid var(--mw-border-light); }
.kwr-summary--strategy .kwr-summary__actions { border-left: 3px solid var(--mw-primary-blue, #568184); }
.kwr-summary--competitor .kwr-summary__actions { border-left: 3px solid var(--mw-accent-attention, #C9A84C); }
.kwr-summary__actions-title { font-size: 14px; font-weight: 700; color: var(--mw-text-heading); margin: 0 0 12px; display: flex; align-items: center; gap: 6px; }
.kwr-summary__actions-list { list-style: none; margin: 0; padding: 0; }
.kwr-summary__actions-list li { font-size: 14px; color: var(--mw-text-primary, #263335); line-height: 1.8; padding: 5px 0 5px 20px; position: relative; font-weight: 500; }
.kwr-summary__actions-list li::before { content: '▸'; position: absolute; left: 0; color: var(--mw-primary-blue, #568184); font-weight: 700; font-size: 14px; }
.kwr-summary--competitor .kwr-summary__actions-list li::before { color: var(--mw-accent-attention, #C9A84C); }

/* グループ */
.kwr-group {
    background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md); margin-bottom: 20px; overflow: hidden;
}
.kwr-group__header {
    display: flex; align-items: center; gap: 10px;
    padding: 16px 20px; cursor: pointer; transition: background 0.15s; user-select: none;
}
.kwr-group__header:hover { background: var(--mw-bg-secondary); }
.kwr-group__icon { font-size: 20px; }
.kwr-group__title { font-size: 15px; font-weight: 600; margin: 0; flex: 1; }
.kwr-group__count { font-size: 13px; font-weight: 400; color: var(--mw-text-tertiary); }
.kwr-group__arrow { font-size: 12px; color: var(--mw-text-tertiary); transition: transform 0.2s; }
.kwr-group__arrow.collapsed { transform: rotate(-90deg); }
.kwr-group__body { border-top: 1px solid var(--mw-border-light); overflow-x: auto; -webkit-overflow-scrolling: touch; }

/* テーブル */
.kwr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.kwr-table th {
    padding: 10px 12px; text-align: left; font-weight: 600;
    color: var(--mw-text-secondary); font-size: 11px;
    border-bottom: 1px solid var(--mw-border-light);
    background: var(--mw-bg-secondary); white-space: nowrap;
}
.kwr-table td { padding: 10px 12px; border-bottom: 1px solid var(--mw-border-light); vertical-align: top; line-height: 1.5; }
.kwr-table tbody tr:hover { background: var(--mw-bg-secondary); }
.kwr-table .kwr-keyword-cell { font-weight: 600; color: var(--mw-text-heading); white-space: nowrap; }

/* バッジ */
.kwr-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.kwr-badge--type-core       { background: rgba(74,144,164,0.12); color: #2D7A8F; }
.kwr-badge--type-support    { background: var(--mw-bg-tertiary, #f1f5f9); color: var(--mw-text-tertiary); }
.kwr-badge--type-local      { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.kwr-badge--type-comparison { background: rgba(201,168,76,0.15); color: #C9A84C; }
.kwr-badge--type-column     { background: rgba(124,58,237,0.1); color: #7C3AED; }
.kwr-badge--type-competitor { background: rgba(201,90,79,0.1); color: #C95A4F; }
.kwr-badge--type-diff       { background: rgba(39,174,96,0.12); color: #27AE60; }

.kwr-badge--pri-high   { background: rgba(201,90,79,0.12); color: #C95A4F; }
.kwr-badge--pri-medium { background: rgba(201,168,76,0.15); color: #C9A84C; }
.kwr-badge--pri-low    { background: var(--mw-bg-tertiary, #f1f5f9); color: var(--mw-text-tertiary); }

.kwr-badge--action-improve  { background: rgba(74,144,164,0.12); color: #2D7A8F; }
.kwr-badge--action-new      { background: rgba(78,138,107,0.12); color: #4E8A6B; }

/* 記事作成ボタン */
.kwr-btn-create {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600;
    background: #4E8A6B; color: #fff; text-decoration: none;
    white-space: nowrap; transition: background 0.15s, box-shadow 0.15s;
    box-shadow: 0 1px 2px rgba(0,0,0,0.08);
}
.kwr-btn-create:hover { background: #3d7358; box-shadow: 0 2px 6px rgba(78,138,107,0.3); color: #fff; }
.kwr-btn-create:active { background: #346248; }
.kwr-btn-create svg { width: 13px; height: 13px; flex-shrink: 0; }

/* 計測キーワード追加ボタン */
.kwr-btn-track {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600;
    background: var(--mw-primary-blue, #4A90A4); color: #fff; border: none; cursor: pointer;
    white-space: nowrap; transition: background 0.15s, box-shadow 0.15s;
    box-shadow: 0 1px 2px rgba(0,0,0,0.08);
}
.kwr-btn-track:hover { background: #3a7a90; box-shadow: 0 2px 6px rgba(74,144,164,0.3); }
.kwr-btn-track:active { background: #2d6a7f; }
.kwr-btn-track:disabled { opacity: 0.5; cursor: not-allowed; }
.kwr-btn-track svg { width: 12px; height: 12px; flex-shrink: 0; }
.kwr-badge-tracked {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600;
    background: rgba(74,144,164,0.12); color: var(--mw-primary-blue, #4A90A4);
    white-space: nowrap;
}
.kwr-badge-tracked svg { width: 11px; height: 11px; flex-shrink: 0; }

.kwr-badge--action-title    { background: rgba(201,168,76,0.15); color: #C9A84C; }
.kwr-badge--action-heading  { background: rgba(124,58,237,0.1); color: #7C3AED; }
.kwr-badge--action-link     { background: rgba(201,90,79,0.08); color: #C95A4F; }

/* 難易度バッジ */
.kwr-diff-easy   { background: rgba(39,174,96,0.12); color: #27AE60; }
.kwr-diff-medium { background: rgba(201,168,76,0.15); color: #C9A84C; }
.kwr-diff-hard   { background: rgba(201,90,79,0.12); color: #C95A4F; }
.kwr-diff-ads    { background: rgba(66,133,244,0.1); color: #4285F4; font-size: 10px; }
.kwr-diff-na     { color: var(--mw-text-tertiary); font-size: 10px; }

/* CSV 出力ボタン */
.kwr-csv-export-btn {
    display: inline-flex; align-items: center; gap: 4px;
    background: #2D7A8F; color: #fff; border: none;
    padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 500;
    cursor: pointer; transition: background 0.15s;
}
.kwr-csv-export-btn:hover { background: #225e6e; }
.kwr-csv-export-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* 戦略バッジ（ROI / 勝ちやすさ） */
.kwr-roi-high { background: rgba(39,174,96,0.15); color: #15803d; font-weight: 600; }
.kwr-roi-mid  { background: rgba(201,168,76,0.15); color: #a16207; font-weight: 600; }
.kwr-roi-low  { background: rgba(201,90,79,0.12); color: #991b1b; font-weight: 600; }
.kwr-win-high { background: rgba(39,174,96,0.10); color: #27ae60; }
.kwr-win-mid  { background: rgba(201,168,76,0.12); color: #c9a84c; }
.kwr-win-low  { background: rgba(201,90,79,0.10); color: #c0392b; }

/* ソート可能ヘッダー */
.kwr-table th[data-sort], .kwr-comp-kw-table th[data-sort] {
    cursor: pointer; user-select: none; position: relative; padding-right: 18px;
}
.kwr-table th[data-sort]:hover, .kwr-comp-kw-table th[data-sort]:hover {
    background: rgba(74,144,164,0.08);
}
.kwr-table th[data-sort]::after, .kwr-comp-kw-table th[data-sort]::after {
    content: '⇅'; position: absolute; right: 4px; top: 50%; transform: translateY(-50%);
    font-size: 9px; opacity: 0.3;
}
.kwr-table th[data-sort].sort-asc::after, .kwr-comp-kw-table th[data-sort].sort-asc::after {
    content: '▲'; opacity: 0.7;
}
.kwr-table th[data-sort].sort-desc::after, .kwr-comp-kw-table th[data-sort].sort-desc::after {
    content: '▼'; opacity: 0.7;
}

/* ミニ棒グラフ */
.kwr-mini-chart { display: inline-flex; align-items: flex-end; gap: 1px; height: 16px; vertical-align: middle; margin-left: 4px; }
.kwr-mini-bar { width: 3px; border-radius: 1px 1px 0 0; background: rgba(74,144,164,0.5); min-height: 1px; transition: background 0.15s; }
.kwr-mini-bar:hover { background: rgba(74,144,164,0.9); }

/* メタ情報 */
.kwr-meta { font-size: 12px; color: var(--mw-text-tertiary); margin-top: 8px; }

/* 空状態 */
.kwr-empty { text-align: center; padding: 48px 20px; color: var(--mw-text-tertiary); }
.kwr-empty__icon { font-size: 40px; margin-bottom: 12px; }
.kwr-empty__text { font-size: 15px; margin-bottom: 8px; color: var(--mw-text-secondary); }
.kwr-empty__sub { font-size: 13px; }

.kwr-results-title {
    font-size: 18px; font-weight: 600;
    color: var(--mw-text-heading); margin: 0 0 20px;
    display: flex; align-items: center; gap: 8px;
}

/* ボリューム列 */
.kwr-vol-cell { text-align: right; font-size: 12px; white-space: nowrap; }
.kwr-comp-bar { display: inline-block; height: 6px; border-radius: 3px; background: var(--mw-border-light); width: 40px; position: relative; vertical-align: middle; }
.kwr-comp-bar__fill { position: absolute; left: 0; top: 0; height: 100%; border-radius: 3px; background: #C9A84C; }

/* 案内文 */
.kwr-intro {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 28px;
    margin-bottom: 28px;
}
.kwr-intro__title {
    font-size: 17px; font-weight: 700;
    color: var(--mw-text-heading); margin: 0 0 6px;
}
.kwr-intro__lead {
    font-size: 14px; color: var(--mw-text-secondary);
    margin: 0 0 20px; line-height: 1.6;
}
.kwr-intro__features {
    display: grid; gap: 16px;
}
.kwr-intro__feature {
    display: flex; gap: 14px; align-items: flex-start;
}
.kwr-intro__feature-icon {
    flex-shrink: 0; width: 28px; height: 28px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; font-size: 13px; font-weight: 700;
    background: rgba(74,144,164,0.1); color: #2D7A8F;
}
.kwr-intro__feature-title {
    font-size: 14px; font-weight: 600;
    color: var(--mw-text-heading); margin-bottom: 2px;
}
.kwr-intro__feature-desc {
    font-size: 13px; color: var(--mw-text-secondary);
    line-height: 1.6;
}

@media (max-width: 1024px) {
    .content-area { padding: 32px 32px 48px; }
}
@media (max-width: 768px) {
    .content-area { padding: 20px 16px 32px; }
    .kwr-intro { padding: 20px 16px; }
    .kwr-conditions { padding: 20px 16px; }
    .kwr-summary { padding: 24px 18px 20px; }
    .kwr-summary__header { flex-wrap: wrap; }
    .kwr-summary__title { font-size: 18px; }
    .kwr-summary__item-title { font-size: 14px; }
    .kwr-client-info { grid-template-columns: 1fr 1fr; }
    .kwr-group__header { padding: 14px 16px; }
    .kwr-table th, .kwr-table td { padding: 8px 6px; }
    .kwr-mini-chart { display: none; }
}
</style>

<div class="content-area">

    <!-- ===== 案内文 ===== -->
    <div class="kwr-intro">
        <h2 class="kwr-intro__title">キーワード調査でできること</h2>
        <p class="kwr-intro__lead">あなたのサイトに合ったSEOキーワードを、Googleの実データとAIで自動提案します。</p>
        <div class="kwr-intro__features">
            <div class="kwr-intro__feature">
                <span class="kwr-intro__feature-icon">1</span>
                <div>
                    <div class="kwr-intro__feature-title">狙うべきキーワードの発見</div>
                    <div class="kwr-intro__feature-desc">業種・エリア・サイト内容をもとに、今すぐ狙えるキーワードからコラム記事向けのロングテールまで、グループ別に提案します。</div>
                </div>
            </div>
            <div class="kwr-intro__feature">
                <span class="kwr-intro__feature-icon">2</span>
                <div>
                    <div class="kwr-intro__feature-title">Google の実データで裏付け</div>
                    <div class="kwr-intro__feature-desc">各キーワードの月間検索数・競合度・検索トレンド（12ヶ月推移）を Google Keyword Planner の実データで表示します。</div>
                </div>
            </div>
            <div class="kwr-intro__feature">
                <span class="kwr-intro__feature-icon">3</span>
                <div>
                    <div class="kwr-intro__feature-title">競合サイトとのキーワード比較</div>
                    <div class="kwr-intro__feature-desc">参考URLに登録した競合サイトが狙っているキーワードを分析し、自社との重複・ギャップを可視化します。</div>
                </div>
            </div>
            <div class="kwr-intro__feature">
                <span class="kwr-intro__feature-icon">4</span>
                <div>
                    <div class="kwr-intro__feature-title">AIによる戦略サマリー</div>
                    <div class="kwr-intro__feature-desc">すべてのデータを総合的に分析し、優先すべきキーワードの方向性・改善すべきページ・新規ページ案をAIが提案します。</div>
                </div>
            </div>
        </div>
    </div>

    <!-- プログレスオーバーレイ -->
    <div class="kwr-progress" id="kwrProgress">
        <div class="kwr-progress__inner">
            <div class="kwr-progress__spinner"></div>
            <div class="kwr-progress__text" id="kwrProgressText">AIがキーワード候補を分析中です…</div>
        </div>
    </div>

    <!-- トースト -->
    <div class="kwr-toast" id="kwrToast"></div>

    <!-- ===== 条件エリア ===== -->
    <div class="kwr-conditions">
        <h2 class="kwr-conditions__title">調査条件</h2>

        <!-- クライアント情報 -->
        <?php
        $info_items = array_filter( [
            'サイトURL'     => $site_url,
            'エリア'        => $area_label,
            '業種'          => $industry_label,
            '業種詳細'      => $industry_detail,
            'ビジネス形態'  => $biz_type_label,
            'ペルソナ概要'  => $persona_one_liner,
        ] );
        if ( ! empty( $info_items ) ) :
        ?>
            <div class="kwr-client-info">
                <?php foreach ( $info_items as $lbl => $val ) : ?>
                    <div>
                        <div class="kwr-client-info__item-label"><?php echo esc_html( $lbl ); ?></div>
                        <div class="kwr-client-info__item-value"><?php echo esc_html( $val ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- 競合・参考URL -->
        <?php if ( $has_competitor_urls ) : ?>
            <div class="kwr-competitor-urls">
                <div class="kwr-competitor-urls__title">参考URL（競合・理想サイト）</div>
                <ul class="kwr-competitor-urls__list">
                    <?php foreach ( $ref_urls as $ref ) :
                        $ref_url  = $ref['url'] ?? '';
                        $ref_note = $ref['note'] ?? '';
                        if ( empty( $ref_url ) ) continue;
                    ?>
                        <li>
                            <span class="kwr-competitor-urls__url"><?php echo esc_html( $ref_url ); ?></span>
                            <?php if ( $ref_note ) : ?>
                                <span class="kwr-competitor-urls__note">(<?php echo esc_html( $ref_note ); ?>)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="kwr-competitor-toggle">
                <input type="checkbox" id="kwrCompetitor" checked>
                <label for="kwrCompetitor">競合分析を含める（参考URLのサイトを解析して比較提案を行います）</label>
            </div>
        <?php endif; ?>

        <?php if ( empty( $site_url ) ) : ?>
            <div class="kwr-warning">
                サイトURLが未設定です。設定画面でサイトURLを登録してから調査を実行してください。
            </div>
        <?php endif; ?>

        <!-- シードキーワード -->
        <div class="kwr-seeds">
            <label class="kwr-seeds__label" for="kwrSeeds">追加キーワード（任意）</label>
            <textarea id="kwrSeeds" rows="2"
                      placeholder="調べたいキーワードがあれば入力（カンマまたは改行区切り）"></textarea>
        </div>

        <!-- 実行ボタン -->
        <button id="kwrRunBtn" class="kwr-btn kwr-btn--primary" type="button"
                <?php echo empty( $site_url ) ? 'disabled' : ''; ?>>
            キーワード調査を実行
        </button>
        <div id="kwrMeta" class="kwr-meta"></div>
    </div>

    <!-- データ精度表示 -->
    <div class="kwr-accuracy" id="kwrAccuracy"></div>

    <!-- ===== AI戦略サマリー ===== -->
    <div class="kwr-summary kwr-summary--strategy" id="kwrSummary" style="display:none;">
        <div class="kwr-summary__header">
            <h2 class="kwr-summary__title">🧭 攻め方の整理</h2>
            <span class="kwr-summary__badge kwr-summary__badge--strategy">まず確認</span>
        </div>
        <p class="kwr-summary__subtitle">このキーワードで、どこを優先的に狙うべきかをAIが整理しました</p>
        <div id="kwrSummaryContent"></div>
    </div>

    <!-- ===== 競合分析サマリー ===== -->
    <div class="kwr-summary kwr-summary--competitor" id="kwrCompSummary" style="display:none;">
        <div class="kwr-summary__header">
            <h2 class="kwr-summary__title">⚔️ 競合との比較</h2>
            <span class="kwr-summary__badge kwr-summary__badge--competitor">勝ち筋を見る</span>
        </div>
        <p class="kwr-summary__subtitle">競合の傾向と、自社が差をつけやすいポイントをまとめています</p>
        <div id="kwrCompSummaryContent"></div>
    </div>

    <!-- ===== グループ別キーワード一覧 ===== -->
    <div id="kwrResults" style="display:none;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px;flex-wrap:wrap;">
            <h2 class="kwr-results-title" style="margin:0;">キーワード候補一覧</h2>
            <button type="button" id="kwrCsvExportBtn" class="kwr-csv-export-btn" title="調査結果を CSV 形式で書き出します">
                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px;"><path d="M3 17a1 1 0 001 1h12a1 1 0 001-1v-4a1 1 0 10-2 0v3H5v-3a1 1 0 10-2 0v4z"/><path d="M9 12.586V3a1 1 0 112 0v9.586l2.293-2.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 12.586z"/></svg>
                CSV で書き出す
            </button>
        </div>
        <div id="kwrGroups"></div>
    </div>

    <!-- ===== 競合キーワード比較（Keyword Planner） ===== -->
    <div class="kwr-summary" id="kwrCompKeywords" style="display:none;">
        <h2 class="kwr-summary__title">競合キーワード比較（Google Keyword Planner）</h2>
        <div id="kwrCompKeywordsContent"></div>
    </div>

    <!-- ===== 空状態 ===== -->
    <div class="kwr-empty" id="kwrEmpty">
        <div class="kwr-empty__icon">🔍</div>
        <div class="kwr-empty__text">キーワード調査を実行してください</div>
        <div class="kwr-empty__sub">クライアント情報やサイト内容をもとに、AIがSEOキーワード候補を提案します</div>
    </div>

</div>

<script>
(function() {
    'use strict';

    var userId = <?php echo (int) $user_id; ?>;
    var restUrl = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gcrev/v1/seo/keyword-research' ) ) ); ?>;
    var rankTrackerUrl = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gcrev/v1/rank-tracker/my-keywords' ) ) ); ?>;
    var writingUrl = <?php echo wp_json_encode( esc_url( home_url( '/writing/' ) ) ); ?>;
    var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    var btn = document.getElementById('kwrRunBtn');
    if (!btn) return;

    /* 計測キーワード管理 */
    var trackedKeywords = [];   // 登録済みキーワード文字列の配列（小文字正規化）
    var trackCanAdd     = true; // 上限に達していないか
    var trackLoaded     = false;

    function loadTrackedKeywords() {
        return fetch(rankTrackerUrl, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.success && json.data) {
                trackedKeywords = (json.data.keywords || []).map(function(k) {
                    return k.keyword.toLowerCase();
                });
                trackCanAdd = json.data.can_add;
            }
            trackLoaded = true;
        })
        .catch(function() { trackLoaded = true; });
    }

    function isKeywordTracked(keyword) {
        return trackedKeywords.indexOf(keyword.toLowerCase()) !== -1;
    }

    function addToTracker(keyword, btnEl) {
        if (!trackCanAdd) {
            alert('計測キーワードの登録上限に達しています。不要なキーワードを削除してから追加してください。');
            return;
        }
        btnEl.disabled = true;
        btnEl.textContent = '追加中…';
        fetch(rankTrackerUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({ keyword: keyword })
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.success) {
                trackedKeywords.push(keyword.toLowerCase());
                // ボタンをバッジに差し替え
                var td = btnEl.parentNode;
                td.innerHTML = trackBadgeHtml();
                // 上限再チェック
                loadTrackedKeywords();
            } else {
                alert(json.message || '追加に失敗しました。');
                btnEl.disabled = false;
                btnEl.innerHTML = trackBtnInner();
            }
        })
        .catch(function() {
            alert('通信エラーが発生しました。');
            btnEl.disabled = false;
            btnEl.innerHTML = trackBtnInner();
        });
    }

    function trackBtnInner() {
        return '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"/></svg>計測に追加';
    }

    function trackBadgeHtml() {
        return '<span class="kwr-badge-tracked"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>追加済み</span>';
    }

    // ページ読み込み時に登録済みキーワードを取得（Promise を保持して待機可能に）
    var trackedReady = loadTrackedKeywords();

    /* グループ定義 */
    var groupMeta = {
        immediate:           { icon: '🎯', label: '今すぐ狙うべきキーワード',                 color: '#C95A4F' },
        local_seo:           { icon: '📍', label: '地域SEO向けキーワード',                     color: '#4E8A6B' },
        comparison:          { icon: '🔄', label: '比較・検討流入向けキーワード',               color: '#C9A84C' },
        column:              { icon: '📝', label: 'コラム記事向きキーワード',                   color: '#7C3AED' },
        service_page:        { icon: '🛠', label: 'サービスページ向きキーワード',              color: '#2D7A8F' },
        traffic_expansion:   { icon: '🌱', label: '集客拡張キーワード（潜在層・認知獲得）',    color: '#5B8DEF' },
        competitor_core:     { icon: '⚔️', label: '競合も狙っている本命キーワード',            color: '#E74C3C' },
        competitor_longterm: { icon: '🏔️', label: '競合が強いが中長期で狙うべきキーワード',    color: '#8E44AD' },
        competitor_gap:      { icon: '✨', label: '競合が弱く自社が狙いやすいキーワード',       color: '#27AE60' },
        competitor_compare:  { icon: '⚖️', label: '比較検討流入を取れるキーワード',             color: '#F39C12' },
        excluded:            { icon: '🚫', label: '自社と関連性が低く除外したキーワード',      color: '#95A5A6' }
    };
    var groupOrder = [
        'immediate', 'competitor_gap', 'local_seo', 'competitor_core',
        'comparison', 'competitor_compare', 'service_page', 'column',
        'traffic_expansion', 'competitor_longterm',
        'excluded'
    ];

    /* バッジマッピング */
    var typeClass = {
        '本命': 'kwr-badge--type-core', '補助': 'kwr-badge--type-support',
        'ローカルSEO': 'kwr-badge--type-local', '比較流入': 'kwr-badge--type-comparison',
        'コラム向け': 'kwr-badge--type-column', '競合重複': 'kwr-badge--type-competitor',
        '差別化': 'kwr-badge--type-diff'
    };
    var priClass = { '高': 'kwr-badge--pri-high', '中': 'kwr-badge--pri-medium', '低': 'kwr-badge--pri-low' };
    var actClass = {
        '既存ページ改善': 'kwr-badge--action-improve', '新規ページ追加': 'kwr-badge--action-new',
        'タイトル改善': 'kwr-badge--action-title', '見出し追加': 'kwr-badge--action-heading',
        '内部リンク強化': 'kwr-badge--action-link',
        '新規記事作成':     'kwr-badge--action-new',
        'コンテンツ蓄積型': 'kwr-badge--action-link',
        '補助記事':         'kwr-badge--action-heading',
        '検証用コンテンツ': 'kwr-badge--action-heading'
    };

    function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function badge(text, map) {
        var cls = map[text] || 'kwr-badge--type-support';
        return '<span class="kwr-badge ' + cls + '">' + esc(text) + '</span>';
    }
    function fmtVol(v) {
        if (v === null || v === undefined) return '<span style="color:var(--mw-text-tertiary);">-</span>';
        return Number(v).toLocaleString();
    }
    function fmtComp(v, compIndex) {
        // competition_index (0-100) を優先表示
        if (compIndex !== null && compIndex !== undefined) {
            var ci = Math.round(compIndex);
            return '<span class="kwr-comp-bar"><span class="kwr-comp-bar__fill" style="width:' + ci + '%;"></span></span> <span style="font-size:11px;">' + ci + '</span>';
        }
        // フォールバック: competition (0-1)
        if (v === null || v === undefined) return '<span style="color:var(--mw-text-tertiary);">-</span>';
        var pct = Math.round(v * 100);
        return '<span class="kwr-comp-bar"><span class="kwr-comp-bar__fill" style="width:' + pct + '%;"></span></span> <span style="font-size:11px;">' + pct + '</span>';
    }
    function fmtTrend(monthlyVolumes) {
        if (!monthlyVolumes || monthlyVolumes.length < 4) return '<span style="color:var(--mw-text-tertiary);">-</span>';
        var recent = monthlyVolumes.slice(-3);
        var prev = monthlyVolumes.slice(-6, -3);
        if (prev.length === 0) prev = monthlyVolumes.slice(0, 3);
        var recentAvg = recent.reduce(function(s, m) { return s + (m.searches || 0); }, 0) / recent.length;
        var prevAvg = prev.reduce(function(s, m) { return s + (m.searches || 0); }, 0) / prev.length;
        if (prevAvg === 0 && recentAvg === 0) return '<span class="kwr-trend kwr-trend-stable" title="データ不足">→</span>';
        var change = prevAvg > 0 ? ((recentAvg - prevAvg) / prevAvg * 100) : (recentAvg > 0 ? 100 : 0);
        var arrow;
        if (change > 10) arrow = '<span class="kwr-trend kwr-trend-up" title="上昇傾向 (+' + Math.round(change) + '%)">↑</span>';
        else if (change < -10) arrow = '<span class="kwr-trend kwr-trend-down" title="下降傾向 (' + Math.round(change) + '%)">↓</span>';
        else arrow = '<span class="kwr-trend kwr-trend-stable" title="安定 (' + (change >= 0 ? '+' : '') + Math.round(change) + '%)">→</span>';
        // ミニ棒グラフ
        var maxV = 0;
        monthlyVolumes.forEach(function(m) { if ((m.searches || 0) > maxV) maxV = m.searches; });
        if (maxV === 0) return arrow;
        var bars = '<span class="kwr-mini-chart">';
        monthlyVolumes.forEach(function(m) {
            var h = Math.max(1, Math.round((m.searches || 0) / maxV * 16));
            var label = (m.year || '') + '/' + (m.month || '') + ': ' + (m.searches || 0).toLocaleString();
            bars += '<span class="kwr-mini-bar" style="height:' + h + 'px;" title="' + label + '"></span>';
        });
        bars += '</span>';
        return arrow + bars;
    }
    function getTrendValue(monthlyVolumes) {
        if (!monthlyVolumes || monthlyVolumes.length < 4) return null;
        var recent = monthlyVolumes.slice(-3);
        var prev = monthlyVolumes.slice(-6, -3);
        if (prev.length === 0) prev = monthlyVolumes.slice(0, 3);
        var recentAvg = recent.reduce(function(s, m) { return s + (m.searches || 0); }, 0) / recent.length;
        var prevAvg = prev.reduce(function(s, m) { return s + (m.searches || 0); }, 0) / prev.length;
        return prevAvg > 0 ? ((recentAvg - prevAvg) / prevAvg * 100) : (recentAvg > 0 ? 100 : 0);
    }
    function fmtDiff(v, compIndex, source) {
        if (v !== null && v !== undefined) {
            var n = Math.round(v);
            var cls = n <= 30 ? 'kwr-diff-easy' : (n <= 60 ? 'kwr-diff-medium' : 'kwr-diff-hard');
            var mark = (source === 'brightdata_serp')
                ? '<sup title="SERP分析による推定値" style="font-size:9px;color:#888;margin-left:2px;">SERP</sup>'
                : '';
            return '<span class="kwr-badge ' + cls + '">' + n + '</span>' + mark;
        }
        if (compIndex !== null && compIndex !== undefined) {
            return '<span class="kwr-badge kwr-diff-ads" title="SEO難易度は未取得。広告競争度を参考表示">広告競争度:' + Math.round(compIndex) + '</span>';
        }
        return '<span class="kwr-diff-na" title="データなし">-</span>';
    }
    /* ===== ソートユーティリティ ===== */
    function kwrSortItems(items, key, dir, type) {
        if (!dir) return items.slice(); // デフォルト順
        return items.slice().sort(function(a, b) {
            var va = (type === 'trend') ? getTrendValue(a[key]) : a[key];
            var vb = (type === 'trend') ? getTrendValue(b[key]) : b[key];
            var aNull = (va === null || va === undefined);
            var bNull = (vb === null || vb === undefined);
            if (aNull && bNull) return 0;
            if (aNull) return 1;
            if (bNull) return -1;
            var cmp;
            if (type === 'number' || type === 'trend') {
                cmp = Number(va) - Number(vb);
            } else {
                cmp = String(va).localeCompare(String(vb), 'ja');
            }
            return dir === 'asc' ? cmp : -cmp;
        });
    }
    function nextSortDir(current) {
        if (!current) return 'asc';
        if (current === 'asc') return 'desc';
        return '';
    }

    function showProgress(msg) {
        document.getElementById('kwrProgressText').textContent = msg || 'AIがキーワード候補を分析中です…';
        document.getElementById('kwrProgress').classList.add('active');
    }
    function hideProgress() { document.getElementById('kwrProgress').classList.remove('active'); }
    function showToast(msg, isError) {
        var el = document.getElementById('kwrToast');
        el.textContent = msg; el.className = 'kwr-toast active' + (isError ? ' kwr-toast--error' : '');
        setTimeout(function() { el.className = 'kwr-toast'; }, 4000);
    }

    /* ===== 調査実行 ===== */
    btn.addEventListener('click', function() {
        var seeds = (document.getElementById('kwrSeeds').value || '').trim();
        var compEl = document.getElementById('kwrCompetitor');
        var enableComp = compEl ? compEl.checked : false;

        btn.disabled = true;
        showProgress(enableComp ? '競合サイトの分析中です…（1〜2分程度）' : 'AIがキーワード候補を分析中です…（30秒〜1分程度）');
        document.getElementById('kwrEmpty').style.display = 'none';
        document.getElementById('kwrSummary').style.display = 'none';
        document.getElementById('kwrCompSummary').style.display = 'none';
        document.getElementById('kwrCompKeywords').style.display = 'none';
        document.getElementById('kwrResults').style.display = 'none';
        document.getElementById('kwrAccuracy').style.display = 'none';
        document.getElementById('kwrMeta').innerHTML = '';

        fetch(restUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({ user_id: userId, seed_keywords: seeds, enable_competitor: enableComp })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hideProgress();
            btn.disabled = false;
            if (!data.success) {
                showToast(data.error || 'エラーが発生しました', true);
                document.getElementById('kwrEmpty').style.display = '';
                return;
            }
            // 計測キーワード最新状態を取得してから描画
            loadTrackedKeywords().then(function() {
                renderAll(data);
                btn.textContent = '再調査を実行';
            });
        })
        .catch(function(err) {
            hideProgress();
            btn.disabled = false;
            showToast('通信エラー: ' + (err.message || '不明'), true);
            document.getElementById('kwrEmpty').style.display = '';
        });
    });

    /* ===== ページ読み込み時に前回結果を取得（計測キーワード取得完了後に描画） ===== */
    Promise.all([
        trackedReady,
        fetch(restUrl, {
            method: 'GET',
            headers: { 'X-WP-Nonce': nonce }
        }).then(function(r) { return r.json(); })
    ])
    .then(function(results) {
        var data = results[1];
        if (data && data.success) {
            renderAll(data);
            btn.textContent = '再調査を実行';
            if (data.is_cached) {
                var meta = data.meta || {};
                var metaEl = document.getElementById('kwrMeta');
                metaEl.innerHTML = '前回の調査結果（' + esc(meta.generated_at || '') + '）';
            }
        }
    })
    .catch(function() { /* 前回結果なし — 空状態のまま */ });

    /* ===== CSV 出力（全グループ・全KW・全フィールド） ===== */
    var lastRenderedData = null;

    function csvEscape(v) {
        if (v === null || v === undefined) return '';
        var s = String(v);
        // ダブルクォート・カンマ・改行を含む場合は "" で囲み、内部の " は "" にエスケープ
        if (s.indexOf('"') !== -1 || s.indexOf(',') !== -1 || s.indexOf('\n') !== -1 || s.indexOf('\r') !== -1) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    function fmtMonthlyForCsv(mv) {
        if (!Array.isArray(mv) || mv.length === 0) return '';
        // 最新3ヶ月 / 以前3ヶ月 の平均変化率だけを簡易表現
        var recent = mv.slice(-3);
        var prev = mv.slice(-6, -3);
        if (prev.length === 0) prev = mv.slice(0, 3);
        var avg = function(arr) {
            if (!arr.length) return 0;
            return arr.reduce(function(s, m) { return s + (m.searches || 0); }, 0) / arr.length;
        };
        var r = avg(recent), p = avg(prev);
        if (p === 0 && r === 0) return '安定';
        var change = p > 0 ? ((r - p) / p * 100) : (r > 0 ? 100 : 0);
        var sign = change >= 0 ? '+' : '';
        if (change > 10) return '上昇(' + sign + Math.round(change) + '%)';
        if (change < -10) return '下降(' + sign + Math.round(change) + '%)';
        return '安定(' + sign + Math.round(change) + '%)';
    }

    function buildCsvFromData(data) {
        // ヘッダー（日本語、Excel 互換）
        var headers = [
            'グループ', 'キーワード', 'タイプ', '優先度',
            '推奨ページ', 'アクション',
            '検索ボリューム', 'トレンド', '競合度(0-100)', '競合度(文字)',
            '難易度', '難易度ソース', '難易度バンド', 'ボリュームバンド',
            '関連度(0-100)', 'ビジネス適合(0-100)', '検索意図', 'CV距離',
            '勝ちやすさ', 'ROIスコア',
            'NG判定', 'NG深刻度',
            'CPC', '現在順位',
            '提案理由', '除外理由'
        ];

        var groupMetaLabels = {
            immediate:           '今すぐ狙うべき',
            local_seo:           '地域SEO向け',
            comparison:          '比較・検討流入',
            column:              'コラム記事向き',
            service_page:        'サービスページ向き',
            traffic_expansion:   '集客拡張（潜在層）',
            competitor_core:     '競合も狙う本命',
            competitor_longterm: '競合強・中長期狙い',
            competitor_gap:      '競合弱・狙い目',
            competitor_compare:  '比較検討で流入',
            excluded:            '除外'
        };

        var lines = [ headers.map(csvEscape).join(',') ];
        var groups = (data && data.groups) || {};

        // 表示順: excluded を最後に、それ以外は groupOrder 順
        var order = groupOrder.slice();
        Object.keys(groups).forEach(function(k) { if (order.indexOf(k) < 0) order.push(k); });

        order.forEach(function(gk) {
            var items = groups[gk] || [];
            if (!Array.isArray(items) || items.length === 0) return;
            var gLabel = groupMetaLabels[gk] || gk;
            items.forEach(function(it) {
                var row = [
                    gLabel,
                    it.keyword || '',
                    it.type || '',
                    it.priority || '',
                    it.page_type || '',
                    it.action || '',
                    (it.volume === null || it.volume === undefined) ? '' : it.volume,
                    fmtMonthlyForCsv(it.monthly_volumes),
                    (it.competition_index === null || it.competition_index === undefined) ? '' : it.competition_index,
                    (it.competition === null || it.competition === undefined) ? '' : it.competition,
                    (it.difficulty === null || it.difficulty === undefined) ? '' : it.difficulty,
                    it.difficulty_source || '',
                    it.difficulty_band || '',
                    it.volume_band || '',
                    (it.relevance_score === null || it.relevance_score === undefined) ? '' : it.relevance_score,
                    (it.business_fit === null || it.business_fit === undefined) ? '' : it.business_fit,
                    it.intent || '',
                    it.cv_distance || '',
                    (it.win_probability === null || it.win_probability === undefined) ? '' : it.win_probability,
                    (it.roi_score === null || it.roi_score === undefined) ? '' : it.roi_score,
                    it.ng_type || '',
                    it.ng_severity || '',
                    (it.cpc === null || it.cpc === undefined) ? '' : it.cpc,
                    (it.current_rank === null || it.current_rank === undefined) ? '' : it.current_rank,
                    it.reason || '',
                    it.why_not_target || ''
                ];
                lines.push(row.map(csvEscape).join(','));
            });
        });

        // CRLF で結合（Excel 推奨）
        return lines.join('\r\n');
    }

    function downloadCsv(csvStr, filename) {
        // UTF-8 BOM を先頭に付けて Excel で文字化けしないようにする
        var BOM = '\uFEFF';
        var blob = new Blob([BOM + csvStr], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        setTimeout(function() {
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }, 100);
    }

    function onCsvExportClick() {
        if (!lastRenderedData || !lastRenderedData.groups) {
            showToast('出力するデータがありません。先に調査を実行してください', true);
            return;
        }
        try {
            var csvStr = buildCsvFromData(lastRenderedData);
            var now = new Date();
            var pad = function(n) { return (n < 10 ? '0' : '') + n; };
            var dateStr = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate())
                + '_' + pad(now.getHours()) + pad(now.getMinutes());
            var filename = 'keyword-research_' + dateStr + '.csv';
            downloadCsv(csvStr, filename);
            showToast('CSVを書き出しました: ' + filename);
        } catch (e) {
            console.error('CSV export failed', e);
            showToast('CSV出力に失敗しました: ' + (e.message || ''), true);
        }
    }

    var csvBtn = document.getElementById('kwrCsvExportBtn');
    if (csvBtn) csvBtn.addEventListener('click', onCsvExportClick);

    /* ===== 統合レンダリング ===== */
    function renderAll(data) {
        lastRenderedData = data;
        document.getElementById('kwrEmpty').style.display = 'none';
        renderAccuracy(data.meta || {});
        renderSummary(data.summary || {});
        renderCompSummary(data.summary || {}, data.competitor_data || []);
        renderCompKeywords(data.competitor_planner_keywords || {});
        renderGroups(data.groups || {});
        renderMeta(data.meta || {});
    }

    /* ===== データ精度表示 ===== */
    function renderAccuracy(meta) {
        var sources = meta.data_sources || [];
        var el = document.getElementById('kwrAccuracy');
        var parts = [];
        if (sources.indexOf('Keyword Planner') >= 0 || sources.indexOf('Google Ads Keyword Planner') >= 0) {
            parts.push('Google Ads Keyword Planner の実データ');
        }
        if (sources.indexOf('Google Ads 競合分析') >= 0) {
            parts.push('競合URL のキーワード分析');
        }
        if (sources.indexOf('GSC') >= 0) {
            parts.push('Search Console');
        }
        if (sources.indexOf('DataForSEO') >= 0) {
            parts.push('外部API補完データ');
        }
        var msg = parts.length > 0
            ? parts.join(' + ') + ' を反映した提案です'
            : 'AI推定ベースの提案です';
        el.textContent = msg;
        el.style.display = '';
    }

    /* ===== メタ行 ===== */
    function renderMeta(meta) {
        var html = '調査完了（' + esc(meta.generated_at || '') + '）';
        html += ' GSC: ' + (meta.gsc_count || 0) + '件参照';
        if (meta.competitor_count > 0) html += ' / 競合: ' + meta.competitor_count + 'サイト解析';

        var sources = meta.data_sources || [];
        if (sources.length > 0) {
            html += '<div class="kwr-data-sources">';
            var dsMap = { 'AI': 'ai', 'GSC': 'gsc', 'Keyword Planner': 'kwplanner', 'Google Ads Keyword Planner': 'kwplanner', 'Google Ads 競合分析': 'kwplanner-comp', 'DataForSEO': 'dataforseo', '競合分析': 'competitor' };
            sources.forEach(function(s) {
                var cls = dsMap[s] || 'ai';
                html += '<span class="kwr-ds-badge kwr-ds-badge--' + cls + '">' + esc(s) + '</span>';
            });
            html += '</div>';
        }

        // エンリッチメント診断情報（取得率を表示）
        var d = meta.enrichment || null;
        if (d && d.total > 0) {
            var pct = function(x) { return Math.round((x / d.total) * 100); };
            html += '<div style="margin-top:8px;font-size:11px;color:var(--mw-text-secondary);">'
                + '<strong>取得率</strong>: '
                + '検索Vol ' + pct(d.got_volume) + '% (' + d.got_volume + '/' + d.total + ') / '
                + '競合度 ' + pct(d.got_competition) + '% (' + d.got_competition + '/' + d.total + ') / '
                + '難易度 ' + pct(d.got_difficulty) + '% (' + d.got_difficulty + '/' + d.total + ') / '
                + 'トレンド ' + pct(d.got_trend) + '% (' + d.got_trend + '/' + d.total + ')';
            if (!d.dataforseo_available) {
                html += '<br><span style="color:#c0392b;">⚠ DataForSEO が未設定のため難易度・競合度が一部欠損する可能性があります</span>';
            }
            if (!d.planner_available) {
                html += '<br><span style="color:#c0392b;">⚠ Google Ads Keyword Planner が未設定のため検索ボリュームが欠損する可能性があります</span>';
            }
            if (d.brightdata_available && (d.bd_processed || 0) > 0) {
                html += '<br>SERP分析（同期キャッシュ）: ' + (d.bd_processed || 0) + '件処理 / '
                     + (d.bd_computed || 0) + '件で難易度算出 / '
                     + (d.bd_cache_hit || 0) + '件キャッシュ利用'
                     + ((d.bd_failed || 0) > 0 ? ' / 失敗 ' + d.bd_failed + '件' : '');
            }
            if (!d.brightdata_available) {
                html += '<br><span style="color:#a16207;">⚠ Bright Data が未設定のため SERP 補強は無効</span>';
            }
            html += '</div>';
        }

        // 非同期 SERP 補強ステータス
        if (meta.bd_async_finished_at) {
            html += '<div style="margin-top:6px;font-size:11px;color:#15803d;">'
                 + '✓ SERP分析バックグラウンド完了（' + esc(meta.bd_async_finished_at) + '）'
                 + (meta.bd_async_stats ? ' — 新規算出 ' + (meta.bd_async_stats.computed || 0) + '件' : '')
                 + '</div>';
        } else if (meta.bd_async_scheduled) {
            html += '<div style="margin-top:6px;font-size:11px;color:#a16207;">'
                 + '⏳ SERP分析をバックグラウンドで実行中です（約1〜2分）。'
                 + '<a href="javascript:location.reload()" style="margin-left:6px;">更新</a>'
                 + '</div>';
        }

        // 戦略指標（平均 ROI / 勝ちやすさ + NG 集計）
        var roi = (meta.avg_roi_score !== undefined && meta.avg_roi_score !== null) ? meta.avg_roi_score : null;
        var winavg = (meta.avg_win_probability !== undefined && meta.avg_win_probability !== null) ? meta.avg_win_probability : null;
        if (roi !== null || winavg !== null) {
            html += '<div style="margin-top:6px;font-size:11px;color:var(--mw-text-secondary);">';
            var parts2 = [];
            if (roi !== null)    parts2.push('<strong>平均ROI</strong>: ' + roi + '/100');
            if (winavg !== null) parts2.push('<strong>平均勝ちやすさ</strong>: ' + winavg + '/100');
            html += parts2.join(' / ');
            html += '</div>';
        }
        if (meta.ng_counts) {
            var sev = meta.ng_counts.severe || {};
            var warn = meta.ng_counts.warn || {};
            var sev_total = (sev.winnable_no_demand||0) + (sev.demand_no_sale||0) + (sev.sellable_too_competitive||0);
            var warn_total = (warn.low_impact||0) + (warn.borderline_competition||0);
            if (sev_total > 0 || warn_total > 0) {
                html += '<div style="margin-top:4px;font-size:11px;">';
                if (sev_total > 0) {
                    html += '<span style="color:#c0392b;">NG検出(深刻)</span>: ' + sev_total + '件'
                         + ' <span style="color:var(--mw-text-secondary);">('
                         + '需要不足 ' + (sev.winnable_no_demand||0)
                         + ' / 売上不向き ' + (sev.demand_no_sale||0)
                         + ' / 競争激化 ' + (sev.sellable_too_competitive||0)
                         + ')</span>';
                }
                if (warn_total > 0) {
                    html += (sev_total > 0 ? ' / ' : '')
                         + '<span style="color:#a16207;">注意</span>: ' + warn_total + '件'
                         + ' <span style="color:var(--mw-text-secondary);">('
                         + '影響小 ' + (warn.low_impact||0)
                         + ' / 境界競争 ' + (warn.borderline_competition||0)
                         + ')</span>';
                }
                html += '</div>';
            }
        }

        document.getElementById('kwrMeta').innerHTML = html;
    }

    /* ===== ユーティリティ: 重要語強調（フレーズ単位・控えめ） ===== */
    var kwrHighlightPhrases = [
        '差別化しやすい', '差別化が難しい', '差別化できる', '差別化ポイント',
        '狙い目', '狙いやすい', '勝ちやすい',
        '競合が強い', '競合が多い', '競合が手薄',
        '優先度が高い', '優先的に', '最優先',
        '実績や事例', '実績の掲載', '料金の見せ方', '料金比較',
        '地域名を含め', '地域密着'
    ];
    function highlightKeywords(text) {
        if (!text) return '';
        var escaped = esc(text);
        var count = 0;
        var maxHighlights = 2;
        kwrHighlightPhrases.forEach(function(phrase) {
            if (count >= maxHighlights) return;
            var regex = new RegExp('(' + phrase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'g');
            escaped = escaped.replace(regex, function(m) {
                if (count >= maxHighlights) return m;
                count++;
                return '<mark class="kwr-hl">' + m + '</mark>';
            });
        });
        return escaped;
    }

    /* ===== ユーティリティ: アクション抽出（短く行動指示寄り） ===== */
    function extractActions(texts, max) {
        max = max || 3;
        var actions = [];
        var actionPatterns = /追加|作成|強化|改善|配置|設置|掲載|修正|入れる|増やす|載せる|書く|加える|整備|対策|見直/;
        texts.forEach(function(t) {
            if (!t || actions.length >= max) return;
            var sentences = t.split(/。|！/).filter(function(s) { return s.trim().length > 5; });
            // アクション的な文を優先
            var actionSentences = sentences.filter(function(s) { return actionPatterns.test(s); });
            var pool = actionSentences.length > 0 ? actionSentences : sentences;
            pool.forEach(function(s) {
                if (actions.length >= max) return;
                var trimmed = s.trim().replace(/^(そのため|したがって|まず|また|具体的には)[、，]?\s*/,'');
                if (trimmed.length > 5 && trimmed.length < 80) actions.push(trimmed);
            });
        });
        return actions;
    }

    /* ===== ユーティリティ: 本文を段落分割＋冒頭文ハイライト ===== */
    function formatBodyText(text) {
        if (!text) return '';
        // 。で区切って段落化
        var sentences = text.split(/。/).filter(function(s) { return s.trim().length > 0; });
        if (sentences.length <= 2) return '<p>' + highlightKeywords(text) + '</p>';
        // 最初の文は冒頭ハイライト（強調マークなし — 既に目立つため二重装飾しない）
        var first = esc(sentences[0].trim()) + '。';
        // 残りの文にだけ控えめに強調を適用
        var restText = sentences.slice(1).map(function(s) { return s.trim() + '。'; }).join('');
        var restHighlighted = highlightKeywords(restText);
        return '<span class="kwr-key-sentence">' + first + '</span><p>' + restHighlighted + '</p>';
    }

    /* ===== サマリー描画 ===== */
    function renderSummary(summary) {
        var items = [
            { icon: '🎯', title: '狙うべき方向性', key: 'direction' },
            { icon: '📄', title: '優先して改善するページ', key: 'priority_pages' },
            { icon: '➕', title: '新規で作るべきページ', key: 'new_pages' },
            { icon: '✏️', title: 'タイトル・見出しに入れるべき語句', key: 'title_tips' },
            { icon: '📍', title: 'ローカルSEO・地域掛け合わせ', key: 'local_tips' }
        ];
        var html = '';

        // 構造化本文（冒頭文ハイライト付き）
        items.forEach(function(si) {
            var val = summary[si.key] || '';
            if (!val) return;
            html += '<div class="kwr-summary__item">'
                + '<div class="kwr-summary__item-title">' + si.icon + ' ' + si.title + '</div>'
                + '<div class="kwr-summary__item-text">' + formatBodyText(val) + '</div></div>';
        });

        // 次にやること
        var actions = extractActions([summary.priority_pages, summary.new_pages, summary.direction]);
        if (actions.length > 0) {
            html += '<div class="kwr-summary__actions">'
                + '<div class="kwr-summary__actions-title">✅ 次にやること</div>'
                + '<ul class="kwr-summary__actions-list">';
            actions.forEach(function(a) { html += '<li>' + esc(a) + '</li>'; });
            html += '</ul></div>';
        }

        document.getElementById('kwrSummaryContent').innerHTML = html;
        document.getElementById('kwrSummary').style.display = html ? '' : 'none';
    }

    /* ===== 競合分析サマリー ===== */
    function renderCompSummary(summary, compData) {
        var items = [
            { icon: '🛡️', title: '競合が押さえている領域', key: 'competitor_strengths' },
            { icon: '🎯', title: '自社が狙えるスキマ', key: 'competitor_gaps' },
            { icon: '✨', title: '差別化で勝てるポイント', key: 'competitor_differentiation' }
        ];
        var html = '';

        // 構造化本文（冒頭文ハイライト付き）
        items.forEach(function(si) {
            var val = summary[si.key] || '';
            if (!val) return;
            html += '<div class="kwr-summary__item">'
                + '<div class="kwr-summary__item-title">' + si.icon + ' ' + si.title + '</div>'
                + '<div class="kwr-summary__item-text">' + formatBodyText(val) + '</div></div>';
        });

        // 競合サイト一覧
        if (compData && compData.length > 0) {
            var okSites = compData.filter(function(c) { return c.status === 'ok'; });
            if (okSites.length > 0) {
                html += '<div class="kwr-summary__item"><div class="kwr-summary__item-title">🔗 解析済み競合サイト</div>';
                html += '<div class="kwr-summary__item-text">';
                okSites.forEach(function(c) {
                    html += '・' + esc(c.title || c.url);
                    if (c.note) html += ' (' + esc(c.note) + ')';
                    html += '<br>';
                });
                html += '</div></div>';
            }
        }

        // 次にやること
        var actions = extractActions([summary.competitor_gaps, summary.competitor_differentiation]);
        if (actions.length > 0) {
            html += '<div class="kwr-summary__actions">'
                + '<div class="kwr-summary__actions-title">✅ 競合に差をつけるには</div>'
                + '<ul class="kwr-summary__actions-list">';
            actions.forEach(function(a) { html += '<li>' + esc(a) + '</li>'; });
            html += '</ul></div>';
        }

        document.getElementById('kwrCompSummaryContent').innerHTML = html;
        document.getElementById('kwrCompSummary').style.display = html ? '' : 'none';
    }

    /* ===== スコア／意図バッジ（Phase A 追加） ===== */
    function fmtScore(v, label, lowThreshold) {
        if (v === null || v === undefined) return '';
        var n = Math.round(Number(v));
        var isLow = (typeof lowThreshold === 'number') && n < lowThreshold;
        var style = isLow
            ? 'background:#fdecea;color:#c0392b;'
            : 'background:#eaf5ef;color:#2d7a8f;';
        return '<span class="kwr-badge" style="' + style + 'font-size:10px;margin-right:4px;">'
            + esc(label) + ' ' + n + '</span>';
    }
    function fmtIntent(v) {
        if (!v) return '';
        return '<span class="kwr-badge" style="background:#f4f0fb;color:#7c3aed;font-size:10px;margin-right:4px;">' + esc(v) + '</span>';
    }
    function fmtCvDist(v) {
        if (!v) return '';
        var color = (v === '近い') ? '#27ae60' : ((v === '遠い') ? '#95a5a6' : '#c9a84c');
        return '<span class="kwr-badge" style="background:#fff;border:1px solid ' + color + ';color:' + color + ';font-size:10px;margin-right:4px;">CV ' + esc(v) + '</span>';
    }

    /* ===== 戦略判定バッジ（Phase B: ROI / win / 難易度バンド / 需要バンド / NG） ===== */
    function fmtRoi(v) {
        if (v === null || v === undefined) return '';
        var n = Math.round(Number(v));
        var cls;
        if (n >= 70) cls = 'kwr-roi-high';
        else if (n >= 50) cls = 'kwr-roi-mid';
        else cls = 'kwr-roi-low';
        return '<span class="kwr-badge ' + cls + '" title="ROIスコア（勝ちやすさ×0.5 + ビジネス適合×0.3 + ボリューム×0.2 + CV補正）" style="font-size:10px;margin-right:4px;">ROI ' + n + '</span>';
    }
    function fmtWin(v) {
        if (v === null || v === undefined) return '';
        var n = Math.round(Number(v));
        var cls, label;
        if (n >= 70)      { cls = 'kwr-win-high'; label = '勝てる'; }
        else if (n >= 50) { cls = 'kwr-win-mid';  label = '狙える'; }
        else              { cls = 'kwr-win-low';  label = '厳しい'; }
        return '<span class="kwr-badge ' + cls + '" title="勝ちやすさ（難易度×0.4 + 適合×0.4 + CV距離×0.2）" style="font-size:10px;margin-right:4px;">' + label + ' ' + n + '</span>';
    }
    function fmtDiffBand(band) {
        if (!band) return '';
        var map = {
            short_term: { label: '短期狙い', color: '#27ae60' },
            mid_term:   { label: '中期',     color: '#c9a84c' },
            long_term:  { label: '長期戦略', color: '#95a5a6' }
        };
        var m = map[band];
        if (!m) return '';
        return '<span class="kwr-badge" style="border:1px solid ' + m.color + ';color:' + m.color + ';background:#fff;font-size:10px;margin-right:4px;">' + m.label + '</span>';
    }
    function fmtVolBand(band) {
        if (band !== 'very_low' && band !== 'low') return '';
        var label = (band === 'very_low') ? '需要微小' : '需要小';
        return '<span class="kwr-badge" style="background:#f4f0fb;color:#8e44ad;font-size:10px;margin-right:4px;" title="月間検索ボリュームが少ない">' + label + '</span>';
    }
    function fmtNgType(ng, severity) {
        if (!ng) return '';
        var map = {
            winnable_no_demand:       { icon: '⚠', label: '需要不足',   tip: '勝てそうだが検索ボリュームが少ない' },
            demand_no_sale:           { icon: '⚠', label: '売上不向き', tip: '需要はあるがビジネス適合度が低い' },
            sellable_too_competitive: { icon: '⚠', label: '競争激化',   tip: 'ビジネス適合は高いが競争が激しい' },
            low_impact:               { icon: '!', label: '影響小',     tip: 'volume 10〜30 + fit<60：取れても影響限定' },
            borderline_competition:   { icon: '!', label: '境界競争',   tip: '難易度 60〜70 のボーダー：時間がかかる可能性' }
        };
        var m = map[ng]; if (!m) return '';
        var bg, fg;
        if (severity === 'severe') { bg = '#fdecea'; fg = '#c0392b'; }
        else                         { bg = '#fff4e6'; fg = '#a16207'; }
        return '<span class="kwr-badge" style="background:' + bg + ';color:' + fg + ';font-size:10px;margin-right:4px;" title="' + m.tip + '">'
            + m.icon + ' ' + m.label + '</span>';
    }

    /* ===== グループ別テーブル描画（ソート対応） ===== */
    var groupSortState = {}; // { groupKey: { key, dir } }

    function renderGroupTable(body, items, gk) {
        var cols = [
            { label: 'キーワード', key: 'keyword', type: 'string' },
            { label: 'タイプ',     key: 'type',    type: 'string' },
            { label: '優先度',     key: 'priority', type: 'string' },
            { label: '検索Vol.',   key: 'volume',  type: 'number' },
            { label: 'トレンド',   key: 'monthly_volumes', type: 'trend' },
            { label: '競合度',     key: 'competition_index', type: 'number' },
            { label: '難易度',     key: 'difficulty', type: 'number' },
            { label: '推奨ページ', key: 'page_type', type: 'string' },
            { label: '提案理由',   key: null },
            { label: 'アクション', key: null },
            { label: '計測', key: null },
            { label: '', key: null }
        ];
        var st = groupSortState[gk] || {};
        var sorted = st.key ? kwrSortItems(items, st.key, st.dir, st.type) : items;

        var html = '<table class="kwr-table"><thead><tr>';
        cols.forEach(function(c) {
            var cls = '';
            if (c.key) {
                if (st.key === c.key && st.dir === 'asc') cls = ' class="sort-asc"';
                else if (st.key === c.key && st.dir === 'desc') cls = ' class="sort-desc"';
                html += '<th data-sort="' + c.key + '" data-type="' + c.type + '"' + cls + '>' + c.label + '</th>';
            } else {
                html += '<th>' + c.label + '</th>';
            }
        });
        html += '</tr></thead><tbody>';

        sorted.forEach(function(item) {
            html += '<tr>'
                + '<td class="kwr-keyword-cell">' + esc(item.keyword) + '</td>'
                + '<td>' + badge(item.type, typeClass) + '</td>'
                + '<td>' + badge(item.priority, priClass) + '</td>'
                + '<td class="kwr-vol-cell">' + fmtVol(item.volume) + '</td>'
                + '<td class="kwr-vol-cell">' + fmtTrend(item.monthly_volumes) + '</td>'
                + '<td class="kwr-vol-cell">' + fmtComp(item.competition, item.competition_index) + '</td>'
                + '<td class="kwr-vol-cell">' + fmtDiff(item.difficulty, item.competition_index, item.difficulty_source) + '</td>'
                + '<td style="font-size:12px;">' + esc(item.page_type) + '</td>'
                + '<td style="font-size:12px;color:var(--mw-text-secondary);">'
                + '<div style="margin-bottom:3px;display:flex;flex-wrap:wrap;gap:2px;">'
                + fmtRoi(item.roi_score)
                + fmtWin(item.win_probability)
                + fmtNgType(item.ng_type, item.ng_severity)
                + fmtDiffBand(item.difficulty_band)
                + fmtVolBand(item.volume_band)
                + fmtScore(item.relevance_score, '関連', 70)
                + fmtScore(item.business_fit, '適合', 60)
                + fmtIntent(item.intent)
                + fmtCvDist(item.cv_distance)
                + '</div>'
                + esc(item.reason) + '</td>'
                + '<td>' + badge(item.action, actClass) + '</td>'
                + '<td>' + (isKeywordTracked(item.keyword)
                    ? trackBadgeHtml()
                    : '<button type="button" class="kwr-btn-track" data-keyword="' + esc(item.keyword).replace(/"/g, '&quot;') + '">' + trackBtnInner() + '</button>')
                + '</td>'
                + '<td><a href="' + writingUrl + '?keyword=' + encodeURIComponent(item.keyword) + '" class="kwr-btn-create" title="このキーワードで記事を作成"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 10.5a.75.75 0 01.75-.75h7.5a.75.75 0 010 1.5h-7.5a.75.75 0 01-.75-.75zM2 10a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 10z"/><path d="M16 12.25a.75.75 0 01.75.75v2.25H19a.75.75 0 010 1.5h-2.25V19a.75.75 0 01-1.5 0v-2.25H13a.75.75 0 010-1.5h2.25V13a.75.75 0 01.75-.75z"/></svg>記事作成</a></td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;

        // ソートイベント
        body.querySelectorAll('th[data-sort]').forEach(function(th) {
            th.addEventListener('click', function() {
                var sortKey = th.getAttribute('data-sort');
                var sortType = th.getAttribute('data-type');
                var cur = (st.key === sortKey) ? st.dir : '';
                var nd = nextSortDir(cur);
                groupSortState[gk] = nd ? { key: sortKey, dir: nd, type: sortType } : {};
                renderGroupTable(body, items, gk);
            });
        });

        // 計測キーワード追加ボタン
        body.querySelectorAll('.kwr-btn-track').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var kw = btn.getAttribute('data-keyword');
                if (kw) addToTracker(kw, btn);
            });
        });
    }

    /* excluded 専用テーブル（採択基準を満たさず除外されたKW） */
    function renderExcludedTable(body, items) {
        var html = '<table class="kwr-table"><thead><tr>'
            + '<th>キーワード</th>'
            + '<th>関連度</th>'
            + '<th>適合度</th>'
            + '<th>検索意図</th>'
            + '<th>CV距離</th>'
            + '<th>除外理由</th>'
            + '</tr></thead><tbody>';
        items.forEach(function(item) {
            html += '<tr>'
                + '<td class="kwr-keyword-cell" style="color:var(--mw-text-secondary);">' + esc(item.keyword) + '</td>'
                + '<td>' + fmtScore(item.relevance_score, '', 70).replace(/margin-right:4px;/, '') + '</td>'
                + '<td>' + fmtScore(item.business_fit, '', 60).replace(/margin-right:4px;/, '') + '</td>'
                + '<td>' + fmtIntent(item.intent) + '</td>'
                + '<td>' + fmtCvDist(item.cv_distance) + '</td>'
                + '<td style="font-size:12px;color:#c0392b;">' + esc(item.why_not_target || item.reason || '') + '</td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
    }

    function renderGroups(groups) {
        var container = document.getElementById('kwrGroups');
        container.innerHTML = '';
        var hasAny = false;

        groupOrder.forEach(function(gk) {
            var items = groups[gk] || [];
            if (items.length === 0) return;
            var gm = groupMeta[gk];
            if (!gm) return;
            hasAny = true;

            var isExcluded = (gk === 'excluded');

            var div = document.createElement('div');
            div.className = 'kwr-group';

            var header = document.createElement('div');
            header.className = 'kwr-group__header';
            header.innerHTML = '<span class="kwr-group__icon">' + gm.icon + '</span>'
                + '<h3 class="kwr-group__title" style="color:' + gm.color + ';">' + esc(gm.label)
                + ' <span class="kwr-group__count">(' + items.length + '件)</span></h3>'
                + '<span class="kwr-group__arrow' + (isExcluded ? ' collapsed' : '') + '">▼</span>';

            var body = document.createElement('div');
            body.className = 'kwr-group__body';
            if (isExcluded) { body.style.display = 'none'; }

            header.addEventListener('click', function() {
                var hidden = body.style.display === 'none';
                body.style.display = hidden ? '' : 'none';
                header.querySelector('.kwr-group__arrow').className = 'kwr-group__arrow' + (hidden ? '' : ' collapsed');
            });

            if (isExcluded) {
                renderExcludedTable(body, items);
            } else {
                renderGroupTable(body, items, gk);
            }
            div.appendChild(header);
            div.appendChild(body);
            container.appendChild(div);
        });

        document.getElementById('kwrResults').style.display = hasAny ? '' : 'none';
    }

    /* ===== 競合キーワード比較描画（ソート対応） ===== */
    var compSortState = {}; // { urlHash: { key, dir, type } }
    var compDataCache = {}; // { url: kws[] }

    function renderCompTable(container, kws, urlHash) {
        var st = compSortState[urlHash] || {};
        var sorted = st.key ? kwrSortItems(kws, st.key, st.dir, st.type) : kws;
        var cols = [
            { label: 'キーワード', key: 'text', type: 'string' },
            { label: '月間検索数', key: 'volume', type: 'number' },
            { label: '順位',       key: 'rank', type: 'number' },
            { label: '推定流入',   key: 'etv', type: 'number' },
            { label: 'トレンド',   key: 'monthly_volumes', type: 'trend' },
            { label: '競合度',     key: 'competition_index', type: 'number' },
            { label: 'CPC',        key: 'cpc', type: 'number' }
        ];

        var html = '<table class="kwr-comp-kw-table"><thead><tr>';
        cols.forEach(function(c) {
            var cls = '';
            if (st.key === c.key && st.dir === 'asc') cls = ' class="sort-asc"';
            else if (st.key === c.key && st.dir === 'desc') cls = ' class="sort-desc"';
            html += '<th data-sort="' + c.key + '" data-type="' + c.type + '"' + cls + '>' + c.label + '</th>';
        });
        html += '</tr></thead><tbody>';

        sorted.forEach(function(kw) {
            var vol = kw.volume !== null && kw.volume !== undefined ? Number(kw.volume).toLocaleString() : '-';
            var rank = kw.rank !== null && kw.rank !== undefined ? kw.rank + '位' : '-';
            var etv = kw.etv !== null && kw.etv !== undefined ? Number(Math.round(kw.etv)).toLocaleString() : '-';
            var ci = kw.competition_index;
            var comp = ci !== null && ci !== undefined
                ? '<span class="kwr-comp-bar"><span class="kwr-comp-bar__fill" style="width:' + ci + '%;"></span></span> ' + ci
                : (kw.competition || '-');
            var cpc = kw.cpc !== null && kw.cpc !== undefined ? '¥' + Number(kw.cpc).toLocaleString() : '-';
            html += '<tr>'
                + '<td style="font-weight:500;">' + esc(kw.text) + '</td>'
                + '<td style="text-align:right;">' + vol + '</td>'
                + '<td style="text-align:center;">' + rank + '</td>'
                + '<td style="text-align:right;">' + etv + '</td>'
                + '<td style="text-align:center;">' + fmtTrend(kw.monthly_volumes) + '</td>'
                + '<td>' + comp + '</td>'
                + '<td style="text-align:right;font-size:11px;">' + cpc + '</td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;

        container.querySelectorAll('th[data-sort]').forEach(function(th) {
            th.addEventListener('click', function() {
                var sortKey = th.getAttribute('data-sort');
                var sortType = th.getAttribute('data-type');
                var cur = (st.key === sortKey) ? st.dir : '';
                var nd = nextSortDir(cur);
                compSortState[urlHash] = nd ? { key: sortKey, dir: nd, type: sortType } : {};
                renderCompTable(container, kws, urlHash);
            });
        });
    }

    function renderCompKeywords(compPlannerKeywords) {
        var el = document.getElementById('kwrCompKeywords');
        if (!compPlannerKeywords || typeof compPlannerKeywords !== 'object') {
            el.style.display = 'none'; return;
        }
        var urls = Object.keys(compPlannerKeywords);
        if (urls.length === 0) { el.style.display = 'none'; return; }

        var hasData = false;
        var wrapper = document.getElementById('kwrCompKeywordsContent');
        wrapper.innerHTML = '<p style="font-size:13px;color:var(--mw-text-secondary);margin-bottom:16px;">'
            + '競合サイトが「どんな検索キーワードで見つけてもらえているか」を一覧にしたものです。<br>'
            + '月間検索数は、そのキーワードが毎月どれくらい検索されているかを示します（Googleの実データ）。<br>'
            + '順位と推定流入は、競合サイトが実際にそのキーワードで何位に表示され、月に何人くらい訪問しているかの推定値です。<br>'
            + '下の競合URLをクリックすると、そのサイトのキーワード一覧が開きます。</p>';

        urls.forEach(function(url, idx) {
            var kws = compPlannerKeywords[url];
            if (!kws || kws.length === 0) return;
            hasData = true;

            // トグルヘッダー
            var toggle = document.createElement('div');
            toggle.className = 'kwr-comp-kw-toggle';
            toggle.innerHTML = '<span class="kwr-comp-kw-toggle__arrow collapsed">▼</span>'
                + '<span class="kwr-comp-kw-toggle__url">' + esc(url) + '</span>'
                + '<span class="kwr-comp-kw-toggle__count">' + kws.length + '件</span>';
            wrapper.appendChild(toggle);

            // テーブルコンテナ（初期非表示）
            var body = document.createElement('div');
            body.className = 'kwr-comp-kw-body';
            wrapper.appendChild(body);

            var urlHash = 'comp_' + idx;
            var rendered = false;

            toggle.addEventListener('click', (function(b, k, uh) {
                return function() {
                    var isOpen = b.classList.contains('open');
                    if (isOpen) {
                        b.classList.remove('open');
                        this.querySelector('.kwr-comp-kw-toggle__arrow').classList.add('collapsed');
                    } else {
                        b.classList.add('open');
                        this.querySelector('.kwr-comp-kw-toggle__arrow').classList.remove('collapsed');
                        if (!rendered) {
                            renderCompTable(b, k, uh);
                            rendered = true;
                        }
                    }
                };
            })(body, kws, urlHash));
        });

        el.style.display = hasData ? '' : 'none';
    }

})();
</script>

<?php get_footer(); ?>
