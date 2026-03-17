<?php
/*
Template Name: キーワード分析
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定（HTML準拠：「キーワード」）
set_query_var('gcrev_page_title', 'どんな言葉で探された？');
set_query_var('gcrev_page_subtitle', '検索で使われた言葉が分かります。');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('どんな言葉で探された？', 'ホームページ'));

get_header();
?>

<style>
/* page-analysis-keywords — Page-specific overrides only */
/* All shared styles are in css/dashboard-redesign.css */
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

    <!-- 期間セレクター（deviceと同じ共通モジュール） -->
<?php
set_query_var('gcrev_period_selector', [
  'id' => 'keywords-period',
  'items' => [
    ['value' => 'last30',     'label' => '直近30日'],
    ['value' => 'prev-month',      'label' => '前月'],
    ['value' => 'prev-prev-month', 'label' => '前々月'],
    ['value' => 'last90',          'label' => '過去90日'],
    ['value' => 'last180',    'label' => '過去半年'],
    ['value' => 'last365',    'label' => '過去1年'],
  ],
  'default' => 'last30',
]);

get_template_part('template-parts/period-selector');
?>

    <!-- 期間表示 -->
    <div class="period-display" id="periodDisplay">
        分析対象期間を選択してください
    </div>
<!-- このページの見方（初心者向け） -->
<?php
set_query_var('analysis_help_key', 'keywords');
get_template_part('template-parts/analysis-help');
?>
    <!-- フィルターセクション -->
    <div class="filter-section">
        <div class="filter-row">
            <div class="filter-group">
                <label class="filter-label">キーワード検索</label>
                <input type="text" class="filter-input" id="filterSearch" placeholder="キーワードを入力...">
            </div>
            <div class="filter-group" style="flex: 0;">
                <label class="filter-label">&nbsp;</label>
                <button class="filter-btn" onclick="applyFilter()">🔍 検索</button>
            </div>
            <div class="filter-group" style="flex: 0;">
                <label class="filter-label">&nbsp;</label>
                <button class="filter-btn secondary" onclick="resetFilter()">リセット</button>
            </div>
        </div>
    </div>

    <!-- データテーブルセクション（HTML準拠：キーワードランキング） -->
    <div class="table-section">
        <div class="table-header">
            <h3 class="table-title">キーワードランキング</h3>
            <div class="table-actions">
                <button class="table-btn" onclick="exportTableData()">📥 CSVエクスポート</button>
            </div>
        </div>

        <table class="data-table" id="keywordTable">
            <thead>
                <tr>
                    <th>順位</th>
                    <th>キーワード</th>
                    <th class="sortable sort-desc" data-sort-key="_clicks" onclick="toggleSort('_clicks', this)">クリック数<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                    <th class="sortable" data-sort-key="_impressions" onclick="toggleSort('_impressions', this)">表示回数<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                    <th class="sortable" data-sort-key="_ctr" onclick="toggleSort('_ctr', this)">CTR（クリック率）<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                    <th class="sortable" data-sort-key="_position" onclick="toggleSort('_position', this)">平均掲載順位<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                    <th class="sortable" data-sort-key="positionChange" onclick="toggleSort('positionChange', this)">順位変動<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                </tr>
            </thead>
            <tbody id="keywordTableBody">
                <tr>
                    <td colspan="7" style="text-align: center; padding: 24px; color: #888888;">
                        データを読み込み中...
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- ペジネーション -->
        <div class="pagination" id="pagination"></div>
    </div>
</div>

<script>
// ===== グローバル変数 =====
let currentData = null;           // API生レスポンス
let filteredKeywords = [];        // フィルタ/ソート後
let currentPage = 1;              // ペジネーション現在ページ
const perPage = 20;               // 1ページあたりの表示件数
let currentPeriod = null;         // 期間セレクター連動ガード
let sortKey = '_clicks';          // 現在のソートカラム
let sortDir = 'desc';             // 'asc' | 'desc'

// ===== period-selector モジュール連携（device と同一パターン） =====
(function bindPeriodSelector() {
    const selectorId = 'keywords-period';
    const selectorEl = document.getElementById(selectorId);

    const initialPeriod = 'last30';

    // 期間変更イベント（period-selector.js から発火）
    if (selectorEl) {
        selectorEl.addEventListener('gcrev:periodChange', function(e) {
            const period = e.detail && e.detail.period ? e.detail.period : null;
            if (!period) return;
            if (period === currentPeriod) return;
            loadData(period);
        });
    }

    // 初回読み込み
    loadData(initialPeriod);
})();

/**
 * データ取得とUI更新（device / page と同一経路）
 */
async function loadData(period) {
    currentPeriod = period;
    showLoading();

    try {
        const apiUrl = '<?php echo esc_url(rest_url("gcrev/v1/analysis/keywords")); ?>?period=' + encodeURIComponent(period);
        const response = await fetch(apiUrl, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
            },
            credentials: 'same-origin'
        });

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.message || 'データ取得に失敗しました');
        }

        currentData = result.data;

        // 期間表示更新（device と同一）
        updatePeriodDisplay(currentData);
        updatePeriodRangeFromData(currentData, 'keywords-period');

        // フィルタ適用＆テーブル描画
        applyFilter();

    } catch (error) {
        console.error('データ取得エラー:', error);
        // データ取得失敗時でもUIを崩さない
        const tbody = document.getElementById('keywordTableBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 24px; color: #888888;">データの取得に失敗しました。Search Console連携が必要な場合があります。</td></tr>';
        }
    } finally {
        hideLoading();
    }
}

// ===== 期間表示（device と同一ロジック） =====
function updatePeriodDisplay(data) {
    if (window.GCREV && typeof window.GCREV.updatePeriodDisplay === 'function') {
        window.GCREV.updatePeriodDisplay(data, { periodDisplayId: 'periodDisplay' });
        return;
    }

    const periodDisplay = document.getElementById('periodDisplay');
    if (!periodDisplay || !data || !data.current_period) return;

    const current = data.current_period;
    const comparison = data.comparison_period;

    const fmt = (start, end) => {
        if (!start || !end) return '-';
        return String(start).replace(/-/g, '/') + ' 〜 ' + String(end).replace(/-/g, '/');
    };

    let html =
      '<div class="period-item">' +
        '<span class="period-label-v2">&#x1F4C5; 分析対象期間：</span>' +
        '<span class="period-value">' + fmt(current.start, current.end) + '</span>' +
      '</div>';
    if (comparison) {
      html +=
        '<div class="period-divider"></div>' +
        '<div class="period-item">' +
          '<span class="period-label-v2">&#x1F4CA; 比較期間：</span>' +
          '<span class="period-value">' + fmt(comparison.start, comparison.end) + '</span>' +
        '</div>';
    }
    periodDisplay.innerHTML = html;
}

function updatePeriodRangeFromData(data, selectorId) {
    if (!window.GCREV || typeof window.GCREV.updatePeriodRange !== 'function' || !data) return;

    const currentLabel =
        data.current_range_label ||
        (data.current_period ? formatRangeLabel(data.current_period.start, data.current_period.end) : '');

    const compareLabel =
        data.compare_range_label ||
        data.comparison_range_label ||
        (data.comparison_period ? formatRangeLabel(data.comparison_period.start, data.comparison_period.end) : '');

    window.GCREV.updatePeriodRange(selectorId, currentLabel, compareLabel);
}

function formatRangeLabel(start, end) {
    if (!start || !end) return '';
    return String(start).replace(/-/g, '/') + ' 〜 ' + String(end).replace(/-/g, '/');
}

// ===== フィルタ・ソート =====
function applyFilter() {
    if (!currentData || !currentData.keywords_detail) {
        // データ未取得時の表示
        const tbody = document.getElementById('keywordTableBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 24px; color: #888888;">キーワードデータがありません</td></tr>';
        }
        return;
    }

    const searchVal = (document.getElementById('filterSearch').value || '').toLowerCase().trim();

    // 検索フィルタ
    let keywords = currentData.keywords_detail.slice();
    if (searchVal) {
        keywords = keywords.filter(k =>
            (k.query || '').toLowerCase().includes(searchVal)
        );
    }

    // カラムソート
    keywords = sortKeywords(keywords, sortKey, sortDir);

    filteredKeywords = keywords;
    currentPage = 1;
    renderTable();
    renderPagination();
}

function resetFilter() {
    document.getElementById('filterSearch').value = '';
    sortKey = '_clicks';
    sortDir = 'desc';
    updateSortHeaders();
    applyFilter();
}

/**
 * 配列を指定カラム・方向でソート
 */
function sortKeywords(arr, key, dir) {
    return arr.sort(function(a, b) {
        var valA = a[key];
        var valB = b[key];
        if (valA == null || isNaN(valA)) valA = -Infinity;
        if (valB == null || isNaN(valB)) valB = -Infinity;
        var cmp = (typeof valA === 'string')
            ? valA.localeCompare(valB, 'ja')
            : valA - valB;
        return dir === 'asc' ? cmp : -cmp;
    });
}

/**
 * テーブルヘッダーをクリックしたときのソート切替
 */
function toggleSort(key, thEl) {
    if (sortKey === key) {
        sortDir = sortDir === 'desc' ? 'asc' : 'desc';
    } else {
        sortKey = key;
        sortDir = 'desc';
    }
    updateSortHeaders();
    applyFilter();
}

/**
 * <th> のアクティブ表示を更新
 */
function updateSortHeaders() {
    document.querySelectorAll('#keywordTable thead th.sortable').forEach(function(th) {
        th.classList.remove('sort-asc', 'sort-desc');
        if (th.dataset.sortKey === sortKey) {
            th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
        }
    });
}

// ===== テーブル描画（HTML準拠列構成） =====
function renderTable() {
    const tbody = document.getElementById('keywordTableBody');
    if (!filteredKeywords || filteredKeywords.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 24px; color: #888888;">データがありません</td></tr>';
        return;
    }

    const start = (currentPage - 1) * perPage;
    const end = start + perPage;
    const pageSlice = filteredKeywords.slice(start, end);

    const rows = pageSlice.map((k, idx) => {
        const rank = start + idx + 1;
        const rankClass = rank <= 3 ? 'top3' : '';
        const query = k.query || '(不明)';

        // クリック数・表示回数（生の数値を使用、フォーマット済みの場合はフォールバック）
        const clicks = typeof k._clicks === 'number' ? k._clicks : 0;
        const impressions = typeof k._impressions === 'number' ? k._impressions : 0;
        const ctr = typeof k._ctr === 'number' ? (k._ctr * 100).toFixed(1) + '%' : (k.ctr || '-');
        const position = typeof k._position === 'number' ? k._position.toFixed(1) : (k.position || '-');

        // 順位変動バッジ
        let trendHtml = '<span class="trend-badge neutral">- -</span>';
        if (typeof k.positionChange === 'number' && !isNaN(k.positionChange)) {
            if (k.positionChange < 0) {
                // 順位が下がった（数値が小さくなった）= 改善
                trendHtml = `<span class="trend-badge up">↑ ${Math.abs(k.positionChange).toFixed(1)}</span>`;
            } else if (k.positionChange > 0) {
                // 順位が上がった（数値が大きくなった）= 悪化
                trendHtml = `<span class="trend-badge down">↓ ${Math.abs(k.positionChange).toFixed(1)}</span>`;
            } else {
                trendHtml = `<span class="trend-badge neutral">- 0.0</span>`;
            }
        }

        return `
            <tr>
                <td><span class="rank-badge ${rankClass}">${rank}</span></td>
                <td>${escHtml(query)}</td>
                <td>${formatNumber(clicks)}</td>
                <td>${formatNumber(impressions)}</td>
                <td>${escHtml(ctr)}</td>
                <td>${escHtml(String(position))}</td>
                <td>${trendHtml}</td>
            </tr>
        `;
    }).join('');

    tbody.innerHTML = rows;
}

// ===== ペジネーション =====
function renderPagination() {
    const container = document.getElementById('pagination');
    const totalPages = Math.ceil(filteredKeywords.length / perPage);

    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';
    html += `<button class="pagination-btn" onclick="goToPage(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}>‹ 前へ</button>`;

    // ページ番号（最大7つ表示）
    let startP = Math.max(1, currentPage - 3);
    let endP = Math.min(totalPages, startP + 6);
    if (endP - startP < 6) startP = Math.max(1, endP - 6);

    for (let i = startP; i <= endP; i++) {
        html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})">${i}</button>`;
    }

    html += `<button class="pagination-btn" onclick="goToPage(${currentPage + 1})" ${currentPage >= totalPages ? 'disabled' : ''}>次へ ›</button>`;

    container.innerHTML = html;
}

function goToPage(page) {
    const totalPages = Math.ceil(filteredKeywords.length / perPage);
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    renderTable();
    renderPagination();
    document.getElementById('keywordTable').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ===== ユーティリティ関数（device と同一） =====

function formatNumber(num) {
    if (num === null || num === undefined) return '-';
    return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function formatPercent(num) {
    if (num === null || num === undefined) return '-';
    return Number(num).toFixed(1) + '%';
}

function escHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function escAttr(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function showLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.add('active');
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.remove('active');
}

// ===== CSVエクスポート =====
function exportTableData() {
    if (!filteredKeywords || filteredKeywords.length === 0) {
        alert('エクスポートするデータがありません');
        return;
    }

    const headers = ['順位', 'キーワード', 'クリック数', '表示回数', 'CTR', '平均掲載順位', '順位変動'];
    const rows = filteredKeywords.map((k, idx) => [
        idx + 1,
        '"' + (k.query || '').replace(/"/g, '""') + '"',
        typeof k._clicks === 'number' ? k._clicks : 0,
        typeof k._impressions === 'number' ? k._impressions : 0,
        typeof k._ctr === 'number' ? (k._ctr * 100).toFixed(1) + '%' : (k.ctr || '-'),
        typeof k._position === 'number' ? k._position.toFixed(1) : (k.position || '-'),
        typeof k.positionChange === 'number' ? k.positionChange.toFixed(1) : '-'
    ]);

    let csv = '\uFEFF' + headers.join(',') + '\n';
    rows.forEach(row => {
        csv += row.join(',') + '\n';
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'keyword-analysis-' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php get_footer(); ?>
