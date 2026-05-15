<?php
// FILE: inc/gcrev-api/admin/class-claude-test-page.php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'Gcrev_Claude_Test_Page' ) ) { return; }

/**
 * Gcrev_Claude_Test_Page
 *
 * 管理画面「みまもりウェブ > Claude API」ページ。
 * wp-config.php の ANTHROPIC_API_KEY 定数を使って Claude API への疎通を確認する。
 *
 * @package Mimamori_Web
 * @since   3.0.0
 */
class Gcrev_Claude_Test_Page {

    private const MENU_SLUG     = 'gcrev-claude-test';
    private const TEST_MODEL    = 'claude-haiku-4-5-20251001';
    private const API_ENDPOINT  = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION   = '2023-06-01';
    private const RESULT_OPTION = 'gcrev_claude_test_last_result';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'handle_test_request' ] );
    }

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
            'Claude API - みまもりウェブ',
            "\xF0\x9F\xA4\x96 Claude API",
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================
    // テスト実行
    // =========================================================

    public function handle_test_request(): void {
        if ( ! isset( $_POST['gcrev_claude_test_run'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        check_admin_referer( 'gcrev_claude_test_nonce' );

        $result = self::run_test();
        update_option( self::RESULT_OPTION, $result, false );

        if ( $result['ok'] ) {
            add_settings_error( 'gcrev_claude_test', 'test_ok', '接続テストに成功しました。', 'success' );
        } else {
            add_settings_error( 'gcrev_claude_test', 'test_ng', '接続テストに失敗しました: ' . $result['error'], 'error' );
        }
    }

    /**
     * Claude API に最小リクエストを送り、結果を配列で返す。
     *
     * @return array{ok:bool, status:int, model:string, reply:string, error:string, latency_ms:int, ran_at:string}
     */
    private static function run_test(): array {
        $base = [
            'ok'         => false,
            'status'     => 0,
            'model'      => self::TEST_MODEL,
            'reply'      => '',
            'error'      => '',
            'latency_ms' => 0,
            'ran_at'     => wp_date( 'Y-m-d H:i:s' ),
        ];

        if ( ! defined( 'ANTHROPIC_API_KEY' ) || ! ANTHROPIC_API_KEY ) {
            $base['error'] = 'wp-config.php に ANTHROPIC_API_KEY が定義されていません。';
            return $base;
        }

        $body = wp_json_encode( [
            'model'      => self::TEST_MODEL,
            'max_tokens' => 64,
            'messages'   => [
                [ 'role' => 'user', 'content' => 'ping' ],
            ],
        ], JSON_UNESCAPED_UNICODE );

        $started = microtime( true );

        $response = wp_remote_post( self::API_ENDPOINT, [
            'timeout' => 20,
            'headers' => [
                'x-api-key'         => ANTHROPIC_API_KEY,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'body'    => $body,
        ] );

        $base['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

        if ( is_wp_error( $response ) ) {
            $base['error'] = $response->get_error_message();
            file_put_contents(
                '/tmp/gcrev_claude_debug.log',
                date( 'Y-m-d H:i:s' ) . " test FAILED (wp_error): " . $base['error'] . "\n",
                FILE_APPEND
            );
            return $base;
        }

        $status        = wp_remote_retrieve_response_code( $response );
        $raw_body      = wp_remote_retrieve_body( $response );
        $base['status'] = (int) $status;

        $decoded = json_decode( $raw_body, true );

        if ( $status !== 200 ) {
            $err_msg = is_array( $decoded ) && isset( $decoded['error']['message'] )
                ? (string) $decoded['error']['message']
                : 'HTTP ' . (int) $status;
            $base['error'] = $err_msg;
            file_put_contents(
                '/tmp/gcrev_claude_debug.log',
                date( 'Y-m-d H:i:s' ) . " test FAILED: status={$status}, body=" . substr( $raw_body, 0, 500 ) . "\n",
                FILE_APPEND
            );
            return $base;
        }

        $reply = '';
        if ( is_array( $decoded ) && isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
            foreach ( $decoded['content'] as $block ) {
                if ( isset( $block['type'], $block['text'] ) && $block['type'] === 'text' ) {
                    $reply .= $block['text'];
                }
            }
        }

        $base['ok']    = true;
        $base['reply'] = trim( $reply );
        if ( isset( $decoded['model'] ) && is_string( $decoded['model'] ) ) {
            $base['model'] = $decoded['model'];
        }

        return $base;
    }

    // =========================================================
    // ページ描画
    // =========================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $key_defined = defined( 'ANTHROPIC_API_KEY' ) && ANTHROPIC_API_KEY;
        $masked_key  = $key_defined ? self::mask_key( ANTHROPIC_API_KEY ) : '（未設定）';
        $last        = get_option( self::RESULT_OPTION, [] );
        if ( ! is_array( $last ) ) { $last = []; }
        ?>
        <div class="wrap">
            <h1>みまもりウェブ — Claude API</h1>

            <?php settings_errors( 'gcrev_claude_test' ); ?>

            <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:16px 24px 24px; margin-bottom:20px; max-width:880px;">
                <h2 style="margin-top:0;">API キー</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">ANTHROPIC_API_KEY</th>
                        <td>
                            <code><?php echo esc_html( $masked_key ); ?></code>
                            <?php if ( ! $key_defined ): ?>
                                <p class="description" style="color:#b32d2e;">
                                    wp-config.php に <code>define('ANTHROPIC_API_KEY', '...')</code> を追記してください。
                                </p>
                            <?php else: ?>
                                <p class="description">wp-config.php の定数を参照しています。</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">テスト用モデル</th>
                        <td><code><?php echo esc_html( self::TEST_MODEL ); ?></code></td>
                    </tr>
                </table>

                <hr style="margin:16px 0;" />

                <h3 style="margin:0 0 8px;">接続テスト</h3>
                <p style="margin:0 0 12px;">
                    上記モデルに <code>ping</code> を1回送信し、応答が返ることを確認します（最大トークン: 64）。
                </p>

                <form method="post">
                    <?php wp_nonce_field( 'gcrev_claude_test_nonce' ); ?>
                    <input type="hidden" name="gcrev_claude_test_run" value="1" />
                    <?php submit_button( '接続テストを実行', 'primary', 'submit', false, $key_defined ? [] : [ 'disabled' => 'disabled' ] ); ?>
                </form>
            </div>

            <?php if ( ! empty( $last ) ): ?>
                <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:16px 24px 24px; max-width:880px;">
                    <h2 style="margin-top:0;">最終テスト結果</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">実行日時</th>
                            <td><?php echo esc_html( (string) ( $last['ran_at'] ?? '' ) ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row">ステータス</th>
                            <td>
                                <?php if ( ! empty( $last['ok'] ) ): ?>
                                    <span style="color:#00a32a; font-weight:600;">✓ 成功</span>
                                <?php else: ?>
                                    <span style="color:#b32d2e; font-weight:600;">✗ 失敗</span>
                                <?php endif; ?>
                                （HTTP <?php echo (int) ( $last['status'] ?? 0 ); ?>）
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">レイテンシ</th>
                            <td><?php echo (int) ( $last['latency_ms'] ?? 0 ); ?> ms</td>
                        </tr>
                        <tr>
                            <th scope="row">モデル</th>
                            <td><code><?php echo esc_html( (string) ( $last['model'] ?? '' ) ); ?></code></td>
                        </tr>
                        <?php if ( ! empty( $last['reply'] ) ): ?>
                            <tr>
                                <th scope="row">応答テキスト</th>
                                <td><pre style="margin:0; padding:8px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:3px; white-space:pre-wrap;"><?php echo esc_html( (string) $last['reply'] ); ?></pre></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ( ! empty( $last['error'] ) ): ?>
                            <tr>
                                <th scope="row">エラー</th>
                                <td><pre style="margin:0; padding:8px; background:#fcf0f1; border:1px solid #f5c2c7; border-radius:3px; white-space:pre-wrap;"><?php echo esc_html( (string) $last['error'] ); ?></pre></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * API キーを末尾4文字以外マスクして表示する。
     */
    private static function mask_key( string $key ): string {
        $len = strlen( $key );
        if ( $len <= 8 ) {
            return str_repeat( '*', $len );
        }
        return substr( $key, 0, 7 ) . str_repeat( '*', max( 4, $len - 11 ) ) . substr( $key, -4 );
    }
}
