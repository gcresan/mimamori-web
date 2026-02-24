<?php
/*
Template Name: ページ分析
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'よく見られているページ');
set_query_var('gcrev_page_subtitle', 'どのページがよく読まれているかが分かります。');

// パンくず設定（HTML準拠：ホーム > ホームページ > ページ）
$breadcrumb = '<a href="' . home_url() . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="#">ホームページ</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<strong>よく見られているページ</strong>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

get_header();
?>

<style>
/* page-analysis-pages — Page-specific overrides only */
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
  'id' => 'page-period',
  'items' => [
    ['value' => 'last30',     'label' => '直近30日'],
    ['value' => 'prev-month',      'label' => '前月'],
    ['value' => 'prev-prev-month', 'label' => '前々月'],
    ['value' => 'last90',          'label' => '過去90日'],
    ['value' => 'last180',    'label' => '過去半年'],
    ['value' => 'last365',    'label' => '過去1年'],
  ],
  'default' => 'prev-month',
]);

get_template_part('template-parts/period-selector');
?>

    <!-- 期間表示 -->
    <div class="period-display" id="periodDisplay">
        分析対象期間を選択してください
    </div>
<!-- このページの見方（初心者向け） -->
<?php
set_query_var('analysis_help_key', 'page');
get_template_part('template-parts/analysis-help');
?>
    <!-- フィルターセクション -->
    <div class="filter-section">
        <div class="filter-row">
            <div class="filter-group">
                <label class="filter-label">ページURL検索</label>
                <input type="text" class="filter-input" id="filterSearch" placeholder="URLまたはタイトルを入力...">
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

    <!-- データテーブルセクション -->
    <div class="table-section">
        <div class="table-header">
            <h3 class="table-title">ページ別パフォーマンス</h3>
            <div class="table-actions">
                <button class="table-btn" onclick="exportTableData()">📥 CSVエクスポート</button>
            </div>
        </div>

        <table class="data-table" id="pageTable">
            <thead>
                <tr>
                    <th>順位</th>
                    <th>ページタイトル / URL</th>
                    <th class="sortable sort-desc" data-sort-key="pageViews" onclick="toggleSort('pageViews', this)">PV<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                    <th class="sortable" data-sort-key="percentage" onclick="toggleSort('percentage', this)">割合<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                    <th class="sortable" data-sort-key="sessions" onclick="toggleSort('sessions', this)">セッション<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                    <th class="sortable" data-sort-key="avgDuration" onclick="toggleSort('avgDuration', this)">平均滞在時間<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                    <th class="sortable" data-sort-key="bounceRate" onclick="toggleSort('bounceRate', this)">直帰率<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                    <th class="sortable" data-sort-key="engagementRate" onclick="toggleSort('engagementRate', this)">エンゲージメント率<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                    <th class="sortable" data-sort-key="pvChange" onclick="toggleSort('pvChange', this)">変動<span class="sort-icon"><span class="arrow-up">▲</span><span class="arrow-down">▼</span></span></th>
                </tr>
            </thead>
            <tbody id="pageTableBody">
                <tr>
                    <td colspan="9" style="text-align: center; padding: 24px; color: #888888;">
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
// ===== サイトURL（ページリンク生成用） =====
const SITE_BASE_URL = '<?php
    $site_url = trim((string) get_user_meta($user_id, 'weisite_url', true));
    echo esc_js( $site_url ? rtrim(esc_url($site_url), '/') : '' );
?>';

// ===== グローバル変数 =====
let currentData = null;       // API生レスポンス
let filteredPages = [];       // フィルタ/ソート後
let currentPage = 1;          // ペジネーション現在ページ
const perPage = 20;           // 1ページあたりの表示件数
let currentPeriod = null;     // 期間セレクター連動ガード
let sortKey = 'pageViews';    // 現在のソートカラム
let sortDir = 'desc';         // 'asc' | 'desc'

// ===== period-selector モジュール連携（device と同一パターン） =====
(function bindPeriodSelector() {
    const selectorId = 'page-period';
    const selectorEl = document.getElementById(selectorId);

    const initialPeriod = 'prev-month';

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
 * データ取得とUI更新（device と同一経路）
 */
async function loadData(period) {
    currentPeriod = period;
    showLoading();

    try {
        const apiUrl = '<?php echo esc_url(rest_url("gcrev/v1/analysis/page")); ?>?period=' + encodeURIComponent(period);
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
        updatePeriodRangeFromData(currentData, 'page-period');

        // フィルタ適用＆テーブル描画
        applyFilter();

    } catch (error) {
        console.error('データ取得エラー:', error);
        alert('データの取得に失敗しました。もう一度お試しください。');
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

    let html = '<strong>分析対象期間：</strong>' + fmt(current.start, current.end);

    if (comparison) {
        html += ' <span style="margin: 0 8px; color: #888888;">|</span> ' +
                '<strong>比較期間：</strong>' + fmt(comparison.start, comparison.end);
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
    if (!currentData || !currentData.pages_detail) return;

    const searchVal = (document.getElementById('filterSearch').value || '').toLowerCase().trim();

    // 検索フィルタ
    let pages = currentData.pages_detail.slice();
    if (searchVal) {
        pages = pages.filter(p =>
            (p.page || '').toLowerCase().includes(searchVal) ||
            (p.title || '').toLowerCase().includes(searchVal)
        );
    }

    // カラムソート
    pages = sortPages(pages, sortKey, sortDir);

    filteredPages = pages;
    currentPage = 1;
    renderTable();
    renderPagination();
}

function resetFilter() {
    document.getElementById('filterSearch').value = '';
    sortKey = 'pageViews';
    sortDir = 'desc';
    updateSortHeaders();
    applyFilter();
}

/**
 * 配列を指定カラム・方向でソート
 */
function sortPages(pages, key, dir) {
    return pages.sort((a, b) => {
        let valA = a[key];
        let valB = b[key];
        // null / undefined は末尾に
        if (valA == null) valA = -Infinity;
        if (valB == null) valB = -Infinity;
        const cmp = (typeof valA === 'string')
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
        // 同じカラム → 方向をトグル
        sortDir = sortDir === 'desc' ? 'asc' : 'desc';
    } else {
        // 違うカラム → descから開始
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
    document.querySelectorAll('#pageTable thead th.sortable').forEach(th => {
        th.classList.remove('sort-asc', 'sort-desc');
        if (th.dataset.sortKey === sortKey) {
            th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
        }
    });
}

// ===== テーブル描画 =====
function renderTable() {
    const tbody = document.getElementById('pageTableBody');
    if (!filteredPages || filteredPages.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 24px; color: #888888;">データがありません</td></tr>';
        return;
    }

    const start = (currentPage - 1) * perPage;
    const end = start + perPage;
    const pageSlice = filteredPages.slice(start, end);

    const rows = pageSlice.map((p, idx) => {
        const rank = start + idx + 1;
        const rankClass = rank <= 5 ? 'top5' : '';
        const title = p.title || p.page || '(不明)';
        const path = p.page || '';

        // 変動バッジ
        let trendHtml = '<span class="trend-badge neutral">- -</span>';
        if (typeof p.pvChange === 'number' && !isNaN(p.pvChange)) {
            if (p.pvChange > 0) {
                trendHtml = `<span class="trend-badge up">↑ ${Math.abs(p.pvChange).toFixed(1)}%</span>`;
            } else if (p.pvChange < 0) {
                trendHtml = `<span class="trend-badge down">↓ ${Math.abs(p.pvChange).toFixed(1)}%</span>`;
            } else {
                trendHtml = `<span class="trend-badge neutral">- 0.0%</span>`;
            }
        }

        // ページURL生成（サイトURLがあればリンク化）
        let titleHtml = '';
        if (SITE_BASE_URL && path) {
            const fullUrl = SITE_BASE_URL + (path.startsWith('/') ? '' : '/') + path;
            titleHtml = `<a href="${escAttr(fullUrl)}" target="_blank" rel="noopener noreferrer">${escHtml(title)}</a>`;
        } else {
            titleHtml = escHtml(title);
        }

        return `
            <tr>
                <td><span class="rank-badge ${rankClass}">${rank}</span></td>
                <td class="page-url">${titleHtml}<br><small>${escHtml(path)}</small></td>
                <td>${formatNumber(p.pageViews)}</td>
                <td>${formatPercent(p.percentage)}</td>
                <td>${formatNumber(p.sessions)}</td>
                <td>${formatDuration(p.avgDuration)}</td>
                <td>${formatPercent(p.bounceRate)}</td>
                <td>${formatPercent(p.engagementRate)}</td>
                <td>${trendHtml}</td>
            </tr>
        `;
    }).join('');

    tbody.innerHTML = rows;
}

// ===== ペジネーション =====
function renderPagination() {
    const container = document.getElementById('pagination');
    const totalPages = Math.ceil(filteredPages.length / perPage);

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
    const totalPages = Math.ceil(filteredPages.length / perPage);
    if (page < 1 || page > totalPages) return;
    currentPage = page;
    renderTable();
    renderPagination();
    // テーブル先頭にスクロール
    document.getElementById('pageTable').scrollIntoView({ behavior: 'smooth', block: 'start' });
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

function formatDuration(seconds) {
    if (seconds === null || seconds === undefined) return '-';
    const minutes = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
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
    if (!filteredPages || filteredPages.length === 0) {
        alert('エクスポートするデータがありません');
        return;
    }

    const headers = ['順位', 'ページタイトル', 'URL', 'PV', '割合', 'セッション', '平均滞在時間', '直帰率', 'エンゲージメント率', '変動'];
    const rows = filteredPages.map((p, idx) => [
        idx + 1,
        '"' + (p.title || '').replace(/"/g, '""') + '"',
        '"' + (p.page || '').replace(/"/g, '""') + '"',
        p.pageViews || 0,
        formatPercent(p.percentage),
        p.sessions || 0,
        formatDuration(p.avgDuration),
        formatPercent(p.bounceRate),
        formatPercent(p.engagementRate),
        typeof p.pvChange === 'number' ? p.pvChange.toFixed(1) + '%' : '-'
    ]);

    let csv = '\uFEFF' + headers.join(',') + '\n';
    rows.forEach(row => {
        csv += row.join(',') + '\n';
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'page-analysis-' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php get_footer(); ?>
