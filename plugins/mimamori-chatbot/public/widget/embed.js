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
      '<div class="mb-header-left">' +
        '<span class="mb-header-avatar" id="mb-header-avatar" aria-hidden="true"></span>' +
        '<span class="mb-title" id="mb-title"></span>' +
      '</div>' +
      '<div class="mb-header-right">' +
        '<span class="mb-status" aria-label="ステータス: 相談受付中">' +
          '<span class="mb-status-dot"></span><span>相談受付中</span>' +
        '</span>' +
        '<button type="button" class="mb-icon-btn" id="mb-minimize" aria-label="最大化" title="最大化">' +
          '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M14 4 L20 4 L20 10 M20 4 L14 10 M10 20 L4 20 L4 14 M4 20 L10 14"/>' +
          '</svg>' +
        '</button>' +
        '<button type="button" class="mb-icon-btn" id="mb-close" aria-label="閉じる" title="閉じる">' +
          '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M6 6 L18 18 M18 6 L6 18"/>' +
          '</svg>' +
        '</button>' +
      '</div>' +
    '</header>' +
    '<div class="mb-quick" id="mb-quick">' +
      '<button type="button" class="mb-quick-toggle" id="mb-quick-toggle" aria-expanded="true">' +
        '<span class="mb-quick-toggle-label">💡 質問例を見る</span>' +
        '<span class="mb-quick-arrow" aria-hidden="true">▲</span>' +
      '</button>' +
      '<div class="mb-quick-body" id="mb-quick-body">' +
        '<div class="mb-cat-tabs"  id="mb-cat-tabs"  role="tablist"></div>' +
        '<div class="mb-quick-chips" id="mb-quick-chips"></div>' +
      '</div>' +
    '</div>' +
    '<div class="mb-list" id="mb-list" role="log" aria-live="polite"></div>' +
    '<form class="mb-input" id="mb-form" autocomplete="off">' +
      '<textarea id="mb-text" rows="1" placeholder="メッセージを入力..." maxlength="2000" autocomplete="off"></textarea>' +
      '<button type="submit" id="mb-send">送信</button>' +
    '</form>';

  var $title        = document.getElementById('mb-title');
  var $headerAvatar = document.getElementById('mb-header-avatar');
  var $list         = document.getElementById('mb-list');
  var $text         = document.getElementById('mb-text');
  var $send         = document.getElementById('mb-send');
  var $form         = document.getElementById('mb-form');
  var $close        = document.getElementById('mb-close');
  var $minimize     = document.getElementById('mb-minimize');
  var $quick        = document.getElementById('mb-quick');
  var $quickToggle  = document.getElementById('mb-quick-toggle');
  var $catTabs      = document.getElementById('mb-cat-tabs');
  var $quickChips   = document.getElementById('mb-quick-chips');

  if ($title) $title.textContent = TITLE;
  // ヘッダー左にアバターを差し込む (空なら非表示のまま)
  if ($headerAvatar && AVATAR_SVG) {
    $headerAvatar.className = 'mb-header-avatar';
    $headerAvatar.innerHTML = AVATAR_SVG;
  }

  $close.addEventListener('click', function () {
    parent.postMessage({ source: 'mimamori-bot', type: 'close' }, '*');
  });
  if ($minimize) {
    $minimize.addEventListener('click', function () {
      // 最大化: 親ページに通知 → widget.js が iframe を全画面表示
      // (もう一度押すと通常サイズに戻る — トグル)
      parent.postMessage({ source: 'mimamori-bot', type: 'maximize' }, '*');
    });
  }

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
  // ユーザー用デフォルト人物アイコン (グレー)
  var USER_AVATAR_SVG =
    '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' +
      '<path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z"/>' +
    '</svg>';

  function pad2(n) { return n < 10 ? '0' + n : '' + n; }
  function formatTime(d) { return pad2(d.getHours()) + ':' + pad2(d.getMinutes()); }

  function appendMessage(role, text, suggestions) {
    var wrap = document.createElement('div');
    wrap.className = 'mb-row-wrap ' + role;

    var row = document.createElement('div');
    row.className = 'mb-row ' + role;

    // assistant: 左にアバター
    if (role === 'assistant' && AVATAR_SVG) {
      var av = document.createElement('div');
      av.className = 'mb-msg-avatar';
      av.innerHTML = AVATAR_SVG;
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

    // user: 右にアバター
    if (role === 'user') {
      var uav = document.createElement('div');
      uav.className = 'mb-msg-avatar mb-user-avatar';
      uav.innerHTML = USER_AVATAR_SVG;
      row.appendChild(uav);
    }

    wrap.appendChild(row);

    // タイムスタンプ (system 以外)
    if (role !== 'system') {
      var ts = document.createElement('div');
      ts.className = 'mb-time';
      ts.textContent = formatTime(new Date());
      wrap.appendChild(ts);
    }

    $list.appendChild(wrap);
    $list.scrollTop = $list.scrollHeight;
  }

  function appendSystem(text, isError) {
    var wrap = document.createElement('div');
    wrap.className = 'mb-row-wrap system';
    var row = document.createElement('div');
    row.className = 'mb-row system';
    var div = document.createElement('div');
    div.className = 'mb-msg system' + (isError ? ' mb-error' : '');
    div.textContent = text;
    row.appendChild(div);
    wrap.appendChild(row);
    $list.appendChild(wrap);
    $list.scrollTop = $list.scrollHeight;
  }

  // タイピング3点アニメ (アシスタント側の吹き出しとして表示)
  var $typingRow = null;
  function showTyping() {
    if ($typingRow) return;
    var wrap = document.createElement('div');
    wrap.className = 'mb-row-wrap assistant mb-typing-wrap';
    var row = document.createElement('div');
    row.className = 'mb-row assistant';
    if (AVATAR_SVG) {
      var av = document.createElement('div');
      av.className = 'mb-msg-avatar';
      av.innerHTML = AVATAR_SVG;
      row.appendChild(av);
    }
    var bubble = document.createElement('div');
    bubble.className = 'mb-msg assistant mb-typing-bubble';
    bubble.innerHTML = '<span></span><span></span><span></span>';
    row.appendChild(bubble);
    wrap.appendChild(row);
    $list.appendChild(wrap);
    $typingRow = wrap;
    $list.scrollTop = $list.scrollHeight;
  }
  function hideTyping() {
    if ($typingRow && $typingRow.parentNode) {
      $typingRow.parentNode.removeChild($typingRow);
    }
    $typingRow = null;
  }

  // ---- 質問例パネル (カテゴリタブ + チップ) ----
  // 1階層: カテゴリ別の質問群。テナント設定 (starters) が来たら status カテゴリを上書きする。
  var DEFAULT_QUICK = {
    categories: [
      { key: 'status',  label: '今どうなってる？' },
      { key: 'next',    label: '次に何する？' },
      { key: 'problem', label: 'うまくいかない…' }
    ],
    questions: {
      status: [
        '今、いちばん大事なことを1つだけ教えて',
        '今の状況は良い？悪い？かんたんに教えて',
        '良いところと気をつけたいところを1つずつ教えて',
        '前回と比べて、何が変わった？（短く）'
      ],
      next: [
        '次にやるべきことを1つだけ教えて',
        '小さな一歩を3つ教えて',
        '今いちばん優先する作業は？',
        '今週中にできる改善案は？'
      ],
      problem: [
        'うまくいっていない箇所はどこ？',
        '原因を、専門用語を使わずに教えて',
        '小さく試せる対策はある？',
        '迷っている時にやるべきことを教えて'
      ]
    }
  };
  var quickData  = JSON.parse(JSON.stringify(DEFAULT_QUICK));
  var currentCat = 'status';
  var quickOpen  = true;

  function renderCatTabs() {
    $catTabs.innerHTML = '';
    quickData.categories.forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'mb-cat-tab' + (c.key === currentCat ? ' active' : '');
      b.textContent = c.label;
      b.addEventListener('click', function () {
        if (currentCat === c.key) return;
        currentCat = c.key;
        renderCatTabs();
        renderQuickChips();
      });
      $catTabs.appendChild(b);
    });
  }
  function renderQuickChips() {
    $quickChips.innerHTML = '';
    var qs = quickData.questions[currentCat] || [];
    qs.forEach(function (q) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'mb-quick-chip';
      b.textContent = q;
      b.addEventListener('click', function () {
        $text.value = q;
        $form.requestSubmit();
      });
      $quickChips.appendChild(b);
    });
  }
  function setQuickOpen(open) {
    quickOpen = open;
    $quick.classList.toggle('mb-quick-closed', !open);
    $quickToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    var arrow = $quickToggle.querySelector('.mb-quick-arrow');
    if (arrow) arrow.textContent = open ? '▲' : '▼';
  }
  $quickToggle.addEventListener('click', function () { setQuickOpen(!quickOpen); });

  function applyStartersToQuick(starters) {
    // テナント設定の starters があれば status カテゴリを差し替え
    if (starters && starters.length) {
      quickData.questions.status = starters.slice(0, 6);
      if (currentCat === 'status') renderQuickChips();
    }
  }

  // ---- スレッド内スターター (ウェルカム直後に並ぶ 6 個程度のチップ) ----
  // テナント starters があれば優先、無ければ 3 カテゴリから抽出した汎用デフォルト。
  var DEFAULT_INLINE_STARTERS = [
    '今、いちばん大事なことを1つだけ教えて',
    '今の状況は良い？悪い？かんたんに教えて',
    '前回と比べて、何が変わった？',
    '次にやるべきことを1つだけ教えて',
    'うまくいっていない箇所はどこ？',
    '小さく試せる対策はある？'
  ];

  function appendInlineStarters(items) {
    if (!items || !items.length) return;
    var wrap = document.createElement('div');
    wrap.className = 'mb-row-wrap assistant mb-inline-starters';
    var list = document.createElement('div');
    list.className = 'mb-inline-starters-list';
    items.slice(0, 6).forEach(function (q) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'mb-inline-starter';
      b.textContent = q;
      b.addEventListener('click', function () {
        $text.value = q;
        $form.requestSubmit();
      });
      list.appendChild(b);
    });
    wrap.appendChild(list);
    $list.appendChild(wrap);
    $list.scrollTop = $list.scrollHeight;
  }
  function removeInlineStarters() {
    var els = $list.querySelectorAll('.mb-inline-starters');
    for (var i = 0; i < els.length; i++) els[i].parentNode.removeChild(els[i]);
  }

  // 初回描画
  renderCatTabs();
  renderQuickChips();

  // ---- セッション開始 ----
  var FALLBACK_WELCOME = 'こんにちは。お気軽にご質問ください。';

  function renderIntro(welcome, starters) {
    appendMessage('assistant', welcome || FALLBACK_WELCOME);
    applyStartersToQuick(starters || []);
    // ウェルカム直後にスレッド内スターターを 6 個並べる
    var inline = (starters && starters.length) ? starters : DEFAULT_INLINE_STARTERS;
    appendInlineStarters(inline);
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
    removeInlineStarters(); // 最初のスターターチップは送信したら片付ける
    appendMessage('user', msg);
    $send.disabled = true;
    showTyping();

    ensureSession()
      .then(function (sess) {
        return apiPost('/message', { session_uuid: sess.uuid, message: msg, page_url: location.href });
      })
      .then(function (r) {
        hideTyping();
        $send.disabled = false;
        appendMessage('assistant', r.reply || '...', r.suggested_actions || []);
      })
      .catch(function (err) {
        hideTyping();
        $send.disabled = false;
        var emsg = (err && err.message) || 'エラーが発生しました。しばらくしてからお試しください。';
        appendSystem(emsg, true);
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
