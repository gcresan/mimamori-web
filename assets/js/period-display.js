(function () {
  window.GCREV = window.GCREV || {};

  // デバッグモード（本番環境では false に設定）
  const DEBUG = true;

  function log(...args) {
    if (DEBUG) console.log('[GCREV:period-display]', ...args);
  }

  function fmt(start, end) {
    if (!start || !end) return "-";
    const s = String(start).replace(/-/g, "/");
    const e = String(end).replace(/-/g, "/");
    return s + " 〜 " + e;
  }

  /**
   * 階層を問わず期間情報を抽出する堅牢な関数
   * - payload / payload.data / period_meta / range_label など、混在しても拾える
   */
  function extractPeriodInfo(payload) {
    log('extractPeriodInfo input:', payload);

    // data プロパティがあればそちらを優先
    const data = payload?.data || payload;

    // 1. period_meta がある場合（最優先）
    if (data?.period_meta) {
      log('Found period_meta:', data.period_meta);
      return {
        current: data.period_meta.current || null,
        compare: data.period_meta.compare || null,
        currentLabel: data.period_meta.current?.label || null,
        compareLabel: data.period_meta.compare?.label || null
      };
    }

    // 2. current_period / comparison_period の形式
    const current = data?.current_period || payload?.current_period || null;
    const compare = data?.comparison_period || payload?.comparison_period || null;
    const currentLabel = data?.current_range_label || payload?.current_range_label || null;
    const compareLabel = data?.compare_range_label || payload?.compare_range_label || null;

    if (current) {
      log('Found current_period:', current);
      return {
        current,
        compare,
        currentLabel,
        compareLabel
      };
    }

    // 3. start_date / end_date の形式（フォールバック）
    if (data?.start_date && data?.end_date) {
      log('Found start_date/end_date:', data.start_date, data.end_date);
      return {
        current: { start: data.start_date, end: data.end_date },
        compare: null,
        currentLabel: null,
        compareLabel: null
      };
    }

    log('No period info found');
    return {
      current: null,
      compare: null,
      currentLabel: null,
      compareLabel: null
    };
  }

  /**
   * 期間表示を更新（共通）
   * - payload / payload.data / period_meta / range_label など、混在しても拾える
   * - #periodDisplay の表示形式は現状維持
   */
  window.GCREV.updatePeriodDisplay = function (payload, opts) {
    const options = opts || {};
    const elId = options.periodDisplayId || "periodDisplay";
    const el = document.getElementById(elId);
    
    if (!el) {
      log('ERROR: Element not found:', elId);
      return;
    }

    log('updatePeriodDisplay called with:', payload);

    const info = extractPeriodInfo(payload);
    log('Extracted info:', info);

    const current = info.current;
    const compare = info.compare;
    const currentLabel = info.currentLabel;
    const compareLabel = info.compareLabel;

    let html =
      "<strong>分析対象期間:</strong> " +
      (currentLabel || (current ? fmt(current.start, current.end) : "-"));

    const hasCompare =
      !!compareLabel || !!(compare && compare.start && compare.end);

    if (hasCompare) {
      html +=
        ' <span style="margin: 0 8px; color: #9ca3af;">|</span> ' +
        "<strong>比較期間:</strong> " +
        (compareLabel || fmt(compare.start, compare.end));
    }

    log('Setting innerHTML:', html);
    el.innerHTML = html;
  };

  // ページ読み込み時に確認
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      log('DOMContentLoaded - GCREV.updatePeriodDisplay is ready');
    });
  } else {
    log('Script loaded after DOM ready - GCREV.updatePeriodDisplay is ready');
  }
})();
