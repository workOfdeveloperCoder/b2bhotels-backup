<?php
// ============================================================================
// HERO / SHORTCUTS
// The pill under the card: services that open their own page instead of
// searching from the hero. Icons move on hover (see icons.php + styles.php).
// An item without a page yet is a button that looks the same as the others.
// ============================================================================
@$SECURE or die('Access Denied!');

if (empty($hero['shortcuts'])) {
    return;
}
?>
<div class="hr-shortcuts-row">
  <div class="hr-shortcuts">
    <?php foreach ($hero['shortcuts'] as $item): ?>
      <?php if ($item['href'] !== ''): ?>
      <a href="<?= htmlspecialchars($item['href']) ?>" class="hr-shortcut">
      <?php else: ?>
      <button type="button" class="hr-shortcut">
      <?php endif; ?>
        <?= heroIcon($item['icon']) ?>
        <span><?= htmlspecialchars((string) $item['label']) ?></span>
        <?php if (!empty($item['soon'])): ?><span class="hr-soon"><?= htmlspecialchars(T::soon) ?></span><?php endif; ?>
      <?= $item['href'] !== '' ? '</a>' : '</button>' ?>

    <?php endforeach; ?>
  </div>
</div>
<?php include __DIR__ . '/lottie.php'; ?>
