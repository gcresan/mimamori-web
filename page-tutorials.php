<?php
/*
Template Name: 使い方ガイド
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

set_query_var( 'gcrev_page_title', '使い方ガイド' );
set_query_var( 'gcrev_page_subtitle', 'みまもりウェブの各機能の使い方をわかりやすく解説します。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '使い方ガイド', 'サポート・問い合わせ' ) );

/* ---------- helpers ---------- */
$tut_img_dir = get_template_directory() . '/images/tutorials/';
$tut_img_uri = get_template_directory_uri() . '/images/tutorials/';

/**
 * スクリーンショット画像を出力。画像がなければプレースホルダーを表示。
 */
function tut_screenshot( $filename, $alt, $dir, $uri ) {
    if ( file_exists( $dir . $filename ) ) {
        echo '<img src="' . esc_url( $uri . $filename ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" width="800" height="450">';
    } else {
        echo '<div class="tut-img-placeholder"><span>' . esc_html( $alt ) . '</span></div>';
    }
}

/* ---------- capability checks ---------- */
$can_seo  = function_exists( 'mimamori_can_access_seo' ) ? mimamori_can_access_seo() : false;
$can_chat = function_exists( 'mimamori_can' ) ? mimamori_can( 'ai_chat' ) : false;

get_header();
?>

<style>
/* ==============================
   使い方ガイド — page-tutorials.php
   ============================== */

/* --- hero --- */
.tut-hero {
    text-align: center;
    padding: 48px 24px 40px;
    background: linear-gradient(135deg, #f0f7f7 0%, #faf9f6 100%);
    border-radius: 16px;
    margin-bottom: 48px;
    border: 1px solid #e5e7eb;
}
.tut-hero-title {
    font-size: 26px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 12px;
    line-height: 1.4;
}
.tut-hero-lead {
    font-size: 15px;
    color: #475569;
    line-height: 1.8;
    margin: 0 0 28px;
    max-width: 560px;
    margin-left: auto;
    margin-right: auto;
}
.tut-hero-lead strong {
    color: #1e293b;
}
.tut-hero-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
    flex-wrap: wrap;
}
.tut-hero-btn {
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
.tut-hero-btn--primary {
    background: #568184;
    color: #fff;
    border: 1px solid #568184;
}
.tut-hero-btn--primary:hover {
    background: #476C6F;
    color: #fff;
}
.tut-hero-btn--secondary {
    background: #fff;
    color: #568184;
    border: 1px solid #568184;
}
.tut-hero-btn--secondary:hover {
    background: #f0f7f7;
    color: #476C6F;
}

/* --- section --- */
.tut-section {
    margin-bottom: 56px;
}
.tut-section-num {
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
.tut-section-title {
    display: flex;
    align-items: center;
    font-size: 20px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 6px;
    line-height: 1.4;
}
.tut-section-sub {
    font-size: 14px;
    color: #64748b;
    margin: 0 0 24px;
    padding-left: 38px;
}
.tut-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    margin-left: 10px;
    vertical-align: middle;
}
.tut-badge--important {
    background: #fef3c7;
    color: #92400e;
}
.tut-badge--intermediate {
    background: #e0f2fe;
    color: #0369a1;
}

/* --- 4機能の使い方フロー --- */
.tut-flow {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 32px;
}
.tut-flow-step {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 18px 16px;
    text-align: center;
    border-top: 4px solid var(--tut-step-color, #568184);
    position: relative;
}
.tut-flow-cycle-note {
    text-align: center;
    font-size: 13px;
    color: #64748b;
    margin: 0 0 28px;
    padding: 10px 16px;
    background: #f8fafc;
    border-radius: 8px;
    line-height: 1.6;
}
.tut-flow-cycle-note strong {
    color: #568184;
}
.tut-flow-step__num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: var(--tut-step-color, #568184);
    color: #fff;
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 8px;
}
.tut-flow-step__name {
    font-size: 15px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 6px;
    line-height: 1.4;
}
.tut-flow-step__when {
    display: inline-block;
    font-size: 11px;
    color: #fff;
    background: var(--tut-step-color, #568184);
    padding: 2px 8px;
    border-radius: 10px;
    margin-bottom: 8px;
    font-weight: 700;
}
.tut-flow-step__desc {
    font-size: 12px;
    color: #64748b;
    line-height: 1.5;
    margin: 0;
}
.tut-flow-step--dashboard { --tut-step-color: #4A6FA5; }
.tut-flow-step--diagnosis { --tut-step-color: #C97A91; }
.tut-flow-step--actions   { --tut-step-color: #5E9F73; }
.tut-flow-step--report    { --tut-step-color: #B8922E; }

/* --- main cards (dashboard, report) --- */
.tut-main-cards {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
.tut-main-card--dashboard { border-left: 5px solid #4A6FA5; }
.tut-main-card--diagnosis { border-left: 5px solid #C97A91; }
.tut-main-card--actions   { border-left: 5px solid #5E9F73; }
.tut-main-card--report    { border-left: 5px solid #B8922E; }
.tut-main-card-tag {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 10px;
    color: #fff;
    margin-bottom: 8px;
}
.tut-main-card-tag--dashboard { background: #4A6FA5; }
.tut-main-card-tag--diagnosis { background: #C97A91; }
.tut-main-card-tag--actions   { background: #5E9F73; }
.tut-main-card-tag--report    { background: #B8922E; }
.tut-main-card {
    display: flex;
    gap: 28px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 28px;
    transition: box-shadow .2s;
}
.tut-main-card:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,.06);
}
.tut-main-card-img {
    flex: 0 0 52%;
    max-width: 52%;
}
.tut-main-card-img img {
    width: 100%;
    height: auto;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    display: block;
}
.tut-main-card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.tut-main-card-label {
    font-size: 13px;
    font-weight: 700;
    color: #568184;
    margin: 0 0 6px;
    letter-spacing: .03em;
}
.tut-main-card-name {
    font-size: 18px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 12px;
    line-height: 1.4;
}
.tut-main-card-desc {
    font-size: 14px;
    color: #475569;
    line-height: 1.7;
    margin: 0 0 16px;
}
.tut-points {
    list-style: none;
    padding: 0;
    margin: 0 0 16px;
}
.tut-points li {
    font-size: 14px;
    color: #334155;
    padding: 4px 0 4px 20px;
    position: relative;
    line-height: 1.6;
}
.tut-points li::before {
    content: '✓';
    position: absolute;
    left: 0;
    color: #568184;
    font-weight: 700;
}
.tut-tip {
    background: #f0f7f7;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    color: #334155;
    line-height: 1.6;
    margin-bottom: 16px;
}
.tut-tip strong {
    color: #568184;
}
.tut-link-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    background: #568184;
    color: #fff;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    transition: background .2s;
    align-self: flex-start;
}
.tut-link-btn:hover {
    background: #476C6F;
    color: #fff;
}
.tut-link-btn svg {
    width: 14px;
    height: 14px;
}

/* --- grid cards --- */
.tut-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}
.tut-grid--3col {
    grid-template-columns: repeat(3, 1fr);
}
.tut-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    overflow: hidden;
    transition: box-shadow .2s;
    display: flex;
    flex-direction: column;
}
.tut-card:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,.06);
}
.tut-card-img {
    padding: 16px 16px 0;
}
.tut-card-img img {
    width: 100%;
    height: auto;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    display: block;
}
.tut-card-body {
    padding: 16px 20px 20px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.tut-card-name {
    font-size: 15px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 8px;
    line-height: 1.4;
}
.tut-card-desc {
    font-size: 13px;
    color: #475569;
    line-height: 1.65;
    margin: 0 0 14px;
    flex: 1;
}
.tut-card-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    font-weight: 700;
    color: #568184;
    text-decoration: none;
    transition: color .2s;
}
.tut-card-link:hover {
    color: #476C6F;
}

/* --- image placeholder --- */
.tut-img-placeholder {
    background: #f1f5f9;
    aspect-ratio: 16 / 9;
    border-radius: 8px;
    border: 1px dashed #cbd5e1;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    font-size: 13px;
    text-align: center;
    padding: 16px;
}

/* --- chat section --- */
.tut-chat-box {
    display: flex;
    gap: 28px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 32px;
}
.tut-chat-img {
    flex: 0 0 44%;
    max-width: 44%;
}
.tut-chat-img img {
    width: 100%;
    height: auto;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    display: block;
}
.tut-chat-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.tut-chat-body h3 {
    font-size: 18px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 12px;
}
.tut-chat-body p {
    font-size: 14px;
    color: #475569;
    line-height: 1.7;
    margin: 0 0 16px;
}
.tut-examples {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.tut-examples li {
    background: #f0f7f7;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    color: #334155;
    line-height: 1.5;
}
.tut-examples li::before {
    content: '💬 ';
}

/* --- divider --- */
.tut-divider {
    border: none;
    border-top: 1px solid #e5e7eb;
    margin: 48px 0;
}

/* --- responsive --- */
@media (max-width: 768px) {
    .tut-hero {
        padding: 32px 16px;
        margin-bottom: 32px;
    }
    .tut-hero-title {
        font-size: 22px;
    }
    .tut-main-card {
        flex-direction: column;
        padding: 20px;
    }
    .tut-main-card-img {
        flex: none;
        max-width: 100%;
    }
    .tut-grid {
        grid-template-columns: 1fr;
    }
    .tut-grid--3col {
        grid-template-columns: 1fr;
    }
    .tut-flow {
        grid-template-columns: repeat(2, 1fr);
    }
    .tut-chat-box {
        flex-direction: column;
        padding: 20px;
    }
    .tut-chat-img {
        flex: none;
        max-width: 100%;
    }
    .tut-section-sub {
        padding-left: 0;
    }
}
</style>

<div class="content-area">

    <!-- ① ファーストビュー -->
    <div class="tut-hero">
        <h2 class="tut-hero-title">はじめての方へ｜使い方ガイド</h2>
        <p class="tut-hero-lead">
            みまもりウェブは、<strong>ホームページの状態をAIといっしょに見て、直して、ふり返るツール</strong>です。<br>
            難しい数字を読む必要はありません。<br>
            <strong>下の4つの画面を、書かれたリズムで開くだけでOK。</strong>
        </p>
        <div class="tut-hero-buttons">
            <a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>" class="tut-hero-btn tut-hero-btn--primary">
                まずはダッシュボードを開く
            </a>
            <a href="#tut-flow-section" class="tut-hero-btn tut-hero-btn--secondary">
                使い方の流れを見る
            </a>
        </div>
    </div>

    <!-- ② 使い方の流れ（4ステップフロー） -->
    <div class="tut-section" id="tut-flow-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num">1</span>
            使い方の流れ
            <span class="tut-badge tut-badge--important">最重要</span>
        </h3>
        <p class="tut-section-sub">4つの画面を、次のリズムで開くだけ。サイト運営が自然にまわります。</p>

        <div class="tut-flow">
            <div class="tut-flow-step tut-flow-step--dashboard">
                <div class="tut-flow-step__num">1</div>
                <div class="tut-flow-step__name">全体ダッシュボード</div>
                <div class="tut-flow-step__when">毎日・週1</div>
                <p class="tut-flow-step__desc">今のサイトの調子を見る</p>
            </div>
            <div class="tut-flow-step tut-flow-step--diagnosis">
                <div class="tut-flow-step__num">2</div>
                <div class="tut-flow-step__name">現状のページ診断</div>
                <div class="tut-flow-step__when">気になったら</div>
                <p class="tut-flow-step__desc">どこに問題があるか見つける</p>
            </div>
            <div class="tut-flow-step tut-flow-step--actions">
                <div class="tut-flow-step__num">3</div>
                <div class="tut-flow-step__name">改善施策提案</div>
                <div class="tut-flow-step__when">日々すこしずつ</div>
                <p class="tut-flow-step__desc">今月やる施策を実行する</p>
            </div>
            <div class="tut-flow-step tut-flow-step--report">
                <div class="tut-flow-step__num">4</div>
                <div class="tut-flow-step__name">月次レポート</div>
                <div class="tut-flow-step__when">月に1回</div>
                <p class="tut-flow-step__desc">先月の結果をふり返る</p>
            </div>
        </div>

        <p class="tut-flow-cycle-note">
            <strong>見る → 見つける → やる → ふり返る</strong>　この4ステップを毎月くり返すだけで、ホームページがじわじわ育っていきます。
        </p>
    </div>

    <hr class="tut-divider">

    <!-- ③ 4つの画面の詳細 -->
    <div class="tut-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num">2</span>
            それぞれの画面でできること
        </h3>
        <p class="tut-section-sub">4つの画面の役割と、開いたときに何を見ればいいかを解説します。</p>

        <div class="tut-main-cards">

            <!-- 1. 全体ダッシュボード -->
            <div class="tut-main-card tut-main-card--dashboard">
                <div class="tut-main-card-img">
                    <?php tut_screenshot( 'dashboard.png', '全体ダッシュボード画面', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-main-card-body">
                    <span class="tut-main-card-tag tut-main-card-tag--dashboard">STEP 1 ／ 毎日・週1で見る</span>
                    <h4 class="tut-main-card-name">全体ダッシュボード</h4>
                    <p class="tut-main-card-desc">ホームページ全体の「今の健康状態」がひと目でわかる画面です。まずはここを開く習慣から始めましょう。</p>
                    <ul class="tut-points">
                        <li>訪問者やお問い合わせが増えているか減っているかがわかる</li>
                        <li>今月の状況をAIがひとことでまとめてくれる</li>
                        <li>気になった数字はクリックで詳しい分析へ移動できる</li>
                    </ul>
                    <div class="tut-tip">
                        <strong>ポイント：</strong>数字の意味を覚える必要はありません。矢印が上向きか下向きかだけ見ればOKです。
                    </div>
                    <a href="<?php echo esc_url( home_url( '/dashboard/' ) ); ?>" class="tut-link-btn">
                        全体ダッシュボードを開く
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                    </a>
                </div>
            </div>

            <!-- 2. 現状のページ診断 -->
            <div class="tut-main-card tut-main-card--diagnosis">
                <div class="tut-main-card-img">
                    <?php tut_screenshot( 'page-analysis.png', '現状のページ診断画面', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-main-card-body">
                    <span class="tut-main-card-tag tut-main-card-tag--diagnosis">STEP 2 ／ 気になったら見る</span>
                    <h4 class="tut-main-card-name">現状のページ診断</h4>
                    <p class="tut-main-card-desc">主要なページを1つずつAIが診断して、弱点と改善案を教えてくれる画面です。「どこを直せばいいか」がはっきりします。</p>
                    <ul class="tut-points">
                        <li>ページごとに何が問題かがわかる</li>
                        <li>AIが具体的な改善案を提案してくれる</li>
                        <li>トップページやサービス紹介など、重要ページから確認できる</li>
                    </ul>
                    <div class="tut-tip">
                        <strong>ポイント：</strong>「このページ反応が薄いな…」と感じたときに開いてみてください。原因と直し方の両方が見えます。
                    </div>
                    <a href="<?php echo esc_url( home_url( '/page-analysis/' ) ); ?>" class="tut-link-btn">
                        現状のページ診断を開く
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                    </a>
                </div>
            </div>

            <!-- 3. 改善施策提案 -->
            <div class="tut-main-card tut-main-card--actions">
                <div class="tut-main-card-img">
                    <?php tut_screenshot( 'execution-dashboard.png', '改善施策提案画面', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-main-card-body">
                    <span class="tut-main-card-tag tut-main-card-tag--actions">STEP 3 ／ 日々すこしずつ進める</span>
                    <h4 class="tut-main-card-name">改善施策提案</h4>
                    <p class="tut-main-card-desc">今のサイトの状態から、今月取り組むべき具体的な改善施策をAIが提案する画面です。「やることリスト」として使えます。</p>
                    <ul class="tut-points">
                        <li>優先度の高い順に「今月のやること」が並ぶ</li>
                        <li>SEO・お問い合わせ改善・コンテンツなど、タイプ別に絞り込める</li>
                        <li>各施策のねらいと具体的な手順がわかる</li>
                    </ul>
                    <div class="tut-tip">
                        <strong>ポイント：</strong>全部やろうとしなくて大丈夫。上から1〜2個だけ取り組めば十分です。
                    </div>
                    <a href="<?php echo esc_url( home_url( '/execution-dashboard/' ) ); ?>" class="tut-link-btn">
                        改善施策提案を開く
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                    </a>
                </div>
            </div>

            <!-- 4. 月次レポート -->
            <div class="tut-main-card tut-main-card--report">
                <div class="tut-main-card-img">
                    <?php tut_screenshot( 'report.png', '月次レポート画面', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-main-card-body">
                    <span class="tut-main-card-tag tut-main-card-tag--report">STEP 4 ／ 月に1回ふり返る</span>
                    <h4 class="tut-main-card-name">月次レポート</h4>
                    <p class="tut-main-card-desc">毎月自動で作られる「通信簿」。先月の良かった点と改善点、来月やるべきことをAIがまとめてくれます。</p>
                    <ul class="tut-points">
                        <li>先月と比べてどうだったかがわかる</li>
                        <li>「かんたんモード」で難しい言葉なしで読める</li>
                        <li>来月すべきことも自動で提案される</li>
                    </ul>
                    <div class="tut-tip">
                        <strong>ポイント：</strong>月初めに1回開いて読むだけでOK。印刷して社内共有の資料としても使えます。
                    </div>
                    <a href="<?php echo esc_url( home_url( '/report/report-latest/' ) ); ?>" class="tut-link-btn">
                        月次レポートを開く
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                    </a>
                </div>
            </div>

        </div>
    </div>

    <hr class="tut-divider">

    <!-- ④ もう少し知りたい方へ -->
    <div class="tut-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num">3</span>
            もう少し深く知りたい方へ
        </h3>
        <p class="tut-section-sub">全体ダッシュボードで気になった数字を、ジャンル別にもっと深掘りできる画面です。全部見る必要はありません。</p>

        <div class="tut-grid">

            <?php
            $analysis_cards = [
                [
                    'img'  => 'analysis-source.png',
                    'name' => 'どこから人が来てるの？',
                    'desc' => '検索・Googleマップ・SNSなど、お客様がどこからホームページに来たかがわかります。',
                    'url'  => '/analysis/analysis-source/',
                    'link' => '見つけたきっかけを見る',
                ],
                [
                    'img'  => 'analysis-pages.png',
                    'name' => 'どのページが人気？',
                    'desc' => 'よく見られているページがランキング形式で表示されます。人気のページがひと目でわかります。',
                    'url'  => '/analysis/analysis-pages/',
                    'link' => 'ページ分析を見る',
                ],
                [
                    'img'  => 'analysis-keywords.png',
                    'name' => 'どんな言葉で検索された？',
                    'desc' => 'お客様がGoogleで実際に入力した検索キーワードがわかります。',
                    'url'  => '/analysis/analysis-keywords/',
                    'link' => 'キーワードを見る',
                ],
                [
                    'img'  => 'analysis-device.png',
                    'name' => 'スマホとPCの割合',
                    'desc' => 'スマホで見ている人とパソコンで見ている人の割合がわかります。',
                    'url'  => '/analysis/analysis-device/',
                    'link' => 'デバイス割合を見る',
                ],
                [
                    'img'  => 'analysis-cv.png',
                    'name' => 'お問い合わせの数',
                    'desc' => '電話・フォーム・LINEなど、実際にあった反応の数が確認できます。',
                    'url'  => '/analysis/analysis-cv/',
                    'link' => 'ゴール分析を見る',
                ],
            ];
            foreach ( $analysis_cards as $card ) : ?>
            <div class="tut-card">
                <div class="tut-card-img">
                    <?php tut_screenshot( $card['img'], $card['name'], $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-card-body">
                    <h4 class="tut-card-name"><?php echo esc_html( $card['name'] ); ?></h4>
                    <p class="tut-card-desc"><?php echo esc_html( $card['desc'] ); ?></p>
                    <a href="<?php echo esc_url( home_url( $card['url'] ) ); ?>" class="tut-card-link">
                        <?php echo esc_html( $card['link'] ); ?> →
                    </a>
                </div>
            </div>
            <?php endforeach; ?>

        </div>
    </div>

    <hr class="tut-divider">

    <!-- ⑤ Googleマップ（MEO）を使っている方へ -->
    <div class="tut-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num">4</span>
            Googleマップを使っている方へ
        </h3>
        <p class="tut-section-sub">Googleビジネスプロフィールを連携している方向けの機能です。</p>

        <div class="tut-grid tut-grid--3col">

            <?php
            $meo_cards = [
                [
                    'img'  => 'meo-dashboard.png',
                    'name' => 'MEOダッシュボード',
                    'desc' => 'Googleマップからどれくらいのお客様が来ているかがわかります。',
                    'url'  => '/meo/meo-dashboard/',
                    'link' => 'MEOダッシュボードを見る',
                ],
                [
                    'img'  => 'gbp-posts.png',
                    'name' => '投稿管理',
                    'desc' => 'お知らせやキャンペーン情報をGoogleマップに投稿できます。',
                    'url'  => '/gbp-posts/',
                    'link' => '投稿管理を見る',
                ],
                [
                    'img'  => 'review-management.png',
                    'name' => '口コミ管理',
                    'desc' => 'Googleの口コミを一覧で確認。AIが返信文を考えてくれます。',
                    'url'  => '/review-management/',
                    'link' => '口コミ管理を見る',
                ],
            ];
            foreach ( $meo_cards as $card ) : ?>
            <div class="tut-card">
                <div class="tut-card-img">
                    <?php tut_screenshot( $card['img'], $card['name'], $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-card-body">
                    <h4 class="tut-card-name"><?php echo esc_html( $card['name'] ); ?></h4>
                    <p class="tut-card-desc"><?php echo esc_html( $card['desc'] ); ?></p>
                    <a href="<?php echo esc_url( home_url( $card['url'] ) ); ?>" class="tut-card-link">
                        <?php echo esc_html( $card['link'] ); ?> →
                    </a>
                </div>
            </div>
            <?php endforeach; ?>

        </div>
    </div>

    <?php if ( $can_seo ) : ?>
    <hr class="tut-divider">

    <!-- ⑥ コンテンツを作りたい方へ -->
    <div class="tut-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num">5</span>
            コンテンツを作りたい方へ
            <span class="tut-badge tut-badge--intermediate">中級者向け</span>
        </h3>
        <p class="tut-section-sub">ブログ記事やSEO対策など、ホームページを積極的に改善したい方向けの機能です。</p>

        <div class="tut-grid tut-grid--3col">

            <?php
            $seo_cards = [
                [
                    'img'  => 'seo-check.png',
                    'name' => 'SEO診断',
                    'desc' => 'ホームページのSEO（検索対策）の状態をチェックし、改善点を見つけます。',
                    'url'  => '/seo-check/',
                    'link' => 'SEO診断を見る',
                ],
                [
                    'img'  => 'keyword-research.png',
                    'name' => 'キーワード調査',
                    'desc' => 'どんな言葉で記事を書けばお客様に届くか、AIが提案してくれます。',
                    'url'  => '/keyword-research/',
                    'link' => 'キーワード調査を見る',
                ],
                [
                    'img'  => 'writing.png',
                    'name' => 'ライティング',
                    'desc' => 'キーワードを選ぶだけで、AIがブログ記事の下書きを作成します。',
                    'url'  => '/writing/',
                    'link' => 'ライティングを見る',
                ],
            ];
            foreach ( $seo_cards as $card ) : ?>
            <div class="tut-card">
                <div class="tut-card-img">
                    <?php tut_screenshot( $card['img'], $card['name'], $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-card-body">
                    <h4 class="tut-card-name"><?php echo esc_html( $card['name'] ); ?></h4>
                    <p class="tut-card-desc"><?php echo esc_html( $card['desc'] ); ?></p>
                    <a href="<?php echo esc_url( home_url( $card['url'] ) ); ?>" class="tut-card-link">
                        <?php echo esc_html( $card['link'] ); ?> →
                    </a>
                </div>
            </div>
            <?php endforeach; ?>

        </div>
    </div>
    <?php endif; ?>

    <?php if ( $can_chat ) : ?>
    <hr class="tut-divider">

    <!-- ⑦ AIチャットの案内 -->
    <div class="tut-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num"><?php echo $can_seo ? '6' : '5'; ?></span>
            わからないことはAIに聞いてみよう
        </h3>
        <p class="tut-section-sub">画面右下のチャットに話しかけるだけで、AIがすぐに答えてくれます。どの画面からでも呼び出せます。</p>

        <div class="tut-chat-box">
            <div class="tut-chat-img">
                <?php tut_screenshot( 'ai-chat.png', 'AIチャット画面', $tut_img_dir, $tut_img_uri ); ?>
            </div>
            <div class="tut-chat-body">
                <h3>こんなことが聞けます</h3>
                <p>難しい画面を読む必要はありません。<br>知りたいことをそのまま入力するだけでOKです。</p>
                <ul class="tut-examples">
                    <li>「先月の訪問者数は？」</li>
                    <li>「何を改善すればいい？」</li>
                    <li>「お問い合わせは増えてる？」</li>
                    <li>「スマホからのアクセスは？」</li>
                </ul>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php get_footer(); ?>
