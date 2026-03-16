<?php
/*
Template Name: MEOダッシュボード
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'MEO設定');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('MEO設定', 'MEO'));

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

// ===== MEO基準地点（座標）設定 =====
$meo_lat     = get_user_meta($user_id, '_gcrev_meo_lat', true);
$meo_lng     = get_user_meta($user_id, '_gcrev_meo_lng', true);
$meo_address = get_user_meta($user_id, '_gcrev_meo_address', true);
$meo_radius  = (int) get_user_meta($user_id, '_gcrev_meo_radius', true) ?: 1000;
$has_meo_coordinate = ($meo_lat !== '' && $meo_lng !== '');

// ===== 市区町村中心部の自動検出（手動座標が未設定時に表示・プリフィル用） =====
$auto_city_coords = null;
if ( ! $has_meo_coordinate && class_exists('Gcrev_City_Coordinates') ) {
    $auto_city_coords = Gcrev_City_Coordinates::get_for_user($user_id);
}

// フォーム表示値（手動座標 → 自動検出 → 空）
$display_lat     = $has_meo_coordinate ? $meo_lat     : ( $auto_city_coords ? $auto_city_coords['lat'] : '' );
$display_lng     = $has_meo_coordinate ? $meo_lng     : ( $auto_city_coords ? $auto_city_coords['lng'] : '' );
$display_address = $has_meo_coordinate ? $meo_address : ( $auto_city_coords ? $auto_city_coords['label'] : '' );
$display_radius  = $has_meo_coordinate ? $meo_radius  : ( $auto_city_coords ? Gcrev_City_Coordinates::DEFAULT_RADIUS : 1000 );

get_header();
?>

<!-- コンテンツエリア -->
<div class="content-area">

<?php if ( ! $is_connected || $needs_reauth ): ?>
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
            <a href="<?php echo esc_url($gcrev_api->gbp_get_auth_url($user_id)); ?>"
               class="btn btn-primary btn-lg"
               style="min-width: 300px; display: inline-block; padding: 16px 32px; background: #568184; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px;">
                🔄 Googleビジネスプロフィールと再接続
            </a>
        <?php else: ?>
            <h3 style="font-size: 22px; font-weight: 600; color: #333; margin-bottom: 12px;">
                MEOダッシュボードを利用するには、<br>Googleビジネスプロフィールとの接続が必要です
            </h3>
            <p style="color: #666; margin-bottom: 32px; max-width: 480px; margin-left: auto; margin-right: auto;">
                お店のGoogleビジネスプロフィール（旧Googleマイビジネス）と連携すると、<br>
                表示回数・検索キーワード・クリック数などをダッシュボードで確認できます。
            </p>
            <a href="<?php echo esc_url($gcrev_api->gbp_get_auth_url($user_id)); ?>"
               class="btn btn-primary btn-lg"
               style="min-width: 300px; display: inline-block; padding: 16px 32px; background: #568184; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px;">
                📍 Googleビジネスプロフィールと接続
            </a>
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

    <!-- 期間選択 -->
    <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px;">
        <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
            <button class="period-btn active" data-period="prev-month">前月</button>
            <button class="period-btn" data-period="last30">直近30日</button>
        </div>
        <div class="period-info" id="meo-period-display">
            <div class="period-item">
                <span class="period-label-v2">&#x1F4C5; 分析対象期間：</span>
                <span class="period-value" id="meo-period-current">読み込み中...</span>
            </div>
            <div class="period-divider"></div>
            <div class="period-item">
                <span class="period-label-v2">&#x1F4CA; 比較期間：</span>
                <span class="period-value" id="meo-period-compare">読み込み中...</span>
            </div>
        </div>
    </div>

    <!-- ローディングオーバーレイ -->
    <div id="meo-loading" style="display: none; text-align: center; padding: 60px 20px;">
        <div style="font-size: 20px; color: #666666; margin-bottom: 12px;">⏳</div>
        <div style="font-size: 15px; color: #666666;">データを取得しています...</div>
    </div>

    <!-- メインコンテンツ（データ読み込み後に表示） -->
    <div id="meo-main-content">

        <!-- サマリーカード：表示回数系 -->
        <div class="summary-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px;">
            <div class="summary-card" style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: rgba(86,129,132,0.1);">🔍</div>
                    <div style="font-size: 13px; color: #666666; font-weight: 600;">検索での表示</div>
                </div>
                <div id="meo-search-impressions" style="font-size: 32px; font-weight: 700; color: #2C3E40; margin-bottom: 8px;">---</div>
                <div id="meo-search-impressions-change" style="font-size: 13px; font-weight: 600; color: #666666;">---</div>
            </div>
            <div class="summary-card" style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: rgba(78,138,107,0.1);">🗺️</div>
                    <div style="font-size: 13px; color: #666666; font-weight: 600;">マップでの表示</div>
                </div>
                <div id="meo-map-impressions" style="font-size: 32px; font-weight: 700; color: #2C3E40; margin-bottom: 8px;">---</div>
                <div id="meo-map-impressions-change" style="font-size: 13px; font-weight: 600; color: #666666;">---</div>
            </div>
            <div class="summary-card" style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: rgba(122,163,166,0.1);">👁️</div>
                    <div style="font-size: 13px; color: #666666; font-weight: 600;">合計表示回数</div>
                </div>
                <div id="meo-total-impressions" style="font-size: 32px; font-weight: 700; color: #2C3E40; margin-bottom: 8px;">---</div>
                <div id="meo-total-impressions-change" style="font-size: 13px; font-weight: 600; color: #666666;">---</div>
            </div>
            <div class="summary-card" style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: rgba(212,168,66,0.12);">📷</div>
                    <div style="font-size: 13px; color: #666666; font-weight: 600;">写真閲覧数</div>
                </div>
                <div id="meo-photo-views" style="font-size: 32px; font-weight: 700; color: #2C3E40; margin-bottom: 8px;">---</div>
                <div id="meo-photo-views-change" style="font-size: 13px; font-weight: 600; color: #666666;">---</div>
            </div>
        </div>

        <!-- サマリーカード：アクション系 -->
        <div class="summary-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px;">
            <div class="summary-card" style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: rgba(212,168,66,0.15);">📞</div>
                    <div style="font-size: 13px; color: #666666; font-weight: 600;">電話クリック</div>
                </div>
                <div id="meo-calls" style="font-size: 32px; font-weight: 700; color: #2C3E40; margin-bottom: 8px;">---</div>
                <div id="meo-calls-change" style="font-size: 13px; font-weight: 600; color: #666666;">---</div>
            </div>
            <div class="summary-card" style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: #fecaca;">📍</div>
                    <div style="font-size: 13px; color: #666666; font-weight: 600;">ルート検索</div>
                </div>
                <div id="meo-directions" style="font-size: 32px; font-weight: 700; color: #2C3E40; margin-bottom: 8px;">---</div>
                <div id="meo-directions-change" style="font-size: 13px; font-weight: 600; color: #666666;">---</div>
            </div>
            <div class="summary-card" style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: #cffafe;">🌐</div>
                    <div style="font-size: 13px; color: #666666; font-weight: 600;">ウェブサイトクリック</div>
                </div>
                <div id="meo-website" style="font-size: 32px; font-weight: 700; color: #2C3E40; margin-bottom: 8px;">---</div>
                <div id="meo-website-change" style="font-size: 13px; font-weight: 600; color: #666666;">---</div>
            </div>
            <div class="summary-card" style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: rgba(181,87,75,0.08);">📋</div>
                    <div style="font-size: 13px; color: #666666; font-weight: 600;">予約クリック</div>
                </div>
                <div id="meo-bookings" style="font-size: 32px; font-weight: 700; color: #2C3E40; margin-bottom: 8px;">---</div>
                <div id="meo-bookings-change" style="font-size: 13px; font-weight: 600; color: #666666;">---</div>
            </div>
        </div>

        <!-- 表示回数推移グラフ -->
        <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div style="font-size: 18px; font-weight: 700; color: #2C3E40;">📈 表示回数の推移</div>
            </div>
            <div style="height: 300px;">
                <canvas id="meo-impressions-chart"></canvas>
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

        <!-- 検索キーワード TOP5 -->
        <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; overflow-x: auto;">
            <div style="font-size: 18px; font-weight: 700; color: #2C3E40; margin-bottom: 20px;">🏆 検索キーワード TOP5</div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="background: #f9fafb; padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #666666; border-bottom: 1px solid #e5e7eb; width: 60px;"></th>
                        <th style="background: #f9fafb; padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; color: #666666; border-bottom: 1px solid #e5e7eb;">キーワード</th>
                        <th style="background: #f9fafb; padding: 12px 16px; text-align: right; font-size: 13px; font-weight: 600; color: #666666; border-bottom: 1px solid #e5e7eb; width: 120px;">表示回数</th>
                        <th style="background: #f9fafb; padding: 12px 16px; text-align: right; font-size: 13px; font-weight: 600; color: #666666; border-bottom: 1px solid #e5e7eb; width: 120px;">前期比</th>
                    </tr>
                </thead>
                <tbody id="meo-keywords-body">
                    <tr><td colspan="4" style="padding: 24px; text-align: center; color: #888888;">データを読み込み中...</td></tr>
                </tbody>
            </table>
        </div>

    </div><!-- #meo-main-content -->

<?php endif; ?>

<!-- ===== 基準地点の設定（GBP接続状態に関係なく常に表示） ===== -->
<div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top: 24px;">
    <div style="margin-bottom: 16px;">
        <div style="font-size: 18px; font-weight: 700; color: #2C3E40;">⚙️ MEO計測の基準地点</div>
    </div>

    <div id="meo-coord-body">
        <?php
        $radius_labels = [500 => '500m', 1000 => '1km', 3000 => '3km', 5000 => '5km', 10000 => '10km'];
        if ($has_meo_coordinate): ?>
        <!-- 手動座標が設定済み -->
        <div style="background: #f0fdf4; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #16a34a; display: flex; align-items: center; gap: 8px;">
            <span>✅</span>
            <span>基準地点が手動で設定されています：<strong><?php echo esc_html($meo_address ?: ($meo_lat . ', ' . $meo_lng)); ?></strong>（半径 <?php echo esc_html($radius_labels[$meo_radius] ?? '1km'); ?>）</span>
        </div>
        <?php elseif ($auto_city_coords): ?>
        <!-- 市区町村中心部を自動検出 -->
        <div style="background: #eff6ff; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #1d4ed8; display: flex; align-items: center; gap: 8px;">
            <span>📍</span>
            <span>クライアント設定の商圏から自動検出：<strong><?php echo esc_html($auto_city_coords['label']); ?></strong>（半径 <?php echo esc_html($radius_labels[Gcrev_City_Coordinates::DEFAULT_RADIUS] ?? '3km'); ?>）がダッシュボードの基準地点として使用されます。下記フォームで手動調整も可能です。</span>
        </div>
        <?php else: ?>
        <!-- 座標なし・自動検出もなし -->
        <div style="background: #fffbeb; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #92400e; display: flex; align-items: center; gap: 8px;">
            <span>💡</span>
            <span>基準地点を設定すると、メインダッシュボードの「Googleマップの見え方」で店舗周辺の正確な順位を計測できます。クライアント設定で商圏（市区町村）を設定すると自動検出されます。</span>
        </div>
        <?php endif; ?>

        <p style="font-size: 13px; color: #666; margin-bottom: 20px; line-height: 1.6;">
            店舗の座標（緯度・経度）を入力してください。<a href="https://www.google.com/maps" target="_blank" rel="noopener" style="color: #568184; text-decoration: underline;">Googleマップ</a>で店舗を右クリックすると座標をコピーできます。
        </p>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
            <div style="grid-column: 1 / -1;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 6px;">住所（表示用）</label>
                <input type="text" id="meo-coord-address"
                       value="<?php echo esc_attr($display_address); ?>"
                       placeholder="例：愛媛県松山市三番町5-3-7"
                       style="width: 100%; padding: 10px 14px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;"
                       onfocus="this.style.borderColor='#568184'" onblur="this.style.borderColor='#D0D5DA'">
            </div>
            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 6px;">緯度（latitude）</label>
                <input type="text" id="meo-coord-lat"
                       value="<?php echo esc_attr($display_lat); ?>"
                       placeholder="例：33.8416000"
                       style="width: 100%; padding: 10px 14px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;"
                       onfocus="this.style.borderColor='#568184'" onblur="this.style.borderColor='#D0D5DA'">
            </div>
            <div>
                <label style="display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 6px;">経度（longitude）</label>
                <input type="text" id="meo-coord-lng"
                       value="<?php echo esc_attr($display_lng); ?>"
                       placeholder="例：132.7657000"
                       style="width: 100%; padding: 10px 14px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;"
                       onfocus="this.style.borderColor='#568184'" onblur="this.style.borderColor='#D0D5DA'">
            </div>
        </div>
        <div style="margin-bottom: 20px;">
            <label style="display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 6px;">計測半径</label>
            <select id="meo-coord-radius"
                    style="padding: 10px 14px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 14px; outline: none; cursor: pointer; min-width: 160px; background: #fff;">
                <option value="500" <?php selected($display_radius, 500); ?>>500m</option>
                <option value="1000" <?php selected($display_radius, 1000); ?>>1km</option>
                <option value="3000" <?php selected($display_radius, 3000); ?>>3km</option>
                <option value="5000" <?php selected($display_radius, 5000); ?>>5km</option>
                <option value="10000" <?php selected($display_radius, 10000); ?>>10km</option>
            </select>
        </div>
        <div style="display: flex; align-items: center; gap: 16px;">
            <button id="meo-coord-save-btn" onclick="gcrevSaveMeoCoordinate()"
                    style="padding: 10px 32px; background: #568184; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap;"
                    onmouseover="this.style.background='#476C6F'" onmouseout="this.style.background='#568184'">
                基準地点を保存
            </button>
            <?php if ($has_meo_coordinate): ?>
            <button id="meo-coord-clear-btn" onclick="gcrevClearMeoCoordinate()"
                    style="padding: 10px 24px; background: #fff; color: #C0392B; border: 1px solid #fca5a5; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap;"
                    onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='#fff'">
                座標をクリア
            </button>
            <?php endif; ?>
        </div>
        <div id="meo-coord-message" style="display: none; margin-top: 12px; padding: 10px 14px; border-radius: 8px; font-size: 13px;"></div>
    </div>
</div>

<script>
(function() {
    var COORD_API = '<?php echo esc_js(rest_url("gcrev/v1/meo/coordinate")); ?>';
    var WP_NONCE_COORD = '<?php echo esc_js(wp_create_nonce("wp_rest")); ?>';

    // 座標保存
    window.gcrevSaveMeoCoordinate = async function() {
        var lat     = document.getElementById('meo-coord-lat').value.trim();
        var lng     = document.getElementById('meo-coord-lng').value.trim();
        var address = document.getElementById('meo-coord-address').value.trim();
        var radius  = parseInt(document.getElementById('meo-coord-radius').value, 10) || 1000;
        var btn     = document.getElementById('meo-coord-save-btn');
        var msgEl   = document.getElementById('meo-coord-message');

        // バリデーション
        if (!lat || !lng) {
            msgEl.style.display = 'block';
            msgEl.style.background = '#fef2f2';
            msgEl.style.color = '#C0392B';
            msgEl.textContent = '緯度と経度は必須です。';
            return;
        }
        var latNum = parseFloat(lat);
        var lngNum = parseFloat(lng);
        if (isNaN(latNum) || latNum < -90 || latNum > 90) {
            msgEl.style.display = 'block';
            msgEl.style.background = '#fef2f2';
            msgEl.style.color = '#C0392B';
            msgEl.textContent = '緯度は -90〜90 の範囲で入力してください。';
            return;
        }
        if (isNaN(lngNum) || lngNum < -180 || lngNum > 180) {
            msgEl.style.display = 'block';
            msgEl.style.background = '#fef2f2';
            msgEl.style.color = '#C0392B';
            msgEl.textContent = '経度は -180〜180 の範囲で入力してください。';
            return;
        }

        btn.disabled = true;
        btn.textContent = '保存中...';
        btn.style.background = '#93c5fd';
        msgEl.style.display = 'none';

        try {
            var response = await fetch(COORD_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WP_NONCE_COORD },
                credentials: 'same-origin',
                body: JSON.stringify({ lat: latNum, lng: lngNum, address: address, radius: radius })
            });
            var result = await response.json();
            if (result.success) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#f0fdf4';
                msgEl.style.color = '#16a34a';
                msgEl.textContent = '✅ ' + (result.message || '基準地点を保存しました') + '　ページをリロードします...';
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                throw new Error(result.message || '保存に失敗しました');
            }
        } catch (error) {
            msgEl.style.display = 'block';
            msgEl.style.background = '#fef2f2';
            msgEl.style.color = '#C0392B';
            msgEl.textContent = 'エラー: ' + error.message;
            btn.disabled = false;
            btn.textContent = '基準地点を保存';
            btn.style.background = '#568184';
        }
    };

    // 座標クリア
    window.gcrevClearMeoCoordinate = async function() {
        if (!confirm('基準地点の設定をクリアしますか？\nクリアすると、MEO計測はエリア単位（都道府県等）に戻ります。')) {
            return;
        }
        var btn   = document.getElementById('meo-coord-clear-btn');
        var msgEl = document.getElementById('meo-coord-message');
        btn.disabled = true;
        btn.textContent = 'クリア中...';
        msgEl.style.display = 'none';

        try {
            var response = await fetch(COORD_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WP_NONCE_COORD },
                credentials: 'same-origin',
                body: JSON.stringify({ clear: true })
            });
            var result = await response.json();
            if (result.success) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#f0fdf4';
                msgEl.style.color = '#16a34a';
                msgEl.textContent = '✅ 基準地点をクリアしました。ページをリロードします...';
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                throw new Error(result.message || 'クリアに失敗しました');
            }
        } catch (error) {
            msgEl.style.display = 'block';
            msgEl.style.background = '#fef2f2';
            msgEl.style.color = '#C0392B';
            msgEl.textContent = 'エラー: ' + error.message;
            btn.disabled = false;
            btn.textContent = '座標をクリア';
        }
    };
})();
</script>

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
    let currentPeriod = 'prev-month';
    let currentData   = null;

    // Chart.jsインスタンス
    let impressionsChart = null;
    let actionsChart     = null;

    // ===== ローディング制御 =====
    function showLoading() {
        var el = document.getElementById('meo-loading');
        var main = document.getElementById('meo-main-content');
        if (el) el.style.display = 'block';
        if (main) main.style.display = 'none';
    }
    function hideLoading() {
        var el = document.getElementById('meo-loading');
        var main = document.getElementById('meo-main-content');
        if (el) el.style.display = 'none';
        if (main) main.style.display = 'block';
    }

    // ===== 期間ボタン切替（deviceと同一パターン） =====
    document.querySelectorAll('.period-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.period-btn').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            currentPeriod = this.dataset.period;
            loadData(currentPeriod);
        });
    });

    // ===== データ取得（deviceページと同一のfetch + nonce + credentials） =====
    async function loadData(period) {
        showLoading();

        try {
            var apiUrl = REST_URL + '?period=' + encodeURIComponent(period);
            var response = await fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': WP_NONCE
                },
                credentials: 'same-origin'
            });

            var result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'データ取得に失敗しました');
            }

            currentData = result;

            // UI更新
            updatePeriodDisplay(currentData);
            updateSummaryCards(currentData);
            updateKeywordsTable(currentData);
            updateImpressionsChart(currentData);
            updateActionsChart(currentData);

        } catch (error) {
            console.error('[MEO] データ取得エラー:', error);
            document.getElementById('meo-period-current').textContent = 'データ取得に失敗しました';
            document.getElementById('meo-period-compare').textContent = '-';
        } finally {
            hideLoading();
        }
    }

    // ===== 期間表示更新 =====
    function updatePeriodDisplay(data) {
        var cur = data.current_range_label || '---';
        var cmp = data.compare_range_label || '---';
        document.getElementById('meo-period-current').textContent = cur;
        document.getElementById('meo-period-compare').textContent = cmp;
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

        setKpi('meo-search-impressions', m.search_impressions, p.search_impressions);
        setKpi('meo-map-impressions',    m.map_impressions,    p.map_impressions);
        setKpi('meo-total-impressions',  m.total_impressions,  p.total_impressions);
        setKpi('meo-photo-views',        m.photo_views,        p.photo_views);
        setKpi('meo-calls',              m.call_clicks,        p.call_clicks);
        setKpi('meo-directions',         m.direction_clicks,   p.direction_clicks);
        setKpi('meo-website',            m.website_clicks,     p.website_clicks);
        setKpi('meo-bookings',           m.booking_clicks,     p.booking_clicks);
    }

    function setKpi(id, current, previous) {
        var val = (current !== null && current !== undefined) ? current : 0;
        var el = document.getElementById(id);
        var chEl = document.getElementById(id + '-change');
        if (el) el.textContent = Number(val).toLocaleString();
        if (chEl) chEl.innerHTML = changeHtml(val, previous);
    }

    // ===== キーワードテーブル更新 =====
    function updateKeywordsTable(data) {
        var keywords = data.search_keywords || [];
        var kwBody = document.getElementById('meo-keywords-body');
        if (!kwBody) return;

        if (keywords.length === 0) {
            kwBody.innerHTML = '<tr><td colspan="4" style="padding: 24px; text-align: center; color: #888888;">キーワードデータがありません</td></tr>';
            return;
        }

        kwBody.innerHTML = '';
        var top5 = keywords.slice(0, 5);
        var ranks = ['🥇', '🥈', '🥉', '4', '5'];

        top5.forEach(function(kw, i) {
            var rankHtml;
            if (i < 3) {
                rankHtml = '<span style="font-size:20px;">' + ranks[i] + '</span>';
            } else {
                rankHtml = '<span style="width:28px;height:28px;border-radius:50%;background:#e5e7eb;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#2C3E40;">' + ranks[i] + '</span>';
            }

            var impressions = kw.impressions || kw.count || 0;
            var prevImpressions = kw.prev_impressions || kw.prev_count || null;
            var chHtml = changeHtml(impressions, prevImpressions);

            kwBody.innerHTML += '<tr>'
                + '<td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;text-align:center;">' + rankHtml + '</td>'
                + '<td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;font-weight:600;font-size:14px;color:#555555;">' + escapeHtml(kw.keyword || kw.query || '') + '</td>'
                + '<td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;font-size:14px;color:#555555;font-weight:700;text-align:right;">' + Number(impressions).toLocaleString() + '</td>'
                + '<td style="padding:12px 16px;border-bottom:1px solid #f3f4f6;font-size:13px;font-weight:600;text-align:right;">' + chHtml + '</td>'
                + '</tr>';
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ===== 表示回数推移グラフ =====
    function updateImpressionsChart(data) {
        var daily = data.daily_metrics || [];
        if (daily.length === 0) return;

        var labels = daily.map(function(d) {
            var parts = (d.date || '').split('-');
            return parts.length === 3 ? parseInt(parts[1]) + '/' + parseInt(parts[2]) : d.date;
        });
        var searchData = daily.map(function(d) { return d.search_impressions || 0; });
        var mapData    = daily.map(function(d) { return d.map_impressions || 0; });

        var ctx = document.getElementById('meo-impressions-chart');
        if (!ctx) return;

        if (impressionsChart) impressionsChart.destroy();

        impressionsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: '検索での表示',
                        data: searchData,
                        borderColor: '#568184',
                        backgroundColor: 'rgba(86,129,132,0.12)',
                        fill: true, tension: 0.3, pointRadius: 2
                    },
                    {
                        label: 'マップでの表示',
                        data: mapData,
                        borderColor: '#4E8A6B',
                        backgroundColor: 'rgba(78,138,107,0.12)',
                        fill: true, tension: 0.3, pointRadius: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
            }
        });
    }

    // ===== アクション内訳グラフ =====
    function updateActionsChart(data) {
        var m = data.metrics || {};
        var ctx = document.getElementById('meo-actions-chart');
        if (!ctx) return;

        var labels = ['電話', 'ルート検索', 'ウェブサイト', '予約'];
        var values = [
            m.call_clicks || 0,
            m.direction_clicks || 0,
            m.website_clicks || 0,
            m.booking_clicks || 0
        ];
        var colors = ['#D4A842', '#C95A4F', '#7AA3A6', '#C95A4F'];

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
@media (max-width: 1200px) { .summary-grid { grid-template-columns: repeat(3, 1fr) !important; } }
@media (max-width: 768px)  { .summary-grid { grid-template-columns: repeat(2, 1fr) !important; } }
@media (max-width: 480px)  { .summary-grid { grid-template-columns: 1fr !important; } }
</style>

<?php get_footer(); ?>
