<?php
// FILE: inc/gcrev-api/admin/class-survey-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }
if ( class_exists('Gcrev_Survey_Page') ) { return; }

/**
 * Gcrev_Survey_Page
 *
 * アンケート管理画面（一覧 + 編集 + 質問管理）
 * アカウント単位でアンケートを作成・編集・公開できる。
 */
class Gcrev_Survey_Page {

    private const MENU_SLUG = 'gcrev-survey';

    public function register(): void {
        add_action('admin_menu', [ $this, 'add_menu_page' ]);
        add_action('admin_init', [ $this, 'handle_actions' ]);
    }

    // =========================================================
    // メニュー登録
    // =========================================================

    public function add_menu_page(): void {
        if ( empty( $GLOBALS['admin_page_hooks']['gcrev-insight'] ) ) {
            add_menu_page(
                'みまもりウェブ', 'みまもりウェブ', 'manage_options',
                'gcrev-insight', '__return_null', 'dashicons-chart-area', 30
            );
        }

        add_submenu_page(
            'gcrev-insight',
            'アンケート管理 - みまもりウェブ',
            '📋 アンケート管理',
            'read', // ログイン中なら表示（中でuser_id絞り込み）
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================
    // アクション処理（POST）
    // =========================================================

    public function handle_actions(): void {
        if ( ! isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG ) {
            return;
        }
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            return;
        }

        $action = sanitize_text_field($_POST['survey_action'] ?? '');
        if ( empty($action) ) return;

        // Nonce検証
        if ( ! wp_verify_nonce($_POST['_wpnonce_survey'] ?? '', 'gcrev_survey_action') ) {
            wp_die('不正なリクエストです。');
        }

        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) return;

        global $wpdb;
        $t_surveys   = $wpdb->prefix . 'gcrev_surveys';
        $t_questions = $wpdb->prefix . 'gcrev_survey_questions';

        switch ($action) {
            case 'save_survey':
                $this->handle_save_survey($wpdb, $t_surveys, $user_id);
                break;

            case 'add_question':
                $this->handle_add_question($wpdb, $t_surveys, $t_questions, $user_id);
                break;

            case 'update_question':
                $this->handle_update_question($wpdb, $t_surveys, $t_questions, $user_id);
                break;

            case 'delete_question':
                $this->handle_delete_question($wpdb, $t_surveys, $t_questions, $user_id);
                break;

            case 'delete_survey':
                $this->handle_delete_survey($wpdb, $t_surveys, $t_questions, $user_id);
                break;
        }
    }

    private function handle_save_survey(\wpdb $wpdb, string $table, int $user_id): void {
        $survey_id     = absint($_POST['survey_id'] ?? 0);
        $title         = sanitize_text_field($_POST['survey_title'] ?? '');
        $description   = sanitize_textarea_field($_POST['survey_description'] ?? '');
        $google_url    = esc_url_raw($_POST['survey_google_review_url'] ?? '');
        $status        = in_array($_POST['survey_status'] ?? '', ['draft', 'published'], true)
                         ? $_POST['survey_status'] : 'draft';

        if (empty($title)) {
            $this->redirect_with_msg('edit', $survey_id, 'error', 'タイトルを入力してください');
            return;
        }

        $now = current_time('mysql');

        if ($survey_id > 0) {
            // 更新 — 所有権チェック
            if ( ! $this->can_access_survey($wpdb, $table, $survey_id, $user_id) ) {
                wp_die('権限がありません。');
            }
            $wpdb->update($table, [
                'title'             => $title,
                'description'       => $description,
                'google_review_url' => $google_url,
                'status'            => $status,
                'updated_at'        => $now,
            ], ['id' => $survey_id], ['%s','%s','%s','%s','%s'], ['%d']);
        } else {
            // 新規作成
            $token = wp_generate_password(32, false);
            $wpdb->insert($table, [
                'user_id'           => $user_id,
                'title'             => $title,
                'description'       => $description,
                'google_review_url' => $google_url,
                'token'             => $token,
                'status'            => $status,
                'created_at'        => $now,
                'updated_at'        => $now,
            ], ['%d','%s','%s','%s','%s','%s','%s','%s']);
            $survey_id = (int) $wpdb->insert_id;
        }

        $this->redirect_with_msg('edit', $survey_id, 'success', 'アンケートを保存しました');
    }

    private function handle_add_question(\wpdb $wpdb, string $t_surveys, string $t_questions, int $user_id): void {
        $survey_id = absint($_POST['survey_id'] ?? 0);
        if ( ! $this->can_access_survey($wpdb, $t_surveys, $survey_id, $user_id) ) {
            wp_die('権限がありません。');
        }

        $label   = sanitize_text_field($_POST['q_label'] ?? '');
        $type    = sanitize_text_field($_POST['q_type'] ?? 'textarea');
        $desc    = sanitize_text_field($_POST['q_description'] ?? '');
        $ph      = sanitize_text_field($_POST['q_placeholder'] ?? '');
        $req     = isset($_POST['q_required']) ? 1 : 0;
        $order   = absint($_POST['q_sort_order'] ?? 0);

        // 選択肢: 改行区切りテキスト → JSON配列
        $options_raw = sanitize_textarea_field($_POST['q_options'] ?? '');
        $options_arr = array_values(array_filter(array_map('trim', explode("\n", $options_raw))));
        $options_json = !empty($options_arr) ? wp_json_encode($options_arr, JSON_UNESCAPED_UNICODE) : '';

        if (empty($label)) {
            $this->redirect_with_msg('edit', $survey_id, 'error', '質問文を入力してください');
            return;
        }

        $valid_types = ['textarea', 'radio', 'checkbox', 'text', 'select'];
        if ( ! in_array($type, $valid_types, true) ) {
            $type = 'textarea';
        }

        $wpdb->insert($t_questions, [
            'survey_id'   => $survey_id,
            'type'        => $type,
            'label'       => $label,
            'description' => $desc,
            'placeholder' => $ph,
            'options'     => $options_json,
            'required'    => $req,
            'sort_order'  => $order,
            'is_active'   => 1,
        ], ['%d','%s','%s','%s','%s','%s','%d','%d','%d']);

        // 更新日を更新
        $wpdb->update($wpdb->prefix . 'gcrev_surveys', ['updated_at' => current_time('mysql')], ['id' => $survey_id]);

        $this->redirect_with_msg('edit', $survey_id, 'success', '質問を追加しました');
    }

    private function handle_update_question(\wpdb $wpdb, string $t_surveys, string $t_questions, int $user_id): void {
        $survey_id   = absint($_POST['survey_id'] ?? 0);
        $question_id = absint($_POST['question_id'] ?? 0);
        if ( ! $this->can_access_survey($wpdb, $t_surveys, $survey_id, $user_id) ) {
            wp_die('権限がありません。');
        }

        $label   = sanitize_text_field($_POST['q_label'] ?? '');
        $type    = sanitize_text_field($_POST['q_type'] ?? 'textarea');
        $desc    = sanitize_text_field($_POST['q_description'] ?? '');
        $ph      = sanitize_text_field($_POST['q_placeholder'] ?? '');
        $req     = isset($_POST['q_required']) ? 1 : 0;
        $order   = absint($_POST['q_sort_order'] ?? 0);
        $active  = isset($_POST['q_is_active']) ? 1 : 0;

        $options_raw = sanitize_textarea_field($_POST['q_options'] ?? '');
        $options_arr = array_values(array_filter(array_map('trim', explode("\n", $options_raw))));
        $options_json = !empty($options_arr) ? wp_json_encode($options_arr, JSON_UNESCAPED_UNICODE) : '';

        $valid_types = ['textarea', 'radio', 'checkbox', 'text', 'select'];
        if ( ! in_array($type, $valid_types, true) ) $type = 'textarea';

        $wpdb->update($t_questions, [
            'type'        => $type,
            'label'       => $label,
            'description' => $desc,
            'placeholder' => $ph,
            'options'     => $options_json,
            'required'    => $req,
            'sort_order'  => $order,
            'is_active'   => $active,
        ], ['id' => $question_id, 'survey_id' => $survey_id]);

        $wpdb->update($wpdb->prefix . 'gcrev_surveys', ['updated_at' => current_time('mysql')], ['id' => $survey_id]);

        $this->redirect_with_msg('edit', $survey_id, 'success', '質問を更新しました');
    }

    private function handle_delete_question(\wpdb $wpdb, string $t_surveys, string $t_questions, int $user_id): void {
        $survey_id   = absint($_POST['survey_id'] ?? 0);
        $question_id = absint($_POST['question_id'] ?? 0);
        if ( ! $this->can_access_survey($wpdb, $t_surveys, $survey_id, $user_id) ) {
            wp_die('権限がありません。');
        }

        $wpdb->delete($t_questions, ['id' => $question_id, 'survey_id' => $survey_id], ['%d', '%d']);
        $wpdb->update($wpdb->prefix . 'gcrev_surveys', ['updated_at' => current_time('mysql')], ['id' => $survey_id]);

        $this->redirect_with_msg('edit', $survey_id, 'success', '質問を削除しました');
    }

    private function handle_delete_survey(\wpdb $wpdb, string $t_surveys, string $t_questions, int $user_id): void {
        $survey_id = absint($_POST['survey_id'] ?? 0);
        if ( ! $this->can_access_survey($wpdb, $t_surveys, $survey_id, $user_id) ) {
            wp_die('権限がありません。');
        }

        // 質問も削除
        $wpdb->delete($t_questions, ['survey_id' => $survey_id], ['%d']);
        $wpdb->delete($t_surveys, ['id' => $survey_id], ['%d']);

        wp_safe_redirect(add_query_arg([
            'page' => self::MENU_SLUG,
            'msg'  => 'deleted',
        ], admin_url('admin.php')));
        exit;
    }

    // =========================================================
    // ヘルパー
    // =========================================================

    private function can_access_survey(\wpdb $wpdb, string $table, int $survey_id, int $user_id): bool {
        if ( $survey_id <= 0 ) return false;
        if ( current_user_can('manage_options') ) return true;

        $owner = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE id = %d", $survey_id
        ));
        return $owner === $user_id;
    }

    private function redirect_with_msg(string $action, int $survey_id, string $type, string $msg): void {
        wp_safe_redirect(add_query_arg([
            'page'      => self::MENU_SLUG,
            'action'    => $action,
            'survey_id' => $survey_id,
            'msg_type'  => $type,
            'msg'       => urlencode($msg),
        ], admin_url('admin.php')));
        exit;
    }

    // =========================================================
    // ページレンダリング
    // =========================================================

    public function render_page(): void {
        $action = sanitize_text_field($_GET['action'] ?? '');

        if ($action === 'edit' || $action === 'new') {
            $this->render_edit_page();
        } elseif ($action === 'edit_question') {
            $this->render_edit_question_page();
        } else {
            $this->render_list_page();
        }
    }

    // ----- 一覧画面 -----

    private function render_list_page(): void {
        global $wpdb;
        $t_surveys   = $wpdb->prefix . 'gcrev_surveys';
        $t_questions = $wpdb->prefix . 'gcrev_survey_questions';
        $user_id     = get_current_user_id();
        $is_admin    = current_user_can('manage_options');

        // メッセージ表示
        $msg = sanitize_text_field($_GET['msg'] ?? '');

        // データ取得
        if ($is_admin) {
            $surveys = $wpdb->get_results("SELECT s.*, u.display_name, (SELECT COUNT(*) FROM {$t_questions} WHERE survey_id = s.id AND is_active = 1) as q_count FROM {$t_surveys} s LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID ORDER BY s.updated_at DESC");
        } else {
            $surveys = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, (SELECT COUNT(*) FROM {$t_questions} WHERE survey_id = s.id AND is_active = 1) as q_count FROM {$t_surveys} s WHERE s.user_id = %d ORDER BY s.updated_at DESC",
                $user_id
            ));
        }

        $review_page_url = home_url('/review-form/');
        ?>
        <div class="wrap">
            <h1 style="display:flex; align-items:center; gap:8px; margin-bottom:20px;">
                <span style="font-size:28px;">📋</span> アンケート管理
                <a href="<?php echo esc_url(add_query_arg(['page' => self::MENU_SLUG, 'action' => 'new'], admin_url('admin.php'))); ?>" class="page-title-action" style="margin-left:16px;">新規作成</a>
            </h1>

            <?php if ($msg === 'deleted'): ?>
                <div class="notice notice-success is-dismissible"><p>アンケートを削除しました。</p></div>
            <?php endif; ?>

            <?php if (empty($surveys)): ?>
                <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:24px; text-align:center; color:#0369a1;">
                    アンケートがまだありません。「新規作成」ボタンから最初のアンケートを作成しましょう。
                </div>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1100px;">
                    <thead>
                        <tr>
                            <?php if ($is_admin): ?><th style="width:140px;">アカウント</th><?php endif; ?>
                            <th>タイトル</th>
                            <th style="width:80px;">ステータス</th>
                            <th style="width:60px;">質問数</th>
                            <th style="width:140px;">更新日</th>
                            <th style="width:280px;">公開URL</th>
                            <th style="width:80px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($surveys as $s):
                        $edit_url = add_query_arg(['page' => self::MENU_SLUG, 'action' => 'edit', 'survey_id' => $s->id], admin_url('admin.php'));
                        $public_url = $review_page_url . '?t=' . $s->token;
                        $status_label = $s->status === 'published'
                            ? '<span style="color:#059669;font-weight:600;">公開</span>'
                            : '<span style="color:#94a3b8;">非公開</span>';
                    ?>
                        <tr>
                            <?php if ($is_admin): ?>
                                <td><?php echo esc_html($s->display_name ?? 'ID:' . $s->user_id); ?></td>
                            <?php endif; ?>
                            <td><a href="<?php echo esc_url($edit_url); ?>" style="font-weight:600;"><?php echo esc_html($s->title); ?></a></td>
                            <td><?php echo $status_label; ?></td>
                            <td style="text-align:center;"><?php echo (int) $s->q_count; ?></td>
                            <td><?php echo esc_html(wp_date('Y/m/d H:i', strtotime($s->updated_at))); ?></td>
                            <td>
                                <?php if ($s->status === 'published'): ?>
                                    <input type="text" value="<?php echo esc_attr($public_url); ?>" readonly onclick="this.select();document.execCommand('copy');" style="width:100%;font-size:11px;padding:3px 6px;border:1px solid #d1d5db;border-radius:4px;background:#f9fafb;cursor:pointer;" title="クリックでコピー">
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:12px;">公開後に表示</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">編集</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ----- 編集画面 -----

    private function render_edit_page(): void {
        global $wpdb;
        $t_surveys   = $wpdb->prefix . 'gcrev_surveys';
        $t_questions = $wpdb->prefix . 'gcrev_survey_questions';
        $user_id     = get_current_user_id();
        $survey_id   = absint($_GET['survey_id'] ?? 0);

        $survey    = null;
        $questions = [];

        if ($survey_id > 0) {
            if ( ! $this->can_access_survey($wpdb, $t_surveys, $survey_id, $user_id) ) {
                wp_die('権限がありません。');
            }
            $survey = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_surveys} WHERE id = %d", $survey_id));
            if ($survey) {
                $questions = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$t_questions} WHERE survey_id = %d ORDER BY sort_order ASC, id ASC",
                    $survey_id
                ));
            }
        }

        $is_new = !$survey;
        $title  = $survey->title ?? '';
        $desc   = $survey->description ?? '';
        $gurl   = $survey->google_review_url ?? '';
        $status = $survey->status ?? 'draft';
        $token  = $survey->token ?? '';

        // メッセージ
        $msg_type = sanitize_text_field($_GET['msg_type'] ?? '');
        $msg_text = sanitize_text_field(urldecode($_GET['msg'] ?? ''));

        $review_page_url = home_url('/review-form/');
        $list_url = add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php'));
        ?>
        <div class="wrap" style="max-width:900px;">
            <h1 style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                <span style="font-size:28px;">📋</span>
                <?php echo $is_new ? 'アンケート新規作成' : 'アンケート編集'; ?>
            </h1>
            <p><a href="<?php echo esc_url($list_url); ?>">&larr; 一覧に戻る</a></p>

            <?php if ($msg_text): ?>
                <div class="notice notice-<?php echo $msg_type === 'error' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html($msg_text); ?></p></div>
            <?php endif; ?>

            <!-- 基本情報 -->
            <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:24px; margin-bottom:24px;">
                <h2 style="margin:0 0 16px; font-size:16px; color:#1e293b;">基本情報</h2>
                <form method="post">
                    <input type="hidden" name="survey_action" value="save_survey">
                    <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
                    <?php wp_nonce_field('gcrev_survey_action', '_wpnonce_survey'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="survey_title">タイトル <span style="color:red;">*</span></label></th>
                            <td><input type="text" id="survey_title" name="survey_title" value="<?php echo esc_attr($title); ?>" class="regular-text" style="width:100%;max-width:500px;" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="survey_description">説明文</label></th>
                            <td><textarea id="survey_description" name="survey_description" rows="3" style="width:100%;max-width:500px;"><?php echo esc_textarea($desc); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="survey_google_review_url">Google口コミURL</label></th>
                            <td>
                                <input type="url" id="survey_google_review_url" name="survey_google_review_url" value="<?php echo esc_attr($gurl); ?>" class="regular-text" style="width:100%;max-width:500px;" placeholder="https://search.google.com/local/writereview?placeid=...">
                                <p class="description">Google口コミ投稿ページのURL。結果画面の「Google口コミを書く」ボタンに使用します。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">ステータス</th>
                            <td>
                                <select name="survey_status">
                                    <option value="draft" <?php selected($status, 'draft'); ?>>非公開（下書き）</option>
                                    <option value="published" <?php selected($status, 'published'); ?>>公開</option>
                                </select>
                            </td>
                        </tr>
                        <?php if ($token): ?>
                        <tr>
                            <th scope="row">公開URL</th>
                            <td>
                                <?php $public_url = $review_page_url . '?t=' . $token; ?>
                                <input type="text" value="<?php echo esc_attr($public_url); ?>" readonly onclick="this.select();document.execCommand('copy');" style="width:100%;max-width:500px;cursor:pointer;background:#f9fafb;" title="クリックでコピー">
                                <p class="description">この URL をお客様に共有してください。</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <?php submit_button($is_new ? 'アンケートを作成' : '基本情報を保存'); ?>
                </form>
            </div>

            <?php if (!$is_new): ?>
            <!-- 質問管理 -->
            <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:24px; margin-bottom:24px;">
                <h2 style="margin:0 0 16px; font-size:16px; color:#1e293b;">質問一覧</h2>

                <?php if (empty($questions)): ?>
                    <p style="color:#94a3b8;">質問がまだありません。下の「質問を追加」フォームから追加してください。</p>
                <?php else: ?>
                    <style>
                    #gcrev-questions-sortable tr { cursor: grab; }
                    #gcrev-questions-sortable tr:active { cursor: grabbing; }
                    #gcrev-questions-sortable tr.gcrev-drag-over { border-top: 3px solid #3b82f6; }
                    .gcrev-drag-handle { color: #94a3b8; font-size: 18px; cursor: grab; user-select: none; }
                    .gcrev-drag-handle:hover { color: #64748b; }
                    .gcrev-sort-saving { color: #3b82f6; font-size: 13px; margin-left: 12px; }
                    .gcrev-sort-saved { color: #059669; font-size: 13px; margin-left: 12px; }
                    </style>
                    <p style="font-size:13px;color:#64748b;margin-bottom:8px;">💡 行をドラッグして並び順を変更できます。変更は自動で保存されます。<span id="gcrev-sort-status"></span></p>
                    <table class="widefat striped" style="margin-bottom:24px;">
                        <thead>
                            <tr>
                                <th style="width:40px;"></th>
                                <th>質問文</th>
                                <th style="width:100px;">タイプ</th>
                                <th style="width:60px;">必須</th>
                                <th style="width:60px;">有効</th>
                                <th style="width:120px;">操作</th>
                            </tr>
                        </thead>
                        <tbody id="gcrev-questions-sortable">
                        <?php foreach ($questions as $q):
                            $type_labels = ['textarea' => 'テキスト', 'radio' => 'ラジオ', 'checkbox' => 'チェック', 'text' => '一行テキスト', 'select' => 'セレクト'];
                            $type_label  = $type_labels[$q->type] ?? $q->type;
                            $eq_url = add_query_arg([
                                'page' => self::MENU_SLUG, 'action' => 'edit_question',
                                'survey_id' => $survey_id, 'question_id' => $q->id
                            ], admin_url('admin.php'));
                        ?>
                            <tr data-question-id="<?php echo (int) $q->id; ?>" <?php echo $q->is_active ? '' : 'style="opacity:0.5;"'; ?>>
                                <td style="text-align:center;"><span class="gcrev-drag-handle" title="ドラッグで並び替え">☰</span></td>
                                <td><?php echo esc_html(mb_strimwidth($q->label, 0, 60, '...')); ?></td>
                                <td><?php echo esc_html($type_label); ?></td>
                                <td style="text-align:center;"><?php echo $q->required ? '必須' : '任意'; ?></td>
                                <td style="text-align:center;"><?php echo $q->is_active ? '有効' : '無効'; ?></td>
                                <td>
                                    <a href="<?php echo esc_url($eq_url); ?>" class="button button-small">編集</a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('この質問を削除しますか？');">
                                        <input type="hidden" name="survey_action" value="delete_question">
                                        <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
                                        <input type="hidden" name="question_id" value="<?php echo $q->id; ?>">
                                        <?php wp_nonce_field('gcrev_survey_action', '_wpnonce_survey'); ?>
                                        <button type="submit" class="button button-small" style="color:#dc2626;">削除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <!-- 質問追加フォーム -->
                <div style="background:#f8f9fa; border:1px solid #e5e7eb; border-radius:8px; padding:20px;">
                    <h3 style="margin:0 0 12px; font-size:14px; color:#374151;">質問を追加</h3>
                    <form method="post">
                        <input type="hidden" name="survey_action" value="add_question">
                        <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
                        <?php wp_nonce_field('gcrev_survey_action', '_wpnonce_survey'); ?>

                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th style="width:120px;padding:8px 10px;"><label for="q_label">質問文 <span style="color:red;">*</span></label></th>
                                <td style="padding:8px 10px;"><input type="text" id="q_label" name="q_label" class="regular-text" style="width:100%;" required></td>
                            </tr>
                            <tr>
                                <th style="padding:8px 10px;"><label for="q_type">タイプ</label></th>
                                <td style="padding:8px 10px;">
                                    <select id="q_type" name="q_type" onchange="toggleOptionsField()">
                                        <option value="checkbox">チェックボックス（複数選択）</option>
                                        <option value="radio">ラジオボタン（単一選択）</option>
                                        <option value="textarea">テキストエリア（自由記述）</option>
                                        <option value="text">テキスト（一行）</option>
                                        <option value="select">セレクトボックス</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 10px;">必須</th>
                                <td style="padding:8px 10px;"><label><input type="checkbox" name="q_required" value="1" checked> 回答必須にする</label></td>
                            </tr>
                            <tr>
                                <th style="padding:8px 10px;"><label for="q_description">補助説明</label></th>
                                <td style="padding:8px 10px;"><input type="text" id="q_description" name="q_description" class="regular-text" style="width:100%;" placeholder="例：当てはまるものをすべて選択してください"></td>
                            </tr>
                            <tr>
                                <th style="padding:8px 10px;"><label for="q_placeholder">プレースホルダー</label></th>
                                <td style="padding:8px 10px;"><input type="text" id="q_placeholder" name="q_placeholder" class="regular-text" style="width:100%;" placeholder="テキスト入力欄に表示するヒント文"></td>
                            </tr>
                            <tr id="options-field">
                                <th style="padding:8px 10px;"><label for="q_options">選択肢</label></th>
                                <td style="padding:8px 10px;">
                                    <textarea id="q_options" name="q_options" rows="4" style="width:100%;" placeholder="選択肢を改行区切りで入力&#10;例：&#10;とても満足&#10;満足&#10;ふつう"></textarea>
                                    <p class="description">1行に1つの選択肢を入力してください。</p>
                                </td>
                            </tr>
                            <tr>
                                <th style="padding:8px 10px;"><label for="q_sort_order">並び順</label></th>
                                <td style="padding:8px 10px;"><input type="number" id="q_sort_order" name="q_sort_order" value="<?php echo count($questions) * 10 + 10; ?>" min="0" style="width:80px;"> <span class="description">小さい数字が先に表示されます</span></td>
                            </tr>
                        </table>

                        <p style="margin:12px 0 0;">
                            <button type="submit" class="button button-primary">質問を追加</button>
                        </p>
                    </form>
                </div>
            </div>

            <!-- アンケート削除 -->
            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:16px 24px;">
                <form method="post" onsubmit="return confirm('このアンケートと全ての質問を削除します。この操作は取り消せません。よろしいですか？');">
                    <input type="hidden" name="survey_action" value="delete_survey">
                    <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
                    <?php wp_nonce_field('gcrev_survey_action', '_wpnonce_survey'); ?>
                    <button type="submit" class="button" style="color:#dc2626;border-color:#fca5a5;">このアンケートを削除</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function toggleOptionsField() {
            var type = document.getElementById('q_type').value;
            var show = (type === 'checkbox' || type === 'radio' || type === 'select');
            document.getElementById('options-field').style.display = show ? '' : 'none';
        }
        toggleOptionsField();

        // ===== ドラッグ＆ドロップ並び替え =====
        (function() {
            var tbody = document.getElementById('gcrev-questions-sortable');
            if (!tbody) return;

            var surveyId = <?php echo (int) $survey_id; ?>;
            var apiUrl   = <?php echo wp_json_encode(rest_url('gcrev/v1/survey/question/reorder')); ?>;
            var dragRow  = null;

            tbody.addEventListener('dragstart', function(e) {
                var tr = e.target.closest('tr');
                if (!tr) return;
                dragRow = tr;
                tr.style.opacity = '0.4';
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', '');
            });

            tbody.addEventListener('dragend', function(e) {
                if (dragRow) dragRow.style.opacity = '';
                dragRow = null;
                // clear all drag-over classes
                tbody.querySelectorAll('.gcrev-drag-over').forEach(function(r) {
                    r.classList.remove('gcrev-drag-over');
                });
            });

            tbody.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                var tr = e.target.closest('tr');
                if (!tr || tr === dragRow) return;
                // highlight
                tbody.querySelectorAll('.gcrev-drag-over').forEach(function(r) {
                    r.classList.remove('gcrev-drag-over');
                });
                tr.classList.add('gcrev-drag-over');
            });

            tbody.addEventListener('drop', function(e) {
                e.preventDefault();
                var tr = e.target.closest('tr');
                if (!tr || !dragRow || tr === dragRow) return;
                tr.classList.remove('gcrev-drag-over');

                // 挿入位置を決定
                var rows = Array.from(tbody.querySelectorAll('tr'));
                var dragIdx = rows.indexOf(dragRow);
                var dropIdx = rows.indexOf(tr);
                if (dragIdx < dropIdx) {
                    tr.parentNode.insertBefore(dragRow, tr.nextSibling);
                } else {
                    tr.parentNode.insertBefore(dragRow, tr);
                }

                saveOrder();
            });

            // 全行を draggable に
            tbody.querySelectorAll('tr').forEach(function(tr) {
                tr.setAttribute('draggable', 'true');
            });

            function saveOrder() {
                var status = document.getElementById('gcrev-sort-status');
                if (status) { status.textContent = '保存中...'; status.className = 'gcrev-sort-saving'; }

                var rows = tbody.querySelectorAll('tr[data-question-id]');
                var order = [];
                rows.forEach(function(row, idx) {
                    order.push({ id: parseInt(row.getAttribute('data-question-id'), 10), sort_order: (idx + 1) * 10 });
                });

                fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ survey_id: surveyId, order: order })
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (status) {
                        if (data.success) {
                            status.textContent = '✓ 保存しました';
                            status.className = 'gcrev-sort-saved';
                        } else {
                            status.textContent = '⚠ 保存失敗';
                            status.className = '';
                            status.style.color = '#dc2626';
                        }
                        setTimeout(function() { status.textContent = ''; }, 2000);
                    }
                })
                .catch(function() {
                    if (status) {
                        status.textContent = '⚠ 通信エラー';
                        status.className = '';
                        status.style.color = '#dc2626';
                        setTimeout(function() { status.textContent = ''; }, 2000);
                    }
                });
            }
        })();
        </script>
        <?php
    }

    // ----- 質問編集画面 -----

    private function render_edit_question_page(): void {
        global $wpdb;
        $t_surveys   = $wpdb->prefix . 'gcrev_surveys';
        $t_questions = $wpdb->prefix . 'gcrev_survey_questions';
        $user_id     = get_current_user_id();
        $survey_id   = absint($_GET['survey_id'] ?? 0);
        $question_id = absint($_GET['question_id'] ?? 0);

        if ( ! $this->can_access_survey($wpdb, $t_surveys, $survey_id, $user_id) ) {
            wp_die('権限がありません。');
        }

        $q = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t_questions} WHERE id = %d AND survey_id = %d", $question_id, $survey_id
        ));
        if (!$q) {
            wp_die('質問が見つかりません。');
        }

        // 選択肢をJSON→改行区切りテキストに変換
        $options_text = '';
        if (!empty($q->options)) {
            $opts = json_decode($q->options, true);
            if (is_array($opts)) {
                $options_text = implode("\n", $opts);
            }
        }

        $back_url = add_query_arg(['page' => self::MENU_SLUG, 'action' => 'edit', 'survey_id' => $survey_id], admin_url('admin.php'));

        // メッセージ
        $msg_type = sanitize_text_field($_GET['msg_type'] ?? '');
        $msg_text = sanitize_text_field(urldecode($_GET['msg'] ?? ''));
        ?>
        <div class="wrap" style="max-width:700px;">
            <h1 style="margin-bottom:8px;">質問を編集</h1>
            <p><a href="<?php echo esc_url($back_url); ?>">&larr; アンケート編集に戻る</a></p>

            <?php if ($msg_text): ?>
                <div class="notice notice-<?php echo $msg_type === 'error' ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html($msg_text); ?></p></div>
            <?php endif; ?>

            <div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:24px;">
                <form method="post">
                    <input type="hidden" name="survey_action" value="update_question">
                    <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
                    <input type="hidden" name="question_id" value="<?php echo $question_id; ?>">
                    <?php wp_nonce_field('gcrev_survey_action', '_wpnonce_survey'); ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="q_label">質問文 <span style="color:red;">*</span></label></th>
                            <td><input type="text" id="q_label" name="q_label" value="<?php echo esc_attr($q->label); ?>" class="regular-text" style="width:100%;" required></td>
                        </tr>
                        <tr>
                            <th><label for="q_type">タイプ</label></th>
                            <td>
                                <select id="q_type" name="q_type" onchange="toggleOptionsField()">
                                    <option value="checkbox" <?php selected($q->type, 'checkbox'); ?>>チェックボックス（複数選択）</option>
                                    <option value="radio" <?php selected($q->type, 'radio'); ?>>ラジオボタン（単一選択）</option>
                                    <option value="textarea" <?php selected($q->type, 'textarea'); ?>>テキストエリア（自由記述）</option>
                                    <option value="text" <?php selected($q->type, 'text'); ?>>テキスト（一行）</option>
                                    <option value="select" <?php selected($q->type, 'select'); ?>>セレクトボックス</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>必須</th>
                            <td><label><input type="checkbox" name="q_required" value="1" <?php checked($q->required, 1); ?>> 回答必須にする</label></td>
                        </tr>
                        <tr>
                            <th>有効</th>
                            <td><label><input type="checkbox" name="q_is_active" value="1" <?php checked($q->is_active, 1); ?>> フォームに表示する</label></td>
                        </tr>
                        <tr>
                            <th><label for="q_description">補助説明</label></th>
                            <td><input type="text" id="q_description" name="q_description" value="<?php echo esc_attr($q->description); ?>" class="regular-text" style="width:100%;"></td>
                        </tr>
                        <tr>
                            <th><label for="q_placeholder">プレースホルダー</label></th>
                            <td><input type="text" id="q_placeholder" name="q_placeholder" value="<?php echo esc_attr($q->placeholder); ?>" class="regular-text" style="width:100%;"></td>
                        </tr>
                        <tr id="options-field">
                            <th><label for="q_options">選択肢</label></th>
                            <td>
                                <textarea id="q_options" name="q_options" rows="5" style="width:100%;"><?php echo esc_textarea($options_text); ?></textarea>
                                <p class="description">1行に1つの選択肢を入力してください。</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="q_sort_order">並び順</label></th>
                            <td><input type="number" id="q_sort_order" name="q_sort_order" value="<?php echo (int) $q->sort_order; ?>" min="0" style="width:80px;"></td>
                        </tr>
                    </table>

                    <?php submit_button('質問を更新'); ?>
                </form>
            </div>
        </div>

        <script>
        function toggleOptionsField() {
            var type = document.getElementById('q_type').value;
            var show = (type === 'checkbox' || type === 'radio' || type === 'select');
            document.getElementById('options-field').style.display = show ? '' : 'none';
        }
        toggleOptionsField();
        </script>
        <?php
    }
}
