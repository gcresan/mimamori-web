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
        <button type="button" class="pa-tab" data-tab="behavior">行動データ</button>
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
        <!-- 行動データタブ -->
        <div class="pa-tab-pane" data-pane="behavior">
            <div class="pa-placeholder">
                <div class="pa-placeholder-icon">&#128202;</div>
                <div class="pa-placeholder-text">Clarity連携は準備中です<br>今後のアップデートで行動データの確認が可能になります</div>
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
    }
    function closeDetail() {
        els.detailPanel.classList.remove('is-open');
        els.detailBackdrop.classList.remove('is-open');
        currentDetailId = null;
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

        // キャプチャタブ
        var pcImg = data.screenshot_pc_url
            ? '<img src="' + escHtml(data.screenshot_pc_url) + '" class="pa-capture-img" alt="PC版">'
            : '<div class="pa-capture-empty">キャプチャ未取得</div>';
        var spImg = data.screenshot_mobile_url
            ? '<img src="' + escHtml(data.screenshot_mobile_url) + '" class="pa-capture-img" alt="SP版">'
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
        .then(function(r) { return r.json(); })
        .then(function(res) {
            hideLoading();
            if (res.success) {
                // 詳細をリロード
                window._paShowDetail(uploadTarget.id);
                // 一覧もリロード
                loadPages();
            } else {
                alert(res.message || 'アップロードに失敗しました');
            }
        })
        .catch(function() {
            hideLoading();
            alert('通信エラーが発生しました');
        });
    });

    // --- キャプチャ削除 ---
    window._paDeleteCapture = function(id, device) {
        var label = device === 'mobile' ? 'スマホ版' : 'PC版';
        if (!confirm(label + 'のキャプチャ画像を削除しますか？')) return;

        showLoading();
        fetch(API_BASE + '/' + id + '/snapshot?device_type=' + encodeURIComponent(device), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': NONCE },
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            hideLoading();
            if (res.success) {
                window._paShowDetail(id);
                loadPages();
            } else {
                alert(res.message || '削除に失敗しました');
            }
        })
        .catch(function() {
            hideLoading();
            alert('通信エラーが発生しました');
        });
    };

    // --- 初期化 ---
    loadPages();

})();
</script>

<?php get_footer(); ?>
