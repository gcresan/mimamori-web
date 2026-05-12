<?php
/**
 * Gcrev_Meo_Report_Service
 *
 * MEO月次レポート用にデータを集約するサービス層。
 *
 *   入力: user_id, year, month
 *   出力: 表示回数 / 行動数 / クチコミ / 順位 / キーワードTOP20
 *         + 前月比 (M/M comparison) を1メソッドで返す
 *
 * 既存の Gcrev_Insight_API を経由してメトリクスを取得し、
 * 順位・キーワードは DB から直接読む。
 *
 * @package Mimamori_Web
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Meo_Report_Service' ) ) { return; }

class Gcrev_Meo_Report_Service {

    /**
     * 月次レポートデータを構築する
     *
     * @param int $user_id
     * @param int $year   例: 2026
     * @param int $month  1..12
     * @return array{
     *   period: array{year:int,month:int,start:string,end:string,label:string},
     *   previous: array,
     *   metrics: array,         // 当月 KPI
     *   metrics_prev: array,    // 前月 KPI
     *   reviews: array,         // クチコミサマリ
     *   ranks: array,           // 順位 (キーワード別)
     *   keywords: array,        // GBP 検索キーワード TOP20
     *   keywords_diff: array    // 増加キーワード TOP10 (前月比)
     * }
     */
    public static function build_report( int $user_id, int $year, int $month ): array {
        $start_dt = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), wp_timezone() );
        $end_dt   = $start_dt->modify( 'last day of this month 23:59:59' );

        $prev_start = $start_dt->modify( 'first day of last month 00:00:00' );
        $prev_end   = $start_dt->modify( 'last day of last month 23:59:59' );

        $period = [
            'year'  => $year,
            'month' => $month,
            'start' => $start_dt->format( 'Y-m-d' ),
            'end'   => $end_dt->format( 'Y-m-d' ),
            'label' => $start_dt->format( 'Y年n月' ),
        ];
        $previous = [
            'start' => $prev_start->format( 'Y-m-d' ),
            'end'   => $prev_end->format( 'Y-m-d' ),
            'label' => $prev_start->format( 'Y年n月' ),
        ];

        $metrics      = self::fetch_metrics( $user_id, $period['start'], $period['end'] );
        $metrics_prev = self::fetch_metrics( $user_id, $previous['start'], $previous['end'] );

        $reviews      = self::fetch_reviews_summary( $user_id );
        $ranks        = self::fetch_ranks( $user_id, $period['start'], $period['end'] );
        $keywords     = self::fetch_keywords( $user_id, $period['start'], $period['end'], 20 );
        $keywords_prev = self::fetch_keywords( $user_id, $previous['start'], $previous['end'], 200 );
        $keywords_diff = self::compute_keywords_diff( $keywords, $keywords_prev, 10 );

        return [
            'period'        => $period,
            'previous'      => $previous,
            'metrics'       => $metrics,
            'metrics_prev'  => $metrics_prev,
            'reviews'       => $reviews,
            'ranks'         => $ranks,
            'keywords'      => $keywords,
            'keywords_diff' => $keywords_diff,
        ];
    }

    /**
     * 表示回数 / 行動数 を取得する。
     * Gcrev_Insight_API::fetch_meo_metrics_safe_public() を経由。
     *
     * 返却形式 (失敗時も0埋めで返す):
     *   [
     *     'desktop_impressions' => int, 'mobile_impressions' => int,
     *     'search_impressions'  => int, 'map_impressions'    => int,
     *     'website_clicks'      => int, 'call_clicks'        => int,
     *     'direction_clicks'    => int,
     *   ]
     */
    private static function fetch_metrics( int $user_id, string $start, string $end ): array {
        $default = [
            'desktop_impressions' => 0, 'mobile_impressions' => 0,
            'search_impressions'  => 0, 'map_impressions'    => 0,
            'website_clicks'      => 0, 'call_clicks'        => 0,
            'direction_clicks'    => 0,
        ];

        if ( ! class_exists( 'Gcrev_Insight_API' ) ) return $default;
        try {
            $api = new Gcrev_Insight_API( false );
            if ( ! method_exists( $api, 'fetch_meo_metrics_safe_public' ) ) return $default;
            $m = $api->fetch_meo_metrics_safe_public( $user_id, $start, $end );
            if ( ! is_array( $m ) ) return $default;
            return array_merge( $default, array_intersect_key( array_map( 'intval', $m ), $default ) );
        } catch ( \Throwable $e ) {
            return $default;
        }
    }

    /**
     * クチコミサマリ: 件数 + 平均評価
     */
    private static function fetch_reviews_summary( int $user_id ): array {
        $default = [ 'count' => 0, 'average_rating' => 0.0 ];

        global $wpdb;
        $option_value = get_user_meta( $user_id, '_gcrev_gbp_reviews_summary', true );
        if ( is_array( $option_value ) ) {
            return [
                'count'          => (int) ( $option_value['count'] ?? 0 ),
                'average_rating' => (float) ( $option_value['average_rating'] ?? 0 ),
            ];
        }

        // フォールバック: gbp_get_reviews_summary 相当のキャッシュキーを直接探す
        $cached = get_transient( 'gcrev_gbp_reviews_summary_' . $user_id );
        if ( is_array( $cached ) ) {
            return [
                'count'          => (int) ( $cached['count'] ?? 0 ),
                'average_rating' => (float) ( $cached['average_rating'] ?? 0 ),
            ];
        }
        return $default;
    }

    /**
     * 順位データを期間内で取得。キーワード × デバイス × スロット の最新値とその前月最新値。
     */
    private static function fetch_ranks( int $user_id, string $start, string $end ): array {
        global $wpdb;
        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_rank_results';

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword, location_code FROM {$kw_table}
              WHERE user_id = %d AND enabled = 1 ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        $out = [];
        foreach ( $keywords as $kw ) {
            $kw_id = (int) $kw['id'];
            // 期間内最新 (desktop)
            $latest = $wpdb->get_row( $wpdb->prepare(
                "SELECT rank_group, is_ranked, fetch_date, fetch_slot
                   FROM {$res_table}
                  WHERE keyword_id = %d AND device = 'desktop'
                    AND fetch_date BETWEEN %s AND %s
                  ORDER BY fetched_at DESC LIMIT 1",
                $kw_id, $start, $end
            ), ARRAY_A );

            // 前月最新 (前月比較用)
            $start_dt = new \DateTimeImmutable( $start, wp_timezone() );
            $prev_start = $start_dt->modify( 'first day of last month' )->format( 'Y-m-d' );
            $prev_end   = $start_dt->modify( 'last day of last month' )->format( 'Y-m-d' );
            $prev = $wpdb->get_row( $wpdb->prepare(
                "SELECT rank_group, is_ranked
                   FROM {$res_table}
                  WHERE keyword_id = %d AND device = 'desktop'
                    AND fetch_date BETWEEN %s AND %s
                  ORDER BY fetched_at DESC LIMIT 1",
                $kw_id, $prev_start, $prev_end
            ), ARRAY_A );

            $out[] = [
                'keyword'        => (string) $kw['keyword'],
                'location_code'  => (int) $kw['location_code'],
                'rank'           => $latest && (int) $latest['is_ranked'] === 1 ? (int) $latest['rank_group'] : null,
                'rank_prev'      => $prev   && (int) $prev['is_ranked']   === 1 ? (int) $prev['rank_group']   : null,
                'fetched_date'   => $latest['fetch_date'] ?? null,
                'fetched_slot'   => $latest['fetch_slot'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * GBP 検索キーワード TOP N。
     * 既存の検索キーワード集計は class-gcrev-api 内にあるが、依存削減のため
     * 専用テーブル _meo_search_terms があればそこから読み、なければ user_meta キャッシュから。
     */
    private static function fetch_keywords( int $user_id, string $start, string $end, int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_meo_search_terms';
        $has_table = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $table
        ) );
        if ( $has_table > 0 ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT search_term, SUM(value) AS total
                   FROM {$table}
                  WHERE user_id = %d AND period_start >= %s AND period_end <= %s
                  GROUP BY search_term
                  ORDER BY total DESC
                  LIMIT %d",
                $user_id, $start, $end, $limit
            ), ARRAY_A );
            return array_map( static fn( $r ) => [
                'keyword' => (string) $r['search_term'],
                'value'   => (int) $r['total'],
            ], $rows ?: [] );
        }

        // フォールバック: user_meta に保存されたキャッシュから (last 30 days)
        $cache = get_user_meta( $user_id, '_gcrev_search_keywords_cache', true );
        if ( is_array( $cache ) && ! empty( $cache['items'] ) ) {
            $items = array_slice( $cache['items'], 0, $limit );
            return array_map( static fn( $r ) => [
                'keyword' => (string) ( $r['keyword'] ?? $r['search_term'] ?? '' ),
                'value'   => (int) ( $r['value'] ?? $r['count'] ?? 0 ),
            ], $items );
        }
        return [];
    }

    /**
     * 増加キーワード分析: 今月 - 前月 で増加量が大きい順に top N。
     */
    private static function compute_keywords_diff( array $current, array $previous, int $top_n = 10 ): array {
        $prev_map = [];
        foreach ( $previous as $p ) {
            $prev_map[ $p['keyword'] ] = (int) $p['value'];
        }
        $diffs = [];
        foreach ( $current as $c ) {
            $kw = $c['keyword'];
            $now = (int) $c['value'];
            $prev = $prev_map[ $kw ] ?? 0;
            $delta = $now - $prev;
            if ( $delta > 0 ) {
                $diffs[] = [
                    'keyword'    => $kw,
                    'value'      => $now,
                    'value_prev' => $prev,
                    'delta'      => $delta,
                ];
            }
        }
        usort( $diffs, static fn( $a, $b ) => $b['delta'] - $a['delta'] );
        return array_slice( $diffs, 0, $top_n );
    }

    /**
     * 前月比 % 計算 (-100..+∞)。前月0なら +100%扱い (新規)。
     */
    public static function pct_change( int $current, int $previous ): array {
        if ( $previous === 0 ) {
            return [ 'pct' => $current > 0 ? 100.0 : 0.0, 'is_new' => $current > 0 ];
        }
        $pct = ( $current - $previous ) * 100 / $previous;
        return [ 'pct' => round( $pct, 1 ), 'is_new' => false ];
    }

    /**
     * CSV 用に2次元配列を CSV 文字列にエンコード (BOM付き UTF-8)。
     */
    public static function to_csv( array $rows ): string {
        $fh = fopen( 'php://temp', 'r+' );
        if ( $fh === false ) return '';
        foreach ( $rows as $row ) {
            fputcsv( $fh, $row );
        }
        rewind( $fh );
        $csv = stream_get_contents( $fh ) ?: '';
        fclose( $fh );
        return "\xEF\xBB\xBF" . $csv; // UTF-8 BOM for Excel
    }
}
