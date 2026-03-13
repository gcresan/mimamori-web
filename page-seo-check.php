<?php
/**
 * Template Name: SEO対策
 * Description: サイトの基本的なSEO状態を診断し、改善ポイントを整理するページ
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'SEO対策' );
set_query_var( 'gcrev_page_subtitle', 'サイトの基本的なSEO状態を診断し、改善ポイントを整理します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'SEO対策', '集客のようす' ) );

get_header();
?>
<style>
/* =========================================================
   SEO対策 — スタイル
   ========================================================= */

/* サマリーカード */
.seo-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}
.seo-summary-card {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 24px;
    text-align: center;
    transition: box-shadow 0.2s, transform 0.2s;
}
.seo-summary-card:hover {
    box-shadow: var(--mw-shadow-float);
    transform: translateY(-1px);
}
.seo-summary-card__label {
    font-size: 13px;
    color: var(--mw-text-tertiary);
    margin-bottom: 8px;
}
.seo-summary-card__value {
    font-size: 36px;
    font-weight: 700;
    color: var(--mw-text-heading);
    line-height: 1.2;
}
.seo-summary-card__value--accent { color: var(--mw-primary-blue); }
.seo-summary-card__value--critical { color: #C95A4F; }
.seo-summary-card__value--warning { color: #C9A84C; }
.seo-summary-card__sub {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-top: 4px;
}
.seo-score-ring {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    position: relative;
    margin-bottom: 4px;
}
.seo-score-ring svg { position: absolute; top: 0; left: 0; transform: rotate(-90deg); }
.seo-score-ring__value {
    font-size: 28px;
    font-weight: 700;
    z-index: 1;
}
.seo-rank-label {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 4px;
}
.seo-rank-label--A { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.seo-rank-label--B { background: rgba(86,129,132,0.12); color: #568184; }
.seo-rank-label--C { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-rank-label--D { background: rgba(201,90,79,0.12); color: #C95A4F; }

/* バッジ */
.seo-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}
.seo-badge--critical { background: rgba(201,90,79,0.12); color: #C95A4F; }
.seo-badge--warning  { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-badge--ok       { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.seo-badge--none     { background: var(--mw-bg-secondary); color: var(--mw-text-tertiary); }

/* セクション */
.seo-section {
    background: var(--mw-bg-primary);
    border: 1px solid var(--mw-border-light);
    border-radius: var(--mw-radius-md);
    padding: 28px;
    margin-bottom: 28px;
}
.seo-section__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.seo-section__title {
    font-size: 16px;
    font-weight: 600;
    color: var(--mw-text-heading);
    margin: 0;
}
.seo-section__note {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-top: 4px;
}

/* ボタン */
.seo-btn {
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
.seo-btn:hover { background: var(--mw-bg-secondary); }
.seo-btn--primary {
    background: var(--mw-primary-blue);
    color: #fff;
    border-color: var(--mw-primary-blue);
}
.seo-btn--primary:hover { opacity: 0.9; }

/* 診断グリッド */
.seo-diagnosis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 12px;
}
.seo-diagnosis-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px;
    border: 1px solid var(--mw-border-light);
    border-radius: 12px;
    background: var(--mw-bg-primary);
    transition: box-shadow 0.15s;
}
.seo-diagnosis-item:hover { box-shadow: var(--mw-shadow-soft); }
.seo-diagnosis-item__icon {
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
.seo-diagnosis-item__icon--ok       { background: rgba(78,138,107,0.12); color: #4E8A6B; }
.seo-diagnosis-item__icon--caution  { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-diagnosis-item__icon--critical { background: rgba(201,90,79,0.12); color: #C95A4F; }
.seo-diagnosis-item__body { flex: 1; min-width: 0; }
.seo-diagnosis-item__label {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-primary);
    margin-bottom: 2px;
}
.seo-diagnosis-item__score {
    font-size: 12px;
    color: var(--mw-text-tertiary);
    margin-bottom: 4px;
}
.seo-diagnosis-item__findings {
    font-size: 13px;
    color: var(--mw-text-secondary);
    line-height: 1.6;
    margin: 0;
    padding: 0;
    list-style: none;
}
.seo-diagnosis-item__findings li { padding: 1px 0; }
.seo-diagnosis-item__findings li::before {
    content: '・';
    color: var(--mw-text-tertiary);
}
.seo-diagnosis-item__urls {
    margin-top: 6px;
    font-size: 11px;
    color: var(--mw-text-tertiary);
    line-height: 1.5;
}

/* 全体評価 */
.seo-assessment { line-height: 1.7; }
.seo-assessment__summary {
    font-size: 14px;
    color: var(--mw-text-primary);
    margin-bottom: 20px;
    padding: 16px;
    background: var(--mw-bg-secondary);
    border-radius: 10px;
}
.seo-assessment__group { margin-bottom: 16px; }
.seo-assessment__group-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-primary);
    margin-bottom: 8px;
}
.seo-assessment__list {
    list-style: none;
    padding: 0;
    margin: 0;
    font-size: 13px;
    color: var(--mw-text-secondary);
}
.seo-assessment__list li {
    padding: 4px 0;
    padding-left: 20px;
    position: relative;
}
.seo-assessment__list li::before {
    position: absolute;
    left: 0;
}
.seo-assessment__list--good li::before { content: '✓'; color: #4E8A6B; font-weight: 600; }
.seo-assessment__list--improve li::before { content: '!'; color: #C95A4F; font-weight: 700; }

/* 問題URLテーブル */
.seo-issues-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.seo-issues-table thead {
    background: var(--mw-bg-secondary);
    border-bottom: 2px solid var(--mw-border-light);
}
.seo-issues-table th {
    padding: 10px 14px;
    font-weight: 600;
    color: var(--mw-text-secondary);
    font-size: 12px;
    text-align: left;
    white-space: nowrap;
}
.seo-issues-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--mw-border-light);
    vertical-align: top;
}
.seo-issues-table tbody tr:hover { background: var(--mw-bg-secondary); }
.seo-issues-table .seo-url-cell {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--mw-primary-blue);
    font-weight: 500;
}
.seo-priority-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.seo-priority-badge--high   { background: rgba(201,90,79,0.12); color: #C95A4F; }
.seo-priority-badge--medium { background: rgba(201,168,76,0.15); color: #C9A84C; }
.seo-priority-badge--low    { background: rgba(86,129,132,0.12); color: #568184; }

/* アクションカード */
.seo-actions-list { display: flex; flex-direction: column; gap: 12px; }
.seo-action-card {
    display: flex;
    gap: 14px;
    padding: 16px;
    border: 1px solid var(--mw-border-light);
    border-radius: 12px;
    background: var(--mw-bg-primary);
    border-left: 4px solid transparent;
}
.seo-action-card--high   { border-left-color: #C95A4F; }
.seo-action-card--medium { border-left-color: #C9A84C; }
.seo-action-card--low    { border-left-color: var(--mw-primary-teal); }
.seo-action-card__priority {
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
.seo-action-card__priority--high   { background: #C95A4F; }
.seo-action-card__priority--medium { background: #C9A84C; }
.seo-action-card__priority--low    { background: var(--mw-primary-teal); }
.seo-action-card__body { flex: 1; }
.seo-action-card__title { font-size: 14px; font-weight: 600; color: var(--mw-text-heading); margin-bottom: 4px; }
.seo-action-card__desc  { font-size: 13px; color: var(--mw-text-secondary); line-height: 1.5; }

/* 将来拡張枠 */
.seo-future-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 16px;
}
.seo-future-card {
    padding: 24px;
    border: 2px dashed var(--mw-border-light);
    border-radius: 12px;
    background: var(--mw-bg-secondary);
    text-align: center;
}
.seo-future-card__icon {
    font-size: 24px;
    margin-bottom: 8px;
}
.seo-future-card__title {
    font-size: 14px;
    font-weight: 600;
    color: var(--mw-text-primary);
    margin-bottom: 4px;
}
.seo-future-card__desc {
    font-size: 12px;
    color: var(--mw-text-tertiary);
}
.seo-coming-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    background: var(--mw-bg-tertiary);
    color: var(--mw-text-tertiary);
    font-size: 11px;
    font-weight: 600;
    margin-top: 8px;
}

/* プログレス */
.seo-progress {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
}
.seo-progress.active { display: flex; }
.seo-progress__inner {
    background: #fff;
    border-radius: 16px;
    padding: 40px;
    text-align: center;
    min-width: 300px;
}
.seo-progress__spinner {
    width: 40px; height: 40px;
    margin: 0 auto 16px;
    border: 3px solid var(--mw-border-light);
    border-top-color: var(--mw-primary-teal);
    border-radius: 50%;
    animation: seo-spin 0.8s linear infinite;
}
@keyframes seo-spin { to { transform: rotate(360deg); } }
.seo-progress__text { font-size: 14px; color: var(--mw-text-secondary); }

/* トースト */
.seo-toast {
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
.seo-toast.active { opacity: 1; pointer-events: auto; }
.seo-toast--error { background: #C95A4F; }

/* 空状態 */
.seo-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--mw-text-tertiary);
    font-size: 14px;
}

/* テーブルラッパー */
.seo-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* レスポンシブ */
@media (max-width: 768px) {
    .seo-summary-cards { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .seo-summary-card { padding: 16px; }
    .seo-summary-card__value { font-size: 28px; }
    .seo-diagnosis-grid { grid-template-columns: 1fr; }
    .seo-issues-table { font-size: 12px; }
    .seo-issues-table th, .seo-issues-table td { padding: 8px 6px; }
    .seo-section { padding: 20px 16px; }
    .seo-future-grid { grid-template-columns: 1fr; }
    .seo-section__header { flex-direction: column; align-items: flex-start; gap: 12px; }
}
</style>

<div class="content-area">

    <!-- プログレスオーバーレイ -->
    <div class="seo-progress" id="seoProgress">
        <div class="seo-progress__inner">
            <div class="seo-progress__spinner"></div>
            <div class="seo-progress__text" id="seoProgressText">診断中...</div>
        </div>
    </div>

    <!-- トースト -->
    <div class="seo-toast" id="seoToast"></div>

    <!-- ===== Section 1: サマリー ===== -->
    <div class="seo-summary-cards" id="seoSummary"></div>

    <!-- ===== Section 2: SEO診断カード一覧 ===== -->
    <div class="seo-section" id="seoDiagnosisSection">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">🔍 SEO診断チェック</h2>
                <div class="seo-section__note">タイトル・description・見出し・画像alt・内部リンク・技術設定などを確認します</div>
            </div>
        </div>
        <div class="seo-diagnosis-grid" id="seoDiagnosisGrid"></div>
    </div>

    <!-- ===== Section 3: 診断結果の概要 ===== -->
    <div class="seo-section" id="seoAssessmentSection">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">📝 全体評価</h2>
                <div class="seo-section__note">診断結果を総合的にまとめた評価です</div>
            </div>
        </div>
        <div id="seoAssessmentContent"></div>
    </div>

    <!-- ===== Section 4: 問題URL一覧 ===== -->
    <div class="seo-section" id="seoIssuesSection">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">📋 ページ別の問題一覧</h2>
                <div class="seo-section__note">対象ページごとに検出された問題と改善候補です</div>
            </div>
        </div>
        <div class="seo-table-wrap" id="seoIssuesContent"></div>
    </div>

    <!-- ===== Section 5: 改善アクション提案 ===== -->
    <div class="seo-section" id="seoActionsSection">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">💡 改善アクション提案</h2>
                <div class="seo-section__note">診断結果をもとに、優先度の高い改善ポイントを提案します</div>
            </div>
        </div>
        <div id="seoActionsContent"></div>
    </div>

    <!-- ===== Section 6: 将来拡張枠 ===== -->
    <div class="seo-section" id="seoFutureSection">
        <div class="seo-section__header">
            <div>
                <h2 class="seo-section__title">📊 今後の拡張予定</h2>
                <div class="seo-section__note">今後対応予定の機能です</div>
            </div>
        </div>
        <div id="seoFutureContent"></div>
    </div>

</div><!-- .content-area -->

<script>
(function() {
    'use strict';

    /* =================================================================
       ダミーデータ
       将来的に fetch('/wp-json/gcrev/v1/seo/report') に差し替え
       ================================================================= */
    var DUMMY_DATA = {
        siteSummary: {
            totalScore: 72,
            rank: 'B',
            criticalCount: 3,
            warningCount: 9,
            pageCount: 8,
            lastCheckedAt: '2026/03/13 14:30'
        },

        seoChecks: [
            {
                key: 'title_tag',
                label: 'タイトルタグ',
                score: 6,
                maxScore: 10,
                status: 'caution',
                findings: [
                    '重複したタイトルが2ページで見つかりました',
                    '長すぎるタイトルが1ページあります（70文字超）',
                    '主要キーワードを含むタイトルは概ね適切です'
                ],
                affectedUrls: ['/works/', '/member/']
            },
            {
                key: 'meta_description',
                label: 'メタディスクリプション',
                score: 4,
                maxScore: 10,
                status: 'critical',
                findings: [
                    '未設定のページが3ページあります',
                    '重複したdescriptionが2ページで見つかりました',
                    '文字数が短すぎるページが1つあります'
                ],
                affectedUrls: ['/faq/', '/works/', '/member/', '/blog/']
            },
            {
                key: 'heading_structure',
                label: '見出し構造',
                score: 7,
                maxScore: 10,
                status: 'caution',
                findings: [
                    'h1が未設定のページが1つあります',
                    'h2の下にいきなりh4が使われている箇所があります',
                    'トップページの見出し階層は適切です'
                ],
                affectedUrls: ['/blog/']
            },
            {
                key: 'content_quality',
                label: 'コンテンツ充実度',
                score: 5,
                maxScore: 10,
                status: 'caution',
                findings: [
                    '文字数が500文字未満のページが2つあります',
                    'サービス説明ページの内容が薄い可能性があります',
                    'トップページ・実績ページは十分な情報量です'
                ],
                affectedUrls: ['/faq/', '/member/']
            },
            {
                key: 'image_optimization',
                label: '画像最適化',
                score: 4,
                maxScore: 10,
                status: 'critical',
                findings: [
                    'alt属性が未設定の画像が7つあります',
                    '1MB超の画像ファイルが3つ使用されています',
                    'ファイル名が不適切な画像があります（IMG_001.jpg等）'
                ],
                affectedUrls: ['/works/', '/blog/', '/']
            },
            {
                key: 'internal_links',
                label: '内部リンク設計',
                score: 6,
                maxScore: 10,
                status: 'caution',
                findings: [
                    '孤立しているページが1つあります（他ページからのリンクなし）',
                    'サービスページから実績ページへの導線が不足しています',
                    'パンくずリストは適切に設定されています'
                ],
                affectedUrls: ['/faq/']
            },
            {
                key: 'url_structure',
                label: 'URL・構造',
                score: 8,
                maxScore: 10,
                status: 'ok',
                findings: [
                    'URLはシンプルで分かりやすい構成です',
                    'カテゴリ構造は整理されています',
                    'canonical設定は適切です'
                ],
                affectedUrls: []
            },
            {
                key: 'indexing_technical',
                label: 'インデックス制御・技術設定',
                score: 9,
                maxScore: 10,
                status: 'ok',
                findings: [
                    'robots.txtは適切に設定されています',
                    'sitemap.xmlが正しく配置されています',
                    'SSL化が完了しています',
                    'HTTPSリダイレクトが設定されています'
                ],
                affectedUrls: []
            },
            {
                key: 'performance',
                label: '表示速度・パフォーマンス',
                score: 5,
                maxScore: 10,
                status: 'caution',
                findings: [
                    '画像の合計サイズが大きく、読み込みに影響しています',
                    '未使用のCSSやJSが読み込まれている可能性があります',
                    'キャッシュ設定は適切です'
                ],
                affectedUrls: ['/works/', '/']
            },
            {
                key: 'structured_data',
                label: '構造化データ',
                score: 3,
                maxScore: 10,
                status: 'critical',
                findings: [
                    'Organization構造化データが未実装です',
                    'FAQ構造化データが未実装です',
                    'パンくずの構造化データは設定されています',
                    'LocalBusiness構造化データの追加を推奨します'
                ],
                affectedUrls: ['/faq/', '/']
            }
        ],

        overallAssessment: {
            summary: 'サイト全体のSEO状態は72点（Bランク）です。技術的な基盤（SSL・robots.txt・sitemap等）は整っていますが、コンテンツ面（description・画像alt・構造化データ等）に複数の改善点が見つかりました。まずはdescriptionの設定と画像altの追加から着手すると、比較的少ない手間で大きな改善効果が期待できます。',
            goodPoints: [
                'SSL化・HTTPSリダイレクトが適切に設定されている',
                'robots.txt・sitemap.xmlが正しく配置されている',
                'URL構造がシンプルで分かりやすい',
                'パンくずリストが適切に設定されている',
                'トップページ・実績ページは十分な情報量がある'
            ],
            improvementPoints: [
                'メタディスクリプションが未設定・重複しているページがある',
                'alt属性が未設定の画像が多い',
                '構造化データ（Organization・FAQ等）が未実装',
                'サービスページのコンテンツ量が不足している',
                '大きすぎる画像ファイルの最適化が必要'
            ]
        },

        issuePages: [
            { url: '/faq/', statusCode: 200, issueType: 'メタディスクリプション', issueDetail: 'descriptionが未設定', priority: 'high', suggestion: 'ページの内容を要約した80〜120文字のdescriptionを設定してください' },
            { url: '/works/', statusCode: 200, issueType: 'タイトルタグ', issueDetail: 'タイトルが他ページと重複', priority: 'high', suggestion: '実績ページ固有のキーワードを含むタイトルに変更してください' },
            { url: '/works/', statusCode: 200, issueType: '画像最適化', issueDetail: 'alt未設定の画像が4つ', priority: 'high', suggestion: '各画像に内容を説明するalt属性を追加してください' },
            { url: '/member/', statusCode: 200, issueType: 'メタディスクリプション', issueDetail: 'descriptionが他ページと重複', priority: 'medium', suggestion: 'スタッフ紹介に特化したdescriptionに変更してください' },
            { url: '/member/', statusCode: 200, issueType: 'コンテンツ充実度', issueDetail: '文字数が約350文字と少ない', priority: 'medium', suggestion: 'スタッフの専門性や実績など、内容を充実させてください' },
            { url: '/blog/', statusCode: 200, issueType: '見出し構造', issueDetail: 'h1が未設定', priority: 'medium', suggestion: 'ブログ一覧ページにh1見出しを追加してください' },
            { url: '/blog/', statusCode: 200, issueType: 'メタディスクリプション', issueDetail: 'descriptionが未設定', priority: 'medium', suggestion: 'ブログの概要を説明するdescriptionを設定してください' },
            { url: '/', statusCode: 200, issueType: '構造化データ', issueDetail: 'Organization未実装', priority: 'medium', suggestion: 'トップページにOrganization構造化データを追加してください' },
            { url: '/', statusCode: 200, issueType: '画像最適化', issueDetail: '1MB超の画像が2つ', priority: 'low', suggestion: '画像を圧縮・リサイズして読み込み速度を改善してください' },
            { url: '/faq/', statusCode: 200, issueType: '内部リンク設計', issueDetail: '他ページからのリンクが少ない', priority: 'low', suggestion: 'サービスページやトップページからFAQへのリンクを追加してください' }
        ],

        recommendations: [
            { title: 'メタディスクリプションの設定・修正', description: '未設定のページにdescriptionを追加し、重複しているページは固有の内容に書き換えてください。検索結果のクリック率向上に直結します。', priority: 'high' },
            { title: '画像alt属性の追加', description: 'alt属性が未設定の画像7つに、画像の内容を説明するテキストを追加してください。アクセシビリティとSEOの両方に効果があります。', priority: 'high' },
            { title: '構造化データの実装', description: 'Organization・FAQ・LocalBusinessなどの構造化データを追加してください。検索結果でリッチスニペットが表示される可能性が高まります。', priority: 'high' },
            { title: 'タイトルタグの重複解消', description: '重複しているタイトルを、各ページ固有の内容に修正してください。ページごとの検索順位向上が期待できます。', priority: 'medium' },
            { title: 'コンテンツの拡充', description: '文字数が少ないページ（FAQ・メンバーページ等）の内容を充実させてください。専門性と情報量はSEO評価に大きく影響します。', priority: 'medium' },
            { title: '画像ファイルの最適化', description: '1MB超の大きな画像を圧縮・リサイズしてください。表示速度の改善はユーザー体験とSEO評価の両方に効果があります。', priority: 'medium' },
            { title: '内部リンクの強化', description: 'サービスページから実績ページへの導線、トップページからFAQへのリンクなど、関連ページ間の内部リンクを追加してください。', priority: 'low' },
            { title: '見出し構造の整理', description: 'h1が未設定のページに見出しを追加し、h2→h4のような階層の飛びを修正してください。', priority: 'low' }
        ]
    };

    /* =================================================================
       ユーティリティ
       ================================================================= */
    function esc(str) {
        if (str === null || str === undefined) return '';
        var d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    function showToast(msg, isError) {
        var el = document.getElementById('seoToast');
        el.textContent = msg;
        el.className = 'seo-toast active' + (isError ? ' seo-toast--error' : '');
        setTimeout(function() { el.className = 'seo-toast'; }, 3000);
    }

    function showProgress(msg) {
        document.getElementById('seoProgressText').textContent = msg || '診断中...';
        document.getElementById('seoProgress').classList.add('active');
    }

    function hideProgress() {
        document.getElementById('seoProgress').classList.remove('active');
    }

    function getScoreColor(score, max) {
        var pct = (score / max) * 100;
        if (pct >= 80) return '#4E8A6B';
        if (pct >= 50) return '#C9A84C';
        return '#C95A4F';
    }

    function getRankColor(rank) {
        var map = { A: '#4E8A6B', B: '#568184', C: '#C9A84C', D: '#C95A4F' };
        return map[rank] || '#568184';
    }

    var STATUS_ICONS = { ok: '✓', caution: '△', critical: '✕' };
    var STATUS_LABELS = { ok: '良好', caution: '要改善', critical: '要対応' };
    var PRIORITY_LABELS = { high: '高', medium: '中', low: '低' };

    /* =================================================================
       初期化
       ================================================================= */
    document.addEventListener('DOMContentLoaded', function() {
        renderAll(DUMMY_DATA);
    });

    function renderAll(data) {
        renderSummary(data.siteSummary);
        renderDiagnosis(data.seoChecks);
        renderAssessment(data.overallAssessment);
        renderIssues(data.issuePages);
        renderActions(data.recommendations);
        renderFuture();
    }

    /* =================================================================
       Section 1: サマリー
       ================================================================= */
    function renderSummary(s) {
        var scoreColor = getRankColor(s.rank);
        var circumference = 2 * Math.PI * 36;
        var offset = circumference - (s.totalScore / 100) * circumference;

        var html = '';
        // スコアカード（リング表示）
        html += '<div class="seo-summary-card">';
        html += '  <div class="seo-summary-card__label">SEO総合スコア</div>';
        html += '  <div class="seo-score-ring">';
        html += '    <svg width="80" height="80" viewBox="0 0 80 80">';
        html += '      <circle cx="40" cy="40" r="36" stroke="' + 'var(--mw-border-light)' + '" stroke-width="6" fill="none"/>';
        html += '      <circle cx="40" cy="40" r="36" stroke="' + esc(scoreColor) + '" stroke-width="6" fill="none"';
        html += '        stroke-dasharray="' + circumference + '" stroke-dashoffset="' + offset + '" stroke-linecap="round"/>';
        html += '    </svg>';
        html += '    <span class="seo-score-ring__value" style="color:' + esc(scoreColor) + '">' + esc(s.totalScore) + '</span>';
        html += '  </div>';
        html += '  <div><span class="seo-rank-label seo-rank-label--' + esc(s.rank) + '">' + esc(s.rank) + 'ランク</span></div>';
        html += '</div>';

        // 致命的な問題
        html += '<div class="seo-summary-card">';
        html += '  <div class="seo-summary-card__label">致命的な問題</div>';
        html += '  <div class="seo-summary-card__value seo-summary-card__value--critical">' + esc(s.criticalCount) + '<span style="font-size:16px;font-weight:400;">件</span></div>';
        html += '  <div class="seo-summary-card__sub">早急に対応が必要</div>';
        html += '</div>';

        // 要改善
        html += '<div class="seo-summary-card">';
        html += '  <div class="seo-summary-card__label">要改善項目</div>';
        html += '  <div class="seo-summary-card__value seo-summary-card__value--warning">' + esc(s.warningCount) + '<span style="font-size:16px;font-weight:400;">件</span></div>';
        html += '  <div class="seo-summary-card__sub">改善でスコアアップが期待</div>';
        html += '</div>';

        // 対象ページ
        html += '<div class="seo-summary-card">';
        html += '  <div class="seo-summary-card__label">診断対象ページ</div>';
        html += '  <div class="seo-summary-card__value">' + esc(s.pageCount) + '<span style="font-size:16px;font-weight:400;">ページ</span></div>';
        html += '  <div class="seo-summary-card__sub">クロール済みページ数</div>';
        html += '</div>';

        // 最終診断
        html += '<div class="seo-summary-card">';
        html += '  <div class="seo-summary-card__label">最終診断日時</div>';
        html += '  <div class="seo-summary-card__value" style="font-size:18px;">' + esc(s.lastCheckedAt) + '</div>';
        html += '  <div class="seo-summary-card__sub">';
        html += '    <button class="seo-btn seo-btn--primary" onclick="window.runSeoCheck()" style="margin-top:8px;font-size:13px;padding:6px 14px;">診断する</button>';
        html += '  </div>';
        html += '</div>';

        document.getElementById('seoSummary').innerHTML = html;
    }

    /* =================================================================
       Section 2: 診断カード一覧
       ================================================================= */
    function renderDiagnosis(checks) {
        var html = '';
        checks.forEach(function(c) {
            var iconClass = 'seo-diagnosis-item__icon--' + c.status;
            var icon = STATUS_ICONS[c.status] || '?';
            var scoreColor = getScoreColor(c.score, c.maxScore);

            html += '<div class="seo-diagnosis-item">';
            html += '  <div class="seo-diagnosis-item__icon ' + iconClass + '">' + esc(icon) + '</div>';
            html += '  <div class="seo-diagnosis-item__body">';
            html += '    <div class="seo-diagnosis-item__label">' + esc(c.label) + '</div>';
            html += '    <div class="seo-diagnosis-item__score" style="color:' + scoreColor + '">' + esc(c.score) + ' / ' + esc(c.maxScore) + '</div>';

            if (c.findings && c.findings.length) {
                html += '    <ul class="seo-diagnosis-item__findings">';
                c.findings.forEach(function(f) {
                    html += '      <li>' + esc(f) + '</li>';
                });
                html += '    </ul>';
            }

            if (c.affectedUrls && c.affectedUrls.length) {
                html += '    <div class="seo-diagnosis-item__urls">対象: ' + c.affectedUrls.map(function(u) { return esc(u); }).join(', ') + '</div>';
            }

            html += '  </div>';
            html += '</div>';
        });
        document.getElementById('seoDiagnosisGrid').innerHTML = html;
    }

    /* =================================================================
       Section 3: 全体評価
       ================================================================= */
    function renderAssessment(a) {
        var html = '';
        html += '<div class="seo-assessment">';
        html += '  <div class="seo-assessment__summary">' + esc(a.summary) + '</div>';

        html += '  <div class="seo-assessment__group">';
        html += '    <div class="seo-assessment__group-title">👍 良いところ</div>';
        html += '    <ul class="seo-assessment__list seo-assessment__list--good">';
        a.goodPoints.forEach(function(p) {
            html += '      <li>' + esc(p) + '</li>';
        });
        html += '    </ul>';
        html += '  </div>';

        html += '  <div class="seo-assessment__group">';
        html += '    <div class="seo-assessment__group-title">👎 改善が必要なところ</div>';
        html += '    <ul class="seo-assessment__list seo-assessment__list--improve">';
        a.improvementPoints.forEach(function(p) {
            html += '      <li>' + esc(p) + '</li>';
        });
        html += '    </ul>';
        html += '  </div>';

        html += '</div>';
        document.getElementById('seoAssessmentContent').innerHTML = html;
    }

    /* =================================================================
       Section 4: 問題URL一覧
       ================================================================= */
    function renderIssues(pages) {
        if (!pages || !pages.length) {
            document.getElementById('seoIssuesContent').innerHTML = '<div class="seo-empty">問題は検出されませんでした</div>';
            return;
        }

        var html = '<table class="seo-issues-table">';
        html += '<thead><tr>';
        html += '  <th>URL</th>';
        html += '  <th>問題カテゴリ</th>';
        html += '  <th>問題内容</th>';
        html += '  <th>重要度</th>';
        html += '  <th>改善候補</th>';
        html += '</tr></thead>';
        html += '<tbody>';

        pages.forEach(function(p) {
            html += '<tr>';
            html += '  <td class="seo-url-cell" title="' + esc(p.url) + '">' + esc(p.url) + '</td>';
            html += '  <td>' + esc(p.issueType) + '</td>';
            html += '  <td>' + esc(p.issueDetail) + '</td>';
            html += '  <td><span class="seo-priority-badge seo-priority-badge--' + esc(p.priority) + '">' + esc(PRIORITY_LABELS[p.priority] || p.priority) + '</span></td>';
            html += '  <td style="font-size:12px;color:var(--mw-text-secondary);max-width:250px;">' + esc(p.suggestion) + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        document.getElementById('seoIssuesContent').innerHTML = html;
    }

    /* =================================================================
       Section 5: 改善アクション提案
       ================================================================= */
    function renderActions(recs) {
        if (!recs || !recs.length) {
            document.getElementById('seoActionsContent').innerHTML = '<div class="seo-empty">提案はありません</div>';
            return;
        }

        var priorityOrder = { high: 0, medium: 1, low: 2 };
        var sorted = recs.slice().sort(function(a, b) {
            return (priorityOrder[a.priority] || 9) - (priorityOrder[b.priority] || 9);
        });

        var html = '<div class="seo-actions-list">';
        sorted.forEach(function(r) {
            html += '<div class="seo-action-card seo-action-card--' + esc(r.priority) + '">';
            html += '  <div class="seo-action-card__priority seo-action-card__priority--' + esc(r.priority) + '">' + esc(PRIORITY_LABELS[r.priority] || '?') + '</div>';
            html += '  <div class="seo-action-card__body">';
            html += '    <div class="seo-action-card__title">' + esc(r.title) + '</div>';
            html += '    <div class="seo-action-card__desc">' + esc(r.description) + '</div>';
            html += '  </div>';
            html += '</div>';
        });
        html += '</div>';
        document.getElementById('seoActionsContent').innerHTML = html;
    }

    /* =================================================================
       Section 6: 将来拡張枠
       ================================================================= */
    function renderFuture() {
        var items = [
            { icon: '🗺️', title: '地域別SEO傾向', desc: '対象エリアでの検索順位や競合状況を確認' },
            { icon: '🏢', title: '競合サイト比較', desc: '競合他社のSEO状態との比較分析' },
            { icon: '🔑', title: '重要キーワードの掲載状況', desc: '主要キーワードでの自社サイトの露出度' },
            { icon: '📈', title: '月次推移', desc: 'SEOスコアの推移を月ごとに追跡' },
            { icon: '🔄', title: '改善前後比較', desc: '改善施策の効果をビフォーアフターで確認' }
        ];

        var html = '<div class="seo-future-grid">';
        items.forEach(function(item) {
            html += '<div class="seo-future-card">';
            html += '  <div class="seo-future-card__icon">' + item.icon + '</div>';
            html += '  <div class="seo-future-card__title">' + esc(item.title) + '</div>';
            html += '  <div class="seo-future-card__desc">' + esc(item.desc) + '</div>';
            html += '  <span class="seo-coming-badge">準備中</span>';
            html += '</div>';
        });
        html += '</div>';
        document.getElementById('seoFutureContent').innerHTML = html;
    }

    /* =================================================================
       診断実行（将来API接続用）
       ================================================================= */
    window.runSeoCheck = function() {
        showProgress('SEO診断を実行中...');
        // 将来的に POST /wp-json/gcrev/v1/seo/run-diagnosis に差し替え
        setTimeout(function() {
            hideProgress();
            showToast('診断機能は現在準備中です。今後のアップデートで対応予定です。');
        }, 1500);
    };

})();
</script>

<?php get_footer(); ?>
