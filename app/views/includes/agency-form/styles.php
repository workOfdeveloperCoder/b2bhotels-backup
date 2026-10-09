<?php
// ============================================================================
// AGENCY FORM / STYLES
// The agency profile of an agent (agencies table), the same fields in the same
// order wherever it is edited: the agent's own Agency page and the admin user
// edit page. Plain CSS, logical properties; inputs are the shared .input /
// .textarea components.
//
// COMPONENT CLASSES
//   .af                 the block: .af-logo on one side, .af-fields grid on the other
//   .af-logo            the square logo picker (a label around a hidden file input):
//                       .af-logo-img (preview), .af-logo-empty (no logo yet), .af-logo-cta
//   .af-fields          the inputs, two columns (.af-wide spans both)
//   .af-hint            small help line
// ============================================================================
@$SECURE or die('Access Denied!');

if (!empty($GLOBALS['__agencyFormStyles'])) {
    return;
}
$GLOBALS['__agencyFormStyles'] = true;
?>
<style>
  .af { display: grid; grid-template-columns: 168px minmax(0, 1fr); gap: 20px; align-items: start; }
  .af-logo {
    position: relative; display: flex; align-items: center; justify-content: center; width: 168px; aspect-ratio: 1 / 1; overflow: hidden;
    border: 1px dashed #cbd5e1; background: #fff; cursor: pointer; transition: border-color .15s, background-color .15s;
  }
  .af-logo:hover, .af-logo:focus-within { border-color: var(--color-primary, #0052e7); background: color-mix(in srgb, var(--color-primary, #0052e7) 4%, #fff); }
  .af-logo input[type="file"] { position: absolute; width: 1px; height: 1px; opacity: 0; }
  .af-logo-img { width: 100%; height: 100%; padding: 12px; object-fit: contain; }
  .af-logo-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 12px; color: #64748b; font-size: 12px; font-weight: 600; text-align: center; }
  .af-logo-empty .material-symbols-outlined { font-size: 30px; color: #94a3b8; }
  .af-logo-cta {
    position: absolute; inset-inline: 0; bottom: 0; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 8px;
    background: rgba(15, 23, 42, .72); color: #fff; font-size: 12px; font-weight: 600; opacity: 0; transition: opacity .15s;
  }
  .af-logo-cta .material-symbols-outlined { font-size: 16px; }
  .af-logo:hover .af-logo-cta, .af-logo:focus-within .af-logo-cta { opacity: 1; }
  .af-logo.is-empty .af-logo-cta { display: none; }
  .af-hint { margin: 8px 0 0; color: #64748b; font-size: 11px; line-height: 1.4; }
  .af-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  .af-fields .af-wide { grid-column: 1 / -1; }
  .af-fields .form-control { margin: 0; }
  .af-fields .textarea { min-height: 72px; }
  @media (max-width: 767px) {
    .af { grid-template-columns: minmax(0, 1fr); }
    .af-logo { width: 140px; }
    .af-fields { grid-template-columns: minmax(0, 1fr); }
  }
</style>
