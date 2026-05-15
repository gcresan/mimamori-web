<?php
/**
 * Template Name: 利用規約（公開・サービス利用規約）
 *
 * Meta アプリレビュー対応の公開ページ。ログイン不要で閲覧可能。
 * URL: /terms-of-service/（/terms/ は別ページが先に使用しているため回避）
 */

$page_title = '利用規約';
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
                本利用規約（以下「本規約」といいます）は、みまもりウェブ（以下「本サービス」といいます）の提供条件および利用に関する権利・義務を定めるものです。本サービスをご利用になる前に、必ず本規約をお読みください。
            </p>

            <section class="policy-section">
                <h2>第1条（サービス概要）</h2>
                <p>
                    本サービスは、ご契約者さま（以下「利用者」といいます）が運営するウェブサイトのアクセス解析・検索データ・店舗情報・SNS 投稿等を一元的に管理し、わかりやすいダッシュボードや月次レポートを通じて改善活動を支援する SaaS 型サービスです。
                </p>
            </section>

            <section class="policy-section">
                <h2>第2条（利用条件）</h2>
                <ul>
                    <li>本サービスは、本規約および別途定める利用契約に同意いただいた利用者が利用できます。</li>
                    <li>利用者は、本サービスを利用するにあたり、関係法令および本規約を遵守するものとします。</li>
                    <li>本サービスの一部機能は、別途オプション契約や個別の設定が必要な場合があります。</li>
                </ul>
            </section>

            <section class="policy-section">
                <h2>第3条（アカウント管理）</h2>
                <ul>
                    <li>利用者は、自らの責任においてログイン情報（ユーザー名・パスワード）を適切に管理するものとします。</li>
                    <li>ログイン情報を第三者に貸与・譲渡してはなりません。</li>
                    <li>ログイン情報の漏えいに起因して生じた損害について、本サービスは責任を負いません。</li>
                    <li>不正利用の疑いを検知した場合、本サービスは事前の通知なくアカウントを一時停止することがあります。</li>
                </ul>
            </section>

            <section class="policy-section">
                <h2>第4条（SNS 連携機能について）</h2>
                <p>
                    本サービスは、利用者が自ら管理する SNS（Facebook、Instagram、Threads、LINE 等）アカウントへの投稿を補助する機能を提供します。本機能の利用にあたっては、以下の事項にご同意いただきます。
                </p>
                <ul>
                    <li>本サービスは、利用者の明示的な操作または利用者が事前に設定した予約投稿スケジュールに基づいてのみ投稿を行います。</li>
                    <li>本サービスは、利用者の同意なく勝手に投稿を行うことはありません。</li>
                    <li>SNS 連携の許諾範囲（取得情報・投稿権限）は、各 SNS プラットフォームの認証画面に表示される内容に従います。</li>
                    <li>各 SNS プラットフォームの仕様変更や障害により、本機能が一時的に利用できなくなる場合があります。</li>
                </ul>
                <div class="policy-callout">
                    本サービスは、利用者自身が管理する SNS アカウントへの投稿を補助するツールであり、利用者の明示的な操作または予約設定に基づいて投稿を行います。
                </div>
            </section>

            <section class="policy-section">
                <h2>第5条（投稿内容に関する責任）</h2>
                <p>
                    本サービスを通じて作成・投稿されるすべてのコンテンツ（テキスト、画像、動画等）の内容、適法性および第三者の権利侵害の有無について、その責任は当該コンテンツを作成・投稿した利用者にあります。本サービスは、投稿内容について事前審査を行いません。
                </p>
            </section>

            <section class="policy-section">
                <h2>第6条（禁止事項）</h2>
                <p>利用者は、本サービスの利用にあたり、次の行為を行ってはなりません。</p>
                <ul>
                    <li>本サービスの不正利用、または不正にアクセスする行為</li>
                    <li>第三者になりすまして投稿または操作する行為</li>
                    <li>第三者の著作権、肖像権、プライバシー権その他の権利を侵害する行為</li>
                    <li>法令または公序良俗に違反する行為</li>
                    <li>誹謗中傷、差別的表現、わいせつな表現等を含むコンテンツを投稿する行為</li>
                    <li>本サービス、他の利用者、または第三者の業務を妨害する行為</li>
                    <li>各 SNS プラットフォームの規約に違反する行為</li>
                    <li>その他、本サービスが不適切と判断する行為</li>
                </ul>
            </section>

            <section class="policy-section">
                <h2>第7条（サービスの変更・停止）</h2>
                <p>
                    本サービスは、利用者への事前通知のうえ、機能の追加・変更・停止を行うことがあります。ただし、緊急の場合またはやむを得ない事情がある場合は、事前通知なく行うことがあります。
                </p>
            </section>

            <section class="policy-section">
                <h2>第8条（免責事項）</h2>
                <ul>
                    <li>本サービスは、提供する情報・機能の正確性、完全性、有用性、特定目的への適合性について保証しません。</li>
                    <li>本サービスの利用または利用不能から生じた利用者または第三者の損害について、本サービスは責任を負いません。</li>
                    <li>外部 SNS プラットフォームや Google サービス等、第三者サービスの仕様変更・障害に起因する不利益について、本サービスは責任を負いません。</li>
                </ul>
            </section>

            <section class="policy-section">
                <h2>第9条（規約の変更）</h2>
                <p>
                    本サービスは、必要に応じて本規約を変更することがあります。重要な変更がある場合は、本サービス上で事前にお知らせします。変更後に本サービスを継続して利用された場合、変更後の規約に同意したものとみなします。
                </p>
            </section>

            <section class="policy-section">
                <h2>第10条（お問い合わせ先）</h2>
                <p>本規約に関するお問い合わせは、以下までご連絡ください。</p>
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
