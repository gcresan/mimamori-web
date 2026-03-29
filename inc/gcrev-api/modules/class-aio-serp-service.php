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
     * @param int $user_id    ユーザーID
     * @param int $keyword_id gcrev_rank_keywords.id
     * @return array { status: string, message: string }
     */
    public function fetch_and_store( int $user_id, int $keyword_id ): array {
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
            'device'   => $serp_settings['device'],
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
     * @return array { processed: int, results: array }
     */
    public function fetch_all_keywords( int $user_id ): array {
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
            $r = $this->fetch_and_store( $user_id, (int) $kw['id'] );
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
    // 内部ヘルパー
    // =========================================================

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
     */
    private function get_latest_results( int $user_id ): array {
        global $wpdb;
        $table_serp = $wpdb->prefix . 'gcrev_aio_serp_results';
        $table_kw   = $wpdb->prefix . 'gcrev_rank_keywords';

        // 各キーワードの最新結果を取得
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*
             FROM {$table_serp} s
             INNER JOIN (
                 SELECT keyword_id, MAX(fetched_at) AS max_fetched
                 FROM {$table_serp}
                 WHERE user_id = %d
                 GROUP BY keyword_id
             ) latest ON s.keyword_id = latest.keyword_id AND s.fetched_at = latest.max_fetched
             WHERE s.user_id = %d
             ORDER BY s.keyword ASC",
            $user_id, $user_id
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
