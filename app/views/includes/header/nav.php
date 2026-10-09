<?php
// ============================================================================
// HEADER / NAV (desktop)
// Services (active modules) + CMS menus + single CMS links.
//
// A site can have any number of menu pages. Items that do not fit in the bar
// are hidden and listed under "More" instead (see fitNav() in script.php), so
// the controls on the right are never squeezed or pushed out.
// ============================================================================
@$SECURE or die('Access Denied!');

if (empty($hd['services']) && empty($hd['menus']) && empty($hd['links'])) {
    return;
}

$hdNavIndex = 0;
?>
<span class="hd-divider hd-desktop"></span>
<nav class="hd-desktop hd-nav" x-ref="nav" aria-label="<?= htmlspecialchars(T::menu) ?>">

  <?php if (!empty($hd['services'])): ?>
  <div class="hd-drop" data-nav-item="<?= $hdNavIndex++ ?>" x-data="hdHoverMenu()" :class="{ 'is-open': open }" @mouseenter="enter()" @mouseleave="leave()" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="hd-link" @click="toggle()" :aria-expanded="open">
      <?= headerIcon('grid') ?>
      <span><?= ucfirst(T::services) ?></span>
      <?= headerIcon('chevron', 12, 'hd-chevron') ?>
    </button>
    <div class="hd-menu">
      <?php foreach ($hd['services'] as $hdSvc): ?>
        <?= headerMenuItem(['label' => $hdSvc['label'], 'href' => $hdSvc['href'], 'symbol' => $hdSvc['icon']]) ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php foreach ($hd['menus'] as $hdMenu): ?>
  <div class="hd-drop" data-nav-item="<?= $hdNavIndex++ ?>" x-data="hdHoverMenu()" :class="{ 'is-open': open }" @mouseenter="enter()" @mouseleave="leave()" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="hd-link" @click="toggle()" :aria-expanded="open">
      <?= headerIcon($hdMenu['icon']) ?>
      <span><?= htmlspecialchars($hdMenu['label']) ?></span>
      <?= headerIcon('chevron', 12, 'hd-chevron') ?>
    </button>
    <div class="hd-menu">
      <?php foreach ($hdMenu['children'] as $hdChild): ?>
        <?= headerMenuItem($hdChild) ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <?php foreach ($hd['links'] as $hdLink): ?>
  <a href="<?= htmlspecialchars($hdLink['href']) ?>" class="hd-link" data-nav-item="<?= $hdNavIndex++ ?>"<?= $hdLink['external'] ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
    <?= headerIcon($hdLink['icon']) ?>
    <span><?= htmlspecialchars($hdLink['label']) ?></span>
  </a>
  <?php endforeach; ?>

  <?php // MORE — the same items as rows, shown only for the ones hidden from the bar ?>
  <?php $moreIndex = 0; ?>
  <div class="hd-drop" data-nav-more hidden x-data="hdHoverMenu()" :class="{ 'is-open': open }" @mouseenter="enter()" @mouseleave="leave()" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="hd-link" @click="toggle()" :aria-expanded="open">
      <span><?= htmlspecialchars(T::more) ?></span>
      <?= headerIcon('chevron', 12, 'hd-chevron') ?>
    </button>
    <div class="hd-menu is-end">

      <?php if (!empty($hd['services'])): ?>
      <div data-more-item="<?= $moreIndex++ ?>">
        <div class="hd-menu-label"><?= ucfirst(T::services) ?></div>
        <?php foreach ($hd['services'] as $hdSvc): ?>
          <?= headerMenuItem(['label' => $hdSvc['label'], 'href' => $hdSvc['href'], 'symbol' => $hdSvc['icon']]) ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php foreach ($hd['menus'] as $hdMenu): ?>
      <div data-more-item="<?= $moreIndex++ ?>">
        <div class="hd-menu-label"><?= htmlspecialchars($hdMenu['label']) ?></div>
        <?php foreach ($hdMenu['children'] as $hdChild): ?>
          <?= headerMenuItem($hdChild) ?>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>

      <?php foreach ($hd['links'] as $hdLink): ?>
      <div data-more-item="<?= $moreIndex++ ?>">
        <?= headerMenuItem($hdLink) ?>
      </div>
      <?php endforeach; ?>

    </div>
  </div>

</nav>
