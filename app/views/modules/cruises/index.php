<?php @$SECURE or die('Access Denied!'); ?>
<section class="relative min-h-[400px] flex items-center justify-center pt-12 pb-16 sm:py-14">
  <div class="absolute inset-0 z-0 overflow-hidden">
    <img src="<?= root ?>uploads/global/cover.png" alt="Cruise holidays" class="w-full h-full object-cover">
    <div class="absolute inset-0 bg-blue-700 bg-opacity-30"></div>
  </div>
  <div class="relative z-10 text-black container">
    <?php $mhType = 'cruises'; include views . 'includes/module-hero/heading.php'; ?>
    <div class="p-6 rounded-lg bg-white shadow-lg mt-4">
      <?php include __DIR__ . '/cruises-search.php'; ?>
    </div>
  </div>
</section>

<?php if (!empty($featured)): ?>
<section class="bg-gray-100 dark:bg-gray-900 py-10">
  <div class="container">
    <div class="flex items-end justify-between mb-5">
      <div>
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Upcoming cruises</h2>
        <p class="text-sm text-slate-500">Hand-picked sailings departing soon</p>
      </div>
      <a href="<?= root ?>cruises/search" class="text-sm font-semibold text-blue-700 hover:underline">View all</a>
    </div>
    <div class="grid grid-cols-1 gap-4">
      <?php $cardQuery = []; foreach ($featured as $c) { include __DIR__ . '/listing/cruise-card.php'; } ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php // SPACE BEFORE THE FOOTER (module-hero/styles.php) ?>
<div class="mh-end" aria-hidden="true"></div>
