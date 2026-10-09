<?php
// ============================================================================
// FEATURED / MODAL STYLES
// A window over the page for a list to choose from (the plans of a country).
// It comes in with a spring; on phones it is a sheet that rises from the foot
// of the screen. Plain CSS, like the rest of the featured components.
//
// COMPONENT CLASSES
//   .fm                     the dark layer over the page (add .is-open to show)
//   .fm-box                 the window
//   .fm-head / .fm-close    top row: tile, title, line under it, close button
//   .fm-tools               rows under the head (a .segmented switch, .fm-chips)
//   .fm-chips / .fm-pick    a row of small choices; .is-active is the chosen one
//   .fm-body                the part that scrolls
//   .fm-row                 one thing to choose: facts on one side, price and button on the other
//   .fm-note                message in the body (nothing found, an error)
//   .fm-foot                bottom row
// ============================================================================
@$SECURE or die('Access Denied!');

if (!empty($GLOBALS['__featuredModal'])) {
    return;
}
$GLOBALS['__featuredModal'] = true;
?>
<style>
  .fm {
    position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 24px;
    background: rgba(10, 10, 20, .5); -webkit-backdrop-filter: blur(3px); backdrop-filter: blur(3px);
    opacity: 0; visibility: hidden; transition: opacity .2s ease, visibility 0s linear .2s;
  }
  .fm.is-open { opacity: 1; visibility: visible; transition: opacity .2s ease, visibility 0s; }

  .fm-box {
    display: flex; flex-direction: column; width: 100%; max-width: 680px; max-height: min(86vh, 760px); overflow: hidden;
    border-radius: 24px; background: #fff; color: #171717; box-shadow: 0 30px 80px -20px rgba(10, 10, 20, .5);
    opacity: 0; transform: translateY(20px) scale(.96);
    transition: opacity .15s ease-out, transform .15s ease-out;
  }
  .fm.is-open .fm-box {
    opacity: 1; transform: none;
    transition: opacity .2s ease, transform .45s var(--spring-pop, cubic-bezier(.22, 1, .36, 1));
  }

  .fm-head { display: flex; align-items: center; gap: 14px; flex-shrink: 0; padding: 20px 20px 16px; }
  .fm-head .fc-tile { width: 48px; height: 48px; }
  .fm-head .fc-tile img { width: 30px; height: 30px; }
  .fm-title { margin: 0; font-size: 20px; font-weight: 700; line-height: 1.2; letter-spacing: .09px; }
  .fm-sub { display: flex; align-items: center; gap: 10px; margin-top: 5px; color: #737373; font-size: 13px; line-height: 1; }
  .fm-close {
    display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; margin-inline-start: auto; flex-shrink: 0; padding: 0;
    border: 1px solid #e5e5e5; border-radius: 50%; background: #fff; color: #525252; cursor: pointer;
    transition: border-color .2s ease, color .2s ease, transform .15s ease;
  }
  .fm-close svg { width: 18px; height: 18px; }
  .fm-close:hover { border-color: var(--color-primary); color: var(--color-primary); }
  .fm-close:active { transform: scale(.94); }

  .fm-tools { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; flex-shrink: 0; padding: 0 20px 14px; }
  /* a row of small choices (validity of a plan, region of a list) */
  .fm-chips { display: flex; flex-wrap: wrap; gap: 6px; max-width: 100%; }
  .fm-pick {
    display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; border: 1px solid #e5e5e5; border-radius: 999px;
    background: #fff; color: #737373; font-family: inherit; font-size: 13px; font-weight: 600; line-height: 18px; white-space: nowrap; cursor: pointer;
    transition: border-color .2s ease, color .2s ease, background-color .2s ease;
  }
  .fm-pick:hover { border-color: color-mix(in srgb, var(--color-primary) 40%, transparent); color: var(--color-primary); }
  .fm-pick.is-active { border-color: var(--color-primary); background: var(--color-primary); color: #fff; }
  .fm-pick i { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 999px; background: #f5f5f5; font-size: 10px; font-style: normal; font-weight: 800; }
  .fm-pick.is-active i { background: rgba(255, 255, 255, .25); }

  .fm-body { flex: 1 1 auto; min-height: 120px; overflow-y: auto; overscroll-behavior: contain; padding: 4px 20px 20px; border-top: 1px solid rgba(229, 229, 229, .7); }
  .fm-group { margin: 16px 0 8px; color: #737373; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }

  .fm-row {
    display: flex; align-items: center; gap: 12px; padding: 12px 12px 12px 16px; margin-top: 8px;
    border: 1px solid #e5e5e5; border-radius: 16px; background: #fff; color: inherit; text-decoration: none;
    transition: border-color .25s ease, background-color .25s ease;
    animation: fm-row .5s var(--spring-row, cubic-bezier(.22, 1, .36, 1)) both; animation-delay: calc(min(var(--n, 0), 10) * 35ms);
  }
  @keyframes fm-row { from { opacity: 0; transform: translateY(12px) scale(.98); } }
  .fm-row:hover { border-color: var(--color-primary); background: color-mix(in srgb, var(--color-primary) 3%, #fff); }
  .fm-row-main { flex: 1 1 auto; min-width: 0; }
  .fm-row-title { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 8px; font-size: 16px; font-weight: 700; line-height: 1.25; }
  .fm-row-title span { color: #737373; font-size: 13px; font-weight: 500; }
  .fm-row-facts { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 7px; }
  .fm-row-price { flex-shrink: 0; text-align: end; }
  .fm-row-price .fc-price b { font-size: 18px; }
  .fm-row .btn { flex-shrink: 0; }

  .fm-note { padding: 36px 16px; color: #737373; font-size: 14px; line-height: 1.5; text-align: center; }
  .fm-note .btn { margin-top: 14px; }

  .fm-skel { display: block; height: 70px; margin-top: 8px; border-radius: 16px;
    background: linear-gradient(90deg, #f1f1f1 0%, #f1f1f1 35%, #e4e4e4 50%, #f1f1f1 65%, #f1f1f1 100%);
    background-size: 300% 100%; animation: fc-shimmer 1.3s linear infinite; }

  .fm-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-shrink: 0; padding: 12px 20px; border-top: 1px solid rgba(229, 229, 229, .7); color: #737373; font-size: 12.5px; }
  .fm-foot a { color: var(--color-primary); font-weight: 600; text-decoration: none; }
  .fm-foot a:hover { opacity: .75; }

  /* phones: a sheet that rises from the foot of the screen */
  @media (max-width: 639px) {
    .fm { align-items: flex-end; padding: 0; }
    .fm-box { max-width: none; max-height: 92vh; border-radius: 24px 24px 0 0; transform: translateY(100%); opacity: 1; transition: transform .2s ease-in; }
    .fm.is-open .fm-box { transition: transform .34s var(--spring-sheet, cubic-bezier(.22, 1, .36, 1)); }
    .fm-head { padding: 16px 16px 12px; }
    .fm-tools { padding: 0 16px 12px; }
    .fm-chips { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; }
    .fm-chips::-webkit-scrollbar { display: none; }
    .fm-body { padding: 0 16px 16px; }
    .fm-foot { padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); }
    .fm-row { flex-wrap: wrap; padding: 12px; }
    .fm-row-main { flex-basis: 100%; }
    .fm-row-price { text-align: start; margin-inline-end: auto; }
  }

  @media (prefers-reduced-motion: reduce) {
    .fm, .fm-box, .fm-row, .fm-skel { transition: none !important; animation: none !important; }
  }
</style>
