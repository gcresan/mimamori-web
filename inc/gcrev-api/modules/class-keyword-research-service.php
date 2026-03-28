<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }
if ( class_exists('Gcrev_Keyword_Research_Service') ) { return; }

/**
 * キーワード調査サービス（Phase 2）
 *
 * クライアント情報・GSCデータ・競合URL解析・DataForSEO実データを統合し、
 * Gemini AIでSEOキーワード候補を調査・提案・保存する。
 */
class Gcrev_Keyword_Research_Service {

    private Gcrev_AI_Client  $ai;
    private Gcrev_Config     $config;
    private ?object          $dataforseo;
    private ?object          $google_ads;

    private const DEBUG_LOG = '/tmp/gcrev_seo_debug.log';
    private const VOLUME_CACHE_TTL = 86400; // 24h
    private const PLANNER_BATCH_SIZE = 20;  // generateKeywordIdeas の1回あたり上限

    /**
     * キーワードグループ定義
     */
    private const GROUPS = [
        'immediate'           => '今すぐ狙うべきキーワード',
        'local_seo'           => '地域SEO向けキーワード',
        'comparison'          => '比較・検討流入向けキーワード',
        'column'              => 'コラム記事向きキーワード',
        'service_page'        => 'サービスページ向きキーワード',
        'competitor_core'     => '競合も狙っている本命キーワード',
        'competitor_longterm' => '競合が強いが中長期で狙うべきキーワード',
        'competitor_gap'      => '競合が弱く自社が狙いやすいキーワード',
        'competitor_compare'  => '比較検討流入を取れるキーワード',
    ];

    public function __construct( Gcrev_AI_Client $ai, Gcrev_Config $config, $dataforseo = null, $google_ads = null ) {
        $this->ai         = $ai;
        $this->config     = $config;
        $this->dataforseo = $dataforseo;
        $this->google_ads = $google_ads;
    }

    // =========================================================
    // メイン調査実行
    // =========================================================

    /**
     * キーワード調査を実行
     */
    public function research( int $user_id, array $seed_keywords = [], bool $enable_competitor = false ): array {
        $this->log( "research start: user_id={$user_id}, seeds=" . count( $seed_keywords ) . ", competitor={$enable_competitor}" );

        $data_sources = [ 'AI' ];

        // 1. クライアント情報取得
        $settings = gcrev_get_client_settings( $user_id );
        $site_url = $settings['site_url'] ?? '';

        if ( empty( $site_url ) ) {
            return [
                'success' => false,
                'error'   => 'クライアントのサイトURLが設定されていません。先にクライアント設定でサイトURLを登録してください。',
            ];
        }

        $area_label     = function_exists( 'gcrev_get_client_area_label' )
            ? gcrev_get_client_area_label( $settings ) : '';
        $biz_type_label = function_exists( 'gcrev_get_business_type_label' )
            ? gcrev_get_business_type_label( $settings['business_type'] ?? [] ) : '';
        $industry_label   = $settings['industry'] ?? '';
        $industry_detail  = $settings['industry_detail'] ?? '';
        $persona_one_liner  = $settings['persona_one_liner'] ?? '';
        $persona_detail     = $settings['persona_detail_text'] ?? '';
        $persona_attributes = $settings['persona_attributes'] ?? [];
        $persona_decision   = $settings['persona_decision_factors'] ?? [];

        // 2. GSCキーワード取得
        $gsc_keywords = [];
        try {
            $gsc = new Gcrev_GSC_Fetcher( $this->config );
            $tz  = wp_timezone();
            $end   = new \DateTimeImmutable( 'now', $tz );
            $start = $end->modify( '-90 days' );
            $gsc_data = $gsc->fetch_gsc_data(
                $site_url,
                $start->format( 'Y-m-d' ),
                $end->format( 'Y-m-d' )
            );
            if ( ! empty( $gsc_data['keywords'] ) ) {
                $gsc_keywords = array_slice( $gsc_data['keywords'], 0, 50 );
                $data_sources[] = 'GSC';
            }
            $this->log( "GSC keywords fetched: " . count( $gsc_keywords ) );
        } catch ( \Throwable $e ) {
            $this->log( "GSC fetch error: " . $e->getMessage() );
        }

        // 2.5. Keyword Planner 関連キーワード取得
        $planner_keywords = [];
        $planner_seeds = array_merge(
            $seed_keywords,
            array_column( array_slice( $gsc_keywords, 0, 20 ), 'query' )
        );
        $planner_seeds = array_unique( array_filter( $planner_seeds ) );
        if ( ! empty( $planner_seeds ) ) {
            $planner_keywords = $this->fetch_related_keywords_from_planner( $user_id, $planner_seeds );
            if ( ! empty( $planner_keywords ) ) {
                $data_sources[] = 'Google Ads Keyword Planner';
                $this->log( "Keyword Planner related keywords: " . count( $planner_keywords ) );
            }
        }

        // 3. 競合URL解析
        $competitor_data = [];
        $ref_urls = $settings['persona_reference_urls'] ?? [];
        if ( $enable_competitor && ! empty( $ref_urls ) ) {
            $competitor_data = $this->fetch_competitor_data( $ref_urls );
            $ok_count = 0;
            foreach ( $competitor_data as $cd ) {
                if ( ( $cd['status'] ?? '' ) === 'ok' ) { $ok_count++; }
            }
            if ( $ok_count > 0 ) {
                $data_sources[] = '競合分析';
            }
            $this->log( "Competitor data fetched: " . count( $competitor_data ) . " total, {$ok_count} ok" );
        }

        // 4. プロンプト構築
        $prompt = $this->build_prompt(
            $site_url, $area_label, $industry_label, $industry_detail, $biz_type_label,
            $persona_one_liner, $persona_detail, $persona_attributes, $persona_decision,
            $settings, $gsc_keywords, $seed_keywords, $competitor_data, $planner_keywords
        );

        // 5. Gemini呼び出し
        try {
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'       => 0.7,
                'max_output_tokens' => 8192,
            ] );
            $this->log( "Gemini response length: " . strlen( $raw ) );
        } catch ( \Throwable $e ) {
            $this->log( "Gemini API error: " . $e->getMessage() );
            return [
                'success' => false,
                'error'   => 'AI分析中にエラーが発生しました: ' . $e->getMessage(),
            ];
        }

        // 6. レスポンスパース
        $parsed = $this->parse_response( $raw );
        if ( $parsed === null ) {
            $this->log( "Parse error. Raw (first 1000): " . substr( $raw, 0, 1000 ) );
            return [
                'success' => false,
                'error'   => 'AI応答の解析に失敗しました。再度お試しください。',
            ];
        }

        // 7. キーワードエンリッチメント（Google Ads + DataForSEO 統合）
        $volume_source = 'none';
        $all_keywords = $this->extract_all_keywords( $parsed['groups'] ?? [] );
        if ( ! empty( $all_keywords ) ) {
            $enrichment = $this->enrich_keywords( $user_id, $all_keywords );
            $volume_data   = $enrichment['data'] ?? [];
            $volume_source = $enrichment['source'] ?? 'none';
            if ( ! empty( $volume_data ) ) {
                $this->merge_volume_into_groups( $parsed['groups'], $volume_data );
                $this->log( "Enrichment complete: source={$volume_source}, keywords=" . count( $volume_data ) );
            }
        }

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );

        $result = [
            'success'          => true,
            'summary'          => $parsed['summary'] ?? [],
            'groups'           => $parsed['groups'] ?? [],
            'competitor_data'  => $competitor_data,
            'meta'             => [
                'user_id'             => $user_id,
                'site_url'            => $site_url,
                'area'                => $area_label,
                'industry'            => $industry_label,
                'gsc_count'           => count( $gsc_keywords ),
                'seed_count'          => count( $seed_keywords ),
                'competitor_count'    => count( $competitor_data ),
                'planner_related'     => count( $planner_keywords ),
                'volume_source'       => $volume_source,
                'data_sources'        => $data_sources,
                'generated_at'        => $now,
            ],
        ];

        // 8. 結果保存
        try {
            $post_id = $this->save_result( $user_id, $result );
            $result['meta']['research_id'] = $post_id;
            $this->log( "Result saved: post_id={$post_id}" );
        } catch ( \Throwable $e ) {
            $this->log( "Save error: " . $e->getMessage() );
        }

        return $result;
    }

    // =========================================================
    // 競合URL解析
    // =========================================================

    /**
     * 競合・参考URLのHTML取得・解析
     */
    private function fetch_competitor_data( array $ref_urls ): array {
        $results = [];

        foreach ( $ref_urls as $i => $entry ) {
            $url  = $entry['url'] ?? '';
            $note = $entry['note'] ?? '';

            if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                continue;
            }

            // 0.5秒待機（初回以外）
            if ( $i > 0 ) {
                usleep( 500000 );
            }

            $item = [
                'url'              => $url,
                'note'             => $note,
                'title'            => '',
                'meta_description' => '',
                'headings'         => [ 'h1' => [], 'h2' => [], 'h3' => [] ],
                'body_excerpt'     => '',
                'status'           => 'error',
                'error'            => '',
            ];

            try {
                $response = wp_remote_get( $url, [
                    'timeout'    => 15,
                    'user-agent' => 'MimamoriSEO/1.0 (+https://mimamori-web.jp)',
                    'sslverify'  => false,
                ] );

                if ( is_wp_error( $response ) ) {
                    $item['error'] = $response->get_error_message();
                    $this->log( "Competitor fetch error [{$url}]: " . $item['error'] );
                    $results[] = $item;
                    continue;
                }

                $status_code = wp_remote_retrieve_response_code( $response );
                if ( $status_code !== 200 ) {
                    $item['error'] = "HTTP {$status_code}";
                    $this->log( "Competitor fetch error [{$url}]: HTTP {$status_code}" );
                    $results[] = $item;
                    continue;
                }

                $html = wp_remote_retrieve_body( $response );
                if ( empty( $html ) ) {
                    $item['error'] = 'Empty response body';
                    $results[] = $item;
                    continue;
                }

                $parsed = $this->parse_competitor_html( $html );
                $item = array_merge( $item, $parsed );
                $item['status'] = 'ok';

            } catch ( \Throwable $e ) {
                $item['error'] = $e->getMessage();
                $this->log( "Competitor exception [{$url}]: " . $e->getMessage() );
            }

            $results[] = $item;
        }

        return $results;
    }

    /**
     * 競合HTMLからタイトル・見出し・メタ情報を抽出
     */
    private function parse_competitor_html( string $html ): array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        @$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        $xpath = new \DOMXPath( $dom );

        // title
        $title = '';
        $titles = $xpath->query( '//title' );
        if ( $titles && $titles->length > 0 ) {
            $title = trim( $titles->item( 0 )->textContent );
        }

        // meta description
        $meta_desc = '';
        $metas = $xpath->query( '//meta[@name="description"]/@content' );
        if ( $metas && $metas->length > 0 ) {
            $meta_desc = trim( $metas->item( 0 )->nodeValue );
        }

        // headings
        $headings = [ 'h1' => [], 'h2' => [], 'h3' => [] ];
        foreach ( [ 'h1', 'h2', 'h3' ] as $tag ) {
            $nodes = $xpath->query( "//{$tag}" );
            if ( $nodes ) {
                $max = ( $tag === 'h3' ) ? 10 : 20;
                for ( $j = 0; $j < min( $nodes->length, $max ); $j++ ) {
                    $text = trim( $nodes->item( $j )->textContent );
                    if ( $text !== '' && mb_strlen( $text ) < 200 ) {
                        $headings[ $tag ][] = $text;
                    }
                }
            }
        }

        // body excerpt
        $body_excerpt = '';
        $body_nodes = $xpath->query( '//body' );
        if ( $body_nodes && $body_nodes->length > 0 ) {
            $raw_text = $body_nodes->item( 0 )->textContent;
            $raw_text = preg_replace( '/\s+/', ' ', $raw_text );
            $body_excerpt = mb_substr( trim( $raw_text ), 0, 500 );
        }

        return [
            'title'            => $title,
            'meta_description' => $meta_desc,
            'headings'         => $headings,
            'body_excerpt'     => $body_excerpt,
        ];
    }

    // =========================================================
    // Google Ads Keyword Planner 連携
    // =========================================================

    /**
     * Keyword Planner で関連キーワード候補を取得
     *
     * @param  int   $user_id        ユーザーID（キャッシュキー用）
     * @param  array $seed_keywords  シードキーワード（上位20件を使用）
     * @return array  関連キーワードのテキスト配列（最大50件）
     */
    private function fetch_related_keywords_from_planner( int $user_id, array $seed_keywords ): array {
        if ( $this->google_ads === null || empty( $seed_keywords ) ) {
            return [];
        }

        // キャッシュ確認
        $cache_key = 'gcrev_kwplanner_rel_' . $user_id . '_' . substr( md5( implode( '|', $seed_keywords ) ), 0, 12 );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            $this->log( "Keyword Planner related: cache hit ({$cache_key})" );
            return $cached;
        }

        $seeds = array_slice( $seed_keywords, 0, self::PLANNER_BATCH_SIZE );

        try {
            $token = $this->google_ads->get_access_token();
            if ( is_wp_error( $token ) ) {
                $this->log( 'Keyword Planner token error: ' . $token->get_error_message() );
                return [];
            }

            $customer_id = preg_replace( '/[^0-9]/', '', GOOGLE_ADS_CUSTOMER_ID );
            $results = $this->google_ads->generate_keyword_ideas( $token, $customer_id, $seeds );

            if ( is_wp_error( $results ) ) {
                $this->log( 'Keyword Planner related error: ' . $results->get_error_message() );
                return [];
            }

            // シードキーワードを除外し、検索ボリューム降順で上位50件を取得
            $seed_set = array_flip( array_map( 'mb_strtolower', $seeds ) );
            $ideas = [];
            foreach ( $results as $item ) {
                $text = $item['text'] ?? '';
                if ( $text === '' || isset( $seed_set[ mb_strtolower( $text ) ] ) ) {
                    continue;
                }
                $volume = (int) ( $item['keywordIdeaMetrics']['avgMonthlySearches'] ?? 0 );
                $ideas[] = [ 'text' => $text, 'volume' => $volume ];
            }

            // ボリューム降順ソート
            usort( $ideas, function( $a, $b ) { return $b['volume'] - $a['volume']; } );
            $related = array_column( array_slice( $ideas, 0, 50 ), 'text' );

            set_transient( $cache_key, $related, self::VOLUME_CACHE_TTL );
            $this->log( 'Keyword Planner related: ' . count( $related ) . ' keywords found' );
            return $related;

        } catch ( \Throwable $e ) {
            $this->log( 'Keyword Planner related exception: ' . $e->getMessage() );
            return [];
        }
    }

    /**
     * Keyword Planner で検索ボリューム・競合性・CPC を取得
     *
     * キーワードを20件ずつバッチ処理し、結果をマージする。
     *
     * @param  array $keywords  キーワード配列
     * @return array  [ 'keyword' => ['volume' => int, 'competition' => float|null, 'cpc' => float|null] ]
     */
    private function fetch_keyword_planner_volume( array $keywords ): array {
        if ( $this->google_ads === null || empty( $keywords ) ) {
            return [];
        }

        try {
            $token = $this->google_ads->get_access_token();
            if ( is_wp_error( $token ) ) {
                $this->log( 'Keyword Planner volume token error: ' . $token->get_error_message() );
                return [];
            }

            $customer_id = preg_replace( '/[^0-9]/', '', GOOGLE_ADS_CUSTOMER_ID );
            $batches = array_chunk( $keywords, self::PLANNER_BATCH_SIZE );
            $merged = [];

            foreach ( $batches as $i => $batch ) {
                $results = $this->google_ads->generate_keyword_ideas( $token, $customer_id, $batch );

                if ( is_wp_error( $results ) ) {
                    $this->log( "Keyword Planner volume batch {$i} error: " . $results->get_error_message() );
                    continue;
                }

                foreach ( $results as $item ) {
                    $text = $item['text'] ?? '';
                    if ( $text === '' ) { continue; }

                    $metrics = $item['keywordIdeaMetrics'] ?? [];
                    $volume  = isset( $metrics['avgMonthlySearches'] )
                        ? (int) $metrics['avgMonthlySearches'] : null;

                    // competition: HIGH→0.8, MEDIUM→0.5, LOW→0.2, UNSPECIFIED→null
                    $comp_str = $metrics['competition'] ?? '';
                    $competition = null;
                    if ( $comp_str === 'HIGH' )        { $competition = 0.8; }
                    elseif ( $comp_str === 'MEDIUM' )   { $competition = 0.5; }
                    elseif ( $comp_str === 'LOW' )      { $competition = 0.2; }

                    // CPC: highTopOfPageBidMicros → 通貨単位に変換
                    $cpc = null;
                    if ( isset( $metrics['highTopOfPageBidMicros'] ) ) {
                        $cpc = round( (int) $metrics['highTopOfPageBidMicros'] / 1000000, 2 );
                    }

                    $merged[ mb_strtolower( $text ) ] = [
                        'volume'      => $volume,
                        'competition' => $competition,
                        'cpc'         => $cpc,
                    ];
                }

                $this->log( "Keyword Planner volume batch {$i}: " . count( $results ) . " results" );
            }

            return $merged;

        } catch ( \Throwable $e ) {
            $this->log( 'Keyword Planner volume exception: ' . $e->getMessage() );
            return [];
        }
    }

    /**
     * キーワードエンリッチメント — Google Ads + DataForSEO 統合
     *
     * 1. Google Ads Keyword Planner → volume/competition/CPC（主データソース）
     * 2. Planner で取得できなかったキーワード → DataForSEO volume（フォールバック）
     * 3. DataForSEO → SEO difficulty（Planner にないため常に使用）
     *
     * @param  int   $user_id   ユーザーID
     * @param  array $keywords  キーワード配列
     * @return array  [ 'data' => merged, 'source' => 'google_ads'|'dataforseo'|'mixed'|'none' ]
     */
    private function enrich_keywords( int $user_id, array $keywords ): array {
        if ( empty( $keywords ) ) {
            return [ 'data' => [], 'source' => 'none' ];
        }

        // キャッシュ確認
        $cache_key = 'gcrev_kwenrich_' . $user_id . '_' . substr( md5( implode( '|', $keywords ) ), 0, 12 );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            $this->log( "enrich_keywords: cache hit ({$cache_key})" );
            return $cached;
        }

        $merged = [];
        $volume_source = 'none';

        // Step 1: Google Ads Keyword Planner でボリューム取得
        $planner_data = $this->fetch_keyword_planner_volume( $keywords );
        if ( ! empty( $planner_data ) ) {
            $volume_source = 'google_ads';
            foreach ( $planner_data as $kw => $data ) {
                $merged[ $kw ] = [
                    'volume'      => $data['volume'],
                    'competition' => $data['competition'],
                    'cpc'         => $data['cpc'],
                    'difficulty'  => null,
                ];
            }
            $this->log( 'enrich_keywords: Planner data for ' . count( $planner_data ) . ' keywords' );
        }

        // Step 2: Planner で取得できなかったキーワード → DataForSEO フォールバック
        $missing = [];
        foreach ( $keywords as $kw ) {
            if ( ! isset( $merged[ mb_strtolower( $kw ) ] ) ) {
                $missing[] = $kw;
            }
        }

        if ( ! empty( $missing ) && $this->dataforseo !== null ) {
            try {
                if ( method_exists( $this->dataforseo, 'is_configured' ) && $this->dataforseo::is_configured() ) {
                    $vol_result = $this->dataforseo->fetch_search_volume( $missing );
                    if ( ! is_wp_error( $vol_result ) && ! empty( $vol_result ) ) {
                        if ( $volume_source === 'google_ads' ) {
                            $volume_source = 'mixed';
                        } else {
                            $volume_source = 'dataforseo';
                        }
                        foreach ( $vol_result as $kw => $data ) {
                            $merged[ mb_strtolower( $kw ) ] = [
                                'volume'      => $data['search_volume'] ?? null,
                                'competition' => $data['competition'] ?? null,
                                'cpc'         => $data['cpc'] ?? null,
                                'difficulty'  => null,
                            ];
                        }
                        $this->log( 'enrich_keywords: DataForSEO fallback for ' . count( $vol_result ) . ' keywords' );
                    }
                }
            } catch ( \Throwable $e ) {
                $this->log( 'enrich_keywords: DataForSEO volume exception: ' . $e->getMessage() );
            }
        }

        // Planner が完全に失敗した場合 → DataForSEO で全件取得
        if ( empty( $merged ) && $this->dataforseo !== null ) {
            try {
                if ( method_exists( $this->dataforseo, 'is_configured' ) && $this->dataforseo::is_configured() ) {
                    $vol_result = $this->dataforseo->fetch_search_volume( $keywords );
                    if ( ! is_wp_error( $vol_result ) && ! empty( $vol_result ) ) {
                        $volume_source = 'dataforseo';
                        foreach ( $vol_result as $kw => $data ) {
                            $merged[ mb_strtolower( $kw ) ] = [
                                'volume'      => $data['search_volume'] ?? null,
                                'competition' => $data['competition'] ?? null,
                                'cpc'         => $data['cpc'] ?? null,
                                'difficulty'  => null,
                            ];
                        }
                        $this->log( 'enrich_keywords: DataForSEO full fallback for ' . count( $vol_result ) . ' keywords' );
                    }
                }
            } catch ( \Throwable $e ) {
                $this->log( 'enrich_keywords: DataForSEO full fallback exception: ' . $e->getMessage() );
            }
        }

        // Step 3: DataForSEO で SEO 難易度を常に取得（Planner にない指標）
        if ( $this->dataforseo !== null ) {
            try {
                if ( method_exists( $this->dataforseo, 'is_configured' ) && $this->dataforseo::is_configured() ) {
                    $diff_result = $this->dataforseo->fetch_keyword_difficulty( $keywords );
                    if ( ! is_wp_error( $diff_result ) && ! empty( $diff_result ) ) {
                        foreach ( $diff_result as $kw => $data ) {
                            $kw_lower = mb_strtolower( $kw );
                            if ( ! isset( $merged[ $kw_lower ] ) ) {
                                $merged[ $kw_lower ] = [
                                    'volume'      => null,
                                    'competition' => null,
                                    'cpc'         => null,
                                    'difficulty'  => null,
                                ];
                            }
                            $merged[ $kw_lower ]['difficulty'] = $data['keyword_difficulty'] ?? null;
                        }
                        $this->log( 'enrich_keywords: DataForSEO difficulty for ' . count( $diff_result ) . ' keywords' );
                    }
                }
            } catch ( \Throwable $e ) {
                $this->log( 'enrich_keywords: DataForSEO difficulty exception: ' . $e->getMessage() );
            }
        }

        $result = [ 'data' => $merged, 'source' => $volume_source ];

        if ( ! empty( $merged ) ) {
            set_transient( $cache_key, $result, self::VOLUME_CACHE_TTL );
        }

        return $result;
    }

    // =========================================================
    // DataForSEO 実データ取得（レガシー — enrich_keywords() からは未使用）
    // =========================================================

    /**
     * DataForSEOで検索ボリューム・競合度・難易度を取得
     */
    private function enrich_with_dataforseo( int $user_id, array $keywords ): array {
        if ( $this->dataforseo === null ) {
            return [];
        }

        if ( ! method_exists( $this->dataforseo, 'is_configured' ) || ! $this->dataforseo::is_configured() ) {
            $this->log( "DataForSEO not configured, skipping enrichment" );
            return [];
        }

        // Transientキャッシュチェック
        sort( $keywords );
        $cache_key = 'gcrev_kwvol_' . $user_id . '_' . substr( md5( implode( '|', $keywords ) ), 0, 12 );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            $this->log( "DataForSEO cache hit: {$cache_key}" );
            return $cached;
        }

        $merged = [];

        // 検索ボリューム取得
        try {
            $vol_result = $this->dataforseo->fetch_search_volume( $keywords );
            if ( ! is_wp_error( $vol_result ) && is_array( $vol_result ) ) {
                foreach ( $vol_result as $kw => $data ) {
                    $merged[ $kw ] = [
                        'volume'      => $data['search_volume'] ?? null,
                        'competition' => $data['competition'] ?? null,
                        'cpc'         => $data['cpc'] ?? null,
                        'difficulty'  => null,
                    ];
                }
            } else {
                $err = is_wp_error( $vol_result ) ? $vol_result->get_error_message() : 'unknown';
                $this->log( "DataForSEO search_volume error: {$err}" );
            }
        } catch ( \Throwable $e ) {
            $this->log( "DataForSEO search_volume exception: " . $e->getMessage() );
        }

        // 難易度取得
        try {
            $diff_result = $this->dataforseo->fetch_keyword_difficulty( $keywords );
            if ( ! is_wp_error( $diff_result ) && is_array( $diff_result ) ) {
                foreach ( $diff_result as $kw => $data ) {
                    if ( ! isset( $merged[ $kw ] ) ) {
                        $merged[ $kw ] = [
                            'volume'      => null,
                            'competition' => null,
                            'cpc'         => null,
                            'difficulty'  => null,
                        ];
                    }
                    $merged[ $kw ]['difficulty'] = $data['keyword_difficulty'] ?? null;
                }
            } else {
                $err = is_wp_error( $diff_result ) ? $diff_result->get_error_message() : 'unknown';
                $this->log( "DataForSEO keyword_difficulty error: {$err}" );
            }
        } catch ( \Throwable $e ) {
            $this->log( "DataForSEO keyword_difficulty exception: " . $e->getMessage() );
        }

        if ( ! empty( $merged ) ) {
            set_transient( $cache_key, $merged, self::VOLUME_CACHE_TTL );
        }

        return $merged;
    }

    /**
     * 全グループからキーワード文字列を抽出
     */
    private function extract_all_keywords( array $groups ): array {
        $keywords = [];
        foreach ( $groups as $items ) {
            if ( ! is_array( $items ) ) { continue; }
            foreach ( $items as $item ) {
                $kw = $item['keyword'] ?? '';
                if ( $kw !== '' ) {
                    $keywords[] = $kw;
                }
            }
        }
        return array_values( array_unique( $keywords ) );
    }

    /**
     * DataForSEOデータをグループのキーワードにマージ
     */
    private function merge_volume_into_groups( array &$groups, array $volume_data ): void {
        foreach ( $groups as $group_key => &$items ) {
            if ( ! is_array( $items ) ) { continue; }
            foreach ( $items as &$item ) {
                $kw = $item['keyword'] ?? '';
                // enrich_keywords() は mb_strtolower キーを使うため両方チェック
                $vd = $volume_data[ $kw ] ?? $volume_data[ mb_strtolower( $kw ) ] ?? null;
                if ( $vd !== null ) {
                    $item['volume']      = $vd['volume'];
                    $item['competition'] = $vd['competition'];
                    $item['cpc']         = $vd['cpc'] ?? null;
                    $item['difficulty']  = $vd['difficulty'];
                }
            }
            unset( $item );
        }
        unset( $items );
    }

    // =========================================================
    // 結果保存・取得
    // =========================================================

    /**
     * 調査結果をCPTに保存
     */
    public function save_result( int $user_id, array $result ): int {
        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );

        $post_id = wp_insert_post( [
            'post_type'   => 'gcrev_kw_research',
            'post_title'  => "KWR_{$user_id}_" . date( 'Y-m-d' ),
            'post_status' => 'publish',
        ] );

        if ( is_wp_error( $post_id ) || $post_id === 0 ) {
            throw new \RuntimeException( 'Failed to save keyword research result' );
        }

        update_post_meta( $post_id, '_gcrev_kwr_user_id', $user_id );
        update_post_meta( $post_id, '_gcrev_kwr_summary',
            wp_json_encode( $result['summary'] ?? [], JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $post_id, '_gcrev_kwr_groups',
            wp_json_encode( $result['groups'] ?? [], JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $post_id, '_gcrev_kwr_competitor_data',
            wp_json_encode( $result['competitor_data'] ?? [], JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $post_id, '_gcrev_kwr_meta',
            wp_json_encode( $result['meta'] ?? [], JSON_UNESCAPED_UNICODE ) );
        update_post_meta( $post_id, '_gcrev_kwr_created_at', $now );

        return $post_id;
    }

    /**
     * 最新の調査結果を取得
     */
    public function get_latest_result( int $user_id ): ?array {
        $query = new \WP_Query( [
            'post_type'      => 'gcrev_kw_research',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_key'       => '_gcrev_kwr_user_id',
            'meta_value'     => $user_id,
            'meta_type'      => 'NUMERIC',
            'no_found_rows'  => true,
        ] );

        if ( ! $query->have_posts() ) {
            return null;
        }

        $post = $query->posts[0];
        $summary         = json_decode( get_post_meta( $post->ID, '_gcrev_kwr_summary', true ) ?: '{}', true );
        $groups          = json_decode( get_post_meta( $post->ID, '_gcrev_kwr_groups', true ) ?: '{}', true );
        $competitor_data = json_decode( get_post_meta( $post->ID, '_gcrev_kwr_competitor_data', true ) ?: '[]', true );
        $meta            = json_decode( get_post_meta( $post->ID, '_gcrev_kwr_meta', true ) ?: '{}', true );

        return [
            'success'         => true,
            'summary'         => is_array( $summary ) ? $summary : [],
            'groups'          => is_array( $groups ) ? $groups : [],
            'competitor_data' => is_array( $competitor_data ) ? $competitor_data : [],
            'meta'            => is_array( $meta ) ? $meta : [],
            'is_cached'       => true,
        ];
    }

    // =========================================================
    // プロンプト構築
    // =========================================================

    /**
     * Geminiプロンプト構築
     */
    private function build_prompt(
        string $site_url,
        string $area_label,
        string $industry_label,
        string $industry_detail,
        string $biz_type_label,
        string $persona_one_liner,
        string $persona_detail,
        array  $persona_attributes,
        array  $persona_decision,
        array  $settings,
        array  $gsc_keywords,
        array  $seed_keywords,
        array  $competitor_data = [],
        array  $planner_keywords = []
    ): string {
        $parts = [];
        $has_competitors = ! empty( $competitor_data );

        // 役割定義
        $role = "あなたは日本のローカルビジネスSEOキーワード戦略の専門家です。\n"
            . "クライアントの業種・業態・地域・ターゲット層・既存の検索流入状況を分析し、\n"
            . "SEOで優先的に狙うべきキーワード候補を調査・提案してください。";
        if ( $has_competitors ) {
            $role .= "\n競合サイトの分析結果も踏まえ、差別化戦略まで含めた提案を行ってください。";
        }
        $parts[] = $role;

        // クライアントプロファイル
        $profile = "## クライアントプロファイル\n";
        $profile .= "- サイトURL: {$site_url}\n";
        if ( $area_label )      $profile .= "- 対応エリア: {$area_label}\n";
        if ( $industry_label )  $profile .= "- 業種: {$industry_label}\n";
        if ( $industry_detail ) $profile .= "- 業種詳細: {$industry_detail}\n";
        if ( $biz_type_label )  $profile .= "- ビジネス形態: {$biz_type_label}\n";

        $area_pref   = $settings['area_pref'] ?? '';
        $area_city   = $settings['area_city'] ?? '';
        $area_custom = $settings['area_custom'] ?? '';
        if ( $area_pref )   $profile .= "- 都道府県: {$area_pref}\n";
        if ( $area_city )   $profile .= "- 市区町村: {$area_city}\n";
        if ( $area_custom ) $profile .= "- カスタムエリア: {$area_custom}\n";

        $stage       = $settings['stage'] ?? '';
        $conversions = $settings['main_conversions'] ?? '';
        if ( $stage )       $profile .= "- 事業ステージ: {$stage}\n";
        if ( $conversions ) $profile .= "- 主要コンバージョン: {$conversions}\n";
        $parts[] = $profile;

        // ペルソナ情報
        if ( $persona_one_liner || $persona_detail || ! empty( $persona_attributes ) ) {
            $persona = "## ターゲットペルソナ\n";
            if ( $persona_one_liner ) $persona .= "- 概要: {$persona_one_liner}\n";
            if ( ! empty( $persona_attributes ) ) {
                $persona .= "- 属性: " . implode( ', ', $persona_attributes ) . "\n";
            }
            if ( ! empty( $persona_decision ) ) {
                $persona .= "- 意思決定要因: " . implode( ', ', $persona_decision ) . "\n";
            }
            if ( $persona_detail ) $persona .= "- 詳細:\n{$persona_detail}\n";
            $parts[] = $persona;
        }

        // GSCデータ
        if ( ! empty( $gsc_keywords ) ) {
            $gsc_section = "## 現在の検索流入キーワード（Google Search Console 直近90日）\n";
            $gsc_section .= "| キーワード | クリック | 表示回数 | CTR | 平均順位 |\n";
            $gsc_section .= "|---|---|---|---|---|\n";
            foreach ( $gsc_keywords as $kw ) {
                $gsc_section .= sprintf( "| %s | %s | %s | %s | %s |\n",
                    $kw['query'] ?? '', $kw['clicks'] ?? '0',
                    $kw['impressions'] ?? '0', $kw['ctr'] ?? '0%', $kw['position'] ?? '-'
                );
            }
            $parts[] = $gsc_section;
        }

        // 競合サイト分析結果
        if ( $has_competitors ) {
            $comp_section = "## 競合サイト分析結果\n\n";
            foreach ( $competitor_data as $idx => $cd ) {
                if ( ( $cd['status'] ?? '' ) !== 'ok' ) { continue; }
                $num = $idx + 1;
                $comp_section .= "### 競合{$num}: " . ( $cd['title'] ?: '(タイトル不明)' ) . " ({$cd['url']})\n";
                if ( $cd['note'] ) $comp_section .= "- メモ: {$cd['note']}\n";
                if ( $cd['meta_description'] ) $comp_section .= "- メタ説明: " . mb_substr( $cd['meta_description'], 0, 200 ) . "\n";
                $h1s = $cd['headings']['h1'] ?? [];
                $h2s = $cd['headings']['h2'] ?? [];
                if ( ! empty( $h1s ) ) $comp_section .= "- H1: " . implode( ' / ', array_slice( $h1s, 0, 3 ) ) . "\n";
                if ( ! empty( $h2s ) ) $comp_section .= "- 主要見出し(H2): " . implode( ' / ', array_slice( $h2s, 0, 10 ) ) . "\n";
                if ( $cd['body_excerpt'] ) $comp_section .= "- ページ概要: " . mb_substr( $cd['body_excerpt'], 0, 300 ) . "\n";
                $comp_section .= "\n";
            }
            $parts[] = $comp_section;
        }

        // シードキーワード
        if ( ! empty( $seed_keywords ) ) {
            $parts[] = "## 追加で調査したいキーワード（シード）\n" . implode( ', ', $seed_keywords );
        }

        // Google Keyword Planner 関連キーワード候補
        if ( ! empty( $planner_keywords ) ) {
            $parts[] = "## Google Keyword Planner 関連キーワード候補\n"
                . "以下はGoogle Keyword Plannerが提案する関連キーワードです。"
                . "実際の検索需要があるキーワードとして、分類・提案に積極的に活用してください：\n"
                . implode( ', ', $planner_keywords );
        }

        // 地域名CSV
        $area_names = [];
        if ( $area_pref )   $area_names[] = $area_pref;
        if ( $area_city )   $area_names[] = $area_city;
        if ( $area_custom ) {
            $custom_parts = preg_split( '/[、,\s]+/', $area_custom );
            if ( $custom_parts ) $area_names = array_merge( $area_names, $custom_parts );
        }
        $area_csv = ! empty( $area_names ) ? implode( '、', array_unique( $area_names ) ) : '（エリア情報なし）';

        // 分類指示
        $group_instruction = <<<INSTRUCTION
## 調査指示

### キーワード分類基準
以下のグループに分類してキーワードを提案してください:

1. **immediate（今すぐ狙うべきキーワード）**: コンバージョンに直結する本命キーワード。競合が少なく、すぐに順位を狙えるもの
2. **local_seo（地域SEO向けキーワード）**: 地域名との掛け合わせキーワード。以下の地域名を使って組み合わせを生成: {$area_csv}
3. **comparison（比較・検討流入向けキーワード）**: 「○○ vs △△」「○○ おすすめ」「○○ 比較」「○○ 口コミ」など比較検討段階のユーザーが検索するキーワード
4. **column（コラム記事向きキーワード）**: 「○○とは」「○○ やり方」「○○ メリット」など情報収集段階のロングテールキーワード
5. **service_page（サービスページ向きキーワード）**: 具体的なサービス名や料金に関するキーワード。サービスページや料金ページで対策すべきもの
INSTRUCTION;

        if ( $has_competitors ) {
            $group_instruction .= <<<COMP

6. **competitor_core（競合も狙っている本命キーワード）**: 競合サイトの見出しやテーマに含まれており、自社も必ず押さえるべきキーワード
7. **competitor_longterm（競合が強いが中長期で狙うべきキーワード）**: 競合が強く今すぐは難しいが、コンテンツ蓄積で中長期的に狙うべきキーワード
8. **competitor_gap（競合が弱く自社が狙いやすいキーワード）**: 競合があまり押していないが需要があり、自社の強みと一致する差別化キーワード
9. **competitor_compare（比較検討流入を取れるキーワード）**: 「○○ vs △△」「○○ 比較」など、競合との比較で流入を取れるキーワード
COMP;
        }

        $group_instruction .= <<<DETAILS

### 地域掛け合わせの自動生成
地域名（{$area_csv}）とサービス関連語を掛け合わせたキーワードを積極的に提案してください。

### 各キーワードに付与する情報
- keyword: キーワード文字列
- type: 本命 / 補助 / 比較流入 / ローカルSEO / コラム向け / 競合重複 / 差別化 のいずれか
- priority: 高 / 中 / 低
- page_type: トップページ / サービスページ / 料金ページ / よくある質問 / コラム記事 / 事例紹介 のいずれか
- reason: このキーワードを狙うべき理由（30〜60文字）
- action: 既存ページ改善 / 新規ページ追加 / タイトル改善 / 見出し追加 / 内部リンク強化 のいずれか

### 各グループの目安件数
- immediate: 5〜10件
- local_seo: 10〜20件
- comparison: 5〜10件
- column: 5〜10件
- service_page: 5〜10件
DETAILS;

        if ( $has_competitors ) {
            $group_instruction .= <<<COMP_COUNT

- competitor_core: 3〜8件
- competitor_longterm: 3〜5件
- competitor_gap: 5〜10件
- competitor_compare: 3〜5件
COMP_COUNT;
        }

        $parts[] = $group_instruction;

        // サマリー指示
        $summary_instruction = "### 戦略サマリー\ngroups とは別に、以下の戦略サマリーも提供してください:\n";
        $summary_instruction .= "- direction: このクライアントが優先して狙うべきキーワードの方向性（100〜200文字）\n";
        $summary_instruction .= "- priority_pages: まず改善すべき既存ページの提案（100〜200文字）\n";
        $summary_instruction .= "- new_pages: 新規作成すべきページ案（100〜200文字）\n";
        $summary_instruction .= "- title_tips: タイトルや見出しに含めるべき語句（100〜200文字）\n";
        $summary_instruction .= "- local_tips: ローカルSEOで有効な地域掛け合わせ案（100〜200文字）\n";

        if ( $has_competitors ) {
            $summary_instruction .= "- competitor_strengths: 競合が強い領域の分析（100〜200文字）\n";
            $summary_instruction .= "- competitor_gaps: 自社が狙いやすい領域の分析（100〜200文字）\n";
            $summary_instruction .= "- competitor_differentiation: 競合との差別化候補（100〜200文字）\n";
        }

        $parts[] = $summary_instruction;

        // 出力フォーマット
        $group_keys = '"immediate": [...], "local_seo": [...], "comparison": [...], "column": [...], "service_page": [...]';
        if ( $has_competitors ) {
            $group_keys .= ', "competitor_core": [...], "competitor_longterm": [...], "competitor_gap": [...], "competitor_compare": [...]';
        }

        $summary_keys = '"direction": "...", "priority_pages": "...", "new_pages": "...", "title_tips": "...", "local_tips": "..."';
        if ( $has_competitors ) {
            $summary_keys .= ', "competitor_strengths": "...", "competitor_gaps": "...", "competitor_differentiation": "..."';
        }

        $parts[] = <<<FORMAT
## 出力フォーマット

以下のJSON形式のみを出力してください。前後に説明文やマークダウンのコードブロック記号を入れないでください。

{
  "summary": { {$summary_keys} },
  "groups": {
    {$group_keys}
  }
}

各キーワードアイテムの形式:
{ "keyword": "...", "type": "本命", "priority": "高", "page_type": "サービスページ", "reason": "...", "action": "既存ページ改善" }
FORMAT;

        return implode( "\n\n", $parts );
    }

    // =========================================================
    // レスポンスパース
    // =========================================================

    /**
     * GeminiレスポンスからJSON抽出・パース
     */
    private function parse_response( string $raw ): ?array {
        $cleaned = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
        $cleaned = preg_replace( '/```\s*$/m', '', $cleaned );
        $cleaned = trim( $cleaned );

        $first = strpos( $cleaned, '{' );
        $last  = strrpos( $cleaned, '}' );
        if ( $first === false || $last === false || $last <= $first ) {
            return null;
        }

        $json_str = substr( $cleaned, $first, $last - $first + 1 );
        $data = json_decode( $json_str, true );

        if ( ! is_array( $data ) ) {
            return null;
        }

        // 正規化: summary
        $summary_defaults = [
            'direction'                  => '',
            'priority_pages'             => '',
            'new_pages'                  => '',
            'title_tips'                 => '',
            'local_tips'                 => '',
            'competitor_strengths'       => '',
            'competitor_gaps'            => '',
            'competitor_differentiation' => '',
        ];
        $data['summary'] = array_merge( $summary_defaults, $data['summary'] ?? [] );

        // 正規化: groups
        $kw_defaults = [
            'keyword'      => '',
            'type'         => '補助',
            'priority'     => '中',
            'page_type'    => '',
            'reason'       => '',
            'action'       => '',
            'volume'       => null,
            'competition'  => null,
            'difficulty'   => null,
            'current_rank' => null,
        ];

        foreach ( array_keys( self::GROUPS ) as $group_key ) {
            $items = $data['groups'][ $group_key ] ?? [];
            if ( ! is_array( $items ) ) {
                $items = [];
            }
            $normalized = [];
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) || empty( $item['keyword'] ) ) {
                    continue;
                }
                $normalized[] = array_merge( $kw_defaults, $item );
            }
            $data['groups'][ $group_key ] = $normalized;
        }

        return $data;
    }

    // =========================================================
    // ユーティリティ
    // =========================================================

    private function log( string $message ): void {
        file_put_contents(
            self::DEBUG_LOG,
            date( 'Y-m-d H:i:s' ) . " [KeywordResearch] {$message}\n",
            FILE_APPEND
        );
    }
}
