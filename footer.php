    </main>
</div><!-- .app-container -->

<?php if ( is_user_logged_in() && ( ! function_exists( 'mimamori_can' ) || mimamori_can( 'ai_chat' ) ) ) : ?>
    <?php get_template_part( 'template-parts/mimamori-ai-chat' ); ?>
<?php endif; ?>

<!-- ===== 共通: 保存中ローディング・オーバーレイ ===== -->
<div id="mw-save-overlay" class="mw-save-overlay" aria-hidden="true" role="status" aria-live="polite">
    <div class="mw-save-overlay-card">
        <div class="mw-save-overlay-spinner" aria-hidden="true"></div>
        <div class="mw-save-overlay-text">保存中...</div>
    </div>
</div>
<style>
.mw-save-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
}
.mw-save-overlay.is-active { display: flex; }
.mw-save-overlay-card {
    background: #fff;
    padding: 28px 36px;
    border-radius: 14px;
    box-shadow: 0 12px 36px rgba(0,0,0,0.22);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    min-width: 220px;
}
.mw-save-overlay-spinner {
    width: 42px;
    height: 42px;
    border: 3px solid #e2e8f0;
    border-top-color: #568184;
    border-radius: 50%;
    animation: mw-save-spin 0.8s linear infinite;
}
@keyframes mw-save-spin { to { transform: rotate(360deg); } }
.mw-save-overlay-text {
    font-size: 14px;
    font-weight: 600;
    color: #334155;
    letter-spacing: 0.02em;
}
</style>
<script>
// =============================================
// 共通: 保存中ローディング・オーバーレイ
//   - window.MW.saveOverlay.show(msg) / .hide()   ... 手動制御
//   - data-mw-save 属性 or 主要な「保存」系セレクタのクリックで自動 show
//   - 自動 show 直後の fetch がすべて完了したら自動 hide
//   - フェイルセーフ: 30秒で強制 hide
// =============================================
(function () {
    var SELECTOR = [
        '[data-mw-save]',
        '#userInfoSave',       // アカウント情報
        '#btn-cs-save',        // クライアント設定
        '#btn-save-cv-routes', // ゴール関連設定
        '#btn-save'            // 月次レポート設定 等
    ].join(',');

    var overlay = null;
    var textEl  = null;
    var active  = false;
    var pending = 0;
    var failTimer = null;

    function ensure() {
        if (!overlay) {
            overlay = document.getElementById('mw-save-overlay');
            if (overlay) textEl = overlay.querySelector('.mw-save-overlay-text');
        }
        return !!overlay;
    }

    function show(msg) {
        if (!ensure()) return;
        textEl.textContent = (typeof msg === 'string' && msg) ? msg : '保存中...';
        overlay.classList.add('is-active');
        overlay.setAttribute('aria-hidden', 'false');
        active = true;
        if (failTimer) clearTimeout(failTimer);
        // 30秒経過で強制非表示（ネットワーク異常時の出しっ放しを防ぐ）
        failTimer = setTimeout(function () { hide(); }, 30000);
    }

    function hide() {
        if (!ensure()) return;
        overlay.classList.remove('is-active');
        overlay.setAttribute('aria-hidden', 'true');
        active  = false;
        pending = 0;
        if (failTimer) { clearTimeout(failTimer); failTimer = null; }
    }

    window.MW = window.MW || {};
    window.MW.saveOverlay = { show: show, hide: hide };

    // 自動フック: 保存系ボタンのクリックで show
    document.addEventListener('click', function (e) {
        var btn = e.target.closest(SELECTOR);
        if (!btn || btn.disabled) return;
        show();
    }, true);

    // fetch をラップ: show 中に発生したリクエストの完了で自動 hide
    if (typeof window.fetch === 'function') {
        var orig = window.fetch.bind(window);
        window.fetch = function () {
            if (!active) return orig.apply(this, arguments);
            pending++;
            return orig.apply(this, arguments).then(function (res) {
                pending--;
                if (pending <= 0 && active) setTimeout(hide, 250);
                return res;
            }).catch(function (err) {
                pending--;
                if (pending <= 0 && active) setTimeout(hide, 250);
                throw err;
            });
        };
    }
})();
</script>

<script>
// =============================================
// クライアントサイドキャッシュ（localStorage 永続版）
// ページ遷移・タブ閉じ・ブラウザ再起動でも保持され、
// TTL（2時間）で自動失効する。
// =============================================
window.gcrevCache = {
    TTL: 2 * 60 * 60 * 1000, // 2時間（サーバー側Transient 24hより十分短い）
    // データ所有者ID（view-as 切替中はそのクライアントID）でプレフィックスを分ける。
    // これにより admin が複数クライアントをビュー切替しても、それぞれ別キャッシュとして
    // 扱われ、前のクライアントのデータが返る事故を防ぐ。
    PREFIX: 'gcrev_u<?php
        if ( is_user_logged_in() ) {
            echo (int) ( function_exists( 'mimamori_get_view_user_id' )
                ? mimamori_get_view_user_id()
                : get_current_user_id() );
        } else {
            echo 0;
        }
    ?>_',
    MAX_AGE: 24 * 60 * 60 * 1000, // 24時間（古いエントリの自動掃除用）

    /** キャッシュからデータを取得（期限切れならnull） */
    get: function(key) {
        try {
            var raw = localStorage.getItem(this.PREFIX + key);
            if (!raw) return null;
            var item = JSON.parse(raw);
            var age = Date.now() - item.ts;
            if (age < this.TTL) {
                return item.data;
            }
            // 期限切れ — 削除
            localStorage.removeItem(this.PREFIX + key);
        } catch(e) {}
        return null;
    },

    /** データをキャッシュに保存 */
    set: function(key, data) {
        try {
            localStorage.setItem(this.PREFIX + key, JSON.stringify({
                ts: Date.now(),
                data: data
            }));
        } catch(e) {
            // 容量超過時は古いキャッシュをクリアして再試行
            this._evict();
            try {
                localStorage.setItem(this.PREFIX + key, JSON.stringify({ ts: Date.now(), data: data }));
            } catch(e2) {}
        }
    },

    /** 特定プレフィックスのキャッシュを削除 */
    clear: function(prefix) {
        try {
            var fullPrefix = this.PREFIX + (prefix || '');
            var keys = [];
            for (var i = 0; i < localStorage.length; i++) {
                var k = localStorage.key(i);
                if (k && k.indexOf(fullPrefix) === 0) keys.push(k);
            }
            keys.forEach(function(k) { localStorage.removeItem(k); });
        } catch(e) {}
    },

    /** 24時間以上古いエントリを掃除 */
    _evict: function() {
        try {
            var now = Date.now();
            var prefix = this.PREFIX;
            var maxAge = this.MAX_AGE;
            var keys = [];
            for (var i = 0; i < localStorage.length; i++) {
                var k = localStorage.key(i);
                if (k && k.indexOf(prefix) === 0) keys.push(k);
            }
            keys.forEach(function(k) {
                try {
                    var item = JSON.parse(localStorage.getItem(k));
                    if (!item || !item.ts || (now - item.ts > maxAge)) {
                        localStorage.removeItem(k);
                    }
                } catch(e) { localStorage.removeItem(k); }
            });
        } catch(e) {}
    }
};

// 旧プレフィックス(user_id無し)のキャッシュをクリーンアップ
(function() {
    try {
        var newPrefix = window.gcrevCache.PREFIX; // 'gcrev_u{id}_'
        var keys = [];
        for (var i = 0; i < localStorage.length; i++) {
            var k = localStorage.key(i);
            // gcrev_ で始まるが新プレフィックスでないもの = 旧キャッシュ
            if (k && k.indexOf('gcrev_') === 0 && k.indexOf(newPrefix) !== 0) {
                keys.push(k);
            }
        }
        keys.forEach(function(k) { localStorage.removeItem(k); });
    } catch(e) {}
})();

// =============================================
// サーバー側 cache seed の流し込み
// 分析ページが描画時に window.__GCREV_SEED へ注入した「cron で温め済みデータ」を
// gcrevCache へ同期で書き込む。これにより各ページの初回 loadData() が REST 往復せず
// 即座に localStorage 命中で描画でき、初回表示の遅さを解消する。
// （gcrevCache 定義の直後・DOMContentLoaded 発火前にこの場で実行されるのが肝）
// =============================================
(function () {
    try {
        var seed = window.__GCREV_SEED;
        if (!seed || typeof window.gcrevCache === 'undefined') { return; }
        Object.keys(seed).forEach(function (k) {
            try { window.gcrevCache.set(k, seed[k]); } catch (e) {}
        });
    } catch (e) {}
})();

// =============================================
// 共通JavaScript
// =============================================

document.addEventListener('DOMContentLoaded', function() {
    // モバイルメニュートグル
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });
    }

    // サイドバー アコーディオンメニュー（トグル式：クリックで開閉）
    // DOM 上のすべての .nav-item-collapsible を自動検出して登録する（ID 列挙不要）
    (function() {
        var items = [];
        document.querySelectorAll('.nav-item-collapsible > .nav-link-toggle').forEach(function(btn) {
            var li = btn.closest('.nav-item-collapsible');
            if (!li) return;
            items.push({ btn: btn, li: li, key: li.getAttribute('data-menu-key') || btn.id });
        });

        items.forEach(function(item) {
            item.btn.addEventListener('click', function() {
                var isOpen = !item.li.classList.contains('collapsed');

                if (isOpen) {
                    // 開いている → 閉じる
                    item.li.classList.add('collapsed');
                    item.btn.setAttribute('aria-expanded', 'false');
                } else {
                    // 他を全て閉じてから開く
                    items.forEach(function(other) {
                        other.li.classList.add('collapsed');
                        other.btn.setAttribute('aria-expanded', 'false');
                    });
                    item.li.classList.remove('collapsed');
                    item.btn.setAttribute('aria-expanded', 'true');
                }
            });
        });
    })();

    // サブグループ (サイト分析/SEO/サイトレポート/口コミアンケート) のトグル
    // 初期は開いた状態。クリックで開閉を独立にトグル (互いに干渉しない)
    (function() {
        var toggles = document.querySelectorAll('.nav-subgroup-toggle');
        toggles.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var wrapper = btn.closest('.nav-subgroup-wrapper');
                if (!wrapper) return;
                var collapsed = wrapper.classList.toggle('collapsed');
                btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            });
        });
    })();
// 期間切替ボタン（ダッシュボード専用）
// ダッシュボードのKPI期間切替だけ有効化（分析ページと干渉させない）
if (typeof updateKPIData === 'function') {
  const kpiPeriodBtns = document.querySelectorAll('.kpi-header .period-selector .period-btn');

  kpiPeriodBtns.forEach(btn => {
    btn.addEventListener('click', function () {
      kpiPeriodBtns.forEach(b => b.classList.remove('active'));
      this.classList.add('active');

      const period = this.dataset.period;
      updateKPIData(period);
    });
  });
}


});
</script>

<?php wp_footer(); ?>
</body>
</html>
