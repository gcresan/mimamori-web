<?php
// ログイン済み → 決済ステータスに応じてリダイレクト
if ( is_user_logged_in() ) {
    if ( gcrev_is_payment_active() ) {
        wp_safe_redirect( home_url('/mypage/dashboard/') );
    } else {
        wp_safe_redirect( home_url('/payment-status/') );
    }
    exit;
}

$page_title = trim( wp_title('', false) );
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?><?php if ($page_title) echo '｜'; ?><?php bloginfo('name'); ?></title>

    <link rel="icon" type="image/x-icon" href="<?php echo esc_url( get_template_directory_uri() ); ?>/images/favicon.ico">
    <link rel="stylesheet" href="<?php echo esc_url( get_template_directory_uri() ); ?>/css/login.css" media="all">
    <?php wp_head(); ?>
</head>
<body class="login">
    <div class="login-container">
        <div class="logo">
            <img src="<?php echo esc_url( get_template_directory_uri() ); ?>/images/common/logo.png" alt="サービスロゴ">
        </div>

        <h1>クライアントポータル</h1>

        <?php echo do_shortcode('[wpmem_form login]'); ?>



        <div class="security-note">
            このサイトは SSL で保護されています。<br>
            ログイン情報は第三者と共有しないでください。
        </div>
    </div>

    <?php wp_footer(); ?>
</body>
</html>
