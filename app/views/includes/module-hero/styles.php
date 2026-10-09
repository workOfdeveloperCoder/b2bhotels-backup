<?php
// ============================================================================
// MODULE HERO / STYLES
// The heading over the photo of each module page (/flights, /stays, /tours …):
// title (the page's h1) and one line under it, above the search card; plus the
// space that closes the page before the footer. Plain CSS, logical properties.
//
// COMPONENT CLASSES
//   .mh          heading block (centred, white on the photo)
//   .mh-title    h1;  .mh-lead  the line under it
//   .mh-end      bottom space of a module page
// ============================================================================
@$SECURE or die('Access Denied!');

if (!empty($GLOBALS['__moduleHeroStyles'])) {
    return;
}
$GLOBALS['__moduleHeroStyles'] = true;
?>
<style>
  .mh { max-width: 760px; margin: 0 auto 22px; padding-top: 8px; text-align: center; color: #fff; }
  .mh-title {
    margin: 0; font-size: clamp(1.75rem, 2.4vw + 1rem, 2.75rem); font-weight: 800; line-height: 1.12; letter-spacing: -.02em;
    text-wrap: balance; text-shadow: 0 2px 18px rgba(0, 0, 0, .35);
  }
  .mh-lead {
    margin: 10px auto 0; max-width: 60ch; font-size: clamp(.95rem, .4vw + .85rem, 1.1rem); line-height: 1.55;
    color: rgba(255, 255, 255, .92); text-shadow: 0 1px 12px rgba(0, 0, 0, .35);
  }
  .mh-end { height: clamp(3rem, 6vw, 5rem); }
  @media (min-width: 640px) { .mh { padding-top: 0; margin-bottom: 26px; } }
</style>
