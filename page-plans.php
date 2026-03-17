<?php
/*
Template Name: プラン紹介
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'プラン紹介' );
set_query_var( 'gcrev_page_subtitle', '3つのプランから、自社に合った使い方を選べます。' );
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
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
    margin-bottom: 48px;
}

/* --- カード基本 --- */
.plan-card {
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 12px;
    padding: 32px 24px 28px;
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
    border: 2px solid #4ECDC4;
    box-shadow: 0 4px 20px rgba(78,205,196,0.10);
}
.plan-card__badge {
    position: absolute;
    top: -14px;
    left: 50%;
    transform: translateX(-50%);
    background: #4ECDC4;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 16px;
    border-radius: 20px;
    white-space: nowrap;
    letter-spacing: 0.05em;
}

/* --- カードヘッダー --- */
.plan-card__header {
    text-align: center;
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid #f0f0f0;
}
.plan-card__label {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.05em;
    margin-bottom: 4px;
}
.plan-card__label--basic      { color: #888; }
.plan-card__label--ai_support { color: #4ECDC4; }
.plan-card__label--bansou     { color: #9333ea; }

.plan-card__name {
    font-size: 18px;
    font-weight: 700;
    color: #333;
    margin-bottom: 12px;
}
.plan-card__price {
    display: flex;
    align-items: baseline;
    justify-content: center;
    gap: 2px;
}
.plan-card__price-amount {
    font-size: 36px;
    font-weight: 800;
    color: #333;
    line-height: 1;
}
.plan-card__price-unit {
    font-size: 13px;
    color: #888;
    font-weight: 500;
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
    padding: 8px 0;
    font-size: 14px;
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
.plan-feature-icon {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    margin-top: 1px;
}
.plan-feature-icon--check { color: #4ECDC4; }
.plan-feature-icon--dash  { color: #ddd; }

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
}
.plan-card__cta--primary {
    background: #4ECDC4;
    color: #fff;
}
.plan-card__cta--primary:hover {
    background: #3dbdb5;
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
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}
.plans-guide__item {
    text-align: center;
    padding: 16px;
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
    font-size: 12px;
    font-weight: 600;
    padding: 2px 10px;
    border-radius: 10px;
}
.plans-guide__item-plan--basic      { color: #888; background: #f0f0f0; }
.plans-guide__item-plan--ai_support { color: #4ECDC4; background: rgba(78,205,196,0.08); }
.plans-guide__item-plan--bansou     { color: #9333ea; background: rgba(147,51,234,0.08); }

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
@media (max-width: 1024px) {
    .plans-grid {
        grid-template-columns: 1fr;
        max-width: 440px;
        margin-left: auto;
        margin-right: auto;
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
        padding: 28px 20px 24px;
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
        <h2 class="plans-lead-heading">見える化から改善提案、伴走支援まで。<br>必要に応じて選べる3つのプラン</h2>
        <p class="plans-lead-sub">すべてのプランにサイト保守・管理が含まれています。</p>
    </div>

    <?php
    // 現在のユーザーのティア
    $current_tier = function_exists( 'gcrev_get_service_tier' )
        ? gcrev_get_service_tier( get_current_user_id() )
        : 'basic';

    // プラン定義
    $plans = [
        [
            'id'       => 'basic',
            'label'    => '見える化中心',
            'name'     => 'ベーシックプラン',
            'price'    => '5,500',
            'badge'    => false,
            'features' => [
                [ 'text' => '基本保守・管理',             'on' => true ],
                [ 'text' => 'サイト健康スコア',           'on' => true ],
                [ 'text' => 'アクセスダッシュボード',     'on' => true ],
                [ 'text' => '月次レポート総評',           'on' => true ],
                [ 'text' => 'AI改善提案・アクション提示', 'on' => false ],
                [ 'text' => 'AIチャット相談',             'on' => false ],
                [ 'text' => 'MEO専用ダッシュボード',      'on' => false ],
                [ 'text' => '口コミアンケート機能',       'on' => false ],
                [ 'text' => '投稿機能',                   'on' => false ],
                [ 'text' => '専門スタッフ伴走支援',       'on' => false ],
            ],
        ],
        [
            'id'       => 'ai_support',
            'label'    => '改善提案まで',
            'name'     => 'AIサポートプラン',
            'price'    => '11,000',
            'badge'    => '★ おすすめ',
            'features' => [
                [ 'text' => '基本保守・管理',             'on' => true ],
                [ 'text' => 'サイト健康スコア',           'on' => true ],
                [ 'text' => 'アクセスダッシュボード',     'on' => true ],
                [ 'text' => '詳細レポート閲覧',           'on' => true ],
                [ 'text' => 'AI改善提案・アクション提示', 'on' => true ],
                [ 'text' => 'AIチャット相談',             'on' => true ],
                [ 'text' => 'MEO専用ダッシュボード',      'on' => true, 'note' => 'Googleビジネスプロフィールの状況確認' ],
                [ 'text' => '口コミアンケート機能',       'on' => true, 'note' => 'お客様の声を集めて口コミ活用' ],
                [ 'text' => '投稿機能',                   'on' => true, 'note' => '最新情報やお知らせの発信' ],
                [ 'text' => '専門スタッフ伴走支援・MTG',  'on' => false ],
            ],
        ],
        [
            'id'       => 'bansou',
            'label'    => '人による継続支援',
            'name'     => '伴走プラン',
            'price'    => '33,000',
            'badge'    => false,
            'features' => [
                [ 'text' => '基本保守・管理',             'on' => true ],
                [ 'text' => 'サイト健康スコア',           'on' => true ],
                [ 'text' => 'アクセスダッシュボード',     'on' => true ],
                [ 'text' => '詳細レポート閲覧',           'on' => true ],
                [ 'text' => 'AI改善提案・アクション提示', 'on' => true ],
                [ 'text' => 'AIチャット相談',             'on' => true ],
                [ 'text' => 'MEO専用ダッシュボード',      'on' => true, 'note' => 'Googleビジネスプロフィールの状況確認' ],
                [ 'text' => '口コミアンケート機能',       'on' => true, 'note' => 'お客様の声を集めて口コミ活用' ],
                [ 'text' => '投稿機能',                   'on' => true, 'note' => '最新情報やお知らせの発信' ],
                [ 'text' => '専門スタッフ伴走支援・MTG',  'on' => true ],
            ],
        ],
    ];

    $check_svg = '<svg class="plan-feature-icon plan-feature-icon--check" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 10.5l4 4 8-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $dash_svg  = '<svg class="plan-feature-icon plan-feature-icon--dash" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 10h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    ?>

    <!-- プランカード -->
    <div class="plans-grid">
        <?php foreach ( $plans as $plan ) :
            $is_rec     = ( $plan['id'] === 'ai_support' );
            $is_current = ( $plan['id'] === $current_tier );
        ?>
        <div class="plan-card<?php echo $is_rec ? ' plan-card--recommended' : ''; ?>">
            <?php if ( $plan['badge'] ) : ?>
                <span class="plan-card__badge"><?php echo esc_html( $plan['badge'] ); ?></span>
            <?php endif; ?>

            <div class="plan-card__header">
                <div class="plan-card__label plan-card__label--<?php echo esc_attr( $plan['id'] ); ?>">
                    <?php echo esc_html( $plan['label'] ); ?>
                </div>
                <div class="plan-card__name"><?php echo esc_html( $plan['name'] ); ?></div>
                <div class="plan-card__price">
                    <span class="plan-card__price-amount"><?php echo esc_html( $plan['price'] ); ?></span>
                    <span class="plan-card__price-unit">円 / 月（税込）</span>
                </div>
            </div>

            <ul class="plan-card__features">
                <?php foreach ( $plan['features'] as $feat ) : ?>
                <li<?php echo ! $feat['on'] ? ' class="is-disabled"' : ''; ?>>
                    <?php echo $feat['on'] ? $check_svg : $dash_svg; ?>
                    <span class="plan-feature-text">
                        <?php echo esc_html( $feat['text'] ); ?>
                        <?php if ( ! empty( $feat['note'] ) ) : ?>
                            <span class="plan-feature-note"><?php echo esc_html( $feat['note'] ); ?></span>
                        <?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ( $is_current ) : ?>
                <span class="plan-card__cta plan-card__cta--current">現在のプラン</span>
            <?php elseif ( $is_rec ) : ?>
                <a href="<?php echo esc_url( home_url( '/inquiry/' ) ); ?>" class="plan-card__cta plan-card__cta--primary">プラン変更を相談する</a>
            <?php else : ?>
                <a href="<?php echo esc_url( home_url( '/inquiry/' ) ); ?>" class="plan-card__cta plan-card__cta--secondary">プラン変更を相談する</a>
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
                <div class="plans-guide__item-heading">まずは現状を把握したい</div>
                <p class="plans-guide__item-text">アクセス数やサイトの状態を<br>定期的にチェックしたい方に</p>
                <span class="plans-guide__item-plan plans-guide__item-plan--basic">ベーシック</span>
            </div>
            <div class="plans-guide__item">
                <div class="plans-guide__item-icon">💡</div>
                <div class="plans-guide__item-heading">改善のヒントもほしい</div>
                <p class="plans-guide__item-text">データを見るだけでなく<br>AIの提案で次のアクションを見つけたい方に</p>
                <span class="plans-guide__item-plan plans-guide__item-plan--ai_support">AIサポート</span>
            </div>
            <div class="plans-guide__item">
                <div class="plans-guide__item-icon">🤝</div>
                <div class="plans-guide__item-heading">専門家と一緒に進めたい</div>
                <p class="plans-guide__item-text">定期的な打ち合わせで<br>専門スタッフと一緒に改善を進めたい方に</p>
                <span class="plans-guide__item-plan plans-guide__item-plan--bansou">伴走</span>
            </div>
        </div>
    </div>

    <!-- お問い合わせ -->
    <div class="plans-contact">
        <p class="plans-contact__text">プラン変更やご不明な点がございましたら、お気軽にお問い合わせください。</p>
        <a href="<?php echo esc_url( home_url( '/inquiry/' ) ); ?>" class="plans-contact__link">お問い合わせ</a>
    </div>

</div>

<?php get_footer(); ?>
