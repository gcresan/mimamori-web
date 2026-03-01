<?php
/*
Template Name: クライアント設定
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// ページタイトル
set_query_var( 'gcrev_page_title', 'クライアント設定' );
set_query_var( 'gcrev_page_subtitle', 'AIレポートやAI相談で使用する、クライアントの基本情報を設定します。' );

// パンくず
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'クライアント設定', 'アカウント' ) );

// 現在の設定を取得
$settings = gcrev_get_client_settings( $user_id );

// 旧データフォールバック: report_target から商圏の初期値を推定
$legacy_target = get_user_meta( $user_id, 'report_target', true );
$has_new_settings = ! empty( $settings['area_type'] );

// 旧サイトURL からの初期値（WP-Members → report_site_url → gcrev_client_site_url）
$initial_site_url = $settings['site_url'];
if ( empty( $initial_site_url ) ) {
    $initial_site_url = get_user_meta( $user_id, 'weisite_url', true ) ?: '';
}

get_header();
?>

<style>
/* page-client-settings — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */

.cs-section { margin-bottom: 28px; }
.cs-section-title {
    font-size: 15px; font-weight: 700; color: #1e293b;
    margin: 0 0 16px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;
    display: flex; align-items: center; gap: 8px;
}
.cs-section-title .icon { font-size: 18px; }

/* 商圏タイプ */
.area-type-options { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
.area-type-option {
    display: flex; align-items: flex-start; gap: 8px;
    padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px;
    cursor: pointer; transition: border-color .2s, background .2s;
}
.area-type-option:hover { border-color: #94a3b8; }
.area-type-option.selected { border-color: #3D8B6E; background: #f0fdf4; }
.area-type-option input[type="radio"] { margin-top: 2px; accent-color: #3D8B6E; }
.area-type-option label { cursor: pointer; font-size: 14px; line-height: 1.5; }
.area-type-option label strong { display: block; font-size: 14px; }
.area-type-option label span { font-size: 12px; color: #64748b; }

/* 商圏サブフィールド */
.area-sub-fields { margin-top: 12px; }
.area-sub-field { display: none; margin-bottom: 12px; }
.area-sub-field.visible { display: block; }
.area-sub-field label { display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 4px; }
.area-sub-field select,
.area-sub-field input,
.area-sub-field textarea {
    width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 14px; line-height: 1.5; transition: border-color .2s;
}
.area-sub-field select:focus,
.area-sub-field input:focus,
.area-sub-field textarea:focus {
    outline: none; border-color: #3D8B6E; box-shadow: 0 0 0 3px rgba(61,139,110,.12);
}

/* 業種・業態セレクト */
.industry-group { margin-bottom: 16px; }
.industry-group label {
    display: block; font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 4px;
}
.industry-group select,
.industry-group input[type="text"] {
    width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 14px; line-height: 1.5; transition: border-color .2s;
    background: #fff;
}
.industry-group select:focus,
.industry-group input[type="text"]:focus {
    outline: none; border-color: #3D8B6E; box-shadow: 0 0 0 3px rgba(61,139,110,.12);
}
.industry-group select:disabled {
    background: #f1f5f9; color: #94a3b8; cursor: not-allowed;
}

/* 業態チェックボックスグリッド */
.subcategory-grid {
    display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;
    min-height: 36px; padding: 8px; border: 1px solid #e2e8f0; border-radius: 8px;
    background: #fafafa;
}
.subcategory-grid.disabled { background: #f1f5f9; pointer-events: none; opacity: .5; }
.subcategory-grid .subcategory-item {
    display: flex; align-items: center; gap: 4px;
    padding: 4px 10px; border: 1px solid #e2e8f0; border-radius: 16px;
    cursor: pointer; font-size: 13px; transition: all .2s; user-select: none;
}
.subcategory-grid .subcategory-item:hover { border-color: #94a3b8; background: #f8fafc; }
.subcategory-grid .subcategory-item.checked {
    border-color: #3D8B6E; background: #f0fdf4; color: #166534;
}
.subcategory-grid .subcategory-item input[type="checkbox"] {
    accent-color: #3D8B6E; width: 14px; height: 14px; margin: 0;
}
.subcategory-placeholder {
    color: #94a3b8; font-size: 13px; padding: 4px 0;
}

/* ビジネス形態 */
.btype-options { display: flex; flex-wrap: wrap; gap: 8px; }
.btype-option {
    display: flex; align-items: center; gap: 6px;
    padding: 6px 14px; border: 1px solid #e2e8f0; border-radius: 20px;
    cursor: pointer; font-size: 13px; transition: all .2s;
}
.btype-option:hover { border-color: #94a3b8; }
.btype-option.selected { border-color: #3D8B6E; background: #f0fdf4; color: #166534; }
.btype-option input[type="radio"] { accent-color: #3D8B6E; }

/* 保存ボタン */
.cs-actions { margin-top: 24px; display: flex; gap: 12px; }
.cs-actions .btn-save {
    padding: 10px 32px; background: #3D8B6E; color: #fff; border: none;
    border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;
    transition: background .2s;
}
.cs-actions .btn-save:hover { background: #2d6b54; }
.cs-actions .btn-save:disabled { background: #94a3b8; cursor: not-allowed; }

/* 旧データ移行バナー */
.migration-banner {
    background: #FFF7ED; border: 1px solid #FED7AA; border-radius: 8px;
    padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #9A3412;
    display: flex; align-items: flex-start; gap: 8px;
}
.migration-banner .icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }

/* トースト */
.cs-toast {
    position: fixed; top: 20px; right: 20px; z-index: 10000;
    background: #166534; color: #fff; padding: 12px 20px;
    border-radius: 8px; font-size: 14px; box-shadow: 0 4px 12px rgba(0,0,0,.15);
    opacity: 0; transform: translateY(-10px); transition: all .3s;
    pointer-events: none;
}
.cs-toast.show { opacity: 1; transform: translateY(0); pointer-events: auto; }
</style>

<!-- コンテンツエリア -->
<div class="content-area">

    <!-- トースト通知 -->
    <div class="cs-toast" id="csToast"></div>

    <?php if ( ! $has_new_settings && ( ! empty( $settings['site_url'] ) || ! empty( $legacy_target ) ) ): ?>
    <div class="migration-banner">
        <span class="icon">💡</span>
        <div>
            以前の「月次レポート設定」で入力されたサイトURLやターゲット情報を引き継いでいます。<br>
            内容を確認のうえ「保存する」を押してください。
        </div>
    </div>
    <?php endif; ?>

    <!-- 対象サイト -->
    <div class="settings-card">
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">🌐</span> 対象サイト</h2>
            <div class="form-group">
                <label for="cs-site-url">解析対象のサイトURL <span class="required">*</span></label>
                <input type="url" id="cs-site-url" placeholder="https://example.com" value="<?php echo esc_attr( $initial_site_url ); ?>">
                <small class="form-text">AIレポートやAI相談で参照されるWebサイトのURLです</small>
            </div>
        </div>

        <!-- 商圏・対応エリア -->
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">📍</span> 主な商圏・対応エリア</h2>
            <?php
            $area_type = $settings['area_type'] ?: '';
            // 旧データからの推定（未移行時）
            if ( ! $area_type && ! empty( $legacy_target ) ) {
                if ( mb_strpos( $legacy_target, '全国' ) !== false ) {
                    $area_type = 'nationwide';
                } elseif ( class_exists( 'Gcrev_Area_Detector' ) ) {
                    $detected = Gcrev_Area_Detector::detect( $legacy_target );
                    if ( $detected ) {
                        $area_type = 'prefecture';
                        if ( empty( $settings['area_pref'] ) ) {
                            $settings['area_pref'] = $detected;
                        }
                    }
                }
            }
            ?>
            <div class="area-type-options" id="areaTypeOptions">
                <div class="area-type-option <?php echo $area_type === 'nationwide' ? 'selected' : ''; ?>" data-value="nationwide">
                    <input type="radio" name="area_type" value="nationwide" id="area-nationwide" <?php checked( $area_type, 'nationwide' ); ?>>
                    <label for="area-nationwide">
                        <strong>全国</strong>
                        <span>全国を対象としたサービス</span>
                    </label>
                </div>
                <div class="area-type-option <?php echo $area_type === 'prefecture' ? 'selected' : ''; ?>" data-value="prefecture">
                    <input type="radio" name="area_type" value="prefecture" id="area-prefecture" <?php checked( $area_type, 'prefecture' ); ?>>
                    <label for="area-prefecture">
                        <strong>都道府県</strong>
                        <span>特定の都道府県を中心としたサービス</span>
                    </label>
                </div>
                <div class="area-type-option <?php echo $area_type === 'city' ? 'selected' : ''; ?>" data-value="city">
                    <input type="radio" name="area_type" value="city" id="area-city" <?php checked( $area_type, 'city' ); ?>>
                    <label for="area-city">
                        <strong>市区町村</strong>
                        <span>特定の市区町村を対象としたサービス</span>
                    </label>
                </div>
                <div class="area-type-option <?php echo $area_type === 'custom' ? 'selected' : ''; ?>" data-value="custom">
                    <input type="radio" name="area_type" value="custom" id="area-custom" <?php checked( $area_type, 'custom' ); ?>>
                    <label for="area-custom">
                        <strong>指定エリア</strong>
                        <span>自由に対応エリアを記述</span>
                    </label>
                </div>
            </div>

            <div class="area-sub-fields">
                <!-- 都道府県 選択 -->
                <div class="area-sub-field" id="sub-prefecture" data-for="prefecture">
                    <label for="cs-pref-select">都道府県を選択</label>
                    <select id="cs-pref-select">
                        <option value="">選択してください</option>
                        <?php
                        $prefs = [
                            '北海道','青森県','岩手県','宮城県','秋田県','山形県','福島県',
                            '茨城県','栃木県','群馬県','埼玉県','千葉県','東京都','神奈川県',
                            '新潟県','富山県','石川県','福井県','山梨県','長野県',
                            '岐阜県','静岡県','愛知県','三重県',
                            '滋賀県','京都府','大阪府','兵庫県','奈良県','和歌山県',
                            '鳥取県','島根県','岡山県','広島県','山口県',
                            '徳島県','香川県','愛媛県','高知県',
                            '福岡県','佐賀県','長崎県','熊本県','大分県','宮崎県','鹿児島県','沖縄県',
                        ];
                        $saved_pref = esc_attr( $settings['area_pref'] ?? '' );
                        foreach ( $prefs as $p ) {
                            $sel = ( $saved_pref === $p ) ? ' selected' : '';
                            echo '<option value="' . esc_attr( $p ) . '"' . $sel . '>' . esc_html( $p ) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <!-- 市区町村 -->
                <div class="area-sub-field" id="sub-city" data-for="city">
                    <label for="cs-city-pref">都道府県</label>
                    <select id="cs-city-pref" style="margin-bottom: 8px;">
                        <option value="">選択してください</option>
                        <?php
                        // 市区町村モード用の都道府県選択（area_pref を共用）
                        $saved_city_pref = esc_attr( $settings['area_pref'] ?? '' );
                        foreach ( $prefs as $p ) {
                            $sel = ( $saved_city_pref === $p ) ? ' selected' : '';
                            echo '<option value="' . esc_attr( $p ) . '"' . $sel . '>' . esc_html( $p ) . '</option>';
                        }
                        ?>
                    </select>
                    <label for="cs-city-input">市区町村（複数ある場合はカンマ区切り）</label>
                    <input type="text" id="cs-city-input" placeholder="例：渋谷区, 新宿区, 港区" value="<?php echo esc_attr( $settings['area_city'] ?? '' ); ?>">
                </div>

                <!-- 指定エリア（自由入力） -->
                <div class="area-sub-field" id="sub-custom" data-for="custom">
                    <label for="cs-area-custom">対応エリアの説明</label>
                    <textarea id="cs-area-custom" rows="2" placeholder="例：関東一円、東京23区および神奈川県横浜市"><?php echo esc_textarea( $settings['area_custom'] ?? '' ); ?></textarea>
                </div>
            </div>
        </div>

        <!-- クライアント情報 -->
        <div class="cs-section">
            <h2 class="cs-section-title"><span class="icon">🏢</span> クライアント情報（任意）</h2>

            <?php
            $industry_master   = gcrev_get_industry_master();
            $saved_category    = $settings['industry_category'] ?? '';
            $saved_subcategory = $settings['industry_subcategory'] ?? [];
            $saved_detail      = $settings['industry_detail'] ?? '';
            ?>

            <!-- 業種（大分類） -->
            <div class="industry-group">
                <label for="cs-industry-category">業種（任意）</label>
                <select id="cs-industry-category">
                    <option value="">選択してください</option>
                    <?php foreach ( $industry_master as $cat_val => $cat_data ): ?>
                    <option value="<?php echo esc_attr( $cat_val ); ?>" <?php selected( $saved_category, $cat_val ); ?>><?php echo esc_html( $cat_data['label'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 業態（小分類 — 複数選択） -->
            <div class="industry-group">
                <label>業態（任意）</label>
                <div class="subcategory-grid <?php echo empty( $saved_category ) ? 'disabled' : ''; ?>" id="subcategoryGrid">
                    <?php if ( empty( $saved_category ) ): ?>
                        <span class="subcategory-placeholder">業種を選択してください</span>
                    <?php else:
                        $subs = $industry_master[ $saved_category ]['subcategories'] ?? [];
                        foreach ( $subs as $sub_val => $sub_label ):
                            $is_checked = in_array( $sub_val, $saved_subcategory, true );
                    ?>
                        <label class="subcategory-item <?php echo $is_checked ? 'checked' : ''; ?>">
                            <input type="checkbox" value="<?php echo esc_attr( $sub_val ); ?>" <?php checked( $is_checked ); ?>>
                            <?php echo esc_html( $sub_label ); ?>
                        </label>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- 詳細 -->
            <div class="industry-group">
                <label for="cs-industry-detail">詳細（任意）</label>
                <input type="text" id="cs-industry-detail" maxlength="160" placeholder="例：小児歯科 / 外壁塗装 / 相続 / ランチ営業中心 など" value="<?php echo esc_attr( $saved_detail ); ?>">
            </div>

            <div class="form-group">
                <label>ビジネス形態</label>
                <?php
                $btype = $settings['business_type'] ?? '';
                $btypes = [
                    'visit'       => '来店型',
                    'non_visit'   => '非来店型',
                    'reservation' => '予約制',
                    'ec'          => 'ECサイト',
                    'other'       => 'その他',
                ];
                ?>
                <div class="btype-options" id="btypeOptions">
                    <?php foreach ( $btypes as $val => $label ): ?>
                    <div class="btype-option <?php echo $btype === $val ? 'selected' : ''; ?>" data-value="<?php echo esc_attr( $val ); ?>">
                        <input type="radio" name="business_type" value="<?php echo esc_attr( $val ); ?>" id="btype-<?php echo esc_attr( $val ); ?>" <?php checked( $btype, $val ); ?>>
                        <label for="btype-<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="cs-actions">
            <button type="button" class="btn-save" id="btn-cs-save" onclick="saveClientSettings()">
                💾 保存する
            </button>
        </div>
    </div>

</div>

<script>
(function() {
    const restBase = '<?php echo esc_js( trailingslashit( rest_url( 'gcrev_insights/v1' ) ) ); ?>';
    const wpNonce  = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';

    // === 業種マスターデータ（PHP→JS） ===
    var industryMaster = <?php echo wp_json_encode( $industry_master, JSON_UNESCAPED_UNICODE ); ?>;

    // === 商圏タイプ切替 ===
    const areaOptions = document.querySelectorAll('#areaTypeOptions .area-type-option');
    const subFields   = document.querySelectorAll('.area-sub-field');

    function updateAreaType(selectedValue) {
        areaOptions.forEach(function(opt) {
            opt.classList.toggle('selected', opt.dataset.value === selectedValue);
        });
        subFields.forEach(function(sf) {
            sf.classList.toggle('visible', sf.dataset.for === selectedValue);
        });
    }

    areaOptions.forEach(function(opt) {
        opt.addEventListener('click', function() {
            var radio = opt.querySelector('input[type="radio"]');
            radio.checked = true;
            updateAreaType(opt.dataset.value);
        });
    });

    // 初期状態の反映
    var checkedRadio = document.querySelector('input[name="area_type"]:checked');
    if (checkedRadio) {
        updateAreaType(checkedRadio.value);
    }

    // === 業種 → 業態 カスケード ===
    var categorySelect   = document.getElementById('cs-industry-category');
    var subcategoryGrid  = document.getElementById('subcategoryGrid');

    function renderSubcategories(catValue, checkedValues) {
        subcategoryGrid.innerHTML = '';
        if (!catValue || !industryMaster[catValue]) {
            subcategoryGrid.classList.add('disabled');
            subcategoryGrid.innerHTML = '<span class="subcategory-placeholder">業種を選択してください</span>';
            return;
        }
        subcategoryGrid.classList.remove('disabled');
        var subs = industryMaster[catValue].subcategories;
        for (var subVal in subs) {
            if (!subs.hasOwnProperty(subVal)) continue;
            var isChecked = checkedValues.indexOf(subVal) !== -1;
            var lbl = document.createElement('label');
            lbl.className = 'subcategory-item' + (isChecked ? ' checked' : '');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = subVal;
            cb.checked = isChecked;
            cb.addEventListener('change', function() {
                this.parentElement.classList.toggle('checked', this.checked);
            });
            lbl.appendChild(cb);
            lbl.appendChild(document.createTextNode(' ' + subs[subVal]));
            subcategoryGrid.appendChild(lbl);
        }
    }

    categorySelect.addEventListener('change', function() {
        renderSubcategories(this.value, []);
    });

    // 業態チェックボックスの初期クリックイベント（PHP レンダリング分）
    subcategoryGrid.querySelectorAll('.subcategory-item input[type="checkbox"]').forEach(function(cb) {
        cb.addEventListener('change', function() {
            this.parentElement.classList.toggle('checked', this.checked);
        });
    });

    // === ビジネス形態切替 ===
    var btypeOptions = document.querySelectorAll('#btypeOptions .btype-option');
    btypeOptions.forEach(function(opt) {
        opt.addEventListener('click', function() {
            btypeOptions.forEach(function(o) { o.classList.remove('selected'); });
            opt.classList.add('selected');
            opt.querySelector('input[type="radio"]').checked = true;
        });
    });

    // === 保存処理 ===
    window.saveClientSettings = async function() {
        var siteUrl = document.getElementById('cs-site-url').value.trim();
        if (!siteUrl) {
            alert('対象サイトURLは必須です。');
            return;
        }
        if (!/^https?:\/\/.+/.test(siteUrl)) {
            alert('URLの形式が正しくありません。https:// から入力してください。');
            return;
        }

        var areaType = '';
        var areaRadio = document.querySelector('input[name="area_type"]:checked');
        if (areaRadio) areaType = areaRadio.value;

        var areaPref = '';
        if (areaType === 'prefecture') {
            areaPref = document.getElementById('cs-pref-select').value;
        } else if (areaType === 'city') {
            areaPref = document.getElementById('cs-city-pref').value;
        }

        var areaCity   = document.getElementById('cs-city-input').value.trim();
        var areaCustom = document.getElementById('cs-area-custom').value.trim();

        // 業種3項目
        var industryCategory = categorySelect.value;
        var industrySubcategory = [];
        subcategoryGrid.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
            industrySubcategory.push(cb.value);
        });
        var industryDetail = document.getElementById('cs-industry-detail').value.trim();

        var businessType = '';
        var btRadio = document.querySelector('input[name="business_type"]:checked');
        if (btRadio) businessType = btRadio.value;

        var btn = document.getElementById('btn-cs-save');
        btn.disabled = true;
        btn.textContent = '保存中...';

        try {
            var res = await fetch(restBase + 'save-client-settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpNonce
                },
                body: JSON.stringify({
                    site_url:               siteUrl,
                    area_type:              areaType,
                    area_pref:              areaPref,
                    area_city:              areaCity,
                    area_custom:            areaCustom,
                    industry_category:      industryCategory,
                    industry_subcategory:   industrySubcategory,
                    industry_detail:        industryDetail,
                    business_type:          businessType
                })
            });

            var json = await res.json();
            if (res.ok && json.success) {
                showToast('クライアント設定を保存しました');
                var banner = document.querySelector('.migration-banner');
                if (banner) banner.style.display = 'none';
            } else {
                alert('保存に失敗しました: ' + (json.message || ''));
            }
        } catch (e) {
            alert('保存中にエラーが発生しました: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.textContent = '💾 保存する';
        }
    };

    function showToast(msg) {
        var toast = document.getElementById('csToast');
        toast.textContent = '✅ ' + msg;
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 3000);
    }
})();
</script>

<?php get_footer(); ?>
