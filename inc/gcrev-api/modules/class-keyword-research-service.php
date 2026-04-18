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
    private const SITE_SUMMARY_TTL  = 21600; // 6h
    private const MAX_PROMPT_BYTES  = 15360; // 15KB 目安
    private const MAX_OWN_PAGES     = 15;    // 要約入力のページ上限
    private const IMAGE_MAX_BYTES   = 4 * 1024 * 1024; // 4MB

    /**
     * キーワードグループ定義
     */
    private const GROUPS = [
        'immediate'           => '今すぐ狙うべきキーワード',
        'local_seo'           => '地域SEO向けキーワード',
        'comparison'          => '比較・検討流入向けキーワード',
        'column'              => 'コラム記事向きキーワード',
        'service_page'        => 'サービスページ向きキーワード',
        'traffic_expansion'   => '集客拡張キーワード（潜在層・認知獲得）',
        'competitor_core'     => '競合も狙っている本命キーワード',
        'competitor_longterm' => '競合が強いが中長期で狙うべきキーワード',
        'competitor_gap'      => '競合が弱く自社が狙いやすいキーワード',
        'competitor_compare'  => '比較検討流入を取れるキーワード',
        'excluded'            => '自社サイトと関連性が低く除外したキーワード',
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

        // 2.7. 競合URLからKeyword Plannerキーワード取得
        $competitor_planner_keywords = [];
        $ref_urls = $settings['persona_reference_urls'] ?? [];
        if ( $enable_competitor && ! empty( $ref_urls ) && $this->google_ads !== null ) {
            $competitor_planner_keywords = $this->fetch_competitor_keywords_from_planner( $ref_urls );
            if ( ! empty( $competitor_planner_keywords ) ) {
                $data_sources[] = 'Google Ads 競合分析';
                $this->log( 'Competitor Planner: ' . count( $competitor_planner_keywords ) . ' URLs analyzed' );
            }
        }

        // 2.8. DataForSEO Ranked Keywords で順位・推定流入数を付加
        if ( ! empty( $competitor_planner_keywords ) && $this->dataforseo !== null ) {
            // 再調査時はランクキャッシュをクリアして最新データを取得
            foreach ( $competitor_planner_keywords as $comp_url => $_kws ) {
                $p = wp_parse_url( $comp_url );
                $d = preg_replace( '/^www\./i', '', $p['host'] ?? '' );
                if ( $d !== '' ) {
                    delete_transient( 'gcrev_kwrank_' . substr( md5( $d ), 0, 16 ) );
                }
            }
            $competitor_planner_keywords = $this->enrich_planner_with_ranked_data( $competitor_planner_keywords );
        }

        // 3. 競合URL解析（HTML スクレイピング — 見出し・メタ情報取得用に継続）
        $competitor_data = [];
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

        // 3.5 自社サイトの実体収集 → 意味圧縮（Phase A）
        $own_raw = $this->fetch_own_site_raw( $user_id, $site_url );
        $site_summary = [];
        $top_page_attachment = 0;
        if ( ! empty( $own_raw['pages'] ) ) {
            $site_summary = $this->summarize_own_site( $user_id, $own_raw );
            $top_page_attachment = (int) ( $own_raw['screenshot_pc_attachment_id'] ?? 0 );
            if ( ! empty( $site_summary['services'] ) ) {
                $data_sources[] = ( ( $own_raw['source'] ?? '' ) === 'diagnosis' )
                    ? 'ページ診断' : '自社サイト簡易クロール';
            }
        }

        // 4. プロンプト構築
        $prompt = $this->build_prompt(
            $site_url, $area_label, $industry_label, $industry_detail, $biz_type_label,
            $persona_one_liner, $persona_detail, $persona_attributes, $persona_decision,
            $settings, $gsc_keywords, $seed_keywords, $competitor_data, $planner_keywords,
            $competitor_planner_keywords, $site_summary
        );

        $prompt_bytes = strlen( $prompt );
        $this->log( sprintf( 'prompt_bytes=%d (limit=%d)', $prompt_bytes, self::MAX_PROMPT_BYTES ) );
        if ( $prompt_bytes > self::MAX_PROMPT_BYTES ) {
            $this->log( 'WARN: prompt exceeded size limit' );
        }

        // 5. Gemini呼び出し（トップページ画像があればマルチモーダル、補助情報として）
        $image_payload = ( $top_page_attachment > 0 )
            ? $this->pick_top_page_image( $top_page_attachment ) : null;

        try {
            if ( $image_payload !== null ) {
                $raw = $this->ai->call_gemini_multimodal(
                    $prompt,
                    [ $image_payload ],
                    [ 'temperature' => 0.7, 'maxOutputTokens' => 16384 ]
                );
                $data_sources[] = 'トップページ画像解析(補助)';
                $this->log( 'Gemini mode: multimodal' );
            } else {
                $raw = $this->ai->call_gemini_api( $prompt, [
                    'temperature'     => 0.7,
                    'maxOutputTokens' => 16384,
                ] );
                $this->log( 'Gemini mode: text-only' );
            }
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

        // 7. キーワードエンリッチメント（excluded はクォータ節約のため対象外）
        $volume_source = 'none';
        $excluded_saved = null;
        if ( isset( $parsed['groups']['excluded'] ) ) {
            $excluded_saved = $parsed['groups']['excluded'];
            unset( $parsed['groups']['excluded'] );
        }
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
        if ( $excluded_saved !== null ) {
            $parsed['groups']['excluded'] = $excluded_saved;
            $avg_rel = $avg_fit = 0; $cnt = count( $excluded_saved );
            foreach ( $excluded_saved as $ex ) {
                $avg_rel += (int) ( $ex['relevance_score'] ?? 0 );
                $avg_fit += (int) ( $ex['business_fit'] ?? 0 );
            }
            if ( $cnt > 0 ) {
                $this->log( sprintf(
                    'excluded count=%d, avg_relevance=%d, avg_business_fit=%d',
                    $cnt, (int) ( $avg_rel / $cnt ), (int) ( $avg_fit / $cnt )
                ) );
            }
        }

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );

        $result = [
            'success'                     => true,
            'summary'                     => $parsed['summary'] ?? [],
            'groups'                      => $parsed['groups'] ?? [],
            'competitor_data'             => $competitor_data,
            'competitor_planner_keywords' => $competitor_planner_keywords,
            'meta'                        => [
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
     * 競合URL から Keyword Planner のキーワード候補を取得
     *
     * Google が各 URL に関連付けているキーワードを返す。
     * HTML スクレイピングよりも信頼性の高い競合キーワードデータ。
     *
     * @param  array $ref_urls  競合URL配列 [ ['url' => '...', 'note' => '...'], ... ]
     * @return array  [ 'url' => [ parsed keyword ideas ], ... ]
     */
    private function fetch_competitor_keywords_from_planner( array $ref_urls ): array {
        if ( $this->google_ads === null || empty( $ref_urls ) ) {
            return [];
        }

        try {
            $token = $this->google_ads->get_access_token();
            if ( is_wp_error( $token ) ) {
                $this->log( 'Competitor Planner token error: ' . $token->get_error_message() );
                return [];
            }

            $customer_id = preg_replace( '/[^0-9]/', '', GOOGLE_ADS_CUSTOMER_ID );
            $result = [];

            foreach ( $ref_urls as $i => $entry ) {
                $url = $entry['url'] ?? '';
                if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                    continue;
                }

                // キャッシュ確認
                $cache_key = 'gcrev_kwcomp_url_' . substr( md5( $url ), 0, 16 );
                $cached = get_transient( $cache_key );
                if ( $cached !== false ) {
                    $result[ $url ] = $cached;
                    $this->log( "Competitor Planner cache hit: {$url}" );
                    continue;
                }

                // API 呼び出し（0.5秒間隔）
                if ( $i > 0 ) {
                    usleep( 500000 );
                }

                $raw = $this->google_ads->generate_keyword_ideas_by_url( $token, $customer_id, $url );
                if ( is_wp_error( $raw ) ) {
                    $this->log( "Competitor Planner error [{$url}]: " . $raw->get_error_message() );
                    $result[ $url ] = [];
                    continue;
                }

                // パースしてボリューム降順で上位30件
                $parsed = Gcrev_Google_Ads_Client::parse_keyword_idea_results( $raw );
                usort( $parsed, function( $a, $b ) {
                    return ( $b['volume'] ?? 0 ) - ( $a['volume'] ?? 0 );
                } );
                $parsed = array_slice( $parsed, 0, 30 );

                set_transient( $cache_key, $parsed, self::VOLUME_CACHE_TTL );
                $result[ $url ] = $parsed;
                $this->log( "Competitor Planner [{$url}]: " . count( $parsed ) . ' keywords' );
            }

            return $result;

        } catch ( \Throwable $e ) {
            $this->log( 'Competitor Planner exception: ' . $e->getMessage() );
            return [];
        }
    }

    /**
     * 競合 Planner キーワードに DataForSEO Ranked Keywords の順位・推定流入数を付加
     *
     * @param array $planner_keywords  [ url => [ {text, volume, ...}, ... ], ... ]
     * @return array 同構造（各キーワードに rank, etv フィールド追加済み）
     */
    private function enrich_planner_with_ranked_data( array $planner_keywords ): array {
        $ranked_cache = []; // domain → ranked data

        foreach ( $planner_keywords as $url => &$keywords ) {
            // URL からドメインを抽出
            $parsed_url = wp_parse_url( $url );
            $domain = $parsed_url['host'] ?? '';
            if ( $domain === '' ) {
                // rank/etv を null で初期化して次へ
                foreach ( $keywords as &$kw ) {
                    $kw['rank'] = null;
                    $kw['etv']  = null;
                }
                unset( $kw );
                continue;
            }

            // www. を除去して正規化
            $domain = preg_replace( '/^www\./i', '', $domain );

            // ドメイン単位でキャッシュ（同じドメインの別URLなら再取得不要）
            if ( ! isset( $ranked_cache[ $domain ] ) ) {
                $cache_key = 'gcrev_kwrank_' . substr( md5( $domain ), 0, 16 );
                $cached = get_transient( $cache_key );

                if ( $cached !== false ) {
                    $ranked_cache[ $domain ] = $cached;
                    $this->log( "Ranked keywords cache hit: {$domain}" );
                } else {
                    $result = $this->dataforseo->fetch_ranked_keywords( $domain );
                    if ( is_wp_error( $result ) ) {
                        $this->log( "Ranked keywords error [{$domain}]: " . $result->get_error_message() );
                        $ranked_cache[ $domain ] = [];
                    } else {
                        $ranked_cache[ $domain ] = $result;
                        // 空結果はキャッシュしない（再調査時にAPIを再実行するため）
                        if ( ! empty( $result ) ) {
                            set_transient( $cache_key, $result, self::VOLUME_CACHE_TTL );
                        }
                        $this->log( "Ranked keywords [{$domain}]: " . count( $result ) . ' keywords' );
                    }
                    // API 間隔を空ける
                    usleep( 500000 );
                }
            }

            $ranked = $ranked_cache[ $domain ];

            // Planner キーワードとマッチング
            $match_count = 0;
            foreach ( $keywords as &$kw ) {
                $text = $kw['text'] ?? '';
                $norm = mb_strtolower( trim( $text ), 'UTF-8' );
                $norm = str_replace( "\xE3\x80\x80", ' ', $norm );
                $norm = preg_replace( '/\s+/u', ' ', $norm );

                if ( isset( $ranked[ $norm ] ) ) {
                    $kw['rank'] = $ranked[ $norm ]['rank'];
                    $kw['etv']  = $ranked[ $norm ]['etv'];
                    $match_count++;
                } else {
                    $kw['rank'] = null;
                    $kw['etv']  = null;
                }
            }
            $this->log( "Ranked match [{$domain}]: {$match_count}/" . count( $keywords ) . " keywords matched (ranked pool: " . count( $ranked ) . ")" );
            unset( $kw );
        }
        unset( $keywords );

        return $planner_keywords;
    }

    /**
     * monthlySearchVolumes 配列をパース
     */
    private function parse_monthly_volumes( array $raw ): array {
        $month_map = [
            'JANUARY' => 1, 'FEBRUARY' => 2, 'MARCH' => 3, 'APRIL' => 4,
            'MAY' => 5, 'JUNE' => 6, 'JULY' => 7, 'AUGUST' => 8,
            'SEPTEMBER' => 9, 'OCTOBER' => 10, 'NOVEMBER' => 11, 'DECEMBER' => 12,
        ];
        $result = [];
        foreach ( $raw as $item ) {
            $result[] = [
                'year'     => (int) ( $item['year'] ?? 0 ),
                'month'    => $month_map[ $item['month'] ?? '' ] ?? 0,
                'searches' => (int) ( $item['monthlySearches'] ?? 0 ),
            ];
        }
        return $result;
    }

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

                    // competitionIndex: 0-100
                    $competition_index = isset( $metrics['competitionIndex'] )
                        ? (int) $metrics['competitionIndex'] : null;

                    // CPC: highTopOfPageBidMicros → 通貨単位に変換
                    $cpc = null;
                    if ( isset( $metrics['highTopOfPageBidMicros'] ) ) {
                        $cpc = round( (int) $metrics['highTopOfPageBidMicros'] / 1000000, 2 );
                    }

                    // monthlySearchVolumes
                    $monthly_volumes = $this->parse_monthly_volumes( $metrics['monthlySearchVolumes'] ?? [] );

                    $merged[ mb_strtolower( $text ) ] = [
                        'volume'            => $volume,
                        'competition'       => $competition,
                        'competition_index' => $competition_index,
                        'cpc'               => $cpc,
                        'monthly_volumes'   => $monthly_volumes,
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
                    'volume'            => $data['volume'],
                    'competition'       => $data['competition'],
                    'competition_index' => $data['competition_index'] ?? null,
                    'cpc'               => $data['cpc'],
                    'monthly_volumes'   => $data['monthly_volumes'] ?? [],
                    'difficulty'        => null,
                ];
            }
            $this->log( 'enrich_keywords: Planner data for ' . count( $planner_data ) . ' keywords' );
        }

        // Step 2: DataForSEO fetch_search_volume を全KWに呼び、
        //         Planner で取れていない or 欠損したフィールドを補強する。
        //         Planner で取れたKWも競合度/CPC の補強対象とする（competition_index が null のケースが多いため）。
        $df_available = ( $this->dataforseo !== null )
            && method_exists( $this->dataforseo, 'is_configured' )
            && $this->dataforseo::is_configured();

        if ( $df_available ) {
            try {
                $vol_result = $this->dataforseo->fetch_search_volume( $keywords );
                if ( ! is_wp_error( $vol_result ) && is_array( $vol_result ) && ! empty( $vol_result ) ) {
                    $added = 0; $augmented = 0;
                    foreach ( $vol_result as $kw => $data ) {
                        $kw_lower = mb_strtolower( $kw );
                        $df_vol  = $data['search_volume'] ?? null;
                        $df_comp = $data['competition'] ?? null;
                        $df_cpc  = $data['cpc'] ?? null;

                        if ( ! isset( $merged[ $kw_lower ] ) ) {
                            // Planner で取れていないKW → DataForSEO をプライマリとして登録
                            $merged[ $kw_lower ] = [
                                'volume'            => $df_vol,
                                'competition'       => $df_comp,
                                'competition_index' => ( $df_comp !== null )
                                    ? (int) round( (float) $df_comp * 100 ) : null,
                                'cpc'               => $df_cpc,
                                'monthly_volumes'   => [],
                                'difficulty'        => null,
                            ];
                            $added++;
                            continue;
                        }

                        // Planner で取れていたKW → 欠損フィールドのみ補強
                        $row = &$merged[ $kw_lower ];
                        if ( $row['volume'] === null && $df_vol !== null ) {
                            $row['volume'] = $df_vol;
                        }
                        if ( $row['competition_index'] === null && $df_comp !== null ) {
                            $row['competition_index'] = (int) round( (float) $df_comp * 100 );
                            if ( $row['competition'] === null ) { $row['competition'] = $df_comp; }
                            $augmented++;
                        }
                        if ( $row['cpc'] === null && $df_cpc !== null ) {
                            $row['cpc'] = $df_cpc;
                        }
                        unset( $row );
                    }
                    if ( $added > 0 || $augmented > 0 ) {
                        $volume_source = ( $volume_source === 'google_ads' ) ? 'mixed' : 'dataforseo';
                    }
                    $this->log( sprintf(
                        'enrich_keywords: DataForSEO volume — added=%d, augmented=%d, total=%d',
                        $added, $augmented, count( $vol_result )
                    ) );
                } elseif ( is_wp_error( $vol_result ) ) {
                    $this->log( 'enrich_keywords: DataForSEO volume error: ' . $vol_result->get_error_message() );
                }
            } catch ( \Throwable $e ) {
                $this->log( 'enrich_keywords: DataForSEO volume exception: ' . $e->getMessage() );
            }
        }

        // Step 3: DataForSEO で難易度をバッチ分割取得（日本語ロングテールで欠損しやすいため）
        if ( $df_available ) {
            $batches = array_chunk( $keywords, 20 );
            $total_got = 0;
            foreach ( $batches as $bi => $batch ) {
                try {
                    $diff_result = $this->dataforseo->fetch_keyword_difficulty( $batch );
                    if ( is_wp_error( $diff_result ) ) {
                        $this->log( "enrich_keywords: difficulty batch {$bi} error: " . $diff_result->get_error_message() );
                        continue;
                    }
                    if ( ! is_array( $diff_result ) ) { continue; }

                    $got_in_batch = 0;
                    foreach ( $diff_result as $kw => $data ) {
                        $kw_lower = mb_strtolower( $kw );
                        if ( ! isset( $merged[ $kw_lower ] ) ) {
                            $merged[ $kw_lower ] = [
                                'volume'            => null,
                                'competition'       => null,
                                'competition_index' => null,
                                'cpc'               => null,
                                'monthly_volumes'   => [],
                                'difficulty'        => null,
                            ];
                        }
                        if ( isset( $data['keyword_difficulty'] ) && $data['keyword_difficulty'] !== null ) {
                            $merged[ $kw_lower ]['difficulty'] = (int) $data['keyword_difficulty'];
                            $got_in_batch++;
                        }
                    }
                    $total_got += $got_in_batch;
                    $this->log( sprintf(
                        'enrich_keywords: difficulty batch %d — got=%d/%d',
                        $bi, $got_in_batch, count( $batch )
                    ) );
                } catch ( \Throwable $e ) {
                    $this->log( "enrich_keywords: difficulty batch {$bi} exception: " . $e->getMessage() );
                }
            }
            $this->log( sprintf(
                'enrich_keywords: difficulty total got=%d/%d',
                $total_got, count( $keywords )
            ) );
        }

        // Step 4: 最終フォールバック — competition_index がまだ null の KW には
        // Planner の competition (HIGH=0.8 / MEDIUM=0.5 / LOW=0.2) を ×100 して変換
        foreach ( $merged as $k => &$row ) {
            if ( $row['competition_index'] === null && $row['competition'] !== null ) {
                $row['competition_index'] = (int) round( (float) $row['competition'] * 100 );
            }
        }
        unset( $row );

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
                    $item['volume']            = $vd['volume'];
                    $item['competition']       = $vd['competition'];
                    $item['competition_index'] = $vd['competition_index'] ?? null;
                    $item['cpc']               = $vd['cpc'] ?? null;
                    $item['monthly_volumes']   = $vd['monthly_volumes'] ?? [];
                    $item['difficulty']        = $vd['difficulty'];
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
        update_post_meta( $post_id, '_gcrev_kwr_competitor_planner',
            wp_json_encode( $result['competitor_planner_keywords'] ?? [], JSON_UNESCAPED_UNICODE ) );
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
        $summary              = json_decode( get_post_meta( $post->ID, '_gcrev_kwr_summary', true ) ?: '{}', true );
        $groups               = json_decode( get_post_meta( $post->ID, '_gcrev_kwr_groups', true ) ?: '{}', true );
        $competitor_data      = json_decode( get_post_meta( $post->ID, '_gcrev_kwr_competitor_data', true ) ?: '[]', true );
        $competitor_planner   = json_decode( get_post_meta( $post->ID, '_gcrev_kwr_competitor_planner', true ) ?: '{}', true );
        $meta                 = json_decode( get_post_meta( $post->ID, '_gcrev_kwr_meta', true ) ?: '{}', true );

        return [
            'success'                     => true,
            'summary'                     => is_array( $summary ) ? $summary : [],
            'groups'                      => is_array( $groups ) ? $groups : [],
            'competitor_data'             => is_array( $competitor_data ) ? $competitor_data : [],
            'competitor_planner_keywords' => is_array( $competitor_planner ) ? $competitor_planner : [],
            'meta'                        => is_array( $meta ) ? $meta : [],
            'is_cached'                   => true,
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
        array  $planner_keywords = [],
        array  $competitor_planner_keywords = [],
        array  $site_summary = []
    ): string {
        $parts = [];
        $has_competitors = ! empty( $competitor_data );
        $has_site_summary = ! empty( $site_summary['services'] );

        // 役割定義
        $role = "あなたは日本のローカルビジネスSEOキーワード戦略の専門家です。\n"
            . "クライアントの業種・業態・地域・ターゲット層・既存の検索流入状況を分析し、\n"
            . "SEOで優先的に狙うべきキーワード候補を調査・提案してください。";
        if ( $has_competitors ) {
            $role .= "\n競合サイトの分析結果も踏まえ、差別化戦略まで含めた提案を行ってください。";
        }
        if ( $has_site_summary ) {
            $role .= "\n判断の主軸は必ずテキスト情報（特に自社サイトの要約）とし、画像は補助情報として扱ってください。";
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

        // 自社サイト要約（意味圧縮済み）
        if ( $has_site_summary ) {
            $parts[] = $this->build_own_site_summary_section( $site_summary );
        }

        // GSCデータ（上位30件に圧縮）
        if ( ! empty( $gsc_keywords ) ) {
            $gsc_top = array_slice( $gsc_keywords, 0, 30 );
            $gsc_section = "## 現在の検索流入キーワード（Google Search Console 直近90日・上位30件）\n";
            $gsc_section .= "| キーワード | クリック | 表示回数 | CTR | 平均順位 |\n";
            $gsc_section .= "|---|---|---|---|---|\n";
            foreach ( $gsc_top as $kw ) {
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
                if ( $cd['body_excerpt'] ) $comp_section .= "- ページ概要: " . mb_substr( $cd['body_excerpt'], 0, 200 ) . "\n";
                $comp_section .= "\n";
            }
            $parts[] = $comp_section;
        }

        // 競合URLのKeyword Plannerデータ
        if ( ! empty( $competitor_planner_keywords ) ) {
            $comp_planner_section = "## 競合URLに関連するキーワード（Google Keyword Planner 実データ）\n\n";
            $comp_planner_section .= "以下はGoogle Keyword Plannerが各競合URLに関連付けているキーワードです。\n";
            $comp_planner_section .= "Googleが実際に保有する検索データに基づいており、HTMLスクレイピングよりも信頼性が高いです。\n";
            $comp_planner_section .= "自社サイトとの重複・ギャップを特定し、競合分析に積極的に活用してください。\n\n";

            foreach ( $competitor_planner_keywords as $comp_url => $kw_list ) {
                if ( empty( $kw_list ) ) { continue; }
                $comp_planner_section .= "### {$comp_url}\n";
                $comp_planner_section .= "| キーワード | 月間検索数 | 競合度 |\n|---|---|---|\n";
                foreach ( array_slice( $kw_list, 0, 15 ) as $kw ) {
                    $vol  = $kw['volume'] ?? '-';
                    $comp = $kw['competition'] ?? '-';
                    $comp_planner_section .= "| {$kw['text']} | {$vol} | {$comp} |\n";
                }
                $comp_planner_section .= "\n";
            }

            $parts[] = $comp_planner_section;
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

        // キーワード採択基準（site_summary を踏まえた最重要ルール）
        if ( $has_site_summary ) {
            $parts[] = $this->build_acceptance_criteria_section();
        }

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
6. **traffic_expansion（集客拡張キーワード）**: 将来の顧客（潜在層）を獲得するための教育・認知向けキーワード。自社サービスと関連のある話題で検索ボリュームが比較的大きく、直接CVには繋がらないが長期的な集客資産になるもの。intent は「潜在」または「情報収集」限定。not_provided 領域は絶対に含めない
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
- reason: このキーワードを狙うべき理由（30〜60文字。自社サービスとの紐づけを必ず含める）
- action: 既存ページ改善 / 新規ページ追加 / タイトル改善 / 見出し追加 / 内部リンク強化 のいずれか
- relevance_score: 0〜100の整数（サイト要約との意味的関連度）
- business_fit: 0〜100の整数（ビジネス適合度）
- intent: 顕在 / 潜在 / 比較検討 / 情報収集 のいずれか
- cv_distance: 近い / 中 / 遠い のいずれか

### 各グループの目安件数
- immediate: 5〜10件
- local_seo: 10〜20件
- comparison: 5〜10件
- column: 5〜10件
- service_page: 5〜10件
- traffic_expansion: 8〜15件（ボリューム優先、潜在層向けの話題を広く）
- excluded: 5〜15件（採択基準で落としたKWを必ず列挙。why_not_target 必須）
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
        $group_keys = '"immediate": [...], "local_seo": [...], "comparison": [...], "column": [...], "service_page": [...], "traffic_expansion": [...]';
        if ( $has_competitors ) {
            $group_keys .= ', "competitor_core": [...], "competitor_longterm": [...], "competitor_gap": [...], "competitor_compare": [...]';
        }
        $group_keys .= ', "excluded": [...]';

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

採択KW（excluded 以外）のアイテム形式:
{ "keyword": "...", "type": "本命", "priority": "高", "page_type": "サービスページ",
  "reason": "自社が提供する〇〇への検索意図と合致", "action": "既存ページ改善",
  "relevance_score": 85, "business_fit": 80, "intent": "顕在", "cv_distance": "近い" }

excluded グループのアイテム形式:
{ "keyword": "...", "relevance_score": 45, "business_fit": 20,
  "intent": "情報収集", "cv_distance": "遠い",
  "why_not_target": "このサイトでは〇〇を提供していないため対象外" }
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
            // JSON が途中で切れている可能性 — 閉じ括弧を補完して再試行
            if ( $first !== false ) {
                $partial = substr( $cleaned, $first );
                $data = $this->try_repair_json( $partial );
                if ( is_array( $data ) ) {
                    $this->log( "JSON repaired (truncated response)" );
                    return $this->normalize_parsed( $data );
                }
            }
            return null;
        }

        $json_str = substr( $cleaned, $first, $last - $first + 1 );
        $data = json_decode( $json_str, true );

        if ( ! is_array( $data ) ) {
            // json_decode 失敗 — 修復を試行
            $data = $this->try_repair_json( $json_str );
            if ( ! is_array( $data ) ) {
                return null;
            }
            $this->log( "JSON repaired (malformed)" );
        }

        return $this->normalize_parsed( $data );
    }

    /**
     * パース済みデータを正規化
     */
    private function normalize_parsed( array $data ): array {
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
            'keyword'           => '',
            'type'              => '補助',
            'priority'          => '中',
            'page_type'         => '',
            'reason'            => '',
            'action'            => '',
            'volume'            => null,
            'competition'       => null,
            'competition_index' => null,
            'monthly_volumes'   => [],
            'difficulty'        => null,
            'current_rank'      => null,
            'relevance_score'   => null,
            'business_fit'      => null,
            'intent'            => '',
            'cv_distance'       => '',
            'why_not_target'    => '',
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
                $merged = array_merge( $kw_defaults, $item );
                // 0-100 範囲バリデーション
                if ( $merged['relevance_score'] !== null ) {
                    $merged['relevance_score'] = max( 0, min( 100, (int) $merged['relevance_score'] ) );
                }
                if ( $merged['business_fit'] !== null ) {
                    $merged['business_fit'] = max( 0, min( 100, (int) $merged['business_fit'] ) );
                }
                // 文字列型強制
                $merged['intent']         = is_string( $merged['intent'] ) ? $merged['intent'] : '';
                $merged['cv_distance']    = is_string( $merged['cv_distance'] ) ? $merged['cv_distance'] : '';
                $merged['why_not_target'] = is_string( $merged['why_not_target'] ) ? $merged['why_not_target'] : '';
                $normalized[] = $merged;
            }
            $data['groups'][ $group_key ] = $normalized;
        }

        return $data;
    }

    /**
     * 途中で切れた JSON を修復して再パース
     *
     * Gemini が max_output_tokens に達して不完全な JSON を返す場合に対応。
     * 文字列の途中で切れていたら閉じ、開きブラケットを数えて閉じる。
     */
    private function try_repair_json( string $json ): ?array {
        // 手動修復: 開いている文字列を閉じ、ブラケットを数えて閉じる
        $repaired = $json;

        // 末尾の不完全な文字列値を閉じる
        // 最後の " の前にエスケープされていない " があるか確認
        $in_string = false;
        $escape = false;
        for ( $i = 0, $len = strlen( $repaired ); $i < $len; $i++ ) {
            $c = $repaired[ $i ];
            if ( $escape ) {
                $escape = false;
                continue;
            }
            if ( $c === '\\' ) {
                $escape = true;
                continue;
            }
            if ( $c === '"' ) {
                $in_string = ! $in_string;
            }
        }

        if ( $in_string ) {
            $repaired .= '"';
        }

        // 開きブラケット/ブレースを数えて閉じる
        $opens  = substr_count( $repaired, '{' ) - substr_count( $repaired, '}' );
        $arrays = substr_count( $repaired, '[' ) - substr_count( $repaired, ']' );

        // 末尾のカンマを除去
        $repaired = rtrim( $repaired );
        $repaired = rtrim( $repaired, ',' );

        for ( $i = 0; $i < $arrays; $i++ ) {
            $repaired .= ']';
        }
        for ( $i = 0; $i < $opens; $i++ ) {
            $repaired .= '}';
        }

        $data = json_decode( $repaired, true );
        return is_array( $data ) ? $data : null;
    }

    // =========================================================
    // 自社サイト要約（Phase A: 意味理解ベース）
    // =========================================================

    /**
     * 自社サイトの生データを収集する（意味圧縮前の入力源）
     *
     * 優先順位:
     *   1. wp_gcrev_page_analysis（ページ診断済みデータ）
     *   2. フォールバック: site_url の簡易クロール（トップ + 内部リンク3件）
     *
     * @return array {
     *   source: 'diagnosis' | 'fallback' | 'none',
     *   pages:  [ {url, title, page_type, purpose, cta, ai_summary, insights, body_excerpt}, ... ],
     *   screenshot_pc_attachment_id: int,
     * }
     */
    private function fetch_own_site_raw( int $user_id, string $site_url ): array {
        global $wpdb;

        // --- 1. ページ診断テーブル優先 ---
        $table = $wpdb->prefix . 'gcrev_page_analysis';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        $pages  = [];
        $top_attachment = 0;

        if ( $exists === $table ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, page_url, page_title, page_type, page_purpose, page_cta,
                            ai_summary, ai_insights, screenshot_pc
                       FROM {$table}
                      WHERE user_id = %d AND status = %s
                   ORDER BY FIELD(page_type, %s) DESC, id ASC
                      LIMIT %d",
                    $user_id, 'active', 'top', self::MAX_OWN_PAGES
                ),
                ARRAY_A
            );
            if ( is_array( $rows ) && ! empty( $rows ) ) {
                foreach ( $rows as $r ) {
                    $insights = [];
                    if ( ! empty( $r['ai_insights'] ) ) {
                        $decoded = json_decode( (string) $r['ai_insights'], true );
                        if ( is_array( $decoded ) ) { $insights = $decoded; }
                    }
                    $pages[] = [
                        'url'          => (string) ( $r['page_url'] ?? '' ),
                        'title'        => (string) ( $r['page_title'] ?? '' ),
                        'page_type'    => (string) ( $r['page_type'] ?? 'other' ),
                        'purpose'      => (string) ( $r['page_purpose'] ?? '' ),
                        'cta'          => (string) ( $r['page_cta'] ?? '' ),
                        'ai_summary'   => (string) ( $r['ai_summary'] ?? '' ),
                        'insights'     => $insights,
                        'body_excerpt' => '',
                    ];
                    if ( $top_attachment === 0 && (string) ( $r['page_type'] ?? '' ) === 'top' ) {
                        $top_attachment = (int) ( $r['screenshot_pc'] ?? 0 );
                    }
                }
                $this->log( sprintf(
                    'fetch_own_site_raw: source=diagnosis, pages=%d, top_attachment=%d',
                    count( $pages ), $top_attachment
                ) );
                return [
                    'source' => 'diagnosis',
                    'pages'  => $pages,
                    'screenshot_pc_attachment_id' => $top_attachment,
                ];
            }
        }

        // --- 2. フォールバック: 簡易クロール ---
        if ( $site_url !== '' && filter_var( $site_url, FILTER_VALIDATE_URL ) ) {
            $urls = $this->collect_fallback_urls( $site_url );
            if ( ! empty( $urls ) ) {
                $entries = array_map( fn( $u ) => [ 'url' => $u, 'note' => '' ], $urls );
                $crawled = $this->fetch_competitor_data( $entries );
                foreach ( $crawled as $c ) {
                    if ( ( $c['status'] ?? '' ) !== 'ok' ) { continue; }
                    $h1 = $c['headings']['h1'][0] ?? '';
                    $h2 = implode( ' / ', array_slice( $c['headings']['h2'] ?? [], 0, 5 ) );
                    $pages[] = [
                        'url'          => (string) $c['url'],
                        'title'        => (string) ( $c['title'] ?? '' ),
                        'page_type'    => '',
                        'purpose'      => $h1 ? "H1: {$h1}" : '',
                        'cta'          => '',
                        'ai_summary'   => (string) ( $c['meta_description'] ?? '' ),
                        'insights'     => $h2 ? [ 'headings_h2' => $h2 ] : [],
                        'body_excerpt' => (string) ( $c['body_excerpt'] ?? '' ),
                    ];
                }
                $this->log( sprintf(
                    'fetch_own_site_raw: source=fallback, pages=%d',
                    count( $pages )
                ) );
                if ( ! empty( $pages ) ) {
                    return [
                        'source' => 'fallback',
                        'pages'  => $pages,
                        'screenshot_pc_attachment_id' => 0,
                    ];
                }
            }
        }

        $this->log( 'fetch_own_site_raw: source=none' );
        return [ 'source' => 'none', 'pages' => [], 'screenshot_pc_attachment_id' => 0 ];
    }

    /**
     * フォールバック用に、site_url から最大4URL（トップ + 内部リンク3件）を収集
     */
    private function collect_fallback_urls( string $site_url ): array {
        $urls = [ $site_url ];

        $response = wp_remote_get( $site_url, [
            'timeout'    => 10,
            'user-agent' => 'MimamoriSEO/1.0 (+https://mimamori-web.jp)',
            'sslverify'  => false,
        ] );
        if ( is_wp_error( $response ) ) {
            return $urls;
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status !== 200 ) { return $urls; }
        $html = (string) wp_remote_retrieve_body( $response );
        if ( $html === '' ) { return $urls; }

        $parsed_base = wp_parse_url( $site_url );
        $base_host   = $parsed_base['host'] ?? '';
        if ( $base_host === '' ) { return $urls; }

        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        @$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        $xpath = new \DOMXPath( $dom );
        $links = $xpath->query( '//a[@href]' );
        if ( ! $links ) { return $urls; }

        $candidates = [];
        foreach ( $links as $a ) {
            $href = trim( (string) $a->getAttribute( 'href' ) );
            if ( $href === '' || $href[0] === '#' ) { continue; }
            if ( strpos( $href, 'javascript:' ) === 0 || strpos( $href, 'mailto:' ) === 0 ) { continue; }
            // 相対URLを絶対URLに
            if ( strpos( $href, '//' ) === 0 ) {
                $href = ( $parsed_base['scheme'] ?? 'https' ) . ':' . $href;
            } elseif ( $href[0] === '/' ) {
                $href = ( $parsed_base['scheme'] ?? 'https' ) . '://' . $base_host . $href;
            } elseif ( ! preg_match( '#^https?://#i', $href ) ) {
                continue;
            }
            $p = wp_parse_url( $href );
            if ( ( $p['host'] ?? '' ) !== $base_host ) { continue; }
            // クエリ・フラグメント除去
            $clean = ( $p['scheme'] ?? 'https' ) . '://' . $base_host . ( $p['path'] ?? '/' );
            if ( rtrim( $clean, '/' ) === rtrim( $site_url, '/' ) ) { continue; }
            $candidates[ $clean ] = true;
            if ( count( $candidates ) >= 3 ) { break; }
        }
        return array_merge( $urls, array_keys( $candidates ) );
    }

    /**
     * 自社サイトの生データを5項目に意味圧縮する（Gemini 1回呼び出し + Transient キャッシュ）
     *
     * 出力: [ services, target, strengths, weaknesses, not_provided ] — 各200〜300字
     */
    private function summarize_own_site( int $user_id, array $raw ): array {
        $pages = $raw['pages'] ?? [];
        if ( empty( $pages ) ) { return []; }

        // キャッシュキー: ページ診断データの内容ハッシュ
        $sig_parts = [];
        foreach ( $pages as $p ) {
            $sig_parts[] = ( $p['url'] ?? '' ) . '|' . mb_substr( $p['ai_summary'] ?? '', 0, 80 )
                . '|' . mb_substr( $p['purpose'] ?? '', 0, 80 );
        }
        $hash = substr( md5( implode( "\n", $sig_parts ) . '|' . ( $raw['source'] ?? '' ) ), 0, 16 );
        $cache_key = 'gcrev_sitesum_' . $user_id . '_' . $hash;
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached['services'] ) ) {
            $this->log( sprintf(
                'summarize_own_site: cache=hit, user=%d, bytes=%d',
                $user_id, strlen( wp_json_encode( $cached, JSON_UNESCAPED_UNICODE ) )
            ) );
            return $cached;
        }

        // 要約元の入力を構築
        $input_parts = [];
        foreach ( $pages as $i => $p ) {
            $n = $i + 1;
            $line  = "### ページ{$n}: " . $p['title'] . " ({$p['url']})";
            if ( $p['page_type'] )  $line .= "\n- 種別: {$p['page_type']}";
            if ( $p['purpose'] )    $line .= "\n- 目的/訴求: " . mb_substr( $p['purpose'], 0, 300 );
            if ( $p['cta'] )        $line .= "\n- CTA: " . mb_substr( $p['cta'], 0, 200 );
            if ( $p['ai_summary'] ) $line .= "\n- 要約: " . mb_substr( $p['ai_summary'], 0, 400 );
            $ins = $p['insights'];
            if ( ! empty( $ins['strengths'] ) )       $line .= "\n- 強み: "   . $this->flatten_insight( $ins['strengths'] );
            if ( ! empty( $ins['weaknesses'] ) )      $line .= "\n- 弱み: "   . $this->flatten_insight( $ins['weaknesses'] );
            if ( ! empty( $ins['suggestions'] ) )     $line .= "\n- 改善案: " . $this->flatten_insight( $ins['suggestions'] );
            if ( ! empty( $ins['target_audience'] ) ) $line .= "\n- ターゲット: " . $this->flatten_insight( $ins['target_audience'] );
            if ( ! empty( $ins['headings_h2'] ) )     $line .= "\n- H2見出し: " . $this->flatten_insight( $ins['headings_h2'] );
            if ( ! empty( $p['body_excerpt'] ) )      $line .= "\n- 本文抜粋: " . mb_substr( $p['body_excerpt'], 0, 200 );
            $input_parts[] = $line;
        }
        $input_text = implode( "\n\n", $input_parts );

        $prompt = <<<PROMPT
あなたはSEO戦略のために、サイトの実体を「意味」として圧縮する専門家です。
以下のページ情報群から、このサイトを5項目で簡潔に要約してください。

出力は必ず以下のJSON形式のみで返してください（前後に説明文・マークダウン記号なし）。
各項目 200〜300文字。重複排除・具体名詞中心・抽象語を避けてください。
特に「not_provided」は「このサイトで明確に扱っていない領域」を入力に書かれていないものから列挙してください（推測ではなく不在領域の明示）。

{
  "services": "このサイトが提供している具体サービス・商品を列挙",
  "target": "想定ターゲットペルソナ（属性・課題・検索意図）",
  "strengths": "サイトの強み・差別化ポイント（訴求・実績・独自性）",
  "weaknesses": "弱み・不足領域（ページが薄い・訴求が弱い箇所）",
  "not_provided": "このサイトが扱っていないサービス・商品領域（重要）"
}

=== 入力 ===
{$input_text}
PROMPT;

        try {
            $raw_text = $this->ai->call_gemini_api( $prompt, [
                'temperature'     => 0.3,
                'maxOutputTokens' => 2048,
            ] );
        } catch ( \Throwable $e ) {
            $this->log( 'summarize_own_site: gemini error: ' . $e->getMessage() );
            return $this->summarize_fallback( $pages );
        }

        // JSON抽出
        $cleaned = preg_replace( '/^```(?:json)?\s*/m', '', $raw_text );
        $cleaned = preg_replace( '/```\s*$/m', '', $cleaned );
        $cleaned = trim( (string) $cleaned );
        $first = strpos( $cleaned, '{' );
        $last  = strrpos( $cleaned, '}' );
        if ( $first === false || $last === false || $last <= $first ) {
            $this->log( 'summarize_own_site: JSON not found in response' );
            return $this->summarize_fallback( $pages );
        }
        $data = json_decode( substr( $cleaned, $first, $last - $first + 1 ), true );
        if ( ! is_array( $data ) ) {
            $this->log( 'summarize_own_site: JSON decode failed' );
            return $this->summarize_fallback( $pages );
        }

        // 5項目正規化＋300字切り詰め
        $summary = [
            'services'     => mb_substr( (string) ( $data['services'] ?? '' ), 0, 320 ),
            'target'       => mb_substr( (string) ( $data['target'] ?? '' ), 0, 320 ),
            'strengths'    => mb_substr( (string) ( $data['strengths'] ?? '' ), 0, 320 ),
            'weaknesses'   => mb_substr( (string) ( $data['weaknesses'] ?? '' ), 0, 320 ),
            'not_provided' => mb_substr( (string) ( $data['not_provided'] ?? '' ), 0, 320 ),
        ];

        if ( $summary['services'] === '' ) {
            $this->log( 'summarize_own_site: services empty, using fallback' );
            return $this->summarize_fallback( $pages );
        }

        set_transient( $cache_key, $summary, self::SITE_SUMMARY_TTL );
        $this->log( sprintf(
            'summarize_own_site: cache=miss, user=%d, bytes=%d',
            $user_id, strlen( wp_json_encode( $summary, JSON_UNESCAPED_UNICODE ) )
        ) );
        return $summary;
    }

    /**
     * Gemini 要約失敗時のフォールバック: タイトル連結のみ
     */
    private function summarize_fallback( array $pages ): array {
        $titles = [];
        foreach ( $pages as $p ) {
            if ( ! empty( $p['title'] ) ) { $titles[] = $p['title']; }
        }
        return [
            'services'     => mb_substr( implode( ' / ', $titles ), 0, 320 ),
            'target'       => '',
            'strengths'    => '',
            'weaknesses'   => '',
            'not_provided' => '',
        ];
    }

    /**
     * 要約をプロンプト用 Markdown に整形
     */
    private function build_own_site_summary_section( array $summary ): string {
        if ( empty( $summary['services'] ) ) { return ''; }
        $s  = "## 自社サイトの要約（一次情報・キーワード採択の根拠）\n\n";
        $s .= "**サービス:**\n{$summary['services']}\n\n";
        if ( ! empty( $summary['target'] ) )       $s .= "**ターゲット:**\n{$summary['target']}\n\n";
        if ( ! empty( $summary['strengths'] ) )    $s .= "**強み:**\n{$summary['strengths']}\n\n";
        if ( ! empty( $summary['weaknesses'] ) )   $s .= "**弱み:**\n{$summary['weaknesses']}\n\n";
        if ( ! empty( $summary['not_provided'] ) ) $s .= "**提供していない領域（重要）:**\n{$summary['not_provided']}\n";
        return $s;
    }

    /**
     * キーワード採択基準セクション（固定文言）
     */
    private function build_acceptance_criteria_section(): string {
        return <<<CRITERIA
## キーワード採択基準（絶対遵守）

### 4要素による最終判断
単純な関連度スコアではなく、以下4要素で総合判断してください：

1. **relevance_score** (0-100): 上記サイト要約の services/strengths との意味的関連度
2. **business_fit** (0-100): ビジネス適合度。100=完全一致・CV直結、50=関連あるが収益に繋がりにくい、0=ビジネス的に無意味
3. **intent**: 顕在 / 潜在 / 比較検討 / 情報収集 のいずれか
4. **cv_distance**: 近い / 中 / 遠い のいずれか

### 基本閾値
- relevance_score 70以上 **かつ** business_fit 60以上 → 通常採択
- いずれか下回るキーワードは `excluded` グループへ

### 例外ルール（基本閾値を下回っても採択）
- **CV直結キーワード**: business_fit 70以上かつ cv_distance=近い → relevance_score 60以上で採択可
- **ローカルキーワード**（地域×自社サービス）: 優先採用。business_fit 50以上で採択可
- **競合比較系**（「○○ 比較」「○○ おすすめ」「○○ vs △△」）: business_fit 60以上で採択可

### 集客拡張キーワード（traffic_expansion）の採用ルール
将来の顧客（潜在層）を獲得するための教育・認知コンテンツ向けとして独立グループを設ける。
以下をすべて満たすキーワードは `traffic_expansion` グループへ採択：
- relevance_score 50以上
- business_fit 40以上
- intent が「潜在」または「情報収集」
- **not_provided に該当する領域は除外**（必ず excluded へ）
- 検索ボリュームが比較的大きいものを優先
- 直接CVには繋がらないが、教育・認知目的で自社サービスと関連のある話題を扱う

### 絶対除外条件（スコアに関わらず必ず excluded へ）
- サイト要約の **not_provided** に含まれる領域に触れるキーワード（business_fit は自動的に 0〜20 とする）
- 情報収集のみでCVに繋がらないキーワード
- 一般論・広すぎるワード（単体「健康」「子育て」「ビジネス」等）
- ペルソナ（target）の検索意図と明確に不一致のキーワード

### excluded キーワードに必須のフィールド
- `why_not_target`: 「このサイトでは〇〇を提供していないため対象外」のように、自社サービスとの不一致を具体的に明示（30〜100文字）

### 採択KWの reason フィールドには
自社サービス（サイト要約の services）との紐づけを必ず含めてください。
例: 「自社が提供する『〇〇』への検索意図と合致」「強み〇〇を訴求できる」
CRITERIA;
    }

    /**
     * トップページのスクリーンショット attachment_id から base64 画像ペイロードを生成
     *
     * @return array|null ['mime_type' => '...', 'data' => '<base64>'] または null
     */
    private function pick_top_page_image( int $attachment_id ): ?array {
        if ( $attachment_id <= 0 ) { return null; }
        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            $this->log( 'pick_top_page_image: file not found, attachment=' . $attachment_id );
            return null;
        }
        $size = filesize( $path );
        if ( $size === false || $size <= 0 || $size > self::IMAGE_MAX_BYTES ) {
            $this->log( sprintf(
                'pick_top_page_image: size out of range, attachment=%d, size=%d',
                $attachment_id, (int) $size
            ) );
            return null;
        }
        $bin = @file_get_contents( $path );
        if ( $bin === false || $bin === '' ) {
            $this->log( 'pick_top_page_image: read failed, attachment=' . $attachment_id );
            return null;
        }
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $mime = 'image/jpeg';
        if ( $ext === 'png' ) { $mime = 'image/png'; }
        elseif ( $ext === 'webp' ) { $mime = 'image/webp'; }
        $this->log( sprintf(
            'pick_top_page_image: ok, attachment=%d, size=%dKB, mime=%s',
            $attachment_id, (int) ( $size / 1024 ), $mime
        ) );
        return [
            'mime_type' => $mime,
            'data'      => base64_encode( $bin ),
        ];
    }

    /**
     * insights 配列/文字列を安全にフラット化（プロンプト用）
     */
    private function flatten_insight( $v ): string {
        if ( is_array( $v ) ) {
            $parts = array_map(
                fn( $x ) => is_array( $x ) ? wp_json_encode( $x, JSON_UNESCAPED_UNICODE ) : (string) $x,
                $v
            );
            return mb_substr( implode( ' / ', array_filter( $parts ) ), 0, 400 );
        }
        return mb_substr( (string) $v, 0, 400 );
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
