<?php
/*
Template Name: Meta OAuth Callback
*/

/**
 * Meta（Facebook / Instagram / Threads）OAuth コールバック
 *
 * /social/meta-oauth-callback/ で固定ページにアサインして使う。
 *
 * 設計原則：
 * - テンプレートにはビジネスロジックを書かない
 * - 外部 API 呼び出しは class-gcrev-api.php を窓口とする
 */

if ( ! defined('ABSPATH') ) { exit; }

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url('/login/') );
    exit;
}

$user_id = get_current_user_id();

$code  = isset($_GET['code'])  ? sanitize_text_field( wp_unslash($_GET['code']) )  : '';
$state = isset($_GET['state']) ? sanitize_text_field( wp_unslash($_GET['state']) ) : '';
$error = isset($_GET['error']) ? sanitize_text_field( wp_unslash($_GET['error']) ) : '';

if ( ! empty($error) ) {
    error_log("[GCREV][Meta Callback] OAuth error: {$error}, user_id={$user_id}");
    get_header();
    ?>
    <div class="content-area">
        <div style="text-align:center; padding:80px 20px;">
            <div style="font-size:48px; margin-bottom:20px;">⚠️</div>
            <h3 style="font-size:20px; font-weight:600; color:#333; margin-bottom:12px;">
                Meta との接続がキャンセルされました
            </h3>
            <p style="color:#666; margin-bottom:32px;"><?php echo esc_html($error); ?></p>
            <a href="<?php echo esc_url(home_url('/social-connect/')); ?>"
               style="display:inline-block; padding:14px 32px; background:#568184; color:#fff; border-radius:8px; text-decoration:none; font-weight:600;">
                📱 SNS連携へ戻る
            </a>
        </div>
    </div>
    <?php
    get_footer();
    exit;
}

if ( empty($code) ) {
    wp_safe_redirect( home_url('/social-connect/') );
    exit;
}

global $gcrev_api_instance;
if ( ! isset($gcrev_api_instance) || ! ($gcrev_api_instance instanceof Gcrev_Insight_API) ) {
    $gcrev_api_instance = new Gcrev_Insight_API(false);
}
$gcrev_api = $gcrev_api_instance;

$result = $gcrev_api->social_meta_exchange_code($user_id, $code, $state);

if ( ! empty($result['success']) ) {
    wp_safe_redirect( add_query_arg('meta_connected', '1', home_url('/social-connect/')) );
    exit;
}

get_header();
?>
<div class="content-area">
    <div style="text-align:center; padding:80px 20px;">
        <div style="font-size:48px; margin-bottom:20px;">❌</div>
        <h3 style="font-size:20px; font-weight:600; color:#333; margin-bottom:12px;">
            Meta 接続に失敗しました
        </h3>
        <p style="color:#666; margin-bottom:32px;">
            <?php echo esc_html( $result['message'] ?? '不明なエラーが発生しました。' ); ?>
        </p>
        <a href="<?php echo esc_url(home_url('/social-connect/')); ?>"
           style="display:inline-block; padding:14px 32px; background:#568184; color:#fff; border-radius:8px; text-decoration:none; font-weight:600;">
            📱 SNS連携へ戻る
        </a>
    </div>
</div>
<?php
get_footer();
