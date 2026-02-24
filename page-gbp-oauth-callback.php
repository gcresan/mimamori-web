<?php
/*
Template Name: GBP OAuth Callback
*/

/**
 * GBP OAuth コールバック処理
 *
 * 設計原則遵守：
 * - テンプレートにビジネスロジックを書かない（原則1）
 * - 外部APIを直接テンプレートから呼ばない（原則2）
 * - class-gcrev-api.php を窓口として使用（原則3）
 */

if ( ! defined('ABSPATH') ) { exit; }

// ===== 未ログインチェック =====
if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url('/login/') );
    exit;
}

$user_id = get_current_user_id();

// ===== GETパラメータ取得 =====
$code  = isset($_GET['code'])  ? sanitize_text_field( wp_unslash($_GET['code']) )  : '';
$state = isset($_GET['state']) ? sanitize_text_field( wp_unslash($_GET['state']) ) : '';
$error = isset($_GET['error']) ? sanitize_text_field( wp_unslash($_GET['error']) ) : '';

// ===== Google側エラー（ユーザーが拒否した場合等） =====
if ( ! empty($error) ) {
    error_log("[GCREV][GBP Callback] OAuth error from Google: {$error}, user_id={$user_id}");
    // エラー表示→MEOダッシュボードへ誘導
    get_header();
    ?>
    <div class="content-area">
        <div style="text-align: center; padding: 80px 20px;">
            <div style="font-size: 48px; margin-bottom: 20px;">⚠️</div>
            <h3 style="font-size: 20px; font-weight: 600; color: #333; margin-bottom: 12px;">
                Googleビジネスプロフィールとの接続がキャンセルされました
            </h3>
            <p style="color: #666; margin-bottom: 32px;">
                <?php echo esc_html($error); ?>
            </p>
            <a href="<?php echo esc_url(home_url('/meo/meo-dashboard/')); ?>"
               style="display: inline-block; padding: 14px 32px; background: #3D6B6E; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600;">
                📍 MEOダッシュボードへ戻る
            </a>
        </div>
    </div>
    <?php
    get_footer();
    exit;
}

// ===== codeが無い場合 =====
if ( empty($code) ) {
    error_log("[GCREV][GBP Callback] No code parameter, user_id={$user_id}");
    wp_safe_redirect( home_url('/meo/meo-dashboard/') );
    exit;
}

// ===== class-gcrev-api.php を窓口にトークン交換・保存 =====
global $gcrev_api_instance;
if ( ! isset($gcrev_api_instance) || ! ($gcrev_api_instance instanceof Gcrev_Insight_API) ) {
    $gcrev_api_instance = new Gcrev_Insight_API(false);
}
$gcrev_api = $gcrev_api_instance;

// state検証 + トークン交換 + user_meta保存（すべてAPI経由）
$result = $gcrev_api->gbp_exchange_code_and_store_tokens( $user_id, $code, $state );

if ( $result['success'] ) {
    // ===== 成功：MEOダッシュボードへリダイレクト =====
    error_log("[GCREV][GBP Callback] Token exchange success, user_id={$user_id}");
    wp_safe_redirect( home_url('/meo/meo-dashboard/') );
    exit;
}

// ===== 失敗：エラー表示 =====
error_log("[GCREV][GBP Callback] Token exchange failed: " . ($result['message'] ?? 'unknown') . ", user_id={$user_id}");

get_header();
?>
<div class="content-area">
    <div style="text-align: center; padding: 80px 20px;">
        <div style="font-size: 48px; margin-bottom: 20px;">❌</div>
        <h3 style="font-size: 20px; font-weight: 600; color: #333; margin-bottom: 12px;">
            接続に失敗しました
        </h3>
        <p style="color: #666; margin-bottom: 32px;">
            <?php echo esc_html( $result['message'] ?? 'トークンの取得に失敗しました。もう一度お試しください。' ); ?>
        </p>
        <a href="<?php echo esc_url(home_url('/meo/meo-dashboard/')); ?>"
           style="display: inline-block; padding: 14px 32px; background: #3D6B6E; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600;">
            📍 MEOダッシュボードへ戻る
        </a>
    </div>
</div>
<?php
get_footer();
