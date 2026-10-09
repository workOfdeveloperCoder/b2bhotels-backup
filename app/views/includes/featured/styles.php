<?php
// ============================================================================
// FEATURED / STYLES
// A featured section of the home page: a heading with a link, a row of cards
// that slides sideways, and two round buttons to move it.
// Plain CSS (no @apply), so the section looks right before the Tailwind
// runtime has compiled anything.
//
// COMPONENT CLASSES
//   .fs                    the section; .is-tint (from fsBand()) puts it on a light grey band
//   .fs-head / .fs-title   heading row; .fs-title-icon is the tile before the words,
//                          .fs-more the link on the far side
//   .fs-track              the row of cards (slides, snaps to a card)
//   .fs-nav / .fs-nav-btn  previous / next
//   .fc                    a card: white, round corners, border turns to the theme colour on hover
//   .fc-top .fc-tile       top row and the tinted tile (flag, icon)
//   .fc-title              name on the card
//   .fc-chips / .fc-chip   small facts
//   .fc-foot               bottom row: price on one side, button on the other
//   .fc-from / .fc-price   "From" and the amount
//   (button)               a card uses the design-system .btn; .fc-btn-icon is its small arrow
//   .fc-signal             network name with four signal bars (data-level="1".."4")
//   .fc-flight             a flight card: .fc-carrier (logo tile, airline, flight number), .fc-cabin,
//                          .fc-route (.fc-time, .fc-place, .fc-leg with the length and the line)
//   .fc-skel               skeleton of a card
//   .fc-poster             a tall card whose words sit on the picture: .fc-poster-top (chip and
//                          rating), .fc-poster-foot (name, facts, price). Used by the featured tours.
//   .fc-stay               a hotel card: .fc-stay-photo (framed picture with .fc-stay-sale, .fc-stay-score,
//                          .fc-stay-stars), .fc-body (name, .fc-stay-place, chips), price a night. Featured hotels.
//   .fc-car                a car card: .fc-car-photo (the car on a soft light, .fc-car-kind, .fc-car-rate),
//                          .fc-body (name, .fc-car-sub, .fc-car-specs), price a day. Featured cars.
//   .fc-post               a post card, picture on one side and words on the other: .fc-post-photo
//                          (with the category chip), .fc-post-kicker (date, reading time), title,
//                          .fc-post-text, .fc-post-more. Two to a row in .fs-grid-2. The blog.
//   .fp                    spotlight: the newest item large on one side (.fp-main), the next
//                          ones as a list on the other (.fp-list > .fp-row with .fp-thumb, .fp-chip,
//                          .fp-title, .fp-meta). Used by the featured Umrah packages.
// Whole cards only: four are in view on a desktop, three on a tablet, two on
// a phone; the buttons bring the next ones. Colours come from the theme.
// ============================================================================
@$SECURE or die('Access Denied!');

// Every second featured section of a page sits on a light grey band (.fs.is-tint), so
// one section can be told from the next: the section prints class="fs" followed by fsBand()
if (!function_exists('fsBand')) {
    function fsBand(): string
    {
        static $n = 0;
        return (++$n % 2 === 0) ? ' is-tint' : '';
    }
}

if (!empty($GLOBALS['__featuredStyles'])) {
    return;
}
$GLOBALS['__featuredStyles'] = true;
?>
<style>
  /* ------------------------------------------------------------ SECTION */
  .fs { position: relative; padding: 24px 0 4px; margin-top: 8px; }
  /* every second section on a light band */
  .fs.is-tint { margin: 16px 0; padding: 28px 0 24px; background: #fafafa; border-top: 1px solid #f0f0f0; border-bottom: 1px solid #f0f0f0; }
  .fs.is-tint + .fs { margin-top: 0; }
  .fs-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
  .fs-title { display: flex; align-items: center; gap: 12px; min-width: 0; margin: 0; color: #171717; font-size: 24px; font-weight: 700; line-height: 1.25; letter-spacing: .12px; }
  .fs-title-icon {
    display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; flex-shrink: 0;
    border: 1px solid color-mix(in srgb, var(--color-primary) 30%, transparent); border-radius: 12px;
    background: color-mix(in srgb, var(--color-primary) 10%, #fff); color: var(--color-primary);
  }
  .fs-title-icon svg { width: 22px; height: 22px; }
  .fs-more {
    display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0; padding: 8px 12px; border-radius: 8px;
    color: var(--color-primary); font-size: 15px; font-weight: 600; letter-spacing: .075px; text-decoration: none;
    transition: opacity .2s ease;
  }
  .fs-more:hover { opacity: .75; }
  .fs-more svg { width: 14px; height: 14px; flex-shrink: 0; transition: transform .2s ease; }
  .fs-more:hover svg { transform: translateX(3px); }
  [dir="rtl"] .fs-more svg { transform: scaleX(-1); }
  [dir="rtl"] .fs-more:hover svg { transform: scaleX(-1) translateX(3px); }

  /* ------------------------------------------------------------ ROW OF CARDS */
  .fs-track {
    display: flex; gap: 16px; overflow-x: auto; overscroll-behavior-x: contain;
    scroll-snap-type: x mandatory; scroll-padding-inline: 6px; scrollbar-width: none; -ms-overflow-style: none;
    /* room around the row, or the border and the shadow of the first and last card are cut */
    padding: 6px 6px 18px; margin: -6px -6px -12px;
  }
  .fs-track::-webkit-scrollbar { display: none; }
  .fs-track > * { scroll-snap-align: start; }

  .fs-nav { display: flex; justify-content: flex-end; gap: 8px; margin-top: 6px; }
  .fs-nav-btn {
    display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; padding: 0;
    border: 1px solid #e5e5e5; border-radius: 50%; background: #fff; color: #262626; cursor: pointer;
    transition: border-color .2s ease, color .2s ease, opacity .2s ease, transform .15s ease;
  }
  .fs-nav-btn svg { width: 18px; height: 18px; }
  .fs-nav-btn:hover:not(:disabled) { border-color: var(--color-primary); color: var(--color-primary); }
  .fs-nav-btn:active:not(:disabled) { transform: scale(.94); }
  .fs-nav-btn:disabled { opacity: .4; cursor: default; }
  [dir="rtl"] .fs-nav-btn svg { transform: scaleX(-1); }

  /* ------------------------------------------------------------ CARD */
  .fc {
    position: relative; display: flex; flex-direction: column; gap: 4px; flex: 0 0 auto;
    width: calc((100% - 48px) / 4); padding: 20px; overflow: hidden;
    border: 1px solid #e5e5e5; border-radius: 24px; background: #fff; color: #171717;
    text-decoration: none; cursor: pointer; outline: none;
    transition: border-color .3s ease, box-shadow .3s ease;
    animation: fc-in .5s cubic-bezier(.22, 1, .36, 1) both; animation-delay: calc(var(--n, 0) * 60ms);
  }
  .fc:hover, .fc:focus-visible { border-color: var(--color-primary); box-shadow: 0 10px 30px -18px color-mix(in srgb, var(--color-primary) 55%, transparent); }
  @keyframes fc-in { from { opacity: 0; transform: translateY(14px); } }

  .fc-top { display: flex; align-items: flex-start; justify-content: space-between; flex-shrink: 0; }
  .fc-tile {
    display: flex; align-items: center; justify-content: center; width: 54px; height: 54px; flex-shrink: 0; overflow: hidden;
    border: 1px solid color-mix(in srgb, var(--color-primary) 30%, transparent); border-radius: 12px;
    background: color-mix(in srgb, var(--color-primary) 10%, #fff); color: var(--color-primary);
  }
  .fc-tile img { width: 32px; height: 32px; object-fit: cover; border-radius: 50%; box-shadow: 0 0 0 1px rgba(0, 0, 0, .06); }

  .fc-title {
    margin: 0; padding-top: 12px; color: #171717; font-size: 18px; font-weight: 700; line-height: 1.2; letter-spacing: .09px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }

  .fc-chips { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; min-height: 49px; padding: 12px 0; }
  .fc-chip {
    display: inline-flex; align-items: center; padding: 5px 8px; border: 1px solid #e5e5e5; border-radius: 6px;
    background: rgba(245, 245, 245, .7); color: #525252; font-size: 12px; font-weight: 500; line-height: 1; white-space: nowrap;
  }

  .fc-foot {
    display: flex; align-items: flex-end; justify-content: space-between; gap: 8px; flex-shrink: 0; margin-top: auto;
    padding-top: 16px; border-top: 1px solid rgba(229, 229, 229, .7);
  }
  .fc-from { display: block; margin-bottom: 4px; color: #737373; font-size: 11px; font-weight: 500; line-height: 1; }
  .fc-price { display: flex; align-items: baseline; gap: 4px; color: #171717; font-variant-numeric: tabular-nums; line-height: 1; }
  .fc-price small { color: #737373; font-size: 11px; font-weight: 600; }
  .fc-price b { font-size: 21px; font-weight: 700; letter-spacing: -.2px; }

  /* the button of a card is the design-system .btn; the card only places it */
  .fc-foot .btn { flex-shrink: 0; }
  .fc-btn-icon { width: 14px; height: 14px; flex-shrink: 0; transition: transform .25s ease; }
  .fc:hover .fc-btn-icon, .fm-row:hover .fc-btn-icon { transform: translateX(2px); }
  [dir="rtl"] .fc-btn-icon { transform: scaleX(-1); }
  [dir="rtl"] .fc:hover .fc-btn-icon, [dir="rtl"] .fm-row:hover .fc-btn-icon { transform: scaleX(-1) translateX(2px); }

  /* ------------------------------------------------------------ SIGNAL */
  .fc-signal { display: inline-flex; align-items: center; gap: 6px; color: var(--color-primary); font-size: 11px; font-weight: 600; line-height: 1; letter-spacing: .06em; }
  .fc-bars { display: flex; align-items: flex-end; gap: 3px; height: 16px; margin-top: 2px; direction: ltr; }
  .fc-bars i { display: block; width: 3px; border-radius: 2px; background: currentColor; opacity: .28; transform-origin: bottom; }
  .fc-bars i:nth-child(1) { height: 5px; }
  .fc-bars i:nth-child(2) { height: 9px; }
  .fc-bars i:nth-child(3) { height: 13px; }
  .fc-bars i:nth-child(4) { height: 16px; }
  .fc-signal[data-level="1"] .fc-bars i:nth-child(-n+1),
  .fc-signal[data-level="2"] .fc-bars i:nth-child(-n+2),
  .fc-signal[data-level="3"] .fc-bars i:nth-child(-n+3),
  .fc-signal[data-level="4"] .fc-bars i { opacity: 1; }
  /* on hover the bars fill one after another, like a phone finding its network */
  .fc:hover .fc-bars i { animation: fc-bar .7s ease both; }
  .fc:hover .fc-bars i:nth-child(2) { animation-delay: .08s; }
  .fc:hover .fc-bars i:nth-child(3) { animation-delay: .16s; }
  .fc:hover .fc-bars i:nth-child(4) { animation-delay: .24s; }
  @keyframes fc-bar { 0% { transform: scaleY(.3); } 60% { transform: scaleY(1.12); } 100% { transform: scaleY(1); } }

  /* ------------------------------------------------------------ FLIGHT CARD */
  .fc-flight { gap: 0; }
  .fc-carrier { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .fc-carrier .fc-tile { width: 40px; height: 40px; border-radius: 50%; }
  /* airline logos are square pictures: they fill the round tile and are cut round */
  .fc-carrier .fc-tile img { width: 100%; height: 100%; border-radius: 50%; box-shadow: none; object-fit: cover; }
  .fc-carrier .fc-tile svg { width: 18px; height: 18px; }
  .fc-airline { margin: 0; color: #171717; font-size: 13px; font-weight: 700; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .fc-flight-no { margin: 2px 0 0; color: #a3a3a3; font-size: 11px; line-height: 1.2; }
  .fc-cabin {
    flex-shrink: 0; padding: 5px 10px; border-radius: 999px; font-size: 10px; font-weight: 600; line-height: 1; white-space: nowrap;
    background: color-mix(in srgb, var(--color-primary) 10%, #fff); color: var(--color-primary);
  }

  .fc-route { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin: 18px 0; }
  .fc-route > div:first-child, .fc-route > div:last-child { min-width: 0; flex: 0 1 auto; }
  .fc-route > div:last-child { text-align: end; }
  .fc-time { margin: 0; color: #171717; font-size: 19px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
  .fc-place { margin: 5px 0 0; color: #a3a3a3; font-size: 12px; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .fc-leg { display: flex; flex: 1 1 0; flex-direction: column; align-items: center; min-width: 64px; padding: 5px 4px 0; }
  .fc-leg-length { color: #a3a3a3; font-size: 10px; font-weight: 500; line-height: 1; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; }
  .fc-leg-stops { margin-top: 4px; color: #737373; font-size: 10px; line-height: 1; white-space: nowrap; }
  .fc-leg-line { position: relative; display: flex; align-items: center; width: 100%; margin-top: 6px; color: var(--color-primary); }
  .fc-leg-line i { display: block; width: 4px; height: 4px; flex-shrink: 0; border-radius: 50%; background: currentColor; opacity: .5; }
  .fc-leg-line b { display: block; flex: 1; height: 1px; background: linear-gradient(to right, color-mix(in srgb, currentColor 50%, transparent), color-mix(in srgb, currentColor 20%, transparent)); }
  .fc-leg-line svg { width: 14px; height: 14px; flex-shrink: 0; transform: rotate(90deg); transition: transform .4s cubic-bezier(.22, 1, .36, 1); }
  .fc:hover .fc-leg-line svg { transform: rotate(90deg) translateY(-3px); }
  [dir="rtl"] .fc-leg-line { flex-direction: row-reverse; }
  [dir="rtl"] .fc-leg-line svg { transform: rotate(-90deg); }
  [dir="rtl"] .fc:hover .fc-leg-line svg { transform: rotate(-90deg) translateY(-3px); }
  /* on a narrow card the button drops under the price */
  .fc-flight .fc-foot { flex-wrap: wrap; align-items: center; padding-top: 14px; }
  .fc-flight .fc-foot .btn svg.fc-btn-icon { width: 16px; height: 16px; }
  .fc-flight:hover .fc-btn-icon { transform: none; }
  /* ------------------------------------------------------------ POSTER CARD */
  .fc-poster { justify-content: flex-end; aspect-ratio: 3 / 4; min-height: 340px; padding: 18px; color: #fff; background: #262626; isolation: isolate; }
  .fc-poster img { position: absolute; inset: 0; z-index: -2; width: 100%; height: 100%; object-fit: cover; transition: transform 1s cubic-bezier(.22, 1, .36, 1); }
  .fc-poster:hover img { transform: scale(1.05); }
  .fc-poster::after {
    content: ''; position: absolute; inset: 0; z-index: -1;
    background: linear-gradient(to top, rgba(0, 0, 0, .82) 0%, rgba(0, 0, 0, .38) 42%, rgba(0, 0, 0, .06) 70%, rgba(0, 0, 0, .18) 100%);
  }
  .fc-poster:hover, .fc-poster:focus-visible { border-color: transparent; box-shadow: 0 20px 40px -22px rgba(0, 0, 0, .5); }
  .fc-poster-top { position: absolute; top: 14px; inset-inline: 14px; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
  .fc-poster-chip { display: inline-flex; align-items: center; gap: 4px; max-width: 70%; padding: 5px 10px; border-radius: 999px; background: rgba(255, 255, 255, .92); color: #171717; font-size: 11px; font-weight: 600; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .fc-poster-chip svg { width: 12px; height: 12px; flex-shrink: 0; color: var(--color-primary); }
  .fc-poster-rate { display: inline-flex; align-items: center; gap: 4px; padding: 5px 8px; border-radius: 999px; background: rgba(0, 0, 0, .55); backdrop-filter: blur(4px); color: #fff; font-size: 11px; font-weight: 700; line-height: 1; }
  .fc-poster-rate svg { width: 11px; height: 11px; color: #fbbf24; }
  .fc-poster-sale { position: absolute; top: 48px; inset-inline-start: 14px; padding: 4px 8px; border-radius: 6px; background: var(--color-primary); color: #fff; font-size: 10px; font-weight: 700; line-height: 1.2; }
  .fc-poster .fc-title { padding-top: 0; color: #fff; font-size: 17px; white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; text-shadow: 0 1px 6px rgba(0, 0, 0, .35); }
  .fc-poster-facts { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin-top: 8px; color: rgba(255, 255, 255, .82); font-size: 12px; line-height: 1.2; }
  .fc-poster-facts i { width: 3px; height: 3px; border-radius: 50%; background: currentColor; opacity: .7; }
  .fc-poster-foot { display: flex; align-items: flex-end; justify-content: space-between; gap: 8px; margin-top: 14px; padding-top: 12px; border-top: 1px solid rgba(255, 255, 255, .22); }
  .fc-poster .fc-from { color: rgba(255, 255, 255, .72); }
  .fc-poster .fc-price { color: #fff; }
  .fc-poster .fc-price small { color: rgba(255, 255, 255, .72); }
  .fc-poster .fc-price b { font-size: 19px; }
  .fc-poster-go { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; flex-shrink: 0; border-radius: 50%; background: rgba(255, 255, 255, .92); color: #171717; transition: background-color .25s ease, color .25s ease, transform .25s ease; }
  .fc-poster-go svg { width: 16px; height: 16px; }
  .fc-poster:hover .fc-poster-go { background: var(--color-primary); color: #fff; transform: translateX(2px); }
  [dir="rtl"] .fc-poster-go svg { transform: scaleX(-1); }
  [dir="rtl"] .fc-poster:hover .fc-poster-go { transform: translateX(-2px); }
  /* the row of places above the cards */
  .fs-filters { display: flex; gap: 8px; margin: -8px 0 20px; overflow-x: auto; scrollbar-width: none; padding-bottom: 2px; }
  .fs-filters::-webkit-scrollbar { display: none; }
  .fs-filters .fm-pick { flex-shrink: 0; }
  /* ------------------------------------------------------------ STAY CARD */
  .fc-stay { gap: 0; padding: 8px 8px 16px; }
  .fc-stay-photo { position: relative; aspect-ratio: 4 / 3; overflow: hidden; border-radius: 16px; background: #f1f1f1; }
  .fc-stay-photo img { width: 100%; height: 100%; object-fit: cover; transition: transform 1s cubic-bezier(.22, 1, .36, 1); }
  .fc:hover .fc-stay-photo img { transform: scale(1.05); }
  .fc-stay-photo::after { content: ''; position: absolute; inset: 0; background: linear-gradient(to top, rgba(0, 0, 0, .35) 0%, transparent 45%); pointer-events: none; }
  .fc-stay-sale { position: absolute; top: 10px; inset-inline-start: 10px; z-index: 1; padding: 4px 8px; border-radius: 6px; background: var(--color-primary); color: #fff; font-size: 10px; font-weight: 700; line-height: 1.2; }
  .fc-stay-score { position: absolute; top: 10px; inset-inline-end: 10px; z-index: 1; display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; border-radius: 999px; background: rgba(255, 255, 255, .92); color: #171717; font-size: 11px; font-weight: 700; line-height: 1; }
  .fc-stay-score b { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 18px; padding: 0 4px; border-radius: 5px; background: var(--color-primary); color: #fff; font-size: 11px; }
  .fc-stay-stars { position: absolute; bottom: 10px; inset-inline-start: 12px; z-index: 1; display: flex; gap: 2px; color: #fbbf24; }
  .fc-stay-stars svg { width: 12px; height: 12px; filter: drop-shadow(0 1px 2px rgba(0, 0, 0, .4)); }
  .fc-stay .fc-body { padding: 0 8px 14px; }
  .fc-stay .fc-title { padding-top: 14px; font-size: 17px; }
  .fc-stay-place { display: flex; align-items: center; gap: 5px; margin: 5px 0 0; color: #737373; font-size: 12.5px; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .fc-stay-place svg { width: 13px; height: 13px; flex-shrink: 0; color: var(--color-primary); }
  .fc-stay .fc-chips { min-height: 0; padding: 12px 0 0; }
  .fc-stay .fc-foot { margin: auto 8px 0; padding-top: 12px; }
  .fc-stay .fc-price b { font-size: 19px; }
  .fc-stay .fc-night { display: block; margin-top: 3px; color: #a3a3a3; font-size: 11px; line-height: 1; }
  /* ------------------------------------------------------------ CAR CARD */
  .fc-car { gap: 0; padding: 8px 8px 16px; }
  /* car pictures are mostly photos on white, not cut-outs: a white frame, the car inside it, no shadow */
  .fc-car-photo { position: relative; display: flex; align-items: center; justify-content: center; aspect-ratio: 16 / 10; overflow: hidden; border: 1px solid #ececec; border-radius: 16px; background: #fff; }
  .fc-car-photo img { width: 100%; height: 100%; padding: 6%; object-fit: contain; transition: transform .6s cubic-bezier(.22, 1, .36, 1); }
  .fc:hover .fc-car-photo img { transform: translateX(4px) scale(1.04); }
  [dir="rtl"] .fc:hover .fc-car-photo img { transform: translateX(-4px) scale(1.04); }
  .fc-car-kind { position: absolute; top: 10px; inset-inline-start: 10px; padding: 4px 9px; border-radius: 999px; background: rgba(255, 255, 255, .92); color: #171717; font-size: 10px; font-weight: 700; line-height: 1.2; letter-spacing: .04em; text-transform: uppercase; }
  .fc-car-rate { position: absolute; top: 10px; inset-inline-end: 10px; display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 999px; background: rgba(255, 255, 255, .92); color: #171717; font-size: 11px; font-weight: 700; line-height: 1; }
  .fc-car-rate svg { width: 11px; height: 11px; color: #fbbf24; }
  .fc-car .fc-body { padding: 0 8px 14px; }
  .fc-car .fc-title { padding-top: 14px; font-size: 17px; }
  .fc-car-sub { margin: 4px 0 0; color: #737373; font-size: 12.5px; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .fc-car-specs { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 10px; margin: 12px 0 0; padding: 0; list-style: none; }
  .fc-car-specs li { display: flex; align-items: center; gap: 6px; color: #525252; font-size: 12px; line-height: 1.2; white-space: nowrap; overflow: hidden; }
  .fc-car-specs li span { overflow: hidden; text-overflow: ellipsis; }
  .fc-car-specs svg { width: 15px; height: 15px; flex-shrink: 0; color: var(--color-primary); }
  /* the foot stays at the bottom (margin-top auto): the cards of a row are as tall as the tallest one */
  .fc-car .fc-foot { flex-wrap: wrap; margin: auto 8px 0; padding-top: 12px; }
  .fc-car .fc-price b { font-size: 19px; }
  .fc-car .fc-night { display: inline; margin: 0; color: #737373; font-size: 12px; }   /* "/ Day" on the line of the price */
  /* ------------------------------------------------------------ POST CARD */
  .fs-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .fc-post { flex-direction: row; align-items: stretch; gap: 20px; width: auto; padding: 10px; }
  .fc-post-photo { position: relative; flex: 0 0 42%; min-height: 172px; overflow: hidden; border-radius: 16px; background: #f1f1f1; }
  .fc-post-photo img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transition: transform 1s cubic-bezier(.22, 1, .36, 1); }
  .fc:hover .fc-post-photo img { transform: scale(1.05); }
  .fc-post-photo .fc-chip { position: absolute; top: 10px; inset-inline-start: 10px; border-color: transparent; background: rgba(255, 255, 255, .92); color: #171717; font-weight: 600; }
  .fc-post-body { display: flex; flex: 1; flex-direction: column; min-width: 0; padding: 8px 10px 8px 0; }
  .fc-post-kicker { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; color: #a3a3a3; font-size: 11px; font-weight: 600; line-height: 1.2; letter-spacing: .06em; text-transform: uppercase; }
  .fc-post-kicker i { width: 3px; height: 3px; border-radius: 50%; background: currentColor; }
  .fc-post .fc-title { padding-top: 10px; font-size: 18px; line-height: 1.3; white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; transition: color .2s ease; }
  .fc:hover .fc-post .fc-title, .fc-post:hover .fc-title { color: var(--color-primary); }
  .fc-post-text { margin: 8px 0 0; color: #737373; font-size: 13.5px; line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .fc-post-more { display: inline-flex; align-items: center; gap: 6px; margin-top: auto; padding-top: 14px; color: var(--color-primary); font-size: 13px; font-weight: 600; }
  .fc-post-more svg { width: 14px; height: 14px; transition: transform .25s ease; }
  .fc-post:hover .fc-post-more svg { transform: translateX(3px); }
  [dir="rtl"] .fc-post-more svg { transform: scaleX(-1); }
  [dir="rtl"] .fc-post:hover .fc-post-more svg { transform: scaleX(-1) translateX(3px); }
  [dir="rtl"] .fc-post-body { padding: 8px 0 8px 10px; }
  @media (max-width: 767px) {
    .fs-grid-2 { grid-template-columns: 1fr; gap: 12px; }
    .fc-post { flex-direction: column; gap: 12px; padding: 8px; border-radius: 16px; }
    .fc-post-photo { flex: none; aspect-ratio: 16 / 9; min-height: 0; border-radius: 11px; }
    .fc-post-body { padding: 0 6px 8px; }
    .fc-post .fc-title { padding-top: 8px; font-size: 16px; }
    .fc-post-text { font-size: 13px; }
  }
  /* ------------------------------------------------------------ SPOTLIGHT */
  .fp { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; align-items: stretch; }
  .fp-main {
    position: relative; display: flex; flex-direction: column; justify-content: flex-end; min-height: 460px; padding: 32px; overflow: hidden;
    border-radius: 24px; background: #262626; color: #fff; text-decoration: none; isolation: isolate;
    animation: fc-in .5s cubic-bezier(.22, 1, .36, 1) both;
  }
  .fp-main img { position: absolute; inset: 0; z-index: -2; width: 100%; height: 100%; object-fit: cover; transition: transform 1.2s cubic-bezier(.22, 1, .36, 1); }
  .fp-main:hover img { transform: scale(1.04); }
  .fp-main::after { content: ''; position: absolute; inset: 0; z-index: -1; background: linear-gradient(to top, rgba(0, 0, 0, .78) 0%, rgba(0, 0, 0, .35) 45%, rgba(0, 0, 0, .05) 100%); }
  .fp-main .fp-chip { position: absolute; top: 20px; inset-inline-start: 20px; background: rgba(255, 255, 255, .92); color: #262626; }
  .fp-main .fp-title { margin: 0; color: #fff; font-size: 34px; font-weight: 700; line-height: 1.15; letter-spacing: -.2px; }
  .fp-main .fp-text { margin: 12px 0 0; max-width: 60ch; color: rgba(255, 255, 255, .85); font-size: 15px; line-height: 1.55; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .fp-main .fp-meta { margin-top: 18px; color: rgba(255, 255, 255, .8); }
  .fp-main .fp-meta b { color: #fff; }

  .fp-list { display: flex; flex-direction: column; overflow: hidden; border: 1px solid #e5e5e5; border-radius: 24px; background: #fff; }
  .fp-list-head { padding: 18px 24px; border-bottom: 1px solid #ececec; color: #737373; font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; }
  .fp-row {
    display: flex; gap: 16px; flex: 1 1 0; align-items: flex-start; padding: 18px 24px; color: #171717; text-decoration: none;
    border-bottom: 1px solid #ececec; transition: background-color .2s ease;
    animation: fc-in .5s cubic-bezier(.22, 1, .36, 1) both; animation-delay: calc(var(--n, 0) * 60ms);
  }
  .fp-row:last-child { border-bottom: 0; }
  .fp-row:hover { background: #fafafa; }
  .fp-thumb { width: 96px; height: 96px; flex-shrink: 0; overflow: hidden; border-radius: 12px; background: #f1f1f1; }
  .fp-thumb img { width: 100%; height: 100%; object-fit: cover; transition: transform .8s cubic-bezier(.22, 1, .36, 1); }
  .fp-row:hover .fp-thumb img { transform: scale(1.06); }
  .fp-row > div { min-width: 0; flex: 1; }
  .fp-chip {
    display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; line-height: 1.2;
    background: color-mix(in srgb, var(--color-primary) 10%, #fff); color: var(--color-primary);
  }
  .fp-row .fp-title { margin: 8px 0 0; color: #171717; font-size: 17px; font-weight: 700; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; transition: color .2s ease; }
  .fp-row:hover .fp-title { color: var(--color-primary); }
  .fp-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; margin-top: 10px; color: #737373; font-size: 13px; line-height: 1.2; }
  .fp-meta b { color: #171717; font-weight: 700; }
  .fp-meta i { width: 3px; height: 3px; border-radius: 50%; background: currentColor; opacity: .6; }
  @media (max-width: 1023px) {
    .fp { grid-template-columns: 1fr; }
    .fp-main { min-height: 360px; padding: 24px; }
    .fp-main .fp-title { font-size: 26px; }
  }
  @media (max-width: 767px) {
    .fp { gap: 16px; }
    .fp-main { min-height: 300px; padding: 20px; border-radius: 16px; }
    .fp-main .fp-title { font-size: 22px; }
    .fp-main .fp-text { display: none; }
    .fp-list { border-radius: 16px; }
    .fp-list-head { padding: 14px 16px; }
    .fp-row { gap: 12px; padding: 14px 16px; }
    .fp-thumb { width: 72px; height: 72px; border-radius: 10px; }
    .fp-row .fp-title { font-size: 15px; }
  }
  /* ------------------------------------------------------------ SKELETON */
  .fc-skel { animation: none; cursor: default; pointer-events: none; }
  .fc-skel i, .fs-skel-line {
    display: block; border-radius: 6px;
    background: linear-gradient(90deg, #f1f1f1 0%, #f1f1f1 35%, #e4e4e4 50%, #f1f1f1 65%, #f1f1f1 100%);
    background-size: 300% 100%; animation: fc-shimmer 1.3s linear infinite;
  }
  @keyframes fc-shimmer { from { background-position: 100% 0; } to { background-position: 0 0; } }

  /* ------------------------------------------------------------ SMALLER SCREENS */
  @media (max-width: 1023px) {
    .fc { width: calc((100% - 32px) / 3); }
  }
  @media (max-width: 767px) {
    .fs { padding-top: 16px; }
    .fs.is-tint { margin: 12px 0; padding: 20px 0 16px; }
    .fs-head { margin-bottom: 14px; }
    .fs-title { gap: 10px; font-size: 17px; letter-spacing: .09px; }
    .fs-title-icon { width: 34px; height: 34px; border-radius: 10px; }
    .fs-title-icon svg { width: 18px; height: 18px; }
    .fs-more { display: none; }
    .fs-nav { margin-top: 10px; }
    .fs-nav-btn { width: 40px; height: 40px; }
    .fc { width: calc((100% - 16px) / 2); padding: 12px; border-radius: 16px; }
    .fc-tile { width: 36px; height: 36px; border-radius: 9px; }
    .fc-tile img { width: 22px; height: 22px; }
    .fc-title { padding-top: 6px; font-size: 14px; white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .fc-chips { display: none; }
    .fc-foot { flex-direction: column; align-items: stretch; gap: 8px; margin-top: 10px; padding-top: 10px; }
    .fc-price b { font-size: 18px; }
    .fc-foot .btn { width: 100%; }
    .fc-signal { font-size: 10px; gap: 4px; }
    .fc-carrier .fc-tile { width: 32px; height: 32px; }
    .fc-carrier .fc-tile img { width: 20px; height: 20px; }
    .fc-cabin { display: none; }
    .fc-route { margin: 12px 0; }
    .fc-time { font-size: 16px; }
    .fc-leg { min-width: 44px; }
    .fc-poster { min-height: 250px; padding: 12px; }
    .fc-poster-top { top: 10px; inset-inline: 10px; }
    .fc-poster-sale { top: 40px; inset-inline-start: 10px; }
    .fc-poster .fc-title { font-size: 14px; }
    .fc-poster-facts { display: flex; font-size: 11px; }
    .fc-poster-foot { flex-direction: row; align-items: flex-end; margin-top: 10px; padding-top: 10px; }
    .fc-poster .fc-price b { font-size: 16px; }
    .fc-poster-go { width: 30px; height: 30px; }
    .fs-filters { margin-bottom: 14px; }
    .fc-stay { padding: 6px 6px 12px; }
    .fc-stay-photo { border-radius: 11px; }
    .fc-stay .fc-body { padding: 0 6px 10px; }
    .fc-stay .fc-title { padding-top: 10px; font-size: 14px; }
    .fc-stay-place { font-size: 11.5px; }
    .fc-stay .fc-chips { display: none; }
    .fc-stay .fc-foot { margin: auto 6px 0; padding-top: 10px; }
    .fc-stay .fc-price b { font-size: 16px; }
    .fc-car { padding: 6px 6px 12px; }
    .fc-car-photo { border-radius: 11px; }
    .fc-car .fc-body { padding: 0 6px 10px; }
    .fc-car .fc-title { padding-top: 10px; font-size: 14px; }
    .fc-car-sub { font-size: 11.5px; }
    .fc-car-specs { grid-template-columns: 1fr; gap: 4px; margin-top: 8px; }
    .fc-car-specs li { font-size: 11px; }
    .fc-car .fc-foot { margin: auto 6px 0; padding-top: 10px; }
    .fc-car .fc-price b { font-size: 16px; }
  }

  @media (prefers-reduced-motion: reduce) {
    .fc, .fc-bars i, .fc-skel i, .fs-skel-line, .fp-main, .fp-row { animation: none !important; }
  }
</style>
