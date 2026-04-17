<?php
/*
Template Name: MEO診断レポート詳細
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

mimamori_guard_meo_access();

$current_user = wp_get_current_user();
$user_id = $current_user->ID;

set_query_var( 'gcrev_page_title', '診断レポート詳細' );
set_query_var( 'gcrev_page_subtitle', '' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '診断レポート詳細', 'MEO診断' ) );

get_header();

$report_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
?>

<style>
/* ============================================================
   page-meo-diagnosis-detail — MEO診断レポート詳細
   ============================================================ */

/* Header bar */
.meo-diag-detail-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.meo-diag-detail-header__left {
    display: flex; align-items: center; gap: 14px;
}
.meo-diag-detail-header__date {
    font-size: 13px; color: #6b7280; background: #f3f4f6;
    border-radius: 6px; padding: 4px 10px;
}
.meo-diag-detail-header__grade {
    font-size: 32px; font-weight: 800; display: inline-flex;
    align-items: center; justify-content: center; width: 52px; height: 52px;
    border-radius: 12px; color: #fff;
}
.meo-diag-pdf-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border: 1px solid #d0d5dd; border-radius: 8px;
    font-size: 13px; font-weight: 500; cursor: pointer;
    background: #fff; color: #344054; transition: all 0.15s;
}
.meo-diag-pdf-btn:hover { background: #f9fafb; border-color: #98a2b3; }

/* Score summary bar */
.meo-diag-score-bar {
    display: grid; grid-template-columns: 180px 1fr; gap: 24px;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
    padding: 24px 28px; margin-bottom: 24px; align-items: center;
}
@media (max-width: 768px) { .meo-diag-score-bar { grid-template-columns: 1fr; text-align: center; } }

.meo-diag-score-ring { position: relative; width: 140px; height: 140px; margin: 0 auto; }
.meo-diag-score-ring svg { width: 140px; height: 140px; transform: rotate(-90deg); }
.meo-diag-score-ring__bg { fill: none; stroke: #e5e7eb; stroke-width: 10; }
.meo-diag-score-ring__fill { fill: none; stroke-width: 10; stroke-linecap: round; }
.meo-diag-score-ring__center {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); text-align: center;
}
.meo-diag-score-ring__val { font-size: 32px; font-weight: 800; color: #1a1a1a; display: block; }
.meo-diag-score-ring__sub { font-size: 11px; color: #9ca3af; }

.meo-diag-cat-grades { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
@media (max-width: 768px) { .meo-diag-cat-grades { grid-template-columns: repeat(2, 1fr); } }
.meo-diag-cat-card {
    background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 14px; text-align: center;
}
.meo-diag-cat-card__label { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
.meo-diag-cat-card__grade {
    font-size: 24px; font-weight: 800; display: inline-flex;
    align-items: center; justify-content: center; width: 38px; height: 38px;
    border-radius: 8px; color: #fff;
}
.meo-diag-cat-card__score { font-size: 11px; color: #9ca3af; margin-top: 2px; }

/* Section card */
.meo-diag-section {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
    margin-bottom: 24px; overflow: hidden;
}
.meo-diag-section__header {
    padding: 18px 24px; border-bottom: 1px solid #e5e7eb;
    font-size: 15px; font-weight: 700; color: #1a1a1a;
    display: flex; align-items: center; gap: 8px;
}
.meo-diag-section__body { padding: 24px; }

/* Summary text */
.meo-diag-summary-text {
    font-size: 14px; line-height: 1.8; color: #374151; margin-bottom: 20px;
}
.meo-diag-points-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 768px) { .meo-diag-points-grid { grid-template-columns: 1fr; } }
.meo-diag-points-card {
    border-radius: 10px; padding: 16px 20px;
}
.meo-diag-points-card--good { background: #f0fdf4; border: 1px solid #bbf7d0; }
.meo-diag-points-card--bad  { background: #fef2f2; border: 1px solid #fecaca; }
.meo-diag-points-card__title {
    font-size: 13px; font-weight: 700; margin-bottom: 8px;
}
.meo-diag-points-card--good .meo-diag-points-card__title { color: #16a34a; }
.meo-diag-points-card--bad .meo-diag-points-card__title { color: #dc2626; }
.meo-diag-points-list { list-style: none; padding: 0; margin: 0; }
.meo-diag-points-list li {
    font-size: 13px; color: #374151; padding: 4px 0; padding-left: 16px;
    position: relative; line-height: 1.6;
}
.meo-diag-points-list li::before {
    content: ''; position: absolute; left: 0; top: 11px;
    width: 6px; height: 6px; border-radius: 50%;
}
.meo-diag-points-card--good .meo-diag-points-list li::before { background: #22c55e; }
.meo-diag-points-card--bad .meo-diag-points-list li::before { background: #ef4444; }

/* Recommendations */
.meo-diag-rec-card {
    border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px 20px; margin-bottom: 12px;
    border-left: 4px solid #e5e7eb;
}
.meo-diag-rec-card--high { border-left-color: #ef4444; }
.meo-diag-rec-card--medium { border-left-color: #f59e0b; }
.meo-diag-rec-card--low { border-left-color: #3b82f6; }
.meo-diag-rec-card__title { font-size: 14px; font-weight: 700; color: #1a1a1a; margin-bottom: 4px; }
.meo-diag-rec-card__desc { font-size: 13px; color: #6b7280; line-height: 1.6; }
.meo-diag-rec-badge {
    display: inline-block; font-size: 10px; font-weight: 700; border-radius: 4px;
    padding: 2px 6px; margin-right: 6px; color: #fff;
}
.meo-diag-rec-badge--high { background: #ef4444; }
.meo-diag-rec-badge--medium { background: #f59e0b; }
.meo-diag-rec-badge--low { background: #3b82f6; }

/* Check items */
.meo-diag-check-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 0;
}
@media (max-width: 768px) { .meo-diag-check-grid { grid-template-columns: 1fr; } }
.meo-diag-check-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 20px; border-bottom: 1px solid #f3f4f6;
}
.meo-diag-check-item:nth-child(odd) { border-right: 1px solid #f3f4f6; }
.meo-diag-check-status {
    flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: #fff;
}
.meo-diag-check-status--ok { background: #22c55e; }
.meo-diag-check-status--warning { background: #f59e0b; }
.meo-diag-check-status--caution { background: #f97316; }
.meo-diag-check-status--critical { background: #ef4444; }
.meo-diag-check-label { font-size: 13px; font-weight: 600; color: #1a1a1a; }
.meo-diag-check-desc { font-size: 12px; color: #9ca3af; }
.meo-diag-check-detail { font-size: 12px; color: #6b7280; margin-top: 2px; }

/* Review bars */
.meo-diag-review-summary { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; margin-bottom: 16px; }
.meo-diag-review-big { font-size: 36px; font-weight: 800; color: #1a1a1a; }
.meo-diag-review-stars { font-size: 20px; color: #f59e0b; letter-spacing: 1px; }
.meo-diag-review-count { font-size: 13px; color: #6b7280; }
.meo-diag-bar-row { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 12px; color: #6b7280; }
.meo-diag-bar-label { width: 24px; text-align: right; flex-shrink: 0; }
.meo-diag-bar-track { flex: 1; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; max-width: 300px; }
.meo-diag-bar-fill { height: 100%; background: #f59e0b; border-radius: 4px; }
.meo-diag-bar-count { width: 50px; text-align: right; flex-shrink: 0; font-size: 11px; color: #9ca3af; }

/* Keywords table */
.meo-diag-kw-table { width: 100%; border-collapse: collapse; }
.meo-diag-kw-table th {
    background: #f9fafb; font-size: 12px; font-weight: 600; color: #6b7280;
    padding: 10px 14px; text-align: left; border-bottom: 1px solid #e5e7eb;
}
.meo-diag-kw-table td {
    padding: 12px 14px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #1a1a1a;
}
.meo-diag-kw-table tr:last-child td { border-bottom: none; }

/* AIO score cells */
.meo-diag-aio-val { font-weight: 700; }
.meo-diag-aio-na { color: #d1d5db; }

/* Navigation link */
.meo-diag-nav-link {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 13px; color: #568184; text-decoration: none;
    margin-top: 12px;
}
.meo-diag-nav-link:hover { text-decoration: underline; }

/* Loading */
.meo-diag-detail-loading { text-align: center; padding: 60px; color: #9ca3af; font-size: 14px; }

/* Print-friendly */
@media print {
    .meo-diag-pdf-btn, .meo-diag-nav-link, .sidebar, .topbar, .mw-chat { display: none !important; }
    .meo-diag-section { page-break-inside: avoid; }
}

/* Grade colors (reuse) */
.grade-A { background: #22c55e; }
.grade-B { background: #3b82f6; }
.grade-C { background: #f59e0b; }
.grade-D { background: #f97316; }
.grade-E { background: #ef4444; }
</style>

<div class="content-area">

    <!-- Loading -->
    <div class="meo-diag-detail-loading" id="diagDetailLoading">レポートデータを読み込み中...</div>

    <!-- Report content (populated by JS) -->
    <div id="meo-report-content" style="display:none;">

        <!-- Header -->
        <div class="meo-diag-detail-header">
            <div class="meo-diag-detail-header__left">
                <span class="meo-diag-detail-header__grade" id="diagHeaderGrade">-</span>
                <div>
                    <div style="font-size:18px; font-weight:700; color:#1a1a1a;">MEO診断レポート</div>
                    <div class="meo-diag-detail-header__date" id="diagHeaderDate"></div>
                </div>
            </div>
            <button class="meo-diag-pdf-btn" id="diagPdfBtn" type="button" onclick="downloadPdf()">
                &#x1F4E5; PDFダウンロード
            </button>
        </div>

        <!-- Score bar -->
        <div class="meo-diag-score-bar">
            <div class="meo-diag-score-ring">
                <svg viewBox="0 0 140 140">
                    <circle class="meo-diag-score-ring__bg" cx="70" cy="70" r="58"/>
                    <circle class="meo-diag-score-ring__fill" cx="70" cy="70" r="58"
                            stroke-dasharray="364.42" stroke-dashoffset="364.42" id="diagScoreRingFill"/>
                </svg>
                <div class="meo-diag-score-ring__center">
                    <span class="meo-diag-score-ring__val" id="diagScoreVal">-</span>
                    <span class="meo-diag-score-ring__sub">100点中</span>
                </div>
            </div>
            <div class="meo-diag-cat-grades" id="diagCatGrades"></div>
        </div>

        <!-- AI Summary -->
        <div class="meo-diag-section" id="diagSummarySection">
            <div class="meo-diag-section__header">&#x1F4DD; 総評</div>
            <div class="meo-diag-section__body">
                <div class="meo-diag-summary-text" id="diagSummaryText"></div>
                <div class="meo-diag-points-grid" id="diagPointsGrid"></div>
            </div>
        </div>

        <!-- Recommendations -->
        <div class="meo-diag-section" id="diagRecsSection" style="display:none;">
            <div class="meo-diag-section__header">&#x1F4A1; 改善推奨アクション</div>
            <div class="meo-diag-section__body" id="diagRecsBody"></div>
        </div>

        <!-- Basic Info Check -->
        <div class="meo-diag-section" id="diagBasicSection">
            <div class="meo-diag-section__header">&#x1F4CB; 基本情報</div>
            <div class="meo-diag-check-grid" id="diagBasicGrid"></div>
        </div>

        <!-- Posts Check -->
        <div class="meo-diag-section" id="diagPostsSection">
            <div class="meo-diag-section__header">&#x270F;&#xFE0F; 投稿</div>
            <div class="meo-diag-check-grid" id="diagPostsGrid"></div>
            <div style="padding: 0 24px 16px;">
                <a href="<?php echo esc_url( home_url( '/tools/gbp-posts/' ) ); ?>" class="meo-diag-nav-link">&#x2192; GBP投稿管理へ</a>
            </div>
        </div>

        <!-- Photos Check -->
        <div class="meo-diag-section" id="diagPhotosSection">
            <div class="meo-diag-section__header">&#x1F4F7; 写真</div>
            <div class="meo-diag-check-grid" id="diagPhotosGrid"></div>
        </div>

        <!-- Reviews Check -->
        <div class="meo-diag-section" id="diagReviewsSection">
            <div class="meo-diag-section__header">&#x1F4AC; レビュー</div>
            <div class="meo-diag-section__body">
                <div id="diagReviewSummary"></div>
                <div class="meo-diag-check-grid" id="diagReviewsGrid" style="margin-top:16px;"></div>
                <a href="<?php echo esc_url( home_url( '/tools/review-management/' ) ); ?>" class="meo-diag-nav-link" style="margin-left:24px;">&#x2192; 口コミ管理へ</a>
            </div>
        </div>

        <!-- Priority Keywords -->
        <div class="meo-diag-section" id="diagKeywordsSection" style="display:none;">
            <div class="meo-diag-section__header">&#x1F50D; 優先対策キーワード</div>
            <div style="overflow-x:auto;">
                <table class="meo-diag-kw-table" id="diagKeywordsTable">
                    <thead>
                        <tr><th>キーワード</th><th>マップ順位</th><th>地域順位</th></tr>
                    </thead>
                    <tbody id="diagKeywordsBody"></tbody>
                </table>
            </div>
            <div style="padding: 0 24px 16px;">
                <a href="<?php echo esc_url( home_url( '/map-rank/' ) ); ?>" class="meo-diag-nav-link">&#x2192; マップ順位チェックへ</a>
            </div>
        </div>

        <!-- AIO Scores -->
        <div class="meo-diag-section" id="diagAioSection" style="display:none;">
            <div class="meo-diag-section__header">&#x1F916; AI検索スコア</div>
            <div style="overflow-x:auto;">
                <table class="meo-diag-kw-table" id="diagAioTable">
                    <thead>
                        <tr><th>キーワード</th><th>ChatGPT</th><th>Gemini</th><th>Google AI</th></tr>
                    </thead>
                    <tbody id="diagAioBody"></tbody>
                </table>
            </div>
            <div style="padding: 0 24px 16px;">
                <a href="<?php echo esc_url( home_url( '/ai-report/' ) ); ?>" class="meo-diag-nav-link">&#x2192; AI検索レポートへ</a>
            </div>
        </div>

    </div><!-- /#meo-report-content -->

</div><!-- /.content-area -->

<?php get_footer(); ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer></script>

<script>
(function() {
    'use strict';

    var restBase = <?php echo wp_json_encode( esc_url_raw( rest_url( 'gcrev/v1/' ) ) ); ?>;
    var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    var reportId = <?php echo (int) $report_id; ?>;

    var catLabels = { basic_info: '基本情報', posts: '投稿', photos: '写真', reviews: 'レビュー' };
    var statusIcons = { ok: '&#x2713;', warning: '!', caution: '!', critical: '&#x2717;' };
    var reportData = null;

    document.addEventListener('DOMContentLoaded', function() {
        if (!reportId) {
            document.getElementById('diagDetailLoading').textContent = 'レポートIDが指定されていません。';
            return;
        }
        loadReport();
    });

    function loadReport() {
        fetch(restBase + 'meo/diagnostic/' + reportId, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            document.getElementById('diagDetailLoading').style.display = 'none';
            if (json.success && json.data) {
                reportData = json.data;
                document.getElementById('meo-report-content').style.display = '';
                renderAll(json.data);
            } else {
                document.getElementById('diagDetailLoading').style.display = '';
                document.getElementById('diagDetailLoading').textContent = json.message || 'レポートが見つかりません。';
            }
        })
        .catch(function() {
            document.getElementById('diagDetailLoading').textContent = '通信エラーが発生しました。';
        });
    }

    function renderAll(d) {
        // Header
        var hGrade = document.getElementById('diagHeaderGrade');
        hGrade.textContent = d.overall_grade || '-';
        hGrade.className = 'meo-diag-detail-header__grade grade-' + (d.overall_grade || 'E');
        document.getElementById('diagHeaderDate').textContent = d.diagnostic_date || '';

        // Score ring
        var score = d.overall_score || 0;
        document.getElementById('diagScoreVal').textContent = score;
        var ringEl = document.getElementById('diagScoreRingFill');
        var circ = 2 * Math.PI * 58;
        ringEl.style.strokeDashoffset = circ - (score / 100) * circ;
        ringEl.style.stroke = gradeColor(d.overall_grade);

        // Category grades
        var catHtml = '';
        ['basic_info','posts','photos','reviews'].forEach(function(k) {
            var c = (d.categories || {})[k] || {};
            catHtml += '<div class="meo-diag-cat-card">'
                + '<div class="meo-diag-cat-card__label">' + (catLabels[k]||k) + '</div>'
                + '<div class="meo-diag-cat-card__grade grade-' + (c.grade||'E') + '">' + (c.grade||'-') + '</div>'
                + '<div class="meo-diag-cat-card__score">' + (c.score||0) + '点</div>'
                + '</div>';
        });
        document.getElementById('diagCatGrades').innerHTML = catHtml;

        // Summary
        document.getElementById('diagSummaryText').textContent = d.summary_text || '';
        var ptsHtml = '';
        if (d.good_points && d.good_points.length) {
            ptsHtml += '<div class="meo-diag-points-card meo-diag-points-card--good">';
            ptsHtml += '<div class="meo-diag-points-card__title">&#x2705; 良い点</div><ul class="meo-diag-points-list">';
            d.good_points.forEach(function(p) { ptsHtml += '<li>' + esc(p) + '</li>'; });
            ptsHtml += '</ul></div>';
        }
        if (d.improvement_points && d.improvement_points.length) {
            ptsHtml += '<div class="meo-diag-points-card meo-diag-points-card--bad">';
            ptsHtml += '<div class="meo-diag-points-card__title">&#x26A0;&#xFE0F; 改善点</div><ul class="meo-diag-points-list">';
            d.improvement_points.forEach(function(p) { ptsHtml += '<li>' + esc(p) + '</li>'; });
            ptsHtml += '</ul></div>';
        }
        document.getElementById('diagPointsGrid').innerHTML = ptsHtml;

        // Recommendations
        if (d.recommendations && d.recommendations.length) {
            var recHtml = '';
            d.recommendations.forEach(function(r) {
                var p = r.priority || 'low';
                recHtml += '<div class="meo-diag-rec-card meo-diag-rec-card--' + p + '">'
                    + '<div class="meo-diag-rec-card__title"><span class="meo-diag-rec-badge meo-diag-rec-badge--' + p + '">'
                    + ({high:'高',medium:'中',low:'低'}[p]||p) + '</span>' + esc(r.title || '') + '</div>'
                    + '<div class="meo-diag-rec-card__desc">' + esc(r.description || '') + '</div></div>';
            });
            document.getElementById('diagRecsBody').innerHTML = recHtml;
            document.getElementById('diagRecsSection').style.display = '';
        }

        // Check items
        renderCheckItems('diagBasicGrid', (d.categories||{}).basic_info);
        renderCheckItems('diagPostsGrid', (d.categories||{}).posts);
        renderCheckItems('diagPhotosGrid', (d.categories||{}).photos);
        renderCheckItems('diagReviewsGrid', (d.categories||{}).reviews);

        // Review summary
        if (d.review_summary) {
            var rs = d.review_summary;
            var rsHtml = '<div class="meo-diag-review-summary">'
                + '<span class="meo-diag-review-big">' + (rs.average_rating ? parseFloat(rs.average_rating).toFixed(1) : '-') + '</span>'
                + '<span class="meo-diag-review-stars">' + stars(rs.average_rating || 0) + '</span>'
                + '<span class="meo-diag-review-count">' + (rs.total || 0) + '件の口コミ</span>'
                + '</div>';
            // Distribution bars
            if (rs.distribution) {
                for (var s = 5; s >= 1; s--) {
                    var cnt = parseInt(rs.distribution[String(s)] || 0);
                    var pct = rs.total > 0 ? Math.round((cnt / rs.total) * 100) : 0;
                    rsHtml += '<div class="meo-diag-bar-row">'
                        + '<div class="meo-diag-bar-label">' + s + '&#x2605;</div>'
                        + '<div class="meo-diag-bar-track"><div class="meo-diag-bar-fill" style="width:' + pct + '%"></div></div>'
                        + '<div class="meo-diag-bar-count">' + cnt + '件 (' + pct + '%)</div></div>';
                }
            }
            document.getElementById('diagReviewSummary').innerHTML = rsHtml;
        }

        // Keywords
        if (d.priority_keywords && d.priority_keywords.length) {
            var kwHtml = '';
            d.priority_keywords.forEach(function(kw) {
                kwHtml += '<tr><td><strong>' + esc(kw.keyword) + '</strong></td>'
                    + '<td>' + rankCell(kw.maps_rank) + '</td>'
                    + '<td>' + rankCell(kw.finder_rank) + '</td></tr>';
            });
            document.getElementById('diagKeywordsBody').innerHTML = kwHtml;
            document.getElementById('diagKeywordsSection').style.display = '';
        }

        // AIO
        if (d.aio_scores && d.aio_scores.length) {
            var aioHtml = '';
            d.aio_scores.forEach(function(a) {
                aioHtml += '<tr><td><strong>' + esc(a.keyword) + '</strong></td>'
                    + '<td>' + aioCell(a.chatgpt) + '</td>'
                    + '<td>' + aioCell(a.gemini) + '</td>'
                    + '<td>' + aioCell(a.google_ai) + '</td></tr>';
            });
            document.getElementById('diagAioBody').innerHTML = aioHtml;
            document.getElementById('diagAioSection').style.display = '';
        }
    }

    function renderCheckItems(gridId, cat) {
        var el = document.getElementById(gridId);
        if (!el || !cat || !cat.items) return;
        var html = '';
        cat.items.forEach(function(item) {
            html += '<div class="meo-diag-check-item">'
                + '<div class="meo-diag-check-status meo-diag-check-status--' + item.status + '">'
                + (statusIcons[item.status] || '?') + '</div>'
                + '<div>'
                + '<div class="meo-diag-check-label">' + esc(item.label) + '</div>'
                + '<div class="meo-diag-check-desc">' + esc(item.description) + '</div>'
                + '<div class="meo-diag-check-detail">' + esc(item.detail) + '</div>'
                + '</div></div>';
        });
        el.innerHTML = html;
    }

    function rankCell(v) {
        if (v === null || v === undefined) return '<span class="meo-diag-aio-na">未取得</span>';
        return '<strong>' + v + '</strong>位';
    }
    function aioCell(v) {
        if (v === null || v === undefined) return '<span class="meo-diag-aio-na">-</span>';
        return '<span class="meo-diag-aio-val">' + v + '%</span>';
    }
    function stars(val) {
        var r = Math.round(parseFloat(val) || 0);
        var s = '';
        for (var i = 1; i <= 5; i++) s += (i <= r) ? '\u2605' : '\u2606';
        return s;
    }
    function gradeColor(g) {
        return { A:'#22c55e', B:'#3b82f6', C:'#f59e0b', D:'#f97316', E:'#ef4444' }[g] || '#9ca3af';
    }
    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // PDF download
    window.downloadPdf = function() {
        var el = document.getElementById('meo-report-content');
        if (!el || typeof html2pdf === 'undefined') {
            alert('PDF生成ライブラリの読み込みに失敗しました。ページを再読み込みしてください。');
            return;
        }
        var date = reportData ? reportData.diagnostic_date : 'report';
        var opt = {
            margin: [10, 10, 10, 10],
            filename: 'MEO診断レポート_' + date + '.pdf',
            image: { type: 'jpeg', quality: 0.95 },
            html2canvas: { scale: 2, useCORS: true, scrollY: 0 },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
        };
        // Hide buttons during PDF generation
        var pdfBtn = document.getElementById('diagPdfBtn');
        var navLinks = document.querySelectorAll('.meo-diag-nav-link');
        if (pdfBtn) pdfBtn.style.display = 'none';
        navLinks.forEach(function(l) { l.style.display = 'none'; });

        html2pdf().set(opt).from(el).save().then(function() {
            if (pdfBtn) pdfBtn.style.display = '';
            navLinks.forEach(function(l) { l.style.display = ''; });
        });
    };

})();
</script>
