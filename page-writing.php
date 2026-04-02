<?php
/**
 * Template Name: ライティング
 * Description: キーワード調査データ・クライアント情報ストックを活用した記事生成基盤。
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/' ) );
    exit;
}

if ( ! mimamori_can_access_seo() ) {
    wp_safe_redirect( home_url( '/dashboard/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// URL パラメータからキーワードを取得
$initial_keyword = isset( $_GET['keyword'] ) ? sanitize_text_field( $_GET['keyword'] ) : '';

set_query_var( 'gcrev_page_title', 'ライティング' );
set_query_var( 'gcrev_page_subtitle', 'キーワード調査やクライアント情報をもとに、SEO記事の構成案を作成します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'ライティング', 'SEO' ) );

get_header();
?>
<style>
/* =========================================================
   ライティング — スタイル (Phase 1)
   ========================================================= */

/* ヘッダーアクション */
.wrt-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.wrt-search-input { flex: 1; min-width: 200px; padding: 10px 16px; border: 1px solid var(--mw-border-light); border-radius: 8px; font-size: 14px; background: var(--mw-bg-primary); color: var(--mw-text-primary); box-sizing: border-box; }
.wrt-search-input:focus { outline: none; border-color: var(--mw-primary-blue, #4A90A4); }
.wrt-status-filter { padding: 10px 14px; border: 1px solid var(--mw-border-light); border-radius: 8px; font-size: 14px; background: var(--mw-bg-primary); color: var(--mw-text-primary); cursor: pointer; min-width: 120px; }
.wrt-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.25s ease; white-space: nowrap; }
.wrt-btn--primary { background: var(--mw-primary-blue, #568184); color: #fff; }
.wrt-btn--primary:hover:not(:disabled) { background: var(--mw-btn-primary-hover, #476C6F); box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.wrt-btn--primary:active:not(:disabled) { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.wrt-btn--secondary { background: var(--mw-bg-secondary); color: var(--mw-text-heading); border: 1px solid var(--mw-border-light); }
.wrt-btn--secondary:hover:not(:disabled) { background: var(--mw-bg-tertiary, #E6EEF0); border-color: var(--mw-border-medium, #AEBCBE); box-shadow: 0 2px 8px rgba(0,0,0,0.06); transform: translateY(-1px); }
.wrt-btn--secondary:active:not(:disabled) { transform: translateY(0); box-shadow: none; }
.wrt-btn--sm { padding: 6px 14px; font-size: 12px; }
.wrt-btn--danger { background: rgba(201,90,79,0.1); color: #C95A4F; }
.wrt-btn--danger:hover:not(:disabled) { background: rgba(201,90,79,0.18); box-shadow: 0 2px 8px rgba(201,90,79,0.15); transform: translateY(-1px); }
.wrt-btn--danger:active:not(:disabled) { transform: translateY(0); box-shadow: none; }
.wrt-btn:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
.wrt-btn:focus-visible { outline: 2px solid var(--mw-primary-blue, #568184); outline-offset: 2px; }

/* テーブル */
.wrt-table-wrap { background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light); border-radius: var(--mw-radius-md, 12px); overflow: hidden; }
.wrt-table { width: 100%; border-collapse: collapse; table-layout: auto; }
.wrt-table thead { border-bottom: 1px solid var(--mw-border-light); }
.wrt-table th { padding: 12px 16px; font-size: 13px; font-weight: 600; color: var(--mw-text-secondary); text-align: left; white-space: nowrap; background: var(--mw-bg-secondary); }
.wrt-table__th-check { width: 40px; text-align: center; }
.wrt-table__th-icon { width: 60px; text-align: center; }
.wrt-table__th-date { width: 110px; }
.wrt-table__row { border-bottom: 1px solid var(--mw-border-light); cursor: pointer; transition: background 0.1s; }
.wrt-table__row:last-child { border-bottom: none; }
.wrt-table__row:hover { background: rgba(74,144,164,0.03); }
.wrt-table td { padding: 12px 16px; font-size: 14px; color: var(--mw-text-primary); }
.wrt-table__td-check { width: 40px; text-align: center; }
.wrt-table__td-title { font-weight: 500; color: var(--mw-text-heading); white-space: nowrap; }
.wrt-table__td-keyword { color: var(--mw-text-secondary); font-size: 13px; white-space: nowrap; width: 160px; }
.wrt-table__td-icon { width: 60px; text-align: center; }
.wrt-table__td-date { font-size: 13px; color: var(--mw-text-tertiary); white-space: nowrap; }
.wrt-icon-active { color: var(--mw-text-heading); }
.wrt-icon-inactive { color: var(--mw-border-light); }

/* 一括操作バー */
.wrt-bulk-bar { display: flex; align-items: center; gap: 12px; padding: 8px 16px; margin-bottom: 8px; background: var(--mw-bg-secondary); border: 1px solid var(--mw-border-light); border-radius: var(--mw-radius-md, 12px); font-size: 13px; color: var(--mw-text-secondary); }

/* ページネーション */
.wrt-pagination { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; font-size: 13px; color: var(--mw-text-tertiary); }
.wrt-pag__nav { display: flex; align-items: center; gap: 8px; }
.wrt-pag__btn { background: none; border: 1px solid var(--mw-border-light); border-radius: 6px; padding: 4px 10px; cursor: pointer; font-size: 14px; color: var(--mw-text-secondary); transition: all 0.25s ease; }
.wrt-pag__btn:hover:not(:disabled) { border-color: var(--mw-primary-blue); color: var(--mw-text-heading); background: rgba(86,129,132,0.06); box-shadow: 0 1px 4px rgba(0,0,0,0.06); transform: translateY(-1px); }
.wrt-pag__btn:active:not(:disabled) { transform: translateY(0); box-shadow: none; }
.wrt-pag__btn:disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
.wrt-pag__info { font-weight: 600; color: var(--mw-text-secondary); }

/* タブ */
.wrt-tabs { display: flex; gap: 0; margin-bottom: 24px; border-bottom: 2px solid var(--mw-border-light); }
.wrt-tabs__tab { padding: 10px 20px; font-size: 14px; font-weight: 600; color: var(--mw-text-tertiary); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.25s ease; background: none; border-top: none; border-left: none; border-right: none; }
.wrt-tabs__tab.active { color: var(--mw-primary-blue, #568184); border-bottom-color: var(--mw-primary-blue, #568184); }
.wrt-tabs__tab:hover:not(.active) { color: var(--mw-text-heading); background: rgba(86,129,132,0.04); }
.wrt-tab-panel { display: none; }
.wrt-tab-panel.active { display: block; }

/* カード（情報ストック用） */
.wrt-card { background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light); border-radius: var(--mw-radius-md, 12px); padding: 20px; margin-bottom: 16px; cursor: pointer; transition: box-shadow 0.15s; }
.wrt-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.wrt-card__title { font-size: 15px; font-weight: 600; color: var(--mw-text-heading); margin: 0 0 6px; }
.wrt-card__meta { font-size: 12px; color: var(--mw-text-tertiary); display: flex; gap: 12px; flex-wrap: wrap; }

/* 情報ストック ヘッダー */
.wrt-kb-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
.wrt-kb-filters { display: flex; gap: 6px; flex-wrap: wrap; flex: 1; }
.wrt-kb-filter-btn { padding: 6px 14px; border: 1px solid var(--mw-border-light); border-radius: 20px; font-size: 13px; font-weight: 500; color: var(--mw-text-secondary); background: var(--mw-bg-primary); cursor: pointer; transition: all 0.25s ease; white-space: nowrap; }
.wrt-kb-filter-btn:hover:not(.active) { border-color: var(--mw-primary-blue, #568184); color: var(--mw-text-heading); background: rgba(86,129,132,0.06); box-shadow: 0 1px 4px rgba(0,0,0,0.06); transform: translateY(-1px); }
.wrt-kb-filter-btn:active:not(.active) { transform: translateY(0); box-shadow: none; }
.wrt-kb-filter-btn.active { background: var(--mw-primary-blue, #568184); border-color: var(--mw-primary-blue, #568184); color: #fff; }
.wrt-kb-filter-btn__count { font-size: 11px; opacity: 0.8; }

/* 情報ストック */
.wrt-knowledge-card { display: flex; align-items: flex-start; gap: 12px; }
.wrt-knowledge-card__body { flex: 1; min-width: 0; }
.wrt-knowledge-card__excerpt { font-size: 12px; color: var(--mw-text-secondary); margin-top: 4px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.wrt-knowledge-card__actions { display: flex; gap: 6px; flex-shrink: 0; }
.wrt-cat-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; background: rgba(74,144,164,0.1); color: #2D7A8F; }

/* モーダル */
.wrt-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; display: none; align-items: center; justify-content: center; }
.wrt-modal-overlay.active { display: flex; }
.wrt-modal { background: var(--mw-bg-primary); border-radius: 16px; padding: 32px; max-width: 560px; width: 90%; max-height: 85vh; overflow-y: auto; position: relative; }
.wrt-modal__title { font-size: 18px; font-weight: 700; color: var(--mw-text-heading); margin: 0 0 20px; }
.wrt-modal__close { position: absolute; top: 16px; right: 16px; background: none; border: none; font-size: 20px; cursor: pointer; color: var(--mw-text-tertiary); padding: 4px; transition: all 0.2s ease; border-radius: 4px; }
.wrt-modal__close:hover { color: var(--mw-text-heading); background: rgba(0,0,0,0.06); transform: scale(1.1); }
.wrt-modal__close:active { transform: scale(0.95); }
.wrt-modal label { display: block; font-size: 13px; font-weight: 600; color: var(--mw-text-secondary); margin-bottom: 6px; }
.wrt-modal input[type="text"], .wrt-modal textarea, .wrt-modal select { width: 100%; padding: 10px 12px; border: 1px solid var(--mw-border-light); border-radius: 8px; font-size: 14px; background: var(--mw-bg-primary); color: var(--mw-text-primary); box-sizing: border-box; }
.wrt-modal textarea { resize: vertical; min-height: 120px; }
.wrt-modal__actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
.wrt-modal__field { margin-bottom: 16px; }
.wrt-modal--wide { max-width: 720px; }

/* 順位計測キーワード選択 */
.wrt-rank-kw-section { margin-top: 16px; }
.wrt-rank-kw-section__title { font-size: 12px; font-weight: 600; color: var(--mw-text-secondary); margin-bottom: 8px; }
.wrt-rank-kw-list { display: flex; flex-wrap: wrap; gap: 6px; max-height: 160px; overflow-y: auto; padding: 4px 0; }
.wrt-rank-kw-chip { padding: 5px 12px; border-radius: 20px; font-size: 12px; border: 1px solid var(--mw-border-light); background: var(--mw-bg-secondary); color: var(--mw-text-secondary); cursor: pointer; transition: all 0.15s; }
.wrt-rank-kw-chip:hover { border-color: var(--mw-primary-blue, #4A90A4); color: var(--mw-text-heading); }
.wrt-rank-kw-chip.selected { background: rgba(74,144,164,0.12); border-color: var(--mw-primary-blue, #4A90A4); color: #2D7A8F; font-weight: 600; }

/* 記事詳細ビュー */
.wrt-detail { background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light); border-radius: var(--mw-radius-md, 12px); padding: 28px; }
.wrt-detail__topbar { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.wrt-detail__back { display: inline-flex; align-items: center; gap: 4px; padding: 6px 14px; border: 1px solid var(--mw-border-light); border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--mw-text-secondary); cursor: pointer; background: var(--mw-bg-secondary); transition: all 0.25s ease; text-decoration: none; }
.wrt-detail__back:hover { background: var(--mw-bg-primary); border-color: var(--mw-primary-blue); color: var(--mw-text-heading); box-shadow: 0 2px 6px rgba(0,0,0,0.06); transform: translateY(-1px); }
.wrt-detail__back:active { transform: translateY(0); box-shadow: none; }
.wrt-detail__keyword-section { display: flex; align-items: baseline; gap: 16px; margin-bottom: 24px; padding: 16px 20px; background: var(--mw-bg-secondary); border-radius: 10px; border: 1px solid var(--mw-border-light); }
.wrt-detail__keyword-label { font-size: 13px; font-weight: 600; color: var(--mw-text-tertiary); white-space: nowrap; }
.wrt-detail__keyword-value { font-size: 20px; font-weight: 700; color: var(--mw-text-heading); }
/* 2カラムレイアウト */
.wrt-detail__body { display: flex; gap: 24px; }
.wrt-detail__main { flex: 1; min-width: 0; }
.wrt-detail__sidebar { width: 340px; flex-shrink: 0; }
.wrt-detail__sidebar .wrt-settings-grid { grid-template-columns: 1fr; }

.wrt-detail-section { margin-bottom: 24px; }
.wrt-detail-section__title { font-size: 14px; font-weight: 600; color: var(--mw-text-heading); margin-bottom: 12px; border-bottom: 1px solid var(--mw-border-light); padding-bottom: 8px; }
.wrt-settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.wrt-settings-grid label { font-size: 12px; }
.wrt-settings-grid select, .wrt-settings-grid input, .wrt-settings-grid textarea { padding: 8px 10px; border: 1px solid var(--mw-border-light); border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box; background: var(--mw-bg-primary); color: var(--mw-text-primary); }

/* 構成案表示 */
.wrt-outline { margin-top: 16px; }
.wrt-outline__titles { margin-bottom: 16px; }
.wrt-outline__title-opt { padding: 8px 14px; background: var(--mw-bg-secondary); border: 1px solid var(--mw-border-light); border-radius: 8px; margin-bottom: 6px; font-size: 14px; font-weight: 600; color: var(--mw-text-heading); }
.wrt-outline__intent { padding: 12px 16px; background: rgba(74,144,164,0.06); border-radius: 8px; font-size: 13px; color: var(--mw-text-secondary); margin-bottom: 16px; line-height: 1.7; }
.wrt-outline__heading { padding: 8px 0; border-bottom: 1px solid var(--mw-border-light); }
.wrt-outline__heading-text { font-weight: 600; color: var(--mw-text-heading); }
.wrt-outline__heading-desc { font-size: 12px; color: var(--mw-text-secondary); margin-top: 2px; }
.wrt-outline__heading-ref { font-size: 11px; color: #4285F4; margin-top: 2px; }
.wrt-outline__heading--h2 { padding-left: 0; font-size: 14px; }
.wrt-outline__heading--h3 { padding-left: 20px; font-size: 13px; }
.wrt-outline__missing { margin-top: 16px; padding: 14px 16px; background: rgba(201,90,79,0.06); border: 1px solid rgba(201,90,79,0.15); border-radius: 8px; }
.wrt-outline__missing-title { font-size: 13px; font-weight: 600; color: #C95A4F; margin-bottom: 8px; }
.wrt-outline__missing-item { font-size: 12px; color: var(--mw-text-secondary); padding: 2px 0; }
.wrt-outline__tips { margin-top: 12px; padding: 12px 16px; background: rgba(39,174,96,0.06); border-radius: 8px; }
.wrt-outline__tips-title { font-size: 13px; font-weight: 600; color: #27AE60; margin-bottom: 6px; }
.wrt-outline__tips-item { font-size: 12px; color: var(--mw-text-secondary); padding: 2px 0; }

/* 空状態 */
.wrt-empty { text-align: center; padding: 48px 20px; color: var(--mw-text-tertiary); }
.wrt-empty__icon { font-size: 40px; margin-bottom: 12px; }
.wrt-empty__text { font-size: 15px; color: var(--mw-text-secondary); margin-bottom: 8px; }

/* プログレス・トースト */
.wrt-progress { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: none; align-items: center; justify-content: center; }
.wrt-progress.active { display: flex; }
.wrt-progress__inner { background: #fff; border-radius: 16px; padding: 40px; text-align: center; min-width: 300px; }
.wrt-progress__spinner { width: 40px; height: 40px; margin: 0 auto 16px; border: 3px solid var(--mw-border-light, #e2e8f0); border-top-color: var(--mw-primary-teal, #4A90A4); border-radius: 50%; animation: wrt-spin 0.8s linear infinite; }
@keyframes wrt-spin { to { transform: rotate(360deg); } }
.wrt-progress__text { font-size: 14px; color: var(--mw-text-secondary); }
.wrt-toast { position: fixed; bottom: 24px; left: 24px; padding: 12px 20px; border-radius: 10px; background: #1A2F33; color: #fff; font-size: 14px; z-index: 10001; opacity: 0; transition: opacity 0.3s; pointer-events: none; }
.wrt-toast.active { opacity: 1; pointer-events: auto; }
.wrt-toast--error { background: #C95A4F; }

/* 情報ストック選択チェックボックス */
.wrt-kb-select { display: flex; flex-wrap: wrap; gap: 8px; max-height: 200px; overflow-y: auto; }
.wrt-kb-select__item { display: flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid var(--mw-border-light); border-radius: 6px; font-size: 12px; cursor: pointer; transition: all 0.15s; }
.wrt-kb-select__item:hover { border-color: var(--mw-primary-blue); }
.wrt-kb-select__item.selected { background: rgba(74,144,164,0.1); border-color: var(--mw-primary-blue); }

/* ファイル添付 */
.wrt-file-item { display: flex; align-items: center; gap: 8px; padding: 6px 10px; background: var(--mw-bg-secondary); border: 1px solid var(--mw-border-light); border-radius: 6px; font-size: 12px; margin-bottom: 4px; }
.wrt-file-item__icon { font-size: 16px; }
.wrt-file-item__name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--mw-text-heading); }
.wrt-file-item__size { color: var(--mw-text-tertiary); font-size: 11px; white-space: nowrap; }
.wrt-file-item__del { background: none; border: none; color: #C95A4F; cursor: pointer; font-size: 14px; padding: 2px; transition: all 0.2s ease; border-radius: 4px; }
.wrt-file-item__del:hover { background: rgba(201,90,79,0.12); transform: scale(1.15); }
.wrt-file-item__del:active { transform: scale(0.95); }

/* ドロップゾーン */
.wrt-dropzone { border: 2px dashed var(--mw-border-light); border-radius: 10px; padding: 20px; text-align: center; transition: all 0.2s; cursor: pointer; }
.wrt-dropzone.drag-over { border-color: var(--mw-primary-blue, #4A90A4); background: rgba(74,144,164,0.04); }
.wrt-dropzone.disabled { opacity: 0.5; pointer-events: none; }
.wrt-dropzone__icon { font-size: 28px; margin-bottom: 6px; }
.wrt-dropzone__text { font-size: 13px; font-weight: 600; color: var(--mw-text-secondary); }
.wrt-dropzone__sub { font-size: 12px; color: var(--mw-text-tertiary); margin-top: 4px; }
.wrt-dropzone__link { color: var(--mw-primary-blue, #4A90A4); text-decoration: underline; cursor: pointer; }

/* 音声入力ボタン */
.wrt-voice-btn { flex-shrink: 0; transition: all 0.2s; }
.wrt-voice-btn:hover { transform: scale(1.1); }

/* 音声入力モーダル */
.wrt-voice-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 10002; display: none; align-items: center; justify-content: center; }
.wrt-voice-modal.active { display: flex; }
.wrt-voice-modal__inner { background: var(--mw-bg-primary); border-radius: 20px; padding: 32px; max-width: 480px; width: 90%; text-align: center; }
.wrt-voice-modal__title { font-size: 18px; font-weight: 700; color: var(--mw-text-heading); margin-bottom: 8px; }
.wrt-voice-modal__status { font-size: 14px; color: var(--mw-text-secondary); margin-bottom: 20px; }
.wrt-voice-modal__wave { width: 100%; height: 80px; margin-bottom: 20px; border-radius: 10px; background: var(--mw-bg-secondary); }
.wrt-voice-modal__result { width: 100%; min-height: 80px; padding: 12px; border: 1px solid var(--mw-border-light); border-radius: 10px; font-size: 14px; line-height: 1.8; resize: vertical; box-sizing: border-box; background: var(--mw-bg-primary); color: var(--mw-text-primary); display: none; }
.wrt-voice-modal__result.visible { display: block; }
.wrt-voice-modal__actions { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }
.wrt-voice-modal__rec-btn { width: 64px; height: 64px; border-radius: 50%; border: none; background: #C95A4F; color: #fff; font-size: 24px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; justify-content: center; }
.wrt-voice-modal__rec-btn:hover { transform: scale(1.05); }
.wrt-voice-modal__rec-btn.recording { animation: wrt-pulse 1.5s ease-in-out infinite; }
@keyframes wrt-pulse { 0%,100% { box-shadow: 0 0 0 0 rgba(201,90,79,0.4); } 50% { box-shadow: 0 0 0 12px rgba(201,90,79,0); } }

/* 本文プレビュー */
.wrt-draft-container { margin-top: 16px; border: 1px solid var(--mw-border-light); border-radius: 10px; overflow: hidden; background: #fff; }
.wrt-draft-toolbar { display: flex; align-items: center; justify-content: space-between; padding: 8px 16px; background: var(--mw-bg-secondary); border-bottom: 1px solid var(--mw-border-light); gap: 8px; flex-wrap: wrap; }
.wrt-draft-toolbar__left { display: flex; gap: 6px; align-items: center; }
.wrt-draft-toolbar__right { display: flex; gap: 6px; align-items: center; }
.wrt-draft-tag { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px; padding: 0 6px; border-radius: 4px; font-size: 12px; color: var(--mw-text-secondary); background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light); }
.wrt-draft-preview { padding: 40px 48px; max-width: 780px; margin: 0 auto; font-family: 'Noto Sans JP', 'Hiragino Sans', 'Hiragino Kaku Gothic ProN', sans-serif; position: relative; }
.wrt-draft-h1 { font-size: 26px; font-weight: 800; line-height: 1.4; color: #1a1a1a; margin: 0 0 24px; letter-spacing: -0.02em; }
.wrt-draft-h2 { font-size: 21px; font-weight: 700; line-height: 1.4; color: #1a1a1a; margin: 40px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; }
.wrt-draft-h3 { font-size: 17px; font-weight: 700; line-height: 1.5; color: #2d3748; margin: 32px 0 12px; }
.wrt-draft-h4 { font-size: 15px; font-weight: 600; line-height: 1.5; color: #4a5568; margin: 24px 0 8px; }
.wrt-draft-p, .wrt-draft-preview p { font-size: 15.5px; line-height: 2; color: #374151; margin: 0 0 20px; letter-spacing: 0.02em; }
.wrt-draft-preview li { font-size: 15.5px; line-height: 2; color: #374151; margin-bottom: 4px; list-style: disc inside; }
.wrt-draft-preview strong { font-weight: 700; color: #1a1a1a; }
.wrt-draft-stats { display: flex; gap: 20px; justify-content: flex-end; padding: 8px 16px; font-size: 13px; color: #94a3b8; border-top: 1px solid var(--mw-border-light); }

/* 編集/プレビュータブ */
.wrt-draft-tab { background: none; border: none; padding: 4px 12px; font-size: 13px; font-weight: 500; color: var(--mw-text-tertiary); cursor: pointer; border-radius: 4px; transition: all 0.25s ease; }
.wrt-draft-tab:hover:not(.active) { color: var(--mw-text-heading); background: rgba(86,129,132,0.06); }
.wrt-draft-tab.active { background: var(--mw-bg-primary); color: var(--mw-primary-blue, #568184); font-weight: 600; }

/* 本文エディター */
.wrt-draft-editor { width: 100%; min-height: 500px; padding: 40px 48px; font-family: 'Noto Sans JP', 'Hiragino Sans', sans-serif; font-size: 15px; line-height: 2; color: #374151; border: none; outline: none; resize: vertical; box-sizing: border-box; background: #fff; }

/* 競合調査結果 */
.wrt-cr-header { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.wrt-cr-count { font-size: 13px; color: var(--mw-text-secondary); }
.wrt-cr-count strong { color: var(--mw-text-heading); }
.wrt-cr-reflected-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 12px; background: rgba(78,138,107,0.12); color: #3D7559; }
.wrt-cr-section { margin-bottom: 16px; }
.wrt-cr-section-title { font-size: 12px; font-weight: 700; color: var(--mw-text-secondary); margin-bottom: 8px; display: flex; align-items: center; gap: 5px; }
.wrt-cr-text { font-size: 13px; color: var(--mw-text-body, #374151); line-height: 1.7; }
.wrt-cr-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.wrt-cr-chip { padding: 4px 12px; background: var(--mw-bg-secondary); border: 1px solid var(--mw-border-light); border-radius: 14px; font-size: 12px; color: var(--mw-text-secondary); }
.wrt-cr-card { padding: 12px 16px; border-radius: 8px; margin-bottom: 8px; }
.wrt-cr-card--gap { background: rgba(78,138,107,0.06); border-left: 3px solid #4E8A6B; }
.wrt-cr-card--angle { background: rgba(86,129,132,0.06); border-left: 3px solid var(--mw-primary-blue, #568184); }
.wrt-cr-card--strength { background: var(--mw-bg-secondary); border-left: 3px solid var(--mw-border-medium); }
.wrt-cr-card-label { font-size: 11px; font-weight: 600; margin-bottom: 4px; }
.wrt-cr-card--gap .wrt-cr-card-label { color: #3D7559; }
.wrt-cr-card--angle .wrt-cr-card-label { color: var(--mw-primary-blue, #568184); }
.wrt-cr-card-text { font-size: 13px; color: var(--mw-text-body, #374151); line-height: 1.6; }
.wrt-cr-stats { display: flex; gap: 16px; font-size: 12px; color: var(--mw-text-tertiary); margin-bottom: 12px; }
.wrt-cr-stats strong { color: var(--mw-text-secondary); }
.wrt-cr-reflected-msg { display: flex; align-items: center; gap: 6px; padding: 10px 14px; background: rgba(78,138,107,0.06); border: 1px solid rgba(78,138,107,0.15); border-radius: 8px; font-size: 12px; color: #3D7559; font-weight: 500; margin-top: 16px; }

/* タイトル選択・編集 */
.wrt-outline__title-opt { cursor: pointer; transition: all 0.2s ease; border-left: 3px solid transparent; position: relative; }
.wrt-outline__title-opt:hover { background: rgba(86,129,132,0.04); border-left-color: var(--mw-border-medium); }
.wrt-outline__title-opt.selected { border-left-color: var(--mw-primary-blue, #568184); background: rgba(86,129,132,0.06); font-weight: 700; }
.wrt-outline__title-opt .wrt-title-radio { position: absolute; left: 8px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; border: 2px solid var(--mw-border-medium); border-radius: 50%; background: #fff; }
.wrt-outline__title-opt.selected .wrt-title-radio { border-color: var(--mw-primary-blue); background: var(--mw-primary-blue); box-shadow: inset 0 0 0 3px #fff; }
.wrt-outline__title-opt { padding-left: 32px !important; }
.wrt-title-edit-input { width: 100%; padding: 8px 10px; border: 1px solid var(--mw-primary-blue, #568184); border-radius: 6px; font-size: 14px; font-weight: 600; color: var(--mw-text-heading); background: #fff; box-sizing: border-box; }
.wrt-title-actions { display: flex; gap: 8px; margin-top: 12px; }

@media (max-width: 768px) {
    .wrt-draft-preview { padding: 24px 20px; }
    .wrt-draft-h1 { font-size: 22px; }
    .wrt-draft-h2 { font-size: 18px; }
    .wrt-draft-p, .wrt-draft-preview p { font-size: 14px; }
    .wrt-draft-stats { flex-direction: column; gap: 4px; align-items: flex-end; }
}

@media (max-width: 768px) {
    .wrt-modal { padding: 20px; max-width: 95%; }
    .wrt-detail { padding: 16px; }
    .wrt-detail__body { flex-direction: column; }
    .wrt-detail__sidebar { width: 100%; }
    .wrt-settings-grid { grid-template-columns: 1fr; }
    .wrt-header { flex-direction: column; align-items: stretch; }
    .wrt-search-input { min-width: 0; }
    .wrt-table__td-keyword { display: none; }
    .wrt-table th:nth-child(3) { display: none; }
    .wrt-table td, .wrt-table th { padding: 10px 8px; }
}

/* ヘルプアイコン */
.wrt-help-trigger { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
.wrt-help-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 16px; height: 16px; border-radius: 50%;
    background: var(--mw-bg-tertiary, #e2e8f0); color: var(--mw-text-tertiary);
    font-size: 10px; font-weight: 700; line-height: 1; flex-shrink: 0;
    transition: background 0.15s, color 0.15s;
}
.wrt-help-trigger:hover .wrt-help-icon { background: var(--mw-primary-blue, #4A90A4); color: #fff; }

/* ヘルプモーダル内部 */
.wrt-help-items { display: flex; flex-direction: column; gap: 16px; }
.wrt-help-item { padding: 14px 16px; background: var(--mw-bg-secondary); border: 1px solid var(--mw-border-light); border-radius: 8px; }
.wrt-help-item__name { font-size: 14px; font-weight: 700; color: var(--mw-text-heading); margin-bottom: 6px; }
.wrt-help-item__desc { margin-bottom: 6px; }
.wrt-help-item__meta { font-size: 12px; color: var(--mw-text-tertiary); }
.wrt-help-item__meta strong { color: var(--mw-text-secondary); font-weight: 600; }
</style>

<div class="content-area">

    <!-- プログレス -->
    <div class="wrt-progress" id="wrtProgress">
        <div class="wrt-progress__inner">
            <div class="wrt-progress__spinner"></div>
            <div class="wrt-progress__text" id="wrtProgressText">処理中…</div>
        </div>
    </div>
    <div class="wrt-toast" id="wrtToast"></div>

    <!-- ===== タブ ===== -->
    <div class="wrt-tabs">
        <button class="wrt-tabs__tab active" data-tab="articles">記事一覧</button>
        <button class="wrt-tabs__tab" data-tab="knowledge">情報ストック</button>
        <button class="wrt-tabs__tab" data-tab="auto-article">自動記事</button>
    </div>

    <!-- ===== 記事一覧タブ ===== -->
    <div class="wrt-tab-panel active" id="wrtPanelArticles">
        <!-- ヘッダー: 検索・フィルタ・作成ボタン -->
        <div class="wrt-header">
            <input type="text" id="wrtSearchInput" class="wrt-search-input" placeholder="記事タイトルを検索">
            <select id="wrtStatusFilter" class="wrt-status-filter">
                <option value="">すべて</option>
                <option value="keyword_set">キーワード設定済</option>
                <option value="outline_generated">構成案あり</option>
                <option value="draft_generated">本文あり</option>
                <option value="wp_draft_saved">WP下書き済</option>
            </select>
            <button class="wrt-btn wrt-btn--primary" id="wrtNewArticleBtn" type="button">+ 作成する</button>
        </div>

        <!-- 一括操作バー -->
        <div class="wrt-bulk-bar" id="wrtBulkBar" style="display:none;">
            <span id="wrtBulkCount">0</span>件選択中
            <button class="wrt-btn wrt-btn--danger wrt-btn--sm" id="wrtBulkDeleteBtn" type="button">選択した記事を削除</button>
        </div>

        <!-- テーブル -->
        <div class="wrt-table-wrap">
            <table class="wrt-table" id="wrtArticleTable">
                <thead>
                    <tr>
                        <th class="wrt-table__th-check"><input type="checkbox" id="wrtCheckAll"></th>
                        <th>タイトル</th>
                        <th>キーワード</th>
                        <th class="wrt-table__th-date">作成日</th>
                    </tr>
                </thead>
                <tbody id="wrtArticleList"></tbody>
            </table>
        </div>
        <div class="wrt-empty" id="wrtArticleEmpty">
            <div class="wrt-empty__icon">✍️</div>
            <div class="wrt-empty__text">まだ記事がありません</div>
        </div>
        <!-- ページネーション -->
        <div class="wrt-pagination" id="wrtPagination"></div>
    </div>

    <!-- ===== 情報ストックタブ ===== -->
    <div class="wrt-tab-panel" id="wrtPanelKnowledge">
        <div class="wrt-kb-header">
            <div class="wrt-kb-filters" id="wrtKbFilters"></div>
            <button class="wrt-btn wrt-btn--primary wrt-btn--sm" id="wrtNewKnowledgeBtn" type="button">+ 情報を追加</button>
        </div>
        <div id="wrtKnowledgeList"></div>
        <div class="wrt-empty" id="wrtKnowledgeEmpty" style="display:none;">
            <div class="wrt-empty__icon">📚</div>
            <div class="wrt-empty__text">情報ストックがありません</div>
        </div>
    </div>

    <!-- ===== 自動記事タブ ===== -->
    <div class="wrt-tab-panel" id="wrtPanelAutoArticle">
        <!-- 設定パネル -->
        <div class="aa-settings-panel" style="background:var(--mw-bg-primary);border:1px solid var(--mw-border-light);border-radius:12px;padding:20px;margin-bottom:20px;">
            <h3 style="margin:0 0 16px;font-size:16px;color:var(--mw-text-heading);">自動記事生成 設定</h3>
            <!-- 現在の設定サマリー -->
            <div id="aaSettingsSummary" style="display:none;margin-bottom:16px;padding:12px 16px;background:rgba(86,129,132,0.06);border-radius:8px;font-size:13px;color:var(--mw-text-secondary);line-height:1.6;"></div>
            <div class="aa-settings-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;">
                <div class="aa-setting-item">
                    <label style="display:flex;align-items:center;gap:8px;font-size:14px;color:var(--mw-text-primary);cursor:pointer;">
                        <input type="checkbox" id="aaEnabled">
                        <span>自動記事生成を有効にする</span>
                    </label>
                </div>
                <div class="aa-setting-item">
                    <label style="font-size:13px;color:var(--mw-text-secondary);display:block;margin-bottom:4px;">実行頻度</label>
                    <select id="aaFrequency" style="padding:8px 12px;border:1px solid var(--mw-border-light);border-radius:8px;font-size:14px;background:var(--mw-bg-primary);color:var(--mw-text-primary);width:100%;">
                        <option value="weekly_1">週1回（火曜）</option>
                        <option value="weekly_2" selected>週2回（火・金）</option>
                        <option value="weekly_3">週3回（月・水・金）</option>
                        <option value="daily">毎日</option>
                    </select>
                    <div style="font-size:11px;color:var(--mw-text-tertiary);margin-top:4px;">週2回が品質と運用負荷のバランスが取りやすいおすすめ設定です</div>
                </div>
                <div class="aa-setting-item">
                    <label style="font-size:13px;color:var(--mw-text-secondary);display:block;margin-bottom:4px;">1回あたり生成数</label>
                    <select id="aaBatchSize" style="padding:8px 12px;border:1px solid var(--mw-border-light);border-radius:8px;font-size:14px;background:var(--mw-bg-primary);color:var(--mw-text-primary);width:100%;">
                        <option value="1" selected>1件</option>
                        <option value="2">2件</option>
                        <option value="3">3件</option>
                        <option value="4">4件</option>
                        <option value="5">5件</option>
                    </select>
                    <div style="font-size:11px;color:var(--mw-text-tertiary);margin-top:4px;">生成数を増やすとAPIコストや重複判定負荷が増えます</div>
                </div>
                <div class="aa-setting-item">
                    <label style="font-size:13px;color:var(--mw-text-secondary);display:block;margin-bottom:4px;">最低優先スコア</label>
                    <input type="number" id="aaMinScore" min="0" max="100" value="40" style="padding:8px 12px;border:1px solid var(--mw-border-light);border-radius:8px;font-size:14px;background:var(--mw-bg-primary);color:var(--mw-text-primary);width:100%;box-sizing:border-box;">
                </div>
                <div class="aa-setting-item">
                    <label style="font-size:13px;color:var(--mw-text-secondary);display:block;margin-bottom:4px;">品質閾値</label>
                    <input type="number" id="aaQualityThreshold" min="0" max="100" value="60" style="padding:8px 12px;border:1px solid var(--mw-border-light);border-radius:8px;font-size:14px;background:var(--mw-bg-primary);color:var(--mw-text-primary);width:100%;box-sizing:border-box;">
                </div>
                <div class="aa-setting-item">
                    <label style="display:flex;align-items:center;gap:8px;font-size:14px;color:var(--mw-text-primary);cursor:pointer;">
                        <input type="checkbox" id="aaAutoPublish">
                        <span>品質基準を満たした記事を自動公開する</span>
                    </label>
                    <div style="font-size:11px;color:var(--mw-text-tertiary);margin-top:4px;">自動公開は別設定で制御してください</div>
                </div>
                <div class="aa-setting-item">
                    <label style="font-size:13px;color:var(--mw-text-secondary);display:block;margin-bottom:4px;">デフォルト文体</label>
                    <select id="aaPreferredTone" style="padding:8px 12px;border:1px solid var(--mw-border-light);border-radius:8px;font-size:14px;background:var(--mw-bg-primary);color:var(--mw-text-primary);width:100%;">
                        <option value="natural">自然で読みやすく</option>
                        <option value="friendly">やさしく丁寧に</option>
                        <option value="trustworthy">信頼感重視</option>
                        <option value="professional">専門的に</option>
                        <option value="casual">親しみやすく</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:16px;display:flex;gap:8px;">
                <button class="wrt-btn wrt-btn--primary wrt-btn--sm" id="aaSaveSettings" type="button">設定を保存</button>
            </div>
        </div>

        <!-- 候補プレビュー -->
        <div style="margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <h3 style="margin:0;font-size:16px;color:var(--mw-text-heading);">記事化候補キーワード</h3>
                <button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="aaLoadPreview" type="button">候補を確認</button>
            </div>
            <div class="wrt-table-wrap" id="aaPreviewWrap" style="display:none;">
                <table class="wrt-table">
                    <thead>
                        <tr>
                            <th>キーワード</th>
                            <th>グループ</th>
                            <th style="text-align:right;">スコア</th>
                            <th style="text-align:right;">ボリューム</th>
                            <th style="text-align:right;">難易度</th>
                            <th style="width:100px;"></th>
                        </tr>
                    </thead>
                    <tbody id="aaPreviewBody"></tbody>
                </table>
            </div>
            <div id="aaPreviewEmpty" style="display:none;text-align:center;padding:40px;color:var(--mw-text-tertiary);font-size:14px;">
                キーワード調査結果がないか、条件に合う候補がありません。
            </div>
            <div id="aaPreviewLoading" style="display:none;text-align:center;padding:40px;color:var(--mw-text-tertiary);font-size:14px;">
                候補を分析中...
            </div>
        </div>

        <!-- 生成履歴 -->
        <div>
            <h3 style="margin:0 0 12px;font-size:16px;color:var(--mw-text-heading);">生成履歴</h3>
            <div class="wrt-table-wrap" id="aaHistoryWrap" style="display:none;">
                <table class="wrt-table">
                    <thead>
                        <tr>
                            <th>キーワード</th>
                            <th>グループ</th>
                            <th style="text-align:center;">ステータス</th>
                            <th style="text-align:right;">スコア</th>
                            <th style="text-align:right;">品質</th>
                            <th>日時</th>
                            <th style="width:80px;"></th>
                        </tr>
                    </thead>
                    <tbody id="aaHistoryBody"></tbody>
                </table>
            </div>
            <div id="aaHistoryEmpty" style="text-align:center;padding:40px;color:var(--mw-text-tertiary);font-size:14px;">
                まだ自動生成の履歴はありません。
            </div>
        </div>
    </div>

    <!-- ===== 記事詳細ビュー ===== -->
    <div id="wrtDetailView" style="display:none;"></div>

    <!-- ===== キーワード入力モーダル ===== -->
    <div class="wrt-modal-overlay" id="wrtKeywordModal">
        <div class="wrt-modal">
            <button class="wrt-modal__close" id="wrtKeywordModalClose" type="button">&times;</button>
            <h2 class="wrt-modal__title">記事を作成する</h2>
            <div class="wrt-modal__field">
                <label for="wrtKeywordInput">対策キーワード</label>
                <input type="text" id="wrtKeywordInput" placeholder="対策キーワードを入力" autocomplete="off">
            </div>
            <div class="wrt-rank-kw-section" id="wrtRankKwSection" style="display:none;">
                <div class="wrt-rank-kw-section__title">順位計測キーワードから選ぶ</div>
                <div class="wrt-rank-kw-list" id="wrtRankKwList"></div>
            </div>
            <div class="wrt-modal__actions">
                <button class="wrt-btn wrt-btn--secondary" id="wrtKeywordModalCancel" type="button">キャンセル</button>
                <button class="wrt-btn wrt-btn--primary" id="wrtKeywordSubmit" type="button" disabled>記事を作成する</button>
            </div>
        </div>
    </div>

    <!-- ===== 情報ストック編集モーダル ===== -->
    <div class="wrt-modal-overlay" id="wrtKnowledgeModal">
        <div class="wrt-modal">
            <button class="wrt-modal__close" id="wrtKnowledgeModalClose" type="button">&times;</button>
            <h2 class="wrt-modal__title" id="wrtKnowledgeModalTitle">情報を追加</h2>
            <input type="hidden" id="wrtKnowledgeId" value="0">
            <div class="wrt-modal__field">
                <label for="wrtKnowledgeTitleInput">タイトル</label>
                <input type="text" id="wrtKnowledgeTitleInput" placeholder="情報のタイトル">
            </div>
            <div class="wrt-modal__field">
                <label for="wrtKnowledgeCategorySelect">種別</label>
                <select id="wrtKnowledgeCategorySelect">
                    <?php foreach ( Gcrev_Writing_Service::KNOWLEDGE_CATEGORIES as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="wrt-modal__field">
                <label for="wrtKnowledgeContent">内容</label>
                <textarea id="wrtKnowledgeContent" rows="8" placeholder="テキストを入力してください"></textarea>
            </div>
            <div class="wrt-modal__field">
                <label for="wrtKnowledgePriority">優先度</label>
                <select id="wrtKnowledgePriority">
                    <option value="1">1（低）</option>
                    <option value="2">2</option>
                    <option value="3" selected>3（標準）</option>
                    <option value="4">4</option>
                    <option value="5">5（高）</option>
                </select>
            </div>
            <div class="wrt-modal__field" id="wrtKnowledgeFileSection">
                <label>添付ファイル</label>
                <div id="wrtKnowledgeFileList" style="margin-bottom:8px;"></div>
                <div class="wrt-dropzone" id="wrtDropzone">
                    <div class="wrt-dropzone__inner" id="wrtDropzoneInner">
                        <div class="wrt-dropzone__icon">📎</div>
                        <div class="wrt-dropzone__text">ファイルをドラッグ＆ドロップ</div>
                        <div class="wrt-dropzone__sub">または <label for="wrtKnowledgeFileInput" class="wrt-dropzone__link">ファイルを選択</label></div>
                        <input type="file" id="wrtKnowledgeFileInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png,.gif,.webp" style="display:none;">
                    </div>
                </div>
                <div style="font-size:11px;color:var(--mw-text-tertiary);margin-top:4px;">PDF, Word, Excel, CSV, テキスト, 画像に対応</div>
            </div>
            <div class="wrt-modal__actions">
                <button class="wrt-btn wrt-btn--secondary" id="wrtKnowledgeModalCancel" type="button">キャンセル</button>
                <button class="wrt-btn wrt-btn--primary" id="wrtKnowledgeSaveBtn" type="button">保存</button>
            </div>
        </div>
    </div>

    <!-- ===== 音声入力モーダル ===== -->
    <div class="wrt-voice-modal" id="wrtVoiceModal">
        <div class="wrt-voice-modal__inner">
            <div class="wrt-voice-modal__title" id="wrtVoiceTitle">音声入力</div>
            <div class="wrt-voice-modal__status" id="wrtVoiceStatus">マイクボタンを押して録音開始</div>
            <canvas class="wrt-voice-modal__wave" id="wrtVoiceWave" width="400" height="80"></canvas>
            <textarea class="wrt-voice-modal__result" id="wrtVoiceResult" rows="3" placeholder="認識されたテキストがここに表示されます"></textarea>
            <div class="wrt-voice-modal__actions" id="wrtVoiceActions">
                <button class="wrt-voice-modal__rec-btn" id="wrtVoiceRecBtn" title="録音開始">🎤</button>
            </div>
            <div class="wrt-voice-modal__actions" id="wrtVoiceConfirmActions" style="display:none;">
                <button class="wrt-btn wrt-btn--secondary" id="wrtVoiceRetryBtn">やり直す</button>
                <button class="wrt-btn wrt-btn--primary" id="wrtVoiceOkBtn">OK</button>
                <button class="wrt-btn wrt-btn--secondary" id="wrtVoiceCancelBtn">キャンセル</button>
            </div>
        </div>
    </div>

    <!-- ===== 類似記事チェックモーダル ===== -->
    <!-- ===== 記事タイプ・目的ヘルプモーダル ===== -->
    <div class="wrt-modal-overlay" id="wrtHelpModal">
        <div class="wrt-modal" style="max-width:540px;">
            <button class="wrt-modal__close" id="wrtHelpModalClose" type="button">&times;</button>
            <h2 class="wrt-modal__title" id="wrtHelpModalTitle"></h2>
            <div id="wrtHelpModalBody" style="font-size:13px;line-height:1.8;color:var(--mw-text-secondary);"></div>
            <div class="wrt-modal__actions">
                <button class="wrt-btn wrt-btn--secondary" id="wrtHelpModalCloseBtn" type="button">閉じる</button>
            </div>
        </div>
    </div>

    <div class="wrt-modal-overlay" id="wrtSimilarityModal">
        <div class="wrt-modal" style="max-width:600px;">
            <button class="wrt-modal__close" id="wrtSimilarityModalClose" type="button">&times;</button>
            <h2 class="wrt-modal__title">類似記事の確認</h2>
            <div id="wrtSimilarityContent"></div>
            <div class="wrt-modal__actions">
                <button class="wrt-btn wrt-btn--secondary" id="wrtSimilarityCancelBtn" type="button">キャンセル</button>
                <button class="wrt-btn wrt-btn--primary" id="wrtSimilarityProceedBtn" type="button">それでも作成する</button>
            </div>
        </div>
    </div>

    <!-- ===== 構成案モーダル ===== -->
    <div class="wrt-modal-overlay" id="wrtOutlineModal">
        <div class="wrt-modal wrt-modal--wide">
            <button class="wrt-modal__close" id="wrtOutlineModalClose" type="button">&times;</button>
            <h2 class="wrt-modal__title">構成案</h2>
            <div id="wrtOutlineModalBody"></div>
            <div class="wrt-modal__actions">
                <button class="wrt-btn wrt-btn--secondary" id="wrtOutlineModalCloseBtn" type="button">閉じる</button>
            </div>
        </div>
    </div>

</div>

<script>
(function() {
    'use strict';

    var userId = <?php echo (int) $user_id; ?>;
    var baseUrl = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gcrev/v1/writing' ) ) ); ?>;
    var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    var initialKeyword = <?php echo wp_json_encode( $initial_keyword ); ?>;

    var catLabels = <?php echo wp_json_encode( Gcrev_Writing_Service::KNOWLEDGE_CATEGORIES, JSON_UNESCAPED_UNICODE ); ?>;
    var typeLabels = <?php echo wp_json_encode( Gcrev_Writing_Service::ARTICLE_TYPES, JSON_UNESCAPED_UNICODE ); ?>;
    var purposeLabels = <?php echo wp_json_encode( Gcrev_Writing_Service::ARTICLE_PURPOSES, JSON_UNESCAPED_UNICODE ); ?>;
    var toneLabels = <?php echo wp_json_encode( Gcrev_Writing_Service::TONES, JSON_UNESCAPED_UNICODE ); ?>;
    var statusLabels = { keyword_set: 'キーワード設定済', outline_generated: '構成案あり', draft_generated: '本文あり', wp_draft_saved: 'WP下書き済' };

    /* 記事タイプ・目的の解説データ */
    var typeDescriptions = {
        explanation: { label: '解説記事', desc: '特定のテーマについて詳しく解説する記事です。読者の「知りたい」に応え、専門知識をわかりやすく伝えます。', example: '「ホームページ制作の流れと費用相場を解説」「SEO対策の基本と始め方」', when: '検索ユーザーが情報収集している段階で接点を作りたいとき。業種の専門性をアピールしたいとき。' },
        comparison: { label: '比較記事', desc: '複数の選択肢を比較し、読者が判断しやすいように整理する記事です。メリット・デメリットを公平に示します。', example: '「WordPress vs Wix — 中小企業に向いているのはどっち？」', when: '読者が選択肢を比較検討している段階。自社サービスの強みを自然に伝えたいとき。' },
        faq: { label: 'FAQ記事', desc: 'よくある質問とその回答をまとめた記事です。読者の疑問や不安を解消し、信頼感を高めます。', example: '「ホームページ制作でよくある質問10選」', when: '問い合わせ前の不安を解消し、コンバージョンにつなげたいとき。' },
        case_study: { label: '事例記事', desc: '実際の事例や成功体験を紹介する記事です。具体的なストーリーで説得力を持たせます。', example: '「松山市の工務店が問い合わせ数を3倍にした方法」', when: '自社の実績を示したいとき。読者に「自分もできそう」と思ってもらいたいとき。' },
        local: { label: '地域訴求記事', desc: '特定の地域に関連した情報を盛り込み、地域SEOを意識した記事です。エリア名を含むキーワードで上位表示を狙います。', example: '「愛媛県で集客に強いホームページ制作会社の選び方」', when: '地域名で検索するユーザーを取り込みたいとき。MEO・ローカルSEOを強化したいとき。' }
    };
    var purposeDescriptions = {
        traffic: { label: 'アクセス獲得', desc: '検索ボリュームの大きいキーワードで上位表示し、サイトへの流入を増やすことを目的とします。', point: '情報提供を主軸に、幅広い読者に役立つ内容を書きます。直接的な問い合わせ誘導よりも、まずサイトを知ってもらうことを優先します。' },
        inquiry: { label: '問い合わせ獲得', desc: '記事を読んだ読者が問い合わせや相談につながることを目的とします。', point: '読者の課題を具体的に示し、「相談してみよう」と思える流れを作ります。CTAは自然に、押し売りにならないように配置します。' },
        local_seo: { label: '地域SEO', desc: '「地域名＋サービス名」などの地域キーワードで検索上位を目指す記事です。', point: '地域の特性やニーズに触れながら、エリア名を自然に盛り込みます。Googleマップ検索との相乗効果も期待できます。' },
        comparison: { label: '比較検討対策', desc: '競合や代替手段と比較検討している読者に向けた記事です。', point: '読者が「どこに頼むか」「どの方法がいいか」を判断する材料を提供します。自社の優位点は事実ベースで控えめに伝えます。' },
        brand: { label: '指名検索補強', desc: '会社名やサービス名で検索したときに、充実した情報が表示されることを目的とします。', point: '自社の理念・強み・実績など、指名検索したユーザーが安心できる情報を整理します。信頼感の醸成が最優先です。' }
    };

    // 現在のデータ
    var articlesData = [];
    var knowledgeData = [];
    var currentArticle = null;

    function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    /* ===== API ===== */
    function apiFetch(path, opts) {
        opts = opts || {};
        var url = baseUrl + path;
        var config = {
            method: opts.method || 'GET',
            headers: { 'X-WP-Nonce': nonce },
        };
        if (opts.body) {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify(opts.body);
        }
        return fetch(url, config).then(function(r) { return r.json(); });
    }

    /* ===== ユーティリティ ===== */
    function showProgress(msg) { document.getElementById('wrtProgressText').textContent = msg || '処理中…'; document.getElementById('wrtProgress').classList.add('active'); }
    function hideProgress() { document.getElementById('wrtProgress').classList.remove('active'); }
    function showToast(msg, isError) {
        var el = document.getElementById('wrtToast');
        el.textContent = msg; el.className = 'wrt-toast active' + (isError ? ' wrt-toast--error' : '');
        setTimeout(function() { el.className = 'wrt-toast'; }, 4000);
    }

    /* ===== タブ切り替え ===== */
    document.querySelectorAll('.wrt-tabs__tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.wrt-tabs__tab').forEach(function(t) { t.classList.remove('active'); });
            document.querySelectorAll('.wrt-tab-panel').forEach(function(p) { p.classList.remove('active'); });
            tab.classList.add('active');
            var panelId = 'wrtPanel' + tab.dataset.tab.split('-').map(function(s) { return s.charAt(0).toUpperCase() + s.slice(1); }).join('');
            var panel = document.getElementById(panelId);
            if (panel) panel.classList.add('active');
            // 詳細ビューを閉じる
            document.getElementById('wrtDetailView').style.display = 'none';
            document.querySelectorAll('.wrt-tabs, .wrt-tab-panel').forEach(function(el) { el.style.display = ''; });
        });
    });

    /* ===== キーワードモーダル ===== */
    var kwInput = document.getElementById('wrtKeywordInput');
    var kwSubmit = document.getElementById('wrtKeywordSubmit');

    function openKeywordModal(prefill) {
        kwInput.value = prefill || '';
        kwSubmit.disabled = !(kwInput.value.trim());
        document.getElementById('wrtKeywordModal').classList.add('active');
        setTimeout(function() { kwInput.focus(); }, 100);
        loadRankKeywords();
    }
    function closeKeywordModal() { document.getElementById('wrtKeywordModal').classList.remove('active'); }

    kwInput.addEventListener('input', function() { kwSubmit.disabled = !kwInput.value.trim(); updateRankKwSelection(); });
    kwInput.addEventListener('keydown', function(e) { if (e.key === 'Enter' && kwInput.value.trim()) { createArticle(kwInput.value.trim()); } });
    document.getElementById('wrtKeywordModalClose').addEventListener('click', closeKeywordModal);
    document.getElementById('wrtKeywordModalCancel').addEventListener('click', closeKeywordModal);
    kwSubmit.addEventListener('click', function() { if (kwInput.value.trim()) createArticle(kwInput.value.trim()); });
    document.getElementById('wrtNewArticleBtn').addEventListener('click', function() { openKeywordModal(''); });
    document.getElementById('wrtKeywordModal').addEventListener('click', function(e) { if (e.target === this) closeKeywordModal(); });

    function loadRankKeywords() {
        apiFetch('/rank-keywords').then(function(res) {
            var kws = (res.keywords || []);
            var section = document.getElementById('wrtRankKwSection');
            if (kws.length === 0) { section.style.display = 'none'; return; }
            section.style.display = '';
            var list = document.getElementById('wrtRankKwList');
            list.innerHTML = '';
            kws.forEach(function(kw) {
                var chip = document.createElement('span');
                chip.className = 'wrt-rank-kw-chip';
                chip.textContent = kw.keyword;
                chip.addEventListener('click', function() { kwInput.value = kw.keyword; kwSubmit.disabled = false; updateRankKwSelection(); });
                list.appendChild(chip);
            });
            updateRankKwSelection();
        });
    }
    function updateRankKwSelection() {
        var val = kwInput.value.trim().toLowerCase();
        document.querySelectorAll('.wrt-rank-kw-chip').forEach(function(chip) {
            chip.classList.toggle('selected', chip.textContent.toLowerCase() === val);
        });
    }

    /* ===== 記事 CRUD ===== */
    function loadArticles() {
        apiFetch('/articles').then(function(res) {
            articlesData = res.items || [];
            renderArticles();
        });
    }
    var articlesPage = 1;
    var articlesPerPage = 20;

    function getFilteredArticles() {
        var search = document.getElementById('wrtSearchInput').value.trim().toLowerCase();
        var statusFilter = document.getElementById('wrtStatusFilter').value;
        return articlesData.filter(function(a) {
            if (search && (a.title || '').toLowerCase().indexOf(search) === -1 && (a.keyword || '').toLowerCase().indexOf(search) === -1) return false;
            if (statusFilter && a.status !== statusFilter) return false;
            return true;
        });
    }

    function renderArticles() {
        var container = document.getElementById('wrtArticleList');
        var empty = document.getElementById('wrtArticleEmpty');
        var tableWrap = document.querySelector('.wrt-table-wrap');
        var filtered = getFilteredArticles();

        if (filtered.length === 0) {
            container.innerHTML = '';
            empty.style.display = '';
            tableWrap.style.display = 'none';
            document.getElementById('wrtPagination').innerHTML = '';
            return;
        }
        empty.style.display = 'none';
        tableWrap.style.display = '';

        var totalPages = Math.ceil(filtered.length / articlesPerPage);
        if (articlesPage > totalPages) articlesPage = totalPages;
        var start = (articlesPage - 1) * articlesPerPage;
        var pageItems = filtered.slice(start, start + articlesPerPage);

        container.innerHTML = pageItems.map(function(a) {
            var dateStr = a.created_at ? a.created_at.replace(/-/g, '/').substring(0, 10) : '';
            var riskBadge = '';
            if (a.similarity_risk === 'high') {
                riskBadge = ' <span style="display:inline-block;padding:1px 6px;font-size:10px;border-radius:4px;background:#C95A4F20;color:#C95A4F;">重複注意</span>';
            } else if (a.similarity_risk === 'medium') {
                riskBadge = ' <span style="display:inline-block;padding:1px 6px;font-size:10px;border-radius:4px;background:#D4A84320;color:#D4A843;">類似あり</span>';
            }
            var autoBadge = '';
            if (a.auto_generated) {
                autoBadge = ' <span style="display:inline-block;padding:1px 6px;font-size:10px;border-radius:4px;background:rgba(86,129,132,0.12);color:var(--mw-primary-blue);">自動</span>';
                if (a.auto_quality_score !== null) {
                    var qc = a.auto_quality_score >= 70 ? '#27AE60' : (a.auto_quality_score >= 50 ? '#E67E22' : '#E74C3C');
                    autoBadge += ' <span style="display:inline-block;padding:1px 6px;font-size:10px;border-radius:4px;background:' + qc + '20;color:' + qc + ';">Q' + Math.round(a.auto_quality_score) + '</span>';
                }
                if (a.needs_hearing) {
                    autoBadge += ' <span style="display:inline-block;padding:1px 6px;font-size:10px;border-radius:4px;background:#E67E2220;color:#E67E22;">要インタビュー</span>';
                }
            }
            return '<tr class="wrt-table__row" data-id="' + a.id + '">'
                + '<td class="wrt-table__td-check"><input type="checkbox" class="wrt-article-check" data-id="' + a.id + '"></td>'
                + '<td class="wrt-table__td-title">' + esc(a.title) + riskBadge + autoBadge + '</td>'
                + '<td class="wrt-table__td-keyword">' + esc(a.keyword) + '</td>'
                + '<td class="wrt-table__td-date">' + esc(dateStr) + '</td>'
                + '</tr>';
        }).join('');

        // 行クリックで詳細
        container.querySelectorAll('.wrt-table__row').forEach(function(row) {
            row.addEventListener('click', function(e) {
                if (e.target.type === 'checkbox') return;
                showArticleDetail(parseInt(row.dataset.id));
            });
        });

        // ページネーション
        renderPagination(filtered.length, totalPages);
    }

    function renderPagination(total, totalPages) {
        var pag = document.getElementById('wrtPagination');
        if (totalPages <= 1) { pag.innerHTML = '<span class="wrt-pag__count">記事数: ' + total + '</span>'; return; }
        pag.innerHTML = '<span class="wrt-pag__count">記事数: ' + total + '</span>'
            + '<div class="wrt-pag__nav">'
            + '<button class="wrt-pag__btn" id="wrtPagPrev" ' + (articlesPage <= 1 ? 'disabled' : '') + '>&lt;</button>'
            + '<span class="wrt-pag__info">' + articlesPage + '/' + totalPages + '</span>'
            + '<button class="wrt-pag__btn" id="wrtPagNext" ' + (articlesPage >= totalPages ? 'disabled' : '') + '>&gt;</button>'
            + '</div>';
        document.getElementById('wrtPagPrev').addEventListener('click', function() { if (articlesPage > 1) { articlesPage--; renderArticles(); } });
        document.getElementById('wrtPagNext').addEventListener('click', function() { if (articlesPage < totalPages) { articlesPage++; renderArticles(); } });
    }

    // 検索・フィルタイベント
    document.getElementById('wrtSearchInput').addEventListener('input', function() { articlesPage = 1; renderArticles(); });
    document.getElementById('wrtStatusFilter').addEventListener('change', function() { articlesPage = 1; renderArticles(); });
    // 全選択チェックボックス
    document.getElementById('wrtCheckAll').addEventListener('change', function() {
        var checked = this.checked;
        document.querySelectorAll('.wrt-article-check').forEach(function(cb) { cb.checked = checked; });
        updateBulkBar();
    });

    // チェックボックス変更でバルクバー更新（イベント委任）
    document.getElementById('wrtArticleList').addEventListener('change', function(e) {
        if (e.target.classList.contains('wrt-article-check')) {
            updateBulkBar();
        }
    });

    function getSelectedArticleIds() {
        var ids = [];
        document.querySelectorAll('.wrt-article-check:checked').forEach(function(cb) {
            ids.push(parseInt(cb.dataset.id));
        });
        return ids;
    }

    function updateBulkBar() {
        var ids = getSelectedArticleIds();
        var bar = document.getElementById('wrtBulkBar');
        if (ids.length > 0) {
            bar.style.display = '';
            document.getElementById('wrtBulkCount').textContent = ids.length;
        } else {
            bar.style.display = 'none';
        }
    }

    // 一括削除ボタン
    document.getElementById('wrtBulkDeleteBtn').addEventListener('click', function() {
        var ids = getSelectedArticleIds();
        if (ids.length === 0) return;
        if (!confirm(ids.length + '件の記事を削除しますか？この操作は取り消せません。')) return;
        showProgress(ids.length + '件の記事を削除中…');
        apiFetch('/articles/bulk-delete', { method: 'POST', body: { ids: ids } }).then(function(res) {
            hideProgress();
            if (res.success) {
                showToast(res.deleted + '件の記事を削除しました');
                document.getElementById('wrtCheckAll').checked = false;
                updateBulkBar();
                loadArticles();
            } else {
                showToast(res.error || 'エラーが発生しました', true);
            }
        }).catch(function() { hideProgress(); showToast('通信エラー', true); });
    });

    function createArticle(keyword) {
        closeKeywordModal();
        showProgress('類似記事をチェック中…');
        apiFetch('/check-similarity', { method: 'POST', body: { keyword: keyword } }).then(function(res) {
            hideProgress();
            if (res.success && res.result && (res.result.risk_level === 'high' || res.result.risk_level === 'medium')) {
                showSimilarityWarning(keyword, res.result);
            } else {
                proceedWithArticleCreation(keyword);
            }
        }).catch(function() {
            hideProgress();
            proceedWithArticleCreation(keyword);
        });
    }
    function proceedWithArticleCreation(keyword) {
        showProgress('記事を作成中…（競合調査・構成案を自動生成しています。2〜3分かかります）');
        apiFetch('/articles', { method: 'POST', body: { keyword: keyword } }).then(function(res) {
            hideProgress();
            if (!res.success) { showToast(res.error || 'エラー', true); return; }
            showToast('記事「' + keyword + '」を作成しました');
            loadArticles();
            showArticleDetail(res.article.id);
        }).catch(function() { hideProgress(); showToast('通信エラー', true); });
    }
    var pendingSimilarityKeyword = '';
    function showSimilarityWarning(keyword, result) {
        pendingSimilarityKeyword = keyword;
        var content = document.getElementById('wrtSimilarityContent');
        var riskColors = { high: '#C95A4F', medium: '#D4A843', low: '#4A90A4' };
        var riskLabels = { high: '重複リスク: 高', medium: '重複リスク: 中', low: '重複リスク: 低' };
        var html = '<div style="padding:16px 0;">';
        html += '<div style="background:' + (riskColors[result.risk_level] || '#999') + '15;border-left:4px solid ' + (riskColors[result.risk_level] || '#999') + ';padding:12px 16px;border-radius:4px;margin-bottom:16px;">';
        html += '<strong style="color:' + (riskColors[result.risk_level] || '#999') + ';">' + esc(riskLabels[result.risk_level] || '') + '</strong>';
        if (result.overall_suggestion) {
            html += '<p style="margin:8px 0 0;font-size:13px;color:var(--mw-text-secondary);">' + esc(result.overall_suggestion) + '</p>';
        }
        html += '</div>';
        if (result.similar_articles && result.similar_articles.length > 0) {
            html += '<div style="font-size:14px;font-weight:600;margin-bottom:8px;">類似する既存記事</div>';
            result.similar_articles.forEach(function(sa) {
                var simColor = sa.similarity === 'high' ? '#C95A4F' : '#D4A843';
                html += '<div style="padding:10px;border:1px solid var(--mw-border-light);border-radius:6px;margin-bottom:8px;">';
                html += '<div style="font-weight:500;">' + esc(sa.title || sa.keyword) + '</div>';
                html += '<div style="font-size:12px;color:var(--mw-text-tertiary);margin-top:2px;">キーワード: ' + esc(sa.keyword) + '</div>';
                html += '<div style="font-size:12px;color:' + simColor + ';margin-top:4px;">' + esc(sa.reason) + '</div>';
                if (sa.differentiation_suggestion) {
                    html += '<div style="font-size:12px;color:#4E8A6B;margin-top:4px;">→ ' + esc(sa.differentiation_suggestion) + '</div>';
                }
                html += '</div>';
            });
        }
        if (result.suggested_angles && result.suggested_angles.length > 0) {
            html += '<div style="font-size:14px;font-weight:600;margin:12px 0 8px;">別の切り口を提案 <span style="font-size:11px;font-weight:400;color:var(--mw-text-tertiary);">クリックで選択</span></div>';
            result.suggested_angles.forEach(function(angle, idx) {
                html += '<div class="wrt-suggested-angle" data-angle-idx="' + idx + '" style="padding:8px 12px;background:var(--mw-bg-secondary);border:1px solid var(--mw-border-light);border-radius:6px;margin-bottom:4px;font-size:13px;cursor:pointer;transition:all 0.15s;" onmouseover="this.style.background=\'#4E8A6B\';this.style.color=\'#fff\';this.style.borderColor=\'#4E8A6B\';" onmouseout="this.style.background=\'\';this.style.color=\'\';this.style.borderColor=\'\';">・' + esc(angle) + '</div>';
            });
        }
        html += '</div>';
        content.innerHTML = html;
        // 別の切り口クリック → その切り口で記事作成
        content.querySelectorAll('.wrt-suggested-angle').forEach(function(el) {
            el.addEventListener('click', function() {
                var angle = result.suggested_angles[parseInt(el.dataset.angleIdx)];
                if (!angle) return;
                document.getElementById('wrtSimilarityModal').classList.remove('active');
                pendingSimilarityKeyword = '';
                proceedWithArticleCreation(angle);
            });
        });
        document.getElementById('wrtSimilarityModal').classList.add('active');
    }
    document.getElementById('wrtSimilarityProceedBtn').addEventListener('click', function() {
        document.getElementById('wrtSimilarityModal').classList.remove('active');
        if (pendingSimilarityKeyword) proceedWithArticleCreation(pendingSimilarityKeyword);
        pendingSimilarityKeyword = '';
    });
    document.getElementById('wrtSimilarityCancelBtn').addEventListener('click', function() {
        document.getElementById('wrtSimilarityModal').classList.remove('active');
        pendingSimilarityKeyword = '';
    });
    document.getElementById('wrtSimilarityModalClose').addEventListener('click', function() {
        document.getElementById('wrtSimilarityModal').classList.remove('active');
        pendingSimilarityKeyword = '';
    });

    /* ===== 記事タイプ・目的ヘルプモーダル ===== */
    function closeHelpModal() { document.getElementById('wrtHelpModal').classList.remove('active'); }
    document.getElementById('wrtHelpModalClose').addEventListener('click', closeHelpModal);
    document.getElementById('wrtHelpModalCloseBtn').addEventListener('click', closeHelpModal);
    document.getElementById('wrtHelpModal').addEventListener('click', function(e) { if (e.target === this) closeHelpModal(); });

    function showHelpModal(kind) {
        var title, items;
        if (kind === 'type') {
            title = '記事タイプの解説';
            items = typeDescriptions;
        } else {
            title = '記事の目的の解説';
            items = purposeDescriptions;
        }
        document.getElementById('wrtHelpModalTitle').textContent = title;
        var html = '<div class="wrt-help-items">';
        Object.keys(items).forEach(function(k) {
            var it = items[k];
            html += '<div class="wrt-help-item">';
            html += '<div class="wrt-help-item__name">' + esc(it.label) + '</div>';
            html += '<div class="wrt-help-item__desc">' + esc(it.desc) + '</div>';
            if (it.example) {
                html += '<div class="wrt-help-item__meta"><strong>例: </strong>' + esc(it.example) + '</div>';
            }
            var when = it.when || it.point || '';
            if (when) {
                html += '<div class="wrt-help-item__meta"><strong>' + (it.point ? 'ポイント: ' : '向いている場面: ') + '</strong>' + esc(when) + '</div>';
            }
            html += '</div>';
        });
        html += '</div>';
        document.getElementById('wrtHelpModalBody').innerHTML = html;
        document.getElementById('wrtHelpModal').classList.add('active');
    }

    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.wrt-help-trigger');
        if (trigger) {
            e.preventDefault();
            showHelpModal(trigger.dataset.help);
        }
    });

    /* ===== 記事詳細 ===== */
    var _competitorAutoRanFor = {};  // { articleId: true } — 自動競合調査の実行済みフラグ
    function showArticleDetail(id) {
        showProgress('読み込み中…');
        apiFetch('/articles/' + id).then(function(res) {
            hideProgress();
            if (!res.success) { showToast(res.error || 'エラー', true); return; }
            currentArticle = res.article;
            renderArticleDetail();
        }).catch(function() { hideProgress(); showToast('通信エラー', true); });
    }
    function renderArticleDetail() {
        var a = currentArticle;
        // タブ・一覧を隠す
        document.querySelectorAll('.wrt-tabs, .wrt-tab-panel').forEach(function(el) { el.style.display = 'none'; });
        var view = document.getElementById('wrtDetailView');
        view.style.display = '';

        var html = '<div class="wrt-detail">';
        html += '<div class="wrt-detail__topbar">';
        html += '<span class="wrt-detail__back" id="wrtBackBtn">← 一覧に戻る</span>';
        html += '<span style="flex:1;"></span>';
        html += '<button class="wrt-btn wrt-btn--danger wrt-btn--sm" id="wrtDeleteArticleBtn">削除</button>';
        html += '</div>';

        // ===== 2カラムレイアウト開始 =====
        html += '<div class="wrt-detail__body">';

        // --- 左カラム: 本文エディター ---
        html += '<div class="wrt-detail__main">';
        html += '<div class="wrt-detail__keyword-section">';
        html += '<span class="wrt-detail__keyword-label">対策キーワード</span>';
        html += '<span class="wrt-detail__keyword-value">' + esc(a.keyword) + '</span>';
        html += '</div>';

        // 類似記事の警告
        if (a.similarity_result && a.similarity_result.risk_level && a.similarity_result.risk_level !== 'none' && a.similarity_result.risk_level !== 'low') {
            var sr = a.similarity_result;
            var srColor = sr.risk_level === 'high' ? '#C95A4F' : '#D4A843';
            var srLabel = sr.risk_level === 'high' ? '重複リスク: 高' : '重複リスク: 中';
            html += '<div style="background:' + srColor + '10;border:1px solid ' + srColor + '30;border-radius:8px;padding:12px 16px;margin-bottom:16px;">';
            html += '<div style="font-weight:600;color:' + srColor + ';margin-bottom:4px;">' + srLabel + '</div>';
            if (sr.overall_suggestion) {
                html += '<p style="font-size:13px;color:var(--mw-text-secondary);margin:0 0 8px;">' + esc(sr.overall_suggestion) + '</p>';
            }
            if (sr.similar_articles && sr.similar_articles.length > 0) {
                sr.similar_articles.forEach(function(sa) {
                    html += '<div style="padding:6px 8px;background:var(--mw-bg-primary);border-radius:4px;margin-bottom:4px;font-size:12px;">';
                    html += '<span style="font-weight:500;">' + esc(sa.title || sa.keyword) + '</span>';
                    html += ' — <span style="color:var(--mw-text-tertiary);">' + esc(sa.reason) + '</span>';
                    if (sa.differentiation_suggestion) {
                        html += '<div style="color:#4E8A6B;margin-top:2px;">→ ' + esc(sa.differentiation_suggestion) + '</div>';
                    }
                    html += '</div>';
                });
            }
            html += '</div>';
        }

        // 本文生成セクション
        html += '<div class="wrt-detail-section" id="wrtDraftSection">';
        html += '<div class="wrt-detail-section__title">本文生成</div>';
        html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        html += '<button class="wrt-btn wrt-btn--primary" id="wrtGenerateDraftBtn">' + (a.draft_content ? '本文を再生成' : '本文たたき台を生成') + '</button>';
        if (a.outline && a.draft_content) {
            // 本文生成済み → モーダルで構成案を見る
            html += '<button class="wrt-btn wrt-btn--secondary" id="wrtShowOutlineBtn">構成案を見る</button>';
        }
        html += '</div>';
        // 本文未生成 + 構成案あり → インライン表示
        if (a.outline && !a.draft_content) {
            html += '<div id="wrtOutlineInline" style="margin-top:16px;"></div>';
        }
        html += '<div id="wrtDraftArea"></div>';
        html += '</div>';

        // 競合調査セクション
        html += '<div class="wrt-detail-section" id="wrtCompetitorSection">';
        html += '<div class="wrt-detail-section__title">競合調査</div>';
        if (a.competitor_research) {
            var cr = a.competitor_research;
            var an = cr.analysis || {};
            var okCompetitors = (cr.competitors || []).filter(function(c) { return c.status === 'ok'; });
            var ranks = okCompetitors.map(function(c) { return c.rank; }).sort(function(a,b) { return a-b; });
            var rankRange = ranks.length ? ranks[0] + '位〜' + ranks[ranks.length-1] + '位' : '';

            // ヘッダー（件数・順位範囲・日時 + 構成案反映バッジ）
            html += '<div class="wrt-cr-header">';
            html += '<div class="wrt-cr-count">🔎 <strong>' + okCompetitors.length + '件</strong>の競合記事（' + rankRange + '）を分析しました';
            if (cr.fetched_at) html += '　' + esc(cr.fetched_at);
            html += '</div>';
            if (a.outline_json) {
                html += '<span class="wrt-cr-reflected-badge">✓ 構成案に反映済み</span>';
            }
            html += '</div>';

            // 統計情報
            if (an.average_word_count || an.average_heading_count) {
                html += '<div class="wrt-cr-stats">';
                if (an.average_word_count) html += '競合の平均文字数: <strong>約' + an.average_word_count.toLocaleString() + '字</strong>';
                if (an.average_heading_count) html += '平均見出し数: <strong>' + an.average_heading_count + '個</strong>';
                html += '</div>';
            }

            // 検索意図
            if (an.search_intent) {
                html += '<div class="wrt-cr-section">';
                html += '<div class="wrt-cr-section-title">💡 このキーワードの検索意図</div>';
                html += '<div class="wrt-cr-text">' + esc(an.search_intent) + '</div>';
                html += '</div>';
            }

            // 共通トピック（チップ）
            if (an.common_topics && an.common_topics.length) {
                html += '<div class="wrt-cr-section">';
                html += '<div class="wrt-cr-section-title">📋 競合が共通して扱っているトピック</div>';
                html += '<div class="wrt-cr-chips">';
                an.common_topics.forEach(function(t) { html += '<span class="wrt-cr-chip">' + esc(t) + '</span>'; });
                html += '</div></div>';
            }

            // コンテンツギャップ（差別化チャンス）
            if (an.content_gaps && an.content_gaps.length) {
                html += '<div class="wrt-cr-section">';
                html += '<div class="wrt-cr-section-title">🎯 差別化チャンス（競合が扱っていないトピック）</div>';
                an.content_gaps.forEach(function(g) {
                    html += '<div class="wrt-cr-card wrt-cr-card--gap"><div class="wrt-cr-card-label">狙い目</div><div class="wrt-cr-card-text">' + esc(g) + '</div></div>';
                });
                html += '</div>';
            }

            // 競合の強み
            if (an.competitor_strengths && an.competitor_strengths.length) {
                html += '<div class="wrt-cr-section">';
                html += '<div class="wrt-cr-section-title">💪 競合の強い点・参考になる点</div>';
                an.competitor_strengths.forEach(function(s) {
                    html += '<div class="wrt-cr-card wrt-cr-card--strength"><div class="wrt-cr-card-text">' + esc(s) + '</div></div>';
                });
                html += '</div>';
            }

            // 推奨差別化アングル
            if (an.recommended_angles && an.recommended_angles.length) {
                html += '<div class="wrt-cr-section">';
                html += '<div class="wrt-cr-section-title">✨ おすすめの差別化アングル</div>';
                an.recommended_angles.forEach(function(a_) {
                    html += '<div class="wrt-cr-card wrt-cr-card--angle"><div class="wrt-cr-card-label">差別化ポイント</div><div class="wrt-cr-card-text">' + esc(a_) + '</div></div>';
                });
                html += '</div>';
            }

            // 競合記事一覧（折りたたみ）
            if (okCompetitors.length) {
                html += '<details style="margin-top:12px;"><summary style="font-size:12px;color:var(--mw-text-tertiary);cursor:pointer;">📄 競合記事の詳細一覧（' + okCompetitors.length + '件）</summary>';
                html += '<div style="margin-top:8px;">';
                okCompetitors.forEach(function(comp) {
                    html += '<div style="margin-bottom:10px;padding:10px 12px;background:var(--mw-bg-secondary);border-radius:6px;">';
                    html += '<div style="font-size:12px;font-weight:600;color:var(--mw-text-heading);">' + esc(comp.rank) + '位: ' + esc(comp.title) + '</div>';
                    html += '<div style="font-size:11px;color:var(--mw-text-tertiary);margin:2px 0;word-break:break-all;">' + esc(comp.url) + '</div>';
                    if (comp.headings && comp.headings.h2 && comp.headings.h2.length) {
                        html += '<ul style="margin:6px 0 0;padding-left:16px;font-size:12px;color:var(--mw-text-secondary);line-height:1.6;">';
                        comp.headings.h2.forEach(function(h) { html += '<li>' + esc(h) + '</li>'; });
                        html += '</ul>';
                    }
                    html += '</div>';
                });
                html += '</div></details>';
            }

            // 構成案反映メッセージ
            if (a.outline_json) {
                html += '<div class="wrt-cr-reflected-msg">✅ この調査結果は構成案に自動で反映されています</div>';
            }

            html += '<div style="margin-top:12px;"><button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtRerunCompetitorBtn">競合調査を再実行</button></div>';
        } else {
            html += '<p style="font-size:12px;color:var(--mw-text-tertiary);margin-bottom:8px;">競合上位記事を分析して構成案に反映します。構成案生成時に自動実行されます。</p>';
            html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtRunCompetitorBtn">競合調査を実行</button>';
        }
        html += '</div>';

        html += '</div>'; // .wrt-detail__main 閉じ

        // --- 右カラム: サイドバー ---
        html += '<div class="wrt-detail__sidebar">';

        // 記事設定
        html += '<div class="wrt-detail-section"><div class="wrt-detail-section__title">記事設定</div>';
        html += '<div class="wrt-settings-grid">';
        html += '<div><label><span class="wrt-help-trigger" data-help="type">記事タイプ <span class="wrt-help-icon">?</span></span></label><select id="wrtSetType">';
        Object.keys(typeLabels).forEach(function(k) { html += '<option value="' + k + '"' + (a.type === k ? ' selected' : '') + '>' + esc(typeLabels[k]) + '</option>'; });
        html += '</select></div>';
        html += '<div><label><span class="wrt-help-trigger" data-help="purpose">目的 <span class="wrt-help-icon">?</span></span></label><select id="wrtSetPurpose">';
        Object.keys(purposeLabels).forEach(function(k) { html += '<option value="' + k + '"' + (a.purpose === k ? ' selected' : '') + '>' + esc(purposeLabels[k]) + '</option>'; });
        html += '</select></div>';
        html += '<div><label>文体</label><select id="wrtSetTone">';
        Object.keys(toneLabels).forEach(function(k) { html += '<option value="' + k + '"' + (a.tone === k ? ' selected' : '') + '>' + esc(toneLabels[k]) + '</option>'; });
        html += '</select></div>';
        html += '<div><label>想定読者</label><input type="text" id="wrtSetReader" value="' + esc(a.target_reader) + '" placeholder="例: 松山市で歯医者を探している30代主婦"></div>';
        html += '</div>';
        // 情報ストック選択
        html += '<div style="margin-top:12px;"><label>参照する情報ストック（自動選択）</label><div class="wrt-kb-select" id="wrtKbSelect"></div></div>';
        html += '<div style="margin-top:12px;text-align:right;">';
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtSaveSettingsBtn">設定を保存</button>';
        html += '</div></div>';

        // 記事個別メモ
        html += '<div class="wrt-detail-section"><div class="wrt-detail-section__title">記事個別メモ</div>';
        html += '<div id="wrtNotesArea"></div>';
        html += '<div style="display:flex;gap:8px;margin-top:8px;"><textarea id="wrtNoteInput" rows="2" placeholder="この記事用のメモ・補足情報を追加" style="flex:1;padding:8px 10px;border:1px solid var(--mw-border-light);border-radius:6px;font-size:13px;resize:vertical;background:var(--mw-bg-primary);color:var(--mw-text-primary);"></textarea>';
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtAddNoteBtn" style="align-self:flex-end;">追加</button></div>';
        html += '</div>';

        // インタビュー（一次情報抽出）
        html += '<div class="wrt-detail-section" id="wrtInterviewSection">';
        html += '<div class="wrt-detail-section__title">インタビュー</div>';
        html += '<p style="font-size:12px;color:var(--mw-text-tertiary);margin-bottom:8px;">記事の独自性を高めるためのインタビューです。体験やエピソードをAIが質問し、回答に応じて深掘りします。</p>';
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtGenerateInterviewBtn">' + (a.interview ? 'インタビューを再生成' : 'インタビュー質問を生成') + '</button>';
        html += '<div id="wrtInterviewArea"></div>';
        html += '</div>';

        // 追加編集プロンプト
        html += '<div class="wrt-detail-section">';
        html += '<div class="wrt-detail-section__title">追加編集プロンプト</div>';
        html += '<p style="font-size:12px;color:var(--mw-text-tertiary);margin-bottom:8px;">本文に対する修正指示を入力して再生成できます。</p>';
        html += '<div style="display:flex;gap:6px;align-items:flex-start;">';
        html += '<textarea id="wrtRefinePrompt" rows="4" placeholder="例: 導入部分をもっと具体的にしてください" style="flex:1;padding:8px 10px;border:1px solid var(--mw-border-light);border-radius:6px;font-size:13px;resize:vertical;background:var(--mw-bg-primary);color:var(--mw-text-primary);box-sizing:border-box;"></textarea>';
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtRefineVoiceBtn" title="音声入力" style="padding:6px 10px;font-size:16px;line-height:1;flex-shrink:0;">🎤</button>';
        html += '</div>';
        html += '<div style="margin-top:8px;text-align:right;">';
        html += '<button class="wrt-btn wrt-btn--primary wrt-btn--sm" id="wrtRefineDraftBtn">この指示で再生成</button>';
        html += '</div></div>';

        html += '</div>'; // .wrt-detail__sidebar 閉じ
        html += '</div>'; // .wrt-detail__body 閉じ
        html += '</div>'; // .wrt-detail 閉じ
        view.innerHTML = html;

        // 戻るボタン
        document.getElementById('wrtBackBtn').addEventListener('click', function() {
            view.style.display = 'none';
            document.querySelectorAll('.wrt-tabs, .wrt-tab-panel').forEach(function(el) { el.style.display = ''; });
            loadArticles();
        });

        // 削除ボタン
        document.getElementById('wrtDeleteArticleBtn').addEventListener('click', function() {
            if (!confirm('この記事を削除しますか？')) return;
            apiFetch('/articles/' + a.id, { method: 'DELETE' }).then(function() {
                showToast('削除しました');
                view.style.display = 'none';
                document.querySelectorAll('.wrt-tabs, .wrt-tab-panel').forEach(function(el) { el.style.display = ''; });
                loadArticles();
            });
        });

        // 設定保存
        document.getElementById('wrtSaveSettingsBtn').addEventListener('click', function() {
            var selectedIds = [];
            document.querySelectorAll('.wrt-kb-select__item.selected').forEach(function(el) { selectedIds.push(parseInt(el.dataset.id)); });
            apiFetch('/articles/' + a.id + '/settings', { method: 'POST', body: {
                type: document.getElementById('wrtSetType').value,
                purpose: document.getElementById('wrtSetPurpose').value,
                tone: document.getElementById('wrtSetTone').value,
                target_reader: document.getElementById('wrtSetReader').value,
                selected_knowledge_ids: selectedIds
            }}).then(function(res) {
                if (res.success) { showToast('設定を保存しました'); currentArticle = res.article; }
                else showToast(res.error || 'エラー', true);
            });
        });

        // メモ追加
        document.getElementById('wrtAddNoteBtn').addEventListener('click', function() {
            var text = document.getElementById('wrtNoteInput').value.trim();
            if (!text) return;
            apiFetch('/articles/' + a.id + '/notes', { method: 'POST', body: { text: text } }).then(function(res) {
                if (res.success) { document.getElementById('wrtNoteInput').value = ''; currentArticle.notes = res.notes; renderNotes(res.notes, a.id); showToast('メモを追加しました'); }
                else showToast(res.error || 'エラー', true);
            });
        });
        renderNotes(a.notes || [], a.id);

        // 競合調査ボタン＋自動実行
        function runCompetitorResearch(force) {
            var section = document.getElementById('wrtCompetitorSection');
            if (section) {
                var prevErr = section.querySelector('.wrt-cr-error');
                if (prevErr) prevErr.remove();
            }
            showProgress('競合調査中…（上位記事をクロール・分析しています。2〜3分かかります）');
            apiFetch('/articles/' + a.id + '/competitor-research', {
                method: 'POST',
                body: { force: !!force }
            }).then(function(res) {
                hideProgress();
                if (!res.success) {
                    showToast(res.error || '競合調査でエラーが発生しました', true);
                    // エラー時はフラグを解除して再試行可能に
                    delete _competitorAutoRanFor[a.id];
                    if (section) {
                        var errDiv = document.createElement('div');
                        errDiv.className = 'wrt-cr-error';
                        errDiv.style.cssText = 'padding:10px 14px;background:#C95A4F10;border:1px solid #C95A4F30;border-radius:6px;margin-top:8px;font-size:12px;color:#C95A4F;';
                        errDiv.textContent = res.error || '競合調査でエラーが発生しました。「競合調査を実行」ボタンで再試行してください。';
                        section.appendChild(errDiv);
                    }
                    return;
                }
                var crRes = res.research || {};
                var crOk = (crRes.competitors || []).filter(function(c) { return c.status === 'ok'; }).length;
                showToast('競合調査が完了しました（' + crOk + '件の上位記事を分析）');
                _competitorAutoRanFor[a.id] = true;
                showArticleDetail(a.id);
            }).catch(function(err) {
                hideProgress();
                showToast('競合調査の通信エラー', true);
                delete _competitorAutoRanFor[a.id];
            });
        }
        var competitorBtn = document.getElementById('wrtRunCompetitorBtn') || document.getElementById('wrtRerunCompetitorBtn');
        if (competitorBtn) {
            competitorBtn.addEventListener('click', function() {
                runCompetitorResearch(this.id === 'wrtRerunCompetitorBtn');
            });
        }
        // 競合調査が一度も実行されていない場合のみ自動実行
        if (!a.competitor_research) {
            if (!_competitorAutoRanFor[a.id]) {
                _competitorAutoRanFor[a.id] = true;
                setTimeout(function() { runCompetitorResearch(false); }, 500);
            }
        }

        // インタビュー生成
        document.getElementById('wrtGenerateInterviewBtn').addEventListener('click', function() {
            showProgress('インタビュー質問を生成中…');
            apiFetch('/articles/' + a.id + '/interview', { method: 'POST' }).then(function(res) {
                hideProgress();
                if (res.success) { currentArticle.interview = res.interview; renderInterviewTimeline(res.interview, a.id); showToast('インタビュー質問を生成しました'); }
                else showToast(res.error || 'エラー', true);
            }).catch(function() { hideProgress(); showToast('通信エラー', true); });
        });

        // 本文生成
        document.getElementById('wrtGenerateDraftBtn').addEventListener('click', function() {
            showProgress('構成案と本文を生成中…（2〜3分程度）');
            var refinePrompt = (document.getElementById('wrtRefinePrompt') || {}).value || '';
            var body = {};
            if (refinePrompt.trim()) body.additional_prompt = refinePrompt.trim();
            apiFetch('/articles/' + a.id + '/draft', { method: 'POST', body: body }).then(function(res) {
                hideProgress();
                if (res.success) {
                    currentArticle.draft_content = res.draft_content;
                    if (res.outline) currentArticle.outline = res.outline;
                    currentArticle.status = 'draft_generated';
                    draftViewMode = 'preview';
                    showToast('本文を生成しました');
                    renderArticleDetail();
                    var rp = document.getElementById('wrtRefinePrompt');
                    if (rp && refinePrompt) rp.value = refinePrompt;
                }
                else showToast(res.error || 'エラー', true);
            }).catch(function() { hideProgress(); showToast('通信エラー', true); });
        });

        // 構成案: 本文未生成ならインライン表示、生成済みならモーダルボタン
        var outlineBtn = document.getElementById('wrtShowOutlineBtn');
        if (outlineBtn) {
            outlineBtn.addEventListener('click', function() {
                renderOutlineModal(a.outline);
                document.getElementById('wrtOutlineModal').classList.add('active');
            });
        }
        var inlineOutlineEl = document.getElementById('wrtOutlineInline');
        if (inlineOutlineEl && a.outline) {
            renderOutlineInline(inlineOutlineEl, a.outline);
        }

        // 追加編集プロンプト 音声入力
        document.getElementById('wrtRefineVoiceBtn').addEventListener('click', function() {
            openVoiceModal(document.getElementById('wrtRefinePrompt'));
        });

        // 追加編集プロンプトで再生成
        document.getElementById('wrtRefineDraftBtn').addEventListener('click', function() {
            var prompt = document.getElementById('wrtRefinePrompt').value.trim();
            if (!prompt) { showToast('編集指示を入力してください', true); return; }
            // 編集中の本文を取得（エディターから）
            var editor = document.getElementById('wrtDraftEditor');
            var content = editor ? editor.value : (currentArticle.draft_content || '');
            if (!content) { showToast('先に本文を生成してください', true); return; }
            showProgress('編集指示をもとに再生成中…');
            apiFetch('/articles/' + a.id + '/refine-draft', { method: 'POST', body: { current_content: content, prompt: prompt } }).then(function(res) {
                hideProgress();
                if (res.success) {
                    currentArticle.draft_content = res.draft_content;
                    renderDraft(res.draft_content);
                    showToast('本文を再生成しました');
                } else {
                    showToast(res.error || 'エラー', true);
                }
            }).catch(function() { hideProgress(); showToast('通信エラー', true); });
        });

        // 情報ストック選択肢をロード
        loadKnowledgeForSelect(a.selected_knowledge_ids || []);

        // 既存データ表示
        if (a.interview) renderInterviewTimeline(a.interview, a.id);
        if (a.draft_content) renderDraft(a.draft_content);
    }

    function renderNotes(notes, articleId) {
        var area = document.getElementById('wrtNotesArea');
        if (!area) return;
        if (!notes || notes.length === 0) { area.innerHTML = '<p style="font-size:12px;color:var(--mw-text-tertiary);">メモはまだありません</p>'; return; }
        area.innerHTML = notes.map(function(n) {
            return '<div style="display:flex;gap:8px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--mw-border-light);">'
                + '<div style="flex:1;font-size:13px;color:var(--mw-text-secondary);white-space:pre-wrap;">' + esc(n.text) + '</div>'
                + '<span style="font-size:11px;color:var(--mw-text-tertiary);white-space:nowrap;">' + esc(n.created_at || '') + '</span>'
                + '<button class="wrt-btn wrt-btn--danger wrt-btn--sm" onclick="deleteNote(\'' + articleId + '\',\'' + n.id + '\')" style="padding:2px 8px;font-size:11px;">×</button>'
                + '</div>';
        }).join('');
    }
    // グローバルに公開
    window.deleteNote = function(articleId, noteId) {
        apiFetch('/articles/' + articleId + '/notes', { method: 'DELETE', body: { note_id: noteId } }).then(function(res) {
            if (res.success) { currentArticle.notes = res.notes; renderNotes(res.notes, articleId); }
        });
    };

    /* ===== 音声入力モーダル (MediaRecorder + Whisper API) ===== */
    var voiceTranscribeUrl = <?php echo wp_json_encode( esc_url_raw( rest_url( 'mimamori/v1/voice-transcribe' ) ) ); ?>;
    var voiceTargetEl = null;
    var voiceMediaRecorder = null;
    var voiceMediaChunks = [];
    var voiceStream = null;
    var voiceAudioCtx = null;
    var voiceAnalyser = null;
    var voiceWaveAnimId = null;
    var voiceMaxTimer = null;
    var VOICE_MAX_DURATION = 300000;

    var voiceModal = document.getElementById('wrtVoiceModal');
    var voiceWaveCanvas = document.getElementById('wrtVoiceWave');
    var voiceWaveCtx = voiceWaveCanvas.getContext('2d');
    var voiceStatusEl = document.getElementById('wrtVoiceStatus');
    var voiceResultEl = document.getElementById('wrtVoiceResult');
    var voiceRecBtn = document.getElementById('wrtVoiceRecBtn');
    var voiceActions = document.getElementById('wrtVoiceActions');
    var voiceConfirmActions = document.getElementById('wrtVoiceConfirmActions');

    function openVoiceModal(targetEl) {
        voiceTargetEl = targetEl;
        voiceResultEl.value = '';
        voiceResultEl.classList.remove('visible');
        voiceStatusEl.textContent = 'ボタンを押して録音開始';
        voiceRecBtn.textContent = '🎤';
        voiceRecBtn.classList.remove('recording');
        voiceActions.style.display = '';
        voiceConfirmActions.style.display = 'none';
        clearVoiceWave();
        voiceModal.classList.add('active');
    }
    function closeVoiceModal() {
        stopVoiceRecording(true);
        voiceModal.classList.remove('active');
        voiceTargetEl = null;
    }

    function startVoiceRecording() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showToast('お使いのブラウザはマイクに対応していません', true); return;
        }
        voiceStatusEl.textContent = 'マイクにアクセス中…';
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
            voiceStream = stream;
            voiceMediaChunks = [];
            var mimeOptions = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', ''];
            var mimeType = '';
            for (var i = 0; i < mimeOptions.length; i++) {
                if (mimeOptions[i] === '' || MediaRecorder.isTypeSupported(mimeOptions[i])) { mimeType = mimeOptions[i]; break; }
            }
            var opts = mimeType ? { mimeType: mimeType } : {};
            voiceMediaRecorder = new MediaRecorder(stream, opts);
            voiceMediaRecorder.addEventListener('dataavailable', function(e) {
                if (e.data && e.data.size > 0) voiceMediaChunks.push(e.data);
            });
            voiceMediaRecorder.start(1000);

            voiceStatusEl.textContent = '録音中… 話してください';
            voiceRecBtn.textContent = '⏹';
            voiceRecBtn.classList.add('recording');

            // 波形アニメーション開始
            startVoiceWave(stream);

            // 最大録音時間
            voiceMaxTimer = setTimeout(function() { stopVoiceRecording(false); }, VOICE_MAX_DURATION);
        }).catch(function(err) {
            if (err.name === 'NotAllowedError') {
                voiceStatusEl.textContent = 'マイクの使用が許可されていません';
            } else {
                voiceStatusEl.textContent = 'マイクにアクセスできませんでした';
            }
        });
    }

    function stopVoiceRecording(isCancel) {
        if (voiceMaxTimer) { clearTimeout(voiceMaxTimer); voiceMaxTimer = null; }
        stopVoiceWave();

        if (!voiceMediaRecorder || voiceMediaRecorder.state !== 'recording') return;

        voiceMediaRecorder.addEventListener('stop', function() {
            if (isCancel) return;

            if (voiceMediaChunks.length === 0) {
                voiceStatusEl.textContent = '音声が検出されませんでした';
                return;
            }
            var blob = new Blob(voiceMediaChunks, { type: voiceMediaRecorder.mimeType || 'audio/webm' });
            sendVoiceToWhisper(blob);
        }, { once: true });

        try { voiceMediaRecorder.stop(); } catch(e) {}
        stopVoiceStream();
    }

    function stopVoiceStream() {
        if (voiceStream) {
            voiceStream.getTracks().forEach(function(t) { t.stop(); });
            voiceStream = null;
        }
    }

    function sendVoiceToWhisper(blob) {
        voiceStatusEl.textContent = '文字起こし中…';
        voiceRecBtn.textContent = '⏳';
        voiceRecBtn.classList.remove('recording');

        var ext = 'webm';
        var mime = blob.type || '';
        if (mime.indexOf('mp4') !== -1 || mime.indexOf('m4a') !== -1) ext = 'm4a';
        else if (mime.indexOf('ogg') !== -1) ext = 'ogg';

        var formData = new FormData();
        formData.append('audio', blob, 'recording.' + ext);

        fetch(voiceTranscribeUrl, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success && res.data && res.data.text) {
                voiceResultEl.value = res.data.text;
                voiceResultEl.classList.add('visible');
                voiceStatusEl.textContent = '認識結果を確認してください';
                voiceActions.style.display = 'none';
                voiceConfirmActions.style.display = '';
            } else {
                voiceStatusEl.textContent = res.message || '音声を認識できませんでした';
                voiceRecBtn.textContent = '🎤';
            }
        })
        .catch(function() {
            voiceStatusEl.textContent = '通信エラーが発生しました';
            voiceRecBtn.textContent = '🎤';
        });
    }

    // 波形描画
    function startVoiceWave(stream) {
        try {
            voiceAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
            var source = voiceAudioCtx.createMediaStreamSource(stream);
            voiceAnalyser = voiceAudioCtx.createAnalyser();
            voiceAnalyser.fftSize = 128;
            voiceAnalyser.smoothingTimeConstant = 0.7;
            source.connect(voiceAnalyser);
            drawVoiceWave();
        } catch(e) {}
    }
    function stopVoiceWave() {
        if (voiceWaveAnimId) { cancelAnimationFrame(voiceWaveAnimId); voiceWaveAnimId = null; }
        if (voiceAudioCtx && voiceAudioCtx.state !== 'closed') {
            voiceAudioCtx.close().catch(function(){}); voiceAudioCtx = null;
        }
        voiceAnalyser = null;
    }
    function clearVoiceWave() {
        voiceWaveCtx.clearRect(0, 0, voiceWaveCanvas.width, voiceWaveCanvas.height);
    }
    function drawVoiceWave() {
        voiceWaveAnimId = requestAnimationFrame(drawVoiceWave);
        if (!voiceAnalyser) return;
        var bufLen = voiceAnalyser.frequencyBinCount;
        var data = new Uint8Array(bufLen);
        voiceAnalyser.getByteFrequencyData(data);
        var w = voiceWaveCanvas.width, h = voiceWaveCanvas.height;
        voiceWaveCtx.clearRect(0, 0, w, h);
        var barCount = 40, totalW = w * 0.85, startX = (w - totalW) / 2;
        var barW = totalW / barCount * 0.6, gap = totalW / barCount * 0.4;
        voiceWaveCtx.fillStyle = '#4A90A4';
        for (var i = 0; i < barCount; i++) {
            var idx = Math.floor(i * bufLen / barCount);
            var amp = data[idx] / 255.0;
            var barH = Math.max(2, amp * h * 0.85);
            voiceWaveCtx.fillRect(startX + i * (barW + gap), (h - barH) / 2, barW, barH);
        }
    }

    // モーダルイベント
    voiceRecBtn.addEventListener('click', function() {
        if (voiceMediaRecorder && voiceMediaRecorder.state === 'recording') {
            stopVoiceRecording(false);
        } else {
            startVoiceRecording();
        }
    });
    document.getElementById('wrtVoiceOkBtn').addEventListener('click', function() {
        if (voiceTargetEl && voiceResultEl.value) {
            var existing = voiceTargetEl.value;
            var sep = existing && !existing.endsWith('\n') && !existing.endsWith(' ') ? ' ' : '';
            voiceTargetEl.value = existing + sep + voiceResultEl.value;
        }
        closeVoiceModal();
    });
    document.getElementById('wrtVoiceRetryBtn').addEventListener('click', function() {
        voiceResultEl.value = '';
        voiceResultEl.classList.remove('visible');
        voiceStatusEl.textContent = 'ボタンを押して録音開始';
        voiceRecBtn.textContent = '🎤';
        voiceRecBtn.classList.remove('recording');
        voiceActions.style.display = '';
        voiceConfirmActions.style.display = 'none';
        clearVoiceWave();
    });
    document.getElementById('wrtVoiceCancelBtn').addEventListener('click', closeVoiceModal);
    voiceModal.addEventListener('click', function(e) { if (e.target === this) closeVoiceModal(); });

    /* ===== インタビュータイムライン表示 ===== */
    var priorityLabels = { high: '高', medium: '中', low: '低' };
    var priorityColors = { high: '#ef4444', medium: '#f59e0b', low: '#9ca3af' };
    var followupPatternLabels = {
        abstract_to_concrete: '具体化',
        reason_digging: '理由の掘り下げ',
        emotion_extraction: '感情の抽出',
        comparison_clarification: '比較の明確化'
    };

    function renderInterviewTimeline(interview, articleId) {
        var area = document.getElementById('wrtInterviewArea');
        if (!area || !interview) { if (area) area.innerHTML = ''; return; }

        var rounds = interview.rounds || [];
        if (rounds.length === 0) { area.innerHTML = ''; return; }

        var html = '<div style="margin-top:12px;">';

        // 各ラウンドを描画
        rounds.forEach(function(round, roundIdx) {
            var roundLabel = round.type === 'initial' ? 'インタビュー' : '深掘り ' + round.round;
            var timeStr = round.generated_at ? ' (' + round.generated_at.substring(0, 16).replace('T', ' ') + ')' : '';

            // ラウンドヘッダー
            html += '<div style="display:flex;align-items:center;gap:8px;margin:' + (roundIdx > 0 ? '20px' : '0') + ' 0 10px 0;">';
            html += '<div style="width:8px;height:8px;border-radius:50%;background:' + (round.type === 'initial' ? '#3b82f6' : '#8b5cf6') + ';flex-shrink:0;"></div>';
            html += '<div style="font-size:12px;font-weight:700;color:var(--mw-text-heading);">' + esc(roundLabel) + '</div>';
            html += '<div style="font-size:11px;color:var(--mw-text-tertiary);">' + esc(timeStr) + '</div>';
            if (roundIdx > 0) {
                html += '<span style="font-size:10px;padding:1px 6px;border-radius:3px;background:#8b5cf620;color:#8b5cf6;">ラウンド ' + (roundIdx + 1) + '/3</span>';
            }
            html += '</div>';

            // 質問リスト
            (round.questions || []).forEach(function(q, qIdx) {
                var ans = q.answer || '';
                html += '<div style="margin-bottom:14px;padding:12px;background:var(--mw-bg-secondary);border-radius:8px;' + (round.type === 'followup' ? 'border-left:3px solid #8b5cf6;' : '') + '">';

                // フォローアップの場合は親質問参照を表示
                if (q.parent_id) {
                    var patternLabel = followupPatternLabels[q.followup_pattern] || '';
                    html += '<div style="font-size:10px;color:#8b5cf6;margin-bottom:4px;">↳ ' + esc(q.parent_id) + 'への深掘り' + (patternLabel ? '（' + esc(patternLabel) + '）' : '') + '</div>';
                }

                // 質問ヘッダー（優先度バッジ付き）
                html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">';
                if (q.priority && priorityColors[q.priority]) {
                    html += '<span style="font-size:10px;font-weight:700;color:#fff;background:' + priorityColors[q.priority] + ';padding:1px 6px;border-radius:3px;white-space:nowrap;">優先度: ' + esc(priorityLabels[q.priority] || q.priority) + '</span>';
                }
                html += '<div style="font-size:13px;font-weight:600;color:var(--mw-text-heading);">Q' + (qIdx+1) + '. ' + esc(q.question) + '</div>';
                html += '</div>';

                if (q.reason) {
                    html += '<div style="font-size:11px;color:var(--mw-text-secondary);margin-bottom:4px;">理由: ' + esc(q.reason) + '</div>';
                }
                if (q.target_headings && q.target_headings.length) {
                    html += '<div style="font-size:11px;color:var(--mw-text-tertiary);margin-bottom:4px;">反映先: ' + q.target_headings.map(function(h) { return esc(h); }).join(', ') + '</div>';
                }
                if (q.hint) {
                    html += '<div style="font-size:11px;color:var(--mw-text-tertiary);margin-bottom:6px;">回答例: ' + esc(q.hint) + '</div>';
                }

                html += '<div style="display:flex;gap:6px;align-items:flex-start;">';
                if (q.field_type === 'textarea' || round.type === 'followup') {
                    html += '<textarea class="wrt-interview-ans" data-round="' + round.round + '" data-idx="' + qIdx + '" rows="3" placeholder="回答を入力（音声入力も可能）" style="flex:1;padding:8px;border:1px solid var(--mw-border-light);border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;background:var(--mw-bg-primary);color:var(--mw-text-primary);">' + esc(ans) + '</textarea>';
                } else {
                    html += '<input type="text" class="wrt-interview-ans" data-round="' + round.round + '" data-idx="' + qIdx + '" value="' + esc(ans).replace(/"/g, '&quot;') + '" placeholder="回答を入力" style="flex:1;padding:8px;border:1px solid var(--mw-border-light);border-radius:6px;font-size:13px;box-sizing:border-box;background:var(--mw-bg-primary);color:var(--mw-text-primary);">';
                }
                html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm wrt-voice-btn" data-target-round="' + round.round + '" data-target-idx="' + qIdx + '" title="音声入力" style="padding:6px 10px;font-size:16px;line-height:1;">🎤</button>';
                html += '</div></div>';
            });

            // ラウンドごとの保存ボタン
            html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm wrt-save-round-btn" data-round="' + round.round + '" style="margin-top:4px;">回答を保存</button>';
        });

        // 深掘りボタン（最大2ラウンドまで）
        var lastRound = rounds[rounds.length - 1];
        var hasAnsweredInLast = (lastRound.questions || []).some(function(q) { return q.answer && q.answer !== ''; });
        if (rounds.length < 3 && hasAnsweredInLast) {
            html += '<div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--mw-border-light);">';
            html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtFollowupBtn" style="display:flex;align-items:center;gap:6px;">';
            html += '<span style="font-size:14px;">🔍</span> 深掘り質問を生成（回答をさらに掘り下げる）</button>';
            html += '</div>';
        }

        // 構造化ボタン
        var totalAnswered = 0;
        rounds.forEach(function(r) { (r.questions || []).forEach(function(q) { if (q.answer && q.answer !== '') totalAnswered++; }); });
        if (totalAnswered > 0) {
            html += '<div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--mw-border-light);display:flex;gap:8px;flex-wrap:wrap;">';
            html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtStructureBtn" style="display:flex;align-items:center;gap:6px;">';
            html += '<span style="font-size:14px;">📋</span> 回答を整理する</button>';
            html += '</div>';
        }

        // 構造化結果の表示
        var si = interview.structured_insights;
        if (si) {
            html += '<div style="margin-top:16px;padding:14px;background:var(--mw-bg-secondary);border-radius:8px;border:1px solid var(--mw-border-light);">';
            html += '<div style="font-size:13px;font-weight:700;color:var(--mw-text-heading);margin-bottom:10px;">整理された一次情報</div>';
            var cats = [
                { key: 'facts', label: '事実', icon: '📌' },
                { key: 'challenges', label: '課題', icon: '⚡' },
                { key: 'actions', label: '行動', icon: '🔧' },
                { key: 'results', label: '結果', icon: '✅' },
                { key: 'learnings', label: '学び', icon: '💡' }
            ];
            cats.forEach(function(cat) {
                var items = si[cat.key] || [];
                if (items.length > 0) {
                    html += '<div style="margin-bottom:8px;">';
                    html += '<div style="font-size:12px;font-weight:600;color:var(--mw-text-secondary);margin-bottom:3px;">' + cat.icon + ' ' + esc(cat.label) + '</div>';
                    items.forEach(function(item) {
                        html += '<div style="font-size:12px;color:var(--mw-text-primary);margin-left:12px;margin-bottom:2px;">- ' + esc(item) + '</div>';
                    });
                    html += '</div>';
                }
            });
            // エピソード
            var exps = si.key_experiences || [];
            if (exps.length > 0) {
                html += '<div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--mw-border-light);">';
                html += '<div style="font-size:12px;font-weight:600;color:var(--mw-text-secondary);margin-bottom:6px;">🎯 記事に使えるエピソード</div>';
                exps.forEach(function(exp) {
                    html += '<div style="margin-bottom:8px;padding:8px;background:var(--mw-bg-primary);border-radius:6px;">';
                    html += '<div style="font-size:12px;font-weight:600;color:var(--mw-text-heading);">' + esc(exp.summary) + '</div>';
                    if (exp.target_headings && exp.target_headings.length) {
                        html += '<div style="font-size:10px;color:var(--mw-text-tertiary);">反映先: ' + exp.target_headings.map(function(h) { return esc(h); }).join(', ') + '</div>';
                    }
                    html += '<div style="font-size:12px;color:var(--mw-text-secondary);margin-top:3px;">' + esc(exp.detail) + '</div>';
                    html += '</div>';
                });
                html += '</div>';
            }
            html += '</div>';
        }

        html += '</div>';
        area.innerHTML = html;

        // イベントリスナー: 音声入力ボタン
        area.querySelectorAll('.wrt-voice-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var round = btn.dataset.targetRound;
                var idx = btn.dataset.targetIdx;
                var target = area.querySelector('.wrt-interview-ans[data-round="' + round + '"][data-idx="' + idx + '"]');
                if (target) openVoiceModal(target);
            });
        });

        // イベントリスナー: ラウンドごとの保存
        area.querySelectorAll('.wrt-save-round-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var roundNum = parseInt(btn.dataset.round);
                var answersObj = {};
                area.querySelectorAll('.wrt-interview-ans[data-round="' + roundNum + '"]').forEach(function(el) {
                    answersObj[el.dataset.idx] = el.value;
                });
                apiFetch('/articles/' + articleId + '/interview', { method: 'PUT', body: { answers: answersObj, round: roundNum } }).then(function(res) {
                    if (res.success) { currentArticle.interview = res.interview; showToast('回答を保存しました'); renderInterviewTimeline(res.interview, articleId); }
                    else showToast(res.error || 'エラー', true);
                });
            });
        });

        // イベントリスナー: 深掘り生成
        var followupBtn = document.getElementById('wrtFollowupBtn');
        if (followupBtn) {
            followupBtn.addEventListener('click', function() {
                showProgress('深掘り質問を生成中…');
                apiFetch('/articles/' + articleId + '/interview/followup', { method: 'POST' }).then(function(res) {
                    hideProgress();
                    if (res.success) { currentArticle.interview = res.interview; renderInterviewTimeline(res.interview, articleId); showToast('深掘り質問を生成しました'); }
                    else showToast(res.error || 'エラー', true);
                }).catch(function() { hideProgress(); showToast('通信エラー', true); });
            });
        }

        // イベントリスナー: 構造化
        var structureBtn = document.getElementById('wrtStructureBtn');
        if (structureBtn) {
            structureBtn.addEventListener('click', function() {
                showProgress('回答を整理中…');
                apiFetch('/articles/' + articleId + '/interview/structure', { method: 'POST' }).then(function(res) {
                    hideProgress();
                    if (res.success) { currentArticle.interview = res.interview; renderInterviewTimeline(res.interview, articleId); showToast('回答を整理しました'); }
                    else showToast(res.error || 'エラー', true);
                }).catch(function() { hideProgress(); showToast('通信エラー', true); });
            });
        }
    }

    /* ===== 構成案モーダル ===== */
    /**
     * 構成案をインライン表示（本文未生成時にセクション内に直接表示）
     */
    function renderOutlineInline(container, outline) {
        if (!outline) { container.innerHTML = ''; return; }
        var html = '<div class="wrt-outline-inline" style="background:var(--mw-bg-secondary);border-radius:8px;padding:20px;border:1px solid var(--mw-border-light);">';

        // タイトル候補
        if (outline.title_options && outline.title_options.length > 0) {
            var currentTitle = (currentArticle && currentArticle.title) ? currentArticle.title : '';
            html += '<div style="margin-bottom:16px;">';
            html += '<div style="font-size:13px;font-weight:600;color:var(--mw-text-secondary);margin-bottom:8px;">タイトル候補 <span style="font-weight:400;font-size:11px;color:var(--mw-text-tertiary);">— クリックで選択、ダブルクリックで編集</span></div>';
            outline.title_options.forEach(function(t, idx) {
                var isSelected = (t === currentTitle) || (idx === 0 && !currentTitle);
                html += '<div class="wrt-outline__title-opt' + (isSelected ? ' selected' : '') + '" data-title-idx="' + idx + '">'
                    + '<span class="wrt-title-radio"></span>'
                    + '<span class="wrt-title-text">' + esc(t) + '</span>'
                    + '</div>';
            });
            html += '<div class="wrt-title-actions">'
                + '<button class="wrt-btn wrt-btn--primary wrt-btn--sm" id="wrtInlineTitleConfirmBtn">このタイトルに決定</button>'
                + '</div>';
            html += '</div>';
        }

        // 検索意図
        if (outline.search_intent) {
            html += '<div class="wrt-outline__intent"><strong>検索意図:</strong> ' + esc(outline.search_intent) + '</div>';
        }

        // 記事の到達ゴール
        if (outline.article_goal) {
            html += '<div class="wrt-outline__intent" style="margin-top:8px;"><strong>記事の到達ゴール:</strong> ' + esc(outline.article_goal) + '</div>';
        }

        // 見出し構成
        if (outline.headings && outline.headings.length > 0) {
            html += '<div style="font-size:13px;font-weight:600;color:var(--mw-text-secondary);margin:16px 0 8px;">見出し構成</div>';
            outline.headings.forEach(function(h) {
                var level = h.level || 'h2';
                html += '<div class="wrt-outline__heading wrt-outline__heading--' + level + '">';
                html += '<div class="wrt-outline__heading-text">' + esc(level.toUpperCase()) + ': ' + esc(h.text) + '</div>';
                if (h.description) html += '<div class="wrt-outline__heading-desc">' + esc(h.description) + '</div>';
                if (h.reference) html += '<div class="wrt-outline__heading-ref">' + esc(h.reference) + '</div>';
                html += '</div>';
            });
        }

        // 不足情報
        if (outline.missing_info && outline.missing_info.length > 0) {
            html += '<div class="wrt-outline__missing">';
            html += '<div class="wrt-outline__missing-title">不足している情報</div>';
            outline.missing_info.forEach(function(m) {
                html += '<div class="wrt-outline__missing-item">' + esc(m) + '</div>';
            });
            html += '</div>';
        }

        // 執筆のポイント
        if (outline.tips && outline.tips.length > 0) {
            html += '<div class="wrt-outline__tips">';
            html += '<div class="wrt-outline__tips-title">執筆のポイント</div>';
            outline.tips.forEach(function(t) {
                html += '<div class="wrt-outline__tips-item">' + esc(t) + '</div>';
            });
            html += '</div>';
        }

        html += '</div>';
        container.innerHTML = html;

        // タイトル選択イベント
        container.querySelectorAll('.wrt-outline__title-opt').forEach(function(opt) {
            opt.addEventListener('click', function(e) {
                if (e.target.classList.contains('wrt-title-edit-input')) return;
                container.querySelectorAll('.wrt-outline__title-opt').forEach(function(o) { o.classList.remove('selected'); });
                opt.classList.add('selected');
            });
            opt.addEventListener('dblclick', function() {
                var textEl = opt.querySelector('.wrt-title-text');
                if (!textEl || opt.querySelector('.wrt-title-edit-input')) return;
                var currentVal = textEl.textContent;
                textEl.style.display = 'none';
                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'wrt-title-edit-input';
                input.value = currentVal;
                opt.appendChild(input);
                input.focus();
                input.select();
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') { textEl.textContent = input.value; textEl.style.display = ''; input.remove(); container.querySelectorAll('.wrt-outline__title-opt').forEach(function(o) { o.classList.remove('selected'); }); opt.classList.add('selected'); }
                    else if (e.key === 'Escape') { textEl.style.display = ''; input.remove(); }
                });
                input.addEventListener('blur', function() { if (input.parentNode) { textEl.textContent = input.value; textEl.style.display = ''; input.remove(); } });
            });
        });

        // 「このタイトルに決定」ボタン
        var confirmBtn = document.getElementById('wrtInlineTitleConfirmBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                var selected = container.querySelector('.wrt-outline__title-opt.selected .wrt-title-text');
                if (!selected) { showToast('タイトルを選択してください', true); return; }
                var newTitle = selected.textContent.trim();
                if (!newTitle) return;
                apiFetch('/articles/' + currentArticle.id + '/settings', {
                    method: 'POST',
                    body: { title: newTitle }
                }).then(function(res) {
                    if (res.success) {
                        showToast('タイトルを「' + newTitle + '」に変更しました');
                        currentArticle.title = newTitle;
                        loadArticles();
                    } else { showToast(res.error || 'エラー', true); }
                });
            });
        }
    }

    function renderOutlineModal(outline) {
        var body = document.getElementById('wrtOutlineModalBody');
        if (!outline) { body.innerHTML = '<p style="color:var(--mw-text-tertiary);">構成案がありません</p>'; return; }
        var html = '';

        // 現在のタイトル（post_title の先頭 = title_options[0]）
        var currentTitle = (currentArticle && currentArticle.title) ? currentArticle.title : '';

        // タイトル候補（選択・編集可能）
        if (outline.title_options && outline.title_options.length > 0) {
            html += '<div class="wrt-outline__titles">';
            html += '<div style="font-size:13px;font-weight:600;color:var(--mw-text-secondary);margin-bottom:8px;">タイトル候補 <span style="font-weight:400;font-size:11px;color:var(--mw-text-tertiary);">— クリックで選択、ダブルクリックで編集</span></div>';
            outline.title_options.forEach(function(t, idx) {
                var isSelected = (t === currentTitle) || (idx === 0 && !currentTitle);
                html += '<div class="wrt-outline__title-opt' + (isSelected ? ' selected' : '') + '" data-title-idx="' + idx + '">'
                    + '<span class="wrt-title-radio"></span>'
                    + '<span class="wrt-title-text">' + esc(t) + '</span>'
                    + '</div>';
            });
            html += '<div class="wrt-title-actions">'
                + '<button class="wrt-btn wrt-btn--primary wrt-btn--sm" id="wrtTitleConfirmBtn">このタイトルに決定</button>'
                + '</div>';
            html += '</div>';
        }

        // 検索意図
        if (outline.search_intent) {
            html += '<div class="wrt-outline__intent"><strong>検索意図:</strong> ' + esc(outline.search_intent) + '</div>';
        }

        // 記事の到達ゴール
        if (outline.article_goal) {
            html += '<div class="wrt-outline__intent" style="margin-top:8px;"><strong>記事の到達ゴール:</strong> ' + esc(outline.article_goal) + '</div>';
        }

        // 見出し構成
        if (outline.headings && outline.headings.length > 0) {
            html += '<div style="font-size:13px;font-weight:600;color:var(--mw-text-secondary);margin-bottom:8px;">見出し構成</div>';
            outline.headings.forEach(function(h) {
                var level = h.level || 'h2';
                html += '<div class="wrt-outline__heading wrt-outline__heading--' + level + '">';
                html += '<div class="wrt-outline__heading-text">' + esc(level.toUpperCase()) + ': ' + esc(h.text) + '</div>';
                if (h.description) html += '<div class="wrt-outline__heading-desc">' + esc(h.description) + '</div>';
                if (h.reference) html += '<div class="wrt-outline__heading-ref">' + esc(h.reference) + '</div>';
                html += '</div>';
            });
        }

        // 不足情報
        if (outline.missing_info && outline.missing_info.length > 0) {
            html += '<div class="wrt-outline__missing">';
            html += '<div class="wrt-outline__missing-title">不足している情報</div>';
            outline.missing_info.forEach(function(m) {
                html += '<div class="wrt-outline__missing-item">' + esc(m) + '</div>';
            });
            html += '</div>';
        }

        // 執筆のポイント
        if (outline.tips && outline.tips.length > 0) {
            html += '<div class="wrt-outline__tips">';
            html += '<div class="wrt-outline__tips-title">執筆のポイント</div>';
            outline.tips.forEach(function(t) {
                html += '<div class="wrt-outline__tips-item">' + esc(t) + '</div>';
            });
            html += '</div>';
        }

        body.innerHTML = html;

        // タイトル選択イベント
        body.querySelectorAll('.wrt-outline__title-opt').forEach(function(opt) {
            // シングルクリック: 選択
            opt.addEventListener('click', function(e) {
                if (e.target.classList.contains('wrt-title-edit-input')) return;
                body.querySelectorAll('.wrt-outline__title-opt').forEach(function(o) { o.classList.remove('selected'); });
                opt.classList.add('selected');
            });
            // ダブルクリック: 編集モード
            opt.addEventListener('dblclick', function() {
                var textEl = opt.querySelector('.wrt-title-text');
                if (!textEl || opt.querySelector('.wrt-title-edit-input')) return;
                var currentVal = textEl.textContent;
                textEl.style.display = 'none';
                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'wrt-title-edit-input';
                input.value = currentVal;
                opt.appendChild(input);
                input.focus();
                input.select();
                // Enter で確定、Escape でキャンセル
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        textEl.textContent = input.value;
                        textEl.style.display = '';
                        input.remove();
                        body.querySelectorAll('.wrt-outline__title-opt').forEach(function(o) { o.classList.remove('selected'); });
                        opt.classList.add('selected');
                    } else if (e.key === 'Escape') {
                        textEl.style.display = '';
                        input.remove();
                    }
                });
                input.addEventListener('blur', function() {
                    if (input.parentNode) {
                        textEl.textContent = input.value;
                        textEl.style.display = '';
                        input.remove();
                    }
                });
            });
        });

        // 「このタイトルに決定」ボタン
        var confirmBtn = document.getElementById('wrtTitleConfirmBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                var selected = body.querySelector('.wrt-outline__title-opt.selected .wrt-title-text');
                if (!selected) { showToast('タイトルを選択してください', true); return; }
                var newTitle = selected.textContent.trim();
                if (!newTitle) return;
                apiFetch('/articles/' + currentArticle.id + '/settings', {
                    method: 'POST',
                    body: { title: newTitle }
                }).then(function(res) {
                    if (res.success) {
                        showToast('タイトルを「' + newTitle + '」に変更しました');
                        currentArticle.title = newTitle;
                        // 記事一覧も更新
                        loadArticles();
                    } else {
                        showToast(res.error || 'エラー', true);
                    }
                });
            });
        }
    }
    function closeOutlineModal() { document.getElementById('wrtOutlineModal').classList.remove('active'); }
    document.getElementById('wrtOutlineModalClose').addEventListener('click', closeOutlineModal);
    document.getElementById('wrtOutlineModalCloseBtn').addEventListener('click', closeOutlineModal);
    document.getElementById('wrtOutlineModal').addEventListener('click', function(e) { if (e.target === this) closeOutlineModal(); });

    var draftViewMode = 'preview'; // 'preview' or 'edit'

    function renderDraft(content) {
        var area = document.getElementById('wrtDraftArea');
        if (!area || !content) return;

        // 文字数算出
        var titleMatch = content.match(/^# (.+)$/m);
        var titleText = titleMatch ? titleMatch[1] : '';
        var titleLen = titleText.length;
        var bodyText = content.replace(/^#{1,4} .+$/gm, '').replace(/\*\*(.+?)\*\*/g, '$1').replace(/^[-*] /gm, '').replace(/\n+/g, '').trim();
        var bodyLen = bodyText.length;

        var html = '<div class="wrt-draft-container">';

        // ツールバー
        html += '<div class="wrt-draft-toolbar">';
        html += '<div class="wrt-draft-toolbar__left">';
        html += '<button class="wrt-draft-tab' + (draftViewMode === 'preview' ? ' active' : '') + '" id="wrtTabPreview">プレビュー</button>';
        html += '<button class="wrt-draft-tab' + (draftViewMode === 'edit' ? ' active' : '') + '" id="wrtTabEdit">編集</button>';
        html += '</div>';
        html += '<div class="wrt-draft-toolbar__right">';
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtCopyHtml" title="HTML形式でコピー">HTMLをコピー</button>';
        html += '<button class="wrt-btn wrt-btn--primary wrt-btn--sm" id="wrtCopyText" title="プレーンテキストでコピー">本文をコピー</button>';
        html += '</div></div>';

        if (draftViewMode === 'edit') {
            // 編集モード: テキストエリア
            html += '<textarea id="wrtDraftEditor" class="wrt-draft-editor">' + esc(content) + '</textarea>';
        } else {
            // プレビューモード
            html += '<div class="wrt-draft-preview" id="wrtDraftRendered">';
            var rendered = esc(content)
                .replace(/^# (.+)$/gm, '<h1 class="wrt-draft-h1">$1</h1>')
                .replace(/^#### (.+)$/gm, '<h4 class="wrt-draft-h4">$1</h4>')
                .replace(/^### (.+)$/gm, '<h3 class="wrt-draft-h3">$1</h3>')
                .replace(/^## (.+)$/gm, '<h2 class="wrt-draft-h2">$1</h2>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/^[-*] (.+)$/gm, '<li>$1</li>')
                .replace(/\n\n/g, '</p><p class="wrt-draft-p">')
                .replace(/\n/g, '<br>');
            html += '<p class="wrt-draft-p">' + rendered + '</p>';
            html += '</div>';
        }

        // 文字数表示
        html += '<div class="wrt-draft-stats">';
        html += '<span>' + titleLen + '文字/タイトル</span>';
        html += '<span>' + bodyLen.toLocaleString() + '文字/本文</span>';
        html += '</div>';

        html += '</div>';
        area.innerHTML = html;

        // タブ切り替え
        document.getElementById('wrtTabPreview').addEventListener('click', function() {
            // 編集中の内容を保持
            var editor = document.getElementById('wrtDraftEditor');
            if (editor) currentArticle.draft_content = editor.value;
            draftViewMode = 'preview';
            renderDraft(currentArticle.draft_content);
        });
        document.getElementById('wrtTabEdit').addEventListener('click', function() {
            draftViewMode = 'edit';
            renderDraft(currentArticle.draft_content);
        });

        // コピーイベント
        document.getElementById('wrtCopyHtml').addEventListener('click', function() {
            var src = getCurrentDraftContent();
            var htmlContent = src
                .replace(/^# (.+)$/gm, '<h1>$1</h1>')
                .replace(/^#### (.+)$/gm, '<h4>$1</h4>')
                .replace(/^### (.+)$/gm, '<h3>$1</h3>')
                .replace(/^## (.+)$/gm, '<h2>$1</h2>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/^[-*] (.+)$/gm, '<li>$1</li>')
                .replace(/\n\n/g, '\n</p>\n<p>\n')
                .replace(/^(?!<[hluop])/gm, function(m) { return m ? '<p>' + m : m; });
            navigator.clipboard.writeText(htmlContent).then(function() { showToast('HTMLをコピーしました'); });
        });
        document.getElementById('wrtCopyText').addEventListener('click', function() {
            var src = getCurrentDraftContent();
            var text = src
                .replace(/^#{1,4} /gm, '')
                .replace(/\*\*(.+?)\*\*/g, '$1')
                .replace(/^[-*] /gm, '・');
            navigator.clipboard.writeText(text).then(function() { showToast('本文をコピーしました'); });
        });
    }

    function getCurrentDraftContent() {
        var editor = document.getElementById('wrtDraftEditor');
        return editor ? editor.value : (currentArticle.draft_content || '');
    }

    function loadKnowledgeForSelect(selectedIds) {
        apiFetch('/knowledge').then(function(res) {
            var items = res.items || [];
            var container = document.getElementById('wrtKbSelect');
            if (!container) return;
            if (items.length === 0) { container.innerHTML = '<span style="font-size:12px;color:var(--mw-text-tertiary);">情報ストックがありません（登録すると自動的に記事生成に活用されます）</span>'; return; }
            // selectedIds が空 = 全件自動選択（デフォルト）
            var autoSelectAll = !selectedIds || selectedIds.length === 0;
            container.innerHTML = '<div style="font-size:11px;color:var(--mw-text-tertiary);margin-bottom:6px;">すべての情報ストックが自動的に参照されます。除外したい場合はクリックして外してください。</div>'
                + items.map(function(ki) {
                    var sel = autoSelectAll || selectedIds.indexOf(ki.id) >= 0 ? ' selected' : '';
                    var fileIcon = ki.files && ki.files.length > 0 ? ' 📎' : '';
                    return '<div class="wrt-kb-select__item' + sel + '" data-id="' + ki.id + '">'
                        + '<span class="wrt-cat-badge">' + esc(catLabels[ki.category] || ki.category) + '</span> '
                        + esc(ki.title) + fileIcon + '</div>';
                }).join('');
            container.querySelectorAll('.wrt-kb-select__item').forEach(function(el) {
                el.addEventListener('click', function() { el.classList.toggle('selected'); });
            });
        });
    }

    /* ===== 情報ストック CRUD ===== */
    var knowledgeCategoryFilter = ''; // 空 = すべて

    function loadKnowledge() {
        apiFetch('/knowledge').then(function(res) {
            knowledgeData = res.items || [];
            renderKnowledgeFilters();
            renderKnowledge();
        });
    }

    function renderKnowledgeFilters() {
        var filtersEl = document.getElementById('wrtKbFilters');
        // 種別ごとの件数を集計
        var counts = {};
        knowledgeData.forEach(function(ki) {
            counts[ki.category] = (counts[ki.category] || 0) + 1;
        });
        var total = knowledgeData.length;

        var html = '<button class="wrt-kb-filter-btn' + (knowledgeCategoryFilter === '' ? ' active' : '') + '" data-cat="">すべて<span class="wrt-kb-filter-btn__count">（' + total + '）</span></button>';
        Object.keys(catLabels).forEach(function(key) {
            var count = counts[key] || 0;
            if (count === 0) return; // 0件の種別は非表示
            html += '<button class="wrt-kb-filter-btn' + (knowledgeCategoryFilter === key ? ' active' : '') + '" data-cat="' + key + '">'
                + esc(catLabels[key]) + '<span class="wrt-kb-filter-btn__count">（' + count + '）</span></button>';
        });
        filtersEl.innerHTML = html;
        filtersEl.querySelectorAll('.wrt-kb-filter-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                knowledgeCategoryFilter = btn.dataset.cat;
                renderKnowledgeFilters();
                renderKnowledge();
            });
        });
    }

    function renderKnowledge() {
        var container = document.getElementById('wrtKnowledgeList');
        var empty = document.getElementById('wrtKnowledgeEmpty');

        var filtered = knowledgeCategoryFilter
            ? knowledgeData.filter(function(ki) { return ki.category === knowledgeCategoryFilter; })
            : knowledgeData;

        if (filtered.length === 0) { container.innerHTML = ''; empty.style.display = ''; return; }
        empty.style.display = 'none';
        container.innerHTML = filtered.map(function(ki) {
            return '<div class="wrt-card wrt-knowledge-card" style="cursor:default;">'
                + '<div class="wrt-knowledge-card__body">'
                + '<div class="wrt-card__title"><span class="wrt-cat-badge">' + esc(catLabels[ki.category] || ki.category) + '</span> ' + esc(ki.title) + '</div>'
                + '<div class="wrt-knowledge-card__excerpt">' + esc((ki.content || '').substring(0, 100)) + '</div>'
                + '<div class="wrt-card__meta"><span>優先度: ' + ki.priority + '</span>'
                + (ki.files && ki.files.length > 0 ? '<span>📎 ' + ki.files.length + '件</span>' : '')
                + '<span>' + esc(ki.updated_at) + '</span></div>'
                + '</div>'
                + '<div class="wrt-knowledge-card__actions">'
                + '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" data-edit="' + ki.id + '">編集</button>'
                + '<button class="wrt-btn wrt-btn--danger wrt-btn--sm" data-delete="' + ki.id + '">削除</button>'
                + '</div></div>';
        }).join('');
        container.querySelectorAll('[data-edit]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var item = knowledgeData.find(function(k) { return k.id === parseInt(btn.dataset.edit); });
                if (item) openKnowledgeModal(item);
            });
        });
        container.querySelectorAll('[data-delete]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!confirm('この情報を削除しますか？')) return;
                apiFetch('/knowledge/' + btn.dataset.delete, { method: 'DELETE' }).then(function(res) {
                    if (res.success) { showToast('削除しました'); loadKnowledge(); }
                    else showToast(res.error || 'エラー', true);
                });
            });
        });
    }
    var currentKnowledgeItem = null;
    function openKnowledgeModal(item) {
        currentKnowledgeItem = item;
        document.getElementById('wrtKnowledgeId').value = item ? item.id : 0;
        document.getElementById('wrtKnowledgeModalTitle').textContent = item ? '情報を編集' : '情報を追加';
        document.getElementById('wrtKnowledgeTitleInput').value = item ? item.title : '';
        document.getElementById('wrtKnowledgeCategorySelect').value = item ? item.category : 'notes';
        document.getElementById('wrtKnowledgeContent').value = item ? item.content : '';
        document.getElementById('wrtKnowledgePriority').value = item ? item.priority : 3;
        // ファイルセクション: 常に表示（未保存でもアップロード可能 — 自動保存される）
        if (item) {
            renderKnowledgeFiles(item.files || []);
        } else {
            document.getElementById('wrtKnowledgeFileList').innerHTML = '<span style="font-size:12px;color:var(--mw-text-tertiary);">添付ファイルなし</span>';
        }
        document.getElementById('wrtDropzone').classList.remove('disabled');
        document.getElementById('wrtKnowledgeFileInput').disabled = false;
        document.getElementById('wrtKnowledgeFileInput').value = '';
        document.getElementById('wrtKnowledgeModal').classList.add('active');
        setTimeout(function() { document.getElementById('wrtKnowledgeTitleInput').focus(); }, 100);
    }
    function renderKnowledgeFiles(files) {
        var container = document.getElementById('wrtKnowledgeFileList');
        if (!files || files.length === 0) { container.innerHTML = '<span style="font-size:12px;color:var(--mw-text-tertiary);">添付ファイルなし</span>'; return; }
        container.innerHTML = files.map(function(f) {
            var icon = f.mime && f.mime.indexOf('image') === 0 ? '🖼️' : (f.mime === 'application/pdf' ? '📄' : '📎');
            var size = f.size > 1048576 ? (f.size / 1048576).toFixed(1) + 'MB' : (f.size / 1024).toFixed(0) + 'KB';
            return '<div class="wrt-file-item">'
                + '<span class="wrt-file-item__icon">' + icon + '</span>'
                + '<a href="' + esc(f.url) + '" target="_blank" class="wrt-file-item__name" title="' + esc(f.name) + '">' + esc(f.name) + '</a>'
                + '<span class="wrt-file-item__size">' + size + '</span>'
                + '<button class="wrt-file-item__del" data-file-id="' + f.id + '" title="削除">×</button>'
                + '</div>';
        }).join('');
        container.querySelectorAll('.wrt-file-item__del').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('このファイルを削除しますか？')) return;
                var kid = parseInt(document.getElementById('wrtKnowledgeId').value);
                var fid = parseInt(btn.dataset.fileId);
                apiFetch('/knowledge/' + kid + '/file', { method: 'DELETE', body: { attachment_id: fid } }).then(function(res) {
                    if (res.success) { showToast('ファイルを削除しました'); loadKnowledge(); var item = knowledgeData.find(function(k) { return k.id === kid; }); if (item) renderKnowledgeFiles(item.files || []); }
                    else showToast(res.error || 'エラー', true);
                });
            });
        });
    }
    function closeKnowledgeModal() { document.getElementById('wrtKnowledgeModal').classList.remove('active'); }

    document.getElementById('wrtNewKnowledgeBtn').addEventListener('click', function() { openKnowledgeModal(null); });
    document.getElementById('wrtKnowledgeModalClose').addEventListener('click', closeKnowledgeModal);
    document.getElementById('wrtKnowledgeModalCancel').addEventListener('click', closeKnowledgeModal);
    document.getElementById('wrtKnowledgeModal').addEventListener('click', function(e) { if (e.target === this) closeKnowledgeModal(); });
    document.getElementById('wrtKnowledgeSaveBtn').addEventListener('click', function() {
        var body = {
            id: parseInt(document.getElementById('wrtKnowledgeId').value),
            title: document.getElementById('wrtKnowledgeTitleInput').value,
            category: document.getElementById('wrtKnowledgeCategorySelect').value,
            content: document.getElementById('wrtKnowledgeContent').value,
            priority: parseInt(document.getElementById('wrtKnowledgePriority').value)
        };
        apiFetch('/knowledge', { method: 'POST', body: body }).then(function(res) {
            if (res.success) {
                showToast('保存しました');
                if (!body.id && res.item) {
                    currentKnowledgeItem = res.item;
                    document.getElementById('wrtKnowledgeId').value = res.item.id;
                    document.getElementById('wrtKnowledgeModalTitle').textContent = '情報を編集';
                    renderKnowledgeFiles([]);
                } else {
                    closeKnowledgeModal();
                }
                loadKnowledge();
            }
            else showToast(res.error || 'エラー', true);
        }).catch(function() { showToast('通信エラー', true); });
    });

    /* ===== ファイルアップロード（ドラッグ&ドロップ + クリック選択） ===== */
    function uploadKnowledgeFile(file) {
        var kid = parseInt(document.getElementById('wrtKnowledgeId').value);

        // 未保存の場合は自動保存してからアップロード
        if (kid < 1) {
            var title = document.getElementById('wrtKnowledgeTitleInput').value || '無題';
            var body = {
                id: 0,
                title: title,
                category: document.getElementById('wrtKnowledgeCategorySelect').value,
                content: document.getElementById('wrtKnowledgeContent').value,
                priority: parseInt(document.getElementById('wrtKnowledgePriority').value)
            };
            showProgress('情報を保存中…');
            apiFetch('/knowledge', { method: 'POST', body: body }).then(function(res) {
                if (res.success && res.item) {
                    currentKnowledgeItem = res.item;
                    document.getElementById('wrtKnowledgeId').value = res.item.id;
                    document.getElementById('wrtKnowledgeModalTitle').textContent = '情報を編集';
                    loadKnowledge();
                    doUploadKnowledgeFile(file, res.item.id);
                } else {
                    hideProgress();
                    showToast(res.error || '自動保存に失敗しました', true);
                }
            }).catch(function() { hideProgress(); showToast('通信エラー', true); });
            return;
        }

        doUploadKnowledgeFile(file, kid);
    }

    function doUploadKnowledgeFile(file, kid) {
        var formData = new FormData();
        formData.append('file', file);

        showProgress('ファイルをアップロード中…');
        fetch(baseUrl + '/knowledge/' + kid + '/file', {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            hideProgress();
            if (res.success) {
                showToast('ファイルをアップロードしました');
                document.getElementById('wrtKnowledgeFileInput').value = '';
                loadKnowledge();
                apiFetch('/knowledge').then(function(r2) {
                    knowledgeData = r2.items || [];
                    var item = knowledgeData.find(function(k) { return k.id === kid; });
                    if (item) renderKnowledgeFiles(item.files || []);
                });
            } else {
                showToast(res.error || 'アップロード失敗', true);
            }
        })
        .catch(function() { hideProgress(); showToast('通信エラー', true); });
    }

    // ファイル選択（クリック）
    document.getElementById('wrtKnowledgeFileInput').addEventListener('change', function() {
        if (this.files[0]) uploadKnowledgeFile(this.files[0]);
    });

    // ドラッグ&ドロップ
    var dropzone = document.getElementById('wrtDropzone');
    dropzone.addEventListener('dragover', function(e) { e.preventDefault(); e.stopPropagation(); this.classList.add('drag-over'); });
    dropzone.addEventListener('dragleave', function(e) { e.preventDefault(); e.stopPropagation(); this.classList.remove('drag-over'); });
    dropzone.addEventListener('drop', function(e) {
        e.preventDefault(); e.stopPropagation();
        this.classList.remove('drag-over');
        var files = e.dataTransfer.files;
        if (files.length > 0) uploadKnowledgeFile(files[0]);
    });
    // ドロップゾーン全体クリックでもファイル選択を開く
    dropzone.addEventListener('click', function(e) {
        if (e.target.tagName === 'LABEL' || e.target.tagName === 'INPUT') return;
        document.getElementById('wrtKnowledgeFileInput').click();
    });

    /* ===== 自動記事生成タブ ===== */
    var AA_API = baseUrl + '/auto-article';
    var AA_GROUP_LABELS = {
        immediate: '今すぐ', local_seo: 'ローカルSEO', comparison: '比較',
        column: 'コラム', service_page: 'サービス', competitor_core: '競合コア',
        competitor_longterm: '長期', competitor_gap: '競合ギャップ', competitor_compare: '競合比較'
    };
    var AA_STATUS_LABELS = {
        pending: ['待機中', '#999'], processing: ['処理中', '#E67E22'],
        draft_created: ['下書き生成', '#27AE60'], published: ['公開済', '#2980B9'],
        skipped: ['スキップ', '#95A5A6'], angle_shifted: ['切り口変更', '#8E44AD'],
        failed: ['失敗', '#E74C3C']
    };

    var AA_FREQ_LABELS = {
        weekly_1: '週1回（火曜）', weekly_2: '週2回（火・金）',
        weekly_3: '週3回（月・水・金）', daily: '毎日'
    };

    function aaLoadSettings() {
        fetch(AA_API + '/settings', { headers: { 'X-WP-Nonce': nonce } })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) return;
                var s = d.settings;
                document.getElementById('aaEnabled').checked = s.enabled;
                document.getElementById('aaFrequency').value = s.frequency || 'weekly_2';
                document.getElementById('aaBatchSize').value = s.batch_size || 1;
                document.getElementById('aaMinScore').value = s.min_score;
                document.getElementById('aaQualityThreshold').value = s.quality_threshold;
                document.getElementById('aaAutoPublish').checked = s.auto_publish;
                document.getElementById('aaPreferredTone').value = s.preferred_tone;
                // サマリー表示
                var summary = document.getElementById('aaSettingsSummary');
                if (s.enabled) {
                    var freqLabel = AA_FREQ_LABELS[s.frequency] || '週2回（火・金）';
                    summary.innerHTML = '現在の設定: <strong>' + freqLabel + '</strong> / 1回あたり <strong>' + s.batch_size + '記事</strong>'
                        + (s.run_days ? ' / 実行日: <strong>' + esc(s.run_days) + '</strong>' : '');
                    summary.style.display = 'block';
                } else {
                    summary.innerHTML = '自動記事生成: <strong>OFF</strong>';
                    summary.style.display = 'block';
                }
            });
    }

    document.getElementById('aaSaveSettings').addEventListener('click', function() {
        var body = {
            enabled: document.getElementById('aaEnabled').checked,
            frequency: document.getElementById('aaFrequency').value,
            batch_size: parseInt(document.getElementById('aaBatchSize').value) || 1,
            min_score: parseInt(document.getElementById('aaMinScore').value) || 40,
            quality_threshold: parseInt(document.getElementById('aaQualityThreshold').value) || 60,
            auto_publish: document.getElementById('aaAutoPublish').checked,
            preferred_tone: document.getElementById('aaPreferredTone').value
        };
        fetch(AA_API + '/settings', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify(body)
        }).then(function(r) { return r.json(); }).then(function(d) {
            showToast(d.success ? '設定を保存しました' : (d.error || 'エラー'));
            if (d.success) aaLoadSettings(); // サマリー更新
        });
    });

    document.getElementById('aaLoadPreview').addEventListener('click', function() {
        var wrap = document.getElementById('aaPreviewWrap');
        var empty = document.getElementById('aaPreviewEmpty');
        var loading = document.getElementById('aaPreviewLoading');
        wrap.style.display = 'none'; empty.style.display = 'none'; loading.style.display = 'block';
        fetch(AA_API + '/preview', { headers: { 'X-WP-Nonce': nonce } })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                loading.style.display = 'none';
                if (!d.success || !d.candidates || d.candidates.length === 0) {
                    empty.style.display = 'block'; return;
                }
                var html = '';
                d.candidates.slice(0, 30).forEach(function(c) {
                    html += '<tr class="wrt-table__row">'
                        + '<td style="font-weight:500;">' + esc(c.keyword) + '</td>'
                        + '<td><span style="font-size:12px;padding:2px 8px;border-radius:4px;background:rgba(86,129,132,0.08);color:var(--mw-primary-blue);">' + (AA_GROUP_LABELS[c.group] || c.group) + '</span></td>'
                        + '<td style="text-align:right;font-weight:600;">' + Math.round(c.score) + '</td>'
                        + '<td style="text-align:right;">' + (c.volume || '-') + '</td>'
                        + '<td style="text-align:right;">' + (c.difficulty || '-') + '</td>'
                        + '<td><button class="wrt-btn wrt-btn--primary wrt-btn--sm aa-trigger-btn" data-keyword="' + esc(c.keyword) + '" type="button">生成</button></td>'
                        + '</tr>';
                });
                document.getElementById('aaPreviewBody').innerHTML = html;
                wrap.style.display = 'block';
            }).catch(function() { loading.style.display = 'none'; empty.style.display = 'block'; });
    });

    document.getElementById('aaPreviewBody').addEventListener('click', function(e) {
        var btn = e.target.closest('.aa-trigger-btn');
        if (!btn) return;
        btn.disabled = true; btn.textContent = '開始中...';
        var kw = btn.getAttribute('data-keyword');
        fetch(AA_API + '/trigger', {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({ keyword: kw })
        }).then(function(r) { return r.json(); }).then(function(d) {
            showToast(d.message || (d.success ? '生成開始' : 'エラー'));
            btn.textContent = d.success ? '開始済' : '生成';
            if (!d.success) btn.disabled = false;
            aaLoadHistory();
        });
    });

    function aaLoadHistory() {
        fetch(AA_API + '/history?limit=50', { headers: { 'X-WP-Nonce': nonce } })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                var wrap = document.getElementById('aaHistoryWrap');
                var empty = document.getElementById('aaHistoryEmpty');
                if (!d.success || !d.history || d.history.length === 0) {
                    wrap.style.display = 'none'; empty.style.display = 'block'; return;
                }
                var html = '';
                d.history.forEach(function(h) {
                    var sl = AA_STATUS_LABELS[h.status] || [h.status, '#999'];
                    var qColor = h.quality_score >= 70 ? '#27AE60' : (h.quality_score >= 50 ? '#E67E22' : '#E74C3C');
                    html += '<tr class="wrt-table__row">'
                        + '<td style="font-weight:500;">' + esc(h.keyword) + (h.final_keyword && h.final_keyword !== h.keyword ? '<br><span style="font-size:11px;color:var(--mw-text-tertiary);">→ ' + esc(h.final_keyword) + '</span>' : '') + '</td>'
                        + '<td><span style="font-size:12px;padding:2px 8px;border-radius:4px;background:rgba(86,129,132,0.08);color:var(--mw-primary-blue);">' + (AA_GROUP_LABELS[h.keyword_group] || h.keyword_group || '-') + '</span></td>'
                        + '<td style="text-align:center;"><span style="font-size:12px;padding:2px 10px;border-radius:4px;color:#fff;background:' + sl[1] + ';">' + sl[0] + '</span></td>'
                        + '<td style="text-align:right;">' + Math.round(h.priority_score) + '</td>'
                        + '<td style="text-align:right;">' + (h.quality_score !== null ? '<span style="color:' + qColor + ';font-weight:600;">' + Math.round(h.quality_score) + '</span>' : '-') + '</td>'
                        + '<td style="font-size:12px;color:var(--mw-text-tertiary);">' + (h.created_at ? h.created_at.substring(0, 16) : '-') + '</td>'
                        + '<td>' + (h.article_id ? '<a href="#" class="wrt-btn wrt-btn--secondary wrt-btn--sm aa-open-article" data-id="' + h.article_id + '">詳細</a>' : (h.error_message ? '<span title="' + esc(h.error_message) + '" style="cursor:help;font-size:12px;color:#E74C3C;">!</span>' : '')) + '</td>'
                        + '</tr>';
                });
                document.getElementById('aaHistoryBody').innerHTML = html;
                wrap.style.display = 'block'; empty.style.display = 'none';
            });
    }

    document.getElementById('aaHistoryBody').addEventListener('click', function(e) {
        var link = e.target.closest('.aa-open-article');
        if (!link) return;
        e.preventDefault();
        var id = parseInt(link.getAttribute('data-id'));
        if (id) openArticleDetail(id);
    });

    // タブ切替時に自動記事タブの初期化
    var aaLoaded = false;
    document.querySelectorAll('.wrt-tabs__tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            if (this.getAttribute('data-tab') === 'auto-article' && !aaLoaded) {
                aaLoaded = true;
                aaLoadSettings();
                aaLoadHistory();
            }
        });
    });

    /* ===== 初期化 ===== */
    loadArticles();
    loadKnowledge();
    if (initialKeyword) {
        setTimeout(function() { openKeywordModal(initialKeyword); }, 300);
    }

})();
</script>

<?php get_footer(); ?>
