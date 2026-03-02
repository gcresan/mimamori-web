/**
 * gcrev-legend-solo.js
 *
 * Chart.js v4 グローバルプラグイン — 凡例ソロ＋複数選択
 *
 * 【挙動】
 *   全選択中 → 凡例クリック → その項目だけ表示（ソロ）
 *   部分選択中 → 非表示の凡例クリック → その項目を追加表示（複数選択）
 *   部分選択中 → 表示中の凡例クリック → その項目を非表示に（最低1つは残す）
 *   最後の1項目を再クリック → 全選択に戻す（リセット）
 *
 * 【適用】
 *   Chart.js 読込後にこのスクリプトを読むだけで全チャートに自動適用。
 *   legend.display: false のチャートには影響しない。
 *   個別チャートが onClick を明示していればスキップ。
 *
 * window.GCREV.legendSolo として公開
 * v2.0.0 — 複数選択対応
 */
(function () {
    'use strict';

    if (typeof Chart === 'undefined') return;

    /* ============================
       Line / Bar 系（dataset 単位）
       onClick(e, legendItem, legend)
       ============================ */
    function datasetSoloClick(e, legendItem, legend) {
        var chart = legend.chart;
        var index = legendItem.datasetIndex;
        var total = chart.data.datasets.length;
        if (total < 2) return;

        // 現在の表示状態をカウント
        var visibleCount = 0;
        var isClickedVisible = false;
        for (var i = 0; i < total; i++) {
            if (!chart.getDatasetMeta(i).hidden) {
                visibleCount++;
            }
        }
        isClickedVisible = !chart.getDatasetMeta(index).hidden;

        if (visibleCount === total) {
            // 全選択 → クリックした項目だけ表示（ソロ）
            for (var j = 0; j < total; j++) {
                chart.getDatasetMeta(j).hidden = (j !== index);
            }
        } else if (visibleCount === 1 && isClickedVisible) {
            // 最後の1項目を再クリック → 全選択に戻す（リセット）
            for (var k = 0; k < total; k++) {
                chart.getDatasetMeta(k).hidden = false;
            }
        } else if (isClickedVisible) {
            // 表示中の項目をクリック → 非表示に（最低1つは残す）
            if (visibleCount > 1) {
                chart.getDatasetMeta(index).hidden = true;
            }
        } else {
            // 非表示の項目をクリック → 追加表示（複数選択）
            chart.getDatasetMeta(index).hidden = false;
        }

        chart.update();
    }

    /* ============================
       Doughnut / Pie 系（dataIndex 単位）
       onClick(e, legendItem, legend)
       ============================ */
    function doughnutSoloClick(e, legendItem, legend) {
        var chart = legend.chart;
        var index = legendItem.index;
        var total = chart.data.datasets[0] ? chart.data.datasets[0].data.length : 0;
        if (total < 2) return;

        // 現在の表示状態をカウント
        var visibleCount = 0;
        var isClickedVisible = chart.getDataVisibility(index);
        for (var i = 0; i < total; i++) {
            if (chart.getDataVisibility(i)) {
                visibleCount++;
            }
        }

        if (visibleCount === total) {
            // 全選択 → クリックした項目だけ表示（ソロ）
            for (var j = 0; j < total; j++) {
                if (j !== index) chart.toggleDataVisibility(j);
            }
        } else if (visibleCount === 1 && isClickedVisible) {
            // 最後の1項目を再クリック → 全選択に戻す（リセット）
            for (var k = 0; k < total; k++) {
                if (!chart.getDataVisibility(k)) chart.toggleDataVisibility(k);
            }
        } else if (isClickedVisible) {
            // 表示中の項目をクリック → 非表示に（最低1つは残す）
            if (visibleCount > 1) {
                chart.toggleDataVisibility(index);
            }
        } else {
            // 非表示の項目をクリック → 追加表示（複数選択）
            chart.toggleDataVisibility(index);
        }

        chart.update();
    }

    /* ============================
       Chart.js グローバルプラグイン登録
       ============================ */
    var LegendSoloPlugin = {
        id: 'gcrevLegendSolo',

        beforeInit: function (chart) {
            var legendOpts = chart.options.plugins && chart.options.plugins.legend;
            if (!legendOpts || legendOpts.display === false) return;

            // 既に個別 onClick が「ユーザー定義で」設定されているチャートはスキップ
            // ※ chart.options は Chart.js デフォルトをマージ済みのため、
            //    chart.config._config（ユーザー指定の生コンフィグ）を確認する
            var rawCfg    = chart.config._config || {};
            var rawLegend = rawCfg.options && rawCfg.options.plugins && rawCfg.options.plugins.legend;
            if (rawLegend && typeof rawLegend.onClick === 'function') return;

            var type = chart.config.type;
            var isDoughnutPie = (type === 'doughnut' || type === 'pie');

            // onClick を設定
            legendOpts.onClick = isDoughnutPie ? doughnutSoloClick : datasetSoloClick;

            // 非選択項目の視覚表現（薄い色）
            if (!legendOpts.labels) legendOpts.labels = {};
            var origGenerate = legendOpts.labels.generateLabels;

            legendOpts.labels.generateLabels = function (chart) {
                // まず元のラベル生成を実行
                var labels;
                if (origGenerate) {
                    labels = origGenerate(chart);
                } else {
                    // Chart.js のデフォルト生成を取得
                    var typeOverrides = Chart.overrides[chart.config.type];
                    var overrideGen = typeOverrides
                        && typeOverrides.plugins
                        && typeOverrides.plugins.legend
                        && typeOverrides.plugins.legend.labels
                        && typeOverrides.plugins.legend.labels.generateLabels;
                    if (overrideGen) {
                        labels = overrideGen(chart);
                    } else {
                        labels = Chart.defaults.plugins.legend.labels.generateLabels(chart);
                    }
                }

                // 非表示項目の見た目を変更
                labels.forEach(function (label) {
                    var isHidden = false;
                    if (typeof label.datasetIndex === 'number') {
                        isHidden = !!chart.getDatasetMeta(label.datasetIndex).hidden;
                    } else if (typeof label.index === 'number') {
                        isHidden = !chart.getDataVisibility(label.index);
                    }

                    if (isHidden) {
                        label.fontColor = 'rgba(0,0,0,0.25)';
                        label.fillStyle = 'rgba(0,0,0,0.10)';
                        label.strokeStyle = 'rgba(0,0,0,0.10)';
                        label.hidden = false; // hidden=true だと Chart.js が取消線を描画するため false に
                    }
                });

                return labels;
            };
        }
    };

    Chart.register(LegendSoloPlugin);

    /* ============================
       Public API
       ============================ */
    window.GCREV = window.GCREV || {};
    window.GCREV.legendSolo = {
        datasetSoloClick: datasetSoloClick,
        doughnutSoloClick: doughnutSoloClick
    };
})();
