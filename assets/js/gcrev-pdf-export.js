/**
 * gcrev-pdf-export.js
 *
 * 月次レポート・深堀レポート・MEOレポートのPDF出力を担う共通ヘルパー。
 *
 * 経緯:
 *   旧実装は html2pdf.bundle を使っていたが、html2pdf().from(elem) が内部の
 *   toContainer() で要素を「PDFページ内寸幅(A4縦・8mmマージン時で ~733px)」の
 *   独自コンテナに詰め直してからキャプチャするため、こちら側で windowWidth を
 *   渡しても無視され、レスポンシブな grid (auto-fill / @media) が崩れていた。
 *
 * 本ヘルパーは html2canvas を直接呼び、得たキャンバスをページごとに切り出して
 * jsPDF.addImage() で配置することで、指定した stageWidth がそのままレイアウト
 * のビューポート幅として効くようにしている。
 *
 * 依存:
 *   - https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js
 *   - https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js
 *   どちらも各ページ側で <script src="..." defer></script> として読み込む。
 *
 * 使い方:
 *   GCREV.exportPdf({
 *       element:    document.querySelector('.foo'),
 *       filename:   'レポート.pdf',
 *       stageWidth: 980          // html2canvasに渡す windowWidth/width(px)
 *   }).catch(err => alert(err.message));
 */
(function () {
    'use strict';
    var GCREV = window.GCREV = window.GCREV || {};

    var DEFAULTS = {
        stageWidth: 980,
        scale:      2,
        margins:    [10, 8, 12, 8],   // top, right, bottom, left (mm)
        a4WidthMm:  210,
        a4HeightMm: 297,
        jpegQuality: 0.95
    };

    function libsReady() {
        var jsPDFCtor = (window.jspdf && window.jspdf.jsPDF) || window.jsPDF;
        return typeof window.html2canvas === 'function' && typeof jsPDFCtor === 'function';
    }

    /**
     * 縦長canvasをA4ページごとに切り出してPDFに配置する。
     * 単一のaddImageだと縦圧縮になることがあるため、ページ単位で子canvasに
     * drawImage → addImageする。
     */
    function paginateCanvasIntoPdf(canvas, filename, opts) {
        var jsPDFCtor = (window.jspdf && window.jspdf.jsPDF) || window.jsPDF;
        var pdf = new jsPDFCtor({ unit: 'mm', format: 'a4', orientation: 'portrait' });
        var innerWmm = opts.a4WidthMm  - opts.margins[1] - opts.margins[3];
        var innerHmm = opts.a4HeightMm - opts.margins[0] - opts.margins[2];
        var pxPerMm  = canvas.width / innerWmm;
        var pageHpx  = Math.floor(innerHmm * pxPerMm);

        var yOffset = 0;
        var page = 0;
        while (yOffset < canvas.height) {
            if (page > 0) pdf.addPage();
            var sliceH = Math.min(pageHpx, canvas.height - yOffset);
            var slice = document.createElement('canvas');
            slice.width  = canvas.width;
            slice.height = sliceH;
            var ctx = slice.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, slice.width, slice.height);
            ctx.drawImage(canvas, 0, yOffset, canvas.width, sliceH, 0, 0, canvas.width, sliceH);
            var sliceHmm = sliceH / pxPerMm;
            pdf.addImage(
                slice.toDataURL('image/jpeg', opts.jpegQuality),
                'JPEG',
                opts.margins[3], opts.margins[0],
                innerWmm, sliceHmm
            );
            yOffset += pageHpx;
            page++;
        }
        pdf.save(filename);
    }

    /**
     * 任意要素をPDFに書き出す。
     * @param {Object}      cfg
     * @param {HTMLElement} cfg.element     キャプチャ対象 (必須)
     * @param {string}      cfg.filename    出力ファイル名 (必須)
     * @param {number}      [cfg.stageWidth=980] html2canvasに渡す windowWidth / width
     * @param {number}      [cfg.scale=2]
     * @param {number[]}    [cfg.margins=[10,8,12,8]] top/right/bottom/left (mm)
     * @returns {Promise<HTMLCanvasElement>}
     */
    GCREV.exportPdf = function (cfg) {
        if (!cfg || !cfg.element) {
            return Promise.reject(new Error('element is required'));
        }
        if (!libsReady()) {
            return Promise.reject(new Error('PDF生成ライブラリが読み込まれていません。ページを再読み込みしてください。'));
        }
        var opts = {
            stageWidth:  cfg.stageWidth  != null ? cfg.stageWidth  : DEFAULTS.stageWidth,
            scale:       cfg.scale       != null ? cfg.scale       : DEFAULTS.scale,
            margins:     cfg.margins     != null ? cfg.margins     : DEFAULTS.margins,
            a4WidthMm:   DEFAULTS.a4WidthMm,
            a4HeightMm:  DEFAULTS.a4HeightMm,
            jpegQuality: DEFAULTS.jpegQuality
        };
        return window.html2canvas(cfg.element, {
            scale:           opts.scale,
            useCORS:         true,
            backgroundColor: '#ffffff',
            logging:         false,
            windowWidth:     opts.stageWidth,
            width:           opts.stageWidth,
            scrollX:         0,
            scrollY:         0
        }).then(function (canvas) {
            paginateCanvasIntoPdf(canvas, cfg.filename, opts);
            return canvas;
        });
    };

    /**
     * クローン用の「画面外固定幅ステージ」を作る。
     * 月次レポート・MEOレポートのように「ページ内で動的にレイアウトが決まる
     * コンテンツを固定幅で取り直したい」ケース用。
     *
     * @param {HTMLElement[]} sourceElements クローンする元要素の配列
     * @param {number}        [width=980]
     * @param {Object}        [styles]       追加のインラインスタイル
     * @returns {HTMLDivElement|null}
     */
    GCREV.buildPdfStage = function (sourceElements, width, styles) {
        var w = width || 980;
        var appended = 0;
        var existing = document.getElementById('gcrevPdfStage');
        if (existing) existing.remove();
        var stage = document.createElement('div');
        stage.id = 'gcrevPdfStage';
        // 画面外配置 + 固定幅。fixedや z-index:-1 等は html2canvas のレイアウト測定で
        // 不具合を起こすことがあるため、シンプルな absolute + top:-99999px に統一する
        stage.style.cssText = [
            'position:absolute',
            'top:-99999px',
            'left:0',
            'width:' + w + 'px',
            'background:#fff',
            'box-sizing:border-box',
            'padding:16px',
            'margin:0',
            'font-family:"Noto Sans JP",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
            'color:#1f2937',
            'font-size:16px',
            'line-height:1.7'
        ].join(';');
        if (styles && typeof styles === 'object') {
            for (var k in styles) {
                if (Object.prototype.hasOwnProperty.call(styles, k)) {
                    stage.style[k] = styles[k];
                }
            }
        }
        (sourceElements || []).forEach(function (el) {
            if (el) {
                stage.appendChild(el.cloneNode(true));
                appended++;
            }
        });
        if (appended === 0) return null;
        document.body.appendChild(stage);
        return stage;
    };
})();
