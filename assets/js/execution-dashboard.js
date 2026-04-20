/**
 * 実行ダッシュボード v2 — フロントエンドJS
 * 「作業指示ツール」としてのUI
 */
(function () {
    'use strict';

    var API   = (typeof gcrevExecVars !== 'undefined' && gcrevExecVars.apiBase) || '/wp-json/gcrev/v1/execution';
    var NONCE = (typeof gcrevExecVars !== 'undefined' && gcrevExecVars.nonce) || '';
    var currentData   = null;

    /* ================================================================
       API
       ================================================================ */
    function apiFetch(path, method, body, timeoutMs) {
        var opts = { method: method || 'GET', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin' };
        if (NONCE) opts.headers['X-WP-Nonce'] = NONCE;
        if (body)  opts.body = JSON.stringify(body);
        var ms = timeoutMs || ((method === 'POST') ? 120000 : 30000);
        var ctrl = new AbortController(); opts.signal = ctrl.signal;
        var t = setTimeout(function(){ ctrl.abort(); }, ms);
        return fetch(API + path, opts)
            .then(function(r){ clearTimeout(t); return r.text(); })
            .then(function(txt){
                var j; try { j = JSON.parse(txt); } catch(e){ throw new Error('不正なレスポンスです'); }
                if (!j || j.success === false) throw new Error(j.error || j.message || 'API Error');
                return j;
            })
            .catch(function(e){ clearTimeout(t); throw e.name === 'AbortError' ? new Error('タイムアウトしました') : e; });
    }

    /* ================================================================
       Load
       ================================================================ */
    function loadDashboard() {
        show('exec-loading'); hide('exec-content'); hide('exec-error');
        apiFetch('/dashboard')
            .then(function(data){
                currentData = data;
                hide('exec-loading'); show('exec-content');
                renderAll(data);
                if (data.needs_generate) triggerGenerate();
            })
            .catch(function(e){
                hide('exec-loading');
                var el = document.getElementById('exec-error');
                if (el) { el.textContent = 'データの読み込みに失敗しました: ' + e.message; el.style.display = ''; }
            });
    }

    function triggerGenerate() {
        var al = document.getElementById('exec-actions-list');
        if (al) al.innerHTML = '<div class="exec-empty"><div class="exec-loading__spinner" style="margin-bottom:12px"></div><div>AIが分析中です...<br>（30秒ほどかかります）</div></div>';
        apiFetch('/refresh', 'POST')
            .then(function(d){ currentData = d; renderAll(d); })
            .catch(function(e){
                if (al) al.innerHTML = '<div class="exec-empty" style="color:#C95A4F">エラー: ' + esc(e.message) + '<br><button class="exec-btn exec-btn--secondary exec-btn--sm" style="margin-top:12px" onclick="location.reload()">再試行</button></div>';
            });
    }

    function renderAll(data) {
        renderHero(data.actions || []);
        renderQuota(data.progress || {});
        renderActions(data.actions || []);
        renderRootCause(data.root_cause);
        renderRankAlerts(data.rank_alerts || []);
    }

    /* ================================================================
       ① 今すぐやること（ヒーロー）
       ================================================================ */
    function renderHero(actions) {
        var section = document.getElementById('exec-hero-section');
        if (!section) return;

        // 最も優先度が高い pending アクションを1つ選ぶ
        var top = null;
        var order = { high: 0, medium: 1, low: 2 };
        for (var i = 0; i < actions.length; i++) {
            if (actions[i].status !== 'pending') continue;
            if (!top || (order[actions[i].priority] || 9) < (order[top.priority] || 9)) {
                top = actions[i];
            }
        }

        if (!top) {
            section.innerHTML = '<div class="exec-hero" style="background:#568184"><div class="exec-hero__label">✅ 今月のタスク</div><div class="exec-hero__title">すべて完了しました！</div><div class="exec-hero__reason">引き続き順位の変化を確認しましょう。</div></div>';
            return;
        }

        // ヒーローは「今すぐやること」を案内する。詳しく見るで実行ガイドを展開できる。
        var heroDetailId = 'exec-hero-detail-' + top.id;
        section.innerHTML =
            '<div class="exec-hero">' +
                '<div class="exec-hero__label">⚡ 今すぐやること</div>' +
                '<div class="exec-hero__title">' + esc(top.title) + '</div>' +
                '<div class="exec-hero__reason">' + esc(top.reason) + '</div>' +
                '<button type="button" class="exec-hero__detail-toggle" ' +
                    'data-exec-detail-toggle="' + top.id + '" ' +
                    'aria-expanded="false" aria-controls="' + heroDetailId + '">詳しく見る</button>' +
                '<div class="exec-action-card__detail exec-hero__detail" id="' + heroDetailId + '" ' +
                    'data-exec-detail-panel="' + top.id + '" hidden></div>' +
            '</div>';

        // ヒーロー内のトグルも有効化
        bindActionButtons(section);
    }

    /* ================================================================
       ② 今月のノルマ
       ================================================================ */
    function renderQuota(progress) {
        var container = document.getElementById('exec-quota');
        if (!container) return;

        var byType = progress.by_type || [];
        if (!byType.length) {
            container.innerHTML = '<div class="exec-empty">「再分析する」でアクションを生成してください。</div>';
            return;
        }

        var html = '';
        byType.forEach(function(t) {
            var pct = t.total > 0 ? Math.round((t.completed / t.total) * 100) : 0;
            var done = pct >= 100;
            html += '<div class="exec-quota__item">';
            html += '<div class="exec-quota__label">' + esc(t.label) + '</div>';
            html += '<div class="exec-quota__value">' + t.completed + '<span> / ' + t.total + '</span></div>';
            html += '<div class="exec-quota__bar"><div class="exec-quota__fill' + (done ? ' exec-quota__fill--done' : '') + '" style="width:' + pct + '%"></div></div>';
            html += '</div>';
        });
        container.innerHTML = html;
    }

    /* ================================================================
       ③ やることリスト
       ================================================================ */
    function renderActions(actions) {
        var container = document.getElementById('exec-actions-list');
        if (!container) return;

        if (!actions.length) {
            container.innerHTML = '<div class="exec-empty">アクションはまだ生成されていません。</div>';
            return;
        }

        // 未完了（pending / in_progress）を上、完了済み（completed / skipped）を下に
        var active = [], done = [];
        actions.forEach(function(a) {
            if (a.status === 'completed' || a.status === 'skipped') done.push(a);
            else active.push(a);
        });

        var html = '';
        active.forEach(function(a) { html += renderActionCard(a); });
        if (done.length) {
            html += '<div style="margin:16px 0 8px;font-size:12px;color:var(--mw-text-tertiary)">完了・スキップ済み</div>';
            done.forEach(function(a) { html += renderActionCard(a); });
        }

        container.innerHTML = html;
        bindActionButtons(container);
    }

    function renderActionCard(a) {
        var status = a.status;
        var isCompleted  = status === 'completed';
        var isSkipped    = status === 'skipped';
        var isInProgress = status === 'in_progress';
        var isDone       = isCompleted || isSkipped;

        var cls = isCompleted ? ' exec-action-card--completed'
                : isSkipped   ? ' exec-action-card--skipped'
                : '';

        var priorityLabel = { high: '高', medium: '中', low: '低' };
        var priorityCls   = { high: 'high', medium: 'medium', low: 'low' };

        var html = '<div class="exec-action-card' + cls + '" data-action-id="' + a.id + '">';

        // ヘッダー（バッジ）
        html += '<div class="exec-action-card__header">';
        if (isCompleted) {
            html += '<span class="exec-badge exec-badge--done">✅ 完了</span>';
        } else if (isSkipped) {
            html += '<span class="exec-badge exec-badge--done">スキップ</span>';
        } else {
            html += '<span class="exec-badge exec-badge--' + (priorityCls[a.priority] || 'medium') + '">' + (priorityLabel[a.priority] || '中') + '</span>';
            if (isInProgress) {
                html += '<span class="exec-badge exec-badge--done">⏳ 作業中</span>';
            }
        }
        if (a.action_type_label) {
            html += '<span class="exec-badge exec-badge--type">' + esc(a.action_type_label) + '</span>';
        }
        html += '</div>';

        // タイトル
        html += '<div class="exec-action-card__title">' + esc(a.title) + '</div>';

        // 理由（1行）
        html += '<div class="exec-action-card__reason">' + esc(a.reason) + '</div>';

        // ボタン
        html += '<div class="exec-action-card__buttons">';
        if (isDone || isInProgress) {
            html += '<button class="exec-btn exec-btn--ghost" data-exec-action="' + a.id + '" data-exec-type="revert">元に戻す</button>';
        } else {
            html += '<button class="exec-btn exec-btn--primary" data-exec-action="' + a.id + '" data-exec-type="complete">完了にする</button>';
            html += '<button class="exec-btn exec-btn--ghost" data-exec-action="' + a.id + '" data-exec-type="skip">スキップ</button>';
        }

        // 詳しく見るトグル（リテラシー低めの方向けの実行手順ガイド）
        var detailId = 'exec-detail-' + a.id;
        html += '<button type="button" class="exec-action-card__detail-toggle" '
              + 'data-exec-detail-toggle="' + a.id + '" '
              + 'aria-expanded="false" aria-controls="' + detailId + '">詳しく見る</button>';
        html += '</div>';

        // 詳細パネル（初回展開時に AI ガイドを fetch）
        // guide_text が既に DB にある場合はサーバ側がHTML化済みのものを返してくれるが、
        // ここではプレースホルダだけ用意して、トグル時に loadActionGuide() で埋める。
        html += '<div class="exec-action-card__detail" id="' + detailId
              + '" data-exec-detail-panel="' + a.id + '" '
              + (a.guide_text ? 'data-exec-detail-cached="1"' : '')
              + ' hidden></div>';

        html += '</div>';
        return html;
    }

    function bindActionButtons(container) {
        container.querySelectorAll('[data-exec-action]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id   = parseInt(btn.dataset.execAction, 10);
                var type = btn.dataset.execType;
                if (type === 'complete') handleComplete(id, btn);
                if (type === 'skip')     handleSkip(id, btn);
                if (type === 'revert')   handleRevert(id, btn);
            });
        });

        // 詳しく見るトグル
        container.querySelectorAll('[data-exec-detail-toggle]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id    = parseInt(btn.dataset.execDetailToggle, 10);
                var panel = container.querySelector('[data-exec-detail-panel="' + id + '"]');
                if (!panel) return;

                var isOpen = btn.getAttribute('aria-expanded') === 'true';
                if (isOpen) {
                    panel.hidden = true;
                    btn.setAttribute('aria-expanded', 'false');
                    btn.textContent = '詳しく見る';
                    return;
                }

                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = '閉じる';
                panel.hidden = false;

                // 初回展開時のみ取得（HTMLが空ならロード）
                if (!panel.innerHTML.trim()) {
                    loadActionGuide(id, panel);
                }
            });
        });
    }

    function loadActionGuide(id, panel) {
        panel.innerHTML = '<div class="exec-action-card__detail--loading">'
            + '<div class="exec-loading__spinner" style="margin-bottom:10px"></div>'
            + '<div>分かりやすい手順を作成中です…（最大30秒ほど）</div></div>';

        apiFetch('/action/' + id + '/guide')
            .then(function(d) {
                if (d && d.guide_html) {
                    panel.innerHTML = d.guide_html;
                } else {
                    panel.innerHTML = '<div class="exec-action-card__detail--error">'
                        + 'ガイドを取得できませんでした。</div>';
                }
            })
            .catch(function(e) {
                panel.innerHTML = '<div class="exec-action-card__detail--error">'
                    + 'エラー: ' + esc(e.message) + ' '
                    + '<button type="button" class="exec-btn exec-btn--secondary exec-btn--sm" '
                    + 'style="margin-left:8px" data-exec-detail-retry="' + id + '">再試行</button>'
                    + '</div>';
                var retry = panel.querySelector('[data-exec-detail-retry]');
                if (retry) retry.addEventListener('click', function() { loadActionGuide(id, panel); });
            });
    }

    /* ================================================================
       ④ 順位が変わった理由
       ================================================================ */
    function renderRootCause(cause) {
        var container = document.getElementById('exec-root-cause');
        if (!container) return;
        if (!cause) {
            container.innerHTML = '<div class="exec-empty">「再分析する」で分析を開始できます。</div>';
            return;
        }
        var bullets = cause.bullets || [];
        if (!bullets.length) {
            container.innerHTML = '<div class="exec-empty">分析データがありません。</div>';
            return;
        }
        var html = '<ul class="exec-cause-list">';
        bullets.forEach(function(b, i) {
            html += '<li class="exec-cause-item"><span class="exec-cause-num">' + (i + 1) + '.</span><span class="exec-cause-text">' + esc(b.title || b.detail || '') + '</span></li>';
        });
        html += '</ul>';
        container.innerHTML = html;
    }

    /* ================================================================
       ⑤ 順位変動
       ================================================================ */
    function renderRankAlerts(alerts) {
        var container = document.getElementById('exec-rank-alerts');
        if (!container) return;
        if (!alerts.length) {
            container.innerHTML = '<div class="exec-empty" style="padding:24px">順位データがまだありません。</div>';
            return;
        }
        var html = '<table class="exec-rank-table"><thead><tr><th>キーワード</th><th>前回</th><th>今回</th><th>変動</th><th>検索ボリューム</th></tr></thead><tbody>';
        alerts.forEach(function(a) {
            var cls = 'exec-rank-change--' + a.severity;
            var txt = a.change === 'new' ? '新規' : a.change === 'dropped' ? '圏外落ち' :
                      typeof a.change === 'number' ? (a.change > 0 ? '+' + a.change + '位' : a.change < 0 ? a.change + '位' : '変化なし') : '';
            html += '<tr><td>' + esc(a.keyword) + '</td><td>' + esc(a.prev_rank_label) + '</td><td>' + esc(a.curr_rank_label) + '</td><td class="' + cls + '">' + txt + '</td><td>' + (a.search_volume ? a.search_volume.toLocaleString() : '-') + '</td></tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    /* ================================================================
       Action Handlers
       ================================================================ */
    function handleComplete(id, btn) {
        btn.disabled = true;
        apiFetch('/action/' + id + '/complete', 'POST')
            .then(function() { loadDashboard(); })
            .catch(function(e) { alert('エラー: ' + e.message); btn.disabled = false; });
    }
    function handleSkip(id, btn) {
        btn.disabled = true;
        apiFetch('/action/' + id + '/skip', 'POST')
            .then(function() { loadDashboard(); })
            .catch(function(e) { alert('エラー: ' + e.message); btn.disabled = false; });
    }
    function handleRevert(id, btn) {
        btn.disabled = true; btn.textContent = '戻しています...';
        apiFetch('/action/' + id + '/revert', 'POST')
            .then(function() { loadDashboard(); })
            .catch(function(e) { alert('エラー: ' + e.message); btn.disabled = false; btn.textContent = '元に戻す'; });
    }
    /* ================================================================
       再分析ボタン
       ================================================================ */
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('#exec-refresh-btn');
        if (!btn) return;
        btn.disabled = true; btn.textContent = '分析中...';
        apiFetch('/refresh', 'POST')
            .then(function(d) { currentData = d; renderAll(d); })
            .catch(function(err) { alert('エラー: ' + err.message); })
            .finally(function() { btn.disabled = false; btn.textContent = '再分析する'; });
    });

    /* ================================================================
       Utilities
       ================================================================ */
    function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function show(id) { var el = document.getElementById(id); if (el) el.style.display = ''; }
    function hide(id) { var el = document.getElementById(id); if (el) el.style.display = 'none'; }

    // 外部公開
    window.GCREV = window.GCREV || {};
    window.GCREV._renderAll = renderAll;
    window.GCREV._currentData = currentData;

    document.addEventListener('DOMContentLoaded', loadDashboard);
})();
