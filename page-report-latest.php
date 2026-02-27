<?php
/*
Template Name: 最新月次レポート
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// 出力モード判定（初心者向けモードかどうか）
$report_output_mode = get_user_meta($user_id, 'report_output_mode', true) ?: 'normal';
$is_easy_mode = ($report_output_mode === 'easy');

// ページタイトル設定（★ダッシュボードではなくレポート用に修正）
set_query_var('gcrev_page_title', '最新月次レポート');
set_query_var('gcrev_page_subtitle', '今月のアクセス状況や反応を、わかりやすくまとめています。');

// パンくず設定（★参照HTMLに合わせてレポート用に修正）
$breadcrumb = '<a href="' . esc_url(home_url('/mypage/dashboard/')) . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<span>AIレポート</span>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<span>最新月次レポート</span>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

// ========================================
// 日付計算（page-dashboard.php と同一ロジック）
// ========================================
$tz = wp_timezone();
$prev_month_start = new DateTimeImmutable('first day of last month', $tz);
$prev_month_end   = new DateTimeImmutable('last day of last month', $tz);

$prev_prev_month_start = new DateTimeImmutable('first day of 2 months ago', $tz);
$prev_prev_month_end   = new DateTimeImmutable('last day of 2 months ago', $tz);

// 月次AIレポート取得（page-dashboard.php と同一）
$year  = (int)$prev_month_start->format('Y');
$month = (int)$prev_month_start->format('n');

$gcrev_api      = new Gcrev_Insight_API(false);
$monthly_report = $gcrev_api->get_monthly_ai_report($year, $month, $user_id);

// === Effective CV（CVチャート + CV数表示用） ===
$effective_cv_json = '{}';
try {
    $prev_year_month = $prev_month_start->format('Y-m');
    $effective_cv_data = $gcrev_api->get_effective_cv_monthly($prev_year_month, $user_id);
    $effective_cv_json = wp_json_encode([
        'source'     => $effective_cv_data['source'],
        'total'      => $effective_cv_data['total'],
        'daily'      => $effective_cv_data['daily'],
        'has_actual' => ($effective_cv_data['source'] !== 'ga4'),
        'components' => $effective_cv_data['components'],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[GCREV] page-report-latest effective CV error: ' . $e->getMessage());
}

// ========================================
// ヘルパー関数（page-dashboard.php と同一）
// ========================================

/**
 * テキスト強調関数
 */
if (!function_exists('enhance_report_text')) {
function enhance_report_text($text, $color_mode = 'default', $auto_head_bold = true) {
    if ($text === null || $text === '') return '';

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

    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = str_replace('**', '', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    $color = match($color_mode) {
        'white'  => '#ffffff',
        'green'  => '#16a34a',
        'red'    => '#B5574B',
        'blue'   => '#3D6B6E',
        'orange' => '#ea580c',
        default  => '#111827'
    };

    if ($auto_head_bold) {
        $text = preg_replace(
            '/^(.{2,80}?[：:])\s*/u',
            '<span class="point-head">$1</span> ',
            $text,
            1
        );
    }

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

    if ($color_mode !== 'white') {
        $keywords = [
            '増加' => '#16a34a', '改善' => '#16a34a',
            '減少' => '#B5574B', '悪化' => '#B5574B',
            '前月比' => '#3D6B6E', '前年比' => '#3D6B6E',
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

/**
 * 考察テキスト整形関数
 */
if (!function_exists('format_consideration_text')) {
function format_consideration_text($text) {
    if ($text === null || $text === '') return '';

    if (is_array($text)) $text = wp_json_encode($text, JSON_UNESCAPED_UNICODE);
    if (!is_string($text)) $text = (string)$text;

    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = str_replace('**', '', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);

    $text = preg_replace('/^\s*データから分かる事実[:：]?\s*/u', '', $text);
    $text = preg_replace('/^\s*【?データから分かる事実】?\s*/u', '', $text);

    if ($text === '') return '';

    $sentences = preg_split('/(?<=。)/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $sentences = array_map('trim', $sentences);
    $sentences = array_values(array_filter($sentences, fn($s) => $s !== ''));

    $lines = [];
    $buf = '';
    $count = 0;

    foreach ($sentences as $s) {
        $buf .= ($buf === '' ? '' : ' ') . $s;
        $count++;
        if ($count % 5 === 0) {
            $lines[] = enhance_report_text(trim($buf));
            $lines[] = '';
            $buf = '';
        }
    }

    if ($buf !== '') $lines[] = enhance_report_text(trim($buf));

    return implode('<br>', $lines);
}
}

/**
 * レポート項目を正規化して {type, title, body, action} 形式にする。
 *
 * - JSON構造化済みの配列（type/title/body/action）→ そのまま返す
 * - フラットテキスト → テキスト解析で3ブロック分割
 *
 * @param  mixed  $item           配列 or 文字列
 * @param  string $default_type   フォールバック時の type（'good' or 'issue'）
 * @return array{type: string, title: string, body: string, action: string}
 */
if (!function_exists('normalize_report_item')) {
function normalize_report_item($item, $default_type = 'issue') {
    $empty = ['type' => $default_type, 'title' => '', 'body' => '', 'action' => ''];

    // --- JSON構造化済み ---
    if (is_array($item) && isset($item['title'])) {
        return [
            'type'   => in_array($item['type'] ?? '', ['good', 'issue'], true) ? $item['type'] : $default_type,
            'title'  => trim((string)($item['title'] ?? '')),
            'body'   => trim((string)($item['body'] ?? '')),
            'action' => trim((string)($item['action'] ?? '')),
        ];
    }

    // --- フラットテキスト → パース ---
    $text = '';
    if (is_array($item)) {
        if (isset($item['description']) && is_string($item['description'])) {
            $text = $item['description'];
        } else {
            $text = wp_json_encode($item, JSON_UNESCAPED_UNICODE);
        }
    } elseif (is_string($item)) {
        $text = $item;
    } else {
        return $empty;
    }

    // HTML除去（アスタリスク記法は保持）
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = preg_replace('/[^\S\n]+/u', ' ', $text);
    $text = trim($text);
    if ($text === '') return $empty;

    $title = '';
    $body  = '';
    $action = '';

    // 対策ブロック抽出（アスタリスク除去前）
    $rest = $text;
    if (preg_match('/^(.+?)\s*\*{0,2}対策[：:]\*{0,2}\s*(.+)$/us', $rest, $m)) {
        $rest   = trim($m[1]);
        $action = trim($m[2]);
    }

    // 見出し抽出 優先①: **太字**
    $heading_found = false;
    if (preg_match('/\*\*(.+?)\*\*/u', $rest, $m)) {
        $title = trim($m[1]);
        $remainder = trim(preg_replace('/\*\*' . preg_quote($m[1], '/') . '\*\*/u', '', $rest, 1));
        $body = trim(preg_replace('/\*{1,2}([^*]+)\*{1,2}/u', '$1', $remainder));
        $heading_found = true;
    }

    // 見出し抽出 優先②: *強調*
    if (!$heading_found && preg_match('/(?<!\*)\*([^*]+)\*(?!\*)/u', $rest, $m)) {
        $title = trim($m[1]);
        $remainder = trim(preg_replace('/(?<!\*)\*' . preg_quote($m[1], '/') . '\*(?!\*)/u', '', $rest, 1));
        $body = trim(preg_replace('/\*{1,2}([^*]+)\*{1,2}/u', '$1', $remainder));
        $heading_found = true;
    }

    // 見出し抽出 優先③: 句読点・改行フォールバック
    if (!$heading_found) {
        $rest = preg_replace('/\*{1,2}([^*]+)\*{1,2}/u', '$1', $rest);
        $rest = trim($rest);

        if (strpos($rest, "\n") !== false) {
            $parts = preg_split('/\n+/u', $rest, 2);
            $title = trim($parts[0]);
            $body  = isset($parts[1]) ? trim($parts[1]) : '';
        } elseif (preg_match('/^(.{2,80}?[！!。])(.+)$/us', $rest, $m)) {
            $title = trim($m[1]);
            $body  = trim($m[2]);
        } elseif (preg_match('/^(.{2,80}?[：:])\s*(.+)$/us', $rest, $m)) {
            $title = trim($m[1]);
            $body  = trim($m[2]);
        } else {
            $title = $rest;
        }
    }

    // 残留アスタリスク除去
    $title  = str_replace(['**', '*'], '', $title);
    $body   = str_replace(['**', '*'], '', $body);
    $action = str_replace(['**', '*'], '', $action);

    return [
        'type'   => $default_type,
        'title'  => trim($title),
        'body'   => trim($body),
        'action' => trim($action),
    ];
}
}

// レポートメタ情報の整形
$report_created_at = '';
$report_state      = '';
$site_url          = '';

if ($monthly_report) {
    // 生成日時
    if (!empty($monthly_report['created_at'])) {
        try {
            $dt = new DateTimeImmutable($monthly_report['created_at'], $tz);
            $report_created_at = $dt->format('Y年n月j日 H:i');
        } catch (Exception $e) {
            $report_created_at = esc_html($monthly_report['created_at']);
        }
    }
    // ステータス
    $raw_state = $monthly_report['state'] ?? '';
    if ($raw_state === 'finalized' || $raw_state === 'completed' || !empty($monthly_report['summary'])) {
        $report_state = '✅ 生成完了';
        $report_state_class = 'status-complete';
    } else {
        $report_state = '⏳ ' . ($raw_state ?: '処理中');
        $report_state_class = '';
    }
    // サイトURL
    $site_url = home_url('/');
}

get_header();
?>

<!-- Chart.js（dashboard と同一） -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- ★ 参照HTML準拠のページ固有スタイル -->
<style>
/* page-report-latest — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */
</style>

<!-- コンテンツエリア -->
<div class="content-area">
    <!-- ローディングオーバーレイ -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>データを取得中...</p>
        </div>
    </div>

    <!-- 2) period-info（参照HTML準拠 / dashboard同一ロジック） -->
    <div class="period-info">
        <div class="period-item">
            <span class="period-label-v2">📅 分析対象期間：</span>
            <span class="period-value"><?php echo esc_html($prev_month_start->format('Y年n月')); ?>（<?php echo esc_html($prev_month_start->format('n/1')); ?> - <?php echo esc_html($prev_month_end->format('n/t')); ?>）</span>
        </div>
        <div class="period-divider"></div>
        <div class="period-item">
            <span class="period-label-v2">📊 比較期間：</span>
            <span class="period-value"><?php echo esc_html($prev_prev_month_start->format('Y年n月')); ?>（<?php echo esc_html($prev_prev_month_start->format('n/1')); ?> - <?php echo esc_html($prev_prev_month_end->format('n/t')); ?>）</span>
        </div>
    </div>

    <!-- 3) KPIサマリーカード -->
    <div class="kpi-grid" id="kpiGrid">
        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">ページビュー</span>
                <div class="kpi-icon" style="background: rgba(61,107,110,0.08);">👁️</div>
            </div>
            <div class="kpi-value" id="kpi-pageviews">-</div>
            <div class="kpi-change" id="kpi-pageviews-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-pageviews"></canvas></div>
        </div>

        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">セッション</span>
                <div class="kpi-icon" style="background: rgba(212,168,66,0.12);">🎯</div>
            </div>
            <div class="kpi-value" id="kpi-sessions">-</div>
            <div class="kpi-change" id="kpi-sessions-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-sessions"></canvas></div>
        </div>

        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">ユーザー</span>
                <div class="kpi-icon" style="background: rgba(61,139,110,0.1);">👥</div>
            </div>
            <div class="kpi-value" id="kpi-users">-</div>
            <div class="kpi-change" id="kpi-users-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-users"></canvas></div>
        </div>

        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">新規ユーザー</span>
                <div class="kpi-icon" style="background: rgba(78,130,133,0.1);">✨</div>
            </div>
            <div class="kpi-value" id="kpi-newusers">-</div>
            <div class="kpi-change" id="kpi-newusers-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-newusers"></canvas></div>
        </div>

        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">リピーター</span>
                <div class="kpi-icon" style="background: rgba(181,87,75,0.08);">🔁</div>
            </div>
            <div class="kpi-value" id="kpi-returning">-</div>
            <div class="kpi-change" id="kpi-returning-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-returning"></canvas></div>
        </div>

        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">平均滞在時間</span>
                <div class="kpi-icon" style="background: rgba(212,168,66,0.15);">⏱️</div>
            </div>
            <div class="kpi-value" id="kpi-duration">-</div>
            <div class="kpi-change" id="kpi-duration-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-duration"></canvas></div>
        </div>

        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-title">CV数<span id="kpi-cv-source-label" style="font-size:10px;color:#666666;margin-left:4px;display:none;"></span></span>
                <div class="kpi-icon" style="background: rgba(61,139,110,0.1);">🎉</div>
            </div>
            <div class="kpi-value" id="kpi-conversions">-</div>
            <div class="kpi-change" id="kpi-conversions-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-conversions"></canvas></div>
        </div>
    </div><!-- .kpi-grid -->

    <?php if ($monthly_report): ?>

    <!-- 4) report-content：総評セクション（KPIカード直下に配置） -->
    <div class="report-content">

        <!-- 📋 総評 -->
        <div class="report-section">
            <h2 class="section-title">📋 <?php echo esc_html($year . '年' . $month . '月'); ?>の総評</h2>
            <div class="section-content">
                <?php if (!empty($monthly_report['summary'])): ?>
                <div class="highlight-box">
                    <h4>🎯 今月の総合評価</h4>
                    <p><?php echo enhance_report_text($monthly_report['summary']); ?></p>
                </div>
                <?php else: ?>
                <p>今月のレポートサマリーを生成中です...</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ✅ 良かった点（成果） -->
        <div class="report-section">
            <h2 class="section-title">✅ 良かった点（成果）</h2>
            <div class="section-content<?php echo $is_easy_mode ? '' : ' list-box'; ?>">
                <?php if (!empty($monthly_report['good_points']) && is_array($monthly_report['good_points'])): ?>
                    <?php if ($is_easy_mode): ?>
                        <?php foreach ($monthly_report['good_points'] as $point):
                            $ni = normalize_report_item($point, 'good');
                            if ($ni['title'] === '') continue;
                        ?>
                        <article class="beginner-report-item beginner-report-item--good">
                            <h4 class="beginner-report-title"><span class="beginner-report-title-text"><?php echo wp_kses_post(enhance_report_text($ni['title'], 'green', false)); ?></span></h4>
                            <?php if ($ni['body'] !== ''): ?>
                            <div class="beginner-report-desc">
                                <p><?php echo wp_kses_post(enhance_report_text($ni['body'], 'green', false)); ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if ($ni['action'] !== ''): ?>
                            <div class="beginner-report-action">
                                <div class="beginner-report-action__label">💡 対策</div>
                                <div class="beginner-report-action__body">
                                    <p><?php echo wp_kses_post(enhance_report_text($ni['action'], 'green', false)); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($monthly_report['good_points'] as $point): ?>
                            <li><?php echo enhance_report_text(is_array($point) ? ($point['title'] ?? '') : $point, 'green'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php else: ?>
                <p>データなし</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ⚠️ 改善が必要な点（課題） -->
        <div class="report-section">
            <h2 class="section-title">⚠️ 改善が必要な点（課題）</h2>
            <div class="section-content<?php echo $is_easy_mode ? '' : ' list-box'; ?>">
                <?php if (!empty($monthly_report['improvement_points']) && is_array($monthly_report['improvement_points'])): ?>
                    <?php if ($is_easy_mode): ?>
                        <?php foreach ($monthly_report['improvement_points'] as $point):
                            $ni = normalize_report_item($point, 'issue');
                            if ($ni['title'] === '') continue;
                        ?>
                        <article class="beginner-report-item beginner-report-item--issue">
                            <h4 class="beginner-report-title"><span class="beginner-report-title-text"><?php echo wp_kses_post(enhance_report_text($ni['title'], 'red', false)); ?></span></h4>
                            <?php if ($ni['body'] !== ''): ?>
                            <div class="beginner-report-desc">
                                <p><?php echo wp_kses_post(enhance_report_text($ni['body'], 'red', false)); ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if ($ni['action'] !== ''): ?>
                            <div class="beginner-report-action">
                                <div class="beginner-report-action__label">💡 対策</div>
                                <div class="beginner-report-action__body">
                                    <p><?php echo wp_kses_post(enhance_report_text($ni['action'], 'default', false)); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($monthly_report['improvement_points'] as $point): ?>
                            <li><?php echo enhance_report_text(is_array($point) ? ($point['title'] ?? '') : $point, 'red'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php else: ?>
                <p>データなし</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 💡 考察とインサイト -->
        <div class="report-section">
            <h2 class="section-title">💡 考察とインサイト</h2>
            <div class="section-content">
                <?php if (!empty($monthly_report['consideration'])): ?>
                <p><?php echo format_consideration_text($monthly_report['consideration']); ?></p>
                <?php else: ?>
                <p>データなし</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 🎯 ネクストアクション（優先度順） -->
        <div class="report-section">
            <h2 class="section-title">🎯 ネクストアクション（優先度順）</h2>
            <?php if (!empty($monthly_report['next_actions']) && is_array($monthly_report['next_actions'])): ?>
            <div class="next-actions">
                <?php foreach ($monthly_report['next_actions'] as $index => $action): ?>
                <?php if (!empty($action)): ?>
                <div class="action-item">
                    <?php
                    // 優先度判定（通常モード＋初心者モード両対応）
                    $priority_label = '中';
                    $priority_class = 'medium';
                    if (is_array($action) && !empty($action['priority'])) {
                        $p = $action['priority'];
                        $p_lower = mb_strtolower($p);
                        if (strpos($p_lower, '最優先') !== false || strpos($p_lower, '高') !== false || strpos($p_lower, 'high') !== false
                            || strpos($p, 'おすすめ①') !== false || strpos($p, 'いちばん大事') !== false
                            || strpos($p_lower, 'priority 1') !== false || strpos($p_lower, 'priority 2') !== false) {
                            $priority_label = '高';
                            $priority_class = 'high';
                        } elseif (strpos($p_lower, '低') !== false || strpos($p_lower, 'low') !== false
                            || strpos($p, 'おすすめ③') !== false || strpos($p, '余裕があれば') !== false) {
                            $priority_label = '低';
                            $priority_class = 'low';
                        }
                    } else {
                        // priority フィールドなし → indexで自動判定
                        if ($index < 2) {
                            $priority_label = '高';
                            $priority_class = 'high';
                        } elseif ($index >= 4) {
                            $priority_label = '低';
                            $priority_class = 'low';
                        }
                    }
                    ?>
                    <span class="action-priority <?php echo esc_attr($priority_class); ?>"><?php echo esc_html($priority_label); ?></span>
                    <div class="action-text">
                        <?php if (is_array($action)): ?>
                            <?php if (!empty($action['title'])): ?>
                                <strong><?php echo esc_html($action['title']); ?></strong><br>
                            <?php endif; ?>
                            <?php if (!empty($action['description'])): ?>
                                <?php echo enhance_report_text($action['description'], 'default', false); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php echo enhance_report_text($action); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="padding: 40px 20px; text-align: center; background: #F7F8F9; border-radius: 8px; color: #666666;">
                <p style="margin: 0; font-size: 15px;">
                    ⚠️ ネクストアクションが生成されませんでした。<br>
                    レポートを再生成してみてください。
                </p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- .report-content -->

    <!-- 5) 📊 集客分析結果（総評の下に配置 - 詳細データとして参照） -->
    <div style="margin-top: 32px; margin-bottom: 24px;">
        <h2 style="font-size: 22px; font-weight: 700; color: #2C3E40; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #E2E6EA;">📊 集客分析結果</h2>
        <div class="digest-grid">
            <!-- デバイス別アクセス -->
            <div class="digest-card">
                <div class="digest-card-header">
                    <h3 class="digest-card-title">
                        <span>📱</span>
                        <span>デバイス別アクセス</span>
                    </h3>
                    <a href="<?php echo esc_url(home_url('/mypage/analysis-device/')); ?>" class="detail-link">詳細を見る →</a>
                </div>
                <div class="digest-chart-placeholder">
                    <canvas id="deviceChart" width="400" height="100"></canvas>
                </div>
                <ul class="digest-list" id="deviceList">
                    <li class="digest-list-item">
                        <span class="digest-list-item-name">読み込み中...</span>
                        <span class="digest-list-item-value">-</span>
                    </li>
                </ul>
            </div>

            <!-- 年齢別アクセス -->
            <div class="digest-card">
                <div class="digest-card-header">
                    <h3 class="digest-card-title">
                        <span>👥</span>
                        <span>年齢別アクセス</span>
                    </h3>
                    <a href="<?php echo esc_url(home_url('/mypage/analysis-age/')); ?>" class="detail-link">詳細を見る →</a>
                </div>
                <div class="digest-chart-placeholder">
                    <canvas id="ageChart" width="400" height="100"></canvas>
                </div>
                <ul class="digest-list" id="ageList">
                    <li class="digest-list-item">
                        <span class="digest-list-item-name">読み込み中...</span>
                        <span class="digest-list-item-value">-</span>
                    </li>
                </ul>
            </div>

            <!-- 流入元 -->
            <div class="digest-card">
                <div class="digest-card-header">
                    <h3 class="digest-card-title">
                        <span>🌐</span>
                        <span>流入元</span>
                    </h3>
                    <a href="<?php echo esc_url(home_url('/mypage/analysis-source/')); ?>" class="detail-link">詳細を見る →</a>
                </div>
                <div class="digest-chart-placeholder">
                    <canvas id="mediumChart" width="400" height="100"></canvas>
                </div>
                <ul class="digest-list" id="mediumList">
                    <li class="digest-list-item">
                        <span class="digest-list-item-name">読み込み中...</span>
                        <span class="digest-list-item-value">-</span>
                    </li>
                </ul>
            </div>

            <!-- 地域別アクセス TOP5 -->
            <div class="digest-card">
                <div class="digest-card-header">
                    <h3 class="digest-card-title">
                        <span>📍</span>
                        <span>地域別アクセス TOP5</span>
                    </h3>
                    <a href="<?php echo esc_url(home_url('/mypage/analysis-region/')); ?>" class="detail-link">詳細を見る →</a>
                </div>
                <ul class="digest-list" style="margin-top: 20px;" id="regionList">
                    <li class="digest-list-item">
                        <span class="digest-list-item-name">読み込み中...</span>
                        <span class="digest-list-item-value">-</span>
                    </li>
                </ul>
            </div>

            <!-- ページランキング TOP5 -->
            <div class="digest-card">
                <div class="digest-card-header">
                    <h3 class="digest-card-title">
                        <span>📄</span>
                        <span>ページランキング TOP5</span>
                    </h3>
                    <a href="<?php echo esc_url(home_url('/mypage/analysis-pages/')); ?>" class="detail-link">詳細を見る →</a>
                </div>
                <ul class="digest-list" style="margin-top: 20px;" id="pagesList">
                    <li class="digest-list-item">
                        <span class="digest-list-item-name">読み込み中...</span>
                        <span class="digest-list-item-value">-</span>
                    </li>
                </ul>
            </div>

            <!-- キーワードランキング TOP5 -->
            <div class="digest-card">
                <div class="digest-card-header">
                    <h3 class="digest-card-title">
                        <span>🔑</span>
                        <span>キーワードランキング TOP5</span>
                    </h3>
                    <a href="<?php echo esc_url(home_url('/mypage/analysis-keywords/')); ?>" class="detail-link">詳細を見る →</a>
                </div>
                <ul class="digest-list" style="margin-top: 20px;" id="keywordsList">
                    <li class="digest-list-item">
                        <span class="digest-list-item-name">読み込み中...</span>
                        <span class="digest-list-item-value">-</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <?php else: ?>

    <!-- 7) レポート未生成時（参照HTML世界観 + dashboard同一導線） -->
    <div class="report-empty">
        <div style="font-size: 48px; margin-bottom: 20px;">📊</div>
        <h3 style="font-size: 20px; font-weight: 600; color: #333; margin-bottom: 12px;">
            <?php echo esc_html($prev_month_start->format('Y年n月')); ?>のAIレポートはまだ生成されていません
        </h3>
        <p style="color: #666; margin-bottom: 32px;">
            まずはAIレポート設定画面で、目標や重点ポイントなどの詳細を設定してください。<br>
            設定内容に基づいて、AIレポートを生成します。
        </p>

        <?php
        // 前々月データチェック（通知表示用）
        $prev2_check_rl = $gcrev_api->has_prev2_data($user_id);
        if (!$prev2_check_rl['available']):
        ?>
        <div class="gcrev-notice-prev2" style="text-align: left; max-width: 540px; margin: 0 auto 24px;">
          <span class="notice-icon">⚠️</span>
          <div class="notice-text">
            <strong>AIレポートを生成できません。</strong><br>
            <?php echo esc_html($prev2_check_rl['reason'] ?? 'GA4プロパティの設定を確認してください。'); ?>
          </div>
        </div>
        <?php elseif (!empty($prev2_check_rl['is_zero'])): ?>
        <div class="gcrev-notice-prev2" style="text-align: left; max-width: 540px; margin: 0 auto 24px; background: #EFF6FF; border-left-color: #3B82F6;">
          <span class="notice-icon">ℹ️</span>
          <div class="notice-text">
            前々月のアクセスデータがゼロのため、「ゼロからの成長」としてレポートが生成されます。
          </div>
        </div>
        <?php endif; ?>

        <button
            class="btn-report btn-primary"
            style="min-width: 240px; padding: 14px 28px; font-size: 16px;"
            onclick="window.location.href='<?php echo esc_url(home_url('/mypage/report-settings/')); ?>'"
        >
            🤖 AIレポート設定へ進む
        </button>
        <div id="report-generation-status" style="margin-top: 20px; color: #666; display: none;">
            <div class="loading-spinner" style="display: inline-block; margin-right: 8px;"></div>
            <span>レポートを生成中です...</span>
        </div>
    </div>

    <?php endif; ?>

</div><!-- .content-area -->

<script>
// =============================================
// KPI取得・表示（page-dashboard.php と同一JS/REST）
// =============================================

// Effective CV データ（PHP → JS）
const effectiveCvData = <?php echo $effective_cv_json ?? '{}'; ?>;

let sparklineCharts = {};

// KPIデータ取得（dashboard同一エンドポイント）
function updateKPIData(period) {
    showLoading();

    const apiUrl = '<?php echo rest_url("gcrev/v1/dashboard/kpi"); ?>?period=' + period;

    fetch(apiUrl, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
        },
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(result => {
        console.log('KPI Data received:', result);
        if (result.success && result.data) {
            updateKPIDisplay(result.data);
        } else {
            throw new Error(result.message || 'データ取得失敗');
        }
        hideLoading();
    })
    .catch(error => {
        console.error('Error fetching KPI data:', error);
        hideLoading();
    });
}

// KPI表示更新（dashboard同一 + CV数追加）
function updateKPIDisplay(data) {
    document.getElementById('kpi-pageviews').textContent = formatNumber(data.pageViews);
    document.getElementById('kpi-sessions').textContent = formatNumber(data.sessions);
    document.getElementById('kpi-users').textContent = formatNumber(data.users);
    document.getElementById('kpi-newusers').textContent = formatNumber(data.newUsers);
    document.getElementById('kpi-returning').textContent = formatNumber(data.returningUsers);
    document.getElementById('kpi-duration').textContent = data.avgDuration + '秒';

    // CV数（APIに存在する場合のみ更新）
    if (data.conversions !== undefined) {
        document.getElementById('kpi-conversions').textContent = formatNumber(data.conversions);
    } else {
        document.getElementById('kpi-conversions').textContent = '—';
    }

    // CV数ソースラベル表示
    const cvSourceLabel = document.getElementById('kpi-cv-source-label');
    if (cvSourceLabel) {
        if (data.cv_source === 'hybrid') {
            cvSourceLabel.textContent = '（GA4+手動）';
            cvSourceLabel.style.display = 'inline';
            cvSourceLabel.style.color = '#3D8B6E';
        } else if (data.cv_source === 'actual_plus_phone') {
            cvSourceLabel.textContent = '（実質+電話タップ）';
            cvSourceLabel.style.display = 'inline';
            cvSourceLabel.style.color = '#3D8B6E';
        } else if (data.cv_source === 'actual') {
            cvSourceLabel.textContent = '（実質）';
            cvSourceLabel.style.display = 'inline';
            cvSourceLabel.style.color = '#3D8B6E';
        } else {
            cvSourceLabel.textContent = '';
            cvSourceLabel.style.display = 'none';
        }
    }

    updateChangeIndicator('kpi-pageviews-change', data.trends.pageViews);
    updateChangeIndicator('kpi-sessions-change', data.trends.sessions);
    updateChangeIndicator('kpi-users-change', data.trends.users);
    updateChangeIndicator('kpi-newusers-change', data.trends.newUsers);
    updateChangeIndicator('kpi-returning-change', data.trends.returningUsers);
    updateChangeIndicator('kpi-duration-change', data.trends.avgDuration);

    // CV数トレンド
    if (data.trends && data.trends.conversions) {
        updateChangeIndicator('kpi-conversions-change', data.trends.conversions);
    }

    if (data.daily) {
        updateSparklines(data.daily);
    }
}

// 増減表示更新（dashboard同一）
function updateChangeIndicator(elementId, trendData) {
    const element = document.getElementById(elementId);
    if (!element || !trendData) return;

    element.innerHTML = '';
    element.className = 'kpi-change';

    if (trendData.value > 0) {
        element.classList.add('positive');
        element.innerHTML = '<span>▲</span><span>' + trendData.text + '</span>';
    } else if (trendData.value < 0) {
        element.classList.add('negative');
        element.innerHTML = '<span>▼</span><span>' + trendData.text.replace('-', '') + '</span>';
    } else {
        element.classList.add('neutral');
        element.innerHTML = '<span>→</span><span>' + trendData.text + '</span>';
    }
}

// スパークライン更新（dashboard同一）
function updateSparklines(dailyData) {
    const sparklineConfigs = [
        { id: 'sparkline-pageviews', data: dailyData.pageViews, color: '#3D6B6E' },
        { id: 'sparkline-sessions', data: dailyData.sessions, color: '#D4A842' },
        { id: 'sparkline-users', data: dailyData.users, color: '#3D8B6E' },
        { id: 'sparkline-newusers', data: dailyData.newUsers, color: '#4E8285' },
        { id: 'sparkline-returning', data: dailyData.returning, color: '#B5574B' },
        { id: 'sparkline-duration', data: dailyData.duration, color: '#f97316' }
    ];
    // CV用スパークライン: effective CV daily がある場合はそれで上書き
    if (effectiveCvData && effectiveCvData.daily && Object.keys(effectiveCvData.daily).length > 0) {
        const cvDates = Object.keys(effectiveCvData.daily).sort();
        const cvValues = cvDates.map(d => effectiveCvData.daily[d]);
        const cvLabels = cvDates.map(d => {
            const parts = d.split('-');
            return parseInt(parts[1]) + '/' + parseInt(parts[2]);
        });
        sparklineConfigs.push({
            id: 'sparkline-conversions',
            data: { labels: cvLabels, values: cvValues },
            color: '#3D8B6E'
        });
    } else if (dailyData.conversions) {
        sparklineConfigs.push({ id: 'sparkline-conversions', data: dailyData.conversions, color: '#3D8B6E' });
    }

    sparklineConfigs.forEach(config => {
        createSparkline(config.id, config.data, config.color);
    });
}

// スパークライン生成（dashboard同一）
function createSparkline(canvasId, data, color) {
    if (typeof Chart === 'undefined') return;
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    if (sparklineCharts[canvasId]) sparklineCharts[canvasId].destroy();
    if (!data || !data.values || data.values.length === 0) return;
    try {
        sparklineCharts[canvasId] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values,
                    borderColor: color,
                    backgroundColor: color + '33',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointBackgroundColor: color,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            title: ctx => ctx[0].label,
                            label: ctx => formatNumber(ctx.parsed.y)
                        }
                    }
                },
                scales: {
                    x: { display: false },
                    y: { display: false, beginAtZero: false }
                }
            }
        });
    } catch (error) {
        console.error('Error creating sparkline:', canvasId, error);
    }
}

// 数値フォーマット（dashboard同一）
function formatNumber(num) {
    if (typeof num === 'string') return num;
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// ローディング表示/非表示（dashboard同一）
function showLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.add('active');
}
function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.remove('active');
}

// =============================================
// 集客分析データ取得（page-analysis準拠）
// =============================================
let charts = {};

function loadAnalysisData() {
    const apiUrl = '<?php echo rest_url("gcrev/v1/dashboard/kpi"); ?>?period=prev-month';

    fetch(apiUrl, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
        },
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(result => {
        if (!result?.success || !result?.data) return;
        const data = result.data;

        updateDeviceList(data.devices || []);
        updateAgeList(data.age || []);
        updateMediumList(data.medium || []);
        updateRegionList(data.geo_region || []);
        updatePagesList(data.pages || []);
        updateKeywordsList(data.keywords || []);
    })
    .catch(err => {
        console.error('集客分析データ取得エラー:', err);
    });
}

// ----- デバイス別リスト更新 -----
function updateDeviceList(devices) {
    const listEl = document.getElementById('deviceList');
    if (!listEl) return;

    if (!devices || devices.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }

    const total = devices.reduce((sum, item) => {
        const count = typeof item.count === 'string' ? parseInt(item.count.replace(/,/g, '')) : (item.count || 0);
        return sum + count;
    }, 0);

    listEl.innerHTML = devices.slice(0, 3).map(item => {
        const name = getDeviceName(item.device || 'unknown');
        const count = typeof item.count === 'string' ? parseInt(item.count.replace(/,/g, '')) : (item.count || 0);
        const pct = calculatePercentage(count, total);
        return '<li class="digest-list-item"><span class="digest-list-item-name">' + escapeHtml(name) + '</span><span class="digest-list-item-value">' + pct + '%</span></li>';
    }).join('');

    createDeviceChart(devices);
}

// ----- 年齢別リスト更新 -----
function updateAgeList(ageData) {
    const listEl = document.getElementById('ageList');
    if (!listEl) return;

    if (!ageData || ageData.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }

    const total = ageData.reduce((sum, item) => {
        const s = typeof item.sessions === 'string' ? parseInt(item.sessions.replace(/,/g, '')) : (item.sessions || 0);
        return sum + s;
    }, 0);

    listEl.innerHTML = ageData.slice(0, 3).map(item => {
        const name = item.name || 'unknown';
        const s = typeof item.sessions === 'string' ? parseInt(item.sessions.replace(/,/g, '')) : (item.sessions || 0);
        const pct = calculatePercentage(s, total);
        return '<li class="digest-list-item"><span class="digest-list-item-name">' + escapeHtml(name) + '</span><span class="digest-list-item-value">' + pct + '%</span></li>';
    }).join('');

    createAgeChart(ageData);
}

// ----- 流入元リスト更新 -----
function updateMediumList(medium) {
    const listEl = document.getElementById('mediumList');
    if (!listEl) return;

    if (!medium || medium.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }

    const total = medium.reduce((sum, item) => {
        const s = typeof item.sessions === 'string' ? parseInt(item.sessions.replace(/,/g, '')) : (item.sessions || 0);
        return sum + s;
    }, 0);

    listEl.innerHTML = medium.slice(0, 3).map(item => {
        const name = getMediumName(item.medium || 'unknown');
        const s = typeof item.sessions === 'string' ? parseInt(item.sessions.replace(/,/g, '')) : (item.sessions || 0);
        const pct = calculatePercentage(s, total);
        return '<li class="digest-list-item"><span class="digest-list-item-name">' + escapeHtml(name) + '</span><span class="digest-list-item-value">' + pct + '%</span></li>';
    }).join('');

    createMediumChart(medium);
}

// ----- 地域別リスト更新 -----
function updateRegionList(regions) {
    const listEl = document.getElementById('regionList');
    if (!listEl) return;

    if (!regions || regions.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }

    listEl.innerHTML = regions.slice(0, 5).map((item, i) => {
        const name = convertRegionNameToJapanese(item.name || item.region || 'unknown');
        const val = typeof item.sessions === 'string' ? parseInt(item.sessions.replace(/,/g, '')) : (item.sessions || 0);
        return '<li class="digest-list-item"><span class="digest-list-item-name">' + (i+1) + '. ' + escapeHtml(name) + '</span><span class="digest-list-item-value">' + formatNumber(val) + '</span></li>';
    }).join('');
}

// ----- ページランキング更新 -----
function updatePagesList(pages) {
    const listEl = document.getElementById('pagesList');
    if (!listEl) return;

    if (!pages || pages.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }

    listEl.innerHTML = pages.slice(0, 5).map((item, i) => {
        let name = item.title || '';
        if (!name || name.trim() === '') {
            name = formatPagePath(item.pagePath || item.page || '');
        }
        const val = typeof item.pageViews === 'string' ? parseInt(item.pageViews.replace(/,/g, '')) : (item.pageViews || item.screenPageViews || 0);
        return '<li class="digest-list-item"><span class="digest-list-item-name" title="' + escapeHtml(name) + '">' + (i+1) + '. ' + escapeHtml(name) + '</span><span class="digest-list-item-value">' + formatNumber(val) + '</span></li>';
    }).join('');
}

function formatPagePath(path) {
    if (!path || path === '/') return 'トップページ';
    try { path = decodeURIComponent(path); } catch(e) {}
    const segs = path.split('/').filter(s => s.length > 0);
    if (segs.length === 0) return 'トップページ';
    let last = segs[segs.length - 1].replace(/\.(html|php|htm)$/i, '').split('?')[0].split('#')[0].replace(/[-_]/g, ' ');
    if (last.length > 0) last = last.charAt(0).toUpperCase() + last.slice(1);
    if (last.length > 30) last = last.substring(0, 27) + '...';
    return last || path;
}

// ----- キーワードランキング更新 -----
function updateKeywordsList(keywords) {
    const listEl = document.getElementById('keywordsList');
    if (!listEl) return;

    if (!keywords || keywords.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }

    listEl.innerHTML = keywords.slice(0, 5).map((item, i) => {
        const name = item.query || item.keyword || 'unknown';
        const val = typeof item.clicks === 'string' ? parseInt(item.clicks.replace(/,/g, '')) : (item.clicks || 0);
        return '<li class="digest-list-item"><span class="digest-list-item-name">' + (i+1) + '. ' + escapeHtml(name) + '</span><span class="digest-list-item-value">' + formatNumber(val) + '</span></li>';
    }).join('');
}

// ===== チャート生成（page-analysis準拠） =====

function createDeviceChart(devices) {
    const ctx = document.getElementById('deviceChart');
    if (!ctx) return;
    if (charts.device) charts.device.destroy();
    if (!devices || devices.length === 0) return;

    const labels = [], data = [];
    const colors = ['#3D6B6E', '#3D8B6E', '#D4A842', '#B5574B', '#8b5cf6'];
    devices.slice(0, 5).forEach(item => {
        labels.push(getDeviceName(item.device || 'unknown'));
        const c = typeof item.count === 'string' ? parseInt(item.count.replace(/,/g, '')) : (item.count || 0);
        data.push(c);
    });

    charts.device = new Chart(ctx, {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '60%',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.label + ': ' + formatNumber(c.parsed) } } }
        }
    });
}

function createAgeChart(ageData) {
    const ctx = document.getElementById('ageChart');
    if (!ctx) return;
    if (charts.age) charts.age.destroy();
    if (!ageData || ageData.length === 0) return;

    const labels = [], data = [];
    ageData.slice(0, 5).forEach(item => {
        labels.push(item.name || 'unknown');
        const s = typeof item.sessions === 'string' ? parseInt(item.sessions.replace(/,/g, '')) : (item.sessions || 0);
        data.push(s);
    });

    charts.age = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets: [{ data, backgroundColor: '#3D8B6E', borderRadius: 4 }] },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => formatNumber(c.parsed.x) + ' sessions' } } },
            scales: { x: { display: false, beginAtZero: true }, y: { display: true, ticks: { font: { size: 10 } } } }
        }
    });
}

function createMediumChart(medium) {
    const ctx = document.getElementById('mediumChart');
    if (!ctx) return;
    if (charts.medium) charts.medium.destroy();
    if (!medium || medium.length === 0) return;

    const labels = [], data = [];
    medium.slice(0, 5).forEach(item => {
        labels.push(getMediumName(item.medium || 'unknown'));
        const s = typeof item.sessions === 'string' ? parseInt(item.sessions.replace(/,/g, '')) : (item.sessions || 0);
        data.push(s);
    });

    charts.medium = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets: [{ data, backgroundColor: '#3D6B6E', borderRadius: 4 }] },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => formatNumber(c.parsed.x) + ' sessions' } } },
            scales: { x: { display: false, beginAtZero: true }, y: { display: true, ticks: { font: { size: 10 } } } }
        }
    });
}

// ===== ユーティリティ（page-analysis準拠） =====
function getDeviceName(device) {
    const map = { 'mobile': 'モバイル', 'desktop': 'デスクトップ', 'tablet': 'タブレット' };
    return map[device] || device;
}
function getMediumName(medium) {
    const map = { 'organic': '自然検索', 'direct': '直接', '(none)': '直接', 'referral': '参照元', 'cpc': '有料広告', 'social': 'ソーシャル', 'email': 'メール' };
    return map[medium] || medium;
}
function calculatePercentage(value, total) {
    if (!total || total === 0) return '0.0';
    return ((value / total) * 100).toFixed(1);
}
function convertRegionNameToJapanese(regionName) {
    const m = {
        'Hokkaido':'北海道','Aomori':'青森県','Iwate':'岩手県','Miyagi':'宮城県','Akita':'秋田県','Yamagata':'山形県','Fukushima':'福島県',
        'Ibaraki':'茨城県','Tochigi':'栃木県','Gunma':'群馬県','Saitama':'埼玉県','Chiba':'千葉県','Tokyo':'東京都','Kanagawa':'神奈川県',
        'Niigata':'新潟県','Toyama':'富山県','Ishikawa':'石川県','Fukui':'福井県','Yamanashi':'山梨県','Nagano':'長野県',
        'Gifu':'岐阜県','Shizuoka':'静岡県','Aichi':'愛知県','Mie':'三重県','Shiga':'滋賀県','Kyoto':'京都府','Osaka':'大阪府',
        'Hyogo':'兵庫県','Nara':'奈良県','Wakayama':'和歌山県','Tottori':'鳥取県','Shimane':'島根県','Okayama':'岡山県',
        'Hiroshima':'広島県','Yamaguchi':'山口県','Tokushima':'徳島県','Kagawa':'香川県','Ehime':'愛媛県','Kochi':'高知県',
        'Fukuoka':'福岡県','Saga':'佐賀県','Nagasaki':'長崎県','Kumamoto':'熊本県','Oita':'大分県','Miyazaki':'宮崎県',
        'Kagoshima':'鹿児島県','Okinawa':'沖縄県'
    };
    return m[regionName] || regionName;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// =============================================
// 月次AIレポート生成機能（dashboard同一）
// =============================================
async function generateMonthlyReport() {
    const btn = document.getElementById('btn-generate-report');
    const statusDiv = document.getElementById('report-generation-status');
    if (!btn || !statusDiv) return;

    showLoading();
    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.style.cursor = 'not-allowed';

    const wpNonce = '<?php echo wp_create_nonce("wp_rest"); ?>';

    try {
        // Step 0: GA4プロパティ設定チェック
        const checkUrl = '<?php echo rest_url("gcrev/v1/report/check-prev2-data"); ?>';
        const checkRes = await fetch(checkUrl, {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        });
        if (checkRes.ok) {
            const checkJson = await checkRes.json();
            if (checkJson.code === 'NO_PREV2_DATA') {
                hideLoading();
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                alert('⚠️ ' + (checkJson.reason || 'GA4プロパティの設定を確認してください。'));
                return;
            }
        }

        statusDiv.style.display = 'block';

        const apiUrl = '<?php echo rest_url("gcrev/v1/report/generate-manual"); ?>';
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpNonce
            },
            credentials: 'same-origin',
            body: JSON.stringify({})
        });

        const result = await response.json();

        if (result.success) {
            alert('✅ レポートの生成が完了しました！');
            location.reload();
        } else {
            if (result.code === 'NO_PREV2_DATA') {
                throw new Error(result.message || 'GA4プロパティの設定を確認してください。');
            }
            throw new Error(result.message || 'レポート生成に失敗しました');
        }
    } catch (error) {
        hideLoading();
        alert('❌ エラー: ' + error.message);
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        statusDiv.style.display = 'none';
    }
}

// =============================================
// ページ初期化
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Report Latest page initialized');
    if (typeof Chart === 'undefined') {
        console.error('Chart.js not loaded');
        return;
    }
    // KPIデータ取得（前月固定 = dashboard初期表示と同じ）
    updateKPIData('prev-month');

    // 集客分析データ取得（常に表示）
    loadAnalysisData();

    // レポート生成ボタン（未生成時のみ存在）
    const btnGenerateReport = document.getElementById('btn-generate-report');
    if (btnGenerateReport) {
        btnGenerateReport.addEventListener('click', generateMonthlyReport);
    }
});
</script>

<?php get_footer(); ?>
