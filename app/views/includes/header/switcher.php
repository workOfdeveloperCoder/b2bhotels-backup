<?php
// ============================================================================
// HEADER / SWITCHER (desktop)
// Currency + language in one pill. Each list is fetched from partials/* the
// first time its menu opens, so it is not part of the first-paint page.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!$hd['showCurr'] && !$hd['showLang']) {
    return;
}
?>
<div class="hd-group">

  <?php if ($hd['showCurr']): ?>
  <div class="hd-drop" x-data="{ open: false }" :class="{ 'is-open': open }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="hd-chip" @mouseenter="loadPart('currencies')" @focus="loadPart('currencies')" @click="open = !open; loadPart('currencies')" :aria-expanded="open" aria-label="<?= htmlspecialchars(T::currency) ?>">
      <?= headerIcon('coins') ?>
      <span><?= htmlspecialchars($hd['currency']) ?></span>
      <?= headerIcon('chevron', 12, 'hd-chevron') ?>
    </button>
    <div class="hd-menu is-end">
      <div class="hd-menu-label"><?= htmlspecialchars(T::currency) ?></div>
      <div x-show="!parts.currencies"><?= headerSpinner() ?></div>
      <div class="hd-menu-list" x-html="parts.currencies"></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($hd['showLang']): ?>
  <div class="hd-drop" x-data="{ open: false }" :class="{ 'is-open': open }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="hd-chip" @mouseenter="loadPart('languages')" @focus="loadPart('languages')" @click="open = !open; loadPart('languages')" :aria-expanded="open" aria-label="<?= htmlspecialchars(T::language) ?>">
      <img src="<?= flagAssetPath($hd['langFlag']) ?>" alt="" width="18" height="18">
      <span style="text-transform:uppercase"><?= htmlspecialchars($hd['langCode']) ?></span>
      <?= headerIcon('chevron', 12, 'hd-chevron') ?>
    </button>
    <div class="hd-menu is-end">
      <div class="hd-menu-label"><?= htmlspecialchars(T::language) ?></div>
      <div x-show="!parts.languages"><?= headerSpinner() ?></div>
      <div class="hd-menu-list" x-html="parts.languages"></div>
    </div>
  </div>
  <?php endif; ?>

</div>
