<?php
/*
Template Name: 最新月次レポート
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}


$current_user = mimamori_get_view_user_object();
$user_id = mimamori_get_view_user_id();

// サービスティア判定
$can_ai              = mimamori_can( 'ai_chat', $user_id );
$can_good_points     = mimamori_can( 'report_good_points', $user_id );
$can_improvements    = mimamori_can( 'report_improvements', $user_id );
$can_consideration   = mimamori_can( 'report_consideration', $user_id );
$can_next_actions    = mimamori_can( 'report_next_actions', $user_id );

// 出力モード判定（初心者向けモードかどうか）
$report_output_mode = get_user_meta($user_id, 'report_output_mode', true) ?: 'normal';
$is_easy_mode = ($report_output_mode === 'easy');

// ========================================
// 日付計算（ymパラメータ対応）
// ========================================
$tz = wp_timezone();
$is_archive_view = false; // 過去月表示かどうか

// ?ym=YYYY-MM パラメータで過去月のレポートを表示可能
// ただし当月以降は指定不可（月次レポートは確定済みの前月以前のみ）
$ym_param = isset($_GET['ym']) ? sanitize_text_field($_GET['ym']) : '';
$max_allowed_ym = (new DateTimeImmutable('first day of last month', $tz))->format('Y-m');
if ($ym_param && preg_match('/^\d{4}-\d{2}$/', $ym_param) && $ym_param <= $max_allowed_ym) {
    // 指定年月のレポートを表示
    $prev_month_start = new DateTimeImmutable($ym_param . '-01', $tz);
    $prev_month_end   = new DateTimeImmutable($prev_month_start->format('Y-m-t'), $tz);

    // 比較期間: 指定月の前月
    $prev_prev_month_start = $prev_month_start->modify('first day of last month');
    $prev_prev_month_end   = $prev_month_start->modify('last day of last month');

    // 最新月（先月）と比較して、過去月表示かどうか判定
    $latest_month = new DateTimeImmutable('first day of last month', $tz);
    if ($prev_month_start->format('Y-m') !== $latest_month->format('Y-m')) {
        $is_archive_view = true;
    }
} else {
    // デフォルト: 前月（既存動作）
    $prev_month_start = new DateTimeImmutable('first day of last month', $tz);
    $prev_month_end   = new DateTimeImmutable('last day of last month', $tz);

    $prev_prev_month_start = new DateTimeImmutable('first day of 2 months ago', $tz);
    $prev_prev_month_end   = new DateTimeImmutable('last day of 2 months ago', $tz);
}

// ========================================
// 全レポート年月リスト取得（年月切り替えUI用）
// ========================================
global $wpdb;
$all_report_yms = $wpdb->get_col( $wpdb->prepare(
    "SELECT DISTINCT ym.meta_value
     FROM {$wpdb->postmeta} ym
     INNER JOIN {$wpdb->posts} p
         ON p.ID = ym.post_id AND p.post_type = 'gcrev_report' AND p.post_status = 'publish'
     INNER JOIN {$wpdb->postmeta} uid
         ON uid.post_id = ym.post_id AND uid.meta_key = '_gcrev_user_id' AND uid.meta_value = %s
     INNER JOIN {$wpdb->postmeta} cur
         ON cur.post_id = ym.post_id AND cur.meta_key = '_gcrev_is_current' AND cur.meta_value = '1'
     WHERE ym.meta_key = '_gcrev_year_month'
     ORDER BY ym.meta_value DESC",
    $user_id
) );

// 年別にグループ化（当月以降のレポートは除外）
$report_years = [];
foreach ( $all_report_yms as $ym_v ) {
    if ( $ym_v > $max_allowed_ym ) continue; // 当月以降は表示しない
    $y = (int) substr( $ym_v, 0, 4 );
    $m = (int) substr( $ym_v, 5, 2 );
    $report_years[ $y ][] = $m;
}
// 年を降順、月を昇順にソート
krsort( $report_years );
foreach ( $report_years as &$months ) {
    sort( $months );
}
unset( $months );

// 現在選択中の年月
$current_ym = $prev_month_start->format('Y-m');
$current_year  = (int) $prev_month_start->format('Y');
$current_month = (int) $prev_month_start->format('n');

// ページタイトル・パンくず設定
$display_ym = $prev_month_start->format('Y年n月');
set_query_var('gcrev_page_title', '月次レポート');
set_query_var('gcrev_page_subtitle', $display_ym . 'のアクセス状況や反応をまとめたレポートです。');
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('月次レポート', 'レポート'));

// 月次AIレポート取得
$year  = (int)$prev_month_start->format('Y');
$month = (int)$prev_month_start->format('n');

$gcrev_api      = new Gcrev_Insight_API(false);
$monthly_report = $gcrev_api->get_monthly_ai_report($year, $month, $user_id);

// レポート対象期間の日付（JS に渡す）
$report_start_date = $prev_month_start->format('Y-m-d');
$report_end_date   = $prev_month_end->format('Y-m-d');

// === KPIスナップショット ===
// 保存済みスナップショットを読み込み。
// 未保存の場合のみ一度だけGA4 APIから取得して固定保存（移行用）。
// 保存済みスナップショットは上書きしない（確定帳票として不変）。
$kpi_snapshot_json  = 'null';
$snapshot_data      = null;
$has_full_snapshot  = false;
$report_post_id     = ( $monthly_report && ! empty( $monthly_report['id'] ) ) ? (int) $monthly_report['id'] : 0;

if ( $report_post_id > 0 ) {
    // 1. 保存済みスナップショットを読み込み
    $snapshot_raw = get_post_meta( $report_post_id, '_gcrev_kpi_snapshot_json', true );
    if ( $snapshot_raw ) {
        $snapshot_data = json_decode( $snapshot_raw, true );
        if ( is_array( $snapshot_data ) && isset( $snapshot_data['pageViews'] ) ) {
            // マイグレーション: pages/keywords がラップされていたら展開して再保存
            $needs_resave = false;
            if ( isset( $snapshot_data['pages']['pages'] ) ) {
                $snapshot_data['pages'] = $snapshot_data['pages']['pages'];
                $needs_resave = true;
            }
            if ( isset( $snapshot_data['keywords']['keywords'] ) ) {
                $snapshot_data['keywords'] = $snapshot_data['keywords']['keywords'];
                $needs_resave = true;
            }
            if ( $needs_resave ) {
                $snapshot_raw = wp_json_encode( $snapshot_data, JSON_UNESCAPED_UNICODE );
                update_post_meta( $report_post_id, '_gcrev_kpi_snapshot_json', wp_slash( $snapshot_raw ) );
            }
            $kpi_snapshot_json = $snapshot_raw;
            $has_full_snapshot = true;
        }
    }

    // 2. 未保存 → 一度だけ取得して固定保存（スナップショット機能追加前のレポート移行用）
    if ( ! $has_full_snapshot ) {
        try {
            $backfill_data = $gcrev_api->get_dashboard_kpi_by_dates(
                $report_start_date,
                $report_end_date,
                $user_id
            );
            if ( is_array( $backfill_data ) && isset( $backfill_data['pageViews'] ) ) {
                $backfill_data['snapshot_version']  = 1;
                $backfill_data['snapshot_saved_at'] = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );
                $json_str = wp_json_encode( $backfill_data, JSON_UNESCAPED_UNICODE );
                update_post_meta( $report_post_id, '_gcrev_kpi_snapshot_json', wp_slash( $json_str ) );
                $kpi_snapshot_json = $json_str;
                $snapshot_data     = $backfill_data;
                $has_full_snapshot  = true;
                error_log( "[GCREV] page-report-latest: Migrated KPI snapshot for report_id={$report_post_id}" );
            }
        } catch ( \Throwable $e ) {
            error_log( '[GCREV] page-report-latest: KPI migration error: ' . $e->getMessage() );
        }
    }
}

// === Effective CV（CVチャート + CV数表示用） ===
// ゴール関連設定（gcrev_actual_cvs / gcrev_cv_routes）はレポート確定後にも更新され得るので、
// スナップショットを使わず常にライブで再計算する。
$effective_cv_json = '{}';
try {
    $year_month_str = sprintf( '%04d-%02d', $year, $month );
    $eff_live = $gcrev_api->get_effective_cv_monthly( $year_month_str, $user_id );
    if ( is_array( $eff_live ) ) {
        $effective_cv_json = wp_json_encode([
            'source'     => $eff_live['source'] ?? 'ga4',
            'total'      => $eff_live['total'] ?? 0,
            'daily'      => $eff_live['daily'] ?? [],
            'has_actual' => ( ( $eff_live['source'] ?? 'ga4' ) !== 'ga4' ),
            'components' => $eff_live['components'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
    }
} catch ( \Throwable $e ) {
    // フォールバック: スナップショットから読む
    if ( $has_full_snapshot && ! empty( $snapshot_data['effective_cv'] ) ) {
        $eff = $snapshot_data['effective_cv'];
        $effective_cv_json = wp_json_encode([
            'source'     => $eff['source'] ?? 'ga4',
            'total'      => $eff['total'] ?? 0,
            'daily'      => $eff['daily'] ?? [],
            'has_actual' => ( ( $eff['source'] ?? 'ga4' ) !== 'ga4' ),
            'components' => $eff['components'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
    }
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
        'red'    => '#C95A4F',
        'blue'   => '#568184',
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
            '減少' => '#C95A4F', '悪化' => '#C95A4F',
            '前月比' => '#568184', '前年比' => '#568184',
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

/* === 年月切り替えUI === */
.rpt-ym-switcher {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 0 24px;
    margin-bottom: 16px;
}
/* 年ナビ：〈 2026年 〉 */
.rpt-year-nav {
    display: flex;
    align-items: center;
    gap: 2px;
    flex-shrink: 0;
}
.rpt-year-arrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    font-size: 14px;
    color: var(--mw-primary-blue, #568184);
    background: none;
    border: 1px solid transparent;
    border-radius: 6px;
    text-decoration: none;
    transition: all 0.15s;
    cursor: pointer;
    line-height: 1;
}
.rpt-year-arrow:hover {
    background: rgba(86,129,132,0.08);
    border-color: rgba(86,129,132,0.15);
    text-decoration: none;
    color: var(--mw-primary-blue, #568184);
}
.rpt-year-arrow.disabled {
    color: #ccc;
    pointer-events: none;
    cursor: default;
}
.rpt-year-label {
    font-size: 15px;
    font-weight: 700;
    color: #2c3e50;
    min-width: 60px;
    text-align: center;
    user-select: none;
}
/* 月ボタン群 */
.rpt-month-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}
.rpt-month-btn {
    min-width: 52px;
    padding: 7px 14px;
    font-size: 13px;
    font-weight: 500;
    border: 1px solid #e0e4ea;
    border-radius: 20px;
    background: #fff;
    color: #555;
    text-decoration: none;
    text-align: center;
    transition: all 0.15s;
}
.rpt-month-btn:hover {
    background: rgba(86,129,132,0.08);
    border-color: rgba(86,129,132,0.3);
    color: var(--mw-primary-blue, #568184);
    text-decoration: none;
}
.rpt-month-btn.active {
    background: var(--mw-primary-blue, #568184);
    color: #fff;
    border-color: var(--mw-primary-blue, #568184);
    font-weight: 600;
}
@media (max-width: 768px) {
    .rpt-ym-switcher { flex-wrap: wrap; padding: 0 16px; gap: 10px; }
    .rpt-month-btn { min-width: 44px; padding: 6px 10px; font-size: 12px; }
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">

    <!-- 印刷ボタン -->
    <div style="display:flex; justify-content:flex-end; margin-bottom:12px;">
        <button type="button" onclick="window.print()"
                style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border:1px solid var(--mw-border-light,#C3CED0); border-radius:8px; background:var(--mw-bg-primary,#fff); color:var(--mw-text-secondary,#384D50); font-size:13px; font-weight:600; cursor:pointer; transition:all 0.15s;"
                onmouseover="this.style.background='var(--mw-bg-secondary,#F5F8F8)'"
                onmouseout="this.style.background='var(--mw-bg-primary,#fff)'">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            印刷
        </button>
    </div>

    <!-- 年月切り替えUI：〈 2026年 〉 [1月] [2月] ... -->
    <?php if ( ! empty( $report_years ) ) :
        // 前年・翌年の算出
        $available_years = array_keys( $report_years );
        $cur_idx   = array_search( $current_year, $available_years, true );
        $prev_year = ( $cur_idx !== false && $cur_idx + 1 < count( $available_years ) ) ? $available_years[ $cur_idx + 1 ] : null; // 降順なので+1が前年
        $next_year = ( $cur_idx !== false && $cur_idx - 1 >= 0 ) ? $available_years[ $cur_idx - 1 ] : null; // -1が翌年

        $prev_year_url = $prev_year ? add_query_arg( 'ym', sprintf( '%04d-%02d', $prev_year, max( $report_years[ $prev_year ] ) ), home_url( '/report/report-latest/' ) ) : '';
        $next_year_url = $next_year ? add_query_arg( 'ym', sprintf( '%04d-%02d', $next_year, max( $report_years[ $next_year ] ) ), home_url( '/report/report-latest/' ) ) : '';
    ?>
    <div class="rpt-ym-switcher">
        <!-- 年ナビ -->
        <div class="rpt-year-nav">
            <?php if ( $prev_year_url ) : ?>
            <a href="<?php echo esc_url( $prev_year_url ); ?>" class="rpt-year-arrow" title="<?php echo esc_attr( $prev_year ); ?>年">&#8249;</a>
            <?php else : ?>
            <span class="rpt-year-arrow disabled">&#8249;</span>
            <?php endif; ?>

            <span class="rpt-year-label"><?php echo esc_html( $current_year ); ?>年</span>

            <?php if ( $next_year_url ) : ?>
            <a href="<?php echo esc_url( $next_year_url ); ?>" class="rpt-year-arrow" title="<?php echo esc_attr( $next_year ); ?>年">&#8250;</a>
            <?php else : ?>
            <span class="rpt-year-arrow disabled">&#8250;</span>
            <?php endif; ?>
        </div>

        <!-- 月ボタン群 -->
        <div class="rpt-month-tabs">
            <?php
            $months_for_year = $report_years[ $current_year ] ?? [];
            foreach ( $months_for_year as $m ) :
                $is_active_month = ( $m === $current_month );
                $month_url = add_query_arg( 'ym', sprintf( '%04d-%02d', $current_year, $m ), home_url( '/report/report-latest/' ) );
            ?>
            <a href="<?php echo esc_url( $month_url ); ?>"
               class="rpt-month-btn<?php echo $is_active_month ? ' active' : ''; ?>"><?php echo esc_html( $m ); ?>月</a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ローディングオーバーレイ -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>データを取得中...</p>
        </div>
    </div>

    <!-- レポート生成プリローダー -->
    <div class="report-preloader-overlay" id="reportPreloader">
        <div class="report-preloader">
            <div class="report-preloader__icon">📊</div>
            <h3 class="report-preloader__title">レポートを生成しています</h3>
            <p class="report-preloader__step" id="preloaderStep">準備しています...</p>
            <div class="report-preloader__bar-wrap">
                <div class="report-preloader__bar" id="preloaderBar"></div>
            </div>
            <p class="report-preloader__percent"><span id="preloaderPercent">0</span>%</p>
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

    <?php
    // 管理者向け: スナップショット情報表示（読み取り専用）
    if ( current_user_can( 'manage_options' ) && $report_post_id > 0 ) :
        $snap_ver  = $has_full_snapshot ? ( $snapshot_data['snapshot_version'] ?? '?' ) : '-';
        $snap_at   = $has_full_snapshot ? ( $snapshot_data['snapshot_saved_at'] ?? '-' ) : '-';
    ?>
    <div class="rpt-admin-toolbar" style="display:flex;align-items:center;gap:12px;margin-bottom:12px;padding:6px 12px;background:#f8f9fa;border-radius:6px;font-size:12px;color:#888;">
        <span>Snapshot: v<?php echo esc_html( $snap_ver ); ?> / <?php echo esc_html( $snap_at ); ?></span>
        <?php if ( ! $has_full_snapshot ) : ?>
        <span style="color:#e65100;">⚠ スナップショット未保存（レポート生成前のデータ）</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // 海外アクセス除外バッジ
    if ( $report_post_id > 0 ) {
        $rpt_client_json = get_post_meta( $report_post_id, '_gcrev_client_info_json', true );
        $rpt_client_data = $rpt_client_json ? json_decode( $rpt_client_json, true ) : [];
        if ( ! empty( $rpt_client_data['exclude_foreign'] ) ) : ?>
    <div class="report-notice-info">
        <span>🌏</span>
        <span>このレポートは海外アクセスを除外して生成されています（日本国内のみ）</span>
    </div>
        <?php endif;
    }
    ?>

    <!-- 3) KPIサマリーカード -->
    <div class="kpi-grid" id="kpiGrid">
        <button type="button" class="kpi-card rpt-kpi-selectable is-active" data-kpi-key="pageViews" data-daily-key="pageViews" data-label="見られた回数" data-color="#568184" data-format="number" aria-pressed="true">
            <div class="kpi-card-header">
                <span class="kpi-title">見られた回数 <span class="kpi-info-btn-wrap" role="button" tabindex="0" aria-label="説明を表示"><svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 6.2a1.8 1.8 0 0 1 3.4.8c0 1.2-1.9 1.4-1.9 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="12" r="0.7" fill="currentColor"/></svg></span><span class="kpi-term">（ページビュー）</span></span>
                <div class="kpi-icon" style="background: rgba(86,129,132,0.08);">👁️</div>
            </div>
            <div class="kpi-info-tip">ホームページの各ページが何回見られたかの合計です。同じ人が何ページも見ると、その分だけ数が増えます。</div>
            <div class="kpi-value" id="kpi-pageviews">-</div>
            <div class="kpi-change" id="kpi-pageviews-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-pageviews"></canvas></div>
            <span class="rpt-kpi-hint">クリックでグラフ切替</span>
        </button>

        <button type="button" class="kpi-card rpt-kpi-selectable" data-kpi-key="sessions" data-daily-key="sessions" data-label="訪問回数" data-color="#D4A842" data-format="number" aria-pressed="false">
            <div class="kpi-card-header">
                <span class="kpi-title">訪問回数 <span class="kpi-info-btn-wrap" role="button" tabindex="0" aria-label="説明を表示"><svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 6.2a1.8 1.8 0 0 1 3.4.8c0 1.2-1.9 1.4-1.9 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="12" r="0.7" fill="currentColor"/></svg></span><span class="kpi-term">（セッション）</span></span>
                <div class="kpi-icon" style="background: rgba(212,168,66,0.12);">🎯</div>
            </div>
            <div class="kpi-info-tip">ホームページに誰かが来た回数です。1人が朝と夜に来たら「2回」とカウントされます。</div>
            <div class="kpi-value" id="kpi-sessions">-</div>
            <div class="kpi-change" id="kpi-sessions-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-sessions"></canvas></div>
            <span class="rpt-kpi-hint">クリックでグラフ切替</span>
        </button>

        <button type="button" class="kpi-card rpt-kpi-selectable" data-kpi-key="users" data-daily-key="users" data-label="見に来た人の数" data-color="#4E8A6B" data-format="number" aria-pressed="false">
            <div class="kpi-card-header">
                <span class="kpi-title">見に来た人の数 <span class="kpi-info-btn-wrap" role="button" tabindex="0" aria-label="説明を表示"><svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 6.2a1.8 1.8 0 0 1 3.4.8c0 1.2-1.9 1.4-1.9 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="12" r="0.7" fill="currentColor"/></svg></span><span class="kpi-term">（ユーザー）</span></span>
                <div class="kpi-icon" style="background: rgba(78,138,107,0.1);">👥</div>
            </div>
            <div class="kpi-info-tip">ホームページを見に来た人数です。同じ人が何回来ても「1人」としてカウントされます。</div>
            <div class="kpi-value" id="kpi-users">-</div>
            <div class="kpi-change" id="kpi-users-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-users"></canvas></div>
            <span class="rpt-kpi-hint">クリックでグラフ切替</span>
        </button>

        <button type="button" class="kpi-card rpt-kpi-selectable" data-kpi-key="newUsers" data-daily-key="newUsers" data-label="はじめての人の数" data-color="#7AA3A6" data-format="number" aria-pressed="false">
            <div class="kpi-card-header">
                <span class="kpi-title">はじめての人の数 <span class="kpi-info-btn-wrap" role="button" tabindex="0" aria-label="説明を表示"><svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 6.2a1.8 1.8 0 0 1 3.4.8c0 1.2-1.9 1.4-1.9 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="12" r="0.7" fill="currentColor"/></svg></span><span class="kpi-term">（新規ユーザー）</span></span>
                <div class="kpi-icon" style="background: rgba(122,163,166,0.1);">✨</div>
            </div>
            <div class="kpi-info-tip">この期間にはじめてホームページを訪れた人の数です。新しいお客様候補がどれだけ増えたかがわかります。</div>
            <div class="kpi-value" id="kpi-newusers">-</div>
            <div class="kpi-change" id="kpi-newusers-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-newusers"></canvas></div>
            <span class="rpt-kpi-hint">クリックでグラフ切替</span>
        </button>

        <button type="button" class="kpi-card rpt-kpi-selectable" data-kpi-key="returningUsers" data-daily-key="returning" data-label="また来てくれた人" data-color="#C95A4F" data-format="number" aria-pressed="false">
            <div class="kpi-card-header">
                <span class="kpi-title">また来てくれた人 <span class="kpi-info-btn-wrap" role="button" tabindex="0" aria-label="説明を表示"><svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 6.2a1.8 1.8 0 0 1 3.4.8c0 1.2-1.9 1.4-1.9 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="12" r="0.7" fill="currentColor"/></svg></span><span class="kpi-term">（リピーター）</span></span>
                <div class="kpi-icon" style="background: rgba(181,87,75,0.08);">🔁</div>
            </div>
            <div class="kpi-info-tip">以前にもホームページを見たことがある人の数です。多いほど「また見たい」と思われている証拠です。</div>
            <div class="kpi-value" id="kpi-returning">-</div>
            <div class="kpi-change" id="kpi-returning-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-returning"></canvas></div>
            <span class="rpt-kpi-hint">クリックでグラフ切替</span>
        </button>

        <button type="button" class="kpi-card rpt-kpi-selectable" data-kpi-key="avgDuration" data-daily-key="duration" data-label="しっかり見られた時間" data-color="#C9A84C" data-format="duration" aria-pressed="false">
            <div class="kpi-card-header">
                <span class="kpi-title">しっかり見られた時間 <span class="kpi-info-btn-wrap" role="button" tabindex="0" aria-label="説明を表示"><svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 6.2a1.8 1.8 0 0 1 3.4.8c0 1.2-1.9 1.4-1.9 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="12" r="0.7" fill="currentColor"/></svg></span><span class="kpi-term">（平均滞在時間）</span></span>
                <div class="kpi-icon" style="background: rgba(212,168,66,0.15);">⏱️</div>
            </div>
            <div class="kpi-info-tip">訪問者がホームページに滞在した平均時間です。長いほど内容に興味を持って読んでもらえています。</div>
            <div class="kpi-value" id="kpi-duration">-</div>
            <div class="kpi-change" id="kpi-duration-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-duration"></canvas></div>
            <span class="rpt-kpi-hint">クリックでグラフ切替</span>
        </button>

        <button type="button" class="kpi-card rpt-kpi-selectable" data-kpi-key="conversions" data-daily-key="conversions" data-label="ゴール数" data-color="#4E8A6B" data-format="number" aria-pressed="false">
            <div class="kpi-card-header">
                <span class="kpi-title">ゴール数<span id="kpi-cv-source-label" style="font-size:10px;color:#666666;margin-left:4px;display:none;"></span> <span class="kpi-info-btn-wrap" role="button" tabindex="0" aria-label="説明を表示"><svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 6.2a1.8 1.8 0 0 1 3.4.8c0 1.2-1.9 1.4-1.9 2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="12" r="0.7" fill="currentColor"/></svg></span><span class="kpi-term">（コンバージョン）</span></span>
                <div class="kpi-icon" style="background: rgba(78,138,107,0.1);">🎉</div>
            </div>
            <div class="kpi-info-tip">お問い合わせや申込みなど、ホームページの目標が達成された回数です。この数が増えると、ホームページが成果につながっています。</div>
            <div class="kpi-value" id="kpi-conversions">-</div>
            <div class="kpi-change" id="kpi-conversions-change"><span>-</span></div>
            <div class="kpi-sparkline"><canvas id="sparkline-conversions"></canvas></div>
            <span class="rpt-kpi-hint">クリックでグラフ切替</span>
        </button>
    </div><!-- .kpi-grid -->

    <!-- KPIトレンドチャート（カード選択に連動） -->
    <div class="rpt-trend-chart-wrap" id="rptTrendChartWrap">
        <div class="rpt-trend-chart-title" id="rptTrendChartTitle">📈 <?php echo (int)$month; ?>月の見られた回数の推移</div>
        <div style="height: 280px;">
            <canvas id="rptTrendChart"></canvas>
        </div>
    </div>

    <?php if ($monthly_report): ?>

    <!-- 4) report-content：総評セクション（KPIカード直下に配置） -->
    <div class="report-content">

        <!-- 📋 総評 -->
        <div class="report-section" data-ai-section="report_summary">
            <h2 class="section-title">📋 <?php echo esc_html($year . '年' . $month . '月'); ?>の総合評価
              <?php if ($can_ai): ?>
              <button type="button" class="ask-ai-btn" data-ai-ask
                data-ai-instruction="先月の総合評価を見て、最も重要な気づきと次にやることを教えて">
                <span class="ask-ai-btn__icon" aria-hidden="true">✨</span>AIに聞く
              </button>
              <?php endif; ?>
            </h2>
            <div class="section-content">
                <?php if (!empty($monthly_report['summary'])): ?>
                <div class="highlight-box">
                    <h4>🎯 総合評価</h4>
                    <p><?php echo enhance_report_text($monthly_report['summary']); ?></p>
                </div>
                <?php else: ?>
                <p>先月のレポートサマリーを生成中です...</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ✅ 良かった点（成果） -->
        <?php if ($can_good_points): ?>
        <div class="report-section" data-ai-section="report_good">
            <h2 class="section-title">✅ 良かった点（成果）
              <?php if ($can_ai): ?>
              <button type="button" class="ask-ai-btn" data-ai-ask
                data-ai-instruction="この「良かった点（成果）」を踏まえて、次に伸ばすべきポイントは？">
                <span class="ask-ai-btn__icon" aria-hidden="true">✨</span>AIに聞く
              </button>
              <?php endif; ?>
            </h2>
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
        <?php else: ?>
        <div class="report-section plan-locked-section" data-ai-section="report_good">
            <h2 class="section-title">✅ 良かった点（成果）</h2>
            <div class="plan-locked-overlay">
                <div class="plan-locked-icon">&#x1F512;</div>
                <p class="plan-locked-message">MEO・口コミ対策プランで詳しい分析が見られます</p>
                <a href="<?php echo esc_url( home_url( '/service/' ) ); ?>" class="plan-locked-link">プランを見る →</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- ⚠️ 改善が必要な点（課題） -->
        <?php if ($can_improvements): ?>
        <div class="report-section" data-ai-section="report_issue">
            <h2 class="section-title">⚠️ 改善が必要な点（課題）
              <?php if ($can_ai): ?>
              <button type="button" class="ask-ai-btn" data-ai-ask
                data-ai-instruction="この「改善が必要な点（課題）」の原因と、最短で効く改善を3つ提案して">
                <span class="ask-ai-btn__icon" aria-hidden="true">✨</span>AIに聞く
              </button>
              <?php endif; ?>
            </h2>
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
        <?php else: ?>
        <div class="report-section plan-locked-section" data-ai-section="report_issue">
            <h2 class="section-title">⚠️ 改善が必要な点（課題）</h2>
            <div class="plan-locked-overlay">
                <div class="plan-locked-icon">&#x1F512;</div>
                <p class="plan-locked-message">MEO・口コミ対策プランで課題と対策が見られます</p>
                <a href="<?php echo esc_url( home_url( '/service/' ) ); ?>" class="plan-locked-link">プランを見る →</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- 💡 考察とインサイト -->
        <?php if ($can_consideration): ?>
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
        <?php else: ?>
        <div class="report-section plan-locked-section">
            <h2 class="section-title">💡 考察とインサイト</h2>
            <div class="plan-locked-overlay">
                <div class="plan-locked-icon">&#x1F512;</div>
                <p class="plan-locked-message">MEO・口コミ対策プランで詳しい考察が見られます</p>
                <a href="<?php echo esc_url( home_url( '/service/' ) ); ?>" class="plan-locked-link">プランを見る →</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- 🎯 ネクストアクション（優先度順） -->
        <?php if ($can_next_actions): ?>
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
        <?php else: ?>
        <div class="report-section plan-locked-section">
            <h2 class="section-title">🎯 ネクストアクション（優先度順）</h2>
            <div class="plan-locked-overlay">
                <div class="plan-locked-icon">&#x1F512;</div>
                <p class="plan-locked-message">MEO・口コミ対策プランで具体的なアクションプランが見られます</p>
                <a href="<?php echo esc_url( home_url( '/service/' ) ); ?>" class="plan-locked-link">プランを見る →</a>
            </div>
        </div>
        <?php endif; ?>

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
                        <span>見つけたきっかけ</span>
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

// KPIスナップショット（保存済みデータのみ — API再取得なし）
const kpiSnapshot = <?php echo $kpi_snapshot_json; ?>;
const reportMonth = <?php echo (int)$month; ?>;

let sparklineCharts = {};

// KPIデータ表示（保存済みスナップショットのみ — API再取得なし）
function updateKPIData() {
    if (kpiSnapshot) {
        updateKPIDisplay(kpiSnapshot);
    }
    // スナップショットなし → KPI欄はデフォルトの「-」のまま表示
}

// KPI表示更新（dashboard同一 + CV数追加）
function updateKPIDisplay(data) {
    document.getElementById('kpi-pageviews').textContent = formatNumber(data.pageViews);
    document.getElementById('kpi-sessions').textContent = formatNumber(data.sessions);
    document.getElementById('kpi-users').textContent = formatNumber(data.users);
    document.getElementById('kpi-newusers').textContent = formatNumber(data.newUsers);
    document.getElementById('kpi-returning').textContent = formatNumber(data.returningUsers);
    document.getElementById('kpi-duration').textContent = data.avgDuration + '秒';

    // CV数: ライブの effectiveCvData が手動設定を含むので最優先。
    // 無ければスナップショットの conversions（GA4）にフォールバック。
    const hasLiveEffective = effectiveCvData
        && typeof effectiveCvData.total === 'number'
        && (effectiveCvData.has_actual || effectiveCvData.total > 0 || (effectiveCvData.source && effectiveCvData.source !== 'ga4'));
    const effectiveSource = hasLiveEffective ? (effectiveCvData.source || 'ga4') : (data.cv_source || 'ga4');

    if (hasLiveEffective) {
        document.getElementById('kpi-conversions').textContent = formatNumber(effectiveCvData.total);
    } else if (data.conversions !== undefined) {
        document.getElementById('kpi-conversions').textContent = formatNumber(data.conversions);
    } else {
        document.getElementById('kpi-conversions').textContent = '—';
    }

    // CV数ソースラベル表示
    const cvSourceLabel = document.getElementById('kpi-cv-source-label');
    if (cvSourceLabel) {
        if (effectiveSource === 'hybrid') {
            cvSourceLabel.textContent = '（GA4+手動）';
            cvSourceLabel.style.display = 'inline';
            cvSourceLabel.style.color = '#4E8A6B';
        } else if (effectiveSource === 'actual_plus_phone') {
            cvSourceLabel.textContent = '（実質+電話タップ）';
            cvSourceLabel.style.display = 'inline';
            cvSourceLabel.style.color = '#4E8A6B';
        } else if (effectiveSource === 'actual') {
            cvSourceLabel.textContent = '（実質）';
            cvSourceLabel.style.display = 'inline';
            cvSourceLabel.style.color = '#4E8A6B';
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
        element.innerHTML = '<span>↑</span><span>' + trendData.text + '</span>';
    } else if (trendData.value < 0) {
        element.classList.add('negative');
        element.innerHTML = '<span>↓</span><span>' + trendData.text.replace('-', '') + '</span>';
    } else {
        element.classList.add('neutral');
        element.innerHTML = '<span>→</span><span>' + trendData.text + '</span>';
    }
}

// スパークライン更新（dashboard同一）
function updateSparklines(dailyData) {
    const sparklineConfigs = [
        { id: 'sparkline-pageviews', data: dailyData.pageViews, color: '#568184' },
        { id: 'sparkline-sessions', data: dailyData.sessions, color: '#D4A842' },
        { id: 'sparkline-users', data: dailyData.users, color: '#4E8A6B' },
        { id: 'sparkline-newusers', data: dailyData.newUsers, color: '#7AA3A6' },
        { id: 'sparkline-returning', data: dailyData.returning, color: '#C95A4F' },
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
            color: '#4E8A6B'
        });
    } else if (dailyData.conversions) {
        sparklineConfigs.push({ id: 'sparkline-conversions', data: dailyData.conversions, color: '#4E8A6B' });
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

// =============================================
// KPIカード選択 → トレンドチャート連動
// =============================================
let rptTrendChart = null;
let selectedRptKpi = 'pageViews';

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + alpha + ')';
}

function formatDurationLabel(sec) {
    const s = parseFloat(sec);
    if (isNaN(s) || s < 0) return '-';
    const m = Math.floor(s / 60);
    const ss = Math.floor(s % 60);
    return m + ':' + (ss < 10 ? '0' : '') + ss;
}

function renderRptTrendChart() {
    if (!kpiSnapshot || !kpiSnapshot.daily) return;
    const daily = kpiSnapshot.daily;
    const grid = document.getElementById('kpiGrid');
    const activeCard = grid ? grid.querySelector('.rpt-kpi-selectable[data-kpi-key="' + selectedRptKpi + '"]') : null;
    if (!activeCard) return;

    const dailyKey = activeCard.dataset.dailyKey;
    const label = activeCard.dataset.label;
    const color = activeCard.dataset.color;
    const fmt = activeCard.dataset.format;

    // タイトル更新
    const titleEl = document.getElementById('rptTrendChartTitle');
    if (titleEl) titleEl.textContent = '📈 ' + reportMonth + '月の' + label + 'の推移';

    // データ取得（CV は effectiveCvData を優先）
    let sparkData = null;
    if (selectedRptKpi === 'conversions' && effectiveCvData && effectiveCvData.daily && Object.keys(effectiveCvData.daily).length > 0) {
        const cvDates = Object.keys(effectiveCvData.daily).sort();
        sparkData = {
            labels: cvDates.map(d => { const p = d.split('-'); return parseInt(p[2]) + '日'; }),
            values: cvDates.map(d => effectiveCvData.daily[d])
        };
    } else {
        sparkData = daily[dailyKey];
    }

    const ctx = document.getElementById('rptTrendChart');
    if (!ctx) return;

    if (!sparkData || !sparkData.values || sparkData.values.length === 0) {
        if (rptTrendChart) rptTrendChart.destroy();
        rptTrendChart = null;
        return;
    }

    const labels = (sparkData.labels || []).map(l => {
        if (l === null || l === undefined) return '';
        const s = String(l);
        const parts = s.split('-');
        if (parts.length === 3) return parseInt(parts[2]) + '日';
        // YYYYMMDD 形式（8桁数値）
        if (/^\d{8}$/.test(s)) return parseInt(s.slice(6, 8)) + '日';
        // M/D 形式
        if (s.includes('/')) return parseInt(s.split('/')[1]) + '日';
        return s;
    });

    if (rptTrendChart) rptTrendChart.destroy();

    const isDuration = (fmt === 'duration');
    const yConfig = { beginAtZero: true, ticks: { precision: 0 } };
    if (isDuration) {
        yConfig.ticks = { callback: v => formatDurationLabel(v) };
    }

    rptTrendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: label,
                data: sparkData.values,
                borderColor: color,
                backgroundColor: hexToRgba(color, 0.12),
                fill: true, tension: 0.3, pointRadius: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: isDuration ? {
                    callbacks: { label: ctx => label + ': ' + formatDurationLabel(ctx.parsed.y) }
                } : {
                    callbacks: { label: ctx => label + ': ' + formatNumber(ctx.parsed.y) }
                }
            },
            scales: { y: yConfig }
        }
    });
}

// カード選択イベント
(function() {
    const grid = document.getElementById('kpiGrid');
    if (!grid) return;

    // ?ボタンのクリックイベント
    grid.querySelectorAll('.kpi-info-btn-wrap').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const card = btn.closest('.kpi-card');
            if (card) card.classList.toggle('info-open');
        });
    });

    // カード選択
    grid.querySelectorAll('.rpt-kpi-selectable').forEach(card => {
        card.addEventListener('click', e => {
            if (e.target.closest('.kpi-info-btn-wrap')) return;
            const key = card.dataset.kpiKey;
            if (!key || key === selectedRptKpi) return;
            selectedRptKpi = key;
            grid.querySelectorAll('.rpt-kpi-selectable').forEach(c => {
                const isActive = (c.dataset.kpiKey === key);
                c.classList.toggle('is-active', isActive);
                c.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            renderRptTrendChart();
        });
    });
})();

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
    // 保存済みスナップショットのみ使用（API再取得なし）
    if (kpiSnapshot) {
        updateDeviceList(kpiSnapshot.devices || []);
        updateAgeList(kpiSnapshot.age || []);
        updateMediumList(kpiSnapshot.medium || []);
        updateRegionList(kpiSnapshot.geo_region || []);
        updatePagesList(kpiSnapshot.pages || []);
        updateKeywordsList(kpiSnapshot.keywords || []);
    } else {
        // スナップショット未保存 → 「データなし」を表示
        updateDeviceList([]);
        updateAgeList([]);
        updateMediumList([]);
        updateRegionList([]);
        updatePagesList([]);
        updateKeywordsList([]);
    }
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
    const colors = ['#568184', '#A68B5B', '#7B8EAA', '#C95A4F', '#8B7BAA'];
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
        data: { labels, datasets: [{ data, backgroundColor: '#4E8A6B', borderRadius: 4 }] },
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
        data: { labels, datasets: [{ data, backgroundColor: '#568184', borderRadius: 4 }] },
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
// レポート生成プリローダー制御
// =============================================
const reportPreloaderSteps = [
    { at:  0, text: '準備しています...' },
    { at:  5, text: 'GA4の設定を確認しています...' },
    { at: 12, text: 'レポート生成を開始しています...' },
    { at: 25, text: 'アクセスデータを取得しています...' },
    { at: 40, text: '前月のデータを分析しています...' },
    { at: 55, text: 'AIコメントを生成しています...' },
    { at: 70, text: 'レポートを組み立てています...' },
    { at: 85, text: '仕上げ処理をしています...' },
    { at: 93, text: '表示を準備しています...' },
];

let preloaderTimer = null;
let preloaderCurrent = 0;

function showReportPreloader() {
    const overlay = document.getElementById('reportPreloader');
    if (overlay) overlay.classList.add('active');
    preloaderCurrent = 0;
    updatePreloaderUI(0, reportPreloaderSteps[0].text);
    startPreloaderProgress();
}

function hideReportPreloader() {
    stopPreloaderProgress();
    const overlay = document.getElementById('reportPreloader');
    if (overlay) overlay.classList.remove('active');
}

function updatePreloaderUI(percent, stepText) {
    const bar = document.getElementById('preloaderBar');
    const num = document.getElementById('preloaderPercent');
    const step = document.getElementById('preloaderStep');
    if (bar) bar.style.width = percent + '%';
    if (num) num.textContent = Math.round(percent);
    if (step && stepText) step.textContent = stepText;
}

function getStepText(percent) {
    let text = reportPreloaderSteps[0].text;
    for (let i = reportPreloaderSteps.length - 1; i >= 0; i--) {
        if (percent >= reportPreloaderSteps[i].at) {
            text = reportPreloaderSteps[i].text;
            break;
        }
    }
    return text;
}

function startPreloaderProgress() {
    stopPreloaderProgress();
    preloaderTimer = setInterval(function() {
        if (preloaderCurrent >= 93) {
            // 93% で一旦停止（実処理完了待ち）
            stopPreloaderProgress();
            return;
        }
        // 序盤は速く、中盤からゆるやかに
        var increment;
        if (preloaderCurrent < 20) {
            increment = 1.2 + Math.random() * 0.8;
        } else if (preloaderCurrent < 50) {
            increment = 0.6 + Math.random() * 0.6;
        } else if (preloaderCurrent < 80) {
            increment = 0.3 + Math.random() * 0.4;
        } else {
            increment = 0.1 + Math.random() * 0.2;
        }
        preloaderCurrent = Math.min(preloaderCurrent + increment, 93);
        updatePreloaderUI(preloaderCurrent, getStepText(preloaderCurrent));
    }, 300);
}

function stopPreloaderProgress() {
    if (preloaderTimer) {
        clearInterval(preloaderTimer);
        preloaderTimer = null;
    }
}

function finishPreloader() {
    return new Promise(function(resolve) {
        stopPreloaderProgress();
        updatePreloaderUI(100, '完了しました！');
        setTimeout(resolve, 800);
    });
}

// =============================================
// 月次AIレポート生成機能
// =============================================
async function generateMonthlyReport() {
    const btn = document.getElementById('btn-generate-report');
    const statusDiv = document.getElementById('report-generation-status');
    if (!btn) return;

    btn.disabled = true;
    btn.style.opacity = '0.6';
    btn.style.cursor = 'not-allowed';

    const wpNonce = '<?php echo wp_create_nonce("wp_rest"); ?>';

    try {
        // Step 0: GA4プロパティ設定チェック（プリローダー表示前に軽量チェック）
        const checkUrl = '<?php echo rest_url("gcrev/v1/report/check-prev2-data"); ?>';
        const checkRes = await fetch(checkUrl, {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        });
        if (checkRes.ok) {
            const checkJson = await checkRes.json();
            if (checkJson.code === 'NO_PREV2_DATA') {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                alert('⚠️ ' + (checkJson.reason || 'GA4プロパティの設定を確認してください。'));
                return;
            }
        }

        // チェック通過 → プリローダー表示
        showReportPreloader();

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

        // レスポンスが JSON かどうかチェック（PHP Fatal Error 時に HTML が返る場合がある）
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text.substring(0, 500));
            throw new Error('サーバーでエラーが発生しました。しばらく待ってから再度お試しください。');
        }

        const result = await response.json();

        if (result.success) {
            await finishPreloader();
            location.reload();
        } else {
            if (result.code === 'NO_PREV2_DATA') {
                throw new Error(result.message || 'GA4プロパティの設定を確認してください。');
            }
            throw new Error(result.message || 'レポート生成に失敗しました');
        }
    } catch (error) {
        hideReportPreloader();
        alert('❌ エラー: ' + error.message);
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        if (statusDiv) statusDiv.style.display = 'none';
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
    // KPIデータ取得（レポート対象期間で取得）
    updateKPIData();
    renderRptTrendChart();

    // 集客分析データ取得（常に表示）
    loadAnalysisData();

    // レポート生成ボタン（未生成時のみ存在）
    const btnGenerateReport = document.getElementById('btn-generate-report');
    if (btnGenerateReport) {
        btnGenerateReport.addEventListener('click', generateMonthlyReport);
    }

    // KPI インフォボタン — クリックで説明表示トグル
    document.querySelectorAll('.kpi-info-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var card = btn.closest('.kpi-card');
            var wasOpen = card.classList.contains('info-open');
            // 他のカードを閉じる
            document.querySelectorAll('.kpi-card.info-open').forEach(function(c) {
                c.classList.remove('info-open');
            });
            // トグル
            if (!wasOpen) card.classList.add('info-open');
        });
    });
    // カード外クリックで閉じる
    document.addEventListener('click', function() {
        document.querySelectorAll('.kpi-card.info-open').forEach(function(c) {
            c.classList.remove('info-open');
        });
    });
});
</script>

<!-- 印刷用フッター（画面では非表示、印刷時のみ表示） -->
<div class="print-footer" style="display:none;">
    <div style="text-align:center; padding:16px 0 8px; border-top:1px solid #E6EEF0; color:#999; font-size:10px;">
        Powered by <strong style="color:#568184;">みまもりウェブ</strong>
        <span style="margin-left:4px; color:#aaa;">mimamori-web.jp</span>
    </div>
</div>

<style>
/* --- KPI Card — Selectable button reset --- */
button.rpt-kpi-selectable {
    font-family: inherit;
    font-size: inherit;
    line-height: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    transition: all 0.25s ease;
}
.rpt-kpi-selectable:hover {
    box-shadow: var(--mw-shadow-float, 0 8px 24px rgba(0,0,0,0.07));
    border-color: var(--mw-border-medium, #AEBCBE);
    transform: translateY(-1px);
}

/* --- KPI Card — Active/Selected state --- */
.rpt-kpi-selectable.is-active {
    border-color: var(--mw-primary-blue, #568184);
    border-bottom: 3px solid var(--mw-primary-blue, #568184);
    background: rgba(86, 129, 132, 0.04);
    box-shadow: var(--mw-shadow-soft, 0 1px 6px rgba(0,0,0,0.03));
}
.rpt-kpi-selectable.is-active .kpi-title {
    color: var(--mw-primary-blue, #568184);
}
.rpt-kpi-selectable.is-active .rpt-kpi-hint {
    color: var(--mw-primary-blue, #568184);
}

/* --- KPI Card — Hint text --- */
.rpt-kpi-hint {
    display: block;
    font-size: 11px;
    color: #aaa;
    margin-top: 6px;
    transition: color 0.2s ease;
}

/* --- KPI Card — Focus visible (accessibility) --- */
.rpt-kpi-selectable:focus-visible {
    outline: 2px solid var(--mw-primary-blue, #568184);
    outline-offset: 2px;
}

/* --- Trend Chart Section --- */
.rpt-trend-chart-wrap {
    background: var(--mw-bg-primary, #FFFFFF);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-md, 16px);
    padding: 24px;
    margin-bottom: 32px;
}
.rpt-trend-chart-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
    margin-bottom: 20px;
}
</style>

<style>
@media print {
    .print-footer {
        display: block !important;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: #fff;
    }
    /* 印刷フッター分の余白確保 */
    .content-area {
        padding-bottom: 50px !important;
    }
    /* 集客分析結果の見出し（インラインstyle上書き） */
    .digest-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}
</style>

<?php get_footer(); ?>
