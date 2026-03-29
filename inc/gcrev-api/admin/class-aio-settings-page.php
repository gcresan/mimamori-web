<?php
// FILE: inc/gcrev-api/admin/class-aio-settings-page.php
declare(strict_types=1);

if ( ! defined('ABSPATH') ) { exit; }

if ( class_exists('Gcrev_AIO_Settings_Page') ) { return; }

/**
 * Gcrev_AIO_Settings_Page
 *
 * WordPress 管理画面に「みまもりウェブ > AI検索スコア」ページを追加する。
 * ユーザーごとの AIO キーワード管理・会社別名設定・手動計測を管理する。
 *
 * @package Mimamori_Web
 * @since   3.1.0
 */
class Gcrev_AIO_Settings_Page {

    /** メニュースラッグ */
    private const MENU_SLUG = 'gcrev-aio-settings';

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
            'AI検索スコア - みまもりウェブ',
            '🤖 AI検索スコア',
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

        if ( empty($_POST['_gcrev_aio_nonce']) ) {
            return;
        }

        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['_gcrev_aio_nonce'] ) ),
            'gcrev_aio_action'
        ) ) {
            wp_die('不正なリクエストです。');
        }

        $action  = isset($_POST['gcrev_action']) ? sanitize_text_field( wp_unslash( $_POST['gcrev_action'] ) ) : '';
        $user_id = isset($_POST['gcrev_target_user']) ? absint( $_POST['gcrev_target_user'] ) : 0;

        global $wpdb;
        $kw_table = $wpdb->prefix . 'gcrev_rank_keywords';
        $tz       = wp_timezone();
        $now      = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d H:i:s' );

        $redirect_args = [ 'page' => self::MENU_SLUG ];
        if ( $user_id > 0 ) {
            $redirect_args['user_id'] = $user_id;
        }

        switch ( $action ) {

            case 'toggle_aio':
                $kw_id       = absint( $_POST['keyword_id'] ?? 0 );
                $aio_enabled = absint( $_POST['aio_enabled'] ?? 0 );
                if ( $kw_id > 0 ) {
                    $wpdb->update( $kw_table, [
                        'aio_enabled' => $aio_enabled ? 1 : 0,
                        'updated_at'  => $now,
                    ], [ 'id' => $kw_id ], null, [ '%d' ] );
                    $redirect_args['msg'] = 'toggled';
                }
                break;

            case 'toggle_aio_all':
                $aio_enabled = absint( $_POST['aio_enabled'] ?? 0 );
                if ( $user_id > 0 ) {
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE {$kw_table} SET aio_enabled = %d, updated_at = %s WHERE user_id = %d AND enabled = 1",
                        $aio_enabled ? 1 : 0,
                        $now,
                        $user_id
                    ) );
                    $redirect_args['msg'] = $aio_enabled ? 'enabled_all' : 'disabled_all';
                }
                break;

            case 'save_aliases':
                if ( $user_id > 0 ) {
                    $raw = isset($_POST['aliases']) ? sanitize_textarea_field( wp_unslash( $_POST['aliases'] ) ) : '';
                    $lines = array_filter(
                        array_map( 'trim', explode( "\n", $raw ) ),
                        fn( $v ) => $v !== ''
                    );
                    $aliases = array_values( $lines );

                    if ( ! empty( $aliases ) ) {
                        update_user_meta( $user_id, 'gcrev_aio_company_aliases', wp_json_encode( $aliases, JSON_UNESCAPED_UNICODE ) );
                    } else {
                        delete_user_meta( $user_id, 'gcrev_aio_company_aliases' );
                    }
                    $redirect_args['msg'] = 'aliases_saved';
                }
                break;

            case 'run_aio_all':
                if ( $user_id > 0 && class_exists( 'Gcrev_AIO_Service' ) ) {
                    $config  = new Gcrev_Config();
                    $service = new Gcrev_AIO_Service( $config );

                    $lock_key = "gcrev_lock_aio_{$user_id}";
                    if ( get_transient( $lock_key ) ) {
                        $redirect_args['msg'] = 'locked';
                        break;
                    }
                    set_transient( $lock_key, 1, 2 * HOUR_IN_SECONDS );

                    try {
                        $result = $service->run_all_keywords( $user_id );
                        $redirect_args['msg']     = 'run_complete';
                        $redirect_args['checked'] = count( $result );
                    } catch ( \Throwable $e ) {
                        error_log( '[GCREV AIO] Admin run error: ' . $e->getMessage() );
                        $redirect_args['msg'] = 'run_error';
                    } finally {
                        delete_transient( $lock_key );
                    }
                } else {
                    $redirect_args['msg'] = 'not_configured';
                }
                break;

            case 'run_aio_single':
                $kw_id = absint( $_POST['keyword_id'] ?? 0 );
                if ( $kw_id > 0 && class_exists( 'Gcrev_AIO_Service' ) ) {
                    $config  = new Gcrev_Config();
                    $service = new Gcrev_AIO_Service( $config );

                    try {
                        $service->run_aio_check( $kw_id );
                        $redirect_args['msg'] = 'run_single_complete';
                    } catch ( \Throwable $e ) {
                        error_log( '[GCREV AIO] Admin single run error: ' . $e->getMessage() );
                        $redirect_args['msg'] = 'run_error';
                    }
                } else {
                    $redirect_args['msg'] = 'not_configured';
                }
                break;

            // --- Bright Data SERP アクション ---

            case 'save_self_domains':
                if ( $user_id > 0 ) {
                    $raw = isset($_POST['self_domains']) ? sanitize_textarea_field( wp_unslash( $_POST['self_domains'] ) ) : '';
                    update_user_meta( $user_id, 'gcrev_aio_self_domains', $raw );
                    $redirect_args['msg'] = 'self_domains_saved';
                }
                break;

            case 'save_serp_settings':
                if ( $user_id > 0 ) {
                    $region   = sanitize_text_field( $_POST['serp_region'] ?? 'jp' );
                    $language = sanitize_text_field( $_POST['serp_language'] ?? 'ja' );
                    $device   = sanitize_text_field( $_POST['serp_device'] ?? 'desktop' );
                    update_user_meta( $user_id, 'gcrev_aio_serp_region', $region );
                    update_user_meta( $user_id, 'gcrev_aio_serp_language', $language );
                    update_user_meta( $user_id, 'gcrev_aio_serp_device', $device );
                    $redirect_args['msg'] = 'serp_settings_saved';
                }
                break;

            case 'run_serp_all':
                if ( $user_id > 0 && class_exists( 'Gcrev_AIO_Serp_Service' ) ) {
                    $config  = new Gcrev_Config();
                    $service = new Gcrev_AIO_Serp_Service( $config );

                    $lock_key = "gcrev_lock_aio_serp_{$user_id}";
                    if ( get_transient( $lock_key ) ) {
                        $redirect_args['msg'] = 'locked';
                        break;
                    }
                    set_transient( $lock_key, 1, 2 * HOUR_IN_SECONDS );

                    try {
                        $result = $service->fetch_all_keywords( $user_id );
                        $redirect_args['msg']     = 'serp_run_complete';
                        $redirect_args['checked'] = $result['processed'] ?? 0;
                    } catch ( \Throwable $e ) {
                        file_put_contents( '/tmp/gcrev_aio_serp_debug.log',
                            date('Y-m-d H:i:s') . " Admin run error: " . $e->getMessage() . "\n", FILE_APPEND );
                        $redirect_args['msg'] = 'run_error';
                    } finally {
                        delete_transient( $lock_key );
                    }
                } else {
                    $redirect_args['msg'] = 'serp_not_configured';
                }
                break;

            case 'run_serp_single':
                $kw_id = absint( $_POST['keyword_id'] ?? 0 );
                if ( $kw_id > 0 && $user_id > 0 && class_exists( 'Gcrev_AIO_Serp_Service' ) ) {
                    $config  = new Gcrev_Config();
                    $service = new Gcrev_AIO_Serp_Service( $config );

                    try {
                        $result = $service->fetch_and_store( $user_id, $kw_id );
                        $redirect_args['msg'] = 'serp_single_complete';
                    } catch ( \Throwable $e ) {
                        file_put_contents( '/tmp/gcrev_aio_serp_debug.log',
                            date('Y-m-d H:i:s') . " Admin single run error: " . $e->getMessage() . "\n", FILE_APPEND );
                        $redirect_args['msg'] = 'run_error';
                    }
                } else {
                    $redirect_args['msg'] = 'serp_not_configured';
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
        $aio_table = $wpdb->prefix . 'gcrev_aio_results';

        // メッセージ
        $msg = sanitize_text_field( $_GET['msg'] ?? '' );
        $messages = [
            'toggled'             => '✅ AIO 有効/無効を切り替えました。',
            'enabled_all'         => '✅ 全キーワードの AIO を有効にしました。',
            'disabled_all'        => '✅ 全キーワードの AIO を無効にしました。',
            'aliases_saved'       => '✅ 会社別名を保存しました。',
            'run_complete'        => '✅ AIO 計測完了（' . absint( $_GET['checked'] ?? 0 ) . ' キーワード）',
            'run_single_complete' => '✅ 個別 AIO 計測完了。',
            'run_error'           => '❌ 計測中にエラーが発生しました。ログを確認してください。',
            'locked'              => '⚠️ 計測が進行中です。しばらくお待ちください。',
            'not_configured'      => '⚠️ AIO サービスが利用できません。API 設定を確認してください。',
            'self_domains_saved'  => '✅ 自社判定ドメインを保存しました。',
            'serp_settings_saved' => '✅ SERP 取得設定を保存しました。',
            'serp_run_complete'   => '✅ Bright Data SERP 取得完了（' . absint( $_GET['checked'] ?? 0 ) . ' キーワード）',
            'serp_single_complete'=> '✅ Bright Data SERP 個別取得完了。',
            'serp_not_configured' => '⚠️ Bright Data が設定されていません。BRIGHTDATA_API_TOKEN / BRIGHTDATA_ZONE を確認してください。',
        ];

        // プロバイダー接続状態
        $has_openai    = defined('MIMAMORI_OPENAI_API_KEY') && MIMAMORI_OPENAI_API_KEY !== '';
        $has_gemini    = defined('GCREV_SA_PATH') && file_exists( GCREV_SA_PATH );
        $has_dataforseo = class_exists('Gcrev_DataForSEO_Client') && Gcrev_DataForSEO_Client::is_configured();
        $has_brightdata = class_exists('Gcrev_Brightdata_Serp_Client') && Gcrev_Brightdata_Serp_Client::is_configured();

        // ユーザー一覧（管理者含む全ユーザー）
        $users = get_users([
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ]);

        // 選択中ユーザー
        $selected_user = absint( $_GET['user_id'] ?? 0 );

        // キーワード一覧
        $keywords = [];
        $company_name = '';
        $aliases_raw  = '';
        if ( $selected_user > 0 ) {
            $keywords = $wpdb->get_results( $wpdb->prepare(
                "SELECT k.*,
                        (SELECT COUNT(*) FROM {$aio_table} a WHERE a.keyword_id = k.id) as aio_result_count,
                        (SELECT MAX(a.fetched_at) FROM {$aio_table} a WHERE a.keyword_id = k.id) as aio_last_fetched
                 FROM {$kw_table} k
                 WHERE k.user_id = %d AND k.enabled = 1
                 ORDER BY k.sort_order ASC, k.id ASC",
                $selected_user
            ), ARRAY_A );

            // 会社名取得
            $company_name = get_user_meta( $selected_user, 'report_company_name', true );
            if ( empty( $company_name ) ) {
                $user_obj = get_userdata( $selected_user );
                $company_name = $user_obj ? gcrev_get_business_name( $user_obj->ID ) : '';
            }

            // 別名
            $aliases_json = get_user_meta( $selected_user, 'gcrev_aio_company_aliases', true );
            if ( ! empty( $aliases_json ) ) {
                $aliases_arr = json_decode( $aliases_json, true );
                if ( is_array( $aliases_arr ) ) {
                    $aliases_raw = implode( "\n", $aliases_arr );
                }
            }
        }

        ?>
        <div class="wrap">
            <h1>🤖 AI検索スコア</h1>
            <p style="color:#666;">ChatGPT / Gemini / Google AI Mode でクライアント企業がどの程度「おすすめ」として表示されるかを計測します。</p>

            <?php if ( isset( $messages[ $msg ] ) ) : ?>
                <div class="notice <?php echo strpos( $msg, 'error' ) !== false || strpos( $msg, 'fail' ) !== false || $msg === 'not_configured' ? 'notice-error' : ( $msg === 'locked' ? 'notice-warning' : 'notice-success' ); ?> is-dismissible">
                    <p><?php echo $messages[ $msg ]; ?></p>
                </div>
            <?php endif; ?>

            <!-- プロバイダー接続状態 -->
            <div class="card" style="max-width:700px; margin-bottom:20px;">
                <h2>プロバイダー接続状態</h2>
                <table class="widefat" style="max-width:500px;">
                    <tbody>
                        <tr>
                            <td><strong>OpenAI (ChatGPT)</strong></td>
                            <td>
                                <?php if ( $has_openai ) : ?>
                                    <span style="color:#0a7b0a; font-weight:600;">✅ 設定済み</span>
                                <?php else : ?>
                                    <span style="color:#d63638; font-weight:600;">❌ 未設定</span>
                                    <span style="color:#666; margin-left:4px; font-size:12px;">MIMAMORI_OPENAI_API_KEY</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Gemini (Vertex AI)</strong></td>
                            <td>
                                <?php if ( $has_gemini ) : ?>
                                    <span style="color:#0a7b0a; font-weight:600;">✅ 設定済み</span>
                                <?php else : ?>
                                    <span style="color:#d63638; font-weight:600;">❌ 未設定</span>
                                    <span style="color:#666; margin-left:4px; font-size:12px;">GCREV_SA_PATH</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>DataForSEO (Google AI)</strong></td>
                            <td>
                                <?php if ( $has_dataforseo ) : ?>
                                    <span style="color:#0a7b0a; font-weight:600;">✅ 設定済み</span>
                                <?php else : ?>
                                    <span style="color:#d63638; font-weight:600;">❌ 未設定</span>
                                    <span style="color:#666; margin-left:4px; font-size:12px;">DATAFORSEO_LOGIN / PASSWORD</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Bright Data (SERP API)</strong></td>
                            <td>
                                <?php if ( $has_brightdata ) : ?>
                                    <span style="color:#0a7b0a; font-weight:600;">✅ 設定済み</span>
                                <?php else : ?>
                                    <span style="color:#d63638; font-weight:600;">❌ 未設定</span>
                                    <span style="color:#666; margin-left:4px; font-size:12px;">BRIGHTDATA_API_TOKEN / BRIGHTDATA_ZONE</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
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
                                <?php echo esc_html( gcrev_get_business_name( $u->ID ) ); ?> (ID: <?php echo esc_html( $u->ID ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if ( $selected_user > 0 ) : ?>

            <!-- 会社名・別名設定 -->
            <div class="card" style="max-width:700px; margin-bottom:20px;">
                <h2>会社名・別名設定</h2>
                <table class="form-table">
                    <tr>
                        <th>マッチング対象の会社名</th>
                        <td>
                            <strong><?php echo esc_html( $company_name ); ?></strong>
                            <p class="description">report_company_name または display_name から自動取得</p>
                        </td>
                    </tr>
                </table>
                <form method="post">
                    <?php wp_nonce_field('gcrev_aio_action', '_gcrev_aio_nonce'); ?>
                    <input type="hidden" name="gcrev_action" value="save_aliases">
                    <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                    <table class="form-table">
                        <tr>
                            <th><label for="aliases">別名リスト</label></th>
                            <td>
                                <textarea id="aliases" name="aliases" rows="4" class="large-text" style="max-width:400px;" placeholder="例:&#10;株式会社ジィクレブ&#10;GCREV&#10;gcrev.co.jp"><?php echo esc_textarea( $aliases_raw ); ?></textarea>
                                <p class="description">1行に1つずつ。AI回答内でこれらの名前が見つかった場合も自社として認識します。<br>ドメイン名（gcrev_client_site_url から自動取得）も自動的にマッチ対象になります。</p>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary">別名を保存</button></p>
                </form>
            </div>

            <!-- Bright Data SERP 設定 -->
            <div class="card" style="max-width:700px; margin-bottom:20px;">
                <h2>🌐 Bright Data SERP 設定</h2>

                <?php
                $self_domains_raw = get_user_meta( $selected_user, 'gcrev_aio_self_domains', true ) ?: '';
                $serp_region      = get_user_meta( $selected_user, 'gcrev_aio_serp_region', true ) ?: 'jp';
                $serp_language    = get_user_meta( $selected_user, 'gcrev_aio_serp_language', true ) ?: 'ja';
                $serp_device      = get_user_meta( $selected_user, 'gcrev_aio_serp_device', true ) ?: 'desktop';
                ?>

                <!-- 自社判定ドメイン -->
                <form method="post">
                    <?php wp_nonce_field('gcrev_aio_action', '_gcrev_aio_nonce'); ?>
                    <input type="hidden" name="gcrev_action" value="save_self_domains">
                    <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                    <table class="form-table">
                        <tr>
                            <th><label for="self_domains">自社判定ドメイン</label></th>
                            <td>
                                <textarea id="self_domains" name="self_domains" rows="4" class="large-text" style="max-width:400px;" placeholder="例:&#10;g-crev.jp&#10;mimamori-web.jp"><?php echo esc_textarea( $self_domains_raw ); ?></textarea>
                                <p class="description">1行に1つずつ。AIO 引用元にこのドメインが含まれる場合「自社」として集計します。<br>www. / m. は自動で除去されます。</p>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary">自社ドメインを保存</button></p>
                </form>

                <hr style="margin:16px 0;">

                <!-- 取得設定 -->
                <form method="post">
                    <?php wp_nonce_field('gcrev_aio_action', '_gcrev_aio_nonce'); ?>
                    <input type="hidden" name="gcrev_action" value="save_serp_settings">
                    <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                    <table class="form-table">
                        <tr>
                            <th><label for="serp_region">地域</label></th>
                            <td>
                                <select id="serp_region" name="serp_region">
                                    <option value="jp" <?php selected( $serp_region, 'jp' ); ?>>日本 (jp)</option>
                                    <option value="us" <?php selected( $serp_region, 'us' ); ?>>アメリカ (us)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="serp_language">言語</label></th>
                            <td>
                                <select id="serp_language" name="serp_language">
                                    <option value="ja" <?php selected( $serp_language, 'ja' ); ?>>日本語 (ja)</option>
                                    <option value="en" <?php selected( $serp_language, 'en' ); ?>>英語 (en)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="serp_device">デバイス</label></th>
                            <td>
                                <select id="serp_device" name="serp_device">
                                    <option value="desktop" <?php selected( $serp_device, 'desktop' ); ?>>デスクトップ</option>
                                    <option value="mobile" <?php selected( $serp_device, 'mobile' ); ?>>モバイル</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary">取得設定を保存</button></p>
                </form>
            </div>

            <!-- キーワード AIO 管理テーブル -->
            <div class="card" style="max-width:1000px; margin-bottom:20px;">
                <h2>
                    AIO キーワード管理
                    <form method="post" style="display:inline; margin-left:16px;">
                        <?php wp_nonce_field('gcrev_aio_action', '_gcrev_aio_nonce'); ?>
                        <input type="hidden" name="gcrev_action" value="toggle_aio_all">
                        <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                        <input type="hidden" name="aio_enabled" value="1">
                        <button type="submit" class="button button-small">全て有効</button>
                    </form>
                    <form method="post" style="display:inline; margin-left:4px;">
                        <?php wp_nonce_field('gcrev_aio_action', '_gcrev_aio_nonce'); ?>
                        <input type="hidden" name="gcrev_action" value="toggle_aio_all">
                        <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                        <input type="hidden" name="aio_enabled" value="0">
                        <button type="submit" class="button button-small">全て無効</button>
                    </form>
                </h2>

                <?php if ( empty( $keywords ) ) : ?>
                    <p style="color:#666;">有効なキーワードがありません。先に「順位トラッキング」でキーワードを登録してください。</p>
                <?php else : ?>
                    <table class="widefat striped" style="margin-top:12px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>キーワード</th>
                                <th>AIO</th>
                                <th>計測結果数</th>
                                <th>最終計測</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $keywords as $kw ) : ?>
                            <tr<?php echo ! (int) ( $kw['aio_enabled'] ?? 0 ) ? ' style="opacity:0.5;"' : ''; ?>>
                                <td><?php echo esc_html( $kw['id'] ); ?></td>
                                <td><strong><?php echo esc_html( $kw['keyword'] ); ?></strong></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('gcrev_aio_action', '_gcrev_aio_nonce'); ?>
                                        <input type="hidden" name="gcrev_action" value="toggle_aio">
                                        <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                                        <input type="hidden" name="keyword_id" value="<?php echo esc_attr( $kw['id'] ); ?>">
                                        <input type="hidden" name="aio_enabled" value="<?php echo (int) ( $kw['aio_enabled'] ?? 0 ) ? '0' : '1'; ?>">
                                        <button type="submit" class="button-link" title="<?php echo (int) ( $kw['aio_enabled'] ?? 0 ) ? '無効にする' : '有効にする'; ?>">
                                            <?php echo (int) ( $kw['aio_enabled'] ?? 0 ) ? '✅' : '⬜'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td><?php echo esc_html( $kw['aio_result_count'] ?? 0 ); ?> 件</td>
                                <td><?php echo ! empty( $kw['aio_last_fetched'] ) ? esc_html( substr( $kw['aio_last_fetched'], 0, 16 ) ) : '<span style="color:#999;">未計測</span>'; ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('gcrev_aio_action', '_gcrev_aio_nonce'); ?>
                                        <input type="hidden" name="gcrev_action" value="run_aio_single">
                                        <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                                        <input type="hidden" name="keyword_id" value="<?php echo esc_attr( $kw['id'] ); ?>">
                                        <button type="submit" class="button button-small" onclick="return confirm('このキーワードの AIO 計測を実行します。API 使用量が発生します。');">個別計測</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- 手動一括計測 -->
            <?php
            $aio_enabled_count = 0;
            foreach ( $keywords as $kw ) {
                if ( (int) ( $kw['aio_enabled'] ?? 0 ) ) {
                    $aio_enabled_count++;
                }
            }
            ?>
            <?php if ( $aio_enabled_count > 0 ) : ?>
            <div class="card" style="max-width:700px; margin-bottom:20px;">
                <h2>手動一括計測</h2>
                <p>AIO 有効キーワード <strong><?php echo esc_html( $aio_enabled_count ); ?></strong> 件を一括計測します。</p>
                <p style="color:#92400E; font-size:13px;">
                    ⚠️ キーワードあたり ChatGPT 20回 + Gemini 20回 + DataForSEO 1回の API コールが発生します。<br>
                    計測には数分かかる場合があります。
                </p>
                <form method="post">
                    <?php wp_nonce_field('gcrev_aio_action', '_gcrev_aio_nonce'); ?>
                    <input type="hidden" name="gcrev_action" value="run_aio_all">
                    <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                    <button type="submit" class="button button-primary button-hero" onclick="return confirm('全キーワードの AIO 計測を実行します。API 使用量が発生します。よろしいですか？');">🚀 全キーワード計測</button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Bright Data SERP 一括取得 -->
            <?php if ( $aio_enabled_count > 0 && $has_brightdata ) : ?>
            <div class="card" style="max-width:700px; margin-bottom:20px;">
                <h2>🌐 Bright Data SERP 一括取得</h2>
                <p>AIO 有効キーワード <strong><?php echo esc_html( $aio_enabled_count ); ?></strong> 件の Google 検索結果（AI Overview）を Bright Data で取得します。</p>
                <p style="color:#92400E; font-size:13px;">
                    ⚠️ キーワードあたり 1回の SERP API コールが発生します。<br>
                    取得には 1キーワードあたり約 10〜15 秒かかります。
                </p>
                <form method="post">
                    <?php wp_nonce_field('gcrev_aio_action', '_gcrev_aio_nonce'); ?>
                    <input type="hidden" name="gcrev_action" value="run_serp_all">
                    <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                    <button type="submit" class="button button-primary button-hero" onclick="return confirm('全キーワードの SERP 取得を実行します。よろしいですか？');">🌐 SERP 一括取得</button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Bright Data SERP 取得結果 -->
            <?php
            $serp_table = $wpdb->prefix . 'gcrev_aio_serp_results';
            $serp_results = [];
            if ( $selected_user > 0 ) {
                $serp_results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT s.keyword_id, s.keyword, s.status, s.self_found, s.self_count, s.self_exposure, s.fetched_at,
                            (SELECT COUNT(*) FROM {$serp_table} s2 WHERE s2.keyword_id = s.keyword_id AND s2.user_id = s.user_id) as result_count
                     FROM {$serp_table} s
                     INNER JOIN (
                         SELECT keyword_id, MAX(fetched_at) AS max_fetched
                         FROM {$serp_table}
                         WHERE user_id = %d
                         GROUP BY keyword_id
                     ) latest ON s.keyword_id = latest.keyword_id AND s.fetched_at = latest.max_fetched
                     WHERE s.user_id = %d
                     ORDER BY s.keyword ASC",
                    $selected_user, $selected_user
                ), ARRAY_A );
            }
            ?>
            <?php if ( ! empty( $serp_results ) ) : ?>
            <div class="card" style="max-width:1000px; margin-bottom:20px;">
                <h2>🌐 Bright Data SERP 取得結果</h2>
                <table class="widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th>キーワード</th>
                            <th>ステータス</th>
                            <th>自社検出</th>
                            <th>自社露出点</th>
                            <th>取得回数</th>
                            <th>最終取得</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $serp_results as $sr ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $sr['keyword'] ); ?></strong></td>
                            <td>
                                <?php
                                $status_labels = [
                                    'success' => '<span style="color:#0a7b0a;">✅ AIOあり</span>',
                                    'no_aio'  => '<span style="color:#2563eb;">ℹ️ AIOなし</span>',
                                    'failed'  => '<span style="color:#d63638;">❌ 失敗</span>',
                                ];
                                echo $status_labels[ $sr['status'] ] ?? esc_html( $sr['status'] );
                                ?>
                            </td>
                            <td><?php echo (int) $sr['self_found'] ? '<span style="color:#0a7b0a; font-weight:600;">✅ あり</span>' : '<span style="color:#999;">なし</span>'; ?></td>
                            <td><?php echo esc_html( $sr['self_exposure'] ); ?> 点</td>
                            <td><?php echo esc_html( $sr['result_count'] ); ?> 件</td>
                            <td><?php echo esc_html( substr( $sr['fetched_at'], 0, 16 ) ); ?></td>
                            <td>
                                <?php if ( $has_brightdata ) : ?>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('gcrev_aio_action', '_gcrev_aio_nonce'); ?>
                                    <input type="hidden" name="gcrev_action" value="run_serp_single">
                                    <input type="hidden" name="gcrev_target_user" value="<?php echo esc_attr( $selected_user ); ?>">
                                    <input type="hidden" name="keyword_id" value="<?php echo esc_attr( $sr['keyword_id'] ); ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('SERP を再取得します。');">再取得</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php endif; // selected_user ?>
        </div>
        <?php
    }
}
