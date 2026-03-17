<?php
/*
Template Name: 問い合わせ
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

set_query_var( 'gcrev_page_title', 'お問い合わせ' );
set_query_var( 'gcrev_page_subtitle', 'プランに関するご相談や、サポートに関するお問い合わせはこちらからご連絡ください。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'お問い合わせ', 'サポート・問い合わせ' ) );

// 現在のティア
$current_tier = function_exists( 'gcrev_get_service_tier' ) ? gcrev_get_service_tier( $user_id ) : 'basic';
$tier_labels  = [
    'basic'      => 'ベーシックプラン',
    'ai_support' => 'AIサポートプラン',
    'bansou'     => '伴走プラン',
];
$current_plan_label = $tier_labels[ $current_tier ] ?? 'ベーシックプラン';

// URLパラメータから初期値を取得
$preset_type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : '';
$preset_plan = isset( $_GET['plan'] ) ? sanitize_text_field( $_GET['plan'] ) : '';

// type → 問い合わせ種別の初期値マッピング
$type_map = [
    'basic'      => 'plan_basic',
    'ai_support' => 'plan_ai_support',
    'bansou'     => 'plan_bansou',
    'change'     => 'plan_change',
    'support'    => 'support',
];
$initial_type = $type_map[ $preset_type ] ?? '';

// plan → 希望プランの初期値マッピング
$plan_map = [
    'basic'      => 'ベーシックプラン',
    'ai_support' => 'AIサポートプラン',
    'bansou'     => '伴走プラン',
];
$initial_plan = $plan_map[ $preset_plan ] ?? '';

get_header();
?>

<style>
/* ============================================
   お問い合わせページ
   ============================================ */
.inquiry-container {
    max-width: 680px;
    margin: 0 auto;
}
.inquiry-card {
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 12px;
    padding: 36px 32px;
    margin-bottom: 24px;
}
.inquiry-lead {
    text-align: center;
    margin-bottom: 32px;
    line-height: 1.7;
    font-size: 14px;
    color: #666;
}

/* --- フォームグループ --- */
.inq-group {
    margin-bottom: 24px;
}
.inq-label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    margin-bottom: 6px;
}
.inq-label .inq-required {
    display: inline-block;
    margin-left: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #e74c3c;
    background: rgba(231,76,60,0.06);
    padding: 1px 6px;
    border-radius: 3px;
    vertical-align: middle;
}
.inq-label .inq-optional {
    display: inline-block;
    margin-left: 6px;
    font-size: 11px;
    font-weight: 600;
    color: #999;
    background: #f5f5f5;
    padding: 1px 6px;
    border-radius: 3px;
    vertical-align: middle;
}

/* --- 種別ラジオ --- */
.inq-type-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.inq-type-item {
    display: block;
    cursor: pointer;
}
.inq-type-item input[type="radio"] {
    display: none;
}
.inq-type-item .inq-type-box {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    font-size: 14px;
    color: #444;
    transition: border-color 0.15s, background 0.15s;
}
.inq-type-item .inq-type-box:hover {
    border-color: #ccc;
    background: #fafafa;
}
.inq-type-item input[type="radio"]:checked + .inq-type-box {
    border-color: #4ECDC4;
    background: rgba(78,205,196,0.04);
    color: #333;
}
.inq-type-radio {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    border: 2px solid #ccc;
    border-radius: 50%;
    position: relative;
    transition: border-color 0.15s;
}
.inq-type-item input[type="radio"]:checked + .inq-type-box .inq-type-radio {
    border-color: #4ECDC4;
}
.inq-type-item input[type="radio"]:checked + .inq-type-box .inq-type-radio::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #4ECDC4;
}

/* --- テキスト入力 --- */
.inq-input,
.inq-select,
.inq-textarea {
    display: block;
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    color: #333;
    background: #fff;
    transition: border-color 0.15s;
    box-sizing: border-box;
    font-family: inherit;
}
.inq-input:focus,
.inq-select:focus,
.inq-textarea:focus {
    outline: none;
    border-color: #4ECDC4;
    box-shadow: 0 0 0 3px rgba(78,205,196,0.1);
}
.inq-textarea {
    min-height: 140px;
    resize: vertical;
    line-height: 1.6;
}
.inq-input.is-error,
.inq-textarea.is-error {
    border-color: #e74c3c;
}
.inq-error-msg {
    display: none;
    font-size: 12px;
    color: #e74c3c;
    margin-top: 4px;
}
.inq-error-msg.is-visible {
    display: block;
}
.inq-hint {
    font-size: 12px;
    color: #aaa;
    margin-top: 4px;
}

/* --- 条件表示エリア --- */
.inq-conditional {
    display: none;
}
.inq-conditional.is-visible {
    display: block;
}

/* --- 送信ボタン --- */
.inq-submit-area {
    text-align: center;
    margin-top: 32px;
}
.inq-submit-btn {
    display: inline-block;
    padding: 14px 48px;
    background: #4A5568;
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.2s, opacity 0.2s;
    min-width: 200px;
}
.inq-submit-btn:hover {
    background: #3D4B5C;
}
.inq-submit-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* --- 完了画面 --- */
.inq-complete {
    display: none;
    text-align: center;
    padding: 48px 24px;
}
.inq-complete.is-visible {
    display: block;
}
.inq-complete__icon {
    font-size: 48px;
    margin-bottom: 16px;
}
.inq-complete__heading {
    font-size: 20px;
    font-weight: 700;
    color: #333;
    margin-bottom: 12px;
}
.inq-complete__text {
    font-size: 14px;
    color: #666;
    line-height: 1.7;
    margin-bottom: 24px;
}
.inq-complete__back {
    display: inline-block;
    padding: 10px 32px;
    background: #f5f5f5;
    color: #666;
    font-size: 14px;
    font-weight: 600;
    border-radius: 8px;
    text-decoration: none;
    transition: background 0.2s;
}
.inq-complete__back:hover {
    background: #ebebeb;
    color: #666;
}

/* --- エラーサマリー --- */
.inq-error-summary {
    display: none;
    padding: 12px 16px;
    background: rgba(231,76,60,0.06);
    border: 1px solid rgba(231,76,60,0.2);
    border-radius: 8px;
    font-size: 13px;
    color: #c0392b;
    margin-bottom: 20px;
    line-height: 1.6;
}
.inq-error-summary.is-visible {
    display: block;
}

/* --- レスポンシブ --- */
@media (max-width: 600px) {
    .inquiry-card {
        padding: 24px 18px;
    }
    .inq-submit-btn {
        width: 100%;
    }
}
</style>

<div class="content-area">
    <div class="inquiry-container">

        <p class="inquiry-lead">
            プランに関するご相談やサポートのお問い合わせを受け付けています。<br>
            内容を確認のうえ、順次ご案内いたします。
        </p>

        <div class="inquiry-card">

            <!-- フォーム -->
            <form id="inquiryForm" novalidate>

                <!-- エラーサマリー -->
                <div class="inq-error-summary" id="inqErrorSummary"></div>

                <!-- 問い合わせ種別 -->
                <div class="inq-group">
                    <span class="inq-label">お問い合わせ種別 <span class="inq-required">必須</span></span>
                    <div class="inq-type-list" id="inqTypeList">
                        <label class="inq-type-item">
                            <input type="radio" name="inquiry_type" value="plan_basic">
                            <span class="inq-type-box"><span class="inq-type-radio"></span>ベーシックプランについて相談したい</span>
                        </label>
                        <label class="inq-type-item">
                            <input type="radio" name="inquiry_type" value="plan_ai_support">
                            <span class="inq-type-box"><span class="inq-type-radio"></span>AIサポートプランについて相談したい</span>
                        </label>
                        <label class="inq-type-item">
                            <input type="radio" name="inquiry_type" value="plan_bansou">
                            <span class="inq-type-box"><span class="inq-type-radio"></span>伴走プランについて相談したい</span>
                        </label>
                        <label class="inq-type-item">
                            <input type="radio" name="inquiry_type" value="plan_change">
                            <span class="inq-type-box"><span class="inq-type-radio"></span>プラン変更について相談したい</span>
                        </label>
                        <label class="inq-type-item">
                            <input type="radio" name="inquiry_type" value="support">
                            <span class="inq-type-box"><span class="inq-type-radio"></span>サポートについて問い合わせしたい</span>
                        </label>
                        <label class="inq-type-item">
                            <input type="radio" name="inquiry_type" value="other">
                            <span class="inq-type-box"><span class="inq-type-radio"></span>その他のお問い合わせ</span>
                        </label>
                    </div>
                    <div class="inq-error-msg" id="errType">お問い合わせ種別を選択してください。</div>
                </div>

                <!-- プラン変更時: 条件表示 -->
                <div class="inq-conditional" id="condPlanChange">
                    <div class="inq-group">
                        <label class="inq-label" for="inqCurrentPlan">現在利用中のプラン <span class="inq-optional">任意</span></label>
                        <input type="text" id="inqCurrentPlan" name="current_plan" class="inq-input"
                               value="<?php echo esc_attr( $current_plan_label ); ?>" readonly
                               style="background: #f9f9f7; color: #888;">
                    </div>
                    <div class="inq-group">
                        <label class="inq-label" for="inqDesiredPlan">変更を検討しているプラン <span class="inq-optional">任意</span></label>
                        <select id="inqDesiredPlan" name="desired_plan" class="inq-select">
                            <option value="">選択してください</option>
                            <option value="ベーシックプラン"<?php echo $initial_plan === 'ベーシックプラン' ? ' selected' : ''; ?>>ベーシックプラン</option>
                            <option value="AIサポートプラン"<?php echo $initial_plan === 'AIサポートプラン' ? ' selected' : ''; ?>>AIサポートプラン</option>
                            <option value="伴走プラン"<?php echo $initial_plan === '伴走プラン' ? ' selected' : ''; ?>>伴走プラン</option>
                        </select>
                    </div>
                </div>

                <!-- 基本情報 -->
                <div class="inq-group">
                    <label class="inq-label" for="inqName">お名前 <span class="inq-required">必須</span></label>
                    <input type="text" id="inqName" name="name" class="inq-input"
                           value="<?php echo esc_attr( $current_user->display_name ); ?>"
                           placeholder="例: 山田 太郎">
                    <div class="inq-error-msg" id="errName">お名前を入力してください。</div>
                </div>

                <div class="inq-group">
                    <label class="inq-label" for="inqCompany">会社名 / 事業者名 <span class="inq-optional">任意</span></label>
                    <input type="text" id="inqCompany" name="company" class="inq-input"
                           value="<?php echo esc_attr( function_exists('gcrev_get_business_name') ? gcrev_get_business_name( $user_id ) : '' ); ?>"
                           placeholder="例: 株式会社サンプル">
                </div>

                <div class="inq-group">
                    <label class="inq-label" for="inqEmail">メールアドレス <span class="inq-required">必須</span></label>
                    <input type="email" id="inqEmail" name="email" class="inq-input"
                           value="<?php echo esc_attr( $current_user->user_email ); ?>"
                           placeholder="例: info@example.com">
                    <div class="inq-error-msg" id="errEmail">正しいメールアドレスを入力してください。</div>
                </div>

                <div class="inq-group">
                    <label class="inq-label" for="inqPhone">電話番号 <span class="inq-optional">任意</span></label>
                    <input type="tel" id="inqPhone" name="phone" class="inq-input"
                           placeholder="例: 090-1234-5678">
                </div>

                <!-- お問い合わせ内容 -->
                <div class="inq-group">
                    <label class="inq-label" for="inqMessage">お問い合わせ内容 <span class="inq-required">必須</span></label>
                    <textarea id="inqMessage" name="message" class="inq-textarea"
                              placeholder="ご相談内容やご質問をご記入ください。&#10;内容が具体的でなくても構いません。"></textarea>
                    <div class="inq-error-msg" id="errMessage">お問い合わせ内容を入力してください。</div>
                </div>

                <!-- 送信ボタン -->
                <div class="inq-submit-area">
                    <button type="submit" class="inq-submit-btn" id="inqSubmitBtn">送信する</button>
                </div>
            </form>

            <!-- 完了画面 -->
            <div class="inq-complete" id="inqComplete">
                <div class="inq-complete__icon">✉️</div>
                <h3 class="inq-complete__heading">お問い合わせを受け付けました</h3>
                <p class="inq-complete__text">
                    内容を確認のうえ、順次ご案内いたします。<br>
                    通常2〜3営業日以内にご連絡いたします。
                </p>
                <a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>" class="inq-complete__back">ダッシュボードに戻る</a>
            </div>

        </div>
    </div>
</div>

<script>
(function(){
    var form       = document.getElementById('inquiryForm');
    var submitBtn  = document.getElementById('inqSubmitBtn');
    var complete   = document.getElementById('inqComplete');
    var errSummary = document.getElementById('inqErrorSummary');

    // --- 初期値設定 ---
    var initialType = <?php echo wp_json_encode( $initial_type ); ?>;
    if (initialType) {
        var radio = form.querySelector('input[name="inquiry_type"][value="' + initialType + '"]');
        if (radio) {
            radio.checked = true;
            toggleConditional(initialType);
        }
    }

    // --- 種別変更時の条件表示 ---
    var typeRadios = form.querySelectorAll('input[name="inquiry_type"]');
    typeRadios.forEach(function(r){
        r.addEventListener('change', function(){ toggleConditional(this.value); });
    });

    function toggleConditional(val) {
        var condChange = document.getElementById('condPlanChange');
        if (val === 'plan_change') {
            condChange.classList.add('is-visible');
        } else {
            condChange.classList.remove('is-visible');
        }
    }

    // --- バリデーション ---
    function validate() {
        var errors = [];
        var typeChecked = form.querySelector('input[name="inquiry_type"]:checked');
        var name    = form.querySelector('#inqName');
        var email   = form.querySelector('#inqEmail');
        var message = form.querySelector('#inqMessage');

        // リセット
        [name, email, message].forEach(function(el){ el.classList.remove('is-error'); });
        form.querySelectorAll('.inq-error-msg').forEach(function(el){ el.classList.remove('is-visible'); });

        if (!typeChecked) {
            document.getElementById('errType').classList.add('is-visible');
            errors.push('種別');
        }
        if (!name.value.trim()) {
            name.classList.add('is-error');
            document.getElementById('errName').classList.add('is-visible');
            errors.push('お名前');
        }
        if (!email.value.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
            email.classList.add('is-error');
            document.getElementById('errEmail').classList.add('is-visible');
            errors.push('メール');
        }
        if (!message.value.trim()) {
            message.classList.add('is-error');
            document.getElementById('errMessage').classList.add('is-visible');
            errors.push('内容');
        }

        if (errors.length) {
            errSummary.textContent = '入力内容をご確認ください。';
            errSummary.classList.add('is-visible');
            return false;
        }
        errSummary.classList.remove('is-visible');
        return true;
    }

    // --- 送信 ---
    form.addEventListener('submit', function(e){
        e.preventDefault();
        if (!validate()) return;

        submitBtn.disabled = true;
        submitBtn.textContent = '送信中...';

        var typeChecked = form.querySelector('input[name="inquiry_type"]:checked');
        var payload = {
            inquiry_type:  typeChecked.value,
            name:          form.querySelector('#inqName').value.trim(),
            company:       form.querySelector('#inqCompany').value.trim(),
            email:         form.querySelector('#inqEmail').value.trim(),
            phone:         form.querySelector('#inqPhone').value.trim(),
            current_plan:  form.querySelector('#inqCurrentPlan') ? form.querySelector('#inqCurrentPlan').value : '',
            desired_plan:  form.querySelector('#inqDesiredPlan') ? form.querySelector('#inqDesiredPlan').value : '',
            message:       form.querySelector('#inqMessage').value.trim()
        };

        fetch('<?php echo esc_url( rest_url( 'mimamori/v1/inquiry' ) ); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
            },
            body: JSON.stringify(payload),
            credentials: 'same-origin'
        })
        .then(function(res){ return res.json(); })
        .then(function(data){
            if (data.success) {
                form.style.display = 'none';
                complete.classList.add('is-visible');
            } else {
                errSummary.textContent = data.message || '送信に失敗しました。もう一度お試しください。';
                errSummary.classList.add('is-visible');
                submitBtn.disabled = false;
                submitBtn.textContent = '送信する';
            }
        })
        .catch(function(){
            errSummary.textContent = '通信エラーが発生しました。もう一度お試しください。';
            errSummary.classList.add('is-visible');
            submitBtn.disabled = false;
            submitBtn.textContent = '送信する';
        });
    });
})();
</script>

<?php get_footer(); ?>
