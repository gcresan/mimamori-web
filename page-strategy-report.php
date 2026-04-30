<?php
/*
Template Name: 戦略レポート
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = (int) $current_user->ID;

// 手動アップロード版の戦略レポートが設定されていれば、それを生のHTMLとして配信
// （AI生成レポートより優先。?ver=ID でバージョン指定、未指定は最新版）
if ( class_exists( 'Gcrev_Manual_Strategy_Report_Page' ) ) {
    $req_ver = isset( $_GET['ver'] ) ? sanitize_text_field( wp_unslash( $_GET['ver'] ) ) : '';
    if ( Gcrev_Manual_Strategy_Report_Page::serve_for_current_user( 'simple', $req_ver ) ) {
        exit;
    }
}

set_query_var( 'gcrev_page_title', '戦略レポート' );
set_query_var( 'gcrev_page_subtitle', '戦略と実データを突き合わせた経営者向けレポート（毎月自動生成）' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '戦略レポート', '戦略連動型 月次レポート' ) );

get_header();
?>

<div class="srpage">

    <header class="srpage-header">
        <div class="srpage-header__lead">
            <h2 class="srpage-header__title">🧠 戦略レポート</h2>
            <p class="srpage-header__desc">設定済み戦略と先月のGA4 / GSCデータをAIが突き合わせ、ズレ・問題・原因・改善アクションを毎月レポートします。</p>
        </div>
        <div class="srpage-header__actions">
            <select class="srpage-select" id="srHistorySelect" aria-label="月を選択">
                <option value="">— 履歴を読み込み中 —</option>
            </select>
            <button type="button" class="ss-btn ss-btn--primary" id="srGenerateBtn" hidden>🔄 やり直し生成</button>
        </div>
    </header>

    <!-- 状態: 戦略未設定 -->
    <div class="srpage-state srpage-state--empty" id="srStateNoStrategy" hidden>
        <h3>📝 戦略がまだ設定されていません</h3>
        <p>戦略レポートを生成するには、まず「戦略設定」でターゲット・課題・差別化要素・コンバージョン導線を入力する必要があります。</p>
        <p><a class="ss-btn ss-btn--primary" href="<?php echo esc_url( home_url( '/strategy-settings/' ) ); ?>">戦略を設定する →</a></p>
    </div>

    <!-- 状態: レポート未生成（戦略はあるが今月分まだ） -->
    <div class="srpage-state srpage-state--ready" id="srStateNoReport" hidden>
        <h3>✨ 最新のレポートがまだ生成されていません</h3>
        <p>「やり直し生成」ボタンを押すと、対象月のデータをAIが分析してレポートを作成します（30秒〜2分）。</p>
        <p><button type="button" class="ss-btn ss-btn--primary" id="srGenerateBtnEmpty">🚀 レポートを生成する</button></p>
    </div>

    <!-- 状態: 生成中 -->
    <div class="srpage-state srpage-state--running" id="srStateRunning" hidden>
        <div class="ss-pdf-progress__spinner"></div>
        <h3>⏳ AIがレポートを作成しています…</h3>
        <p class="srpage-running__text">通常 30秒〜2分ほどかかります。完了したら自動で表示されます。</p>
        <p class="srpage-running__sub" id="srRunningElapsed">経過: 0 秒</p>
    </div>

    <!-- 状態: 失敗 -->
    <div class="srpage-state srpage-state--failed" id="srStateFailed" hidden>
        <h3>❌ レポート生成に失敗しました</h3>
        <p class="srpage-failed__msg" id="srFailedMsg"></p>
        <p><button type="button" class="ss-btn ss-btn--primary" id="srGenerateBtnRetry">🔁 もう一度試す</button></p>
    </div>

    <!-- レポート本体（rendered_html を流し込む） -->
    <div class="srpage-body" id="srReportBody"></div>

    <!-- メタ情報フッタ -->
    <footer class="srpage-meta" id="srMetaFooter" hidden>
        <span id="srMetaModel">—</span> ・
        <span>生成: <span id="srMetaTime">—</span></span> ・
        <span>整合度: <span id="srMetaScore">—</span></span>
    </footer>

    <!-- トースト -->
    <div class="ss-toast" id="srToast" hidden></div>

</div>

<?php get_footer(); ?>
