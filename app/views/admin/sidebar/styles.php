<?php
// ============================================================================
// ADMIN SIDEBAR / STYLES  (PHPTRAVELS design system, pt README §47.7)
// Dark surface with a 1px hairline edge, square rows, one accent (the theme
// colour), spaced-caps group labels, a 2px start bar on the active row, a 1px
// guide line for sub-items, square count badges. Plain CSS (no @apply).
//
// COMPONENT CLASSES
//   .as / .is-wide            the panel (64px rail, 260px open); .as-backdrop, .as-burger (phones)
//   .as-head / .as-brand      top bar: accent tile + "Dashboard"; .as-icon-btn square 36px buttons
//   .as-search                search field; .as-search-clear
//   .as-nav                   the scrolling list
//   .as-label / .as-sep       group label (open) / hairline separator (rail)
//   .as-row                   a 40px row; .is-active (this page), .is-current (a child is this page)
//   .as-chev                  chevron that turns when a group opens
//   .as-sub / .as-subrow      sub-items on a guide line; .is-deep for the third level
//   .as-badge / .as-dot       square count badge / small attention dot on an icon
//   .as-foot / .as-user       user card at the bottom; .as-avatar; .as-menu floating menu
//   .as-spin                  loader shown until the icon font is ready
// ============================================================================
@$SECURE or die('Access Denied!');

if (!empty($GLOBALS['__adminSidebarStyles'])) {
    return;
}
$GLOBALS['__adminSidebarStyles'] = true;
?>
<style>
  .as {
    /* DARK PANEL (design system dark tokens). The accent is the theme colour lifted
       towards white so it reads on the dark surface; the brand tile keeps the pure colour. */
    --as-surface: #0b0f19; --as-canvas: #121828; --as-hover: #1a2134; --as-line: #232c40;
    --as-ink: #eef2fb; --as-mute: #9aa4bb; --as-brand: var(--color-primary, #0052e7);
    --as-accent: color-mix(in srgb, var(--as-brand) 55%, #ffffff);
    --as-tint: color-mix(in srgb, var(--as-brand) 22%, var(--as-surface));
    color-scheme: dark;
    position: fixed; top: 0; left: 0; bottom: 0; z-index: 100;
    flex-direction: column; width: 64px; overflow: hidden;
    background: var(--as-surface); border-inline-end: 1px solid var(--as-line); color: var(--as-ink);
    transition: width .2s linear;
  }
  .as.is-wide { width: 260px; }
  .as .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 350, 'GRAD' 0, 'opsz' 20; }
  .as button { font: inherit; }

  /* ------------------------------------------------------------ HEAD */
  .as-head { height: 64px; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 0 14px; border-bottom: 1px solid var(--as-line); }
  .as:not(.is-wide) .as-head { justify-content: center; padding: 0; }
  .as-brand { display: flex; align-items: center; gap: 10px; min-width: 0; color: var(--as-ink); text-decoration: none; font-size: 14px; font-weight: 700; letter-spacing: -.01em; white-space: nowrap; }
  .as-brand-tile { width: 32px; height: 32px; flex-shrink: 0; display: grid; place-items: center; background: var(--as-brand); color: #fff; }
  .as-brand-tile .material-symbols-outlined { font-size: 19px; }
  .as-icon-btn { width: 36px; height: 36px; flex-shrink: 0; display: grid; place-items: center; border: 0; background: transparent; color: var(--as-mute); cursor: pointer; transition: background-color .15s, color .15s; }
  .as-icon-btn:hover { background: var(--as-hover); color: var(--as-ink); }
  .as-icon-btn svg { width: 19px; height: 19px; }

  /* ------------------------------------------------------------ SEARCH */
  .as-search { flex-shrink: 0; padding: 12px 14px; border-bottom: 1px solid var(--as-line); }
  .as:not(.is-wide) .as-search { display: flex; justify-content: center; padding: 10px 0; }
  .as-search-field { position: relative; display: flex; align-items: center; }
  .as-search-field > .material-symbols-outlined { position: absolute; inset-inline-start: 10px; font-size: 17px; color: var(--as-mute); pointer-events: none; }
  .as-search input {
    width: 100%; height: 36px; padding-inline: 34px 30px; border: 1px solid var(--as-line); border-radius: 0;
    background: var(--as-canvas); color: var(--as-ink); font-size: 13px; outline: none; transition: border-color .15s, box-shadow .15s;
  }
  .as-search input::placeholder { color: var(--as-mute); }
  .as-search input:focus { border-color: var(--as-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--as-accent) 20%, transparent); background: var(--as-surface); }
  .as-search-clear { position: absolute; inset-inline-end: 4px; width: 26px; height: 26px; display: grid; place-items: center; border: 0; background: transparent; color: var(--as-mute); cursor: pointer; }
  .as-search-clear:hover { color: var(--as-ink); }
  .as-search-clear .material-symbols-outlined { font-size: 16px; }

  /* ------------------------------------------------------------ NAV */
  .as-nav { flex: 1; min-height: 0; overflow-y: auto; overflow-x: hidden; padding: 6px 0 14px; scrollbar-width: thin; scrollbar-color: #2c364d transparent; }
  .as-nav::-webkit-scrollbar { width: 6px; }
  .as-nav::-webkit-scrollbar-thumb { background: #2c364d; }
  .as-nav::-webkit-scrollbar-track { background: transparent; }
  .as-label { padding: 16px 20px 6px; color: var(--as-mute); font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; white-space: nowrap; }
  .as-group:first-child .as-label { padding-top: 8px; }
  .as-sep { height: 1px; margin: 8px 16px; background: var(--as-line); }
  .as-group:first-child .as-sep { display: none; }

  .as-row {
    position: relative; width: 100%; height: 40px; display: flex; align-items: center; gap: 12px; padding: 0 20px;
    border: 0; background: transparent; color: var(--as-mute); font-size: 13.5px; font-weight: 500; text-align: start;
    text-decoration: none; white-space: nowrap; cursor: pointer; transition: background-color .15s, color .15s;
  }
  .as:not(.is-wide) .as-row { justify-content: center; padding: 0; }
  .as-row:hover { background: var(--as-hover); color: var(--as-ink); }
  .as-row > .as-ico { position: relative; flex-shrink: 0; display: grid; place-items: center; }
  .as-row .as-ico .material-symbols-outlined { font-size: 20px; }
  .as-row .as-name { min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; }
  .as-row.is-current { color: var(--as-ink); font-weight: 600; }
  .as-row.is-current .as-ico { color: var(--as-accent); }
  .as-row.is-active, .as-subrow.is-active { background: var(--as-tint); color: var(--as-accent); font-weight: 600; }
  .as-row.is-active::before { content: ''; position: absolute; inset-block: 0; inset-inline-start: 0; width: 2px; background: var(--as-accent); }
  .as-chev { flex-shrink: 0; width: 16px; height: 16px; color: var(--as-mute); transition: transform .2s ease; }
  .as-chev.is-open { transform: rotate(180deg); }
  .as-row mark, .as-subrow mark { background: transparent; color: var(--as-accent); font-weight: 700; }

  /* SUB-ITEMS ON A GUIDE LINE UNDER THE PARENT'S ICON (ICON CENTRE = 20 + 10 PX) */
  .as-sub { position: relative; padding: 2px 0 6px; }
  .as-sub::before { content: ''; position: absolute; top: 0; bottom: 6px; inset-inline-start: 30px; width: 1px; background: var(--as-line); }
  .as-subrow {
    position: relative; width: 100%; height: 34px; display: flex; align-items: center; gap: 8px;
    padding-inline: 46px 20px; border: 0; background: transparent; color: var(--as-mute); font-size: 13px; font-weight: 500;
    text-align: start; text-decoration: none; white-space: nowrap; cursor: pointer; transition: background-color .15s, color .15s;
  }
  .as-subrow:hover { background: var(--as-hover); color: var(--as-ink); }
  .as-subrow .as-name { min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; }
  .as-subrow.is-active::before { content: ''; position: absolute; inset-block: 7px; inset-inline-start: 29px; width: 3px; background: var(--as-accent); }
  .as-subrow.is-open-parent { color: var(--as-ink); font-weight: 600; }
  .as-subrow.is-deep { padding-inline-start: 62px; height: 32px; font-size: 12.5px; }
  .as-subrow.is-deep.is-active::before { inset-inline-start: 45px; }
  .as-deep { position: relative; }
  .as-deep::before { content: ''; position: absolute; top: 0; bottom: 4px; inset-inline-start: 46px; width: 1px; background: var(--as-line); }
  .as-sub-sep { height: 1px; margin: 4px 20px 4px 46px; background: var(--as-line); }

  .as-badge { min-width: 20px; height: 20px; padding: 0 5px; display: inline-grid; place-items: center; flex-shrink: 0; background: var(--as-brand); color: #fff; font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .as-dot { position: absolute; top: -2px; inset-inline-end: -3px; width: 7px; height: 7px; background: var(--as-accent); box-shadow: 0 0 0 2px var(--as-surface); }

  /* ------------------------------------------------------------ FOOT (USER CARD) */
  .as-foot { position: relative; flex-shrink: 0; border-top: 1px solid var(--as-line); }
  .as-user { width: 100%; height: 60px; display: flex; align-items: center; gap: 10px; padding: 0 14px; border: 0; background: transparent; color: var(--as-ink); text-align: start; cursor: pointer; transition: background-color .15s; }
  .as:not(.is-wide) .as-user { justify-content: center; padding: 0; }
  .as-user:hover { background: var(--as-hover); }
  .as-avatar { width: 34px; height: 34px; flex-shrink: 0; display: grid; place-items: center; border-radius: 50%; background: var(--as-tint); color: var(--as-accent); font-size: 13px; font-weight: 800; letter-spacing: .02em; }
  .as-user-text { min-width: 0; flex: 1; line-height: 1.25; }
  .as-user-name { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; font-weight: 600; }
  .as-user-role { display: block; color: var(--as-mute); font-size: 10.5px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
  .as-menu { position: absolute; bottom: calc(100% + 6px); inset-inline: 10px; z-index: 110; min-width: 200px; padding: 4px 0; background: var(--as-surface); border: 1px solid var(--as-line); box-shadow: 0 18px 40px -12px rgba(0, 0, 0, .6); }
  .as-menu a { height: 40px; display: flex; align-items: center; gap: 10px; padding: 0 14px; color: var(--as-ink); font-size: 13.5px; text-decoration: none; transition: background-color .15s; }
  .as-menu a:hover { background: var(--as-hover); }
  .as-menu a .material-symbols-outlined { font-size: 18px; color: var(--as-mute); }
  .as-menu .is-danger, .as-menu .is-danger .material-symbols-outlined { color: #f87171; }
  .as-menu hr { margin: 4px 0; border: 0; border-top: 1px solid var(--as-line); }

  /* ------------------------------------------------------------ LOADER, PHONES */
  .as-spin { position: absolute; inset: 0; display: grid; place-items: center; }
  .as-fade { opacity: 0; transition: opacity .35s ease; }
  .as-fade.is-ready { opacity: 1; }
  .as-backdrop { position: fixed; inset: 0; z-index: 99; background: rgba(6, 9, 15, .55); }
  .as-burger { position: fixed; bottom: 20px; left: 20px; z-index: 98; width: 48px; height: 48px; display: none; place-items: center; border: 0; background: #0b0f19; color: #fff; cursor: pointer; border: 1px solid #232c40; box-shadow: 0 10px 24px -10px rgba(11, 18, 32, .45); }
  .as-burger:hover { background: #1a2134; }
  .as :focus-visible, .as-burger:focus-visible { outline: 2px solid var(--as-accent); outline-offset: -2px; }
  @media (max-width: 1023px) { .as, .as.is-wide { width: 280px; max-width: 86vw; } .as-burger { display: grid; } }
  @media (prefers-reduced-motion: reduce) { .as, .as-chev, .as-fade { transition: none; } }
</style>
