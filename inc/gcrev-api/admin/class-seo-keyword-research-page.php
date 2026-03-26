<?php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }
if ( class_exists('Gcrev_SEO_Keyword_Research_Page') ) { return; }

/**
 * SEO キーワード調査 管理画面ページ
 *
 * - 新規親メニュー「SEO」を作成
 * - 子メニュー「キーワード調査」を追加
 * - クライアント選択 → AI調査実行 → グループ別キーワード一覧を表示
 */
class Gcrev_SEO_Keyword_Research_Page {

    private const PARENT_SLUG = 'gcrev-seo';
    private const MENU_SLUG   = 'gcrev-seo-keyword-research';

    /**
     * フック登録
     */
    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    /**
     * メニュー登録
     */
    public function add_menu_pages(): void {
        // SEO 親メニュー（未作成の場合のみ）
        if ( empty( $GLOBALS['admin_page_hooks'][ self::PARENT_SLUG ] ) ) {
            add_menu_page(
                'SEO',
                'SEO',
                'manage_options',
                self::PARENT_SLUG,
                '__return_null',
                'dashicons-search',
                31
            );
        }

        // キーワード調査 サブメニュー
        add_submenu_page(
            self::PARENT_SLUG,
            "\xF0\x9F\x94\x8D \u30AD\u30FC\u30EF\u30FC\u30C9\u8ABF\u67FB \u2014 SEO",
            "\xF0\x9F\x94\x8D \u30AD\u30FC\u30EF\u30FC\u30C9\u8ABF\u67FB",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * スクリプト（REST用nonce等）
     */
    public function enqueue_scripts( string $hook ): void {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }
        wp_localize_script( 'jquery-core', 'gcrevSeoKwResearch', [
            'restUrl' => esc_url_raw( rest_url( 'gcrev/v1/seo/keyword-research' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    /**
     * ページ描画
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'アクセス権限がありません。' );
        }

        $selected_user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

        // クライアント一覧
        $users = get_users( [
            'role__not_in' => [ 'administrator' ],
            'orderby'      => 'display_name',
            'order'        => 'ASC',
        ] );

        // 選択中のクライアント情報
        $client_settings = null;
        $area_label = '';
        $biz_type_label = '';
        $industry_label = '';
        if ( $selected_user_id > 0 ) {
            $client_settings = function_exists( 'gcrev_get_client_settings' )
                ? gcrev_get_client_settings( $selected_user_id )
                : [];
            $area_label = function_exists( 'gcrev_get_client_area_label' )
                ? gcrev_get_client_area_label( $client_settings )
                : '';
            $biz_type_label = function_exists( 'gcrev_get_business_type_label' )
                ? gcrev_get_business_type_label( $client_settings['business_type'] ?? [] )
                : '';
            $industry_label = $client_settings['industry'] ?? '';
        }

        ?>
        <div class="wrap">
            <h1 style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span style="font-size:28px;">🔍</span> キーワード調査
            </h1>
            <p style="color:#64748b; margin:0 0 24px; font-size:14px;">
                クライアント情報やサイト内容をもとに、SEOで狙うべきキーワード候補を調査・提案します。
            </p>

            <!-- ===== 条件エリア ===== -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:24px; max-width:900px; margin-bottom:24px;">
                <h2 style="margin:0 0 16px; font-size:16px; color:#1e293b;">📋 調査条件</h2>

                <!-- クライアント選択 -->
                <div style="margin-bottom:16px;">
                    <label for="gcrev-seo-user" style="display:block; font-weight:600; margin-bottom:4px; font-size:13px; color:#374151;">対象クライアント</label>
                    <select id="gcrev-seo-user" style="width:100%; max-width:400px; padding:6px 8px; border:1px solid #d1d5db; border-radius:4px;"
                            onchange="if(this.value){location.href='<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>&user_id='+this.value}">
                        <option value="">-- クライアントを選択 --</option>
                        <?php foreach ( $users as $u ) : ?>
                            <option value="<?php echo esc_attr( $u->ID ); ?>"
                                <?php selected( $selected_user_id, $u->ID ); ?>>
                                <?php echo esc_html( $u->display_name ); ?>
                                (<?php echo esc_html( $u->user_email ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ( $selected_user_id > 0 && $client_settings ) : ?>
                    <!-- クライアント情報（読み取り専用） -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:16px; margin-bottom:16px;">
                        <h3 style="margin:0 0 12px; font-size:14px; color:#475569;">📌 クライアント情報</h3>
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:8px 24px; font-size:13px;">
                            <?php
                            $info_items = [
                                'サイトURL'     => $client_settings['site_url'] ?? '',
                                'エリア'        => $area_label,
                                '業種'          => $industry_label,
                                '業種詳細'      => $client_settings['industry_detail'] ?? '',
                                'ビジネス形態'  => $biz_type_label,
                                'ペルソナ概要'  => $client_settings['persona_one_liner'] ?? '',
                            ];
                            foreach ( $info_items as $lbl => $val ) :
                                if ( empty( $val ) ) continue;
                            ?>
                                <div>
                                    <span style="color:#64748b;"><?php echo esc_html( $lbl ); ?>:</span>
                                    <span style="color:#1e293b; font-weight:500;"><?php echo esc_html( $val ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ( empty( $client_settings['site_url'] ) ) : ?>
                            <div style="margin-top:12px; padding:8px 12px; background:#fef2f2; border:1px solid #fecaca; border-radius:4px; color:#b91c1c; font-size:13px;">
                                ⚠ サイトURLが未設定です。クライアント設定でサイトURLを登録してください。
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- シードキーワード -->
                    <div style="margin-bottom:16px;">
                        <label for="gcrev-seo-seeds" style="display:block; font-weight:600; margin-bottom:4px; font-size:13px; color:#374151;">
                            シードキーワード（任意）
                        </label>
                        <textarea id="gcrev-seo-seeds" rows="3"
                                  style="width:100%; max-width:600px; padding:8px; border:1px solid #d1d5db; border-radius:4px; font-size:13px;"
                                  placeholder="追加で調査したいキーワードがあれば入力（カンマまたは改行区切り）"></textarea>
                    </div>

                    <!-- 実行ボタン -->
                    <button id="gcrev-seo-run" type="button"
                            style="background:#2563eb; color:#fff; border:none; padding:10px 24px; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;"
                            <?php echo empty( $client_settings['site_url'] ) ? 'disabled style="opacity:0.5;cursor:not-allowed;background:#2563eb;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:14px;font-weight:600;"' : ''; ?>>
                        🔍 キーワード調査を実行
                    </button>
                    <span id="gcrev-seo-status" style="margin-left:12px; font-size:13px; color:#64748b;"></span>

                <?php endif; ?>
            </div>

            <!-- ===== ローディング ===== -->
            <div id="gcrev-seo-loading" style="display:none; text-align:center; padding:40px;">
                <div style="display:inline-block; width:40px; height:40px; border:4px solid #e2e8f0; border-top-color:#2563eb; border-radius:50%; animation:gcrevSpin 0.8s linear infinite;"></div>
                <p style="margin-top:12px; color:#64748b; font-size:14px;">AIがキーワード候補を分析中です…（30秒〜1分程度かかります）</p>
            </div>
            <style>@keyframes gcrevSpin{to{transform:rotate(360deg)}}</style>

            <!-- ===== エラー表示 ===== -->
            <div id="gcrev-seo-error" style="display:none; max-width:900px; margin-bottom:24px; padding:16px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; color:#b91c1c; font-size:14px;"></div>

            <!-- ===== AI戦略サマリー ===== -->
            <div id="gcrev-seo-summary" style="display:none; max-width:900px; margin-bottom:24px;">
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:24px;">
                    <h2 style="margin:0 0 16px; font-size:18px; color:#166534;">💡 AI戦略サマリー</h2>
                    <div id="gcrev-seo-summary-content" style="font-size:14px; line-height:1.8; color:#1e293b;"></div>
                </div>
            </div>

            <!-- ===== グループ別キーワード一覧 ===== -->
            <div id="gcrev-seo-results" style="display:none; max-width:1100px;">
                <h2 style="font-size:18px; margin:0 0 16px; color:#1e293b;">📊 キーワード候補一覧</h2>
                <div id="gcrev-seo-groups"></div>
            </div>

        </div><!-- .wrap -->

        <?php if ( $selected_user_id > 0 ) : ?>
        <script>
        (function() {
            var userId = <?php echo (int) $selected_user_id; ?>;
            var btn = document.getElementById('gcrev-seo-run');
            if (!btn) return;

            var groupMeta = {
                immediate:    { icon: '🎯', label: '今すぐ狙うべきキーワード',          color: '#dc2626' },
                local_seo:    { icon: '📍', label: '地域SEO向けキーワード',              color: '#059669' },
                comparison:   { icon: '🔄', label: '比較・検討流入向けキーワード',       color: '#d97706' },
                column:       { icon: '📝', label: 'コラム記事向きキーワード',           color: '#7c3aed' },
                service_page: { icon: '🛠', label: 'サービスページ向きキーワード',      color: '#2563eb' }
            };

            var typeBadge = {
                '本命':       { bg: '#dbeafe', color: '#1e40af' },
                '補助':       { bg: '#f1f5f9', color: '#475569' },
                'ローカルSEO':{ bg: '#d1fae5', color: '#065f46' },
                '比較流入':   { bg: '#ffedd5', color: '#9a3412' },
                'コラム向け': { bg: '#ede9fe', color: '#5b21b6' }
            };
            var priBadge = {
                '高': { bg: '#fee2e2', color: '#b91c1c' },
                '中': { bg: '#fef9c3', color: '#854d0e' },
                '低': { bg: '#f1f5f9', color: '#64748b' }
            };

            function badge(text, map) {
                var s = map[text] || { bg: '#f1f5f9', color: '#475569' };
                return '<span style="display:inline-block;padding:2px 8px;font-size:11px;font-weight:600;border-radius:3px;background:'+s.bg+';color:'+s.color+';">'+esc(text)+'</span>';
            }
            function esc(s) {
                if (!s) return '';
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }

            btn.addEventListener('click', function() {
                var seeds = (document.getElementById('gcrev-seo-seeds').value || '').trim();
                btn.disabled = true;
                btn.style.opacity = '0.6';
                document.getElementById('gcrev-seo-loading').style.display = 'block';
                document.getElementById('gcrev-seo-error').style.display = 'none';
                document.getElementById('gcrev-seo-summary').style.display = 'none';
                document.getElementById('gcrev-seo-results').style.display = 'none';
                document.getElementById('gcrev-seo-status').textContent = '';

                fetch(gcrevSeoKwResearch.restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': gcrevSeoKwResearch.nonce
                    },
                    body: JSON.stringify({ user_id: userId, seed_keywords: seeds })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    document.getElementById('gcrev-seo-loading').style.display = 'none';
                    btn.disabled = false;
                    btn.style.opacity = '1';

                    if (!data.success) {
                        var errEl = document.getElementById('gcrev-seo-error');
                        errEl.innerHTML = '❌ ' + esc(data.error || 'エラーが発生しました');
                        errEl.style.display = 'block';
                        return;
                    }

                    // サマリー描画
                    var summary = data.summary || {};
                    var summaryHtml = '';
                    var summaryItems = [
                        { icon: '🎯', title: '優先すべきキーワードの方向性', key: 'direction' },
                        { icon: '📄', title: 'まず改善すべきページ', key: 'priority_pages' },
                        { icon: '➕', title: '新規作成すべきページ案', key: 'new_pages' },
                        { icon: '✏️', title: 'タイトル・見出しに含めるべき語句', key: 'title_tips' },
                        { icon: '📍', title: 'ローカルSEO 地域掛け合わせ案', key: 'local_tips' }
                    ];
                    summaryItems.forEach(function(si) {
                        var val = summary[si.key] || '';
                        if (!val) return;
                        summaryHtml += '<div style="margin-bottom:14px;"><strong>' + si.icon + ' ' + si.title + '</strong>';
                        summaryHtml += '<p style="margin:4px 0 0; color:#374151;">' + esc(val) + '</p></div>';
                    });
                    document.getElementById('gcrev-seo-summary-content').innerHTML = summaryHtml;
                    document.getElementById('gcrev-seo-summary').style.display = 'block';

                    // グループ別テーブル描画
                    var groups = data.groups || {};
                    var container = document.getElementById('gcrev-seo-groups');
                    container.innerHTML = '';
                    var groupOrder = ['immediate', 'local_seo', 'comparison', 'column', 'service_page'];

                    groupOrder.forEach(function(gk) {
                        var items = groups[gk] || [];
                        var gm = groupMeta[gk] || { icon: '', label: gk, color: '#64748b' };
                        if (items.length === 0) return;

                        var section = document.createElement('div');
                        section.style.cssText = 'margin-bottom:24px;';

                        // グループヘッダー
                        var header = document.createElement('div');
                        header.style.cssText = 'display:flex;align-items:center;gap:8px;padding:12px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px 8px 0 0;cursor:pointer;';
                        header.innerHTML = '<span style="font-size:20px;">' + gm.icon + '</span>'
                            + '<h3 style="margin:0;font-size:15px;color:' + gm.color + ';">' + esc(gm.label)
                            + ' <span style="font-weight:400;color:#94a3b8;font-size:13px;">(' + items.length + '件)</span></h3>'
                            + '<span class="gcrev-seo-arrow" style="margin-left:auto;font-size:12px;color:#94a3b8;transition:transform 0.2s;">▼</span>';

                        var tableWrap = document.createElement('div');
                        tableWrap.style.cssText = 'border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;overflow-x:auto;';

                        header.addEventListener('click', function() {
                            var isHidden = tableWrap.style.display === 'none';
                            tableWrap.style.display = isHidden ? 'block' : 'none';
                            header.querySelector('.gcrev-seo-arrow').style.transform = isHidden ? '' : 'rotate(-90deg)';
                        });

                        // テーブル
                        var html = '<table class="widefat striped" style="margin:0;border:none;">'
                            + '<thead><tr>'
                            + '<th style="width:22%;">キーワード</th>'
                            + '<th style="width:10%;">タイプ</th>'
                            + '<th style="width:8%;">優先度</th>'
                            + '<th style="width:14%;">推奨ページ種別</th>'
                            + '<th style="width:28%;">提案理由</th>'
                            + '<th style="width:18%;">対応アクション</th>'
                            + '</tr></thead><tbody>';

                        items.forEach(function(item) {
                            html += '<tr>'
                                + '<td style="font-weight:600;color:#1e293b;">' + esc(item.keyword) + '</td>'
                                + '<td>' + badge(item.type, typeBadge) + '</td>'
                                + '<td>' + badge(item.priority, priBadge) + '</td>'
                                + '<td style="font-size:12px;color:#475569;">' + esc(item.page_type) + '</td>'
                                + '<td style="font-size:12px;color:#475569;">' + esc(item.reason) + '</td>'
                                + '<td style="font-size:12px;">' + badge(item.action, {
                                    '既存ページ改善': { bg: '#dbeafe', color: '#1e40af' },
                                    '新規ページ追加': { bg: '#d1fae5', color: '#065f46' },
                                    'タイトル改善':   { bg: '#fef9c3', color: '#854d0e' },
                                    '見出し追加':     { bg: '#ede9fe', color: '#5b21b6' },
                                    '内部リンク強化': { bg: '#ffedd5', color: '#9a3412' }
                                }) + '</td>'
                                + '</tr>';
                        });

                        html += '</tbody></table>';
                        tableWrap.innerHTML = html;

                        section.appendChild(header);
                        section.appendChild(tableWrap);
                        container.appendChild(section);
                    });

                    document.getElementById('gcrev-seo-results').style.display = 'block';

                    // メタ情報
                    var meta = data.meta || {};
                    document.getElementById('gcrev-seo-status').textContent =
                        '✅ 調査完了（' + (meta.generated_at || '') + '）'
                        + ' GSC: ' + (meta.gsc_count || 0) + '件参照';
                })
                .catch(function(err) {
                    document.getElementById('gcrev-seo-loading').style.display = 'none';
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    var errEl = document.getElementById('gcrev-seo-error');
                    errEl.innerHTML = '❌ 通信エラーが発生しました: ' + esc(err.message || '不明なエラー');
                    errEl.style.display = 'block';
                });
            });
        })();
        </script>
        <?php endif;
    }
}
