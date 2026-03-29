<?php
// FILE: inc/gcrev-api/modules/class-aio-serp-parser.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Gcrev_AIO_Serp_Parser' ) ) { return; }

/**
 * SERP レスポンスから AI Overview を抽出・パースする
 *
 * Bright Data の brd_json=1 + brd_ai_overview=2 レスポンスを解析し、
 * AI Overview の有無・引用 URL・ドメイン・サイト名・表示順を抽出する。
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Gcrev_AIO_Serp_Parser {

    // =========================================================
    // メイン解析
    // =========================================================

    /**
     * SERP レスポンスを解析して AI Overview 情報を抽出
     *
     * @param array $serp_response Bright Data SERP API の JSON デコード済みレスポンス
     * @return array {
     *     @type bool   $has_aio   AI Overview が存在するか
     *     @type string $aio_text  AI Overview のテキスト全文（結合済み）
     *     @type array  $citations 引用一覧 [{url, domain, normalized_domain, site_name, position}]
     * }
     */
    public function parse( array $serp_response ): array {
        $aio = $this->find_ai_overview( $serp_response );

        if ( $aio === null ) {
            return [
                'has_aio'   => false,
                'aio_text'  => '',
                'citations' => [],
            ];
        }

        $text      = $this->extract_text( $aio );
        $citations = $this->extract_citations( $aio );

        return [
            'has_aio'   => true,
            'aio_text'  => $text,
            'citations' => $citations,
        ];
    }

    // =========================================================
    // AI Overview の検出
    // =========================================================

    /**
     * SERP レスポンスから AI Overview セクションを探す
     *
     * Bright Data は ai_overview キーを使うが、
     * レスポンス構造の差異に備えて複数のパスを探索する。
     */
    private function find_ai_overview( array $response ): ?array {
        // パターン1: トップレベル ai_overview
        if ( ! empty( $response['ai_overview'] ) && is_array( $response['ai_overview'] ) ) {
            return $response['ai_overview'];
        }

        // パターン2: general.ai_overview
        if ( ! empty( $response['general']['ai_overview'] ) && is_array( $response['general']['ai_overview'] ) ) {
            return $response['general']['ai_overview'];
        }

        // パターン3: results 配列内に type=ai_overview
        if ( ! empty( $response['results'] ) && is_array( $response['results'] ) ) {
            foreach ( $response['results'] as $result ) {
                if ( isset( $result['type'] ) && $result['type'] === 'ai_overview' ) {
                    return $result;
                }
            }
        }

        // パターン4: search_information 内
        if ( ! empty( $response['search_information']['ai_overview'] ) ) {
            return $response['search_information']['ai_overview'];
        }

        return null;
    }

    // =========================================================
    // テキスト抽出
    // =========================================================

    /**
     * AI Overview のテキストブロックからテキストを結合
     */
    private function extract_text( array $aio ): string {
        $parts = [];

        // text_blocks パターン
        if ( ! empty( $aio['text_blocks'] ) && is_array( $aio['text_blocks'] ) ) {
            foreach ( $aio['text_blocks'] as $block ) {
                if ( ! empty( $block['snippet'] ) ) {
                    $parts[] = $block['snippet'];
                }
                // リスト型ブロック
                if ( ! empty( $block['list'] ) && is_array( $block['list'] ) ) {
                    foreach ( $block['list'] as $item ) {
                        $line = '';
                        if ( ! empty( $item['title'] ) ) {
                            $line .= $item['title'] . ': ';
                        }
                        if ( ! empty( $item['snippet'] ) ) {
                            $line .= $item['snippet'];
                        }
                        if ( $line !== '' ) {
                            $parts[] = $line;
                        }
                    }
                }
            }
        }

        // text / content / snippet 直接
        foreach ( [ 'text', 'content', 'snippet' ] as $key ) {
            if ( ! empty( $aio[ $key ] ) && is_string( $aio[ $key ] ) ) {
                $parts[] = $aio[ $key ];
            }
        }

        return implode( "\n", $parts );
    }

    // =========================================================
    // 引用抽出
    // =========================================================

    /**
     * AI Overview から引用（リファレンス）を抽出
     *
     * @return array[] [{url, domain, normalized_domain, site_name, position}]
     */
    private function extract_citations( array $aio ): array {
        $raw_refs = [];

        // パターン1: references 配列
        if ( ! empty( $aio['references'] ) && is_array( $aio['references'] ) ) {
            $raw_refs = $aio['references'];
        }
        // パターン2: sources 配列
        elseif ( ! empty( $aio['sources'] ) && is_array( $aio['sources'] ) ) {
            $raw_refs = $aio['sources'];
        }
        // パターン3: citations 配列
        elseif ( ! empty( $aio['citations'] ) && is_array( $aio['citations'] ) ) {
            $raw_refs = $aio['citations'];
        }

        $citations = [];
        $position  = 1;

        foreach ( $raw_refs as $ref ) {
            $url = $ref['link'] ?? $ref['url'] ?? $ref['href'] ?? '';
            if ( empty( $url ) ) {
                continue;
            }

            $domain    = self::extract_domain( $url );
            $site_name = $ref['source'] ?? $ref['site_name'] ?? $ref['title'] ?? $domain;

            $citations[] = [
                'url'               => $url,
                'domain'            => $domain,
                'normalized_domain' => self::normalize_domain( $domain ),
                'site_name'         => $site_name,
                'position'          => $position,
            ];

            $position++;
        }

        return $citations;
    }

    // =========================================================
    // ドメインユーティリティ
    // =========================================================

    /**
     * URL からドメインを抽出
     */
    public static function extract_domain( string $url ): string {
        $parsed = wp_parse_url( $url );
        return $parsed['host'] ?? $url;
    }

    /**
     * ドメインを正規化
     *
     * - 小文字化
     * - www. / m. プレフィックス除去
     * - 末尾スラッシュ除去
     * - http(s):// 除去
     */
    public static function normalize_domain( string $domain ): string {
        $domain = strtolower( trim( $domain ) );

        // プロトコル除去
        $domain = preg_replace( '#^https?://#', '', $domain );

        // 末尾スラッシュ・パス除去
        $domain = explode( '/', $domain )[0];

        // www. / m. プレフィックス除去
        $domain = preg_replace( '/^(www\.|m\.)/', '', $domain );

        return $domain;
    }
}
