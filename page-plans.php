<?php
/*
Template Name: プラン紹介
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'プラン紹介' );
set_query_var( 'gcrev_page_subtitle', '目的に合わせて選べる4つのプラン。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'プラン紹介' ) );

get_header();
?>

<style>
/* ============================================
   プラン紹介ページ
   ============================================ */
.plans-lead {
    text-align: center;
    margin-bottom: 40px;
}
.plans-lead-heading {
    font-size: 22px;
    font-weight: 700;
    color: #333;
    margin-bottom: 8px;
    line-height: 1.5;
}
.plans-lead-sub {
    font-size: 14px;
    color: #888;
    line-height: 1.6;
}

/* --- プランカード並び --- */
.plans-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 48px;
}

/* --- カード基本 --- */
.plan-card {
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 12px;
    padding: 32px 20px 28px;
    display: flex;
    flex-direction: column;
    position: relative;
    transition: box-shadow 0.2s;
}
.plan-card:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}

/* --- おすすめカード --- */
.plan-card--recommended {
    border: 2px solid #C0392B;
    box-shadow: 0 4px 20px rgba(192,57,43,0.10);
}
.plan-card--recommended .plan-card__price-amount {
    color: #C0392B;
}
.plan-card__badge {
    position: absolute;
    top: -14px;
    left: 50%;
    transform: translateX(-50%);
    background: #C0392B;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 16px;
    border-radius: 20px;
    white-space: nowrap;
    letter-spacing: 0.05em;
}
/* 近日公開予定バッジ */
.plan-card__badge--coming-soon {
    background: #F59E0B;
}

/* --- 近日公開予定カード --- */
.plan-card--coming-soon {
    border: 1px dashed #E5B85C;
    background: #FFFDF5;
}
.plan-card--coming-soon:hover {
    box-shadow: 0 4px 20px rgba(245,158,11,0.08);
}

/* --- カードヘッダー --- */
.plan-card__header {
    text-align: center;
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid #f0f0f0;
}
.plan-card__name {
    font-size: 17px;
    font-weight: 700;
    color: #333;
    margin-bottom: 8px;
}
.plan-card__subtitle {
    font-size: 12px;
    color: #888;
    line-height: 1.6;
    margin-bottom: 14px;
}
.plan-card__price {
    display: flex;
    align-items: baseline;
    justify-content: center;
    gap: 2px;
}
.plan-card__price-amount {
    font-size: 34px;
    font-weight: 800;
    color: #333;
    line-height: 1;
}
.plan-card__price-unit {
    font-size: 13px;
    color: #888;
    font-weight: 500;
}

/* --- 上位プランへの積み上げラベル --- */
.plan-card__cumulative {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #C0392B;
    font-size: 13px;
    font-weight: 700;
    margin: 0 0 10px;
    padding-bottom: 2px;
}
.plan-card__cumulative::before {
    content: "+";
    font-weight: 800;
    font-size: 15px;
}

/* --- 機能リスト --- */
.plan-card__features {
    list-style: none;
    padding: 0;
    margin: 0 0 24px;
    flex-grow: 1;
}
.plan-card__features li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 7px 0;
    font-size: 13px;
    line-height: 1.5;
    color: #444;
    border-bottom: 1px solid #f8f8f8;
}
.plan-card__features li:last-child {
    border-bottom: none;
}
.plan-card__features li.is-disabled {
    color: #ccc;
}
/* 新機能ハイライト */
.plan-card__features li.is-new {
    color: #333;
    font-weight: 600;
}
.plan-feature-icon {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    margin-top: 1px;
}
.plan-feature-icon--check { color: #C0392B; }
.plan-feature-icon--dash  { color: #ddd; }
.plan-feature-icon--new   { color: #C0392B; }

.plan-feature-text {
    flex: 1;
}
.plan-feature-note {
    display: block;
    font-size: 11px;
    color: #aaa;
    margin-top: 2px;
    line-height: 1.4;
}
.plan-feature-new-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    color: #C0392B;
    margin-left: 4px;
}

/* --- 機能区切りラベル --- */
.plan-card__features li.feature-divider {
    border-bottom: none;
    padding: 10px 0 4px;
    font-size: 11px;
    font-weight: 700;
    color: #C0392B;
    letter-spacing: 0.05em;
}
.plan-card__features li.feature-divider .plan-feature-icon { display: none; }

/* --- CTA ボタン --- */
.plan-card__cta {
    display: block;
    width: 100%;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.2s, opacity 0.2s;
    border: none;
    box-sizing: border-box;
}
.plan-card__cta--primary {
    background: #C0392B;
    color: #fff;
}
.plan-card__cta--primary:hover {
    background: #a4301f;
    color: #fff;
}
.plan-card__cta--secondary {
    background: #4A5568;
    color: #fff;
    border: none;
}
.plan-card__cta--secondary:hover {
    background: #3D4B5C;
    color: #fff;
}
.plan-card__cta--current {
    background: #f0f0f0;
    color: #aaa;
    cursor: default;
    pointer-events: none;
}
.plan-card__cta--coming-soon {
    background: #FEF3C7;
    color: #B45309;
    cursor: default;
    pointer-events: none;
    font-size: 13px;
}

/* --- 選び方セクション --- */
.plans-guide {
    background: #f9f9f7;
    border-radius: 12px;
    padding: 32px;
    margin-bottom: 32px;
}
.plans-guide__title {
    font-size: 16px;
    font-weight: 700;
    color: #333;
    margin-bottom: 20px;
    text-align: center;
}
.plans-guide__list {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}
.plans-guide__item {
    text-align: center;
    padding: 16px 12px;
}
.plans-guide__item-icon {
    font-size: 28px;
    margin-bottom: 8px;
}
.plans-guide__item-heading {
    font-size: 14px;
    font-weight: 700;
    color: #444;
    margin-bottom: 6px;
}
.plans-guide__item-text {
    font-size: 13px;
    color: #888;
    line-height: 1.6;
}
.plans-guide__item-plan {
    display: inline-block;
    margin-top: 6px;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 10px;
    border-radius: 10px;
}
.plans-guide__item-plan--basic        { color: #888; background: #f0f0f0; }
.plans-guide__item-plan--ai_support   { color: #C0392B; background: rgba(192,57,43,0.08); }
.plans-guide__item-plan--content_seo  { color: #B45309; background: rgba(245,158,11,0.08); }
.plans-guide__item-plan--bansou       { color: #9333ea; background: rgba(147,51,234,0.08); }

/* --- お問い合わせ --- */
.plans-contact {
    text-align: center;
    padding: 24px;
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 12px;
    margin-bottom: 16px;
}
.plans-contact__text {
    font-size: 14px;
    color: #666;
    margin-bottom: 12px;
    line-height: 1.6;
}
.plans-contact__link {
    display: inline-block;
    padding: 10px 32px;
    background: #4A5568;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    border-radius: 8px;
    text-decoration: none;
    transition: background 0.2s;
}
.plans-contact__link:hover {
    background: #3D4B5C;
    color: #fff;
}

/* --- レスポンシブ --- */
@media (max-width: 1200px) {
    .plans-grid {
        grid-template-columns: repeat(2, 1fr);
        max-width: 720px;
        margin-left: auto;
        margin-right: auto;
    }
    .plans-guide__list {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 700px) {
    .plans-grid {
        grid-template-columns: 1fr;
        max-width: 440px;
    }
    .plan-card--recommended {
        order: -1;
    }
    .plans-guide__list {
        grid-template-columns: 1fr;
        gap: 12px;
    }
}
@media (max-width: 600px) {
    .plans-lead-heading {
        font-size: 18px;
    }
    .plan-card {
        padding: 28px 18px 24px;
    }
    .plan-card__price-amount {
        font-size: 30px;
    }
    .plans-guide {
        padding: 24px 16px;
    }
}
</style>

<div class="content-area">

    <!-- リード文 -->
    <div class="plans-lead">
        <h2 class="plans-lead-heading">見える化から改善代行まで。<br>目的に合わせて選べる4つのプラン</h2>
        <p class="plans-lead-sub">すべてのプランにサイト保守・管理が含まれています。</p>
    </div>

    <?php
    // 現在のユーザーのティア
    $current_tier = function_exists( 'gcrev_get_service_tier' )
        ? gcrev_get_service_tier( get_current_user_id() )
        : 'basic';

    // プラン定義（4プラン・累積構造）
    $plans = [
        [
            'id'           => 'basic',
            'subtitle'     => 'AIがデータを分析しサイトの状態をレポート化',
            'name'         => 'AI分析・レポートプラン',
            'price'        => '11,000',
            'badge'        => false,
            'coming_soon'  => false,
            'cumulative'   => '',
            'features'     => [
                [ 'text' => 'サイト健康スコア',           'on' => true ],
                [ 'text' => 'アクセス状況ダッシュボード', 'on' => true ],
                [ 'text' => '基本データの可視化',         'on' => true ],
                [ 'text' => '月次レポート総評',           'on' => true ],
                [ 'text' => '基本保守・管理',             'on' => true ],
                [ 'text' => 'AI改善アクション提示',       'on' => true ],
                [ 'text' => 'AIチャット相談',             'on' => true ],
            ],
        ],
        [
            'id'           => 'ai_support',
            'subtitle'     => 'MEO対策や口コミ獲得で地域集客を強化',
            'name'         => 'MEO・口コミ対策プラン',
            'price'        => '22,000',
            'badge'        => '★ おすすめ',
            'coming_soon'  => false,
            'cumulative'   => 'AI分析・レポートに加え',
            'features'     => [
                [ 'text' => 'MEOダッシュボード',          'on' => true ],
                [ 'text' => '口コミ返信機能',             'on' => true ],
                [ 'text' => '口コミアンケート＋AI機能',   'on' => true ],
                [ 'text' => 'MEO運用代行',                'on' => true ],
            ],
        ],
        [
            'id'           => 'content_seo',
            'subtitle'     => 'コンテンツSEOを強化し検索流入を拡大',
            'name'         => 'コンテンツSEO強化プラン',
            'price'        => '44,000',
            'badge'        => false,
            'coming_soon'  => false,
            'cumulative'   => 'MEO・口コミ対策に加え',
            'features'     => [
                [ 'text' => 'コンテンツSEO機能',          'on' => true ],
                [ 'text' => 'キーワード調査・競合分析',   'on' => true ],
                [ 'text' => 'コラム記事作成機能',         'on' => true ],
                [ 'text' => '一次情報ストック機能',       'on' => true ],
            ],
        ],
        [
            'id'           => 'bansou',
            'subtitle'     => 'プロが伴走し改善施策の実行まで全面サポート',
            'name'         => 'プロ伴走・改善実行プラン',
            'price'        => '110,000',
            'badge'        => false,
            'coming_soon'  => false,
            'cumulative'   => 'コンテンツSEO強化に加え',
            'features'     => [
                [ 'text' => '改善指示に基づく作業',       'on' => true ],
                [ 'text' => '専門スタッフ伴走支援',       'on' => true ],
                [ 'text' => '定例ミーティング',           'on' => true ],
            ],
        ],
    ];

    $check_svg = '<svg class="plan-feature-icon plan-feature-icon--check" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 10.5l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $dash_svg  = '<svg class="plan-feature-icon plan-feature-icon--dash" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 10h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    $new_svg   = '<svg class="plan-feature-icon plan-feature-icon--new" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    ?>

    <!-- プランカード -->
    <div class="plans-grid">
        <?php foreach ( $plans as $plan ) :
            $is_rec        = ( $plan['badge'] === '★ おすすめ' );
            $is_coming     = ! empty( $plan['coming_soon'] );
            $is_current    = ( ! $is_coming && $plan['id'] === $current_tier );

            // カードのCSSクラス
            $card_classes = 'plan-card';
            if ( $is_rec )    $card_classes .= ' plan-card--recommended';
            if ( $is_coming ) $card_classes .= ' plan-card--coming-soon';
        ?>
        <div class="<?php echo esc_attr( $card_classes ); ?>">
            <?php if ( $plan['badge'] ) : ?>
                <span class="plan-card__badge<?php echo $is_coming ? ' plan-card__badge--coming-soon' : ''; ?>"><?php echo esc_html( $plan['badge'] ); ?></span>
            <?php endif; ?>

            <div class="plan-card__header">
                <div class="plan-card__name"><?php echo esc_html( $plan['name'] ); ?></div>
                <?php if ( ! empty( $plan['subtitle'] ) ) : ?>
                    <div class="plan-card__subtitle"><?php echo esc_html( $plan['subtitle'] ); ?></div>
                <?php endif; ?>
                <div class="plan-card__price">
                    <span class="plan-card__price-amount"><?php echo esc_html( $plan['price'] ); ?></span>
                    <span class="plan-card__price-unit">円 / 月（税込）</span>
                </div>
            </div>

            <?php if ( ! empty( $plan['cumulative'] ) ) : ?>
                <div class="plan-card__cumulative"><?php echo esc_html( $plan['cumulative'] ); ?></div>
            <?php endif; ?>

            <ul class="plan-card__features">
                <?php foreach ( $plan['features'] as $feat ) :
                    $li_class = '';
                    if ( ! empty( $feat['divider'] ) ) $li_class = 'feature-divider';
                    elseif ( ! $feat['on'] )           $li_class = 'is-disabled';
                    elseif ( ! empty( $feat['new'] ) ) $li_class = 'is-new';
                ?>
                <li<?php echo $li_class ? ' class="' . esc_attr( $li_class ) . '"' : ''; ?>>
                    <?php if ( ! empty( $feat['divider'] ) ) : ?>
                        <span class="plan-feature-text"><?php echo esc_html( $feat['text'] ); ?></span>
                    <?php else : ?>
                        <?php
                        if ( ! empty( $feat['new'] ) ) {
                            echo $new_svg;
                        } elseif ( $feat['on'] ) {
                            echo $check_svg;
                        } else {
                            echo $dash_svg;
                        }
                        ?>
                        <span class="plan-feature-text">
                            <?php echo esc_html( $feat['text'] ); ?>
                            <?php if ( ! empty( $feat['new'] ) ) : ?>
                                <span class="plan-feature-new-badge">NEW</span>
                            <?php endif; ?>
                            <?php if ( ! empty( $feat['note'] ) ) : ?>
                                <span class="plan-feature-note"><?php echo esc_html( $feat['note'] ); ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ( $is_coming ) : ?>
                <span class="plan-card__cta plan-card__cta--coming-soon">近日公開予定</span>
            <?php elseif ( $is_current ) : ?>
                <span class="plan-card__cta plan-card__cta--current">現在のプラン</span>
            <?php elseif ( $is_rec ) : ?>
                <a href="<?php echo esc_url( home_url( '/inquiry/?type=change&plan=' . $plan['id'] ) ); ?>" class="plan-card__cta plan-card__cta--primary">プラン変更を相談する</a>
            <?php else : ?>
                <a href="<?php echo esc_url( home_url( '/inquiry/?type=change&plan=' . $plan['id'] ) ); ?>" class="plan-card__cta plan-card__cta--secondary">プラン変更を相談する</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 選び方ガイド -->
    <div class="plans-guide">
        <h3 class="plans-guide__title">どのプランが合っていますか？</h3>
        <div class="plans-guide__list">
            <div class="plans-guide__item">
                <div class="plans-guide__item-icon">📊</div>
                <div class="plans-guide__item-heading">まずはデータで現状把握したい</div>
                <p class="plans-guide__item-text">AIの分析と月次レポートで<br>サイトの状態を見える化したい方に</p>
                <span class="plans-guide__item-plan plans-guide__item-plan--basic">AI分析・レポート</span>
            </div>
            <div class="plans-guide__item">
                <div class="plans-guide__item-icon">📍</div>
                <div class="plans-guide__item-heading">地域集客を強化したい</div>
                <p class="plans-guide__item-text">MEO対策と口コミ獲得で<br>地元からの問い合わせを増やしたい方に</p>
                <span class="plans-guide__item-plan plans-guide__item-plan--ai_support">MEO・口コミ対策</span>
            </div>
            <div class="plans-guide__item">
                <div class="plans-guide__item-icon">🚀</div>
                <div class="plans-guide__item-heading">検索流入を拡大したい</div>
                <p class="plans-guide__item-text">SEO・記事作成・競合分析で<br>コンテンツからの集客を強化したい方に</p>
                <span class="plans-guide__item-plan plans-guide__item-plan--content_seo">コンテンツSEO強化</span>
            </div>
            <div class="plans-guide__item">
                <div class="plans-guide__item-icon">🤝</div>
                <div class="plans-guide__item-heading">改善実行まで任せたい</div>
                <p class="plans-guide__item-text">提案だけでなく改善施策の実行まで<br>プロに伴走してほしい方に</p>
                <span class="plans-guide__item-plan plans-guide__item-plan--bansou">プロ伴走・改善実行</span>
            </div>
        </div>
    </div>

    <!-- お問い合わせ -->
    <div class="plans-contact">
        <p class="plans-contact__text">プラン変更やご不明な点がございましたら、お気軽にお問い合わせください。</p>
        <a href="<?php echo esc_url( home_url( '/inquiry/?type=other' ) ); ?>" class="plans-contact__link">お問い合わせ</a>
    </div>

</div>

<?php get_footer(); ?>
