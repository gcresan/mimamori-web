<?php
/*
Template Name: 戦略レポート
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = mimamori_get_view_user_object();
$user_id = mimamori_get_view_user_id();
$req_ver      = isset( $_GET['ver'] ) ? sanitize_text_field( wp_unslash( $_GET['ver'] ) ) : '';
// 注: WordPress コアが ?embed=1 を予約しているため、独自パラメータは ?raw=1 を使う
$is_raw       = isset( $_GET['raw'] ) && $_GET['raw'] === '1';

// raw=1 が指定された時だけ、HTML レポートを生のまま配信する（iframe の中身用）。
// それ以外はテーマのヘッダー/サイドバーを維持してメインに iframe を埋め込む。
if ( $is_raw ) {
    if ( class_exists( 'Gcrev_Manual_Strategy_Report_Page' ) ) {
        try {
            if ( Gcrev_Manual_Strategy_Report_Page::serve_for_current_user( 'simple', $req_ver ) ) {
                exit;
            }
        } catch ( \Throwable $e ) {
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
    // raw=1 で配信できなかった場合にテーマ全体を返すと iframe 内にサイドバー付き UI が再帰描画される。
    // 必ず最小限の HTML だけ返して exit する。
    status_header( 404 );
    nocache_headers();
    header( 'Content-Type: text/html; charset=utf-8' );
    echo '<!doctype html><meta charset="utf-8"><title>Report not available</title>'
       . '<div style="font-family:sans-serif;padding:24px;color:#666;">レポートが見つかりませんでした。</div>';
    exit;
}

// テーマ内表示用: 手動レポートの有無を判定
$has_manual_report = false;
$latest_version    = null;
if ( class_exists( 'Gcrev_Manual_Strategy_Report_Page' ) ) {
    try {
        $latest_version = $req_ver !== ''
            ? Gcrev_Manual_Strategy_Report_Page::get_version( $user_id, $req_ver )
            : Gcrev_Manual_Strategy_Report_Page::get_latest( $user_id );
        if ( $latest_version && (int) ( $latest_version['simple_id'] ?? 0 ) > 0 ) {
            $has_manual_report = true;
        }
    } catch ( \Throwable $e ) {
        // 例外時はフォールバック表示
    }
}

set_query_var( 'gcrev_page_title', '深掘りレポート' );
set_query_var( 'gcrev_page_subtitle', '担当者がアップロードした深掘りレポートを閲覧できます。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '深掘りレポート', 'レポート' ) );

get_header();
?>

<?php if ( $has_manual_report ) :
    $embed_url = add_query_arg(
        array_filter([
            'raw' => '1',
            'ver' => $req_ver !== '' ? $req_ver : null,
        ]),
        home_url( '/strategy-report/' )
    );
    $period_label = '';
    if ( ! empty( $latest_version['period'] ) && preg_match( '/^(\d{4})-(\d{2})$/', $latest_version['period'], $m ) ) {
        $period_label = $m[1] . '年' . (int) $m[2] . '月版';
    }
    $label        = (string) ( $latest_version['label'] ?? '' );
    $has_detail_v = (int) ( $latest_version['detail_id'] ?? 0 ) > 0;
    $detail_link  = home_url( '/strategy-report-detail/' . ( $req_ver !== '' ? '?ver=' . rawurlencode( $req_ver ) : '' ) );
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
                </div>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ( $has_detail_v ) : ?>
                <a class="ss-btn"
                   style="background:#1a1a1a;color:#fff;border:1px solid #1a1a1a;text-decoration:none;"
                   href="<?php echo esc_url( $detail_link ); ?>">📊 詳細版を開く</a>
            <?php endif; ?>
            <button type="button" id="strategyReportPdfBtn"
                    style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;color:#344054;font-size:13px;font-weight:500;cursor:pointer;font-family:inherit;line-height:1;transition:all 0.15s;white-space:nowrap;"
                    onmouseover="this.style.background='#f9fafb';this.style.borderColor='#98a2b3';"
                    onmouseout="this.style.background='#fff';this.style.borderColor='#d0d5dd';">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                PDF ダウンロード
            </button>
            <a class="ss-btn" href="<?php echo esc_url( home_url( '/strategy-report-history/' ) ); ?>"
               style="background:#fff;color:#333;border:1px solid #ccc;text-decoration:none;">📚 過去のレポート</a>
        </div>
    </div>

    <iframe
        id="strategyReportIframe"
        src="<?php echo esc_url( $embed_url ); ?>"
        title="深掘りレポート"
        scrolling="no"
        style="width:100%;height:300px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;display:block;overflow:hidden;"
        loading="lazy">
    </iframe>
</div>

<script>
(function () {
    var iframe = document.getElementById('strategyReportIframe');
    if (!iframe) return;

    var detailPath = '<?php echo esc_js( wp_parse_url( home_url( '/strategy-report-detail/' ), PHP_URL_PATH ) ?: '/strategy-report-detail/' ); ?>';

    // 親URLにアンカーがあれば iframe の src に引き継ぐ
    if (window.location.hash) {
        try {
            var u = new URL(iframe.getAttribute('src'), window.location.origin);
            u.hash = window.location.hash;
            iframe.setAttribute('src', u.toString());
        } catch (e) { /* noop */ }
    }

    // iframe ロード完了後、概要版内の「詳細版へのリンク」を親フレームで開くようにする。
    // 概要版 HTML 内に <a href="/strategy-report-detail/#sec-X"> のようなアンカー付きリンクが
    // ある場合、そのまま iframe 内でクリックすると iframe 内ナビゲーションになってしまい、
    // 詳細版ページのアンカー引き継ぎ処理（親 URL hash → iframe src）が発動しない。
    // 同一オリジンなので親から iframe.contentDocument を操作してリンクを書き換える。
    function rewireDetailLinks() {
        try {
            var doc = iframe.contentDocument || iframe.contentWindow.document;
            if (!doc) return;
            var anchors = doc.querySelectorAll('a[href]');
            anchors.forEach(function (a) {
                var href = a.getAttribute('href') || '';
                // /strategy-report-detail/ への相対 / 絶対リンクを検出
                var isDetailLink =
                    href.indexOf(detailPath) === 0 ||
                    href.indexOf('/strategy-report-detail') !== -1 ||
                    /strategy-report-detail/.test(href);
                if (!isDetailLink) return;
                // 親フレームで開かせる
                a.setAttribute('target', '_top');
                // フォールバック: 何らかの理由で target=_top が効かない場合に備え、
                // クリック時に明示的に親 window を navigate する。
                a.addEventListener('click', function (ev) {
                    try {
                        // ctrl/cmd/middle click は新規タブ動作を尊重
                        if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1) return;
                        ev.preventDefault();
                        var resolved = new URL(href, doc.baseURI || window.location.href);
                        window.top.location.href = resolved.toString();
                    } catch (err) { /* noop */ }
                }, { once: false });
            });
        } catch (e) { /* cross-origin 等 - noop */ }
    }

    iframe.addEventListener('load', rewireDetailLinks);

    window.addEventListener('message', function (e) {
        if (!e.data || e.data.type !== 'mimamori-report-height') return;
        // iframe 内コンテンツの実高さに完全追従（短ければ短く、長ければ長く）
        var h = parseInt(e.data.height, 10) || 0;
        if (h <= 0) return;
        iframe.style.height = h + 'px';
    });

    // PDF ダウンロードボタン: iframe 内（レポート本体）だけを PDF 化する
    // 同一オリジンなので iframe.contentDocument.body を html2pdf のターゲットにできる
    var pdfBtn = document.getElementById('strategyReportPdfBtn');
    if (pdfBtn) {
        pdfBtn.addEventListener('click', function () {
            if (typeof html2pdf === 'undefined') {
                alert('PDF生成ライブラリの読み込みに失敗しました。ページを再読み込みしてください。');
                return;
            }
            var doc = null;
            try { doc = iframe.contentDocument || iframe.contentWindow.document; } catch (e) {}
            if (!doc || !doc.body) {
                window.open(iframe.getAttribute('src'), '_blank', 'noopener');
                return;
            }
            var prevLabel = pdfBtn.innerHTML;
            pdfBtn.disabled = true;
            pdfBtn.innerHTML = 'PDF 生成中...';

            // iframe 本体の自然な幅をキャプチャ幅として採用し、PDFページとのスケールがズレないようにする
            var bodyWidth = Math.max(
                doc.body.scrollWidth,
                doc.documentElement.scrollWidth,
                doc.body.offsetWidth,
                800
            );

            var periodSlug = <?php echo wp_json_encode( (string) ( $latest_version['period'] ?? 'report' ) ); ?>;
            var opt = {
                margin:      [12, 10, 14, 10],
                filename:    '深掘りレポート_概要版_' + periodSlug + '.pdf',
                image:       { type: 'jpeg', quality: 0.95 },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    scrollX: 0, scrollY: 0,
                    backgroundColor: '#ffffff',
                    windowWidth: bodyWidth,
                    width: bodyWidth
                },
                jsPDF:     { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak: { mode: ['css', 'legacy'] }
            };

            var restore = function () {
                pdfBtn.disabled = false;
                pdfBtn.innerHTML = prevLabel;
            };
            html2pdf().set(opt).from(doc.body).save()
                .then(restore)
                .catch(function (err) {
                    console.error('PDF generation failed', err);
                    restore();
                    alert('PDFの生成に失敗しました。もう一度お試しください。');
                });
        });
    }
})();
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer></script>

<?php else : ?>

<div class="content-area" style="max-width:720px;margin:48px auto;padding:0 24px;">
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:48px 28px;text-align:center;">
        <div style="font-size:42px;margin-bottom:14px;">📭</div>
        <h2 style="font-size:20px;margin:0 0 10px;">深掘りレポートはまだ発行されていません</h2>
        <p style="color:#666;line-height:1.8;margin:0 0 24px;">
            このアカウント向けの深掘りレポートは、現在準備中です。<br>
            担当者がレポートをアップロードすると、ここに自動で表示されます。
        </p>
        <p>
            <a class="ss-btn" href="<?php echo esc_url( home_url( '/strategy-report-history/' ) ); ?>"
               style="background:#fff;color:#333;border:1px solid #ccc;text-decoration:none;">📚 過去のレポート一覧</a>
            <a class="ss-btn" href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>"
               style="margin-left:8px;background:#27ae60;color:#fff;border:1px solid #27ae60;text-decoration:none;">🏠 ダッシュボードに戻る</a>
        </p>
    </div>
</div>

<?php endif; ?>

<?php get_footer(); ?>
