<?php
/*
Template Name: 使い方ガイド
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', '使い方ガイド' );
set_query_var( 'gcrev_page_subtitle', 'みまもりウェブの各機能の使い方を動画やスクリーンショットで解説します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '使い方ガイド', 'サポート・問い合わせ' ) );

get_header();
?>

<div class="content-area">
    <div style="text-align:center; padding:60px 20px;">
        <p style="font-size:48px; margin-bottom:16px;">🚧</p>
        <h2 style="font-size:20px; color:#1e293b; margin-bottom:8px;">準備中です</h2>
        <p style="font-size:14px; color:#64748b;">この機能は現在準備中です。もうしばらくお待ちください。</p>
    </div>
</div>

<?php get_footer(); ?>
