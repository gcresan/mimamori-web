<?php
// FILE: inc/gcrev-api/utils/class-qa-triage.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Triage
 *
 * QAバッチの低スコア原因を分類する（ルールベース）。
 * 1ケースに複数カテゴリが付く場合あり。
 *
 * 分類カテゴリ:
 *   TOOL_ERROR       実行失敗 or GA4/GSC API エラー         (critical)
 *   HALLUCINATION    コンテキスト外の数値を断言              (critical)
 *   PARAM_MISS       質問が特定データ要求だがクエリ未生成     (major)
 *   WRONG_PERIOD     期間の不一致                           (major)
 *   JSON_BROKEN      JSON パース失敗                        (major)
 *   JARGON           禁止用語使用                           (minor)
 *   FALSE_NEGATIVE   データあるのに「不明」と回答            (minor)
 *   EMPTY_RESPONSE   回答が空/極短                          (major)
 *   TIMEOUT          API タイムアウト                       (critical)
 *   PARAM_GATE       ParamResolver が確認質問を返した         (info)
 *   FOLLOWUP_FAIL    マルチターン後もデータ取得失敗           (major)
 *   UNKNOWN          上記に該当しない低スコア                (minor)
 *
 * @package GCREV_INSIGHT
 * @since   3.0.0
 */
class Mimamori_QA_Triage {

    /** 低スコア閾値（この点以下を分類対象） */
    private const LOW_SCORE_THRESHOLD = 70;

    /** 極短回答の文字数閾値 */
    private const MIN_RESPONSE_LENGTH = 20;

    /**
     * 1ケースを分類する。
     *
     * @param  array $case 実行結果（score, trace, raw_text, structured, error 等を含む）
     * @return array 分類結果配列 [{ category, severity, reason }]
     */
    public function classify( array $case ): array {
        $findings = [];

        // --- 実行失敗系 ---
        if ( ! ( $case['success'] ?? false ) ) {
            $error = $case['error'] ?? '';

            if ( stripos( $error, 'timeout' ) !== false || stripos( $error, 'timed out' ) !== false ) {
                $findings[] = [
                    'category' => 'TIMEOUT',
                    'severity' => 'critical',
                    'reason'   => $error,
                ];
            } else {
                $findings[] = [
                    'category' => 'TOOL_ERROR',
                    'severity' => 'critical',
                    'reason'   => $error,
                ];
            }

            return $findings; // 失敗ケースは他の分類不要
        }

        $score      = $case['score'] ?? [];
        $total      = $score['total'] ?? 0;
        $details    = $score['details'] ?? [];
        $raw_text   = $case['raw_text'] ?? '';
        $structured = $case['structured'] ?? [];
        $trace      = $case['trace'] ?? [];

        // 低スコアでなければ分類不要
        if ( $total >= self::LOW_SCORE_THRESHOLD ) {
            return [];
        }

        // --- EMPTY_RESPONSE ---
        if ( mb_strlen( $raw_text ) < self::MIN_RESPONSE_LENGTH ) {
            $findings[] = [
                'category' => 'EMPTY_RESPONSE',
                'severity' => 'major',
                'reason'   => sprintf( 'Response too short: %d chars', mb_strlen( $raw_text ) ),
            ];
        }

        // --- HALLUCINATION ---
        $honesty_detail = $details['honesty'] ?? '';
        if ( is_string( $honesty_detail ) && str_starts_with( $honesty_detail, 'HALLUCINATION' ) ) {
            $findings[] = [
                'category' => 'HALLUCINATION',
                'severity' => 'critical',
                'reason'   => $honesty_detail,
            ];
        }

        $di_detail = $details['data_integrity'] ?? '';
        if ( $di_detail === 'numbers_without_context' ) {
            // data_integrity でも捏造を検出
            if ( ! $this->has_category( $findings, 'HALLUCINATION' ) ) {
                $findings[] = [
                    'category' => 'HALLUCINATION',
                    'severity' => 'critical',
                    'reason'   => 'Numbers in answer but no context data',
                ];
            }
        }

        // --- FALSE_NEGATIVE ---
        if ( is_string( $honesty_detail ) && str_starts_with( $honesty_detail, 'FALSE_NEGATIVE' ) ) {
            $findings[] = [
                'category' => 'FALSE_NEGATIVE',
                'severity' => 'minor',
                'reason'   => $honesty_detail,
            ];
        }

        // --- PARAM_MISS ---
        $category    = $case['question']['category'] ?? '';
        $det_queries = $trace['deterministic_queries'] ?? [];
        $flex_count  = $trace['flex_results_count'] ?? 0;
        $digest_len  = $trace['digest_length'] ?? mb_strlen( (string) ( $trace['digest'] ?? '' ) );

        // KPI/ページ系の質問でクエリが生成されず、ダイジェストも空
        if ( in_array( $category, [ 'kpi', 'page' ], true ) && empty( $det_queries ) && $flex_count === 0 && $digest_len === 0 ) {
            $findings[] = [
                'category' => 'PARAM_MISS',
                'severity' => 'major',
                'reason'   => "Category={$category} but no queries generated and no digest",
            ];
        }

        // --- PARAM_GATE (info — スコアには影響しない) ---
        $param_gate = $trace['param_gate'] ?? [];
        if ( ! empty( $param_gate ) ) {
            $findings[] = [
                'category' => 'PARAM_GATE',
                'severity' => 'info',
                'reason'   => 'ParamResolver confirmation: ' . implode( ', ', $param_gate ),
            ];
        }

        // --- FOLLOWUP_FAIL: マルチターン後もデータ取得に失敗 ---
        $is_followup = $case['is_followup'] ?? false;
        if ( $is_followup ) {
            $planner_queries   = $trace['planner_queries'] ?? [];
            $followup_resolved = $trace['followup_resolved'] ?? null;

            // フォローアップ解決後もクエリ生成ゼロ & ダイジェスト空 → パイプライン未動作
            if ( empty( $det_queries ) && $flex_count === 0 && empty( $planner_queries ) && $digest_len === 0 ) {
                $findings[] = [
                    'category' => 'FOLLOWUP_FAIL',
                    'severity' => 'major',
                    'reason'   => 'Multi-turn follow-up: no queries generated. resolved=' . ( $followup_resolved ?? 'null' ),
                ];
            }
        }

        // --- WRONG_PERIOD ---
        $period_detail = $details['period_accuracy'] ?? '';
        if ( is_string( $period_detail ) && str_contains( $period_detail, 'period_match=' ) ) {
            // period_match=0/N のようなケース
            if ( preg_match( '/period_match=0\/(\d+)/', $period_detail, $pm ) && (int) $pm[1] > 0 ) {
                $findings[] = [
                    'category' => 'WRONG_PERIOD',
                    'severity' => 'major',
                    'reason'   => $period_detail,
                ];
            }
        }

        // --- JSON_BROKEN ---
        $json_detail = $details['structure_json'] ?? '';
        if ( $json_detail === 'no_structured_data' && mb_strlen( $raw_text ) >= self::MIN_RESPONSE_LENGTH ) {
            $findings[] = [
                'category' => 'JSON_BROKEN',
                'severity' => 'major',
                'reason'   => 'No structured data parsed from response',
            ];
        }

        // --- JARGON ---
        $jargon_detail = $details['structure_jargon'] ?? '';
        if ( is_string( $jargon_detail ) && str_starts_with( $jargon_detail, 'found:' ) ) {
            $findings[] = [
                'category' => 'JARGON',
                'severity' => 'minor',
                'reason'   => $jargon_detail,
            ];
        }

        // --- UNKNOWN ---
        if ( empty( $findings ) && $total < self::LOW_SCORE_THRESHOLD ) {
            $findings[] = [
                'category' => 'UNKNOWN',
                'severity' => 'minor',
                'reason'   => sprintf( 'Low score (%d) but no specific issue identified', $total ),
            ];
        }

        return $findings;
    }

    /**
     * findings 内に特定カテゴリが既にあるか
     *
     * @param  array  $findings 分類結果配列
     * @param  string $category カテゴリ名
     * @return bool
     */
    private function has_category( array $findings, string $category ): bool {
        foreach ( $findings as $f ) {
            if ( ( $f['category'] ?? '' ) === $category ) {
                return true;
            }
        }
        return false;
    }
}
