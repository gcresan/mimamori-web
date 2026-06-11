<?php
/*
Template Name: 戦略レポート（詳細版）
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( wp_login_url( home_url( '/strategy-report-detail/' ) ) );
    exit;
}

$current_user = mimamori_get_view_user_object();
$user_id = mimamori_get_view_user_id();

// プランゲート: 見える化プランはレポート閲覧不可（2026-06 改定）
if ( function_exists( 'mimamori_can_view_reports' ) && ! mimamori_can_view_reports( $user_id ) ) {
    mimamori_render_upgrade_page( '深掘りレポート', '改善提案プラン' );
}

$req_ver      = isset( $_GET['ver'] ) ? sanitize_text_field( wp_unslash( $_GET['ver'] ) ) : '';
// 注: WordPress コアが ?embed=1 を予約しているため、独自パラメータは ?raw=1 を使う
$is_raw       = isset( $_GET['raw'] ) && $_GET['raw'] === '1';

// raw=1 で iframe 内にレンダリングする生 HTML を配信する
if ( $is_raw ) {
    if ( class_exists( 'Gcrev_Manual_Strategy_Report_Page' ) ) {
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
    // 配信できなかった場合にテーマ全体を返すと iframe 内にサイドバー付き UI が再帰描画されるため、
    // 最小限の HTML だけ返して exit する。
    status_header( 404 );
    nocache_headers();
    header( 'Content-Type: text/html; charset=utf-8' );
    echo '<!doctype html><meta charset="utf-8"><title>Report not available</title>'
       . '<div style="font-family:sans-serif;padding:24px;color:#666;">レポートが見つかりませんでした。</div>';
    exit;
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
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '詳細版', 'レポート' ) );

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
            <button type="button" id="strategyReportDetailPdfBtn"
                    style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;color:#344054;font-size:13px;font-weight:500;cursor:pointer;font-family:inherit;line-height:1;transition:all 0.15s;white-space:nowrap;"
                    onmouseover="this.style.background='#f9fafb';this.style.borderColor='#98a2b3';"
                    onmouseout="this.style.background='#fff';this.style.borderColor='#d0d5dd';">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                PDF ダウンロード
            </button>
            <a class="ss-btn" href="<?php echo esc_url( home_url( '/strategy-report/' . ( $req_ver !== '' ? '?ver=' . rawurlencode( $req_ver ) : '' ) ) ); ?>"
               style="background:#27ae60;color:#fff;border:1px solid #27ae60;text-decoration:none;">📋 概要版に戻る</a>
        </div>
    </div>

    <iframe
        id="strategyReportDetailIframe"
        src="<?php echo esc_url( $embed_url ); ?>"
        title="深掘りレポート（詳細版）"
        scrolling="no"
        style="width:100%;height:300px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;display:block;overflow:hidden;"
        loading="lazy">
    </iframe>
</div>

<script>
(function () {
    var iframe = document.getElementById('strategyReportDetailIframe');
    if (!iframe) return;

    // 親URLの hash（#sec-XX）を抽出
    function currentAnchor() {
        var h = window.location.hash || '';
        return h.replace(/^#/, '');
    }

    // iframe に「指定アンカーへスクロール」を指示（iframe 側 JS が受信して scrollIntoView）
    function postScrollAnchor(id) {
        if (!id || !iframe.contentWindow) return;
        try {
            iframe.contentWindow.postMessage(
                { type: 'mimamori-scroll-to-anchor', anchor: id },
                window.location.origin
            );
        } catch (e) { /* noop */ }
    }

    // 同一オリジンの保険として iframe.contentDocument を直接スクロール
    function fallbackScroll(id) {
        try {
            var doc = iframe.contentDocument || iframe.contentWindow.document;
            if (!doc) return false;
            var target = doc.getElementById(id);
            if (!target) {
                try { target = doc.querySelector('[name="' + CSS.escape(id) + '"]'); } catch (e) {}
            }
            if (target && typeof target.scrollIntoView === 'function') {
                target.scrollIntoView({ behavior: 'auto', block: 'start' });
                return true;
            }
        } catch (e) { /* cross-origin */ }
        return false;
    }

    // iframe ロード完了時に親 hash を伝達してスクロール
    iframe.addEventListener('load', function () {
        var id = currentAnchor();
        if (!id) return;
        // 即時 / 200ms / 700ms と段階的に試す（フォント適用・遅延描画対策）
        postScrollAnchor(id); fallbackScroll(id);
        setTimeout(function () { postScrollAnchor(id); fallbackScroll(id); }, 200);
        setTimeout(function () { postScrollAnchor(id); fallbackScroll(id); }, 700);
        // 親側も iframe を画面上部に位置調整
        setTimeout(function () {
            try { iframe.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
        }, 250);
    });

    // 戻る・進む等で親 hash が変わった場合にも反応
    window.addEventListener('hashchange', function () {
        var id = currentAnchor();
        if (!id) return;
        postScrollAnchor(id);
        fallbackScroll(id);
    });

    // iframe の高さを内容に完全追従（短ければ短く、長ければ長く）
    window.addEventListener('message', function (e) {
        if (!e.data || e.data.type !== 'mimamori-report-height') return;
        var h = parseInt(e.data.height, 10) || 0;
        if (h <= 0) return;
        iframe.style.height = h + 'px';
    });

    // PDF ダウンロードボタン: iframe 内（レポート本体）だけを PDF 化する
    var pdfBtn = document.getElementById('strategyReportDetailPdfBtn');
    if (pdfBtn) {
        pdfBtn.addEventListener('click', function () {
            var doc = null;
            try { doc = iframe.contentDocument || iframe.contentWindow.document; } catch (e) {}
            if (!doc || !doc.body) {
                window.open(iframe.getAttribute('src'), '_blank', 'noopener');
                return;
            }
            if (typeof window.GCREV === 'undefined' || typeof GCREV.exportPdf !== 'function') {
                alert('PDF生成ライブラリの読み込みに失敗しました。ページを再読み込みしてください。');
                return;
            }
            var prevLabel = pdfBtn.innerHTML;
            pdfBtn.disabled = true;
            pdfBtn.innerHTML = 'PDF 生成中...';

            // iframe 本体の自然な幅をキャプチャ幅として採用 (最低980pxを保証)
            var bodyWidth = Math.max(
                doc.body.scrollWidth,
                doc.documentElement.scrollWidth,
                doc.body.offsetWidth,
                980
            );

            var periodSlug = <?php echo wp_json_encode( (string) ( $latest_version['period'] ?? 'report' ) ); ?>;
            var restore = function () {
                pdfBtn.disabled = false;
                pdfBtn.innerHTML = prevLabel;
            };
            GCREV.exportPdf({
                element:    doc.body,
                filename:   '深掘りレポート_詳細版_' + periodSlug + '.pdf',
                stageWidth: bodyWidth,
                margins:    [12, 10, 14, 10]
            }).then(restore).catch(function (err) {
                console.error('PDF generation failed', err);
                restore();
                alert(err && err.message ? err.message : 'PDFの生成に失敗しました。もう一度お試しください。');
            });
        });
    }
})();
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<?php
$gcrev_pdf_export_url  = get_stylesheet_directory_uri() . '/assets/js/gcrev-pdf-export.js';
$gcrev_pdf_export_path = get_stylesheet_directory() . '/assets/js/gcrev-pdf-export.js';
$gcrev_pdf_export_ver  = file_exists( $gcrev_pdf_export_path ) ? filemtime( $gcrev_pdf_export_path ) : '1';
?>
<script src="<?php echo esc_url( $gcrev_pdf_export_url . '?v=' . $gcrev_pdf_export_ver ); ?>" defer></script>

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
