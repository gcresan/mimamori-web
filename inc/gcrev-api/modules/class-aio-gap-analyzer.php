<?php
// FILE: inc/gcrev-api/modules/class-aio-gap-analyzer.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Gcrev_AIO_Gap_Analyzer' ) ) { return; }

/**
 * AIO 差分分析クラス
 *
 * 競合ページ（AIO引用ページ）と自社ページの構造を比較し、
 * AIO掲載に必要な改善項目を優先度付きで生成する。
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Gcrev_AIO_Gap_Analyzer {

    /** カテゴリ定義 */
    private const CATEGORIES = [
        'content_structure'  => 'コンテンツ構造',
        'information_volume' => '情報量',
        'comprehensiveness'  => '網羅性',
        'trust_signals'      => '信頼性（E-E-A-T）',
        'structured_content' => '構造化コンテンツ',
        'freshness'          => '最新性',
    ];

    // =========================================================
    // キーワード単位の差分分析
    // =========================================================

    /**
     * 1キーワード分の差分分析
     *
     * @param array      $competitor_analyses 競合ページの解析結果配列
     * @param array|null $self_analysis       自社ページの解析結果（null=未特定）
     * @param string     $keyword             対象キーワード
     * @return array { gaps: array, stats: array }
     */
    public function analyze_gaps( array $competitor_analyses, ?array $self_analysis, string $keyword ): array {
        // 成功した競合のみ
        $competitors = array_filter( $competitor_analyses, function ( $a ) {
            return ( $a['fetch_status'] ?? '' ) === 'success';
        } );

        $comp_count = count( $competitors );
        if ( $comp_count === 0 ) {
            return [ 'gaps' => [], 'stats' => [], 'keyword' => $keyword ];
        }

        $gaps  = [];
        $stats = [];

        // --- コンテンツ構造 ---

        // 定義型コンテンツ
        $def_count = $this->count_feature( $competitors, 'has_definition' );
        $def_rate  = $this->calc_rate( $def_count, $comp_count );
        $self_def  = $self_analysis['has_definition'] ?? false;
        if ( $def_rate >= 40 && ! $self_def ) {
            $gaps[] = [
                'category'        => 'content_structure',
                'title'           => '定義型コンテンツが不足',
                'detail'          => "「{$keyword}」で AIO に掲載されている競合 {$comp_count} サイト中 {$def_count} サイトが「〜とは」形式の解説コンテンツを持っていますが、自社には存在しません。",
                'priority'        => $this->determine_priority( $def_rate, $self_def ),
                'competitor_rate' => $def_rate,
                'self_has'        => $self_def,
            ];
        }

        // FAQ構造
        $faq_count = $this->count_feature( $competitors, 'has_faq' );
        $faq_rate  = $this->calc_rate( $faq_count, $comp_count );
        $self_faq  = $self_analysis['has_faq'] ?? false;
        if ( $faq_rate >= 40 && ! $self_faq ) {
            $gaps[] = [
                'category'        => 'content_structure',
                'title'           => 'FAQ構造が存在しない',
                'detail'          => "競合 {$comp_count} サイト中 {$faq_count} サイトが FAQ 形式を採用しています。AIO は Q&A 形式のコンテンツを引用しやすい傾向があります。",
                'priority'        => $this->determine_priority( $faq_rate, $self_faq ),
                'competitor_rate' => $faq_rate,
                'self_has'        => $self_faq,
            ];
        }

        // HowTo / 手順型
        $howto_count = $this->count_feature( $competitors, 'has_howto' );
        $howto_rate  = $this->calc_rate( $howto_count, $comp_count );
        $self_howto  = $self_analysis['has_howto'] ?? false;
        if ( $howto_rate >= 40 && ! $self_howto ) {
            $gaps[] = [
                'category'        => 'content_structure',
                'title'           => '手順型コンテンツがない',
                'detail'          => "競合 {$comp_count} サイト中 {$howto_count} サイトが「方法」「手順」形式のコンテンツを持っています。ステップ形式のコンテンツは AIO で引用されやすい傾向があります。",
                'priority'        => $this->determine_priority( $howto_rate, $self_howto ),
                'competitor_rate' => $howto_rate,
                'self_has'        => $self_howto,
            ];
        }

        // --- 情報量 ---
        $comp_words     = array_column( $competitors, 'word_count' );
        $avg_comp_words = $comp_count > 0 ? (int) round( array_sum( $comp_words ) / $comp_count ) : 0;
        $self_words     = $self_analysis['word_count'] ?? 0;

        $stats['avg_competitor_word_count'] = $avg_comp_words;
        $stats['self_word_count']           = $self_words;

        if ( $avg_comp_words > 0 && $self_words < $avg_comp_words * 0.6 ) {
            $gaps[] = [
                'category'        => 'information_volume',
                'title'           => '情報量が不足',
                'detail'          => "競合平均: " . number_format( $avg_comp_words ) . "文字 / 自社: " . number_format( $self_words ) . "文字。AIO に引用されるページは十分な情報量を持つ傾向があります。",
                'priority'        => $self_words < $avg_comp_words * 0.4 ? 'high' : 'medium',
                'competitor_rate' => 100,
                'self_has'        => false,
            ];
        }

        // --- 網羅性（見出し階層の深さ） ---
        $comp_depths     = array_column( $competitors, 'heading_depth' );
        $avg_comp_depth  = $comp_count > 0 ? round( array_sum( $comp_depths ) / $comp_count, 1 ) : 0;
        $self_depth      = $self_analysis['heading_depth'] ?? 0;

        $stats['avg_competitor_heading_depth'] = $avg_comp_depth;
        $stats['self_heading_depth']           = $self_depth;

        $comp_heading_counts = array_map( function ( $a ) {
            return count( $a['headings'] ?? [] );
        }, $competitors );
        $avg_headings = $comp_count > 0 ? round( array_sum( $comp_heading_counts ) / $comp_count, 1 ) : 0;
        $self_headings = count( $self_analysis['headings'] ?? [] );

        $stats['avg_competitor_heading_count'] = $avg_headings;
        $stats['self_heading_count']           = $self_headings;

        if ( $avg_headings > 3 && $self_headings < $avg_headings * 0.5 ) {
            $gaps[] = [
                'category'        => 'comprehensiveness',
                'title'           => '見出し構成が不足',
                'detail'          => "競合平均: " . number_format( $avg_headings, 0 ) . " 見出し / 自社: {$self_headings} 見出し。見出しが多いページはトピックの網羅性が高く、AIO に引用されやすくなります。",
                'priority'        => $self_headings < $avg_headings * 0.3 ? 'high' : 'medium',
                'competitor_rate' => 100,
                'self_has'        => false,
            ];
        }

        if ( $avg_comp_depth >= 3 && $self_depth < $avg_comp_depth ) {
            $gaps[] = [
                'category'        => 'comprehensiveness',
                'title'           => '見出し階層が浅い',
                'detail'          => "競合は平均 H{$avg_comp_depth} まで使用していますが、自社は H{$self_depth} まで。より深い階層で情報を整理すると、AIO に構造的に理解されやすくなります。",
                'priority'        => 'low',
                'competitor_rate' => 100,
                'self_has'        => false,
            ];
        }

        // --- 信頼性（E-E-A-T） ---
        $eeat_count = $this->count_feature( $competitors, 'has_eeat' );
        $eeat_rate  = $this->calc_rate( $eeat_count, $comp_count );
        $self_eeat  = $self_analysis['has_eeat'] ?? false;
        if ( $eeat_rate >= 40 && ! $self_eeat ) {
            $gaps[] = [
                'category'        => 'trust_signals',
                'title'           => '信頼性情報（E-E-A-T）が不足',
                'detail'          => "競合 {$comp_count} サイト中 {$eeat_count} サイトが著者情報・実績・監修者情報を掲載しています。E-E-A-T の強化は AIO 掲載の信頼性判定に影響します。",
                'priority'        => $this->determine_priority( $eeat_rate, $self_eeat ),
                'competitor_rate' => $eeat_rate,
                'self_has'        => $self_eeat,
            ];
        }

        // --- 構造化コンテンツ（リスト） ---
        $comp_lists      = array_column( $competitors, 'list_count' );
        $avg_comp_lists   = $comp_count > 0 ? round( array_sum( $comp_lists ) / $comp_count, 1 ) : 0;
        $self_list_count  = $self_analysis['list_count'] ?? 0;

        $stats['avg_competitor_list_count'] = $avg_comp_lists;
        $stats['self_list_count']           = $self_list_count;

        if ( $avg_comp_lists >= 3 && $self_list_count < 2 ) {
            $gaps[] = [
                'category'        => 'structured_content',
                'title'           => 'リスト構造が少ない',
                'detail'          => "競合は平均 " . number_format( $avg_comp_lists, 0 ) . " 個のリスト（箇条書き）を使用していますが、自社は {$self_list_count} 個です。リスト形式は AIO に抽出されやすい構造です。",
                'priority'        => $self_list_count === 0 ? 'medium' : 'low',
                'competitor_rate' => 100,
                'self_has'        => $self_list_count >= 2,
            ];
        }

        // --- 最新性 ---
        $date_count = $this->count_feature( $competitors, 'has_updated_date' );
        $date_rate  = $this->calc_rate( $date_count, $comp_count );
        $self_date  = $self_analysis['has_updated_date'] ?? false;
        if ( $date_rate >= 50 && ! $self_date ) {
            $gaps[] = [
                'category'        => 'freshness',
                'title'           => '更新日・公開日の表示がない',
                'detail'          => "競合 {$comp_count} サイト中 {$date_count} サイトが更新日を表示しています。最新の情報であることを示すことで、AIO の引用対象として選ばれやすくなります。",
                'priority'        => $this->determine_priority( $date_rate, $self_date ),
                'competitor_rate' => $date_rate,
                'self_has'        => $self_date,
            ];
        }

        // --- 自社ページ未特定 ---
        if ( $self_analysis === null ) {
            array_unshift( $gaps, [
                'category'        => 'content_structure',
                'title'           => '対象ページが未特定',
                'detail'          => "「{$keyword}」に対応する自社ページが特定できませんでした。このキーワードに対応する専用ページの作成を検討してください。",
                'priority'        => 'high',
                'competitor_rate' => 100,
                'self_has'        => false,
            ] );
        }

        // 優先度順ソート
        usort( $gaps, function ( $a, $b ) {
            $order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
            return ( $order[ $a['priority'] ] ?? 9 ) <=> ( $order[ $b['priority'] ] ?? 9 );
        } );

        return [
            'gaps'    => $gaps,
            'stats'   => $stats,
            'keyword' => $keyword,
        ];
    }

    // =========================================================
    // 全キーワード集約
    // =========================================================

    /**
     * 全キーワードの差分を集約し、重複を統合して重要度でソート
     */
    public function aggregate_all_keywords( array $keyword_gap_results ): array {
        $merged_gaps = [];
        $all_stats   = [];

        foreach ( $keyword_gap_results as $kr ) {
            foreach ( $kr['gaps'] ?? [] as $gap ) {
                $key = $gap['category'] . '::' . $gap['title'];

                if ( ! isset( $merged_gaps[ $key ] ) ) {
                    $merged_gaps[ $key ] = $gap;
                    $merged_gaps[ $key ]['keywords']     = [];
                    $merged_gaps[ $key ]['occurrence']    = 0;
                }
                $merged_gaps[ $key ]['keywords'][]  = $kr['keyword'] ?? '';
                $merged_gaps[ $key ]['occurrence']++;

                // 最高優先度を採用
                $order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
                if ( ( $order[ $gap['priority'] ] ?? 9 ) < ( $order[ $merged_gaps[ $key ]['priority'] ] ?? 9 ) ) {
                    $merged_gaps[ $key ]['priority'] = $gap['priority'];
                }
            }

            if ( ! empty( $kr['stats'] ) ) {
                $all_stats[] = $kr['stats'];
            }
        }

        // 出現回数が多い順 → 優先度順でソート
        $gaps_list = array_values( $merged_gaps );
        usort( $gaps_list, function ( $a, $b ) {
            $order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
            $pa = $order[ $a['priority'] ] ?? 9;
            $pb = $order[ $b['priority'] ] ?? 9;
            if ( $pa !== $pb ) { return $pa <=> $pb; }
            return ( $b['occurrence'] ?? 0 ) <=> ( $a['occurrence'] ?? 0 );
        } );

        // 統計の平均を算出
        $avg_stats = $this->average_stats( $all_stats );

        return [
            'gaps'      => $gaps_list,
            'stats'     => $avg_stats,
            'categories' => self::CATEGORIES,
        ];
    }

    // =========================================================
    // ヘルパー
    // =========================================================

    /**
     * 競合の中で特定の特徴を持つ数をカウント
     */
    private function count_feature( array $competitors, string $key ): int {
        $count = 0;
        foreach ( $competitors as $c ) {
            if ( ! empty( $c[ $key ] ) ) { $count++; }
        }
        return $count;
    }

    /**
     * 割合を計算
     */
    private function calc_rate( int $count, int $total ): int {
        return $total > 0 ? (int) round( ( $count / $total ) * 100 ) : 0;
    }

    /**
     * 優先度判定
     */
    public function determine_priority( int $competitor_rate, bool $self_has ): string {
        if ( $self_has ) { return 'low'; }
        if ( $competitor_rate >= 80 ) { return 'high'; }
        if ( $competitor_rate >= 50 ) { return 'medium'; }
        return 'low';
    }

    /**
     * 統計値の平均を算出
     */
    private function average_stats( array $all_stats ): array {
        if ( empty( $all_stats ) ) { return []; }

        $keys   = array_keys( $all_stats[0] );
        $result = [];
        foreach ( $keys as $key ) {
            $values = array_column( $all_stats, $key );
            $values = array_filter( $values, function ( $v ) { return is_numeric( $v ); } );
            $result[ $key ] = count( $values ) > 0 ? round( array_sum( $values ) / count( $values ), 1 ) : 0;
        }
        return $result;
    }
}
