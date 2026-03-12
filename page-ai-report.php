<?php
/**
 * Template Name: AI検索対策
 * Description: AI検索・AI回答に対するサイトの伝わりやすさ・掲載状況を可視化するページ
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'AI検索対策' );
set_query_var( 'gcrev_page_subtitle', 'AIに自社がどれだけ伝わりやすいか、言及されやすいかを確認できます。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'AI検索対策', '集客のようす' ) );

get_header();
?>
<style>
/* =========================================================
   AIレポート — スタイル
   ========================================================= */

/* サマリーカード */
.air-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}
.air-summary-card {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 24px;
    text-align: center;
    transition: box-shadow 0.2s, transform 0.2s;
}
.air-summary-card:hover {
    box-shadow: var(--mw-shadow-float);
    transform: translateY(-1px);
}
.air-summary-card__label {
    font-size: 13px;
    color: var(--mw-text-tertiary);
    margin-bottom: 8px;
}
.air-summary-card__value {
    font-size: 36px;
    font-weight: 700;
    color: var(--mw-text-heading);
    line-height: 1.2;
}
.air-summary-card__value--accent { color: var(--mw-primary-blue); }
.air-summary-card__sub {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-top: 4px;
}
.air-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}
.air-badge--high   { background: rgba(201,90,79,0.12); color: #C95A4F; }
.air-badge--medium { background: rgba(201,168,76,0.15); color: #C9A84C; }
.air-badge--low    { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.air-badge--none   { background: var(--mw-bg-secondary); color: var(--mw-text-tertiary); }

/* セクション */
.air-section {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 28px;
    margin-bottom: 28px;
}
.air-section__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.air-section__title {
    font-size: 16px;
    font-weight: 600;
    color: var(--mw-text-heading);
    margin: 0;
}
.air-section__note {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-top: 4px;
}
.air-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 1px solid var(--mw-border-light);
    background: var(--mw-bg-primary);
    border-radius: 8px;
    font-size: 14px;
    color: var(--mw-text-primary);
    cursor: pointer;
    transition: background 0.15s;
}
.air-btn:hover { background: var(--mw-bg-secondary); }
.air-btn--primary {
    background: var(--mw-primary-blue);
    color: #fff;
    border-color: var(--mw-primary-blue);
}
.air-btn--primary:hover { opacity: 0.9; }

/* 診断グリッド */
.air-diagnosis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}
.air-diagnosis-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px;
    border: 1px solid var(--mw-border-light);
    border-radius: 12px;
    background: var(--mw-bg-primary);
}
.air-diagnosis-item__icon {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 700;
}
.air-diagnosis-item__icon--ok             { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.air-diagnosis-item__icon--caution        { background: rgba(201,168,76,0.15); color: #C9A84C; }
.air-diagnosis-item__icon--not_addressed  { background: rgba(201,90,79,0.12); color: #C95A4F; }
.air-diagnosis-item__icon--unknown        { background: rgba(150,150,150,0.1); color: #999; }
.air-diagnosis-item__icon--fetch_failed   { background: rgba(201,90,79,0.12); color: #C95A4F; }
.air-diagnosis-item__body { flex: 1; min-width: 0; }
.air-diagnosis-item__label {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-primary);
    margin-bottom: 2px;
}
.air-diagnosis-item__score {
    font-size: 12px;
    color: var(--mw-text-tertiary);
}
.air-diagnosis-item__comment {
    font-size: 13px;
    color: var(--mw-text-secondary);
    margin-top: 4px;
    line-height: 1.5;
}

/* 掲載チェックテーブル */
.air-mention-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.air-mention-table thead {
    background: var(--mw-bg-secondary);
    border-bottom: 2px solid var(--mw-border-light);
}
.air-mention-table th {
    padding: 10px 14px;
    font-weight: 600;
    color: var(--mw-text-secondary);
    font-size: 13px;
    text-align: center;
}
.air-mention-table th:first-child { text-align: left; }
.air-mention-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--mw-border-light);
    text-align: center;
    vertical-align: middle;
}
.air-mention-table td:first-child { text-align: left; font-weight: 500; }
.air-mention-table tbody tr:hover { background: var(--mw-bg-secondary); }

/* ステータスバッジ */
.air-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
}
.air-status--success_mentioned     { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.air-status--success_not_mentioned { background: rgba(150,150,150,0.1); color: #777; }
.air-status--not_run               { background: var(--mw-bg-secondary); color: var(--mw-text-tertiary); }
.air-status--fetch_failed          { background: rgba(201,90,79,0.12); color: #C95A4F; }
.air-status--parse_failed          { background: rgba(201,168,76,0.15); color: #C9A84C; }
.air-status--unsupported           { background: var(--mw-bg-tertiary); color: var(--mw-text-tertiary); }
.air-status--no_answer             { background: rgba(150,150,150,0.1); color: #999; }
.air-status--no_ai_overview        { background: rgba(37,99,235,0.08); color: #2563eb; }

/* 競合バーチャート */
.air-competitor-list { list-style: none; padding: 0; margin: 0; }
.air-competitor-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid var(--mw-border-light);
}
.air-competitor-item:last-child { border-bottom: none; }
.air-competitor-name {
    flex: 0 0 180px;
    font-size: 14px;
    color: var(--mw-text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.air-competitor-bar-wrap {
    flex: 1;
    height: 20px;
    background: var(--mw-bg-secondary);
    border-radius: 10px;
    overflow: hidden;
}
.air-competitor-bar {
    height: 100%;
    border-radius: 10px;
    background: var(--mw-primary-teal);
    transition: width 0.4s ease;
}
.air-competitor-bar--self { background: var(--mw-primary-blue); }
.air-competitor-rate {
    flex: 0 0 50px;
    text-align: right;
    font-size: 13px;
    color: var(--mw-text-secondary);
    font-weight: 600;
}

/* アクションカード */
.air-actions-list { display: flex; flex-direction: column; gap: 12px; }
.air-action-card {
    display: flex;
    gap: 14px;
    padding: 16px;
    border: 1px solid var(--mw-border-light);
    border-radius: 12px;
    background: var(--mw-bg-primary);
    border-left: 4px solid transparent;
}
.air-action-card--high   { border-left-color: #C95A4F; }
.air-action-card--medium { border-left-color: #C9A84C; }
.air-action-card--low    { border-left-color: var(--mw-primary-teal); }
.air-action-card__priority {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    color: #fff;
}
.air-action-card__priority--high   { background: #C95A4F; }
.air-action-card__priority--medium { background: #C9A84C; }
.air-action-card__priority--low    { background: var(--mw-primary-teal); }
.air-action-card__body { flex: 1; }
.air-action-card__title { font-size: 14px; font-weight: 600; color: var(--mw-text-heading); margin-bottom: 4px; }
.air-action-card__desc  { font-size: 13px; color: var(--mw-text-secondary); line-height: 1.5; }

/* β版ラベル */
.air-beta-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    background: var(--mw-bg-tertiary);
    color: var(--mw-text-tertiary);
    font-size: 11px;
    font-weight: 600;
    margin-left: 8px;
    vertical-align: middle;
}

/* ランキング（β版・折りたたみ） */
.air-ranking-toggle {
    background: none;
    border: 1px solid var(--mw-border-light);
    border-radius: 8px;
    padding: 10px 16px;
    width: 100%;
    text-align: left;
    cursor: pointer;
    font-size: 14px;
    color: var(--mw-text-secondary);
    transition: background 0.15s;
}
.air-ranking-toggle:hover { background: var(--mw-bg-secondary); }
.air-ranking-content { display: none; margin-top: 16px; }
.air-ranking-content.open { display: block; }
.air-ranking-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}
.air-ranking-provider {
    border: 1px solid var(--mw-border-light);
    border-radius: 12px;
    overflow: hidden;
}
.air-ranking-provider__header {
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
    border-bottom: 1px solid var(--mw-border-light);
}
.air-ranking-provider__header--chatgpt  { background: rgba(22,163,74,0.08); color: #16a34a; }
.air-ranking-provider__header--gemini   { background: rgba(37,99,235,0.08); color: #2563eb; }
.air-ranking-provider__header--google_ai { background: rgba(234,88,12,0.08); color: #ea580c; }
.air-ranking-list { list-style: none; padding: 0; margin: 0; }
.air-ranking-list li {
    display: flex;
    justify-content: space-between;
    padding: 8px 14px;
    font-size: 13px;
    border-bottom: 1px solid rgba(0,0,0,0.04);
}
.air-ranking-list li:last-child { border-bottom: none; }
.air-ranking-list li.is-self { background: #ecfdf5; font-weight: 600; }
.air-ranking-rank { color: var(--mw-text-tertiary); margin-right: 8px; min-width: 20px; }

/* プログレス */
.air-progress {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
}
.air-progress.active { display: flex; }
.air-progress__inner {
    background: #fff;
    border-radius: 16px;
    padding: 40px;
    text-align: center;
    min-width: 300px;
}
.air-progress__spinner {
    width: 40px; height: 40px;
    margin: 0 auto 16px;
    border: 3px solid var(--mw-border-light);
    border-top-color: var(--mw-primary-teal);
    border-radius: 50%;
    animation: air-spin 0.8s linear infinite;
}
@keyframes air-spin { to { transform: rotate(360deg); } }
.air-progress__text { font-size: 14px; color: var(--mw-text-secondary); }

/* トースト */
.air-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 12px 20px;
    border-radius: 10px;
    background: #1A2F33;
    color: #fff;
    font-size: 14px;
    z-index: 10000;
    opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
}
.air-toast.active { opacity: 1; pointer-events: auto; }
.air-toast--error { background: #C95A4F; }

/* エビデンス表示 */
.air-evidence { margin-top: 6px; font-size: 12px; line-height: 1.7; }
.air-evidence__item { display: flex; align-items: baseline; gap: 4px; }
.air-evidence__item--found { color: #4E8A6B; }
.air-evidence__item--missing { color: #C95A4F; }
.air-evidence__icon { flex-shrink: 0; font-size: 10px; }

/* 診断対象URL */
.air-crawled-urls { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--mw-border-light); }
.air-crawled-urls__title { font-size: 13px; font-weight: 600; color: var(--mw-text-secondary); margin-bottom: 8px; }
.air-crawled-url { font-size: 12px; margin-bottom: 4px; color: var(--mw-text-secondary); }
.air-crawled-url__status { font-weight: 600; margin-right: 4px; }
.air-crawled-url__status--ok { color: #4E8A6B; }
.air-crawled-url__status--err { color: #C95A4F; }

/* 診断実行ボタン（未診断時の大きなCTA） */
.air-diagnosis-cta {
    text-align: center;
    padding: 40px 20px;
    border: 2px dashed var(--mw-border-light);
    border-radius: 12px;
    background: var(--mw-bg-secondary);
}
.air-diagnosis-cta__text {
    font-size: 14px;
    color: var(--mw-text-secondary);
    margin-bottom: 16px;
}
.air-diagnosis-cta__btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 12px 24px;
    background: var(--mw-primary-blue);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.15s;
}
.air-diagnosis-cta__btn:hover { opacity: 0.9; }
.air-diagnosis-cta__btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* 地点タグ */
.air-location-tag {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 11px;
    color: var(--mw-text-tertiary);
}
/* プロバイダー注記 */
.air-provider-notes {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--mw-border-light);
    font-size: 11px;
    color: var(--mw-text-tertiary);
    line-height: 1.6;
}
.air-provider-notes div { margin-bottom: 2px; }

/* 空状態 */
.air-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--mw-text-tertiary);
    font-size: 14px;
}

/* レスポンシブ */
@media (max-width: 768px) {
    .air-summary-cards { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .air-summary-card { padding: 16px; }
    .air-summary-card__value { font-size: 28px; }
    .air-diagnosis-grid { grid-template-columns: 1fr; }
    .air-mention-table { font-size: 12px; }
    .air-mention-table th, .air-mention-table td { padding: 8px 6px; }
    .air-competitor-name { flex: 0 0 120px; font-size: 12px; }
    .air-ranking-grid { grid-template-columns: 1fr; }
    .air-section { padding: 20px 16px; }
}
</style>

<div class="content-area">

    <!-- プログレスオーバーレイ -->
    <div class="air-progress" id="airProgress">
        <div class="air-progress__inner">
            <div class="air-progress__spinner"></div>
            <div class="air-progress__text" id="airProgressText">データを読み込み中...</div>
        </div>
    </div>

    <!-- トースト -->
    <div class="air-toast" id="airToast"></div>

    <!-- ===== Section 1: サマリー ===== -->
    <div class="air-summary-cards" id="airSummary">
        <div class="air-summary-card">
            <div class="air-summary-card__label">AI伝達性スコア</div>
            <div class="air-summary-card__value air-summary-card__value--accent" id="airDiagScore">--</div>
            <div class="air-summary-card__sub">参考値 / 10項目の診断結果</div>
        </div>
        <div class="air-summary-card">
            <div class="air-summary-card__label">AI掲載確認率</div>
            <div class="air-summary-card__value" id="airMentionRate">--</div>
            <div class="air-summary-card__sub" id="airMentionSub">計測結果ベース</div>
        </div>
        <div class="air-summary-card">
            <div class="air-summary-card__label">改善優先度</div>
            <div id="airPriority"><span class="air-badge air-badge--none">--</span></div>
            <div class="air-summary-card__sub">診断 + 掲載結果から判定</div>
        </div>
        <div class="air-summary-card">
            <div class="air-summary-card__label">基準地点</div>
            <div class="air-summary-card__value" id="airLocationLabel" style="font-size:18px;">--</div>
            <div class="air-summary-card__sub" id="airLocationSub"></div>
        </div>
        <div class="air-summary-card">
            <div class="air-summary-card__label">最終計測</div>
            <div class="air-summary-card__value" id="airLastFetched" style="font-size:18px;">--</div>
            <div class="air-summary-card__sub">
                <button class="air-btn air-btn--primary" id="airRunBtn" onclick="runMeasurement()" style="margin-top:8px;font-size:13px;padding:6px 14px;">
                    計測する
                </button>
            </div>
        </div>
    </div>

    <!-- ===== Section 2: サイト診断 ===== -->
    <div class="air-section" id="airDiagnosisSection">
        <div class="air-section__header">
            <div>
                <h2 class="air-section__title">🔍 AIに伝わるサイト診断</h2>
                <div class="air-section__note">AIがサイト内容を正しく理解できる状態か、10項目でチェックします（参考値）</div>
            </div>
            <button class="air-btn air-btn--primary" id="airDiagBtn" onclick="runDiagnosis()" style="font-size:13px;padding:6px 14px;display:none;">
                再診断
            </button>
        </div>
        <div id="airDiagnosisBody">
            <div class="air-diagnosis-grid" id="airDiagnosisGrid">
                <div class="air-empty">診断データを読み込み中...</div>
            </div>
        </div>
    </div>

    <!-- ===== Section 3: AI掲載チェック ===== -->
    <div class="air-section" id="airMentionSection">
        <div class="air-section__header">
            <div>
                <h2 class="air-section__title">📋 AI掲載チェック</h2>
                <div class="air-section__note">各AIの回答内で自社が言及された傾向を確認できます（指定地点ベース・参考値）</div>
            </div>
        </div>
        <div id="airMentionContent">
            <div class="air-empty">掲載チェックデータを読み込み中...</div>
        </div>
    </div>

    <!-- ===== Section 4: 競合との比較 ===== -->
    <div class="air-section" id="airCompetitorSection">
        <div class="air-section__header">
            <div>
                <h2 class="air-section__title">👥 AI掲載傾向 — 競合比較</h2>
                <div class="air-section__note" id="airCompetitorNote">自社と一緒にAIで言及されやすい他社の傾向です（参考値）</div>
            </div>
        </div>
        <div id="airCompetitorContent">
            <div class="air-empty">データを読み込み中...</div>
        </div>
    </div>

    <!-- ===== Section 5: 改善アクション ===== -->
    <div class="air-section" id="airActionsSection">
        <div class="air-section__header">
            <div>
                <h2 class="air-section__title">💡 改善アクション提案</h2>
                <div class="air-section__note">診断結果をもとに、優先度の高い改善ポイントを提案します</div>
            </div>
        </div>
        <div id="airActionsContent">
            <div class="air-empty">データを読み込み中...</div>
        </div>
    </div>

    <!-- ===== Section 6: ランキング（β版） ===== -->
    <div class="air-section" id="airRankingSection">
        <div class="air-section__header">
            <div>
                <h2 class="air-section__title">📊 地点別AI掲載傾向 <span class="air-beta-badge">β版・参考値</span></h2>
                <div class="air-section__note" id="airRankingNote">指定地点を基準にしたAIの言及傾向です。実際の結果はユーザーの位置情報や環境により変動します。</div>
            </div>
        </div>
        <div id="airRankingContent">
            <div class="air-empty">データを読み込み中...</div>
        </div>
    </div>

</div><!-- .content-area -->

<script>
(function() {
    'use strict';

    var wpNonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
    var reportData = null;

    var PROVIDERS = ['chatgpt', 'gemini', 'google_ai'];
    var PROVIDER_LABELS = { chatgpt: 'ChatGPT', gemini: 'Gemini', google_ai: 'Google AI概要 β' };
    var STATUS_ICONS = {
        success_mentioned:     '✅',
        success_not_mentioned: '—',
        not_run:               '⏳',
        fetch_failed:          '⚠️',
        parse_failed:          '⚠️',
        unsupported:           '🚫',
        no_answer:             '💬',
        no_ai_overview:        'ℹ️'
    };
    var PRIORITY_LABELS = { high: '高', medium: '中', low: '低' };

    // =========================================================
    // 初期化
    // =========================================================
    document.addEventListener('DOMContentLoaded', function() {
        fetchReportData();
    });

    function fetchReportData() {
        fetch('/wp-json/gcrev/v1/aio/report', {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success && json.data) {
                reportData = json.data;
                renderAll();
            } else {
                showToast(json.message || 'データ取得に失敗しました', true);
            }
        })
        .catch(function(err) {
            console.error('[AIReport]', err);
            showToast('通信エラーが発生しました', true);
        });
    }

    function renderAll() {
        renderSummary();
        renderDiagnosis();
        renderMentionCheck();
        renderCompetitors();
        renderActions();
        renderRanking();
    }

    // =========================================================
    // Section 1: サマリー
    // =========================================================
    function renderSummary() {
        var s = reportData.summary;
        var el = function(id) { return document.getElementById(id); };

        el('airDiagScore').textContent = s.diagnosis_score !== null ? s.diagnosis_score + '点' : 'ー';
        if (s.diagnosis_score === null) {
            el('airDiagScore').insertAdjacentHTML('afterend', '<div style="font-size:11px;color:var(--mw-text-tertiary);margin-top:2px;">未診断</div>');
        }
        el('airMentionRate').textContent = s.mention_total > 0 ? s.mention_rate + '%' : '未計測';
        el('airMentionSub').textContent = s.mention_total > 0
            ? s.mention_count + ' / ' + s.mention_total + ' 件で掲載確認'
            : 'まだ計測されていません';

        var priorityMap = {
            high:   '<span class="air-badge air-badge--high">高</span>',
            medium: '<span class="air-badge air-badge--medium">中</span>',
            low:    '<span class="air-badge air-badge--low">低</span>'
        };
        el('airPriority').innerHTML = s.mention_total > 0 || s.diagnosis_score !== null
            ? (priorityMap[s.priority] || '<span class="air-badge air-badge--none">--</span>')
            : '<span class="air-badge air-badge--none">--</span>';

        el('airLastFetched').textContent = s.last_fetched ? formatDate(s.last_fetched) : '未計測';

        // 基準地点カード
        el('airLocationLabel').textContent = s.location_label || '未設定';
        var sourceMap = {
            'auto_from_client_settings': 'クライアント設定から自動取得',
            'keyword_text': 'キーワードから自動検出',
            'client_settings': 'クライアント設定から取得',
            'none': '地点補正なし'
        };
        el('airLocationSub').textContent = sourceMap[s.location_source] || '';
        if (s.location_source === 'none' || !s.location_label) {
            el('airLocationLabel').style.color = 'var(--mw-text-tertiary)';
        }
    }

    // =========================================================
    // Section 2: サイト診断
    // =========================================================
    function renderDiagnosis() {
        var diag = reportData.diagnosis;
        var items = diag.items || [];
        var body = document.getElementById('airDiagnosisBody');
        var grid = document.getElementById('airDiagnosisGrid');
        var diagBtn = document.getElementById('airDiagBtn');
        var isDefault = items.length === 0 || items.every(function(i) { return i.source === 'default'; });

        if (isDefault) {
            // 未診断 — CTA表示
            diagBtn.style.display = 'none';
            grid.innerHTML = '<div class="air-diagnosis-cta">'
                + '<div class="air-diagnosis-cta__text">サイト診断はまだ実施されていません。<br>サイトをクロールして、AIへの伝わりやすさを10項目で診断します。</div>'
                + '<button class="air-diagnosis-cta__btn" onclick="runDiagnosis()">🔍 診断を実行</button>'
                + '</div>';
            return;
        }

        // 診断済み — ヘッダーに再診断ボタン表示
        diagBtn.style.display = '';
        diagBtn.textContent = '再診断';

        var iconMap = { ok: '✓', caution: '!', not_addressed: '×', unknown: '?', fetch_failed: '×' };
        var html = '';
        items.forEach(function(item) {
            html += '<div class="air-diagnosis-item">'
                + '<div class="air-diagnosis-item__icon air-diagnosis-item__icon--' + esc(item.status) + '">'
                + (iconMap[item.status] || '?')
                + '</div>'
                + '<div class="air-diagnosis-item__body">'
                + '<div class="air-diagnosis-item__label">' + esc(item.label) + '</div>'
                + '<div class="air-diagnosis-item__score">' + item.score + ' / 10</div>'
                + (item.comment ? '<div class="air-diagnosis-item__comment">' + esc(item.comment) + '</div>' : '');

            // エビデンス表示
            if (item.evidence) {
                html += '<div class="air-evidence">';
                (item.evidence.found || []).forEach(function(e) {
                    html += '<div class="air-evidence__item air-evidence__item--found">'
                        + '<span class="air-evidence__icon">✓</span> ' + esc(e) + '</div>';
                });
                (item.evidence.missing || []).forEach(function(e) {
                    html += '<div class="air-evidence__item air-evidence__item--missing">'
                        + '<span class="air-evidence__icon">✕</span> ' + esc(e) + '</div>';
                });
                html += '</div>';
            }

            html += '</div></div>';
        });
        grid.innerHTML = html;

        // 診断対象URL表示
        var crawledUrls = diag.crawled_urls || [];
        if (crawledUrls.length > 0) {
            var urlHtml = '<div class="air-crawled-urls">'
                + '<div class="air-crawled-urls__title">診断対象URL</div>';
            crawledUrls.forEach(function(u) {
                var isOk = u.status >= 200 && u.status < 400;
                urlHtml += '<div class="air-crawled-url">'
                    + '<span class="air-crawled-url__status ' + (isOk ? 'air-crawled-url__status--ok' : 'air-crawled-url__status--err') + '">'
                    + '[' + u.status + ']</span> '
                    + esc(u.url) + (u.title ? ' — ' + esc(u.title) : '')
                    + '</div>';
            });
            urlHtml += '</div>';
            // updated_at 表示
            if (diag.updated_at) {
                urlHtml += '<div style="font-size:11px;color:var(--mw-text-tertiary);margin-top:8px;">診断日時: ' + formatDate(diag.updated_at) + '</div>';
            }
            grid.insertAdjacentHTML('afterend', urlHtml);
        }
    }

    // =========================================================
    // Section 3: AI掲載チェック
    // =========================================================
    function renderMentionCheck() {
        var matrix = reportData.mention_matrix || [];
        var container = document.getElementById('airMentionContent');

        if (matrix.length === 0) {
            container.innerHTML = '<div class="air-empty">計測対象のキーワードがありません。<br>管理画面でAIO計測を有効にしてください。</div>';
            return;
        }

        var html = '<div style="overflow-x:auto;">'
            + '<table class="air-mention-table"><thead><tr>'
            + '<th>キーワード</th>';
        PROVIDERS.forEach(function(p) {
            html += '<th>' + PROVIDER_LABELS[p] + '</th>';
        });
        html += '<th>最終計測</th></tr></thead><tbody>';

        matrix.forEach(function(kw) {
            html += '<tr><td>' + esc(kw.keyword);
            if (kw.location_label) {
                html += '<br><span class="air-location-tag">📍 ' + esc(kw.location_label) + '</span>';
            }
            html += '</td>';
            var lastFetched = null;
            PROVIDERS.forEach(function(p) {
                var d = kw.providers[p] || { status: 'not_run', label: '未計測' };
                html += '<td><span class="air-status air-status--' + esc(d.status) + '">'
                    + (STATUS_ICONS[d.status] || '') + ' ' + esc(d.label)
                    + '</span>';
                if (d.avg_rank !== null && d.avg_rank > 0) {
                    html += '<br><span style="font-size:11px;color:var(--mw-text-tertiary);">平均' + d.avg_rank + '位</span>';
                }
                if (d.status === 'no_ai_overview') {
                    html += '<br><span style="font-size:10px;color:var(--mw-text-tertiary);">日本語未対応の可能性</span>';
                }
                html += '</td>';
                if (d.last_fetched && (!lastFetched || d.last_fetched > lastFetched)) {
                    lastFetched = d.last_fetched;
                }
            });
            html += '<td style="font-size:12px;color:var(--mw-text-tertiary);">' + (lastFetched ? formatDate(lastFetched) : '--') + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table></div>';

        // プロバイダー注記
        if (reportData.provider_notes) {
            html += '<div class="air-provider-notes">';
            PROVIDERS.forEach(function(p) {
                if (reportData.provider_notes[p]) {
                    html += '<div><strong>' + PROVIDER_LABELS[p] + ':</strong> '
                        + esc(reportData.provider_notes[p]) + '</div>';
                }
            });
            html += '</div>';
        }

        container.innerHTML = html;
    }

    // =========================================================
    // Section 4: 競合比較
    // =========================================================
    function renderCompetitors() {
        var comp = reportData.competitors || {};
        var container = document.getElementById('airCompetitorContent');
        var competitors = comp.competitors || [];

        // 地点ラベルを note に反映
        var noteEl = document.getElementById('airCompetitorNote');
        if (comp.location_label && noteEl) {
            noteEl.textContent = '基準地点: ' + comp.location_label
                + ' — 自社と一緒にAIで言及されやすい他社の傾向です（参考値）';
        }

        if (competitors.length === 0) {
            container.innerHTML = '<div class="air-empty">計測データが不足しているため、競合比較を表示できません。</div>';
            return;
        }

        var maxRate = Math.max(comp.self_rate || 0, competitors[0].rate || 0, 1);

        var html = '<ul class="air-competitor-list">';

        // 自社
        html += '<li class="air-competitor-item">'
            + '<span class="air-competitor-name" style="font-weight:600;">🏢 自社</span>'
            + '<div class="air-competitor-bar-wrap"><div class="air-competitor-bar air-competitor-bar--self" style="width:' + Math.round((comp.self_rate / maxRate) * 100) + '%"></div></div>'
            + '<span class="air-competitor-rate">' + (comp.self_rate || 0) + '%</span>'
            + '</li>';

        competitors.slice(0, 7).forEach(function(c) {
            html += '<li class="air-competitor-item">'
                + '<span class="air-competitor-name">' + esc(c.name) + '</span>'
                + '<div class="air-competitor-bar-wrap"><div class="air-competitor-bar" style="width:' + Math.round((c.rate / maxRate) * 100) + '%"></div></div>'
                + '<span class="air-competitor-rate">' + c.rate + '%</span>'
                + '</li>';
        });

        html += '</ul>'
            + '<p style="font-size:12px;color:var(--mw-text-tertiary);margin-top:12px;">※ 全回答 ' + comp.total_responses + ' 件中の言及率。参考値としてご覧ください。</p>';

        container.innerHTML = html;
    }

    // =========================================================
    // Section 5: 改善アクション
    // =========================================================
    function renderActions() {
        var actions = reportData.actions || [];
        var container = document.getElementById('airActionsContent');

        if (actions.length === 0) {
            container.innerHTML = '<div class="air-empty">現在、改善提案はありません。サイト診断が完了するとここに表示されます。</div>';
            return;
        }

        var html = '<div class="air-actions-list">';
        actions.forEach(function(a) {
            html += '<div class="air-action-card air-action-card--' + a.priority + '">'
                + '<div class="air-action-card__priority air-action-card__priority--' + a.priority + '">'
                + (PRIORITY_LABELS[a.priority] || '?') + '</div>'
                + '<div class="air-action-card__body">'
                + '<div class="air-action-card__title">' + esc(a.label) + '</div>'
                + '<div class="air-action-card__desc">' + esc(a.description) + '</div>'
                + '</div></div>';
        });
        html += '</div>';
        container.innerHTML = html;
    }

    // =========================================================
    // Section 6: ランキング（β版）
    // =========================================================
    function renderRanking() {
        var matrix = reportData.mention_matrix || [];
        var container = document.getElementById('airRankingContent');

        // 地点名をランキング note に反映
        var rankNote = document.getElementById('airRankingNote');
        var locLabel = reportData.summary && reportData.summary.location_label;
        if (locLabel && rankNote) {
            rankNote.textContent = locLabel + ' を基準にしたAIの言及傾向です。実際の結果はユーザーの位置情報や環境により変動します。';
        }

        if (matrix.length === 0) {
            container.innerHTML = '<div class="air-empty">計測データがありません。</div>';
            return;
        }

        // キーワードごとにアコーディオン
        var html = '';
        matrix.forEach(function(kw) {
            html += '<div style="margin-bottom:12px;">'
                + '<button class="air-ranking-toggle" onclick="toggleRanking(this)">'
                + '▶ ' + esc(kw.keyword) + ' の掲載傾向'
                + '</button>'
                + '<div class="air-ranking-content" data-keyword-id="' + kw.keyword_id + '"></div>'
                + '</div>';
        });
        container.innerHTML = html;
    }

    window.toggleRanking = function(btn) {
        var content = btn.nextElementSibling;
        if (content.classList.contains('open')) {
            content.classList.remove('open');
            btn.textContent = btn.textContent.replace('▼', '▶');
            return;
        }
        content.classList.add('open');
        btn.textContent = btn.textContent.replace('▶', '▼');

        if (content.dataset.loaded) return;
        content.dataset.loaded = '1';
        content.innerHTML = '<div style="padding:16px;color:var(--mw-text-tertiary);font-size:13px;">読み込み中...</div>';

        var kwId = content.dataset.keywordId;
        fetch('/wp-json/gcrev/v1/aio/keyword-detail?keyword_id=' + kwId, {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (!json.success || !json.data) {
                content.innerHTML = '<div class="air-empty">データを取得できませんでした</div>';
                return;
            }
            var detail = json.data;
            var html = '<div class="air-ranking-grid">';
            PROVIDERS.forEach(function(p) {
                var pd = detail.providers[p] || { rankings: [] };
                html += '<div class="air-ranking-provider">'
                    + '<div class="air-ranking-provider__header air-ranking-provider__header--' + p + '">'
                    + PROVIDER_LABELS[p] + '</div>';
                if (pd.rankings && pd.rankings.length > 0) {
                    html += '<ul class="air-ranking-list">';
                    pd.rankings.forEach(function(r) {
                        html += '<li' + (r.is_self ? ' class="is-self"' : '') + '>'
                            + '<span><span class="air-ranking-rank">' + r.rank + '</span>' + esc(r.name) + '</span>'
                            + '<span>' + r.mention_rate + '%</span></li>';
                    });
                    html += '</ul>';
                } else {
                    html += '<div style="padding:16px;font-size:13px;color:var(--mw-text-tertiary);text-align:center;">データなし</div>';
                }
                html += '</div>';
            });
            html += '</div>';
            content.innerHTML = html;
        })
        .catch(function() {
            content.innerHTML = '<div class="air-empty">読み込みに失敗しました</div>';
        });
    };

    // =========================================================
    // 計測実行
    // =========================================================
    window.runMeasurement = function() {
        var matrix = (reportData && reportData.mention_matrix) ? reportData.mention_matrix : [];
        if (matrix.length === 0) {
            showToast('計測対象のキーワードがありません', true);
            return;
        }
        if (!confirm('全キーワードのAI掲載チェックを実行します。数分かかる場合があります。よろしいですか？')) return;

        var btn = document.getElementById('airRunBtn');
        btn.disabled = true;

        var jobs = [];
        matrix.forEach(function(kw) {
            PROVIDERS.forEach(function(p) {
                jobs.push({ keyword_id: kw.keyword_id, keyword: kw.keyword, provider: p });
            });
        });

        var total = jobs.length, done = 0, errors = [];

        function runNext() {
            if (done >= total) {
                showProgress(false);
                btn.disabled = false;
                reportData = null;
                fetchReportData();
                if (errors.length > 0) {
                    showToast('計測完了（' + errors.length + '件エラーあり）', true);
                } else {
                    showToast('計測が完了しました');
                }
                return;
            }

            var job = jobs[done];
            showProgress(true, '計測中 (' + (done + 1) + '/' + total + '): ' + job.keyword + ' — ' + PROVIDER_LABELS[job.provider]);

            fetch('/wp-json/gcrev/v1/aio/run-keyword', {
                method: 'POST',
                headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ keyword_id: job.keyword_id, provider: job.provider })
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                done++;
                if (!json.success) errors.push(job.keyword + ' / ' + job.provider);
                runNext();
            })
            .catch(function(err) {
                done++;
                errors.push(job.keyword + ' / ' + job.provider + ': ' + err.message);
                runNext();
            });
        }
        runNext();
    };

    // =========================================================
    // サイト診断実行
    // =========================================================
    window.runDiagnosis = function() {
        if (!confirm('サイト診断を実行します。サイトをクロールして解析するため、1〜2分ほどかかります。よろしいですか？')) return;

        var btns = document.querySelectorAll('#airDiagBtn, .air-diagnosis-cta__btn');
        btns.forEach(function(b) { b.disabled = true; });
        showProgress(true, 'サイトを診断中... ページを取得・解析しています');

        fetch('/wp-json/gcrev/v1/aio/run-diagnosis', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            showProgress(false);
            btns.forEach(function(b) { b.disabled = false; });
            if (json.success) {
                showToast('サイト診断が完了しました');
                reportData = null;
                fetchReportData();
            } else {
                showToast(json.message || '診断に失敗しました', true);
            }
        })
        .catch(function(err) {
            showProgress(false);
            btns.forEach(function(b) { b.disabled = false; });
            showToast('通信エラーが発生しました', true);
            console.error('[AIReport] runDiagnosis error:', err);
        });
    };

    // =========================================================
    // ユーティリティ
    // =========================================================
    function showProgress(on, text) {
        var el = document.getElementById('airProgress');
        if (on) {
            document.getElementById('airProgressText').textContent = text || 'しばらくお待ちください...';
            el.classList.add('active');
        } else {
            el.classList.remove('active');
        }
    }

    function showToast(msg, isErr) {
        var el = document.getElementById('airToast');
        el.textContent = msg;
        el.className = 'air-toast active' + (isErr ? ' air-toast--error' : '');
        setTimeout(function() { el.classList.remove('active'); }, 4000);
    }

    function formatDate(str) {
        if (!str) return '--';
        var d = new Date(str.replace(' ', 'T'));
        if (isNaN(d.getTime())) return str;
        var pad = function(n) { return n < 10 ? '0' + n : '' + n; };
        return d.getFullYear() + '/' + pad(d.getMonth() + 1) + '/' + pad(d.getDate())
            + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function esc(str) {
        if (typeof str !== 'string') return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})();
</script>
<?php get_footer(); ?>
