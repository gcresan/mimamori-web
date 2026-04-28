<?php
// FILE: inc/gcrev-api/modules/class-strategy-data-aggregator.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Strategy_Data_Aggregator' ) ) { return; }

/**
 * Gcrev_Strategy_Data_Aggregator
 *
 * 戦略レポート生成のために、対象月の GA4 / GSC データを集約して
 * AI 用に「要約済みの数値・文字列」に圧縮する。生レスポンスはAIに渡さない。
 *
 * 既存 fetcher を組み合わせるだけで、新しい外部API呼び出しは行わない。
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Gcrev_Strategy_Data_Aggregator {

    private Gcrev_Config      $config;
    private Gcrev_GA4_Fetcher $ga4;
    private Gcrev_GSC_Fetcher $gsc;

    public function __construct( Gcrev_Config $config, Gcrev_GA4_Fetcher $ga4, Gcrev_GSC_Fetcher $gsc ) {
        $this->config = $config;
        $this->ga4    = $ga4;
        $this->gsc    = $gsc;
    }

    /**
     * 対象月のデータを集約する。
     *
     * 戻り値（プロンプトビルダーで参照する形）:
     *   period:   { from, to, prev_from, prev_to, year_month }
     *   ga4:      { current: [...], previous: [...], deltas: [...] }
     *   gsc:      { total: { impressions, clicks, ctr }, top_keywords: [...] }
     *   meta:     { ga4_property_id, gsc_site_url }
     *   warnings: [string,...]   // 一部 fetch が失敗した場合の理由
     *
     * @throws \RuntimeException ユーザー設定が無いなど致命的な場合のみ
     */
    public function collect( int $user_id, string $year_month ): array {
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $year_month ) ) {
            throw new \RuntimeException( 'invalid year_month: ' . $year_month );
        }

        $period = $this->build_period( $year_month );
        $warnings = [];

        // GA4 / GSC のユーザー設定（無ければ throw する）
        $user_cfg = $this->config->get_user_config( $user_id );
        $ga4_id   = $user_cfg['ga4_id'];
        $gsc_url  = $user_cfg['gsc_url'];

        // 海外アクセス除外フィルタ（既存仕様の踏襲: report_exclude_foreign user meta）
        $exclude_foreign = (bool) get_user_meta( $user_id, 'report_exclude_foreign', true );
        if ( $exclude_foreign ) {
            $this->ga4->set_country_filter( 'Japan' );
        }

        // GA4 — 当月 / 前月
        $ga4_current  = $this->safe_ga4_summary( $ga4_id, $period['from'],      $period['to'],      $warnings, '当月' );
        $ga4_previous = $this->safe_ga4_summary( $ga4_id, $period['prev_from'], $period['prev_to'], $warnings, '前月' );

        // GSC — 当月のみ（top キーワード抽出が主目的）
        $gsc_current  = $this->safe_gsc_data( $gsc_url, $period['from'], $period['to'], $warnings );

        // 前月比デルタ
        $deltas = $this->build_deltas( $ga4_current, $ga4_previous );

        return [
            'period'   => $period,
            'ga4'      => [
                'current'  => $ga4_current,
                'previous' => $ga4_previous,
                'deltas'   => $deltas,
            ],
            'gsc'      => [
                'total'        => $gsc_current['total'] ?? [],
                'top_keywords' => $this->top_keywords( $gsc_current['keywords'] ?? [], 20 ),
            ],
            'meta'     => [
                'ga4_property_id' => $ga4_id,
                'gsc_site_url'    => $gsc_url,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * year_month から当月期間 + 前月期間を生成
     */
    private function build_period( string $year_month ): array {
        $from  = ( new \DateTimeImmutable( $year_month . '-01', wp_timezone() ) );
        $to    = $from->modify( 'last day of this month' );
        $pfrom = $from->modify( '-1 month' );
        $pto   = $pfrom->modify( 'last day of this month' );

        return [
            'year_month' => $year_month,
            'from'       => $from->format( 'Y-m-d' ),
            'to'         => $to->format( 'Y-m-d' ),
            'prev_from'  => $pfrom->format( 'Y-m-d' ),
            'prev_to'    => $pto->format( 'Y-m-d' ),
        ];
    }

    private function safe_ga4_summary( string $ga4_id, string $from, string $to, array &$warnings, string $label ): array {
        try {
            return $this->ga4->fetch_ga4_summary( $ga4_id, $from, $to );
        } catch ( \Throwable $e ) {
            $warnings[] = "GA4 ({$label}) 取得失敗: " . $e->getMessage();
            file_put_contents(
                '/tmp/gcrev_strategy_debug.log',
                date( 'Y-m-d H:i:s' ) . " ga4_summary failed range={$from}~{$to}: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return $this->empty_ga4_summary();
        }
    }

    private function safe_gsc_data( string $site_url, string $from, string $to, array &$warnings ): array {
        try {
            return $this->gsc->fetch_gsc_data( $site_url, $from, $to );
        } catch ( \Throwable $e ) {
            $warnings[] = 'GSC 取得失敗: ' . $e->getMessage();
            file_put_contents(
                '/tmp/gcrev_strategy_debug.log',
                date( 'Y-m-d H:i:s' ) . " gsc_data failed range={$from}~{$to}: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return [ 'total' => [], 'keywords' => [] ];
        }
    }

    private function empty_ga4_summary(): array {
        return [
            'pageViews'      => '0', 'sessions' => '0', 'users' => '0',
            'newUsers'       => '0', 'returningUsers' => '0',
            'avgDuration'    => '0', 'conversions' => '0',
            'engagedSessions'=> '0', 'engagementRate' => '0%',
            '_pageViews'     => 0, '_sessions' => 0, '_users' => 0,
            '_newUsers'      => 0, '_returningUsers' => 0, '_avgDuration' => 0,
            '_conversions'   => 0, '_engagedSessions' => 0, '_engagementRate' => 0,
        ];
    }

    /**
     * 主要メトリクスの前月比デルタを計算する。
     */
    private function build_deltas( array $cur, array $prev ): array {
        $keys = [
            'sessions', 'users', 'pageViews',
            'conversions', 'newUsers', 'returningUsers',
            'engagedSessions',
        ];
        $out = [];
        foreach ( $keys as $k ) {
            $cur_v  = (int) ( $cur['_' . $k] ?? 0 );
            $prev_v = (int) ( $prev['_' . $k] ?? 0 );
            $out[ $k ] = [
                'current'   => $cur_v,
                'previous'  => $prev_v,
                'diff'      => $cur_v - $prev_v,
                'pct'       => $prev_v > 0 ? round( ( ( $cur_v - $prev_v ) / $prev_v ) * 100, 1 ) : null,
            ];
        }
        return $out;
    }

    /**
     * GSC キーワードを上位 N 件に絞る（impressions 降順、AI に渡しすぎない）
     */
    private function top_keywords( array $keywords, int $limit ): array {
        $top = array_slice( $keywords, 0, $limit );
        $out = [];
        foreach ( $top as $k ) {
            $out[] = [
                'query'       => (string) ( $k['query']       ?? '' ),
                'impressions' => (int)    ( $k['_impressions'] ?? 0 ),
                'clicks'      => (int)    ( $k['_clicks']     ?? 0 ),
                'ctr'         => (string) ( $k['ctr']         ?? '' ),
                'position'    => (string) ( $k['position']    ?? '' ),
            ];
        }
        return $out;
    }
}
