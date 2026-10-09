<?php
// ============================================================================
// TOURS / FEATURED
// Popular tours on the home page, as posters: the picture fills the card and
// the words sit on it — place, rating, name, days and type, price. A row of
// places above the cards narrows the row to one destination. Built from the
// featured components (app/views/includes/featured/, card variant .fc-poster).
// The tours come from /api/tours/featured, asked only when the section is
// about to be seen. A card opens the page of the tour.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!in_array('tours', array_column($GLOBALS['modules'] ?? [], 'type'), true)) {
    return;
}
$tfCurrency = strtoupper($_SESSION['app_currency'] ?? 'USD');
$tfPax = [
    'adults'   => (int) ($_SESSION['tour_adults'] ?? $_SESSION['tour_travelers'] ?? 2),
    'children' => (int) ($_SESSION['tour_children'] ?? 0),
];
$tfWords = ['day' => T::day, 'days' => T::days, 'night' => T::night, 'nights' => T::nights, 'all' => T::all, 'off' => T::off];

require views . 'includes/featured/styles.php';
require views . 'includes/featured/modal.php';    // .fm-chips / .fm-pick for the row of places
require views . 'includes/featured/script.php';

$tfArrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$tfPin = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>';
$tfStar = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg>';
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout so the scroll check has a real position; content shows once loaded -->
<section class="fs<?= fsBand() ?>" style="min-height:420px"
         x-data="toursFeatured(<?= htmlspecialchars(json_encode(root . 'api/tours/featured?currency=' . $tfCurrency), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(root), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($tfPax), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($tfWords, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
  <div class="container">

    <!-- SKELETON — VISIBLE FROM FIRST PAINT UNTIL THE TOURS ARRIVE -->
    <div x-show="!loaded" aria-hidden="true">
      <div class="fs-head">
        <span class="fs-skel-line" style="width:240px;max-width:70%;height:36px"></span>
        <span class="fs-skel-line" style="width:96px;height:20px"></span>
      </div>
      <div class="fs-filters"><?php for ($tfN = 0; $tfN < 5; $tfN++): ?><span class="fs-skel-line" style="width:<?= 64 + $tfN * 12 ?>px;height:32px;border-radius:999px"></span><?php endfor; ?></div>
      <div class="fs-track" style="overflow:hidden">
        <?php for ($tfN = 0; $tfN < 4; $tfN++): ?>
        <div class="fc fc-skel" style="aspect-ratio:3/4;min-height:340px;background:#f1f1f1;border-color:#ececec"></div>
        <?php endfor; ?>
      </div>
    </div>

    <div x-show="all.length" x-cloak>
      <div class="fs-head">
        <h2 class="fs-title">
          <span class="fs-title-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2.2 6.3-4.8 1.7 2.2-6.3z"/></svg>
          </span>
          <span><?= htmlspecialchars((string) T::popular_tours) ?></span>
        </h2>
        <a class="fs-more" href="<?= root ?>tours"><span><?= htmlspecialchars((string) T::view_all) ?></span><?= $tfArrow ?></a>
      </div>

      <!-- the places: one narrows the row, "All" widens it again -->
      <div class="fs-filters" x-show="locs.length > 1">
        <button type="button" class="fm-pick" :class="{ 'is-active': place === '' }" @click="pick('')"><span x-text="words.all"></span><i x-text="all.length"></i></button>
        <template x-for="loc in locs" :key="loc.slug">
          <button type="button" class="fm-pick" :class="{ 'is-active': place === loc.slug }" @click="pick(loc.slug)"><span x-text="loc.name"></span><i x-text="loc.count"></i></button>
        </template>
      </div>

      <div class="fs-track" x-ref="track" @scroll.passive="sync()">
        <template x-for="(t, n) in items" :key="place + '-' + t.id">
          <a class="fc fc-poster" :href="link(t)" :style="{ '--n': Math.min(n, 7) }">
            <img :src="t.image" alt="" loading="lazy" decoding="async" @error="$el.src = '<?= root ?>uploads/no_img.jpg'">
            <div class="fc-poster-top">
              <span class="fc-poster-chip" x-show="t.location"><?= $tfPin ?><span x-text="t.location"></span></span>
              <span class="fc-poster-rate" x-show="t.rating > 0"><?= $tfStar ?><span x-text="Number(t.rating || 0).toFixed(1)"></span></span>
            </div>
            <span class="fc-poster-sale" x-show="t.discount > 0" x-text="'-' + t.discount + '% ' + words.off"></span>

            <h3 class="fc-title" x-text="t.name"></h3>
            <div class="fc-poster-facts">
              <span x-show="t.days" x-text="daysText(t)"></span>
              <i x-show="t.days && t.type"></i>
              <span x-show="t.type" x-text="t.type"></span>
            </div>
            <div class="fc-poster-foot">
              <div>
                <template x-if="t.price > 0">
                  <div>
                    <span class="fc-from"><?= htmlspecialchars((string) T::from) ?></span>
                    <span class="fc-price"><small x-text="t.currency"></small><b x-text="money(t.price)"></b></span>
                  </div>
                </template>
                <template x-if="!(t.price > 0)">
                  <span class="fc-from"><?= htmlspecialchars((string) T::contact_for_price) ?></span>
                </template>
              </div>
              <span class="fc-poster-go" aria-hidden="true"><?= $tfArrow ?></span>
            </div>
          </a>
        </template>
      </div>

      <!-- only when there is more than one screen of cards -->
      <div class="fs-nav" x-show="!(atStart && atEnd)">
        <button type="button" class="fs-nav-btn" @click="move(-1)" :disabled="atStart" aria-label="<?= htmlspecialchars((string) T::previous) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
        </button>
        <button type="button" class="fs-nav-btn" @click="move(1)" :disabled="atEnd" aria-label="<?= htmlspecialchars((string) T::next) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
      </div>
    </div>

  </div>
</section>

<script data-cfasync="false">
function toursFeatured(url, root, pax, words) {
    return {
        ...featuredTrack(),
        words, all: [], locs: [], byLoc: {}, place: '', date: '', loaded: false,

        init() {
            // Fetch the tours only when the section is about to enter the viewport.
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(url).then(r => r.json()).then(d => {
                        const x = d.data || {};
                        this.byLoc = x.tours_by_location || {};
                        this.locs = (x.locations || []).slice(0, 8).map(l => ({ ...l, count: (this.byLoc[l.slug] || []).length })).filter(l => l.count);
                        this.all = this.locs.flatMap(l => this.byLoc[l.slug] || []);
                        this.date = (x.default_dates && x.default_dates.date) || '';
                        this.done();
                    }).catch(() => this.done());
                }
            };
            addEventListener('scroll', f, { passive: true });
            f();
        },
        done() {
            this.loaded = true;
            this.$el.style.minHeight = '';           // release the reserved space
            if (!this.all.length) this.$el.style.display = 'none';
            this.$nextTick(() => this.sync());
        },
        // the cards in view: every place, or one
        get items() {
            return this.place ? (this.byLoc[this.place] || []) : this.all;
        },
        pick(slug) {
            this.place = slug;
            this.$nextTick(() => { if (this.$refs.track) this.$refs.track.scrollTo({ left: 0 }); this.sync(); });
        },
        daysText(t) {
            let s = t.days + ' ' + (t.days === 1 ? words.day : words.days);
            if (t.nights > 0) s += ' / ' + t.nights + ' ' + (t.nights === 1 ? words.night : words.nights);
            return s;
        },
        money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        link(t) { return root + 'tour/' + t.slug + '/' + t.id + '/tours/' + this.date + '/' + (t.days || 1) + '/' + pax.adults + '-' + pax.children; },
    };
}
</script>
