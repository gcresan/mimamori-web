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
$view_uid           = function_exists( 'mimamori_get_view_user_id' ) ? mimamori_get_view_user_id() : get_current_user_id();
$can_meo            = function_exists( 'mimamori_can_access_meo' ) ? mimamori_can_access_meo() : false;
$can_seo            = function_exists( 'mimamori_can_access_seo' ) ? mimamori_can_access_seo() : false;
$can_chat           = function_exists( 'mimamori_can' ) ? mimamori_can( 'ai_chat' ) : false;
$can_aio            = function_exists( 'mimamori_aio_enabled' ) ? mimamori_aio_enabled() : false;
$show_chatbot       = current_user_can( 'manage_options' )
    || ( function_exists( 'mimamori_bot_is_enabled_for_user' ) && mimamori_bot_is_enabled_for_user( $view_uid ) );
$show_page_analysis = current_user_can( 'manage_options' )
    || ( function_exists( 'mimamori_page_analysis_is_enabled_for_user' ) && mimamori_page_analysis_is_enabled_for_user( $view_uid ) );

$has_options = ( $show_page_analysis || $show_chatbot );

/* セクション番号 */
$sec = 0;
$sec_num = function() use ( &$sec ) { return ++$sec; };

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
    line-height: 1.9;
    margin: 0 0 28px;
    max-width: none;
    text-align: center;
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
    flex-wrap: wrap;
    gap: 8px;
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
    vertical-align: middle;
}
.tut-badge--important {
    background: #fef3c7;
    color: #92400e;
}
.tut-badge--plan {
    background: #dcfce7;
    color: #166534;
}
.tut-badge--option {
    background: #ede9fe;
    color: #6b21a8;
}

/* --- 3ステップフロー --- */
.tut-flow {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}
.tut-flow-step {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px 18px;
    text-align: center;
    border-top: 4px solid var(--tut-step-color, #568184);
    position: relative;
}
.tut-flow-cycle-note {
    text-align: center;
    font-size: 13px;
    color: #64748b;
    margin: 0;
    padding: 12px 16px;
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
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--tut-step-color, #568184);
    color: #fff;
    font-size: 13px;
    font-weight: 800;
    margin-bottom: 10px;
}
.tut-flow-step__name {
    font-size: 32px;
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
    padding: 2px 10px;
    border-radius: 10px;
    margin-bottom: 10px;
    font-weight: 700;
}
.tut-flow-step__desc {
    font-size: 13px;
    color: #64748b;
    line-height: 1.5;
    margin: 0;
}
.tut-flow-step--see    { --tut-step-color: #4A6FA5; }
.tut-flow-step--dive   { --tut-step-color: #7B5E97; }
.tut-flow-step--review { --tut-step-color: #B8922E; }

/* --- main cards (basic 4 features) --- */
.tut-main-cards {
    display: flex;
    flex-direction: column;
    gap: 24px;
}
.tut-main-card--dashboard { border-left: 5px solid #4A6FA5; }
.tut-main-card--analysis  { border-left: 5px solid #7B5E97; }
.tut-main-card--report    { border-left: 5px solid #B8922E; }
.tut-main-card--chat      { border-left: 5px solid #568184; }
.tut-main-card-tag {
    display: inline-block;
    font-size: 18px;
    font-weight: 800;
    padding: 5px 14px;
    border-radius: 12px;
    color: #fff;
    margin-bottom: 12px;
    letter-spacing: 0.02em;
}
.tut-main-card-tag--dashboard { background: #4A6FA5; }
.tut-main-card-tag--analysis  { background: #7B5E97; }
.tut-main-card-tag--report    { background: #B8922E; }
.tut-main-card-tag--chat      { background: #568184; }
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
.tut-link-btn--ghost {
    background: #fff;
    color: #568184;
    border: 1px solid #568184;
}
.tut-link-btn--ghost:hover {
    background: #f0f7f7;
    color: #476C6F;
}
.tut-link-btn svg {
    width: 14px;
    height: 14px;
}

/* --- sub-group (MEO/SEO 内のグループ見出し) --- */
.tut-subgroup {
    margin-bottom: 32px;
}
.tut-subgroup:last-child {
    margin-bottom: 0;
}
.tut-subgroup-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 16px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 6px;
    padding-left: 38px;
}
.tut-subgroup-title__icon {
    font-size: 18px;
}
.tut-subgroup-desc {
    font-size: 13px;
    color: #64748b;
    margin: 0 0 16px;
    padding-left: 38px;
}

/* --- grid cards --- */
.tut-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    padding-left: 38px;
}
.tut-grid--3col {
    grid-template-columns: repeat(3, 1fr);
}
.tut-grid--4col {
    grid-template-columns: repeat(4, 1fr);
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
    padding: 14px 14px 0;
}
.tut-card-img img {
    width: 100%;
    height: auto;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    display: block;
}
.tut-card-body {
    padding: 14px 18px 18px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.tut-card-name {
    font-size: 14px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 6px;
    line-height: 1.4;
}
.tut-card-desc {
    font-size: 12.5px;
    color: #475569;
    line-height: 1.6;
    margin: 0 0 12px;
    flex: 1;
}
.tut-card-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12.5px;
    font-weight: 700;
    color: #568184;
    text-decoration: none;
    transition: color .2s;
}
.tut-card-link:hover {
    color: #476C6F;
}

/* --- 深掘りレポート の単体カード --- */
.tut-strategy-card {
    display: flex;
    gap: 28px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-left: 5px solid #5E9F73;
    border-radius: 16px;
    padding: 24px;
    margin-left: 38px;
}
.tut-strategy-card-img {
    flex: 0 0 38%;
    max-width: 38%;
}
.tut-strategy-card-img img {
    width: 100%;
    height: auto;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
    display: block;
}
.tut-strategy-card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.tut-strategy-card-body h4 {
    font-size: 17px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 10px;
}
.tut-strategy-card-body p {
    font-size: 14px;
    color: #475569;
    line-height: 1.7;
    margin: 0 0 14px;
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
    margin-bottom: 24px;
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

/* --- support links --- */
.tut-support {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.tut-support-card {
    display: flex;
    align-items: center;
    gap: 16px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px 24px;
    text-decoration: none;
    transition: box-shadow .2s, transform .2s;
}
.tut-support-card:hover {
    box-shadow: 0 4px 16px rgba(0,0,0,.06);
    transform: translateY(-1px);
}
.tut-support-card__icon {
    flex: 0 0 44px;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #f0f7f7;
    color: #568184;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}
.tut-support-card__body {
    flex: 1;
}
.tut-support-card__name {
    font-size: 15px;
    font-weight: 800;
    color: #1e293b;
    margin: 0 0 2px;
}
.tut-support-card__desc {
    font-size: 12.5px;
    color: #64748b;
    margin: 0;
    line-height: 1.5;
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
    .tut-grid,
    .tut-grid--3col,
    .tut-grid--4col {
        grid-template-columns: 1fr;
        padding-left: 0;
    }
    .tut-flow {
        grid-template-columns: 1fr;
    }
    .tut-main-card-tag {
        font-size: 16px;
        padding: 4px 12px;
        border-radius: 10px;
    }
    .tut-chat-box {
        flex-direction: column;
        padding: 20px;
    }
    .tut-chat-img {
        flex: none;
        max-width: 100%;
    }
    .tut-section-sub,
    .tut-subgroup-title,
    .tut-subgroup-desc {
        padding-left: 0;
    }
    .tut-strategy-card {
        flex-direction: column;
        margin-left: 0;
        padding: 20px;
    }
    .tut-strategy-card-img {
        flex: none;
        max-width: 100%;
    }
    .tut-support {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="content-area">

    <!-- ① ヒーロー -->
    <div class="tut-hero">
        <h2 class="tut-hero-title">初めての方へ</h2>
        <p class="tut-hero-lead">
            みまもりウェブは、<strong>ホームページとGoogleマップの状態をAIといっしょに見て、ふり返るツール</strong>です。<br>
            難しい数字を覚える必要はありません。<br>
            <strong>「見る → 深掘る → ふり返る」</strong>の3ステップをくり返すだけで、サイトがじわじわ育っていきます。
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

    <!-- ② 使い方の3ステップ -->
    <div class="tut-section" id="tut-flow-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num"><?php echo $sec_num(); ?></span>
            使い方の流れ
            <span class="tut-badge tut-badge--important">最重要</span>
        </h3>
        <p class="tut-section-sub">3つのリズムをくり返すだけ。サイト運営が自然にまわります。</p>

        <div class="tut-flow">
            <div class="tut-flow-step tut-flow-step--see">
                <div class="tut-flow-step__num">1</div>
                <div class="tut-flow-step__name">見る</div>
                <div class="tut-flow-step__when">毎日・週1</div>
                <p class="tut-flow-step__desc">全体ダッシュボードで<br>今のサイトの調子をチェック</p>
            </div>
            <div class="tut-flow-step tut-flow-step--dive">
                <div class="tut-flow-step__num">2</div>
                <div class="tut-flow-step__name">深掘る</div>
                <div class="tut-flow-step__when">気になったら</div>
                <p class="tut-flow-step__desc">サイト分析・SEO・MEOで<br>原因や改善点を探す</p>
            </div>
            <div class="tut-flow-step tut-flow-step--review">
                <div class="tut-flow-step__num">3</div>
                <div class="tut-flow-step__name">ふり返る</div>
                <div class="tut-flow-step__when">月に1回</div>
                <p class="tut-flow-step__desc">月次レポートで<br>先月の結果をふり返る</p>
            </div>
        </div>

        <p class="tut-flow-cycle-note">
            <strong>見る → 深掘る → ふり返る</strong>　この3ステップを毎月くり返すだけでOK。難しい数字を読む必要はありません。
        </p>
    </div>

    <hr class="tut-divider">

    <!-- ③ どのプランでも使える基本機能 -->
    <div class="tut-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num"><?php echo $sec_num(); ?></span>
            まずおさえたい基本の4機能
        </h3>
        <p class="tut-section-sub">どのプランでも使える、いちばん大切な4つの画面です。</p>

        <div class="tut-main-cards">

            <!-- 1. 全体ダッシュボード -->
            <div class="tut-main-card tut-main-card--dashboard">
                <div class="tut-main-card-img">
                    <?php tut_screenshot( 'dashboard.png', '全体ダッシュボード画面', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-main-card-body">
                    <span class="tut-main-card-tag tut-main-card-tag--dashboard">毎日・週1で見る</span>
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

            <!-- 2. サイト分析 -->
            <div class="tut-main-card tut-main-card--analysis">
                <div class="tut-main-card-img">
                    <?php tut_screenshot( 'site-dashboard.png', 'サイト分析画面', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-main-card-body">
                    <span class="tut-main-card-tag tut-main-card-tag--analysis">気になったら見る</span>
                    <h4 class="tut-main-card-name">サイト分析</h4>
                    <p class="tut-main-card-desc">「どこから人が来てるか」「どのページが人気か」など、ホームページの詳しい状況を見られる画面です。</p>
                    <ul class="tut-points">
                        <li>訪問者の年代や使っているデバイスがわかる</li>
                        <li>検索キーワードや流入元のランキングが見られる</li>
                        <li>お問い合わせの数や経路を追える</li>
                    </ul>
                    <div class="tut-tip">
                        <strong>ポイント：</strong>全部見る必要はありません。ダッシュボードで気になった数字だけ深掘りすればOK。
                    </div>
                    <a href="<?php echo esc_url( home_url( '/site-dashboard/' ) ); ?>" class="tut-link-btn">
                        サイト分析を開く
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                    </a>
                </div>
            </div>

            <!-- 3. 月次レポート -->
            <div class="tut-main-card tut-main-card--report">
                <div class="tut-main-card-img">
                    <?php tut_screenshot( 'report.png', '月次レポート画面', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-main-card-body">
                    <span class="tut-main-card-tag tut-main-card-tag--report">月に1回ふり返る</span>
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

            <!-- 4. AIチャット相談 -->
            <?php if ( $can_chat ) : ?>
            <div class="tut-main-card tut-main-card--chat">
                <div class="tut-main-card-img">
                    <?php tut_screenshot( 'ai-chat.png', 'AIチャット画面', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-main-card-body">
                    <span class="tut-main-card-tag tut-main-card-tag--chat">いつでも相談</span>
                    <h4 class="tut-main-card-name">AIチャット相談</h4>
                    <p class="tut-main-card-desc">画面右下のチャットに話しかけるだけで、AIがすぐに答えてくれます。どの画面からでも呼び出せます。</p>
                    <ul class="tut-points">
                        <li>難しい画面を読まずに知りたいことを聞ける</li>
                        <li>「先月の訪問者は？」のような会話で答えてくれる</li>
                        <li>音声入力にも対応</li>
                    </ul>
                    <div class="tut-tip">
                        <strong>ポイント：</strong>専門用語を覚えなくてOK。普段の言葉でそのまま聞いてください。
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <?php if ( $can_meo || $can_seo ) : ?>
    <hr class="tut-divider">

    <!-- ④ MEO・SEO・深掘りレポート -->
    <div class="tut-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num"><?php echo $sec_num(); ?></span>
            集客を伸ばすための機能
            <span class="tut-badge tut-badge--plan">MEO・検索集客強化プラン以上</span>
        </h3>
        <p class="tut-section-sub">Googleマップと検索からの集客を強化するための機能群です。</p>

        <?php if ( $can_meo ) : ?>
        <!-- MEOグループ -->
        <div class="tut-subgroup">
            <h4 class="tut-subgroup-title">
                <span class="tut-subgroup-title__icon">🗺️</span>
                MEO（地域からの集客）
            </h4>
            <p class="tut-subgroup-desc">Googleマップ・口コミ・GBP投稿で地域のお客様を集めます。</p>

            <div class="tut-grid tut-grid--4col">
                <?php
                $meo_cards = [
                    [ 'img' => 'meo-dashboard.png',   'name' => 'MEOダッシュボード', 'desc' => 'Googleマップ経由のアクセス・電話・経路検索を一覧で確認できます。', 'url' => '/meo/meo-dashboard/' ],
                    [ 'img' => 'map-rank.png',        'name' => 'マップ順位',        'desc' => '指定したキーワードでマップ内の順位が今どの位置にあるかを計測します。', 'url' => '/map-rank/' ],
                    [ 'img' => 'meo-diagnosis.png',   'name' => 'MEO診断',           'desc' => 'プロフィールの完成度や口コミ状況をAIが診断し、改善点を教えてくれます。', 'url' => '/meo-diagnosis/' ],
                    [ 'img' => 'meo-search-terms.png','name' => '検索語句分析',      'desc' => '実際にお客様がGoogleマップで入力した検索語句がわかります。', 'url' => '/meo/meo-search-terms/' ],
                    [ 'img' => 'meo-report.png',      'name' => 'MEOレポート',       'desc' => 'MEO関連の数字を月次でまとめたレポート。改善の手応えを確認できます。', 'url' => '/meo-report/' ],
                    [ 'img' => 'review-survey.png',   'name' => '口コミアンケート',  'desc' => 'お客様にアンケートを送って、自然な口コミ投稿につなげます。', 'url' => '/tools/review-survey/' ],
                    [ 'img' => 'review-management.png','name' => '口コミ管理',       'desc' => 'Googleの口コミを一覧で確認。AIが返信文を考えてくれます。', 'url' => '/review-management/' ],
                    [ 'img' => 'gbp-posts.png',       'name' => '投稿管理',          'desc' => 'お知らせやキャンペーン情報をGoogleマップに投稿できます。', 'url' => '/gbp-posts/' ],
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
                            開く →
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( $can_seo ) : ?>
        <!-- SEOグループ -->
        <div class="tut-subgroup">
            <h4 class="tut-subgroup-title">
                <span class="tut-subgroup-title__icon">🔍</span>
                SEO（検索からの集客）
            </h4>
            <p class="tut-subgroup-desc">Google検索とAI検索（ChatGPT等）からの流入を増やすための機能です。</p>

            <div class="tut-grid <?php echo $can_aio ? 'tut-grid--4col' : 'tut-grid--3col'; ?>">
                <?php
                $seo_cards = [
                    [ 'img' => 'seo-check.png',        'name' => 'SEO診断',       'desc' => 'ホームページのSEO（検索対策）の状態をチェックし、改善点を見つけます。', 'url' => '/seo-check/', 'show' => true ],
                    [ 'img' => 'ai-report.png',        'name' => 'AIO診断',       'desc' => 'ChatGPTなどのAI検索に自社サイトがどう扱われているかを診断します。', 'url' => '/ai-report/', 'show' => $can_aio ],
                    [ 'img' => 'rank-tracker.png',     'name' => '自然検索順位',  'desc' => '計測キーワードのGoogle検索順位を毎日記録し、推移をグラフで確認できます。', 'url' => '/rank-tracker/', 'show' => true ],
                    [ 'img' => 'keyword-research.png', 'name' => 'キーワード調査','desc' => 'どんな言葉で記事を書けばお客様に届くか、AIが提案してくれます。', 'url' => '/keyword-research/', 'show' => true ],
                ];
                foreach ( $seo_cards as $card ) :
                    if ( ! $card['show'] ) continue;
                ?>
                <div class="tut-card">
                    <div class="tut-card-img">
                        <?php tut_screenshot( $card['img'], $card['name'], $tut_img_dir, $tut_img_uri ); ?>
                    </div>
                    <div class="tut-card-body">
                        <h4 class="tut-card-name"><?php echo esc_html( $card['name'] ); ?></h4>
                        <p class="tut-card-desc"><?php echo esc_html( $card['desc'] ); ?></p>
                        <a href="<?php echo esc_url( home_url( $card['url'] ) ); ?>" class="tut-card-link">
                            開く →
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 深掘りレポート -->
        <div class="tut-subgroup">
            <h4 class="tut-subgroup-title">
                <span class="tut-subgroup-title__icon">📊</span>
                深掘りレポート（年3回まで）
            </h4>
            <p class="tut-subgroup-desc">ご都合のよいタイミングでご依頼ください。上級ウェブ解析士が監修した戦略レポートを年3回までお届けします。</p>

            <div class="tut-strategy-card">
                <div class="tut-strategy-card-img">
                    <?php tut_screenshot( 'strategy-report.png', '深掘りレポート画面', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-strategy-card-body">
                    <h4>AI × 上級ウェブ解析士による戦略レポート</h4>
                    <p>月次レポートが「先月どうだった？」のふり返りなのに対し、深掘りレポートは「今後どう動くべきか？」を提案する戦略レポートです。<strong>AIによるデータ分析を上級ウェブ解析士が監修</strong>し、その視点をもとに<strong>AIがさらに深く掘り下げて</strong>、サイト全体の改善方針をご提案します。お客様のご都合に合わせて年3回までご依頼いただけます。</p>
                    <a href="<?php echo esc_url( home_url( '/strategy-report-history/' ) ); ?>" class="tut-link-btn">
                        深掘りレポートを見る
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( $has_options ) : ?>
    <hr class="tut-divider">

    <!-- ⑤ オプション機能 -->
    <div class="tut-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num"><?php echo $sec_num(); ?></span>
            オプション機能
            <span class="tut-badge tut-badge--option">追加契約者向け</span>
        </h3>
        <p class="tut-section-sub">追加でご契約いただいている方が使える機能です。</p>

        <div class="tut-grid">
            <?php if ( $show_page_analysis ) : ?>
            <div class="tut-card">
                <div class="tut-card-img">
                    <?php tut_screenshot( 'page-analysis.png', '現状のページ診断', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-card-body">
                    <h4 class="tut-card-name">現状のページ診断</h4>
                    <p class="tut-card-desc">主要なページを1つずつAIが診断して、弱点と具体的な改善案を提示します。「どこを直せばいいか」がはっきりします。</p>
                    <a href="<?php echo esc_url( home_url( '/page-analysis/' ) ); ?>" class="tut-card-link">
                        現状のページ診断を開く →
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $show_chatbot ) : ?>
            <div class="tut-card">
                <div class="tut-card-img">
                    <?php tut_screenshot( 'chatbot.png', 'チャットボット', $tut_img_dir, $tut_img_uri ); ?>
                </div>
                <div class="tut-card-body">
                    <h4 class="tut-card-name">チャットボット</h4>
                    <p class="tut-card-desc">あなたのホームページにAIチャットボットを設置し、24時間お客様の質問に対応できるようにします。</p>
                    <a href="<?php echo esc_url( home_url( '/chatbot/' ) ); ?>" class="tut-card-link">
                        チャットボットを開く →
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <hr class="tut-divider">

    <!-- ⑥ わからないときは -->
    <div class="tut-section">
        <h3 class="tut-section-title">
            <span class="tut-section-num"><?php echo $sec_num(); ?></span>
            わからないときは
        </h3>
        <p class="tut-section-sub">使い方で迷ったら、まずAIチャットに聞いてみてください。それでも解決しない場合は下記からどうぞ。</p>

        <?php if ( $can_chat ) : ?>
        <div class="tut-chat-box">
            <div class="tut-chat-img">
                <?php tut_screenshot( 'ai-chat.png', 'AIチャット画面', $tut_img_dir, $tut_img_uri ); ?>
            </div>
            <div class="tut-chat-body">
                <h3>AIチャットに聞いてみる</h3>
                <p>画面右下のチャットに、知りたいことをそのまま入力するだけ。難しい画面を読む必要はありません。</p>
                <ul class="tut-examples">
                    <li>「先月の訪問者数は？」</li>
                    <li>「何を改善すればいい？」</li>
                    <li>「お問い合わせは増えてる？」</li>
                    <li>「スマホからのアクセスは？」</li>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <div class="tut-support">
            <a href="<?php echo esc_url( home_url( '/faq/' ) ); ?>" class="tut-support-card">
                <div class="tut-support-card__icon">❓</div>
                <div class="tut-support-card__body">
                    <h4 class="tut-support-card__name">よくある質問</h4>
                    <p class="tut-support-card__desc">他の方からよくいただく質問と回答をまとめています。</p>
                </div>
            </a>
            <a href="<?php echo esc_url( home_url( '/inquiry/' ) ); ?>" class="tut-support-card">
                <div class="tut-support-card__icon">✉️</div>
                <div class="tut-support-card__body">
                    <h4 class="tut-support-card__name">問い合わせ</h4>
                    <p class="tut-support-card__desc">担当者に直接ご相談いただけます。</p>
                </div>
            </a>
        </div>
    </div>

</div>

<?php get_footer(); ?>
