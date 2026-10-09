<?php
// ============================================================================
// ADMIN MODULES LIST / STYLES  (PHPTRAVELS design system, pt README §47)
// Page header with KPI cells, a framed category rail, and the modules as one
// hairline grid (cells share their 1px borders). Square corners, one accent
// (the theme colour), status colours only for status. Plain CSS.
//
// COMPONENT CLASSES
//   .am                        page wrapper (tokens)
//   .am-head / .am-eyebrow / .am-title / .am-lead     page header
//   .am-kpis / .am-kpi         KPI cells: label (caps), big tabular number
//   .am-layout                 rail + main (one column under 1024px)
//   .am-rail / .am-cat         category list; .is-active (accent bar + tint); .am-count square count
//   .am-bar                    toolbar above the grid: category title, counts, search
//   .am-grid / .am-card        separate module cards; data-type filters by category
//   .am-logo / .am-name / .am-type / .am-status        card identity + status line
//   .am-facts / .am-fact       key-value cells (label caps, value tabular)
//   .am-actions / .am-btn      square actions (Settings = secondary, Docs = ghost)
//   .am-empty                  no module matches the search
// ============================================================================
@$SECURE or die('Access Denied!');
?>
<style>
  .am {
    --am-canvas: #f6f8fc; --am-surface: #ffffff; --am-hover: #f1f4fa; --am-line: #e4e9f2;
    --am-ink: #0b1220; --am-mute: #5b6578; --am-accent: var(--color-primary, #0052e7);
    --am-tint: color-mix(in srgb, var(--am-accent) 9%, var(--am-surface));
    color: var(--am-ink);
  }
  .am .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 350, 'GRAD' 0, 'opsz' 20; }

  /* ------------------------------------------------------------ HEAD */
  .am-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 20px 32px; padding: 28px 0 24px; }
  .am-eyebrow { margin: 0 0 8px; color: var(--am-accent); font-size: 11px; font-weight: 800; letter-spacing: .2em; text-transform: uppercase; }
  .am-title { margin: 0; font-size: 2rem; font-weight: 800; line-height: 1.1; letter-spacing: -.03em; }
  .am-lead { margin: 8px 0 0; max-width: 56ch; color: var(--am-mute); font-size: 15px; line-height: 1.55; }
  .am-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; background: var(--am-line); border: 1px solid var(--am-line); min-width: min(100%, 420px); }
  .am-kpi { padding: 14px 18px; background: var(--am-surface); }
  .am-kpi-label { display: block; color: var(--am-mute); font-size: 10.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; white-space: nowrap; }
  .am-kpi-value { display: block; margin-top: 4px; font-size: 28px; font-weight: 800; line-height: 1; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
  .am-kpi-value small { margin-inline-start: 3px; color: var(--am-mute); font-size: 13px; font-weight: 600; letter-spacing: 0; }
  .am-kpi.is-live .am-kpi-value { color: var(--am-accent); }

  /* ------------------------------------------------------------ LAYOUT + RAIL */
  .am-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 24px; padding-bottom: 48px; }
  @media (min-width: 1024px) { .am-layout { grid-template-columns: 248px minmax(0, 1fr); align-items: start; } }
  .am-rail { border: 1px solid var(--am-line); background: var(--am-surface); }
  @media (min-width: 1024px) { .am-rail { position: sticky; top: 16px; } }
  .am-rail-head { display: flex; align-items: center; justify-content: space-between; height: 44px; padding: 0 16px; border-bottom: 1px solid var(--am-line); background: var(--am-canvas); color: var(--am-mute); font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
  .am-rail-list { display: flex; flex-direction: column; padding: 4px 0; }
  .am-cat {
    position: relative; height: 40px; display: flex; align-items: center; gap: 12px; padding: 0 16px; border: 0; background: transparent;
    color: var(--am-mute); font: inherit; font-size: 13.5px; font-weight: 500; text-align: start; cursor: pointer; white-space: nowrap; transition: background-color .15s, color .15s;
  }
  .am-cat:hover { background: var(--am-hover); color: var(--am-ink); }
  .am-cat .material-symbols-outlined { font-size: 20px; }
  .am-cat-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
  .am-cat.is-active { background: var(--am-tint); color: var(--am-accent); font-weight: 600; }
  .am-cat.is-active::before { content: ''; position: absolute; inset-block: 0; inset-inline-start: 0; width: 2px; background: var(--am-accent); }
  .am-count { min-width: 34px; height: 20px; padding: 0 5px; display: inline-grid; place-items: center; border: 1px solid var(--am-line); background: var(--am-surface); color: var(--am-mute); font-size: 11px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .am-cat.is-active .am-count { border-color: color-mix(in srgb, var(--am-accent) 35%, var(--am-line)); color: var(--am-accent); }
  /* PHONES / TABLETS: THE RAIL BECOMES ONE SIDEWAYS ROW */
  @media (max-width: 1023px) {
    .am-rail-head { display: none; }
    .am-rail-list { flex-direction: row; overflow-x: auto; padding: 0; scrollbar-width: none; }
    .am-rail-list::-webkit-scrollbar { display: none; }
    .am-cat { flex-shrink: 0; border-inline-end: 1px solid var(--am-line); }
    .am-cat.is-active::before { inset-block: auto 0; inset-inline: 0; width: auto; height: 2px; }
  }

  /* ------------------------------------------------------------ TOOLBAR */
  .am-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 20px; margin-bottom: 16px; }
  .am-bar-title { margin: 0; font-size: 18px; font-weight: 800; letter-spacing: -.01em; }
  .am-bar-meta { margin: 2px 0 0; color: var(--am-mute); font-size: 13px; font-variant-numeric: tabular-nums; }
  .am-find { position: relative; width: min(100%, 280px); }
  .am-find .material-symbols-outlined { position: absolute; inset-inline-start: 12px; top: 50%; translate: 0 -50%; color: var(--am-mute); font-size: 18px; pointer-events: none; }
  .am-find input {
    width: 100%; height: 40px; padding-inline: 38px 12px; border: 1px solid var(--am-line); border-radius: 0; background: var(--am-surface);
    color: var(--am-ink); font-size: 14px; outline: none; transition: border-color .15s, box-shadow .15s;
  }
  .am-find input:focus { border-color: var(--am-accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--am-accent) 20%, transparent); }

  /* ------------------------------------------------------------ GRID + CARDS */
  /* EACH CARD DRAWS ITS END AND BOTTOM LINE, THE GRID ITS TOP AND START LINE: CELLS SHARE ONE
     LINE AND A LAST ROW THAT IS NOT FULL LEAVES THE PAGE BACKGROUND, NOT A GREY BLOCK */
  .am-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; }
  @media (min-width: 640px) { .am-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (min-width: 1680px) { .am-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
  .am-card { display: flex; flex-direction: column; min-width: 0; background: var(--am-surface); border: 1px solid var(--am-line); transition: border-color .15s; }
  .am-card:hover { border-color: color-mix(in srgb, var(--am-accent) 40%, var(--am-line)); }
  .am-card-top { display: flex; align-items: center; gap: 14px; padding: 18px 18px 14px; }
  .am-logo { width: 48px; height: 48px; flex-shrink: 0; display: grid; place-items: center; border: 1px solid var(--am-line); background: var(--am-surface); overflow: hidden; }
  .am-logo img { width: 100%; height: 100%; object-fit: contain; }
  .am-logo .material-symbols-outlined { color: var(--am-mute); font-size: 22px; }
  .am-ident { flex: 1; min-width: 0; }
  .am-name { margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 15px; font-weight: 700; letter-spacing: -.01em; }
  .am-type { margin: 3px 0 0; color: var(--am-mute); font-size: 10.5px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
  .am-status { display: inline-flex; align-items: center; gap: 6px; margin: 0 18px 14px; color: var(--am-mute); font-size: 12px; font-weight: 600; }
  .am-status i { width: 7px; height: 7px; border-radius: 50%; background: #94a3b8; }
  .am-status.is-on { color: #047857; }
  .am-status.is-on i { background: #059669; }
  .am-facts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); border-top: 1px solid var(--am-line); margin-top: auto; }
  .am-fact { min-width: 0; padding: 12px 18px; display: flex; flex-direction: column; justify-content: space-between; }
  .am-fact + .am-fact { border-inline-start: 1px solid var(--am-line); }
  .am-fact dt { color: var(--am-mute); font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; line-height: 1.3; }
  .am-fact dd { margin: 4px 0 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13.5px; font-weight: 700; font-variant-numeric: tabular-nums; }
  .am-actions { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid var(--am-line); }
  .am-btn {
    height: 44px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; background: transparent;
    color: var(--am-ink); font-size: 13.5px; font-weight: 600; text-decoration: none; transition: background-color .15s, color .15s;
  }
  .am-btn + .am-btn { border-inline-start: 1px solid var(--am-line); }
  .am-btn .material-symbols-outlined { font-size: 18px; }
  /* SETTINGS = PRIMARY (THEME COLOUR), DOCS = LIGHT GREY */
  .am-btn { background: var(--am-accent); color: #fff; }
  .am-btn:hover { background: color-mix(in srgb, var(--am-accent) 86%, #000); color: #fff; }
  .am-btn.is-ghost { background: var(--am-hover); color: var(--am-ink); }
  .am-btn.is-ghost:hover { background: #e6ebf3; color: var(--am-ink); }
  @media (max-width: 479px) { .am-fact { padding: 10px 12px; } .am-fact dt { white-space: normal; line-height: 1.3; } }
  .am :focus-visible { outline: 2px solid var(--am-accent); outline-offset: -2px; }
  .am-empty { padding: 48px 20px; border: 1px solid var(--am-line); background: var(--am-surface); text-align: center; color: var(--am-mute); font-size: 14px; }
  .am-empty .material-symbols-outlined { display: block; margin: 0 auto 8px; font-size: 28px; }
</style>
