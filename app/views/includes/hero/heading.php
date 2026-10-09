<?php
// HERO / HEADING — title, one line under it, and three short selling points.
@$SECURE or die('Access Denied!');
?>
<div class="hr-head">
  <h1 class="hr-title"><?= htmlspecialchars((string) $hero['title']) ?></h1>
  <?php if (trim((string) $hero['sub']) !== ''): ?>
  <p class="hr-sub"><?= htmlspecialchars((string) $hero['sub']) ?></p>
  <?php endif; ?>
  <div class="hr-badges">
    <?php foreach ($hero['badges'] as $badge): ?>
    <span class="hr-badge"><?= heroBadgeIcon($badge['icon']) ?><span><?= htmlspecialchars((string) $badge['text']) ?></span></span>
    <?php endforeach; ?>
  </div>
</div>
