<?php
/*
Template Name: 検索語句分析
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

mimamori_guard_meo_access();

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', '検索語句分析');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('検索語句分析', 'MEO'));

// ===== GBP接続状態判定 =====
global $gcrev_api_instance;
if ( ! isset($gcrev_api_instance) || ! ($gcrev_api_instance instanceof Gcrev_Insight_API) ) {
    $gcrev_api_instance = new Gcrev_Insight_API(false);
}
$gcrev_api = $gcrev_api_instance;

$gbp_status    = $gcrev_api->gbp_get_connection_status($user_id);
$is_connected  = $gbp_status['connected'];
$needs_reauth  = $gbp_status['needs_reauth'];

$location_id      = get_user_meta($user_id, '_gcrev_gbp_location_id', true);
$location_address = get_user_meta($user_id, '_gcrev_gbp_location_address', true);
$has_location     = !empty($location_address);

get_header();
?>

<style>
.st-page-description {
    background: var(--mw-card-bg, #fff);
    border-radius: 12px;
    padding: 20px 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 24px;
    font-size: 14px;
    line-height: 1.7;
    color: var(--mw-text-secondary, #666);
}
.st-card {
    background: var(--mw-card-bg, #fff);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 24px;
}
.st-card-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--mw-text-primary, #2C3E40);
    margin-bottom: 20px;
}
.st-table {
    width: 100%;
    border-collapse: collapse;
}
.st-table th {
    padding: 10px 14px;
    text-align: left;
    font-size: 13px;
    font-weight: 700;
    color: #555;
    border-bottom: 2px solid #e5e7eb;
    white-space: nowrap;
}
.st-table th.num {
    text-align: right;
}
.st-table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #555;
}
.st-table td.num {
    text-align: right;
}
.st-table td.kw {
    font-weight: 600;
}
.st-table tr.total-row {
    background: #f8f9fa;
}
.st-table tr.total-row td {
    font-weight: 700;
    color: #333;
}
.st-empty {
    padding: 40px 24px;
    text-align: center;
    color: #888;
    font-size: 14px;
}
.st-chart-container {
    position: relative;
    width: 100%;
    max-height: 360px;
}
.st-loading {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(255,255,255,0.6);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}
.st-loading-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #e5e7eb;
    border-top: 4px solid #568184;
    border-radius: 50%;
    animation: st-spin 0.8s linear infinite;
}
@keyframes st-spin {
    to { transform: rotate(360deg); }
}
@media (max-width: 768px) {
    .st-card { padding: 16px; }
    .st-table th, .st-table td { padding: 8px 10px; font-size: 13px; }
}
</style>

<!-- コンテンツエリア -->
<div class="content-area">

    <!-- ローディング -->
    <div class="st-loading" id="stLoading" style="display: none;">
        <div class="st-loading-spinner"></div>
    </div>

<?php if ( ! $is_connected ): ?>
    <div style="background: #fff; border-radius: 12px; padding: 40px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="font-size: 48px; margin-bottom: 16px;">🔗</div>
        <h2 style="font-size: 20px; font-weight: 700; color: #2C3E40; margin-bottom: 12px;">Googleビジネスプロフィールが未接続です</h2>
        <p style="color: #666; margin-bottom: 24px;">検索語句データを表示するには、まず MEOダッシュボード からGBPを接続してください。</p>
        <a href="<?php echo esc_url( home_url('/meo/meo-dashboard/') ); ?>" style="display: inline-block; padding: 12px 32px; background: #568184; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600;">MEOダッシュボードへ</a>
    </div>
<?php elseif ( $needs_reauth ): ?>
    <div style="background: #fffbeb; border-radius: 12px; padding: 20px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 24px;">⚠️</span>
        <div>
            <div style="font-weight: 700; color: #92400e; margin-bottom: 4px;">GBP認証の更新が必要です</div>
            <div style="font-size: 13px; color: #92400e;">MEOダッシュボードから再認証してください。</div>
        </div>
    </div>
<?php elseif ( ! $has_location ): ?>
    <div style="background: #fff; border-radius: 12px; padding: 40px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="font-size: 48px; margin-bottom: 16px;">📍</div>
        <h2 style="font-size: 20px; font-weight: 700; color: #2C3E40; margin-bottom: 12px;">ロケーションが未設定です</h2>
        <p style="color: #666; margin-bottom: 24px;">MEOダッシュボードでGBPロケーションを選択してください。</p>
        <a href="<?php echo esc_url( home_url('/meo/meo-dashboard/') ); ?>" style="display: inline-block; padding: 12px 32px; background: #568184; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600;">MEOダッシュボードへ</a>
    </div>
<?php else: ?>

    <!-- 説明文 -->
    <div class="st-page-description">
        📊 Googleビジネスプロフィールの検索パフォーマンスデータです。ユーザーがGoogle検索やGoogleマップであなたのビジネスを見つけた際に使用した検索語句と、その表示回数を確認できます。
    </div>

    <!-- 検索語句テーブル（月別時系列） -->
    <div class="st-card">
        <div class="st-card-title">🔍 見つけられた検索語句</div>
        <div style="overflow-x: auto;">
            <table class="st-table" id="stKeywordsTable">
                <thead id="stKeywordsHead"></thead>
                <tbody id="stKeywordsBody">
                    <tr><td colspan="2" class="st-empty">データを読み込み中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 検索語句推移グラフ -->
    <div class="st-card">
        <div class="st-card-title">📈 検索語句の表示回数推移</div>
        <div class="st-chart-container">
            <canvas id="stKeywordsChart"></canvas>
        </div>
    </div>

<?php endif; ?>

</div><!-- .content-area -->

<?php if ( $is_connected && ! $needs_reauth && $has_location ): ?>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
(function() {
    var REST_BASE = <?php echo wp_json_encode(esc_url_raw(rest_url('gcrev/v1/'))); ?>;
    var WP_NONCE  = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;

    var chartInstance = null;
    var chartColors = ['#568184', '#D4A842', '#C95A4F', '#7AA3A6', '#ec4899', '#8b5cf6', '#10b981', '#f59e0b', '#6366f1', '#14b8a6'];

    function showLoading() { document.getElementById('stLoading').style.display = 'flex'; }
    function hideLoading() { document.getElementById('stLoading').style.display = 'none'; }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function loadData() {
        // キャッシュチェック（ローディングなしで即表示）
        var cacheKey = 'meo_search_terms';
        var cached = window.gcrevCache && window.gcrevCache.get(cacheKey);
        if (cached) {
            renderKeywordsTable(cached);
            renderKeywordsChart(cached);
            return;
        }

        showLoading();

        fetch(REST_BASE + 'meo/dashboard?period=prev-month', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': WP_NONCE }
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                document.getElementById('stKeywordsBody').innerHTML =
                    '<tr><td colspan="2" class="st-empty">データの取得に失敗しました: ' + escapeHtml(res.message || '') + '</td></tr>';
                return;
            }
            // キャッシュに保存
            if (window.gcrevCache) window.gcrevCache.set(cacheKey, res);
            renderKeywordsTable(res);
            renderKeywordsChart(res);
        })
        .catch(function(err) {
            console.error('Search terms fetch error:', err);
            document.getElementById('stKeywordsBody').innerHTML =
                '<tr><td colspan="2" class="st-empty">データの取得に失敗しました。</td></tr>';
        })
        .finally(function() {
            hideLoading();
        });
    }

    function renderKeywordsTable(data) {
        var kwData = data.search_keywords || {};
        var months = kwData.months || [];
        var keywords = kwData.keywords || [];
        var kwHead = document.getElementById('stKeywordsHead');
        var kwBody = document.getElementById('stKeywordsBody');
        if (!kwBody) return;

        var colCount = months.length + 2;

        if (keywords.length === 0) {
            if (kwHead) kwHead.innerHTML = '';
            kwBody.innerHTML = '<tr><td colspan="' + colCount + '" class="st-empty">検索語句データがありません</td></tr>';
            return;
        }

        // ヘッダー
        if (kwHead) {
            var headHtml = '<tr><th>検索語句</th>';
            months.forEach(function(m) {
                headHtml += '<th class="num">' + escapeHtml(m) + '</th>';
            });
            headHtml += '<th class="num">合計</th></tr>';
            kwHead.innerHTML = headHtml;
        }

        // ボディ
        var bodyHtml = '';
        var monthTotals = new Array(months.length).fill(0);
        var grandTotal = 0;

        keywords.forEach(function(kw) {
            var monthly = kw.monthly || [];
            var total = kw.total || 0;
            bodyHtml += '<tr><td class="kw">' + escapeHtml(kw.keyword || '') + '</td>';
            months.forEach(function(m, mi) {
                var val = monthly[mi] || 0;
                monthTotals[mi] += val;
                bodyHtml += '<td class="num">' + Number(val).toLocaleString() + '</td>';
            });
            grandTotal += total;
            bodyHtml += '<td class="num" style="font-weight:700;color:#333;">' + Number(total).toLocaleString() + '</td>';
            bodyHtml += '</tr>';
        });

        // 合計行
        bodyHtml += '<tr class="total-row"><td>合計</td>';
        monthTotals.forEach(function(t) {
            bodyHtml += '<td class="num">' + Number(t).toLocaleString() + '</td>';
        });
        bodyHtml += '<td class="num">' + Number(grandTotal).toLocaleString() + '</td></tr>';

        kwBody.innerHTML = bodyHtml;
    }

    function renderKeywordsChart(data) {
        var kwData = data.search_keywords || {};
        var months = kwData.months || [];
        var keywords = kwData.keywords || [];
        var canvas = document.getElementById('stKeywordsChart');
        if (!canvas || keywords.length === 0 || months.length === 0) return;

        // 上位10キーワードのみグラフ化
        var topKw = keywords.slice(0, 10);

        var datasets = topKw.map(function(kw, i) {
            return {
                label: kw.keyword || '',
                data: kw.monthly || [],
                borderColor: chartColors[i % chartColors.length],
                backgroundColor: chartColors[i % chartColors.length] + '20',
                borderWidth: 2,
                pointRadius: 3,
                tension: 0.3,
                fill: false
            };
        });

        if (chartInstance) {
            chartInstance.destroy();
        }

        chartInstance = new Chart(canvas, {
            type: 'line',
            data: {
                labels: months,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 16, font: { size: 12 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': ' + Number(ctx.parsed.y).toLocaleString() + ' 回表示';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(val) { return Number(val).toLocaleString(); }
                        },
                        title: {
                            display: true,
                            text: '表示回数',
                            font: { size: 12 }
                        }
                    }
                }
            }
        });
    }

    // 初期読み込み
    loadData();

})();
</script>
<?php endif; ?>

<?php get_footer(); ?>
