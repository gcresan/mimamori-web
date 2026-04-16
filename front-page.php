<?php
/**
 * Front Page ルーター
 *
 * ルート (/) は直接表示せず、状態に応じて適切なページへリダイレクトする。
 *   - ログイン済み（お試し中 or プラン設定済み or 決済済み） → /dashboard/
 *   - ログイン済み（それ以外） → /payment-status/
 *   - 未ログイン → /login/（実体は page-login.php）
 *
 * ※ ログインページ本体は page-login.php に移設済み（2026-04-16）。
 */

if ( is_user_logged_in() ) {
    $uid = get_current_user_id();
    if ( gcrev_is_trial_active( $uid ) || gcrev_has_plan_configured( $uid ) || gcrev_is_payment_active( $uid ) ) {
        wp_safe_redirect( home_url( '/dashboard/' ) );
    } else {
        wp_safe_redirect( home_url( '/payment-status/' ) );
    }
    exit;
}

// 未ログイン → ログインページへ（クエリパラメータは引き継ぐ：login_error, trial_expired 等）
$query_string = ! empty( $_SERVER['QUERY_STRING'] ) ? '?' . $_SERVER['QUERY_STRING'] : '';
wp_safe_redirect( home_url( '/login/' . $query_string ) );
exit;
