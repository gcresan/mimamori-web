<?php
/*
Template Name: SNS投稿管理
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url('/login/') );
    exit;
}

$user_id = get_current_user_id();

set_query_var('gcrev_page_title',    'SNS投稿管理');
set_query_var('gcrev_page_subtitle', '1度の入力で Facebook / Instagram / Threads / LINE へ同時投稿。');
if ( function_exists('gcrev_breadcrumb') ) {
    set_query_var('gcrev_breadcrumb', gcrev_breadcrumb('SNS投稿管理', 'SNS'));
}

$connected = class_exists('Gcrev_Social_Poster')
    ? Gcrev_Social_Poster::get_connected_platforms($user_id)
    : ['facebook' => false, 'instagram' => false, 'threads' => false, 'line' => false];

$any_connected = array_filter($connected) ? true : false;

get_header();
wp_enqueue_media();
?>

<div class="content-area">
    <style>
        .sp-wrap { max-width: 1080px; margin: 0 auto; padding: 24px 16px; }
        .sp-empty { text-align:center; padding:60px 20px; background:#fff; border:1px solid #e2e8f0; border-radius:14px; }
        .sp-empty-icon { font-size:48px; margin-bottom:14px; }
        .sp-grid { display:grid; grid-template-columns: 1fr; gap:20px; }
        @media (min-width:920px) { .sp-grid { grid-template-columns: 1fr 420px; } }
        .sp-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; }
        .sp-card h3 { margin:0 0 14px; font-size:16px; }
        .sp-field { margin-bottom:14px; }
        .sp-field label { display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:6px; }
        .sp-textarea { width:100%; min-height:170px; padding:12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; font-family:inherit; line-height:1.7; resize:vertical; }
        .sp-input { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:inherit; }
        .sp-media-preview { margin-top:8px; }
        .sp-media-preview img { max-width:100%; max-height:200px; border-radius:8px; }
        .sp-platforms { display:flex; flex-direction:column; gap:8px; }
        .sp-pf-row { display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer; transition:.12s; }
        .sp-pf-row:hover { background:#f8fafc; }
        .sp-pf-row.disabled { opacity:.45; cursor:not-allowed; }
        .sp-pf-row.selected { background:#ecfdf5; border-color:#86efac; }
        .sp-pf-icon { font-size:22px; line-height:1; }
        .sp-pf-name { flex:1; font-weight:600; font-size:14px; }
        .sp-pf-meta { font-size:11px; color:#94a3b8; }
        .sp-counter { font-size:11px; color:#94a3b8; text-align:right; margin-top:4px; }
        .sp-btn { display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; cursor:pointer; font-size:13px; font-family:inherit; transition:.12s; }
        .sp-btn:hover { background:#f1f5f9; }
        .sp-btn-primary { background:#3b6b5e; color:#fff; border-color:#3b6b5e; }
        .sp-btn-primary:hover { background:#2d5349; }
        .sp-btn:disabled { opacity:.5; cursor:not-allowed; }
        .sp-toolbar { display:flex; gap:8px; justify-content:flex-end; margin-top:16px; flex-wrap:wrap; }

        .sp-history-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; margin-top:24px; }
        .sp-post-list { display:flex; flex-direction:column; gap:10px; margin-top:14px; }
        .sp-post-item { padding:14px 16px; border:1px solid #e2e8f0; border-radius:10px; position:relative; }
        .sp-post-body { font-size:13px; color:#1e293b; line-height:1.6; margin-bottom:8px; white-space:pre-wrap; word-break:break-word; }
        .sp-post-meta { display:flex; gap:12px; align-items:center; flex-wrap:wrap; font-size:11px; color:#64748b; }
        .sp-post-status { display:inline-block; padding:2px 8px; border-radius:999px; font-weight:700; font-size:10px; }
        .sp-post-status.draft     { background:#f1f5f9; color:#475569; }
        .sp-post-status.scheduled { background:#dbeafe; color:#1d4ed8; }
        .sp-post-status.posted    { background:#dcfce7; color:#15803d; }
        .sp-post-status.partial   { background:#fef3c7; color:#b45309; }
        .sp-post-status.failed    { background:#fee2e2; color:#b91c1c; }
        .sp-post-status.cancelled { background:#e5e7eb; color:#374151; }
        .sp-post-actions { display:flex; gap:6px; margin-top:8px; }
        .sp-btn-sm { padding:4px 10px; font-size:11px; }

        .sp-warning { background:#fef3c7; border:1px solid #fde68a; border-radius:10px; padding:12px 16px; margin-bottom:18px; color:#92400e; font-size:13px; }
    </style>

    <div class="sp-wrap">

        <?php if ( ! $any_connected ): ?>
            <div class="sp-empty">
                <div class="sp-empty-icon">🔌</div>
                <h3 style="font-size:20px; margin:0 0 8px;">まだSNSと接続していません</h3>
                <p style="color:#64748b; margin:0 0 20px;">Meta（Facebook / Instagram / Threads）または LINE と接続すると、ここから投稿できるようになります。</p>
                <a href="<?php echo esc_url( home_url('/social-connect/') ); ?>" class="sp-btn sp-btn-primary" style="text-decoration:none;">
                    📱 SNS連携ページへ
                </a>
            </div>
        <?php else: ?>

            <div class="sp-grid">
                <!-- 左: 投稿作成 -->
                <div>
                    <div class="sp-card">
                        <h3>📝 新しい投稿を作成</h3>

                        <div class="sp-field">
                            <label for="sp-body">本文</label>
                            <textarea id="sp-body" class="sp-textarea" placeholder="ここに投稿内容を入力..."></textarea>
                            <div class="sp-counter" id="sp-counter">0 文字</div>
                        </div>

                        <div class="sp-field">
                            <label>画像（任意）</label>
                            <div>
                                <button type="button" class="sp-btn" id="sp-media-btn">🖼️ 画像を選択</button>
                                <button type="button" class="sp-btn" id="sp-media-clear" style="display:none;">✕ 削除</button>
                            </div>
                            <div class="sp-media-preview" id="sp-media-preview"></div>
                            <input type="hidden" id="sp-media-url" value="">
                            <input type="hidden" id="sp-media-id" value="">
                        </div>

                        <div class="sp-field">
                            <label for="sp-link">リンク URL（任意・Facebook用）</label>
                            <input type="url" id="sp-link" class="sp-input" placeholder="https://example.com/page">
                        </div>

                        <div class="sp-field">
                            <label for="sp-scheduled">予約投稿（任意・空欄なら即時投稿）</label>
                            <input type="datetime-local" id="sp-scheduled" class="sp-input">
                        </div>
                    </div>
                </div>

                <!-- 右: プラットフォーム選択 + アクション -->
                <div>
                    <div class="sp-card">
                        <h3>📡 送信先プラットフォーム</h3>
                        <div class="sp-platforms">
                            <?php
                            $platforms_meta = [
                                'facebook'  => ['icon' => '📘', 'name' => 'Facebook ページ',     'note' => 'テキスト + 画像対応'],
                                'instagram' => ['icon' => '📷', 'name' => 'Instagram',           'note' => '画像必須'],
                                'threads'   => ['icon' => '@',  'name' => 'Threads',             'note' => '最大500文字'],
                                'line'      => ['icon' => '💚', 'name' => 'LINE 公式アカウント', 'note' => '友だち全員に配信'],
                            ];
                            foreach ($platforms_meta as $key => $info):
                                $ok = ! empty($connected[$key]);
                            ?>
                                <div class="sp-pf-row <?php echo $ok ? '' : 'disabled'; ?>" data-platform="<?php echo esc_attr($key); ?>">
                                    <span class="sp-pf-icon"><?php echo esc_html($info['icon']); ?></span>
                                    <div style="flex:1;">
                                        <div class="sp-pf-name"><?php echo esc_html($info['name']); ?></div>
                                        <div class="sp-pf-meta"><?php echo esc_html($info['note']); ?></div>
                                    </div>
                                    <?php if ($ok): ?>
                                        <input type="checkbox" data-pf="<?php echo esc_attr($key); ?>" checked style="width:18px; height:18px;">
                                    <?php else: ?>
                                        <span style="font-size:11px; color:#94a3b8;">未接続</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="sp-toolbar">
                            <button type="button" class="sp-btn" id="sp-save-draft">💾 下書き保存</button>
                            <button type="button" class="sp-btn sp-btn-primary" id="sp-post-now">🚀 今すぐ投稿</button>
                        </div>
                        <p style="font-size:11px; color:#94a3b8; margin:8px 0 0;">
                            予約日時を入力した状態で「下書き保存」を押すと予約投稿になります。
                        </p>
                    </div>
                </div>
            </div>

            <!-- 履歴 -->
            <div class="sp-history-card">
                <h3 style="margin:0;">📜 投稿履歴</h3>
                <div class="sp-post-list" id="sp-post-list">
                    <p style="color:#94a3b8; font-size:13px; padding:14px;">読み込み中...</p>
                </div>
            </div>

        <?php endif; ?>
    </div>
</div>

<?php if ( $any_connected ): ?>
<script>
(function(){
    const API_BASE = '<?php echo esc_js( esc_url_raw( rest_url('gcrev/v1/social') ) ); ?>';
    const NONCE    = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';

    function api(method, path, body) {
        const opts = {
            method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': NONCE,
            },
        };
        if (body !== undefined) opts.body = JSON.stringify(body);
        return fetch(API_BASE + path, opts).then(r => r.json().then(j => ({ ok: r.ok, status: r.status, json: j })));
    }

    const $ = id => document.getElementById(id);
    const bodyEl     = $('sp-body');
    const counterEl  = $('sp-counter');
    const linkEl     = $('sp-link');
    const schedEl    = $('sp-scheduled');
    const mediaUrlEl = $('sp-media-url');
    const mediaIdEl  = $('sp-media-id');
    const previewEl  = $('sp-media-preview');
    const mediaClear = $('sp-media-clear');

    // 文字数カウンタ
    bodyEl.addEventListener('input', () => {
        counterEl.textContent = bodyEl.value.length + ' 文字';
    });

    // プラットフォーム選択UI
    document.querySelectorAll('.sp-pf-row:not(.disabled)').forEach(row => {
        row.addEventListener('click', (e) => {
            if (e.target.tagName === 'INPUT') return;
            const cb = row.querySelector('input[type=checkbox]');
            if (cb) {
                cb.checked = !cb.checked;
                row.classList.toggle('selected', cb.checked);
            }
        });
        const cb = row.querySelector('input[type=checkbox]');
        if (cb) {
            row.classList.toggle('selected', cb.checked);
            cb.addEventListener('change', () => row.classList.toggle('selected', cb.checked));
        }
    });

    // メディアピッカー
    $('sp-media-btn').addEventListener('click', () => {
        const frame = wp.media({
            title: '画像を選択',
            multiple: false,
            library: { type: 'image' },
            button: { text: '選択' },
        });
        frame.on('select', () => {
            const att = frame.state().get('selection').first().toJSON();
            mediaUrlEl.value = att.url;
            mediaIdEl.value  = att.id;
            previewEl.innerHTML = '<img src="' + att.url + '" alt="">';
            mediaClear.style.display = 'inline-flex';
        });
        frame.open();
    });

    mediaClear.addEventListener('click', () => {
        mediaUrlEl.value = '';
        mediaIdEl.value = '';
        previewEl.innerHTML = '';
        mediaClear.style.display = 'none';
    });

    function getSelectedPlatforms() {
        return Array.from(document.querySelectorAll('input[data-pf]:checked')).map(el => el.dataset.pf);
    }

    function buildPayload(status) {
        const platforms = getSelectedPlatforms();
        const scheduled_at = schedEl.value;
        const payload = {
            body: bodyEl.value,
            media_url: mediaUrlEl.value,
            media_attachment_id: parseInt(mediaIdEl.value || '0', 10) || null,
            media_type: mediaUrlEl.value ? 'image' : null,
            link_url: linkEl.value,
            platforms,
            platform_overrides: {},
            status: scheduled_at ? 'scheduled' : status,
            scheduled_at: scheduled_at || null,
        };
        return payload;
    }

    function validate(payload) {
        if (!payload.body.trim() && !payload.media_url) {
            alert('本文または画像のどちらかは必須です。');
            return false;
        }
        if (!payload.platforms.length) {
            alert('送信先プラットフォームを1つ以上選択してください。');
            return false;
        }
        if (payload.platforms.includes('instagram') && !payload.media_url) {
            alert('Instagram には画像が必須です。');
            return false;
        }
        return true;
    }

    $('sp-save-draft').addEventListener('click', () => {
        const payload = buildPayload('draft');
        if (!validate(payload)) return;
        api('POST', '/posts', payload).then(res => {
            if (res.ok) {
                alert(payload.status === 'scheduled' ? '予約投稿を登録しました。' : '下書きを保存しました。');
                resetForm();
                loadHistory();
            } else {
                alert(res.json.message || '保存失敗');
            }
        });
    });

    $('sp-post-now').addEventListener('click', () => {
        const payload = buildPayload('draft');
        payload.action = 'post_now';
        if (!validate(payload)) return;
        if (!confirm('選択したプラットフォームへ即時投稿します。よろしいですか？')) return;
        $('sp-post-now').disabled = true;
        api('POST', '/posts', payload).then(res => {
            $('sp-post-now').disabled = false;
            if (res.json.platform_results) {
                let msg = '投稿結果:\n';
                Object.keys(res.json.platform_results).forEach(p => {
                    const r = res.json.platform_results[p];
                    msg += `  ${p}: ${r.status === 'success' ? '✅' : '❌'} ${r.message || ''}\n`;
                });
                alert(msg);
                resetForm();
                loadHistory();
            } else if (res.ok) {
                alert('保存しました');
                resetForm();
                loadHistory();
            } else {
                alert(res.json.message || '投稿失敗');
            }
        });
    });

    function resetForm() {
        bodyEl.value = '';
        counterEl.textContent = '0 文字';
        linkEl.value = '';
        schedEl.value = '';
        mediaUrlEl.value = '';
        mediaIdEl.value = '';
        previewEl.innerHTML = '';
        mediaClear.style.display = 'none';
    }

    function fmtDate(s) {
        if (!s) return '';
        try {
            return s.replace('T', ' ').slice(0, 16);
        } catch { return s; }
    }

    function platformChips(post) {
        const results = post.platform_results || {};
        return (post.platforms || []).map(p => {
            const r = results[p];
            const sym = !r ? '⏳' : (r.status === 'success' ? '✅' : '❌');
            return `<span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#f1f5f9; margin-right:4px;">${sym} ${p}</span>`;
        }).join('');
    }

    function loadHistory() {
        api('GET', '/posts').then(res => {
            const list = $('sp-post-list');
            const posts = (res.json.posts || []);
            if (!posts.length) {
                list.innerHTML = '<p style="color:#94a3b8; font-size:13px; padding:14px;">まだ投稿はありません。</p>';
                return;
            }
            list.innerHTML = posts.map(p => `
                <div class="sp-post-item">
                    <div class="sp-post-body">${escapeHtml(p.body || '')}</div>
                    <div class="sp-post-meta">
                        <span class="sp-post-status ${p.status}">${p.status}</span>
                        ${platformChips(p)}
                        <span>${p.scheduled_at ? '予定: ' + fmtDate(p.scheduled_at) : ''}</span>
                        <span>${p.posted_at ? '投稿: ' + fmtDate(p.posted_at) : ''}</span>
                    </div>
                    ${p.error_message ? `<div style="font-size:11px; color:#b91c1c; margin-top:6px;">${escapeHtml(p.error_message)}</div>` : ''}
                    <div class="sp-post-actions">
                        ${p.status === 'failed' || p.status === 'partial' ? `<button class="sp-btn sp-btn-sm" data-retry="${p.id}">🔁 再試行</button>` : ''}
                        ${p.status === 'draft' || p.status === 'scheduled' ? `<button class="sp-btn sp-btn-sm sp-btn-primary" data-post-now="${p.id}">🚀 今すぐ投稿</button>` : ''}
                        <button class="sp-btn sp-btn-sm" data-delete="${p.id}" style="color:#b91c1c;">🗑️ 削除</button>
                    </div>
                </div>
            `).join('');

            list.querySelectorAll('[data-retry]').forEach(b => b.addEventListener('click', () => {
                api('POST', '/posts/' + b.dataset.retry + '/retry').then(() => loadHistory());
            }));
            list.querySelectorAll('[data-post-now]').forEach(b => b.addEventListener('click', () => {
                if (!confirm('この投稿を即時送信しますか？')) return;
                api('POST', '/posts/' + b.dataset.postNow + '/post-now').then(() => loadHistory());
            }));
            list.querySelectorAll('[data-delete]').forEach(b => b.addEventListener('click', () => {
                if (!confirm('削除しますか？')) return;
                api('DELETE', '/posts/' + b.dataset.delete).then(() => loadHistory());
            }));
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    loadHistory();
})();
</script>
<?php endif; ?>

<?php get_footer(); ?>
