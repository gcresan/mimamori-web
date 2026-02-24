<?php
// FILE: inc/gcrev-api/modules/class-ai-client.php

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_AI_Client') ) { return; }

/**
 * Gcrev_AI_Client
 *
 * Gemini (Vertex AI) への認証・トークン取得・API呼び出しを担当する。
 *
 * 責務:
 *   - Google サービスアカウントを使ったアクセストークン取得
 *   - Vertex AI (Gemini) の generateContent エンドポイント呼び出し
 *
 * 責務外（他モジュールに残す）:
 *   - GCPプロジェクトID / ロケーション / モデル名の取得 → Gcrev_Config
 *   - レポートHTML の正規化・セクション抽出 → Report Generator (Step3)
 *   - プロンプト構築 → Report Generator (Step3)
 *
 * @package GCREV_INSIGHT
 * @since   2.0.0
 */
class Gcrev_AI_Client {

    // =========================================================
    // プロパティ
    // =========================================================

    /**
     * @var Gcrev_Config 設定オブジェクト（サービスアカウントパス等を取得するため）
     */
    private $config;

    // =========================================================
    // コンストラクタ
    // =========================================================

    /**
     * @param Gcrev_Config $config 設定オブジェクト
     */
    public function __construct( Gcrev_Config $config ) {
        $this->config = $config;
    }

    // =========================================================
    // アクセストークン取得
    // =========================================================

    /**
     * Google サービスアカウントからアクセストークンを取得する
     *
     * @return string アクセストークン
     * @throws \Exception トークン取得に失敗した場合
     */
    public function get_vertex_access_token() {

        $sa_path = $this->config->get_service_account_path();

        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $sa_path);

        $client = new \Google\Client();
        $client->setAuthConfig($sa_path);
        $client->addScope('https://www.googleapis.com/auth/cloud-platform');

        $token = $client->fetchAccessTokenWithAssertion();

        if (!is_array($token) || empty($token['access_token'])) {
            throw new \Exception('Google アクセストークンを取得できませんでした。サービスアカウント設定を確認してください。');
        }

        return (string)$token['access_token'];
    }

    // =========================================================
    // Gemini (Vertex AI) 呼び出し
    // =========================================================

    /**
     * Gemini API (generateContent) を呼び出してテキスト応答を返す
     *
     * Gcrev_Config から project_id / location / model を取得し、
     * Vertex AI のエンドポイントにプロンプトを送信する。
     *
     * @param  string $prompt 送信するプロンプト文字列
     * @return string Gemini からの応答テキスト
     * @throws \Exception API通信エラーまたは応答が空の場合
     */
    public function call_gemini_api( $prompt ) {

        $project_id = $this->config->get_gcp_project_id();
        if ($project_id === '') {
            throw new \Exception('GCP project_id を取得できませんでした（サービスアカウントJSONを確認してください）。');
        }

        $location = $this->config->get_gemini_location();
        $model    = $this->config->get_gemini_model();

        $host = ($location === 'global') ? 'aiplatform.googleapis.com' : ($location . '-aiplatform.googleapis.com');

        $url = sprintf(
            'https://%s/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent',
            $host,
            rawurlencode($project_id),
            rawurlencode($location),
            rawurlencode($model)
        );

        $access_token = $this->get_vertex_access_token();

        $body = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [[ 'text' => $prompt ]]
            ]],
            'generationConfig' => [
                'temperature'     => 0.4,
                'maxOutputTokens' => 8192, // 長いセクション(考察・アクション)対応のため増量
            ],
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json; charset=utf-8',
            ],
            'body'    => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout' => 180, // 長いレスポンス対応のため延長
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Gemini API 通信エラー: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $json   = json_decode($raw, true);

        if ($status < 200 || $status >= 300) {
            $msg = $json['error']['message'] ?? $raw;
            throw new \Exception('Gemini API エラー (HTTP {$status}): ' . $msg);
        }

        // finishReason をログに記録（STOP以外の場合は注意を促す）
        $finish_reason = $json['candidates'][0]['finishReason'] ?? 'UNKNOWN';

        // MAX_OUTPUT_TOKENSの場合は警告してテキストを返す（リトライループで再生成される）
        if ($finish_reason === 'MAX_OUTPUT_TOKENS') {
            error_log("[GCREV] call_gemini_api: WARNING - finishReason=MAX_OUTPUT_TOKENS (output was truncated, may need retry)");
            $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (!is_string($text) || $text === '') {
                throw new \Exception('Gemini の応答が空でした。finishReason=' . $finish_reason);
            }
            // 途中で切れたテキストでも一旦返す（上位レイヤーでリトライ判定）
            return $text;
        }

        // STOP以外の場合は警告
        if ($finish_reason !== 'STOP') {
            error_log("[GCREV] call_gemini_api: WARNING - finishReason={$finish_reason} (expected STOP)");
        }

        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (!is_string($text) || $text === '') {
            throw new \Exception('Gemini の応答が空でした。finishReason=' . $finish_reason);
        }

        return $text;
    }

    // =========================================================
    // 初心者向けリライト（Markdown出力）
    // =========================================================

    /**
     * 素材テキストを初心者向けにリライトし、Markdown形式で返す
     *
     * 「総評」は呼び出し元で独立表示するため、ここでは含まれない。
     * 良かった点・課題・考察・ネクストアクション等の分析内容を
     * ベンチマーク的視点（一般傾向・地方中小の立ち位置・成長段階）を加えた
     * 1本の自然な解説文章に統合して返す。
     *
     * @param  string $raw_report_text セクション別に構造化された素材テキスト（総評を除く）
     * @return string リライト済みMarkdown文字列
     * @throws \Exception API通信エラーまたは応答が空の場合
     */
    public function rewrite_for_beginner( string $raw_report_text ): string {

        $prompt = <<<REWRITE_PROMPT
あなたは、地方の中小企業向けにWeb解析データを「現実的な視点で噛み砕いて説明する」伴走型のレポート編集者です。

以下は、アクセス解析データと、実際に確認された成果（手動入力されたコンバージョン数）を含む分析素材テキストです。

これらの数値をもとに、
・ウェブ全体の一般的な傾向
・地方の一企業としての立ち位置
・このサイトの今の成長段階
を踏まえながら、
「この数値は、今のフェーズとしてどう評価できるのか」
「良い点はどこか、気をつける点はどこか」
「次に何をすべきか」
が自然に理解できるよう、1本の読みやすい解説文章にまとめてください。

※「総評（サマリー）」は別途表示するので、ここでは書かないでください。

【数値の扱いルール】
- 手動入力されたコンバージョン数がある場合は「確定した成果」として最優先で扱う
- GA4 / Search Console の数値は、成果の背景・流れ・兆候を説明するために使う
- 数値の良し悪しは単純な大小で判断せず、「文脈（立ち位置）」を踏まえて評価する
- 絶対的な業界平均値を断定的に示さない（「一般的には〜と言えます」のように表現する）

【評価に含める視点】
1. ウェブ全体の一般的な傾向から見てどうか
   - よくある水準か / 良い兆候か / まだこれからの段階か
2. 地方の中小企業サイトとして見たときの評価
   - 全国大手と比較しない
   - 地域密着型ビジネスとして妥当か
   - 「知ってもらう段階」か「選ばれる段階」か
3. このサイト自身の成長段階としての位置づけ
   - 立ち上げ期 / 露出拡大期 / 成果転換期 など
   - 次に何をすべきフェーズか

【文章の構成（この順番で書く）】
1. 今月のホームページ全体の動きの流れ（成長段階にも触れる）
2. 良かった点と、その理由（立ち位置を踏まえた評価を添える）
3. 気をつけたい点（課題）と、その背景
4. そこから考えられること（示唆・可能性）
5. 次にやるとよいこと（具体的に・優先度が高いものから）

【出力形式ルール（厳守）】
- 出力は Markdown 形式
- 見出しには ## や ### を使わない。代わりに **太字テキスト** だけで見出しを表現する
- 見出しの前後には必ず空行を入れる
- 1段落は2〜3文を目安にし、詰め込みすぎない
- 重要な数値・判断・成果・次にやることは **太字** で強調する
- 箇条書き（- ）を適宜使ってよい
- 専門用語はできるだけ避ける。使う場合はカッコで短く補足（例：CV＝問い合わせなどの成果）
- 素材テキストの各項目で同じ内容が繰り返されている場合は統合して整理する
- 文体は丁寧で親しみやすく、経営者・担当者に寄り添うトーン
- 不安を煽らない。断定しすぎず「可能性があります」「傾向が見えます」と表現する
- 数値（増減、割合、回数）は根拠がある箇所にだけ残し、読みやすく表記する（例：+8.1% / 322回）

【素材テキスト】
{$raw_report_text}
REWRITE_PROMPT;

        return $this->call_gemini_api( $prompt );
    }
}
