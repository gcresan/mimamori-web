<?php
/*
Template Name: よくある質問
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', 'よくある質問' );
set_query_var( 'gcrev_page_subtitle', 'みまもりウェブについて、よくいただく質問をまとめました。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( 'よくある質問', 'サポート・問い合わせ' ) );

/* ---------- FAQ data ---------- */
$faq_categories = [

    [
        'title' => 'はじめに・基本操作',
        'items' => [
            [
                'q' => 'ログインできません',
                'a' => 'ログイン画面でメールアドレスとパスワードを正しく入力しているかご確認ください。大文字・小文字やスペースが入っていないかもチェックしてみてください。それでもうまくいかない場合は、下の「パスワードを忘れた場合」をお試しください。',
            ],
            [
                'q' => 'パスワードを忘れてしまいました',
                'a' => 'ログイン画面の「パスワードをお忘れですか？」をクリックすると、登録したメールアドレスにリセット用のメールが届きます。メールが届かない場合は、迷惑メールフォルダもご確認ください。',
            ],
            [
                'q' => 'ダッシュボードには何が表示されていますか？',
                'a' => 'ホームページに関する主な数字がまとまっています。訪問者数、ページの閲覧数、お問い合わせの件数などが、前の期間と比べてどう変わったかがひと目でわかるようになっています。数字の意味がわからなくても、矢印が上向きか下向きかを見るだけで、状況をつかめます。',
            ],
            [
                'q' => '数字が「0」や「データなし」と表示されます',
                'a' => '利用開始直後はデータがまだ集まっていないため、しばらく「0」と表示されることがあります。通常、1〜2日ほどでデータが反映されはじめます。数日たっても変わらない場合は、お問い合わせください。',
            ],
        ],
    ],

    [
        'title' => '月次レポートについて',
        'items' => [
            [
                'q' => '月次レポートはいつ届きますか？',
                'a' => '毎月初め（1日〜3日頃）に、前月分のレポートが自動で作成されます。届いたらメニューの「月次レポート」から確認できます。特に通知などはありませんので、月が変わったらチェックしてみてください。',
            ],
            [
                'q' => 'レポートの内容がよくわかりません',
                'a' => 'レポートには「かんたんモード」があり、専門用語を使わずにやさしい言葉で説明してくれます。レポート画面の上のほうにある切り替えボタンで変更できます。それでもわからないことがあれば、AIチャットに「レポートの内容を教えて」と聞いてみてください。',
            ],
            [
                'q' => '過去のレポートは見られますか？',
                'a' => 'はい。メニューの「レポート履歴」から、これまでに作成されたレポートをすべて確認できます。月ごとの変化を見比べたいときに便利です。',
            ],
        ],
    ],

    [
        'title' => 'データ・分析について',
        'items' => [
            [
                'q' => 'データはリアルタイムで反映されますか？',
                'a' => 'Googleアナリティクスのデータは、通常24〜48時間ほど遅れて反映されます。「昨日の分が見たい」という場合は、翌日の夕方以降にご確認ください。そのため、直近1〜2日分は表示されないことがあります。',
            ],
            [
                'q' => '「訪問者数」と「ページビュー」は何が違いますか？',
                'a' => '「訪問者数」は、ホームページに来た人の数です。同じ人が何回来ても1人とカウントされます。「ページビュー」は、見られたページの合計回数です。1人が3ページ見れば、訪問者数は1、ページビューは3になります。',
            ],
            [
                'q' => '検索キーワードが「（not provided）」と表示されます',
                'a' => 'これはGoogleの仕様で、検索キーワードの多くが非公開になっているためです。みまもりウェブに限らず、どのツールでも見られる現象です。キーワード分析画面では、Googleサーチコンソールのデータを使って、実際に検索された言葉をできるだけ表示しています。',
            ],
        ],
    ],

    [
        'title' => 'Googleマップ（MEO）について',
        'items' => [
            [
                'q' => 'Googleビジネスプロフィールとは何ですか？',
                'a' => 'Googleで店名や会社名を検索したときに、地図と一緒に表示される情報欄のことです。住所・電話番号・営業時間・口コミなどが掲載されます。みまもりウェブでは、このプロフィールのデータを連携して、閲覧数や反応の推移を確認できます。',
            ],
            [
                'q' => '口コミへの返信はどうすればいいですか？',
                'a' => '口コミ管理画面から、いただいた口コミの一覧を確認できます。返信に迷ったときは、AIが返信文の下書きを提案してくれる機能もありますので、それをベースに自分の言葉で調整するのがおすすめです。',
            ],
            [
                'q' => 'MEO機能が表示されません',
                'a' => 'Googleビジネスプロフィールとの連携設定がまだの場合、MEO関連の機能は表示されません。連携設定がお済みかどうか、担当者にご確認ください。',
            ],
        ],
    ],

    [
        'title' => 'AIチャットについて',
        'items' => [
            [
                'q' => 'AIチャットではどんなことが聞けますか？',
                'a' => 'ホームページに関することなら何でも聞けます。たとえば「先月の訪問者数は？」「お問い合わせは増えてる？」「何を改善すればいい？」など、思いついたことをそのまま入力するだけで大丈夫です。画面右下のチャットアイコンからいつでも使えます。',
            ],
            [
                'q' => 'AIの回答は正確ですか？',
                'a' => 'AIはGoogleアナリティクスやサーチコンソールの実際のデータをもとに回答しています。ただし、AIの特性上、解釈や提案の部分はあくまで参考としてお考えください。具体的な数字はデータに基づいていますが、判断に迷う場合は担当者にご相談いただくのが確実です。',
            ],
        ],
    ],

    [
        'title' => 'アカウント・その他',
        'items' => [
            [
                'q' => '利用料金はかかりますか？',
                'a' => 'みまもりウェブのご利用は、ホームページの制作・管理をご契約いただいているお客様向けのサービスです。料金についてはご契約内容によって異なりますので、担当者にお問い合わせください。',
            ],
            [
                'q' => '退会（利用停止）したい場合はどうすればいいですか？',
                'a' => '担当者までご連絡ください。アカウントの停止手続きをいたします。',
            ],
            [
                'q' => 'お問い合わせはどこからできますか？',
                'a' => 'ページ下部の「お問い合わせ」リンク、またはご担当者へ直接ご連絡ください。AIチャットでは解決できない内容や、設定変更のご依頼なども受け付けています。',
            ],
        ],
    ],

];

get_header();
?>

<style>
/* ==============================
   よくある質問 — page-faq.php
   ============================== */

/* --- hero --- */
.faq-hero {
    text-align: center;
    padding: 48px 24px 40px;
    background: linear-gradient(135deg, #f0f7f7 0%, #faf9f6 100%);
    border-radius: 16px;
    margin-bottom: 48px;
    border: 1px solid #e5e7eb;
}
.faq-hero-title {
    font-size: 26px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 12px;
    line-height: 1.4;
}
.faq-hero-lead {
    font-size: 15px;
    color: #475569;
    line-height: 1.8;
    margin: 0 0 28px;
    max-width: 560px;
    margin-left: auto;
    margin-right: auto;
}
.faq-hero-lead strong {
    color: #1e293b;
}

/* --- category nav --- */
.faq-nav {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
}
.faq-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 8px 16px;
    background: #fff;
    color: #568184;
    border: 1px solid #568184;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all .2s;
}
.faq-nav-btn:hover {
    background: #568184;
    color: #fff;
}

/* --- section --- */
.faq-section {
    margin-bottom: 48px;
}
.faq-section-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #568184;
    color: #fff;
    font-size: 13px;
    font-weight: 800;
    margin-right: 10px;
    flex-shrink: 0;
}
.faq-section-title {
    display: flex;
    align-items: center;
    font-size: 20px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 20px;
    line-height: 1.4;
}

/* --- accordion --- */
.faq-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.faq-item {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    transition: box-shadow .2s;
}
.faq-item:hover {
    box-shadow: 0 2px 12px rgba(0,0,0,.04);
}
.faq-question {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 18px 20px;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.5;
    gap: 12px;
    transition: background .15s;
}
.faq-question:hover {
    background: #faf9f6;
}
.faq-q-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 6px;
    background: #568184;
    color: #fff;
    font-size: 13px;
    font-weight: 800;
    flex-shrink: 0;
}
.faq-q-text {
    flex: 1;
}
.faq-q-icon {
    flex-shrink: 0;
    width: 20px;
    height: 20px;
    color: #94a3b8;
    transition: transform .25s;
}
.faq-item.is-open .faq-q-icon {
    transform: rotate(180deg);
}
.faq-answer {
    max-height: 0;
    overflow: hidden;
    transition: max-height .3s ease, padding .3s ease;
}
.faq-item.is-open .faq-answer {
    max-height: 400px;
}
.faq-answer-inner {
    padding: 0 20px 20px 58px;
    font-size: 14px;
    color: #475569;
    line-height: 1.8;
}

/* --- divider --- */
.faq-divider {
    border: none;
    border-top: 1px solid #e5e7eb;
    margin: 48px 0;
}

/* --- contact cta --- */
.faq-cta {
    text-align: center;
    background: #f0f7f7;
    border-radius: 16px;
    padding: 40px 24px;
    margin-bottom: 24px;
}
.faq-cta-title {
    font-size: 18px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 10px;
}
.faq-cta-text {
    font-size: 14px;
    color: #475569;
    line-height: 1.7;
    margin: 0 0 20px;
    max-width: 480px;
    margin-left: auto;
    margin-right: auto;
}
.faq-cta-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
}
.faq-cta-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 12px 24px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    transition: all .2s;
}
.faq-cta-btn--primary {
    background: #568184;
    color: #fff;
    border: 1px solid #568184;
}
.faq-cta-btn--primary:hover {
    background: #476C6F;
    color: #fff;
}
.faq-cta-btn--secondary {
    background: #fff;
    color: #568184;
    border: 1px solid #568184;
}
.faq-cta-btn--secondary:hover {
    background: #f0f7f7;
    color: #476C6F;
}

/* --- responsive --- */
@media (max-width: 768px) {
    .faq-hero {
        padding: 32px 16px;
        margin-bottom: 32px;
    }
    .faq-hero-title {
        font-size: 22px;
    }
    .faq-nav {
        gap: 8px;
    }
    .faq-nav-btn {
        font-size: 12px;
        padding: 6px 12px;
    }
    .faq-question {
        padding: 14px 16px;
        font-size: 14px;
    }
    .faq-answer-inner {
        padding: 0 16px 16px 44px;
        font-size: 13px;
    }
    .faq-cta {
        padding: 32px 16px;
    }
}
</style>

<div class="content-area">

    <!-- hero -->
    <div class="faq-hero">
        <h2 class="faq-hero-title">よくある質問</h2>
        <p class="faq-hero-lead">
            みまもりウェブについて、<strong>よくいただく質問</strong>をまとめました。<br>
            わからないことがあれば、まずこちらをご覧ください。
        </p>
        <nav class="faq-nav">
            <?php foreach ( $faq_categories as $i => $cat ) : ?>
            <a href="#faq-cat-<?php echo $i + 1; ?>" class="faq-nav-btn">
                <?php echo esc_html( $cat['title'] ); ?>
            </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <!-- FAQ sections -->
    <?php foreach ( $faq_categories as $i => $cat ) : ?>
    <?php if ( $i > 0 ) : ?><hr class="faq-divider"><?php endif; ?>
    <div id="faq-cat-<?php echo $i + 1; ?>" class="faq-section">
        <h3 class="faq-section-title">
            <span class="faq-section-num"><?php echo $i + 1; ?></span>
            <?php echo esc_html( $cat['title'] ); ?>
        </h3>
        <div class="faq-list">
            <?php foreach ( $cat['items'] as $item ) : ?>
            <div class="faq-item">
                <button type="button" class="faq-question" aria-expanded="false">
                    <span class="faq-q-badge">Q</span>
                    <span class="faq-q-text"><?php echo esc_html( $item['q'] ); ?></span>
                    <svg class="faq-q-icon" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                    </svg>
                </button>
                <div class="faq-answer">
                    <div class="faq-answer-inner"><?php echo esc_html( $item['a'] ); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <hr class="faq-divider">

    <!-- CTA -->
    <div class="faq-cta">
        <h3 class="faq-cta-title">解決しない場合は</h3>
        <p class="faq-cta-text">
            上の質問で解決しなかった場合は、AIチャットに質問するか、担当者までお気軽にご連絡ください。
        </p>
        <div class="faq-cta-buttons">
            <a href="<?php echo esc_url( home_url( '/tutorials/' ) ); ?>" class="faq-cta-btn faq-cta-btn--secondary">
                使い方ガイドを見る
            </a>
        </div>
    </div>

</div>

<script>
(function() {
    document.querySelectorAll('.faq-question').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var item = this.closest('.faq-item');
            var isOpen = item.classList.contains('is-open');
            item.closest('.faq-list').querySelectorAll('.faq-item.is-open').forEach(function(el) {
                el.classList.remove('is-open');
                el.querySelector('.faq-question').setAttribute('aria-expanded', 'false');
            });
            if (!isOpen) {
                item.classList.add('is-open');
                this.setAttribute('aria-expanded', 'true');
            }
        });
    });
    document.querySelectorAll('.faq-nav-btn').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
})();
</script>

<?php get_footer(); ?>
