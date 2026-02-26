<?php
// FILE: inc/gcrev-api/admin/class-deploy-page.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Deploy_Page' ) ) { return; }

/**
 * Gcrev_Deploy_Page
 *
 * Dev 環境の WordPress 管理画面に「みまもりウェブ > デプロイ」ページを追加する。
 * ワンクリックで Dev テーマを本番にデプロイ、またはスナップショットからロールバックする。
 *
 * 固定シェルスクリプト（deploy.sh / rollback.sh / snapshot.sh）を sudo 経由で実行する。
 * PHP からの任意コマンド実行は禁止。
 *
 * 必要な wp-config.php 定数:
 *   MIMAMORI_ENV          = 'development'
 *   MIMAMORI_PROD_THEME_PATH  = '/home/kusanagi/mimamori/DocumentRoot/wp-content/themes/mimamori'
 *   MIMAMORI_SNAPSHOT_DIR     = '/home/kusanagi/mimamori/snapshots'
 *   MIMAMORI_SCRIPTS_DIR      = '/home/kusanagi/mimamori/scripts'
 *
 * @package GCREV_INSIGHT
 * @since   3.1.0
 */
class Gcrev_Deploy_Page {

    /** メニュースラッグ */
    private const MENU_SLUG = 'gcrev-deploy';

    /** Nonce アクション名 */
    private const NONCE_ACTION = 'gcrev_deploy_nonce';

    /** 許可するスクリプトのホワイトリスト */
    private const ALLOWED_SCRIPTS = [ 'deploy.sh', 'rollback.sh', 'snapshot.sh' ];

    /** スナップショットファイル名の正規表現 */
    private const SNAPSHOT_PATTERN = '/^[\w\-]+\.zip$/';

    /** デプロイログ表示行数 */
    private const LOG_DISPLAY_LINES = 30;

    /** 直近のアクション結果（admin_notices 表示用） */
    private string $action_result = '';
    private string $action_type   = '';

    /**
     * フック登録
     */
    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
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
            'デプロイ - みまもりウェブ',
            "\xF0\x9F\x9A\x80 デプロイ",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================
    // POST アクション処理
    // =========================================================

    public function handle_actions(): void {
        if ( ! isset( $_POST['gcrev_deploy_action'] ) ) {
            return;
        }

        // Nonce 検証
        check_admin_referer( self::NONCE_ACTION );

        // 権限チェック
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '権限がありません。' );
        }

        $action = sanitize_text_field( wp_unslash( $_POST['gcrev_deploy_action'] ) );

        // スクリプトディレクトリチェック
        $scripts_dir = defined( 'MIMAMORI_SCRIPTS_DIR' ) ? MIMAMORI_SCRIPTS_DIR : '';
        if ( empty( $scripts_dir ) || ! is_dir( $scripts_dir ) ) {
            add_settings_error(
                'gcrev_deploy',
                'no_scripts',
                'MIMAMORI_SCRIPTS_DIR が未設定、またはディレクトリが存在しません。',
                'error'
            );
            return;
        }

        switch ( $action ) {
            case 'deploy':
                $this->action_deploy();
                break;

            case 'rollback':
                $snapshot = isset( $_POST['snapshot_file'] )
                    ? sanitize_file_name( wp_unslash( $_POST['snapshot_file'] ) )
                    : '';
                $this->action_rollback( $snapshot );
                break;

            case 'snapshot':
                $this->action_snapshot();
                break;

            default:
                add_settings_error(
                    'gcrev_deploy',
                    'unknown_action',
                    '不明なアクションです: ' . esc_html( $action ),
                    'error'
                );
                break;
        }
    }

    /**
     * デプロイ実行
     */
    private function action_deploy(): void {
        $output  = $this->run_script( 'deploy.sh' );
        $success = ( strpos( $output, 'OK' ) !== false );

        add_settings_error(
            'gcrev_deploy',
            'deploy_result',
            $success
                ? 'デプロイが完了しました。'
                : 'デプロイに失敗しました: ' . esc_html( mb_substr( $output, 0, 500 ) ),
            $success ? 'success' : 'error'
        );

        error_log( '[GCREV Deploy] deploy: ' . ( $success ? 'OK' : 'FAIL' ) . ' output=' . mb_substr( $output, 0, 300 ) );
    }

    /**
     * ロールバック実行
     */
    private function action_rollback( string $snapshot ): void {
        // ファイル名バリデーション
        if ( empty( $snapshot ) || ! preg_match( self::SNAPSHOT_PATTERN, $snapshot ) ) {
            add_settings_error(
                'gcrev_deploy',
                'bad_file',
                '無効なスナップショットファイル名です。',
                'error'
            );
            return;
        }

        $output  = $this->run_script( 'rollback.sh', [ 'theme', $snapshot ] );
        $success = ( strpos( $output, 'OK' ) !== false );

        add_settings_error(
            'gcrev_deploy',
            'rollback_result',
            $success
                ? 'ロールバックが完了しました（' . esc_html( $snapshot ) . '）'
                : 'ロールバックに失敗しました: ' . esc_html( mb_substr( $output, 0, 500 ) ),
            $success ? 'success' : 'error'
        );

        error_log( '[GCREV Deploy] rollback: ' . ( $success ? 'OK' : 'FAIL' ) . ' snapshot=' . $snapshot );
    }

    /**
     * KUSANAGI スナップショット作成
     */
    private function action_snapshot(): void {
        $output  = $this->run_script( 'snapshot.sh' );
        $success = ( strpos( $output, 'OK' ) !== false );

        add_settings_error(
            'gcrev_deploy',
            'snapshot_result',
            $success
                ? 'KUSANAGI スナップショットを作成しました。'
                : 'スナップショット作成に失敗しました: ' . esc_html( mb_substr( $output, 0, 500 ) ),
            $success ? 'success' : 'error'
        );

        error_log( '[GCREV Deploy] snapshot: ' . ( $success ? 'OK' : 'FAIL' ) . ' output=' . mb_substr( $output, 0, 300 ) );
    }

    // =========================================================
    // スクリプト実行（固定スクリプトのみ）
    // =========================================================

    /**
     * 固定シェルスクリプトを sudo -u kusanagi 経由で実行する。
     *
     * - ホワイトリスト検証
     * - パストラバーサル防止（realpath + 前方一致）
     * - 引数は escapeshellarg() でサニタイズ
     *
     * @param string $script  スクリプトファイル名（deploy.sh 等）
     * @param array  $args    コマンド引数（ホワイトリスト済みの値のみ）
     * @return string スクリプト出力
     */
    private function run_script( string $script, array $args = [] ): string {
        // ホワイトリスト検証
        if ( ! in_array( $script, self::ALLOWED_SCRIPTS, true ) ) {
            return 'ERROR: script not in whitelist';
        }

        $scripts_dir = rtrim( MIMAMORI_SCRIPTS_DIR, '/' );
        $script_path = realpath( $scripts_dir . '/' . $script );

        // パストラバーサル防止
        if ( ! $script_path || strpos( $script_path, $scripts_dir ) !== 0 ) {
            return 'ERROR: invalid script path';
        }

        // 実行可能チェック
        if ( ! is_file( $script_path ) ) {
            return 'ERROR: script file not found';
        }

        // コマンド組み立て
        $cmd = sprintf( 'sudo -u kusanagi %s', escapeshellarg( $script_path ) );
        foreach ( $args as $arg ) {
            $cmd .= ' ' . escapeshellarg( (string) $arg );
        }

        // 実行（標準出力 + 標準エラー）
        $output = shell_exec( $cmd . ' 2>&1' );

        return trim( (string) $output );
    }

    // =========================================================
    // ページ描画
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1 style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:28px;">🚀</span> デプロイ管理
            </h1>

            <?php settings_errors( 'gcrev_deploy' ); ?>

            <?php $this->render_env_status(); ?>

            <hr style="margin:24px 0;" />

            <?php $this->render_deploy_section(); ?>

            <hr style="margin:24px 0;" />

            <?php $this->render_rollback_section(); ?>

            <hr style="margin:24px 0;" />

            <?php $this->render_deploy_log(); ?>
        </div>
        <?php
    }

    // =========================================================
    // セクション 1: 環境ステータス
    // =========================================================

    private function render_env_status(): void {
        $env = defined( 'MIMAMORI_ENV' ) ? MIMAMORI_ENV : '未設定';
        $prod_theme_path = defined( 'MIMAMORI_PROD_THEME_PATH' ) ? MIMAMORI_PROD_THEME_PATH : '';
        $snapshot_dir    = defined( 'MIMAMORI_SNAPSHOT_DIR' ) ? MIMAMORI_SNAPSHOT_DIR : '';
        $scripts_dir     = defined( 'MIMAMORI_SCRIPTS_DIR' ) ? MIMAMORI_SCRIPTS_DIR : '';

        $dev_theme_path  = get_template_directory();
        $theme_data      = wp_get_theme();
        $theme_version   = $theme_data->get( 'Version' ) ?: '不明';

        // Git 情報（Dev テーマ）
        $git_branch = '不明';
        $git_commit = '不明';
        $git_dir    = $dev_theme_path . '/.git';
        if ( is_dir( $git_dir ) ) {
            $head_file = $git_dir . '/HEAD';
            if ( is_readable( $head_file ) ) {
                $head_content = trim( (string) file_get_contents( $head_file ) );
                if ( strpos( $head_content, 'ref: refs/heads/' ) === 0 ) {
                    $git_branch = substr( $head_content, strlen( 'ref: refs/heads/' ) );
                }
            }
            // 最新コミットハッシュ
            $ref_path = $git_dir . '/refs/heads/' . $git_branch;
            if ( is_readable( $ref_path ) ) {
                $git_commit = substr( trim( (string) file_get_contents( $ref_path ) ), 0, 8 );
            }
        }

        // スナップショット数
        $snapshot_count = 0;
        $theme_snap_dir = rtrim( $snapshot_dir, '/' ) . '/theme';
        if ( is_dir( $theme_snap_dir ) ) {
            $files = glob( $theme_snap_dir . '/*.zip' );
            $snapshot_count = $files ? count( $files ) : 0;
        }

        // 最終デプロイ
        $last_deploy = $this->get_last_log_line();

        ?>
        <h2>環境ステータス</h2>
        <table class="widefat striped" style="max-width:720px;">
            <tbody>
                <tr>
                    <th style="width:200px;">環境</th>
                    <td>
                        <?php if ( $env === 'development' ): ?>
                            <span style="background:#3b82f6; color:#fff; padding:2px 10px; border-radius:4px; font-weight:600;">Development</span>
                        <?php elseif ( $env === 'production' ): ?>
                            <span style="background:#ef4444; color:#fff; padding:2px 10px; border-radius:4px; font-weight:600;">Production</span>
                        <?php else: ?>
                            <span style="color:#999;"><?php echo esc_html( $env ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>テーマバージョン</th>
                    <td><?php echo esc_html( $theme_version ); ?></td>
                </tr>
                <tr>
                    <th>Dev テーマ（Git）</th>
                    <td>
                        <code style="font-size:12px;"><?php echo esc_html( $git_branch ); ?></code>
                        @ <code style="font-size:12px;"><?php echo esc_html( $git_commit ); ?></code>
                    </td>
                </tr>
                <tr>
                    <th>Prod テーマパス</th>
                    <td>
                        <?php if ( ! empty( $prod_theme_path ) && is_dir( $prod_theme_path ) ): ?>
                            <span style="color:#059669;">✅ 存在</span>
                            <code style="font-size:11px; margin-left:8px;"><?php echo esc_html( $prod_theme_path ); ?></code>
                        <?php elseif ( ! empty( $prod_theme_path ) ): ?>
                            <span style="color:#dc3545;">❌ 未検出</span>
                            <code style="font-size:11px; margin-left:8px;"><?php echo esc_html( $prod_theme_path ); ?></code>
                        <?php else: ?>
                            <span style="color:#999;">MIMAMORI_PROD_THEME_PATH 未設定</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>スクリプトディレクトリ</th>
                    <td>
                        <?php if ( ! empty( $scripts_dir ) && is_dir( $scripts_dir ) ): ?>
                            <span style="color:#059669;">✅ 存在</span>
                        <?php elseif ( ! empty( $scripts_dir ) ): ?>
                            <span style="color:#dc3545;">❌ 未検出</span>
                        <?php else: ?>
                            <span style="color:#999;">MIMAMORI_SCRIPTS_DIR 未設定</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>テーマスナップショット数</th>
                    <td><?php echo esc_html( (string) $snapshot_count ); ?> 件</td>
                </tr>
                <tr>
                    <th>最終デプロイログ</th>
                    <td>
                        <?php if ( $last_deploy ): ?>
                            <code style="font-size:12px;"><?php echo esc_html( $last_deploy ); ?></code>
                        <?php else: ?>
                            <span style="color:#999;">記録なし</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    // =========================================================
    // セクション 2: デプロイ
    // =========================================================

    private function render_deploy_section(): void {
        $scripts_ok = defined( 'MIMAMORI_SCRIPTS_DIR' ) && is_dir( MIMAMORI_SCRIPTS_DIR );
        $prod_ok    = defined( 'MIMAMORI_PROD_THEME_PATH' ) && is_dir( MIMAMORI_PROD_THEME_PATH );
        $can_deploy = $scripts_ok && $prod_ok;

        ?>
        <h2>Dev → 本番にデプロイ</h2>

        <?php if ( ! $can_deploy ): ?>
            <div style="background:#fef3cd; border:1px solid #ffc107; border-radius:8px; padding:16px; max-width:720px;">
                <p style="margin:0; color:#856404;">
                    ⚠️ デプロイに必要な設定が不足しています。<br>
                    wp-config.php に <code>MIMAMORI_SCRIPTS_DIR</code> と <code>MIMAMORI_PROD_THEME_PATH</code> を設定し、
                    対応するディレクトリとスクリプトを配置してください。
                </p>
            </div>
        <?php else: ?>
            <div style="max-width:720px;">
                <!-- KUSANAGI Snapshot -->
                <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:16px 20px; margin-bottom:16px;">
                    <h3 style="margin:0 0 8px; font-size:15px; color:#0369a1;">📸 KUSANAGI Snapshot（推奨: デプロイ前に作成）</h3>
                    <p style="margin:0 0 12px; color:#64748b; font-size:13px;">
                        DB + ファイル全体のスナップショットを作成します。重大な問題が発生した場合、SSH から完全復元できます。
                    </p>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        <input type="hidden" name="gcrev_deploy_action" value="snapshot" />
                        <button type="submit" class="button button-secondary"
                                onclick="return confirm('KUSANAGI Snapshot を作成しますか？');">
                            📸 KUSANAGI Snapshot 作成
                        </button>
                    </form>
                </div>

                <!-- Deploy -->
                <div style="background:#fef2f2; border:1px solid #fca5a5; border-radius:8px; padding:16px 20px;">
                    <h3 style="margin:0 0 8px; font-size:15px; color:#dc2626;">🚀 Dev → 本番デプロイ</h3>
                    <p style="margin:0 0 4px; color:#64748b; font-size:13px;">
                        現在の Dev テーマを本番にデプロイします。実行前にテーマ ZIP スナップショットが自動作成されます。
                    </p>
                    <p style="margin:0 0 12px; color:#ef4444; font-size:13px; font-weight:600;">
                        ※ 本番サイトに即時反映されます。事前に KUSANAGI Snapshot の作成を推奨します。
                    </p>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field( self::NONCE_ACTION ); ?>
                        <input type="hidden" name="gcrev_deploy_action" value="deploy" />
                        <button type="submit" class="button button-primary button-hero"
                                style="background:#dc2626; border-color:#b91c1c;"
                                onclick="return confirm('本当にデプロイしますか？\n\nDev テーマの内容が本番サイトに反映されます。');">
                            🚀 本番にデプロイ
                        </button>
                    </form>
                </div>
            </div>
        <?php endif;
    }

    // =========================================================
    // セクション 3: ロールバック
    // =========================================================

    private function render_rollback_section(): void {
        ?>
        <h2>ロールバック</h2>

        <div style="max-width:720px;">
            <!-- タブ -->
            <div id="rollback-tabs" style="display:flex; gap:0; margin-bottom:0;">
                <button type="button" class="gcrev-tab active"
                        onclick="switchRollbackTab('kusanagi')"
                        id="tab-kusanagi"
                        style="padding:8px 20px; border:1px solid #ccc; border-bottom:none; border-radius:6px 6px 0 0; background:#fff; cursor:pointer; font-weight:600;">
                    KUSANAGI Snapshot（推奨）
                </button>
                <button type="button" class="gcrev-tab"
                        onclick="switchRollbackTab('theme')"
                        id="tab-theme"
                        style="padding:8px 20px; border:1px solid #ccc; border-bottom:none; border-radius:6px 6px 0 0; background:#f1f5f9; cursor:pointer; margin-left:-1px;">
                    テーマ ZIP（補助）
                </button>
            </div>

            <!-- KUSANAGI Snapshot タブ -->
            <div id="panel-kusanagi" style="border:1px solid #ccc; border-radius:0 6px 6px 6px; padding:20px; background:#fff;">
                <p style="margin:0 0 12px; color:#334155;">
                    KUSANAGI Snapshot は DB + ファイル全体を含む完全なロールバック手段です。<br>
                    影響範囲が大きいため、<strong>SSH でのコマンド実行を推奨</strong>します。
                </p>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px 16px;">
                    <p style="margin:0 0 8px; font-weight:600; color:#1e293b;">復元手順:</p>
                    <ol style="margin:0; padding-left:20px; color:#475569; line-height:1.8;">
                        <li>SSH でサーバーにログイン</li>
                        <li>スナップショット一覧を確認:
                            <code style="background:#e2e8f0; padding:2px 6px; border-radius:3px;">kusanagi snapshot list mimamori</code>
                        </li>
                        <li>復元を実行:
                            <code style="background:#e2e8f0; padding:2px 6px; border-radius:3px;">kusanagi snapshot restore &lt;snapshot-name&gt; mimamori</code>
                        </li>
                    </ol>
                </div>
            </div>

            <!-- テーマ ZIP タブ -->
            <div id="panel-theme" style="border:1px solid #ccc; border-radius:0 6px 6px 6px; padding:20px; background:#fff; display:none;">
                <p style="margin:0 0 12px; color:#334155;">
                    テーマファイルのみを以前のバージョンに戻します（DB は変更されません）。<br>
                    デプロイ時に自動作成された ZIP スナップショットから復元します。
                </p>
                <?php $this->render_snapshot_table(); ?>
            </div>
        </div>

        <script>
        function switchRollbackTab(tab) {
            document.getElementById('panel-kusanagi').style.display = (tab === 'kusanagi') ? 'block' : 'none';
            document.getElementById('panel-theme').style.display = (tab === 'theme') ? 'block' : 'none';
            document.getElementById('tab-kusanagi').style.background = (tab === 'kusanagi') ? '#fff' : '#f1f5f9';
            document.getElementById('tab-kusanagi').style.fontWeight = (tab === 'kusanagi') ? '600' : 'normal';
            document.getElementById('tab-theme').style.background = (tab === 'theme') ? '#fff' : '#f1f5f9';
            document.getElementById('tab-theme').style.fontWeight = (tab === 'theme') ? '600' : 'normal';
        }
        </script>
        <?php
    }

    /**
     * テーマ ZIP スナップショット一覧テーブル
     */
    private function render_snapshot_table(): void {
        $snapshot_dir = defined( 'MIMAMORI_SNAPSHOT_DIR' ) ? MIMAMORI_SNAPSHOT_DIR : '';
        $theme_snap_dir = rtrim( $snapshot_dir, '/' ) . '/theme';

        if ( empty( $snapshot_dir ) || ! is_dir( $theme_snap_dir ) ) {
            echo '<p style="color:#999;">スナップショットディレクトリが見つかりません。</p>';
            return;
        }

        $files = glob( $theme_snap_dir . '/*.zip' );
        if ( empty( $files ) ) {
            echo '<p style="color:#999;">スナップショットはまだありません。デプロイ時に自動作成されます。</p>';
            return;
        }

        // 新しい順にソート
        usort( $files, function ( $a, $b ) {
            return filemtime( $b ) - filemtime( $a );
        } );

        $tz = wp_timezone();

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>日時</th><th>ファイル名</th><th>サイズ</th><th>操作</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $files as $file ) {
            $filename  = basename( $file );
            $filesize  = filesize( $file );
            $filemtime = filemtime( $file );

            $size_str = $filesize ? $this->format_bytes( $filesize ) : '不明';
            $date_str = '';
            if ( $filemtime ) {
                $dt = ( new \DateTimeImmutable( '@' . $filemtime ) )->setTimezone( $tz );
                $date_str = $dt->format( 'Y-m-d H:i:s' );
            }

            echo '<tr>';
            echo '<td>' . esc_html( $date_str ) . '</td>';
            echo '<td><code style="font-size:12px;">' . esc_html( $filename ) . '</code></td>';
            echo '<td>' . esc_html( $size_str ) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( self::NONCE_ACTION );
            echo '<input type="hidden" name="gcrev_deploy_action" value="rollback" />';
            echo '<input type="hidden" name="snapshot_file" value="' . esc_attr( $filename ) . '" />';
            echo '<button type="submit" class="button button-secondary"';
            echo ' onclick="return confirm(\'スナップショット ' . esc_js( $filename ) . ' にロールバックしますか？\\n\\n本番テーマが上書きされます。\');">';
            echo '🔄 ロールバック';
            echo '</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // =========================================================
    // セクション 4: デプロイ履歴
    // =========================================================

    private function render_deploy_log(): void {
        ?>
        <h2>デプロイ履歴</h2>
        <?php

        $log_path = $this->get_log_path();
        if ( ! $log_path || ! is_readable( $log_path ) ) {
            echo '<p style="color:#999;">デプロイログが見つかりません。</p>';
            return;
        }

        $lines = $this->tail_file( $log_path, self::LOG_DISPLAY_LINES );

        if ( empty( $lines ) ) {
            echo '<p style="color:#999;">まだ履歴がありません。</p>';
            return;
        }

        echo '<div style="background:#1e293b; color:#e2e8f0; border-radius:8px; padding:16px 20px; max-width:900px; overflow-x:auto;">';
        echo '<pre style="margin:0; font-size:12px; line-height:1.6; white-space:pre-wrap;">';
        foreach ( array_reverse( $lines ) as $line ) {
            echo esc_html( $line ) . "\n";
        }
        echo '</pre>';
        echo '</div>';
        echo '<p class="description" style="margin-top:8px;">直近 ' . esc_html( (string) self::LOG_DISPLAY_LINES ) . ' 件を新しい順で表示</p>';
    }

    // =========================================================
    // ヘルパー
    // =========================================================

    /**
     * deploy.log のパスを返す
     */
    private function get_log_path(): string {
        $snapshot_dir = defined( 'MIMAMORI_SNAPSHOT_DIR' ) ? MIMAMORI_SNAPSHOT_DIR : '';
        if ( empty( $snapshot_dir ) ) {
            return '';
        }
        return rtrim( $snapshot_dir, '/' ) . '/deploy.log';
    }

    /**
     * deploy.log の最終行を返す
     */
    private function get_last_log_line(): string {
        $log_path = $this->get_log_path();
        if ( empty( $log_path ) || ! is_readable( $log_path ) ) {
            return '';
        }

        $lines = $this->tail_file( $log_path, 1 );
        return ! empty( $lines ) ? $lines[0] : '';
    }

    /**
     * ファイル末尾 N 行を取得
     *
     * @param string $file_path ファイルパス
     * @param int    $num_lines 行数
     * @return array 行の配列
     */
    private function tail_file( string $file_path, int $num_lines ): array {
        if ( ! is_readable( $file_path ) ) {
            return [];
        }

        $lines = [];
        $fp    = fopen( $file_path, 'r' );
        if ( ! $fp ) {
            return [];
        }

        // ファイル全体を読んで末尾を取得（ログファイルは小さい想定）
        while ( ( $line = fgets( $fp ) ) !== false ) {
            $lines[] = rtrim( $line );
        }
        fclose( $fp );

        return array_slice( $lines, -$num_lines );
    }

    /**
     * バイト数を読みやすい形式に変換
     */
    private function format_bytes( int $bytes ): string {
        $units = [ 'B', 'KB', 'MB', 'GB' ];
        $power = $bytes > 0 ? (int) floor( log( $bytes, 1024 ) ) : 0;
        $power = min( $power, count( $units ) - 1 );

        return round( $bytes / pow( 1024, $power ), 1 ) . ' ' . $units[ $power ];
    }
}
