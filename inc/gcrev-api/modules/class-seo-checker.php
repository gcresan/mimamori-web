<?php
// FILE: inc/gcrev-api/modules/class-seo-checker.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SEO診断サービス v2
 *
 * sitemap.xml → クロール → HTML解析 → ルールベーススコアリング → 履歴保存
 */
class Gcrev_SEO_Checker {

    /** user meta key（レガシー — 後方互換用） */
    private const META_KEY = 'gcrev_seo_diagnosis';

    /** user meta key（履歴用） */
    private const META_KEY_HISTORY = 'gcrev_seo_diagnosis_history';

    /** 履歴保持件数 */
    private const MAX_HISTORY = 10;

    /** クロール上限 */
    private const MAX_PAGES = 20;

    /** フェッチ間隔（秒） */
    private const CRAWL_DELAY = 0.5;

    /** User-Agent */
    private const USER_AGENT = 'MimamoriSEO/1.0';

    /** Gemini AI クライアント（キーワード分析用、null なら AI 分析スキップ） */
    private $ai_client;

    public function __construct( $ai_client = null ) {
        $this->ai_client = $ai_client;
    }

    /**
     * コンテンツ量チェックの対象外URLパターン
     *
     * 確認ページ・送信完了ページ等、SEO的にコンテンツ量が少なくて当然のページ。
     * パスの末尾部分（スラッシュ除去後）に部分一致で判定する。
     * 拡張時はここにパターンを追加するだけでよい。
     */
    private const CONTENT_EXCLUDE_SLUGS = [
        'thanks',
        'thank-you',
        'thankyou',
        'confirm',
        'confirmation',
        'complete',
        'finish',
        'finished',
        'done',
        'contact-confirm',
        'contact-thanks',
        'contact-complete',
        'form-confirm',
        'form-thanks',
        'form-complete',
        'sent',
        'submitted',
    ];

    /* =========================================================
     * 公開 API
     * ========================================================= */

    /**
     * 診断を実行して保存し、結果を返す
     */
    public function run_diagnosis( int $user_id ): array {
        $site_url = $this->resolve_site_url( $user_id );
        if ( ! $site_url ) {
            throw new \RuntimeException( 'クライアント設定にサイトURLが登録されていません。' );
        }

        $host = wp_parse_url( $site_url, PHP_URL_HOST );
        if ( ! $host ) {
            throw new \RuntimeException( '無効なサイトURLです: ' . esc_html( $site_url ) );
        }

        // 1. URL一覧取得（sitemap → fallback: リンクディスカバリー）
        $urls = $this->fetch_sitemap( $site_url );
        if ( empty( $urls ) ) {
            $urls = $this->discover_urls_from_homepage( $site_url, $host );
        }
        // トップページを必ず先頭に
        $site_url_normalized = trailingslashit( $site_url );
        $urls = array_unique( array_merge( [ $site_url_normalized ], $urls ) );

        // クライアント設定の解析対象URL条件 / 解析除外URL条件 を反映
        // /media/ などの除外配下を SEO 診断のクロール対象から外す。
        $urls = $this->apply_user_path_filters( $urls, $user_id, $site_url_normalized );

        $urls = array_slice( $urls, 0, self::MAX_PAGES );

        // 2. クロール & 解析
        $page_results = $this->crawl_and_analyze( $urls, $host );

        // 3. スコアリング（8次元）
        $scores = $this->compute_scores( $page_results );

        // 4. キーワード最適化分析
        $keywords         = $this->fetch_user_keywords( $user_id );
        $keyword_coverage = [];
        $ai_analysis      = null;

        if ( ! empty( $keywords ) ) {
            $keyword_coverage = $this->analyze_keyword_coverage( $keywords, $page_results );
            $ai_analysis      = $this->analyze_keyword_with_ai( $keywords, $page_results );
            $keyword_score    = $this->score_keyword_optimization( $keyword_coverage, $ai_analysis );
            $scores[]         = $keyword_score;
        }

        // 5. 総合スコア
        $total_score = $this->compute_total_score( $scores );
        $rank        = $this->determine_rank( $total_score );

        // 6. 問題一覧（既存 + キーワード）
        $issues = $this->compute_issues( $page_results );
        if ( ! empty( $keyword_coverage ) ) {
            $kw_issues = $this->compute_keyword_issues( $keyword_coverage, $ai_analysis );
            $issues    = array_merge( $issues, $kw_issues );
            $priority_order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
            usort( $issues, function( $a, $b ) use ( $priority_order ) {
                return ( $priority_order[ $a['priority'] ] ?? 9 ) - ( $priority_order[ $b['priority'] ] ?? 9 );
            } );
        }

        // 6b. 全issueにページタイトルを付与
        $title_map = [];
        foreach ( $page_results as $p ) {
            $title_map[ $this->url_path( $p['url'] ) ] = $p['title'] ?? '';
        }
        foreach ( $issues as &$_issue ) {
            $_issue['pageTitle'] = $title_map[ $_issue['url'] ] ?? '';
        }
        unset( $_issue );

        // 7. 改善提案（キーワード + 既存）
        $recommendations = $this->compute_recommendations( $scores, $issues );
        if ( ! empty( $keyword_coverage ) ) {
            $kw_recs = $this->compute_keyword_recommendations( $keyword_coverage, $ai_analysis );
            $recommendations = array_merge( $kw_recs, $recommendations );
            // 全体を重要度順に再ソート
            $priority_order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
            usort( $recommendations, function( $a, $b ) use ( $priority_order ) {
                return ( $priority_order[ $a['priority'] ] ?? 9 ) - ( $priority_order[ $b['priority'] ] ?? 9 );
            } );
        }

        // 8. 全体評価
        $assessment = $this->compute_overall_assessment( $scores, $total_score, $rank );

        $critical_count = 0;
        $warning_count  = 0;
        foreach ( $scores as $s ) {
            if ( ! empty( $s['excluded'] ) ) { continue; }
            if ( $s['status'] === 'critical' ) { $critical_count++; }
            if ( $s['status'] === 'caution' )  { $warning_count++; }
        }

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y/m/d H:i' );

        $data = [
            'version'           => 2,
            'updated_at'        => $now,
            'site_url'          => $site_url,
            'siteSummary'       => [
                'totalScore'    => $total_score,
                'rank'          => $rank,
                'criticalCount' => $critical_count,
                'warningCount'  => $warning_count,
                'pageCount'     => count( $page_results ),
                'lastCheckedAt' => $now,
            ],
            'seoChecks'         => array_values( $scores ),
            'overallAssessment' => $assessment,
            'issuePages'        => $issues,
            'recommendations'   => $recommendations,
            'keywordAnalysis'   => [
                'keywords'     => $keyword_coverage,
                'aiAnalysis'   => $ai_analysis,
                'keywordCount' => count( $keywords ),
            ],
        ];

        $this->save_diagnosis( $user_id, $data );

        return $data;
    }

    /**
     * 保存済み診断結果を取得（比較データ付き）
     */
    public function get_diagnosis( int $user_id ): ?array {
        $raw = get_user_meta( $user_id, self::META_KEY_HISTORY, true );

        // 新形式: PHP配列で保存されたデータ
        if ( is_array( $raw ) && ! empty( $raw['history'] ) ) {
            $current  = $raw['history'][0];
            $previous = $raw['history'][1] ?? null;
            $current['comparison']   = $this->compute_comparison( $current, $previous );
            $current['historyCount'] = count( $raw['history'] );
            return $current;
        }

        // 旧形式: JSON文字列で保存された既存データ
        if ( $raw && is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) && ! empty( $decoded['history'] ) ) {
                $current  = $decoded['history'][0];
                $previous = $decoded['history'][1] ?? null;
                $current['comparison']   = $this->compute_comparison( $current, $previous );
                $current['historyCount'] = count( $decoded['history'] );
                return $current;
            }
        }

        // レガシーキーにフォールバック
        $legacy = get_user_meta( $user_id, self::META_KEY, true );
        if ( is_array( $legacy ) && isset( $legacy['siteSummary'] ) ) {
            $legacy['comparison']   = null;
            $legacy['historyCount'] = 1;
            return $legacy;
        }
        if ( $legacy && is_string( $legacy ) ) {
            $data = json_decode( $legacy, true );
            if ( is_array( $data ) ) {
                $data['comparison']   = null;
                $data['historyCount'] = 1;
                return $data;
            }
        }

        return null;
    }

    /**
     * 診断結果を履歴として保存（PHP配列としてWordPressに委ねる）
     */
    public function save_diagnosis( int $user_id, array $data ): void {
        // 不正UTF-8をサニタイズ
        $data = $this->sanitize_utf8_recursive( $data );

        // 既存の履歴を読み込み
        $history = [];
        $raw = get_user_meta( $user_id, self::META_KEY_HISTORY, true );

        // 新形式: PHP配列
        if ( is_array( $raw ) && isset( $raw['history'] ) ) {
            $history = $raw['history'];
        }
        // 旧形式: JSON文字列
        elseif ( $raw && is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) && isset( $decoded['history'] ) ) {
                $history = $decoded['history'];
            }
        }

        // 空ならレガシーキーからマイグレーション
        if ( empty( $history ) ) {
            $old = get_user_meta( $user_id, self::META_KEY, true );
            if ( is_array( $old ) && isset( $old['siteSummary'] ) ) {
                $history[] = $old;
            } elseif ( $old && is_string( $old ) ) {
                $old_data = json_decode( $old, true );
                if ( is_array( $old_data ) && isset( $old_data['siteSummary'] ) ) {
                    $history[] = $old_data;
                }
            }
        }

        // 新結果を先頭に追加、最大件数に制限
        array_unshift( $history, $data );
        $history = array_slice( $history, 0, self::MAX_HISTORY );

        $envelope = [
            'version' => 2,
            'history' => $history,
        ];

        // PHP配列として保存（WordPressが内部でserializeする）
        $result = update_user_meta( $user_id, self::META_KEY_HISTORY, $envelope );
        update_user_meta( $user_id, self::META_KEY, $data );

        // 保存失敗時のデバッグログ
        if ( $result === false ) {
            file_put_contents( '/tmp/gcrev_seo_debug.log',
                date( 'Y-m-d H:i:s' ) . " SAVE FAILED: user={$user_id}, history_count=" . count( $history ) . "\n",
                FILE_APPEND
            );
        }
    }

    /**
     * 配列内の文字列を再帰的にUTF-8サニタイズ
     */
    private function sanitize_utf8_recursive( $value ) {
        if ( is_string( $value ) ) {
            // 不正なUTF-8バイトを除去
            $value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
            return $value;
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $k => $v ) {
                $value[ $k ] = $this->sanitize_utf8_recursive( $v );
            }
        }
        return $value;
    }

    /* =========================================================
     * 比較データ算出
     * ========================================================= */

    private function compute_comparison( array $current, ?array $previous ): ?array {
        if ( ! $previous || empty( $previous['seoChecks'] ) ) {
            return null;
        }

        $cur_scores  = [];
        foreach ( $current['seoChecks'] as $c ) {
            $cur_scores[ $c['key'] ] = $c['score'];
        }
        $prev_scores = [];
        foreach ( $previous['seoChecks'] as $c ) {
            $prev_scores[ $c['key'] ] = $c['score'];
        }

        $improved  = 0;
        $worsened  = 0;
        $per_check = [];
        foreach ( $cur_scores as $key => $score ) {
            $prev  = $prev_scores[ $key ] ?? null;
            $delta = ( $prev !== null ) ? $score - $prev : null;
            if ( $delta !== null && $delta > 0 ) { $improved++; }
            if ( $delta !== null && $delta < 0 ) { $worsened++; }
            $per_check[ $key ] = [
                'current'  => $score,
                'previous' => $prev,
                'delta'    => $delta,
            ];
        }

        $cur_total  = $current['siteSummary']['totalScore'] ?? 0;
        $prev_total = $previous['siteSummary']['totalScore'] ?? 0;

        return [
            'previousDate'    => $previous['updated_at'] ?? $previous['siteSummary']['lastCheckedAt'] ?? null,
            'totalScoreDelta' => $cur_total - $prev_total,
            'improvedCount'   => $improved,
            'worsenedCount'   => $worsened,
            'perCheck'        => $per_check,
        ];
    }

    /* =========================================================
     * 解析対象URL条件 / 解析除外URL条件
     * ========================================================= */

    /**
     * クライアント設定の include/exclude path 条件で URL リストを絞り込む。
     * トップページ（site_url_normalized）は SEO 診断の基準ページとして必ず残す。
     */
    private function apply_user_path_filters( array $urls, int $user_id, string $site_url_normalized ): array {
        if ( ! class_exists( 'Gcrev_Path_Filter' ) ) {
            return $urls;
        }
        $f = Gcrev_Path_Filter::get_user_filters( $user_id );
        if ( empty( $f['include'] ) && empty( $f['exclude'] ) ) {
            return $urls;
        }

        $top = trailingslashit( $site_url_normalized );

        $filtered = [];
        foreach ( $urls as $u ) {
            // トップページは常に残す（include 条件に一致しなくても診断基準として必要）
            if ( $u === $top || $u === rtrim( $top, '/' ) ) {
                $filtered[] = $u;
                continue;
            }
            if ( Gcrev_Path_Filter::matches( $u, $f['include'], $f['exclude'] ) ) {
                $filtered[] = $u;
            }
        }
        return $filtered;
    }

    /* =========================================================
     * サイトURL解決
     * ========================================================= */

    private function resolve_site_url( int $user_id ): string {
        $url = get_user_meta( $user_id, 'gcrev_client_site_url', true );
        if ( ! $url ) {
            $url = get_user_meta( $user_id, 'report_site_url', true );
        }
        if ( ! $url ) {
            $url = get_user_meta( $user_id, 'weisite_url', true );
        }
        if ( ! $url ) {
            return '';
        }
        $url = esc_url_raw( $url );
        if ( $url && strpos( $url, '://' ) === false ) {
            $url = 'https://' . $url;
        }
        return trailingslashit( $url );
    }

    /* =========================================================
     * Sitemap 取得
     * ========================================================= */

    private function fetch_sitemap( string $site_url ): array {
        $sitemap_url = trailingslashit( $site_url ) . 'sitemap.xml';
        $body = $this->fetch_body( $sitemap_url );
        if ( ! $body ) {
            return [];
        }
        return $this->parse_sitemap_xml( $body, $site_url );
    }

    private function parse_sitemap_xml( string $xml, string $site_url ): array {
        libxml_use_internal_errors( true );
        $doc = simplexml_load_string( $xml );
        libxml_clear_errors();
        if ( ! $doc ) {
            return [];
        }

        // sitemap index → 全サブsitemapを取得して結合
        $doc->registerXPathNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
        $sitemaps = $doc->xpath( '//sm:sitemap/sm:loc' );
        if ( ! empty( $sitemaps ) ) {
            $all_urls = [];
            foreach ( $sitemaps as $sitemap_loc ) {
                $sub_body = $this->fetch_body( (string) $sitemap_loc );
                if ( $sub_body ) {
                    $sub_urls = $this->parse_sitemap_xml( $sub_body, $site_url );
                    $all_urls = array_merge( $all_urls, $sub_urls );
                }
            }
            return array_values( array_unique( $all_urls ) );
        }

        // 通常のsitemap → URL一覧
        $locs = $doc->xpath( '//sm:url/sm:loc' );
        if ( empty( $locs ) ) {
            $locs = $doc->xpath( '//url/loc' );
        }
        $urls = [];
        $host = wp_parse_url( $site_url, PHP_URL_HOST );
        foreach ( $locs as $loc ) {
            $u = trim( (string) $loc );
            if ( ! $u ) { continue; }
            $u_host = wp_parse_url( $u, PHP_URL_HOST );
            if ( $u_host && $u_host !== $host ) { continue; }
            if ( preg_match( '#\.(jpg|jpeg|png|gif|svg|webp|pdf|zip|css|js)$#i', $u ) ) { continue; }
            if ( preg_match( '#/wp-(admin|login|content|includes|json)/#', $u ) ) { continue; }
            if ( preg_match( '#/(feed|xmlrpc)#', $u ) ) { continue; }
            $urls[] = $u;
        }
        return $urls;
    }

    /**
     * トップページからリンクディスカバリー（sitemap取得失敗時のフォールバック）
     */
    private function discover_urls_from_homepage( string $site_url, string $host ): array {
        $html = $this->fetch_body( $site_url );
        if ( ! $html ) {
            return [];
        }
        return $this->extract_same_domain_links( $html, $host );
    }

    private function extract_same_domain_links( string $html, string $host ): array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        @$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        $xpath = new \DOMXPath( $dom );
        $nodes = $xpath->query( '//a[@href]' );
        $urls  = [];
        foreach ( $nodes as $node ) {
            $href = trim( $node->getAttribute( 'href' ) );
            if ( ! $href || $href[0] === '#' || strpos( $href, 'javascript:' ) === 0 || strpos( $href, 'mailto:' ) === 0 || strpos( $href, 'tel:' ) === 0 ) {
                continue;
            }
            if ( strpos( $href, '//' ) === false ) {
                $href = 'https://' . $host . '/' . ltrim( $href, '/' );
            }
            $href = strtok( $href, '?#' );
            $h = wp_parse_url( $href, PHP_URL_HOST );
            if ( $h !== $host ) { continue; }
            if ( preg_match( '#\.(jpg|jpeg|png|gif|svg|webp|pdf|zip|css|js)$#i', $href ) ) { continue; }
            if ( preg_match( '#/wp-(admin|login|content|includes|json)/#', $href ) ) { continue; }
            $urls[] = $href;
        }
        return array_values( array_unique( $urls ) );
    }

    /* =========================================================
     * クロール & 解析
     * ========================================================= */

    private function crawl_and_analyze( array $urls, string $host ): array {
        $results = [];
        foreach ( $urls as $i => $url ) {
            if ( $i > 0 ) {
                usleep( (int) ( self::CRAWL_DELAY * 1000000 ) );
            }
            $res = $this->fetch_page( $url );
            if ( $res['html'] ) {
                $analysis = $this->analyze_page( $res['html'], $url, $res['status'], $host );
            } else {
                $analysis = [
                    'url'               => $url,
                    'status_code'       => $res['status'],
                    'title'             => '',
                    'meta_description'  => '',
                    'h1'                => [],
                    'h2'                => [],
                    'images_total'      => 0,
                    'images_no_alt'     => 0,
                    'canonical'         => '',
                    'internal_links'    => 0,
                    'body_text_length'  => 0,
                    'body_text_excerpt' => '',
                    'fetch_error'       => true,
                ];
            }
            $results[] = $analysis;
        }
        return $results;
    }

    private function fetch_page( string $url ): array {
        $max_attempts = 2;
        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $res = wp_remote_get( $url, [
                'timeout'             => 15,
                'redirection'         => 3,
                'user-agent'          => self::USER_AGENT,
                'limit_response_size' => 1024000,
                'sslverify'           => false,
            ] );
            if ( is_wp_error( $res ) ) {
                // タイムアウト時のみリトライ
                if ( $attempt < $max_attempts && strpos( $res->get_error_message(), 'timed out' ) !== false ) {
                    usleep( 1000000 ); // 1秒待機
                    continue;
                }
                return [ 'url' => $url, 'status' => 0, 'html' => '' ];
            }
            return [
                'url'    => $url,
                'status' => (int) wp_remote_retrieve_response_code( $res ),
                'html'   => wp_remote_retrieve_body( $res ),
            ];
        }
        return [ 'url' => $url, 'status' => 0, 'html' => '' ];
    }

    private function fetch_body( string $url ): string {
        $res = wp_remote_get( $url, [
            'timeout'             => 10,
            'redirection'         => 3,
            'user-agent'          => self::USER_AGENT,
            'limit_response_size' => 1024000,
            'sslverify'           => false,
        ] );
        if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
            return '';
        }
        return wp_remote_retrieve_body( $res );
    }

    /* =========================================================
     * HTML 解析
     * ========================================================= */

    private function analyze_page( string $html, string $url, int $status_code, string $host ): array {
        $dom = new \DOMDocument();
        libxml_use_internal_errors( true );
        @$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        $xpath = new \DOMXPath( $dom );

        $body_text = $this->extract_body_text( $dom );

        return [
            'url'               => $url,
            'status_code'       => $status_code,
            'title'             => $this->extract_title( $xpath ),
            'meta_description'  => $this->extract_meta_description( $xpath ),
            'h1'                => $this->extract_headings( $xpath, 'h1' ),
            'h2'                => $this->extract_headings( $xpath, 'h2' ),
            'images_total'      => $this->count_images( $xpath ),
            'images_no_alt'     => $this->count_images_no_alt( $xpath ),
            'canonical'         => $this->extract_canonical( $xpath ),
            'internal_links'    => $this->count_internal_links( $xpath, $host ),
            'body_text_length'  => mb_strlen( $body_text ),
            'body_text_excerpt' => mb_substr( $body_text, 0, 500 ),
            'fetch_error'       => false,
        ];
    }

    private function extract_title( \DOMXPath $xpath ): string {
        $nodes = $xpath->query( '//title' );
        if ( $nodes && $nodes->length > 0 ) {
            return trim( $nodes->item( 0 )->textContent );
        }
        return '';
    }

    private function extract_meta_description( \DOMXPath $xpath ): string {
        $nodes = $xpath->query( '//meta[@name="description"]/@content' );
        if ( $nodes && $nodes->length > 0 ) {
            return trim( $nodes->item( 0 )->nodeValue );
        }
        return '';
    }

    private function extract_headings( \DOMXPath $xpath, string $tag ): array {
        $nodes  = $xpath->query( '//' . $tag );
        $result = [];
        if ( $nodes ) {
            foreach ( $nodes as $n ) {
                $text = trim( $n->textContent );
                $result[] = $text;
            }
        }
        return $result;
    }

    private function count_images( \DOMXPath $xpath ): int {
        $nodes = $xpath->query( '//img' );
        return $nodes ? $nodes->length : 0;
    }

    private function count_images_no_alt( \DOMXPath $xpath ): int {
        $nodes = $xpath->query( '//img[not(@alt) or @alt=""]' );
        return $nodes ? $nodes->length : 0;
    }

    private function extract_canonical( \DOMXPath $xpath ): string {
        $nodes = $xpath->query( '//link[@rel="canonical"]/@href' );
        if ( $nodes && $nodes->length > 0 ) {
            return trim( $nodes->item( 0 )->nodeValue );
        }
        return '';
    }

    private function count_internal_links( \DOMXPath $xpath, string $host ): int {
        $nodes = $xpath->query( '//a[@href]' );
        $count = 0;
        if ( $nodes ) {
            foreach ( $nodes as $n ) {
                $href = $n->getAttribute( 'href' );
                if ( ! $href ) { continue; }
                if ( strpos( $href, '//' ) === false && $href[0] !== '#' && strpos( $href, 'mailto:' ) !== 0 && strpos( $href, 'tel:' ) !== 0 && strpos( $href, 'javascript:' ) !== 0 ) {
                    $count++;
                    continue;
                }
                $h = wp_parse_url( $href, PHP_URL_HOST );
                if ( $h === $host ) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * 本文テキストを抽出（script/style/nav/header/footer を除去）
     */
    private function extract_body_text( \DOMDocument $dom ): string {
        $body_nodes = $dom->getElementsByTagName( 'body' );
        if ( $body_nodes->length === 0 ) {
            return '';
        }
        $body = $body_nodes->item( 0 );

        // 除外タグを削除したクローンを作成
        $clone = $body->cloneNode( true );
        $remove_tags = [ 'script', 'style', 'nav', 'header', 'footer', 'noscript' ];
        foreach ( $remove_tags as $tag ) {
            $elements = $clone->getElementsByTagName( $tag );
            // 後ろから削除（ライブNodeList対策）
            for ( $i = $elements->length - 1; $i >= 0; $i-- ) {
                $el = $elements->item( $i );
                if ( $el && $el->parentNode ) {
                    $el->parentNode->removeChild( $el );
                }
            }
        }

        $text = trim( $clone->textContent );
        // 連続空白を1つに正規化
        return preg_replace( '/\s+/u', ' ', $text );
    }

    /* =========================================================
     * スコアリング
     * ========================================================= */

    private function compute_scores( array $page_results ): array {
        $scores = [];
        $scores[] = $this->score_title( $page_results );
        $scores[] = $this->score_meta_description( $page_results );
        $scores[] = $this->score_headings( $page_results );
        $scores[] = $this->score_images( $page_results );
        $scores[] = $this->score_internal_links( $page_results );
        $scores[] = $this->score_canonical( $page_results );
        $scores[] = $this->score_status_codes( $page_results );
        $scores[] = $this->score_body_content( $page_results );
        return $scores;
    }

    private function score_title( array $pages ): array {
        $score    = 10;
        $findings = [];
        $affected = [];

        $titles    = [];
        $missing   = 0;
        $too_long  = 0;
        $too_short = 0;

        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            $t = $p['title'];
            if ( $t === '' ) {
                $missing++;
                $affected[] = $this->url_path( $p['url'] );
            } else {
                $len = mb_strlen( $t );
                if ( $len > 60 ) { $too_long++; $affected[] = $this->url_path( $p['url'] ); }
                elseif ( $len < 30 ) { $too_short++; $affected[] = $this->url_path( $p['url'] ); }
                $titles[] = $t;
            }
        }

        // 重複チェック
        $dup_count = count( $titles ) - count( array_unique( $titles ) );

        if ( $missing > 0 )   { $score -= 3; $findings[] = "titleタグが未設定のページが{$missing}つあります"; }
        if ( $dup_count > 0 ) { $score -= 2; $findings[] = "重複したtitleが{$dup_count}件あります"; }
        if ( $too_long > 0 )  { $score -= 1; $findings[] = "長すぎるtitle（60文字超）が{$too_long}ページあります"; }
        if ( $too_short > 0 ) { $score -= 1; $findings[] = "短すぎるtitle（30文字未満）が{$too_short}ページあります"; }
        if ( empty( $findings ) ) { $findings[] = 'すべてのページでtitleタグが適切に設定されています'; }

        $score = max( 0, $score );
        return [
            'key'          => 'title_tag',
            'label'        => 'タイトルタグ',
            'score'        => $score,
            'maxScore'     => 10,
            'status'       => $this->status_from_score( $score ),
            'findings'     => $findings,
            'affectedUrls' => array_values( array_unique( $affected ) ),
        ];
    }

    private function score_meta_description( array $pages ): array {
        $score    = 10;
        $findings = [];
        $affected = [];

        $descs     = [];
        $missing   = 0;
        $too_long  = 0;
        $too_short = 0;

        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            $d = $p['meta_description'];
            if ( $d === '' ) {
                $missing++;
                $affected[] = $this->url_path( $p['url'] );
            } else {
                $len = mb_strlen( $d );
                if ( $len > 160 ) { $too_long++; $affected[] = $this->url_path( $p['url'] ); }
                elseif ( $len < 70 ) { $too_short++; $affected[] = $this->url_path( $p['url'] ); }
                $descs[] = $d;
            }
        }

        $dup_count = count( $descs ) - count( array_unique( $descs ) );

        if ( $missing > 0 )   { $score -= 3; $findings[] = "meta descriptionが未設定のページが{$missing}つあります"; }
        if ( $dup_count > 0 ) { $score -= 2; $findings[] = "重複したdescriptionが{$dup_count}件あります"; }
        if ( $too_long > 0 )  { $score -= 1; $findings[] = "長すぎるdescription（160文字超）が{$too_long}ページあります"; }
        if ( $too_short > 0 ) { $score -= 1; $findings[] = "短すぎるdescription（70文字未満）が{$too_short}ページあります"; }
        if ( empty( $findings ) ) { $findings[] = 'すべてのページでmeta descriptionが適切に設定されています'; }

        $score = max( 0, $score );
        return [
            'key'          => 'meta_description',
            'label'        => 'メタディスクリプション',
            'score'        => $score,
            'maxScore'     => 10,
            'status'       => $this->status_from_score( $score ),
            'findings'     => $findings,
            'affectedUrls' => array_values( array_unique( $affected ) ),
        ];
    }

    private function score_headings( array $pages ): array {
        $score    = 10;
        $findings = [];
        $affected = [];

        $no_h1    = 0;
        $multi_h1 = 0;
        $empty_h1 = 0;
        $no_h2    = 0;

        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            $h1_count = count( $p['h1'] );
            $h2_count = count( $p['h2'] );

            if ( $h1_count === 0 ) {
                $no_h1++;
                $affected[] = $this->url_path( $p['url'] );
            } elseif ( $h1_count > 1 ) {
                $multi_h1++;
                $affected[] = $this->url_path( $p['url'] );
            } else {
                // h1が1つ — 空チェック
                if ( $p['h1'][0] === '' ) {
                    $empty_h1++;
                    $affected[] = $this->url_path( $p['url'] );
                }
            }
            if ( $h2_count === 0 ) {
                $no_h2++;
            }
        }

        if ( $no_h1 > 0 )    { $score -= 3; $findings[] = "h1が未設定のページが{$no_h1}つあります"; }
        if ( $empty_h1 > 0 ) { $score -= 3; $findings[] = "h1タグが空のページが{$empty_h1}つあります"; }
        if ( $multi_h1 > 0 ) { $score -= 2; $findings[] = "h1が複数あるページが{$multi_h1}つあります"; }
        if ( $no_h2 > 0 )    { $score -= 1; $findings[] = "h2が未設定のページが{$no_h2}つあります"; }
        if ( empty( $findings ) ) { $findings[] = 'すべてのページで見出し構造が適切です'; }

        $score = max( 0, $score );
        return [
            'key'          => 'heading_structure',
            'label'        => '見出し構造（h1 / h2）',
            'score'        => $score,
            'maxScore'     => 10,
            'status'       => $this->status_from_score( $score ),
            'findings'     => $findings,
            'affectedUrls' => array_values( array_unique( $affected ) ),
        ];
    }

    private function score_images( array $pages ): array {
        $total_imgs  = 0;
        $no_alt_imgs = 0;
        $affected    = [];

        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            $total_imgs  += $p['images_total'];
            $no_alt_imgs += $p['images_no_alt'];
            if ( $p['images_no_alt'] > 0 ) {
                $affected[] = $this->url_path( $p['url'] );
            }
        }

        $findings = [];
        if ( $total_imgs === 0 ) {
            $score = 10;
            $findings[] = '画像は検出されませんでした';
        } else {
            $alt_rate = ( $total_imgs - $no_alt_imgs ) / $total_imgs;
            $score    = (int) round( $alt_rate * 10 );
            if ( $no_alt_imgs > 0 ) {
                $findings[] = "alt属性が未設定の画像が{$no_alt_imgs}つあります（全{$total_imgs}画像中）";
            }
            if ( $alt_rate >= 1.0 ) {
                $findings[] = 'すべての画像にalt属性が設定されています';
            }
        }

        return [
            'key'          => 'image_alt',
            'label'        => '画像alt属性',
            'score'        => max( 0, $score ),
            'maxScore'     => 10,
            'status'       => $this->status_from_score( $score ),
            'findings'     => $findings,
            'affectedUrls' => array_values( array_unique( $affected ) ),
        ];
    }

    private function score_internal_links( array $pages ): array {
        $score       = 10;
        $findings    = [];
        $affected    = [];
        $link_counts = [];
        $zero_link   = 0;

        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            $link_counts[] = $p['internal_links'];
            if ( $p['internal_links'] === 0 ) {
                $zero_link++;
                $affected[] = $this->url_path( $p['url'] );
            }
        }

        $avg_links = count( $link_counts ) > 0 ? array_sum( $link_counts ) / count( $link_counts ) : 0;

        // ページ数に応じた閾値
        $page_count    = count( $link_counts );
        $avg_threshold = 3;
        if ( $page_count < 5 ) {
            $avg_threshold = 1;
        } elseif ( $page_count <= 10 ) {
            $avg_threshold = 2;
        }

        if ( $zero_link > 0 )            { $score -= 3; $findings[] = "内部リンクが0のページが{$zero_link}つあります（孤立ページ）"; }
        if ( $avg_links < $avg_threshold ) { $score -= 2; $findings[] = sprintf( '平均内部リンク数が少なめです（%.1f本/ページ）', $avg_links ); }
        if ( empty( $findings ) )         { $findings[] = sprintf( '内部リンクは適切に設定されています（平均%.1f本/ページ）', $avg_links ); }

        $score = max( 0, $score );
        return [
            'key'          => 'internal_links',
            'label'        => '内部リンク',
            'score'        => $score,
            'maxScore'     => 10,
            'status'       => $this->status_from_score( $score ),
            'findings'     => $findings,
            'affectedUrls' => array_values( array_unique( $affected ) ),
        ];
    }

    private function score_canonical( array $pages ): array {
        $score    = 10;
        $findings = [];
        $affected = [];
        $missing  = 0;
        $mismatch = 0;

        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            if ( $p['canonical'] === '' ) {
                $missing++;
                $affected[] = $this->url_path( $p['url'] );
            } else {
                // canonical URL不一致チェック
                $canonical_normalized = untrailingslashit( $p['canonical'] );
                $url_normalized       = untrailingslashit( $p['url'] );
                // スキーム差異を無視して比較
                $canonical_normalized = preg_replace( '#^https?://#', '', $canonical_normalized );
                $url_normalized       = preg_replace( '#^https?://#', '', $url_normalized );
                if ( $canonical_normalized !== $url_normalized ) {
                    $mismatch++;
                    $affected[] = $this->url_path( $p['url'] );
                }
            }
        }

        if ( $missing > 0 )  { $score -= 3; $findings[] = "canonicalタグが未設定のページが{$missing}つあります"; }
        if ( $mismatch > 0 ) { $score -= 2; $findings[] = "canonicalのURLが実際のURLと異なるページが{$mismatch}つあります"; }
        if ( empty( $findings ) ) { $findings[] = 'すべてのページでcanonicalタグが適切に設定されています'; }

        $score = max( 0, $score );
        return [
            'key'          => 'canonical',
            'label'        => 'canonical（正規URL）',
            'score'        => $score,
            'maxScore'     => 10,
            'status'       => $this->status_from_score( $score ),
            'findings'     => $findings,
            'affectedUrls' => array_values( array_unique( $affected ) ),
        ];
    }

    private function score_status_codes( array $pages ): array {
        $score       = 10;
        $findings    = [];
        $affected    = [];
        $error_count = 0;

        foreach ( $pages as $p ) {
            if ( $p['status_code'] !== 200 ) {
                $error_count++;
                $affected[] = $this->url_path( $p['url'] ) . ' (' . $p['status_code'] . ')';
            }
        }

        if ( $error_count > 0 ) {
            $score = max( 0, $score - ( $error_count * 3 ) );
            $findings[] = "ステータスコードが200以外のページが{$error_count}つあります";
        }
        if ( empty( $findings ) ) { $findings[] = 'すべてのページが正常に表示されています（200 OK）'; }

        return [
            'key'          => 'status_code',
            'label'        => 'ステータスコード',
            'score'        => $score,
            'maxScore'     => 10,
            'status'       => $this->status_from_score( $score ),
            'findings'     => $findings,
            'affectedUrls' => array_values( array_unique( $affected ) ),
        ];
    }

    /**
     * コンテンツ量スコアリング
     *
     * 確認ページ・送信完了ページ等（CONTENT_EXCLUDE_SLUGS）は
     * コンテンツが少なくて当然なので評価対象から除外する。
     */
    private function score_body_content( array $pages ): array {
        $score     = 10;
        $findings  = [];
        $affected  = [];
        $very_thin = 0;
        $thin      = 0;
        $excluded  = 0;

        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            // 確認・完了ページはコンテンツ量評価から除外
            if ( $this->is_content_excluded_url( $p['url'] ) ) {
                $excluded++;
                continue;
            }
            $len = $p['body_text_length'] ?? 0;
            if ( $len < 100 ) {
                $very_thin++;
                $affected[] = $this->url_path( $p['url'] ) . " ({$len}文字)";
            } elseif ( $len < 300 ) {
                $thin++;
                $affected[] = $this->url_path( $p['url'] ) . " ({$len}文字)";
            }
        }

        if ( $very_thin > 0 ) { $score -= min( $very_thin * 5, 10 ); $findings[] = "コンテンツが極端に少ないページが{$very_thin}つあります（100文字未満）"; }
        if ( $thin > 0 )      { $score -= min( $thin * 2, 6 );       $findings[] = "コンテンツが少なめのページが{$thin}つあります（300文字未満）"; }
        if ( $excluded > 0 )  { $findings[] = "確認・完了ページ{$excluded}件はコンテンツ量評価の対象外としました"; }
        if ( empty( $findings ) ) { $findings[] = 'すべてのページで十分なコンテンツ量があります'; }

        $score = max( 0, $score );
        return [
            'key'          => 'body_content',
            'label'        => 'コンテンツ量',
            'score'        => $score,
            'maxScore'     => 10,
            'status'       => $this->status_from_score( $score ),
            'findings'     => $findings,
            'affectedUrls' => array_values( array_unique( $affected ) ),
        ];
    }

    /* =========================================================
     * 問題一覧生成
     * ========================================================= */

    private function compute_issues( array $page_results ): array {
        $issues = [];

        foreach ( $page_results as $p ) {
            $url_path = $this->url_path( $p['url'] );
            $code     = $p['status_code'];

            if ( $code !== 200 ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => 'ステータスコード',
                    'issueDetail' => "HTTP {$code} エラー",
                    'priority'    => 'high',
                    'suggestion'  => 'ページが正常に表示できません。404はリンク切れの修正かリダイレクト設定、500はサーバー側のエラーを調査してください。',
                ];
            }

            if ( ! empty( $p['fetch_error'] ) ) { continue; }

            // --- title ---
            if ( $p['title'] === '' ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => 'タイトルタグ',
                    'issueDetail' => 'titleタグが未設定',
                    'priority'    => 'high',
                    'suggestion'  => 'titleはGoogle検索結果の見出しになります。ページ内容を表す30〜60文字のtitleを設定してください。',
                ];
            } else {
                $title_len = mb_strlen( $p['title'] );
                if ( $title_len > 60 ) {
                    $issues[] = [
                        'url'         => $url_path,
                        'statusCode'  => $code,
                        'issueType'   => 'タイトルタグ',
                        'issueDetail' => "titleが長すぎます（{$title_len}文字）",
                        'priority'    => 'low',
                        'suggestion'  => '60文字を超えると検索結果で末尾が省略されます。重要なキーワードを前半に配置し、60文字以内に収めてください。',
                    ];
                } elseif ( $title_len < 30 ) {
                    $issues[] = [
                        'url'         => $url_path,
                        'statusCode'  => $code,
                        'issueType'   => 'タイトルタグ',
                        'issueDetail' => "titleが短すぎます（{$title_len}文字）",
                        'priority'    => 'low',
                        'suggestion'  => '30文字未満だと検索結果での訴求力が弱くなります。ターゲットキーワードを含む30〜60文字のtitleに変更してください。',
                    ];
                }
            }

            // --- meta description ---
            if ( $p['meta_description'] === '' ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => 'メタディスクリプション',
                    'issueDetail' => 'meta descriptionが未設定',
                    'priority'    => 'high',
                    'suggestion'  => '未設定だとGoogleがページ内容から自動生成しますが、意図した説明にならないことがあります。70〜160文字で記述してください。',
                ];
            } else {
                $desc_len = mb_strlen( $p['meta_description'] );
                if ( $desc_len > 160 ) {
                    $issues[] = [
                        'url'         => $url_path,
                        'statusCode'  => $code,
                        'issueType'   => 'メタディスクリプション',
                        'issueDetail' => "descriptionが長すぎます（{$desc_len}文字）",
                        'priority'    => 'low',
                        'suggestion'  => '160文字を超えると検索結果で省略されます。重要な情報を前半に配置し、160文字以内に収めてください。',
                    ];
                } elseif ( $desc_len < 70 ) {
                    $issues[] = [
                        'url'         => $url_path,
                        'statusCode'  => $code,
                        'issueType'   => 'メタディスクリプション',
                        'issueDetail' => "descriptionが短すぎます（{$desc_len}文字）",
                        'priority'    => 'low',
                        'suggestion'  => '70文字未満だと検索結果での表示面積が少なくなります。ページの魅力が伝わる70〜160文字の説明文にしてください。',
                    ];
                }
            }

            // --- h1 ---
            $h1_count = count( $p['h1'] );
            if ( $h1_count === 0 ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => '見出し構造',
                    'issueDetail' => 'h1が未設定',
                    'priority'    => 'medium',
                    'suggestion'  => 'h1はページの主題を検索エンジンに伝える最重要の見出しです。ページに1つ、主題を表すh1を設定してください。',
                ];
            } elseif ( $h1_count > 1 ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => '見出し構造',
                    'issueDetail' => "h1が{$h1_count}個あります",
                    'priority'    => 'medium',
                    'suggestion'  => 'h1が複数あるとページの主題が曖昧になります。最も重要な見出し1つだけをh1にし、他はh2以下に変更してください。',
                ];
            } elseif ( $p['h1'][0] === '' ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => '見出し構造',
                    'issueDetail' => 'h1タグが空です',
                    'priority'    => 'high',
                    'suggestion'  => 'h1タグは存在しますがテキストが空です。ページの主題を表すテキストをh1に設定してください。',
                ];
            }

            // --- img alt ---
            if ( $p['images_no_alt'] > 0 ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => '画像alt属性',
                    'issueDetail' => "alt未設定の画像が{$p['images_no_alt']}つ",
                    'priority'    => 'medium',
                    'suggestion'  => 'alt属性は画像の内容を検索エンジンとスクリーンリーダーに伝えます。各画像に内容を説明するaltを追加してください。',
                ];
            }

            // --- canonical ---
            if ( $p['canonical'] === '' ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => 'canonical',
                    'issueDetail' => 'canonicalタグが未設定',
                    'priority'    => 'low',
                    'suggestion'  => 'canonicalがないとURLパラメータ違いで重複コンテンツと判定されるリスクがあります。自身のURLをcanonicalに設定してください。',
                ];
            } else {
                // canonical URL不一致チェック
                $c_norm = preg_replace( '#^https?://#', '', untrailingslashit( $p['canonical'] ) );
                $u_norm = preg_replace( '#^https?://#', '', untrailingslashit( $p['url'] ) );
                if ( $c_norm !== $u_norm ) {
                    $issues[] = [
                        'url'         => $url_path,
                        'statusCode'  => $code,
                        'issueType'   => 'canonical',
                        'issueDetail' => 'canonicalのURLが実際のURLと異なります',
                        'priority'    => 'medium',
                        'suggestion'  => 'canonicalが別URLを指していると、このページが検索結果に表示されなくなる可能性があります。正しいURLか確認してください。',
                    ];
                }
            }

            // --- 内部リンク ---
            if ( $p['internal_links'] === 0 ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => '内部リンク',
                    'issueDetail' => '内部リンクが0本（孤立ページ）',
                    'priority'    => 'medium',
                    'suggestion'  => 'どこからもリンクされていない孤立ページは検索エンジンに発見されにくくなります。関連ページからリンクを追加してください。',
                ];
            }

            // --- コンテンツ量（確認・完了ページは除外） ---
            if ( ! $this->is_content_excluded_url( $p['url'] ) ) {
                $body_len = $p['body_text_length'] ?? 0;
                if ( $body_len < 100 ) {
                    $issues[] = [
                        'url'         => $url_path,
                        'statusCode'  => $code,
                        'issueType'   => 'コンテンツ量',
                        'issueDetail' => "コンテンツが極端に少ない（{$body_len}文字）",
                        'priority'    => 'high',
                        'suggestion'  => 'テキスト量が極端に少ないページは低品質と判定される可能性があります。ユーザーにとって有益な情報を追加してください。',
                    ];
                } elseif ( $body_len < 300 ) {
                    $issues[] = [
                        'url'         => $url_path,
                        'statusCode'  => $code,
                        'issueType'   => 'コンテンツ量',
                        'issueDetail' => "コンテンツが少なめ（{$body_len}文字）",
                        'priority'    => 'medium',
                        'suggestion'  => 'テキスト量が少ないページは検索エンジンからの評価が低くなる傾向があります。ページのテーマに沿った有益なコンテンツを追加してください。',
                    ];
                }
            }
        }

        // 重複title検出
        $this->add_duplicate_issues(
            $issues, $page_results, 'title', 'タイトルタグ',
            'titleが他ページと重複',
            '同じtitleが複数ページにあると検索エンジンが表示ページを判断しにくくなります。各ページ固有のtitleに変更してください。'
        );
        // 重複description検出
        $this->add_duplicate_issues(
            $issues, $page_results, 'meta_description', 'メタディスクリプション',
            'descriptionが他ページと重複',
            '同じdescriptionが複数ページにあると差別化できません。各ページの内容に合った固有の説明文に変更してください。'
        );

        // 優先度でソート
        $priority_order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
        usort( $issues, function( $a, $b ) use ( $priority_order ) {
            return ( $priority_order[ $a['priority'] ] ?? 9 ) - ( $priority_order[ $b['priority'] ] ?? 9 );
        } );

        return $issues;
    }

    private function add_duplicate_issues( array &$issues, array $pages, string $field, string $type, string $detail, string $suggestion ): void {
        $values = [];
        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) || $p[ $field ] === '' ) { continue; }
            $values[ $p[ $field ] ][] = $this->url_path( $p['url'] );
        }
        foreach ( $values as $val => $urls ) {
            if ( count( $urls ) <= 1 ) { continue; }
            foreach ( array_slice( $urls, 1 ) as $u ) {
                $issues[] = [
                    'url'         => $u,
                    'statusCode'  => 200,
                    'issueType'   => $type,
                    'issueDetail' => $detail,
                    'priority'    => 'medium',
                    'suggestion'  => $suggestion,
                ];
            }
        }
    }

    /* =========================================================
     * 改善アクション生成
     * ========================================================= */

    private function compute_recommendations( array $scores, array $issues ): array {
        $recs = [];

        $sorted = $scores;
        usort( $sorted, function( $a, $b ) { return $a['score'] - $b['score']; } );

        $templates = [
            'title_tag' => [
                'title' => 'タイトルタグの改善',
                'desc'  => '【問題】titleの未設定・重複・長さの問題が見つかりました。【影響】titleは検索結果の見出しとして表示され、クリック率に直結します。【対策】各ページに固有の30〜60文字のtitleを設定し、重要キーワードを前半に配置してください。',
            ],
            'meta_description' => [
                'title' => 'メタディスクリプションの改善',
                'desc'  => '【問題】descriptionの未設定・重複・長さの問題が見つかりました。【影響】descriptionは検索結果の説明文として表示され、クリック率に影響します。【対策】各ページに固有の70〜160文字のdescriptionを設定し、ページの魅力を簡潔に伝えてください。',
            ],
            'heading_structure' => [
                'title' => '見出し構造の整理',
                'desc'  => '【問題】h1の未設定・複数設定・空h1が見つかりました。【影響】h1はページの主題を検索エンジンに伝える最重要の見出しです。【対策】各ページにh1を1つだけ設定し、h2以下で内容を階層的に整理してください。',
            ],
            'image_alt' => [
                'title' => '画像alt属性の追加',
                'desc'  => '【問題】alt属性が未設定の画像が見つかりました。【影響】altは画像内容を検索エンジンとスクリーンリーダーに伝える重要な属性です。【対策】各画像の内容を簡潔に説明するalt属性を追加してください。装飾用画像はalt=""で明示してください。',
            ],
            'internal_links' => [
                'title' => '内部リンクの強化',
                'desc'  => '【問題】内部リンクが不足しているページが見つかりました。【影響】内部リンクはサイト内の回遊性とページ評価の分配に影響します。【対策】孤立ページへのリンクを追加し、関連ページ間の導線を整えてください。',
            ],
            'canonical' => [
                'title' => 'canonicalタグの設定',
                'desc'  => '【問題】canonicalタグの未設定またはURL不一致が見つかりました。【影響】canonicalが適切でないと重複コンテンツの問題が発生する可能性があります。【対策】各ページに自身の正規URLをcanonicalとして設定してください。',
            ],
            'status_code' => [
                'title' => 'エラーページの修正',
                'desc'  => '【問題】200以外のステータスコードを返すページがあります。【影響】エラーページはユーザー体験を損ない、検索エンジンの評価にも悪影響です。【対策】404はリンク切れの修正かリダイレクト設定、500はサーバー側エラーの原因を調査してください。',
            ],
            'body_content' => [
                'title' => 'コンテンツ量の充実',
                'desc'  => '【問題】テキスト量が少ないページが見つかりました。【影響】コンテンツが薄いページは検索エンジンから低品質と判定されやすくなります。【対策】ユーザーの疑問に答える有益な情報を追加し、ページのテーマを深掘りしてください。',
            ],
        ];

        foreach ( $sorted as $s ) {
            if ( $s['score'] >= 9 ) { continue; }
            $key = $s['key'];
            if ( isset( $templates[ $key ] ) ) {
                $priority = 'low';
                if ( $s['status'] === 'critical' ) { $priority = 'high'; }
                elseif ( $s['status'] === 'caution' ) { $priority = 'medium'; }

                $recs[] = [
                    'title'       => $templates[ $key ]['title'],
                    'description' => $templates[ $key ]['desc'],
                    'priority'    => $priority,
                ];
            }
        }

        // 重要度の高い順にソート（high → medium → low）
        $priority_order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
        usort( $recs, function( $a, $b ) use ( $priority_order ) {
            return ( $priority_order[ $a['priority'] ] ?? 9 ) - ( $priority_order[ $b['priority'] ] ?? 9 );
        } );

        return $recs;
    }

    /* =========================================================
     * 全体評価
     * ========================================================= */

    private function compute_overall_assessment( array $scores, int $total_score, string $rank ): array {
        $good_points        = [];
        $improvement_points = [];

        foreach ( $scores as $s ) {
            if ( ! empty( $s['excluded'] ) ) { continue; }
            if ( $s['score'] >= 8 ) {
                $good_points[] = $s['label'] . 'は適切に設定されています';
            }
            if ( $s['score'] < 7 ) {
                $first_finding = ! empty( $s['findings'] ) ? $s['findings'][0] : $s['label'] . 'に改善の余地があります';
                $improvement_points[] = $first_finding;
            }
        }

        if ( empty( $good_points ) ) {
            $good_points[] = '診断対象のページがクロールできました';
        }

        $rank_desc = [
            'A' => '良好な状態です。細かな改善でさらに強化できます。',
            'B' => '基本的な設定は概ねできていますが、いくつかの改善点が見つかりました。',
            'C' => '複数の改善点が見つかりました。優先度の高いものから対応してください。',
            'D' => '基本的なSEO設定に不備があります。まず基礎項目の修正から着手してください。',
        ];

        $summary = "SEO総合スコアは{$total_score}点（{$rank}ランク）です。" . ( $rank_desc[ $rank ] ?? '' );

        return [
            'summary'           => $summary,
            'goodPoints'        => $good_points,
            'improvementPoints' => $improvement_points,
        ];
    }

    /* =========================================================
     * キーワード最適化分析
     * ========================================================= */

    /**
     * ユーザーの計測キーワードを取得
     */
    private function fetch_user_keywords( int $user_id ): array {
        global $wpdb;
        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_rank_results';

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword, target_domain FROM {$kw_table}
             WHERE user_id = %d AND enabled = 1
             ORDER BY sort_order ASC, id ASC",
            $user_id
        ), ARRAY_A );

        if ( empty( $keywords ) ) {
            return [];
        }

        // 各キーワードの最新ランクイン URL を取得
        foreach ( $keywords as &$kw ) {
            $found = $wpdb->get_var( $wpdb->prepare(
                "SELECT found_url FROM {$res_table}
                 WHERE keyword_id = %d AND is_ranked = 1 AND found_url != ''
                 ORDER BY fetch_date DESC
                 LIMIT 1",
                (int) $kw['id']
            ) );
            $kw['found_url'] = $found ?: '';
        }
        unset( $kw );

        return $keywords;
    }

    /**
     * ルールベースのキーワード出現チェック
     */
    private function analyze_keyword_coverage( array $keywords, array $page_results ): array {
        $result = [];

        foreach ( $keywords as $kw ) {
            $keyword   = $kw['keyword'];
            $found_url = $kw['found_url'];
            $matches   = [];

            foreach ( $page_results as $p ) {
                if ( ! empty( $p['fetch_error'] ) ) { continue; }

                $in_title = mb_stripos( $p['title'], $keyword ) !== false;
                $in_desc  = mb_stripos( $p['meta_description'], $keyword ) !== false;
                $in_h1    = $this->keyword_in_array( $keyword, $p['h1'] );
                $in_h2    = $this->keyword_in_array( $keyword, $p['h2'] );
                $in_body  = mb_stripos( $p['body_text_excerpt'] ?? '', $keyword ) !== false;

                // rank_results の found_url と一致するか
                $is_ranking_url = false;
                if ( $found_url ) {
                    $found_path = untrailingslashit( wp_parse_url( $found_url, PHP_URL_PATH ) ?: '/' );
                    $page_path  = untrailingslashit( wp_parse_url( $p['url'], PHP_URL_PATH ) ?: '/' );
                    $is_ranking_url = ( $found_path === $page_path );
                }

                if ( $in_title || $in_desc || $in_h1 || $in_h2 || $in_body || $is_ranking_url ) {
                    $placements = [];
                    if ( $in_title ) { $placements[] = 'title'; }
                    if ( $in_desc )  { $placements[] = 'description'; }
                    if ( $in_h1 )    { $placements[] = 'h1'; }
                    if ( $in_h2 )    { $placements[] = 'h2'; }
                    if ( $in_body )  { $placements[] = 'body'; }
                    if ( $is_ranking_url ) { $placements[] = 'ranking_url'; }

                    $matches[] = [
                        'url'            => $p['url'],
                        'url_path'       => $this->url_path( $p['url'] ),
                        'placements'     => $placements,
                        'is_ranking_url' => $is_ranking_url,
                    ];
                }
            }

            $result[] = [
                'keyword'    => $keyword,
                'found_url'  => $found_url,
                'matches'    => $matches,
                'matchCount' => count( $matches ),
                'hasTitle'   => $this->any_placement( $matches, 'title' ),
                'hasH1'      => $this->any_placement( $matches, 'h1' ),
                'hasDesc'    => $this->any_placement( $matches, 'description' ),
                'hasBody'    => $this->any_placement( $matches, 'body' ),
            ];
        }
        return $result;
    }

    private function keyword_in_array( string $keyword, array $texts ): bool {
        foreach ( $texts as $text ) {
            if ( mb_stripos( $text, $keyword ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private function any_placement( array $matches, string $placement ): bool {
        foreach ( $matches as $m ) {
            if ( in_array( $placement, $m['placements'], true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gemini AI によるキーワード×ページのコンテンツ関連性分析
     *
     * 全ページメタデータ + 全キーワードを1回の呼び出しで分析。
     * AI client が null またはエラー時は null を返す（ルールベース分析のみで動作）。
     */
    private function analyze_keyword_with_ai( array $keywords, array $page_results ): ?array {
        if ( ! $this->ai_client || empty( $keywords ) ) {
            return null;
        }

        // ページ情報を要約
        $page_summaries = [];
        foreach ( $page_results as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            $page_summaries[] = [
                'url'       => $this->url_path( $p['url'] ),
                'title'     => mb_substr( $p['title'], 0, 80 ),
                'desc'      => mb_substr( $p['meta_description'], 0, 160 ),
                'h1'        => implode( ' / ', array_slice( $p['h1'], 0, 3 ) ),
                'h2_sample' => implode( ' / ', array_slice( $p['h2'], 0, 5 ) ),
                'excerpt'   => mb_substr( $p['body_text_excerpt'] ?? '', 0, 300 ),
            ];
        }

        $kw_list    = array_map( function( $k ) { return $k['keyword']; }, $keywords );
        $pages_json = wp_json_encode( $page_summaries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        $kw_json    = wp_json_encode( $kw_list, JSON_UNESCAPED_UNICODE );

        $prompt = <<<PROMPT
あなたはSEOの専門家です。以下のウェブサイトのページ情報とターゲットキーワードを分析してください。

【ターゲットキーワード】
{$kw_json}

【ページ情報】
{$pages_json}

各キーワードについて以下を分析し、JSON配列で返してください。

分析観点:
1. そのキーワードに最も関連性の高いページ（URL）はどれか
2. そのページのタイトル・見出し・説明文にキーワードが適切に含まれているか
3. コンテンツの検索意図（情報収集型/比較検討型/行動型）との適合度
4. 検索意図に応えるために不足している要素（料金、事例、FAQ、地域情報、比較情報など）
5. 改善すべき具体的なポイント

出力形式（厳守）:
```json
[
  {
    "keyword": "キーワード名",
    "best_page": "/最も関連性の高いページのパス",
    "relevance": "high または medium または low",
    "intent_match": "検索意図との適合についての1文コメント",
    "missing_elements": "不足している要素についての1文コメント",
    "suggestions": [
      "改善提案1（具体的に）",
      "改善提案2（具体的に）"
    ]
  }
]
```

ルール:
- 各キーワードに対して必ず1つのエントリを返す
- best_pageはページ情報のurlから選ぶ。該当ページがない場合は空文字""
- suggestionsは最大3つまで
- 日本語で回答する
- JSON以外のテキストは出力しない
PROMPT;

        try {
            $raw = $this->ai_client->call_gemini_api( $prompt );
            // ```json フェンスを除去
            $raw = preg_replace( '/^```json\s*/s', '', $raw );
            $raw = preg_replace( '/\s*```\s*$/s', '', $raw );
            $raw = trim( $raw );
            $parsed = json_decode( $raw, true );
            if ( ! is_array( $parsed ) ) {
                file_put_contents( '/tmp/gcrev_seo_debug.log',
                    date( 'Y-m-d H:i:s' ) . " AI keyword analysis JSON parse failed: " . substr( $raw, 0, 500 ) . "\n",
                    FILE_APPEND
                );
                return null;
            }
            return $parsed;
        } catch ( \Throwable $e ) {
            file_put_contents( '/tmp/gcrev_seo_debug.log',
                date( 'Y-m-d H:i:s' ) . " AI keyword analysis error: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return null;
        }
    }

    /**
     * キーワード最適化スコアリング（9番目の診断次元）
     */
    private function score_keyword_optimization( array $keyword_coverage, ?array $ai_analysis ): array {
        if ( empty( $keyword_coverage ) ) {
            return [
                'key'          => 'keyword_optimization',
                'label'        => 'キーワード最適化',
                'score'        => 0,
                'maxScore'     => 0,
                'status'       => 'none',
                'findings'     => [ 'キーワードが登録されていません。順位トラッキングでキーワードを設定すると、キーワード最適化の診断が利用できます。' ],
                'affectedUrls' => [],
                'excluded'     => true,
            ];
        }

        $score    = 10;
        $findings = [];
        $affected = [];
        $kw_count = count( $keyword_coverage );

        $no_title = 0;
        $no_h1    = 0;
        $no_match = 0;

        foreach ( $keyword_coverage as $kc ) {
            if ( $kc['matchCount'] === 0 ) {
                $no_match++;
                $findings[] = "「{$kc['keyword']}」がサイト内のどのページにも見つかりませんでした";
            } else {
                if ( ! $kc['hasTitle'] ) { $no_title++; }
                if ( ! $kc['hasH1'] )    { $no_h1++; }
            }
        }

        if ( $no_match > 0 ) { $score -= min( $no_match * 3, 8 ); }
        if ( $no_title > 0 ) { $score -= min( $no_title * 1, 3 ); $findings[] = "titleタグに含まれていないキーワードが{$no_title}つあります"; }
        if ( $no_h1 > 0 )    { $score -= min( $no_h1 * 1, 2 );    $findings[] = "h1に含まれていないキーワードが{$no_h1}つあります"; }

        // AI 関連性チェック
        if ( $ai_analysis ) {
            $low_count = 0;
            foreach ( $ai_analysis as $a ) {
                if ( isset( $a['relevance'] ) && $a['relevance'] === 'low' ) {
                    $low_count++;
                }
            }
            if ( $low_count > 0 ) {
                $score -= min( $low_count * 2, 4 );
                $findings[] = "コンテンツの関連性が低いキーワードが{$low_count}つあります（AI分析）";
            }
        }

        if ( empty( $findings ) ) {
            $findings[] = "すべてのターゲットキーワード（{$kw_count}件）がサイト内で適切に使用されています";
        }

        $score = max( 0, $score );
        return [
            'key'          => 'keyword_optimization',
            'label'        => 'キーワード最適化',
            'score'        => $score,
            'maxScore'     => 10,
            'status'       => $this->status_from_score( $score ),
            'findings'     => $findings,
            'affectedUrls' => $affected,
        ];
    }

    /**
     * キーワード関連の問題一覧を生成
     */
    private function compute_keyword_issues( array $keyword_coverage, ?array $ai_analysis ): array {
        $issues = [];
        $ai_map = [];
        if ( $ai_analysis ) {
            foreach ( $ai_analysis as $a ) {
                $ai_map[ $a['keyword'] ] = $a;
            }
        }

        foreach ( $keyword_coverage as $kc ) {
            $kw = $kc['keyword'];

            if ( $kc['matchCount'] === 0 ) {
                $issues[] = [
                    'url'         => '（サイト全体）',
                    'statusCode'  => 200,
                    'issueType'   => 'キーワード最適化',
                    'issueDetail' => "「{$kw}」がサイト内のどのページにも見つかりません",
                    'priority'    => 'high',
                    'suggestion'  => "ターゲットキーワード「{$kw}」に対応するページを作成するか、既存ページのtitle・h1・本文にキーワードを含めてください。",
                ];
                continue;
            }

            $page_path = $kc['matches'][0]['url_path'] ?? '（不明）';

            if ( ! $kc['hasTitle'] ) {
                $issues[] = [
                    'url'         => $page_path,
                    'statusCode'  => 200,
                    'issueType'   => 'キーワード最適化',
                    'issueDetail' => "「{$kw}」がtitleタグに含まれていません",
                    'priority'    => 'medium',
                    'suggestion'  => "「{$kw}」をtitleタグに含めることで、検索結果での表示を改善できます。",
                ];
            }

            if ( ! $kc['hasH1'] ) {
                $issues[] = [
                    'url'         => $page_path,
                    'statusCode'  => 200,
                    'issueType'   => 'キーワード最適化',
                    'issueDetail' => "「{$kw}」がh1に含まれていません",
                    'priority'    => 'medium',
                    'suggestion'  => "「{$kw}」またはその関連語をh1見出しに含めることで、ページの主題を明確にできます。",
                ];
            }

            if ( ! $kc['hasDesc'] ) {
                $issues[] = [
                    'url'         => $page_path,
                    'statusCode'  => 200,
                    'issueType'   => 'キーワード最適化',
                    'issueDetail' => "「{$kw}」がmeta descriptionに含まれていません",
                    'priority'    => 'low',
                    'suggestion'  => "「{$kw}」をmeta descriptionに自然に含めることで、検索結果のクリック率向上が期待できます。",
                ];
            }

            // AI 分析の改善提案
            if ( isset( $ai_map[ $kw ] ) ) {
                $ai = $ai_map[ $kw ];
                if ( isset( $ai['relevance'] ) && $ai['relevance'] !== 'high' && ! empty( $ai['suggestions'] ) ) {
                    $best_page = ! empty( $ai['best_page'] ) ? $ai['best_page'] : $page_path;
                    $detail = "「{$kw}」のコンテンツ関連性が" . ( $ai['relevance'] === 'low' ? '低い' : 'やや不十分' ) . 'です';
                    if ( ! empty( $ai['missing_elements'] ) ) {
                        $detail .= '（' . $ai['missing_elements'] . '）';
                    }
                    $issues[] = [
                        'url'         => $best_page,
                        'statusCode'  => 200,
                        'issueType'   => 'キーワード最適化（AI分析）',
                        'issueDetail' => $detail,
                        'priority'    => $ai['relevance'] === 'low' ? 'high' : 'medium',
                        'suggestion'  => $ai['suggestions'][0],
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * キーワード関連の改善提案を生成
     */
    private function compute_keyword_recommendations( array $keyword_coverage, ?array $ai_analysis ): array {
        $recs   = [];
        $ai_map = [];
        if ( $ai_analysis ) {
            foreach ( $ai_analysis as $a ) {
                $ai_map[ $a['keyword'] ] = $a;
            }
        }

        foreach ( $keyword_coverage as $kc ) {
            $kw = $kc['keyword'];

            if ( $kc['matchCount'] === 0 ) {
                $recs[] = [
                    'title'       => "「{$kw}」の専用ページ作成",
                    'description' => "【問題】ターゲットキーワード「{$kw}」に対応するコンテンツがありません。【対策】「{$kw}」をテーマにした専用ページを作成し、title・h1・本文にキーワードを自然に含めてください。",
                    'priority'    => 'high',
                ];
                continue;
            }

            // AI 分析からの改善提案
            if ( isset( $ai_map[ $kw ] ) && ! empty( $ai_map[ $kw ]['suggestions'] ) ) {
                $ai = $ai_map[ $kw ];
                $desc_parts = [];
                foreach ( $ai['suggestions'] as $s ) {
                    $desc_parts[] = $s;
                }
                if ( ! empty( $ai['intent_match'] ) ) {
                    $desc_parts[] = '【検索意図】' . $ai['intent_match'];
                }
                if ( ! empty( $ai['missing_elements'] ) ) {
                    $desc_parts[] = '【不足要素】' . $ai['missing_elements'];
                }
                $priority = 'low';
                if ( isset( $ai['relevance'] ) ) {
                    if ( $ai['relevance'] === 'low' )    { $priority = 'high'; }
                    elseif ( $ai['relevance'] === 'medium' ) { $priority = 'medium'; }
                }
                $recs[] = [
                    'title'       => "「{$kw}」のSEO最適化",
                    'description' => implode( '。', $desc_parts ),
                    'priority'    => $priority,
                ];
            }
        }

        return $recs;
    }

    /* =========================================================
     * ユーティリティ
     * ========================================================= */

    /**
     * 総合スコア計算（excluded な次元はスキップ）
     */
    private function compute_total_score( array $scores ): int {
        if ( empty( $scores ) ) { return 0; }
        $sum     = 0;
        $max_sum = 0;
        foreach ( $scores as $s ) {
            if ( ! empty( $s['excluded'] ) ) { continue; }
            $sum     += $s['score'];
            $max_sum += $s['maxScore'];
        }
        if ( $max_sum === 0 ) { return 0; }
        return (int) round( ( $sum / $max_sum ) * 100 );
    }

    private function determine_rank( int $score ): string {
        if ( $score >= 80 ) { return 'A'; }
        if ( $score >= 60 ) { return 'B'; }
        if ( $score >= 40 ) { return 'C'; }
        return 'D';
    }

    private function status_from_score( int $score ): string {
        if ( $score >= 8 ) { return 'ok'; }
        if ( $score >= 5 ) { return 'caution'; }
        return 'critical';
    }

    private function url_path( string $url ): string {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        $path = $path ? urldecode( $path ) : '/';
        return $path;
    }

    /**
     * コンテンツ量チェックの除外対象ページか判定
     *
     * 確認ページ・送信完了ページ等はコンテンツが少なくて当然なので除外する。
     * URLパスの末尾スラッグを CONTENT_EXCLUDE_SLUGS と照合。
     */
    private function is_content_excluded_url( string $url ): bool {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! $path || $path === '/' ) {
            return false;
        }
        // 末尾スラッシュを除去してスラッグを取得
        $slug = basename( rtrim( $path, '/' ) );
        $slug = strtolower( $slug );

        foreach ( self::CONTENT_EXCLUDE_SLUGS as $pattern ) {
            if ( $slug === $pattern ) {
                return true;
            }
            // スラッグが pattern を含む場合も除外（例: my-thanks, contact-confirm-page）
            if ( strpos( $slug, $pattern ) !== false ) {
                return true;
            }
        }
        return false;
    }
}
