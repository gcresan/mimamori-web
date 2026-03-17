<?php
/*
Template Name: マップ順位
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'マップ順位');
set_query_var('gcrev_page_subtitle', 'Googleマップやローカル検索での表示順位を確認できます。');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('マップ順位', '検索順位チェック'));

get_header();
?>

<style>
/* ============================================================
   Map Rank Page (.meo-)
   ============================================================ */
.meo-section {
    background: transparent;
    border: none;
    border-radius: 0;
    padding: 0;
    box-shadow: none;
}
.meo-header { margin-bottom: 20px; }
.meo-header__title {
    font-size: 20px; font-weight: 700; color: #1a1a1a;
    display: flex; align-items: center; gap: 8px;
}
.meo-help { font-size: 13px; color: #6b7280; margin-bottom: 20px; line-height: 1.6; }
/* Measurement conditions row */
.meo-conditions {
    display: flex; align-items: flex-start; gap: 24px; margin-bottom: 24px; flex-wrap: wrap;
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 20px;
}
.meo-condition-group { display: flex; flex-direction: column; gap: 4px; }
.meo-condition-label {
    font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;
}
.meo-condition-value { font-size: 13px; font-weight: 600; color: #1a1a1a; }
.meo-condition-value--sub { font-size: 11px; font-weight: 400; color: #6b7280; }
/* Device toggle */
.meo-device-toggle {
    display: inline-flex; background: #f2f4f7; border-radius: 8px; padding: 3px;
}
.meo-device-btn {
    padding: 6px 18px; border: none; border-radius: 6px; font-size: 13px; font-weight: 500;
    cursor: pointer; background: transparent; color: #667085; transition: all 0.2s;
}
.meo-device-btn.active {
    background: #fff; color: #1a1a1a; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
/* Keyword selector with label */
.meo-keyword-group { display: flex; flex-direction: column; gap: 4px; }
.meo-keyword-label {
    font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;
}
.meo-keyword-select {
    font-size: 13px; color: #344054; border: 1px solid #d0d5dd; border-radius: 8px;
    padding: 5px 10px; background: #fff; cursor: pointer; max-width: 240px; font-weight: 500;
}
.meo-keyword-single {
    font-size: 13px; font-weight: 600; color: #1a1a1a;
}
/* Radius selector */
.meo-radius-group { display: flex; flex-direction: column; gap: 4px; }
.meo-radius-label {
    font-size: 10px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;
}
.meo-radius-select {
    font-size: 13px; color: #344054; border: 1px solid #d0d5dd; border-radius: 8px;
    padding: 5px 10px; background: #fff; cursor: pointer; max-width: 120px; font-weight: 500;
}
/* Metrics cards */
.meo-metrics-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 28px; }
.meo-metric-card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 16px 20px; border-left: 4px solid #e5e7eb; text-align: center;
}
.meo-metric-card--teal   { border-left-color: #568184; }
.meo-metric-card--blue   { border-left-color: #3b82f6; }
.meo-metric-card--gold   { border-left-color: #f59e0b; }
.meo-metric-card--green  { border-left-color: #22c55e; }
.meo-metric-icon { font-size: 20px; margin-bottom: 4px; }
.meo-metric-label { font-size: 12px; color: #1a1a1a; font-weight: 600; margin-bottom: 2px; }
.meo-metric-sublabel { font-size: 10px; color: #9ca3af; margin-bottom: 6px; line-height: 1.3; }
.meo-metric-value { font-size: 22px; font-weight: 700; color: #1a1a1a; line-height: 1.2; }
.meo-metric-value small { font-size: 12px; font-weight: 400; color: #9ca3af; }
.meo-metric-value--out { font-size: 14px; color: #ef4444; }
/* Store card */
.meo-store-card {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 20px; margin-top: 20px; margin-bottom: 20px;
}
.meo-store-card__title {
    font-size: 14px; font-weight: 700; color: #1a1a1a; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.meo-store-grid {
    display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; font-size: 13px;
}
.meo-store-label { color: #6b7280; font-weight: 500; white-space: nowrap; }
.meo-store-value { color: #1a1a1a; }
.meo-store-link {
    display: inline-flex; align-items: center; gap: 4px; font-size: 12px;
    color: #568184; text-decoration: none; margin-top: 12px;
}
.meo-store-link:hover { text-decoration: underline; }
/* Reviews card */
.meo-reviews-card {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 20px; margin-bottom: 20px;
}
.meo-reviews-card__title {
    font-size: 14px; font-weight: 700; color: #1a1a1a; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.meo-reviews-summary {
    display: flex; align-items: center; gap: 16px; margin-bottom: 16px; flex-wrap: wrap;
}
.meo-reviews-big-rating { font-size: 28px; font-weight: 700; color: #1a1a1a; }
.meo-reviews-stars { font-size: 18px; color: #f59e0b; letter-spacing: 1px; }
.meo-reviews-count { font-size: 13px; color: #6b7280; }
.meo-rating-bars { max-width: 360px; }
.meo-rating-bar-row {
    display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 12px; color: #6b7280;
}
.meo-rating-bar-label { width: 24px; text-align: right; flex-shrink: 0; }
.meo-rating-bar-track {
    flex: 1; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden;
}
.meo-rating-bar-fill {
    height: 100%; background: #f59e0b; border-radius: 4px; transition: width 0.3s;
}
.meo-rating-bar-count { width: 32px; text-align: right; flex-shrink: 0; font-size: 11px; color: #9ca3af; }
/* Competitor table */
.meo-competitor-wrap {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;
    margin-bottom: 10px;
}
.meo-competitor-title {
    font-size: 14px; font-weight: 700; color: #1a1a1a; padding: 16px 16px 12px;
    display: flex; align-items: center; gap: 6px;
}
.meo-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.meo-competitor-table { width: 100%; border-collapse: collapse; }
.meo-competitor-table th {
    background: #f9fafb; font-size: 11px; font-weight: 600; color: #6b7280;
    padding: 10px 14px; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.meo-competitor-table td {
    padding: 12px 14px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #1a1a1a;
    vertical-align: middle;
}
.meo-competitor-table tr:last-child td { border-bottom: none; }
.meo-self-row td {
    background: #f0fdf4; font-weight: 600;
}
.meo-self-row td:first-child {
    border-left: 3px solid #568184;
}
.meo-self-badge {
    display: inline-block; font-size: 9px; color: #568184; background: #e8f4f5;
    border: 1px solid #c5dfe0; border-radius: 4px; padding: 1px 5px; margin-left: 4px;
    font-weight: 600;
}
.meo-stars-sm { font-size: 12px; color: #f59e0b; }
/* States */
.meo-loading { text-align: center; padding: 32px; color: #9ca3af; font-size: 13px; }
.meo-empty { text-align: center; padding: 40px 20px; color: #9ca3af; display: none; }
.meo-empty__icon { font-size: 32px; margin-bottom: 8px; }
.meo-empty__text { font-size: 14px; color: #6b7280; }
.meo-error {
    text-align: center; padding: 24px; color: #ef4444; font-size: 13px;
    background: #fef2f2; border-radius: 8px; margin-top: 12px; display: none;
}
.meo-retry-btn {
    display: inline-block; margin-top: 8px; padding: 6px 14px; border: 1px solid #d0d5dd;
    border-radius: 8px; font-size: 12px; cursor: pointer; background: #fff; color: #344054;
}
.meo-retry-btn:hover { background: #f9fafb; }

/* MEO History Table */
.meo-history-wrap {
    margin-top: 24px; background: #fff; border: 1px solid #e5e7eb;
    border-radius: 12px; padding: 24px;
}
.meo-history-title {
    font-weight: 700; font-size: 15px; color: #1a1a1a; margin-bottom: 12px;
    display: flex; align-items: center; gap: 6px;
}
.meo-history-table { width: 100%; border-collapse: collapse; }
.meo-history-table th {
    background: #f9fafb; font-size: 11px; font-weight: 600; color: #6b7280;
    padding: 10px 14px; text-align: center; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.meo-history-table th:first-child { text-align: left; }
.meo-history-table td {
    padding: 12px 14px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #1a1a1a;
    vertical-align: middle; text-align: center;
}
.meo-history-table td:first-child { text-align: left; font-size: 12px; }
.meo-history-table tr:last-child td { border-bottom: none; }
.meo-trend-up { color: #16a34a; font-weight: 600; font-size: 11px; }
.meo-trend-down { color: #dc2626; font-weight: 600; font-size: 11px; }
.meo-trend-same { color: #9ca3af; font-size: 11px; }

/* Responsive */
@media (max-width: 768px) {
    .meo-metrics-cards { grid-template-columns: repeat(2, 1fr); }
    .meo-conditions { flex-direction: column; gap: 12px; padding: 12px 14px; }
    .meo-store-grid { grid-template-columns: 1fr; gap: 4px; }
    .meo-store-label { font-weight: 600; }
    .meo-reviews-summary { flex-direction: column; align-items: flex-start; }
}
</style>

<div class="content-area">
<section class="meo-section" id="meoSection">
    <div class="meo-header">
        <div class="meo-header__title">
            &#x1F4CD; マップ順位
        </div>
    </div>

    <div class="meo-help">
        Googleマップやローカル検索で、あなたのお店が<strong>何番目に表示されるか</strong>、
        口コミの状況、近くの競合との比較をまとめています。
    </div>

    <!-- 計測条件エリア -->
    <div class="meo-conditions" id="meoConditions">
        <!-- デバイス -->
        <div class="meo-condition-group">
            <span class="meo-condition-label">表示デバイス</span>
            <div class="meo-device-toggle" id="meoDeviceToggle">
                <button class="meo-device-btn active" data-device="mobile">スマホ</button>
                <button class="meo-device-btn" data-device="desktop">PC</button>
            </div>
        </div>
        <!-- 基準地点 -->
        <div class="meo-condition-group" id="meoRegionGroup">
            <span class="meo-condition-label">基準地点</span>
            <span class="meo-condition-value" id="meoRegion">読み込み中...</span>
        </div>
        <!-- 半径（座標モード時のみ表示） -->
        <div class="meo-radius-group" id="meoRadiusGroup" style="display:none;">
            <span class="meo-radius-label">半径</span>
            <select class="meo-radius-select" id="meoRadiusSelect"></select>
        </div>
        <!-- キーワード -->
        <div class="meo-keyword-group" id="meoKeywordGroup">
            <span class="meo-keyword-label">計測キーワード</span>
            <span class="meo-keyword-single" id="meoKeywordSingle"></span>
            <select class="meo-keyword-select" id="meoKeywordSelect" style="display:none;"></select>
        </div>
    </div>

    <!-- メトリクスカード 4枚 -->
    <div class="meo-metrics-cards" id="meoMetricsCards"></div>

    <!-- 週次履歴テーブル -->
    <div class="meo-history-wrap" id="meoHistoryWrap" style="display:none;">
        <div class="meo-history-title">&#x1F4CA; 週次推移</div>
        <div class="meo-help" style="margin-bottom:12px;">
            <span style="color:#6b7280; font-size:12px;">毎週月曜日に自動計測しています。</span>
            <div style="margin-top:8px; font-size:12px; color:#6b7280; line-height:1.7;">
                <span style="font-weight:600; color:#568184;">🗺️ マップ順位</span> … Googleマップアプリや、Google検索の地図枠（上位3件）に表示される順位です。<br>
                <span style="font-weight:600; color:#3b82f6;">🔍 地域順位</span> … Google検索で「もっと見る」をタップした先の、ローカル検索結果の一覧での順位です。
            </div>
        </div>
        <div class="meo-table-scroll">
            <table class="meo-history-table">
                <thead id="meoHistoryHead"></thead>
                <tbody id="meoHistoryBody"></tbody>
            </table>
        </div>
    </div>

    <!-- 店舗情報 -->
    <div class="meo-store-card" id="meoStoreCard" style="display:none;"></div>

    <!-- 口コミ状況 -->
    <div class="meo-reviews-card" id="meoReviewsCard" style="display:none;"></div>

    <!-- 競合比較 -->
    <div class="meo-competitor-wrap" id="meoCompetitorWrap" style="display:none;"></div>

    <!-- 状態表示 -->
    <div class="meo-loading" id="meoLoading">データを取得中...</div>
    <div class="meo-empty" id="meoEmpty" style="display:none;">
        <div class="meo-empty__icon">&#x1F4CD;</div>
        <div class="meo-empty__text">MEOデータがまだありません</div>
        <div style="color:#9ca3af; font-size:12px; margin-top:6px;">
            <a href="<?php echo esc_url( home_url( '/rank-tracker/' ) ); ?>" style="color:#568184;">自然検索順位</a>ページでキーワードを登録すると、Googleマップでの順位も確認できます。
        </div>
    </div>
    <div class="meo-error" id="meoError" style="display:none;"></div>
</section>
</div><!-- /.content-area -->

<script>
/* ============================================================
   MEO Section — マップ順位
   ============================================================ */
(function() {
    'use strict';

    var restBase = '<?php echo esc_url( rest_url( 'gcrev/v1/' ) ); ?>';
    var nonce    = '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>';

    // DOM refs
    var meoSection      = document.getElementById('meoSection');
    var meoLoading      = document.getElementById('meoLoading');
    var meoEmpty        = document.getElementById('meoEmpty');
    var meoError        = document.getElementById('meoError');
    var meoMetricsCards = document.getElementById('meoMetricsCards');
    var meoStoreCard    = document.getElementById('meoStoreCard');
    var meoReviewsCard  = document.getElementById('meoReviewsCard');
    var meoCompetitorWrap = document.getElementById('meoCompetitorWrap');
    var meoRegion       = document.getElementById('meoRegion');
    var meoKeywordSelect = document.getElementById('meoKeywordSelect');
    var meoKeywordSingle = document.getElementById('meoKeywordSingle');
    var meoDeviceToggle  = document.getElementById('meoDeviceToggle');
    var meoRadiusGroup   = document.getElementById('meoRadiusGroup');
    var meoRadiusSelect  = document.getElementById('meoRadiusSelect');

    if (!meoSection) return;

    var currentDevice    = 'mobile';
    var currentKeywordId = 0;
    var currentRadius    = 0;
    var isCoordinateMode = false;

    // ----- Init -----
    function meoInit() {
        meoDeviceToggle.addEventListener('click', function(e) {
            var btn = e.target.closest('.meo-device-btn');
            if (!btn || btn.classList.contains('active')) return;
            meoDeviceToggle.querySelectorAll('.meo-device-btn').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentDevice = btn.dataset.device;
            meoFetchData(currentDevice, currentKeywordId);
            meoRenderHistory();
        });

        meoKeywordSelect.addEventListener('change', function() {
            currentKeywordId = parseInt(meoKeywordSelect.value, 10) || 0;
            meoFetchData(currentDevice, currentKeywordId);
            meoRenderHistory();
        });

        meoRadiusSelect.addEventListener('change', function() {
            currentRadius = parseInt(meoRadiusSelect.value, 10) || 0;
            meoFetchData(currentDevice, currentKeywordId);
        });

        meoFetchData('mobile', 0);
        meoFetchHistory();
    }

    // ----- Fetch Data -----
    function meoFetchData(device, keywordId) {
        meoShowLoading();

        var url = restBase + 'meo/rankings?device=' + encodeURIComponent(device)
                + '&keyword_id=' + encodeURIComponent(keywordId);

        if (isCoordinateMode && currentRadius > 0) {
            url += '&radius=' + encodeURIComponent(currentRadius);
        }

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (data.success) {
                meoRenderAll(data);
            } else if (data.keywords && data.keywords.length === 0) {
                meoShowEmpty();
            } else {
                meoShowError(data.message || 'データの取得に失敗しました');
            }
        })
        .catch(function(err) {
            console.error('[GCREV][MEO]', err);
            meoShowError('通信エラーが発生しました');
        });
    }

    // ----- Render All -----
    function meoRenderAll(data) {
        meoHideStates();

        var loc = data.location || {};
        isCoordinateMode = (loc.mode === 'coordinate');

        if (isCoordinateMode) {
            if (loc.source === 'city_center') {
                meoRegion.innerHTML = meoEsc(loc.address || '')
                    + ' <span style="font-size:11px;color:#999;font-weight:400;">(自動設定)</span>';
            } else {
                meoRegion.textContent = loc.address || (loc.lat + ', ' + loc.lng);
            }
        } else {
            var regionText = data.region || '';
            if (regionText && regionText !== '日本（広域）') {
                meoRegion.textContent = regionText + '周辺';
            } else {
                meoRegion.textContent = regionText || '未設定';
            }
        }

        if (isCoordinateMode && data.radius_options && data.radius_options.length > 0) {
            meoRenderRadiusOptions(data.radius_options, loc.radius || 3000);
            meoRadiusGroup.style.display = '';
        } else {
            meoRadiusGroup.style.display = 'none';
        }

        meoRenderKeywords(data.keywords || []);
        meoRenderMetrics(data);

        if (data.maps && data.maps.store) {
            meoRenderStore(data.maps.store);
            meoStoreCard.style.display = '';
            meoRenderReviews(data.maps.store);
            meoReviewsCard.style.display = '';
        } else {
            meoStoreCard.style.display = 'none';
            meoReviewsCard.style.display = 'none';
        }

        if (data.maps && data.maps.competitors && data.maps.competitors.length > 0) {
            meoRenderCompetitors(data.maps.competitors);
            meoCompetitorWrap.style.display = '';
        } else {
            meoCompetitorWrap.style.display = 'none';
        }
    }

    // ----- Render Keywords Selector -----
    function meoRenderKeywords(keywords) {
        if (!keywords || keywords.length === 0) {
            meoKeywordSingle.textContent = '未登録';
            meoKeywordSelect.style.display = 'none';
            meoKeywordSingle.style.display = '';
            return;
        }

        if (keywords.length === 1) {
            meoKeywordSingle.textContent = keywords[0].keyword;
            meoKeywordSelect.style.display = 'none';
            meoKeywordSingle.style.display = '';
            return;
        }

        var html = '';
        keywords.forEach(function(kw) {
            html += '<option value="' + kw.id + '"' + (kw.selected ? ' selected' : '') + '>'
                  + meoEsc(kw.keyword) + '</option>';
        });
        meoKeywordSelect.innerHTML = html;
        meoKeywordSelect.style.display = '';
        meoKeywordSingle.style.display = 'none';
    }

    // ----- Render Metrics Cards -----
    function meoRenderMetrics(data) {
        var maps = data.maps || {};
        var finder = data.local_finder || {};
        var store = maps.store || {};

        var cards = [
            {
                icon: '\uD83D\uDDFA\uFE0F',
                label: 'Googleマップ順位',
                sublabel: 'マップアプリでの表示順',
                value: maps.rank ? maps.rank + '<small>位</small>' : '<span class="meo-metric-value--out">圏外</span>',
                cls: 'meo-metric-card--teal'
            },
            {
                icon: '\uD83D\uDD0D',
                label: '検索結果の地域順位',
                sublabel: 'Google検索のローカル表示',
                value: finder.rank ? finder.rank + '<small>位</small>' : '<span class="meo-metric-value--out">圏外</span>',
                cls: 'meo-metric-card--blue'
            },
            {
                icon: '\u2B50',
                label: '口コミ評価',
                sublabel: 'Googleの平均評価',
                value: store.rating != null ? store.rating + '<small> / 5.0</small>' : '<small>-</small>',
                cls: 'meo-metric-card--gold'
            },
            {
                icon: '\uD83D\uDCAC',
                label: '口コミ件数',
                sublabel: 'Googleの口コミ総数',
                value: store.reviews_count != null ? store.reviews_count + '<small>件</small>' : '<small>-</small>',
                cls: 'meo-metric-card--green'
            }
        ];

        var html = '';
        cards.forEach(function(c) {
            html += '<div class="meo-metric-card ' + c.cls + '">'
                  + '<div class="meo-metric-icon">' + c.icon + '</div>'
                  + '<div class="meo-metric-label">' + c.label + '</div>'
                  + '<div class="meo-metric-sublabel">' + c.sublabel + '</div>'
                  + '<div class="meo-metric-value">' + c.value + '</div>'
                  + '</div>';
        });
        meoMetricsCards.innerHTML = html;
    }

    // ----- Render Store -----
    function meoRenderStore(store) {
        var rows = [];
        if (store.title)    rows.push(['店舗名', meoEsc(store.title)]);
        if (store.category) rows.push(['カテゴリ', meoEsc(store.category)]);
        if (store.address)  rows.push(['住所', meoEsc(store.address)]);
        if (store.phone)    rows.push(['電話番号', meoEsc(store.phone)]);
        if (store.work_hours) rows.push(['営業時間', meoEsc(store.work_hours)]);

        if (rows.length === 0) { meoStoreCard.style.display = 'none'; return; }

        var html = '<div class="meo-store-card__title">\uD83C\uDFEA 店舗情報</div>'
                 + '<div class="meo-store-grid">';
        rows.forEach(function(r) {
            html += '<div class="meo-store-label">' + r[0] + '</div>'
                  + '<div class="meo-store-value">' + r[1] + '</div>';
        });
        html += '</div>';

        if (store.maps_url) {
            html += '<a href="' + meoEsc(store.maps_url) + '" target="_blank" rel="noopener" class="meo-store-link">'
                  + 'Googleマップで見る \u2192</a>';
        }

        meoStoreCard.innerHTML = html;
    }

    // ----- Render Reviews -----
    function meoRenderReviews(store) {
        if (store.rating == null) { meoReviewsCard.style.display = 'none'; return; }

        var rating = parseFloat(store.rating) || 0;
        var total = store.reviews_count || 0;
        var dist = store.rating_distribution || {};

        var stars = '';
        for (var i = 1; i <= 5; i++) {
            stars += (i <= Math.round(rating)) ? '\u2605' : '\u2606';
        }

        var barsHtml = '';
        for (var s = 5; s >= 1; s--) {
            var cnt = parseInt(dist[s] || dist[String(s)] || 0, 10);
            var pct = total > 0 ? Math.round((cnt / total) * 100) : 0;
            barsHtml += '<div class="meo-rating-bar-row">'
                      + '<div class="meo-rating-bar-label">' + s + '\u2605</div>'
                      + '<div class="meo-rating-bar-track"><div class="meo-rating-bar-fill" style="width:' + pct + '%"></div></div>'
                      + '<div class="meo-rating-bar-count">' + cnt + '件</div>'
                      + '</div>';
        }

        var html = '<div class="meo-reviews-card__title">\uD83D\uDCAC 口コミの状況</div>'
                 + '<div class="meo-reviews-summary">'
                 + '<span class="meo-reviews-big-rating">' + rating.toFixed(1) + '</span>'
                 + '<span class="meo-reviews-stars">' + stars + '</span>'
                 + '<span class="meo-reviews-count">' + total + '件の口コミ</span>'
                 + '</div>'
                 + '<div class="meo-rating-bars">' + barsHtml + '</div>';

        meoReviewsCard.innerHTML = html;
    }

    // ----- Render Competitors -----
    function meoRenderCompetitors(competitors) {
        var html = '<div class="meo-competitor-title">\uD83C\uDFC6 近くの競合との比較</div>'
                 + '<div class="meo-table-scroll">'
                 + '<table class="meo-competitor-table">'
                 + '<thead><tr>'
                 + '<th>店舗名</th><th>マップ順位</th><th>評価</th><th>口コミ数</th>'
                 + '</tr></thead><tbody>';

        competitors.forEach(function(c) {
            var rowCls = c.is_self ? ' class="meo-self-row"' : '';
            var name = meoEsc(c.title || '');
            if (c.is_self) name += '<span class="meo-self-badge">自社</span>';

            var rank = c.rank ? c.rank + '位' : '圏外';
            var rating = c.rating != null
                ? '<span class="meo-stars-sm">' + meoStarsMini(c.rating) + '</span> ' + parseFloat(c.rating).toFixed(1)
                : '-';
            var reviews = c.reviews_count != null ? c.reviews_count + '件' : '-';

            html += '<tr' + rowCls + '>'
                  + '<td>' + name + '</td>'
                  + '<td>' + rank + '</td>'
                  + '<td>' + rating + '</td>'
                  + '<td>' + reviews + '</td>'
                  + '</tr>';
        });

        html += '</tbody></table></div>';
        meoCompetitorWrap.innerHTML = html;
    }

    // ----- Stars mini -----
    function meoStarsMini(val) {
        var r = Math.round(parseFloat(val) || 0);
        var s = '';
        for (var i = 1; i <= 5; i++) s += (i <= r) ? '\u2605' : '\u2606';
        return s;
    }

    // ----- Render Radius Options -----
    function meoRenderRadiusOptions(options, selectedRadius) {
        var html = '';
        options.forEach(function(opt) {
            var sel = (opt.value === selectedRadius) ? ' selected' : '';
            html += '<option value="' + opt.value + '"' + sel + '>'
                  + meoEsc(opt.label) + '</option>';
        });
        meoRadiusSelect.innerHTML = html;
        currentRadius = selectedRadius;
    }

    // ----- State helpers -----
    function meoShowLoading() {
        meoLoading.style.display = '';
        meoEmpty.style.display = 'none';
        meoError.style.display = 'none';
        meoMetricsCards.innerHTML = '';
        meoStoreCard.style.display = 'none';
        meoReviewsCard.style.display = 'none';
        meoCompetitorWrap.style.display = 'none';
    }
    function meoShowEmpty() {
        meoLoading.style.display = 'none';
        meoEmpty.style.display = '';
        meoError.style.display = 'none';
    }
    function meoShowError(msg) {
        meoLoading.style.display = 'none';
        meoEmpty.style.display = 'none';
        meoError.style.display = '';
        meoError.innerHTML = msg + '<br><button class="meo-retry-btn" onclick="document.getElementById(\'meoError\').style.display=\'none\';">閉じる</button>';
    }
    function meoHideStates() {
        meoLoading.style.display = 'none';
        meoEmpty.style.display = 'none';
        meoError.style.display = 'none';
    }

    // ----- Escape helper -----
    function meoEsc(str) {
        if (!str) return '';
        var el = document.createElement('span');
        el.textContent = str;
        return el.innerHTML;
    }

    // ----- MEO History (週次推移) -----
    var meoHistoryData = null;

    function meoFetchHistory() {
        fetch(restBase + 'meo/history', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (data.success && data.data) {
                meoHistoryData = data.data;
                meoRenderHistory();
            }
        })
        .catch(function(err) {
            console.error('[GCREV][MEO History]', err);
        });
    }

    function meoRenderHistory() {
        var wrap = document.getElementById('meoHistoryWrap');
        var thead = document.getElementById('meoHistoryHead');
        var tbody = document.getElementById('meoHistoryBody');

        if (!meoHistoryData || !meoHistoryData.keywords || meoHistoryData.keywords.length === 0) {
            wrap.style.display = 'none';
            return;
        }

        var weeks = meoHistoryData.weeks || [];
        var labels = meoHistoryData.week_labels || [];

        if (weeks.length === 0) {
            wrap.style.display = 'none';
            return;
        }

        // 選択中のキーワードに一致するデータを探す（見つからなければ先頭）
        var kwData = meoHistoryData.keywords[0];
        if (currentKeywordId > 0) {
            for (var k = 0; k < meoHistoryData.keywords.length; k++) {
                if (meoHistoryData.keywords[k].keyword_id === currentKeywordId) {
                    kwData = meoHistoryData.keywords[k];
                    break;
                }
            }
        }
        var weekly = kwData.weekly ? kwData.weekly[currentDevice] : {};

        if (!weekly || Object.keys(weekly).length === 0) {
            wrap.style.display = '';
            thead.innerHTML = '';
            tbody.innerHTML = '<tr><td colspan="' + (weeks.length + 1) + '" style="text-align:center;color:#9ca3af;padding:24px;">'
                + '&#x1F4CA; データを蓄積中です。毎週月曜日に自動計測されます。</td></tr>';
            return;
        }

        wrap.style.display = '';

        var hHtml = '<tr><th style="min-width:140px;">指標</th>';
        for (var i = 0; i < labels.length; i++) {
            hHtml += '<th style="text-align:center;min-width:60px;">' + labels[i] + '</th>';
        }
        hHtml += '</tr>';
        thead.innerHTML = hHtml;

        var metrics = [
            { key: 'maps_rank', label: '\uD83D\uDDFA\uFE0F マップ順位', unit: '位', lower_is_better: true },
            { key: 'finder_rank', label: '\uD83D\uDD0D 地域順位', unit: '位', lower_is_better: true },
            { key: 'rating', label: '\u2B50 口コミ評価', unit: '', lower_is_better: false },
            { key: 'reviews', label: '\uD83D\uDCAC 口コミ件数', unit: '件', lower_is_better: false }
        ];

        var bHtml = '';
        for (var m = 0; m < metrics.length; m++) {
            var met = metrics[m];
            bHtml += '<tr>';
            bHtml += '<td style="font-weight:500;white-space:nowrap;">' + met.label + '</td>';

            var prevVal = null;
            for (var w = 0; w < weeks.length; w++) {
                var wData = weekly[weeks[w]];
                var val = wData ? wData[met.key] : null;

                var display = '-';
                var trendHtml = '';

                if (val !== null && val !== undefined) {
                    if (met.key === 'rating') {
                        display = parseFloat(val).toFixed(1);
                    } else {
                        display = val + '<small>' + met.unit + '</small>';
                    }

                    if (prevVal !== null && prevVal !== undefined) {
                        var diff = val - prevVal;
                        if (diff !== 0) {
                            var isGood = met.lower_is_better ? (diff < 0) : (diff > 0);
                            if (isGood) {
                                trendHtml = ' <span class="meo-trend-up">\u2191</span>';
                            } else {
                                trendHtml = ' <span class="meo-trend-down">\u2193</span>';
                            }
                        } else {
                            trendHtml = ' <span class="meo-trend-same">\u2192</span>';
                        }
                    }
                }

                bHtml += '<td style="text-align:center;">' + display + trendHtml + '</td>';
                if (val !== null && val !== undefined) {
                    prevVal = val;
                }
            }
            bHtml += '</tr>';
        }

        tbody.innerHTML = bHtml;
    }

    // ----- Go -----
    meoInit();
})();
</script>

<?php get_footer(); ?>
