<?php /*Template Name: 新規登録ページ */ ?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','GTM-M5HWSJN3');</script>
    <!-- End Google Tag Manager -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo trim(wp_title('', false));if(wp_title('', false))echo '｜';bloginfo('name'); ?></title>
    <link rel="icon" type="image/x-icon" href="<?php echo get_template_directory_uri(); ?>/images/favicon.ico">
      <link rel="stylesheet" type="text/css" href="<?php echo get_template_directory_uri(); ?>/css/register.css" media="all">
</head>
<body class="register">
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-M5HWSJN3"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
    <div class="container">
        <div class="logo">
            <img src="<?php echo get_template_directory_uri(); ?>/images/common/logo.png" alt="サービスロゴ">
        </div>
        <h1>クライアントポータル新規登録</h1>
        

        <form action="/login" method="POST">
            <div class="form-group">
                <?php echo do_shortcode('[wpmem_form register]'); ?>
            </div>
        </form>
        

        
        <div class="security-note">
            このサイトは SSL で保護されています。<br>
            ログイン情報は第三者と共有しないでください。
        </div>
    </div>
</body>
</html>
