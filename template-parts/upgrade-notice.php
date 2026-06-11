<?php
/**
 * 機能ブロック＋アップグレード案内
 *
 * プランの機能ゲートに引っかかった場合に、ページ本文の代わりに表示する。
 * 呼び出し側で以下の query_var を設定すること:
 *   - gcrev_upgrade_feature       : ブロックされた機能名（例: '月次レポート'）
 *   - gcrev_upgrade_required_plan : 必要プラン名（例: '改善提案プラン'）
 *
 * 通常は mimamori_render_upgrade_page() 経由で使用する（functions.php）。
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$feature_label = get_query_var( 'gcrev_upgrade_feature' ) ?: 'この機能';
$required_plan = get_query_var( 'gcrev_upgrade_required_plan' ) ?: '上位プラン';
?>
<div class="gcrev-upgrade-notice" role="status"
     style="max-width:640px;margin:48px auto;padding:40px 32px;text-align:center;background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
    <div aria-hidden="true" style="font-size:40px;line-height:1;margin-bottom:16px;">&#128274;</div>
    <h2 style="margin:0 0 12px;font-size:20px;color:#111827;">
        <?php echo esc_html( $feature_label ); ?>は<?php echo esc_html( $required_plan ); ?>以上でご利用いただけます
    </h2>
    <p style="margin:0 0 24px;font-size:14px;color:#6b7280;line-height:1.8;">
        現在ご契約中のプランでは、この機能はご利用いただけません。<br>
        プランをアップグレードすると、すぐにご利用を開始できます。
    </p>
    <a href="<?php echo esc_url( home_url( '/plans/' ) ); ?>"
       style="display:inline-block;padding:12px 32px;background:#1d4ed8;color:#fff;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;">
        プランを確認する
    </a>
</div>
