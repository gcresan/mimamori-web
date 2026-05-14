<?php
/*
Template Name: SNS連携
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url('/login/') );
    exit;
}

$user_id = get_current_user_id();

set_query_var( 'gcrev_page_title',    'SNS連携' );
set_query_var( 'gcrev_page_subtitle', 'Facebook / Instagram / Threads / LINE と接続して、複数の媒体に一括投稿します。' );
if ( function_exists('gcrev_breadcrumb') ) {
    set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'SNS連携', 'SNS' ) );
}

// 接続状況
$meta_status = class_exists('Gcrev_Meta_Client') ? Gcrev_Meta_Client::get_connection_status($user_id) : ['connected' => false, 'expires_at' => 0, 'account_label' => '', 'fb_page_id' => '', 'ig_user_id' => '', 'threads_user_id' => ''];
$line_status = class_exists('Gcrev_LINE_Client') ? Gcrev_LINE_Client::get_connection_status($user_id) : ['connected' => false, 'basic_id' => '', 'channel_id' => ''];

$meta_app_configured = get_option('gcrev_meta_app_id', '') !== '' && get_option('gcrev_meta_app_secret', '') !== '';
$just_connected = ! empty( $_GET['meta_connected'] );

get_header();
?>

<div class="content-area">
    <style>
        .sc-wrap { max-width: 920px; margin: 0 auto; padding: 24px 16px; }
        .sc-notice { background:#ecfdf5; border:1px solid #a7f3d0; border-radius:10px; padding:14px 18px; margin-bottom:24px; color:#047857; }
        .sc-grid { display:grid; grid-template-columns: 1fr; gap:16px; }
        @media (min-width:760px) { .sc-grid { grid-template-columns: 1fr 1fr; } }
        .sc-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; }
        .sc-card h3 { margin:0 0 10px; font-size:18px; display:flex; align-items:center; gap:10px; }
        .sc-platform-row { display:flex; align-items:center; gap:10px; padding:8px 0; border-top:1px dashed #e2e8f0; font-size:14px; }
        .sc-platform-row:first-of-type { border-top:none; }
        .sc-badge { display:inline-block; font-size:11px; font-weight:700; padding:3px 8px; border-radius:999px; }
        .sc-badge.ok  { background:#dcfce7; color:#15803d; }
        .sc-badge.ng  { background:#fee2e2; color:#b91c1c; }
        .sc-badge.warn{ background:#fef3c7; color:#b45309; }
        .sc-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 16px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; cursor:pointer; font-size:13px; font-family:inherit; transition:.15s; }
        .sc-btn:hover { background:#f1f5f9; }
        .sc-btn-primary { background:#3b6b5e; color:#fff; border-color:#3b6b5e; }
        .sc-btn-primary:hover { background:#2d5349; }
        .sc-btn-danger { color:#b91c1c; border-color:#fecaca; }
        .sc-btn-danger:hover { background:#fef2f2; }
        .sc-meta-section { margin-top:18px; }
        .sc-token-input { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:monospace; }
        .sc-help { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; margin-top:16px; font-size:13px; color:#475569; line-height:1.8; }
        .sc-help h4 { margin:0 0 6px; font-size:13px; color:#1e293b; }
        .sc-toolbar { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; }
    </style>

    <div class="sc-wrap">
        <?php if ( $just_connected ): ?>
            <div class="sc-notice">✅ Meta と接続しました。下のアカウント情報をご確認ください。</div>
        <?php endif; ?>

        <?php if ( ! $meta_app_configured ): ?>
            <div class="sc-notice" style="background:#fef3c7; border-color:#fde68a; color:#92400e;">
                ⚠️ 管理者による Meta アプリ設定が未完了です。管理画面の「みまもりウェブ &gt; SNS連携設定」で App ID / Secret を登録してください。
            </div>
        <?php endif; ?>

        <div class="sc-grid">

            <!-- Meta カード -->
            <div class="sc-card">
                <h3>📘 Meta（Facebook / Instagram / Threads）</h3>
                <p style="color:#64748b; font-size:13px; margin:0 0 14px;">
                    1回の認証で <strong>Facebookページ・Instagramビジネス・Threads</strong> へ投稿できるようになります。
                </p>

                <div class="sc-platform-row">
                    <span style="flex:1;">📘 Facebook ページ</span>
                    <?php if ( $meta_status['fb_page_id'] !== '' ): ?>
                        <span class="sc-badge ok">接続済み</span>
                        <span style="font-size:12px; color:#64748b;"><?php echo esc_html($meta_status['account_label']); ?></span>
                    <?php else: ?>
                        <span class="sc-badge ng">未接続</span>
                    <?php endif; ?>
                </div>

                <div class="sc-platform-row">
                    <span style="flex:1;">📷 Instagram ビジネス</span>
                    <?php if ( $meta_status['ig_user_id'] !== '' ): ?>
                        <span class="sc-badge ok">接続済み</span>
                    <?php else: ?>
                        <span class="sc-badge ng">未接続</span>
                    <?php endif; ?>
                </div>

                <div class="sc-platform-row">
                    <span style="flex:1;">@ Threads</span>
                    <?php if ( $meta_status['threads_user_id'] !== '' ): ?>
                        <span class="sc-badge ok">接続済み</span>
                    <?php else: ?>
                        <span class="sc-badge warn">権限承認が必要</span>
                    <?php endif; ?>
                </div>

                <?php if ( $meta_status['connected'] ): ?>
                    <div class="sc-toolbar">
                        <button type="button" class="sc-btn" id="sc-meta-reauth" <?php echo $meta_app_configured ? '' : 'disabled'; ?>>
                            🔁 再認証
                        </button>
                        <button type="button" class="sc-btn sc-btn-danger" id="sc-meta-disconnect">接続を解除</button>
                    </div>
                    <?php if ( $meta_status['expires_at'] > 0 ): ?>
                        <p style="font-size:11px; color:#94a3b8; margin:10px 0 0;">
                            トークン有効期限: <?php echo esc_html( wp_date('Y/m/d H:i', $meta_status['expires_at']) ); ?>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="sc-toolbar">
                        <button type="button" class="sc-btn sc-btn-primary" id="sc-meta-connect" <?php echo $meta_app_configured ? '' : 'disabled'; ?>>
                            📘 Meta と接続する
                        </button>
                    </div>
                <?php endif; ?>

                <div class="sc-help">
                    <h4>📋 接続するために必要なもの</h4>
                    Meta ビジネスアカウント / Facebook ページ / Instagram ビジネスアカウント。<br>
                    Instagram は「ビジネス」または「クリエイター」アカウントである必要があります。<br>
                    Threads は対象国でアプリの権限承認が下りていれば自動で接続されます。
                </div>
            </div>

            <!-- LINE カード -->
            <div class="sc-card">
                <h3>💚 LINE 公式アカウント</h3>
                <p style="color:#64748b; font-size:13px; margin:0 0 14px;">
                    友だち登録してくれた利用者全員に <strong>ブロードキャスト送信</strong> ができます。
                </p>

                <div class="sc-platform-row">
                    <span style="flex:1;">📨 Messaging API</span>
                    <?php if ( $line_status['connected'] ): ?>
                        <span class="sc-badge ok">接続済み</span>
                        <?php if ( $line_status['basic_id'] !== '' ): ?>
                            <span style="font-size:12px; color:#64748b;"><?php echo esc_html($line_status['basic_id']); ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="sc-badge ng">未接続</span>
                    <?php endif; ?>
                </div>

                <?php if ( $line_status['connected'] ): ?>
                    <div class="sc-toolbar">
                        <button type="button" class="sc-btn sc-btn-danger" id="sc-line-disconnect">接続を解除</button>
                    </div>
                <?php else: ?>
                    <div style="margin-top:14px;">
                        <label style="display:block; font-size:13px; font-weight:600; margin-bottom:6px;">
                            チャネルアクセストークン
                        </label>
                        <textarea id="sc-line-token" class="sc-token-input" rows="3" placeholder="LINE Developers コンソールで発行した長期トークンを貼り付け" spellcheck="false"></textarea>
                        <div class="sc-toolbar">
                            <button type="button" class="sc-btn sc-btn-primary" id="sc-line-save">💚 LINE を接続</button>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="sc-help">
                    <h4>📋 接続するために必要なもの</h4>
                    1. <a href="https://developers.line.biz/console/" target="_blank" rel="noopener">LINE Developers</a> で Messaging API チャネルを作成<br>
                    2. 「Messaging API設定」タブで <strong>長期のチャネルアクセストークン</strong> を発行<br>
                    3. トークンを上の欄に貼り付けて「接続」
                </div>
            </div>

        </div>

        <div style="margin-top:28px; text-align:center;">
            <a href="<?php echo esc_url( home_url('/social-posts/') ); ?>" class="sc-btn sc-btn-primary" style="text-decoration:none;">
                📝 SNS投稿管理ページへ
            </a>
        </div>
    </div>
</div>

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

    const metaConnect = document.getElementById('sc-meta-connect');
    const metaReauth  = document.getElementById('sc-meta-reauth');
    const metaDisc    = document.getElementById('sc-meta-disconnect');
    const lineSave    = document.getElementById('sc-line-save');
    const lineDisc    = document.getElementById('sc-line-disconnect');

    function startMetaOAuth() {
        api('GET', '/meta/auth-url').then(res => {
            if (res.ok && res.json.url) {
                window.location.href = res.json.url;
            } else {
                alert(res.json.message || 'Meta 認可URL生成失敗');
            }
        });
    }

    if (metaConnect) metaConnect.addEventListener('click', startMetaOAuth);
    if (metaReauth)  metaReauth.addEventListener('click', startMetaOAuth);

    if (metaDisc) metaDisc.addEventListener('click', () => {
        if (!confirm('Meta との接続を解除しますか？')) return;
        api('POST', '/meta/disconnect').then(() => window.location.reload());
    });

    if (lineSave) lineSave.addEventListener('click', () => {
        const token = (document.getElementById('sc-line-token').value || '').trim();
        if (!token) { alert('トークンを入力してください。'); return; }
        lineSave.disabled = true;
        api('POST', '/line/save-token', { token }).then(res => {
            lineSave.disabled = false;
            if (res.ok && res.json.success) {
                window.location.reload();
            } else {
                alert(res.json.message || 'LINE 接続失敗');
            }
        });
    });

    if (lineDisc) lineDisc.addEventListener('click', () => {
        if (!confirm('LINE との接続を解除しますか？')) return;
        api('POST', '/line/disconnect').then(() => window.location.reload());
    });
})();
</script>

<?php get_footer(); ?>
