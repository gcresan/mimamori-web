<?php
// FILE: inc/gcrev-api/modules/class-client-info-extractor-service.php

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Client_Info_Extractor_Service' ) ) { return; }

/**
 * Gcrev_Client_Info_Extractor_Service
 *
 * クライアントのホームページ URL から HTML を取得し、Gemini に分析させて
 * クライアント情報一式（業種・業態・商圏・ペルソナ・口コミアンケート生成用3項目）を推定する。
 *
 * 出力はユーザーが確認・編集してから保存する前提なので、推定精度はベストエフォート。
 * 各項目に confidence (high/medium/low) を付けて返す。
 *
 * @package Mimamori_Web
 * @since   3.2.0
 */
class Gcrev_Client_Info_Extractor_Service {

    private const FETCH_TIMEOUT     = 15;
    private const MAX_HTML_BYTES    = 1_500_000; // 1.5MB
    private const MAX_BODY_CHARS    = 6000;
    private const USER_AGENT        = 'Mozilla/5.0 (compatible; MimamoriBot/1.0; +https://mimamori-web.jp/)';

    /**
     * @param string $url ホームページ URL
     * @return array { success, suggestions?, confidence?, notes?, message, raw_excerpt? }
     */
    public function extract( string $url ): array {
        $url = esc_url_raw( $url );
        if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
            return [ 'success' => false, 'message' => 'URL の形式が正しくありません（https:// から始まる URL を指定してください）。' ];
        }

        // Step 1: HTML 取得
        $fetched = $this->fetch_html( $url );
        if ( ! $fetched['success'] ) {
            return $fetched;
        }

        // Step 2: HTML からテキストコンテンツ抽出
        $content = $this->extract_content( $fetched['html'], $url );
        if ( $content['text_length'] < 100 ) {
            return [
                'success' => false,
                'message' => 'ホームページから十分なテキストを取得できませんでした。JavaScript 主体のサイトまたはアクセス制限の可能性があります。',
            ];
        }

        // Step 3: Gemini で分析
        try {
            $suggestions = $this->analyze_with_ai( $content, $url );
        } catch ( \Throwable $e ) {
            file_put_contents(
                '/tmp/gcrev_client_extract_debug.log',
                date( 'Y-m-d H:i:s' ) . ' AI extract ERROR: ' . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return [
                'success' => false,
                'message' => 'AI 分析に失敗しました: ' . $e->getMessage(),
            ];
        }

        if ( ! is_array( $suggestions ) ) {
            return [
                'success' => false,
                'message' => 'AI 応答を解釈できませんでした。再度お試しください。',
            ];
        }

        return [
            'success'     => true,
            'url'         => $url,
            'suggestions' => $suggestions,
            'message'     => 'ホームページから情報を取得しました。内容を確認して保存してください。',
        ];
    }

    // =========================================================
    // Step 1: HTML 取得
    // =========================================================

    private function fetch_html( string $url ): array {
        $response = wp_remote_get( $url, [
            'timeout'     => self::FETCH_TIMEOUT,
            'redirection' => 5,
            'user-agent'  => self::USER_AGENT,
            'headers'     => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ja,en;q=0.9',
            ],
            'sslverify'   => false,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => 'ホームページの取得に失敗しました: ' . $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return [
                'success' => false,
                'message' => sprintf( 'ホームページから %d が返されました。URL をご確認ください。', $code ),
            ];
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( strlen( $body ) > self::MAX_HTML_BYTES ) {
            $body = substr( $body, 0, self::MAX_HTML_BYTES );
        }
        if ( $body === '' ) {
            return [ 'success' => false, 'message' => 'ホームページの本文が空でした。' ];
        }

        return [ 'success' => true, 'html' => $body ];
    }

    // =========================================================
    // Step 2: HTML からテキスト抽出
    // =========================================================

    private function extract_content( string $html, string $url ): array {
        // 文字コード正規化（UTF-8 以外対応）
        $encoding = mb_detect_encoding( $html, [ 'UTF-8', 'SJIS-win', 'SJIS', 'EUC-JP', 'ASCII' ], true );
        if ( $encoding && $encoding !== 'UTF-8' ) {
            $html = mb_convert_encoding( $html, 'UTF-8', $encoding );
        }

        // DOMDocument で解析
        $prev = libxml_use_internal_errors( true );
        $doc  = new \DOMDocument();
        @$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        $title = '';
        $titles = $doc->getElementsByTagName( 'title' );
        if ( $titles->length > 0 ) {
            $title = trim( (string) $titles->item( 0 )->textContent );
        }

        $meta_description = '';
        $meta_keywords    = '';
        $og_site_name     = '';
        $og_description   = '';
        foreach ( $doc->getElementsByTagName( 'meta' ) as $meta ) {
            $name     = strtolower( (string) $meta->getAttribute( 'name' ) );
            $property = strtolower( (string) $meta->getAttribute( 'property' ) );
            $content  = trim( (string) $meta->getAttribute( 'content' ) );
            if ( $content === '' ) { continue; }
            if ( $name === 'description' )    { $meta_description = $content; }
            if ( $name === 'keywords' )       { $meta_keywords    = $content; }
            if ( $property === 'og:site_name' ) { $og_site_name   = $content; }
            if ( $property === 'og:description' ) { $og_description = $content; }
        }

        $headings = [];
        foreach ( [ 'h1', 'h2', 'h3' ] as $tag ) {
            foreach ( $doc->getElementsByTagName( $tag ) as $el ) {
                $t = trim( preg_replace( '/\s+/u', ' ', (string) $el->textContent ) );
                if ( $t !== '' && mb_strlen( $t ) <= 200 ) {
                    $headings[] = strtoupper( $tag ) . ': ' . $t;
                }
                if ( count( $headings ) >= 40 ) { break 2; }
            }
        }

        // ナビ・メニューと思われる <a> の短いテキスト（10〜30個）
        $nav_items = [];
        foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
            $t = trim( preg_replace( '/\s+/u', ' ', (string) $a->textContent ) );
            if ( $t === '' ) { continue; }
            $len = mb_strlen( $t );
            if ( $len < 2 || $len > 20 ) { continue; }
            $nav_items[] = $t;
            if ( count( $nav_items ) >= 30 ) { break; }
        }
        $nav_items = array_unique( $nav_items );

        // 本文: script/style/nav/footer 除去して text 抽出
        $xpath = new \DOMXPath( $doc );
        foreach ( [ '//script', '//style', '//noscript', '//iframe' ] as $q ) {
            foreach ( iterator_to_array( $xpath->query( $q ) ) as $node ) {
                if ( $node->parentNode ) { $node->parentNode->removeChild( $node ); }
            }
        }

        $body_text = '';
        $body_nodes = $doc->getElementsByTagName( 'body' );
        if ( $body_nodes->length > 0 ) {
            $body_text = (string) $body_nodes->item( 0 )->textContent;
            $body_text = preg_replace( '/\s+/u', ' ', $body_text );
            $body_text = trim( (string) $body_text );
            if ( mb_strlen( $body_text ) > self::MAX_BODY_CHARS ) {
                $body_text = mb_substr( $body_text, 0, self::MAX_BODY_CHARS );
            }
        }

        return [
            'url'              => $url,
            'title'            => $title,
            'meta_description' => $meta_description,
            'meta_keywords'    => $meta_keywords,
            'og_site_name'     => $og_site_name,
            'og_description'   => $og_description,
            'headings'         => $headings,
            'nav_items'        => array_values( $nav_items ),
            'body_text'        => $body_text,
            'text_length'      => mb_strlen( $body_text ),
        ];
    }

    // =========================================================
    // Step 3: Gemini で分析
    // =========================================================

    private function analyze_with_ai( array $content, string $url ): ?array {
        if ( ! class_exists( 'Gcrev_Config' ) || ! class_exists( 'Gcrev_AI_Client' ) ) {
            throw new \Exception( 'Gcrev_AI_Client が利用できません。' );
        }
        $config = new Gcrev_Config();
        $client = new Gcrev_AI_Client( $config );

        $prompt = $this->build_prompt( $content, $url );

        $raw = (string) $client->call_gemini_api( $prompt, [
            'temperature'       => 0.3,
            'max_output_tokens' => 4096,
        ] );

        $parsed = $this->extract_json( $raw );
        if ( ! is_array( $parsed ) ) { return null; }

        return $this->normalize_suggestions( $parsed );
    }

    private function build_prompt( array $content, string $url ): string {
        // マスターデータを Gemini に提示
        $industry_master = function_exists( 'gcrev_get_industry_master' ) ? gcrev_get_industry_master() : [];
        $industry_compact = [];
        foreach ( $industry_master as $key => $cat ) {
            $industry_compact[ $key ] = [
                'label'        => $cat['label'] ?? '',
                'subcategories' => array_keys( $cat['subcategories'] ?? [] ),
            ];
        }

        $business_type_master = function_exists( 'gcrev_get_business_type_master' ) ? gcrev_get_business_type_master() : [];

        $industry_json       = wp_json_encode( $industry_compact, JSON_UNESCAPED_UNICODE );
        $business_types_json = wp_json_encode( array_keys( $business_type_master ), JSON_UNESCAPED_UNICODE );

        $headings = implode( "\n", array_slice( $content['headings'], 0, 30 ) );
        $nav      = implode( ' / ', array_slice( $content['nav_items'], 0, 30 ) );
        $body     = $content['body_text'];

        return <<<PROMPT
あなたは、企業のウェブサイトを分析してクライアント情報を推定する専門家です。
以下のホームページ情報から、指定のクライアント情報項目を JSON で返してください。

# 対象サイト
URL: {$url}
タイトル: {$content['title']}
og:site_name: {$content['og_site_name']}
meta description: {$content['meta_description']}
meta keywords: {$content['meta_keywords']}
og:description: {$content['og_description']}

## 見出し一覧（先頭30件）
{$headings}

## ナビゲーション項目（推定）
{$nav}

## 本文抜粋
{$body}

# 業種マスター（industry_category と industry_subcategory はここから選ぶ）
キー構造: category_key => { label, subcategories: [subcategory_keys] }
{$industry_json}

# ビジネス形態マスター（business_type はここから選ぶ、複数可）
{$business_types_json}

# 成長ステージ（stage はここから1つ選ぶ、該当不明なら空文字）
- launch: 立ち上げ期（開設〜半年）
- awareness: 認知拡大期（半年〜1年）
- growth: 安定成長期（1〜3年）
- mature: 成熟期（3年以上）
- renewal: リニューアル直後

# 商圏タイプ (area_type)
- nationwide: 全国
- prefecture: 都道府県単位
- city: 市区町村単位
- custom: その他テキスト

# ペルソナ選択肢（複数選択可）
- persona_age_ranges: ["teens","20s","30s","40s","50s","60plus"]
- persona_genders:    ["male","female","any"]
- persona_attributes: ["family","single","dinks","senior","student","business","owner","highincome","local","tourist"]
- persona_decision_factors: ["price","quality","speed","reviews","compare","impulse","recommend","brand","proximity","support"]

# 出力スキーマ（この JSON だけを出力、マークダウン禁止）
{
  "industry_category":    "医療・ヘルスケア等のカテゴリキー、マスター外なら空",
  "industry_subcategory": ["選択したカテゴリの subcategory キー配列、0〜3個"],
  "industry_detail":      "業種の補足説明、80字以内",
  "business_type":        ["business_type キー配列、該当するものを1〜4個"],
  "stage":                "成長ステージキーか空文字",
  "main_conversions":     "CTAボタン・問い合わせ手段から推定、カンマ区切り、100字以内（例: お問い合わせフォーム, 電話タップ, 予約フォーム）",
  "area_type":            "商圏タイプキーか空文字",
  "area_pref":            "都道府県名（例: 東京都）、不明なら空",
  "area_city":            "市区町村名、不明なら空",
  "area_custom":          "自由テキスト（area_type=custom時のみ）",
  "service_description":  "サービス内容を1〜3文で要約した文字列、500字以内",
  "strengths":            "強み・特徴を改行(\\n)区切りで3〜6項目含めた **文字列**（配列ではない）。例: \"痛みの少ない治療\\n丁寧なカウンセリング\\n駅から徒歩2分\"",
  "review_emphasis":      "このサイトで口コミを集めるとしたら引き出したい要素、100字以内の文字列",
  "persona_age_ranges":       ["推定年齢層キー配列"],
  "persona_genders":          ["推定性別キー配列"],
  "persona_attributes":       ["推定属性キー配列、0〜4個"],
  "persona_decision_factors": ["推定意思決定傾向キー配列、0〜4個"],
  "persona_one_liner":        "ターゲット像を1文で、100字以内",
  "confidence": {
    "industry":  "high|medium|low",
    "area":      "high|medium|low",
    "strengths": "high|medium|low",
    "persona":   "high|medium|low"
  },
  "notes": "推定の根拠や注意点を短く（100字以内）"
}

# 厳守事項
- industry_category / industry_subcategory / business_type / stage / area_type / persona_* は **必ずマスターのキーから選ぶ**。日本語ラベルや自作のキーを入れない
- industry_subcategory は industry_category に属するキーからのみ選ぶ
- 根拠に乏しい項目は空配列 [] や空文字 "" にする（推測で埋めない）
- service_description・strengths・review_emphasis は、そのまま使える自然な日本語で
- 企業名・個人名・電話番号・住所等の個人情報は応答本文に含めない（area_pref/area_city の公開情報を除く）
PROMPT;
    }

    private function extract_json( string $text ): ?array {
        $text = trim( $text );
        if ( $text === '' ) { return null; }
        $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
        $text = preg_replace( '/\s*```$/i', '', $text );
        $text = trim( (string) $text );
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        if ( $start === false || $end === false || $end <= $start ) { return null; }
        $json = substr( $text, $start, $end - $start + 1 );
        $parsed = json_decode( $json, true );
        return is_array( $parsed ) ? $parsed : null;
    }

    /**
     * AI 応答をマスターキー照合してサニタイズする。
     */
    private function normalize_suggestions( array $parsed ): array {
        $industry_master      = function_exists( 'gcrev_get_industry_master' ) ? gcrev_get_industry_master() : [];
        $business_type_master = function_exists( 'gcrev_get_business_type_master' ) ? gcrev_get_business_type_master() : [];

        $valid_category_keys = array_keys( $industry_master );
        $valid_stages        = [ 'launch', 'awareness', 'growth', 'mature', 'renewal' ];
        $valid_area_types    = [ 'nationwide', 'prefecture', 'city', 'custom' ];
        $valid_business      = array_keys( $business_type_master );

        $valid_persona = [
            'persona_age_ranges'        => [ 'teens','20s','30s','40s','50s','60plus' ],
            'persona_genders'           => [ 'male','female','any' ],
            'persona_attributes'        => [ 'family','single','dinks','senior','student','business','owner','highincome','local','tourist' ],
            'persona_decision_factors'  => [ 'price','quality','speed','reviews','compare','impulse','recommend','brand','proximity','support' ],
        ];

        $out = [
            'industry_category'       => '',
            'industry_subcategory'    => [],
            'industry_detail'         => '',
            'business_type'           => [],
            'stage'                   => '',
            'main_conversions'        => '',
            'area_type'               => '',
            'area_pref'               => '',
            'area_city'               => '',
            'area_custom'             => '',
            'service_description'     => '',
            'strengths'               => '',
            'review_emphasis'         => '',
            'persona_age_ranges'      => [],
            'persona_genders'         => [],
            'persona_attributes'      => [],
            'persona_decision_factors'=> [],
            'persona_one_liner'       => '',
            'confidence'              => [],
            'notes'                   => '',
        ];

        $cat = (string) ( $parsed['industry_category'] ?? '' );
        if ( in_array( $cat, $valid_category_keys, true ) ) {
            $out['industry_category'] = $cat;

            $valid_subs = array_keys( $industry_master[ $cat ]['subcategories'] ?? [] );
            $subs_raw   = is_array( $parsed['industry_subcategory'] ?? null ) ? $parsed['industry_subcategory'] : [];
            $subs_clean = [];
            foreach ( $subs_raw as $s ) {
                if ( is_string( $s ) && in_array( $s, $valid_subs, true ) ) {
                    $subs_clean[] = $s;
                }
            }
            $out['industry_subcategory'] = array_slice( array_unique( $subs_clean ), 0, 3 );
        }

        $out['industry_detail'] = $this->clip_text( $parsed['industry_detail'] ?? '', 160 );

        $bt_raw = is_array( $parsed['business_type'] ?? null ) ? $parsed['business_type'] : [];
        $bt_clean = [];
        foreach ( $bt_raw as $b ) {
            if ( is_string( $b ) && in_array( $b, $valid_business, true ) ) {
                $bt_clean[] = $b;
            }
        }
        $out['business_type'] = array_values( array_unique( $bt_clean ) );

        $stage = (string) ( $parsed['stage'] ?? '' );
        $out['stage'] = in_array( $stage, $valid_stages, true ) ? $stage : '';

        $out['main_conversions'] = $this->clip_text( $parsed['main_conversions'] ?? '', 300 );

        $at = (string) ( $parsed['area_type'] ?? '' );
        $out['area_type']   = in_array( $at, $valid_area_types, true ) ? $at : '';
        $out['area_pref']   = $this->clip_text( $parsed['area_pref'] ?? '', 40 );
        $out['area_city']   = $this->clip_text( $parsed['area_city'] ?? '', 80 );
        $out['area_custom'] = $this->clip_text( $parsed['area_custom'] ?? '', 200 );

        $out['service_description'] = $this->clip_text( $this->to_multiline_string( $parsed['service_description'] ?? '' ), 1000, true );
        $out['strengths']           = $this->clip_text( $this->to_multiline_string( $parsed['strengths'] ?? '' ), 1000, true );
        $out['review_emphasis']     = $this->clip_text( $this->to_multiline_string( $parsed['review_emphasis'] ?? '' ), 500, true );

        foreach ( $valid_persona as $key => $valid_list ) {
            $raw = is_array( $parsed[ $key ] ?? null ) ? $parsed[ $key ] : [];
            $clean = [];
            foreach ( $raw as $v ) {
                if ( is_string( $v ) && in_array( $v, $valid_list, true ) ) {
                    $clean[] = $v;
                }
            }
            $out[ $key ] = array_values( array_unique( $clean ) );
        }

        $out['persona_one_liner'] = $this->clip_text( $parsed['persona_one_liner'] ?? '', 200 );

        if ( is_array( $parsed['confidence'] ?? null ) ) {
            $out['confidence'] = array_filter( [
                'industry'  => in_array( $parsed['confidence']['industry']  ?? '', [ 'high','medium','low' ], true ) ? $parsed['confidence']['industry']  : null,
                'area'      => in_array( $parsed['confidence']['area']      ?? '', [ 'high','medium','low' ], true ) ? $parsed['confidence']['area']      : null,
                'strengths' => in_array( $parsed['confidence']['strengths'] ?? '', [ 'high','medium','low' ], true ) ? $parsed['confidence']['strengths'] : null,
                'persona'   => in_array( $parsed['confidence']['persona']   ?? '', [ 'high','medium','low' ], true ) ? $parsed['confidence']['persona']   : null,
            ] );
        }

        $out['notes'] = $this->clip_text( $parsed['notes'] ?? '', 300 );

        return $out;
    }

    private function clip_text( $text, int $max_len, bool $multiline = false ): string {
        if ( ! is_string( $text ) ) { return ''; }
        $text = $multiline ? trim( $text ) : trim( preg_replace( '/\s+/u', ' ', $text ) );
        if ( mb_strlen( $text ) > $max_len ) {
            $text = mb_substr( $text, 0, $max_len );
        }
        return $text;
    }

    /**
     * AI 応答が文字列でも配列でも受け取れるようにする。
     * 配列の場合は空要素を除いて改行結合する。
     */
    private function to_multiline_string( $value ): string {
        if ( is_string( $value ) ) { return $value; }
        if ( is_array( $value ) ) {
            $lines = [];
            foreach ( $value as $item ) {
                if ( is_string( $item ) ) {
                    $s = trim( $item );
                    if ( $s !== '' ) { $lines[] = $s; }
                } elseif ( is_array( $item ) ) {
                    // [{ "title": "...", "description": "..." }] 形式にも最低限対応
                    $bits = [];
                    foreach ( $item as $v ) {
                        if ( is_string( $v ) && trim( $v ) !== '' ) { $bits[] = trim( $v ); }
                    }
                    if ( ! empty( $bits ) ) { $lines[] = implode( ' — ', $bits ); }
                }
            }
            return implode( "\n", $lines );
        }
        return '';
    }
}
