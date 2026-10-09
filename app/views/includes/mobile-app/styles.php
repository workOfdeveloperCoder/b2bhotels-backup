<?php
// ============================================================================
// MOBILE APP / STYLES
// The "get the app" section of the home page: one white card with three parts —
// the words and the store badges, two phones drawn in CSS, three numbered
// reasons. Plain CSS (no @apply); colours come from the theme.
//
// COMPONENT CLASSES
//   .ma                    the card; .ma-glow is the tinted light behind the phones; .ma-grid the three parts
//   .ma-eyebrow            small line above the heading ("— MOBILE APP")
//   .ma-title              heading; <em> inside it takes the theme colour
//   .ma-lead               paragraph
//   .ma-stores / .ma-store the two black store badges
//   .ma-phones / .ma-phone the phones; .ma-phone.is-back is the one behind
//   .ma-screen             what the phone shows: .ma-bar, .ma-hello, .ma-search, .ma-tiles,
//                          .ma-deal, .ma-ticket
//   .ma-points / .ma-point the numbered reasons: <b> the number, <h3> the title, <p> the text
// ============================================================================
@$SECURE or die('Access Denied!');

if (!empty($GLOBALS['__mobileAppStyles'])) {
    return;
}
$GLOBALS['__mobileAppStyles'] = true;
?>
<style>
  .ma {
    position: relative; padding: 48px; overflow: hidden; border: 1px solid #e5e5e5; border-radius: 24px; background: #fff; color: #171717;
    container-type: inline-size;   /* the parts follow the width of the card, not of the window */
  }
  /* a container cannot ask about its own width: the grid is the element inside it */
  .ma-grid { position: relative; z-index: 1; display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, .9fr) minmax(0, 1fr); gap: 32px; align-items: center; }
  .ma-glow {
    position: absolute; z-index: 0; width: 520px; height: 520px; border-radius: 50%; pointer-events: none;
    background: radial-gradient(circle, color-mix(in srgb, var(--color-primary) 18%, transparent) 0%, transparent 65%);
    left: 50%; top: 50%; transform: translate(-50%, -50%);
  }

  /* ------------------------------------------------------------ WORDS */
  .ma-eyebrow { display: inline-flex; align-items: center; gap: 10px; margin: 0 0 18px; color: var(--color-primary); font-size: 12px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; }
  .ma-eyebrow::before { content: ''; width: 22px; height: 2px; border-radius: 2px; background: currentColor; }
  .ma-title { margin: 0; font-size: 44px; font-weight: 800; line-height: 1.08; letter-spacing: -.6px; color: #171717; }
  .ma-title em { font-style: normal; color: var(--color-primary); }
  .ma-lead { max-width: 40ch; margin: 20px 0 0; color: #525252; font-size: 16px; line-height: 1.6; }
  .ma-stores { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 28px; }
  .ma-store {
    display: inline-flex; align-items: center; gap: 10px; padding: 10px 18px 10px 14px; border-radius: 12px;
    background: #171717; color: #fff; text-decoration: none; transition: transform .2s ease, background-color .2s ease;
  }
  .ma-store:hover { background: #262626; transform: translateY(-2px); }
  .ma-store svg { width: 24px; height: 24px; flex-shrink: 0; }
  .ma-store small { display: block; font-size: 10px; line-height: 1; opacity: .8; }
  .ma-store b { display: block; margin-top: 2px; font-size: 16px; font-weight: 600; line-height: 1.1; }

  /* ------------------------------------------------------------ PHONES */
  .ma-phones { position: relative; height: 420px; }
  .ma-phone {
    position: absolute; width: 196px; height: 400px; padding: 10px; border-radius: 34px;
    background: #171717; box-shadow: 0 30px 60px -30px rgba(0, 0, 0, .5), inset 0 0 0 2px #3a3a3a;
    transition: transform .6s cubic-bezier(.22, 1, .36, 1);
  }
  .ma-phone::before { content: ''; position: absolute; top: 18px; left: 50%; width: 56px; height: 16px; border-radius: 10px; background: #171717; transform: translateX(-50%); z-index: 2; }
  .ma-phone.is-front { left: 50%; top: 10px; transform: translateX(-30%) rotate(4deg); z-index: 2; }
  .ma-phone.is-back  { left: 50%; top: 40px; transform: translateX(-78%) rotate(-7deg) scale(.94); opacity: .95; }
  .ma:hover .ma-phone.is-front { transform: translateX(-28%) rotate(2deg) translateY(-6px); }
  .ma:hover .ma-phone.is-back  { transform: translateX(-82%) rotate(-9deg) scale(.94) translateY(4px); }
  [dir="rtl"] .ma-phone.is-front { transform: translateX(-70%) rotate(-4deg); }
  [dir="rtl"] .ma-phone.is-back  { transform: translateX(-22%) rotate(7deg) scale(.94); }
  [dir="rtl"] .ma:hover .ma-phone.is-front { transform: translateX(-72%) rotate(-2deg) translateY(-6px); }
  [dir="rtl"] .ma:hover .ma-phone.is-back  { transform: translateX(-18%) rotate(9deg) scale(.94) translateY(4px); }
  .ma-screen { position: relative; height: 100%; overflow: hidden; border-radius: 26px; background: #f5f5f5; font-size: 9px; line-height: 1.2; color: #171717; }
  .ma-bar { display: flex; justify-content: space-between; padding: 12px 16px 0; font-size: 9px; font-weight: 600; }
  .ma-hello { padding: 22px 14px 0; }
  .ma-hello small { display: block; color: #737373; font-size: 8px; }
  .ma-hello b { display: block; font-size: 13px; font-weight: 700; }
  .ma-search { display: flex; align-items: center; gap: 6px; margin: 10px 14px 0; padding: 8px 10px; border-radius: 10px; background: #fff; color: #a3a3a3; border: 1px solid #e5e5e5; }
  .ma-search svg { width: 11px; height: 11px; }
  .ma-tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding: 12px 14px 0; }
  .ma-tile { display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 9px 4px; border-radius: 12px; background: #fff; border: 1px solid #ececec; color: #525252; font-size: 8px; font-weight: 500; }
  .ma-tile svg { width: 16px; height: 16px; color: var(--color-primary); }
  .ma-deal { display: flex; align-items: center; gap: 8px; margin: 12px 14px 0; padding: 10px; border-radius: 12px; background: #fff; border: 1px solid #ececec; }
  .ma-deal i { width: 30px; height: 30px; flex-shrink: 0; border-radius: 8px; background: color-mix(in srgb, var(--color-primary) 12%, #fff); }
  .ma-deal b { display: block; font-size: 9px; }
  .ma-deal small { display: block; color: #737373; font-size: 8px; }
  .ma-deal em { margin-inline-start: auto; font-style: normal; font-size: 10px; font-weight: 700; color: var(--color-primary); }
  .ma-ticket { margin: 14px 12px 0; padding: 14px; border-radius: 14px; background: var(--color-primary); color: #fff; }
  .ma-ticket .ma-route { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; font-size: 17px; font-weight: 800; letter-spacing: -.2px; }
  .ma-ticket .ma-route span { flex: 1; height: 1px; margin: 0 8px; background: rgba(255, 255, 255, .5); }
  .ma-ticket small { display: block; font-size: 8px; opacity: .85; }
  .ma-ticket .ma-cells { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; margin-top: 12px; padding-top: 10px; border-top: 1px dashed rgba(255, 255, 255, .45); }
  .ma-ticket .ma-cells b { display: block; font-size: 10px; }
  .ma-qr { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; width: 44px; margin: 14px auto 0; }
  .ma-qr i { display: block; aspect-ratio: 1; border-radius: 1px; background: #fff; }
  .ma-qr i:nth-child(3n), .ma-qr i:nth-child(5n + 1), .ma-qr i:nth-child(11n) { background: transparent; }
  .ma-note { margin: 12px 14px 0; padding: 10px; border-radius: 12px; background: #fff; border: 1px solid #ececec; color: #525252; font-size: 8px; }
  .ma-note b { display: block; color: #171717; font-size: 9px; margin-bottom: 2px; }

  /* ------------------------------------------------------------ REASONS */
  /* the reasons sit on a light grey panel */
  .ma-points {
    display: flex; flex-direction: column; gap: 22px; margin: 0; padding: 28px; list-style: none;
    border-radius: 20px; background: linear-gradient(160deg, #fafafa 0%, #f3f4f6 100%);
    box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .04);
  }
  .ma-point { display: grid; grid-template-columns: 40px 1fr; gap: 12px; align-items: start; }
  .ma-point b { color: var(--color-primary); font-size: 26px; font-weight: 800; line-height: 1; letter-spacing: -.5px; }
  .ma-point h3 { margin: 4px 0 0; font-size: 17px; font-weight: 700; line-height: 1.3; color: #171717; }
  .ma-point p { margin: 6px 0 0; color: #525252; font-size: 14px; line-height: 1.55; }

  /* ------------------------------------------------------------ NARROWER CARDS */
  @container (max-width: 1100px) {
    .ma { padding: 40px; }
    .ma-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 28px; }
    .ma-points { grid-column: 1 / -1; flex-direction: row; gap: 24px; }
    .ma-point { flex: 1; }
  }
  @container (max-width: 640px) {
    .ma { padding: 24px; border-radius: 16px; }
    .ma-grid { grid-template-columns: 1fr; gap: 24px; }
    .ma-title { font-size: 30px; }
    .ma-lead { font-size: 15px; }
    .ma-phones { height: 330px; }
    .ma-phone { width: 160px; height: 326px; padding: 8px; border-radius: 28px; }
    .ma-screen { border-radius: 21px; }
    .ma-points { flex-direction: column; gap: 18px; padding: 20px; border-radius: 14px; }
    .ma-glow { width: 360px; height: 360px; }
  }
  @media (prefers-reduced-motion: reduce) {
    .ma-phone, .ma-store { transition: none; }
  }
</style>
