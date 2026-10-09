<?php
// ============================================================================
// ADMIN THEME / STYLES  (PHPTRAVELS design system, pt README §47)
// One layer for every /admin page. header.php adds the body class "ad-page" on
// admin URLs; every rule here is scoped to it and skips the site header
// (.site-header), the site footer (.ft) and the sidebar (.as-wrap). A body class
// is used instead of a wrapper div because some admin views close one </div>
// too many, which would end a wrapper early.
//
//  1. Shared components (.btn, .input, .select, .textarea, .card) read CSS
//     variables: they are redefined on the page content (square corners,
//     hairline borders, theme accent), so every page follows without edits.
//  2. Raw Tailwind utilities (rounded-xl, shadow-sm, border-gray-200 …) are
//     squared and flattened. Round things stay round (rounded-full: avatars,
//     dots, toggles); floating layers keep their shadow (shadow-lg and up,
//     anything fixed / absolute: menus, modals, popovers).
//  3. Common patterns: alerts, badges, tabs, labels, plain tables, headings.
//
// The accent is the theme colour (--color-primary), as everywhere in v10.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!empty($GLOBALS['__adminThemeStyles'])) {
    return;
}
$GLOBALS['__adminThemeStyles'] = true;

// $P = the page; $X = "not inside the site header, footer or sidebar";
// $C = the page-content elements (direct children of body that are not chrome).
$P = 'body.ad-page';
$X = ':not(:is(.site-header, .ft, .as-wrap) *)';
$C = 'body.ad-page > :not(.site-header):not(.ft):not(.as-wrap)';
?>
<style>
  <?= $P ?> {
    --ad-canvas: #f6f8fc; --ad-surface: #ffffff; --ad-hover: #f1f4fa; --ad-line: #e4e9f2;
    --ad-ink: #0b1220; --ad-mute: #5b6578; --ad-accent: var(--color-primary, #0052e7);
    --ad-tint: color-mix(in srgb, var(--ad-accent) 9%, var(--ad-surface));
    --ad-ring: 0 0 0 3px color-mix(in srgb, var(--ad-accent) 20%, transparent);
    background: var(--ad-canvas);
  }
  /* 1. SHARED COMPONENTS, VIA THEIR OWN VARIABLES — ONLY ON THE PAGE CONTENT */
  <?= $C ?> {
    --btn-border-radius: 0; --btn-height: 40px;
    --input-border-radius: 0; --input-border-color: var(--ad-line); --input-border-focus-color: var(--ad-accent);
    --select-border-radius: 0; --select-border-color: var(--ad-line); --select-border-focus-color: var(--ad-accent);
    --textarea-border-radius: 0; --textarea-border-color: var(--ad-line); --textarea-border-focus-color: var(--ad-accent);
    --card-border-radius: 0; --card-border-color: var(--ad-line); --card-border-width: 1px; --card-shadow: none;
    --radius: 0;
    color: var(--ad-ink);
  }
  <?= $P ?> > .ft { margin-top: 48px; }

  /* BUTTONS: NO SHADOW, NO PRESS-SCALE, SECONDARY = HAIRLINE */
  <?= $P ?> .btn<?= $X ?> { box-shadow: none; font-weight: 600; }
  <?= $P ?> .btn<?= $X ?>:active { transform: none; }
  <?= $P ?> :is(.btn.light, .btn.white)<?= $X ?> { background: var(--ad-surface); color: var(--ad-ink); border: 1px solid var(--ad-line); }
  <?= $P ?> :is(.btn.light, .btn.white)<?= $X ?>:hover { background: var(--ad-surface); border-color: color-mix(in srgb, var(--ad-accent) 45%, var(--ad-line)); color: var(--ad-accent); }
  <?= $P ?> .btn<?= $X ?>:focus-visible { box-shadow: var(--ad-ring); }

  /* FIELDS: ACCENT FOCUS RING */
  <?= $P ?> :is(.input, .select, .textarea, input[type="text"], input[type="email"], input[type="number"], input[type="password"],
                input[type="search"], input[type="url"], input[type="tel"], textarea, select)<?= $X ?>:focus {
    border-color: var(--ad-accent); box-shadow: var(--ad-ring); outline: none;
  }
  <?= $P ?> .form-control > label<?= $X ?> { font-size: 13px; font-weight: 600; color: var(--ad-ink); }

  /* CARDS / PANELS */
  <?= $P ?> .card<?= $X ?> { box-shadow: none; }
  /* .section: the shared panel (rounded-3xl in tailwind.php; settings.php adds its own radius + shadow) */
  <?= $P ?> .section<?= $X ?> { border-radius: 0; box-shadow: none; border-color: var(--ad-line); }
  <?= $P ?> .card-header<?= $X ?> { height: 48px; padding: 0 18px; background: var(--ad-canvas); border-color: var(--ad-line); }
  <?= $P ?> .card-header :is(h1, h2, h3, h4)<?= $X ?> { font-size: 14px; font-weight: 700; }

  /* 2. RAW TAILWIND UTILITIES: SQUARE CORNERS, FLAT SURFACES */
  <?= $P ?> :is(.rounded, .rounded-sm, .rounded-md, .rounded-lg, .rounded-xl, .rounded-2xl, .rounded-3xl,
          .rounded-t, .rounded-t-md, .rounded-t-lg, .rounded-t-xl, .rounded-t-2xl,
          .rounded-b, .rounded-b-md, .rounded-b-lg, .rounded-b-xl, .rounded-b-2xl,
          .rounded-l-lg, .rounded-r-lg, .rounded-l-md, .rounded-r-md, .rounded-s-lg, .rounded-e-lg,
          [class*="rounded-["]:not([class*="rounded-full"]))<?= $X ?> { border-radius: 0 !important; }
  <?= $P ?> :is(.shadow-sm, .shadow, .shadow-md, [class*="shadow-["]):not(.fixed):not(.absolute)<?= $X ?> { box-shadow: none !important; }
  <?= $P ?> :is(.hover\:shadow-md, .hover\:shadow-lg, .hover\:shadow-xl)<?= $X ?>:hover { box-shadow: none !important; }
  <?= $P ?> :is(.border-gray-100, .border-gray-200, .border-slate-100, .border-slate-200)<?= $X ?> { border-color: var(--ad-line); }
  <?= $P ?> :is(.divide-gray-100, .divide-gray-200)<?= $X ?> > :not([hidden]) ~ :not([hidden]) { border-color: var(--ad-line); }
  <?= $P ?> .bg-gray-50<?= $X ?> { background-color: var(--ad-canvas); }

  /* 3. COMMON PATTERNS */
  <?= $P ?> h1<?= $X ?> { font-weight: 800; letter-spacing: -.02em; }
  <?= $P ?> h2<?= $X ?> { letter-spacing: -.01em; }

  <?= $P ?> :is(.alert-success, .alert-error, .alert-warning, .alert-info)<?= $X ?> { border-radius: 0; border-inline-start-width: 3px; padding: 12px 18px; }
  <?= $P ?> .alert-success<?= $X ?> { border-inline-start-color: #059669; }
  <?= $P ?> .alert-error<?= $X ?> { border-inline-start-color: #dc2626; }
  <?= $P ?> .alert-warning<?= $X ?> { border-inline-start-color: #d97706; }
  <?= $P ?> .alert-info<?= $X ?> { border-inline-start-color: var(--ad-accent); }

  <?= $P ?> .badge<?= $X ?> { border-radius: 0; font-weight: 700; }
  <?= $P ?> .section-tabs .tab<?= $X ?> { border-radius: 0; }
  <?= $P ?> :is(.dropdown-menu, .dropdown-content)<?= $X ?> { border-radius: 0; border-color: var(--ad-line); }

  /* plain tables written by hand (CRUD tables have their own .cr styles) */
  <?= $P ?> table:not(.cr-table) > thead > tr > th<?= $X ?> {
    color: var(--ad-mute); font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; white-space: nowrap;
  }
  <?= $P ?> table:not(.cr-table) td<?= $X ?> { font-variant-numeric: tabular-nums; }

  <?= $P ?> :is(input[type="checkbox"], input[type="radio"])<?= $X ?> { accent-color: var(--ad-accent); }
  <?= $P ?> input[type="checkbox"]:not(.switch-input):not(.toggle-status-switch):not(.toggle-default-switch):not(.sr-only)<?= $X ?> { border-radius: 0; }

  <?= $P ?> :is(a, button, [role="button"], [tabindex])<?= $X ?>:focus-visible { outline: 2px solid var(--ad-accent); outline-offset: 2px; }
</style>
