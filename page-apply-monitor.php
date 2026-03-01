<?php
/**
 * Template Name: お申込み（モニター価格）
 *
 * みまもりウェブ 伴走運用プラン モニター価格（¥11,000/月）の申込ページ。
 * 既存クライアント限定。最低契約期間3ヶ月。
 * ログイン不要の公開ページ。プランは1つのみ（プリセレクト）。
 *
 * @package GCREV_INSIGHT
 */

// ページタイトル・パンくず設定（header.php が使用）
set_query_var('gcrev_page_title', 'お申込み（モニター価格）');

set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('モニター価格', 'お申込み'));

get_header();
?>

<!-- signup.css 追加読み込み -->
<link rel="stylesheet" type="text/css" href="<?php echo esc_url(get_template_directory_uri()); ?>/css/signup.css" media="all">

<!-- コンテンツエリア -->
<div class="content-area">

  <!-- 1) ヒーロー -->
  <div class="signup-hero">
    <h2 class="signup-hero-title">お申込み<br>みまもりウェブ 伴走運用プラン<br><small style="font-size: 0.6em; color: #B8941E;">モニター価格</small></h2>
    <p class="signup-hero-subtitle">既存クライアント限定のモニター価格でご利用いただけます。</p>
  </div>

  <!-- 2) プラン表示（1プラン・プリセレクト済み） -->
  <section class="signup-section">
    <h3 class="signup-section-title">お申込みプラン</h3>

    <div class="signup-plan-group">
      <div class="signup-plan-group-header signup-plan-group-header--unyou">
        <span class="signup-plan-group-icon">&#x1F527;</span>
        <div>
          <h4 class="signup-plan-group-name">伴走運用プラン（モニター価格）</h4>
          <p class="signup-plan-group-desc">既存クライアント限定・特別価格</p>
        </div>
      </div>
      <div class="signup-plans signup-plans--unyou">
        <!-- モニター価格プラン（プリセレクト） -->
        <div class="signup-plan-card selected" data-plan="monitor" data-plan-name="みまもりウェブ 伴走運用プラン（モニター価格）" data-price="11,000/月">
          <span class="signup-plan-badge" style="background: rgba(212,168,66,0.08); color: #B8941E;">モニター価格</span>
          <div class="signup-plan-checkmark">&#x2713;</div>
          <div class="signup-plan-name">みまもりウェブ【伴走運用プラン】</div>
          <div class="signup-plan-price-monthly">
            月額 <strong>11,000</strong><span>円（税込）</span>
          </div>
          <div class="signup-plan-unit">月額サブスクリプション</div>
          <div class="signup-plan-detail">
            レポート＋解釈＋次の打ち手まで伴走<br>
            既存クライアント限定・モニター価格<br>
            継続利用のためサブスクリプション契約となります<br>
            <strong style="color: #B8941E;">最低契約期間：3ヶ月</strong>
          </div>
        </div>
      </div>
    </div>

  </section>

  <!-- 3) 重要事項 -->
  <section class="signup-section">
    <h3 class="signup-section-title">重要事項のご確認</h3>
    <div class="signup-terms-box">
      <ul class="signup-terms-list">
        <li>
          <strong style="color: #B8941E;">最低契約期間</strong>：<strong>3ヶ月間</strong>が最低契約期間となります。3ヶ月経過後は、前月末までの通知で解約可能です。
        </li>
        <li>
          <strong>モニター価格について</strong>：本価格は既存クライアント向けの特別価格です。通常の伴走運用プランは月額16,500円（税込）です。
        </li>
        <li>
          <strong>お支払い方法</strong>：継続利用のため月額サブスクリプション契約（クレジットカード）となります。
        </li>
        <li>
          <strong>WordPressの編集権限</strong>：お客様には編集者権限のみを付与します。プラグインの追加やテーマファイルの改変は禁止されています。
        </li>
        <li>
          <strong>成果保証について</strong>：本サービスは成果保証型ではありません。最善の施策をご提案しますが、結果を保証するものではありません。
        </li>
      </ul>
    </div>
  </section>

  <!-- 4) 同意チェック -->
  <section class="signup-section signup-section--consent">
    <h3 class="signup-section-title">同意事項</h3>
    <div class="signup-consent-box">
      <div class="signup-consent-item">
        <input type="checkbox" id="consent-terms" class="signup-consent-checkbox consent-checkbox" name="consent_terms" value="同意する">
        <label for="consent-terms" class="signup-consent-label">
          上記の重要事項に同意します <span style="color: #C0392B;">(必須)</span>
        </label>
      </div>
      <div class="signup-consent-item">
        <input type="checkbox" id="consent-policy" class="signup-consent-checkbox consent-checkbox" name="consent_policy" value="同意する">
        <label for="consent-policy" class="signup-consent-label">
          <a href="https://example.com/terms" target="_blank" rel="noopener noreferrer">利用規約</a>に同意します <span style="color: #C0392B;">(必須)</span>
        </label>
      </div>
    </div>
  </section>

  <!-- 5〜6) MW WP Form ショートコード埋め込み領域 -->
  <?php
  while ( have_posts() ) : the_post();
      the_content();
  endwhile;
  ?>

</div><!-- .content-area -->

<!-- 同意チェック JS（プランは固定） -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  // プランを hidden にプリセット
  var planInput = document.querySelector('input[name="plan"]');
  if (planInput) {
    planInput.value = 'monitor';
  }

  // ---------- 同意チェック ----------
  var consentBoxes  = document.querySelectorAll('.consent-checkbox');
  var submitButtons = document.querySelectorAll('.mw_wp_form input[type="submit"], .signup-cta-button');

  function checkReady() {
    var allChecked = Array.from(consentBoxes).every(function(cb) { return cb.checked; });

    submitButtons.forEach(function(btn) {
      btn.disabled = !allChecked;
    });
  }

  consentBoxes.forEach(function(cb) {
    cb.addEventListener('change', checkReady);
  });

  // ---------- フォーム送信時の最終バリデーション ----------
  var mwForm = document.querySelector('.mw_wp_form form');
  if (mwForm) {
    mwForm.addEventListener('submit', function(e) {
      var allChecked = Array.from(consentBoxes).every(function(cb) { return cb.checked; });
      if (!allChecked) {
        e.preventDefault();
        var consentBox = document.querySelector('.signup-consent-box');
        if (consentBox) {
          consentBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return false;
      }
    });
  }

  // 初期状態
  checkReady();
});
</script>

<?php get_footer(); ?>
