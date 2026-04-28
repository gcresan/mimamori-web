<?php
// FILE: inc/gcrev-api/modules/class-strategy-report-renderer.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Strategy_Report_Renderer' ) ) { return; }

/**
 * Gcrev_Strategy_Report_Renderer
 *
 * Validator 正規化済みの戦略レポートJSONを、ユーザーに見せる HTML に変換する。
 * 出力 HTML は page-strategy-report.php や管理画面プレビューでそのまま表示できる。
 *
 * クラス名前空間: 出力 HTML には "sr-*" (Strategy Report) プレフィックス。
 *
 * @package Mimamori_Web
 * @since   3.5.0
 */
class Gcrev_Strategy_Report_Renderer {

    private const SEVERITY_LABEL = [ 'high' => '高', 'mid' => '中', 'low' => '低' ];
    private const HORIZON_LABEL  = [
        'this_month' => '今月',
        'next_month' => '来月',
        'quarter'    => '今四半期',
    ];

    /**
     * @param array $report_json Validator 正規化済み戦略レポートJSON
     * @param array $strategy    関連付けられた戦略 hydrated row（version 等の表示用に使う）
     * @param array $aggregated  Aggregator::collect() の結果（期間表示等）
     */
    public function render( array $report_json, array $strategy = [], array $aggregated = [] ): string {
        $sec   = $report_json['sections'] ?? [];
        $score = max( 0, min( 100, (int) ( $report_json['alignment_score'] ?? 0 ) ) );

        $strategy_label = '';
        if ( $strategy ) {
            $version = isset( $strategy['version'] ) ? (int) $strategy['version'] : 0;
            $eff     = (string) ( $strategy['effective_from'] ?? '' );
            if ( $version > 0 && $eff !== '' ) {
                $strategy_label = sprintf( 'v%d（%s〜）', $version, $eff );
            }
        }

        $period_label = '';
        $year_month   = (string) ( $aggregated['period']['year_month'] ?? '' );
        if ( preg_match( '/^(\d{4})-(\d{2})$/', $year_month, $m ) ) {
            $period_label = sprintf( '%d年%d月', (int) $m[1], (int) $m[2] );
        }

        ob_start();
        ?>
        <article class="sr-report">
            <header class="sr-report__head">
                <h2 class="sr-report__title">🧠 戦略レポート<?php if ( $period_label !== '' ) : ?> — <?php echo esc_html( $period_label ); ?><?php endif; ?></h2>
                <?php if ( $strategy_label !== '' ) : ?>
                    <p class="sr-report__strategy">対象戦略: <?php echo esc_html( $strategy_label ); ?></p>
                <?php endif; ?>
            </header>

            <section class="sr-card sr-score">
                <div class="sr-score__label">戦略整合度スコア</div>
                <div class="sr-score__bar"><div class="sr-score__fill" style="width: <?php echo esc_attr( (string) $score ); ?>%"></div></div>
                <div class="sr-score__num"><?php echo esc_html( (string) $score ); ?> <span>/ 100</span></div>
            </section>

            <?php if ( ! empty( $sec['conclusion'] ) ) : ?>
                <section class="sr-card sr-conclusion">
                    <h3 class="sr-card__title">📌 今月の結論</h3>
                    <p class="sr-conclusion__text"><?php echo $this->paragraphify( (string) $sec['conclusion'] ); ?></p>
                </section>
            <?php endif; ?>

            <?php if ( ! empty( $sec['alignment'] ) ) : ?>
                <section class="sr-card sr-alignment">
                    <h3 class="sr-card__title">⚖️ 戦略とのズレ</h3>
                    <ul class="sr-alignment__list">
                        <?php foreach ( $sec['alignment'] as $a ) : ?>
                            <li class="sr-alignment__item">
                                <strong class="sr-alignment__topic"><?php echo esc_html( (string) ( $a['topic'] ?? '' ) ); ?></strong>
                                <div class="sr-alignment__row"><span class="sr-alignment__lbl">期待</span><span><?php echo esc_html( (string) ( $a['expected'] ?? '' ) ); ?></span></div>
                                <div class="sr-alignment__row"><span class="sr-alignment__lbl">実態</span><span><?php echo esc_html( (string) ( $a['actual'] ?? '' ) ); ?></span></div>
                                <div class="sr-alignment__row sr-alignment__row--gap"><span class="sr-alignment__lbl">ギャップ</span><span><?php echo esc_html( (string) ( $a['gap'] ?? '' ) ); ?></span></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ( ! empty( $sec['issues'] ) ) : ?>
                <section class="sr-card sr-issues">
                    <h3 class="sr-card__title">🚨 問題点</h3>
                    <ol class="sr-issues__list">
                        <?php foreach ( $sec['issues'] as $idx => $is ) :
                            $sev = (string) ( $is['severity'] ?? 'mid' );
                            $sev_label = self::SEVERITY_LABEL[ $sev ] ?? $sev;
                        ?>
                            <li class="sr-issues__item">
                                <div class="sr-issues__head">
                                    <span class="sr-badge sr-badge--<?php echo esc_attr( $sev ); ?>">深刻度<?php echo esc_html( $sev_label ); ?></span>
                                    <strong class="sr-issues__title"><?php echo esc_html( (string) ( $is['title'] ?? '' ) ); ?></strong>
                                </div>
                                <p class="sr-issues__evidence"><?php echo esc_html( (string) ( $is['evidence'] ?? '' ) ); ?></p>

                                <?php
                                $causes = [];
                                foreach ( (array) ( $sec['causes'] ?? [] ) as $c ) {
                                    if ( (int) ( $c['issue_ref'] ?? -1 ) === $idx ) {
                                        $causes[] = (string) ( $c['cause'] ?? '' );
                                    }
                                }
                                if ( $causes ) : ?>
                                    <div class="sr-issues__cause">
                                        <span class="sr-issues__cause-lbl">推定原因</span>
                                        <ul>
                                            <?php foreach ( $causes as $c ) : ?>
                                                <li><?php echo esc_html( $c ); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>

            <?php if ( ! empty( $sec['actions'] ) ) : ?>
                <section class="sr-card sr-actions">
                    <h3 class="sr-card__title">💡 改善アクション</h3>
                    <ul class="sr-actions__list">
                        <?php foreach ( $sec['actions'] as $a ) :
                            $h = (string) ( $a['horizon'] ?? 'this_month' );
                            $h_label = self::HORIZON_LABEL[ $h ] ?? $h;
                        ?>
                            <li class="sr-actions__item">
                                <div class="sr-actions__head">
                                    <span class="sr-tag sr-tag--<?php echo esc_attr( $h ); ?>"><?php echo esc_html( $h_label ); ?></span>
                                    <strong class="sr-actions__title"><?php echo esc_html( (string) ( $a['title'] ?? '' ) ); ?></strong>
                                </div>
                                <div class="sr-actions__meta">
                                    <span>👤 <?php echo esc_html( (string) ( $a['owner'] ?? '—' ) ); ?></span>
                                    <span>🎯 <?php echo esc_html( (string) ( $a['kpi'] ?? '—' ) ); ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ( ! empty( $sec['this_month_todos'] ) ) : ?>
                <section class="sr-card sr-todos">
                    <h3 class="sr-card__title">✅ 今月やるべきこと</h3>
                    <ul class="sr-todos__list">
                        <?php foreach ( $sec['this_month_todos'] as $t ) : ?>
                            <li class="sr-todos__item">
                                <span class="sr-todos__check">□</span>
                                <span class="sr-todos__title"><?php echo esc_html( (string) ( $t['title'] ?? '' ) ); ?></span>
                                <?php if ( ! empty( $t['due_date'] ) ) : ?>
                                    <span class="sr-todos__due">期日 <?php echo esc_html( (string) $t['due_date'] ); ?></span>
                                <?php endif; ?>
                                <?php if ( ! empty( $t['kpi'] ) ) : ?>
                                    <span class="sr-todos__kpi">🎯 <?php echo esc_html( (string) $t['kpi'] ); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private function paragraphify( string $text ): string {
        $parts = preg_split( '/\n{2,}/u', trim( $text ) );
        if ( ! is_array( $parts ) ) {
            return esc_html( $text );
        }
        $out = [];
        foreach ( $parts as $p ) {
            $out[] = nl2br( esc_html( trim( $p ) ) );
        }
        return implode( '<br><br>', $out );
    }
}
