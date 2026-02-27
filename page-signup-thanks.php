<?php
/**
 * Template Name: お申込み完了
 *
 * ?app=xxxx パラメータから transient を引き、
 * 申込内容サマリーと完了メッセージを表示する。
 * （決済ボタンは表示しない — 管理者が手動で paid に設定）
 *
 * @package GCREV_INSIGHT
 */

// ログイン不要の公開ページ（未ログインリダイレクトなし）

// --------------------------------------------------
// 1) app_id → transient から payload を取得
// --------------------------------------------------
$app_id  = isset($_GET['app']) ? sanitize_text_field(wp_unslash($_GET['app'])) : '';
$payload = null;
$error   = '';

if ($app_id === '') {
    $error = 'お申込み情報が見つかりません。お手数ですが、もう一度お申込みフォームからやり直してください。';
} else {
    $payload = get_transient('gcrev_apply_' . $app_id);
    if (!$payload || !is_array($payload)) {
        $error = 'お申込み情報の有効期限が切れました。お手数ですが、もう一度お申込みフォームからやり直してください。';
    }
}

// プラン判定
$plan = '';
if ($payload) {
    $plan = isset($payload['plan']) ? $payload['plan'] : '';
    if (!in_array($plan, gcrev_get_valid_plan_ids(), true)) {
        $error = '不正なプラン情報です。お手数ですが、もう一度お申込みフォームからやり直してください。';
        $payload = null;
    }
}

// --------------------------------------------------
// 2) ページタイトル・パンくず設定（header.php が使用）
// --------------------------------------------------
set_query_var('gcrev_page_title', 'お申込み完了');

$breadcrumb = '<a href="' . esc_url(home_url('/')) . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';

$breadcrumb .= '<span>お申込み完了</span>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

get_header();
?>

<!-- signup.css 追加読み込み -->
<link rel="stylesheet" type="text/css" href="<?php echo esc_url(get_template_directory_uri()); ?>/css/signup.css" media="all">

<!-- コンテンツエリア -->
<div class="content-area">

  <?php if ($error !== ''): ?>
  <!-- エラー表示 -->
  <div class="thanks-error-box">
    <div class="thanks-error-icon">&#x26A0;&#xFE0F;</div>
    <h2 class="thanks-error-title">エラー</h2>
    <p class="thanks-error-message"><?php echo esc_html($error); ?></p>
    <a href="<?php echo esc_url(home_url('/apply/')); ?>" class="thanks-back-link">
      &larr; お申込みページに戻る
    </a>
  </div>

  <?php else: ?>
  <!-- 正常表示 -->

  <!-- 完了メッセージ -->
  <div class="thanks-hero">
    <div class="thanks-hero-icon">&#x2705;</div>
    <h2 class="thanks-hero-title" style="color: #fff;">お申込みを受け付けました</h2>
    <p class="thanks-hero-subtitle">
      ありがとうございます。<br>
      ご登録内容の確認完了後、ご利用開始のご案内をメールでお送りいたします。<br>
      しばらくお待ちください。
    </p>
  </div>

  <!-- 申込内容確認 -->
  <div class="thanks-summary">
    <h3 class="thanks-summary-title">&#x1F4CB; お申込み内容</h3>
    <table class="thanks-summary-table">
      <?php
      $plan_defs    = gcrev_get_plan_definitions();
      $plan_info    = isset($plan_defs[$plan]) ? $plan_defs[$plan] : null;
      $plan_display = $plan_info ? $plan_info['name'] : esc_html($plan);
      if ($plan_info && !$plan_info['has_installment']) {
          $plan_display .= '（月額 ' . number_format($plan_info['monthly']) . '円・税込）';
      }
      ?>
      <tr>
        <th>プラン</th>
        <td><?php echo esc_html($plan_display); ?></td>
      </tr>
      <?php if (!empty($payload['company'])): ?>
      <tr>
        <th>会社名</th>
        <td><?php echo esc_html($payload['company']); ?></td>
      </tr>
      <?php endif; ?>
      <?php if (!empty($payload['name'])): ?>
      <tr>
        <th>担当者名</th>
        <td><?php echo esc_html($payload['name']); ?></td>
      </tr>
      <?php endif; ?>
      <?php if (!empty($payload['email'])): ?>
      <tr>
        <th>メールアドレス</th>
        <td><?php echo esc_html($payload['email']); ?></td>
      </tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- 分割決済ボタン（制作込みプランのみ表示） -->
  <?php
  // プランが制作込み（has_installment）の場合のみ分割決済ボタンを表示
  // サブスク決済ボタンは一切表示しない
  if ( $plan_info && $plan_info['has_installment'] ) :
      // plan_id から 1y/2y を判定
      $thanks_plan_term = ( strpos( $plan, '1y' ) !== false ) ? '1y' : '2y';
      $thanks_installment_url = ( $thanks_plan_term === '1y' )
          ? get_option( 'gcrev_url_installment_1y', '' )
          : get_option( 'gcrev_url_installment_2y', '' );
  ?>
  <?php if ( $thanks_installment_url ): ?>
  <div style="max-width: 600px; margin: 24px auto; text-align: center;">
    <a href="<?php echo esc_url( $thanks_installment_url ); ?>"
       target="_blank" rel="noopener noreferrer"
       style="display:inline-flex; align-items:center; justify-content:center; gap:10px;
              width:100%; max-width:480px; padding:18px 24px;
              background:#3D6B6E; color:#fff; border:none; border-radius:12px;
              font-size:17px; font-weight:700; text-decoration:none;
              box-shadow:0 4px 12px rgba(59,130,246,.3); transition:all 0.2s;">
        &#x1F4B3; 分割払い（初回決済）を行う &#x2192;
    </a>
    <p style="font-size:14px; color:#C0392B; margin-top:14px; line-height:1.7; font-weight:600;">
      ※ 1年プランの場合は支払い回数を<strong>12回</strong>、2年プランの場合は<strong>24回</strong>を選択してください。
    </p>
    <p style="font-size:13px; color:#888888; margin-top:10px; line-height:1.6;">
      ※ ボタンを押すと安全な決済ページへ移動します。<br>
      ※ お支払い確認後、管理者がステータスを更新いたします。
    </p>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- 案内メッセージ -->
  <div style="max-width: 600px; margin: 24px auto; padding: 20px 24px; background: rgba(61,107,110,0.04); border-radius: 12px; text-align: center;">
    <p style="font-size: 15px; color: #346062; line-height: 1.8; margin: 0;">
      &#x1F4E7; ご登録のメールアドレスに確認メールをお送りしております。<br>
      内容のご確認をお願いいたします。
    </p>
  </div>

  <!-- フッターリンク -->
<!--   <footer class="signup-footer">
    <div class="signup-footer-links">
      <a href="https://example.com/tokusho" target="_blank" rel="noopener noreferrer" class="signup-footer-link">特定商取引法に基づく表記</a>
      <a href="https://example.com/privacy" target="_blank" rel="noopener noreferrer" class="signup-footer-link">プライバシーポリシー</a>
    </div>
  </footer> -->

  <?php endif; ?>

</div><!-- .content-area -->

<?php get_footer(); ?>
