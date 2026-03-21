    </main>
</div><!-- .app-container -->

<?php if ( is_user_logged_in() && ( ! function_exists( 'mimamori_can' ) || mimamori_can( 'ai_chat' ) ) ) : ?>
    <?php get_template_part( 'template-parts/mimamori-ai-chat' ); ?>
<?php endif; ?>

<script>
// =============================================
// クライアントサイドキャッシュ（localStorage 永続版）
// ページ遷移・タブ閉じ・ブラウザ再起動でも保持され、
// TTL（2時間）で自動失効する。
// =============================================
window.gcrevCache = {
    TTL: 2 * 60 * 60 * 1000, // 2時間（サーバー側Transient 24hより十分短い）
    PREFIX: 'gcrev_',
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

// sessionStorage → localStorage 移行（一回限り）
(function() {
    try {
        if (sessionStorage.getItem('gcrev_migrated')) return;
        for (var i = 0; i < sessionStorage.length; i++) {
            var k = sessionStorage.key(i);
            if (k && k.indexOf('gcrev_') === 0 && !localStorage.getItem(k)) {
                localStorage.setItem(k, sessionStorage.getItem(k));
            }
        }
        sessionStorage.setItem('gcrev_migrated', '1');
    } catch(e) {}
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
    (function() {
        var menuIds = ['navToggleReport', 'navToggleRanking', 'navToggleDiagnosis', 'navToggleWebsite', 'navToggleMeo', 'navToggleSettings', 'navToggleSupport'];
        var items = [];

        menuIds.forEach(function(id) {
            var btn = document.getElementById(id);
            if (!btn) return;
            var li = btn.closest('.nav-item-collapsible');
            if (!li) return;
            items.push({ btn: btn, li: li, key: li.getAttribute('data-menu-key') || id });
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
