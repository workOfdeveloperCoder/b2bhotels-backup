<?php
// ============================================================================
// TRIPADVISOR / STYLES
// The Tripadvisor reviews card of the hotel details page: the overall score,
// the rating breakdown, the sub-ratings, then the reviews. Plain CSS (no
// @apply). Page colours come from the theme; only Tripadvisor's own marks
// (rating bubbles, brand name) use the Tripadvisor green, as its brand rules ask.
//
// COMPONENT CLASSES
//   .ta                       the card (same frame as the other boxes of the page)
//   .ta-head / .ta-title      heading row; .ta-brand is the Tripadvisor mark on the other side
//   .ta-summary               the three parts: .ta-score, .ta-bars, .ta-subs
//   .ta-score                 big number (.ta-score-num), bubbles, review count, .ta-status
//   .ta-bars / .ta-bar        one row per rating: label, track with fill (.ta-bar-fill), count
//   .ta-subs / .ta-sub        sub-ratings: label, short track, value
//   .ta-actions               "Read all reviews" link + "Write a review" button
//   .ta-list / .ta-review     the reviews: .ta-user (avatar, name, place), .ta-meta (bubbles, date,
//                             trip type), .ta-review-title, .ta-text (.is-open shows all),
//                             .ta-more, .ta-photos, .ta-owner (response from the owner)
//   .ta-bubbles               rating image from Tripadvisor; .ta-dots is the drawn fallback
//   .ta-foot                  attribution line
//   .ta-skel                  grey placeholder while loading
// ============================================================================
@$SECURE or die('Access Denied!');

if (!empty($GLOBALS['__tripadvisorStyles'])) {
    return;
}
$GLOBALS['__tripadvisorStyles'] = true;
?>
<style>
  .ta {
    --ta-green: #00aa6c;                     /* Tripadvisor brand green — its marks only */
    --ta-ink: #171717; --ta-muted: #6b7280; --ta-line: #e5e7eb; --ta-soft: #f5f5f4;
    margin-top: 24px; padding: 24px; border: 1px solid var(--ta-line); border-radius: 12px; background: #fff; color: var(--ta-ink);
    container-type: inline-size;
  }
  .ta[hidden] { display: none; }

  /* ------------------------------------------------------------ HEAD */
  .ta-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
  .ta-title { margin: 0; font-size: 18px; font-weight: 600; line-height: 1.3; }
  .ta-brand { display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0; color: var(--ta-ink); font-size: 15px; font-weight: 700; letter-spacing: -.2px; text-decoration: none; }
  .ta-brand img { width: 24px; height: 24px; border-radius: 6px; object-fit: cover; display: block; }
  .ta-brand-dot { width: 22px; height: 22px; border-radius: 50%; background: var(--ta-green); display: grid; place-items: center; }
  .ta-brand-dot::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: #fff; box-shadow: 0 0 0 2px var(--ta-ink) inset; }

  /* ------------------------------------------------------------ SUMMARY */
  .ta-summary { display: grid; grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr) minmax(0, 1.2fr); gap: 28px; padding-bottom: 22px; border-bottom: 1px solid var(--ta-line); }
  .ta-score { display: flex; flex-direction: column; justify-content: center; gap: 6px; }
  .ta-score-num { font-size: 44px; font-weight: 800; line-height: 1; letter-spacing: -1px; }
  .ta-score-num small { margin-inline-start: 4px; color: var(--ta-muted); font-size: 16px; font-weight: 500; letter-spacing: 0; }
  .ta-count { color: var(--ta-muted); font-size: 14px; }
  .ta-status { align-self: flex-start; margin-top: 4px; padding: 3px 10px; border-radius: 999px; background: #fef3c7; color: #92400e; font-size: 12px; font-weight: 600; }

  .ta-bars { display: flex; flex-direction: column; justify-content: center; gap: 8px; }
  .ta-bar { display: grid; grid-template-columns: 84px minmax(0, 1fr) 52px; align-items: center; gap: 10px; font-size: 13px; }
  .ta-bar-label { color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ta-track { display: block; height: 8px; border-radius: 999px; background: var(--ta-soft); overflow: hidden; }
  .ta-bar-fill { display: block; height: 100%; border-radius: inherit; background: var(--ta-green); transition: width .8s cubic-bezier(.22, 1, .36, 1); }
  .ta-bar-count { color: var(--ta-muted); text-align: end; font-variant-numeric: tabular-nums; }

  .ta-subs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 20px; align-content: center; }
  .ta-sub { display: flex; flex-direction: column; gap: 5px; font-size: 13px; }
  .ta-sub-row { display: flex; justify-content: space-between; gap: 8px; }
  .ta-sub-row span:first-child { color: #374151; }
  .ta-sub-row b { font-weight: 700; font-variant-numeric: tabular-nums; }
  .ta-sub .ta-track { height: 5px; }

  /* ------------------------------------------------------------ ACTIONS */
  .ta-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 0 4px; }
  .ta-actions-note { color: var(--ta-muted); font-size: 13px; }
  .ta-links { display: flex; flex-wrap: wrap; gap: 10px; }
  .ta-link { display: inline-flex; align-items: center; gap: 6px; color: var(--color-primary); font-size: 14px; font-weight: 600; text-decoration: none; }
  .ta-link:hover { text-decoration: underline; }
  .ta-link svg { width: 14px; height: 14px; }

  /* ------------------------------------------------------------ REVIEWS */
  .ta-list { display: grid; gap: 14px; margin-top: 14px; }
  .ta-review { padding: 18px; border: 1px solid var(--ta-line); border-radius: 12px; background: #fff; animation: ta-in .45s cubic-bezier(.22, 1, .36, 1) both; }
  @keyframes ta-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

  .ta-user { display: flex; align-items: center; gap: 12px; }
  .ta-avatar { width: 40px; height: 40px; flex-shrink: 0; border-radius: 50%; object-fit: cover; background: var(--ta-soft); display: grid; place-items: center; color: #4b5563; font-weight: 700; }
  .ta-user-name { font-size: 14px; font-weight: 600; line-height: 1.2; }
  .ta-user-place { color: var(--ta-muted); font-size: 12px; margin-top: 2px; }
  .ta-review-link { margin-inline-start: auto; color: var(--ta-muted); }
  .ta-review-link:hover { color: var(--ta-green); }
  .ta-review-link svg { width: 16px; height: 16px; display: block; }

  .ta-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; margin-top: 12px; color: var(--ta-muted); font-size: 12px; }
  .ta-chip { padding: 2px 8px; border-radius: 999px; background: var(--ta-soft); color: #374151; font-weight: 500; }
  .ta-review-title { margin: 10px 0 6px; font-size: 15px; font-weight: 700; line-height: 1.35; }
  .ta-text { margin: 0; color: #374151; font-size: 14px; line-height: 1.65; white-space: pre-line; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 4; overflow: hidden; }
  .ta-text.is-open { display: block; -webkit-line-clamp: unset; }
  .ta-more { margin-top: 6px; padding: 0; border: 0; background: none; color: var(--color-primary); font-size: 13px; font-weight: 600; cursor: pointer; }
  .ta-more:hover { text-decoration: underline; }

  .ta-photos { display: flex; gap: 8px; margin-top: 12px; overflow-x: auto; scrollbar-width: none; }
  .ta-photos::-webkit-scrollbar { display: none; }
  .ta-photos a { flex-shrink: 0; }
  .ta-photos img { width: 84px; height: 84px; border-radius: 8px; object-fit: cover; display: block; transition: transform .25s ease; }
  .ta-photos a:hover img { transform: scale(1.04); }

  .ta-owner { margin-top: 12px; padding: 12px 14px; border-inline-start: 3px solid var(--ta-green); border-radius: 0 8px 8px 0; background: var(--ta-soft); font-size: 13px; line-height: 1.6; }
  .ta-owner b { display: block; margin-bottom: 4px; font-size: 12px; }

  .ta-more-reviews { display: flex; justify-content: center; margin-top: 16px; }

  /* ------------------------------------------------------------ RATING MARKS */
  .ta-bubbles { height: 16px; width: auto; display: block; }
  .ta-score .ta-bubbles { height: 20px; }
  .ta-dots { display: inline-flex; gap: 2px; }
  .ta-dots i { width: 12px; height: 12px; border-radius: 50%; border: 1.5px solid var(--ta-green); background: linear-gradient(to right, var(--ta-green) var(--f, 0%), transparent var(--f, 0%)); }
  [dir="rtl"] .ta-dots i { background: linear-gradient(to left, var(--ta-green) var(--f, 0%), transparent var(--f, 0%)); }

  /* ------------------------------------------------------------ FOOT */
  .ta-foot { display: flex; align-items: center; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--ta-line); color: var(--ta-muted); font-size: 12px; }
  .ta-foot a { color: inherit; font-weight: 600; }

  /* ------------------------------------------------------------ LOADING */
  .ta-skel { border-radius: 8px; background: linear-gradient(90deg, #f3f4f6 25%, #e9eaec 37%, #f3f4f6 63%); background-size: 400% 100%; animation: ta-shine 1.3s ease infinite; }
  @keyframes ta-shine { from { background-position: 100% 50%; } to { background-position: 0 50%; } }

  /* ------------------------------------------------------------ NARROW CARD */
  @container (max-width: 760px) {
    .ta-summary { grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr); }
    .ta-subs { grid-column: 1 / -1; grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  @container (max-width: 520px) {
    .ta { padding: 18px; }
    .ta-summary { grid-template-columns: minmax(0, 1fr); gap: 20px; }
    .ta-score { flex-direction: row; flex-wrap: wrap; justify-content: flex-start; align-items: center; column-gap: 12px; }
    .ta-subs { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ta-review { padding: 14px; }
  }
  @media (prefers-reduced-motion: reduce) {
    .ta-review, .ta-bar-fill, .ta-skel { animation: none; transition: none; }
  }
</style>
