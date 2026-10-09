<?php
// ============================================================================
// MODULE HERO / HEADING
// Title + one line above the search card of a module page. Needs $mhType
// (flights, stays, tours, cars, bus, rail, cruises, ferries, umrah, visa);
// the words are the mh_<type>_title / mh_<type>_lead keys in app/lang/*.json.
// ============================================================================
@$SECURE or die('Access Denied!');

require __DIR__ . '/styles.php';

$mhType = preg_replace('/[^a-z]/', '', strtolower((string) ($mhType ?? '')));
$mhTitleKey = 'T::mh_' . $mhType . '_title';
$mhLeadKey = 'T::mh_' . $mhType . '_lead';
if ($mhType === '' || !defined($mhTitleKey)) {
    return;
}
?>
<div class="mh">
  <h1 class="mh-title"><?= htmlspecialchars((string) constant($mhTitleKey)) ?></h1>
  <?php if (defined($mhLeadKey)): ?>
    <p class="mh-lead"><?= htmlspecialchars((string) constant($mhLeadKey)) ?></p>
  <?php endif; ?>
</div>
