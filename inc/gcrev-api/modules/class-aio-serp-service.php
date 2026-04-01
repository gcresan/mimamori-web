<?php
// FILE: inc/gcrev-api/modules/class-aio-serp-service.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Gcrev_AIO_Serp_Service' ) ) { return; }

/**
 * AIO SERP サービス（オーケストレーター）
 *
 * Bright Data SERP 取得 → パース → 保存 → 集計を統合するサービスクラス。
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Gcrev_AIO_Serp_Service {

    private const LOG_FILE = '/tmp/gcrev_aio_serp_debug.log';

    /** @var Gcrev_Config */
    private $config;

    /** @var Gcrev_Brightdata_Serp_Client */
    private $client;

    /** @var Gcrev_AIO_Serp_Parser */
    private $parser;

    /** @var Gcrev_AIO_Serp_Aggregator */
    private $aggregator;

    // =========================================================
    // コンストラクタ
    // =========================================================

    public function __construct( Gcrev_Config $config ) {
        $this->config     = $config;
        $this->client     = new Gcrev_Brightdata_Serp_Client();
        $this->parser     = new Gcrev_AIO_Serp_Parser();
        $this->aggregator = new Gcrev_AIO_Serp_Aggregator();
    }

    // =========================================================
    // 取得・保存
    // =========================================================

    /**
     * 1キーワード分の AIO SERP を取得して保存
     *
     * @param int         $user_id         ユーザーID
     * @param int         $keyword_id      gcrev_rank_keywords.id
     * @param string|null $device_override 'desktop'|'mobile'|null
     * @return array { status: string, message: string }
     */
    public function fetch_and_store( int $user_id, int $keyword_id, ?string $device_override = null ): array {
        global $wpdb;
        $table_kw   = $wpdb->prefix . 'gcrev_rank_keywords';
        $table_serp = $wpdb->prefix . 'gcrev_aio_serp_results';

        // キーワード情報取得
        $kw = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, keyword, location_code, language_code FROM {$table_kw} WHERE id = %d AND user_id = %d",
            $keyword_id, $user_id
        ), ARRAY_A );

        if ( ! $kw ) {
            return [ 'status' => 'error', 'message' => 'Keyword not found' ];
        }

        // ユーザーの SERP 設定を取得
        $serp_settings = $this->get_serp_settings( $user_id );

        $options = [
            'region'   => $serp_settings['region'],
            'language' => $serp_settings['language'],
            'device'   => $device_override ?? $serp_settings['device'],
        ];

        self::log( "Fetching SERP for user={$user_id}, kw_id={$keyword_id}, keyword='{$kw['keyword']}'" );

        // Bright Data SERP API 呼び出し
        $result = $this->client->fetch_serp( $kw['keyword'], $options );

        $now = current_time( 'mysql' );

        if ( ! $result['success'] ) {
            // API 失敗
            self::log( "FAILED: {$result['error']}" );
            $this->save_result( $table_serp, [
                'user_id'    => $user_id,
                'keyword_id' => $keyword_id,
                'keyword'    => $kw['keyword'],
                'fetched_at' => $now,
                'region'     => $options['region'],
                'language'   => $options['language'],
                'device'     => $options['device'],
                'status'     => 'failed',
                'aio_text'   => null,
                'citations'  => null,
                'self_found' => 0,
                'self_count' => 0,
                'self_exposure' => 0,
                'raw_response' => wp_json_encode( [ 'error' => $result['error'] ], JSON_UNESCAPED_UNICODE ),
                'created_at' => $now,
            ] );
            return [ 'status' => 'failed', 'message' => $result['error'] ?? 'API error' ];
        }

        // パース
        $parsed = $this->parser->parse( $result['data'] );

        if ( ! $parsed['has_aio'] ) {
            // AIO なし（正常結果）
            self::log( "no_aio for keyword='{$kw['keyword']}'" );
            $this->save_result( $table_serp, [
                'user_id'    => $user_id,
                'keyword_id' => $keyword_id,
                'keyword'    => $kw['keyword'],
                'fetched_at' => $now,
                'region'     => $options['region'],
                'language'   => $options['language'],
                'device'     => $options['device'],
                'status'     => 'no_aio',
                'aio_text'   => null,
                'citations'  => wp_json_encode( [], JSON_UNESCAPED_UNICODE ),
                'self_found' => 0,
                'self_count' => 0,
                'self_exposure' => 0,
                'raw_response' => wp_json_encode( $result['data'], JSON_UNESCAPED_UNICODE ),
                'created_at' => $now,
            ] );
            return [ 'status' => 'no_aio', 'message' => 'AI Overview not present' ];
        }

        // AIO あり — 自社判定
        $self_domains = $this->get_self_domains( $user_id );
        $kw_agg       = $this->aggregator->aggregate_keyword( $parsed['citations'], $self_domains );

        self::log( "success for keyword='{$kw['keyword']}': citations=" . count( $parsed['citations'] ) . ", self_found=" . ( $kw_agg['self_found'] ? 'yes' : 'no' ) );

        $this->save_result( $table_serp, [
            'user_id'       => $user_id,
            'keyword_id'    => $keyword_id,
            'keyword'       => $kw['keyword'],
            'fetched_at'    => $now,
            'region'        => $options['region'],
            'language'      => $options['language'],
            'device'        => $options['device'],
            'status'        => 'success',
            'aio_text'      => mb_substr( $parsed['aio_text'], 0, 10000 ),
            'citations'     => wp_json_encode( $parsed['citations'], JSON_UNESCAPED_UNICODE ),
            'self_found'    => $kw_agg['self_found'] ? 1 : 0,
            'self_count'    => $kw_agg['self_count'],
            'self_exposure' => $kw_agg['self_exposure'],
            'raw_response'  => wp_json_encode( $result['data'], JSON_UNESCAPED_UNICODE ),
            'created_at'    => $now,
        ] );

        return [ 'status' => 'success', 'message' => 'AIO found, ' . count( $parsed['citations'] ) . ' citations' ];
    }

    /**
     * ユーザーの全 AIO 有効キーワードを取得・保存
     *
     * @param int    $user_id
     * @param string $device_override 'desktop'|'mobile'|null（null時はユーザー設定を使用）
     * @return array { processed: int, results: array }
     */
    public function fetch_all_keywords( int $user_id, ?string $device_override = null ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_rank_keywords';

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword FROM {$table} WHERE user_id = %d AND aio_enabled = 1 AND enabled = 1 ORDER BY sort_order ASC",
            $user_id
        ), ARRAY_A );

        if ( empty( $keywords ) ) {
            return [ 'processed' => 0, 'results' => [] ];
        }

        @ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $results = [];
        foreach ( $keywords as $kw ) {
            $r = $this->fetch_and_store( $user_id, (int) $kw['id'], $device_override );
            $results[] = [
                'keyword_id' => (int) $kw['id'],
                'keyword'    => $kw['keyword'],
                'status'     => $r['status'],
                'message'    => $r['message'],
            ];
            // キーワード間で 3 秒待機（レート制限対策）
            sleep( 3 );
        }

        return [ 'processed' => count( $results ), 'results' => $results ];
    }

    // =========================================================
    // データ取得（表示用）
    // =========================================================

    /**
     * サマリー取得（スコア・カバレッジ・最終取得日等）
     */
    public function get_summary( int $user_id ): array {
        $keyword_results = $this->get_latest_results( $user_id );
        $self_domains    = $this->get_self_domains( $user_id );
        $aggregated      = $this->aggregator->aggregate_all( $keyword_results, $self_domains );

        // 最終取得日
        $last_fetched = $this->get_last_fetched( $user_id );

        return [
            'self_score'          => $aggregated['self_score'],
            'self_coverage'       => $aggregated['self_coverage'],
            'self_total_exposure' => $aggregated['self_total_exposure'],
            'self_keyword_count'  => $aggregated['self_keyword_count'],
            'aio_keyword_count'   => $aggregated['aio_keyword_count'],
            'total_keyword_count' => $aggregated['total_keyword_count'],
            'self_rank'           => $aggregated['self_rank'],
            'last_fetched'        => $last_fetched,
        ];
    }

    /**
     * 上位露出ドメインランキング
     */
    public function get_rankings( int $user_id ): array {
        $keyword_results = $this->get_latest_results( $user_id );
        $self_domains    = $this->get_self_domains( $user_id );
        $aggregated      = $this->aggregator->aggregate_all( $keyword_results, $self_domains );

        return $aggregated['rankings'] ?? [];
    }

    /**
     * キーワード別詳細一覧
     */
    public function get_keyword_details( int $user_id ): array {
        $keyword_results = $this->get_latest_results( $user_id );
        $self_domains    = $this->get_self_domains( $user_id );
        $aggregated      = $this->aggregator->aggregate_all( $keyword_results, $self_domains );

        return $aggregated['keyword_details'] ?? [];
    }

    /**
     * 単一キーワードの詳細
     */
    public function get_keyword_detail( int $keyword_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_aio_serp_results';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE keyword_id = %d ORDER BY fetched_at DESC LIMIT 1",
            $keyword_id
        ), ARRAY_A );

        if ( ! $row ) {
            return [ 'status' => 'not_found' ];
        }

        $citations   = json_decode( $row['citations'] ?? '[]', true ) ?: [];
        $self_domains = $this->get_self_domains( (int) $row['user_id'] );
        $kw_agg       = $this->aggregator->aggregate_keyword( $citations, $self_domains );

        return [
            'keyword_id'    => (int) $row['keyword_id'],
            'keyword'       => $row['keyword'],
            'status'        => $row['status'],
            'fetched_at'    => $row['fetched_at'],
            'aio_text'      => $row['aio_text'] ?? '',
            'citations'     => $citations,
            'domains'       => $kw_agg['domains'],
            'self_found'    => (bool) $row['self_found'],
            'self_count'    => (int) $row['self_count'],
            'self_exposure'  => (int) $row['self_exposure'],
        ];
    }

    // =========================================================
    // AIコメント生成
    // =========================================================

    /**
     * 集計データをもとに Gemini で AIコメントを生成
     */
    public function generate_ai_comment( int $user_id ): array {
        $keyword_results = $this->get_latest_results( $user_id );
        $self_domains    = $this->get_self_domains( $user_id );
        $aggregated      = $this->aggregator->aggregate_all( $keyword_results, $self_domains );
        $payload         = $this->aggregator->build_ai_comment_payload( $aggregated );

        if ( $aggregated['aio_keyword_count'] === 0 ) {
            return [
                'comment' => 'AIO が表示されたキーワードがまだありません。データが蓄積されるまでお待ちください。',
                'payload' => $payload,
            ];
        }

        // Gemini / AI Client が利用可能か確認
        if ( ! class_exists( 'Gcrev_AI_Client' ) ) {
            return [
                'comment' => 'AI コメント生成機能が利用できません。',
                'payload' => $payload,
            ];
        }

        $ai_client = new Gcrev_AI_Client( $this->config );

        $prompt = $this->build_comment_prompt( $payload );

        try {
            $raw = $ai_client->call_gemini_api( $prompt, [
                'temperature'       => 0.7,
                'max_output_tokens' => 1024,
            ] );

            return [
                'comment' => ! empty( $raw ) ? $raw : 'コメント生成に失敗しました。',
                'payload' => $payload,
            ];
        } catch ( \Exception $e ) {
            self::log( "AI comment error: " . $e->getMessage() );
            return [
                'comment' => 'AI コメントの生成中にエラーが発生しました。',
                'payload' => $payload,
            ];
        }
    }

    // =========================================================
    // 全体認識サマリー生成
    // =========================================================

    /**
     * AIから見たサイト全体の認識サマリーを生成（Gemini）
     *
     * Transient キャッシュ（24h）あり。データ不足時はフォールバック。
     */
    public function generate_recognition_summary( int $user_id ): array {
        // キャッシュチェック
        $cache_key = "gcrev_aio_recog_{$user_id}";
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        // データ収集
        $keyword_results = $this->get_latest_results( $user_id );
        $self_domains    = $this->get_self_domains( $user_id );
        $aggregated      = $this->aggregator->aggregate_all( $keyword_results, $self_domains );
        $payload         = $this->aggregator->build_ai_comment_payload( $aggregated );

        // データ不足チェック
        if ( ( $aggregated['aio_keyword_count'] ?? 0 ) === 0 ) {
            return [
                'status'  => 'no_data',
                'message' => 'まだAIOデータが取得されていません。上部のボタンからデータを取得すると、AIから見たサイトの認識傾向を表示します。',
            ];
        }

        // 改善提案データも収集（利用可能なら）
        $improvements = $this->get_improvement_suggestions( $user_id );
        $gaps_summary = [];
        if ( ( $improvements['status'] ?? '' ) === 'complete' ) {
            $gaps = $improvements['data']['gaps'] ?? [];
            foreach ( array_slice( $gaps, 0, 5 ) as $g ) {
                $gaps_summary[] = $g['title'] ?? '';
            }
        }

        // サイト情報
        $site_url  = get_user_meta( $user_id, 'gcrev_client_site_url', true ) ?: '';
        $site_name = get_user_meta( $user_id, 'gcrev_client_company', true ) ?: '';

        // Gemini で構造化サマリー生成
        if ( ! class_exists( 'Gcrev_AI_Client' ) ) {
            return $this->build_fallback_summary( $payload, $gaps_summary );
        }

        $ai_client = new Gcrev_AI_Client( $this->config );
        $prompt    = $this->build_recognition_prompt( $payload, $gaps_summary, $site_name, $site_url );

        try {
            $raw = $ai_client->call_gemini_api( $prompt, [
                'temperature'       => 0.5,
                'max_output_tokens' => 1024,
            ] );

            $parsed = $this->parse_recognition_json( $raw );
            if ( $parsed ) {
                $result = [
                    'status' => 'ok',
                    'data'   => $parsed,
                ];
                set_transient( $cache_key, $result, DAY_IN_SECONDS );
                return $result;
            }

            // JSON パース失敗 → フォールバック
            self::log( "Recognition summary JSON parse failed, raw=" . substr( $raw, 0, 500 ) );
            $fallback = $this->build_fallback_summary( $payload, $gaps_summary );
            set_transient( $cache_key, $fallback, 6 * HOUR_IN_SECONDS );
            return $fallback;

        } catch ( \Exception $e ) {
            self::log( "Recognition summary error: " . $e->getMessage() );
            $fallback = $this->build_fallback_summary( $payload, $gaps_summary );
            set_transient( $cache_key, $fallback, 6 * HOUR_IN_SECONDS );
            return $fallback;
        }
    }

    /**
     * Gemini 向けプロンプト（構造化JSON出力を要求）
     */
    private function build_recognition_prompt( array $payload, array $gaps_summary, string $site_name, string $site_url ): string {
        $json_payload = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        $gaps_text    = ! empty( $gaps_summary ) ? implode( "\n- ", $gaps_summary ) : 'なし';
        $site_info    = '';
        if ( $site_name ) {
            $site_info .= "サイト名: {$site_name}\n";
        }
        if ( $site_url ) {
            $site_info .= "URL: {$site_url}\n";
        }

        return <<<PROMPT
あなたはWebマーケティングの専門家です。以下は、あるウェブサイトのGoogle AI Overview（AIO）での露出状況データです。

{$site_info}
## AIO露出データ
```json
{$json_payload}
```

## 検出された改善課題
- {$gaps_text}

このデータをもとに、「AIがこのサイトをどう認識しているか」の全体サマリーを、以下のJSON形式で出力してください。

```json
{
  "recognition": "AIがこのサイトをどういうサイトとして見ているかの要約（1〜2文、自然な日本語）",
  "strengths": ["強み1", "強み2", "強み3"],
  "weaknesses": ["課題1", "課題2", "課題3"],
  "next_actions": ["次にやるべきこと1", "次にやるべきこと2", "次にやるべきこと3"]
}
```

ルール:
- 各配列は2〜4項目
- 各項目は1文（20〜60文字程度）で簡潔に
- 専門用語を避け、初心者でも理解できる平易な日本語で
- 「現時点の計測データから見ると」のように、断定しすぎない控えめな表現で
- strong_keywords にあるテーマを「強み」に、weak_keywords にあるテーマを「課題」に反映
- 出力はJSONのみ（前後の説明文は不要）
PROMPT;
    }

    /**
     * Gemini レスポンスから JSON オブジェクトを抽出・パース
     */
    private function parse_recognition_json( string $raw ): ?array {
        $text = trim( $raw );
        // コードフェンス除去
        $text = preg_replace( '/```(?:json)?\s*/i', '', $text );
        $text = str_replace( '```', '', $text );
        $text = trim( $text );

        // 最初の { から最後の } を抽出
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        if ( $start === false || $end === false || $end <= $start ) {
            return null;
        }

        $json = substr( $text, $start, $end - $start + 1 );
        $data = json_decode( $json, true );

        if ( ! is_array( $data ) ) {
            return null;
        }

        // 必須フィールド検証
        $required = [ 'recognition', 'strengths', 'weaknesses', 'next_actions' ];
        foreach ( $required as $key ) {
            if ( ! isset( $data[ $key ] ) ) {
                return null;
            }
        }

        // サニタイズ
        return [
            'recognition'  => sanitize_text_field( $data['recognition'] ),
            'strengths'    => array_map( 'sanitize_text_field', array_slice( (array) $data['strengths'], 0, 4 ) ),
            'weaknesses'   => array_map( 'sanitize_text_field', array_slice( (array) $data['weaknesses'], 0, 4 ) ),
            'next_actions' => array_map( 'sanitize_text_field', array_slice( (array) $data['next_actions'], 0, 4 ) ),
        ];
    }

    /**
     * Gemini 利用不可・失敗時のフォールバックサマリー
     */
    private function build_fallback_summary( array $payload, array $gaps_summary ): array {
        $strong = $payload['strong_keywords'] ?? [];
        $weak   = $payload['weak_keywords'] ?? [];
        $score  = $payload['self_score'] ?? 0;

        $recognition = '現時点の計測データをもとにした暫定的な要約です。';
        if ( $score > 0 ) {
            $recognition = "AIO露出スコアは{$score}点で、一部のキーワードでAI検索結果に表示されています。";
        }

        $strengths = [];
        if ( ! empty( $strong ) ) {
            $strengths[] = '「' . implode( '」「', array_slice( $strong, 0, 3 ) ) . '」のテーマでAIOに引用されています';
        }
        $coverage = $payload['self_coverage'] ?? 0;
        if ( $coverage > 0 ) {
            $strengths[] = "設定キーワードの{$coverage}%でAI検索結果に露出しています";
        }
        if ( empty( $strengths ) ) {
            $strengths[] = '今後の計測蓄積により、より正確な傾向が見えるようになります';
        }

        $weaknesses = [];
        if ( ! empty( $weak ) ) {
            $weaknesses[] = '「' . implode( '」「', array_slice( $weak, 0, 3 ) ) . '」のテーマではまだAIOに表示されていません';
        }
        if ( ! empty( $gaps_summary ) ) {
            foreach ( array_slice( $gaps_summary, 0, 2 ) as $g ) {
                if ( $g ) {
                    $weaknesses[] = $g;
                }
            }
        }
        if ( empty( $weaknesses ) ) {
            $weaknesses[] = '十分な計測データが蓄積されると、課題が明確になります';
        }

        $next_actions = [];
        if ( ! empty( $weak ) ) {
            $next_actions[] = '「' . ( $weak[0] ?? '' ) . '」に関する専用ページの充実を検討する';
        }
        if ( ! empty( $gaps_summary ) ) {
            foreach ( array_slice( $gaps_summary, 0, 2 ) as $g ) {
                if ( $g ) {
                    $next_actions[] = $g;
                }
            }
        }
        if ( empty( $next_actions ) ) {
            $next_actions[] = 'まずはデータの蓄積を続け、傾向を把握しましょう';
        }

        return [
            'status' => 'fallback',
            'data'   => [
                'recognition'  => $recognition,
                'strengths'    => $strengths,
                'weaknesses'   => $weaknesses,
                'next_actions' => $next_actions,
            ],
        ];
    }

    // =========================================================
    // 競合ページ分析 & 改善提案
    // =========================================================

    /**
     * 競合ページ分析を実行（バックグラウンドジョブ用）
     *
     * SERP結果から引用URLを収集 → クロール → 解析 → 差分分析 → 結果保存
     */
    public function analyze_competitor_pages( int $user_id ): array {
        if ( ! class_exists( 'Gcrev_AIO_Page_Analyzer' ) || ! class_exists( 'Gcrev_AIO_Gap_Analyzer' ) ) {
            self::log( "Page analyzer classes not available" );
            update_user_meta( $user_id, 'gcrev_aio_analysis_status', 'failed' );
            return [ 'success' => false, 'message' => 'Analyzer classes not available' ];
        }

        update_user_meta( $user_id, 'gcrev_aio_analysis_status', 'analyzing' );
        self::log( "Starting competitor page analysis for user_id={$user_id}" );

        $page_analyzer = new Gcrev_AIO_Page_Analyzer();
        $gap_analyzer  = new Gcrev_AIO_Gap_Analyzer();

        $keyword_results = $this->get_latest_results( $user_id );
        $self_domains    = $this->get_self_domains( $user_id );
        $site_url        = get_user_meta( $user_id, 'gcrev_client_site_url', true );

        $keyword_gap_results = [];

        foreach ( $keyword_results as $kr ) {
            if ( ( $kr['status'] ?? '' ) !== 'success' ) {
                continue;
            }

            $keyword   = $kr['keyword'] ?? '';
            $citations = $kr['citations'] ?? [];

            if ( empty( $citations ) ) {
                continue;
            }

            // 競合URLの上位5件をクロール・解析
            $competitor_analyses = [];
            $competitor_relevance = [];
            $urls_done = [];
            foreach ( array_slice( $citations, 0, 5 ) as $cite ) {
                $url = $cite['url'] ?? '';
                if ( empty( $url ) || isset( $urls_done[ $url ] ) ) {
                    continue;
                }

                // 自社URLはスキップ
                $cite_domain = Gcrev_AIO_Serp_Parser::normalize_domain( $cite['domain'] ?? '' );
                if ( in_array( $cite_domain, $self_domains, true ) ) {
                    continue;
                }

                $urls_done[ $url ] = true;
                $analysis = $page_analyzer->fetch_and_analyze( $url );
                $competitor_analyses[] = $analysis;

                // 競合のキーワード関連性を分析
                $relevance = $page_analyzer->analyze_keyword_relevance( $analysis, $keyword );
                $competitor_relevance[ $url ] = $relevance;

                $page_analyzer->wait();
            }

            // 自社ページの特定と解析
            // GSC で該当キーワードのランディングページを探す
            $self_page_url = $this->find_self_page_for_keyword( $user_id, $keyword, $site_url );
            $self_analysis = null;
            $self_relevance = null;

            if ( ! empty( $self_page_url ) ) {
                $self_analysis = $page_analyzer->fetch_and_analyze( $self_page_url );
                if ( ( $self_analysis['fetch_status'] ?? '' ) !== 'success' ) {
                    $self_analysis = null;
                } else {
                    $self_relevance = $page_analyzer->analyze_keyword_relevance( $self_analysis, $keyword );
                }
            }

            // 差分分析（キーワード関連性データを含む）
            $gap_result = $gap_analyzer->analyze_gaps(
                $competitor_analyses, $self_analysis, $keyword,
                $competitor_relevance, $self_relevance
            );
            $keyword_gap_results[] = $gap_result;

            self::log( "Keyword '{$keyword}': " . count( $competitor_analyses ) . " competitors analyzed, " . count( $gap_result['gaps'] ) . " gaps found" );
        }

        // 全キーワード集約
        $aggregated = $gap_analyzer->aggregate_all_keywords( $keyword_gap_results );

        // 結果保存
        $now = current_time( 'mysql' );
        update_user_meta( $user_id, 'gcrev_aio_analysis_result', wp_json_encode( $aggregated, JSON_UNESCAPED_UNICODE ) );
        update_user_meta( $user_id, 'gcrev_aio_analysis_updated_at', $now );
        update_user_meta( $user_id, 'gcrev_aio_analysis_status', 'complete' );

        self::log( "Analysis complete for user_id={$user_id}: " . count( $aggregated['gaps'] ?? [] ) . " total gaps" );

        return [ 'success' => true, 'gaps_count' => count( $aggregated['gaps'] ?? [] ) ];
    }

    /**
     * 改善提案データを取得（キャッシュ済み結果を返す）
     */
    public function get_improvement_suggestions( int $user_id ): array {
        $status = get_user_meta( $user_id, 'gcrev_aio_analysis_status', true ) ?: 'not_started';

        if ( $status === 'analyzing' ) {
            return [ 'status' => 'analyzing' ];
        }

        if ( $status === 'failed' ) {
            return [ 'status' => 'failed' ];
        }

        if ( $status !== 'complete' ) {
            return [ 'status' => 'not_started' ];
        }

        $result_json = get_user_meta( $user_id, 'gcrev_aio_analysis_result', true );
        $result      = json_decode( $result_json, true );

        if ( ! is_array( $result ) ) {
            return [ 'status' => 'not_started' ];
        }

        $updated_at = get_user_meta( $user_id, 'gcrev_aio_analysis_updated_at', true );

        return [
            'status'     => 'complete',
            'data'       => $result,
            'updated_at' => $updated_at,
        ];
    }

    // =========================================================
    // 内部ヘルパー
    // =========================================================

    /**
     * キーワードに対応する自社ページを特定
     *
     * 1. GSC データからこのキーワードで最もインプレッションのあるURLを探す
     * 2. 見つからなければ site_url（トップページ）を返す
     */
    private function find_self_page_for_keyword( int $user_id, string $keyword, string $site_url ): string {
        // GSC からの特定を試行
        try {
            if ( class_exists( 'Gcrev_GSC_Fetcher' ) ) {
                $gsc = new Gcrev_GSC_Fetcher( $this->config );
                $tz  = wp_timezone();
                $end   = new \DateTimeImmutable( 'now', $tz );
                $start = $end->modify( '-90 days' );

                $gsc_data = $gsc->fetch_gsc_data(
                    $site_url,
                    $start->format( 'Y-m-d' ),
                    $end->format( 'Y-m-d' ),
                    'page',
                    $keyword
                );

                // pages キーから最もインプレッションの多いURLを取得
                $pages = $gsc_data['pages'] ?? $gsc_data['rows'] ?? [];
                if ( ! empty( $pages ) ) {
                    // インプレッション順ソート
                    usort( $pages, function ( $a, $b ) {
                        return ( $b['impressions'] ?? $b['_impressions'] ?? 0 ) <=> ( $a['impressions'] ?? $a['_impressions'] ?? 0 );
                    } );
                    $best_url = $pages[0]['page'] ?? $pages[0]['keys'][0] ?? '';
                    if ( ! empty( $best_url ) ) {
                        self::log( "GSC found self page for '{$keyword}': {$best_url}" );
                        return $best_url;
                    }
                }
            }
        } catch ( \Throwable $e ) {
            self::log( "GSC lookup failed for '{$keyword}': " . $e->getMessage() );
        }

        // フォールバック: サイトのトップページ
        return $site_url ?: '';
    }

    /**
     * ユーザーの自社判定ドメインを取得（正規化済み）
     *
     * クライアント設定の site_url を常に自動で含め、
     * 手動設定（gcrev_aio_self_domains）は追加ドメインとして扱う。
     */
    public function get_self_domains( int $user_id ): array {
        $domains = [];

        // 1. クライアント設定の site_url を自動で含める
        $site_url = get_user_meta( $user_id, 'gcrev_client_site_url', true );
        if ( ! empty( $site_url ) ) {
            $domain = Gcrev_AIO_Serp_Parser::normalize_domain(
                Gcrev_AIO_Serp_Parser::extract_domain( $site_url )
            );
            if ( $domain ) {
                $domains[] = $domain;
            }
        }

        // 2. 手動設定の追加ドメイン（改行区切り）
        $raw = get_user_meta( $user_id, 'gcrev_aio_self_domains', true );
        if ( ! empty( $raw ) ) {
            $lines = preg_split( '/[\r\n]+/', $raw );
            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( $line !== '' ) {
                    $domains[] = Gcrev_AIO_Serp_Parser::normalize_domain( $line );
                }
            }
        }

        return array_unique( array_filter( $domains ) );
    }

    /**
     * SERP 取得設定を取得
     */
    private function get_serp_settings( int $user_id ): array {
        return [
            'region'   => get_user_meta( $user_id, 'gcrev_aio_serp_region', true ) ?: 'jp',
            'language' => get_user_meta( $user_id, 'gcrev_aio_serp_language', true ) ?: 'ja',
            'device'   => get_user_meta( $user_id, 'gcrev_aio_serp_device', true ) ?: 'desktop',
        ];
    }

    /**
     * ユーザーの全 AIO キーワードの最新結果を取得
     *
     * AIO は Google が出したり出さなかったりするため、
     * 直近7日間で success 結果があればそちらを優先する。
     * success がなければ最新の結果（no_aio / failed）を使用する。
     */
    private function get_latest_results( int $user_id ): array {
        global $wpdb;
        $table_serp = $wpdb->prefix . 'gcrev_aio_serp_results';

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

        // 各キーワードについて:
        // 1. 直近7日間の success 結果の最新を優先
        // 2. なければ最新の結果を使用
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*
             FROM {$table_serp} s
             INNER JOIN (
                 SELECT keyword_id,
                        COALESCE(
                            (SELECT MAX(fetched_at) FROM {$table_serp}
                             WHERE user_id = %d AND keyword_id = t.keyword_id
                               AND status = 'success' AND fetched_at > %s),
                            (SELECT MAX(fetched_at) FROM {$table_serp}
                             WHERE user_id = %d AND keyword_id = t.keyword_id)
                        ) AS best_fetched
                 FROM (SELECT DISTINCT keyword_id FROM {$table_serp} WHERE user_id = %d) t
             ) best ON s.keyword_id = best.keyword_id AND s.fetched_at = best.best_fetched
             WHERE s.user_id = %d
             ORDER BY s.keyword ASC",
            $user_id, $cutoff, $user_id, $user_id, $user_id
        ), ARRAY_A );

        $results = [];
        foreach ( $rows as $row ) {
            $results[] = [
                'keyword_id'    => (int) $row['keyword_id'],
                'keyword'       => $row['keyword'],
                'status'        => $row['status'],
                'fetched_at'    => $row['fetched_at'],
                'citations'     => json_decode( $row['citations'] ?? '[]', true ) ?: [],
                'self_found'    => (bool) $row['self_found'],
                'self_count'    => (int) $row['self_count'],
                'self_exposure' => (int) $row['self_exposure'],
            ];
        }

        return $results;
    }

    /**
     * 最終取得日時を取得
     */
    private function get_last_fetched( int $user_id ): ?string {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_aio_serp_results';

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(fetched_at) FROM {$table} WHERE user_id = %d AND status != 'pending'",
            $user_id
        ) );
    }

    /**
     * 結果を DB に保存
     */
    private function save_result( string $table, array $data ): void {
        global $wpdb;
        $wpdb->replace( $table, $data );

        if ( $wpdb->last_error ) {
            self::log( "DB save error: {$wpdb->last_error}" );
        }
    }

    /**
     * AI コメント用プロンプトを構築
     */
    private function build_comment_prompt( array $payload ): string {
        $json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        return <<<PROMPT
以下は、Google検索のAI Overview（AIによる概要）における、あるウェブサイトの露出状況の集計データです。

```json
{$json}
```

このデータをもとに、以下の3点についてコメントを作成してください。

1. **現状の傾向**（自社のAIO露出スコアとカバレッジの評価）
2. **競合との比較**（上位ドメインと比較した自社の位置づけ）
3. **改善の方向性**（AIO露出を改善するための具体的なアクション提案）

注意事項:
- 専門用語は最小限にしてください
- 具体的で実行可能なアドバイスにしてください
- 箇条書きを活用してください
- 300〜500文字程度でまとめてください
PROMPT;
    }

    /**
     * デバッグログ
     */
    private static function log( string $message ): void {
        file_put_contents(
            self::LOG_FILE,
            date( 'Y-m-d H:i:s' ) . " [AIO_SERP] {$message}\n",
            FILE_APPEND
        );
    }
}
