<?php
/*
Template Name: 計測キーワード設定
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

// 見える化プランは順位計測(SEO)非対応 → サイトダッシュボードへ転送
if ( function_exists( 'mimamori_guard_against_mieruka' ) ) {
    mimamori_guard_against_mieruka();
}

$current_user = mimamori_get_view_user_object();
$user_id      = mimamori_get_view_user_id();

set_query_var( 'gcrev_page_title', '計測キーワード設定' );
set_query_var( 'gcrev_page_subtitle', '検索順位チェックで計測するキーワードを管理します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '計測キーワード設定', '検索順位チェック' ) );

$aio_enabled = function_exists( 'mimamori_aio_enabled' ) ? mimamori_aio_enabled() : false;

get_header();
?>

<style>
.kws-wrap { max-width: 980px; margin: 0 auto; padding: 16px; }

.kws-card {
    background: var(--mw-bg-primary, #fff);
    border: 1px solid var(--mw-border-light, #C3CED0);
    border-radius: var(--mw-radius-md, 16px);
    padding: 24px 28px;
    box-shadow: var(--mw-shadow-soft, 0 1px 6px rgba(0,0,0,.03));
    margin-bottom: 20px;
}
.kws-card h2 {
    margin: 0 0 8px;
    font-size: 17px; font-weight: 700;
    color: var(--mw-text-heading, #1A2F33);
    display: flex; align-items: center; gap: 8px;
}
.kws-card .desc {
    font-size: 13px; color: var(--mw-text-tertiary, #5D6E70);
    margin: 0 0 18px;
}

/* --- 計測キーワード管理 (page-client-settings から移植) --- */
.cs-kw-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
.cs-kw-table th {
    font-size: 12px; font-weight: 600; color: #6b7280;
    padding: 10px 12px; text-align: left;
    border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.cs-kw-table td {
    padding: 12px; border-bottom: 1px solid #f3f4f6;
    vertical-align: middle; font-size: 14px;
}
.cs-kw-table tr:last-child td { border-bottom: none; }

.cs-kw-actions {
    display: flex; align-items: center; gap: 8px;
    white-space: nowrap; justify-content: flex-end;
}
.cs-kw-order-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px;
    border: 1px solid #e5e7eb; border-radius: 6px;
    background: #fff; cursor: pointer; font-size: 14px; color: #6b7280;
}
.cs-kw-order-btn:hover { background: #f9fafb; }
.cs-kw-delete-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 10px; border: 1px solid #fecaca; border-radius: 6px;
    background: #fff; color: #ef4444; font-size: 12px; font-weight: 500; cursor: pointer;
}
.cs-kw-delete-btn:hover { background: #fef2f2; }

.cs-kw-add-form {
    display: flex; gap: 10px; align-items: end;
    padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb;
    border-radius: 8px; margin-bottom: 12px;
}
.cs-kw-add-form input[type="text"] {
    flex: 1; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 14px; background: #fff; box-sizing: border-box;
}
.cs-kw-add-form input[type="text"]:focus {
    outline: none; border-color: #4E8A6B;
    box-shadow: 0 0 0 3px rgba(78,138,107,.12);
}
.cs-kw-add-form .btn-kw-submit {
    padding: 9px 18px; background: #4E8A6B; color: #fff;
    border: none; border-radius: 6px;
    font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap;
}
.cs-kw-add-form .btn-kw-submit:hover { background: #2d6b54; }
.cs-kw-add-form .btn-kw-submit:disabled { opacity: .5; cursor: not-allowed; }
.cs-kw-add-form .btn-kw-cancel {
    padding: 9px 14px; background: #fff; color: #64748b;
    border: 1px solid #cbd5e1; border-radius: 6px;
    font-size: 13px; cursor: pointer; white-space: nowrap;
}
.cs-kw-add-form .btn-kw-cancel:hover { background: #f9fafb; }

.cs-kw-footer {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; padding-top: 4px;
}
.btn-add-kw {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 8px 16px; background: #fff; color: #4E8A6B;
    border: 1px dashed #4E8A6B; border-radius: 6px;
    font-size: 13px; font-weight: 500; cursor: pointer;
}
.btn-add-kw:hover { background: #f0fdf4; }
.btn-add-kw:disabled { opacity: .5; cursor: not-allowed; border-color: #94a3b8; color: #94a3b8; }
.cs-kw-quota { font-size: 13px; color: #6b7280; }
.cs-kw-quota strong { font-weight: 700; color: #1e293b; }
.cs-kw-empty { font-size: 13px; color: #94a3b8; padding: 12px 0; }

/* Toggle switch */
.cs-kw-toggle {
    position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0;
}
.cs-kw-toggle input { opacity: 0; width: 0; height: 0; }
.cs-kw-toggle__slider {
    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
    background: #d1d5db; border-radius: 22px; transition: 0.2s;
}
.cs-kw-toggle__slider::before {
    content: ''; position: absolute; height: 16px; width: 16px; left: 3px; bottom: 3px;
    background: #fff; border-radius: 50%; transition: 0.2s;
}
.cs-kw-toggle input:checked + .cs-kw-toggle__slider { background: #568184; }
.cs-kw-toggle input:checked + .cs-kw-toggle__slider::before { transform: translateX(18px); }

/* Toast */
.kws-toast {
    position: fixed; bottom: 24px; right: 24px;
    background: #1e293b; color: #fff;
    padding: 10px 18px; border-radius: 8px;
    font-size: 13px; box-shadow: 0 4px 12px rgba(0,0,0,.15);
    opacity: 0; transform: translateY(8px);
    transition: opacity .2s, transform .2s;
    pointer-events: none; z-index: 1000;
}
.kws-toast.show { opacity: 1; transform: translateY(0); }
</style>

<div class="content-area"><div class="kws-wrap">

    <div class="kws-card">
        <h2>🔑 計測キーワード</h2>
        <p class="desc"><?php echo $aio_enabled ? '順位チェック・AIO診断で計測するキーワードを設定します（最大5件）' : '順位チェックで計測するキーワードを設定します（最大5件）'; ?></p>

        <div id="csKwTableWrap" style="display:none;">
            <table class="cs-kw-table">
                <thead>
                    <tr>
                        <th>キーワード</th>
                        <th style="text-align:center;">ランキング計測</th>
                        <?php if ( $aio_enabled ) : ?>
                        <th style="text-align:center;">AIO診断</th>
                        <?php endif; ?>
                        <th style="text-align:right;">操作</th>
                    </tr>
                </thead>
                <tbody id="csKwTableBody"></tbody>
            </table>
        </div>

        <div id="csKwEmpty" class="cs-kw-empty">キーワードが登録されていません。</div>

        <div id="csKwFormWrap" style="display:none;">
            <div class="cs-kw-add-form">
                <input type="text" id="csKwInput" placeholder="キーワードを入力（例: 愛媛 ホームページ制作）" maxlength="255">
                <button type="button" class="btn-kw-submit" id="csKwSubmitBtn" onclick="kwsSubmit()">追加する</button>
                <button type="button" class="btn-kw-cancel" onclick="kwsCancelAdd()">キャンセル</button>
            </div>
        </div>

        <div class="cs-kw-footer">
            <button type="button" class="btn-add-kw" id="csKwAddBtn" onclick="kwsShowAddForm()">＋ キーワードを追加</button>
            <span class="cs-kw-quota" id="csKwQuota"></span>
        </div>
    </div>

    <div style="text-align:center;font-size:12px;color:#94a3b8;margin-top:8px;">
        順位の確認は <a href="<?php echo esc_url( home_url( '/rank-tracker/' ) ); ?>" style="color:#568184;">自然検索順位</a> / <a href="<?php echo esc_url( home_url( '/map-rank/' ) ); ?>" style="color:#568184;">マップ順位</a> から
    </div>

</div></div>

<div id="csToast" class="kws-toast"></div>

<script>
(function() {
    'use strict';

    var wpNonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
    var csKwList = [];
    var csKwLimit = 5;
    var csKwCanAdd = true;
    var csKwDefaultDomain = '';

    function csKwEsc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function showToast(msg) {
        var toast = document.getElementById('csToast');
        toast.textContent = '✅ ' + msg;
        toast.classList.add('show');
        setTimeout(function() { toast.classList.remove('show'); }, 3000);
    }

    // キーワードを変更したら、自然検索順位ページの localStorage キャッシュ（2時間TTL）を破棄。
    // これを呼ばないと、追加直後に順位ページへ戻った際、古い「空」キャッシュが返り
    // 「キーワードが登録されていません」が最大2時間表示され続ける。
    function kwsInvalidateRankCache() {
        if (window.gcrevCache) window.gcrevCache.clear('rank_tracker_');
    }

    function csKwFetch() {
        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords', {
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success && json.data) {
                csKwList = json.data.keywords || [];
                csKwLimit = json.data.limit || 5;
                csKwCanAdd = json.data.can_add;
                csKwDefaultDomain = json.data.default_domain || '';
                csKwRender();
            }
        })
        .catch(function(err) { console.error('[KWS]', err); });
    }

    function csKwRender() {
        var tableWrap = document.getElementById('csKwTableWrap');
        var tbody = document.getElementById('csKwTableBody');
        var empty = document.getElementById('csKwEmpty');
        var addBtn = document.getElementById('csKwAddBtn');
        var quota = document.getElementById('csKwQuota');

        quota.innerHTML = '<strong>' + csKwList.length + '</strong> / ' + csKwLimit + ' キーワード';
        addBtn.disabled = !csKwCanAdd;

        if (csKwList.length === 0) {
            tableWrap.style.display = 'none';
            empty.style.display = 'block';
            return;
        }

        empty.style.display = 'none';
        tableWrap.style.display = 'block';

        var html = '';
        for (var i = 0; i < csKwList.length; i++) {
            var kw = csKwList[i];
            html += '<tr>';
            html += '<td><strong>' + csKwEsc(kw.keyword) + '</strong></td>';

            html += '<td style="text-align:center;">';
            html += '<label class="cs-kw-toggle">';
            html += '<input type="checkbox"' + (kw.enabled ? ' checked' : '') + ' onchange="kwsToggle(' + kw.id + ', this.checked)">';
            html += '<span class="cs-kw-toggle__slider"></span>';
            html += '</label>';
            html += '</td>';

            <?php if ( $aio_enabled ) : ?>
            html += '<td style="text-align:center;">';
            html += '<label class="cs-kw-toggle">';
            html += '<input type="checkbox"' + (kw.aio_enabled ? ' checked' : '') + ' onchange="kwsToggleAio(' + kw.id + ', this.checked)">';
            html += '<span class="cs-kw-toggle__slider"></span>';
            html += '</label>';
            html += '</td>';
            <?php endif; ?>

            html += '<td style="text-align:right;">';
            html += '<div class="cs-kw-actions">';
            html += '<button class="cs-kw-order-btn" onclick="kwsReorder(' + kw.id + ',\'up\')" title="上に移動">&#x2191;</button>';
            html += '<button class="cs-kw-order-btn" onclick="kwsReorder(' + kw.id + ',\'down\')" title="下に移動">&#x2193;</button>';
            html += '<button class="cs-kw-delete-btn" onclick="kwsDelete(' + kw.id + ',\'' + csKwEsc(kw.keyword).replace(/'/g, "\\'") + '\')">&#x1F5D1; 削除</button>';
            html += '</div>';
            html += '</td>';
            html += '</tr>';
        }
        tbody.innerHTML = html;
    }

    window.kwsShowAddForm = function() {
        document.getElementById('csKwFormWrap').style.display = 'block';
        document.getElementById('csKwInput').focus();
    };

    window.kwsCancelAdd = function() {
        document.getElementById('csKwInput').value = '';
        document.getElementById('csKwFormWrap').style.display = 'none';
    };

    window.kwsSubmit = function() {
        var input = document.getElementById('csKwInput');
        var keyword = input.value.trim();
        if (!keyword) { alert('キーワードを入力してください。'); return; }

        var btn = document.getElementById('csKwSubmitBtn');
        btn.disabled = true;

        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ keyword: keyword, target_domain: csKwDefaultDomain })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            btn.disabled = false;
            if (json.success) {
                input.value = '';
                document.getElementById('csKwFormWrap').style.display = 'none';
                kwsInvalidateRankCache();
                csKwFetch();
                showToast('キーワードを追加しました');
            } else {
                alert(json.message || 'エラーが発生しました。');
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            console.error('[KWS Submit]', err);
            alert('通信エラーが発生しました。');
        });
    };

    window.kwsDelete = function(id, keyword) {
        if (!confirm('「' + keyword + '」を削除しますか？\nこのキーワードの順位履歴も削除されます。')) return;

        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords/' + id, {
            method: 'DELETE',
            headers: { 'X-WP-Nonce': wpNonce },
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success) {
                kwsInvalidateRankCache();
                csKwFetch();
                showToast('キーワードを削除しました');
            } else {
                alert(json.message || '削除に失敗しました。');
            }
        });
    };

    window.kwsReorder = function(id, direction) {
        fetch('/wp-json/gcrev/v1/rank-tracker/reorder', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ keyword_id: id, direction: direction })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success) { kwsInvalidateRankCache(); csKwFetch(); }
        });
    };

    window.kwsToggle = function(id, enable) {
        var kw = csKwList.find(function(k) { return k.id === id; });
        if (!kw) return;

        fetch('/wp-json/gcrev/v1/rank-tracker/my-keywords', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ id: id, keyword: kw.keyword, enabled: enable ? 1 : 0 })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success) {
                kwsInvalidateRankCache();
                csKwFetch();
            } else {
                alert(json.message || 'エラーが発生しました。');
                csKwFetch();
            }
        });
    };

    window.kwsToggleAio = function(id, enable) {
        fetch('/wp-json/gcrev/v1/aio/my-keywords', {
            method: 'POST',
            headers: { 'X-WP-Nonce': wpNonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ keyword_id: id, aio_enabled: enable ? 1 : 0 })
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success) {
                csKwFetch();
            } else {
                alert(json.message || 'エラーが発生しました。');
                csKwFetch();
            }
        });
    };

    csKwFetch();
})();
</script>

<?php get_footer(); ?>
