/**
 * 戦略設定 (page-strategy-settings.php) - フロントロジック
 *
 * 主機能:
 *   - 起動時に latest draft → active の順でロードしてフォームに流し込む
 *   - 「下書き保存」: POST /strategy/draft（無ければ作成、あれば PUT で更新）
 *   - 「有効化」: 必須項目チェック → POST /strategy/draft/{id}/activate
 *   - バージョン履歴モーダル
 *
 * window.GCREV namespace に Strategy_Settings として置く。
 */
(function () {
    'use strict';

    var cfg = window.gcrevStrategyConfig || {};
    var REST = (cfg.restRoot || '/wp-json/').replace(/\/$/, '') + '/gcrev_insights/v1';
    var NONCE = cfg.nonce || '';

    // 編集中の draft id（無ければ null = 新規）
    var currentDraftId = null;

    // =========================================================
    // Utilities
    // =========================================================
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

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
        var el = $('#ssToast');
        if (!el) return;
        el.textContent = msg;
        el.className = 'ss-toast' + (type === 'error' ? ' ss-toast--error' : (type === 'success' ? ' ss-toast--success' : ''));
        el.hidden = false;
        clearTimeout(showToast._t);
        showToast._t = setTimeout(function () { el.hidden = true; }, 3500);
    }

    function todayYmd() {
        var d = new Date();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + dd;
    }

    // =========================================================
    // 動的リスト（issues / value_proposition）
    // =========================================================
    function makeListRow(name, value) {
        var row = document.createElement('div');
        row.className = 'ss-list__row';
        var inp = document.createElement('input');
        inp.className = 'ss-input';
        inp.type = 'text';
        inp.setAttribute('data-list-input', name);
        inp.value = value || '';
        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'ss-btn ss-btn--icon';
        rm.setAttribute('data-list-remove', name);
        rm.setAttribute('aria-label', '削除');
        rm.textContent = '×';
        row.appendChild(inp);
        row.appendChild(rm);
        return row;
    }

    function setListValues(name, values) {
        var listEl = document.querySelector('[data-list="' + name + '"]');
        if (!listEl) return;
        listEl.innerHTML = '';
        var safeValues = (values && values.length) ? values : [''];
        safeValues.forEach(function (v) {
            listEl.appendChild(makeListRow(name, v));
        });
    }

    function getListValues(name) {
        return $$('[data-list-input="' + name + '"]')
            .map(function (i) { return i.value.trim(); })
            .filter(function (s) { return s !== ''; });
    }

    document.addEventListener('click', function (e) {
        var addName = e.target.getAttribute && e.target.getAttribute('data-list-add');
        if (addName) {
            var listEl = document.querySelector('[data-list="' + addName + '"]');
            if (listEl) listEl.appendChild(makeListRow(addName, ''));
            return;
        }
        var rmName = e.target.getAttribute && e.target.getAttribute('data-list-remove');
        if (rmName) {
            var row = e.target.closest('.ss-list__row');
            var listEl2 = document.querySelector('[data-list="' + rmName + '"]');
            if (row && listEl2 && listEl2.children.length > 1) {
                row.remove();
            } else if (row) {
                // 最後の1行は値だけクリア
                var inp = row.querySelector('input');
                if (inp) inp.value = '';
            }
        }
    });

    // =========================================================
    // フォーム ↔ JSON 変換
    // =========================================================
    function readForm() {
        return {
            meta: {
                client_name:    $('#ssClientName').value.trim(),
                site_url:       $('#ssSiteUrl').value.trim(),
                effective_from: $('#ssEffectiveFrom').value.trim() || todayYmd()
            },
            target:            $('#ssTarget').value.trim(),
            issues:            getListValues('issues'),
            strategy:          $('#ssStrategy').value.trim(),
            value_proposition: getListValues('value_proposition'),
            conversion_path:   $('#ssConversionPath').value.trim()
        };
    }

    function writeForm(strategyJson) {
        var s = strategyJson || {};
        var meta = s.meta || {};
        $('#ssClientName').value    = meta.client_name || '';
        $('#ssSiteUrl').value       = meta.site_url || '';
        $('#ssEffectiveFrom').value = meta.effective_from || todayYmd();
        $('#ssTarget').value        = s.target || '';
        $('#ssStrategy').value      = s.strategy || '';
        $('#ssConversionPath').value = s.conversion_path || '';
        setListValues('issues', s.issues || []);
        setListValues('value_proposition', s.value_proposition || []);
    }

    // =========================================================
    // 初期ロード: draft 優先 → active → 空
    // =========================================================
    function bootstrap() {
        // active 表示は別 API で取る
        api('/strategy').then(function (data) {
            var active = data && data.strategy;
            var bar = $('#ssStatusBar');
            if (active) {
                bar.hidden = false;
                $('#ssActiveLabel').textContent = 'v' + active.version + '（' + active.effective_from + '〜）';
            } else {
                bar.hidden = false;
                $('#ssActiveLabel').textContent = '未設定';
            }
        }).catch(function () { /* noop */ });

        api('/strategy/draft/latest').then(function (data) {
            if (data && data.draft) {
                currentDraftId = data.draft.id;
                writeForm(data.draft.strategy_json);
                showToast('編集中の下書きを読み込みました');
                return;
            }
            // draft 無し → active を雛形にロード
            return api('/strategy').then(function (resp) {
                if (resp && resp.strategy) {
                    writeForm(resp.strategy.strategy_json);
                } else {
                    writeForm({}); // 空テンプレ
                }
            });
        }).catch(function (err) {
            console.error('strategy bootstrap failed', err);
            writeForm({});
        });
    }

    // =========================================================
    // 保存系
    // =========================================================
    function saveDraft() {
        var payload = { strategy: readForm() };
        var btn = $('#ssBtnSaveDraft');
        btn.disabled = true;
        var p = currentDraftId
            ? api('/strategy/draft/' + currentDraftId, { method: 'PUT',  body: payload })
            : api('/strategy/draft',                    { method: 'POST', body: payload });

        p.then(function (data) {
            if (data && data.strategy_id) {
                currentDraftId = data.strategy_id;
            }
            showToast('下書きを保存しました', 'success');
        }).catch(function (err) {
            console.error(err);
            showToast('保存に失敗しました', 'error');
        }).then(function () { btn.disabled = false; });
    }

    function activate() {
        if (!confirm('この内容で戦略を「有効化」しますか？\n現在の active 戦略は過去版に降格されます。')) return;

        var doActivate = function (id) {
            return api('/strategy/draft/' + id + '/activate', { method: 'POST' });
        };

        var btn = $('#ssBtnActivate');
        btn.disabled = true;

        // 必ず最新内容で draft を保存してから activate
        var payload = { strategy: readForm() };
        var savePromise = currentDraftId
            ? api('/strategy/draft/' + currentDraftId, { method: 'PUT',  body: payload })
            : api('/strategy/draft',                    { method: 'POST', body: payload });

        savePromise.then(function (data) {
            if (data && data.strategy_id) currentDraftId = data.strategy_id;
            return doActivate(currentDraftId);
        }).then(function (data) {
            showToast('戦略を有効化しました', 'success');
            currentDraftId = null;
            bootstrap();
        }).catch(function (err) {
            console.error(err);
            if (err && err.status === 422 && err.payload && err.payload.errors) {
                showToast('必須項目が不足しています:\n' + err.payload.errors.join('\n'), 'error');
            } else {
                showToast('有効化に失敗しました', 'error');
            }
        }).then(function () { btn.disabled = false; });
    }

    // =========================================================
    // バージョン履歴
    // =========================================================
    function openVersions() {
        var modal = $('#ssVersionsModal');
        var body  = $('#ssVersionsBody');
        modal.hidden = false;
        body.innerHTML = '<p class="ss-empty">読み込み中…</p>';
        api('/strategy/versions').then(function (data) {
            var rows = (data && data.versions) || [];
            if (!rows.length) {
                body.innerHTML = '<p class="ss-empty">バージョン履歴はまだありません。</p>';
                return;
            }
            var html = ['<table class="ss-versions">'];
            html.push('<thead><tr><th>v</th><th>状態</th><th>取込元</th><th>有効期間</th><th>更新</th></tr></thead><tbody>');
            rows.forEach(function (r) {
                var period = (r.effective_from || '—') + ' 〜 ' + (r.effective_until || '現在');
                html.push('<tr>');
                html.push('<td>' + r.version + '</td>');
                html.push('<td><span class="ss-badge ss-badge--' + r.status + '">' + r.status + '</span></td>');
                html.push('<td>' + (r.source_type || '—') + '</td>');
                html.push('<td>' + period + '</td>');
                html.push('<td>' + (r.created_at || '—') + '</td>');
                html.push('</tr>');
            });
            html.push('</tbody></table>');
            body.innerHTML = html.join('');
        }).catch(function () {
            body.innerHTML = '<p class="ss-empty">読み込みに失敗しました。</p>';
        });
    }

    function closeVersions() {
        $('#ssVersionsModal').hidden = true;
    }

    // =========================================================
    // Wire up
    // =========================================================
    document.addEventListener('DOMContentLoaded', function () {
        if (!$('#ssForm')) return; // ページが違う

        $('#ssBtnSaveDraft').addEventListener('click', saveDraft);
        $('#ssBtnActivate').addEventListener('click', activate);
        $('#ssVersionsBtn').addEventListener('click', openVersions);

        $$('[data-ss-modal-close]').forEach(function (el) {
            el.addEventListener('click', closeVersions);
        });

        bootstrap();
    });

    // namespace
    window.GCREV = window.GCREV || {};
    window.GCREV.StrategySettings = {
        readForm: readForm,
        writeForm: writeForm
    };

})();
