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

// 管理者がアップロードした戦略レポート（手動アップロード）を表示する。
// ?ver=ID でバージョン指定、未指定は最新版を生のHTMLとして配信する。
if ( class_exists( 'Gcrev_Manual_Strategy_Report_Page' ) ) {
    $req_ver = isset( $_GET['ver'] ) ? sanitize_text_field( wp_unslash( $_GET['ver'] ) ) : '';
    try {
        if ( Gcrev_Manual_Strategy_Report_Page::serve_for_current_user( 'simple', $req_ver ) ) {
            exit;
        }
    } catch ( \Throwable $e ) {
        // 例外が起きてもサイト全体を落とさない。ログに残してフォールバック表示へ進む
        if ( function_exists( 'file_put_contents' ) ) {
            file_put_contents(
                '/tmp/gcrev_strategy_report_debug.log',
                date( 'Y-m-d H:i:s' ) . ' [serve_simple] ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n",
                FILE_APPEND
            );
        }
    }
}

// 手動レポートが未設定の場合のフォールバック（テーマ内で案内のみ表示）
set_query_var( 'gcrev_page_title', '戦略レポート' );
set_query_var( 'gcrev_page_subtitle', '担当者がアップロードした戦略レポートを閲覧できます。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '戦略レポート', '戦略連動型 月次レポート' ) );

get_header();
?>

<div class="content-area" style="max-width:720px;margin:48px auto;padding:0 24px;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:48px 28px;text-align:center;">
        <div style="font-size:42px;margin-bottom:14px;">📭</div>
        <h2 style="font-size:20px;margin:0 0 10px;">戦略レポートはまだ発行されていません</h2>
        <p style="color:#666;line-height:1.8;margin:0 0 24px;">
            このアカウント向けの戦略レポートは、現在準備中です。<br>
            担当者がレポートをアップロードすると、ここに自動で表示されます。
        </p>
        <p>
            <a class="ss-btn" href="<?php echo esc_url( home_url( '/strategy-report-history/' ) ); ?>"
               style="background:#fff;color:#333;border:1px solid #ccc;">📚 過去のレポート一覧</a>
            <a class="ss-btn ss-btn--primary" href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>"
               style="margin-left:8px;">🏠 ダッシュボードに戻る</a>
        </p>
    </div>
</div>

<?php get_footer(); ?>
