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
                          status, fetched_at, created_at)
                         VALUES (%d, %d, %s, %s, %d, %s, %s, {$self_rank_sql}, {$self_score_sql}, %d, %s, %s, %s)
                         ON DUPLICATE KEY UPDATE
                          raw_response = VALUES(raw_response),
                          extracted_names = VALUES(extracted_names),
                          self_rank = VALUES(self_rank),
                          self_score = VALUES(self_score),
                          is_mentioned = VALUES(is_mentioned),
                          status = VALUES(status),
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

        // 改善優先度
        $priority = 'low';
        if ( $diagnosis_score < 40 || $mention_stats['rate'] < 20 ) {
            $priority = 'high';
        } elseif ( $diagnosis_score < 70 || $mention_stats['rate'] < 50 ) {
            $priority = 'medium';
        }

        return [
            'summary' => [
                'diagnosis_score'  => $diagnosis_score,
                'mention_rate'     => $mention_stats['rate'],
                'mention_count'    => $mention_stats['mentioned'],
                'mention_total'    => $mention_stats['total'],
                'priority'         => $priority,
                'last_fetched'     => $mention_stats['last_fetched'],
            ],
            'diagnosis'      => $diagnosis,
            'mention_matrix' => $mention_matrix,
            'competitors'    => $competitors,
            'actions'        => $actions,
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
                    raw_response, extracted_names
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
                'keyword_id' => $kid,
                'keyword'    => $kw['keyword'],
                'providers'  => [],
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
    public function save_site_diagnosis( int $user_id, array $items ): bool {
        $tz  = wp_timezone();
        $now = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

        $sanitized_items = [];
        foreach ( $items as $item ) {
            $key = sanitize_text_field( $item['key'] ?? '' );
            if ( ! isset( self::DIAGNOSIS_ITEMS[ $key ] ) ) {
                continue;
            }
            $status = sanitize_text_field( $item['status'] ?? 'not_addressed' );
            if ( ! in_array( $status, [ 'ok', 'caution', 'not_addressed' ], true ) ) {
                $status = 'not_addressed';
            }
            $sanitized_items[] = [
                'key'     => $key,
                'label'   => self::DIAGNOSIS_ITEMS[ $key ],
                'status'  => $status,
                'score'   => max( 0, min( 10, absint( $item['score'] ?? 0 ) ) ),
                'comment' => sanitize_text_field( $item['comment'] ?? '' ),
                'source'  => sanitize_text_field( $item['source'] ?? 'manual' ),
            ];
        }

        $data = [
            'version'    => 1,
            'updated_at' => $now,
            'items'      => $sanitized_items,
        ];

        return (bool) update_user_meta(
            $user_id,
            'gcrev_ai_report_diagnosis',
            wp_json_encode( $data, JSON_UNESCAPED_UNICODE )
        );
    }

    /**
     * 診断スコアを計算（10項目の平均 × 10）
     */
    private function calc_diagnosis_score( array $diagnosis ): int {
        $items = $diagnosis['items'] ?? [];
        if ( empty( $items ) ) {
            return 0;
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

        return [
            'competitors'     => $competitors,
            'self_count'      => $self_count,
            'self_rate'       => $total_responses > 0 ? round( ( $self_count / $total_responses ) * 100, 0 ) : 0,
            'total_responses' => $total_responses,
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
}
