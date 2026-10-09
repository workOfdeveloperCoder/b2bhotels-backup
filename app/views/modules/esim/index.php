<?php
// ============================================================================
// eSIM / PAGE
// The page of the eSIM service: a top card with a search box, every destination
// with its starting price (by region), how it works, and why to buy here.
// Built from the landing components (app/views/includes/landing/) and the
// featured card parts. The destinations come from /api/esim/catalog.
//
// The search box finds a destination: its card comes into view and is lit.
// A card opens the window with all its plans (plans-modal.php); a plan leads
// to the booking page.
// ============================================================================
@$SECURE or die('Access Denied!');

$esimCurrency = strtoupper($_SESSION['app_currency'] ?? 'USD');

require views . 'includes/featured/styles.php';
require views . 'includes/featured/modal.php';
require views . 'includes/landing/styles.php';
require views . 'modules/esim/plans-script.php';   // sets $esimWords

$esimRegions = [
    'middle_east'  => (string) T::region_middle_east,
    'africa'       => (string) T::region_africa,
    'europe'       => (string) T::region_europe,
    'asia_pacific' => (string) T::region_asia_pacific,
    'americas'     => (string) T::region_americas,
];

$esimSteps = [
    [(string) T::esim_step1_t, (string) T::esim_step1_d],
    [(string) T::esim_step2_t, (string) T::esim_step2_d],
    [(string) T::esim_step3_t, (string) T::esim_step3_d],
];

// reasons: class of the tile, icon, title, text ({n} = number of countries)
$esimReasons = [
    ['',         '<path d="M13 2 4.500 13.500H11l-1 8.500 8.500-11.500H12l1-8.500Z"/>', (string) T::esim_why1_t, (string) T::esim_why1_d],
    ['is-blue',  '<circle cx="12" cy="12" r="9.500"/><path d="M2.500 12h19"/><path d="M12 2.500c2.500 2.600 3.800 5.800 3.800 9.500s-1.300 6.900-3.800 9.500c-2.500-2.600-3.800-5.800-3.800-9.500S9.500 5.100 12 2.500Z"/>', (string) T::esim_why2_t, (string) T::esim_why2_d],
    ['is-green', '<path d="M12 2.500 4.500 5.400v5.900c0 4.700 3.100 8.600 7.500 10.200 4.400-1.600 7.500-5.500 7.500-10.200V5.400L12 2.500Z"/><path d="m8.600 12.100 2.400 2.400 4.400-4.600"/>', (string) T::esim_why3_t, (string) T::esim_why3_d],
    ['is-amber', '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><path d="M4 14a2 2 0 0 1 2-2h1v6H6a2 2 0 0 1-2-2v-2Zm16 0a2 2 0 0 0-2-2h-1v6h1a2 2 0 0 0 2-2v-2Z"/><path d="M19 18c0 1.700-1.800 3-4 3h-2"/>', (string) T::esim_why4_t, (string) T::esim_why4_d],
];

$esimChevron = '<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
?>
<style>[x-cloak]{display:none!important}</style>

<div class="ld"
     x-data="esimPage(<?= htmlspecialchars(json_encode(root . 'api/esim/catalog?currency=' . $esimCurrency), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($esimWords, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"
     @keydown.escape.window="close(); suggest = false">
  <div class="container">

    <!-- ============================================================ TOP CARD -->
    <div class="ld-card ld-hero">
      <div class="ld-hero-top">
        <div style="flex:1 1 auto;min-width:0">
          <span class="ld-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2.500" width="12" height="19" rx="2.500"/><path d="M11 18h2"/></svg>
            <?= htmlspecialchars((string) T::esim_badge) ?>
          </span>
          <h1 class="ld-h1"><?= htmlspecialchars((string) T::esim_h1) ?></h1>
          <p class="ld-lead" x-text="fill(<?= htmlspecialchars(json_encode((string) T::esim_lead, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"><?= htmlspecialchars(str_replace('{n}', '190', (string) T::esim_lead)) ?></p>
        </div>
        <div class="ld-stats">
          <div class="ld-stat"><b x-text="total() + '+'">190+</b><span><?= htmlspecialchars((string) T::countries) ?></span></div>
          <div class="ld-stat"><b><?= htmlspecialchars((string) T::esim_stat_instant) ?></b><span><?= htmlspecialchars((string) T::esim_stat_delivery) ?></span></div>
        </div>
      </div>

      <div class="ld-rule" aria-hidden="true"></div>

      <p class="ld-label"><?= htmlspecialchars((string) T::esim_find) ?></p>
      <div class="ld-search-wrap" @click.outside="suggest = false">
        <form class="ld-search" @submit.prevent="search()">
          <div class="ld-search-field">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12s-8-7-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            <input type="text" x-model="q" x-ref="q" autocomplete="off" placeholder="<?= htmlspecialchars((string) T::esim_where) ?>"
                   aria-label="<?= htmlspecialchars((string) T::esim_where) ?>"
                   @input="suggest = true; limit = step; hit = ''" @focus="suggest = true">
          </div>
          <button type="submit" class="btn">
            <?= htmlspecialchars((string) T::search) ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.200" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.500-3.500"/></svg>
          </button>
        </form>

        <!-- countries that match what is typed -->
        <div class="ld-suggest" x-show="suggest && q.trim().length > 0 && matches().length" x-transition:enter="pop-enter" x-transition:leave="pop-leave" style="display:none">
          <template x-for="item in matches().slice(0, 7)" :key="item.id">
            <button type="button" class="ld-suggest-row cursor-pointer hover:bg-gray-50" @click="choose(item)">
              <img :src="item.flag" alt="">
              <b x-text="item.country_name"></b>
              <span x-text="item.currency + ' ' + item.price"></span>
            </button>
          </template>
        </div>
      </div>
      <p class="ld-note" x-show="daysNote()" x-cloak>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.500"/><path d="M12 16v-4.500M12 8h.01"/></svg>
        <span x-text="daysNote()"></span>
      </p>
    </div>

    <!-- ============================================================ DESTINATIONS -->
    <div class="ld-card is-clip" id="esim-catalog" x-ref="catalog" style="scroll-margin-top:88px">
      <div class="ld-head">
        <div>
          <h2><?= htmlspecialchars((string) T::esim_destinations) ?></h2>
          <p x-text="loaded ? words.showing.replace('{n}', list().length) : ' '">&nbsp;</p>
        </div>
        <div class="fm-chips" x-show="loaded && all.length" x-cloak>
          <button type="button" class="fm-pick" :class="region === '' ? 'is-active' : ''" @click="pick('')"><?= htmlspecialchars((string) T::all) ?><i x-text="inRegion('')"></i></button>
          <?php foreach ($esimRegions as $esimKey => $esimName): ?>
          <button type="button" class="fm-pick" :class="region === '<?= $esimKey ?>' ? 'is-active' : ''" x-show="inRegion('<?= $esimKey ?>')" @click="pick('<?= $esimKey ?>')"><?= htmlspecialchars($esimName) ?><i x-text="inRegion('<?= $esimKey ?>')"></i></button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="ld-body">
        <!-- SKELETON — UNTIL THE CATALOG ARRIVES -->
        <div class="ld-grid" x-show="!loaded" aria-hidden="true">
          <?php for ($esimN = 0; $esimN < 8; $esimN++): ?>
          <div class="ld-dest is-skel">
            <div class="ld-dest-pic"><i style="height:148px;border-radius:12px"></i></div>
            <div class="ld-dest-main"><i style="width:55%;height:22px"></i><i style="width:64px;height:30px"></i></div>
            <div class="ld-dest-facts"><i style="height:30px;margin-inline-end:12px"></i><i style="height:30px;margin-inline-end:12px"></i><i style="height:30px"></i></div>
          </div>
          <?php endfor; ?>
        </div>

        <div class="ld-grid" x-show="loaded && list().length" x-cloak>
          <template x-for="(item, n) in list().slice(0, limit)" :key="item.id + region">
            <div class="ld-dest" role="button" tabindex="0" :class="{ 'is-hit': hit === item.id }" :data-dest="item.id" :style="{ '--n': n % step }" :aria-label="item.title"
                 @click="open(item, $el)" @keydown.enter.prevent="open(item, $el)" @keydown.space.prevent="open(item, $el)">
              <div class="ld-dest-pic">
                <div>
                  <img class="is-pic" x-show="item.image" :src="item.image" alt="" aria-hidden="true" loading="lazy" decoding="async" @error="$el.style.display = 'none'">
                  <img class="is-card" x-show="item.image" :src="item.image" alt="" loading="lazy" decoding="async" @error="$el.style.display = 'none'">
                  <span class="ld-dest-tag" x-show="item.featured"><?= htmlspecialchars((string) T::popular) ?></span>
                </div>
              </div>
              <div class="ld-dest-main">
                <div class="ld-dest-name">
                  <div>
                    <img :src="item.flag" alt="" loading="lazy" decoding="async">
                    <b x-text="item.country_name"></b>
                  </div>
                  <span class="ld-dest-line" x-text="(item.network ? item.network + ' · ' : '') + plansText(item.plans)"></span>
                </div>
                <div class="ld-dest-price">
                  <span><?= htmlspecialchars((string) T::from) ?></span>
                  <span class="fc-price"><small x-text="item.currency"></small><b x-text="item.price"></b></span>
                </div>
              </div>
              <div class="ld-dest-facts">
                <div><span><?= htmlspecialchars((string) T::network) ?></span><b x-text="item.network || '—'"></b></div>
                <div><span><?= htmlspecialchars((string) T::options) ?></span><b x-text="plansText(item.plans)"></b></div>
                <div><span><?= htmlspecialchars((string) T::esim_recharge) ?></span><b x-text="item.recharge ? '<?= htmlspecialchars((string) T::yes, ENT_QUOTES) ?>' : '<?= htmlspecialchars((string) T::no, ENT_QUOTES) ?>'"></b></div>
              </div>
            </div>
          </template>
        </div>

        <div class="ld-more" x-show="loaded && list().length > limit" x-cloak>
          <button type="button" class="btn light" @click="limit += step"><svg class="ld-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.200" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg><?= htmlspecialchars((string) T::show_more) ?></button>
        </div>

        <div class="ld-empty" x-show="loaded && !list().length" x-cloak>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.500-3.500M8.500 8.500l5 5M13.500 8.500l-5 5"/></svg>
          <div x-text="failed ? words.error : '<?= htmlspecialchars((string) T::esim_none, ENT_QUOTES) ?>'"></div>
          <button type="button" class="btn light" style="margin-top:14px" x-show="failed" @click="fetchAll()"><svg class="ld-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.200" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 15.500-6.200L21 8M21 3v5h-5M21 12a9 9 0 0 1-15.500 6.200L3 16M3 21v-5h5"/></svg><?= htmlspecialchars((string) T::try_again) ?></button>
          <button type="button" class="btn light" style="margin-top:14px" x-show="!failed && (q || region)" @click="q = ''; region = ''; hit = ''"><svg class="ld-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.200" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg><?= htmlspecialchars((string) T::clear) ?></button>
        </div>
      </div>
    </div>

    <!-- ============================================================ HOW IT WORKS -->
    <div class="ld-card is-clip">
      <div class="ld-head is-large">
        <div>
          <h2><?= htmlspecialchars((string) T::how_it_works) ?></h2>
          <p><?= htmlspecialchars((string) T::esim_how_sub) ?></p>
        </div>
      </div>
      <div class="ld-steps">
        <?php foreach ($esimSteps as $esimN => $esimStep): ?>
        <div class="ld-step">
          <i><?= $esimN + 1 ?></i>
          <div><b><?= htmlspecialchars($esimStep[0]) ?></b><p><?= htmlspecialchars($esimStep[1]) ?></p></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ============================================================ WHY HERE -->
    <div class="ld-card is-clip">
      <div class="ld-head is-large">
        <div>
          <h2><?= htmlspecialchars((string) T::esim_why) ?></h2>
          <p><?= htmlspecialchars((string) T::esim_why_sub) ?></p>
        </div>
      </div>
      <div class="ld-why">
        <?php foreach ($esimReasons as $esimReason): ?>
        <div class="ld-reason <?= $esimReason[0] ?>">
          <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $esimReason[1] ?></svg></i>
          <div>
            <b x-text="fill(<?= htmlspecialchars(json_encode($esimReason[2], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"><?= htmlspecialchars(str_replace('{n}', '190', $esimReason[2])) ?></b>
            <p><?= htmlspecialchars($esimReason[3]) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <?php include views . 'modules/esim/plans-modal.php'; ?>
</div>

<script data-cfasync="false">
function esimPage(url, words) {
    return {
        ...esimPlans(words),
        all: [], loaded: false, failed: false,
        q: '', region: '', suggest: false,
        hit: '',          // the destination the search box found: its card is lit
        step: 12, limit: 12,

        init() {
            this.fetchAll();
        },
        fetchAll() {
            this.loaded = false;
            this.failed = false;
            fetch(url).then(r => r.json())
                .then(d => {
                    if (!d.success) throw new Error(d.message || '');
                    const all = (d.data && d.data.countries) || [];
                    // popular destinations first, then by name
                    this.all = all.slice().sort((a, b) => (b.featured - a.featured) || a.country_name.localeCompare(b.country_name));
                })
                .catch(() => { this.all = []; this.failed = true; })
                .finally(() => { this.loaded = true; });
        },

        // number of countries, rounded down to a ten: "190+"
        total() {
            const n = Math.floor(this.all.length / 10) * 10;
            return n >= 10 ? n : 190;
        },
        fill(text) {
            return String(text).replace('{n}', this.total());
        },
        daysNote() {
            if (!this.all.length) return '';
            const a = Math.min(...this.all.map(i => i.days_min || 999));
            const b = Math.max(...this.all.map(i => i.days_max || 0));
            return a < b ? words.note.replace('{a}', a).replace('{b}', b) : '';
        },

        // countries that match what is typed (name or country code)
        matches() {
            const q = this.q.trim().toLowerCase();
            if (!q) return this.all;
            const starts = [], holds = [];
            this.all.forEach(i => {
                const name = i.country_name.toLowerCase();
                if (name.startsWith(q) || i.country_iso.toLowerCase() === q) starts.push(i);
                else if (name.includes(q)) holds.push(i);
            });
            return starts.concat(holds);
        },
        inRegion(region) {
            const found = this.matches();
            return region ? found.filter(i => i.region === region).length : found.length;
        },
        list() {
            const found = this.matches();
            return this.region ? found.filter(i => i.region === this.region) : found;
        },
        pick(region) {
            this.region = region;
            this.limit = this.step;
        },
        // THE SEARCH BOX FINDS, THE CARD OPENS. A destination chosen in the list under
        // the box, or the best match of what was typed, is brought into view and lit.
        choose(item) {
            this.q = item.country_name;
            this.show(item);
        },
        search() {
            const found = this.q.trim() ? this.matches() : [];
            this.show(found.length ? found[0] : null);
        },
        show(item) {
            this.suggest = false;
            this.region = '';
            this.limit = this.step;
            this.hit = item ? item.id : '';
            this.$nextTick(() => {
                const card = item && this.$el.querySelector('[data-dest="' + item.id + '"]');
                const to = card || this.$refs.catalog;
                if (!to) return;
                to.scrollIntoView({ behavior: 'smooth', block: card ? 'center' : 'start' });
                if (card) card.focus({ preventScroll: true });
            });
        },
    };
}
</script>
