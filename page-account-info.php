<?php
/*
Template Name: アカウント情報
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// ページタイトル
set_query_var( 'gcrev_page_title', 'アカウント情報' );

// パンくず
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'アカウント情報' ) );

// --- ① 契約プランデータ ---
$steps      = gcrev_get_payment_steps( $user_id );
$dates      = gcrev_get_contract_dates( $user_id );
$plan_defs  = gcrev_get_plan_definitions();

$contract_type = $steps['contract_type'];
$type_label    = ( $contract_type === 'with_site' ) ? '制作込みプラン' : '伴走運用プラン';

$user_plan = get_user_meta( $user_id, 'gcrev_user_plan', true );
$plan_info = isset( $plan_defs[ $user_plan ] ) ? $plan_defs[ $user_plan ] : null;
$plan_name = $plan_info ? $plan_info['name'] : '未選択';
$monthly   = $plan_info ? number_format( $plan_info['monthly'] ) : '—';

$c_status     = $dates['status'];
$has_contract = ! empty( $dates['start_at'] );

// --- ② ユーザー情報データ ---
$acct_company = $current_user->last_name;
$acct_person  = $current_user->first_name;
$acct_email   = $current_user->user_email;

get_header();
?>

<style>
/* =============================================
   page-account-info — Page-specific styles
   ============================================= */

/* --- セクション共通 --- */
.acct-container {
    max-width: 960px;
    margin: 0 auto;
    padding: 32px 32px 48px;
}

.acct-card {
    background: #fff;
    border-radius: var(--mw-radius-md, 10px);
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    padding: 28px 32px;
    margin-bottom: 24px;
}

.acct-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--mw-primary-blue, #568184);
}
.acct-card-header h2 {
    font-size: 17px;
    font-weight: 700;
    color: var(--mw-text-primary, #2c3e50);
    margin: 0;
}

/* --- テーブル共通 --- */
.acct-table {
    width: 100%;
    border-collapse: collapse;
}
.acct-table th,
.acct-table td {
    padding: 14px 16px;
    font-size: 14px;
    border-bottom: 1px solid var(--mw-border-light, #e8ecee);
    text-align: left;
    vertical-align: middle;
}
.acct-table th {
    width: 180px;
    color: var(--mw-text-secondary, #666);
    font-weight: 600;
    background: #fafbfc;
}
.acct-table td {
    color: var(--mw-text-primary, #2c3e50);
}
.acct-table tr:last-child th,
.acct-table tr:last-child td {
    border-bottom: none;
}

/* --- バッジ --- */
.contract-badge {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
}
.contract-badge--active   { color: #4E8A6B; background: rgba(78,138,107,0.08); }
.contract-badge--canceled { color: #C0392B; background: #FDF0EE; }
.contract-badge--none     { color: #888;    background: #f0f0f0; }

/* --- 注釈ボックス --- */
.acct-notice {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 20px;
    background: #fafbfc;
    border: 1px solid var(--mw-border-light, #e8ecee);
    border-radius: 8px;
    font-size: 13px;
    color: var(--mw-text-secondary, #666);
    margin-top: 16px;
}
.acct-notice .notice-icon { font-size: 18px; flex-shrink: 0; }

/* --- ボタン共通 --- */
.acct-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 20px;
    border: none;
    border-radius: var(--mw-radius-sm, 6px);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s, box-shadow 0.2s;
}
.acct-btn-primary {
    background: var(--mw-primary-blue, #568184);
    color: #fff;
}
.acct-btn-primary:hover {
    background: #476C6F;
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
}
.acct-btn-secondary {
    background: #e8ecee;
    color: var(--mw-text-primary, #2c3e50);
}
.acct-btn-secondary:hover {
    background: #dce0e3;
}
.acct-btn-sm {
    padding: 6px 14px;
    font-size: 12px;
}

/* --- ② ユーザー情報 --- */
.acct-field-row {
    display: flex;
    align-items: center;
    padding: 14px 0;
    border-bottom: 1px solid var(--mw-border-light, #e8ecee);
}
.acct-field-row:last-child { border-bottom: none; }
.acct-field-label {
    width: 180px;
    flex-shrink: 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-secondary, #666);
}
.acct-field-value {
    flex: 1;
    font-size: 14px;
    color: var(--mw-text-primary, #2c3e50);
}
.acct-field-input {
    flex: 1;
}
.acct-field-input input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--mw-border-light, #e8ecee);
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
    transition: border-color 0.2s;
}
.acct-field-input input:focus {
    border-color: var(--mw-primary-blue, #568184);
    outline: none;
    box-shadow: 0 0 0 2px rgba(86,129,132,0.12);
}
.acct-field-input .field-error {
    font-size: 12px;
    color: #C0392B;
    margin-top: 4px;
    display: none;
}

.acct-edit-actions {
    display: flex;
    gap: 8px;
    margin-top: 20px;
    justify-content: flex-end;
}

/* --- ③ パスワード変更 --- */
.acct-pw-group {
    margin-bottom: 16px;
}
.acct-pw-group:last-of-type { margin-bottom: 0; }
.acct-pw-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--mw-text-secondary, #666);
    margin-bottom: 6px;
}
.acct-pw-wrap {
    position: relative;
}
.acct-pw-wrap input {
    width: 100%;
    padding: 10px 40px 10px 12px;
    border: 1px solid var(--mw-border-light, #e8ecee);
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
    transition: border-color 0.2s;
}
.acct-pw-wrap input:focus {
    border-color: var(--mw-primary-blue, #568184);
    outline: none;
    box-shadow: 0 0 0 2px rgba(86,129,132,0.12);
}
.acct-pw-toggle {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: #999;
    font-size: 16px;
    padding: 2px;
    line-height: 1;
}
.acct-pw-toggle:hover { color: #666; }
.acct-pw-error {
    font-size: 12px;
    color: #C0392B;
    margin-top: 4px;
    display: none;
}
.acct-pw-actions {
    margin-top: 24px;
}

/* --- トースト --- */
.acct-toast {
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
.acct-toast.show {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
}
.acct-toast--success { background: #4E8A6B; }
.acct-toast--error   { background: #C0392B; }

/* --- レスポンシブ --- */
@media (max-width: 768px) {
    .acct-container {
        padding: 24px 16px 40px;
    }
    .acct-card {
        padding: 20px 16px;
    }
    .acct-table th,
    .acct-table td {
        display: block;
        width: 100%;
        box-sizing: border-box;
    }
    .acct-table th {
        padding-bottom: 2px;
        border-bottom: none;
        font-size: 12px;
    }
    .acct-table td {
        padding-top: 0;
        padding-bottom: 14px;
    }
    .acct-field-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
    .acct-field-label {
        width: auto;
        font-size: 12px;
    }
    .acct-field-value,
    .acct-field-input {
        width: 100%;
    }
}
</style>

<div class="acct-container">

    <!-- =============================================
         ① 契約中プラン
         ============================================= -->
    <div class="acct-card">
        <div class="acct-card-header">
            <h2>契約中プラン</h2>
        </div>

        <table class="acct-table">
            <tbody>
                <tr>
                    <th>プランタイプ</th>
                    <td><?php echo esc_html( $type_label ); ?></td>
                </tr>
                <tr>
                    <th>プラン名</th>
                    <td><?php echo esc_html( $plan_name ); ?></td>
                </tr>
                <tr>
                    <th>月額料金</th>
                    <td><?php echo $plan_info ? '&yen;' . esc_html( $monthly ) : '—'; ?></td>
                </tr>
                <tr>
                    <th>契約ステータス</th>
                    <td>
                        <?php if ( $c_status === 'active' ) : ?>
                            <span class="contract-badge contract-badge--active">利用中</span>
                        <?php elseif ( $c_status === 'canceled' ) : ?>
                            <span class="contract-badge contract-badge--canceled">解約済み</span>
                        <?php else : ?>
                            <span class="contract-badge contract-badge--none">未開始</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ( $has_contract ) : ?>
                <tr>
                    <th>契約開始日</th>
                    <td><?php echo esc_html( wp_date( 'Y年n月j日', strtotime( $dates['start_at'] ) ) ); ?></td>
                </tr>
                <tr>
                    <th>最終更新日</th>
                    <td><?php echo $dates['last_renewed_at'] ? esc_html( wp_date( 'Y年n月j日', strtotime( $dates['last_renewed_at'] ) ) ) : '—'; ?></td>
                </tr>
                <tr>
                    <th>次回更新日</th>
                    <td><?php echo $dates['next_renewal_at'] ? esc_html( wp_date( 'Y年n月j日', strtotime( $dates['next_renewal_at'] ) ) ) : '—'; ?></td>
                </tr>
                <tr>
                    <th>解約可能日</th>
                    <td><?php echo $dates['cancellable_at'] ? esc_html( wp_date( 'Y年n月j日', strtotime( $dates['cancellable_at'] ) ) ) : '—'; ?></td>
                </tr>
                <?php else : ?>
                <tr><th>契約開始日</th><td>—</td></tr>
                <tr><th>最終更新日</th><td>—</td></tr>
                <tr><th>次回更新日</th><td>—</td></tr>
                <tr><th>解約可能日</th><td>—</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( ! $has_contract ) : ?>
        <div class="acct-notice">
            <span class="notice-icon">&#9432;</span>
            <span>決済手続きが完了すると、契約情報が表示されます。</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- =============================================
         ② ユーザー情報
         ============================================= -->
    <div class="acct-card" id="userInfoCard">
        <div class="acct-card-header">
            <h2>ユーザー情報</h2>
            <button type="button" class="acct-btn acct-btn-secondary acct-btn-sm" id="userInfoEditBtn">編集</button>
        </div>

        <!-- 閲覧モード -->
        <div id="userInfoView">
            <div class="acct-field-row">
                <span class="acct-field-label">事業者名</span>
                <span class="acct-field-value" id="viewCompany"><?php echo esc_html( $acct_company ?: '—' ); ?></span>
            </div>
            <div class="acct-field-row">
                <span class="acct-field-label">担当者名</span>
                <span class="acct-field-value" id="viewPerson"><?php echo esc_html( $acct_person ?: '—' ); ?></span>
            </div>
            <div class="acct-field-row">
                <span class="acct-field-label">メールアドレス</span>
                <span class="acct-field-value" id="viewEmail"><?php echo esc_html( $acct_email ); ?></span>
            </div>
        </div>

        <!-- 編集モード -->
        <div id="userInfoEdit" style="display:none;">
            <div class="acct-field-row">
                <span class="acct-field-label">事業者名</span>
                <div class="acct-field-input">
                    <input type="text" id="editCompany" value="<?php echo esc_attr( $acct_company ); ?>" placeholder="例：株式会社○○">
                    <div class="field-error" id="errCompany">事業者名を入力してください</div>
                </div>
            </div>
            <div class="acct-field-row">
                <span class="acct-field-label">担当者名</span>
                <div class="acct-field-input">
                    <input type="text" id="editPerson" value="<?php echo esc_attr( $acct_person ); ?>" placeholder="例：山田 太郎">
                    <div class="field-error" id="errPerson">担当者名を入力してください</div>
                </div>
            </div>
            <div class="acct-field-row">
                <span class="acct-field-label">メールアドレス</span>
                <div class="acct-field-input">
                    <input type="email" id="editEmail" value="<?php echo esc_attr( $acct_email ); ?>" placeholder="例：info@example.com">
                    <div class="field-error" id="errEmail">有効なメールアドレスを入力してください</div>
                </div>
            </div>
            <div class="acct-edit-actions">
                <button type="button" class="acct-btn acct-btn-secondary" id="userInfoCancel">キャンセル</button>
                <button type="button" class="acct-btn acct-btn-primary" id="userInfoSave">保存</button>
            </div>
        </div>
    </div>

    <!-- =============================================
         ③ パスワード変更
         ============================================= -->
    <div class="acct-card">
        <div class="acct-card-header">
            <h2>パスワード変更</h2>
        </div>

        <form id="pwChangeForm" autocomplete="off">
            <div class="acct-pw-group">
                <label for="pwCurrent">現在のパスワード</label>
                <div class="acct-pw-wrap">
                    <input type="password" id="pwCurrent" autocomplete="current-password">
                    <button type="button" class="acct-pw-toggle" data-target="pwCurrent" aria-label="表示切替">👁</button>
                </div>
                <div class="acct-pw-error" id="errPwCurrent">現在のパスワードを入力してください</div>
            </div>
            <div class="acct-pw-group">
                <label for="pwNew">新しいパスワード</label>
                <div class="acct-pw-wrap">
                    <input type="password" id="pwNew" autocomplete="new-password">
                    <button type="button" class="acct-pw-toggle" data-target="pwNew" aria-label="表示切替">👁</button>
                </div>
                <div class="acct-pw-error" id="errPwNew">8文字以上で入力してください</div>
            </div>
            <div class="acct-pw-group">
                <label for="pwConfirm">新しいパスワード（確認）</label>
                <div class="acct-pw-wrap">
                    <input type="password" id="pwConfirm" autocomplete="new-password">
                    <button type="button" class="acct-pw-toggle" data-target="pwConfirm" aria-label="表示切替">👁</button>
                </div>
                <div class="acct-pw-error" id="errPwConfirm">パスワードが一致しません</div>
            </div>
            <div class="acct-pw-actions">
                <button type="submit" class="acct-btn acct-btn-primary">パスワードを変更</button>
            </div>
        </form>
    </div>

</div><!-- .acct-container -->

<!-- トースト -->
<div class="acct-toast" id="acctToast"></div>

<script>
(function() {
    var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
    var nonce   = '<?php echo esc_js( wp_create_nonce( 'gcrev_account_info' ) ); ?>';

    // ========== ユーティリティ ==========
    function showToast(msg, type) {
        var t = document.getElementById('acctToast');
        t.textContent = msg;
        t.className = 'acct-toast acct-toast--' + (type || 'success');
        requestAnimationFrame(function() { t.classList.add('show'); });
        setTimeout(function() { t.classList.remove('show'); }, 3000);
    }

    function showError(id) { document.getElementById(id).style.display = 'block'; }
    function hideError(id) { document.getElementById(id).style.display = 'none'; }
    function hideAllErrors(ids) { ids.forEach(function(id) { hideError(id); }); }

    // ========== ② ユーザー情報 ==========
    var viewEl = document.getElementById('userInfoView');
    var editEl = document.getElementById('userInfoEdit');
    var editBtn = document.getElementById('userInfoEditBtn');

    editBtn.addEventListener('click', function() {
        viewEl.style.display = 'none';
        editEl.style.display = 'block';
        editBtn.style.display = 'none';
    });

    document.getElementById('userInfoCancel').addEventListener('click', function() {
        editEl.style.display = 'none';
        viewEl.style.display = 'block';
        editBtn.style.display = '';
        hideAllErrors(['errCompany', 'errPerson', 'errEmail']);
    });

    document.getElementById('userInfoSave').addEventListener('click', function() {
        var company = document.getElementById('editCompany').value.trim();
        var person  = document.getElementById('editPerson').value.trim();
        var email   = document.getElementById('editEmail').value.trim();
        var valid   = true;

        hideAllErrors(['errCompany', 'errPerson', 'errEmail']);

        if (!company) { showError('errCompany'); valid = false; }
        if (!person)  { showError('errPerson');  valid = false; }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showError('errEmail'); valid = false; }
        if (!valid) return;

        var btn = this;
        btn.disabled = true;
        btn.textContent = '保存中...';

        var fd = new FormData();
        fd.append('action', 'gcrev_save_account_info');
        fd.append('nonce', nonce);
        fd.append('company', company);
        fd.append('person', person);
        fd.append('email', email);

        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btn.disabled = false;
                btn.textContent = '保存';
                if (res.success) {
                    document.getElementById('viewCompany').textContent = company;
                    document.getElementById('viewPerson').textContent  = person;
                    document.getElementById('viewEmail').textContent   = email;
                    editEl.style.display = 'none';
                    viewEl.style.display = 'block';
                    editBtn.style.display = '';
                    showToast('保存しました');
                } else {
                    showToast(res.data || '保存に失敗しました', 'error');
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.textContent = '保存';
                showToast('通信エラーが発生しました', 'error');
            });
    });

    // ========== ③ パスワード変更 ==========
    document.querySelectorAll('.acct-pw-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = document.getElementById(this.dataset.target);
            if (input.type === 'password') {
                input.type = 'text';
                this.textContent = '🔒';
            } else {
                input.type = 'password';
                this.textContent = '👁';
            }
        });
    });

    document.getElementById('pwChangeForm').addEventListener('submit', function(e) {
        e.preventDefault();

        var current = document.getElementById('pwCurrent').value;
        var newPw   = document.getElementById('pwNew').value;
        var confirm = document.getElementById('pwConfirm').value;
        var valid   = true;

        hideAllErrors(['errPwCurrent', 'errPwNew', 'errPwConfirm']);

        if (!current) { showError('errPwCurrent'); valid = false; }
        if (!newPw || newPw.length < 8) { showError('errPwNew'); valid = false; }
        if (newPw !== confirm) { showError('errPwConfirm'); valid = false; }
        if (!valid) return;

        var btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = '変更中...';

        var fd = new FormData();
        fd.append('action', 'gcrev_change_password');
        fd.append('nonce', nonce);
        fd.append('current_password', current);
        fd.append('new_password', newPw);

        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                btn.disabled = false;
                btn.textContent = 'パスワードを変更';
                if (res.success) {
                    document.getElementById('pwCurrent').value = '';
                    document.getElementById('pwNew').value     = '';
                    document.getElementById('pwConfirm').value = '';
                    showToast('パスワードを変更しました');
                } else {
                    showToast(res.data || 'パスワードの変更に失敗しました', 'error');
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.textContent = 'パスワードを変更';
                showToast('通信エラーが発生しました', 'error');
            });
    });
})();
</script>

<?php get_footer(); ?>
