<?php
/*
Template Name: MEO診断
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'MEO診断' );
set_query_var( 'gcrev_page_subtitle', 'Googleビジネスプロフィールの状態を診断し、改善ポイントを整理します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'MEO診断', '診断レポート' ) );

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
