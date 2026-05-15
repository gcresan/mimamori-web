<?php
/**
 * Template Name: Meta Data Deletion Callback
 *
 * Meta（Facebook）の「データ削除コールバック URL」エンドポイント。
 * https://mimamori-web.jp/meta-data-deletion-callback/
 *
 * 動作:
 *   - POST: signed_request を受け取り、Meta App Secret で署名検証
 *           → 検証成功で該当ユーザーの Meta 連携 user_meta を削除
 *           → JSON ({ url, confirmation_code }) を返す
 *   - GET:  人間向けの簡単な説明ページを表示
 *
 * Meta 仕様:
 *   https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback
 *
 * 注意:
 *   - 本テンプレートはログイン不要・Cookie 不要・nonce 不要の公開エンドポイント
 *   - 認証は signed_request の HMAC-SHA256 のみ
 *   - LINE 連携用 user_meta（_gcrev_line_*）は削除しない
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ===================================================================
// POST: signed_request 検証 → 削除 → JSON 返却
// ===================================================================
if ( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( $_SERVER['REQUEST_METHOD'] ) === 'POST' ) {
    mimamori_meta_data_deletion_handle_post();
    exit; // 念のため
}

// ===================================================================
// GET: 人間向け説明ページ
// ===================================================================
$page_title = 'Meta データ削除コールバック';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html( $page_title ); ?>｜<?php bloginfo( 'name' ); ?></title>
    <link rel="icon" type="image/x-icon" href="<?php echo esc_url( get_template_directory_uri() ); ?>/images/favicon.ico">
    <?php get_template_part( 'template-parts/policy-page-styles' ); ?>
</head>
<body class="policy-page">
    <div class="policy-wrapper">
        <header class="policy-header">
            <a href="<?php echo esc_url( home_url( '/login/' ) ); ?>" class="policy-logo-link">
                <img src="<?php echo esc_url( get_template_directory_uri() ); ?>/images/common/logo.png" alt="みまもりウェブ">
            </a>
        </header>

        <main class="policy-main">
            <h1 class="policy-title"><?php echo esc_html( $page_title ); ?></h1>

            <section class="policy-section">
                <p>
                    このページは Meta（Facebook）からの「データ削除コールバック」リクエストを受け付けるエンドポイントです。
                </p>
                <p>
                    通常のユーザー向け削除手順は <a href="<?php echo esc_url( home_url( '/user-data-deletion/' ) ); ?>">データ削除について</a> をご確認ください。
                </p>
            </section>
        </main>

        <?php get_template_part( 'template-parts/policy-page-footer' ); ?>
    </div>
</body>
</html>
<?php
exit;

// ===================================================================
// ハンドラー（テンプレート専用、ファイル末尾に定義）
// ===================================================================

/**
 * POST リクエストを処理し、JSON を返却する。
 * 必ず exit する。
 */
function mimamori_meta_data_deletion_handle_post(): void {
    // 既に何か出力されていればクリア（安全策）
    if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
        @ob_clean();
    }
    nocache_headers();

    $signed_request = isset( $_POST['signed_request'] )
        ? (string) wp_unslash( $_POST['signed_request'] )
        : '';

    if ( $signed_request === '' ) {
        mimamori_meta_data_deletion_log( 'missing signed_request' );
        status_header( 400 );
        wp_send_json( [ 'error' => 'missing signed_request' ], 400 );
    }

    $parts = explode( '.', $signed_request, 2 );
    if ( count( $parts ) !== 2 ) {
        mimamori_meta_data_deletion_log( 'malformed signed_request' );
        status_header( 400 );
        wp_send_json( [ 'error' => 'malformed signed_request' ], 400 );
    }
    list( $encoded_sig, $payload ) = $parts;

    $sig         = mimamori_meta_data_deletion_b64url_decode( $encoded_sig );
    $payload_raw = mimamori_meta_data_deletion_b64url_decode( $payload );
    $data        = json_decode( $payload_raw, true );

    if ( ! is_array( $data ) || ( $data['algorithm'] ?? '' ) !== 'HMAC-SHA256' ) {
        mimamori_meta_data_deletion_log( 'bad payload or algorithm' );
        status_header( 400 );
        wp_send_json( [ 'error' => 'unknown algorithm' ], 400 );
    }

    if ( ! class_exists( 'Gcrev_Meta_Client' ) ) {
        mimamori_meta_data_deletion_log( 'Gcrev_Meta_Client missing' );
        status_header( 500 );
        wp_send_json( [ 'error' => 'server misconfigured' ], 500 );
    }

    $app_secret = Gcrev_Meta_Client::get_app_secret();
    if ( $app_secret === '' ) {
        mimamori_meta_data_deletion_log( 'app_secret not set' );
        status_header( 500 );
        wp_send_json( [ 'error' => 'app secret not configured' ], 500 );
    }

    // HMAC-SHA256 は「エンコード前のペイロード」ではなく「エンコード済みペイロード文字列」に対して計算する（Meta 仕様）
    $expected_sig = hash_hmac( 'sha256', $payload, $app_secret, true );
    if ( ! hash_equals( $expected_sig, $sig ) ) {
        mimamori_meta_data_deletion_log( 'signature mismatch' );
        status_header( 403 );
        wp_send_json( [ 'error' => 'signature mismatch' ], 403 );
    }

    $fb_user_id = isset( $data['user_id'] ) ? (string) $data['user_id'] : '';
    if ( $fb_user_id === '' ) {
        mimamori_meta_data_deletion_log( 'missing user_id in payload' );
        status_header( 400 );
        wp_send_json( [ 'error' => 'missing user_id' ], 400 );
    }

    // 該当 WP ユーザーを検索して Meta 連携データを削除（LINE 連携は削除しない）
    $user_query = new WP_User_Query( [
        'meta_key'   => '_gcrev_meta_fb_user_id',
        'meta_value' => $fb_user_id,
        'fields'     => 'ID',
        'number'     => 100,
    ] );

    $matched_ids = array_map( 'intval', (array) $user_query->get_results() );
    foreach ( $matched_ids as $uid ) {
        if ( $uid > 0 ) {
            Gcrev_Meta_Client::disconnect( $uid );
        }
    }

    // 確認コード生成（英数字 24 桁、衝突実質ゼロ）
    $confirmation_code = 'meta-' . wp_generate_password( 24, false, false );

    // 削除ログを wp_options に記録（autoload なし、上限 1000 件）
    $log = get_option( 'gcrev_meta_data_deletion_log', [] );
    if ( ! is_array( $log ) ) { $log = []; }
    $log[ $confirmation_code ] = [
        'fb_user_id'    => $fb_user_id,
        'matched_users' => $matched_ids,
        'requested_at'  => time(),
        'status'        => 'completed',
    ];
    if ( count( $log ) > 1000 ) {
        $log = array_slice( $log, -1000, null, true );
    }
    update_option( 'gcrev_meta_data_deletion_log', $log, false );

    mimamori_meta_data_deletion_log( sprintf(
        'OK fb_user_id=%s matched=%d code=%s',
        $fb_user_id,
        count( $matched_ids ),
        $confirmation_code
    ) );

    // Meta 仕様の JSON レスポンス
    $status_url = add_query_arg(
        'code',
        $confirmation_code,
        home_url( '/data-deletion-status/' )
    );
    wp_send_json( [
        'url'               => $status_url,
        'confirmation_code' => $confirmation_code,
    ], 200 );
}

/**
 * Meta が使う base64url 形式（'-' '_' を '+' '/' に置換）でデコード
 */
function mimamori_meta_data_deletion_b64url_decode( string $s ): string {
    $remainder = strlen( $s ) % 4;
    if ( $remainder ) {
        $s .= str_repeat( '=', 4 - $remainder );
    }
    return (string) base64_decode( strtr( $s, '-_', '+/' ) );
}

/**
 * 開発・障害解析用ログ（CLAUDE.md §7.1 のパターン）
 */
function mimamori_meta_data_deletion_log( string $msg ): void {
    @file_put_contents(
        '/tmp/gcrev_meta_deletion_debug.log',
        date( 'Y-m-d H:i:s' ) . ' ' . $msg . "\n",
        FILE_APPEND
    );
}
