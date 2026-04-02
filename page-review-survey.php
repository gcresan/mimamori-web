<?php
/*
Template Name: 口コミ用アンケート作成
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

set_query_var( 'gcrev_page_title', '口コミ用アンケート管理' );
set_query_var( 'gcrev_page_subtitle', 'お客様向けのアンケートを作成し、回答から口コミ案を自動生成します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '口コミ用アンケート管理', 'MEO' ) );

get_header();
?>

<style>
/* ===== page-review-survey — Page-specific styles ===== */

.sv-usage-bar {
    display: flex; align-items: center; gap: 12px;
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    padding: 16px 20px; margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.sv-usage-meter {
    flex: 1; max-width: 200px; height: 8px;
    background: #e5e7eb; border-radius: 4px; overflow: hidden;
}
.sv-usage-meter-fill {
    height: 100%; border-radius: 4px;
    background: var(--mw-primary-blue, #568184);
    transition: width 0.3s;
}
.sv-usage-meter-fill.full { background: #dc2626; }
.sv-usage-text {
    font-size: 14px; color: var(--mw-text-primary, #263335); font-weight: 600;
}
.sv-usage-limit-msg {
    font-size: 13px; color: #dc2626; margin-left: auto;
}

.sv-header-row {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px;
}
.sv-btn-new {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 20px;
    background: var(--mw-primary-blue, #568184); color: #fff;
    font-size: 14px; font-weight: 600; border: none; border-radius: 8px;
    cursor: pointer; transition: all 0.25s ease;
}
.sv-btn-new:hover { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.sv-btn-new:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.sv-btn-new:focus-visible { outline: 2px solid var(--mw-primary-blue, #568184); outline-offset: 2px; }
.sv-btn-new:disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

/* Survey cards */
.sv-card {
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    padding: 20px; margin-bottom: 12px;
    transition: box-shadow 0.15s;
}
.sv-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.sv-card-header {
    display: flex; align-items: center; gap: 10px; margin-bottom: 8px;
}
.sv-card-title {
    font-size: 16px; font-weight: 700; color: var(--mw-text-heading, #1A2F33);
    flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.sv-badge {
    font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px; flex-shrink: 0;
}
.sv-badge-published { background: #d1fae5; color: #065f46; }
.sv-badge-draft { background: #f3f4f6; color: #6b7280; }
.sv-card-meta {
    display: flex; gap: 16px; font-size: 13px; color: #6b7280; margin-bottom: 12px;
}
.sv-card-actions {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
}
.sv-btn-sm {
    padding: 6px 14px; font-size: 13px; font-weight: 600;
    border-radius: 6px; cursor: pointer; border: 1.5px solid; transition: background 0.15s;
}
.sv-btn-edit {
    background: #fff; color: var(--mw-primary-blue, #568184);
    border-color: var(--mw-primary-blue, #568184);
}
.sv-btn-edit:hover { background: #f0f7f7; }
.sv-btn-copy-url {
    background: #fff; color: #3b82f6; border-color: #3b82f6;
}
.sv-btn-copy-url:hover { background: #eff6ff; }
.sv-btn-copy-url.copied { background: #d1fae5; color: #059669; border-color: #059669; }
.sv-btn-delete {
    background: #fff; color: #dc2626; border-color: #fca5a5;
}
.sv-btn-delete:hover { background: #fef2f2; }

.sv-empty {
    text-align: center; padding: 48px 20px; color: #888;
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.sv-empty-icon { font-size: 40px; margin-bottom: 12px; opacity: 0.5; }
.sv-empty-text { font-size: 15px; }

/* ===== Edit view ===== */
.sv-back-link {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 14px; color: var(--mw-primary-blue, #568184);
    text-decoration: none; margin-bottom: 16px; cursor: pointer;
}
.sv-back-link:hover { text-decoration: underline; }

.sv-form-card {
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    padding: 24px; margin-bottom: 20px;
}
.sv-form-title {
    font-size: 16px; font-weight: 700; color: var(--mw-text-heading, #1A2F33);
    margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;
}
.sv-form-group { margin-bottom: 16px; }
.sv-form-label {
    display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 4px;
}
.sv-form-input, .sv-form-select, .sv-form-textarea {
    width: 100%; padding: 10px 12px; font-size: 14px; font-family: inherit;
    border: 1.5px solid #e5e7eb; border-radius: 8px; background: #f9fafb;
    transition: border-color 0.15s;
}
.sv-form-input:focus, .sv-form-select:focus, .sv-form-textarea:focus {
    outline: none; border-color: var(--mw-primary-blue, #568184); background: #fff;
}
.sv-form-textarea { min-height: 80px; resize: vertical; line-height: 1.6; }

.sv-form-row {
    display: flex; gap: 12px;
}
.sv-form-row > .sv-form-group { flex: 1; }

.sv-btn-save {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 24px; background: var(--mw-primary-blue, #568184);
    color: #fff; font-size: 14px; font-weight: 600; border: none; border-radius: 8px;
    cursor: pointer; transition: all 0.25s ease;
}
.sv-btn-save:hover { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.sv-btn-save:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.sv-btn-save:focus-visible { outline: 2px solid var(--mw-primary-blue, #568184); outline-offset: 2px; }
.sv-btn-save:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

/* Public URL */
.sv-public-url {
    display: flex; align-items: center; gap: 8px;
    background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;
    padding: 10px 14px; margin-bottom: 16px;
}
.sv-public-url input {
    flex: 1; border: none; background: transparent; font-size: 13px; color: #374151;
    outline: none;
}
.sv-public-url-copy {
    padding: 4px 12px; font-size: 12px; font-weight: 600;
    background: #fff; color: #059669; border: 1px solid #059669; border-radius: 6px;
    cursor: pointer;
}

/* Question list */
.sv-q-table {
    width: 100%; border-collapse: collapse; font-size: 14px;
}
.sv-q-table th {
    text-align: left; font-size: 12px; font-weight: 600; color: #6b7280;
    padding: 8px 10px; border-bottom: 1px solid #e5e7eb;
}
.sv-q-table td {
    padding: 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle;
}
.sv-q-table tr:last-child td { border-bottom: none; }
.sv-q-type-badge {
    display: inline-block; font-size: 11px; font-weight: 600;
    padding: 2px 8px; border-radius: 4px; background: #e0e7ff; color: #3730a3;
}
.sv-q-required { color: #dc2626; font-size: 12px; font-weight: 600; }
.sv-q-optional { color: #16a34a; font-size: 12px; }
.sv-q-actions { display: flex; gap: 6px; }
.sv-q-btn {
    padding: 4px 10px; font-size: 12px; font-weight: 600; border-radius: 4px;
    cursor: pointer; border: 1px solid; background: #fff;
}
.sv-q-btn-edit { color: var(--mw-primary-blue, #568184); border-color: var(--mw-primary-blue, #568184); }
.sv-q-btn-del { color: #dc2626; border-color: #fca5a5; }

.sv-q-empty {
    text-align: center; padding: 24px; color: #9ca3af; font-size: 14px;
}

/* Drag & drop sorting */
.sv-q-table tbody tr { cursor: grab; }
.sv-q-table tbody tr:active { cursor: grabbing; }
.sv-q-table tbody tr.sv-drag-over { border-top: 3px solid var(--mw-primary-blue, #568184); }
.sv-q-drag-handle { color: #94a3b8; font-size: 16px; cursor: grab; user-select: none; }
.sv-q-drag-handle:hover { color: #64748b; }
.sv-q-sort-status { font-size: 12px; margin-left: 8px; }
.sv-q-sort-status.saving { color: var(--mw-primary-blue, #568184); }
.sv-q-sort-status.saved { color: #059669; }

/* Question add/edit form */
.sv-q-form {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: 16px; margin-top: 16px;
}
.sv-q-form-title {
    font-size: 14px; font-weight: 700; color: var(--mw-text-heading, #1A2F33);
    margin-bottom: 12px;
}
.sv-q-form-row {
    display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;
}
.sv-q-form-row > .sv-form-group { flex: 1; min-width: 150px; }
.sv-q-form-actions { display: flex; gap: 8px; }
.sv-btn-secondary {
    padding: 8px 16px; font-size: 13px; font-weight: 600;
    background: #fff; color: #374151; border: 1.5px solid #d1d5db; border-radius: 6px;
    cursor: pointer;
}
.sv-btn-secondary:hover { background: #f9fafb; }

/* Keyword tags */
.sv-keyword-tag {
    display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px;
    background: #eff6ff; color: #1d4ed8; font-size: 12px; font-weight: 600;
    border-radius: 20px; border: 1px solid #bfdbfe;
}
.sv-keyword-remove {
    width: 16px; height: 16px; border: none; background: none; color: #93c5fd;
    font-size: 14px; line-height: 1; cursor: pointer; padding: 0;
}
.sv-keyword-remove:hover { color: #dc2626; }

/* Color customization */
.sv-color-row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; }
.sv-color-group { flex: 1; min-width: 140px; }
.sv-color-label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px; }
.sv-color-picker-wrap { display: flex; gap: 8px; align-items: center; }
.sv-color-input { width: 48px; height: 40px; padding: 2px; border: 1.5px solid #e5e7eb; border-radius: 6px; cursor: pointer; background: #f9fafb; flex-shrink: 0; }
.sv-color-hex { flex: 1; height: 40px; padding: 0 10px; font-size: 13px; font-family: monospace; border: 1.5px solid #e5e7eb; border-radius: 6px; background: #f9fafb; text-transform: uppercase; }
.sv-color-hex:focus { outline: none; border-color: var(--mw-primary-blue, #568184); background: #fff; }
.sv-color-preview { margin-top: 16px; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb; }
.sv-color-preview-header { padding: 14px; color: #fff; text-align: center; font-weight: 700; font-size: 14px; }
.sv-color-preview-body { padding: 16px; background: #f5f6fa; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.sv-color-preview-heading { font-weight: 700; font-size: 13px; }
.sv-color-preview-btn { display: inline-block; padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: default; }
.sv-color-preview-accent { display: inline-block; padding: 6px 14px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 2px solid; background: #fff; cursor: default; }

/* Danger zone */
.sv-danger-zone {
    border-top: 1px solid #fca5a5; padding-top: 16px; margin-top: 24px;
}
.sv-btn-danger {
    padding: 8px 16px; font-size: 13px; font-weight: 600;
    background: #fff; color: #dc2626; border: 1.5px solid #fca5a5; border-radius: 6px;
    cursor: pointer;
}
.sv-btn-danger:hover { background: #fef2f2; }

/* Toast */
.sv-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    padding: 12px 20px; border-radius: 8px;
    font-size: 14px; font-weight: 600; color: #fff;
    opacity: 0; transform: translateY(10px);
    transition: opacity 0.3s, transform 0.3s;
    pointer-events: none;
}
.sv-toast.show { opacity: 1; transform: translateY(0); }
.sv-toast-success { background: #059669; }
.sv-toast-error { background: #dc2626; }

/* Loading */
.sv-loading {
    text-align: center; padding: 40px; color: #9ca3af;
}

/* Modal overlay */
.sv-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 9000;
    background: rgba(0,0,0,0.4); align-items: center; justify-content: center;
}
.sv-modal-overlay.show { display: flex; }
.sv-modal {
    background: #fff; border-radius: 12px; padding: 24px; width: 90%; max-width: 520px;
    max-height: 80vh; overflow-y: auto; box-shadow: 0 8px 30px rgba(0,0,0,0.15);
}
.sv-modal-title {
    font-size: 16px; font-weight: 700; margin-bottom: 16px;
    color: var(--mw-text-heading, #1A2F33);
}
.sv-modal-actions {
    display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px;
}

@media (max-width: 768px) {
    .sv-form-row { flex-direction: column; }
    .sv-q-form-row { flex-direction: column; }
    .sv-card-actions { flex-wrap: wrap; }
    .sv-header-row { flex-direction: column; gap: 12px; align-items: flex-start; }
}
</style>

<div class="content-area">

    <!-- ===== 一覧ビュー ===== -->
    <div id="sv-list-section">
        <div class="sv-usage-bar" id="sv-usage-bar"></div>
        <div class="sv-header-row">
            <div style="font-size:13px;color:#6b7280;">作成したアンケートを管理できます。</div>
            <button type="button" class="sv-btn-new" id="sv-btn-new">+ 新規作成</button>
        </div>
        <div id="sv-list-container">
            <div class="sv-loading">読み込み中...</div>
        </div>
    </div>

    <!-- ===== 編集/作成ビュー ===== -->
    <div id="sv-edit-section" style="display:none;">
        <a class="sv-back-link" id="sv-back-link">&larr; 一覧に戻る</a>

        <!-- 基本情報 -->
        <div class="sv-form-card">
            <div class="sv-form-title">基本情報</div>
            <div id="sv-public-url-area" style="display:none;"></div>
            <input type="hidden" id="sv-edit-id" value="0">
            <div class="sv-form-group">
                <label class="sv-form-label">タイトル <span style="color:#dc2626;">*</span></label>
                <input type="text" class="sv-form-input" id="sv-title" placeholder="例：お客様アンケート">
            </div>
            <div class="sv-form-group">
                <label class="sv-form-label">説明文</label>
                <textarea class="sv-form-textarea" id="sv-description" placeholder="アンケートの説明（回答者には表示されません）"></textarea>
            </div>
            <div class="sv-form-row">
                <div class="sv-form-group">
                    <label class="sv-form-label">Google口コミ投稿URL</label>
                    <input type="url" class="sv-form-input" id="sv-google-url" placeholder="https://g.page/r/...">
                </div>
                <div class="sv-form-group" style="max-width:180px;">
                    <label class="sv-form-label">ステータス</label>
                    <select class="sv-form-select" id="sv-status">
                        <option value="draft">非公開</option>
                        <option value="published">公開</option>
                    </select>
                </div>
            </div>
            <div style="border-top:1px solid #e5e7eb; margin-top:20px; padding-top:20px;">
                <div style="font-size:14px; font-weight:700; color:#1A2F33; margin-bottom:12px;">口コミ生成 AI 設定</div>
                <div class="sv-form-group">
                    <label class="sv-form-label">口コミに含めるキーワード</label>
                    <div id="sv-keywords-container" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;"></div>
                    <div style="display:flex;gap:8px;">
                        <input type="text" class="sv-form-input" id="sv-keyword-input" placeholder="キーワードを入力してEnter" style="flex:1;">
                        <button type="button" class="sv-btn-secondary" id="sv-keyword-add" style="white-space:nowrap;">追加</button>
                    </div>
                    <p style="font-size:11px;color:#9ca3af;margin-top:4px;">口コミ文にできるだけ含めたい語句を登録してください（例：丁寧、安心、駅近）</p>
                </div>
                <div class="sv-form-group">
                    <label class="sv-form-label">追加プロンプト（口コミ生成時用）</label>
                    <textarea class="sv-form-textarea" id="sv-ai-extra-prompt" placeholder="例：文末は「ありがとうございました」で締めてください" style="min-height:60px;"></textarea>
                    <p style="font-size:11px;color:#9ca3af;margin-top:4px;">口コミ生成AIへの追加指示を自由に記述できます</p>
                </div>
            </div>
            <button type="button" class="sv-btn-save" id="sv-btn-save-info">保存する</button>
        </div>

        <!-- デザイン設定（カラーカスタマイズ） -->
        <div class="sv-form-card" id="sv-color-card" style="display:none;">
            <div class="sv-form-title">デザイン設定（フォームのカラー）</div>
            <p style="font-size:13px;color:#6b7280;margin-bottom:16px;">
                アンケートフォームの色をカスタマイズできます。この設定はアカウント全体のアンケートに適用されます。
            </p>
            <div class="sv-color-row">
                <div class="sv-color-group">
                    <label class="sv-color-label">ヘッダー背景色</label>
                    <div class="sv-color-picker-wrap">
                        <input type="color" class="sv-color-input" id="sv-color-header-bg" value="#2C3E50">
                        <input type="text" class="sv-color-hex" id="sv-hex-header-bg" value="#2C3E50" maxlength="7" placeholder="#2C3E50">
                    </div>
                </div>
                <div class="sv-color-group">
                    <label class="sv-color-label">見出し文字色</label>
                    <div class="sv-color-picker-wrap">
                        <input type="color" class="sv-color-input" id="sv-color-heading-text" value="#2C3E40">
                        <input type="text" class="sv-color-hex" id="sv-hex-heading-text" value="#2C3E40" maxlength="7" placeholder="#2C3E40">
                    </div>
                </div>
            </div>
            <div class="sv-color-row">
                <div class="sv-color-group">
                    <label class="sv-color-label">ボタン背景色</label>
                    <div class="sv-color-picker-wrap">
                        <input type="color" class="sv-color-input" id="sv-color-button-bg" value="#2C3E50">
                        <input type="text" class="sv-color-hex" id="sv-hex-button-bg" value="#2C3E50" maxlength="7" placeholder="#2C3E50">
                    </div>
                </div>
                <div class="sv-color-group">
                    <label class="sv-color-label">ボタン文字色</label>
                    <div class="sv-color-picker-wrap">
                        <input type="color" class="sv-color-input" id="sv-color-button-text" value="#FFFFFF">
                        <input type="text" class="sv-color-hex" id="sv-hex-button-text" value="#FFFFFF" maxlength="7" placeholder="#FFFFFF">
                    </div>
                </div>
            </div>
            <div class="sv-color-row">
                <div class="sv-color-group">
                    <label class="sv-color-label">アクセントカラー（選択肢・フォーカス等）</label>
                    <div class="sv-color-picker-wrap">
                        <input type="color" class="sv-color-input" id="sv-color-accent" value="#3b82f6">
                        <input type="text" class="sv-color-hex" id="sv-hex-accent" value="#3b82f6" maxlength="7" placeholder="#3b82f6">
                    </div>
                </div>
                <div class="sv-color-group">
                    <label class="sv-color-label">アクセント上の文字色</label>
                    <div class="sv-color-picker-wrap">
                        <input type="color" class="sv-color-input" id="sv-color-accent-text" value="#FFFFFF">
                        <input type="text" class="sv-color-hex" id="sv-hex-accent-text" value="#FFFFFF" maxlength="7" placeholder="#FFFFFF">
                    </div>
                </div>
            </div>
            <!-- ライブプレビュー -->
            <div class="sv-color-preview" id="sv-color-preview">
                <div class="sv-color-preview-header" id="sv-preview-header">サンプルヘッダー</div>
                <div class="sv-color-preview-body">
                    <span class="sv-color-preview-heading" id="sv-preview-heading">見出しサンプル</span>
                    <button class="sv-color-preview-btn" id="sv-preview-btn" type="button">ボタン</button>
                    <span class="sv-color-preview-accent" id="sv-preview-accent">アクセント</span>
                </div>
            </div>
            <div style="margin-top:16px;display:flex;gap:8px;">
                <button type="button" class="sv-btn-save" id="sv-btn-save-colors">カラー設定を保存</button>
                <button type="button" class="sv-btn-secondary" id="sv-btn-reset-colors">デフォルトに戻す</button>
            </div>
        </div>

        <!-- 質問管理 -->
        <div class="sv-form-card" id="sv-questions-card" style="display:none;">
            <div class="sv-form-title">質問一覧</div>
            <div id="sv-questions-container"></div>

            <!-- 質問追加フォーム -->
            <div class="sv-q-form" id="sv-q-add-form">
                <div class="sv-q-form-title" id="sv-q-form-title">質問を追加</div>
                <input type="hidden" id="sv-q-edit-id" value="0">
                <div class="sv-form-group">
                    <label class="sv-form-label">質問文 <span style="color:#dc2626;">*</span></label>
                    <input type="text" class="sv-form-input" id="sv-q-label" placeholder="例：利用前に困っていたこと">
                </div>
                <div class="sv-q-form-row">
                    <div class="sv-form-group">
                        <label class="sv-form-label">質問タイプ</label>
                        <select class="sv-form-select" id="sv-q-type">
                            <option value="checkbox">チェックボックス（複数選択）</option>
                            <option value="radio">ラジオボタン（単一選択）</option>
                            <option value="text">テキスト（短文・1行）</option>
                            <option value="textarea">テキストエリア（自由記述）</option>
                        </select>
                    </div>
                    <div class="sv-form-group" style="max-width:120px;">
                        <label class="sv-form-label">必須</label>
                        <select class="sv-form-select" id="sv-q-required">
                            <option value="1">必須</option>
                            <option value="0">任意</option>
                        </select>
                    </div>
                    <input type="hidden" id="sv-q-sort" value="0">
                </div>
                <div class="sv-form-group">
                    <label class="sv-form-label">補助説明</label>
                    <input type="text" class="sv-form-input" id="sv-q-description" placeholder="例：当てはまるものをすべて選択してください">
                </div>
                <div class="sv-form-group">
                    <label class="sv-form-label">プレースホルダー</label>
                    <input type="text" class="sv-form-input" id="sv-q-placeholder" placeholder="例：一言でも大丈夫です">
                </div>
                <div class="sv-form-group" id="sv-q-options-group">
                    <label class="sv-form-label">選択肢（1行に1つ）</label>
                    <textarea class="sv-form-textarea" id="sv-q-options" rows="4" placeholder="集客が伸び悩んでいた&#10;反応があるか不安だった&#10;その他"></textarea>
                </div>
                <div class="sv-q-form-actions">
                    <button type="button" class="sv-btn-save" id="sv-q-btn-save">追加する</button>
                    <button type="button" class="sv-btn-secondary" id="sv-q-btn-cancel" style="display:none;">キャンセル</button>
                </div>
            </div>
        </div>

        <!-- 削除ゾーン -->
        <div class="sv-form-card" id="sv-danger-card" style="display:none;">
            <div class="sv-danger-zone" style="border-top:none; padding-top:0; margin-top:0;">
                <button type="button" class="sv-btn-danger" id="sv-btn-delete-survey">このアンケートを削除する</button>
            </div>
        </div>
    </div>

</div>

<!-- Toast -->
<div class="sv-toast" id="sv-toast"></div>

<!-- Question edit modal -->
<div class="sv-modal-overlay" id="sv-q-modal">
    <div class="sv-modal">
        <div class="sv-modal-title">質問を編集</div>
        <div id="sv-q-modal-body"></div>
        <div class="sv-modal-actions">
            <button type="button" class="sv-btn-secondary" id="sv-q-modal-cancel">キャンセル</button>
            <button type="button" class="sv-btn-save" id="sv-q-modal-save">更新する</button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var API_BASE = <?php echo wp_json_encode(rest_url('gcrev/v1/survey/')); ?>;
    var WP_NONCE = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;

    // =====================================================
    // DOM
    // =====================================================
    var listSection = document.getElementById('sv-list-section');
    var editSection = document.getElementById('sv-edit-section');
    var listContainer = document.getElementById('sv-list-container');
    var usageBar = document.getElementById('sv-usage-bar');
    var btnNew = document.getElementById('sv-btn-new');
    var backLink = document.getElementById('sv-back-link');
    var toastEl = document.getElementById('sv-toast');

    var surveyData = { surveys: [], count: 0, limit: 3 };
    var currentSurveyId = 0;

    // =====================================================
    // API helpers
    // =====================================================
    function apiGet(path) {
        return fetch(API_BASE + path, {
            headers: { 'X-WP-Nonce': WP_NONCE },
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); });
    }

    function apiPost(path, body) {
        return fetch(API_BASE + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WP_NONCE },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(function(r) { return r.json(); });
    }

    function toast(msg, type) {
        toastEl.textContent = msg;
        toastEl.className = 'sv-toast sv-toast-' + (type || 'success') + ' show';
        setTimeout(function() { toastEl.classList.remove('show'); }, 3000);
    }

    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    // =====================================================
    // View switching
    // =====================================================
    function showList() {
        editSection.style.display = 'none';
        listSection.style.display = 'block';
        loadSurveyList();
    }

    function showEdit(id) {
        listSection.style.display = 'none';
        editSection.style.display = 'block';
        currentSurveyId = id || 0;
        if (id > 0) {
            loadSurveyDetail(id);
        } else {
            resetEditForm();
        }
        window.scrollTo({ top: 0 });
    }

    // =====================================================
    // List view
    // =====================================================
    function loadSurveyList() {
        listContainer.innerHTML = '<div class="sv-loading">読み込み中...</div>';
        apiGet('list').then(function(data) {
            surveyData = data;
            renderUsageBar(data);
            renderSurveyList(data);
        }).catch(function() {
            listContainer.innerHTML = '<div class="sv-empty"><div class="sv-empty-text">読み込みに失敗しました。</div></div>';
        });
    }

    function renderUsageBar(data) {
        var pct = Math.round((data.count / data.limit) * 100);
        var full = data.count >= data.limit;
        usageBar.innerHTML =
            '<span class="sv-usage-text">' + data.count + ' / ' + data.limit + ' アンケート</span>' +
            '<div class="sv-usage-meter"><div class="sv-usage-meter-fill' + (full ? ' full' : '') + '" style="width:' + pct + '%;"></div></div>' +
            (full ? '<span class="sv-usage-limit-msg">上限に達しています</span>' : '');
        btnNew.disabled = full;
        btnNew.title = full ? 'アンケートは最大' + data.limit + '件まで作成できます' : '';
    }

    function renderSurveyList(data) {
        if (!data.surveys || data.surveys.length === 0) {
            listContainer.innerHTML =
                '<div class="sv-empty">' +
                '<div class="sv-empty-icon">&#128203;</div>' +
                '<div class="sv-empty-text">アンケートがまだありません。<br>「新規作成」から最初のアンケートを作成しましょう。</div>' +
                '</div>';
            return;
        }

        var html = '';
        data.surveys.forEach(function(s) {
            var isPub = s.status === 'published';
            html += '<div class="sv-card">';
            html += '<div class="sv-card-header">';
            html += '<span class="sv-card-title">' + esc(s.title) + '</span>';
            html += '<span class="sv-badge ' + (isPub ? 'sv-badge-published' : 'sv-badge-draft') + '">' + (isPub ? '公開' : '非公開') + '</span>';
            html += '</div>';
            html += '<div class="sv-card-meta">';
            html += '<span>質問: ' + s.question_count + '件</span>';
            html += '<span>更新: ' + esc(s.updated_at ? s.updated_at.substring(0, 10) : '-') + '</span>';
            html += '</div>';
            html += '<div class="sv-card-actions">';
            html += '<button class="sv-btn-sm sv-btn-edit" data-id="' + s.id + '">編集</button>';
            if (isPub && s.public_url) {
                html += '<button class="sv-btn-sm sv-btn-copy-url" data-url="' + esc(s.public_url) + '">回答URLコピー</button>';
            }
            html += '<button class="sv-btn-sm sv-btn-delete" data-id="' + s.id + '" data-title="' + esc(s.title) + '">削除</button>';
            html += '</div>';
            html += '</div>';
        });
        listContainer.innerHTML = html;

        // Bind events
        listContainer.querySelectorAll('.sv-btn-edit').forEach(function(btn) {
            btn.addEventListener('click', function() { showEdit(parseInt(btn.dataset.id)); });
        });
        listContainer.querySelectorAll('.sv-btn-copy-url').forEach(function(btn) {
            btn.addEventListener('click', function() { copyToClipboard(btn, btn.dataset.url); });
        });
        listContainer.querySelectorAll('.sv-btn-delete').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('「' + btn.dataset.title + '」を削除しますか？\nこの操作は取り消せません。')) return;
                apiPost('delete', { id: parseInt(btn.dataset.id) }).then(function(res) {
                    if (res.success) { toast('削除しました'); loadSurveyList(); }
                    else { toast(res.message || '削除に失敗しました', 'error'); }
                });
            });
        });
    }

    function copyToClipboard(btn, text) {
        navigator.clipboard.writeText(text).then(function() {
            var orig = btn.textContent;
            btn.classList.add('copied');
            btn.textContent = 'コピーしました';
            setTimeout(function() { btn.classList.remove('copied'); btn.textContent = orig; }, 2000);
        });
    }

    // =====================================================
    // Edit view
    // =====================================================
    function resetEditForm() {
        document.getElementById('sv-edit-id').value = '0';
        document.getElementById('sv-title').value = '';
        document.getElementById('sv-description').value = '';
        document.getElementById('sv-google-url').value = '';
        document.getElementById('sv-status').value = 'draft';
        document.getElementById('sv-public-url-area').style.display = 'none';
        document.getElementById('sv-questions-card').style.display = 'none';
        document.getElementById('sv-danger-card').style.display = 'none';
        document.getElementById('sv-color-card').style.display = 'none';
        document.getElementById('sv-questions-container').innerHTML = '';
        document.getElementById('sv-ai-extra-prompt').value = '';
        if (typeof loadKeywords === 'function') loadKeywords('');
        resetQForm();
    }

    function loadSurveyDetail(id) {
        resetEditForm();
        document.getElementById('sv-edit-id').value = id;
        apiGet('detail?id=' + id).then(function(data) {
            if (!data.survey) { toast('読み込み失敗', 'error'); showList(); return; }
            var s = data.survey;
            document.getElementById('sv-title').value = s.title || '';
            document.getElementById('sv-description').value = s.description || '';
            document.getElementById('sv-google-url').value = s.google_review_url || '';
            document.getElementById('sv-status').value = s.status || 'draft';
            document.getElementById('sv-ai-extra-prompt').value = s.ai_extra_prompt || '';
            loadKeywords(s.ai_keywords || '');

            // Public URL
            if (data.public_url) {
                var area = document.getElementById('sv-public-url-area');
                area.style.display = 'block';
                area.innerHTML =
                    '<div class="sv-public-url">' +
                    '<input type="text" value="' + esc(data.public_url) + '" readonly>' +
                    '<button type="button" class="sv-public-url-copy" id="sv-copy-pub-url">コピー</button>' +
                    '</div>';
                document.getElementById('sv-copy-pub-url').addEventListener('click', function() {
                    copyToClipboard(this, data.public_url);
                });
            }

            // Questions
            document.getElementById('sv-questions-card').style.display = 'block';
            document.getElementById('sv-danger-card').style.display = 'block';
            renderQuestionTable(data.questions || []);

            // カラー設定
            document.getElementById('sv-color-card').style.display = 'block';
            loadSurveyColors();
        });
    }

    // =====================================================
    // Save survey info
    // =====================================================
    document.getElementById('sv-btn-save-info').addEventListener('click', function() {
        var btn = this;
        var title = document.getElementById('sv-title').value.trim();
        if (!title) { toast('タイトルを入力してください', 'error'); return; }

        btn.disabled = true;
        btn.textContent = '保存中...';

        var payload = {
            id: parseInt(document.getElementById('sv-edit-id').value) || 0,
            title: title,
            description: document.getElementById('sv-description').value.trim(),
            google_review_url: document.getElementById('sv-google-url').value.trim(),
            status: document.getElementById('sv-status').value,
            ai_keywords: getKeywordsString(),
            ai_extra_prompt: document.getElementById('sv-ai-extra-prompt').value.trim(),
        };

        apiPost('save', payload).then(function(res) {
            btn.disabled = false;
            btn.textContent = '保存する';
            if (res.success) {
                toast('保存しました');
                var newId = res.survey_id;
                document.getElementById('sv-edit-id').value = newId;
                currentSurveyId = newId;
                // Show questions card if newly created
                document.getElementById('sv-questions-card').style.display = 'block';
                document.getElementById('sv-danger-card').style.display = 'block';
                // Reload to get token/url
                loadSurveyDetail(newId);
            } else {
                toast(res.message || '保存に失敗しました', 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.textContent = '保存する';
            toast('保存に失敗しました', 'error');
        });
    });

    // =====================================================
    // Question table
    // =====================================================
    function renderQuestionTable(questions) {
        var container = document.getElementById('sv-questions-container');
        if (!questions || questions.length === 0) {
            container.innerHTML = '<div class="sv-q-empty">まだ質問がありません。下のフォームから追加してください。</div>';
            return;
        }

        var typeLabels = { checkbox: 'チェック', radio: 'ラジオ', textarea: 'テキスト', text: 'テキスト(1行)', select: 'セレクト' };
        var html = '<p style="font-size:12px;color:#9ca3af;margin-bottom:6px;">💡 行をドラッグして並び順を変更できます<span class="sv-q-sort-status" id="sv-sort-status"></span></p>';
        html += '<table class="sv-q-table"><thead><tr>';
        html += '<th style="width:36px;"></th><th style="width:36px;">No.</th><th>質問文</th><th style="width:80px;">タイプ</th><th style="width:50px;">必須</th><th style="width:110px;"></th>';
        html += '</tr></thead><tbody id="sv-q-sortable">';

        questions.forEach(function(q, idx) {
            html += '<tr draggable="true" data-qid="' + q.id + '">';
            html += '<td><span class="sv-q-drag-handle" title="ドラッグで並び替え">☰</span></td>';
            html += '<td style="font-weight:600;color:#6b7280;">' + (idx + 1) + '</td>';
            html += '<td>' + esc(q.label) + '</td>';
            html += '<td><span class="sv-q-type-badge">' + (typeLabels[q.type] || q.type) + '</span></td>';
            html += '<td>' + (q.required ? '<span class="sv-q-required">必須</span>' : '<span class="sv-q-optional">任意</span>') + '</td>';
            html += '<td class="sv-q-actions">';
            html += '<button class="sv-q-btn sv-q-btn-edit" data-qid="' + q.id + '">編集</button>';
            html += '<button class="sv-q-btn sv-q-btn-del" data-qid="' + q.id + '">削除</button>';
            html += '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;

        // Init drag & drop
        initQuestionDragSort();

        // Store for edit modal
        container._questions = questions;

        // Events
        container.querySelectorAll('.sv-q-btn-edit').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var qid = parseInt(btn.dataset.qid);
                var q = questions.find(function(x) { return x.id === qid; });
                if (q) openQuestionEditModal(q);
            });
        });
        container.querySelectorAll('.sv-q-btn-del').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('この質問を削除しますか？')) return;
                apiPost('question/delete', {
                    survey_id: currentSurveyId,
                    question_id: parseInt(btn.dataset.qid)
                }).then(function(res) {
                    if (res.success) { toast('質問を削除しました'); loadSurveyDetail(currentSurveyId); }
                    else { toast(res.message || '削除に失敗しました', 'error'); }
                });
            });
        });
    }

    // =====================================================
    // Question drag & drop sorting
    // =====================================================
    function initQuestionDragSort() {
        var tbody = document.getElementById('sv-q-sortable');
        if (!tbody) return;

        var dragRow = null;

        tbody.addEventListener('dragstart', function(e) {
            var tr = e.target.closest('tr');
            if (!tr) return;
            dragRow = tr;
            tr.style.opacity = '0.4';
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
        });

        tbody.addEventListener('dragend', function() {
            if (dragRow) dragRow.style.opacity = '';
            dragRow = null;
            tbody.querySelectorAll('.sv-drag-over').forEach(function(r) {
                r.classList.remove('sv-drag-over');
            });
        });

        tbody.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var tr = e.target.closest('tr');
            if (!tr || tr === dragRow) return;
            tbody.querySelectorAll('.sv-drag-over').forEach(function(r) {
                r.classList.remove('sv-drag-over');
            });
            tr.classList.add('sv-drag-over');
        });

        tbody.addEventListener('drop', function(e) {
            e.preventDefault();
            var tr = e.target.closest('tr');
            if (!tr || !dragRow || tr === dragRow) return;
            tr.classList.remove('sv-drag-over');

            var rows = Array.from(tbody.querySelectorAll('tr'));
            var dragIdx = rows.indexOf(dragRow);
            var dropIdx = rows.indexOf(tr);
            if (dragIdx < dropIdx) {
                tr.parentNode.insertBefore(dragRow, tr.nextSibling);
            } else {
                tr.parentNode.insertBefore(dragRow, tr);
            }

            saveSortOrder();
        });
    }

    function saveSortOrder() {
        var tbody = document.getElementById('sv-q-sortable');
        var status = document.getElementById('sv-sort-status');
        if (!tbody) return;

        if (status) { status.textContent = '保存中...'; status.className = 'sv-q-sort-status saving'; }

        var rows = tbody.querySelectorAll('tr[data-qid]');
        var order = [];
        rows.forEach(function(row, idx) {
            order.push({ id: parseInt(row.getAttribute('data-qid'), 10), sort_order: (idx + 1) * 10 });
        });

        apiPost('question/reorder', { survey_id: currentSurveyId, order: order })
        .then(function(data) {
            if (status) {
                if (data.success) {
                    status.textContent = '✓ 保存しました';
                    status.className = 'sv-q-sort-status saved';
                } else {
                    status.textContent = '⚠ 保存失敗';
                    status.className = 'sv-q-sort-status';
                    status.style.color = '#dc2626';
                }
                setTimeout(function() { status.textContent = ''; }, 2000);
            }
        })
        .catch(function() {
            if (status) {
                status.textContent = '⚠ 通信エラー';
                status.className = 'sv-q-sort-status';
                status.style.color = '#dc2626';
                setTimeout(function() { status.textContent = ''; }, 2000);
            }
        });
    }

    // =====================================================
    // Question add form
    // =====================================================
    var qTypeSelect = document.getElementById('sv-q-type');
    var qOptionsGroup = document.getElementById('sv-q-options-group');

    function toggleOptionsField() {
        var t = qTypeSelect.value;
        qOptionsGroup.style.display = (t === 'checkbox' || t === 'radio') ? 'block' : 'none';
    }
    qTypeSelect.addEventListener('change', toggleOptionsField);
    toggleOptionsField();

    function resetQForm() {
        document.getElementById('sv-q-edit-id').value = '0';
        document.getElementById('sv-q-label').value = '';
        qTypeSelect.value = 'checkbox';
        document.getElementById('sv-q-required').value = '1';
        // 新規追加時はリストの末尾に配置されるよう max sort_order + 10 をセット
        var maxSort = 0;
        var qContainer = document.getElementById('sv-questions-container');
        if (qContainer && qContainer._questions) {
            qContainer._questions.forEach(function(q) {
                if (q.sort_order > maxSort) maxSort = q.sort_order;
            });
        }
        document.getElementById('sv-q-sort').value = String(maxSort + 10);
        document.getElementById('sv-q-description').value = '';
        document.getElementById('sv-q-placeholder').value = '';
        document.getElementById('sv-q-options').value = '';
        document.getElementById('sv-q-form-title').textContent = '質問を追加';
        document.getElementById('sv-q-btn-save').textContent = '追加する';
        document.getElementById('sv-q-btn-cancel').style.display = 'none';
        toggleOptionsField();
    }

    document.getElementById('sv-q-btn-save').addEventListener('click', function() {
        var label = document.getElementById('sv-q-label').value.trim();
        if (!label) { toast('質問文を入力してください', 'error'); return; }

        var type = qTypeSelect.value;
        var optionsText = document.getElementById('sv-q-options').value.trim();
        var options = [];
        if ((type === 'checkbox' || type === 'radio') && optionsText) {
            options = optionsText.split('\n').map(function(s) { return s.trim(); }).filter(Boolean);
        }

        var payload = {
            survey_id: currentSurveyId,
            id: parseInt(document.getElementById('sv-q-edit-id').value) || 0,
            label: label,
            type: type,
            required: document.getElementById('sv-q-required').value === '1',
            sort_order: parseInt(document.getElementById('sv-q-sort').value) || 0,
            description: document.getElementById('sv-q-description').value.trim(),
            placeholder: document.getElementById('sv-q-placeholder').value.trim(),
            options: options,
            is_active: true,
        };

        apiPost('question/save', payload).then(function(res) {
            if (res.success) {
                toast('質問を保存しました');
                resetQForm();
                loadSurveyDetail(currentSurveyId);
            } else {
                toast(res.message || '保存に失敗しました', 'error');
            }
        });
    });

    document.getElementById('sv-q-btn-cancel').addEventListener('click', resetQForm);

    // =====================================================
    // Question edit modal
    // =====================================================
    var modalOverlay = document.getElementById('sv-q-modal');
    var modalBody = document.getElementById('sv-q-modal-body');

    function openQuestionEditModal(q) {
        var typeLabels = { checkbox: 'チェックボックス（複数選択）', radio: 'ラジオボタン（単一選択）', text: 'テキスト（短文・1行）', textarea: 'テキストエリア（自由記述）' };
        var optionsVal = (q.options || []).join('\n');
        var showOpts = (q.type === 'checkbox' || q.type === 'radio');

        modalBody.innerHTML =
            '<input type="hidden" id="sv-qm-id" value="' + q.id + '">' +
            '<div class="sv-form-group"><label class="sv-form-label">質問文</label><input type="text" class="sv-form-input" id="sv-qm-label" value="' + esc(q.label) + '"></div>' +
            '<div class="sv-q-form-row">' +
            '<div class="sv-form-group"><label class="sv-form-label">タイプ</label><select class="sv-form-select" id="sv-qm-type">' +
            '<option value="checkbox"' + (q.type === 'checkbox' ? ' selected' : '') + '>' + (typeLabels.checkbox) + '</option>' +
            '<option value="radio"' + (q.type === 'radio' ? ' selected' : '') + '>' + (typeLabels.radio) + '</option>' +
            '<option value="text"' + (q.type === 'text' ? ' selected' : '') + '>' + (typeLabels.text) + '</option>' +
            '<option value="textarea"' + (q.type === 'textarea' ? ' selected' : '') + '>' + (typeLabels.textarea) + '</option>' +
            '</select></div>' +
            '<div class="sv-form-group" style="max-width:100px;"><label class="sv-form-label">必須</label><select class="sv-form-select" id="sv-qm-required"><option value="1"' + (q.required ? ' selected' : '') + '>必須</option><option value="0"' + (!q.required ? ' selected' : '') + '>任意</option></select></div>' +
            '<input type="hidden" id="sv-qm-sort" value="' + q.sort_order + '">' +
            '</div>' +
            '<div class="sv-form-group"><label class="sv-form-label">補助説明</label><input type="text" class="sv-form-input" id="sv-qm-desc" value="' + esc(q.description) + '"></div>' +
            '<div class="sv-form-group"><label class="sv-form-label">プレースホルダー</label><input type="text" class="sv-form-input" id="sv-qm-ph" value="' + esc(q.placeholder) + '"></div>' +
            '<div class="sv-form-group" id="sv-qm-opts-group" style="display:' + (showOpts ? 'block' : 'none') + ';"><label class="sv-form-label">選択肢（1行に1つ）</label><textarea class="sv-form-textarea" id="sv-qm-opts" rows="4">' + esc(optionsVal) + '</textarea></div>';

        // Toggle options on type change
        var qmType = document.getElementById('sv-qm-type');
        qmType.addEventListener('change', function() {
            document.getElementById('sv-qm-opts-group').style.display =
                (qmType.value === 'checkbox' || qmType.value === 'radio') ? 'block' : 'none';
        });

        modalOverlay.classList.add('show');
    }

    document.getElementById('sv-q-modal-cancel').addEventListener('click', function() {
        modalOverlay.classList.remove('show');
    });
    modalOverlay.addEventListener('click', function(e) {
        if (e.target === modalOverlay) modalOverlay.classList.remove('show');
    });

    document.getElementById('sv-q-modal-save').addEventListener('click', function() {
        var label = document.getElementById('sv-qm-label').value.trim();
        if (!label) { toast('質問文を入力してください', 'error'); return; }

        var type = document.getElementById('sv-qm-type').value;
        var optsText = document.getElementById('sv-qm-opts').value.trim();
        var options = [];
        if ((type === 'checkbox' || type === 'radio') && optsText) {
            options = optsText.split('\n').map(function(s) { return s.trim(); }).filter(Boolean);
        }

        apiPost('question/save', {
            survey_id: currentSurveyId,
            id: parseInt(document.getElementById('sv-qm-id').value),
            label: label,
            type: type,
            required: document.getElementById('sv-qm-required').value === '1',
            sort_order: parseInt(document.getElementById('sv-qm-sort').value) || 0,
            description: document.getElementById('sv-qm-desc').value.trim(),
            placeholder: document.getElementById('sv-qm-ph').value.trim(),
            options: options,
            is_active: true,
        }).then(function(res) {
            if (res.success) {
                toast('質問を更新しました');
                modalOverlay.classList.remove('show');
                loadSurveyDetail(currentSurveyId);
            } else {
                toast(res.message || '更新に失敗しました', 'error');
            }
        });
    });

    // =====================================================
    // キーワード管理
    // =====================================================
    var keywordsContainer = document.getElementById('sv-keywords-container');
    var keywordInput = document.getElementById('sv-keyword-input');
    var currentKeywords = [];

    function renderKeywords() {
        keywordsContainer.innerHTML = '';
        currentKeywords.forEach(function(kw, i) {
            var tag = document.createElement('span');
            tag.className = 'sv-keyword-tag';
            tag.innerHTML = esc(kw) + '<button type="button" class="sv-keyword-remove" data-idx="' + i + '">&times;</button>';
            keywordsContainer.appendChild(tag);
        });
        keywordsContainer.querySelectorAll('.sv-keyword-remove').forEach(function(btn) {
            btn.addEventListener('click', function() {
                currentKeywords.splice(parseInt(this.dataset.idx), 1);
                renderKeywords();
            });
        });
    }

    function addKeyword() {
        var v = keywordInput.value.trim();
        if (!v) return;
        if (currentKeywords.indexOf(v) === -1) {
            currentKeywords.push(v);
            renderKeywords();
        }
        keywordInput.value = '';
    }

    function loadKeywords(str) {
        currentKeywords = str ? str.split('\n').filter(function(s) { return s.trim() !== ''; }) : [];
        renderKeywords();
    }

    function getKeywordsString() {
        return currentKeywords.join('\n');
    }

    document.getElementById('sv-keyword-add').addEventListener('click', addKeyword);
    keywordInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); addKeyword(); }
    });

    // =====================================================
    // Delete survey
    // =====================================================
    document.getElementById('sv-btn-delete-survey').addEventListener('click', function() {
        var title = document.getElementById('sv-title').value.trim() || 'このアンケート';
        if (!confirm('「' + title + '」を削除しますか？\nこの操作は取り消せません。')) return;

        apiPost('delete', { id: currentSurveyId }).then(function(res) {
            if (res.success) { toast('削除しました'); showList(); }
            else { toast(res.message || '削除に失敗しました', 'error'); }
        });
    });

    // =====================================================
    // カラーカスタマイズ
    // =====================================================
    var colorDefaults = {
        header_bg: '#2C3E50', heading_text: '#2C3E40',
        button_bg: '#2C3E50', button_text: '#FFFFFF',
        accent: '#3b82f6', accent_text: '#FFFFFF'
    };
    var colorKeys = Object.keys(colorDefaults);

    function colorEl(key)  { return document.getElementById('sv-color-' + key.replace(/_/g, '-')); }
    function hexEl(key)    { return document.getElementById('sv-hex-' + key.replace(/_/g, '-')); }
    function isValidHex(v) { return /^#[0-9A-Fa-f]{6}$/.test(v); }

    // 両方の入力を同じ値にセット
    function setColor(key, val) {
        var c = colorEl(key), h = hexEl(key);
        if (c) c.value = val;
        if (h) h.value = val.toUpperCase();
    }

    function updateColorPreview() {
        var g = function(key) { return colorEl(key) ? colorEl(key).value : colorDefaults[key]; };
        document.getElementById('sv-preview-header').style.background = g('header_bg');
        document.getElementById('sv-preview-heading').style.color = g('heading_text');
        document.getElementById('sv-preview-btn').style.background = g('button_bg');
        document.getElementById('sv-preview-btn').style.color = g('button_text');
        document.getElementById('sv-preview-accent').style.borderColor = g('accent');
        document.getElementById('sv-preview-accent').style.color = g('accent');
    }

    function loadSurveyColors() {
        apiGet('colors').then(function(data) {
            if (!data.success) return;
            var c = data.colors || {};
            colorKeys.forEach(function(key) { if (c[key]) setColor(key, c[key]); });
            updateColorPreview();
        });
    }

    // カラーピッカー → hex入力に同期 + プレビュー更新
    colorKeys.forEach(function(key) {
        var picker = colorEl(key), hex = hexEl(key);
        if (picker) picker.addEventListener('input', function() {
            if (hex) hex.value = picker.value.toUpperCase();
            updateColorPreview();
        });
        // hex入力 → カラーピッカーに同期 + プレビュー更新
        if (hex) hex.addEventListener('input', function() {
            var v = hex.value.trim();
            if (v.charAt(0) !== '#') v = '#' + v;
            hex.value = v;
            if (isValidHex(v)) {
                if (picker) picker.value = v;
                updateColorPreview();
            }
        });
        // hex入力からフォーカスが外れた時に値を正規化
        if (hex) hex.addEventListener('blur', function() {
            var v = hex.value.trim();
            if (v.charAt(0) !== '#') v = '#' + v;
            if (!isValidHex(v)) v = picker ? picker.value : colorDefaults[key];
            setColor(key, v);
            updateColorPreview();
        });
    });

    // 保存
    document.getElementById('sv-btn-save-colors').addEventListener('click', function() {
        var btn = this;
        var colors = {};
        colorKeys.forEach(function(key) {
            var c = colorEl(key);
            if (c) colors[key] = c.value;
        });
        btn.disabled = true;
        btn.textContent = '保存中...';
        apiPost('colors', { colors: colors }).then(function(res) {
            btn.disabled = false;
            btn.textContent = 'カラー設定を保存';
            if (res.success) toast('カラー設定を保存しました');
            else toast('保存に失敗しました', 'error');
        });
    });

    // デフォルトに戻す
    document.getElementById('sv-btn-reset-colors').addEventListener('click', function() {
        if (!confirm('カラー設定をデフォルトに戻しますか？')) return;
        colorKeys.forEach(function(key) { setColor(key, colorDefaults[key]); });
        updateColorPreview();
        var btn = document.getElementById('sv-btn-save-colors');
        btn.disabled = true;
        btn.textContent = '保存中...';
        apiPost('colors', { colors: colorDefaults }).then(function(res) {
            btn.disabled = false;
            btn.textContent = 'カラー設定を保存';
            if (res.success) toast('デフォルトに戻しました');
        });
    });

    // =====================================================
    // Navigation events
    // =====================================================
    btnNew.addEventListener('click', function() { if (!btnNew.disabled) showEdit(0); });
    backLink.addEventListener('click', function() { showList(); });

    // =====================================================
    // Init
    // =====================================================
    loadSurveyList();
})();
</script>

<?php get_footer(); ?>
