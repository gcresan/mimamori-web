<?php
/**
 * Template Name: 実行ダッシュボード
 * Description: 「考えなくても、やることが分かり、そのまま実行できる」作業指示UI。
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
set_query_var( 'gcrev_page_subtitle', 'やることが分かる。そのまま実行できる。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '実行ダッシュボード', 'SEO' ) );

get_header();
?>
<style>
/* =========================================================
   実行ダッシュボード v2 — スタイル
   ========================================================= */
.content-area { max-width: none !important; padding: 44px 48px 64px; }

/* ローディング */
.exec-loading { text-align: center; padding: 80px 0; color: var(--mw-text-secondary); font-size: 15px; }
.exec-loading__spinner { display: inline-block; width: 32px; height: 32px; border: 3px solid var(--mw-border-light); border-top-color: var(--mw-primary-blue, #568184); border-radius: 50%; animation: exec-spin 0.8s linear infinite; margin-bottom: 16px; }
@keyframes exec-spin { to { transform: rotate(360deg); } }

/* セクション共通 */
.exec-section { margin-bottom: 36px; }
.exec-section__title { font-size: 15px; font-weight: 700; color: var(--mw-text-heading); margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.exec-section__title-icon { font-size: 16px; }
.exec-card { background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light); border-radius: var(--mw-radius-md, 12px); padding: 20px 24px; }

/* ===== ① 今すぐやること（ヒーロー） ===== */
.exec-hero { background: linear-gradient(135deg, #568184 0%, #476C6F 100%); border-radius: var(--mw-radius-md, 12px); padding: 28px 32px; color: #fff; position: relative; overflow: hidden; }
.exec-hero::before { content: '⚡'; position: absolute; top: -10px; right: -10px; font-size: 80px; opacity: 0.08; }
.exec-hero__label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; margin-bottom: 10px; }
.exec-hero__title { font-size: 20px; font-weight: 700; line-height: 1.5; margin-bottom: 8px; }
.exec-hero__reason { font-size: 13px; opacity: 0.85; line-height: 1.6; margin-bottom: 18px; }
.exec-hero__btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 24px; background: #fff; color: #568184; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
.exec-hero__btn:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(0,0,0,0.2); }
.exec-hero__btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }

/* ===== ② 今月のノルマ ===== */
.exec-quota { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-bottom: 12px; }
.exec-quota__item { background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light); border-radius: var(--mw-radius-md, 12px); padding: 16px; text-align: center; }
.exec-quota__label { font-size: 12px; color: var(--mw-text-secondary); margin-bottom: 6px; }
.exec-quota__value { font-size: 22px; font-weight: 700; color: var(--mw-text-heading); }
.exec-quota__value span { font-size: 13px; font-weight: 400; color: var(--mw-text-tertiary); }
.exec-quota__bar { height: 4px; background: var(--mw-bg-secondary); border-radius: 2px; margin-top: 8px; overflow: hidden; }
.exec-quota__fill { height: 100%; background: var(--mw-primary-blue, #568184); border-radius: 2px; transition: width 0.5s; }
.exec-quota__fill--done { background: #568184; }
.exec-bulk-row { text-align: right; }

/* ===== ③ アクションカード ===== */
.exec-actions__header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.exec-action-card { background: var(--mw-bg-primary); border: 1px solid var(--mw-border-light); border-radius: var(--mw-radius-md, 12px); padding: 18px 22px; margin-bottom: 10px; transition: box-shadow 0.2s; }
.exec-action-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.05); }
.exec-action-card--completed { opacity: 0.55; border-left: 3px solid #568184; }
.exec-action-card--skipped { opacity: 0.4; border-left: 3px solid var(--mw-border-light); }
.exec-action-card__header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap; }
.exec-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; line-height: 1; }
.exec-badge--high { background: rgba(201,90,79,0.1); color: #C95A4F; }
.exec-badge--medium { background: rgba(212,168,67,0.1); color: #B8922E; }
.exec-badge--low { background: rgba(86,129,132,0.1); color: #568184; }
.exec-badge--auto { background: rgba(86,129,132,0.1); color: #568184; }
.exec-badge--manual { background: rgba(212,168,67,0.1); color: #B8922E; }
.exec-badge--done { background: rgba(86,129,132,0.12); color: #568184; }
.exec-action-card__title { font-size: 15px; font-weight: 600; color: var(--mw-text-heading); line-height: 1.5; }
.exec-action-card__reason { font-size: 13px; color: var(--mw-text-secondary); margin-top: 4px; line-height: 1.5; }
.exec-action-card__buttons { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; align-items: center; }

/* ボタン */
.exec-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.25s ease; white-space: nowrap; }
.exec-btn--primary { background: var(--mw-primary-blue, #568184); color: #fff; }
.exec-btn--primary:hover:not(:disabled) { background: var(--mw-btn-primary-hover, #476C6F); box-shadow: 0 4px 12px rgba(86,129,132,0.25); transform: translateY(-1px); }
.exec-btn--secondary { background: var(--mw-bg-secondary); color: var(--mw-text-heading); border: 1px solid var(--mw-border-light); }
.exec-btn--secondary:hover:not(:disabled) { background: var(--mw-bg-tertiary, #E6EEF0); }
.exec-btn--sm { padding: 6px 12px; font-size: 12px; }
.exec-btn--ghost { background: transparent; color: var(--mw-text-secondary); padding: 6px 12px; font-size: 12px; }
.exec-btn--ghost:hover { color: var(--mw-text-heading); background: var(--mw-bg-secondary); }
.exec-btn--bulk { background: var(--mw-primary-blue, #568184); color: #fff; padding: 10px 22px; font-size: 14px; }
.exec-btn--bulk:hover:not(:disabled) { background: var(--mw-btn-primary-hover, #476C6F); }
.exec-btn:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

/* ===== ⑤ 順位変動・原因 ===== */
.exec-rank-table { width: 100%; border-collapse: collapse; }
.exec-rank-table th { padding: 10px 16px; font-size: 13px; font-weight: 600; color: var(--mw-text-secondary); text-align: left; background: var(--mw-bg-secondary); border-bottom: 1px solid var(--mw-border-light); }
.exec-rank-table td { padding: 10px 16px; font-size: 14px; color: var(--mw-text-primary); border-bottom: 1px solid var(--mw-border-light); }
.exec-rank-table tr:last-child td { border-bottom: none; }
.exec-rank-change--danger { color: #C95A4F; font-weight: 600; }
.exec-rank-change--warning { color: #D4A843; font-weight: 600; }
.exec-rank-change--good { color: #568184; font-weight: 600; }
.exec-rank-change--stable { color: var(--mw-text-tertiary); }

.exec-cause-list { list-style: none; padding: 0; margin: 0; }
.exec-cause-item { padding: 12px 0; border-bottom: 1px solid var(--mw-border-light); display: flex; gap: 10px; align-items: baseline; }
.exec-cause-item:last-child { border-bottom: none; }
.exec-cause-num { font-size: 14px; font-weight: 700; color: var(--mw-primary-blue, #568184); min-width: 20px; }
.exec-cause-text { font-size: 14px; color: var(--mw-text-heading); line-height: 1.6; }

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

/* エラー・空 */
.exec-error { text-align: center; padding: 60px 0; color: #C95A4F; }
.exec-empty { text-align: center; padding: 40px 0; color: var(--mw-text-secondary); font-size: 14px; }

/* レスポンシブ */
@media (max-width: 768px) {
    .content-area { padding: 24px 16px 48px; }
    .exec-hero { padding: 22px 20px; }
    .exec-hero__title { font-size: 17px; }
    .exec-quota { grid-template-columns: repeat(2, 1fr); }
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

        <!-- ① 今すぐやること（ヒーロー） -->
        <section class="exec-section" id="exec-hero-section"></section>

        <!-- ② 今月のノルマ -->
        <section class="exec-section">
            <h2 class="exec-section__title"><span class="exec-section__title-icon">📊</span> 今月のノルマ</h2>
            <div class="exec-quota" id="exec-quota"></div>
            <div class="exec-bulk-row" id="exec-bulk-row"></div>
        </section>

        <!-- ③ やることリスト -->
        <section class="exec-section">
            <div class="exec-actions__header">
                <h2 class="exec-section__title"><span class="exec-section__title-icon">📋</span> やることリスト</h2>
                <button class="exec-btn exec-btn--secondary exec-btn--sm" id="exec-refresh-btn">再分析する</button>
            </div>
            <div id="exec-actions-list"></div>
        </section>

        <!-- ④ 順位が変わった理由 -->
        <section class="exec-section">
            <h2 class="exec-section__title"><span class="exec-section__title-icon">🔍</span> 順位が変わった理由</h2>
            <div class="exec-card" id="exec-root-cause"></div>
        </section>

        <!-- ⑤ 順位変動 -->
        <section class="exec-section">
            <h2 class="exec-section__title"><span class="exec-section__title-icon">📈</span> 順位変動</h2>
            <div class="exec-card" style="padding:0; overflow:hidden;">
                <div id="exec-rank-alerts" style="overflow-x:auto;"></div>
            </div>
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
// フォールバック: 再分析ボタンの動作を保証
(function(){
    var API  = <?php echo wp_json_encode( rest_url( 'gcrev/v1/execution' ) ); ?>;
    var NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('#exec-refresh-btn');
        if (!btn) return;
        e.preventDefault();
        btn.disabled = true;
        btn.textContent = '分析中...';
        var al = document.getElementById('exec-actions-list');
        if (al) al.innerHTML = '<div style="text-align:center;padding:40px 0;color:#888"><div style="display:inline-block;width:28px;height:28px;border:3px solid #ddd;border-top-color:#568184;border-radius:50%;animation:exec-spin 0.8s linear infinite;margin-bottom:12px"></div><div>AIが分析中です...</div></div>';
        fetch(API + '/refresh', { method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE}, credentials:'same-origin' })
        .then(function(r){return r.json()})
        .then(function(d){ if(d.success && window.GCREV && window.GCREV._renderAll){window.GCREV._currentData=d;window.GCREV._renderAll(d)}else{location.reload()} })
        .catch(function(err){ if(al)al.innerHTML='<div style="text-align:center;padding:40px 0;color:#C95A4F">エラー: '+(err.message||'不明')+'<br><button onclick="location.reload()" style="margin-top:12px;padding:6px 16px;border:1px solid #ddd;border-radius:6px;cursor:pointer">再試行</button></div>' })
        .finally(function(){btn.disabled=false;btn.textContent='再分析する'});
    });
})();
</script>
<?php get_footer(); ?>
