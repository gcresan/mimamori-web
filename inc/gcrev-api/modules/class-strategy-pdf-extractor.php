<?php
// FILE: inc/gcrev-api/modules/class-strategy-pdf-extractor.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Strategy_Pdf_Extractor' ) ) { return; }

/**
 * Gcrev_Strategy_Pdf_Extractor
 *
 * クライアント企画書PDFを Gemini (Vertex AI) に投げて、戦略 JSON を抽出する。
 *
 * 設計方針:
 *   - PDFテキスト抽出は外部ライブラリではなく Gemini のマルチモーダル機能に丸投げ
 *     （PDFは inlineData mime=application/pdf でそのまま渡せる）
 *   - 出力 JSON は Gcrev_Strategy_Schema_Validator に必ず通して正規化する
 *   - 抽出結果は draft として保存し、UI 側でユーザーが編集できる前提
 *
 * 依存:
 *   - Gcrev_AI_Client     ::call_gemini_multimodal()
 *   - Gcrev_Strategy_Schema_Validator ::validate()
 *   - Gcrev_AI_Json_Parser ::parse_or_throw()  （あれば使用、なければ json_decode）
 *
 * 設計書: docs/strategy-report-design.md §4.2
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Gcrev_Strategy_Pdf_Extractor {

    /** PDFファイルサイズ上限（バイト） */
    public const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20MB

    private Gcrev_AI_Client $ai;

    public function __construct( Gcrev_AI_Client $ai ) {
        $this->ai = $ai;
    }

    /**
     * 添付ファイルIDから戦略JSONを抽出する。
     *
     * @param int $attachment_id WP attachment ID（PDF）
     * @return array {
     *     valid: bool,
     *     errors: string[],
     *     normalized: array,   // Schema_Validator で正規化済み戦略JSON
     *     raw_ai_text: string  // デバッグ・監査用
     * }
     * @throws \Exception ファイル読み込み・AI呼び出しに失敗した場合
     */
    public function extract_from_attachment( int $attachment_id ): array {
        $log = function ( string $msg ) use ( $attachment_id ) {
            file_put_contents(
                '/tmp/gcrev_strategy_debug.log',
                date( 'Y-m-d H:i:s' ) . " pdf_extract att={$attachment_id}: {$msg}\n",
                FILE_APPEND
            );
        };

        $log( 'start' );

        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            $log( 'FAILED file_not_found path=' . (string) $path );
            throw new \Exception( 'PDFファイルが見つかりません (attachment_id=' . $attachment_id . ')' );
        }
        $size = (int) filesize( $path );
        $log( "file_loaded size_bytes={$size}" );
        if ( $size <= 0 || $size > self::MAX_FILE_SIZE ) {
            $log( "FAILED invalid_size={$size}" );
            throw new \Exception( 'PDFファイルのサイズが不正です: ' . $size . ' bytes' );
        }

        // PDF をサーバー側で pdftotext でテキスト化してから Gemini に渡す
        // （Gemini multimodal で PDF inline を渡すと、リージョン・モデル組合せ次第で
        //  HTTP 417 になるため、ロバストなテキスト経路を採用）
        $text = $this->extract_text_with_pdftotext( $path );
        $text_len = mb_strlen( $text );
        $log( "pdftotext_extracted chars={$text_len}" );
        if ( $text_len < 30 ) {
            // ベクトルテキストが取れない（スキャン PDF など）
            $log( 'FAILED pdf_text_too_short_or_empty' );
            throw new \Exception( 'PDFからテキストを抽出できませんでした。スキャン画像のみのPDFは未対応です。テキスト主体のPDFをご用意ください。' );
        }

        // Gemini に渡すプロンプトサイズ上限ガード（テキスト 100k 文字以内に抑える）
        $max_chars = 100000;
        if ( $text_len > $max_chars ) {
            $text = mb_substr( $text, 0, $max_chars );
            $log( "pdftotext_truncated_to {$max_chars}" );
        }

        $prompt = $this->build_extraction_prompt() . "\n\n# PDF テキスト\n" . $text;

        $start_us = microtime( true );
        try {
            $log( 'gemini_call_start' );
            $raw = $this->ai->call_gemini_api( $prompt, [
                'temperature'      => 0.2,
                'maxOutputTokens'  => 4096,
                'responseMimeType' => 'application/json',
            ] );
            $dur_ms = (int) round( ( microtime( true ) - $start_us ) * 1000 );
            $log( "gemini_call_ok dur_ms={$dur_ms} raw_len=" . strlen( $raw ) );
        } catch ( \Throwable $e ) {
            $dur_ms = (int) round( ( microtime( true ) - $start_us ) * 1000 );
            $log( "FAILED gemini_call dur_ms={$dur_ms} msg=" . $e->getMessage() );
            throw new \Exception( 'AI抽出に失敗しました: ' . $e->getMessage() );
        }

        $parsed = $this->parse_json_lenient( $raw );
        if ( ! is_array( $parsed ) ) {
            $log( 'FAILED json_parse raw_head=' . substr( $raw, 0, 300 ) );
            throw new \Exception( 'AI応答をJSONとして解釈できませんでした' );
        }
        $log( 'json_parsed_ok' );

        $validation = Gcrev_Strategy_Schema_Validator::validate( $parsed );
        $log( 'validated valid=' . ( $validation['valid'] ? '1' : '0' ) . ' errors=' . count( $validation['errors'] ) );
        return [
            'valid'       => $validation['valid'],
            'errors'      => $validation['errors'],
            'normalized'  => $validation['normalized'],
            'raw_ai_text' => $raw,
        ];
    }

    /**
     * PDF からテキストを抽出する（poppler-utils の pdftotext を利用）。
     * 失敗時は空文字を返す。-layout でレイアウト保持、UTF-8 出力。
     */
    private function extract_text_with_pdftotext( string $path ): string {
        // pdftotext のパスを安全に解決
        $bin = '/usr/bin/pdftotext';
        if ( ! is_executable( $bin ) ) {
            $bin = trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) );
            if ( $bin === '' || ! is_executable( $bin ) ) {
                return '';
            }
        }
        $cmd = sprintf(
            '%s -layout -nopgbrk -enc UTF-8 %s -',
            escapeshellcmd( $bin ),
            escapeshellarg( $path )
        );
        $output = shell_exec( $cmd . ' 2>/dev/null' );
        if ( ! is_string( $output ) ) return '';

        // 連続する空白・改行を整形
        $output = preg_replace( "/\r\n?/", "\n", $output );
        $output = preg_replace( "/[ \t]+/", ' ', (string) $output );
        $output = preg_replace( "/\n{3,}/", "\n\n", (string) $output );
        return trim( (string) $output );
    }

    /**
     * PDF抽出用プロンプト（設計書 §4.2 ベース）
     */
    private function build_extraction_prompt(): string {
        $today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
        return <<<PROMPT
あなたは企画書からマーケティング戦略を構造化するアシスタントです。
添付されたPDF（クライアントの企画書・提案書・戦略資料）を読み、定義スキーマに従って JSON のみを返してください。

# 制約
- 出力は JSON のみ。前後の説明文・コードフェンス（```）は禁止。
- 抜けている項目は空配列 [] または空文字 "" を入れること。捏造禁止。
- meta.client_name は本文中の社名・屋号を抽出。見つからない場合は ""。
- meta.effective_from は {$today}（本日）を入れる。
- 課題（issues）と差別化要素（value_proposition）はそれぞれ最低1件抽出を試みる。
- 戦略方針（strategy）は1〜3文に要約。長文の引用は避ける。
- conversion_path は「HP訪問 → 〇〇 → 商談」のように矢印で経路を示す。

# 出力スキーマ
{
  "meta": {
    "client_name": "",
    "site_url": "",
    "extracted_from": "pdf",
    "effective_from": "YYYY-MM-DD"
  },
  "target":           "",
  "issues":           [],
  "strategy":         "",
  "value_proposition":[],
  "conversion_path":  "",
  "competitors":      [
    {"name":"","url":"","type":"peer|rival_major|rival_local","notes":""}
  ],
  "company_strengths": {
    "design_function":  [],
    "support_trust":    [],
    "economy_eco":      []
  },
  "differentiation_axes": [],
  "customer_segments": {
    "potential":   {"label":"潜在層","channel":"","kpi":[]},
    "semi_active": {"label":"準顕在層","channel":"","kpi":[]},
    "active":      {"label":"顕在層","channel":"","kpi":[]}
  },
  "customer_journey": [
    {"stage":"","pains":[],"messaging":[]}
  ],
  "site_map_priorities": [
    {"path":"","role":"","kpi_focus":[]}
  ]
}
PROMPT;
    }

    /**
     * AI応答をJSONとして寛容にパースする。
     * 期待: 純粋なJSON。実際: ```json ... ``` で囲まれていたり、前後に説明文が入ることがある。
     */
    private function parse_json_lenient( string $raw ): ?array {
        $text = trim( $raw );
        // コードフェンス除去
        $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
        $text = preg_replace( '/\s*```\s*$/', '', $text );
        $text = trim( (string) $text );

        // 直接デコード
        $decoded = json_decode( $text, true );
        if ( is_array( $decoded ) ) { return $decoded; }

        // { ... } の最大ブロックを抜き出して再試行
        if ( preg_match( '/\{.*\}/s', $text, $m ) ) {
            $candidate = $m[0];
            $decoded = json_decode( $candidate, true );
            if ( is_array( $decoded ) ) { return $decoded; }
        }
        return null;
    }
}
