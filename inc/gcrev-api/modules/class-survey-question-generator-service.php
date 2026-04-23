<?php
// FILE: inc/gcrev-api/modules/class-survey-question-generator-service.php

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Survey_Question_Generator_Service' ) ) { return; }

/**
 * Gcrev_Survey_Question_Generator_Service
 *
 * クライアント情報（業種・サービス内容・強み等）をもとに、Gemini で
 * 「口コミ生成に最適化された 30 問の口コミアンケート」を生成する。
 *
 * 出力 JSON スキーマ:
 *   {
 *     "industry_label": "...",
 *     "questions": [
 *       { "category": "不安・課題", "label": "...", "type": "textarea",
 *         "description": "...", "placeholder": "...",
 *         "options": [], "required": true, "is_fixed": false }
 *     ],
 *     "fixed_ids": [1, 7, 15, 22],
 *     "design_intent": "..."
 *   }
 *
 * @package Mimamori_Web
 * @since   3.2.0
 */
class Gcrev_Survey_Question_Generator_Service {

    /** カテゴリ構成（合計30問） */
    private const CATEGORY_PLAN = [
        '不安・課題'              => 4,
        '比較・検討'              => 3,
        '決め手'                  => 3,
        '対応・コミュニケーション' => 4,
        '提案・特徴'              => 3,
        '結果・体験'              => 4,
        '他社との違い'            => 2,
        'おすすめ'                => 2,
        '総合評価'                => 2,
        '感情・印象'              => 1,
        '自由回答'                => 2, // 1問は required、もう1問は任意（口コミ向けのひとこと）
    ];

    /**
     * @param array $input 生成入力（クライアント情報 + モーダル上書き値）
     *   - industry           string  業種（例: 医療・ヘルスケア / 歯科医院）
     *   - service_description string サービス内容
     *   - target             string  ターゲット像
     *   - strengths          string  強み（改行区切り）
     *   - review_emphasis    string  口コミで引き出したい内容
     * @return array { success, questions, design_intent, industry_label, message, raw }
     */
    public function generate( array $input ): array {
        $industry            = trim( (string) ( $input['industry'] ?? '' ) );
        $service_description = trim( (string) ( $input['service_description'] ?? '' ) );
        $target              = trim( (string) ( $input['target'] ?? '' ) );
        $strengths           = trim( (string) ( $input['strengths'] ?? '' ) );
        $review_emphasis     = trim( (string) ( $input['review_emphasis'] ?? '' ) );

        if ( $industry === '' ) {
            return [
                'success' => false,
                'message' => '業種が指定されていません。クライアント情報を設定するか、モーダルで入力してください。',
            ];
        }

        try {
            $prompt = $this->build_prompt( $industry, $service_description, $target, $strengths, $review_emphasis );
            $response = $this->call_ai( $prompt );

            // 応答の切断検知（JSON の末尾 `}` が欠けている場合）
            $trimmed_tail = rtrim( $response, "` \n\r\t" );
            $looks_truncated = ( strrpos( $trimmed_tail, '}' ) === false )
                || ( strrpos( $trimmed_tail, '}' ) < strlen( $trimmed_tail ) - 500 );

            $parsed = $this->extract_json( (string) $response );

            if ( ! is_array( $parsed ) || empty( $parsed['questions'] ) || ! is_array( $parsed['questions'] ) ) {
                // 生応答の冒頭だけログに残す（デバッグ用、機密情報はないプロンプト出力）
                file_put_contents(
                    '/tmp/gcrev_survey_debug.log',
                    date( 'Y-m-d H:i:s' ) . " parse FAIL (truncated=" . ( $looks_truncated ? '1' : '0' ) . ") len=" . strlen( $response )
                        . " head=" . substr( $response, 0, 200 )
                        . " tail=" . substr( $response, -300 ) . "\n",
                    FILE_APPEND
                );
                $msg = $looks_truncated
                    ? 'AI 応答が途中で切れました（出力トークン上限到達）。もう一度お試しください。'
                    : 'AI 応答から質問リストを抽出できませんでした。再度お試しください。';
                return [ 'success' => false, 'message' => $msg ];
            }

            $questions = $this->normalize_questions( $parsed['questions'], $parsed['fixed_ids'] ?? [] );

            if ( empty( $questions ) ) {
                return [
                    'success' => false,
                    'message' => 'AI が有効な質問を1つも返しませんでした。内容を確認してもう一度お試しください。',
                ];
            }

            return [
                'success'        => true,
                'industry_label' => (string) ( $parsed['industry_label'] ?? $industry ),
                'questions'      => $questions,
                'design_intent'  => (string) ( $parsed['design_intent'] ?? '' ),
                'message'        => sprintf( '%d問のアンケートを生成しました。', count( $questions ) ),
            ];
        } catch ( \Throwable $e ) {
            file_put_contents(
                '/tmp/gcrev_survey_debug.log',
                date( 'Y-m-d H:i:s' ) . ' survey generation ERROR: ' . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return [
                'success' => false,
                'message' => 'AI 生成中にエラーが発生しました: ' . $e->getMessage(),
            ];
        }
    }

    // =========================================================
    // Prompt build
    // =========================================================

    private function build_prompt(
        string $industry,
        string $service_description,
        string $target,
        string $strengths,
        string $review_emphasis
    ): string {
        $category_spec = [];
        foreach ( self::CATEGORY_PLAN as $cat => $count ) {
            $category_spec[] = sprintf( '- %s: %d問', $cat, $count );
        }
        $category_block = implode( "\n", $category_spec );

        $service_part    = $service_description !== '' ? $service_description : '（記載なし — 業種から一般的なサービス像を想定してください）';
        $target_part     = $target !== ''             ? $target               : '（記載なし — 業種に合致する典型的な顧客像を想定してください）';
        $strengths_part  = $strengths !== ''          ? $strengths            : '（記載なし — 質問では強みを直接想定せず、自然な聞き方にしてください）';
        $emphasis_part   = $review_emphasis !== ''    ? $review_emphasis      : '（記載なし — 一般的な口コミで価値がある要素を引き出してください）';

        return <<<PROMPT
あなたは、口コミ生成に最適化されたアンケート設計の専門家です。
以下の情報をもとに「自然でバリエーションのある口コミを生成するためのアンケート30問」を設計してください。

# 入力情報
- 業種: {$industry}
- サービス内容: {$service_part}
- ターゲット: {$target_part}
- 強み: {$strengths_part}
- 口コミで特に引き出したい内容: {$emphasis_part}

# カテゴリ構成（合計30問、順序厳守）
{$category_block}

# 設計ルール（厳守）
- 同じ意味の質問を重複させない
- 抽象的すぎる質問は禁止（具体的に答えられる内容にする）
- 感情が出る質問、変化（ビフォー→アフター）が出る質問、比較理由が出る質問、具体エピソードが出やすい質問を必ず含める
- 専門用語は控えめに、一般ユーザーでも答えやすく
- やわらかく自然な日本語、威圧感や誘導感のない聞き方
- その業種に特化した具体性のある質問にする（汎用質問は避ける）

# 質問タイプ（type）の使い分け — **選択式を最優先、textarea は自由回答カテゴリのみ**
回答者の入力負担を減らすため、可能な限り radio / checkbox で答えられるように設計する。
- **radio**（単一選択）: 総合評価・満足度・感情・印象・おすすめ度・推奨度・初回 or リピート など
- **checkbox**（複数選択）: 不安・決め手・良かった点・変化・他社との違い・印象に残った提案 など「複数当てはまる」質問
- **textarea**（自由記述）: 「自由回答」カテゴリの2問のみ使用。それ以外のカテゴリでは絶対に使わない
- text: 使わない

radio/checkbox の options は 4〜7 個の具体的・業種特化した選択肢を入れる（回答者が「あるある」と共感できる言葉選び）。
末尾には必要に応じて「その他」または「特になし」を1つ加えてよい。

# required の付与ルール（厳守）
- **自由回答カテゴリは必ず2問: 1問目=required=true（全体感想など）、2問目=required=false（ひとこと・メッセージなど任意）**
- textarea は自由回答カテゴリ以外では使わない（他カテゴリの textarea は禁止）
- radio / checkbox は原則 required=true でよい（選択式は負担が軽いため）

# placeholder のルール（textarea は必須）
- **textarea の placeholder は必ず埋める**（空文字は禁止）。40〜120字の具体的な回答例を業種特化で書く
- placeholder は「例：」で始める
- 業種・サービス内容に合わせた自然な回答例にする（汎用的すぎる例は不可）
- 例（歯科医院の場合）: 「例：治療前はクリニック選びに迷っていましたが、説明がわかりやすく不安が解消されました。治療中も声かけが多くて安心できました。」
- radio / checkbox の placeholder は空でよい（description で補足）

# カテゴリ別のタイプ指針（目安）
- 不安・課題: checkbox 中心
- 比較・検討: checkbox / radio（比較した数など）
- 決め手: checkbox
- 対応・コミュニケーション: radio / checkbox 混在（対応の印象は radio 5段階、良かった点は checkbox）
- 提案・特徴: checkbox
- 結果・体験: checkbox（感じた変化を多選択）+ radio（満足度変化）
- 他社との違い: checkbox
- おすすめ: radio（おすすめしたい度 5段階 / NPS）
- 総合評価: radio（満足度 5段階）
- 感情・印象: radio（ひとことで表すと…）
- 自由回答: **2問とも textarea**
  - 1問目: required=true（例: 「全体を通して印象に残ったこと」「一番良かった点」など、口コミ生成のメイン素材になる質問）
  - 2問目: required=false（例: 「これから検討する方へひとこと」「その他あればご自由に」など、補足・メッセージ的な質問）

# 固定質問の選定
- 30問のうち 3〜4 問を「口コミ生成に必須の固定質問」として選ぶ（決め手・総合評価・自由回答・おすすめのいずれかを含める）
- 固定質問は fixed_ids に 1 始まりの質問番号で列挙する

# 出力要件
下記の JSON 形式のみで出力してください（マークダウン・前置き・コードフェンス一切なし）。

{
  "industry_label": "業種名（入力をそのまま、または自然に整えた形）",
  "design_intent": "このアンケート構成でどんな口コミが生成されやすくなるかを 2〜3 文で説明",
  "fixed_ids": [1, 7, 15],
  "questions": [
    {
      "category": "不安・課題",
      "label": "質問文",
      "type": "textarea",
      "description": "回答者への補足説明（任意、空でもよい）",
      "placeholder": "回答欄のプレースホルダー例（任意、空でもよい）",
      "options": [],
      "required": true
    }
  ]
}

# 厳守事項
- questions は必ず {$this->total_questions()} 件、カテゴリ順で並べる
- 質問文に個人名・企業名・具体URLは含めない
- 「満足しましたか？」のような定型質問は禁止
- どの業種にも当てはまる汎用質問は禁止
- 自由回答カテゴリの1問は「最後に何か一言お願いします」系の自由欄にする
PROMPT;
    }

    private function total_questions(): int {
        return array_sum( self::CATEGORY_PLAN );
    }

    // =========================================================
    // AI call + parse
    // =========================================================

    private function call_ai( string $prompt ): string {
        if ( ! class_exists( 'Gcrev_Config' ) || ! class_exists( 'Gcrev_AI_Client' ) ) {
            throw new \Exception( 'Gcrev_AI_Client が利用できません。' );
        }
        $config = new Gcrev_Config();
        $client = new Gcrev_AI_Client( $config );
        // 30問 + 選択肢込みの JSON は 15000〜20000 tokens 規模になるため十分な枠を確保
        return (string) $client->call_gemini_api( $prompt, [
            'temperature'     => 0.7,
            'maxOutputTokens' => 32768,
        ] );
    }

    private function extract_json( string $text ): ?array {
        $text = trim( $text );
        if ( $text === '' ) { return null; }

        $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
        $text = preg_replace( '/\s*```$/i', '', $text );
        $text = trim( (string) $text );

        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        if ( $start === false || $end === false || $end <= $start ) {
            return null;
        }
        $json = substr( $text, $start, $end - $start + 1 );

        $parsed = json_decode( $json, true );
        return is_array( $parsed ) ? $parsed : null;
    }

    /**
     * AI が返した質問配列を正規化する。
     *
     * @param array $raw_questions AI生成の questions 配列
     * @param array $fixed_ids     AI 指定の固定質問番号（1 始まり）
     * @return array<int,array>
     */
    private function normalize_questions( array $raw_questions, array $fixed_ids ): array {
        $fixed_ids_set = array_flip( array_map( 'intval', (array) $fixed_ids ) );

        $valid_types  = [ 'textarea', 'radio', 'checkbox', 'text' ];
        $valid_cats   = array_keys( self::CATEGORY_PLAN );

        $out = [];
        foreach ( $raw_questions as $i => $q ) {
            if ( ! is_array( $q ) ) { continue; }
            $label = trim( (string) ( $q['label'] ?? '' ) );
            if ( $label === '' ) { continue; }

            $type = (string) ( $q['type'] ?? 'textarea' );
            if ( ! in_array( $type, $valid_types, true ) ) { $type = 'textarea'; }

            $category = (string) ( $q['category'] ?? '' );
            if ( ! in_array( $category, $valid_cats, true ) ) { $category = ''; }

            $options = [];
            if ( in_array( $type, [ 'radio', 'checkbox' ], true ) && isset( $q['options'] ) && is_array( $q['options'] ) ) {
                foreach ( $q['options'] as $opt ) {
                    $opt = trim( (string) $opt );
                    if ( $opt !== '' && mb_strlen( $opt ) <= 200 ) {
                        $options[] = $opt;
                    }
                }
                // options 必須
                if ( empty( $options ) ) {
                    $type = 'textarea';
                }
            }

            $is_fixed = isset( $fixed_ids_set[ $i + 1 ] ) || ! empty( $q['is_fixed'] );

            $placeholder = mb_substr( trim( (string) ( $q['placeholder'] ?? '' ) ), 0, 500 );
            // textarea で placeholder が空なら汎用フォールバックを注入（回答者の心理的負担を減らすため例文必須）
            if ( $type === 'textarea' && $placeholder === '' ) {
                $placeholder = ! empty( $q['required'] ?? false )
                    ? '例：今回感じた良かった点や、印象に残ったことを自由にお書きください。（箇条書きでも大丈夫です）'
                    : '例：これから検討される方へのメッセージや、ご要望があればご記入ください。';
            }

            $out[] = [
                'category'    => $category,
                'label'       => mb_substr( $label, 0, 500 ),
                'type'        => $type,
                'description' => mb_substr( trim( (string) ( $q['description'] ?? '' ) ), 0, 500 ),
                'placeholder' => $placeholder,
                'options'     => $options,
                'required'    => isset( $q['required'] ) ? (bool) $q['required'] : true,
                'is_fixed'    => $is_fixed,
            ];
        }

        $out = $this->enforce_single_required_textarea( $out );

        return $out;
    }

    /**
     * 自由回答 textarea の required ルールを正規化する。
     *
     * 期待する状態:
     *   - 自由回答カテゴリの textarea は 2問 (1 required + 1 optional)
     *   - 自由回答以外の textarea（ルール違反）は required=false に落とす
     *   - 全体で required=true の textarea は必ず 1問（0 なら先頭に付ける、2以上なら1問だけ残す）
     */
    private function enforce_single_required_textarea( array $questions ): array {
        // 自由回答 textarea と それ以外 textarea を分類
        $freeform_indexes = [];
        $other_indexes    = [];
        foreach ( $questions as $i => $q ) {
            if ( ( $q['type'] ?? '' ) !== 'textarea' ) { continue; }
            if ( ( $q['category'] ?? '' ) === '自由回答' ) {
                $freeform_indexes[] = $i;
            } else {
                $other_indexes[] = $i;
            }
        }

        // 自由回答以外の textarea は optional に強制
        foreach ( $other_indexes as $i ) {
            $questions[ $i ]['required'] = false;
        }

        // 自由回答 textarea が0件なら何もせず終了
        if ( empty( $freeform_indexes ) ) {
            return $questions;
        }

        // 現在 required=true の自由回答 textarea を集める
        $currently_required = array_values( array_filter( $freeform_indexes, static function ( $i ) use ( $questions ) {
            return ! empty( $questions[ $i ]['required'] );
        } ) );

        // ちょうど1問 required にしたい
        $keep_idx = null;
        if ( count( $currently_required ) === 1 ) {
            $keep_idx = $currently_required[0];
        } else {
            // 0 or 複数 → 先頭の自由回答を required とする
            $keep_idx = $freeform_indexes[0];
        }

        foreach ( $freeform_indexes as $i ) {
            $questions[ $i ]['required'] = ( $i === $keep_idx );
        }

        return $questions;
    }
}
