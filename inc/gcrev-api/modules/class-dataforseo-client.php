<?php
// FILE: inc/gcrev-api/modules/class-dataforseo-client.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_DataForSEO_Client') ) { return; }

/**
 * Gcrev_DataForSEO_Client
 *
 * DataForSEO API 通信クライアント。
 * Google Organic Search (Live/Advanced) を利用して
 * 指定キーワードの SERP 順位を取得する。
 *
 * 認証: wp-config.php の DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD 定数
 * レート制限: Gcrev_Rate_Limiter（30回/分）
 *
 * @package Mimamori_Web
 * @since   2.5.0
 */
class Gcrev_DataForSEO_Client {

    /** API ベース URL */
    private const API_BASE = 'https://api.dataforseo.com/v3';

    /** レートリミット（回/分） */
    private const RATE_LIMIT_PER_MINUTE = 30;

    /** HTTP タイムアウト（秒） */
    private const HTTP_TIMEOUT = 60;

    /**
     * 半径(m) → DataForSEO zoom レベル マッピング
     *
     * Google Maps の zoom レベルで検索範囲を制御する。
     * 将来的にグリッドスキャン等で複数ポイント計測に拡張可能。
     */
    private const RADIUS_ZOOM_MAP = [
        500   => 19,
        1000  => 17,
        3000  => 15,
        5000  => 14,
        10000 => 13,
    ];

    /** デフォルト zoom（1km 相当） */
    private const DEFAULT_ZOOM = 17;

    /** @var Gcrev_Config */
    private Gcrev_Config $config;

    /**
     * @param Gcrev_Config $config
     */
    public function __construct( Gcrev_Config $config ) {
        $this->config = $config;
    }

    // =========================================================
    // 設定確認
    // =========================================================

    /**
     * API 認証情報が設定されているか
     */
    public static function is_configured(): bool {
        return defined('DATAFORSEO_LOGIN')
            && defined('DATAFORSEO_PASSWORD')
            && DATAFORSEO_LOGIN !== ''
            && DATAFORSEO_PASSWORD !== '';
    }

    // =========================================================
    // 半径 / zoom 変換ヘルパー
    // =========================================================

    /**
     * 半径（メートル）を DataForSEO の zoom レベルに変換
     *
     * RADIUS_ZOOM_MAP に完全一致がなければ最も近い半径の zoom を返す。
     *
     * @param int $radius 半径（メートル）
     * @return int zoom レベル (3-21)
     */
    public static function radius_to_zoom( int $radius ): int {
        if ( isset( self::RADIUS_ZOOM_MAP[ $radius ] ) ) {
            return self::RADIUS_ZOOM_MAP[ $radius ];
        }
        $closest  = self::DEFAULT_ZOOM;
        $min_diff = PHP_INT_MAX;
        foreach ( self::RADIUS_ZOOM_MAP as $r => $z ) {
            $diff = abs( $r - $radius );
            if ( $diff < $min_diff ) {
                $min_diff = $diff;
                $closest  = $z;
            }
        }
        return $closest;
    }

    /**
     * 緯度・経度・zoom から DataForSEO の location_coordinate 文字列を生成
     *
     * @param float $lat  緯度 (-90 〜 90)
     * @param float $lng  経度 (-180 〜 180)
     * @param int   $zoom zoom レベル (3-21, デフォルト 17)
     * @return string "lat,lng,{zoom}z"
     */
    public static function build_coordinate_string( float $lat, float $lng, int $zoom = 17 ): string {
        $zoom = max( 3, min( 21, $zoom ) );
        return sprintf( '%.7f,%.7f,%dz', $lat, $lng, $zoom );
    }

    /**
     * UI 用の半径選択肢配列を返す
     *
     * @return array [ ['value' => 500, 'label' => '500m', 'zoom' => 19], ... ]
     */
    public static function get_radius_options(): array {
        $options = [];
        foreach ( self::RADIUS_ZOOM_MAP as $radius => $zoom ) {
            $label = $radius >= 1000
                ? ( $radius / 1000 ) . 'km'
                : $radius . 'm';
            $options[] = [
                'value' => $radius,
                'label' => $label,
                'zoom'  => $zoom,
            ];
        }
        return $options;
    }

    /**
     * zoom レベルから対応する半径ラベルを返す
     *
     * @param int $zoom zoom レベル
     * @return string 半径ラベル（例: '1km'）
     */
    public static function zoom_to_radius_label( int $zoom ): string {
        $flip = array_flip( self::RADIUS_ZOOM_MAP );
        $radius = $flip[ $zoom ] ?? 1000;
        return $radius >= 1000 ? ( $radius / 1000 ) . 'km' : $radius . 'm';
    }

    // =========================================================
    // API 疎通テスト
    // =========================================================

    /**
     * 最小限のリクエストで API 接続をテストする
     *
     * @return array{success: bool, message: string, balance?: float}
     */
    public function test_connection(): array {
        if ( ! self::is_configured() ) {
            return [
                'success' => false,
                'message' => 'DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD が wp-config.php に未設定です。',
            ];
        }

        // depth=10 の小さいリクエストで疎通確認
        $post_data = [
            [
                'keyword'       => 'test',
                'location_code' => 2392, // Japan
                'language_code' => 'ja',
                'device'        => 'desktop',
                'os'            => 'windows',
                'depth'         => 10,
            ]
        ];

        $response = $this->api_request( '/serp/google/organic/live/advanced', $post_data );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $status_code = (int) ( $response['status_code'] ?? 0 );
        if ( $status_code === 20000 ) {
            $cost = $response['cost'] ?? 0;
            return [
                'success' => true,
                'message' => 'API 接続成功（テストリクエスト cost: ' . $cost . '）',
            ];
        }

        return [
            'success' => false,
            'message' => 'API エラー: ' . ( $response['status_message'] ?? 'unknown' ),
        ];
    }

    // =========================================================
    // SERP 取得
    // =========================================================

    /**
     * 指定キーワード・デバイスの SERP データを取得
     *
     * @param string $keyword       検索キーワード
     * @param string $device        'desktop' or 'mobile'
     * @param int    $location_code ロケーションコード（デフォルト: Japan 2392）
     * @param string $language_code 言語コード（デフォルト: 'ja'）
     * @param bool   $include_meta  true の場合、items だけでなく meta 情報も返す
     * @return array|WP_Error SERP items 配列（$include_meta=true 時は連想配列）。失敗時は WP_Error
     */
    public function fetch_serp( string $keyword, string $device = 'desktop', int $location_code = 2392, string $language_code = 'ja', bool $include_meta = false ) {
        if ( ! self::is_configured() ) {
            return new \WP_Error( 'not_configured', 'DataForSEO API が未設定です。' );
        }

        // レートリミット
        if ( class_exists( 'Gcrev_Rate_Limiter' ) ) {
            Gcrev_Rate_Limiter::check_and_wait( 'dataforseo', self::RATE_LIMIT_PER_MINUTE );
        }

        $post_data = [
            [
                'keyword'       => $keyword,
                'location_code' => $location_code,
                'language_code' => $language_code,
                'device'        => $device,
                'os'            => $device === 'desktop' ? 'windows' : 'android',
                'depth'         => 100,
            ]
        ];

        $response = $this->api_request( '/serp/google/organic/live/advanced', $post_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = (int) ( $response['status_code'] ?? 0 );
        if ( $status_code !== 20000 ) {
            $msg = $response['status_message'] ?? 'Unknown error';
            error_log( "[GCREV][DataForSEO] API error: {$msg} (code: {$status_code})" );
            return new \WP_Error( 'api_error', $msg );
        }

        $tasks = $response['tasks'] ?? [];
        if ( empty( $tasks ) || empty( $tasks[0]['result'] ) ) {
            return new \WP_Error( 'no_result', 'API 応答にデータが含まれていません。' );
        }

        $result = $tasks[0]['result'][0];
        $items  = $result['items'] ?? [];

        if ( ! $include_meta ) {
            return $items;
        }

        // メタ情報付きで返す（AIO デバッグ・診断用）
        $item_types = [];
        foreach ( $items as $item ) {
            $t = $item['type'] ?? 'unknown';
            $item_types[ $t ] = ( $item_types[ $t ] ?? 0 ) + 1;
        }

        return [
            'items'            => $items,
            'item_types'       => $item_types,
            'total_items'      => count( $items ),
            'keyword'          => $result['keyword'] ?? $keyword,
            'language_code'    => $result['language_code'] ?? $language_code,
            'location_code'    => $result['location_code'] ?? $location_code,
            'se_results_count' => $result['se_results_count'] ?? 0,
        ];
    }

    // =========================================================
    // キーワード別順位取得（desktop + mobile）
    // =========================================================

    /**
     * キーワードの desktop / mobile 両方の順位を取得する
     *
     * @param string $keyword       検索キーワード
     * @param string $target_domain ターゲットドメイン
     * @param int    $location_code ロケーションコード
     * @param string $language_code 言語コード
     * @return array{desktop: ?array, mobile: ?array, serp_items: array} デバイスごとの順位情報 + desktop SERP items
     */
    public function fetch_rankings_for_keyword(
        string $keyword,
        string $target_domain,
        int    $location_code = 1009312,
        string $language_code = 'ja'
    ): array {
        $results = [ 'desktop' => null, 'mobile' => null, 'serp_items' => [] ];

        foreach ( ['desktop', 'mobile'] as $device ) {
            $items = $this->fetch_serp( $keyword, $device, $location_code, $language_code );

            if ( is_wp_error( $items ) ) {
                error_log( "[GCREV][DataForSEO] fetch_serp failed for '{$keyword}' ({$device}): " . $items->get_error_message() );
                $results[ $device ] = [
                    'is_ranked'     => false,
                    'rank_group'    => null,
                    'rank_absolute' => null,
                    'found_url'     => null,
                    'found_domain'  => null,
                    'serp_type'     => 'organic',
                    'error'         => $items->get_error_message(),
                ];
                continue;
            }

            // desktop SERP items を保持（上がりやすさスコア算出用）
            if ( $device === 'desktop' && is_array( $items ) ) {
                $results['serp_items'] = $items;
            }

            $match = $this->find_domain_in_results( $items, $target_domain );

            if ( $match ) {
                $results[ $device ] = [
                    'is_ranked'     => true,
                    'rank_group'    => (int) $match['rank_group'],
                    'rank_absolute' => (int) $match['rank_absolute'],
                    'found_url'     => $match['url'] ?? null,
                    'found_domain'  => $match['domain'] ?? null,
                    'serp_type'     => $match['type'] ?? 'organic',
                ];
            } else {
                $results[ $device ] = [
                    'is_ranked'     => false,
                    'rank_group'    => null,
                    'rank_absolute' => null,
                    'found_url'     => null,
                    'found_domain'  => null,
                    'serp_type'     => 'organic',
                ];
            }
        }

        return $results;
    }

    // =========================================================
    // ドメインマッチ
    // =========================================================

    /**
     * SERP 結果からターゲットドメインにマッチするアイテムを検索
     *
     * www. / 非www. 対応。organic タイプを優先。
     *
     * @param array  $items         SERP items 配列
     * @param string $target_domain ターゲットドメイン（例: 'example.com'）
     * @return array|null マッチしたアイテム（rank_group, rank_absolute, url, domain, type）
     */
    public function find_domain_in_results( array $items, string $target_domain ): ?array {
        // URL が渡された場合はホスト部分を抽出し、www. を除いた正規化ドメインにする
        $host = $target_domain;
        if ( preg_match( '#^https?://#i', $target_domain ) ) {
            $parsed = wp_parse_url( $target_domain );
            $host   = $parsed['host'] ?? $target_domain;
        }
        $normalized_target = preg_replace( '/^www\./i', '', strtolower( trim( $host, '/' ) ) );

        foreach ( $items as $item ) {
            if ( empty( $item['domain'] ) ) {
                continue;
            }

            $item_domain = preg_replace( '/^www\./i', '', strtolower( $item['domain'] ) );

            if ( $item_domain === $normalized_target ) {
                return [
                    'rank_group'    => $item['rank_group'] ?? null,
                    'rank_absolute' => $item['rank_absolute'] ?? null,
                    'url'           => $item['url'] ?? null,
                    'domain'        => $item['domain'] ?? null,
                    'type'          => $item['type'] ?? 'organic',
                ];
            }
        }

        return null;
    }

    // =========================================================
    // 検索ボリューム取得
    // =========================================================

    /**
     * Google Ads Search Volume API でキーワードの検索ボリューム・競合性・CPC を取得
     *
     * @param array  $keywords       キーワード配列（最大1000件）
     * @param int    $location_code  ロケーションコード（デフォルト: Japan 2392）
     * @param string $language_code  言語コード（デフォルト: 'ja'）
     * @return array|WP_Error [ 'keyword' => { search_volume, competition, cpc } ] 形式
     */
    public function fetch_search_volume( array $keywords, int $location_code = 2392, string $language_code = 'ja' ) {
        if ( ! self::is_configured() ) {
            return new \WP_Error( 'not_configured', 'DataForSEO API が未設定です。' );
        }

        if ( empty( $keywords ) ) {
            return [];
        }

        // レートリミット（検索ボリュームは API 側 12回/分制限のため安全マージン）
        if ( class_exists( 'Gcrev_Rate_Limiter' ) ) {
            Gcrev_Rate_Limiter::check_and_wait( 'dataforseo_kw', 10 );
        }

        $post_data = [
            [
                'keywords'      => array_values( $keywords ),
                'location_code' => $location_code,
                'language_code' => $language_code,
            ]
        ];

        $response = $this->api_request( '/keywords_data/google_ads/search_volume/live', $post_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = (int) ( $response['status_code'] ?? 0 );
        if ( $status_code !== 20000 ) {
            $msg = $response['status_message'] ?? 'Unknown error';
            error_log( "[GCREV][DataForSEO] search_volume API error: {$msg} (code: {$status_code})" );
            return new \WP_Error( 'api_error', $msg );
        }

        $tasks = $response['tasks'] ?? [];
        if ( empty( $tasks ) || empty( $tasks[0]['result'] ) ) {
            return new \WP_Error( 'no_result', '検索ボリューム API 応答にデータが含まれていません。' );
        }

        // 正規化キー → 元キーワード のマップを構築
        $normalized_to_original = [];
        foreach ( $keywords as $orig_kw ) {
            $normalized_to_original[ $this->normalize_keyword( $orig_kw ) ] = $orig_kw;
        }

        $results = [];
        $null_vol = 0; $null_comp = 0;
        foreach ( $tasks[0]['result'] as $item ) {
            $kw = $item['keyword'] ?? '';
            if ( $kw === '' ) {
                continue;
            }
            $norm = $this->normalize_keyword( $kw );
            $original_kw = $normalized_to_original[ $norm ] ?? $kw;
            $sv = $item['search_volume'] ?? null;
            $cp = $item['competition'] ?? null;
            if ( $sv === null ) $null_vol++;
            if ( $cp === null ) $null_comp++;
            $results[ $original_kw ] = [
                'search_volume' => $sv,
                'competition'   => $cp,
                'cpc'           => $item['cpc'] ?? null,
            ];
        }

        file_put_contents( '/tmp/gcrev_seo_debug.log',
            date( 'Y-m-d H:i:s' ) . sprintf(
                " [DataForSEO] search_volume: sent=%d returned=%d null_volume=%d null_competition=%d\n",
                count( $keywords ), count( $results ), $null_vol, $null_comp
            ),
            FILE_APPEND
        );

        return $results;
    }

    // =========================================================
    // SEO 難易度取得
    // =========================================================

    /**
     * DataForSEO Labs Keyword Overview API でキーワードの SEO 難易度を取得
     *
     * レスポンス構造（2026-04 実測）:
     *   tasks[0].result[0].items[n].keyword                                 ← キーワード
     *   tasks[0].result[0].items[n].keyword_properties.keyword_difficulty    ← 難易度
     *   tasks[0].result[0].items[n].keyword_info.search_volume               ← ボリューム
     *   tasks[0].result[0].items[n].keyword_info.competition                 ← 競合度
     *
     * @param array  $keywords       キーワード配列（最大1000件）
     * @param int    $location_code  ロケーションコード（デフォルト: Japan 2392）
     * @param string $language_code  言語コード（デフォルト: 'ja'）
     * @return array|WP_Error [ 'keyword' => { keyword_difficulty, search_volume, competition, cpc } ]
     */
    public function fetch_keyword_difficulty( array $keywords, int $location_code = 2392, string $language_code = 'ja' ) {
        if ( ! self::is_configured() ) {
            return new \WP_Error( 'not_configured', 'DataForSEO API が未設定です。' );
        }

        if ( empty( $keywords ) ) {
            return [];
        }

        // レートリミット
        if ( class_exists( 'Gcrev_Rate_Limiter' ) ) {
            Gcrev_Rate_Limiter::check_and_wait( 'dataforseo_kw', 10 );
        }

        // keyword_overview/live は keywords（複数形・配列）で送信
        $post_data = [
            [
                'keywords'      => array_values( $keywords ),
                'location_code' => $location_code,
                'language_code' => $language_code,
            ]
        ];

        $response = $this->api_request( '/dataforseo_labs/google/keyword_overview/live', $post_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = (int) ( $response['status_code'] ?? 0 );
        if ( $status_code !== 20000 ) {
            $msg = $response['status_message'] ?? 'Unknown error';
            error_log( "[GCREV][DataForSEO] keyword_overview API error: {$msg} (code: {$status_code})" );
            file_put_contents( '/tmp/gcrev_seo_debug.log',
                date( 'Y-m-d H:i:s' ) . " [DataForSEO] keyword_overview top-level error: code={$status_code} msg={$msg}\n",
                FILE_APPEND
            );
            return new \WP_Error( 'api_error', $msg );
        }

        // 正規化キー → 元キーワード のマップを構築
        $normalized_to_original = [];
        foreach ( $keywords as $orig_kw ) {
            $normalized_to_original[ $this->normalize_keyword( $orig_kw ) ] = $orig_kw;
        }

        $results = [];
        $tasks = $response['tasks'] ?? [];

        // 診断: 最初のレスポンス構造を1回だけログ出力
        $diag = [
            'tasks_count'      => count( $tasks ),
            'first_task_keys'  => [],
            'first_result_keys' => [],
            'first_items_keys' => [],
            'task_status'      => null,
            'task_msg'         => null,
        ];
        if ( ! empty( $tasks[0] ) ) {
            $diag['first_task_keys'] = array_keys( $tasks[0] );
            $diag['task_status']     = $tasks[0]['status_code'] ?? null;
            $diag['task_msg']        = $tasks[0]['status_message'] ?? null;
            $first_result = $tasks[0]['result'][0] ?? null;
            if ( is_array( $first_result ) ) {
                $diag['first_result_keys'] = array_keys( $first_result );
                $first_item = $first_result['items'][0] ?? null;
                if ( is_array( $first_item ) ) {
                    $diag['first_items_keys'] = array_keys( $first_item );
                }
            }
        }
        file_put_contents( '/tmp/gcrev_seo_debug.log',
            date( 'Y-m-d H:i:s' ) . " [DataForSEO] keyword_overview diag=" . wp_json_encode( $diag, JSON_UNESCAPED_UNICODE ) . "\n",
            FILE_APPEND
        );

        foreach ( $tasks as $task_i => $task ) {
            // タスクレベルのエラーはスキップ（ただしログに残す）
            $t_status = (int) ( $task['status_code'] ?? 0 );
            if ( $t_status !== 20000 ) {
                file_put_contents( '/tmp/gcrev_seo_debug.log',
                    date( 'Y-m-d H:i:s' ) . " [DataForSEO] keyword_overview task[{$task_i}] error: code={$t_status} msg=" . ( $task['status_message'] ?? '-' ) . "\n",
                    FILE_APPEND
                );
                continue;
            }

            // 正しい構造: result[n] は集約ブロック（keyword を持たない）、items[n] が個別キーワード
            foreach ( ( $task['result'] ?? [] ) as $result_block ) {
                $items = $result_block['items'] ?? [];
                if ( ! is_array( $items ) || empty( $items ) ) {
                    continue;
                }
                foreach ( $items as $item_idx => $item ) {
                    $kw = $item['keyword'] ?? '';
                    if ( $kw === '' ) {
                        continue;
                    }

                    // === 難易度: keyword_properties 優先、フォールバック多段 ===
                    $difficulty = $item['keyword_properties']['keyword_difficulty']
                        ?? $item['keyword_info']['keyword_difficulty']
                        ?? $item['keyword_difficulty']
                        ?? null;

                    // === ボリューム: 複数ソースから最も信頼できる値を採用 ===
                    // 優先度: keyword_info (Google Ads) > normalized_with_clickstream > normalized_with_bing > clickstream_keyword_info
                    $kw_info          = $item['keyword_info'] ?? [];
                    $kw_info_clicks   = $item['keyword_info_normalized_with_clickstream'] ?? [];
                    $kw_info_bing     = $item['keyword_info_normalized_with_bing'] ?? [];
                    $clickstream_info = $item['clickstream_keyword_info'] ?? [];

                    $sv = $kw_info['search_volume']
                        ?? $kw_info_clicks['search_volume']
                        ?? $kw_info_bing['search_volume']
                        ?? $clickstream_info['search_volume']
                        ?? null;

                    // === 競合度: keyword_info 優先、competition_level も考慮 ===
                    $cp = $kw_info['competition']
                        ?? $kw_info_clicks['competition']
                        ?? $kw_info_bing['competition']
                        ?? null;
                    if ( $cp === null ) {
                        foreach ( [ $kw_info, $kw_info_clicks, $kw_info_bing ] as $src ) {
                            if ( isset( $src['competition_level'] ) ) {
                                $lv = strtoupper( (string) $src['competition_level'] );
                                if ( $lv === 'HIGH' )        { $cp = 0.8; break; }
                                elseif ( $lv === 'MEDIUM' )   { $cp = 0.5; break; }
                                elseif ( $lv === 'LOW' )      { $cp = 0.2; break; }
                            }
                        }
                    }

                    $cpc = $kw_info['cpc']
                        ?? $kw_info_clicks['cpc']
                        ?? $kw_info_bing['cpc']
                        ?? null;

                    // 初回アイテムのみフィールド有無を詳細ログ出力（診断用）
                    if ( $task_i === 0 && $item_idx === 0 ) {
                        $field_diag = [
                            'keyword' => mb_substr( $kw, 0, 40 ),
                            'kw_props_has_diff'   => isset( $item['keyword_properties']['keyword_difficulty'] ),
                            'kw_props_diff_val'   => $item['keyword_properties']['keyword_difficulty'] ?? null,
                            'kw_info_has_vol'     => isset( $kw_info['search_volume'] ),
                            'kw_info_vol_val'     => $kw_info['search_volume'] ?? null,
                            'kw_info_has_comp'    => isset( $kw_info['competition'] ),
                            'kw_info_comp_val'    => $kw_info['competition'] ?? null,
                            'clicks_has_vol'      => isset( $kw_info_clicks['search_volume'] ),
                            'clicks_vol_val'      => $kw_info_clicks['search_volume'] ?? null,
                            'bing_has_vol'        => isset( $kw_info_bing['search_volume'] ),
                            'bing_vol_val'        => $kw_info_bing['search_volume'] ?? null,
                            'clickstream_has_vol' => isset( $clickstream_info['search_volume'] ),
                            'clickstream_vol_val' => $clickstream_info['search_volume'] ?? null,
                            'resolved_sv'         => $sv,
                            'resolved_cp'         => $cp,
                            'resolved_diff'       => $difficulty,
                        ];
                        file_put_contents( '/tmp/gcrev_seo_debug.log',
                            date( 'Y-m-d H:i:s' ) . " [DataForSEO] item[0] fields=" . wp_json_encode( $field_diag, JSON_UNESCAPED_UNICODE ) . "\n",
                            FILE_APPEND
                        );
                    }

                    $norm        = $this->normalize_keyword( $kw );
                    $original_kw = $normalized_to_original[ $norm ] ?? $kw;
                    $results[ $original_kw ] = [
                        'keyword_difficulty' => ( $difficulty !== null ) ? (int) $difficulty : null,
                        'search_volume'      => ( $sv !== null ) ? (int) $sv : null,
                        'competition'        => $cp,
                        'cpc'                => $cpc,
                    ];
                }
            }
        }

        return $results;
    }

    // =========================================================
    // Google Maps SERP 取得
    // =========================================================

    /**
     * Google Maps SERP を取得
     *
     * DataForSEO の Maps SERP API を使い、Google マップ検索結果を取得する。
     * 各アイテムにはビジネス名・住所・評価・口コミ数・カテゴリ等が含まれる。
     *
     * $location_coordinate が指定された場合は座標ベースで検索し、
     * $location_code は無視される（DataForSEO の排他制約）。
     *
     * @param string $keyword              検索キーワード
     * @param string $device               'desktop' or 'mobile'
     * @param int    $location_code        ロケーションコード（デフォルト: Japan 2392）
     * @param string $language_code        言語コード（デフォルト: 'ja'）
     * @param string $location_coordinate  座標文字列 "lat,lng,{zoom}z"（空文字なら location_code 使用）
     * @return array|WP_Error              Maps SERP items 配列
     */
    public function fetch_maps_serp(
        string $keyword,
        string $device = 'desktop',
        int    $location_code = 2392,
        string $language_code = 'ja',
        string $location_coordinate = ''
    ) {
        if ( ! self::is_configured() ) {
            return new \WP_Error( 'not_configured', 'DataForSEO API が未設定です。' );
        }

        if ( class_exists( 'Gcrev_Rate_Limiter' ) ) {
            Gcrev_Rate_Limiter::check_and_wait( 'dataforseo', self::RATE_LIMIT_PER_MINUTE );
        }

        $post_data = [
            [
                'keyword'       => $keyword,
                'language_code' => $language_code,
                'device'        => $device,
                'os'            => $device === 'desktop' ? 'windows' : 'android',
                'depth'         => 20,
            ]
        ];

        // location_coordinate と location_code は排他（DataForSEO 仕様）
        if ( $location_coordinate !== '' ) {
            $post_data[0]['location_coordinate'] = $location_coordinate;
        } else {
            $post_data[0]['location_code'] = $location_code;
        }

        $response = $this->api_request( '/serp/google/maps/live/advanced', $post_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = (int) ( $response['status_code'] ?? 0 );
        if ( $status_code !== 20000 ) {
            $msg = $response['status_message'] ?? 'Unknown error';
            error_log( "[GCREV][DataForSEO] Maps SERP error: {$msg} (code: {$status_code})" );
            return new \WP_Error( 'api_error', $msg );
        }

        $tasks = $response['tasks'] ?? [];
        if ( empty( $tasks ) || empty( $tasks[0]['result'] ) ) {
            return new \WP_Error( 'no_result', 'Maps SERP の応答にデータが含まれていません。' );
        }

        $result = $tasks[0]['result'][0];
        return $result['items'] ?? [];
    }

    // =========================================================
    // Google Local Finder SERP 取得
    // =========================================================

    /**
     * Google Local Finder SERP を取得
     *
     * DataForSEO の Local Finder SERP API を使い、ローカルファインダー検索結果を取得する。
     *
     * $location_coordinate が指定された場合は座標ベースで検索し、
     * $location_code は無視される（DataForSEO の排他制約）。
     *
     * @param string $keyword              検索キーワード
     * @param string $device               'desktop' or 'mobile'
     * @param int    $location_code        ロケーションコード（デフォルト: Japan 2392）
     * @param string $language_code        言語コード（デフォルト: 'ja'）
     * @param string $location_coordinate  座標文字列 "lat,lng,{zoom}z"（空文字なら location_code 使用）
     * @return array|WP_Error              Local Finder SERP items 配列
     */
    public function fetch_local_finder_serp(
        string $keyword,
        string $device = 'desktop',
        int    $location_code = 2392,
        string $language_code = 'ja',
        string $location_coordinate = ''
    ) {
        if ( ! self::is_configured() ) {
            return new \WP_Error( 'not_configured', 'DataForSEO API が未設定です。' );
        }

        if ( class_exists( 'Gcrev_Rate_Limiter' ) ) {
            Gcrev_Rate_Limiter::check_and_wait( 'dataforseo', self::RATE_LIMIT_PER_MINUTE );
        }

        $post_data = [
            [
                'keyword'       => $keyword,
                'language_code' => $language_code,
                'device'        => $device,
                'os'            => $device === 'desktop' ? 'windows' : 'android',
                'depth'         => 20,
            ]
        ];

        // location_coordinate と location_code は排他（DataForSEO 仕様）
        if ( $location_coordinate !== '' ) {
            $post_data[0]['location_coordinate'] = $location_coordinate;
        } else {
            $post_data[0]['location_code'] = $location_code;
        }

        $response = $this->api_request( '/serp/google/local_finder/live/advanced', $post_data );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = (int) ( $response['status_code'] ?? 0 );
        if ( $status_code !== 20000 ) {
            $msg = $response['status_message'] ?? 'Unknown error';
            error_log( "[GCREV][DataForSEO] Local Finder SERP error: {$msg} (code: {$status_code})" );
            return new \WP_Error( 'api_error', $msg );
        }

        $tasks = $response['tasks'] ?? [];
        if ( empty( $tasks ) || empty( $tasks[0]['result'] ) ) {
            return new \WP_Error( 'no_result', 'Local Finder SERP の応答にデータが含まれていません。' );
        }

        $result = $tasks[0]['result'][0];
        return $result['items'] ?? [];
    }

    // =========================================================
    // Maps/Local Finder ビジネスマッチ
    // =========================================================

    /**
     * Maps/Local Finder 結果からターゲットドメインのビジネスを検索
     *
     * organic SERP の find_domain_in_results() と同様の www./非www. 正規化でマッチ。
     * マッチ時はアイテム全体を返す（店舗情報・評価等を含む）。
     *
     * @param array  $items         Maps/Local Finder items 配列
     * @param string $target_domain ターゲットドメイン（例: 'example.com'）
     * @return array|null マッチしたアイテム全体、見つからない場合 null
     */
    public function find_business_in_maps_results( array $items, string $target_domain ): ?array {
        // URL が渡された場合はホスト部分を抽出し、www. を除いた正規化ドメインにする
        $host = $target_domain;
        if ( preg_match( '#^https?://#i', $target_domain ) ) {
            $parsed = wp_parse_url( $target_domain );
            $host   = $parsed['host'] ?? $target_domain;
        }
        $normalized_target = preg_replace( '/^www\./i', '', strtolower( trim( $host, '/' ) ) );

        // 1st pass: domain フィールドで照合
        foreach ( $items as $item ) {
            $domain = $item['domain'] ?? '';
            if ( $domain === '' ) {
                continue;
            }

            $item_domain = preg_replace( '/^www\./i', '', strtolower( trim( $domain, '/' ) ) );

            if ( $item_domain === $normalized_target ) {
                return $item;
            }
        }

        // 2nd pass: domain が空のアイテムに対して url フィールドからドメインを抽出して照合
        // DataForSEO が domain を返さなくても url には含まれていることがある
        foreach ( $items as $item ) {
            $domain = $item['domain'] ?? '';
            if ( $domain !== '' ) {
                continue; // 1st pass で既にチェック済み
            }
            $url = $item['url'] ?? '';
            if ( $url === '' ) {
                continue;
            }
            $url_parsed = wp_parse_url( $url );
            $url_host   = $url_parsed['host'] ?? '';
            if ( $url_host === '' ) {
                continue;
            }
            $url_domain = preg_replace( '/^www\./i', '', strtolower( $url_host ) );
            if ( $url_domain === $normalized_target ) {
                return $item;
            }
        }

        return null;
    }

    // =========================================================
    // キーワード正規化（内部）
    // =========================================================

    /**
     * キーワードテキストを正規化する（レスポンス照合用）
     *
     * DataForSEO API はレスポンスでキーワードの表記を微妙に変える場合がある
     * （全角/半角スペース、大文字/小文字など）ため、正規化して比較する。
     *
     * @param string $kw キーワードテキスト
     * @return string 正規化済みキーワード
     */
    private function normalize_keyword( string $kw ): string {
        $kw = mb_strtolower( trim( $kw ), 'UTF-8' );
        // 全角スペースを半角に統一
        $kw = str_replace( "\xE3\x80\x80", ' ', $kw );
        // 連続空白を単一半角スペースに
        return preg_replace( '/\s+/u', ' ', $kw );
    }

    // =========================================================
    // Ranked Keywords（競合ドメインの順位＋推定流入数）
    // =========================================================

    /**
     * DataForSEO Labs Ranked Keywords API で
     * 指定ドメインがランクインしているキーワード・順位・推定流入数を取得
     *
     * @param string $target        ドメイン（例: example.com）
     * @param int    $location_code ロケーションコード（デフォルト: Japan 2392）
     * @param string $language_code 言語コード（デフォルト: 'ja'）
     * @param int    $limit         取得件数上限（デフォルト: 100）
     * @return array|WP_Error [ 'keyword_text' => ['rank' => int, 'etv' => float, 'volume' => int], ... ]
     */
    public function fetch_ranked_keywords(
        string $target,
        int    $location_code = 2392,
        string $language_code = 'ja',
        int    $limit         = 700
    ) {
        if ( ! self::is_configured() ) {
            return new \WP_Error( 'not_configured', 'DataForSEO API が未設定です。' );
        }

        if ( class_exists( 'Gcrev_Rate_Limiter' ) ) {
            Gcrev_Rate_Limiter::check_and_wait( 'dataforseo', self::RATE_LIMIT_PER_MINUTE );
        }

        $post_data = [
            [
                'target'        => $target,
                'location_code' => $location_code,
                'language_code' => $language_code,
                'limit'         => $limit,
                'order_by'      => [ 'ranked_serp_element.serp_item.etv,desc' ],
            ]
        ];

        $response = $this->api_request( '/dataforseo_labs/google/ranked_keywords/live', $post_data );

        if ( is_wp_error( $response ) ) {
            file_put_contents( '/tmp/gcrev_dataforseo_debug.log',
                date( 'Y-m-d H:i:s' ) . " ranked_keywords ERROR [{$target}]: " . $response->get_error_message() . "\n",
                FILE_APPEND
            );
            return $response;
        }

        $status_code = (int) ( $response['status_code'] ?? 0 );
        if ( $status_code !== 20000 ) {
            $msg = $response['status_message'] ?? 'Unknown error';
            file_put_contents( '/tmp/gcrev_dataforseo_debug.log',
                date( 'Y-m-d H:i:s' ) . " ranked_keywords API error [{$target}]: {$msg} (code: {$status_code})\n",
                FILE_APPEND
            );
            return new \WP_Error( 'api_error', $msg );
        }

        $tasks = $response['tasks'] ?? [];
        if ( empty( $tasks ) || empty( $tasks[0]['result'] ) ) {
            return [];
        }

        $items = $tasks[0]['result'][0]['items'] ?? [];
        $results = [];

        foreach ( $items as $item ) {
            $kw_data = $item['keyword_data'] ?? [];
            $kw_text = $kw_data['keyword'] ?? '';
            if ( $kw_text === '' ) {
                continue;
            }

            $serp_item = $item['ranked_serp_element']['serp_item'] ?? [];
            $kw_info   = $kw_data['keyword_info'] ?? [];

            $norm = $this->normalize_keyword( $kw_text );
            $results[ $norm ] = [
                'rank'   => $serp_item['rank_group'] ?? null,
                'etv'    => $serp_item['etv'] ?? null,
                'volume' => $kw_info['search_volume'] ?? null,
                'url'    => $serp_item['url'] ?? '',
            ];
        }

        return $results;
    }

    // =========================================================
    // HTTP リクエスト（内部）
    // =========================================================

    /**
     * DataForSEO API へ HTTP POST リクエストを送信
     *
     * @param string $endpoint エンドポイントパス
     * @param array  $post_data リクエストボディ
     * @return array|WP_Error レスポンスボディ（デコード済み）
     */
    private function api_request( string $endpoint, array $post_data ) {
        $url = self::API_BASE . $endpoint;

        $auth = base64_encode( DATAFORSEO_LOGIN . ':' . DATAFORSEO_PASSWORD );

        $response = wp_remote_post( $url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $post_data, JSON_UNESCAPED_UNICODE ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[GCREV][DataForSEO] HTTP error: ' . $response->get_error_message() );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body, true );

        if ( $status_code === 401 || $status_code === 403 ) {
            error_log( '[GCREV][DataForSEO] Authentication failed (HTTP ' . $status_code . ')' );
            return new \WP_Error( 'auth_failed', 'DataForSEO 認証に失敗しました。ログイン情報を確認してください。' );
        }

        if ( $status_code >= 400 ) {
            $msg = $decoded['status_message'] ?? "HTTP {$status_code}";
            error_log( "[GCREV][DataForSEO] HTTP error {$status_code}: {$msg}" );
            return new \WP_Error( 'http_error', $msg );
        }

        if ( ! is_array( $decoded ) ) {
            error_log( '[GCREV][DataForSEO] Invalid JSON response' );
            return new \WP_Error( 'json_error', 'API レスポンスの JSON 解析に失敗しました。' );
        }

        return $decoded;
    }
}
