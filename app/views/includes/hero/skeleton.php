<?php
// ============================================================================
// HERO / SKELETON
// Grey blocks in the shape of a search form, with a light that runs over them.
// Shown in place of a form until that form is ready to be seen.
//   heroSkeleton(true)   form with a row of options on top (flights, bus ...)
//   heroSkeleton(false)  form with fields only
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('heroSkeleton')) {
  function heroSkeleton(bool $options = false): string {
    $html = '<div class="hr-skel" aria-hidden="true">';
    if ($options) {
      $html .= '<div class="hr-skel-top"><i class="is-pill"></i><i class="is-pill is-short"></i></div>';
    }
    $html .= '<div class="hr-skel-grid"><i></i><i></i><i></i><i></i><i class="is-btn"></i></div>';
    return $html . '</div>';
  }
}
