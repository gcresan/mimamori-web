/**
 * mimamori-ai-chat.js
 * みまもりウェブ AIチャット UIコンポーネント
 *
 * Public API: window.GCREV.chat
 *   .switchViewMode(mode)           — 'closed' | 'normal' | 'panel' | 'modal'
 *   .sendMessage(text, options?)    — メッセージ送信（今はダミー応答）
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
    options: {
      includeScreenshot: false,
      useDetailedData: false,
      conversationId: null
    }
  };

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
   * @param {Object} payload
   * @param {string} payload.summary — 要約テキスト
   * @param {Array}  payload.sections — [{title, text?, items?[]}, ...]
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

    // Summary bubble
    var bubble = document.createElement('div');
    bubble.className = 'mw-chat-msg__bubble';
    bubble.textContent = payload.summary || '';
    content.appendChild(bubble);

    // Structured answer sections
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
     Dummy Response System
     ============================ */
  function getDummyResponse(text) {

    // --- 用語解説系 ---
    if (/ctr|用語|とは$|意味/i.test(text)) {
      return {
        summary: 'CTR（クリック率）について説明します。',
        sections: [
          {
            title: '\uD83D\uDCCA \u7D50\u8AD6', // 📊 結論
            text: 'CTRとは「Click Through Rate」の略で、表示された回数に対してクリックされた割合のことです。'
          },
          {
            title: '\uD83D\uDCA1 \u7406\u7531', // 💡 理由
            items: [
              'CTRが高い＝検索結果で選ばれやすいページです',
              '業種平均は2〜5%くらいが目安になります',
              'タイトルやメタディスクリプションの工夫で改善できます'
            ]
          },
          {
            title: '\u2705 \u4ECA\u3059\u3050\u3084\u308B\u3053\u3068', // ✅ 今すぐやること
            items: [
              'CTRが低いページのタイトルを見直してみましょう',
              '検索結果でどう表示されているか確認しましょう',
              '競合のタイトルと比較してみましょう'
            ]
          },
          {
            title: '\uD83D\uDCC8 \u6B21\u306B\u898B\u308B\u6570\u5B57', // 📈 次に見る数字
            items: [
              '各ページのCTR（Search Console → 検索パフォーマンス）',
              '表示回数が多いのにCTRが低いページ',
              '改善後のCTR変化（2週間後に確認）'
            ]
          }
        ]
      };
    }

    // --- 減少・低下系 ---
    if (/落ち|下がっ|減|低下|悪化/.test(text)) {
      return {
        summary: '確認しました。数値の変化について分析します。',
        sections: [
          {
            title: '\uD83D\uDCCA \u7D50\u8AD6',
            text: '検索からの流入が減ったことが主な原因と考えられます。'
          },
          {
            title: '\uD83D\uDCA1 \u7406\u7531',
            items: [
              '一部の検索キーワードの表示回数が下がっています',
              '主要ページのクリック率が少し低下しています',
              '季節的な要因も影響している可能性があります'
            ]
          },
          {
            title: '\u2705 \u4ECA\u3059\u3050\u3084\u308B\u3053\u3068',
            items: [
              '表示回数が減ったキーワードを特定する',
              'タイトルタグとメタディスクリプションを見直す',
              '内部リンクを追加してページの評価を高める'
            ]
          },
          {
            title: '\uD83D\uDCC8 \u6B21\u306B\u898B\u308B\u6570\u5B57',
            items: [
              '検索クリック数（前月比）',
              '主要ページのCTR推移',
              '問い合わせページの閲覧数'
            ]
          }
        ]
      };
    }

    // --- 汎用回答 ---
    return {
      summary: 'ご質問ありがとうございます。データを確認してお答えします。',
      sections: [
        {
          title: '\uD83D\uDCCA \u7D50\u8AD6',
          text: 'お客様のサイトは全体的に安定した状態です。いくつか改善できるポイントがあります。'
        },
        {
          title: '\uD83D\uDCA1 \u7406\u7531',
          items: [
            'アクセス数は前月と同水準で推移しています',
            '特定のページに集中したアクセスパターンが見られます',
            'モバイルからの閲覧が全体の7割を占めています'
          ]
        },
        {
          title: '\u2705 \u4ECA\u3059\u3050\u3084\u308B\u3053\u3068',
          items: [
            'アクセスの多いページの内容を充実させる',
            'モバイルでの表示速度を確認する',
            'お問い合わせへの導線を見直す'
          ]
        },
        {
          title: '\uD83D\uDCC8 \u6B21\u306B\u898B\u308B\u6570\u5B57',
          items: [
            'ページごとの滞在時間',
            'お問い合わせ完了率',
            '新規ユーザーの割合'
          ]
        }
      ]
    };
  }

  /* ============================
     Send Message
     ============================ */

  /**
   * メッセージを送信する（ダミー応答 → 将来 API 接続ポイント）
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

    // Add user message
    appendUserMessage(text);

    // Clear input
    if (els.textarea) {
      els.textarea.value = '';
      els.textarea.style.height = 'auto';
    }

    // Show loading
    setLoading(true);

    // -------------------------------------------------------
    // API connection point (Phase 2)
    //
    // Replace the setTimeout below with:
    //
    //   fetch(mwChatConfig.apiUrl, {
    //     method: 'POST',
    //     headers: {
    //       'Content-Type': 'application/json',
    //       'X-WP-Nonce': mwChatConfig.nonce
    //     },
    //     body: JSON.stringify({ message: text, ...opts })
    //   })
    //   .then(function(r) { return r.json(); })
    //   .then(function(data) {
    //     setLoading(false);
    //     appendAssistantMessage(data);
    //   })
    //   .catch(function(err) {
    //     setLoading(false);
    //     setError(err.message || '通信エラーが発生しました');
    //   });
    //
    // -------------------------------------------------------
    var capturedText = text;
    setTimeout(function () {
      setLoading(false);
      var response = getDummyResponse(capturedText);
      appendAssistantMessage(response);
    }, 1000);
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
