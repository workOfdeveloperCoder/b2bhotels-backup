<?php
// PARTIAL: HEADER CURRENCY LIST
// FETCHED ON DEMAND (GET partials/currencies) WHEN THE HEADER DROPDOWN OPENS,
// SO THE LIST AND ITS FLAG IMAGES ARE NOT PART OF THE FIRST-PAINT DOM.
@$SECURE or die('Access Denied!');

if (!function_exists('flagAssetPath')) {
  function flagAssetPath($flagCode) {
    static $availableFlags = null;

    if ($availableFlags === null) {
      $availableFlags = [];
      $flagsDir = __DIR__ . '/../../../assets/img/flags';
      foreach (glob($flagsDir . '/*.svg') as $flagFile) {
        $availableFlags[basename($flagFile, '.svg')] = true;
      }
    }

    $code = strtolower(trim((string)$flagCode));
    if ($code !== '' && isset($availableFlags[$code])) {
      return root . "assets/img/flags/{$code}.svg";
    }

    return root . 'assets/img/flags/xx.svg';
  }
}

// CURRENT CURRENCY FIRST, THEN THE REST
$currentCurrency = strtoupper((string)($_SESSION['app_currency'] ?? 'USD'));
$selectedCurr = null;
$otherCurrs = [];
foreach ($GLOBALS['currencies'] ?? [] as $curr) {
  if (strtoupper((string)$curr['name']) === $currentCurrency) {
    $selectedCurr = $curr;
  } else {
    $otherCurrs[] = $curr;
  }
}
$sortedCurrs = $selectedCurr ? array_merge([$selectedCurr], $otherCurrs) : $otherCurrs;
foreach ($sortedCurrs as $curr):
  $active = strtoupper((string)$curr['name']) === $currentCurrency;
  $flagCode = !empty($curr['country']) ? strtolower($curr['country']) : 'xx';
  $flagPath = flagAssetPath($flagCode);
?>
<a href="<?=root?>currency?currency=<?= urlencode((string) $curr['name']) ?>" class="hd-menu-item<?= $active ? ' is-active' : '' ?>">
  <img src="<?= $flagPath ?>" alt="" loading="lazy">
  <span class="hd-menu-text"><?= htmlspecialchars($curr['country_name'] ?? $curr['name']) ?></span>
  <span class="hd-menu-meta"><?= htmlspecialchars($curr['name']) ?></span>
  <?php if ($active): ?><span class="material-symbols-outlined hd-menu-check">check_circle</span><?php endif; ?>
</a>
<?php endforeach; ?>
