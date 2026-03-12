<?php
/*
Template Name: AI検索スコア
*/

if (!is_user_logged_in()) {
    wp_safe_redirect(home_url('/login/'));
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

// ページタイトル設定
set_query_var('gcrev_page_title', 'AI検索スコア');
set_query_var('gcrev_page_subtitle', 'ChatGPT・Gemini・Google AIモードで、あなたのビジネスがどれくらい表示されるかを確認できます。');

// パンくず設定
set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('AI検索スコア', '集客のようす'));

get_header();
?>

<style>
/* ============================================================
   page-aio-score — AI検索スコアページ
   ============================================================ */

/* --- Summary Cards (3列) --- */
.aio-summary-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}
.aio-summary-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    padding: 24px 20px;
    text-align: center;
    transition: box-shadow 0.2s;
}
.aio-summary-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
.aio-summary-card__provider {
    font-size: 13px;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 8px;
    letter-spacing: 0.02em;
}
.aio-summary-card__icon {
    width: 36px; height: 36px;
    margin: 0 auto 8px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}
.aio-summary-card__icon--chatgpt  { background: #e8f5e9; color: #2e7d32; }
.aio-summary-card__icon--gemini   { background: #e3f2fd; color: #1565c0; }
.aio-summary-card__icon--google   { background: #fff3e0; color: #e65100; }
.aio-summary-card__score {
    font-size: 42px;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 4px;
}
.aio-summary-card__score--chatgpt  { color: #16a34a; }
.aio-summary-card__score--gemini   { color: #2563eb; }
.aio-summary-card__score--google   { color: #ea580c; }
.aio-summary-card__label {
    font-size: 12px;
    color: #94a3b8;
    font-weight: 500;
}
.aio-summary-card__nodata {
    font-size: 13px;
    color: #cbd5e1;
    margin-top: 8px;
}

/* --- Info Banner --- */
.aio-info-banner {
    background: #eff6ff;
    border-left: 4px solid #3b82f6;
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 24px;
    font-size: 13px;
    color: #1e40af;
    line-height: 1.6;
}

/* --- Header bar --- */
.aio-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}
.aio-header__title {
    font-size: 20px; font-weight: 700; color: #1e293b;
}
.aio-header__meta {
    font-size: 12px; color: #94a3b8;
}

/* --- Buttons --- */
.aio-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px;
    font-size: 13px; font-weight: 600;
    cursor: pointer; border: none;
    transition: background 0.2s, opacity 0.2s;
}
.aio-btn--primary { background: #2563eb; color: #fff; }
.aio-btn--primary:hover { background: #1d4ed8; }
.aio-btn--primary:disabled { opacity: 0.5; cursor: not-allowed; }
.aio-btn--sm { padding: 5px 10px; font-size: 12px; }

/* --- Keyword Table --- */
.aio-kw-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    overflow: hidden;
    margin-bottom: 24px;
}
.aio-kw-table th {
    background: #f8fafc; font-size: 12px; font-weight: 600;
    color: #64748b; padding: 12px 14px; text-align: left;
    border-bottom: 1px solid #e2e8f0;
}
.aio-kw-table td {
    font-size: 13px; padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9; color: #334155;
}
.aio-kw-row { cursor: pointer; transition: background 0.15s; }
.aio-kw-row:hover { background: #f8fafc; }
.aio-kw-row__arrow {
    display: inline-block; transition: transform 0.2s;
    font-size: 11px; color: #94a3b8; margin-right: 6px;
}
.aio-kw-row__arrow.open { transform: rotate(90deg); }
.aio-kw-vis { font-weight: 700; font-size: 14px; }
.aio-kw-vis--high { color: #16a34a; }
.aio-kw-vis--mid  { color: #d97706; }
.aio-kw-vis--low  { color: #ef4444; }
.aio-kw-vis--none { color: #cbd5e1; }

/* --- Detail (Accordion) --- */
.aio-detail-row { display: none; }
.aio-detail-row.open { display: table-row; }
.aio-detail-cell { padding: 16px !important; background: #fafbfc; }
.aio-detail-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}
.aio-detail-col {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    overflow: hidden;
}
.aio-detail-col__header {
    padding: 10px 14px;
    font-size: 13px; font-weight: 700;
    border-bottom: 1px solid #f1f5f9;
}
.aio-detail-col__header--chatgpt { background: #f0fdf4; color: #166534; }
.aio-detail-col__header--gemini  { background: #eff6ff; color: #1e40af; }
.aio-detail-col__header--google  { background: #fff7ed; color: #9a3412; }
.aio-detail-col__vis {
    font-size: 11px; font-weight: 500; float: right;
    padding: 2px 8px; border-radius: 10px; background: rgba(0,0,0,0.05);
}
.aio-rank-list { list-style: none; margin: 0; padding: 0; }
.aio-rank-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 14px; font-size: 13px;
    border-bottom: 1px solid #f8fafc;
}
.aio-rank-item:last-child { border-bottom: none; }
.aio-rank-item--self {
    background: #ecfdf5;
    font-weight: 600;
}
.aio-rank-num {
    width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; font-size: 11px; font-weight: 700;
    background: #f1f5f9; color: #64748b; flex-shrink: 0;
}
.aio-rank-item--self .aio-rank-num {
    background: #16a34a; color: #fff;
}
.aio-rank-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.aio-rank-rate { font-size: 12px; color: #64748b; font-weight: 600; }
.aio-nodata {
    padding: 20px; text-align: center;
    font-size: 13px; color: #94a3b8;
}

/* --- Progress Overlay --- */
.aio-progress {
    display: none;
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(255,255,255,0.85);
    z-index: 9999;
    justify-content: center; align-items: center;
    flex-direction: column; gap: 12px;
}
.aio-progress.active { display: flex; }
.aio-progress__spinner {
    width: 40px; height: 40px;
    border: 4px solid #e2e8f0; border-top-color: #2563eb;
    border-radius: 50%; animation: aio-spin 0.8s linear infinite;
}
@keyframes aio-spin { to { transform: rotate(360deg); } }
.aio-progress__text { font-size: 14px; color: #475569; font-weight: 500; }

/* --- Toast --- */
.aio-toast {
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
    background: #1e293b; color: #fff; padding: 10px 20px; border-radius: 8px;
    font-size: 13px; z-index: 10000; opacity: 0; transition: opacity 0.3s;
    pointer-events: none;
}
.aio-toast.show { opacity: 1; }
.aio-toast--error { background: #dc2626; }

/* --- Empty State --- */
.aio-empty {
    text-align: center; padding: 48px 24px;
    background: #fff; border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}
.aio-empty__icon { font-size: 48px; margin-bottom: 12px; }
.aio-empty__title { font-size: 16px; font-weight: 600; color: #475569; margin-bottom: 8px; }
.aio-empty__desc { font-size: 13px; color: #94a3b8; line-height: 1.5; }

/* --- Responsive --- */
@media (max-width: 768px) {
    .aio-summary-cards { grid-template-columns: 1fr; }
    .aio-detail-grid { grid-template-columns: 1fr; }
    .aio-summary-card__score { font-size: 32px; }
    .aio-kw-table { font-size: 12px; }
    .aio-kw-table th, .aio-kw-table td { padding: 10px 8px; }
}
</style>

<div class="content-area">

    <!-- ヘッダー -->
    <div class="aio-header">
        <div>
            <div class="aio-header__title">AI検索スコア</div>
            <div class="aio-header__meta" id="lastFetched"></div>
        </div>
        <button class="aio-btn aio-btn--primary" id="runAllBtn" onclick="runAllAio()">
            計測する
        </button>
    </div>

    <!-- サマリーカード × 3 -->
    <div class="aio-summary-cards" id="summaryCards">
        <div class="aio-summary-card">
            <div class="aio-summary-card__icon aio-summary-card__icon--chatgpt">C</div>
            <div class="aio-summary-card__provider">ChatGPT</div>
            <div class="aio-summary-card__score aio-summary-card__score--chatgpt" id="visChatgpt">--%</div>
            <div class="aio-summary-card__label">表示確率</div>
        </div>
        <div class="aio-summary-card">
            <div class="aio-summary-card__icon aio-summary-card__icon--gemini">G</div>
            <div class="aio-summary-card__provider">Gemini</div>
            <div class="aio-summary-card__score aio-summary-card__score--gemini" id="visGemini">--%</div>
            <div class="aio-summary-card__label">表示確率</div>
        </div>
        <div class="aio-summary-card">
            <div class="aio-summary-card__icon aio-summary-card__icon--google">A</div>
            <div class="aio-summary-card__provider">Google AIモード</div>
            <div class="aio-summary-card__score aio-summary-card__score--google" id="visGoogle">--%</div>
            <div class="aio-summary-card__label">表示確率</div>
        </div>
    </div>

    <!-- インフォバナー -->
    <div class="aio-info-banner">
        Googleマップや検索での評価が高まると、AIからのおすすめにも表示されやすくなる可能性があります。口コミの獲得やサイトコンテンツの充実など、各項目の改善を進めていきましょう。
    </div>

    <!-- キーワード別テーブル -->
    <div id="keywordSection">
        <table class="aio-kw-table" id="kwTable" style="display:none;">
            <thead>
                <tr>
                    <th>キーワード</th>
                    <th style="text-align:center; width: 120px;">ChatGPT</th>
                    <th style="text-align:center; width: 120px;">Gemini</th>
                    <th style="text-align:center; width: 140px;">Google AIモード</th>
                </tr>
            </thead>
            <tbody id="kwTableBody"></tbody>
        </table>
        <div class="aio-empty" id="emptyState" style="display:none;">
            <div class="aio-empty__icon">🔍</div>
            <div class="aio-empty__title">AIO計測キーワードがありません</div>
            <div class="aio-empty__desc">
                管理画面の「AI検索スコア」設定から、<br>計測したいキーワードのAIO診断を有効にしてください。
            </div>
        </div>
    </div>

</div>

<!-- プログレスオーバーレイ -->
<div class="aio-progress" id="progressOverlay">
    <div class="aio-progress__spinner"></div>
    <div class="aio-progress__text" id="progressText">AI検索スコアを計測中...</div>
</div>

<!-- Toast -->
<div class="aio-toast" id="toast"></div>

<?php get_footer(); ?>

<script>
(function() {
    'use strict';

    var wpNonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    var summaryData = null;

    document.addEventListener('DOMContentLoaded', function() {
        fetchSummary();
    });

    // =========================================================
    // Fetch & Render Summary
    // =========================================================
    function fetchSummary() {
        fetch('/wp-json/gcrev/v1/aio/summary', {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success && json.data) {
                summaryData = json.data;
                renderSummary();
                renderKeywordTable();
            }
        })
        .catch(function(err) { console.error('[AIO]', err); });
    }

    function renderSummary() {
        var d = summaryData;
        setVis('visChatgpt', d.chatgpt);
        setVis('visGemini', d.gemini);
        setVis('visGoogle', d.google_ai);

        if (d.last_fetched) {
            document.getElementById('lastFetched').textContent = '最終計測: ' + formatDate(d.last_fetched);
        }
    }

    function setVis(elId, pData) {
        var el = document.getElementById(elId);
        if (!el || !pData) return;
        if (pData.total_queries === 0) {
            el.textContent = '--';
            var nodata = document.createElement('div');
            nodata.className = 'aio-summary-card__nodata';
            nodata.textContent = 'データなし';
            el.parentNode.appendChild(nodata);
        } else {
            el.textContent = Math.round(pData.visibility) + '%';
        }
    }

    // =========================================================
    // Keyword Table
    // =========================================================
    function renderKeywordTable() {
        var keywords = summaryData.keywords || [];
        var table = document.getElementById('kwTable');
        var empty = document.getElementById('emptyState');

        if (keywords.length === 0) {
            table.style.display = 'none';
            empty.style.display = 'block';
            return;
        }
        table.style.display = '';
        empty.style.display = 'none';

        var tbody = document.getElementById('kwTableBody');
        var html = '';

        for (var i = 0; i < keywords.length; i++) {
            var kw = keywords[i];
            // Summary row (clickable)
            html += '<tr class="aio-kw-row" onclick="toggleDetail(' + kw.keyword_id + ', this)">';
            html += '<td><span class="aio-kw-row__arrow" id="arrow-' + kw.keyword_id + '">▶</span>' + escHtml(kw.keyword) + '</td>';
            html += '<td style="text-align:center;">' + visCell(kw.chatgpt) + '</td>';
            html += '<td style="text-align:center;">' + visCell(kw.gemini) + '</td>';
            html += '<td style="text-align:center;">' + visCell(kw.google_ai) + '</td>';
            html += '</tr>';
            // Detail row (hidden)
            html += '<tr class="aio-detail-row" id="detail-' + kw.keyword_id + '">';
            html += '<td colspan="4" class="aio-detail-cell">';
            html += '<div class="aio-nodata">クリックで詳細を読み込みます</div>';
            html += '</td>';
            html += '</tr>';
        }

        tbody.innerHTML = html;
    }

    function visCell(pd) {
        if (!pd || pd.status === 'no_data') {
            return '<span class="aio-kw-vis aio-kw-vis--none">--</span>';
        }
        var v = Math.round(pd.visibility);
        var cls = 'aio-kw-vis--none';
        if (v >= 60) cls = 'aio-kw-vis--high';
        else if (v >= 30) cls = 'aio-kw-vis--mid';
        else if (v > 0) cls = 'aio-kw-vis--low';
        return '<span class="aio-kw-vis ' + cls + '">' + v + '%</span>';
    }

    // =========================================================
    // Accordion Toggle + Lazy Load Detail
    // =========================================================
    window.toggleDetail = function(kwId, rowEl) {
        var detail = document.getElementById('detail-' + kwId);
        var arrow = document.getElementById('arrow-' + kwId);
        if (!detail) return;

        if (detail.classList.contains('open')) {
            detail.classList.remove('open');
            if (arrow) arrow.classList.remove('open');
            return;
        }

        detail.classList.add('open');
        if (arrow) arrow.classList.add('open');

        // Lazy load if not yet loaded
        if (!detail.dataset.loaded) {
            var cell = detail.querySelector('.aio-detail-cell') || detail.querySelector('td');
            cell.innerHTML = '<div class="aio-nodata">読み込み中...</div>';

            fetch('/wp-json/gcrev/v1/aio/keyword-detail?keyword_id=' + kwId, {
                headers: { 'X-WP-Nonce': wpNonce },
                credentials: 'same-origin'
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.success && json.data) {
                    cell.innerHTML = renderDetailGrid(json.data);
                    detail.dataset.loaded = '1';
                } else {
                    cell.innerHTML = '<div class="aio-nodata">データの取得に失敗しました</div>';
                }
            })
            .catch(function() {
                cell.innerHTML = '<div class="aio-nodata">通信エラー</div>';
            });
        }
    };

    function renderDetailGrid(data) {
        var providers = [
            { key: 'chatgpt',   label: 'ChatGPT',        hdrCls: 'aio-detail-col__header--chatgpt'  },
            { key: 'gemini',    label: 'Gemini',          hdrCls: 'aio-detail-col__header--gemini'   },
            { key: 'google_ai', label: 'Google AIモード', hdrCls: 'aio-detail-col__header--google'   }
        ];

        var html = '<div class="aio-detail-grid">';

        for (var pi = 0; pi < providers.length; pi++) {
            var p = providers[pi];
            var pd = (data.providers && data.providers[p.key]) || {};

            html += '<div class="aio-detail-col">';
            html += '<div class="aio-detail-col__header ' + p.hdrCls + '">';
            html += p.label;
            if (pd.visibility != null) {
                html += '<span class="aio-detail-col__vis">' + Math.round(pd.visibility) + '%</span>';
            }
            html += '</div>';

            var rankings = pd.rankings || [];
            if (rankings.length === 0) {
                if (pd.status === 'no_data') {
                    html += '<div class="aio-nodata">AI Overviewデータなし</div>';
                } else {
                    html += '<div class="aio-nodata">データなし</div>';
                }
            } else {
                html += '<ul class="aio-rank-list">';
                for (var ri = 0; ri < rankings.length; ri++) {
                    var r = rankings[ri];
                    var selfCls = r.is_self ? ' aio-rank-item--self' : '';
                    html += '<li class="aio-rank-item' + selfCls + '">';
                    html += '<span class="aio-rank-num">' + r.rank + '</span>';
                    html += '<span class="aio-rank-name">' + escHtml(r.name) + '</span>';
                    html += '<span class="aio-rank-rate">' + r.mention_rate + '%</span>';
                    html += '</li>';
                }
                html += '</ul>';
            }

            html += '</div>'; // col
        }

        html += '</div>'; // grid
        return html;
    }

    // =========================================================
    // Run AIO Check — キーワード×プロバイダー単位で逐次リクエスト
    // =========================================================
    var PROVIDERS = ['chatgpt', 'gemini', 'google_ai'];
    var PROVIDER_LABELS = { chatgpt: 'ChatGPT', gemini: 'Gemini', google_ai: 'Google AI' };

    window.runAllAio = function() {
        if (!summaryData || !summaryData.keywords || summaryData.keywords.length === 0) {
            showToast('計測対象のキーワードがありません', true);
            return;
        }
        if (!confirm('全キーワードのAI検索スコアを計測します。数分かかる場合があります。よろしいですか？')) return;

        var btn = document.getElementById('runAllBtn');
        btn.disabled = true;

        // キーワード × プロバイダーのジョブキューを構築
        var jobs = [];
        summaryData.keywords.forEach(function(kw) {
            PROVIDERS.forEach(function(p) {
                jobs.push({ keyword_id: kw.keyword_id, keyword: kw.keyword, provider: p });
            });
        });

        var total = jobs.length;
        var done  = 0;
        var errors = [];

        function runNext() {
            if (done >= total) {
                // 全ジョブ完了
                showProgress(false);
                btn.disabled = false;
                summaryData = null;
                fetchSummary();
                if (errors.length > 0) {
                    showToast('計測完了（' + errors.length + '件エラーあり）', true);
                } else {
                    showToast('計測が完了しました');
                }
                return;
            }

            var job = jobs[done];
            var label = job.keyword + ' — ' + PROVIDER_LABELS[job.provider];
            showProgress(true, '計測中 (' + (done + 1) + '/' + total + '): ' + label);

            fetch('/wp-json/gcrev/v1/aio/run-keyword', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': wpNonce,
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ keyword_id: job.keyword_id, provider: job.provider })
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                done++;
                if (!json.success) {
                    errors.push(label + ': ' + (json.message || 'error'));
                    console.warn('[AIO] job error:', label, json.message);
                }
                runNext();
            })
            .catch(function(err) {
                done++;
                errors.push(label + ': ' + err.message);
                console.error('[AIO] job fetch error:', label, err);
                runNext(); // エラーでも次へ進む
            });
        }

        runNext();
    };

    // =========================================================
    // Utilities
    // =========================================================
    function escHtml(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    function formatDate(str) {
        if (!str) return '';
        var d = new Date(str.replace(/-/g, '/'));
        if (isNaN(d.getTime())) return str;
        var y = d.getFullYear();
        var m = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        var h = ('0' + d.getHours()).slice(-2);
        var min = ('0' + d.getMinutes()).slice(-2);
        return y + '/' + m + '/' + day + ' ' + h + ':' + min;
    }

    function showProgress(on, text) {
        var el = document.getElementById('progressOverlay');
        if (on) {
            el.classList.add('active');
            if (text) document.getElementById('progressText').textContent = text;
        } else {
            el.classList.remove('active');
        }
    }

    function showToast(msg, isErr) {
        var el = document.getElementById('toast');
        el.textContent = msg;
        el.className = 'aio-toast show' + (isErr ? ' aio-toast--error' : '');
        setTimeout(function() { el.className = 'aio-toast'; }, 3000);
    }
})();
</script>
