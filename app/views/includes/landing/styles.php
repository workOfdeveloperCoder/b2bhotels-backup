<?php
// ============================================================================
// LANDING / STYLES
// The page of a service (eSIM ...): white cards on a light page. A top card
// with the heading, two numbers and a search box; a catalog card with a row of
// choices and a grid of destinations; a card with steps; a card with reasons.
// Plain CSS (no @apply), so the page looks right before the Tailwind runtime
// has compiled anything.
//
// COMPONENT CLASSES
//   .ld                       the page (light background, cards under each other)
//   .ld-card                  a white card with a border and round corners
//   .ld-hero                  the top card
//   .ld-badge                 small label above the heading
//   .ld-h1 / .ld-lead         heading and the lines under it
//   .ld-stats / .ld-stat      numbers beside the heading
//   .ld-rule                  thin line between parts of a card
//   .ld-label                 small grey heading of a part
//   .ld-search                search box: icon, input, button (.btn)
//   .ld-suggest               list under the search box while typing
//   .ld-note                  small grey line with an icon
//   .ld-head                  head of a card: title, line under it, things on the far side
//   .ld-body                  the part of a card under its head
//   .ld-grid                  grid of destinations (4 / 3 / 2 / 1 columns)
//   .ld-dest                  one destination: picture, flag, name, price, three facts
//                             (.is-hit = the one the search box found)
//   .ld-more                  row with the "show more" button
//   .ld-empty                 message when nothing is found
//   .ld-steps / .ld-step      numbered steps
//   .ld-why / .ld-reason      reasons, each with an icon (is-blue, is-green, is-amber)
// Rows of choices use .fm-chips / .fm-pick (app/views/includes/featured/modal.php).
// Colours come from the theme (--color-primary).
// ============================================================================
@$SECURE or die('Access Denied!');

if (!empty($GLOBALS['__landingStyles'])) {
    return;
}
$GLOBALS['__landingStyles'] = true;
?>
<style>
  .ld { padding: 24px 0 64px; background: #fafafa; color: #171717; }
  .ld > .container { display: flex; flex-direction: column; gap: 16px; }
  .ld-card { border: 1px solid #e5e5e5; border-radius: 24px; background: #fff; }
  .ld-card.is-clip { overflow: hidden; }

  /* ------------------------------------------------------------ TOP CARD */
  .ld-hero { padding: 32px; box-shadow: 0 1px 2px rgba(0, 0, 0, .05); animation: ld-in .5s cubic-bezier(.22, 1, .36, 1) both; }
  @keyframes ld-in { from { opacity: 0; transform: translateY(12px); } }
  .ld-hero-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 48px; }
  .ld-badge {
    display: inline-flex; align-items: center; gap: 7px; padding: 4px 12px;
    border: 1px solid color-mix(in srgb, var(--color-primary) 22%, transparent); border-radius: 999px;
    background: color-mix(in srgb, var(--color-primary) 8%, #fff); color: var(--color-primary);
    font-size: 12px; font-weight: 700; line-height: 18px; letter-spacing: .025em; text-transform: uppercase;
  }
  .ld-badge svg { width: 12px; height: 12px; }
  .ld-h1 { margin: 16px 0 0; font-size: 32px; font-weight: 800; line-height: 1.15; letter-spacing: -.025em; text-wrap: balance; }
  .ld-lead { max-width: 520px; margin: 8px 0 0; color: #737373; font-size: 14px; font-weight: 500; line-height: 1.6; }

  .ld-stats { display: flex; align-items: center; flex-shrink: 0; }
  .ld-stat { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 0 28px; text-align: center; }
  .ld-stat + .ld-stat { border-inline-start: 1px solid #e5e5e5; }
  .ld-stat b { display: flex; align-items: center; height: 26px; font-size: 22px; font-weight: 800; line-height: 1; letter-spacing: -.025em; }
  .ld-stat span { margin-top: 4px; color: #a3a3a3; font-size: 12px; font-weight: 500; }

  .ld-rule { height: 1px; margin: 24px 0; background: #e5e5e5; }
  .ld-label { margin: 0 0 10px; color: #a3a3a3; font-size: 12px; font-weight: 700; letter-spacing: .025em; text-transform: uppercase; }

  .ld-search-wrap { position: relative; }
  .ld-search {
    display: flex; align-items: center; gap: 4px; padding: 6px; padding-inline-start: 16px;
    border: 1px solid #e5e5e5; border-radius: 16px; background: #fafafa;
    transition: border-color .2s ease, box-shadow .2s ease;
  }
  .ld-search:focus-within { border-color: var(--color-primary); box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 10%, transparent); }
  .ld-search-field { display: flex; align-items: center; gap: 8px; flex: 1 1 auto; min-width: 0; padding-inline-start: 8px; }
  .ld-search-field svg { width: 16px; height: 16px; flex-shrink: 0; color: #a3a3a3; }
  .ld-search input {
    width: 100%; padding: 8px 0; border: 0 !important; outline: 0 !important; box-shadow: none !important; background: transparent;
    color: #171717; font-family: inherit; font-size: 14px; font-weight: 500;
  }
  .ld-search input::placeholder { color: #a3a3a3; font-weight: 400; }
  .ld-search .btn { flex-shrink: 0; }
  .ld-search .btn svg { width: 14px; height: 14px; }

  .ld-suggest {
    position: absolute; top: 100%; inset-inline: 0; z-index: 30; margin-top: 8px; padding: 8px; max-height: 320px; overflow-y: auto;
    border-radius: 16px; background: #fff; box-shadow: 0 10px 15px -3px rgb(0 0 0 / .1), 0 4px 6px -4px rgb(0 0 0 / .1), 0 0 0 1px rgb(0 0 0 / .05);
  }
  .ld-suggest-row { display: flex; align-items: center; gap: 12px; width: 100%; padding: 9px 12px; border: 0; border-radius: 10px; background: transparent; color: inherit; font-family: inherit; text-align: start; cursor: pointer; transition: background-color .15s ease; }
  .ld-suggest-row:hover, .ld-suggest-row:focus-visible { background: #f5f5f5; outline: none; }
  .ld-suggest-row img { width: 26px; height: 26px; border-radius: 50%; box-shadow: 0 0 0 1px rgba(0, 0, 0, .06); }
  .ld-suggest-row b { flex: 1 1 auto; min-width: 0; font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ld-suggest-row span { color: #737373; font-size: 12.5px; white-space: nowrap; }

  .ld-note { display: flex; align-items: center; gap: 5px; margin: 10px 0 0; color: #a3a3a3; font-size: 12px; font-weight: 500; }
  .ld-note svg { width: 12px; height: 12px; flex-shrink: 0; }

  /* ------------------------------------------------------------ HEAD AND BODY OF A CARD */
  .ld-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 20px 28px; border-bottom: 1px solid #e5e5e5; }
  .ld-head h2 { margin: 0; font-size: 18px; font-weight: 800; line-height: 1.3; letter-spacing: -.025em; }
  .ld-head.is-large { padding: 24px 32px; }
  .ld-head.is-large h2 { font-size: 20px; }
  .ld-head p { margin: 2px 0 0; color: #a3a3a3; font-size: 13px; font-weight: 500; }
  .ld-body { padding: 28px; }

  /* ------------------------------------------------------------ DESTINATIONS */
  .ld-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
  .ld-dest {
    display: flex; flex-direction: column; overflow: hidden; border: 1px solid #e5e5e5; border-radius: 20px; background: #fff;
    color: inherit; cursor: pointer; outline: none;
    transition: border-color .3s ease, box-shadow .3s ease, transform .3s ease;
    animation: ld-in .45s cubic-bezier(.22, 1, .36, 1) both; animation-delay: calc(min(var(--n, 0), 12) * 35ms);
  }
  .ld-dest:hover, .ld-dest:focus-visible {
    border-color: color-mix(in srgb, var(--color-primary) 40%, transparent);
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / .1), 0 2px 4px -2px rgb(0 0 0 / .1); transform: translateY(-3px);
  }
  /* the destination the search box found */
  .ld-dest.is-hit {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-primary) 16%, transparent), 0 10px 30px -14px color-mix(in srgb, var(--color-primary) 60%, transparent);
    animation: ld-in .45s cubic-bezier(.22, 1, .36, 1) both, ld-hit 1.400s ease-out .2s 2;
  }
  @keyframes ld-hit {
    0%   { box-shadow: 0 0 0 0 color-mix(in srgb, var(--color-primary) 45%, transparent), 0 10px 30px -14px color-mix(in srgb, var(--color-primary) 60%, transparent); }
    100% { box-shadow: 0 0 0 14px color-mix(in srgb, var(--color-primary) 0%, transparent), 0 10px 30px -14px color-mix(in srgb, var(--color-primary) 60%, transparent); }
  }
  .ld-dest-pic { padding: 8px 8px 0; }
  .ld-dest-pic > div {
    position: relative; height: 148px; overflow: hidden; border-radius: 12px;
    background: linear-gradient(135deg, color-mix(in srgb, var(--color-primary) 16%, #fff), color-mix(in srgb, var(--color-primary) 4%, #fff));
  }
  /* the pictures of the supplier are small: one copy fills the box, blurred, and a sharp copy lies on it */
  .ld-dest-pic img.is-pic { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; filter: blur(18px) saturate(1.25); transform: scale(1.35); opacity: .85; }
  .ld-dest-pic img.is-card {
    position: absolute; top: 50%; left: 50%; height: 104px; width: auto; border-radius: 10px;
    box-shadow: 0 10px 24px -8px rgba(10, 10, 20, .45); transform: translate(-50%, -50%);
    transition: transform .5s cubic-bezier(.22, 1, .36, 1);
  }
  .ld-dest:hover .ld-dest-pic img.is-card { transform: translate(-50%, -50%) scale(1.06) rotate(-1.5deg); }
  .ld-dest-tag {
    position: absolute; top: 10px; inset-inline-start: 10px; padding: 4px 10px; border-radius: 999px;
    background: color-mix(in srgb, var(--color-primary) 90%, transparent); color: #fff; font-size: 11px; font-weight: 700; line-height: 16px;
    -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px);
  }
  .ld-dest-main { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 18px 18px 14px; }
  .ld-dest-name { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .ld-dest-name img { width: 40px; height: 40px; flex-shrink: 0; border-radius: 50%; box-shadow: 0 0 0 1px #e5e5e5; background: #f5f5f5; }
  .ld-dest-name b { min-width: 0; font-size: 16px; font-weight: 800; letter-spacing: -.025em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ld-dest-price { display: flex; flex-direction: column; align-items: flex-end; flex-shrink: 0; text-align: end; }
  .ld-dest-price > span { color: #737373; font-size: 10px; font-weight: 500; line-height: 16px; }
  .ld-dest-price .fc-price b { font-size: 22px; }
  .ld-dest-facts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: auto; padding: 18px; border-top: 1px solid #e5e5e5; }
  .ld-dest-facts > div { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
  .ld-dest-facts > div + div { padding-inline-start: 12px; border-inline-start: 1px solid #e5e5e5; }
  .ld-dest-facts span { color: #a3a3a3; font-size: 10px; font-weight: 700; letter-spacing: .025em; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ld-dest-facts b { font-size: 13px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ld-dest-line { display: none; color: #737373; font-size: 12px; font-weight: 500; }

  .ld-dest.is-skel { animation: none; cursor: default; pointer-events: none; }
  .ld-dest.is-skel i {
    display: block; border-radius: 8px;
    background: linear-gradient(90deg, #f1f1f1 0%, #f1f1f1 35%, #e4e4e4 50%, #f1f1f1 65%, #f1f1f1 100%);
    background-size: 300% 100%; animation: fc-shimmer 1.3s linear infinite;
  }

  .ld-more { display: flex; justify-content: center; margin-top: 24px; }
  .ld-btn-icon { width: 16px; height: 16px; flex-shrink: 0; }
  .ld-empty { padding: 48px 16px; color: #737373; font-size: 14px; text-align: center; }
  .ld-empty svg { display: block; width: 36px; height: 36px; margin: 0 auto 12px; color: #a3a3a3; }

  /* ------------------------------------------------------------ STEPS AND REASONS */
  .ld-steps, .ld-why { display: grid; gap: 1px; background: #e5e5e5; }
  .ld-steps { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .ld-why { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .ld-step { display: flex; align-items: flex-start; gap: 16px; padding: 26px; background: #fff; }
  .ld-step > i {
    display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; flex-shrink: 0;
    border: 1px solid color-mix(in srgb, var(--color-primary) 22%, transparent); border-radius: 11px;
    background: color-mix(in srgb, var(--color-primary) 8%, #fff); color: var(--color-primary);
    font-size: 14px; font-style: normal; font-weight: 800;
  }
  .ld-step b, .ld-reason b { display: block; font-size: 14px; font-weight: 800; letter-spacing: -.025em; }
  .ld-step p, .ld-reason p { margin: 5px 0 0; color: #737373; font-size: 13px; font-weight: 500; line-height: 1.6; }
  .ld-reason { display: flex; flex-direction: column; align-items: center; gap: 16px; padding: 28px; background: #fff; text-align: center; }
  .ld-reason > i {
    --tint: var(--color-primary);
    display: flex; align-items: center; justify-content: center; width: 64px; height: 64px;
    border: 1px solid color-mix(in srgb, var(--tint) 22%, transparent); border-radius: 18px;
    background: color-mix(in srgb, var(--tint) 8%, #fff); color: var(--tint);
    transition: transform .4s cubic-bezier(.22, 1, .36, 1);
  }
  .ld-reason:hover > i { transform: translateY(-3px) scale(1.05); }
  .ld-reason > i svg { width: 28px; height: 28px; }
  .ld-reason.is-blue > i { --tint: #0284c7; }
  .ld-reason.is-green > i { --tint: #16a34a; }
  .ld-reason.is-amber > i { --tint: #d97706; }

  /* ------------------------------------------------------------ SMALLER SCREENS */
  @media (max-width: 1279px) { .ld-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
  @media (max-width: 1023px) {
    .ld-hero-top { flex-direction: column; gap: 20px; }
    .ld-stats { width: 100%; padding-top: 20px; border-top: 1px solid #e5e5e5; }
    .ld-stat { flex: 1 1 0; padding: 0 6px; }
    .ld-head { flex-direction: column; align-items: flex-start; }
    .ld-head .fm-chips { flex-wrap: nowrap; max-width: 100%; overflow-x: auto; scrollbar-width: none; }
    .ld-head .fm-chips::-webkit-scrollbar { display: none; }
    .ld-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ld-why { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (max-width: 767px) {
    .ld-card { border-radius: 20px; }
    .ld-hero { padding: 16px; }
    .ld-h1 { font-size: 26px; }
    .ld-stat b { height: 22px; font-size: 19px; }
    .ld-stat span { font-size: 11px; }
    .ld-rule { margin: 16px 0; }
    .ld-head, .ld-head.is-large { padding: 18px 20px; }
    .ld-steps { grid-template-columns: 1fr; }
  }
  @media (max-width: 639px) {
    .ld-search { flex-direction: column; align-items: stretch; gap: 10px; padding: 8px; }
    .ld-search-field { padding: 0 12px; }
    .ld-search input { padding: 10px 0; font-size: 16px; }
    .ld-search .btn { width: 100%; }
    .ld-body { padding: 12px; }
    .ld-grid { grid-template-columns: 1fr; gap: 10px; }
    /* a destination becomes a row: picture, name and facts, price */
    .ld-dest { flex-direction: row; align-items: center; gap: 12px; padding: 12px; border-radius: 16px; }
    .ld-dest:hover { transform: none; }
    .ld-dest-pic { width: 96px; flex-shrink: 0; padding: 0; }
    .ld-dest-pic > div { height: 64px; border-radius: 8px; }
    .ld-dest-pic img.is-card { height: 52px; border-radius: 6px; }
    .ld-dest-tag, .ld-dest-facts { display: none; }
    .ld-dest-main { flex: 1 1 auto; min-width: 0; padding: 0; }
    .ld-dest-name { flex-direction: column; align-items: flex-start; gap: 4px; }
    .ld-dest-name > div { display: flex; align-items: center; gap: 7px; min-width: 0; max-width: 100%; }
    .ld-dest-name img { width: 22px; height: 22px; }
    .ld-dest-name b { font-size: 15px; white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; line-height: 1.25; }
    .ld-dest-line { display: block; }
    .ld-why { grid-template-columns: 1fr; }
  }

  @media (prefers-reduced-motion: reduce) {
    .ld-hero, .ld-dest, .ld-dest.is-hit, .ld-dest.is-skel i { animation: none !important; }
    .ld-dest, .ld-reason > i, .ld-dest-pic img.is-card { transition: none !important; }
  }
</style>
