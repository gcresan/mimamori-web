<?php
// FILE: inc/gcrev-api/utils/class-qa-intent-resolver.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Intent_Resolver
 *
 * intent 判定の SSOT。QA 側と本番側で同じロジックを使う。
 *
 * - 既存 functions.php::mimamori_rewrite_intent() をそのままラップする
 * - 新しい推測ロジックは追加しない
 * - 判定不能 / 空 → Mimamori_QA_Prompt_Registry::GLOBAL_KEY ("_global")
 *
 * 使用例:
 *   $intent_name = Mimamori_QA_Intent_Resolver::resolve([
 *       'message'   => $text,
 *       'page_type' => $page_type,
 *   ]);
 *
 * @package Mimamori_Web
 * @since   3.2.0
 */
class Mimamori_QA_Intent_Resolver {

    /**
     * intent を解決して文字列名を返す。
     *
     * @param array $context {
     *   message?: string, page_type?: string, intent?: array
     * }
     * @return string intent 名（例: site_improvement / reason_analysis / how_to /
     *                report_interpretation / general）。不明時は "_global"。
     */
    public static function resolve( array $context ): string {
        // 既に intent 配列が渡されていればそれを優先（二重判定回避）
        if ( is_array( $context['intent'] ?? null ) && isset( $context['intent']['intent'] ) ) {
            $name = self::coerce( (string) $context['intent']['intent'] );
            if ( $name !== '' ) {
                return $name;
            }
        }

        $message   = (string) ( $context['message'] ?? '' );
        $page_type = (string) ( $context['page_type'] ?? '' );

        if ( $message === '' ) {
            return Mimamori_QA_Prompt_Registry::GLOBAL_KEY;
        }

        if ( ! function_exists( 'mimamori_rewrite_intent' ) ) {
            // functions.php がまだロードされていないケース
            return Mimamori_QA_Prompt_Registry::GLOBAL_KEY;
        }

        $result = mimamori_rewrite_intent( $message, $page_type );
        $name   = is_array( $result ) ? (string) ( $result['intent'] ?? '' ) : '';
        return self::coerce( $name );
    }

    /**
     * registry 参照キーとして使える intent 名に正規化する。
     *
     * @param string $name
     * @return string
     */
    public static function coerce( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) {
            return Mimamori_QA_Prompt_Registry::GLOBAL_KEY;
        }
        // 予約キーの直接指定は禁止（外部入力対策）
        if ( $name === Mimamori_QA_Prompt_Registry::GLOBAL_KEY ) {
            return Mimamori_QA_Prompt_Registry::GLOBAL_KEY;
        }
        return $name;
    }

    /**
     * 保存済みケースデータ（cases.jsonl の 1 行）から intent を抽出する。
     * 優先順: 1) top-level intent, 2) trace.intent.intent, 3) message+page_type から再判定
     *
     * @param array $case_row
     * @return string
     */
    public static function from_case_row( array $case_row ): string {
        if ( ! empty( $case_row['intent'] ) ) {
            return self::coerce( (string) $case_row['intent'] );
        }
        $trace_intent = $case_row['trace']['intent'] ?? null;
        if ( is_array( $trace_intent ) && isset( $trace_intent['intent'] ) ) {
            return self::coerce( (string) $trace_intent['intent'] );
        }
        return self::resolve( [
            'message'   => (string) ( $case_row['message'] ?? '' ),
            'page_type' => (string) ( $case_row['page_type'] ?? '' ),
        ] );
    }
}
