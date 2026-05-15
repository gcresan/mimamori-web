<?php
/**
 * Template Name: データ削除について（公開）
 *
 * Meta アプリレビュー対応の公開ページ。ログイン不要で閲覧可能。
 * Meta App Review の Data Deletion Instructions URL として参照される。
 */

$page_title = 'データ削除について';
$last_updated = '2026年5月15日';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="index,follow">
    <title><?php echo esc_html( $page_title ); ?>｜<?php bloginfo( 'name' ); ?></title>
    <link rel="icon" type="image/x-icon" href="<?php echo esc_url( get_template_directory_uri() ); ?>/images/favicon.ico">
    <?php get_template_part( 'template-parts/policy-page-styles' ); ?>
    <?php wp_head(); ?>
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
            <p class="policy-updated">最終更新日：<?php echo esc_html( $last_updated ); ?></p>

            <p class="policy-lead">
                みまもりウェブ（以下「本サービス」といいます）では、Meta（Facebook / Instagram / Threads）連携でお預かりしているデータの削除手順をご案内します。
            </p>

            <section class="policy-section">
                <h2>1. Meta 連携の解除方法</h2>
                <p>本サービス上で Meta 連携を解除するには、以下の手順で操作してください。</p>
                <ol>
                    <li>本サービスにログインします。</li>
                    <li>サイドメニューから「SNS連携設定」を開きます。</li>
                    <li>連携中の Meta（Facebook / Instagram / Threads）アカウントの「連携を解除する」ボタンをクリックします。</li>
                    <li>確認ダイアログで「解除する」を選択します。</li>
                </ol>
                <div class="policy-callout">
                    みまもりウェブ上で Meta 連携を解除すると、保存されている Meta 連携用アクセストークンを削除します。
                    追加でデータ削除を希望される場合は、お問い合わせ先までご連絡ください。
                </div>
            </section>

            <section class="policy-section">
                <h2>2. 連携解除により削除されるデータ</h2>
                <p>本サービス上で Meta 連携を解除すると、以下のデータが速やかに削除されます。</p>
                <ul>
                    <li>Meta（Facebook / Instagram / Threads）のアクセストークン</li>
                    <li>連携状態に関する情報（連携先アカウント ID、連携日時、トークン有効期限など）</li>
                </ul>
                <p>
                    なお、解除後も本サービス内に残る情報（過去に本サービスから投稿したコンテンツの投稿ログなど）の削除をご希望の場合は、お問い合わせ先までご連絡ください。
                </p>
            </section>

            <section class="policy-section">
                <h2>3. 投稿済みコンテンツについて</h2>
                <p>
                    本サービスを通じて Facebook / Instagram / Threads 等にすでに投稿されたコンテンツは、各 SNS プラットフォーム上に残ります。これらの投稿の削除は、各 SNS プラットフォーム上で利用者ご自身に行っていただく必要があります。
                </p>
                <ul>
                    <li>Facebook 投稿：Facebook ページ上で対象の投稿を削除してください。</li>
                    <li>Instagram 投稿：Instagram アプリまたは Web で対象の投稿を削除してください。</li>
                    <li>Threads 投稿：Threads アプリまたは Web で対象の投稿を削除してください。</li>
                </ul>
            </section>

            <section class="policy-section">
                <h2>4. 追加削除のご依頼</h2>
                <p>
                    上記のセルフサービス手順では削除できないデータについて削除をご希望の場合、または本サービス側でのアカウント情報・各種ログの削除をご希望の場合は、お問い合わせ先までご連絡ください。
                </p>
                <p>
                    本人確認のため、ご登録のメールアドレスからご連絡いただくか、本サービスにログイン中の状態でお問い合わせフォームをご利用ください。
                </p>
            </section>

            <section class="policy-section">
                <h2>5. お問い合わせ先（削除依頼）</h2>
                <p>データ削除に関するお問い合わせは、以下までご連絡ください。</p>
                <p class="policy-contact">
                    みまもりウェブ運営事務局<br>
                    メール：<a href="mailto:<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><?php echo esc_html( get_option( 'admin_email' ) ); ?></a><br>
                    件名：「Meta 連携データ削除依頼」とご記載ください。
                </p>
                <p>
                    お問い合わせを受け付け後、原則として 5 営業日以内に対応状況をご連絡します。
                </p>
            </section>
        </main>

        <?php get_template_part( 'template-parts/policy-page-footer' ); ?>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
