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
    icon_url: '',
    rounded: true,   // 角丸 (= 円形)
    shadow:  true,   // ドロップシャドウ
    size:    56,     // 大画面 (>= 1440px) サイズ
    size_md: 56,     // 中画面以下 (< 1440px) サイズ — 未設定時は size と同じ
    width_pct_sp: 0  // スマホ版バナー最大横幅 (% of vw) — 0 = 自動 (制限なし)
  };
  // 効果音のオン/オフ (widget-config 取得まではデフォルト ON)
  var soundOpenOn = true;
  // 初回は非表示 → config 取得後 (または失敗時) に表示。設定色での "青→緑" フラッシュ抑止。
  var fabReady = false;

  function applyFabStyles() {
    var st = document.getElementById('mimamori-bot-style');
    if (!st) return;
    var x   = fabConfig.offset_x,    y   = fabConfig.offset_y;
    var xsp = fabConfig.offset_x_sp, ysp = fabConfig.offset_y_sp;
    var sz   = Math.max(32, Math.min(120, fabConfig.size    || 56));
    var szmd = Math.max(32, Math.min(120, fabConfig.size_md || sz));
    // 角丸: ON = 完全な円 (50%) / OFF = 鋭角 (0)
    var radius     = fabConfig.rounded ? '50%' : '8px';
    // シャドウ: ON = 立体感シャドウ / OFF = なし
    var shadow     = fabConfig.shadow  ? '0 6px 20px rgba(0,0,0,.18)' : 'none';
    var hoverShadow= fabConfig.shadow  ? '0 8px 24px rgba(0,0,0,.22)' : 'none';
    var opacity = fabReady ? '1' : '0';
    var pointer = fabReady ? 'auto' : 'none';
    // 開閉アニメ用イージング (Material 風 ease-out)
    var EASE = 'cubic-bezier(.22,1,.36,1)';
    // 画像モード時の角丸: rounded ON で控えめに 12px、OFF で角張る
    var imgRadius = fabConfig.rounded ? '12px' : '0';
    var css =
      // PC (デフォルト = 大画面 ≥1440px)
      // 画像なしモード — 円形 FAB
      '#' + FAB_ID + '{position:fixed;right:' + x + 'px;bottom:' + y + 'px;' +
      'width:' + sz + 'px;height:' + sz + 'px;border-radius:' + radius + ';' +
      'background:' + fabConfig.bg + ';color:#fff;display:flex;align-items:center;justify-content:center;' +
      'box-shadow:' + shadow + ';cursor:pointer;z-index:2147483646;border:none;font:600 24px/1 system-ui;padding:0;overflow:hidden;' +
      'opacity:' + opacity + ';pointer-events:' + pointer + ';' +
      // 初回 reveal 時の青→設定色フラッシュ防止のため background/width/height/border-radius は
      // transition から除外 (= 即時切替)。fade-in / hover アニメ用の opacity / transform / filter のみ残す
      'transition:opacity .18s ease-out, transform .18s ' + EASE + ', filter .15s ease-out}' +
      '#' + FAB_ID + ':hover{filter:brightness(.92);transform:translateY(-2px);box-shadow:' + hoverShadow + '}' +
      '#' + FAB_ID + ':active{transform:translateY(0) scale(.96)}' +
      '#' + FAB_ID + ' img{width:100%;height:100%;object-fit:contain;padding:10px;display:block}' +
      // 画像あり (.has-image) — 円形・背景・パディングを全て撤回し、画像そのままを表示
      // height/width は auto + max-* に変更: 画像の比率に合わせて FAB ボックスもピッタリ縮み、
      // 上下に余白が出ない (object-fit:contain で生まれていた縦の隙間を解消)
      '#' + FAB_ID + '.has-image{' +
        'background:transparent;padding:0;width:auto;height:auto;line-height:0;' +
        'max-width:min(380px,calc(100vw - 24px));max-height:' + sz + 'px;' +
        'border-radius:' + imgRadius + ';overflow:visible;' +
      '}' +
      '#' + FAB_ID + '.has-image img{' +
        'display:block;width:auto;height:auto;max-width:100%;max-height:' + sz + 'px;padding:0;margin:0;' +
        'border-radius:inherit;' +
      '}' +
      // ウィンドウ
      '#' + WRAP_ID + '{position:fixed;right:' + x + 'px;bottom:' + (y + sz + 12) + 'px;' +
      'width:380px;max-width:calc(100vw - 24px);' +
      'height:600px;max-height:calc(100vh - 120px);border:0;border-radius:14px;overflow:hidden;' +
      'box-shadow:0 12px 40px rgba(0,0,0,.22);z-index:2147483647;background:#fff;' +
      'opacity:0;transform:translateY(16px) scale(.96);transform-origin:bottom right;' +
      'pointer-events:none;visibility:hidden;' +
      'transition:opacity .22s ease-out, transform .26s ' + EASE + ', visibility 0s linear .26s}' +
      '#' + WRAP_ID + '.open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto;visibility:visible;' +
      'transition:opacity .22s ease-out, transform .26s ' + EASE + ', visibility 0s}' +
      // 全画面化 (最大化ボタン)
      '#' + WRAP_ID + '.maxd{right:0 !important;left:0;top:0;bottom:0;width:100vw;height:100vh;' +
      'max-width:100vw;max-height:100vh;border-radius:0;' +
      'transform:translateY(0) scale(1) !important;transform-origin:center center}' +
      // 中画面以下 (< 1440px): FAB サイズを size_md に変更
      '@media (max-width:1439px){' +
        '#' + FAB_ID + '{width:' + szmd + 'px;height:' + szmd + 'px}' +
        '#' + FAB_ID + '.has-image{width:auto;height:auto;max-height:' + szmd + 'px}' +
        '#' + FAB_ID + '.has-image img{max-height:' + szmd + 'px}' +
        '#' + WRAP_ID + '{bottom:' + (y + szmd + 12) + 'px}' +
      '}' +
      // SP (< 600px): FAB 位置を SP オフセットに、ウィンドウは下から全画面スライド
      // 画像ありの時のみ、width_pct_sp が指定されていればバナー最大横幅を vw% に上書き
      // ウィンドウ: top/right/bottom/left の 4 辺で囲って viewport にぴったり = iOS Safari の
      //   100vh が address bar 含む大きい値になる問題 (ヘッダーが画面外にはみ出す) を回避
      '@media (max-width:600px){' +
        '#' + FAB_ID + '{right:' + xsp + 'px;bottom:' + ysp + 'px}' +
        ( fabConfig.width_pct_sp > 0
          ? '#' + FAB_ID + '.has-image{max-width:' + fabConfig.width_pct_sp + 'vw}'
          : '' ) +
        '#' + WRAP_ID + '{top:0;right:0;bottom:0;left:0;width:auto;height:auto;' +
          'max-width:none;max-height:none;border-radius:0;' +
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
    fab.style.color = '#ffffff';
    fab.textContent = '';
    if (fabConfig.icon_url) {
      // 画像モード — 円形コンテナを撤回してバナー画像そのままを見せる (has-image クラス)
      fab.classList.add('has-image');
      var img = document.createElement('img');
      img.src = fabConfig.icon_url;
      img.alt = '';
      img.onerror = function () {
        fab.classList.remove('has-image');
        fab.innerHTML = DEFAULT_ICON_SVG;
      };
      fab.appendChild(img);
    } else {
      // 画像なし — デフォルトの円形 FAB
      fab.classList.remove('has-image');
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
  //
  // 高速化: widget-config を localStorage にキャッシュし、再訪問は即時で正しい色の FAB を表示。
  //         キャッシュがある場合は表示後に裏でフェッチして差分反映 (stale-while-revalidate)。
  var CFG_CACHE_KEY = 'mb_cfg_' + tenant;

  function applyConfigPayload(j) {
    if (!j) return;
    if (j.fab) {
      if (j.fab.bg)       fabConfig.bg       = j.fab.bg;
      if (j.fab.icon_url) fabConfig.icon_url = j.fab.icon_url;
      if (typeof j.fab.offset_x === 'number') fabConfig.offset_x = j.fab.offset_x;
      if (typeof j.fab.offset_y === 'number') fabConfig.offset_y = j.fab.offset_y;
      fabConfig.offset_x_sp = (typeof j.fab.offset_x_sp === 'number') ? j.fab.offset_x_sp : fabConfig.offset_x;
      fabConfig.offset_y_sp = (typeof j.fab.offset_y_sp === 'number') ? j.fab.offset_y_sp : fabConfig.offset_y;
      if (typeof j.fab.rounded === 'boolean') fabConfig.rounded = j.fab.rounded;
      if (typeof j.fab.shadow  === 'boolean') fabConfig.shadow  = j.fab.shadow;
      if (typeof j.fab.size    === 'number' && j.fab.size    > 0) fabConfig.size    = j.fab.size;
      if (typeof j.fab.size_md === 'number' && j.fab.size_md > 0) fabConfig.size_md = j.fab.size_md;
      else fabConfig.size_md = fabConfig.size;
      if (typeof j.fab.width_pct_sp === 'number' && j.fab.width_pct_sp > 0) {
        fabConfig.width_pct_sp = j.fab.width_pct_sp;
      }
    }
    if (j.sound && typeof j.sound.open === 'boolean') {
      soundOpenOn = j.sound.open;
    }
  }

  function fetchConfig() {
    function reveal() {
      if (fabReady) return; // 二重 reveal 防止
      fabReady = true;
      // CSS を確定 → FAB を DOM に挿入 (青フラッシュ完全防止)
      applyFabStyles();
      createAndAppendFab();
    }
    function updateLive() {
      // すでに表示済みの場合、最新値で再描画 (transition から色変動は除外済みなので一瞬の点滅なし)
      if (fabReady) {
        applyFabStyles();
        applyFabContent();
      }
    }

    // 1) キャッシュがあれば先に適用して即表示 (フラッシュなし)
    var hasCache = false;
    try {
      var raw = localStorage.getItem(CFG_CACHE_KEY);
      if (raw) {
        var cached = JSON.parse(raw);
        if (cached && cached.fab) {
          applyConfigPayload(cached);
          hasCache = true;
          reveal();
        }
      }
    } catch (e) { /* ignore */ }

    // 2) 安全弁: キャッシュも無くフェッチも遅い場合、800ms でデフォルト表示
    setTimeout(function () { if (!fabReady) reveal(); }, hasCache ? 0 : 800);

    // 3) 常に最新を裏フェッチして反映 + キャッシュ更新
    try {
      fetch(configUrl, { credentials: 'omit' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          if (j && j.fab) {
            applyConfigPayload(j);
            try { localStorage.setItem(CFG_CACHE_KEY, JSON.stringify(j)); } catch (e) {}
            if (fabReady) updateLive();
            else reveal();
          }
        })
        .catch(function () { /* キャッシュ or デフォルトのまま */ })
        .then(function () { if (!fabReady) reveal(); });
    } catch (e) { if (!fabReady) reveal(); }
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
  var preloadOnce = null; // boot() でセットされる (FAB hover preload と共有)
  function preloadIframe() {
    if (iframe && !iframe.src) iframe.src = embedUrl;
  }

  // FAB を DOM に挿入 — CSS が "正しい色 + opacity 制御" になっている状態で
  // 初めて DOM に置くことで、デフォルト青色での一瞬の表示を完全に防ぐ。
  function createAndAppendFab() {
    if (fab) return; // 二重挿入防止
    fab = createFab();
    // 念のため inline opacity:0 で挿入 → 次フレームで CSS の opacity:1 へ
    // (transition により .18s でなめらかにフェードイン)
    fab.style.opacity = '0';
    document.body.appendChild(fab);
    applyFabContent();
    if (preloadOnce) {
      fab.addEventListener('mouseenter', preloadOnce, { passive: true });
      fab.addEventListener('touchstart', preloadOnce, { passive: true });
      fab.addEventListener('focus',      preloadOnce);
    }
    // フェードイン: 反映の reflow を強制し、次フレームで inline opacity を解除
    void fab.offsetWidth;
    requestAnimationFrame(function () {
      if (fab) fab.style.opacity = '';
    });
  }

  // ---- 開く時の効果音 (Web Audio API でその場合成 — 外部ファイル不要) ----
  // C5 → E5 の短い 2音 "ポン" を fade-out 付きで再生。
  // 初回はユーザー操作 (クリック) 内で resume 必須 — toggle 内で呼ぶので OK。
  var audioCtx = null;
  function playOpenSound() {
    if (!soundOpenOn) return;
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
    iframe = createIframeWrap();
    document.body.appendChild(iframe);
    // FAB はここでは挿入しない — fetchConfig.reveal() のタイミングで
    // 正しい色の CSS が用意できた状態で初めて DOM に追加する (青フラッシュ防止)。
    fetchConfig();

    // iframe からの postMessage を受信
    //   close    -> 閉じる
    //   minimize -> 同上 (互換のため受け付ける)
    //   maximize -> iframe を全画面化 / 復元 (トグル)
    var maximized = false;
    window.addEventListener('message', function (ev) {
      try {
        if (!ev.data || typeof ev.data !== 'object') return;
        if (ev.data.source !== 'mimamori-bot') return;
        if (ev.data.type === 'close' || ev.data.type === 'minimize') {
          // 全画面状態でも閉じる時はリセット
          if (maximized) { maximized = false; iframe.classList.remove('maxd'); }
          toggle();
        } else if (ev.data.type === 'maximize') {
          maximized = !maximized;
          iframe.classList.toggle('maxd', maximized);
        }
      } catch (e) {}
    });

    // 外側 (iframe / FAB 以外) クリックで閉じる
    document.addEventListener('click', function (ev) {
      if (!opened) return;
      var t = ev.target;
      if (!t) return;
      if (t === iframe || iframe.contains(t)) return;
      if (fab && (t === fab || fab.contains(t))) return;
      toggle();
    }, false);
    // Esc でも閉じる
    document.addEventListener('keydown', function (ev) {
      if (opened && (ev.key === 'Escape' || ev.keyCode === 27)) toggle();
    }, false);

    // iframe の遅延プリロード — 初回クリック時の待ち時間を消す
    var preloaded = false;
    preloadOnce = function () { if (!preloaded) { preloaded = true; preloadIframe(); } };
    if ('requestIdleCallback' in window) {
      requestIdleCallback(preloadOnce, { timeout: 2500 });
    } else {
      setTimeout(preloadOnce, 1800);
    }
    // FAB の hover/touchstart/focus でもプリロード — FAB 作成時 (createAndAppendFab)
    // で preloadOnce を参照してリスナーを取り付ける

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
