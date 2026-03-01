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

        // score 再計算（常にv2ロジックで再スコアリング）
        // breakdown の curr/prev から指標を再抽出し、calc_monthly_health_score v2 で再計算
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
        } // end if (!empty($kpi_curr))
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
    <button type="button" class="drilldown-popover-item" data-dd-type="region">
      <span class="drilldown-popover-icon">📍</span>
      <span class="drilldown-popover-label">
        見ている人の場所
        <small class="drilldown-popover-help" data-help-key="region">ホームページを見ている人が、どの地域からアクセスしているかを表しています</small>
      </span>
    </button>
    <button type="button" class="drilldown-popover-item" data-dd-type="page">
      <span class="drilldown-popover-icon">📄</span>
      <span class="drilldown-popover-label">
        訪問の入口となったページ
        <small class="drilldown-popover-help" data-help-key="page">検索やSNS、広告などから、最初に表示されたページです</small>
      </span>
    </button>
    <button type="button" class="drilldown-popover-item" data-dd-type="source">
      <span class="drilldown-popover-icon">🔗</span>
      <span class="drilldown-popover-label">
        見つけたきっかけ
        <small class="drilldown-popover-help" data-help-key="source">検索、SNS、広告、他サイトなど、ホームページを知った経路です</small>
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
                <div class="score-comp-inline-note">
                  <?php
                  $drops = (int)($comp['drops'] ?? 0);
                  if ($drops === 0) {
                      echo '<span class="score-comp-check-ok">急落なし ✓</span>';
                  } else {
                      echo '<span class="score-comp-check-ng">' . esc_html("{$drops}観点で急落（-20%超）") . '</span>';
                  }
                  ?>
                </div>
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
        <button type="button" class="ask-ai-btn" data-ai-ask
          data-ai-instruction="今月の月次レポート結果を見て、いちばん重要な気づきと次にやることを3つ教えて">
          <span class="ask-ai-btn__icon" aria-hidden="true">✨</span>AIに聞く
        </button>
      </div>

      <div class="info-monthly-summary">
        <?php if (!empty($monthly_report['summary'])): ?>
          <?php echo enhance_report_text($monthly_report['summary'], 'default'); ?>
        <?php else: ?>
          <p class="info-monthly-wait">今月のレポートサマリーを生成中です...</p>
        <?php endif; ?>
      </div>


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
        var typeLabels = { region: '見ている人の場所', page: '訪問の入口となったページ', source: '見つけたきっかけ' };
        var parts = month.split('-');
        ddModalTitle.textContent = parts[0] + '年' + parseInt(parts[1], 10) + '月 — ' + typeLabels[type];

        ddLoading.style.display   = 'block';
        ddChartWrap.style.display = 'none';
        ddEmpty.style.display     = 'none';
        ddError.style.display     = 'none';
        ddOverlay.style.display   = 'flex';
        document.body.style.overflow = 'hidden';

        var cacheKey = month + '_' + type;
        if (_ddCache[cacheKey]) {
            renderDrilldownChart(_ddCache[cacheKey]);
            return;
        }

        fetch(restBase + 'dashboard/drilldown?month=' + encodeURIComponent(month)
              + '&type=' + encodeURIComponent(type), {
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

<?php get_footer(); ?>
