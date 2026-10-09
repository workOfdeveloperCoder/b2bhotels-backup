<?php
// ============================================================================
// UMRAH / FEATURED
// Featured Umrah packages on the home page: the newest one large on one side
// (picture, type, name, a line of the description, days and price), the next
// four as a list on the other (picture, type, name, place, days, price).
// Built from the featured components (app/views/includes/featured/, layout .fp).
// Server-rendered: the packages come from the umrah table. A package opens
// its page.
// ============================================================================
@$SECURE or die('Access Denied!');

$umFeatured = $db->select('umrah', '*', [
    'status'   => '1',
    'featured' => '1',
    'ORDER'    => ['created_at' => 'DESC', 'id' => 'DESC'],
    'LIMIT'    => 5,
]) ?: [];
if (empty($umFeatured)) {
    return;
}

$umCurrency = strtoupper($_SESSION['app_currency'] ?? 'USD');
$umModule   = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah', 'status' => 1]) ?: 'umrah';
$umAdults   = (int) ($_SESSION['umrah_adults'] ?? 1);
$umChildren = (int) ($_SESSION['umrah_children'] ?? 0);
$umStart    = date('d-m-Y', strtotime('+3 days'));

// Names of the package types, once
$umTypeIds = array_values(array_unique(array_filter(array_column($umFeatured, 'umrah_type_id'))));
$umTypes = [];
if ($umTypeIds) {
    foreach ($db->select('umrah_settings', ['id', 'setting_label'], ['id' => $umTypeIds]) ?: [] as $umType) {
        $umTypes[$umType['id']] = (string) $umType['setting_label'];
    }
}

// Everything a card shows, from a package row
$umCard = function (array $pkg) use ($db, $umModule, $umCurrency, $umTypes, $umAdults, $umChildren, $umStart): array {
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($pkg['name'] ?? ''))), '-') ?: 'umrah-package';
    $days = (int) ($pkg['days'] ?? 0);

    $images = json_decode((string) ($pkg['img'] ?? '[]'), true);
    $image = 'uploads/no_img.jpg';
    if (is_array($images) && $images) {
        $default = array_values(array_filter($images, fn($i) => is_array($i) && !empty($i['default'])));
        $first = $default[0] ?? $images[0];
        $image = is_array($first) ? (string) ($first['url'] ?? $image) : (string) $first;
    }

    $price = 0.0;
    if ((float) ($pkg['adult_price'] ?? 0) > 0) {
        $marked = MARKUP((float) $pkg['adult_price'], $umModule, $db, $pkg['currency'] ?: 'USD', $umCurrency);
        $price = (float) (is_array($marked) ? ($marked['price'] ?? 0) : $marked);
    }

    return [
        'name'  => (string) ($pkg['name'] ?? ''),
        'text'  => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) ($pkg['description'] ?? '')), ENT_QUOTES, 'UTF-8'))),
        'type'  => $umTypes[$pkg['umrah_type_id'] ?? 0] ?? '',
        'place' => (string) ($pkg['location'] ?? ''),
        'days'  => $days,
        'nights'=> (int) ($pkg['nights'] ?? 0),
        'price' => $price,
        'image' => root . ltrim(str_replace('\\/', '/', $image), '/'),
        'link'  => root . 'umrah/detail/' . $slug . '/' . (int) $pkg['id'] . '/umrah/' . $umStart . '/' . ($days ?: 'any') . '/' . $umAdults . '-' . $umChildren . '/any',
    ];
};

$umMain = $umCard(array_shift($umFeatured));
$umRows = array_map($umCard, $umFeatured);

require views . 'includes/featured/styles.php';

$umArrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$umDays = function (array $c): string {
    if ($c['days'] < 1) return '';
    $s = $c['days'] . ' ' . ($c['days'] === 1 ? T::day : T::days);
    if ($c['nights'] > 0) $s .= ' / ' . $c['nights'] . ' ' . ($c['nights'] === 1 ? T::night : T::nights);
    return $s;
};
$umPrice = function (array $c) use ($umCurrency): string {
    return $c['price'] > 0
        ? '<span>' . htmlspecialchars((string) T::from) . ' <b>' . htmlspecialchars($umCurrency) . ' ' . number_format($c['price'], 2) . '</b> / ' . htmlspecialchars((string) T::person) . '</span>'
        : '<span>' . htmlspecialchars((string) T::contact_for_price) . '</span>';
};
?>
<section class="fs<?= fsBand() ?>">
  <div class="container">
    <div class="fs-head">
      <h2 class="fs-title">
        <span class="fs-title-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21v-7M19 21v-7M4 14h16M12 3c-2.5 2.2-4.5 4.4-4.5 7A4.5 4.5 0 0 0 12 14a4.5 4.5 0 0 0 4.5-4c0-2.6-2-4.8-4.5-7Z"/><path d="M12 14v7M6 11V9M18 11V9"/></svg>
        </span>
        <span><?= htmlspecialchars((string) T::featured_umrah_packages) ?></span>
      </h2>
      <a class="fs-more" href="<?= root ?>umrah"><span><?= htmlspecialchars((string) T::view_all) ?></span><?= $umArrow ?></a>
    </div>

    <div class="fp">
      <!-- the newest package, large -->
      <a class="fp-main" href="<?= htmlspecialchars($umMain['link']) ?>">
        <img src="<?= htmlspecialchars($umMain['image']) ?>" alt="" loading="lazy" decoding="async" onerror="this.src='<?= root ?>uploads/no_img.jpg'">
        <?php if ($umMain['type'] !== '' || $umMain['place'] !== ''): ?>
        <span class="fp-chip"><?= htmlspecialchars($umMain['type'] !== '' ? $umMain['type'] : $umMain['place']) ?></span>
        <?php endif; ?>
        <h3 class="fp-title"><?= htmlspecialchars($umMain['name']) ?></h3>
        <?php if ($umMain['text'] !== ''): ?>
        <p class="fp-text"><?= htmlspecialchars(mb_substr($umMain['text'], 0, 180)) ?></p>
        <?php endif; ?>
        <div class="fp-meta">
          <?php if ($umMain['place'] !== ''): ?><span><?= htmlspecialchars($umMain['place']) ?></span><i></i><?php endif; ?>
          <?php if ($umDays($umMain) !== ''): ?><span><?= htmlspecialchars($umDays($umMain)) ?></span><i></i><?php endif; ?>
          <?= $umPrice($umMain) ?>
        </div>
      </a>

      <?php if ($umRows): ?>
      <!-- the next ones, as a list -->
      <div class="fp-list">
        <div class="fp-list-head"><?= htmlspecialchars((string) T::umrah_packages) ?></div>
        <?php foreach ($umRows as $umN => $umRow): ?>
        <a class="fp-row" href="<?= htmlspecialchars($umRow['link']) ?>" style="--n:<?= $umN + 1 ?>">
          <span class="fp-thumb"><img src="<?= htmlspecialchars($umRow['image']) ?>" alt="" loading="lazy" decoding="async" onerror="this.src='<?= root ?>uploads/no_img.jpg'"></span>
          <div>
            <?php if ($umRow['type'] !== ''): ?><span class="fp-chip"><?= htmlspecialchars($umRow['type']) ?></span><?php endif; ?>
            <h3 class="fp-title"><?= htmlspecialchars($umRow['name']) ?></h3>
            <div class="fp-meta">
              <?php if ($umRow['place'] !== ''): ?><span><?= htmlspecialchars($umRow['place']) ?></span><i></i><?php endif; ?>
              <?php if ($umDays($umRow) !== ''): ?><span><?= htmlspecialchars($umDays($umRow)) ?></span><i></i><?php endif; ?>
              <?= $umPrice($umRow) ?>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
