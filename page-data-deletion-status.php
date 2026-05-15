<?php
/**
 * Template Name: データ削除ステータス（公開）
 *
 * Meta データ削除コールバック（page-meta-data-deletion-callback.php）が
 * 返した confirmation_code の処理状況を表示する公開ページ。
 *
 * URL: https://mimamori-web.jp/data-deletion-status/?code=...
 * Meta App Review でこの URL が到達可能であることを確認される。
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$page_title = 'データ削除ステータス';

$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

// セキュリティ：取得した code を信頼せず、保存ログから整合性チェック
$record = null;
if ( $code !== '' && preg_match( '/^[A-Za-z0-9_\-]{4,64}$/', $code ) ) {
    $log = get_option( 'gcrev_meta_data_deletion_log', [] );
    if ( is_array( $log ) && isset( $log[ $code ] ) && is_array( $log[ $code ] ) ) {
        $record = $log[ $code ];
    }
}
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

            <?php if ( $code === '' ) : ?>
                <section class="policy-section">
                    <p>確認コード（<code>code</code>）が指定されていません。</p>
                    <p>
                        通常のユーザー向け削除手順は <a href="<?php echo esc_url( home_url( '/user-data-deletion/' ) ); ?>">データ削除について</a> をご確認ください。
                    </p>
                </section>
            <?php elseif ( $record === null ) : ?>
                <section class="policy-section">
                    <p>
                        確認コード <code><?php echo esc_html( $code ); ?></code> に対応する削除リクエストが見つかりませんでした。
                    </p>
                    <p>
                        コードが正しいかご確認ください。お手数ですが、ご不明な場合は
                        <a href="<?php echo esc_url( home_url( '/user-data-deletion/' ) ); ?>">データ削除について</a> 記載のお問い合わせ先までご連絡ください。
                    </p>
                </section>
            <?php else :
                $requested_at = isset( $record['requested_at'] ) ? (int) $record['requested_at'] : 0;
                $matched      = isset( $record['matched_users'] ) ? (array) $record['matched_users'] : [];
                $status       = isset( $record['status'] ) ? (string) $record['status'] : 'unknown';
            ?>
                <section class="policy-section">
                    <h2>削除リクエスト受付済み</h2>
                    <p>確認コード：<code><?php echo esc_html( $code ); ?></code></p>
                    <p>受付日時：<?php echo esc_html( $requested_at > 0 ? wp_date( 'Y-m-d H:i:s', $requested_at ) : '—' ); ?></p>
                    <p>処理状況：<strong><?php echo esc_html( $status ); ?></strong></p>
                    <p>
                        Meta（Facebook / Instagram / Threads）連携用に保存していたアクセストークン・連携情報は削除済みです。
                    </p>
                    <?php if ( count( $matched ) === 0 ) : ?>
                        <p>
                            <small>※ 連携情報はすでに存在しなかったため、追加の削除処理はありません。</small>
                        </p>
                    <?php endif; ?>
                </section>

                <section class="policy-section">
                    <h2>追加の削除依頼</h2>
                    <p>
                        本サービス内に残るその他のデータ（過去の投稿ログ等）の削除をご希望の場合は、
                        <a href="<?php echo esc_url( home_url( '/user-data-deletion/' ) ); ?>">データ削除について</a> 記載のお問い合わせ先までご連絡ください。
                    </p>
                </section>
            <?php endif; ?>
        </main>

        <?php get_template_part( 'template-parts/policy-page-footer' ); ?>
    </div>
</body>
</html>
