<?php
// FILE: inc/gcrev-api/modules/class-aio-serp-aggregator.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'Gcrev_AIO_Serp_Aggregator' ) ) { return; }

/**
 * AIO SERP 集計・スコアリングクラス
 *
 * キーワード単位のドメイン露出度集計、全体ランキング、自社スコア算出を行う。
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Gcrev_AIO_Serp_Aggregator {

    // =========================================================
    // 定数
    // =========================================================

    /** 引用位置ごとの重み（1位=5, 2位=4, ..., 5位以降=1） */
    private const POSITION_WEIGHTS = [
        1 => 5,
        2 => 4,
        3 => 3,
        4 => 2,
    ];
    private const DEFAULT_WEIGHT = 1;

    /** 1キーワードあたりの理論最大露出度（1+2+3+4+5 = 15） */
    public const THEORETICAL_MAX_PER_KEYWORD = 15;

    // =========================================================
    // 位置重みユーティリティ
    // =========================================================

    /**
     * 引用位置から重みを返す
     */
    public static function weight_for_position( int $position ): int {
        return self::POSITION_WEIGHTS[ $position ] ?? self::DEFAULT_WEIGHT;
    }

    // =========================================================
    // キーワード単位の集計
    // =========================================================

    /**
     * 1キーワード分の引用リストからドメイン別露出度を集計
     *
     * @param array $citations パーサーが返した引用配列
     * @param array $self_domains 自社判定ドメイン（正規化済み）
     * @return array {
     *     @type array  $domains       ドメイン別集計 [ normalized_domain => {domain, site_name, count, exposure, positions} ]
     *     @type bool   $self_found    自社ドメインが含まれるか
     *     @type int    $self_count    自社ドメインの出現回数
     *     @type int    $self_exposure 自社ドメインの重み付き露出度
     * }
     */
    public function aggregate_keyword( array $citations, array $self_domains ): array {
        $domains     = [];
        $self_found  = false;
        $self_count  = 0;
        $self_exp    = 0;

        foreach ( $citations as $cite ) {
            $norm   = $cite['normalized_domain'] ?? '';
            $weight = self::weight_for_position( (int) ( $cite['position'] ?? 99 ) );

            if ( ! isset( $domains[ $norm ] ) ) {
                $domains[ $norm ] = [
                    'domain'            => $cite['domain'] ?? $norm,
                    'normalized_domain' => $norm,
                    'site_name'         => $cite['site_name'] ?? $norm,
                    'count'             => 0,
                    'exposure'          => 0,
                    'positions'         => [],
                ];
            }

            $domains[ $norm ]['count']++;
            $domains[ $norm ]['exposure'] += $weight;
            $domains[ $norm ]['positions'][] = (int) ( $cite['position'] ?? 99 );

            // サイト名の更新（空でない方を優先）
            if ( $domains[ $norm ]['site_name'] === $norm && ! empty( $cite['site_name'] ) && $cite['site_name'] !== $norm ) {
                $domains[ $norm ]['site_name'] = $cite['site_name'];
            }

            // 自社判定
            if ( in_array( $norm, $self_domains, true ) ) {
                $self_found = true;
                $self_count++;
                $self_exp += $weight;
            }
        }

        // 露出度降順でソート
        uasort( $domains, function ( $a, $b ) {
            return $b['exposure'] <=> $a['exposure'];
        } );

        return [
            'domains'       => array_values( $domains ),
            'self_found'    => $self_found,
            'self_count'    => $self_count,
            'self_exposure' => $self_exp,
        ];
    }

    // =========================================================
    // 全体集計
    // =========================================================

    /**
     * 全キーワードの結果を集計して全体ランキング + 自社スコアを算出
     *
     * @param array $keyword_results キーワード別の結果配列
     *   各要素: {
     *     keyword_id, keyword, status, citations, fetched_at,
     *     self_found, self_count, self_exposure
     *   }
     * @param array $self_domains 自社判定ドメイン（正規化済み）
     * @return array
     */
    public function aggregate_all( array $keyword_results, array $self_domains ): array {
        $global_domains     = []; // normalized_domain => { site_name, total_exposure, total_count, keywords: Set }
        $aio_keyword_count  = 0;
        $self_total_exp     = 0;
        $self_keyword_count = 0;
        $keyword_details    = [];

        foreach ( $keyword_results as $kr ) {
            if ( ( $kr['status'] ?? '' ) !== 'success' ) {
                $keyword_details[] = [
                    'keyword_id' => $kr['keyword_id'] ?? 0,
                    'keyword'    => $kr['keyword'] ?? '',
                    'status'     => $kr['status'] ?? 'unknown',
                    'domains'    => [],
                    'self_found' => false,
                    'self_rank'  => null,
                ];
                continue;
            }

            $aio_keyword_count++;
            $citations = $kr['citations'] ?? [];
            $kw_agg    = $this->aggregate_keyword( $citations, $self_domains );

            // 自社集計
            if ( $kw_agg['self_found'] ) {
                $self_keyword_count++;
                $self_total_exp += $kw_agg['self_exposure'];
            }

            // グローバルドメイン集計
            foreach ( $kw_agg['domains'] as $d ) {
                $norm = $d['normalized_domain'];
                if ( ! isset( $global_domains[ $norm ] ) ) {
                    $global_domains[ $norm ] = [
                        'domain'            => $d['domain'],
                        'normalized_domain' => $norm,
                        'site_name'         => $d['site_name'],
                        'total_exposure'    => 0,
                        'total_count'       => 0,
                        'keywords'          => [],
                    ];
                }
                $global_domains[ $norm ]['total_exposure'] += $d['exposure'];
                $global_domains[ $norm ]['total_count']    += $d['count'];
                $global_domains[ $norm ]['keywords'][]      = $kr['keyword'] ?? '';

                if ( $global_domains[ $norm ]['site_name'] === $norm && $d['site_name'] !== $norm ) {
                    $global_domains[ $norm ]['site_name'] = $d['site_name'];
                }
            }

            // 自社のKW内順位を算出
            $self_rank = null;
            $rank_pos  = 1;
            foreach ( $kw_agg['domains'] as $d ) {
                if ( in_array( $d['normalized_domain'], $self_domains, true ) ) {
                    $self_rank = $rank_pos;
                    break;
                }
                $rank_pos++;
            }

            $keyword_details[] = [
                'keyword_id'    => $kr['keyword_id'] ?? 0,
                'keyword'       => $kr['keyword'] ?? '',
                'status'        => 'success',
                'domains'       => $kw_agg['domains'],
                'self_found'    => $kw_agg['self_found'],
                'self_exposure' => $kw_agg['self_exposure'],
                'self_rank'     => $self_rank,
            ];
        }

        // グローバルランキング: 露出度降順ソート
        uasort( $global_domains, function ( $a, $b ) {
            return $b['total_exposure'] <=> $a['total_exposure'];
        } );

        // ランキング配列構築
        $rankings = [];
        $rank     = 1;
        $self_global_rank = null;

        foreach ( $global_domains as $norm => $d ) {
            $is_self        = in_array( $norm, $self_domains, true );
            $unique_kws     = array_unique( $d['keywords'] );
            $rankings[]     = [
                'rank'              => $rank,
                'domain'            => $d['domain'],
                'normalized_domain' => $norm,
                'site_name'         => $d['site_name'],
                'total_exposure'    => $d['total_exposure'],
                'total_count'       => $d['total_count'],
                'keyword_count'     => count( $unique_kws ),
                'is_self'           => $is_self,
            ];
            if ( $is_self && $self_global_rank === null ) {
                $self_global_rank = $rank;
            }
            $rank++;
        }

        // スコア計算
        $theoretical_max = $aio_keyword_count * self::THEORETICAL_MAX_PER_KEYWORD;
        $self_score      = $theoretical_max > 0
            ? round( ( $self_total_exp / $theoretical_max ) * 100, 1 )
            : 0;
        $self_coverage   = $aio_keyword_count > 0
            ? round( ( $self_keyword_count / $aio_keyword_count ) * 100, 1 )
            : 0;

        return [
            'self_score'          => $self_score,
            'self_coverage'       => $self_coverage,
            'self_total_exposure' => $self_total_exp,
            'self_keyword_count'  => $self_keyword_count,
            'aio_keyword_count'   => $aio_keyword_count,
            'total_keyword_count' => count( $keyword_results ),
            'self_rank'           => $self_global_rank,
            'rankings'            => $rankings,
            'keyword_details'     => $keyword_details,
        ];
    }

    // =========================================================
    // AIコメント用ペイロード構築
    // =========================================================

    /**
     * 集計結果から AI コメント生成用の最小限データを構築
     */
    public function build_ai_comment_payload( array $aggregated ): array {
        // 上位5ドメインのみ
        $top_domains = array_slice( $aggregated['rankings'] ?? [], 0, 5 );
        $top_simple  = array_map( function ( $d ) {
            return [
                'domain'    => $d['normalized_domain'],
                'site_name' => $d['site_name'],
                'score'     => $d['total_exposure'],
                'is_self'   => $d['is_self'] ?? false,
            ];
        }, $top_domains );

        // 自社が強い/弱いキーワード
        $strong = [];
        $weak   = [];
        foreach ( $aggregated['keyword_details'] ?? [] as $kd ) {
            if ( ( $kd['status'] ?? '' ) !== 'success' ) {
                continue;
            }
            if ( ! empty( $kd['self_found'] ) ) {
                $strong[] = $kd['keyword'];
            } else {
                $weak[] = $kd['keyword'];
            }
        }

        return [
            'self_score'         => $aggregated['self_score'] ?? 0,
            'self_coverage'      => $aggregated['self_coverage'] ?? 0,
            'aio_keyword_count'  => $aggregated['aio_keyword_count'] ?? 0,
            'self_rank'          => $aggregated['self_rank'],
            'top_domains'        => $top_simple,
            'strong_keywords'    => array_slice( $strong, 0, 5 ),
            'weak_keywords'      => array_slice( $weak, 0, 5 ),
        ];
    }
}
