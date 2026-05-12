<?php
/**
 * チャットボット管理 — FAQタブ
 *
 * 変数: $tenant, $is_admin, $return_url
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$editing = $edit_id ? Mimamori_Bot_Faq_Repository::find( (int) $tenant['id'], $edit_id ) : null;
$rows    = Mimamori_Bot_Faq_Repository::list_for_tenant( (int) $tenant['id'] );

$id_form     = $editing ? (int) $editing['id'] : 0;
$question_v  = $editing ? (string) $editing['question'] : '';
$answer_v    = $editing ? (string) $editing['answer'] : '';
$category_v  = $editing ? (string) ( $editing['category'] ?? '' ) : '';
$priority_v  = $editing ? (int) $editing['priority'] : 0;
$is_starter_v= $editing ? (int) $editing['is_starter'] : 0;
$status_v    = $editing ? (string) $editing['status'] : 'active';

// AI スターター提案 (キャッシュ済みのみ表示)
$ai_starter_cache = Mimamori_Bot_Faq_Repository::get_cached_suggestions( (int) $tenant['id'] );

// 通知
if ( isset( $_GET['starter_generated'] ) ) echo '<div class="mb-notice success">スターター提案を生成しました。</div>';
if ( isset( $_GET['starter_adopted'] ) )   echo '<div class="mb-notice success">FAQとして追加しました。「回答」を編集してください。</div>';
?>

<div class="mb-card">
    <h2>💡 履歴とナレッジから提案するスターター</h2>
    <p class="description" style="margin-bottom:14px">
        AI が <strong>ナレッジ全体</strong>と<strong>直近90日のユーザー質問履歴</strong>を分析し、訪問者が聞きそうな代表的な質問を4つ提案します。
        毎週自動更新されますが、ナレッジを追加した直後は手動で再生成すると最新の傾向が反映されます。
    </p>

    <?php if ( $ai_starter_cache && ! empty( $ai_starter_cache['suggestions'] ) ) :
        $gen_at  = (int) ( $ai_starter_cache['generated_at'] ?? 0 );
        $msg_n   = (int) ( $ai_starter_cache['message_count'] ?? 0 );
        $kn_n    = (int) ( $ai_starter_cache['knowledge_count'] ?? 0 );
    ?>
        <p style="font-size:12px;color:#64748b;margin-bottom:12px">
            最終更新: <strong><?php echo esc_html( $gen_at > 0 ? wp_date( 'Y-m-d H:i', $gen_at ) : '—' ); ?></strong>
            　/　 履歴: <?php echo esc_html( (string) $msg_n ); ?>件　ナレッジ: <?php echo esc_html( (string) $kn_n ); ?>件
        </p>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
            <?php foreach ( $ai_starter_cache['suggestions'] as $sg ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:10px;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px">
                    <?php wp_nonce_field( 'mimamori_bot_starter_adopt' ); ?>
                    <input type="hidden" name="action" value="mimamori_bot_starter_adopt">
                    <input type="hidden" name="question" value="<?php echo esc_attr( (string) $sg ); ?>">
                    <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">
                    <span style="flex:1;color:#0f172a">💬 <?php echo esc_html( (string) $sg ); ?></span>
                    <button type="submit" class="mb-btn mb-btn-secondary" style="padding:5px 12px;font-size:12px">FAQとして採用</button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <p style="color:#94a3b8;margin-bottom:14px">まだ提案がありません。ナレッジを登録するか、下のボタンで今すぐ生成してください。</p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('AIで質問例を生成します (OpenAI APIコール発生)。実行しますか？');">
        <?php wp_nonce_field( 'mimamori_bot_starter_regenerate' ); ?>
        <input type="hidden" name="action" value="mimamori_bot_starter_regenerate">
        <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">
        <button type="submit" class="mb-btn mb-btn-secondary">🔄 今すぐ更新する</button>
    </form>
</div>

<div class="mb-card">
    <h2><?php echo $editing ? '編集 (#' . esc_html( (string) $id_form ) . ')' : '新規追加'; ?></h2>
    <p>「<strong>初期表示</strong>」にチェックしたFAQはチャット起動時の質問例ボタンに使われます (priority降順、最大4件)。</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'mimamori_bot_faq_save' ); ?>
        <input type="hidden" name="action" value="mimamori_bot_faq_save">
        <input type="hidden" name="id" value="<?php echo esc_attr( (string) $id_form ); ?>">
        <input type="hidden" name="_return_url" value="<?php echo esc_attr( $return_url ); ?>">

        <div class="mb-form-group">
            <label for="f_q">質問</label>
            <input type="text" id="f_q" name="question" maxlength="500" required value="<?php echo esc_attr( $question_v ); ?>">
        </div>

        <div class="mb-form-group">
            <label for="f_a">回答</label>
            <textarea id="f_a" name="answer" rows="5" required><?php echo esc_textarea( $answer_v ); ?></textarea>
        </div>

        <div class="mb-form-group">
            <label for="f_cat">カテゴリ</label>
            <input type="text" id="f_cat" name="category" maxlength="80" value="<?php echo esc_attr( $category_v ); ?>">
        </div>

        <div class="mb-form-group">
            <label for="f_p">優先度</label>
            <input type="number" id="f_p" name="priority" value="<?php echo esc_attr( (string) $priority_v ); ?>" step="1" style="max-width:120px">
            <p class="description">数値が大きいほど優先 (RAGスコアと初期表示順に影響)</p>
        </div>

        <div class="mb-form-group">
            <label>
                <input type="checkbox" name="is_starter" value="1" <?php checked( $is_starter_v, 1 ); ?>>
                チャット起動時の質問例として表示
            </label>
        </div>

        <div class="mb-form-group">
            <label for="f_st">ステータス</label>
            <select id="f_st" name="status" style="max-width:200px">
                <option value="active"<?php selected( $status_v, 'active' ); ?>>公開中</option>
                <option value="draft"<?php selected( $status_v, 'draft' ); ?>>下書き</option>
                <option value="disabled"<?php selected( $status_v, 'disabled' ); ?>>無効</option>
            </select>
        </div>

        <button type="submit" class="mb-btn mb-btn-primary"><?php echo $editing ? '更新する' : '追加する'; ?></button>
        <?php if ( $editing ) : ?>
            <a href="<?php echo esc_url( home_url( '/chatbot/?tab=faq' ) ); ?>" class="mb-btn mb-btn-secondary">キャンセル</a>
        <?php endif; ?>
    </form>
</div>

<div class="mb-card">
    <h2>登録済みFAQ (<?php echo count( $rows ); ?>件)</h2>
    <?php if ( empty( $rows ) ) : ?>
        <p>まだ登録がありません。</p>
    <?php else : ?>
        <table class="mb-table">
            <thead><tr><th>★</th><th>質問</th><th>カテゴリ</th><th>優先</th><th>状態</th><th>ヒット</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $id = (int) $r['id'];
                $edit_url = add_query_arg( [ 'tab' => 'faq', 'edit' => $id ], home_url( '/chatbot/' ) );
                $del_url = wp_nonce_url(
                    add_query_arg( [
                        'action' => 'mimamori_bot_faq_delete',
                        'id'     => $id,
                        '_return_url' => $return_url,
                    ], admin_url( 'admin-post.php' ) ),
                    'mimamori_bot_faq_delete'
                );
            ?>
                <tr>
                    <td><?php echo (int) $r['is_starter'] === 1 ? '★' : ''; ?></td>
                    <td><strong><?php echo esc_html( mb_substr( (string) $r['question'], 0, 80 ) ); ?></strong></td>
                    <td><?php echo esc_html( (string) ( $r['category'] ?? '' ) ); ?></td>
                    <td><?php echo esc_html( (string) (int) $r['priority'] ); ?></td>
                    <td><?php echo esc_html( (string) $r['status'] ); ?></td>
                    <td><?php echo esc_html( (string) (int) $r['hit_count'] ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( $edit_url ); ?>" class="mb-btn mb-btn-link" style="padding:4px 8px;font-size:12px">編集</a>
                        <a href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('削除しますか？');" class="mb-btn mb-btn-danger" style="padding:4px 10px;font-size:12px">削除</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
