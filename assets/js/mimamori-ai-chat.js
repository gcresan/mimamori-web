/**
 * mimamori-ai-chat.js
 * みまもりウェブ AIチャット UIコンポーネント
 *
 * Public API: window.GCREV.chat
 *   .switchViewMode(mode)           — 'closed' | 'normal' | 'panel' | 'modal'
 *   .sendMessage(text, options?)    — メッセージ送信 → REST API → OpenAI
 *   .appendUserMessage(text)        — ユーザーメッセージ追加
 *   .appendAssistantMessage(payload)— AI応答追加
 *   .setLoading(bool)              — ローディング表示切替
 *   .setError(message|null)        — エラー表示
 */
(function () {
  'use strict';

  /* ============================
     State
     ============================ */
  var state = {
    viewMode: 'closed',
    isLoading: false,
    hasError: false,
    history: [],  // 会話履歴 [{role:'user',content:'...'},{role:'assistant',content:'...'},...]
    options: {
      includeScreenshot: false,
      useDetailedData: false,
      conversationId: null
    }
  };

  /* ============================
     Config (from wp_localize_script)
     ============================ */
  var config = window.mwChatConfig || {};

  /* ============================
     DOM references (populated on init)
     ============================ */
  var els = {};

  /* ============================
     Helpers
     ============================ */

  /** 現在時刻を HH:MM 形式で返す */
  function formatTime() {
    var now = new Date();
    return now.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
  }

  /** メッセージ一覧の最下部へスクロール */
  function scrollToBottom() {
    if (!els.messages) return;
    els.messages.scrollTop = els.messages.scrollHeight;
  }

  /* ============================
     View Mode
     ============================ */
  function switchViewMode(mode) {
    if (!els.root) return;
    var valid = ['closed', 'normal', 'panel', 'modal'];
    if (valid.indexOf(mode) === -1) return;

    els.root.className = 'mw-chat mw-chat--' + mode;
    state.viewMode = mode;

    if (mode !== 'closed' && els.textarea) {
      setTimeout(function () { els.textarea.focus(); }, 300);
    }
  }

  /* ============================
     Message Creation (XSS-safe)
     ============================ */

  /**
   * ユーザーメッセージをDOMに追加
   * @param {string} text
   */
  function appendUserMessage(text) {
    removeWelcome();

    var msg = document.createElement('div');
    msg.className = 'mw-chat-msg mw-chat-msg--user';

    var avatar = document.createElement('div');
    avatar.className = 'mw-chat-msg__avatar';
    avatar.textContent = '\uD83D\uDC64'; // 👤

    var content = document.createElement('div');
    content.className = 'mw-chat-msg__content';

    var bubble = document.createElement('div');
    bubble.className = 'mw-chat-msg__bubble';
    bubble.textContent = text; // textContent = XSS safe

    var time = document.createElement('div');
    time.className = 'mw-chat-msg__time';
    time.textContent = formatTime();

    content.appendChild(bubble);
    content.appendChild(time);
    msg.appendChild(avatar);
    msg.appendChild(content);
    els.messages.appendChild(msg);
    scrollToBottom();
  }

  /**
   * AI回答をDOMに追加
   *
   * payload.type === 'talk'   → 対話形式（バブルのみ）
   * payload.type === 'advice' → 構造化アドバイス（サマリー + カード）
   * type 省略時は sections の有無で自動判定
   *
   * @param {Object} payload
   * @param {string} [payload.type]     — 'talk' | 'advice'
   * @param {string} [payload.text]     — talk 用テキスト
   * @param {string} [payload.summary]  — advice 用サマリー
   * @param {Array}  [payload.sections] — [{title, text?, items?[]}, ...]
   */
  function appendAssistantMessage(payload) {
    removeWelcome();

    var msg = document.createElement('div');
    msg.className = 'mw-chat-msg mw-chat-msg--ai';

    var avatar = document.createElement('div');
    avatar.className = 'mw-chat-msg__avatar';
    avatar.textContent = '\uD83E\uDD16'; // 🤖

    var content = document.createElement('div');
    content.className = 'mw-chat-msg__content';

    // Determine response type
    var isTalk = payload.type === 'talk' ||
                 (!payload.type && (!payload.sections || payload.sections.length === 0));

    if (isTalk) {
      // --- 対話形式: テキストバブルのみ ---
      var bubble = document.createElement('div');
      bubble.className = 'mw-chat-msg__bubble';
      bubble.textContent = payload.text || payload.summary || '';
      content.appendChild(bubble);

    } else {
      // --- 構造化アドバイス: サマリー + カード ---
      if (payload.summary) {
        var summaryBubble = document.createElement('div');
        summaryBubble.className = 'mw-chat-msg__bubble';
        summaryBubble.textContent = payload.summary;
        content.appendChild(summaryBubble);
      }

      if (payload.sections && payload.sections.length > 0) {
        var answer = document.createElement('div');
        answer.className = 'mw-chat-answer';

        for (var i = 0; i < payload.sections.length; i++) {
          var s = payload.sections[i];
          var sec = document.createElement('div');
          sec.className = 'mw-chat-answer__section';

          var title = document.createElement('div');
          title.className = 'mw-chat-answer__title';
          title.textContent = s.title;
          sec.appendChild(title);

          if (s.items && s.items.length > 0) {
            var ul = document.createElement('ul');
            ul.className = 'mw-chat-answer__list';
            for (var j = 0; j < s.items.length; j++) {
              var li = document.createElement('li');
              li.textContent = s.items[j]; // safe
              ul.appendChild(li);
            }
            sec.appendChild(ul);
          } else if (s.text) {
            var txt = document.createElement('div');
            txt.className = 'mw-chat-answer__text';
            txt.textContent = s.text; // safe
            sec.appendChild(txt);
          }

          answer.appendChild(sec);
        }
        content.appendChild(answer);
      }
    }

    // Feedback buttons
    var actions = document.createElement('div');
    actions.className = 'mw-chat-msg__actions';

    var thumbUp = document.createElement('button');
    thumbUp.type = 'button';
    thumbUp.className = 'mw-chat-feedback';
    thumbUp.title = '\u5F79\u306B\u7ACB\u3063\u305F'; // 役に立った
    thumbUp.textContent = '\uD83D\uDC4D';

    var thumbDown = document.createElement('button');
    thumbDown.type = 'button';
    thumbDown.className = 'mw-chat-feedback';
    thumbDown.title = '\u5F79\u306B\u7ACB\u305F\u306A\u304B\u3063\u305F'; // 役に立たなかった
    thumbDown.textContent = '\uD83D\uDC4E';

    actions.appendChild(thumbUp);
    actions.appendChild(thumbDown);
    content.appendChild(actions);

    // Time
    var time = document.createElement('div');
    time.className = 'mw-chat-msg__time';
    time.textContent = formatTime();
    content.appendChild(time);

    msg.appendChild(avatar);
    msg.appendChild(content);
    els.messages.appendChild(msg);
    scrollToBottom();
  }

  /** Welcome メッセージを除去 */
  function removeWelcome() {
    if (!els.messages) return;
    var w = els.messages.querySelector('.mw-chat-welcome');
    if (w) w.remove();
  }

  /* ============================
     Loading / Error
     ============================ */
  function setLoading(isLoading) {
    state.isLoading = isLoading;

    // Remove existing loading indicator
    var existing = els.messages ? els.messages.querySelector('.mw-chat-msg--loading') : null;
    if (existing) existing.remove();

    if (isLoading) {
      var msg = document.createElement('div');
      msg.className = 'mw-chat-msg mw-chat-msg--ai mw-chat-msg--loading';

      var avatar = document.createElement('div');
      avatar.className = 'mw-chat-msg__avatar';
      avatar.textContent = '\uD83E\uDD16';

      var dots = document.createElement('div');
      dots.className = 'mw-chat-loading';
      for (var i = 0; i < 3; i++) {
        var dot = document.createElement('div');
        dot.className = 'mw-chat-loading__dot';
        dots.appendChild(dot);
      }

      msg.appendChild(avatar);
      msg.appendChild(dots);
      els.messages.appendChild(msg);
      scrollToBottom();
    }

    // Disable/enable send button
    if (els.sendBtn) els.sendBtn.disabled = isLoading;
  }

  function setError(message) {
    state.hasError = !!message;

    // Remove existing error
    var existing = els.messages ? els.messages.querySelector('.mw-chat-error') : null;
    if (existing) existing.remove();

    if (!message) return;

    var err = document.createElement('div');
    err.className = 'mw-chat-error';

    var icon = document.createElement('div');
    icon.className = 'mw-chat-error__icon';
    icon.textContent = '\u26A0\uFE0F'; // ⚠️

    var body = document.createElement('div');
    body.className = 'mw-chat-error__body';

    var title = document.createElement('div');
    title.className = 'mw-chat-error__title';
    title.textContent = '\u30A8\u30E9\u30FC\u304C\u767A\u751F\u3057\u307E\u3057\u305F'; // エラーが発生しました

    var text = document.createElement('div');
    text.className = 'mw-chat-error__text';
    text.textContent = message;

    var retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'mw-chat-error__retry';
    retry.textContent = '\u3082\u3046\u4E00\u5EA6\u8A66\u3059'; // もう一度試す
    retry.addEventListener('click', function () {
      err.remove();
      state.hasError = false;
    });

    body.appendChild(title);
    body.appendChild(text);
    body.appendChild(retry);
    err.appendChild(icon);
    err.appendChild(body);
    els.messages.appendChild(err);
    scrollToBottom();
  }

  /* ============================
     Send Message → REST API → OpenAI
     ============================ */

  /**
   * メッセージを送信し、REST API 経由で AI 応答を取得する
   *
   * @param {string}  [messageText] — 省略時は textarea の値を使う
   * @param {Object}  [options]
   * @param {boolean} [options.includeScreenshot]
   * @param {boolean} [options.useDetailedData]
   * @param {string}  [options.conversationId]
   */
  function sendMessage(messageText, options) {
    var text = messageText || (els.textarea ? els.textarea.value.trim() : '');
    if (!text || state.isLoading) return;

    // Merge options
    var opts = {
      includeScreenshot: state.options.includeScreenshot,
      useDetailedData: state.options.useDetailedData,
      conversationId: state.options.conversationId
    };
    if (options) {
      for (var k in options) {
        if (options.hasOwnProperty(k)) opts[k] = options[k];
      }
    }

    // Add user message to DOM
    appendUserMessage(text);

    // Track in history (before API call so context is maintained even on failure)
    state.history.push({ role: 'user', content: text });

    // Clear input
    if (els.textarea) {
      els.textarea.value = '';
      els.textarea.style.height = 'auto';
    }

    // Show loading
    setLoading(true);

    // Build request body
    var body = {
      message: text,
      history: state.history.slice(0, -1).slice(-20), // Previous messages (max 20, exclude current)
      includeScreenshot: opts.includeScreenshot,
      useDetailedData: opts.useDetailedData,
      conversationId: opts.conversationId,
      viewMode: state.viewMode,
      currentPage: {
        title: document.title,
        url: window.location.href
      }
    };

    // API call
    fetch(config.apiUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce
      },
      body: JSON.stringify(body)
    })
    .then(function (response) {
      return response.json();
    })
    .then(function (data) {
      setLoading(false);

      if (data.success && data.data && data.data.message) {
        var msg = data.data.message;

        // Use structured response if available, otherwise plain text fallback
        var payload = msg.structured || { type: 'talk', text: msg.content };
        appendAssistantMessage(payload);

        // Track assistant response in history (raw content for API context)
        state.history.push({ role: 'assistant', content: msg.content });
      } else {
        setError(data.message || '\u56DE\u7B54\u306E\u53D6\u5F97\u306B\u5931\u6557\u3057\u307E\u3057\u305F'); // 回答の取得に失敗しました
      }
    })
    .catch(function () {
      setLoading(false);
      setError('\u901A\u4FE1\u30A8\u30E9\u30FC\u304C\u767A\u751F\u3057\u307E\u3057\u305F\u3002\u3082\u3046\u5C11\u3057\u6642\u9593\u3092\u304A\u3044\u3066\u304A\u8A66\u3057\u304F\u3060\u3055\u3044\u3002'); // 通信エラーが発生しました。もう少し時間をおいてお試しください。
    });
  }

  /* ============================
     Textarea auto-resize
     ============================ */
  function autoResize(textarea) {
    textarea.style.height = 'auto';
    var max = 120;
    textarea.style.height = Math.min(textarea.scrollHeight, max) + 'px';
  }

  /* ============================
     Event Binding
     ============================ */
  function bindEvents() {
    // FAB click → open normal
    els.fab.addEventListener('click', function () {
      switchViewMode('normal');
    });

    // Close button
    els.closeBtn.addEventListener('click', function () {
      switchViewMode('closed');
    });

    // Expand → panel
    els.expandBtn.addEventListener('click', function () {
      switchViewMode('panel');
    });

    // Collapse → normal
    els.collapseBtn.addEventListener('click', function () {
      switchViewMode('normal');
    });

    // Overlay click → close
    els.overlay.addEventListener('click', function () {
      switchViewMode('closed');
    });

    // Quick question chips (event delegation)
    els.quickArea.addEventListener('click', function (e) {
      var chip = e.target.closest('.mw-chat-quick__chip');
      if (!chip) return;
      var q = chip.getAttribute('data-question');
      if (q && els.textarea) {
        els.textarea.value = q;
        els.textarea.focus();
        autoResize(els.textarea);
      }
    });

    // Send button
    els.sendBtn.addEventListener('click', function () {
      sendMessage();
    });

    // Voice button (placeholder)
    if (els.voiceBtn) {
      els.voiceBtn.addEventListener('click', function () {
        // TODO: implement voice input in a future phase
      });
    }

    // Textarea: Enter to send, Shift+Enter for newline
    els.textarea.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        sendMessage();
      }
    });

    // Textarea auto-resize on input
    els.textarea.addEventListener('input', function () {
      autoResize(this);
    });

    // Option checkboxes
    var screenshotCb = els.root.querySelector('[data-option="screenshot"]');
    var detailedCb = els.root.querySelector('[data-option="detailed"]');
    if (screenshotCb) {
      screenshotCb.addEventListener('change', function () {
        state.options.includeScreenshot = this.checked;
      });
    }
    if (detailedCb) {
      detailedCb.addEventListener('change', function () {
        state.options.useDetailedData = this.checked;
      });
    }

    // Escape key → close
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && state.viewMode !== 'closed') {
        switchViewMode('closed');
      }
    });
  }

  /* ============================
     Init
     ============================ */
  function init() {
    els.root        = document.getElementById('mw-chat');
    if (!els.root) return;

    els.fab         = els.root.querySelector('.mw-chat-fab');
    els.overlay     = els.root.querySelector('.mw-chat-overlay');
    els.window      = els.root.querySelector('.mw-chat-window');
    els.messages    = els.root.querySelector('.mw-chat-messages');
    els.textarea    = els.root.querySelector('.mw-chat-input__textarea');
    els.sendBtn     = els.root.querySelector('.mw-chat-input__btn--send');
    els.voiceBtn    = els.root.querySelector('.mw-chat-input__btn--voice');
    els.closeBtn    = els.root.querySelector('.mw-chat-header__btn--close');
    els.expandBtn   = els.root.querySelector('.mw-chat-header__btn--expand');
    els.collapseBtn = els.root.querySelector('.mw-chat-header__btn--collapse');
    els.quickArea   = els.root.querySelector('.mw-chat-quick');

    if (!els.fab || !els.messages || !els.textarea) return;

    bindEvents();
  }

  /* ============================
     Expose public API
     ============================ */
  window.GCREV = window.GCREV || {};
  window.GCREV.chat = {
    switchViewMode:        switchViewMode,
    sendMessage:           sendMessage,
    appendUserMessage:     appendUserMessage,
    appendAssistantMessage: appendAssistantMessage,
    setLoading:            setLoading,
    setError:              setError
  };

  document.addEventListener('DOMContentLoaded', init);
})();
