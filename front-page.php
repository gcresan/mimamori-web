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
    <?php wp_head(); ?>

    <!-- ログインページ専用スタイル（インライン：プラグインCSS より確実に優先） -->
    <style>
    body.login {
        font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', 'Yu Gothic', sans-serif !important;
        background: #F2F1EC !important;
        color: #2B2B2B !important;
        line-height: 1.6 !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        min-height: 100vh;
        overflow-x: hidden;
    }
    body.login * { box-sizing: border-box; }
    body.login .login-container {
        width: 90%;
        max-width: 440px;
        margin: 60px auto;
        padding: 40px;
        background: #FAF9F6;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(43,43,43,0.06);
    }
    /* ロゴ — 1.5倍 */
    body.login .logo { text-align: center; margin-bottom: 30px; }
    body.login .logo img { max-width: 225px !important; width: auto; height: auto; }

    /* WP-Members wrapper — 幅100% 強制 */
    body.login #wpmem_login,
    body.login .wpmem_login,
    body.login #wpmem_login_form,
    body.login form,
    body.login fieldset,
    body.login .div_text,
    body.login .button_div {
        width: 100% !important;
        max-width: 100% !important;
        padding-left: 0 !important;
        padding-right: 0 !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
    }
    body.login fieldset {
        border: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    body.login legend { display: none !important; }
    body.login .div_text { margin-bottom: 20px; }

    /* ラベル */
    body.login label {
        display: block;
        margin-bottom: 5px;
        font-weight: 600;
        color: #666660;
        font-size: 14px;
    }
    /* 入力フィールド — 横幅100% */
    body.login input[type="text"],
    body.login input[type="password"],
    body.login input[type="email"],
    body.login #user_login,
    body.login #user_pass {
        width: 100% !important;
        max-width: 100% !important;
        padding: 12px !important;
        border: 1px solid #E5E3DC !important;
        border-radius: 4px !important;
        font-size: 14px !important;
        display: block !important;
    }
    body.login input[type="text"]:focus,
    body.login input[type="password"]:focus,
    body.login input[type="email"]:focus {
        outline: none !important;
        border-color: #2F3A4A !important;
    }
    /* Remember Me チェックボックス */
    body.login .button_div { margin-top: 20px; text-align: center; }
    body.login .button_div input#rememberme { width: auto !important; display: inline !important; }
    body.login .button_div label { display: inline !important; }

    /* ログインボタン — 横幅100% */
    body.login .buttons,
    body.login input[type="submit"] {
        margin-top: 20px !important;
        margin-bottom: 20px !important;
        width: 100% !important;
        padding: 14px !important;
        background: #2F3A4A !important;
        color: #FAF9F6 !important;
        border: none !important;
        border-radius: 4px !important;
        font-size: 16px !important;
        font-weight: 600 !important;
        cursor: pointer !important;
        display: block !important;
        transition: background 0.3s;
    }
    body.login .buttons:hover,
    body.login input[type="submit"]:hover { background: #3D4B5C !important; }

    /* WP-Members の英語リンクを非表示 */
    body.login .wpmem_login_link,
    body.login .wpmem_register_link,
    body.login a[href*="pwdreset"],
    body.login a[href*="register"] { display: none !important; }

    /* パスワードリマインダー — 控えめ */
    body.login .login-links { text-align: center; margin-top: 20px; font-size: 12px; }
    body.login .login-links a { color: #B0AEA8; text-decoration: none; }
    body.login .login-links a:hover { color: #666660; text-decoration: underline; }

    /* セキュリティ注意書き */
    body.login .security-note {
        margin-top: 30px;
        padding: 15px;
        background: #EEEDEA;
        border-radius: 4px;
        font-size: 12px;
        color: #8C8A85;
        text-align: center;
    }
    </style>
</head>
<body class="login">
    <div class="login-container">
        <div class="logo">
            <img src="<?php echo esc_url( get_template_directory_uri() ); ?>/images/common/logo.png" alt="みまもりウェブ">
        </div>

        <?php
        // WP-Members ログインフォームを取得し、ラベルを日本語化
        $login_form = do_shortcode('[wpmem_form login]');
        $login_form = str_replace( 'Username or Email', 'ユーザー名', $login_form );
        $login_form = str_replace( '>Password<',        '>パスワード<', $login_form );
        $login_form = str_replace( 'Remember Me',       'ログイン状態を保持する', $login_form );
        $login_form = str_replace( 'value="Log In"',    'value="ログイン"', $login_form );
        // WP-Members が出力する英語リンク（<div class="link-text">...forgot/register...</div>）を除去
        $login_form = preg_replace( '/<div class="link-text">.*?<\/div>/si', '', $login_form );
        echo $login_form;
        ?>

        <div class="login-links">
            <a href="<?php echo esc_url( home_url( '/wp-login.php?action=lostpassword' ) ); ?>">パスワードをお忘れですか？</a>
        </div>

        <div class="security-note">
            このサイトは SSL で保護されています。<br>
            ログイン情報は第三者と共有しないでください。
        </div>
    </div>

    <?php wp_footer(); ?>
</body>
</html>
