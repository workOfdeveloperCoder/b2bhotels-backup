<section class="relative min-h-[400px] flex items-center justify-center pt-12 pb-16 sm:py-14">
  <div class="absolute inset-0 z-0 overflow-hidden">
    <img src="<?=root?>uploads/global/cover.png" alt="Umrah packages" class="w-full h-full object-cover">
    <div class="absolute inset-0 bg-blue-700 bg-opacity-30"></div>
  </div>
  <div class="relative z-10 text-black container border-0">
    <?php $mhType = 'umrah'; include views . 'includes/module-hero/heading.php'; ?>
    <div class="p-6 rounded-lg bg-white shadow-lg mt-4 border-0">
      <?php require_once "umrah-search.php"; ?>
    </div>
  </div>
</section>

<?php
// FEATURED SECTION — THE SAME ONE AS ON THE HOME PAGE (SELF-CONTAINED: LOADS ITS
// OWN STYLES AND READS THE FEATURED PACKAGES; NOTHING IS SHOWN WITHOUT ANY).
include views . 'modules/umrah/featured.php';
?>

<?php // SPACE BEFORE THE FOOTER (module-hero/styles.php) ?>
<div class="mh-end" aria-hidden="true"></div>
