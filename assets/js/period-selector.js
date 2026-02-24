(function () {
  function closeAllDropdowns() {
    document.querySelectorAll('.period-dropdown.open').forEach(dd => dd.classList.remove('open'));
  }

  function initSelector(root) {
    const defaultPeriod = root.dataset.default || 'prev-month';

    // 常にデフォルト期間（前月）で開く
    const initial = defaultPeriod;

    const buttons = root.querySelectorAll('.period-btn[data-period]');
    const dropdownToggle = root.querySelector('.period-dropdown-toggle');
    const dropdown = root.querySelector('.period-dropdown');

    function setActive(period) {
      buttons.forEach(b => b.classList.remove('active'));
      dropdownToggle?.classList.remove('active');

      const target = root.querySelector('.period-btn[data-period="' + period + '"]');
      if (target) {
        target.classList.add('active');
      } else {
        // dropdown item selected → toggle を active に
        dropdownToggle?.classList.add('active');
      }
    }

    function emit(period) {
      setActive(period);

      // 各ページ側のコールバックを呼ぶ
      const event = new CustomEvent('gcrev:periodChange', {
        detail: { period, selectorId: root.id || null }
      });
      root.dispatchEvent(event);
    }

    // top buttons
    buttons.forEach(btn => {
      btn.addEventListener('click', () => {
        const period = btn.dataset.period;
        if (!period) return;
        emit(period);
      });
    });

    // dropdown open/close
    if (dropdown && dropdownToggle) {
      dropdownToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('open');
      });

      dropdown.querySelectorAll('.period-dropdown-menu button[data-period]').forEach(btn => {
        btn.addEventListener('click', () => {
          const period = btn.dataset.period;
          if (!period) return;
          dropdown.classList.remove('open');
          emit(period);
        });
      });
    }

    // initial
    setActive(initial);
    emit(initial);
  }

  document.addEventListener('click', closeAllDropdowns);

  window.GCREV = window.GCREV || {};
  window.GCREV.initPeriodSelectors = function () {
    document.querySelectorAll('[data-period-selector]').forEach(initSelector);
  };

  document.addEventListener('DOMContentLoaded', () => {
    window.GCREV.initPeriodSelectors();
  });
})();



