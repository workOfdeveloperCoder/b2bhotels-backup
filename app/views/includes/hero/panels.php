<?php
// ============================================================================
// HERO / PANELS
// The white card with one search form per tab. On a tab switch the forms
// cross-fade (the one that leaves gets smaller and blurs) and the card moves
// to the height of the new form. The first form is printed with the page; the
// others are fetched from partials/search/{type}.
//
// LOADING
// The card starts as .is-loading: it shows a skeleton and keeps the form out
// of sight until styles and icons are ready (script.php removes the class).
// A form that is fetched later shows the same skeleton while it loads.
// ============================================================================
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/skeleton.php';
?>
<div class="hr-card is-loading<?= $hero['initial'] !== '' ? ' is-first' : '' ?>" x-ref="card">

  <div class="hr-card-skel"><?= heroSkeleton(in_array($hero['initial'], $hero['withOptions'], true)) ?></div>

  <?php foreach ($hero['tabs'] as $tab):
      $t = htmlspecialchars($tab['type'], ENT_QUOTES);
      $isInitial = $tab['type'] === $hero['initial'];
      $printed = $isInitial && $hero['formFile'] !== '';
  ?>
  <div class="hr-panel<?= $isInitial ? ' is-active' : '' ?>" role="tabpanel"
       :class="{ 'is-active': activeTab === '<?= $t ?>' }" :aria-hidden="activeTab !== '<?= $t ?>'">
    <div class="hr-panel-load" x-show="!searchLoaded['<?= $t ?>']"<?= $printed ? ' style="display:none"' : '' ?>>
      <?= heroSkeleton(in_array($tab['type'], $hero['withOptions'], true)) ?>
    </div>
    <div id="search-panel-<?= $t ?>" x-show="searchLoaded['<?= $t ?>']"<?= $printed ? '' : ' style="display:none"' ?>>
      <?php if ($printed) { include $hero['formFile']; } ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if ($hero['ai']): ?>
  <div class="hr-panel hr-panel-ai" role="tabpanel" :class="{ 'is-active': activeTab === 'ai' }" :aria-hidden="activeTab !== 'ai'">
    <?php include __DIR__ . '/ai-panel.php'; ?>
  </div>
  <?php endif; ?>

</div>
