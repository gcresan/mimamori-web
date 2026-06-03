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

    /** get_diagnosis() の結果キャッシュ TTL（24時間） */
    private const REPORT_CACHE_TTL = DAY_IN_SECONDS;

    /** Transient キー */
    private static function report_cache_key( int $user_id ): string {
        return 'gcrev_seo_report_' . $user_id;
    }

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
        $sitemap_urls = $this->fetch_sitemap( $site_url );
        $urls = $sitemap_urls;
        if ( empty( $urls ) ) {
            $urls = $this->discover_urls_from_homepage( $site_url, $host );
        }
        // トップページを必ず先頭に
        $site_url_normalized = trailingslashit( $site_url );
        $urls = array_unique( array_merge( [ $site_url_normalized ], $urls ) );

        // クライアント設定の解析対象URL条件 / 解析除外URL条件 を反映
        // /media/ などの除外配下を SEO／AIO 診断のクロール対象から外す。
        $urls = $this->apply_user_path_filters( $urls, $user_id, $site_url_normalized );

        $urls = array_slice( $urls, 0, self::MAX_PAGES );

        // 2. robots.txt（サイトレベル）
        $robots = $this->fetch_robots_txt( $site_url );

        // 3. クロール & 解析
        $page_results = $this->crawl_and_analyze( $urls, $host );

        // 4. AI判定（AI検索対応 + コンテンツ品質 + 構造化データ一致）— null ならルールベースで動作
        $ai = $this->analyze_aio_with_ai( $page_results );

        // 5. 6カテゴリを構築
        $site_info = [ 'sitemap_urls' => $sitemap_urls ];
        $built      = $this->build_categories( $page_results, $robots, $site_info, $ai );
        $categories = $built['categories'];
        $flat       = $built['flat'];

        // カテゴリ別スコア & 総合スコア（参考値 = カテゴリスコアの平均）
        $category_scores = [];
        $score_sum       = 0;
        foreach ( $categories as $c ) {
            $category_scores[] = [
                'key'    => $c['key'],
                'label'  => $c['label'],
                'score'  => $c['score'],
                'status' => $c['status'],
            ];
            $score_sum += $c['score'];
        }
        $total_score = count( $categories ) > 0 ? (int) round( $score_sum / count( $categories ) ) : 0;
        $rank        = $this->determine_rank( $total_score );

        // 致命的 / 要改善 件数（項目ステータスから集計）
        $critical_count = 0;
        $warning_count  = 0;
        foreach ( $flat as $it ) {
            if ( ( $it['status'] ?? '' ) === 'critical' ) { $critical_count++; }
            elseif ( ( $it['status'] ?? '' ) === 'caution' ) { $warning_count++; }
        }

        // 6. キーワード最適化分析（独立セクション）
        $keywords         = $this->fetch_user_keywords( $user_id );
        $keyword_coverage = [];
        $ai_analysis      = null;
        if ( ! empty( $keywords ) ) {
            $keyword_coverage = $this->analyze_keyword_coverage( $keywords, $page_results );
            $ai_analysis      = $this->analyze_keyword_with_ai( $keywords, $page_results );
        }

        // 7. 問題一覧（ページ別 + キーワード）
        $issues = $this->compute_issues( $page_results );
        if ( ! empty( $keyword_coverage ) ) {
            $kw_issues = $this->compute_keyword_issues( $keyword_coverage, $ai_analysis );
            $issues    = array_merge( $issues, $kw_issues );
            $priority_order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
            usort( $issues, function( $a, $b ) use ( $priority_order ) {
                return ( $priority_order[ $a['priority'] ] ?? 9 ) - ( $priority_order[ $b['priority'] ] ?? 9 );
            } );
        }

        // 7b. 全issueにページタイトルを付与
        $title_map = [];
        foreach ( $page_results as $p ) {
            $title_map[ $this->url_path( $p['url'] ) ] = $p['title'] ?? '';
        }
        foreach ( $issues as &$_issue ) {
            $_issue['pageTitle'] = $title_map[ $_issue['url'] ] ?? '';
        }
        unset( $_issue );

        // 8. 改善提案（カテゴリ項目 + キーワード）
        $recommendations = $this->build_recommendations( $flat );
        if ( ! empty( $keyword_coverage ) ) {
            $kw_recs = $this->compute_keyword_recommendations( $keyword_coverage, $ai_analysis );
            $recommendations = array_merge( $kw_recs, $recommendations );
            $priority_order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
            usort( $recommendations, function( $a, $b ) use ( $priority_order ) {
                return ( $priority_order[ $a['priority'] ] ?? 9 ) - ( $priority_order[ $b['priority'] ] ?? 9 );
            } );
        }

        // 9. 全体評価
        $assessment = $this->compute_overall_assessment( $categories, $flat, $total_score );

        $now = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y/m/d H:i' );

        $data = [
            'version'           => 3,
            'updated_at'        => $now,
            'site_url'          => $site_url,
            'aiEnabled'         => $ai !== null,
            'siteSummary'       => [
                'totalScore'     => $total_score,
                'rank'           => $rank,
                'criticalCount'  => $critical_count,
                'warningCount'   => $warning_count,
                'pageCount'      => count( $page_results ),
                'lastCheckedAt'  => $now,
                'categoryScores' => $category_scores,
            ],
            'categories'        => $categories,
            // 後方互換 + execution-service 用のフラット項目配列
            'seoChecks'         => array_values( $flat ),
            'overallAssessment' => $assessment,
            'issuePages'        => $issues,
            'recommendations'   => $recommendations,
            'keywordAnalysis'   => [
                'keywords'     => $keyword_coverage,
                'aiAnalysis'   => $ai_analysis,
                'keywordCount' => count( $keywords ),
            ],
            'disclaimer'        => '本診断は、検索エンジンやAI検索がページ内容を理解しやすくするための改善ポイントを確認するものです。Google AI OverviewやChatGPT等での表示・引用を保証するものではありません。',
        ];

        $this->save_diagnosis( $user_id, $data );

        return $data;
    }

    /**
     * 保存済み診断結果を取得（比較データ付き）
     *
     * 重い user_meta blob（履歴最大10件）を毎回読まないよう、
     * 結果を Transient にキャッシュする。診断実行時に無効化される。
     */
    public function get_diagnosis( int $user_id ): ?array {
        $cache_key = self::report_cache_key( $user_id );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        if ( $cached === 'EMPTY' ) {
            return null;
        }

        $result = $this->load_diagnosis_uncached( $user_id );
        if ( is_array( $result ) ) {
            set_transient( $cache_key, $result, self::REPORT_CACHE_TTL );
        } else {
            // 未診断状態を短めにキャッシュ（クロール中の連続アクセス対策）
            set_transient( $cache_key, 'EMPTY', 5 * MINUTE_IN_SECONDS );
        }
        return $result;
    }

    /**
     * user_meta から実際にデータを読む処理本体（キャッシュなし）
     */
    private function load_diagnosis_uncached( int $user_id ): ?array {
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

        // 結果キャッシュを無効化（次回 get_diagnosis() で再構築）
        delete_transient( self::report_cache_key( $user_id ) );

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
        // 旧形式（categories を持たない）の前回データとは比較しない
        if ( ! $previous || empty( $previous['categories'] ) || empty( $current['categories'] ) ) {
            return null;
        }

        $cur_scores = [];
        foreach ( $current['categories'] as $c ) {
            $cur_scores[ $c['key'] ] = $c['score'];
        }
        $prev_scores = [];
        foreach ( $previous['categories'] as $c ) {
            $prev_scores[ $c['key'] ] = $c['score'];
        }

        $improved     = 0;
        $worsened     = 0;
        $per_category = [];
        foreach ( $cur_scores as $key => $score ) {
            $prev  = $prev_scores[ $key ] ?? null;
            $delta = ( $prev !== null ) ? $score - $prev : null;
            if ( $delta !== null && $delta > 0 ) { $improved++; }
            if ( $delta !== null && $delta < 0 ) { $worsened++; }
            $per_category[ $key ] = [
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
            'perCategory'     => $per_category,
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

    /**
     * robots.txt を取得して解析する（クロール・インデックス診断用）
     *
     * 戻り値:
     *   exists       … robots.txt が取得できたか
     *   sitemaps     … Sitemap: ディレクティブの URL 一覧
     *   blocks       … ['googlebot'=>bool, 'gptbot'=>bool, 'oai'=>bool]（トップ "/" がブロックされているか）
     *   raw_excerpt  … 先頭抜粋（デバッグ用）
     */
    private function fetch_robots_txt( string $site_url ): array {
        $robots_url = trailingslashit( $site_url ) . 'robots.txt';
        $res = wp_remote_get( $robots_url, [
            'timeout'             => 10,
            'redirection'         => 3,
            'user-agent'          => self::USER_AGENT,
            'limit_response_size' => 256000,
            'sslverify'           => false,
        ] );

        $result = [
            'exists'      => false,
            'sitemaps'    => [],
            'blocks'      => [ 'googlebot' => false, 'gptbot' => false, 'oai' => false ],
            'raw_excerpt' => '',
        ];

        if ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) {
            return $result;
        }
        $body = wp_remote_retrieve_body( $res );
        if ( $body === '' ) {
            return $result;
        }

        $result['exists']      = true;
        $result['raw_excerpt'] = mb_substr( $body, 0, 500 );

        // Sitemap ディレクティブ
        if ( preg_match_all( '/^\s*Sitemap:\s*(\S+)/im', $body, $m ) ) {
            $result['sitemaps'] = array_values( array_unique( $m[1] ) );
        }

        // User-agent ブロック単位で Disallow を判定
        $result['blocks']['googlebot'] = $this->robots_blocks_root( $body, [ 'googlebot', '*' ] );
        $result['blocks']['gptbot']    = $this->robots_blocks_root( $body, [ 'gptbot' ] );
        $result['blocks']['oai']       = $this->robots_blocks_root( $body, [ 'oai-searchbot' ] );

        return $result;
    }

    /**
     * robots.txt 本文で、指定 User-agent 群のいずれかがルート "/" を Disallow しているか判定。
     * "Disallow: /"（全面ブロック）のみを「ブロック」とみなす簡易判定。
     */
    private function robots_blocks_root( string $body, array $agents ): bool {
        $lines       = preg_split( '/\r\n|\r|\n/', $body );
        $applies     = false; // 現在のグループが対象 UA に該当するか
        $in_rules    = false; // 直前にルール行を見たか（グループ境界判定用）
        $blocked     = false;
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' || $line[0] === '#' ) { continue; }

            if ( preg_match( '/^User-agent:\s*(.+)$/i', $line, $mm ) ) {
                // ルール行のあとに現れた User-agent は新しいグループの開始
                if ( $in_rules ) { $applies = false; $in_rules = false; }
                $ua = strtolower( trim( $mm[1] ) );
                if ( in_array( $ua, $agents, true ) ) { $applies = true; }
                continue;
            }

            if ( preg_match( '/^Disallow:\s*(.*)$/i', $line, $mm ) ) {
                $in_rules = true;
                if ( $applies && trim( $mm[1] ) === '/' ) { $blocked = true; }
                continue;
            }
            if ( preg_match( '/^Allow:\s*(.*)$/i', $line, $mm ) ) {
                $in_rules = true;
                if ( $applies && trim( $mm[1] ) === '/' ) { $blocked = false; }
                continue;
            }
            $in_rules = true; // Crawl-delay 等もグループ内ルールとして扱う
        }
        return $blocked;
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
                    'h3'                => [],
                    'images_total'      => 0,
                    'images_no_alt'     => 0,
                    'canonical'         => '',
                    'internal_links'    => 0,
                    'body_text_length'  => 0,
                    'body_text_excerpt' => '',
                    'noindex'           => false,
                    'og'                => [],
                    'twitter'           => [],
                    'meta_author'       => '',
                    'jsonld_types'      => [],
                    'jsonld_count'      => 0,
                    'jsonld_invalid'    => 0,
                    'script_count'      => 0,
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
        $jsonld    = $this->extract_jsonld( $xpath );

        return [
            'url'               => $url,
            'status_code'       => $status_code,
            'title'             => $this->extract_title( $xpath ),
            'meta_description'  => $this->extract_meta_description( $xpath ),
            'h1'                => $this->extract_headings( $xpath, 'h1' ),
            'h2'                => $this->extract_headings( $xpath, 'h2' ),
            'h3'                => $this->extract_headings( $xpath, 'h3' ),
            'images_total'      => $this->count_images( $xpath ),
            'images_no_alt'     => $this->count_images_no_alt( $xpath ),
            'canonical'         => $this->extract_canonical( $xpath ),
            'internal_links'    => $this->count_internal_links( $xpath, $host ),
            'body_text_length'  => mb_strlen( $body_text ),
            // キーワード出現チェックに使うため、本文抜粋は十分な長さを確保する
            // （500字だとページ下部のキーワードを取りこぼす）
            'body_text_excerpt' => mb_substr( $body_text, 0, 3000 ),
            // --- SEO／AIO 拡張シグナル ---
            'noindex'           => $this->extract_noindex( $xpath ),
            'og'                => $this->extract_og_tags( $xpath ),
            'twitter'           => $this->extract_twitter_tags( $xpath ),
            'meta_author'       => $this->extract_meta_author( $xpath ),
            'jsonld_types'      => $jsonld['types'],
            'jsonld_count'      => $jsonld['count'],
            'jsonld_invalid'    => $jsonld['invalid'],
            'script_count'      => $this->count_scripts( $xpath ),
            'fetch_error'       => false,
        ];
    }

    /* ---------------------------------------------------------
     * SEO／AIO 拡張シグナル抽出
     * ------------------------------------------------------- */

    /** meta robots / googlebot に noindex があるか */
    private function extract_noindex( \DOMXPath $xpath ): bool {
        $nodes = $xpath->query( '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots" or translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="googlebot"]/@content' );
        if ( $nodes ) {
            foreach ( $nodes as $n ) {
                if ( stripos( (string) $n->nodeValue, 'noindex' ) !== false ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** OGP（og:*）タグを連想配列で取得 */
    private function extract_og_tags( \DOMXPath $xpath ): array {
        $keys = [ 'og:title', 'og:description', 'og:image', 'og:site_name', 'og:locale', 'og:image:width', 'og:image:height', 'og:type', 'og:url' ];
        $out  = [];
        foreach ( $keys as $k ) {
            $nodes = $xpath->query( '//meta[@property="' . $k . '"]/@content' );
            $out[ $k ] = ( $nodes && $nodes->length > 0 ) ? trim( (string) $nodes->item( 0 )->nodeValue ) : '';
        }
        return $out;
    }

    /** Twitter Card（twitter:*）タグを連想配列で取得 */
    private function extract_twitter_tags( \DOMXPath $xpath ): array {
        $keys = [ 'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image' ];
        $out  = [];
        foreach ( $keys as $k ) {
            // twitter card は name 属性が標準だが property を使うサイトもあるため両対応
            $nodes = $xpath->query( '//meta[@name="' . $k . '" or @property="' . $k . '"]/@content' );
            $out[ $k ] = ( $nodes && $nodes->length > 0 ) ? trim( (string) $nodes->item( 0 )->nodeValue ) : '';
        }
        return $out;
    }

    /** meta author を取得 */
    private function extract_meta_author( \DOMXPath $xpath ): string {
        $nodes = $xpath->query( '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="author"]/@content' );
        if ( $nodes && $nodes->length > 0 ) {
            return trim( (string) $nodes->item( 0 )->nodeValue );
        }
        return '';
    }

    /** script タグ数（JS依存度の簡易指標） */
    private function count_scripts( \DOMXPath $xpath ): int {
        $nodes = $xpath->query( '//script' );
        return $nodes ? $nodes->length : 0;
    }

    /**
     * JSON-LD 構造化データを抽出して @type 一覧・件数・壊れた件数を返す
     */
    private function extract_jsonld( \DOMXPath $xpath ): array {
        $nodes   = $xpath->query( '//script[@type="application/ld+json"]' );
        $types   = [];
        $count   = 0;
        $invalid = 0;
        if ( $nodes ) {
            foreach ( $nodes as $n ) {
                $count++;
                $raw     = trim( $n->textContent );
                $decoded = json_decode( $raw, true );
                if ( $decoded === null && json_last_error() !== JSON_ERROR_NONE ) {
                    $invalid++;
                    continue;
                }
                $this->collect_jsonld_types( $decoded, $types );
            }
        }
        return [
            'types'   => array_values( array_unique( $types ) ),
            'count'   => $count,
            'invalid' => $invalid,
        ];
    }

    /** JSON-LD（入れ子・@graph 対応）から @type を再帰収集 */
    private function collect_jsonld_types( $node, array &$types ): void {
        if ( is_array( $node ) ) {
            // @type は文字列または配列
            if ( isset( $node['@type'] ) ) {
                foreach ( (array) $node['@type'] as $t ) {
                    if ( is_string( $t ) && $t !== '' ) { $types[] = $t; }
                }
            }
            foreach ( $node as $v ) {
                if ( is_array( $v ) ) {
                    $this->collect_jsonld_types( $v, $types );
                }
            }
        }
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

    /** 6カテゴリの定義（表示順・ラベル・説明） */
    private const CATEGORY_DEFS = [
        'seo_basic'   => [ 'label' => 'SEO基本設定',          'desc' => '検索結果に表示される基本情報や、ページ構造が適切に設定されているかを確認します。' ],
        'ogp'         => [ 'label' => 'OGP・SNS表示',          'desc' => 'SNS共有時の見え方や、外部サービスがページ情報を取得しやすい状態かを確認します。' ],
        'structured'  => [ 'label' => '構造化データ',          'desc' => '検索エンジンやAIが会社情報・サービス内容・ページ内容を理解しやすい形式で設定されているかを確認します。' ],
        'ai_search'   => [ 'label' => 'AI検索対応',            'desc' => 'AI検索でページ内容が正しく理解・要約・引用されやすい情報設計になっているかを確認します。' ],
        'crawl_index' => [ 'label' => 'クロール・インデックス', 'desc' => '検索エンジンやAI検索クローラーがページを取得・認識できる状態かを確認します。' ],
        'content'     => [ 'label' => 'コンテンツ品質',        'desc' => 'ユーザーとAIの両方にとって、ページ内容が具体的で信頼できる情報になっているかを確認します。' ],
    ];

    /** 重要度の重み（カテゴリスコア算出用） */
    private const IMPORTANCE_WEIGHT = [ 'high' => 3, 'medium' => 2, 'low' => 1 ];

    /**
     * 6カテゴリを構築する。
     *
     * @return array{categories: array, flat: array} categories=表示用、flat=後方互換のフラット項目配列
     */
    private function build_categories( array $pages, array $robots, array $site_info, ?array $ai ): array {
        $items_by_cat = [
            'seo_basic'   => $this->build_seo_basic_items( $pages ),
            'ogp'         => $this->build_ogp_items( $pages ),
            'structured'  => $this->build_structured_items( $pages, $ai ),
            'ai_search'   => $this->build_ai_search_items( $pages, $ai ),
            'crawl_index' => $this->build_crawl_items( $pages, $robots, $site_info ),
            'content'     => $this->build_content_items( $pages, $ai ),
        ];

        $categories = [];
        $flat       = [];
        foreach ( self::CATEGORY_DEFS as $key => $def ) {
            $items = $items_by_cat[ $key ] ?? [];
            $score = $this->category_score( $items );
            $categories[] = [
                'key'    => $key,
                'label'  => $def['label'],
                'desc'   => $def['desc'],
                'score'  => $score,
                'status' => $this->status_from_percent( $score ),
                'items'  => array_values( $items ),
            ];
            foreach ( $items as $it ) {
                // execution-service / 後方互換用のフラット配列（label/status/score を保持）
                $flat[] = [
                    'key'          => $it['key'],
                    'label'        => $it['label'],
                    'name'         => $it['label'],
                    'category'     => $def['label'],
                    'categoryKey'  => $key,
                    'score'        => $it['score'],
                    'maxScore'     => $it['maxScore'],
                    'status'       => $it['status'],
                    'state'        => $it['state'],
                    'importance'   => $it['importance'],
                    'findings'     => array_values( array_filter( [ $it['currentState'] ] ) ),
                    'affectedUrls' => $it['affectedUrls'],
                ];
            }
        }
        return [ 'categories' => $categories, 'flat' => $flat ];
    }

    /**
     * 1診断項目を組み立てる共通ヘルパー。
     *
     * @param string $state good=良好 / caution=要改善 / none=未設定
     */
    private function make_item( string $key, string $label, string $state, string $importance, string $current, string $reason, string $suggestion, string $fix = '', array $affected = [] ): array {
        $state = in_array( $state, [ 'good', 'caution', 'none' ], true ) ? $state : 'caution';
        $score = $state === 'good' ? 10 : ( $state === 'caution' ? 5 : 0 );
        // 後方互換ステータス（ok/caution/critical）— 未設定は重要度が高ければ critical
        if ( $state === 'good' ) {
            $status = 'ok';
        } elseif ( $state === 'caution' ) {
            $status = 'caution';
        } else {
            $status = ( $importance === 'high' ) ? 'critical' : 'caution';
        }
        return [
            'key'          => $key,
            'label'        => $label,
            'state'        => $state,
            'importance'   => $importance,
            'currentState' => $current,
            'reason'       => $reason,
            'suggestion'   => $suggestion,
            'fixExample'   => $fix,
            'affectedUrls' => array_values( array_unique( $affected ) ),
            'status'       => $status,
            'score'        => $score,
            'maxScore'     => 10,
        ];
    }

    /** 重要度で重み付けしたカテゴリスコア（0〜100） */
    private function category_score( array $items ): int {
        if ( empty( $items ) ) { return 0; }
        $w_sum = 0;
        $s_sum = 0.0;
        foreach ( $items as $it ) {
            $w = self::IMPORTANCE_WEIGHT[ $it['importance'] ] ?? 1;
            $w_sum += $w;
            $s_sum += $w * ( $it['score'] / 10 );
        }
        if ( $w_sum === 0 ) { return 0; }
        return (int) round( ( $s_sum / $w_sum ) * 100 );
    }

    private function status_from_percent( int $pct ): string {
        if ( $pct >= 80 ) { return 'ok'; }
        if ( $pct >= 50 ) { return 'caution'; }
        return 'critical';
    }

    /** fetch_error でないページのみ */
    private function valid_pages( array $pages ): array {
        return array_values( array_filter( $pages, static function ( $p ) {
            return empty( $p['fetch_error'] );
        } ) );
    }

    /** 条件に一致したページの URL パス一覧 */
    private function urls_where( array $pages, callable $cond ): array {
        $out = [];
        foreach ( $pages as $p ) {
            if ( $cond( $p ) ) { $out[] = $this->url_path( $p['url'] ); }
        }
        return $out;
    }

    /** トップページ（パスが "/" のページ。無ければ先頭） */
    private function homepage_page( array $pages ): ?array {
        foreach ( $pages as $p ) {
            $path = wp_parse_url( $p['url'], PHP_URL_PATH );
            if ( $path === null || $path === '' || $path === '/' ) { return $p; }
        }
        return $pages[0] ?? null;
    }

    /** いずれかのページのテキスト（title/desc/見出し/本文抜粋）に needle 群を含むか */
    private function any_page_contains( array $pages, array $needles ): bool {
        foreach ( $pages as $p ) {
            $hay = ( $p['title'] ?? '' ) . ' '
                 . ( $p['meta_description'] ?? '' ) . ' '
                 . implode( ' ', $p['h1'] ?? [] ) . ' '
                 . implode( ' ', $p['h2'] ?? [] ) . ' '
                 . implode( ' ', $p['h3'] ?? [] ) . ' '
                 . ( $p['body_text_excerpt'] ?? '' );
            foreach ( $needles as $n ) {
                if ( mb_stripos( $hay, $n ) !== false ) { return true; }
            }
        }
        return false;
    }

    /**
     * AI判定（あれば）を優先し、無ければルールベースのフォールバックで項目を作る。
     *
     * @param array  $fallback ['state','current','reason','suggestion','fix']
     */
    private function ai_item( ?array $ai, string $group, string $key, string $label, string $importance, array $fallback ): array {
        $a = ( is_array( $ai ) && isset( $ai[ $group ][ $key ] ) && is_array( $ai[ $group ][ $key ] ) )
            ? $ai[ $group ][ $key ] : null;
        if ( $a && ! empty( $a['state'] ) ) {
            return $this->make_item(
                $key, $label,
                (string) $a['state'], $importance,
                (string) ( $a['currentState'] ?? $fallback['current'] ?? '' ),
                (string) ( $a['reason']       ?? $fallback['reason'] ?? '' ),
                (string) ( $a['suggestion']   ?? $fallback['suggestion'] ?? '' ),
                (string) ( $a['fixExample']   ?? $fallback['fix'] ?? '' ),
                []
            );
        }
        return $this->make_item(
            $key, $label,
            (string) ( $fallback['state'] ?? 'caution' ), $importance,
            (string) ( $fallback['current'] ?? '' ),
            (string) ( $fallback['reason'] ?? '' ),
            (string) ( $fallback['suggestion'] ?? '' ),
            (string) ( $fallback['fix'] ?? '' ),
            []
        );
    }

    /* ---------------------------------------------------------
     * カテゴリ1: SEO基本設定
     * ------------------------------------------------------- */
    private function build_seo_basic_items( array $pages ): array {
        $valid = $this->valid_pages( $pages );
        $total = max( 1, count( $valid ) );
        $items = [];

        // title 有無
        $miss = $this->urls_where( $valid, static function ( $p ) { return $p['title'] === ''; } );
        $items[] = $this->make_item(
            'title_presence', 'titleタグの有無',
            empty( $miss ) ? 'good' : 'none', 'high',
            empty( $miss ) ? "全{$total}ページでtitleタグが設定されています。" : count( $miss ) . "ページでtitleタグが未設定です。",
            'titleはGoogle検索結果やAI検索の見出しになり、ページ内容を伝える最重要要素です。',
            '各ページに、内容を表す固有のtitleを設定してください。',
            empty( $miss ) ? '' : '<title>みまもり歯科クリニック｜松山市の予防歯科・小児歯科</title>',
            $miss
        );

        // title 文字数
        $len_bad = $this->urls_where( $valid, static function ( $p ) {
            if ( $p['title'] === '' ) { return false; }
            $l = mb_strlen( $p['title'] );
            return $l > 60 || $l < 30;
        } );
        $items[] = $this->make_item(
            'title_length', 'titleタグの文字数',
            empty( $len_bad ) ? 'good' : 'caution', 'low',
            empty( $len_bad ) ? 'titleの文字数は適切です（30〜60文字）。' : count( $len_bad ) . 'ページでtitleが長すぎる（60文字超）か短すぎ（30文字未満）です。',
            '60文字を超えると検索結果で末尾が省略され、短すぎると訴求力が弱まります。',
            '重要なキーワードを前半に置き、30〜60文字に収めてください。',
            '',
            $len_bad
        );

        // meta description 有無
        $miss = $this->urls_where( $valid, static function ( $p ) { return $p['meta_description'] === ''; } );
        $items[] = $this->make_item(
            'desc_presence', 'meta descriptionの有無',
            empty( $miss ) ? 'good' : 'none', 'high',
            empty( $miss ) ? "全{$total}ページでmeta descriptionが設定されています。" : count( $miss ) . 'ページでmeta descriptionが未設定です。',
            'descriptionは検索結果やSNSの説明文として表示され、クリック率に影響します。AI検索でも内容把握の手がかりになります。',
            '各ページに70〜120文字程度の固有の説明文を設定してください。',
            empty( $miss ) ? '' : '<meta name="description" content="松山市の予防歯科・小児歯科。土曜診療・キッズスペース完備。痛みの少ない治療を心がけています。初診のご相談はお気軽にどうぞ。">',
            $miss
        );

        // meta description 文字数
        $len_bad = $this->urls_where( $valid, static function ( $p ) {
            if ( $p['meta_description'] === '' ) { return false; }
            $l = mb_strlen( $p['meta_description'] );
            return $l > 160 || $l < 70;
        } );
        $items[] = $this->make_item(
            'desc_length', 'meta descriptionの文字数',
            empty( $len_bad ) ? 'good' : 'caution', 'low',
            empty( $len_bad ) ? 'descriptionの文字数は適切です（70〜160文字）。' : count( $len_bad ) . 'ページでdescriptionが長すぎる（160文字超）か短すぎ（70文字未満）です。',
            '160文字を超えると検索結果で省略され、短すぎると表示面積が小さくなります。',
            '重要な情報を前半に置き、70〜160文字に収めてください。',
            '',
            $len_bad
        );

        // h1 有無
        $miss = $this->urls_where( $valid, static function ( $p ) { return count( $p['h1'] ) === 0; } );
        $items[] = $this->make_item(
            'h1_presence', 'h1タグの有無',
            empty( $miss ) ? 'good' : 'none', 'medium',
            empty( $miss ) ? '全ページにh1が設定されています。' : count( $miss ) . 'ページでh1が未設定です。',
            'h1はページの主題を検索エンジンとAIに伝える見出しです。',
            '各ページにページの主題を表すh1を1つ設定してください。',
            empty( $miss ) ? '' : '<h1>松山市の予防歯科・小児歯科 みまもり歯科クリニック</h1>',
            $miss
        );

        // h1 単一
        $multi = $this->urls_where( $valid, static function ( $p ) { return count( $p['h1'] ) > 1; } );
        $items[] = $this->make_item(
            'h1_single', 'h1タグが複数存在しないか',
            empty( $multi ) ? 'good' : 'caution', 'medium',
            empty( $multi ) ? 'h1の重複はありません。' : count( $multi ) . 'ページでh1が複数あります。',
            'h1が複数あるとページの主題が曖昧になります。',
            '最も重要な見出し1つだけをh1にし、他はh2以下に変更してください。',
            '',
            $multi
        );

        // h2 有無
        $miss = $this->urls_where( $valid, static function ( $p ) { return count( $p['h2'] ) === 0; } );
        $items[] = $this->make_item(
            'h2_presence', 'h2見出しの有無',
            empty( $miss ) ? 'good' : 'caution', 'low',
            empty( $miss ) ? '各ページでh2見出しが使われています。' : count( $miss ) . 'ページでh2見出しがありません。',
            'h2はページ内容を階層的に整理し、見出しだけで内容を把握しやすくします（AI検索でも有効）。',
            '本文の段落構成に合わせてh2見出しを設定してください。',
            '',
            $miss
        );

        // canonical 有無
        $miss = $this->urls_where( $valid, static function ( $p ) { return $p['canonical'] === ''; } );
        $items[] = $this->make_item(
            'canonical_presence', 'canonicalタグの有無',
            empty( $miss ) ? 'good' : 'caution', 'medium',
            empty( $miss ) ? '全ページにcanonicalが設定されています。' : count( $miss ) . 'ページでcanonicalが未設定です。',
            'canonicalがないとURLパラメータ違いで重複コンテンツと判定されるリスクがあります。',
            '各ページに自身の正規URLをcanonicalとして設定してください。',
            empty( $miss ) ? '' : '<link rel="canonical" href="https://example.com/service/">',
            $miss
        );

        // noindex 有無（SEO基本設定の観点）
        $noidx = $this->urls_where( $valid, static function ( $p ) { return ! empty( $p['noindex'] ); } );
        $items[] = $this->make_item(
            'noindex_check', 'noindexの有無',
            empty( $noidx ) ? 'good' : 'caution', 'medium',
            empty( $noidx ) ? 'インデックス対象ページにnoindexは設定されていません。' : count( $noidx ) . 'ページにnoindexが設定されています。',
            'noindexが付いたページは検索結果やAI検索に表示されません。意図しないページに付いていると集客機会を失います。',
            '公開すべきページにnoindexが付いていないか確認し、不要なら外してください。',
            '',
            $noidx
        );

        // 画像alt
        $no_alt = $this->urls_where( $valid, static function ( $p ) { return ( $p['images_no_alt'] ?? 0 ) > 0; } );
        $items[] = $this->make_item(
            'image_alt', '画像altの有無',
            empty( $no_alt ) ? 'good' : 'caution', 'medium',
            empty( $no_alt ) ? 'すべての画像にalt属性が設定されています。' : count( $no_alt ) . 'ページにalt未設定の画像があります。',
            'altは画像の内容を検索エンジン・AI・スクリーンリーダーに伝えます。',
            '各画像に内容を説明するalt属性を追加してください（装飾画像は alt="" で明示）。',
            empty( $no_alt ) ? '' : '<img src="clinic.jpg" alt="みまもり歯科クリニックの診察室">',
            $no_alt
        );

        // 内部リンク
        $no_link = $this->urls_where( $valid, static function ( $p ) { return ( $p['internal_links'] ?? 0 ) === 0; } );
        $items[] = $this->make_item(
            'internal_links', '内部リンクの有無',
            empty( $no_link ) ? 'good' : 'caution', 'medium',
            empty( $no_link ) ? '各ページに内部リンクが設定されています。' : count( $no_link ) . 'ページが内部リンク0本（孤立ページ）です。',
            '内部リンクはサイト内の回遊性とページ評価の分配、クローラーの発見性に影響します。',
            '孤立ページへのリンクを関連ページから追加してください。',
            '',
            $no_link
        );

        return $items;
    }

    /* ---------------------------------------------------------
     * カテゴリ2: OGP・SNS表示
     * ------------------------------------------------------- */
    private function build_ogp_items( array $pages ): array {
        $valid = $this->valid_pages( $pages );
        $items = [];

        // 各 OGP / Twitter タグの定義: [key, label, importance, fix例]
        $og_defs = [
            [ 'og:title',        'og_title',        'og:title',        'medium', '<meta property="og:title" content="松山市の予防歯科 みまもり歯科クリニック">' ],
            [ 'og:description',  'og_description',  'og:description',  'medium', '<meta property="og:description" content="土曜診療・キッズスペース完備。痛みの少ない治療を心がけています。">' ],
            [ 'og:image',        'og_image',        'og:image',        'medium', '<meta property="og:image" content="https://example.com/ogp.jpg">' ],
            [ 'og:site_name',    'og_site_name',    'og:site_name',    'low',    '<meta property="og:site_name" content="みまもり歯科クリニック">' ],
            [ 'og:locale',       'og_locale',       'og:locale',       'low',    '<meta property="og:locale" content="ja_JP">' ],
        ];

        foreach ( $og_defs as $d ) {
            list( $prop, $key, $label, $imp, $fix ) = $d;
            $miss = $this->urls_where( $valid, static function ( $p ) use ( $prop ) {
                return empty( $p['og'][ $prop ] );
            } );
            $all_miss = ( count( $miss ) === count( $valid ) && ! empty( $valid ) );
            $items[] = $this->make_item(
                $key, $label,
                empty( $miss ) ? 'good' : ( $all_miss ? 'none' : 'caution' ), $imp,
                empty( $miss ) ? "全ページで {$label} が設定されています。" : ( $all_miss ? "{$label} がどのページにも設定されていません。" : count( $miss ) . "ページで {$label} が未設定です。" ),
                'OGPタグはSNS共有時の表示や、外部サービス・AIがページ情報を取得する手がかりになります。',
                "{$label} を各ページに設定してください。",
                empty( $miss ) ? '' : $fix,
                $miss
            );
        }

        // og:image サイズ指定（width/height）
        $miss_size = $this->urls_where( $valid, static function ( $p ) {
            // og:image があるのにサイズ未指定のページ
            return ! empty( $p['og']['og:image'] ) && ( empty( $p['og']['og:image:width'] ) || empty( $p['og']['og:image:height'] ) );
        } );
        $has_image = $this->any_page_contains_og( $valid, 'og:image' );
        $items[] = $this->make_item(
            'og_image_size', 'og:imageサイズ指定（width/height）',
            ! $has_image ? 'none' : ( empty( $miss_size ) ? 'good' : 'caution' ), 'low',
            ! $has_image ? 'og:image自体が未設定です。' : ( empty( $miss_size ) ? 'og:imageにサイズ指定があります。' : count( $miss_size ) . 'ページでog:imageのwidth/heightが未指定です。' ),
            'og:image:width / height の指定はSNS側で画像を即座に正しいサイズで表示するのに役立ちます。',
            'og:image:width と og:image:height を指定してください。',
            empty( $miss_size ) && $has_image ? '' : '<meta property="og:image:width" content="1200">' . "\n" . '<meta property="og:image:height" content="630">',
            $miss_size
        );

        // Twitter Card 各種
        $tw_defs = [
            [ 'twitter:card',        'twitter_card',        'twitter:card',        'medium', '<meta name="twitter:card" content="summary_large_image">' ],
            [ 'twitter:title',       'twitter_title',       'twitter:title',       'low',    '<meta name="twitter:title" content="松山市の予防歯科 みまもり歯科クリニック">' ],
            [ 'twitter:description', 'twitter_description', 'twitter:description', 'low',    '<meta name="twitter:description" content="土曜診療・キッズスペース完備。">' ],
            [ 'twitter:image',       'twitter_image',       'twitter:image',       'low',    '<meta name="twitter:image" content="https://example.com/ogp.jpg">' ],
        ];
        foreach ( $tw_defs as $d ) {
            list( $prop, $key, $label, $imp, $fix ) = $d;
            $miss = $this->urls_where( $valid, static function ( $p ) use ( $prop ) {
                return empty( $p['twitter'][ $prop ] );
            } );
            $all_miss = ( count( $miss ) === count( $valid ) && ! empty( $valid ) );
            $items[] = $this->make_item(
                $key, $label,
                empty( $miss ) ? 'good' : ( $all_miss ? 'none' : 'caution' ), $imp,
                empty( $miss ) ? "全ページで {$label} が設定されています。" : ( $all_miss ? "{$label} がどのページにも設定されていません。" : count( $miss ) . "ページで {$label} が未設定です。" ),
                'Twitter Card（X）は共有時の表示形式を制御します。未設定でもOGPで代替されますが、明示が望ましいです。',
                "{$label} を設定してください。",
                empty( $miss ) ? '' : $fix,
                $miss
            );
        }

        // meta author
        $miss_author = $this->urls_where( $valid, static function ( $p ) { return ( $p['meta_author'] ?? '' ) === ''; } );
        $all_miss = ( count( $miss_author ) === count( $valid ) && ! empty( $valid ) );
        $items[] = $this->make_item(
            'meta_author', 'meta authorタグ',
            empty( $miss_author ) ? 'good' : ( $all_miss ? 'none' : 'caution' ), 'low',
            empty( $miss_author ) ? '全ページにmeta authorが設定されています。' : ( $all_miss ? 'meta authorがどのページにも設定されていません。' : count( $miss_author ) . 'ページでmeta authorが未設定です。' ),
            'meta authorは運営者・著者情報を示し、AI検索やコンテンツの信頼性評価の手がかりになります。',
            '運営者名・著者名をmeta authorに設定してください。',
            empty( $miss_author ) ? '' : '<meta name="author" content="みまもり歯科クリニック">',
            $miss_author
        );

        return $items;
    }

    /** いずれかのページに指定 og プロパティがあるか */
    private function any_page_contains_og( array $pages, string $prop ): bool {
        foreach ( $pages as $p ) {
            if ( ! empty( $p['og'][ $prop ] ) ) { return true; }
        }
        return false;
    }

    /* ---------------------------------------------------------
     * カテゴリ3: 構造化データ（JSON-LD）
     * ------------------------------------------------------- */
    private function build_structured_items( array $pages, ?array $ai ): array {
        $valid = $this->valid_pages( $pages );
        $items = [];

        // サイト全体で検出された @type 一覧と JSON-LD の有無・壊れ
        $all_types     = [];
        $has_jsonld    = false;
        $invalid_urls  = [];
        $no_jsonld_urls = [];
        foreach ( $valid as $p ) {
            $types = $p['jsonld_types'] ?? [];
            if ( ( $p['jsonld_count'] ?? 0 ) > 0 ) {
                $has_jsonld = true;
            } else {
                $no_jsonld_urls[] = $this->url_path( $p['url'] );
            }
            if ( ( $p['jsonld_invalid'] ?? 0 ) > 0 ) {
                $invalid_urls[] = $this->url_path( $p['url'] );
            }
            foreach ( $types as $t ) { $all_types[] = $t; }
        }
        $all_types = array_map( 'strtolower', $all_types );
        $has_type  = function ( $needle ) use ( $all_types ) {
            return in_array( strtolower( $needle ), $all_types, true );
        };

        // JSON-LD 有無
        $items[] = $this->make_item(
            'jsonld_presence', 'JSON-LDの有無',
            $has_jsonld ? 'good' : 'none', 'high',
            $has_jsonld ? 'JSON-LD形式の構造化データが検出されました。' : 'JSON-LD形式の構造化データが検出されませんでした。',
            '構造化データは検索エンジンやAIが会社情報・サービス・ページ内容を正確に理解する手がかりになります。',
            'トップ・会社概要・サービスページにJSON-LDを設置してください。',
            $has_jsonld ? '' : '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"みまもり歯科クリニック","url":"https://example.com/"}</script>',
            $no_jsonld_urls
        );

        // JSON 形式の妥当性
        $items[] = $this->make_item(
            'jsonld_valid', 'JSON形式として壊れていないか',
            empty( $invalid_urls ) ? 'good' : 'caution', 'high',
            empty( $invalid_urls ) ? '検出されたJSON-LDはすべて正しいJSON形式です。' : count( $invalid_urls ) . 'ページでJSON-LDの構文エラーが検出されました。',
            '壊れたJSON-LDは検索エンジンに無視され、構造化データの効果が得られません。',
            'JSON-LDの構文（カンマ・括弧・引用符）を修正し、Rich Results Test等で検証してください。',
            '',
            $invalid_urls
        );

        // 各スキーマ型の有無
        $schema_defs = [
            [ 'Organization',   'schema_organization', 'Organizationの有無',   'medium', '会社・組織情報。AIが運営主体を理解する基盤になります。' ],
            [ 'WebSite',        'schema_website',      'WebSiteの有無',         'low',    'サイト全体の情報。サイトリンク検索ボックス等に関わります。' ],
            [ 'WebPage',        'schema_webpage',      'WebPageの有無',         'low',    '各ページの基本情報を明示します。' ],
            [ 'Service',        'schema_service',      'Serviceの有無',         'low',    '提供サービスの内容をAI・検索エンジンに明示します。' ],
            [ 'LocalBusiness',  'schema_localbusiness', 'LocalBusinessの有無',  'medium', '店舗・所在地・営業時間など地域ビジネス情報。MEO/AI検索で重要です。' ],
            [ 'FAQPage',        'schema_faqpage',      'FAQPageの有無',         'low',    'よくある質問。AI検索で引用されやすくなります。' ],
            [ 'BreadcrumbList', 'schema_breadcrumb',   'BreadcrumbListの有無',  'low',    'パンくず構造。サイト階層の理解を助けます。' ],
        ];
        foreach ( $schema_defs as $d ) {
            list( $type, $key, $label, $imp, $reason ) = $d;
            $present = $has_type( $type );
            $items[] = $this->make_item(
                $key, $label,
                $present ? 'good' : 'none', $imp,
                $present ? "{$type} 構造化データが検出されました。" : "{$type} 構造化データは検出されませんでした。",
                $reason,
                $present ? '設定済みです。内容が最新か確認してください。' : "{$type} のJSON-LDを該当ページに追加してください。",
                '',
                []
            );
        }

        // 本文と構造化データの一致（AI判定）
        $items[] = $this->ai_item(
            $ai, 'structured', 'schema_consistency', '本文と構造化データの内容が一致しているか', 'medium',
            [
                'state'      => $has_jsonld ? 'caution' : 'none',
                'current'    => $has_jsonld ? '構造化データは検出されましたが、本文との一致はAI分析時のみ判定します。' : '構造化データが無いため一致を判定できません。',
                'reason'     => '構造化データと本文の内容が食い違うと、検索エンジンがスパムと判断したり、AIが誤った情報を引用するおそれがあります。',
                'suggestion' => '構造化データに記載した会社名・住所・サービス内容が本文と一致しているか確認してください。',
                'fix'        => '',
            ]
        );

        return $items;
    }

    /* ---------------------------------------------------------
     * カテゴリ4: AI検索対応（ルール信号 + AI判定のハイブリッド）
     * ------------------------------------------------------- */
    private function build_ai_search_items( array $pages, ?array $ai ): array {
        $valid = $this->valid_pages( $pages );

        // ルールベース信号（AIが使えない場合のフォールバック判定に使う）
        $sig = $this->aio_rule_signals( $valid );

        $items = [];

        $items[] = $this->ai_item( $ai, 'ai_search', 'lead_summary', 'ページ冒頭に内容を簡潔に説明する要約文があるか', 'medium', [
            'state'      => 'caution',
            'current'    => '冒頭の要約文の有無はAI分析時に詳しく判定します。',
            'reason'     => 'AI検索は冒頭の要約文をそのまま引用・要約に使うことが多く、要点が先頭にあると理解・引用されやすくなります。',
            'suggestion' => 'ページ冒頭に「誰に・何を・どこで提供するか」を2〜3文でまとめた要約文を置いてください。',
            'fix'        => '例：「みまもり歯科クリニックは松山市の予防歯科・小児歯科です。土曜診療・キッズスペース完備で、痛みの少ない治療を心がけています。」',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'entity_clarity', '会社名・サービス名・所在地・対応エリアが明確か', 'high', [
            'state'      => $sig['area'] ? 'caution' : 'none',
            'current'    => $sig['area'] ? '地域・エリアに関する記述が見られます（明確さはAI分析で判定）。' : '所在地・対応エリアの明確な記述が見つかりませんでした。',
            'reason'     => 'AI検索は「どこの・何という事業者か」を特定できないと、地域検索の回答に含めにくくなります。',
            'suggestion' => '会社名・サービス名・所在地（住所）・対応エリアを本文に明記してください。',
            'fix'        => '例：「対応エリア：松山市・東温市・伊予市（出張対応可）」',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'audience_clarity', '誰向けのサービスか明確か', 'medium', [
            'state'      => 'caution',
            'current'    => '対象者の明確さはAI分析時に判定します。',
            'reason'     => '対象者が明確だと、AIは「〇〇な人向けのサービス」として適切な質問に紐づけて回答できます。',
            'suggestion' => '「お子様連れの方」「忙しい会社員」など、想定する利用者を本文に明記してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'offering_clarity', '何を提供しているサービスか明確か', 'high', [
            'state'      => 'caution',
            'current'    => '提供内容の明確さはAI分析時に判定します。',
            'reason'     => '提供サービスが具体的だと、AIが正確に内容を要約・引用できます。',
            'suggestion' => '提供するサービス・商品を箇条書き等で具体的に記載してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'strengths_clarity', '強みや他社との違いが明確か', 'medium', [
            'state'      => 'caution',
            'current'    => '強み・差別化の明確さはAI分析時に判定します。',
            'reason'     => '独自の強みが明記されていると、AIが比較・推薦の文脈で引用しやすくなります。',
            'suggestion' => '「他社との違い」「選ばれる理由」を具体的な根拠とともに記載してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'pricing_clarity', '料金・対応範囲・相談方法が明確か', 'medium', [
            'state'      => $sig['pricing'] ? 'caution' : 'none',
            'current'    => $sig['pricing'] ? '料金・相談に関する記述が見られます（明確さはAI分析で判定）。' : '料金・対応範囲・相談方法の記述が見つかりませんでした。',
            'reason'     => '料金や相談方法が明確だと、AIがユーザーの「いくら？どう相談？」に答える際に引用できます。',
            'suggestion' => '料金の目安、対応範囲、問い合わせ・相談の方法を明記してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'faq_presence', 'よくある質問が掲載されているか', 'medium', [
            'state'      => $sig['faq'] ? 'good' : 'none',
            'current'    => $sig['faq'] ? 'よくある質問（FAQ）が確認できました。' : 'よくある質問（FAQ）が見つかりませんでした。',
            'reason'     => 'FAQは質問と回答の形式がAI検索の引用に最も適しており、回答に直接使われやすい要素です。',
            'suggestion' => '想定される質問と回答をFAQとして掲載し、可能ならFAQPage構造化データも設定してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'proof_presence', '実績・事例・お客様の声が掲載されているか', 'medium', [
            'state'      => $sig['proof'] ? 'good' : 'none',
            'current'    => $sig['proof'] ? '実績・事例・お客様の声に関する記述が確認できました。' : '実績・事例・お客様の声が見つかりませんでした。',
            'reason'     => '具体的な実績や声は信頼性の根拠となり、AIが推薦の裏付けとして引用しやすくなります。',
            'suggestion' => '導入事例・施工事例・お客様の声などを具体的に掲載してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'operator_info', '代表者または運営者情報が掲載されているか', 'medium', [
            'state'      => $sig['operator'] ? 'good' : 'none',
            'current'    => $sig['operator'] ? '運営者・代表者・会社概要に関する記述が確認できました。' : '運営者・代表者情報が見つかりませんでした。',
            'reason'     => '運営主体が明確だと、AI検索や検索エンジンの信頼性（E-E-A-T）評価が高まります。',
            'suggestion' => '会社概要・運営者情報・代表者名を掲載してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'trust_signals', '経験年数・資格・受賞歴・対応実績などの信頼情報があるか', 'medium', [
            'state'      => $sig['trust'] ? 'good' : 'none',
            'current'    => $sig['trust'] ? '資格・受賞・経験年数などの信頼情報が確認できました。' : '資格・受賞歴・経験年数などの信頼情報が見つかりませんでした。',
            'reason'     => '専門性・経験を示す情報はAIが「信頼できる情報源」と判断する材料になります。',
            'suggestion' => '保有資格・受賞歴・創業/経験年数・対応実績などを明記してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'citable_snippet', 'AIが引用しやすい短い説明文があるか', 'medium', [
            'state'      => 'caution',
            'current'    => '引用しやすい短文の有無はAI分析時に判定します。',
            'reason'     => '1〜2文で完結する説明文は、AI検索がそのまま引用しやすい形式です。',
            'suggestion' => '事業内容を1〜2文で言い切る説明文を本文中に用意してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'heading_clarity', '見出しだけを読んでもページ内容が理解できるか', 'low', [
            'state'      => 'caution',
            'current'    => '見出しだけで内容が伝わるかはAI分析時に判定します。',
            'reason'     => 'AIは見出し構造を手がかりにページを要約します。見出しだけで流れが分かると理解・引用されやすくなります。',
            'suggestion' => '抽象的な見出しを避け、内容が分かる具体的な見出しにしてください。',
            'fix'        => '例：「特徴」→「土曜も診療・キッズスペース完備の3つの特徴」',
        ] );

        $items[] = $this->ai_item( $ai, 'ai_search', 'term_explanation', '専門用語に補足説明があるか', 'low', [
            'state'      => 'caution',
            'current'    => '専門用語の補足の有無はAI分析時に判定します。',
            'reason'     => '専門用語に補足があると、AIが内容を正しく解釈し、一般ユーザー向けに要約しやすくなります。',
            'suggestion' => '専門用語には初出時にかっこ書き等で簡単な補足を添えてください。',
            'fix'        => '',
        ] );

        return $items;
    }

    /** AI検索対応・コンテンツ品質のルールベース信号を一括算出 */
    private function aio_rule_signals( array $valid ): array {
        $has_faq_schema = false;
        foreach ( $valid as $p ) {
            foreach ( ( $p['jsonld_types'] ?? [] ) as $t ) {
                if ( strtolower( $t ) === 'faqpage' ) { $has_faq_schema = true; break 2; }
            }
        }
        return [
            'faq'      => $has_faq_schema || $this->any_page_contains( $valid, [ 'よくある質問', 'FAQ', 'Q&A', 'Q＆A' ] ),
            'proof'    => $this->any_page_contains( $valid, [ '実績', '事例', 'お客様の声', '口コミ', '症例', '導入事例', 'レビュー', 'お喜びの声' ] ),
            'operator' => $this->any_page_contains( $valid, [ '会社概要', '運営者', '代表', '沿革', '院長', 'スタッフ紹介', '会社案内', '事業者' ] ),
            'trust'    => $this->any_page_contains( $valid, [ '資格', '認定', '受賞', '創業', '設立', '年の実績', '有資格', '学会', '認定医', '実績多数' ] ),
            'area'     => $this->any_page_contains( $valid, [ '対応エリア', '対応地域', 'サービスエリア', '出張', '営業エリア' ] ),
            'pricing'  => $this->any_page_contains( $valid, [ '料金', '費用', '価格', 'プラン', '見積', '無料相談', 'お見積' ] ),
        ];
    }

    /* ---------------------------------------------------------
     * カテゴリ5: クロール・インデックス
     * ------------------------------------------------------- */
    private function build_crawl_items( array $pages, array $robots, array $site_info ): array {
        $valid = $this->valid_pages( $pages );
        $items = [];

        // robots.txt 有無
        $items[] = $this->make_item(
            'robots_txt', 'robots.txtの有無',
            ! empty( $robots['exists'] ) ? 'good' : 'none', 'medium',
            ! empty( $robots['exists'] ) ? 'robots.txtが見つかりました。' : 'robots.txtが見つかりませんでした。',
            'robots.txtはクローラーの巡回ルールとsitemapの場所を伝えるファイルです。',
            'サイト直下に robots.txt を設置し、Sitemap の場所を記載してください。',
            ! empty( $robots['exists'] ) ? '' : "User-agent: *\nAllow: /\nSitemap: https://example.com/sitemap.xml",
            []
        );

        // Googlebot ブロック
        $gb_blocked = ! empty( $robots['blocks']['googlebot'] );
        $items[] = $this->make_item(
            'googlebot_allowed', 'Googlebotがブロックされていないか',
            $gb_blocked ? 'none' : 'good', 'high',
            $gb_blocked ? 'robots.txtでGooglebotがサイト全体をブロックしています。' : 'Googlebotのクロールはブロックされていません。',
            'Googlebotがブロックされると検索結果から消え、集客に致命的な影響があります。',
            'robots.txtの "Disallow: /" を見直し、必要なページをクロール可能にしてください。',
            '',
            []
        );

        // GPTBot ブロック（AI検索クローラー）
        $gpt_blocked = ! empty( $robots['blocks']['gptbot'] );
        $items[] = $this->make_item(
            'gptbot_allowed', 'GPTBotがブロックされていないか',
            $gpt_blocked ? 'caution' : 'good', 'low',
            $gpt_blocked ? 'robots.txtでGPTBot（ChatGPT系クローラー）がブロックされています。' : 'GPTBotのクロールはブロックされていません。',
            'GPTBotをブロックするとChatGPT等のAI検索でページ内容が利用されにくくなります（意図的な遮断なら問題ありません）。',
            'AI検索での露出を望む場合は、GPTBotのブロックを解除してください。',
            '',
            []
        );

        // OAI-SearchBot ブロック
        $oai_blocked = ! empty( $robots['blocks']['oai'] );
        $items[] = $this->make_item(
            'oai_searchbot_allowed', 'OAI-SearchBotがブロックされていないか',
            $oai_blocked ? 'caution' : 'good', 'low',
            $oai_blocked ? 'robots.txtでOAI-SearchBot（OpenAIの検索クローラー）がブロックされています。' : 'OAI-SearchBotのクロールはブロックされていません。',
            'OAI-SearchBotをブロックするとOpenAIのAI検索結果に表示されにくくなります（意図的な遮断なら問題ありません）。',
            'AI検索での露出を望む場合は、OAI-SearchBotのブロックを解除してください。',
            '',
            []
        );

        // noindex（インデックス観点・重要）
        $noidx = $this->urls_where( $valid, static function ( $p ) { return ! empty( $p['noindex'] ); } );
        $items[] = $this->make_item(
            'noindex_absent', 'noindexが設定されていないか',
            empty( $noidx ) ? 'good' : 'caution', 'high',
            empty( $noidx ) ? '公開ページにnoindexは設定されていません。' : count( $noidx ) . 'ページにnoindexが設定されています。',
            'noindexのページは検索結果・AI検索に表示されません。重要ページに付いていると集客機会を失います。',
            '集客対象のページにnoindexが付いていないか確認してください。',
            '',
            $noidx
        );

        // canonical 不適切（別URLを指す）
        $bad_canon = $this->urls_where( $valid, function ( $p ) {
            if ( $p['canonical'] === '' ) { return false; }
            $c = preg_replace( '#^https?://#', '', untrailingslashit( $p['canonical'] ) );
            $u = preg_replace( '#^https?://#', '', untrailingslashit( $p['url'] ) );
            return $c !== $u;
        } );
        $items[] = $this->make_item(
            'canonical_valid', 'canonicalが不適切なURLを指していないか',
            empty( $bad_canon ) ? 'good' : 'caution', 'medium',
            empty( $bad_canon ) ? 'canonicalは自ページの正規URLを指しています。' : count( $bad_canon ) . 'ページでcanonicalが別URLを指しています。',
            'canonicalが別URLを指すと、そのページが検索結果に表示されなくなる可能性があります。',
            'canonicalが自ページの正しいURLを指しているか確認してください。',
            '',
            $bad_canon
        );

        // sitemap.xml 有無
        $has_sitemap = ! empty( $site_info['sitemap_urls'] ) || ! empty( $robots['sitemaps'] );
        $items[] = $this->make_item(
            'sitemap_presence', 'sitemap.xmlの有無',
            $has_sitemap ? 'good' : 'none', 'medium',
            $has_sitemap ? 'sitemap.xmlが検出されました。' : 'sitemap.xmlが検出されませんでした。',
            'sitemapはクローラーにサイト内のページ一覧を伝え、発見性を高めます。',
            'sitemap.xmlを生成し、robots.txtに場所を記載してください。',
            $has_sitemap ? '' : 'Sitemap: https://example.com/sitemap.xml',
            []
        );

        // sitemap カバレッジ（クロール対象がsitemapに含まれるか）
        $sitemap_count = is_array( $site_info['sitemap_urls'] ?? null ) ? count( $site_info['sitemap_urls'] ) : 0;
        $items[] = $this->make_item(
            'sitemap_coverage', 'sitemap.xmlに対象ページが含まれているか',
            ! $has_sitemap ? 'none' : ( $sitemap_count > 0 ? 'good' : 'caution' ), 'low',
            ! $has_sitemap ? 'sitemapが無いため確認できません。' : ( $sitemap_count > 0 ? "sitemapから{$sitemap_count}件のURLを取得できました。" : 'sitemapは存在しますが対象URLを取得できませんでした。' ),
            'sitemapに重要ページが含まれていないと、クローラーに発見されにくくなります。',
            'sitemapに公開中の重要ページがすべて含まれているか確認してください。',
            '',
            []
        );

        // 重要ページがクロール可能か（200で取得できたか）
        $err_pages = $this->urls_where( $pages, static function ( $p ) {
            return (int) ( $p['status_code'] ?? 0 ) !== 200;
        } );
        $items[] = $this->make_item(
            'important_pages_crawlable', '重要ページがクロール可能か',
            empty( $err_pages ) ? 'good' : 'caution', 'medium',
            empty( $err_pages ) ? '診断対象ページはすべて200 OKで取得できました。' : count( $err_pages ) . 'ページが200以外（取得失敗・エラー）でした。',
            'クローラーが取得できないページは検索結果・AI検索に載りません。',
            '404はリンク切れの修正かリダイレクト、5xxはサーバー側の調査を行ってください。',
            '',
            $err_pages
        );

        // JavaScript依存（本文がほぼ空でscriptが多いページ）
        $js_dep = $this->urls_where( $valid, static function ( $p ) {
            return ( $p['body_text_length'] ?? 0 ) < 150 && ( $p['script_count'] ?? 0 ) >= 5;
        } );
        $items[] = $this->make_item(
            'js_dependency', 'JavaScript依存で本文取得が難しい状態でないか',
            empty( $js_dep ) ? 'good' : 'caution', 'medium',
            empty( $js_dep ) ? 'HTMLから本文テキストを取得できています。' : count( $js_dep ) . 'ページで本文がほぼ空（JS描画依存の可能性）でした。',
            'JavaScriptでしか本文が表示されないと、AI検索クローラーや一部の検索エンジンが内容を取得できないことがあります。',
            '重要な本文はHTMLに含める（SSR/静的出力）か、初期表示でテキストが見える構成にしてください。',
            '',
            $js_dep
        );

        return $items;
    }

    /* ---------------------------------------------------------
     * カテゴリ6: コンテンツ品質（ルール信号 + AI判定のハイブリッド）
     * ------------------------------------------------------- */
    private function build_content_items( array $pages, ?array $ai ): array {
        $valid = $this->valid_pages( $pages );
        $sig   = $this->aio_rule_signals( $valid );
        $items = [];

        $items[] = $this->ai_item( $ai, 'content', 'purpose_clarity', 'ページの目的が明確か', 'medium', [
            'state'      => 'caution',
            'current'    => 'ページ目的の明確さはAI分析時に判定します。',
            'reason'     => '目的が明確なページは、ユーザーにもAIにも「何のためのページか」が伝わりやすくなります。',
            'suggestion' => '各ページが「誰に・何を伝え・何をしてほしいか」を明確にしてください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'content', 'answers_questions', 'ユーザーの疑問に答えているか', 'medium', [
            'state'      => 'caution',
            'current'    => 'ユーザーの疑問への回答状況はAI分析時に判定します。',
            'reason'     => '想定される疑問に答えているページは滞在時間が伸び、AI検索でも回答に使われやすくなります。',
            'suggestion' => '利用者がよく抱く疑問を洗い出し、本文やFAQで回答してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'content', 'concreteness', '具体的な説明があるか（抽象的すぎないか）', 'medium', [
            'state'      => 'caution',
            'current'    => '具体性はAI分析時に判定します。',
            'reason'     => '具体的な記述は信頼性が高く、AIが要点を抽出・引用しやすくなります。',
            'suggestion' => '「丁寧な対応」などの抽象表現を、数値・事例・手順など具体的な情報に置き換えてください。',
            'fix'        => '例：「丁寧な対応」→「初回カウンセリングに30分かけ、治療計画を書面でお渡しします」',
        ] );

        $items[] = $this->ai_item( $ai, 'content', 'evidence', '実績や事例で裏付けされているか', 'medium', [
            'state'      => $sig['proof'] ? 'good' : 'none',
            'current'    => $sig['proof'] ? '実績・事例に関する記述が確認できました。' : '実績・事例による裏付けが見つかりませんでした。',
            'reason'     => '主張を実績・事例で裏付けると、ユーザー・AI双方からの信頼が高まります。',
            'suggestion' => '具体的な実績・事例・データを根拠として掲載してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'content', 'uniqueness', '独自性のある情報があるか', 'low', [
            'state'      => 'caution',
            'current'    => '独自性はAI分析時に判定します。',
            'reason'     => '他サイトにない独自情報は、検索・AI検索での差別化につながります。',
            'suggestion' => '自社ならではの知見・データ・体験を盛り込んでください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'content', 'eeat', '専門性・経験・信頼性が伝わるか', 'medium', [
            'state'      => $sig['trust'] ? 'good' : 'caution',
            'current'    => $sig['trust'] ? '資格・経験などの専門性情報が確認できました。' : '専門性・経験・信頼性を示す情報が十分か、AI分析時に判定します。',
            'reason'     => 'E-E-A-T（経験・専門性・権威性・信頼性）は検索・AI検索の品質評価で重視されます。',
            'suggestion' => '執筆者・運営者の専門性、経験、根拠を明示してください。',
            'fix'        => '',
        ] );

        $items[] = $this->ai_item( $ai, 'content', 'reassurance', '問い合わせ前の不安を解消できているか', 'low', [
            'state'      => 'caution',
            'current'    => '不安解消の度合いはAI分析時に判定します。',
            'reason'     => '料金・流れ・対応範囲などの不安要素に答えると、問い合わせ・成約につながりやすくなります。',
            'suggestion' => '「料金は？」「流れは？」「対応エリアは？」など、問い合わせ前の疑問に答えてください。',
            'fix'        => '',
        ] );

        // 関連ページへの内部リンク（ルール）
        $low_link = $this->urls_where( $valid, static function ( $p ) { return ( $p['internal_links'] ?? 0 ) < 2; } );
        $items[] = $this->make_item(
            'related_links', '関連ページへの内部リンクがあるか',
            empty( $low_link ) ? 'good' : 'caution', 'low',
            empty( $low_link ) ? '各ページに関連ページへの内部リンクがあります。' : count( $low_link ) . 'ページで内部リンクがほとんどありません。',
            '関連ページへのリンクは回遊性を高め、トピックの関連性をAI・検索エンジンに伝えます。',
            '関連するサービス・事例・FAQページへの内部リンクを追加してください。',
            '',
            $low_link
        );

        // FAQ（ルール、AI検索対応と共有信号）
        $items[] = $this->make_item(
            'content_faq', 'FAQがあるか',
            $sig['faq'] ? 'good' : 'none', 'low',
            $sig['faq'] ? 'よくある質問（FAQ）が確認できました。' : 'よくある質問（FAQ）が見つかりませんでした。',
            'FAQはユーザーの疑問解消とAI検索での引用の両方に効果的です。',
            'よくある質問と回答を掲載してください。',
            '',
            []
        );

        // コンテンツ量（ルール、確認・完了ページは除外）
        $thin = [];
        foreach ( $valid as $p ) {
            if ( $this->is_content_excluded_url( $p['url'] ) ) { continue; }
            if ( ( $p['body_text_length'] ?? 0 ) < 300 ) {
                $thin[] = $this->url_path( $p['url'] ) . ' (' . ( $p['body_text_length'] ?? 0 ) . '文字)';
            }
        }
        $items[] = $this->make_item(
            'thin_content', 'コンテンツ量が十分か',
            empty( $thin ) ? 'good' : 'caution', 'medium',
            empty( $thin ) ? '各ページに十分なコンテンツ量があります。' : count( $thin ) . 'ページでコンテンツが少なめ（300文字未満）です。',
            'テキスト量が極端に少ないページは低品質と判定されやすく、AIも内容を要約しにくくなります。',
            'ユーザーの疑問に答える有益な情報を追加し、テーマを深掘りしてください。',
            '',
            $thin
        );

        return $items;
    }

    /* ---------------------------------------------------------
     * AI判定（AI検索対応 + コンテンツ品質 + 構造化データ一致）
     *
     * Gemini を1回だけ呼び出し、定性的なカテゴリの項目ごとに
     * state / currentState / reason / suggestion / fixExample を返す。
     * AI client が null またはエラー時は null を返し、ルールベースのフォールバックで動作する。
     * ------------------------------------------------------- */
    private function analyze_aio_with_ai( array $pages ): ?array {
        if ( ! $this->ai_client ) {
            return null;
        }
        $valid = $this->valid_pages( $pages );
        if ( empty( $valid ) ) {
            return null;
        }

        // トップページ + 代表ページ（最大4ページ）を要約してプロンプトへ
        $home = $this->homepage_page( $valid );
        $selected = [];
        if ( $home ) { $selected[] = $home; }
        foreach ( $valid as $p ) {
            if ( count( $selected ) >= 4 ) { break; }
            if ( $home && $p['url'] === $home['url'] ) { continue; }
            $selected[] = $p;
        }

        $page_summaries = [];
        foreach ( $selected as $p ) {
            $page_summaries[] = [
                'url'      => $this->url_path( $p['url'] ),
                'title'    => mb_substr( $p['title'] ?? '', 0, 100 ),
                'h1'       => implode( ' / ', array_slice( $p['h1'] ?? [], 0, 3 ) ),
                'headings' => implode( ' / ', array_slice( array_merge( $p['h2'] ?? [], $p['h3'] ?? [] ), 0, 12 ) ),
                'excerpt'  => mb_substr( $p['body_text_excerpt'] ?? '', 0, 1200 ),
            ];
        }

        $sig         = $this->aio_rule_signals( $valid );
        $jsonld_types = [];
        foreach ( $valid as $p ) {
            foreach ( ( $p['jsonld_types'] ?? [] ) as $t ) { $jsonld_types[] = $t; }
        }
        $jsonld_types = array_values( array_unique( $jsonld_types ) );

        $pages_json  = wp_json_encode( $page_summaries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        $signal_json = wp_json_encode( [
            'faq_detected'      => $sig['faq'],
            'proof_detected'    => $sig['proof'],
            'operator_detected' => $sig['operator'],
            'trust_detected'    => $sig['trust'],
            'jsonld_types'      => $jsonld_types,
        ], JSON_UNESCAPED_UNICODE );

        $prompt = <<<PROMPT
あなたはAI検索（ChatGPT・Google AI Overview等）とSEOに精通したコンサルタントです。
以下のウェブサイトのページ情報をもとに、各診断項目を評価してください。

【ページ情報】
{$pages_json}

【参考シグナル（ルールベース検出）】
{$signal_json}

各項目について、次のJSONオブジェクトを返してください。
- state: "good"（良好）/ "caution"（要改善）/ "none"（未設定・見当たらない）のいずれか
- currentState: 現在の状態を説明する1文（日本語、事実ベース。「AIに表示される」等の断定はしない）
- reason: なぜ改善が必要か／重要かを説明する1文
- suggestion: 具体的な改善提案を1文
- fixExample: 可能なら短い修正例（無ければ空文字 ""）

評価項目（キーは英語のまま使う）:
ai_search:
  lead_summary（冒頭に内容を簡潔に説明する要約文があるか）
  entity_clarity（会社名・サービス名・所在地・対応エリアが明確か）
  audience_clarity（誰向けのサービスか明確か）
  offering_clarity（何を提供しているか明確か）
  strengths_clarity（強み・他社との違いが明確か）
  pricing_clarity（料金・対応範囲・相談方法が明確か）
  faq_presence（よくある質問が掲載されているか）
  proof_presence（実績・事例・お客様の声があるか）
  operator_info（代表者・運営者情報があるか）
  trust_signals（経験年数・資格・受賞歴・実績などの信頼情報があるか）
  citable_snippet（AIが引用しやすい短い説明文があるか）
  heading_clarity（見出しだけ読んでも内容が理解できるか）
  term_explanation（専門用語に補足説明があるか）
content:
  purpose_clarity（ページの目的が明確か）
  answers_questions（ユーザーの疑問に答えているか）
  concreteness（具体的な説明があるか／抽象的すぎないか）
  evidence（実績や事例で裏付けされているか）
  uniqueness（独自性のある情報があるか）
  eeat（専門性・経験・信頼性が伝わるか）
  reassurance（問い合わせ前の不安を解消できているか）
structured:
  schema_consistency（本文の内容と構造化データの内容が一致しているか。構造化データが無ければ "none"）

出力形式（厳守。JSON以外のテキストは出力しない）:
```json
{
  "ai_search": {
    "lead_summary": { "state": "...", "currentState": "...", "reason": "...", "suggestion": "...", "fixExample": "" }
  },
  "content": { },
  "structured": { }
}
```
ルール:
- すべての項目に必ずエントリを返す
- 断定的に「AIに表示される」「上位表示される」とは書かない。「理解・引用されやすくするための整備」という観点で書く
- 日本語で回答する
PROMPT;

        try {
            $raw = $this->ai_client->call_gemini_api( $prompt );
            $raw = preg_replace( '/^```json\s*/s', '', (string) $raw );
            $raw = preg_replace( '/\s*```\s*$/s', '', $raw );
            $raw = trim( $raw );
            $parsed = json_decode( $raw, true );
            if ( ! is_array( $parsed ) ) {
                file_put_contents( '/tmp/gcrev_seo_debug.log',
                    date( 'Y-m-d H:i:s' ) . " AIO analysis JSON parse failed: " . substr( $raw, 0, 500 ) . "\n",
                    FILE_APPEND
                );
                return null;
            }
            return $parsed;
        } catch ( \Throwable $e ) {
            file_put_contents( '/tmp/gcrev_seo_debug.log',
                date( 'Y-m-d H:i:s' ) . " AIO analysis error: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return null;
        }
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
     * 全体評価
     * ========================================================= */

    /**
     * カテゴリ別スコアと項目から全体評価を生成する。
     * 総合スコアはあくまで参考値として扱う。
     */
    private function compute_overall_assessment( array $categories, array $flat, int $total_score ): array {
        $good_points        = [];
        $improvement_points = [];

        foreach ( $categories as $c ) {
            if ( ( $c['score'] ?? 0 ) >= 80 ) {
                $good_points[] = "{$c['label']}は良好です（{$c['score']}点）";
            } elseif ( ( $c['score'] ?? 0 ) < 50 ) {
                $improvement_points[] = "{$c['label']}に改善の余地があります（{$c['score']}点）";
            }
        }

        // 重要度の高い未対応項目を改善ポイントに補足
        $high_issues = array_filter( $flat, static function ( $it ) {
            return ( $it['importance'] ?? '' ) === 'high' && ( $it['state'] ?? '' ) !== 'good';
        } );
        foreach ( array_slice( array_values( $high_issues ), 0, 3 ) as $it ) {
            $improvement_points[] = "{$it['category']}：{$it['label']}（重要度：高）";
        }

        if ( empty( $good_points ) ) {
            $good_points[] = '診断対象のページがクロールできました';
        }
        if ( empty( $improvement_points ) ) {
            $improvement_points[] = '大きな問題は見つかりませんでした。細かな項目の見直しでさらに改善できます。';
        }

        $summary = "総合スコアは{$total_score}点（参考値）です。総合点は目安であり、実際の改善ではカテゴリ別スコアと重要度の高い項目から優先して対応してください。";

        return [
            'summary'           => $summary,
            'goodPoints'        => array_values( array_unique( $good_points ) ),
            'improvementPoints' => array_values( array_unique( $improvement_points ) ),
        ];
    }

    /**
     * フラット項目（state != good）から改善アクション提案を生成する。
     */
    private function build_recommendations( array $flat ): array {
        $recs = [];
        foreach ( $flat as $it ) {
            if ( ( $it['state'] ?? '' ) === 'good' ) { continue; }

            $imp   = $it['importance'] ?? 'low';
            $state = $it['state'] ?? 'caution';
            // 未設定 or 高重要度 → high、中重要度 → medium、それ以外 → low
            if ( $imp === 'high' ) {
                $priority = 'high';
            } elseif ( $imp === 'medium' ) {
                $priority = ( $state === 'none' ) ? 'high' : 'medium';
            } else {
                $priority = ( $state === 'none' ) ? 'medium' : 'low';
            }

            $desc = '';
            if ( ! empty( $it['currentState'] ) ) { $desc .= '【現状】' . $it['currentState']; }
            if ( ! empty( $it['reason'] ) )       { $desc .= '【理由】' . $it['reason']; }
            if ( ! empty( $it['suggestion'] ) )   { $desc .= '【対策】' . $it['suggestion']; }
            if ( ! empty( $it['fixExample'] ) )   { $desc .= '【修正例】' . $it['fixExample']; }

            $recs[] = [
                'title'       => "「{$it['category']}」{$it['label']}",
                'description' => $desc,
                'priority'    => $priority,
                'category'    => $it['category'],
            ];
        }

        $priority_order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
        usort( $recs, static function ( $a, $b ) use ( $priority_order ) {
            return ( $priority_order[ $a['priority'] ] ?? 9 ) - ( $priority_order[ $b['priority'] ] ?? 9 );
        } );

        // 上位を中心に最大18件
        return array_slice( $recs, 0, 18 );
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

                $in_title = $this->keyword_in_text( $keyword, $p['title'] ?? '' );
                $in_desc  = $this->keyword_in_text( $keyword, $p['meta_description'] ?? '' );
                $in_h1    = $this->keyword_in_array( $keyword, $p['h1'] );
                $in_h2    = $this->keyword_in_array( $keyword, $p['h2'] );
                $in_body  = $this->keyword_in_text( $keyword, $p['body_text_excerpt'] ?? '' );

                // ページ全体での一致（複合キーワードの各語が title/desc/h見出し/本文の
                // どこかに散らばって存在するケースを拾う。例: 「松山市」がtitle・「歯科」が本文）
                $combined = trim(
                    ( $p['title'] ?? '' ) . ' '
                    . ( $p['meta_description'] ?? '' ) . ' '
                    . implode( ' ', $p['h1'] ?? [] ) . ' '
                    . implode( ' ', $p['h2'] ?? [] ) . ' '
                    . ( $p['body_text_excerpt'] ?? '' )
                );
                $in_page = $this->keyword_in_text( $keyword, $combined );

                // rank_results の found_url と一致するか
                $is_ranking_url = false;
                if ( $found_url ) {
                    $found_path = untrailingslashit( wp_parse_url( $found_url, PHP_URL_PATH ) ?: '/' );
                    $page_path  = untrailingslashit( wp_parse_url( $p['url'], PHP_URL_PATH ) ?: '/' );
                    $is_ranking_url = ( $found_path === $page_path );
                }

                if ( $in_title || $in_desc || $in_h1 || $in_h2 || $in_body || $in_page || $is_ranking_url ) {
                    $placements = [];
                    if ( $in_title ) { $placements[] = 'title'; }
                    if ( $in_desc )  { $placements[] = 'description'; }
                    if ( $in_h1 )    { $placements[] = 'h1'; }
                    if ( $in_h2 )    { $placements[] = 'h2'; }
                    if ( $in_body )  { $placements[] = 'body'; }
                    if ( $in_page && empty( $placements ) ) { $placements[] = 'content'; }
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
            if ( $this->keyword_in_text( $keyword, (string) $text ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * キーワードがテキストに含まれるか判定する。
     *
     * スペース区切りの複合キーワード（例「松山市 歯科」）は、各トークンが
     * すべて含まれていれば一致とみなす（語順・間に挟まる文字は問わない）。
     * フレーズ全体の完全一致だけを見ていた旧実装では、実ページに
     * 「松山市の歯科医院」と書かれていても "松山市 歯科" を検出できなかった。
     * 単語キーワード（スペースなし）は従来どおりの部分一致。
     */
    private function keyword_in_text( string $keyword, string $text ): bool {
        if ( $text === '' ) { return false; }
        $tokens = $this->tokenize_keyword( $keyword );
        if ( empty( $tokens ) ) { return false; }
        foreach ( $tokens as $t ) {
            if ( mb_stripos( $text, $t ) === false ) {
                return false;
            }
        }
        return true;
    }

    /**
     * キーワードを空白（全角・半角）で分割してトークン配列にする。
     */
    private function tokenize_keyword( string $keyword ): array {
        $normalized = preg_replace( '/[\x{3000}\s]+/u', ' ', trim( $keyword ) );
        if ( $normalized === null || $normalized === '' ) { return []; }
        return array_values( array_filter(
            explode( ' ', $normalized ),
            static function ( $t ) { return $t !== ''; }
        ) );
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
