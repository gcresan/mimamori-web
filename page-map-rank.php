<?php
/*
Template Name: マップ順位
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

mimamori_guard_meo_access();

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'マップ順位');
set_query_var('gcrev_page_subtitle', 'Googleマップやローカル検索での表示順位を確認できます。');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('マップ順位', '検索順位チェック'));

// Maps用ドメイン
$maps_domain = get_user_meta( $user_id, '_gcrev_maps_domain', true ) ?: '';

// 基準地点プリセット用データ
$mw_saved_meo_address = (string) get_user_meta( $user_id, '_gcrev_meo_address', true );
$mw_saved_meo_lat     = (string) get_user_meta( $user_id, '_gcrev_meo_lat', true );
$mw_saved_meo_lng     = (string) get_user_meta( $user_id, '_gcrev_meo_lng', true );
$mw_saved_meo_mode    = (string) get_user_meta( $user_id, '_gcrev_meo_base_mode', true );
$mw_gbp_address       = (string) get_user_meta( $user_id, '_gcrev_gbp_location_address', true );

// 自社住所プリセット
// 1. base_mode が business で保存済み → 保存値（住所・lat/lng）をそのまま使う
// 2. それ以外 → 保存済みの meo_address（前回業務住所として使ったもの）を流用
// 3. 上記もなければ GBP 住所の整形版
$mw_biz_address = '';
$mw_biz_lat = '';
$mw_biz_lng = '';
if ( $mw_saved_meo_mode === 'business' && $mw_saved_meo_address !== '' ) {
    $mw_biz_address = $mw_saved_meo_address;
    $mw_biz_lat     = $mw_saved_meo_lat;
    $mw_biz_lng     = $mw_saved_meo_lng;
} elseif ( $mw_saved_meo_address !== '' ) {
    $mw_biz_address = $mw_saved_meo_address;
} elseif ( $mw_gbp_address !== '' ) {
    // GBP 住所はフォーマットが Google API の生データ（"Ehime甲 3-1平井町松山市" など）
    // で正しくジオコーディングできないため、ベストエフォートで成形。
    $mw_biz_address = trim( preg_replace( '/\s+/u', '', $mw_gbp_address ) );
}

$mw_area_pref = (string) get_user_meta( $user_id, 'gcrev_client_area_pref', true );
$mw_area_city = (string) get_user_meta( $user_id, 'gcrev_client_area_city', true );
// 市区町村の中心 — 「○○市役所」のように具体的な地名を追加した方が
// Nominatim のジオコーディングが安定する
$mw_city_query = '';
if ( $mw_area_city !== '' ) {
    if ( preg_match( '/[市区町村]$/u', $mw_area_city ) ) {
        $mw_city_query = trim( $mw_area_pref . $mw_area_city . '役所' );
    } else {
        $mw_city_query = trim( $mw_area_pref . $mw_area_city );
    }
}
$mw_city_label = $mw_area_city !== '' ? ( $mw_area_city . '中心部' ) : '市区町村の中心';

$mw_base_presets = wp_json_encode([
    'business' => [
        'available' => $mw_biz_address !== '',
        'address'   => $mw_biz_address,
        'lat'       => $mw_biz_lat,
        'lng'       => $mw_biz_lng,
    ],
    'city'     => [
        'available' => $mw_city_query !== '',
        'query'     => $mw_city_query,
        'label'     => $mw_city_label,
    ],
], JSON_UNESCAPED_UNICODE);

get_header();
?>

<style>
/* ============================================================
   page-map-rank v2 — Multi-keyword list layout
   ============================================================ */

/* --- Header bar --- */
.rt-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}
.rt-header__title {
    font-size: 20px;
    font-weight: 700;
    color: #1a1a1a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.rt-header__actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.rt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border: 1px solid #d0d5dd;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    background: #fff;
    color: #344054;
    transition: all 0.15s;
    white-space: nowrap;
}
.rt-btn:hover { background: #f9fafb; border-color: #98a2b3; }
.rt-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.rt-btn--primary {
    background: #1a1a1a;
    color: #fff;
    border-color: #1a1a1a;
}
.rt-btn--primary:hover { background: #333; }
.rt-btn--primary:disabled { background: #999; border-color: #999; }
.rt-btn__icon { font-size: 15px; }

/* --- Help lead --- */
.rt-help {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 20px;
    line-height: 1.6;
}

/* --- カラム見出しツールチップ --- */
.rt-th-help {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    cursor: help;
    position: relative;
}
.rt-th-help__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 1px solid #9ca3af;
    color: #6b7280;
    font-size: 10px;
    font-weight: 600;
    line-height: 1;
    background: #fff;
}
.rt-th-help__tip {
    visibility: hidden;
    opacity: 0;
    position: absolute;
    top: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: #1a1a1a;
    color: #fff;
    font-size: 12px;
    font-weight: 400;
    line-height: 1.55;
    padding: 10px 12px;
    border-radius: 8px;
    width: 260px;
    text-align: left;
    box-shadow: 0 6px 18px rgba(0,0,0,0.18);
    z-index: 20;
    transition: opacity 0.15s, visibility 0.15s;
    pointer-events: none;
    white-space: normal;
}
.rt-th-help__tip::before {
    content: '';
    position: absolute;
    top: -5px;
    left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-bottom-color: #1a1a1a;
    border-top: 0;
}
.rt-th-help:hover .rt-th-help__tip,
.rt-th-help:focus-within .rt-th-help__tip {
    visibility: visible;
    opacity: 1;
}

/* --- Summary cards --- */
.rt-summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 28px;
}
@media (max-width: 768px) {
    .rt-summary-cards { grid-template-columns: repeat(2, 1fr); }
}
.rt-summary-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    border-left: 4px solid #e5e7eb;
}
.rt-summary-card--gold   { border-left-color: #f59e0b; }
.rt-summary-card--blue   { border-left-color: #3b82f6; }
.rt-summary-card--green  { border-left-color: #22c55e; }
.rt-summary-card--red    { border-left-color: #ef4444; }
.rt-summary-card__dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
}
.rt-summary-card__dot--gold  { background: #f59e0b; }
.rt-summary-card__dot--blue  { background: #3b82f6; }
.rt-summary-card__dot--green { background: #22c55e; }
.rt-summary-card__dot--red   { background: #ef4444; }
.rt-summary-card__label {
    font-size: 13px;
    color: #6b7280;
    flex: 1;
}
.rt-summary-card__count {
    font-size: 20px;
    font-weight: 700;
    color: #1a1a1a;
    min-width: 32px;
    text-align: right;
}
.rt-summary-card__unit {
    font-size: 12px;
    font-weight: 400;
    color: #9ca3af;
}

/* --- Rankings table --- */
.rt-table-wrap {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 32px;
}
.rt-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.rt-table {
    width: 100%;
    border-collapse: collapse;
}
.rt-table th {
    background: #f9fafb;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    padding: 12px 14px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
}
.rt-table td {
    padding: 14px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #1a1a1a;
    vertical-align: middle;
}
.rt-table tr:last-child td { border-bottom: none; }
.rt-table tr:hover td { background: #fafbfc; }

/* Left accent bar */
.rt-table td:first-child {
    position: relative;
    padding-left: 20px;
}
.rt-rank-accent {
    position: absolute;
    left: 0;
    top: 8px;
    bottom: 8px;
    width: 4px;
    border-radius: 2px;
}
.rt-rank-accent--gold  { background: #f59e0b; }
.rt-rank-accent--blue  { background: #3b82f6; }
.rt-rank-accent--green { background: #22c55e; }
.rt-rank-accent--red   { background: #ef4444; }
.rt-rank-accent--gray  { background: #d1d5db; }

/* Keyword cell */
.rt-kw-name {
    font-weight: 600;
    color: #1a1a1a;
    margin-bottom: 2px;
}

/* Rank value */
.rt-rank {
    font-weight: 700;
    font-size: 16px;
    color: #1a1a1a;
}
.rt-rank--out {
    font-size: 12px;
    font-weight: 600;
    color: #ef4444;
}
.rt-rank--na {
    font-size: 12px;
    color: #d1d5db;
}
.rt-rank-unit {
    font-size: 11px;
    font-weight: 400;
    color: #9ca3af;
}
.rt-rank-change {
    font-size: 11px;
    font-weight: 600;
    margin-top: 2px;
}
.rt-rank-change--up   { color: #16a34a; }
.rt-rank-change--down { color: #ef4444; }
.rt-rank-change--same { color: #9ca3af; }

/* Daily cell (compact) */
.rt-daily {
    font-size: 13px;
    font-weight: 500;
    text-align: center;
    min-width: 48px;
}
.rt-daily--out { color: #ef4444; font-size: 11px; }
.rt-daily--na  { color: #d1d5db; }

/* Meta cells */
.rt-meta-rating { font-size: 13px; color: #f59e0b; }
.rt-meta-reviews { font-size: 13px; color: #6b7280; }

/* Actions column */
.rt-action-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    color: #568184;
    cursor: pointer;
    text-decoration: none;
    padding: 4px 0;
    border: none;
    background: none;
    white-space: nowrap;
}
.rt-action-link:hover { color: #476C6F; text-decoration: underline; }
.rt-action-link__icon { font-size: 14px; }

/* --- Empty state --- */
.rt-empty {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
}
.rt-empty__icon { font-size: 40px; margin-bottom: 12px; }
.rt-empty__text { font-size: 15px; color: #6b7280; }

/* --- Loading --- */
.rt-loading {
    text-align: center;
    padding: 40px;
    color: #9ca3af;
    font-size: 14px;
}

/* --- Modal --- */
.rt-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4);
    z-index: 10000;
    display: none;
    justify-content: center;
    align-items: flex-start;
    padding: 60px 20px;
    overflow-y: auto;
}
.rt-modal-overlay.active { display: flex; }
.rt-modal {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 720px;
    max-height: calc(100vh - 120px);
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.15);
}
.rt-modal__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 1;
    border-radius: 14px 14px 0 0;
}
.rt-modal__title {
    font-size: 16px;
    font-weight: 700;
    color: #1a1a1a;
}
.rt-modal__close {
    width: 32px;
    height: 32px;
    border: none;
    background: #f3f4f6;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
}
.rt-modal__close:hover { background: #e5e7eb; }
.rt-modal__body {
    padding: 24px;
}

/* --- Progress overlay (bulk fetch) --- */
.rt-progress-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 10002;
    display: none;
    justify-content: center;
    align-items: center;
}
.rt-progress-overlay.active { display: flex; }
.rt-progress-box {
    background: #fff;
    border-radius: 16px;
    padding: 32px 40px;
    min-width: 340px;
    max-width: 480px;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
}
.rt-progress-title {
    font-size: 16px;
    font-weight: 700;
    color: #1a1a1a;
    margin-bottom: 16px;
}
.rt-progress-bar-wrap {
    width: 100%;
    height: 10px;
    background: #e5e7eb;
    border-radius: 5px;
    overflow: hidden;
    margin-bottom: 12px;
}
.rt-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #568184, #a3c9a9);
    border-radius: 5px;
    width: 0%;
    transition: width 0.3s ease;
}
.rt-progress-bar--indeterminate {
    width: 30%;
    animation: rt-progress-slide 1.5s infinite ease-in-out;
}
@keyframes rt-progress-slide {
    0%   { transform: translateX(-100%); }
    50%  { transform: translateX(200%); }
    100% { transform: translateX(-100%); }
}
.rt-progress-text {
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 4px;
}
.rt-progress-sub {
    font-size: 12px;
    color: #9ca3af;
}

/* --- Toast --- */
.rt-toast {
    position: fixed;
    bottom: 24px;
    left: 24px;
    background: #1a1a1a;
    color: #fff;
    padding: 14px 20px;
    border-radius: 10px;
    font-size: 14px;
    z-index: 10001;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    opacity: 0;
    transform: translateY(12px);
    transition: opacity 0.3s, transform 0.3s;
    max-width: 400px;
    line-height: 1.5;
}
.rt-toast.show { opacity: 1; transform: translateY(0); }
.rt-toast--error { background: #ef4444; }

/* ============================================================
   MEO-specific styles (conditions bar, modal content)
   ============================================================ */

/* Measurement conditions row */
.meo-conditions {
    display: flex; align-items: center; gap: 32px; margin-bottom: 24px; flex-wrap: wrap;
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 24px;
}
.meo-condition-group {
    display: flex; flex-direction: column; gap: 6px;
}
.meo-condition-label {
    font-size: 11px; font-weight: 600; color: #9ca3af; letter-spacing: 0.3px;
    line-height: 1;
}
.meo-condition-value {
    font-size: 14px; font-weight: 600; color: #1a1a1a; line-height: 1.3;
}

/* Device toggle */
.meo-device-toggle {
    display: inline-flex; background: #eef0f3; border-radius: 8px; padding: 3px;
}
.meo-device-btn {
    padding: 7px 20px; border: none; border-radius: 6px; font-size: 13px; font-weight: 500;
    cursor: pointer; background: transparent; color: #667085; transition: all 0.2s;
    line-height: 1.3;
}
.meo-device-btn.active {
    background: #fff; color: #1a1a1a; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,0.06);
}

/* GBP domain badge */
.meo-gbp-badge {
    display: inline-block; font-size: 9px; font-weight: 700; color: #568184;
    background: #e8f4f5; border: 1px solid #c5dfe0; border-radius: 4px;
    padding: 1px 5px; letter-spacing: 0.5px;
}

/* Radius display */
.meo-radius-group { display: flex; flex-direction: column; gap: 6px; }

/* Store card (modal content) */
.meo-store-card {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 20px; margin-bottom: 20px;
}
.meo-store-card__title {
    font-size: 14px; font-weight: 700; color: #1a1a1a; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.meo-store-grid {
    display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; font-size: 13px;
}
.meo-store-label { color: #6b7280; font-weight: 500; white-space: nowrap; }
.meo-store-value { color: #1a1a1a; }
.meo-store-link {
    display: inline-flex; align-items: center; gap: 4px; font-size: 12px;
    color: #568184; text-decoration: none; margin-top: 12px;
}
.meo-store-link:hover { text-decoration: underline; }

/* Reviews card (modal content) */
.meo-reviews-card {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 20px; margin-bottom: 20px;
}
.meo-reviews-card__title {
    font-size: 14px; font-weight: 700; color: #1a1a1a; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.meo-reviews-summary {
    display: flex; align-items: center; gap: 16px; margin-bottom: 16px; flex-wrap: wrap;
}
.meo-reviews-big-rating { font-size: 28px; font-weight: 700; color: #1a1a1a; }
.meo-reviews-stars { font-size: 18px; color: #f59e0b; letter-spacing: 1px; }
.meo-reviews-count { font-size: 13px; color: #6b7280; }
.meo-rating-bars { max-width: 360px; }
.meo-rating-bar-row {
    display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 12px; color: #6b7280;
}
.meo-rating-bar-label { width: 24px; text-align: right; flex-shrink: 0; }
.meo-rating-bar-track {
    flex: 1; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;
}
.meo-rating-bar-fill {
    height: 100%; background: #f59e0b; border-radius: 4px; transition: width 0.3s;
}
.meo-rating-bar-count { width: 32px; text-align: right; flex-shrink: 0; font-size: 11px; color: #9ca3af; }

/* Competitor table (modal content) */
.meo-competitor-wrap {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;
    margin-bottom: 10px;
}
.meo-competitor-title {
    font-size: 14px; font-weight: 700; color: #1a1a1a; padding: 16px 16px 12px;
    display: flex; align-items: center; gap: 6px;
}
.meo-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.meo-competitor-table { width: 100%; border-collapse: collapse; }
.meo-competitor-table th {
    background: #f9fafb; font-size: 11px; font-weight: 600; color: #6b7280;
    padding: 10px 14px; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.meo-competitor-table td {
    padding: 12px 14px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #1a1a1a;
    vertical-align: middle;
}
.meo-competitor-table tr:last-child td { border-bottom: none; }
.meo-self-row td {
    background: #f0fdf4; font-weight: 600;
}
.meo-self-row td:first-child {
    border-left: 3px solid #568184;
}
.meo-self-badge {
    display: inline-block; font-size: 9px; color: #568184; background: #e8f4f5;
    border: 1px solid #c5dfe0; border-radius: 4px; padding: 1px 5px; margin-left: 4px;
    font-weight: 600;
}
.meo-stars-sm { font-size: 12px; color: #f59e0b; }

/* States */
.meo-loading { text-align: center; padding: 32px; color: #9ca3af; font-size: 13px; }
.meo-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }
.meo-empty__icon { font-size: 32px; margin-bottom: 8px; }
.meo-empty__text { font-size: 14px; color: #6b7280; }

/* --- Store info card (above table) --- */
.meo-store-info-card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 18px 24px; margin-bottom: 24px; display: none;
}
.meo-store-info-card.show { display: block; }
.meo-store-info-card__header {
    font-size: 13px; font-weight: 700; color: #1a1a1a; margin-bottom: 12px;
    display: flex; align-items: center; gap: 6px;
}
.meo-store-info-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 8px 32px; font-size: 13px;
}
.meo-store-info-item { display: flex; align-items: center; gap: 8px; }
.meo-store-info-label { color: #6b7280; font-weight: 500; white-space: nowrap; }
.meo-store-info-val { color: #1a1a1a; font-weight: 600; }
.meo-store-info-link {
    display: inline-flex; align-items: center; gap: 4px; font-size: 12px;
    color: #568184; text-decoration: none; margin-top: 10px;
}
.meo-store-info-link:hover { text-decoration: underline; }

/* --- Base location UI --- */
.meo-base-location-unset {
    background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px;
    padding: 14px 20px; margin-bottom: 20px; display: none;
}
.meo-base-location-unset.show { display: block; }
.meo-base-location-unset__title {
    font-size: 13px; font-weight: 700; color: #92400e; margin-bottom: 4px;
    display: flex; align-items: center; gap: 6px;
}
.meo-base-location-unset__text {
    font-size: 12px; color: #92400e; line-height: 1.5; margin-bottom: 8px;
}
.meo-base-location-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px;
    border: 1px solid #d0d5dd; border-radius: 8px; font-size: 12px; font-weight: 500;
    cursor: pointer; background: #fff; color: #344054; transition: all 0.15s;
}
.meo-base-location-btn:hover { background: #f9fafb; border-color: #98a2b3; }
.meo-base-change-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 7px 16px; border: 1px solid #d0d5dd; border-radius: 8px;
    font-size: 12px; font-weight: 500; cursor: pointer;
    background: #fff; color: #568184; transition: all 0.15s; white-space: nowrap;
    line-height: 1.3;
}
.meo-base-change-btn:hover { background: #f7fafa; border-color: #a8bfc1; }

/* --- Base location modal --- */
.meo-base-modal__desc {
    font-size: 13px; color: #6b7280; line-height: 1.6; margin-bottom: 16px;
}
.meo-base-modal__field { margin-bottom: 14px; }
.meo-base-modal__label {
    display: block; font-size: 12px; font-weight: 600; color: #344054; margin-bottom: 4px;
}
.meo-base-modal__input {
    width: 100%; padding: 8px 12px; border: 1px solid #d0d5dd; border-radius: 8px;
    font-size: 14px; color: #1a1a1a; box-sizing: border-box;
}
.meo-base-modal__input:focus { border-color: #568184; outline: none; box-shadow: 0 0 0 2px rgba(86,129,132,0.15); }
.meo-base-modal__actions { display: flex; gap: 10px; align-items: center; margin-top: 20px; flex-wrap: wrap; }

/* リセットボタン */
.meo-base-reset-btn {
    color: #6b7280;
    border-color: #d1d5db;
    background: #fff;
}
.meo-base-reset-btn:hover:not(:disabled) {
    color: #4E8A6B;
    border-color: #4E8A6B;
    background: rgba(78,138,107,0.06);
}
.meo-base-reset-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* --- 計測モードプリセット --- */
.meo-base-mode-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 14px 0 10px;
}
.meo-base-mode {
    display: flex;
    gap: 10px;
    padding: 12px 14px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    cursor: pointer;
    background: #fff;
    transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
    align-items: flex-start;
}
.meo-base-mode:hover { border-color: #b8c9c9; background: #fafbfb; }
.meo-base-mode input[type="radio"] {
    margin-top: 4px;
    accent-color: #568184;
    cursor: pointer;
}
.meo-base-mode input[type="radio"]:disabled { cursor: not-allowed; }
.meo-base-mode--disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.meo-base-mode--disabled:hover { border-color: #e5e7eb; background: #fff; }
.meo-base-mode:has(input:checked) {
    border-color: #568184;
    background: rgba(86,129,132,0.06);
    box-shadow: 0 0 0 3px rgba(86,129,132,0.1);
}
.meo-base-mode__body {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.meo-base-mode__title {
    font-size: 13px;
    font-weight: 600;
    color: #1a1a1a;
    line-height: 1.4;
}
.meo-base-mode__desc {
    font-size: 12px;
    color: #6b7280;
    line-height: 1.55;
}
.meo-base-mode__badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 4px;
    margin-left: 4px;
    vertical-align: middle;
    letter-spacing: 0.04em;
}
.meo-base-mode__badge--reco { background: #4E8A6B; color: #fff; }
.meo-base-mode-note {
    display: none;
    margin-bottom: 14px;
    padding: 10px 12px;
    background: rgba(86,129,132,0.08);
    border: 1px solid rgba(86,129,132,0.2);
    border-radius: 8px;
    font-size: 12px;
    color: #1f4143;
    line-height: 1.6;
}
.meo-base-mode-note.is-visible { display: block; }
.meo-base-mode-note--warn {
    background: rgba(201,168,76,0.10);
    border-color: rgba(201,168,76,0.35);
    color: #75590f;
}

/* Responsive */
@media (max-width: 768px) {
    .rt-header { flex-direction: column; align-items: flex-start; }
    .rt-table-wrap { overflow-x: auto; }
    .meo-conditions { flex-direction: column; align-items: flex-start; gap: 16px; padding: 16px 18px; }
    .meo-store-grid { grid-template-columns: 1fr; gap: 4px; }
    .meo-store-label { font-weight: 600; }
    .meo-reviews-summary { flex-direction: column; align-items: flex-start; }
    .meo-store-info-grid { grid-template-columns: 1fr; }
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">

    <!-- Header -->
    <div class="rt-header">
        <div class="rt-header__title">
            <span>&#x1F4CD;</span> マップ順位
        </div>
        <div class="rt-header__actions">
            <button class="rt-btn rt-btn--primary" id="meoFetchAllBtn" type="button">
                <span class="rt-btn__icon">&#x21BB;</span>
                最新の情報を見る
            </button>
            <button class="rt-btn" id="meoExportCsvBtn" type="button" style="display:none;">
                <span class="rt-btn__icon">&#x2193;</span>
                CSV ダウンロード
            </button>
        </div>
    </div>

    <!-- Help -->
    <div class="rt-help">
        Googleマップやローカル検索で、あなたのお店が<strong>何番目に表示されるか</strong>、
        口コミの状況、近くの競合との比較をまとめています。<br>
        <span style="display:inline-block;margin-top:6px;">
            <strong style="color:#1a1a1a;">マップ順位</strong>＝Googleマップ単体での順位。
            <strong style="color:#1a1a1a;">地域順位</strong>＝通常のGoogle検索結果に出る地図枠（ローカルファインダー／3パック）での順位です。
        </span>
    </div>

    <!-- 計測条件エリア -->
    <div class="meo-conditions" id="meoConditions">
        <!-- デバイス -->
        <div class="meo-condition-group">
            <span class="meo-condition-label">表示デバイス</span>
            <div class="meo-device-toggle" id="meoDeviceToggle">
                <button class="meo-device-btn active" data-device="mobile">スマホ</button>
                <button class="meo-device-btn" data-device="desktop">PC</button>
            </div>
        </div>
        <!-- 基準地点 -->
        <div class="meo-condition-group" id="meoRegionGroup">
            <span class="meo-condition-label">基準地点</span>
            <span class="meo-condition-value" id="meoRegion">読み込み中...</span>
        </div>
        <!-- 半径 -->
        <div class="meo-condition-group" id="meoRadiusGroup" style="display:none;">
            <span class="meo-condition-label">半径</span>
            <span class="meo-condition-value" id="meoRadiusDisplay">-</span>
        </div>
        <!-- 地点変更ボタン -->
        <div class="meo-condition-group" id="meoBaseChangeBtnGroup" style="display:none; margin-top:6px;">
            <button class="meo-base-change-btn" id="meoBaseChangeBtn" type="button">&#x1F4CD; 地点変更</button>
        </div>
<?php if ( $maps_domain !== '' ) : ?>
        <!-- 対象ドメイン（GBPドメイン） -->
        <div class="meo-condition-group">
            <span class="meo-condition-label">対象ドメイン（GBPドメイン）</span>
            <span class="meo-condition-value"><?php echo esc_html( $maps_domain ); ?></span>
        </div>
<?php endif; ?>
    </div>

    <!-- 基準地点未設定バナー -->
    <div class="meo-base-location-unset" id="meoBaseUnset">
        <div class="meo-base-location-unset__title">&#x26A0;&#xFE0F; 基準地点が未設定です</div>
        <div class="meo-base-location-unset__text">マップ順位をより正確に計測するため、基準地点を設定してください。ターゲットエリアの中心地（駅前・市役所周辺など）がおすすめです。</div>
        <button class="meo-base-location-btn" id="meoBaseSetBtn" type="button">&#x1F4CD; 基準地点を設定する</button>
    </div>

    <!-- 店舗情報カード -->
    <div class="meo-store-info-card" id="meoStoreInfoCard">
        <div class="meo-store-info-card__header">&#x1F3EA; Googleビジネスプロフィール</div>
        <div class="meo-store-info-grid" id="meoStoreInfoGrid"></div>
        <a href="#" target="_blank" rel="noopener" class="meo-store-info-link" id="meoStoreInfoMapLink" style="display:none;">Googleマップで見る &#x2192;</a>
    </div>

    <!-- Summary cards -->
    <div class="rt-summary-cards" id="summaryCards">
        <div class="rt-summary-card rt-summary-card--gold">
            <span class="rt-summary-card__dot rt-summary-card__dot--gold"></span>
            <span class="rt-summary-card__label">1位〜3位</span>
            <span class="rt-summary-card__count" id="summary13">0<span class="rt-summary-card__unit">件</span></span>
        </div>
        <div class="rt-summary-card rt-summary-card--blue">
            <span class="rt-summary-card__dot rt-summary-card__dot--blue"></span>
            <span class="rt-summary-card__label">4位〜10位</span>
            <span class="rt-summary-card__count" id="summary410">0<span class="rt-summary-card__unit">件</span></span>
        </div>
        <div class="rt-summary-card rt-summary-card--green">
            <span class="rt-summary-card__dot rt-summary-card__dot--green"></span>
            <span class="rt-summary-card__label">11位〜20位</span>
            <span class="rt-summary-card__count" id="summary1120">0<span class="rt-summary-card__unit">件</span></span>
        </div>
        <div class="rt-summary-card rt-summary-card--red">
            <span class="rt-summary-card__dot rt-summary-card__dot--red"></span>
            <span class="rt-summary-card__label">圏外(20位以下)</span>
            <span class="rt-summary-card__count" id="summaryOut">0<span class="rt-summary-card__unit">件</span></span>
        </div>
    </div>

    <!-- Rankings table -->
    <div id="meoTableWrap">
        <div class="meo-loading" id="meoLoading">データを取得中...</div>
        <div class="meo-empty" id="meoEmpty" style="display:none;">
            <div class="meo-empty__icon">&#x1F4CD;</div>
            <div class="meo-empty__text" id="meoEmptyText">MEOデータがまだありません</div>
            <div id="meoEmptyDesc" style="color:#9ca3af; font-size:12px; margin-top:6px;">
                <a href="<?php echo esc_url( home_url( '/rank-tracker/' ) ); ?>" style="color:#568184;">自然検索順位</a>ページでキーワードを登録すると、Googleマップでの順位も確認できます。
            </div>
        </div>
        <div class="rt-table-wrap" id="meoTableContainer" style="display:none;">
            <div class="rt-table-scroll">
                <table class="rt-table" id="meoTable">
                    <thead id="meoTableHead"></thead>
                    <tbody id="meoTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /.content-area -->

<!-- Progress overlay (bulk fetch) -->
<div class="rt-progress-overlay" id="progressOverlay">
    <div class="rt-progress-box">
        <div class="rt-progress-title" id="progressTitle">最新の順位を取得中...</div>
        <div class="rt-progress-bar-wrap">
            <div class="rt-progress-bar rt-progress-bar--indeterminate" id="progressBar"></div>
        </div>
        <div class="rt-progress-text" id="progressText">キーワードの順位を取得しています...</div>
        <div class="rt-progress-sub" id="progressSub">しばらくお待ちください</div>
    </div>
</div>

<!-- Detail modal (store + reviews + competitors) -->
<div class="rt-modal-overlay" id="meoDetailModal">
    <div class="rt-modal">
        <div class="rt-modal__header">
            <div class="rt-modal__title" id="meoDetailModalTitle">詳細情報</div>
            <button class="rt-modal__close" id="meoDetailCloseBtn">&times;</button>
        </div>
        <div class="rt-modal__body" id="meoDetailModalBody">
            <div class="rt-loading">読み込み中...</div>
        </div>
    </div>
</div>

<!-- Base location modal -->
<div class="rt-modal-overlay" id="meoBaseModal">
    <div class="rt-modal" style="max-width:520px;">
        <div class="rt-modal__header">
            <div class="rt-modal__title">基準地点を設定</div>
            <button class="rt-modal__close" id="meoBaseModalClose">&times;</button>
        </div>
        <div class="rt-modal__body">
            <div class="meo-base-modal__desc">
                基準地点は、Googleマップ上での表示順位を計測する起点となる場所です。
                どこを基準にするかで結果の意味が変わります。
            </div>
            <div class="meo-base-mode-group" role="radiogroup" aria-label="計測モード">
                <label class="meo-base-mode" data-mode="city">
                    <input type="radio" name="meoBaseMode" value="city">
                    <span class="meo-base-mode__body">
                        <span class="meo-base-mode__title">🏛 市区町村の中心 <span class="meo-base-mode__badge meo-base-mode__badge--reco">推奨</span></span>
                        <span class="meo-base-mode__desc">市役所・駅前など商圏の中心点を基準にします。<strong>競合と公平に比較できる客観的な指標</strong>です。</span>
                    </span>
                </label>
                <label class="meo-base-mode" data-mode="business">
                    <input type="radio" name="meoBaseMode" value="business">
                    <span class="meo-base-mode__body">
                        <span class="meo-base-mode__title">🏠 自社の住所</span>
                        <span class="meo-base-mode__desc">店舗のすぐ近くにいる人にどう見えているかを確認します。距離スコアの影響で実力以上の順位が出やすい点に注意。</span>
                    </span>
                </label>
                <label class="meo-base-mode" data-mode="custom">
                    <input type="radio" name="meoBaseMode" value="custom" checked>
                    <span class="meo-base-mode__body">
                        <span class="meo-base-mode__title">📍 任意の場所</span>
                        <span class="meo-base-mode__desc">特定エリア（出店候補地・ターゲット商圏など）を手動で指定します。</span>
                    </span>
                </label>
            </div>
            <div class="meo-base-mode-note" id="meoBaseModeNote"></div>
            <div class="meo-base-modal__field">
                <label class="meo-base-modal__label" for="meoBaseLabel">表示名（任意）</label>
                <input class="meo-base-modal__input" type="text" id="meoBaseLabel" placeholder="例: 松山市中心部">
            </div>
            <div class="meo-base-modal__field">
                <label class="meo-base-modal__label" for="meoBaseAddress">住所</label>
                <input class="meo-base-modal__input" type="text" id="meoBaseAddress" placeholder="例: 愛媛県松山市大街道">
                <div style="font-size:11px; color:#9ca3af; margin-top:4px;">住所を入力するか、下の緯度経度を直接指定してください</div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div class="meo-base-modal__field">
                    <label class="meo-base-modal__label" for="meoBaseLat">緯度（任意）</label>
                    <input class="meo-base-modal__input" type="text" id="meoBaseLat" placeholder="例: 33.8395" inputmode="decimal">
                </div>
                <div class="meo-base-modal__field">
                    <label class="meo-base-modal__label" for="meoBaseLng">経度（任意）</label>
                    <input class="meo-base-modal__input" type="text" id="meoBaseLng" placeholder="例: 132.7657" inputmode="decimal">
                </div>
            </div>
            <div style="font-size:11px; color:#6b7280; line-height:1.5; margin-top:4px; margin-bottom:12px;">
                &#x1F4A1; 緯度経度を設定すると、その地点を中心にマップ順位を計測します。<br>
                <a href="https://www.google.com/maps" target="_blank" rel="noopener" style="color:#568184;">Googleマップ</a>で地点を右クリック→座標をコピーして貼り付けできます。
                <br>
                <a href="#" id="meoBaseVerifyLink" target="_blank" rel="noopener"
                   style="display:none; color:#568184; font-size:12px; margin-top:4px; text-decoration:underline;">&#x1F4CD; この地点の場所を確認する</a>
            </div>
            <div class="meo-base-modal__field">
                <label class="meo-base-modal__label" for="meoBaseRadius">検索半径</label>
                <select class="meo-base-modal__input" id="meoBaseRadius" style="max-width:200px;">
                    <option value="500">500m</option>
                    <option value="1000" selected>1km</option>
                    <option value="2000">2km</option>
                    <option value="3000">3km</option>
                    <option value="5000">5km</option>
                    <option value="10000">10km</option>
                    <option value="20000">20km</option>
                    <option value="50000">50km</option>
                </select>
                <div style="font-size:11px; color:#9ca3af; margin-top:4px;">基準地点を中心に、この半径内での順位を計測します</div>
            </div>
            <div class="meo-base-modal__actions">
                <button class="rt-btn meo-base-reset-btn" id="meoBaseResetBtn" type="button" title="市区町村の中心の情報に戻します">&#x21BB; 市の中心に戻す</button>
                <span style="flex:1;"></span>
                <button class="rt-btn" id="meoBaseCancelBtn" type="button">キャンセル</button>
                <button class="rt-btn rt-btn--primary" id="meoBaseSaveBtn" type="button">&#x1F4BE; 保存する</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="rt-toast" id="meoToast"></div>

<?php get_footer(); ?>

<script>
/* ============================================================
   MEO Section v2 — Multi-keyword list
   ============================================================ */
(function() {
    'use strict';

    var restBase = '<?php echo esc_url( rest_url( 'gcrev/v1/' ) ); ?>';
    var nonce    = '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>';
    var basePresets = <?php echo $mw_base_presets; ?>;

    // State
    var currentDevice = 'mobile';
    var meoData       = null; // Full API response
    var keywordsList  = [];   // keyword objects from API
    var dayLabels     = [];
    var dayKeys       = [];
    var summaryData   = {};

    // DOM refs
    var loadingEl     = document.getElementById('meoLoading');
    var emptyEl       = document.getElementById('meoEmpty');
    var tableContainer= document.getElementById('meoTableContainer');
    var thead         = document.getElementById('meoTableHead');
    var tbody         = document.getElementById('meoTableBody');
    var regionEl      = document.getElementById('meoRegion');
    var radiusGroup   = document.getElementById('meoRadiusGroup');

    // =========================================================
    // Init
    // =========================================================
    document.addEventListener('DOMContentLoaded', function() {
        // Device toggle
        var deviceToggle = document.getElementById('meoDeviceToggle');
        if (deviceToggle) {
            deviceToggle.addEventListener('click', function(e) {
                var btn = e.target.closest('.meo-device-btn');
                if (!btn || btn.classList.contains('active')) return;
                deviceToggle.querySelectorAll('.meo-device-btn').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                currentDevice = btn.dataset.device;
                renderSummary();
                renderTable();
            });
        }

        // Fetch all button
        var fetchAllBtn = document.getElementById('meoFetchAllBtn');
        if (fetchAllBtn) {
            fetchAllBtn.addEventListener('click', fetchAllKeywords);
        }

        // Detail modal close
        var closeBtn = document.getElementById('meoDetailCloseBtn');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeDetailModal);
        }
        document.addEventListener('click', function(e) {
            if (e.target.id === 'meoDetailModal') closeDetailModal();
        });

        // Base location modal handlers
        var baseSetBtn = document.getElementById('meoBaseSetBtn');
        var baseChangeBtn = document.getElementById('meoBaseChangeBtn');
        var baseModal = document.getElementById('meoBaseModal');
        var baseModalClose = document.getElementById('meoBaseModalClose');
        var baseCancelBtn = document.getElementById('meoBaseCancelBtn');
        var baseSaveBtn = document.getElementById('meoBaseSaveBtn');

        function openBaseModal() {
            if (!baseModal) return;
            var addrInput = document.getElementById('meoBaseAddress');
            var labelInput = document.getElementById('meoBaseLabel');
            var latInput = document.getElementById('meoBaseLat');
            var lngInput = document.getElementById('meoBaseLng');
            var radiusInput = document.getElementById('meoBaseRadius');
            if (meoData && meoData.location) {
                if (addrInput && meoData.location.address) addrInput.value = meoData.location.address;
                if (labelInput && meoData.location.base_label) labelInput.value = meoData.location.base_label;
                if (latInput && meoData.location.lat) latInput.value = meoData.location.lat;
                if (lngInput && meoData.location.lng) lngInput.value = meoData.location.lng;
                if (radiusInput && meoData.location.radius) radiusInput.value = String(meoData.location.radius);
            }
            // モード別キャッシュを初期化（モーダル開くたびにリセット）
            modeFormCache = {};
            currentBaseMode = null;
            initBaseModeRadios();
            // initBaseModeRadios で初期選択されたモードを currentBaseMode として記録
            var initialChecked = document.querySelector('input[name="meoBaseMode"]:checked');
            currentBaseMode = initialChecked ? initialChecked.value : null;
            // 初期モードのキャッシュには、現在フォームに表示されている保存値を必ず格納する
            // （サーバ側に base_mode が保存されていない古いデータでも復元できるように）
            if (currentBaseMode) {
                modeFormCache[currentBaseMode] = readBaseForm();
            }
            // リセットボタンの利用可否（city プリセットが無いと無効化）
            var resetBtn = document.getElementById('meoBaseResetBtn');
            if (resetBtn) {
                resetBtn.disabled = !(basePresets.city && basePresets.city.available);
            }
            baseModal.classList.add('active');
            updateVerifyLink();
        }

        // =========================================================
        // 計測モードラジオ（市の中心 / 自社住所 / 任意）
        // =========================================================
        // モーダルが開いている間、モード切替時にフォーム入力を失わないよう
        // モード別にフィールド値をキャッシュする。
        var modeFormCache = {};
        var currentBaseMode = null;

        function readBaseForm() {
            return {
                address: (document.getElementById('meoBaseAddress').value || ''),
                label:   (document.getElementById('meoBaseLabel').value || ''),
                lat:     (document.getElementById('meoBaseLat').value || ''),
                lng:     (document.getElementById('meoBaseLng').value || ''),
                radius:  (document.getElementById('meoBaseRadius').value || ''),
            };
        }
        function writeBaseForm(v) {
            if (!v) return;
            document.getElementById('meoBaseAddress').value = v.address || '';
            document.getElementById('meoBaseLabel').value   = v.label || '';
            document.getElementById('meoBaseLat').value     = v.lat || '';
            document.getElementById('meoBaseLng').value     = v.lng || '';
            if (v.radius) document.getElementById('meoBaseRadius').value = v.radius;
        }

        function initBaseModeRadios() {
            var modeGroup = document.querySelector('.meo-base-mode-group');
            if (!modeGroup) return;
            var radios = modeGroup.querySelectorAll('input[name="meoBaseMode"]');
            // 利用可否で disable/enable
            radios.forEach(function(r) {
                var mode = r.value;
                var label = r.closest('.meo-base-mode');
                if (mode === 'business' && (!basePresets.business || !basePresets.business.available)) {
                    r.disabled = true;
                    label && label.classList.add('meo-base-mode--disabled');
                    label && (label.title = '自社住所がまだ登録されていません。');
                }
                if (mode === 'city' && (!basePresets.city || !basePresets.city.available)) {
                    r.disabled = true;
                    label && label.classList.add('meo-base-mode--disabled');
                    label && (label.title = '対象エリア（市区町村）がまだ登録されていません。');
                }
            });
            // 初期選択: ① 保存済み base_mode ② 既存値からの推測 ③ custom
            var savedMode = (meoData && meoData.location && meoData.location.base_mode) ? meoData.location.base_mode : '';
            var initial = '';
            if (savedMode === 'business' || savedMode === 'city' || savedMode === 'custom') {
                initial = savedMode;
            } else {
                var addrVal = (document.getElementById('meoBaseAddress').value || '').trim();
                var biz = basePresets.business && basePresets.business.address ? basePresets.business.address : '';
                var city = basePresets.city && basePresets.city.query ? basePresets.city.query : '';
                if (biz && addrVal === biz) initial = 'business';
                else if (city && addrVal.indexOf(city) === 0) initial = 'city';
                else initial = 'custom';
            }
            modeGroup.querySelectorAll('input[name="meoBaseMode"]').forEach(function(r) {
                r.checked = (r.value === initial && !r.disabled);
            });
            // 保存済みモードが利用不可なら custom にフォールバック
            if (!modeGroup.querySelector('input[name="meoBaseMode"]:checked')) {
                var fallback = modeGroup.querySelector('input[value="custom"]');
                if (fallback) fallback.checked = true;
            }
            updateBaseModeNote();
            // 注: ここでは applyBaseMode を呼ばない（保存済みの住所・座標を上書きしてしまうため）
        }

        // ユーザーが明示的にラジオをクリックした時のみ実行
        function applyBaseMode(mode) {
            var addr = document.getElementById('meoBaseAddress');
            var lat  = document.getElementById('meoBaseLat');
            var lng  = document.getElementById('meoBaseLng');
            var lbl  = document.getElementById('meoBaseLabel');
            if (mode === 'business' && basePresets.business && basePresets.business.address) {
                addr.value = basePresets.business.address;
                lbl.value  = '自社住所';
                // プリセットに lat/lng があればそのまま使う（ジオコーディング不要）
                if (basePresets.business.lat && basePresets.business.lng) {
                    lat.value = basePresets.business.lat;
                    lng.value = basePresets.business.lng;
                } else {
                    lat.value = ''; lng.value = '';
                    addr.dispatchEvent(new Event('blur')); // Nominatim へフォールバック
                }
            } else if (mode === 'city' && basePresets.city && basePresets.city.query) {
                addr.value = basePresets.city.query;
                lbl.value  = basePresets.city.label || '';
                if (basePresets.city.lat && basePresets.city.lng) {
                    lat.value = basePresets.city.lat;
                    lng.value = basePresets.city.lng;
                } else {
                    lat.value = ''; lng.value = '';
                    addr.dispatchEvent(new Event('blur'));
                }
            }
            // mode === 'custom' は何もしない（自由入力に任せる）
            updateBaseModeNote();
            updateVerifyLink();
        }

        function updateBaseModeNote() {
            var note = document.getElementById('meoBaseModeNote');
            if (!note) return;
            var checked = document.querySelector('input[name="meoBaseMode"]:checked');
            var mode = checked ? checked.value : '';
            // フォームに入っている lat/lng を取得（プリセット適用後でも反映できる）
            var latVal = (document.getElementById('meoBaseLat').value || '').trim();
            var lngVal = (document.getElementById('meoBaseLng').value || '').trim();
            var mapLinkHtml = '';
            if (latVal && lngVal && !isNaN(parseFloat(latVal)) && !isNaN(parseFloat(lngVal))) {
                var mapUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latVal + ',' + lngVal);
                mapLinkHtml = '<div style="margin-top:8px;"><a href="' + mapUrl + '" target="_blank" rel="noopener" style="color:#568184;text-decoration:underline;font-weight:600;">📍 この地点をGoogleマップで確認する →</a></div>';
            }
            if (mode === 'business') {
                note.className = 'meo-base-mode-note meo-base-mode-note--warn is-visible';
                note.innerHTML = '⚠️ <strong>自社住所基準</strong>では、Googleマップが「店舗のすぐ近くの人」に向けて表示する結果を計測します。距離スコアの影響で実態より高い順位が出やすく、競合との比較指標としては不正確になる場合があります。' + mapLinkHtml;
            } else if (mode === 'city') {
                note.className = 'meo-base-mode-note is-visible';
                note.innerHTML = '✅ <strong>市区町村中心基準</strong>は、商圏全体のユーザー視点に近い計測ができます。競合との真の力関係や、SEO 施策の効果測定に向いています。' + mapLinkHtml;
            } else if (mode === 'custom') {
                if (mapLinkHtml) {
                    note.className = 'meo-base-mode-note is-visible';
                    note.innerHTML = mapLinkHtml.replace('margin-top:8px;', '');
                } else {
                    note.className = 'meo-base-mode-note';
                    note.innerHTML = '';
                }
            } else {
                note.className = 'meo-base-mode-note';
                note.innerHTML = '';
            }
        }

        document.querySelectorAll('input[name="meoBaseMode"]').forEach(function(r) {
            r.addEventListener('change', function() {
                if (!this.checked) return;
                var newMode = this.value;
                // 切り替え前のモードの入力値を退避
                if (currentBaseMode && currentBaseMode !== newMode) {
                    modeFormCache[currentBaseMode] = readBaseForm();
                }
                // 新モードの入力値が既に退避されていれば復元、なければプリセット適用
                if (modeFormCache[newMode]) {
                    writeBaseForm(modeFormCache[newMode]);
                    updateBaseModeNote();
                    updateVerifyLink();
                } else {
                    applyBaseMode(newMode);
                }
                currentBaseMode = newMode;
            });
        });
        function closeBaseModal() {
            if (baseModal) baseModal.classList.remove('active');
        }

        // 「この地点の場所を確認する」リンクの表示制御
        function updateVerifyLink() {
            var link = document.getElementById('meoBaseVerifyLink');
            var latVal = (document.getElementById('meoBaseLat').value || '').trim();
            var lngVal = (document.getElementById('meoBaseLng').value || '').trim();
            if (!link) return;
            if (latVal && lngVal && !isNaN(parseFloat(latVal)) && !isNaN(parseFloat(lngVal))) {
                link.href = 'https://www.google.com/maps?q=' + encodeURIComponent(latVal + ',' + lngVal);
                link.style.display = 'inline-flex';
            } else {
                link.style.display = 'none';
            }
        }
        var baseLatField = document.getElementById('meoBaseLat');
        var baseLngField = document.getElementById('meoBaseLng');
        if (baseLatField) {
            baseLatField.addEventListener('input', updateVerifyLink);
            baseLatField.addEventListener('input', updateBaseModeNote);
        }
        if (baseLngField) {
            baseLngField.addEventListener('input', updateVerifyLink);
            baseLngField.addEventListener('input', updateBaseModeNote);
        }

        // 住所入力 → Nominatim で緯度経度を自動取得
        var geocodeTimer = null;
        var addrField = document.getElementById('meoBaseAddress');
        if (addrField) {
            addrField.addEventListener('blur', function() {
                var addr = addrField.value.trim();
                var latField = document.getElementById('meoBaseLat');
                var lngField = document.getElementById('meoBaseLng');
                if (!addr || !latField || !lngField) return;
                // 既に手動で入力済みならスキップ
                if (latField.value.trim() && lngField.value.trim()) return;

                latField.setAttribute('placeholder', '取得中...');
                lngField.setAttribute('placeholder', '取得中...');
                clearTimeout(geocodeTimer);
                geocodeTimer = setTimeout(function() {
                    fetch('https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(addr)
                        + '&format=json&limit=1&countrycodes=jp', {
                        headers: { 'Accept': 'application/json' }
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data && data.length > 0 && data[0].lat && data[0].lon) {
                            latField.value = parseFloat(data[0].lat).toFixed(6);
                            lngField.value = parseFloat(data[0].lon).toFixed(6);
                            updateVerifyLink();
                            showToast('住所から座標を自動取得しました');
                        } else {
                            showToast('座標を取得できませんでした。緯度経度を手動で入力してください。', 'error');
                        }
                        latField.setAttribute('placeholder', '例: 33.8395');
                        lngField.setAttribute('placeholder', '例: 132.7657');
                    })
                    .catch(function() {
                        latField.setAttribute('placeholder', '例: 33.8395');
                        lngField.setAttribute('placeholder', '例: 132.7657');
                    });
                }, 300);
            });
        }

        if (baseSetBtn) baseSetBtn.addEventListener('click', openBaseModal);
        if (baseChangeBtn) baseChangeBtn.addEventListener('click', openBaseModal);
        if (baseModalClose) baseModalClose.addEventListener('click', closeBaseModal);
        if (baseCancelBtn) baseCancelBtn.addEventListener('click', closeBaseModal);

        // リセットボタン: 市区町村の中心モードに強制リセット（フォーム入力を初期化）
        var baseResetBtn = document.getElementById('meoBaseResetBtn');
        if (baseResetBtn) {
            baseResetBtn.addEventListener('click', function() {
                if (!basePresets.city || !basePresets.city.available) {
                    showToast('市区町村が登録されていないため初期化できません。', 'error');
                    return;
                }
                // city モードに切替＆フォーム初期化（modeFormCache.city も破棄して常にプリセット適用）
                delete modeFormCache.city;
                var cityRadio = document.querySelector('input[name="meoBaseMode"][value="city"]');
                if (cityRadio && !cityRadio.disabled) {
                    cityRadio.checked = true;
                }
                applyBaseMode('city');
                currentBaseMode = 'city';
                showToast('市区町村の中心に戻しました。「保存する」を押すと反映されます。');
            });
        }
        if (baseModal) {
            baseModal.addEventListener('click', function(e) {
                if (e.target === baseModal) closeBaseModal();
            });
        }
        if (baseSaveBtn) {
            baseSaveBtn.addEventListener('click', function() {
                var address = (document.getElementById('meoBaseAddress').value || '').trim();
                var label = (document.getElementById('meoBaseLabel').value || '').trim();
                var lat = (document.getElementById('meoBaseLat').value || '').trim();
                var lng = (document.getElementById('meoBaseLng').value || '').trim();

                if (!address && !lat && !lng) {
                    showToast('住所または緯度経度を入力してください。', 'error');
                    return;
                }
                if ((lat && !lng) || (!lat && lng)) {
                    showToast('緯度と経度は両方入力してください。', 'error');
                    return;
                }
                if (lat && (isNaN(parseFloat(lat)) || parseFloat(lat) < -90 || parseFloat(lat) > 90)) {
                    showToast('緯度は -90〜90 の数値で入力してください。', 'error');
                    return;
                }
                if (lng && (isNaN(parseFloat(lng)) || parseFloat(lng) < -180 || parseFloat(lng) > 180)) {
                    showToast('経度は -180〜180 の数値で入力してください。', 'error');
                    return;
                }

                baseSaveBtn.disabled = true;
                baseSaveBtn.textContent = '保存中...';

                var radius = document.getElementById('meoBaseRadius').value || '1000';
                var modeRadio = document.querySelector('input[name="meoBaseMode"]:checked');
                var selectedMode = modeRadio ? modeRadio.value : 'custom';
                var payload = { address: address, label: label, radius: parseInt(radius, 10), mode: selectedMode };
                if (lat && lng) {
                    payload.lat = lat;
                    payload.lng = lng;
                }

                fetch(restBase + 'meo/base-location', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(function(resp) { return resp.json(); })
                .then(function(json) {
                    baseSaveBtn.disabled = false;
                    baseSaveBtn.innerHTML = '&#x1F4BE; 保存する';
                    if (json.success) {
                        closeBaseModal();
                        showToast(json.message || '基準地点を保存しました。');
                        // 共通キャッシュは破棄（古いデータで誤表示しないため）
                        if (window.gcrevCache) window.gcrevCache.clear('map_rank');

                        // 新しい地点に対する取得済みスナップショットがあればそれを表示し、サーバ取得しない
                        var savedLat = (json.data && json.data.lat) ? json.data.lat : (lat || '');
                        var savedLng = (json.data && json.data.lng) ? json.data.lng : (lng || '');
                        var savedRadius = (json.data && json.data.radius) ? json.data.radius : payload.radius;
                        var newHash = locationHashFromInputs(selectedMode, savedLat, savedLng, savedRadius);
                        var snap = getLocationSnapshot(newHash);
                        if (snap) {
                            renderFromSnapshot(snap);
                            showToast('この地点で取得済みのデータを表示しています。最新を見るには「最新の情報を見る」を押してください。');
                        } else {
                            // この地点用のキャッシュがない場合、DB は別地点の最新データを返してしまうので
                            // 表示せず「未取得」状態にして「最新の情報を見る」を促す。
                            showUntrackedLocationState(json.data || {
                                address: address, lat: savedLat, lng: savedLng, radius: savedRadius, base_label: label, base_mode: selectedMode
                            });
                        }
                    } else {
                        showToast(json.message || '保存に失敗しました。', 'error');
                    }
                })
                .catch(function() {
                    baseSaveBtn.disabled = false;
                    baseSaveBtn.innerHTML = '&#x1F4BE; 保存する';
                    showToast('通信エラーが発生しました。', 'error');
                });
            });
        }

        // Initial load
        fetchMeoData();
    });

    // =========================================================
    // 地点別キャッシュ（地点を切り替えたとき、その地点で前回取得した
    // データをそのまま再表示できるようにするためのスナップショット保存）
    // =========================================================
    function locationHash(loc) {
        if (!loc) return '';
        var mode = loc.base_mode || (loc.lat && loc.lng ? 'coord' : 'code');
        var lat  = loc.lat ? parseFloat(loc.lat).toFixed(5) : '';
        var lng  = loc.lng ? parseFloat(loc.lng).toFixed(5) : '';
        var rad  = loc.radius || 0;
        return mode + '|' + lat + '|' + lng + '|' + rad;
    }
    function locationHashFromInputs(mode, lat, lng, radius) {
        return locationHash({
            base_mode: mode,
            lat: lat,
            lng: lng,
            radius: parseInt(radius, 10) || 0,
        });
    }
    function saveLocationSnapshot(data) {
        if (!data || !data.location) return;
        var hash = locationHash(data.location);
        if (!hash || hash.indexOf('||') !== -1) return; // lat/lng 未設定はスキップ
        if (window.gcrevCache) window.gcrevCache.set('map_rank_loc:' + hash, data);
    }
    function getLocationSnapshot(hash) {
        if (!hash || !window.gcrevCache) return null;
        return window.gcrevCache.get('map_rank_loc:' + hash);
    }
    // 空状態メッセージを既定に戻す（キーワード未登録時用）
    function resetEmptyMessage() {
        var emptyTextEl = document.getElementById('meoEmptyText');
        var emptyDescEl = document.getElementById('meoEmptyDesc');
        if (emptyTextEl) emptyTextEl.textContent = 'MEOデータがまだありません';
        if (emptyDescEl) emptyDescEl.innerHTML = '<a href="<?php echo esc_url( home_url( '/rank-tracker/' ) ); ?>" style="color:#568184;">自然検索順位</a>ページでキーワードを登録すると、Googleマップでの順位も確認できます。';
    }

    // 地点切替後にスナップショットが無い時の「未取得」状態
    function showUntrackedLocationState(savedLoc) {
        // 上部の基準地点ラベル等は新地点で更新する
        var loc = {
            mode: (savedLoc.lat && savedLoc.lng) ? 'coordinate' : 'location_code',
            address: savedLoc.address || '',
            lat: savedLoc.lat || '',
            lng: savedLoc.lng || '',
            radius: savedLoc.radius || 1000,
            source: 'manual',
            base_label: savedLoc.base_label || '',
            base_mode: savedLoc.base_mode || ''
        };
        // 共通キャッシュは破棄（DB の古い別地点データを再描画しないため）
        if (window.gcrevCache) window.gcrevCache.clear('map_rank');
        // 既存テーブルをクリアして「未取得」状態に切替
        meoData      = { location: loc, keywords: [], summary: {}, days: [], day_labels: [] };
        keywordsList = [];
        dayLabels    = [];
        dayKeys      = [];
        summaryData  = {};
        renderLocation(loc);
        var emptyTextEl = document.getElementById('meoEmptyText');
        var emptyDescEl = document.getElementById('meoEmptyDesc');
        if (emptyTextEl) emptyTextEl.textContent = 'この地点ではまだ計測データがありません';
        if (emptyDescEl) emptyDescEl.innerHTML = '画面右上の <strong>「最新の情報を見る」</strong> ボタンを押すと、新しい基準地点でマップ順位を取得できます。';
        showState('empty');
    }

    function renderFromSnapshot(snap) {
        meoData      = snap;
        keywordsList = snap.keywords || [];
        dayLabels    = snap.day_labels || [];
        dayKeys      = snap.days || [];
        summaryData  = snap.summary || {};
        renderLocation(snap.location);
        renderStoreInfoCard(snap.latest);
        if (keywordsList.length === 0) {
            showState('empty');
        } else {
            showState('table');
            renderSummary();
            renderTable();
        }
        // 共通キャッシュも上書きしておく（次回開いた時にも即時表示できるように）
        if (window.gcrevCache) window.gcrevCache.set('map_rank', snap);
    }

    // =========================================================
    // Fetch data (single API call)
    // =========================================================
    function fetchMeoData() {
        // Stale-While-Revalidate:
        // 1) キャッシュがあれば即時表示（速度のため）
        // 2) その後、必ずサーバへ最新を取りに行き、内容に差があれば更新する
        var cacheKey = 'map_rank';
        var cached = window.gcrevCache && window.gcrevCache.get(cacheKey);
        var hadCache = false;
        if (cached) {
            hadCache = true;
            meoData      = cached;
            keywordsList = cached.keywords || [];
            dayLabels    = cached.day_labels || [];
            dayKeys      = cached.days || [];
            summaryData  = cached.summary || {};
            renderLocation(cached.location);
            renderStoreInfoCard(cached.latest);
            if (keywordsList.length === 0) {
                resetEmptyMessage();
                showState('empty');
            } else {
                showState('table');
                renderSummary();
                renderTable();
            }
        } else {
            showState('loading');
        }

        fetch(restBase + 'meo/history', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(json) {
            if (json.success && json.data) {
                // 既に同一内容を表示済みならスキップ（無駄な再描画を防ぐ）
                var cachedJson = hadCache ? JSON.stringify(cached) : null;
                var serverJson = JSON.stringify(json.data);
                if (hadCache && cachedJson === serverJson) {
                    return;
                }

                meoData      = json.data;
                keywordsList = json.data.keywords || [];
                dayLabels    = json.data.day_labels || [];
                dayKeys      = json.data.days || [];
                summaryData  = json.data.summary || {};

                // キャッシュ更新
                if (window.gcrevCache) window.gcrevCache.set(cacheKey, json.data);
                // 地点別スナップショットも保存
                saveLocationSnapshot(json.data);

                // Location info
                renderLocation(json.data.location);

                // Store info card
                renderStoreInfoCard(json.data.latest);

                if (keywordsList.length === 0) {
                    resetEmptyMessage();
                    showState('empty');
                    return;
                }

                showState('table');
                renderSummary();
                renderTable();
            } else if (!hadCache) {
                // キャッシュ無しでサーバも空 → empty。キャッシュがあれば既に表示済みなので維持
                resetEmptyMessage();
                showState('empty');
            }
        })
        .catch(function(err) {
            console.error('[GCREV][MEO]', err);
            // キャッシュがあれば既に表示中なので維持。無ければ empty。
            if (!hadCache) {
                resetEmptyMessage();
                showState('empty');
            }
        });
    }

    // =========================================================
    // Location rendering + base location UI
    // =========================================================
    function renderLocation(loc) {
        if (!loc || !regionEl) return;

        var baseUnset = document.getElementById('meoBaseUnset');
        var changeBtnGroup = document.getElementById('meoBaseChangeBtnGroup');
        var radiusDisplay = document.getElementById('meoRadiusDisplay');
        var hasAddress = loc.address && loc.address !== '';

        if (hasAddress) {
            // 基準地点が設定済み
            if (loc.mode === 'coordinate') {
                if (loc.source === 'city_center') {
                    regionEl.innerHTML = escHtml(loc.address)
                        + ' <span style="font-size:11px;color:#999;font-weight:400;">(自動設定)</span>';
                } else {
                    regionEl.textContent = loc.address;
                }
                // 半径を表示テキストとして設定
                if (radiusGroup) radiusGroup.style.display = '';
                if (radiusDisplay) radiusDisplay.textContent = formatRadius(loc.radius || 1000);
            } else {
                regionEl.textContent = loc.address;
                if (radiusGroup) radiusGroup.style.display = 'none';
            }
            if (changeBtnGroup) changeBtnGroup.style.display = '';
            if (baseUnset) baseUnset.classList.remove('show');
        } else {
            // 基準地点が未設定
            regionEl.textContent = '未設定';
            if (radiusGroup) radiusGroup.style.display = 'none';
            if (changeBtnGroup) changeBtnGroup.style.display = 'none';
            if (baseUnset) baseUnset.classList.add('show');
        }
    }

    function formatRadius(meters) {
        if (meters >= 1000) return (meters / 1000) + 'km';
        return meters + 'm';
    }

    // =========================================================
    // Store info card (above table)
    // =========================================================
    function renderStoreInfoCard(latest) {
        var card = document.getElementById('meoStoreInfoCard');
        var grid = document.getElementById('meoStoreInfoGrid');
        var mapLink = document.getElementById('meoStoreInfoMapLink');
        if (!card || !grid) return;

        if (!latest || !latest.store) {
            card.classList.remove('show');
            return;
        }

        var store = latest.store;
        var items = [];

        if (store.title) items.push(['店舗名', escHtml(store.title)]);
        if (store.rating != null) {
            items.push(['Google評価', '<span style="color:#f59e0b;">' + meoStarsMini(store.rating) + '</span> ' + parseFloat(store.rating).toFixed(1)]);
        }
        if (store.category) items.push(['カテゴリ', escHtml(store.category)]);
        if (store.reviews_count != null) items.push(['口コミ件数', store.reviews_count + '件']);

        if (items.length === 0) {
            card.classList.remove('show');
            return;
        }

        var html = '';
        items.forEach(function(item) {
            html += '<div class="meo-store-info-item">'
                  + '<span class="meo-store-info-label">' + item[0] + ':</span>'
                  + '<span class="meo-store-info-val">' + item[1] + '</span>'
                  + '</div>';
        });
        grid.innerHTML = html;

        if (store.maps_url && mapLink) {
            mapLink.href = store.maps_url;
            mapLink.style.display = '';
        } else if (mapLink) {
            mapLink.style.display = 'none';
        }

        card.classList.add('show');
    }

    // =========================================================
    // Summary cards
    // =========================================================
    function renderSummary() {
        var s = summaryData[currentDevice] || { rank_1_3: 0, rank_4_10: 0, rank_11_20: 0, rank_out: 0 };
        setSummaryText('summary13', s.rank_1_3);
        setSummaryText('summary410', s.rank_4_10);
        setSummaryText('summary1120', s.rank_11_20);
        setSummaryText('summaryOut', s.rank_out);
    }

    function setSummaryText(id, val) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = val + '<span class="rt-summary-card__unit">件</span>';
    }

    // =========================================================
    // Keywords table
    // =========================================================
    function renderTable() {
        var exportBtn = document.getElementById('meoExportCsvBtn');
        if (!keywordsList || keywordsList.length === 0) {
            if (exportBtn) exportBtn.style.display = 'none';
            showState('empty');
            return;
        }
        if (exportBtn) exportBtn.style.display = 'inline-flex';

        // Build header
        var mapsRankTip = 'Googleマップ単体（マップアプリ／PCの「マップ」タブ）で検索したときの表示順位です。<br>地図そのものから探すユーザーにどう見えているかを表します。';
        var finderRankTip = '通常のGoogle検索結果の途中に表示される「地図付きローカル枠（ローカルファインダー／3パック）」での順位です。<br>普通にGoogleで検索したとき、地図枠でどう見えているかを表します。';
        var hHtml = '<tr>';
        hHtml += '<th>キーワード</th>';
        hHtml += '<th><span class="rt-th-help" tabindex="0">マップ順位<span class="rt-th-help__icon" aria-hidden="true">?</span><span class="rt-th-help__tip" role="tooltip">' + mapsRankTip + '</span></span></th>';
        hHtml += '<th><span class="rt-th-help" tabindex="0">地域順位<span class="rt-th-help__icon" aria-hidden="true">?</span><span class="rt-th-help__tip" role="tooltip">' + finderRankTip + '</span></span></th>';
        for (var d = 0; d < dayLabels.length; d++) {
            hHtml += '<th style="text-align:center;">' + dayLabels[d] + '</th>';
        }
        hHtml += '<th>操作</th>';
        hHtml += '</tr>';
        thead.innerHTML = hHtml;

        // Build body
        var html = '';
        for (var i = 0; i < keywordsList.length; i++) {
            var kw = keywordsList[i];
            var cur = kw.current ? kw.current[currentDevice] : null;
            var daily = kw.daily ? kw.daily[currentDevice] : {};

            // Determine accent color based on maps_rank
            var accent = getAccentClass(cur);

            html += '<tr>';

            // Keyword name
            html += '<td>';
            html += '<div class="rt-rank-accent ' + accent + '"></div>';
            html += '<div class="rt-kw-name">' + escHtml(kw.keyword) + '</div>';
            html += '</td>';

            // Maps rank (current)
            html += '<td>';
            html += formatCurrentRank(cur, 'maps_rank');
            html += '</td>';

            // Finder rank (current)
            html += '<td>';
            html += formatSimpleRank(cur, 'finder_rank');
            html += '</td>';

            // Daily columns (7 days) — maps_rank
            for (var d = 0; d < dayKeys.length; d++) {
                var dayData = daily ? daily[dayKeys[d]] : null;
                html += '<td class="rt-daily">' + formatDailyMapsRank(dayData) + '</td>';
            }

            // Actions
            html += '<td>';
            html += '<button class="rt-action-link" data-keyword-id="' + kw.keyword_id + '" onclick="meoOpenDetail(' + kw.keyword_id + ')">';
            html += '<span class="rt-action-link__icon">&#x1F50D;</span> 詳細を見る';
            html += '</button>';
            html += '</td>';

            html += '</tr>';
        }

        tbody.innerHTML = html;
    }

    // =========================================================
    // CSV export
    // =========================================================
    function escapeCsv(str) {
        return String(str == null ? '' : str).replace(/"/g, '""');
    }

    function rankCellForCsv(rank) {
        if (rank === null || rank === undefined) return '圏外';
        return rank;
    }

    window.exportMeoCsv = function() {
        if (!keywordsList || keywordsList.length === 0) return;

        var bom = "\uFEFF";
        var loc = (meoData && meoData.location) ? meoData.location : {};
        var baseModeMap = { business: '店舗住所', address: '指定住所', coord: '指定座標', custom: 'カスタム' };
        var baseModeLabel = baseModeMap[loc.base_mode] || loc.base_mode || '';
        var radiusLabel = loc.radius
            ? (loc.radius >= 1000 ? (loc.radius / 1000) + 'km' : loc.radius + 'm')
            : '';
        var coordLabel = (loc.lat && loc.lng) ? (loc.lat + ', ' + loc.lng) : '';
        var nowStr = new Date().toISOString().slice(0, 16).replace('T', ' ');

        var headerCols = [
            'キーワード',
            'デバイス',
            'マップ順位（現在）',
            '地域順位（現在）',
            '前回比'
        ];
        for (var d = 0; d < dayLabels.length; d++) {
            headerCols.push(dayLabels[d] + '（マップ順位）');
        }
        headerCols.push('最終取得日');

        var lines = [];
        // ===== 基準地点メタ情報 =====
        lines.push('"# 基準地点情報"');
        lines.push('"項目","値"');
        lines.push('"基準地点ラベル","' + escapeCsv(loc.base_label || '') + '"');
        lines.push('"基準地点（住所）","' + escapeCsv(loc.address || '') + '"');
        lines.push('"基準地点（緯度・経度）","' + escapeCsv(coordLabel) + '"');
        lines.push('"基準地点モード","' + escapeCsv(baseModeLabel) + '"');
        lines.push('"基準地点からの検索半径","' + escapeCsv(radiusLabel) + '"');
        lines.push('"出力日時","' + escapeCsv(nowStr) + '"');
        lines.push('');

        // ===== キーワード順位データ（スマホ・PC 両方） =====
        lines.push('"# キーワード順位"');
        lines.push(headerCols.map(function(c) { return '"' + escapeCsv(c) + '"'; }).join(','));

        var devices = [
            { key: 'mobile',  label: 'スマホ' },
            { key: 'desktop', label: 'PC' }
        ];

        for (var i = 0; i < keywordsList.length; i++) {
            var kw = keywordsList[i];

            for (var di = 0; di < devices.length; di++) {
                var dev = devices[di];
                var cur = kw.current ? kw.current[dev.key] : null;
                var daily = kw.daily ? kw.daily[dev.key] : {};

                var mapsRank = (cur && cur.is_ranked) ? rankCellForCsv(cur.maps_rank) : (cur ? '圏外' : '未取得');
                var finderRank = (cur && cur.finder_rank != null) ? cur.finder_rank : (cur ? '圏外' : '未取得');
                var change = (cur && cur.change != null) ? cur.change : '';
                var fetchedAt = (cur && cur.fetched_at) ? cur.fetched_at : (kw.fetched_at || '');

                var row = [];
                row.push('"' + escapeCsv(kw.keyword) + '"');
                row.push('"' + escapeCsv(dev.label) + '"');
                row.push(mapsRank);
                row.push(finderRank);
                row.push(change);
                for (var dd = 0; dd < dayKeys.length; dd++) {
                    var dayData = daily ? daily[dayKeys[dd]] : null;
                    if (!dayData) {
                        row.push('');
                    } else if (dayData.maps_rank == null) {
                        row.push('圏外');
                    } else {
                        row.push(dayData.maps_rank);
                    }
                }
                row.push('"' + escapeCsv(fetchedAt) + '"');

                lines.push(row.join(','));
            }
        }

        var blob = new Blob([bom + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'map-rank-' + new Date().toISOString().slice(0, 10) + '.csv';
        link.click();
    };

    // ボタンにイベントを紐付け（DOM が既に構築済みでも対応）
    (function() {
        function bindCsvBtn() {
            var btn = document.getElementById('meoExportCsvBtn');
            if (btn && !btn.dataset.bound) {
                btn.addEventListener('click', window.exportMeoCsv);
                btn.dataset.bound = '1';
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bindCsvBtn);
        } else {
            bindCsvBtn();
        }
    })();

    // =========================================================
    // Format helpers
    // =========================================================
    function getAccentClass(cur) {
        if (!cur || !cur.is_ranked || cur.maps_rank == null) return 'rt-rank-accent--red';
        var r = cur.maps_rank;
        if (r <= 3) return 'rt-rank-accent--gold';
        if (r <= 10) return 'rt-rank-accent--blue';
        if (r <= 20) return 'rt-rank-accent--green';
        return 'rt-rank-accent--red';
    }

    function formatCurrentRank(cur, key) {
        if (!cur) return '<span class="rt-rank--na">-</span>';
        var rank = cur[key];
        if (rank == null) return '<span class="rt-rank--out">圏外</span>';

        var html = '<span class="rt-rank">' + rank + '<span class="rt-rank-unit">位</span></span>';

        // Change indicator (only for maps_rank)
        if (key === 'maps_rank' && cur.change != null) {
            if (cur.change === 0) {
                html += '<div class="rt-rank-change rt-rank-change--same">&#x2192;</div>';
            } else {
                html += '<div class="rt-rank-change ' + (cur.change > 0 ? 'rt-rank-change--up' : 'rt-rank-change--down') + '">';
                if (cur.change === 999) {
                    html += '&#x2191; NEW';
                } else if (cur.change === -999) {
                    html += '&#x2193; 圏外';
                } else if (cur.change > 0) {
                    html += '&#x2191; ' + cur.change;
                } else {
                    html += '&#x2193; ' + Math.abs(cur.change);
                }
                html += '</div>';
            }
        }
        return html;
    }

    function formatSimpleRank(cur, key) {
        if (!cur) return '<span class="rt-rank--na">-</span>';
        var rank = cur[key];
        if (rank == null) return '<span class="rt-rank--out">圏外</span>';
        return '<span class="rt-rank">' + rank + '<span class="rt-rank-unit">位</span></span>';
    }

    function formatDailyMapsRank(dayData) {
        if (!dayData) return '<span class="rt-daily--na">-</span>';
        if (dayData.maps_rank == null) return '<span class="rt-daily--out">圏外</span>';
        return dayData.maps_rank + '位';
    }

    function meoStarsMini(val) {
        var r = Math.round(parseFloat(val) || 0);
        var s = '';
        for (var i = 1; i <= 5; i++) s += (i <= r) ? '\u2605' : '\u2606';
        return s;
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // =========================================================
    // State management
    // =========================================================
    function showState(state) {
        loadingEl.style.display = state === 'loading' ? '' : 'none';
        emptyEl.style.display   = state === 'empty' ? '' : 'none';
        tableContainer.style.display = state === 'table' ? '' : 'none';
    }

    // =========================================================
    // Detail modal
    // =========================================================
    window.meoOpenDetail = function(keywordId) {
        var modal = document.getElementById('meoDetailModal');
        var titleEl = document.getElementById('meoDetailModalTitle');
        var bodyEl = document.getElementById('meoDetailModalBody');
        if (!modal) return;

        // Find keyword name
        var kwName = '';
        for (var i = 0; i < keywordsList.length; i++) {
            if (keywordsList[i].keyword_id === keywordId) {
                kwName = keywordsList[i].keyword;
                break;
            }
        }
        titleEl.textContent = '「' + kwName + '」の詳細情報';
        bodyEl.innerHTML = '<div class="rt-loading">データを取得中...</div>';
        modal.classList.add('active');

        // Fetch detail data from meo/rankings (cache_only: 外部API接続しない)
        var url = restBase + 'meo/rankings?device=' + encodeURIComponent(currentDevice)
                + '&keyword_id=' + encodeURIComponent(keywordId)
                + '&cache_only=1';

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (data && data.success !== false) {
                if (data.cache_only) {
                    bodyEl.innerHTML = '<div class="rt-loading" style="color:#9ca3af;">' + escHtml(data.message || 'キャッシュデータがありません。') + '</div>';
                } else {
                    renderDetailModal(data, bodyEl);
                }
            } else {
                bodyEl.innerHTML = '<div class="rt-loading" style="color:#ef4444;">' + escHtml(data.message || 'データの取得に失敗しました。') + '</div>';
            }
        })
        .catch(function(err) {
            console.error('[GCREV][MEO Detail]', err);
            bodyEl.innerHTML = '<div class="rt-loading" style="color:#ef4444;">通信エラーが発生しました。</div>';
        });
    };

    function renderDetailModal(data, bodyEl) {
        var html = '';

        // Store info
        if (data.maps && data.maps.store) {
            html += renderStoreHtml(data.maps.store);
            html += renderReviewsHtml(data.maps.store);
        }

        // Competitors
        if (data.maps && data.maps.competitors && data.maps.competitors.length > 0) {
            html += renderCompetitorsHtml(data.maps.competitors);
        }

        if (html === '') {
            html = '<div class="rt-loading" style="color:#9ca3af;">詳細データがありません。「最新の情報を見る」でデータを取得してください。</div>';
        }

        bodyEl.innerHTML = html;
    }

    function renderStoreHtml(store) {
        var rows = [];
        if (store.title)    rows.push(['店舗名', escHtml(store.title)]);
        if (store.category) rows.push(['カテゴリ', escHtml(store.category)]);
        if (store.address)  rows.push(['住所', escHtml(store.address)]);
        if (store.phone)    rows.push(['電話番号', escHtml(store.phone)]);
        if (store.work_hours) rows.push(['営業時間', escHtml(store.work_hours)]);

        if (rows.length === 0) return '';

        var html = '<div class="meo-store-card">'
                 + '<div class="meo-store-card__title">\uD83C\uDFEA 店舗情報</div>'
                 + '<div class="meo-store-grid">';
        rows.forEach(function(r) {
            html += '<div class="meo-store-label">' + r[0] + '</div>'
                  + '<div class="meo-store-value">' + r[1] + '</div>';
        });
        html += '</div>';

        if (store.maps_url) {
            html += '<a href="' + escHtml(store.maps_url) + '" target="_blank" rel="noopener" class="meo-store-link">'
                  + 'Googleマップで見る \u2192</a>';
        }

        html += '</div>';
        return html;
    }

    function renderReviewsHtml(store) {
        if (store.rating == null) return '';

        var rating = parseFloat(store.rating) || 0;
        var total = store.reviews_count || 0;
        var dist = store.rating_distribution || {};

        var stars = '';
        for (var i = 1; i <= 5; i++) {
            stars += (i <= Math.round(rating)) ? '\u2605' : '\u2606';
        }

        var barsHtml = '';
        for (var s = 5; s >= 1; s--) {
            var cnt = parseInt(dist[s] || dist[String(s)] || 0, 10);
            var pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
            barsHtml += '<div class="meo-rating-bar-row">'
                      + '<div class="meo-rating-bar-label">' + s + '\u2605</div>'
                      + '<div class="meo-rating-bar-track"><div class="meo-rating-bar-fill" style="width:' + pct + '%"></div></div>'
                      + '<div class="meo-rating-bar-count">' + cnt + '件</div>'
                      + '</div>';
        }

        return '<div class="meo-reviews-card">'
             + '<div class="meo-reviews-card__title">\uD83D\uDCAC 口コミの状況</div>'
             + '<div class="meo-reviews-summary">'
             + '<span class="meo-reviews-big-rating">' + rating.toFixed(1) + '</span>'
             + '<span class="meo-reviews-stars">' + stars + '</span>'
             + '<span class="meo-reviews-count">' + total + '件の口コミ</span>'
             + '</div>'
             + '<div class="meo-rating-bars">' + barsHtml + '</div>'
             + '</div>';
    }

    function renderCompetitorsHtml(competitors) {
        var html = '<div class="meo-competitor-wrap">'
                 + '<div class="meo-competitor-title">\uD83C\uDFC6 近くの競合との比較</div>'
                 + '<div class="meo-table-scroll">'
                 + '<table class="meo-competitor-table">'
                 + '<thead><tr>'
                 + '<th>店舗名</th><th>マップ順位</th><th>評価</th><th>口コミ数</th>'
                 + '</tr></thead><tbody>';

        competitors.forEach(function(c) {
            var rowCls = c.is_self ? ' class="meo-self-row"' : '';
            var name = escHtml(c.title || '');
            if (c.is_self) name += '<span class="meo-self-badge">自社</span>';

            var rank = c.rank ? c.rank + '位' : '圏外';
            var rating = c.rating != null
                ? '<span class="meo-stars-sm">' + meoStarsMini(c.rating) + '</span> ' + parseFloat(c.rating).toFixed(1)
                : '-';
            var reviews = c.reviews_count != null ? c.reviews_count + '件' : '-';

            html += '<tr' + rowCls + '>'
                  + '<td>' + name + '</td>'
                  + '<td>' + rank + '</td>'
                  + '<td>' + rating + '</td>'
                  + '<td>' + reviews + '</td>'
                  + '</tr>';
        });

        html += '</tbody></table></div></div>';
        return html;
    }

    function closeDetailModal() {
        var modal = document.getElementById('meoDetailModal');
        if (modal) modal.classList.remove('active');
    }

    // =========================================================
    // Fetch all keywords (bulk) — mobile + desktop
    // =========================================================
    function fetchAllKeywords() {
        if (!keywordsList || keywordsList.length === 0) {
            // If no data yet, try a single fetch first
            fetchMeoData();
            return;
        }

        var btn = document.getElementById('meoFetchAllBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="rt-btn__icon">&#x22EF;</span> 最新情報を取得中...';

        var devices = ['mobile', 'desktop'];
        var deviceLabels = { mobile: 'スマホ版', desktop: 'PC版' };
        var totalSteps = keywordsList.length * devices.length;
        showProgress(true, totalSteps);

        var completed = 0;
        var errors = 0;
        var stepIndex = 0;

        // Build sequential task list: [{ kw, device }, ...]
        var tasks = [];
        for (var k = 0; k < keywordsList.length; k++) {
            for (var d = 0; d < devices.length; d++) {
                tasks.push({ kw: keywordsList[k], device: devices[d] });
            }
        }

        function fetchNext(index) {
            if (index >= tasks.length) {
                // All done
                showProgressComplete(completed, errors);
                setTimeout(function() {
                    showProgress(false);
                    btn.disabled = false;
                    btn.innerHTML = '<span class="rt-btn__icon">&#x21BB;</span> 最新の情報を見る';
                    var msg = completed + '件の順位データを取得しました。';
                    if (errors > 0) msg += '（' + errors + '件のエラー）';
                    showToast(msg, errors > 0 ? 'error' : '');
                    // 古いキャッシュを破棄してから再取得（force=1 で DB 更新済みなので必須）
                    if (window.gcrevCache) window.gcrevCache.clear('map_rank');
                    // 現在の地点に対するスナップショットも一旦無効化（fetchMeoData が新データで上書き保存する）
                    if (window.gcrevCache && meoData && meoData.location) {
                        var curHash = locationHash(meoData.location);
                        if (curHash) window.gcrevCache.clear('map_rank_loc:' + curHash);
                    }
                    fetchMeoData(); // Reload table
                }, 1200);
                return;
            }

            var task = tasks[index];
            var stepNum = index + 1;
            var deviceLabel = deviceLabels[task.device];
            updateProgressTextDual(stepNum, totalSteps, task.kw.keyword, deviceLabel);

            var url = restBase + 'meo/rankings?device=' + encodeURIComponent(task.device)
                    + '&keyword_id=' + encodeURIComponent(task.kw.keyword_id)
                    + '&force=1';

            fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce }
            })
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (data && data.success !== false) {
                    completed++;
                } else {
                    errors++;
                }
                updateProgressBar(stepNum, totalSteps);
                fetchNext(index + 1);
            })
            .catch(function() {
                errors++;
                updateProgressBar(stepNum, totalSteps);
                fetchNext(index + 1);
            });
        }

        fetchNext(0);
    }

    // =========================================================
    // Progress overlay
    // =========================================================
    function showProgress(show, totalSteps) {
        var overlay = document.getElementById('progressOverlay');
        if (!overlay) return;
        if (show) {
            var titleEl = document.getElementById('progressTitle');
            var textEl = document.getElementById('progressText');
            var subEl = document.getElementById('progressSub');
            var barEl = document.getElementById('progressBar');
            if (titleEl) titleEl.textContent = 'スマホ版・PC版の最新データを取得中...';
            if (textEl) textEl.textContent = totalSteps + '件の順位データを取得します...';
            if (subEl) subEl.textContent = '1キーワードあたり数秒かかります。しばらくお待ちください。';
            if (barEl) {
                barEl.style.width = '0%';
                barEl.classList.remove('rt-progress-bar--indeterminate');
            }
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    }

    function updateProgressBar(current, total) {
        var barEl = document.getElementById('progressBar');
        if (barEl) {
            var pct = Math.round((current / total) * 100);
            barEl.style.width = pct + '%';
        }
    }

    function updateProgressTextDual(current, total, keyword, deviceLabel) {
        var textEl = document.getElementById('progressText');
        if (textEl) textEl.textContent = '「' + keyword + '」' + deviceLabel + ' 取得中 (' + current + '/' + total + ')';
    }

    function showProgressComplete(count, errors) {
        var titleEl = document.getElementById('progressTitle');
        var textEl = document.getElementById('progressText');
        var subEl = document.getElementById('progressSub');
        var barEl = document.getElementById('progressBar');
        if (titleEl) titleEl.textContent = '取得完了!';
        var msg = count + '件の順位データを取得しました。';
        if (errors > 0) msg += '（' + errors + '件のエラー）';
        if (textEl) textEl.textContent = msg;
        if (subEl) subEl.textContent = '';
        if (barEl) barEl.style.width = '100%';
    }

    // =========================================================
    // Toast
    // =========================================================
    function showToast(msg, type) {
        var toast = document.getElementById('meoToast');
        if (!toast) return;
        toast.textContent = msg;
        toast.className = 'rt-toast' + (type === 'error' ? ' rt-toast--error' : '');
        setTimeout(function() { toast.classList.add('show'); }, 10);
        setTimeout(function() { toast.classList.remove('show'); }, 4000);
    }

})();
</script>
