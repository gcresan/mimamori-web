<?php
/**
 * Template Name: お申込み（伴走運用プラン）
 *
 * みまもりウェブ 伴走運用プラン（¥16,500/月）の申込ページ。
 * ログイン不要の公開ページ。プランは1つのみ（プリセレクト）。
 *
 * @package GCREV_INSIGHT
 */

// ページタイトル・パンくず設定（header.php が使用）
set_query_var('gcrev_page_title', 'お申込み（伴走運用プラン）');

set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('伴走運用プラン', 'お申込み'));

get_header();
?>

<!-- signup.css 追加読み込み -->
<link rel="stylesheet" type="text/css" href="<?php echo esc_url(get_template_directory_uri()); ?>/css/signup.css" media="all">

<!-- コンテンツエリア -->
<div class="content-area">

  <!-- 1) ヒーロー -->
  <div class="signup-hero">
    <h2 class="signup-hero-title">お申込み<br>みまもりウェブ 伴走運用プラン</h2>
    <p class="signup-hero-subtitle">レポート＋解釈＋次の打ち手まで伴走する運用サポートプランです。</p>
  </div>

  <!-- 2) プラン表示（1プラン・プリセレクト済み） -->
  <section class="signup-section">
    <h3 class="signup-section-title">お申込みプラン</h3>

    <div class="signup-plan-group">
      <div class="signup-plan-group-header signup-plan-group-header--unyou">
        <span class="signup-plan-group-icon">&#x1F527;</span>
        <div>
          <h4 class="signup-plan-group-name">制作なしプラン</h4>
          <p class="signup-plan-group-desc">既存サイトの運用サポート</p>
        </div>
      </div>
      <div class="signup-plans signup-plans--unyou">
        <!-- みまもりウェブ 伴走運用プラン（プリセレクト） -->
        <div class="signup-plan-card selected" data-plan="unyou" data-plan-name="みまもりウェブ 伴走運用プラン" data-price="16,500/月">
          <div class="signup-plan-checkmark">&#x2713;</div>
          <div class="signup-plan-name">みまもりウェブ【伴走運用プラン】</div>
          <div class="signup-plan-price-monthly">
            月額 <strong>16,500</strong><span>円（税込）</span>
          </div>
          <div class="signup-plan-unit">月額サブスクリプション</div>
          <div class="signup-plan-detail">
            レポート＋解釈＋次の打ち手まで伴走<br>
            初期費用なし・いつでも解約可能<br>
            継続利用のためサブスクリプション契約となります<br>
            ※ 2年プラン満了者は月額 12,100円
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
          <strong>契約期間</strong>：期間の定めはなく、前月末までの通知で解約可能です。
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
    planInput.value = 'unyou';
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
