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

// パンくず設定
$breadcrumb = '<a href="' . home_url() . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="#">ホームページ</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<strong>全体のようす</strong>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

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

/* KPI trend modal responsive (page-specific) */
@media (max-width: 600px) {
  .kpi-trend-modal { max-width: 100%; border-radius: 12px; }
  .kpi-trend-chart-wrap { height: 240px; }
  .kpi-trend-header { padding: 16px 16px 10px; }
  .kpi-trend-body { padding: 16px; }
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

        // score 再計算（全観点の points を合算）
        $new_score_total = 0;
        foreach ($infographic['breakdown'] as $bk => $bv) {
            if (is_array($bv) && isset($bv['points'])) {
                $new_score_total += (int)$bv['points'];
            }
        }
        $infographic['score'] = max(0, min(100, $new_score_total));
        if ($infographic['score'] >= 75) $infographic['status'] = '安定しています';
        elseif ($infographic['score'] >= 50) $infographic['status'] = '改善傾向です';
        else $infographic['status'] = '要注意です';
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
            'top_issue'      => 'コンバージョン改善',
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

  <!-- スコア + KPI 横並びエリア -->
  <div class="info-top-row">
    <!-- スコア -->
    <div class="info-score">
      <div class="info-score-circle">
        <span class="info-score-value"><?php echo esc_html((string)($infographic['score'] ?? 0)); ?></span>
        <span class="info-score-label">/ 100</span>
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
    </div>

    <!-- KPI -->
    <div class="info-kpi-area">
      <h3 class="section-title info-kpi-heading">主な指標</h3>
      <div class="info-kpi">
        <?php
        $kpi_items = [
          'visits' => ['label' => '訪問数',   'icon' => '👥', 'metric' => 'sessions'],
          'cv'     => ['label' => '問合せ数', 'icon' => '🎯', 'metric' => 'cv'],
          'meo'    => ['label' => 'MEO表示',  'icon' => '📍', 'metric' => 'meo'],
        ];
        foreach ($kpi_items as $key => $meta):
          $kpi = $infographic['kpi'][$key] ?? ['value' => 0, 'diff' => 0];
          $kpi_val  = (int)($kpi['value'] ?? 0);
          $kpi_diff = (int)($kpi['diff'] ?? 0);

          $kpi_diff_class = $kpi_diff > 0 ? 'positive' : ($kpi_diff < 0 ? 'negative' : 'neutral');
          $kpi_diff_icon  = $kpi_diff > 0 ? '▲' : ($kpi_diff < 0 ? '▼' : '→');
          $kpi_diff_text  = $kpi_diff > 0 ? '+' . number_format($kpi_diff) : number_format($kpi_diff);
        ?>
          <div class="info-kpi-item" data-kpi-key="<?php echo esc_attr($key); ?>" data-metric="<?php echo esc_attr($meta['metric']); ?>">
            <span class="info-kpi-icon"><?php echo $meta['icon']; ?></span>
            <span class="info-kpi-label"><?php echo esc_html($meta['label']); ?></span>
            <span class="info-kpi-value" data-kpi-role="value"><?php echo esc_html(number_format($kpi_val)); ?></span>
            <span class="info-kpi-diff <?php echo esc_attr($kpi_diff_class); ?>" data-kpi-role="diff">
              <?php echo esc_html($kpi_diff_icon . ' ' . $kpi_diff_text); ?>
            </span>
            <span class="info-kpi-hint">クリックで推移を見る 📊</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- サマリー -->
  <div class="info-summary">
    <?php echo esc_html($infographic['summary'] ?? ''); ?>
  </div>



  <!-- 採点の内訳（breakdown） -->
  <?php
  $breakdown = $infographic['breakdown'] ?? null;
  $has_breakdown = is_array($breakdown) && !empty($breakdown);
  ?>
  <details class="info-breakdown-details">
    <summary class="info-breakdown-toggle">
      <span class="info-breakdown-toggle-icon">📋</span>
      <span>採点の内訳を見る</span>
      <span class="info-breakdown-arrow" aria-hidden="true">▾</span>
    </summary>

    <?php if ($has_breakdown): ?>
      <div class="info-breakdown-body">
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
          $bd_icons = [
            'traffic' => '👥',
            'cv'      => '🎯',
            'gsc'     => '🔍',
            'meo'     => '📍',
          ];
          $bd_labels = [
            'traffic' => 'サイトに来た人の数',
            'cv'      => '問い合わせ・申込み',
            'gsc'     => '検索結果からクリックされた数',
            'meo'     => '地図検索からの表示数',
          ];
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
      <div class="info-breakdown-body">
        <p class="info-breakdown-empty">内訳は集計中です</p>
      </div>
    <?php endif; ?>
  </details>

  <!-- 結論サマリー + ハイライト（インフォ内に統合） -->
  <?php if (!empty($monthly_report)): ?>
    <div class="info-monthly">
      <div class="info-monthly-head">
        <div class="info-monthly-title">
          <span class="info-monthly-pin">📌</span>
          <span>結論サマリー</span>
        </div>
        <!-- ボタンは外枠右上へ移動したため、ここには置かない -->
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
    ['label' => '📈 最重要ポイント',     'value' => $highlights['most_important'] ?? '新規ユーザー獲得', 'key' => 'most_important'],
    ['label' => '⚠️ 最優先課題',         'value' => $highlights['top_issue'] ?? 'コンバージョン改善',    'key' => 'top_issue'],
    ['label' => '🎯 ネクストアクション', 'value' => $next_action,                                       'key' => 'opportunity'],
];

foreach ($highlight_items as $highlight):
    $detail    = $highlight_details[$highlight['key']] ?? null;
    $detail_id = 'highlight-detail-' . esc_attr($highlight['key']);
?>
    <div class="info-monthly-highlight-item">
        <div class="info-monthly-highlight-label">
            <?php echo esc_html($highlight['label']); ?>
        </div>
        <div class="info-monthly-highlight-value">
            <?php echo esc_html($highlight['value']); ?>
        </div>

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


<?php endif; ?>







<!-- KPI トレンドモーダル -->
<div class="kpi-trend-overlay" id="kpiTrendOverlay">
  <div class="kpi-trend-modal">
    <div class="kpi-trend-header">
      <h3 id="kpiTrendTitle">過去12ヶ月の推移</h3>
      <button class="kpi-trend-close" id="kpiTrendClose">&times;</button>
    </div>
    <div class="kpi-trend-body">
      <div class="kpi-trend-loading" id="kpiTrendLoading">データ取得中...</div>
      <div class="kpi-trend-chart-wrap">
        <canvas id="kpiTrendChart"></canvas>
      </div>
    </div>
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
    // --- キャッシュミス: REST API で非同期取得（ページは即座に表示済み） ---
    (function(){
        var restBase = <?php echo wp_json_encode(esc_url_raw(rest_url('gcrev/v1/'))); ?>;
        var nonce    = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;

        // KPI値にローディング表示
        document.querySelectorAll('.info-kpi-value').forEach(function(el){
            el.style.opacity = '0.3';
            el.style.transition = 'opacity 0.3s';
        });

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
            var curr = results[0].success ? results[0].data : null;
            var prev = results[1].success ? results[1].data : null;
            if(!curr) return;

            var cS = parseInt(String(curr.sessions || 0).replace(/,/g, ''), 10);
            var pS = prev ? parseInt(String(prev.sessions || 0).replace(/,/g, ''), 10) : 0;
            updateInfoKpi('visits', cS, cS - pS);

            var cC = parseInt(String(curr.conversions || 0).replace(/,/g, ''), 10);
            var pC = prev ? parseInt(String(prev.conversions || 0).replace(/,/g, ''), 10) : 0;
            updateInfoKpi('cv', cC, cC - pC);
        }).catch(function(err){
            console.error('[GCREV] KPI async fetch error:', err);
        }).finally(function(){
            document.querySelectorAll('.info-kpi-value').forEach(function(el){
                el.style.opacity = '1';
            });
        });
    })();
    <?php endif; ?>
})();

// --- KPI トレンドモーダル ---
(function(){
    var restBase = '<?php echo esc_url(rest_url('gcrev/v1/')); ?>';
    var nonce    = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
    var kpiTrendChart = null;

    // KPIトレンドデータをバックグラウンドで先読み（クリック時に即表示するため）
    var _trendCache = {};
    setTimeout(function(){
        ['sessions', 'cv', 'meo'].forEach(function(m){
            fetch(restBase + 'dashboard/trends?metric=' + encodeURIComponent(m), {
                headers: { 'X-WP-Nonce': nonce },
                credentials: 'same-origin'
            })
            .then(function(res){ return res.json(); })
            .then(function(json){ _trendCache[m] = json; })
            .catch(function(){});
        });
    }, 1500);

    // KPIカードクリック
    document.querySelectorAll('.info-kpi-item[data-metric]').forEach(function(card){
        card.addEventListener('click', function(){
            var metric = card.dataset.metric;
            var label  = card.querySelector('.info-kpi-label').textContent.trim();
            openKpiTrend(metric, label);
        });
    });

    // トレンドチャート描画（共通）
    function renderTrendChart(json, label, chartWrap, loading){
        loading.classList.remove('active');
        chartWrap.style.display = 'block';
        if(kpiTrendChart){ kpiTrendChart.destroy(); kpiTrendChart = null; }
        if(!json.success || !json.values){
            chartWrap.innerHTML = '<p style="text-align:center;color:#888;padding:40px 0;">データを取得できませんでした</p>';
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
                    tension: 0.3,
                    fill: true,
                    backgroundColor: 'rgba(59,130,246,0.08)',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function(ctx){
                                return json.labels[ctx[0].dataIndex];
                            },
                            label: function(ctx){
                                return label + ': ' + ctx.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v){ return v.toLocaleString(); }
                        }
                    }
                }
            }
        });
    }

    function openKpiTrend(metric, label){
        var overlay = document.getElementById('kpiTrendOverlay');
        var loading = document.getElementById('kpiTrendLoading');
        var chartWrap = overlay.querySelector('.kpi-trend-chart-wrap');

        document.getElementById('kpiTrendTitle').textContent = label + ' — 過去12ヶ月の推移';
        overlay.classList.add('active');
        loading.classList.add('active');
        chartWrap.style.display = 'none';

        // バックグラウンド先読みキャッシュがあれば即表示
        if (_trendCache[metric]) {
            renderTrendChart(_trendCache[metric], label, chartWrap, loading);
            return;
        }

        // キャッシュなし → API取得
        fetch(restBase + 'dashboard/trends?metric=' + encodeURIComponent(metric), {
            headers: { 'X-WP-Nonce': nonce },
            credentials: 'same-origin'
        })
        .then(function(res){ return res.json(); })
        .then(function(json){
            _trendCache[metric] = json;
            renderTrendChart(json, label, chartWrap, loading);
        })
        .catch(function(){
            loading.classList.remove('active');
            chartWrap.style.display = 'block';
            chartWrap.innerHTML = '<p style="text-align:center;color:#888;padding:40px 0;">データを取得できませんでした</p>';
        });
    }

    // 閉じる
    document.getElementById('kpiTrendClose').addEventListener('click', closeKpiTrend);
    document.getElementById('kpiTrendOverlay').addEventListener('click', function(e){
        if(e.target === e.currentTarget) closeKpiTrend();
    });
    document.addEventListener('keydown', function(e){
        if(e.key === 'Escape' && document.getElementById('kpiTrendOverlay').classList.contains('active')){
            closeKpiTrend();
        }
    });

    function closeKpiTrend(){
        document.getElementById('kpiTrendOverlay').classList.remove('active');
        if(kpiTrendChart){ kpiTrendChart.destroy(); kpiTrendChart = null; }
    }
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
</script>

<?php get_footer(); ?>
