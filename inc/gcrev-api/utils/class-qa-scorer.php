<?php
// FILE: inc/gcrev-api/utils/class-qa-scorer.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Scorer
 *
 * QAバッチ結果を100点満点で採点する。
 *
 * ルーブリック:
 *   data_integrity   40点  数値がコンテキストと一致するか
 *   period_accuracy   20点  期間言及が正しいか
 *   honesty          20点  データなし時に断言していないか
 *   structure        20点  JSON構造・禁止用語チェック
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Mimamori_QA_Scorer {

    /** 配点 */
    private const WEIGHTS = [
        'data_integrity'  => 40,
        'period_accuracy' => 20,
        'honesty'         => 20,
        'structure'       => 20,
    ];

    /** 数値の許容誤差（±2%） */
    private const NUM_TOLERANCE = 0.02;

    /** パーセントの許容誤差（±0.5pp） */
    private const PCT_TOLERANCE = 0.5;

    /** 禁止用語リスト（システムプロンプトの用語変換ルールに準拠） */
    private const JARGON_TERMS = [
        'セッション', 'CV', 'CTR', 'PV', 'KPI', 'SEO',
        'インプレッション', 'コンバージョン', 'オーガニック',
        'リファラル', 'バウンスレート', 'エンゲージメント率',
    ];

    /**
     * 1ケースを採点する。
     *
     * @param  array $case 実行結果（raw_text, structured, trace を含む）
     * @return array { total, data_integrity, period_accuracy, honesty, structure, details }
     */
    public function score( array $case ): array {
        $raw_text   = $case['raw_text'] ?? '';
        $structured = $case['structured'] ?? [];
        $trace      = $case['trace'] ?? [];

        $details = [];

        $data_integrity  = $this->score_data_integrity( $raw_text, $trace, $details );
        $period_accuracy = $this->score_period_accuracy( $raw_text, $case['question'] ?? [], $trace, $details );
        $honesty         = $this->score_honesty( $raw_text, $trace, $details );
        $structure       = $this->score_structure( $raw_text, $structured, $details );

        $total = $data_integrity + $period_accuracy + $honesty + $structure;

        return [
            'total'           => $total,
            'data_integrity'  => $data_integrity,
            'period_accuracy' => $period_accuracy,
            'honesty'         => $honesty,
            'structure'       => $structure,
            'details'         => $details,
        ];
    }

    // =========================================================
    // data_integrity (40点)
    // =========================================================

    /**
     * 回答内の数値がコンテキストデータと一致するかチェック。
     *
     * @param  string $raw_text 回答テキスト
     * @param  array  $trace    トレースデータ
     * @param  array  &$details 詳細ログ
     * @return int    スコア (0-40)
     */
    private function score_data_integrity( string $raw_text, array $trace, array &$details ): int {
        $max = self::WEIGHTS['data_integrity'];

        // コンテキストから数値を抽出
        $context_text = implode( "\n", array_filter( [
            $trace['digest'] ?? '',
            $trace['flex_text'] ?? '',
            $trace['enrichment_text'] ?? '',
            $trace['context_blocks'] ?? '',
        ] ) );

        $context_numbers = $this->extract_numbers( $context_text );
        $answer_numbers  = $this->extract_numbers( $raw_text );

        // コンテキストも回答も数値なし → データ不要の質問として満点
        if ( empty( $answer_numbers ) && empty( $context_numbers ) ) {
            $details['data_integrity'] = 'no_numbers_in_both';
            return $max;
        }

        // コンテキストに数値なしだが回答に具体的数値あり → 大幅減点（捏造の可能性）
        if ( empty( $context_numbers ) && ! empty( $answer_numbers ) ) {
            $details['data_integrity'] = 'numbers_without_context';
            $details['fabricated_numbers'] = $answer_numbers;
            return (int) ( $max * 0.25 ); // 10/40
        }

        // 回答に数値なしだがコンテキストに数値あり → 質問による（やや減点）
        if ( empty( $answer_numbers ) && ! empty( $context_numbers ) ) {
            $details['data_integrity'] = 'no_numbers_in_answer';
            return (int) ( $max * 0.7 ); // 28/40
        }

        // マッチ率計算
        $matched = 0;
        $unmatched_numbers = [];

        foreach ( $answer_numbers as $ans_num ) {
            if ( $this->number_exists_in_context( $ans_num, $context_numbers ) ) {
                $matched++;
            } else {
                $unmatched_numbers[] = $ans_num;
            }
        }

        $total_ans = count( $answer_numbers );
        $match_rate = $total_ans > 0 ? $matched / $total_ans : 0;

        $score = (int) round( $max * $match_rate );

        $details['data_integrity'] = sprintf( 'matched=%d/%d (%.0f%%)', $matched, $total_ans, $match_rate * 100 );
        if ( ! empty( $unmatched_numbers ) ) {
            $details['unmatched_numbers'] = array_slice( $unmatched_numbers, 0, 10 );
        }

        return $score;
    }

    // =========================================================
    // period_accuracy (20点)
    // =========================================================

    /**
     * 回答で言及した期間がクエリの期間と一致するかチェック。
     *
     * @param  string $raw_text 回答テキスト
     * @param  array  $question 質問データ
     * @param  array  $trace    トレースデータ
     * @param  array  &$details 詳細ログ
     * @return int    スコア (0-20)
     */
    private function score_period_accuracy( string $raw_text, array $question, array $trace, array &$details ): int {
        $max = self::WEIGHTS['period_accuracy'];

        // 期間パターン検出
        $period_patterns = [
            '/(\d{4})年(\d{1,2})月/',     // 2026年2月
            '/(\d{1,2})月/',              // 2月
            '/先月/',
            '/前月/',
            '/前年/',
            '/去年/',
            '/今月/',
            '/今日/',
            '/直近\d+日/',
        ];

        $has_period_mention = false;
        foreach ( $period_patterns as $pattern ) {
            if ( preg_match( $pattern, $raw_text ) ) {
                $has_period_mention = true;
                break;
            }
        }

        // 期間への言及がなければ質問次第（中間点）
        if ( ! $has_period_mention ) {
            $category = $question['category'] ?? '';
            if ( $category === 'comparison' ) {
                // 比較カテゴリで期間言及なしはやや減点
                $details['period_accuracy'] = 'comparison_no_period_mention';
                return (int) ( $max * 0.5 );
            }
            $details['period_accuracy'] = 'no_period_mention';
            return $max; // 期間不要の質問は満点
        }

        // コンテキストから期間情報を取得
        $context_text = ( $trace['digest'] ?? '' ) . ( $trace['flex_text'] ?? '' ) . ( $trace['enrichment_text'] ?? '' );

        // 年月パターンの一致チェック
        preg_match_all( '/(\d{4})年(\d{1,2})月/', $raw_text, $ans_months );
        preg_match_all( '/(\d{4})年(\d{1,2})月/', $context_text, $ctx_months );

        if ( ! empty( $ans_months[0] ) && ! empty( $ctx_months[0] ) ) {
            // 回答の年月がコンテキストに含まれるかチェック
            $ans_set = array_unique( $ans_months[0] );
            $ctx_set = array_unique( $ctx_months[0] );
            $valid = 0;
            foreach ( $ans_set as $ym ) {
                if ( in_array( $ym, $ctx_set, true ) ) {
                    $valid++;
                }
            }
            $rate = count( $ans_set ) > 0 ? $valid / count( $ans_set ) : 1;
            $score = (int) round( $max * $rate );
            $details['period_accuracy'] = sprintf( 'period_match=%d/%d', $valid, count( $ans_set ) );
            return $score;
        }

        // 明示的な年月なしの期間表現は一律OK
        $details['period_accuracy'] = 'relative_period_ok';
        return $max;
    }

    // =========================================================
    // honesty (20点)
    // =========================================================

    /**
     * データなし時に断言していないか / 根拠なし数値がないかチェック。
     *
     * @param  string $raw_text 回答テキスト
     * @param  array  $trace    トレースデータ
     * @param  array  &$details 詳細ログ
     * @return int    スコア (0-20)
     */
    private function score_honesty( string $raw_text, array $trace, array &$details ): int {
        $max = self::WEIGHTS['honesty'];

        $digest     = $trace['digest'] ?? '';
        $flex_text  = $trace['flex_text'] ?? '';
        $enrich     = $trace['enrichment_text'] ?? '';
        $context    = $trace['context_blocks'] ?? '';
        $has_data   = ( $digest !== '' || $flex_text !== '' || $enrich !== '' );

        $answer_numbers = $this->extract_numbers( $raw_text );

        // HALLUCINATION: データなしなのに具体的数値を断言
        if ( ! $has_data && ! empty( $answer_numbers ) ) {
            // ただし挨拶系や一般質問で数字が含まれる場合はスキップ
            $hedging = $this->has_hedging( $raw_text );
            if ( ! $hedging ) {
                $details['honesty'] = 'HALLUCINATION: no data but asserts numbers';
                $details['hallucinated_numbers'] = $answer_numbers;
                return 0;
            }
            $details['honesty'] = 'no_data_but_hedged';
            return (int) ( $max * 0.5 );
        }

        // FALSE_NEGATIVE: データあるのに「確認できません」等
        if ( $has_data ) {
            $denial_patterns = [
                '/データ[をが]?確認できません/',
                '/情報[をが]?取得できません/',
                '/データ[がは]ありません/',
                '/確認する(?:こと)?ができません/',
                '/お答えすることが(?:でき|難し)/',
            ];
            foreach ( $denial_patterns as $pattern ) {
                if ( preg_match( $pattern, $raw_text ) ) {
                    $details['honesty'] = 'FALSE_NEGATIVE: data exists but denied';
                    return (int) ( $max * 0.3 ); // 6/20
                }
            }
        }

        // ヘッジング適切使用をチェック
        if ( ! $has_data && $this->has_hedging( $raw_text ) ) {
            $details['honesty'] = 'appropriate_hedging';
            return $max;
        }

        $details['honesty'] = 'ok';
        return $max;
    }

    // =========================================================
    // structure (20点)
    // =========================================================

    /**
     * JSON構造・type・セクション数・禁止用語をチェック。
     *
     * @param  string $raw_text   回答テキスト
     * @param  array  $structured 構造化データ
     * @param  array  &$details   詳細ログ
     * @return int    スコア (0-20)
     */
    private function score_structure( string $raw_text, array $structured, array &$details ): int {
        $max   = self::WEIGHTS['structure'];
        $score = 0;

        // 1. JSON パース成功 (5点)
        if ( ! empty( $structured ) ) {
            $score += 5;
            $details['structure_json'] = 'ok';
        } else {
            $details['structure_json'] = 'no_structured_data';
        }

        // 2. type が talk/advice (5点)
        $type = $structured['type'] ?? '';
        if ( in_array( $type, [ 'talk', 'advice' ], true ) ) {
            $score += 5;
            $details['structure_type'] = $type;
        } elseif ( $type !== '' ) {
            $details['structure_type'] = 'unexpected: ' . $type;
        } else {
            $details['structure_type'] = 'missing';
        }

        // 3. advice: sections ≤ 3 && items/section ≤ 3 (5点)
        if ( $type === 'advice' ) {
            $sections = $structured['sections'] ?? [];
            $section_count = count( $sections );
            $max_items = 0;
            foreach ( $sections as $sec ) {
                $items = $sec['items'] ?? [];
                $max_items = max( $max_items, count( $items ) );
            }
            if ( $section_count <= 3 && $max_items <= 3 ) {
                $score += 5;
                $details['structure_sections'] = "sections={$section_count}, max_items={$max_items}";
            } else {
                $details['structure_sections'] = "too_many: sections={$section_count}, max_items={$max_items}";
            }
        } elseif ( $type === 'talk' ) {
            // talk はセクション制限なし → 加点
            $score += 5;
            $details['structure_sections'] = 'talk_no_limit';
        }

        // 4. 禁止用語チェック (5点)
        $found_jargon = [];
        foreach ( self::JARGON_TERMS as $term ) {
            if ( mb_strpos( $raw_text, $term ) !== false ) {
                $found_jargon[] = $term;
            }
        }

        if ( empty( $found_jargon ) ) {
            $score += 5;
            $details['structure_jargon'] = 'clean';
        } else {
            $details['structure_jargon'] = 'found: ' . implode( ', ', $found_jargon );
        }

        return min( $score, $max );
    }

    // =========================================================
    // ユーティリティ
    // =========================================================

    /**
     * テキストから数値を抽出する。
     *
     * @param  string $text テキスト
     * @return array  数値文字列の配列
     */
    private function extract_numbers( string $text ): array {
        $numbers = [];

        // パーセント: 12.3% / 12%
        if ( preg_match_all( '/(\d+\.?\d*)\s*[%％]/', $text, $m ) ) {
            foreach ( $m[1] as $v ) {
                $numbers[] = [ 'value' => (float) $v, 'type' => 'percent' ];
            }
        }

        // 万: 1.2万 / 3万
        if ( preg_match_all( '/(\d+\.?\d*)万/', $text, $m ) ) {
            foreach ( $m[1] as $v ) {
                $numbers[] = [ 'value' => (float) $v * 10000, 'type' => 'number' ];
            }
        }

        // カンマ区切り数値: 1,234 / 12,345,678
        if ( preg_match_all( '/(?<!\d)(\d{1,3}(?:,\d{3})+)(?!\d)/', $text, $m ) ) {
            foreach ( $m[1] as $v ) {
                $num = (float) str_replace( ',', '', $v );
                // 万で既に追加済みかチェック
                $numbers[] = [ 'value' => $num, 'type' => 'number' ];
            }
        }

        // 普通の数値: 123 / 12.34（年月日や短い数値は除外）
        if ( preg_match_all( '/(?<![,\d年月日])(\d{2,})\.?\d*(?![,\d年月日%％万])/', $text, $m ) ) {
            foreach ( $m[0] as $v ) {
                $num = (float) $v;
                if ( $num > 0 ) {
                    $numbers[] = [ 'value' => $num, 'type' => 'number' ];
                }
            }
        }

        return $numbers;
    }

    /**
     * 回答の数値がコンテキスト内に存在するか（許容誤差付き）。
     *
     * @param  array $answer_num   回答の数値 { value, type }
     * @param  array $context_nums コンテキストの数値配列
     * @return bool
     */
    private function number_exists_in_context( array $answer_num, array $context_nums ): bool {
        $ans_val  = $answer_num['value'];
        $ans_type = $answer_num['type'];

        foreach ( $context_nums as $ctx ) {
            $ctx_val = $ctx['value'];

            if ( $ans_type === 'percent' && $ctx['type'] === 'percent' ) {
                // パーセント: ±0.5pp
                if ( abs( $ans_val - $ctx_val ) <= self::PCT_TOLERANCE ) {
                    return true;
                }
            } else {
                // 数値: ±2%
                if ( $ctx_val == 0 ) {
                    if ( $ans_val == 0 ) return true;
                    continue;
                }
                $diff = abs( ( $ans_val - $ctx_val ) / $ctx_val );
                if ( $diff <= self::NUM_TOLERANCE ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ヘッジング表現（不確定な言い方）が含まれるかチェック。
     *
     * @param  string $text テキスト
     * @return bool
     */
    private function has_hedging( string $text ): bool {
        $hedging_patterns = [
            '/かもしれません/',
            '/可能性があります/',
            '/と思われます/',
            '/と考えられます/',
            '/推測/',
            '/おそらく/',
            '/一般的に/',
            '/確認が必要/',
            '/データ[をが]?確認/',
        ];

        foreach ( $hedging_patterns as $pattern ) {
            if ( preg_match( $pattern, $text ) ) {
                return true;
            }
        }

        return false;
    }
}
