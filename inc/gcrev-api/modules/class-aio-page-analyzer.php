<?php
// FILE: inc/gcrev-api/modules/class-aio-page-analyzer.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Gcrev_AIO_Page_Analyzer' ) ) { return; }

/**
 * AIO ページ解析クラス
 *
 * AIO 引用ページおよび自社ページの HTML 構造を解析し、
 * AIO 掲載に影響する要素（FAQ, 定義型, HowTo, E-E-A-T 等）を検出する。
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Gcrev_AIO_Page_Analyzer {

    private const LOG_FILE     = '/tmp/gcrev_aio_analysis_debug.log';
    private const TIMEOUT      = 15;
    private const USER_AGENT   = 'MimamoriAIO/1.0';
    private const CRAWL_DELAY  = 1;
    private const CACHE_TTL    = 7 * DAY_IN_SECONDS;

    // =========================================================
    // メイン解析
    // =========================================================

    /**
     * URL をクロールして解析。キャッシュがあればそれを返す。
     */
    public function fetch_and_analyze( string $url ): array {
        global $wpdb;
        $table    = $wpdb->prefix . 'gcrev_aio_page_analyses';
        $url_hash = hash( 'sha256', $url );

        // キャッシュチェック（7日以内の成功結果）
        $cached = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE url_hash = %s AND fetch_status = 'success' AND fetched_at > %s LIMIT 1",
            $url_hash,
            gmdate( 'Y-m-d H:i:s', time() - self::CACHE_TTL )
        ), ARRAY_A );

        if ( $cached ) {
            return $this->row_to_result( $cached );
        }

        // クロール実行
        $html = $this->fetch_page( $url );
        if ( $html === null ) {
            $this->save_result( $url, $url_hash, [
                'fetch_status' => 'failed',
                'title'        => '',
                'word_count'   => 0,
            ] );
            return [ 'url' => $url, 'fetch_status' => 'failed', 'error' => 'Fetch failed' ];
        }

        // HTML 解析
        $result = $this->analyze_html( $html, $url );
        $result['fetch_status'] = 'success';

        // DB 保存
        $this->save_result( $url, $url_hash, $result );

        return $result;
    }

    /**
     * HTML 文字列を解析して構造情報を返す
     */
    public function analyze_html( string $html, string $url ): array {
        $result = [
            'url'                 => $url,
            'title'               => '',
            'word_count'          => 0,
            'headings'            => [],
            'heading_depth'       => 0,
            'has_faq'             => false,
            'has_list'            => false,
            'list_count'          => 0,
            'has_definition'      => false,
            'has_howto'           => false,
            'has_eeat'            => false,
            'has_updated_date'    => false,
            'updated_date_text'   => '',
            'internal_link_count' => 0,
        ];

        libxml_use_internal_errors( true );
        $doc = new \DOMDocument();
        $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();

        $xpath = new \DOMXPath( $doc );

        // タイトル
        $titles = $xpath->query( '//title' );
        if ( $titles->length > 0 ) {
            $result['title'] = trim( $titles->item( 0 )->textContent );
        }

        // 本文テキスト抽出（nav/header/footer/script/style 除去）
        $body_text = $this->extract_body_text( $xpath );
        $result['word_count'] = mb_strlen( preg_replace( '/\s+/u', '', $body_text ) );

        // 見出し構造
        $result['headings']      = $this->extract_heading_structure( $xpath );
        $result['heading_depth'] = $this->calc_heading_depth( $result['headings'] );

        // FAQ 検出
        $result['has_faq'] = $this->detect_faq( $xpath, $body_text );

        // リスト構造
        $result['list_count'] = $this->count_list_elements( $xpath );
        $result['has_list']   = $result['list_count'] > 0;

        // 定義型コンテンツ
        $result['has_definition'] = $this->detect_definition_content( $xpath, $body_text );

        // 手順型コンテンツ
        $result['has_howto'] = $this->detect_howto( $xpath, $body_text );

        // E-E-A-T 要素
        $result['has_eeat'] = $this->detect_eeat( $xpath, $body_text );

        // 更新日
        $date = $this->detect_updated_date( $xpath, $html );
        $result['has_updated_date']  = $date !== null;
        $result['updated_date_text'] = $date ?? '';

        // 内部リンク数
        $parsed = wp_parse_url( $url );
        $host   = $parsed['host'] ?? '';
        $result['internal_link_count'] = $this->count_internal_links( $xpath, $host );

        return $result;
    }

    // =========================================================
    // 検出メソッド
    // =========================================================

    /**
     * FAQ 構造検出
     */
    private function detect_faq( \DOMXPath $xpath, string $body_text ): bool {
        // Schema.org FAQPage
        $faq_schema = $xpath->query( '//*[@itemtype="https://schema.org/FAQPage"]' );
        if ( $faq_schema->length > 0 ) { return true; }

        // JSON-LD に FAQPage
        $scripts = $xpath->query( '//script[@type="application/ld+json"]' );
        foreach ( $scripts as $script ) {
            $json = $script->textContent;
            if ( stripos( $json, 'FAQPage' ) !== false ) { return true; }
        }

        // FAQ / よくある質問 の見出し
        $faq_headings = $xpath->query( '//h2[contains(., "FAQ")] | //h2[contains(., "よくある質問")] | //h3[contains(., "FAQ")] | //h3[contains(., "よくある質問")]' );
        if ( $faq_headings->length > 0 ) { return true; }

        // Q&A パターン（Q: / A: の繰り返し）
        if ( preg_match_all( '/(?:Q[\.:：]|質問[\.:：])/u', $body_text ) >= 2 ) { return true; }

        // dl/dt/dd 構造が3つ以上
        $dts = $xpath->query( '//dl/dt' );
        if ( $dts->length >= 3 ) { return true; }

        // details/summary 構造（アコーディオン FAQ）
        $details = $xpath->query( '//details/summary' );
        if ( $details->length >= 3 ) { return true; }

        return false;
    }

    /**
     * 定義型コンテンツ検出（「〜とは」形式）
     */
    private function detect_definition_content( \DOMXPath $xpath, string $body_text ): bool {
        // 見出しに「〜とは」
        $def_headings = $xpath->query(
            '//h1[contains(., "とは")] | //h2[contains(., "とは")] | //h3[contains(., "とは")]'
        );
        if ( $def_headings->length > 0 ) { return true; }

        // 本文に定義パターン
        $patterns = [
            '/[^。]{2,20}とは[、,]?[^\n]{10,}/u',
            '/[^。]{2,20}(の意味|の定義|について解説)/u',
        ];
        foreach ( $patterns as $pat ) {
            if ( preg_match( $pat, $body_text ) ) { return true; }
        }

        return false;
    }

    /**
     * 手順型コンテンツ検出（HowTo）
     */
    private function detect_howto( \DOMXPath $xpath, string $body_text ): bool {
        // Schema.org HowTo
        $howto_schema = $xpath->query( '//*[@itemtype="https://schema.org/HowTo"]' );
        if ( $howto_schema->length > 0 ) { return true; }

        // JSON-LD に HowTo
        $scripts = $xpath->query( '//script[@type="application/ld+json"]' );
        foreach ( $scripts as $script ) {
            if ( stripos( $script->textContent, 'HowTo' ) !== false ) { return true; }
        }

        // 見出しに手順系キーワード
        $howto_headings = $xpath->query(
            '//h2[contains(., "方法")] | //h2[contains(., "手順")] | //h2[contains(., "ステップ")] | //h2[contains(., "やり方")] | //h2[contains(., "流れ")]'
            . ' | //h3[contains(., "方法")] | //h3[contains(., "手順")] | //h3[contains(., "ステップ")]'
        );
        if ( $howto_headings->length > 0 ) { return true; }

        // 番号付きステップ（STEP1, 手順1 等）
        if ( preg_match_all( '/(STEP\s*\d|ステップ\s*\d|手順\s*\d)/ui', $body_text ) >= 2 ) {
            return true;
        }

        return false;
    }

    /**
     * E-E-A-T 要素検出
     */
    private function detect_eeat( \DOMXPath $xpath, string $body_text ): bool {
        $signals = 0;

        // 著者・監修者情報
        $author_patterns = [
            '//div[contains(@class, "author")]',
            '//div[contains(@class, "writer")]',
            '//div[contains(@class, "profile")]',
            '//p[contains(@class, "author")]',
            '//*[contains(@class, "supervisor")]',
            '//*[contains(@class, "expert")]',
        ];
        foreach ( $author_patterns as $q ) {
            if ( $xpath->query( $q )->length > 0 ) { $signals++; break; }
        }

        // テキスト内の監修・著者キーワード
        if ( preg_match( '/(監修|執筆者|著者|ライター|編集者|プロフィール)/u', $body_text ) ) {
            $signals++;
        }

        // 会社概要・運営者情報リンク
        $company_links = $xpath->query(
            '//a[contains(@href, "company")] | //a[contains(@href, "about")] | //a[contains(., "会社概要")] | //a[contains(., "運営者")]'
        );
        if ( $company_links->length > 0 ) { $signals++; }

        // 実績・事例キーワード
        if ( preg_match( '/(実績|事例|導入企業|お客様の声|レビュー)/u', $body_text ) ) {
            $signals++;
        }

        // 資格・経歴キーワード
        if ( preg_match( '/(資格|経歴|認定|受賞|年間|年以上)/u', $body_text ) ) {
            $signals++;
        }

        return $signals >= 2;
    }

    /**
     * 更新日検出
     */
    private function detect_updated_date( \DOMXPath $xpath, string $html ): ?string {
        // time タグの datetime 属性
        $times = $xpath->query( '//time[@datetime]' );
        $latest = null;
        foreach ( $times as $time ) {
            $dt = $time->getAttribute( 'datetime' );
            if ( ! empty( $dt ) ) {
                $latest = $dt;
            }
        }
        if ( $latest ) { return $latest; }

        // meta タグの更新日
        $meta_modified = $xpath->query( '//meta[@property="article:modified_time"]/@content' );
        if ( $meta_modified->length > 0 ) {
            return $meta_modified->item( 0 )->textContent;
        }

        // テキストから日付パターン
        if ( preg_match( '/(更新日|最終更新|公開日)[：:\s]*(\d{4}[\-\/年]\d{1,2}[\-\/月]\d{1,2})/u', $html, $m ) ) {
            return $m[2];
        }

        return null;
    }

    // =========================================================
    // ユーティリティ
    // =========================================================

    /**
     * 見出し構造を抽出
     */
    private function extract_heading_structure( \DOMXPath $xpath ): array {
        $headings = [];
        $nodes = $xpath->query( '//h1 | //h2 | //h3 | //h4' );
        foreach ( $nodes as $node ) {
            $level = (int) substr( $node->nodeName, 1 );
            $text  = trim( $node->textContent );
            if ( $text !== '' ) {
                $headings[] = [ 'level' => $level, 'text' => mb_substr( $text, 0, 100 ) ];
            }
        }
        return $headings;
    }

    /**
     * 見出し階層の深さを計算
     */
    private function calc_heading_depth( array $headings ): int {
        if ( empty( $headings ) ) { return 0; }
        $max = 0;
        foreach ( $headings as $h ) {
            if ( $h['level'] > $max ) { $max = $h['level']; }
        }
        return $max;
    }

    /**
     * ul/ol 要素数をカウント
     */
    private function count_list_elements( \DOMXPath $xpath ): int {
        $lists = $xpath->query( '//article//ul | //article//ol | //main//ul | //main//ol | //div[contains(@class,"content")]//ul | //div[contains(@class,"content")]//ol' );
        if ( $lists->length > 0 ) { return $lists->length; }

        // article/main がない場合は body 全体
        $all = $xpath->query( '//body//ul | //body//ol' );
        return $all->length;
    }

    /**
     * 内部リンク数をカウント
     */
    private function count_internal_links( \DOMXPath $xpath, string $host ): int {
        if ( empty( $host ) ) { return 0; }
        $norm_host = preg_replace( '/^(www\.|m\.)/', '', strtolower( $host ) );
        $links = $xpath->query( '//a[@href]' );
        $count = 0;
        foreach ( $links as $link ) {
            $href = $link->getAttribute( 'href' );
            $parsed = wp_parse_url( $href );
            $link_host = $parsed['host'] ?? '';
            $link_host = preg_replace( '/^(www\.|m\.)/', '', strtolower( $link_host ) );
            if ( $link_host === $norm_host || ( empty( $link_host ) && strpos( $href, '/' ) === 0 ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 本文テキスト抽出（nav/header/footer/script/style 除去）
     */
    private function extract_body_text( \DOMXPath $xpath ): string {
        $body = $xpath->query( '//body' );
        if ( $body->length === 0 ) { return ''; }

        $clone = $body->item( 0 )->cloneNode( true );
        $doc   = $clone->ownerDocument;

        $remove_tags = [ 'script', 'style', 'nav', 'header', 'footer', 'noscript', 'iframe' ];
        foreach ( $remove_tags as $tag ) {
            $nodes = $clone->getElementsByTagName( $tag );
            $to_remove = [];
            foreach ( $nodes as $n ) { $to_remove[] = $n; }
            foreach ( $to_remove as $n ) { $n->parentNode->removeChild( $n ); }
        }

        $text = $clone->textContent;
        return preg_replace( '/\s+/u', ' ', trim( $text ) );
    }

    /**
     * ページ取得
     */
    private function fetch_page( string $url ): ?string {
        $response = wp_remote_get( $url, [
            'timeout'    => self::TIMEOUT,
            'user-agent' => self::USER_AGENT,
            'sslverify'  => false,
            'redirection' => 3,
        ] );

        if ( is_wp_error( $response ) ) {
            self::log( "Fetch error for {$url}: " . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            self::log( "HTTP {$code} for {$url}" );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( strlen( $body ) > 2 * 1024 * 1024 ) {
            self::log( "Response too large for {$url}: " . strlen( $body ) . " bytes" );
            return null;
        }

        return $body;
    }

    /**
     * 解析結果を DB に保存
     */
    private function save_result( string $url, string $url_hash, array $result ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_aio_page_analyses';
        $now   = current_time( 'mysql' );

        $wpdb->replace( $table, [
            'url'                 => mb_substr( $url, 0, 2048 ),
            'url_hash'            => $url_hash,
            'title'               => mb_substr( $result['title'] ?? '', 0, 500 ),
            'word_count'          => (int) ( $result['word_count'] ?? 0 ),
            'heading_json'        => wp_json_encode( $result['headings'] ?? [], JSON_UNESCAPED_UNICODE ),
            'has_faq'             => (int) ( $result['has_faq'] ?? false ),
            'has_list'            => (int) ( $result['has_list'] ?? false ),
            'list_count'          => (int) ( $result['list_count'] ?? 0 ),
            'has_definition'      => (int) ( $result['has_definition'] ?? false ),
            'has_howto'           => (int) ( $result['has_howto'] ?? false ),
            'has_eeat'            => (int) ( $result['has_eeat'] ?? false ),
            'has_updated_date'    => (int) ( $result['has_updated_date'] ?? false ),
            'updated_date_text'   => mb_substr( $result['updated_date_text'] ?? '', 0, 100 ),
            'internal_link_count' => (int) ( $result['internal_link_count'] ?? 0 ),
            'heading_depth'       => (int) ( $result['heading_depth'] ?? 0 ),
            'fetch_status'        => $result['fetch_status'] ?? 'pending',
            'fetched_at'          => $now,
            'created_at'          => $now,
        ] );

        if ( $wpdb->last_error ) {
            self::log( "DB save error: {$wpdb->last_error}" );
        }
    }

    /**
     * DB 行を結果配列に変換
     */
    private function row_to_result( array $row ): array {
        return [
            'url'                 => $row['url'],
            'fetch_status'        => $row['fetch_status'],
            'title'               => $row['title'],
            'word_count'          => (int) $row['word_count'],
            'headings'            => json_decode( $row['heading_json'] ?? '[]', true ) ?: [],
            'heading_depth'       => (int) $row['heading_depth'],
            'has_faq'             => (bool) $row['has_faq'],
            'has_list'            => (bool) $row['has_list'],
            'list_count'          => (int) $row['list_count'],
            'has_definition'      => (bool) $row['has_definition'],
            'has_howto'           => (bool) $row['has_howto'],
            'has_eeat'            => (bool) $row['has_eeat'],
            'has_updated_date'    => (bool) $row['has_updated_date'],
            'updated_date_text'   => $row['updated_date_text'] ?? '',
            'internal_link_count' => (int) $row['internal_link_count'],
        ];
    }

    /**
     * クロール間隔を空ける
     */
    public function wait(): void {
        sleep( self::CRAWL_DELAY );
    }

    private static function log( string $message ): void {
        file_put_contents(
            self::LOG_FILE,
            date( 'Y-m-d H:i:s' ) . " [PageAnalyzer] {$message}\n",
            FILE_APPEND
        );
    }
}
