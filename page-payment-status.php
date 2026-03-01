<?php
/**
 * Template Name: 決済ステータス
 *
 * ログイン後の決済状況案内ページ。
 * 契約タイプ（with_site / insight_only）と決済ステップに応じて表示を切り替える。
 *
 * - with_site : 3ステップ（分割→サブスク→利用開始）
 * - insight_only : 2ステップ（サブスク→利用開始）
 *
 * ★ サブスク決済ボタンは表示しない（文言のみ）
 * ★ 分割決済ボタンは 1y/2y で URL を切り替える
 *
 * @package GCREV_INSIGHT
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url('/login/') );
    exit;
}

// 支払い済み → ダッシュボードへ
if ( gcrev_is_payment_active() ) {
    wp_safe_redirect( home_url('/mypage/dashboard/') );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;
$steps        = gcrev_get_payment_steps( $user_id );

$contract_type          = $steps['contract_type'];
$plan_term              = $steps['plan_term'];
$initial_completed      = $steps['initial_completed'];
$subscription_completed = $steps['subscription_completed'];

// 分割払い決済URL（1y/2y で切り替え）
$installment_url = gcrev_get_installment_url( $user_id );

// --------------------------------------------------
// ページタイトル・パンくず
// --------------------------------------------------
set_query_var( 'gcrev_page_title', 'お支払い手続き' );

set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'お支払い手続き', 'お申込み' ) );

get_header();
?>

<style>
/* page-payment-status — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */
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
.ps-status-badge--pending  { background: rgba(229,62,62,0.08); color: #e53e3e; }
.ps-status-badge--waiting  { background: rgba(157,166,174,0.12); color: var(--mw-primary-blue); }
.ps-status-badge--active   { background: rgba(125,181,179,0.15); color: #0d9488; }
.ps-title { font-size: 22px; font-weight: 600; color: var(--mw-text-primary); margin: 0 0 12px; }
.ps-desc  { font-size: 15px; color: var(--mw-text-secondary); line-height: 1.7; margin: 0 0 24px; }
.ps-step-list { list-style: none; padding: 0; margin: 0 0 24px; }
.ps-step-list li {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 0; border-bottom: 1px solid var(--mw-border-light);
    font-size: 15px; color: var(--mw-text-secondary);
}
.ps-step-list li:last-child { border-bottom: none; }
.ps-step-icon {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.ps-step-icon--done    { background: rgba(125,181,179,0.15); color: #0d9488; }
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
.ps-btn--success { background: var(--mw-primary-teal); color: #fff; box-shadow: var(--mw-shadow-card); }
.ps-btn--success:hover { background: var(--mw-primary-blue); color: #fff; }
.ps-note { font-size: 13px; color: var(--mw-text-tertiary); text-align: center; margin-top: 16px; line-height: 1.6; }
.ps-waiting-box {
    background: var(--mw-bg-secondary); border: 1px solid var(--mw-border-light);
    border-radius: 10px; padding: 20px 24px; text-align: center;
}
.ps-waiting-box p { margin: 0; font-size: 15px; color: var(--mw-text-secondary); line-height: 1.7; }
</style>

<div class="content-area">
<div class="ps-container">

<?php if ( $contract_type === 'with_site' ): ?>
<!-- ========================================================
     契約タイプA：制作込み（3ステップ）
     ======================================================== -->

    <?php if ( ! $initial_completed ): ?>
    <!-- (1) 初回未完了 — 分割決済ボタン表示 -->
    <div class="ps-card">
        <span class="ps-status-badge ps-status-badge--pending">&#x23F3; お支払い手続き待ち</span>
        <h2 class="ps-title">お支払い手続きをお願いします</h2>
        <p class="ps-desc">
            お申込みありがとうございます。<br>
            みまもりウェブ のご利用を開始するには、以下の手順でお支払いを完了してください。
        </p>

        <ul class="ps-step-list">
            <li>
                <span class="ps-step-icon ps-step-icon--current">1</span>
                <span><strong>分割払い（初回決済）</strong> — 下のボタンから手続きしてください</span>
            </li>
            <li>
                <span class="ps-step-icon ps-step-icon--future">2</span>
                <span>月額サブスクリプション決済</span>
            </li>
            <li>
                <span class="ps-step-icon ps-step-icon--future">3</span>
                <span>みまもりウェブ 利用開始</span>
            </li>
        </ul>

        <a href="<?php echo esc_url( home_url('/apply/with-production/') ); ?>"
           class="ps-btn ps-btn--primary">
            &#x1F4DD; 申し込みフォームへ &#x2192;
        </a>
    </div>

    <?php elseif ( $initial_completed && ! $subscription_completed ): ?>
    <!-- (2) 初回完了・サブスク未完了 — ボタンなし・文言のみ -->
    <div class="ps-card">
        <span class="ps-status-badge ps-status-badge--waiting">&#x1F504; 月額サブスクリプション決済待ち</span>
        <h2 class="ps-title">あと一歩でご利用開始です</h2>
        <p class="ps-desc">
            分割払いの確認が完了しました。<br>
            月額サブスクリプションの決済準備が整い次第、ご案内いたします。
        </p>

        <ul class="ps-step-list">
            <li>
                <span class="ps-step-icon ps-step-icon--done">&#x2713;</span>
                <span><strong>分割払い（初回決済）</strong> — 完了しました</span>
            </li>
            <li>
                <span class="ps-step-icon ps-step-icon--current">2</span>
                <span><strong>月額サブスクリプション決済</strong> — お手続き待ち</span>
            </li>
            <li>
                <span class="ps-step-icon ps-step-icon--future">3</span>
                <span>みまもりウェブ 利用開始</span>
            </li>
        </ul>

        <div class="ps-waiting-box">
            <p>
                月額サブスクリプション決済の準備が整い次第、<br>
                メールにてご案内をお送りいたします。<br>
                しばらくお待ちください。
            </p>
        </div>
    </div>

    <?php else: ?>
    <!-- (3) 全完了（通常ここに来る前にダッシュボードへリダイレクト） -->
    <div class="ps-card" style="text-align:center;">
        <span class="ps-status-badge ps-status-badge--active">&#x2705; ご利用可能</span>
        <h2 class="ps-title">すべての手続きが完了しました</h2>
        <p class="ps-desc">
            お支払いの確認が完了しました。<br>
            みまもりウェブ の全機能をご利用いただけます。
        </p>

        <ul class="ps-step-list">
            <li>
                <span class="ps-step-icon ps-step-icon--done">&#x2713;</span>
                <span><strong>分割払い（初回決済）</strong> — 完了</span>
            </li>
            <li>
                <span class="ps-step-icon ps-step-icon--done">&#x2713;</span>
                <span><strong>月額サブスクリプション決済</strong> — 完了</span>
            </li>
            <li>
                <span class="ps-step-icon ps-step-icon--done">&#x2713;</span>
                <span><strong>みまもりウェブ 利用開始</strong> — ご利用可能です</span>
            </li>
        </ul>

        <a href="<?php echo esc_url( home_url('/mypage/dashboard/') ); ?>"
           class="ps-btn ps-btn--success">
            &#x1F680; みまもりウェブ スタート &#x2192;
        </a>
    </div>
    <?php endif; ?>

<?php else: ?>
<!-- ========================================================
     契約タイプB：伴走運用のみ（2ステップ）
     ======================================================== -->

    <?php if ( ! $subscription_completed ): ?>
    <!-- (1) サブスク未完了 — ボタンなし・文言のみ -->
    <div class="ps-card">
        <span class="ps-status-badge ps-status-badge--pending">&#x23F3; 月額サブスクリプション決済待ち</span>
        <h2 class="ps-title">お支払い手続き待ちです</h2>
        <p class="ps-desc">
            お申込みありがとうございます。<br>
            月額サブスクリプションの決済準備が整い次第、ご案内いたします。
        </p>

        <ul class="ps-step-list">
            <li>
                <span class="ps-step-icon ps-step-icon--current">1</span>
                <span><strong>月額サブスクリプション決済</strong> — お手続き待ち</span>
            </li>
            <li>
                <span class="ps-step-icon ps-step-icon--future">2</span>
                <span>みまもりウェブ 利用開始</span>
            </li>
        </ul>

        <div class="ps-waiting-box">
            <p>
                月額サブスクリプション決済の準備が整い次第、<br>
                メールにてご案内をお送りいたします。<br>
                しばらくお待ちください。
            </p>
        </div>
    </div>

    <?php else: ?>
    <!-- (2) サブスク完了（通常ここに来る前にダッシュボードへリダイレクト） -->
    <div class="ps-card" style="text-align:center;">
        <span class="ps-status-badge ps-status-badge--active">&#x2705; ご利用可能</span>
        <h2 class="ps-title">すべての手続きが完了しました</h2>
        <p class="ps-desc">
            お支払いの確認が完了しました。<br>
            みまもりウェブ の全機能をご利用いただけます。
        </p>

        <ul class="ps-step-list">
            <li>
                <span class="ps-step-icon ps-step-icon--done">&#x2713;</span>
                <span><strong>月額サブスクリプション決済</strong> — 完了</span>
            </li>
            <li>
                <span class="ps-step-icon ps-step-icon--done">&#x2713;</span>
                <span><strong>みまもりウェブ 利用開始</strong> — ご利用可能です</span>
            </li>
        </ul>

        <a href="<?php echo esc_url( home_url('/mypage/dashboard/') ); ?>"
           class="ps-btn ps-btn--success">
            &#x1F680; みまもりウェブ スタート &#x2192;
        </a>
    </div>
    <?php endif; ?>

<?php endif; ?>

</div><!-- .ps-container -->
</div><!-- .content-area -->

<?php get_footer(); ?>
