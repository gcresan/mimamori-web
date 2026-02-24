<?php
/**
 * Template Name: お申込み（制作込みプラン）
 *
 * 制作込み 2年プラン / 1年プラン の申込ページ。
 * ログイン不要の公開ページ。
 *
 * @package GCREV_INSIGHT
 */

// ページタイトル・パンくず設定（header.php が使用）
set_query_var('gcrev_page_title', 'お申込み（制作込みプラン）');

$breadcrumb  = '<a href="' . esc_url(home_url('/')) . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="' . esc_url(home_url('/apply/')) . '">お申込み</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<span>制作込みプラン</span>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

get_header();
?>

<!-- signup.css 追加読み込み -->
<link rel="stylesheet" type="text/css" href="<?php echo esc_url(get_template_directory_uri()); ?>/css/signup.css" media="all">

<!-- コンテンツエリア -->
<div class="content-area">

  <!-- 1) ヒーロー -->
  <div class="signup-hero">
    <h2 class="signup-hero-title">お申込み<br>ジィクレブインサイト 制作込みプラン</h2>
    <p class="signup-hero-subtitle">ホームページ制作 + 運用サポートがセットになった伴走型プランです。</p>
  </div>

  <!-- 2) プラン選択 -->
  <section class="signup-section">
    <h3 class="signup-section-title">プランを選択してください</h3>

    <div class="signup-plan-group">
      <div class="signup-plan-group-header signup-plan-group-header--seisaku">
        <span class="signup-plan-group-icon">&#x1F3D7;</span>
        <div>
          <h4 class="signup-plan-group-name">制作込みプラン</h4>
          <p class="signup-plan-group-desc">ホームページ制作 + 運用サポート</p>
        </div>
      </div>
      <div class="signup-plans signup-plans--seisaku">
        <!-- 制作込み 2年プラン（おすすめ） -->
        <div class="signup-plan-card" data-plan="seisaku_2y" data-plan-name="制作込み2年プラン" data-price="約52.8万円前後">
          <span class="signup-plan-badge">おすすめ</span>
          <div class="signup-plan-checkmark">&#x2713;</div>
          <div class="signup-plan-name">2年プラン</div>
          <div class="signup-plan-price-monthly">
            月額 <strong>22,000</strong><span>円前後（税込）</span>
          </div>
          <div class="signup-plan-price-total">
            総額 約528,000円前後（税込）
          </div>
          <div class="signup-plan-detail">
            22,000円前後 &times; 24回 クレジットカード分割払い<br>
            ※ 分割決済手数料を含むため「前後」となります<br>
            契約満了後 → 月額サブスクリプション契約へ自動移行
          </div>
        </div>
        <!-- 制作込み 1年プラン -->
        <div class="signup-plan-card" data-plan="seisaku_1y" data-plan-name="制作込み1年プラン" data-price="約26.4万円前後">
          <div class="signup-plan-checkmark">&#x2713;</div>
          <div class="signup-plan-name">1年プラン</div>
          <div class="signup-plan-price-monthly">
            月額 <strong>22,000</strong><span>円前後（税込）</span>
          </div>
          <div class="signup-plan-price-total">
            総額 約264,000円前後（税込）
          </div>
          <div class="signup-plan-detail">
            22,000円前後 &times; 12回 クレジットカード分割払い<br>
            ※ 分割決済手数料を含むため「前後」となります<br>
            契約満了後 → 月額サブスクリプション契約へ自動移行
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
          <strong>最低契約期間</strong>：選択されたプラン期間（1年または2年）が最低契約期間となります。
        </li>
        <li>
          <strong>途中解約</strong>：契約期間中の途中解約は原則不可。やむを得ない事情による解約の場合も、残期間分の料金をお支払いいただきます。
        </li>
        <li>
          <strong>契約満了後</strong>：契約満了後は月額22,000円（税込）の月次サブスクリプションへ自動移行。期間の定めはなく、前月末までの通知で解約可能です。
        </li>
        <li>
          <strong>制作物の著作権</strong>：制作物の著作権は株式会社ジィクレブに帰属し、お客様には利用許諾を付与します。著作権の買い取りは任意で可能です（2年プラン：30万円、1年プラン：55万円）。
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

<!-- プラン選択 & 同意チェック JS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  // ---------- プラン選択 ----------
  var planCards     = document.querySelectorAll('.signup-plan-card');
  var planDisplay   = document.getElementById('selected-plan-display');
  var planInput     = document.querySelector('input[name="plan"]');
  var selectedPlan  = null;

  planCards.forEach(function(card) {
    card.addEventListener('click', function() {
      planCards.forEach(function(c) { c.classList.remove('selected'); });
      card.classList.add('selected');
      selectedPlan = card.dataset.plan;

      if (planDisplay) {
        planDisplay.textContent = card.dataset.planName + '（総額 ' + card.dataset.price + '円・税込）';
      }
      if (planInput) {
        planInput.value = selectedPlan;
      }
      checkReady();
    });
  });

  // ---------- 同意チェック ----------
  var consentBoxes  = document.querySelectorAll('.consent-checkbox');
  var submitButtons = document.querySelectorAll('.mw_wp_form input[type="submit"], .signup-cta-button');

  function checkReady() {
    var allChecked   = Array.from(consentBoxes).every(function(cb) { return cb.checked; });
    var planSelected = selectedPlan !== null;
    var isReady      = allChecked && planSelected;

    submitButtons.forEach(function(btn) {
      btn.disabled = !isReady;
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
      if (!selectedPlan) {
        e.preventDefault();
        var planSection = document.querySelector('.signup-plans');
        if (planSection) {
          planSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
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
