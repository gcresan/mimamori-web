<?php
/*
Template Name: MEOダッシュボード
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

mimamori_guard_meo_access();

$current_user = mimamori_get_view_user_object();
$user_id = mimamori_get_view_user_id();

// ページタイトル設定
set_query_var('gcrev_page_title', 'MEOダッシュボード');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('MEOダッシュボード', 'MEO'));

// ===== GBP接続状態判定（class-gcrev-api.php経由） =====
global $gcrev_api_instance;
if ( ! isset($gcrev_api_instance) || ! ($gcrev_api_instance instanceof Gcrev_Insight_API) ) {
    $gcrev_api_instance = new Gcrev_Insight_API(false);
}
$gcrev_api = $gcrev_api_instance;

$gbp_status    = $gcrev_api->gbp_get_connection_status($user_id);
$is_connected  = $gbp_status['connected'];
$needs_reauth  = $gbp_status['needs_reauth'];

// ===== ロケーション設定確認 =====
// ?meo_reset=1 でロケーション情報とキャッシュをリセット
if (isset($_GET['meo_reset']) && $_GET['meo_reset'] === '1' && current_user_can('manage_options')) {
    delete_user_meta($user_id, '_gcrev_gbp_location_id');
    delete_user_meta($user_id, '_gcrev_gbp_location_address');
    delete_user_meta($user_id, '_gcrev_gbp_location_name');
    delete_user_meta($user_id, '_gcrev_gbp_location_radius');
    // MEOキャッシュ削除
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_gcrev_meo_' . $user_id . '%'
    ));
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_timeout_gcrev_meo_' . $user_id . '%'
    ));
    wp_safe_redirect(remove_query_arg('meo_reset'));
    exit;
}

$location_id      = get_user_meta($user_id, '_gcrev_gbp_location_id', true);
$location_address = get_user_meta($user_id, '_gcrev_gbp_location_address', true);
$has_location     = !empty($location_address);
// 住所が登録済みであればダッシュボードを表示する

get_header();
?>

<!-- コンテンツエリア -->
<div class="content-area">

<?php if ( ! $is_connected || $needs_reauth ):
    $gbp_auth_url = $gcrev_api->gbp_get_auth_url($user_id);
?>
    <!-- ===== 未接続 or 再認証必要：接続ボタンのみ表示 ===== -->
    <div style="text-align: center; padding: 80px 20px;">
        <div style="font-size: 56px; margin-bottom: 24px;">📍</div>

        <?php if ( $needs_reauth ): ?>
            <h3 style="font-size: 22px; font-weight: 600; color: #333; margin-bottom: 12px;">
                Googleビジネスプロフィールの接続が切れています
            </h3>
            <p style="color: #666; margin-bottom: 32px; max-width: 480px; margin-left: auto; margin-right: auto;">
                アクセストークンの更新に失敗しました。<br>
                再接続して、MEOダッシュボードをご利用ください。
            </p>
        <?php else: ?>
            <h3 style="font-size: 22px; font-weight: 600; color: #333; margin-bottom: 12px;">
                MEOダッシュボードを利用するには、<br>Googleビジネスプロフィールとの接続が必要です
            </h3>
            <p style="color: #666; margin-bottom: 32px; max-width: 480px; margin-left: auto; margin-right: auto;">
                お店のGoogleビジネスプロフィール（旧Googleマイビジネス）と連携すると、<br>
                表示回数・検索キーワード・クリック数などをダッシュボードで確認できます。
            </p>
        <?php endif; ?>

        <?php if ( ! empty($gbp_auth_url) ): ?>
            <a href="<?php echo esc_url($gbp_auth_url); ?>"
               class="btn btn-primary btn-lg"
               style="min-width: 300px; display: inline-block; padding: 16px 32px; background: #568184; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px;">
                <?php echo $needs_reauth ? '🔄 Googleビジネスプロフィールと再接続' : '📍 Googleビジネスプロフィールと接続'; ?>
            </a>
        <?php else: ?>
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px 24px; max-width: 480px; margin: 0 auto; color: #991b1b; font-size: 14px;">
                GBP OAuth の設定が未完了です。管理者に連絡してください。
            </div>
        <?php endif; ?>
    </div>

<?php elseif ( ! $has_location ): ?>
    <!-- ===== ロケーション未設定：GBPから自動取得 + 選択 ===== -->
    <div style="max-width: 700px; margin: 40px auto; padding: 0 20px;">
        <div style="background: #fff; border-radius: 16px; padding: 40px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <h3 id="gbp-loc-heading" style="font-size: 20px; font-weight: 700; color: #2C3E40; text-align: center; margin-bottom: 12px;">
                📍 Googleビジネスプロフィールからロケーションを取得中...
            </h3>
            <p id="gbp-loc-status" style="text-align: center; color: #666; margin-bottom: 24px;">接続先のGBPアカウントからロケーション一覧を読み込んでいます...</p>

            <!-- ロケーション一覧表示エリア -->
            <div id="gbp-loc-list" style="display: none;"></div>

            <!-- エラー時メッセージ -->
            <div id="gbp-loc-error" style="display: none; background: #fef2f2; border-radius: 8px; padding: 16px; margin-bottom: 20px; color: #C0392B; font-size: 14px;"></div>

            <!-- 選択結果メッセージ -->
            <div id="gbp-loc-message" style="display: none; margin-top: 20px; padding: 12px 16px; border-radius: 8px; font-size: 14px; text-align: center;"></div>

            <!-- フォールバック：手動入力（非表示→ エラー時に表示） -->
            <div id="gbp-loc-manual" style="display: none; margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 24px;">
                <h4 style="font-size: 15px; font-weight: 600; color: #555; margin-bottom: 16px;">手動でロケーション情報を入力</h4>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 6px;">ロケーションID</label>
                    <input type="text" id="manual-loc-id" placeholder="例：12345678901234567"
                           style="width: 100%; padding: 10px 14px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                    <div style="font-size: 11px; color: #888; margin-top: 4px;">Googleビジネスプロフィールの管理画面URLに含まれる数字</div>
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 6px;">店舗名</label>
                    <input type="text" id="manual-loc-title" placeholder="例：株式会社ジィクレブ"
                           style="width: 100%; padding: 10px 14px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 6px;">住所</label>
                    <input type="text" id="manual-loc-address" placeholder="例：愛媛県松山市三番町7丁目12-1"
                           style="width: 100%; padding: 10px 14px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                </div>
                <div style="text-align: center;">
                    <button onclick="gcrevSubmitManualLocation()"
                            id="manual-loc-btn"
                            style="padding: 12px 32px; background: #568184; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer;">
                        設定
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var GBP_LOC_API   = '<?php echo esc_js(rest_url("gcrev/v1/meo/gbp-locations")); ?>';
        var SELECT_API    = '<?php echo esc_js(rest_url("gcrev/v1/meo/select-location")); ?>';
        var WP_NONCE      = '<?php echo esc_js(wp_create_nonce("wp_rest")); ?>';

        function escHtml(str) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(str || ''));
            return d.innerHTML;
        }
        function escAttr(str) {
            return (str || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
        }

        // ページ読み込み時に自動取得
        (async function() {
            var headingEl = document.getElementById('gbp-loc-heading');
            var statusEl  = document.getElementById('gbp-loc-status');
            var listEl    = document.getElementById('gbp-loc-list');
            var errorEl   = document.getElementById('gbp-loc-error');
            var manualEl  = document.getElementById('gbp-loc-manual');

            try {
                var response = await fetch(GBP_LOC_API, {
                    headers: { 'X-WP-Nonce': WP_NONCE },
                    credentials: 'same-origin'
                });
                var result = await response.json();

                if (!result.success || !result.locations || result.locations.length === 0) {
                    headingEl.textContent = '📍 ロケーションの設定';
                    statusEl.textContent = '';
                    errorEl.style.display = 'block';
                    errorEl.textContent = result.message || 'ロケーションが見つかりません。Google Cloud Console で My Business Account Management API と My Business Business Information API が有効化されているか確認してください。';
                    manualEl.style.display = 'block';
                    return;
                }

                // ロケーション一覧を表示
                headingEl.textContent = '📍 ロケーションを選択してください';
                statusEl.textContent = '以下のロケーションが見つかりました。設定するロケーションを選択してください。';
                listEl.style.display = 'block';

                var html = '';
                result.locations.forEach(function(loc) {
                    html += '<div style="border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">';
                    html += '  <div style="flex: 1; min-width: 200px;">';
                    html += '    <div style="font-size: 16px; font-weight: 700; color: #2C3E40; margin-bottom: 4px;">' + escHtml(loc.title) + '</div>';
                    html += '    <div style="font-size: 13px; color: #666;">' + escHtml(loc.address) + '</div>';
                    html += '    <div style="font-size: 11px; color: #999; margin-top: 4px;">ID: ' + escHtml(loc.location_id) + '</div>';
                    html += '  </div>';
                    html += '  <button onclick="gcrevSelectLocation(\'' + escAttr(loc.location_id) + '\', \'' + escAttr(loc.title) + '\', \'' + escAttr(loc.address) + '\', this)"';
                    html += '    style="padding: 10px 24px; background: #568184; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap;"';
                    html += '    onmouseover="this.style.background=\'#476C6F\'" onmouseout="this.style.background=\'#568184\'">';
                    html += '    この店舗を設定';
                    html += '  </button>';
                    html += '</div>';
                });
                listEl.innerHTML = html;

            } catch (error) {
                headingEl.textContent = '📍 ロケーションの設定';
                statusEl.textContent = '';
                errorEl.style.display = 'block';
                errorEl.textContent = 'ロケーション取得中にエラーが発生しました: ' + error.message;
                manualEl.style.display = 'block';
            }
        })();

        // ロケーション選択
        window.gcrevSelectLocation = async function(locationId, title, address, btn) {
            var msgEl = document.getElementById('gbp-loc-message');
            btn.disabled = true;
            btn.textContent = '設定中...';
            btn.style.background = '#93c5fd';
            msgEl.style.display = 'none';

            try {
                var response = await fetch(SELECT_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WP_NONCE },
                    credentials: 'same-origin',
                    body: JSON.stringify({ location_id: locationId, title: title, address: address })
                });
                var result = await response.json();
                if (result.success) {
                    msgEl.style.display = 'block';
                    msgEl.style.background = result.verified ? '#f0fdf4' : '#fffbeb';
                    msgEl.style.color = result.verified ? '#16a34a' : '#B8941E';
                    msgEl.textContent = (result.verified ? '✅ ' : '⚠️ ') + result.message + '　ページをリロードします...';
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    throw new Error(result.message || '設定に失敗しました');
                }
            } catch (error) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#fef2f2';
                msgEl.style.color = '#C0392B';
                msgEl.textContent = 'エラー: ' + error.message;
                btn.disabled = false;
                btn.textContent = 'この店舗を設定';
                btn.style.background = '#568184';
            }
        };

        // 手動入力
        window.gcrevSubmitManualLocation = async function() {
            var locId   = document.getElementById('manual-loc-id').value.trim();
            var title   = document.getElementById('manual-loc-title').value.trim();
            var address = document.getElementById('manual-loc-address').value.trim();
            var btn     = document.getElementById('manual-loc-btn');
            var msgEl   = document.getElementById('gbp-loc-message');

            if (!locId) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#fef2f2';
                msgEl.style.color = '#C0392B';
                msgEl.textContent = 'ロケーションIDは必須です。';
                return;
            }
            if (/^\d+$/.test(locId)) { locId = 'locations/' + locId; }

            btn.disabled = true;
            btn.textContent = '設定中...';
            msgEl.style.display = 'none';

            try {
                var response = await fetch(SELECT_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WP_NONCE },
                    credentials: 'same-origin',
                    body: JSON.stringify({ location_id: locId, title: title, address: address })
                });
                var result = await response.json();
                if (result.success) {
                    msgEl.style.display = 'block';
                    msgEl.style.background = result.verified ? '#f0fdf4' : '#fffbeb';
                    msgEl.style.color = result.verified ? '#16a34a' : '#B8941E';
                    msgEl.textContent = (result.verified ? '✅ ' : '⚠️ ') + result.message + '　ページをリロードします...';
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    throw new Error(result.message || '設定に失敗しました');
                }
            } catch (error) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#fef2f2';
                msgEl.style.color = '#C0392B';
                msgEl.textContent = 'エラー: ' + error.message;
                btn.disabled = false;
                btn.textContent = '設定';
            }
        };
    })();
    </script>

<?php else: ?>
    <!-- ===== 接続済み：MEOダッシュボード表示 ===== -->

    <?php
    $is_pending_location = (strpos($location_id, 'pending_') === 0);
    if ($is_pending_location):
    ?>
    <!-- ロケーションID設定バナー -->
    <div style="background: #fffbeb; border: 1px solid #D4A842; border-radius: 12px; padding: 24px; margin-bottom: 24px;">
        <h4 style="font-size: 16px; font-weight: 700; color: #92400e; margin-bottom: 8px;">⚠️ GBPロケーションIDを設定してください</h4>
        <p style="font-size: 13px; color: #78350f; margin-bottom: 16px; line-height: 1.6;">
            ロケーションIDが未設定のため、データを取得できません。<br>
            「GBPから自動取得」ボタンでロケーションを取得するか、手動でIDを入力してください。
        </p>

        <!-- 自動取得ボタン -->
        <div style="margin-bottom: 16px;">
            <button id="meo-auto-fetch-btn" onclick="gcrevAutoFetchLocation()"
                    style="padding: 10px 24px; background: #568184; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap;"
                    onmouseover="this.style.background='#476C6F'" onmouseout="this.style.background='#568184'">
                📍 GBPから自動取得
            </button>
        </div>

        <!-- 自動取得結果表示エリア -->
        <div id="meo-auto-loc-list" style="display: none; margin-bottom: 16px;"></div>

        <!-- 手動入力（折りたたみ） -->
        <details style="margin-top: 12px;">
            <summary style="cursor: pointer; font-size: 13px; color: #78350f; font-weight: 600;">手動でロケーションIDを入力する</summary>
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-top: 12px;">
                <input type="text" id="meo-location-id-input"
                       placeholder="例：12345678901234567"
                       style="flex: 1; min-width: 200px; padding: 10px 14px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;"
                       onfocus="this.style.borderColor='#568184'" onblur="this.style.borderColor='#D0D5DA'">
                <button id="meo-set-location-btn" onclick="gcrevSetManualLocationId()"
                        style="padding: 10px 24px; background: #D4A842; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap;"
                        onmouseover="this.style.background='#B8941E'" onmouseout="this.style.background='#D4A842'">
                    設定
                </button>
            </div>
        </details>

        <div id="meo-locid-message" style="display: none; margin-top: 12px; padding: 8px 12px; border-radius: 6px; font-size: 13px;"></div>
    </div>
    <script>
    (function() {
        var GBP_LOC_API = '<?php echo esc_js(rest_url("gcrev/v1/meo/gbp-locations")); ?>';
        var SELECT_API  = '<?php echo esc_js(rest_url("gcrev/v1/meo/select-location")); ?>';
        var LOCID_API   = '<?php echo esc_js(rest_url("gcrev/v1/meo/location-id")); ?>';
        var WP_NONCE    = '<?php echo esc_js(wp_create_nonce("wp_rest")); ?>';

        function escHtml(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }
        function escAttr(s) { return (s || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"'); }

        // 自動取得
        window.gcrevAutoFetchLocation = async function() {
            var btn    = document.getElementById('meo-auto-fetch-btn');
            var listEl = document.getElementById('meo-auto-loc-list');
            var msgEl  = document.getElementById('meo-locid-message');
            btn.disabled = true;
            btn.textContent = '取得中...';
            btn.style.background = '#93c5fd';
            msgEl.style.display = 'none';
            listEl.style.display = 'none';

            try {
                var response = await fetch(GBP_LOC_API, {
                    headers: { 'X-WP-Nonce': WP_NONCE },
                    credentials: 'same-origin'
                });
                var result = await response.json();

                if (!result.success || !result.locations || result.locations.length === 0) {
                    msgEl.style.display = 'block';
                    msgEl.style.background = '#fef2f2';
                    msgEl.style.color = '#C0392B';
                    msgEl.textContent = result.message || 'ロケーションが見つかりません。';
                    btn.disabled = false;
                    btn.textContent = '📍 GBPから自動取得';
                    btn.style.background = '#568184';
                    return;
                }

                // ロケーション一覧表示
                listEl.style.display = 'block';
                var html = '';
                result.locations.forEach(function(loc) {
                    html += '<div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: #fff;">';
                    html += '  <div style="flex: 1; min-width: 180px;">';
                    html += '    <div style="font-weight: 700; color: #2C3E40;">' + escHtml(loc.title) + '</div>';
                    html += '    <div style="font-size: 12px; color: #666;">' + escHtml(loc.address) + '</div>';
                    html += '  </div>';
                    html += '  <button onclick="gcrevSelectPendingLocation(\'' + escAttr(loc.location_id) + '\', \'' + escAttr(loc.title) + '\', \'' + escAttr(loc.address) + '\', this)"';
                    html += '    style="padding: 8px 20px; background: #568184; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">';
                    html += '    選択';
                    html += '  </button>';
                    html += '</div>';
                });
                listEl.innerHTML = html;
                btn.style.display = 'none';

            } catch (error) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#fef2f2';
                msgEl.style.color = '#C0392B';
                msgEl.textContent = 'エラー: ' + error.message;
                btn.disabled = false;
                btn.textContent = '📍 GBPから自動取得';
                btn.style.background = '#568184';
            }
        };

        // 自動取得からの選択
        window.gcrevSelectPendingLocation = async function(locationId, title, address, btn) {
            var msgEl = document.getElementById('meo-locid-message');
            btn.disabled = true;
            btn.textContent = '設定中...';
            msgEl.style.display = 'none';

            try {
                var response = await fetch(SELECT_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WP_NONCE },
                    credentials: 'same-origin',
                    body: JSON.stringify({ location_id: locationId, title: title, address: address })
                });
                var result = await response.json();
                if (result.success) {
                    msgEl.style.display = 'block';
                    msgEl.style.background = result.verified ? '#f0fdf4' : '#fffbeb';
                    msgEl.style.color = result.verified ? '#16a34a' : '#B8941E';
                    msgEl.textContent = (result.verified ? '✅ ' : '⚠️ ') + result.message + '　ページをリロードします...';
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    throw new Error(result.message || '設定に失敗しました');
                }
            } catch (error) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#fef2f2';
                msgEl.style.color = '#C0392B';
                msgEl.textContent = 'エラー: ' + error.message;
                btn.disabled = false;
                btn.textContent = '選択';
            }
        };

        // 手動入力
        window.gcrevSetManualLocationId = async function() {
            var input = document.getElementById('meo-location-id-input').value.trim();
            var msgEl = document.getElementById('meo-locid-message');
            var btn   = document.getElementById('meo-set-location-btn');

            if (!input) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#fef2f2';
                msgEl.style.color = '#C0392B';
                msgEl.textContent = 'ロケーションIDを入力してください';
                return;
            }

            btn.disabled = true;
            btn.textContent = '設定中...';
            msgEl.style.display = 'none';

            try {
                var response = await fetch(LOCID_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WP_NONCE },
                    credentials: 'same-origin',
                    body: JSON.stringify({ location_id: input })
                });
                var result = await response.json();
                if (result.success) {
                    msgEl.style.display = 'block';
                    if (result.verified) {
                        msgEl.style.background = '#f0fdf4';
                        msgEl.style.color = '#16a34a';
                        msgEl.textContent = '✅ ' + result.message + '　ページをリロードします...';
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        msgEl.style.background = '#fffbeb';
                        msgEl.style.color = '#B8941E';
                        msgEl.textContent = '⚠️ ' + result.message;
                        btn.disabled = false;
                        btn.textContent = '設定';
                    }
                } else {
                    throw new Error(result.message || '設定に失敗しました');
                }
            } catch (error) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#fef2f2';
                msgEl.style.color = '#C0392B';
                msgEl.textContent = 'エラー: ' + error.message;
                btn.disabled = false;
                btn.textContent = '設定';
            }
        };
    })();
    </script>
    <?php endif; ?>

    <!-- ローディングオーバーレイ（標準パターン） -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>データを取得中...</p>
        </div>
    </div>

    <!-- 期間選択（共通コンポーネント） -->
    <?php
    set_query_var('gcrev_period_selector', [
        'id'      => 'meo-period',
        'items'   => [
            ['value' => 'last30',          'label' => '直近30日'],
            ['value' => 'prev-month',      'label' => '前月'],
            ['value' => 'prev-prev-month', 'label' => '前々月'],
            ['value' => 'last90',          'label' => '過去90日'],
            ['value' => 'last180',         'label' => '過去半年'],
            ['value' => 'last365',         'label' => '過去1年'],
        ],
        'default' => 'last30',
    ]);
    get_template_part('template-parts/period-selector');
    ?>

    <!-- 期間表示 -->
    <div class="period-display" id="periodDisplay">
        分析対象期間を選択してください
    </div>

    <!-- メインコンテンツ（データ読み込み後に表示） -->
    <div id="meo-main-content">

        <!-- サマリーカード：表示回数系 -->
        <div class="meo-summary-grid" id="meoSummaryCards">
            <button type="button" class="meo-summary-card is-active" data-metric="total-impressions" data-label="表示回数" aria-pressed="true">
                <div class="meo-card-header">
                    <div class="meo-card-icon" style="background: #dbeafe; color: #3b82f6;">👁️</div>
                    <div class="meo-card-label">表示回数</div>
                </div>
                <div class="meo-card-value" id="meo-total-impressions">---</div>
                <div class="meo-card-change" id="meo-total-impressions-change">---</div>
                <span class="meo-card-hint">クリックでグラフ切替</span>
            </button>
            <button type="button" class="meo-summary-card" data-metric="mobile-impressions" data-label="モバイル" aria-pressed="false">
                <div class="meo-card-header">
                    <div class="meo-card-icon" style="background: #d1fae5; color: #10b981;">📱</div>
                    <div class="meo-card-label">モバイル</div>
                </div>
                <div class="meo-card-value" id="meo-mobile-impressions">---</div>
                <div class="meo-card-change" id="meo-mobile-impressions-change">---</div>
                <span class="meo-card-hint">クリックでグラフ切替</span>
            </button>
            <button type="button" class="meo-summary-card" data-metric="desktop-impressions" data-label="PC" aria-pressed="false">
                <div class="meo-card-header">
                    <div class="meo-card-icon" style="background: #dbeafe; color: #2563eb;">🖥️</div>
                    <div class="meo-card-label">PC</div>
                </div>
                <div class="meo-card-value" id="meo-desktop-impressions">---</div>
                <div class="meo-card-change" id="meo-desktop-impressions-change">---</div>
                <span class="meo-card-hint">クリックでグラフ切替</span>
            </button>
            <button type="button" class="meo-summary-card" data-metric="click-rate" data-label="平均クリック率" aria-pressed="false">
                <div class="meo-card-header">
                    <div class="meo-card-icon" style="background: #fef3c7; color: #f59e0b;">📈</div>
                    <div class="meo-card-label">平均クリック率</div>
                </div>
                <div class="meo-card-value" id="meo-click-rate">---</div>
                <div class="meo-card-change" id="meo-click-rate-change">---</div>
                <span class="meo-card-hint">クリックでグラフ切替</span>
            </button>
            <button type="button" class="meo-summary-card" data-metric="calls" data-label="電話クリック数" aria-pressed="false">
                <div class="meo-card-header">
                    <div class="meo-card-icon" style="background: #fef3c7; color: #d97706;">📞</div>
                    <div class="meo-card-label">電話クリック数</div>
                </div>
                <div class="meo-card-value" id="meo-calls">---</div>
                <div class="meo-card-change" id="meo-calls-change">---</div>
                <span class="meo-card-hint">クリックでグラフ切替</span>
            </button>
            <button type="button" class="meo-summary-card" data-metric="directions" data-label="ルート検索回数" aria-pressed="false">
                <div class="meo-card-header">
                    <div class="meo-card-icon" style="background: #fee2e2; color: #ef4444;">📍</div>
                    <div class="meo-card-label">ルート検索回数</div>
                </div>
                <div class="meo-card-value" id="meo-directions">---</div>
                <div class="meo-card-change" id="meo-directions-change">---</div>
                <span class="meo-card-hint">クリックでグラフ切替</span>
            </button>
            <button type="button" class="meo-summary-card" data-metric="website" data-label="ウェブサイトクリック数" aria-pressed="false">
                <div class="meo-card-header">
                    <div class="meo-card-icon" style="background: #cffafe; color: #06b6d4;">🌐</div>
                    <div class="meo-card-label">ウェブサイトクリック数</div>
                </div>
                <div class="meo-card-value" id="meo-website">---</div>
                <div class="meo-card-change" id="meo-website-change">---</div>
                <span class="meo-card-hint">クリックでグラフ切替</span>
            </button>
            <button type="button" class="meo-summary-card" data-metric="menu" data-label="メニュークリック数" aria-pressed="false">
                <div class="meo-card-header">
                    <div class="meo-card-icon" style="background: #fce7f3; color: #ec4899;">☰</div>
                    <div class="meo-card-label">メニュークリック数</div>
                </div>
                <div class="meo-card-value" id="meo-menu">---</div>
                <div class="meo-card-change" id="meo-menu-change">---</div>
                <span class="meo-card-hint">クリックでグラフ切替</span>
            </button>
        </div>

        <!-- 指標推移グラフ（カード選択に連動） -->
        <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div id="meo-metric-chart-title" style="font-size: 18px; font-weight: 700; color: #2C3E40;">📈 表示回数の推移</div>
            </div>
            <div id="meo-impressions-legend" style="display: flex; gap: 24px; flex-wrap: wrap; margin-bottom: 16px; padding: 14px 18px; background: #f8fafa; border-radius: 8px; border: 1px solid #e8eeee;">
                <div style="display: flex; align-items: flex-start; gap: 10px; flex: 1; min-width: 220px;">
                    <span style="display: inline-block; width: 14px; height: 14px; border-radius: 3px; background: #568184; flex-shrink: 0; margin-top: 3px;"></span>
                    <div>
                        <div style="font-size: 13px; font-weight: 700; color: #2C3E40; margin-bottom: 2px;">検索での表示</div>
                        <div style="font-size: 12px; color: #777; line-height: 1.6;">Google検索であなたのビジネス情報が表示された回数です。<br>「地域名＋業種」などで検索した際に、検索結果にビジネスプロフィールが出た回数を表します。</div>
                    </div>
                </div>
                <div style="display: flex; align-items: flex-start; gap: 10px; flex: 1; min-width: 220px;">
                    <span style="display: inline-block; width: 14px; height: 14px; border-radius: 3px; background: #E8964D; flex-shrink: 0; margin-top: 3px;"></span>
                    <div>
                        <div style="font-size: 13px; font-weight: 700; color: #2C3E40; margin-bottom: 2px;">マップでの表示</div>
                        <div style="font-size: 12px; color: #777; line-height: 1.6;">Googleマップであなたのビジネス情報が表示された回数です。<br>マップ上で周辺を探している人に、お店や会社の情報が表示された回数を表します。</div>
                    </div>
                </div>
            </div>
            <div style="height: 300px;">
                <canvas id="meo-metric-chart"></canvas>
            </div>
        </div>

        <!-- アクション内訳グラフ -->
        <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div style="font-size: 18px; font-weight: 700; color: #2C3E40;">📊 アクション内訳</div>
            </div>
            <div style="height: 300px;">
                <canvas id="meo-actions-chart"></canvas>
            </div>
        </div>

        <!-- 検索キーワード（月別時系列） -->
        <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; overflow-x: auto;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                <div style="font-size: 18px; font-weight: 700; color: #2C3E40;">🔍 見つけられた検索語句</div>
                <a href="<?php echo esc_url( home_url('/meo/meo-search-terms/') ); ?>" style="font-size: 13px; color: #568184; text-decoration: none; font-weight: 600;">もっと見る →</a>
            </div>
            <table id="meo-keywords-table" style="width: 100%; border-collapse: collapse;">
                <thead id="meo-keywords-head"></thead>
                <tbody id="meo-keywords-body">
                    <tr><td colspan="2" style="padding: 24px; text-align: center; color: #888888;">データを読み込み中...</td></tr>
                </tbody>
            </table>
        </div>

    </div><!-- #meo-main-content -->

<?php endif; ?>


</div><!-- .content-area -->

<?php if ( $is_connected && ! $needs_reauth && $has_location ): ?>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
// =============================================
// MEOダッシュボード：実データ連携
// deviceページと同一のREST/nonce/JSパターン
// =============================================
(function() {
    'use strict';

    // REST API設定（deviceページと同一パターン）
    const REST_URL    = '<?php echo esc_js(rest_url("gcrev/v1/meo/dashboard")); ?>';
    const WP_NONCE    = '<?php echo wp_create_nonce("wp_rest"); ?>';
    let currentPeriod = 'last30';
    let currentData   = null;

    // Chart.jsインスタンス
    let metricChart      = null;
    let actionsChart     = null;
    let selectedMetric   = 'total-impressions'; // デフォルト選択

    // ===== ローディング制御（標準パターン） =====
    function showLoading() {
        var overlay = document.getElementById('loadingOverlay');
        if (overlay) overlay.classList.add('active');
    }
    function hideLoading() {
        var overlay = document.getElementById('loadingOverlay');
        if (overlay) overlay.classList.remove('active');
    }

    // ===== 期間セレクター（共通コンポーネント連携） =====
    var selectorEl = document.getElementById('meo-period');
    if (selectorEl) {
        selectorEl.addEventListener('gcrev:periodChange', function(e) {
            var period = e.detail && e.detail.period ? e.detail.period : null;
            if (!period || period === currentPeriod) return;
            currentPeriod = period;
            loadData(currentPeriod);
        });
    }

    // ===== カード選択ハンドラー =====
    var cardsContainer = document.getElementById('meoSummaryCards');
    if (cardsContainer) {
        cardsContainer.addEventListener('click', function(e) {
            var card = e.target.closest('.meo-summary-card');
            if (!card) return;
            var metric = card.dataset.metric;
            if (!metric || metric === selectedMetric) return;
            selectedMetric = metric;
            // アクティブ状態を更新
            cardsContainer.querySelectorAll('.meo-summary-card').forEach(function(c) {
                c.classList.toggle('is-active', c.dataset.metric === metric);
                c.setAttribute('aria-pressed', c.dataset.metric === metric ? 'true' : 'false');
            });
            // グラフを再描画
            if (currentData) updateMetricChart(currentData);
        });
    }

    // ===== データ取得（キャッシュ優先 + fetch） =====
    async function loadData(period) {
        // キャッシュチェック（ローディングなしで即表示）
        var cacheKey = 'meo_dash_' + period;
        var cached = window.gcrevCache && window.gcrevCache.get(cacheKey);
        if (cached) {
            currentData = cached;
            updatePeriodDisplay(currentData);
            updateSummaryCards(currentData);
            updateKeywordsTable(currentData);
            updateMetricChart(currentData);
            updateActionsChart(currentData);
            return;
        }

        showLoading();

        try {
            var apiUrl = REST_URL + '?period=' + encodeURIComponent(period);
            var controller = new AbortController();
            var timeoutId = setTimeout(function() { controller.abort(); }, 120000); // 2分タイムアウト
            var response = await fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': WP_NONCE
                },
                credentials: 'same-origin',
                signal: controller.signal
            });
            clearTimeout(timeoutId);

            var result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'データ取得に失敗しました');
            }

            currentData = result;

            // キャッシュに保存
            if (window.gcrevCache) window.gcrevCache.set(cacheKey, currentData);

            // UI更新
            updatePeriodDisplay(currentData);
            updateSummaryCards(currentData);
            updateKeywordsTable(currentData);
            updateMetricChart(currentData);
            updateActionsChart(currentData);

        } catch (error) {
            console.error('[MEO] データ取得エラー:', error);
            var pdEl = document.getElementById('periodDisplay');
            if (pdEl) pdEl.innerHTML = '<span style="color:#dc2626;">データ取得に失敗しました。再読み込みしてください。</span>';
        } finally {
            hideLoading();
        }
    }

    // ===== 期間表示更新（標準パターン） =====
    function updatePeriodDisplay(data) {
        var cur = data.current_range_label || '---';
        var cmp = data.compare_range_label || '---';

        // 共通ユーティリティがあれば使用
        if (window.GCREV && typeof window.GCREV.updatePeriodDisplay === 'function') {
            window.GCREV.updatePeriodDisplay(data, { periodDisplayId: 'periodDisplay' });
            return;
        }

        // フォールバック
        var el = document.getElementById('periodDisplay');
        if (el) {
            el.innerHTML =
                '<div class="period-item">' +
                '  <span class="period-label-v2">&#x1F4C5; 分析対象期間：</span>' +
                '  <span class="period-value">' + cur + '</span>' +
                '</div>' +
                '<div class="period-divider"></div>' +
                '<div class="period-item">' +
                '  <span class="period-label-v2">&#x1F4CA; 比較期間：</span>' +
                '  <span class="period-value">' + cmp + '</span>' +
                '</div>';
        }
    }

    // ===== 前期比のHTML生成 =====
    function changeHtml(current, previous) {
        if (previous === 0 || previous === null || previous === undefined) {
            if (current === 0) return '<span style="color:#666666;">→ 0.0%</span>';
            return '<span style="color:#568184;">NEW</span>';
        }
        var pct = ((current - previous) / previous * 100).toFixed(1);
        if (pct > 0) return '<span style="color:#4E8A6B;">↑ +' + pct + '%</span>';
        if (pct < 0) return '<span style="color:#C0392B;">↓ ' + pct + '%</span>';
        return '<span style="color:#666666;">→ 0.0%</span>';
    }

    // ===== サマリーカード更新 =====
    function updateSummaryCards(data) {
        var m = data.metrics || {};
        var p = data.metrics_previous || {};

        setKpi('meo-total-impressions',   m.total_impressions,   p.total_impressions);
        setKpi('meo-mobile-impressions',  m.mobile_impressions,  p.mobile_impressions);
        setKpi('meo-desktop-impressions', m.desktop_impressions, p.desktop_impressions);
        setKpi('meo-calls',               m.call_clicks,         p.call_clicks);
        setKpi('meo-directions',          m.direction_clicks,    p.direction_clicks);
        setKpi('meo-website',             m.website_clicks,      p.website_clicks);
        setKpi('meo-menu',                m.menu_clicks,         p.menu_clicks);

        // 平均クリック率
        var totalActions = (m.call_clicks || 0) + (m.website_clicks || 0);
        var totalImpressions = m.total_impressions || 0;
        var clickRate = totalImpressions > 0 ? (totalActions / totalImpressions * 100) : 0;
        var prevActions = (p.call_clicks || 0) + (p.website_clicks || 0);
        var prevImpressions = p.total_impressions || 0;
        var prevRate = prevImpressions > 0 ? (prevActions / prevImpressions * 100) : 0;

        var rateEl = document.getElementById('meo-click-rate');
        var rateChEl = document.getElementById('meo-click-rate-change');
        if (rateEl) rateEl.textContent = clickRate.toFixed(2) + '%';
        if (rateChEl) {
            var diff = clickRate - prevRate;
            if (prevRate === 0 && clickRate === 0) {
                rateChEl.innerHTML = '<span style="color:#666666;">→ 0.0%</span>';
            } else if (prevRate === 0) {
                rateChEl.innerHTML = '<span style="color:#568184;">NEW</span>';
            } else if (diff > 0) {
                rateChEl.innerHTML = '<span style="color:#4E8A6B;">↑ +' + diff.toFixed(1) + 'pt</span>';
            } else if (diff < 0) {
                rateChEl.innerHTML = '<span style="color:#C0392B;">↓ ' + diff.toFixed(1) + 'pt</span>';
            } else {
                rateChEl.innerHTML = '<span style="color:#666666;">→ 0.0%</span>';
            }
        }
    }

    function setKpi(id, current, previous) {
        var val = (current !== null && current !== undefined) ? current : 0;
        var el = document.getElementById(id);
        var chEl = document.getElementById(id + '-change');
        if (el) el.textContent = Number(val).toLocaleString();
        if (chEl) chEl.innerHTML = changeHtml(val, previous);
    }

    // ===== キーワードテーブル更新（月別時系列） =====
    function updateKeywordsTable(data) {
        var kwData = data.search_keywords || {};
        var months = kwData.months || [];
        var keywords = kwData.keywords || [];
        var kwHead = document.getElementById('meo-keywords-head');
        var kwBody = document.getElementById('meo-keywords-body');
        if (!kwBody) return;

        var colCount = months.length + 2; // キーワード + 各月 + 合計

        if (keywords.length === 0) {
            if (kwHead) kwHead.innerHTML = '';
            kwBody.innerHTML = '<tr><td colspan="' + colCount + '" style="padding: 24px; text-align: center; color: #888888;">キーワードデータがありません</td></tr>';
            return;
        }

        // ヘッダー構築
        if (kwHead) {
            var headHtml = '<tr>'
                + '<th style="padding:10px 14px;text-align:left;font-size:13px;font-weight:700;color:#555;border-bottom:2px solid #e5e7eb;white-space:nowrap;">キーワード</th>';
            months.forEach(function(m) {
                headHtml += '<th style="padding:10px 14px;text-align:right;font-size:13px;font-weight:700;color:#555;border-bottom:2px solid #e5e7eb;white-space:nowrap;">' + escapeHtml(m) + '</th>';
            });
            headHtml += '<th style="padding:10px 14px;text-align:right;font-size:13px;font-weight:700;color:#555;border-bottom:2px solid #e5e7eb;white-space:nowrap;">合計</th>';
            headHtml += '</tr>';
            kwHead.innerHTML = headHtml;
        }

        // ボディ構築
        var bodyHtml = '';
        var monthTotals = new Array(months.length).fill(0);
        var grandTotal = 0;

        keywords.forEach(function(kw) {
            var monthly = kw.monthly || [];
            var total = kw.total || 0;
            bodyHtml += '<tr>'
                + '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:14px;font-weight:600;color:#555;">' + escapeHtml(kw.keyword || '') + '</td>';
            months.forEach(function(m, mi) {
                var val = monthly[mi] || 0;
                monthTotals[mi] += val;
                bodyHtml += '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:14px;color:#555;text-align:right;">' + Number(val).toLocaleString() + '</td>';
            });
            grandTotal += total;
            bodyHtml += '<td style="padding:10px 14px;border-bottom:1px solid #f3f4f6;font-size:14px;font-weight:700;color:#333;text-align:right;">' + Number(total).toLocaleString() + '</td>';
            bodyHtml += '</tr>';
        });

        // 合計行
        bodyHtml += '<tr style="background:#f8f9fa;">'
            + '<td style="padding:10px 14px;font-size:14px;font-weight:700;color:#333;">合計</td>';
        monthTotals.forEach(function(t) {
            bodyHtml += '<td style="padding:10px 14px;font-size:14px;font-weight:700;color:#333;text-align:right;">' + Number(t).toLocaleString() + '</td>';
        });
        bodyHtml += '<td style="padding:10px 14px;font-size:14px;font-weight:700;color:#333;text-align:right;">' + Number(grandTotal).toLocaleString() + '</td>';
        bodyHtml += '</tr>';

        kwBody.innerHTML = bodyHtml;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ===== 指標推移グラフ（カード選択に連動） =====
    // 指標ごとのグラフ設定マップ
    var metricConfig = {
        'total-impressions': {
            title: '表示回数の推移',
            showLegend: true,
            isPercent: false,
            getDatasets: function(daily) {
                return [
                    { label: '検索での表示', data: daily.map(function(d){ return d.search_impressions || 0; }), borderColor: '#568184', backgroundColor: 'rgba(86,129,132,0.12)', fill: true, tension: 0.3, pointRadius: 2 },
                    { label: 'マップでの表示', data: daily.map(function(d){ return d.map_impressions || 0; }), borderColor: '#E8964D', backgroundColor: 'rgba(232,150,77,0.12)', fill: true, tension: 0.3, pointRadius: 2 }
                ];
            }
        },
        'mobile-impressions': {
            title: 'モバイルの推移',
            showLegend: false,
            isPercent: false,
            getDatasets: function(daily) {
                return [{ label: 'モバイル表示回数', data: daily.map(function(d){ return d.mobile_impressions || 0; }), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.12)', fill: true, tension: 0.3, pointRadius: 2 }];
            }
        },
        'desktop-impressions': {
            title: 'PCの推移',
            showLegend: false,
            isPercent: false,
            getDatasets: function(daily) {
                return [{ label: 'PC表示回数', data: daily.map(function(d){ return d.desktop_impressions || 0; }), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.12)', fill: true, tension: 0.3, pointRadius: 2 }];
            }
        },
        'click-rate': {
            title: '平均クリック率の推移',
            showLegend: false,
            isPercent: true,
            getDatasets: function(daily) {
                return [{ label: '平均クリック率', data: daily.map(function(d){
                    var imp = (d.search_impressions || 0) + (d.map_impressions || 0);
                    var act = (d.call_clicks || 0) + (d.website_clicks || 0);
                    return imp > 0 ? parseFloat((act / imp * 100).toFixed(2)) : 0;
                }), borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.12)', fill: true, tension: 0.3, pointRadius: 2 }];
            }
        },
        'calls': {
            title: '電話クリック数の推移',
            showLegend: false,
            isPercent: false,
            getDatasets: function(daily) {
                return [{ label: '電話クリック数', data: daily.map(function(d){ return d.call_clicks || 0; }), borderColor: '#d97706', backgroundColor: 'rgba(217,119,6,0.12)', fill: true, tension: 0.3, pointRadius: 2 }];
            }
        },
        'directions': {
            title: 'ルート検索回数の推移',
            showLegend: false,
            isPercent: false,
            getDatasets: function(daily) {
                return [{ label: 'ルート検索回数', data: daily.map(function(d){ return d.direction_clicks || 0; }), borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.12)', fill: true, tension: 0.3, pointRadius: 2 }];
            }
        },
        'website': {
            title: 'ウェブサイトクリック数の推移',
            showLegend: false,
            isPercent: false,
            getDatasets: function(daily) {
                return [{ label: 'ウェブサイトクリック数', data: daily.map(function(d){ return d.website_clicks || 0; }), borderColor: '#06b6d4', backgroundColor: 'rgba(6,182,212,0.12)', fill: true, tension: 0.3, pointRadius: 2 }];
            }
        },
        'menu': {
            title: 'メニュークリック数の推移',
            showLegend: false,
            isPercent: false,
            getDatasets: function(daily) {
                return [{ label: 'メニュークリック数', data: daily.map(function(d){ return d.menu_clicks || 0; }), borderColor: '#ec4899', backgroundColor: 'rgba(236,72,153,0.12)', fill: true, tension: 0.3, pointRadius: 2 }];
            }
        }
    };

    function updateMetricChart(data) {
        var daily = data.daily_metrics || [];
        var config = metricConfig[selectedMetric];
        if (!config) return;

        // タイトル更新
        var titleEl = document.getElementById('meo-metric-chart-title');
        if (titleEl) titleEl.textContent = '📈 ' + config.title;

        // 凡例表示制御（表示回数のみ検索/マップの2系列凡例を表示）
        var legendEl = document.getElementById('meo-impressions-legend');
        if (legendEl) legendEl.style.display = config.showLegend ? '' : 'none';

        var ctx = document.getElementById('meo-metric-chart');
        if (!ctx) return;

        if (daily.length === 0) {
            if (metricChart) metricChart.destroy();
            metricChart = null;
            return;
        }

        var labels = daily.map(function(d) {
            var parts = (d.date || '').split('-');
            return parts.length === 3 ? parseInt(parts[1]) + '/' + parseInt(parts[2]) : d.date;
        });
        var datasets = config.getDatasets(daily);

        if (metricChart) metricChart.destroy();

        var yConfig = { beginAtZero: true, ticks: { precision: config.isPercent ? 2 : 0 } };
        if (config.isPercent) {
            yConfig.ticks.callback = function(value) { return value + '%'; };
        }

        metricChart = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: config.showLegend, position: 'top' },
                    tooltip: config.isPercent ? {
                        callbacks: { label: function(context) { return context.dataset.label + ': ' + context.parsed.y + '%'; } }
                    } : {}
                },
                scales: { y: yConfig }
            }
        });
    }

    // ===== アクション内訳グラフ =====
    function updateActionsChart(data) {
        var m = data.metrics || {};
        var ctx = document.getElementById('meo-actions-chart');
        if (!ctx) return;

        var labels = ['電話', 'ルート検索', 'ウェブサイト', 'メニュー'];
        var values = [
            m.call_clicks || 0,
            m.direction_clicks || 0,
            m.website_clicks || 0,
            m.menu_clicks || 0
        ];
        var colors = ['#D4A842', '#C95A4F', '#7AA3A6', '#ec4899'];

        if (actionsChart) actionsChart.destroy();

        actionsChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right' } }
            }
        });
    }

    // ===== 初期読み込み =====
    loadData(currentPeriod);

})();
</script>
<?php endif; ?>

<style>
/* page-meo-dashboard — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */

/* ===== MEO サマリーカード グリッド ===== */
.meo-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 24px;
}

/* ===== MEO サマリーカード — ボタンリセット + 基本スタイル ===== */
.meo-summary-card {
    font-family: inherit;
    font-size: inherit;
    line-height: inherit;
    color: inherit;
    text-align: left;
    background: #fff;
    border: 1px solid #C3CED0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    cursor: pointer;
    transition: all 0.25s ease;
    display: block;
    width: 100%;
}

.meo-summary-card:hover {
    box-shadow: 0 8px 24px rgba(0,0,0,0.07);
    border-color: #AEBCBE;
    transform: translateY(-1px);
}

/* ===== MEO サマリーカード — アクティブ/選択状態 ===== */
.meo-summary-card.is-active {
    border-color: #568184;
    border-bottom: 3px solid #568184;
    background: rgba(86, 129, 132, 0.04);
    box-shadow: 0 1px 6px rgba(0,0,0,0.03);
}
.meo-summary-card.is-active .meo-card-label {
    color: #568184;
    font-weight: 700;
}
.meo-summary-card.is-active .meo-card-hint {
    color: #568184;
}

/* ===== MEO カード内部要素 ===== */
.meo-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}
.meo-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.meo-card-label {
    font-size: 13px;
    color: #666666;
    font-weight: 600;
    transition: color 0.2s ease;
}
.meo-card-value {
    font-size: 32px;
    font-weight: 700;
    color: #2C3E40;
    margin-bottom: 8px;
}
.meo-card-change {
    font-size: 13px;
    font-weight: 600;
    color: #666666;
    margin-bottom: 4px;
}
.meo-card-hint {
    display: block;
    font-size: 11px;
    color: #aaa;
    margin-top: 6px;
    transition: color 0.2s ease;
}

/* ===== フォーカス状態（アクセシビリティ） ===== */
.meo-summary-card:focus-visible {
    outline: 2px solid #568184;
    outline-offset: 2px;
}

/* ===== レスポンシブ ===== */
@media (max-width: 1200px) { .meo-summary-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 768px)  { .meo-summary-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 480px)  { .meo-summary-grid { grid-template-columns: 1fr; } }
</style>

<?php get_footer(); ?>
