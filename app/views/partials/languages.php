<?php
// PARTIAL: HEADER LANGUAGE LIST
// FETCHED ON DEMAND (GET partials/languages) WHEN THE HEADER DROPDOWN OPENS,
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

// CURRENT LANGUAGE FIRST, THEN THE REST
$currentLangCode = $_SESSION['app_language'] ?? 'en';
$selectedLang = null;
$otherLangs = [];
foreach ($GLOBALS['languages'] ?? [] as $lang) {
  if ($lang['lang_code'] === $currentLangCode) {
    $selectedLang = $lang;
  } else {
    $otherLangs[] = $lang;
  }
}
$sortedLangs = $selectedLang ? array_merge([$selectedLang], $otherLangs) : $otherLangs;
foreach ($sortedLangs as $lang):
  $active = $lang['lang_code'] === $currentLangCode;
  $flagCode = !empty($lang['country']) ? strtolower($lang['country']) : 'xx';
  $flagPath = flagAssetPath($flagCode);
?>
<a href="<?=root?>lang?lang=<?= urlencode((string) $lang['lang_code']) ?>" class="hd-menu-item<?= $active ? ' is-active' : '' ?>">
  <img src="<?= $flagPath ?>" alt="" loading="lazy">
  <span class="hd-menu-text"><?= htmlspecialchars($lang['name']) ?></span>
  <span class="hd-menu-meta"><?= htmlspecialchars($lang['lang_code']) ?></span>
  <?php if ($active): ?><span class="material-symbols-outlined hd-menu-check">check_circle</span><?php endif; ?>
</a>
<?php endforeach; ?>
