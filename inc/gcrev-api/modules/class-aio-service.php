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

        // 2パス目: 企業名抽出（Gemini で抽出）
        $names = $this->extract_companies_via_gemini( $raw_text );

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

                    // DB保存（UPSERT）— self_rank / self_score は NULL 可なので条件分岐
                    $self_rank_sql  = $result['self_rank'] === null ? 'NULL' : $wpdb->prepare( '%d', $result['self_rank'] );
                    $self_score_sql = $result['self_score'] === null ? 'NULL' : $wpdb->prepare( '%s', number_format( $result['self_score'], 2, '.', '' ) );

                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $self_rank_sql / $self_score_sql are safe values
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$results_table}
                         (user_id, keyword_id, provider, question, question_index,
                          raw_response, extracted_names, self_rank, self_score, is_mentioned,
                          fetched_at, created_at)
                         VALUES (%d, %d, %s, %s, %d, %s, %s, {$self_rank_sql}, {$self_score_sql}, %d, %s, %s)
                         ON DUPLICATE KEY UPDATE
                          raw_response = VALUES(raw_response),
                          extracted_names = VALUES(extracted_names),
                          self_rank = VALUES(self_rank),
                          self_score = VALUES(self_score),
                          is_mentioned = VALUES(is_mentioned),
                          fetched_at = VALUES(fetched_at)",
                        $user_id,
                        $keyword_id,
                        $provider,
                        $question,
                        $qi,
                        $result['raw'],
                        wp_json_encode( $result['names'], JSON_UNESCAPED_UNICODE ),
                        $result['is_mentioned'] ? 1 : 0,
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

        // 長時間実行: キーワード数 × プロバイダー数 × 質問数分の API コールが発生する
        @set_time_limit( max( 300, count( $keywords ) * 180 ) );

        $results = [];
        foreach ( $keywords as $kw ) {
            $results[] = $this->run_aio_check( (int) $kw['id'] );
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
}
