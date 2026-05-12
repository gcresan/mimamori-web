/*!
 * みまもりチャットボット - Widget Loader v0.1.0
 * 親ページに FAB を注入し、クリックで iframe を表示する。
 *
 * 使い方:
 *   <script src="https://mimamori-web.jp/wp-content/plugins/mimamori-chatbot/public/widget/widget.js"
 *           data-tenant="ekc-001"
 *           data-key="pk_live_xxxx" async></script>
 */
(function () {
  'use strict';

  // ローダ自身の <script> タグから設定を取り出す
  var currentScript = document.currentScript || (function () {
    var scripts = document.getElementsByTagName('script');
    return scripts[scripts.length - 1];
  })();

  var tenant = currentScript.dataset.tenant || '';
  var key    = currentScript.dataset.key    || '';
  if (!tenant || !key) {
    console.error('[mimamori-bot] data-tenant and data-key are required');
    return;
  }

  // 二重起動ガード
  if (window.__mimamoriBotLoaded) return;
  window.__mimamoriBotLoaded = true;

  var base = (function () {
    // widget.js を配信した origin を API ベースとして使う
    var u = new URL(currentScript.src);
    return u.protocol + '//' + u.host;
  })();
  var embedUrl = base + '/wp-json/mimamori-bot/v1/embed?tenant=' + encodeURIComponent(tenant)
                       + '#key=' + encodeURIComponent(key);
  // key を含めることで maybe_handle_cors() が Origin を解決してヘッダ付与する
  var configUrl = base + '/wp-json/mimamori-bot/v1/widget-config?tenant=' + encodeURIComponent(tenant)
                       + '&key=' + encodeURIComponent(key);

  // ---- DOM 構築 ----
  var FAB_ID  = 'mimamori-bot-fab';
  var WRAP_ID = 'mimamori-bot-wrap';

  // デフォルト見た目 (config 取得前に FAB を即座に出すため)
  // SP 値が未設定なら PC 値にフォールバック
  var fabConfig = {
    bg: '#2563eb',
    offset_x: 20,
    offset_y: 20,
    offset_x_sp: 20,
    offset_y_sp: 20,
    icon_url: ''
  };
  // 初回は非表示 → config 取得後 (または失敗時) に表示。設定色での "青→緑" フラッシュ抑止。
  var fabReady = false;

  function applyFabStyles() {
    var st = document.getElementById('mimamori-bot-style');
    if (!st) return;
    var x   = fabConfig.offset_x,    y   = fabConfig.offset_y;
    var xsp = fabConfig.offset_x_sp, ysp = fabConfig.offset_y_sp;
    var opacity = fabReady ? '1' : '0';
    var pointer = fabReady ? 'auto' : 'none';
    // 開閉アニメ用イージング (Material 風 ease-out)
    var EASE = 'cubic-bezier(.22,1,.36,1)';
    var css =
      // PC (デフォルト)
      '#' + FAB_ID + '{position:fixed;right:' + x + 'px;bottom:' + y + 'px;' +
      'width:56px;height:56px;border-radius:28px;' +
      'background:' + fabConfig.bg + ';color:#fff;display:flex;align-items:center;justify-content:center;' +
      'box-shadow:0 6px 20px rgba(0,0,0,.18);cursor:pointer;z-index:2147483646;border:none;font:600 24px/1 system-ui;padding:0;overflow:hidden;' +
      'opacity:' + opacity + ';pointer-events:' + pointer + ';' +
      'transition:opacity .18s ease-out, transform .18s ' + EASE + ', filter .15s ease-out, background-color .2s ease-out}' +
      '#' + FAB_ID + ':hover{filter:brightness(.92);transform:translateY(-2px)}' +
      '#' + FAB_ID + ':active{transform:translateY(0) scale(.96)}' +
      '#' + FAB_ID + ' img{width:100%;height:100%;object-fit:contain;padding:10px;display:block}' +
      // ウィンドウ: display:none ではなく opacity+transform でフェード&浮上アニメ
      '#' + WRAP_ID + '{position:fixed;right:' + x + 'px;bottom:' + (y + 68) + 'px;' +
      'width:380px;max-width:calc(100vw - 24px);' +
      'height:600px;max-height:calc(100vh - 120px);border:0;border-radius:14px;overflow:hidden;' +
      'box-shadow:0 12px 40px rgba(0,0,0,.22);z-index:2147483647;background:#fff;' +
      'opacity:0;transform:translateY(16px) scale(.96);transform-origin:bottom right;' +
      'pointer-events:none;visibility:hidden;' +
      'transition:opacity .22s ease-out, transform .26s ' + EASE + ', visibility 0s linear .26s}' +
      '#' + WRAP_ID + '.open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto;visibility:visible;' +
      'transition:opacity .22s ease-out, transform .26s ' + EASE + ', visibility 0s}' +
      // SP 上書き — FAB 位置は SP 用、ウィンドウは下から全画面スライド
      '@media (max-width:600px){' +
        '#' + FAB_ID + '{right:' + xsp + 'px;bottom:' + ysp + 'px}' +
        '#' + WRAP_ID + '{right:0;bottom:0;width:100vw;height:100vh;max-height:100vh;border-radius:0;' +
          'transform:translateY(100%);transform-origin:center bottom}' +
        '#' + WRAP_ID + '.open{transform:translateY(0)}' +
      '}';
    st.textContent = css;
  }

  function injectStyles() {
    if (document.getElementById('mimamori-bot-style')) return;
    var style = document.createElement('style');
    style.id = 'mimamori-bot-style';
    document.head.appendChild(style);
    applyFabStyles();
  }

  // デフォルトアイコン: 白の吹き出し SVG (絵文字 💬 だと OS 依存で青系に着色されるため)
  var DEFAULT_ICON_SVG =
    '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false" ' +
    'style="display:block;margin:auto"' +
    '>' +
    '<path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8.83l-4.54 3.78A.6.6 0 0 1 3.3 21.3L3.3 18H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" opacity=".95"/>' +
    '<circle cx="8.5"  cy="11" r="1.3" fill="' + 'rgba(0,0,0,.25)"/>' +
    '<circle cx="12"   cy="11" r="1.3" fill="rgba(0,0,0,.25)"/>' +
    '<circle cx="15.5" cy="11" r="1.3" fill="rgba(0,0,0,.25)"/>' +
    '</svg>';

  function applyFabContent() {
    if (!fab) return;
    // 文字色 = アイコン色 (currentColor で SVG にも適用される)
    fab.style.color = '#ffffff';
    fab.textContent = '';
    if (fabConfig.icon_url) {
      var img = document.createElement('img');
      img.src = fabConfig.icon_url;
      img.alt = '';
      img.onerror = function () { fab.innerHTML = DEFAULT_ICON_SVG; };
      fab.appendChild(img);
    } else {
      fab.innerHTML = DEFAULT_ICON_SVG;
    }
  }

  function createFab() {
    var btn = document.createElement('button');
    btn.id = FAB_ID;
    btn.type = 'button';
    btn.setAttribute('aria-label', 'チャットを開く');
    btn.addEventListener('click', toggle);
    return btn;
  }

  // テナント別の見た目設定を取得して反映 (失敗してもデフォルトのまま動く)。
  // 反映後 (成功/失敗どちらでも) FAB を表示する — 青→正色のフラッシュを避けるため初回は opacity:0。
  function fetchConfig() {
    function reveal() {
      fabReady = true;
      applyFabStyles();
      applyFabContent();
    }
    var fetched = false;
    // 安全弁: ネットワークが極端に遅くても 1.5 秒で必ず FAB を出す
    setTimeout(function () { if (!fetched) reveal(); }, 1500);
    try {
      fetch(configUrl, { credentials: 'omit' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (j && j.fab) {
            if (j.fab.bg)       fabConfig.bg       = j.fab.bg;
            if (j.fab.icon_url) fabConfig.icon_url = j.fab.icon_url;
            if (typeof j.fab.offset_x === 'number') fabConfig.offset_x = j.fab.offset_x;
            if (typeof j.fab.offset_y === 'number') fabConfig.offset_y = j.fab.offset_y;
            fabConfig.offset_x_sp = (typeof j.fab.offset_x_sp === 'number') ? j.fab.offset_x_sp : fabConfig.offset_x;
            fabConfig.offset_y_sp = (typeof j.fab.offset_y_sp === 'number') ? j.fab.offset_y_sp : fabConfig.offset_y;
          }
        })
        .catch(function () { /* fallback to defaults */ })
        .then(function () { fetched = true; reveal(); });
    } catch (e) { fetched = true; reveal(); }
  }

  function createIframeWrap() {
    var wrap = document.createElement('iframe');
    wrap.id = WRAP_ID;
    wrap.title = 'みまもりチャットボット';
    wrap.allow = '';
    wrap.referrerPolicy = 'no-referrer';
    // src は初回オープン時に遅延ロード（LCP保護）
    return wrap;
  }

  var fab, iframe, opened = false;
  function preloadIframe() {
    if (iframe && !iframe.src) iframe.src = embedUrl;
  }

  // ---- 開く時の効果音 (Web Audio API でその場合成 — 外部ファイル不要) ----
  // C5 → E5 の短い 2音 "ポン" を fade-out 付きで再生。
  // 初回はユーザー操作 (クリック) 内で resume 必須 — toggle 内で呼ぶので OK。
  var audioCtx = null;
  function playOpenSound() {
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return;
      if (!audioCtx) audioCtx = new AC();
      if (audioCtx.state === 'suspended') audioCtx.resume();
      var now = audioCtx.currentTime;
      // sine 2音重ね — トータル ~180ms
      [ { f: 523.25, t: 0.00 },   // C5
        { f: 659.25, t: 0.07 } ]  // E5
      .forEach(function (n) {
        var osc  = audioCtx.createOscillator();
        var gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.value = n.f;
        var start = now + n.t;
        // 12ms で立ち上げ → 150ms で減衰
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.18, start + 0.012);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.18);
        osc.connect(gain).connect(audioCtx.destination);
        osc.start(start);
        osc.stop(start + 0.2);
      });
    } catch (e) { /* 鳴らなくても致命的ではない */ }
  }

  function toggle() {
    if (!opened) {
      preloadIframe();
      playOpenSound();
      iframe.classList.add('open');
      opened = true;
      try { localStorage.setItem('mimamori_bot_open', '1'); } catch (e) {}
    } else {
      iframe.classList.remove('open');
      opened = false;
      try { localStorage.setItem('mimamori_bot_open', '0'); } catch (e) {}
    }
  }

  function boot() {
    injectStyles();
    fab = createFab();
    iframe = createIframeWrap();
    document.body.appendChild(iframe);
    document.body.appendChild(fab);
    applyFabContent();
    fetchConfig();

    // iframe からの postMessage を受信（resize / close / minimize 等）
    // minimize は close と同等動作: iframe を畳む。state は localStorage に残るので再オープン時に復元
    window.addEventListener('message', function (ev) {
      try {
        if (!ev.data || typeof ev.data !== 'object') return;
        if (ev.data.source !== 'mimamori-bot') return;
        if (ev.data.type === 'close' || ev.data.type === 'minimize') toggle();
      } catch (e) {}
    });

    // 外側 (iframe / FAB 以外) クリックで閉じる
    // - iframe 内クリックはクロスorigin 境界でブラウザ側がイベントを止めるので影響なし
    // - SP では iframe が全画面なので外側クリックは発生しない (= 影響なし)
    // - mousedown 段階で判定すると FAB の click が走る前に閉じてしまうので click 段階
    document.addEventListener('click', function (ev) {
      if (!opened) return;
      var t = ev.target;
      if (!t) return;
      if (t === iframe || iframe.contains(t)) return;
      if (t === fab    || fab.contains(t))    return;
      toggle();
    }, false);
    // Esc でも閉じる
    document.addEventListener('keydown', function (ev) {
      if (opened && (ev.key === 'Escape' || ev.keyCode === 27)) toggle();
    }, false);

    // iframe の遅延プリロード — 初回クリック時の待ち時間を消す
    // 1) ホスト側ページが落ち着いたら (idle) ロード開始
    // 2) FAB に hover or touchstart した時点でも (まだなら) ロード
    var preloaded = false;
    function preloadOnce() { if (!preloaded) { preloaded = true; preloadIframe(); } }
    if ('requestIdleCallback' in window) {
      requestIdleCallback(preloadOnce, { timeout: 2500 });
    } else {
      setTimeout(preloadOnce, 1800);
    }
    fab.addEventListener('mouseenter',  preloadOnce, { passive: true });
    fab.addEventListener('touchstart',  preloadOnce, { passive: true });
    fab.addEventListener('focus',       preloadOnce);

    // 自動オープン復元（誤起動防止のため明示クリック後のみ復元）
    try {
      if (localStorage.getItem('mimamori_bot_open') === '1') {
        // ページ遷移直後の自動展開は一旦無効（クリックを促す）
      }
    } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
