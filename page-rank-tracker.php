<?php
/*
Template Name: 順位トラッキング
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', '検索順位チェック');
set_query_var('gcrev_page_subtitle', '指定キーワードの Google 検索順位を確認できます。');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('検索順位チェック', '集客のようす'));

get_header();
?>

<style>
/* page-rank-tracker — Page-specific styles */
.rank-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.rank-summary-card {
    background: #FAF9F6;
    border: 1px solid #E8E4DF;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
}
.rank-summary-card__value {
    font-size: 32px;
    font-weight: 800;
    color: #2F3A4A;
    line-height: 1.1;
}
.rank-summary-card__label {
    font-size: 13px;
    color: #8A8A8A;
    margin-top: 6px;
}
.rank-table {
    width: 100%;
    border-collapse: collapse;
    background: #FAF9F6;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid #E8E4DF;
}
.rank-table th {
    background: #F5F3EF;
    font-size: 12px;
    font-weight: 600;
    color: #8A8A8A;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid #E8E4DF;
    white-space: nowrap;
    cursor: pointer;
    user-select: none;
}
.rank-table th:hover {
    color: #2F3A4A;
}
.rank-table th .sort-indicator {
    margin-left: 4px;
    opacity: 0.4;
}
.rank-table th.sort-asc .sort-indicator,
.rank-table th.sort-desc .sort-indicator {
    opacity: 1;
}
.rank-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #E8E4DF;
    font-size: 14px;
    color: #2B2B2B;
    vertical-align: middle;
}
.rank-table tr:last-child td {
    border-bottom: none;
}
.rank-table tr:hover td {
    background: rgba(47,58,74,0.03);
}
.rank-table tr.kw-disabled td {
    opacity: 0.45;
    background: #f5f5f5;
}
.rank-value {
    font-weight: 700;
    font-size: 18px;
}
.rank-value--out {
    color: #d63638;
    font-size: 13px;
    font-weight: 600;
}
.rank-change {
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
}
.rank-change--up {
    color: #0a7b0a;
}
.rank-change--down {
    color: #d63638;
}
.rank-change--same {
    color: #8A8A8A;
}
.rank-url {
    font-size: 12px;
    color: #8A8A8A;
    max-width: 240px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.rank-keyword-name {
    font-weight: 600;
    color: #2B2B2B;
}
.rank-keyword-memo {
    font-size: 12px;
    color: #8A8A8A;
    margin-top: 2px;
}
.rank-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #8A8A8A;
}
.rank-empty-state__icon {
    font-size: 48px;
    margin-bottom: 16px;
}
.rank-empty-state__text {
    font-size: 16px;
    margin-bottom: 8px;
}
.rank-export-btn {
    display: inline-block;
    padding: 8px 16px;
    background: #2F3A4A;
    color: #FAF9F6;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    margin-bottom: 16px;
}
.rank-export-btn:hover {
    background: #3D4D61;
}
/* 期間セレクター */
.rank-range-selector {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    background: #F5F3EF;
    padding: 4px;
    border-radius: 8px;
    width: fit-content;
}
.rank-range-btn {
    padding: 8px 18px;
    border: none;
    background: transparent;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    color: #8A8A8A;
    cursor: pointer;
    transition: all 0.25s ease;
}
.rank-range-btn:hover {
    background: rgba(47,58,74,0.06);
    color: #2F3A4A;
}
.rank-range-btn.active {
    background: #FAF9F6;
    color: #2B2B2B;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
/* ソート */
.sortable { cursor: pointer; }
.sortable:hover { color: #2F3A4A; }

/* ============================
   キーワード管理セクション
   ============================ */
.kw-management {
    background: #FAF9F6;
    border: 1px solid #E8E4DF;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 32px;
}
.kw-management__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.kw-management__title {
    font-size: 17px;
    font-weight: 700;
    color: #2F3A4A;
}
.kw-management__count {
    font-size: 14px;
    color: #8A8A8A;
}
.kw-management__count strong {
    color: #2F3A4A;
    font-weight: 700;
}
.kw-add-form {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 12px;
    align-items: end;
    margin-bottom: 20px;
    padding: 16px;
    background: #fff;
    border: 1px solid #E8E4DF;
    border-radius: 8px;
}
@media (max-width: 768px) {
    .kw-add-form {
        grid-template-columns: 1fr;
    }
}
.kw-add-form label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: #8A8A8A;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.kw-add-form input[type="text"] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #E8E4DF;
    border-radius: 6px;
    font-size: 14px;
    color: #2B2B2B;
    background: #FAF9F6;
    box-sizing: border-box;
}
.kw-add-form input[type="text"]:focus {
    outline: none;
    border-color: #2F3A4A;
}
.kw-add-form__btn {
    padding: 10px 20px;
    background: #2F3A4A;
    color: #FAF9F6;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    height: fit-content;
}
.kw-add-form__btn:hover {
    background: #3D4D61;
}
.kw-add-form__btn:disabled {
    background: #ccc;
    cursor: not-allowed;
}
.kw-limit-msg {
    background: #FFF3CD;
    border: 1px solid #FFD166;
    border-radius: 8px;
    padding: 12px 16px;
    font-size: 13px;
    color: #856404;
    margin-bottom: 16px;
}
.kw-edit-cancel {
    padding: 10px 16px;
    background: #E8E4DF;
    color: #2F3A4A;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    margin-left: 8px;
}

/* キーワード管理テーブル */
.kw-mgmt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.kw-mgmt-table th {
    background: #F5F3EF;
    font-size: 12px;
    font-weight: 600;
    color: #8A8A8A;
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid #E8E4DF;
    white-space: nowrap;
}
.kw-mgmt-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #E8E4DF;
    vertical-align: middle;
}
.kw-mgmt-table tr:last-child td {
    border-bottom: none;
}
.kw-mgmt-table tr.kw-disabled td {
    opacity: 0.5;
    background: #f9f9f7;
}
.kw-toggle-btn {
    padding: 4px 10px;
    border: 1px solid #E8E4DF;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    background: #fff;
}
.kw-toggle-btn--active {
    color: #0a7b0a;
    border-color: #0a7b0a;
}
.kw-toggle-btn--paused {
    color: #d63638;
    border-color: #d63638;
}
.kw-action-btn {
    padding: 4px 10px;
    border: 1px solid #E8E4DF;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    background: #fff;
    color: #2F3A4A;
    margin-left: 4px;
}
.kw-action-btn:hover {
    background: #F5F3EF;
}
.kw-action-btn--delete {
    color: #d63638;
    border-color: #d63638;
}
.kw-action-btn--delete:hover {
    background: #FFF0F0;
}
.kw-action-btn--fetch {
    color: #2271b1;
    border-color: #2271b1;
    font-weight: 600;
}
.kw-action-btn--fetch:hover {
    background: #f0f6fc;
}
.kw-action-btn--fetch:disabled {
    opacity: 0.6;
    cursor: wait;
}

/* SEO難易度バッジ */
.difficulty-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
}
.difficulty-badge--low {
    background: #D1FAE5;
    color: #065F46;
}
.difficulty-badge--medium {
    background: #FEF3C7;
    color: #92400E;
}
.difficulty-badge--high {
    background: #FEE2E2;
    color: #991B1B;
}

/* 検索ボリューム */
.volume-value {
    font-weight: 600;
    font-size: 13px;
    color: #2F3A4A;
}

/* Live取得ボタン */
.kw-action-btn--live-fetch {
    color: #0a7b0a;
    border-color: #0a7b0a;
    font-weight: 600;
}
.kw-action-btn--live-fetch:hover {
    background: #f0faf0;
}
.kw-action-btn--live-fetch:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    color: #8A8A8A;
    border-color: #E8E4DF;
}
.kw-action-btn--live-fetch:disabled:hover {
    background: #fff;
}

/* 手動取得 quota */
.kw-fetch-quota {
    font-size: 12px;
    color: #8A8A8A;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #E8E4DF;
}
.kw-fetch-quota__count {
    font-weight: 600;
    color: #2F3A4A;
}
.kw-fetch-quota__note {
    margin-top: 4px;
    font-size: 11px;
    color: #aaa;
}

/* トースト通知 */
.fetch-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #2F3A4A;
    color: #FAF9F6;
    padding: 14px 20px;
    border-radius: 8px;
    font-size: 14px;
    z-index: 10000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    opacity: 0;
    transform: translateY(12px);
    transition: opacity 0.3s ease, transform 0.3s ease;
    max-width: 400px;
    line-height: 1.5;
}
.fetch-toast.show {
    opacity: 1;
    transform: translateY(0);
}
.fetch-toast--error {
    background: #d63638;
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">
    <!-- ローディングオーバーレイ -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>データを取得中...</p>
        </div>
    </div>

    <!-- 期間セレクター -->
    <div class="rank-range-selector" id="rankRangeSelector">
        <button class="rank-range-btn" data-range="4w">4週間</button>
        <button class="rank-range-btn active" data-range="8w">8週間</button>
        <button class="rank-range-btn" data-range="12w">12週間</button>
        <button class="rank-range-btn" data-range="26w">半年</button>
        <button class="rank-range-btn" data-range="52w">1年</button>
    </div>

    <!-- このページの見方（初心者向け） -->
    <div class="help-lead">
        Google で検索した時に、あなたのホームページが<strong>何番目に表示されるか</strong>をチェックしています。<br>
        数字が小さいほど上位表示されています。「<strong>圏外</strong>」は100位以内に表示されなかったことを意味します。
    </div>

    <!-- サマリーカード -->
    <div class="rank-summary-cards" id="rankSummary">
        <div class="rank-summary-card">
            <div class="rank-summary-card__value" id="summaryTotal">--</div>
            <div class="rank-summary-card__label">追跡キーワード数</div>
        </div>
        <div class="rank-summary-card">
            <div class="rank-summary-card__value" id="summaryDesktop">--</div>
            <div class="rank-summary-card__label">PC 平均順位</div>
        </div>
        <div class="rank-summary-card">
            <div class="rank-summary-card__value" id="summaryMobile">--</div>
            <div class="rank-summary-card__label">スマホ 平均順位</div>
        </div>
        <div class="rank-summary-card">
            <div class="rank-summary-card__value" id="summaryImproved">--</div>
            <div class="rank-summary-card__label">順位改善</div>
        </div>
    </div>

    <!-- ===========================
         キーワード管理セクション
         =========================== -->
    <div class="kw-management" id="kwManagement">
        <div class="kw-management__header">
            <div class="kw-management__title">順位を確認したいキーワード</div>
            <div class="kw-management__count">
                登録数 <strong id="kwCount">-</strong> / <strong id="kwLimit">5</strong>
            </div>
        </div>

        <!-- 上限メッセージ（5件到達時のみ表示） -->
        <div class="kw-limit-msg" id="kwLimitMsg" style="display:none;">
            上限の<span id="kwLimitNum">5</span>件に達しているため、新しいキーワードを追加できません。不要なキーワードを削除してから追加してください。
        </div>

        <!-- 追加/編集フォーム -->
        <div id="kwFormWrap">
            <div class="kw-add-form" id="kwAddForm">
                <div>
                    <label for="kwInput">キーワード</label>
                    <input type="text" id="kwInput" placeholder="例: 愛媛 ホームページ制作" maxlength="255">
                </div>
                <div>
                    <label for="kwMemoInput">メモ（任意）</label>
                    <input type="text" id="kwMemoInput" placeholder="分かりやすいメモを入力" maxlength="500">
                </div>
                <div style="display:flex;align-items:end;">
                    <button class="kw-add-form__btn" id="kwSubmitBtn" onclick="submitKeyword()">追加する</button>
                    <button class="kw-edit-cancel" id="kwCancelBtn" style="display:none;" onclick="cancelEdit()">キャンセル</button>
                </div>
            </div>
        </div>

        <!-- キーワード管理テーブル -->
        <div id="kwMgmtTableWrap" style="display:none;">
            <table class="kw-mgmt-table">
                <thead>
                    <tr>
                        <th>キーワード</th>
                        <th>状態</th>
                        <th>PC順位</th>
                        <th>スマホ順位</th>
                        <th>検索ボリューム</th>
                        <th>SEO難易度</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="kwMgmtBody"></tbody>
            </table>
            <!-- 手動取得 quota 表示 -->
            <div class="kw-fetch-quota" id="kwFetchQuota" style="display:none;">
                <div>最新順位の手動取得: 本日 <span class="kw-fetch-quota__count" id="kwFetchUsed">0</span> / <span class="kw-fetch-quota__count" id="kwFetchLimit">5</span> 回使用済み</div>
                <div class="kw-fetch-quota__note">通常は週1回自動で更新されます。必要な場合のみ手動で最新順位を取得できます。</div>
            </div>
        </div>
    </div>

    <!-- CSV エクスポート -->
    <button class="rank-export-btn" id="exportCsvBtn" onclick="exportRankCsv()" style="display:none;">CSVダウンロード</button>

    <!-- ランキングテーブル -->
    <div id="rankTableWrap">
        <div class="rank-empty-state" id="rankEmptyState" style="display:none;">
            <div class="rank-empty-state__icon">&#x1F50D;</div>
            <div class="rank-empty-state__text">キーワードが登録されていません</div>
            <div style="color:#aaa; font-size:13px;">上の「順位を確認したいキーワード」にキーワードを追加すると、検索順位が表示されます。</div>
        </div>
        <table class="rank-table" id="rankTable" style="display:none;">
            <thead>
                <tr>
                    <th class="sortable" data-sort-key="keyword" onclick="toggleSort('keyword')">キーワード <span class="sort-indicator">&updownarrow;</span></th>
                    <th class="sortable" data-sort-key="desktop" onclick="toggleSort('desktop')">PC順位 <span class="sort-indicator">&updownarrow;</span></th>
                    <th class="sortable" data-sort-key="desktop_change" onclick="toggleSort('desktop_change')">PC変動 <span class="sort-indicator">&updownarrow;</span></th>
                    <th class="sortable" data-sort-key="mobile" onclick="toggleSort('mobile')">スマホ順位 <span class="sort-indicator">&updownarrow;</span></th>
                    <th class="sortable" data-sort-key="mobile_change" onclick="toggleSort('mobile_change')">スマホ変動 <span class="sort-indicator">&updownarrow;</span></th>
                    <th class="sortable" data-sort-key="search_volume" onclick="toggleSort('search_volume')">検索数 <span class="sort-indicator">&updownarrow;</span></th>
                    <th class="sortable" data-sort-key="keyword_difficulty" onclick="toggleSort('keyword_difficulty')">難易度 <span class="sort-indicator">&updownarrow;</span></th>
                    <th class="sortable" data-sort-key="fetched_at" onclick="toggleSort('fetched_at')">最終取得日 <span class="sort-indicator">&updownarrow;</span></th>
                </tr>
            </thead>
            <tbody id="rankTableBody"></tbody>
        </table>
    </div>
</div>

<?php get_footer(); ?>

<script>
(function() {
    'use strict';

    var wpNonce = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
    var currentRange = '8w';
    var rankData = [];
    var sortKey = 'keyword';
    var sortDir = 'asc';

    // キーワード管理状態
    var myKeywords = [];
    var kwLimit = 5;
    var kwCanAdd = true;
    var kwDefaultDomain = '';
    var editingId = 0; // 0 = 追加モード, >0 = 編集モード
    var manualFetchLimit = { daily_used: 0, daily_limit: 5, daily_remaining: 5, is_admin: false };

    // =========================================================
    // 初期ロード
    // =========================================================
    document.addEventListener('DOMContentLoaded', function() {
        fetchMyKeywords();
        fetchRankings(currentRange);

        // 期間ボタン
        document.querySelectorAll('.rank-range-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.rank-range-btn').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                currentRange = btn.dataset.range;
                fetchRankings(currentRange);
            });
        });
    });

    // =========================================================
    // キーワード管理 — データ取得
    // =========================================================
    function fetchMyKeywords() {
        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords', {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success && json.data) {
                myKeywords = json.data.keywords || [];
                kwLimit = json.data.limit || 5;
                kwCanAdd = json.data.can_add;
                kwDefaultDomain = json.data.default_domain || '';
                if (json.data.manual_fetch_limit) {
                    manualFetchLimit = json.data.manual_fetch_limit;
                }
                renderKwManagement();
            }
        })
        .catch(function(err) {
            console.error('[KW Mgmt]', err);
        });
    }

    // =========================================================
    // キーワード管理 — 描画
    // =========================================================
    function renderKwManagement() {
        var countEl = document.getElementById('kwCount');
        var limitEl = document.getElementById('kwLimit');
        var limitMsg = document.getElementById('kwLimitMsg');
        var formWrap = document.getElementById('kwFormWrap');
        var tableWrap = document.getElementById('kwMgmtTableWrap');
        var tbody = document.getElementById('kwMgmtBody');
        var limitNumEl = document.getElementById('kwLimitNum');

        countEl.textContent = myKeywords.length;
        limitEl.textContent = kwLimit;
        limitNumEl.textContent = kwLimit;

        // 上限チェック
        if (!kwCanAdd && editingId === 0) {
            limitMsg.style.display = 'block';
            formWrap.style.display = 'none';
        } else {
            limitMsg.style.display = 'none';
            formWrap.style.display = 'block';
        }

        // テーブル描画
        if (myKeywords.length === 0) {
            tableWrap.style.display = 'none';
            return;
        }

        tableWrap.style.display = 'block';
        var html = '';

        for (var i = 0; i < myKeywords.length; i++) {
            var kw = myKeywords[i];
            var isEnabled = kw.enabled;
            var rowClass = isEnabled ? '' : ' class="kw-disabled"';

            html += '<tr' + rowClass + '>';

            // キーワード + メモ
            html += '<td><div class="rank-keyword-name">' + escHtml(kw.keyword) + '</div>';
            if (kw.memo) {
                html += '<div class="rank-keyword-memo">' + escHtml(kw.memo) + '</div>';
            }
            html += '</td>';

            // 状態トグル
            if (isEnabled) {
                html += '<td><button class="kw-toggle-btn kw-toggle-btn--active" onclick="toggleKeyword(' + kw.id + ', false)">有効</button></td>';
            } else {
                html += '<td><button class="kw-toggle-btn kw-toggle-btn--paused" onclick="toggleKeyword(' + kw.id + ', true)">停止中</button></td>';
            }

            // PC順位
            if (kw.latest_desktop && kw.latest_desktop.is_ranked) {
                html += '<td><span class="rank-value">' + kw.latest_desktop.rank_group + '<span style="font-size:12px;font-weight:400;color:#8A8A8A;">位</span></span></td>';
            } else if (kw.latest_desktop && !kw.latest_desktop.is_ranked) {
                html += '<td><span class="rank-value--out">圏外</span></td>';
            } else {
                html += '<td><span style="color:#aaa;">未取得</span></td>';
            }

            // SP順位
            if (kw.latest_mobile && kw.latest_mobile.is_ranked) {
                html += '<td><span class="rank-value">' + kw.latest_mobile.rank_group + '<span style="font-size:12px;font-weight:400;color:#8A8A8A;">位</span></span></td>';
            } else if (kw.latest_mobile && !kw.latest_mobile.is_ranked) {
                html += '<td><span class="rank-value--out">圏外</span></td>';
            } else {
                html += '<td><span style="color:#aaa;">未取得</span></td>';
            }

            // 検索ボリューム
            if (kw.search_volume != null) {
                html += '<td><span class="volume-value">' + numberFormat(kw.search_volume) + '</span></td>';
            } else {
                html += '<td style="color:#aaa;">&#8212;</td>';
            }

            // SEO難易度
            html += '<td>' + formatDifficulty(kw.keyword_difficulty) + '</td>';

            // 操作ボタン
            html += '<td style="white-space:nowrap;">';
            // 手動取得ボタン（有効なキーワードのみ表示）
            if (isEnabled) {
                var btnLabel = (!kw.latest_desktop && !kw.latest_mobile) ? '取得' : '更新';
                var canFetch = kw.can_manual_fetch;
                var disabledAttr = canFetch ? '' : ' disabled';
                var tooltip = '';
                if (!canFetch) {
                    if (kw.manual_fetched_today) {
                        tooltip = ' title="本日すでに取得済み"';
                    } else {
                        tooltip = ' title="本日の取得上限に達しました"';
                    }
                }
                html += '<button class="kw-action-btn kw-action-btn--live-fetch" id="fetchBtn' + kw.id + '"' + disabledAttr + tooltip + ' onclick="fetchKeywordRankLive(' + kw.id + ')">' + btnLabel + '</button>';
            }
            html += '<button class="kw-action-btn" onclick="startEdit(' + kw.id + ')">編集</button>';
            html += '<button class="kw-action-btn kw-action-btn--delete" onclick="deleteKeyword(' + kw.id + ', \'' + escHtml(kw.keyword).replace(/'/g, "\\'") + '\')">削除</button>';
            html += '</td>';

            html += '</tr>';
        }

        tbody.innerHTML = html;

        // quota 表示の更新
        var quotaEl = document.getElementById('kwFetchQuota');
        if (quotaEl && myKeywords.length > 0) {
            quotaEl.style.display = manualFetchLimit.is_admin ? 'none' : 'block';
            document.getElementById('kwFetchUsed').textContent = manualFetchLimit.daily_used;
            document.getElementById('kwFetchLimit').textContent = manualFetchLimit.daily_limit;
        }
    }

    // =========================================================
    // キーワード管理 — 追加/編集
    // =========================================================
    window.submitKeyword = function() {
        var kwInput = document.getElementById('kwInput');
        var memoInput = document.getElementById('kwMemoInput');
        var keyword = kwInput.value.trim();
        var memo = memoInput.value.trim();

        if (!keyword) {
            alert('キーワードを入力してください。');
            return;
        }

        var submitBtn = document.getElementById('kwSubmitBtn');
        submitBtn.disabled = true;

        var body = {
            keyword: keyword,
            memo: memo,
            target_domain: kwDefaultDomain
        };

        if (editingId > 0) {
            body.id = editingId;
        }

        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords', {
            method: 'POST',
            headers: {
                'X-WP-Nonce': wpNonce,
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            submitBtn.disabled = false;
            if (json.success) {
                kwInput.value = '';
                memoInput.value = '';
                editingId = 0;
                document.getElementById('kwSubmitBtn').textContent = '追加する';
                document.getElementById('kwCancelBtn').style.display = 'none';
                fetchMyKeywords();
                fetchRankings(currentRange); // ランキングも更新
            } else {
                alert(json.message || 'エラーが発生しました。');
            }
        })
        .catch(function(err) {
            submitBtn.disabled = false;
            console.error('[KW Submit]', err);
            alert('通信エラーが発生しました。');
        });
    };

    window.startEdit = function(id) {
        var kw = myKeywords.find(function(k) { return k.id === id; });
        if (!kw) return;

        editingId = id;
        document.getElementById('kwInput').value = kw.keyword;
        document.getElementById('kwMemoInput').value = kw.memo || '';
        document.getElementById('kwSubmitBtn').textContent = '更新する';
        document.getElementById('kwCancelBtn').style.display = 'inline-block';

        // 上限で非表示になっているフォームを表示
        document.getElementById('kwFormWrap').style.display = 'block';
        document.getElementById('kwLimitMsg').style.display = 'none';

        // フォームにスクロール
        document.getElementById('kwAddForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    window.cancelEdit = function() {
        editingId = 0;
        document.getElementById('kwInput').value = '';
        document.getElementById('kwMemoInput').value = '';
        document.getElementById('kwSubmitBtn').textContent = '追加する';
        document.getElementById('kwCancelBtn').style.display = 'none';
        renderKwManagement(); // 上限メッセージ等を再表示
    };

    // =========================================================
    // キーワード管理 — 状態切り替え
    // =========================================================
    window.toggleKeyword = function(id, enable) {
        var kw = myKeywords.find(function(k) { return k.id === id; });
        if (!kw) return;

        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords', {
            method: 'POST',
            headers: {
                'X-WP-Nonce': wpNonce,
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                id: id,
                keyword: kw.keyword,
                enabled: enable ? 1 : 0
            })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success) {
                fetchMyKeywords();
                fetchRankings(currentRange);
            } else {
                alert(json.message || 'エラーが発生しました。');
            }
        })
        .catch(function(err) {
            console.error('[KW Toggle]', err);
            alert('通信エラーが発生しました。');
        });
    };

    // =========================================================
    // キーワード管理 — 削除
    // =========================================================
    window.deleteKeyword = function(id, keyword) {
        if (!confirm('「' + keyword + '」を削除しますか？\nこのキーワードの順位履歴も削除されます。')) {
            return;
        }

        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords/' + id, {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success) {
                fetchMyKeywords();
                fetchRankings(currentRange);
            } else {
                alert(json.message || '削除に失敗しました。');
            }
        })
        .catch(function(err) {
            console.error('[KW Delete]', err);
            alert('通信エラーが発生しました。');
        });
    };

    // =========================================================
    // キーワード管理 — 手動 Live 順位取得
    // =========================================================
    window.fetchKeywordRankLive = function(id) {
        var btn = document.getElementById('fetchBtn' + id);
        var origLabel = btn ? btn.textContent : '取得';
        if (btn) {
            btn.disabled = true;
            btn.textContent = '取得中...';
        }

        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords/' + id + '/fetch', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success && json.data) {
                var d = json.data;
                // トースト通知: 結果表示
                var msg = '「' + escHtml(d.keyword) + '」の順位を取得しました。';
                var r = d.results || {};
                var parts = [];
                if (r.desktop) {
                    parts.push('PC: ' + (r.desktop.is_ranked ? r.desktop.rank_group + '位' : '圏外'));
                }
                if (r.mobile) {
                    parts.push('スマホ: ' + (r.mobile.is_ranked ? r.mobile.rank_group + '位' : '圏外'));
                }
                if (parts.length > 0) {
                    msg += '\n' + parts.join(' / ');
                }
                showToast(msg, 'success');

                // quota 更新
                if (d.rate_limit) {
                    manualFetchLimit = d.rate_limit;
                }

                fetchMyKeywords();
                fetchRankings(currentRange);
            } else {
                showToast(json.message || '順位の取得に失敗しました。', 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = origLabel;
                }
            }
        })
        .catch(function(err) {
            console.error('[KW LiveFetch]', err);
            showToast('通信エラーが発生しました。', 'error');
            if (btn) {
                btn.disabled = false;
                btn.textContent = origLabel;
            }
        });
    };

    // =========================================================
    // トースト通知
    // =========================================================
    function showToast(message, type) {
        // 既存トーストがあれば削除
        var existing = document.querySelector('.fetch-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.className = 'fetch-toast' + (type === 'error' ? ' fetch-toast--error' : '');
        toast.textContent = message;
        document.body.appendChild(toast);

        // フェードイン
        requestAnimationFrame(function() {
            toast.classList.add('show');
        });

        // 4秒後にフェードアウト
        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
        }, 4000);
    }

    // =========================================================
    // データ取得（ランキング）
    // =========================================================
    function fetchRankings(range) {
        showLoading(true);

        fetch('/wp-json/gcrev/v1/rank-tracker/rankings?range=' + range, {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            showLoading(false);
            if (json.success && json.data) {
                rankData = json.data.keywords || [];
                renderSummary(json.data.summary || {});
                renderTable();
            } else {
                rankData = [];
                renderSummary({});
                renderTable();
            }
        })
        .catch(function(err) {
            showLoading(false);
            console.error('[RankTracker]', err);
            rankData = [];
            renderSummary({});
            renderTable();
        });
    }

    // =========================================================
    // サマリー描画
    // =========================================================
    function renderSummary(s) {
        document.getElementById('summaryTotal').textContent = s.total != null ? s.total : '--';
        document.getElementById('summaryDesktop').textContent = s.avg_desktop != null ? s.avg_desktop + '位' : '--';
        document.getElementById('summaryMobile').textContent = s.avg_mobile != null ? s.avg_mobile + '位' : '--';
        document.getElementById('summaryImproved').textContent = s.improved != null ? s.improved + '件' : '--';
    }

    // =========================================================
    // テーブル描画
    // =========================================================
    function renderTable() {
        var emptyState = document.getElementById('rankEmptyState');
        var table      = document.getElementById('rankTable');
        var tbody      = document.getElementById('rankTableBody');
        var exportBtn  = document.getElementById('exportCsvBtn');

        if (!rankData || rankData.length === 0) {
            emptyState.style.display = 'block';
            table.style.display = 'none';
            exportBtn.style.display = 'none';
            return;
        }

        emptyState.style.display = 'none';
        table.style.display = 'table';
        exportBtn.style.display = 'inline-block';

        // ソート
        var sorted = sortRankData(rankData, sortKey, sortDir);

        var html = '';
        for (var i = 0; i < sorted.length; i++) {
            var kw = sorted[i];
            html += '<tr>';

            // キーワード
            html += '<td><div class="rank-keyword-name">' + escHtml(kw.keyword) + '</div>';
            if (kw.memo) {
                html += '<div class="rank-keyword-memo">' + escHtml(kw.memo) + '</div>';
            }
            html += '</td>';

            // Desktop
            html += '<td>' + formatRank(kw.desktop) + '</td>';
            html += '<td>' + formatChange(kw.desktop) + '</td>';

            // Mobile
            html += '<td>' + formatRank(kw.mobile) + '</td>';
            html += '<td>' + formatChange(kw.mobile) + '</td>';

            // 検索ボリューム
            if (kw.search_volume != null) {
                html += '<td><span class="volume-value">' + numberFormat(kw.search_volume) + '</span></td>';
            } else {
                html += '<td style="color:#aaa;">&#8212;</td>';
            }

            // SEO難易度
            html += '<td>' + formatDifficulty(kw.keyword_difficulty) + '</td>';

            // 最終取得日
            html += '<td>' + (kw.fetched_at || '<span style="color:#aaa;">未取得</span>') + '</td>';

            html += '</tr>';
        }

        tbody.innerHTML = html;
        updateSortHeaders();
    }

    // =========================================================
    // フォーマッター
    // =========================================================
    function formatRank(device) {
        if (!device) {
            return '<span style="color:#aaa;">未取得</span>';
        }
        if (!device.is_ranked) {
            return '<span class="rank-value--out">圏外</span>';
        }
        return '<span class="rank-value">' + device.rank_group + '<span style="font-size:12px;font-weight:400;color:#8A8A8A;">位</span></span>';
    }

    function formatChange(device) {
        if (!device || device.change == null) {
            return '<span class="rank-change rank-change--same">-</span>';
        }
        var c = device.change;
        if (c === 999) {
            return '<span class="rank-change rank-change--up">&uarr; NEW</span>';
        }
        if (c === -999) {
            return '<span class="rank-change rank-change--down">&darr; 圏外</span>';
        }
        if (c > 0) {
            return '<span class="rank-change rank-change--up">&uarr; ' + c + '</span>';
        }
        if (c < 0) {
            return '<span class="rank-change rank-change--down">&darr; ' + Math.abs(c) + '</span>';
        }
        return '<span class="rank-change rank-change--same">&rarr; 0</span>';
    }

    function formatDifficulty(val) {
        if (val == null) {
            return '<span style="color:#aaa;">&#8212;</span>';
        }
        var v = parseInt(val, 10);
        if (v <= 33) {
            return '<span class="difficulty-badge difficulty-badge--low">低 ' + v + '</span>';
        }
        if (v <= 66) {
            return '<span class="difficulty-badge difficulty-badge--medium">中 ' + v + '</span>';
        }
        return '<span class="difficulty-badge difficulty-badge--high">高 ' + v + '</span>';
    }

    function numberFormat(n) {
        if (n == null) return '&#8212;';
        return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // =========================================================
    // ソート
    // =========================================================
    window.toggleSort = function(key) {
        if (sortKey === key) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortKey = key;
            sortDir = (key === 'keyword' || key === 'fetched_at') ? 'asc' : 'asc';
        }
        renderTable();
    };

    function sortRankData(data, key, dir) {
        var sorted = data.slice();
        sorted.sort(function(a, b) {
            var va = getSortValue(a, key);
            var vb = getSortValue(b, key);

            if (va == null && vb == null) return 0;
            if (va == null) return 1;
            if (vb == null) return -1;

            var cmp = 0;
            if (typeof va === 'string') {
                cmp = va.localeCompare(vb, 'ja');
            } else {
                cmp = va - vb;
            }
            return dir === 'asc' ? cmp : -cmp;
        });
        return sorted;
    }

    function getSortValue(kw, key) {
        switch (key) {
            case 'keyword':
                return kw.keyword || '';
            case 'desktop':
                return (kw.desktop && kw.desktop.is_ranked) ? kw.desktop.rank_group : 9999;
            case 'desktop_change':
                return (kw.desktop && kw.desktop.change != null) ? kw.desktop.change : -9999;
            case 'mobile':
                return (kw.mobile && kw.mobile.is_ranked) ? kw.mobile.rank_group : 9999;
            case 'mobile_change':
                return (kw.mobile && kw.mobile.change != null) ? kw.mobile.change : -9999;
            case 'search_volume':
                return kw.search_volume != null ? kw.search_volume : -1;
            case 'keyword_difficulty':
                return kw.keyword_difficulty != null ? kw.keyword_difficulty : -1;
            case 'fetched_at':
                return kw.fetched_at || '';
            default:
                return '';
        }
    }

    function updateSortHeaders() {
        document.querySelectorAll('.rank-table th.sortable').forEach(function(th) {
            th.classList.remove('sort-asc', 'sort-desc');
            var indicator = th.querySelector('.sort-indicator');
            if (indicator) indicator.textContent = '\u21C5';

            if (th.dataset.sortKey === sortKey) {
                th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
                if (indicator) indicator.textContent = sortDir === 'asc' ? '\u2191' : '\u2193';
            }
        });
    }

    // =========================================================
    // CSV エクスポート
    // =========================================================
    window.exportRankCsv = function() {
        if (!rankData || rankData.length === 0) return;

        var bom = '\uFEFF';
        var header = 'キーワード,PC順位,PC変動,スマホ順位,スマホ変動,検索ボリューム,SEO難易度,最終取得日\n';
        var rows = '';

        for (var i = 0; i < rankData.length; i++) {
            var kw = rankData[i];
            var dRank  = kw.desktop ? (kw.desktop.is_ranked ? kw.desktop.rank_group : '圏外') : '未取得';
            var dChange = (kw.desktop && kw.desktop.change != null) ? kw.desktop.change : '';
            var mRank  = kw.mobile ? (kw.mobile.is_ranked ? kw.mobile.rank_group : '圏外') : '未取得';
            var mChange = (kw.mobile && kw.mobile.change != null) ? kw.mobile.change : '';
            var vol = kw.search_volume != null ? kw.search_volume : '';
            var diff = kw.keyword_difficulty != null ? kw.keyword_difficulty : '';

            rows += '"' + escapeCsv(kw.keyword) + '",';
            rows += dRank + ',';
            rows += dChange + ',';
            rows += mRank + ',';
            rows += mChange + ',';
            rows += vol + ',';
            rows += diff + ',';
            rows += (kw.fetched_at || '') + '\n';
        }

        var blob = new Blob([bom + header + rows], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'rank-tracker-' + new Date().toISOString().slice(0,10) + '.csv';
        link.click();
    };

    function escapeCsv(str) {
        return (str || '').replace(/"/g, '""');
    }

    // =========================================================
    // ユーティリティ
    // =========================================================
    function showLoading(show) {
        var overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            if (show) {
                overlay.classList.add('active');
            } else {
                overlay.classList.remove('active');
            }
        }
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
})();
</script>
