<?php
/**
 * チャットボット管理 — 設定タブ
 *
 * 変数 (page-chatbot.php から渡される):
 *   $tenant, $is_admin, $return_url
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="mb-card">
    <h2>基本設定 — <?php echo esc_html( $tenant['name'] ); ?> <code style="font-size:12px;color:#6b7280;font-weight:normal"><?php echo esc_html( $tenant['slug'] ); ?></code></h2>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'mimamori_bot_update_tenant' ); ?>
        <input type="hidden" name="action" value="mimamori_bot_update_tenant">
        <input type="hidden" name="tenant_id" value="<?php echo esc_attr( (string) $tenant['id'] ); ?>">
        <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">

        <div class="mb-form-group">
            <label>スラッグ</label>
            <code style="background:#f1f5f9;padding:4px 8px;border-radius:4px"><?php echo esc_html( $tenant['slug'] ); ?></code>
        </div>

        <?php if ( $is_admin ) : ?>
        <div class="mb-form-group">
            <label for="owner_user_id_edit">オーナーユーザー</label>
            <?php wp_dropdown_users( [
                'name'             => 'owner_user_id',
                'id'               => 'owner_user_id_edit',
                'selected'         => (int) $tenant['user_id'],
                'show_option_none' => '— 未割当 —',
            ] ); ?>
            <p class="description">オーナーユーザーが管理画面でこのテナントを編集できます。</p>
        </div>
        <?php endif; ?>

        <div class="mb-form-group">
            <label for="name">表示名</label>
            <input type="text" id="name" name="name" value="<?php echo esc_attr( $tenant['name'] ); ?>" required>
        </div>

        <div class="mb-form-group">
            <label for="persona">ペルソナ</label>
            <input type="text" id="persona" name="persona" value="<?php echo esc_attr( (string) ( $tenant['persona'] ?? '' ) ); ?>" placeholder="例: ポスティング会社のアシスタント">
            <p class="description">AIの役割を一言で。応答のトーンに影響します。</p>
        </div>

        <div class="mb-form-group">
            <label for="cta_url_quote">見積もりCTA URL</label>
            <input type="url" id="cta_url_quote" name="cta_url_quote" value="<?php echo esc_attr( (string) ( $tenant['cta_url_quote'] ?? '' ) ); ?>" placeholder="https://example.com/quote">
            <p class="description">回答下に「見積もりへ進む」ボタンとして表示されます。</p>
        </div>

        <div class="mb-form-group">
            <label for="cta_url_contact">問い合わせCTA URL</label>
            <input type="url" id="cta_url_contact" name="cta_url_contact" value="<?php echo esc_attr( (string) ( $tenant['cta_url_contact'] ?? '' ) ); ?>" placeholder="https://example.com/contact">
        </div>

        <div class="mb-form-group">
            <label for="allowed_origins">許可Origin</label>
            <?php $ao = is_array( $tenant['allowed_origins'] ) ? implode( "\n", $tenant['allowed_origins'] ) : ''; ?>
            <textarea id="allowed_origins" name="allowed_origins" rows="4" placeholder="https://example.com"><?php echo esc_textarea( $ao ); ?></textarea>
            <p class="description">1行1Origin。Widgetを設置するサイトを記載。プロトコル(https://)必須。許可されていないサイトからの埋め込みはブロックされます。</p>
        </div>

        <div class="mb-form-group">
            <label for="rate_limit_rpm">レート制限 (req/分)</label>
            <input type="number" id="rate_limit_rpm" name="rate_limit_rpm" min="10" max="600" value="<?php echo esc_attr( (string) $tenant['rate_limit_rpm'] ); ?>" style="max-width:120px"> req/分
        </div>

        <div class="mb-form-group">
            <label for="monthly_budget_jpy">月次バジェット (円)</label>
            <input type="number" id="monthly_budget_jpy" name="monthly_budget_jpy" min="0" value="<?php echo esc_attr( (string) ( $tenant['monthly_budget_jpy'] ?? '' ) ); ?>" style="max-width:160px"> 円
            <p class="description">0または空欄で無制限。超過すると公開Widgetが一時停止します。</p>
        </div>

        <div class="mb-form-group">
            <label for="system_prompt">システム指示 (プロンプト)</label>
            <textarea id="system_prompt" name="system_prompt" rows="8" class="code"><?php echo esc_textarea( (string) ( $tenant['system_prompt'] ?? '' ) ); ?></textarea>
            <p class="description">AIに与える役割・口調・禁則事項などを記述。</p>
        </div>

        <div class="mb-form-group">
            <label for="status">ステータス</label>
            <select id="status" name="status" style="max-width:200px">
                <option value="active"<?php selected( (string) ( $tenant['status'] ?? '' ), 'active' ); ?>>稼働中</option>
                <option value="suspended"<?php selected( (string) ( $tenant['status'] ?? '' ), 'suspended' ); ?>>停止</option>
            </select>
        </div>

        <button type="submit" class="mb-btn mb-btn-primary">設定を保存</button>
    </form>
</div>

<div class="mb-card">
    <h2>埋め込みコード</h2>
    <p>下記コードをクライアントサイトの <code>&lt;/body&gt;</code> 直前に貼り付けると、チャットが起動します。</p>
    <?php
    $widget_url = MIMAMORI_BOT_URL . 'public/widget/widget.js';
    $snippet = "<script>\n  (function(){\n    var s=document.createElement('script');\n    s.src='" . esc_js( $widget_url ) . "';\n    s.async=true;\n    s.dataset.tenant='" . esc_js( $tenant['slug'] ) . "';\n    s.dataset.key='" . esc_js( $tenant['public_key'] ) . "';\n    document.head.appendChild(s);\n  })();\n</script>";
    ?>
    <textarea readonly onclick="this.select()" rows="9" class="mb-snippet-box"><?php echo esc_textarea( $snippet ); ?></textarea>
    <p style="margin-top:12px"><strong>公開キー:</strong> <code><?php echo esc_html( $tenant['public_key'] ); ?></code></p>

    <h3>動作確認 (iframe テスト)</h3>
    <?php $embed = rest_url( MIMAMORI_BOT_REST_NS . '/embed?tenant=' . rawurlencode( $tenant['slug'] ) ); ?>
    <p><a href="<?php echo esc_url( $embed ); ?>" target="_blank" rel="noopener" class="mb-btn mb-btn-secondary">→ チャットUIを別タブで開く</a></p>
</div>

<div class="mb-card">
    <h2>キー再発行</h2>
    <p>キーを再発行すると既存の埋め込みコードは無効になります。差し替えが必要です。</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('キーを再発行します。既存埋め込みは無効になります。続行しますか？');">
        <?php wp_nonce_field( 'mimamori_bot_regenerate_keys' ); ?>
        <input type="hidden" name="action" value="mimamori_bot_regenerate_keys">
        <input type="hidden" name="tenant_id" value="<?php echo esc_attr( (string) $tenant['id'] ); ?>">
        <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">
        <button type="submit" class="mb-btn mb-btn-danger">🔁 キーを再発行</button>
    </form>
</div>
