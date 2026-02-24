    </main>
</div><!-- .app-container -->

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
