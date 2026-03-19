<?php
/**
 * Analysis Unit Tabs Component
 * 複数解析ユニットがあるクライアント向けのタブ切り替えUI
 *
 * Usage:
 *   $units = gcrev_get_analysis_units( $user_id );
 *   if ( ! empty( $units ) ) :
 *     set_query_var( 'gcrev_analysis_units', $units );
 *     get_template_part( 'template-parts/analysis-unit-tabs' );
 *   endif;
 *
 * Fires: gcrev:unitChange custom event on the #analysisUnitTabs element
 *   detail: { unitId: int, unitType: string, unitLabel: string }
 */

$units = get_query_var( 'gcrev_analysis_units' );
if ( empty( $units ) || ! is_array( $units ) ) {
    return;
}
?>

<?php
$reload = get_query_var( 'gcrev_unit_reload', '0' );
$active_unit_id = absint( get_query_var( 'gcrev_active_unit_id', 0 ) );
?>
<div class="gcrev-unit-tabs" id="analysisUnitTabs" style="margin-bottom: 16px;"
     data-reload-on-change="<?php echo esc_attr( $reload ); ?>">
  <div class="period-selector">
    <button class="period-btn unit-tab <?php echo $active_unit_id === 0 ? 'active' : ''; ?>" type="button"
        data-unit-id="0" data-unit-type="all" data-unit-label="全体">
        全体
    </button>
    <?php foreach ( $units as $u ) : ?>
    <button class="period-btn unit-tab <?php echo $active_unit_id === (int) $u['id'] ? 'active' : ''; ?>" type="button"
        data-unit-id="<?php echo esc_attr( $u['id'] ); ?>"
        data-unit-type="<?php echo esc_attr( $u['unit_type'] ); ?>"
        data-unit-label="<?php echo esc_attr( $u['label'] ); ?>">
        <?php echo esc_html( $u['label'] ); ?>
    </button>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function() {
    'use strict';
    var root = document.getElementById('analysisUnitTabs');
    if (!root) return;

    // グローバルにユニットIDを保持（アクティブタブから初期化）
    if (!window.GCREV) window.GCREV = {};
    var activeBtn = root.querySelector('.unit-tab.active');
    window.GCREV.currentUnitId   = activeBtn ? (parseInt(activeBtn.dataset.unitId, 10) || 0) : 0;
    window.GCREV.currentUnitType = activeBtn ? (activeBtn.dataset.unitType || 'all') : 'all';

    root.addEventListener('click', function(e) {
        var btn = e.target.closest('.unit-tab');
        if (!btn) return;

        var unitId    = parseInt(btn.dataset.unitId, 10) || 0;
        var unitType  = btn.dataset.unitType || 'all';
        var unitLabel = btn.dataset.unitLabel || '全体';

        // 同じタブなら何もしない
        if (unitId === window.GCREV.currentUnitId) return;

        // アクティブ切り替え
        root.querySelectorAll('.unit-tab').forEach(function(b) {
            b.classList.remove('active');
        });
        btn.classList.add('active');

        // グローバル更新
        window.GCREV.currentUnitId   = unitId;
        window.GCREV.currentUnitType = unitType;

        // カスタムイベント発火
        var event = new CustomEvent('gcrev:unitChange', {
            detail: {
                unitId:    unitId,
                unitType:  unitType,
                unitLabel: unitLabel,
            },
            bubbles: true,
        });
        root.dispatchEvent(event);

        // ダッシュボード等サーバーサイドレンダリングのページではリロード
        if (root.dataset.reloadOnChange === '1') {
            var url = new URL(window.location.href);
            if (unitId > 0) {
                url.searchParams.set('unit', unitId);
            } else {
                url.searchParams.delete('unit');
            }
            window.location.href = url.toString();
        }
    });
})();
</script>
