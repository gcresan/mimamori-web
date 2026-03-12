<?php
// FILE: inc/gcrev-api/modules/class-aio-service.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Gcrev_AIO_Service' ) ) { return; }

/**
 * AIO (AI Optimization) Score Service
 *
 * 各 AI（ChatGPT / Gemini / Google AI）にキーワード質問を投げ、
 * 回答内の企業名出現状況からスコアを算出するサービスクラス。
 *
 * @package Mimamori_Web
 * @since   2.5.0
 */
class Gcrev_AIO_Service {

    // =========================================================
    // 定数
    // =========================================================

    /** スコアマップ: 順位 → スコア */
    private const SCORE_MAP = [
        1  => 1.00,
        2  => 0.80,
        3  => 0.60,
        4  => 0.40,
        5  => 0.40,
        6  => 0.20,
        7  => 0.20,
        8  => 0.20,
        9  => 0.20,
        10 => 0.20,
    ];

    /** 1キーワードあたりの質問数 */
    private const QUESTIONS_PER_KEYWORD = 10;

    /** 有効なプロバイダー */
    private const PROVIDERS = [ 'chatgpt', 'gemini', 'google_ai' ];

    // =========================================================
    // プロパティ
    // =========================================================

    /** @var Gcrev_Config */
    private $config;

    // =========================================================
    // コンストラクタ
    // =========================================================

    public function __construct( Gcrev_Config $config ) {
        $this->config = $config;
    }

    // =========================================================
    // 質問生成
    // =========================================================

    /**
     * キーワードとエリアから質問バリエーションを生成
     *
     * @param string $keyword    検索キーワード
     * @param string $area_label エリアラベル（例: "愛媛県 松山市"）
     * @return string[] 10問の質問文配列
     */
    public function generate_questions( string $keyword, string $area_label ): array {
        $templates = $this->get_question_templates();

        $questions = [];
        foreach ( $templates as $tpl ) {
            $q = str_replace(
                [ '{area}', '{keyword}' ],
                [ $area_label, $keyword ],
                $tpl
            );
            $questions[] = $q;
        }

        return array_slice( $questions, 0, self::QUESTIONS_PER_KEYWORD );
    }

    /**
     * 質問テンプレート一覧
     * 将来的に管理画面から編集可能にする想定。現在はコード内配列。
     *
     * @return string[]
     */
    private function get_question_templates(): array {
        // オプションから取得（管理画面で保存済みなら）
        $saved = get_option( 'gcrev_aio_question_templates', [] );
        if ( ! empty( $saved ) && is_array( $saved ) ) {
            return $saved;
        }

        return [
            '{area}で{keyword}のおすすめの会社を教えてください',
            '{area}の{keyword}で評判のいいところはどこですか？',
            '{keyword}を{area}で依頼するならどこがいい？',
            '{area}で{keyword}に強い会社を5社教えてください',
            '{area} {keyword} おすすめ ランキング',
            '{keyword}を{area}で探しています。おすすめを教えて',
            '{area}で{keyword}が得意な業者を比較したい',
            '{area}の{keyword}の口コミが良い会社は？',
            '{area}で人気の{keyword}サービスを教えてください',
            '{keyword} {area} 比較 おすすめ',
        ];
    }

    // =========================================================
    // AI問い合わせ — ChatGPT
    // =========================================================

    /**
     * ChatGPT に質問を投げ、回答から企業名を抽出する
     *
     * @param string   $question     質問文
     * @param string   $company_name 自社名
     * @param string[] $aliases      自社別名リスト
     * @return array{raw: string, names: string[], self_rank: ?int, self_score: float, is_mentioned: bool}
     */
    public function query_chatgpt( string $question, string $company_name, array $aliases ): array {
        $empty = [
            'raw'          => '',
            'names'        => [],
            'self_rank'    => null,
            'self_score'   => 0.00,
            'is_mentioned' => false,
            'error'        => null,
        ];

        if ( ! function_exists( 'mimamori_call_openai_responses_api' ) ) {
            $empty['error'] = 'OpenAI API 関数が利用できません';
            return $empty;
        }

        $model = defined( 'MIMAMORI_OPENAI_MODEL' ) ? MIMAMORI_OPENAI_MODEL : 'gpt-4.1-mini';

        // 1パス目: 質問に回答を求める
        $result = mimamori_call_openai_responses_api( [
            'model'        => $model,
            'input'        => $question,
            'instructions' => 'あなたは日本の地域ビジネスに詳しいアシスタントです。質問に対して、具体的な会社名・店舗名を挙げて回答してください。おすすめの会社をランキング形式で5〜10社紹介してください。',
        ] );

        if ( is_wp_error( $result ) ) {
            $empty['error'] = $result->get_error_message();
            error_log( '[GCREV][AIO] ChatGPT query error: ' . $result->get_error_message() );
            return $empty;
        }

        $raw_text = $result['text'] ?? '';
        if ( $raw_text === '' ) {
            $empty['error'] = '空の応答';
            return $empty;
        }

        // 2パス目: 企業名抽出
        $names = $this->extract_companies_via_chatgpt( $raw_text );

        $self_rank  = $this->find_self_rank( $names, $company_name, $aliases );
        $self_score = $this->rank_to_score( $self_rank );

        return [
            'raw'          => $raw_text,
            'names'        => $names,
            'self_rank'    => $self_rank,
            'self_score'   => $self_score,
            'is_mentioned' => $self_rank !== null,
            'error'        => null,
        ];
    }

    // =========================================================
    // AI問い合わせ — Gemini
    // =========================================================

    /**
     * Gemini に質問を投げ、回答から企業名を抽出する
     *
     * @param string   $question     質問文
     * @param string   $company_name 自社名
     * @param string[] $aliases      自社別名リスト
     * @return array{raw: string, names: string[], self_rank: ?int, self_score: float, is_mentioned: bool}
     */
    public function query_gemini( string $question, string $company_name, array $aliases ): array {
        $empty = [
            'raw'          => '',
            'names'        => [],
            'self_rank'    => null,
            'self_score'   => 0.00,
            'is_mentioned' => false,
            'error'        => null,
        ];

        try {
            $ai_client = new Gcrev_AI_Client( $this->config );
            $raw_text  = $ai_client->call_gemini_api( $question );
        } catch ( \Throwable $e ) {
            $empty['error'] = $e->getMessage();
            error_log( '[GCREV][AIO] Gemini query error: ' . $e->getMessage() );
            return $empty;
        }

        if ( ! is_string( $raw_text ) || $raw_text === '' ) {
            $empty['error'] = '空の応答';
            return $empty;
        }

        // 2パス目: 企業名抽出（ChatGPT で抽出 — Gemini より高速・低コスト）
        $names = $this->extract_companies_via_chatgpt( $raw_text );

        $self_rank  = $this->find_self_rank( $names, $company_name, $aliases );
        $self_score = $this->rank_to_score( $self_rank );

        return [
            'raw'          => $raw_text,
            'names'        => $names,
            'self_rank'    => $self_rank,
            'self_score'   => $self_score,
            'is_mentioned' => $self_rank !== null,
            'error'        => null,
        ];
    }

    // =========================================================
    // AI問い合わせ — Google AI（DataForSEO SERP）
    // =========================================================

    /**
     * DataForSEO の SERP API から AI Overview アイテムを取得
     *
     * @param string   $keyword       検索キーワード
     * @param string   $company_name  自社名
     * @param string[] $aliases       自社別名リスト
     * @param int      $location_code ロケーションコード
     * @return array{raw: string, names: string[], self_rank: ?int, self_score: float, is_mentioned: bool}
     */
    public function query_google_ai( string $keyword, string $company_name, array $aliases, int $location_code = 2392 ): array {
        $empty = [
            'raw'          => '',
            'names'        => [],
            'self_rank'    => null,
            'self_score'   => 0.00,
            'is_mentioned' => false,
            'error'        => null,
            'status'       => 'no_data',
        ];

        if ( ! class_exists( 'Gcrev_DataForSEO_Client' ) || ! Gcrev_DataForSEO_Client::is_configured() ) {
            $empty['error'] = 'DataForSEO API が未設定です';
            return $empty;
        }

        $client = new Gcrev_DataForSEO_Client( $this->config );
        $items  = $client->fetch_serp( $keyword, 'desktop', $location_code );

        if ( is_wp_error( $items ) ) {
            $empty['error'] = $items->get_error_message();
            error_log( '[GCREV][AIO] Google AI SERP error: ' . $items->get_error_message() );
            return $empty;
        }

        // AI Overview アイテムを抽出
        $ai_texts = [];
        foreach ( $items as $item ) {
            $type = $item['type'] ?? '';
            if ( $type === 'ai_overview' || $type === 'featured_snippet' || $type === 'answer_box' ) {
                $text = $item['description'] ?? $item['text'] ?? '';
                if ( $text !== '' ) {
                    $ai_texts[] = $text;
                }
                // AI Overview内のサブアイテムも確認
                if ( isset( $item['items'] ) && is_array( $item['items'] ) ) {
                    foreach ( $item['items'] as $sub ) {
                        $sub_text = $sub['description'] ?? $sub['title'] ?? '';
                        if ( $sub_text !== '' ) {
                            $ai_texts[] = $sub_text;
                        }
                    }
                }
            }
        }

        if ( empty( $ai_texts ) ) {
            // AI Overview が見つからなかった
            return $empty;
        }

        $combined = implode( "\n", $ai_texts );

        // 企業名抽出（ChatGPT で抽出 — コスト効率重視）
        $names = $this->extract_companies_via_chatgpt( $combined );

        $self_rank  = $this->find_self_rank( $names, $company_name, $aliases );
        $self_score = $this->rank_to_score( $self_rank );

        return [
            'raw'          => $combined,
            'names'        => $names,
            'self_rank'    => $self_rank,
            'self_score'   => $self_score,
            'is_mentioned' => $self_rank !== null,
            'error'        => null,
            'status'       => 'ok',
        ];
    }

    // =========================================================
    // 企業名抽出
    // =========================================================

    /**
     * ChatGPT (OpenAI) を使って回答テキストから企業名を抽出する
     *
     * @param string $response_text AI回答テキスト
     * @return string[] 企業名配列（言及順）
     */
    private function extract_companies_via_chatgpt( string $response_text ): array {
        if ( ! function_exists( 'mimamori_call_openai_responses_api' ) ) {
            return $this->extract_companies_fallback( $response_text );
        }

        $model = defined( 'MIMAMORI_OPENAI_MODEL' ) ? MIMAMORI_OPENAI_MODEL : 'gpt-4.1-mini';

        $prompt = $this->build_extraction_prompt( $response_text );

        $result = mimamori_call_openai_responses_api( [
            'model'             => $model,
            'input'             => $prompt,
            'max_output_tokens' => 512,
        ] );

        if ( is_wp_error( $result ) ) {
            error_log( '[GCREV][AIO] ChatGPT extract error: ' . $result->get_error_message() );
            return $this->extract_companies_fallback( $response_text );
        }

        return $this->parse_names_json( $result['text'] ?? '' );
    }

    /**
     * Gemini を使って回答テキストから企業名を抽出する
     *
     * @param string $response_text AI回答テキスト
     * @return string[] 企業名配列（言及順）
     */
    private function extract_companies_via_gemini( string $response_text ): array {
        $prompt = $this->build_extraction_prompt( $response_text );

        try {
            $ai_client = new Gcrev_AI_Client( $this->config );
            $text      = $ai_client->call_gemini_api( $prompt );
        } catch ( \Throwable $e ) {
            error_log( '[GCREV][AIO] Gemini extract error: ' . $e->getMessage() );
            return $this->extract_companies_fallback( $response_text );
        }

        return $this->parse_names_json( $text );
    }

    /**
     * 企業名抽出プロンプトを構築
     *
     * @param string $response_text
     * @return string
     */
    private function build_extraction_prompt( string $response_text ): string {
        // テキストが長すぎる場合は切り詰める
        $max_len = 4000;
        if ( mb_strlen( $response_text, 'UTF-8' ) > $max_len ) {
            $response_text = mb_substr( $response_text, 0, $max_len, 'UTF-8' );
        }

        return <<<PROMPT
以下のテキストから、言及されている会社名・サービス名・店舗名を、言及順にJSON配列で返してください。
最大10件まで。重複は除外してください。
出力はJSON配列のみ（説明不要）。

例: ["株式会社A", "Bサービス", "C工房"]

テキスト:
{$response_text}
PROMPT;
    }

    /**
     * AI応答からJSON配列をパースして文字列配列を返す
     *
     * @param string $text AI応答テキスト
     * @return string[]
     */
    private function parse_names_json( string $text ): array {
        $text = trim( $text );
        if ( $text === '' ) {
            return [];
        }

        // ```json ... ``` フェンスを除去
        $text = preg_replace( '/```(?:json)?\s*/i', '', $text );
        $text = str_replace( '```', '', $text );

        // 最初の [ ... ] を抽出
        $start = mb_strpos( $text, '[' );
        if ( $start === false ) {
            return [];
        }
        $end = mb_strrpos( $text, ']' );
        if ( $end === false || $end <= $start ) {
            return [];
        }

        $json_str = mb_substr( $text, $start, $end - $start + 1 );
        $decoded  = json_decode( $json_str, true );

        if ( ! is_array( $decoded ) ) {
            return [];
        }

        // 文字列のみ抽出し、空文字除去
        $names = [];
        foreach ( $decoded as $item ) {
            if ( is_string( $item ) && trim( $item ) !== '' ) {
                $names[] = trim( $item );
            }
        }

        return array_slice( $names, 0, 10 );
    }

    /**
     * AI抽出が失敗した場合のフォールバック（正規表現で番号付きリストを解析）
     *
     * @param string $text
     * @return string[]
     */
    private function extract_companies_fallback( string $text ): array {
        $names = [];

        // パターン: "1. 会社名" or "1）会社名" or "① 会社名" or "**1. 会社名**"
        if ( preg_match_all( '/(?:^|\n)\s*(?:\*\*)?(?:\d+[\.\)）]|[①②③④⑤⑥⑦⑧⑨⑩])\s*(?:\*\*)?(.+?)(?:\*\*)?(?:\n|$)/u', $text, $matches ) ) {
            foreach ( $matches[1] as $name ) {
                $name = trim( $name );
                // URLや長すぎるテキストは除外
                if ( mb_strlen( $name, 'UTF-8' ) > 50 || preg_match( '/^https?:/', $name ) ) {
                    continue;
                }
                // 「 - 」「（」以降を切り捨て
                $name = preg_replace( '/\s*[\-\(（【].*/u', '', $name );
                $name = trim( $name, " \t\n\r\0\x0B*" );
                if ( $name !== '' ) {
                    $names[] = $name;
                }
            }
        }

        return array_slice( array_unique( $names ), 0, 10 );
    }

    // =========================================================
    // 企業名マッチング
    // =========================================================

    /**
     * 抽出された企業名リストから自社の順位を特定する
     *
     * @param string[] $names       抽出企業名リスト（言及順）
     * @param string   $company     自社名
     * @param string[] $aliases     自社別名リスト
     * @return int|null 1-indexed の順位。見つからない場合 null
     */
    private function find_self_rank( array $names, string $company, array $aliases ): ?int {
        if ( empty( $names ) || $company === '' ) {
            return null;
        }

        $targets = array_merge( [ $company ], $aliases );
        $targets = array_filter( array_map( function ( $t ) {
            return mb_strtolower( trim( $t ), 'UTF-8' );
        }, $targets ) );

        if ( empty( $targets ) ) {
            return null;
        }

        foreach ( $names as $idx => $name ) {
            $normalized = mb_strtolower( trim( $name ), 'UTF-8' );
            foreach ( $targets as $target ) {
                if ( $target === '' ) {
                    continue;
                }
                // 双方向部分一致（"ジィクレブ" ∈ "株式会社ジィクレブ" or逆）
                if ( mb_strpos( $normalized, $target ) !== false || mb_strpos( $target, $normalized ) !== false ) {
                    return $idx + 1; // 1-indexed
                }
            }
        }

        return null;
    }

    // =========================================================
    // スコア計算
    // =========================================================

    /**
     * 順位からスコアを計算
     *
     * @param int|null $rank 1-indexed 順位
     * @return float スコア（0.00 - 1.00）
     */
    private function rank_to_score( ?int $rank ): float {
        if ( $rank === null || $rank < 1 ) {
            return 0.00;
        }
        return self::SCORE_MAP[ $rank ] ?? 0.00;
    }

    // =========================================================
    // 地点コンテキスト解決
    // =========================================================

    /**
     * キーワード＋ユーザー設定から地点コンテキストを解決する
     *
     * 優先順位:
     *  1. キーワードから市区町村検出
     *  2. キーワードから都道府県検出
     *  3. クライアント設定から取得
     *  4. 該当なし
     *
     * @param string $keyword  検索キーワード
     * @param int    $user_id  ユーザーID
     * @return array { label: string, source: string }
     */
    public function resolve_location_context( string $keyword, int $user_id ): array {
        // 1. キーワードから市区町村検出
        $city = Gcrev_Area_Detector::detect_city( $keyword );
        if ( $city ) {
            return [
                'label'  => $city,
                'source' => 'keyword_text',
            ];
        }

        // 2. キーワードから都道府県検出
        $pref = Gcrev_Area_Detector::detect( $keyword );
        if ( $pref ) {
            return [
                'label'  => $pref,
                'source' => 'keyword_text',
            ];
        }

        // 3. クライアント設定から取得
        if ( function_exists( 'gcrev_get_aio_default_location' ) ) {
            $default = gcrev_get_aio_default_location( $user_id );
            if ( ! empty( $default['location_label'] ) ) {
                return [
                    'label'  => $default['location_label'],
                    'source' => 'client_settings',
                ];
            }
        }

        // 4. 該当なし
        return [
            'label'  => '',
            'source' => 'none',
        ];
    }

    // =========================================================
    // オーケストレーション
    // =========================================================

    /**
     * 単一キーワードの AIO チェックを実行する
     *
     * @param int      $keyword_id キーワードID（gcrev_rank_keywords.id）
     * @param string[] $providers  対象プロバイダー（省略時: 全プロバイダー）
     * @return array 実行結果サマリー
     */
    public function run_aio_check( int $keyword_id, array $providers = [] ): array {
        if ( empty( $providers ) ) {
            $providers = self::PROVIDERS;
        }

        global $wpdb;
        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';

        // キーワード情報取得
        $kw = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$kw_table} WHERE id = %d AND aio_enabled = 1",
            $keyword_id
        ), ARRAY_A );

        if ( ! $kw ) {
            return [ 'error' => 'キーワードが見つからないか AIO が無効です' ];
        }

        $user_id = (int) $kw['user_id'];

        // クライアント設定から自社名・エリアを取得
        $settings   = gcrev_get_client_settings( $user_id );
        $area_label = gcrev_get_client_area_label( $settings );

        $company_name = $this->get_company_name( $user_id );
        $aliases      = $this->get_company_aliases( $user_id );

        // 質問生成
        $questions = $this->generate_questions( $kw['keyword'], $area_label );

        // 地点コンテキスト解決
        $location_ctx = $this->resolve_location_context( $kw['keyword'], $user_id );

        $tz  = wp_timezone();
        $now = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

        $results_table = $wpdb->prefix . 'gcrev_aio_results';
        $summary       = [];

        foreach ( $providers as $provider ) {
            if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
                continue;
            }

            // プロバイダー単位でエラー分離（TypeError 等も捕捉）
            try {
                $mention_count = 0;
                $score_sum     = 0.0;

                foreach ( $questions as $qi => $question ) {
                    // プロバイダー別クエリ
                    $result = $this->query_provider( $provider, $question, $kw['keyword'], $company_name, $aliases, (int) $kw['location_code'] );

                    // 状態判定
                    $status = $this->determine_status( $result, $provider );

                    // DB保存（UPSERT）— self_rank / self_score は NULL 可なので条件分岐
                    $self_rank_sql  = $result['self_rank'] === null ? 'NULL' : $wpdb->prepare( '%d', $result['self_rank'] );
                    $self_score_sql = $result['self_score'] === null ? 'NULL' : $wpdb->prepare( '%s', number_format( $result['self_score'], 2, '.', '' ) );

                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $self_rank_sql / $self_score_sql are safe values
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$results_table}
                         (user_id, keyword_id, provider, question, question_index,
                          raw_response, extracted_names, self_rank, self_score, is_mentioned,
                          status, location_label, location_source, fetched_at, created_at)
                         VALUES (%d, %d, %s, %s, %d, %s, %s, {$self_rank_sql}, {$self_score_sql}, %d, %s, %s, %s, %s, %s)
                         ON DUPLICATE KEY UPDATE
                          raw_response = VALUES(raw_response),
                          extracted_names = VALUES(extracted_names),
                          self_rank = VALUES(self_rank),
                          self_score = VALUES(self_score),
                          is_mentioned = VALUES(is_mentioned),
                          status = VALUES(status),
                          location_label = VALUES(location_label),
                          location_source = VALUES(location_source),
                          fetched_at = VALUES(fetched_at)",
                        $user_id,
                        $keyword_id,
                        $provider,
                        $question,
                        $qi,
                        $result['raw'],
                        wp_json_encode( $result['names'], JSON_UNESCAPED_UNICODE ),
                        $result['is_mentioned'] ? 1 : 0,
                        $status,
                        $location_ctx['label'],
                        $location_ctx['source'],
                        $now,
                        $now
                    ) );

                    if ( $result['is_mentioned'] ) {
                        $mention_count++;
                    }
                    $score_sum += $result['self_score'];

                    // Google AI は1回のみ（SERP結果は質問バリエーション不要）
                    if ( $provider === 'google_ai' ) {
                        break;
                    }
                }

                $total = ( $provider === 'google_ai' ) ? 1 : count( $questions );
                $summary[ $provider ] = [
                    'visibility'    => $total > 0 ? round( ( $mention_count / $total ) * 100, 1 ) : 0,
                    'avg_score'     => $total > 0 ? round( $score_sum / $total, 2 ) : 0,
                    'mention_count' => $mention_count,
                    'total_queries' => $total,
                ];
            } catch ( \Throwable $e ) {
                error_log( "[GCREV][AIO] provider={$provider} keyword_id={$keyword_id} error: " . $e->getMessage() );
                $summary[ $provider ] = [
                    'visibility'    => 0,
                    'avg_score'     => 0,
                    'mention_count' => 0,
                    'total_queries' => 0,
                    'error'         => $e->getMessage(),
                ];
            }
        }

        return [
            'keyword_id' => $keyword_id,
            'keyword'    => $kw['keyword'],
            'providers'  => $summary,
        ];
    }

    /**
     * プロバイダー別にクエリを振り分ける内部メソッド
     */
    private function query_provider( string $provider, string $question, string $keyword, string $company, array $aliases, int $location_code ): array {
        switch ( $provider ) {
            case 'chatgpt':
                return $this->query_chatgpt( $question, $company, $aliases );
            case 'gemini':
                return $this->query_gemini( $question, $company, $aliases );
            case 'google_ai':
                return $this->query_google_ai( $keyword, $company, $aliases, $location_code );
            default:
                return [
                    'raw'          => '',
                    'names'        => [],
                    'self_rank'    => null,
                    'self_score'   => 0.00,
                    'is_mentioned' => false,
                    'error'        => '不明なプロバイダー: ' . $provider,
                ];
        }
    }

    /**
     * ユーザーの全 AIO 有効キーワードを一括計測する
     *
     * @param int $user_id ユーザーID
     * @return array 全キーワードの結果サマリー
     */
    public function run_all_keywords( int $user_id ): array {
        global $wpdb;
        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$kw_table} WHERE user_id = %d AND aio_enabled = 1 ORDER BY sort_order ASC",
            $user_id
        ), ARRAY_A );

        if ( empty( $keywords ) ) {
            return [ 'keywords' => [], 'message' => 'AIO 有効なキーワードがありません' ];
        }

        // 長時間実行: 接続切断でもスクリプトを継続し、十分な実行時間を確保
        @ignore_user_abort( true );
        @set_time_limit( 0 );

        $results = [];
        foreach ( $keywords as $kw ) {
            try {
                $results[] = $this->run_aio_check( (int) $kw['id'] );
            } catch ( \Throwable $e ) {
                error_log( '[GCREV][AIO] run_all_keywords keyword_id=' . $kw['id'] . ' error: ' . $e->getMessage() );
                $results[] = [
                    'keyword_id' => (int) $kw['id'],
                    'error'      => $e->getMessage(),
                ];
            }
        }

        return [ 'keywords' => $results ];
    }

    // =========================================================
    // 読み取り（DB → 集計）
    // =========================================================

    /**
     * ユーザーの AIO サマリーを取得（DB から集計）
     *
     * @param int $user_id
     * @return array
     */
    public function get_results_summary( int $user_id ): array {
        global $wpdb;
        $results_table = $wpdb->prefix . 'gcrev_aio_results';
        $kw_table      = $wpdb->prefix . 'gcrev_rank_keywords';

        // AIO有効キーワード一覧
        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword FROM {$kw_table}
             WHERE user_id = %d AND aio_enabled = 1
             ORDER BY sort_order ASC",
            $user_id
        ), ARRAY_A );

        if ( empty( $keywords ) ) {
            return [
                'chatgpt'      => $this->empty_provider_summary(),
                'gemini'       => $this->empty_provider_summary(),
                'google_ai'    => $this->empty_provider_summary(),
                'keywords'     => [],
                'last_fetched' => null,
            ];
        }

        $kw_ids = array_column( $keywords, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $kw_ids ), '%d' ) );

        // 最新の結果を取得
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT keyword_id, provider, question_index, self_rank, self_score, is_mentioned, fetched_at
             FROM {$results_table}
             WHERE user_id = %d AND keyword_id IN ({$placeholders})
             ORDER BY keyword_id, provider, question_index",
            array_merge( [ $user_id ], $kw_ids )
        ), ARRAY_A );

        // プロバイダー別・キーワード別に集計
        $totals = [];
        $kw_data = [];
        $last_fetched = null;

        foreach ( self::PROVIDERS as $p ) {
            $totals[ $p ] = [ 'mentioned' => 0, 'total' => 0, 'score_sum' => 0.0 ];
        }

        foreach ( $keywords as $kw ) {
            $kid = (int) $kw['id'];
            $kw_data[ $kid ] = [
                'keyword_id' => $kid,
                'keyword'    => $kw['keyword'],
            ];
            foreach ( self::PROVIDERS as $p ) {
                $kw_data[ $kid ][ $p ] = [ 'mentioned' => 0, 'total' => 0, 'score_sum' => 0.0 ];
            }
            $kw_data[ $kid ]['last_fetched'] = null;
        }

        foreach ( $rows as $row ) {
            $kid = (int) $row['keyword_id'];
            $p   = $row['provider'];
            if ( ! isset( $totals[ $p ] ) || ! isset( $kw_data[ $kid ] ) ) {
                continue;
            }

            $totals[ $p ]['total']++;
            $totals[ $p ]['score_sum'] += (float) $row['self_score'];
            if ( (int) $row['is_mentioned'] ) {
                $totals[ $p ]['mentioned']++;
            }

            $kw_data[ $kid ][ $p ]['total']++;
            $kw_data[ $kid ][ $p ]['score_sum'] += (float) $row['self_score'];
            if ( (int) $row['is_mentioned'] ) {
                $kw_data[ $kid ][ $p ]['mentioned']++;
            }

            if ( $row['fetched_at'] && ( ! $last_fetched || $row['fetched_at'] > $last_fetched ) ) {
                $last_fetched = $row['fetched_at'];
            }
            if ( $row['fetched_at'] && ( ! $kw_data[ $kid ]['last_fetched'] || $row['fetched_at'] > $kw_data[ $kid ]['last_fetched'] ) ) {
                $kw_data[ $kid ]['last_fetched'] = $row['fetched_at'];
            }
        }

        // サマリー構築
        $provider_summaries = [];
        foreach ( self::PROVIDERS as $p ) {
            $t = $totals[ $p ];
            $provider_summaries[ $p ] = [
                'visibility'    => $t['total'] > 0 ? round( ( $t['mentioned'] / $t['total'] ) * 100, 1 ) : 0,
                'avg_score'     => $t['total'] > 0 ? round( $t['score_sum'] / $t['total'], 2 ) : 0,
                'mention_count' => $t['mentioned'],
                'total_queries' => $t['total'],
            ];
        }

        // キーワード別サマリー
        $kw_summaries = [];
        foreach ( $kw_data as $kid => $kd ) {
            $entry = [
                'keyword_id'   => $kd['keyword_id'],
                'keyword'      => $kd['keyword'],
                'last_fetched' => $kd['last_fetched'],
            ];
            foreach ( self::PROVIDERS as $p ) {
                $pd = $kd[ $p ];
                $entry[ $p ] = [
                    'visibility' => $pd['total'] > 0 ? round( ( $pd['mentioned'] / $pd['total'] ) * 100, 1 ) : 0,
                    'avg_score'  => $pd['total'] > 0 ? round( $pd['score_sum'] / $pd['total'], 2 ) : 0,
                ];
                if ( $p === 'google_ai' && $pd['total'] === 0 ) {
                    $entry[ $p ]['status'] = 'no_data';
                }
            }
            $kw_summaries[] = $entry;
        }

        return array_merge( $provider_summaries, [
            'keywords'     => $kw_summaries,
            'last_fetched' => $last_fetched,
        ] );
    }

    /**
     * キーワード詳細（アコーディオン展開時に使う）
     *
     * @param int $keyword_id
     * @return array
     */
    public function get_keyword_detail( int $keyword_id ): array {
        global $wpdb;
        $results_table = $wpdb->prefix . 'gcrev_aio_results';
        $kw_table      = $wpdb->prefix . 'gcrev_rank_keywords';

        $kw = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, keyword FROM {$kw_table} WHERE id = %d",
            $keyword_id
        ), ARRAY_A );

        if ( ! $kw ) {
            return [ 'error' => 'キーワードが見つかりません' ];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT provider, question_index, extracted_names, self_rank, self_score, is_mentioned
             FROM {$results_table}
             WHERE keyword_id = %d
             ORDER BY provider, question_index",
            $keyword_id
        ), ARRAY_A );

        // プロバイダー別ランキング集計
        $providers = [];
        foreach ( self::PROVIDERS as $p ) {
            $providers[ $p ] = [
                'visibility'  => 0,
                'rankings'    => [],
                '_name_count' => [], // 内部用: 企業名→出現回数
                '_total'      => 0,
                '_mentioned'  => 0,
            ];
        }

        foreach ( $rows as $row ) {
            $p = $row['provider'];
            if ( ! isset( $providers[ $p ] ) ) {
                continue;
            }

            $providers[ $p ]['_total']++;
            if ( (int) $row['is_mentioned'] ) {
                $providers[ $p ]['_mentioned']++;
            }

            $names = json_decode( $row['extracted_names'] ?? '[]', true );
            if ( ! is_array( $names ) ) {
                $names = [];
            }

            foreach ( $names as $idx => $name ) {
                if ( ! isset( $providers[ $p ]['_name_count'][ $name ] ) ) {
                    $providers[ $p ]['_name_count'][ $name ] = 0;
                }
                $providers[ $p ]['_name_count'][ $name ]++;
            }
        }

        // ユーザー情報（自社名判定用）
        $kw_full = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id FROM {$kw_table} WHERE id = %d",
            $keyword_id
        ), ARRAY_A );
        $user_id      = (int) ( $kw_full['user_id'] ?? 0 );
        $company_name = $this->get_company_name( $user_id );
        $aliases      = $this->get_company_aliases( $user_id );

        // ランキング構築
        $result_providers = [];
        foreach ( self::PROVIDERS as $p ) {
            $pd    = $providers[ $p ];
            $total = $pd['_total'];

            $result_providers[ $p ] = [
                'visibility' => $total > 0 ? round( ( $pd['_mentioned'] / $total ) * 100, 1 ) : 0,
                'rankings'   => [],
            ];

            if ( $total === 0 ) {
                if ( $p === 'google_ai' ) {
                    $result_providers[ $p ]['status'] = 'no_data';
                }
                continue;
            }

            // 出現回数でソート（降順）
            arsort( $pd['_name_count'] );

            $rank = 0;
            foreach ( $pd['_name_count'] as $name => $count ) {
                $rank++;
                if ( $rank > 10 ) {
                    break;
                }
                $is_self = $this->find_self_rank( [ $name ], $company_name, $aliases ) !== null;
                $result_providers[ $p ]['rankings'][] = [
                    'rank'         => $rank,
                    'name'         => $name,
                    'mention_rate' => round( ( $count / $total ) * 100, 0 ),
                    'is_self'      => $is_self,
                ];
            }
        }

        return [
            'keyword_id' => (int) $kw['id'],
            'keyword'    => $kw['keyword'],
            'providers'  => $result_providers,
        ];
    }

    // =========================================================
    // ユーティリティ
    // =========================================================

    /**
     * 自社名を取得（report_company_name → display_name の順）
     *
     * @param int $user_id
     * @return string
     */
    public function get_company_name( int $user_id ): string {
        $name = get_user_meta( $user_id, 'report_company_name', true );
        if ( ! empty( $name ) ) {
            return $name;
        }
        $user = get_userdata( $user_id );
        return $user ? $user->display_name : '';
    }

    /**
     * 自社別名リストを取得
     *
     * @param int $user_id
     * @return string[]
     */
    public function get_company_aliases( int $user_id ): array {
        $raw = get_user_meta( $user_id, 'gcrev_aio_company_aliases', true );
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }
        if ( is_array( $raw ) ) {
            return $raw;
        }

        // フォールバック: target_domain をサイトURLから抽出
        $aliases  = [];
        $settings = gcrev_get_client_settings( $user_id );
        $site_url = $settings['site_url'] ?? '';
        if ( $site_url !== '' ) {
            $host = wp_parse_url( $site_url, PHP_URL_HOST );
            if ( $host ) {
                $aliases[] = preg_replace( '/^www\./i', '', $host );
            }
        }

        return $aliases;
    }

    /**
     * 空のプロバイダーサマリーを返す
     */
    private function empty_provider_summary(): array {
        return [
            'visibility'    => 0,
            'avg_score'     => 0,
            'mention_count' => 0,
            'total_queries' => 0,
        ];
    }

    // =========================================================
    // 状態判定
    // =========================================================

    /**
     * クエリ結果からステータスを判定する
     *
     * @param array  $result   query_* メソッドの戻り値
     * @param string $provider プロバイダー名
     * @return string ステータス文字列
     */
    private function determine_status( array $result, string $provider ): string {
        // エラーがある場合
        if ( ! empty( $result['error'] ) ) {
            // DataForSEO未設定 or プロバイダー非対応
            if ( $provider === 'google_ai' && (
                strpos( $result['error'], '未設定' ) !== false ||
                strpos( $result['error'], 'not configured' ) !== false
            ) ) {
                return 'unsupported';
            }
            return 'fetch_failed';
        }

        // Google AI の no_data ステータス
        if ( isset( $result['status'] ) && $result['status'] === 'no_data' ) {
            return 'no_answer';
        }

        // raw_response が空
        if ( empty( $result['raw'] ) ) {
            return 'fetch_failed';
        }

        // 企業名が1つも抽出できなかった
        if ( empty( $result['names'] ) ) {
            return 'parse_failed';
        }

        // 正常完了
        return $result['is_mentioned'] ? 'success_mentioned' : 'success_not_mentioned';
    }

    // =========================================================
    // AIレポート統合データ
    // =========================================================

    /** ステータスの日本語ラベルマップ */
    public const STATUS_LABELS = [
        'success_mentioned'     => '掲載あり',
        'success_not_mentioned' => '掲載なし',
        'not_run'               => '未計測',
        'fetch_failed'          => '取得失敗',
        'parse_failed'          => '解析失敗',
        'unsupported'           => '対応外',
        'no_answer'             => '回答なし',
    ];

    /**
     * AIレポート統合データを返す（全セクション分）
     *
     * @param int $user_id
     * @return array
     */
    public function get_report_data( int $user_id ): array {
        $mention_matrix = $this->get_mention_check_matrix( $user_id );
        $diagnosis      = $this->get_site_diagnosis( $user_id );
        $competitors    = $this->get_competitor_analysis( $user_id );
        $actions        = $this->generate_improvement_actions( $user_id, $diagnosis );

        // サマリー計算
        $mention_stats  = $this->calc_mention_stats( $mention_matrix );
        $diagnosis_score = $this->calc_diagnosis_score( $diagnosis );

        // 改善優先度（diagnosis_score が null の場合は mention_rate のみで判定）
        $priority = 'low';
        if ( $diagnosis_score === null ) {
            // 未診断時は掲載率のみで判定
            if ( $mention_stats['rate'] < 20 ) {
                $priority = 'high';
            } elseif ( $mention_stats['rate'] < 50 ) {
                $priority = 'medium';
            }
        } else {
            if ( $diagnosis_score < 40 || $mention_stats['rate'] < 20 ) {
                $priority = 'high';
            } elseif ( $diagnosis_score < 70 || $mention_stats['rate'] < 50 ) {
                $priority = 'medium';
            }
        }

        // 地点情報
        $default_location = function_exists( 'gcrev_get_aio_default_location' )
            ? gcrev_get_aio_default_location( $user_id )
            : [ 'location_type' => '', 'location_label' => '', 'location_source' => 'none' ];

        return [
            'summary' => [
                'diagnosis_score'  => $diagnosis_score,
                'mention_rate'     => $mention_stats['rate'],
                'mention_count'    => $mention_stats['mentioned'],
                'mention_total'    => $mention_stats['total'],
                'priority'         => $priority,
                'last_fetched'     => $mention_stats['last_fetched'],
                'location_label'   => $default_location['location_label'],
                'location_type'    => $default_location['location_type'],
                'location_source'  => $default_location['location_source'],
            ],
            'diagnosis'      => $diagnosis,
            'mention_matrix' => $mention_matrix,
            'competitors'    => $competitors,
            'actions'        => $actions,
            'provider_notes' => [
                'chatgpt'   => '質問文に地域名を含めた一般傾向ベースの参考値',
                'gemini'    => '質問文に地域名を含めた参考計測値',
                'google_ai' => '地域コード指定によるSERP結果。地域文脈が考慮されています',
            ],
        ];
    }

    /**
     * 掲載チェックマトリックス（キーワード × プロバイダー × 状態）
     *
     * @param int $user_id
     * @return array
     */
    public function get_mention_check_matrix( int $user_id ): array {
        global $wpdb;
        $results_table = $wpdb->prefix . 'gcrev_aio_results';
        $kw_table      = $wpdb->prefix . 'gcrev_rank_keywords';

        $keywords = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, keyword FROM {$kw_table}
             WHERE user_id = %d AND aio_enabled = 1
             ORDER BY sort_order ASC",
            $user_id
        ), ARRAY_A );

        if ( empty( $keywords ) ) {
            return [];
        }

        $kw_ids       = array_column( $keywords, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $kw_ids ), '%d' ) );

        // 状態カラムが存在しない場合のフォールバック
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT keyword_id, provider, status, is_mentioned, self_rank, fetched_at,
                    raw_response, extracted_names, location_label
             FROM {$results_table}
             WHERE user_id = %d AND keyword_id IN ({$placeholders})
             ORDER BY keyword_id, provider, question_index",
            array_merge( [ $user_id ], $kw_ids )
        ), ARRAY_A );

        // キーワード×プロバイダーの集約
        $matrix = [];
        foreach ( $keywords as $kw ) {
            $kid = (int) $kw['id'];
            $entry = [
                'keyword_id'     => $kid,
                'keyword'        => $kw['keyword'],
                'location_label' => '',
                'providers'      => [],
            ];
            foreach ( self::PROVIDERS as $p ) {
                $entry['providers'][ $p ] = [
                    'status'        => 'not_run',
                    'label'         => self::STATUS_LABELS['not_run'],
                    'mentioned'     => 0,
                    'total'         => 0,
                    'avg_rank'      => null,
                    'last_fetched'  => null,
                ];
            }
            $matrix[ $kid ] = $entry;
        }

        foreach ( $rows as $row ) {
            $kid = (int) $row['keyword_id'];
            $p   = $row['provider'];
            if ( ! isset( $matrix[ $kid ]['providers'][ $p ] ) ) {
                continue;
            }

            // 地点ラベルを最初に見つかった値で設定
            if ( empty( $matrix[ $kid ]['location_label'] ) && ! empty( $row['location_label'] ) ) {
                $matrix[ $kid ]['location_label'] = $row['location_label'];
            }

            $ref = &$matrix[ $kid ]['providers'][ $p ];
            $ref['total']++;

            $row_status = $row['status'] ?? '';
            // status が not_run のまま（マイグレーション前データ）→ 再判定
            if ( $row_status === '' || $row_status === 'not_run' ) {
                if ( (int) $row['is_mentioned'] ) {
                    $row_status = 'success_mentioned';
                } elseif ( ! empty( $row['raw_response'] ) ) {
                    $row_status = 'success_not_mentioned';
                }
            }

            if ( $row_status === 'success_mentioned' ) {
                $ref['mentioned']++;
                if ( $row['self_rank'] !== null ) {
                    $ref['_rank_sum'] = ( $ref['_rank_sum'] ?? 0 ) + (int) $row['self_rank'];
                    $ref['_rank_cnt'] = ( $ref['_rank_cnt'] ?? 0 ) + 1;
                }
            }

            if ( $row['fetched_at'] && ( ! $ref['last_fetched'] || $row['fetched_at'] > $ref['last_fetched'] ) ) {
                $ref['last_fetched'] = $row['fetched_at'];
            }

            // プロバイダー全体の最終ステータスを決定（最も重要な状態を優先）
            $ref['_statuses'][] = $row_status;
        }

        // 最終ステータス判定
        foreach ( $matrix as &$entry ) {
            foreach ( self::PROVIDERS as $p ) {
                $ref = &$entry['providers'][ $p ];
                if ( $ref['total'] === 0 ) {
                    // DataForSEO未設定の場合は unsupported
                    if ( $p === 'google_ai' && ( ! class_exists( 'Gcrev_DataForSEO_Client' ) || ! Gcrev_DataForSEO_Client::is_configured() ) ) {
                        $ref['status'] = 'unsupported';
                        $ref['label']  = self::STATUS_LABELS['unsupported'];
                    }
                    continue;
                }

                // 平均順位
                if ( isset( $ref['_rank_cnt'] ) && $ref['_rank_cnt'] > 0 ) {
                    $ref['avg_rank'] = round( $ref['_rank_sum'] / $ref['_rank_cnt'], 1 );
                }

                // 集約ステータス: mentioned > 0 なら掲載あり、それ以外は最頻ステータス
                if ( $ref['mentioned'] > 0 ) {
                    $ref['status'] = 'success_mentioned';
                } else {
                    $statuses = $ref['_statuses'] ?? [];
                    $priority_order = [ 'fetch_failed', 'parse_failed', 'no_answer', 'unsupported', 'success_not_mentioned' ];
                    $ref['status'] = 'success_not_mentioned';
                    foreach ( $priority_order as $s ) {
                        if ( in_array( $s, $statuses, true ) ) {
                            $ref['status'] = $s;
                            break;
                        }
                    }
                }
                $ref['label'] = self::STATUS_LABELS[ $ref['status'] ] ?? $ref['status'];

                // 内部用フィールド削除
                unset( $ref['_rank_sum'], $ref['_rank_cnt'], $ref['_statuses'] );
            }
        }

        // DB に location_label が未保存（legacy データ）の場合、キーワードテキストから解決
        foreach ( $matrix as &$entry ) {
            if ( empty( $entry['location_label'] ) ) {
                $loc = $this->resolve_location_context( $entry['keyword'], $user_id );
                $entry['location_label'] = $loc['label'];
            }
        }
        unset( $entry );

        return array_values( $matrix );
    }

    /**
     * 掲載統計を計算
     */
    private function calc_mention_stats( array $matrix ): array {
        $mentioned    = 0;
        $total        = 0;
        $last_fetched = null;

        foreach ( $matrix as $entry ) {
            foreach ( $entry['providers'] as $p => $data ) {
                if ( $data['total'] > 0 ) {
                    // キーワード×プロバイダー単位で「掲載ありか」を判定
                    $total++;
                    if ( $data['status'] === 'success_mentioned' ) {
                        $mentioned++;
                    }
                }
                if ( $data['last_fetched'] && ( ! $last_fetched || $data['last_fetched'] > $last_fetched ) ) {
                    $last_fetched = $data['last_fetched'];
                }
            }
        }

        return [
            'mentioned'    => $mentioned,
            'total'        => $total,
            'rate'         => $total > 0 ? round( ( $mentioned / $total ) * 100, 0 ) : 0,
            'last_fetched' => $last_fetched,
        ];
    }

    // =========================================================
    // サイト診断
    // =========================================================

    /** 診断項目の定義 */
    private const DIAGNOSIS_ITEMS = [
        'service_clarity'  => 'サービスの明確さ',
        'locality'         => '地域性の明確さ',
        'expertise'        => '専門性・信頼性',
        'track_record'     => '実績の見せ方',
        'faq'              => 'FAQ整備',
        'pricing'          => '料金・流れの明示',
        'company_info'     => '会社情報の明示',
        'heading_structure' => '見出し構造',
        'uniqueness'       => '独自性',
        'internal_links'   => '内部リンク設計',
    ];

    /**
     * サイト診断データを取得
     *
     * @param int $user_id
     * @return array
     */
    public function get_site_diagnosis( int $user_id ): array {
        $raw = get_user_meta( $user_id, 'gcrev_ai_report_diagnosis', true );
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) && ! empty( $decoded['items'] ) ) {
                return $decoded;
            }
        }

        // 未設定時のデフォルト
        $items = [];
        foreach ( self::DIAGNOSIS_ITEMS as $key => $label ) {
            $items[] = [
                'key'     => $key,
                'label'   => $label,
                'status'  => 'not_addressed',
                'score'   => 0,
                'comment' => '',
                'source'  => 'default',
            ];
        }

        return [
            'version'    => 1,
            'updated_at' => null,
            'items'      => $items,
        ];
    }

    /**
     * サイト診断データを保存（管理者用）
     *
     * @param int   $user_id
     * @param array $items
     * @return bool
     */
    /**
     * サイト診断データを保存
     *
     * @param int   $user_id
     * @param array $items        診断項目配列
     * @param array $crawled_urls クロールしたURL配列（自動診断時のみ）
     * @return bool
     */
    public function save_site_diagnosis( int $user_id, array $items, array $crawled_urls = [] ): bool {
        $tz  = wp_timezone();
        $now = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

        $valid_statuses = [ 'ok', 'caution', 'not_addressed', 'unknown', 'fetch_failed' ];
        $valid_sources  = [ 'manual', 'auto', 'default' ];

        $sanitized_items = [];
        foreach ( $items as $item ) {
            $key = sanitize_text_field( $item['key'] ?? '' );
            if ( ! isset( self::DIAGNOSIS_ITEMS[ $key ] ) ) {
                continue;
            }
            $status = sanitize_text_field( $item['status'] ?? 'not_addressed' );
            if ( ! in_array( $status, $valid_statuses, true ) ) {
                $status = 'not_addressed';
            }
            $source = sanitize_text_field( $item['source'] ?? 'manual' );
            if ( ! in_array( $source, $valid_sources, true ) ) {
                $source = 'manual';
            }

            $sanitized_item = [
                'key'     => $key,
                'label'   => self::DIAGNOSIS_ITEMS[ $key ],
                'status'  => $status,
                'score'   => max( 0, min( 10, absint( $item['score'] ?? 0 ) ) ),
                'comment' => sanitize_text_field( $item['comment'] ?? '' ),
                'source'  => $source,
            ];

            // エビデンス（自動診断時）
            if ( isset( $item['evidence'] ) && is_array( $item['evidence'] ) ) {
                $sanitized_item['evidence'] = [
                    'found'   => array_map( 'sanitize_text_field', (array) ( $item['evidence']['found'] ?? [] ) ),
                    'missing' => array_map( 'sanitize_text_field', (array) ( $item['evidence']['missing'] ?? [] ) ),
                ];
            }

            // 診断日時
            if ( ! empty( $item['diagnosed_at'] ) ) {
                $sanitized_item['diagnosed_at'] = sanitize_text_field( $item['diagnosed_at'] );
            }

            $sanitized_items[] = $sanitized_item;
        }

        // クロールURL
        $sanitized_urls = [];
        foreach ( $crawled_urls as $cu ) {
            $sanitized_urls[] = [
                'url'    => esc_url_raw( $cu['url'] ?? '' ),
                'status' => absint( $cu['status'] ?? 0 ),
                'title'  => sanitize_text_field( $cu['title'] ?? '' ),
            ];
        }

        $version = ! empty( $crawled_urls ) ? 2 : 1;

        $data = [
            'version'    => $version,
            'updated_at' => $now,
            'items'      => $sanitized_items,
        ];
        if ( $version === 2 ) {
            $data['crawled_urls'] = $sanitized_urls;
        }

        return (bool) update_user_meta(
            $user_id,
            'gcrev_ai_report_diagnosis',
            wp_json_encode( $data, JSON_UNESCAPED_UNICODE )
        );
    }

    /**
     * 診断スコアを計算（10項目の平均 × 10）
     */
    private function calc_diagnosis_score( array $diagnosis ): ?int {
        $items = $diagnosis['items'] ?? [];
        if ( empty( $items ) ) {
            return null;
        }

        // 未診断（全てdefault）の場合は null を返す
        $all_default = true;
        foreach ( $items as $item ) {
            if ( ( $item['source'] ?? 'default' ) !== 'default' ) {
                $all_default = false;
                break;
            }
        }
        if ( $all_default ) {
            return null;
        }

        $total = 0;
        $count = 0;
        foreach ( $items as $item ) {
            $total += (int) ( $item['score'] ?? 0 );
            $count++;
        }

        return $count > 0 ? (int) round( ( $total / $count ) * 10 ) : 0;
    }

    // =========================================================
    // 競合分析
    // =========================================================

    /**
     * 全キーワード横断の競合出現分析
     *
     * @param int $user_id
     * @return array
     */
    public function get_competitor_analysis( int $user_id ): array {
        global $wpdb;
        $results_table = $wpdb->prefix . 'gcrev_aio_results';
        $kw_table      = $wpdb->prefix . 'gcrev_rank_keywords';

        $kw_ids_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$kw_table} WHERE user_id = %d AND aio_enabled = 1",
            $user_id
        ), ARRAY_A );

        if ( empty( $kw_ids_rows ) ) {
            return [ 'competitors' => [], 'self_count' => 0 ];
        }

        $kw_ids       = array_column( $kw_ids_rows, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $kw_ids ), '%d' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT extracted_names FROM {$results_table}
             WHERE user_id = %d AND keyword_id IN ({$placeholders})
               AND extracted_names IS NOT NULL AND extracted_names != ''",
            array_merge( [ $user_id ], $kw_ids )
        ), ARRAY_A );

        $company_name = $this->get_company_name( $user_id );
        $aliases      = $this->get_company_aliases( $user_id );

        // 企業名の出現回数を集計
        $name_counts = [];
        $self_count  = 0;
        $total_responses = count( $rows );

        foreach ( $rows as $row ) {
            $names = json_decode( $row['extracted_names'], true );
            if ( ! is_array( $names ) ) {
                continue;
            }
            $seen = []; // 1回答内での重複除去
            foreach ( $names as $name ) {
                $name = trim( $name );
                if ( $name === '' || isset( $seen[ $name ] ) ) {
                    continue;
                }
                $seen[ $name ] = true;

                $is_self = $this->find_self_rank( [ $name ], $company_name, $aliases ) !== null;
                if ( $is_self ) {
                    $self_count++;
                    continue;
                }

                if ( ! isset( $name_counts[ $name ] ) ) {
                    $name_counts[ $name ] = 0;
                }
                $name_counts[ $name ]++;
            }
        }

        arsort( $name_counts );

        $competitors = [];
        $rank = 0;
        foreach ( $name_counts as $name => $count ) {
            $rank++;
            if ( $rank > 10 ) {
                break;
            }
            $competitors[] = [
                'name'         => $name,
                'count'        => $count,
                'rate'         => $total_responses > 0 ? round( ( $count / $total_responses ) * 100, 0 ) : 0,
            ];
        }

        // 地点情報
        $default_location = function_exists( 'gcrev_get_aio_default_location' )
            ? gcrev_get_aio_default_location( $user_id )
            : [ 'location_label' => '' ];

        return [
            'competitors'     => $competitors,
            'self_count'      => $self_count,
            'self_rate'       => $total_responses > 0 ? round( ( $self_count / $total_responses ) * 100, 0 ) : 0,
            'total_responses' => $total_responses,
            'location_label'  => $default_location['location_label'],
        ];
    }

    // =========================================================
    // 改善アクション生成
    // =========================================================

    /**
     * サイト診断結果から改善アクション提案を生成
     *
     * @param int   $user_id
     * @param array $diagnosis
     * @return array
     */
    public function generate_improvement_actions( int $user_id, array $diagnosis ): array {
        $items   = $diagnosis['items'] ?? [];

        // 未診断（全てdefault）の場合は空配列を返す（偽陽性防止）
        $all_default = true;
        foreach ( $items as $item ) {
            if ( ( $item['source'] ?? 'default' ) !== 'default' ) {
                $all_default = false;
                break;
            }
        }
        if ( $all_default ) {
            return [];
        }

        $actions = [];

        // 各診断項目から改善提案を生成
        $action_map = [
            'service_clarity' => [
                'high'   => 'サービス内容の明確化が不足しています。対象業種・サービス範囲を具体的に記載してください。',
                'medium' => 'サービス説明をより具体的にすると、AIに伝わりやすくなります。',
            ],
            'locality' => [
                'high'   => '対応エリアの明記が不足しています。市区町村名を含めて具体的に記載してください。',
                'medium' => 'エリア情報を各ページに分散させると、地域性がAIに伝わりやすくなります。',
            ],
            'expertise' => [
                'high'   => '専門性を示す情報（資格・実績年数等）の掲載を検討してください。',
                'medium' => '専門分野の説明を充実させると、信頼性が向上します。',
            ],
            'track_record' => [
                'high'   => '実績ページの作成・充実を優先してください。事例は具体的な数字付きが効果的です。',
                'medium' => '実績ページへの導線を強化してください。',
            ],
            'faq' => [
                'high'   => 'FAQページが不足しています。よくある質問を10件以上整備してください。',
                'medium' => 'FAQの内容を充実させると、AIの回答材料になります。',
            ],
            'pricing' => [
                'high'   => '料金・サービスの流れの明示が不足しています。概算でも掲載してください。',
                'medium' => '料金ページへの導線を追加してください。',
            ],
            'company_info' => [
                'high'   => '会社概要情報の補強が必要です。所在地・代表者・設立年等を明記してください。',
                'medium' => '会社概要にスタッフ紹介や理念を追加すると効果的です。',
            ],
            'heading_structure' => [
                'high'   => '見出し構造（h1-h3）の整備が必要です。検索意図に沿った見出しを設定してください。',
                'medium' => '見出しにキーワードを自然に含めると、AIの理解度が向上します。',
            ],
            'uniqueness' => [
                'high'   => '他社との差別化ポイントを明確にしてください。',
                'medium' => '独自の強み・特徴をより具体的に伝えると効果的です。',
            ],
            'internal_links' => [
                'high'   => '内部リンク設計の改善が必要です。関連ページ同士をつなげてください。',
                'medium' => 'サービスページから実績・FAQへの導線を追加してください。',
            ],
        ];

        foreach ( $items as $item ) {
            $key    = $item['key'] ?? '';
            $status = $item['status'] ?? 'not_addressed';
            $score  = (int) ( $item['score'] ?? 0 );

            if ( $status === 'ok' && $score >= 7 ) {
                continue; // 問題なし
            }

            $priority = 'low';
            if ( $status === 'not_addressed' || $score <= 3 ) {
                $priority = 'high';
            } elseif ( $status === 'caution' || $score <= 6 ) {
                $priority = 'medium';
            }

            $msg_key = $priority === 'low' ? 'medium' : $priority;
            $description = $action_map[ $key ][ $msg_key ] ?? '';

            if ( $description !== '' ) {
                $actions[] = [
                    'key'         => $key,
                    'label'       => self::DIAGNOSIS_ITEMS[ $key ] ?? $key,
                    'priority'    => $priority,
                    'description' => $item['comment'] !== '' ? $item['comment'] : $description,
                    'score'       => $score,
                ];
            }
        }

        // 優先度順にソート（high → medium → low）
        $priority_order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
        usort( $actions, function ( $a, $b ) use ( $priority_order ) {
            return ( $priority_order[ $a['priority'] ] ?? 9 ) - ( $priority_order[ $b['priority'] ] ?? 9 );
        } );

        return $actions;
    }

    // =========================================================
    // サイト診断実行エンジン（クロール + 解析）
    // =========================================================

    /**
     * クライアントのサイトURLを解決
     */
    private function resolve_site_url( int $user_id ): string {
        $settings = gcrev_get_client_settings( $user_id );
        $url = $settings['site_url'] ?? '';

        if ( empty( $url ) ) {
            $url = get_user_meta( $user_id, 'report_site_url', true );
        }
        if ( empty( $url ) ) {
            $url = get_user_meta( $user_id, 'weisite_url', true );
        }

        return $url ? trailingslashit( esc_url_raw( $url ) ) : '';
    }

    /**
     * サイトをクロールして HTML を取得
     *
     * @param string $site_url サイトトップURL
     * @return array [ 'crawled_urls' => [...], 'errors' => [...] ]
     */
    private function crawl_site( string $site_url ): array {
        $crawled = [];
        $errors  = [];
        $host    = wp_parse_url( $site_url, PHP_URL_HOST );

        // ホームページ取得
        $home_result = $this->fetch_page( $site_url );
        $crawled[] = $home_result;

        if ( $home_result['status'] < 200 || $home_result['status'] >= 400 ) {
            $errors[] = $site_url . ' returned ' . $home_result['status'];
            return [ 'crawled_urls' => $crawled, 'errors' => $errors ];
        }

        // ホームから内部リンクを収集
        $discovered = $this->discover_internal_links( $home_result['html'], $site_url, $host );

        // 優先スラッグでスコアリング・ソート
        $priority_slugs = [
            'service', 'about', 'faq', 'price', 'pricing', 'company',
            'flow', 'works', 'case', 'staff', 'contact', 'access',
            'voice', 'portfolio', 'blog', 'news',
        ];

        usort( $discovered, function ( $a, $b ) use ( $priority_slugs ) {
            $score_a = $this->slug_priority_score( $a, $priority_slugs );
            $score_b = $this->slug_priority_score( $b, $priority_slugs );
            return $score_b - $score_a;
        } );

        // 最大5サブページを取得
        $fetched_count = 0;
        foreach ( $discovered as $url ) {
            if ( $fetched_count >= 5 ) {
                break;
            }
            $result = $this->fetch_page( $url );
            $crawled[] = $result;
            $fetched_count++;

            if ( $result['status'] < 200 || $result['status'] >= 400 ) {
                $errors[] = $url . ' returned ' . $result['status'];
            }
        }

        return [ 'crawled_urls' => $crawled, 'errors' => $errors ];
    }

    /**
     * 1ページを取得
     */
    private function fetch_page( string $url ): array {
        $res = wp_remote_get( $url, [
            'timeout'             => 10,
            'redirection'         => 3,
            'user-agent'          => 'MimamoriDiag/1.0',
            'limit_response_size' => 512000, // 500KB
        ] );

        if ( is_wp_error( $res ) ) {
            return [
                'url'    => $url,
                'status' => 0,
                'title'  => '',
                'html'   => '',
            ];
        }

        $code = wp_remote_retrieve_response_code( $res );
        $html = wp_remote_retrieve_body( $res );

        // 文字コード変換
        $html = $this->ensure_utf8( $html );

        // タイトル抽出
        $title = '';
        if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/is', $html, $m ) ) {
            $title = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
        }

        return [
            'url'    => $url,
            'status' => $code,
            'title'  => $title,
            'html'   => $html,
        ];
    }

    /**
     * UTF-8 に変換
     */
    private function ensure_utf8( string $html ): string {
        // charset 指定を確認
        if ( preg_match( '/charset=["\']?([a-zA-Z0-9_-]+)/i', $html, $m ) ) {
            $charset = strtolower( $m[1] );
            if ( $charset !== 'utf-8' && $charset !== 'utf8' ) {
                $converted = @mb_convert_encoding( $html, 'UTF-8', $charset );
                if ( $converted !== false ) {
                    return $converted;
                }
            }
        }

        // 自動検出
        $detected = mb_detect_encoding( $html, [ 'UTF-8', 'SJIS', 'EUC-JP', 'ISO-8859-1' ], true );
        if ( $detected && $detected !== 'UTF-8' ) {
            $converted = @mb_convert_encoding( $html, 'UTF-8', $detected );
            if ( $converted !== false ) {
                return $converted;
            }
        }

        return $html;
    }

    /**
     * ホームページの HTML から同ドメイン内部リンクを収集
     */
    private function discover_internal_links( string $html, string $site_url, string $host ): array {
        $links = [];
        $seen  = [ rtrim( $site_url, '/' ) => true ];

        // 除外パターン
        $exclude_patterns = [
            '/wp-admin/', '/wp-login', '/feed/', '/wp-content/',
            '/wp-includes/', '/wp-json/', '/xmlrpc.php',
        ];
        $exclude_extensions = [ '.jpg', '.jpeg', '.png', '.gif', '.svg', '.webp', '.pdf', '.zip', '.css', '.js' ];

        libxml_use_internal_errors( true );
        $dom = new \DOMDocument();
        @$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        $xpath = new \DOMXPath( $dom );

        $anchors = $xpath->query( '//a[@href]' );
        if ( $anchors ) {
            foreach ( $anchors as $a ) {
                $href = trim( $a->getAttribute( 'href' ) );
                if ( $href === '' || $href[0] === '#' || strpos( $href, 'mailto:' ) === 0 || strpos( $href, 'tel:' ) === 0 || strpos( $href, 'javascript:' ) === 0 ) {
                    continue;
                }

                // 相対パスを絶対URLに変換
                if ( strpos( $href, 'http' ) !== 0 ) {
                    $href = rtrim( $site_url, '/' ) . '/' . ltrim( $href, '/' );
                }

                // クエリ文字列・アンカー除去
                $href = strtok( $href, '?#' );
                $href = trailingslashit( $href );

                // 同ドメインチェック
                $parsed_host = wp_parse_url( $href, PHP_URL_HOST );
                if ( $parsed_host !== $host ) {
                    continue;
                }

                // 除外チェック
                $path = wp_parse_url( $href, PHP_URL_PATH ) ?? '/';
                $skip = false;
                foreach ( $exclude_patterns as $pat ) {
                    if ( strpos( $path, $pat ) !== false ) {
                        $skip = true;
                        break;
                    }
                }
                if ( ! $skip ) {
                    foreach ( $exclude_extensions as $ext ) {
                        if ( substr( strtolower( $path ), -strlen( $ext ) ) === $ext ) {
                            $skip = true;
                            break;
                        }
                    }
                }
                if ( $skip ) {
                    continue;
                }

                $normalized = rtrim( $href, '/' );
                if ( isset( $seen[ $normalized ] ) ) {
                    continue;
                }
                $seen[ $normalized ] = true;
                $links[] = $href;
            }
        }
        libxml_clear_errors();

        return $links;
    }

    /**
     * URLのスラッグ優先度スコア
     */
    private function slug_priority_score( string $url, array $priority_slugs ): int {
        $path = strtolower( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );
        foreach ( $priority_slugs as $i => $slug ) {
            if ( strpos( $path, '/' . $slug ) !== false ) {
                return count( $priority_slugs ) - $i;
            }
        }
        return 0;
    }

    /**
     * 1ページの HTML からシグナルを抽出
     */
    private function extract_page_signals( string $html, string $url ): array {
        $signals = [
            'url'              => $url,
            'title'            => '',
            'meta_description' => '',
            'h1'               => [],
            'headings'         => [],
            'json_ld'          => [],
            'body_text'        => '',
            'internal_links'   => [],
            'has_breadcrumbs'  => false,
            'word_count'       => 0,
        ];

        if ( empty( $html ) ) {
            return $signals;
        }

        libxml_use_internal_errors( true );
        $dom = new \DOMDocument();
        @$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        $xpath = new \DOMXPath( $dom );

        // title
        $title_nodes = $xpath->query( '//title' );
        if ( $title_nodes && $title_nodes->length > 0 ) {
            $signals['title'] = trim( $title_nodes->item( 0 )->textContent );
        }

        // meta description
        $meta_nodes = $xpath->query( '//meta[@name="description"]' );
        if ( $meta_nodes && $meta_nodes->length > 0 ) {
            $signals['meta_description'] = trim( $meta_nodes->item( 0 )->getAttribute( 'content' ) );
        }

        // headings
        for ( $level = 1; $level <= 6; $level++ ) {
            $h_nodes = $xpath->query( '//h' . $level );
            if ( $h_nodes ) {
                foreach ( $h_nodes as $h ) {
                    $text = trim( $h->textContent );
                    if ( $text === '' ) {
                        continue;
                    }
                    $signals['headings'][] = [ 'level' => $level, 'text' => $text ];
                    if ( $level === 1 ) {
                        $signals['h1'][] = $text;
                    }
                }
            }
        }

        // JSON-LD
        $scripts = $xpath->query( '//script[@type="application/ld+json"]' );
        if ( $scripts ) {
            foreach ( $scripts as $script ) {
                $json = json_decode( trim( $script->textContent ), true );
                if ( is_array( $json ) ) {
                    $signals['json_ld'][] = $json;
                }
            }
        }

        // body text（script, style, nav, footer を除去）
        $body_nodes = $xpath->query( '//body' );
        if ( $body_nodes && $body_nodes->length > 0 ) {
            $body = $body_nodes->item( 0 );
            $clone = $body->cloneNode( true );

            // 不要要素を除去
            foreach ( [ 'script', 'style', 'nav', 'footer', 'header' ] as $tag ) {
                $removes = [];
                foreach ( $clone->getElementsByTagName( $tag ) as $el ) {
                    $removes[] = $el;
                }
                foreach ( $removes as $el ) {
                    $el->parentNode->removeChild( $el );
                }
            }

            $text = trim( $clone->textContent );
            $text = preg_replace( '/\s+/', ' ', $text );
            $signals['body_text']  = mb_substr( $text, 0, 5000 );
            $signals['word_count'] = mb_strlen( $text );
        }

        // internal links
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $anchors = $xpath->query( '//a[@href]' );
        if ( $anchors ) {
            foreach ( $anchors as $a ) {
                $href = trim( $a->getAttribute( 'href' ) );
                $link_host = wp_parse_url( $href, PHP_URL_HOST );
                if ( $link_host === $host || ( $link_host === null && strpos( $href, '/' ) === 0 ) ) {
                    $signals['internal_links'][] = $href;
                }
            }
            $signals['internal_links'] = array_unique( $signals['internal_links'] );
        }

        // breadcrumbs
        $breadcrumb_navs = $xpath->query( '//nav[contains(@class, "breadcrumb")]' );
        if ( $breadcrumb_navs && $breadcrumb_navs->length > 0 ) {
            $signals['has_breadcrumbs'] = true;
        }
        if ( ! $signals['has_breadcrumbs'] ) {
            foreach ( $signals['json_ld'] as $ld ) {
                $type = $ld['@type'] ?? '';
                if ( $type === 'BreadcrumbList' || ( isset( $ld['@graph'] ) && is_array( $ld['@graph'] ) ) ) {
                    if ( $type === 'BreadcrumbList' ) {
                        $signals['has_breadcrumbs'] = true;
                    }
                    if ( isset( $ld['@graph'] ) ) {
                        foreach ( $ld['@graph'] as $item ) {
                            if ( ( $item['@type'] ?? '' ) === 'BreadcrumbList' ) {
                                $signals['has_breadcrumbs'] = true;
                                break;
                            }
                        }
                    }
                }
            }
        }

        libxml_clear_errors();
        return $signals;
    }

    /**
     * 10項目のルールベース診断を実行
     */
    private function analyze_diagnosis_item( string $key, array $all_signals, array $client_settings ): array {
        $tz  = wp_timezone();
        $now = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'c' );

        $result = [
            'key'          => $key,
            'label'        => self::DIAGNOSIS_ITEMS[ $key ] ?? $key,
            'status'       => 'not_addressed',
            'score'        => 0,
            'comment'      => '',
            'source'       => 'auto',
            'evidence'     => [ 'found' => [], 'missing' => [] ],
            'diagnosed_at' => $now,
        ];

        // ページ取得がゼロの場合
        $valid_pages = array_filter( $all_signals, function ( $s ) {
            return ! empty( $s['body_text'] );
        } );
        if ( empty( $valid_pages ) ) {
            $result['status']  = 'fetch_failed';
            $result['comment'] = 'ページの取得に失敗したため診断できません。';
            return $result;
        }

        $home = $all_signals[0] ?? [];

        switch ( $key ) {
            case 'service_clarity':
                $result = $this->diagnose_service_clarity( $result, $all_signals, $home );
                break;
            case 'locality':
                $result = $this->diagnose_locality( $result, $all_signals, $home, $client_settings );
                break;
            case 'expertise':
                $result = $this->diagnose_expertise( $result, $all_signals );
                break;
            case 'track_record':
                $result = $this->diagnose_track_record( $result, $all_signals );
                break;
            case 'faq':
                $result = $this->diagnose_faq( $result, $all_signals );
                break;
            case 'pricing':
                $result = $this->diagnose_pricing( $result, $all_signals );
                break;
            case 'company_info':
                $result = $this->diagnose_company_info( $result, $all_signals );
                break;
            case 'heading_structure':
                $result = $this->diagnose_heading_structure( $result, $all_signals );
                break;
            case 'uniqueness':
                $result = $this->diagnose_uniqueness( $result, $all_signals );
                break;
            case 'internal_links':
                $result = $this->diagnose_internal_links( $result, $all_signals, $home );
                break;
        }

        // ステータスをスコアから決定
        if ( $result['status'] !== 'fetch_failed' ) {
            if ( $result['score'] >= 8 ) {
                $result['status'] = 'ok';
            } elseif ( $result['score'] >= 4 ) {
                $result['status'] = 'caution';
            } else {
                $result['status'] = 'not_addressed';
            }
        }

        return $result;
    }

    // --- 各診断項目の個別ロジック ---

    private function diagnose_service_clarity( array $r, array $all_signals, array $home ): array {
        $score = 0;

        // meta description
        $meta = $home['meta_description'] ?? '';
        if ( mb_strlen( $meta ) >= 50 ) {
            $score += 3;
            $r['evidence']['found'][] = 'メタディスクリプション: ' . mb_strlen( $meta ) . '文字';
        } elseif ( ! empty( $meta ) ) {
            $score += 1;
            $r['evidence']['found'][] = 'メタディスクリプション: ' . mb_strlen( $meta ) . '文字（短め）';
        } else {
            $r['evidence']['missing'][] = 'メタディスクリプション未設定';
        }

        // h1 にサービス関連ワード
        $h1_texts = $home['h1'] ?? [];
        $h1_joined = implode( ' ', $h1_texts );
        if ( ! empty( $h1_texts ) ) {
            $score += 2;
            $r['evidence']['found'][] = 'h1: 「' . mb_substr( $h1_joined, 0, 50 ) . '」';
        } else {
            $r['evidence']['missing'][] = 'トップページにh1なし';
        }

        // 本文の充実度
        $body_len = $home['word_count'] ?? 0;
        if ( $body_len >= 1000 ) {
            $score += 3;
            $r['evidence']['found'][] = 'トップページ本文: 約' . number_format( $body_len ) . '文字';
        } elseif ( $body_len >= 300 ) {
            $score += 2;
            $r['evidence']['found'][] = 'トップページ本文: 約' . number_format( $body_len ) . '文字（やや少なめ）';
        } else {
            $r['evidence']['missing'][] = 'トップページの本文が少ない（約' . number_format( $body_len ) . '文字）';
        }

        // サービスページの存在
        foreach ( $all_signals as $s ) {
            $path = strtolower( wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '' );
            if ( strpos( $path, '/service' ) !== false || strpos( $path, '/menu' ) !== false ) {
                $score += 2;
                $r['evidence']['found'][] = 'サービスページ検出: ' . $s['url'];
                break;
            }
        }

        $r['score'] = min( 10, $score );
        $r['comment'] = $score >= 8
            ? 'サービス内容が明確に記載されています。'
            : ( $score >= 4 ? 'サービス説明はありますが、より具体的にすると効果的です。' : 'サービス内容の記載が不足しています。' );
        return $r;
    }

    private function diagnose_locality( array $r, array $all_signals, array $home, array $settings ): array {
        $score = 0;
        $area_pref = $settings['area_pref'] ?? '';
        $area_city = $settings['area_city'] ?? '';

        if ( empty( $area_pref ) && empty( $area_city ) ) {
            $r['score'] = 5;
            $r['comment'] = 'クライアント設定にエリア情報がないため、地域性の評価は限定的です。';
            $r['evidence']['missing'][] = 'クライアント設定にエリア未設定';
            return $r;
        }

        $search_terms = array_filter( [ $area_pref, $area_city ] );

        foreach ( $all_signals as $s ) {
            $title = $s['title'] ?? '';
            $h1    = implode( ' ', $s['h1'] ?? [] );
            $body  = $s['body_text'] ?? '';
            $url_label = wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '/';

            foreach ( $search_terms as $term ) {
                if ( empty( $term ) ) continue;

                // タイトルに含まれるか
                if ( mb_strpos( $title, $term ) !== false ) {
                    $score += 2;
                    $r['evidence']['found'][] = '「' . $term . '」がタイトルに含まれる（' . $url_label . '）';
                }
                // h1に含まれるか
                if ( mb_strpos( $h1, $term ) !== false ) {
                    $score += 2;
                    $r['evidence']['found'][] = '「' . $term . '」がh1に含まれる（' . $url_label . '）';
                }
                // 本文中の出現回数
                $count = mb_substr_count( $body, $term );
                if ( $count > 0 ) {
                    $score += min( 2, $count );
                    $r['evidence']['found'][] = '「' . $term . '」が本文中に' . $count . '回出現（' . $url_label . '）';
                }
            }
        }

        // 重複を除去
        $r['evidence']['found'] = array_values( array_unique( $r['evidence']['found'] ) );

        if ( $score === 0 ) {
            $r['evidence']['missing'][] = '設定エリア（' . implode( '・', $search_terms ) . '）がサイト内に見つかりません';
        }

        $r['score'] = min( 10, $score );
        $r['comment'] = $score >= 8
            ? '地域情報が適切に記載されています。'
            : ( $score >= 4 ? '地域情報はありますが、より多くのページに記載すると効果的です。' : '地域情報の記載が不足しています。' );
        return $r;
    }

    private function diagnose_expertise( array $r, array $all_signals ): array {
        $score = 0;
        $keywords = [ '資格', '認定', '免許', '国家資格', '経験', '受賞', '表彰', '専門', '認証', '登録', '許可', '年の実績' ];

        foreach ( $all_signals as $s ) {
            $body = $s['body_text'] ?? '';
            $url_label = wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '/';

            foreach ( $keywords as $kw ) {
                if ( mb_strpos( $body, $kw ) !== false ) {
                    $score += 1;
                    $r['evidence']['found'][] = '「' . $kw . '」を検出（' . $url_label . '）';
                }
            }

            // JSON-LD で Organization / Person
            foreach ( $s['json_ld'] ?? [] as $ld ) {
                $type = $ld['@type'] ?? '';
                if ( in_array( $type, [ 'Organization', 'Person', 'ProfessionalService' ], true ) ) {
                    $score += 2;
                    $r['evidence']['found'][] = 'JSON-LD ' . $type . ' 検出（' . $url_label . '）';
                }
            }
        }

        $r['evidence']['found'] = array_values( array_unique( $r['evidence']['found'] ) );
        if ( empty( $r['evidence']['found'] ) ) {
            $r['evidence']['missing'][] = '専門性・信頼性を示すキーワードが見つかりません';
        }

        $r['score'] = min( 10, $score );
        $r['comment'] = $score >= 8
            ? '専門性・信頼性の情報が充実しています。'
            : ( $score >= 4 ? '専門性の記載はありますが、さらに充実させると効果的です。' : '専門性・信頼性を示す情報が不足しています。' );
        return $r;
    }

    private function diagnose_track_record( array $r, array $all_signals ): array {
        $score = 0;
        $keywords = [ '実績', '事例', 'お客様の声', '導入事例', 'ポートフォリオ', '施工例', '制作実績' ];

        // 実績ページの存在
        foreach ( $all_signals as $s ) {
            $path = strtolower( wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '' );
            if ( preg_match( '/(works|case|voice|portfolio|results|jisseki)/', $path ) ) {
                $score += 3;
                $r['evidence']['found'][] = '実績ページ検出: ' . $s['url'];
            }
        }

        // キーワード + 数値パターン
        foreach ( $all_signals as $s ) {
            $body = $s['body_text'] ?? '';
            $url_label = wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '/';

            foreach ( $keywords as $kw ) {
                if ( mb_strpos( $body, $kw ) !== false ) {
                    $score += 1;
                    $r['evidence']['found'][] = '「' . $kw . '」を検出（' . $url_label . '）';
                }
            }

            // 数値 + 件/社/年 パターン
            if ( preg_match( '/[0-9,]+\s*(件|社|年|棟|台|回|名)/u', $body, $m ) ) {
                $score += 2;
                $r['evidence']['found'][] = '数値実績「' . mb_substr( $m[0], 0, 20 ) . '」検出（' . $url_label . '）';
            }
        }

        $r['evidence']['found'] = array_values( array_unique( $r['evidence']['found'] ) );
        if ( empty( $r['evidence']['found'] ) ) {
            $r['evidence']['missing'][] = '実績・事例に関する情報が見つかりません';
        }

        $r['score'] = min( 10, $score );
        $r['comment'] = $score >= 8
            ? '実績情報が充実しています。'
            : ( $score >= 4 ? '実績情報はありますが、具体的な数字や事例を追加すると効果的です。' : '実績・事例の掲載が不足しています。' );
        return $r;
    }

    private function diagnose_faq( array $r, array $all_signals ): array {
        $score = 0;

        // FAQ ページの存在
        $faq_page_found = false;
        foreach ( $all_signals as $s ) {
            $path = strtolower( wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '' );
            if ( strpos( $path, '/faq' ) !== false || strpos( $path, '/question' ) !== false ) {
                $faq_page_found = true;
                $score += 3;
                $r['evidence']['found'][] = 'FAQページ検出: ' . $s['url'];
            }
        }

        // JSON-LD FAQPage
        foreach ( $all_signals as $s ) {
            foreach ( $s['json_ld'] ?? [] as $ld ) {
                $type = $ld['@type'] ?? '';
                if ( $type === 'FAQPage' ) {
                    $qa_count = count( $ld['mainEntity'] ?? [] );
                    $score += 3;
                    $r['evidence']['found'][] = 'JSON-LD FAQPage構造化データ: ' . $qa_count . '件（' . ( wp_parse_url( $s['url'], PHP_URL_PATH ) ?? '/' ) . '）';
                }
                // @graph 内もチェック
                if ( isset( $ld['@graph'] ) && is_array( $ld['@graph'] ) ) {
                    foreach ( $ld['@graph'] as $item ) {
                        if ( ( $item['@type'] ?? '' ) === 'FAQPage' ) {
                            $qa_count = count( $item['mainEntity'] ?? [] );
                            $score += 3;
                            $r['evidence']['found'][] = 'JSON-LD FAQPage構造化データ: ' . $qa_count . '件';
                        }
                    }
                }
            }
        }

        // Q&A パターンの検出
        foreach ( $all_signals as $s ) {
            $body = $s['body_text'] ?? '';
            $url_label = wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '/';

            $qa_patterns = [ 'よくある質問', 'よくあるご質問', 'FAQ', 'Q.', 'Q：', 'Q:' ];
            foreach ( $qa_patterns as $pat ) {
                $count = mb_substr_count( $body, $pat );
                if ( $count > 0 ) {
                    $score += min( 2, $count );
                    $r['evidence']['found'][] = '「' . $pat . '」パターン: ' . $count . '件（' . $url_label . '）';
                    break;
                }
            }
        }

        $r['evidence']['found'] = array_values( array_unique( $r['evidence']['found'] ) );
        if ( ! $faq_page_found ) {
            $r['evidence']['missing'][] = 'FAQページが見つかりません';
        }

        $r['score'] = min( 10, $score );
        $r['comment'] = $score >= 8
            ? 'FAQ情報が充実しています。'
            : ( $score >= 4 ? 'FAQはありますが、質問数を増やすとAIの回答材料になります。' : 'FAQ整備が不足しています。' );
        return $r;
    }

    private function diagnose_pricing( array $r, array $all_signals ): array {
        $score = 0;

        // 料金ページの存在
        foreach ( $all_signals as $s ) {
            $path = strtolower( wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '' );
            if ( preg_match( '/(price|pricing|fee|plan|ryoukin|cost)/', $path ) ) {
                $score += 3;
                $r['evidence']['found'][] = '料金ページ検出: ' . $s['url'];
            }
        }

        // 料金関連キーワード
        $price_keywords = [ '円', '税込', '税別', '料金', '費用', '価格', 'プラン', 'コース', '月額', '年額', '初期費用', '見積' ];
        foreach ( $all_signals as $s ) {
            $body = $s['body_text'] ?? '';
            $url_label = wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '/';

            foreach ( $price_keywords as $kw ) {
                if ( mb_strpos( $body, $kw ) !== false ) {
                    $score += 1;
                    $r['evidence']['found'][] = '「' . $kw . '」を検出（' . $url_label . '）';
                }
            }
        }

        // 流れ・ステップ
        $flow_keywords = [ 'ご利用の流れ', 'お申し込みの流れ', 'ステップ', 'STEP', '納品まで' ];
        foreach ( $all_signals as $s ) {
            $body = $s['body_text'] ?? '';
            $url_label = wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '/';

            foreach ( $flow_keywords as $kw ) {
                if ( mb_strpos( $body, $kw ) !== false ) {
                    $score += 1;
                    $r['evidence']['found'][] = '「' . $kw . '」を検出（' . $url_label . '）';
                    break;
                }
            }
        }

        $r['evidence']['found'] = array_values( array_unique( $r['evidence']['found'] ) );
        if ( empty( $r['evidence']['found'] ) ) {
            $r['evidence']['missing'][] = '料金・費用に関する情報が見つかりません';
        }

        $r['score'] = min( 10, $score );
        $r['comment'] = $score >= 8
            ? '料金・サービスの流れが明示されています。'
            : ( $score >= 4 ? '料金情報はありますが、より明確に提示すると効果的です。' : '料金・流れの明示が不足しています。' );
        return $r;
    }

    private function diagnose_company_info( array $r, array $all_signals ): array {
        $score = 0;
        $keywords = [ '代表', '社長', '所在地', '住所', 'TEL', '電話番号', '設立', '創業', 'メールアドレス', '資本金' ];

        // 会社概要ページの存在
        foreach ( $all_signals as $s ) {
            $path = strtolower( wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '' );
            if ( preg_match( '/(company|about|profile|gaiyou|corporate)/', $path ) ) {
                $score += 2;
                $r['evidence']['found'][] = '会社概要ページ検出: ' . $s['url'];
            }
        }

        // キーワード検出
        foreach ( $all_signals as $s ) {
            $body = $s['body_text'] ?? '';
            $url_label = wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '/';

            foreach ( $keywords as $kw ) {
                if ( mb_strpos( $body, $kw ) !== false ) {
                    $score += 1;
                    $r['evidence']['found'][] = '「' . $kw . '」を検出（' . $url_label . '）';
                }
            }
        }

        // JSON-LD Organization / LocalBusiness
        foreach ( $all_signals as $s ) {
            foreach ( $s['json_ld'] ?? [] as $ld ) {
                $type = $ld['@type'] ?? '';
                if ( in_array( $type, [ 'Organization', 'LocalBusiness', 'Corporation' ], true ) ) {
                    $score += 2;
                    $r['evidence']['found'][] = 'JSON-LD ' . $type . ' 検出';
                }
                if ( isset( $ld['@graph'] ) && is_array( $ld['@graph'] ) ) {
                    foreach ( $ld['@graph'] as $item ) {
                        if ( in_array( $item['@type'] ?? '', [ 'Organization', 'LocalBusiness', 'Corporation' ], true ) ) {
                            $score += 2;
                            $r['evidence']['found'][] = 'JSON-LD ' . $item['@type'] . ' 検出';
                        }
                    }
                }
            }
        }

        $r['evidence']['found'] = array_values( array_unique( $r['evidence']['found'] ) );
        if ( empty( $r['evidence']['found'] ) ) {
            $r['evidence']['missing'][] = '会社情報が見つかりません';
        }

        $r['score'] = min( 10, $score );
        $r['comment'] = $score >= 8
            ? '会社情報が充実しています。'
            : ( $score >= 4 ? '会社情報はありますが、所在地・代表者等を追加すると信頼性が向上します。' : '会社情報の明示が不足しています。' );
        return $r;
    }

    private function diagnose_heading_structure( array $r, array $all_signals ): array {
        $score = 10; // 減点方式
        $issues = [];

        foreach ( $all_signals as $s ) {
            $headings = $s['headings'] ?? [];
            $h1s = array_filter( $headings, function ( $h ) { return $h['level'] === 1; } );
            $url_label = wp_parse_url( $s['url'] ?? '', PHP_URL_PATH ) ?? '/';

            // h1 が 0 または 2以上
            $h1_count = count( $h1s );
            if ( $h1_count === 0 ) {
                $score -= 2;
                $r['evidence']['missing'][] = 'h1なし（' . $url_label . '）';
            } elseif ( $h1_count > 1 ) {
                $score -= 1;
                $issues[] = 'h1が' . $h1_count . '個（' . $url_label . '）';
            } else {
                $r['evidence']['found'][] = 'h1が1件（正常）（' . $url_label . '）';
            }

            // 見出し階層スキップチェック
            $prev_level = 0;
            foreach ( $headings as $h ) {
                if ( $prev_level > 0 && $h['level'] > $prev_level + 1 ) {
                    $score -= 1;
                    $issues[] = 'h' . $prev_level . '→h' . $h['level'] . 'のスキップ（' . $url_label . '）';
                    break; // 1ページにつき1回だけ
                }
                $prev_level = $h['level'];
            }

            // 見出し数の充実度
            if ( count( $headings ) >= 5 ) {
                $r['evidence']['found'][] = '見出し' . count( $headings ) . '件（' . $url_label . '）';
            } elseif ( count( $headings ) < 3 && ! empty( $s['body_text'] ) ) {
                $score -= 1;
                $r['evidence']['missing'][] = '見出しが少ない: ' . count( $headings ) . '件（' . $url_label . '）';
            }
        }

        foreach ( $issues as $issue ) {
            $r['evidence']['missing'][] = $issue;
        }

        $r['evidence']['found']   = array_values( array_unique( $r['evidence']['found'] ) );
        $r['evidence']['missing'] = array_values( array_unique( $r['evidence']['missing'] ) );

        $r['score'] = max( 0, min( 10, $score ) );
        $r['comment'] = $r['score'] >= 8
            ? '見出し構造が適切に設計されています。'
            : ( $r['score'] >= 4 ? '見出し構造に一部改善の余地があります。' : '見出し構造の整備が必要です。' );
        return $r;
    }

    private function diagnose_uniqueness( array $r, array $all_signals ): array {
        $score = 0;

        // メタディスクリプションの多様性
        $meta_descs = [];
        foreach ( $all_signals as $s ) {
            $meta = $s['meta_description'] ?? '';
            if ( ! empty( $meta ) ) {
                $meta_descs[] = $meta;
            }
        }

        if ( count( $meta_descs ) >= 2 ) {
            $unique_metas = array_unique( $meta_descs );
            if ( count( $unique_metas ) === count( $meta_descs ) ) {
                $score += 3;
                $r['evidence']['found'][] = 'メタディスクリプションが各ページで異なる（' . count( $meta_descs ) . 'ページ）';
            } else {
                $score += 1;
                $r['evidence']['missing'][] = 'メタディスクリプションが一部のページで重複';
            }
        } elseif ( count( $meta_descs ) === 1 ) {
            $score += 1;
            $r['evidence']['found'][] = 'メタディスクリプション設定あり（1ページのみ確認）';
        } else {
            $r['evidence']['missing'][] = 'メタディスクリプション未設定';
        }

        // 本文量（充実度）
        $total_content = 0;
        foreach ( $all_signals as $s ) {
            $total_content += $s['word_count'] ?? 0;
        }
        if ( $total_content >= 5000 ) {
            $score += 3;
            $r['evidence']['found'][] = 'サイト全体のコンテンツ量: 約' . number_format( $total_content ) . '文字';
        } elseif ( $total_content >= 2000 ) {
            $score += 2;
            $r['evidence']['found'][] = 'サイト全体のコンテンツ量: 約' . number_format( $total_content ) . '文字（やや少なめ）';
        } else {
            $r['evidence']['missing'][] = 'コンテンツ量が少ない（約' . number_format( $total_content ) . '文字）';
        }

        // タイトルの多様性
        $titles = [];
        foreach ( $all_signals as $s ) {
            $t = $s['title'] ?? '';
            if ( ! empty( $t ) ) {
                $titles[] = $t;
            }
        }
        if ( count( $titles ) >= 2 ) {
            $unique_titles = array_unique( $titles );
            if ( count( $unique_titles ) === count( $titles ) ) {
                $score += 2;
                $r['evidence']['found'][] = 'タイトルが各ページで異なる';
            } else {
                $r['evidence']['missing'][] = 'タイトルが一部のページで重複';
            }
        }

        // ページ数の多様性
        if ( count( $all_signals ) >= 4 ) {
            $score += 2;
            $r['evidence']['found'][] = '診断ページ数: ' . count( $all_signals ) . 'ページ';
        }

        $r['evidence']['found']   = array_values( array_unique( $r['evidence']['found'] ) );
        $r['evidence']['missing'] = array_values( array_unique( $r['evidence']['missing'] ) );

        $r['score'] = min( 10, $score );
        $r['comment'] = $score >= 8
            ? 'コンテンツの独自性が高いです。'
            : ( $score >= 4 ? 'コンテンツはありますが、各ページの差別化を進めると効果的です。' : '独自性のあるコンテンツが不足しています。' );
        return $r;
    }

    private function diagnose_internal_links( array $r, array $all_signals, array $home ): array {
        $score = 0;

        // ホームからの内部リンク数
        $home_links = $home['internal_links'] ?? [];
        $link_count = count( $home_links );

        if ( $link_count >= 20 ) {
            $score += 3;
            $r['evidence']['found'][] = 'トップページ内部リンク: ' . $link_count . '件';
        } elseif ( $link_count >= 10 ) {
            $score += 2;
            $r['evidence']['found'][] = 'トップページ内部リンク: ' . $link_count . '件';
        } elseif ( $link_count > 0 ) {
            $score += 1;
            $r['evidence']['found'][] = 'トップページ内部リンク: ' . $link_count . '件（少なめ）';
        } else {
            $r['evidence']['missing'][] = 'トップページに内部リンクなし';
        }

        // パンくず
        $has_breadcrumbs = false;
        foreach ( $all_signals as $s ) {
            if ( $s['has_breadcrumbs'] ?? false ) {
                $has_breadcrumbs = true;
                $score += 2;
                $r['evidence']['found'][] = 'パンくずリスト検出（' . ( wp_parse_url( $s['url'], PHP_URL_PATH ) ?? '/' ) . '）';
                break;
            }
        }
        if ( ! $has_breadcrumbs ) {
            $r['evidence']['missing'][] = 'パンくずリストが見つかりません';
        }

        // 主要ページへの導線
        $important_paths = [ '/contact', '/service', '/about', '/faq', '/price' ];
        foreach ( $important_paths as $path ) {
            foreach ( $home_links as $link ) {
                if ( stripos( $link, $path ) !== false ) {
                    $score += 1;
                    $r['evidence']['found'][] = $path . ' への導線あり';
                    break;
                }
            }
        }

        $r['evidence']['found']   = array_values( array_unique( $r['evidence']['found'] ) );
        $r['evidence']['missing'] = array_values( array_unique( $r['evidence']['missing'] ) );

        $r['score'] = min( 10, $score );
        $r['comment'] = $score >= 8
            ? '内部リンク設計が良好です。'
            : ( $score >= 4 ? '内部リンクはありますが、主要ページへの導線を強化すると効果的です。' : '内部リンク設計の改善が必要です。' );
        return $r;
    }

    /**
     * サイト診断を実行（クロール → 解析 → 保存）
     *
     * @param int $user_id
     * @return array 診断結果
     * @throws \RuntimeException サイトURL未設定時
     */
    public function run_site_diagnosis( int $user_id ): array {
        $site_url = $this->resolve_site_url( $user_id );
        if ( empty( $site_url ) ) {
            throw new \RuntimeException( '対象サイトURLが設定されていません。クライアント設定でサイトURLを登録してください。' );
        }

        // クロール
        $crawl_result = $this->crawl_site( $site_url );

        // シグナル抽出
        $all_signals = [];
        foreach ( $crawl_result['crawled_urls'] as $page ) {
            if ( ! empty( $page['html'] ) ) {
                $all_signals[] = $this->extract_page_signals( $page['html'], $page['url'] );
            }
        }

        // クライアント設定取得
        $client_settings = gcrev_get_client_settings( $user_id );

        // 10項目の診断
        $items = [];
        foreach ( self::DIAGNOSIS_ITEMS as $key => $label ) {
            $items[] = $this->analyze_diagnosis_item( $key, $all_signals, $client_settings );
        }

        // crawled_urls から html を除去（保存用）
        $urls_for_save = array_map( function ( $p ) {
            return [
                'url'    => $p['url'],
                'status' => $p['status'],
                'title'  => $p['title'],
            ];
        }, $crawl_result['crawled_urls'] );

        // 保存
        $this->save_site_diagnosis( $user_id, $items, $urls_for_save );

        // 保存後のデータを返す
        return $this->get_site_diagnosis( $user_id );
    }
}
