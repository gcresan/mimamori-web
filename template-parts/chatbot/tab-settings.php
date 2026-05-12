<?php
/**
 * チャットボット管理 — 設定タブ
 *
 * 変数 (page-chatbot.php から渡される):
 *   $tenant, $is_admin, $return_url
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="mb-card">
    <h2>基本設定 — <?php echo esc_html( $tenant['name'] ); ?> <code style="font-size:12px;color:#6b7280;font-weight:normal"><?php echo esc_html( $tenant['slug'] ); ?></code></h2>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'mimamori_bot_update_tenant' ); ?>
        <input type="hidden" name="action" value="mimamori_bot_update_tenant">
        <input type="hidden" name="tenant_id" value="<?php echo esc_attr( (string) $tenant['id'] ); ?>">
        <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">

        <div class="mb-form-group">
            <label>スラッグ</label>
            <code style="background:#f1f5f9;padding:4px 8px;border-radius:4px"><?php echo esc_html( $tenant['slug'] ); ?></code>
        </div>

        <?php if ( $is_admin ) : ?>
        <div class="mb-form-group">
            <label for="owner_user_id_edit">オーナーユーザー</label>
            <?php wp_dropdown_users( [
                'name'             => 'owner_user_id',
                'id'               => 'owner_user_id_edit',
                'selected'         => (int) $tenant['user_id'],
                'show_option_none' => '— 未割当 —',
            ] ); ?>
            <p class="description">オーナーユーザーが管理画面でこのテナントを編集できます。</p>
        </div>
        <?php endif; ?>

        <div class="mb-form-group">
            <label for="name">表示名</label>
            <input type="text" id="name" name="name" value="<?php echo esc_attr( $tenant['name'] ); ?>" required>
        </div>

        <div class="mb-form-group">
            <label for="persona">ペルソナ</label>
            <input type="text" id="persona" name="persona" value="<?php echo esc_attr( (string) ( $tenant['persona'] ?? '' ) ); ?>" placeholder="例: お問い合わせアシスタント / 商品案内スタッフ">
            <p class="description">AIの役割を一言で。応答のトーンに影響します。</p>
        </div>

        <h3 style="margin-top:28px;padding-top:18px;border-top:1px solid #e5e7eb">🎨 見た目カスタマイズ</h3>
        <p class="description" style="margin-bottom:16px">チャット画面のタイトル・配色・吹き出しアイコンを変更できます。空欄にするとデフォルトに戻ります。</p>

        <div class="mb-form-group">
            <label for="title_text">チャットタイトル</label>
            <input type="text" id="title_text" name="title_text" maxlength="80" value="<?php echo esc_attr( (string) ( $tenant['title_text'] ?? '' ) ); ?>" placeholder="AIアシスタント">
            <p class="description">チャットウィンドウのヘッダーに表示されます。未設定時は「AIアシスタント」。</p>
        </div>

        <div class="mb-form-group" style="display:flex;gap:24px;flex-wrap:wrap;max-width:none">
            <div style="flex:0 0 220px">
                <label for="theme_primary">メインカラー</label>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="color" id="theme_primary_picker" value="<?php echo esc_attr( (string) ( $tenant['theme_primary'] ?: '#2563eb' ) ); ?>" style="width:48px;height:36px;padding:2px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer">
                    <input type="text" id="theme_primary" name="theme_primary" value="<?php echo esc_attr( (string) ( $tenant['theme_primary'] ?? '' ) ); ?>" placeholder="#2563eb" pattern="^#[0-9a-fA-F]{3,8}$" style="flex:1;max-width:140px;font-family:monospace">
                </div>
                <p class="description">ヘッダー・送信ボタン・ユーザー吹き出し色</p>
            </div>
            <div style="flex:0 0 220px">
                <label for="theme_on_primary">メインカラー上の文字色</label>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="color" id="theme_on_primary_picker" value="<?php echo esc_attr( (string) ( $tenant['theme_on_primary'] ?: '#ffffff' ) ); ?>" style="width:48px;height:36px;padding:2px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer">
                    <input type="text" id="theme_on_primary" name="theme_on_primary" value="<?php echo esc_attr( (string) ( $tenant['theme_on_primary'] ?? '' ) ); ?>" placeholder="#ffffff" pattern="^#[0-9a-fA-F]{3,8}$" style="flex:1;max-width:140px;font-family:monospace">
                </div>
                <p class="description">ヘッダー・ボタン上の文字色</p>
            </div>
        </div>

        <h3 style="margin-top:24px">💬 吹き出しアイコン (FAB)</h3>

        <div class="mb-form-group">
            <label for="fab_icon_url">バナー画像 (吹き出しアイコン)</label>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="url" id="fab_icon_url" name="fab_icon_url" value="<?php echo esc_attr( (string) ( $tenant['fab_icon_url'] ?? '' ) ); ?>" placeholder="https://example.com/banner.png" style="flex:1;min-width:280px;max-width:480px">
                <button type="button" class="mb-btn mb-btn-primary" id="mb-pick-fab-icon">📁 画像をアップロード / 選択</button>
                <button type="button" class="mb-btn mb-btn-link" id="mb-clear-fab-icon">クリア</button>
            </div>
            <p class="description">「画像をアップロード / 選択」ボタンから WordPress メディアライブラリを開いて新規アップロードまたは既存画像を選択できます。推奨: 透過 PNG / SVG、正方形、64〜128px。未設定時は💬白アイコン。</p>
            <div id="mb-fab-icon-preview" style="margin-top:10px<?php echo empty( $tenant['fab_icon_url'] ) ? ';display:none' : ''; ?>">
                <img src="<?php echo esc_url( (string) ( $tenant['fab_icon_url'] ?? '' ) ); ?>" alt="" style="width:56px;height:56px;border-radius:28px;background:<?php echo esc_attr( (string) ( $tenant['fab_bg_color'] ?: ( $tenant['theme_primary'] ?: '#2563eb' ) ) ); ?>;object-fit:contain;padding:8px;box-shadow:0 4px 12px rgba(0,0,0,.15)">
            </div>
        </div>

        <div class="mb-form-group" style="max-width:none">
            <label for="fab_bg_color">アイコン背景色</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="color" id="fab_bg_color_picker" value="<?php echo esc_attr( (string) ( $tenant['fab_bg_color'] ?: ( $tenant['theme_primary'] ?: '#2563eb' ) ) ); ?>" style="width:48px;height:36px;padding:2px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer">
                <input type="text" id="fab_bg_color" name="fab_bg_color" value="<?php echo esc_attr( (string) ( $tenant['fab_bg_color'] ?? '' ) ); ?>" placeholder="メインカラーを使用" pattern="^#[0-9a-fA-F]{3,8}$" style="flex:0 0 200px;max-width:200px;font-family:monospace">
            </div>
            <p class="description">空欄でメインカラーと同じ</p>
        </div>

        <?php
        $fab_rounded_on = ! isset( $tenant['fab_rounded'] ) || (int) $tenant['fab_rounded'] === 1;
        $fab_shadow_on  = ! isset( $tenant['fab_shadow'] )  || (int) $tenant['fab_shadow']  === 1;
        $fab_size_val   = isset( $tenant['fab_size'] ) ? (int) $tenant['fab_size'] : 56;
        $fab_size_md_val = ( isset( $tenant['fab_size_md'] ) && $tenant['fab_size_md'] !== null ) ? (int) $tenant['fab_size_md'] : '';
        ?>
        <input type="hidden" name="_fab_effects_section" value="1">
        <div class="mb-form-group" style="max-width:none">
            <label style="font-weight:600">エフェクト</label>
            <p class="description" style="margin-bottom:10px">アイコンの見た目に効果を追加できます。チェックを外すと無効になります。</p>
            <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer;margin-bottom:8px;max-width:480px;background:#fff">
                <input type="checkbox" name="fab_rounded" value="1" <?php checked( $fab_rounded_on ); ?> style="width:18px;height:18px;cursor:pointer">
                <span style="flex:1">
                    <strong style="font-size:13px;color:#0f172a">角丸を付ける</strong>
                    <span style="display:block;font-size:12px;color:#64748b;margin-top:2px">ON: 円形 (デフォルト) / OFF: 角ばった四角形</span>
                </span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer;max-width:480px;background:#fff">
                <input type="checkbox" name="fab_shadow" value="1" <?php checked( $fab_shadow_on ); ?> style="width:18px;height:18px;cursor:pointer">
                <span style="flex:1">
                    <strong style="font-size:13px;color:#0f172a">シャドウを付ける</strong>
                    <span style="display:block;font-size:12px;color:#64748b;margin-top:2px">FAB に立体感を出すためのドロップシャドウ</span>
                </span>
            </label>
        </div>

        <div class="mb-form-group" style="max-width:none">
            <label style="font-weight:600">サイズ (バナーの一辺の長さ)</label>
            <p class="description" style="margin-bottom:10px">ブラウザ幅に応じて 2 段階で切り替えできます。32〜120px の範囲。「中画面以下」を空欄にすると常に「大画面」の値が使われます。</p>

            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:8px">
                <div style="flex:0 0 320px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px">
                    <div style="font-weight:600;font-size:13px;color:#0f172a;margin-bottom:6px">🖥️ 大画面 (1440px 以上)</div>
                    <p class="description" style="margin:0 0 8px 0">通常のデスクトップ向けサイズ</p>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="number" id="fab_size" name="fab_size" min="32" max="120" value="<?php echo esc_attr( (string) $fab_size_val ); ?>" style="width:100%">
                        <span style="font-size:13px;color:#64748b">px</span>
                    </div>
                </div>

                <div style="flex:0 0 320px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px">
                    <div style="font-weight:600;font-size:13px;color:#0f172a;margin-bottom:6px">💻 中画面以下 (1440px 未満)</div>
                    <p class="description" style="margin:0 0 8px 0">ノートPC・タブレット・スマホ向け</p>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="number" id="fab_size_md" name="fab_size_md" min="32" max="120" value="<?php echo esc_attr( (string) $fab_size_md_val ); ?>" placeholder="大画面と同じ" style="width:100%">
                        <span style="font-size:13px;color:#64748b">px</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-form-group" style="max-width:none">
            <label style="font-weight:600">アイコン表示位置</label>
            <p class="description" style="margin-bottom:10px">画面の右下を基準にした距離。PC・スマホで個別に設定できます。スマホの値を空欄にすると PC と同じ値が使われます。</p>

            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:12px">
                <div style="flex:0 0 320px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px">
                    <div style="font-weight:600;font-size:13px;color:#0f172a;margin-bottom:10px">🖥️ PC (画面幅 601px 以上)</div>
                    <div style="display:flex;gap:12px">
                        <div style="flex:1">
                            <label for="fab_offset_x" style="font-size:12px">右からの距離 (px)</label>
                            <input type="number" id="fab_offset_x" name="fab_offset_x" min="0" max="200" value="<?php echo esc_attr( (string) ( $tenant['fab_offset_x'] ?? 20 ) ); ?>" style="width:100%">
                        </div>
                        <div style="flex:1">
                            <label for="fab_offset_y" style="font-size:12px">下からの距離 (px)</label>
                            <input type="number" id="fab_offset_y" name="fab_offset_y" min="0" max="200" value="<?php echo esc_attr( (string) ( $tenant['fab_offset_y'] ?? 20 ) ); ?>" style="width:100%">
                        </div>
                    </div>
                </div>

                <div style="flex:0 0 320px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px">
                    <div style="font-weight:600;font-size:13px;color:#0f172a;margin-bottom:10px">📱 スマホ (画面幅 600px 以下)</div>
                    <div style="display:flex;gap:12px">
                        <div style="flex:1">
                            <label for="fab_offset_x_sp" style="font-size:12px">右からの距離 (px)</label>
                            <input type="number" id="fab_offset_x_sp" name="fab_offset_x_sp" min="0" max="200" value="<?php echo esc_attr( $tenant['fab_offset_x_sp'] === null ? '' : (string) $tenant['fab_offset_x_sp'] ); ?>" placeholder="PC値と同じ" style="width:100%">
                        </div>
                        <div style="flex:1">
                            <label for="fab_offset_y_sp" style="font-size:12px">下からの距離 (px)</label>
                            <input type="number" id="fab_offset_y_sp" name="fab_offset_y_sp" min="0" max="200" value="<?php echo esc_attr( $tenant['fab_offset_y_sp'] === null ? '' : (string) $tenant['fab_offset_y_sp'] ); ?>" placeholder="PC値と同じ" style="width:100%">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <h3 style="margin-top:28px;padding-top:18px;border-top:1px solid #e5e7eb">💬 初期メッセージ</h3>
        <p class="description" style="margin-bottom:16px">チャットを開いた時に最初に表示する案内文です。空欄にするとデフォルト文が表示されます。</p>

        <div class="mb-form-group">
            <label for="welcome_message">初期メッセージ</label>
            <?php
            $welcome_default = "こんにちは！👋\nサービス内容・料金・対応エリアなど、お気軽にお尋ねください。\n担当者への直接のご相談もご案内できます。";
            $welcome_value   = (string) ( $tenant['welcome_message'] ?? '' );
            ?>
            <textarea id="welcome_message" name="welcome_message" rows="4" maxlength="500" placeholder="<?php echo esc_attr( $welcome_default ); ?>"><?php echo esc_textarea( $welcome_value ); ?></textarea>
            <p class="description">改行可。2〜3行・全体で200字以内が目安です。装飾は絵文字1個まで。空欄ならデフォルト文 (上のプレースホルダ) が使われます。</p>
        </div>

        <h3 style="margin-top:28px;padding-top:18px;border-top:1px solid #e5e7eb">🎭 担当アイコン</h3>
        <p class="description" style="margin-bottom:16px">チャットのヘッダー左と、アシスタント発言の左に表示されるアイコンを選択できます。3 パターンの画像 + 「表示しない」の 4 種類から選べます。</p>

        <?php
        $current_avatar  = (string) ( $tenant['assistant_avatar'] ?? '' );
        $avatar_presets  = class_exists( 'Mimamori_Bot_Avatars' ) ? Mimamori_Bot_Avatars::presets() : [];
        ?>
        <div class="mb-form-group" style="max-width:none">
            <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(130px, 1fr));gap:12px;max-width:600px">
                <label class="mb-avatar-option"
                       style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 8px;
                              border:2px solid <?php echo $current_avatar === '' ? '#2563eb' : '#e5e7eb'; ?>;
                              background:<?php echo $current_avatar === '' ? '#eff6ff' : '#fff'; ?>;
                              border-radius:12px;transition:border-color .15s,background-color .15s;position:relative">
                    <input type="radio" name="assistant_avatar" value="" <?php checked( $current_avatar, '' ); ?>
                           style="position:absolute;opacity:0;pointer-events:none">
                    <div style="width:64px;height:64px;border-radius:50%;background:#f1f5f9;color:#94a3b8;
                                display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600">なし</div>
                    <span style="font-size:13px;color:#334155;font-weight:500">表示しない</span>
                </label>

                <?php foreach ( $avatar_presets as $key => $info ) :
                    $is_selected = ( $current_avatar === $key );
                    $img_url     = (string) ( $info['img'] ?? '' );
                ?>
                <label class="mb-avatar-option"
                       style="cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 8px;
                              border:2px solid <?php echo $is_selected ? '#2563eb' : '#e5e7eb'; ?>;
                              background:<?php echo $is_selected ? '#eff6ff' : '#fff'; ?>;
                              border-radius:12px;transition:border-color .15s,background-color .15s;position:relative">
                    <input type="radio" name="assistant_avatar" value="<?php echo esc_attr( $key ); ?>" <?php checked( $current_avatar, $key ); ?>
                           style="position:absolute;opacity:0;pointer-events:none">
                    <div style="width:64px;height:64px;border-radius:50%;overflow:hidden;background:#f1f5f9;
                                display:flex;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,.06)">
                        <?php if ( $img_url ) : ?>
                            <img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $info['label'] ); ?>"
                                 style="width:100%;height:100%;object-fit:cover;display:block" loading="lazy" decoding="async"
                                 onerror="this.style.display='none';this.parentNode.style.background='#fee2e2';this.parentNode.innerHTML='<span style=\'font-size:10px;color:#dc2626\'>画像なし</span>'">
                        <?php endif; ?>
                    </div>
                    <span style="font-size:13px;color:#334155;text-align:center;line-height:1.3;font-weight:500"><?php echo esc_html( $info['label'] ); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="description" style="margin-top:10px">クリックで選択。「表示しない」を選ぶとアイコンは出ません。</p>
        </div>

        <script>
        (function () {
            // ラジオ選択でカードのハイライトを切り替え
            var opts = document.querySelectorAll('.mb-avatar-option');
            function refresh() {
                opts.forEach(function (o) {
                    var input = o.querySelector('input[type=radio]');
                    var on = input && input.checked;
                    o.style.borderColor = on ? '#2563eb' : '#e5e7eb';
                    o.style.background  = on ? '#eff6ff' : '#fff';
                });
            }
            opts.forEach(function (o) {
                o.addEventListener('click', function () {
                    var input = o.querySelector('input[type=radio]');
                    if (input) { input.checked = true; refresh(); }
                });
            });
        })();
        </script>

        <div class="mb-form-group">
            <label for="cta_url_quote">見積もりCTA URL</label>
            <input type="url" id="cta_url_quote" name="cta_url_quote" value="<?php echo esc_attr( (string) ( $tenant['cta_url_quote'] ?? '' ) ); ?>" placeholder="https://example.com/quote">
            <p class="description">回答下に「見積もりへ進む」ボタンとして表示されます。</p>
        </div>

        <div class="mb-form-group">
            <label for="cta_url_contact">問い合わせCTA URL</label>
            <input type="url" id="cta_url_contact" name="cta_url_contact" value="<?php echo esc_attr( (string) ( $tenant['cta_url_contact'] ?? '' ) ); ?>" placeholder="https://example.com/contact">
        </div>

        <div class="mb-form-group">
            <label for="allowed_origins">許可Origin</label>
            <?php $ao = is_array( $tenant['allowed_origins'] ) ? implode( "\n", $tenant['allowed_origins'] ) : ''; ?>
            <textarea id="allowed_origins" name="allowed_origins" rows="4" placeholder="https://example.com"><?php echo esc_textarea( $ao ); ?></textarea>
            <p class="description">1行1Origin。Widgetを設置するサイトを記載。プロトコル(https://)必須。許可されていないサイトからの埋め込みはブロックされます。</p>
        </div>

        <?php if ( $is_admin ) : ?>
        <div class="mb-form-group">
            <label for="rate_limit_rpm">レート制限 (req/分) <span style="font-size:11px;color:#9ca3af">[運営者のみ]</span></label>
            <input type="number" id="rate_limit_rpm" name="rate_limit_rpm" min="10" max="600" value="<?php echo esc_attr( (string) $tenant['rate_limit_rpm'] ); ?>" style="max-width:120px"> req/分
        </div>

        <div class="mb-form-group">
            <label for="monthly_budget_jpy">月次バジェット (円) <span style="font-size:11px;color:#9ca3af">[運営者のみ]</span></label>
            <input type="number" id="monthly_budget_jpy" name="monthly_budget_jpy" min="0" value="<?php echo esc_attr( (string) ( $tenant['monthly_budget_jpy'] ?? '' ) ); ?>" style="max-width:160px"> 円
            <p class="description">0または空欄で無制限。超過すると公開Widgetが一時停止します。</p>
        </div>
        <?php endif; ?>

        <div class="mb-form-group">
            <label for="system_prompt">システム指示 (プロンプト)</label>
            <textarea id="system_prompt" name="system_prompt" rows="8" class="code"><?php echo esc_textarea( (string) ( $tenant['system_prompt'] ?? '' ) ); ?></textarea>
            <p class="description">AIに与える役割・口調・禁則事項などを記述。</p>
        </div>

        <h3 style="margin-top:28px;padding-top:18px;border-top:1px solid #e5e7eb">🔔 効果音</h3>
        <p class="description" style="margin-bottom:16px">チャットの開閉や送信時の効果音をオン/オフできます。チェックを外すと無音になります。</p>

        <?php
        $sound_open_on = ! isset( $tenant['sound_open_enabled'] ) || (int) $tenant['sound_open_enabled'] === 1;
        $sound_send_on = ! isset( $tenant['sound_send_enabled'] ) || (int) $tenant['sound_send_enabled'] === 1;
        ?>
        <input type="hidden" name="_sound_section" value="1">
        <div class="mb-form-group" style="max-width:none">
            <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer;margin-bottom:8px;max-width:480px;background:#fff">
                <input type="checkbox" name="sound_open_enabled" value="1" <?php checked( $sound_open_on ); ?> style="width:18px;height:18px;cursor:pointer">
                <span style="flex:1">
                    <strong style="font-size:13px;color:#0f172a">バナークリック時の効果音</strong>
                    <span style="display:block;font-size:12px;color:#64748b;margin-top:2px">FAB (吹き出しアイコン) を押してチャットを開いた時の "ポン" 音</span>
                </span>
            </label>
            <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;cursor:pointer;max-width:480px;background:#fff">
                <input type="checkbox" name="sound_send_enabled" value="1" <?php checked( $sound_send_on ); ?> style="width:18px;height:18px;cursor:pointer">
                <span style="flex:1">
                    <strong style="font-size:13px;color:#0f172a">送信時の効果音</strong>
                    <span style="display:block;font-size:12px;color:#64748b;margin-top:2px">メッセージを送信した時の "シュッ" 音</span>
                </span>
            </label>
        </div>

        <div class="mb-form-group">
            <label for="status">ステータス</label>
            <select id="status" name="status" style="max-width:200px">
                <option value="active"<?php selected( (string) ( $tenant['status'] ?? '' ), 'active' ); ?>>稼働中</option>
                <option value="suspended"<?php selected( (string) ( $tenant['status'] ?? '' ), 'suspended' ); ?>>停止</option>
            </select>
        </div>

        <button type="submit" class="mb-btn mb-btn-primary">設定を保存</button>
    </form>
</div>

<script>
(function () {
    // カラーピッカー ↔ テキスト入力 同期
    function syncColor(pickerId, textId) {
        var picker = document.getElementById(pickerId);
        var text   = document.getElementById(textId);
        if (!picker || !text) return;
        picker.addEventListener('input', function () { text.value = picker.value; });
        text.addEventListener('input', function () {
            var v = text.value.trim();
            if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(v)) picker.value = v;
        });
    }
    syncColor('theme_primary_picker',    'theme_primary');
    syncColor('theme_on_primary_picker', 'theme_on_primary');
    syncColor('fab_bg_color_picker',     'fab_bg_color');

    // FAB アイコン: メディアライブラリ
    var pickBtn  = document.getElementById('mb-pick-fab-icon');
    var clearBtn = document.getElementById('mb-clear-fab-icon');
    var iconUrl  = document.getElementById('fab_icon_url');
    var preview  = document.getElementById('mb-fab-icon-preview');
    var previewImg = preview ? preview.querySelector('img') : null;

    function updatePreview(url) {
        if (!preview || !previewImg) return;
        if (url) {
            previewImg.src = url;
            preview.style.display = '';
        } else {
            previewImg.src = '';
            preview.style.display = 'none';
        }
    }

    if (pickBtn && iconUrl && window.wp && wp.media) {
        var frame;
        pickBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({
                title: 'チャットボットの吹き出しアイコン',
                button: { text: 'この画像を使う' },
                library: { type: 'image' },
                multiple: false
            });
            frame.on('select', function () {
                var att = frame.state().get('selection').first().toJSON();
                iconUrl.value = att.url || '';
                updatePreview(att.url || '');
            });
            frame.open();
        });
    } else if (pickBtn) {
        pickBtn.style.display = 'none';
    }
    if (clearBtn && iconUrl) {
        clearBtn.addEventListener('click', function () {
            iconUrl.value = '';
            updatePreview('');
        });
    }
    if (iconUrl) {
        iconUrl.addEventListener('input', function () { updatePreview(iconUrl.value.trim()); });
    }
})();
</script>

<div class="mb-card">
    <h2>埋め込みコード</h2>
    <p>下記コードをクライアントサイトの <code>&lt;/body&gt;</code> 直前に貼り付けると、チャットが起動します。</p>
    <?php
    $widget_url = MIMAMORI_BOT_URL . 'public/widget/widget.js';
    $snippet = "<script>\n  (function(){\n    var s=document.createElement('script');\n    s.src='" . esc_js( $widget_url ) . "';\n    s.async=true;\n    s.dataset.tenant='" . esc_js( $tenant['slug'] ) . "';\n    s.dataset.key='" . esc_js( $tenant['public_key'] ) . "';\n    document.head.appendChild(s);\n  })();\n</script>";
    ?>
    <textarea readonly onclick="this.select()" rows="9" class="mb-snippet-box"><?php echo esc_textarea( $snippet ); ?></textarea>
    <p style="margin-top:12px"><strong>公開キー:</strong> <code><?php echo esc_html( $tenant['public_key'] ); ?></code></p>

    <h3>動作確認 (iframe テスト)</h3>
    <?php $embed = rest_url( MIMAMORI_BOT_REST_NS . '/embed?tenant=' . rawurlencode( $tenant['slug'] ) ); ?>
    <p><a href="<?php echo esc_url( $embed ); ?>" target="_blank" rel="noopener" class="mb-btn mb-btn-secondary">→ チャットUIを別タブで開く</a></p>
</div>

<div class="mb-card">
    <h2>キー再発行</h2>
    <p>キーを再発行すると既存の埋め込みコードは無効になります。差し替えが必要です。</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('キーを再発行します。既存埋め込みは無効になります。続行しますか？');">
        <?php wp_nonce_field( 'mimamori_bot_regenerate_keys' ); ?>
        <input type="hidden" name="action" value="mimamori_bot_regenerate_keys">
        <input type="hidden" name="tenant_id" value="<?php echo esc_attr( (string) $tenant['id'] ); ?>">
        <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">
        <button type="submit" class="mb-btn mb-btn-danger">🔁 キーを再発行</button>
    </form>
</div>
