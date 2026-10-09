<?php
// ============================================================================
// eSIM / FEATURED
// Featured countries on the home page, as cards: flag, network, data and days
// on offer, the lowest price and a button. Four cards are in view; the buttons
// under the row bring the next four. Built from the featured components
// (app/views/includes/featured/). The facts come from /api/esim/featured, which
// is asked only when the section is about to be seen.
//
// A card opens the window with all plans of its country (plans-modal.php).
// Countries the supplier has no plans for are left out.
// ============================================================================
@$SECURE or die('Access Denied!');

// Render the section shell only when the eSIM module is enabled (same as before).
if (!in_array('esim', array_column($GLOBALS['modules'] ?? [], 'type'), true)) {
    return;
}
$esimCurrency = strtoupper($_SESSION['app_currency'] ?? 'USD');

require views . 'includes/featured/styles.php';
require views . 'includes/featured/modal.php';
require views . 'includes/featured/script.php';
require views . 'modules/esim/plans-script.php';   // sets $esimWords

$esimArrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$esimChevron = '<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout so the scroll check has a real position; content shows once loaded -->
<section class="fs<?= fsBand() ?>" style="min-height:330px"
         x-data="esimFeatured(<?= htmlspecialchars(json_encode(root . 'api/esim/featured?currency=' . $esimCurrency), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($esimWords, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"
         @keydown.escape.window="close()">
  <div class="container">

    <!-- SKELETON — VISIBLE FROM FIRST PAINT UNTIL THE FEATURED DATA ARRIVES -->
    <div x-show="!loaded" aria-hidden="true">
      <div class="fs-head">
        <span class="fs-skel-line" style="width:320px;max-width:70%;height:36px"></span>
        <span class="fs-skel-line" style="width:128px;height:20px"></span>
      </div>
      <div class="fs-track" style="overflow:hidden">
        <?php for ($esimN = 0; $esimN < 4; $esimN++): ?>
        <div class="fc fc-skel">
          <div class="fc-top"><i style="width:54px;height:54px;border-radius:12px"></i><i style="width:24px;height:16px"></i></div>
          <i style="width:66%;height:18px;margin-top:12px"></i>
          <div class="fc-chips"><i style="width:96px;height:24px"></i><i style="width:64px;height:24px"></i></div>
          <div class="fc-foot"><i style="width:80px;height:26px"></i><i style="width:104px;height:38px;border-radius:8px"></i></div>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <div x-show="items.length" x-cloak>
      <div class="fs-head">
        <h2 class="fs-title">
          <span class="fs-title-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7.200a2 2 0 0 1 1.400.600l3.800 3.800a2 2 0 0 1 .600 1.400V19a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><rect x="8.500" y="11.500" width="7" height="6" rx="1.200"/><path d="M12 11.500v6M8.500 14.500h7"/></svg>
          </span>
          <span><?= htmlspecialchars((string) T::esim_featured_title) ?></span>
        </h2>
        <a class="fs-more" href="<?= root ?>esim">
          <span x-text="browseText()"><?= htmlspecialchars((string) T::view_all) ?></span><?= $esimArrow ?>
        </a>
      </div>

      <div class="fs-track" x-ref="track" @scroll.passive="sync()">
        <template x-for="(item, n) in items" :key="item.id">
          <article class="fc" role="button" tabindex="0" :style="{ '--n': n }" :aria-label="item.title"
                   @click="open(item, $el)" @keydown.enter.prevent="open(item, $el)" @keydown.space.prevent="open(item, $el)">
            <div class="fc-top">
              <span class="fc-tile">
                <img :src="item.flag" alt="" loading="lazy" decoding="async" @error="$el.style.display = 'none'">
              </span>
              <span class="fc-signal" x-show="item.plans" :data-level="level(item)" aria-hidden="true">
                <span x-text="item.network"></span>
                <span class="fc-bars"><i></i><i></i><i></i><i></i></span>
              </span>
            </div>

            <h3 class="fc-title" x-text="item.country_name"></h3>

            <div class="fc-chips">
              <span class="fc-chip" x-show="dataText(item)" x-text="dataText(item)"></span>
              <span class="fc-chip" x-show="daysText(item)" x-text="daysText(item)"></span>
            </div>

            <div class="fc-foot">
              <div>
                <template x-if="item.price">
                  <div>
                    <span class="fc-from"><?= htmlspecialchars((string) T::from) ?></span>
                    <span class="fc-price"><small x-text="item.currency"></small><b x-text="item.price"></b></span>
                  </div>
                </template>
              </div>
              <button type="button" class="btn" tabindex="-1"><?= htmlspecialchars((string) T::choose_plan) ?><?= $esimChevron ?></button>
            </div>
          </article>
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

  <?php include views . 'modules/esim/plans-modal.php'; ?>
</section>

<script data-cfasync="false">
function esimFeatured(url, words) {
    return {
        ...featuredTrack(),
        ...esimPlans(words),
        items: [], countries: 0, loaded: false,

        init() {
            // Fetch the data only when the wrapper is about to enter the viewport.
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(url).then(r => r.json())
                        .then(d => {
                            // countries without plans are left out; if the supplier told us
                            // nothing at all, every country is shown with its name only
                            const all = (d.data && d.data.featured) || [];
                            const ready = all.filter(i => i.plans);
                            this.items = ready.length ? ready : all;
                            this.countries = (d.data && d.data.countries) || 0;
                            this.done();
                        })
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
        // "Browse 190+ destinations": the number of countries, rounded down to a ten
        browseText() {
            const n = Math.floor(this.countries / 10) * 10;
            return n >= 10 ? words.browse.replace('{n}', n) : words.all;
        },
    };
}
</script>
