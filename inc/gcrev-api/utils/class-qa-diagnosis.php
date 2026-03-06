<?php
// FILE: inc/gcrev-api/utils/class-qa-diagnosis.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Diagnosis
 *
 * 採点結果を分析し、構造化された減点理由と改善アクションを生成する。
 * Improver が PromptTuner に渡すアクション一覧を作るための変換レイヤー。
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Mimamori_QA_Diagnosis {

    /** 各軸の満点 */
    private const MAX_SCORES = [
        'data_integrity'  => 40,
        'period_accuracy' => 20,
        'honesty'         => 20,
        'structure'       => 20,
    ];

    /** 減点しきい値（この割合未満で改善アクションを生成） */
    private const DEDUCTION_THRESHOLD = 0.9;

    /**
     * 1ケースを診断し、改善アクション一覧を返す。
     *
     * @param  array $case score + triage + trace 付きケースデータ
     * @return array 改善アクション配列
     */
    public function diagnose( array $case ): array {
        $score   = $case['score'] ?? [];
        $details = $score['details'] ?? [];
        $triage  = $case['triage'] ?? [];
        $actions = [];

        // data_integrity (40点)
        $actions = array_merge( $actions, $this->diagnose_data_integrity( $score, $details ) );

        // period_accuracy (20点)
        $actions = array_merge( $actions, $this->diagnose_period_accuracy( $score, $details ) );

        // honesty (20点)
        $actions = array_merge( $actions, $this->diagnose_honesty( $score, $details ) );

        // structure (20点)
        $actions = array_merge( $actions, $this->diagnose_structure( $score, $details ) );

        // triage ベースの追加アクション
        $actions = array_merge( $actions, $this->diagnose_triage( $triage ) );

        // 優先度でソート（deduction 大きい順）
        usort( $actions, static fn( $a, $b ) => $b['deduction'] <=> $a['deduction'] );

        return $actions;
    }

    /**
     * 改善アクション一覧から主要な減点理由キーを返す（stale検出用）。
     *
     * @param  array $actions diagnose() の出力
     * @return string 最大減点のアクション名
     */
    public function top_action_key( array $actions ): string {
        if ( empty( $actions ) ) {
            return 'none';
        }
        return $actions[0]['action'] . ':' . $actions[0]['axis'];
    }

    private function diagnose_data_integrity( array $score, array $details ): array {
        $max       = self::MAX_SCORES['data_integrity'];
        $actual    = $score['data_integrity'] ?? $max;
        $deduction = $max - $actual;
        if ( $deduction < 1 ) return [];

        $detail = $details['data_integrity'] ?? '';
        $actions = [];

        if ( $detail === 'numbers_without_context' ) {
            $actions[] = [
                'axis'      => 'data_integrity',
                'deduction' => $deduction,
                'reason'    => 'コンテキストにない数値を生成（捏造の可能性）',
                'action'    => 'lower_temperature',
                'params'    => [ 'step' => 0.2 ],
            ];
            $actions[] = [
                'axis'      => 'data_integrity',
                'deduction' => $deduction,
                'reason'    => 'データ根拠なしの数値生成を防止',
                'action'    => 'add_honesty_guard',
                'params'    => [],
            ];
        } elseif ( $detail === 'no_numbers_in_answer' ) {
            $actions[] = [
                'axis'      => 'data_integrity',
                'deduction' => $deduction,
                'reason'    => 'コンテキストに数値があるが回答に数値なし',
                'action'    => 'add_data_hint',
                'params'    => [],
            ];
        } elseif ( str_starts_with( $detail, 'matched=' ) ) {
            // matched=X/Y 形式
            $actions[] = [
                'axis'      => 'data_integrity',
                'deduction' => $deduction,
                'reason'    => "数値一致率が不足: {$detail}",
                'action'    => 'add_context',
                'params'    => [],
            ];
            if ( $deduction >= 15 ) {
                $actions[] = [
                    'axis'      => 'data_integrity',
                    'deduction' => $deduction,
                    'reason'    => '大幅な数値不一致',
                    'action'    => 'lower_temperature',
                    'params'    => [ 'step' => 0.1 ],
                ];
            }
        }

        return $actions;
    }

    private function diagnose_period_accuracy( array $score, array $details ): array {
        $max       = self::MAX_SCORES['period_accuracy'];
        $actual    = $score['period_accuracy'] ?? $max;
        $deduction = $max - $actual;
        if ( $deduction < 1 ) return [];

        $detail = $details['period_accuracy'] ?? '';

        if ( $detail === 'comparison_no_period_mention' ) {
            return [[
                'axis'      => 'period_accuracy',
                'deduction' => $deduction,
                'reason'    => '比較カテゴリで期間言及なし',
                'action'    => 'enforce_period',
                'params'    => [],
            ]];
        }

        if ( str_starts_with( $detail, 'period_match=' ) ) {
            return [[
                'axis'      => 'period_accuracy',
                'deduction' => $deduction,
                'reason'    => "期間一致率不足: {$detail}",
                'action'    => 'enforce_period',
                'params'    => [],
            ]];
        }

        return [];
    }

    private function diagnose_honesty( array $score, array $details ): array {
        $max       = self::MAX_SCORES['honesty'];
        $actual    = $score['honesty'] ?? $max;
        $deduction = $max - $actual;
        if ( $deduction < 1 ) return [];

        $detail = $details['honesty'] ?? '';
        $actions = [];

        if ( str_starts_with( $detail, 'HALLUCINATION' ) ) {
            $actions[] = [
                'axis'      => 'honesty',
                'deduction' => $deduction,
                'reason'    => 'ハルシネーション: データなしで数値断言',
                'action'    => 'lower_temperature',
                'params'    => [ 'step' => 0.3 ],
            ];
            $actions[] = [
                'axis'      => 'honesty',
                'deduction' => $deduction,
                'reason'    => 'ハルシネーション防止ガード追加',
                'action'    => 'add_honesty_guard',
                'params'    => [],
            ];
        } elseif ( str_starts_with( $detail, 'FALSE_NEGATIVE' ) ) {
            $actions[] = [
                'axis'      => 'honesty',
                'deduction' => $deduction,
                'reason'    => '虚偽否定: データがあるのに「確認できません」',
                'action'    => 'add_data_hint',
                'params'    => [],
            ];
        } elseif ( $detail === 'no_data_but_hedged' ) {
            $actions[] = [
                'axis'      => 'honesty',
                'deduction' => $deduction,
                'reason'    => 'データなし+ヘッジングあるが数値含む',
                'action'    => 'add_honesty_guard',
                'params'    => [],
            ];
        }

        return $actions;
    }

    private function diagnose_structure( array $score, array $details ): array {
        $max       = self::MAX_SCORES['structure'];
        $actual    = $score['structure'] ?? $max;
        $deduction = $max - $actual;
        if ( $deduction < 1 ) return [];

        $actions = [];

        if ( ( $details['structure_json'] ?? '' ) === 'no_structured_data' ) {
            $actions[] = [
                'axis'      => 'structure',
                'deduction' => min( $deduction, 10 ),
                'reason'    => 'JSONパース失敗',
                'action'    => 'add_format_guard',
                'params'    => [],
            ];
            $actions[] = [
                'axis'      => 'structure',
                'deduction' => min( $deduction, 5 ),
                'reason'    => 'トークン不足によるJSON切断の可能性',
                'action'    => 'increase_max_tokens',
                'params'    => [ 'step' => 1024 ],
            ];
        }

        if ( ( $details['structure_type'] ?? '' ) === 'missing' || str_starts_with( $details['structure_type'] ?? '', 'unexpected' ) ) {
            $actions[] = [
                'axis'      => 'structure',
                'deduction' => 5,
                'reason'    => 'type が talk/advice でない',
                'action'    => 'add_format_guard',
                'params'    => [],
            ];
        }

        $jargon_detail = $details['structure_jargon'] ?? '';
        if ( str_starts_with( $jargon_detail, 'found:' ) ) {
            $found_terms = trim( substr( $jargon_detail, 6 ) );
            $actions[] = [
                'axis'      => 'structure',
                'deduction' => 5,
                'reason'    => "禁止用語検出: {$found_terms}",
                'action'    => 'add_jargon_guard',
                'params'    => [ 'terms' => $found_terms ],
            ];
        }

        $sections_detail = $details['structure_sections'] ?? '';
        if ( str_starts_with( $sections_detail, 'too_many' ) ) {
            $actions[] = [
                'axis'      => 'structure',
                'deduction' => 5,
                'reason'    => "セクション数超過: {$sections_detail}",
                'action'    => 'add_format_guard',
                'params'    => [],
            ];
        }

        return $actions;
    }

    private function diagnose_triage( array $triage ): array {
        $actions = [];

        foreach ( $triage as $t ) {
            $cat = $t['category'] ?? '';

            if ( $cat === 'EMPTY_RESPONSE' ) {
                $actions[] = [
                    'axis'      => 'triage',
                    'deduction' => 20,
                    'reason'    => '空レスポンス',
                    'action'    => 'increase_max_tokens',
                    'params'    => [ 'step' => 1024 ],
                ];
            }
        }

        return $actions;
    }
}
