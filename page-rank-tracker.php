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

    <!-- CSV エクスポート -->
    <button class="rank-export-btn" id="exportCsvBtn" onclick="exportRankCsv()" style="display:none;">📥 CSVダウンロード</button>

    <!-- ランキングテーブル -->
    <div id="rankTableWrap">
        <div class="rank-empty-state" id="rankEmptyState" style="display:none;">
            <div class="rank-empty-state__icon">🔍</div>
            <div class="rank-empty-state__text">キーワードが登録されていません</div>
            <div style="color:#aaa; font-size:13px;">管理者がキーワードを設定すると、ここに検索順位が表示されます。</div>
        </div>
        <table class="rank-table" id="rankTable" style="display:none;">
            <thead>
                <tr>
                    <th class="sortable" data-sort-key="keyword" onclick="toggleSort('keyword')">キーワード <span class="sort-indicator">⇅</span></th>
                    <th class="sortable" data-sort-key="desktop" onclick="toggleSort('desktop')">PC順位 <span class="sort-indicator">⇅</span></th>
                    <th class="sortable" data-sort-key="desktop_change" onclick="toggleSort('desktop_change')">PC変動 <span class="sort-indicator">⇅</span></th>
                    <th class="sortable" data-sort-key="mobile" onclick="toggleSort('mobile')">スマホ順位 <span class="sort-indicator">⇅</span></th>
                    <th class="sortable" data-sort-key="mobile_change" onclick="toggleSort('mobile_change')">スマホ変動 <span class="sort-indicator">⇅</span></th>
                    <th>ランクインURL</th>
                    <th class="sortable" data-sort-key="fetched_at" onclick="toggleSort('fetched_at')">最終取得日 <span class="sort-indicator">⇅</span></th>
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

    const wpNonce = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
    let currentRange = '8w';
    let rankData = [];
    let sortKey = 'keyword';
    let sortDir = 'asc';

    // =========================================================
    // 初期ロード
    // =========================================================
    document.addEventListener('DOMContentLoaded', function() {
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
    // データ取得
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

            // URL
            var url = (kw.desktop && kw.desktop.url) || (kw.mobile && kw.mobile.url) || '';
            html += '<td><div class="rank-url" title="' + escHtml(url) + '">' + escHtml(url ? shortenUrl(url) : '-') + '</div></td>';

            // 最終取得日
            html += '<td>' + (kw.fetched_at || '<span style="color:#aaa;">未取得</span>') + '</td>';

            html += '</tr>';
        }

        tbody.innerHTML = html;
        updateSortHeaders();
    }

    // =========================================================
    // 順位フォーマット
    // =========================================================
    function formatRank(device) {
        if (!device || !device.is_ranked) {
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
            return '<span class="rank-change rank-change--up">↑ NEW</span>';
        }
        if (c === -999) {
            return '<span class="rank-change rank-change--down">↓ 圏外</span>';
        }
        if (c > 0) {
            return '<span class="rank-change rank-change--up">↑ ' + c + '</span>';
        }
        if (c < 0) {
            return '<span class="rank-change rank-change--down">↓ ' + Math.abs(c) + '</span>';
        }
        return '<span class="rank-change rank-change--same">→ 0</span>';
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
            if (indicator) indicator.textContent = '⇅';

            if (th.dataset.sortKey === sortKey) {
                th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
                if (indicator) indicator.textContent = sortDir === 'asc' ? '↑' : '↓';
            }
        });
    }

    // =========================================================
    // CSV エクスポート
    // =========================================================
    window.exportRankCsv = function() {
        if (!rankData || rankData.length === 0) return;

        var bom = '\uFEFF';
        var header = 'キーワード,PC順位,PC変動,スマホ順位,スマホ変動,ランクインURL,最終取得日\n';
        var rows = '';

        for (var i = 0; i < rankData.length; i++) {
            var kw = rankData[i];
            var dRank  = (kw.desktop && kw.desktop.is_ranked) ? kw.desktop.rank_group : '圏外';
            var dChange = (kw.desktop && kw.desktop.change != null) ? kw.desktop.change : '';
            var mRank  = (kw.mobile && kw.mobile.is_ranked) ? kw.mobile.rank_group : '圏外';
            var mChange = (kw.mobile && kw.mobile.change != null) ? kw.mobile.change : '';
            var url = (kw.desktop && kw.desktop.url) || (kw.mobile && kw.mobile.url) || '';

            rows += '"' + escapeCsv(kw.keyword) + '",';
            rows += dRank + ',';
            rows += dChange + ',';
            rows += mRank + ',';
            rows += mChange + ',';
            rows += '"' + escapeCsv(url) + '",';
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

    function shortenUrl(url) {
        try {
            var u = new URL(url);
            return u.pathname + (u.search || '');
        } catch(e) {
            return url;
        }
    }
})();
</script>
