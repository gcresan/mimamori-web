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

    <!-- Quick Questions — grouped by usage order (B) -->
    <div class="mw-chat-quick">
      <div class="mw-chat-quick__group">
        <div class="mw-chat-quick__label"><?php echo esc_html( "\xF0\x9F\x9F\xA2 まずはここから" ); ?></div>
        <div class="mw-chat-quick__chips">
          <button type="button" class="mw-chat-quick__chip mw-chat-quick__chip--primary"
                  data-question="<?php echo esc_attr( '今月やることを3つ教えて' ); ?>">
            <?php echo esc_html( '今月やることを3つ' ); ?>
          </button>
        </div>
      </div>
      <div class="mw-chat-quick__group">
        <div class="mw-chat-quick__label"><?php echo esc_html( "\xF0\x9F\x93\x8A 状況を知りたいとき" ); ?></div>
        <div class="mw-chat-quick__chips">
          <button type="button" class="mw-chat-quick__chip"
                  data-question="<?php echo esc_attr( 'この数字は良いですか？' ); ?>">
            <?php echo esc_html( 'この数字は良いですか？' ); ?>
          </button>
          <button type="button" class="mw-chat-quick__chip"
                  data-question="<?php echo esc_attr( '先月と比べてどうですか？' ); ?>">
            <?php echo esc_html( '先月と比べてどう？' ); ?>
          </button>
        </div>
      </div>
      <div class="mw-chat-quick__group">
        <div class="mw-chat-quick__label"><?php echo esc_html( "\xF0\x9F\x9B\xA0 改善したいとき" ); ?></div>
        <div class="mw-chat-quick__chips">
          <button type="button" class="mw-chat-quick__chip"
                  data-question="<?php echo esc_attr( 'このページの改善点を教えて' ); ?>">
            <?php echo esc_html( 'このページの改善点' ); ?>
          </button>
        </div>
      </div>
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
