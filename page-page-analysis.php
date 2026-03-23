<?php
/*
Template Name: ページ分析（Page Analysis）
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

// ページタイトル設定
set_query_var( 'gcrev_page_title', 'ページ分析' );
set_query_var( 'gcrev_page_subtitle', '主要ページのキャプチャ・行動データ・AI所見をまとめて管理できます。' );

// パンくず設定
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'ページ分析', 'ホームページ' ) );

get_header();
?>

<style>
/* page-page-analysis — Page-specific styles */
.pa-action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.pa-add-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #2d9cdb;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    transition: background 0.2s;
}
.pa-add-btn:hover { background: #2188c4; }
.pa-count { color: #666; font-size: 14px; }

/* サムネイル */
.pa-thumb {
    width: 60px;
    height: 40px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #e0e0e0;
    cursor: pointer;
}
.pa-thumb-placeholder {
    width: 60px;
    height: 40px;
    border-radius: 4px;
    border: 1px dashed #ccc;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #aaa;
    font-size: 11px;
    background: #fafafa;
}

/* バッジ */
.pa-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 500;
}
.pa-badge--done { background: #e6f7ed; color: #1a9b4a; }
.pa-badge--pending { background: #f0f0f0; color: #999; }
.pa-badge--type { background: #e8f0fe; color: #1967d2; }

/* 操作ボタン */
.pa-btn-sm {
    padding: 4px 10px;
    font-size: 12px;
    border-radius: 4px;
    border: 1px solid #ddd;
    background: #fff;
    cursor: pointer;
    transition: background 0.2s;
}
.pa-btn-sm:hover { background: #f5f5f5; }
.pa-btn-sm--danger { color: #e74c3c; border-color: #f5c6cb; }
.pa-btn-sm--danger:hover { background: #fef0f0; }

/* モーダル */
.pa-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}
.pa-modal-overlay.is-open { display: flex; }
.pa-modal {
    background: #fff;
    border-radius: 12px;
    padding: 28px;
    width: 480px;
    max-width: 90vw;
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
}
.pa-modal h3 {
    margin: 0 0 20px;
    font-size: 18px;
    font-weight: 600;
}
.pa-form-group {
    margin-bottom: 16px;
}
.pa-form-group label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 6px;
    color: #333;
}
.pa-form-group input,
.pa-form-group select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
}
.pa-form-group input:focus,
.pa-form-group select:focus {
    outline: none;
    border-color: #2d9cdb;
    box-shadow: 0 0 0 2px rgba(45,156,219,0.15);
}
.pa-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 20px;
}
.pa-modal-btn {
    padding: 8px 20px;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    border: 1px solid #ddd;
    background: #fff;
    transition: background 0.2s;
}
.pa-modal-btn:hover { background: #f5f5f5; }
.pa-modal-btn--primary {
    background: #2d9cdb;
    color: #fff;
    border-color: #2d9cdb;
}
.pa-modal-btn--primary:hover { background: #2188c4; }
.pa-modal-btn:disabled { opacity: 0.6; cursor: not-allowed; }

/* 詳細パネル */
.pa-detail-overlay {
    display: none;
    position: fixed;
    top: 0; right: 0; bottom: 0;
    width: 600px;
    max-width: 90vw;
    background: #fff;
    box-shadow: -4px 0 20px rgba(0,0,0,0.1);
    z-index: 1001;
    flex-direction: column;
    overflow: hidden;
}
.pa-detail-overlay.is-open { display: flex; }
.pa-detail-backdrop {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.3);
    z-index: 1000;
}
.pa-detail-backdrop.is-open { display: block; }
.pa-detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 28px 24px 20px;
    margin-top: 76px;
}
.pa-detail-header h3 {
    margin: 0;
    font-size: 17px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 80%;
}
.pa-detail-close {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #999;
    padding: 4px;
}
.pa-detail-close:hover { color: #333; }

/* タブ */
.pa-tabs {
    display: flex;
    border-bottom: 1px solid #eee;
    padding: 0 24px;
    margin-top: 16px;
}
.pa-tab {
    padding: 12px 16px;
    font-size: 14px;
    color: #666;
    cursor: pointer;
    border: none;
    background: none;
    border-bottom: 2px solid transparent;
    transition: color 0.2s, border-color 0.2s;
}
.pa-tab:hover { color: #333; }
.pa-tab.is-active {
    color: #2d9cdb;
    border-bottom-color: #2d9cdb;
    font-weight: 500;
}
.pa-tab-content {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
}
.pa-tab-pane { display: none; }
.pa-tab-pane.is-active { display: block; }

/* 概要セクション */
.pa-info-row {
    display: flex;
    padding: 10px 0;
    border-bottom: 1px solid #f5f5f5;
    font-size: 14px;
}
.pa-info-label {
    width: 120px;
    color: #888;
    flex-shrink: 0;
}
.pa-info-value {
    flex: 1;
    color: #333;
}
.pa-info-value a { color: #2d9cdb; text-decoration: none; }
.pa-info-value a:hover { text-decoration: underline; }

/* キャプチャセクション */
.pa-capture-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.pa-capture-box {
    border: 1px solid #eee;
    border-radius: 8px;
    padding: 16px;
}
.pa-capture-box h4 {
    margin: 0 0 12px;
    font-size: 14px;
    font-weight: 500;
    color: #555;
}
.pa-capture-img {
    width: 100%;
    border-radius: 6px;
    border: 1px solid #e0e0e0;
    margin-bottom: 12px;
    cursor: pointer;
    transition: opacity 0.2s;
}
.pa-capture-img:hover { opacity: 0.85; }
/* 原寸プレビューモーダル */
.pa-lightbox {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 100000;
    background: rgba(0,0,0,0.8);
    align-items: flex-start;
    justify-content: center;
    overflow-y: auto;
    padding: 40px 20px;
}
.pa-lightbox.is-open { display: flex; }
.pa-lightbox img {
    max-width: 90%;
    max-height: none;
    border-radius: 8px;
    box-shadow: 0 4px 30px rgba(0,0,0,0.5);
}
.pa-lightbox-close {
    position: fixed;
    top: 16px;
    right: 24px;
    color: #fff;
    font-size: 32px;
    cursor: pointer;
    z-index: 100001;
    background: none;
    border: none;
    line-height: 1;
}
.pa-capture-empty {
    width: 100%;
    height: 200px;
    border: 2px dashed #ddd;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #aaa;
    font-size: 13px;
    margin-bottom: 12px;
    background: #fafafa;
}
.pa-upload-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    font-size: 12px;
    border: 1px solid #2d9cdb;
    color: #2d9cdb;
    background: #fff;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s;
}
.pa-upload-btn:hover { background: #f0f8ff; }
.pa-delete-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 6px 12px;
    font-size: 12px;
    border: 1px solid #dc2626;
    color: #dc2626;
    background: #fff;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s;
    margin-left: 8px;
}
.pa-delete-btn:hover { background: #fef2f2; }

/* プレースホルダー */
.pa-placeholder {
    text-align: center;
    padding: 60px 20px;
    color: #aaa;
}
.pa-placeholder-icon { font-size: 40px; margin-bottom: 12px; }
.pa-placeholder-text { font-size: 14px; }

/* 空状態 */
.pa-empty {
    text-align: center;
    padding: 80px 20px;
    color: #999;
}
.pa-empty-icon { font-size: 48px; margin-bottom: 16px; }
.pa-empty-text { font-size: 16px; margin-bottom: 8px; }
.pa-empty-sub { font-size: 13px; color: #bbb; }

/* テーブル上書き */
.pa-table .data-table td { vertical-align: middle; }
.pa-table .data-table th { white-space: nowrap; }

/* ===== ヒートマップ解析ビュー ===== */
.hm-controls {
    padding: 0 0 14px;
    border-bottom: 1px solid #eee;
    margin-bottom: 14px;
}
.hm-control-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.hm-control-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 120px;
}
.hm-control-item label {
    font-size: 11px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.hm-control-item select {
    padding: 6px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    background: #fff;
    cursor: pointer;
    transition: border-color .2s;
}
.hm-control-item select:focus {
    outline: none;
    border-color: #2d9cdb;
    box-shadow: 0 0 0 3px rgba(45,156,219,.12);
}
/* ビューア */
.hm-viewer {
    position: relative;
    margin-bottom: 16px;
}
.hm-canvas-wrap {
    position: relative;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.hm-canvas-wrap img {
    display: block;
    width: 100%;
    height: auto;
    position: relative;
    z-index: 1;
}
.hm-canvas-wrap canvas {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 2;
    pointer-events: none;
}
.hm-empty {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
    font-size: 13px;
    line-height: 1.8;
}
.hm-empty .pa-placeholder-icon { font-size: 36px; margin-bottom: 8px; }
/* サマリー */
.hm-summary {
    padding: 14px 0 0;
    border-top: 1px solid #eee;
}
.hm-summary-title {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 10px;
}
.hm-summary-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}
.hm-stat {
    padding: 10px 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
}
.hm-stat-label {
    font-size: 11px;
    color: #64748b;
    margin-bottom: 2px;
}
.hm-stat-value {
    font-size: 18px;
    font-weight: 700;
    color: #1e293b;
}
.hm-stat-sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 2px;
}
.hm-no-data {
    text-align: center;
    padding: 16px;
    color: #94a3b8;
    font-size: 13px;
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

    <!-- アクションバー -->
    <div class="pa-action-bar">
        <span class="pa-count" id="paCount"></span>
        <button type="button" class="pa-add-btn" id="paAddBtn">+ ページを追加</button>
    </div>

    <!-- 一覧テーブル -->
    <div class="table-section pa-table">
        <div id="paTableArea">
            <!-- 空状態（初期表示） -->
            <div class="pa-empty" id="paEmpty" style="display:none;">
                <div class="pa-empty-icon">&#128196;</div>
                <div class="pa-empty-text">まだページが登録されていません</div>
                <div class="pa-empty-sub">「+ ページを追加」からサイトの主要ページを登録してください</div>
            </div>

            <!-- テーブル -->
            <table class="data-table" id="paTable" style="display:none;">
                <thead>
                    <tr>
                        <th>ページ名</th>
                        <th>種別</th>
                        <th>PC</th>
                        <th>SP</th>
                        <th>Clarity</th>
                        <th>AI分析</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="paTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- 追加モーダル -->
<div class="pa-modal-overlay" id="paAddModal">
    <div class="pa-modal">
        <h3>ページを追加</h3>
        <div class="pa-form-group">
            <label for="paUrl">ページURL</label>
            <input type="url" id="paUrl" placeholder="https://example.com/service/">
        </div>
        <div class="pa-form-group">
            <label for="paTitle">ページ名（空欄なら自動取得）</label>
            <input type="text" id="paTitle" placeholder="サービス紹介">
        </div>
        <div class="pa-form-group">
            <label for="paType">ページ種別</label>
            <select id="paType">
                <option value="top">トップページ</option>
                <option value="service">サービス紹介</option>
                <option value="lp">LP（ランディングページ）</option>
                <option value="contact">お問い合わせ</option>
                <option value="blog">ブログ・コラム</option>
                <option value="company">会社概要</option>
                <option value="access">アクセス</option>
                <option value="staff">スタッフ紹介</option>
                <option value="price">料金</option>
                <option value="other" selected>その他</option>
            </select>
        </div>
        <div class="pa-modal-actions">
            <button type="button" class="pa-modal-btn" id="paAddCancel">キャンセル</button>
            <button type="button" class="pa-modal-btn pa-modal-btn--primary" id="paAddSubmit">追加する</button>
        </div>
    </div>
</div>

<!-- 詳細パネル -->
<div class="pa-detail-backdrop" id="paDetailBackdrop"></div>
<div class="pa-detail-overlay" id="paDetailPanel">
    <div class="pa-detail-header">
        <h3 id="paDetailTitle">-</h3>
        <button type="button" class="pa-detail-close" id="paDetailClose">&times;</button>
    </div>
    <div class="pa-tabs">
        <button type="button" class="pa-tab is-active" data-tab="overview">概要</button>
        <button type="button" class="pa-tab" data-tab="capture">キャプチャ</button>
        <button type="button" class="pa-tab" data-tab="behavior">ヒートマップ</button>
        <button type="button" class="pa-tab" data-tab="ai">AI所見</button>
    </div>
    <div class="pa-tab-content">
        <!-- 概要タブ -->
        <div class="pa-tab-pane is-active" data-pane="overview">
            <div id="paOverviewContent"></div>
        </div>
        <!-- キャプチャタブ -->
        <div class="pa-tab-pane" data-pane="capture">
            <div class="pa-capture-grid" id="paCaptureContent"></div>
        </div>
        <!-- ヒートマップ解析タブ -->
        <div class="pa-tab-pane" data-pane="behavior">
            <!-- コントロール -->
            <div class="hm-controls">
                <div class="hm-control-row">
                    <div class="hm-control-item">
                        <label>デバイス</label>
                        <select id="hmDevice">
                            <option value="pc">PC版</option>
                            <option value="mobile">スマホ版</option>
                        </select>
                    </div>
                    <div class="hm-control-item">
                        <label>表示</label>
                        <select id="hmMetric">
                            <option value="scroll">スクロール深度</option>
                            <option value="click">クリック分布</option>
                            <option value="dead_click">Dead Click</option>
                            <option value="rage_click">Rage Click</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 画像 + オーバーレイ表示 -->
            <div class="hm-viewer" id="hmViewer">
                <div class="hm-canvas-wrap" id="hmCanvasWrap">
                    <img id="hmImage" src="" alt="" style="display:none;">
                    <canvas id="hmCanvas"></canvas>
                    <div class="hm-empty" id="hmEmpty">
                        <div class="pa-placeholder-icon">&#128444;</div>
                        <div>キャプチャ画像をアップロードすると<br>ヒートマップ表示が利用できます</div>
                    </div>
                </div>
            </div>

            <!-- 数値サマリー -->
            <div class="hm-summary" id="hmSummary">
                <div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:4px;margin-bottom:10px;">
                    <h4 class="hm-summary-title" style="margin:0;">行動データサマリー</h4>
                    <span id="hmDataPeriod" style="font-size:11px;color:#94a3b8;"></span>
                </div>
                <div class="hm-summary-grid" id="hmSummaryGrid">
                    <!-- JS で動的描画 -->
                </div>
            </div>
        </div>
        <!-- AI所見タブ -->
        <div class="pa-tab-pane" data-pane="ai">
            <div class="pa-placeholder">
                <div class="pa-placeholder-icon">&#129302;</div>
                <div class="pa-placeholder-text">AI分析は準備中です<br>キャプチャと行動データをもとに、改善提案を自動生成します</div>
            </div>
        </div>
    </div>
</div>

<!-- 非表示ファイル入力 -->
<input type="file" id="paFileInput" accept="image/jpeg,image/png,image/webp" style="display:none;">

<!-- 原寸プレビュー -->
<div class="pa-lightbox" id="paLightbox">
    <button type="button" class="pa-lightbox-close" id="paLightboxClose">&times;</button>
    <img src="" alt="プレビュー" id="paLightboxImg">
</div>

<script>
(function() {
    'use strict';

    var API_BASE = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gcrev/v1/page-analysis/pages' ) ) ); ?>;
    var NONCE    = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

    var PAGE_TYPES = {
        top: 'トップページ', service: 'サービス', lp: 'LP', contact: 'お問い合わせ',
        blog: 'ブログ', company: '会社概要', access: 'アクセス', staff: 'スタッフ',
        price: '料金', other: 'その他'
    };

    // --- DOM ---
    var els = {
        loading:       document.getElementById('loadingOverlay'),
        count:         document.getElementById('paCount'),
        empty:         document.getElementById('paEmpty'),
        table:         document.getElementById('paTable'),
        tbody:         document.getElementById('paTableBody'),
        addBtn:        document.getElementById('paAddBtn'),
        addModal:      document.getElementById('paAddModal'),
        addCancel:     document.getElementById('paAddCancel'),
        addSubmit:     document.getElementById('paAddSubmit'),
        urlInput:      document.getElementById('paUrl'),
        titleInput:    document.getElementById('paTitle'),
        typeSelect:    document.getElementById('paType'),
        detailPanel:   document.getElementById('paDetailPanel'),
        detailBackdrop:document.getElementById('paDetailBackdrop'),
        detailTitle:   document.getElementById('paDetailTitle'),
        detailClose:   document.getElementById('paDetailClose'),
        overviewContent: document.getElementById('paOverviewContent'),
        captureContent:  document.getElementById('paCaptureContent'),
        fileInput:     document.getElementById('paFileInput'),
    };

    var currentDetailId = null;
    var uploadTarget = null; // { id, device }

    // --- 原寸プレビュー（ライトボックス） ---
    var lightbox = document.getElementById('paLightbox');
    var lightboxImg = document.getElementById('paLightboxImg');
    window._paLightbox = function(url) {
        lightboxImg.src = url;
        lightbox.classList.add('is-open');
    };
    document.getElementById('paLightboxClose').addEventListener('click', function() {
        lightbox.classList.remove('is-open');
        lightboxImg.src = '';
    });
    lightbox.addEventListener('click', function(e) {
        if (e.target === lightbox) {
            lightbox.classList.remove('is-open');
            lightboxImg.src = '';
        }
    });

    // --- ユーティリティ ---
    function escHtml(s) {
        if (!s) return '';
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function showLoading() { els.loading.style.display = 'flex'; }
    function hideLoading() { els.loading.style.display = 'none'; }

    function apiFetch(url, options) {
        var opts = options || {};
        var headers = opts.headers || {};
        headers['X-WP-Nonce'] = NONCE;
        if (!opts.isUpload) {
            headers['Content-Type'] = 'application/json';
        }
        return fetch(url, {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: headers,
            body: opts.body || null,
        }).then(function(r) { return r.json(); });
    }

    // --- 一覧読み込み ---
    function loadPages() {
        showLoading();
        apiFetch(API_BASE).then(function(res) {
            hideLoading();
            if (!res.success) return;
            var pages = res.data.pages || [];
            renderList(pages);
        }).catch(function() {
            hideLoading();
        });
    }

    function renderList(pages) {
        els.count.textContent = pages.length + ' ページ登録中';
        if (pages.length === 0) {
            els.empty.style.display = 'block';
            els.table.style.display = 'none';
            return;
        }
        els.empty.style.display = 'none';
        els.table.style.display = 'table';

        var html = '';
        for (var i = 0; i < pages.length; i++) {
            var p = pages[i];
            var typeName = PAGE_TYPES[p.page_type] || p.page_type;
            var pcThumb = p.screenshot_pc_url
                ? '<img src="' + escHtml(p.screenshot_pc_url) + '" class="pa-thumb" onclick="window._paShowDetail(' + p.id + ')" alt="PC">'
                : '<div class="pa-thumb-placeholder">未取得</div>';
            var spThumb = p.screenshot_mobile_url
                ? '<img src="' + escHtml(p.screenshot_mobile_url) + '" class="pa-thumb" onclick="window._paShowDetail(' + p.id + ')" alt="SP">'
                : '<div class="pa-thumb-placeholder">未取得</div>';
            var clarityBadge = p.clarity_data
                ? '<span class="pa-badge pa-badge--done">取得済み</span>'
                : '<span class="pa-badge pa-badge--pending">未連携</span>';
            var aiBadge = p.ai_summary
                ? '<span class="pa-badge pa-badge--done">分析済み</span>'
                : '<span class="pa-badge pa-badge--pending">未分析</span>';

            html += '<tr>'
                + '<td><div style="font-weight:500;">' + escHtml(p.page_title || '（タイトル未取得）') + '</div>'
                + '<div style="font-size:12px;color:#999;margin-top:2px;">' + escHtml(p.page_url) + '</div></td>'
                + '<td><span class="pa-badge pa-badge--type">' + escHtml(typeName) + '</span></td>'
                + '<td>' + pcThumb + '</td>'
                + '<td>' + spThumb + '</td>'
                + '<td>' + clarityBadge + '</td>'
                + '<td>' + aiBadge + '</td>'
                + '<td>'
                + '<button class="pa-btn-sm" onclick="window._paShowDetail(' + p.id + ')">詳細</button> '
                + '<button class="pa-btn-sm pa-btn-sm--danger" onclick="window._paDeletePage(' + p.id + ')">削除</button>'
                + '</td></tr>';
        }
        els.tbody.innerHTML = html;
    }

    // --- ページ追加 ---
    els.addBtn.addEventListener('click', function() {
        els.urlInput.value = '';
        els.titleInput.value = '';
        els.typeSelect.value = 'other';
        els.addModal.classList.add('is-open');
        els.urlInput.focus();
    });
    els.addCancel.addEventListener('click', function() {
        els.addModal.classList.remove('is-open');
    });
    els.addModal.addEventListener('click', function(e) {
        if (e.target === els.addModal) els.addModal.classList.remove('is-open');
    });

    els.addSubmit.addEventListener('click', function() {
        var url = els.urlInput.value.trim();
        if (!url) { els.urlInput.focus(); return; }
        els.addSubmit.disabled = true;
        els.addSubmit.textContent = '追加中...';
        apiFetch(API_BASE, {
            method: 'POST',
            body: JSON.stringify({
                page_url: url,
                page_title: els.titleInput.value.trim(),
                page_type: els.typeSelect.value,
            }),
        }).then(function(res) {
            els.addSubmit.disabled = false;
            els.addSubmit.textContent = '追加する';
            if (res.success) {
                els.addModal.classList.remove('is-open');
                loadPages();
            } else {
                alert(res.message || 'エラーが発生しました');
            }
        }).catch(function() {
            els.addSubmit.disabled = false;
            els.addSubmit.textContent = '追加する';
            alert('通信エラーが発生しました');
        });
    });

    // --- ページ削除 ---
    window._paDeletePage = function(id) {
        if (!confirm('このページを削除しますか？')) return;
        apiFetch(API_BASE + '/' + id, { method: 'DELETE' }).then(function(res) {
            if (res.success) loadPages();
        });
    };

    // --- 詳細パネル ---
    function openDetail() {
        els.detailPanel.classList.add('is-open');
        els.detailBackdrop.classList.add('is-open');
        // モーダル操作を妨げないようAI相談FABを非表示
        var chatFab = document.querySelector('.mw-chat-fab');
        if (chatFab) chatFab.style.display = 'none';
    }
    function closeDetail() {
        els.detailPanel.classList.remove('is-open');
        els.detailBackdrop.classList.remove('is-open');
        currentDetailId = null;
        // AI相談FABを復元
        var chatFab = document.querySelector('.mw-chat-fab');
        if (chatFab) chatFab.style.display = '';
    }
    els.detailClose.addEventListener('click', closeDetail);
    els.detailBackdrop.addEventListener('click', closeDetail);

    window._paShowDetail = function(id) {
        currentDetailId = id;
        els.detailTitle.textContent = '読み込み中...';
        openDetail();
        // タブリセット
        var tabs = els.detailPanel.querySelectorAll('.pa-tab');
        var panes = els.detailPanel.querySelectorAll('.pa-tab-pane');
        for (var i = 0; i < tabs.length; i++) { tabs[i].classList.remove('is-active'); }
        for (var i = 0; i < panes.length; i++) { panes[i].classList.remove('is-active'); }
        tabs[0].classList.add('is-active');
        panes[0].classList.add('is-active');

        apiFetch(API_BASE + '/' + id + '/detail').then(function(res) {
            if (!res.success) { closeDetail(); return; }
            renderDetail(res.data);
        });
    };

    function renderDetail(data) {
        els.detailTitle.textContent = data.page_title || '（タイトル未取得）';

        // 概要タブ
        var typeName = PAGE_TYPES[data.page_type] || data.page_type;
        els.overviewContent.innerHTML = ''
            + '<div class="pa-info-row"><span class="pa-info-label">URL</span><span class="pa-info-value"><a href="' + escHtml(data.page_url) + '" target="_blank" rel="noopener">' + escHtml(data.page_url) + '</a></span></div>'
            + '<div class="pa-info-row"><span class="pa-info-label">種別</span><span class="pa-info-value"><span class="pa-badge pa-badge--type">' + escHtml(typeName) + '</span></span></div>'
            + '<div class="pa-info-row"><span class="pa-info-label">登録日</span><span class="pa-info-value">' + escHtml(data.created_at || '-') + '</span></div>'
            + '<div class="pa-info-row"><span class="pa-info-label">最新キャプチャ</span><span class="pa-info-value">' + escHtml(data.capture_date || '未取得') + '</span></div>'
            + '<div class="pa-info-row"><span class="pa-info-label">Clarity同期</span><span class="pa-info-value">' + escHtml(data.clarity_sync_date || '未連携') + '</span></div>'
            + '<div class="pa-info-row"><span class="pa-info-label">AI分析</span><span class="pa-info-value">' + escHtml(data.ai_analysis_date || '未分析') + '</span></div>';

        // AI所見があれば表示
        if (data.ai_summary) {
            var aiPane = els.detailPanel.querySelector('[data-pane="ai"]');
            aiPane.innerHTML = '<div style="padding:8px 0;line-height:1.8;font-size:14px;">' + escHtml(data.ai_summary).replace(/\n/g, '<br>') + '</div>';
        }

        // キャプチャタブ（サムネイル表示 + クリックで原寸）
        // キャプチャタブもオリジナル高解像度画像を使用
        var pcThumb = data.screenshot_pc_original || data.screenshot_pc_url;
        var spThumb = data.screenshot_mobile_original || data.screenshot_mobile_url;
        var pcImg = data.screenshot_pc_url
            ? '<img src="' + escHtml(pcThumb) + '" class="pa-capture-img" alt="PC版" onclick="window._paLightbox(\'' + escHtml(data.screenshot_pc_url) + '\')">'
            : '<div class="pa-capture-empty">キャプチャ未取得</div>';
        var spImg = data.screenshot_mobile_url
            ? '<img src="' + escHtml(spThumb) + '" class="pa-capture-img" alt="SP版" onclick="window._paLightbox(\'' + escHtml(data.screenshot_mobile_url) + '\')">'
            : '<div class="pa-capture-empty">キャプチャ未取得</div>';

        var pcDel = data.screenshot_pc_url
            ? '<button type="button" class="pa-delete-btn" onclick="window._paDeleteCapture(' + data.id + ', \'pc\')">削除</button>'
            : '';
        var spDel = data.screenshot_mobile_url
            ? '<button type="button" class="pa-delete-btn" onclick="window._paDeleteCapture(' + data.id + ', \'mobile\')">削除</button>'
            : '';

        els.captureContent.innerHTML = ''
            + '<div class="pa-capture-box"><h4>PC版</h4>' + pcImg
            + '<button type="button" class="pa-upload-btn" onclick="window._paUpload(' + data.id + ', \'pc\')">画像をアップロード</button>' + pcDel + '</div>'
            + '<div class="pa-capture-box"><h4>スマホ版</h4>' + spImg
            + '<button type="button" class="pa-upload-btn" onclick="window._paUpload(' + data.id + ', \'mobile\')">画像をアップロード</button>' + spDel + '</div>';

        // ===== ヒートマップ解析タブ =====
        window._hmCurrentData = data;
        renderHeatmapTab(data);
    }

    // ===== ヒートマップ解析 =====
    var hmDeviceSelect = document.getElementById('hmDevice');
    var hmMetricSelect = document.getElementById('hmMetric');
    var hmImage        = document.getElementById('hmImage');
    var hmCanvas       = document.getElementById('hmCanvas');
    var hmEmpty        = document.getElementById('hmEmpty');
    var hmSummaryGrid  = document.getElementById('hmSummaryGrid');

    if (hmDeviceSelect) {
        hmDeviceSelect.addEventListener('change', function() { renderHeatmapView(); });
    }
    if (hmMetricSelect) {
        hmMetricSelect.addEventListener('change', function() { renderHeatmapView(); });
    }

    function renderHeatmapTab(data) {
        // デバイスセレクト初期値
        if (hmDeviceSelect) {
            hmDeviceSelect.value = data.screenshot_pc_url ? 'pc' : (data.screenshot_mobile_url ? 'mobile' : 'pc');
        }
        renderHeatmapView();
    }

    function renderHeatmapView() {
        var data = window._hmCurrentData;
        if (!data) return;

        var device = hmDeviceSelect ? hmDeviceSelect.value : 'pc';
        var metric = hmMetricSelect ? hmMetricSelect.value : 'scroll';

        // 画像URL取得（ヒートマップではオリジナル高解像度画像を使用）
        var imgUrl = device === 'mobile'
            ? (data.screenshot_mobile_original || data.screenshot_mobile_url)
            : (data.screenshot_pc_original || data.screenshot_pc_url);

        if (!imgUrl) {
            hmImage.style.display = 'none';
            hmCanvas.style.display = 'none';
            hmEmpty.style.display = 'block';
            hmSummaryGrid.innerHTML = '<div class="hm-no-data">キャプチャ画像をアップロードしてください</div>';
            return;
        }

        hmEmpty.style.display = 'none';
        hmImage.style.display = 'block';
        hmCanvas.style.display = 'block';

        function doDrawOverlay() {
            var dpr = window.devicePixelRatio || 1;
            var imgW = hmImage.offsetWidth;
            var imgH = hmImage.offsetHeight;
            if (imgW < 10 || imgH < 10) return; // まだレイアウト未完了

            hmCanvas.width  = imgW * dpr;
            hmCanvas.height = imgH * dpr;
            hmCanvas.style.width  = imgW + 'px';
            hmCanvas.style.height = imgH + 'px';

            var ctx = hmCanvas.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            drawOverlay(metric, device, data, imgW, imgH);
        }

        // 画像読み込みエラー時のフォールバック
        var fallbackUrl = device === 'mobile'
            ? (data.screenshot_mobile_url || data.screenshot_mobile_original)
            : (data.screenshot_pc_url || data.screenshot_pc_original);

        hmImage.onerror = function() {
            // メインURLが失敗した場合、フォールバックURLを試す
            if (fallbackUrl && hmImage.src !== fallbackUrl) {
                console.warn('[Heatmap] Image load failed, trying fallback:', fallbackUrl);
                hmImage.src = fallbackUrl;
                return;
            }
            // フォールバックも失敗した場合はエラー表示
            console.error('[Heatmap] Image load failed:', imgUrl);
            hmImage.style.display = 'none';
            hmCanvas.style.display = 'none';
            hmEmpty.style.display = 'block';
            hmEmpty.querySelector('div:last-child').innerHTML =
                '画像の読み込みに失敗しました<br><small style="color:#94a3b8;">キャプチャタブから再アップロードしてください</small>';
        };

        // キャッシュ済み画像にも対応
        if (hmImage.src === imgUrl && hmImage.complete && hmImage.naturalHeight > 0) {
            doDrawOverlay();
        } else {
            hmImage.onload = doDrawOverlay;
            hmImage.src = imgUrl;
        }
        // フォールバック: 少し遅れて再描画（レイアウト完了待ち）
        setTimeout(doDrawOverlay, 300);

        // サマリー描画
        renderHeatmapSummary(data, device);
    }

    function drawOverlay(metric, device, data, cssW, cssH) {
        var ctx = hmCanvas.getContext('2d');
        var w = cssW || hmCanvas.width;
        var h = cssH || hmCanvas.height;

        // 描画クリア
        ctx.clearRect(0, 0, w, h);

        var clarity = data.clarity_data || {};
        var metrics = clarity.metrics || {};
        // データがなくてもスクロール深度のガイドは常に表示
        if (Object.keys(metrics).length === 0 && metric === 'scroll') {
            metrics = {}; // 空でも描画関数は呼ぶ
        }
        // site_wide（サイト全体の正確なDevice別集計）を優先使用
        var devKey = device === 'mobile' ? 'Mobile' : 'PC';
        var siteWide = clarity.site_wide || {};
        var swDev = (siteWide.by_device && (siteWide.by_device[devKey] || siteWide.by_device['Desktop'])) || {};
        var urlDev = (clarity.devices && (clarity.devices[devKey] || clarity.devices['Desktop'])) || {};
        var deviceData = {};
        var dk; for (dk in urlDev) { deviceData[dk] = urlDev[dk]; }
        for (dk in swDev) { deviceData[dk] = swDev[dk]; }

        switch (metric) {
            case 'scroll':
                drawScrollOverlay(ctx, w, h, metrics, deviceData);
                break;
            case 'click':
                drawClickOverlay(ctx, w, h, metrics);
                break;
            case 'dead_click':
                drawDeadClickOverlay(ctx, w, h, metrics);
                break;
            case 'rage_click':
                drawRageClickOverlay(ctx, w, h, metrics);
                break;
        }
    }

    function drawScrollOverlay(ctx, w, h, metrics, deviceData) {
        var scrollData = deviceData.scroll_depth || metrics.scroll_depth || {};
        var trafficData = deviceData.traffic || metrics.traffic || {};
        var hasData = Object.keys(scrollData).length > 0 || Object.keys(trafficData).length > 0;

        if (!hasData) {
            drawNoDataLabel(ctx, w, h, 'Clarityデータ未同期');
            return;
        }

        // スクロール到達セッション割合(%)
        var scrollPct = scrollData.sessionsWithMetricPercentage;
        var avgScroll = (scrollPct !== undefined && scrollPct !== null) ? parseFloat(scrollPct) : -1;
        var sessions = parseInt(trafficData.sessionsCount || trafficData.totalSessionCount || 0);

        // ===== 段階的スクロール深度バー（左端に到達率バー） =====
        var barW = 36; // 左端バーの幅
        var steps = [
            { pct: 0,   label: 'FV',   color: 'rgba(34,197,94,0.35)' },
            { pct: 25,  label: '25%',  color: 'rgba(34,197,94,0.25)' },
            { pct: 50,  label: '50%',  color: 'rgba(251,191,36,0.25)' },
            { pct: 75,  label: '75%',  color: 'rgba(239,68,68,0.20)' },
            { pct: 100, label: '100%', color: 'rgba(239,68,68,0.30)' },
        ];

        // 各区間を色分け（左端バー + 全体に薄くグラデーション）
        for (var i = 0; i < steps.length - 1; i++) {
            var y1 = h * (steps[i].pct / 100);
            var y2 = h * (steps[i + 1].pct / 100);
            var sh = y2 - y1;

            // 左端バー
            ctx.fillStyle = steps[i].color;
            ctx.fillRect(0, y1, barW, sh);

            // 全体に薄く
            ctx.fillStyle = steps[i].color.replace(/[\d.]+\)$/, '0.06)');
            ctx.fillRect(barW, y1, w - barW, sh);
        }

        // 区切り線 + パーセンテージラベル
        ctx.strokeStyle = 'rgba(100,100,100,0.3)';
        ctx.lineWidth = 1;
        [25, 50, 75].forEach(function(pct) {
            var y = h * (pct / 100);
            // 破線
            ctx.setLineDash([3, 3]);
            ctx.beginPath(); ctx.moveTo(barW, y); ctx.lineTo(w, y); ctx.stroke();
            ctx.setLineDash([]);
            // バー内のラベル
            ctx.fillStyle = 'rgba(30,41,59,0.8)';
            ctx.font = 'bold 10px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(pct + '%', barW / 2, y + 14);
            ctx.textAlign = 'start';
        });

        // FV ラベル
        ctx.fillStyle = 'rgba(30,41,59,0.7)';
        ctx.font = 'bold 10px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('FV', barW / 2, 14);
        ctx.textAlign = 'start';

        // ===== 到達率ライン（赤い実線） =====
        if (avgScroll >= 0) {
            var scrollLine = h * (avgScroll / 100);

            // 到達ラインより下を暗く
            ctx.fillStyle = 'rgba(0,0,0,0.08)';
            ctx.fillRect(barW, scrollLine, w - barW, h - scrollLine);

            // ライン描画
            ctx.strokeStyle = '#ef4444';
            ctx.lineWidth = 2.5;
            ctx.beginPath(); ctx.moveTo(0, scrollLine); ctx.lineTo(w, scrollLine); ctx.stroke();

            // ラベルバッジ
            var labelText = '▼ 到達率 ' + avgScroll.toFixed(1) + '%';
            ctx.font = 'bold 12px sans-serif';
            var tw = ctx.measureText(labelText).width;
            var badgeX = barW + 8;
            var badgeY = scrollLine + 4;
            // バッジ背景
            ctx.fillStyle = '#ef4444';
            roundRect(ctx, badgeX, badgeY, tw + 16, 22, 4);
            ctx.fill();
            // テキスト
            ctx.fillStyle = '#fff';
            ctx.fillText(labelText, badgeX + 8, badgeY + 16);
        }

        // セッション数（右上）
        if (sessions > 0) {
            var sessText = 'セッション: ' + sessions.toLocaleString();
            ctx.font = '11px sans-serif';
            var stw = ctx.measureText(sessText).width;
            ctx.fillStyle = 'rgba(30,41,59,0.75)';
            roundRect(ctx, w - stw - 20, 6, stw + 14, 20, 3);
            ctx.fill();
            ctx.fillStyle = '#fff';
            ctx.textAlign = 'right';
            ctx.fillText(sessText, w - 14, 20);
            ctx.textAlign = 'start';
        }
    }

    // 角丸矩形ヘルパー
    function roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + w - r, y);
        ctx.quadraticCurveTo(x + w, y, x + w, y + r);
        ctx.lineTo(x + w, y + h - r);
        ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
        ctx.lineTo(x + r, y + h);
        ctx.quadraticCurveTo(x, y + h, x, y + h - r);
        ctx.lineTo(x, y + r);
        ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.closePath();
    }

    function drawClickOverlay(ctx, w, h, metrics) {
        var traffic = metrics.traffic || {};
        var sessions = parseInt(traffic.sessionsCount || traffic.totalSessionCount || traffic.Count || 0);
        if (sessions <= 0) {
            drawNoDataLabel(ctx, w, h, 'クリックデータなし');
            return;
        }
        // クリック分布: 座標データなし → ページ上部に集中する傾向を可視化
        // TODO: 将来CSV/座標データ取り込み時に実座標ベース描画に置換
        var clickGrad = ctx.createRadialGradient(w * 0.5, h * 0.15, 0, w * 0.5, h * 0.15, w * 0.4);
        clickGrad.addColorStop(0, 'rgba(59,130,246,0.3)');
        clickGrad.addColorStop(0.5, 'rgba(59,130,246,0.1)');
        clickGrad.addColorStop(1, 'rgba(59,130,246,0)');
        ctx.fillStyle = clickGrad;
        ctx.fillRect(0, 0, w, h);
        ctx.fillStyle = 'rgba(30,64,175,0.6)';
        ctx.font = '11px sans-serif';
        ctx.fillText('セッション数: ' + sessions.toLocaleString(), 8, 18);
        ctx.fillStyle = 'rgba(30,64,175,0.35)';
        ctx.font = '10px sans-serif';
        ctx.fillText('※ 座標データは今後対応予定', 8, 34);
    }

    function drawDeadClickOverlay(ctx, w, h, metrics) {
        var dc = metrics.dead_click_count || {};
        var count = parseInt(dc.subTotal || dc.sessionsCount || dc.Count || dc.value || 0);
        drawClickBadge(ctx, w, h, count, 'Dead Click', '#f59e0b', 'rgba(245,158,11,0.12)');
    }

    function drawRageClickOverlay(ctx, w, h, metrics) {
        var rc = metrics.rage_click_count || {};
        var count = parseInt(rc.subTotal || rc.sessionsCount || rc.Count || rc.value || 0);
        drawClickBadge(ctx, w, h, count, 'Rage Click', '#ef4444', 'rgba(239,68,68,0.12)');
    }

    function drawClickBadge(ctx, w, h, count, label, color, bgColor) {
        ctx.fillStyle = bgColor;
        ctx.fillRect(0, 0, w, h);
        ctx.fillStyle = color;
        ctx.font = 'bold 28px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(count, w / 2, h / 2 - 10);
        ctx.font = '14px sans-serif';
        ctx.fillText(label, w / 2, h / 2 + 16);
        if (count === 0) {
            ctx.font = '12px sans-serif';
            ctx.fillStyle = 'rgba(100,100,100,0.5)';
            ctx.fillText('検出されていません', w / 2, h / 2 + 40);
        } else {
            ctx.font = '10px sans-serif';
            ctx.fillStyle = 'rgba(100,100,100,0.4)';
            ctx.fillText('※ 座標は今後対応予定', w / 2, h / 2 + 40);
        }
        ctx.textAlign = 'start';
    }

    function drawNoDataLabel(ctx, w, h, text) {
        ctx.fillStyle = 'rgba(148,163,184,0.15)';
        ctx.fillRect(0, 0, w, h);
        ctx.fillStyle = '#94a3b8';
        ctx.font = '13px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(text, w / 2, h / 2);
        ctx.textAlign = 'start';
    }

    function renderHeatmapSummary(data, device) {
        var clarity = data.clarity_data || {};
        var metrics = clarity.metrics || {};
        // サイト全体のDevice別データ（正確な集計値）を優先使用
        var deviceKey = device === 'mobile' ? 'Mobile' : 'PC';
        var siteWide = clarity.site_wide || {};
        var swDevice = (siteWide.by_device && (siteWide.by_device[deviceKey] || siteWide.by_device['Desktop'])) || {};
        // URL集約データ（1000行制限で不正確な場合あり）をフォールバック
        var devMetrics = (clarity.devices && (clarity.devices[deviceKey] || clarity.devices['Desktop'])) || {};

        // データ期間を表示（Clarity APIは直近3日間のデータ）
        var periodEl = document.getElementById('hmDataPeriod');
        if (periodEl) {
            var syncDate = data.clarity_sync_date;
            if (syncDate) {
                // 同期日の3日前〜同期日
                var sd = new Date(syncDate.replace(' ', 'T'));
                var from = new Date(sd);
                from.setDate(from.getDate() - 3);
                var fmt = function(d) { return d.getFullYear() + '/' + (d.getMonth()+1) + '/' + d.getDate(); };
                periodEl.textContent = '📅 ' + fmt(from) + ' 〜 ' + fmt(sd) + '（直近3日間） 同期: ' + syncDate;
            } else {
                periodEl.textContent = '';
            }
        }

        if (Object.keys(metrics).length === 0) {
            hmSummaryGrid.innerHTML = '<div class="hm-no-data">Clarityデータ未取得 — クライアント設定からClarity同期を実行してください</div>';
            return;
        }

        var debugHtml = '';

        // Clarity APIの実レスポンスキー:
        // sessionsCount, sessionsWithMetricPercentage, pagesViews, subTotal
        function getMetricVal(obj, fallbackKey) {
            if (!obj || typeof obj !== 'object') return '-';
            // sessionsWithMetricPercentage = そのメトリクスに該当するセッション割合(%)
            // sessionsCount = セッション数
            // subTotal = メトリクスの合計値
            // pagesViews = ページビュー数
            return obj.sessionsWithMetricPercentage
                || obj.subTotal
                || obj.sessionsCount
                || obj.pagesViews
                || obj.Average || obj.Count || obj.value
                || '-';
        }

        function getSessionCount(obj) {
            if (!obj || typeof obj !== 'object') return '-';
            return obj.sessionsCount || obj.totalSessionCount || obj.Count || '-';
        }

        function stat(label, value, sub) {
            return '<div class="hm-stat"><div class="hm-stat-label">' + escHtml(label) + '</div>'
                + '<div class="hm-stat-value">' + escHtml(String(value)) + '</div>'
                + (sub ? '<div class="hm-stat-sub">' + escHtml(sub) + '</div>' : '')
                + '</div>';
        }

        // site_wide（サイト全体の正確なDevice別集計）→ URL集約 → 全体 の優先順
        var scrollM = swDevice.scroll_depth || devMetrics.scroll_depth || metrics.scroll_depth || {};
        var engM    = swDevice.engagement_time || devMetrics.engagement_time || metrics.engagement_time || {};
        var dcM     = swDevice.dead_click_count || devMetrics.dead_click_count || metrics.dead_click_count || {};
        var rcM     = swDevice.rage_click_count || devMetrics.rage_click_count || metrics.rage_click_count || {};
        var tM      = swDevice.traffic || devMetrics.traffic || metrics.traffic || {};
        var ecM     = swDevice.error_click_count || devMetrics.error_click_count || metrics.error_click_count || {};

        // 0 を正しく扱うヘルパー（|| は 0 を falsy にするため使わない）
        function safeNum(v) {
            if (v === undefined || v === null || v === '') return null;
            return v;
        }

        // スクロール深度: sessionsWithMetricPercentage が到達セッション割合
        var scrollPct = safeNum(scrollM.sessionsWithMetricPercentage);
        var scrollDisplay = scrollPct !== null ? (parseFloat(scrollPct).toFixed(1) + '%') : '-';

        // エンゲージメント
        var engPct = safeNum(engM.sessionsWithMetricPercentage);
        var engDisplay = engPct !== null ? (parseFloat(engPct).toFixed(1) + '%') : '-';

        // Dead/Rage Click: subTotal がイベント合計数
        var dcCount = safeNum(dcM.subTotal) !== null ? dcM.subTotal : (safeNum(dcM.sessionsCount) !== null ? dcM.sessionsCount : '-');
        var rcCount = safeNum(rcM.subTotal) !== null ? rcM.subTotal : (safeNum(rcM.sessionsCount) !== null ? rcM.sessionsCount : '-');
        var ecCount = safeNum(ecM.subTotal) !== null ? ecM.subTotal : (safeNum(ecM.sessionsCount) !== null ? ecM.sessionsCount : '-');

        // セッション数
        var sessions = safeNum(tM.sessionsCount) !== null ? tM.sessionsCount : (safeNum(tM.totalSessionCount) !== null ? tM.totalSessionCount : '-');
        var pageViews = safeNum(tM.pagesViews) !== null ? tM.pagesViews : '-';

        hmSummaryGrid.innerHTML = ''
            + stat('セッション数', sessions, 'PV: ' + pageViews)
            + stat('スクロール深度', scrollDisplay, device === 'mobile' ? 'スマホ版' : 'PC版')
            + stat('Dead Click', dcCount, '無反応クリック')
            + stat('Rage Click', rcCount, '連打クリック')
            + stat('エンゲージメント', engDisplay, '')
            + stat('Error Click', ecCount, 'エラークリック')
            + debugHtml;
    }

    // --- タブ切り替え ---
    els.detailPanel.addEventListener('click', function(e) {
        var tab = e.target.closest('.pa-tab');
        if (!tab) return;
        var tabName = tab.getAttribute('data-tab');
        var tabs = els.detailPanel.querySelectorAll('.pa-tab');
        var panes = els.detailPanel.querySelectorAll('.pa-tab-pane');
        for (var i = 0; i < tabs.length; i++) { tabs[i].classList.remove('is-active'); }
        for (var i = 0; i < panes.length; i++) { panes[i].classList.remove('is-active'); }
        tab.classList.add('is-active');
        var pane = els.detailPanel.querySelector('[data-pane="' + tabName + '"]');
        if (pane) pane.classList.add('is-active');
    });

    // --- ファイルアップロード ---
    window._paUpload = function(id, device) {
        uploadTarget = { id: id, device: device };
        els.fileInput.value = '';
        els.fileInput.click();
    };

    els.fileInput.addEventListener('change', function() {
        if (!this.files[0] || !uploadTarget) return;
        var file = this.files[0];

        // ファイルサイズチェック（50MB上限）
        var maxSize = 50 * 1024 * 1024;
        if (file.size > maxSize) {
            alert('ファイルサイズが大きすぎます（上限: 50MB）。\n現在のサイズ: ' + Math.round(file.size / 1024 / 1024) + 'MB');
            return;
        }

        var formData = new FormData();
        formData.append('file', file);
        formData.append('device_type', uploadTarget.device);

        showLoading();
        fetch(API_BASE + '/' + uploadTarget.id + '/snapshot', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': NONCE },
            body: formData,
        })
        .then(function(r) {
            if (!r.ok) {
                throw new Error('HTTP ' + r.status + ': サーバーエラー（ファイルが大きすぎる可能性があります）');
            }
            return r.json();
        })
        .then(function(res) {
            hideLoading();
            if (res.success) {
                window._paShowDetail(uploadTarget.id);
                loadPages();
            } else {
                alert(res.message || 'アップロードに失敗しました');
            }
        })
        .catch(function(err) {
            hideLoading();
            var sizeInfo = '\nファイルサイズ: ' + Math.round(file.size / 1024) + 'KB';
            alert('アップロードエラー: ' + (err.message || '通信エラー') + sizeInfo + '\n\nサーバーのアップロード上限を超えている可能性があります。');
        });
    });

    // --- キャプチャ削除 ---
    window._paDeleteCapture = function(id, device) {
        var label = device === 'mobile' ? 'スマホ版' : 'PC版';
        if (!confirm(label + 'のキャプチャ画像を削除しますか？')) return;

        showLoading();
        fetch(API_BASE + '/' + id + '/snapshot', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': NONCE,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ device_type: device }),
        })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(res) {
            hideLoading();
            if (res.success) {
                window._paShowDetail(id);
                loadPages();
            } else {
                alert(res.message || '削除に失敗しました');
            }
        })
        .catch(function(err) {
            hideLoading();
            alert('削除エラー: ' + (err.message || '通信エラー'));
        });
    };

    // --- 初期化 ---
    loadPages();

})();
</script>

<?php get_footer(); ?>
