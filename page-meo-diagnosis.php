<?php
/*
Template Name: MEO診断
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

set_query_var( 'gcrev_page_title', 'MEO診断' );
set_query_var( 'gcrev_page_subtitle', 'Googleビジネスプロフィールの状態を診断し、改善ポイントを整理します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'MEO診断', '各種診断' ) );

get_header();
?>

<style>
/* ============================================================
   page-meo-diagnosis — MEO診断 実行+履歴
   ============================================================ */

/* Header */
.meo-diag-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.meo-diag-header__title {
    font-size: 20px; font-weight: 700; color: #1a1a1a;
    display: flex; align-items: center; gap: 8px;
}
.meo-diag-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 22px; border: none; border-radius: 10px;
    font-size: 14px; font-weight: 600; cursor: pointer;
    background: #568184; color: #fff; transition: all 0.15s; white-space: nowrap;
}
.meo-diag-btn:hover { background: #476C6F; }
.meo-diag-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.meo-diag-btn--sm { padding: 7px 14px; font-size: 13px; }
.meo-diag-btn--outline {
    background: #fff; color: #568184; border: 1px solid #c5dfe0;
}
.meo-diag-btn--outline:hover { background: #f0f7f7; }

/* Summary grid */
.meo-diag-summary {
    display: grid; grid-template-columns: 200px 1fr; gap: 24px;
    margin-bottom: 32px; background: #fff; border: 1px solid #e5e7eb;
    border-radius: 14px; padding: 28px 32px; align-items: center;
}
@media (max-width: 768px) {
    .meo-diag-summary { grid-template-columns: 1fr; text-align: center; }
}

/* Score ring */
.meo-diag-ring { position: relative; width: 160px; height: 160px; margin: 0 auto; }
.meo-diag-ring svg { width: 160px; height: 160px; transform: rotate(-90deg); }
.meo-diag-ring__bg { fill: none; stroke: #e5e7eb; stroke-width: 10; }
.meo-diag-ring__fill { fill: none; stroke-width: 10; stroke-linecap: round; transition: stroke-dashoffset 1s ease; }
.meo-diag-ring__center {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
    text-align: center;
}
.meo-diag-ring__score { font-size: 36px; font-weight: 800; color: #1a1a1a; display: block; }
.meo-diag-ring__label { font-size: 12px; color: #9ca3af; }

/* Grade cards */
.meo-diag-grades {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
}
@media (max-width: 768px) {
    .meo-diag-grades { grid-template-columns: repeat(2, 1fr); }
}
.meo-diag-grade-card {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 16px; text-align: center;
}
.meo-diag-grade-card__label { font-size: 12px; color: #6b7280; margin-bottom: 6px; }
.meo-diag-grade-card__grade {
    font-size: 28px; font-weight: 800; display: inline-flex;
    align-items: center; justify-content: center; width: 44px; height: 44px;
    border-radius: 10px; color: #fff;
}
.grade-A { background: #22c55e; }
.grade-B { background: #3b82f6; }
.grade-C { background: #f59e0b; }
.grade-D { background: #f97316; }
.grade-E { background: #ef4444; }
.meo-diag-grade-card__score { font-size: 12px; color: #9ca3af; margin-top: 4px; }

/* History */
.meo-diag-section {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
    overflow: hidden; margin-bottom: 32px;
}
.meo-diag-section__header {
    padding: 18px 24px; border-bottom: 1px solid #e5e7eb;
    font-size: 15px; font-weight: 700; color: #1a1a1a;
    display: flex; align-items: center; gap: 8px;
}
.meo-diag-table { width: 100%; border-collapse: collapse; }
.meo-diag-table th {
    background: #f9fafb; font-size: 12px; font-weight: 600; color: #6b7280;
    padding: 12px 16px; text-align: left; border-bottom: 1px solid #e5e7eb;
}
.meo-diag-table td {
    padding: 14px 16px; border-bottom: 1px solid #f3f4f6; font-size: 14px; color: #1a1a1a;
}
.meo-diag-table tr:last-child td { border-bottom: none; }
.meo-diag-table tr:hover td { background: #fafbfc; }

.meo-diag-link {
    color: #568184; text-decoration: none; font-weight: 500; font-size: 13px;
}
.meo-diag-link:hover { text-decoration: underline; }

/* Empty */
.meo-diag-empty {
    text-align: center; padding: 60px 20px; color: #9ca3af;
}
.meo-diag-empty__icon { font-size: 48px; margin-bottom: 16px; }
.meo-diag-empty__title { font-size: 18px; font-weight: 700; color: #1a1a1a; margin-bottom: 8px; }
.meo-diag-empty__text { font-size: 14px; color: #6b7280; margin-bottom: 20px; line-height: 1.6; }

/* Loading */
.meo-diag-loading { text-align: center; padding: 40px; color: #9ca3af; font-size: 14px; }

/* Progress overlay */
.meo-diag-progress-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5); z-index: 10002;
    display: none; justify-content: center; align-items: center;
}
.meo-diag-progress-overlay.active { display: flex; }
.meo-diag-progress-box {
    background: #fff; border-radius: 16px; padding: 36px 44px;
    min-width: 360px; max-width: 480px; text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
}
.meo-diag-progress-title { font-size: 16px; font-weight: 700; color: #1a1a1a; margin-bottom: 16px; }
.meo-diag-progress-spinner {
    width: 48px; height: 48px; border: 4px solid #e5e7eb;
    border-top-color: #568184; border-radius: 50%;
    animation: meo-spin 1s linear infinite; margin: 0 auto 16px;
}
@keyframes meo-spin { to { transform: rotate(360deg); } }
.meo-diag-progress-text { font-size: 14px; color: #6b7280; line-height: 1.6; }

/* Toast */
.meo-diag-toast {
    position: fixed; bottom: 24px; right: 24px; background: #1a1a1a;
    color: #fff; padding: 14px 20px; border-radius: 10px; font-size: 14px;
    z-index: 10001; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    opacity: 0; transform: translateY(12px); transition: opacity 0.3s, transform 0.3s;
    max-width: 400px; line-height: 1.5;
}
.meo-diag-toast.show { opacity: 1; transform: translateY(0); }
.meo-diag-toast--error { background: #ef4444; }

/* Date badge */
.meo-diag-date-badge {
    font-size: 12px; color: #6b7280; background: #f3f4f6; border-radius: 6px; padding: 3px 8px;
}
</style>

<div class="content-area">

    <!-- Header -->
    <div class="meo-diag-header">
        <div class="meo-diag-header__title">&#x1F50D; MEO診断</div>
        <button class="meo-diag-btn" id="meoDiagRunBtn" type="button">
            &#x1F680; 診断を実行する
        </button>
    </div>

    <!-- Loading -->
    <div class="meo-diag-loading" id="meoDiagLoading">データを読み込み中...</div>

    <!-- Empty state -->
    <div class="meo-diag-empty" id="meoDiagEmpty" style="display:none;">
        <div class="meo-diag-empty__icon">&#x1F50D;</div>
        <div class="meo-diag-empty__title">まだ診断が実行されていません</div>
        <div class="meo-diag-empty__text">
            Googleビジネスプロフィールの状態を自動診断し、<br>
            基本情報・投稿・写真・口コミなど各項目のスコアと<br>
            改善ポイントをレポートとして保存します。
        </div>
        <button class="meo-diag-btn" onclick="runDiagnostic()" type="button">
            &#x1F680; 最初の診断を実行する
        </button>
    </div>

    <!-- Summary (latest) -->
    <div id="meoDiagSummaryWrap" style="display:none;">
        <div class="meo-diag-summary" id="meoDiagSummary">
            <div class="meo-diag-ring" id="meoDiagRing">
                <svg viewBox="0 0 160 160">
                    <circle class="meo-diag-ring__bg" cx="80" cy="80" r="66"/>
                    <circle class="meo-diag-ring__fill" cx="80" cy="80" r="66"
                            stroke-dasharray="414.69" stroke-dashoffset="414.69" id="meoDiagRingFill"/>
                </svg>
                <div class="meo-diag-ring__center">
                    <span class="meo-diag-ring__score" id="meoDiagScore">-</span>
                    <span class="meo-diag-ring__label">100点中</span>
                </div>
            </div>
            <div>
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                    <span style="font-size:15px; font-weight:700; color:#1a1a1a;">カテゴリ評価</span>
                    <span class="meo-diag-date-badge" id="meoDiagDate"></span>
                </div>
                <div class="meo-diag-grades" id="meoDiagGrades"></div>
                <div style="margin-top:14px;">
                    <a href="#" class="meo-diag-link" id="meoDiagDetailLink">&#x1F4CB; 詳細レポートを見る &rarr;</a>
                </div>
            </div>
        </div>
    </div>

    <!-- History -->
    <div class="meo-diag-section" id="meoDiagHistory" style="display:none;">
        <div class="meo-diag-section__header">&#x1F4C5; 診断履歴</div>
        <div style="overflow-x:auto;">
            <table class="meo-diag-table">
                <thead>
                    <tr>
                        <th>診断日</th>
                        <th>総合スコア</th>
                        <th>グレード</th>
                        <th>基本情報</th>
                        <th>投稿</th>
                        <th>写真</th>
                        <th>レビュー</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody id="meoDiagHistoryBody"></tbody>
            </table>
        </div>
    </div>

</div><!-- /.content-area -->

<!-- Progress overlay -->
<div class="meo-diag-progress-overlay" id="meoDiagProgress">
    <div class="meo-diag-progress-box">
        <div class="meo-diag-progress-title">GBPプロフィールを診断中...</div>
        <div class="meo-diag-progress-spinner"></div>
        <div class="meo-diag-progress-text">
            基本情報・投稿・写真・口コミなどのデータを取得し、<br>
            スコアを算出しています。<br>
            <span style="font-size:12px; color:#9ca3af;">通常30秒〜1分ほどかかります</span>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="meo-diag-toast" id="meoDiagToast"></div>

<?php get_footer(); ?>

<script>
(function() {
    'use strict';

    var restBase = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gcrev/v1/' ) ) ); ?>;
    var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    var detailBase = <?php echo wp_json_encode( esc_url( home_url( '/meo-diagnosis-detail/' ) ) ); ?>;

    var loadingEl  = document.getElementById('meoDiagLoading');
    var emptyEl    = document.getElementById('meoDiagEmpty');
    var summaryEl  = document.getElementById('meoDiagSummaryWrap');
    var historyEl  = document.getElementById('meoDiagHistory');
    var runBtn     = document.getElementById('meoDiagRunBtn');

    var gradeLabels = {
        basic_info: '基本情報',
        posts:      '投稿',
        photos:     '写真',
        reviews:    'レビュー'
    };

    // Init
    document.addEventListener('DOMContentLoaded', function() {
        if (runBtn) runBtn.addEventListener('click', runDiagnostic);
        loadHistory();
    });

    function loadHistory() {
        showState('loading');
        fetch(restBase + 'meo/diagnostic/list', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json.success && json.data && json.data.length > 0) {
                renderLatest(json.data[0]);
                renderHistory(json.data);
                showState('data');
            } else {
                showState('empty');
            }
        })
        .catch(function() { showState('empty'); });
    }

    function showState(s) {
        loadingEl.style.display  = s === 'loading' ? '' : 'none';
        emptyEl.style.display    = s === 'empty' ? '' : 'none';
        summaryEl.style.display  = s === 'data' ? '' : 'none';
        historyEl.style.display  = s === 'data' ? '' : 'none';
    }

    function renderLatest(d) {
        var scoreEl = document.getElementById('meoDiagScore');
        var ringEl  = document.getElementById('meoDiagRingFill');
        var dateEl  = document.getElementById('meoDiagDate');
        var gradesEl = document.getElementById('meoDiagGrades');
        var detailLink = document.getElementById('meoDiagDetailLink');

        var score = d.overall_score || 0;
        if (scoreEl) scoreEl.textContent = score;
        if (dateEl) dateEl.textContent = d.diagnostic_date || '';
        if (detailLink) detailLink.href = detailBase + '?id=' + d.id;

        // Ring animation
        if (ringEl) {
            var circumference = 2 * Math.PI * 66;
            var offset = circumference - (score / 100) * circumference;
            ringEl.style.strokeDashoffset = offset;
            ringEl.style.stroke = gradeColor(d.overall_grade || 'E');
        }

        // Grade cards
        if (gradesEl && d.categories) {
            var html = '';
            ['basic_info', 'posts', 'photos', 'reviews'].forEach(function(key) {
                var cat = d.categories[key] || {};
                var g = cat.grade || '-';
                html += '<div class="meo-diag-grade-card">'
                    + '<div class="meo-diag-grade-card__label">' + (gradeLabels[key] || key) + '</div>'
                    + '<div class="meo-diag-grade-card__grade grade-' + g + '">' + g + '</div>'
                    + '<div class="meo-diag-grade-card__score">' + (cat.score || 0) + '点</div>'
                    + '</div>';
            });
            gradesEl.innerHTML = html;
        }
    }

    function renderHistory(list) {
        var tbody = document.getElementById('meoDiagHistoryBody');
        if (!tbody) return;
        var html = '';
        list.forEach(function(d) {
            var cats = d.categories || {};
            html += '<tr>'
                + '<td>' + esc(d.diagnostic_date || '') + '</td>'
                + '<td><strong>' + (d.overall_score || 0) + '</strong>点</td>'
                + '<td><span class="meo-diag-grade-card__grade grade-' + (d.overall_grade || 'E') + '" style="font-size:16px;width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;">' + (d.overall_grade || '-') + '</span></td>'
                + '<td>' + catBadge(cats.basic_info) + '</td>'
                + '<td>' + catBadge(cats.posts) + '</td>'
                + '<td>' + catBadge(cats.photos) + '</td>'
                + '<td>' + catBadge(cats.reviews) + '</td>'
                + '<td><a href="' + detailBase + '?id=' + d.id + '" class="meo-diag-link">詳細</a></td>'
                + '</tr>';
        });
        tbody.innerHTML = html;
    }

    function catBadge(cat) {
        if (!cat) return '<span style="color:#d1d5db;">-</span>';
        return '<span class="meo-diag-grade-card__grade grade-' + cat.grade + '" style="font-size:13px;width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;border-radius:5px;">' + cat.grade + '</span>';
    }

    function gradeColor(g) {
        var map = { A: '#22c55e', B: '#3b82f6', C: '#f59e0b', D: '#f97316', E: '#ef4444' };
        return map[g] || '#9ca3af';
    }

    // Run diagnostic
    window.runDiagnostic = function() {
        var progress = document.getElementById('meoDiagProgress');
        if (progress) progress.classList.add('active');
        if (runBtn) { runBtn.disabled = true; runBtn.textContent = '診断中...'; }

        fetch(restBase + 'meo/diagnostic/run', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
            body: '{}'
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (progress) progress.classList.remove('active');
            if (runBtn) { runBtn.disabled = false; runBtn.innerHTML = '&#x1F680; 診断を実行する'; }

            if (json.success) {
                showToast('診断が完了しました！');
                loadHistory();
            } else {
                showToast(json.message || '診断に失敗しました。', 'error');
            }
        })
        .catch(function(err) {
            if (progress) progress.classList.remove('active');
            if (runBtn) { runBtn.disabled = false; runBtn.innerHTML = '&#x1F680; 診断を実行する'; }
            showToast('通信エラーが発生しました。', 'error');
        });
    };

    if (runBtn) runBtn.addEventListener('click', window.runDiagnostic);

    function showToast(msg, type) {
        var toast = document.getElementById('meoDiagToast');
        if (!toast) return;
        toast.textContent = msg;
        toast.className = 'meo-diag-toast' + (type === 'error' ? ' meo-diag-toast--error' : '');
        setTimeout(function() { toast.classList.add('show'); }, 10);
        setTimeout(function() { toast.classList.remove('show'); }, 4000);
    }

    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

})();
</script>
