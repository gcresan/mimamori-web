<?php
// FILE: inc/gcrev-api/modules/class-chat-analysis-service.php

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Chat_Analysis_Service' ) ) { return; }

/**
 * Gcrev_Chat_Analysis_Service
 *
 * `{prefix}gcrev_chat_logs` に蓄積されたユーザー発話・AI応答を Gemini に送り、
 * ニーズ・改善案をまとめた分析レポートを生成する。
 *
 * 生成したレポートは CPT `mimamori_chat_report` として保存する。
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Gcrev_Chat_Analysis_Service {

    /** 1レポートで分析対象にする最大ログ件数（トークン超過防止） */
    private const MAX_LOGS_PER_REPORT = 500;

    /** 1メッセージの最大文字数（Gemini 入力トリム用） */
    private const MAX_MESSAGE_CHARS = 500;

    /**
     * 指定期間のチャットログから分析レポートを生成し、CPT に保存する。
     *
     * @param int $days           分析対象期間（日数）
     * @param int $target_user_id 0 = 全ユーザー、>0 = 特定ユーザー
     * @return array { success, post_id, message, log_count }
     */
    public function generate_report( int $days = 30, int $target_user_id = 0 ): array {
        $days = max( 1, min( 365, $days ) );

        $logs = $this->fetch_logs( $days, $target_user_id );
        if ( count( $logs ) < 3 ) {
            return [
                'success'   => false,
                'post_id'   => 0,
                'message'   => sprintf( '分析対象のログが不足しています（%d件）。3件以上必要です。', count( $logs ) ),
                'log_count' => count( $logs ),
            ];
        }

        try {
            $analysis = $this->call_ai( $logs, $days, $target_user_id );
        } catch ( \Throwable $e ) {
            file_put_contents(
                '/tmp/gcrev_chat_debug.log',
                date( 'Y-m-d H:i:s' ) . ' analysis ERROR: ' . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return [
                'success'   => false,
                'post_id'   => 0,
                'message'   => 'AI 分析に失敗しました: ' . $e->getMessage(),
                'log_count' => count( $logs ),
            ];
        }

        $post_id = $this->save_report( $analysis, $logs, $days, $target_user_id );
        if ( $post_id === 0 ) {
            return [
                'success'   => false,
                'post_id'   => 0,
                'message'   => 'レポート保存に失敗しました。',
                'log_count' => count( $logs ),
            ];
        }

        return [
            'success'   => true,
            'post_id'   => $post_id,
            'message'   => sprintf( '%d件のログから分析レポートを生成しました。', count( $logs ) ),
            'log_count' => count( $logs ),
        ];
    }

    /**
     * 分析対象のログを取得する。
     *
     * @return array<int,array>
     */
    private function fetch_logs( int $days, int $target_user_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'gcrev_chat_logs';

        $cutoff = ( new DateTimeImmutable( 'now', wp_timezone() ) )
            ->modify( "-{$days} days" )
            ->format( 'Y-m-d H:i:s' );

        if ( $target_user_id > 0 ) {
            $sql = $wpdb->prepare(
                "SELECT id, user_id, intent, page_type, page_url, is_followup,
                        is_quick_prompt, param_gate, error_message, message, response, created_at
                 FROM {$table}
                 WHERE user_id = %d AND created_at >= %s
                 ORDER BY created_at DESC
                 LIMIT %d",
                $target_user_id,
                $cutoff,
                self::MAX_LOGS_PER_REPORT
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT id, user_id, intent, page_type, page_url, is_followup,
                        is_quick_prompt, param_gate, error_message, message, response, created_at
                 FROM {$table}
                 WHERE created_at >= %s
                 ORDER BY created_at DESC
                 LIMIT %d",
                $cutoff,
                self::MAX_LOGS_PER_REPORT
            );
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Gemini にログを投げて分析 JSON を受け取る。
     */
    private function call_ai( array $logs, int $days, int $target_user_id ): array {
        if ( ! class_exists( 'Gcrev_Config' ) || ! class_exists( 'Gcrev_AI_Client' ) ) {
            throw new \Exception( 'Gcrev_AI_Client が利用できません。' );
        }

        $config = new Gcrev_Config();
        $client = new Gcrev_AI_Client( $config );

        $prompt = $this->build_prompt( $logs, $days, $target_user_id );
        $response = $client->call_gemini_api( $prompt, [
            'temperature'      => 0.4,
            'max_output_tokens' => 4096,
        ] );

        $json = $this->extract_json( (string) $response );
        if ( $json === null ) {
            throw new \Exception( 'AI 応答から JSON を抽出できませんでした。' );
        }
        return $json;
    }

    /**
     * 分析プロンプトを構築する。
     */
    private function build_prompt( array $logs, int $days, int $target_user_id ): string {
        $log_lines = [];
        foreach ( $logs as $i => $log ) {
            $msg = mb_substr( (string) $log['message'], 0, self::MAX_MESSAGE_CHARS );
            $res = mb_substr( (string) ( $log['response'] ?? '' ), 0, 200 );
            $meta_bits = [];
            if ( ! empty( $log['intent'] ) )           { $meta_bits[] = 'intent=' . $log['intent']; }
            if ( ! empty( $log['page_type'] ) )        { $meta_bits[] = 'page=' . $log['page_type']; }
            if ( ! empty( $log['param_gate'] ) )       { $meta_bits[] = 'param_gate=' . $log['param_gate']; }
            if ( ! empty( $log['is_followup'] ) )      { $meta_bits[] = 'followup'; }
            if ( ! empty( $log['error_message'] ) )    { $meta_bits[] = 'ERROR'; }
            $meta = empty( $meta_bits ) ? '' : ' [' . implode( ', ', $meta_bits ) . ']';

            $log_lines[] = sprintf( "%d.%s Q: %s", $i + 1, $meta, $msg );
            if ( $res !== '' ) {
                $log_lines[] = '   A(要約): ' . preg_replace( '/\s+/u', ' ', $res );
            }
        }
        $log_block = implode( "\n", $log_lines );

        $scope = $target_user_id > 0
            ? "ユーザーID {$target_user_id} の直近 {$days} 日間"
            : "全ユーザーの直近 {$days} 日間";

        return <<<PROMPT
あなたは、SaaS プロダクトのユーザー行動アナリストです。
以下は「みまもりウェブ」（中小企業向けウェブ分析ダッシュボード）の AI チャット機能に寄せられた、{$scope}の質問ログです。
これを分析し、プロダクト改善のためのインサイトを抽出してください。

# ログデータ（各行=1質問、インデント行=AIの応答要約、[...]内はメタデータ）
{$log_block}

# 出力要件
下記の JSON 形式のみで出力してください（マークダウン・前置き・コードフェンス一切なし）。
各フィールドの記述は日本語で、中小企業の事業主に伝わる平易な表現を使ってください。

{
  "summary": "期間全体の傾向を 3〜4 文で要約（全体のボリューム感、質問の主な傾向、特徴的な動き）",
  "top_needs": [
    { "theme": "テーマ名（短く）", "count": 件数, "description": "ユーザーが何を知りたがっているか 1〜2 文", "example_questions": ["代表的な質問文", "..."] }
  ],
  "unresolved_issues": [
    { "issue": "未解決・困りごとのテーマ", "count": 件数, "description": "なぜ解決できていないか（エラー/param_gate/followup 多発 等を踏まえて）", "example_questions": ["..."] }
  ],
  "page_hotspots": [
    { "page_type": "ページ識別子", "count": 件数, "observation": "そのページで何が起きているか" }
  ],
  "improvement_suggestions": [
    { "priority": "high|medium|low", "target": "改善対象（UI / プロンプト / データ / 機能追加 など）", "suggestion": "具体的な改善案", "rationale": "根拠となる質問パターン" }
  ],
  "quality_flags": [
    "AI 回答品質で気になった点（空応答・誤解・堅い言葉遣い・of course 症 など、ログから観察できた範囲で）"
  ]
}

# 分析の観点
- top_needs は頻度順に最大 8 項目。類似質問はクラスタリングして 1 テーマに集約する。
- unresolved_issues は param_gate（確認質問だけ返して終了）、followup 多発、ERROR、回答が抽象的すぎるケース等を拾う。
- improvement_suggestions は「このテーマで質問が多いなら、ダッシュボードにこのカードを追加すべき」のような、具体的で実装可能な提案にする。
- 個人名・企業名・具体的な URL 等の個人情報はレポート本文に含めない（テーマ抽出のみに使う）。
PROMPT;
    }

    /**
     * AI 応答から JSON を抽出する（コードフェンス対応）。
     */
    private function extract_json( string $text ): ?array {
        $text = trim( $text );
        if ( $text === '' ) { return null; }

        // コードフェンス除去
        $text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
        $text = preg_replace( '/\s*```$/i', '', $text );
        $text = trim( (string) $text );

        // 先頭の `{` から末尾の `}` までを抽出
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        if ( $start === false || $end === false || $end <= $start ) {
            return null;
        }
        $json_str = substr( $text, $start, $end - $start + 1 );

        $parsed = json_decode( $json_str, true );
        return is_array( $parsed ) ? $parsed : null;
    }

    /**
     * 分析結果を CPT として保存する。
     *
     * @return int 作成した投稿ID（失敗時 0）
     */
    private function save_report( array $analysis, array $logs, int $days, int $target_user_id ): int {
        $now_tz = new DateTimeImmutable( 'now', wp_timezone() );
        $title  = sprintf(
            'チャット分析レポート %s（直近%d日・%d件・%s）',
            $now_tz->format( 'Y-m-d H:i' ),
            $days,
            count( $logs ),
            $target_user_id > 0 ? 'user=' . $target_user_id : '全体'
        );

        $post_id = wp_insert_post( [
            'post_type'    => 'mimamori_chat_report',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => wp_json_encode( $analysis, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ),
        ], true );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return 0;
        }

        update_post_meta( $post_id, '_chat_report_days', $days );
        update_post_meta( $post_id, '_chat_report_user_id', $target_user_id );
        update_post_meta( $post_id, '_chat_report_log_count', count( $logs ) );
        update_post_meta( $post_id, '_chat_report_summary', (string) ( $analysis['summary'] ?? '' ) );
        update_post_meta( $post_id, '_chat_report_generated_at', $now_tz->format( 'Y-m-d H:i:s' ) );

        return (int) $post_id;
    }
}
