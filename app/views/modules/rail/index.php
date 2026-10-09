<?php @$SECURE or die('Access Denied!'); ?>
<section class="relative min-h-[450px] flex items-center justify-center pt-12 pb-16 sm:py-14">
  <div class="absolute inset-0 z-0 overflow-hidden">
    <img src="<?=root?>uploads/global/cover.png" alt="Rail" class="w-full h-full object-cover">
    <div class="absolute inset-0 bg-indigo-900 bg-opacity-45"></div>
  </div>
  <div class="relative z-10 text-black container">
    <?php $mhType = 'rail'; include views . 'includes/module-hero/heading.php'; ?>
    <div class="p-6 rounded-lg bg-white shadow-lg mt-4">
      <?php include "rail-search.php"; ?>
    </div>
  </div>
</section>

<?php // SPACE BEFORE THE FOOTER (module-hero/styles.php) ?>
<div class="mh-end" aria-hidden="true"></div>
