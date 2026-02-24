<?php /*Template Name: 新規登録ページ */ ?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo trim(wp_title('', false));if(wp_title('', false))echo '｜';bloginfo('name'); ?></title>
    <link rel="icon" type="image/x-icon" href="<?php echo get_template_directory_uri(); ?>/images/favicon.ico">
      <link rel="stylesheet" type="text/css" href="<?php echo get_template_directory_uri(); ?>/css/register.css" media="all">
</head>
<body class="register">
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
