<?php
// FILE: inc/gcrev-api/utils/class-qa-run-comparator.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Run_Comparator
 *
 * 2 つの QA run の cases.jsonl を読み、スコアの差分を集計する。
 * Auto Rollback と管理画面ダッシュボードが利用する。
 *
 * 出力形式（compare()）:
 *   [
 *     'avg_score_delta'     => float,    // 今回 - 前回（正なら改善）
 *     'avg_current'         => float,
 *     'avg_previous'        => float,
 *     'fail_count_delta'    => int,      // 失格件数の増加（正なら悪化）
 *     'hallucination_delta' => int,      // triage HALLUCINATION 件数の増加
 *     'by_intent'           => array,    // intent => { current_avg, previous_avg, delta }
 *     'by_category'         => array,    // category => { current_avg, previous_avg, delta }
 *     'meta'                => array,    // { current_run, previous_run, cases_current, cases_previous }
 *   ]
 *
 * @package Mimamori_Web
 * @since   3.2.0
 */
class Mimamori_QA_Run_Comparator {

    public const OUTPUT_BASE = 'mimamori/qa_runs';

    /** pass しきい値（fail 件数カウント用） */
    public const PASS_SCORE = 90;

    /**
     * 2 つの run を比較する。
     */
    public function compare( string $current_run_id, string $previous_run_id ): array {
        $current_cases  = $this->load_cases( $current_run_id );
        $previous_cases = $this->load_cases( $previous_run_id );

        $current_stats  = $this->aggregate( $current_cases );
        $previous_stats = $this->aggregate( $previous_cases );

        $by_intent   = $this->merge_groups( $current_stats['by_intent'], $previous_stats['by_intent'] );
        $by_category = $this->merge_groups( $current_stats['by_category'], $previous_stats['by_category'] );

        return [
            'avg_score_delta'     => $current_stats['avg'] - $previous_stats['avg'],
            'avg_current'         => $current_stats['avg'],
            'avg_previous'        => $previous_stats['avg'],
            'fail_count_delta'    => $current_stats['fail_count'] - $previous_stats['fail_count'],
            'hallucination_delta' => $current_stats['hallucination_count'] - $previous_stats['hallucination_count'],
            'by_intent'           => $by_intent,
            'by_category'         => $by_category,
            'meta'                => [
                'current_run'     => $current_run_id,
                'previous_run'    => $previous_run_id,
                'cases_current'   => count( $current_cases ),
                'cases_previous'  => count( $previous_cases ),
                'current_avg'     => $current_stats['avg'],
                'previous_avg'    => $previous_stats['avg'],
                'current_fails'   => $current_stats['fail_count'],
                'previous_fails'  => $previous_stats['fail_count'],
            ],
        ];
    }

    /**
     * 比較結果を JSON で保存する。
     */
    public function save_comparison( string $current_run_id, string $previous_run_id, array $diff ): ?string {
        $dir = $this->get_run_dir( $current_run_id );
        if ( $dir === null ) {
            return null;
        }
        $safe_prev = preg_replace( '/[^0-9_]/', '', $previous_run_id ) ?: 'prev';
        $path      = trailingslashit( $dir ) . 'comparison_' . $safe_prev . '.json';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents( $path, wp_json_encode( $diff, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
        return $path;
    }

    /**
     * 直近の前 run を探す（current より前の最新ディレクトリ）。
     */
    public function find_previous_run( string $current_run_id ): ?string {
        $upload = wp_upload_dir();
        $base   = trailingslashit( $upload['basedir'] ) . self::OUTPUT_BASE;
        if ( ! is_dir( $base ) ) {
            return null;
        }
        $dirs = glob( $base . '/*', GLOB_ONLYDIR );
        if ( ! is_array( $dirs ) ) {
            return null;
        }
        $ids = array_map( 'basename', $dirs );
        sort( $ids );
        $found = null;
        foreach ( $ids as $id ) {
            if ( ! preg_match( '/^\d{8}_\d{6}$/', $id ) ) {
                continue;
            }
            if ( $id === $current_run_id ) {
                return $found;
            }
            $found = $id;
        }
        return null;
    }

    // =========================================================
    // Aggregation
    // =========================================================

    /**
     * 1 run の統計を計算する。
     */
    private function aggregate( array $cases ): array {
        $totals      = [];
        $fail_count  = 0;
        $hall_count  = 0;
        $by_intent   = [];
        $by_category = [];

        foreach ( $cases as $row ) {
            $total = (int) ( $row['score']['total'] ?? 0 );
            $totals[] = $total;

            if ( $total < self::PASS_SCORE ) {
                $fail_count++;
            }

            // Triage HALLUCINATION カウント
            $triage = is_array( $row['triage'] ?? null ) ? $row['triage'] : [];
            foreach ( $triage as $t ) {
                if ( ( $t['category'] ?? '' ) === 'HALLUCINATION' ) {
                    $hall_count++;
                    break;
                }
            }

            // intent 集計（Intent Resolver で統一）
            $intent = Mimamori_QA_Intent_Resolver::from_case_row( $row );
            $by_intent[ $intent ]['scores'][] = $total;

            // category 集計（既存の category フィールド）
            $cat = (string) ( $row['category'] ?? 'unknown' );
            $by_category[ $cat ]['scores'][] = $total;
        }

        return [
            'avg'                 => $this->avg( $totals ),
            'fail_count'          => $fail_count,
            'hallucination_count' => $hall_count,
            'by_intent'           => $this->finalize_groups( $by_intent ),
            'by_category'         => $this->finalize_groups( $by_category ),
            'count'               => count( $cases ),
        ];
    }

    private function finalize_groups( array $groups ): array {
        $out = [];
        foreach ( $groups as $k => $g ) {
            $s = $g['scores'] ?? [];
            $out[ $k ] = [
                'count' => count( $s ),
                'avg'   => $this->avg( $s ),
            ];
        }
        return $out;
    }

    private function merge_groups( array $current, array $previous ): array {
        $keys = array_unique( array_merge( array_keys( $current ), array_keys( $previous ) ) );
        $out  = [];
        foreach ( $keys as $k ) {
            $c = $current[ $k ]['avg'] ?? null;
            $p = $previous[ $k ]['avg'] ?? null;
            $out[ $k ] = [
                'current_avg'   => $c,
                'previous_avg'  => $p,
                'delta'         => ( $c !== null && $p !== null ) ? $c - $p : null,
                'count_current' => $current[ $k ]['count'] ?? 0,
                'count_prev'    => $previous[ $k ]['count'] ?? 0,
            ];
        }
        return $out;
    }

    private function avg( array $arr ): float {
        if ( empty( $arr ) ) {
            return 0.0;
        }
        return array_sum( $arr ) / count( $arr );
    }

    // =========================================================
    // IO
    // =========================================================

    private function load_cases( string $run_id ): array {
        $dir = $this->get_run_dir( $run_id );
        if ( $dir === null ) {
            return [];
        }
        $path = trailingslashit( $dir ) . 'cases.jsonl';
        if ( ! file_exists( $path ) ) {
            return [];
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file
        $lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! is_array( $lines ) ) {
            return [];
        }
        $out = [];
        foreach ( $lines as $line ) {
            $row = json_decode( $line, true );
            if ( is_array( $row ) ) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private function get_run_dir( string $run_id ): ?string {
        if ( ! preg_match( '/^\d{8}_\d{6}$/', $run_id ) ) {
            return null;
        }
        $upload = wp_upload_dir();
        $base   = trailingslashit( $upload['basedir'] ) . self::OUTPUT_BASE;
        $dir    = trailingslashit( $base ) . $run_id;
        return is_dir( $dir ) ? $dir : null;
    }
}
