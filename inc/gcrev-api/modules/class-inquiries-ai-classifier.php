<?php
/**
 * Mimamori_Inquiries_AI_Classifier
 *
 * 問い合わせデータ（Flamingo / MW WP Form 由来）を Gemini で分類する。
 *
 * 各 item に以下を付与して返す:
 * - category   : "お問い合わせ・資料請求" | "見学会・イベント" | "営業" | "配信停止" | "SPAM" | "その他"
 * - valid      : bool（営業/配信停止/SPAM/テスト系は false）
 * - region     : 地域（例「愛媛県松山市」）または "—"
 * - summary    : 100〜200文字程度に整形された内容
 * - tags       : 補足バッジ（例「大人2名」「大人2名・お子様1名」）
 *
 * 結果は user_id × year_month × ハッシュ で 7 日間トランジェントキャッシュ。
 * 同じ items を再分類しても API 呼び出しはスキップされる。
 *
 * @package Mimamori_Web
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( class_exists( 'Mimamori_Inquiries_AI_Classifier' ) ) {
    return;
}

class Mimamori_Inquiries_AI_Classifier {

    private const CACHE_PREFIX = 'gcrev_inq_ai_';
    private const CACHE_TTL    = 7 * DAY_IN_SECONDS;
    private const DEBUG_LOG    = '/tmp/gcrev_inquiries_debug.log';

    /** AI が返すべきカテゴリの固定リスト */
    public const CATEGORIES = [
        'お問い合わせ・資料請求',
        '見学会・イベント',
        '採用・求人',
        '営業',
        '配信停止',
        'SPAM',
        'その他',
    ];

    /** valid とみなすカテゴリ */
    public const VALID_CATEGORIES = [
        'お問い合わせ・資料請求',
        '見学会・イベント',
        '採用・求人',
        'その他',
    ];

    /**
     * items を Gemini で分類して付加情報付きで返す
     *
     * @param array<int,array<string,mixed>> $items 各 item は date,name,email,message を持つ
     * @param int                            $user_id
     * @param string                         $year_month YYYY-MM
     * @return array<int,array<string,mixed>>
     */
    public function classify_items( array $items, int $user_id, string $year_month ): array {
        if ( empty( $items ) ) {
            return [];
        }

        // ハッシュ: 入力内容が変わらなければキャッシュヒット
        $hash = substr( md5( wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) ), 0, 12 );
        $cache_key = self::CACHE_PREFIX . "{$user_id}_{$year_month}_{$hash}";
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        try {
            $classified = $this->run_gemini_classification( $items );
        } catch ( \Throwable $e ) {
            self::log( "[AI-CLASSIFIER] user={$user_id} ym={$year_month} ERROR " . $e->getMessage() );
            // 失敗時は元データに最低限の情報を付与して返す（fail-open）
            $classified = $this->build_fallback( $items );
        }

        set_transient( $cache_key, $classified, self::CACHE_TTL );
        return $classified;
    }

    /**
     * Claude (Anthropic) で items を分類する。
     * 旧称 run_gemini_classification を維持しているが内部実装は Claude。
     */
    private function run_gemini_classification( array $items ): array {
        if ( ! class_exists( 'Gcrev_Claude_Client' ) || ! class_exists( 'Gcrev_Config' ) ) {
            throw new \Exception( 'Gcrev_Claude_Client / Gcrev_Config が読み込まれていません' );
        }

        $config = new \Gcrev_Config();
        if ( $config->get_anthropic_api_key() === '' ) {
            throw new \Exception( 'ANTHROPIC_API_KEY が wp-config.php に設定されていません' );
        }
        $claude = new \Gcrev_Claude_Client( $config );

        // items を AI に渡しやすい形に変換。
        // 個人情報最小化: 外部LLM（Anthropic）には氏名を送らず、メールはドメイン部のみ送る。
        // - 氏名: カテゴリ/有効判定に寄与しないため送信しない（高い識別性のPII）。
        // - メール: ドメインは営業/SPAM判定の手掛かりになるためドメインのみ送る（ローカル部=個人特定要素は除去）。
        // 分類結果は呼び出し側で元の item に array_merge され、氏名・メールはみまもりWeb内の表示用に保持される。
        $payload = [];
        foreach ( $items as $i => $it ) {
            $email_raw    = (string) ( $it['email'] ?? '' );
            $at_pos       = strrpos( $email_raw, '@' );
            $email_domain = ( $at_pos !== false ) ? substr( $email_raw, $at_pos ) : '';
            $payload[] = [
                'idx'          => $i,
                'date'         => (string) ( $it['date'] ?? '' ),
                'email_domain' => $email_domain,
                'message'      => (string) ( $it['message'] ?? '' ),
            ];
        }

        $categories_str = implode( ' / ', self::CATEGORIES );

        $system_prompt = <<<SYS
あなたは工務店・住宅会社向けの問い合わせ内容分類アシスタントです。

入力 JSON 配列の各問い合わせを精査し、次のフィールドを付けて JSON 配列で返してください。

【出力フィールド】
- idx: 入力と同じ通し番号
- category: 次のいずれかちょうど1つ → {$categories_str}
- valid: true/false（営業・配信停止・SPAM・テスト系は false。実利用ユーザーや見込み客なら true）
- region: 「愛媛県松山市」のように都道府県＋市区町村。本文や住所欄に書かれていれば抽出。不明なら "—"
- summary: 内容を 60〜140 文字程度の自然な日本語で要約。実家のリフォーム検討等の核心情報は残す。フォーム特有の制御文字（[][][]、行番号、駅名など）は無視。
- tags: 来場者数や予約日時など、ハイライトしたい情報を短いラベル配列で返す（例: ["大人2名","お子様1名"]、無ければ空配列）

【判定ガイド】
- 「お問い合わせ・資料請求」は施主予備軍からの質問・カタログ請求等
- 「見学会・イベント」は完成見学会の参加申込（フォーム名や本文に「見学会」「予約」が含まれる）
- 「採用・求人」は求人応募関連
- 「営業」は他社からの BtoB 勧誘（マーケ・SEO・MEO・人材育成・eラーニング・ランキング掲載・サービス紹介・「貴社」「弊社」等）
- 「配信停止」はメルマガ解除・購読停止等
- 「SPAM」はテスト送信・無意味文字列・短すぎる本文・過剰な英語スパム
- それ以外で判別困難なら「その他」

回答はマークダウンや余計な解説を含めず、JSON 配列のみを返してください。
コードブロック (```) も付けないでください。
SYS;

        $user_prompt = "以下の問い合わせ群を分類してください。\n\n【入力】\n"
            . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        $result = $claude->call_messages_api( $system_prompt, $user_prompt, [
            'temperature' => 0.1,
            'max_tokens'  => 8000,
        ] );

        $text = (string) ( $result['text'] ?? '' );

        // コードフェンス除去
        $text = trim( $text );
        $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
        $text = preg_replace( '/\s*```\s*$/', '', $text );
        $text = trim( $text );

        // JSON 抽出（前後にゴミがあっても拾えるよう正規表現でフォールバック）
        $decoded = json_decode( $text, true );
        if ( ! is_array( $decoded ) ) {
            if ( preg_match( '/\[[\s\S]*\]/', $text, $matches ) ) {
                $decoded = json_decode( $matches[0], true );
            }
        }
        if ( ! is_array( $decoded ) ) {
            throw new \Exception( 'Claude レスポンスを JSON としてパースできませんでした' );
        }

        // 結果を入力 items にマージ
        $by_idx = [];
        foreach ( $decoded as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['idx'] ) ) continue;
            $by_idx[ (int) $row['idx'] ] = $row;
        }

        $result = [];
        foreach ( $items as $i => $it ) {
            $row     = $by_idx[ $i ] ?? [];
            $category = isset( $row['category'] ) && in_array( $row['category'], self::CATEGORIES, true )
                ? (string) $row['category']
                : 'その他';
            // category から valid を逆算（AI が valid を間違えた場合の保険）
            $valid_from_category = in_array( $category, self::VALID_CATEGORIES, true );
            $valid = isset( $row['valid'] ) ? (bool) $row['valid'] : $valid_from_category;
            // 矛盾があったら category 優先（営業を valid=true で返してきた場合の補正）
            if ( ! $valid_from_category ) {
                $valid = false;
            }
            $tags = ( isset( $row['tags'] ) && is_array( $row['tags'] ) )
                ? array_values( array_filter( array_map( static fn( $t ) => is_scalar( $t ) ? (string) $t : '', $row['tags'] ) ) )
                : [];

            $result[] = array_merge( $it, [
                'ai_category' => $category,
                'ai_valid'    => $valid,
                'ai_region'   => isset( $row['region'] ) && (string) $row['region'] !== '' ? (string) $row['region'] : '—',
                'ai_summary'  => isset( $row['summary'] ) ? (string) $row['summary'] : '',
                'ai_tags'     => $tags,
            ] );
        }

        return $result;
    }

    /**
     * AI 失敗時の fallback（最低限の表示が出るように）
     */
    private function build_fallback( array $items ): array {
        $result = [];
        foreach ( $items as $it ) {
            $result[] = array_merge( $it, [
                'ai_category' => 'その他',
                'ai_valid'    => ! empty( $it['valid'] ),
                'ai_region'   => '—',
                'ai_summary'  => mb_substr( (string) ( $it['message'] ?? '' ), 0, 140 ),
                'ai_tags'     => [],
                'ai_failed'   => true,
            ] );
        }
        return $result;
    }

    public static function clear_cache_for( int $user_id, string $year_month ): void {
        global $wpdb;
        $like = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX . "{$user_id}_{$year_month}_" ) . '%';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like,
            '_transient_timeout_' . self::CACHE_PREFIX . "{$user_id}_{$year_month}_%"
        ) );
    }

    private static function log( string $message ): void {
        file_put_contents( self::DEBUG_LOG, date( 'Y-m-d H:i:s' ) . ' ' . $message . "\n", FILE_APPEND );
    }
}
