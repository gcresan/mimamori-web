    </main>
</div><!-- .app-container -->

<?php if ( is_user_logged_in() ) : ?>
    <?php get_template_part( 'template-parts/mimamori-ai-chat' ); ?>
<?php endif; ?>

<script>
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
        var menuIds = ['navToggleReport', 'navToggleAnalysis', 'navToggleSettings', 'navToggleSupport', 'navToggleOption', 'navToggleAccount'];
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
