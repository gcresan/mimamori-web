/**
 * 戦略レポート閲覧ページ (page-strategy-report.php) のフロントロジック
 *
 * 機能:
 *   - 起動時: 履歴を取得 → 直近 completed のレポートをロード
 *   - 履歴セレクト変更: その月のレポートをロード
 *   - 「やり直し生成」: POST /strategy-report/generate → 進捗 polling
 *   - エラー / skipped / 戦略未設定 の各状態を分岐表示
 *
 * 設定: window.gcrevStrategyReportConfig = { restRoot, nonce, defaultYearMonth }
 */
(function () {
    'use strict';

    var cfg = window.gcrevStrategyReportConfig || {};
    var REST = (cfg.restRoot || '/wp-json/').replace(/\/$/, '') + '/gcrev_insights/v1';
    var NONCE = cfg.nonce || '';
    var DEFAULT_YM = cfg.defaultYearMonth || '';

    function $(s, r) { return (r || document).querySelector(s); }
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

    function api(path, opts) {
        opts = opts || {};
        return fetch(REST + path, {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: Object.assign({
                'Content-Type': 'application/json',
                'X-WP-Nonce': NONCE
            }, opts.headers || {}),
            body: opts.body ? JSON.stringify(opts.body) : undefined
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) {
                    var err = new Error(data && data.error ? data.error : 'request failed');
                    err.status = res.status;
                    err.payload = data;
                    throw err;
                }
                return data;
            });
        });
    }

    function showToast(msg, type) {
        var el = $('#srToast');
        if (!el) return;
        el.textContent = msg;
        el.className = 'ss-toast' + (type === 'error' ? ' ss-toast--error' : (type === 'success' ? ' ss-toast--success' : ''));
        el.hidden = false;
        clearTimeout(showToast._t);
        showToast._t = setTimeout(function () { el.hidden = true; }, 3500);
    }

    function showState(name) {
        $$('.srpage-state').forEach(function (el) {
            var s = el.id;
            el.hidden = !(
                (name === 'no-strategy' && s === 'srStateNoStrategy') ||
                (name === 'no-report'   && s === 'srStateNoReport')   ||
                (name === 'running'     && s === 'srStateRunning')    ||
                (name === 'failed'      && s === 'srStateFailed')
            );
        });
        // body / meta はレポートが現にあるとき以外は隠す
        var hasBody = (name === 'has-report');
        $('#srReportBody').hidden = !hasBody;
        $('#srMetaFooter').hidden = !hasBody;
    }

    // =========================================================
    // 履歴セレクト
    // =========================================================
    function loadHistory() {
        return api('/strategy-report/history').then(function (data) {
            var rows = (data && data.reports) || [];
            var sel = $('#srHistorySelect');
            if (!rows.length) {
                sel.innerHTML = '<option value="">レポートはまだありません</option>';
                return [];
            }
            var html = rows.map(function (r) {
                var ym = r.year_month || '';
                var status = r.status || '';
                var label = ym + ' (' + status + ')';
                if (r.alignment_score !== null && r.alignment_score !== undefined && status === 'completed') {
                    label += ' / 整合度 ' + r.alignment_score;
                }
                return '<option value="' + ym + '">' + label + '</option>';
            }).join('');
            sel.innerHTML = html;
            return rows;
        });
    }

    // =========================================================
    // レポート表示
    // =========================================================
    function renderReport(report) {
        var body = $('#srReportBody');
        if (report.rendered_html) {
            body.innerHTML = report.rendered_html;
        } else {
            body.innerHTML = '<p class="srpage-failed__msg">レポート本体が見つかりません。</p>';
        }
        $('#srMetaModel').textContent = report.ai_model || 'unknown';
        $('#srMetaTime').textContent  = report.finished_at || report.created_at || '—';
        $('#srMetaScore').textContent = (report.alignment_score === null || report.alignment_score === undefined)
            ? '—' : (report.alignment_score + ' / 100');
        $('#srGenerateBtn').hidden = false;
        showState('has-report');
    }

    function loadReportForMonth(year_month) {
        if (!year_month) {
            return loadCurrent();
        }
        return api('/strategy-report/status?year_month=' + encodeURIComponent(year_month))
            .then(function (data) {
                var r = data && data.report;
                if (!r) {
                    showState('no-report');
                    $('#srGenerateBtn').hidden = false;
                    return null;
                }
                applyReportState(r, year_month);
                return r;
            });
    }

    function loadCurrent() {
        return api('/strategy-report/current').then(function (data) {
            var r = data && data.report;
            if (!r) {
                showState('no-report');
                $('#srGenerateBtn').hidden = true;
                // 戦略の有無を見る
                return checkStrategyForEmpty();
            }
            // 履歴セレクトもこの ym に揃える
            $('#srHistorySelect').value = r.year_month || '';
            applyReportState(r, r.year_month);
            return r;
        });
    }

    function applyReportState(r, year_month) {
        if (r.status === 'completed') {
            renderReport(r);
            return;
        }
        if (r.status === 'running' || r.status === 'pending') {
            startPolling(year_month || r.year_month);
            return;
        }
        if (r.status === 'failed') {
            $('#srFailedMsg').textContent = r.error_message || '不明なエラー';
            $('#srGenerateBtn').hidden = false;
            showState('failed');
            return;
        }
        if (r.status === 'skipped') {
            // skipped: 戦略未設定が原因かどうか
            $('#srFailedMsg').textContent = (r.error_message === 'no_active_strategy')
                ? 'この月の有効戦略が設定されていません。「戦略設定」で戦略を有効化してください。'
                : ('スキップされました: ' + (r.error_message || '理由不明'));
            $('#srGenerateBtn').hidden = false;
            showState('failed');
            return;
        }
        // unknown
        showState('no-report');
    }

    function checkStrategyForEmpty() {
        // 戦略未設定なら no-strategy へ。戦略があれば no-report のまま生成ボタンを出す
        return api('/strategy').then(function (data) {
            if (!data || !data.strategy) {
                showState('no-strategy');
            } else {
                $('#srGenerateBtn').hidden = false;
            }
        }).catch(function () { /* noop */ });
    }

    // =========================================================
    // 生成キュー投入 + ポーリング
    // =========================================================
    var pollState = { timer: null, ym: null, started: 0 };

    function startPolling(year_month) {
        showState('running');
        $('#srGenerateBtn').hidden = true;
        pollState.ym = year_month;
        pollState.started = Date.now();
        tickPoll();
    }

    function stopPolling() {
        if (pollState.timer) { clearTimeout(pollState.timer); pollState.timer = null; }
    }

    function tickPoll() {
        if (!pollState.ym) return;
        var elapsed = Math.round((Date.now() - pollState.started) / 1000);
        $('#srRunningElapsed').textContent = '経過: ' + elapsed + ' 秒';

        api('/strategy-report/status?year_month=' + encodeURIComponent(pollState.ym)).then(function (data) {
            var r = data && data.report;
            if (r && r.status === 'completed') {
                stopPolling();
                renderReport(r);
                showToast('レポートを生成しました', 'success');
                loadHistory(); // 履歴も再読込
                return;
            }
            if (r && r.status === 'failed') {
                stopPolling();
                $('#srFailedMsg').textContent = r.error_message || '不明なエラー';
                $('#srGenerateBtn').hidden = false;
                showState('failed');
                return;
            }
            if (r && r.status === 'skipped') {
                stopPolling();
                $('#srFailedMsg').textContent = (r.error_message === 'no_active_strategy')
                    ? 'この月の有効戦略が設定されていません。'
                    : ('スキップ: ' + (r.error_message || '理由不明'));
                $('#srGenerateBtn').hidden = false;
                showState('failed');
                return;
            }
            // running / pending / null → 続ける
            // 5分（150 ticks）超えたら諦める
            if (elapsed > 5 * 60) {
                stopPolling();
                $('#srFailedMsg').textContent = 'タイムアウトしました。WP-Cronが動作していない可能性があります。';
                $('#srGenerateBtn').hidden = false;
                showState('failed');
                return;
            }
            pollState.timer = setTimeout(tickPoll, 4000);
        }).catch(function () {
            // 一時的なエラーは無視して continue
            pollState.timer = setTimeout(tickPoll, 6000);
        });
    }

    function generateForCurrent() {
        var ym = $('#srHistorySelect').value || DEFAULT_YM || '';
        var body = ym ? { year_month: ym } : {};

        // 同期生成は最大 3 分かかるので、UI は running 表示にする
        showState('running');
        $('#srGenerateBtn').hidden = true;
        var elapsedStart = Date.now();
        var elapsedTimer = setInterval(function () {
            var sec = Math.round((Date.now() - elapsedStart) / 1000);
            $('#srRunningElapsed').textContent = '経過: ' + sec + ' 秒';
        }, 1000);

        api('/strategy-report/generate', { method: 'POST', body: body }).then(function (data) {
            clearInterval(elapsedTimer);
            loadHistory();

            if (data.status === 'completed' && data.report) {
                renderReport(data.report);
                showToast('レポートを生成しました', 'success');
                return;
            }
            if (data.status === 'failed') {
                $('#srFailedMsg').textContent = (data.report && data.report.error_message) || '不明なエラー';
                $('#srGenerateBtn').hidden = false;
                showState('failed');
                showToast('生成に失敗しました', 'error');
                return;
            }
            if (data.status === 'skipped') {
                $('#srFailedMsg').textContent = (data.report && data.report.error_message === 'no_active_strategy')
                    ? 'この月の有効戦略が設定されていません。'
                    : ('スキップ: ' + ((data.report && data.report.error_message) || '理由不明'));
                $('#srGenerateBtn').hidden = false;
                showState('failed');
                return;
            }
            // pending / running → polling フォールバック
            showToast('バックグラウンドで生成中です…', 'success');
            startPolling(data.year_month || ym);
        }).catch(function (err) {
            clearInterval(elapsedTimer);
            console.error(err);
            $('#srGenerateBtn').hidden = false;
            if (err && err.status === 429) {
                showState('failed');
                $('#srFailedMsg').textContent = err.message || '同月の手動生成は3回までです';
                showToast(err.message || '同月の手動生成は3回までです', 'error');
            } else if (err && err.status === 409) {
                showToast('すでに生成中です。完了をお待ちください。', 'error');
                if (err.payload && err.payload.report) {
                    startPolling(err.payload.report.year_month);
                }
            } else {
                showState('failed');
                $('#srFailedMsg').textContent = err.message || '不明なエラー';
                showToast('生成に失敗しました: ' + (err.message || ''), 'error');
            }
        });
    }

    // =========================================================
    // Wire up
    // =========================================================
    document.addEventListener('DOMContentLoaded', function () {
        if (!$('#srReportBody')) return; // ページが違う

        $('#srGenerateBtn').addEventListener('click', generateForCurrent);
        $('#srGenerateBtnEmpty').addEventListener('click', generateForCurrent);
        $('#srGenerateBtnRetry').addEventListener('click', generateForCurrent);

        $('#srHistorySelect').addEventListener('change', function (e) {
            var ym = e.target.value;
            if (ym) loadReportForMonth(ym);
        });

        loadHistory().then(function () {
            return loadCurrent();
        });
    });

})();
