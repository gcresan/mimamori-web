<?php
/*
Template Name: 口コミ用アンケート作成
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

mimamori_guard_meo_access();

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
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    padding: 16px 20px; margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.sv-usage-main { flex: 1; min-width: 180px; display: flex; flex-direction: column; gap: 2px; }
.sv-usage-remaining {
    font-size: 16px; font-weight: 700;
    color: var(--mw-text-primary, #263335);
}
.sv-usage-bar.full .sv-usage-remaining { color: #dc2626; }
.sv-usage-sub {
    font-size: 12px; color: #6b7280;
}
.sv-usage-slots { display: flex; gap: 6px; }
.sv-slot {
    width: 28px; height: 28px; border-radius: 6px;
    border: 2px solid #e5e7eb; background: #f9fafb;
    transition: background 0.2s, border-color 0.2s;
}
.sv-slot.filled {
    background: var(--mw-primary-blue, #568184);
    border-color: var(--mw-primary-blue, #568184);
}
.sv-usage-bar.full .sv-slot.filled {
    background: #dc2626; border-color: #dc2626;
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
    position: fixed; top: 24px; left: 50%; z-index: 100000;
    padding: 14px 28px; border-radius: 10px;
    font-size: 15px; font-weight: 700; color: #fff;
    min-width: 240px; max-width: 90vw; text-align: center;
    box-shadow: 0 8px 24px rgba(0,0,0,0.18);
    opacity: 0; transform: translate(-50%, -16px);
    transition: opacity 0.25s ease, transform 0.25s ease;
    pointer-events: none;
    white-space: pre;
}
.sv-toast.show { opacity: 1; transform: translate(-50%, 0); }
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
                <textarea class="sv-form-textarea" id="sv-description" placeholder="アンケートの説明"></textarea>
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
                    <label class="sv-form-label" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                        <span>追加プロンプト（口コミ生成時用）</span>
                        <button type="button" class="sv-btn-ai-gen" id="sv-btn-extra-prompt-gen" style="font-size:12px; padding:6px 12px;">🤖 参考口コミから生成</button>
                    </label>
                    <textarea class="sv-form-textarea" id="sv-ai-extra-prompt" placeholder="例：文末は「ありがとうございました」で締めてください" style="min-height:80px;"></textarea>
                    <p style="font-size:11px;color:#9ca3af;margin-top:4px;">口コミ生成AIへの追加指示を自由に記述できます。実際の口コミをいくつかお持ちなら「参考口コミから生成」で AI が書き方のクセを抽出してくれます。</p>
                </div>
                <div class="sv-form-group">
                    <label class="sv-form-label">参考口コミサンプル（最大10件登録 / 生成時に使うものを選択）</label>
                    <div id="sv-ref-reviews-slots"><!-- JS で動的追加 --></div>
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-top:6px; gap:10px; flex-wrap:wrap;">
                        <button type="button" class="sv-btn-secondary" id="sv-btn-add-ref-review" style="font-size:12px;">＋ 口コミを追加</button>
                        <span id="sv-ref-reviews-count" style="font-size:12px; color:#6b7280;">登録 0 / 10 件　参考 0 件</span>
                    </div>
                    <p style="font-size:11px;color:#9ca3af;margin-top:4px;">登録した口コミのうち「生成時に参考にする」にチェックしたものだけが、口コミ生成時の文体参考として AI に渡されます（固有名詞や具体的な数値は引用禁止の指示付き）。「参考口コミから生成」で入力した口コミは自動でここに反映されます。</p>
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
            <div class="sv-form-title" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <span>質問一覧</span>
                <button type="button" class="sv-btn-ai-gen" id="sv-btn-ai-generate" title="クライアント情報をもとに口コミアンケート30問を自動生成します">
                    🤖 AIで30問生成
                </button>
            </div>
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

<!-- 追加プロンプト AI生成モーダル -->
<div class="sv-modal-overlay" id="sv-extra-prompt-modal">
    <div class="sv-modal sv-modal-wide">
        <div class="sv-modal-title">🤖 参考口コミから追加プロンプトを生成</div>

        <!-- 入力ステップ -->
        <div id="sv-ep-step-input">
            <p style="color:#555; font-size:13px; margin:0 0 12px;">
                実際に集まった口コミを1件ずつ登録してください。<br>
                AIが「書き方のクセ・長さ・雰囲気」を分析し、既存プロンプトに追加する形式のルールを生成します。<br>
                <strong>同業他社の口コミでも構いません。複数件（3件以上推奨）あるほど精度が上がります。最大10件まで登録できます。</strong><br>
                <span style="color:#2271b1;">※ すでに保存されている参考口コミサンプルが自動で入力欄に読み込まれます。編集・追加・削除してから「生成する」を押してください。</span>
            </p>
            <div class="sv-form-group">
                <label class="sv-form-label">参考口コミサンプル <span style="color:#dc2626;">*</span></label>
                <div id="sv-ep-slots"><!-- JS で動的に slot を追加 --></div>
                <div style="display:flex; align-items:center; justify-content:space-between; margin-top:8px;">
                    <button type="button" class="sv-btn-secondary" id="sv-ep-btn-add-slot">＋ 口コミを追加</button>
                    <span id="sv-ep-slots-count" style="font-size:12px; color:#6b7280;">0 / 10 件</span>
                </div>
                <p style="font-size:11px;color:#9ca3af;margin-top:6px;">目安: 1件あたり 50〜300字。登録は最大10件まで。</p>
            </div>
        </div>

        <!-- 生成中 -->
        <div id="sv-ep-step-loading" style="display:none; text-align:center; padding:30px 0;">
            <div class="sv-ai-spinner"></div>
            <p style="margin:14px 0 0; color:#555;">AIが口コミの傾向を分析しています…（30秒〜1分）</p>
        </div>

        <!-- プレビュー -->
        <div id="sv-ep-step-preview" style="display:none;">
            <div class="sv-ai-intent-box" id="sv-ep-summary"></div>

            <details style="margin:10px 0;">
                <summary style="cursor:pointer; color:#555; font-size:13px;">▼ 特徴分析（参考情報）</summary>
                <pre id="sv-ep-analysis" style="background:#f6f7f7; padding:10px; border-radius:4px; font-size:12px; white-space:pre-wrap; max-height:220px; overflow:auto;"></pre>
            </details>

            <div style="margin:14px 0 8px;">
                <strong style="font-size:13px;">生成された追加プロンプト（このまま反映できます）:</strong>
            </div>
            <textarea id="sv-ep-output" readonly rows="12"
                style="width:100%; font-family:inherit; font-size:13px; line-height:1.6; padding:10px; border:1px solid #ddd; border-radius:6px; background:#fafafa;"></textarea>
        </div>

        <div class="sv-modal-actions">
            <button type="button" class="sv-btn-secondary" id="sv-ep-btn-close">閉じる</button>
            <button type="button" class="sv-btn-save" id="sv-ep-btn-generate">生成する</button>
            <button type="button" class="sv-btn-secondary" id="sv-ep-btn-append" style="display:none;">追記</button>
            <button type="button" class="sv-btn-save" id="sv-ep-btn-replace" style="display:none;">上書きで反映</button>
        </div>
    </div>
</div>

<!-- AI 生成モーダル -->
<div class="sv-modal-overlay" id="sv-ai-modal">
    <div class="sv-modal sv-modal-wide">
        <div class="sv-modal-title">🤖 AIで口コミアンケート30問を生成</div>

        <!-- 入力ステップ -->
        <div id="sv-ai-step-input">
            <p style="color:#555; font-size:13px; margin:0 0 16px;">
                クライアント設定の「クライアント情報」に保存された内容が自動で入ります。<br>
                このアンケート1回だけ変更したい場合は下で上書きできます（保存内容は変わりません）。
            </p>
            <div class="sv-form-group">
                <label class="sv-form-label">業種 <span style="color:#dc2626;">*</span></label>
                <input type="text" class="sv-form-input" id="sv-ai-industry" placeholder="例: 医療・ヘルスケア / 歯科医院">
            </div>
            <div class="sv-form-group">
                <label class="sv-form-label">サービス内容</label>
                <textarea class="sv-form-textarea" id="sv-ai-service" rows="2" placeholder="例: 駅前で25年続く地域密着の歯科医院。一般歯科から予防歯科まで対応。"></textarea>
            </div>
            <div class="sv-form-group">
                <label class="sv-form-label">ターゲット</label>
                <textarea class="sv-form-textarea" id="sv-ai-target" rows="2" placeholder="例: 共働きで忙しく、週末にまとめて情報収集する30〜40代夫婦"></textarea>
            </div>
            <div class="sv-form-group">
                <label class="sv-form-label">強み・特徴（1行に1つ）</label>
                <textarea class="sv-form-textarea" id="sv-ai-strengths" rows="3" placeholder="例:&#10;痛みの少ない治療&#10;丁寧なカウンセリング&#10;駅から徒歩2分"></textarea>
            </div>
            <div class="sv-form-group">
                <label class="sv-form-label">口コミで特に引き出したい内容</label>
                <textarea class="sv-form-textarea" id="sv-ai-emphasis" rows="2" placeholder="例: スタッフの丁寧さ、治療前後の変化、通いやすさ"></textarea>
            </div>
        </div>

        <!-- 生成中 -->
        <div id="sv-ai-step-loading" style="display:none; text-align:center; padding:40px 0;">
            <div class="sv-ai-spinner"></div>
            <p style="margin:16px 0 0; color:#555;">AIが30問のアンケートを作成しています…（30秒〜1分）</p>
        </div>

        <!-- プレビュー -->
        <div id="sv-ai-step-preview" style="display:none;">
            <div id="sv-ai-design-intent" class="sv-ai-intent-box"></div>
            <p style="color:#555; font-size:13px; margin:8px 0;">
                チェックされている質問が一括追加されます（既存質問の後ろに追加されます）。不要な質問はチェックを外してください。
                <label style="display:inline-block; margin-left:12px; cursor:pointer;">
                    <input type="checkbox" id="sv-ai-select-all" checked> 全選択
                </label>
            </p>
            <div id="sv-ai-preview-list" class="sv-ai-preview-list"></div>
        </div>

        <div class="sv-modal-actions">
            <button type="button" class="sv-btn-secondary" id="sv-ai-btn-close">閉じる</button>
            <button type="button" class="sv-btn-save" id="sv-ai-btn-generate">生成する</button>
            <button type="button" class="sv-btn-save" id="sv-ai-btn-save" style="display:none;">選択した質問を一括追加</button>
        </div>
    </div>
</div>

<style>
.sv-btn-ai-gen {
    background: linear-gradient(135deg, #7c3aed 0%, #2271b1 100%);
    color: #fff; border: none; padding: 8px 16px;
    border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600;
    transition: opacity 0.2s;
}
.sv-btn-ai-gen:hover { opacity: 0.9; }
.sv-btn-ai-gen:disabled { opacity: 0.5; cursor: not-allowed; }
.sv-modal-wide { max-width: 780px; }
.sv-ai-spinner {
    display: inline-block; width: 40px; height: 40px;
    border: 4px solid #e5e5e5; border-top-color: #2271b1;
    border-radius: 50%; animation: sv-spin 1s linear infinite;
}
@keyframes sv-spin { to { transform: rotate(360deg); } }
.sv-ai-intent-box {
    background: #f0f9ff; border-left: 3px solid #2271b1;
    padding: 10px 14px; border-radius: 4px; margin-bottom: 14px;
    font-size: 13px; color: #333; line-height: 1.6;
}
.sv-ai-preview-list {
    max-height: 420px; overflow-y: auto; border: 1px solid #ddd;
    border-radius: 6px; padding: 8px;
}
.sv-ai-cat-header {
    background: #f6f7f7; padding: 6px 10px; margin: 8px 0 4px;
    font-size: 12px; font-weight: 600; color: #555; border-radius: 4px;
}
.sv-ai-cat-header:first-child { margin-top: 0; }
.sv-ai-q-row {
    display: flex; align-items: flex-start; gap: 8px; padding: 6px 4px;
    border-bottom: 1px dashed #e5e5e5;
}
.sv-ai-q-row:last-child { border-bottom: none; }
.sv-ai-q-body { flex: 1; font-size: 13px; line-height: 1.5; }
.sv-ai-q-body .sv-ai-label { font-weight: 500; }
.sv-ai-q-body .sv-ai-type { font-size: 11px; color: #777; margin-right: 8px; }
.sv-ai-q-body .sv-ai-fixed { font-size: 10px; color: #fff; background: #dc2626; padding: 1px 6px; border-radius: 3px; margin-left: 6px; vertical-align: middle; }
.sv-ai-q-body .sv-ai-opts { font-size: 11px; color: #666; margin-top: 2px; }
</style>

<script>
(function() {
    'use strict';

    var API_BASE = <?php echo wp_json_encode(rest_url('gcrev/v1/survey/')); ?>;
    var WP_NONCE = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
    // クライアント情報（AI生成モーダルの初期値）
    <?php
    $_sv_client = function_exists( 'gcrev_get_client_settings' ) ? gcrev_get_client_settings() : [];
    $_sv_ai_defaults = [
        'industry'            => (string) ( $_sv_client['industry'] ?? ( $_sv_client['industry_detail'] ?? '' ) ),
        'service_description' => (string) ( $_sv_client['service_description'] ?? '' ),
        'strengths'           => (string) ( $_sv_client['strengths'] ?? '' ),
        'review_emphasis'     => (string) ( $_sv_client['review_emphasis'] ?? '' ),
        'target'              => (string) ( $_sv_client['persona_one_liner'] ?? '' ),
    ];
    ?>
    var AI_DEFAULTS = <?php echo wp_json_encode( $_sv_ai_defaults, JSON_UNESCAPED_UNICODE ); ?>;

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
        var t = (type === 'error') ? 'error' : 'success';
        var icon = (t === 'error') ? '⚠ ' : '✓ ';
        toastEl.textContent = icon + msg;
        toastEl.className = 'sv-toast sv-toast-' + t + ' show';
        if (window._svToastTimer) { clearTimeout(window._svToastTimer); }
        window._svToastTimer = setTimeout(function() { toastEl.classList.remove('show'); }, 4000);
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
        var used  = Math.max(0, parseInt(data.count, 10) || 0);
        var limit = Math.max(1, parseInt(data.limit, 10) || 1);
        if (used > limit) used = limit;
        var remaining = limit - used;
        var full = (remaining <= 0);

        var slotsHtml = '';
        for (var i = 0; i < limit; i++) {
            slotsHtml += '<span class="sv-slot ' + (i < used ? 'filled' : 'empty') + '"></span>';
        }

        var mainText = full
            ? '上限に達しています'
            : 'あと ' + remaining + ' 件作成できます';
        var subText = full
            ? '作成済み ' + used + ' / 最大 ' + limit + ' 件（新規作成するには既存のアンケートを削除してください）'
            : '作成済み ' + used + ' / 最大 ' + limit + ' 件';

        usageBar.className = 'sv-usage-bar' + (full ? ' full' : '');
        usageBar.innerHTML =
            '<div class="sv-usage-main">' +
              '<strong class="sv-usage-remaining">' + mainText + '</strong>' +
              '<span class="sv-usage-sub">' + subText + '</span>' +
            '</div>' +
            '<div class="sv-usage-slots">' + slotsHtml + '</div>';

        btnNew.disabled = full;
        btnNew.title = full ? 'アンケートは最大' + limit + '件まで作成できます' : '';
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
        if (typeof setReferenceReviews === 'function') setReferenceReviews([]);
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
            setReferenceReviews(Array.isArray(s.ai_reference_reviews) ? s.ai_reference_reviews : []);

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
            ai_reference_reviews: getReferenceReviews(),
        };

        // デバッグ: 送信する payload のサイズを記録
        try {
            var payloadStr = JSON.stringify(payload);
            console.log('[survey-save] payload size=' + payloadStr.length + ' bytes, refs=' + (payload.ai_reference_reviews || []).length);
        } catch (e) { /* ignore */ }

        var resetBtn = function() {
            btn.disabled = false;
            btn.textContent = '保存する';
        };

        // タイムアウト（60秒）: サーバーが応答しない場合もトーストを出す
        var timeoutId = setTimeout(function() {
            console.warn('[survey-save] timeout after 60s');
            resetBtn();
            toast('保存に時間がかかりすぎています。ネットワークまたはサーバーを確認してください。', 'error');
        }, 60000);

        apiPost('save', payload).then(function(res) {
            clearTimeout(timeoutId);
            resetBtn();
            console.log('[survey-save] response', res);
            if (res && res.success) {
                toast('保存しました');
                var newId = res.survey_id;
                document.getElementById('sv-edit-id').value = newId;
                currentSurveyId = newId;
                document.getElementById('sv-questions-card').style.display = 'block';
                document.getElementById('sv-danger-card').style.display = 'block';
                loadSurveyDetail(newId);
            } else {
                toast((res && res.message) ? res.message : '保存に失敗しました', 'error');
            }
        }).catch(function(e) {
            clearTimeout(timeoutId);
            resetBtn();
            console.error('[survey-save] error', e);
            toast('保存に失敗しました: ' + (e && e.message ? e.message : '通信エラー'), 'error');
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
    // 参考口コミサンプル（最大10件登録 / 生成時に使うものを選択）
    // =====================================================
    var REF_MAX = 10;
    var refSlotsContainer = document.getElementById('sv-ref-reviews-slots');
    var refCountEl = document.getElementById('sv-ref-reviews-count');
    var btnAddRef = document.getElementById('sv-btn-add-ref-review');

    function refSlotCount() {
        return refSlotsContainer ? refSlotsContainer.querySelectorAll('.sv-ref-slot').length : 0;
    }

    function refActiveCount() {
        if (!refSlotsContainer) return 0;
        var n = 0;
        refSlotsContainer.querySelectorAll('.sv-ref-slot').forEach(function(slot) {
            var ta = slot.querySelector('.sv-ref-slot-input');
            var cb = slot.querySelector('.sv-ref-slot-active');
            if (ta && ta.value.trim() !== '' && cb && cb.checked) n++;
        });
        return n;
    }

    function renumberRefSlots() {
        if (!refSlotsContainer) return;
        var slots = refSlotsContainer.querySelectorAll('.sv-ref-slot');
        slots.forEach(function(slot, i) {
            var label = slot.querySelector('.sv-ref-slot-label');
            if (label) label.textContent = 'サンプル ' + (i + 1);
        });
        if (refCountEl) {
            refCountEl.textContent = '登録 ' + slots.length + ' / ' + REF_MAX + ' 件　参考 ' + refActiveCount() + ' 件';
        }
        if (btnAddRef) {
            var isMax = (slots.length >= REF_MAX);
            btnAddRef.disabled = isMax;
            btnAddRef.style.display = isMax ? 'none' : '';
        }
    }

    function createRefSlot(value, active) {
        if (!refSlotsContainer) return null;
        if (refSlotCount() >= REF_MAX) return null;
        var isActive = (active === undefined) ? true : !!active;
        var wrap = document.createElement('div');
        wrap.className = 'sv-ref-slot';
        wrap.style.cssText = 'border:1px solid #e5e7eb; border-radius:6px; padding:8px 10px; margin-bottom:6px; background:#fff;';
        wrap.innerHTML =
            '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; gap:10px; flex-wrap:wrap;">' +
            '  <strong class="sv-ref-slot-label" style="font-size:12px; color:#374151;">サンプル</strong>' +
            '  <div style="display:flex; align-items:center; gap:12px;">' +
            '    <label style="font-size:12px; color:#374151; cursor:pointer; user-select:none; display:inline-flex; align-items:center; gap:4px;">' +
            '      <input type="checkbox" class="sv-ref-slot-active"' + (isActive ? ' checked' : '') + '> 生成時に参考にする' +
            '    </label>' +
            '    <button type="button" class="sv-ref-slot-remove" style="background:none; border:none; color:#dc2626; font-size:12px; cursor:pointer;">× 削除</button>' +
            '  </div>' +
            '</div>' +
            '<textarea class="sv-form-textarea sv-ref-slot-input" rows="3" maxlength="600" placeholder="例：初めて利用しました。説明がわかりやすく、不安だった部分もきちんと確認してもらえたので安心できました。仕上がりも納得で、また機会があればお願いしたいです。" style="min-height:70px;"></textarea>';
        if (typeof value === 'string') {
            wrap.querySelector('.sv-ref-slot-input').value = value;
        }
        wrap.querySelector('.sv-ref-slot-remove').addEventListener('click', function() {
            wrap.remove();
            renumberRefSlots();
        });
        wrap.querySelector('.sv-ref-slot-active').addEventListener('change', renumberRefSlots);
        wrap.querySelector('.sv-ref-slot-input').addEventListener('input', renumberRefSlots);
        refSlotsContainer.appendChild(wrap);
        renumberRefSlots();
        return wrap;
    }

    /**
     * 保存データのロード。
     * @param {Array<string|{text:string,active:boolean}>} arr
     */
    function setReferenceReviews(arr) {
        if (!refSlotsContainer) return;
        refSlotsContainer.innerHTML = '';
        if (Array.isArray(arr)) {
            arr.slice(0, REF_MAX).forEach(function(item) {
                if (typeof item === 'string') {
                    if (item.trim() !== '') createRefSlot(item, true);
                } else if (item && typeof item.text === 'string' && item.text.trim() !== '') {
                    createRefSlot(item.text, item.active !== false);
                }
            });
        }
        renumberRefSlots();
    }

    /**
     * 保存用データの収集。
     * @returns {Array<{text:string, active:boolean}>}
     */
    function getReferenceReviews() {
        if (!refSlotsContainer) return [];
        var out = [];
        refSlotsContainer.querySelectorAll('.sv-ref-slot').forEach(function(slot) {
            var ta = slot.querySelector('.sv-ref-slot-input');
            var cb = slot.querySelector('.sv-ref-slot-active');
            if (!ta) return;
            var v = ta.value.trim();
            if (v === '') return;
            out.push({ text: v, active: cb ? cb.checked : true });
        });
        return out.slice(0, REF_MAX);
    }

    // クロージャ外からも参照できるよう保持（モーダルからは同一 IIFE 内でそのまま参照可能）
    window.__svSetReferenceReviews = setReferenceReviews;
    window.__svGetReferenceReviews = getReferenceReviews;

    if (btnAddRef) {
        btnAddRef.addEventListener('click', function() {
            var slot = createRefSlot('', true);
            if (slot) {
                var input = slot.querySelector('.sv-ref-slot-input');
                if (input) input.focus();
            }
        });
    }

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
    // AI 30問生成モーダル
    // =====================================================
    var aiModal       = document.getElementById('sv-ai-modal');
    var aiStepInput   = document.getElementById('sv-ai-step-input');
    var aiStepLoading = document.getElementById('sv-ai-step-loading');
    var aiStepPreview = document.getElementById('sv-ai-step-preview');
    var aiBtnGenerate = document.getElementById('sv-ai-btn-generate');
    var aiBtnSave     = document.getElementById('sv-ai-btn-save');
    var aiBtnClose    = document.getElementById('sv-ai-btn-close');
    var aiPreviewList = document.getElementById('sv-ai-preview-list');
    var aiDesignIntent = document.getElementById('sv-ai-design-intent');
    var aiSelectAll   = document.getElementById('sv-ai-select-all');

    var aiGeneratedQuestions = [];

    function setAiStep(step) {
        aiStepInput.style.display   = (step === 'input')   ? 'block' : 'none';
        aiStepLoading.style.display = (step === 'loading') ? 'block' : 'none';
        aiStepPreview.style.display = (step === 'preview') ? 'block' : 'none';

        aiBtnGenerate.style.display = (step === 'input')   ? 'inline-block' : 'none';
        aiBtnSave.style.display     = (step === 'preview') ? 'inline-block' : 'none';
        aiBtnClose.textContent      = (step === 'preview') ? '閉じる' : 'キャンセル';
    }

    function prefillAiInputs() {
        // PHP 側で注入したクライアント情報を初期値として反映（既に入力がある場合は上書きしない）
        var inputs = {
            'sv-ai-industry':  AI_DEFAULTS.industry,
            'sv-ai-service':   AI_DEFAULTS.service_description,
            'sv-ai-target':    AI_DEFAULTS.target,
            'sv-ai-strengths': AI_DEFAULTS.strengths,
            'sv-ai-emphasis':  AI_DEFAULTS.review_emphasis
        };
        Object.keys(inputs).forEach(function(id) {
            var el = document.getElementById(id);
            if (el && !el.value) { el.value = inputs[id] || ''; }
        });
    }

    function openAiModal() {
        setAiStep('input');
        prefillAiInputs();
        aiModal.classList.add('show');
    }

    function closeAiModal() {
        aiModal.classList.remove('show');
        // プレビューは都度作り直す
        aiGeneratedQuestions = [];
        aiPreviewList.innerHTML = '';
        aiDesignIntent.textContent = '';
        setAiStep('input');
    }

    document.getElementById('sv-btn-ai-generate').addEventListener('click', function() {
        if (!currentSurveyId) {
            toast('先にアンケートを保存してください', 'error');
            return;
        }
        openAiModal();
    });

    aiBtnClose.addEventListener('click', closeAiModal);
    aiModal.addEventListener('click', function(e) {
        if (e.target === aiModal) closeAiModal();
    });

    aiBtnGenerate.addEventListener('click', function() {
        var industry = document.getElementById('sv-ai-industry').value.trim();
        if (!industry) {
            toast('業種を入力してください', 'error');
            return;
        }

        var body = {
            industry:            industry,
            service_description: document.getElementById('sv-ai-service').value.trim(),
            target:              document.getElementById('sv-ai-target').value.trim(),
            strengths:           document.getElementById('sv-ai-strengths').value.trim(),
            review_emphasis:     document.getElementById('sv-ai-emphasis').value.trim(),
        };

        setAiStep('loading');

        apiPost('ai-generate-questions', body).then(function(res) {
            if (res.success && Array.isArray(res.questions) && res.questions.length > 0) {
                aiGeneratedQuestions = res.questions;
                aiDesignIntent.textContent = res.design_intent || '';
                renderAiPreview(res.questions);
                setAiStep('preview');
            } else {
                toast(res.message || '生成に失敗しました', 'error');
                setAiStep('input');
            }
        }).catch(function(e) {
            toast('通信エラー: ' + e.message, 'error');
            setAiStep('input');
        });
    });

    function renderAiPreview(questions) {
        aiPreviewList.innerHTML = '';
        var currentCat = null;
        questions.forEach(function(q, i) {
            if (q.category !== currentCat) {
                currentCat = q.category;
                var header = document.createElement('div');
                header.className = 'sv-ai-cat-header';
                header.textContent = currentCat || 'その他';
                aiPreviewList.appendChild(header);
            }

            var row = document.createElement('label');
            row.className = 'sv-ai-q-row';

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = true;
            cb.dataset.idx = i;
            cb.className = 'sv-ai-q-check';

            var body = document.createElement('div');
            body.className = 'sv-ai-q-body';
            var typeLabel = { textarea: '自由記述', radio: '単一選択', checkbox: '複数選択', text: '短文' }[q.type] || q.type;

            var html = '<span class="sv-ai-type">[' + esc(typeLabel) + ']</span>';
            html += '<span class="sv-ai-label">' + esc(q.label) + '</span>';
            if (q.is_fixed) {
                html += '<span class="sv-ai-fixed">固定</span>';
            }
            if (q.options && q.options.length > 0) {
                html += '<div class="sv-ai-opts">選択肢: ' + q.options.map(esc).join(' / ') + '</div>';
            }
            body.innerHTML = html;

            row.appendChild(cb);
            row.appendChild(body);
            aiPreviewList.appendChild(row);
        });
    }

    aiSelectAll.addEventListener('change', function() {
        var checked = aiSelectAll.checked;
        aiPreviewList.querySelectorAll('.sv-ai-q-check').forEach(function(cb) {
            cb.checked = checked;
        });
    });

    aiBtnSave.addEventListener('click', function() {
        var checked = aiPreviewList.querySelectorAll('.sv-ai-q-check:checked');
        if (checked.length === 0) {
            toast('保存する質問が選択されていません', 'error');
            return;
        }
        var selected = [];
        checked.forEach(function(cb) {
            var idx = parseInt(cb.dataset.idx);
            if (aiGeneratedQuestions[idx]) {
                selected.push(aiGeneratedQuestions[idx]);
            }
        });

        aiBtnSave.disabled = true;
        aiBtnSave.textContent = '追加中...';

        apiPost('questions/bulk-save', {
            survey_id: currentSurveyId,
            questions: selected
        }).then(function(res) {
            aiBtnSave.disabled = false;
            aiBtnSave.textContent = '選択した質問を一括追加';
            if (res.success) {
                toast(res.message || (selected.length + '問を追加しました'));
                closeAiModal();
                loadSurveyDetail(currentSurveyId);
            } else {
                toast(res.message || '追加に失敗しました', 'error');
            }
        }).catch(function(e) {
            aiBtnSave.disabled = false;
            aiBtnSave.textContent = '選択した質問を一括追加';
            toast('通信エラー: ' + e.message, 'error');
        });
    });

    // =====================================================
    // 追加プロンプトを参考口コミから生成するモーダル
    // =====================================================
    (function() {
        var btn = document.getElementById('sv-btn-extra-prompt-gen');
        if (!btn) return;

        var MAX_SLOTS = 10;
        var INITIAL_SLOTS = 3;

        var modal       = document.getElementById('sv-extra-prompt-modal');
        var stepInput   = document.getElementById('sv-ep-step-input');
        var stepLoading = document.getElementById('sv-ep-step-loading');
        var stepPreview = document.getElementById('sv-ep-step-preview');
        var slotsContainer = document.getElementById('sv-ep-slots');
        var slotsCountEl   = document.getElementById('sv-ep-slots-count');
        var btnAddSlot  = document.getElementById('sv-ep-btn-add-slot');
        var summaryBox  = document.getElementById('sv-ep-summary');
        var analysisBox = document.getElementById('sv-ep-analysis');
        var outputBox   = document.getElementById('sv-ep-output');
        var btnGen      = document.getElementById('sv-ep-btn-generate');
        var btnClose    = document.getElementById('sv-ep-btn-close');
        var btnAppend   = document.getElementById('sv-ep-btn-append');
        var btnReplace  = document.getElementById('sv-ep-btn-replace');
        var extraPromptField = document.getElementById('sv-ai-extra-prompt');

        function slotCount() {
            return slotsContainer.querySelectorAll('.sv-ep-slot').length;
        }

        function renumberSlots() {
            var slots = slotsContainer.querySelectorAll('.sv-ep-slot');
            slots.forEach(function(slot, i) {
                var label = slot.querySelector('.sv-ep-slot-label');
                if (label) label.textContent = '口コミ ' + (i + 1);
            });
            slotsCountEl.textContent = slots.length + ' / ' + MAX_SLOTS + ' 件';
            btnAddSlot.disabled = (slots.length >= MAX_SLOTS);
            // 最後の1件は削除不可（最低1件は残す）
            slots.forEach(function(slot) {
                var rm = slot.querySelector('.sv-ep-slot-remove');
                if (rm) rm.disabled = (slots.length <= 1);
            });
        }

        function createSlot(value) {
            if (slotCount() >= MAX_SLOTS) return null;
            var wrap = document.createElement('div');
            wrap.className = 'sv-ep-slot';
            wrap.style.cssText = 'border:1px solid #e5e7eb; border-radius:6px; padding:10px; margin-bottom:8px; background:#fff;';
            wrap.innerHTML =
                '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">' +
                '  <strong class="sv-ep-slot-label" style="font-size:13px; color:#374151;">口コミ</strong>' +
                '  <button type="button" class="sv-ep-slot-remove" style="background:none; border:none; color:#dc2626; font-size:13px; cursor:pointer;">× 削除</button>' +
                '</div>' +
                '<textarea class="sv-form-textarea sv-ep-slot-input" rows="4" maxlength="600" placeholder="例：初めて利用しました。説明がわかりやすく、不安だった部分もきちんと確認してもらえたので安心できました。仕上がりも納得で、また機会があればお願いしたいです。" style="min-height:90px;"></textarea>';
            if (typeof value === 'string') {
                wrap.querySelector('.sv-ep-slot-input').value = value;
            }
            wrap.querySelector('.sv-ep-slot-remove').addEventListener('click', function() {
                if (slotCount() <= 1) { return; }
                wrap.remove();
                renumberSlots();
            });
            slotsContainer.appendChild(wrap);
            renumberSlots();
            return wrap;
        }

        function resetSlots() {
            slotsContainer.innerHTML = '';
            // 保存済みの参考口コミサンプルを初期値として引き継ぐ
            var saved = (typeof getReferenceReviews === 'function') ? getReferenceReviews() : [];
            if (saved && saved.length > 0) {
                saved.forEach(function(item) {
                    var text = item && typeof item.text === 'string' ? item.text : (typeof item === 'string' ? item : '');
                    if (text) { createSlot(text); }
                });
            } else {
                // 何も保存されていない場合は3件の空スロットから始める
                for (var i = 0; i < INITIAL_SLOTS; i++) { createSlot(''); }
            }
        }

        function collectReviews() {
            var out = [];
            slotsContainer.querySelectorAll('.sv-ep-slot-input').forEach(function(ta) {
                var v = ta.value.trim();
                if (v !== '') out.push(v);
            });
            return out;
        }

        btnAddSlot.addEventListener('click', function() {
            var slot = createSlot('');
            if (slot) {
                var input = slot.querySelector('.sv-ep-slot-input');
                if (input) input.focus();
            }
        });

        function setStep(step) {
            stepInput.style.display   = (step === 'input')   ? 'block' : 'none';
            stepLoading.style.display = (step === 'loading') ? 'block' : 'none';
            stepPreview.style.display = (step === 'preview') ? 'block' : 'none';

            btnGen.style.display     = (step === 'input')   ? 'inline-block' : 'none';
            btnAppend.style.display  = (step === 'preview') ? 'inline-block' : 'none';
            btnReplace.style.display = (step === 'preview') ? 'inline-block' : 'none';
            btnClose.textContent = (step === 'preview') ? '閉じる' : 'キャンセル';
        }

        function closeModal() {
            modal.classList.remove('show');
            setStep('input');
        }

        btn.addEventListener('click', function() {
            resetSlots();
            setStep('input');
            modal.classList.add('show');
        });

        btnClose.addEventListener('click', closeModal);
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });

        btnGen.addEventListener('click', function() {
            var reviews = collectReviews();
            if (reviews.length === 0) {
                toast('参考口コミを1件以上入力してください。', 'error');
                return;
            }
            var combined = reviews.join('\n\n');
            if (combined.length < 40) {
                toast('参考口コミが短すぎます。もう少し詳しく、または件数を増やしてください。', 'error');
                return;
            }

            setStep('loading');

            apiPost('generate-extra-prompt', { reviews: combined }).then(function(res) {
                if (res.success && res.extra_prompt) {
                    summaryBox.textContent = res.summary || '';
                    analysisBox.textContent = res.analysis || '（分析データなし）';
                    outputBox.value = res.extra_prompt;
                    setStep('preview');
                } else {
                    toast(res.message || '生成に失敗しました', 'error');
                    setStep('input');
                }
            }).catch(function(e) {
                toast('通信エラー: ' + e.message, 'error');
                setStep('input');
            });
        });

        function syncReferenceReviewsFromModal(mode) {
            // mode: 'replace' = スロットを入力内容で丸ごと置換 / 'append' = 既存スロットに追加（計10件上限）
            if (typeof getReferenceReviews !== 'function' || typeof setReferenceReviews !== 'function') return;
            var modalTexts = collectReviews().slice(0, 10);
            if (modalTexts.length === 0) return;

            // モーダル入力は「生成時に参考にする=ON」で反映
            var modalObjs = modalTexts.map(function(t) { return { text: t, active: true }; });

            if (mode === 'replace') {
                setReferenceReviews(modalObjs);
                return;
            }

            // append: 既存 + モーダル入力、テキスト重複排除、10件上限
            var current = getReferenceReviews();
            var existingTexts = current.map(function(o) { return o.text; });
            var merged = current.slice();
            modalObjs.forEach(function(obj) {
                if (merged.length >= 10) return;
                if (existingTexts.indexOf(obj.text) === -1) {
                    merged.push(obj);
                    existingTexts.push(obj.text);
                }
            });
            setReferenceReviews(merged);
        }

        btnReplace.addEventListener('click', function() {
            if (!extraPromptField) return;
            if (extraPromptField.value.trim() !== '' &&
                !confirm('既存の追加プロンプトと参考口コミサンプルを上書きします。よろしいですか？')) return;
            extraPromptField.value = outputBox.value;
            syncReferenceReviewsFromModal('replace');
            toast('追加プロンプト＋参考口コミサンプルに反映しました。「保存する」ボタンで確定してください。');
            closeModal();
        });

        btnAppend.addEventListener('click', function() {
            if (!extraPromptField) return;
            var current = extraPromptField.value.trim();
            extraPromptField.value = current === ''
                ? outputBox.value
                : current + '\n\n' + outputBox.value;
            syncReferenceReviewsFromModal('append');
            toast('追加プロンプトに追記＋参考口コミサンプルを追加しました。「保存する」ボタンで確定してください。');
            closeModal();
        });
    })();

    // =====================================================
    // Init
    // =====================================================
    loadSurveyList();
})();
</script>

<?php get_footer(); ?>
