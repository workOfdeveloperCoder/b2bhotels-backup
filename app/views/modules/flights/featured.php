<?php
// ============================================================================
// FLIGHTS / FEATURED
// Featured flights on the home page, as cards: airline, flight number, cabin,
// times and cities with the length of the flight between them, the price and
// a button. Built from the featured components (app/views/includes/featured/,
// card variant .fc-flight). The flights come from /api/flights/featured, asked
// only when the section is about to be seen. A card opens the search of that
// route on the date of the flight.
// ============================================================================
@$SECURE or die('Access Denied!');

// Only with the MANUAL flights module (parent "flights" row): featured flights are
// manual deals, so API suppliers alone (e.g. TBO) don't count.
$ffManual = false;
foreach (($GLOBALS['modules'] ?? []) as $ffModule) {
    if (($ffModule['name'] ?? '') === 'flights' && ($ffModule['type'] ?? '') === 'flights') {
        $ffManual = true;
        break;
    }
}
if (!$ffManual) {
    return;
}
$ffCurrency = strtoupper($_SESSION['app_currency'] ?? 'USD');
$ffPax = [
    'a' => (int) ($_SESSION['flight_adults'] ?? 1),
    'c' => (int) ($_SESSION['flight_children'] ?? 0),
    'i' => (int) ($_SESSION['flight_infants'] ?? 0),
];
$ffWords = [
    'cabins' => [
        'economy' => T::economy, 'premium-economy' => T::premium_economy, 'business' => T::business, 'first' => T::first,
    ],
    'direct' => T::direct, 'stop' => T::stop, 'stops' => T::stops,
];

require views . 'includes/featured/styles.php';
require views . 'includes/featured/script.php';

$ffPlane = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 16v-2l-8-5V3.5A1.5 1.5 0 0 0 11.5 2 1.5 1.5 0 0 0 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg>';
$ffArrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
// the same glass as on the search button of the hero
$ffChevron = '<svg class="fc-btn-icon" viewBox="0 0 22 22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.48 15.51l2.83 2.82M17.49 10.62a6.93 6.93 0 1 1-13.85 0 6.93 6.93 0 0 1 13.85 0Z"/></svg>';
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout so the scroll check has a real position; content shows once loaded -->
<section class="fs<?= fsBand() ?>" style="min-height:300px"
         x-data="flightsFeatured(<?= htmlspecialchars(json_encode(root . 'api/flights/featured?currency=' . $ffCurrency), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(root), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($ffPax), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($ffWords, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
  <div class="container">

    <!-- SKELETON — VISIBLE FROM FIRST PAINT UNTIL THE FLIGHTS ARRIVE -->
    <div x-show="!loaded" aria-hidden="true">
      <div class="fs-head">
        <span class="fs-skel-line" style="width:260px;max-width:70%;height:36px"></span>
        <span class="fs-skel-line" style="width:96px;height:20px"></span>
      </div>
      <div class="fs-track" style="overflow:hidden">
        <?php for ($ffN = 0; $ffN < 4; $ffN++): ?>
        <div class="fc fc-skel">
          <div class="fc-top"><i style="width:40px;height:40px;border-radius:50%"></i><i style="width:64px;height:22px;border-radius:999px"></i></div>
          <div class="fc-route"><i style="width:56px;height:20px"></i><i style="width:60px;height:14px;margin-top:4px"></i><i style="width:56px;height:20px"></i></div>
          <div class="fc-foot"><i style="width:96px;height:26px"></i><i style="width:88px;height:38px;border-radius:8px"></i></div>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <div x-show="items.length" x-cloak>
      <div class="fs-head">
        <h2 class="fs-title">
          <span class="fs-title-icon" aria-hidden="true"><?= $ffPlane ?></span>
          <span><?= htmlspecialchars(T::featured . ' ' . T::flights) ?></span>
        </h2>
        <a class="fs-more" href="<?= root ?>flights">
          <span><?= htmlspecialchars((string) T::view_all) ?></span><?= $ffArrow ?>
        </a>
      </div>

      <div class="fs-track" x-ref="track" @scroll.passive="sync()">
        <template x-for="(f, n) in items" :key="f.id">
          <a class="fc fc-flight" :href="link(f)" :style="{ '--n': n }">
            <div class="fc-top" style="align-items:center">
              <div class="fc-carrier">
                <span class="fc-tile">
                  <img x-show="f.airline.logo" :src="f.airline.logo" alt="" loading="lazy" decoding="async" @error="f.airline.logo = ''">
                  <template x-if="!f.airline.logo"><?= $ffPlane ?></template>
                </span>
                <div style="min-width:0">
                  <p class="fc-airline" x-text="f.airline.name"></p>
                  <p class="fc-flight-no" x-text="f.flight_number || f.airline.code"></p>
                </div>
              </div>
              <span class="fc-cabin" x-text="cabin(f)"></span>
            </div>

            <div class="fc-route">
              <div>
                <p class="fc-time" x-text="f.departure_time || (f.from.code || '').toUpperCase()"></p>
                <p class="fc-place" x-text="f.from.city || f.from.code"></p>
              </div>
              <div class="fc-leg">
                <span class="fc-leg-length" x-text="f.duration || (f.from.code + ' → ' + f.to.code)"></span>
                <span class="fc-leg-line" aria-hidden="true"><i></i><b></b><?= $ffPlane ?></span>
                <span class="fc-leg-stops" x-text="stops(f)"></span>
              </div>
              <div>
                <p class="fc-time" x-text="f.arrival_time || (f.to.code || '').toUpperCase()"></p>
                <p class="fc-place" x-text="f.to.city || f.to.code"></p>
              </div>
            </div>

            <div class="fc-foot">
              <div>
                <span class="fc-from"><?= htmlspecialchars((string) T::from) ?></span>
                <span class="fc-price"><small x-text="f.currency"></small><b x-text="money(f.price)"></b></span>
              </div>
              <span class="btn" tabindex="-1"><?= htmlspecialchars((string) T::search_now) ?><?= $ffChevron ?></span>
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
function flightsFeatured(url, root, pax, words) {
    return {
        ...featuredTrack(),
        items: [], loaded: false,

        init() {
            // Fetch the flights only when the section is about to enter the viewport.
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(url).then(r => r.json())
                        .then(d => { this.items = ((d.data && d.data.flights) || []).slice(0, 12); this.done(); })
                        .catch(() => this.done());
                }
            };
            addEventListener('scroll', f, { passive: true });
            f();
        },
        done() {
            this.loaded = true;
            this.$el.style.minHeight = '';           // release the reserved space
            if (!this.items.length) this.$el.style.display = 'none';
            this.$nextTick(() => this.sync());
        },
        money(v) {
            const n = Number(v) || 0;
            return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        cabin(f) {
            return words.cabins[f.cabin] || f.cabin || '';
        },
        stops(f) {
            if (f.direct || !f.stops) return words.direct;
            return f.stops + ' ' + (f.stops === 1 ? words.stop : words.stops);
        },
        // the flight search of this route on the date of the flight
        link(f) {
            return root + 'flights/' + (f.from.code || '').toLowerCase() + '/' + (f.to.code || '').toLowerCase()
                + '/oneway/' + f.cabin + '/' + f.date + '/' + pax.a + '/' + pax.c + '/' + pax.i;
        },
    };
}
</script>
