<?php
// FILE: inc/gcrev-api/modules/class-ga4-fetcher.php

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_GA4_Fetcher') ) { return; }

use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\OrderBy;

use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\FilterExpressionList;
use Google\Analytics\Data\V1beta\StringFilter;

/**
 * Gcrev_GA4_Fetcher
 *
 * Google Analytics 4 (GA4) Data API への通信を担当する。
 *
 * 責務:
 *   - GA4 Data API (BetaAnalyticsDataClient) を使ったデータ取得
 *   - サマリー / 日次シリーズ / 年齢・性別 / デバイス / 内訳 / KPI推移
 *   - ページタイトル推測・取得
 *   - キーイベント取得・選定
 *   - 分析ページ用: ページ詳細 / 地域詳細 / ソースデータ
 *
 * @package GCREV_INSIGHT
 * @since   2.0.0
 */
class Gcrev_GA4_Fetcher {

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

    /**
     * GA4 API 呼び出し前の共通準備（認証 + レートリミット）
     */
    private function prepare_ga4_call(): void {
        if ( class_exists( 'Gcrev_Rate_Limiter' ) ) {
            Gcrev_Rate_Limiter::check_and_wait( 'ga4' );
        }

        putenv( 'GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path() );
    }

    public function fetch_ga4_data(string $property_id, string $start, string $end, string $site_url = ''): array {

        $this->prepare_ga4_call();

        $client = new BetaAnalyticsDataClient();

        $request = new RunReportRequest([
            'property' => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
            'dimensions' => [ 
                new Dimension(['name' => 'pagePath']),
                new Dimension(['name' => 'pageTitle'])  // 実際のページタイトル（<title>タグ）を取得
            ],
            'metrics' => [
                new Metric(['name' => 'screenPageViews']),
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'engagedSessions']),
            ],
            'limit' => 200,
        ]);

        $response = $client->runReport($request);

        $total_pv = 0;
        $total_sessions = 0;
        $total_engaged = 0;
        $pages = [];

        foreach ($response->getRows() as $row) {
            $dimensions = $row->getDimensionValues();
            $path = $dimensions[0]->getValue();
            $pageTitle = $dimensions[1]->getValue();  // 実際のページタイトルを取得
            
            $pv   = (int) $row->getMetricValues()[0]->getValue();
            $sess = (int) $row->getMetricValues()[1]->getValue();
            $eng  = (int) $row->getMetricValues()[2]->getValue();

            $total_pv += $pv;
            $total_sessions += $sess;
            $total_engaged  += $eng;

            $title = '';
            if ($pageTitle && $pageTitle !== '(not set)') {
                $title = $pageTitle;
            } else {
                // まず実ページの<title>を取りに行く（取れなければguess）
                $fetched = $this->fetch_title_from_site($site_url, $path);
                $title = ($fetched && $fetched !== '') ? $fetched : $this->guess_page_title($path);
            }

            $pages[] = [
                'page'            => $path,
                'title'           => $title,  // 実際のページタイトルを使用
                'pageViews'       => number_format($pv),
                'sessions'        => number_format($sess),
                'engagedSessions' => number_format($eng),
                'conversions'     => number_format($eng),
                '_pageViews'      => $pv,
                '_sessions'       => $sess,
                '_engagedSessions'=> $eng,
            ];
        }

        usort($pages, fn($a, $b) => $b['_pageViews'] <=> $a['_pageViews']);

        foreach ($pages as $i => &$page) {
            $page['_percentage'] = $total_pv > 0 ? ($page['_pageViews'] / $total_pv) * 100 : 0;
            $page['percentage']  = number_format($page['_percentage'], 1) . '%';
            $page['_engagementRate'] = $page['_sessions'] > 0
                ? ($page['_engagedSessions'] / $page['_sessions']) * 100
                : 0;
            $page['engagementRate'] = number_format($page['_engagementRate'], 1) . '%';
        }
        unset($page);

        return [
            'total' => [
                'pageViews' => number_format($total_pv),
                'sessions'  => number_format($total_sessions),
                'engagedSessions' => number_format($total_engaged),
                'engagementRate'  => $total_sessions > 0
                    ? number_format(($total_engaged / $total_sessions) * 100, 1) . '%'
                    : '0.0%',
            ],
            'pages' => $pages,
        ];
    }

    // =========================================================
    // GA4: サマリー取得（KPI用）
    // =========================================================
    public function fetch_ga4_summary(string $property_id, string $start, string $end): array {

        $this->prepare_ga4_call();

        $client = new BetaAnalyticsDataClient();

        $request = new RunReportRequest([
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
            'metrics'     => [
                new Metric(['name' => 'screenPageViews']),
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'totalUsers']),
                new Metric(['name' => 'newUsers']),
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'engagedSessions']),
                new Metric(['name' => 'engagementRate']),
                new Metric(['name' => 'keyEvents']),  // CV数（GA4キーイベント）
            ],
            'limit' => 1,
        ]);

        $response = $client->runReport($request);
        $rows = $response->getRows();

        $pv   = 0;
        $sess = 0;
        $users = 0;
        $new  = 0;
        $dur  = 0.0;
        $engS = 0;
        $engR = 0.0;
        $conv = 0;

        $row0 = ($rows !== null && count($rows) > 0) ? $rows[0] : null;
        if ($row0 !== null) {
            $mv = $row0->getMetricValues();
            $pv   = (int)($mv[0]->getValue() ?? 0);
            $sess = (int)($mv[1]->getValue() ?? 0);
            $users= (int)($mv[2]->getValue() ?? 0);
            $new  = (int)($mv[3]->getValue() ?? 0);
            $dur  = (float)($mv[4]->getValue() ?? 0);
            $engS = (int)($mv[5]->getValue() ?? 0);
            $engR = (float)($mv[6]->getValue() ?? 0);
            $conv = (int)($mv[7]->getValue() ?? 0);
        }

        $returning = max(0, $users - $new);

        return [
            // 表示用
            'pageViews'      => number_format($pv),
            'sessions'       => number_format($sess),
            'users'          => number_format($users),
            'newUsers'       => number_format($new),
            'returningUsers' => number_format($returning),
            'avgDuration'    => (string)round($dur),
            'conversions'    => number_format($conv),

            
            'engagedSessions'   => number_format($engS),
            'engagementRate'    => number_format($engR * 100, 1) . '%',

            // 生値（trends 計算用）
            '_engagedSessions'  => $engS,
            '_engagementRate'   => $engR,
            // 生値（trends 計算用）
            '_pageViews'      => $pv,
            '_sessions'       => $sess,
            '_users'          => $users,
            '_newUsers'       => $new,
            '_returningUsers' => $returning,
            '_avgDuration'    => $dur,
            '_conversions'    => $conv,
        ];
    }

    // =========================================================
    // GA4: 日次シリーズ（スパークライン用：直近7日）
    // =========================================================
    public function fetch_ga4_daily_series(string $property_id, string $start, string $end): array {

        $tz = wp_timezone();
        $end_dt   = new DateTimeImmutable($end, $tz);
        $start_dt = new DateTimeImmutable($start, $tz);

                // 期間全体の推移を返す（ダッシュボードの5日ごとのポイント用）
        // ただし極端に長い期間の場合のために最大92日（約3ヶ月）に制限
        $max_days = 92;
        $diff_days = (int)$start_dt->diff($end_dt)->format('%a') + 1;
        if ($diff_days > $max_days) {
            $series_start = $end_dt->sub(new DateInterval('P' . ($max_days - 1) . 'D'));
        } else {
            $series_start = $start_dt;
        }

        $series_start_str = $series_start->format('Y-m-d');
        $series_end_str   = $end_dt->format('Y-m-d');

        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
        $client = new BetaAnalyticsDataClient();

        $request = new RunReportRequest([
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange(['start_date' => $series_start_str, 'end_date' => $series_end_str]) ],
            'dimensions'  => [ new Dimension(['name' => 'date']) ],
            'metrics'     => [
                new Metric(['name' => 'screenPageViews']),
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'totalUsers']),
                new Metric(['name' => 'newUsers']),
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'keyEvents']),  // CV数（GA4キーイベント）
            ],
            'limit' => 100,
        ]);

        $response = $client->runReport($request);

        
        $rows = [];

        foreach ($response->getRows() as $row) {
            $dateStr = $row->getDimensionValues()[0]->getValue(); // YYYYMMDD
            $mv = $row->getMetricValues();

            $pv    = (int)($mv[0]->getValue() ?? 0);
            $sess  = (int)($mv[1]->getValue() ?? 0);
            $users = (int)($mv[2]->getValue() ?? 0);
            $new   = (int)($mv[3]->getValue() ?? 0);
            $dur   = (float)($mv[4]->getValue() ?? 0);
            $conv  = (int)($mv[5]->getValue() ?? 0);

            $rows[$dateStr] = [
                'pv'   => $pv,
                'sess' => $sess,
                'users'=> $users,
                'new'  => $new,
                'dur'  => (int)round($dur),
                'conv' => $conv,
            ];
        }

        ksort($rows, SORT_STRING);

        $labels   = array_keys($rows);
        $pvVals   = [];
        $sessVals = [];
        $userVals = [];
        $newVals  = [];
        $retVals  = [];
        $durVals  = [];
        $convVals = [];

        foreach ($rows as $r) {
            $pvVals[]   = $r['pv'];
            $sessVals[] = $r['sess'];
            $userVals[] = $r['users'];
            $newVals[]  = $r['new'];
            $retVals[]  = max(0, $r['users'] - $r['new']);
            $durVals[]  = $r['dur'];
            $convVals[] = $r['conv'];
        }

        return [
            // labels は YYYYMMDD を返す（フロント側で整形）
            'pageViews'   => ['labels' => $labels, 'values' => $pvVals],
            'sessions'    => ['labels' => $labels, 'values' => $sessVals],
            'users'       => ['labels' => $labels, 'values' => $userVals],
            'newUsers'    => ['labels' => $labels, 'values' => $newVals],
            'returning'   => ['labels' => $labels, 'values' => $retVals],
            'duration'    => ['labels' => $labels, 'values' => $durVals],
            'conversions' => ['labels' => $labels, 'values' => $convVals],
        ];
    }


    // =========================================================
    // GA4: 年齢別（Google Signals 必須）
    // =========================================================
    public function fetch_ga4_age_breakdown(string $property_id, string $start, string $end): array {

        try {
            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
            $client = new BetaAnalyticsDataClient();

            $request = new RunReportRequest([
                'property'    => 'properties/' . $property_id,
                'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
                'dimensions'  => [ new Dimension(['name' => 'userAgeBracket']) ],
                'metrics'     => [
                    new Metric(['name' => 'sessions']),
                    // CV はサイトごとに定義が異なるので、まずは keyEvents（= GA4の「キーイベント」）を利用
                    // 未設定の場合は 0 になります
                    new Metric(['name' => 'keyEvents']),
                ],
                'limit' => 50,
            ]);

            $response = $client->runReport($request);

            $rows = [];
            $totalSessions = 0;

            foreach ($response->getRows() as $row) {
                $age = $row->getDimensionValues()[0]->getValue();
                $mv  = $row->getMetricValues();

                $sessions = (int)($mv[0]->getValue() ?? 0);
                $cv       = (int)($mv[1]->getValue() ?? 0);

                $totalSessions += $sessions;

                $rows[] = [
                    'name'     => $age ?: '(not set)',
                    'sessions' => number_format($sessions),
                    'cv'       => $cv,
                ];
            }

            // sessions の多い順
            usort($rows, function ($a, $b) {
                $as = (int)str_replace(',', '', (string)($a['sessions'] ?? '0'));
                $bs = (int)str_replace(',', '', (string)($b['sessions'] ?? '0'));
                return $bs <=> $as;
            });

            // percentage / cvr 付与
            foreach ($rows as &$r) {
                $sess = (int)str_replace(',', '', (string)($r['sessions'] ?? '0'));
                $pct  = $totalSessions > 0 ? ($sess / $totalSessions) * 100.0 : 0.0;
                $cv   = (int)($r['cv'] ?? 0);
                $r['percentage'] = round($pct, 1);
                $r['cvr']        = $sess > 0 ? round(($cv / $sess) * 100.0, 2) : 0.0;
            }
            unset($r);

            return $rows;

        } catch (Throwable $e) {
            // Google Signals 無効などで取得できないケースは空配列を返す
            error_log('[GCREV] fetch_ga4_age_breakdown error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 年齢別の詳細データを取得（年齢別アクセスページ用）
     * 
     * @param string $property_id GA4プロパティID
     * @param string $start 開始日 (YYYY-MM-DD)
     * @param string $end 終了日 (YYYY-MM-DD)
     * @return array 年齢別詳細データの配列
     */
    public function fetch_age_demographics(string $property_id, string $start, string $end): array {
        
        try {
            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
            $client = new BetaAnalyticsDataClient();
            
            $request = new RunReportRequest([
                'property'    => 'properties/' . $property_id,
                'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
                'dimensions'  => [ new Dimension(['name' => 'userAgeBracket']) ],
                'metrics'     => [
                    new Metric(['name' => 'sessions']),
                    new Metric(['name' => 'totalUsers']),
                    new Metric(['name' => 'screenPageViews']),
                    new Metric(['name' => 'averageSessionDuration']),
                    new Metric(['name' => 'bounceRate']),
                    new Metric(['name' => 'engagementRate']),
                    new Metric(['name' => 'keyEvents']), // コンバージョン
                ],
                'limit'       => 50,
            ]);
            
            $response = $client->runReport($request);
            
            $demographics = [];
            $total_sessions = 0;
            
            // 1回目: 合計セッション数を計算
            foreach ($response->getRows() as $row) {
                $sessions = (int)($row->getMetricValues()[0]->getValue() ?? 0);
                $total_sessions += $sessions;
            }
            
            // 2回目: 各年齢層のデータを整形
            foreach ($response->getRows() as $row) {
                $age_bracket = $row->getDimensionValues()[0]->getValue();
                
                // 年齢層が"unknown"または空の場合はスキップ
                if (empty($age_bracket) || $age_bracket === 'unknown' || $age_bracket === '(not set)') {
                    continue;
                }
                
                $sessions      = (int)($row->getMetricValues()[0]->getValue() ?? 0);
                $users         = (int)($row->getMetricValues()[1]->getValue() ?? 0);
                $pageViews     = (int)($row->getMetricValues()[2]->getValue() ?? 0);
                $avgDuration   = (float)($row->getMetricValues()[3]->getValue() ?? 0);
                $bounceRate    = (float)($row->getMetricValues()[4]->getValue() ?? 0);
                $engagementRate = (float)($row->getMetricValues()[5]->getValue() ?? 0);
                $conversions   = (int)($row->getMetricValues()[6]->getValue() ?? 0);
                
                // CVRの計算（0除算対策）
                $cvr = $sessions > 0 ? ($conversions / $sessions) * 100.0 : 0.0;
                
                $demographics[] = [
                    'age_range'        => $age_bracket,
                    'sessions'         => $sessions,
                    'users'            => $users,
                    'pageviews'        => $pageViews,
                    'avg_duration'     => $avgDuration, // 秒数
                    'bounce_rate'      => $bounceRate * 100.0, // パーセント表記に変換（GA4は0.0-1.0で返す）
                    'engagement_rate'  => $engagementRate * 100.0, // パーセント表記に変換
                    'conversions'      => $conversions,
                    'cvr'              => $cvr,
                ];
            }
            
            // セッション数の多い順にソート
            usort($demographics, function($a, $b) {
                return $b['sessions'] <=> $a['sessions'];
            });
            
            return $demographics;
            
        } catch (Throwable $e) {
            // Google Signals 無効などで取得できないケースは空配列を返す
            error_log('[GCREV] fetch_age_demographics error: ' . $e->getMessage());
            return [];
        }
    }


    // =========================================================
    // GA4: 性別 × 年齢（Google Signals 必須）
    // =========================================================
    public function fetch_ga4_gender_age_cross(string $property_id, string $start, string $end): array {

        try {
            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
            $client = new BetaAnalyticsDataClient();

            // ※ 年齢別詳細（fetch_age_demographics）と同じ指標定義に合わせる
            // - PV: screenPageViews
            // - 平均滞在時間: averageSessionDuration（秒）
            // - 直帰率: bounceRate（※このプロジェクトでは従来ロジックに合わせて *100 して返す）
            // - エンゲージメント率: engagementRate（※同上）
            // - CV: keyEvents（年齢別詳細と同じ）
            $request = new RunReportRequest([
                'property'    => 'properties/' . $property_id,
                'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
                'dimensions'  => [
                    new Dimension(['name' => 'userAgeBracket']),
                    new Dimension(['name' => 'userGender']),
                ],
                'metrics'     => [
                    new Metric(['name' => 'sessions']),
                    new Metric(['name' => 'screenPageViews']),
                    new Metric(['name' => 'averageSessionDuration']),
                    new Metric(['name' => 'bounceRate']),
                    new Metric(['name' => 'engagementRate']),
                    new Metric(['name' => 'keyEvents']),
                ],
                'limit'       => 500,
            ]);

            $response = $client->runReport($request);

            // age_range => ['male'=>metrics, 'female'=>metrics, 'other'=>metrics]
            $bucket = [];

            $empty = [
                'sessions' => 0,
                'pv'       => 0,
                'avg_sec'  => 0.0,
                'bounce'   => 0.0,
                'engage'   => 0.0,
                'conv'     => 0,
            ];

            foreach ($response->getRows() as $row) {
                $age    = (string)($row->getDimensionValues()[0]->getValue() ?? '');
                $gender = (string)($row->getDimensionValues()[1]->getValue() ?? '');

                // 年齢が不明は除外（必要なら残してもOK）
                if ($age === '' || $age === 'unknown' || $age === '(not set)') {
                    continue;
                }

                $sessions    = (int)($row->getMetricValues()[0]->getValue() ?? 0);
                $pv          = (int)($row->getMetricValues()[1]->getValue() ?? 0);
                $avgDuration = (float)($row->getMetricValues()[2]->getValue() ?? 0);
                $bounceRate  = (float)($row->getMetricValues()[3]->getValue() ?? 0);
                $engageRate  = (float)($row->getMetricValues()[4]->getValue() ?? 0);
                $conversions = (int)($row->getMetricValues()[5]->getValue() ?? 0);

                if (!isset($bucket[$age])) {
                    $bucket[$age] = [
                        'male'   => $empty,
                        'female' => $empty,
                        'other'  => $empty,
                    ];
                }

                // userGender は "male" / "female" / "unknown" が多い
                $key = 'other';
                switch (strtolower($gender)) {
                    case 'male':
                        $key = 'male';
                        break;
                    case 'female':
                        $key = 'female';
                        break;
                    default:
                        $key = 'other';
                        break;
                }

                // 念のため複数行で返るケースに備えて加算 + sessions加重平均
                $bucket[$age][$key]['pv']       += $pv;
                $bucket[$age][$key]['conv']     += $conversions;

                $prevSess  = (int)$bucket[$age][$key]['sessions'];
                $totalSess = $prevSess + $sessions;

                $bucket[$age][$key]['sessions'] = $totalSess;

                if ($totalSess > 0) {
                    $bucket[$age][$key]['avg_sec'] =
                        (($bucket[$age][$key]['avg_sec'] * $prevSess) + ($avgDuration * $sessions)) / $totalSess;

                    // 年齢別詳細と合わせて *100（現状ロジック踏襲）
                    $bouncePct = $bounceRate * 100.0;
                    $engagePct = $engageRate * 100.0;

                    $bucket[$age][$key]['bounce'] =
                        (($bucket[$age][$key]['bounce'] * $prevSess) + ($bouncePct * $sessions)) / $totalSess;

                    $bucket[$age][$key]['engage'] =
                        (($bucket[$age][$key]['engage'] * $prevSess) + ($engagePct * $sessions)) / $totalSess;
                }
            }

            // 表示順を固定
            $order = ['18-24','25-34','35-44','45-54','55-64','65+'];
            $out = [];

            foreach ($order as $age) {
                if (!isset($bucket[$age])) continue;
                $out[] = [
                    'age_range' => $age,
                    'male'      => $bucket[$age]['male'],
                    'female'    => $bucket[$age]['female'],
                    'other'     => $bucket[$age]['other'],
                ];
            }

            // 想定外の年齢ラベルも末尾に追加
            foreach ($bucket as $age => $vals) {
                if (in_array($age, $order, true)) continue;
                $out[] = [
                    'age_range' => $age,
                    'male'      => $vals['male'],
                    'female'    => $vals['female'],
                    'other'     => $vals['other'],
                ];
            }

            return $out;

        } catch (Throwable $e) {
            error_log('[GCREV] fetch_ga4_gender_age_cross error: ' . $e->getMessage());
            return [];
        }
    }



    // =========================================================
    // GA4: 内訳（デバイス/流入/地域）共通
    // =========================================================
    public function fetch_ga4_breakdown(string $property_id, string $start, string $end, string $dimension, string $metric): array {

        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
        $client = new BetaAnalyticsDataClient();

        $request = new RunReportRequest([
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
            'dimensions'  => [ new Dimension(['name' => $dimension]) ],
            'metrics'     => [ new Metric(['name' => $metric]) ],
            'limit'       => 10,
        ]);

        $response = $client->runReport($request);

        $rows = [];
        $total = 0;

        foreach ($response->getRows() as $row) {
            $name = $row->getDimensionValues()[0]->getValue();
            $val  = (int)($row->getMetricValues()[0]->getValue() ?? 0);
            $total += $val;
            $rows[] = ['name' => $name, 'value' => $val];
        }

        $out = [];
        foreach ($rows as $r) {
            $pct = $total > 0 ? ($r['value'] / $total) * 100.0 : 0.0;

            if ($dimension === 'deviceCategory') {
                $out[] = [
                    'device' => $r['name'],
                    'count'  => number_format($r['value']),
                    'percent'=> number_format($pct, 1) . '%',
                ];
            } elseif ($dimension === 'sessionMedium') {
                $out[] = [
                    'medium'     => $r['name'],
                    'sessions'   => number_format($r['value']),
                    'conversions'=> '0',
                    'cvr'        => '0.0%',
                ];
            } elseif ($dimension === 'city') {
                $out[] = [
                    'city'     => $r['name'],
                    'sessions' => number_format($r['value']),
                ];
            } else {
                $out[] = [
                    'name'     => $r['name'],
                    'sessions' => number_format($r['value']),
                    'percent'  => number_format($pct, 1) . '%',
                ];
            }
        }

        return $out;
    }


    // =========================================================
    // GSCデータ取得
    // =========================================================
    public function fetch_device_details(string $property_id, string $start, string $end): array {
        
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
        $client = new BetaAnalyticsDataClient();
        
        $request = new RunReportRequest([
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
            'dimensions'  => [ new Dimension(['name' => 'deviceCategory']) ],
            'metrics'     => [
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'totalUsers']),
                new Metric(['name' => 'screenPageViews']),
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'bounceRate']),
                new Metric(['name' => 'conversions']),
            ],
            'limit'       => 10,
        ]);
        
        $response = $client->runReport($request);
        
        $devices = [];
        $total_sessions = 0;
        
        // 1回目: 合計セッション数を計算
        foreach ($response->getRows() as $row) {
            $sessions = (int)($row->getMetricValues()[0]->getValue() ?? 0);
            $total_sessions += $sessions;
        }
        
        // 2回目: 各デバイスのデータを整形
        foreach ($response->getRows() as $row) {
            $device_name = $row->getDimensionValues()[0]->getValue();
            
            $sessions      = (int)($row->getMetricValues()[0]->getValue() ?? 0);
            $users         = (int)($row->getMetricValues()[1]->getValue() ?? 0);
            $pageViews     = (int)($row->getMetricValues()[2]->getValue() ?? 0);
            $avgDuration   = (float)($row->getMetricValues()[3]->getValue() ?? 0);
            $bounceRate    = (float)($row->getMetricValues()[4]->getValue() ?? 0);
            $conversions   = (int)($row->getMetricValues()[5]->getValue() ?? 0);
            
            // シェアの計算（0除算対策）
            $share = $total_sessions > 0 ? ($sessions / $total_sessions) * 100.0 : 0.0;
            
            // CVRの計算（0除算対策）
            $cvr = $sessions > 0 ? ($conversions / $sessions) * 100.0 : 0.0;
            
            // デバイス名の正規化（mobile/desktop/tablet に統一）
            $device_normalized = strtolower($device_name);
            
            $devices[] = [
                'device'      => $device_normalized,
                'sessions'    => $sessions,
                'users'       => $users,
                'pageViews'   => $pageViews,
                'avgDuration' => $avgDuration, // 秒数
                'bounceRate'  => $bounceRate * 100.0, // パーセント表記に変換（GA4は0.0-1.0で返す）
                'conversions' => $conversions,
                'cvr'         => $cvr,
                'share'       => $share,
            ];
        }
        
        return $devices;
    }

    /**
     * デバイス別の日次推移データを取得
     * 
     * @param string $property_id GA4プロパティID
     * @param string $start 開始日 (YYYY-MM-DD)
     * @param string $end 終了日 (YYYY-MM-DD)
     * @return array {labels: [日付配列], mobile: [数値配列], desktop: [数値配列], tablet: [数値配列]}
     */
    public function fetch_device_daily_series(string $property_id, string $start, string $end): array {
        
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
        $client = new BetaAnalyticsDataClient();
        
        $request = new RunReportRequest([
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
            'dimensions'  => [
                new Dimension(['name' => 'date']),
                new Dimension(['name' => 'deviceCategory'])
            ],
            'metrics'     => [ new Metric(['name' => 'sessions']) ],
            'order_bys'   => [
                new \Google\Analytics\Data\V1beta\OrderBy([
                    'dimension' => new \Google\Analytics\Data\V1beta\OrderBy\DimensionOrderBy([
                        'dimension_name' => 'date'
                    ])
                ])
            ],
        ]);
        
        $response = $client->runReport($request);
        
        // データ構造: date => { mobile: n, desktop: n, tablet: n }
        $data_by_date = [];
        
        foreach ($response->getRows() as $row) {
            $date   = $row->getDimensionValues()[0]->getValue(); // YYYYMMDD形式
            $device = strtolower($row->getDimensionValues()[1]->getValue());
            $sessions = (int)($row->getMetricValues()[0]->getValue() ?? 0);
            
            // 日付フォーマット変換: YYYYMMDD → YYYY-MM-DD
            $formatted_date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
            
            if (!isset($data_by_date[$formatted_date])) {
                $data_by_date[$formatted_date] = [
                    'mobile'  => 0,
                    'desktop' => 0,
                    'tablet'  => 0,
                ];
            }
            
            // デバイス名の正規化とマッピング
            if ($device === 'mobile') {
                $data_by_date[$formatted_date]['mobile'] = $sessions;
            } elseif ($device === 'desktop') {
                $data_by_date[$formatted_date]['desktop'] = $sessions;
            } elseif ($device === 'tablet') {
                $data_by_date[$formatted_date]['tablet'] = $sessions;
            }
        }
        
        // 配列を日付順にソート
        ksort($data_by_date);
        
        // Chart.js用のデータ構造に変換
        $labels = array_keys($data_by_date);
        $mobile = [];
        $desktop = [];
        $tablet = [];
        
        foreach ($data_by_date as $date => $values) {
            $mobile[]  = $values['mobile'];
            $desktop[] = $values['desktop'];
            $tablet[]  = $values['tablet'];
        }
        
        return [
            'labels'  => $labels,
            'mobile'  => $mobile,
            'desktop' => $desktop,
            'tablet'  => $tablet,
        ];
    }

    // =========================================================
    // KPI推移データ取得
    // =========================================================
    public function fetch_kpi_trends(array $config, array $dates): array {

        $ga4_id  = $config['ga4_id'];
        $gsc_url = $config['gsc_url'];
        $site_url = $config['site_url'] ?? '';

        $trends = [
            'dates'       => [],
            'pageViews'   => [],
            'sessions'    => [],
            'impressions' => [],
            'clicks'      => [],
        ];

        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());

        // GA4
        $client = new BetaAnalyticsDataClient();

        foreach ($dates as $date) {
            $request = new RunReportRequest([
                'property' => 'properties/' . $ga4_id,
                'date_ranges' => [ new DateRange(['start_date' => $date, 'end_date' => $date]) ],
                'metrics' => [
                    new Metric(['name' => 'screenPageViews']),
                    new Metric(['name' => 'sessions']),
                ],
            ]);

            $response = $client->runReport($request);
            $pv   = 0;
            $sess = 0;

            foreach ($response->getRows() as $row) {
                $pv   += (int) $row->getMetricValues()[0]->getValue();
                $sess += (int) $row->getMetricValues()[1]->getValue();
            }

            $trends['dates'][]     = $date;
            $trends['pageViews'][] = $pv;
            $trends['sessions'][]  = $sess;
        }

        // GSC
        $gClient = new GoogleClient();
        $gClient->setAuthConfig($this->config->get_service_account_path());
        $gClient->addScope(GoogleWebmasters::WEBMASTERS_READONLY);
        $service = new GoogleWebmasters($gClient);

        foreach ($dates as $date) {
            $req = new SearchAnalyticsQueryRequest();
            $req->setStartDate($date);
            $req->setEndDate($date);

            $resp = $service->searchanalytics->query($gsc_url, $req);
            $rows = $resp->getRows() ?: [];

            $impr  = 0;
            $click = 0;

            foreach ($rows as $row) {
                $impr  += (int) ($row->getImpressions() ?? 0);
                $click += (int) ($row->getClicks() ?? 0);
            }

            $trends['impressions'][] = $impr;
            $trends['clicks'][]      = $click;
        }

        return $trends;
    }

    // =========================================================
    // ページタイトルの推測
    // =========================================================
    public function guess_page_title(string $path): string {

        if ($path === '' || $path === '/') {
            return 'トップページ';
        }

        $segments = array_filter(explode('/', trim($path, '/')));
        if (empty($segments)) return 'トップページ';

        $last = end($segments);
        $last = preg_replace('/\.(html|php)$/i', '', $last);
        $last = str_replace(['-', '_'], ' ', $last);

        return mb_convert_case($last, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * 指定サイトのURLから <title> を取得（transientでキャッシュ）
     */
    public function fetch_title_from_site(string $site_url, string $path): string {

        $site_url = trim($site_url);
        if ($site_url === '') return '';

        // URL組み立て
        $base = untrailingslashit($site_url);
        $path = ($path === '' || $path[0] !== '/') ? '/' . ltrim($path, '/') : $path;
        $url  = $base . $path;

        // 最低限の安全チェック
        if (!wp_http_validate_url($url)) return '';
        $p = wp_parse_url($url);
        if (empty($p['scheme']) || empty($p['host'])) return '';
        if (!in_array($p['scheme'], ['http', 'https'], true)) return '';
        if (in_array($p['host'], ['localhost', '127.0.0.1'], true)) return '';

        // キャッシュ
        $key = 'gcrev_title_' . md5($url);
        $cached = get_transient($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // 取得
        $res = wp_remote_get($url, [
            'timeout' => 6,
            'redirection' => 3,
            'user-agent' => 'GCREV-INSIGHT/1.0',
            'limit_response_size' => 200000, // 200KBで十分（titleだけ抜く）
        ]);

        if (is_wp_error($res)) return '';
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 400) return '';

        $html = wp_remote_retrieve_body($res);
        if (!is_string($html) || $html === '') return '';

        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) return '';

        $title = html_entity_decode(trim(wp_strip_all_tags($m[1])), ENT_QUOTES, 'UTF-8');
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title);

        if ($title !== '') {
            // 7日キャッシュ
            set_transient($key, $title, 7 * DAY_IN_SECONDS);
        }

        return $title;
    }


    public function fetch_ga4_key_events(string $property_id, string $start, string $end): array {

        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
        $client = new BetaAnalyticsDataClient();

        $request = new RunReportRequest([
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
            'dimensions'  => [ new Dimension(['name' => 'eventName']) ],
            'metrics'     => [ new Metric(['name' => 'keyEvents']) ],
            'limit'       => 100,
        ]);

        $response = $client->runReport($request);

        $out = [];
        foreach ($response->getRows() as $row) {
            $name = (string)($row->getDimensionValues()[0]->getValue() ?? '');
            $val  = (int)($row->getMetricValues()[0]->getValue() ?? 0);
            if ($name !== '' && $val > 0) {
                $out[$name] = $val;
            }
        }

        // 多い順
        arsort($out);

        return $out; // [eventName => count]
    }

    /**
     * GA4キーイベントを日別×イベント名で取得
     * @return array [eventName => [YYYY-MM-DD => count, ...], ...]
     */
    public function fetch_ga4_key_events_daily(string $property_id, string $start, string $end): array {

        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
        $client = new BetaAnalyticsDataClient();

        $request = new RunReportRequest([
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
            'dimensions'  => [
                new Dimension(['name' => 'eventName']),
                new Dimension(['name' => 'date']),
            ],
            'metrics'     => [ new Metric(['name' => 'keyEvents']) ],
            'limit'       => 10000,
        ]);

        $response = $client->runReport($request);

        $out = [];
        foreach ($response->getRows() as $row) {
            $name    = (string)($row->getDimensionValues()[0]->getValue() ?? '');
            $dateRaw = (string)($row->getDimensionValues()[1]->getValue() ?? '');
            $val     = (int)($row->getMetricValues()[0]->getValue() ?? 0);

            if ($name === '' || $dateRaw === '') continue;

            // YYYYMMDD → YYYY-MM-DD
            $dk = substr($dateRaw, 0, 4) . '-' . substr($dateRaw, 4, 2) . '-' . substr($dateRaw, 6, 2);

            if (!isset($out[$name])) {
                $out[$name] = [];
            }
            $out[$name][$dk] = ($out[$name][$dk] ?? 0) + $val;
        }

        return $out;
    }

    public function is_likely_contact_event(string $name): bool {
        $n = mb_strtolower($name);
        $keywords = ['contact', 'inquiry', 'form', 'lead', 'cv', 'conversion', 'submit', 'toiawase', 'お問い合わせ', '問合せ', '問い合わせ', '送信', '完了'];
        foreach ($keywords as $kw) {
            if (mb_stripos($n, mb_strtolower($kw)) !== false) return true;
        }
        return false;
    }

    public function is_likely_download_event(string $name): bool {
        $n = mb_strtolower($name);
        $keywords = ['download', 'dl', 'pdf', '資料', 'カタログ', 'whitepaper', 'brochure'];
        foreach ($keywords as $kw) {
            if (mb_stripos($n, mb_strtolower($kw)) !== false) return true;
        }
        return false;
    }



    // =========================================================
    // 追加仕様：レポートに表示するキーイベントの選定
    // - お問い合わせ / 資料DL を推定し、該当が無ければ上位から採用
    // =========================================================
    public function select_report_key_events(array $prev, array $two): array {

        $all_names = array_unique(array_merge(array_keys($prev), array_keys($two)));

        $contact_keywords = ['contact', 'inquiry', 'form', 'lead', 'cv', 'conversion', 'submit', 'toiawase', 'お問い合わせ', '問合せ', '問い合わせ', '送信', '完了'];
        $download_keywords = ['download', 'dl', 'pdf', '資料', 'カタログ', 'whitepaper', 'brochure'];

        $contact = null;
        $download = null;

        foreach ($all_names as $name) {
            $n = mb_strtolower($name);
            foreach ($contact_keywords as $kw) {
                if (mb_stripos($n, mb_strtolower($kw)) !== false) {
                    $contact = $name;
                    break 2;
                }
            }
        }

        foreach ($all_names as $name) {
            $n = mb_strtolower($name);
            foreach ($download_keywords as $kw) {
                if (mb_stripos($n, mb_strtolower($kw)) !== false) {
                    $download = $name;
                    break 2;
                }
            }
        }

        // フォールバック：上位から埋める
        $ranked = $prev;
        foreach ($two as $k => $v) {
            if (!isset($ranked[$k])) $ranked[$k] = $v;
        }
        arsort($ranked);
        $ranked_names = array_keys($ranked);

        if ($contact === null && isset($ranked_names[0])) $contact = $ranked_names[0];
        if ($download === null) {
            foreach ($ranked_names as $nm) {
                if ($nm !== $contact) { $download = $nm; break; }
            }
        }

        $result = [
            'contact' => $contact ? [
                'name'  => $contact,
                'label' => ($contact !== null && $this->is_likely_contact_event($contact)) ? 'お問い合わせ（CV）' : $contact,
            ] : null,
            'download' => $download ? [
                'name'  => $download,
                'label' => ($download !== null && $this->is_likely_download_event($download)) ? '資料ダウンロード数' : $download,
            ] : null,
        ];

        return $result;
    }

    public function fetch_page_details(string $property_id, string $start, string $end, string $site_url = ''): array {

        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
        $client = new BetaAnalyticsDataClient();

        $request = new RunReportRequest([
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
            'dimensions'  => [
                new Dimension(['name' => 'pagePath']),
                new Dimension(['name' => 'pageTitle']),
            ],
            'metrics'     => [
                new Metric(['name' => 'screenPageViews']),
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'bounceRate']),
                new Metric(['name' => 'engagedSessions']),
            ],
            'limit'       => 200,
            'order_bys'   => [
                new \Google\Analytics\Data\V1beta\OrderBy([
                    'metric' => new \Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy([
                        'metric_name' => 'screenPageViews'
                    ]),
                    'desc' => true
                ])
            ],
        ]);

        $response = $client->runReport($request);

        $pages = [];
        $total_pv = 0;

        // 1回目: 合計PVを計算
        foreach ($response->getRows() as $row) {
            $pv = (int)($row->getMetricValues()[0]->getValue() ?? 0);
            $total_pv += $pv;
        }

        // 2回目: 各ページのデータを整形
        foreach ($response->getRows() as $row) {
            $dims = $row->getDimensionValues();
            $path      = $dims[0]->getValue();
            $pageTitle = $dims[1]->getValue();

            $pv            = (int)($row->getMetricValues()[0]->getValue() ?? 0);
            $sessions      = (int)($row->getMetricValues()[1]->getValue() ?? 0);
            $avgDuration   = (float)($row->getMetricValues()[2]->getValue() ?? 0);
            $bounceRate    = (float)($row->getMetricValues()[3]->getValue() ?? 0);
            $engagedSess   = (int)($row->getMetricValues()[4]->getValue() ?? 0);

            // タイトル決定（既存の fetch_ga4_data と同じロジック）
            $title = '';
            if ($pageTitle && $pageTitle !== '(not set)') {
                $title = $pageTitle;
            } else {
                $fetched = $this->fetch_title_from_site($site_url, $path);
                $title = ($fetched && $fetched !== '') ? $fetched : $this->guess_page_title($path);
            }

            // 割合
            $percentage = $total_pv > 0 ? ($pv / $total_pv) * 100.0 : 0.0;

            // エンゲージメント率
            $engagementRate = $sessions > 0 ? ($engagedSess / $sessions) * 100.0 : 0.0;

            $pages[] = [
                'page'           => $path,
                'title'          => $title,
                'pageViews'      => $pv,
                'percentage'     => round($percentage, 1),
                'sessions'       => $sessions,
                'avgDuration'    => $avgDuration,             // 秒数
                'bounceRate'     => round($bounceRate * 100.0, 1), // GA4は0.0-1.0 → %
                'engagementRate' => round($engagementRate, 1),
            ];
        }

        return $pages;
    }

    /**
     * ページ別データに前期比の PV 変動を追加
     * パターン: calculate_region_changes と同一
     */
    public function calculate_page_changes(array $current, array $previous): array {
        // 前期データをパスでマップ化
        $prev_map = [];
        foreach ($previous as $page) {
            $prev_map[$page['page']] = $page;
        }

        $result = [];
        foreach ($current as $page) {
            $path = $page['page'];

            if (isset($prev_map[$path])) {
                $prev_pv = $prev_map[$path]['pageViews'];
                if ($prev_pv > 0) {
                    $page['pvChange'] = round((($page['pageViews'] - $prev_pv) / $prev_pv) * 100.0, 1);
                } else {
                    $page['pvChange'] = 0.0;
                }
            } else {
                // 前期にデータがない→新規ページ
                $page['pvChange'] = null;
            }

            $result[] = $page;
        }

        return $result;
    }

    /**
     * キーワードデータに前期比の掲載順位変動を追加
     * パターン: calculate_page_changes と同一
     */
    public function calculate_keyword_changes(array $current, array $previous): array {
        // 前期データをクエリでマップ化
        $prev_map = [];
        foreach ($previous as $kw) {
            $prev_map[$kw['query']] = $kw;
        }

        $result = [];
        foreach ($current as $kw) {
            $query = $kw['query'];

            if (isset($prev_map[$query])) {
                $prev_pos = $prev_map[$query]['_position'] ?? 0;
                $curr_pos = $kw['_position'] ?? 0;
                if ($prev_pos > 0 && $curr_pos > 0) {
                    // 順位変動: 前期順位 - 今期順位 （マイナス=悪化、プラス=改善ではなく）
                    // positionChange > 0 → 順位が下がった（悪化）
                    // positionChange < 0 → 順位が上がった（改善）
                    $kw['positionChange'] = round($curr_pos - $prev_pos, 1);
                } else {
                    $kw['positionChange'] = 0.0;
                }
            } else {
                // 前期にデータがない→新規キーワード
                $kw['positionChange'] = null;
            }

            $result[] = $kw;
        }

        return $result;
    }
    /**
     * GA4から地域別詳細データを取得
     */
    public function fetch_region_details(string $property_id, string $start, string $end): array {
        
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
        $client = new BetaAnalyticsDataClient();
        
        $request = new RunReportRequest([
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange(['start_date' => $start, 'end_date' => $end]) ],
            'dimensions'  => [ new Dimension(['name' => 'region']) ],
            'metrics'     => [
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'totalUsers']),
                new Metric(['name' => 'screenPageViews']),
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'bounceRate']),
                new Metric(['name' => 'conversions']),
            ],
            'limit'       => 50,
            'order_bys'   => [
                new \Google\Analytics\Data\V1beta\OrderBy([
                    'metric' => new \Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy([
                        'metric_name' => 'sessions'
                    ]),
                    'desc' => true
                ])
            ]
        ]);
        
        $response = $client->runReport($request);
        
        $regions = [];
        $total_sessions = 0;
        
        // 1回目: 合計セッション数を計算
        foreach ($response->getRows() as $row) {
            $sessions = (int)($row->getMetricValues()[0]->getValue() ?? 0);
            $total_sessions += $sessions;
        }
        
        // 2回目: 各地域のデータを整形
        foreach ($response->getRows() as $row) {
            $region_name = $row->getDimensionValues()[0]->getValue();
            
            // 地域名が空または "(not set)" の場合はスキップ
            if (empty($region_name) || $region_name === '(not set)') {
                continue;
            }
            
            $sessions      = (int)($row->getMetricValues()[0]->getValue() ?? 0);
            $users         = (int)($row->getMetricValues()[1]->getValue() ?? 0);
            $pageViews     = (int)($row->getMetricValues()[2]->getValue() ?? 0);
            $avgDuration   = (float)($row->getMetricValues()[3]->getValue() ?? 0);
            $bounceRate    = (float)($row->getMetricValues()[4]->getValue() ?? 0);
            $conversions   = (int)($row->getMetricValues()[5]->getValue() ?? 0);
            
            // CVRの計算（0除算対策）
            $cvr = $sessions > 0 ? ($conversions / $sessions) * 100.0 : 0.0;
            
            $regions[] = [
                'region'      => $region_name,
                'sessions'    => $sessions,
                'users'       => $users,
                'pageViews'   => $pageViews,
                'avgDuration' => $avgDuration,
                'bounceRate'  => $bounceRate * 100.0,
                'conversions' => $conversions,
                'cvr'         => $cvr,
            ];
        }
        
        return $regions;
    }

    /**
     * GA4から特定地域の月別セッション推移を取得（直近12ヶ月）
     *
     * @param string $property_id GA4プロパティID
     * @param string $region      地域名（英語、GA4のregionディメンション値）
     * @param string $metric      取得指標 (sessions|screenPageViews|totalUsers|conversions)
     * @param int    $months      取得月数（デフォルト12）
     * @return array{labels: string[], values: int[]}
     */
    public function fetch_region_monthly_trend(string $property_id, string $region, string $metric = 'sessions', int $months = 12): array {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
        $client = new BetaAnalyticsDataClient();

        $tz    = new \DateTimeZone('Asia/Tokyo');
        // 前月末を終了日とする（当月は未確定データのため除外）
        $end   = new \DateTimeImmutable('last day of last month', $tz);
        // 開始日: 前月から ($months - 1) ヶ月遡った月の1日
        // ※ modify('first day of -N months') は月末日起点だと意図しない結果になるため
        //    まず前月1日を求めてから遡る
        $first_of_last_month = new \DateTimeImmutable('first day of last month', $tz);
        $start = $first_of_last_month->modify('-' . ($months - 1) . ' months');

        // メトリクス名のバリデーション
        $allowed = ['sessions', 'screenPageViews', 'totalUsers', 'conversions'];
        if (!in_array($metric, $allowed, true)) {
            $metric = 'sessions';
        }

        $request = new RunReportRequest([
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [
                new DateRange([
                    'start_date' => $start->format('Y-m-d'),
                    'end_date'   => $end->format('Y-m-d'),
                ]),
            ],
            'dimensions'  => [
                new Dimension(['name' => 'yearMonth']),
                new Dimension(['name' => 'region']),
            ],
            'metrics'     => [
                new Metric(['name' => $metric]),
            ],
            'dimension_filter' => new \Google\Analytics\Data\V1beta\FilterExpression([
                'filter' => new \Google\Analytics\Data\V1beta\Filter([
                    'field_name'   => 'region',
                    'string_filter' => new \Google\Analytics\Data\V1beta\Filter\StringFilter([
                        'value'      => $region,
                        'match_type' => \Google\Analytics\Data\V1beta\Filter\StringFilter\MatchType::EXACT,
                    ]),
                ]),
            ]),
            'order_bys'   => [
                new \Google\Analytics\Data\V1beta\OrderBy([
                    'dimension' => new \Google\Analytics\Data\V1beta\OrderBy\DimensionOrderBy([
                        'dimension_name' => 'yearMonth',
                    ]),
                    'desc' => false,
                ]),
            ],
            'limit' => $months + 2,
        ]);

        $response = $client->runReport($request);

        // GA4レスポンスをマップ化（YYYYMM → 値）
        $raw = [];
        foreach ($response->getRows() as $row) {
            $ym  = $row->getDimensionValues()[0]->getValue(); // e.g. "202501"
            $val = (int)($row->getMetricValues()[0]->getValue() ?? 0);
            $raw[$ym] = $val;
        }

        // 全月分のラベルを生成し、データがない月は0で埋める
        $labels = [];
        $values = [];
        $cursor = new \DateTime($start->format('Y-m-01'), $tz);
        $end_ym = $end->format('Ym');
        while ($cursor->format('Ym') <= $end_ym) {
            $ym_key    = $cursor->format('Ym');       // e.g. "202502"
            $formatted = $cursor->format('Y-m');      // e.g. "2025-02"
            $labels[] = $formatted;
            $values[] = $raw[$ym_key] ?? 0;
            $cursor->modify('+1 month');
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }
    public function fetch_source_data_from_ga4(string $property_id, string $start_date, string $end_date): array {
        // Google認証情報を環境変数に設定
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->config->get_service_account_path());
        
        $client = new BetaAnalyticsDataClient();
        
        $result = [
            'channels' => [],
            'sources' => [],
            'daily_series' => []
        ];
        
        // 1. チャネルグループ別集計
        $request_channels = new RunReportRequest([
            'property' => 'properties/' . $property_id,
            'date_ranges' => [
                new DateRange([
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                ]),
            ],
            'dimensions' => [
                new Dimension(['name' => 'sessionDefaultChannelGroup']),
            ],
            'metrics' => [
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'screenPageViews']),
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'bounceRate']),
                new Metric(['name' => 'conversions']),
            ],
            'order_bys' => [
                new \Google\Analytics\Data\V1beta\OrderBy([
                    'metric' => new \Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy([
                        'metric_name' => 'sessions'
                    ]),
                    'desc' => true
                ])
            ],
            'limit' => 10
        ]);
        
        $response_channels = $client->runReport($request_channels);
        
        foreach ($response_channels->getRows() as $row) {
            $channel = $row->getDimensionValues()[0]->getValue();
            $sessions = (int) $row->getMetricValues()[0]->getValue();
            $pageViews = (int) $row->getMetricValues()[1]->getValue();
            $avgDuration = (float) $row->getMetricValues()[2]->getValue();
            $bounceRate = (float) $row->getMetricValues()[3]->getValue() * 100;
            $conversions = (int) $row->getMetricValues()[4]->getValue();
            
            $result['channels'][] = [
                'channel' => $channel,
                'sessions' => $sessions,
                'pageViews' => $pageViews,
                'avgDuration' => $avgDuration,
                'bounceRate' => $bounceRate,
                'conversions' => $conversions,
                'cvr' => $sessions > 0 ? ($conversions / $sessions * 100) : 0,
            ];
        }
        
        // 2. 参照元 / メディア別詳細（TOP10）
        $request_sources = new RunReportRequest([
            'property' => 'properties/' . $property_id,
            'date_ranges' => [
                new DateRange([
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                ]),
            ],
            'dimensions' => [
                new Dimension(['name' => 'sessionSource']),
                new Dimension(['name' => 'sessionMedium']),
            ],
            'metrics' => [
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'screenPageViews']),
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'bounceRate']),
                new Metric(['name' => 'conversions']),
            ],
            'order_bys' => [
                new \Google\Analytics\Data\V1beta\OrderBy([
                    'metric' => new \Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy([
                        'metric_name' => 'sessions'
                    ]),
                    'desc' => true
                ])
            ],
            'limit' => 10
        ]);
        
        $response_sources = $client->runReport($request_sources);
        
        foreach ($response_sources->getRows() as $row) {
            $source = $row->getDimensionValues()[0]->getValue();
            $medium = $row->getDimensionValues()[1]->getValue();
            $sessions = (int) $row->getMetricValues()[0]->getValue();
            $pageViews = (int) $row->getMetricValues()[1]->getValue();
            $avgDuration = (float) $row->getMetricValues()[2]->getValue();
            $bounceRate = (float) $row->getMetricValues()[3]->getValue() * 100;
            $conversions = (int) $row->getMetricValues()[4]->getValue();
            
            $result['sources'][] = [
                'source' => $source,
                'medium' => $medium,
                'sessions' => $sessions,
                'pageViews' => $pageViews,
                'avgDuration' => $avgDuration,
                'bounceRate' => $bounceRate,
                'conversions' => $conversions,
                'cvr' => $sessions > 0 ? ($conversions / $sessions * 100) : 0,
            ];
        }
        
        // 3. 日別推移（主要チャネルのみ）
        $request_daily = new RunReportRequest([
            'property' => 'properties/' . $property_id,
            'date_ranges' => [
                new DateRange([
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                ]),
            ],
            'dimensions' => [
                new Dimension(['name' => 'date']),
                new Dimension(['name' => 'sessionDefaultChannelGroup']),
            ],
            'metrics' => [
                new Metric(['name' => 'sessions']),
            ],
            'order_bys' => [
                new \Google\Analytics\Data\V1beta\OrderBy([
                    'dimension' => new \Google\Analytics\Data\V1beta\OrderBy\DimensionOrderBy([
                        'dimension_name' => 'date'
                    ]),
                    'desc' => false
                ])
            ],
        ]);
        
        $response_daily = $client->runReport($request_daily);
        
        $daily_data = [];
        foreach ($response_daily->getRows() as $row) {
            $date = $row->getDimensionValues()[0]->getValue(); // YYYYMMDD形式
            $channel = $row->getDimensionValues()[1]->getValue();
            $sessions = (int) $row->getMetricValues()[0]->getValue();
            
            // 日付フォーマット変換: YYYYMMDD → YYYY-MM-DD（デバイス別と同じ形式）
            $formatted_date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
            
            if (!isset($daily_data[$formatted_date])) {
                $daily_data[$formatted_date] = [];
            }
            $daily_data[$formatted_date][$channel] = $sessions;
        }
        
        // 日付順にソート
        ksort($daily_data);
        
        $result['daily_series'] = $daily_data;

        return $result;
    }

    /**
     * フレキシブルレポート取得
     *
     * 任意のディメンション・メトリクス組み合わせで GA4 Data API を叩く。
     * みまもりAI チャットからの動的クエリに使用する。
     *
     * @param string $property_id  GA4 プロパティID
     * @param string $start        開始日 YYYY-MM-DD
     * @param string $end          終了日 YYYY-MM-DD
     * @param array  $dimensions   ディメンション名の配列（例: ['country', 'city']）
     * @param array  $metrics      メトリクス名の配列（例: ['sessions', 'totalUsers']）
     * @param int    $limit        取得行数上限（デフォルト 20）
     * @param string $order_metric 並べ替えメトリクス名（デフォルト: $metrics[0]）
     * @param bool   $order_desc   降順（デフォルト true）
     * @return array {
     *   rows: [[ dim1 => val, dim2 => val, metric1 => val, ... ], ...],
     *   totals: [ metric1 => val, ... ],
     *   row_count: int,
     *   query_meta: [ dimensions => [...], metrics => [...], start => ..., end => ..., limit => ... ]
     * }
     */
    public function fetch_flexible_report(
        string $property_id,
        string $start,
        string $end,
        array  $dimensions,
        array  $metrics,
        int    $limit        = 20,
        string $order_metric = '',
        bool   $order_desc   = true
    ): array {
        $this->prepare_ga4_call();

        $client = new BetaAnalyticsDataClient();

        // ディメンション構築
        $dim_objects = [];
        foreach ( $dimensions as $d ) {
            $dim_objects[] = new Dimension( [ 'name' => $d ] );
        }

        // メトリクス構築
        $met_objects = [];
        foreach ( $metrics as $m ) {
            $met_objects[] = new Metric( [ 'name' => $m ] );
        }

        // 並び順
        if ( $order_metric === '' && ! empty( $metrics ) ) {
            $order_metric = $metrics[0];
        }

        $request_params = [
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange( [ 'start_date' => $start, 'end_date' => $end ] ) ],
            'dimensions'  => $dim_objects,
            'metrics'     => $met_objects,
            'limit'       => $limit,
        ];

        if ( $order_metric !== '' ) {
            $request_params['order_bys'] = [
                new OrderBy( [
                    'metric' => new OrderBy\MetricOrderBy( [
                        'metric_name' => $order_metric,
                    ] ),
                    'desc' => $order_desc,
                ] ),
            ];
        }

        $request  = new RunReportRequest( $request_params );
        $response = $client->runReport( $request );

        // 結果パース
        $rows   = [];
        $totals = [];
        foreach ( $metrics as $m ) {
            $totals[ $m ] = 0;
        }

        foreach ( $response->getRows() as $row ) {
            $item = [];

            // ディメンション値
            $dim_vals = $row->getDimensionValues();
            foreach ( $dimensions as $i => $d ) {
                $item[ $d ] = isset( $dim_vals[ $i ] ) ? $dim_vals[ $i ]->getValue() : '';
            }

            // メトリクス値
            $met_vals = $row->getMetricValues();
            foreach ( $metrics as $i => $m ) {
                $raw = isset( $met_vals[ $i ] ) ? $met_vals[ $i ]->getValue() : '0';
                // 小数を含むメトリクスは float、それ以外は int
                $is_float = in_array( $m, [
                    'averageSessionDuration', 'bounceRate', 'engagementRate',
                    'averageEngagementTime', 'averageEngagementTimePerSession',
                ], true );
                $val = $is_float ? (float) $raw : (int) $raw;
                $item[ $m ] = $val;
                $totals[ $m ] += $val;
            }

            $rows[] = $item;
        }

        return [
            'rows'       => $rows,
            'totals'     => $totals,
            'row_count'  => count( $rows ),
            'query_meta' => [
                'dimensions' => $dimensions,
                'metrics'    => $metrics,
                'start'      => $start,
                'end'        => $end,
                'limit'      => $limit,
            ],
        ];
    }

    /**
     * CVイベントの詳細グループデータを取得
     *
     * GA4 Data API はイベント単位の生データを返せないため、
     * dateHourMinute + pagePath + sessionSourceMedium + deviceCategory + country
     * の組み合わせごとに eventCount を集計したデータを返す。
     *
     * @param string   $property_id GA4プロパティID
     * @param string   $start       開始日 (YYYY-MM-DD)
     * @param string   $end         終了日 (YYYY-MM-DD)
     * @param string[] $event_names フィルタするイベント名の配列
     * @return array   ['rows' => [...], 'total_events' => int]
     */
    public function fetch_cv_event_detail(
        string $property_id,
        string $start,
        string $end,
        array  $event_names
    ): array {
        $this->prepare_ga4_call();

        $client = new BetaAnalyticsDataClient();

        $dimensions = [
            new Dimension(['name' => 'dateHourMinute']),
            new Dimension(['name' => 'eventName']),
            new Dimension(['name' => 'pagePath']),
            new Dimension(['name' => 'sessionSourceMedium']),
            new Dimension(['name' => 'deviceCategory']),
            new Dimension(['name' => 'country']),
        ];

        $metrics = [
            new Metric(['name' => 'eventCount']),
        ];

        // eventNameフィルタ構築
        $filter_expression = null;
        if ( ! empty( $event_names ) ) {
            if ( count( $event_names ) === 1 ) {
                $filter_expression = new FilterExpression([
                    'filter' => new Filter([
                        'field_name'    => 'eventName',
                        'string_filter' => new StringFilter([
                            'value'      => $event_names[0],
                            'match_type' => StringFilter\MatchType::EXACT,
                        ]),
                    ]),
                ]);
            } else {
                $filters = [];
                foreach ( $event_names as $name ) {
                    $filters[] = new FilterExpression([
                        'filter' => new Filter([
                            'field_name'    => 'eventName',
                            'string_filter' => new StringFilter([
                                'value'      => $name,
                                'match_type' => StringFilter\MatchType::EXACT,
                            ]),
                        ]),
                    ]);
                }
                $filter_expression = new FilterExpression([
                    'or_group' => new FilterExpressionList([
                        'expressions' => $filters,
                    ]),
                ]);
            }
        }

        $request_params = [
            'property'    => 'properties/' . $property_id,
            'date_ranges' => [ new DateRange([ 'start_date' => $start, 'end_date' => $end ]) ],
            'dimensions'  => $dimensions,
            'metrics'     => $metrics,
            'order_bys'   => [
                new OrderBy([
                    'dimension' => new OrderBy\DimensionOrderBy([
                        'dimension_name' => 'dateHourMinute',
                    ]),
                    'desc' => true,
                ]),
            ],
            'limit' => 10000,
        ];

        if ( $filter_expression !== null ) {
            $request_params['dimension_filter'] = $filter_expression;
        }

        $request  = new RunReportRequest( $request_params );
        $response = $client->runReport( $request );

        $rows         = [];
        $total_events = 0;

        foreach ( $response->getRows() as $row ) {
            $dim_vals = $row->getDimensionValues();
            $met_vals = $row->getMetricValues();

            $dateHourMinute = $dim_vals[0]->getValue();
            $eventName      = $dim_vals[1]->getValue();
            $pagePath       = $dim_vals[2]->getValue();
            $sourceMedium   = $dim_vals[3]->getValue();
            $deviceCategory = $dim_vals[4]->getValue();
            $country        = $dim_vals[5]->getValue();
            $eventCount     = (int) ( $met_vals[0]->getValue() ?? 0 );

            // ハッシュ生成: GA4行を一意に識別
            $hash = md5( $eventName . $dateHourMinute . $pagePath . $sourceMedium . $deviceCategory . $country );

            $rows[] = [
                'row_hash'         => $hash,
                'event_name'       => $eventName,
                'date_hour_minute' => $dateHourMinute,
                'page_path'        => $pagePath,
                'source_medium'    => $sourceMedium,
                'device_category'  => $deviceCategory,
                'country'          => $country,
                'event_count'      => $eventCount,
            ];

            $total_events += $eventCount;
        }

        return [
            'rows'         => $rows,
            'total_events' => $total_events,
        ];
    }

}
