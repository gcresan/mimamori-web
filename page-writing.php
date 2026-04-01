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
.wrt-header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
.wrt-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: opacity 0.15s; }
.wrt-btn--primary { background: var(--mw-primary-blue, #4A90A4); color: #fff; }
.wrt-btn--primary:hover { opacity: 0.9; }
.wrt-btn--secondary { background: var(--mw-bg-secondary); color: var(--mw-text-heading); border: 1px solid var(--mw-border-light); }
.wrt-btn--sm { padding: 6px 14px; font-size: 12px; }
.wrt-btn--danger { background: rgba(201,90,79,0.1); color: #C95A4F; }
.wrt-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* タブ */
.wrt-tabs { display: flex; gap: 0; margin-bottom: 24px; border-bottom: 2px solid var(--mw-border-light); }
.wrt-tabs__tab { padding: 10px 20px; font-size: 14px; font-weight: 600; color: var(--mw-text-tertiary); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: all 0.15s; background: none; border-top: none; border-left: none; border-right: none; }
.wrt-tabs__tab.active { color: var(--mw-primary-blue, #4A90A4); border-bottom-color: var(--mw-primary-blue, #4A90A4); }
.wrt-tabs__tab:hover { color: var(--mw-text-heading); }
.wrt-tab-panel { display: none; }
.wrt-tab-panel.active { display: block; }

/* カード */
.wrt-card { background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light); border-radius: var(--mw-radius-md, 12px); padding: 20px; margin-bottom: 16px; cursor: pointer; transition: box-shadow 0.15s; }
.wrt-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
.wrt-card__title { font-size: 15px; font-weight: 600; color: var(--mw-text-heading); margin: 0 0 6px; }
.wrt-card__meta { font-size: 12px; color: var(--mw-text-tertiary); display: flex; gap: 12px; flex-wrap: wrap; }
.wrt-status { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.wrt-status--keyword_set { background: rgba(201,168,76,0.15); color: #C9A84C; }
.wrt-status--outline_generated { background: rgba(39,174,96,0.12); color: #27AE60; }
.wrt-status--draft_generated { background: rgba(74,144,164,0.12); color: #2D7A8F; }
.wrt-status--wp_draft_saved { background: rgba(66,133,244,0.12); color: #4285F4; }

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
.wrt-modal__close { position: absolute; top: 16px; right: 16px; background: none; border: none; font-size: 20px; cursor: pointer; color: var(--mw-text-tertiary); padding: 4px; }
.wrt-modal label { display: block; font-size: 13px; font-weight: 600; color: var(--mw-text-secondary); margin-bottom: 6px; }
.wrt-modal input[type="text"], .wrt-modal textarea, .wrt-modal select { width: 100%; padding: 10px 12px; border: 1px solid var(--mw-border-light); border-radius: 8px; font-size: 14px; background: var(--mw-bg-primary); color: var(--mw-text-primary); box-sizing: border-box; }
.wrt-modal textarea { resize: vertical; min-height: 120px; }
.wrt-modal__actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
.wrt-modal__field { margin-bottom: 16px; }

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
.wrt-detail__back { display: inline-flex; align-items: center; gap: 4px; padding: 6px 14px; border: 1px solid var(--mw-border-light); border-radius: 6px; font-size: 13px; font-weight: 500; color: var(--mw-text-secondary); cursor: pointer; background: var(--mw-bg-secondary); transition: all 0.15s; text-decoration: none; }
.wrt-detail__back:hover { background: var(--mw-bg-primary); border-color: var(--mw-primary-blue); color: var(--mw-text-heading); }
.wrt-detail__keyword-section { display: flex; align-items: baseline; gap: 16px; margin-bottom: 24px; padding: 16px 20px; background: var(--mw-bg-secondary); border-radius: 10px; border: 1px solid var(--mw-border-light); }
.wrt-detail__keyword-label { font-size: 13px; font-weight: 600; color: var(--mw-text-tertiary); white-space: nowrap; }
.wrt-detail__keyword-value { font-size: 20px; font-weight: 700; color: var(--mw-text-heading); }
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
.wrt-file-item__del { background: none; border: none; color: #C95A4F; cursor: pointer; font-size: 14px; padding: 2px; }

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
.wrt-draft-stats { display: flex; gap: 20px; justify-content: flex-end; padding: 12px 0 0; margin-top: 24px; border-top: 1px solid #e2e8f0; font-size: 13px; color: #94a3b8; }

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
    .wrt-settings-grid { grid-template-columns: 1fr; }
    .wrt-header { flex-direction: column; align-items: stretch; }
}
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

    <!-- ===== ヘッダー ===== -->
    <div class="wrt-header">
        <button class="wrt-btn wrt-btn--primary" id="wrtNewArticleBtn" type="button">新規記事作成</button>
    </div>

    <!-- ===== タブ ===== -->
    <div class="wrt-tabs">
        <button class="wrt-tabs__tab active" data-tab="articles">記事一覧</button>
        <button class="wrt-tabs__tab" data-tab="knowledge">情報ストック</button>
    </div>

    <!-- ===== 記事一覧タブ ===== -->
    <div class="wrt-tab-panel active" id="wrtPanelArticles">
        <div id="wrtArticleList"></div>
        <div class="wrt-empty" id="wrtArticleEmpty">
            <div class="wrt-empty__icon">✍️</div>
            <div class="wrt-empty__text">まだ記事がありません</div>
        </div>
    </div>

    <!-- ===== 情報ストックタブ ===== -->
    <div class="wrt-tab-panel" id="wrtPanelKnowledge">
        <div style="margin-bottom:16px;">
            <button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtNewKnowledgeBtn" type="button">情報を追加</button>
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
            document.querySelectorAll('.wrt-tabs, .wrt-tab-panel, .wrt-header').forEach(function(el) { el.style.display = ''; });
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
    function renderArticles() {
        var container = document.getElementById('wrtArticleList');
        var empty = document.getElementById('wrtArticleEmpty');
        if (articlesData.length === 0) { container.innerHTML = ''; empty.style.display = ''; return; }
        empty.style.display = 'none';
        container.innerHTML = articlesData.map(function(a) {
            var stCls = 'wrt-status wrt-status--' + a.status;
            return '<div class="wrt-card" data-id="' + a.id + '">'
                + '<div class="wrt-card__title">' + esc(a.keyword) + '</div>'
                + '<div class="wrt-card__meta">'
                + '<span class="' + stCls + '">' + esc(statusLabels[a.status] || a.status) + '</span>'
                + '<span>' + esc(typeLabels[a.type] || '') + '</span>'
                + '<span>' + esc(a.created_at) + '</span>'
                + '</div></div>';
        }).join('');
        container.querySelectorAll('.wrt-card').forEach(function(card) {
            card.addEventListener('click', function() { showArticleDetail(parseInt(card.dataset.id)); });
        });
    }
    function createArticle(keyword) {
        closeKeywordModal();
        showProgress('記事を作成中…');
        apiFetch('/articles', { method: 'POST', body: { keyword: keyword } }).then(function(res) {
            hideProgress();
            if (!res.success) { showToast(res.error || 'エラー', true); return; }
            showToast('記事「' + keyword + '」を作成しました');
            loadArticles();
            showArticleDetail(res.article.id);
        }).catch(function() { hideProgress(); showToast('通信エラー', true); });
    }

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
        document.querySelectorAll('.wrt-tabs, .wrt-tab-panel, .wrt-header').forEach(function(el) { el.style.display = 'none'; });
        var view = document.getElementById('wrtDetailView');
        view.style.display = '';

        var html = '<div class="wrt-detail">';
        html += '<div class="wrt-detail__topbar">';
        html += '<span class="wrt-detail__back" id="wrtBackBtn">← 一覧に戻る</span>';
        html += '<span style="flex:1;"></span>';
        html += '<button class="wrt-btn wrt-btn--danger wrt-btn--sm" id="wrtDeleteArticleBtn">削除</button>';
        html += '</div>';
        html += '<div class="wrt-detail__keyword-section">';
        html += '<span class="wrt-detail__keyword-label">対策キーワード</span>';
        html += '<span class="wrt-detail__keyword-value">' + esc(a.keyword) + '</span>';
        html += '</div>';

        // 設定セクション
        html += '<div class="wrt-detail-section"><div class="wrt-detail-section__title">記事設定</div>';
        html += '<div class="wrt-settings-grid">';
        html += '<div><label>記事タイプ</label><select id="wrtSetType">';
        Object.keys(typeLabels).forEach(function(k) { html += '<option value="' + k + '"' + (a.type === k ? ' selected' : '') + '>' + esc(typeLabels[k]) + '</option>'; });
        html += '</select></div>';
        html += '<div><label>目的</label><select id="wrtSetPurpose">';
        Object.keys(purposeLabels).forEach(function(k) { html += '<option value="' + k + '"' + (a.purpose === k ? ' selected' : '') + '>' + esc(purposeLabels[k]) + '</option>'; });
        html += '</select></div>';
        html += '<div><label>文体</label><select id="wrtSetTone">';
        Object.keys(toneLabels).forEach(function(k) { html += '<option value="' + k + '"' + (a.tone === k ? ' selected' : '') + '>' + esc(toneLabels[k]) + '</option>'; });
        html += '</select></div>';
        html += '<div style="grid-column:1/-1;"><label>想定読者</label><input type="text" id="wrtSetReader" value="' + esc(a.target_reader) + '" placeholder="例: 松山市で歯医者を探している30代主婦"></div>';
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

        // ヒアリングセクション
        html += '<div class="wrt-detail-section" id="wrtInterviewSection">';
        html += '<div class="wrt-detail-section__title">追加ヒアリング</div>';
        html += '<p style="font-size:12px;color:var(--mw-text-tertiary);margin-bottom:8px;">情報ストックの内容をもとに、記事執筆に不足している情報をAIが質問します。回答すると本文生成の精度が上がります。</p>';
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtGenerateInterviewBtn">' + (a.interview ? 'ヒアリングを再生成' : 'ヒアリング質問を生成') + '</button>';
        html += '<div id="wrtInterviewArea"></div>';
        html += '</div>';

        // 本文生成セクション
        html += '<div class="wrt-detail-section" id="wrtDraftSection">';
        html += '<div class="wrt-detail-section__title">本文生成</div>';
        html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        html += '<button class="wrt-btn wrt-btn--primary" id="wrtGenerateDraftBtn">' + (a.draft_content ? '本文を再生成' : '本文たたき台を生成') + '</button>';
        if (a.draft_content) {
            html += '<button class="wrt-btn wrt-btn--secondary" id="wrtSaveWpDraftBtn">WordPress下書き保存</button>';
        }
        if (a.wp_draft_id) {
            html += '<a href="' + <?php echo wp_json_encode( admin_url( 'post.php?action=edit&post=' ) ); ?> + a.wp_draft_id + '" target="_blank" class="wrt-btn wrt-btn--secondary wrt-btn--sm" style="text-decoration:none;">WP編集画面を開く</a>';
        }
        html += '</div>';
        html += '<div id="wrtDraftArea"></div>';
        html += '</div>';

        html += '</div>';
        view.innerHTML = html;

        // 戻るボタン
        document.getElementById('wrtBackBtn').addEventListener('click', function() {
            view.style.display = 'none';
            document.querySelectorAll('.wrt-tabs, .wrt-tab-panel, .wrt-header').forEach(function(el) { el.style.display = ''; });
            loadArticles();
        });

        // 削除ボタン
        document.getElementById('wrtDeleteArticleBtn').addEventListener('click', function() {
            if (!confirm('この記事を削除しますか？')) return;
            apiFetch('/articles/' + a.id, { method: 'DELETE' }).then(function() {
                showToast('削除しました');
                view.style.display = 'none';
                document.querySelectorAll('.wrt-tabs, .wrt-tab-panel, .wrt-header').forEach(function(el) { el.style.display = ''; });
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
            showProgress('本文を生成中…（1〜2分程度）');
            apiFetch('/articles/' + a.id + '/draft', { method: 'POST' }).then(function(res) {
                hideProgress();
                if (res.success) { currentArticle.draft_content = res.draft_content; currentArticle.status = 'draft_generated'; renderDraft(res.draft_content); showToast('本文を生成しました'); renderArticleDetail(); }
                else showToast(res.error || 'エラー', true);
            }).catch(function() { hideProgress(); showToast('通信エラー', true); });
        });

        // WP下書き保存
        var wpDraftBtn = document.getElementById('wrtSaveWpDraftBtn');
        if (wpDraftBtn) {
            wpDraftBtn.addEventListener('click', function() {
                showProgress('WordPress下書きを保存中…');
                apiFetch('/articles/' + a.id + '/wp-draft', { method: 'POST' }).then(function(res) {
                    hideProgress();
                    if (res.success) { currentArticle.wp_draft_id = res.draft_id; showToast('WordPress下書きを保存しました'); renderArticleDetail(); }
                    else showToast(res.error || 'エラー', true);
                }).catch(function() { hideProgress(); showToast('通信エラー', true); });
            });
        }

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

    var hasSpeechRecognition = 'webkitSpeechRecognition' in window || 'SpeechRecognition' in window;

    function renderInterview(interview, articleId) {
        var area = document.getElementById('wrtInterviewArea');
        if (!area || !interview || !interview.questions || interview.questions.length === 0) return;
        var answers = interview.answers || {};
        var html = '<div style="margin-top:12px;">';
        interview.questions.forEach(function(q, idx) {
            var ans = answers[idx] || '';
            html += '<div style="margin-bottom:16px;padding:12px;background:var(--mw-bg-secondary);border-radius:8px;">';
            html += '<div style="font-size:13px;font-weight:600;color:var(--mw-text-heading);margin-bottom:6px;">Q' + (idx+1) + '. ' + esc(q.question) + '</div>';
            if (q.hint) html += '<div style="font-size:11px;color:var(--mw-text-tertiary);margin-bottom:6px;">ヒント: ' + esc(q.hint) + '</div>';
            html += '<div style="display:flex;gap:6px;align-items:flex-start;">';
            if (q.field_type === 'textarea') {
                html += '<textarea class="wrt-interview-ans" data-idx="' + idx + '" rows="3" placeholder="回答を入力（テキスト or 音声）" style="flex:1;padding:8px;border:1px solid var(--mw-border-light);border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;background:var(--mw-bg-primary);color:var(--mw-text-primary);">' + esc(ans) + '</textarea>';
            } else {
                html += '<input type="text" class="wrt-interview-ans" data-idx="' + idx + '" value="' + esc(ans).replace(/"/g, '&quot;') + '" placeholder="回答を入力（テキスト or 音声）" style="flex:1;padding:8px;border:1px solid var(--mw-border-light);border-radius:6px;font-size:13px;box-sizing:border-box;background:var(--mw-bg-primary);color:var(--mw-text-primary);">';
            }
            if (hasSpeechRecognition) {
                html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm wrt-voice-btn" data-target-idx="' + idx + '" title="音声入力" style="padding:6px 10px;font-size:16px;line-height:1;">🎤</button>';
            }
            html += '</div></div>';
        });
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtSaveInterviewBtn">回答を保存</button>';
        html += '</div>';
        area.innerHTML = html;

        // 音声入力イベント
        if (hasSpeechRecognition) {
            area.querySelectorAll('.wrt-voice-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var idx = btn.dataset.targetIdx;
                    var target = area.querySelector('.wrt-interview-ans[data-idx="' + idx + '"]');
                    if (!target) return;
                    startVoiceInput(btn, target);
                });
            });
        }

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

    /* ===== 音声入力 (Web Speech API) ===== */
    function startVoiceInput(btn, targetEl) {
        var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) { showToast('お使いのブラウザは音声入力に対応していません', true); return; }

        var recognition = new SpeechRecognition();
        recognition.lang = 'ja-JP';
        recognition.continuous = true;
        recognition.interimResults = true;

        var origText = btn.textContent;
        var finalTranscript = '';
        btn.textContent = '⏹️';
        btn.style.background = 'rgba(201,90,79,0.15)';
        btn.title = '音声入力中…クリックで停止';

        recognition.onresult = function(e) {
            var interim = '';
            for (var i = e.resultIndex; i < e.results.length; i++) {
                if (e.results[i].isFinal) {
                    finalTranscript += e.results[i][0].transcript;
                } else {
                    interim += e.results[i][0].transcript;
                }
            }
            var current = targetEl.value || '';
            // 既存テキストの末尾に追記
            var separator = current && !current.endsWith('\n') && !current.endsWith(' ') ? ' ' : '';
            targetEl.value = current.replace(/\s*$/, '') + separator + finalTranscript + interim;
        };

        recognition.onend = function() {
            btn.textContent = origText;
            btn.style.background = '';
            btn.title = '音声入力';
            // 最終テキストを確定
            var current = targetEl.value || '';
            if (finalTranscript) {
                showToast('音声入力を完了しました');
            }
        };

        recognition.onerror = function(e) {
            btn.textContent = origText;
            btn.style.background = '';
            if (e.error !== 'aborted') {
                showToast('音声認識エラー: ' + e.error, true);
            }
        };

        // 2回目クリックで停止
        btn.addEventListener('click', function stopHandler() {
            recognition.stop();
            btn.removeEventListener('click', stopHandler);
        }, { once: true });

        recognition.start();
    }

    function renderDraft(content) {
        var area = document.getElementById('wrtDraftArea');
        if (!area || !content) return;

        // タイトル・本文の文字数を算出
        var titleMatch = content.match(/^# (.+)$/m);
        var titleText = titleMatch ? titleMatch[1] : '';
        var titleLen = titleText.length;
        var bodyText = content.replace(/^#{1,4} .+$/gm, '').replace(/\*\*(.+?)\*\*/g, '$1').replace(/^[-*] /gm, '').replace(/\n+/g, '').trim();
        var bodyLen = bodyText.length;

        var html = '<div class="wrt-draft-container">';

        // ツールバー
        html += '<div class="wrt-draft-toolbar">';
        html += '<div class="wrt-draft-toolbar__left">';
        html += '<span class="wrt-draft-tag">h1</span><span class="wrt-draft-tag">h2</span><span class="wrt-draft-tag">h3</span><span class="wrt-draft-tag">h4</span>';
        html += '<span class="wrt-draft-tag"><strong>B</strong></span>';
        html += '</div>';
        html += '<div class="wrt-draft-toolbar__right">';
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtCopyMarkdown" title="Markdown形式">Markdown</button>';
        html += '<button class="wrt-btn wrt-btn--secondary wrt-btn--sm" id="wrtCopyHtml" title="HTML形式">HTML</button>';
        html += '<button class="wrt-btn wrt-btn--primary wrt-btn--sm" id="wrtCopyText" title="プレーンテキスト">コピー</button>';
        html += '</div></div>';

        // 本文プレビュー
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

        // 文字数表示（右下）
        html += '<div class="wrt-draft-stats">';
        html += '<span>' + titleLen + '文字/タイトル</span>';
        html += '<span>' + bodyLen.toLocaleString() + '文字/本文</span>';
        html += '</div>';

        html += '</div></div>';
        area.innerHTML = html;

        // コピーイベント
        document.getElementById('wrtCopyMarkdown').addEventListener('click', function() {
            navigator.clipboard.writeText(content).then(function() { showToast('Markdownをコピーしました'); });
        });
        document.getElementById('wrtCopyHtml').addEventListener('click', function() {
            var htmlContent = content
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
            var text = content
                .replace(/^#{1,4} /gm, '')
                .replace(/\*\*(.+?)\*\*/g, '$1')
                .replace(/^[-*] /gm, '・');
            navigator.clipboard.writeText(text).then(function() { showToast('本文をコピーしました'); });
        });
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
    function loadKnowledge() {
        apiFetch('/knowledge').then(function(res) {
            knowledgeData = res.items || [];
            renderKnowledge();
        });
    }
    function renderKnowledge() {
        var container = document.getElementById('wrtKnowledgeList');
        var empty = document.getElementById('wrtKnowledgeEmpty');
        if (knowledgeData.length === 0) { container.innerHTML = ''; empty.style.display = ''; return; }
        empty.style.display = 'none';
        container.innerHTML = knowledgeData.map(function(ki) {
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
