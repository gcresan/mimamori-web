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
set_query_var('gcrev_page_title', '全体ダッシュボード');
set_query_var('gcrev_page_subtitle', 'このホームページが、今どんな状態かをひと目で確認できます。');

// パンくず設定（ダッシュボードは2階層: ホーム › 全体ダッシュボード）
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('全体ダッシュボード'));

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
  .kpi-trend-inline-actions { width: 100%; justify-content: flex-end; }
  .kpi-trend-toggle { width: auto; }
}

/* KPI trend actions container (hint + toggle) */
.kpi-trend-inline-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-left: auto;
}

/* KPI trend period toggle */
.kpi-trend-toggle {
  display: flex;
  gap: 0;
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

/* (DRT + MEO sections removed — see page-map-rank.php, page-rank-tracker.php) */

/* ========== 検索・診断の総合状況 ========== */
.search-diag-section { margin: 32px 0 24px; }
.search-diag-title {
  font-size: 18px; font-weight: 700; color: var(--mw-text-heading, #2C3E40);
  margin: 0 0 8px; display: flex; align-items: center; gap: 6px;
}
.search-diag-title .icon { font-size: 20px; }
.search-diag-comment {
  font-size: 14px; color: var(--mw-text-secondary, #64748b);
  line-height: 1.7; margin: 0 0 16px;
}
.search-diag-grid {
  display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px;
}
.search-diag-card {
  display: flex; flex-direction: column;
  background: var(--mw-bg-primary, #fff);
  border: 1px solid var(--mw-border-light, #C3CED0);
  border-radius: var(--mw-radius-md, 16px);
  box-shadow: var(--mw-shadow-card, 0 2px 12px rgba(0,0,0,.04));
  padding: 16px; text-decoration: none; color: inherit;
  transition: box-shadow .2s, border-color .2s;
}
.search-diag-card:hover {
  box-shadow: 0 4px 16px rgba(0,0,0,.1);
  border-color: var(--mw-primary-blue, #568184);
}
.search-diag-card-header {
  display: flex; align-items: center; gap: 6px; margin-bottom: 10px;
}
.search-diag-card-icon { font-size: 18px; }
.search-diag-card-title {
  font-size: 13px; font-weight: 600; color: var(--mw-text-secondary, #64748b);
}
.search-diag-card-body {
  display: flex; align-items: baseline; gap: 8px; margin-bottom: 6px;
}
.search-diag-card-score {
  font-size: 28px; font-weight: 700; color: var(--mw-text-primary, #1e293b); line-height: 1;
}
.search-diag-card-score-unit {
  font-size: 14px; font-weight: 500; margin-left: 1px;
}
.search-diag-card-label {
  font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px; white-space: nowrap;
}
.search-diag-card-label--good      { background: #E8F5E9; color: #2E7D32; }
.search-diag-card-label--warning   { background: #FFF8E1; color: #F57F17; }
.search-diag-card-label--attention { background: #FFEBEE; color: #C62828; }
.search-diag-card-label--none      { background: #F5F5F5; color: #9E9E9E; }
.search-diag-card-summary {
  font-size: 12px; color: var(--mw-text-tertiary, #94a3b8);
  line-height: 1.5; margin: 0; flex: 1;
}
.search-diag-card-link {
  font-size: 12px; color: var(--mw-primary-blue, #568184);
  margin-top: 10px; font-weight: 500;
}
@media (max-width: 1024px) {
  .search-diag-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 600px) {
  .search-diag-grid { grid-template-columns: repeat(2, 1fr); }
  .search-diag-card-score { font-size: 24px; }
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
        // cache_first=1: プリフェッチ済みキャッシュがあれば即返却、なければ空配列
        // （キャッシュなし時はJS非同期で取得→スコアも非同期更新）
        $kpi_curr = $gcrev_api->get_dashboard_kpi('last30', $user_id, 1);

        // 比較期間のKPI（currが取得できた場合のみ）
        // キャッシュのみ確認。キャッシュミス時はJS非同期に委任（同期API呼び出しを排除してページ表示を高速化）
        $kpi_prev = [];
        if (!empty($kpi_curr)) {
            $exclude_foreign_prev = get_user_meta( $user_id, 'report_exclude_foreign', true );
            $filter_suffix_prev   = $exclude_foreign_prev ? '_filtered' : '';
            $prev_cache_key = "gcrev_dash_bydate_{$user_id}_{$last30_comp['start']}_{$last30_comp['end']}{$filter_suffix_prev}";
            $prev_cached    = get_transient( $prev_cache_key );
            if ( $prev_cached !== false && is_array( $prev_cached ) ) {
                $kpi_prev = $prev_cached;
            }
            // キャッシュミス時は $kpi_prev = [] のまま → JS非同期で取得＆表示更新
        }

        // --- MEO直近30日 ---
        // キャッシュのみ確認。キャッシュミス時はJS非同期に委任（同期GBP API呼び出しを排除してページ表示を高速化）
        $meo_cache_curr = get_transient("gcrev_meo_perf_{$user_id}_{$last30['start']}_{$last30['end']}");
        $meo_cache_prev = get_transient("gcrev_meo_perf_{$user_id}_{$last30_comp['start']}_{$last30_comp['end']}");

        $meo_curr = (is_array($meo_cache_curr) && $meo_cache_curr !== false)
            ? (int)($meo_cache_curr['total_impressions'] ?? 0) : null;
        $meo_prev = (is_array($meo_cache_prev) && $meo_cache_prev !== false)
            ? (int)($meo_cache_prev['total_impressions'] ?? 0) : null;

        // MEO電話タップ数（ゴール数合算用）
        $call_clicks_curr = (is_array($meo_cache_curr) && $meo_cache_curr !== false)
            ? (int)($meo_cache_curr['call_clicks'] ?? 0) : null;
        $call_clicks_prev = (is_array($meo_cache_prev) && $meo_cache_prev !== false)
            ? (int)($meo_cache_prev['call_clicks'] ?? 0) : null;

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
        // ゴール数: HPゴール + MEO電話タップ
        $cv_total_curr = $cv_curr + ($call_clicks_curr ?? 0);
        $cv_total_prev = $cv_prev + ($call_clicks_prev ?? 0);
        $infographic['kpi']['cv']     = [
            'value'       => $cv_total_curr,
            'diff'        => $cv_total_curr - $cv_total_prev,
            'hp_cv'       => $cv_curr,
            'call_clicks' => $call_clicks_curr ?? 0,
        ];
        // MEOはキャッシュがある場合のみ上書き（なければJS非同期で後から更新）
        if ($meo_curr !== null && $meo_prev !== null) {
            $infographic['kpi']['meo'] = ['value' => $meo_curr, 'diff' => $meo_curr - $meo_prev];
        }

        // === スコア計算（キャッシュ済みなら復元、なければ計算してキャッシュ保存）===
        if ( !empty($kpi_curr) && isset($kpi_curr['_cached_score']) ) {
            // キャッシュ済みスコアを復元（再計算しない → スコア安定化）
            $infographic['score']      = $kpi_curr['_cached_score']['score'];
            $infographic['status']     = $kpi_curr['_cached_score']['status'];
            $infographic['breakdown']  = $kpi_curr['_cached_score']['breakdown'];
            $infographic['components'] = $kpi_curr['_cached_score']['components'];
        } else {
            // スコア計算（初回 or キャッシュにスコアが含まれていない場合）
            // MEO: 片方でもキャッシュミスなら両方0にする（非対称比較によるスコア水増し防止）
            $meo_curr_score = ($meo_curr !== null && $meo_prev !== null) ? $meo_curr : 0;
            $meo_prev_score = ($meo_curr !== null && $meo_prev !== null) ? $meo_prev : 0;
            $re_curr = ['traffic' => $sess_curr, 'cv' => $cv_curr, 'gsc' => $gsc_curr_val, 'meo' => $meo_curr_score];
            $re_prev = ['traffic' => $sess_prev, 'cv' => $cv_prev, 'gsc' => $gsc_prev_val, 'meo' => $meo_prev_score];
            $re_health = $gcrev_api->calc_monthly_health_score($re_curr, $re_prev, [], $user_id);
            $infographic['score']      = $re_health['score'];
            $infographic['status']     = $re_health['status'];
            $infographic['breakdown']  = $re_health['breakdown'];
            $infographic['components'] = $re_health['components'];

            // スコアをKPIキャッシュに保存（次回以降スコア安定化）
            // ※ kpi_prev が空の場合はスコアをキャッシュしない（prev=0 で水増しスコアが24h持続するのを防止）
            if ( !empty($kpi_curr) && !empty($kpi_prev) ) {
                $kpi_curr['_cached_score'] = [
                    'score'      => $re_health['score'],
                    'status'     => $re_health['status'],
                    'breakdown'  => $re_health['breakdown'],
                    'components' => $re_health['components'],
                ];
                $exclude_foreign_sc = get_user_meta( $user_id, 'report_exclude_foreign', true );
                $filter_suffix_sc   = $exclude_foreign_sc ? '_jp' : '';
                set_transient(
                    "gcrev_dash_{$user_id}_last30{$filter_suffix_sc}",
                    $kpi_curr,
                    24 * HOUR_IN_SECONDS
                );
            }
        }
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

// 検索・診断の総合状況
$search_diag = mimamori_get_search_diagnostic_summary( $user_id );
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
  $congrats_label_map    = ['visits' => '訪問数', 'cv' => $cv_label, 'meo' => 'マップ表示'];
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
          <span class="info-score-value"><?php
            if (empty($kpi_curr) && $score_val === 0) {
                echo '<span class="info-kpi-spinner"></span>';
            } else {
                echo esc_html((string)$score_val) . '<span class="info-score-unit">点</span>';
            }
          ?></span>
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
        // クライアントごとのゴール名カスタマイズ（未設定時はデフォルト）
        $cv_label_custom = get_user_meta($user_id, '_gcrev_cv_label', true);
        $cv_label = !empty($cv_label_custom) ? $cv_label_custom : 'ゴール数';
        $cv_sub   = !empty($cv_label_custom) ? '' : 'お問い合わせ・電話タップなど';

        $kpi_items = [
          'visits' => ['label' => '訪問数',       'sub' => 'ホームページを見に来た人数', 'icon' => '👥', 'metric' => 'sessions'],
          'cv'     => ['label' => $cv_label,      'sub' => $cv_sub,                     'icon' => '🎯', 'metric' => 'cv'],
          'meo'    => ['label' => 'マップ表示回数', 'sub' => 'Googleマップで見られた回数', 'icon' => '📍', 'metric' => 'meo'],
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
            <span class="info-kpi-label"><?php echo esc_html($meta['label']); ?><?php if (!empty($meta['sub'])): ?><span class="info-kpi-sub"><?php echo esc_html($meta['sub']); ?></span><?php endif; ?></span>
            <span class="info-kpi-value" data-kpi-role="value"><?php echo esc_html(number_format($kpi_val)); ?></span>
            <span class="info-kpi-diff <?php echo esc_attr($kpi_diff_class); ?>" data-kpi-role="diff">
              <?php echo esc_html($kpi_diff_icon . ' ' . $kpi_diff_text); ?>
            </span>
            <?php if ($key === 'cv'): ?>
            <span class="info-kpi-breakdown" data-kpi-role="breakdown">
              <?php
              $hp_cv_val = (int)($kpi['hp_cv'] ?? $kpi_val);
              $cc_val    = (int)($kpi['call_clicks'] ?? 0);
              if ($hp_cv_val > 0 || $cc_val > 0) {
                  echo '<span class="bk-item">HP ' . esc_html(number_format($hp_cv_val)) . '</span>';
                  echo '<span class="bk-sep">/</span>';
                  echo '<span class="bk-item">電話タップ ' . esc_html(number_format($cc_val)) . '</span>';
              }
              ?>
            </span>
            <?php endif; ?>
            <span class="info-kpi-hint">クリックでグラフ切替</span>
          </button>
        <?php $first_kpi = false; endforeach; ?>
      </div>
    </div>
  </div>

  <!-- サマリー（結論サマリーに集約したため削除） -->

  <!-- KPI トレンドチャート（インライン常時表示） -->
  <div class="kpi-trend-inline" id="kpiTrendInline">
    <div class="kpi-trend-inline-header">
      <h3 class="kpi-trend-inline-title" id="kpiTrendTitle">
        <span class="kpi-trend-inline-icon" id="kpiTrendIcon">👥</span>
        <span id="kpiTrendTitleText">訪問数 — 過去12ヶ月の推移</span>
      </h3>
      <div class="kpi-trend-inline-actions">
        <span class="kpi-trend-inline-hint" id="kpiTrendHint" title="各月の点をクリックすると、内訳データを確認できます">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1Zm0 12.5a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11ZM8 5a.75.75 0 1 1 0-1.5A.75.75 0 0 1 8 5Zm-.75 1.75a.75.75 0 0 1 1.5 0v3.5a.75.75 0 0 1-1.5 0v-3.5Z"/></svg>
          <span class="kpi-trend-inline-hint-text">各月の点をクリックで詳細を表示</span>
        </span>
        <div class="kpi-trend-toggle" id="kpiTrendToggle">
          <button type="button" class="kpi-trend-toggle-btn is-active" data-view="daily">直近30日</button>
          <button type="button" class="kpi-trend-toggle-btn" data-view="monthly">1年間</button>
        </div>
      </div>
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

  <!-- 検索・診断の総合状況 -->
  <section class="search-diag-section">
    <h2 class="search-diag-title"><span class="icon" aria-hidden="true">🔍</span>検索・診断の総合状況</h2>
    <?php if ( ! empty( $search_diag['overall_comment'] ) ): ?>
    <p class="search-diag-comment"><?php echo esc_html( $search_diag['overall_comment'] ); ?></p>
    <?php endif; ?>
    <div class="search-diag-grid">
      <?php
      $sd_cards = [
        'organic_rank'  => [ 'icon' => '🔍', 'title' => '自然検索順位' ],
        'map_rank'      => [ 'icon' => '📍', 'title' => 'マップ順位' ],
        'seo_diagnosis' => [ 'icon' => '🛡️', 'title' => 'SEO診断' ],
        'aio_score'     => [ 'icon' => '🤖', 'title' => 'AIO診断' ],
        'meo_diagnosis' => [ 'icon' => '📋', 'title' => 'MEO診断' ],
      ];
      foreach ( $sd_cards as $sd_key => $sd_meta ):
        $sd_card  = $search_diag[ $sd_key ] ?? null;
        $sd_none  = ! $sd_card || ( $sd_card['status'] ?? '' ) === 'none';
        $sd_st    = $sd_none ? 'none' : $sd_card['status'];
      ?>
      <a href="<?php echo esc_url( home_url( $sd_card['link'] ?? '#' ) ); ?>" class="search-diag-card">
        <div class="search-diag-card-header">
          <span class="search-diag-card-icon"><?php echo $sd_meta['icon']; ?></span>
          <span class="search-diag-card-title"><?php echo esc_html( $sd_meta['title'] ); ?></span>
        </div>
        <?php if ( $sd_none ): ?>
        <div class="search-diag-card-body">
          <span class="search-diag-card-score">--</span>
          <span class="search-diag-card-label search-diag-card-label--none">未取得</span>
        </div>
        <p class="search-diag-card-summary">まだデータがありません</p>
        <?php else: ?>
        <div class="search-diag-card-body">
          <span class="search-diag-card-score"><?php echo esc_html( (string) round( $sd_card['score'] ) ); ?><span class="search-diag-card-score-unit">点</span></span>
          <span class="search-diag-card-label search-diag-card-label--<?php echo esc_attr( $sd_st ); ?>">
            <?php echo esc_html( $sd_card['label'] ); ?>
          </span>
        </div>
        <p class="search-diag-card-summary"><?php echo esc_html( $sd_card['summary'] ); ?></p>
        <?php endif; ?>
        <span class="search-diag-card-link">詳細を見る →</span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

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
          data-ai-instruction="直近の月次レポート結果を見て、いちばん重要な気づきと次にやることを3つ教えて">
          <span class="ask-ai-btn__icon" aria-hidden="true">✨</span>AIに聞く
        </button>
        <?php endif; ?>
      </div>

      <?php if (!empty($monthly_report['summary'])): ?>
      <?php
      // サマリーテキストを3ブロック（状況・要因・次の行動）に構造化して表示
      $summary_text = $monthly_report['summary'];
      $highlight_detail_fact    = $highlight_details['top_issue']['fact'] ?? '';
      $highlight_detail_causes  = $highlight_details['top_issue']['causes'] ?? [];
      $highlight_detail_actions = $highlight_details['top_issue']['actions'] ?? [];
      $next_action = !empty($infographic['action'])
          ? $infographic['action']
          : ($highlights['opportunity'] ?? '');

      // ハイライトから3ブロックの素材を組み立て
      $block_situation = $summary_text;
      $block_cause     = $highlight_detail_fact;
      if (empty($block_cause) && !empty($highlight_detail_causes)) {
          $block_cause = implode('。', array_slice($highlight_detail_causes, 0, 2));
      }
      $block_action = '';
      if (!empty($highlight_detail_actions)) {
          $block_action = implode('。', array_slice($highlight_detail_actions, 0, 2));
      } elseif (!empty($next_action)) {
          $block_action = $next_action;
      }
      ?>

      <div class="info-summary-blocks">
        <div class="info-summary-block">
          <div class="info-summary-block-label">
            <span class="info-summary-block-icon">📊</span> 現状
          </div>
          <div class="info-summary-block-text">
            <?php echo enhance_report_text($block_situation, 'default'); ?>
          </div>
        </div>

        <?php if (!empty($block_cause)): ?>
        <div class="info-summary-block">
          <div class="info-summary-block-label">
            <span class="info-summary-block-icon">🔍</span> 主な要因
          </div>
          <div class="info-summary-block-text">
            <p><?php echo esc_html($block_cause); ?></p>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($block_action)): ?>
        <div class="info-summary-block">
          <div class="info-summary-block-label">
            <span class="info-summary-block-icon">✅</span> 次にやること
          </div>
          <div class="info-summary-block-text">
            <p><?php echo esc_html($block_action); ?></p>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php else: ?>
        <div class="info-monthly-summary">
          <p class="info-monthly-wait">今月のレポートサマリーを生成中です...</p>
        </div>
      <?php endif; ?>

<?php if (!$can_highlights): ?>
<!-- 見える化プラン: ロック表示 -->
<div class="plan-locked-section">
    <div class="plan-locked-overlay">
        <div class="plan-locked-icon">&#x1F512;</div>
        <p class="plan-locked-message">改善提案プランで、改善ポイントや<br>次にやるべきことのアドバイスが見られます</p>
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




</div><!-- .content-area -->

<script>
// 解析ユニットID（タブ切り替え時のURL ?unit=X から取得）
(function(){
    // スコアゲージ更新（非同期KPI取得後に呼ばれる）
    function updateScoreGauge(score) {
        var circumference = 326.73; // 2 * π * 52
        var pct = Math.max(0, Math.min(100, score));
        var offset = circumference * (1 - pct / 100);

        // SVG circle
        var circle = document.querySelector('.score-progress');
        if (circle) {
            circle.style.transition = 'stroke-dashoffset 0.8s ease-out';
            circle.setAttribute('stroke-dashoffset', offset);
        }

        // スコア数値
        var valEl = document.querySelector('.info-score-value');
        if (valEl) valEl.innerHTML = score + '<span class="info-score-unit">点</span>';

        // ステータスラベル
        var statusEl = document.querySelector('.info-score-status');
        if (statusEl) {
            var status = score >= 70 ? '安定しています' : (score >= 50 ? '改善傾向です' : (score >= 35 ? 'もう少しです' : '要注意です'));
            statusEl.textContent = status;
        }
    }

    // スコア内訳モーダルを動的更新（非同期スコア計算後に呼ばれる）
    function updateScoreBreakdownModal(score, components) {
        // モーダル内の合計スコアを更新
        var totalVal = document.querySelector('.score-breakdown-total-value');
        if (totalVal) totalVal.textContent = String(score);

        if (!components || typeof components !== 'object') return;

        // コンポーネントリストを生成
        var listEl = document.querySelector('.score-comp-list');
        if (!listEl) {
            // v2 リストがまだない場合（PHP側で空だった場合）、作成
            var body = document.querySelector('.score-breakdown-modal-body');
            if (!body) return;
            // 「内訳は集計中です」テキストがあれば除去
            var emptyMsg = body.querySelector('.score-breakdown-empty');
            if (emptyMsg) emptyMsg.remove();
            listEl = document.createElement('div');
            listEl.className = 'score-comp-list';
            // .score-breakdown-total の後に挿入
            var totalDiv = body.querySelector('.score-breakdown-total');
            if (totalDiv && totalDiv.nextSibling) {
                body.insertBefore(listEl, totalDiv.nextSibling);
            } else {
                body.appendChild(listEl);
            }
        }

        var compIcons = { achievement: '📊', growth: '📈', stability: '\u{1F6E1}\uFE0F', action: '⭐' };
        var compLabels = { achievement: '実績（中央値比較）', growth: '成長（前月比）', stability: '安定性', action: '行動ボーナス' };
        var dimLabels = { traffic: 'サイトに来た人の数', cv: 'ゴール（問い合わせ・申込みなど）', gsc: '検索結果からクリックされた数', meo: '地図検索からの表示数' };

        var html = '';
        var compOrder = ['achievement', 'growth', 'stability', 'action'];
        compOrder.forEach(function(key) {
            var comp = components[key];
            if (!comp) return;
            var pts = parseInt(comp.points || 0, 10);
            var max = parseInt(comp.max || 0, 10);
            var pct = max > 0 ? Math.min(100, (pts / max) * 100) : 0;
            var icon = compIcons[key] || '📊';
            var label = comp.label || compLabels[key] || key;

            html += '<div class="score-comp-card">';
            html += '<div class="score-comp-header">';
            html += '<span class="score-comp-icon">' + icon + '</span>';
            html += '<span class="score-comp-label">' + label + '</span>';
            html += '<span class="score-comp-pts">' + pts + ' / ' + max + 'pt</span>';
            html += '</div>';
            html += '<div class="score-comp-bar"><div class="score-comp-bar-fill" style="width:' + pct + '%"></div></div>';

            // 内訳表示（achievement, growth）
            if ((key === 'achievement' || key === 'growth') && comp.details) {
                html += '<details class="score-comp-details"><summary>▶内訳を見る</summary><div class="score-comp-details-body">';
                Object.keys(comp.details).forEach(function(dk) {
                    var dim = comp.details[dk];
                    var dLabel = dimLabels[dk] || dk;
                    var dPts = dim.points || 0;
                    var dMax = dim.max || 0;
                    html += '<div class="score-comp-dim-row">';
                    html += '<span class="score-comp-dim-label">' + dLabel + '</span>';
                    html += '<span class="score-comp-dim-pts">' + dPts + '/' + dMax + '</span>';
                    html += '</div>';
                });
                html += '</div></details>';
            }

            // 安定性
            if (key === 'stability') {
                var drops = parseInt(comp.drops || 0, 10);
                html += '<div class="score-comp-inline-note">';
                html += drops === 0
                    ? '<span class="score-comp-check-ok">急落なし ✓</span>'
                    : '<span class="score-comp-check-ng">' + drops + '観点で急落（-20%超）</span>';
                html += '</div>';
            }

            // 行動ボーナス
            if (key === 'action' && comp.checks) {
                html += '<div class="score-comp-checklist">';
                comp.checks.forEach(function(chk) {
                    var cls = chk.ok ? 'is-ok' : 'is-ng';
                    var mark = chk.ok ? '✓' : '✗';
                    html += '<span class="score-comp-check-item ' + cls + '">' + mark + ' ' + chk.label + '</span>';
                });
                html += '</div>';
            }

            html += '</div>';
        });

        listEl.innerHTML = html;
    }

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
    // ゴール数カードの内訳を更新
    function updateCvBreakdown(hpCv, callClicks){
        var el = document.querySelector('[data-kpi-key="cv"] [data-kpi-role="breakdown"]');
        if(!el) return;
        if(hpCv > 0 || callClicks > 0){
            el.innerHTML = '<span class="bk-item">HP ' + fmt(hpCv) + '</span>'
                         + '<span class="bk-sep">/</span>'
                         + '<span class="bk-item">\u96fb\u8a71\u30bf\u30c3\u30d7 ' + fmt(callClicks) + '</span>';
        } else {
            el.innerHTML = '';
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

    var currHpCv = parseInt(String(curr.conversions || 0).replace(/,/g, ''), 10);
    var prevHpCv = prev ? parseInt(String(prev.conversions || 0).replace(/,/g, ''), 10) : 0;

    // MEO
    <?php if ($meo_curr !== null && $meo_prev !== null): ?>
    // MEOキャッシュヒット: PHP側で取得済み
    var meoCurr = <?php echo (int)$meo_curr; ?>;
    var meoPrev = <?php echo (int)$meo_prev; ?>;
    updateInfoKpi('meo', meoCurr, meoCurr - meoPrev);
    // ゴール数: HPゴール + MEO電話タップ
    var ccCurr = <?php echo (int)($call_clicks_curr ?? 0); ?>;
    var ccPrev = <?php echo (int)($call_clicks_prev ?? 0); ?>;
    updateInfoKpi('cv', currHpCv + ccCurr, (currHpCv + ccCurr) - (prevHpCv + ccPrev));
    updateCvBreakdown(currHpCv, ccCurr);
    <?php else: ?>
    // MEOキャッシュミス: まずHPゴールのみ表示、MEO非同期で後から合算
    updateInfoKpi('cv', currHpCv, currHpCv - prevHpCv);
    updateCvBreakdown(currHpCv, 0);
    (function(){
        var restBase = <?php echo wp_json_encode(esc_url_raw(rest_url('gcrev/v1/'))); ?>;
        var nonce    = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
        var card = document.querySelector('[data-kpi-key="meo"]');
        if (card) {
            card.classList.add('is-kpi-loading');
            card.setAttribute('aria-busy', 'true');
        }
        fetch(restBase + 'meo/dashboard?period=last30', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        }).then(function(r){ return r.json(); }).then(function(meoData){
            var mC = (meoData && meoData.metrics) ? parseInt(meoData.metrics.total_impressions || 0, 10) : 0;
            var mP = (meoData && meoData.metrics_previous) ? parseInt(meoData.metrics_previous.total_impressions || 0, 10) : 0;
            updateInfoKpi('meo', mC, mC - mP);
            // ゴール数にMEO電話タップを合算
            var ccC = (meoData && meoData.metrics) ? parseInt(meoData.metrics.call_clicks || 0, 10) : 0;
            var ccP = (meoData && meoData.metrics_previous) ? parseInt(meoData.metrics_previous.call_clicks || 0, 10) : 0;
            updateInfoKpi('cv', currHpCv + ccC, (currHpCv + ccC) - (prevHpCv + ccP));
            updateCvBreakdown(currHpCv, ccC);
            if (card) {
                card.classList.remove('is-kpi-loading');
                card.classList.add('is-kpi-loaded');
                card.setAttribute('aria-busy', 'false');
            }
        }).catch(function(){
            if (card) {
                card.classList.remove('is-kpi-loading');
                card.setAttribute('aria-busy', 'false');
            }
        });
    })();
    <?php endif; ?>

    <?php if (empty($kpi_prev)): ?>
    // --- 比較期間のみ非同期取得（currはキャッシュヒット済み、prevのみ未取得）---
    (function(){
        var restBase = <?php echo wp_json_encode(esc_url_raw(rest_url('gcrev/v1/'))); ?>;
        var nonce    = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
        var compStart = <?php echo wp_json_encode($last30_comp['start']); ?>;
        var compEnd   = <?php echo wp_json_encode($last30_comp['end']); ?>;

        fetch(restBase + 'dashboard/kpi?start_date=' + encodeURIComponent(compStart) + '&end_date=' + encodeURIComponent(compEnd), {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        }).then(function(r){ return r.json(); }).then(function(result){
            var prev = result.success ? result.data : null;
            if (!prev) return;

            var cS = <?php echo (int)$sess_curr; ?>;
            var pS = parseInt(String(prev.sessions || 0).replace(/,/g, ''), 10);
            var cHpCv = <?php echo (int)$cv_curr; ?>;
            var pHpCv = parseInt(String(prev.conversions || 0).replace(/,/g, ''), 10);
            var cCC = <?php echo (int)($call_clicks_curr ?? 0); ?>;
            var pCC = <?php echo (int)($call_clicks_prev ?? 0); ?>;

            updateInfoKpi('visits', cS, cS - pS);
            updateInfoKpi('cv', cHpCv + cCC, (cHpCv + cCC) - (pHpCv + pCC));
            updateCvBreakdown(cHpCv, cCC);

            // スコアも再計算（prev=0 で計算された水増しスコアを修正）
            var gscCurr = <?php echo (int)$gsc_curr_val; ?>;
            var gscPrev = parseInt(String((prev.gsc && prev.gsc.total ? prev.gsc.total.clicks || prev.gsc.total.impressions : 0) || 0).replace(/,/g, ''), 10);
            var mCurr = <?php echo (int)($meo_curr ?? 0); ?>;
            var mPrev = <?php echo (int)($meo_prev ?? 0); ?>;

            fetch(restBase + 'dashboard/score', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    curr: { traffic: cS, cv: cC, gsc: gscCurr, meo: mCurr },
                    prev: { traffic: pS, cv: pC, gsc: gscPrev, meo: mPrev }
                })
            }).then(function(r){ return r.json(); }).then(function(scoreRes){
                if (scoreRes.success && typeof updateScoreGauge === 'function') {
                    updateScoreGauge(scoreRes.score);
                    if (scoreRes.components) updateScoreBreakdownModal(scoreRes.score, scoreRes.components);
                }
            });
        });
    })();
    <?php endif; ?>

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

        // キャッシュチェック（sessionStorage）
        var _dashCacheKey = 'main_dash_kpi';
        var _dashCached = window.gcrevCache && window.gcrevCache.get(_dashCacheKey);
        if (_dashCached && _dashCached.curr && _dashCached.meoData) {
            clearTimeout(timeoutId);
            var curr = _dashCached.curr;
            var prev = _dashCached.prev;
            var meoData = _dashCached.meoData;
            var cS = _dashCached.cS, pS = _dashCached.pS, cC = _dashCached.cC, pC = _dashCached.pC;
            var mCurr = _dashCached.mCurr, mPrev = _dashCached.mPrev;
            var ccC = _dashCached.ccCurr || 0, ccP = _dashCached.ccPrev || 0;
            updateInfoKpi('visits', cS, cS - pS); finishCard('visits');
            updateInfoKpi('cv', cC + ccC, (cC + ccC) - (pC + ccP)); finishCard('cv');
            updateCvBreakdown(cC, ccC);
            updateInfoKpi('meo', mCurr, mCurr - mPrev); finishCard('meo');
            if (_dashCached.score !== undefined) updateScoreGauge(_dashCached.score);
            return; // キャッシュヒット — API呼び出しなし
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

            // staleデータの場合は注記を表示
            var isStale = results[0].stale || results[1].stale;
            if (isStale) {
                var staleMsg = results[0].message || results[1].message || '';
                var staleEl = document.getElementById('kpiStaleNotice');
                if (!staleEl) {
                    staleEl = document.createElement('div');
                    staleEl.id = 'kpiStaleNotice';
                    staleEl.style.cssText = 'background:#fff3cd;color:#856404;padding:8px 16px;border-radius:6px;font-size:13px;margin-bottom:12px;';
                    staleEl.textContent = staleMsg || '最新データの取得に失敗したため、前回のデータを表示しています。';
                    var kpiWrap = document.querySelector('.info-kpi-cards');
                    if (kpiWrap && kpiWrap.parentNode) kpiWrap.parentNode.insertBefore(staleEl, kpiWrap);
                }
            }

            if (!curr) {
                ['visits', 'cv'].forEach(function(k){ errorCard(k); });
            } else {
                var cS = parseInt(String(curr.sessions || 0).replace(/,/g, ''), 10);
                var pS = prev ? parseInt(String(prev.sessions || 0).replace(/,/g, ''), 10) : 0;
                updateInfoKpi('visits', cS, cS - pS);
                finishCard('visits');

                var cC = parseInt(String(curr.conversions || 0).replace(/,/g, ''), 10);
                var pC = prev ? parseInt(String(prev.conversions || 0).replace(/,/g, ''), 10) : 0;
                // MEO電話タップをゴール数に合算
                var ccC = (meoData && meoData.metrics) ? parseInt(meoData.metrics.call_clicks || 0, 10) : 0;
                var ccP = (meoData && meoData.metrics_previous) ? parseInt(meoData.metrics_previous.call_clicks || 0, 10) : 0;
                updateInfoKpi('cv', cC + ccC, (cC + ccC) - (pC + ccP));
                updateCvBreakdown(cC, ccC);
                finishCard('cv');
            }

            // MEO（meo/dashboard は metrics + metrics_previous を一括返却）
            var mCurr = (meoData && meoData.metrics) ? parseInt(meoData.metrics.total_impressions || 0, 10) : 0;
            var mPrev = (meoData && meoData.metrics_previous) ? parseInt(meoData.metrics_previous.total_impressions || 0, 10) : 0;
            updateInfoKpi('meo', mCurr, mCurr - mPrev);
            finishCard('meo');

            // --- スコアゲージ非同期更新（サーバーサイドで統一計算） ---
            if (curr) {
                var gscCurr = parseInt(String((curr.gsc && curr.gsc.total ? curr.gsc.total.clicks || curr.gsc.total.impressions : 0) || 0).replace(/,/g, ''), 10);
                var gscPrev = prev ? parseInt(String((prev.gsc && prev.gsc.total ? prev.gsc.total.clicks || prev.gsc.total.impressions : 0) || 0).replace(/,/g, ''), 10) : 0;

                // サーバーサイドで calc_monthly_health_score を使って統一スコアを計算
                fetch(restBase + 'dashboard/score', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        curr: { traffic: cS, cv: cC, gsc: gscCurr, meo: mCurr },
                        prev: { traffic: pS, cv: pC, gsc: gscPrev, meo: mPrev }
                    })
                }).then(function(r){ return r.json(); }).then(function(scoreRes){
                    if (scoreRes.success) {
                        updateScoreGauge(scoreRes.score);
                        if (scoreRes.components) updateScoreBreakdownModal(scoreRes.score, scoreRes.components);
                        // キャッシュに保存（スコア込み）
                        if (window.gcrevCache) {
                            window.gcrevCache.set(_dashCacheKey, {
                                curr: curr, prev: prev, meoData: meoData,
                                cS: cS, pS: pS, cC: cC, pC: pC,
                                ccCurr: ccC, ccPrev: ccP,
                                mCurr: mCurr, mPrev: mPrev,
                                score: scoreRes.score
                            });
                        }
                    }
                });
            } else {
                // スコア計算なしでもキャッシュ保存
                if (window.gcrevCache) {
                    window.gcrevCache.set(_dashCacheKey, {
                        curr: curr, prev: prev, meoData: meoData,
                        cS: cS, pS: pS, cC: cC, pC: pC,
                        mCurr: mCurr, mPrev: mPrev
                    });
                }
            }
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

    // DOM参照: ヒントテキスト
    var hintEl = document.getElementById('kpiTrendHint');

    // (1) 先読み — sessionsのみ即時fetch、残りは遅延
    function prefetchMetric(m) {
        return fetch(restBase + 'dashboard/trends?metric=' + encodeURIComponent(m) + '&view=daily', {
            headers: { 'X-WP-Nonce': nonce },
            credentials: 'same-origin'
        })
        .then(function(res){ return res.json(); })
        .then(function(json){ _trendCache.daily[m] = json; return json; });
    }

    // sessionsを即時fetchして初期チャート描画
    prefetchMetric('sessions')
        .then(function(){
            if (!_activeMetric) showTrend('sessions', '訪問数', '👥');
        })
        .catch(function(){
            if (!_activeMetric) showError('sessions', '訪問数', '👥');
        });

    // cv, meo は2秒後に遅延先読み（初期表示をブロックしない）
    setTimeout(function(){
        prefetchMetric('cv');
        prefetchMetric('meo');
    }, 2000);

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

        // MEO時はドリルダウンヒントを非表示、それ以外は表示
        if (hintEl) {
            hintEl.style.display = (metric === 'meo') ? 'none' : '';
            var hintText = hintEl.querySelector('.kpi-trend-inline-hint-text');
            if (hintText) {
                hintText.textContent = _activeView === 'daily'
                    ? 'クリックで該当月の詳細を表示'
                    : '各月の点をクリックで詳細を表示';
            }
        }

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
        var isMeo   = _activeMetric === 'meo';
        // MEO以外はドリルダウン可能（日別・月別両方）
        var canDrill = !isMeo;

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
                    evt.native.target.style.cursor = (elements.length && canDrill) ? 'pointer' : 'default';
                },
                onClick: function(evt, elements) {
                    if (!canDrill || !elements.length) return;
                    var idx = elements[0].index;
                    // 日別: YYYY-MM-DD をそのまま渡す、月別: YYYY-MM
                    var month = json.labels[idx];
                    showDrilldownPopover(month, elements[0].element);
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(ctx){ return json.labels[ctx[0].dataIndex]; },
                            label: function(ctx){ return label + ': ' + ctx.parsed.y.toLocaleString(); },
                            afterLabel: function(){
                                return canDrill ? 'クリックして詳細を表示' : '';
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
        // YYYY-MM-DD（日別）か YYYY-MM（月別）かでタイトルを分岐
        ddPopTitle.textContent = parts.length >= 3
            ? parts[0] + '年' + parseInt(parts[1], 10) + '月' + parseInt(parts[2], 10) + '日'
            : parts[0] + '年' + parseInt(parts[1], 10) + '月';

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
        var dateStr = parts.length >= 3
            ? parts[0] + '年' + parseInt(parts[1], 10) + '月' + parseInt(parts[2], 10) + '日'
            : parts[0] + '年' + parseInt(parts[1], 10) + '月';
        ddModalTitle.textContent = dateStr + ' — ' + typeLabel;

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



<?php get_footer(); ?>
