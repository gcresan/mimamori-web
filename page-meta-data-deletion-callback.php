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
 *           → 検証失敗・signed_request 欠落時も削除はせず、HTTP 200 + JSON を返す
 *             （Meta 管理画面の URL 保存時に空 POST で疎通確認されるため）
 *   - GET:  HTTP 200 で人間向けの簡単な説明ページを表示
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
// GET: 人間向け説明ページ（HTTP 200）
// ===================================================================
status_header( 200 );
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
 * POST リクエストを処理し、HTTP 200 + JSON を返却する。
 *
 * Meta は「データ削除コールバック URL」を保存する際に空 POST で疎通確認するため、
 * signed_request が無い・不正・署名検証 NG の場合でも 200 + JSON で応答する。
 * 実際の削除処理は signed_request の HMAC-SHA256 検証に成功した場合のみ行う。
 */
function mimamori_meta_data_deletion_handle_post(): void {
    // 既に何か出力されていればクリア（安全策）。
    if ( function_exists( 'ob_get_length' ) && ob_get_length() ) {
        @ob_clean();
    }
    nocache_headers();

    $signed_request = isset( $_POST['signed_request'] )
        ? (string) wp_unslash( $_POST['signed_request'] )
        : '';

    $verification = mimamori_meta_data_deletion_verify_signed_request( $signed_request );
    $verified     = ( $verification['status'] === 'verified' );
    $fb_user_id   = $verification['fb_user_id'];
    $reason       = $verification['reason'];

    // 検証成功時のみ user_meta 削除を実行。
    $matched_ids = [];
    if ( $verified && $fb_user_id !== '' && class_exists( 'Gcrev_Meta_Client' ) ) {
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
    }

    // 確認コード生成（英数字 24 桁、衝突実質ゼロ）。
    $confirmation_code = 'meta-' . wp_generate_password( 24, false, false );

    // 全リクエストを wp_options に記録（autoload なし、上限 1000 件）。
    $log = get_option( 'gcrev_meta_data_deletion_log', [] );
    if ( ! is_array( $log ) ) { $log = []; }
    $log[ $confirmation_code ] = [
        'fb_user_id'    => $fb_user_id,
        'matched_users' => $matched_ids,
        'requested_at'  => time(),
        'verified'      => $verified,
        'reason'        => $reason,
        'status'        => $verified ? 'completed' : 'received_no_action',
    ];
    if ( count( $log ) > 1000 ) {
        $log = array_slice( $log, -1000, null, true );
    }
    update_option( 'gcrev_meta_data_deletion_log', $log, false );

    mimamori_meta_data_deletion_log( sprintf(
        'POST signed_request=%s verified=%s reason=%s fb_user_id=%s matched=%d code=%s',
        $signed_request === '' ? 'missing' : 'present',
        $verified ? 'true' : 'false',
        $reason,
        $fb_user_id !== '' ? $fb_user_id : '-',
        count( $matched_ids ),
        $confirmation_code
    ) );

    // Meta 仕様の JSON レスポンス（常に HTTP 200）。
    $status_url = add_query_arg(
        'code',
        $confirmation_code,
        home_url( '/data-deletion-status/' )
    );
    status_header( 200 );
    wp_send_json( [
        'url'               => $status_url,
        'confirmation_code' => $confirmation_code,
    ], 200 );
}

/**
 * signed_request の検証だけを行い、結果を返す（副作用なし）。
 *
 * @return array{status:string,fb_user_id:string,reason:string}
 *   status     : 'verified' | 'unverified'
 *   fb_user_id : 検証成功時の Facebook ユーザー ID（失敗時は ''）
 *   reason     : 失敗理由のラベル（ログ用）
 */
function mimamori_meta_data_deletion_verify_signed_request( string $signed_request ): array {
    if ( $signed_request === '' ) {
        return [ 'status' => 'unverified', 'fb_user_id' => '', 'reason' => 'no_signed_request' ];
    }
    $parts = explode( '.', $signed_request, 2 );
    if ( count( $parts ) !== 2 ) {
        return [ 'status' => 'unverified', 'fb_user_id' => '', 'reason' => 'malformed' ];
    }
    list( $encoded_sig, $payload ) = $parts;

    $sig         = mimamori_meta_data_deletion_b64url_decode( $encoded_sig );
    $payload_raw = mimamori_meta_data_deletion_b64url_decode( $payload );
    $data        = json_decode( $payload_raw, true );
    if ( ! is_array( $data ) || ( $data['algorithm'] ?? '' ) !== 'HMAC-SHA256' ) {
        return [ 'status' => 'unverified', 'fb_user_id' => '', 'reason' => 'bad_payload_or_algorithm' ];
    }
    if ( ! class_exists( 'Gcrev_Meta_Client' ) ) {
        return [ 'status' => 'unverified', 'fb_user_id' => '', 'reason' => 'meta_client_missing' ];
    }
    $app_secret = Gcrev_Meta_Client::get_app_secret();
    if ( $app_secret === '' ) {
        return [ 'status' => 'unverified', 'fb_user_id' => '', 'reason' => 'app_secret_not_configured' ];
    }
    // HMAC-SHA256 は「エンコード前のペイロード」ではなく「エンコード済みペイロード文字列」に対して計算する（Meta 仕様）
    $expected_sig = hash_hmac( 'sha256', $payload, $app_secret, true );
    if ( ! hash_equals( $expected_sig, $sig ) ) {
        return [ 'status' => 'unverified', 'fb_user_id' => '', 'reason' => 'signature_mismatch' ];
    }
    $fb_user_id = isset( $data['user_id'] ) ? (string) $data['user_id'] : '';
    if ( $fb_user_id === '' ) {
        return [ 'status' => 'unverified', 'fb_user_id' => '', 'reason' => 'missing_user_id' ];
    }
    return [ 'status' => 'verified', 'fb_user_id' => $fb_user_id, 'reason' => 'ok' ];
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
