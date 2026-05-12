<?php
/**
 * チャットボット管理 — ナレッジタブ
 *
 * 変数: $tenant, $is_admin, $return_url
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$rows = Mimamori_Bot_Knowledge_Repository::list_for_tenant( (int) $tenant['id'] );

// 埋め込み未生成カウント
global $wpdb;
$ct = Mimamori_Bot_Installer::table_knowledge_chunks();
$ft = Mimamori_Bot_Installer::table_faq();
$missing_chunks = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$ct} WHERE tenant_id = %d AND embedding IS NULL", (int) $tenant['id']
) );
$missing_faqs = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$ft} WHERE tenant_id = %d AND embedding IS NULL AND status = 'active'", (int) $tenant['id']
) );
?>

<div class="mb-card">
    <h2>新規追加 (テキスト)</h2>
    <p>サービス内容・料金プラン・FAQ・会社案内・対応事例などをテキストで登録します。AIはチャット応答時にここから引用するので、お客様によくある質問の回答や提供サービスの詳細をまとめて入れておくと精度が上がります。</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'mimamori_bot_knowledge_add' ); ?>
        <input type="hidden" name="action" value="mimamori_bot_knowledge_add">
        <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">

        <div class="mb-form-group">
            <label for="kn_title">タイトル</label>
            <input type="text" id="kn_title" name="title" required maxlength="200" placeholder="例: サービス料金表 / よくある質問 / 会社概要">
        </div>

        <div class="mb-form-group">
            <label for="kn_category">カテゴリ (任意)</label>
            <input type="text" id="kn_category" name="category" maxlength="80" placeholder="例: 料金 / サービス / FAQ / 会社情報">
        </div>

        <div class="mb-form-group">
            <label for="kn_body">本文</label>
            <textarea id="kn_body" name="raw_text" rows="10" required placeholder="改行を含む長文OK。AIはこの内容を根拠に回答します。"></textarea>
            <p class="description">段落で区切るとチャンク (検索単位) が綺麗になります。1チャンク最大500字目安。</p>
        </div>

        <button type="submit" class="mb-btn mb-btn-primary">追加する</button>
    </form>
</div>

<div class="mb-card">
    <h2>ファイルから取り込み</h2>
    <p>対応: <code>.txt</code> <code>.md</code> <code>.csv</code> <code>.html</code> <code>.pdf</code> (最大2MB) / <code>.jpg</code> <code>.png</code> <code>.webp</code> (最大4MB、OpenAI Visionで日本語OCR)</p>
    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'mimamori_bot_knowledge_upload' ); ?>
        <input type="hidden" name="action" value="mimamori_bot_knowledge_upload">
        <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">

        <div class="mb-form-group">
            <label for="up_title">タイトル (任意)</label>
            <input type="text" id="up_title" name="title" maxlength="200" placeholder="空ならファイル名をそのまま使用">
        </div>

        <div class="mb-form-group">
            <label for="up_category">カテゴリ (任意)</label>
            <input type="text" id="up_category" name="category" maxlength="80">
        </div>

        <div class="mb-form-group">
            <label for="up_file">ファイル</label>
            <input type="file" id="up_file" name="file" accept=".txt,.md,.markdown,.csv,.html,.htm,.pdf,.jpg,.jpeg,.png,.webp" required>
        </div>

        <button type="submit" class="mb-btn mb-btn-secondary">取り込み</button>
    </form>
</div>

<?php if ( $missing_chunks > 0 || $missing_faqs > 0 ) : ?>
<div class="mb-card">
    <h2>埋め込み再生成</h2>
    <p>AIが意味的に近いナレッジを引けるよう、ベクトル化が必要なデータがあります — チャンク <strong><?php echo esc_html( (string) $missing_chunks ); ?></strong>件 / FAQ <strong><?php echo esc_html( (string) $missing_faqs ); ?></strong>件。下のボタンで AI が引用しやすい形に整えます。</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('AI が引用しやすい形にナレッジを整えます。実行しますか？');">
        <?php wp_nonce_field( 'mimamori_bot_knowledge_reindex' ); ?>
        <input type="hidden" name="action" value="mimamori_bot_knowledge_reindex">
        <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">
        <button type="submit" class="mb-btn mb-btn-secondary">🔄 未生成分を再インデックス</button>
    </form>
</div>
<?php endif; ?>

<div class="mb-card">
    <h2>登録済みナレッジ (<?php echo count( $rows ); ?>件)</h2>
    <?php if ( empty( $rows ) ) : ?>
        <p>まだ登録がありません。</p>
    <?php else : ?>
        <table class="mb-table">
            <thead><tr><th>タイトル</th><th>タイプ</th><th>チャンク数</th><th>更新</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $id = (int) $r['id'];
                $del_url = wp_nonce_url(
                    add_query_arg( [
                        'action' => 'mimamori_bot_knowledge_delete',
                        'id'     => $id,
                        '_return_url' => $return_url,
                    ], admin_url( 'admin-post.php' ) ),
                    'mimamori_bot_knowledge_delete'
                );
            ?>
                <tr>
                    <td><strong><?php echo esc_html( (string) $r['title'] ); ?></strong></td>
                    <td><?php echo esc_html( (string) $r['source_type'] ); ?></td>
                    <td><?php echo esc_html( (string) (int) $r['chunk_count'] ); ?></td>
                    <td style="font-size:12px;color:#64748b"><?php echo esc_html( (string) $r['updated_at'] ); ?></td>
                    <td><a href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('削除しますか？関連チャンクも全て消えます。');" class="mb-btn mb-btn-danger" style="padding:4px 10px;font-size:12px">削除</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
