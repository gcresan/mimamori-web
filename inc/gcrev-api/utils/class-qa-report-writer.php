<?php
// FILE: inc/gcrev-api/utils/class-qa-report-writer.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Mimamori_QA_Report_Writer
 *
 * QAバッチの結果をファイルに出力する。
 *
 * 出力ファイル:
 *   meta.json          実行条件・サマリー
 *   cases.jsonl         1行1ケース（採点・分類付き）
 *   summary.md          カテゴリ別平均点・失敗分布
 *   failures_top10.md   低スコア上位10件の詳細
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Mimamori_QA_Report_Writer {

    /**
     * 全レポートファイルを出力する。
     *
     * @param string $out_dir 出力ディレクトリ
     * @param array  $meta    メタ情報
     * @param array  $results 結果配列（score, triage 付き）
     */
    public function write( string $out_dir, array $meta, array $results ): void {
        $dir = trailingslashit( $out_dir );

        // 統計計算
        $stats = $this->compute_stats( $results );

        // meta に平均スコアを追加
        $meta['avg_score']     = $stats['overall']['avg'];
        $meta['success_count'] = $stats['success_count'];
        $meta['fail_count']    = $stats['fail_count'];

        // 1. meta.json
        $this->write_file( $dir . 'meta.json', wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );

        // 2. cases.jsonl（採点・分類付きで上書き）
        $this->write_cases_jsonl( $dir . 'cases.jsonl', $results );

        // 3. summary.md
        $this->write_file( $dir . 'summary.md', $this->render_summary( $meta, $results, $stats ) );

        // 4. failures_top10.md
        $this->write_file( $dir . 'failures_top10.md', $this->render_failures( $results ) );
    }

    // =========================================================
    // 統計計算
    // =========================================================

    /**
     * 結果配列から統計を計算する。
     *
     * @param  array $results 結果配列
     * @return array 統計データ
     */
    private function compute_stats( array $results ): array {
        $total         = count( $results );
        $success_count = 0;
        $fail_count    = 0;

        $scores = [
            'total'           => [],
            'data_integrity'  => [],
            'period_accuracy' => [],
            'honesty'         => [],
            'structure'       => [],
        ];

        $by_category = [];
        $triage_dist = [];

        foreach ( $results as $r ) {
            if ( $r['success'] ) {
                $success_count++;
            } else {
                $fail_count++;
            }

            $s = $r['score'] ?? [];
            foreach ( $scores as $dim => &$arr ) {
                $arr[] = $s[ $dim ] ?? 0;
            }
            unset( $arr );

            // カテゴリ別集計
            $cat = $r['question']['category'] ?? 'unknown';
            if ( ! isset( $by_category[ $cat ] ) ) {
                $by_category[ $cat ] = [ 'count' => 0, 'scores' => [] ];
            }
            $by_category[ $cat ]['count']++;
            $by_category[ $cat ]['scores'][] = $s['total'] ?? 0;

            // 分類分布
            foreach ( ( $r['triage'] ?? [] ) as $t ) {
                $tc = $t['category'] ?? 'UNKNOWN';
                $triage_dist[ $tc ] = ( $triage_dist[ $tc ] ?? 0 ) + 1;
            }
        }

        // 集計
        $overall = [];
        foreach ( $scores as $dim => $arr ) {
            $overall[ $dim ] = $this->stat_summary( $arr );
        }

        $cat_stats = [];
        foreach ( $by_category as $cat => $data ) {
            $cat_stats[ $cat ] = [
                'count'     => $data['count'],
                'avg_score' => $data['count'] > 0 ? array_sum( $data['scores'] ) / $data['count'] : 0,
            ];
        }

        arsort( $triage_dist );

        return [
            'total'         => $total,
            'success_count' => $success_count,
            'fail_count'    => $fail_count,
            'overall'       => $overall['total'],
            'dimensions'    => $overall,
            'by_category'   => $cat_stats,
            'triage_dist'   => $triage_dist,
        ];
    }

    /**
     * 配列の統計サマリーを返す。
     *
     * @param  array $arr 数値配列
     * @return array { avg, min, max, p50 }
     */
    private function stat_summary( array $arr ): array {
        if ( empty( $arr ) ) {
            return [ 'avg' => 0, 'min' => 0, 'max' => 0, 'p50' => 0 ];
        }

        sort( $arr );
        $count = count( $arr );
        $mid   = (int) floor( $count / 2 );
        $p50   = $count % 2 === 0
            ? ( $arr[ $mid - 1 ] + $arr[ $mid ] ) / 2
            : $arr[ $mid ];

        return [
            'avg' => array_sum( $arr ) / $count,
            'min' => $arr[0],
            'max' => $arr[ $count - 1 ],
            'p50' => $p50,
        ];
    }

    // =========================================================
    // cases.jsonl（最終版）
    // =========================================================

    /**
     * 採点・分類付きの cases.jsonl を出力する。
     *
     * @param string $path    ファイルパス
     * @param array  $results 結果配列
     */
    private function write_cases_jsonl( string $path, array $results ): void {
        $lines = [];
        foreach ( $results as $r ) {
            // intent を最終 jsonl にも保存（Auto Promoter / Comparator が参照）
            $intent_name = '';
            $trace_intent = $r['trace']['intent'] ?? null;
            if ( is_array( $trace_intent ) && isset( $trace_intent['intent'] ) ) {
                $intent_name = (string) $trace_intent['intent'];
            }

            $lines[] = wp_json_encode( [
                'id'          => $r['question']['id'] ?? '',
                'category'    => $r['question']['category'] ?? '',
                'intent'      => $intent_name,
                'message'     => $r['question']['message'] ?? '',
                'page_type'   => $r['question']['page_type'] ?? '',
                'success'     => $r['success'],
                'error'       => $r['error'] ?? null,
                'duration_ms' => $r['duration_ms'] ?? 0,
                'raw_text'    => $r['raw_text'] ?? '',
                'structured'  => $r['structured'] ?? [],
                'score'       => $r['score'] ?? [],
                'triage'      => $r['triage'] ?? [],
                'started_at'  => $r['started_at'] ?? '',
                'ended_at'    => $r['ended_at'] ?? '',
                // マルチターン情報
                'is_followup' => $r['is_followup'] ?? false,
                'turn1'       => $r['turn1'] ?? null,
            ], JSON_UNESCAPED_UNICODE );
        }

        $this->write_file( $path, implode( "\n", $lines ) . "\n" );
    }

    // =========================================================
    // summary.md
    // =========================================================

    /**
     * summary.md を生成する。
     *
     * @param  array  $meta    メタ情報
     * @param  array  $results 結果配列
     * @param  array  $stats   統計データ
     * @return string Markdown
     */
    private function render_summary( array $meta, array $results, array $stats ): string {
        $total = $stats['total'];

        // 実行時間計算
        $started = $meta['started_at'] ?? '';
        $ended   = $meta['ended_at'] ?? '';
        $duration_str = '';
        if ( $started !== '' && $ended !== '' ) {
            $diff = strtotime( $ended ) - strtotime( $started );
            if ( $diff !== false && $diff > 0 ) {
                $min = (int) floor( $diff / 60 );
                $sec = $diff % 60;
                $duration_str = sprintf( '%dm %ds', $min, $sec );
            }
        }

        $md = "# QA Run Summary\n\n";
        $md .= sprintf( "- **Date**: %s\n", $meta['run_id'] ?? '' );
        $md .= sprintf( "- **User**: %d\n", $meta['user_id'] ?? 0 );
        $md .= sprintf( "- **Questions**: %d | **Seed**: %s\n", $meta['n'] ?? 0, $meta['seed'] ?? '' );
        if ( $duration_str !== '' ) {
            $md .= sprintf( "- **Duration**: %s\n", $duration_str );
        }
        $md .= sprintf( "- **Model**: %s\n", $meta['model'] ?? 'unknown' );
        $md .= sprintf( "- **Mode**: %s | **Category**: %s\n", $meta['mode'] ?? '', $meta['category'] ?? 'mixed' );
        $md .= "\n";

        // Overall Scores
        $dims = $stats['dimensions'] ?? [];
        $md .= "## Overall Scores\n\n";
        $md .= "| Dimension | Avg | Min | Max | P50 |\n";
        $md .= "|---|---|---|---|---|\n";

        $dim_labels = [
            'total'           => '**Total**',
            'data_integrity'  => 'Data Integrity',
            'period_accuracy' => 'Period',
            'honesty'         => 'Honesty',
            'structure'       => 'Structure',
        ];

        foreach ( $dim_labels as $key => $label ) {
            $d = $dims[ $key ] ?? [ 'avg' => 0, 'min' => 0, 'max' => 0, 'p50' => 0 ];
            $md .= sprintf( "| %s | %.1f | %d | %d | %.0f |\n", $label, $d['avg'], $d['min'], $d['max'], $d['p50'] );
        }
        $md .= "\n";

        // By Category
        $cat_stats = $stats['by_category'] ?? [];
        if ( ! empty( $cat_stats ) ) {
            $md .= "## By Category\n\n";
            $md .= "| Category | Count | Avg Score |\n";
            $md .= "|---|---|---|\n";
            foreach ( $cat_stats as $cat => $cs ) {
                $md .= sprintf( "| %s | %d | %.1f |\n", $cat, $cs['count'], $cs['avg_score'] );
            }
            $md .= "\n";
        }

        // Failure Distribution
        $triage_dist = $stats['triage_dist'] ?? [];
        if ( ! empty( $triage_dist ) ) {
            $md .= "## Failure Distribution\n\n";
            $md .= "| Type | Count | % of Total |\n";
            $md .= "|---|---|---|\n";
            foreach ( $triage_dist as $type => $cnt ) {
                $pct = $total > 0 ? ( $cnt / $total ) * 100 : 0;
                $md .= sprintf( "| %s | %d | %.1f%% |\n", $type, $cnt, $pct );
            }
            $md .= "\n";
        }

        // Multi-Turn Follow-up Stats
        $mt_count  = 0;
        $mt_scores = [];
        foreach ( $results as $r ) {
            if ( ! empty( $r['is_followup'] ) ) {
                $mt_count++;
                $mt_scores[] = $r['score']['total'] ?? 0;
            }
        }
        if ( $mt_count > 0 ) {
            $mt_avg = array_sum( $mt_scores ) / $mt_count;
            $md .= "## Multi-Turn Follow-up\n\n";
            $md .= sprintf( "- **Triggered**: %d / %d questions\n", $mt_count, $total );
            $md .= sprintf( "- **Avg Score (Turn 2)**: %.1f\n", $mt_avg );
            $md .= "\n";
        }

        // Slow Queries Top 5
        $sorted = $results;
        usort( $sorted, function ( $a, $b ) {
            return ( $b['duration_ms'] ?? 0 ) <=> ( $a['duration_ms'] ?? 0 );
        } );

        $md .= "## Slowest Queries (Top 5)\n\n";
        $md .= "| ID | Duration | Category | Message |\n";
        $md .= "|---|---|---|---|\n";
        foreach ( array_slice( $sorted, 0, 5 ) as $r ) {
            $q   = $r['question'] ?? [];
            $dur = ( $r['duration_ms'] ?? 0 ) / 1000;
            $msg = mb_strimwidth( $q['message'] ?? '', 0, 40, '…' );
            $md .= sprintf( "| %s | %.1fs | %s | %s |\n", $q['id'] ?? '', $dur, $q['category'] ?? '', $msg );
        }
        $md .= "\n";

        return $md;
    }

    // =========================================================
    // failures_top10.md
    // =========================================================

    /**
     * 低スコア上位10件の詳細分析を生成する。
     *
     * @param  array  $results 結果配列
     * @return string Markdown
     */
    private function render_failures( array $results ): string {
        // スコア昇順でソート
        $sorted = $results;
        usort( $sorted, function ( $a, $b ) {
            return ( $a['score']['total'] ?? 0 ) <=> ( $b['score']['total'] ?? 0 );
        } );

        // 上位10件
        $top10 = array_slice( $sorted, 0, 10 );

        $md = "# Failure Analysis — Top 10 Lowest Scores\n\n";

        if ( empty( $top10 ) ) {
            $md .= "No failures to report.\n";
            return $md;
        }

        foreach ( $top10 as $idx => $r ) {
            $q     = $r['question'] ?? [];
            $score = $r['score'] ?? [];
            $triage = $r['triage'] ?? [];

            $md .= sprintf( "## %d. [%s] %s (Score: %d)\n\n", $idx + 1, $q['id'] ?? '', $q['category'] ?? '', $score['total'] ?? 0 );
            $md .= sprintf( "**Question**: %s\n\n", $q['message'] ?? '' );

            // スコア内訳
            $md .= "**Score Breakdown**:\n";
            $md .= sprintf( "- Data Integrity: %d/40\n", $score['data_integrity'] ?? 0 );
            $md .= sprintf( "- Period Accuracy: %d/20\n", $score['period_accuracy'] ?? 0 );
            $md .= sprintf( "- Honesty: %d/20\n", $score['honesty'] ?? 0 );
            $md .= sprintf( "- Structure: %d/20\n", $score['structure'] ?? 0 );
            $md .= "\n";

            // 分類
            if ( ! empty( $triage ) ) {
                $md .= "**Triage**:\n";
                foreach ( $triage as $t ) {
                    $md .= sprintf( "- `%s` (%s): %s\n", $t['category'] ?? '', $t['severity'] ?? '', $t['reason'] ?? '' );
                }
                $md .= "\n";
            }

            // スコア詳細
            $details = $score['details'] ?? [];
            if ( ! empty( $details ) ) {
                $md .= "**Details**:\n";
                foreach ( $details as $key => $val ) {
                    $display = is_array( $val ) ? wp_json_encode( $val, JSON_UNESCAPED_UNICODE ) : (string) $val;
                    $md .= sprintf( "- %s: %s\n", $key, $display );
                }
                $md .= "\n";
            }

            // 回答抜粋
            $raw = $r['raw_text'] ?? '';
            if ( mb_strlen( $raw ) > 0 ) {
                $excerpt = mb_strimwidth( $raw, 0, 200, '…' );
                $md .= "**Response excerpt**:\n";
                $md .= "```\n" . $excerpt . "\n```\n\n";
            } elseif ( $r['error'] ?? '' ) {
                $md .= sprintf( "**Error**: %s\n\n", $r['error'] );
            }

            $md .= "---\n\n";
        }

        return $md;
    }

    // =========================================================
    // ファイル書き出しヘルパー
    // =========================================================

    /**
     * 改善サマリーを meta.json に追記する。
     *
     * 既存 meta.json を読み込み、improve キーを追加して上書きする。
     *
     * @param string $out_dir  QA実行出力ディレクトリ
     * @param array  $summary  改善サマリーデータ
     */
    public function append_improve_meta( string $out_dir, array $summary ): void {
        $meta_path = trailingslashit( $out_dir ) . 'meta.json';

        if ( ! file_exists( $meta_path ) ) return;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw = file_get_contents( $meta_path );
        if ( $raw === false ) return;

        $meta = json_decode( $raw, true );
        if ( ! is_array( $meta ) ) return;

        $meta['improve'] = [
            'below_pass'        => $summary['below_pass'] ?? 0,
            'passed_count'      => $summary['passed_count'] ?? 0,
            'still_below'       => $summary['still_below'] ?? 0,
            'avg_initial_score' => $summary['avg_initial_score'] ?? 0,
            'avg_final_score'   => $summary['avg_final_score'] ?? 0,
            'avg_revisions'     => $summary['avg_revisions'] ?? 0,
            'config'            => $summary['config'] ?? [],
        ];

        $this->write_file( $meta_path, wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
    }

    /**
     * ファイルを書き出す。
     *
     * @param string $path    ファイルパス
     * @param string $content 内容
     */
    private function write_file( string $path, string $content ): void {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written = file_put_contents( $path, $content );
        if ( $written === false ) {
            WP_CLI::warning( "Failed to write: {$path}" );
        }
    }
}
