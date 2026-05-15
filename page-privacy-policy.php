<?php
/**
 * Template Name: プライバシーポリシー（公開）
 *
 * Meta アプリレビュー対応の公開ページ。ログイン不要で閲覧可能。
 */

$page_title = 'プライバシーポリシー';
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
                みまもりウェブ（以下「本サービス」といいます）は、ご利用いただくお客さまの個人情報および各種データの取り扱いについて、本プライバシーポリシーを定めます。お客さまに安心して本サービスをご利用いただけるよう、わかりやすい言葉でご案内します。
            </p>

            <section class="policy-section">
                <h2>1. 本サービスが取得する情報</h2>
                <p>本サービスでは、サービス提供のために以下の情報を取得・保存することがあります。</p>
                <ul>
                    <li>アカウント情報（お名前、会社名、メールアドレス等）</li>
                    <li>ご契約・お支払いに関する情報</li>
                    <li>本サービスの利用状況（アクセスログ、操作履歴、設定内容など）</li>
                    <li>お客さまがご利用中のウェブサイトの分析データ（Google アナリティクス、Google Search Console、Google ビジネスプロフィール経由）</li>
                </ul>
            </section>

            <section class="policy-section">
                <h2>2. SNS（Meta）連携時に取得・保存する情報</h2>
                <p>
                    本サービスでは、お客さまがご自身で管理する SNS アカウントへの投稿を補助する機能を提供しています。Meta（Facebook / Instagram / Threads）連携をご利用いただく場合、以下の情報を取得・保存することがあります。
                </p>
                <ul>
                    <li>Facebook ページ情報（ページ ID、ページ名）</li>
                    <li>Instagram アカウント情報（アカウント ID、ユーザーネーム）</li>
                    <li>Threads アカウント情報（アカウント ID、ユーザーネーム）</li>
                    <li>SNS 投稿に必要なアクセストークン</li>
                    <li>投稿予定・投稿済みコンテンツの内容（テキスト、画像、動画、投稿日時など）</li>
                    <li>投稿結果情報（投稿の成否、投稿先 URL、エラーメッセージ等）</li>
                </ul>
                <p>
                    LINE 連携をご利用いただく場合についても、同様に LINE 公式アカウントへの投稿に必要な範囲で情報を取得・保存します。
                </p>
            </section>

            <section class="policy-section">
                <h2>3. 取得した情報の利用目的</h2>
                <p>取得した情報は、次の目的のために利用します。</p>
                <ul>
                    <li>SNS（Meta、LINE 等）アカウントとの連携機能の提供</li>
                    <li>SNS 投稿および予約投稿の実行</li>
                    <li>投稿結果の確認・履歴管理</li>
                    <li>ダッシュボードや月次レポートなど、本サービス機能の表示・改善</li>
                    <li>お客さまからのお問い合わせへの対応</li>
                    <li>本サービスの品質向上、機能改善、不具合対応</li>
                </ul>
            </section>

            <section class="policy-section">
                <h2>4. 第三者提供について</h2>
                <p>
                    本サービスは、お客さまから取得した情報を、お客さまの同意なく第三者に提供することはありません。ただし、次の場合を除きます。
                </p>
                <ul>
                    <li>法令に基づき開示が求められた場合</li>
                    <li>人の生命、身体または財産の保護のために必要であって、お客さまの同意を得ることが困難な場合</li>
                    <li>本サービスの運営を委託する事業者に対し、業務遂行に必要な範囲で開示する場合</li>
                </ul>
            </section>

            <section class="policy-section">
                <h2>5. 外部サービスとの連携について</h2>
                <p>本サービスは、お客さまの操作または設定に基づき、以下の外部サービスと連携します。</p>
                <ul>
                    <li><strong>Meta（Facebook / Instagram / Threads）</strong>：SNS 投稿、予約投稿、投稿結果の取得</li>
                    <li><strong>LINE</strong>：LINE 公式アカウントへの投稿</li>
                    <li><strong>Google（Analytics、Search Console、Business Profile）</strong>：アクセス解析、検索データ、店舗情報の取得</li>
                </ul>
                <p>
                    これらの外部サービス上での情報取り扱いについては、各社のプライバシーポリシーをご確認ください。
                </p>
            </section>

            <section class="policy-section">
                <h2>6. アクセストークン等の安全管理</h2>
                <p>
                    SNS 連携で取得したアクセストークンは、暗号化のうえ保存します。本サービスの管理者および権限を有する開発・運用担当者以外がアクセスできない仕組みとしています。
                </p>
                <p>
                    本サービスは、お客さまの明示的な操作または事前に設定された予約投稿スケジュールに基づいて投稿を行います。お客さまの同意なく勝手に投稿を行うことはありません。
                </p>
            </section>

            <section class="policy-section">
                <h2>7. ユーザーによる連携解除</h2>
                <p>
                    お客さまは、本サービスの設定画面からいつでも SNS 連携を解除できます。連携を解除すると、本サービスに保存されているアクセストークンおよび連携状態に関する情報は削除されます。
                </p>
                <p>
                    詳しい削除手順については、<a href="<?php echo esc_url( home_url( '/data-deletion/' ) ); ?>">データ削除について</a>のページをご確認ください。
                </p>
            </section>

            <section class="policy-section">
                <h2>8. プライバシーポリシーの変更</h2>
                <p>
                    本サービスは、必要に応じて本プライバシーポリシーを変更することがあります。重要な変更がある場合は、本サービス上で事前にお知らせします。
                </p>
            </section>

            <section class="policy-section">
                <h2>9. お問い合わせ先</h2>
                <p>
                    本プライバシーポリシーに関するお問い合わせは、以下までご連絡ください。
                </p>
                <p class="policy-contact">
                    みまもりウェブ運営事務局<br>
                    メール：<a href="mailto:<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><?php echo esc_html( get_option( 'admin_email' ) ); ?></a>
                </p>
            </section>
        </main>

        <?php get_template_part( 'template-parts/policy-page-footer' ); ?>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
