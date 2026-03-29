<?php
/**
 * Template Name: AI検索対策
 * Description: Google AI Overview での自社露出状況を Bright Data SERP データで可視化するページ
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'AI Overview 分析' );
set_query_var( 'gcrev_page_subtitle', 'Google AI Overview（AI概要）で自社がどれだけ引用されているかを分析します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'AI Overview 分析', '各種診断' ) );

get_header();
?>
<style>
/* =========================================================
   AI Overview 分析 — スタイル
   ========================================================= */

/* サマリーカード */
.aio-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}
.aio-summary-card {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 24px;
    text-align: center;
    transition: box-shadow 0.2s, transform 0.2s;
}
.aio-summary-card:hover {
    box-shadow: var(--mw-shadow-float);
    transform: translateY(-1px);
}
.aio-summary-card__label {
    font-size: 13px;
    color: var(--mw-text-tertiary);
    margin-bottom: 8px;
}
.aio-summary-card__value {
    font-size: 36px;
    font-weight: 700;
    color: var(--mw-text-heading);
    line-height: 1.2;
}
.aio-summary-card__value--accent { color: var(--mw-primary-blue); }
.aio-summary-card__sub {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-top: 4px;
}

/* セクション */
.aio-section {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 28px;
    margin-bottom: 28px;
}
.aio-section__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.aio-section__title {
    font-size: 16px;
    font-weight: 600;
    color: var(--mw-text-heading);
    margin: 0;
}
.aio-section__note {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-top: 4px;
}

/* ランキングテーブル */
.aio-ranking-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.aio-ranking-table thead {
    background: var(--mw-bg-secondary);
    border-bottom: 2px solid var(--mw-border-light);
}
.aio-ranking-table th {
    padding: 10px 14px;
    font-weight: 600;
    color: var(--mw-text-secondary);
    font-size: 13px;
    text-align: center;
}
.aio-ranking-table th:nth-child(2),
.aio-ranking-table th:nth-child(3) { text-align: left; }
.aio-ranking-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--mw-border-light);
    text-align: center;
    vertical-align: middle;
}
.aio-ranking-table td:nth-child(2),
.aio-ranking-table td:nth-child(3) { text-align: left; }
.aio-ranking-table tbody tr:hover { background: var(--mw-bg-secondary); }
.aio-ranking-table .aio-self-row {
    background: rgba(86,129,132,0.06);
    font-weight: 600;
}
.aio-ranking-table .aio-self-row td { border-left: 3px solid var(--mw-primary-blue); }

/* 自社バッジ */
.aio-self-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    background: var(--mw-primary-blue);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    margin-left: 6px;
    vertical-align: middle;
}

/* ステータスバッジ */
.aio-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
}
.aio-status--success { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.aio-status--no_aio  { background: rgba(37,99,235,0.08); color: #2563eb; }
.aio-status--failed  { background: rgba(201,90,79,0.12); color: #C95A4F; }

/* キーワード詳細テーブル */
.aio-kw-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.aio-kw-table thead {
    background: var(--mw-bg-secondary);
    border-bottom: 2px solid var(--mw-border-light);
}
.aio-kw-table th {
    padding: 10px 14px;
    font-weight: 600;
    color: var(--mw-text-secondary);
    font-size: 13px;
    text-align: center;
}
.aio-kw-table th:first-child { text-align: left; }
.aio-kw-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--mw-border-light);
    text-align: center;
    vertical-align: top;
}
.aio-kw-table td:first-child { text-align: left; font-weight: 500; }
.aio-kw-table tbody tr:hover { background: var(--mw-bg-secondary); }

/* ドメインリスト */
.aio-domain-list {
    list-style: none;
    padding: 0;
    margin: 0;
    text-align: left;
}
.aio-domain-list li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 3px 0;
    font-size: 12px;
    color: var(--mw-text-secondary);
}
.aio-domain-list .aio-domain-self {
    color: var(--mw-primary-blue);
    font-weight: 600;
}

/* バーチャート */
.aio-bar-wrap {
    height: 8px;
    background: var(--mw-bg-secondary);
    border-radius: 4px;
    overflow: hidden;
    margin-top: 2px;
}
.aio-bar {
    height: 100%;
    border-radius: 4px;
    background: var(--mw-primary-teal);
    transition: width 0.4s ease;
}
.aio-bar--self { background: var(--mw-primary-blue); }

/* AIコメント */
.aio-comment-box {
    background: var(--mw-bg-secondary);
    border-radius: 12px;
    padding: 20px;
    font-size: 14px;
    line-height: 1.8;
    color: var(--mw-text-primary);
    white-space: pre-wrap;
}

/* 取得ボタン */
.aio-fetch-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--mw-primary-blue);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.15s;
}
.aio-fetch-btn:hover { opacity: 0.9; }
.aio-fetch-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* 設定パネル */
.aio-settings-panel {
    background: var(--mw-bg-secondary);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 12px;
}
.aio-settings-panel label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--mw-text-secondary);
    margin-bottom: 4px;
}
.aio-settings-panel textarea,
.aio-settings-panel select {
    font-size: 13px;
    padding: 6px 10px;
    border: 1px solid var(--mw-border-light);
    border-radius: 6px;
    background: var(--mw-bg-primary);
}
.aio-settings-row {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.aio-settings-row > div { flex: 1; min-width: 120px; }

/* トースト */
.aio-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 12px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    color: #fff;
    background: var(--mw-primary-blue);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transform: translateY(100px);
    opacity: 0;
    transition: all 0.3s ease;
    z-index: 9999;
}
.aio-toast.show { transform: translateY(0); opacity: 1; }
.aio-toast--error { background: #C95A4F; }
.aio-toast--success { background: #4E8A6B; }

/* プログレス */
.aio-progress-bar {
    height: 4px;
    background: var(--mw-bg-tertiary);
    border-radius: 2px;
    overflow: hidden;
    margin-top: 8px;
}
.aio-progress-bar__fill {
    height: 100%;
    background: var(--mw-primary-blue);
    border-radius: 2px;
    transition: width 0.3s ease;
}

/* ローディング */
.aio-loading {
    text-align: center;
    padding: 40px 20px;
    color: var(--mw-text-tertiary);
    font-size: 14px;
}
.aio-loading__spinner {
    display: inline-block;
    width: 24px;
    height: 24px;
    border: 3px solid var(--mw-border-light);
    border-top-color: var(--mw-primary-blue);
    border-radius: 50%;
    animation: aio-spin 0.8s linear infinite;
    margin-bottom: 8px;
}
@keyframes aio-spin { to { transform: rotate(360deg); } }

/* 空状態 */
.aio-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--mw-text-tertiary);
    font-size: 14px;
}

/* ���ータ注記 */
.aio-data-note {
    margin-top: 24px;
    padding: 12px 16px;
    background: var(--mw-bg-secondary);
    border-radius: 8px;
    font-size: 12px;
    color: var(--mw-text-tertiary);
    line-height: 1.6;
}

/* レスポンシブ */
@media (max-width: 768px) {
    .aio-summary-cards { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .aio-summary-card { padding: 16px; }
    .aio-summary-card__value { font-size: 28px; }
    .aio-section { padding: 20px 16px; }
    .aio-ranking-table, .aio-kw-table { font-size: 12px; }
    .aio-ranking-table th, .aio-ranking-table td,
    .aio-kw-table th, .aio-kw-table td { padding: 8px 6px; }
}
</style>

<div class="content-area">

    <!-- ===== Section 1: サマリー ===== -->
    <div class="aio-summary-cards" id="aioSummary">
        <div class="aio-summary-card">
            <div class="aio-summary-card__label">AIO露出スコア</div>
            <div class="aio-summary-card__value aio-summary-card__value--accent" id="aioScore">--</div>
            <div class="aio-summary-card__sub">100点満点</div>
        </div>
        <div class="aio-summary-card">
            <div class="aio-summary-card__label">カバレッジ</div>
            <div class="aio-summary-card__value" id="aioCoverage">--</div>
            <div class="aio-summary-card__sub">自社が出現したKW比率</div>
        </div>
        <div class="aio-summary-card">
            <div class="aio-summary-card__label">AIO表示KW数</div>
            <div class="aio-summary-card__value" id="aioKwCount">--</div>
            <div class="aio-summary-card__sub" id="aioKwCountSub"></div>
        </div>
        <div class="aio-summary-card">
            <div class="aio-summary-card__label">全体順位</div>
            <div class="aio-summary-card__value" id="aioSelfRank">--</div>
            <div class="aio-summary-card__sub">露出ドメインランキング中</div>
        </div>
        <div class="aio-summary-card">
            <div class="aio-summary-card__label">最終取得日</div>
            <div class="aio-summary-card__value" id="aioLastFetched" style="font-size:18px;">--</div>
            <div class="aio-summary-card__sub">週1回の定期取得</div>
        </div>
    </div>

    <!-- ===== 設定 & 手動取得 ===== -->
    <?php if ( current_user_can( 'manage_options' ) ) : ?>
    <?php
        $current_user_id   = get_current_user_id();
        $self_domains_raw  = get_user_meta( $current_user_id, 'gcrev_aio_self_domains', true ) ?: '';
        $serp_region       = get_user_meta( $current_user_id, 'gcrev_aio_serp_region', true ) ?: 'jp';
        $serp_language     = get_user_meta( $current_user_id, 'gcrev_aio_serp_language', true ) ?: 'ja';
        $serp_device       = get_user_meta( $current_user_id, 'gcrev_aio_serp_device', true ) ?: 'desktop';

        // クライアント設定の site_url から自動判定ドメインを取得
        $auto_domain = '';
        $site_url = get_user_meta( $current_user_id, 'gcrev_client_site_url', true );
        if ( ! empty( $site_url ) ) {
            $parsed_host = wp_parse_url( $site_url, PHP_URL_HOST );
            if ( $parsed_host ) {
                $auto_domain = preg_replace( '/^(www\.|m\.)/', '', strtolower( $parsed_host ) );
            }
        }
    ?>
    <div class="aio-section" id="aioSettingsSection">
        <div class="aio-section__header">
            <div>
                <h2 class="aio-section__title">SERP 取得設定</h2>
                <div class="aio-section__note">自社判定ドメイン・取得条件の設定と、手動データ取得ができます（管理者のみ表示）</div>
            </div>
        </div>

        <div class="aio-settings-panel">
            <div style="margin-bottom:16px;">
                <?php if ( $auto_domain ) : ?>
                <div style="margin-bottom:8px;font-size:13px;">
                    <span style="color:var(--mw-text-secondary);font-weight:600;">自動判定ドメイン:</span>
                    <code style="background:var(--mw-bg-primary);padding:2px 8px;border-radius:4px;font-size:13px;"><?php echo esc_html( $auto_domain ); ?></code>
                    <span style="color:var(--mw-text-tertiary);font-size:12px;margin-left:4px;">（クライアント設定のサイトURLから自動設定）</span>
                </div>
                <?php endif; ?>
                <label for="aioSelfDomains">追加の自社ドメイン（任意・1行1つ）</label>
                <textarea id="aioSelfDomains" rows="2" style="width:100%;max-width:400px;" placeholder="別ドメインがあれば追加&#10;例: mimamori-web.jp"><?php echo esc_textarea( $self_domains_raw ); ?></textarea>
                <div style="font-size:11px;color:var(--mw-text-tertiary);margin-top:4px;">上記の自動判定ドメインに加え、追加で自社として判定したいドメインがあれば入力してください。</div>
            </div>

            <div class="aio-settings-row" style="margin-bottom:16px;">
                <div>
                    <label for="aioRegion">地域</label>
                    <select id="aioRegion">
                        <option value="jp" <?php selected( $serp_region, 'jp' ); ?>>日本 (jp)</option>
                        <option value="us" <?php selected( $serp_region, 'us' ); ?>>アメリカ (us)</option>
                    </select>
                </div>
                <div>
                    <label for="aioLanguage">言語</label>
                    <select id="aioLanguage">
                        <option value="ja" <?php selected( $serp_language, 'ja' ); ?>>日本語 (ja)</option>
                        <option value="en" <?php selected( $serp_language, 'en' ); ?>>英語 (en)</option>
                    </select>
                </div>
                <div>
                    <label for="aioDevice">デバイス</label>
                    <select id="aioDevice">
                        <option value="desktop" <?php selected( $serp_device, 'desktop' ); ?>>デスクトップ</option>
                        <option value="mobile" <?php selected( $serp_device, 'mobile' ); ?>>モバイル</option>
                    </select>
                </div>
                <div>
                    <button class="aio-fetch-btn" id="aioSaveSettingsBtn" onclick="saveSettings()">設定を保存</button>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <button class="aio-fetch-btn" id="aioFetchBtn" onclick="runFetch()">SERP データを取得</button>
            <span id="aioFetchStatus" style="font-size:13px;color:var(--mw-text-tertiary);"></span>
        </div>
        <div class="aio-progress-bar" id="aioProgressBar" style="display:none;">
            <div class="aio-progress-bar__fill" id="aioProgressFill" style="width:0%;"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== Section 2: 上位露出ドメインランキング ===== -->
    <div class="aio-section" id="aioRankingSection">
        <div class="aio-section__header">
            <div>
                <h2 class="aio-section__title">上位露出ドメインランキング</h2>
                <div class="aio-section__note">AIO 内で露出の多いドメインを重み付き露出度で順位付け</div>
            </div>
        </div>
        <div id="aioRankingContent">
            <div class="aio-loading"><div class="aio-loading__spinner"></div><div>データを読み込み中...</div></div>
        </div>
    </div>

    <!-- ===== Section 3: キーワード別詳細 ===== -->
    <div class="aio-section" id="aioKeywordSection">
        <div class="aio-section__header">
            <div>
                <h2 class="aio-section__title">キーワード別詳細</h2>
                <div class="aio-section__note">各キーワードでの AIO 有無・引用ドメイン・自社の順位を確認できます</div>
            </div>
        </div>
        <div id="aioKeywordContent">
            <div class="aio-loading"><div class="aio-loading__spinner"></div><div>データを読み込み中...</div></div>
        </div>
    </div>

    <!-- ===== Section 4: AIコメント ===== -->
    <div class="aio-section" id="aioCommentSection">
        <div class="aio-section__header">
            <div>
                <h2 class="aio-section__title">AI分析コメント</h2>
                <div class="aio-section__note">集計データをもとに AI が分析した改善コメントです</div>
            </div>
        </div>
        <div id="aioCommentContent">
            <div class="aio-loading"><div class="aio-loading__spinner"></div><div>コメント���生成中...</div></div>
        </div>
    </div>

    <!-- データ注記 -->
    <div class="aio-data-note">
        このページのデータは、Google 検索結果の AI Overview（AI 概要）セクションを Bright Data SERP API で週1回取得・分析したものです。
        AI Overview は検索条件（地域・デバイス・時期）により変動するため、参考値としてご覧ください。
        重み付き露出度: 1位=5点、2位=4点、3位=3点、4位=2点、5位以降=1点。
    </div>

</div><!-- .content-area -->

<script>
(function() {
    'use strict';

    const API_BASE = '<?php echo esc_js( rest_url( 'gcrev/v1/aio-serp' ) ); ?>';

    function fetchAPI(endpoint) {
        return fetch(API_BASE + endpoint, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
        }).then(r => r.json());
    }

    // ===== サマリー =====
    fetchAPI('/summary').then(res => {
        if (!res.success || !res.data) {
            document.getElementById('aioScore').textContent = '--';
            return;
        }
        const d = res.data;
        document.getElementById('aioScore').textContent = d.self_score !== null ? d.self_score : '--';
        document.getElementById('aioCoverage').textContent = d.self_coverage !== null ? d.self_coverage + '%' : '--';
        document.getElementById('aioKwCount').textContent = d.aio_keyword_count ?? '--';
        document.getElementById('aioKwCountSub').textContent =
            (d.total_keyword_count ?? 0) + ' KW 中 ' + (d.aio_keyword_count ?? 0) + ' KW で AIO 表示';
        document.getElementById('aioSelfRank').textContent = d.self_rank ? d.self_rank + '位' : '--';
        document.getElementById('aioLastFetched').textContent = d.last_fetched
            ? d.last_fetched.substring(0, 10)
            : '未取得';
    }).catch(() => {
        document.getElementById('aioScore').textContent = '--';
    });

    // ===== ランキング =====
    fetchAPI('/rankings').then(res => {
        const el = document.getElementById('aioRankingContent');
        if (!res.success || !res.data || res.data.length === 0) {
            el.innerHTML = '<div class="aio-empty">まだデータがありません。上部の「SERP データを取得」ボタンからデータを取得してください。</div>';
            return;
        }

        const maxExp = Math.max(...res.data.map(d => d.total_exposure));
        let html = '<table class="aio-ranking-table"><thead><tr>'
            + '<th>順位</th><th>サイト名</th><th>ドメイン</th><th>総露出点</th><th>出現KW数</th><th>露出度</th>'
            + '</tr></thead><tbody>';

        res.data.forEach(d => {
            const pct = maxExp > 0 ? (d.total_exposure / maxExp * 100).toFixed(0) : 0;
            const selfClass = d.is_self ? ' aio-self-row' : '';
            const selfBadge = d.is_self ? '<span class="aio-self-badge">自社</span>' : '';
            const barClass = d.is_self ? 'aio-bar aio-bar--self' : 'aio-bar';

            html += '<tr class="' + selfClass + '">'
                + '<td>' + d.rank + '</td>'
                + '<td>' + escHtml(d.site_name) + selfBadge + '</td>'
                + '<td style="font-size:12px;color:var(--mw-text-tertiary);">' + escHtml(d.normalized_domain) + '</td>'
                + '<td><strong>' + d.total_exposure + '</strong></td>'
                + '<td>' + d.keyword_count + '</td>'
                + '<td style="min-width:80px;"><div class="aio-bar-wrap"><div class="' + barClass + '" style="width:' + pct + '%;"></div></div></td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        el.innerHTML = html;
    }).catch(() => {
        document.getElementById('aioRankingContent').innerHTML = '<div class="aio-empty">データの取得に失敗しました。</div>';
    });

    // ===== キーワード別詳細 =====
    fetchAPI('/keywords').then(res => {
        const el = document.getElementById('aioKeywordContent');
        if (!res.success || !res.data || res.data.length === 0) {
            el.innerHTML = '<div class="aio-empty">まだデータがありません。</div>';
            return;
        }

        let html = '<table class="aio-kw-table"><thead><tr>'
            + '<th>キーワード</th><th>AIO</th><th>自社</th><th>自社順位</th><th>引用ドメイン</th>'
            + '</tr></thead><tbody>';

        res.data.forEach(kw => {
            let statusBadge = '';
            if (kw.status === 'success') {
                statusBadge = '<span class="aio-status aio-status--success">AIOあり</span>';
            } else if (kw.status === 'no_aio') {
                statusBadge = '<span class="aio-status aio-status--no_aio">AIOなし</span>';
            } else if (kw.status === 'failed') {
                statusBadge = '<span class="aio-status aio-status--failed">取得失敗</span>';
            } else {
                statusBadge = '<span class="aio-status" style="background:var(--mw-bg-secondary);color:var(--mw-text-tertiary);">未取得</span>';
            }

            const selfIcon = kw.self_found ? '<span style="color:#4E8A6B;font-weight:600;">あり</span>' : '<span style="color:#999;">なし</span>';
            const selfRank = kw.self_rank ? kw.self_rank + '位' : '--';

            // ドメインリスト
            let domainHtml = '--';
            if (kw.domains && kw.domains.length > 0) {
                domainHtml = '<ul class="aio-domain-list">';
                kw.domains.forEach(d => {
                    const cls = d.is_self ? ' class="aio-domain-self"' : '';
                    domainHtml += '<li' + cls + '>'
                        + '<span>' + escHtml(d.site_name || d.normalized_domain) + '</span>'
                        + '<span style="font-weight:600;">' + d.exposure + '点</span>'
                        + '</li>';
                });
                domainHtml += '</ul>';
            }

            html += '<tr>'
                + '<td><strong>' + escHtml(kw.keyword) + '</strong></td>'
                + '<td>' + statusBadge + '</td>'
                + '<td>' + selfIcon + '</td>'
                + '<td>' + selfRank + '</td>'
                + '<td>' + domainHtml + '</td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        el.innerHTML = html;
    }).catch(() => {
        document.getElementById('aioKeywordContent').innerHTML = '<div class="aio-empty">データの取得に失敗しました。</div>';
    });

    // ===== AIコメント =====
    fetchAPI('/ai-comment').then(res => {
        const el = document.getElementById('aioCommentContent');
        if (!res.success || !res.data || !res.data.comment) {
            el.innerHTML = '<div class="aio-empty">AIコメントを生成できませんでした。</div>';
            return;
        }
        el.innerHTML = '<div class="aio-comment-box">' + escHtml(res.data.comment) + '</div>';
    }).catch(() => {
        document.getElementById('aioCommentContent').innerHTML = '<div class="aio-empty">AIコメントの取得に失敗しました。</div>';
    });

    // ===== ユーティリティ =====
    function escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function showToast(msg, type) {
        let toast = document.getElementById('aioToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'aioToast';
            toast.className = 'aio-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.className = 'aio-toast' + (type === 'error' ? ' aio-toast--error' : type === 'success' ? ' aio-toast--success' : '');
        requestAnimationFrame(() => { toast.classList.add('show'); });
        setTimeout(() => { toast.classList.remove('show'); }, 3500);
    }

    function postAPI(endpoint, body) {
        return fetch(API_BASE + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
            },
            body: JSON.stringify(body)
        }).then(r => r.json());
    }

    // ===== 設定保存 =====
    window.saveSettings = function() {
        const btn = document.getElementById('aioSaveSettingsBtn');
        btn.disabled = true;
        btn.textContent = '保存中...';

        postAPI('/settings', {
            self_domains: document.getElementById('aioSelfDomains').value,
            region: document.getElementById('aioRegion').value,
            language: document.getElementById('aioLanguage').value,
            device: document.getElementById('aioDevice').value,
        }).then(res => {
            if (res.success) {
                showToast('設定を保存しました', 'success');
            } else {
                showToast(res.message || '保存に失敗しました', 'error');
            }
        }).catch(() => {
            showToast('通信エラーが発生しました', 'error');
        }).finally(() => {
            btn.disabled = false;
            btn.textContent = '設定を保存';
        });
    };

    // ===== SERP 取得 =====
    window.runFetch = function() {
        if (!confirm('全 AIO 有効キーワードの SERP データを取得します。\n1キーワードあたり約10〜15秒かかります。\n\n実行しますか？')) {
            return;
        }

        const btn = document.getElementById('aioFetchBtn');
        const status = document.getElementById('aioFetchStatus');
        const bar = document.getElementById('aioProgressBar');
        const fill = document.getElementById('aioProgressFill');

        btn.disabled = true;
        btn.textContent = '取得中...';
        status.textContent = 'SERP データを取得しています...';
        bar.style.display = 'block';
        fill.style.width = '10%';

        postAPI('/fetch', { user_id: <?php echo (int) get_current_user_id(); ?> }).then(res => {
            fill.style.width = '100%';
            if (res.success) {
                const d = res.data;
                const count = d.processed ?? (Array.isArray(d.results) ? d.results.length : 0);
                status.textContent = count + ' キーワードの取得が完了しました。';
                showToast('SERP 取得完了 (' + count + ' KW)', 'success');
                // 2秒後にページ再読み込みでデータ反映
                setTimeout(() => { location.reload(); }, 2000);
            } else {
                status.textContent = '取得に失敗しました: ' + (res.message || '');
                showToast(res.message || '取得に失敗しました', 'error');
            }
        }).catch(err => {
            status.textContent = '通信エラーが発生しました。';
            showToast('通信エラーが発生しました', 'error');
        }).finally(() => {
            btn.disabled = false;
            btn.textContent = 'SERP データを取得';
        });
    };
})();
</script>

<?php get_footer(); ?>
