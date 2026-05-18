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

        $reviews      = self::fetch_reviews_summary( $user_id, $period['end'], $previous['end'] );
        $ranks        = self::fetch_ranks( $user_id, $period['start'], $period['end'] );
        $keywords     = self::fetch_keywords( $user_id, $period['start'], $period['end'], 20 );
        $keywords_prev = self::fetch_keywords( $user_id, $previous['start'], $previous['end'], 200 );
        $keywords_diff = self::compute_keywords_diff( $keywords, $keywords_prev, 10 );
        $keywords_info = self::get_keywords_resolution_info( $user_id, $period['start'] );

        return [
            'period'        => $period,
            'previous'      => $previous,
            'metrics'       => $metrics,
            'metrics_prev'  => $metrics_prev,
            'reviews'       => $reviews,
            'ranks'         => $ranks,
            'keywords'      => $keywords,
            'keywords_diff' => $keywords_diff,
            'keywords_info' => $keywords_info,
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
     * クチコミサマリ: 件数 + 平均評価 + 当月/前月の新規クチコミ数
     *
     * gcrev_meo_results に日次で `reviews_count` のスナップショットが残るので、
     *   - 当月末時点の累計
     *   - 前月末時点の累計
     *   - 前々月末時点の累計
     * を取り出し、差分から「その月に増えたクチコミ件数」を算出する。
     *
     * 各 *_has_baseline フラグは「比較元のスナップショットがあるかどうか」を表す。
     * false の場合、delta_* は 0 のままなので UI 側で「データなし(—)」と表示する。
     *
     * @param int    $user_id
     * @param string $period_end    YYYY-MM-DD (当月末)
     * @param string $previous_end  YYYY-MM-DD (前月末)
     */
    private static function fetch_reviews_summary( int $user_id, string $period_end = '', string $previous_end = '' ): array {
        $default = [
            'count'                   => 0,
            'average_rating'          => 0.0,
            'count_curr_end'          => 0,
            'count_prev_end'          => 0,
            'count_prev2_end'         => 0,
            'delta_curr'              => 0,
            'delta_prev'              => 0,
            'delta_curr_has_baseline' => false,
            'delta_prev_has_baseline' => false,
        ];

        global $wpdb;
        $meo_table = $wpdb->prefix . 'gcrev_meo_results';

        // 全体の最新サマリ (累計件数 + 平均評価)
        $latest = $wpdb->get_row( $wpdb->prepare(
            "SELECT rating, reviews_count
               FROM {$meo_table}
              WHERE user_id = %d
                AND rating IS NOT NULL
                AND reviews_count IS NOT NULL
              ORDER BY fetch_date DESC, id DESC
              LIMIT 1",
            $user_id
        ), ARRAY_A );

        $count          = 0;
        $average_rating = 0.0;
        if ( is_array( $latest ) ) {
            $count          = (int) ( $latest['reviews_count'] ?? 0 );
            $average_rating = (float) ( $latest['rating'] ?? 0 );
        } else {
            // フォールバック: user_meta / transient
            $option_value = get_user_meta( $user_id, '_gcrev_gbp_reviews_summary', true );
            if ( is_array( $option_value ) ) {
                $count          = (int) ( $option_value['count'] ?? 0 );
                $average_rating = (float) ( $option_value['average_rating'] ?? 0 );
            } else {
                $cached = get_transient( 'gcrev_gbp_reviews_summary_' . $user_id );
                if ( is_array( $cached ) ) {
                    $count          = (int) ( $cached['count'] ?? 0 );
                    $average_rating = (float) ( $cached['average_rating'] ?? 0 );
                }
            }
        }

        // 月別 delta 計算用: 前々月末日も算出
        $prev2_end = '';
        if ( $previous_end !== '' ) {
            try {
                $prev_dt    = new \DateTimeImmutable( $previous_end, wp_timezone() );
                $prev2_end  = $prev_dt->modify( 'last day of previous month' )->format( 'Y-m-d' );
            } catch ( \Throwable $e ) {
                $prev2_end = '';
            }
        }

        // 指定日 (含む) までで最新の reviews_count スナップショットを返すクロージャ
        $snapshot_at_or_before = static function ( string $on_or_before ) use ( $wpdb, $meo_table, $user_id ): ?int {
            if ( $on_or_before === '' ) return null;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT reviews_count
                   FROM {$meo_table}
                  WHERE user_id = %d
                    AND reviews_count IS NOT NULL
                    AND fetch_date <= %s
                  ORDER BY fetch_date DESC, id DESC
                  LIMIT 1",
                $user_id, $on_or_before
            ), ARRAY_A );
            if ( ! is_array( $row ) ) return null;
            return (int) $row['reviews_count'];
        };

        $count_curr_end  = $period_end   !== '' ? $snapshot_at_or_before( $period_end )   : null;
        $count_prev_end  = $previous_end !== '' ? $snapshot_at_or_before( $previous_end ) : null;
        $count_prev2_end = $prev2_end    !== '' ? $snapshot_at_or_before( $prev2_end )    : null;

        $delta_curr_has_baseline = ( $count_curr_end !== null && $count_prev_end  !== null );
        $delta_prev_has_baseline = ( $count_prev_end !== null && $count_prev2_end !== null );

        $delta_curr = $delta_curr_has_baseline ? max( 0, $count_curr_end - $count_prev_end )  : 0;
        $delta_prev = $delta_prev_has_baseline ? max( 0, $count_prev_end - $count_prev2_end ) : 0;

        return [
            'count'                   => $count,
            'average_rating'          => $average_rating,
            'count_curr_end'          => (int) ( $count_curr_end ?? 0 ),
            'count_prev_end'          => (int) ( $count_prev_end ?? 0 ),
            'count_prev2_end'         => (int) ( $count_prev2_end ?? 0 ),
            'delta_curr'              => $delta_curr,
            'delta_prev'              => $delta_prev,
            'delta_curr_has_baseline' => $delta_curr_has_baseline,
            'delta_prev_has_baseline' => $delta_prev_has_baseline,
        ];
    }

    /**
     * 順位データを期間内で取得。
     * gcrev_meo_results から「マップ順位 (maps_rank)」「地域順位 (finder_rank)」の
     * 当月最新と前月最新を取得する。
     *
     * 既定: device='mobile', base_mode='business' (自社中心)。
     */
    private static function fetch_ranks( int $user_id, string $start, string $end ): array {
        global $wpdb;
        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_meo_results';

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword, location_code FROM {$kw_table}
              WHERE user_id = %d AND enabled = 1 ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        $start_dt   = new \DateTimeImmutable( $start, wp_timezone() );
        $prev_start = $start_dt->modify( 'first day of last month' )->format( 'Y-m-d' );
        $prev_end   = $start_dt->modify( 'last day of last month' )->format( 'Y-m-d' );

        $device    = 'mobile';
        $base_mode = 'business';

        $out = [];
        foreach ( $keywords as $kw ) {
            $kw_id = (int) $kw['id'];

            // 当月最新
            $latest = $wpdb->get_row( $wpdb->prepare(
                "SELECT maps_rank, finder_rank, fetch_date
                   FROM {$res_table}
                  WHERE user_id = %d AND keyword_id = %d
                    AND device = %s AND base_mode = %s
                    AND fetch_date BETWEEN %s AND %s
                  ORDER BY fetch_date DESC, id DESC LIMIT 1",
                $user_id, $kw_id, $device, $base_mode, $start, $end
            ), ARRAY_A );

            // 前月最新
            $prev = $wpdb->get_row( $wpdb->prepare(
                "SELECT maps_rank, finder_rank, fetch_date
                   FROM {$res_table}
                  WHERE user_id = %d AND keyword_id = %d
                    AND device = %s AND base_mode = %s
                    AND fetch_date BETWEEN %s AND %s
                  ORDER BY fetch_date DESC, id DESC LIMIT 1",
                $user_id, $kw_id, $device, $base_mode, $prev_start, $prev_end
            ), ARRAY_A );

            $maps        = $latest && $latest['maps_rank']   !== null ? (int) $latest['maps_rank']   : null;
            $maps_prev   = $prev   && $prev['maps_rank']     !== null ? (int) $prev['maps_rank']     : null;
            $finder      = $latest && $latest['finder_rank'] !== null ? (int) $latest['finder_rank'] : null;
            $finder_prev = $prev   && $prev['finder_rank']   !== null ? (int) $prev['finder_rank']   : null;

            $out[] = [
                'keyword'          => (string) $kw['keyword'],
                'location_code'    => (int) $kw['location_code'],
                // 後方互換: rank = マップ順位
                'rank'             => $maps,
                'rank_prev'        => $maps_prev,
                // 明示化したフィールド
                'maps_rank'        => $maps,
                'maps_rank_prev'   => $maps_prev,
                'finder_rank'      => $finder,
                'finder_rank_prev' => $finder_prev,
                'fetched_date'     => $latest['fetch_date'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * GBP 検索キーワード TOP N。
     *
     * GBP Performance API の月次データは MEO ダッシュボード REST が
     * `transient: gcrev_meo_{user_id}_{period}` の `search_keywords` 配下に
     * 「過去6ヶ月分のキーワード × 月別インプレッション」をまとめて保存している。
     *
     * 構造:
     *   search_keywords.months  = ['2025/12', '2026/01', ...]
     *   search_keywords.keywords[] = [ 'keyword' => string, 'monthly' => [int...], 'total' => int ]
     *
     * 指定期間 ($start 由来の年月) に該当する month_idx を引いて、各キーワードの
     * その月のインプレッション数を取り出して TOP N で返す。
     *
     * cache が無ければ MEO ダッシュボード REST を内部呼び出ししてキャッシュを温める。
     */
    private static function fetch_keywords( int $user_id, string $start, string $end, int $limit ): array {
        $dt           = new \DateTimeImmutable( $start, wp_timezone() );
        // cache.months は "2026/04" (ゼロパディング有り) 形式なので合わせる
        $target_label = $dt->format( 'Y/m' );

        $cache = self::load_search_keywords_cache( $user_id );
        if ( ! is_array( $cache ) || empty( $cache['months'] ) || empty( $cache['keywords'] ) ) {
            return [];
        }

        $months    = array_values( (array) $cache['months'] );
        $month_idx = array_search( $target_label, $months, true );

        // 対象月のデータが無ければ、cache.months の最新月 (末尾) にフォールバック
        $fallback_used = false;
        $effective_label = $target_label;
        if ( $month_idx === false ) {
            $month_idx = count( $months ) - 1;
            if ( $month_idx < 0 ) { return []; }
            $effective_label = (string) $months[ $month_idx ];
            $fallback_used   = true;
        }

        // 対象月の値が 0 (=閾値未満) のキーワードもタイブレーク用に合計値を持たせて含める。
        // 0 のキーワードしか残らないと「データなし」と紛らわしいので、その月で値 0 でも、
        // 全期間合計が 0 でなければ TOP リストに残す (GBP の閾値未満キーワードを救う)。
        $out = [];
        foreach ( (array) $cache['keywords'] as $k ) {
            $monthly  = isset( $k['monthly'] ) && is_array( $k['monthly'] ) ? $k['monthly'] : [];
            $val      = (int) ( $monthly[ $month_idx ] ?? 0 );
            $total    = isset( $k['total'] ) && is_numeric( $k['total'] ) ? (int) $k['total'] : array_sum( array_map( 'intval', $monthly ) );
            // その月とその全期間がともに 0 のものはまったく登場しないキーワードなので除外
            if ( $val === 0 && $total === 0 ) {
                continue;
            }
            $out[] = [
                'keyword' => (string) ( $k['keyword'] ?? '' ),
                'value'   => $val,
                '_total'  => $total,
            ];
        }

        // 対象月の値 DESC → 同値なら全期間合計 DESC で並べ、対象月 0 のキーワードも含めて表示
        usort( $out, static function ( $a, $b ) {
            if ( $a['value'] !== $b['value'] ) { return $b['value'] - $a['value']; }
            return $b['_total'] - $a['_total'];
        } );

        $top = array_slice( $out, 0, $limit );
        // 内部用キーは外して返す
        foreach ( $top as &$row ) { unset( $row['_total'] ); }
        return $top;
    }

    /**
     * GBP 検索キーワードの「対象月にデータがあるか / フォールバックでどの月を返したか」を返す。
     * page-meo-report.php から呼ばれ、notice 表示に使う。
     *
     * @return array { found:bool, requested:string, served:string, has_any_data:bool }
     */
    public static function get_keywords_resolution_info( int $user_id, string $start ): array {
        $dt           = new \DateTimeImmutable( $start, wp_timezone() );
        // cache.months は "2026/04" (ゼロパディング有り) 形式なので合わせる
        $target_label = $dt->format( 'Y/m' );

        $cache = self::load_search_keywords_cache( $user_id );
        if ( ! is_array( $cache ) || empty( $cache['months'] ) || empty( $cache['keywords'] ) ) {
            return [
                'found'        => false,
                'requested'    => $target_label,
                'served'       => '',
                'has_any_data' => false,
            ];
        }

        $months = array_values( (array) $cache['months'] );
        $idx    = array_search( $target_label, $months, true );
        if ( $idx !== false ) {
            return [
                'found'        => true,
                'requested'    => $target_label,
                'served'       => $target_label,
                'has_any_data' => true,
            ];
        }
        $latest = end( $months );
        return [
            'found'        => false,
            'requested'    => $target_label,
            'served'       => (string) $latest,
            'has_any_data' => true,
        ];
    }

    /**
     * MEOダッシュボード transient から search_keywords ブロックを取得。
     * 複数の period キャッシュを順に試し、なければ /meo/dashboard?period=prev-month を
     * 内部 REST 呼び出しして温める。
     */
    private static function load_search_keywords_cache( int $user_id ): ?array {
        $periods = [ 'prev-month', 'prev-prev-month', 'last30', 'last90', 'last180', 'last365' ];

        foreach ( $periods as $p ) {
            $t = get_transient( 'gcrev_meo_' . $user_id . '_' . $p );
            if ( is_array( $t ) && ! empty( $t['search_keywords']['months'] ) ) {
                return $t['search_keywords'];
            }
        }

        // キャッシュなし → REST を内部呼び出ししてキャッシュを温める
        if ( function_exists( 'rest_do_request' ) ) {
            $req = new \WP_REST_Request( 'GET', '/gcrev/v1/meo/dashboard' );
            $req->set_param( 'period', 'prev-month' );
            // ユーザーコンテキストを保証
            $prev_user = get_current_user_id();
            if ( $prev_user !== $user_id ) {
                wp_set_current_user( $user_id );
            }
            $res = rest_do_request( $req );
            if ( $prev_user !== $user_id ) {
                wp_set_current_user( $prev_user );
            }
            if ( ! $res->is_error() ) {
                $t = get_transient( 'gcrev_meo_' . $user_id . '_prev-month' );
                if ( is_array( $t ) && ! empty( $t['search_keywords']['months'] ) ) {
                    return $t['search_keywords'];
                }
                // transient に書かれていないケース: レスポンス本体に search_keywords が乗ることもある
                $data = $res->get_data();
                if ( is_array( $data ) && ! empty( $data['search_keywords']['months'] ) ) {
                    return $data['search_keywords'];
                }
            }
        }

        return null;
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
