<?php
/*
Template Name: 集客分析
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定（修正）
set_query_var('gcrev_page_title', '集客分析');

// パンくず設定（修正）
$breadcrumb = '<a href="' . home_url() . '">ホーム</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<a href="#">ホームページ</a>';
$breadcrumb .= '<span>›</span>';
$breadcrumb .= '<strong>集客分析</strong>';
set_query_var('gcrev_breadcrumb', $breadcrumb);

get_header();
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<style>
/* page-analysis — Page-specific overrides only */
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

    <!-- 期間セレクター -->
<?php
set_query_var('gcrev_period_selector', [
  'id' => 'analysis-period',
  'items' => [
    ['value' => 'last30',     'label' => '直近30日'],
    ['value' => 'prev-month', 'label' => '前月'],
    ['value' => 'last90',     'label' => '過去90日'],
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
set_query_var('analysis_help_key', 'default');
get_template_part('template-parts/analysis-help');
?>
    <!-- 主要トレンドサマリー（青い帯） -->
    <div class="summary-banner" id="summaryBanner">
        <h2>
            <span>📊</span>
            <span>主要トレンドサマリー</span>
        </h2>
        <div class="summary-content" id="summaryContent">
            データを読み込んでいます...
        </div>
    </div>

    <!-- ダイジェストグリッド -->
    <div class="digest-grid">
        <!-- デバイス別アクセス -->
        <div class="digest-card">
            <div class="digest-card-header">
                <h3 class="digest-card-title">
                    <span>📱</span>
                    <span>デバイス別アクセス</span>
                </h3>
                <a href="<?php echo esc_url(home_url('/mypage/analysis-device/')); ?>" class="detail-link">詳細を見る →</a>
            </div>
            <div class="digest-chart-placeholder">
                <canvas id="deviceChart" width="400" height="100"></canvas>
            </div>
            <ul class="digest-list" id="deviceList">
                <li class="digest-list-item">
                    <span class="digest-list-item-name">読み込み中...</span>
                    <span class="digest-list-item-value">-</span>
                </li>
            </ul>
        </div>

        <!-- 年齢別アクセス -->
        <div class="digest-card">
            <div class="digest-card-header">
                <h3 class="digest-card-title">
                    <span>👥</span>
                    <span>年齢別アクセス</span>
                </h3>
                <a href="<?php echo esc_url(home_url('/mypage/analysis-age/')); ?>" class="detail-link">詳細を見る →</a>
            </div>
            <div class="digest-chart-placeholder">
                <canvas id="ageChart" width="400" height="100"></canvas>
            </div>
            <ul class="digest-list" id="ageList">
                <li class="digest-list-item">
                    <span class="digest-list-item-name">読み込み中...</span>
                    <span class="digest-list-item-value">-</span>
                </li>
            </ul>
        </div>

        <!-- 流入元 -->
        <div class="digest-card">
            <div class="digest-card-header">
                <h3 class="digest-card-title">
                    <span>🌐</span>
                    <span>見つけたきっかけ</span>
                </h3>
                <a href="<?php echo esc_url(home_url('/mypage/analysis-source/')); ?>" class="detail-link">詳細を見る →</a>
            </div>
            <div class="digest-chart-placeholder">
                <canvas id="mediumChart" width="400" height="100"></canvas>
            </div>
            <ul class="digest-list" id="mediumList">
                <li class="digest-list-item">
                    <span class="digest-list-item-name">読み込み中...</span>
                    <span class="digest-list-item-value">-</span>
                </li>
            </ul>
        </div>

        <!-- 地域別アクセス TOP5 -->
        <div class="digest-card">
            <div class="digest-card-header">
                <h3 class="digest-card-title">
                    <span>📍</span>
                    <span>地域別アクセス TOP5</span>
                </h3>
                <a href="<?php echo esc_url(home_url('/mypage/analysis-region/')); ?>" class="detail-link">詳細を見る →</a>
            </div>
            <ul class="digest-list" style="margin-top: 20px;" id="regionList">
                <li class="digest-list-item">
                    <span class="digest-list-item-name">読み込み中...</span>
                    <span class="digest-list-item-value">-</span>
                </li>
            </ul>
        </div>

        <!-- ページランキング TOP5 -->
        <div class="digest-card">
            <div class="digest-card-header">
                <h3 class="digest-card-title">
                    <span>📄</span>
                    <span>ページランキング TOP5</span>
                </h3>
                <a href="<?php echo esc_url(home_url('/mypage/analysis-pages/')); ?>" class="detail-link">詳細を見る →</a>
            </div>
            <ul class="digest-list" style="margin-top: 20px;" id="pagesList">
                <li class="digest-list-item">
                    <span class="digest-list-item-name">読み込み中...</span>
                    <span class="digest-list-item-value">-</span>
                </li>
            </ul>
        </div>

        <!-- キーワードランキング TOP5 -->
        <div class="digest-card">
            <div class="digest-card-header">
                <h3 class="digest-card-title">
                    <span>🔑</span>
                    <span>キーワードランキング TOP5</span>
                </h3>
                <a href="<?php echo esc_url(home_url('/mypage/analysis-keywords/')); ?>" class="detail-link">詳細を見る →</a>
            </div>
            <ul class="digest-list" style="margin-top: 20px;" id="keywordsList">
                <li class="digest-list-item">
                    <span class="digest-list-item-name">読み込み中...</span>
                    <span class="digest-list-item-value">-</span>
                </li>
            </ul>
        </div>
    </div>
</div>

<script>
// グローバル変数
let currentPeriod = 'prev-month';
let charts = {}; // Chart.jsインスタンスを保持


let chartsReady = false;
let pendingPeriod = null;

// 期間変更イベント（共通モジュールから発火）
document.getElementById('analysis-period')?.addEventListener('gcrev:periodChange', (e) => {
    const period = e.detail?.period;
    if (!period) return;

    currentPeriod = period;

    // DOMContentLoaded前にイベントが来た場合は保留
    if (!chartsReady) {
        pendingPeriod = period;
        return;
    }

    updateAnalysisData(period);
});

// ページ読み込み時の初期化
document.addEventListener('DOMContentLoaded', function() {
    console.log('Analysis page initialized');

    // Chart.jsの読み込み確認
    if (typeof Chart === 'undefined') {
        console.error('Chart.js not loaded');
        alert('グラフライブラリの読み込みに失敗しました。ページを再読み込みしてください。');
        return;
    }

    // ここまで来たら描画処理を動かしてOK
    chartsReady = true;

    // 共通モジュールが localStorage に保存している選択値を優先
    try {
        const stored = localStorage.getItem('gcrev_period_analysis-period');
        if (stored) currentPeriod = stored;
    } catch (e) {}

    // DOMContentLoaded前に periodChange が来ていた場合はそれを優先
    const initialPeriod = pendingPeriod || currentPeriod;
    pendingPeriod = null;

    updateAnalysisData(initialPeriod);
});
/**
 * 分析データを更新
 */
function updateAnalysisData(period) {
    showLoading();

    const apiUrl =
        '<?php echo rest_url("gcrev/v1/dashboard/kpi"); ?>?period=' +
        encodeURIComponent(period);

    fetch(apiUrl, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
        },
        credentials: 'same-origin'
    })
        .then(response => {
            console.log('Response status:', response.status);

            if (!response.ok) {
                if (response.status === 401) {
                    throw new Error(
                        '認証エラー: ログインセッションが切れている可能性があります。ページを再読み込みしてください。'
                    );
                }
                throw new Error('サーバーエラー (HTTP ' + response.status + ')');
            }

            return response.json();
        })
        .then(result => {
            console.log('API Response:', result);

            if (!result?.success || !result?.data) {
                console.error('API Error:', result);
                throw new Error(result?.message || 'データの取得に失敗しました');
            }

            const data = result.data;

            // ===============================
            // ▼ 各分析表示更新
            // ===============================
            updateAnalysisDisplay(data);

            // ===============================
            // ▼ 期間表示を period-selector モジュールへ反映
            // ===============================
            if (window.GCREV?.updatePeriodRange) {
                GCREV.updatePeriodRange(
                    'analysis-period',
                    data.current_range_label || '',
                    data.compare_range_label || ''
                );
            }
        })
        .catch(error => {
            console.error('Error fetching analysis data:', error);

            alert(
                'データの取得に失敗しました。ページを再読み込みしてください。\n\nエラー: ' +
                    error.message
            );
        })
        .finally(() => {
            hideLoading();
        });
}


/**
 * 画面表示を更新
 */
function updateAnalysisDisplay(data) {
    // デバッグ：データ構造を確認
    console.log('=== Data Structure Debug ===');
    console.log('Full data:', data);
    console.log('devices:', data.devices);
    console.log('age:', data.age);
    console.log('medium:', data.medium);
    console.log('geo_region:', data.geo_region);
    console.log('pages:', data.pages);
    console.log('keywords:', data.keywords);
    console.log('===========================');
    
    // 期間表示を更新
    updatePeriodDisplay(data);
    
    // サマリーバナーを更新
    updateSummaryBanner(data);
    
    // デバイス別アクセスを更新
    updateDeviceList(data.devices || []);
    
    // 年齢別アクセスを更新
    updateAgeList(data.age || []);
    
    // 流入元を更新
    updateMediumList(data.medium || []);
    
    // 地域別アクセスを更新
    updateRegionList(data.geo_region || []);
    
    // ページランキングを更新
    updatePagesList(data.pages || []);
    
    // キーワードランキングを更新
    updateKeywordsList(data.keywords || []);
}

/**
 * 期間表示を更新（共通モジュールへ委譲）
 * - 表示形式（#periodDisplay）は現状維持
 * - data.current_period / data.comparison_period を想定（後方互換）
 */
function updatePeriodDisplay(data) {
    // 共通モジュールがあればそれを使用
    if (window.GCREV && typeof window.GCREV.updatePeriodDisplay === 'function') {
        window.GCREV.updatePeriodDisplay(data, { periodDisplayId: 'periodDisplay' });
        return;
    }

    // フォールバック（モジュール未読込時でも表示を崩さない）
    const periodDisplay = document.getElementById('periodDisplay');
    if (!periodDisplay || !data || !data.current_period) return;

    const current = data.current_period;
    const comparison = data.comparison_period;

    const formatPeriod = (start, end) => {
        if (!start || !end) return '-';
        return String(start).replace(/-/g, '/') + ' 〜 ' + String(end).replace(/-/g, '/');
    };

    let html = '<strong>分析対象期間：</strong>' + formatPeriod(current.start, current.end);

    if (comparison) {
        html += ' <span style="margin: 0 8px; color: #888888;">|</span> ' +
                '<strong>比較期間：</strong>' + formatPeriod(comparison.start, comparison.end);
    }

    periodDisplay.innerHTML = html;
}

/**
 * サマリーバナーを更新
 */
function updateSummaryBanner(data) {
    const summaryContent = document.getElementById('summaryContent');
    if (!summaryContent) return;
    
    const trends = data.trends || {};
    const devices = data.devices || [];
    const medium = data.medium || [];
    
    let summary = [];
    
    // 主要KPIのトレンド
    if (trends.sessions && trends.sessions.text) {
        summary.push('セッション数は' + trends.sessions.text);
    }
    if (trends.users && trends.users.text) {
        summary.push('ユーザー数は' + trends.users.text);
    }
    
    // デバイスの傾向
    if (devices.length > 0) {
        const topDevice = devices[0];
        const deviceName = getDeviceName(topDevice.device || topDevice.deviceCategory || 'unknown');
        
        // デバイスの合計を計算
        const deviceTotal = devices.reduce((sum, item) => {
            const count = typeof item.count === 'string' 
                ? parseInt(item.count.replace(/,/g, '')) 
                : (item.count || item.sessions || 0);
            return sum + count;
        }, 0);
        
        const topDeviceCount = typeof topDevice.count === 'string' 
            ? parseInt(topDevice.count.replace(/,/g, '')) 
            : (topDevice.count || topDevice.sessions || 0);
        
        const percentage = calculatePercentage(topDeviceCount, deviceTotal);
        summary.push(deviceName + 'からのアクセスが' + percentage + '%を占めています');
    }
    
    // 流入元の傾向
    if (medium.length > 0) {
        const topMedium = medium[0];
        const mediumName = getMediumName(topMedium.medium || topMedium.sessionMedium || 'unknown');
        summary.push('もっとも多い「見つけたきっかけ」は' + mediumName + 'です');
    }
    
    summaryContent.innerHTML = summary.length > 0 ? summary.join('。') + '。' : 'データを分析中...';
}

/**
 * デバイス別リストを更新
 */
function updateDeviceList(devices) {
    console.log('updateDeviceList called with:', devices);
    
    const listEl = document.getElementById('deviceList');
    if (!listEl) return;
    
    if (!devices || devices.length === 0) {
        console.log('No devices data');
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }
    
    console.log('Processing devices, count:', devices.length);
    console.log('First device:', devices[0]);
    
    // 合計を計算（countフィールドはカンマ区切り文字列の可能性がある）
    const total = devices.reduce((sum, item) => {
        const count = typeof item.count === 'string' 
            ? parseInt(item.count.replace(/,/g, '')) 
            : (item.count || 0);
        return sum + count;
    }, 0);
    
    console.log('Total count:', total);
    
    const top3 = devices.slice(0, 3);
    
    listEl.innerHTML = top3.map(item => {
        console.log('Device item:', item);
        // deviceフィールドを使用
        const name = getDeviceName(item.device || item.deviceCategory || 'unknown');
        // countフィールドを使用
        const count = typeof item.count === 'string' 
            ? parseInt(item.count.replace(/,/g, '')) 
            : (item.count || item.sessions || 0);
        const percentage = calculatePercentage(count, total);
        
        return `
            <li class="digest-list-item">
                <span class="digest-list-item-name">${escapeHtml(name)}</span>
                <span class="digest-list-item-value">${percentage}%</span>
            </li>
        `;
    }).join('');
    
    // グラフ生成
    createDeviceChart(devices);
}

/**
 * 年齢別リストを更新
 */
function updateAgeList(ageData) {
    const listEl = document.getElementById('ageList');
    if (!listEl) return;
    
    if (!ageData || ageData.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }
    
    // 合計を計算（sessionsフィールドはカンマ区切り文字列）
    const total = ageData.reduce((sum, item) => {
        const sessions = typeof item.sessions === 'string' 
            ? parseInt(item.sessions.replace(/,/g, '')) 
            : (item.sessions || item.users || 0);
        return sum + sessions;
    }, 0);
    
    const top3 = ageData.slice(0, 3);
    
    listEl.innerHTML = top3.map(item => {
        // nameフィールドを使用（userAgeBracket/ageではない）
        const name = item.name || item.userAgeBracket || item.age || 'unknown';
        // sessionsフィールドを数値に変換
        const sessions = typeof item.sessions === 'string' 
            ? parseInt(item.sessions.replace(/,/g, '')) 
            : (item.sessions || item.users || 0);
        const percentage = calculatePercentage(sessions, total);
        
        return `
            <li class="digest-list-item">
                <span class="digest-list-item-name">${escapeHtml(name)}</span>
                <span class="digest-list-item-value">${percentage}%</span>
            </li>
        `;
    }).join('');
    
    // グラフ生成
    createAgeChart(ageData);
}

/**
 * 流入元リストを更新
 */
function updateMediumList(medium) {
    const listEl = document.getElementById('mediumList');
    if (!listEl) return;
    
    if (!medium || medium.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }
    
    // 合計を計算（sessionsフィールドはカンマ区切り文字列）
    const total = medium.reduce((sum, item) => {
        const sessions = typeof item.sessions === 'string' 
            ? parseInt(item.sessions.replace(/,/g, '')) 
            : (item.sessions || 0);
        return sum + sessions;
    }, 0);
    
    const top3 = medium.slice(0, 3);
    
    listEl.innerHTML = top3.map(item => {
        // mediumフィールドを使用（sessionMediumではない）
        const name = getMediumName(item.medium || item.sessionMedium || 'unknown');
        // sessionsフィールドを数値に変換
        const sessions = typeof item.sessions === 'string' 
            ? parseInt(item.sessions.replace(/,/g, '')) 
            : (item.sessions || 0);
        const percentage = calculatePercentage(sessions, total);
        
        return `
            <li class="digest-list-item">
                <span class="digest-list-item-name">${escapeHtml(name)}</span>
                <span class="digest-list-item-value">${percentage}%</span>
            </li>
        `;
    }).join('');
    
    // グラフ生成
    createMediumChart(medium);
}

/**
 * 地域別リストを更新
 */
function updateRegionList(regions) {
    const listEl = document.getElementById('regionList');
    if (!listEl) return;
    
    if (!regions || regions.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }
    
    const top5 = regions.slice(0, 5);
    
    listEl.innerHTML = top5.map((item, index) => {
        // nameフィールドを使用（region/cityではない）
        const name = item.name || item.region || item.city || 'unknown';
        // 地域名を日本語に変換
        const displayName = convertRegionNameToJapanese(name);
        // sessionsフィールドを数値に変換（カンマ区切り文字列の可能性）
        const value = typeof item.sessions === 'string' 
            ? parseInt(item.sessions.replace(/,/g, '')) 
            : (item.sessions || item.users || 0);
        
        return `
            <li class="digest-list-item">
                <span class="digest-list-item-name">${index + 1}. ${escapeHtml(displayName)}</span>
                <span class="digest-list-item-value">${formatNumber(value)}</span>
            </li>
        `;
    }).join('');
}

/**
 * ページランキングを更新
 */
function updatePagesList(pages) {
    const listEl = document.getElementById('pagesList');
    if (!listEl) return;
    
    if (!pages || pages.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }
    
    const top5 = pages.slice(0, 5);
    
    listEl.innerHTML = top5.map((item, index) => {
        // titleフィールドをそのまま使用（APIで実際のページタイトルが返される）
        let name = item.title || '';
        
        // titleが空の場合のみpagePathを整形
        if (!name || name.trim() === '') {
            const path = item.pagePath || item.page || '';
            name = formatPagePath(path);
        }
        
        // pageViewsフィールドを数値に変換（カンマ区切り文字列の可能性）
        const value = typeof item.pageViews === 'string' 
            ? parseInt(item.pageViews.replace(/,/g, '')) 
            : (item.pageViews || item.screenPageViews || item.pageviews || 0);
        
        return `
            <li class="digest-list-item">
                <span class="digest-list-item-name" title="${escapeHtml(name)}">${index + 1}. ${escapeHtml(name)}</span>
                <span class="digest-list-item-value">${formatNumber(value)}</span>
            </li>
        `;
    }).join('');
}

/**
 * ページパスをタイトル風に整形
 */
function formatPagePath(path) {
    if (!path || path === '/') {
        return 'トップページ';
    }
    
    // URLデコード
    try {
        path = decodeURIComponent(path);
    } catch(e) {
        // デコードエラーは無視
    }
    
    // 最後のセグメントを取得
    const segments = path.split('/').filter(s => s.length > 0);
    if (segments.length === 0) {
        return 'トップページ';
    }
    
    let last = segments[segments.length - 1];
    
    // 拡張子を削除
    last = last.replace(/\.(html|php|htm|asp|aspx|jsp)$/i, '');
    
    // クエリパラメータを削除
    last = last.split('?')[0];
    
    // ハッシュを削除
    last = last.split('#')[0];
    
    // ハイフンやアンダースコアをスペースに
    last = last.replace(/[-_]/g, ' ');
    
    // 最初の文字を大文字に
    if (last.length > 0) {
        last = last.charAt(0).toUpperCase() + last.slice(1);
    }
    
    // 長すぎる場合は切り詰め
    if (last.length > 30) {
        last = last.substring(0, 27) + '...';
    }
    
    return last || path;
}

/**
 * キーワードランキングを更新
 */
function updateKeywordsList(keywords) {
    const listEl = document.getElementById('keywordsList');
    if (!listEl) return;
    
    if (!keywords || keywords.length === 0) {
        listEl.innerHTML = '<li class="digest-list-item"><span class="digest-list-item-name">データなし</span></li>';
        return;
    }
    
    const top5 = keywords.slice(0, 5);
    
    listEl.innerHTML = top5.map((item, index) => {
        // queryフィールドを使用（keywordではない）
        const name = item.query || item.keyword || 'unknown';
        // clicksフィールドを数値に変換（カンマ区切り文字列の可能性）
        const value = typeof item.clicks === 'string' 
            ? parseInt(item.clicks.replace(/,/g, '')) 
            : (item.clicks || item.impressions || 0);
        
        return `
            <li class="digest-list-item">
                <span class="digest-list-item-name">${index + 1}. ${escapeHtml(name)}</span>
                <span class="digest-list-item-value">${formatNumber(value)}</span>
            </li>
        `;
    }).join('');
}

// ===== グラフ生成関数 =====

/**
 * デバイスグラフ（ドーナツチャート）
 */
function createDeviceChart(devices) {
    const ctx = document.getElementById('deviceChart');
    if (!ctx) return;
    
    // 既存のチャートがあれば破棄
    if (charts.device) {
        charts.device.destroy();
    }
    
    if (!devices || devices.length === 0) return;
    
    // データ準備
    const labels = [];
    const data = [];
    const colors = ['#3D6B6E', '#3D8B6E', '#D4A842', '#B5574B', '#7A6FA0'];
    
    devices.slice(0, 5).forEach(item => {
        const name = getDeviceName(item.device || item.deviceCategory || 'unknown');
        const count = typeof item.count === 'string' 
            ? parseInt(item.count.replace(/,/g, '')) 
            : (item.count || item.sessions || 0);
        
        labels.push(name);
        data.push(count);
    });
    
    // チャート作成
    charts.device = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + formatNumber(context.parsed);
                        }
                    }
                }
            },
            cutout: '60%'
        }
    });
}

/**
 * 年齢グラフ（横棒グラフ）
 */
function createAgeChart(ageData) {
    const ctx = document.getElementById('ageChart');
    if (!ctx) return;
    
    // 既存のチャートがあれば破棄
    if (charts.age) {
        charts.age.destroy();
    }
    
    if (!ageData || ageData.length === 0) return;
    
    // データ準備
    const labels = [];
    const data = [];
    
    ageData.slice(0, 5).forEach(item => {
        const name = item.name || item.userAgeBracket || item.age || 'unknown';
        const sessions = typeof item.sessions === 'string' 
            ? parseInt(item.sessions.replace(/,/g, '')) 
            : (item.sessions || item.users || 0);
        
        labels.push(name);
        data.push(sessions);
    });
    
    // チャート作成
    charts.age = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: '#3D8B6E',
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return formatNumber(context.parsed.x) + ' sessions';
                        }
                    }
                }
            },
            scales: {
                x: {
                    display: false,
                    beginAtZero: true
                },
                y: {
                    display: true,
                    ticks: {
                        font: {
                            size: 10
                        }
                    }
                }
            }
        }
    });
}

/**
 * 流入元グラフ（横棒グラフ）
 */
function createMediumChart(medium) {
    const ctx = document.getElementById('mediumChart');
    if (!ctx) return;
    
    // 既存のチャートがあれば破棄
    if (charts.medium) {
        charts.medium.destroy();
    }
    
    if (!medium || medium.length === 0) return;
    
    // データ準備
    const labels = [];
    const data = [];
    
    medium.slice(0, 5).forEach(item => {
        const name = getMediumName(item.medium || item.sessionMedium || 'unknown');
        const sessions = typeof item.sessions === 'string' 
            ? parseInt(item.sessions.replace(/,/g, '')) 
            : (item.sessions || 0);
        
        labels.push(name);
        data.push(sessions);
    });
    
    // チャート作成
    charts.medium = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: '#3D6B6E',
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return formatNumber(context.parsed.x) + ' sessions';
                        }
                    }
                }
            },
            scales: {
                x: {
                    display: false,
                    beginAtZero: true
                },
                y: {
                    display: true,
                    ticks: {
                        font: {
                            size: 10
                        }
                    }
                }
            }
        }
    });
}

// ===== ユーティリティ関数 =====

/**
 * デバイス名を日本語に変換
 */
function getDeviceName(device) {
    const map = {
        'mobile': 'モバイル',
        'desktop': 'デスクトップ',
        'tablet': 'タブレット'
    };
    return map[device] || device;
}

/**
 * 流入元名を日本語に変換
 */
function getMediumName(medium) {
    const map = {
        'organic': '自然検索',
        'direct': '直接',
        '(none)': '直接',
        'referral': '参照元',
        'cpc': '有料広告',
        'social': 'ソーシャル',
        'email': 'メール'
    };
    return map[medium] || medium;
}

/**
 * パーセンテージを計算
 */
function calculatePercentage(value, total) {
    if (!total || total === 0) return '0.0';
    return ((value / total) * 100).toFixed(1);
}

/**
 * 地域名を日本語に変換
 */
function convertRegionNameToJapanese(regionName) {
    const regionMap = {
        'Hokkaido': '北海道', 'Aomori': '青森県', 'Iwate': '岩手県', 'Miyagi': '宮城県',
        'Akita': '秋田県', 'Yamagata': '山形県', 'Fukushima': '福島県',
        'Ibaraki': '茨城県', 'Tochigi': '栃木県', 'Gunma': '群馬県', 'Saitama': '埼玉県',
        'Chiba': '千葉県', 'Tokyo': '東京都', 'Kanagawa': '神奈川県',
        'Niigata': '新潟県', 'Toyama': '富山県', 'Ishikawa': '石川県', 'Fukui': '福井県',
        'Yamanashi': '山梨県', 'Nagano': '長野県', 'Gifu': '岐阜県', 'Shizuoka': '静岡県', 'Aichi': '愛知県',
        'Mie': '三重県', 'Shiga': '滋賀県', 'Kyoto': '京都府', 'Osaka': '大阪府',
        'Hyogo': '兵庫県', 'Nara': '奈良県', 'Wakayama': '和歌山県',
        'Tottori': '鳥取県', 'Shimane': '島根県', 'Okayama': '岡山県', 'Hiroshima': '広島県', 'Yamaguchi': '山口県',
        'Tokushima': '徳島県', 'Kagawa': '香川県', 'Ehime': '愛媛県', 'Kochi': '高知県',
        'Fukuoka': '福岡県', 'Saga': '佐賀県', 'Nagasaki': '長崎県', 'Kumamoto': '熊本県',
        'Oita': '大分県', 'Miyazaki': '宮崎県', 'Kagoshima': '鹿児島県', 'Okinawa': '沖縄県',
        'Kansai': '関西', 'Kanto': '関東', 'Tohoku': '東北', 'Chubu': '中部',
        'Chugoku': '中国', 'Shikoku': '四国', 'Kyushu': '九州',
        'Beijing': '北京市', 'Shanghai': '上海市', 'Tianjin': '天津市', 'Chongqing': '重慶市',
        'Hebei': '河北省', 'Shanxi': '山西省', 'Liaoning': '遼寧省', 'Jilin': '吉林省', 'Heilongjiang': '黒竜江省',
        'Jiangsu': '江蘇省', 'Zhejiang': '浙江省', 'Anhui': '安徽省', 'Fujian': '福建省', 'Jiangxi': '江西省',
        'Shandong': '山東省', 'Henan': '河南省', 'Hubei': '湖北省', 'Hunan': '湖南省', 'Guangdong': '広東省',
        'Hainan': '海南省', 'Sichuan': '四川省', 'Guizhou': '貴州省', 'Yunnan': '雲南省',
        'Shaanxi': '陝西省', 'Gansu': '甘粛省', 'Qinghai': '青海省', 'Taiwan': '台湾省',
        'Inner Mongolia': '内モンゴル自治区', 'Guangxi': '広西チワン族自治区',
        'Tibet': 'チベット自治区', 'Ningxia': '寧夏回族自治区', 'Xinjiang': '新疆ウイグル自治区',
        'Hong Kong': '香港', 'Macau': 'マカオ',
        'United States': 'アメリカ', 'China': '中国', 'South Korea': '韓国',
        'Singapore': 'シンガポール', 'Thailand': 'タイ', 'Vietnam': 'ベトナム',
        'Philippines': 'フィリピン', 'Indonesia': 'インドネシア', 'Malaysia': 'マレーシア',
        'India': 'インド', 'Australia': 'オーストラリア', 'United Kingdom': 'イギリス',
        'Germany': 'ドイツ', 'France': 'フランス', 'Canada': 'カナダ', 'Brazil': 'ブラジル',
        'Russia': 'ロシア', 'Italy': 'イタリア', 'Spain': 'スペイン',
        'Netherlands': 'オランダ', 'Switzerland': 'スイス'
    };
    return regionMap[regionName] || regionName;
}

/**
 * 数値をフォーマット（カンマ区切り）
 */
function formatNumber(num) {
    if (typeof num === 'string') return num;
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

/**
 * HTML特殊文字をエスケープ
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * ローディング表示
 */
function showLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.add('active');
}

/**
 * ローディング非表示
 */
function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.remove('active');
}



</script>

<?php get_footer(); ?>
