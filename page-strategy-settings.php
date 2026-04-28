<?php
/*
Template Name: 戦略設定
*/

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( home_url( '/login/' ) );
    exit;
}

$current_user = wp_get_current_user();
$user_id      = (int) $current_user->ID;

set_query_var( 'gcrev_page_title', '戦略設定' );
set_query_var( 'gcrev_page_subtitle', '貴社の戦略（ターゲット・課題・差別化要素・コンバージョン導線）を登録します。月次の戦略レポート生成に使用されます。' );
set_query_var( 'gcrev_breadcrumb', gcrev_breadcrumb( '戦略設定', 'クライアント戦略の登録' ) );

get_header();
?>

<div class="ss-wrap">

    <div class="ss-header">
        <div class="ss-header__lead">
            <h2 class="ss-header__title">🧭 戦略設定</h2>
            <p class="ss-header__desc">この設定が「戦略連動型 月次レポート」のベースになります。AIは戦略と実際のデータを突き合わせ、ズレと改善アクションを提示します。</p>
        </div>
        <div class="ss-header__actions">
            <button type="button" class="ss-btn ss-btn--ghost" id="ssVersionsBtn">📋 バージョン履歴</button>
        </div>
    </div>

    <div class="ss-status-bar" id="ssStatusBar" hidden>
        <div class="ss-status-bar__inner">
            <span class="ss-status-bar__label">現在の有効戦略</span>
            <strong class="ss-status-bar__value" id="ssActiveLabel">未設定</strong>
        </div>
    </div>

    <form class="ss-form" id="ssForm" autocomplete="off">

        <section class="ss-section">
            <header class="ss-section__head">
                <h3 class="ss-section__title">基本情報</h3>
                <p class="ss-section__hint">この4つは月次レポート生成の最低要件です。</p>
            </header>

            <div class="ss-field">
                <label class="ss-field__label" for="ssClientName">会社名 / ブランド名 <span class="ss-required">必須</span></label>
                <input class="ss-input" type="text" id="ssClientName" name="client_name" placeholder="例: 株式会社みまもり工務店" />
            </div>

            <div class="ss-field">
                <label class="ss-field__label" for="ssEffectiveFrom">有効開始日 <span class="ss-required">必須</span></label>
                <input class="ss-input" type="date" id="ssEffectiveFrom" name="effective_from" />
                <p class="ss-field__hint">この戦略が「いつから有効か」を入力します。月次レポート生成時はこの日付で有効な戦略が選ばれます。</p>
            </div>

            <div class="ss-field">
                <label class="ss-field__label" for="ssTarget">ターゲット顧客 <span class="ss-required">必須</span></label>
                <textarea class="ss-textarea" id="ssTarget" name="target" rows="3" placeholder="例: 30〜50代の戸建て検討層。郊外エリアで土地から探している。"></textarea>
            </div>

            <div class="ss-field">
                <label class="ss-field__label">課題 <span class="ss-required">必須・1件以上</span></label>
                <div class="ss-list" data-list="issues">
                    <div class="ss-list__row">
                        <input class="ss-input" type="text" data-list-input="issues" placeholder="例: 集客はあるが資料請求が伸びない" />
                        <button type="button" class="ss-btn ss-btn--icon" data-list-remove="issues" aria-label="削除">×</button>
                    </div>
                </div>
                <button type="button" class="ss-btn ss-btn--ghost ss-btn--small" data-list-add="issues">+ 課題を追加</button>
            </div>

            <div class="ss-field">
                <label class="ss-field__label" for="ssStrategy">戦略方針 <span class="ss-required">必須</span></label>
                <textarea class="ss-textarea" id="ssStrategy" name="strategy" rows="4" placeholder="例: 完成見学会で「実物を体感する顧客」を獲得し、SNSと併せて記憶想起を強化する。"></textarea>
            </div>

            <div class="ss-field">
                <label class="ss-field__label">差別化要素 / 提供価値 <span class="ss-required">必須・1件以上</span></label>
                <div class="ss-list" data-list="value_proposition">
                    <div class="ss-list__row">
                        <input class="ss-input" type="text" data-list-input="value_proposition" placeholder="例: 自然素材を使った高気密高断熱住宅" />
                        <button type="button" class="ss-btn ss-btn--icon" data-list-remove="value_proposition" aria-label="削除">×</button>
                    </div>
                </div>
                <button type="button" class="ss-btn ss-btn--ghost ss-btn--small" data-list-add="value_proposition">+ 差別化要素を追加</button>
            </div>

            <div class="ss-field">
                <label class="ss-field__label" for="ssConversionPath">コンバージョン導線 <span class="ss-required">必須</span></label>
                <textarea class="ss-textarea" id="ssConversionPath" name="conversion_path" rows="3" placeholder="例: HP訪問 → 施工事例ページ → 資料請求 → 完成見学会予約 → 商談"></textarea>
            </div>
        </section>

        <section class="ss-section">
            <header class="ss-section__head">
                <h3 class="ss-section__title">サイト URL（任意）</h3>
                <p class="ss-section__hint">レポートで参照するメインサイトの URL（任意）。</p>
            </header>
            <div class="ss-field">
                <input class="ss-input" type="url" id="ssSiteUrl" name="site_url" placeholder="https://example.com" />
            </div>
        </section>

        <div class="ss-actions">
            <button type="button" class="ss-btn ss-btn--ghost" id="ssBtnSaveDraft">💾 下書き保存</button>
            <button type="button" class="ss-btn ss-btn--primary" id="ssBtnActivate">🚀 この内容を有効化する</button>
        </div>

        <p class="ss-help-note">
            「下書き保存」は途中の状態を保存します。必須項目が揃ったら「有効化」を押してください。<br>
            有効化すると、現在の active 戦略は自動的に過去版（archived）に降格します。
        </p>

    </form>

    <!-- バージョン履歴モーダル -->
    <div class="ss-modal" id="ssVersionsModal" hidden>
        <div class="ss-modal__backdrop" data-ss-modal-close></div>
        <div class="ss-modal__panel" role="dialog" aria-labelledby="ssVersionsTitle">
            <div class="ss-modal__head">
                <h3 id="ssVersionsTitle" class="ss-modal__title">📋 バージョン履歴</h3>
                <button type="button" class="ss-modal__close" data-ss-modal-close aria-label="閉じる">×</button>
            </div>
            <div class="ss-modal__body" id="ssVersionsBody">
                <p class="ss-empty">読み込み中…</p>
            </div>
        </div>
    </div>

    <!-- トースト -->
    <div class="ss-toast" id="ssToast" hidden></div>

</div>

<?php get_footer(); ?>
