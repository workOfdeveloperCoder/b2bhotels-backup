<?php
// ============================================================================
// HEADER / MOBILE SHEET
// Opens under the bar on phones and tablets: services, pages, currency and
// language, then the account actions.
// ============================================================================
@$SECURE or die('Access Denied!');
?>
<div class="hd-mobile hd-mobile-only"
     :class="{ 'is-open': mobile }"
     @click.outside="if (!$event.target.closest('.hd-icon-btn')) mobile = false" @keydown.escape.window="mobile = false"
     x-data="{ section: '' , toggle(name) { this.section = this.section === name ? '' : name } }"
     >

  <?php if (!empty($hd['services'])): ?>
  <div class="hd-mobile-section">
    <div class="hd-menu-label"><?= ucfirst(T::services) ?></div>
    <div class="hd-tiles">
      <?php foreach ($hd['services'] as $hdSvc): ?>
      <a href="<?= htmlspecialchars($hdSvc['href']) ?>" class="hd-tile">
        <span class="material-symbols-outlined"><?= htmlspecialchars($hdSvc['icon']) ?></span>
        <span><?= htmlspecialchars($hdSvc['label']) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($hd['menus']) || !empty($hd['links']) || $hd['support'] !== ''): ?>
  <div class="hd-mobile-section">
    <?php foreach ($hd['menus'] as $hdI => $hdMenu): ?>
    <button type="button" class="hd-menu-item" @click="toggle('menu<?= $hdI ?>')" :aria-expanded="section === 'menu<?= $hdI ?>'">
      <?= headerIcon($hdMenu['icon']) ?>
      <span class="hd-menu-text"><?= htmlspecialchars($hdMenu['label']) ?></span>
      <?= headerIcon('chevron', 14, 'hd-chevron') ?>
    </button>
    <div x-show="section === 'menu<?= $hdI ?>'" x-transition.duration.200ms style="display:none;padding-inline-start:26px">
      <?php foreach ($hdMenu['children'] as $hdChild): ?>
        <?= headerMenuItem($hdChild) ?>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php foreach ($hd['links'] as $hdLink): ?>
      <?= headerMenuItem($hdLink) ?>
    <?php endforeach; ?>

    <?php if ($hd['support'] !== ''): ?>
      <?= headerMenuItem(['label' => T::support, 'href' => $hd['support'], 'icon' => 'headset']) ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($hd['showCurr'] || $hd['showLang']): ?>
  <div class="hd-mobile-section">
    <div class="hd-mobile-row">
      <?php if ($hd['showCurr']): ?>
      <button type="button" class="hd-chip<?= $hd['showLang'] ? '' : ' is-full' ?>" @click="toggle('currency'); loadPart('currencies')" :aria-expanded="section === 'currency'">
        <span style="display:inline-flex;align-items:center;gap:8px"><?= headerIcon('coins') ?><?= htmlspecialchars($hd['currency']) ?></span>
        <?= headerIcon('chevron', 14, 'hd-chevron') ?>
      </button>
      <?php endif; ?>
      <?php if ($hd['showLang']): ?>
      <button type="button" class="hd-chip<?= $hd['showCurr'] ? '' : ' is-full' ?>" @click="toggle('language'); loadPart('languages')" :aria-expanded="section === 'language'">
        <span style="display:inline-flex;align-items:center;gap:8px"><img src="<?= flagAssetPath($hd['langFlag']) ?>" alt="" width="18" height="18"><?= htmlspecialchars($hd['langName']) ?></span>
        <?= headerIcon('chevron', 14, 'hd-chevron') ?>
      </button>
      <?php endif; ?>
    </div>
    <?php if ($hd['showCurr']): ?>
    <div class="hd-mobile-list" x-show="section === 'currency'" x-transition.duration.200ms style="display:none">
      <div x-show="!parts.currencies"><?= headerSpinner() ?></div>
      <div class="hd-menu-list" x-html="parts.currencies"></div>
    </div>
    <?php endif; ?>
    <?php if ($hd['showLang']): ?>
    <div class="hd-mobile-list" x-show="section === 'language'" x-transition.duration.200ms style="display:none">
      <div x-show="!parts.languages"><?= headerSpinner() ?></div>
      <div class="hd-menu-list" x-html="parts.languages"></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="hd-mobile-section">
    <?php if ($hd['loggedIn']): ?>

      <div class="hd-menu-user">
        <span class="hd-avatar is-lg"><?= htmlspecialchars(mb_substr($hd['userName'], 0, 1)) ?></span>
        <div style="min-width:0">
          <div class="hd-menu-text" style="font-size:14px;font-weight:600"><?= htmlspecialchars($hd['userName']) ?></div>
          <div class="hd-menu-meta" style="margin:2px 0 0"><?= htmlspecialchars($hd['userRole']) ?></div>
        </div>
      </div>
      <?php foreach (headerAccountItems($hd) as $hdItem): ?>
        <?= headerMenuItem($hdItem) ?>
      <?php endforeach; ?>
      <div class="hd-menu-sep"></div>
      <?= headerMenuItem(['label' => T::logout, 'href' => root . 'logout', 'icon' => 'logout', 'danger' => true]) ?>

    <?php else: ?>

      <div class="hd-mobile-row">
        <a href="<?= htmlspecialchars($hd['loginHref']) ?>" class="btn<?= $hd['showSignup'] ? ' light' : ' is-full' ?>">
          <?= headerIcon('login') ?>
          <span><?= htmlspecialchars(T::login) ?></span>
        </a>
        <?php if ($hd['showSignup'] && !$hd['agentReg']): ?>
        <a href="<?= root ?>signup" class="btn">
          <?= headerIcon('user-plus') ?>
          <span><?= htmlspecialchars(T::signup) ?></span>
        </a>
        <?php elseif ($hd['showSignup']): ?>
        <button type="button" class="btn" @click="toggle('signup')" :aria-expanded="section === 'signup'">
          <?= headerIcon('user-plus') ?>
          <span><?= htmlspecialchars(T::signup) ?></span>
          <?= headerIcon('chevron', 12, 'hd-chevron') ?>
        </button>
        <?php endif; ?>
      </div>
      <?php if ($hd['showSignup'] && $hd['agentReg']): ?>
      <div class="hd-mobile-list" x-show="section === 'signup'" x-transition.duration.200ms style="display:none">
        <?php foreach (headerSignupItems() as $hdItem): ?>
          <?= headerMenuItem($hdItem) ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>

</div>
