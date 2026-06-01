<?php
/*
Template Name: 通知設定
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$user_id = mimamori_get_view_user_id();

set_query_var( 'gcrev_page_title', '通知設定' );
set_query_var( 'gcrev_page_subtitle', 'メール通知やアラートの設定を管理します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '通知設定', '各種設定' ) );

// 現在の設定値
$notify_enabled = gcrev_is_report_notify_enabled( $user_id );
$notify_email   = (string) get_user_meta( $user_id, 'gcrev_notify_report_email', true );
$account_email  = '';
$u = get_userdata( $user_id );
if ( $u ) {
    $account_email = (string) $u->user_email;
}

get_header();
?>

<style>
/* =============================================
   page-notifications — Page-specific styles
   ============================================= */
.notif-container {
    max-width: 760px;
    margin: 0 auto;
    padding: 32px 32px 48px;
}
.notif-card {
    background: #fff;
    border-radius: var(--mw-radius-md, 10px);
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    padding: 28px 32px;
    margin-bottom: 24px;
}
.notif-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--mw-primary-blue, #568184);
}
.notif-card-header h2 {
    font-size: 17px;
    font-weight: 700;
    color: var(--mw-text-primary, #2c3e50);
    margin: 0;
}
.notif-desc {
    font-size: 13px;
    color: var(--mw-text-secondary, #666);
    margin: 12px 0 20px;
    line-height: 1.7;
}

/* --- トグル行 --- */
.notif-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid var(--mw-border-light, #e8ecee);
}
.notif-row:last-child { border-bottom: none; }
.notif-row-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-primary, #2c3e50);
}
.notif-row-note {
    font-size: 12px;
    color: var(--mw-text-secondary, #666);
    margin-top: 4px;
    line-height: 1.6;
}

/* --- スイッチ --- */
.notif-switch {
    position: relative;
    display: inline-block;
    width: 46px;
    height: 26px;
    flex-shrink: 0;
}
.notif-switch input { opacity: 0; width: 0; height: 0; }
.notif-slider {
    position: absolute;
    cursor: pointer;
    inset: 0;
    background: #cbd5e1;
    border-radius: 26px;
    transition: background 0.25s;
}
.notif-slider::before {
    content: "";
    position: absolute;
    height: 20px;
    width: 20px;
    left: 3px;
    bottom: 3px;
    background: #fff;
    border-radius: 50%;
    transition: transform 0.25s;
}
.notif-switch input:checked + .notif-slider { background: var(--mw-primary-blue, #568184); }
.notif-switch input:checked + .notif-slider::before { transform: translateX(20px); }

/* --- メール入力 --- */
.notif-field { margin-top: 18px; }
.notif-field label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--mw-text-secondary, #666);
    margin-bottom: 6px;
}
.notif-field input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--mw-border-light, #e8ecee);
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
    transition: border-color 0.2s;
}
.notif-field input:focus {
    border-color: var(--mw-primary-blue, #568184);
    outline: none;
    box-shadow: 0 0 0 2px rgba(86,129,132,0.12);
}
.notif-field .field-note {
    font-size: 12px;
    color: var(--mw-text-secondary, #666);
    margin-top: 6px;
}
.notif-field .field-error {
    font-size: 12px;
    color: #C0392B;
    margin-top: 4px;
    display: none;
}

/* --- ボタン --- */
.notif-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 24px;
}
.notif-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 28px;
    border: none;
    border-radius: var(--mw-radius-sm, 6px);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    background: var(--mw-primary-blue, #568184);
    color: #fff;
    transition: background 0.2s, box-shadow 0.2s;
}
.notif-btn:hover { background: #476C6F; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
.notif-btn:disabled { opacity: 0.6; cursor: default; }
.notif-btn-secondary {
    background: #e8ecee;
    color: var(--mw-text-primary, #2c3e50);
}
.notif-btn-secondary:hover { background: #dce0e3; box-shadow: none; }

/* --- トースト --- */
.notif-toast {
    position: fixed;
    bottom: 32px;
    left: 50%;
    transform: translateX(-50%) translateY(80px);
    padding: 12px 28px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    color: #fff;
    z-index: 9999;
    opacity: 0;
    transition: transform 0.3s ease, opacity 0.3s ease;
    pointer-events: none;
}
.notif-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
.notif-toast--success { background: #4E8A6B; }
.notif-toast--error   { background: #C0392B; }

@media (max-width: 768px) {
    .notif-container { padding: 24px 16px 40px; }
    .notif-card { padding: 20px 16px; }
}
</style>

<div class="notif-container">

    <div class="notif-card">
        <div class="notif-card-header">
            <h2>月次レポート通知</h2>
        </div>
        <p class="notif-desc">
            毎月のレポートが生成・公開されたタイミングで、完成のお知らせメールをお届けします。
        </p>

        <div class="notif-row">
            <div>
                <div class="notif-row-label">レポート完成メールを受け取る</div>
                <div class="notif-row-note">毎月1日ごろ、月次レポートが完成すると通知メールが届きます。</div>
            </div>
            <label class="notif-switch">
                <input type="checkbox" id="notifEnabled" <?php checked( $notify_enabled ); ?> />
                <span class="notif-slider"></span>
            </label>
        </div>

        <div class="notif-field">
            <label for="notifEmail">送信先メールアドレス</label>
            <input type="email" id="notifEmail" value="<?php echo esc_attr( $notify_email ); ?>"
                   placeholder="<?php echo esc_attr( $account_email ?: 'example@example.com' ); ?>">
            <div class="field-note">
                空欄の場合は、ご登録のメールアドレス<?php echo $account_email ? '（' . esc_html( $account_email ) . '）' : ''; ?> に送信されます。
            </div>
            <div class="field-error" id="errEmail">有効なメールアドレスを入力してください</div>
        </div>

        <div class="notif-actions">
            <button type="button" class="notif-btn notif-btn-secondary" id="notifTest">テスト送信</button>
            <button type="button" class="notif-btn" id="notifSave" data-mw-save="1">設定を保存</button>
        </div>
        <p class="field-note" style="text-align:right; margin-top:10px;">
            「テスト送信」を押すと、上の送信先（未入力の場合はご登録のメール）宛にサンプルメールを送信します。
        </p>
    </div>

</div><!-- .notif-container -->

<div class="notif-toast" id="notifToast"></div>

<script>
(function() {
    var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
    var nonce   = '<?php echo esc_js( wp_create_nonce( 'gcrev_notification_settings' ) ); ?>';

    function showToast(msg, type) {
        var t = document.getElementById('notifToast');
        t.textContent = msg;
        t.className = 'notif-toast notif-toast--' + (type || 'success');
        requestAnimationFrame(function() { t.classList.add('show'); });
        setTimeout(function() { t.classList.remove('show'); }, 3000);
    }

    var saveBtn = document.getElementById('notifSave');

    saveBtn.addEventListener('click', function() {
        var enabled = document.getElementById('notifEnabled').checked;
        var email   = document.getElementById('notifEmail').value.trim();
        var errEl   = document.getElementById('errEmail');

        errEl.style.display = 'none';

        // メールは任意入力。入力がある場合のみ形式チェック
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errEl.style.display = 'block';
            return;
        }

        saveBtn.disabled = true;
        saveBtn.textContent = '保存中...';

        var fd = new FormData();
        fd.append('action', 'gcrev_save_notification_settings');
        fd.append('nonce', nonce);
        fd.append('report_enabled', enabled ? '1' : '0');
        fd.append('report_email', email);

        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                saveBtn.disabled = false;
                saveBtn.textContent = '設定を保存';
                if (res.success) {
                    showToast('設定を保存しました');
                } else {
                    showToast((res.data && res.data.message) ? res.data.message : (res.data || '保存に失敗しました'), 'error');
                }
            })
            .catch(function() {
                saveBtn.disabled = false;
                saveBtn.textContent = '設定を保存';
                showToast('通信エラーが発生しました', 'error');
            });
    });

    // ========== テスト送信 ==========
    var testBtn = document.getElementById('notifTest');

    testBtn.addEventListener('click', function() {
        var email = document.getElementById('notifEmail').value.trim();
        var errEl = document.getElementById('errEmail');

        errEl.style.display = 'none';

        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errEl.style.display = 'block';
            return;
        }

        testBtn.disabled = true;
        testBtn.textContent = '送信中...';

        var fd = new FormData();
        fd.append('action', 'gcrev_send_test_notification');
        fd.append('nonce', nonce);
        fd.append('report_email', email);

        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                testBtn.disabled = false;
                testBtn.textContent = 'テスト送信';
                if (res.success) {
                    showToast('テストメールを ' + (res.data && res.data.sent_to ? res.data.sent_to : '送信先') + ' に送信しました');
                } else {
                    showToast((res.data && res.data.message) ? res.data.message : (res.data || 'テスト送信に失敗しました'), 'error');
                }
            })
            .catch(function() {
                testBtn.disabled = false;
                testBtn.textContent = 'テスト送信';
                showToast('通信エラーが発生しました', 'error');
            });
    });
})();
</script>

<?php get_footer(); ?>
