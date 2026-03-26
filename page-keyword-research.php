<?php
/**
 * Template Name: キーワード調査
 * Description: クライアント情報やサイト内容をもとに、SEOで狙うべきキーワード候補を調査・提案します。
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// クライアント情報取得
$client_settings = function_exists( 'gcrev_get_client_settings' )
    ? gcrev_get_client_settings( $user_id )
    : [];
$area_label = function_exists( 'gcrev_get_client_area_label' )
    ? gcrev_get_client_area_label( $client_settings )
    : '';
$biz_type_label = function_exists( 'gcrev_get_business_type_label' )
    ? gcrev_get_business_type_label( $client_settings['business_type'] ?? [] )
    : '';
$industry_label   = $client_settings['industry'] ?? '';
$industry_detail  = $client_settings['industry_detail'] ?? '';
$site_url         = $client_settings['site_url'] ?? '';
$persona_one_liner = $client_settings['persona_one_liner'] ?? '';

set_query_var( 'gcrev_page_title', 'キーワード調査' );
set_query_var( 'gcrev_page_subtitle', 'サイト情報をもとに、SEOで狙うべきキーワード候補を調査・提案します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'キーワード調査', 'SEO' ) );

get_header();
?>
<style>
/* =========================================================
   キーワード調査 — スタイル
   ========================================================= */

/* 条件エリア */
.kwr-conditions {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 28px;
    margin-bottom: 28px;
}
.kwr-conditions__title {
    font-size: 16px;
    font-weight: 600;
    color: var(--mw-text-heading);
    margin: 0 0 20px;
}

/* クライアント情報グリッド */
.kwr-client-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px 24px;
    padding: 16px 20px;
    background: var(--mw-bg-secondary);
    border: 1px solid var(--mw-border-light);
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 13px;
}
.kwr-client-info__item-label {
    color: var(--mw-text-tertiary);
    font-size: 11px;
    margin-bottom: 2px;
}
.kwr-client-info__item-value {
    color: var(--mw-text-heading);
    font-weight: 500;
}

/* シード入力 */
.kwr-seeds {
    margin-bottom: 20px;
}
.kwr-seeds__label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--mw-text-secondary);
    margin-bottom: 6px;
}
.kwr-seeds textarea {
    width: 100%;
    max-width: 600px;
    padding: 10px 12px;
    border: 1px solid var(--mw-border-light);
    border-radius: 6px;
    font-size: 13px;
    resize: vertical;
    background: var(--mw-bg-primary);
    color: var(--mw-text-primary);
}

/* 実行ボタン */
.kwr-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.15s;
}
.kwr-btn--primary {
    background: var(--mw-primary-blue, #4A90A4);
    color: #fff;
}
.kwr-btn--primary:hover { opacity: 0.9; }
.kwr-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* 警告 */
.kwr-warning {
    padding: 10px 14px;
    background: rgba(201,90,79,0.08);
    border: 1px solid rgba(201,90,79,0.2);
    border-radius: 6px;
    color: #C95A4F;
    font-size: 13px;
    margin-bottom: 16px;
}

/* プログレス */
.kwr-progress {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
}
.kwr-progress.active { display: flex; }
.kwr-progress__inner {
    background: #fff;
    border-radius: 16px;
    padding: 40px;
    text-align: center;
    min-width: 300px;
}
.kwr-progress__spinner {
    width: 40px; height: 40px;
    margin: 0 auto 16px;
    border: 3px solid var(--mw-border-light, #e2e8f0);
    border-top-color: var(--mw-primary-teal, #4A90A4);
    border-radius: 50%;
    animation: kwr-spin 0.8s linear infinite;
}
@keyframes kwr-spin { to { transform: rotate(360deg); } }
.kwr-progress__text { font-size: 14px; color: var(--mw-text-secondary); }

/* トースト */
.kwr-toast {
    position: fixed;
    bottom: 24px;
    left: 24px;
    padding: 12px 20px;
    border-radius: 10px;
    background: #1A2F33;
    color: #fff;
    font-size: 14px;
    z-index: 10000;
    opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
}
.kwr-toast.active { opacity: 1; pointer-events: auto; }
.kwr-toast--error { background: #C95A4F; }

/* サマリーセクション */
.kwr-summary {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 28px;
    margin-bottom: 28px;
}
.kwr-summary__title {
    font-size: 16px;
    font-weight: 600;
    color: var(--mw-text-heading);
    margin: 0 0 20px;
}
.kwr-summary__item {
    margin-bottom: 16px;
}
.kwr-summary__item:last-child { margin-bottom: 0; }
.kwr-summary__item-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-heading);
    margin-bottom: 4px;
}
.kwr-summary__item-text {
    font-size: 13px;
    color: var(--mw-text-secondary);
    line-height: 1.7;
}

/* グループセクション */
.kwr-group {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    margin-bottom: 20px;
    overflow: hidden;
}
.kwr-group__header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 20px;
    cursor: pointer;
    transition: background 0.15s;
    user-select: none;
}
.kwr-group__header:hover { background: var(--mw-bg-secondary); }
.kwr-group__icon { font-size: 20px; }
.kwr-group__title {
    font-size: 15px;
    font-weight: 600;
    margin: 0;
    flex: 1;
}
.kwr-group__count {
    font-size: 13px;
    font-weight: 400;
    color: var(--mw-text-tertiary);
}
.kwr-group__arrow {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    transition: transform 0.2s;
}
.kwr-group__arrow.collapsed { transform: rotate(-90deg); }
.kwr-group__body {
    border-top: 1px solid var(--mw-border-light);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* テーブル */
.kwr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.kwr-table th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 600;
    color: var(--mw-text-secondary);
    font-size: 12px;
    border-bottom: 1px solid var(--mw-border-light);
    background: var(--mw-bg-secondary);
    white-space: nowrap;
}
.kwr-table td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--mw-border-light);
    vertical-align: top;
    line-height: 1.5;
}
.kwr-table tbody tr:hover { background: var(--mw-bg-secondary); }
.kwr-table .kwr-keyword-cell {
    font-weight: 600;
    color: var(--mw-text-heading);
    white-space: nowrap;
}

/* バッジ */
.kwr-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.kwr-badge--type-core       { background: rgba(74,144,164,0.12); color: #2D7A8F; }
.kwr-badge--type-support    { background: var(--mw-bg-tertiary, #f1f5f9); color: var(--mw-text-tertiary); }
.kwr-badge--type-local      { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.kwr-badge--type-comparison { background: rgba(201,168,76,0.15); color: #C9A84C; }
.kwr-badge--type-column     { background: rgba(124,58,237,0.1); color: #7C3AED; }

.kwr-badge--pri-high   { background: rgba(201,90,79,0.12); color: #C95A4F; }
.kwr-badge--pri-medium { background: rgba(201,168,76,0.15); color: #C9A84C; }
.kwr-badge--pri-low    { background: var(--mw-bg-tertiary, #f1f5f9); color: var(--mw-text-tertiary); }

.kwr-badge--action-improve  { background: rgba(74,144,164,0.12); color: #2D7A8F; }
.kwr-badge--action-new      { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.kwr-badge--action-title    { background: rgba(201,168,76,0.15); color: #C9A84C; }
.kwr-badge--action-heading  { background: rgba(124,58,237,0.1); color: #7C3AED; }
.kwr-badge--action-link     { background: rgba(201,90,79,0.08); color: #C95A4F; }

/* メタ情報 */
.kwr-meta {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-top: 8px;
}

/* 空状態 */
.kwr-empty {
    text-align: center;
    padding: 48px 20px;
    color: var(--mw-text-tertiary);
}
.kwr-empty__icon { font-size: 40px; margin-bottom: 12px; }
.kwr-empty__text { font-size: 15px; margin-bottom: 8px; color: var(--mw-text-secondary); }
.kwr-empty__sub { font-size: 13px; }

/* セクションタイトル */
.kwr-results-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--mw-text-heading);
    margin: 0 0 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* レスポンシブ */
@media (max-width: 768px) {
    .kwr-conditions { padding: 20px 16px; }
    .kwr-summary { padding: 20px 16px; }
    .kwr-client-info { grid-template-columns: 1fr 1fr; }
    .kwr-group__header { padding: 14px 16px; }
    .kwr-table th, .kwr-table td { padding: 10px 8px; }
}
</style>

<div class="content-area">

    <!-- プログレスオーバーレイ -->
    <div class="kwr-progress" id="kwrProgress">
        <div class="kwr-progress__inner">
            <div class="kwr-progress__spinner"></div>
            <div class="kwr-progress__text" id="kwrProgressText">AIがキーワード候補を分析中です…</div>
        </div>
    </div>

    <!-- トースト -->
    <div class="kwr-toast" id="kwrToast"></div>

    <!-- ===== 条件エリア ===== -->
    <div class="kwr-conditions">
        <h2 class="kwr-conditions__title">📋 調査条件</h2>

        <!-- クライアント情報 -->
        <?php
        $info_items = array_filter( [
            'サイトURL'     => $site_url,
            'エリア'        => $area_label,
            '業種'          => $industry_label,
            '業種詳細'      => $industry_detail,
            'ビジネス形態'  => $biz_type_label,
            'ペルソナ概要'  => $persona_one_liner,
        ] );
        if ( ! empty( $info_items ) ) :
        ?>
            <div class="kwr-client-info">
                <?php foreach ( $info_items as $lbl => $val ) : ?>
                    <div>
                        <div class="kwr-client-info__item-label"><?php echo esc_html( $lbl ); ?></div>
                        <div class="kwr-client-info__item-value"><?php echo esc_html( $val ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( empty( $site_url ) ) : ?>
            <div class="kwr-warning">
                ⚠ サイトURLが未設定です。設定画面でサイトURLを登録してから調査を実行してください。
            </div>
        <?php endif; ?>

        <!-- シードキーワード -->
        <div class="kwr-seeds">
            <label class="kwr-seeds__label" for="kwrSeeds">追加キーワード（任意）</label>
            <textarea id="kwrSeeds" rows="2"
                      placeholder="調べたいキーワードがあれば入力（カンマまたは改行区切り）"></textarea>
        </div>

        <!-- 実行ボタン -->
        <button id="kwrRunBtn" class="kwr-btn kwr-btn--primary" type="button"
                <?php echo empty( $site_url ) ? 'disabled' : ''; ?>>
            🔍 キーワード調査を実行
        </button>
        <span id="kwrMeta" class="kwr-meta"></span>
    </div>

    <!-- ===== AI戦略サマリー ===== -->
    <div class="kwr-summary" id="kwrSummary" style="display:none;">
        <h2 class="kwr-summary__title">💡 AI戦略サマリー</h2>
        <div id="kwrSummaryContent"></div>
    </div>

    <!-- ===== グループ別キーワード一覧 ===== -->
    <div id="kwrResults" style="display:none;">
        <h2 class="kwr-results-title">📊 キーワード候補一覧</h2>
        <div id="kwrGroups"></div>
    </div>

    <!-- ===== 空状態 ===== -->
    <div class="kwr-empty" id="kwrEmpty">
        <div class="kwr-empty__icon">🔍</div>
        <div class="kwr-empty__text">キーワード調査を実行してください</div>
        <div class="kwr-empty__sub">クライアント情報やサイト内容をもとに、AIがSEOキーワード候補を提案します</div>
    </div>

</div>

<script>
(function() {
    'use strict';

    var userId = <?php echo (int) $user_id; ?>;
    var restUrl = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gcrev/v1/seo/keyword-research' ) ) ); ?>;
    var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    var btn = document.getElementById('kwrRunBtn');
    if (!btn) return;

    /* グループ定義 */
    var groupMeta = {
        immediate:    { icon: '🎯', label: '今すぐ狙うべきキーワード',     color: '#C95A4F' },
        local_seo:    { icon: '📍', label: '地域SEO向けキーワード',        color: '#4E8A6B' },
        comparison:   { icon: '🔄', label: '比較・検討流入向けキーワード', color: '#C9A84C' },
        column:       { icon: '📝', label: 'コラム記事向きキーワード',     color: '#7C3AED' },
        service_page: { icon: '🛠', label: 'サービスページ向きキーワード', color: '#2D7A8F' }
    };
    var groupOrder = ['immediate', 'local_seo', 'comparison', 'column', 'service_page'];

    /* バッジマッピング */
    var typeClass = {
        '本命':       'kwr-badge--type-core',
        '補助':       'kwr-badge--type-support',
        'ローカルSEO':'kwr-badge--type-local',
        '比較流入':   'kwr-badge--type-comparison',
        'コラム向け': 'kwr-badge--type-column'
    };
    var priClass = {
        '高': 'kwr-badge--pri-high',
        '中': 'kwr-badge--pri-medium',
        '低': 'kwr-badge--pri-low'
    };
    var actClass = {
        '既存ページ改善': 'kwr-badge--action-improve',
        '新規ページ追加': 'kwr-badge--action-new',
        'タイトル改善':   'kwr-badge--action-title',
        '見出し追加':     'kwr-badge--action-heading',
        '内部リンク強化': 'kwr-badge--action-link'
    };

    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function badge(text, map) {
        var cls = map[text] || 'kwr-badge--type-support';
        return '<span class="kwr-badge ' + cls + '">' + esc(text) + '</span>';
    }

    function showProgress(msg) {
        document.getElementById('kwrProgressText').textContent = msg || 'AIがキーワード候補を分析中です…';
        document.getElementById('kwrProgress').classList.add('active');
    }
    function hideProgress() {
        document.getElementById('kwrProgress').classList.remove('active');
    }
    function showToast(msg, isError) {
        var el = document.getElementById('kwrToast');
        el.textContent = msg;
        el.className = 'kwr-toast active' + (isError ? ' kwr-toast--error' : '');
        setTimeout(function() { el.className = 'kwr-toast'; }, 4000);
    }

    btn.addEventListener('click', function() {
        var seeds = (document.getElementById('kwrSeeds').value || '').trim();
        btn.disabled = true;
        showProgress('AIがキーワード候補を分析中です…（30秒〜1分程度）');
        document.getElementById('kwrEmpty').style.display = 'none';
        document.getElementById('kwrSummary').style.display = 'none';
        document.getElementById('kwrResults').style.display = 'none';
        document.getElementById('kwrMeta').textContent = '';

        fetch(restUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify({ user_id: userId, seed_keywords: seeds })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            hideProgress();
            btn.disabled = false;

            if (!data.success) {
                showToast(data.error || 'エラーが発生しました', true);
                document.getElementById('kwrEmpty').style.display = '';
                return;
            }

            renderSummary(data.summary || {});
            renderGroups(data.groups || {});

            var meta = data.meta || {};
            document.getElementById('kwrMeta').textContent =
                '✅ 調査完了（' + (meta.generated_at || '') + '）  GSC: ' + (meta.gsc_count || 0) + '件参照';
        })
        .catch(function(err) {
            hideProgress();
            btn.disabled = false;
            showToast('通信エラー: ' + (err.message || '不明'), true);
            document.getElementById('kwrEmpty').style.display = '';
        });
    });

    /* ===== サマリー描画 ===== */
    function renderSummary(summary) {
        var items = [
            { icon: '🎯', title: '優先すべきキーワードの方向性', key: 'direction' },
            { icon: '📄', title: 'まず改善すべきページ',         key: 'priority_pages' },
            { icon: '➕', title: '新規作成すべきページ案',       key: 'new_pages' },
            { icon: '✏️', title: 'タイトル・見出しに含めるべき語句', key: 'title_tips' },
            { icon: '📍', title: 'ローカルSEO 地域掛け合わせ案', key: 'local_tips' }
        ];
        var html = '';
        items.forEach(function(si) {
            var val = summary[si.key] || '';
            if (!val) return;
            html += '<div class="kwr-summary__item">'
                + '<div class="kwr-summary__item-title">' + si.icon + ' ' + si.title + '</div>'
                + '<div class="kwr-summary__item-text">' + esc(val) + '</div>'
                + '</div>';
        });
        var el = document.getElementById('kwrSummaryContent');
        el.innerHTML = html;
        document.getElementById('kwrSummary').style.display = html ? '' : 'none';
    }

    /* ===== グループ別テーブル描画 ===== */
    function renderGroups(groups) {
        var container = document.getElementById('kwrGroups');
        container.innerHTML = '';
        var hasAny = false;

        groupOrder.forEach(function(gk) {
            var items = groups[gk] || [];
            if (items.length === 0) return;
            hasAny = true;
            var gm = groupMeta[gk];

            var div = document.createElement('div');
            div.className = 'kwr-group';

            /* ヘッダー */
            var header = document.createElement('div');
            header.className = 'kwr-group__header';
            header.innerHTML = '<span class="kwr-group__icon">' + gm.icon + '</span>'
                + '<h3 class="kwr-group__title" style="color:' + gm.color + ';">' + esc(gm.label)
                + ' <span class="kwr-group__count">(' + items.length + '件)</span></h3>'
                + '<span class="kwr-group__arrow">▼</span>';

            var body = document.createElement('div');
            body.className = 'kwr-group__body';

            header.addEventListener('click', function() {
                var hidden = body.style.display === 'none';
                body.style.display = hidden ? '' : 'none';
                header.querySelector('.kwr-group__arrow').className =
                    'kwr-group__arrow' + (hidden ? '' : ' collapsed');
            });

            /* テーブル */
            var html = '<table class="kwr-table"><thead><tr>'
                + '<th>キーワード</th>'
                + '<th>タイプ</th>'
                + '<th>優先度</th>'
                + '<th>推奨ページ種別</th>'
                + '<th>提案理由</th>'
                + '<th>対応アクション</th>'
                + '</tr></thead><tbody>';

            items.forEach(function(item) {
                html += '<tr>'
                    + '<td class="kwr-keyword-cell">' + esc(item.keyword) + '</td>'
                    + '<td>' + badge(item.type, typeClass) + '</td>'
                    + '<td>' + badge(item.priority, priClass) + '</td>'
                    + '<td style="font-size:12px;">' + esc(item.page_type) + '</td>'
                    + '<td style="font-size:12px;color:var(--mw-text-secondary);">' + esc(item.reason) + '</td>'
                    + '<td>' + badge(item.action, actClass) + '</td>'
                    + '</tr>';
            });

            html += '</tbody></table>';
            body.innerHTML = html;
            div.appendChild(header);
            div.appendChild(body);
            container.appendChild(div);
        });

        document.getElementById('kwrResults').style.display = hasAny ? '' : 'none';
    }

})();
</script>

<?php get_footer(); ?>
