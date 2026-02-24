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
    isRecording: false,
    hasError: false,
    history: [],  // 会話履歴 [{role:'user',content:'...'},{role:'assistant',content:'...'},...]
    options: {
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
     Voice Input (Web Speech API + Waveform)
     ============================ */

  var VOICE_MAX_DURATION = 45000;   // 最大45秒（安全制限）
  var VOICE_SILENCE_TIMEOUT = 3000; // 沈黙3秒で認識自動停止

  /** Audio visualization state */
  var audioCtx = null;
  var audioAnalyser = null;
  var audioStream = null;
  var waveAnimId = null;
  var waveCanvas = null;
  var waveCtx = null;

  /** 録音中にバッファするテキスト */
  var voiceBuffer = '';

  /** 録音モードUIが表示中かどうか */
  function isInRecordingMode() {
    return els.inputArea && els.inputArea.classList.contains('mw-chat-input--recording');
  }

  /**
   * 音声認識を初期化する
   * continuous: true で長時間（20〜30秒）の発話に対応
   * 非対応ブラウザではボタンを非表示にする
   */
  function initVoice() {
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition || !els.voiceBtn) {
      if (els.voiceBtn) els.voiceBtn.style.display = 'none';
      return;
    }

    recognition = new SpeechRecognition();
    recognition.lang = 'ja-JP';
    recognition.interimResults = true;
    recognition.continuous = true;
    recognition.maxAlternatives = 1;

    // --- Waveform DOM element (hidden by default via CSS) ---
    if (els.inputRow) {
      var waveContainer = document.createElement('div');
      waveContainer.className = 'mw-chat-input__waveform';
      waveCanvas = document.createElement('canvas');
      waveCanvas.width = 600;
      waveCanvas.height = 80;
      waveContainer.appendChild(waveCanvas);
      waveCtx = waveCanvas.getContext('2d');
      els.inputRow.insertBefore(waveContainer, els.voiceBtn);
    }

    // --- Recognition event handlers ---
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
          recognition.stop(); // 認識停止（録音UIはそのまま）
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
      stopWaveformAnimation();
      // 録音UIは維持 — ユーザーが ✓ or ✕ をクリックするのを待つ
    });

    recognition.addEventListener('error', function (e) {
      state.isRecording = false;
      clearTimers();
      stopWaveformAnimation();
      exitRecordingMode();
      if (e.error !== 'aborted') {
        handleVoiceError(e.error);
      }
    });
  }

  /** 録音を開始し、録音モードUIに切り替える */
  function startVoice() {
    if (!recognition || state.isRecording || state.isLoading) return;

    voiceBuffer = '';
    enterRecordingMode();

    try {
      recognition.start();
    } catch (e) {
      exitRecordingMode();
      return;
    }

    startWaveformAnimation();
  }

  /** 録音を確定し、テキストを入力欄に配置する */
  function confirmVoice() {
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

  /** 録音をキャンセルし、テキストを破棄する */
  function cancelVoice() {
    if (recognition && state.isRecording) {
      try { recognition.stop(); } catch (e) {}
    }
    stopWaveformAnimation();
    voiceBuffer = '';
    exitRecordingMode();
  }

  /** 録音モードUIに切り替える（テキスト欄→波形、ボタンアイコン差替） */
  function enterRecordingMode() {
    if (els.inputArea) els.inputArea.classList.add('mw-chat-input--recording');

    if (els.voiceBtn) {
      els.voiceBtn.textContent = '\u2715'; // ✕
      els.voiceBtn.title = '\u30AD\u30E3\u30F3\u30BB\u30EB'; // キャンセル
      els.voiceBtn.setAttribute('aria-label', '\u30AD\u30E3\u30F3\u30BB\u30EB');
    }
    if (els.sendBtn) {
      els.sendBtn.textContent = '\u2713'; // ✓
      els.sendBtn.title = '\u78BA\u5B9A'; // 確定
      els.sendBtn.setAttribute('aria-label', '\u78BA\u5B9A');
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
    }
    if (els.sendBtn) {
      els.sendBtn.textContent = '\u27A4'; // ➤
      els.sendBtn.title = '\u9001\u4FE1'; // 送信
      els.sendBtn.setAttribute('aria-label', '\u9001\u4FE1');
    }
  }

  /** Waveform アニメーション開始（Web Audio API で実波形描画） */
  function startWaveformAnimation() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;

    navigator.mediaDevices.getUserMedia({ audio: true })
      .then(function (stream) {
        if (!isInRecordingMode()) {
          stream.getTracks().forEach(function (t) { t.stop(); });
          return;
        }
        audioStream = stream;
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        var source = audioCtx.createMediaStreamSource(stream);
        audioAnalyser = audioCtx.createAnalyser();
        audioAnalyser.fftSize = 128;
        audioAnalyser.smoothingTimeConstant = 0.7;
        source.connect(audioAnalyser);
        drawWaveform();
      })
      .catch(function () {
        // マイク取得失敗 — 波形なしだが認識は動作する
      });
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

    waveCtx.fillStyle = '#4a6d7c';
    for (var i = 0; i < barCount; i++) {
      var idx = Math.floor(i * bufferLength / barCount);
      var amp = dataArray[idx] / 255.0;
      var barH = Math.max(2, amp * height * 0.85);
      var x = startX + i * (barW + gap);
      var y = (height - barH) / 2;
      waveCtx.fillRect(x, y, barW, barH);
    }
  }

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

    // Send button: 通常時は送信、録音モード時は確定
    els.sendBtn.addEventListener('click', function () {
      if (isInRecordingMode()) {
        confirmVoice();
      } else {
        sendMessage();
      }
    });

    // Voice button: 通常時は録音開始、録音モード時はキャンセル
    if (els.voiceBtn && recognition) {
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
