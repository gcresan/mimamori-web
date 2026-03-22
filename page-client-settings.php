<?php
/*
Template Name: クライアント設定
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// ページタイトル
set_query_var( 'gcrev_page_title', 'クライアント設定' );
set_query_var( 'gcrev_page_subtitle', 'AIレポートやAI相談で使用する、クライアントの基本情報を設定します。' );

// パンくず
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'クライアント設定', '各種設定' ) );

// 現在の設定を取得
$settings = gcrev_get_client_settings( $user_id );

// Clarity 連携設定
$clarity_settings = class_exists( 'Gcrev_Clarity_Client' ) ? Gcrev_Clarity_Client::get_settings( $user_id ) : [];
$clarity_sync     = class_exists( 'Gcrev_Clarity_Client' ) ? Gcrev_Clarity_Client::get_sync_summary( $user_id ) : [];

// 旧データフォールバック: report_target から商圏の初期値を推定
$legacy_target = get_user_meta( $user_id, 'report_target', true );
$has_new_settings = ! empty( $settings['area_type'] );

// 旧サイトURL からの初期値（WP-Members → report_site_url → gcrev_client_site_url）
$initial_site_url = $settings['site_url'];
if ( empty( $initial_site_url ) ) {
    $initial_site_url = get_user_meta( $user_id, 'weisite_url', true ) ?: '';
}

// Maps（GBP）用ドメイン
$maps_domain = get_user_meta( $user_id, '_gcrev_maps_domain', true ) ?: '';

// 解析対象URL条件
$include_paths_raw = get_user_meta( $user_id, '_gcrev_include_paths', true );
$include_paths_text = '';
if ( is_array( $include_paths_raw ) && ! empty( $include_paths_raw ) ) {
    $include_paths_text = implode( "\n", $include_paths_raw );
}

// 解析除外URL条件
$exclude_paths_raw = get_user_meta( $user_id, '_gcrev_exclude_paths', true );
$exclude_paths_text = '';
if ( is_array( $exclude_paths_raw ) && ! empty( $exclude_paths_raw ) ) {
    $exclude_paths_text = implode( "\n", $exclude_paths_raw );
}

get_header();
?>

<style>
/* page-client-settings — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */

/* 親コンテナ（settings-card）をカード風から透明なラッパーに変更 */
.content-area .settings-card {
    background: transparent;
    border: none;
    box-shadow: none;
    padding: 0;
    border-radius: 0;
}

/* カード化：各セクションを独立したカードとして表示 */
.cs-section {
    margin-bottom: 20px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 24px 28px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.cs-section-title {
    font-size: 16px; font-weight: 700; color: #1e293b;
    margin: 0 0 20px; padding-bottom: 12px; border-bottom: 2px solid #e9ecef;
    display: flex; align-items: center; gap: 8px;
}
.cs-section-title .icon { font-size: 18px; }

/* 商圏タイプ */
.area-type-options { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
.area-type-option {
    display: flex; align-items: flex-start; gap: 8px;
    padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px;
    cursor: pointer; transition: border-color .2s, background .2s;
}
.area-type-option:hover { border-color: #94a3b8; }
.area-type-option.selected { border-color: #4E8A6B; background: #f0fdf4; }
.area-type-option input[type="radio"] { margin-top: 2px; accent-color: #4E8A6B; }
.area-type-option label { cursor: pointer; font-size: 14px; line-height: 1.5; }
.area-type-option label strong { display: block; font-size: 14px; }
.area-type-option label span { font-size: 12px; color: #64748b; }

/* 商圏サブフィールド */
.area-sub-fields { margin-top: 12px; }
.area-sub-field { display: none; margin-bottom: 12px; }
.area-sub-field.visible { display: block; }
.area-sub-field label { display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 4px; }
.area-sub-field select,
.area-sub-field input,
.area-sub-field textarea {
    width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 14px; line-height: 1.5; transition: border-color .2s;
}
.area-sub-field select:focus,
.area-sub-field input:focus,
.area-sub-field textarea:focus {
    outline: none; border-color: #4E8A6B; box-shadow: 0 0 0 3px rgba(78,138,107,.12);
}

/* 業種・業態セレクト */
.industry-group { margin-bottom: 16px; }
.industry-group label {
    display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 4px;
}
.industry-group select,
.industry-group input[type="text"] {
    width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 14px; line-height: 1.5; transition: border-color .2s;
    background: #fff;
}
.industry-group select:focus,
.industry-group input[type="text"]:focus {
    outline: none; border-color: #4E8A6B; box-shadow: 0 0 0 3px rgba(78,138,107,.12);
}
.industry-group select:disabled {
    background: #f1f5f9; color: #94a3b8; cursor: not-allowed;
}

/* 業態チェックボックスグリッド */
.subcategory-grid {
    display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;
    min-height: 36px; padding: 8px; border: 1px solid #e2e8f0; border-radius: 8px;
    background: #fafafa;
}
.subcategory-grid.disabled { background: #f1f5f9; pointer-events: none; opacity: .5; }
.subcategory-grid .subcategory-item {
    display: flex; align-items: center; gap: 4px;
    padding: 4px 10px; border: 1px solid #e2e8f0; border-radius: 16px;
    cursor: pointer; font-size: 13px; transition: all .2s; user-select: none;
}
.subcategory-grid .subcategory-item:hover { border-color: #94a3b8; background: #f8fafc; }
.subcategory-grid .subcategory-item.checked {
    border-color: #4E8A6B; background: #f0fdf4; color: #166534;
}
.subcategory-grid .subcategory-item input[type="checkbox"] {
    accent-color: #4E8A6B; width: 14px; height: 14px; margin: 0;
}
.subcategory-placeholder {
    color: #94a3b8; font-size: 13px; padding: 4px 0;
}

/* ビジネス形態 */
.btype-options { display: flex; flex-wrap: wrap; gap: 8px; }
.btype-option {
    display: flex; align-items: center; gap: 6px;
    padding: 6px 14px; border: 1px solid #e2e8f0; border-radius: 20px;
    cursor: pointer; font-size: 13px; transition: all .2s;
}
.btype-option:hover { border-color: #94a3b8; }
.btype-option.selected { border-color: #4E8A6B; background: #f0fdf4; color: #166534; }
.btype-option input[type="radio"] { accent-color: #4E8A6B; }

/* 保存ボタン — カード群の外に独立配置 */
.cs-actions {
    margin-top: 8px; padding: 20px 0; display: flex; gap: 12px;
    justify-content: center;
}
.cs-actions .btn-save {
    padding: 12px 48px; background: #4E8A6B; color: #fff; border: none;
    border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer;
    transition: background .2s; box-shadow: 0 2px 6px rgba(78,138,107,0.25);
}
.cs-actions .btn-save:hover { background: #2d6b54; }
.cs-actions .btn-save:disabled { background: #94a3b8; cursor: not-allowed; box-shadow: none; }

/* トースト */
.cs-toast {
    position: fixed; top: 20px; right: 20px; z-index: 10000;
    background: #166534; color: #fff; padding: 12px 20px;
    border-radius: 8px; font-size: 14px; box-shadow: 0 4px 12px rgba(0,0,0,.15);
    opacity: 0; transform: translateY(-10px); transition: all .3s;
    pointer-events: none;
}
.cs-toast.show { opacity: 1; transform: translateY(0); pointer-events: auto; }

/* ===== ペルソナセクション ===== */
.persona-grid {
    display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;
    padding: 8px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fafafa;
}
.persona-grid .subcategory-item {
    display: flex; align-items: center; gap: 4px;
    padding: 4px 10px; border: 1px solid #e2e8f0; border-radius: 16px;
    cursor: pointer; font-size: 13px; transition: all .2s; user-select: none;
}
.persona-grid .subcategory-item:hover { border-color: #94a3b8; background: #f8fafc; }
.persona-grid .subcategory-item.checked { border-color: #4E8A6B; background: #f0fdf4; color: #166534; }
.persona-grid .subcategory-item input[type="checkbox"] { accent-color: #4E8A6B; width: 14px; height: 14px; margin: 0; }
.persona-section-desc { font-size: 13px; color: #64748b; margin: -8px 0 16px; }

/* 参考URL */
.ref-url-list { display: flex; flex-direction: column; gap: 8px; }
.ref-url-row {
    display: flex; gap: 8px; align-items: center;
}
.ref-url-row input[type="url"] { flex: 1; min-width: 0; }
.ref-url-row input[type="text"] { flex: 0.7; min-width: 0; }
.ref-url-row input {
    padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; transition: border-color .2s;
}
.ref-url-row input:focus { outline: none; border-color: #4E8A6B; box-shadow: 0 0 0 3px rgba(78,138,107,.12); }
.ref-url-remove {
    width: 28px; height: 28px; border: none; background: #fee2e2; color: #dc2626;
    border-radius: 50%; cursor: pointer; font-size: 16px; line-height: 1;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    transition: background .2s;
}
.ref-url-remove:hover { background: #fca5a5; }
.btn-add-ref-url {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 6px 14px; border: 1px dashed #94a3b8; border-radius: 6px;
    background: transparent; color: #475569; font-size: 13px; cursor: pointer;
    transition: all .2s; margin-top: 4px;
}
.btn-add-ref-url:hover { border-color: #4E8A6B; color: #4E8A6B; }

/* AI生成ボタン */
.btn-generate-persona {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 18px; border: 1px solid #4E8A6B; border-radius: 8px;
    background: #f0fdf4; color: #166534; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .2s; margin-top: 8px;
}
.btn-generate-persona:hover { background: #dcfce7; }

/* ペルソナ生成モーダル */
.persona-gen-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,.45); z-index: 10001;
    display: flex; align-items: center; justify-content: center;
}
.persona-gen-modal {
    background: #fff; border-radius: 12px; width: 92%; max-width: 640px;
    max-height: 85vh; display: flex; flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
}
.persona-gen-modal header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
}
.persona-gen-modal header h3 { margin: 0; font-size: 16px; font-weight: 700; color: #1e293b; }
.persona-gen-modal .modal-close {
    width: 32px; height: 32px; border: none; background: #f1f5f9;
    border-radius: 50%; cursor: pointer; font-size: 18px; color: #64748b;
    display: flex; align-items: center; justify-content: center;
}
.persona-gen-modal .modal-close:hover { background: #e2e8f0; }
.persona-gen-body { padding: 20px; overflow-y: auto; flex: 1; }
.persona-gen-context {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: 12px 16px; margin-bottom: 16px; font-size: 13px; color: #475569;
}
.persona-gen-context dt { font-weight: 600; color: #334155; margin-top: 6px; }
.persona-gen-context dt:first-child { margin-top: 0; }
.persona-gen-context dd { margin: 2px 0 0 0; }
.persona-gen-extra { margin-bottom: 16px; }
.persona-gen-extra label {
    display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 4px;
}
.persona-gen-extra input,
.persona-gen-extra textarea {
    width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; margin-bottom: 8px; box-sizing: border-box;
}
.persona-gen-extra input:focus,
.persona-gen-extra textarea:focus {
    outline: none; border-color: #4E8A6B; box-shadow: 0 0 0 3px rgba(78,138,107,.12);
}
.persona-gen-actions {
    display: flex; gap: 8px; margin-bottom: 16px;
}
.btn-persona-gen {
    padding: 9px 20px; background: #4E8A6B; color: #fff; border: none;
    border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;
    transition: background .2s; display: inline-flex; align-items: center; gap: 6px;
}
.btn-persona-gen:hover { background: #2d6b54; }
.btn-persona-gen:disabled { background: #94a3b8; cursor: not-allowed; }
.persona-gen-preview {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: 16px; font-size: 13px; line-height: 1.8; color: #334155;
    white-space: pre-wrap; max-height: 320px; overflow-y: auto;
    display: none;
}
.persona-gen-apply {
    display: none; gap: 8px; margin-top: 12px;
}
.persona-gen-apply.visible { display: flex; }
.btn-apply-persona {
    padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .2s;
}
.btn-apply-overwrite { background: #4E8A6B; color: #fff; border: none; }
.btn-apply-overwrite:hover { background: #2d6b54; }
.btn-apply-append { background: #fff; color: #4E8A6B; border: 1px solid #4E8A6B; }
.btn-apply-append:hover { background: #f0fdf4; }

/* ペルソナ詳細テキストエリア */
#cs-persona-detail {
    width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; line-height: 1.8; resize: vertical; min-height: 180px;
    transition: border-color .2s; box-sizing: border-box;
}
#cs-persona-detail:focus {
    outline: none; border-color: #4E8A6B; box-shadow: 0 0 0 3px rgba(78,138,107,.12);
}
.char-count { font-size: 12px; color: #94a3b8; text-align: right; margin-top: 2px; }

/* --- 計測キーワード管理 --- */
.cs-kw-table {
    width: 100%; border-collapse: collapse; margin-bottom: 12px;
}
.cs-kw-table th {
    font-size: 12px; font-weight: 600; color: #6b7280;
    padding: 10px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.cs-kw-table td {
    padding: 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; font-size: 14px;
}
.cs-kw-table tr:last-child td { border-bottom: none; }

.cs-kw-actions {
    display: flex; align-items: center; gap: 8px; white-space: nowrap; justify-content: flex-end;
}
.cs-kw-order-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border: 1px solid #e5e7eb; border-radius: 6px;
    background: #fff; cursor: pointer; font-size: 14px; color: #6b7280;
}
.cs-kw-order-btn:hover { background: #f9fafb; }
.cs-kw-delete-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 10px; border: 1px solid #fecaca; border-radius: 6px;
    background: #fff; color: #ef4444; font-size: 12px; font-weight: 500; cursor: pointer;
}
.cs-kw-delete-btn:hover { background: #fef2f2; }

.cs-kw-add-form {
    display: flex; gap: 10px; align-items: end;
    padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px;
}
.cs-kw-add-form input[type="text"] {
    flex: 1; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 14px; background: #fff; box-sizing: border-box;
}
.cs-kw-add-form input[type="text"]:focus { outline: none; border-color: #4E8A6B; box-shadow: 0 0 0 3px rgba(78,138,107,.12); }
.cs-kw-add-form .btn-kw-submit {
    padding: 9px 18px; background: #4E8A6B; color: #fff; border: none; border-radius: 6px;
    font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap;
}
.cs-kw-add-form .btn-kw-submit:hover { background: #2d6b54; }
.cs-kw-add-form .btn-kw-submit:disabled { opacity: .5; cursor: not-allowed; }
.cs-kw-add-form .btn-kw-cancel {
    padding: 9px 14px; background: #fff; color: #64748b; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; cursor: pointer; white-space: nowrap;
}
.cs-kw-add-form .btn-kw-cancel:hover { background: #f9fafb; }

.cs-kw-footer {
    display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-top: 4px;
}
.btn-add-kw {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 8px 16px; background: #fff; color: #4E8A6B; border: 1px dashed #4E8A6B; border-radius: 6px;
    font-size: 13px; font-weight: 500; cursor: pointer;
}
.btn-add-kw:hover { background: #f0fdf4; }
.btn-add-kw:disabled { opacity: .5; cursor: not-allowed; border-color: #94a3b8; color: #94a3b8; }
.cs-kw-quota { font-size: 13px; color: #6b7280; }
.cs-kw-quota strong { font-weight: 700; color: #1e293b; }
.cs-kw-empty { font-size: 13px; color: #94a3b8; padding: 12px 0; }

/* Toggle switch */
.cs-kw-toggle {
    position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0;
}
.cs-kw-toggle input { opacity: 0; width: 0; height: 0; }
.cs-kw-toggle__slider {
    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
    background: #d1d5db; border-radius: 22px; transition: 0.2s;
}
.cs-kw-toggle__slider::before {
    content: ''; position: absolute; height: 16px; width: 16px; left: 3px; bottom: 3px;
    background: #fff; border-radius: 50%; transition: 0.2s;
}
.cs-kw-toggle input:checked + .cs-kw-toggle__slider { background: #568184; }
.cs-kw-toggle input:checked + .cs-kw-toggle__slider::before { transform: translateX(18px); }

/* ===== 外部連携共通UI ===== */

/* トグルスイッチ */
.cs-toggle-label {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    padding: 8px 14px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    transition: border-color .2s, background .2s;
}
.cs-toggle-label:hover { border-color: #94a3b8; background: #f8fafc; }
.cs-toggle-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #4E8A6B;
}
.cs-toggle-label:has(input:checked) {
    border-color: #4E8A6B;
    background: #f0fdf4;
}

/* トークン入力グループ */
.cs-token-group {
    display: flex;
    align-items: stretch;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    overflow: hidden;
    transition: border-color .2s, box-shadow .2s;
}
.cs-token-group:focus-within {
    border-color: #4E8A6B;
    box-shadow: 0 0 0 3px rgba(78,138,107,.12);
}
.cs-token-group input {
    flex: 1;
    border: none;
    padding: 10px 14px;
    font-size: 14px;
    line-height: 1.5;
    background: #fff;
    outline: none;
    min-width: 0;
}
.cs-token-group input::placeholder { color: #94a3b8; }
.cs-token-toggle {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 0 14px;
    border: none;
    border-left: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    font-size: 13px;
    cursor: pointer;
    transition: background .2s, color .2s;
    white-space: nowrap;
}
.cs-token-toggle:hover { background: #f1f5f9; color: #1e293b; }
.cs-token-toggle svg { width: 16px; height: 16px; flex-shrink: 0; }

/* 接続テストボタン */
.cs-btn-test {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 20px;
    font-size: 13px;
    font-weight: 600;
    color: #4E8A6B;
    background: #fff;
    border: 1px solid #4E8A6B;
    border-radius: 6px;
    cursor: pointer;
    transition: all .2s;
}
.cs-btn-test:hover { background: #f0fdf4; color: #2d6b54; }
.cs-btn-test:active { background: #dcfce7; }
.cs-btn-test:disabled {
    color: #94a3b8;
    border-color: #d1d5db;
    background: #f8fafc;
    cursor: not-allowed;
}
.cs-btn-test svg { width: 16px; height: 16px; }

/* 接続ステータスバッジ */
.cs-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    font-size: 13px;
    font-weight: 500;
    border-radius: 20px;
    line-height: 1.4;
}
.cs-status-badge--neutral {
    background: #f1f5f9;
    color: #64748b;
    border: 1px solid #e2e8f0;
}
.cs-status-badge--success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}
.cs-status-badge--error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.cs-status-badge--loading {
    background: #eff6ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}
.cs-status-badge .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}
.cs-status-badge--neutral .dot { background: #94a3b8; }
.cs-status-badge--success .dot { background: #22c55e; }
.cs-status-badge--error .dot { background: #ef4444; }
.cs-status-badge--loading .dot {
    background: #3b82f6;
    animation: csPulse 1.2s ease-in-out infinite;
}
@keyframes csPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* テストエリア */
.cs-test-area {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.cs-test-meta {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 8px;
}

/* ヒントテキスト */
.cs-hint {
    font-size: 12px;
    color: #94a3b8;
    margin: 6px 0 0;
    line-height: 1.5;
}
.cs-hint code {
    font-size: 11px;
    background: #f1f5f9;
    padding: 1px 6px;
    border-radius: 3px;
    color: #475569;
}

@media (max-width: 600px) {
    .cs-section { padding: 18px 16px; margin-bottom: 16px; }
    .ref-url-row { flex-wrap: wrap; }
    .ref-url-row input[type="url"],
    .ref-url-row input[type="text"] { flex: 1 1 100%; }
    .persona-gen-modal { width: 96%; max-height: 90vh; }
    .cs-kw-add-form { flex-wrap: wrap; }
    .cs-kw-add-form input[type="text"] { flex: 1 1 100%; }
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">

    <!-- トースト通知 -->
    <div class="cs-toast" id="csToast"></div>

    <!-- 対象サイト -->
    <div class="settings-card">
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">🌐</span> 対象サイト</h2>
            <div class="form-group">
                <label for="cs-site-url">解析対象のサイトURL <span class="required">*</span></label>
                <input type="url" id="cs-site-url" placeholder="https://example.com" value="<?php echo esc_attr( $initial_site_url ); ?>">
                <small class="form-text">AIレポートやAI相談で参照されるWebサイトのURLです</small>
            </div>
            <div class="form-group" style="margin-top:16px;">
                <label for="cs-maps-domain">Googleマップ用ドメイン <span style="font-size:11px;color:#94a3b8;font-weight:400;">（任意）</span></label>
                <input type="text" id="cs-maps-domain" placeholder="例: example.co.jp" value="<?php echo esc_attr( $maps_domain ); ?>">
                <small class="form-text">Googleビジネスプロフィール（GBP）に登録しているWebサイトのドメインが、対象サイトURLと異なる場合に設定してください。未入力の場合は対象サイトURLのドメインで照合します。</small>
            </div>
            <div class="form-group" style="margin-top:16px;">
                <label for="cs-include-paths">解析対象URL条件 <span style="font-size:11px;color:#94a3b8;font-weight:400;">（任意）</span></label>
                <textarea id="cs-include-paths" rows="2" placeholder="例: /example/" style="font-family: monospace; font-size: 13px; line-height: 1.6;"><?php echo esc_textarea( $include_paths_text ); ?></textarea>
                <small class="form-text">このアカウントで集計対象に<strong>含めたい</strong>URL条件を指定します。同一ドメイン内の特定ディレクトリだけを分析したい場合に使用します。1行に1つずつ入力してください。<br>例: <code>/example/</code> と入力すると、<code>/example/</code> 配下のページだけが集計対象になります。<br><strong>未設定の場合は、解析対象URL配下すべてが対象です。</strong></small>
            </div>
            <div class="form-group" style="margin-top:16px;">
                <label for="cs-exclude-paths">解析除外URL条件 <span style="font-size:11px;color:#94a3b8;font-weight:400;">（任意）</span></label>
                <textarea id="cs-exclude-paths" rows="2" placeholder="例:&#10;/example/&#10;/recruit/" style="font-family: monospace; font-size: 13px; line-height: 1.6;"><?php echo esc_textarea( $exclude_paths_text ); ?></textarea>
                <small class="form-text">このアカウントで集計対象から<strong>除外したい</strong>URL条件を指定します。別LP・採用ページ・キャンペーンページなどを除外したい場合に使用します。1行に1つずつ入力してください。<br>例: <code>/example/</code> と入力すると、<code>/example/</code> 配下のページデータが除外されます。</small>
            </div>
        </div>

        <!-- 計測キーワード -->
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">🔑</span> 計測キーワード</h2>
            <p style="font-size:13px;color:#64748b;margin:0 0 14px;">順位チェック・AIO診断で計測するキーワードを設定します（最大5件）</p>

            <div id="csKwTableWrap" style="display:none;">
                <table class="cs-kw-table">
                    <thead>
                        <tr>
                            <th>キーワード</th>
                            <th style="text-align:center;">ランキング計測</th>
                            <th style="text-align:center;">AIO診断</th>
                            <th style="text-align:right;">操作</th>
                        </tr>
                    </thead>
                    <tbody id="csKwTableBody"></tbody>
                </table>
            </div>

            <div id="csKwEmpty" class="cs-kw-empty">キーワードが登録されていません。</div>

            <div id="csKwFormWrap" style="display:none;">
                <div class="cs-kw-add-form">
                    <input type="text" id="csKwInput" placeholder="キーワードを入力（例: 愛媛 ホームページ制作）" maxlength="255">
                    <button type="button" class="btn-kw-submit" id="csKwSubmitBtn" onclick="csSubmitKeyword()">追加する</button>
                    <button type="button" class="btn-kw-cancel" onclick="csCancelAdd()">キャンセル</button>
                </div>
            </div>

            <div class="cs-kw-footer">
                <button type="button" class="btn-add-kw" id="csKwAddBtn" onclick="csShowAddForm()">＋ キーワードを追加</button>
                <span class="cs-kw-quota" id="csKwQuota"></span>
            </div>
        </div>

        <!-- 商圏・対応エリア -->
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">📍</span> 主な商圏・対応エリア</h2>
            <?php
            $area_type = $settings['area_type'] ?: '';
            // 旧データからの推定（未移行時）
            if ( ! $area_type && ! empty( $legacy_target ) ) {
                if ( mb_strpos( $legacy_target, '全国' ) !== false ) {
                    $area_type = 'nationwide';
                } elseif ( class_exists( 'Gcrev_Area_Detector' ) ) {
                    $detected = Gcrev_Area_Detector::detect( $legacy_target );
                    if ( $detected ) {
                        $area_type = 'prefecture';
                        if ( empty( $settings['area_pref'] ) ) {
                            $settings['area_pref'] = $detected;
                        }
                    }
                }
            }
            ?>
            <div class="area-type-options" id="areaTypeOptions">
                <div class="area-type-option <?php echo $area_type === 'nationwide' ? 'selected' : ''; ?>" data-value="nationwide">
                    <input type="radio" name="area_type" value="nationwide" id="area-nationwide" <?php checked( $area_type, 'nationwide' ); ?>>
                    <label for="area-nationwide">
                        <strong>全国</strong>
                        <span>全国を対象としたサービス</span>
                    </label>
                </div>
                <div class="area-type-option <?php echo $area_type === 'prefecture' ? 'selected' : ''; ?>" data-value="prefecture">
                    <input type="radio" name="area_type" value="prefecture" id="area-prefecture" <?php checked( $area_type, 'prefecture' ); ?>>
                    <label for="area-prefecture">
                        <strong>都道府県</strong>
                        <span>特定の都道府県を中心としたサービス</span>
                    </label>
                </div>
                <div class="area-type-option <?php echo $area_type === 'city' ? 'selected' : ''; ?>" data-value="city">
                    <input type="radio" name="area_type" value="city" id="area-city" <?php checked( $area_type, 'city' ); ?>>
                    <label for="area-city">
                        <strong>市区町村</strong>
                        <span>特定の市区町村を対象としたサービス</span>
                    </label>
                </div>
                <div class="area-type-option <?php echo $area_type === 'custom' ? 'selected' : ''; ?>" data-value="custom">
                    <input type="radio" name="area_type" value="custom" id="area-custom" <?php checked( $area_type, 'custom' ); ?>>
                    <label for="area-custom">
                        <strong>指定エリア</strong>
                        <span>自由に対応エリアを記述</span>
                    </label>
                </div>
            </div>

            <div class="area-sub-fields">
                <!-- 都道府県 選択 -->
                <div class="area-sub-field" id="sub-prefecture" data-for="prefecture">
                    <label for="cs-pref-select">都道府県を選択</label>
                    <select id="cs-pref-select">
                        <option value="">選択してください</option>
                        <?php
                        $prefs = [
                            '北海道','青森県','岩手県','宮城県','秋田県','山形県','福島県',
                            '茨城県','栃木県','群馬県','埼玉県','千葉県','東京都','神奈川県',
                            '新潟県','富山県','石川県','福井県','山梨県','長野県',
                            '岐阜県','静岡県','愛知県','三重県',
                            '滋賀県','京都府','大阪府','兵庫県','奈良県','和歌山県',
                            '鳥取県','島根県','岡山県','広島県','山口県',
                            '徳島県','香川県','愛媛県','高知県',
                            '福岡県','佐賀県','長崎県','熊本県','大分県','宮崎県','鹿児島県','沖縄県',
                        ];
                        $saved_pref = esc_attr( $settings['area_pref'] ?? '' );
                        foreach ( $prefs as $p ) {
                            $sel = ( $saved_pref === $p ) ? ' selected' : '';
                            echo '<option value="' . esc_attr( $p ) . '"' . $sel . '>' . esc_html( $p ) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <!-- 市区町村 -->
                <div class="area-sub-field" id="sub-city" data-for="city">
                    <label for="cs-city-pref">都道府県</label>
                    <select id="cs-city-pref" style="margin-bottom: 8px;">
                        <option value="">選択してください</option>
                        <?php
                        // 市区町村モード用の都道府県選択（area_pref を共用）
                        $saved_city_pref = esc_attr( $settings['area_pref'] ?? '' );
                        foreach ( $prefs as $p ) {
                            $sel = ( $saved_city_pref === $p ) ? ' selected' : '';
                            echo '<option value="' . esc_attr( $p ) . '"' . $sel . '>' . esc_html( $p ) . '</option>';
                        }
                        ?>
                    </select>
                    <label for="cs-city-input">市区町村（複数ある場合はカンマ区切り）</label>
                    <input type="text" id="cs-city-input" placeholder="例：渋谷区, 新宿区, 港区" value="<?php echo esc_attr( $settings['area_city'] ?? '' ); ?>">
                </div>

                <!-- 指定エリア（自由入力） -->
                <div class="area-sub-field" id="sub-custom" data-for="custom">
                    <label for="cs-area-custom">対応エリアの説明</label>
                    <textarea id="cs-area-custom" rows="2" placeholder="例：関東一円、東京23区および神奈川県横浜市"><?php echo esc_textarea( $settings['area_custom'] ?? '' ); ?></textarea>
                </div>
            </div>
        </div>

        <!-- クライアント情報 -->
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">🏢</span> クライアント情報（任意）</h2>

            <?php
            $industry_master   = gcrev_get_industry_master();
            $saved_category    = $settings['industry_category'] ?? '';
            $saved_subcategory = $settings['industry_subcategory'] ?? [];
            $saved_detail      = $settings['industry_detail'] ?? '';
            ?>

            <!-- 業種（大分類） -->
            <div class="industry-group">
                <label for="cs-industry-category">業種（任意）</label>
                <select id="cs-industry-category">
                    <option value="">選択してください</option>
                    <?php foreach ( $industry_master as $cat_val => $cat_data ): ?>
                    <option value="<?php echo esc_attr( $cat_val ); ?>" <?php selected( $saved_category, $cat_val ); ?>><?php echo esc_html( $cat_data['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 業態（小分類 — 複数選択） -->
            <div class="industry-group">
                <label>業態（任意）</label>
                <div class="subcategory-grid <?php echo empty( $saved_category ) ? 'disabled' : ''; ?>" id="subcategoryGrid">
                    <?php if ( empty( $saved_category ) ): ?>
                        <span class="subcategory-placeholder">業種を選択してください</span>
                    <?php else:
                        $subs = $industry_master[ $saved_category ]['subcategories'] ?? [];
                        foreach ( $subs as $sub_val => $sub_label ):
                            $is_checked = in_array( $sub_val, $saved_subcategory, true );
                    ?>
                        <label class="subcategory-item <?php echo $is_checked ? 'checked' : ''; ?>">
                            <input type="checkbox" value="<?php echo esc_attr( $sub_val ); ?>" <?php checked( $is_checked ); ?>>
                            <?php echo esc_html( $sub_label ); ?>
                        </label>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- 詳細 -->
            <div class="industry-group">
                <label for="cs-industry-detail">詳細（任意）</label>
                <input type="text" id="cs-industry-detail" maxlength="160" placeholder="例：小児歯科 / 外壁塗装 / 相続 / ランチ営業中心 など" value="<?php echo esc_attr( $saved_detail ); ?>">
            </div>

            <div class="form-group">
                <label>ビジネス形態</label>
                <?php
                $btype = $settings['business_type'] ?? '';
                $btypes = [
                    'visit'       => '来店型',
                    'non_visit'   => '非来店型',
                    'reservation' => '予約制',
                    'ec'          => 'ECサイト',
                    'other'       => 'その他',
                ];
                ?>
                <div class="btype-options" id="btypeOptions">
                    <?php foreach ( $btypes as $val => $label ): ?>
                    <div class="btype-option <?php echo $btype === $val ? 'selected' : ''; ?>" data-value="<?php echo esc_attr( $val ); ?>">
                        <input type="radio" name="business_type" value="<?php echo esc_attr( $val ); ?>" id="btype-<?php echo esc_attr( $val ); ?>" <?php checked( $btype, $val ); ?>>
                        <label for="btype-<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 成長ステージ -->
            <div class="form-group">
                <label for="cs-stage">成長ステージ</label>
                <?php
                $stage_val = $settings['stage'] ?? '';
                $stage_options = [
                    ''             => '— 選択してください —',
                    'launch'       => '立ち上げ期（開設〜半年）',
                    'awareness'    => '認知拡大期（半年〜1年）',
                    'growth'       => '安定成長期（1〜3年）',
                    'mature'       => '成熟期（3年以上）',
                    'renewal'      => 'リニューアル直後',
                ];
                ?>
                <select id="cs-stage" class="cs-select">
                    <?php foreach ( $stage_options as $val => $label ): ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $stage_val, $val ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ゴール種別 -->
            <div class="form-group">
                <label for="cs-main-conversions">ゴール種別（カンマ区切り）</label>
                <input type="text" id="cs-main-conversions" class="cs-input" value="<?php echo esc_attr( $settings['main_conversions'] ?? '' ); ?>" placeholder="例: お問い合わせフォーム, 電話タップ, 来店予約">
                <p class="field-hint">サイトの主なゴール（コンバージョン）を入力してください。AIが改善提案の優先度付けに活用します。</p>
            </div>
        </div>

        <!-- ===== (A) 簡易ペルソナ ===== -->
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">👤</span> 想定するお客様（ペルソナ）</h2>
            <p class="persona-section-desc">AIレポートや改善提案の精度を上げるための任意項目です。</p>

            <?php
            $persona_age_map = [
                'teens'  => '10代',
                '20s'    => '20代',
                '30s'    => '30代',
                '40s'    => '40代',
                '50s'    => '50代',
                '60plus' => '60代以上',
            ];
            $persona_gender_map = [
                'male'   => '男性',
                'female' => '女性',
                'any'    => '指定なし',
            ];
            $persona_attr_map = [
                'family'     => 'ファミリー層',
                'single'     => '単身者',
                'dinks'      => 'DINKS',
                'senior'     => 'シニア',
                'student'    => '学生',
                'business'   => 'ビジネスパーソン',
                'owner'      => '経営者・個人事業主',
                'highincome' => '富裕層',
                'local'      => '地元住民',
                'tourist'    => '観光客・旅行者',
            ];
            $persona_decision_map = [
                'price'      => '価格重視',
                'quality'    => '品質・実績重視',
                'speed'      => 'スピード重視',
                'reviews'    => '口コミ・評判で判断',
                'compare'    => '複数比較してから決める',
                'impulse'    => '即決タイプ',
                'recommend'  => '紹介・推薦で動く',
                'brand'      => 'ブランド・知名度重視',
                'proximity'  => '近さ・アクセス重視',
                'support'    => 'カスタマーサポート重視',
            ];
            $saved_ages      = $settings['persona_age_ranges'] ?? [];
            $saved_genders   = $settings['persona_genders'] ?? [];
            $saved_attrs     = $settings['persona_attributes'] ?? [];
            $saved_decisions = $settings['persona_decision_factors'] ?? [];
            ?>

            <!-- A1: 想定年齢層 -->
            <div class="form-group">
                <label>想定年齢層</label>
                <div class="persona-grid" data-persona-group="persona-age">
                    <?php foreach ( $persona_age_map as $val => $label ):
                        $checked = in_array( $val, $saved_ages, true );
                    ?>
                    <label class="subcategory-item <?php echo $checked ? 'checked' : ''; ?>">
                        <input type="checkbox" value="<?php echo esc_attr( $val ); ?>" <?php checked( $checked ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- A2: 想定性別 -->
            <div class="form-group">
                <label>想定性別</label>
                <div class="persona-grid" data-persona-group="persona-gender">
                    <?php foreach ( $persona_gender_map as $val => $label ):
                        $checked = in_array( $val, $saved_genders, true );
                    ?>
                    <label class="subcategory-item <?php echo $checked ? 'checked' : ''; ?>">
                        <input type="checkbox" value="<?php echo esc_attr( $val ); ?>" <?php checked( $checked ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- A3: ターゲット属性 -->
            <div class="form-group">
                <label>ターゲット属性</label>
                <div class="persona-grid" data-persona-group="persona-attr">
                    <?php foreach ( $persona_attr_map as $val => $label ):
                        $checked = in_array( $val, $saved_attrs, true );
                    ?>
                    <label class="subcategory-item <?php echo $checked ? 'checked' : ''; ?>">
                        <input type="checkbox" value="<?php echo esc_attr( $val ); ?>" <?php checked( $checked ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- A4: 検討・意思決定の特徴 -->
            <div class="form-group">
                <label>検討・意思決定の特徴</label>
                <div class="persona-grid" data-persona-group="persona-decision">
                    <?php foreach ( $persona_decision_map as $val => $label ):
                        $checked = in_array( $val, $saved_decisions, true );
                    ?>
                    <label class="subcategory-item <?php echo $checked ? 'checked' : ''; ?>">
                        <input type="checkbox" value="<?php echo esc_attr( $val ); ?>" <?php checked( $checked ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- A5: ひとことで -->
            <div class="form-group">
                <label for="cs-persona-oneliner">ひとことで表すと（任意）</label>
                <input type="text" id="cs-persona-oneliner" maxlength="200"
                    placeholder="例：共働きで忙しく、週末にまとめて情報収集する30代夫婦"
                    value="<?php echo esc_attr( $settings['persona_one_liner'] ?? '' ); ?>">
            </div>
        </div>

        <!-- ===== (B) 詳細ペルソナ ===== -->
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">📝</span> 詳細ペルソナ</h2>
            <div class="form-group">
                <label for="cs-persona-detail">詳細ペルソナ文（任意）</label>
                <textarea id="cs-persona-detail" rows="10" maxlength="4000"
                    placeholder="AIで生成するか、直接入力してください。&#10;&#10;■ 基本プロフィール&#10;■ 日常と課題&#10;■ 情報収集の行動パターン&#10;■ このサービスに求めること&#10;■ 響くメッセージ・表現"
                ><?php echo esc_textarea( $settings['persona_detail_text'] ?? '' ); ?></textarea>
                <div class="char-count"><span id="personaDetailCount"><?php echo mb_strlen( $settings['persona_detail_text'] ?? '' ); ?></span> / 4000</div>
            </div>
            <button type="button" class="btn-generate-persona" id="btnOpenPersonaGen">
                ✨ AIで詳細ペルソナを作成
            </button>
        </div>

        <!-- ===== (C) 参考URL ===== -->
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">🔗</span> 参考URL（競合・理想サイトなど）</h2>
            <p class="persona-section-desc">ペルソナ設計やAIレポートの参考にしたいサイトがあれば追加してください（最大5件）。</p>
            <div class="ref-url-list" id="referenceUrlList">
                <?php
                $ref_urls = $settings['persona_reference_urls'] ?? [];
                if ( ! empty( $ref_urls ) ):
                    foreach ( $ref_urls as $idx => $ru ):
                ?>
                <div class="ref-url-row">
                    <input type="url" placeholder="https://example.com" value="<?php echo esc_url( $ru['url'] ?? '' ); ?>">
                    <input type="text" placeholder="意図メモ（例: 同業の成功例）" maxlength="120" value="<?php echo esc_attr( $ru['note'] ?? '' ); ?>">
                    <button type="button" class="ref-url-remove" onclick="removeRefUrlRow(this)">×</button>
                </div>
                <?php
                    endforeach;
                endif;
                ?>
            </div>
            <button type="button" class="btn-add-ref-url" id="btnAddRefUrl">＋ URLを追加</button>
        </div>

        <!-- ===== Clarity連携設定 ===== -->
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">📊</span> Clarity連携設定</h2>
            <p class="persona-section-desc">Microsoft Clarity のデータをページ分析に統合します。</p>

            <div class="form-group">
                <label class="cs-toggle-label">
                    <input type="checkbox" id="cs-clarity-enabled" <?php echo ( $clarity_settings['clarity_enabled'] ?? false ) ? 'checked' : ''; ?>>
                    Clarity連携を有効にする
                </label>
            </div>

            <div class="form-group" style="margin-top:16px;">
                <label for="cs-clarity-token">APIトークン</label>
                <div class="cs-token-group">
                    <input type="password" id="cs-clarity-token"
                        placeholder="<?php echo ( $clarity_settings['clarity_has_token'] ?? false ) ? '設定済み（変更する場合のみ入力）' : 'Clarity APIトークンを入力'; ?>"
                        autocomplete="off">
                    <button type="button" class="cs-token-toggle" id="btnToggleClarityToken" onclick="toggleClarityToken()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <span>表示</span>
                    </button>
                </div>
                <p class="cs-hint">
                    <?php if ( $clarity_settings['clarity_has_token'] ?? false ): ?>
                        現在のトークン: <code><?php echo esc_html( $clarity_settings['clarity_token_mask'] ?? '' ); ?></code>
                    <?php else: ?>
                        Clarityのデータエクスポート用APIトークンを入力してください。このトークンはサーバー側でのみ使用されます。
                    <?php endif; ?>
                </p>
            </div>

            <div class="form-group" style="margin-top:16px;">
                <label for="cs-clarity-project">Clarityプロジェクト名（任意）</label>
                <input type="text" id="cs-clarity-project"
                    placeholder="例: g-crev.jp"
                    value="<?php echo esc_attr( $clarity_settings['clarity_project_name'] ?? '' ); ?>">
            </div>

            <div class="form-group" style="margin-top:20px;">
                <label>接続確認</label>
                <div class="cs-test-area">
                    <button type="button" class="cs-btn-test" id="btnClarityTest" onclick="testClarityConnection()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        接続テスト
                    </button>
                    <span id="clarityTestResult">
                        <?php
                        $c_status = $clarity_settings['clarity_connection_status'] ?? '';
                        if ( $c_status === 'success' ) {
                            echo '<span class="cs-status-badge cs-status-badge--success"><span class="dot"></span>接続確認済み</span>';
                        } elseif ( $c_status === 'failed' ) {
                            echo '<span class="cs-status-badge cs-status-badge--error"><span class="dot"></span>' . esc_html( $clarity_settings['clarity_last_message'] ?? '接続エラー' ) . '</span>';
                        } else {
                            echo '<span class="cs-status-badge cs-status-badge--neutral"><span class="dot"></span>未確認</span>';
                        }
                        ?>
                    </span>
                </div>
                <?php if ( ! empty( $clarity_settings['clarity_last_connected_at'] ) ): ?>
                <p class="cs-test-meta">
                    最終確認: <?php echo esc_html( $clarity_settings['clarity_last_connected_at'] ); ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- データ同期 -->
            <div class="form-group" style="margin-top:24px;padding-top:20px;border-top:1px solid #e9ecef;">
                <label>データ同期</label>
                <p class="cs-hint" style="margin:-2px 0 12px;">Clarity APIからページ別の行動データを取得し、ページ分析に反映します。<br>※ APIリクエスト制限: 1日10回まで / 直近3日分のデータが対象です。</p>
                <div class="cs-test-area">
                    <button type="button" class="cs-btn-test" id="btnClaritySync" onclick="syncClarityData()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        手動同期
                    </button>
                    <span id="claritySyncResult">
                        <?php
                        $s_status = $clarity_sync['last_sync_status'] ?? '';
                        if ( $s_status === 'success' ) {
                            echo '<span class="cs-status-badge cs-status-badge--success"><span class="dot"></span>同期済み</span>';
                        } elseif ( $s_status === 'failed' ) {
                            echo '<span class="cs-status-badge cs-status-badge--error"><span class="dot"></span>同期失敗</span>';
                        } elseif ( $s_status === 'partial' ) {
                            echo '<span class="cs-status-badge cs-status-badge--neutral"><span class="dot"></span>一部取得</span>';
                        } else {
                            echo '<span class="cs-status-badge cs-status-badge--neutral"><span class="dot"></span>未同期</span>';
                        }
                        ?>
                    </span>
                </div>
                <?php if ( ! empty( $clarity_sync['last_sync_at'] ) ): ?>
                <p class="cs-test-meta">
                    最終同期: <?php echo esc_html( $clarity_sync['last_sync_at'] ); ?>
                    <?php if ( ! empty( $clarity_sync['last_sync_message'] ) ): ?>
                        — <?php echo esc_html( $clarity_sync['last_sync_message'] ); ?>
                    <?php endif; ?>
                </p>
                <?php endif; ?>

                <!-- 同期結果詳細（JS で動的更新） -->
                <div id="claritySyncDetail" style="display:none;margin-top:12px;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;line-height:1.7;">
                </div>
            </div>
        </div>

        <div class="cs-actions">
            <button type="button" class="btn-save" id="btn-cs-save" onclick="saveClientSettings()">
                💾 保存する
            </button>
        </div>
    </div>

    <!-- ===== AI生成モーダル ===== -->
    <div class="persona-gen-overlay" id="personaGenOverlay" style="display:none;">
        <div class="persona-gen-modal">
            <header>
                <h3>✨ AIで詳細ペルソナを作成</h3>
                <button type="button" class="modal-close" id="personaGenClose">×</button>
            </header>
            <div class="persona-gen-body">
                <p style="font-size:13px;color:#64748b;margin:0 0 12px;">
                    現在の設定内容をもとに、AIが詳細なペルソナを生成します。
                </p>
                <div class="persona-gen-context" id="personaGenContext">
                    <!-- JSで動的に生成 -->
                </div>

                <div class="persona-gen-extra">
                    <label for="pgExtra-service">主なサービス・商品</label>
                    <input type="text" id="pgExtra-service" placeholder="例：外壁塗装、ホームページ制作">

                    <label for="pgExtra-price">価格帯</label>
                    <input type="text" id="pgExtra-price" placeholder="例：3万〜10万円、月額制">

                    <label for="pgExtra-area">対応エリア</label>
                    <input type="text" id="pgExtra-area" placeholder="例：東京23区、全国対応">

                    <label for="pgExtra-competitor">競合との違い・強み</label>
                    <input type="text" id="pgExtra-competitor" placeholder="例：地域密着20年、女性スタッフ対応">

                    <label for="pgExtra-avoid">避けたい表現・方針</label>
                    <input type="text" id="pgExtra-avoid" placeholder="例：煽り表現は避けたい">
                </div>

                <div class="persona-gen-actions">
                    <button type="button" class="btn-persona-gen" id="btnGeneratePersona">
                        🤖 ペルソナを生成する
                    </button>
                </div>

                <div class="persona-gen-preview" id="personaGenPreview"></div>

                <div class="persona-gen-apply" id="personaGenApply">
                    <button type="button" class="btn-apply-persona btn-apply-overwrite" id="btnApplyOverwrite">上書きで反映</button>
                    <button type="button" class="btn-apply-persona btn-apply-append" id="btnApplyAppend">追記で反映</button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(function() {
    const restBase = '<?php echo esc_js( trailingslashit( rest_url( 'gcrev_insights/v1' ) ) ); ?>';
    const wpNonce  = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';

    // === 業種マスターデータ（PHP→JS） ===
    var industryMaster = <?php echo wp_json_encode( $industry_master, JSON_UNESCAPED_UNICODE ); ?>;

    // === 商圏タイプ切替 ===
    const areaOptions = document.querySelectorAll('#areaTypeOptions .area-type-option');
    const subFields   = document.querySelectorAll('.area-sub-field');

    function updateAreaType(selectedValue) {
        areaOptions.forEach(function(opt) {
            opt.classList.toggle('selected', opt.dataset.value === selectedValue);
        });
        subFields.forEach(function(sf) {
            sf.classList.toggle('visible', sf.dataset.for === selectedValue);
        });
    }

    areaOptions.forEach(function(opt) {
        opt.addEventListener('click', function() {
            var radio = opt.querySelector('input[type="radio"]');
            radio.checked = true;
            updateAreaType(opt.dataset.value);
        });
    });

    // 初期状態の反映
    var checkedRadio = document.querySelector('input[name="area_type"]:checked');
    if (checkedRadio) {
        updateAreaType(checkedRadio.value);
    }

    // === 業種 → 業態 カスケード ===
    var categorySelect   = document.getElementById('cs-industry-category');
    var subcategoryGrid  = document.getElementById('subcategoryGrid');

    function renderSubcategories(catValue, checkedValues) {
        subcategoryGrid.innerHTML = '';
        if (!catValue || !industryMaster[catValue]) {
            subcategoryGrid.classList.add('disabled');
            subcategoryGrid.innerHTML = '<span class="subcategory-placeholder">業種を選択してください</span>';
            return;
        }
        subcategoryGrid.classList.remove('disabled');
        var subs = industryMaster[catValue].subcategories;
        for (var subVal in subs) {
            if (!subs.hasOwnProperty(subVal)) continue;
            var isChecked = checkedValues.indexOf(subVal) !== -1;
            var lbl = document.createElement('label');
            lbl.className = 'subcategory-item' + (isChecked ? ' checked' : '');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = subVal;
            cb.checked = isChecked;
            cb.addEventListener('change', function() {
                this.parentElement.classList.toggle('checked', this.checked);
            });
            lbl.appendChild(cb);
            lbl.appendChild(document.createTextNode(' ' + subs[subVal]));
            subcategoryGrid.appendChild(lbl);
        }
    }

    categorySelect.addEventListener('change', function() {
        renderSubcategories(this.value, []);
    });

    // 業態チェックボックスの初期クリックイベント（PHP レンダリング分）
    subcategoryGrid.querySelectorAll('.subcategory-item input[type="checkbox"]').forEach(function(cb) {
        cb.addEventListener('change', function() {
            this.parentElement.classList.toggle('checked', this.checked);
        });
    });

    // === ビジネス形態切替 ===
    var btypeOptions = document.querySelectorAll('#btypeOptions .btype-option');
    btypeOptions.forEach(function(opt) {
        opt.addEventListener('click', function() {
            btypeOptions.forEach(function(o) { o.classList.remove('selected'); });
            opt.classList.add('selected');
            opt.querySelector('input[type="radio"]').checked = true;
        });
    });

    // === ペルソナ チェックボックス イベント ===
    document.querySelectorAll('.persona-grid .subcategory-item input[type="checkbox"]').forEach(function(cb) {
        cb.addEventListener('change', function() {
            this.parentElement.classList.toggle('checked', this.checked);
        });
    });

    // チェック済み値を収集するヘルパー
    function collectChecked(groupName) {
        var checked = [];
        var grid = document.querySelector('[data-persona-group="' + groupName + '"]');
        if (grid) {
            grid.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
                checked.push(cb.value);
            });
        }
        return checked;
    }

    // === 参考URL 動的追加 ===
    var refUrlList = document.getElementById('referenceUrlList');
    var btnAddRefUrl = document.getElementById('btnAddRefUrl');

    function addRefUrlRow(urlVal, noteVal) {
        if (refUrlList.querySelectorAll('.ref-url-row').length >= 5) {
            alert('参考URLは最大5件までです。');
            return;
        }
        var row = document.createElement('div');
        row.className = 'ref-url-row';
        row.innerHTML =
            '<input type="url" placeholder="https://example.com" value="' + (urlVal || '') + '">' +
            '<input type="text" placeholder="意図メモ（例: 同業の成功例）" maxlength="120" value="' + (noteVal || '') + '">' +
            '<button type="button" class="ref-url-remove" onclick="removeRefUrlRow(this)">×</button>';
        refUrlList.appendChild(row);
    }

    btnAddRefUrl.addEventListener('click', function() {
        addRefUrlRow('', '');
    });

    window.removeRefUrlRow = function(btn) {
        btn.closest('.ref-url-row').remove();
    };

    // 参考URL収集
    function collectRefUrls() {
        var urls = [];
        refUrlList.querySelectorAll('.ref-url-row').forEach(function(row) {
            var u = row.querySelector('input[type="url"]').value.trim();
            var n = row.querySelector('input[type="text"]').value.trim();
            if (u) {
                urls.push({ url: u, note: n });
            }
        });
        return urls;
    }

    // === 詳細ペルソナ文字数カウント ===
    var detailTextarea = document.getElementById('cs-persona-detail');
    var detailCount    = document.getElementById('personaDetailCount');
    detailTextarea.addEventListener('input', function() {
        detailCount.textContent = this.value.length;
    });

    // === AI生成モーダル ===
    var overlay       = document.getElementById('personaGenOverlay');
    var btnOpen       = document.getElementById('btnOpenPersonaGen');
    var btnClose      = document.getElementById('personaGenClose');
    var genContext    = document.getElementById('personaGenContext');
    var genPreview    = document.getElementById('personaGenPreview');
    var genApply      = document.getElementById('personaGenApply');
    var btnGenerate   = document.getElementById('btnGeneratePersona');
    var btnOverwrite  = document.getElementById('btnApplyOverwrite');
    var btnAppend     = document.getElementById('btnApplyAppend');
    var isGenerating  = false;
    var generatedText = '';

    // ラベルマップ（JS用）
    var ageLabels      = <?php echo wp_json_encode( $persona_age_map, JSON_UNESCAPED_UNICODE ); ?>;
    var genderLabels   = <?php echo wp_json_encode( $persona_gender_map, JSON_UNESCAPED_UNICODE ); ?>;
    var attrLabels     = <?php echo wp_json_encode( $persona_attr_map, JSON_UNESCAPED_UNICODE ); ?>;
    var decisionLabels = <?php echo wp_json_encode( $persona_decision_map, JSON_UNESCAPED_UNICODE ); ?>;

    function valuesToLabels(values, labelMap) {
        return values.map(function(v) { return labelMap[v] || v; });
    }

    btnOpen.addEventListener('click', function() {
        // コンテキストサマリーを生成
        var ages      = collectChecked('persona-age');
        var genders   = collectChecked('persona-gender');
        var attrs     = collectChecked('persona-attr');
        var decisions = collectChecked('persona-decision');
        var oneLiner  = document.getElementById('cs-persona-oneliner').value.trim();
        var indLabel  = categorySelect.options[categorySelect.selectedIndex]
                        ? categorySelect.options[categorySelect.selectedIndex].text : '';
        if (indLabel === '選択してください') indLabel = '';

        var html = '<dl>';
        if (indLabel) html += '<dt>業種・業態</dt><dd>' + escH(indLabel) + '</dd>';
        if (ages.length)      html += '<dt>想定年齢層</dt><dd>' + valuesToLabels(ages, ageLabels).join(', ') + '</dd>';
        if (genders.length)   html += '<dt>想定性別</dt><dd>' + valuesToLabels(genders, genderLabels).join(', ') + '</dd>';
        if (attrs.length)     html += '<dt>ターゲット属性</dt><dd>' + valuesToLabels(attrs, attrLabels).join(', ') + '</dd>';
        if (decisions.length) html += '<dt>検討・意思決定</dt><dd>' + valuesToLabels(decisions, decisionLabels).join(', ') + '</dd>';
        if (oneLiner)         html += '<dt>ひとこと</dt><dd>' + escH(oneLiner) + '</dd>';

        var refs = collectRefUrls();
        if (refs.length) {
            html += '<dt>参考URL</dt>';
            refs.forEach(function(r) {
                html += '<dd>' + escH(r.url) + (r.note ? ' (' + escH(r.note) + ')' : '') + '</dd>';
            });
        }
        html += '</dl>';
        if (html === '<dl></dl>') {
            html = '<p style="color:#94a3b8;">設定情報がまだありません。上のフォームで簡易ペルソナを入力すると、より精度の高い生成ができます。</p>';
        }
        genContext.innerHTML = html;

        // リセット
        genPreview.style.display = 'none';
        genPreview.textContent = '';
        genApply.classList.remove('visible');
        generatedText = '';

        overlay.style.display = 'flex';
    });

    btnClose.addEventListener('click', function() { overlay.style.display = 'none'; });
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.style.display = 'none';
    });

    function escH(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // AI生成
    btnGenerate.addEventListener('click', async function() {
        if (isGenerating) return;
        isGenerating = true;
        btnGenerate.disabled = true;
        btnGenerate.textContent = '⏳ 生成中...';
        genPreview.style.display = 'none';
        genApply.classList.remove('visible');

        var ages      = collectChecked('persona-age');
        var genders   = collectChecked('persona-gender');
        var attrs     = collectChecked('persona-attr');
        var decisions = collectChecked('persona-decision');
        var oneLiner  = document.getElementById('cs-persona-oneliner').value.trim();

        var indCat = categorySelect.value;
        var indSub = [];
        subcategoryGrid.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
            indSub.push(cb.value);
        });
        var indLabel = '';
        if (categorySelect.selectedIndex > 0) {
            indLabel = categorySelect.options[categorySelect.selectedIndex].text;
        }

        var refs = collectRefUrls();

        var body = {
            context: {
                industry_category:    indCat,
                industry_subcategory: indSub,
                industry_label:       indLabel,
                persona_age_ranges:       valuesToLabels(ages, ageLabels),
                persona_genders:          valuesToLabels(genders, genderLabels),
                persona_attributes:       valuesToLabels(attrs, attrLabels),
                persona_decision_factors: valuesToLabels(decisions, decisionLabels),
                persona_one_liner:        oneLiner,
                reference_urls:           refs,
                extra: {
                    service:             document.getElementById('pgExtra-service').value.trim(),
                    price_range:         document.getElementById('pgExtra-price').value.trim(),
                    area:                document.getElementById('pgExtra-area').value.trim(),
                    competitor_features: document.getElementById('pgExtra-competitor').value.trim(),
                    avoid_notes:         document.getElementById('pgExtra-avoid').value.trim(),
                }
            }
        };

        try {
            var res = await fetch(restBase + 'generate-persona', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce },
                body: JSON.stringify(body)
            });
            var json = await res.json();
            if (res.ok && json.success) {
                generatedText = json.generated_text;
                genPreview.textContent = generatedText;
                genPreview.style.display = 'block';

                // 自動反映: 既存テキストがあれば追記、なければ上書き
                var existing = detailTextarea.value.trim();
                if (existing) {
                    detailTextarea.value = existing + '\n\n---\n\n' + generatedText;
                } else {
                    detailTextarea.value = generatedText;
                }
                detailCount.textContent = detailTextarea.value.length;

                // 反映ボタンも表示（再反映用）
                genApply.classList.add('visible');
            } else {
                alert('生成に失敗しました: ' + (json.message || ''));
            }
        } catch (e) {
            alert('生成中にエラーが発生しました: ' + e.message);
        } finally {
            isGenerating = false;
            btnGenerate.disabled = false;
            btnGenerate.textContent = '🤖 ペルソナを生成する';
        }
    });

    // 反映: 上書き（再反映用）
    btnOverwrite.addEventListener('click', function() {
        if (!generatedText) return;
        detailTextarea.value = generatedText;
        detailCount.textContent = generatedText.length;
        overlay.style.display = 'none';
        showToast('詳細ペルソナを上書きで反映しました');
    });

    // 反映: 追記（再反映用）
    btnAppend.addEventListener('click', function() {
        if (!generatedText) return;
        var existing = detailTextarea.value.trim();
        if (existing) {
            detailTextarea.value = existing + '\n\n---\n\n' + generatedText;
        } else {
            detailTextarea.value = generatedText;
        }
        detailCount.textContent = detailTextarea.value.length;
        overlay.style.display = 'none';
        showToast('詳細ペルソナを追記で反映しました');
    });

    // === 保存処理 ===
    window.saveClientSettings = async function() {
        var siteUrl = document.getElementById('cs-site-url').value.trim();
        if (!siteUrl) {
            alert('対象サイトURLは必須です。');
            return;
        }
        if (!/^https?:\/\/.+/.test(siteUrl)) {
            alert('URLの形式が正しくありません。https:// から入力してください。');
            return;
        }

        var areaType = '';
        var areaRadio = document.querySelector('input[name="area_type"]:checked');
        if (areaRadio) areaType = areaRadio.value;

        var areaPref = '';
        if (areaType === 'prefecture') {
            areaPref = document.getElementById('cs-pref-select').value;
        } else if (areaType === 'city') {
            areaPref = document.getElementById('cs-city-pref').value;
        }

        var areaCity   = document.getElementById('cs-city-input').value.trim();
        var areaCustom = document.getElementById('cs-area-custom').value.trim();

        // 業種3項目
        var industryCategory = categorySelect.value;
        var industrySubcategory = [];
        subcategoryGrid.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
            industrySubcategory.push(cb.value);
        });
        var industryDetail = document.getElementById('cs-industry-detail').value.trim();

        var businessType = '';
        var btRadio = document.querySelector('input[name="business_type"]:checked');
        if (btRadio) businessType = btRadio.value;

        var stageVal = document.getElementById('cs-stage') ? document.getElementById('cs-stage').value : '';
        var mainConversions = document.getElementById('cs-main-conversions') ? document.getElementById('cs-main-conversions').value.trim() : '';

        // ペルソナフィールド
        var personaAgeRanges      = collectChecked('persona-age');
        var personaGenders        = collectChecked('persona-gender');
        var personaAttributes     = collectChecked('persona-attr');
        var personaDecisionFactors = collectChecked('persona-decision');
        var personaOneLiner       = document.getElementById('cs-persona-oneliner').value.trim();
        var personaDetailText     = detailTextarea.value;
        var personaRefUrls        = collectRefUrls();

        var btn = document.getElementById('btn-cs-save');
        btn.disabled = true;
        btn.textContent = '保存中...';

        try {
            var res = await fetch(restBase + 'save-client-settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpNonce
                },
                body: JSON.stringify({
                    site_url:               siteUrl,
                    maps_domain:            (document.getElementById('cs-maps-domain').value || '').trim(),
                    include_paths:          (document.getElementById('cs-include-paths').value || '').trim(),
                    exclude_paths:          (document.getElementById('cs-exclude-paths').value || '').trim(),
                    area_type:              areaType,
                    area_pref:              areaPref,
                    area_city:              areaCity,
                    area_custom:            areaCustom,
                    industry_category:      industryCategory,
                    industry_subcategory:   industrySubcategory,
                    industry_detail:        industryDetail,
                    business_type:          businessType,
                    stage:                  stageVal,
                    main_conversions:       mainConversions,
                    persona_age_ranges:       personaAgeRanges,
                    persona_genders:          personaGenders,
                    persona_attributes:       personaAttributes,
                    persona_decision_factors: personaDecisionFactors,
                    persona_one_liner:        personaOneLiner,
                    persona_detail_text:      personaDetailText,
                    persona_reference_urls:   personaRefUrls,
                    // Clarity連携
                    clarity_enabled:      document.getElementById('cs-clarity-enabled').checked ? 1 : 0,
                    clarity_api_token:    document.getElementById('cs-clarity-token').value.trim(),
                    clarity_project_name: document.getElementById('cs-clarity-project').value.trim()
                })
            });

            var json = await res.json();
            if (res.ok && json.success) {
                showToast('クライアント設定を保存しました');
            } else {
                alert('保存に失敗しました: ' + (json.message || ''));
            }
        } catch (e) {
            alert('保存中にエラーが発生しました: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.textContent = '💾 保存する';
        }
    };

    function showToast(msg) {
        var toast = document.getElementById('csToast');
        toast.textContent = '✅ ' + msg;
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 3000);
    }

    // ===== Clarity 接続テスト =====
    window.testClarityConnection = async function() {
        var btn = document.getElementById('btnClarityTest');
        var resultEl = document.getElementById('clarityTestResult');
        btn.disabled = true;
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> 確認中...';
        resultEl.innerHTML = '<span class="cs-status-badge cs-status-badge--loading"><span class="dot"></span>確認中...</span>';

        // トークンが入力されていたら先に保存
        var tokenVal = document.getElementById('cs-clarity-token').value.trim();
        if (tokenVal) {
            await fetch(restBase + 'save-client-settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce },
                body: JSON.stringify({
                    site_url: document.getElementById('cs-site-url').value.trim() || 'https://placeholder.test',
                    clarity_enabled: document.getElementById('cs-clarity-enabled').checked ? 1 : 0,
                    clarity_api_token: tokenVal,
                    clarity_project_name: document.getElementById('cs-clarity-project').value.trim()
                })
            });
        }

        try {
            var res = await fetch('<?php echo esc_url_raw( rest_url( 'gcrev/v1/clarity/test-connection' ) ); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce },
                credentials: 'same-origin'
            });
            var json = await res.json();
            if (json.success) {
                resultEl.innerHTML = '<span class="cs-status-badge cs-status-badge--success"><span class="dot"></span>接続確認済み</span>';
                showToast('Clarity接続テストに成功しました');
            } else {
                var msg = json.message || '接続エラー';
                resultEl.innerHTML = '<span class="cs-status-badge cs-status-badge--error"><span class="dot"></span>' + msg.replace(/</g,'&lt;') + '</span>';
            }
        } catch (e) {
            resultEl.innerHTML = '<span class="cs-status-badge cs-status-badge--error"><span class="dot"></span>通信エラー</span>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> 接続テスト';
        }
    };

    // ===== Clarity 手動同期 =====
    window.syncClarityData = async function() {
        var btn = document.getElementById('btnClaritySync');
        var resultEl = document.getElementById('claritySyncResult');
        var detailEl = document.getElementById('claritySyncDetail');
        btn.disabled = true;
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> 同期中...';
        resultEl.innerHTML = '<span class="cs-status-badge cs-status-badge--loading"><span class="dot"></span>Clarity APIからデータ取得中...</span>';
        detailEl.style.display = 'none';

        try {
            var res = await fetch('<?php echo esc_url_raw( rest_url( 'gcrev/v1/clarity/sync' ) ); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpNonce },
                credentials: 'same-origin'
            });
            var json = await res.json();
            if (json.success) {
                resultEl.innerHTML = '<span class="cs-status-badge cs-status-badge--success"><span class="dot"></span>同期完了</span>';
                showToast('Clarityデータの同期が完了しました');

                // 結果詳細表示
                var s = json.summary || {};
                var html = '<strong>同期結果:</strong><br>';
                html += '取得メトリクス数: ' + (s.metrics_fetched || 0) + '<br>';
                html += 'ページ分析更新数: ' + (s.pages_updated || 0) + '<br>';
                if (s.normalized) {
                    if (s.normalized.total_urls) html += '取得URL数: ' + s.normalized.total_urls + '<br>';
                    if (s.normalized.available_metrics && s.normalized.available_metrics.length > 0) {
                        html += '取得指標: ' + s.normalized.available_metrics.join(', ') + '<br>';
                    }
                    if (s.normalized.device_types && s.normalized.device_types.length > 0) {
                        html += 'デバイス種別: ' + s.normalized.device_types.join(', ') + '<br>';
                    }
                }
                if (s.matched_urls && s.matched_urls.length > 0) {
                    html += '<br><strong>マッチしたURL:</strong><br>';
                    s.matched_urls.forEach(function(u) { html += '✅ ' + u + '<br>'; });
                }
                if (s.unmatched_urls && s.unmatched_urls.length > 0) {
                    html += '<br><strong style="color:#dc2626;">未マッチURL（Clarityから取得したが、ページ分析に未登録）:</strong><br>';
                    s.unmatched_urls.slice(0, 20).forEach(function(u) { html += '⚠ ' + u + '<br>'; });
                    if (s.unmatched_urls.length > 20) html += '...他 ' + (s.unmatched_urls.length - 20) + '件<br>';
                }
                html += '<br>同期日時: ' + (s.synced_at || '-');
                detailEl.innerHTML = html;
                detailEl.style.display = 'block';
            } else {
                var msg = json.message || '同期に失敗しました';
                resultEl.innerHTML = '<span class="cs-status-badge cs-status-badge--error"><span class="dot"></span>' + msg.replace(/</g,'&lt;') + '</span>';
            }
        } catch (e) {
            resultEl.innerHTML = '<span class="cs-status-badge cs-status-badge--error"><span class="dot"></span>通信エラーが発生しました</span>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> 手動同期';
        }
    };

    // ===== Clarity トークン表示切替 =====
    window.toggleClarityToken = function() {
        var inp = document.getElementById('cs-clarity-token');
        var toggleBtn = document.getElementById('btnToggleClarityToken');
        var label = toggleBtn.querySelector('span');
        var svg = toggleBtn.querySelector('svg');
        if (inp.type === 'password') {
            inp.type = 'text';
            label.textContent = '非表示';
            svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
        } else {
            inp.type = 'password';
            label.textContent = '表示';
            svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        }
    };

    // =========================================================
    // 計測キーワード管理
    // =========================================================
    var csKwList = [];
    var csKwLimit = 5;
    var csKwCanAdd = true;
    var csKwDefaultDomain = '';

    function csKwEsc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function csKwFormatDate(str) {
        if (!str) return '-';
        var dt = new Date(str.replace(/-/g, '/'));
        if (isNaN(dt.getTime())) return str;
        var y = dt.getFullYear();
        var m = ('0' + (dt.getMonth() + 1)).slice(-2);
        var day = ('0' + dt.getDate()).slice(-2);
        return y + '/' + m + '/' + day;
    }

    function csKwFetch() {
        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords', {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success && json.data) {
                csKwList = json.data.keywords || [];
                csKwLimit = json.data.limit || 5;
                csKwCanAdd = json.data.can_add;
                csKwDefaultDomain = json.data.default_domain || '';
                csKwRender();
            }
        })
        .catch(function(err) { console.error('[CS KW]', err); });
    }

    function csKwRender() {
        var tableWrap = document.getElementById('csKwTableWrap');
        var tbody = document.getElementById('csKwTableBody');
        var empty = document.getElementById('csKwEmpty');
        var addBtn = document.getElementById('csKwAddBtn');
        var quota = document.getElementById('csKwQuota');

        // 枠数表示
        quota.innerHTML = '<strong>' + csKwList.length + '</strong> / ' + csKwLimit + ' キーワード';

        // 追加ボタン
        addBtn.disabled = !csKwCanAdd;

        if (csKwList.length === 0) {
            tableWrap.style.display = 'none';
            empty.style.display = 'block';
            return;
        }

        empty.style.display = 'none';
        tableWrap.style.display = 'block';

        var html = '';
        for (var i = 0; i < csKwList.length; i++) {
            var kw = csKwList[i];
            html += '<tr>';
            html += '<td><strong>' + csKwEsc(kw.keyword) + '</strong></td>';

            // ランキング計測トグル
            html += '<td style="text-align:center;">';
            html += '<label class="cs-kw-toggle">';
            html += '<input type="checkbox"' + (kw.enabled ? ' checked' : '') + ' onchange="csToggleKeyword(' + kw.id + ', this.checked)">';
            html += '<span class="cs-kw-toggle__slider"></span>';
            html += '</label>';
            html += '</td>';

            // AIO診断トグル
            html += '<td style="text-align:center;">';
            html += '<label class="cs-kw-toggle">';
            html += '<input type="checkbox"' + (kw.aio_enabled ? ' checked' : '') + ' onchange="csToggleAio(' + kw.id + ', this.checked)">';
            html += '<span class="cs-kw-toggle__slider"></span>';
            html += '</label>';
            html += '</td>';

            html += '<td style="text-align:right;">';
            html += '<div class="cs-kw-actions">';
            html += '<button class="cs-kw-order-btn" onclick="csReorderKeyword(' + kw.id + ',\'up\')" title="上に移動">&#x2191;</button>';
            html += '<button class="cs-kw-order-btn" onclick="csReorderKeyword(' + kw.id + ',\'down\')" title="下に移動">&#x2193;</button>';
            html += '<button class="cs-kw-delete-btn" onclick="csDeleteKeyword(' + kw.id + ',\'' + csKwEsc(kw.keyword).replace(/'/g, "\\'") + '\')">&#x1F5D1; 削除</button>';
            html += '</div>';
            html += '</td>';
            html += '</tr>';
        }
        tbody.innerHTML = html;
    }

    window.csShowAddForm = function() {
        document.getElementById('csKwFormWrap').style.display = 'block';
        document.getElementById('csKwInput').focus();
    };

    window.csCancelAdd = function() {
        document.getElementById('csKwInput').value = '';
        document.getElementById('csKwFormWrap').style.display = 'none';
    };

    window.csSubmitKeyword = function() {
        var input = document.getElementById('csKwInput');
        var keyword = input.value.trim();
        if (!keyword) { alert('キーワードを入力してください。'); return; }

        var btn = document.getElementById('csKwSubmitBtn');
        btn.disabled = true;

        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ keyword: keyword, target_domain: csKwDefaultDomain })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            btn.disabled = false;
            if (json.success) {
                input.value = '';
                document.getElementById('csKwFormWrap').style.display = 'none';
                csKwFetch();
                showToast('キーワードを追加しました');
            } else {
                alert(json.message || 'エラーが発生しました。');
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            console.error('[CS KW Submit]', err);
            alert('通信エラーが発生しました。');
        });
    };

    window.csDeleteKeyword = function(id, keyword) {
        if (!confirm('「' + keyword + '」を削除しますか？\nこのキーワードの順位履歴も削除されます。')) return;

        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords/' + id, {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success) {
                csKwFetch();
                showToast('キーワードを削除しました');
            } else {
                alert(json.message || '削除に失敗しました。');
            }
        });
    };

    window.csReorderKeyword = function(id, direction) {
        fetch('/wp-json/gcrev/v1/rank-tracker/reorder', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ keyword_id: id, direction: direction })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success) { csKwFetch(); }
        });
    };

    window.csToggleKeyword = function(id, enable) {
        var kw = csKwList.find(function(k) { return k.id === id; });
        if (!kw) return;

        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ id: id, keyword: kw.keyword, enabled: enable ? 1 : 0 })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success) {
                csKwFetch();
            } else {
                alert(json.message || 'エラーが発生しました。');
                csKwFetch(); // revert UI
            }
        });
    };

    window.csToggleAio = function(id, enable) {
        fetch('/wp-json/gcrev/v1/aio/my-keywords', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ keyword_id: id, aio_enabled: enable ? 1 : 0 })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success) {
                csKwFetch();
            } else {
                alert(json.message || 'エラーが発生しました。');
                csKwFetch(); // revert UI
            }
        });
    };

    // 初期読み込み
    csKwFetch();
})();
</script>

<?php get_footer(); ?>
