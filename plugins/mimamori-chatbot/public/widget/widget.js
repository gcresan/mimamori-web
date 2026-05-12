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
  var fabConfig = {
    bg: '#2563eb',
    offset_x: 20,
    offset_y: 20,
    icon_url: ''
  };

  function applyFabStyles() {
    var st = document.getElementById('mimamori-bot-style');
    if (!st) return;
    var css =
      '#' + FAB_ID + '{position:fixed;right:' + fabConfig.offset_x + 'px;bottom:' + fabConfig.offset_y + 'px;' +
      'width:56px;height:56px;border-radius:28px;' +
      'background:' + fabConfig.bg + ';color:#fff;display:flex;align-items:center;justify-content:center;' +
      'box-shadow:0 6px 20px rgba(0,0,0,.18);cursor:pointer;z-index:2147483646;border:none;font:600 24px/1 system-ui;padding:0;overflow:hidden}' +
      '#' + FAB_ID + ':hover{filter:brightness(.92)}' +
      '#' + FAB_ID + ' img{width:100%;height:100%;object-fit:contain;padding:10px;display:block}' +
      '#' + WRAP_ID + '{position:fixed;right:' + fabConfig.offset_x + 'px;bottom:' + (fabConfig.offset_y + 68) + 'px;' +
      'width:380px;max-width:calc(100vw - 24px);' +
      'height:600px;max-height:calc(100vh - 120px);border:0;border-radius:14px;overflow:hidden;' +
      'box-shadow:0 12px 40px rgba(0,0,0,.22);z-index:2147483647;background:#fff;display:none}' +
      '#' + WRAP_ID + '.open{display:block}' +
      '@media (max-width:600px){#' + WRAP_ID + '{right:0;bottom:0;width:100vw;height:100vh;max-height:100vh;border-radius:0}}';
    st.textContent = css;
  }

  function injectStyles() {
    if (document.getElementById('mimamori-bot-style')) return;
    var style = document.createElement('style');
    style.id = 'mimamori-bot-style';
    document.head.appendChild(style);
    applyFabStyles();
  }

  function applyFabContent() {
    if (!fab) return;
    fab.textContent = '';
    if (fabConfig.icon_url) {
      var img = document.createElement('img');
      img.src = fabConfig.icon_url;
      img.alt = '';
      img.onerror = function () { fab.textContent = '💬'; };
      fab.appendChild(img);
    } else {
      fab.textContent = '💬';
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

  // テナント別の見た目設定を取得して反映 (失敗してもデフォルトのまま動く)
  function fetchConfig() {
    try {
      fetch(configUrl, { credentials: 'omit' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (!j || !j.fab) return;
          if (j.fab.bg)       fabConfig.bg       = j.fab.bg;
          if (j.fab.icon_url) fabConfig.icon_url = j.fab.icon_url;
          if (typeof j.fab.offset_x === 'number') fabConfig.offset_x = j.fab.offset_x;
          if (typeof j.fab.offset_y === 'number') fabConfig.offset_y = j.fab.offset_y;
          applyFabStyles();
          applyFabContent();
        })
        .catch(function () { /* fallback to defaults */ });
    } catch (e) { /* ignore */ }
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
  function toggle() {
    if (!opened) {
      if (!iframe.src) iframe.src = embedUrl;
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

    // iframe からの postMessage を受信（resize / close 等）
    window.addEventListener('message', function (ev) {
      try {
        if (!ev.data || typeof ev.data !== 'object') return;
        if (ev.data.source !== 'mimamori-bot') return;
        if (ev.data.type === 'close') toggle();
      } catch (e) {}
    });

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
