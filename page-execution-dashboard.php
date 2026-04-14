<?php
/**
 * Template Name: 実行ダッシュボード
 * Description: 「今やるべきこと」を表示し、そのまま実行できる伴走型UI。
 */

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

if ( ! mimamori_can_access_seo() ) {
    wp_safe_redirect( home_url( '/dashboard/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;

set_query_var( 'gcrev_page_title', '実行ダッシュボード' );
set_query_var( 'gcrev_page_subtitle', '「今やるべきこと」がひと目でわかります。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '実行ダッシュボード', 'SEO' ) );

get_header();
?>
<style>
/* =========================================================
   実行ダッシュボード — スタイル
   ========================================================= */
.content-area { max-width: none !important; padding: 44px 48px 64px; }

/* ローディング */
.exec-loading { text-align: center; padding: 80px 0; color: var(--mw-text-secondary); font-size: 15px; }
.exec-loading__spinner { display: inline-block; width: 32px; height: 32px; border: 3px solid var(--mw-border-light); border-top-color: var(--mw-primary-blue, #568184); border-radius: 50%; animation: exec-spin 0.8s linear infinite; margin-bottom: 16px; }
@keyframes exec-spin { to { transform: rotate(360deg); } }

/* セクション共通 */
.exec-section { margin-bottom: 32px; }
.exec-section__title { font-size: 16px; font-weight: 700; color: var(--mw-text-heading); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.exec-card { background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light); border-radius: var(--mw-radius-md, 12px); padding: 20px 24px; }

/* A. ステータスサマリー */
.exec-status { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 12px; }
.exec-status__card { text-align: center; padding: 20px 16px; }
.exec-status__value { font-size: 36px; font-weight: 700; color: var(--mw-text-heading); line-height: 1.2; }
.exec-status__label { font-size: 13px; color: var(--mw-text-secondary); margin-top: 4px; }
.exec-status__sub { font-size: 12px; margin-top: 6px; }
.exec-status__sub--up { color: #568184; }
.exec-status__sub--down { color: #C95A4F; }
.exec-status__sub--stable { color: var(--mw-text-tertiary); }
.exec-ai-message { background: var(--mw-bg-secondary); border-radius: var(--mw-radius-md, 12px); padding: 16px 20px; font-size: 14px; color: var(--mw-text-heading); line-height: 1.7; margin-bottom: 32px; }

/* B. アクションリスト */
.exec-actions__header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.exec-priority-group { margin-bottom: 20px; }
.exec-priority-label { font-size: 13px; font-weight: 600; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.exec-priority-label--high { color: #C95A4F; }
.exec-priority-label--medium { color: #D4A843; }
.exec-priority-label--low { color: #568184; }
.exec-priority-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
.exec-priority-dot--high { background: #C95A4F; }
.exec-priority-dot--medium { background: #D4A843; }
.exec-priority-dot--low { background: #568184; }

.exec-action-card { background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light); border-radius: var(--mw-radius-md, 12px); padding: 20px 24px; margin-bottom: 12px; transition: box-shadow 0.2s; }
.exec-action-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
.exec-action-card--completed { opacity: 0.6; }
.exec-action-card--skipped { opacity: 0.4; }
.exec-action-card__title { font-size: 15px; font-weight: 600; color: var(--mw-text-heading); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
.exec-action-card__title-check { color: #568184; font-size: 18px; }
.exec-action-card__meta { font-size: 13px; color: var(--mw-text-secondary); margin-bottom: 6px; line-height: 1.6; }
.exec-action-card__keyword { display: inline-block; background: var(--mw-bg-secondary); padding: 2px 10px; border-radius: 4px; font-size: 12px; color: var(--mw-text-heading); margin-right: 6px; }
.exec-action-card__effect { font-size: 12px; color: var(--mw-text-tertiary); margin-top: 8px; }
.exec-action-card__comparison { font-size: 12px; color: var(--mw-text-secondary); margin-top: 4px; }
.exec-action-card__buttons { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }

/* ボタン（page-writing.php から流用） */
.exec-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.25s ease; white-space: nowrap; }
.exec-btn--primary { background: var(--mw-primary-blue, #568184); color: #fff; }
.exec-btn--primary:hover:not(:disabled) { background: var(--mw-btn-primary-hover, #476C6F); box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.exec-btn--secondary { background: var(--mw-bg-secondary); color: var(--mw-text-heading); border: 1px solid var(--mw-border-light); }
.exec-btn--secondary:hover:not(:disabled) { background: var(--mw-bg-tertiary, #E6EEF0); }
.exec-btn--sm { padding: 6px 12px; font-size: 12px; }
.exec-btn--ghost { background: transparent; color: var(--mw-text-secondary); padding: 6px 12px; font-size: 12px; }
.exec-btn--ghost:hover { color: var(--mw-text-heading); background: var(--mw-bg-secondary); }
.exec-btn:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

/* C. 順位変動アラート */
.exec-rank-table { width: 100%; border-collapse: collapse; }
.exec-rank-table th { padding: 10px 16px; font-size: 13px; font-weight: 600; color: var(--mw-text-secondary); text-align: left; background: var(--mw-bg-secondary); border-bottom: 1px solid var(--mw-border-light); }
.exec-rank-table td { padding: 10px 16px; font-size: 14px; color: var(--mw-text-primary); border-bottom: 1px solid var(--mw-border-light); }
.exec-rank-table tr:last-child td { border-bottom: none; }
.exec-rank-change--danger { color: #C95A4F; font-weight: 600; }
.exec-rank-change--warning { color: #D4A843; font-weight: 600; }
.exec-rank-change--good { color: #568184; font-weight: 600; }
.exec-rank-change--stable { color: var(--mw-text-tertiary); }

/* D. 進捗トラッカー */
.exec-progress-bar { background: var(--mw-bg-secondary); border-radius: 6px; height: 12px; overflow: hidden; margin-bottom: 6px; }
.exec-progress-bar__fill { height: 100%; background: var(--mw-primary-blue, #568184); border-radius: 6px; transition: width 0.5s ease; }
.exec-progress-rate { font-size: 14px; font-weight: 600; color: var(--mw-text-heading); margin-bottom: 16px; }
.exec-progress-types { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.exec-progress-type { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--mw-text-primary); }
.exec-progress-type__bar { flex: 1; background: var(--mw-bg-secondary); border-radius: 4px; height: 8px; overflow: hidden; }
.exec-progress-type__fill { height: 100%; background: var(--mw-primary-blue, #568184); border-radius: 4px; }
.exec-progress-type__count { font-size: 12px; color: var(--mw-text-secondary); white-space: nowrap; min-width: 40px; text-align: right; }

/* E. 原因分析 */
.exec-cause-list { list-style: none; padding: 0; margin: 0; }
.exec-cause-item { padding: 14px 0; border-bottom: 1px solid var(--mw-border-light); }
.exec-cause-item:last-child { border-bottom: none; }
.exec-cause-item__title { font-size: 14px; font-weight: 600; color: var(--mw-text-heading); margin-bottom: 4px; }
.exec-cause-item__detail { font-size: 13px; color: var(--mw-text-secondary); line-height: 1.6; }

/* ガイドモーダル */
.exec-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 9999; align-items: center; justify-content: center; }
.exec-modal-overlay.is-open { display: flex; }
.exec-modal { background: var(--mw-bg-primary); border-radius: var(--mw-radius-md, 12px); padding: 32px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
.exec-modal__title { font-size: 18px; font-weight: 700; color: var(--mw-text-heading); margin-bottom: 8px; }
.exec-modal__subtitle { font-size: 13px; color: var(--mw-text-secondary); margin-bottom: 20px; }
.exec-modal__guide { font-size: 14px; color: var(--mw-text-primary); line-height: 1.8; }
.exec-modal__guide ol { padding-left: 20px; margin: 12px 0; }
.exec-modal__guide li { margin-bottom: 8px; }
.exec-modal__footer { display: flex; gap: 8px; margin-top: 24px; justify-content: flex-end; }

/* エラー */
.exec-error { text-align: center; padding: 60px 0; color: #C95A4F; }

/* 空状態 */
.exec-empty { text-align: center; padding: 40px 0; color: var(--mw-text-secondary); font-size: 14px; }

/* レスポンシブ */
@media (max-width: 768px) {
    .content-area { padding: 24px 16px 48px; }
    .exec-status { grid-template-columns: 1fr; }
    .exec-status__card { padding: 14px; }
    .exec-status__value { font-size: 28px; }
    .exec-progress-types { grid-template-columns: 1fr; }
    .exec-rank-table { font-size: 13px; }
    .exec-rank-table th, .exec-rank-table td { padding: 8px 10px; }
    .exec-modal { padding: 24px; }
}
</style>

<div id="exec-dashboard-app" class="content-area">

    <!-- ローディング -->
    <div id="exec-loading" class="exec-loading">
        <div class="exec-loading__spinner"></div>
        <div>データを読み込んでいます...</div>
    </div>

    <!-- エラー -->
    <div id="exec-error" class="exec-error" style="display:none;"></div>

    <!-- メインコンテンツ（JS で表示） -->
    <div id="exec-content" style="display:none;">

        <!-- A. ステータスサマリー -->
        <section class="exec-section">
            <div class="exec-status" id="exec-status-cards"></div>
            <div class="exec-ai-message" id="exec-ai-message"></div>
        </section>

        <!-- B. アクションリスト -->
        <section class="exec-section">
            <div class="exec-actions__header">
                <h2 class="exec-section__title">今月やること</h2>
                <button class="exec-btn exec-btn--secondary exec-btn--sm" id="exec-refresh-btn">再分析する</button>
            </div>
            <div id="exec-actions-list"></div>
        </section>

        <!-- C. 順位変動アラート -->
        <section class="exec-section">
            <h2 class="exec-section__title">順位変動</h2>
            <div class="exec-card" style="padding:0; overflow:hidden;">
                <div id="exec-rank-alerts" style="overflow-x:auto;"></div>
            </div>
        </section>

        <!-- D. 進捗トラッカー -->
        <section class="exec-section">
            <h2 class="exec-section__title">今月の進捗</h2>
            <div class="exec-card" id="exec-progress"></div>
        </section>

        <!-- E. 原因分析 -->
        <section class="exec-section">
            <h2 class="exec-section__title">順位変動の主な原因</h2>
            <div class="exec-card" id="exec-root-cause"></div>
        </section>

    </div>
</div>

<!-- ガイドモーダル -->
<div class="exec-modal-overlay" id="exec-guide-modal">
    <div class="exec-modal">
        <h3 class="exec-modal__title" id="exec-modal-title"></h3>
        <p class="exec-modal__subtitle" id="exec-modal-subtitle"></p>
        <div class="exec-modal__guide" id="exec-modal-guide"></div>
        <div class="exec-modal__footer">
            <button class="exec-btn exec-btn--secondary" id="exec-modal-close">閉じる</button>
            <button class="exec-btn exec-btn--primary" id="exec-modal-complete">完了にする</button>
        </div>
    </div>
</div>

<script>
// フォールバック: 外部JSのキャッシュに依存せず、再分析ボタンの動作を保証
(function(){
    var API  = <?php echo wp_json_encode( rest_url( 'gcrev/v1/execution' ) ); ?>;
    var NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

    // 再分析ボタン
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('#exec-refresh-btn');
        if (!btn) return;
        e.preventDefault();
        btn.disabled = true;
        btn.textContent = '分析中...';
        var actionsList = document.getElementById('exec-actions-list');
        if (actionsList) {
            actionsList.innerHTML = '<div style="text-align:center;padding:40px 0;color:#888">' +
                '<div style="display:inline-block;width:28px;height:28px;border:3px solid #ddd;border-top-color:#568184;border-radius:50%;animation:exec-spin 0.8s linear infinite;margin-bottom:12px"></div>' +
                '<div>AIがアクションを分析中です...<br>（30秒ほどかかります）</div></div>';
        }
        fetch(API + '/refresh', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && typeof window.GCREV !== 'undefined' && window.GCREV._renderAll) {
                window.GCREV._currentData = data;
                window.GCREV._renderAll(data);
            } else {
                location.reload();
            }
        })
        .catch(function(err) {
            if (actionsList) {
                actionsList.innerHTML = '<div style="text-align:center;padding:40px 0;color:#C95A4F">' +
                    'エラー: ' + (err.message || '不明なエラー') +
                    '<br><button onclick="location.reload()" style="margin-top:12px;padding:6px 16px;border:1px solid #ddd;border-radius:6px;cursor:pointer">再試行</button></div>';
            }
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = '再分析する';
        });
    });
})();
</script>
<?php get_footer(); ?>
