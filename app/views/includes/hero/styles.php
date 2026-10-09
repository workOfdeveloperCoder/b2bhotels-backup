<?php
// ============================================================================
// HERO / STYLES
// Plain CSS on purpose (no @apply): the hero is the first thing painted, before
// the Tailwind runtime has compiled anything.
//
// COMPONENT CLASSES
//   .hr                     the section (image, overlay, content)
//   .hr-bg / .hr-bg-layer   background picture: drifts, zooms and fades into itself
//   .hr-skel                skeleton shown while a form is not ready (.hr-card.is-loading)
//   .hr-title / .hr-sub     heading and one line under it
//   .hr-badges / .hr-badge  short selling points under the heading
//   .hr-tabs / .hr-tab      module tabs (.is-compact = icon and label on one line)
//   .hr-tab-ind             the white tab that slides behind the active one
//   .hr-tabs-bar            scroll bar above the tabs, when they do not all fit
//   .hr-ai                  glass button on the right of the tabs (AI Trip Planner)
//   .hr-card                white search card; follows the height of its form
//   .hr-panel               one search form inside the card (.is-active = shown)
//   .hr-shortcuts / .hr-shortcut   pill under the card: services that open their own page
//   .hr-soon                small "Soon" label
//   .hr-ico                 solid icon that moves when its shortcut is hovered
//   .hr-lottie              the same icon played from a Lottie file
//
// MOTION (one place to tune it)
//   --hr-ease         the curve used for fades and the card height
//   --hr-spring-*     spring curves (stiffness / damping) written as linear()
// Colours come from the active theme (--color-primary, --btn-*, --header-height).
// ============================================================================
@$SECURE or die('Access Denied!');
?>
<style>
  :root {
    --hr-ease: cubic-bezier(.25, .46, .45, .94);
    /* spring 400 / 25: tab icon */
    --hr-spring-icon: linear(0, 0.0577, 0.1953, 0.3684, 0.5455, 0.706, 0.8389, 0.9401, 1.0104, 1.0537, 1.0753, 1.0808, 1.0756, 1.0643, 1.0502, 1.0359, 1.0231, 1.0125, 1.0044, 0.9989, 0.9955, 0.9939, 0.9935, 0.9939, 0.9949, 0.996, 0.9972, 0.9982, 1);
    /* spring 350 / 30: corner of the card */
    --hr-spring-soft: linear(0, 0.0392, 0.1331, 0.2536, 0.3819, 0.5055, 0.6173, 0.7138, 0.7938, 0.8579, 0.9076, 0.9448, 0.9717, 0.9902, 1.0023, 1.0096, 1.0134, 1.0147, 1.0145, 1.0133, 1.0116, 1.0097, 1.0078, 1.0061, 1.0046, 1.0034, 1.0024, 1.0016, 1);
    --hr-tab-w: 100px;
    --hr-tab-h: 88px;
  }

  /* ------------------------------------------------------------ SECTION */
  .hr {
    position: relative; display: flex; flex-direction: column; align-items: center;
    z-index: 5;   /* a dropdown that is longer than the hero lies over the next section */
    min-height: 720px;
    padding: calc(var(--header-height, 64px) + clamp(28px, 6vh, 72px)) 0 36px;
    isolation: isolate;
  }
  /* the height is fixed from tablets up: forms of a different height fit inside it */
  @media (min-width: 640px) { .hr { min-height: 700px; } }
  @media (min-width: 768px) { .hr { min-height: 770px; padding-top: clamp(116px, 16vh, 160px); } }
  .hr-wrap { position: relative; z-index: 2; width: 100%; max-width: 80rem; margin: 0 auto; padding: 0 24px; }
  @media (max-width: 639px) { .hr-wrap { padding: 0 16px; } }

  /* ------------------------------------------------------------ BACKGROUND */
  .hr-bg { position: absolute; inset: 0; z-index: 0; overflow: hidden; background: #0f172a; }
  /* the picture keeps its own height (--hr-bg-h), so the hero can change size under it */
  .hr-bg-move {
    position: absolute; top: 0; left: 0; right: 0; height: var(--hr-bg-h, 100%); min-height: 100%;
    opacity: 0; transform: translate3d(0, var(--hr-par, 0px), 0);
    transition: opacity .9s ease;
    will-change: transform;
  }
  .hr-bg.is-ready .hr-bg-move { opacity: 1; }
  /* two layers of the same picture take turns: 16s of drift, then the other one fades in */
  .hr-bg-layer {
    position: absolute; top: 0; left: -1%; width: 102%; height: 100%; object-fit: cover;
    transform-origin: center; will-change: transform, opacity;
    animation: hr-life-move 32s linear infinite, hr-life-fade 32s linear infinite;
  }
  .hr-bg-layer.is-b { animation-delay: -16s, -16s; }
  @keyframes hr-life-move {
    0%        { transform: translateX(-3%) scale(1.05); }
    50%, 100% { transform: translateX(3%) scale(1.15); }
  }
  @keyframes hr-life-fade {
    0%       { opacity: 0; z-index: 2; animation-timing-function: ease-in-out; }
    4.6875%  { opacity: 1; z-index: 2; }
    4.7%     { opacity: 1; z-index: 1; }
    54.6875% { opacity: 1; z-index: 1; }
    54.7%    { opacity: 0; z-index: 1; }
    100%     { opacity: 0; z-index: 1; }
  }
  .hr-shade {
    position: absolute; inset: 0; z-index: 1; pointer-events: none;
    background: linear-gradient(to bottom, rgba(0, 0, 0, .42) 0%, rgba(0, 0, 0, .18) 38%, rgba(0, 0, 0, .34) 100%);
  }

  /* ------------------------------------------------------------ HEADING */
  .hr-head { will-change: transform; display: flex; flex-direction: column; align-items: center; text-align: center; margin-bottom: clamp(18px, 3.4vh, 32px); }
  .hr-title {
    max-width: 820px; margin: 0; color: #fff;
    font-size: clamp(24px, 6.6vw, 32px); font-weight: 800; line-height: 1.2; letter-spacing: -.6px;
    text-wrap: balance; text-shadow: 0 1px 12px rgba(0, 0, 0, .35);
    text-transform: capitalize;
  }
  @media (min-width: 768px) { .hr-title { font-size: clamp(32px, 3.3vw, 42px); letter-spacing: -1px; } }
  .hr-sub {
    max-width: 720px; margin: 8px 0 0; color: rgba(255, 255, 255, .88);
    font-size: clamp(13.5px, 1.15vw, 15.5px); line-height: 1.5; text-shadow: 0 1px 6px rgba(0, 0, 0, .35);
  }
  .hr-badges { display: flex; flex-wrap: wrap; justify-content: center; gap: 2px 8px; margin-top: 8px; }
  .hr-badge {
    display: inline-flex; align-items: center; gap: 10px; padding: 8px 10px;
    color: #fff; font-size: 14.5px; font-weight: 500; text-shadow: 0 1px 6px rgba(0, 0, 0, .4);
    text-transform: capitalize;
  }
  .hr-badge svg { width: 20px; height: 20px; flex-shrink: 0; }
  @media (max-width: 639px) { .hr-badge { font-size: 13px; padding: 4px 6px; gap: 6px; } .hr-badge svg { width: 18px; height: 18px; } }

  /* ------------------------------------------------------------ TABS */
  .hr-tabs-row {
    display: flex; align-items: flex-end; justify-content: space-between; gap: 16px;
  }
  .hr-tabs-box { position: relative; flex: 0 1 auto; min-width: 0; }
  /* scroll bar of the tabs: a thin line with a thumb, tall enough to take with a finger */
  .hr-tabs-bar {
    display: none; position: relative; align-items: center; height: 20px; margin: 0 2px 4px;
    cursor: pointer; touch-action: none; user-select: none; -webkit-user-select: none;
  }
  .hr-tabs-box.has-more .hr-tabs-bar { display: flex; }
  .hr-tabs-bar::before { content: ''; position: absolute; left: 0; right: 0; top: 50%; height: 4px; margin-top: -2px; border-radius: 4px; background: rgba(255, 255, 255, .28); }
  .hr-tabs-bar i { position: relative; display: block; width: 40%; height: 4px; border-radius: 4px; background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, .3); transition: height .15s ease; }
  .hr-tabs-bar:hover i, .hr-tabs-bar.is-drag i { height: 6px; }
  /* the last tab fades out while there is more to see on that side */
  .hr-tabs-box.has-more.more-end .hr-tabs { -webkit-mask-image: linear-gradient(to right, #000 calc(100% - 36px), transparent); mask-image: linear-gradient(to right, #000 calc(100% - 36px), transparent); }
  [dir="rtl"] .hr-tabs-box.has-more.more-end .hr-tabs { -webkit-mask-image: linear-gradient(to left, #000 calc(100% - 36px), transparent); mask-image: linear-gradient(to left, #000 calc(100% - 36px), transparent); }
  .hr-tabs {
    position: relative; display: flex; align-items: flex-end; min-width: 0;
    overflow-x: auto; overflow-y: hidden; scrollbar-width: none;
  }
  .hr-tabs::-webkit-scrollbar { display: none; }

  /* the white tab: one element that slides under the active tab */
  .hr-tab-ind {
    position: absolute; bottom: 0; inset-inline-start: 0; z-index: 0;
    width: var(--hr-tab-w); height: var(--hr-tab-h);
    border-radius: 12px 12px 0 0; background: #fff; pointer-events: none;
    transform: translateX(calc(var(--hr-tab-w) * var(--i, 0)));
    transition: transform .25s ease-out, opacity .15s ease;
  }
  [dir="rtl"] .hr-tab-ind { transform: translateX(calc(var(--hr-tab-w) * var(--i, 0) * -1)); }
  .hr-tab-ind.is-off { opacity: 0; }
  /* the two small curves at its foot */
  .hr-tab-ind i { position: absolute; bottom: 0; width: 12px; height: 12px; transition: opacity .15s ease; }
  .hr-tab-ind .c-start { left: -12px;  background: radial-gradient(circle at 0 0, transparent 11.5px, #fff 12px); }
  .hr-tab-ind .c-end   { right: -12px; background: radial-gradient(circle at 100% 0, transparent 11.5px, #fff 12px); }
  /* A corner that is off is also taken inside the tab. Left outside, it made the row of
     tabs wider than its tabs: with one module, or on the last tab, the row thought it
     could scroll and showed its scroll bar and the fade on its side. */
  .hr-tab-ind.no-start .c-start { opacity: 0; left: 0; }
  .hr-tab-ind.no-end .c-end     { opacity: 0; right: 0; }
  [dir="rtl"] .hr-tab-ind.no-start .c-start { opacity: 1; left: -12px; }
  [dir="rtl"] .hr-tab-ind.no-end .c-end     { opacity: 1; right: -12px; }
  [dir="rtl"] .hr-tab-ind.no-start .c-end   { opacity: 0; right: 0; }
  [dir="rtl"] .hr-tab-ind.no-end .c-start   { opacity: 0; left: 0; }

  .hr-tab {
    position: relative; z-index: 1; flex: 0 0 auto; width: var(--hr-tab-w); height: var(--hr-tab-h); padding: 0;
    border: 0; border-radius: 12px; background: transparent; color: #fff;
    font-family: inherit; cursor: pointer; outline: none;
    transition: color .2s ease;
  }
  .hr-tab-in {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
    width: 100%; height: 100%; padding: 8px; border-radius: 12px;
    transition: transform .2s ease, background-color .2s ease;
  }
  /* outline icon; the active one is filled and a little bigger (spring) */
  .hr-tab .material-symbols-outlined.hr-tab-icon {
    font-size: 38px !important; line-height: 40px; width: 40px; height: 40px;
    font-family: 'Material Symbols Outlined Thin', 'Material Symbols Outlined';
    font-weight: 200;   /* the thin cut loaded by heroTabFont() */
    font-variation-settings: 'FILL' 0, 'wght' 200, 'GRAD' 0, 'opsz' 40;
    text-shadow: 0 1px 8px rgba(0, 0, 0, .3);
    transition: transform .52s var(--hr-spring-icon);
  }
  .hr-tab-label { font-size: 16px; font-weight: 500; line-height: 24px; white-space: nowrap; text-shadow: 0 1px 6px rgba(0, 0, 0, .35); }
  .hr-tab:not(.is-active):hover .hr-tab-in { background: rgba(255, 255, 255, .1); transform: scale(.92); }
  .hr-tab:focus-visible .hr-tab-in { box-shadow: 0 0 0 2px #fff; }

  .hr-tab.is-active { color: var(--color-primary); }
  .hr-tab.is-active .material-symbols-outlined.hr-tab-icon {
    font-weight: 300;
    font-variation-settings: 'FILL' 1, 'wght' 300, 'GRAD' 0, 'opsz' 40;
    transform: scale(1.08);
  }
  .hr-tab.is-active .hr-tab-icon, .hr-tab.is-active .hr-tab-label { text-shadow: none !important; }

  @media (max-width: 767px) {
    :root { --hr-tab-w: 78px; --hr-tab-h: 72px; }
    .hr-tab-in { gap: 4px; }
    .hr-tab .material-symbols-outlined.hr-tab-icon { font-size: 28px !important; line-height: 30px; width: 30px; height: 30px; }
    .hr-tab-label { font-size: 12.5px; line-height: 18px; }
  }

  /* the white tab once script.php has measured the active tab: any width, any place */
  .hr-tabs.is-measured .hr-tab-ind, [dir="rtl"] .hr-tabs.is-measured .hr-tab-ind {
    inset-inline-start: auto; left: 0; width: var(--w, var(--hr-tab-w));
    transform: translateX(var(--x, 0px));
    transition: transform .25s ease-out, width .25s ease-out, opacity .15s ease;
  }

  /* ------------------------------------------------------------ TABS / COMPACT
     Icon and label on one line: the tabs are low and as wide as their label. */
  .hr-tabs.is-compact { --hr-tab-h: 54px; }
  .hr-tabs.is-compact .hr-tab { width: auto; padding: 0; }
  .hr-tabs.is-compact .hr-tab-in { flex-direction: row; gap: 8px; padding: 0 18px; }
  .hr-tabs.is-compact .hr-tab .material-symbols-outlined.hr-tab-icon {
    font-size: 26px !important; line-height: 26px; width: 26px; height: 26px;
  }
  .hr-tabs.is-compact .hr-tab.is-active .material-symbols-outlined.hr-tab-icon { transform: scale(1.06); }
  .hr-tabs.is-compact .hr-tab-label { font-size: 15.5px; line-height: 22px; }
  .hr-tabs.is-compact .hr-tab:not(.is-active):hover .hr-tab-in { transform: scale(.96); }
  /* until the active tab has been measured it carries the white itself */
  .hr-tabs.is-compact:not(.is-measured) .hr-tab-ind { opacity: 0; transition: none; }
  .hr-tabs.is-compact:not(.is-measured) .hr-tab.is-active { background: #fff; border-radius: 12px 12px 0 0; }
  @media (max-width: 767px) {
    .hr-tabs.is-compact { --hr-tab-h: 48px; }
    .hr-tabs.is-compact .hr-tab-in { gap: 6px; padding: 0 14px; }
    .hr-tabs.is-compact .hr-tab .material-symbols-outlined.hr-tab-icon { font-size: 22px !important; line-height: 22px; width: 22px; height: 22px; }
    .hr-tabs.is-compact .hr-tab-label { font-size: 13.5px; line-height: 20px; }
  }

  /* ------------------------------------------------------------ AI BUTTON */
  .hr-ai {
    position: relative; display: inline-flex; align-items: center; gap: 10px; flex-shrink: 0;
    margin-bottom: 12px; padding: 6px 18px 6px 6px; overflow: hidden;
    border: 1px solid rgba(255, 255, 255, .22); border-radius: 999px;
    background: rgba(255, 255, 255, .12); color: #fff;
    -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px);
    font-family: inherit; font-size: 14px; font-weight: 600; letter-spacing: -.01em; cursor: pointer;
    transition: background-color .25s ease, border-color .25s ease, transform .15s ease;
  }
  .hr-ai:hover, .hr-ai.is-active { background: rgba(255, 255, 255, .2); border-color: rgba(255, 255, 255, .4); }
  .hr-ai:active { transform: scale(.98); }
  .hr-ai-icon {
    position: relative; display: inline-flex; align-items: center; justify-content: center;
    width: 40px; height: 40px; border-radius: 50%; color: #fff;
    background: linear-gradient(135deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 70%, #000));
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, .4), inset 0 0 0 1px rgba(255, 255, 255, .25), 0 4px 12px rgba(0, 0, 0, .25);
  }
  .hr-ai-icon .material-symbols-outlined { font-size: 22px !important; }
  .hr-ai-arrow { display: inline-flex; opacity: .7; transition: transform .25s ease; }
  .hr-ai:hover .hr-ai-arrow { transform: translateX(3px); }
  [dir="rtl"] .hr-ai-arrow { transform: scaleX(-1); }
  .hr-ai-shine {
    position: absolute; inset: 0 -8px; pointer-events: none;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .28), transparent);
    transform: translateX(-130%) skewX(-18deg);
  }
  .hr-ai:hover .hr-ai-shine { animation: hr-shine .9s ease; }
  @keyframes hr-shine { to { transform: translateX(130%) skewX(-18deg); } }
  @media (max-width: 767px) {
    /* phones: the AI button sits above the tabs, so the tabs get the full width */
    .hr-tabs-row { flex-direction: column-reverse; align-items: stretch; gap: 12px; }
    .hr-ai { align-self: center; padding: 4px 14px 4px 4px; font-size: 13px; margin-bottom: 0; }
    .hr-ai-icon { width: 32px; height: 32px; }
    .hr-ai-arrow { display: none; }
  }

  /* ------------------------------------------------------------ CARD */
  .hr-card {
    position: relative; z-index: 20; border-radius: 24px; background: #fff; color: #262626;
    transition: height .25s var(--hr-ease), border-start-start-radius .46s var(--hr-spring-soft), border-start-end-radius .46s var(--hr-spring-soft);
  }
  /* the corner under the active tab is square when the tab sits on it (script.php placeTab) */
  .hr-card.is-first { border-start-start-radius: 0; }
  .hr-card.is-last  { border-start-end-radius: 0; }
  /* while the card moves to a new height nothing may stick out of it */
  .hr-card.is-switching { overflow: hidden; }

  /* forms lie on top of each other; the active one gives the card its height */
  .hr-panel {
    position: absolute; top: 0; inset-inline: 0; padding: 24px;
    opacity: 0; visibility: hidden; pointer-events: none;
    transform: scale(.98); filter: blur(4px);
    transition: opacity .25s var(--hr-ease), transform .25s var(--hr-ease), filter .25s var(--hr-ease), visibility 0s linear .25s;
  }
  .hr-panel.is-active {
    position: relative; opacity: 1; visibility: visible; pointer-events: auto;
    transform: none; filter: none;
    transition: opacity .25s var(--hr-ease), transform .25s var(--hr-ease), filter .25s var(--hr-ease), visibility 0s;
  }
  .hr-panel-ai { padding: 0; }
  @media (max-width: 767px) {
    .hr-card { border-radius: 20px; }
    .hr-panel { padding: 16px; }
    .hr-panel-ai { padding: 0; }
  }
  /* ------------------------------------------------------------ LOADING */
  /* the card shows a skeleton until its form can be seen as it should */
  .hr-card-skel { display: none; padding: 24px; }
  .hr-card.is-loading > .hr-card-skel { display: block; }
  .hr-card.is-loading { overflow: hidden; }
  .hr-card.is-loading > .hr-panel { position: absolute; opacity: 0; visibility: hidden; pointer-events: none; transition: none; }

  .hr-skel-top { display: flex; gap: 12px; margin-bottom: 16px; }
  .hr-skel-grid { display: grid; grid-template-columns: 1.2fr 1.2fr 1.6fr 1.1fr 150px; gap: 12px; }
  .hr-skel i {
    display: block; height: 67px; border-radius: 8px;
    background: linear-gradient(90deg, #f1f1f1 0%, #f1f1f1 35%, #e2e2e2 50%, #f1f1f1 65%, #f1f1f1 100%);
    background-size: 300% 100%;
    animation: hr-shimmer 1.3s linear infinite;
  }
  .hr-skel i.is-pill { width: 330px; max-width: 60%; height: 46px; border-radius: 12px; }
  .hr-skel i.is-pill.is-short { width: 118px; }
  .hr-skel i.is-btn { background: color-mix(in srgb, var(--color-primary) 22%, #f1f1f1); animation: none; }
  @keyframes hr-shimmer { from { background-position: 100% 0; } to { background-position: 0 0; } }
  [dir="rtl"] .hr-skel i { animation-direction: reverse; }
  @media (max-width: 1023px) {
    .hr-skel-grid { grid-template-columns: 1fr 1fr; }
    .hr-skel-grid .is-btn { grid-column: 1 / -1; }
  }
  @media (max-width: 767px) {
    .hr-card-skel { padding: 16px; }
    .hr-skel-top { flex-direction: column; }
    .hr-skel i.is-pill, .hr-skel i.is-pill.is-short { width: 100%; max-width: none; }
    .hr-skel-grid { grid-template-columns: 1fr; }
  }

  /* icons are letters until their font has loaded: keep them out of sight until then
     (they show by themselves after 6s, should the script never run) */
  .hr-wrap .hr-tab-icon, .hr-wrap .hr-ai-icon .material-symbols-outlined { opacity: 0; transition: opacity .25s ease; animation: hr-show 0s linear 6s forwards; }
  .hr-wrap.is-ready .hr-tab-icon, .hr-wrap.is-ready .hr-ai-icon .material-symbols-outlined { opacity: 1; animation: none; }
  @keyframes hr-show { to { opacity: 1; } }

  /* ------------------------------------------------------------ SHORTCUTS */
  .hr-shortcuts-row { position: relative; z-index: 1; display: flex; justify-content: center; margin-top: clamp(20px, 4vh, 36px); }
  .hr-shortcuts {
    display: inline-flex; flex-wrap: wrap; align-items: center; justify-content: center; max-width: 100%;
    padding: 8px; border-radius: 999px; background: rgba(250, 250, 250, .8); color: #404040;
    -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px);
  }
  .hr-shortcut {
    display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px;
    border: 0; border-radius: 999px; background: transparent; color: inherit;
    font-family: inherit; font-size: 16px; font-weight: 500; line-height: 24px; white-space: nowrap;
    text-decoration: none; cursor: pointer; outline: none; transition: background-color .15s ease;
  }
  .hr-shortcut:hover { background: rgba(38, 38, 38, .1); }
  .hr-shortcut:focus-visible { box-shadow: 0 0 0 2px var(--color-primary); }
  .hr-soon {
    display: inline-flex; align-items: center; padding: 3px 7px; border-radius: 999px;
    background: #262626; color: #fff; font-size: 10px; font-weight: 600; line-height: 1; letter-spacing: .02em;
  }
  @media (max-width: 767px) {
    .hr-shortcuts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px; width: 100%; border-radius: 20px; background: rgba(250, 250, 250, .92); }
    .hr-shortcut { justify-content: flex-start; padding: 10px 12px; font-size: 14px; border-radius: 14px; }
  }

  /* ------------------------------------------------------------ ANIMATED ICONS */
  .hr-ico { width: 24px; height: 24px; flex-shrink: 0; overflow: visible; }
  .hr-ico * { transform-box: fill-box; transform-origin: center; }
  .hr-lottie, .hr-lottie-box { display: inline-flex; width: 24px; height: 24px; flex-shrink: 0; }
  .hr-lottie svg { width: 100% !important; height: 100% !important; }
  .hr-lottie.is-ready path { fill: currentColor; stroke: currentColor; }

  /* suitcase: it hops, the handle pulls out and goes back */
  .hr-ico-bag .i-body { transform-origin: 50% 100%; }
  .hr-shortcut:hover .hr-ico-bag { animation: hr-bag-hop .9s var(--hr-ease) both; }
  .hr-shortcut:hover .hr-ico-bag .i-handle { animation: hr-bag-handle .9s var(--hr-ease) both; }
  .hr-shortcut:hover .hr-ico-bag .i-body   { animation: hr-bag-squash .9s var(--hr-ease) both; }
  @keyframes hr-bag-hop    { 0%, 100% { transform: translateY(0) rotate(0); } 30% { transform: translateY(-2.5px) rotate(-8deg); } 55% { transform: translateY(0) rotate(5deg); } 78% { transform: translateY(-.8px) rotate(-2deg); } }
  @keyframes hr-bag-handle { 0%, 100% { transform: translateY(0); } 30%, 50% { transform: translateY(-1.8px); } }
  @keyframes hr-bag-squash { 0%, 100% { transform: scale(1, 1); } 58% { transform: scale(1.05, .94); } 78% { transform: scale(.98, 1.03); } }

  /* globe: it turns, the meridians sweep across */
  .hr-ico-globe .i-axis { opacity: 0; }
  .hr-shortcut:hover .hr-ico-globe .i-meridian { animation: hr-globe-spin 1.1s ease-in-out both; }
  .hr-shortcut:hover .hr-ico-globe .i-axis     { animation: hr-globe-axis 1.1s ease-in-out both; }
  .hr-shortcut:hover .hr-ico-globe .i-ring     { animation: hr-pop .7s var(--hr-ease) both; }
  @keyframes hr-globe-spin { 0%, 100% { transform: scaleX(1); } 25% { transform: scaleX(0); } 50% { transform: scaleX(1); } 75% { transform: scaleX(0); } }
  @keyframes hr-globe-axis { 0%, 12%, 38%, 62%, 88%, 100% { opacity: 0; } 25%, 75% { opacity: 1; } }

  /* shield: it swells and the tick is drawn again */
  .hr-ico-shield .i-tick { stroke-dasharray: 1; stroke-dashoffset: 0; }
  .hr-shortcut:hover .hr-ico-shield .i-shield { animation: hr-shield .8s var(--hr-ease) both; }
  .hr-shortcut:hover .hr-ico-shield .i-tick   { animation: hr-draw .55s ease .2s both; }
  @keyframes hr-shield { 0%, 100% { transform: scale(1) rotate(0); } 30% { transform: scale(.9) rotate(-6deg); } 60% { transform: scale(1.12) rotate(3deg); } }
  @keyframes hr-draw { from { stroke-dashoffset: 1; } to { stroke-dashoffset: 0; } }

  /* eSIM: the corners close in and a line scans the code */
  .hr-ico-esim .i-scan { opacity: 0; }
  .hr-shortcut:hover .hr-ico-esim .i-corners { animation: hr-pinch .9s var(--hr-ease) both; }
  .hr-shortcut:hover .hr-ico-esim .i-chip    { animation: hr-blink .9s ease both; }
  .hr-shortcut:hover .hr-ico-esim .i-scan    { animation: hr-scan .9s ease-in-out both; }
  @keyframes hr-pinch { 0%, 100% { transform: scale(1); } 35% { transform: scale(.84); } 70% { transform: scale(1.06); } }
  @keyframes hr-blink { 0%, 100% { opacity: 1; } 30%, 60% { opacity: .45; } }
  @keyframes hr-scan  { 0% { opacity: 0; transform: translateY(-6px); } 15% { opacity: 1; } 50% { transform: translateY(6px); } 85% { opacity: 1; } 100% { opacity: 0; transform: translateY(-6px); } }

  @keyframes hr-pop { 0%, 100% { transform: scale(1); } 40% { transform: scale(1.12); } }

  /* ------------------------------------------------------------ ENTRANCE
     The hero rises in one soft movement, part after part: heading, tabs, card,
     shortcuts. Only "from" is written, so once it is over every part is back on
     its own values (no transform stays behind to catch fixed popups). */
  .hr-head, .hr-tabs-row, .hr-card, .hr-shortcuts-row {
    animation: hr-enter .6s cubic-bezier(.16, 1, .3, 1) both;
  }
  .hr-tabs-row      { animation-delay: .07s; }
  .hr-card          { animation-delay: .13s; }
  .hr-shortcuts-row { animation-delay: .2s; }
  @keyframes hr-enter { from { opacity: 0; transform: translateY(22px); } }

  @media (prefers-reduced-motion: reduce) {
    .hr-bg-layer { animation: none; }
    .hr-bg-layer.is-b { display: none; }
    .hr *, .hr *::before, .hr *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
  }
</style>
