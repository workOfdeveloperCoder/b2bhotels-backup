<?php
// ============================================================================
// HEADER / STYLES
// Plain CSS on purpose (no @apply): the header is painted before the Tailwind
// runtime has compiled anything, so it must not depend on it.
//
// COMPONENT CLASSES
//   .site-header            the bar. Add .is-overlay when it sits on a hero image
//   .hd-inner               content row, same width as the page .container
//   .hd-logo                brand image
//   .hd-link                text link / menu trigger        (Services, Support, Login)
//   .hd-chip                small bordered pill             (a single control)
//   .hd-group               several chips sharing one pill  (currency + language)
//   .hd-divider             thin vertical separator
//   .btn                    design-system button            (Signup, Login) — not defined here
//   .hd-icon-btn            square icon button              (mobile menu)
//   .hd-drop + .hd-menu     dropdown wrapper + panel
//   .hd-menu-item/-label/-sep/-meta   rows inside a panel
//   .hd-mobile + .hd-tile   mobile sheet + service tiles
// Every control in the bar is --hd-h tall, which is the theme button height,
// so links, chips, the currency/language pill and .btn always line up.
// Colours come from the active theme (--header-*, --btn-*, --color-primary).
// ============================================================================
@$SECURE or die('Access Denied!');
?>
<style>
  /* ------------------------------------------------------------ BAR */
  .site-header {
    --hd-h: var(--btn-height, 40px);
    --hd-fill: rgba(38, 38, 38, .05);
    --hd-fill-hover: rgba(38, 38, 38, .1);
    --hd-line: rgba(38, 38, 38, .12);
    position: sticky; top: 0; z-index: 50; width: 100%;
    height: var(--header-height);
    font-size: var(--header-font-size);
    color: var(--header-text-color);
    background: var(--header-background);
    border-bottom: var(--header-border-width) solid var(--header-border-color);
    box-shadow: 0 6px 28px rgba(15, 15, 26, .07);
    transition: background-color .3s ease, border-color .3s ease, box-shadow .3s ease, color .3s ease;
  }
  @supports (background: color-mix(in srgb, red 50%, blue)) {
    .site-header {
      --hd-fill: color-mix(in srgb, currentColor 5%, transparent);
      --hd-fill-hover: color-mix(in srgb, currentColor 10%, transparent);
      --hd-line: color-mix(in srgb, currentColor 13%, transparent);
      background: color-mix(in srgb, var(--header-background) 82%, transparent);
      -webkit-backdrop-filter: saturate(1.4) blur(12px);
      backdrop-filter: saturate(1.4) blur(12px);
    }
  }
  .site-header a { text-decoration: none; }
  .site-header button { font-family: inherit; cursor: pointer; }

  .hd-inner {
    position: relative; z-index: 1;
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    height: 100%; max-width: 80rem; margin: 0 auto; padding: 0 24px;
  }
  .hd-side { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
  /* Nothing in the bar may be squeezed: a squeezed pill cuts off its own content. */
  .hd-side > *, .hd-desktop > *, .hd-group > * { flex-shrink: 0; }
  .hd-nav { gap: 2px; }
  .site-header [hidden] { display: none !important; }
  .hd-desktop { display: none; align-items: center; gap: 10px; }
  @media (min-width: 1024px) {
    .hd-desktop { display: flex; }
    .hd-mobile-only { display: none !important; }
  }
  @media (max-width: 639px) { .hd-inner { padding: 0 16px; } }

  .hd-icon { flex-shrink: 0; }
  .hd-chevron { opacity: .7; transition: transform .2s ease; }
  .is-open > .hd-chevron, [aria-expanded="true"] > .hd-chevron { transform: rotate(180deg); }

  /* ------------------------------------------------------------ OVERLAY (on a hero image, before scrolling) */
  .site-header.is-overlay { margin-bottom: calc(-1 * var(--header-height)); }
  .site-header.is-overlay:not(.is-scrolled):not(.is-open) {
    --hd-fill: rgba(255, 255, 255, .15);
    --hd-fill-hover: rgba(255, 255, 255, .22);
    --hd-line: rgba(255, 255, 255, .25);
    color: #fff;
    background: transparent;
    border-bottom-color: transparent;
    box-shadow: none;
    -webkit-backdrop-filter: none; backdrop-filter: none;
  }
  .site-header.is-overlay:not(.is-scrolled):not(.is-open)::before {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background: linear-gradient(to bottom, rgba(0, 0, 0, .3), transparent);
  }
  .site-header.is-overlay:not(.is-scrolled):not(.is-open) :is(.hd-chip, .hd-group, .hd-icon-btn) {
    -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px);
  }
  /* one blur per pill: chips inside a group must not blur again */
  .site-header.is-overlay:not(.is-scrolled):not(.is-open) .hd-group .hd-chip { -webkit-backdrop-filter: none; backdrop-filter: none; }
  .site-header.is-overlay:not(.is-scrolled):not(.is-open) .hd-link { color: inherit; }
  .site-header.is-overlay:not(.is-scrolled):not(.is-open) .hd-logo img { filter: brightness(0) invert(1); }

  /* ------------------------------------------------------------ LOGO */
  .hd-logo { display: flex; align-items: center; flex-shrink: 0; height: 40px; max-width: 176px; }
  .hd-logo img { display: block; height: 32px; width: auto; max-width: 100%; object-fit: contain; transition: filter .3s ease; }
  @media (min-width: 768px) { .hd-logo img { height: 36px; } }

  /* ------------------------------------------------------------ LINK */
  .hd-link {
    display: inline-flex; align-items: center; gap: 7px;
    height: var(--hd-h); padding: 0 12px; border: 0; border-radius: var(--btn-border-radius, 8px); background: transparent;
    font-size: var(--header-font-size, 14px); font-weight: 600; line-height: 1; white-space: nowrap;
    color: var(--header-link-color);
    transition: background-color .2s ease, color .2s ease;
  }
  .hd-link:hover, .hd-link[aria-expanded="true"] { background: var(--hd-fill); color: var(--header-link-hover-color); }

  /* ------------------------------------------------------------ CHIP + GROUP */
  .hd-chip {
    display: inline-flex; align-items: center; gap: 7px;
    height: var(--hd-h); padding: 0 12px;
    border: 1px solid var(--hd-line); border-radius: var(--btn-border-radius, 8px); background: var(--hd-fill);
    font-size: var(--header-font-size, 14px); font-weight: 600; line-height: 1; white-space: nowrap; color: inherit;
    transition: background-color .2s ease, border-color .2s ease, transform .1s ease;
  }
  .hd-chip:hover, .hd-chip[aria-expanded="true"] { background: var(--hd-fill-hover); }
  .hd-chip:active { transform: scale(.98); }
  .hd-chip img { width: 18px; height: 18px; border-radius: 50%; object-fit: cover; }

  .hd-group {
    display: inline-flex; align-items: center; gap: 2px; height: var(--hd-h); padding: 0 3px; box-sizing: border-box;
    border: 1px solid var(--hd-line); border-radius: var(--btn-border-radius, 8px); background: var(--hd-fill);
  }
  /* chips inside the pill are 8px shorter, so the pill itself is --hd-h like everything else */
  .hd-group .hd-chip { height: calc(var(--hd-h) - 8px); padding: 0 10px; border-color: transparent; border-radius: calc(var(--btn-border-radius, 8px) - 2px); background: transparent; }
  .hd-group .hd-chip:hover, .hd-group .hd-chip[aria-expanded="true"] { background: var(--hd-fill-hover); }

  .hd-divider { flex-shrink: 0; width: 1px; height: 22px; background: var(--hd-line); }

  /* ------------------------------------------------------------ BUTTONS */
  /* Buttons are the design-system .btn (app/views/tailwind.php). Only what the bar needs is added. */
  .site-header .btn { flex-shrink: 0; text-decoration: none; font-size: var(--header-font-size, 14px); }
  .site-header .btn .hd-icon { opacity: .95; }

  .hd-icon-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: var(--hd-h); height: var(--hd-h); padding: 0;
    border: 1px solid var(--hd-line); border-radius: var(--btn-border-radius, 8px); background: var(--hd-fill); color: inherit;
    transition: background-color .2s ease, transform .1s ease;
  }
  .hd-icon-btn:hover { background: var(--hd-fill-hover); }
  .hd-icon-btn:active { transform: scale(.95); }

  /* ------------------------------------------------------------ DROPDOWN */
  .hd-drop { position: relative; display: inline-flex; }
  .hd-menu {
    position: absolute; top: calc(100% + 10px); inset-inline-start: 0; z-index: 60;
    min-width: 240px; max-height: min(70vh, 460px); overflow-y: auto; padding: 6px;
    border: 1px solid #e5e5e5; border-radius: 14px; background: #fff; color: #262626;
    box-shadow: 0 16px 40px -8px rgba(15, 15, 26, .18), 0 2px 6px rgba(15, 15, 26, .06);
    font-weight: 400; text-align: start;
    /* closed: lifted, slightly smaller, see-through. Opening eases it into place. */
    opacity: 0; visibility: hidden; pointer-events: none;
    transform: translateY(-8px) scale(.97); transform-origin: top;
    transition: opacity .22s ease, transform .22s cubic-bezier(.16, 1, .3, 1), visibility 0s linear .22s;
  }
  .hd-drop.is-open > .hd-menu {
    opacity: 1; visibility: visible; pointer-events: auto;
    transform: translateY(0) scale(1);
    transition: opacity .22s ease, transform .22s cubic-bezier(.16, 1, .3, 1), visibility 0s;
  }
  /* rows of a list that was just fetched fade in instead of popping */
  .hd-menu-list > * { animation: hd-row-in .25s ease both; }
  @keyframes hd-row-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
  .hd-menu.is-end { inset-inline-start: auto; inset-inline-end: 0; }
  /* hover menus of the bar: an invisible strip over the gap, so the pointer stays "in" on its way to the panel */
  .hd-nav .hd-drop.is-open::after { content: ''; position: absolute; top: 100%; inset-inline: 0; height: 12px; }
  .hd-menu-label { padding: 8px 10px 6px; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #a3a3a3; }
  .hd-menu-sep { height: 1px; margin: 6px 4px; background: #f0f0f0; }
  .hd-menu-item {
    display: flex; align-items: center; gap: 10px; width: 100%;
    min-height: var(--hd-h); padding: 0 10px; border: 0; border-radius: 9px; background: transparent;
    font-size: 13px; font-weight: 500; line-height: 1.25; color: #262626; text-align: start;
    transition: background-color .15s ease, color .15s ease;
  }
  .hd-menu-item:hover { background: #f5f5f5; color: #262626; }
  .hd-menu-item .material-symbols-outlined { font-size: 18px; color: #737373; }
  .hd-menu-item .hd-icon { color: #737373; }
  .hd-menu-item img { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; border: 1px solid #e5e5e5; background: #f5f5f5; flex-shrink: 0; }
  .hd-menu-text { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .hd-menu-meta { margin-inline-start: auto; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #a3a3a3; }
  /* selected row — design system: primary tint, primary text, check mark */
  .hd-menu-item.is-active { background: rgba(59, 130, 246, .06); color: var(--color-primary); font-weight: 600; }
  .hd-menu-item.is-active:hover { color: var(--color-primary); }
  @supports (background: color-mix(in srgb, red 50%, blue)) {
    .hd-menu-item.is-active { background: color-mix(in srgb, var(--color-primary) 7%, transparent); }
    .hd-menu-item.is-active:hover { background: color-mix(in srgb, var(--color-primary) 11%, transparent); }
  }
  .hd-menu-item.is-active .hd-menu-meta { color: inherit; opacity: .7; }
  .hd-menu-item .material-symbols-outlined.hd-menu-check { font-size: 18px !important; color: var(--color-primary); }
  .hd-menu-item.is-danger, .hd-menu-item.is-danger .hd-icon { color: #dc2626; }
  .hd-menu-item.is-danger:hover { background: #fef2f2; }
  .hd-menu-user { display: flex; align-items: center; gap: 10px; padding: 10px; margin-bottom: 4px; border-radius: 10px; background: #f7f7f7; }
  .hd-avatar {
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    width: 22px; height: 22px; border-radius: 50%;
    background: var(--btn-background); color: var(--btn-text-color);
    font-size: 11px; font-weight: 700; text-transform: uppercase;
  }
  .hd-avatar.is-lg { width: 36px; height: 36px; font-size: 14px; }
  .hd-spinner { display: flex; justify-content: center; padding: 24px 0; color: var(--color-primary); }
  .hd-spinner svg { animation: hd-spin .8s linear infinite; }
  @keyframes hd-spin { to { transform: rotate(360deg); } }

  /* ------------------------------------------------------------ MOBILE SHEET */
  .hd-mobile {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 40;
    max-height: calc(100vh - var(--header-height)); overflow-y: auto;
    padding: 16px 16px 24px;
    border-bottom: 1px solid #e5e5e5; background: #fff; color: #262626;
    box-shadow: 0 24px 40px -12px rgba(15, 15, 26, .25);
    opacity: 0; visibility: hidden; pointer-events: none; transform: translateY(-10px);
    transition: opacity .22s ease, transform .25s cubic-bezier(.16, 1, .3, 1), visibility 0s linear .25s;
  }
  .hd-mobile.is-open {
    opacity: 1; visibility: visible; pointer-events: auto; transform: none;
    transition: opacity .22s ease, transform .25s cubic-bezier(.16, 1, .3, 1), visibility 0s;
  }
  .hd-mobile-section + .hd-mobile-section { margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
  .hd-mobile .hd-menu-label { padding: 0 2px 10px; }
  .hd-mobile .hd-menu-item { min-height: 44px; font-size: 14px; }
  .hd-mobile .btn { width: 100%; }
  .hd-mobile .btn.is-full { grid-column: 1 / -1; }
  .hd-tiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
  .hd-tile {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
    min-height: 84px; padding: 12px 6px;
    border: 1px solid #e5e5e5; border-radius: 14px; background: #fff;
    font-size: 12px; font-weight: 600; line-height: 1.2; color: #262626; text-align: center;
    transition: border-color .15s ease, background-color .15s ease;
  }
  .hd-tile:hover { border-color: var(--color-primary); background: #fafafa; color: #262626; }
  .hd-tile .material-symbols-outlined { font-size: 26px !important; color: var(--color-primary); }
  .hd-mobile-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
  .hd-mobile-row > .is-full { grid-column: 1 / -1; }
  .hd-mobile .hd-chip { width: 100%; justify-content: space-between; border-color: #e5e5e5; background: #f7f7f7; color: #262626; font-size: 14px; }
  .hd-mobile-list { margin-top: 6px; max-height: 260px; overflow-y: auto; border: 1px solid #f0f0f0; border-radius: 12px; padding: 4px; }

  @media (prefers-reduced-motion: reduce) {
    .site-header, .site-header * { transition: none !important; animation-duration: .01ms !important; }
  }
</style>
