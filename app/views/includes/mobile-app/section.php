<?php
// ============================================================================
// MOBILE APP / SECTION
// "Get the app": the words and the store badges, two phones drawn in CSS with
// a glimpse of the app (home screen and a ticket), three numbered reasons.
// Needs $maIos and $maAndroid (store links; an empty one hides its badge).
// ============================================================================
@$SECURE or die('Access Denied!');

require views . 'includes/mobile-app/styles.php';

$maIos = $maIos ?? '';
$maAndroid = $maAndroid ?? '';
$maTiles = [
    ['flights', T::flights, '<path d="M21 16v-2l-8-5V3.5A1.5 1.5 0 0 0 11.5 2 1.5 1.5 0 0 0 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>'],
    ['stays',   T::stays,   '<path d="M3 21V7l9-4 9 4v14M9 21v-6h6v6M8 11h.01M12 11h.01M16 11h.01"/>'],
    ['cars',    T::cars,    '<path d="M5 17h14M6 17l1.5-6h9L18 17M6 17v2M18 17v2M8 14h.01M16 14h.01"/>'],
    ['tours',   T::tours,   '<circle cx="12" cy="12" r="9"/><path d="m15 9-2 6-4 2 2-6z"/>'],
    ['visa',    T::visa,    '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="10" r="2.5"/><path d="M8 17c1-2 7-2 8 0"/>'],
    ['esim',    'eSIM',     '<path d="M7 3h7l5 5v13H7z"/><rect x="9.5" y="11.5" width="6" height="5" rx="1"/>'],
];
$maPoints = [
    [T::app_point_1_title, T::app_point_1_text],
    [T::app_point_2_title, T::app_point_2_text],
    [T::app_point_3_title, T::app_point_3_text],
];
?>
<section class="ma">
  <span class="ma-glow" aria-hidden="true"></span>
  <div class="ma-grid">

  <!-- the words -->
  <div>
    <p class="ma-eyebrow"><?= htmlspecialchars((string) T::mobile_apps) ?></p>
    <h2 class="ma-title"><?= htmlspecialchars((string) T::app_title_1) ?> <em><?= htmlspecialchars((string) T::app_title_2) ?></em></h2>
    <p class="ma-lead"><?= htmlspecialchars((string) T::app_lead) ?></p>
    <div class="ma-stores">
      <?php if ($maIos !== ''): ?>
      <a class="ma-store" href="<?= htmlspecialchars($maIos) ?>" target="_blank" rel="noopener noreferrer">
        <svg viewBox="0 0 384 512" fill="currentColor" aria-hidden="true"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5q0 39.3 14.4 81.2c12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>
        <span><small><?= htmlspecialchars((string) T::download_on_the) ?></small><b>App Store</b></span>
      </a>
      <?php endif; ?>
      <?php if ($maAndroid !== ''): ?>
      <a class="ma-store" href="<?= htmlspecialchars($maAndroid) ?>" target="_blank" rel="noopener noreferrer">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.609 1.814 13.792 12 3.61 22.186a.996.996 0 0 1-.61-.92V2.734a1 1 0 0 1 .609-.92zm10.89 10.893 2.302 2.302-10.937 6.333 8.635-8.635zm3.199-3.198 2.807 1.626a1 1 0 0 1 0 1.73l-2.808 1.626L15.206 12l2.492-2.491zM5.864 2.658 16.8 8.99l-2.302 2.302-8.635-8.635z"/></svg>
        <span><small><?= htmlspecialchars((string) T::get_it_on) ?></small><b>Google Play</b></span>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- the phones -->
  <div class="ma-phones" aria-hidden="true">
    <div class="ma-phone is-back">
      <div class="ma-screen">
        <div class="ma-bar"><span>9:41</span><span>●●●</span></div>
        <div class="ma-ticket">
          <small><?= htmlspecialchars((string) T::flights) ?> · <?= htmlspecialchars((string) T::confirmed) ?></small>
          <div class="ma-route">DXB<span></span>LHR</div>
          <div class="ma-cells">
            <div><small><?= htmlspecialchars((string) T::date) ?></small><b>12 Oct</b></div>
            <div><small><?= htmlspecialchars((string) T::time) ?></small><b>07:30</b></div>
            <div><small><?= htmlspecialchars((string) T::seat) ?></small><b>14A</b></div>
          </div>
          <div class="ma-qr"><?= str_repeat('<i></i>', 49) ?></div>
        </div>
        <div class="ma-note"><b><?= htmlspecialchars((string) T::stays) ?> · <?= htmlspecialchars((string) T::confirmed) ?></b>London · 12–15 Oct · 2 <?= htmlspecialchars((string) T::adults) ?></div>
        <div class="ma-note"><b>eSIM · <?= htmlspecialchars((string) T::active) ?></b>United Kingdom · 5 GB · 15 <?= htmlspecialchars((string) T::days) ?></div>
      </div>
    </div>
    <div class="ma-phone is-front">
      <div class="ma-screen">
        <div class="ma-bar"><span>9:41</span><span>●●●</span></div>
        <div class="ma-hello"><small><?= htmlspecialchars((string) T::welcome) ?></small><b><?= htmlspecialchars((string) T::app_where_next) ?></b></div>
        <div class="ma-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><span><?= htmlspecialchars((string) T::search) ?>…</span></div>
        <div class="ma-tiles">
          <?php foreach ($maTiles as [$maKey, $maLabel, $maPath]): ?>
          <div class="ma-tile"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $maPath ?></svg><span><?= htmlspecialchars((string) $maLabel) ?></span></div>
          <?php endforeach; ?>
        </div>
        <div class="ma-deal"><i></i><div><b>DXB → LHR</b><small>Emirates · 7h 35m</small></div><em>$669</em></div>
        <div class="ma-deal"><i></i><div><b>Makkah · 7 <?= htmlspecialchars((string) T::days) ?></b><small><?= htmlspecialchars((string) T::umrah) ?> · 4★</small></div><em>$1,200</em></div>
      </div>
    </div>
  </div>

  <!-- the reasons -->
  <ol class="ma-points">
    <?php foreach ($maPoints as $maN => [$maTitle, $maText]): ?>
    <li class="ma-point">
      <b><?= sprintf('%02d', $maN + 1) ?></b>
      <div><h3><?= htmlspecialchars((string) $maTitle) ?></h3><p><?= htmlspecialchars((string) $maText) ?></p></div>
    </li>
    <?php endforeach; ?>
  </ol>

  </div>
</section>
