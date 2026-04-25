<?php
/*
Template Name: アンケート分析
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

mimamori_guard_meo_access();

set_query_var( 'gcrev_page_title', 'アンケート分析' );
set_query_var( 'gcrev_page_subtitle', 'AIがアンケート回答を分析し、改善のヒントを提供します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'アンケート分析', 'MEO' ) );

get_header();
?>

<style>
/* ===== page-survey-analysis — Page-specific styles ===== */

.sv-filter-bar {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
}
.sv-filter-bar select {
    flex: 1; min-width: 200px; max-width: 400px;
    padding: 10px 12px; font-size: 14px; font-family: inherit;
    border: 1.5px solid #e5e7eb; border-radius: 8px; background: #f9fafb;
    transition: border-color 0.15s;
}
.sv-filter-bar select:focus {
    outline: none; border-color: var(--mw-primary-blue, #568184); background: #fff;
}

/* Buttons — match other survey pages */
.sv-btn-save {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 24px; background: var(--mw-primary-blue, #568184);
    color: #fff; font-size: 14px; font-weight: 600; border: none; border-radius: 8px;
    cursor: pointer; transition: all 0.25s ease;
}
.sv-btn-save:hover { background: #476C6F; box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.sv-btn-save:active { transform: translateY(0); box-shadow: 0 1px 3px rgba(86,129,132,0.15); }
.sv-btn-save:focus-visible { outline: 2px solid var(--mw-primary-blue, #568184); outline-offset: 2px; }
.sv-btn-save:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

.sv-btn-secondary {
    padding: 8px 16px; font-size: 13px; font-weight: 600;
    background: #fff; color: #374151; border: 1.5px solid #d1d5db; border-radius: 6px;
    cursor: pointer;
}
.sv-btn-secondary:hover { background: #f9fafb; }

/* Analysis cards */
.sv-analysis-card {
    background: var(--mw-bg-primary, #fff);
    border-radius: var(--mw-radius-sm, 12px);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    padding: 24px; margin-bottom: 16px;
    transition: box-shadow 0.15s;
}
.sv-analysis-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }

.sv-analysis-title {
    display: flex; align-items: center; gap: 8px;
    font-size: 16px; font-weight: 700; color: var(--mw-text-heading, #1A2F33);
    margin-bottom: 16px; padding-bottom: 10px;
}
.sv-analysis-title-icon { font-size: 20px; flex-shrink: 0; }
.sv-analysis-title-text { flex: 1; }
.sv-analysis-title-accent {
    height: 3px; border-radius: 2px; margin-top: 10px;
}

/* Lists */
.sv-analysis-list {
    list-style: none; padding: 0; margin: 0;
}
.sv-analysis-list li {
    padding: 8px 0; font-size: 14px; line-height: 1.7; color: #374151;
    border-bottom: 1px solid #f3f4f6;
}
.sv-analysis-list li:last-child { border-bottom: none; }
.sv-analysis-list.bullet li::before {
    content: '•'; color: #3b82f6; font-weight: 700; margin-right: 8px;
}
.sv-analysis-list.numbered { counter-reset: sv-num; }
.sv-analysis-list.numbered li { display: flex; align-items: flex-start; gap: 10px; }
.sv-analysis-list.numbered li::before {
    counter-increment: sv-num;
    content: counter(sv-num);
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 24px; height: 24px; border-radius: 50%;
    background: #d1fae5; color: #065f46;
    font-size: 12px; font-weight: 700; flex-shrink: 0; margin-top: 2px;
}

/* Keyword tags */
.sv-analysis-tags {
    display: flex; flex-wrap: wrap; gap: 8px;
}
.sv-analysis-tag {
    display: inline-block; padding: 6px 14px;
    font-size: 13px; font-weight: 600; color: #4338ca;
    background: #e0e7ff; border-radius: 20px;
}

/* Overall comment */
.sv-analysis-comment {
    background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;
    padding: 20px; font-size: 14px; line-height: 1.8; color: #374151;
    white-space: pre-wrap;
}

/* Improvement areas — amber accent */
.sv-analysis-list.amber li::before {
    content: '▸'; color: #d97706; font-weight: 700; margin-right: 8px;
}

@media (max-width: 768px) {
    .sv-filter-bar { flex-direction: column; align-items: stretch; }
    .sv-filter-bar select { max-width: 100%; }
}
</style>

<div class="content-area">

    <!-- Survey selector -->
    <div class="sv-form-card">
        <div class="sv-filter-bar">
            <select id="sv-analysis-survey">
                <option value="">読み込み中...</option>
            </select>
            <button type="button" class="sv-btn-save" id="sv-analysis-btn">AI分析を実行</button>
            <button type="button" class="sv-btn-secondary" id="sv-analysis-refresh" style="display:none;">再分析する</button>
        </div>
        <div id="sv-analysis-cache-note" style="display:none;font-size:12px;color:#9ca3af;margin-top:8px;"></div>
    </div>

    <!-- Placeholder -->
    <div id="sv-analysis-placeholder">
        <div class="sv-empty">
            <div class="sv-empty-icon">📊</div>
            <div class="sv-empty-text">アンケートを選択して「AI分析を実行」を押してください。</div>
        </div>
    </div>

    <!-- Loading -->
    <div id="sv-analysis-loading" style="display:none;">
        <div class="sv-form-card" style="text-align:center;padding:48px;">
            <div style="font-size:32px;margin-bottom:12px;">🔍</div>
            <div style="font-size:15px;font-weight:600;color:#374151;">AIが回答データを分析しています...</div>
            <div style="font-size:13px;color:#9ca3af;margin-top:8px;">最大30秒ほどかかる場合があります</div>
        </div>
    </div>

    <!-- Results -->
    <div id="sv-analysis-results" style="display:none;"></div>

</div>

<!-- Toast -->
<div class="sv-toast" id="sv-toast"></div>

<script>
(function() {
    'use strict';

    var API_BASE = <?php echo wp_json_encode( rest_url( 'gcrev/v1/survey/' ) ); ?>;
    var WP_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

    // =====================================================
    // DOM
    // =====================================================
    var surveySelect   = document.getElementById('sv-analysis-survey');
    var btnAnalysis    = document.getElementById('sv-analysis-btn');
    var btnRefresh     = document.getElementById('sv-analysis-refresh');
    var cacheNote      = document.getElementById('sv-analysis-cache-note');
    var placeholder    = document.getElementById('sv-analysis-placeholder');
    var loading        = document.getElementById('sv-analysis-loading');
    var resultsEl      = document.getElementById('sv-analysis-results');
    var toastEl        = document.getElementById('sv-toast');

    // =====================================================
    // API helpers
    // =====================================================
    function apiGet(path) {
        return fetch(API_BASE + path, {
            headers: { 'X-WP-Nonce': WP_NONCE },
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); });
    }

    function toast(msg, type) {
        toastEl.textContent = msg;
        toastEl.className = 'sv-toast sv-toast-' + (type || 'success') + ' show';
        setTimeout(function() { toastEl.classList.remove('show'); }, 3000);
    }

    function esc(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    // =====================================================
    // Load survey filter
    // =====================================================
    function loadSurveyFilter() {
        apiGet('list').then(function(data) {
            var surveys = data.surveys || [];
            if (surveys.length === 0) {
                surveySelect.innerHTML = '<option value="">アンケートがありません</option>';
                btnAnalysis.disabled = true;
                return;
            }
            var html = '<option value="">アンケートを選択してください</option>';
            surveys.forEach(function(s) {
                html += '<option value="' + s.id + '">' + esc(s.title) + '</option>';
            });
            surveySelect.innerHTML = html;
        }).catch(function() {
            surveySelect.innerHTML = '<option value="">読み込みに失敗しました</option>';
            btnAnalysis.disabled = true;
        });
    }

    // =====================================================
    // Run analysis
    // =====================================================
    function showView(view) {
        placeholder.style.display = view === 'placeholder' ? 'block' : 'none';
        loading.style.display     = view === 'loading'     ? 'block' : 'none';
        resultsEl.style.display   = view === 'results'     ? 'block' : 'none';
    }

    function runAnalysis(surveyId) {
        showView('loading');
        btnAnalysis.disabled = true;
        btnRefresh.style.display = 'none';
        cacheNote.style.display = 'none';

        apiGet('analysis?survey_id=' + encodeURIComponent(surveyId)).then(function(data) {
            btnAnalysis.disabled = false;
            if (!data.success) {
                showView('placeholder');
                toast(data.message || '分析に失敗しました', 'error');
                return;
            }
            if (!data.analysis) {
                showView('placeholder');
                placeholder.innerHTML =
                    '<div class="sv-empty">' +
                    '<div class="sv-empty-icon">📭</div>' +
                    '<div class="sv-empty-text">' + esc(data.message || '分析に必要な回答データがありません。') + '</div>' +
                    '</div>';
                return;
            }

            renderResults(data.analysis);
            showView('results');

            // Cache indicator
            btnRefresh.style.display = 'inline-flex';
            if (data.cached) {
                cacheNote.style.display = 'block';
                cacheNote.textContent = 'キャッシュされた結果を表示中';
            } else {
                cacheNote.style.display = 'none';
            }
        }).catch(function() {
            btnAnalysis.disabled = false;
            showView('placeholder');
            toast('通信エラーが発生しました', 'error');
        });
    }

    // =====================================================
    // Render results
    // =====================================================
    function renderResults(analysis) {
        var html = '';

        // 1. 回答傾向
        if (analysis.satisfaction_trends && analysis.satisfaction_trends.length) {
            html += buildCardOpen('📊', '回答傾向', '#3b82f6');
            html += '<ul class="sv-analysis-list bullet">';
            analysis.satisfaction_trends.forEach(function(item) {
                html += '<li>' + esc(item) + '</li>';
            });
            html += '</ul>';
            html += buildCardClose();
        }

        // 2. 満足ポイントTOP5
        if (analysis.satisfaction_points && analysis.satisfaction_points.length) {
            html += buildCardOpen('⭐', '満足ポイントTOP5', '#059669');
            html += '<ul class="sv-analysis-list numbered">';
            analysis.satisfaction_points.forEach(function(item) {
                html += '<li><span>' + esc(item) + '</span></li>';
            });
            html += '</ul>';
            html += buildCardClose();
        }

        // 3. 改善の余地
        if (analysis.improvement_areas && analysis.improvement_areas.length) {
            html += buildCardOpen('💡', '改善の余地', '#d97706');
            html += '<ul class="sv-analysis-list amber">';
            analysis.improvement_areas.forEach(function(item) {
                html += '<li>' + esc(item) + '</li>';
            });
            html += '</ul>';
            html += buildCardClose();
        }

        // 4. 頻出キーワード
        if (analysis.frequent_keywords && analysis.frequent_keywords.length) {
            html += buildCardOpen('🏷️', '頻出キーワード', '#4338ca');
            html += '<div class="sv-analysis-tags">';
            analysis.frequent_keywords.forEach(function(kw) {
                html += '<span class="sv-analysis-tag">' + esc(kw) + '</span>';
            });
            html += '</div>';
            html += buildCardClose();
        }

        // 5. 総合コメント
        if (analysis.overall_comment) {
            html += buildCardOpen('📝', '総合コメント', '#059669');
            html += '<div class="sv-analysis-comment">' + esc(analysis.overall_comment) + '</div>';
            html += buildCardClose();
        }

        resultsEl.innerHTML = html;
    }

    function buildCardOpen(icon, title, accentColor) {
        return '<div class="sv-analysis-card">' +
            '<div class="sv-analysis-title">' +
            '<span class="sv-analysis-title-icon">' + icon + '</span>' +
            '<span class="sv-analysis-title-text">' + esc(title) + '</span>' +
            '</div>' +
            '<div class="sv-analysis-title-accent" style="background:' + accentColor + ';margin-top:-6px;margin-bottom:16px;"></div>';
    }

    function buildCardClose() {
        return '</div>';
    }

    // =====================================================
    // Events
    // =====================================================
    btnAnalysis.addEventListener('click', function() {
        var surveyId = surveySelect.value;
        if (!surveyId) {
            toast('アンケートを選択してください', 'error');
            return;
        }
        runAnalysis(surveyId);
    });

    btnRefresh.addEventListener('click', function() {
        var surveyId = surveySelect.value;
        if (!surveyId) {
            toast('アンケートを選択してください', 'error');
            return;
        }
        runAnalysis(surveyId);
    });

    // =====================================================
    // Init
    // =====================================================
    loadSurveyFilter();
})();
</script>

<?php get_footer(); ?>
