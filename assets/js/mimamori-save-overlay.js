/*!
 * みまもりウェブ - 保存中オーバーレイ (グローバル共通)
 *
 * 役割:
 *   保存系フォーム送信中、画面全体に半透過オーバーレイ +
 *   スピナー +「保存中です」表示を出し、ユーザーに処理中であることを伝える。
 *
 * 適用対象:
 *   1) <form data-save-overlay> または class="gcrev-save-form" のフォーム
 *   2) 上記が無くても、送信ボタン (button[type=submit] / input[type=submit])
 *      のテキストが "保存"/"登録"/"更新" を含むフォーム (自動検出)
 *
 * 除外したい場合: form に data-save-overlay="off" を付ける。
 *
 * API:
 *   window.gcrevShowSaving(title?, text?) — 明示的に表示
 *   window.gcrevHideSaving()              — 明示的に非表示
 */
(function () {
  'use strict';

  // ---- CSS をその場で注入 (専用 CSS ファイル不要) ----
  var CSS =
    '.gcrev-saving-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);' +
      '-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);' +
      'display:flex;align-items:center;justify-content:center;z-index:2147483640;' +
      'opacity:0;visibility:hidden;transition:opacity .22s ease,visibility .22s ease}' +
    '.gcrev-saving-overlay.active{opacity:1;visibility:visible}' +
    '.gcrev-saving-card{background:#FAF9F6;padding:36px 48px;border-radius:16px;text-align:center;' +
      'box-shadow:0 12px 40px rgba(0,0,0,.22);min-width:260px;max-width:420px;width:90%}' +
    '.gcrev-saving-spinner{width:48px;height:48px;margin:0 auto 18px;' +
      'border:4px solid #E5E3DC;border-top-color:#568184;border-radius:50%;' +
      'animation:gcrevSavingSpin .8s linear infinite}' +
    '@keyframes gcrevSavingSpin{to{transform:rotate(360deg)}}' +
    '.gcrev-saving-title{margin:0 0 6px;font-size:17px;font-weight:700;color:#2B2B2B;line-height:1.4}' +
    '.gcrev-saving-text{margin:0;font-size:13px;color:#666660;line-height:1.6}';

  function injectStyle() {
    if (document.getElementById('gcrev-saving-style')) return;
    var s = document.createElement('style');
    s.id = 'gcrev-saving-style';
    s.appendChild(document.createTextNode(CSS));
    document.head.appendChild(s);
  }

  var overlay = null;
  function ensureOverlay() {
    if (overlay) return overlay;
    injectStyle();
    var wrap = document.createElement('div');
    wrap.className = 'gcrev-saving-overlay';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-live', 'polite');
    wrap.setAttribute('aria-busy', 'true');
    wrap.innerHTML =
      '<div class="gcrev-saving-card">' +
        '<div class="gcrev-saving-spinner" aria-hidden="true"></div>' +
        '<h3 class="gcrev-saving-title">保存中です</h3>' +
        '<p class="gcrev-saving-text">変更内容を反映しています…</p>' +
      '</div>';
    document.body.appendChild(wrap);
    overlay = wrap;
    return overlay;
  }

  function show(title, text) {
    var ov = ensureOverlay();
    if (title) ov.querySelector('.gcrev-saving-title').textContent = String(title);
    if (text)  ov.querySelector('.gcrev-saving-text').textContent  = String(text);
    // 次フレームで active にすると transition が効く (初回挿入直後の visibility:hidden 起点を確保)
    requestAnimationFrame(function () { ov.classList.add('active'); });
    document.body.style.overflow = 'hidden';
  }
  function hide() {
    if (!overlay) return;
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  }
  window.gcrevShowSaving = show;
  window.gcrevHideSaving = hide;

  // ---- フォーム submit を hook ----
  // 自動検出: 送信ボタンに "保存"/"登録"/"更新" を含むフォーム
  var AUTO_TEXT_RE = /保存|登録|更新/;

  function isAutoTarget(form) {
    if (!form || form._gcrevHooked) return false;
    // 明示 off
    if (form.matches && form.matches('[data-save-overlay="off"]')) return false;
    // 明示 on は最優先 (action / method の制約をスキップ)
    if (form.matches && form.matches('[data-save-overlay], .gcrev-save-form')) return true;
    // GET フォームは対象外 (検索/フィルタ系の誤検出を避ける)
    var method = (form.method || 'get').toLowerCase();
    if (method !== 'post') return false;
    // AJAX エンドポイント (admin-ajax / wp-json / mimamori-bot) は除外
    var action = form.getAttribute('action') || form.action || '';
    if (/\/admin-ajax\.php/.test(action))       return false;
    if (/\/wp-json\//.test(action))             return false;
    if (/\/mimamori-bot(-admin)?\//.test(action)) return false;
    // 送信ボタンのテキストで判定 (保存 / 登録 / 更新)
    var btn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (!btn) return false;
    var label = btn.tagName === 'INPUT' ? (btn.value || '') : (btn.textContent || '');
    return AUTO_TEXT_RE.test(label);
  }

  function hookForm(form) {
    if (!form || form._gcrevHooked) return;
    form._gcrevHooked = true;
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) return;
      // input[type=submit].formaction や e.preventDefault されているケースは無視
      show();
    });
  }

  function scanAndHook() {
    var forms = document.querySelectorAll('form');
    for (var i = 0; i < forms.length; i++) {
      if (isAutoTarget(forms[i])) hookForm(forms[i]);
    }
  }

  // bfcache から戻った時 (ブラウザバック) はオーバーレイを必ず消す
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) hide();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scanAndHook);
  } else {
    scanAndHook();
  }

  // 動的に DOM へ後から差し込まれたフォームにも追従 (軽量 MutationObserver)
  if (typeof MutationObserver === 'function') {
    var mo = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var added = mutations[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var n = added[j];
          if (n.nodeType !== 1) continue;
          if (n.tagName === 'FORM') {
            if (isAutoTarget(n)) hookForm(n);
          } else if (n.querySelectorAll) {
            var inner = n.querySelectorAll('form');
            for (var k = 0; k < inner.length; k++) {
              if (isAutoTarget(inner[k])) hookForm(inner[k]);
            }
          }
        }
      }
    });
    mo.observe(document.documentElement, { childList: true, subtree: true });
  }
})();
