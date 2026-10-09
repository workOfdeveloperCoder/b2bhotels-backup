<?php
// ============================================================================
// HEADER / ACCOUNT (desktop)
// Support link, then either the signed-in account menu or Login + Signup.
// ============================================================================
@$SECURE or die('Access Denied!');
?>
<?php if ($hd['support'] !== ''): ?>
<a href="<?= htmlspecialchars($hd['support']) ?>" class="hd-link">
  <?= headerIcon('headset', 17) ?>
  <span><?= htmlspecialchars(T::support) ?></span>
</a>
<?php endif; ?>

<?php if ($hd['loggedIn']): ?>

<div class="hd-drop" x-data="{ open: false }" :class="{ 'is-open': open }" @click.outside="open = false" @keydown.escape.window="open = false">
  <button type="button" class="hd-chip" @click="open = !open" :aria-expanded="open">
    <span class="hd-avatar"><?= htmlspecialchars(mb_substr($hd['userName'], 0, 1)) ?></span>
    <span style="max-width:140px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($hd['userName']) ?></span>
    <?= headerIcon('chevron', 12, 'hd-chevron') ?>
  </button>
  <div class="hd-menu is-end">
    <div class="hd-menu-user">
      <span class="hd-avatar is-lg"><?= htmlspecialchars(mb_substr($hd['userName'], 0, 1)) ?></span>
      <div style="min-width:0">
        <div class="hd-menu-text" style="font-size:13px;font-weight:600"><?= htmlspecialchars($hd['userName']) ?></div>
        <div class="hd-menu-meta" style="margin:2px 0 0"><?= htmlspecialchars($hd['userRole']) ?></div>
      </div>
    </div>
    <?php foreach (headerAccountItems($hd) as $hdItem): ?>
      <?= headerMenuItem($hdItem) ?>
    <?php endforeach; ?>
    <div class="hd-menu-sep"></div>
    <?= headerMenuItem(['label' => T::logout, 'href' => root . 'logout', 'icon' => 'logout', 'danger' => true]) ?>
  </div>
</div>

<?php else: ?>

  <?php if (!$hd['showSignup']): ?>
  <a href="<?= htmlspecialchars($hd['loginHref']) ?>" class="btn">
    <?= headerIcon('login') ?>
    <span><?= htmlspecialchars(T::login) ?></span>
  </a>
  <?php else: ?>
  <a href="<?= htmlspecialchars($hd['loginHref']) ?>" class="hd-link">
    <?= headerIcon('login') ?>
    <span><?= htmlspecialchars(T::login) ?></span>
  </a>

    <?php if ($hd['agentReg']): ?>
    <div class="hd-drop" x-data="{ open: false }" :class="{ 'is-open': open }" @click.outside="open = false" @keydown.escape.window="open = false">
      <button type="button" class="btn" @click="open = !open" :aria-expanded="open">
        <?= headerIcon('user-plus') ?>
        <span><?= htmlspecialchars(T::signup) ?></span>
        <?= headerIcon('chevron', 12, 'hd-chevron') ?>
      </button>
      <div class="hd-menu is-end">
        <?php foreach (headerSignupItems() as $hdItem): ?>
          <?= headerMenuItem($hdItem) ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php else: ?>
    <a href="<?= root ?>signup" class="btn">
      <?= headerIcon('user-plus') ?>
      <span><?= htmlspecialchars(T::signup) ?></span>
    </a>
    <?php endif; ?>
  <?php endif; ?>

<?php endif; ?>
