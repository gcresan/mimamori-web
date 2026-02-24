<?php
// FILE: inc/gcrev-api/utils/class-date-helper.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Date_Helper') ) { return; }

/**
 * Gcrev_Date_Helper
 *
 * 日付範囲の計算・比較期間の算出など、
 * ダッシュボード / 分析ページ / レポート生成で共通利用する
 * 日付関連ヘルパーを集約する。
 *
 * メソッド一覧（旧 Gcrev_Insight_API からの移植）:
 *   - get_date_range()              : ダッシュボード用の期間計算（wp_timezone 使用）
 *   - get_comparison_range()        : 同日数の直前比較期間を算出
 *   - get_kpi_dates()               : 過去7日間の日付配列
 *   - calculate_period_dates()      : 分析ページ用の期間計算（Asia/Tokyo 固定）
 *   - calculate_comparison_dates()  : 分析ページ用の比較期間
 *
 * @package GCREV_INSIGHT
 * @since   2.0.0
 */
class Gcrev_Date_Helper {

    // =========================================================
    // コンストラクタ
    // =========================================================

    /**
     * 現時点ではステートレス。将来 DI が必要になった場合に備えて
     * コンストラクタを用意しておく。
     */
    public function __construct() {
        // 依存なし
    }

    // =========================================================
    // ダッシュボード用：期間範囲の計算
    // =========================================================

    /**
     * 指定された range キーに対応する開始日・終了日・表示情報を返す
     *
     * 使用箇所（旧）: fetch_dashboard_data_internal, get_source_analysis 等
     *
     * @param  string $range 'last30' | 'last90' | 'last180' | 'last365' | 'previousMonth' | 'twoMonthsAgo'
     * @return array{start: string, end: string, display: array{text: string, start: string, end: string}}
     * @throws \InvalidArgumentException 未知の range が渡された場合
     */
    public function get_date_range(string $range): array {

        $tz = wp_timezone();

        switch ($range) {
            case 'last30':
                $end   = new \DateTimeImmutable('yesterday', $tz);
                $start = $end->sub(new \DateInterval('P29D'));
                $display = [
                    'text'  => '直近30日',
                    'start' => $start->format('Y/m/d'),
                    'end'   => $end->format('Y/m/d'),
                ];
                break;

            case 'last90':
                $end   = new \DateTimeImmutable('yesterday', $tz);
                $start = $end->sub(new \DateInterval('P89D'));
                $display = [
                    'text'  => '過去90日',
                    'start' => $start->format('Y/m/d'),
                    'end'   => $end->format('Y/m/d'),
                ];
                break;

            case 'last180':
                $end   = new \DateTimeImmutable('yesterday', $tz);
                $start = $end->sub(new \DateInterval('P179D'));
                $display = [
                    'text'  => '過去半年',
                    'start' => $start->format('Y/m/d'),
                    'end'   => $end->format('Y/m/d'),
                ];
                break;

            case 'last365':
                $end   = new \DateTimeImmutable('yesterday', $tz);
                $start = $end->sub(new \DateInterval('P364D'));
                $display = [
                    'text'  => '過去1年',
                    'start' => $start->format('Y/m/d'),
                    'end'   => $end->format('Y/m/d'),
                ];
                break;

            case 'previousMonth':
                $now   = new \DateTimeImmutable('now', $tz);
                $start = new \DateTimeImmutable('first day of last month', $tz);
                $end   = new \DateTimeImmutable('last day of last month', $tz);
                $display = [
                    'text'  => $start->format('Y年n月'),
                    'start' => $start->format('Y/m/d'),
                    'end'   => $end->format('Y/m/d'),
                ];
                break;

            case 'twoMonthsAgo':
                $now   = new \DateTimeImmutable('now', $tz);
                $start = new \DateTimeImmutable('first day of 2 months ago', $tz);
                $end   = new \DateTimeImmutable('last day of 2 months ago', $tz);
                $display = [
                    'text'  => $start->format('Y年n月'),
                    'start' => $start->format('Y/m/d'),
                    'end'   => $end->format('Y/m/d'),
                ];
                break;

            default:
                throw new \InvalidArgumentException("Unknown range: {$range}");
        }

        return [
            'start'   => $start->format('Y-m-d'),
            'end'     => $end->format('Y-m-d'),
            'display' => $display,
        ];
    }

    // =========================================================
    // 比較期間（同日数の直前期間）を計算
    // =========================================================

    /**
     * 指定期間と同じ日数だけ遡った比較用期間を返す
     *
     * @param  string $start 開始日（Y-m-d）
     * @param  string $end   終了日（Y-m-d）
     * @return array{start: string, end: string, display: array{text: string, start: string, end: string}}
     */
    public function get_comparison_range(string $start, string $end): array {
        $tz = wp_timezone();

        $start_dt = new \DateTimeImmutable($start, $tz);
        $end_dt   = new \DateTimeImmutable($end, $tz);

        // 日数（両端含む）
        $days = (int)$start_dt->diff($end_dt)->format('%a') + 1;

        $comp_end   = $start_dt->sub(new \DateInterval('P1D'));
        $comp_start = $comp_end->sub(new \DateInterval('P' . ($days - 1) . 'D'));

        return [
            'start' => $comp_start->format('Y-m-d'),
            'end'   => $comp_end->format('Y-m-d'),
            'display' => [
                'text'  => '前期',
                'start' => $comp_start->format('Y/m/d'),
                'end'   => $comp_end->format('Y/m/d'),
            ],
        ];
    }

    // =========================================================
    // KPI推移取得用：過去7日分の日付配列
    // =========================================================

    /**
     * 昨日から遡って7日間の日付配列を返す（昇順）
     *
     * @return string[] Y-m-d 形式の日付配列（要素数7）
     */
    public function get_kpi_dates(): array {

        $tz = wp_timezone();
        $end   = new \DateTimeImmutable('yesterday', $tz);
        $dates = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = $end->sub(new \DateInterval("P{$i}D"));
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }

    // =========================================================
    // 分析ページ用：期間の日付計算
    // =========================================================

    /**
     * 分析ページ用の当該期間を算出する
     *
     * get_date_range() との違い:
     *   - タイムゾーンが Asia/Tokyo 固定（wp_timezone ではなく）
     *   - display が文字列（配列ではなく単一テキスト）
     *   - 'prev-month' キーに対応
     *
     * @param  string $period 'last30' | 'last90' | 'last180' | 'last365' | 'prev-month'
     * @return array{start: string, end: string, display: string}
     */
    public function calculate_period_dates(string $period): array {
        $today = new \DateTime('now', new \DateTimeZone('Asia/Tokyo'));

        switch ($period) {
            case 'last30':
                $end = clone $today;
                $end->modify('-1 day');
                $start = clone $end;
                $start->modify('-29 days');
                $display = $start->format('Y/m/d') . ' 〜 ' . $end->format('Y/m/d');
                break;

            case 'last90':
                $end = clone $today;
                $end->modify('-1 day');
                $start = clone $end;
                $start->modify('-89 days');
                $display = $start->format('Y/m/d') . ' 〜 ' . $end->format('Y/m/d');
                break;

            case 'last180':
                $end = clone $today;
                $end->modify('-1 day');
                $start = clone $end;
                $start->modify('-179 days');
                $display = $start->format('Y/m/d') . ' 〜 ' . $end->format('Y/m/d');
                break;

            case 'last365':
                $end = clone $today;
                $end->modify('-1 day');
                $start = clone $end;
                $start->modify('-364 days');
                $display = $start->format('Y/m/d') . ' 〜 ' . $end->format('Y/m/d');
                break;

            case 'prev-prev-month':
                $first_of_month = new \DateTime('first day of -2 months', new \DateTimeZone('Asia/Tokyo'));
                $last_of_month  = new \DateTime('last day of -2 months', new \DateTimeZone('Asia/Tokyo'));
                $start   = $first_of_month;
                $end     = $last_of_month;
                $display = $start->format('Y年m月');
                break;

            case 'prev-month':
            default:
                $first_of_month = new \DateTime('first day of last month', new \DateTimeZone('Asia/Tokyo'));
                $last_of_month  = new \DateTime('last day of last month', new \DateTimeZone('Asia/Tokyo'));
                $start   = $first_of_month;
                $end     = $last_of_month;
                $display = $start->format('Y年m月');
                break;
        }

        return [
            'start'   => $start->format('Y-m-d'),
            'end'     => $end->format('Y-m-d'),
            'display' => $display,
        ];
    }

    // =========================================================
    // 分析ページ用：比較期間の日付計算
    // =========================================================

    /**
     * 分析ページ用の比較期間を算出する
     *
     * @param  string $period 'last30' | 'last90' | 'last180' | 'last365' | 'prev-month'
     * @return array{start: string, end: string}
     */
    public function calculate_comparison_dates(string $period): array {
        $today = new \DateTime('now', new \DateTimeZone('Asia/Tokyo'));

        switch ($period) {
            case 'last30':
                $end = clone $today;
                $end->modify('-31 days');
                $start = clone $end;
                $start->modify('-29 days');
                break;

            case 'last90':
                $end = clone $today;
                $end->modify('-91 days');
                $start = clone $end;
                $start->modify('-89 days');
                break;

            case 'last180':
                $end = clone $today;
                $end->modify('-181 days');
                $start = clone $end;
                $start->modify('-179 days');
                break;

            case 'last365':
                $end = clone $today;
                $end->modify('-366 days');
                $start = clone $end;
                $start->modify('-364 days');
                break;

            case 'prev-prev-month':
                $first_of_month = new \DateTime('first day of -3 months', new \DateTimeZone('Asia/Tokyo'));
                $last_of_month  = new \DateTime('last day of -3 months', new \DateTimeZone('Asia/Tokyo'));
                $start = $first_of_month;
                $end   = $last_of_month;
                break;

            case 'prev-month':
            default:
                $first_of_month = new \DateTime('first day of -2 months', new \DateTimeZone('Asia/Tokyo'));
                $last_of_month  = new \DateTime('last day of -2 months', new \DateTimeZone('Asia/Tokyo'));
                $start = $first_of_month;
                $end   = $last_of_month;
                break;
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end'   => $end->format('Y-m-d'),
        ];
    }
}
