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
  var TENANT = app.dataset.tenant;
  var PUBKEY = hashKey || app.dataset.pubkey;
  var API    = app.dataset.api;

  if (!TENANT || !PUBKEY || !API) {
    app.textContent = '初期化に失敗しました。';
    return;
  }

  // セッション状態（localStorage は iframe origin スコープなので親 ekc.jp とは分離）
  var SESSION_KEY = 'mb_session_' + TENANT;
  var session = null;
  try { session = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null'); } catch (e) {}

  // ---- DOM 構築 ----
  app.innerHTML =
    '<header class="mb-header">' +
      '<h1>AIアシスタント</h1>' +
      '<button type="button" id="mb-close" aria-label="閉じる">×</button>' +
    '</header>' +
    '<div class="mb-list" id="mb-list" role="log" aria-live="polite"></div>' +
    '<div class="mb-starters" id="mb-starters"></div>' +
    '<div class="mb-typing" id="mb-typing" hidden>AI が考えています...</div>' +
    '<form class="mb-input" id="mb-form">' +
      '<textarea id="mb-text" rows="1" placeholder="メッセージを入力..." maxlength="2000"></textarea>' +
      '<button type="submit" id="mb-send">送信</button>' +
    '</form>';

  var $list     = document.getElementById('mb-list');
  var $starters = document.getElementById('mb-starters');
  var $typing   = document.getElementById('mb-typing');
  var $text     = document.getElementById('mb-text');
  var $send     = document.getElementById('mb-send');
  var $form     = document.getElementById('mb-form');
  var $close    = document.getElementById('mb-close');

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
  function appendMessage(role, text, suggestions) {
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
    $list.appendChild(div);
    $list.scrollTop = $list.scrollHeight;
  }

  function appendSystem(text, isError) {
    var div = document.createElement('div');
    div.className = 'mb-msg system' + (isError ? ' mb-error' : '');
    div.textContent = text;
    $list.appendChild(div);
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
  function ensureSession() {
    if (session && session.uuid) return Promise.resolve(session);
    return apiPost('/session', {
      user_agent: navigator.userAgent,
      referer: document.referrer,
      landing_url: location.href
    }).then(function (r) {
      session = { uuid: r.session_uuid, persona: r.persona, starters: r.starters || [] };
      try { localStorage.setItem(SESSION_KEY, JSON.stringify(session)); } catch (e) {}
      renderStarters(session.starters);
      appendSystem('AIアシスタントです。お気軽にどうぞ。');
      return session;
    });
  }

  // ---- 送信 ----
  $form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var msg = ($text.value || '').trim();
    if (!msg) return;
    $text.value = '';
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

  // Enter で送信、Shift+Enter で改行
  $text.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey) {
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

  // ---- 初期化 ----
  ensureSession().catch(function (err) {
    appendSystem((err && err.message) || '接続できませんでした。', true);
  });
})();
