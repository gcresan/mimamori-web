/*!
 * みまもりチャットボット - iframe SPA v0.1.0
 * 依存なし。embed.html 内で読み込まれる。
 */
(function () {
  'use strict';

  var app = document.getElementById('app');
  if (!app) return;

  // public_key は URL fragment 経由で渡される（Refererに乗らない）
  var hashKey = '';
  if (location.hash) {
    var m = /key=([^&]+)/.exec(location.hash.slice(1));
    if (m) hashKey = decodeURIComponent(m[1]);
  }
  var TENANT     = app.dataset.tenant;
  var PUBKEY     = hashKey || app.dataset.pubkey;
  var API        = app.dataset.api;
  var TITLE      = app.dataset.title || 'AIアシスタント';
  var AVATAR_SVG = app.dataset.avatarSvg || ''; // 担当アバター (SVG文字列, 空ならアイコンなし)

  if (!TENANT || !PUBKEY || !API) {
    app.textContent = '初期化に失敗しました。';
    return;
  }

  // セッション状態（localStorage は iframe origin スコープなので親 ekc.jp とは分離）
  var SESSION_KEY = 'mb_session_' + TENANT;
  var session = null;
  try { session = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null'); } catch (e) {}

  // textContent で安全に入れたいので、innerHTML の段階ではプレースホルダ
  // ヘッダーアバターは AVATAR_SVG がある時のみ後で挿入
  app.innerHTML =
    '<header class="mb-header">' +
      '<h1 id="mb-title-wrap"><span id="mb-header-avatar"></span><span id="mb-title"></span></h1>' +
      '<button type="button" id="mb-close" aria-label="閉じる">×</button>' +
    '</header>' +
    '<div class="mb-list" id="mb-list" role="log" aria-live="polite"></div>' +
    '<div class="mb-starters" id="mb-starters"></div>' +
    '<div class="mb-typing" id="mb-typing" hidden>AI が考えています...</div>' +
    '<form class="mb-input" id="mb-form" autocomplete="off">' +
      '<textarea id="mb-text" rows="1" placeholder="メッセージを入力..." maxlength="2000" autocomplete="off"></textarea>' +
      '<button type="submit" id="mb-send">送信</button>' +
    '</form>';

  var $title       = document.getElementById('mb-title');
  var $headerAvatar = document.getElementById('mb-header-avatar');
  var $list        = document.getElementById('mb-list');
  var $starters    = document.getElementById('mb-starters');
  var $typing      = document.getElementById('mb-typing');
  var $text        = document.getElementById('mb-text');
  var $send        = document.getElementById('mb-send');
  var $form        = document.getElementById('mb-form');
  var $close       = document.getElementById('mb-close');

  if ($title) $title.textContent = TITLE;
  // ヘッダー左にアバターを差し込む (空なら非表示のまま)
  if ($headerAvatar && AVATAR_SVG) {
    $headerAvatar.className = 'mb-header-avatar';
    $headerAvatar.innerHTML = AVATAR_SVG;
  }

  $close.addEventListener('click', function () {
    parent.postMessage({ source: 'mimamori-bot', type: 'close' }, '*');
  });

  // ---- API ヘルパ ----
  function apiPost(path, body) {
    return fetch(API + path, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Mimamori-Public-Key': PUBKEY
      },
      // same-origin: 同origin (mimamori-web.jp 内の iframe → mimamori-web.jp API) で
      // Cookie / Basic認証 を引き継ぐ。Dev環境の Basic Auth 通過に必要。
      // 本番ではBasic認証なしなので影響なし。クロスoriginでは credentials は送られない。
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    }).then(function (res) {
      return res.json().then(function (j) {
        if (!res.ok) throw j;
        return j;
      });
    });
  }

  // ---- メッセージ描画 ----
  // 各メッセージは <div class="mb-row {role}"> でラップし、
  // assistant + AVATAR_SVG ありなら左にアバター円を並べる。
  function appendMessage(role, text, suggestions) {
    var row = document.createElement('div');
    row.className = 'mb-row ' + role;

    if (role === 'assistant' && AVATAR_SVG) {
      var av = document.createElement('div');
      av.className = 'mb-msg-avatar';
      av.innerHTML = AVATAR_SVG; // SVG は plugin 内定数由来 (XSS なし)
      row.appendChild(av);
    }

    var div = document.createElement('div');
    div.className = 'mb-msg ' + role;
    div.textContent = text;
    if (suggestions && suggestions.length) {
      var s = document.createElement('div');
      s.className = 'mb-suggestions';
      suggestions.forEach(function (sg) {
        if (sg.url) {
          var a = document.createElement('a');
          a.className = 'mb-suggestion';
          a.href = sg.url; a.target = '_top'; a.rel = 'noopener';
          a.textContent = sg.label;
          a.addEventListener('click', function () {
            recordEvent('cta_' + (sg.type || 'click') + '_click', { url: sg.url });
          });
          s.appendChild(a);
        }
      });
      div.appendChild(s);
    }
    row.appendChild(div);
    $list.appendChild(row);
    $list.scrollTop = $list.scrollHeight;
  }

  function appendSystem(text, isError) {
    var row = document.createElement('div');
    row.className = 'mb-row system';
    var div = document.createElement('div');
    div.className = 'mb-msg system' + (isError ? ' mb-error' : '');
    div.textContent = text;
    row.appendChild(div);
    $list.appendChild(row);
    $list.scrollTop = $list.scrollHeight;
  }

  function renderStarters(arr) {
    $starters.innerHTML = '';
    (arr || []).forEach(function (s) {
      var b = document.createElement('button');
      b.className = 'mb-starter';
      b.type = 'button';
      b.textContent = s;
      b.addEventListener('click', function () {
        $text.value = s;
        $form.requestSubmit();
      });
      $starters.appendChild(b);
    });
  }

  // ---- セッション開始 ----
  var FALLBACK_WELCOME = 'こんにちは。お気軽にご質問ください。';

  function renderIntro(welcome, starters) {
    appendMessage('assistant', welcome || FALLBACK_WELCOME);
    renderStarters(starters || []);
  }

  // 初期化フロー専用: イントロ (welcome + starters) を描画してセッションを返す。
  // submit からは呼ばないこと — 毎メッセージごとに welcome が増殖する。
  function startSessionWithIntro() {
    if (session && session.uuid) {
      renderIntro(session.welcome_message, session.starters);
      return Promise.resolve(session);
    }
    return apiPost('/session', {
      user_agent: navigator.userAgent,
      referer: document.referrer,
      landing_url: location.href
    }).then(function (r) {
      var welcome = (r && typeof r.welcome_message === 'string' && r.welcome_message.trim() !== '')
        ? r.welcome_message
        : FALLBACK_WELCOME;
      session = {
        uuid:            r.session_uuid,
        persona:         r.persona,
        starters:        r.starters || [],
        welcome_message: welcome
      };
      try { localStorage.setItem(SESSION_KEY, JSON.stringify(session)); } catch (e) {}
      renderIntro(welcome, session.starters);
      return session;
    });
  }

  // submit 側で使う: セッション参照を返すだけ (UI は触らない)
  function ensureSession() {
    if (session && session.uuid) return Promise.resolve(session);
    // 念のため: 初期化が未完了の状態で submit された場合のフォールバック (intro 描画はしない)
    return apiPost('/session', {
      user_agent: navigator.userAgent,
      referer: document.referrer,
      landing_url: location.href
    }).then(function (r) {
      var welcome = (r && typeof r.welcome_message === 'string' && r.welcome_message.trim() !== '')
        ? r.welcome_message
        : FALLBACK_WELCOME;
      session = {
        uuid:            r.session_uuid,
        persona:         r.persona,
        starters:        r.starters || [],
        welcome_message: welcome
      };
      try { localStorage.setItem(SESSION_KEY, JSON.stringify(session)); } catch (e) {}
      return session;
    });
  }

  // ---- 送信 ----
  // IME composition 中フラグ (Enter 確定時の二重送信防止)
  var composing = false;
  $text.addEventListener('compositionstart', function () { composing = true; });
  $text.addEventListener('compositionend',   function () { composing = false; });

  // ---- 送信時の効果音 "シュッ" (Web Audio API でその場合成) ----
  // sine の高音→低音スイープ + 短いノイズで風切り感を出す。
  var sendAudioCtx = null;
  function playSendSound() {
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      if (!sendAudioCtx) sendAudioCtx = new AC();
      if (sendAudioCtx.state === 'suspended') sendAudioCtx.resume();
      var ctx = sendAudioCtx;
      var now = ctx.currentTime;
      var dur = 0.16;

      // 1) ピッチ下降 sine (1400Hz → 500Hz) — "シュッ" の主成分
      var osc = ctx.createOscillator();
      var oscGain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(1400, now);
      osc.frequency.exponentialRampToValueAtTime(500, now + dur);
      oscGain.gain.setValueAtTime(0.0001, now);
      oscGain.gain.exponentialRampToValueAtTime(0.10, now + 0.008);
      oscGain.gain.exponentialRampToValueAtTime(0.0001, now + dur);
      osc.connect(oscGain).connect(ctx.destination);
      osc.start(now);
      osc.stop(now + dur + 0.02);

      // 2) 軽いホワイトノイズ → ハイパスで風切り音っぽく
      var bufferSize = Math.floor(ctx.sampleRate * dur);
      var noiseBuf = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
      var data = noiseBuf.getChannelData(0);
      for (var i = 0; i < bufferSize; i++) data[i] = (Math.random() * 2 - 1);
      var noise = ctx.createBufferSource();
      noise.buffer = noiseBuf;
      var hp = ctx.createBiquadFilter();
      hp.type = 'highpass';
      hp.frequency.value = 2200;
      var noiseGain = ctx.createGain();
      noiseGain.gain.setValueAtTime(0.0001, now);
      noiseGain.gain.exponentialRampToValueAtTime(0.06, now + 0.005);
      noiseGain.gain.exponentialRampToValueAtTime(0.0001, now + dur);
      noise.connect(hp).connect(noiseGain).connect(ctx.destination);
      noise.start(now);
      noise.stop(now + dur + 0.02);
    } catch (e) { /* 致命的でない */ }
  }

  function clearInput() {
    // IME 確定が遅延して value が復活するケース対策で blur → clear → 次フレームで再フォーカス
    $text.blur();
    $text.value = '';
    // 一部ブラウザで value 復元を抑止するため input イベントを明示発火
    try { $text.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
    requestAnimationFrame(function () {
      $text.value = '';
      $text.focus();
    });
  }

  $form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (composing) return; // IME 変換中は無視
    var msg = ($text.value || '').trim();
    if (!msg) return;
    playSendSound();
    clearInput();
    $starters.innerHTML = '';
    appendMessage('user', msg);
    $send.disabled = true;
    $typing.hidden = false;

    ensureSession()
      .then(function (sess) {
        return apiPost('/message', { session_uuid: sess.uuid, message: msg, page_url: location.href });
      })
      .then(function (r) {
        $typing.hidden = true;
        $send.disabled = false;
        appendMessage('assistant', r.reply || '...', r.suggested_actions || []);
      })
      .catch(function (err) {
        $typing.hidden = true;
        $send.disabled = false;
        var msg = (err && err.message) || 'エラーが発生しました。しばらくしてからお試しください。';
        appendSystem(msg, true);
      });
  });

  // Enter で送信、Shift+Enter で改行 (IME 変換確定の Enter は除外)
  $text.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing && !composing && ev.keyCode !== 229) {
      ev.preventDefault();
      $form.requestSubmit();
    }
  });

  // ---- イベント送信 ----
  function recordEvent(type, payload) {
    if (!session || !session.uuid) return;
    apiPost('/event', { session_uuid: session.uuid, type: type, payload: payload || {} })
      .catch(function () { /* 失敗は致命的ではないので握りつぶす */ });
  }

  // ---- 初期化 — イントロ (welcome + starters) はここでだけ描画する ----
  startSessionWithIntro().catch(function (err) {
    appendSystem((err && err.message) || '接続できませんでした。', true);
  });
})();
