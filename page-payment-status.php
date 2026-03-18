<?php
/**
 * Template Name: 決済ステータス
 *
 * お試し期間終了後 or 未決済ユーザー向けのご利用案内ページ。
 * シンプルな2ステップ表示でプラン紹介へ誘導する。
 *
 * @package Mimamori_Web
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url('/login/') );
    exit;
}

$uid = get_current_user_id();

// お試し中（期限内）or 支払い済み → ダッシュボードへ
if ( gcrev_is_trial_active( $uid ) || gcrev_is_payment_active( $uid ) ) {
    wp_safe_redirect( home_url('/dashboard/') );
    exit;
}

// お試し期限切れかどうか
$trial_expired = gcrev_is_trial_expired( $uid );

// --------------------------------------------------
// ページタイトル・パンくず
// --------------------------------------------------
set_query_var( 'gcrev_page_title', 'ご利用のご案内' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'ご利用のご案内' ) );

get_header();
?>

<style>
/* page-payment-status — Page-specific overrides only */
.ps-container { max-width: 640px; margin: 0 auto; padding: 40px 20px; }
.ps-card {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: 10px;
    padding: 32px;
    margin-bottom: 24px;
}
.ps-status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 16px; border-radius: 20px;
    font-size: 13px; font-weight: 700; margin-bottom: 20px;
}
.ps-status-badge--expired { background: rgba(229,62,62,0.08); color: #e53e3e; }
.ps-status-badge--pending { background: rgba(157,166,174,0.12); color: var(--mw-primary-blue); }
.ps-title { font-size: 22px; font-weight: 600; color: var(--mw-text-primary); margin: 0 0 12px; }
.ps-desc  { font-size: 15px; color: var(--mw-text-secondary); line-height: 1.7; margin: 0 0 24px; }
.ps-step-list { list-style: none; padding: 0; margin: 0 0 28px; }
.ps-step-list li {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 0; border-bottom: 1px solid var(--mw-border-light);
    font-size: 15px; color: var(--mw-text-secondary);
}
.ps-step-list li:last-child { border-bottom: none; }
.ps-step-icon {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.ps-step-icon--current { background: rgba(157,166,174,0.12); color: var(--mw-primary-blue); }
.ps-step-icon--future  { background: var(--mw-bg-tertiary); color: var(--mw-text-tertiary); }
.ps-btn {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    width: 100%; padding: 16px 24px; border: none; border-radius: 10px;
    font-size: 17px; font-weight: 700; text-decoration: none; text-align: center;
    cursor: pointer; transition: all 0.2s;
}
.ps-btn--primary { background: var(--mw-primary-blue); color: #fff; box-shadow: var(--mw-shadow-card); }
.ps-btn--primary:hover { background: var(--mw-primary-teal); color: #fff; }
</style>

<div class="content-area">
<div class="ps-container">

    <div class="ps-card">
        <?php if ( $trial_expired ): ?>
        <span class="ps-status-badge ps-status-badge--expired">&#x23F3; お試し期間が終了しました</span>
        <h2 class="ps-title">引き続きご利用いただくには</h2>
        <p class="ps-desc">
            お試し期間をご利用いただきありがとうございました。<br>
            みまもりウェブ を引き続きご利用いただくには、プランをお選びのうえお申し込みください。
        </p>
        <?php else: ?>
        <span class="ps-status-badge ps-status-badge--pending">&#x23F3; お手続き待ち</span>
        <h2 class="ps-title">ご利用開始までの流れ</h2>
        <p class="ps-desc">
            みまもりウェブ のご利用を開始するには、以下の手順でお手続きをお願いいたします。
        </p>
        <?php endif; ?>

        <ol class="ps-step-list">
            <li>
                <span class="ps-step-icon ps-step-icon--current">1</span>
                <span><strong>プランを選んでお申し込み</strong></span>
            </li>
            <li>
                <span class="ps-step-icon ps-step-icon--future">2</span>
                <span>みまもりウェブ 利用開始</span>
            </li>
        </ol>

        <a href="<?php echo esc_url( home_url('/plans/') ); ?>"
           class="ps-btn ps-btn--primary">
            プラン・料金を見る &#x2192;
        </a>
    </div>

</div><!-- .ps-container -->
</div><!-- .content-area -->

<?php get_footer(); ?>
