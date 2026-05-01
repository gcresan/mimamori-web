<?php
/*
Template Name: 戦略レポート（詳細版）
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( wp_login_url( home_url( '/strategy-report-detail/' ) ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = (int) $current_user->ID;
$req_ver      = isset( $_GET['ver'] ) ? sanitize_text_field( wp_unslash( $_GET['ver'] ) ) : '';
// 注: WordPress コアが ?embed=1 を予約しているため、独自パラメータは ?raw=1 を使う
$is_raw       = isset( $_GET['raw'] ) && $_GET['raw'] === '1';

// raw=1 で iframe 内にレンダリングする生 HTML を配信する
if ( $is_raw && class_exists( 'Gcrev_Manual_Strategy_Report_Page' ) ) {
    try {
        if ( Gcrev_Manual_Strategy_Report_Page::serve_for_current_user( 'detail', $req_ver ) ) {
            exit;
        }
    } catch ( \Throwable $e ) {
        if ( function_exists( 'file_put_contents' ) ) {
            file_put_contents(
                '/tmp/gcrev_strategy_report_debug.log',
                date( 'Y-m-d H:i:s' ) . ' [serve_detail] ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n",
                FILE_APPEND
            );
        }
    }
}

// テーマ内表示用: 詳細版が設定されているか判定
$has_detail = false;
$version    = null;
if ( class_exists( 'Gcrev_Manual_Strategy_Report_Page' ) ) {
    try {
        $version = $req_ver !== ''
            ? Gcrev_Manual_Strategy_Report_Page::get_version( $user_id, $req_ver )
            : Gcrev_Manual_Strategy_Report_Page::get_latest( $user_id );
        if ( $version && (int) ( $version['detail_id'] ?? 0 ) > 0 ) {
            $has_detail = true;
        }
    } catch ( \Throwable $e ) {
        // フォールバック表示
    }
}

set_query_var( 'gcrev_page_title', '深掘りレポート（詳細版）' );
set_query_var( 'gcrev_page_subtitle', 'レポート本体の詳細データ・分析を閲覧できます。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '詳細版', '深掘りレポート' ) );

get_header();
?>

<?php if ( $has_detail ) :
    $embed_url = add_query_arg(
        array_filter([
            'raw' => '1',
            'ver' => $req_ver !== '' ? $req_ver : null,
        ]),
        home_url( '/strategy-report-detail/' )
    );
    $period_label = '';
    if ( ! empty( $version['period'] ) && preg_match( '/^(\d{4})-(\d{2})$/', $version['period'], $m ) ) {
        $period_label = $m[1] . '年' . (int) $m[2] . '月版';
    }
    $label = (string) ( $version['label'] ?? '' );
?>

<div class="content-area" style="padding:24px 24px 48px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
        <div>
            <?php if ( $period_label !== '' || $label !== '' ) : ?>
                <div style="font-size:13px;color:#666;line-height:1.6;">
                    <?php if ( $period_label !== '' ) : ?>
                        <strong style="color:#1a1a1a;font-size:14px;"><?php echo esc_html( $period_label ); ?></strong>
                    <?php endif; ?>
                    <?php if ( $label !== '' ) : ?>
                        <span style="margin-left:8px;"><?php echo esc_html( $label ); ?></span>
                    <?php endif; ?>
                    <span style="margin-left:8px;color:#888;">／ 詳細版</span>
                </div>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="ss-btn" href="<?php echo esc_url( home_url( '/strategy-report/' . ( $req_ver !== '' ? '?ver=' . rawurlencode( $req_ver ) : '' ) ) ); ?>"
               style="background:#27ae60;color:#fff;border:1px solid #27ae60;text-decoration:none;">📋 概要版に戻る</a>
        </div>
    </div>

    <iframe
        id="strategyReportDetailIframe"
        src="<?php echo esc_url( $embed_url ); ?>"
        title="深掘りレポート（詳細版）"
        style="width:100%;height:1500px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;display:block;"
        loading="lazy">
    </iframe>
</div>

<script>
(function () {
    var iframe = document.getElementById('strategyReportDetailIframe');
    if (!iframe) return;

    // 親URLにアンカー（#sec-XX）が付いていれば iframe の src にも引き継ぐ
    // → iframe 内の対象セクションへ自動スクロール
    if (window.location.hash) {
        try {
            var u = new URL(iframe.getAttribute('src'), window.location.origin);
            u.hash = window.location.hash;
            iframe.setAttribute('src', u.toString());
        } catch (e) { /* noop */ }
    }

    window.addEventListener('message', function (e) {
        if (!e.data || e.data.type !== 'mimamori-report-height') return;
        var h = Math.max(1000, parseInt(e.data.height, 10) || 0);
        iframe.style.height = (h + 24) + 'px';
    });
})();
</script>

<?php else : ?>

<div class="content-area" style="max-width:720px;margin:48px auto;padding:0 24px;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:48px 28px;text-align:center;">
        <div style="font-size:42px;margin-bottom:14px;">📊</div>
        <h2 style="font-size:20px;margin:0 0 10px;">詳細レポートは未設定です</h2>
        <p style="color:#666;line-height:1.8;margin:0 0 24px;">
            このアカウント向けの詳細レポートはまだアップロードされていません。<br>
            担当者にご連絡ください。
        </p>
        <p>
            <a class="ss-btn" href="<?php echo esc_url( home_url( '/strategy-report/' ) ); ?>"
               style="background:#27ae60;color:#fff;border:1px solid #27ae60;text-decoration:none;">← 深掘りレポートに戻る</a>
        </p>
    </div>
</div>

<?php endif; ?>

<?php get_footer(); ?>
