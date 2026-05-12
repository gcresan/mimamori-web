<?php
/**
 * チャットボット管理 — ナレッジタブ
 *
 * 変数: $tenant, $is_admin, $return_url
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$rows = Mimamori_Bot_Knowledge_Repository::list_for_tenant( (int) $tenant['id'] );

// 詳細ビュー対象 (?view=ID)
$view_id     = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
$view_record = $view_id > 0 ? Mimamori_Bot_Knowledge_Repository::find( (int) $tenant['id'], $view_id ) : null;

// ストレージ使用量
$storage_used  = Mimamori_Bot_Knowledge_Repository::get_tenant_storage_bytes( (int) $tenant['id'] );
$storage_limit = Mimamori_Bot_Knowledge_Repository::MAX_BYTES_PER_TENANT;
$storage_pct   = $storage_limit > 0 ? min( 100, ( $storage_used * 100 / $storage_limit ) ) : 0;
$fmt_size = static function ( int $bytes ): string {
    if ( $bytes < 1024 ) return $bytes . ' B';
    if ( $bytes < 1048576 ) return number_format( $bytes / 1024, 1 ) . ' KB';
    if ( $bytes < 1073741824 ) return number_format( $bytes / 1048576, 1 ) . ' MB';
    return number_format( $bytes / 1073741824, 2 ) . ' GB';
};
if ( $storage_pct >= 95 ) {
    $bar_color = '#dc2626'; $bg_color = '#fef2f2';
} elseif ( $storage_pct >= 80 ) {
    $bar_color = '#f59e0b'; $bg_color = '#fffbeb';
} else {
    $bar_color = '#1a73e8'; $bg_color = '#eff6ff';
}

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

<div class="mb-card" style="padding:16px 20px">
    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px">
        <strong style="font-size:13px;color:#334155">📦 ナレッジストレージ使用量</strong>
        <span style="font-size:13px;color:#475569"><strong style="color:<?php echo esc_attr( $bar_color ); ?>"><?php echo esc_html( $fmt_size( $storage_used ) ); ?></strong> / <?php echo esc_html( $fmt_size( $storage_limit ) ); ?> (<?php echo esc_html( number_format( $storage_pct, 1 ) ); ?>%)</span>
    </div>
    <div style="background:<?php echo esc_attr( $bg_color ); ?>;border-radius:6px;height:10px;overflow:hidden">
        <div style="background:<?php echo esc_attr( $bar_color ); ?>;width:<?php echo esc_attr( (string) $storage_pct ); ?>%;height:100%;transition:width .3s"></div>
    </div>
    <?php if ( $storage_pct >= 95 ) : ?>
        <p style="font-size:12px;color:#991b1b;margin:8px 0 0">⚠️ 上限間近です。これ以上の追加はできません。古いナレッジを削除してください。</p>
    <?php elseif ( $storage_pct >= 80 ) : ?>
        <p style="font-size:12px;color:#92400e;margin:8px 0 0">⚠️ 残り容量が少なくなっています。不要なナレッジを整理することをおすすめします。</p>
    <?php else : ?>
        <p style="font-size:11px;color:#94a3b8;margin:6px 0 0">本文・チャンク・ベクトルデータの合計サイズ。1クライアントあたり 1 GB まで。</p>
    <?php endif; ?>
</div>

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

<?php if ( $view_record ) :
    $list_url = home_url( '/chatbot/?tab=knowledge' );
    $meta     = json_decode( (string) ( $view_record['metadata'] ?? '' ), true );
    $category = is_array( $meta ) ? (string) ( $meta['category'] ?? '' ) : '';
    $raw_text = (string) ( $view_record['raw_text'] ?? '' );
    $char_len = mb_strlen( $raw_text );

    // チャンク一覧
    $ct_table = Mimamori_Bot_Installer::table_knowledge_chunks();
    $chunks   = $wpdb->get_results( $wpdb->prepare(
        "SELECT chunk_index, content, embedding IS NOT NULL AS has_embed
           FROM {$ct_table}
          WHERE tenant_id = %d AND knowledge_id = %d
          ORDER BY chunk_index ASC",
        (int) $tenant['id'], (int) $view_record['id']
    ), ARRAY_A );
?>
<div class="mb-card" id="mb-knowledge-detail">
    <h2>📄 ナレッジ詳細 — <?php echo esc_html( (string) $view_record['title'] ); ?></h2>
    <p style="margin-top:-8px"><a href="<?php echo esc_url( $list_url ); ?>" class="mb-btn-link">← 一覧に戻る</a></p>

    <table class="mb-table" style="margin-bottom:16px">
        <tbody>
            <tr><th style="width:160px">ID</th><td><?php echo esc_html( (string) (int) $view_record['id'] ); ?></td></tr>
            <tr><th>タイプ</th><td><?php echo esc_html( (string) $view_record['source_type'] ); ?><?php if ( ! empty( $view_record['mime_type'] ) ) echo ' <span style="color:#94a3b8">(' . esc_html( (string) $view_record['mime_type'] ) . ')</span>'; ?></td></tr>
            <?php if ( $category !== '' ) : ?>
            <tr><th>カテゴリ</th><td><?php echo esc_html( $category ); ?></td></tr>
            <?php endif; ?>
            <tr><th>チャンク数</th><td><?php echo esc_html( (string) (int) $view_record['chunk_count'] ); ?></td></tr>
            <tr><th>本文文字数</th><td><?php echo esc_html( number_format( $char_len ) ); ?> 文字</td></tr>
            <tr><th>登録日</th><td><?php echo esc_html( (string) ( $view_record['created_at'] ?? '' ) ); ?></td></tr>
            <tr><th>更新日</th><td><?php echo esc_html( (string) ( $view_record['updated_at'] ?? '' ) ); ?></td></tr>
        </tbody>
    </table>

    <h3>本文 (AIが参照する全文)</h3>
    <?php if ( $raw_text === '' ) : ?>
        <p style="color:#94a3b8">本文がありません。</p>
    <?php else : ?>
        <pre style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;max-height:480px;overflow:auto;white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:13px;line-height:1.7;margin:0;"><?php echo esc_html( $raw_text ); ?></pre>
    <?php endif; ?>

    <?php if ( ! empty( $chunks ) ) : ?>
    <h3>チャンク一覧 (検索単位)</h3>
    <p class="description" style="font-size:12px;color:#64748b;margin-top:-4px">AIは下記チャンク単位で意味検索し、関連したものだけを引用します。</p>
    <div style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ( $chunks as $ch ) : ?>
            <details style="border:1px solid #e5e7eb;border-radius:8px;background:#fff">
                <summary style="cursor:pointer;padding:8px 12px;font-size:13px;color:#334155;display:flex;justify-content:space-between;align-items:center">
                    <span>#<?php echo esc_html( (string) (int) $ch['chunk_index'] ); ?>　<span style="color:#94a3b8">(<?php echo esc_html( (string) mb_strlen( (string) $ch['content'] ) ); ?>文字)</span></span>
                    <span style="font-size:11px;color:<?php echo $ch['has_embed'] ? '#16a34a' : '#dc2626'; ?>"><?php echo $ch['has_embed'] ? '✓ ベクトル化済' : '✗ 未ベクトル化'; ?></span>
                </summary>
                <pre style="margin:0;padding:10px 14px;border-top:1px solid #f1f5f9;background:#fafafa;white-space:pre-wrap;word-break:break-word;font-family:inherit;font-size:12px;line-height:1.6"><?php echo esc_html( (string) $ch['content'] ); ?></pre>
            </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
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
                $view_url = add_query_arg( [ 'tab' => 'knowledge', 'view' => $id ], home_url( '/chatbot/' ) ) . '#mb-knowledge-detail';
                $del_url  = wp_nonce_url(
                    add_query_arg( [
                        'action' => 'mimamori_bot_knowledge_delete',
                        'id'     => $id,
                        '_return_url' => $return_url,
                    ], admin_url( 'admin-post.php' ) ),
                    'mimamori_bot_knowledge_delete'
                );
                $is_active = ( $view_id === $id );
            ?>
                <tr<?php echo $is_active ? ' style="background:#eff6ff"' : ''; ?>>
                    <td><a href="<?php echo esc_url( $view_url ); ?>" style="color:#1a73e8;text-decoration:none;font-weight:600"><?php echo esc_html( (string) $r['title'] ); ?></a></td>
                    <td><?php echo esc_html( (string) $r['source_type'] ); ?></td>
                    <td><?php echo esc_html( (string) (int) $r['chunk_count'] ); ?></td>
                    <td style="font-size:12px;color:#64748b"><?php echo esc_html( (string) $r['updated_at'] ); ?></td>
                    <td style="white-space:nowrap">
                        <a href="<?php echo esc_url( $view_url ); ?>" class="mb-btn mb-btn-secondary" style="padding:4px 10px;font-size:12px">📄 中身</a>
                        <a href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('削除しますか？関連チャンクも全て消えます。');" class="mb-btn mb-btn-danger" style="padding:4px 10px;font-size:12px">削除</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
