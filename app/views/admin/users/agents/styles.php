<?php
// ============================================================================
// ADMIN / USERS / AGENTS / STYLES
// The agents page (Users > Agent): a card per agency instead of a table row.
// PHPTRAVELS admin design: square corners, 1px hairlines, the theme colour
// (--color-primary) as the only accent, no card shadows. Plain CSS, logical
// properties.
//
// COMPONENT CLASSES
//   .ag                     the page
//   .ag-head                title, lead and the actions (.ag-btn, .ag-btn.is-primary)
//   .ag-kpis / .ag-kpi      the four numbers
//   .ag-bar                 search, the status and country filters (.select.ag-filter), the count
//   .ag-grid / .ag-card     the cards: .ag-card-top (avatar, name, pills, switch),
//                           .ag-rows (contact rows), .ag-facts (wallet, credit, markup,
//                           bookings), .ag-card-foot (actions)
//   .ag-pill                status pill (.is-on, .is-off, .is-wait, .is-ban)
//   .ag-empty               nothing matches
// ============================================================================
@$SECURE or die('Access Denied!');

if (!empty($GLOBALS['__adminAgentsStyles'])) {
    return;
}
$GLOBALS['__adminAgentsStyles'] = true;
?>
<style>
  .ag {
    --ag-surface: #fff; --ag-canvas: #f6f8fc; --ag-line: #e4e9f2; --ag-ink: #0b1220; --ag-mute: #5b6578;
    --ag-accent: var(--color-primary, #0052e7); --ag-tint: color-mix(in srgb, var(--ag-accent) 9%, #fff);
    color: var(--ag-ink);
  }
  .ag [x-cloak] { display: none !important; }

  /* HEAD */
  .ag-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 22px; }
  .ag-crumbs { display: flex; gap: 6px; margin: 0 0 6px; color: var(--ag-mute); font-size: 12px; }
  .ag-crumbs a { color: var(--ag-mute); text-decoration: none; }
  .ag-crumbs a:hover { color: var(--ag-accent); }
  .ag-title { margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -.02em; line-height: 1.15; }
  .ag-lead { margin: 6px 0 0; color: var(--ag-mute); font-size: 14px; }
  .ag-actions { display: flex; flex-wrap: wrap; gap: 10px; }
  .ag-btn {
    display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border: 1px solid var(--ag-line);
    background: var(--ag-surface); color: var(--ag-ink); font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer;
    transition: border-color .15s, color .15s, background-color .15s;
  }
  .ag-btn:hover { border-color: color-mix(in srgb, var(--ag-accent) 45%, var(--ag-line)); color: var(--ag-accent); }
  .ag-btn.is-primary { border-color: var(--ag-accent); background: var(--ag-accent); color: #fff; }
  .ag-btn.is-primary:hover { background: color-mix(in srgb, var(--ag-accent) 86%, #000); color: #fff; }
  .ag-btn .material-symbols-outlined { font-size: 18px; }

  /* KPIS */
  .ag-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 22px; border: 1px solid var(--ag-line); background: var(--ag-surface); }
  .ag-kpi { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 18px 20px; min-width: 0; }
  .ag-kpi + .ag-kpi { border-inline-start: 1px solid var(--ag-line); }
  .ag-kpi dt { color: var(--ag-mute); font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
  .ag-kpi dd { margin: 8px 0 0; font-size: 28px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
  .ag-kpi small { display: block; margin-top: 8px; color: var(--ag-mute); font-size: 12px; }
  .ag-kpi-icon { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; width: 36px; height: 36px; background: var(--ag-tint); color: var(--ag-accent); }
  .ag-kpi-icon .material-symbols-outlined { font-size: 20px; }

  /* FILTER BAR */
  .ag-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 14px; border: 1px solid var(--ag-line); border-bottom: 0; background: var(--ag-surface); }
  .ag-search { position: relative; flex: 1 1 260px; max-width: 360px; }
  .ag-search .material-symbols-outlined { position: absolute; inset-inline-start: 12px; top: 50%; transform: translateY(-50%); color: var(--ag-mute); font-size: 18px; pointer-events: none; }
  /* search and filters are the shared .input / .select components; here only room for the icon and their width */
  .ag-search .input { padding-inline-start: 38px; }
  .ag-filter.select { width: auto; min-width: 160px; }
  .ag-count { margin-inline-start: auto; color: var(--ag-mute); font-size: 13px; white-space: nowrap; }

  /* GRID OF CARDS */
  .ag-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; padding: 16px; border: 1px solid var(--ag-line); background: var(--ag-canvas); }
  .ag-card { display: flex; flex-direction: column; min-width: 0; border: 1px solid var(--ag-line); background: var(--ag-surface); transition: border-color .15s; }
  .ag-card:hover { border-color: color-mix(in srgb, var(--ag-accent) 40%, var(--ag-line)); }
  .ag-card-top { display: flex; align-items: flex-start; gap: 12px; padding: 18px 18px 14px; }
  .ag-avatar {
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; width: 46px; height: 46px; overflow: hidden;
    font-size: 15px; font-weight: 800; letter-spacing: .02em;
  }
  .ag-avatar img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
  .ag-avatar:has(img) { border: 1px solid var(--ag-line); }
  .ag-who { flex: 1; min-width: 0; }
  .ag-name { margin: 0; overflow: hidden; font-size: 15px; font-weight: 800; line-height: 1.3; text-overflow: ellipsis; white-space: nowrap; }
  .ag-name a { color: inherit; text-decoration: none; }
  .ag-name a:hover { color: var(--ag-accent); }
  .ag-sub { margin: 2px 0 0; overflow: hidden; color: var(--ag-mute); font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
  .ag-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
  .ag-pill { display: inline-flex; align-items: center; gap: 5px; height: 22px; padding: 0 8px; border: 1px solid; font-size: 11px; font-weight: 700; }
  .ag-pill i { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
  .ag-pill.is-on { border-color: #a7f3d0; background: #ecfdf5; color: #047857; }
  .ag-pill.is-off { border-color: var(--ag-line); background: var(--ag-canvas); color: var(--ag-mute); }
  .ag-pill.is-wait { border-color: #fed7aa; background: #fff7ed; color: #c2410c; }
  .ag-pill.is-ban { border-color: #fecaca; background: #fef2f2; color: #b91c1c; }

  /* the status switch of the card */
  .ag-switch { position: relative; display: inline-flex; flex-shrink: 0; cursor: pointer; }
  .ag-switch input { position: absolute; width: 1px; height: 1px; opacity: 0; }
  .ag-switch span { width: 36px; height: 20px; border-radius: 999px; background: #cbd5e1; transition: background-color .2s; }
  .ag-switch span::after { content: ''; position: absolute; top: 2px; inset-inline-start: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .2s; }
  .ag-switch input:checked + span { background: var(--ag-accent); }
  .ag-switch input:checked + span::after { transform: translateX(16px); }
  [dir="rtl"] .ag-switch input:checked + span::after { transform: translateX(-16px); }
  .ag-switch input:focus-visible + span { outline: 2px solid var(--ag-accent); outline-offset: 2px; }
  .ag-switch input:disabled + span { opacity: .5; }

  .ag-rows { display: grid; gap: 8px; margin: 0; padding: 14px 18px; border-top: 1px solid var(--ag-line); list-style: none; }
  .ag-rows li { display: flex; align-items: center; gap: 10px; min-width: 0; color: var(--ag-ink); font-size: 13px; }
  .ag-rows li > span:last-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ag-rows .material-symbols-outlined { flex-shrink: 0; color: var(--ag-mute); font-size: 17px; }
  .ag-rows a { color: inherit; text-decoration: none; }
  .ag-rows a:hover { color: var(--ag-accent); }
  .ag-rows .is-none { color: var(--ag-mute); }

  .ag-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; border-top: 1px solid var(--ag-line); }
  .ag-fact { min-width: 0; padding: 12px 18px; }
  .ag-fact:nth-child(even) { border-inline-start: 1px solid var(--ag-line); }
  .ag-fact:nth-child(n+3) { border-top: 1px solid var(--ag-line); }
  .ag-fact dt { color: var(--ag-mute); font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
  .ag-fact dd { margin: 4px 0 0; overflow: hidden; font-size: 15px; font-weight: 800; text-overflow: ellipsis; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .ag-fact dd small { color: var(--ag-mute); font-size: 11px; font-weight: 600; }

  .ag-card-foot { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: auto; padding: 12px 18px; border-top: 1px solid var(--ag-line); background: var(--ag-canvas); }
  .ag-card-foot form { margin: 0; }
  .ag-act {
    display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border: 1px solid var(--ag-line); background: var(--ag-surface);
    color: var(--ag-ink); font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; transition: border-color .15s, color .15s, background-color .15s;
  }
  .ag-act:hover { border-color: color-mix(in srgb, var(--ag-accent) 45%, var(--ag-line)); color: var(--ag-accent); }
  .ag-act.is-primary { border-color: var(--ag-accent); background: var(--ag-accent); color: #fff; }
  .ag-act.is-primary:hover { background: color-mix(in srgb, var(--ag-accent) 86%, #000); color: #fff; }
  .ag-act.is-approve { border-color: #059669; background: #059669; color: #fff; }
  .ag-act.is-approve:hover { background: #047857; color: #fff; }
  .ag-act .material-symbols-outlined { font-size: 16px; }
  .ag-act.is-end { margin-inline-start: auto; }

  .ag-empty { grid-column: 1 / -1; padding: 48px 16px; text-align: center; color: var(--ag-mute); font-size: 14px; }
  .ag-empty .material-symbols-outlined { display: block; margin-bottom: 8px; font-size: 32px; }

  @media (max-width: 1023px) {
    .ag-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ag-kpi:nth-child(3) { border-inline-start: 0; }
    .ag-kpi:nth-child(n+3) { border-top: 1px solid var(--ag-line); }
  }
  @media (max-width: 639px) {
    .ag-title { font-size: 22px; }
    .ag-kpi { padding: 14px; }
    .ag-kpi dd { font-size: 22px; }
    .ag-kpi-icon { display: none; }
    .ag-search { max-width: none; flex-basis: 100%; }
    .ag-filter.select { flex: 1; min-width: 0; }
    .ag-count { width: 100%; margin: 0; }
    .ag-grid { grid-template-columns: minmax(0, 1fr); padding: 10px; gap: 10px; }
  }
</style>
