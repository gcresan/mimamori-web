<?php
// FILE: inc/gcrev-api/modules/class-seo-checker.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SEO診断サービス（MVP）
 *
 * sitemap.xml → クロール → HTML解析 → ルールベーススコアリング → 保存
 */
class Gcrev_SEO_Checker {

    /** user meta key */
    private const META_KEY = 'gcrev_seo_diagnosis';

    /** クロール上限 */
    private const MAX_PAGES = 20;

    /** フェッチ間隔（秒） */
    private const CRAWL_DELAY = 0.5;

    /** User-Agent */
    private const USER_AGENT = 'MimamoriSEO/1.0';

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
        $urls = array_slice( $urls, 0, self::MAX_PAGES );

        // 2. クロール & 解析
        $page_results = $this->crawl_and_analyze( $urls, $host );

        // 3. スコアリング
        $scores          = $this->compute_scores( $page_results );
        $total_score     = $this->compute_total_score( $scores );
        $rank            = $this->determine_rank( $total_score );
        $issues          = $this->compute_issues( $page_results );
        $recommendations = $this->compute_recommendations( $scores, $issues );
        $assessment      = $this->compute_overall_assessment( $scores, $total_score, $rank );

        $critical_count = 0;
        $warning_count  = 0;
        foreach ( $scores as $s ) {
            if ( $s['status'] === 'critical' ) { $critical_count++; }
            if ( $s['status'] === 'caution' )  { $warning_count++; }
        }

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y/m/d H:i' );

        $data = [
            'version'           => 1,
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
        ];

        $this->save_diagnosis( $user_id, $data );

        return $data;
    }

    /**
     * 保存済み診断結果を取得
     */
    public function get_diagnosis( int $user_id ): ?array {
        $raw = get_user_meta( $user_id, self::META_KEY, true );
        if ( ! $raw || ! is_string( $raw ) ) {
            return null;
        }
        $data = json_decode( $raw, true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * 診断結果を保存
     */
    public function save_diagnosis( int $user_id, array $data ): void {
        update_user_meta(
            $user_id,
            self::META_KEY,
            wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        );
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

        // sitemap index → 最初のサブsitemapを取得
        $doc->registerXPathNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
        $sitemaps = $doc->xpath( '//sm:sitemap/sm:loc' );
        if ( ! empty( $sitemaps ) ) {
            // sitemapインデックスの最初のサブsitemapを取得
            $sub_body = $this->fetch_body( (string) $sitemaps[0] );
            if ( $sub_body ) {
                return $this->parse_sitemap_xml( $sub_body, $site_url );
            }
            return [];
        }

        // 通常のsitemap → URL一覧
        $locs = $doc->xpath( '//sm:url/sm:loc' );
        if ( empty( $locs ) ) {
            // 名前空間なしで再試行
            $locs = $doc->xpath( '//url/loc' );
        }
        $urls = [];
        $host = wp_parse_url( $site_url, PHP_URL_HOST );
        foreach ( $locs as $loc ) {
            $u = trim( (string) $loc );
            if ( ! $u ) { continue; }
            $u_host = wp_parse_url( $u, PHP_URL_HOST );
            if ( $u_host && $u_host !== $host ) { continue; }
            // 除外パターン
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
            // 相対URL→絶対URL
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
                    'url'              => $url,
                    'status_code'      => $res['status'],
                    'title'            => '',
                    'meta_description' => '',
                    'h1'               => [],
                    'h2'               => [],
                    'images_total'     => 0,
                    'images_no_alt'    => 0,
                    'canonical'        => '',
                    'internal_links'   => 0,
                    'fetch_error'      => true,
                ];
            }
            $results[] = $analysis;
        }
        return $results;
    }

    private function fetch_page( string $url ): array {
        $res = wp_remote_get( $url, [
            'timeout'             => 15,
            'redirection'         => 3,
            'user-agent'          => self::USER_AGENT,
            'limit_response_size' => 1024000,
            'sslverify'           => false,
        ] );
        if ( is_wp_error( $res ) ) {
            return [ 'url' => $url, 'status' => 0, 'html' => '' ];
        }
        return [
            'url'    => $url,
            'status' => (int) wp_remote_retrieve_response_code( $res ),
            'html'   => wp_remote_retrieve_body( $res ),
        ];
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

        return [
            'url'              => $url,
            'status_code'      => $status_code,
            'title'            => $this->extract_title( $xpath ),
            'meta_description' => $this->extract_meta_description( $xpath ),
            'h1'               => $this->extract_headings( $xpath, 'h1' ),
            'h2'               => $this->extract_headings( $xpath, 'h2' ),
            'images_total'     => $this->count_images( $xpath ),
            'images_no_alt'    => $this->count_images_no_alt( $xpath ),
            'canonical'        => $this->extract_canonical( $xpath ),
            'internal_links'   => $this->count_internal_links( $xpath, $host ),
            'fetch_error'      => false,
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
        $safe_tag = preg_quote( $tag, '/' );
        $nodes    = $xpath->query( '//' . $tag );
        $result   = [];
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
        // alt属性が無い or 空の画像
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
                // 相対リンクは内部リンク
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
        return $scores;
    }

    private function score_title( array $pages ): array {
        $score    = 10;
        $findings = [];
        $affected = [];

        $titles       = [];
        $missing      = 0;
        $too_long     = 0;
        $too_short    = 0;

        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            $t = $p['title'];
            if ( $t === '' ) {
                $missing++;
                $affected[] = $this->url_path( $p['url'] );
            } else {
                $len = mb_strlen( $t );
                if ( $len > 60 ) { $too_long++; $affected[] = $this->url_path( $p['url'] ); }
                elseif ( $len < 15 ) { $too_short++; $affected[] = $this->url_path( $p['url'] ); }
                $titles[] = $t;
            }
        }

        // 重複チェック
        $dup_count = count( $titles ) - count( array_unique( $titles ) );

        if ( $missing > 0 )   { $score -= 3; $findings[] = "titleタグが未設定のページが{$missing}つあります"; }
        if ( $dup_count > 0 ) { $score -= 2; $findings[] = "重複したtitleが{$dup_count}件あります"; }
        if ( $too_long > 0 )  { $score -= 1; $findings[] = "長すぎるtitle（60文字超）が{$too_long}ページあります"; }
        if ( $too_short > 0 ) { $score -= 1; $findings[] = "短すぎるtitle（15文字未満）が{$too_short}ページあります"; }
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

        $descs    = [];
        $missing  = 0;
        $too_long = 0;
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
                elseif ( $len < 50 ) { $too_short++; $affected[] = $this->url_path( $p['url'] ); }
                $descs[] = $d;
            }
        }

        $dup_count = count( $descs ) - count( array_unique( $descs ) );

        if ( $missing > 0 )   { $score -= 3; $findings[] = "meta descriptionが未設定のページが{$missing}つあります"; }
        if ( $dup_count > 0 ) { $score -= 2; $findings[] = "重複したdescriptionが{$dup_count}件あります"; }
        if ( $too_long > 0 )  { $score -= 1; $findings[] = "長すぎるdescription（160文字超）が{$too_long}ページあります"; }
        if ( $too_short > 0 ) { $score -= 1; $findings[] = "短すぎるdescription（50文字未満）が{$too_short}ページあります"; }
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

        $no_h1      = 0;
        $multi_h1   = 0;
        $no_h2      = 0;

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
            }
            if ( $h2_count === 0 ) {
                $no_h2++;
            }
        }

        if ( $no_h1 > 0 )    { $score -= 3; $findings[] = "h1が未設定のページが{$no_h1}つあります"; }
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
        $score     = 10;
        $findings  = [];
        $affected  = [];
        $link_counts = [];
        $zero_link = 0;

        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            $link_counts[] = $p['internal_links'];
            if ( $p['internal_links'] === 0 ) {
                $zero_link++;
                $affected[] = $this->url_path( $p['url'] );
            }
        }

        $avg_links = count( $link_counts ) > 0 ? array_sum( $link_counts ) / count( $link_counts ) : 0;

        if ( $zero_link > 0 )  { $score -= 3; $findings[] = "内部リンクが0のページが{$zero_link}つあります（孤立ページ）"; }
        if ( $avg_links < 3 )  { $score -= 2; $findings[] = sprintf( '平均内部リンク数が少なめです（%.1f本/ページ）', $avg_links ); }
        if ( empty( $findings ) ) { $findings[] = sprintf( '内部リンクは適切に設定されています（平均%.1f本/ページ）', $avg_links ); }

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

        foreach ( $pages as $p ) {
            if ( ! empty( $p['fetch_error'] ) ) { continue; }
            if ( $p['canonical'] === '' ) {
                $missing++;
                $affected[] = $this->url_path( $p['url'] );
            }
        }

        if ( $missing > 0 ) { $score -= 3; $findings[] = "canonical タグが未設定のページが{$missing}つあります"; }
        if ( empty( $findings ) ) { $findings[] = 'すべてのページでcanonicalタグが設定されています'; }

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
                    'suggestion'  => 'ページが正常に表示されるよう修正してください',
                ];
            }

            if ( ! empty( $p['fetch_error'] ) ) { continue; }

            if ( $p['title'] === '' ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => 'タイトルタグ',
                    'issueDetail' => 'titleタグが未設定',
                    'priority'    => 'high',
                    'suggestion'  => 'ページの内容を表す30〜60文字のtitleを設定してください',
                ];
            } elseif ( mb_strlen( $p['title'] ) > 60 ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => 'タイトルタグ',
                    'issueDetail' => 'titleが長すぎます（' . mb_strlen( $p['title'] ) . '文字）',
                    'priority'    => 'low',
                    'suggestion'  => '60文字以内に収めると検索結果で省略されにくくなります',
                ];
            }

            if ( $p['meta_description'] === '' ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => 'メタディスクリプション',
                    'issueDetail' => 'meta descriptionが未設定',
                    'priority'    => 'high',
                    'suggestion'  => 'ページの内容を要約した70〜160文字のdescriptionを設定してください',
                ];
            }

            if ( count( $p['h1'] ) === 0 ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => '見出し構造',
                    'issueDetail' => 'h1が未設定',
                    'priority'    => 'medium',
                    'suggestion'  => 'ページの主題を表すh1見出しを1つ設定してください',
                ];
            } elseif ( count( $p['h1'] ) > 1 ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => '見出し構造',
                    'issueDetail' => 'h1が' . count( $p['h1'] ) . '個あります',
                    'priority'    => 'medium',
                    'suggestion'  => 'h1はページに1つだけにしてください',
                ];
            }

            if ( $p['images_no_alt'] > 0 ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => '画像alt属性',
                    'issueDetail' => "alt未設定の画像が{$p['images_no_alt']}つ",
                    'priority'    => 'medium',
                    'suggestion'  => '各画像に内容を説明するalt属性を追加してください',
                ];
            }

            if ( $p['canonical'] === '' ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => 'canonical',
                    'issueDetail' => 'canonicalタグが未設定',
                    'priority'    => 'low',
                    'suggestion'  => 'canonicalタグを設定して正規URLを明示してください',
                ];
            }

            if ( $p['internal_links'] === 0 ) {
                $issues[] = [
                    'url'         => $url_path,
                    'statusCode'  => $code,
                    'issueType'   => '内部リンク',
                    'issueDetail' => '内部リンクが0本（孤立ページ）',
                    'priority'    => 'medium',
                    'suggestion'  => '関連するページへの内部リンクを追加してください',
                ];
            }
        }

        // 重複title検出
        $this->add_duplicate_issues( $issues, $page_results, 'title', 'タイトルタグ', 'titleが他ページと重複', '各ページ固有のtitleに変更してください' );
        // 重複description検出
        $this->add_duplicate_issues( $issues, $page_results, 'meta_description', 'メタディスクリプション', 'descriptionが他ページと重複', '各ページ固有のdescriptionに変更してください' );

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
            // 最初のURL以外に重複issueを追加
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

        // スコアが低いカテゴリから改善提案を生成
        $sorted = $scores;
        usort( $sorted, function( $a, $b ) { return $a['score'] - $b['score']; } );

        $templates = [
            'title_tag'        => [ 'title' => 'タイトルタグの修正', 'desc' => 'タイトルの未設定・重複・長さの問題を修正してください。検索結果でのクリック率向上に直結します。' ],
            'meta_description' => [ 'title' => 'メタディスクリプションの設定・修正', 'desc' => '未設定のページにdescriptionを追加し、重複を解消してください。検索結果の説明文が改善されます。' ],
            'heading_structure' => [ 'title' => '見出し構造の整理', 'desc' => 'h1はページに1つ設定し、h2以下で内容を整理してください。検索エンジンがページ構造を理解しやすくなります。' ],
            'image_alt'        => [ 'title' => '画像alt属性の追加', 'desc' => 'alt属性が未設定の画像に説明テキストを追加してください。アクセシビリティとSEOの両方に効果があります。' ],
            'internal_links'   => [ 'title' => '内部リンクの強化', 'desc' => '孤立ページへのリンクを追加し、関連ページ間の導線を整えてください。サイト全体の評価向上につながります。' ],
            'canonical'        => [ 'title' => 'canonicalタグの設定', 'desc' => '未設定のページにcanonicalタグを追加して正規URLを明示してください。重複コンテンツの問題を防げます。' ],
            'status_code'      => [ 'title' => 'エラーページの修正', 'desc' => '200以外のステータスコードを返すページを修正してください。ユーザー体験と検索エンジンの評価に影響します。' ],
        ];

        foreach ( $sorted as $s ) {
            if ( $s['score'] >= 9 ) { continue; } // ほぼ満点のカテゴリはスキップ
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

        return $recs;
    }

    /* =========================================================
     * 全体評価
     * ========================================================= */

    private function compute_overall_assessment( array $scores, int $total_score, string $rank ): array {
        $good_points       = [];
        $improvement_points = [];

        foreach ( $scores as $s ) {
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
     * ユーティリティ
     * ========================================================= */

    private function compute_total_score( array $scores ): int {
        if ( empty( $scores ) ) { return 0; }
        $sum = 0;
        foreach ( $scores as $s ) {
            $sum += $s['score'];
        }
        return (int) round( ( $sum / ( count( $scores ) * 10 ) ) * 100 );
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
        return $path ?: '/';
    }
}
