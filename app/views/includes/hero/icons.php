<?php
// ============================================================================
// HERO / ICONS
// Solid icons drawn for this project. Each one has named parts (i-handle,
// i-meridian, i-tick ...) that styles.php moves when the shortcut is hovered.
//   heroIcon('bag' | 'globe' | 'shield' | 'esim')   animated shortcut icons
//   heroBadgeIcon('search' | 'bolt' | 'tag')         small icons for the badges
//   heroTabFont(['flight', 'hotel', ...])            thin icon font for the tabs
//
// LOTTIE FILES (optional)
// An icon can be played from a Lottie file instead of the drawing. Put the file
// in assets/lottie/hero/ and it is used by itself, played on hover:
//   bag    -> luggage.json       globe -> globe.json
//   shield -> verified.json      esim  -> qr-scan.json
// The icon takes the colour of the text around it. Use files you hold a
// licence for (for example from your own Lordicon account).
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('heroTabFont')) {
  // The site loads Material Symbols in one weight (400). The tabs show their
  // icons large, where that weight looks heavy, so a thin cut (200-300) is
  // loaded for the tab icons only: a few icons, a few kilobytes.
  //
  // Only the FONT FACE is taken from the font service. Its stylesheet also sets
  // the size of every .material-symbols-outlined to 24px; linked in the page
  // body that rule would win over the icon sizes of the whole page.
  // The thin cut is registered as its own family, 'Material Symbols Outlined Thin'.
  function heroTabFont(array $icons): string {
    $names = [];
    foreach ($icons as $icon) {
      $icon = strtolower(trim((string) $icon));
      if ($icon !== '' && preg_match('/^[a-z0-9_]+$/', $icon)) {
        $names[$icon] = true;
      }
    }
    if (!$names) {
      return '';
    }
    $names = array_keys($names);
    sort($names, SORT_STRING);   // the font service wants the names in order
    $url = 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@40,200..300,0..1,0'
         . '&icon_names=' . implode(',', $names) . '&display=block';

    return '<script data-cfasync="false">'
         . 'window.heroTabFontReady=window.heroTabFontReady||fetch(' . json_encode($url, JSON_UNESCAPED_SLASHES) . ')'
         . '.then(function(r){return r.ok?r.text():""})'
         . '.then(function(css){var faces=css.match(/@font-face\s*\{[^}]*\}/g);if(!faces)return;'
         // its own family: no other icon of the page may fall on this cut, which holds the tab glyphs only
         . 'faces=faces.map(function(f){return f.replace(/font-family:\s*[\'"]?Material Symbols Outlined[\'"]?/,"font-family:\'Material Symbols Outlined Thin\'")});'
         . 'var s=document.createElement("style");s.setAttribute("data-hero-font","");s.textContent=faces.join("\n");document.head.appendChild(s)})'
         . '.catch(function(){});'
         . '</script>';
  }
}

if (!function_exists('heroIconLottie')) {
  // URL of the Lottie file of an icon, or '' when there is none.
  function heroIconLottie(string $name): string {
    static $files = ['bag' => 'luggage', 'globe' => 'globe', 'shield' => 'verified', 'esim' => 'qr-scan'];
    if (!isset($files[$name])) {
      return '';
    }
    $path = 'assets/lottie/hero/' . $files[$name] . '.json';
    $file = dirname(__DIR__, 4) . '/' . $path;
    return is_file($file) ? root . $path . '?v=' . filemtime($file) : '';
  }
}

if (!function_exists('heroIcon')) {
  function heroIcon(string $name): string {
    static $n = 0;
    $n++;
    $m = 'hr-ico-m' . $n;   // mask ids must be unique on the page

    $icons = [
      // suitcase: body with two slots, wheels, and a handle that pulls up
      'bag' => '<path class="i-handle" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M9.5 6.6V3.9a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v2.700"/>'
             . '<g class="i-body"><path fill-rule="evenodd" d="M7.5 6.5h9A2.5 2.5 0 0 1 19 9v8.500a2.5 2.5 0 0 1-2.5 2.5h-9A2.5 2.5 0 0 1 5 17.500V9a2.5 2.5 0 0 1 2.5-2.5Zm2 3.250a.75.75 0 0 0-.75.75v5.500a.75.75 0 0 0 1.5 0v-5.500a.75.75 0 0 0-.75-.75Zm5 0a.75.75 0 0 0-.75.75v5.500a.75.75 0 0 0 1.5 0v-5.500a.75.75 0 0 0-.75-.75Z"/>'
             . '<rect x="7.4" y="20.3" width="2.2" height="1.900" rx=".95"/><rect x="14.4" y="20.3" width="2.2" height="1.900" rx=".95"/></g>',

      // globe: a disc with the lines cut out; the meridians turn
      'globe' => '<mask id="' . $m . '"><circle cx="12" cy="12" r="9.600" fill="#fff"/>'
               . '<g fill="none" stroke="#000" stroke-width="1.500"><path d="M2 12h20"/><path d="M3.6 7.500h16.800M3.6 16.500h16.800"/>'
               . '<ellipse class="i-meridian" cx="12" cy="12" rx="4.300" ry="9.600"/><path class="i-axis" d="M12 2v20"/></g></mask>'
               . '<g class="i-ring"><rect width="24" height="24" mask="url(#' . $m . ')"/></g>',

      // shield: solid, with the tick cut out; the tick is drawn again on hover
      'shield' => '<mask id="' . $m . '"><path fill="#fff" d="M12 2.300 4.300 5.200v6c0 4.800 3.200 8.800 7.700 10.500 4.500-1.700 7.700-5.700 7.700-10.500v-6L12 2.300Z"/>'
                . '<path class="i-tick" pathLength="1" fill="none" stroke="#000" stroke-width="1.900" stroke-linecap="round" stroke-linejoin="round" d="m8.500 12.200 2.400 2.400 4.600-4.800"/></mask>'
                . '<g class="i-shield"><rect width="24" height="24" mask="url(#' . $m . ')"/></g>',

      // eSIM: scan corners around a code; a line scans it
      'esim' => '<g class="i-corners" fill="none" stroke="currentColor" stroke-width="1.900" stroke-linecap="round" stroke-linejoin="round">'
              . '<path d="M3.5 8V5.800a2.300 2.300 0 0 1 2.300-2.300H8"/><path d="M16 3.500h2.200a2.300 2.300 0 0 1 2.300 2.300V8"/>'
              . '<path d="M20.500 16v2.200a2.300 2.300 0 0 1-2.300 2.300H16"/><path d="M8 20.500H5.800a2.300 2.300 0 0 1-2.300-2.300V16"/></g>'
              . '<g class="i-chip"><rect x="7" y="7" width="4.300" height="4.300" rx="1.100"/><rect x="12.700" y="7" width="4.300" height="4.300" rx="1.100"/>'
              . '<rect x="7" y="12.700" width="4.300" height="4.300" rx="1.100"/><rect x="12.700" y="12.700" width="1.900" height="1.900" rx=".55"/>'
              . '<rect x="15.100" y="15.100" width="1.900" height="1.900" rx=".55"/></g>'
              . '<rect class="i-scan" x="4.500" y="11.200" width="15" height="1.600" rx=".8"/>',
    ];
    if (!isset($icons[$name])) {
      return '';
    }
    $svg = '<svg class="hr-ico hr-ico-' . $name . '" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' . $icons[$name] . '</svg>';

    $lottie = heroIconLottie($name);
    if ($lottie === '') {
      return $svg;
    }
    return '<span class="hr-lottie" data-lottie="' . htmlspecialchars($lottie, ENT_QUOTES) . '" aria-hidden="true">' . $svg . '</span>';
  }
}

if (!function_exists('heroBadgeIcon')) {
  function heroBadgeIcon(string $name): string {
    static $icons = [
      'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/><path d="M8.5 11h5M11 8.500v5"/>',
      'bolt'   => '<path d="M13 2 4.500 13.500H11l-1 8.500 8.500-11.500H12l1-8.500Z"/>',
      'tag'    => '<path d="M12.6 2.6H4.500a2 2 0 0 0-2 2v8.100a2 2 0 0 0 .6 1.400l7.800 7.800a2 2 0 0 0 2.800 0l7.600-7.600a2 2 0 0 0 0-2.800L14 3.200a2 2 0 0 0-1.400-.6Z"/><circle cx="7.500" cy="7.500" r="1.200" fill="currentColor"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($icons[$name] ?? '') . '</svg>';
  }
}
