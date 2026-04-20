<?php
// FILE: inc/gcrev-api/modules/class-gsc-fetcher.php

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_GSC_Fetcher') ) { return; }

use Google\Client as GoogleClient;
use Google\Service\Webmasters as GoogleWebmasters;
use Google\Service\Webmasters\SearchAnalyticsQueryRequest;

/**
 * Gcrev_GSC_Fetcher
 *
 * Google Search Console (GSC) API への通信を担当する。
 *
 * 責務:
 *   - Search Console API を使った検索パフォーマンスデータの取得
 *   - クライアント設定「解析対象URL条件」「解析除外URL条件」を page 単位で適用してから
 *     query で再集計する（/media/ 等の配下を除外できるようにする）
 *
 * @package Mimamori_Web
 * @since   2.0.0
 */
class Gcrev_GSC_Fetcher {

    /** Search Console API の RowLimit 上限 */
    private const ROW_LIMIT = 25000;

    /**
     * @var Gcrev_Config
     */
    private $config;

    /** @var array<string> 解析対象URL条件（前方一致 path プレフィックス） */
    private array $include_paths = [];

    /** @var array<string> 解析除外URL条件（前方一致 path プレフィックス） */
    private array $exclude_paths = [];

    /**
     * @param Gcrev_Config $config
     */
    public function __construct( Gcrev_Config $config ) {
        $this->config = $config;
    }

    /**
     * 解析対象URL条件（前方一致 path プレフィックス配列）を設定する。
     */
    public function set_include_paths_filter( array $paths ): void {
        $this->include_paths = self::normalize_paths( $paths );
    }

    /**
     * 解析除外URL条件（前方一致 path プレフィックス配列）を設定する。
     */
    public function set_exclude_paths_filter( array $paths ): void {
        $this->exclude_paths = self::normalize_paths( $paths );
    }

    /**
     * include / exclude フィルタをクリアする。
     */
    public function clear_path_filters(): void {
        $this->include_paths = [];
        $this->exclude_paths = [];
    }

    /**
     * フィルタが何かしら設定されているか
     */
    public function has_path_filters(): bool {
        return ! empty( $this->include_paths ) || ! empty( $this->exclude_paths );
    }

    /**
     * user_meta（_gcrev_include_paths / _gcrev_exclude_paths）から
     * 自動で path フィルタを適用する。
     */
    public function apply_user_path_filters( int $user_id ): void {
        if ( ! class_exists( 'Gcrev_Path_Filter' ) ) {
            return;
        }
        $f = Gcrev_Path_Filter::get_user_filters( $user_id );
        $this->set_include_paths_filter( $f['include'] );
        $this->set_exclude_paths_filter( $f['exclude'] );
    }

    /**
     * Search Console から検索パフォーマンスデータを取得する。
     *
     * 内部仕様:
     *   - dimensions = ['page','query'] で取得（行数上限まで page 別に分割される）
     *   - 取得した行に対し、include/exclude path 条件をアプリ側で適用
     *   - 残った行を query で再集計し、上位キーワードと合計値を返す
     *
     * これにより「クエリは入ってくるが、それが除外対象ページ由来なら集計しない」
     * を実現する（query 単独取得では不可能）。
     *
     * @return array{ total: array, keywords: array }
     */
    public function fetch_gsc_data(string $site_url, string $start, string $end): array {

        if ( class_exists( 'Gcrev_Rate_Limiter' ) ) {
            Gcrev_Rate_Limiter::check_and_wait( 'gsc' );
        }

        $sa_path = $this->config->get_service_account_path();
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $sa_path);

        $client = new GoogleClient();
        $client->setAuthConfig($sa_path);
        $client->addScope(GoogleWebmasters::WEBMASTERS_READONLY);

        $service = new GoogleWebmasters($client);

        $rows = $this->fetch_rows_paged( $service, $site_url, $start, $end );

        // include/exclude path 条件を適用しながら query で再集計
        $keywords = $this->aggregate_by_query( $rows );

        // 集計（合計 impressions / clicks）
        $total_impressions = 0;
        $total_clicks      = 0;
        foreach ( $keywords as $k ) {
            $total_impressions += (int) ( $k['_impressions'] ?? 0 );
            $total_clicks      += (int) ( $k['_clicks'] ?? 0 );
        }

        usort( $keywords, fn( $a, $b ) => $b['_impressions'] <=> $a['_impressions'] );

        $average_ctr = $total_impressions > 0 ? ( $total_clicks / $total_impressions ) * 100 : 0;

        return [
            'total' => [
                'impressions' => number_format( $total_impressions ),
                'clicks'      => number_format( $total_clicks ),
                'ctr'         => number_format( $average_ctr, 1 ) . '%',
            ],
            'keywords' => $keywords,
        ];
    }

    /**
     * Search Console から page+query 行を取得する（必要なら StartRow ページング）
     *
     * 安全のため最大 4 ページ（25000 × 4 = 100k 行）まで。
     * 通常のクライアント1月分はこれで十分カバーできる。
     */
    private function fetch_rows_paged( $service, string $site_url, string $start, string $end ): array {
        $all = [];
        $start_row      = 0;
        $max_iterations = 4;

        for ( $i = 0; $i < $max_iterations; $i++ ) {
            $req = new SearchAnalyticsQueryRequest();
            $req->setStartDate( $start );
            $req->setEndDate( $end );
            $req->setDimensions( [ 'page', 'query' ] );
            $req->setRowLimit( self::ROW_LIMIT );
            $req->setStartRow( $start_row );

            try {
                $resp = $service->searchanalytics->query( $site_url, $req );
            } catch ( \Throwable $e ) {
                file_put_contents( '/tmp/gcrev_gsc_debug.log',
                    date( 'Y-m-d H:i:s' ) . " GSC API ERROR: site={$site_url}, range={$start}~{$end}, msg=" . substr( $e->getMessage(), 0, 500 ) . "\n",
                    FILE_APPEND
                );
                throw $e;
            }

            $rows  = $resp->getRows() ?: [];
            $count = count( $rows );
            if ( $count === 0 ) {
                break;
            }

            foreach ( $rows as $row ) {
                $all[] = $row;
            }

            if ( $count < self::ROW_LIMIT ) {
                break;
            }
            $start_row += self::ROW_LIMIT;
        }

        return $all;
    }

    /**
     * page+query 行に対して include/exclude path 条件を適用しながら、query 単位で再集計する。
     *
     * average position は impressions 加重平均で計算する（GSC のデフォルト挙動と同じ）。
     */
    private function aggregate_by_query( array $rows ): array {
        $bucket = []; // query => [impressions, clicks, pos_weighted]

        foreach ( $rows as $row ) {
            $keys  = $row->getKeys() ?: [];
            $page  = isset( $keys[0] ) ? (string) $keys[0] : '';
            $query = isset( $keys[1] ) ? (string) $keys[1] : '';

            if ( $query === '' ) {
                continue;
            }

            // path フィルタ判定（フィルタ未設定なら常に通過）
            if ( ! $this->page_passes_filter( $page ) ) {
                continue;
            }

            $impr  = (int)   ( $row->getImpressions() ?? 0 );
            $click = (int)   ( $row->getClicks() ?? 0 );
            $pos   = (float) ( $row->getPosition() ?? 0 );

            if ( ! isset( $bucket[ $query ] ) ) {
                $bucket[ $query ] = [
                    'impressions'  => 0,
                    'clicks'       => 0,
                    'pos_weighted' => 0.0,
                ];
            }
            $bucket[ $query ]['impressions']  += $impr;
            $bucket[ $query ]['clicks']       += $click;
            $bucket[ $query ]['pos_weighted'] += $pos * $impr;
        }

        $keywords = [];
        foreach ( $bucket as $query => $agg ) {
            $impr  = (int) $agg['impressions'];
            $click = (int) $agg['clicks'];
            $ctr   = $impr > 0 ? ( $click / $impr ) : 0.0;
            $pos   = $impr > 0 ? ( $agg['pos_weighted'] / $impr ) : 0.0;

            $keywords[] = [
                'query'        => $query,
                'impressions'  => number_format( $impr ),
                'clicks'       => number_format( $click ),
                'ctr'          => number_format( $ctr * 100, 1 ) . '%',
                'position'     => number_format( $pos, 1 ),
                '_impressions' => $impr,
                '_clicks'      => $click,
                '_ctr'         => $ctr,
                '_position'    => $pos,
            ];
        }

        return $keywords;
    }

    private function page_passes_filter( string $page ): bool {
        if ( ! $this->has_path_filters() ) {
            return true;
        }
        if ( class_exists( 'Gcrev_Path_Filter' ) ) {
            return Gcrev_Path_Filter::matches( $page, $this->include_paths, $this->exclude_paths );
        }
        return true;
    }

    private static function normalize_paths( array $paths ): array {
        $out = [];
        foreach ( $paths as $p ) {
            $s = trim( (string) $p );
            if ( $s === '' ) continue;
            if ( $s[0] !== '/' ) {
                $s = '/' . $s;
            }
            $out[] = $s;
        }
        return array_values( array_unique( $out ) );
    }
}
