<?php
// ============================================================================
// HERO / TABS
// One tab per module that searches from the hero. The white tab behind the
// active one (.hr-tab-ind) slides to the tab that is clicked: script.php reads
// the place and the width of that tab, so tabs may have any width. Pointing at a
// tab fetches its form in advance, so it is already there on the click.
// When the tabs do not fit (phones), a scroll bar appears above them.
// On the right: the AI Trip Planner button (when the feature is on).
// ============================================================================
@$SECURE or die('Access Denied!');

$heroTabCount = count($hero['tabs']);
?>
<?= heroTabFont(array_column($hero['tabs'], 'icon')) ?>

<div class="hr-tabs-row">

  <div class="hr-tabs-box" x-ref="tabsBox">
  <!-- shown only when some tabs do not fit: drag it, or swipe the tabs -->
  <div class="hr-tabs-bar" x-ref="tabsBar" aria-hidden="true"><i x-ref="tabsThumb"></i></div>
  <div class="hr-tabs<?= $hero['tabStyle'] === 'compact' ? ' is-compact' : '' ?>" role="tablist" x-ref="tabs">
    <span class="hr-tab-ind no-start<?= $heroTabCount < 2 ? ' no-end' : '' ?>" aria-hidden="true" style="--i:0" x-ref="tabInd"
          :style="{ '--i': Math.max(tabIndex(), 0) }"
          :class="{ 'is-off': tabIndex() < 0, 'no-start': tabIndex() <= 0, 'no-end': tabIndex() >= <?= $heroTabCount - 1 ?> }">
      <i class="c-start"></i><i class="c-end"></i>
    </span>
    <?php foreach ($hero['tabs'] as $tab): $t = htmlspecialchars($tab['type'], ENT_QUOTES); ?>
    <button type="button" class="hr-tab<?= $tab['type'] === $hero['initial'] ? ' is-active' : '' ?>" role="tab" data-tab="<?= $t ?>"
            :class="{ 'is-active': activeTab === '<?= $t ?>' }"
            :aria-selected="activeTab === '<?= $t ?>'"
            @click="activeTab = '<?= $t ?>'"
            @mouseenter="loadSearch('<?= $t ?>')" @focus="loadSearch('<?= $t ?>')" @touchstart.passive="loadSearch('<?= $t ?>')">
      <span class="hr-tab-in">
        <span class="material-symbols-outlined hr-tab-icon"><?= htmlspecialchars($tab['icon']) ?></span>
        <span class="hr-tab-label"><?= htmlspecialchars($tab['label']) ?></span>
      </span>
    </button>
    <?php endforeach; ?>
  </div>
  </div>

  <?php if ($hero['ai']): ?>
  <button type="button" class="hr-ai" :class="{ 'is-active': activeTab === 'ai' }" @click="activeTab = 'ai'">
    <span class="hr-ai-shine"></span>
    <span class="hr-ai-icon"><span class="material-symbols-outlined">auto_awesome</span></span>
    <span><?= htmlspecialchars(T::ai_trip_planner) ?></span>
    <span class="hr-ai-arrow">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
    </span>
  </button>
  <?php endif; ?>

</div>
