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
            document.getElementById('wrtPanel' + tab.dataset.tab.charAt(0).toUpperCase() + tab.dataset.tab.slice(1)).classList.add('active');
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
            return '<tr class="wrt-table__row" data-id="' + a.id + '">'
                + '<td class="wrt-table__td-check"><input type="checkbox" class="wrt-article-check" data-id="' + a.id + '"></td>'
                + '<td class="wrt-table__td-title">' + esc(a.title) + riskBadge + '</td>'
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
        if (a.outline) {
            html += '<button class="wrt-btn wrt-btn--secondary" id="wrtShowOutlineBtn">構成案を見る</button>';
        }
        html += '</div>';
        html += '<div id="wrtDraftArea"></div>';
        html += '</div>';

        // 競合調査セクション
        html += '<div class="wrt-detail-section" id="wrtCompetitorSection">';
        html += '<div class="wrt-detail-section__title">競合調査</div>';
        if (a.competitor_research && a.competitor_research.analysis) {
            var cr = a.competitor_research;
            var okCount = (cr.competitors || []).filter(function(c) { return c.status === 'ok'; }).length;
            html += '<div style="font-size:12px;color:var(--mw-text-tertiary);margin-bottom:10px;">'
                  + okCount + '件の競合記事を分析済み'
                  + '（' + esc(cr.fetched_at || '') + '）</div>';

            if (cr.analysis.search_intent) {
                html += '<div style="margin-bottom:10px;"><div style="font-size:12px;font-weight:600;color:var(--mw-text-secondary);margin-bottom:4px;">検索意図</div>'
                      + '<p style="font-size:13px;margin:0;line-height:1.6;">' + esc(cr.analysis.search_intent) + '</p></div>';
            }
            if (cr.analysis.common_topics && cr.analysis.common_topics.length) {
                html += '<div style="margin-bottom:10px;"><div style="font-size:12px;font-weight:600;color:var(--mw-text-secondary);margin-bottom:4px;">共通トピック</div>'
                      + '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;">';
                cr.analysis.common_topics.forEach(function(t) { html += '<li>' + esc(t) + '</li>'; });
                html += '</ul></div>';
            }
            if (cr.analysis.content_gaps && cr.analysis.content_gaps.length) {
                html += '<div style="margin-bottom:10px;"><div style="font-size:12px;font-weight:600;color:var(--mw-accent-green,#22c55e);margin-bottom:4px;">コンテンツギャップ（差別化チャンス）</div>'
                      + '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;">';
                cr.analysis.content_gaps.forEach(function(g) { html += '<li>' + esc(g) + '</li>'; });
                html += '</ul></div>';
            }
            if (cr.analysis.recommended_angles && cr.analysis.recommended_angles.length) {
                html += '<div style="margin-bottom:10px;"><div style="font-size:12px;font-weight:600;color:var(--mw-text-secondary);margin-bottom:4px;">推奨差別化アングル</div>'
                      + '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6;">';
                cr.analysis.recommended_angles.forEach(function(a_) { html += '<li>' + esc(a_) + '</li>'; });
                html += '</ul></div>';
            }

            // 競合記事一覧（折りたたみ）
            var okCompetitors = (cr.competitors || []).filter(function(c) { return c.status === 'ok'; });
            if (okCompetitors.length) {
                html += '<details style="margin-top:8px;"><summary style="font-size:12px;color:var(--mw-text-tertiary);cursor:pointer;">競合記事一覧（' + okCompetitors.length + '件）</summary>';
                html += '<div style="margin-top:8px;">';
                okCompetitors.forEach(function(comp) {
                    html += '<div style="margin-bottom:10px;padding:8px;background:var(--mw-bg-secondary);border-radius:6px;">';
                    html += '<div style="font-size:12px;font-weight:600;">' + esc(comp.rank) + '位: ' + esc(comp.title) + '</div>';
                    html += '<div style="font-size:11px;color:var(--mw-text-tertiary);margin:2px 0;">' + esc(comp.url) + '</div>';
                    if (comp.headings && comp.headings.h2 && comp.headings.h2.length) {
                        html += '<ul style="margin:4px 0 0;padding-left:16px;font-size:12px;color:var(--mw-text-secondary);">';
                        comp.headings.h2.forEach(function(h) { html += '<li>' + esc(h) + '</li>'; });
                        html += '</ul>';
                    }
                    html += '</div>';
                });
                html += '</div></details>';
            }

            html += '<div style="margin-top:10px;"><button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtRerunCompetitorBtn">競合調査を再実行</button></div>';
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

        // 追加ヒアリング
        html += '<div class="wrt-detail-section" id="wrtInterviewSection">';
        html += '<div class="wrt-detail-section__title">追加ヒアリング</div>';
        html += '<p style="font-size:12px;color:var(--mw-text-tertiary);margin-bottom:8px;">情報ストックの内容をもとに、記事執筆に不足している情報をAIが質問します。回答すると本文生成の精度が上がります。</p>';
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtGenerateInterviewBtn">' + (a.interview ? 'ヒアリングを再生成' : 'ヒアリング質問を生成') + '</button>';
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

        // 競合調査ボタン
        var competitorBtn = document.getElementById('wrtRunCompetitorBtn') || document.getElementById('wrtRerunCompetitorBtn');
        if (competitorBtn) {
            competitorBtn.addEventListener('click', function() {
                var isRerun = this.id === 'wrtRerunCompetitorBtn';
                showProgress('競合調査中…（上位記事をクロール・分析しています。2〜3分かかります）');
                apiFetch('/articles/' + a.id + '/competitor-research', {
                    method: 'POST',
                    body: { force: isRerun }
                }).then(function(res) {
                    hideProgress();
                    if (!res.success) { showToast(res.error || 'エラー', true); return; }
                    showToast('競合調査が完了しました');
                    showArticleDetail(a.id); // 画面リロード
                }).catch(function() {
                    hideProgress();
                    showToast('通信エラー', true);
                });
            });
        }

        // ヒアリング生成
        document.getElementById('wrtGenerateInterviewBtn').addEventListener('click', function() {
            showProgress('ヒアリング質問を生成中…');
            apiFetch('/articles/' + a.id + '/interview', { method: 'POST' }).then(function(res) {
                hideProgress();
                if (res.success) { currentArticle.interview = res.interview; renderInterview(res.interview, a.id); showToast('ヒアリング質問を生成しました'); }
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

        // 構成案を見るボタン
        var outlineBtn = document.getElementById('wrtShowOutlineBtn');
        if (outlineBtn) {
            outlineBtn.addEventListener('click', function() {
                renderOutlineModal(a.outline);
                document.getElementById('wrtOutlineModal').classList.add('active');
            });
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
        if (a.interview) renderInterview(a.interview, a.id);
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

    /* ===== ヒアリング表示 ===== */
    var priorityLabels = { high: '高', medium: '中', low: '低' };
    var priorityColors = { high: '#ef4444', medium: '#f59e0b', low: '#9ca3af' };
    function renderInterview(interview, articleId) {
        var area = document.getElementById('wrtInterviewArea');
        if (!area || !interview || !interview.questions || interview.questions.length === 0) return;
        var answers = interview.answers || {};
        var html = '<div style="margin-top:12px;">';
        interview.questions.forEach(function(q, idx) {
            var ans = answers[idx] || '';
            html += '<div style="margin-bottom:16px;padding:12px;background:var(--mw-bg-secondary);border-radius:8px;">';
            // 質問ヘッダー（優先度バッジ付き）
            html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">';
            if (q.priority && priorityColors[q.priority]) {
                html += '<span style="font-size:10px;font-weight:700;color:#fff;background:' + priorityColors[q.priority] + ';padding:1px 6px;border-radius:3px;white-space:nowrap;">優先度: ' + esc(priorityLabels[q.priority] || q.priority) + '</span>';
            }
            html += '<div style="font-size:13px;font-weight:600;color:var(--mw-text-heading);">Q' + (idx+1) + '. ' + esc(q.question) + '</div>';
            html += '</div>';
            // 質問の理由
            if (q.reason) {
                html += '<div style="font-size:11px;color:var(--mw-text-secondary);margin-bottom:4px;">理由: ' + esc(q.reason) + '</div>';
            }
            // 反映先見出し
            if (q.target_headings && q.target_headings.length) {
                html += '<div style="font-size:11px;color:var(--mw-text-tertiary);margin-bottom:4px;">反映先: ' + q.target_headings.map(function(h) { return esc(h); }).join(', ') + '</div>';
            }
            // ヒント・回答例
            if (q.hint) html += '<div style="font-size:11px;color:var(--mw-text-tertiary);margin-bottom:6px;">回答例: ' + esc(q.hint) + '</div>';
            html += '<div style="display:flex;gap:6px;align-items:flex-start;">';
            if (q.field_type === 'textarea') {
                html += '<textarea class="wrt-interview-ans" data-idx="' + idx + '" rows="3" placeholder="回答を入力" style="flex:1;padding:8px;border:1px solid var(--mw-border-light);border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;background:var(--mw-bg-primary);color:var(--mw-text-primary);">' + esc(ans) + '</textarea>';
            } else {
                html += '<input type="text" class="wrt-interview-ans" data-idx="' + idx + '" value="' + esc(ans).replace(/"/g, '&quot;') + '" placeholder="回答を入力" style="flex:1;padding:8px;border:1px solid var(--mw-border-light);border-radius:6px;font-size:13px;box-sizing:border-box;background:var(--mw-bg-primary);color:var(--mw-text-primary);">';
            }
            html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm wrt-voice-btn" data-target-idx="' + idx + '" title="音声入力" style="padding:6px 10px;font-size:16px;line-height:1;">🎤</button>';
            html += '</div></div>';
        });
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtSaveInterviewBtn">回答を保存</button>';
        html += '</div>';
        area.innerHTML = html;

        // 音声入力ボタン → モーダルを開く
        area.querySelectorAll('.wrt-voice-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var idx = btn.dataset.targetIdx;
                var target = area.querySelector('.wrt-interview-ans[data-idx="' + idx + '"]');
                if (target) openVoiceModal(target);
            });
        });

        document.getElementById('wrtSaveInterviewBtn').addEventListener('click', function() {
            var answersObj = {};
            document.querySelectorAll('.wrt-interview-ans').forEach(function(el) {
                answersObj[el.dataset.idx] = el.value;
            });
            apiFetch('/articles/' + articleId + '/interview', { method: 'PUT', body: { answers: answersObj } }).then(function(res) {
                if (res.success) { currentArticle.interview = res.interview; showToast('回答を保存しました'); }
                else showToast(res.error || 'エラー', true);
            });
        });
    }

    /* ===== 構成案モーダル ===== */
    function renderOutlineModal(outline) {
        var body = document.getElementById('wrtOutlineModalBody');
        if (!outline) { body.innerHTML = '<p style="color:var(--mw-text-tertiary);">構成案がありません</p>'; return; }
        var html = '';

        // タイトル候補
        if (outline.title_options && outline.title_options.length > 0) {
            html += '<div class="wrt-outline__titles">';
            html += '<div style="font-size:13px;font-weight:600;color:var(--mw-text-secondary);margin-bottom:8px;">タイトル候補</div>';
            outline.title_options.forEach(function(t) {
                html += '<div class="wrt-outline__title-opt">' + esc(t) + '</div>';
            });
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

    /* ===== 初期化 ===== */
    loadArticles();
    loadKnowledge();
    if (initialKeyword) {
        setTimeout(function() { openKeywordModal(initialKeyword); }, 300);
    }

})();
</script>

<?php get_footer(); ?>
