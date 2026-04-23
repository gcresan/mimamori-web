<?php
// FILE: inc/gcrev-api/modules/class-extra-prompt-generator-service.php

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Extra_Prompt_Generator_Service' ) ) { return; }

/**
 * Gcrev_Extra_Prompt_Generator_Service
 *
 * クライアントが入力した参考口コミを Gemini で分析し、
 * 既存の口コミ生成プロンプト（build_review_prompt）の `$ai_extra_prompt` に
 * そのまま流し込める形式の「追加ルールブロック」を生成する。
 *
 * 出力スキーマ:
 *   {
 *     "summary":      "参考口コミの傾向 (1-2行)",
 *     "analysis":     "特徴分析の箇条書き (Markdown)",
 *     "rules":        ["〜すること", ...5-10個],
 *     "extra_prompt": "■参考口コミの傾向\n<summary>\n\n■追加ルール\n・rule1\n・rule2..."
 *   }
 *
 * @package Mimamori_Web
 * @since   3.2.0
 */
class Gcrev_Extra_Prompt_Generator_Service {

    private const MAX_REVIEWS_CHARS = 6000; // 入力上限（トークン暴発防止）

    /**
     * @param string $reviews_text 参考口コミ（複数件を改行で連結したテキスト）
     * @return array { success, summary, analysis, rules, extra_prompt, message }
     */
    public function generate( string $reviews_text ): array {
        $reviews_text = trim( $reviews_text );
        if ( $reviews_text === '' ) {
            return [ 'success' => false, 'message' => '参考口コミが空です。' ];
        }
        if ( mb_strlen( $reviews_text ) < 40 ) {
            return [ 'success' => false, 'message' => '参考口コミが短すぎます。最低でも数文、できれば複数件を入力してください。' ];
        }
        if ( mb_strlen( $reviews_text ) > self::MAX_REVIEWS_CHARS ) {
            $reviews_text = mb_substr( $reviews_text, 0, self::MAX_REVIEWS_CHARS );
        }

        try {
            $prompt   = $this->build_prompt( $reviews_text );
            $response = $this->call_ai( $prompt );
            $parsed   = $this->extract_json( (string) $response );

            if ( ! is_array( $parsed ) ) {
                file_put_contents(
                    '/tmp/gcrev_extra_prompt_debug.log',
                    date( 'Y-m-d H:i:s' ) . ' parse FAIL head=' . substr( $response, 0, 200 )
                        . ' tail=' . substr( $response, -200 ) . "\n",
                    FILE_APPEND
                );
                return [ 'success' => false, 'message' => 'AI 応答を解釈できませんでした。再度お試しください。' ];
            }

            $summary      = $this->clip( $parsed['summary']  ?? '', 300 );
            $analysis     = $this->clip( $parsed['analysis'] ?? '', 2000, true );
            $rules        = $this->normalize_rules( $parsed['rules'] ?? [] );
            $extra_prompt = $this->clip( $parsed['extra_prompt'] ?? '', 3000, true );

            // extra_prompt が空ならルールから組み立て直す（フォールバック）
            if ( $extra_prompt === '' ) {
                $extra_prompt = $this->compose_extra_prompt( $summary, $rules );
            }

            if ( empty( $rules ) ) {
                return [ 'success' => false, 'message' => 'AI から有効なルールが返りませんでした。参考口コミを増やして再試行してください。' ];
            }

            return [
                'success'      => true,
                'summary'      => $summary,
                'analysis'     => $analysis,
                'rules'        => $rules,
                'extra_prompt' => $extra_prompt,
                'message'      => sprintf( '%d個のルールを生成しました。', count( $rules ) ),
            ];
        } catch ( \Throwable $e ) {
            file_put_contents(
                '/tmp/gcrev_extra_prompt_debug.log',
                date( 'Y-m-d H:i:s' ) . ' ERROR: ' . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return [ 'success' => false, 'message' => 'AI 生成中にエラーが発生しました: ' . $e->getMessage() ];
        }
    }

    // =========================================================
    // Prompt
    // =========================================================

    private function build_prompt( string $reviews_text ): string {
        return <<<PROMPT
あなたは、実際の口コミを分析し、「自然な口コミ生成のための追加プロンプト」を設計する専門家です。
以下の参考口コミをもとに、AIが自然な口コミを生成できるようにするための"特徴ルール"を作成してください。

# 目的
- AIっぽい不自然な文章を防ぐ
- 実際のユーザーが書いたような口コミに近づける
- 業種やクライアントごとの"書き方のクセ"を再現する

# 参考口コミ
{$reviews_text}

# タスク
次の3つを作成してください。

## ① 口コミの特徴分析（内部用）
以下の観点で、参考口コミの傾向を分析する。
- 文章の長さ（短い／中程度／長い）
- 文体（丁寧・ラフ・混在）
- 構成（整理されている／バラバラ）
- よく触れられている要素（例: 味、対応、価格、雰囲気など）
- 具体性の出し方（具体情報の有無）
- 感情の強さ（強い／控えめ）
- その他、特徴的なクセ

箇条書きで簡潔に書く。

## ② 口コミ生成用の特徴ルール
上記分析をもとに、AI が文章生成時に従うべき「書き方の特徴ルール」を作成する。

制約:
- 5〜10個程度
- 抽象的すぎず、実際に文章生成に使える内容
- 各ルールは「〜すること」という命令形で終える
- 業種に依存しすぎない（汎用性も考慮）
- やりすぎて不自然にならないレベルにする

## ③ 追加プロンプト（そのまま使う用）
②のルールを、既存の口コミ生成プロンプトにそのまま追加できる形にまとめる。

形式:
```
■参考口コミの傾向
（1〜2行で要約）

■追加ルール
・〜すること
・〜すること
```

# 重要ルール
- 元の口コミの文章をそのまま再利用しない
- 特定の表現を真似させるのではなく「傾向」を抽出する
- 過剰な演出（揺らぎ・クセの強調）は避ける
- あくまで"自然さを補強するための補助ルール"にする

# 禁止事項
- 口コミの文章をそのまま出力する
- 特定の文体を強制する（例: 必ずラフに書くなど）
- 極端なルール（例: 必ず短文にする、必ず感情を入れる）
- 宣伝的な要素の追加

# 出力形式
以下の JSON のみを出力してください（マークダウン・前置き・コードフェンス不可）。
各文字列に改行を含む場合は \\n で表現してください。

{
  "summary":     "参考口コミの傾向を1〜2文で要約",
  "analysis":    "① の箇条書き（Markdown形式の文字列、各行先頭にハイフン）",
  "rules":       ["〜すること", "〜すること", ... 5〜10個],
  "extra_prompt": "③ の形式に従った、そのままコピペ可能な追加プロンプトブロック（■参考口コミの傾向 と ■追加ルール の両セクションを含む文字列）"
}
PROMPT;
    }

    // =========================================================
    // AI call & parse
    // =========================================================

    private function call_ai( string $prompt ): string {
        if ( ! class_exists( 'Gcrev_Config' ) || ! class_exists( 'Gcrev_AI_Client' ) ) {
            throw new \Exception( 'Gcrev_AI_Client が利用できません。' );
        }
        $config = new Gcrev_Config();
        $client = new Gcrev_AI_Client( $config );
        return (string) $client->call_gemini_api( $prompt, [
            'temperature'     => 0.5,
            'maxOutputTokens' => 4096,
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
        if ( $start === false || $end === false || $end <= $start ) { return null; }
        $json = substr( $text, $start, $end - $start + 1 );
        $parsed = json_decode( $json, true );
        return is_array( $parsed ) ? $parsed : null;
    }

    // =========================================================
    // Normalize helpers
    // =========================================================

    private function clip( $text, int $max_len, bool $multiline = false ): string {
        if ( ! is_string( $text ) ) {
            if ( is_array( $text ) ) {
                $text = implode( "\n", array_filter( array_map( static function ( $v ) {
                    return is_string( $v ) ? trim( $v ) : '';
                }, $text ) ) );
            } else {
                return '';
            }
        }
        $text = $multiline ? trim( $text ) : trim( preg_replace( '/\s+/u', ' ', $text ) );
        if ( mb_strlen( $text ) > $max_len ) {
            $text = mb_substr( $text, 0, $max_len );
        }
        return $text;
    }

    private function normalize_rules( $rules ): array {
        if ( ! is_array( $rules ) ) { return []; }
        $out = [];
        foreach ( $rules as $r ) {
            if ( ! is_string( $r ) ) { continue; }
            $r = trim( preg_replace( '/\s+/u', ' ', $r ) );
            if ( $r === '' ) { continue; }
            // 先頭の「・」「-」等を落とす
            $r = preg_replace( '/^[・\-\*\s]+/u', '', $r );
            if ( $r === '' ) { continue; }
            // 末尾が「すること」で終わっていなければ追記しない（AI の元形を尊重。誤補正は避ける）
            if ( mb_strlen( $r ) > 200 ) { $r = mb_substr( $r, 0, 200 ); }
            $out[] = $r;
            if ( count( $out ) >= 10 ) { break; }
        }
        return $out;
    }

    private function compose_extra_prompt( string $summary, array $rules ): string {
        $lines = [ '■参考口コミの傾向', $summary !== '' ? $summary : '（分析不能）', '', '■追加ルール' ];
        foreach ( $rules as $r ) {
            $lines[] = '・' . $r;
        }
        return implode( "\n", $lines );
    }
}
