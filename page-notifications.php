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

// みまもりアラート／週次便の受信設定（opt-out 方式 — 未設定=受信ON）
$alert_on  = get_user_meta( $user_id, 'mimamori_alert_optout', true )  !== '1';
$digest_on = get_user_meta( $user_id, 'mimamori_digest_optout', true ) !== '1';
$suggest_on = get_user_meta( $user_id, 'mimamori_suggest_optout', true ) !== '1';

// アラート種類別の受信設定（管理画面で配信ONの種類のみ、本人の受信状況つきで表示）
$alert_types = [];
$_mm_module = get_template_directory() . '/inc/gcrev-api/modules/class-mimamori-notification-service.php';
if ( ! class_exists( 'Mimamori_Notification_Service' ) && file_exists( $_mm_module ) ) {
    require_once $_mm_module;
}
if ( class_exists( 'Mimamori_Notification_Service' ) ) {
    $_mm_settings    = Mimamori_Notification_Service::get_settings();
    $_mm_type_optout = get_user_meta( $user_id, 'mimamori_alert_type_optout', true );
    $_mm_type_optout = is_array( $_mm_type_optout ) ? $_mm_type_optout : [];
    foreach ( Mimamori_Notification_Service::alert_type_labels() as $_type => $_label ) {
        // 管理者が全体OFFにした種類はクライアントにも表示しない
        if ( ! Mimamori_Notification_Service::alert_type_enabled( $_mm_settings, $_type ) ) { continue; }
        $alert_types[] = [
            'type'  => $_type,
            'label' => $_label,
            'on'    => empty( $_mm_type_optout[ $_type ] ),
        ];
    }
}

// AI改善提案通知は AI改善提案プラン以上のみ
$_notif_can_suggest = function_exists( 'mimamori_can' )
    ? mimamori_can( 'improvement_actions', $user_id )
    : false;
$suggest_checklist = ( $_notif_can_suggest && function_exists( 'gcrev_suggestion_accuracy_checklist' ) )
    ? gcrev_suggestion_accuracy_checklist( $user_id )
    : [];

// 見える化プランは月次レポート非対応 → レポート完成通知カードは非表示
$_notif_is_mieruka = function_exists( 'mimamori_is_mieruka_user' )
    ? mimamori_is_mieruka_user( $user_id )
    : false;

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

/* --- アラート種類別サブトグル --- */
.notif-subrows {
    margin: 0 0 4px;
    padding: 4px 0 4px 14px;
    border-left: 2px solid var(--mw-border-light, #e8ecee);
}
.notif-subrows-head {
    font-size: 12px;
    font-weight: 700;
    color: var(--mw-text-secondary, #666);
    margin: 6px 0 2px;
}
.notif-subrow {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 9px 0;
    border-bottom: 1px dashed var(--mw-border-light, #e8ecee);
}
.notif-subrow:last-child { border-bottom: none; }
.notif-subrow-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--mw-text-primary, #2c3e50);
}
.notif-subrows.is-disabled { opacity: 0.45; }
.notif-switch--sm { width: 40px; height: 23px; }
.notif-switch--sm .notif-slider::before { height: 17px; width: 17px; }
.notif-switch--sm input:checked + .notif-slider::before { transform: translateX(17px); }

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

/* --- 精度向上チェックリスト --- */
.notif-checklist {
    margin: 8px 0 0;
    padding: 0;
    list-style: none;
}
.notif-check-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 0;
    border-bottom: 1px solid var(--mw-border-light, #e8ecee);
}
.notif-check-item:last-child { border-bottom: none; }
.notif-check-mark {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
    line-height: 1;
    margin-top: 1px;
}
.notif-check-mark--done {
    background: #4E8A6B;
    color: #fff;
}
.notif-check-mark--todo {
    background: #fff;
    color: #c0392b;
    border: 2px solid #e0b4ae;
}
.notif-check-body { flex: 1; min-width: 0; }
.notif-check-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-primary, #2c3e50);
}
.notif-check-item--done .notif-check-label { color: #6b7c74; }
.notif-check-note {
    font-size: 12px;
    color: var(--mw-text-secondary, #666);
    margin-top: 3px;
    line-height: 1.6;
}
.notif-check-action {
    flex-shrink: 0;
    align-self: center;
    font-size: 12px;
    font-weight: 700;
    color: var(--mw-primary-blue, #568184);
    text-decoration: none;
    white-space: nowrap;
    padding: 6px 12px;
    border: 1px solid var(--mw-primary-blue, #568184);
    border-radius: 6px;
    transition: background 0.2s, color 0.2s;
}
.notif-check-action:hover { background: var(--mw-primary-blue, #568184); color: #fff; }
.notif-check-status {
    flex-shrink: 0;
    align-self: center;
    font-size: 12px;
    font-weight: 700;
    color: #4E8A6B;
    white-space: nowrap;
}
.notif-checklist-msg {
    margin: 18px 0 0;
    padding: 14px 16px;
    background: #f4f8f7;
    border-left: 4px solid var(--mw-primary-blue, #568184);
    border-radius: 6px;
    font-size: 13px;
    line-height: 1.7;
    color: var(--mw-text-primary, #2c3e50);
}
.notif-subhead {
    font-size: 14px;
    font-weight: 700;
    color: var(--mw-text-primary, #2c3e50);
    margin: 22px 0 4px;
}

@media (max-width: 768px) {
    .notif-container { padding: 24px 16px 40px; }
    .notif-card { padding: 20px 16px; }
    .notif-check-action, .notif-check-status { align-self: flex-start; margin-top: 2px; }
}
</style>

<div class="notif-container">

    <!-- みまもりメール通知（全プラン共通） -->
    <div class="notif-card">
        <div class="notif-card-header">
            <h2>みまもりメール通知</h2>
        </div>
        <p class="notif-desc">
            みまもりウェブからの自動メール通知の受信設定です。変更は自動で保存されます。
        </p>

        <div class="notif-row">
            <div>
                <div class="notif-row-label">みまもりアラート</div>
                <div class="notif-row-note">サイトの数値に異常を検知したときに、お知らせメールが届きます。</div>
            </div>
            <label class="notif-switch">
                <input type="checkbox" id="notifyAlertToggle" <?php checked( $alert_on ); ?> />
                <span class="notif-slider"></span>
            </label>
        </div>

        <?php if ( ! empty( $alert_types ) ) : ?>
        <div class="notif-subrows<?php echo $alert_on ? '' : ' is-disabled'; ?>" id="alertTypeRows">
            <div class="notif-subrows-head">受け取るアラートの種類を選べます</div>
            <?php foreach ( $alert_types as $at ) : ?>
            <div class="notif-subrow">
                <span class="notif-subrow-label"><?php echo esc_html( $at['label'] ); ?></span>
                <label class="notif-switch notif-switch--sm">
                    <input type="checkbox" class="js-alert-type"
                           data-type="<?php echo esc_attr( $at['type'] ); ?>"
                           <?php checked( $at['on'] ); ?> <?php disabled( ! $alert_on ); ?> />
                    <span class="notif-slider"></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="notif-row">
            <div>
                <div class="notif-row-label">みまもり週次便</div>
                <div class="notif-row-note">毎週、サイトの状態をまとめたサマリーメールが届きます。</div>
            </div>
            <label class="notif-switch">
                <input type="checkbox" id="notifyDigestToggle" <?php checked( $digest_on ); ?> />
                <span class="notif-slider"></span>
            </label>
        </div>
    </div>

    <?php if ( $_notif_can_suggest ) : // AI改善提案プラン以上のみ ?>
    <div class="notif-card">
        <div class="notif-card-header">
            <h2>AI改善提案通知</h2>
        </div>
        <p class="notif-desc">
            みまもりウェブのAIが見つけた「今やるべき改善施策」を、月に数回メールでお届けします。
        </p>

        <div class="notif-row">
            <div>
                <div class="notif-row-label">改善提案メールを受け取る</div>
                <div class="notif-row-note">優先度の高い改善提案が見つかったときに、お知らせメールが届きます。</div>
            </div>
            <label class="notif-switch">
                <input type="checkbox" id="notifySuggestToggle" <?php checked( $suggest_on ); ?> />
                <span class="notif-slider"></span>
            </label>
        </div>

        <?php if ( ! empty( $suggest_checklist ) ) : ?>
        <div class="notif-subhead">提案の精度を上げるための設定</div>
        <p class="notif-row-note" style="margin-bottom:6px;">
            次の情報が揃っているほど、あなたのサイトに合った精度の高い提案ができます。未対応の項目は「設定する」から登録してください。
        </p>
        <ul class="notif-checklist">
            <?php foreach ( $suggest_checklist as $item ) : ?>
            <li class="notif-check-item <?php echo $item['done'] ? 'notif-check-item--done' : ''; ?>">
                <span class="notif-check-mark <?php echo $item['done'] ? 'notif-check-mark--done' : 'notif-check-mark--todo'; ?>">
                    <?php echo $item['done'] ? '✓' : '!'; ?>
                </span>
                <span class="notif-check-body">
                    <span class="notif-check-label"><?php echo esc_html( $item['label'] ); ?></span>
                    <span class="notif-check-note"><?php echo esc_html( $item['note'] ); ?></span>
                </span>
                <?php if ( $item['done'] ) : ?>
                    <span class="notif-check-status">対応済み</span>
                <?php else : ?>
                    <a class="notif-check-action" href="<?php echo esc_url( $item['url'] ); ?>">設定する</a>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="notif-checklist-msg">
            改善提案の精度を上げるために、上記をなるべく対応するようにしてください。
        </p>
        <?php endif; ?>
    </div>
    <?php endif; // $_notif_can_suggest ?>

    <?php if ( ! $_notif_is_mieruka ) : // 見える化プランは月次レポート非対応 ?>
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
            <button type="button" class="notif-btn" id="notifSave" data-mw-save="1">設定を保存</button>
        </div>
    </div>
    <?php endif; // ! $_notif_is_mieruka ?>

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

    if (saveBtn) saveBtn.addEventListener('click', function() {
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

    // --- みまもりアラート／週次便（変更時に自動保存） ---
    var prefsUrl    = <?php echo wp_json_encode( esc_url_raw( rest_url( 'mimamori/v1/notification-prefs' ) ), JSON_UNESCAPED_UNICODE ); ?>;
    var prefsNonce  = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
    var alertToggle   = document.getElementById('notifyAlertToggle');
    var digestToggle  = document.getElementById('notifyDigestToggle');
    var suggestToggle = document.getElementById('notifySuggestToggle');
    var alertTypeEls  = Array.prototype.slice.call(document.querySelectorAll('.js-alert-type'));
    var alertTypeRows = document.getElementById('alertTypeRows');

    function savePrefs() {
        var payload = {
            alert_enabled:  alertToggle ? alertToggle.checked : true,
            digest_enabled: digestToggle ? digestToggle.checked : true
        };
        if (suggestToggle) { payload.suggest_enabled = suggestToggle.checked; }
        if (alertTypeEls.length) {
            payload.alert_types = {};
            alertTypeEls.forEach(function(el) {
                payload.alert_types[el.getAttribute('data-type')] = el.checked;
            });
        }

        fetch(prefsUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': prefsNonce },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function() { showToast('設定を保存しました'); })
        .catch(function() { showToast('通信エラーが発生しました', 'error'); });
    }

    // みまもりアラート全体OFF時は種類別トグルを無効表示にする
    function syncAlertTypeState() {
        var on = alertToggle ? alertToggle.checked : true;
        alertTypeEls.forEach(function(el) { el.disabled = !on; });
        if (alertTypeRows) { alertTypeRows.classList.toggle('is-disabled', !on); }
    }

    if (alertToggle) alertToggle.addEventListener('change', function() {
        syncAlertTypeState();
        savePrefs();
    });
    if (digestToggle)  digestToggle.addEventListener('change', savePrefs);
    if (suggestToggle) suggestToggle.addEventListener('change', savePrefs);
    alertTypeEls.forEach(function(el) { el.addEventListener('change', savePrefs); });
})();
</script>

<?php get_footer(); ?>
