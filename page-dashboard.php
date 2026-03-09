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

// 前月・前々月の日付範囲を計算
$tz = wp_timezone();
$prev_month_start = new DateTimeImmutable('first day of last month', $tz);
$prev_month_end = new DateTimeImmutable('last day of last month', $tz);

$prev_prev_month_start = new DateTimeImmutable('first day of 2 months ago', $tz);
$prev_prev_month_end = new DateTimeImmutable('last day of 2 months ago', $tz);

$year = (int)$prev_month_start->format('Y');
$month = (int)$prev_month_start->format('n');

global $gcrev_api_instance;
if ( ! isset($gcrev_api_instance) || ! ($gcrev_api_instance instanceof Gcrev_Insight_API) ) {
    $gcrev_api_instance = new Gcrev_Insight_API(false);
}
$gcrev_api = $gcrev_api_instance;


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
        'red'    => '#B5574B',
        'blue'   => '#3D6B6E',
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
            '減少' => '#B5574B',
            '悪化' => '#B5574B',
            '前月比' => '#3D6B6E',
            '前年比' => '#3D6B6E',
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
  background: #3D6B6E;
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
  background: #3D6B6E;
  color: #fff !important;
  font-size: 16px;
  font-weight: 600;
  border-radius: 6px;
  text-decoration: none;
  transition: background 0.2s;
}
.setup-guide-btn:hover {
  background: #346062;
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
.drt-btn--primary { background: #2563eb; color: #fff; border-color: #2563eb; }
.drt-btn--primary:hover { background: #1d4ed8; }
.drt-btn--primary:disabled { background: #93b4f5; border-color: #93b4f5; }
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
.drt-rank { font-weight: 700; font-size: 15px; color: #1a1a1a; }
.drt-rank--out { font-size: 11px; font-weight: 600; color: #ef4444; }
.drt-rank--na  { font-size: 11px; color: #d1d5db; }
.drt-rank-unit { font-size: 10px; font-weight: 400; color: #9ca3af; }
.drt-rank-change { font-size: 10px; font-weight: 600; margin-top: 1px; }
.drt-rank-change--up   { color: #16a34a; }
.drt-rank-change--down { color: #ef4444; }
.drt-pc-diff {
    display: inline-block; font-size: 9px; color: #f59e0b; background: #fffbeb;
    border: 1px solid #fde68a; border-radius: 4px; padding: 1px 4px; margin-left: 3px; white-space: nowrap;
}
.drt-daily { font-size: 12px; font-weight: 500; text-align: center; min-width: 42px; white-space: nowrap; }
.drt-daily--out { color: #ef4444; font-size: 10px; }
.drt-daily--na  { color: #d1d5db; }
.drt-action-link {
    display: inline-flex; align-items: center; gap: 3px; font-size: 12px; color: #2563eb;
    cursor: pointer; text-decoration: none; padding: 3px 0; border: none; background: none; white-space: nowrap;
}
.drt-action-link:hover { color: #1d4ed8; text-decoration: underline; }
.drt-action-link__icon { font-size: 13px; }
.drt-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }
.drt-empty__icon { font-size: 32px; margin-bottom: 8px; }
.drt-empty__text { font-size: 14px; color: #6b7280; }
.drt-loading { text-align: center; padding: 32px; color: #9ca3af; font-size: 13px; }
.drt-footer { text-align: center; margin-top: 16px; }
.drt-footer__link {
    display: inline-flex; align-items: center; gap: 6px; font-size: 13px;
    color: #2563eb; text-decoration: none; font-weight: 500;
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
.drt-serp-table { width: 100%; border-collapse: collapse; }
.drt-serp-table th {
    font-size: 12px; font-weight: 600; color: #6b7280;
    padding: 10px 16px; text-align: left; border-bottom: 1px solid #e5e7eb; background: #f9fafb;
}
.drt-serp-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; vertical-align: top; }
.drt-serp-table tr:last-child td { border-bottom: none; }
.drt-serp-rank { font-weight: 700; font-size: 15px; color: #2563eb; text-align: center; min-width: 36px; }
.drt-serp-title { font-weight: 600; color: #1a1a1a; margin-bottom: 2px; line-height: 1.4; }
.drt-serp-url { font-size: 12px; color: #2563eb; word-break: break-all; }
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
    height: 100%; background: linear-gradient(90deg, #2563eb, #60a5fa); border-radius: 4px;
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
/* Responsive */
@media (max-width: 768px) {
    .drt-section { padding: 20px 16px; }
    .drt-header { flex-direction: column; align-items: flex-start; }
    .drt-summary-cards { grid-template-columns: repeat(2, 1fr); }
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

// === Effective CV で採点の「成果」を上書き ＋ KPIライブデータで全観点を補正 ===
if ($infographic && is_array($infographic)) {
    try {
        $prev_ym_dash = $prev_month_start->format('Y-m');
        $prev_prev_ym_dash = $prev_prev_month_start->format('Y-m');
        $eff_cv_curr = $gcrev_api->get_effective_cv_monthly($prev_ym_dash, $user_id);
        $eff_cv_prev = $gcrev_api->get_effective_cv_monthly($prev_prev_ym_dash, $user_id);

        // KPI の cv を上書き
        if (isset($infographic['kpi']['cv'])) {
            $infographic['kpi']['cv']['value']  = $eff_cv_curr['total'];
            $infographic['kpi']['cv']['diff']   = $eff_cv_curr['total'] - $eff_cv_prev['total'];
            $infographic['kpi']['cv']['source'] = $eff_cv_curr['source'];
        }

        // breakdown の cv を上書き
        if (isset($infographic['breakdown']['cv'])) {
            $bd_cv = &$infographic['breakdown']['cv'];
            $bd_cv['curr'] = (float)$eff_cv_curr['total'];
            $bd_cv['prev'] = (float)$eff_cv_prev['total'];
            // pct 再計算
            if ($bd_cv['prev'] > 0) {
                $bd_cv['pct'] = round((($bd_cv['curr'] - $bd_cv['prev']) / $bd_cv['prev']) * 100.0, 1);
            } else {
                $bd_cv['pct'] = ($bd_cv['curr'] > 0) ? 100.0 : 0.0;
            }
            // points 再計算
            $max_p = (int)($bd_cv['max'] ?? 25);
            $pct_v = (float)$bd_cv['pct'];
            if ($pct_v >= 15.0) $bd_cv['points'] = $max_p;
            elseif ($pct_v >= 5.0) $bd_cv['points'] = (int)($max_p * 0.8);
            elseif ($pct_v >= -4.0) $bd_cv['points'] = (int)($max_p * 0.6);
            elseif ($pct_v >= -14.0) $bd_cv['points'] = (int)($max_p * 0.32);
            else $bd_cv['points'] = 0;
            if ((int)$bd_cv['curr'] === 0) $bd_cv['points'] = 0;
            unset($bd_cv);
        }

        // === KPIライブデータで流入・検索の採点を補正 ===
        // cache_first=1: キャッシュがあれば使い、なければスキップ（JS側で非同期取得）
        $kpi_curr = $gcrev_api->get_dashboard_kpi('prev-month', $user_id, 1);
        $kpi_prev = $gcrev_api->get_dashboard_kpi('prev-prev-month', $user_id, 1);

        if (!empty($kpi_curr)) {
        // --- 流入（traffic = sessions）を上書き ---
        $sess_curr = (int)str_replace(',', '', (string)($kpi_curr['sessions'] ?? '0'));
        $sess_prev = (int)str_replace(',', '', (string)($kpi_prev['sessions'] ?? '0'));

        if (isset($infographic['kpi']['visits'])) {
            $infographic['kpi']['visits']['value'] = $sess_curr;
            $infographic['kpi']['visits']['diff']  = $sess_curr - $sess_prev;
        }

        if (isset($infographic['breakdown']['traffic'])) {
            $bd_tr = &$infographic['breakdown']['traffic'];
            $bd_tr['curr'] = (float)$sess_curr;
            $bd_tr['prev'] = (float)$sess_prev;
            if ($bd_tr['prev'] > 0) {
                $bd_tr['pct'] = round((($bd_tr['curr'] - $bd_tr['prev']) / $bd_tr['prev']) * 100.0, 1);
            } else {
                $bd_tr['pct'] = ($bd_tr['curr'] > 0) ? 100.0 : 0.0;
            }
            $max_p = (int)($bd_tr['max'] ?? 25);
            $pct_v = (float)$bd_tr['pct'];
            if ($pct_v >= 15.0) $bd_tr['points'] = $max_p;
            elseif ($pct_v >= 5.0) $bd_tr['points'] = (int)($max_p * 0.8);
            elseif ($pct_v >= -4.0) $bd_tr['points'] = (int)($max_p * 0.6);
            elseif ($pct_v >= -14.0) $bd_tr['points'] = (int)($max_p * 0.32);
            else $bd_tr['points'] = 0;
            if ((int)$bd_tr['curr'] === 0) $bd_tr['points'] = 0;
            unset($bd_tr);
        }

        // --- 検索（gsc = clicks）を上書き ---
        $gsc_curr_raw = $kpi_curr['gsc']['total'] ?? [];
        $gsc_prev_raw = $kpi_prev['gsc']['total'] ?? [];
        $gsc_curr_val = (int)str_replace(',', '', (string)($gsc_curr_raw['clicks'] ?? $gsc_curr_raw['impressions'] ?? '0'));
        $gsc_prev_val = (int)str_replace(',', '', (string)($gsc_prev_raw['clicks'] ?? $gsc_prev_raw['impressions'] ?? '0'));

        if ($gsc_curr_val > 0 && isset($infographic['breakdown']['gsc'])) {
            $bd_gsc = &$infographic['breakdown']['gsc'];
            $bd_gsc['curr'] = (float)$gsc_curr_val;
            $bd_gsc['prev'] = (float)$gsc_prev_val;
            if ($bd_gsc['prev'] > 0) {
                $bd_gsc['pct'] = round((($bd_gsc['curr'] - $bd_gsc['prev']) / $bd_gsc['prev']) * 100.0, 1);
            } else {
                $bd_gsc['pct'] = ($bd_gsc['curr'] > 0) ? 100.0 : 0.0;
            }
            $max_p = (int)($bd_gsc['max'] ?? 25);
            $pct_v = (float)$bd_gsc['pct'];
            if ($pct_v >= 15.0) $bd_gsc['points'] = $max_p;
            elseif ($pct_v >= 5.0) $bd_gsc['points'] = (int)($max_p * 0.8);
            elseif ($pct_v >= -4.0) $bd_gsc['points'] = (int)($max_p * 0.6);
            elseif ($pct_v >= -14.0) $bd_gsc['points'] = (int)($max_p * 0.32);
            else $bd_gsc['points'] = 0;
            if ((int)$bd_gsc['curr'] === 0) $bd_gsc['points'] = 0;
            unset($bd_gsc);
        }

        } // end if (!empty($kpi_curr))

        // === score 再計算（常にv2ロジックで再スコアリング） ===
        // KPIライブデータ有無に関わらず、最新の breakdown curr/prev で v2 再計算
        $re_curr = [];
        $re_prev = [];
        foreach (['traffic', 'cv', 'gsc', 'meo'] as $rk) {
            $re_curr[$rk] = (float)($infographic['breakdown'][$rk]['curr'] ?? 0);
            $re_prev[$rk] = (float)($infographic['breakdown'][$rk]['prev'] ?? 0);
        }
        $re_health = $gcrev_api->calc_monthly_health_score(
            $re_curr, $re_prev, [],
            $user_id,
            (int)$prev_month_start->format('Y'),
            (int)$prev_month_start->format('n')
        );
        $infographic['score']      = $re_health['score'];
        $infographic['status']     = $re_health['status'];
        $infographic['breakdown']  = $re_health['breakdown'];
        $infographic['components'] = $re_health['components'];
    } catch (\Throwable $e) {
        error_log('[GCREV] page-dashboard infographic override error: ' . $e->getMessage());
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


    <!-- 期間表示バー -->
    <div class="period-info-bar">
        <div>
            <span class="period-label">分析期間</span>
            <strong><?php echo $prev_month_start->format('Y年n月'); ?>（<?php echo $prev_month_start->format('Y/n/1'); ?> ～ <?php echo $prev_month_end->format('Y/n/t'); ?>）</strong>
        </div>
        <div>
            <span class="period-label">比較期間</span>
            <strong><?php echo $prev_prev_month_start->format('Y年n月'); ?>（<?php echo $prev_prev_month_start->format('Y/n/1'); ?> ～ <?php echo $prev_prev_month_end->format('Y/n/t'); ?>）</strong>
        </div>
    </div>
<?php if ($infographic): ?>
<section class="dashboard-infographic">

  <!-- 外枠右上：最新月次レポートを見る（※月次レポートがある時だけ表示） -->
  <?php if (!empty($monthly_report)): ?>
    <a href="<?php echo esc_url(home_url('/report/report-latest/')); ?>" class="info-monthly-link info-monthly-link--corner">
      <span aria-hidden="true">📊</span> 最新月次レポートを見る
    </a>
  <?php endif; ?>

  <!-- 見出し -->
  <h2 class="dashboard-infographic-title">
    <span class="icon" aria-hidden="true">📊</span><?php echo esc_html($year . '年' . $month); ?>月の状態
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
      <div class="info-score-circle">
        <span class="info-score-value"><?php echo esc_html((string)($infographic['score'] ?? 0)); ?><span class="info-score-unit">点</span></span>
        <span class="info-score-label">100点中</span>
      </div>

      <?php
      $score_diff = (int)($infographic['score_diff'] ?? 0);
      $diff_class = $score_diff > 0 ? 'positive' : ($score_diff < 0 ? 'negative' : 'neutral');
      $diff_icon  = $score_diff > 0 ? '▲' : ($score_diff < 0 ? '▼' : '→');
      $diff_text  = $score_diff > 0 ? '+' . $score_diff : (string)$score_diff;
      ?>
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
          $kpi_diff_icon  = $kpi_diff > 0 ? '▲' : ($kpi_diff < 0 ? '▼' : '→');
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
                <span>ℹ️ 詳しく見る</span>
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
     計測キーワードランキング（ダッシュボード版）
     ============================================================ -->
<section class="drt-section" id="drtSection">
    <div class="drt-header">
        <div class="drt-header__title">
            &#x1F4C8; 計測キーワードランキング
        </div>
        <div class="drt-header__actions">
            <button class="drt-btn drt-btn--primary" id="drtFetchAllBtn">
                <span class="drt-btn__icon">&#x1F504;</span>
                最新の情報を見る
            </button>
        </div>
    </div>

    <div class="drt-help">
        Google で検索した時に、あなたのホームページが<strong>何番目に表示されるか</strong>をチェックしています。
        数字が小さいほど上位表示されています。「<strong>圏外</strong>」は100位以内に表示されなかったことを意味します。
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
                <a href="<?php echo esc_url(home_url('/mypage/rank-tracker/')); ?>" style="color:#2563eb;">検索順位チェック</a>ページからキーワードを追加できます。
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
            var icon = diff > 0 ? '▲' : (diff < 0 ? '▼' : '→');
            var cls  = diff > 0 ? 'positive' : (diff < 0 ? 'negative' : 'neutral');
            diffEl.textContent = icon + ' ' + (diff > 0 ? '+' : '') + fmt(diff);
            diffEl.className = 'info-kpi-diff ' + cls;
        }
    }

    <?php if (!empty($kpi_curr)): ?>
    // --- キャッシュヒット: サーバーサイドで取得済みのデータをそのまま適用 ---
    var curr = <?php echo wp_json_encode(['sessions' => $kpi_curr['sessions'] ?? 0, 'conversions' => $kpi_curr['conversions'] ?? 0]); ?>;
    var prev = <?php echo wp_json_encode($kpi_prev ? ['sessions' => $kpi_prev['sessions'] ?? 0, 'conversions' => $kpi_prev['conversions'] ?? 0] : null); ?>;

    var currSessions = parseInt(String(curr.sessions || 0).replace(/,/g, ''), 10);
    var prevSessions = prev ? parseInt(String(prev.sessions || 0).replace(/,/g, ''), 10) : 0;
    updateInfoKpi('visits', currSessions, currSessions - prevSessions);

    var currCv = parseInt(String(curr.conversions || 0).replace(/,/g, ''), 10);
    var prevCv = prev ? parseInt(String(prev.conversions || 0).replace(/,/g, ''), 10) : 0;
    updateInfoKpi('cv', currCv, currCv - prevCv);

    <?php else: ?>
    // --- キャッシュミス: REST API で非同期取得（スケルトン＋スピナー表示） ---
    (function(){
        var restBase = <?php echo wp_json_encode(esc_url_raw(rest_url('gcrev/v1/'))); ?>;
        var nonce    = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;

        // visits/cv のみローディング表示（MEOはinfographic由来で正しいのでスキップ）
        var kpiKeys = ['visits', 'cv'];
        kpiKeys.forEach(function(key){
            var card = document.querySelector('[data-kpi-key="' + key + '"]');
            if (!card) return;
            var valEl  = card.querySelector('[data-kpi-role="value"]');
            var diffEl = card.querySelector('[data-kpi-role="diff"]');
            if (valEl) {
                valEl.dataset.originalText = valEl.textContent;
                valEl.textContent = '\u8aad\u307f\u8fbc\u307f\u4e2d'; // 読み込み中（CSS shimmerで透明化）
            }
            if (diffEl) {
                diffEl.dataset.originalText = diffEl.textContent;
                diffEl.textContent = '';
            }
            card.classList.add('is-kpi-loading');
            card.setAttribute('aria-busy', 'true');
            // スピナー＋「読み込み中…」テキスト
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
                el.textContent = '\u6642\u9593\u304c\u304b\u304b\u3063\u3066\u3044\u307e\u3059\u2026'; // 時間がかかっています…
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
            if (valEl) valEl.textContent = '\u53d6\u5f97\u306b\u5931\u6557\u3057\u307e\u3057\u305f'; // 取得に失敗しました
            var diffEl = card.querySelector('[data-kpi-role="diff"]');
            if (diffEl) diffEl.textContent = '';
            var retryBtn = document.createElement('button');
            retryBtn.type = 'button';
            retryBtn.className = 'info-kpi-retry-btn';
            retryBtn.textContent = '\u518d\u53d6\u5f97'; // 再取得
            retryBtn.addEventListener('click', function(e){
                e.stopPropagation();
                location.reload();
            });
            card.appendChild(retryBtn);
        }

        Promise.all([
            fetch(restBase + 'dashboard/kpi?period=prev-month', {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce }
            }).then(function(r){ return r.json(); }),
            fetch(restBase + 'dashboard/kpi?period=prev-prev-month', {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce }
            }).then(function(r){ return r.json(); })
        ]).then(function(results){
            clearTimeout(timeoutId);
            var curr = results[0].success ? results[0].data : null;
            var prev = results[1].success ? results[1].data : null;
            if (!curr) {
                kpiKeys.forEach(function(k){ errorCard(k); });
                return;
            }

            var cS = parseInt(String(curr.sessions || 0).replace(/,/g, ''), 10);
            var pS = prev ? parseInt(String(prev.sessions || 0).replace(/,/g, ''), 10) : 0;
            updateInfoKpi('visits', cS, cS - pS);
            finishCard('visits');

            var cC = parseInt(String(curr.conversions || 0).replace(/,/g, ''), 10);
            var pC = prev ? parseInt(String(prev.conversions || 0).replace(/,/g, ''), 10) : 0;
            updateInfoKpi('cv', cC, cC - pC);
            finishCard('cv');
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
    var _trendCache   = {};
    var _activeMetric = null;
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

    // (1) 即時データ先読み — 全3指標をfetch、sessionsが来たら即描画
    ['sessions', 'cv', 'meo'].forEach(function(m){
        fetch(restBase + 'dashboard/trends?metric=' + encodeURIComponent(m), {
            headers: { 'X-WP-Nonce': nonce },
            credentials: 'same-origin'
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            _trendCache[m] = json;
            // sessionsデータが取れたらまだ何も表示していなければ即描画
            if (m === 'sessions' && !_activeMetric) {
                showTrend('sessions', '訪問数', '👥');
            }
        })
        .catch(function(){
            // sessions の初回ロードが失敗した場合はエラー表示
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
        if (_activeMetric === metric) return; // 同じメトリクスなら何もしない
        _activeMetric = metric;
        setActiveCard(metric);

        // タイトル更新
        titleText.textContent = label + ' — 過去12ヶ月の推移';
        titleIcon.textContent = icon;

        // エラー非表示
        errorEl.style.display = 'none';

        // キャッシュがあれば即表示（フェードアニメーション付き）
        if (_trendCache[metric]) {
            // 切替アニメーション: 0.3s fade
            chartWrap.style.opacity = '0';
            loading.classList.remove('active');
            chartWrap.style.display = 'block';
            renderTrendChart(_trendCache[metric], label);
            // requestAnimationFrame で次フレームに opacity を戻す
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

        fetch(restBase + 'dashboard/trends?metric=' + encodeURIComponent(metric), {
            headers: { 'X-WP-Nonce': nonce },
            credentials: 'same-origin'
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            _trendCache[metric] = json;
            // 取得中にユーザーが別カードを押した場合はスキップ
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
            // リトライ: キャッシュをクリアして再取得
            var m = _retryMetric;
            var l = _retryLabel;
            var i = _retryIcon;
            _activeMetric = null; // ガードをリセット
            delete _trendCache[m];
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

        chartWrap.innerHTML = '<canvas id="kpiTrendChart"></canvas>';
        var shortLabels = json.labels.map(function(ym){
            return parseInt(ym.split('-')[1], 10) + '月';
        });
        var dataLen = json.values.length;
        var pointBg = json.values.map(function(v, i){
            return i === dataLen - 1 ? '#B5574B' : '#3D6B6E';
        });
        var pointR = json.values.map(function(v, i){
            return i === dataLen - 1 ? 6 : 3;
        });

        kpiTrendChart = new Chart('kpiTrendChart', {
            type: 'line',
            data: {
                labels: shortLabels,
                datasets: [{
                    label: label,
                    data: json.values,
                    borderColor: '#3D6B6E',
                    borderWidth: 2,
                    pointBackgroundColor: pointBg,
                    pointRadius: pointR,
                    pointHitRadius: 15,
                    pointHoverRadius: 7,
                    tension: 0.3,
                    fill: true,
                    backgroundColor: 'rgba(59,130,246,0.08)',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onHover: function(evt, elements) {
                    evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                },
                onClick: function(evt, elements) {
                    if (!elements.length) return;
                    var idx = elements[0].index;
                    var month = json.labels[idx];
                    showDrilldownPopover(month, elements[0].element);
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(ctx){ return json.labels[ctx[0].dataIndex]; },
                            label: function(ctx){ return label + ': ' + ctx.parsed.y.toLocaleString(); },
                            afterLabel: function(){ return 'クリックして詳細を表示'; }
                        }
                    }
                },
                scales: {
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
            '#3D6B6E','#5A8A8D','#7BA9AC','#9CC8CB','#B5574B',
            '#C97A6F','#D49D94','#DFBFB8','#A8A29E','#C5BFB9'
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
    var drtDateLabels = [];
    var drtDateKeys = [];
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
    // Fetch All (refresh)
    // =========================================================
    document.getElementById('drtFetchAllBtn').addEventListener('click', function() {
        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="drt-btn__icon">&#x23F3;</span> 取得中...';
        drtShowProgress(true);

        fetch(restBase + 'rank-tracker/fetch-all', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            btn.disabled = false;
            btn.innerHTML = '<span class="drt-btn__icon">&#x1F504;</span> 最新の情報を見る';
            if (json.success && json.data) {
                drtShowProgressComplete(json.data.fetched || 0);
                setTimeout(function() {
                    drtShowProgress(false);
                    drtShowToast((json.data.fetched || 0) + '件のキーワードの最新順位を取得しました。');
                    fetchDrtRankings();
                }, 1200);
            } else {
                drtShowProgress(false);
                drtShowToast(json.message || '取得に失敗しました。', 'error');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<span class="drt-btn__icon">&#x1F504;</span> 最新の情報を見る';
            drtShowProgress(false);
            drtShowToast('通信エラーが発生しました。', 'error');
        });
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
                drtDateLabels = json.data.dates || [];
                drtDateKeys = json.data.date_keys || [];
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
        hHtml += '<th>前回</th>';
        for (var d = 0; d < drtDateLabels.length; d++) {
            hHtml += '<th style="text-align:center;">' + drtDateLabels[d] + '</th>';
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
            var daily = kw.daily ? kw.daily[drtDevice] : {};
            var accent = drtGetAccent(dev);

            html += '<tr>';

            // Keyword + volume
            html += '<td>';
            html += '<div class="drt-rank-accent ' + accent + '"></div>';
            html += '<div class="drt-kw-name">' + drtEsc(kw.keyword) + '</div>';
            if (kw.search_volume != null) {
                html += '<div class="drt-kw-meta"><span class="drt-kw-meta-item">Vol: <strong>' + drtFmt(kw.search_volume) + '</strong></span></div>';
            }
            html += '</td>';

            // Current rank
            html += '<td>';
            html += drtFormatCurrent(dev);
            if (dev && otherDev && drtHasBigDiff(dev, otherDev)) {
                html += '<span class="drt-pc-diff">' + (drtDevice === 'mobile' ? 'PC' : 'SP') + 'と差あり</span>';
            }
            html += '</td>';

            // Previous rank
            html += '<td>' + drtFormatPrev(dev) + '</td>';

            // Daily columns (7 days)
            if (drtDateKeys) {
                for (var d = 0; d < drtDateKeys.length; d++) {
                    var dayData = daily ? daily[drtDateKeys[d]] : null;
                    html += '<td class="drt-daily">' + drtFormatDaily(dayData) + '</td>';
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
        if (dev.change != null && dev.change !== 0) {
            html += '<div class="drt-rank-change ' + (dev.change > 0 ? 'drt-rank-change--up' : 'drt-rank-change--down') + '">';
            if (dev.change === 999) html += '&#x2191; NEW';
            else if (dev.change === -999) html += '&#x2193; 圏外';
            else if (dev.change > 0) html += '&#x2191; ' + dev.change;
            else html += '&#x2193; ' + Math.abs(dev.change);
            html += '</div>';
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

    function drtOpenSerpModal(keywordId) {
        var modal = document.getElementById('drtSerpModal');
        var body = document.getElementById('drtSerpModalBody');
        var title = document.getElementById('drtSerpModalTitle');

        var kw = null;
        for (var i = 0; i < drtRankData.length; i++) {
            if (drtRankData[i].keyword_id == keywordId) { kw = drtRankData[i]; break; }
        }
        title.textContent = (kw ? '「' + kw.keyword + '」' : '') + ' 上位ランキング (' + (drtDevice === 'mobile' ? 'スマホ' : 'PC') + ')';
        body.innerHTML = '<div class="drt-loading">上位サイトを取得中...</div>';
        modal.classList.add('active');

        fetch(restBase + 'rank-tracker/serp-top?keyword_id=' + encodeURIComponent(keywordId) + '&device=' + encodeURIComponent(drtDevice), {
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
</script>

<?php get_footer(); ?>
