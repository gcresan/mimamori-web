<?php
// FILE: inc/gcrev-api/admin/class-rank-tracker-settings-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_Rank_Tracker_Settings_Page') ) { return; }

/**
 * Gcrev_Rank_Tracker_Settings_Page
 *
 * WordPress管理画面に「みまもりウェブ > 順位トラッキング」ページを追加する。
 * ユーザーごとのキーワード設定・手動取得・API接続テストを管理する。
 *
 * @package Mimamori_Web
 * @since   2.5.0
 */
class Gcrev_Rank_Tracker_Settings_Page {

    /** メニュースラッグ */
    private const MENU_SLUG = 'gcrev-rank-tracker';

    /**
     * フック登録
     */
    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'handle_actions']);
    }

    // =========================================================
    // メニュー登録
    // =========================================================

    public function add_menu_page(): void {
        if ( empty( $GLOBALS['admin_page_hooks']['gcrev-insight'] ) ) {
            add_menu_page(
                'みまもりウェブ',
                'みまもりウェブ',
                'manage_options',
                'gcrev-insight',
                '__return_null',
                'dashicons-chart-area',
                30
            );
        }

        add_submenu_page(
            'gcrev-insight',
            '順位トラッキング - みまもりウェブ',
            '🔍 順位トラッキング',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    // =========================================================
    // POST アクション処理
    // =========================================================

    public function handle_actions(): void {
        if ( ! current_user_can('manage_options') ) {
            return;
        }

        if ( empty($_POST['_gcrev_rank_tracker_nonce']) ) {
            return;
        }

        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['_gcrev_rank_tracker_nonce'] ) ),
            'gcrev_rank_tracker_action'
        ) ) {
            wp_die('不正なリクエストです。');
        }

        $action  = isset($_POST['gcrev_action']) ? sanitize_text_field( wp_unslash( $_POST['gcrev_action'] ) ) : '';
        $user_id = isset($_POST['gcrev_target_user']) ? absint( $_POST['gcrev_target_user'] ) : 0;

        global $wpdb;
        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_rank_results';
        $tz        = wp_timezone();
        $now       = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

        $redirect_args = [ 'page' => self::MENU_SLUG ];
        if ( $user_id > 0 ) {
            $redirect_args['user_id'] = $user_id;
        }

        switch ( $action ) {
            case 'add_keyword':
                $keyword       = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
                $target_domain = sanitize_text_field( wp_unslash( $_POST['target_domain'] ?? '' ) );
                $location_code = absint( $_POST['location_code'] ?? 1009312 );
                $memo          = sanitize_text_field( wp_unslash( $_POST['memo'] ?? '' ) );

                if ( $keyword !== '' && $target_domain !== '' && $user_id > 0 ) {
                    $wpdb->insert( $kw_table, [
                        'user_id'       => $user_id,
                        'keyword'       => $keyword,
                        'target_domain' => $target_domain,
                        'location_code' => $location_code,
                        'language_code' => 'ja',
                        'enabled'       => 1,
                        'sort_order'    => 0,
                        'memo'          => $memo,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ] );
                    $redirect_args['msg'] = 'added';
                }
                break;

            case 'edit_keyword':
                $kw_id         = absint( $_POST['keyword_id'] ?? 0 );
                $keyword       = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
                $target_domain = sanitize_text_field( wp_unslash( $_POST['target_domain'] ?? '' ) );
                $location_code = absint( $_POST['location_code'] ?? 1009312 );
                $memo          = sanitize_text_field( wp_unslash( $_POST['memo'] ?? '' ) );

                if ( $kw_id > 0 && $keyword !== '' && $target_domain !== '' ) {
                    $wpdb->update( $kw_table, [
                        'keyword'       => $keyword,
                        'target_domain' => $target_domain,
                        'location_code' => $location_code,
                        'memo'          => $memo,
                        'updated_at'    => $now,
                    ], [ 'id' => $kw_id ], null, [ '%d' ] );
                    $redirect_args['msg'] = 'updated';
                }
                break;

            case 'delete_keyword':
                $kw_id = absint( $_POST['keyword_id'] ?? 0 );
                if ( $kw_id > 0 ) {
                    $wpdb->delete( $kw_table, [ 'id' => $kw_id ], [ '%d' ] );
                    $wpdb->delete( $res_table, [ 'keyword_id' => $kw_id ], [ '%d' ] );
                    $redirect_args['msg'] = 'deleted';
                }
                break;

            case 'toggle_keyword':
                $kw_id   = absint( $_POST['keyword_id'] ?? 0 );
                $enabled = absint( $_POST['enabled'] ?? 0 );
                if ( $kw_id > 0 ) {
                    $wpdb->update( $kw_table, [
                        'enabled'    => $enabled ? 1 : 0,
                        'updated_at' => $now,
                    ], [ 'id' => $kw_id ], null, [ '%d' ] );
                    $redirect_args['msg'] = 'toggled';
                }
                break;

            case 'manual_fetch':
                $kw_id = absint( $_POST['keyword_id'] ?? 0 );
                if ( $kw_id > 0 && class_exists( 'Gcrev_DataForSEO_Client' ) && Gcrev_DataForSEO_Client::is_configured() ) {
                    $kw = $wpdb->get_row( $wpdb->prepare(
                        "SELECT * FROM {$kw_table} WHERE id = %d", $kw_id
                    ), ARRAY_A );

                    if ( $kw ) {
                        $api     = new Gcrev_Insight_API( false );
                        $config  = new Gcrev_Config();
                        $client  = new Gcrev_DataForSEO_Client( $config );
                        $results = $client->fetch_rankings_for_keyword(
                            $kw['keyword'],
                            $kw['target_domain'],
                            (int) $kw['location_code'],
                            $kw['language_code']
                        );

                        // save_rank_results は API クラスの private メソッドなので
                        // ここでは直接 INSERT する
                        $iso_week = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'o-W' );

                        foreach ( ['desktop', 'mobile'] as $device ) {
                            $r = $results[ $device ] ?? null;
                            if ( ! $r || isset( $r['error'] ) ) { continue; }

                            $rg  = $r['is_ranked'] ? $wpdb->prepare( '%d', $r['rank_group'] ) : 'NULL';
                            $ra  = $r['is_ranked'] ? $wpdb->prepare( '%d', $r['rank_absolute'] ) : 'NULL';
                            $url = $r['found_url'] !== null ? $wpdb->prepare( '%s', $r['found_url'] ) : "''";
                            $dom = $r['found_domain'] !== null ? $wpdb->prepare( '%s', $r['found_domain'] ) : "''";

                            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                            $sql = $wpdb->prepare(
                                "INSERT IGNORE INTO {$res_table}
                                 (keyword_id, user_id, device, rank_group, rank_absolute, found_url, found_domain, is_ranked, serp_type, api_source, iso_year_week, fetched_at, created_at)
                                 VALUES (%d, %d, %s, {$rg}, {$ra}, {$url}, {$dom}, %d, %s, %s, %s, %s, %s)",
                                (int) $kw['id'],
                                (int) $kw['user_id'],
                                $device,
                                $r['is_ranked'] ? 1 : 0,
                                $r['serp_type'] ?? 'organic',
                                'dataforseo',
                                $iso_week,
                                $now,
                                $now
                            );
                            $wpdb->query( $sql );
                        }

                        $redirect_args['msg'] = 'fetched';
                    }
                } else {
                    $redirect_args['msg'] = 'not_configured';
                }
                break;

            case 'manual_fetch_all':
                if ( $user_id > 0 && class_exists( 'Gcrev_DataForSEO_Client' ) && Gcrev_DataForSEO_Client::is_configured() ) {
                    $keywords = $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$kw_table} WHERE user_id = %d AND enabled = 1 AND target_domain != '' ORDER BY sort_order ASC, id ASC",
                        $user_id
                    ), ARRAY_A );

                    $config   = new Gcrev_Config();
                    $client   = new Gcrev_DataForSEO_Client( $config );
                    $iso_week = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'o-W' );
                    $fetched  = 0;

                    foreach ( $keywords as $kw ) {
                        // 重複チェック
                        $already = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$res_table} WHERE keyword_id = %d AND iso_year_week = %s",
                            (int) $kw['id'], $iso_week
                        ) );
                        if ( $already > 0 ) { continue; }

                        $results = $client->fetch_rankings_for_keyword(
                            $kw['keyword'],
                            $kw['target_domain'],
                            (int) $kw['location_code'],
                            $kw['language_code']
                        );

                        foreach ( ['desktop', 'mobile'] as $device ) {
                            $r = $results[ $device ] ?? null;
                            if ( ! $r || isset( $r['error'] ) ) { continue; }

                            $rg  = $r['is_ranked'] ? $wpdb->prepare( '%d', $r['rank_group'] ) : 'NULL';
                            $ra  = $r['is_ranked'] ? $wpdb->prepare( '%d', $r['rank_absolute'] ) : 'NULL';
                            $url = $r['found_url'] !== null ? $wpdb->prepare( '%s', $r['found_url'] ) : "''";
                            $dom = $r['found_domain'] !== null ? $wpdb->prepare( '%s', $r['found_domain'] ) : "''";

                            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                            $sql = $wpdb->prepare(
                                "INSERT IGNORE INTO {$res_table}
                                 (keyword_id, user_id, device, rank_group, rank_absolute, found_url, found_domain, is_ranked, serp_type, api_source, iso_year_week, fetched_at, created_at)
                                 VALUES (%d, %d, %s, {$rg}, {$ra}, {$url}, {$dom}, %d, %s, %s, %s, %s, %s)",
                                (int) $kw['id'],
                                (int) $kw['user_id'],
                                $device,
                                $r['is_ranked'] ? 1 : 0,
                                $r['serp_type'] ?? 'organic',
                                'dataforseo',
                                $iso_week,
                                $now,
                                $now
                            );
                            $wpdb->query( $sql );
                        }

                        $fetched++;
                    }

                    $redirect_args['msg']     = 'fetched_all';
                    $redirect_args['fetched'] = $fetched;
                } else {
                    $redirect_args['msg'] = 'not_configured';
                }
                break;

            case 'test_connection':
                if ( class_exists( 'Gcrev_DataForSEO_Client' ) ) {
                    $config = new Gcrev_Config();
                    $client = new Gcrev_DataForSEO_Client( $config );
                    $result = $client->test_connection();
                    $redirect_args['msg']      = $result['success'] ? 'conn_ok' : 'conn_fail';
                    $redirect_args['conn_msg'] = urlencode( $result['message'] );
                } else {
                    $redirect_args['msg'] = 'not_configured';
                }
                break;

            default:
                return;
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    // =========================================================
    // ページ描画
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can('manage_options') ) {
            wp_die('権限がありません。');
        }

        global $wpdb;
        $kw_table  = $wpdb->prefix . 'gcrev_rank_keywords';
        $res_table = $wpdb->prefix . 'gcrev_rank_results';

        // メッセージ
        $msg = sanitize_text_field( $_GET['msg'] ?? '' );
        $messages = [
            'added'          => '✅ キーワードを追加しました。',
            'updated'        => '✅ キーワードを更新しました。',
            'deleted'        => '✅ キーワードを削除しました。',
            'toggled'        => '✅ 有効/無効を切り替えました。',
            'fetched'        => '✅ 順位データを取得しました。',
            'fetched_all'    => '✅ 一括取得完了（' . absint( $_GET['fetched'] ?? 0 ) . '件）',
            'not_configured' => '⚠️ DataForSEO API が未設定です。wp-config.php を確認してください。',
            'conn_ok'        => '✅ ' . esc_html( urldecode( $_GET['conn_msg'] ?? '' ) ),
            'conn_fail'      => '❌ ' . esc_html( urldecode( $_GET['conn_msg'] ?? '' ) ),
        ];

        // API 接続状態
        $is_configured = class_exists( 'Gcrev_DataForSEO_Client' ) && Gcrev_DataForSEO_Client::is_configured();

        // ユーザー一覧（管理者以外）
        $users = get_users([
            'role__not_in' => ['administrator'],
            'orderby'      => 'display_name',
            'order'        => 'ASC',
        ]);

        // 選択中ユーザー
        $selected_user = absint( $_GET['user_id'] ?? 0 );

        // キーワード一覧
        $keywords = [];
        if ( $selected_user > 0 ) {
            $keywords = $wpdb->get_results( $wpdb->prepare(
                "SELECT k.*,
                        (SELECT r.rank_group FROM {$res_table} r WHERE r.keyword_id = k.id AND r.device = 'desktop' ORDER BY r.fetched_at DESC LIMIT 1) as latest_desktop,
                        (SELECT r.rank_group FROM {$res_table} r WHERE r.keyword_id = k.id AND r.device = 'mobile' ORDER BY r.fetched_at DESC LIMIT 1) as latest_mobile,
                        (SELECT r.fetched_at FROM {$res_table} r WHERE r.keyword_id = k.id ORDER BY r.fetched_at DESC LIMIT 1) as last_fetched
                 FROM {$kw_table} k
                 WHERE k.user_id = %d
                 ORDER BY k.sort_order ASC, k.id ASC",
                $selected_user
            ), ARRAY_A );
        }

        // 編集モード
        $edit_kw_id = absint( $_GET['edit'] ?? 0 );
        $edit_kw    = null;
        if ( $edit_kw_id > 0 ) {
            $edit_kw = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$kw_table} WHERE id = %d", $edit_kw_id
            ), ARRAY_A );
        }

        // ロケーションコード一覧
        $locations = [
            1009312 => '日本（全国）',
            1009283 => '東京都',
            1009303 => '大阪府',
            1009269 => '愛知県',
            1009280 => '福岡県',
            1009275 => '北海道',
            1009271 => '愛媛県',
        ];

        ?>
        <div class="wrap">
            <h1>🔍 順位トラッキング</h1>

            <?php if ( isset( $messages[ $msg ] ) ) : ?>
                <div class="notice <?php echo strpos( $msg, 'fail' ) !== false || $msg === 'not_configured' ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                    <p><?php echo $messages[ $msg ]; ?></p>
                </div>
            <?php endif; ?>

            <!-- API 接続状態 -->
            <div class="card" style="max-width:700px; margin-bottom:20px;">
                <h2>API 接続状態</h2>
                <p>
                    <?php if ( $is_configured ) : ?>
                        <span style="color:#0a7b0a; font-weight:600;">✅ 設定済み</span>
                        <span style="color:#666; margin-left:8px;">(DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD)</span>
                    <?php else : ?>
                        <span style="color:#d63638; font-weight:600;">❌ 未設定</span>
                        <span style="color:#666; margin-left:8px;">wp-config.php に <code>DATAFORSEO_LOGIN</code> / <code>DATAFORSEO_PASSWORD</code> を設定してください。</span>
                    <?php endif; ?>
                </p>
                <?php if ( $is_configured ) : ?>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('gcrev_rank_tracker_action', '_gcrev_rank_tracker_nonce'); ?>
                        <input type="hidden" name="gcrev_action" value="test_connection">
                        <button type="submit" class="button">テスト接続</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- ユーザー選択 -->
            <div class="card" style="max-width:700px; margin-bottom:20px;">
                <h2>ユーザー選択</h2>
                <form method="get">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
                    <select name="user_id" onchange="this.form.submit();" style="min-width:300px;">
                        <option value="0">-- ユーザーを選択 --</option>
                        <?php foreach ( $users as $u ) : ?>
                            <option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $selected_user, $u->ID ); ?>>
                                <?php echo esc_html( $u->display_name ); ?> (ID: <?php echo esc_html( $u->ID ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if ( $selected_user > 0 ) : ?>

            <!-- キーワード追加/編集フォーム -->
            <div class="card" style="max-width:700px; margin-bottom:20px;">
                <h2><?php echo $edit_kw ? 'キーワード編集' : 'キーワード追加'; ?></h2>
                <form method="post">
                    <?php wp_nonce_field('gcrev_rank_tracker_action', '_gcrev_rank_tracker_nonce'); ?>
                    <input type="hidden" name="gcrev_action" value="<?php echo $edit_kw ? 'edit_keyword' : 'add_keyword'; ?>">
                    <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                    <?php if ( $edit_kw ) : ?>
                        <input type="hidden" name="keyword_id" value="<?php echo esc_attr( $edit_kw['id'] ); ?>">
                    <?php endif; ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="keyword">キーワード</label></th>
                            <td><input type="text" id="keyword" name="keyword" value="<?php echo esc_attr( $edit_kw['keyword'] ?? '' ); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="target_domain">対象ドメイン</label></th>
                            <td><input type="text" id="target_domain" name="target_domain" value="<?php echo esc_attr( $edit_kw['target_domain'] ?? '' ); ?>" class="regular-text" placeholder="example.com" required></td>
                        </tr>
                        <tr>
                            <th><label for="location_code">地域</label></th>
                            <td>
                                <select id="location_code" name="location_code">
                                    <?php foreach ( $locations as $code => $label ) : ?>
                                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( (int)( $edit_kw['location_code'] ?? 1009312 ), $code ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="memo">メモ</label></th>
                            <td><input type="text" id="memo" name="memo" value="<?php echo esc_attr( $edit_kw['memo'] ?? '' ); ?>" class="regular-text"></td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary"><?php echo $edit_kw ? '更新' : '追加'; ?></button>
                        <?php if ( $edit_kw ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'user_id' => $selected_user ], admin_url('admin.php') ) ); ?>" class="button">キャンセル</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <!-- キーワード一覧テーブル -->
            <div class="card" style="max-width:1100px;">
                <h2>
                    キーワード一覧
                    <?php if ( $is_configured && ! empty( $keywords ) ) : ?>
                        <form method="post" style="display:inline; margin-left:16px;">
                            <?php wp_nonce_field('gcrev_rank_tracker_action', '_gcrev_rank_tracker_nonce'); ?>
                            <input type="hidden" name="gcrev_action" value="manual_fetch_all">
                            <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                            <button type="submit" class="button" onclick="return confirm('全キーワードの順位を取得します。API使用量が発生します。よろしいですか？');">🔄 一括取得</button>
                        </form>
                    <?php endif; ?>
                </h2>

                <?php if ( empty( $keywords ) ) : ?>
                    <p style="color:#666;">キーワードが登録されていません。</p>
                <?php else : ?>
                    <table class="widefat striped" style="margin-top:12px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>キーワード</th>
                                <th>対象ドメイン</th>
                                <th>地域</th>
                                <th>PC順位</th>
                                <th>SP順位</th>
                                <th>有効</th>
                                <th>メモ</th>
                                <th>最終取得</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $keywords as $kw ) : ?>
                            <tr<?php echo ! $kw['enabled'] ? ' style="opacity:0.5;"' : ''; ?>>
                                <td><?php echo esc_html( $kw['id'] ); ?></td>
                                <td><strong><?php echo esc_html( $kw['keyword'] ); ?></strong></td>
                                <td><?php echo esc_html( $kw['target_domain'] ); ?></td>
                                <td><?php echo esc_html( $locations[ (int) $kw['location_code'] ] ?? $kw['location_code'] ); ?></td>
                                <td><?php echo $kw['latest_desktop'] !== null ? esc_html( $kw['latest_desktop'] ) . '位' : '<span style="color:#999;">—</span>'; ?></td>
                                <td><?php echo $kw['latest_mobile'] !== null ? esc_html( $kw['latest_mobile'] ) . '位' : '<span style="color:#999;">—</span>'; ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('gcrev_rank_tracker_action', '_gcrev_rank_tracker_nonce'); ?>
                                        <input type="hidden" name="gcrev_action" value="toggle_keyword">
                                        <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                                        <input type="hidden" name="keyword_id" value="<?php echo esc_attr( $kw['id'] ); ?>">
                                        <input type="hidden" name="enabled" value="<?php echo $kw['enabled'] ? '0' : '1'; ?>">
                                        <button type="submit" class="button-link" title="<?php echo $kw['enabled'] ? '無効にする' : '有効にする'; ?>">
                                            <?php echo $kw['enabled'] ? '✅' : '⬜'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td><?php echo esc_html( $kw['memo'] ); ?></td>
                                <td><?php echo $kw['last_fetched'] ? esc_html( substr( $kw['last_fetched'], 0, 10 ) ) : '<span style="color:#999;">未取得</span>'; ?></td>
                                <td>
                                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::MENU_SLUG, 'user_id' => $selected_user, 'edit' => $kw['id'] ], admin_url('admin.php') ) ); ?>" class="button button-small">編集</a>

                                    <?php if ( $is_configured ) : ?>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('gcrev_rank_tracker_action', '_gcrev_rank_tracker_nonce'); ?>
                                        <input type="hidden" name="gcrev_action" value="manual_fetch">
                                        <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                                        <input type="hidden" name="keyword_id" value="<?php echo esc_attr( $kw['id'] ); ?>">
                                        <button type="submit" class="button button-small" onclick="return confirm('この1キーワードの順位を取得します（API 2回分）。');">取得</button>
                                    </form>
                                    <?php endif; ?>

                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('gcrev_rank_tracker_action', '_gcrev_rank_tracker_nonce'); ?>
                                        <input type="hidden" name="gcrev_action" value="delete_keyword">
                                        <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                                        <input type="hidden" name="keyword_id" value="<?php echo esc_attr( $kw['id'] ); ?>">
                                        <button type="submit" class="button button-small" style="color:#d63638;" onclick="return confirm('このキーワードと取得済みデータを削除します。よろしいですか？');">削除</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php endif; // selected_user ?>
        </div>
        <?php
    }
}
