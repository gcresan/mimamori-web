<?php
// FILE: inc/gcrev-api/modules/class-report-prompt-builder.php

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Report_Prompt_Builder') ) { return; }

/**
 * Gcrev_Report_Prompt_Builder
 *
 * ChatGPT 月次レポート用のプロンプト構築と、
 * JSON 構造化出力 → HTML セクション変換を担当する。
 *
 * 責務:
 *   - システムプロンプト構築（役割・文体・出力スキーマ・クライアント設定/ペルソナ注入）
 *   - ユーザープロンプト構築（数値データ・ページ改善データ）
 *   - JSON → HTML セクション変換（既存テンプレートと互換）
 *   - 出力バリデーション
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Gcrev_Report_Prompt_Builder {

    // =========================================================
    // システムプロンプト構築
    // =========================================================

    /**
     * ChatGPT 用システムプロンプトを構築する
     *
     * @param  array  $client_info   build_client_info_for_report() の戻り値
     * @param  bool   $is_easy_mode  初心者モードか
     * @param  ?string $target_area  ターゲットエリア（都道府県）
     * @return string
     */
    public function build_system_prompt( array $client_info, bool $is_easy_mode, ?string $target_area = null ): string {

        $role = $this->build_role_section( $is_easy_mode );
        $client_context = $this->build_client_context_section( $client_info );
        $persona_context = $this->build_persona_section( $client_info );
        $style_rules = $is_easy_mode
            ? $this->build_easy_mode_rules()
            : $this->build_normal_mode_rules();
        $json_schema = $this->build_json_schema_instruction( $is_easy_mode, $target_area );
        $domain_instruction = $this->build_domain_instruction( $client_info );

        $prompt = <<<SYSTEM
{$role}

{$domain_instruction}

{$client_context}

{$persona_context}

{$style_rules}

{$json_schema}
SYSTEM;

        return $prompt;
    }

    // =========================================================
    // ユーザープロンプト構築
    // =========================================================

    /**
     * ChatGPT 用ユーザープロンプトを構築する
     *
     * @param  array  $prev_data    前月データ
     * @param  array  $two_data     前々月データ
     * @param  array  $client_info  クライアント情報
     * @param  ?string $target_area ターゲットエリア
     * @param  Gcrev_Report_Generator $generator データフォーマット用（既存メソッド再利用）
     * @return string
     */
    public function build_user_prompt(
        array $prev_data,
        array $two_data,
        array $client_info,
        ?string $target_area,
        Gcrev_Report_Generator $generator
    ): string {

        $area_label = $target_area ?? '全国';

        // 海外アクセス除外時の注記
        $foreign_note = '';
        if ( ! empty( $client_info['exclude_foreign'] ) ) {
            $foreign_note = "\n⚠️ 重要：このレポートのデータは「日本国内からのアクセスのみ」に絞り込まれています。海外からのアクセスは除外されています。\n";
        }

        $prompt  = "以下のデータに基づき、月次レポートのJSON を生成してください。\n";
        $prompt .= "ターゲットエリア（都道府県）: {$area_label}\n";
        $prompt .= $foreign_note;

        $prompt .= "\n# 前々月データ（比較基準）\n";
        $prompt .= $generator->format_data_for_prompt( $two_data );

        $prompt .= "\n\n# 前月データ（最新）\n";
        $prompt .= $generator->format_data_for_prompt( $prev_data );

        // ページ改善分析データ（あれば）
        if ( ! empty( $client_info['page_insights']['available'] ) ) {
            $prompt .= $generator->format_page_insights_for_prompt( $client_info['page_insights'] );
        }

        $prompt .= "\n\n上記データに基づき、指定されたJSON形式でレポートを出力してください。";

        return $prompt;
    }

    // =========================================================
    // JSON → HTML 変換
    // =========================================================

    /**
     * ChatGPT の JSON 出力をセクション HTML に変換
     *
     * 既存の build_sample_layout_report_html() で使えるセクション HTML を生成する。
     * クラス名は既存と完全互換: summary, good-points, improvement-points,
     * area-box, insight-box, actions
     *
     * @param  array  $json          パース済み JSON
     * @param  bool   $is_easy_mode  初心者モードか
     * @param  ?string $target_area  ターゲットエリア
     * @return string セクション HTML
     */
    public function json_to_section_html( array $json, bool $is_easy_mode, ?string $target_area = null ): string {

        $html = '';

        // 1. 結論サマリー
        $html .= $this->render_summary_section( $json['summary'] ?? '' );

        // 2. 良かった点
        $html .= $this->render_good_points_section( $json['good_points'] ?? [], $is_easy_mode );

        // 3. 改善が必要な点
        $html .= $this->render_improvement_points_section( $json['improvement_points'] ?? [], $is_easy_mode );

        // 4. ターゲットエリアの状況（target_area がある場合のみ）
        if ( $target_area !== null && ! empty( $json['area_evaluation'] ) ) {
            $html .= $this->render_area_section( $json['area_evaluation'], $is_easy_mode );
        }

        // 5. 考察
        $html .= $this->render_insight_section( $json['insight'] ?? [], $is_easy_mode );

        // 6. ネクストアクション
        $html .= $this->render_actions_section( $json['actions'] ?? [], $is_easy_mode );

        return $html;
    }

    // =========================================================
    // バリデーション
    // =========================================================

    /**
     * ChatGPT の JSON 出力を検証する
     *
     * @param  array   $json         パース済み JSON
     * @param  ?string $target_area  ターゲットエリア
     * @return array   エラーメッセージの配列（空 = 正常）
     */
    public function validate_json_output( array $json, ?string $target_area = null ): array {
        $errors = [];

        // 必須キーチェック
        if ( empty( $json['summary'] ) ) {
            $errors[] = 'summary が空です';
        }
        if ( empty( $json['good_points'] ) || ! is_array( $json['good_points'] ) ) {
            $errors[] = 'good_points が空または配列ではありません';
        } elseif ( count( $json['good_points'] ) < 2 ) {
            $errors[] = 'good_points が2件未満です';
        }
        if ( empty( $json['improvement_points'] ) || ! is_array( $json['improvement_points'] ) ) {
            $errors[] = 'improvement_points が空または配列ではありません';
        } elseif ( count( $json['improvement_points'] ) < 2 ) {
            $errors[] = 'improvement_points が2件未満です';
        }
        if ( $target_area !== null ) {
            if ( empty( $json['area_evaluation'] ) ) {
                $errors[] = 'area_evaluation がありません（target_area 指定時は必須）';
            }
        }
        if ( empty( $json['insight'] ) ) {
            $errors[] = 'insight が空です';
        }
        if ( empty( $json['actions'] ) || ! is_array( $json['actions'] ) ) {
            $errors[] = 'actions が空または配列ではありません';
        } elseif ( count( $json['actions'] ) < 3 ) {
            $errors[] = 'actions が3件未満です';
        }

        return $errors;
    }

    // =========================================================
    // プライベート: 役割定義
    // =========================================================

    private function build_role_section( bool $is_easy_mode ): string {
        if ( $is_easy_mode ) {
            return <<<'ROLE'
# あなたの役割
あなたは、ウェブの知識がまったくない個人事業主・中小企業の社長の隣に座って、
やさしく教えてくれる「頼れるWeb担当の友人」です。
アクセス解析の説明者ではなく、クライアントに伴走するWeb運用アドバイザーとして振る舞ってください。

数値だけでなく、クライアントの事業内容・サービス・ターゲット顧客・詳細ペルソナを前提に解釈してください。
事業やターゲットに合わない一般論は避け、このクライアント固有の文脈に合った分析・提案をしてください。
不明な点は断定せず、仮説として「〜かもしれません」「〜の可能性があります」と表現してください。
ROLE;
        }

        return <<<'ROLE'
# あなたの役割
あなたはアクセス解析の説明者ではなく、クライアントに伴走するWeb運用アドバイザーです。

## 重要な行動原則
- 数値だけでなく、クライアント設定・事業内容・詳細ペルソナを前提に解釈すること
- 事業やターゲットに合わない一般論は避けること
- ペルソナの悩み・不安・検索意図に照らして、流入やページ閲覧の意味を考えること
- 次のアクションは、実際の改善作業に落とし込みやすい具体的な内容にすること
- 不明な点は断定せず、仮説として「〜の可能性があります」等と表現すること
- 同じ内容の繰り返しや、抽象的で中身のない褒め言葉を避けること
ROLE;
    }

    // =========================================================
    // プライベート: クライアント設定コンテキスト
    // =========================================================

    private function build_client_context_section( array $client_info ): string {
        $lines = [];
        $lines[] = '# クライアント情報';
        $lines[] = "- 解析対象サイトURL: " . ( $client_info['site_url'] ?? '' );
        $lines[] = "- 業種・業態: " . ( $client_info['industry'] ?? '' );

        if ( ! empty( $client_info['industry_subcategory'] ) && is_array( $client_info['industry_subcategory'] ) ) {
            $lines[] = "- 業態詳細: " . implode( ', ', $client_info['industry_subcategory'] );
        }
        if ( ! empty( $client_info['industry_detail'] ) ) {
            $lines[] = "- 業種の特徴: " . $client_info['industry_detail'];
        }
        if ( ! empty( $client_info['business_type'] ) ) {
            $type_labels = [
                'visit'       => '来店型ビジネス',
                'non_visit'   => '非来店型ビジネス',
                'reservation' => '予約型ビジネス',
                'ec'          => 'ECサイト',
                'other'       => 'その他',
            ];
            $lines[] = "- ビジネス形態: " . ( $type_labels[ $client_info['business_type'] ] ?? $client_info['business_type'] );
        }
        if ( ! empty( $client_info['stage'] ) ) {
            $stage_labels = [
                'launch'    => '立ち上げ期',
                'awareness' => '認知拡大期',
                'growth'    => '成長期',
                'mature'    => '安定期',
                'renewal'   => 'リニューアル期',
            ];
            $lines[] = "- 成長ステージ: " . ( $stage_labels[ $client_info['stage'] ] ?? $client_info['stage'] );
        }
        if ( ! empty( $client_info['area_label'] ) ) {
            $lines[] = "- 商圏・対応エリア: " . $client_info['area_label'];
        }
        if ( ! empty( $client_info['main_conversions'] ) ) {
            $lines[] = "- 主要コンバージョン: " . $client_info['main_conversions'];
        }

        // 月次設定
        $monthly_fields = [
            'issue'            => '現在の課題',
            'goal_monthly'     => '今月の目標',
            'focus_numbers'    => '注目指標',
            'current_state'    => '現在の取り組み',
            'goal_main'        => '主要目標',
            'additional_notes' => 'その他留意事項',
        ];
        foreach ( $monthly_fields as $key => $label ) {
            if ( ! empty( $client_info[ $key ] ) ) {
                $lines[] = "- {$label}: " . $client_info[ $key ];
            }
        }

        return implode( "\n", $lines );
    }

    // =========================================================
    // プライベート: ペルソナ情報（全量、切り詰めなし）
    // =========================================================

    private function build_persona_section( array $client_info ): string {
        $lines = [];

        $has_any = false;
        foreach ( [ 'persona_one_liner', 'persona_detail_text', 'persona_age_ranges',
                     'persona_genders', 'persona_attributes', 'persona_decision_factors',
                     'persona_reference_urls' ] as $key ) {
            if ( ! empty( $client_info[ $key ] ) ) {
                $has_any = true;
                break;
            }
        }

        if ( ! $has_any ) {
            return '';
        }

        $lines[] = '# ターゲット顧客像（ペルソナ）';
        $lines[] = '';
        $lines[] = '以下のペルソナ情報はレポート解釈の前提条件です。';
        $lines[] = '- 数字の増減をこの事業目的に照らして解釈すること';
        $lines[] = '- 何を成果とみなすべきかの基準にすること';
        $lines[] = '- どの導線を優先改善すべきか判断する材料にすること';
        $lines[] = '- ペルソナの悩み・不安・検索意図を踏まえて流入やページ閲覧の意味を考えること';
        $lines[] = '';

        if ( ! empty( $client_info['persona_one_liner'] ) ) {
            $lines[] = "## ターゲット顧客の概要";
            $lines[] = $client_info['persona_one_liner'];
            $lines[] = '';
        }

        // 基本属性
        $attrs = [];
        if ( ! empty( $client_info['persona_age_ranges'] ) && is_array( $client_info['persona_age_ranges'] ) ) {
            $attrs[] = "- 年齢層: " . implode( ', ', $client_info['persona_age_ranges'] );
        }
        if ( ! empty( $client_info['persona_genders'] ) && is_array( $client_info['persona_genders'] ) ) {
            $attrs[] = "- 性別: " . implode( ', ', $client_info['persona_genders'] );
        }
        if ( ! empty( $client_info['persona_attributes'] ) && is_array( $client_info['persona_attributes'] ) ) {
            $attrs[] = "- 属性: " . implode( ', ', $client_info['persona_attributes'] );
        }
        if ( ! empty( $client_info['persona_decision_factors'] ) && is_array( $client_info['persona_decision_factors'] ) ) {
            $attrs[] = "- 意思決定の特徴: " . implode( ', ', $client_info['persona_decision_factors'] );
        }
        if ( ! empty( $attrs ) ) {
            $lines[] = "## 基本属性";
            $lines = array_merge( $lines, $attrs );
            $lines[] = '';
        }

        // 詳細ペルソナ（全文、切り詰めなし — ChatGPT は 128k コンテキスト）
        if ( ! empty( $client_info['persona_detail_text'] ) ) {
            $lines[] = "## 詳細ペルソナ";
            $lines[] = $client_info['persona_detail_text'];
            $lines[] = '';
        }

        // 競合・参考サイト
        if ( ! empty( $client_info['persona_reference_urls'] ) && is_array( $client_info['persona_reference_urls'] ) ) {
            $ref_parts = [];
            foreach ( $client_info['persona_reference_urls'] as $ref ) {
                $url  = $ref['url'] ?? '';
                $note = $ref['note'] ?? '';
                if ( $url ) {
                    $ref_parts[] = $note ? "{$url}（{$note}）" : $url;
                }
            }
            if ( $ref_parts ) {
                $lines[] = "## 参考・競合サイト";
                $lines[] = implode( "\n", $ref_parts );
                $lines[] = '';
            }
        }

        return implode( "\n", $lines );
    }

    // =========================================================
    // プライベート: 統合型/分離型レポートの指示
    // =========================================================

    private function build_domain_instruction( array $client_info ): string {
        $report_type = $client_info['report_type'] ?? 'integrated';
        $site_domain = $client_info['site_domain'] ?? '';
        $maps_domain = $client_info['maps_domain'] ?? '';

        if ( $report_type === 'separated' ) {
            return <<<DOMAIN
# レポートタイプ: 分離型
解析対象サイト（{$site_domain}）と Googleビジネスプロフィール（{$maps_domain}）は異なるサイトです。
- ホームページ分析は「{$site_domain}」の成果として記述
- MEOデータは「{$maps_domain}」の成果として記述
- 両者の数値を合算・混同しないこと
DOMAIN;
        }

        return <<<DOMAIN
# レポートタイプ: 統合型
解析対象サイトとGoogleビジネスプロフィールは同じドメイン（{$site_domain}）です。
集客全体の流れとして統合的に評価してください。
DOMAIN;
    }

    // =========================================================
    // プライベート: 初心者モードルール
    // =========================================================

    private function build_easy_mode_rules(): string {
        return <<<'RULES'
# 文体ルール（初心者モード）

## 絶対ルール
1. 専門用語は一切使わない。以下の言い換えを使う:
   - セッション → 「ホームページに来てくれた人の数」
   - PV → 「見られたページの数」
   - 直帰率 → 「1ページだけ見て帰った人の割合」
   - CVR → 「来てくれた人のうちゴールを達成してくれた割合」
   - エンゲージメント率 → 「じっくり見てくれた人の割合」
   - オーガニック検索 → 「Google検索から来た人」
   - インプレッション → 「検索結果に表示された回数」
   - クリック数 → 「検索結果からクリックされた回数」
2. 「ホームページ」という言葉をそのまま使う。「お店」などの比喩は使わない
3. 1文は短く（30文字前後）。だらだら続けない
4. 「つまりどういうこと？」を毎回書く。数字だけ並べず意味を添える
5. 改善アクションは「何を」「どこに」「どうする」まで書く
6. 数値は必ず前月と比較して「増えた/減った/変わらない」を明記
7. 感情的な表現を使う。「すごい！」「ちょっと心配」「これはチャンス！」など

## 出力時の強調ルール
- すべての数値を <strong> タグで囲む
- 重要な結論も <strong> で強調
- Markdown の ** は絶対に使用禁止。HTML の <strong> のみ使用
RULES;
    }

    // =========================================================
    // プライベート: 通常モードルール
    // =========================================================

    private function build_normal_mode_rules(): string {
        return <<<'RULES'
# 文体ルール（通常モード）

## 必須ルール
- クライアント向けにわかりやすい自然な日本語
- 専門用語はできるだけかみ砕く
- 数字の変化は意味とセットで説明する（単に数値を言い換えるだけの文章は禁止）
- 過剰に大げさにしない
- 改善提案は実行しやすい粒度にする
- 同じ内容の繰り返しを避ける
- ポジティブさは維持しつつ、課題は曖昧にしない
- クライアント設定やペルソナに合わない一般論で済ませない
- 根拠のない断定をしない

## NG（絶対に避けること）
- 抽象的で中身のない褒め言葉
- 数字を言い換えるだけの文章
- 同じ意味の反復
- 無駄に長い説明
- 事業内容とズレた改善提案

## 出力時の強調ルール
- すべての数値を <strong> タグで囲む（例外なし）
- 重要なキーワード・動詞・形容詞も <strong> で強調する
  例: <strong>増加</strong>、<strong>+8.1%</strong>、<strong>322セッション</strong>
- Markdown の ** は絶対に使用禁止。HTML の <strong> のみ使用
RULES;
    }

    // =========================================================
    // プライベート: JSON出力スキーマ指示
    // =========================================================

    private function build_json_schema_instruction( bool $is_easy_mode, ?string $target_area ): string {
        $area_note = $target_area !== null
            ? '"area_evaluation": { "facts": "データから分かる事実（HTML）", "possibilities": "考えられる可能性（HTML）" },'
            : '// area_evaluation は不要（ターゲットエリア未指定のため）';

        $area_label = $target_area ?? '全国';

        if ( $is_easy_mode ) {
            $actions_schema = <<<'ACT'
  "actions": [
    { "priority_label": "おすすめ① いちばん大事！", "title": "施策タイトル", "description": "「何を」「どこに」「どうする」を具体的に。〜してみませんか？の口調" },
    { "priority_label": "おすすめ② やっておくと安心", "title": "施策タイトル", "description": "具体的な説明" },
    { "priority_label": "おすすめ③ 余裕があれば", "title": "施策タイトル", "description": "具体的な説明" }
  ]
ACT;
        } else {
            $actions_schema = <<<'ACT'
  "actions": [
    { "priority_label": "Priority 1 - 最優先", "title": "20文字以内の具体的アクション名", "description": "100文字程度の詳細説明。必ず数値目標を含める" },
    { "priority_label": "Priority 2 - 最優先", "title": "2つ目のアクション名", "description": "詳細説明" },
    { "priority_label": "Priority 3 - 中優先", "title": "3つ目のアクション名", "description": "詳細説明" }
  ]
ACT;
        }

        return <<<SCHEMA
# 出力形式

必ず以下のJSON構造で出力してください。JSON以外の文字列は一切出力しないでください。

```json
{
  "summary": "2〜3文で直近の状態を説明。<strong>で数値を強調。「今月は」で始めない",
  "good_points": [
    "完結した1文。<strong>で数値・重要語を強調。最低2件、最大5件",
    "..."
  ],
  "improvement_points": [
    "完結した1文。<strong>で数値・重要語を強調。最低2件、最大5件",
    "..."
  ],
  {$area_note}
  "insight": {
    "facts": "データから分かる事実（<ul><li>形式のHTML推奨）",
    "possibilities": "そこから考えられる可能性（<p>タグのHTML推奨）"
  },
{$actions_schema}
}
```

## 各セクションの要件

### summary
- 直近の状態を友人に話すように2〜3文で簡潔にまとめる
- 「今月は」という書き出しは使わず、「ここ最近」「直近では」など期間に依存しない表現を使う

### good_points
- 各項目は完結した1文。名詞句だけや「：」で終わる見出しは禁止
- 事業内容・ペルソナを踏まえて、なぜ良いのかを説明する
- 最低2件、最大5件

### improvement_points
- 各項目は完結した1文。名詞句だけの記述は禁止
- 課題の背景と影響を簡潔に説明する
- 最低2件、最大5件

### area_evaluation（ターゲットエリア: {$area_label}）
- facts: ターゲットエリアからのアクセス数・全体比・前月比を具体的に記述
- possibilities: その数値から推測できること

### insight
- facts: データから読み取れる事実を箇条書きで
- possibilities: 事実から導かれる仮説や示唆を。断定せず「可能性があります」等で表現

### actions
- 必ず3件出力すること（省略禁止）
- クライアントの事業内容・ペルソナに合った具体的な施策にすること
- 「何を」「どこに」「どうする」まで具体的に書くこと
SCHEMA;
    }

    // =========================================================
    // プライベート: HTML レンダリング
    // =========================================================

    private function render_summary_section( string $text ): string {
        if ( $text === '' ) return '';
        return "\n<div class=\"summary\"><p>{$text}</p></div>\n";
    }

    private function render_good_points_section( array $items, bool $is_easy_mode ): string {
        if ( empty( $items ) ) return '';

        $heading = $is_easy_mode ? '<h3>⭕ 良かったこと</h3>' : '';
        $li_html = '';
        foreach ( $items as $item ) {
            if ( is_string( $item ) && $item !== '' ) {
                $li_html .= "<li>{$item}</li>\n";
            }
        }
        if ( $li_html === '' ) return '';

        return <<<HTML
<div class="good-points">
{$heading}
<ul class="point-list">
{$li_html}</ul>
</div>
HTML;
    }

    private function render_improvement_points_section( array $items, bool $is_easy_mode ): string {
        if ( empty( $items ) ) return '';

        $heading = $is_easy_mode ? '<h3>❌ 課題</h3>' : '';
        $li_html = '';
        foreach ( $items as $item ) {
            if ( is_string( $item ) && $item !== '' ) {
                $li_html .= "<li>{$item}</li>\n";
            }
        }
        if ( $li_html === '' ) return '';

        return <<<HTML
<div class="improvement-points">
{$heading}
<ul class="point-list">
{$li_html}</ul>
</div>
HTML;
    }

    private function render_area_section( $area_data, bool $is_easy_mode ): string {
        if ( empty( $area_data ) ) return '';

        // 文字列の場合（シンプルな出力）
        if ( is_string( $area_data ) ) {
            $heading = $is_easy_mode ? '<h3>🏠 地元のお客さんの動き</h3>' : '<h3>データから分かる事実</h3>';
            return <<<HTML
<div class="area-box"><div class="consideration">
{$heading}
<p>{$area_data}</p>
</div></div>
HTML;
        }

        // オブジェクトの場合（facts + possibilities）
        $facts = $area_data['facts'] ?? '';
        $possibilities = $area_data['possibilities'] ?? '';

        if ( $is_easy_mode ) {
            return <<<HTML
<div class="area-box"><div class="consideration">
<h3>🏠 地元のお客さんの動き</h3>
{$facts}
{$possibilities}
</div></div>
HTML;
        }

        return <<<HTML
<div class="area-box"><div class="consideration">
<h3>データから分かる事実</h3>
{$facts}
<h3>考えられる可能性</h3>
{$possibilities}
</div></div>
HTML;
    }

    private function render_insight_section( $insight_data, bool $is_easy_mode ): string {
        if ( empty( $insight_data ) ) return '';

        // 文字列の場合
        if ( is_string( $insight_data ) ) {
            $heading = $is_easy_mode
                ? '<h3>今のサイトの状態をひと言で言うと</h3>'
                : '<h3>データから分かる事実</h3>';
            return <<<HTML
<div class="insight-box"><div class="consideration">
{$heading}
<p>{$insight_data}</p>
</div></div>
HTML;
        }

        // オブジェクトの場合
        $facts = $insight_data['facts'] ?? '';
        $possibilities = $insight_data['possibilities'] ?? '';

        if ( $is_easy_mode ) {
            return <<<HTML
<div class="insight-box"><div class="consideration">
<h3>今のサイトの状態をひと言で言うと</h3>
{$facts}
{$possibilities}
</div></div>
HTML;
        }

        return <<<HTML
<div class="insight-box"><div class="consideration">
<h3>データから分かる事実</h3>
{$facts}
<h3>そこから考えられる可能性</h3>
{$possibilities}
</div></div>
HTML;
    }

    private function render_actions_section( array $actions, bool $is_easy_mode ): string {
        if ( empty( $actions ) ) return '';

        $heading = $is_easy_mode
            ? '<h2>💡 今後の作戦（ネクストステップ）</h2>'
            : '<h2>🚀 今すぐやるべき3つのアクション</h2>';

        $items_html = '';
        foreach ( $actions as $action ) {
            $priority = esc_html( $action['priority_label'] ?? '' );
            $title = $action['title'] ?? '';
            $description = $action['description'] ?? '';
            $items_html .= <<<ITEM
<div class="action-item">
<div class="action-priority">{$priority}</div>
<div class="action-title">{$title}</div>
<div class="action-description">{$description}</div>
</div>
ITEM;
        }

        return <<<HTML
<div class="actions">
{$heading}
{$items_html}
</div>
HTML;
    }
}
