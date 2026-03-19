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
  var STORAGE_KEY_HISTORY  = 'mw_chat_history';
  var STORAGE_KEY_VIEW     = 'mw_chat_viewMode';
  var STORAGE_KEY_PROMPT_MODE = 'mw_chat_promptMode';
  var MAX_PERSISTED_MESSAGES = 50;

  var state = {
    viewMode: 'closed',
    isLoading: false,
    isRecording: false,
    hasError: false,
    history: [],  // 会話履歴 [{role:'user',content:'...',structured?:{}},...]
    options: {
      conversationId: null
    }
  };

  /* ============================
     Config (from wp_localize_script)
     ============================ */
  var config = window.mwChatConfig || {};

  /* ============================
     Quick Prompt State
     ============================ */
  var promptMode = 'beginner';  // 'beginner' | 'standard'
  var promptCat  = 'status';    // 'status' | 'action' | 'trouble'

  /* ============================
     DOM references (populated on init)
     ============================ */
  var els = {};

  /** SpeechRecognition instance (null if browser unsupported) */
  var recognition = null;

  /* ============================
     Helpers
     ============================ */

  /** 現在時刻を HH:MM 形式で返す */
  function formatTime() {
    var now = new Date();
    return now.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
  }

  /**
   * テキストを安全に要素へ挿入する（\n を <br> に変換）
   * textContent と違い改行を反映しつつ XSS を防ぐ。
   */
  function setTextWithBreaks(el, text) {
    el.innerHTML = ''; // clear
    if (!text) return;
    // リテラル \n（AI が \\n で返すケース）を実際の改行に正規化
    var normalized = String(text).replace(/\\n/g, '\n');
    var lines = normalized.split('\n');
    for (var i = 0; i < lines.length; i++) {
      if (i > 0) {
        el.appendChild(document.createElement('br'));
      }
      el.appendChild(document.createTextNode(lines[i]));
    }
  }

  /** メッセージ一覧の最下部へスクロール */
  function scrollToBottom() {
    if (!els.messages) return;
    els.messages.scrollTop = els.messages.scrollHeight;
  }

  /* ============================
     Session Persistence (sessionStorage)
     ============================ */

  /** 会話履歴と表示モードを sessionStorage に保存 */
  function saveSession() {
    try {
      var toSave = state.history.slice(-MAX_PERSISTED_MESSAGES);
      sessionStorage.setItem(STORAGE_KEY_HISTORY, JSON.stringify(toSave));
      sessionStorage.setItem(STORAGE_KEY_VIEW, state.viewMode);
    } catch (e) { /* QuotaExceeded 等は無視 */ }
  }

  /**
   * sessionStorage から会話履歴を復元し、DOMに再描画する
   * @returns {boolean} 復元されたかどうか
   */
  function restoreSession() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY_HISTORY);
      if (!raw) return false;

      var saved = JSON.parse(raw);
      if (!Array.isArray(saved) || saved.length === 0) return false;

      // Welcome メッセージを除去（復元メッセージで上書きされるため）
      removeWelcome();

      // 履歴を復元
      state.history = saved;

      // DOM に再描画
      for (var i = 0; i < saved.length; i++) {
        var entry = saved[i];
        if (entry.role === 'user') {
          appendUserMessage(entry.content);
        } else if (entry.role === 'assistant') {
          var payload = entry.structured || { type: 'talk', text: entry.content };
          appendAssistantMessage(payload);
        }
      }

      // Quick question chips を非表示にする（会話がある場合）
      var quickArea = els.quickArea || (els.root ? els.root.querySelector('.mw-chat-quick') : null);
      if (quickArea) quickArea.style.display = 'none';

      return true;
    } catch (e) {
      return false;
    }
  }

  /** 保存済み viewMode を復元 */
  function restoreViewMode() {
    try {
      var saved = sessionStorage.getItem(STORAGE_KEY_VIEW);
      if (saved && saved !== 'closed') {
        return saved;
      }
    } catch (e) {}
    return null;
  }

  /* ============================
     View Mode
     ============================ */
  function switchViewMode(mode) {
    if (!els.root) return;
    var valid = ['closed', 'normal', 'panel', 'modal'];
    if (valid.indexOf(mode) === -1) return;

    // Cancel voice recording if closing
    if (mode === 'closed' && isInRecordingMode()) {
      cancelVoice();
    }

    els.root.className = 'mw-chat mw-chat--' + mode;
    state.viewMode = mode;
    saveSession();

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
      setTextWithBreaks(bubble, payload.text || payload.summary || '');
      content.appendChild(bubble);

    } else {
      // --- 構造化アドバイス: サマリー + カード ---
      if (payload.summary) {
        var summaryBubble = document.createElement('div');
        summaryBubble.className = 'mw-chat-msg__bubble';
        setTextWithBreaks(summaryBubble, payload.summary);
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
              setTextWithBreaks(li, s.items[j]);
              ul.appendChild(li);
            }
            sec.appendChild(ul);
          } else if (s.text) {
            var txt = document.createElement('div');
            txt.className = 'mw-chat-answer__text';
            setTextWithBreaks(txt, s.text);
            sec.appendChild(txt);
          }

          answer.appendChild(sec);
        }
        content.appendChild(answer);
      }
    }

    // Support notice — 専門スタッフ対応が必要な場合
    if (payload.support_notice) {
      var notice = document.createElement('div');
      notice.className = 'mw-chat-support-notice';

      var noticeIcon = document.createElement('div');
      noticeIcon.className = 'mw-chat-support-notice__icon';
      noticeIcon.textContent = '\u{1F4E9}'; // 📩

      var noticeBody = document.createElement('div');
      noticeBody.className = 'mw-chat-support-notice__body';

      var noticeTitle = document.createElement('div');
      noticeTitle.className = 'mw-chat-support-notice__title';
      noticeTitle.textContent = '\u5C02\u9580\u30B9\u30BF\u30C3\u30D5\u304C\u30B5\u30DD\u30FC\u30C8\u3044\u305F\u3057\u307E\u3059'; // 専門スタッフがサポートいたします

      var noticeText = document.createElement('div');
      noticeText.className = 'mw-chat-support-notice__text';
      setTextWithBreaks(noticeText,
        '\u3053\u306E\u5185\u5BB9\u306F\u3001\u307F\u307E\u3082\u308A\u30A6\u30A7\u30D6\u62C5\u5F53\u306E\u5C02\u9580\u30B9\u30BF\u30C3\u30D5\u304C\n' + // この内容は、みまもりウェブ担当の専門スタッフが
        '\u5185\u5BB9\u3092\u78BA\u8A8D\u306E\u3046\u3048\u3001\u76F4\u63A5\u3054\u6848\u5185\u3044\u305F\u3057\u307E\u3059\u3002\n' + // 内容を確認のうえ、直接ご案内いたします。
        '\u304A\u6C17\u8EFD\u306B\u3054\u76F8\u8AC7\u304F\u3060\u3055\u3044\u3002' // お気軽にご相談ください。
      );

      var noticeContact = document.createElement('a');
      noticeContact.className = 'mw-chat-support-notice__link';
      noticeContact.href = 'mailto:support@g-crev.jp';
      noticeContact.textContent = '\u2709 support@g-crev.jp'; // ✉ support@g-crev.jp

      var noticeLabel = document.createElement('div');
      noticeLabel.className = 'mw-chat-support-notice__label';
      noticeLabel.textContent = '\u682A\u5F0F\u4F1A\u793E\u30B8\u30A3\u30AF\u30EC\u30D6\u300C\u307F\u307E\u3082\u308A\u30A6\u30A7\u30D6\u62C5\u5F53\u300D'; // 株式会社ジィクレブ「みまもりウェブ担当」

      noticeBody.appendChild(noticeTitle);
      noticeBody.appendChild(noticeText);
      noticeBody.appendChild(noticeLabel);
      noticeBody.appendChild(noticeContact);
      notice.appendChild(noticeIcon);
      notice.appendChild(noticeBody);
      content.appendChild(notice);
    }

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

    // Disable/enable send & voice buttons
    if (els.sendBtn) els.sendBtn.disabled = isLoading;
    if (els.voiceBtn) els.voiceBtn.disabled = isLoading;
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
    saveSession();

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
      conversationId: opts.conversationId,
      viewMode: state.viewMode,
      currentPage: {
        title: document.title,
        url: window.location.href
      }
    };

    // セクションコンテキスト（「AIに聞く」ボタンから抽出）
    if (opts.sectionContext) {
      body.sectionContext = opts.sectionContext;
    }

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

        // Track assistant response in history (raw content for API context + structured for DOM rebuild)
        state.history.push({ role: 'assistant', content: msg.content, structured: payload });
        saveSession();
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
     Voice Input (Web Speech API + MediaRecorder Fallback + Waveform)

     戦略:
     1. SpeechRecognition 対応 → リアルタイム認識（従来動作）
     2. 非対応 or networkエラー発生 → MediaRecorder + Whisper API に自動切替
     ============================ */

  var VOICE_MAX_DURATION = 45000;   // 最大45秒（安全制限）
  var VOICE_SILENCE_TIMEOUT = 12000; // 沈黙12秒で認識自動停止（ゆとりを持たせる）

  /** Audio visualization state */
  var audioCtx = null;
  var audioAnalyser = null;
  var audioStream = null;
  var waveAnimId = null;
  var waveCanvas = null;
  var waveCtx = null;

  /** 録音中にバッファするテキスト（SpeechRecognition 用） */
  var voiceBuffer = '';

  /** MediaRecorder フォールバック用 */
  var useFallback = false;       // true = MediaRecorder + Whisper を使用
  var mediaRecorder = null;
  var mediaChunks = [];
  var fallbackMaxTimer = null;
  var fallbackStream = null;     // MediaRecorder 用ストリーム
  var isTranscribing = false;    // Whisper API 呼び出し中

  /** SpeechRecognition 自動再接続用 */
  var voiceStoppedByUser = false;  // confirm/cancel で明示的に停止したか
  var voiceRestartCount = 0;       // 自動再接続回数
  var VOICE_MAX_RESTARTS = 5;      // 自動再接続上限

  /** 録音モードUIが表示中かどうか */
  function isInRecordingMode() {
    return els.inputArea && els.inputArea.classList.contains('mw-chat-input--recording');
  }

  /**
   * iOS デバイス判定
   * iPhone / iPad / iPod touch を検出する。
   * iPad (iPadOS 13+) は User-Agent が Mac になるため maxTouchPoints で補完。
   */
  function isIOSDevice() {
    var ua = navigator.userAgent || '';
    if (/iPhone|iPod/.test(ua)) return true;
    if (/iPad/.test(ua)) return true;
    // iPadOS 13+ Safari は Mac 表記だが touchPoints で判別
    if (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1) return true;
    return false;
  }

  /**
   * 音声認識を初期化する
   * iOS → 常に MediaRecorder + Whisper（SpeechRecognition が不安定なため）
   * PC等で SpeechRecognition 対応 → リアルタイム認識
   * 非対応 → MediaRecorder + Whisper API フォールバック
   */
  function initVoice() {
    if (!els.voiceBtn) return;

    // --- Waveform DOM element (hidden by default via CSS) ---
    initWaveformCanvas();

    // MediaRecorder (フォールバック) が使えるか確認
    var hasMediaRecorder = !!(navigator.mediaDevices &&
                              navigator.mediaDevices.getUserMedia &&
                              window.MediaRecorder);

    // iOS は SpeechRecognition が不安定 → 常に MediaRecorder を使用
    if (isIOSDevice()) {
      if (hasMediaRecorder && config.voiceUrl) {
        useFallback = true;
      } else {
        els.voiceBtn.style.display = 'none';
      }
      return;
    }

    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (SpeechRecognition) {
      // SpeechRecognition 対応 → 従来のリアルタイム認識で初期化
      initSpeechRecognition(SpeechRecognition, hasMediaRecorder);
    } else if (hasMediaRecorder && config.voiceUrl) {
      // SpeechRecognition 非対応 → MediaRecorder フォールバック
      useFallback = true;
    } else {
      // どちらも使えない → ボタン非表示
      els.voiceBtn.style.display = 'none';
      return;
    }
  }

  /** Waveform Canvas を DOM に追加 */
  function initWaveformCanvas() {
    if (!els.inputRow) return;
    var waveContainer = document.createElement('div');
    waveContainer.className = 'mw-chat-input__waveform';
    waveCanvas = document.createElement('canvas');
    waveCanvas.width = 600;
    waveCanvas.height = 80;
    waveContainer.appendChild(waveCanvas);
    waveCtx = waveCanvas.getContext('2d');
    els.inputRow.insertBefore(waveContainer, els.voiceBtn);
  }

  /**
   * SpeechRecognition を初期化する
   * @param {Function} SpeechRecognition コンストラクタ
   * @param {boolean}  hasMediaRecorder  フォールバック切替可能か
   */
  function initSpeechRecognition(SpeechRecognition, hasMediaRecorder) {
    recognition = new SpeechRecognition();
    recognition.lang = 'ja-JP';
    recognition.interimResults = true;
    recognition.continuous = true;
    recognition.maxAlternatives = 1;

    var accumulatedFinal = '';
    var silenceTimer = null;
    var maxTimer = null;

    function clearTimers() {
      if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
      if (maxTimer) { clearTimeout(maxTimer); maxTimer = null; }
    }

    function resetSilenceTimer() {
      if (silenceTimer) clearTimeout(silenceTimer);
      silenceTimer = setTimeout(function () {
        if (state.isRecording && recognition) {
          recognition.stop();
        }
      }, VOICE_SILENCE_TIMEOUT);
    }

    recognition.addEventListener('start', function () {
      state.isRecording = true;
      accumulatedFinal = '';

      maxTimer = setTimeout(function () {
        if (state.isRecording && recognition) {
          recognition.stop();
        }
      }, VOICE_MAX_DURATION);

      resetSilenceTimer();
    });

    recognition.addEventListener('result', function (e) {
      var interim = '';
      var newFinal = '';
      for (var i = e.resultIndex; i < e.results.length; i++) {
        var transcript = e.results[i][0].transcript;
        if (e.results[i].isFinal) {
          newFinal += transcript;
        } else {
          interim += transcript;
        }
      }
      accumulatedFinal += newFinal;
      voiceBuffer = accumulatedFinal + interim;

      resetSilenceTimer();
    });

    recognition.addEventListener('end', function () {
      state.isRecording = false;
      clearTimers();

      // ユーザーが明示的に確定/キャンセルした場合 → 停止
      if (voiceStoppedByUser || !isInRecordingMode()) {
        stopWaveformAnimation();
        return;
      }

      // Chrome は continuous: true でも途中で切断することがある
      // → 録音モード中なら自動再接続を試みる
      if (voiceRestartCount < VOICE_MAX_RESTARTS) {
        voiceRestartCount++;
        try {
          recognition.start();
          return; // 波形はそのまま維持
        } catch (e) {
          // 再接続失敗 → 停止して UI 維持
        }
      }

      stopWaveformAnimation();
      // 録音UIは維持 — ユーザーが ✓ or ✕ をクリックするのを待つ
    });

    recognition.addEventListener('error', function (e) {
      state.isRecording = false;
      clearTimers();

      // aborted = プログラム的に stop() を呼んだ（沈黙タイマー等）
      // → 録音UIはそのまま維持し、ユーザーの確定/キャンセルを待つ
      if (e.error === 'aborted') return;

      stopWaveformAnimation();
      exitRecordingMode();

      // not-allowed / audio-capture はハードウェア系 → フォールバックでも解決不可
      if (e.error === 'not-allowed' || e.error === 'audio-capture') {
        handleVoiceError(e.error);
        return;
      }

      // no-speech はユーザー操作ミス → フォールバック不要
      if (e.error === 'no-speech') {
        handleVoiceError(e.error);
        return;
      }

      // network / service-not-allowed / その他すべて → フォールバックに切替
      if (hasMediaRecorder && config.voiceUrl) {
        useFallback = true;
        setError('\u97F3\u58F0\u8A8D\u8B58\u306B\u5931\u6557\u3057\u307E\u3057\u305F\u3002\u6B21\u56DE\u304B\u3089\u4EE3\u66FF\u65B9\u5F0F\u3067\u9332\u97F3\u3057\u307E\u3059\u3002\u3082\u3046\u4E00\u5EA6\u304A\u8A66\u3057\u304F\u3060\u3055\u3044\u3002');
        // 音声認識に失敗しました。次回から代替方式で録音します。もう一度お試しください。
        return;
      }

      handleVoiceError(e.error);
    });
  }

  // ---------------------------------------------------------
  // 統合 Voice API（useFallback で自動分岐）
  // ---------------------------------------------------------

  /** 録音を開始し、録音モードUIに切り替える */
  function startVoice() {
    if (state.isRecording || state.isLoading || isTranscribing) return;

    if (useFallback) {
      startVoiceFallback();
    } else {
      startVoiceSpeech();
    }
  }

  /** 録音を確定する */
  function confirmVoice() {
    if (useFallback) {
      confirmVoiceFallback();
    } else {
      confirmVoiceSpeech();
    }
  }

  /** 録音をキャンセルし、テキストを破棄する */
  function cancelVoice() {
    if (useFallback) {
      cancelVoiceFallback();
    } else {
      cancelVoiceSpeech();
    }
  }

  // ---------------------------------------------------------
  // SpeechRecognition 方式（従来ロジック）
  // ---------------------------------------------------------

  function startVoiceSpeech() {
    if (!recognition) return;

    voiceBuffer = '';
    voiceStoppedByUser = false;
    voiceRestartCount = 0;
    enterRecordingMode();

    try {
      recognition.start();
    } catch (e) {
      exitRecordingMode();
      return;
    }

    startWaveformAnimation();
  }

  function confirmVoiceSpeech() {
    voiceStoppedByUser = true;
    if (recognition && state.isRecording) {
      try { recognition.stop(); } catch (e) {}
    }
    stopWaveformAnimation();

    if (els.textarea && voiceBuffer) {
      els.textarea.value = voiceBuffer;
      autoResize(els.textarea);
    }
    voiceBuffer = '';
    exitRecordingMode();

    if (els.textarea) els.textarea.focus();
  }

  function cancelVoiceSpeech() {
    voiceStoppedByUser = true;
    if (recognition && state.isRecording) {
      try { recognition.stop(); } catch (e) {}
    }
    stopWaveformAnimation();
    voiceBuffer = '';
    exitRecordingMode();
  }

  // ---------------------------------------------------------
  // MediaRecorder + Whisper API フォールバック
  // ---------------------------------------------------------

  /** フォールバック: 録音開始 */
  function startVoiceFallback() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;

    enterRecordingMode();

    navigator.mediaDevices.getUserMedia({ audio: true })
      .then(function (stream) {
        if (!isInRecordingMode()) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          return;
        }

        fallbackStream = stream;
        mediaChunks = [];

        // MIME タイプ選択（ブラウザ対応順）
        var mimeType = '';
        var mimeOptions = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus', ''];
        for (var i = 0; i < mimeOptions.length; i++) {
          if (mimeOptions[i] === '' || MediaRecorder.isTypeSupported(mimeOptions[i])) {
            mimeType = mimeOptions[i];
            break;
          }
        }

        var recorderOptions = {};
        if (mimeType) recorderOptions.mimeType = mimeType;

        try {
          mediaRecorder = new MediaRecorder(stream, recorderOptions);
        } catch (e) {
          exitRecordingMode();
          stopFallbackStream();
          setError('\u9332\u97F3\u306E\u958B\u59CB\u306B\u5931\u6557\u3057\u307E\u3057\u305F\u3002');
          // 録音の開始に失敗しました。
          return;
        }

        mediaRecorder.addEventListener('dataavailable', function (e) {
          if (e.data && e.data.size > 0) {
            mediaChunks.push(e.data);
          }
        });

        mediaRecorder.addEventListener('stop', function () {
          // stop は confirmVoiceFallback / cancelVoiceFallback から呼ばれる
          // Blob 処理はそちらで行う
        });

        mediaRecorder.start(1000); // 1秒ごとにデータ取得
        state.isRecording = true;

        // 波形アニメーション（ストリーム共有）
        startWaveformFromStream(stream);

        // 最大録音時間
        fallbackMaxTimer = setTimeout(function () {
          if (state.isRecording && mediaRecorder && mediaRecorder.state === 'recording') {
            // 自動停止 → 確定扱い
            confirmVoiceFallback();
          }
        }, VOICE_MAX_DURATION);
      })
      .catch(function (err) {
        exitRecordingMode();
        if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
          setError('\u30DE\u30A4\u30AF\u306E\u4F7F\u7528\u304C\u8A31\u53EF\u3055\u308C\u3066\u3044\u307E\u305B\u3093\u3002\u30D6\u30E9\u30A6\u30B6\u306E\u8A2D\u5B9A\u304B\u3089\u30DE\u30A4\u30AF\u3092\u8A31\u53EF\u3057\u3066\u304F\u3060\u3055\u3044\u3002');
          // マイクの使用が許可されていません。ブラウザの設定からマイクを許可してください。
        } else {
          setError('\u30DE\u30A4\u30AF\u306B\u30A2\u30AF\u30BB\u30B9\u3067\u304D\u307E\u305B\u3093\u3067\u3057\u305F\u3002');
          // マイクにアクセスできませんでした。
        }
      });
  }

  /** フォールバック: 録音確定 → Whisper API で文字起こし */
  function confirmVoiceFallback() {
    if (fallbackMaxTimer) { clearTimeout(fallbackMaxTimer); fallbackMaxTimer = null; }

    var wasRecording = (mediaRecorder && mediaRecorder.state === 'recording');

    if (wasRecording) {
      // stop イベントで最終データが来てから Blob を作る
      mediaRecorder.addEventListener('stop', function onStop() {
        mediaRecorder.removeEventListener('stop', onStop);
        processRecordedAudio();
      }, { once: true });
      mediaRecorder.stop();
    } else {
      processRecordedAudio();
    }

    state.isRecording = false;
    stopWaveformAnimation();
    stopFallbackStream();
  }

  /** 録音完了後: Blob → REST API 送信 */
  function processRecordedAudio() {
    if (mediaChunks.length === 0) {
      exitRecordingMode();
      return;
    }

    var mimeType = (mediaRecorder && mediaRecorder.mimeType) || 'audio/webm';
    var blob = new Blob(mediaChunks, { type: mimeType });
    mediaChunks = [];

    // サイズチェック（25MB）
    if (blob.size > 25 * 1024 * 1024) {
      exitRecordingMode();
      setError('\u9332\u97F3\u304C\u9577\u3059\u304E\u307E\u3059\u3002\u77ED\u304F\u3057\u3066\u304A\u8A66\u3057\u304F\u3060\u3055\u3044\u3002');
      // 録音が長すぎます。短くしてお試しください。
      return;
    }

    // 極端に小さい場合（0.5秒未満相当）スキップ
    if (blob.size < 1000) {
      exitRecordingMode();
      return;
    }

    // UI: 文字起こし中表示
    isTranscribing = true;
    enterTranscribingMode();

    // ファイル拡張子を MIME タイプから推定（Whisper API はファイル拡張子で形式を判定する）
    var ext = 'webm';
    if (mimeType.indexOf('mp4') !== -1 || mimeType.indexOf('m4a') !== -1 || mimeType.indexOf('aac') !== -1) {
      ext = 'm4a';
    } else if (mimeType.indexOf('ogg') !== -1) {
      ext = 'ogg';
    } else if (isIOSDevice()) {
      // iOS のデフォルト録音形式は AAC/MP4 → m4a として送信
      ext = 'm4a';
    }

    var formData = new FormData();
    formData.append('audio', blob, 'recording.' + ext);

    fetch(config.voiceUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': config.nonce
      },
      body: formData
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      isTranscribing = false;
      exitRecordingMode();

      if (data.success && data.data && data.data.text) {
        if (els.textarea) {
          els.textarea.value = data.data.text;
          autoResize(els.textarea);
          els.textarea.focus();
        }
      } else {
        setError(data.message || '\u97F3\u58F0\u3092\u8A8D\u8B58\u3067\u304D\u307E\u305B\u3093\u3067\u3057\u305F\u3002\u3082\u3046\u4E00\u5EA6\u304A\u8A66\u3057\u304F\u3060\u3055\u3044\u3002');
        // 音声を認識できませんでした。もう一度お試しください。
      }
    })
    .catch(function () {
      isTranscribing = false;
      exitRecordingMode();
      setError('\u97F3\u58F0\u306E\u9001\u4FE1\u306B\u5931\u6557\u3057\u307E\u3057\u305F\u3002\u901A\u4FE1\u74B0\u5883\u3092\u78BA\u8A8D\u3057\u3066\u304A\u8A66\u3057\u304F\u3060\u3055\u3044\u3002');
      // 音声の送信に失敗しました。通信環境を確認してお試しください。
    });
  }

  /** フォールバック: 録音キャンセル */
  function cancelVoiceFallback() {
    if (fallbackMaxTimer) { clearTimeout(fallbackMaxTimer); fallbackMaxTimer = null; }

    if (mediaRecorder && mediaRecorder.state === 'recording') {
      try { mediaRecorder.stop(); } catch (e) {}
    }
    state.isRecording = false;
    mediaChunks = [];
    stopWaveformAnimation();
    stopFallbackStream();
    exitRecordingMode();
  }

  /** フォールバック用ストリーム停止 */
  function stopFallbackStream() {
    if (fallbackStream) {
      fallbackStream.getTracks().forEach(function (t) { t.stop(); });
      fallbackStream = null;
    }
  }

  // ---------------------------------------------------------
  // 共通 UI: 録音モード / 文字起こし中
  // ---------------------------------------------------------

  /** 録音モードUIに切り替える（テキスト欄→波形、ボタンアイコン差替） */
  function enterRecordingMode() {
    if (els.inputArea) els.inputArea.classList.add('mw-chat-input--recording');

    if (els.voiceBtn) {
      els.voiceBtn.textContent = '\u2715'; // ✕
      els.voiceBtn.title = '\u30AD\u30E3\u30F3\u30BB\u30EB'; // キャンセル
      els.voiceBtn.setAttribute('aria-label', '\u30AD\u30E3\u30F3\u30BB\u30EB');
      els.voiceBtn.disabled = false;
    }
    if (els.sendBtn) {
      els.sendBtn.textContent = '\u2713'; // ✓
      els.sendBtn.title = '\u78BA\u5B9A'; // 確定
      els.sendBtn.setAttribute('aria-label', '\u78BA\u5B9A');
      els.sendBtn.disabled = false;
    }

    // モバイル: 入力エリアが画面内に見えるようスクロール
    if (els.inputArea) {
      setTimeout(function () {
        els.inputArea.scrollIntoView({ behavior: 'smooth', block: 'end' });
      }, 100);
    }
  }

  /** 文字起こし処理中の表示（確定ボタン押下後〜Whisper応答まで） */
  function enterTranscribingMode() {
    if (els.voiceBtn) {
      els.voiceBtn.disabled = true;
    }
    if (els.sendBtn) {
      els.sendBtn.textContent = '\u23F3'; // ⏳
      els.sendBtn.title = '\u6587\u5B57\u8D77\u3053\u3057\u4E2D...'; // 文字起こし中...
      els.sendBtn.disabled = true;
    }
  }

  /** 通常モードUIに戻す */
  function exitRecordingMode() {
    state.isRecording = false;

    if (els.inputArea) els.inputArea.classList.remove('mw-chat-input--recording');

    if (els.voiceBtn) {
      els.voiceBtn.textContent = '\uD83C\uDF99'; // 🎙
      els.voiceBtn.title = '\u97F3\u58F0\u5165\u529B'; // 音声入力
      els.voiceBtn.setAttribute('aria-label', '\u97F3\u58F0\u5165\u529B');
      els.voiceBtn.disabled = false;
    }
    if (els.sendBtn) {
      els.sendBtn.textContent = '\u27A4'; // ➤
      els.sendBtn.title = '\u9001\u4FE1'; // 送信
      els.sendBtn.setAttribute('aria-label', '\u9001\u4FE1');
      els.sendBtn.disabled = false;
    }
  }

  // ---------------------------------------------------------
  // Waveform (波形アニメーション)
  // ---------------------------------------------------------

  /** 新規ストリームを取得して波形アニメーション開始（SpeechRecognition 用） */
  function startWaveformAnimation() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;

    navigator.mediaDevices.getUserMedia({ audio: true })
      .then(function (stream) {
        if (!isInRecordingMode()) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          return;
        }
        audioStream = stream;
        connectWaveformAnalyser(stream);
      })
      .catch(function () {
        // マイク取得失敗 — 波形なしだが認識は動作する
      });
  }

  /** 既存ストリームで波形アニメーション開始（MediaRecorder 用） */
  function startWaveformFromStream(stream) {
    audioStream = null; // ストリームの管理は fallbackStream 側が行う
    connectWaveformAnalyser(stream);
  }

  /** ストリームを AnalyserNode に接続して描画ループ開始 */
  function connectWaveformAnalyser(stream) {
    try {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      var source = audioCtx.createMediaStreamSource(stream);
      audioAnalyser = audioCtx.createAnalyser();
      audioAnalyser.fftSize = 128;
      audioAnalyser.smoothingTimeConstant = 0.7;
      source.connect(audioAnalyser);
      drawWaveform();
    } catch (e) {
      // AudioContext 非対応 — 波形なし
    }
  }

  /** Waveform アニメーション停止 */
  function stopWaveformAnimation() {
    if (waveAnimId) {
      cancelAnimationFrame(waveAnimId);
      waveAnimId = null;
    }
    if (audioStream) {
      audioStream.getTracks().forEach(function (t) { t.stop(); });
      audioStream = null;
    }
    if (audioCtx && audioCtx.state !== 'closed') {
      audioCtx.close().catch(function () {});
      audioCtx = null;
    }
    audioAnalyser = null;
  }

  /** Waveform 描画ループ（周波数データからバー描画） */
  function drawWaveform() {
    waveAnimId = requestAnimationFrame(drawWaveform);

    if (!audioAnalyser || !waveCtx || !waveCanvas) return;

    var bufferLength = audioAnalyser.frequencyBinCount;
    var dataArray = new Uint8Array(bufferLength);
    audioAnalyser.getByteFrequencyData(dataArray);

    var width = waveCanvas.width;
    var height = waveCanvas.height;
    waveCtx.clearRect(0, 0, width, height);

    var barCount = 50;
    var totalW = width * 0.9;
    var startX = (width - totalW) / 2;
    var barW = totalW / barCount * 0.6;
    var gap = totalW / barCount * 0.4;

    waveCtx.fillStyle = '#568184';
    for (var i = 0; i < barCount; i++) {
      var idx = Math.floor(i * bufferLength / barCount);
      var amp = dataArray[idx] / 255.0;
      var barH = Math.max(2, amp * height * 0.85);
      var x = startX + i * (barW + gap);
      var y = (height - barH) / 2;
      waveCtx.fillRect(x, y, barW, barH);
    }
  }

  // ---------------------------------------------------------
  // エラーハンドリング
  // ---------------------------------------------------------

  /**
   * 音声認識エラーをユーザーに通知する
   * @param {string} errorType — SpeechRecognitionErrorEvent.error
   */
  function handleVoiceError(errorType) {
    var messages = {
      'not-allowed':    '\u30DE\u30A4\u30AF\u306E\u4F7F\u7528\u304C\u8A31\u53EF\u3055\u308C\u3066\u3044\u307E\u305B\u3093\u3002\u30D6\u30E9\u30A6\u30B6\u306E\u8A2D\u5B9A\u304B\u3089\u30DE\u30A4\u30AF\u3092\u8A31\u53EF\u3057\u3066\u304F\u3060\u3055\u3044\u3002',
      // マイクの使用が許可されていません。ブラウザの設定からマイクを許可してください。
      'no-speech':      '\u97F3\u58F0\u304C\u691C\u51FA\u3055\u308C\u307E\u305B\u3093\u3067\u3057\u305F\u3002\u3082\u3046\u4E00\u5EA6\u304A\u8A66\u3057\u304F\u3060\u3055\u3044\u3002',
      // 音声が検出されませんでした。もう一度お試しください。
      'network':        '\u97F3\u58F0\u8A8D\u8B58\u306E\u901A\u4FE1\u30A8\u30E9\u30FC\u304C\u767A\u751F\u3057\u307E\u3057\u305F\u3002',
      // 音声認識の通信エラーが発生しました。
      'audio-capture':  '\u30DE\u30A4\u30AF\u304C\u898B\u3064\u304B\u308A\u307E\u305B\u3093\u3002\u30DE\u30A4\u30AF\u304C\u63A5\u7D9A\u3055\u308C\u3066\u3044\u308B\u304B\u78BA\u8A8D\u3057\u3066\u304F\u3060\u3055\u3044\u3002',
      // マイクが見つかりません。マイクが接続されているか確認してください。
      'aborted':        null  // User-initiated abort — no message needed
    };

    var msg = messages[errorType];
    if (msg === null) return;
    if (msg === undefined) {
      msg = '\u97F3\u58F0\u5165\u529B\u3067\u30A8\u30E9\u30FC\u304C\u767A\u751F\u3057\u307E\u3057\u305F\u3002';
      // 音声入力でエラーが発生しました。
    }
    setError(msg);
  }

  /* ============================
     Quick Prompt — Tab Switching & Chip Rendering
     ============================ */

  /** localStorage からモードを復元 */
  function restorePromptMode() {
    try {
      var saved = localStorage.getItem(STORAGE_KEY_PROMPT_MODE);
      if (saved === 'beginner' || saved === 'standard') return saved;
    } catch (e) {}
    return config.initialPromptMode || 'beginner';
  }

  /** モードタブの is-active を更新 */
  function updateModeTabs() {
    if (!els.quickArea) return;
    var tabs = els.quickArea.querySelectorAll('.mw-chat-quick__mode-tab');
    for (var i = 0; i < tabs.length; i++) {
      if (tabs[i].getAttribute('data-mode') === promptMode) {
        tabs[i].classList.add('is-active');
      } else {
        tabs[i].classList.remove('is-active');
      }
    }
  }

  /** カテゴリタブの is-active を更新 */
  function updateCatTabs() {
    if (!els.quickArea) return;
    var tabs = els.quickArea.querySelectorAll('.mw-chat-quick__cat-tab');
    for (var i = 0; i < tabs.length; i++) {
      if (tabs[i].getAttribute('data-cat') === promptCat) {
        tabs[i].classList.add('is-active');
      } else {
        tabs[i].classList.remove('is-active');
      }
    }
  }

  /** チップを動的に描画 */
  function renderQuickChips() {
    var container = document.getElementById('mwChatQuickChips');
    if (!container) return;

    var prompts = config.quickPrompts;
    if (!prompts || !prompts[promptMode] || !prompts[promptMode][promptCat]) {
      container.innerHTML = '';
      return;
    }

    var items = prompts[promptMode][promptCat];
    var html = '';
    for (var i = 0; i < items.length; i++) {
      html += '<button type="button" class="mw-chat-quick__chip" data-question="'
            + escapeAttr(items[i]) + '">'
            + escapeHtmlStr(items[i]) + '</button>';
    }
    container.innerHTML = html;
  }

  /** HTML属性エスケープ */
  function escapeAttr(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /** HTMLテキストエスケープ */
  function escapeHtmlStr(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /** クイックプロンプト初期化 */
  function initQuickPrompts() {
    promptMode = restorePromptMode();
    promptCat  = 'status';
    updateModeTabs();
    updateCatTabs();
    renderQuickChips();
  }

  /* ============================
     Event Binding
     ============================ */
  function bindEvents() {
    // FAB click → 決済未完了なら payment-status へリダイレクト、完了済みなら panel を開く
    els.fab.addEventListener('click', function () {
      if ( !config.paymentActive && config.paymentStatusUrl ) {
        window.location.href = config.paymentStatusUrl;
        return;
      }
      switchViewMode('panel');
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

    // Quick prompt — モードタブ切替（event delegation）
    els.quickArea.addEventListener('click', function (e) {
      // モードタブ
      var modeTab = e.target.closest('.mw-chat-quick__mode-tab');
      if (modeTab) {
        var newMode = modeTab.getAttribute('data-mode');
        if (newMode && newMode !== promptMode) {
          promptMode = newMode;
          updateModeTabs();
          renderQuickChips();
          try { localStorage.setItem(STORAGE_KEY_PROMPT_MODE, promptMode); } catch (ex) {}
        }
        return;
      }

      // カテゴリタブ
      var catTab = e.target.closest('.mw-chat-quick__cat-tab');
      if (catTab) {
        var newCat = catTab.getAttribute('data-cat');
        if (newCat && newCat !== promptCat) {
          promptCat = newCat;
          updateCatTabs();
          renderQuickChips();
        }
        return;
      }

      // チップ — 入力欄にテキスト挿入（即送信ではなく挿入のみ）
      var chip = e.target.closest('.mw-chat-quick__chip');
      if (!chip) return;
      var q = chip.getAttribute('data-question');
      if (q && !state.isLoading && els.textarea) {
        els.textarea.value = q;
        autoResize(els.textarea);
        els.textarea.focus();
      }
    });

    // Send button: 通常時は送信、録音モード時は確定
    els.sendBtn.addEventListener('click', function () {
      if (isInRecordingMode()) {
        confirmVoice();
      } else {
        sendMessage();
      }
    });

    // Voice button: 通常時は録音開始、録音モード時はキャンセル
    // recognition(SpeechRecognition) または useFallback(MediaRecorder) のいずれかが有効な場合
    if (els.voiceBtn && (recognition || useFallback)) {
      els.voiceBtn.addEventListener('click', function () {
        if (isInRecordingMode()) {
          cancelVoice();
        } else {
          startVoice();
        }
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
    els.inputArea   = els.root.querySelector('.mw-chat-input');
    els.inputRow    = els.root.querySelector('.mw-chat-input__row');
    els.textarea    = els.root.querySelector('.mw-chat-input__textarea');
    els.sendBtn     = els.root.querySelector('.mw-chat-input__btn--send');
    els.voiceBtn    = els.root.querySelector('.mw-chat-input__btn--voice');
    els.closeBtn    = els.root.querySelector('.mw-chat-header__btn--close');
    els.expandBtn   = els.root.querySelector('.mw-chat-header__btn--expand');
    els.collapseBtn = els.root.querySelector('.mw-chat-header__btn--collapse');
    els.quickArea   = els.root.querySelector('.mw-chat-quick');

    if (!els.fab || !els.messages || !els.textarea) return;

    initVoice();
    bindEvents();
    initQuickPrompts();

    // セッション復元: 前ページの会話履歴をDOMに再描画
    var hadHistory = restoreSession();

    // viewMode 復元: チャットが開いていた状態を維持
    var savedMode = restoreViewMode();
    if (savedMode && hadHistory) {
      switchViewMode(savedMode);
    }
  }

  /* ============================
     Expose public API
     ============================ */
  /**
   * チャットを開いてメッセージを自動送信する。
   * 「AIに聞く」ボタンから呼ばれる。
   * @param {string} text — 送信するメッセージテキスト
   * @param {Object} [sectionContext] — セクションコンテキスト（「AIに聞く」ボタン周辺のテキスト情報）
   */
  function openWithPrompt(text, sectionContext) {
    // 決済未完了なら payment-status へリダイレクト（FABと同じチェック）
    if (!config.paymentActive && config.paymentStatusUrl) {
      window.location.href = config.paymentStatusUrl;
      return;
    }

    // 最大化（panel）で開く — FABと同じサイズ
    switchViewMode('panel');

    if (!text) return;

    // チャットのopen transitionが完了してからメッセージを送信
    // switchViewMode の CSS transition が 300ms なのでそれ以降に実行
    var sendOpts = sectionContext ? { sectionContext: sectionContext } : undefined;
    setTimeout(function () {
      sendMessage(text, sendOpts);
    }, 350);
  }

  /* ============================
     Section Context Extraction for [data-ai-ask] buttons
     ============================ */

  /**
   * 「AIに聞く」ボタンの親セクションからコンテキストテキストを抽出する。
   * data-ai-section 属性を持つ最寄りの祖先要素を探し、
   * その中のタイトル・本文テキストを構造化オブジェクトとして返す。
   *
   * @param {HTMLElement} btn — クリックされた [data-ai-ask] ボタン
   * @returns {Object|null} { sectionType, sectionTitle, sectionBody, pageType }
   */
  function extractSectionContext(btn) {
    var container = btn.closest('[data-ai-section]');
    if (!container) return null;

    var sectionType = container.getAttribute('data-ai-section') || '';

    // セクションタイトル: 最初の見出し要素のテキスト
    var headingEl = container.querySelector(
      '.section-title, .info-monthly-title, .info-monthly-highlight-label'
    );
    var sectionTitle = headingEl ? headingEl.textContent.trim() : '';

    // セクション本文: コンテンツコンテナのテキスト
    var bodyEl = container.querySelector(
      '.section-content, .info-monthly-summary, .info-summary-blocks, .info-monthly-highlight-value'
    );
    var sectionBody = bodyEl ? bodyEl.textContent.trim() : '';

    // ハイライト詳細アコーディオンの中身も含める（fact/causes/actions）
    var detailEl = container.querySelector('.highlight-detail-body');
    if (detailEl) {
      var detailText = detailEl.textContent.trim();
      if (detailText) {
        sectionBody += '\n' + detailText;
      }
    }

    // 長すぎるテキストは切り詰め（APIペイロード肥大化防止）
    if (sectionBody.length > 2000) {
      sectionBody = sectionBody.substring(0, 2000) + '…';
    }

    // ページ種別判定（URLベース）
    var path = window.location.pathname;
    var pageType = 'unknown';
    if (path.indexOf('/dashboard') !== -1) {
      pageType = 'dashboard';
    } else if (path.indexOf('/report') !== -1) {
      pageType = 'report';
    }

    return {
      sectionType:  sectionType,
      sectionTitle: sectionTitle,
      sectionBody:  sectionBody,
      pageType:     pageType
    };
  }

  /* ============================
     Global Event Delegation for [data-ai-ask] buttons
     ============================ */
  function bindAskAiButtons() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-ai-ask]');
      if (!btn) return;

      e.preventDefault();

      // セクションコンテキストの抽出を試みる
      var ctx = extractSectionContext(btn);

      if (ctx && ctx.sectionBody) {
        // コンテキスト付き: data-ai-instruction を指示として使用
        var instruction = btn.getAttribute('data-ai-instruction')
          || 'このレポート内容をもとに、なぜこうなっているのか、背景に考えられる要因、今すぐ取れる具体的な改善アクションを教えてください';
        openWithPrompt(instruction, ctx);
      } else {
        // フォールバック: 従来の data-ai-prompt（data-ai-section がない旧マークアップ対応）
        var prompt = btn.getAttribute('data-ai-prompt') || '';
        if (prompt) {
          openWithPrompt(prompt);
        }
      }
    });
  }

  window.GCREV = window.GCREV || {};
  window.GCREV.chat = {
    switchViewMode:        switchViewMode,
    sendMessage:           sendMessage,
    appendUserMessage:     appendUserMessage,
    appendAssistantMessage: appendAssistantMessage,
    setLoading:            setLoading,
    setError:              setError,
    openWithPrompt:        openWithPrompt
  };

  // data-ai-ask のイベント委譲は DOMContentLoaded 不要（document.click）
  bindAskAiButtons();

  document.addEventListener('DOMContentLoaded', init);
})();
