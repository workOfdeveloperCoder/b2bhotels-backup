<?php
// ============================================================================
// FOOTER / STYLES
// The footer of every page: brand and contact, the CMS menus, the ways to
// follow and to get the app, then a bottom bar with the legal links and the
// trust marks. Plain CSS (no @apply); colours come from the theme
// (--footer-background, --footer-text-color, --footer-heading-color,
// --footer-link-color, --footer-border-color, --color-primary).
//
// The theme sets one font size on everything in the footer (!important), so
// the sizes here carry !important too.
//
// COMPONENT CLASSES
//   .ft                    the footer
//   .ft-top                the main row: .ft-brand, .ft-menus (.ft-menu), .ft-side
//   .ft-brand              logo, tagline, .ft-contact rows
//   .ft-h                  small heading of a column
//   .ft-menu a             a link of a menu, with a moving underline
//   .ft-social / .ft-social a   round social buttons
//   .ft-apps / .ft-app     the store badges
//   .ft-strip              the trust marks (.ft-mark: round icon tile and word) centred on their own line,
//                          then .ft-pay ("We accept" card logos, .ft-pay-logo)
//   .ft-news               the newsletter form (.ft-news-row: email + button, .ft-news-msg the answer)
//   .ft-bottom             the bottom bar: .ft-copy | .ft-legal links | the .ft-powered pill
//   .ft-top-btn            the back-to-top button
// ============================================================================
@$SECURE or die('Access Denied!');
?>
<style>
  .ft { position: relative; background: var(--footer-background); color: var(--footer-text-color); border-top: 1px solid var(--footer-border-color); }
  .ft a { text-decoration: none; }

  /* ------------------------------------------------------------ MAIN ROW */
  .ft-top { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 2fr) minmax(0, 1.1fr); gap: 48px; padding: 56px 0 44px; }
  .ft-brand img { display: block; height: 36px; width: auto; }
  .ft-brand img.is-inverted { filter: brightness(0) invert(1); }   /* a dark logo turned white for the dark ground */
  .ft-tagline { margin: 18px 0 0; max-width: 34ch; color: var(--footer-text-color); font-size: 14px !important; line-height: 1.65; }
  .ft-contact { display: flex; flex-direction: column; gap: 10px; margin: 24px 0 0; padding: 0; list-style: none; }
  .ft-contact li { display: flex; align-items: flex-start; gap: 10px; color: var(--footer-text-color); font-size: 14px !important; line-height: 1.5; }
  .ft-contact li > span:first-child { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; flex-shrink: 0; border-radius: 8px; background: color-mix(in srgb, var(--color-primary) 10%, transparent); color: var(--color-primary); }
  .ft-contact svg { width: 15px; height: 15px; }
  .ft-contact a { color: var(--footer-heading-color); font-weight: 500; transition: color .2s ease; }
  .ft-contact a:hover { color: var(--color-primary); }

  .ft-menus { display: grid; grid-template-columns: repeat(var(--ft-cols, 3), minmax(0, 1fr)); gap: 32px; }
  .ft-h { display: flex; align-items: center; gap: 10px; margin: 0 0 18px; color: var(--footer-heading-color); font-size: 12px !important; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; }
  .ft-h::before { content: ''; width: 18px; height: 2px; border-radius: 2px; background: var(--color-primary); }
  .ft-menu { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 10px; }
  .ft-menu a { position: relative; display: inline-block; color: var(--footer-text-color); font-size: 14px !important; line-height: 1.4; transition: color .2s ease, transform .2s ease; }
  .ft-menu a::after { content: ''; position: absolute; left: 0; bottom: -2px; width: 100%; height: 1px; background: var(--color-primary); transform: scaleX(0); transform-origin: left; transition: transform .25s ease; }
  .ft-menu a:hover { color: var(--footer-heading-color); }
  .ft-menu a:hover::after { transform: scaleX(1); }
  [dir="rtl"] .ft-menu a::after { transform-origin: right; }

  .ft-side .ft-h { margin-bottom: 14px; }
  .ft-social { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 26px; }
  .ft-social a { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border: 1px solid var(--footer-border-color); border-radius: 50%; background: transparent; color: var(--footer-text-color); transition: border-color .2s ease, background-color .2s ease, color .2s ease, transform .2s ease; }
  .ft-social a:hover { border-color: var(--color-primary); background: var(--color-primary); color: #fff; transform: translateY(-2px); }
  .ft-social svg { width: 16px; height: 16px; }
  .ft-apps { display: flex; flex-wrap: wrap; gap: 8px; }
  .ft-app { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px 8px 10px; border: 1px solid var(--footer-border-color); border-radius: 10px; background: color-mix(in srgb, var(--footer-heading-color) 6%, transparent); color: var(--footer-heading-color); transition: transform .2s ease, background-color .2s ease, border-color .2s ease; }
  .ft-app:hover { border-color: color-mix(in srgb, var(--color-primary) 45%, transparent); background: color-mix(in srgb, var(--color-primary) 14%, transparent); transform: translateY(-2px); }
  .ft-app svg { width: 20px; height: 20px; flex-shrink: 0; }
  .ft-app small { display: block; font-size: 9px !important; line-height: 1; opacity: .8; }
  .ft-app b { display: block; margin-top: 2px; font-size: 13px !important; font-weight: 600; line-height: 1.1; }
  .ft-support { display: flex; align-items: center; gap: 12px; margin-top: 26px; padding: 14px 16px; border: 1px solid var(--footer-border-color); border-radius: 14px; background: color-mix(in srgb, var(--footer-heading-color) 4%, transparent); }
  .ft-support > span:first-child { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; flex-shrink: 0; border-radius: 12px; background: var(--color-primary); color: #fff; }
  .ft-support svg { width: 20px; height: 20px; }
  .ft-support b { display: block; color: var(--footer-heading-color); font-size: 14px !important; font-weight: 700; line-height: 1.2; }
  .ft-support small { display: block; margin-top: 3px; color: var(--footer-text-color); font-size: 12px !important; line-height: 1.3; }

  /* ------------------------------------------------------------ TRUST MARKS */
  .ft-strip { border-top: 1px solid var(--footer-border-color); }
  .ft-strip .container { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 12px 0; padding-top: 18px; padding-bottom: 18px; }
  .ft-mark { display: inline-flex; align-items: center; gap: 10px; padding: 4px 28px; color: var(--footer-heading-color) !important; font-size: 13px !important; font-weight: 600 !important; line-height: 1; white-space: nowrap; text-decoration: none; }
  .ft-mark + .ft-mark { border-inline-start: 1px solid var(--footer-border-color); }
  .ft-mark span:first-child { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: color-mix(in srgb, var(--color-primary) 14%, transparent); color: var(--color-primary); transition: background-color .2s ease, color .2s ease, transform .25s ease; }
  /* the word: one size for the links and the plain marks (the theme sizes footer spans with !important) */
  .ft-strip .ft-mark > span:last-child { font-size: 13px !important; font-weight: 600 !important; }
  .ft-mark svg { width: 15px; height: 15px; }
  a.ft-mark:hover span:first-child { background: var(--color-primary); color: #fff; transform: translateY(-2px); }
  /* "We accept": white card-logo tiles after the marks */
  .ft-pay { display: inline-flex; align-items: center; gap: 8px; padding: 4px 28px; border-inline-start: 1px solid var(--footer-border-color); }
  .ft-pay small { margin-inline-end: 4px; color: var(--footer-text-color); font-size: 12px !important; font-weight: 600; white-space: nowrap; }
  .ft-pay-logo { display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 30px; padding: 5px 7px; border-radius: 6px; background: #fff; }
  .ft-pay-logo svg { width: 100%; height: 100%; }
  @media (max-width: 767px) {
    .ft-strip .container { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .ft-mark { padding: 0; font-size: 12px !important; }
    .ft-mark + .ft-mark { border: 0; }
    .ft-pay { grid-column: 1 / -1; justify-content: center; padding: 6px 0 0; border: 0; }
  }

  /* ------------------------------------------------------------ NEWSLETTER */
  .ft-news { margin-bottom: 26px; }
  .ft-news p { margin: 0 0 10px; color: var(--footer-text-color); font-size: 13px !important; line-height: 1.5; }
  .ft-news-row { display: flex; gap: 8px; }
  .ft-news input[type="email"] {
    flex: 1; min-width: 0; height: 42px; padding: 0 14px; border: 1px solid var(--footer-border-color); border-radius: 10px;
    background: color-mix(in srgb, var(--footer-background) 85%, #fff 15%); color: var(--footer-heading-color); font-size: 14px !important; outline: none;
  }
  .ft-news input[type="email"]::placeholder { color: var(--footer-text-color); opacity: .8; }
  .ft-news input[type="email"]:focus { border-color: var(--color-primary); }
  .ft-news button {
    flex-shrink: 0; height: 42px; padding: 0 16px; border: 0; border-radius: 10px; background: var(--color-primary); color: #fff;
    font-size: 14px !important; font-weight: 600; cursor: pointer; transition: filter .2s ease;
  }
  .ft-news button:hover { filter: brightness(1.08); }
  .ft-news button:disabled { opacity: .6; cursor: default; }
  .ft-news-hp { position: absolute !important; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
  .ft-news .ft-news-msg { margin: 8px 0 0; min-height: 0; font-size: 12px !important; }
  .ft-news .ft-news-msg:empty { display: none; }
  .ft-news .ft-news-msg.is-ok { color: #34d399; }
  .ft-news .ft-news-msg.is-error { color: #f87171; }
  /* ------------------------------------------------------------ BOTTOM BAR */
  .ft-bottom { border-top: 1px solid var(--footer-border-color); background: color-mix(in srgb, var(--footer-background) 92%, #000 8%); }
  .ft-bottom .container { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 12px 24px; padding-top: 16px; padding-bottom: 16px; }
  .ft-copy { margin: 0; color: var(--footer-text-color); font-size: 13px !important; line-height: 1.5; }
  .ft-copy b { color: var(--footer-heading-color); font-weight: 600; }
  .ft-legal { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 6px 4px; margin: 0; padding: 0; list-style: none; }
  .ft-legal li { display: flex; align-items: center; }
  .ft-legal li + li::before { content: ''; width: 3px; height: 3px; margin: 0 10px; border-radius: 50%; background: var(--footer-text-color); opacity: .5; }
  .ft-legal a { color: var(--footer-text-color); font-size: 13px !important; transition: color .2s ease; }
  .ft-legal a:hover { color: var(--footer-heading-color); }
  .ft-bottom .ft-powered { justify-self: end; margin: 0; }
  /* the linkback: a pill with the PHPTRAVELS mark; the dot breathes, the pill lifts on hover */
  .ft-powered { display: inline-flex; align-items: center; gap: 7px; height: 30px; margin-inline-start: 10px; padding: 0 12px 0 10px; border: 1px solid var(--footer-border-color); border-radius: 999px; background: color-mix(in srgb, var(--footer-heading-color) 5%, transparent); color: var(--footer-text-color) !important; font-size: 11px !important; font-weight: 500 !important; line-height: 1; white-space: nowrap; vertical-align: middle; transition: transform .25s ease, border-color .25s ease, background-color .25s ease, color .25s ease; }
  .ft-powered i { width: 6px; height: 6px; border-radius: 50%; background: var(--color-primary); animation: ft-breathe 2.4s ease-in-out infinite; }
  .ft-powered svg { width: 20px; height: 20px; margin-inline-start: 4px; color: var(--color-primary); transition: transform .4s cubic-bezier(.22, 1, .36, 1); }
  .ft-powered b { color: var(--color-primary); font-weight: 700; letter-spacing: .02em; }
  .ft-powered:hover { transform: translateY(-2px); border-color: color-mix(in srgb, var(--color-primary) 45%, transparent); background: color-mix(in srgb, var(--color-primary) 12%, transparent); color: var(--footer-heading-color) !important; }
  .ft-powered:hover svg { transform: rotate(-12deg) scale(1.15); }
  .ft-powered:hover i { animation-duration: .8s; }
  @keyframes ft-breathe { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.5); opacity: .55; } }

  /* ------------------------------------------------------------ BACK TO TOP */
  .ft-top-btn { position: fixed; bottom: 24px; inset-inline-end: 24px; z-index: 40; display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; border: 0; border-radius: 50%; background: var(--color-primary); color: #fff; box-shadow: 0 10px 24px -10px color-mix(in srgb, var(--color-primary) 70%, transparent); cursor: pointer; opacity: 0; visibility: hidden; transform: translateY(8px); transition: opacity .25s ease, transform .25s ease, visibility .25s; }
  .ft-top-btn.is-on { opacity: 1; visibility: visible; transform: none; }
  .ft-top-btn svg { width: 18px; height: 18px; }

  /* ------------------------------------------------------------ SMALLER SCREENS */
  @media (max-width: 1023px) {
    .ft-top { grid-template-columns: 1fr 1fr; gap: 36px; padding: 44px 0 36px; }
    .ft-side { grid-column: 1 / -1; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
    .ft-side .ft-support { grid-column: 1 / -1; margin-top: 0; }
  }
  @media (max-width: 767px) {
    .ft-top { grid-template-columns: 1fr; gap: 30px; padding: 36px 0 28px; }
    .ft-menus { grid-template-columns: 1fr 1fr; gap: 24px; }
    .ft-side { grid-template-columns: 1fr; }
    .ft-bottom .container { grid-template-columns: 1fr; justify-items: start; gap: 10px; }
    .ft-legal { justify-content: flex-start; }
    .ft-bottom .ft-powered { justify-self: start; }
  }
</style>
