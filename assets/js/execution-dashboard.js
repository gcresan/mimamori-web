/**
 * 実行ダッシュボード — フロントエンドJS
 */
(function () {
    'use strict';

    const API  = (typeof gcrevExecVars !== 'undefined' && gcrevExecVars.apiBase) || '/wp-json/gcrev/v1/execution';
    const NONCE = (typeof gcrevExecVars !== 'undefined' && gcrevExecVars.nonce) || '';

    let currentData   = null;
    let guideActionId = null;

    /* ==================================================================
       API ヘルパー
       ================================================================== */

    async function apiFetch(path, method, body) {
        const opts = {
            method: method || 'GET',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
        };
        if (NONCE) { opts.headers['X-WP-Nonce'] = NONCE; }
        if (body)  { opts.body = JSON.stringify(body); }

        const res  = await fetch(API + path, opts);
        const json = await res.json();
        if (!res.ok || json.success === false) {
            throw new Error(json.error || json.message || 'API Error');
        }
        return json;
    }

    /* ==================================================================
       初回ロード
       ================================================================== */

    async function loadDashboard() {
        show('exec-loading');
        hide('exec-content');
        hide('exec-error');

        try {
            currentData = await apiFetch('/dashboard');
            hide('exec-loading');
            show('exec-content');
            renderAll(currentData);
        } catch (e) {
            hide('exec-loading');
            var el = document.getElementById('exec-error');
            if (el) {
                el.textContent = 'データの読み込みに失敗しました: ' + e.message;
                el.style.display = '';
            }
        }
    }

    function renderAll(data) {
        renderStatus(data.status || {});
        renderActions(data.actions || []);
        renderRankAlerts(data.rank_alerts || []);
        renderProgress(data.progress || {});
        renderRootCause(data.root_cause || {});
    }

    /* ==================================================================
       A. ステータスサマリー
       ================================================================== */

    function renderStatus(status) {
        var container = document.getElementById('exec-status-cards');
        if (!container) return;

        var score  = status.score || 0;
        var up     = status.rank_up || 0;
        var down   = status.rank_down || 0;
        var stable = status.rank_stable || 0;
        var newKw  = status.rank_new || 0;

        // 実行率は progress から
        var progress = currentData && currentData.progress ? currentData.progress : {};
        var overall  = progress.overall || {};
        var rate     = Math.round((overall.rate || 0) * 100);
        var completed = overall.completed || 0;
        var total     = overall.total || 0;

        container.innerHTML =
            '<div class="exec-card exec-status__card">' +
                '<div class="exec-status__value">' + esc(score) + '</div>' +
                '<div class="exec-status__label">総合スコア</div>' +
            '</div>' +
            '<div class="exec-card exec-status__card">' +
                '<div class="exec-status__value">' +
                    '<span class="exec-status__sub--up">' + up + '</span>' +
                    '<span style="color:var(--mw-text-tertiary);font-size:16px;margin:0 4px">/</span>' +
                    '<span class="exec-status__sub--down">' + down + '</span>' +
                    '<span style="color:var(--mw-text-tertiary);font-size:16px;margin:0 4px">/</span>' +
                    '<span class="exec-status__sub--stable">' + stable + '</span>' +
                '</div>' +
                '<div class="exec-status__label">順位 上昇 / 下落 / 安定</div>' +
                (newKw > 0 ? '<div class="exec-status__sub exec-status__sub--up">+ ' + newKw + '件 新規ランクイン</div>' : '') +
            '</div>' +
            '<div class="exec-card exec-status__card">' +
                '<div class="exec-status__value">' + rate + '<span style="font-size:18px">%</span></div>' +
                '<div class="exec-status__label">今月の実行率 (' + completed + '/' + total + ')</div>' +
            '</div>';

        var msgEl = document.getElementById('exec-ai-message');
        if (msgEl) {
            msgEl.textContent = status.ai_message || '';
            msgEl.style.display = status.ai_message ? '' : 'none';
        }
    }

    /* ==================================================================
       B. アクションリスト
       ================================================================== */

    function renderActions(actions) {
        var container = document.getElementById('exec-actions-list');
        if (!container) return;

        if (!actions.length) {
            container.innerHTML = '<div class="exec-empty">アクションはまだ生成されていません。</div>';
            return;
        }

        var groups = { high: [], medium: [], low: [] };
        actions.forEach(function (a) {
            var p = a.priority || 'medium';
            if (!groups[p]) groups[p] = [];
            groups[p].push(a);
        });

        var priorityLabels = {
            high:   { label: '優先度：高', cls: 'high' },
            medium: { label: '優先度：中', cls: 'medium' },
            low:    { label: '優先度：低', cls: 'low' },
        };

        var html = '';
        ['high', 'medium', 'low'].forEach(function (p) {
            var items = groups[p];
            if (!items || !items.length) return;

            var info = priorityLabels[p];
            html += '<div class="exec-priority-group">';
            html += '<div class="exec-priority-label exec-priority-label--' + info.cls + '">';
            html += '<span class="exec-priority-dot exec-priority-dot--' + info.cls + '"></span> ' + info.label;
            html += '</div>';

            items.forEach(function (a) {
                html += renderActionCard(a);
            });

            html += '</div>';
        });

        container.innerHTML = html;

        // イベントバインド
        container.querySelectorAll('[data-exec-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var actionId = parseInt(btn.dataset.execAction, 10);
                var type     = btn.dataset.execType;
                if (type === 'execute')  { handleExecute(actionId, btn); }
                if (type === 'complete') { handleComplete(actionId, btn); }
                if (type === 'skip')     { handleSkip(actionId, btn); }
                if (type === 'guide')    { handleGuide(actionId); }
            });
        });
    }

    function renderActionCard(a) {
        var statusCls = a.status === 'completed' ? ' exec-action-card--completed' :
                        a.status === 'skipped'   ? ' exec-action-card--skipped' : '';
        var checkIcon = a.status === 'completed' ? '<span class="exec-action-card__title-check">&#10003;</span>' : '';

        var html = '<div class="exec-action-card' + statusCls + '" data-action-id="' + a.id + '">';
        html += '<div class="exec-action-card__title">' + checkIcon + esc(a.title) + '</div>';

        if (a.target_keyword) {
            html += '<span class="exec-action-card__keyword">' + esc(a.target_keyword) + '</span>';
        }

        html += '<div class="exec-action-card__meta">' + esc(a.reason) + '</div>';

        if (a.comparison_self || a.comparison_competitor) {
            html += '<div class="exec-action-card__comparison">';
            if (a.comparison_self)       html += '御社: ' + esc(a.comparison_self);
            if (a.comparison_competitor) html += ' / 競合平均: ' + esc(a.comparison_competitor);
            html += '</div>';
        }

        if (a.expected_effect) {
            html += '<div class="exec-action-card__effect">期待される効果: ' + esc(a.expected_effect) + '</div>';
        }

        // ボタン
        if (a.status === 'pending' || a.status === 'in_progress') {
            html += '<div class="exec-action-card__buttons">';

            if (a.is_auto_executable) {
                var execLabel = a.action_type === 'article_create' ? '記事をつくる' :
                                a.action_type === 'meo_post' ? '投稿をつくる' : '実行する';
                html += '<button class="exec-btn exec-btn--primary" data-exec-action="' + a.id + '" data-exec-type="execute"' +
                        (a.status === 'in_progress' ? ' disabled' : '') + '>' +
                        (a.status === 'in_progress' ? '実行中...' : execLabel) + '</button>';
            }

            if (a.guide_text) {
                html += '<button class="exec-btn exec-btn--secondary" data-exec-action="' + a.id + '" data-exec-type="guide">ガイドを見る</button>';
            } else if (!a.is_auto_executable) {
                html += '<button class="exec-btn exec-btn--primary" data-exec-action="' + a.id + '" data-exec-type="complete">完了にする</button>';
            }

            html += '<button class="exec-btn exec-btn--ghost" data-exec-action="' + a.id + '" data-exec-type="skip">スキップ</button>';
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    /* ==================================================================
       C. 順位変動アラート
       ================================================================== */

    function renderRankAlerts(alerts) {
        var container = document.getElementById('exec-rank-alerts');
        if (!container) return;

        if (!alerts.length) {
            container.innerHTML = '<div class="exec-empty" style="padding:24px">順位データがまだありません。</div>';
            return;
        }

        var html = '<table class="exec-rank-table">';
        html += '<thead><tr><th>キーワード</th><th>前回</th><th>今回</th><th>変動</th><th>検索ボリューム</th></tr></thead>';
        html += '<tbody>';

        alerts.forEach(function (a) {
            var changeCls = 'exec-rank-change--' + a.severity;
            var changeText = '';

            if (a.change === 'new') {
                changeText = '新規';
            } else if (a.change === 'dropped') {
                changeText = '圏外落ち';
            } else if (typeof a.change === 'number') {
                if (a.change > 0) changeText = '+' + a.change + '位';
                else if (a.change < 0) changeText = a.change + '位';
                else changeText = '変化なし';
            }

            html += '<tr>';
            html += '<td>' + esc(a.keyword) + '</td>';
            html += '<td>' + esc(a.prev_rank_label) + '</td>';
            html += '<td>' + esc(a.curr_rank_label) + '</td>';
            html += '<td class="' + changeCls + '">' + changeText + '</td>';
            html += '<td>' + (a.search_volume ? a.search_volume.toLocaleString() : '-') + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        container.innerHTML = html;
    }

    /* ==================================================================
       D. 進捗トラッカー
       ================================================================== */

    function renderProgress(progress) {
        var container = document.getElementById('exec-progress');
        if (!container) return;

        var overall  = progress.overall || {};
        var rate     = Math.round((overall.rate || 0) * 100);
        var byType   = progress.by_type || [];

        var html = '';
        html += '<div class="exec-progress-bar"><div class="exec-progress-bar__fill" style="width:' + rate + '%"></div></div>';
        html += '<div class="exec-progress-rate">' + rate + '% 完了 (' + (overall.completed || 0) + '/' + (overall.total || 0) + ')</div>';

        if (byType.length) {
            html += '<div class="exec-progress-types">';
            byType.forEach(function (t) {
                var pct = t.total > 0 ? Math.round((t.completed / t.total) * 100) : 0;
                html += '<div class="exec-progress-type">';
                html += '<span style="min-width:80px">' + esc(t.label) + '</span>';
                html += '<div class="exec-progress-type__bar"><div class="exec-progress-type__fill" style="width:' + pct + '%"></div></div>';
                html += '<span class="exec-progress-type__count">' + t.completed + '/' + t.total + '</span>';
                html += '</div>';
            });
            html += '</div>';
        }

        container.innerHTML = html;
    }

    /* ==================================================================
       E. 原因分析
       ================================================================== */

    function renderRootCause(cause) {
        var container = document.getElementById('exec-root-cause');
        if (!container) return;

        var bullets = cause.bullets || [];
        if (!bullets.length) {
            container.innerHTML = '<div class="exec-empty">分析データがありません。</div>';
            return;
        }

        var html = '<ul class="exec-cause-list">';
        bullets.forEach(function (b, i) {
            html += '<li class="exec-cause-item">';
            html += '<div class="exec-cause-item__title">' + (i + 1) + '. ' + esc(b.title) + '</div>';
            html += '<div class="exec-cause-item__detail">' + esc(b.detail) + '</div>';
            html += '</li>';
        });
        html += '</ul>';

        container.innerHTML = html;
    }

    /* ==================================================================
       アクションハンドラ
       ================================================================== */

    async function handleExecute(actionId, btn) {
        btn.disabled = true;
        btn.textContent = '実行中...';
        try {
            await apiFetch('/action/' + actionId + '/execute', 'POST');
            // ダッシュボード再読み込み
            await loadDashboard();
        } catch (e) {
            alert('エラー: ' + e.message);
            btn.disabled = false;
            btn.textContent = '実行する';
        }
    }

    async function handleComplete(actionId, btn) {
        btn.disabled = true;
        try {
            await apiFetch('/action/' + actionId + '/complete', 'POST');
            await loadDashboard();
        } catch (e) {
            alert('エラー: ' + e.message);
            btn.disabled = false;
        }
    }

    async function handleSkip(actionId, btn) {
        btn.disabled = true;
        try {
            await apiFetch('/action/' + actionId + '/skip', 'POST');
            await loadDashboard();
        } catch (e) {
            alert('エラー: ' + e.message);
            btn.disabled = false;
        }
    }

    function handleGuide(actionId) {
        var actions = currentData && currentData.actions ? currentData.actions : [];
        var action  = null;
        for (var i = 0; i < actions.length; i++) {
            if (actions[i].id === actionId) { action = actions[i]; break; }
        }
        if (!action) return;

        guideActionId = actionId;
        document.getElementById('exec-modal-title').textContent = action.title || '';
        document.getElementById('exec-modal-subtitle').textContent = action.reason || '';
        document.getElementById('exec-modal-guide').innerHTML = action.guide_text || '<p>ガイド情報がありません。</p>';
        document.getElementById('exec-guide-modal').classList.add('is-open');
    }

    /* ==================================================================
       再分析
       ================================================================== */

    var refreshBtn = document.getElementById('exec-refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', async function () {
            refreshBtn.disabled = true;
            refreshBtn.textContent = '分析中...';
            try {
                await apiFetch('/refresh', 'POST');
                await loadDashboard();
            } catch (e) {
                alert('エラー: ' + e.message);
            }
            refreshBtn.disabled = false;
            refreshBtn.textContent = '再分析する';
        });
    }

    /* ==================================================================
       モーダル
       ================================================================== */

    document.getElementById('exec-modal-close').addEventListener('click', function () {
        document.getElementById('exec-guide-modal').classList.remove('is-open');
        guideActionId = null;
    });

    document.getElementById('exec-modal-complete').addEventListener('click', async function () {
        if (!guideActionId) return;
        var btn = this;
        btn.disabled = true;
        try {
            await apiFetch('/action/' + guideActionId + '/complete', 'POST');
            document.getElementById('exec-guide-modal').classList.remove('is-open');
            guideActionId = null;
            await loadDashboard();
        } catch (e) {
            alert('エラー: ' + e.message);
        }
        btn.disabled = false;
    });

    document.getElementById('exec-guide-modal').addEventListener('click', function (e) {
        if (e.target === this) {
            this.classList.remove('is-open');
            guideActionId = null;
        }
    });

    /* ==================================================================
       ユーティリティ
       ================================================================== */

    function esc(str) {
        if (!str) return '';
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function show(id) { var el = document.getElementById(id); if (el) el.style.display = ''; }
    function hide(id) { var el = document.getElementById(id); if (el) el.style.display = 'none'; }

    /* ==================================================================
       Init
       ================================================================== */

    document.addEventListener('DOMContentLoaded', loadDashboard);

})();
