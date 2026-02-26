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
 *
 * @package GCREV_INSIGHT
 * @since   2.0.0
 */
class Gcrev_GSC_Fetcher {

    /**
     * @var Gcrev_Config
     */
    private $config;

    /**
     * @param Gcrev_Config $config
     */
    public function __construct( Gcrev_Config $config ) {
        $this->config = $config;
    }

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

        $req = new SearchAnalyticsQueryRequest();
        $req->setStartDate($start);
        $req->setEndDate($end);
        $req->setDimensions(['query']);
        $req->setRowLimit(200);

        $resp = $service->searchanalytics->query($site_url, $req);
        $rows = $resp->getRows() ?: [];

        $total_impressions = 0;
        $total_clicks = 0;

        $keywords = [];
        foreach ($rows as $row) {
            $query = $row->getKeys()[0] ?? '';
            $impr  = (int) ($row->getImpressions() ?? 0);
            $click = (int) ($row->getClicks() ?? 0);
            $ctr   = (float) ($row->getCtr() ?? 0);
            $pos   = (float) ($row->getPosition() ?? 0);

            $total_impressions += $impr;
            $total_clicks += $click;

            $keywords[] = [
                'query'       => $query,
                'impressions' => number_format($impr),
                'clicks'      => number_format($click),
                'ctr'         => number_format($ctr * 100, 1) . '%',
                'position'    => number_format($pos, 1),
                '_impressions'=> $impr,
                '_clicks'     => $click,
                '_ctr'        => $ctr,
                '_position'   => $pos,
            ];
        }

        usort($keywords, fn($a, $b) => $b['_impressions'] <=> $a['_impressions']);

        $average_ctr = $total_impressions > 0 ? ($total_clicks / $total_impressions) * 100 : 0;

        return [
            'total' => [
                'impressions' => number_format($total_impressions),
                'clicks'      => number_format($total_clicks),
                'ctr'         => number_format($average_ctr, 1) . '%',
            ],
            'keywords' => $keywords,
        ];
    }
    /**
     * デバイス別の詳細メトリクスを取得
     * 
     * @param string $property_id GA4プロパティID
     * @param string $start 開始日 (YYYY-MM-DD)
     * @param string $end 終了日 (YYYY-MM-DD)
     * @return array デバイス別詳細データ
     */
}
