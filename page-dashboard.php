<?php
/*
Template Name: ダッシュボード
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// サービスティア判定
$can_ai         = mimamori_can( 'ai_chat', $user_id );
$can_highlights = mimamori_can( 'dashboard_highlights', $user_id );

// ページタイトル設定
set_query_var('gcrev_page_title', '全体のようす');
set_query_var('gcrev_page_subtitle', 'このホームページが、今どんな状態かをひと目で確認できます。');

// パンくず設定（ダッシュボードは2階層: ホーム › 全体のようす）
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('全体のようす'));

// APIインスタンス初期化（日付計算より先に必要）
global $gcrev_api_instance;
if ( ! isset($gcrev_api_instance) || ! ($gcrev_api_instance instanceof Gcrev_Insight_API) ) {
    $gcrev_api_instance = new Gcrev_Insight_API(false);
}
$gcrev_api = $gcrev_api_instance;

// 直近30日（ダッシュボード主軸）
$tz = wp_timezone();
$date_helper = new Gcrev_Date_Helper();
$last30      = $date_helper->get_date_range('last30');
$last30_comp = $date_helper->get_comparison_range($last30['start'], $last30['end']);

// 月次レポート/インフォグラフィック用（従来どおり）
$prev_month_start = new DateTimeImmutable('first day of last month', $tz);
$prev_month_end   = new DateTimeImmutable('last day of last month', $tz);
$year  = (int)$prev_month_start->format('Y');
$month = (int)$prev_month_start->format('n');


/**
 * レポートテキスト装飾（結論サマリー表示用）
 */
if ( ! function_exists('enhance_report_text') ) {

function enhance_report_text($text, $color_mode = 'default', $auto_head_bold = true) {
    if ($text === null || $text === '') return '';

    // 配列対策
    if (is_array($text)) {
        if (isset($text['description']) && is_string($text['description'])) {
            $text = $text['description'];
        } elseif (isset($text['title']) && is_string($text['title'])) {
            $text = $text['title'];
        } else {
            $text = wp_json_encode($text, JSON_UNESCAPED_UNICODE);
        }
    }
    if (!is_string($text)) $text = (string)$text;

    // HTML除去
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = str_replace('**', '', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    // 色モード
    $color = match($color_mode) {
        'white'  => '#ffffff',
        'green'  => '#16a34a',
        'red'    => '#C95A4F',
        'blue'   => '#568184',
        'orange' => '#ea580c',
        default  => '#111827'
    };

    // ==================================================
    // ✅ 先頭ラベル太字（必要なときだけ）
    // ==================================================
    if ($auto_head_bold) {
        $text = preg_replace(
            '/^(.{2,80}?[：:])\s*/u',
            '<span class="point-head">$1</span> ',
            $text,
            1
        );
    }

    // ==================================================
    // ✅ 数字＋単位を太字
    // ==================================================
    $unit_pattern = '(?:PV|ページビュー|セッション|ユーザー|新規ユーザー|クリック|表示|回|件|秒|分|時間|円|%|％|位|ページ|日|月|年|歳|人|社|ヶ所|か所|km|m|cm|mm|GB|MB|KB)';
    $text = preg_replace_callback(
    '/(?<![A-Za-z])([\+\-]?\d{1,3}(?:,\d{3})*(?:\.\d+)?)(\s*)(' . $unit_pattern . ')?/u',
        function($m) use ($color) {
            $num  = $m[1];
            $sp   = $m[2] ?? '';
            $unit = $m[3] ?? '';
            $val = $unit !== '' ? ($num . $unit) : $num;

            return '<strong style="color:' . $color . ';font-weight:800;">' . $val . '</strong>' . ($unit !== '' ? '' : $sp);
        },
        $text
    );

    // ==================================================
    // ✅ キーワード強調
    // ==================================================
    if ($color_mode !== 'white') {
        $keywords = [
            '増加' => '#16a34a',
            '改善' => '#16a34a',
            '減少' => '#C95A4F',
            '悪化' => '#C95A4F',
            '前月比' => '#568184',
            '前年比' => '#568184',
        ];
        foreach ($keywords as $kw => $kw_color) {
            $text = preg_replace(
                '/' . preg_quote($kw, '/') . '/u',
                '<strong style="color:' . $kw_color . ';font-weight:800;">' . $kw . '</strong>',
                $text
            );
        }
    }

    return $text;
}
}



get_header();
?>
<style>
/* =========================================================
   Dashboard - Page-specific overrides only
   Core styles are in css/dashboard-redesign.css
   ========================================================= */

/* サービスコンセプト — 常時表示・一段だけ目立たせる */
.service-lead {
  margin: 0 0 28px;
  font-size: 15.5px;
  font-weight: 600;
  line-height: 2;
  color: #3b3b3b;
  letter-spacing: 0.04em;
}

/* Container: position relative for corner CTA */
.dashboard-infographic {
  position: relative;
}

/* KPI trend inline responsive (page-specific) */
@media (max-width: 600px) {
  .kpi-trend-chart-wrap { height: 200px; }
  .kpi-trend-inline-title { font-size: 13px; }
  .kpi-trend-inline-header { flex-direction: column; align-items: flex-start; gap: 4px; }
  .kpi-trend-toggle { width: 100%; justify-content: flex-end; }
}

/* KPI trend period toggle */
.kpi-trend-toggle {
  display: flex;
  gap: 0;
  margin-left: auto;
  border: 1px solid #d0d0d0;
  border-radius: 6px;
  overflow: hidden;
}
.kpi-trend-toggle-btn {
  background: #fff;
  border: none;
  padding: 5px 14px;
  font-size: 12px;
  color: #666;
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
  line-height: 1.4;
}
.kpi-trend-toggle-btn + .kpi-trend-toggle-btn {
  border-left: 1px solid #d0d0d0;
}
.kpi-trend-toggle-btn.is-active {
  background: #568184;
  color: #fff;
}
.kpi-trend-toggle-btn:hover:not(.is-active) {
  background: #f0f0f0;
}

/* =========================================================
   レポート未生成 — セットアップガイド
   ========================================================= */
.dashboard-setup-guide {
  text-align: center;
  padding: 48px 32px;
  background: #FAF9F6;
  border: 2px dashed #D5D3CD;
  border-radius: 12px;
  margin-top: 8px;
}
.setup-guide-icon {
  font-size: 48px;
  margin-bottom: 12px;
}
.setup-guide-title {
  font-size: 22px;
  font-weight: 700;
  color: #2B2B2B;
  margin: 0 0 12px;
}
.setup-guide-desc {
  font-size: 14px;
  color: #6B6B65;
  line-height: 1.9;
  margin: 0 0 28px;
}
.setup-guide-steps {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 14px;
  text-align: left;
  margin: 0 auto 32px;
  width: fit-content;
}
.setup-guide-step {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 14px;
  color: #3b3b3b;
}
.setup-guide-step-num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  background: #568184;
  color: #fff;
  border-radius: 50%;
  font-size: 13px;
  font-weight: 700;
  flex-shrink: 0;
}
.setup-guide-btn {
  display: block;
  width: fit-content;
  margin: 0 auto;
  padding: 14px 36px;
  background: #568184;
  color: #fff !important;
  font-size: 16px;
  font-weight: 600;
  border-radius: 6px;
  text-decoration: none;
  transition: background 0.2s;
}
.setup-guide-btn:hover {
  background: #476C6F;
}
@media (max-width: 600px) {
  .dashboard-setup-guide { padding: 32px 20px; }
  .setup-guide-title { font-size: 18px; }
  .setup-guide-desc br { display: none; }
}

/* ============================================================
   Dashboard Rank Tracker Widget (.drt-)
   ============================================================ */
.drt-section {
    margin-top: 40px;
    background: #fff;
    border: 1px solid #E5E3DC;
    border-radius: 14px;
    padding: 30px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.drt-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
}
.drt-header__title {
    font-size: 18px; font-weight: 700; color: #1a1a1a;
    display: flex; align-items: center; gap: 8px;
}
.drt-header__actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.drt-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border: 1px solid #d0d5dd; border-radius: 8px;
    font-size: 13px; font-weight: 500; cursor: pointer;
    background: #fff; color: #344054; transition: all 0.15s; white-space: nowrap;
    text-decoration: none;
}
.drt-btn:hover { background: #f9fafb; border-color: #98a2b3; }
.drt-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.drt-btn--primary { background: #1a1a1a; color: #fff; border-color: #1a1a1a; }
.drt-btn--primary:hover { background: #333; }
.drt-btn--primary:disabled { background: #999; border-color: #999; }
.drt-btn__icon { font-size: 14px; }
.drt-help { font-size: 13px; color: #6b7280; margin-bottom: 16px; line-height: 1.6; }
/* Device toggle */
.drt-device-toggle {
    display: inline-flex; background: #f2f4f7; border-radius: 8px; padding: 3px; margin-bottom: 16px;
}
.drt-device-btn {
    padding: 6px 18px; border: none; border-radius: 6px; font-size: 13px; font-weight: 500;
    cursor: pointer; background: transparent; color: #667085; transition: all 0.2s;
}
.drt-device-btn.active {
    background: #fff; color: #1a1a1a; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
/* Summary cards */
.drt-summary-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
.drt-summary-card {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 14px 16px; display: flex; align-items: center; gap: 12px;
    border-left: 4px solid #e5e7eb;
}
.drt-summary-card--gold  { border-left-color: #f59e0b; }
.drt-summary-card--blue  { border-left-color: #3b82f6; }
.drt-summary-card--green { border-left-color: #22c55e; }
.drt-summary-card--red   { border-left-color: #ef4444; }
.drt-summary-card__dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
.drt-summary-card__dot--gold  { background: #f59e0b; }
.drt-summary-card__dot--blue  { background: #3b82f6; }
.drt-summary-card__dot--green { background: #22c55e; }
.drt-summary-card__dot--red   { background: #ef4444; }
.drt-summary-card__label { font-size: 12px; color: #6b7280; flex: 1; }
.drt-summary-card__count { font-size: 18px; font-weight: 700; color: #1a1a1a; min-width: 28px; text-align: right; }
.drt-summary-card__unit  { font-size: 11px; font-weight: 400; color: #9ca3af; }
/* Table */
.drt-table-wrap { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
.drt-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.drt-table { width: 100%; border-collapse: collapse; }
.drt-table th {
    background: #f9fafb; font-size: 11px; font-weight: 600; color: #6b7280;
    padding: 10px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.drt-table td {
    padding: 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #1a1a1a; vertical-align: middle;
}
.drt-table tr:last-child td { border-bottom: none; }
.drt-table tr:hover td { background: #fafbfc; }
.drt-table td:first-child { position: relative; padding-left: 16px; }
.drt-rank-accent { position: absolute; left: 0; top: 8px; bottom: 8px; width: 3px; border-radius: 2px; }
.drt-rank-accent--gold  { background: #f59e0b; }
.drt-rank-accent--blue  { background: #3b82f6; }
.drt-rank-accent--green { background: #22c55e; }
.drt-rank-accent--red   { background: #ef4444; }
.drt-kw-name { font-weight: 600; font-size: 13px; color: #1a1a1a; margin-bottom: 2px; }
.drt-kw-meta { display: flex; gap: 10px; flex-wrap: wrap; }
.drt-kw-meta-item { font-size: 10px; color: #9ca3af; }
.drt-kw-meta-item strong { color: #6b7280; font-weight: 600; }
/* 上がりやすさ目安 — 5段階ドットインジケーター */
.drt-opp-dots { display: inline-flex; gap: 2px; align-items: center; margin-right: 3px; vertical-align: middle; }
.drt-opp-dot { width: 5px; height: 5px; border-radius: 50%; background: #d1d5db; }
.drt-opp-label { font-size: 10px; }
.drt-rank { font-weight: 700; font-size: 15px; color: #1a1a1a; }
.drt-rank--out { font-size: 11px; font-weight: 600; color: #ef4444; }
.drt-rank--na  { font-size: 11px; color: #d1d5db; }
.drt-rank-unit { font-size: 10px; font-weight: 400; color: #9ca3af; }
.drt-rank-change { font-size: 10px; font-weight: 600; margin-top: 1px; }
.drt-rank-change--up   { color: #16a34a; }
.drt-rank-change--down { color: #ef4444; }
.drt-rank-change--same { color: #9ca3af; }
.drt-pc-diff {
    display: inline-block; font-size: 9px; color: #f59e0b; background: #fffbeb;
    border: 1px solid #fde68a; border-radius: 4px; padding: 1px 4px; margin-left: 3px; white-space: nowrap;
}
.drt-daily { font-size: 12px; font-weight: 500; text-align: center; min-width: 42px; white-space: nowrap; }
.drt-daily--out { color: #ef4444; font-size: 10px; }
.drt-daily--na  { color: #d1d5db; }
.drt-action-link {
    display: inline-flex; align-items: center; gap: 3px; font-size: 12px; color: #568184;
    cursor: pointer; text-decoration: none; padding: 3px 0; border: none; background: none; white-space: nowrap;
}
.drt-action-link:hover { color: #476C6F; text-decoration: underline; }
.drt-action-link__icon { font-size: 13px; }
.drt-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }
.drt-empty__icon { font-size: 32px; margin-bottom: 8px; }
.drt-empty__text { font-size: 14px; color: #6b7280; }
.drt-loading { text-align: center; padding: 32px; color: #9ca3af; font-size: 13px; }
.drt-footer { text-align: center; margin-top: 16px; }
.drt-footer__link {
    display: inline-flex; align-items: center; gap: 6px; font-size: 13px;
    color: #568184; text-decoration: none; font-weight: 500;
}
.drt-footer__link:hover { text-decoration: underline; }
/* SERP Modal */
.drt-modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4); z-index: 10000;
    display: none; justify-content: center; align-items: flex-start;
    padding: 60px 20px; overflow-y: auto;
}
.drt-modal-overlay.active { display: flex; }
.drt-modal {
    background: #fff; border-radius: 14px; width: 100%; max-width: 720px;
    max-height: calc(100vh - 120px); overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.15);
}
.drt-modal__header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 22px; border-bottom: 1px solid #e5e7eb;
    position: sticky; top: 0; background: #fff; z-index: 1; border-radius: 14px 14px 0 0;
}
.drt-modal__title { font-size: 15px; font-weight: 700; color: #1a1a1a; }
.drt-modal__close {
    width: 30px; height: 30px; border: none; background: #f3f4f6; border-radius: 8px;
    cursor: pointer; font-size: 15px; display: flex; align-items: center; justify-content: center; color: #6b7280;
}
.drt-modal__close:hover { background: #e5e7eb; }
.drt-modal__body { padding: 0; }
.drt-modal__toolbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 22px 0; gap: 12px;
}
.drt-device-toggle--modal { margin-bottom: 0; }
.drt-serp-google-link {
    font-size: 12.5px; color: var(--mw-primary-blue, #568184);
    text-decoration: none; white-space: nowrap; opacity: 0.75; transition: opacity 0.2s;
}
.drt-serp-google-link:hover { opacity: 1; text-decoration: underline; }
.drt-serp-note {
    padding: 8px 22px 12px; font-size: 12px; color: #8b8f96;
    line-height: 1.7; border-bottom: 1px solid #f0f0f0;
}
.drt-serp-table { width: 100%; border-collapse: collapse; }
.drt-serp-table th {
    font-size: 12px; font-weight: 600; color: #6b7280;
    padding: 10px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; background: #f9fafb;
}
.drt-serp-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; vertical-align: top; }
.drt-serp-table tr:last-child td { border-bottom: none; }
.drt-serp-rank { font-weight: 700; font-size: 15px; color: #568184; text-align: center; min-width: 36px; }
.drt-serp-title { font-weight: 600; color: #1a1a1a; margin-bottom: 2px; line-height: 1.4; }
.drt-serp-url { font-size: 12px; color: #568184; word-break: break-all; }
.drt-serp-desc {
    font-size: 12px; color: #9ca3af; margin-top: 4px; line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
/* Progress overlay */
.drt-progress-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5); z-index: 10002; display: none; justify-content: center; align-items: center;
}
.drt-progress-overlay.active { display: flex; }
.drt-progress-box {
    background: #fff; border-radius: 16px; padding: 28px 36px;
    min-width: 300px; max-width: 440px; text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,0.15);
}
.drt-progress-title { font-size: 15px; font-weight: 700; color: #1a1a1a; margin-bottom: 14px; }
.drt-progress-bar-wrap { width: 100%; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; margin-bottom: 10px; }
.drt-progress-bar {
    height: 100%; background: linear-gradient(90deg, #568184, #a3c9a9); border-radius: 4px;
    width: 0%; transition: width 0.3s ease;
}
.drt-progress-bar--indeterminate { width: 30%; animation: drt-progress-slide 1.5s infinite ease-in-out; }
@keyframes drt-progress-slide {
    0%   { transform: translateX(-100%); }
    50%  { transform: translateX(200%); }
    100% { transform: translateX(-100%); }
}
.drt-progress-text { font-size: 13px; color: #6b7280; margin-bottom: 4px; }
.drt-progress-sub  { font-size: 11px; color: #9ca3af; }
/* Toast */
.drt-toast {
    position: fixed; bottom: 24px; right: 24px; background: #1a1a1a; color: #fff;
    padding: 12px 18px; border-radius: 10px; font-size: 13px; z-index: 10001;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15); opacity: 0; transform: translateY(12px);
    transition: opacity 0.3s, transform 0.3s; max-width: 380px; line-height: 1.5;
}
.drt-toast.show { opacity: 1; transform: translateY(0); }
.drt-toast--error { background: #ef4444; }
/* ============================================================
   MEO Section (.meo-)
   ============================================================ */
.meo-section {
    margin-top: 40px;
    background: #fff;
    border: 1px solid #E5E3DC;
    border-radius: 14px;
    padding: 30px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.meo-header { margin-bottom: 16px; }
.meo-header__title {
    font-size: 18px; font-weight: 700; color: #1a1a1a;
    display: flex; align-items: center; gap: 8px;
}
.meo-help { font-size: 13px; color: #6b7280; margin-bottom: 16px; line-height: 1.6; }
/* Measurement conditions row */
.meo-conditions {
    display: flex; align-items: flex-start; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 18px;
}
.meo-condition-group { display: flex; flex-direction: column; gap: 4px; }
.meo-condition-label {
    font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;
}
.meo-condition-value { font-size: 13px; font-weight: 600; color: #1a1a1a; }
.meo-condition-value--sub { font-size: 11px; font-weight: 400; color: #6b7280; }
/* Device toggle */
.meo-device-toggle {
    display: inline-flex; background: #f2f4f7; border-radius: 8px; padding: 3px;
}
.meo-device-btn {
    padding: 6px 18px; border: none; border-radius: 6px; font-size: 13px; font-weight: 500;
    cursor: pointer; background: transparent; color: #667085; transition: all 0.2s;
}
.meo-device-btn.active {
    background: #fff; color: #1a1a1a; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
/* Keyword selector with label */
.meo-keyword-group { display: flex; flex-direction: column; gap: 4px; }
.meo-keyword-label {
    font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;
}
.meo-keyword-select {
    font-size: 13px; color: #344054; border: 1px solid #d0d5dd; border-radius: 8px;
    padding: 5px 10px; background: #fff; cursor: pointer; max-width: 240px; font-weight: 500;
}
.meo-keyword-single {
    font-size: 13px; font-weight: 600; color: #1a1a1a;
}
/* Radius selector */
.meo-radius-group { display: flex; flex-direction: column; gap: 4px; }
.meo-radius-label {
    font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;
}
.meo-radius-select {
    font-size: 13px; color: #344054; border: 1px solid #d0d5dd; border-radius: 8px;
    padding: 5px 10px; background: #fff; cursor: pointer; max-width: 120px; font-weight: 500;
}
/* Metrics cards */
.meo-metrics-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
.meo-metric-card {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 16px; border-left: 4px solid #e5e7eb; text-align: center;
}
.meo-metric-card--teal   { border-left-color: #568184; }
.meo-metric-card--blue   { border-left-color: #3b82f6; }
.meo-metric-card--gold   { border-left-color: #f59e0b; }
.meo-metric-card--green  { border-left-color: #22c55e; }
.meo-metric-icon { font-size: 20px; margin-bottom: 4px; }
.meo-metric-label { font-size: 12px; color: #1a1a1a; font-weight: 600; margin-bottom: 2px; }
.meo-metric-sublabel { font-size: 10px; color: #9ca3af; margin-bottom: 6px; line-height: 1.3; }
.meo-metric-value { font-size: 22px; font-weight: 700; color: #1a1a1a; line-height: 1.2; }
.meo-metric-value small { font-size: 12px; font-weight: 400; color: #9ca3af; }
.meo-metric-value--out { font-size: 14px; color: #ef4444; }
/* Store card */
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
/* Reviews card */
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
/* Competitor table */
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
.meo-empty { text-align: center; padding: 40px 20px; color: #9ca3af; display: none; }
.meo-empty__icon { font-size: 32px; margin-bottom: 8px; }
.meo-empty__text { font-size: 14px; color: #6b7280; }
.meo-error {
    text-align: center; padding: 24px; color: #ef4444; font-size: 13px;
    background: #fef2f2; border-radius: 8px; margin-top: 12px; display: none;
}
.meo-retry-btn {
    display: inline-block; margin-top: 8px; padding: 6px 14px; border: 1px solid #d0d5dd;
    border-radius: 8px; font-size: 12px; cursor: pointer; background: #fff; color: #344054;
}
.meo-retry-btn:hover { background: #f9fafb; }

/* MEO History Table */
.meo-history-wrap {
    margin-top: 20px; background: #fff; border: 1px solid #e5e7eb;
    border-radius: 12px; padding: 20px;
}
.meo-history-title {
    font-weight: 600; font-size: 15px; color: #1a1a1a; margin-bottom: 12px;
}
.meo-history-table { width: 100%; border-collapse: collapse; }
.meo-history-table th {
    background: #f9fafb; font-size: 11px; font-weight: 600; color: #6b7280;
    padding: 10px 14px; text-align: center; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.meo-history-table th:first-child { text-align: left; }
.meo-history-table td {
    padding: 12px 14px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #1a1a1a;
    vertical-align: middle; text-align: center;
}
.meo-history-table td:first-child { text-align: left; font-size: 12px; }
.meo-history-table tr:last-child td { border-bottom: none; }
.meo-trend-up { color: #16a34a; font-weight: 600; font-size: 11px; }
.meo-trend-down { color: #dc2626; font-weight: 600; font-size: 11px; }
.meo-trend-same { color: #9ca3af; font-size: 11px; }

/* Responsive */
@media (max-width: 768px) {
    .drt-section { padding: 20px 16px; }
    .drt-header { flex-direction: column; align-items: flex-start; }
    .drt-summary-cards { grid-template-columns: repeat(2, 1fr); }
    .meo-section { padding: 20px 16px; }
    .meo-metrics-cards { grid-template-columns: repeat(2, 1fr); }
    .meo-conditions { flex-direction: column; gap: 12px; padding: 12px 14px; }
    .meo-store-grid { grid-template-columns: 1fr; gap: 4px; }
    .meo-store-label { font-weight: 600; }
    .meo-reviews-summary { flex-direction: column; align-items: flex-start; }
}

</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>


<!-- コンテンツエリア -->
<div class="content-area">
    <!-- ローディングオーバーレイ -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>データを取得中...</p>
        </div>
    </div>

    <!-- サービス説明（常時表示） -->
    <p class="service-lead">
        「みまもりウェブ」は、ホームページの状態を毎日データで見守り、「今どうなっているか」をやさしく伝えるサービスです。
    </p>

<?php
// =========================================================
// インフォグラフィック（月次サマリーブロック）
// 保存済みJSONを読むだけ（外部API通信なし）
// =========================================================
$infographic = $gcrev_api->get_monthly_infographic($year, $month, $user_id);

// KPIデータ（JSインライン用に外スコープで宣言）
$kpi_curr = [];
$kpi_prev = [];

// === 直近30日ベースのKPI・スコア算出 ===
if ($infographic && is_array($infographic)) {
    try {
        // --- 直近30日のKPIデータ（GA4+GSC）---
        // cache_first=1: キャッシュがあれば使い、なければスキップ（JS側で非同期取得）
        $kpi_curr = $gcrev_api->get_dashboard_kpi('last30', $user_id, 1);

        // 比較期間のKPI
        $kpi_prev = [];
        if (!empty($kpi_curr)) {
            $kpi_prev = $gcrev_api->get_dashboard_kpi_by_dates(
                $last30_comp['start'], $last30_comp['end'], $user_id
            );
        }

        // --- MEO直近30日 ---
        $meo_curr = $gcrev_api->get_meo_total_impressions($user_id, $last30['start'], $last30['end']);
        $meo_prev = $gcrev_api->get_meo_total_impressions($user_id, $last30_comp['start'], $last30_comp['end']);

        // --- KPI値構築 ---
        $sess_curr = (int)str_replace(',', '', (string)($kpi_curr['sessions'] ?? '0'));
        $sess_prev = (int)str_replace(',', '', (string)($kpi_prev['sessions'] ?? '0'));
        $cv_curr   = (int)str_replace(',', '', (string)($kpi_curr['conversions'] ?? '0'));
        $cv_prev   = (int)str_replace(',', '', (string)($kpi_prev['conversions'] ?? '0'));
        $gsc_curr_val = (int)str_replace(',', '', (string)(
            $kpi_curr['gsc']['total']['clicks'] ?? $kpi_curr['gsc']['total']['impressions'] ?? '0'
        ));
        $gsc_prev_val = (int)str_replace(',', '', (string)(
            $kpi_prev['gsc']['total']['clicks'] ?? $kpi_prev['gsc']['total']['impressions'] ?? '0'
        ));

        // infographic KPI 上書き（訪問数・ゴール数・MEO）
        $infographic['kpi']['visits'] = ['value' => $sess_curr, 'diff' => $sess_curr - $sess_prev];
        $infographic['kpi']['cv']     = ['value' => $cv_curr,   'diff' => $cv_curr - $cv_prev];
        $infographic['kpi']['meo']    = ['value' => $meo_curr,  'diff' => $meo_curr - $meo_prev];

        // === スコア再計算（直近30日 vs その前の30日）===
        $re_curr = ['traffic' => $sess_curr, 'cv' => $cv_curr, 'gsc' => $gsc_curr_val, 'meo' => $meo_curr];
        $re_prev = ['traffic' => $sess_prev, 'cv' => $cv_prev, 'gsc' => $gsc_prev_val, 'meo' => $meo_prev];
        $re_health = $gcrev_api->calc_monthly_health_score($re_curr, $re_prev, [], $user_id);
        $infographic['score']      = $re_health['score'];
        $infographic['status']     = $re_health['status'];
        $infographic['breakdown']  = $re_health['breakdown'];
        $infographic['components'] = $re_health['components'];
    } catch (\Throwable $e) {
        error_log('[GCREV] page-dashboard last30 override error: ' . $e->getMessage());
    }
}

// 月次レポート（結論サマリー・ハイライト・ハイライト詳細を一括取得）
$monthly_report = null;
$highlights = [];
$highlight_details = [];
if ($infographic) {
    $payload = $gcrev_api->get_dashboard_payload($year, $month, $user_id, $infographic);
    $monthly_report = $payload['monthly_report'] ?? null;
    if ($monthly_report && !empty($monthly_report['highlights']['most_important'])) {
        $highlights = $monthly_report['highlights'];
    } else {
        $highlights = [
            'most_important' => '新規ユーザー獲得',
            'top_issue'      => 'ゴール改善',
            'opportunity'    => '地域施策見直し',
        ];
    }
    $highlight_details = $payload['highlight_details'] ?? [];
}
?>


    <!-- 期間表示 -->
    <div class="period-info">
        <div class="period-item">
            <span class="period-label-v2">&#x1F4C5; 対象期間：</span>
            <span class="period-value">直近30日（<?php echo esc_html( $last30['display']['start'] ); ?> 〜 <?php echo esc_html( $last30['display']['end'] ); ?>）</span>
        </div>
        <div class="period-divider"></div>
        <div class="period-item">
            <span class="period-label-v2">&#x1F4CA; 比較期間：</span>
            <span class="period-value">その前の30日（<?php echo esc_html( $last30_comp['display']['start'] ); ?> 〜 <?php echo esc_html( $last30_comp['display']['end'] ); ?>）</span>
        </div>
    </div>
<?php if ($infographic): ?>
<section class="dashboard-infographic">

  <!-- 外枠右上：最新月次レポートを見る（※月次レポートがある時だけ表示） -->
  <?php if (!empty($monthly_report)): ?>
    <a href="<?php echo esc_url(home_url('/report/report-latest/')); ?>" class="info-monthly-link info-monthly-link--corner">
      <span aria-hidden="true">📊</span> 前月の月次レポートを見る
    </a>
  <?php endif; ?>

  <!-- 見出し -->
  <h2 class="dashboard-infographic-title">
    <span class="icon" aria-hidden="true">📊</span>直近30日の状態
  </h2>

  <?php
  // --- おめでとうメッセージ判定 ---
  $congrats_score_diff   = (int)($infographic['score_diff'] ?? 0);
  $congrats_kpi          = $infographic['kpi'] ?? [];
  $congrats_improved     = 0;
  $congrats_improved_labels = [];
  $congrats_label_map    = ['visits' => '訪問数', 'cv' => 'ゴール数', 'meo' => 'マップ表示'];
  foreach (['visits', 'cv', 'meo'] as $ck) {
      $cd = (int)($congrats_kpi[$ck]['diff'] ?? 0);
      $cv = (int)($congrats_kpi[$ck]['value'] ?? 0);
      if ($cd > 0 && $cv >= 5) {
          $congrats_improved++;
          $congrats_improved_labels[] = $congrats_label_map[$ck];
      }
  }
  $show_congrats = ($congrats_score_diff > 0 && $congrats_improved >= 1)
                || ($congrats_improved >= 2);

  if ($show_congrats):
      if ($congrats_score_diff > 0 && $congrats_improved >= 2) {
          $congrats_icon  = '🏆';
          $congrats_title = '素晴らしい改善です！';
          $congrats_text  = 'スコアも主要指標も改善しています。やった施策が数字に反映されています。';
      } elseif ($congrats_score_diff > 0) {
          $congrats_icon  = '🎉';
          $congrats_title = 'スコアが改善しています！';
          $congrats_text  = sprintf('いい感じです！前月よりスコアが +%d 改善しました。この調子で次の一手を進めましょう。', $congrats_score_diff);
      } else {
          $congrats_icon  = '📈';
          $congrats_title = '改善が数字に表れています！';
          $congrats_text  = implode('・', $congrats_improved_labels) . ' が前月より改善しました。成果が出ています。';
      }
  ?>
  <div class="info-congrats">
    <span class="info-congrats-icon" aria-hidden="true"><?php echo $congrats_icon; ?></span>
    <div class="info-congrats-body">
      <div class="info-congrats-title"><?php echo esc_html($congrats_title); ?></div>
      <div class="info-congrats-text"><?php echo esc_html($congrats_text); ?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- スコア + KPI 横並びエリア -->
  <div class="info-top-row">
    <!-- スコア -->
    <div class="info-score">
      <?php
      $score_val   = (int)($infographic['score'] ?? 0);
      $score_pct   = max(0, min(100, $score_val));
      $circumference = 326.73; // 2 * π * 52
      $dash_offset   = $circumference * (1 - $score_pct / 100);
      $score_diff = (int)($infographic['score_diff'] ?? 0);
      $diff_class = $score_diff > 0 ? 'positive' : ($score_diff < 0 ? 'negative' : 'neutral');
      $diff_icon  = $score_diff > 0 ? '↑' : ($score_diff < 0 ? '↓' : '→');
      $diff_text  = $score_diff > 0 ? '+' . $score_diff : (string)$score_diff;
      ?>
      <div class="info-score-gauge">
        <svg viewBox="0 0 120 120" class="score-svg">
          <circle cx="60" cy="60" r="52" fill="none" stroke="var(--mw-bg-tertiary)" stroke-width="10" />
          <circle cx="60" cy="60" r="52" fill="none" stroke="var(--mw-primary-blue)" stroke-width="10"
                  stroke-linecap="round"
                  stroke-dasharray="<?php echo esc_attr($circumference); ?>"
                  stroke-dashoffset="<?php echo esc_attr($dash_offset); ?>"
                  class="score-progress" transform="rotate(-90 60 60)" />
        </svg>
        <div class="score-center-text">
          <span class="info-score-value"><?php echo esc_html((string)$score_val); ?><span class="info-score-unit">点</span></span>
          <span class="info-score-label">100点中</span>
        </div>
      </div>

      <span class="info-score-diff <?php echo esc_attr($diff_class); ?>">
        <?php echo esc_html($diff_icon . ' ' . $diff_text); ?>
      </span>

      <?php if (!empty($infographic['status'])): ?>
        <span class="info-score-status"><?php echo esc_html($infographic['status']); ?></span>
      <?php endif; ?>

      <button type="button" class="info-score-breakdown-link" id="scoreBreakdownOpen">採点の内訳を見る</button>
    </div>

    <!-- KPI -->
    <div class="info-kpi-area">
      <h3 class="section-title info-kpi-heading">主な指標</h3>
      <div class="info-kpi">
        <?php
        $kpi_items = [
          'visits' => ['label' => '訪問数',   'icon' => '👥', 'metric' => 'sessions'],
          'cv'     => ['label' => 'ゴール数', 'icon' => '🎯', 'metric' => 'cv'],
          'meo'    => ['label' => 'Googleマップでの表示回数',  'icon' => '📍', 'metric' => 'meo'],
        ];
        $first_kpi = true;
        foreach ($kpi_items as $key => $meta):
          $kpi = $infographic['kpi'][$key] ?? ['value' => 0, 'diff' => 0];
          $kpi_val  = (int)($kpi['value'] ?? 0);
          $kpi_diff = (int)($kpi['diff'] ?? 0);

          $kpi_diff_class = $kpi_diff > 0 ? 'positive' : ($kpi_diff < 0 ? 'negative' : 'neutral');
          $kpi_diff_icon  = $kpi_diff > 0 ? '↑' : ($kpi_diff < 0 ? '↓' : '→');
          $kpi_diff_text  = $kpi_diff > 0 ? '+' . number_format($kpi_diff) : number_format($kpi_diff);
          $is_first_active = $first_kpi ? ' is-active' : '';
          $aria_pressed    = $first_kpi ? 'true' : 'false';
        ?>
          <button type="button" class="info-kpi-item<?php echo $is_first_active; ?>" data-kpi-key="<?php echo esc_attr($key); ?>" data-metric="<?php echo esc_attr($meta['metric']); ?>" data-kpi-icon="<?php echo esc_attr($meta['icon']); ?>" aria-pressed="<?php echo esc_attr($aria_pressed); ?>">
            <span class="info-kpi-icon"><?php echo $meta['icon']; ?></span>
            <span class="info-kpi-label"><?php echo esc_html($meta['label']); ?></span>
            <span class="info-kpi-value" data-kpi-role="value"><?php echo esc_html(number_format($kpi_val)); ?></span>
            <span class="info-kpi-diff <?php echo esc_attr($kpi_diff_class); ?>" data-kpi-role="diff">
              <?php echo esc_html($kpi_diff_icon . ' ' . $kpi_diff_text); ?>
            </span>
            <span class="info-kpi-hint">クリックでグラフ切替</span>
          </button>
        <?php $first_kpi = false; endforeach; ?>
      </div>
    </div>
  </div>

  <!-- サマリー -->
  <div class="info-summary">
    <span class="info-summary-icon" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 1.5a5.5 5.5 0 0 1 3.16 10.01c-.44.31-.66.56-.76.82-.1.27-.15.61-.15 1.17v.5H7.75v-.5c0-.56-.05-.9-.15-1.17-.1-.26-.32-.51-.76-.82A5.5 5.5 0 0 1 10 1.5Z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 16.5h4M8.5 14h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="10" cy="7" r="1" fill="currentColor"/></svg>
    </span>
    <span class="info-summary-text"><?php echo esc_html($infographic['summary'] ?? ''); ?></span>
  </div>

  <!-- KPI トレンドチャート（インライン常時表示） -->
  <div class="kpi-trend-inline" id="kpiTrendInline">
    <div class="kpi-trend-inline-header">
      <h3 class="kpi-trend-inline-title" id="kpiTrendTitle">
        <span class="kpi-trend-inline-icon" id="kpiTrendIcon">👥</span>
        <span id="kpiTrendTitleText">訪問数 — 過去12ヶ月の推移</span>
      </h3>
      <span class="kpi-trend-inline-hint" title="各月の点をクリックすると、内訳データを確認できます">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1Zm0 12.5a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11ZM8 5a.75.75 0 1 1 0-1.5A.75.75 0 0 1 8 5Zm-.75 1.75a.75.75 0 0 1 1.5 0v3.5a.75.75 0 0 1-1.5 0v-3.5Z"/></svg>
        <span class="kpi-trend-inline-hint-text">各月の点をクリックで詳細を表示</span>
      </span>
    </div>
    <div class="kpi-trend-inline-body">
      <div class="kpi-trend-loading active" id="kpiTrendLoading">
        <div class="kpi-trend-skeleton"></div>
      </div>
      <div class="kpi-trend-chart-wrap" id="kpiTrendChartWrap" style="display:none;">
        <canvas id="kpiTrendChart"></canvas>
      </div>
      <div class="kpi-trend-error" id="kpiTrendError" style="display:none;">
        <p>データを取得できませんでした</p>
        <button type="button" class="kpi-trend-retry" id="kpiTrendRetry">再試行</button>
      </div>
    </div>
  </div>

  <!-- ドリルダウンポップオーバー -->
  <div class="drilldown-popover" id="drilldownPopover" style="display:none;">
    <div class="drilldown-popover-title" id="drilldownPopoverTitle"></div>
    <button type="button" class="drilldown-popover-item" data-dd-type="region" id="ddItem_region">
      <span class="drilldown-popover-icon">📍</span>
      <span class="drilldown-popover-label">
        <span id="ddLabel_region">見ている人の場所</span>
        <small class="drilldown-popover-help" data-help-key="region" id="ddHelp_region">ホームページを見ている人が、どの地域からアクセスしているかを表しています</small>
      </span>
    </button>
    <button type="button" class="drilldown-popover-item" data-dd-type="page" id="ddItem_page">
      <span class="drilldown-popover-icon">📄</span>
      <span class="drilldown-popover-label">
        <span id="ddLabel_page">訪問の入口となったページ</span>
        <small class="drilldown-popover-help" data-help-key="page" id="ddHelp_page">検索やSNS、広告などから、最初に表示されたページです</small>
      </span>
    </button>
    <button type="button" class="drilldown-popover-item" data-dd-type="source" id="ddItem_source">
      <span class="drilldown-popover-icon">🔗</span>
      <span class="drilldown-popover-label">
        <span id="ddLabel_source">見つけたきっかけ</span>
        <small class="drilldown-popover-help" data-help-key="source" id="ddHelp_source">検索、SNS、広告、他サイトなど、ホームページを知った経路です</small>
      </span>
    </button>
  </div>

  <!-- ドリルダウンモーダル -->
  <div class="drilldown-modal-overlay" id="drilldownOverlay" style="display:none;">
    <div class="drilldown-modal">
      <div class="drilldown-modal-header">
        <h3 class="drilldown-modal-title" id="drilldownModalTitle"></h3>
        <button type="button" class="drilldown-modal-close" id="drilldownModalClose" aria-label="閉じる">&times;</button>
      </div>
      <div class="drilldown-modal-body">
        <div class="drilldown-modal-loading" id="drilldownLoading">
          <div class="kpi-trend-skeleton"></div>
        </div>
        <div class="drilldown-modal-chart" id="drilldownChartWrap" style="display:none;">
          <canvas id="drilldownChart"></canvas>
        </div>
        <div class="drilldown-modal-empty" id="drilldownEmpty" style="display:none;">
          データがありません
        </div>
        <div class="drilldown-modal-error" id="drilldownError" style="display:none;">
          データを取得できませんでした
        </div>
      </div>
    </div>
  </div>

  <!-- 採点の内訳（breakdown） -->
  <?php
  $breakdown  = $infographic['breakdown'] ?? null;
  $components = $infographic['components'] ?? null;
  $has_breakdown  = is_array($breakdown) && !empty($breakdown);
  $has_components = is_array($components) && !empty($components);
  $bd_icons = [
    'traffic' => '👥',
    'cv'      => '🎯',
    'gsc'     => '🔍',
    'meo'     => '📍',
  ];
  $bd_labels = [
    'traffic' => 'サイトに来た人の数',
    'cv'      => 'ゴール（問い合わせ・申込みなど）',
    'gsc'     => '検索結果からクリックされた数',
    'meo'     => '地図検索からの表示数',
  ];
  $comp_icons = [
    'achievement' => '📊',
    'growth'      => '📈',
    'stability'   => "\u{1F6E1}\u{FE0F}",
    'action'      => '⭐',
  ];
  ?>

  <!-- スコア内訳モーダル -->
  <div class="score-breakdown-overlay" id="scoreBreakdownOverlay" style="display:none;">
    <div class="score-breakdown-modal">
      <div class="score-breakdown-modal-header">
        <h3 class="score-breakdown-modal-title">採点の内訳</h3>
        <button type="button" class="score-breakdown-modal-close" id="scoreBreakdownClose" aria-label="閉じる">&times;</button>
      </div>
      <div class="score-breakdown-modal-body">
        <div class="score-breakdown-total">
          <span class="score-breakdown-total-value"><?php echo esc_html((string)($infographic['score'] ?? 0)); ?></span>
          <span class="score-breakdown-total-unit">点</span>
          <span class="score-breakdown-total-sep">/</span>
          <span class="score-breakdown-total-label">100点中</span>
        </div>

        <?php if ($has_components): ?>
          <!-- v2: 4コンポーネント表示 -->
          <div class="score-comp-list">
          <?php foreach ($components as $comp_key => $comp):
            if (!is_array($comp)) continue;
            $c_points = (int)($comp['points'] ?? 0);
            $c_max    = (int)($comp['max'] ?? 0);
            $c_label  = esc_html($comp['label'] ?? $comp_key);
            $c_icon   = $comp_icons[$comp_key] ?? '📊';
            $c_bar_pct = $c_max > 0 ? min(100, ($c_points / $c_max) * 100) : 0;
          ?>
            <div class="score-comp-card">
              <div class="score-comp-header">
                <span class="score-comp-icon"><?php echo $c_icon; ?></span>
                <span class="score-comp-label"><?php echo $c_label; ?></span>
                <span class="score-comp-pts"><?php echo esc_html("{$c_points} / {$c_max}pt"); ?></span>
              </div>
              <div class="score-comp-bar">
                <div class="score-comp-bar-fill" style="width:<?php echo esc_attr((string)$c_bar_pct); ?>%"></div>
              </div>

              <?php if ($comp_key === 'achievement' && !empty($comp['details'])): ?>
                <details class="score-comp-details">
                  <summary>内訳を見る</summary>
                  <div class="score-comp-details-body">
                    <?php foreach ($comp['details'] as $dim_key => $dim):
                      $d_icon   = $bd_icons[$dim_key] ?? '📊';
                      $d_label  = $bd_labels[$dim_key] ?? $dim_key;
                      $d_pts    = $dim['points'] ?? 0;
                      $d_max    = $dim['max'] ?? 12.5;
                      $d_ratio  = $dim['ratio'] ?? null;
                      $d_fb     = !empty($dim['fallback']);
                      $ratio_text = '';
                      if ($d_ratio !== null) {
                          $ratio_text = '（中央値の' . number_format($d_ratio * 100, 0) . '%）';
                      } elseif ($d_fb) {
                          $ratio_text = '（前月比フォールバック）';
                      }
                    ?>
                      <div class="score-comp-dim-row">
                        <span class="score-comp-dim-icon"><?php echo $d_icon; ?></span>
                        <span class="score-comp-dim-label"><?php echo esc_html($d_label); ?></span>
                        <span class="score-comp-dim-pts"><?php echo esc_html("{$d_pts}/{$d_max}"); ?></span>
                        <?php if ($ratio_text): ?>
                          <span class="score-comp-dim-note"><?php echo esc_html($ratio_text); ?></span>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endif; ?>

              <?php if ($comp_key === 'growth' && !empty($comp['details'])): ?>
                <details class="score-comp-details">
                  <summary>内訳を見る</summary>
                  <div class="score-comp-details-body">
                    <?php foreach ($comp['details'] as $dim_key => $dim):
                      $d_icon  = $bd_icons[$dim_key] ?? '📊';
                      $d_label = $bd_labels[$dim_key] ?? $dim_key;
                      $d_pts   = $dim['points'] ?? 0;
                      $d_max   = $dim['max'] ?? 7.5;
                      $d_pct   = $dim['pct'] ?? 0;
                      $d_zone  = $dim['zone'] ?? '';
                      $pct_sign = $d_pct > 0 ? '+' : '';
                      $zone_label = '';
                      if ($d_zone === 'dead')  $zone_label = '安定（デッドゾーン）';
                      if ($d_zone === 'zero')  $zone_label = 'データなし';
                    ?>
                      <div class="score-comp-dim-row">
                        <span class="score-comp-dim-icon"><?php echo $d_icon; ?></span>
                        <span class="score-comp-dim-label"><?php echo esc_html($d_label); ?></span>
                        <span class="score-comp-dim-pct"><?php echo esc_html("{$pct_sign}" . number_format((float)$d_pct, 1) . '%'); ?></span>
                        <span class="score-comp-dim-pts"><?php echo esc_html("{$d_pts}/{$d_max}"); ?></span>
                        <?php if ($zone_label): ?>
                          <span class="score-comp-dim-note"><?php echo esc_html($zone_label); ?></span>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endif; ?>

              <?php if ($comp_key === 'stability'): ?>
                <?php $stab_drops = (int)($comp['drops'] ?? 0); ?>
                <?php if (!empty($comp['details'])): ?>
                <details class="score-comp-details">
                  <summary><?php echo $stab_drops === 0 ? '急落なし ✓' : esc_html("{$stab_drops}観点で急落（-20%超）"); ?></summary>
                  <div class="score-comp-details-body">
                    <?php foreach ($comp['details'] as $dim_key => $dim):
                      $d_icon  = $bd_icons[$dim_key] ?? '📊';
                      $d_label = $bd_labels[$dim_key] ?? $dim_key;
                      $d_pct   = (float)($dim['pct'] ?? 0);
                      $d_drop  = !empty($dim['drop']);
                      $d_zero  = !empty($dim['zero']);
                      $pct_sign = $d_pct > 0 ? '+' : '';
                      if ($d_zero) {
                          $status_text = 'データなし';
                          $status_class = 'score-comp-dim-note';
                      } elseif ($d_drop) {
                          $status_text = '急落 ⚠';
                          $status_class = 'score-comp-check-ng';
                      } else {
                          $status_text = '安定 ✓';
                          $status_class = 'score-comp-check-ok';
                      }
                    ?>
                      <div class="score-comp-dim-row">
                        <span class="score-comp-dim-icon"><?php echo $d_icon; ?></span>
                        <span class="score-comp-dim-label"><?php echo esc_html($d_label); ?></span>
                        <?php if (!$d_zero): ?>
                          <span class="score-comp-dim-pct"><?php echo esc_html("{$pct_sign}" . number_format($d_pct, 1) . '%'); ?></span>
                        <?php endif; ?>
                        <span class="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_text); ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </details>
                <?php else: ?>
                <div class="score-comp-inline-note">
                  <?php echo $stab_drops === 0
                      ? '<span class="score-comp-check-ok">急落なし ✓</span>'
                      : '<span class="score-comp-check-ng">' . esc_html("{$stab_drops}観点で急落（-20%超）") . '</span>'; ?>
                </div>
                <?php endif; ?>
              <?php endif; ?>

              <?php if ($comp_key === 'action' && !empty($comp['checks'])): ?>
                <div class="score-comp-checklist">
                  <?php foreach ($comp['checks'] as $check): ?>
                    <span class="score-comp-check-item <?php echo $check['ok'] ? 'is-ok' : 'is-ng'; ?>">
                      <?php echo $check['ok'] ? '✓' : '✗'; ?>
                      <?php echo esc_html($check['label']); ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>

        <?php elseif ($has_breakdown): ?>
          <!-- 旧形式: テーブル表示（後方互換） -->
          <div class="score-breakdown-table-wrap">
            <table class="info-breakdown-table" role="table">
              <thead>
                <tr>
                  <th>観点</th>
                  <th>当月</th>
                  <th>先月</th>
                  <th>前月比</th>
                  <th>配点</th>
                </tr>
              </thead>
              <tbody>
              <?php
              foreach ($breakdown as $bd_key => $bd):
                if (!is_array($bd)) continue;

                $bd_label  = esc_html($bd_labels[$bd_key] ?? $bd['label'] ?? $bd_key);
                $bd_curr   = number_format((float)($bd['curr'] ?? 0));
                $bd_prev   = number_format((float)($bd['prev'] ?? 0));
                $bd_pct    = (float)($bd['pct'] ?? 0);
                $bd_points = (int)($bd['points'] ?? 0);
                $bd_max    = (int)($bd['max'] ?? 25);
                $bd_icon   = $bd_icons[$bd_key] ?? '📊';

                $pct_class = $bd_pct > 0 ? 'positive' : ($bd_pct < 0 ? 'negative' : 'neutral');
                $pct_text  = ($bd_pct > 0 ? '+' : '') . number_format($bd_pct, 1) . '%';

                $bar_pct = $bd_max > 0 ? min(100, ($bd_points / $bd_max) * 100) : 0;
              ?>
                <tr>
                  <td><span class="bd-icon"><?php echo $bd_icon; ?></span><?php echo $bd_label; ?></td>
                  <td class="bd-num"><?php echo esc_html($bd_curr); ?></td>
                  <td class="bd-num bd-prev"><?php echo esc_html($bd_prev); ?></td>
                  <td class="bd-num <?php echo esc_attr($pct_class); ?>"><?php echo esc_html($pct_text); ?></td>
                  <td class="bd-score-cell">
                    <div class="bd-score-bar-wrap">
                      <div class="bd-score-bar" style="width:<?php echo esc_attr((string)$bar_pct); ?>%"></div>
                    </div>
                    <span class="bd-score-text"><?php echo esc_html("{$bd_points}/{$bd_max}"); ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="score-breakdown-empty">内訳は集計中です</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 結論サマリー + ハイライト（インフォ内に統合） -->
  <?php if (!empty($monthly_report)): ?>
    <div class="info-monthly" data-ai-section="summary">
      <div class="info-monthly-head">
        <div class="info-monthly-title">
          <span class="info-monthly-pin">📌</span>
          <span>結論サマリー</span>
        </div>
        <?php if ($can_ai): ?>
        <button type="button" class="ask-ai-btn" data-ai-ask
          data-ai-instruction="今月の月次レポート結果を見て、いちばん重要な気づきと次にやることを3つ教えて">
          <span class="ask-ai-btn__icon" aria-hidden="true">✨</span>AIに聞く
        </button>
        <?php endif; ?>
      </div>

      <div class="info-monthly-summary">
        <?php if (!empty($monthly_report['summary'])): ?>
          <?php echo enhance_report_text($monthly_report['summary'], 'default'); ?>
        <?php else: ?>
          <p class="info-monthly-wait">今月のレポートサマリーを生成中です...</p>
        <?php endif; ?>
      </div>


<?php if ($can_highlights): ?>
<div class="info-monthly-highlights">
<?php
$next_action = !empty($infographic['action'])
    ? $infographic['action']
    : ($highlights['opportunity'] ?? '改善施策を検討');

$highlight_items = [
    ['label' => '📈 今月うまくいっていること',  'value' => $highlights['most_important'] ?? '新規ユーザー獲得', 'key' => 'most_important', 'ai_instruction' => 'この「良かった点」を踏まえて、次に伸ばすべきポイントは？'],
    ['label' => '⚠️ 今いちばん気をつけたい点',  'value' => $highlights['top_issue'] ?? 'ゴール改善',    'key' => 'top_issue',       'ai_instruction' => 'この「課題」の原因と、最短で効く改善を3つ提案して'],
    ['label' => '🎯 次にやるとよいこと',         'value' => $next_action,                                       'key' => 'opportunity',     'ai_instruction' => 'この「次にやること」を具体的な手順に分解して教えて'],
];

$section_type_map = [
    'most_important' => 'highlight_good',
    'top_issue'      => 'highlight_issue',
    'opportunity'    => 'highlight_action',
];

foreach ($highlight_items as $highlight):
    $detail    = $highlight_details[$highlight['key']] ?? null;
    $detail_id = 'highlight-detail-' . esc_attr($highlight['key']);
?>
    <div class="info-monthly-highlight-item" data-ai-section="<?php echo esc_attr( $section_type_map[ $highlight['key'] ] ?? 'highlight' ); ?>">
        <div class="info-monthly-highlight-label">
            <?php echo esc_html($highlight['label']); ?>
        </div>
        <div class="info-monthly-highlight-value">
            <?php echo esc_html($highlight['value']); ?>
        </div>
        <button type="button" class="ask-ai-btn ask-ai-btn--sm" data-ai-ask
          data-ai-instruction="<?php echo esc_attr($highlight['ai_instruction']); ?>">
          <span class="ask-ai-btn__icon" aria-hidden="true">✨</span>AIに聞く
        </button>

        <?php if ($detail && (!empty($detail['fact']) || !empty($detail['causes']) || !empty($detail['actions']))): ?>
        <details class="highlight-detail-accordion" id="<?php echo $detail_id; ?>">
            <summary class="highlight-detail-toggle"
                     aria-expanded="false"
                     aria-controls="<?php echo $detail_id; ?>-body">
                <span>詳しく見る</span>
                <span class="highlight-detail-arrow" aria-hidden="true">▾</span>
            </summary>
            <div class="highlight-detail-body" id="<?php echo $detail_id; ?>-body" role="region">
                <?php if (!empty($detail['fact'])): ?>
                <div class="highlight-detail-section">
                    <div class="highlight-detail-section-label">📊 何が起きているか</div>
                    <p class="highlight-detail-section-text"><?php echo esc_html($detail['fact']); ?></p>
                </div>
                <?php endif; ?>
                <?php if (!empty($detail['causes'])): ?>
                <div class="highlight-detail-section">
                    <div class="highlight-detail-section-label">🔍 考えられる原因</div>
                    <ul class="highlight-detail-list">
                    <?php foreach ($detail['causes'] as $cause): ?>
                        <li><?php echo esc_html($cause); ?></li>
                    <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if (!empty($detail['actions'])): ?>
                <div class="highlight-detail-section">
                    <div class="highlight-detail-section-label">✅ 次にやること</div>
                    <ul class="highlight-detail-list">
                    <?php foreach ($detail['actions'] as $act): ?>
                        <li><?php echo esc_html($act); ?></li>
                    <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
<?php else: ?>
<!-- ベーシックプラン: ロック表示 -->
<div class="plan-locked-section">
    <div class="plan-locked-overlay">
        <div class="plan-locked-icon">&#x1F512;</div>
        <p class="plan-locked-message">AIサポートプランで、改善ポイントや<br>次にやるべきことのアドバイスが見られます</p>
        <a href="<?php echo esc_url( home_url( '/service/' ) ); ?>" class="plan-locked-link">プランを見る →</a>
    </div>
</div>
<?php endif; ?>

    </div>

  <?php else: ?>
    <!-- 月次レポート未生成の場合（インフォ内に表示） -->
    <div id="monthly-report-empty" class="monthly-section monthly-empty">
      <div class="monthly-empty-icon">📊</div>

      <h3 class="monthly-empty-title">
        <?php echo esc_html($prev_month_start->format('Y年n月')); ?>のAIレポートはまだ生成されていません
      </h3>

      <p class="monthly-empty-text">
        まずはAIレポート設定画面で、目標や重点ポイントなどの詳細を設定してください。<br />
        設定内容に基づいて、AIレポートを生成します。
      </p>

      <?php
      // 前々月データチェック（軽量版: GA4設定の有無のみ確認、重いAPI呼び出しを回避）
      $config_tmp = new Gcrev_Config();
      $user_config = $config_tmp->get_user_config($user_id);
      $has_ga4 = !empty($user_config['ga4_id']);
      $prev2_check = $has_ga4
          ? ['available' => true]
          : ['available' => false, 'reason' => 'GA4プロパティが設定されていません。'];
      if (!$prev2_check['available']):
      ?>
      <div class="gcrev-notice-prev2">
        <span class="notice-icon">⚠️</span>
        <div class="notice-text">
          <strong>AIレポートを生成できません。</strong><br>
          <?php echo esc_html($prev2_check['reason'] ?? 'GA4プロパティの設定を確認してください。'); ?>
        </div>
      </div>
      <?php elseif (!empty($prev2_check['is_zero'])): ?>
      <div class="gcrev-notice-prev2" style="background: #EFF6FF; border-left-color: #3B82F6;">
        <span class="notice-icon">ℹ️</span>
        <div class="notice-text">
          前々月のアクセスデータがゼロのため、「ゼロからの成長」としてレポートが生成されます。
        </div>
      </div>
      <?php endif; ?>

      <button
        class="monthly-empty-btn"
        onclick="window.location.href='<?php echo esc_url(home_url('/mypage/report-settings/')); ?>'"
      >
        🤖 AIレポート設定へ進む
      </button>

      <div id="report-generation-status" class="monthly-empty-status" style="display:none;">
        <div class="loading-spinner"></div>
        <span>レポートを生成中です...</span>
      </div>
    </div>
  <?php endif; ?>

</section>


<?php else: ?>
<!-- レポート未生成：設定画面への誘導 -->
<section class="dashboard-setup-guide">
  <div class="setup-guide-icon">🚀</div>
  <h2 class="setup-guide-title">AIレポートを始めましょう</h2>
  <p class="setup-guide-desc">
    まだレポートが生成されていません。<br>
    レポート設定画面で、対象サイトや目標を登録すると、<br>
    AIが毎月のホームページの状態を自動で分析・レポートします。
  </p>
  <div class="setup-guide-steps">
    <div class="setup-guide-step">
      <span class="setup-guide-step-num">1</span>
      <span>レポート設定で<strong>対象サイト</strong>と<strong>目標</strong>を登録</span>
    </div>
    <div class="setup-guide-step">
      <span class="setup-guide-step-num">2</span>
      <span>AIが自動でデータを分析・<strong>レポート生成</strong></span>
    </div>
    <div class="setup-guide-step">
      <span class="setup-guide-step-num">3</span>
      <span>毎月この画面に<strong>スコアやハイライト</strong>が表示されます</span>
    </div>
  </div>
  <a href="<?php echo esc_url( home_url('/mypage/report-settings/') ); ?>" class="setup-guide-btn">
    ⚙️ レポート設定へ進む
  </a>
</section>
<?php endif; ?>

<!-- ============================================================
     MEO（Googleマップの見え方）セクション
     ============================================================ -->
<section class="meo-section" id="meoSection">
    <div class="meo-header">
        <div class="meo-header__title">
            &#x1F4CD; Googleマップの見え方
        </div>
    </div>

    <div class="meo-help">
        Googleマップやローカル検索で、あなたのお店が<strong>何番目に表示されるか</strong>、
        口コミの状況、近くの競合との比較をまとめています。
    </div>

    <!-- 計測条件エリア（将来: 地点・半径・地域セレクト等を追加可能） -->
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
        <!-- 半径（座標モード時のみ表示） -->
        <div class="meo-radius-group" id="meoRadiusGroup" style="display:none;">
            <span class="meo-radius-label">半径</span>
            <select class="meo-radius-select" id="meoRadiusSelect"></select>
        </div>
        <!-- キーワード -->
        <div class="meo-keyword-group" id="meoKeywordGroup">
            <span class="meo-keyword-label">計測キーワード</span>
            <span class="meo-keyword-single" id="meoKeywordSingle"></span>
            <select class="meo-keyword-select" id="meoKeywordSelect" style="display:none;"></select>
        </div>
    </div>

    <!-- メトリクスカード 4枚 -->
    <div class="meo-metrics-cards" id="meoMetricsCards"></div>

    <!-- 週次履歴テーブル -->
    <div class="meo-history-wrap" id="meoHistoryWrap" style="display:none;">
        <div class="meo-history-title">&#x1F4CA; 週次推移</div>
        <div class="meo-help" style="margin-bottom:8px;">
            <span style="color:#6b7280; font-size:12px;">毎週月曜日に自動計測しています。</span>
        </div>
        <div class="meo-table-scroll">
            <table class="meo-history-table">
                <thead id="meoHistoryHead"></thead>
                <tbody id="meoHistoryBody"></tbody>
            </table>
        </div>
    </div>

    <!-- 店舗情報 -->
    <div class="meo-store-card" id="meoStoreCard" style="display:none;"></div>

    <!-- 口コミ状況 -->
    <div class="meo-reviews-card" id="meoReviewsCard" style="display:none;"></div>

    <!-- 競合比較 -->
    <div class="meo-competitor-wrap" id="meoCompetitorWrap" style="display:none;"></div>

    <!-- 状態表示 -->
    <div class="meo-loading" id="meoLoading">データを取得中...</div>
    <div class="meo-empty" id="meoEmpty" style="display:none;">
        <div class="meo-empty__icon">&#x1F4CD;</div>
        <div class="meo-empty__text">MEOデータがまだありません</div>
        <div style="color:#9ca3af; font-size:12px; margin-top:6px;">
            <a href="<?php echo esc_url( home_url( '/mypage/rank-tracker/' ) ); ?>" style="color:#568184;">検索順位チェック</a>でキーワードを登録すると、Googleマップでの順位も確認できます。
        </div>
    </div>
    <div class="meo-error" id="meoError" style="display:none;"></div>
</section>

<!-- ============================================================
     計測キーワードランキング（ダッシュボード版）
     ============================================================ -->
<section class="drt-section" id="drtSection">
    <div class="drt-header">
        <div class="drt-header__title">
            &#x1F4C8; 計測キーワードランキング
        </div>
    </div>

    <div class="drt-help">
        Google で検索した時に、あなたのホームページが<strong>何番目に表示されるか</strong>をチェックしています。
        数字が小さいほど上位表示されています。「<strong>圏外</strong>」は100位以内に表示されなかったことを意味します。
        <br><span style="color:#6b7280; font-size:12px;">毎週月曜日に自動計測しています。</span>
    </div>

    <div class="drt-device-toggle" id="drtDeviceToggle">
        <button class="drt-device-btn active" data-device="mobile">スマホ</button>
        <button class="drt-device-btn" data-device="desktop">PC</button>
    </div>

    <div class="drt-summary-cards" id="drtSummaryCards">
        <div class="drt-summary-card drt-summary-card--gold">
            <span class="drt-summary-card__dot drt-summary-card__dot--gold"></span>
            <span class="drt-summary-card__label">1位〜3位</span>
            <span class="drt-summary-card__count" id="drtSummary13">-<span class="drt-summary-card__unit">件</span></span>
        </div>
        <div class="drt-summary-card drt-summary-card--blue">
            <span class="drt-summary-card__dot drt-summary-card__dot--blue"></span>
            <span class="drt-summary-card__label">4位〜10位</span>
            <span class="drt-summary-card__count" id="drtSummary410">-<span class="drt-summary-card__unit">件</span></span>
        </div>
        <div class="drt-summary-card drt-summary-card--green">
            <span class="drt-summary-card__dot drt-summary-card__dot--green"></span>
            <span class="drt-summary-card__label">11位〜20位</span>
            <span class="drt-summary-card__count" id="drtSummary1120">-<span class="drt-summary-card__unit">件</span></span>
        </div>
        <div class="drt-summary-card drt-summary-card--red">
            <span class="drt-summary-card__dot drt-summary-card__dot--red"></span>
            <span class="drt-summary-card__label">圏外(20位以下)</span>
            <span class="drt-summary-card__count" id="drtSummaryOut">-<span class="drt-summary-card__unit">件</span></span>
        </div>
    </div>

    <div id="drtTableWrap">
        <div class="drt-loading" id="drtLoading">データを取得中...</div>
        <div class="drt-empty" id="drtEmptyState" style="display:none;">
            <div class="drt-empty__icon">&#x1F50D;</div>
            <div class="drt-empty__text">計測キーワードが登録されていません</div>
            <div style="color:#9ca3af; font-size:12px; margin-top:6px;">
                <a href="<?php echo esc_url(home_url('/mypage/rank-tracker/')); ?>" style="color:#568184;">検索順位チェック</a>ページからキーワードを追加できます。
            </div>
        </div>
        <div class="drt-table-wrap" id="drtTableContainer" style="display:none;">
            <div class="drt-table-scroll">
                <table class="drt-table" id="drtTable">
                    <thead id="drtTableHead"></thead>
                    <tbody id="drtTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="drt-footer" id="drtFooter" style="display:none;">
        <a href="<?php echo esc_url(home_url('/mypage/rank-tracker/')); ?>" class="drt-footer__link">
            検索順位チェックの詳細ページへ &#x2192;
        </a>
    </div>
</section>

<!-- SERP Top modal (dashboard) -->
<div class="drt-modal-overlay" id="drtSerpModal">
    <div class="drt-modal">
        <div class="drt-modal__header">
            <div class="drt-modal__title" id="drtSerpModalTitle">上位ランキング</div>
            <button class="drt-modal__close" id="drtSerpModalClose">&times;</button>
        </div>
        <div class="drt-modal__toolbar">
            <div class="drt-device-toggle drt-device-toggle--modal" id="drtSerpDeviceToggle">
                <button class="drt-device-btn active" data-device="mobile">スマホ</button>
                <button class="drt-device-btn" data-device="desktop">PC</button>
            </div>
            <a class="drt-serp-google-link" id="drtSerpGoogleLink" href="#" target="_blank" rel="noopener">Google検索結果を見る →</a>
        </div>
        <div class="drt-serp-note">
            順位は、指定条件で取得した参考値です。実際のGoogle検索結果は、地域・端末・検索設定・時間帯などにより異なる場合があります。
        </div>
        <div class="drt-modal__body" id="drtSerpModalBody">
            <div class="drt-loading">読み込み中...</div>
        </div>
    </div>
</div>

<!-- Progress overlay (dashboard fetch) -->
<div class="drt-progress-overlay" id="drtProgressOverlay">
    <div class="drt-progress-box">
        <div class="drt-progress-title" id="drtProgressTitle">最新の順位を取得中...</div>
        <div class="drt-progress-bar-wrap">
            <div class="drt-progress-bar drt-progress-bar--indeterminate" id="drtProgressBar"></div>
        </div>
        <div class="drt-progress-text" id="drtProgressText">キーワードの順位を取得しています...</div>
        <div class="drt-progress-sub" id="drtProgressSub">しばらくお待ちください</div>
    </div>
</div>

</div><!-- .content-area -->

<script>
(function(){
    // KPI更新の共通関数
    function fmt(n){ return n.toLocaleString(); }
    function updateInfoKpi(key, value, diff){
        var el = document.querySelector('[data-kpi-key="' + key + '"]');
        if(!el) return;
        var valEl = el.querySelector('[data-kpi-role="value"]');
        var diffEl = el.querySelector('[data-kpi-role="diff"]');
        if(valEl) valEl.textContent = fmt(value);
        if(diffEl){
            var icon = diff > 0 ? '↑' : (diff < 0 ? '↓' : '→');
            var cls  = diff > 0 ? 'positive' : (diff < 0 ? 'negative' : 'neutral');
            diffEl.textContent = icon + ' ' + (diff > 0 ? '+' : '') + fmt(diff);
            diffEl.className = 'info-kpi-diff ' + cls;
        }
    }

    <?php if (!empty($kpi_curr)): ?>
    // --- キャッシュヒット: サーバーサイドで取得済みのデータをそのまま適用 ---
    var curr = <?php echo wp_json_encode([
        'sessions'    => $kpi_curr['sessions'] ?? 0,
        'conversions' => $kpi_curr['conversions'] ?? 0,
    ]); ?>;
    var prev = <?php echo wp_json_encode($kpi_prev ? [
        'sessions'    => $kpi_prev['sessions'] ?? 0,
        'conversions' => $kpi_prev['conversions'] ?? 0,
    ] : null); ?>;

    var currSessions = parseInt(String(curr.sessions || 0).replace(/,/g, ''), 10);
    var prevSessions = prev ? parseInt(String(prev.sessions || 0).replace(/,/g, ''), 10) : 0;
    updateInfoKpi('visits', currSessions, currSessions - prevSessions);

    var currCv = parseInt(String(curr.conversions || 0).replace(/,/g, ''), 10);
    var prevCv = prev ? parseInt(String(prev.conversions || 0).replace(/,/g, ''), 10) : 0;
    updateInfoKpi('cv', currCv, currCv - prevCv);

    // MEO（PHP側で取得済み）
    var meoCurr = <?php echo (int)($meo_curr ?? 0); ?>;
    var meoPrev = <?php echo (int)($meo_prev ?? 0); ?>;
    updateInfoKpi('meo', meoCurr, meoCurr - meoPrev);

    <?php else: ?>
    // --- キャッシュミス: REST API で非同期取得（スケルトン＋スピナー表示） ---
    (function(){
        var restBase = <?php echo wp_json_encode(esc_url_raw(rest_url('gcrev/v1/'))); ?>;
        var nonce    = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
        var compStart = <?php echo wp_json_encode($last30_comp['start']); ?>;
        var compEnd   = <?php echo wp_json_encode($last30_comp['end']); ?>;

        // 3カード全てローディング表示（MEO含む）
        var kpiKeys = ['visits', 'cv', 'meo'];
        kpiKeys.forEach(function(key){
            var card = document.querySelector('[data-kpi-key="' + key + '"]');
            if (!card) return;
            var valEl  = card.querySelector('[data-kpi-role="value"]');
            var diffEl = card.querySelector('[data-kpi-role="diff"]');
            if (valEl) {
                valEl.dataset.originalText = valEl.textContent;
                valEl.textContent = '\u8aad\u307f\u8fbc\u307f\u4e2d';
            }
            if (diffEl) {
                diffEl.dataset.originalText = diffEl.textContent;
                diffEl.textContent = '';
            }
            card.classList.add('is-kpi-loading');
            card.setAttribute('aria-busy', 'true');
            var loadEl = document.createElement('span');
            loadEl.className = 'info-kpi-loading-text';
            loadEl.innerHTML = '<span class="info-kpi-spinner"></span>\u8aad\u307f\u8fbc\u307f\u4e2d\u2026';
            loadEl.dataset.kpiRole = 'loading-indicator';
            card.appendChild(loadEl);
        });

        // タイムアウト警告（8秒後）
        var timeoutId = setTimeout(function(){
            kpiKeys.forEach(function(key){
                var card = document.querySelector('[data-kpi-key="' + key + '"]');
                if (!card || !card.classList.contains('is-kpi-loading')) return;
                if (card.querySelector('.info-kpi-timeout-text')) return;
                var el = document.createElement('span');
                el.className = 'info-kpi-timeout-text';
                el.textContent = '\u6642\u9593\u304c\u304b\u304b\u3063\u3066\u3044\u307e\u3059\u2026';
                card.appendChild(el);
            });
        }, 8000);

        // ローディング解除＋フェードイン
        function finishCard(key){
            var card = document.querySelector('[data-kpi-key="' + key + '"]');
            if (!card) return;
            card.classList.remove('is-kpi-loading');
            card.classList.add('is-kpi-loaded');
            card.setAttribute('aria-busy', 'false');
            var els = card.querySelectorAll('[data-kpi-role="loading-indicator"], .info-kpi-timeout-text');
            els.forEach(function(e){ e.remove(); });
        }

        // エラー表示＋再取得ボタン
        function errorCard(key){
            var card = document.querySelector('[data-kpi-key="' + key + '"]');
            if (!card) return;
            card.classList.remove('is-kpi-loading');
            card.classList.add('is-kpi-error');
            card.setAttribute('aria-busy', 'false');
            var els = card.querySelectorAll('[data-kpi-role="loading-indicator"], .info-kpi-timeout-text');
            els.forEach(function(e){ e.remove(); });
            var valEl = card.querySelector('[data-kpi-role="value"]');
            if (valEl) valEl.textContent = '\u53d6\u5f97\u306b\u5931\u6557\u3057\u307e\u3057\u305f';
            var diffEl = card.querySelector('[data-kpi-role="diff"]');
            if (diffEl) diffEl.textContent = '';
            var retryBtn = document.createElement('button');
            retryBtn.type = 'button';
            retryBtn.className = 'info-kpi-retry-btn';
            retryBtn.textContent = '\u518d\u53d6\u5f97';
            retryBtn.addEventListener('click', function(e){
                e.stopPropagation();
                location.reload();
            });
            card.appendChild(retryBtn);
        }

        // 直近30日 + 比較期間 + MEO を並列取得
        Promise.all([
            fetch(restBase + 'dashboard/kpi?period=last30', {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce }
            }).then(function(r){ return r.json(); }),
            fetch(restBase + 'dashboard/kpi?start_date=' + encodeURIComponent(compStart) + '&end_date=' + encodeURIComponent(compEnd), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce }
            }).then(function(r){ return r.json(); }),
            fetch(restBase + 'meo/dashboard?period=last30', {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce }
            }).then(function(r){ return r.json(); })
        ]).then(function(results){
            clearTimeout(timeoutId);
            var curr = results[0].success ? results[0].data : null;
            var prev = results[1].success ? results[1].data : null;
            var meoData = results[2];

            if (!curr) {
                ['visits', 'cv'].forEach(function(k){ errorCard(k); });
            } else {
                var cS = parseInt(String(curr.sessions || 0).replace(/,/g, ''), 10);
                var pS = prev ? parseInt(String(prev.sessions || 0).replace(/,/g, ''), 10) : 0;
                updateInfoKpi('visits', cS, cS - pS);
                finishCard('visits');

                var cC = parseInt(String(curr.conversions || 0).replace(/,/g, ''), 10);
                var pC = prev ? parseInt(String(prev.conversions || 0).replace(/,/g, ''), 10) : 0;
                updateInfoKpi('cv', cC, cC - pC);
                finishCard('cv');
            }

            // MEO（meo/dashboard は metrics + metrics_previous を一括返却）
            var mCurr = (meoData && meoData.metrics) ? parseInt(meoData.metrics.total_impressions || 0, 10) : 0;
            var mPrev = (meoData && meoData.metrics_previous) ? parseInt(meoData.metrics_previous.total_impressions || 0, 10) : 0;
            updateInfoKpi('meo', mCurr, mCurr - mPrev);
            finishCard('meo');
        }).catch(function(err){
            clearTimeout(timeoutId);
            console.error('[GCREV] KPI async fetch error:', err);
            kpiKeys.forEach(function(k){ errorCard(k); });
        });
    })();
    <?php endif; ?>
})();

// --- KPI トレンドチャート（インライン常時表示） ---
(function(){
    var restBase = '<?php echo esc_url(rest_url('gcrev/v1/')); ?>';
    var nonce    = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
    var kpiTrendChart = null;
    // ビュー別キャッシュ: _trendCache[view][metric]
    var _trendCache   = { daily: {}, monthly: {} };
    var _activeMetric = null;
    var _activeView   = 'daily'; // デフォルトは直近30日（日別）
    var _activeLabel  = null;
    var _activeIcon   = null;
    var _retryMetric  = null;
    var _retryLabel   = null;
    var _retryIcon    = null;

    // DOM参照
    var titleText = document.getElementById('kpiTrendTitleText');
    var titleIcon = document.getElementById('kpiTrendIcon');
    var loading   = document.getElementById('kpiTrendLoading');
    var chartWrap = document.getElementById('kpiTrendChartWrap');
    var errorEl   = document.getElementById('kpiTrendError');
    var retryBtn  = document.getElementById('kpiTrendRetry');

    // (0) 期間トグルボタンを挿入（チャートヘッダー内）
    var trendHeader = document.querySelector('.kpi-trend-inline-header');
    if (trendHeader) {
        var toggleDiv = document.createElement('div');
        toggleDiv.className = 'kpi-trend-toggle';
        toggleDiv.id = 'kpiTrendToggle';
        toggleDiv.innerHTML =
            '<button type="button" class="kpi-trend-toggle-btn is-active" data-view="daily">直近30日</button>' +
            '<button type="button" class="kpi-trend-toggle-btn" data-view="monthly">1年間</button>';
        trendHeader.appendChild(toggleDiv);
    }

    // (1) 即時データ先読み — dailyビューの全3指標をfetch
    ['sessions', 'cv', 'meo'].forEach(function(m){
        fetch(restBase + 'dashboard/trends?metric=' + encodeURIComponent(m) + '&view=daily', {
            headers: { 'X-WP-Nonce': nonce },
            credentials: 'same-origin'
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            _trendCache.daily[m] = json;
            if (m === 'sessions' && !_activeMetric) {
                showTrend('sessions', '訪問数', '👥');
            }
        })
        .catch(function(){
            if (m === 'sessions' && !_activeMetric) {
                showError('sessions', '訪問数', '👥');
            }
        });
    });

    // (2) KPIカードクリックでチャート切替
    document.querySelectorAll('.info-kpi-item[data-metric]').forEach(function(card){
        card.addEventListener('click', function(){
            var metric = card.dataset.metric;
            var label  = card.querySelector('.info-kpi-label').textContent.trim();
            var icon   = card.dataset.kpiIcon || '📊';
            showTrend(metric, label, icon);
        });
    });

    // (2b) 期間トグルクリック
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.kpi-trend-toggle-btn');
        if (!btn) return;
        var newView = btn.dataset.view;
        if (newView === _activeView) return;
        _activeView = newView;
        // ボタン状態更新
        document.querySelectorAll('.kpi-trend-toggle-btn').forEach(function(b){
            b.classList.toggle('is-active', b.dataset.view === newView);
        });
        // 現在のメトリクスで再描画（強制）
        if (_activeMetric) {
            var m = _activeMetric;
            _activeMetric = null; // ガードリセット
            showTrend(m, _activeLabel, _activeIcon);
        }
    });

    // (3) アクティブカード状態更新（is-active + aria-pressed）
    function setActiveCard(metric) {
        document.querySelectorAll('.info-kpi-item[data-metric]').forEach(function(card){
            if (card.dataset.metric === metric) {
                card.classList.add('is-active');
                card.setAttribute('aria-pressed', 'true');
            } else {
                card.classList.remove('is-active');
                card.setAttribute('aria-pressed', 'false');
            }
        });
    }

    // (4) チャート表示メイン関数
    function showTrend(metric, label, icon) {
        if (_activeMetric === metric) return;
        _activeMetric = metric;
        _activeLabel  = label;
        _activeIcon   = icon;
        setActiveCard(metric);

        var viewLabel = _activeView === 'daily' ? '直近30日の推移' : '1年間の推移';
        titleText.textContent = label + ' — ' + viewLabel;
        titleIcon.textContent = icon;

        errorEl.style.display = 'none';

        // ビュー別キャッシュ確認
        var cache = _trendCache[_activeView];
        if (cache[metric]) {
            chartWrap.style.opacity = '0';
            loading.classList.remove('active');
            chartWrap.style.display = 'block';
            renderTrendChart(cache[metric], label);
            requestAnimationFrame(function(){
                requestAnimationFrame(function(){
                    chartWrap.style.opacity = '1';
                });
            });
            return;
        }

        // なければローディング表示 → API取得
        chartWrap.style.display = 'none';
        loading.classList.add('active');

        fetch(restBase + 'dashboard/trends?metric=' + encodeURIComponent(metric) + '&view=' + _activeView, {
            headers: { 'X-WP-Nonce': nonce },
            credentials: 'same-origin'
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            _trendCache[_activeView][metric] = json;
            if (_activeMetric !== metric) return;
            loading.classList.remove('active');
            chartWrap.style.display = 'block';
            chartWrap.style.opacity = '1';
            renderTrendChart(json, label);
        })
        .catch(function(){
            if (_activeMetric !== metric) return;
            showError(metric, label, icon);
        });
    }

    // (5) エラー表示 + 再試行対応
    function showError(metric, label, icon) {
        loading.classList.remove('active');
        chartWrap.style.display = 'none';
        errorEl.style.display = 'block';
        _retryMetric = metric;
        _retryLabel  = label;
        _retryIcon   = icon;
    }

    if (retryBtn) {
        retryBtn.addEventListener('click', function(){
            if (!_retryMetric) return;
            var m = _retryMetric;
            var l = _retryLabel;
            var i = _retryIcon;
            _activeMetric = null;
            delete _trendCache[_activeView][m];
            errorEl.style.display = 'none';
            showTrend(m, l, i);
        });
    }

    // (6) Chart.js レンダリング
    function renderTrendChart(json, label) {
        if (kpiTrendChart) { kpiTrendChart.destroy(); kpiTrendChart = null; }

        if (!json.success || !json.values) {
            chartWrap.style.display = 'none';
            errorEl.style.display = 'block';
            return;
        }

        var view = json.view || 'monthly';
        chartWrap.innerHTML = '<canvas id="kpiTrendChart"></canvas>';

        // 日付ラベルをビューに応じてフォーマット
        var shortLabels;
        if (view === 'daily') {
            shortLabels = json.labels.map(function(d){
                var parts = d.split('-');
                return parseInt(parts[1], 10) + '/' + parseInt(parts[2], 10);
            });
        } else {
            shortLabels = json.labels.map(function(ym){
                return parseInt(ym.split('-')[1], 10) + '月';
            });
        }

        var dataLen = json.values.length;
        var pointBg = json.values.map(function(v, i){
            return i === dataLen - 1 ? '#C95A4F' : '#568184';
        });
        var pointR = json.values.map(function(v, i){
            if (view === 'daily') return i === dataLen - 1 ? 4 : 2;
            return i === dataLen - 1 ? 6 : 3;
        });

        var isDaily = view === 'daily';

        kpiTrendChart = new Chart('kpiTrendChart', {
            type: 'line',
            data: {
                labels: shortLabels,
                datasets: [{
                    label: label,
                    data: json.values,
                    borderColor: '#568184',
                    borderWidth: 2.5,
                    pointBackgroundColor: pointBg,
                    pointRadius: pointR,
                    pointHitRadius: isDaily ? 8 : 15,
                    pointHoverRadius: isDaily ? 5 : 7,
                    tension: 0.3,
                    fill: true,
                    backgroundColor: 'rgba(86,129,132,0.13)',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onHover: function(evt, elements) {
                    evt.native.target.style.cursor = (elements.length && !isDaily) ? 'pointer' : 'default';
                },
                onClick: function(evt, elements) {
                    // 日別表示ではドリルダウン無効
                    if (isDaily || !elements.length) return;
                    var idx = elements[0].index;
                    var month = json.labels[idx];
                    showDrilldownPopover(month, elements[0].element);
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(ctx){
                                if (isDaily) {
                                    return json.labels[ctx[0].dataIndex];
                                }
                                return json.labels[ctx[0].dataIndex];
                            },
                            label: function(ctx){ return label + ': ' + ctx.parsed.y.toLocaleString(); },
                            afterLabel: function(){
                                return isDaily ? '' : 'クリックして詳細を表示';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: isDaily ? {
                            maxTicksAtIndex: 10,
                            maxRotation: 0,
                            autoSkip: true,
                            autoSkipPadding: 8
                        } : {}
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { callback: function(v){ return v.toLocaleString(); } }
                    }
                }
            }
        });
    }

    // --- ドリルダウン ---
    var ddPopover    = document.getElementById('drilldownPopover');
    var ddPopTitle   = document.getElementById('drilldownPopoverTitle');
    var ddOverlay    = document.getElementById('drilldownOverlay');
    var ddModalTitle = document.getElementById('drilldownModalTitle');
    var ddLoading    = document.getElementById('drilldownLoading');
    var ddChartWrap  = document.getElementById('drilldownChartWrap');
    var ddEmpty      = document.getElementById('drilldownEmpty');
    var ddError      = document.getElementById('drilldownError');
    var ddClose      = document.getElementById('drilldownModalClose');
    var ddChart      = null;
    var _ddCache     = {};
    var _ddMonth     = null;

    // ── 指標別ポップオーバーラベル定義 ──
    var _ddLabels = {
        sessions: {
            region: { label: '見ている人の場所',       help: 'ホームページを見ている人が、どの地域からアクセスしているかを表しています' },
            page:   { label: '訪問の入口となったページ', help: '検索やSNS、広告などから、最初に表示されたページです' },
            source: { label: '見つけたきっかけ',        help: '検索、SNS、広告、他サイトなど、ホームページを知った経路です' }
        },
        cv: {
            region: { label: 'ゴールが発生した地域',     help: 'ゴール（お問い合わせなど）が発生したユーザーの地域です' },
            page:   { label: 'ゴールに至った入口ページ',  help: 'ゴールにつながった最初のページです' },
            source: { label: 'ゴールに至ったきっかけ',    help: 'ゴールにつながった流入経路です' }
        },
        meo: {
            region: { label: 'Googleマップで表示された地域', help: 'Googleマップでお店の情報が表示されたユーザーのエリアです' }
        }
    };
    // 指標ごとのポップオーバー表示項目
    var _ddVisibleTypes = {
        sessions: ['region', 'page', 'source'],
        cv:       ['region', 'page', 'source'],
        meo:      ['region']
    };

    /**
     * ポップオーバー位置計算ユーティリティ（再利用可能）
     * position:fixed でビューポート基準に配置。
     *
     * @param {HTMLElement} popover   表示するポップオーバー要素
     * @param {number}      anchorX   アンカーのビューポートX座標
     * @param {number}      anchorY   アンカーのビューポートY座標
     * @param {Object}      [opts]
     *   offsetX  : 水平オフセット（正=右）default 10
     *   offsetY  : 垂直オフセット（負=上）default -10
     */
    function positionPopover(popover, anchorX, anchorY, opts) {
        opts = opts || {};
        var ox     = opts.offsetX != null ? opts.offsetX : 10;
        var oy     = opts.offsetY != null ? opts.offsetY : -10;
        var margin = 8;
        var vw     = window.innerWidth;
        var vh     = window.innerHeight;

        popover.style.display = 'block';
        var popW = popover.offsetWidth;
        var popH = popover.offsetHeight;

        // 水平: 基本は右、はみ出すなら左に反転
        var left = anchorX + ox;
        if (left + popW > vw - margin) {
            left = anchorX - popW - ox;
        }
        left = Math.max(margin, Math.min(left, vw - popW - margin));

        // 垂直: 基本は上（oy が負）、はみ出すなら下に反転
        var top = anchorY + oy;
        if (top < margin) {
            top = anchorY + Math.abs(oy);
        }
        top = Math.max(margin, Math.min(top, vh - popH - margin));

        popover.style.position = 'fixed';
        popover.style.left = left + 'px';
        popover.style.top  = top  + 'px';
    }

    function showDrilldownPopover(month, pointEl) {
        _ddMonth = month;
        var parts = month.split('-');
        ddPopTitle.textContent = parts[0] + '年' + parseInt(parts[1], 10) + '月';

        // ── 指標に応じてポップオーバー項目を切り替え ──
        var ddMetric = _activeMetric || 'sessions';
        var visibleTypes = _ddVisibleTypes[ddMetric] || _ddVisibleTypes.sessions;
        var ddLabelsMap  = _ddLabels[ddMetric] || _ddLabels.sessions;

        ['region', 'page', 'source'].forEach(function(t) {
            var item = document.getElementById('ddItem_' + t);
            if (!item) return;
            item.style.display = visibleTypes.indexOf(t) >= 0 ? '' : 'none';

            var lbl     = ddLabelsMap[t] || _ddLabels.sessions[t];
            var elLabel = document.getElementById('ddLabel_' + t);
            var elHelp  = document.getElementById('ddHelp_' + t);
            if (elLabel) elLabel.textContent = lbl.label;
            if (elHelp)  elHelp.textContent  = lbl.help;
        });

        // ── Chart.js ポイント座標 → ビューポート座標 ──
        // Chart.js v4 の element.x/y は CSS pixel 座標。
        // canvas.getBoundingClientRect() の left/top を足すだけで viewport 座標になる。
        // ※ canvas.width は CSS幅×devicePixelRatio なので割ってはいけない。
        var canvas = kpiTrendChart.canvas;
        var rect   = canvas.getBoundingClientRect();
        var vpX    = rect.left + pointEl.x;
        var vpY    = rect.top  + pointEl.y;

        // デバッグマーカー（アンカー位置の目視確認用・確認後に削除）
        var marker = document.getElementById('_ddDebugMarker');
        if (!marker) {
            marker = document.createElement('div');
            marker.id = '_ddDebugMarker';
            marker.style.cssText = 'position:fixed;width:10px;height:10px;background:red;border-radius:50%;z-index:99999;pointer-events:none;box-shadow:0 0 4px red;';
            document.body.appendChild(marker);
        }
        marker.style.left    = (vpX - 5) + 'px';
        marker.style.top     = (vpY - 5) + 'px';
        marker.style.display = 'block';

        positionPopover(ddPopover, vpX, vpY, { offsetX: 10, offsetY: -10 });

        // 初回のみ補足説明を表示（localStorage で制御）
        var helpEls = ddPopover.querySelectorAll('.drilldown-popover-help');
        var helpSeen = false;
        try { helpSeen = localStorage.getItem('mw_dd_help_seen') === '1'; } catch(e){}
        for (var i = 0; i < helpEls.length; i++) {
            helpEls[i].style.display = helpSeen ? 'none' : 'block';
        }
        if (!helpSeen) {
            try { localStorage.setItem('mw_dd_help_seen', '1'); } catch(e){}
        }
    }

    function hideDrilldownPopover() {
        ddPopover.style.display = 'none';
        var marker = document.getElementById('_ddDebugMarker');
        if (marker) marker.style.display = 'none';
    }

    // ポップオーバー外クリックで閉じる
    document.addEventListener('click', function(e) {
        if (ddPopover.style.display === 'block'
            && !ddPopover.contains(e.target)
            && !e.target.closest('#kpiTrendChartWrap')) {
            hideDrilldownPopover();
        }
    });

    // メニュー項目クリック → モーダル表示
    ddPopover.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-dd-type]');
        if (!btn) return;
        hideDrilldownPopover();
        openDrilldownModal(_ddMonth, btn.dataset.ddType);
    });

    function openDrilldownModal(month, type) {
        var metric = _activeMetric || 'sessions';
        var metricLabels = _ddLabels[metric] || _ddLabels.sessions;
        var typeLabel = (metricLabels[type] || _ddLabels.sessions[type]).label;

        var parts = month.split('-');
        ddModalTitle.textContent = parts[0] + '年' + parseInt(parts[1], 10) + '月 — ' + typeLabel;

        ddLoading.style.display   = 'block';
        ddChartWrap.style.display = 'none';
        ddEmpty.style.display     = 'none';
        ddError.style.display     = 'none';
        ddOverlay.style.display   = 'flex';
        document.body.style.overflow = 'hidden';

        var cacheKey = month + '_' + type + '_' + metric;
        if (_ddCache[cacheKey]) {
            renderDrilldownChart(_ddCache[cacheKey]);
            return;
        }

        fetch(restBase + 'dashboard/drilldown?month=' + encodeURIComponent(month)
              + '&type=' + encodeURIComponent(type)
              + '&metric=' + encodeURIComponent(metric), {
            headers: { 'X-WP-Nonce': nonce },
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.success && json.items && json.items.length) {
                _ddCache[cacheKey] = json;
                renderDrilldownChart(json);
            } else if (json.success && (!json.items || !json.items.length)) {
                ddLoading.style.display = 'none';
                ddEmpty.style.display   = 'block';
            } else {
                ddLoading.style.display = 'none';
                ddError.style.display   = 'block';
            }
        })
        .catch(function() {
            ddLoading.style.display = 'none';
            ddError.style.display   = 'block';
        });
    }

    function renderDrilldownChart(json) {
        ddLoading.style.display   = 'none';
        ddChartWrap.style.display = 'block';
        if (ddChart) { ddChart.destroy(); ddChart = null; }

        ddChartWrap.innerHTML = '<canvas id="drilldownChart"></canvas>';
        var labels = json.items.map(function(i) { return i.label; });
        var values = json.items.map(function(i) { return i.value; });
        var metricLabel = json.metric_label || json.metric || '';

        var barColors = [
            '#568184','#7AA3A6','#a3c9a9','#bddac0','#C95A4F',
            '#D4756A','#DFA192','#E8C5BE','#A8A29E','#C5BFB9'
        ];

        ddChart = new Chart('drilldownChart', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: metricLabel,
                    data: values,
                    backgroundColor: barColors.slice(0, values.length),
                    borderRadius: 4,
                    barPercentage: 0.7
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(ctx) { return ctx[0].label; },
                            label: function(ctx) {
                                return metricLabel + ': ' + ctx.parsed.x.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        title: { display: true, text: metricLabel, font: { size: 11 }, color: '#999' },
                        ticks: { callback: function(v) { return v.toLocaleString(); } }
                    },
                    y: {
                        ticks: {
                            font: { size: 12 },
                            callback: function(value) {
                                var lbl = this.getLabelForValue(value);
                                return lbl.length > 20 ? lbl.substring(0, 20) + '…' : lbl;
                            }
                        }
                    }
                }
            }
        });
    }

    // モーダル閉じる
    function closeDrilldownModal() {
        ddOverlay.style.display = 'none';
        document.body.style.overflow = '';
        if (ddChart) { ddChart.destroy(); ddChart = null; }
    }
    ddClose.addEventListener('click', closeDrilldownModal);
    ddOverlay.addEventListener('click', function(e) {
        if (e.target === ddOverlay) closeDrilldownModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && ddOverlay.style.display === 'flex') closeDrilldownModal();
    });
})();

// --- ハイライト詳細アコーディオン: aria-expanded 同期 ---
(function(){
    document.querySelectorAll('.highlight-detail-accordion').forEach(function(det){
        var summary = det.querySelector('.highlight-detail-toggle');
        if (!summary) return;
        det.addEventListener('toggle', function(){
            summary.setAttribute('aria-expanded', det.open ? 'true' : 'false');
        });
    });
})();

// --- スコア内訳モーダル ---
(function(){
    var openBtn  = document.getElementById('scoreBreakdownOpen');
    var overlay  = document.getElementById('scoreBreakdownOverlay');
    var closeBtn = document.getElementById('scoreBreakdownClose');
    if (!openBtn || !overlay || !closeBtn) return;

    function openModal() {
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeModal() {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.style.display === 'flex') closeModal();
    });
})();
</script>

<!-- ダッシュボード版 キーワードランキング JS -->
<script>
(function(){
    'use strict';

    var restBase = '<?php echo esc_url(rest_url('gcrev/v1/')); ?>';
    var wpNonce  = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
    var drtDevice = 'mobile';
    var drtRankData = [];
    var drtWeekLabels = [];
    var drtWeekKeys = [];

    // SERP Modal state
    var drtSerpDevice = 'mobile';
    var drtSerpKeywordId = null;
    var drtSummary = {};

    // Init
    document.addEventListener('DOMContentLoaded', function() {
        fetchDrtRankings();
    });

    // =========================================================
    // Device toggle
    // =========================================================
    document.getElementById('drtDeviceToggle').addEventListener('click', function(e) {
        var btn = e.target.closest('.drt-device-btn');
        if (!btn) return;
        drtDevice = btn.dataset.device;
        document.querySelectorAll('#drtDeviceToggle .drt-device-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.device === drtDevice);
        });
        renderDrtSummary();
        renderDrtTable();
    });

    // =========================================================
    // Fetch rankings
    // =========================================================
    function fetchDrtRankings() {
        document.getElementById('drtLoading').style.display = 'block';
        document.getElementById('drtEmptyState').style.display = 'none';
        document.getElementById('drtTableContainer').style.display = 'none';
        document.getElementById('drtFooter').style.display = 'none';

        fetch(restBase + 'rank-tracker/rankings', {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            document.getElementById('drtLoading').style.display = 'none';
            if (json.success && json.data) {
                drtRankData = json.data.keywords || [];
                drtWeekLabels = json.data.week_labels || [];
                drtWeekKeys = json.data.weeks || [];
                drtSummary = json.data.summary || {};
                renderDrtSummary();
                renderDrtTable();
            }
        })
        .catch(function(err) {
            document.getElementById('drtLoading').style.display = 'none';
            console.error('[DRT]', err);
        });
    }

    // =========================================================
    // Summary — render
    // =========================================================
    function renderDrtSummary() {
        var s = drtSummary[drtDevice] || { rank_1_3: 0, rank_4_10: 0, rank_11_20: 0, rank_out: 0 };
        drtSetCount('drtSummary13', s.rank_1_3);
        drtSetCount('drtSummary410', s.rank_4_10);
        drtSetCount('drtSummary1120', s.rank_11_20);
        drtSetCount('drtSummaryOut', s.rank_out);
    }

    function drtSetCount(id, val) {
        var el = document.getElementById(id);
        if (el) el.innerHTML = val + '<span class="drt-summary-card__unit">件</span>';
    }

    // =========================================================
    // Rankings table — render
    // =========================================================
    function renderDrtTable() {
        var emptyState = document.getElementById('drtEmptyState');
        var container = document.getElementById('drtTableContainer');
        var thead = document.getElementById('drtTableHead');
        var tbody = document.getElementById('drtTableBody');
        var footer = document.getElementById('drtFooter');

        if (!drtRankData || drtRankData.length === 0) {
            emptyState.style.display = 'block';
            container.style.display = 'none';
            footer.style.display = 'none';
            return;
        }

        emptyState.style.display = 'none';
        container.style.display = 'block';
        footer.style.display = 'block';

        // Header
        var hHtml = '<tr>';
        hHtml += '<th>キーワード</th>';
        hHtml += '<th>現在</th>';
        for (var d = 0; d < drtWeekLabels.length; d++) {
            hHtml += '<th style="text-align:center;">' + drtWeekLabels[d] + '</th>';
        }
        hHtml += '<th>操作</th>';
        hHtml += '</tr>';
        thead.innerHTML = hHtml;

        // Body
        var html = '';
        for (var i = 0; i < drtRankData.length; i++) {
            var kw = drtRankData[i];
            var dev = kw[drtDevice];
            var otherDev = kw[drtDevice === 'mobile' ? 'desktop' : 'mobile'];
            var weekly = kw.weekly ? kw.weekly[drtDevice] : {};
            var accent = drtGetAccent(dev);

            html += '<tr>';

            // Keyword + volume + 上がりやすさ目安
            html += '<td>';
            html += '<div class="drt-rank-accent ' + accent + '"></div>';
            html += '<div class="drt-kw-name">' + drtEsc(kw.keyword) + '</div>';
            html += '<div class="drt-kw-meta">';
            html += '<span class="drt-kw-meta-item">Vol: <strong>' + (kw.search_volume != null ? drtFmt(kw.search_volume) : '-') + '</strong></span>';
            html += '<span class="drt-kw-meta-item">上がりやすさ: ' + drtFormatOpportunityBadge(kw.opportunity_score) + '</span>';
            html += '</div>';
            html += '</td>';

            // Current rank
            html += '<td>';
            html += drtFormatCurrent(dev);
            if (dev && otherDev && drtHasBigDiff(dev, otherDev)) {
                html += '<span class="drt-pc-diff">' + (drtDevice === 'mobile' ? 'PC' : 'SP') + 'と差あり</span>';
            }
            html += '</td>';

            // Weekly columns (6 weeks)
            if (drtWeekKeys) {
                for (var d = 0; d < drtWeekKeys.length; d++) {
                    var weekData = weekly ? weekly[drtWeekKeys[d]] : null;
                    html += '<td class="drt-daily">' + drtFormatDaily(weekData) + '</td>';
                }
            }

            // Action
            html += '<td>';
            html += '<button class="drt-action-link" data-kw-id="' + kw.keyword_id + '">';
            html += '<span class="drt-action-link__icon">&#x1F4CA;</span> 上位ランキングを見る';
            html += '</button>';
            html += '</td>';

            html += '</tr>';
        }

        tbody.innerHTML = html;
    }

    // =========================================================
    // Format helpers
    // =========================================================
    function drtGetAccent(dev) {
        if (!dev || !dev.is_ranked) return 'drt-rank-accent--red';
        var r = dev.rank_group;
        if (r <= 3) return 'drt-rank-accent--gold';
        if (r <= 10) return 'drt-rank-accent--blue';
        if (r <= 20) return 'drt-rank-accent--green';
        return 'drt-rank-accent--red';
    }

    function drtFormatCurrent(dev) {
        if (!dev) return '<span class="drt-rank--na">-</span>';
        if (!dev.is_ranked) return '<span class="drt-rank--out">圏外</span>';
        var html = '<span class="drt-rank">' + dev.rank_group + '<span class="drt-rank-unit">位</span></span>';
        if (dev.change != null) {
            if (dev.change === 0) {
                html += '<div class="drt-rank-change drt-rank-change--same">&#x2192;</div>';
            } else {
                html += '<div class="drt-rank-change ' + (dev.change > 0 ? 'drt-rank-change--up' : 'drt-rank-change--down') + '">';
                if (dev.change === 999) html += '&#x2191; NEW';
                else if (dev.change === -999) html += '&#x2193; 圏外';
                else if (dev.change > 0) html += '&#x2191; ' + dev.change;
                else html += '&#x2193; ' + Math.abs(dev.change);
                html += '</div>';
            }
        }
        return html;
    }

    function drtFormatPrev(dev) {
        if (!dev || dev.change == null) return '<span class="drt-rank--na">-</span>';
        if (dev.change === 999) return '<span class="drt-rank--out">圏外</span>';
        if (dev.change === -999) return '<span class="drt-rank--na">-</span>';
        if (dev.is_ranked && dev.change != null) {
            var prev = dev.rank_group + dev.change;
            if (prev > 0 && prev <= 100) {
                return '<span class="drt-rank">' + prev + '<span class="drt-rank-unit">位</span></span>';
            }
        }
        return '<span class="drt-rank--na">-</span>';
    }

    function drtFormatDaily(dayData) {
        if (!dayData) return '<span class="drt-daily--na">-</span>';
        if (!dayData.is_ranked) return '<span class="drt-daily--out">圏外</span>';
        return dayData.rank + '位';
    }

    function drtHasBigDiff(a, b) {
        if (!a || !b) return false;
        if (!a.is_ranked && !b.is_ranked) return false;
        if (a.is_ranked !== b.is_ranked) return true;
        if (a.is_ranked && b.is_ranked) return Math.abs(a.rank_group - b.rank_group) >= 3;
        return false;
    }

    function drtFmt(n) {
        if (n == null) return '-';
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // 上がりやすさ目安 — 5段階インジケーター
    // SERP上位サイトの傾向をもとにした独自の参考指標
    function drtFormatOpportunityBadge(val) {
        if (val == null) return '<span style="color:#d1d5db;">-</span>';
        var v = parseInt(val, 10);
        var tier, label, color;
        if (v <= 19)      { tier = 1; label = 'かなり狙いやすい'; color = '#5B9A6B'; }
        else if (v <= 39) { tier = 2; label = 'やや狙いやすい';   color = '#7B9A4C'; }
        else if (v <= 59) { tier = 3; label = 'ふつう';           color = '#C4943C'; }
        else if (v <= 79) { tier = 4; label = 'やや難しい';       color = '#C4703C'; }
        else              { tier = 5; label = '難しい';           color = '#B5574B'; }
        var dots = '';
        for (var i = 1; i <= 5; i++) {
            dots += '<span class="drt-opp-dot" style="' + (i <= tier ? 'background:' + color : '') + '"></span>';
        }
        return '<span class="drt-opp-dots">' + dots + '</span>'
             + '<span class="drt-opp-label" style="color:' + color + '">' + label + '</span>';
    }

    function drtEsc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // =========================================================
    // SERP Modal
    // =========================================================
    document.getElementById('drtTableWrap').addEventListener('click', function(e) {
        var btn = e.target.closest('.drt-action-link');
        if (!btn) return;
        drtOpenSerpModal(parseInt(btn.dataset.kwId, 10));
    });

    /** キーワードIDからdrtRankDataのオブジェクトを探す */
    function drtFindKeywordById(keywordId) {
        for (var i = 0; i < drtRankData.length; i++) {
            if (drtRankData[i].keyword_id == keywordId) return drtRankData[i];
        }
        return null;
    }

    /** モーダルタイトルを更新 */
    function drtUpdateSerpTitle() {
        var title = document.getElementById('drtSerpModalTitle');
        if (!title) return;
        var kw = drtFindKeywordById(drtSerpKeywordId);
        title.textContent = (kw ? '「' + kw.keyword + '」' : '') + ' 上位ランキング (' + (drtSerpDevice === 'mobile' ? 'スマホ' : 'PC') + ')';
    }

    /** Google検索リンクを更新 */
    function drtUpdateSerpGoogleLink() {
        var link = document.getElementById('drtSerpGoogleLink');
        if (!link) return;
        var kw = drtFindKeywordById(drtSerpKeywordId);
        if (kw) {
            link.href = 'https://www.google.co.jp/search?q=' + encodeURIComponent(kw.keyword);
            link.style.display = '';
        } else {
            link.style.display = 'none';
        }
    }

    /** SERPデータをAPIから取得して表示 */
    function drtFetchSerpData(keywordId, device) {
        var body = document.getElementById('drtSerpModalBody');
        if (!body) return;
        body.innerHTML = '<div class="drt-loading">上位サイトを取得中...</div>';

        fetch(restBase + 'rank-tracker/serp-top?keyword_id=' + encodeURIComponent(keywordId) + '&device=' + encodeURIComponent(device), {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(r) {
            if (!r.ok) return r.json().then(function(ej) { throw new Error(ej.message || 'HTTP ' + r.status); });
            return r.json();
        })
        .then(function(json) {
            if (json.success && json.data && json.data.items) {
                body.innerHTML = drtBuildSerpTable(json.data.items);
            } else {
                body.innerHTML = '<div class="drt-loading" style="color:#ef4444;">' + drtEsc(json.message || 'データの取得に失敗しました。') + '</div>';
            }
        })
        .catch(function(err) {
            body.innerHTML = '<div class="drt-loading" style="color:#ef4444;">' + drtEsc(err.message || '通信エラーが発生しました。') + '</div>';
        });
    }

    /** モーダルを開く — 親画面のデバイス状態を引き継ぐ */
    function drtOpenSerpModal(keywordId) {
        var modal = document.getElementById('drtSerpModal');
        if (!modal) return;

        drtSerpDevice = drtDevice;
        drtSerpKeywordId = keywordId;

        // モーダル内トグルを同期
        document.querySelectorAll('#drtSerpDeviceToggle .drt-device-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.device === drtSerpDevice);
        });

        drtUpdateSerpTitle();
        drtUpdateSerpGoogleLink();
        modal.classList.add('active');
        drtFetchSerpData(keywordId, drtSerpDevice);
    }

    /** モーダル内のデバイス切替 */
    document.getElementById('drtSerpDeviceToggle').addEventListener('click', function(e) {
        var btn = e.target.closest('.drt-device-btn');
        if (!btn) return;
        var device = btn.dataset.device;
        if (device === drtSerpDevice) return;
        drtSerpDevice = device;

        document.querySelectorAll('#drtSerpDeviceToggle .drt-device-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.device === device);
        });

        drtUpdateSerpTitle();
        drtUpdateSerpGoogleLink();
        drtFetchSerpData(drtSerpKeywordId, device);
    });

    function drtBuildSerpTable(items) {
        if (!items || items.length === 0) {
            return '<div class="drt-loading">上位サイトのデータがありません。</div>';
        }
        var html = '<table class="drt-serp-table"><thead><tr>';
        html += '<th style="text-align:center;">順位</th>';
        html += '<th>サイト情報</th>';
        html += '</tr></thead><tbody>';
        for (var i = 0; i < items.length; i++) {
            var item = items[i];
            html += '<tr>';
            html += '<td class="drt-serp-rank">' + item.rank + '</td>';
            html += '<td>';
            html += '<div class="drt-serp-title">' + drtEsc(item.title) + '</div>';
            html += '<a class="drt-serp-url" href="' + drtEsc(item.url) + '" target="_blank" rel="noopener">' + drtEsc(item.url) + '</a>';
            if (item.description) {
                html += '<div class="drt-serp-desc">' + drtEsc(item.description) + '</div>';
            }
            html += '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        return html;
    }

    // Close modal
    document.getElementById('drtSerpModalClose').addEventListener('click', function() {
        document.getElementById('drtSerpModal').classList.remove('active');
    });
    document.getElementById('drtSerpModal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var m = document.getElementById('drtSerpModal');
            if (m && m.classList.contains('active')) m.classList.remove('active');
        }
    });

    // =========================================================
    // Progress overlay
    // =========================================================
    function drtShowProgress(show) {
        var overlay = document.getElementById('drtProgressOverlay');
        if (!overlay) return;
        if (show) {
            var barEl = document.getElementById('drtProgressBar');
            if (barEl) { barEl.style.width = '0%'; barEl.classList.add('drt-progress-bar--indeterminate'); }
            document.getElementById('drtProgressTitle').textContent = '最新の順位を取得中...';
            document.getElementById('drtProgressText').textContent = 'キーワードの順位を取得しています...';
            document.getElementById('drtProgressSub').textContent = '1キーワードあたり数秒かかります。しばらくお待ちください。';
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    }

    function drtShowProgressComplete(count) {
        document.getElementById('drtProgressTitle').textContent = '取得完了!';
        document.getElementById('drtProgressText').textContent = count + '件のキーワードの最新順位を取得しました。';
        document.getElementById('drtProgressSub').textContent = '';
        var barEl = document.getElementById('drtProgressBar');
        if (barEl) { barEl.classList.remove('drt-progress-bar--indeterminate'); barEl.style.width = '100%'; }
    }

    // =========================================================
    // Toast
    // =========================================================
    function drtShowToast(msg, type) {
        var existing = document.querySelector('.drt-toast');
        if (existing) existing.remove();
        var toast = document.createElement('div');
        toast.className = 'drt-toast' + (type === 'error' ? ' drt-toast--error' : '');
        toast.textContent = msg;
        document.body.appendChild(toast);
        requestAnimationFrame(function() {
            requestAnimationFrame(function() { toast.classList.add('show'); });
        });
        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }
})();

/* ============================================================
   MEO Section — Googleマップの見え方
   ============================================================ */
(function() {
    'use strict';

    var restBase = '<?php echo esc_url( rest_url( 'gcrev/v1/' ) ); ?>';
    var nonce    = '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>';

    // DOM refs
    var meoSection      = document.getElementById('meoSection');
    var meoLoading      = document.getElementById('meoLoading');
    var meoEmpty        = document.getElementById('meoEmpty');
    var meoError        = document.getElementById('meoError');
    var meoMetricsCards = document.getElementById('meoMetricsCards');
    var meoStoreCard    = document.getElementById('meoStoreCard');
    var meoReviewsCard  = document.getElementById('meoReviewsCard');
    var meoCompetitorWrap = document.getElementById('meoCompetitorWrap');
    var meoRegion       = document.getElementById('meoRegion');
    var meoKeywordSelect = document.getElementById('meoKeywordSelect');
    var meoKeywordSingle = document.getElementById('meoKeywordSingle');
    var meoDeviceToggle  = document.getElementById('meoDeviceToggle');
    var meoRadiusGroup   = document.getElementById('meoRadiusGroup');
    var meoRadiusSelect  = document.getElementById('meoRadiusSelect');

    if (!meoSection) return;

    var currentDevice    = 'mobile';
    var currentKeywordId = 0;
    var currentRadius    = 0;       // 0 = サーバー側デフォルト
    var isCoordinateMode = false;

    // ----- Init -----
    function meoInit() {
        // Device toggle
        meoDeviceToggle.addEventListener('click', function(e) {
            var btn = e.target.closest('.meo-device-btn');
            if (!btn || btn.classList.contains('active')) return;
            meoDeviceToggle.querySelectorAll('.meo-device-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentDevice = btn.dataset.device;
            meoFetchData(currentDevice, currentKeywordId);
            meoRenderHistory(); // デバイス切替時に履歴も再描画
        });

        // Keyword selector
        meoKeywordSelect.addEventListener('change', function() {
            currentKeywordId = parseInt(meoKeywordSelect.value, 10) || 0;
            meoFetchData(currentDevice, currentKeywordId);
        });

        // Radius selector
        meoRadiusSelect.addEventListener('change', function() {
            currentRadius = parseInt(meoRadiusSelect.value, 10) || 0;
            meoFetchData(currentDevice, currentKeywordId);
        });

        meoFetchData('mobile', 0);
        meoFetchHistory();
    }

    // ----- Fetch Data -----
    function meoFetchData(device, keywordId) {
        meoShowLoading();

        var url = restBase + 'meo/rankings?device=' + encodeURIComponent(device)
                + '&keyword_id=' + encodeURIComponent(keywordId);

        // 座標モードかつ半径指定ありの場合のみ radius を送る
        if (isCoordinateMode && currentRadius > 0) {
            url += '&radius=' + encodeURIComponent(currentRadius);
        }

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (data.success) {
                meoRenderAll(data);
            } else if (data.keywords && data.keywords.length === 0) {
                meoShowEmpty();
            } else {
                meoShowError(data.message || 'データの取得に失敗しました');
            }
        })
        .catch(function(err) {
            console.error('[GCREV][MEO]', err);
            meoShowError('通信エラーが発生しました');
        });
    }

    // ----- Render All -----
    function meoRenderAll(data) {
        meoHideStates();

        // Location / 基準地点の表示
        var loc = data.location || {};
        isCoordinateMode = (loc.mode === 'coordinate');

        if (isCoordinateMode) {
            if (loc.source === 'city_center') {
                // 市区町村中心部の自動検出: ラベル + (自動設定) 表示
                meoRegion.innerHTML = meoEsc(loc.address || '')
                    + ' <span style="font-size:11px;color:#999;font-weight:400;">(自動設定)</span>';
            } else {
                // 手動座標: 住所 or 座標を表示
                meoRegion.textContent = loc.address || (loc.lat + ', ' + loc.lng);
            }
        } else {
            // location_code フォールバック: 「○○県周辺」
            var regionText = data.region || '';
            if (regionText && regionText !== '日本（広域）') {
                meoRegion.textContent = regionText + '周辺';
            } else {
                meoRegion.textContent = regionText || '未設定';
            }
        }

        // 半径セレクター: 座標モード時のみ表示（手動・自動検出とも）
        if (isCoordinateMode && data.radius_options && data.radius_options.length > 0) {
            meoRenderRadiusOptions(data.radius_options, loc.radius || 3000);
            meoRadiusGroup.style.display = '';
        } else {
            meoRadiusGroup.style.display = 'none';
        }

        // Keyword selector
        meoRenderKeywords(data.keywords || []);

        // Metrics cards
        meoRenderMetrics(data);

        // Store info
        if (data.maps && data.maps.store) {
            meoRenderStore(data.maps.store);
            meoStoreCard.style.display = '';

            // Reviews
            meoRenderReviews(data.maps.store);
            meoReviewsCard.style.display = '';
        } else {
            meoStoreCard.style.display = 'none';
            meoReviewsCard.style.display = 'none';
        }

        // Competitors
        if (data.maps && data.maps.competitors && data.maps.competitors.length > 0) {
            meoRenderCompetitors(data.maps.competitors);
            meoCompetitorWrap.style.display = '';
        } else {
            meoCompetitorWrap.style.display = 'none';
        }
    }

    // ----- Render Keywords Selector -----
    function meoRenderKeywords(keywords) {
        if (!keywords || keywords.length === 0) {
            meoKeywordSingle.textContent = '未登録';
            meoKeywordSelect.style.display = 'none';
            meoKeywordSingle.style.display = '';
            return;
        }

        if (keywords.length === 1) {
            // 1件のみ — セレクトではなくテキスト表示
            meoKeywordSingle.textContent = keywords[0].keyword;
            meoKeywordSelect.style.display = 'none';
            meoKeywordSingle.style.display = '';
            return;
        }

        // 複数キーワード — セレクター表示
        var html = '';
        keywords.forEach(function(kw) {
            html += '<option value="' + kw.id + '"' + (kw.selected ? ' selected' : '') + '>'
                  + meoEsc(kw.keyword) + '</option>';
        });
        meoKeywordSelect.innerHTML = html;
        meoKeywordSelect.style.display = '';
        meoKeywordSingle.style.display = 'none';
    }

    // ----- Render Metrics Cards -----
    function meoRenderMetrics(data) {
        var maps = data.maps || {};
        var finder = data.local_finder || {};
        var store = maps.store || {};

        var cards = [
            {
                icon: '\uD83D\uDDFA\uFE0F',
                label: 'Googleマップ順位',
                sublabel: 'マップアプリでの表示順',
                value: maps.rank ? maps.rank + '<small>位</small>' : '<span class="meo-metric-value--out">圏外</span>',
                cls: 'meo-metric-card--teal'
            },
            {
                icon: '\uD83D\uDD0D',
                label: '検索結果の地域順位',
                sublabel: 'Google検索のローカル表示',
                value: finder.rank ? finder.rank + '<small>位</small>' : '<span class="meo-metric-value--out">圏外</span>',
                cls: 'meo-metric-card--blue'
            },
            {
                icon: '\u2B50',
                label: '口コミ評価',
                sublabel: 'Googleの平均評価',
                value: store.rating != null ? store.rating + '<small> / 5.0</small>' : '<small>-</small>',
                cls: 'meo-metric-card--gold'
            },
            {
                icon: '\uD83D\uDCAC',
                label: '口コミ件数',
                sublabel: 'Googleの口コミ総数',
                value: store.reviews_count != null ? store.reviews_count + '<small>件</small>' : '<small>-</small>',
                cls: 'meo-metric-card--green'
            }
        ];

        var html = '';
        cards.forEach(function(c) {
            html += '<div class="meo-metric-card ' + c.cls + '">'
                  + '<div class="meo-metric-icon">' + c.icon + '</div>'
                  + '<div class="meo-metric-label">' + c.label + '</div>'
                  + '<div class="meo-metric-sublabel">' + c.sublabel + '</div>'
                  + '<div class="meo-metric-value">' + c.value + '</div>'
                  + '</div>';
        });
        meoMetricsCards.innerHTML = html;
    }

    // ----- Render Store -----
    function meoRenderStore(store) {
        var rows = [];
        if (store.title)    rows.push(['店舗名', meoEsc(store.title)]);
        if (store.category) rows.push(['カテゴリ', meoEsc(store.category)]);
        if (store.address)  rows.push(['住所', meoEsc(store.address)]);
        if (store.phone)    rows.push(['電話番号', meoEsc(store.phone)]);
        if (store.work_hours) rows.push(['営業時間', meoEsc(store.work_hours)]);

        if (rows.length === 0) { meoStoreCard.style.display = 'none'; return; }

        var html = '<div class="meo-store-card__title">\uD83C\uDFEA 店舗情報</div>'
                 + '<div class="meo-store-grid">';
        rows.forEach(function(r) {
            html += '<div class="meo-store-label">' + r[0] + '</div>'
                  + '<div class="meo-store-value">' + r[1] + '</div>';
        });
        html += '</div>';

        if (store.maps_url) {
            html += '<a href="' + meoEsc(store.maps_url) + '" target="_blank" rel="noopener" class="meo-store-link">'
                  + 'Googleマップで見る \u2192</a>';
        }

        meoStoreCard.innerHTML = html;
    }

    // ----- Render Reviews -----
    function meoRenderReviews(store) {
        if (store.rating == null) { meoReviewsCard.style.display = 'none'; return; }

        var rating = parseFloat(store.rating) || 0;
        var total = store.reviews_count || 0;
        var dist = store.rating_distribution || {};

        // Stars
        var stars = '';
        for (var i = 1; i <= 5; i++) {
            stars += (i <= Math.round(rating)) ? '\u2605' : '\u2606';
        }

        // Bars
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

        var html = '<div class="meo-reviews-card__title">\uD83D\uDCAC 口コミの状況</div>'
                 + '<div class="meo-reviews-summary">'
                 + '<span class="meo-reviews-big-rating">' + rating.toFixed(1) + '</span>'
                 + '<span class="meo-reviews-stars">' + stars + '</span>'
                 + '<span class="meo-reviews-count">' + total + '件の口コミ</span>'
                 + '</div>'
                 + '<div class="meo-rating-bars">' + barsHtml + '</div>';

        meoReviewsCard.innerHTML = html;
    }

    // ----- Render Competitors -----
    function meoRenderCompetitors(competitors) {
        var html = '<div class="meo-competitor-title">\uD83C\uDFC6 近くの競合との比較</div>'
                 + '<div class="meo-table-scroll">'
                 + '<table class="meo-competitor-table">'
                 + '<thead><tr>'
                 + '<th>店舗名</th><th>マップ順位</th><th>評価</th><th>口コミ数</th>'
                 + '</tr></thead><tbody>';

        competitors.forEach(function(c) {
            var rowCls = c.is_self ? ' class="meo-self-row"' : '';
            var name = meoEsc(c.title || '');
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

        html += '</tbody></table></div>';
        meoCompetitorWrap.innerHTML = html;
    }

    // ----- Stars mini -----
    function meoStarsMini(val) {
        var r = Math.round(parseFloat(val) || 0);
        var s = '';
        for (var i = 1; i <= 5; i++) s += (i <= r) ? '\u2605' : '\u2606';
        return s;
    }

    // ----- Render Radius Options -----
    function meoRenderRadiusOptions(options, selectedRadius) {
        var html = '';
        options.forEach(function(opt) {
            var sel = (opt.value === selectedRadius) ? ' selected' : '';
            html += '<option value="' + opt.value + '"' + sel + '>'
                  + meoEsc(opt.label) + '</option>';
        });
        meoRadiusSelect.innerHTML = html;
        currentRadius = selectedRadius;
    }

    // ----- State helpers -----
    function meoShowLoading() {
        meoLoading.style.display = '';
        meoEmpty.style.display = 'none';
        meoError.style.display = 'none';
        meoMetricsCards.innerHTML = '';
        meoStoreCard.style.display = 'none';
        meoReviewsCard.style.display = 'none';
        meoCompetitorWrap.style.display = 'none';
    }
    function meoShowEmpty() {
        meoLoading.style.display = 'none';
        meoEmpty.style.display = '';
        meoError.style.display = 'none';
    }
    function meoShowError(msg) {
        meoLoading.style.display = 'none';
        meoEmpty.style.display = 'none';
        meoError.style.display = '';
        meoError.innerHTML = msg + '<br><button class="meo-retry-btn" onclick="document.getElementById(\'meoError\').style.display=\'none\';">閉じる</button>';
    }
    function meoHideStates() {
        meoLoading.style.display = 'none';
        meoEmpty.style.display = 'none';
        meoError.style.display = 'none';
    }

    // ----- Escape helper -----
    function meoEsc(str) {
        if (!str) return '';
        var el = document.createElement('span');
        el.textContent = str;
        return el.innerHTML;
    }

    // ----- MEO History (週次推移) -----
    var meoHistoryData = null;

    function meoFetchHistory() {
        fetch(restBase + 'meo/history', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (data.success && data.data) {
                meoHistoryData = data.data;
                meoRenderHistory();
            }
        })
        .catch(function(err) {
            console.error('[GCREV][MEO History]', err);
        });
    }

    function meoRenderHistory() {
        var wrap = document.getElementById('meoHistoryWrap');
        var thead = document.getElementById('meoHistoryHead');
        var tbody = document.getElementById('meoHistoryBody');

        if (!meoHistoryData || !meoHistoryData.keywords || meoHistoryData.keywords.length === 0) {
            wrap.style.display = 'none';
            return;
        }

        var weeks = meoHistoryData.weeks || [];
        var labels = meoHistoryData.week_labels || [];

        if (weeks.length === 0) {
            wrap.style.display = 'none';
            return;
        }

        // 最初のキーワード（MEOでは通常1キーワード）のデータを使用
        var kwData = meoHistoryData.keywords[0];
        var weekly = kwData.weekly ? kwData.weekly[currentDevice] : {};

        if (!weekly || Object.keys(weekly).length === 0) {
            // データ蓄積中の表示
            wrap.style.display = '';
            thead.innerHTML = '';
            tbody.innerHTML = '<tr><td colspan="' + (weeks.length + 1) + '" style="text-align:center;color:#9ca3af;padding:24px;">'
                + '&#x1F4CA; データを蓄積中です。毎週月曜日に自動計測されます。</td></tr>';
            return;
        }

        wrap.style.display = '';

        // Header
        var hHtml = '<tr><th style="min-width:140px;">指標</th>';
        for (var i = 0; i < labels.length; i++) {
            hHtml += '<th style="text-align:center;min-width:60px;">' + labels[i] + '</th>';
        }
        hHtml += '</tr>';
        thead.innerHTML = hHtml;

        // Rows for each metric
        var metrics = [
            { key: 'maps_rank', label: '\uD83D\uDDFA\uFE0F マップ順位', unit: '位', lower_is_better: true },
            { key: 'finder_rank', label: '\uD83D\uDD0D 地域順位', unit: '位', lower_is_better: true },
            { key: 'rating', label: '\u2B50 口コミ評価', unit: '', lower_is_better: false },
            { key: 'reviews', label: '\uD83D\uDCAC 口コミ件数', unit: '件', lower_is_better: false }
        ];

        var bHtml = '';
        for (var m = 0; m < metrics.length; m++) {
            var met = metrics[m];
            bHtml += '<tr>';
            bHtml += '<td style="font-weight:500;white-space:nowrap;">' + met.label + '</td>';

            var prevVal = null;
            for (var w = 0; w < weeks.length; w++) {
                var wData = weekly[weeks[w]];
                var val = wData ? wData[met.key] : null;

                var display = '-';
                var trendHtml = '';

                if (val !== null && val !== undefined) {
                    if (met.key === 'rating') {
                        display = parseFloat(val).toFixed(1);
                    } else {
                        display = val + '<small>' + met.unit + '</small>';
                    }

                    // Trend arrow
                    if (prevVal !== null && prevVal !== undefined) {
                        var diff = val - prevVal;
                        if (diff !== 0) {
                            var isGood = met.lower_is_better ? (diff < 0) : (diff > 0);
                            if (isGood) {
                                trendHtml = ' <span class="meo-trend-up">\u2191</span>';
                            } else {
                                trendHtml = ' <span class="meo-trend-down">\u2193</span>';
                            }
                        } else {
                            trendHtml = ' <span class="meo-trend-same">\u2192</span>';
                        }
                    }
                }

                bHtml += '<td style="text-align:center;">' + display + trendHtml + '</td>';
                if (val !== null && val !== undefined) {
                    prevVal = val;
                }
            }
            bHtml += '</tr>';
        }

        tbody.innerHTML = bHtml;
    }

    // ----- Go -----
    meoInit();
})();
</script>

<?php get_footer(); ?>
