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
set_query_var('gcrev_page_title', 'MEOダッシュボード');

// パンくず設定（HTMLデザインに合わせる）
$breadcrumb = '<a href="' . esc_url(home_url()) . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="' . esc_url(home_url()) . '">MEO</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<strong>ダッシュボード</strong>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

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
               style="min-width: 300px; display: inline-block; padding: 16px 32px; background: #3D6B6E; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px;">
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
               style="min-width: 300px; display: inline-block; padding: 16px 32px; background: #3D6B6E; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 16px;">
                📍 Googleビジネスプロフィールと接続
            </a>
        <?php endif; ?>
    </div>

<?php elseif ( ! $has_location ): ?>
    <!-- ===== ロケーション未設定：登録フォーム ===== -->
    <?php
    $user_info      = get_userdata($user_id);
    $user_last_name = get_user_meta($user_id, 'last_name', true);
    if (!empty($user_last_name)) {
        $display_label = $user_last_name;
    } elseif (!empty($user_info->display_name) && $user_info->display_name !== $user_info->user_login) {
        $display_label = $user_info->display_name;
    } else {
        $display_label = get_bloginfo('name');
    }
    ?>
    <div style="max-width: 600px; margin: 40px auto; padding: 0 20px;">
        <div style="background: #fff; border-radius: 16px; padding: 40px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
            <h3 style="font-size: 20px; font-weight: 700; color: #2C3E40; text-align: center; margin-bottom: 32px;">
                「<?php echo esc_html($display_label); ?>」のランキング計測場所を登録してください
            </h3>

            <div id="meo-location-form">
                <!-- 場所名 -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 14px; font-weight: 600; color: #555555; margin-bottom: 8px;">場所名</label>
                    <input type="text" id="meo-loc-name"
                           placeholder="例：愛媛県松山市"
                           style="width: 100%; padding: 12px 16px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 15px; color: #2C3E40; outline: none; transition: border-color 0.15s; box-sizing: border-box;"
                           onfocus="this.style.borderColor='#3D6B6E'" onblur="this.style.borderColor='#D0D5DA'">
                    <div style="font-size: 12px; color: #888888; margin-top: 6px;">※任意(チーム内で分かりやすい場所名を入力してください)</div>
                </div>

                <!-- 住所 -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 14px; font-weight: 600; color: #555555; margin-bottom: 8px;">住所</label>
                    <input type="text" id="meo-loc-address"
                           placeholder="例：〒790-0003 愛媛県松山市三番町7丁目12-1"
                           style="width: 100%; padding: 12px 16px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 15px; color: #2C3E40; outline: none; transition: border-color 0.15s; box-sizing: border-box;"
                           onfocus="this.style.borderColor='#3D6B6E'" onblur="this.style.borderColor='#D0D5DA'">
                    <div style="font-size: 12px; color: #888888; margin-top: 6px;">※必須</div>
                </div>

                <!-- 検索範囲 -->
                <div style="margin-bottom: 32px;">
                    <label style="display: block; font-size: 14px; font-weight: 600; color: #555555; margin-bottom: 8px;">検索範囲（m）</label>
                    <input type="number" id="meo-loc-radius" value="1000" min="100" max="50000" step="100"
                           style="width: 100%; padding: 12px 16px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 15px; color: #2C3E40; outline: none; transition: border-color 0.15s; box-sizing: border-box;"
                           onfocus="this.style.borderColor='#3D6B6E'" onblur="this.style.borderColor='#D0D5DA'">
                    <div style="font-size: 12px; color: #888888; margin-top: 6px;">※キーワードランキングを検索する範囲（デフォルト：1000m）</div>
                </div>

                <!-- 送信ボタン -->
                <div style="text-align: center;">
                    <button id="meo-loc-submit"
                            onclick="submitLocation()"
                            style="min-width: 240px; padding: 14px 40px; background: #3D6B6E; color: #fff; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.15s;"
                            onmouseover="this.style.background='#346062'" onmouseout="this.style.background='#3D6B6E'">
                        送信
                    </button>
                </div>

                <!-- メッセージ -->
                <div id="meo-loc-message" style="display: none; margin-top: 20px; padding: 12px 16px; border-radius: 8px; font-size: 14px; text-align: center;"></div>
            </div>
        </div>
    </div>

    <script>
    async function submitLocation() {
        var name    = document.getElementById('meo-loc-name').value.trim();
        var address = document.getElementById('meo-loc-address').value.trim();
        var radius  = document.getElementById('meo-loc-radius').value.trim();
        var msgEl   = document.getElementById('meo-loc-message');
        var btn     = document.getElementById('meo-loc-submit');

        if (!address) {
            msgEl.style.display = 'block';
            msgEl.style.background = '#fef2f2';
            msgEl.style.color = '#C0392B';
            msgEl.textContent = '住所は必須です。';
            return;
        }

        btn.disabled = true;
        btn.textContent = '送信中...';
        btn.style.background = '#93c5fd';
        msgEl.style.display = 'none';

        try {
            var response = await fetch('<?php echo esc_js(rest_url("gcrev/v1/meo/location")); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: name,
                    address: address,
                    radius: parseInt(radius) || 1000
                })
            });

            var result = await response.json();

            if (result.success) {
                msgEl.style.display = 'block';
                msgEl.style.background = '#f0fdf4';
                msgEl.style.color = '#16a34a';
                msgEl.textContent = '✅ 登録しました。ページをリロードします...';
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                throw new Error(result.message || '登録に失敗しました');
            }
        } catch (error) {
            msgEl.style.display = 'block';
            msgEl.style.background = '#fef2f2';
            msgEl.style.color = '#C0392B';
            msgEl.textContent = 'エラー: ' + error.message;
            btn.disabled = false;
            btn.textContent = '送信';
            btn.style.background = '#3D6B6E';
        }
    }
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
            Googleビジネスプロフィールの管理画面URLに含まれる数字がロケーションIDです。<br>
            例：<code style="background:rgba(212,168,66,0.12);padding:2px 6px;border-radius:4px;">https://business.google.com/dashboard/l/<strong style="color:#C0392B;">12345678901234567</strong></code>
        </p>
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <input type="text" id="meo-location-id-input"
                   placeholder="例：12345678901234567"
                   style="flex: 1; min-width: 200px; padding: 10px 14px; border: 1px solid #D0D5DA; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;"
                   onfocus="this.style.borderColor='#3D6B6E'" onblur="this.style.borderColor='#D0D5DA'">
            <button id="meo-set-location-btn" onclick="setLocationId()"
                    style="padding: 10px 24px; background: #D4A842; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap;"
                    onmouseover="this.style.background='#B8941E'" onmouseout="this.style.background='#D4A842'">
                設定
            </button>
        </div>
        <div id="meo-locid-message" style="display: none; margin-top: 12px; padding: 8px 12px; border-radius: 6px; font-size: 13px;"></div>
    </div>
    <script>
    async function setLocationId() {
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
            var response = await fetch('<?php echo esc_js(rest_url("gcrev/v1/meo/location-id")); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>' },
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
    }
    </script>
    <?php endif; ?>

    <!-- 期間選択 -->
    <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px;">
        <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
            <button class="period-btn active" data-period="prev-month">前月</button>
            <button class="period-btn" data-period="last30">直近30日</button>
        </div>
        <div id="meo-period-display" style="font-size: 14px; color: #555555;">
            <span style="font-weight: 600;">分析対象期間：</span>
            <span id="meo-period-current">読み込み中...</span>
            <span style="margin: 0 12px; color: #D0D5DA;">|</span>
            <span style="font-weight: 600;">比較期間：</span>
            <span id="meo-period-compare">読み込み中...</span>
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
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: rgba(61,107,110,0.1);">🔍</div>
                    <div style="font-size: 13px; color: #666666; font-weight: 600;">検索での表示</div>
                </div>
                <div id="meo-search-impressions" style="font-size: 32px; font-weight: 700; color: #2C3E40; margin-bottom: 8px;">---</div>
                <div id="meo-search-impressions-change" style="font-size: 13px; font-weight: 600; color: #666666;">---</div>
            </div>
            <div class="summary-card" style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: rgba(61,139,110,0.1);">🗺️</div>
                    <div style="font-size: 13px; color: #666666; font-weight: 600;">マップでの表示</div>
                </div>
                <div id="meo-map-impressions" style="font-size: 32px; font-weight: 700; color: #2C3E40; margin-bottom: 8px;">---</div>
                <div id="meo-map-impressions-change" style="font-size: 13px; font-weight: 600; color: #666666;">---</div>
            </div>
            <div class="summary-card" style="background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                    <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: rgba(78,130,133,0.1);">👁️</div>
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
            return '<span style="color:#3D6B6E;">NEW</span>';
        }
        var pct = ((current - previous) / previous * 100).toFixed(1);
        if (pct > 0) return '<span style="color:#3D8B6E;">↑ +' + pct + '%</span>';
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
                        borderColor: '#3D6B6E',
                        backgroundColor: 'rgba(59,130,246,0.08)',
                        fill: true, tension: 0.3, pointRadius: 2
                    },
                    {
                        label: 'マップでの表示',
                        data: mapData,
                        borderColor: '#3D8B6E',
                        backgroundColor: 'rgba(16,185,129,0.08)',
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
        var colors = ['#D4A842', '#B5574B', '#4E8285', '#B5574B'];

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
