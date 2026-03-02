/**
 * gcrev-legend-solo.js
 *
 * Chart.js v4 グローバルプラグイン — 凡例ソロ切替
 *
 * 【挙動】
 *   全選択中 → 凡例クリック → その項目だけ表示（他を非表示）
 *   単一選択中 → 同じ項目クリック → 全選択に戻す
 *   単一選択中 → 別の項目クリック → その項目だけに切替
 *   最後の1つはOFF不可（常に最低1系列表示）
 *
 * 【適用】
 *   Chart.js 読込後にこのスクリプトを読むだけで全チャートに自動適用。
 *   legend.display: false のチャートには影響しない。
 *   個別チャートが onClick を明示していればスキップ。
 *
 * window.GCREV.legendSolo として公開
 * v1.0.0
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

        var visibleCount = 0;
        var visibleIndex = -1;
        for (var i = 0; i < total; i++) {
            if (!chart.getDatasetMeta(i).hidden) {
                visibleCount++;
                visibleIndex = i;
            }
        }

        var allVisible  = (visibleCount === total);
        var soloVisible = (visibleCount === 1 && visibleIndex === index);

        if (allVisible) {
            // 全選択 → ソロ
            for (var j = 0; j < total; j++) {
                chart.getDatasetMeta(j).hidden = (j !== index);
            }
        } else if (soloVisible) {
            // 同じ項目の再クリック → 全選択に戻す
            for (var k = 0; k < total; k++) {
                chart.getDatasetMeta(k).hidden = false;
            }
        } else {
            // 部分選択 → クリックした項目だけに切替
            for (var m = 0; m < total; m++) {
                chart.getDatasetMeta(m).hidden = (m !== index);
            }
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

        var visibleCount = 0;
        var visibleIndex = -1;
        for (var i = 0; i < total; i++) {
            if (chart.getDataVisibility(i)) {
                visibleCount++;
                visibleIndex = i;
            }
        }

        var allVisible  = (visibleCount === total);
        var soloVisible = (visibleCount === 1 && visibleIndex === index);

        if (allVisible) {
            for (var j = 0; j < total; j++) {
                if (j !== index) chart.toggleDataVisibility(j);
            }
        } else if (soloVisible) {
            for (var k = 0; k < total; k++) {
                if (!chart.getDataVisibility(k)) chart.toggleDataVisibility(k);
            }
        } else {
            for (var m = 0; m < total; m++) {
                var vis = chart.getDataVisibility(m);
                if (m === index && !vis) chart.toggleDataVisibility(m);
                if (m !== index && vis)  chart.toggleDataVisibility(m);
            }
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
