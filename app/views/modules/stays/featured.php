<?php
// ============================================================================
// STAYS / FEATURED
// Featured hotels on the home page, as cards: a framed picture with the
// discount, the guest score and the stars on it; the name, the place, the
// amenities; the price of a night and a button. A row of places above the
// cards narrows the row to one city. Built from the featured components
// (app/views/includes/featured/, card variant .fc-stay). The hotels come
// from /api/stays/featured, asked only when the section is about to be seen.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!in_array('stays', array_column($GLOBALS['modules'] ?? [], 'type'), true)) {
    return;
}
$sfCurrency = strtoupper($_SESSION['app_currency'] ?? 'USD');
$sfWords = ['all' => T::all, 'off' => T::off];

require views . 'includes/featured/styles.php';
require views . 'includes/featured/modal.php';    // .fm-chips / .fm-pick for the row of places
require views . 'includes/featured/script.php';

$sfArrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$sfChevron = '<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
$sfPin = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>';
$sfStar = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg>';
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout so the scroll check has a real position; content shows once loaded -->
<section class="fs<?= fsBand() ?>" style="min-height:440px"
         x-data="staysFeatured(<?= htmlspecialchars(json_encode(root . 'api/stays/featured?currency=' . $sfCurrency), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($sfWords, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
  <div class="container">

    <!-- SKELETON — VISIBLE FROM FIRST PAINT UNTIL THE HOTELS ARRIVE -->
    <div x-show="!loaded" aria-hidden="true">
      <div class="fs-head">
        <span class="fs-skel-line" style="width:260px;max-width:70%;height:36px"></span>
        <span class="fs-skel-line" style="width:96px;height:20px"></span>
      </div>
      <div class="fs-filters"><?php for ($sfN = 0; $sfN < 5; $sfN++): ?><span class="fs-skel-line" style="width:<?= 64 + $sfN * 12 ?>px;height:32px;border-radius:999px"></span><?php endfor; ?></div>
      <div class="fs-track" style="overflow:hidden">
        <?php for ($sfN = 0; $sfN < 4; $sfN++): ?>
        <div class="fc fc-skel fc-stay">
          <i style="aspect-ratio:4/3;border-radius:16px"></i>
          <div class="fc-body"><i style="width:70%;height:18px;margin-top:14px"></i><i style="width:50%;height:12px;margin-top:8px"></i></div>
          <div class="fc-foot"><i style="width:80px;height:26px"></i><i style="width:96px;height:38px;border-radius:8px"></i></div>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <div x-show="all.length" x-cloak>
      <div class="fs-head">
        <h2 class="fs-title">
          <span class="fs-title-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V8l9-5 9 5v13M3 21h18M9 21v-5h6v5M8 12h.01M12 12h.01M16 12h.01M8 16h.01M16 16h.01"/></svg>
          </span>
          <span><?= htmlspecialchars((string) T::featured_properties) ?></span>
        </h2>
        <a class="fs-more" href="<?= root ?>stays"><span><?= htmlspecialchars((string) T::view_all) ?></span><?= $sfArrow ?></a>
      </div>

      <!-- the places: one narrows the row, "All" widens it again -->
      <div class="fs-filters" x-show="locs.length > 1">
        <button type="button" class="fm-pick" :class="{ 'is-active': place === '' }" @click="pick('')"><span x-text="words.all"></span><i x-text="all.length"></i></button>
        <template x-for="loc in locs" :key="loc.slug">
          <button type="button" class="fm-pick" :class="{ 'is-active': place === loc.slug }" @click="pick(loc.slug)"><span x-text="loc.name"></span><i x-text="loc.count"></i></button>
        </template>
      </div>

      <div class="fs-track" x-ref="track" @scroll.passive="sync()">
        <template x-for="(h, n) in items" :key="place + '-' + h.id">
          <a class="fc fc-stay" :href="h.url" :style="{ '--n': Math.min(n, 7) }">
            <div class="fc-stay-photo">
              <img :src="h.image" alt="" loading="lazy" decoding="async" @error="$el.src = '<?= root ?>uploads/no_img.jpg'">
              <span class="fc-stay-sale" x-show="h.discount > 0" x-text="'-' + h.discount + '% ' + words.off"></span>
              <span class="fc-stay-score" x-show="h.rating > 0"><b x-text="Number(h.rating || 0).toFixed(1)"></b><span x-text="scoreText(h.rating)"></span></span>
              <span class="fc-stay-stars" aria-hidden="true"><template x-for="i in Math.min(Math.max(h.stars || 0, 0), 5)" :key="i"><?= $sfStar ?></template></span>
            </div>
            <div class="fc-body">
              <h3 class="fc-title" x-text="h.name"></h3>
              <p class="fc-stay-place" x-show="h.address || h.location"><?= $sfPin ?><span x-text="h.address || h.location"></span></p>
              <div class="fc-chips" x-show="(h.amenities || []).length">
                <template x-for="a in (h.amenities || []).slice(0, 3)" :key="a"><span class="fc-chip" x-text="a"></span></template>
              </div>
            </div>
            <div class="fc-foot">
              <div>
                <template x-if="h.min_room_price > 0">
                  <div>
                    <span class="fc-from"><?= htmlspecialchars((string) T::from) ?></span>
                    <span class="fc-price"><small x-text="h.currency"></small><b x-text="money(h.min_room_price)"></b></span>
                    <span class="fc-night"><?= htmlspecialchars((string) T::per_night) ?></span>
                  </div>
                </template>
                <template x-if="!(h.min_room_price > 0)">
                  <span class="fc-from"><?= htmlspecialchars((string) T::contact_for_price) ?></span>
                </template>
              </div>
              <span class="btn" tabindex="-1"><?= htmlspecialchars((string) T::view_details) ?><?= $sfChevron ?></span>
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
function staysFeatured(url, words) {
    return {
        ...featuredTrack(),
        words, all: [], locs: [], byLoc: {}, place: '', loaded: false,

        init() {
            // Fetch the hotels only when the section is about to enter the viewport.
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(url).then(r => r.json()).then(d => {
                        const x = d.data || {};
                        this.byLoc = x.hotels_by_location || {};
                        this.locs = (x.locations || []).map(l => ({ ...l, count: (this.byLoc[l.slug] || []).length })).filter(l => l.count);
                        this.all = this.locs.flatMap(l => this.byLoc[l.slug] || []);
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
        // a word for the guest score: 4.5 and up is excellent, 4 very good, 3.5 good
        scoreText(r) {
            r = Number(r) || 0;
            return r >= 4.5 ? <?= json_encode((string) T::excellent) ?> : (r >= 4 ? <?= json_encode((string) T::very_good) ?> : (r >= 3.5 ? <?= json_encode((string) T::good) ?> : ''));
        },
        money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    };
}
</script>
