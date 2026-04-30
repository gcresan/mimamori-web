<?php
/*
Template Name: 戦略レポート（詳細版）
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( wp_login_url( home_url( '/strategy-report-detail/' ) ) );
    exit;
}

if ( class_exists( 'Gcrev_Manual_Strategy_Report_Page' )
    && Gcrev_Manual_Strategy_Report_Page::serve_for_current_user( 'detail' ) ) {
    exit;
}

// 詳細版が設定されていない場合のフォールバック表示
get_header();
?>
<div class="content-area" style="max-width:720px;margin:48px auto;padding:0 24px;">
    <h1 style="font-size:22px;margin-bottom:12px;">📊 詳細レポートは未設定です</h1>
    <p style="color:#666;line-height:1.8;">このアカウント向けの詳細レポートはまだアップロードされていません。担当者にご連絡ください。</p>
    <p style="margin-top:24px;">
        <a class="ss-btn ss-btn--primary" href="<?php echo esc_url( home_url( '/strategy-report/' ) ); ?>">← 戦略レポートに戻る</a>
    </p>
</div>
<?php get_footer(); ?>
