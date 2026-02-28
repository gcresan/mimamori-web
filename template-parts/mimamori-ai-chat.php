<?php
/**
 * AIチャット UIコンポーネント
 *
 * 会話本体は1つの共通コンポーネント。
 * CSS クラス切替で closed / normal / panel / modal を表現。
 *
 * @package GCREV_Insight
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="mw-chat" class="mw-chat mw-chat--closed">

  <!-- FAB -->
  <button type="button" class="mw-chat-fab" aria-label="<?php echo esc_attr( 'AIチャットを開く' ); ?>">
    <span class="mw-chat-fab__icon" aria-hidden="true">&#x1F916;</span>
    <span class="mw-chat-fab__text"><?php echo esc_html( 'AI相談' ); ?></span>
    <span class="mw-chat-fab__badge" aria-hidden="true"></span>
  </button>

  <!-- Overlay (panel / modal backdrop) -->
  <div class="mw-chat-overlay" aria-hidden="true"></div>

  <!-- Chat Window (single instance — layout controlled by parent class) -->
  <div class="mw-chat-window" role="dialog" aria-label="<?php echo esc_attr( 'AIチャット' ); ?>">

    <!-- Header -->
    <div class="mw-chat-header">
      <div class="mw-chat-header__top">
        <div class="mw-chat-header__title">
          <span aria-hidden="true">&#x1F916;</span>
          <span><?php echo esc_html( 'みまもりAI' ); ?></span>
        </div>
        <div class="mw-chat-header__actions">
          <button type="button"
                  class="mw-chat-header__btn mw-chat-header__btn--expand"
                  title="<?php echo esc_attr( '拡大表示' ); ?>"
                  aria-label="<?php echo esc_attr( '拡大表示' ); ?>">&#x2922;</button>
          <button type="button"
                  class="mw-chat-header__btn mw-chat-header__btn--collapse"
                  title="<?php echo esc_attr( '通常サイズに戻す' ); ?>"
                  aria-label="<?php echo esc_attr( '通常サイズに戻す' ); ?>">&#x2921;</button>
          <button type="button"
                  class="mw-chat-header__btn mw-chat-header__btn--close"
                  title="<?php echo esc_attr( '閉じる' ); ?>"
                  aria-label="<?php echo esc_attr( '閉じる' ); ?>">&#x2715;</button>
        </div>
      </div>
      <div class="mw-chat-header__subtitle"><?php echo esc_html( '今どうなっていて、次に何をすればいいかをお伝えします' ); ?></div>
      <div class="mw-chat-header__status">
        <span class="mw-chat-header__status-dot" aria-hidden="true"></span>
        <span><?php echo esc_html( '相談受付中' ); ?></span>
      </div>
    </div>

    <!-- Quick Questions — 2モード×3カテゴリ（JSで動的描画） -->
    <div class="mw-chat-quick">
      <div class="mw-chat-quick__mode-tabs">
        <button type="button" class="mw-chat-quick__mode-tab"
                data-mode="beginner"><?php echo esc_html( '初心者向け' ); ?></button>
        <button type="button" class="mw-chat-quick__mode-tab"
                data-mode="standard"><?php echo esc_html( '通常' ); ?></button>
      </div>
      <div class="mw-chat-quick__cat-tabs">
        <button type="button" class="mw-chat-quick__cat-tab"
                data-cat="status"><?php echo esc_html( '今どうなってる？' ); ?></button>
        <button type="button" class="mw-chat-quick__cat-tab"
                data-cat="action"><?php echo esc_html( '次に何する？' ); ?></button>
        <button type="button" class="mw-chat-quick__cat-tab"
                data-cat="trouble"><?php echo esc_html( 'うまくいかない…' ); ?></button>
      </div>
      <div class="mw-chat-quick__chips" id="mwChatQuickChips"></div>
    </div>

    <!-- Messages -->
    <div class="mw-chat-messages">
      <div class="mw-chat-welcome">
        <div class="mw-chat-welcome__icon" aria-hidden="true">&#x1F44B;</div>
        <div class="mw-chat-welcome__title"><?php echo esc_html( 'こんにちは！' ); ?></div>
        <div class="mw-chat-welcome__text">
          <?php echo esc_html( '今月のアクセス状況や「次に何をすればいいか」を' ); ?><br>
          <?php echo esc_html( '一緒に整理するためのAIです。' ); ?>
        </div>
        <div class="mw-chat-welcome__hint">
          <?php echo esc_html( "\xE2\x86\x91 まずは「今月やることを3つ」を押してみてください" ); ?>
        </div>
      </div>
    </div>

    <!-- Input Area -->
    <div class="mw-chat-input">
      <div class="mw-chat-input__row">
        <textarea
          class="mw-chat-input__textarea"
          placeholder=""
          rows="1"
        ></textarea>
        <button type="button"
                class="mw-chat-input__btn mw-chat-input__btn--voice"
                title="<?php echo esc_attr( '音声入力' ); ?>"
                aria-label="<?php echo esc_attr( '音声入力' ); ?>">&#x1F399;</button>
        <button type="button"
                class="mw-chat-input__btn mw-chat-input__btn--send"
                title="<?php echo esc_attr( '送信' ); ?>"
                aria-label="<?php echo esc_attr( '送信' ); ?>">&#x27A4;</button>
      </div>
    </div>

  </div><!-- .mw-chat-window -->
</div><!-- #mw-chat -->
