<?php
/*
Template Name: チャットボット管理
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

// オプション機能の利用可否チェック
// 運営者(管理者)は常時アクセス可。一般ユーザーは クライアント管理で ON にされた人だけ。
if ( ! current_user_can( 'manage_options' )
     && function_exists( 'mimamori_bot_is_enabled_for_user' )
     && ! mimamori_bot_is_enabled_for_user( get_current_user_id() ) ) {
    set_query_var( 'gcrev_page_title', 'チャットボット管理' );
    set_query_var( 'gcrev_breadcrumb', function_exists( 'gcrev_breadcrumb' ) ? gcrev_breadcrumb( 'チャットボット管理' ) : '' );
    get_header();
    ?>
    <div class="content-area">
      <div style="max-width:720px;margin:40px auto;padding:32px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;">
        <h2 style="margin-top:0;">🔒 このプランではチャットボット機能はご利用いただけません</h2>
        <p>チャットボット機能はオプション扱いです。ご利用をご希望の場合は、担当者までご連絡ください。</p>
        <p style="margin-top:20px;"><a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>" class="button">← ダッシュボードに戻る</a></p>
      </div>
    </div>
    <?php
    get_footer();
    exit;
}

if ( ! class_exists( 'Mimamori_Bot_Tenant_Repository' ) ) {
    set_query_var( 'gcrev_page_title', 'チャットボット管理' );
    set_query_var( 'gcrev_breadcrumb', function_exists( 'gcrev_breadcrumb' ) ? gcrev_breadcrumb( 'チャットボット管理' ) : '' );
    get_header();
    echo '<div class="content-area"><div class="notice notice-warning" style="padding:24px;background:#fff;border-left:4px solid #f59e0b;border-radius:8px;margin:20px;"><p>チャットボットプラグイン (mimamori-chatbot) が有効化されていません。サーバー管理者にご連絡ください。</p></div></div>';
    get_footer();
    exit;
}

$current_user = function_exists( 'mimamori_get_view_user_object' ) ? mimamori_get_view_user_object() : wp_get_current_user();
$user_id      = function_exists( 'mimamori_get_view_user_id' ) ? mimamori_get_view_user_id() : (int) $current_user->ID;
$is_admin     = current_user_can( 'manage_options' );

// テナント解決
$tenant = Mimamori_Bot_Tenant_Context::resolve_active_for_user( $user_id );

// タブ
$valid_tabs = [ 'settings', 'knowledge', 'faq', 'history', 'analytics' ];
$active_tab = isset( $_GET['tab'] ) && in_array( $_GET['tab'], $valid_tabs, true ) ? $_GET['tab'] : 'settings';

$tab_labels = [
    'settings'  => '⚙️ 設定',
    'knowledge' => '📚 ナレッジ',
    'faq'       => '❓ FAQ',
    'history'   => '💬 履歴',
    'analytics' => '📊 分析',
];

set_query_var( 'gcrev_page_title', 'チャットボット管理' );
set_query_var( 'gcrev_page_subtitle', 'クライアントサイトに埋め込めるAIチャットボットの設定・ナレッジ・FAQ・分析を管理します。' );
set_query_var( 'gcrev_breadcrumb', function_exists( 'gcrev_breadcrumb' ) ? gcrev_breadcrumb( 'チャットボット管理' ) : '' );

// 設定タブ: FAB アイコン選択でメディアライブラリを使う
if ( $active_tab === 'settings' ) {
    wp_enqueue_media();
}

get_header();

// 通知
$notices = [
    'updated'     => [ 'success', '設定を保存しました。' ],
    'created'     => [ 'success', 'テナントを作成しました。' ],
    'added'       => [ 'success', 'ナレッジを追加しました。' ],
    'uploaded'    => [ 'success', 'ファイルを取り込みました。' ],
    'deleted'     => [ 'success', '削除しました。' ],
    'saved'       => [ 'success', '保存しました。' ],
    'reindexed'   => [ 'success', '再インデックスしました。' ],
    'regenerated' => [ 'warning', 'キーを再発行しました。' ],
];

$return_url = home_url( '/chatbot/?tab=' . $active_tab );
?>

<style>
.mb-wrap { max-width: 1280px; margin: 0 auto; padding: 16px; }
.mb-tabs { display:flex; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:24px; flex-wrap:wrap; }
.mb-tab { padding:12px 20px; background:#f8fafc; border:1px solid #e5e7eb; border-bottom:none; border-radius:8px 8px 0 0; color:#64748b; text-decoration:none; font-weight:500; font-size:14px; cursor:pointer; }
.mb-tab.active { background:#fff; color:#1a73e8; border-color:#1a73e8 #1a73e8 #fff; border-bottom:2px solid #fff; margin-bottom:-2px; position:relative; z-index:1; }
.mb-tab:hover:not(.active) { background:#eef2ff; }

.mb-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:24px 28px; margin-bottom:20px; }
.mb-card h2 { margin:0 0 16px; font-size:18px; font-weight:600; color:#0f172a; padding-bottom:12px; border-bottom:1px solid #e5e7eb; }
.mb-card h3 { margin:24px 0 12px; font-size:15px; font-weight:600; color:#334155; }

.mb-form-group { margin-bottom:20px; }
.mb-form-group label { display:block; font-weight:500; color:#334155; margin-bottom:6px; font-size:13px; }
.mb-form-group .description { font-size:12px; color:#64748b; margin-top:4px; line-height:1.5; }
.mb-form-group input[type="text"], .mb-form-group input[type="url"], .mb-form-group input[type="number"], .mb-form-group textarea, .mb-form-group select {
    width:100%; max-width:560px; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; font-family:inherit;
}
.mb-form-group textarea { resize:vertical; min-height:80px; }
.mb-form-group textarea.code { font-family:monospace; font-size:13px; }

/* ファイル入力 — 「ファイルを選択」ボタンを mb-btn-secondary 相当に */
.mb-form-group input[type="file"] {
    font-size:13px; color:#475569; padding:6px 0;
}
.mb-form-group input[type="file"]::file-selector-button,
.mb-form-group input[type="file"]::-webkit-file-upload-button {
    display:inline-block; padding:8px 18px; margin-right:12px;
    background:#fff; color:#374151; border:1px solid #d1d5db; border-radius:6px;
    font-weight:500; font-size:14px; font-family:inherit; cursor:pointer;
    transition:background-color .15s ease, border-color .15s ease;
}
.mb-form-group input[type="file"]::file-selector-button:hover,
.mb-form-group input[type="file"]::-webkit-file-upload-button:hover {
    background:#f9fafb; border-color:#9ca3af;
}

.mb-notice { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-size:14px; }
.mb-notice.success { background:#ecfdf5; border:1px solid #34d399; color:#065f46; }
.mb-notice.warning { background:#fffbeb; border:1px solid #fcd34d; color:#92400e; }
.mb-notice.error { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; }

.mb-btn { display:inline-block; padding:8px 18px; border:1px solid transparent; border-radius:6px; font-weight:500; font-size:14px; cursor:pointer; text-decoration:none; }
.mb-btn-primary { background:#1a73e8; color:#fff; border-color:#1a73e8; }
.mb-btn-primary:hover { background:#1557b0; }
.mb-btn-secondary { background:#fff; color:#374151; border-color:#d1d5db; }
.mb-btn-secondary:hover { background:#f9fafb; }
.mb-btn-danger { background:#fff; color:#dc2626; border-color:#dc2626; }
.mb-btn-danger:hover { background:#fef2f2; }
.mb-btn-link { background:transparent; color:#1a73e8; padding:4px 8px; }

.mb-table { width:100%; border-collapse:collapse; }
.mb-table th, .mb-table td { padding:10px 12px; text-align:left; border-bottom:1px solid #e5e7eb; font-size:13px; }
.mb-table thead th { background:#f8fafc; font-weight:600; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.3px; }

.mb-switcher { background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 14px; margin-bottom:16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.mb-switcher strong { color:#1e40af; }
.mb-switcher select { padding:6px 10px; border:1px solid #93c5fd; border-radius:6px; background:#fff; }

.mb-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; }
.mb-kpi-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; }
.mb-kpi-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.5px; }
.mb-kpi-value { font-size:24px; font-weight:700; color:#0f172a; margin:6px 0; }
.mb-kpi-sub { font-size:11px; color:#94a3b8; }

.mb-snippet-box { font-family:monospace; font-size:12px; background:#f6f7f7; padding:12px; border-radius:8px; border:1px solid #e5e7eb; width:100%; }

.mb-msg { padding:8px 10px; border-radius:8px; margin:6px 0; }
.mb-msg-user { background:#dbeafe; }
.mb-msg-assistant { background:#fff; border:1px solid #e5e7eb; }
.mb-bar { height:14px; border-radius:4px; }
</style>

<div class="content-area"><div class="mb-wrap">

<?php
// 通知バナー
foreach ( $notices as $param => $info ) {
    if ( isset( $_GET[ $param ] ) ) {
        $cls  = $info[0];
        $body = $info[1];
        if ( $param === 'reindexed' ) {
            $n = absint( $_GET[ $param ] );
            $body .= ' (' . $n . ' 件)';
        }
        echo '<div class="mb-notice ' . esc_attr( $cls ) . '">' . esc_html( $body ) . '</div>';
    }
}
if ( isset( $_GET['error'] ) ) {
    echo '<div class="mb-notice error">' . esc_html( (string) $_GET['error'] ) . '</div>';
}

// テナント切替UI (管理者なら全テナント、一般なら自分のもの)
if ( $is_admin || ( $tenant && Mimamori_Bot_Tenant_Repository::list_for_user( $user_id, 10 ) ) ) {
    $accessible = Mimamori_Bot_Tenant_Context::list_accessible_for_user( $user_id );
    if ( ! empty( $accessible ) && ( count( $accessible ) > 1 || $is_admin ) ) {
        echo '<div class="mb-switcher">';
        echo '<strong>現在管理中:</strong>';
        echo '<form method="GET" action="' . esc_url( home_url( '/chatbot/' ) ) . '" style="display:flex;gap:8px;align-items:center;margin:0;flex:1;flex-wrap:wrap">';
        echo '<input type="hidden" name="tab" value="' . esc_attr( $active_tab ) . '">';
        echo '<select name="tenant_id" onchange="this.form.submit()">';
        foreach ( $accessible as $t ) {
            $sel = ( $tenant && (int) $tenant['id'] === (int) $t['id'] ) ? ' selected' : '';
            echo '<option value="' . esc_attr( (string) $t['id'] ) . '"' . $sel . '>' . esc_html( $t['name'] . ' (' . $t['slug'] . ')' ) . '</option>';
        }
        echo '</select>';
        echo '</form>';
        if ( $is_admin ) {
            echo '<span style="font-size:12px;color:#6b7280">運営者ビュー</span>';
        }
        echo '</div>';
    }
}

// テナント未割当
if ( ! $tenant ) {
    if ( $is_admin ) {
        ?>
        <div class="mb-card">
            <h2>新規テナント作成</h2>
            <p>まずテナントを作成してください。</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'mimamori_bot_create_tenant' ); ?>
                <input type="hidden" name="action" value="mimamori_bot_create_tenant">
                <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">
                <div class="mb-form-group">
                    <label for="slug">スラッグ</label>
                    <input type="text" id="slug" name="slug" pattern="[a-z0-9\-]{3,32}" required placeholder="例: client-001">
                    <p class="description">英小文字・数字・ハイフン 3〜32字。あとから変更できません。</p>
                </div>
                <div class="mb-form-group">
                    <label for="name">表示名</label>
                    <input type="text" id="name" name="name" required placeholder="例: 株式会社サンプル">
                </div>
                <?php if ( $is_admin ) : ?>
                <div class="mb-form-group">
                    <label for="owner_user_id">オーナーユーザー</label>
                    <?php wp_dropdown_users( [
                        'name'             => 'owner_user_id',
                        'show_option_none' => '— 自分 (運営者) を所有者にする —',
                        'selected'         => 0,
                    ] ); ?>
                    <p class="description">クライアント側ユーザーを指定すると、そのユーザーがログイン時に自分のテナントとして編集可能になります。</p>
                </div>
                <?php endif; ?>
                <button type="submit" class="mb-btn mb-btn-primary">テナントを作成</button>
            </form>
        </div>
        <?php
    } else {
        echo '<div class="mb-card"><p>あなたのアカウントにはまだチャットボットテナントが割り当てられていません。運営者にご連絡ください。</p></div>';
    }
    echo '</div></div>';
    get_footer();
    exit;
}

// タブナビ
echo '<div class="mb-tabs">';
foreach ( $tab_labels as $key => $label ) {
    $url = add_query_arg( [ 'tab' => $key ], home_url( '/chatbot/' ) );
    $class = $key === $active_tab ? 'mb-tab active' : 'mb-tab';
    echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
}
echo '</div>';

// タブ別コンテンツ
switch ( $active_tab ) {
    case 'settings':
        require __DIR__ . '/template-parts/chatbot/tab-settings.php';
        break;
    case 'knowledge':
        require __DIR__ . '/template-parts/chatbot/tab-knowledge.php';
        break;
    case 'faq':
        require __DIR__ . '/template-parts/chatbot/tab-faq.php';
        break;
    case 'history':
        require __DIR__ . '/template-parts/chatbot/tab-history.php';
        break;
    case 'analytics':
        require __DIR__ . '/template-parts/chatbot/tab-analytics.php';
        break;
}
?>

</div></div>

<?php get_footer(); ?>
