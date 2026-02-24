<?php
/**
 * Period Selector Component
 * Usage:
 *   set_query_var('gcrev_period_selector', [
 *     'id' => 'analysis-period',
 *     'items' => [
 *        ['value'=>'prev-month','label'=>'前月'],
 *        ['value'=>'last30','label'=>'直近30日'],
 *     ],
 *     'dropdown' => [
 *        ['value'=>'last90','label'=>'過去90日'],
 *        ['value'=>'last180','label'=>'過去半年'],
 *        ['value'=>'last365','label'=>'過去1年'],
 *     ],
 *     'default' => 'prev-month',
 *   ]);
 *   get_template_part('template-parts/period-selector');
 */

$cfg = get_query_var('gcrev_period_selector');
if (empty($cfg) || empty($cfg['id'])) return;

$id = esc_attr($cfg['id']);
$items = $cfg['items'] ?? [];
$dropdown = $cfg['dropdown'] ?? [];
$default = $cfg['default'] ?? ($items[0]['value'] ?? 'prev-month');
?>

<div class="gcrev-period-selector" data-period-selector id="<?php echo $id; ?>" data-default="<?php echo esc_attr($default); ?>">
  <div class="period-selector">

    <?php foreach ($items as $it): ?>
      <button class="period-btn" type="button"
        data-period="<?php echo esc_attr($it['value']); ?>">
        <?php echo esc_html($it['label']); ?>
      </button>
    <?php endforeach; ?>

    <?php if (!empty($dropdown)): ?>
      <div class="period-dropdown">
        <button class="period-btn period-dropdown-toggle" type="button" data-role="toggle">
          ▼ 期間指定
        </button>
        <div class="period-dropdown-menu" data-role="menu">
          <?php foreach ($dropdown as $it): ?>
            <button type="button" data-period="<?php echo esc_attr($it['value']); ?>">
              <?php echo esc_html($it['label']); ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>


