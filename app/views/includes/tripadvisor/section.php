<?php
// ============================================================================
// TRIPADVISOR / SECTION
// Tripadvisor ratings and reviews of one hotel. Nothing about the hotel is in
// the HTML: the card asks POST modules/others/tripadvisor/hotel when it comes
// near the screen (each call is billable) and fills itself. Tripadvisor's
// Review Implementation Policy requires this — review text must not be in the
// page source, and robots.txt blocks /modules/others/tripadvisor/.
// The card stays hidden when the module is off, the hotel is not on
// Tripadvisor, or the call fails.
//
// Needs one of:
//   $taLocationId   Tripadvisor location id saved on the hotel (must be known to
//                   the module: tripadvisor_locations)
//   $taHotel        ['name', 'city', 'country_code', 'lat', 'lng', 'supplier', 'hotel_id']
//                   to let the module find the hotel by name
// ============================================================================
@$SECURE or die('Access Denied!');

require views . 'includes/tripadvisor/styles.php';

$taLocationId = preg_replace('/\D+/', '', (string) ($taLocationId ?? ''));
$taHotel = is_array($taHotel ?? null) ? $taHotel : [];
if ($taLocationId === '' && trim((string) ($taHotel['name'] ?? '')) === '') {
    return;
}

$taPayload = $taLocationId !== '' ? ['location_id' => $taLocationId] : array_intersect_key($taHotel, array_flip(['name', 'city', 'country_code', 'lat', 'lng', 'supplier', 'hotel_id']));
$taPayload['language'] = (string) ($_SESSION['app_language'] ?? 'en');

$taWords = [
    'reviews'       => (string) T::ta_n_reviews,
    'readMore'      => (string) T::read_more,
    'readLess'      => (string) T::read_less,
    'stayed'        => (string) T::ta_stayed,
    'latest'        => (string) T::ta_latest,
    'ratings'       => [5 => (string) T::excellent, 4 => (string) T::very_good, 3 => (string) T::ta_average, 2 => (string) T::ta_poor, 1 => (string) T::ta_terrible],
    'trips'         => [
        'FAMILY'   => (string) T::ta_trip_family,
        'COUPLES'  => (string) T::ta_trip_couples,
        'SOLO'     => (string) T::ta_trip_solo,
        'BUSINESS' => (string) T::ta_trip_business,
        'FRIENDS'  => (string) T::ta_trip_friends,
    ],
    'status'        => ['TEMPORARILY_CLOSED' => (string) T::ta_temporarily_closed, 'CLOSED' => (string) T::ta_closed],
];
// A brand logo file (from Tripadvisor's brand kit) replaces the drawn mark when present.
$taLogo = null;
foreach (['uploads/modules/tripadvisor.svg', 'assets/img/modules/tripadvisor.svg', 'assets/img/modules/tripadvisor.png'] as $taFile) {
    if (is_file(dirname(__DIR__, 4) . '/' . $taFile)) { $taLogo = root . $taFile; break; }
}
?>
<section class="ta" x-data="taReviews(<?= htmlspecialchars(json_encode([
    'endpoint' => root . 'modules/others/tripadvisor/',
    'payload'  => $taPayload,
    'words'    => $taWords,
    'locale'   => str_replace('_', '-', (string) ($_SESSION['app_language'] ?? 'en')),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)" x-show="state !== 'empty'" x-cloak aria-live="polite">

  <div class="ta-head">
    <h3 class="ta-title"><?= htmlspecialchars((string) T::ta_traveller_reviews) ?></h3>
    <a class="ta-brand" :href="loc?.web_url || 'https://www.tripadvisor.com'" target="_blank" rel="noopener nofollow" aria-label="Tripadvisor">
      <?php if ($taLogo): ?>
        <img src="<?= htmlspecialchars($taLogo) ?>" alt="" loading="lazy">
      <?php else: ?>
        <span class="ta-brand-dot" aria-hidden="true"></span>
      <?php endif; ?>
      <span>Tripadvisor</span>
    </a>
  </div>

  <!-- loading -->
  <template x-if="state === 'loading'">
    <div>
      <div class="ta-summary">
        <div class="ta-score"><div class="ta-skel" style="width:90px;height:44px"></div><div class="ta-skel" style="width:120px;height:14px"></div></div>
        <div class="ta-bars"><template x-for="i in 5"><div class="ta-skel" style="height:10px"></div></template></div>
        <div class="ta-subs"><template x-for="i in 6"><div class="ta-skel" style="height:22px"></div></template></div>
      </div>
      <div class="ta-list"><template x-for="i in 2"><div class="ta-skel" style="height:150px;border-radius:12px"></div></template></div>
    </div>
  </template>

  <template x-if="state === 'ready'">
    <div>
      <!-- summary -->
      <div class="ta-summary">
        <div class="ta-score">
          <div class="ta-score-num"><span x-text="fmtRating(loc.rating)"></span><small>/5</small></div>
          <span x-html="bubbles(loc.rating, loc.rating_image_url)"></span>
          <div class="ta-count" x-text="countText(loc.num_reviews)"></div>
          <span class="ta-status" x-show="statusText()" x-text="statusText()"></span>
        </div>

        <div class="ta-bars" x-show="loc.breakdown?.length">
          <template x-for="b in loc.breakdown" :key="b.rating">
            <div class="ta-bar">
              <span class="ta-bar-label" x-text="words.ratings[b.rating] || b.label"></span>
              <span class="ta-track"><span class="ta-bar-fill" :style="`width:${shown ? pct(b.count) : 0}%`"></span></span>
              <span class="ta-bar-count" x-text="num(b.count)"></span>
            </div>
          </template>
        </div>

        <div class="ta-subs" x-show="loc.subratings?.length">
          <template x-for="s in loc.subratings" :key="s.type">
            <div class="ta-sub">
              <div class="ta-sub-row"><span x-text="s.label"></span><b x-text="fmtRating(s.rating)"></b></div>
              <span class="ta-track"><span class="ta-bar-fill" :style="`width:${shown ? (s.rating / 5 * 100) : 0}%`"></span></span>
            </div>
          </template>
        </div>
      </div>

      <div class="ta-actions">
        <span class="ta-actions-note" x-show="reviews.length" x-text="latestText()"></span>
        <div class="ta-links">
          <a class="ta-link" x-show="loc.web_url" :href="loc.web_url" target="_blank" rel="noopener nofollow">
            <?= htmlspecialchars((string) T::ta_read_all) ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>
          </a>
          <a class="ta-link" x-show="loc.write_review_url" :href="loc.write_review_url" target="_blank" rel="noopener nofollow"><?= htmlspecialchars((string) T::ta_write_review) ?></a>
        </div>
      </div>

      <!-- reviews -->
      <div class="ta-list">
        <template x-for="r in reviews" :key="r.id">
          <article class="ta-review" x-data="{ open: false }">
            <div class="ta-user">
              <template x-if="r.user.avatar"><img class="ta-avatar" :src="r.user.avatar" alt="" loading="lazy"></template>
              <template x-if="!r.user.avatar"><span class="ta-avatar" x-text="(r.user.name || '?').charAt(0).toUpperCase()"></span></template>
              <div>
                <div class="ta-user-name" x-text="r.user.name"></div>
                <div class="ta-user-place" x-show="r.user.location" x-text="r.user.location"></div>
              </div>
              <a class="ta-review-link" x-show="r.url" :href="r.url" target="_blank" rel="noopener nofollow" aria-label="Tripadvisor">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
              </a>
            </div>

            <div class="ta-meta">
              <span x-html="bubbles(r.rating, r.rating_image_url)"></span>
              <span x-text="monthYear(r.published_date)"></span>
              <span class="ta-chip" x-show="words.trips[r.trip_type]" x-text="words.trips[r.trip_type]"></span>
              <span x-show="r.travel_date" x-text="`${words.stayed}: ${monthYear(r.travel_date)}`"></span>
            </div>

            <h4 class="ta-review-title" x-show="r.title" x-text="r.title"></h4>
            <p class="ta-text" :class="open && 'is-open'" x-text="r.text"></p>
            <button type="button" class="ta-more" x-show="(r.text || '').length > 300" @click="open = !open" x-text="open ? words.readLess : words.readMore"></button>

            <div class="ta-photos" x-show="r.photos?.length">
              <template x-for="(p, i) in r.photos.slice(0, 6)" :key="i">
                <a :href="r.url || p" target="_blank" rel="noopener nofollow"><img :src="p" alt="" loading="lazy"></a>
              </template>
            </div>

            <div class="ta-owner" x-show="r.owner_response">
              <b x-text="`<?= htmlspecialchars((string) T::ta_owner_response, ENT_QUOTES) ?>${r.owner_response?.author ? ' · ' + r.owner_response.author : ''}`"></b>
              <span x-text="r.owner_response?.text"></span>
            </div>
          </article>
        </template>
      </div>

      <div class="ta-more-reviews" x-show="paging.has_more">
        <button type="button" class="btn light" @click="more()" :disabled="busy">
          <span x-show="!busy"><?= htmlspecialchars((string) T::ta_more_reviews) ?></span>
          <span x-show="busy" class="material-symbols-outlined animate-spin" style="font-size:18px">progress_activity</span>
        </button>
      </div>

      <div class="ta-foot">
        <span><?= htmlspecialchars((string) T::ta_from_tripadvisor) ?></span>
        <a :href="loc.web_url || 'https://www.tripadvisor.com'" target="_blank" rel="noopener nofollow">Tripadvisor</a>
      </div>
    </div>
  </template>
</section>

<?php if (empty($GLOBALS['__tripadvisorScript'])): $GLOBALS['__tripadvisorScript'] = true; ?>
<script>
function taReviews(cfg) {
  return {
    ...cfg,
    state: 'idle',          // idle → loading → ready | empty
    shown: false,           // bars grow once the numbers are in
    busy: false,
    loc: null,
    reviews: [],
    paging: { page: 1, has_more: false },

    init() {
      // Each call is billable: ask only when the card comes near the screen.
      const io = new IntersectionObserver((entries) => {
        if (entries.some(e => e.isIntersecting)) { io.disconnect(); this.load(); }
      }, { rootMargin: '400px 0px' });
      io.observe(this.$el);
    },

    async post(path, body) {
      const res = await fetch(this.endpoint + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      return res.json();
    },

    async load() {
      this.state = 'loading';
      try {
        const data = await this.post('hotel', this.payload);
        if (!data.success || !data.found || !data.data?.location) {
          if (!data.success) console.warn('Tripadvisor:', data.message);
          this.state = 'empty';
          return;
        }
        this.loc = data.data.location;
        this.reviews = data.data.reviews || [];
        this.paging = data.data.paging || this.paging;
        this.payload.location_id = this.loc.location_id;
        this.state = this.loc.rating || this.reviews.length ? 'ready' : 'empty';
        this.$nextTick(() => setTimeout(() => { this.shown = true; }, 60));
      } catch (e) {
        console.warn('Tripadvisor:', e);
        this.state = 'empty';
      }
    },

    async more() {
      this.busy = true;
      try {
        const data = await this.post('reviews', { location_id: this.loc.location_id, language: this.payload.language, page: (this.paging.page || 1) + 1 });
        if (data.success) {
          this.reviews.push(...(data.data.reviews || []));
          this.paging = data.data.paging;
        } else {
          this.paging.has_more = false;
        }
      } finally {
        this.busy = false;
      }
    },

    num(n) { return Number(n || 0).toLocaleString(this.locale); },
    fmtRating(r) { return r ? Number(r).toFixed(1) : '–'; },
    countText(n) { return this.words.reviews.replace('{n}', this.num(n)); },
    pct(count) {
      const total = (this.loc.breakdown || []).reduce((t, b) => t + (b.count || 0), 0);
      return total ? Math.max(1, Math.round(count / total * 100)) : 0;
    },
    statusText() { return this.words.status[this.loc?.status] || ''; },
    latestText() { return this.words.latest.replace('{n}', this.num(this.reviews.length)).replace('{total}', this.num(this.loc.num_reviews)); },
    monthYear(v) {
      if (!v) return '';
      const d = new Date(/^\d{4}-\d{2}$/.test(v) ? v + '-01T00:00:00' : v);
      if (isNaN(d)) return v;
      try { return d.toLocaleDateString(this.locale, { month: 'short', year: 'numeric' }); }
      catch (e) { return d.toLocaleDateString('en', { month: 'short', year: 'numeric' }); }
    },
    // Tripadvisor's own rating image when it sends one, else five drawn bubbles.
    bubbles(rating, img) {
      const label = `${this.fmtRating(rating)} / 5`;
      if (img) return `<img class="ta-bubbles" src="${img}" alt="${label}" loading="lazy">`;
      let html = `<span class="ta-dots" role="img" aria-label="${label}">`;
      for (let i = 1; i <= 5; i++) {
        const f = Math.max(0, Math.min(1, (rating || 0) - (i - 1)));
        html += `<i style="--f:${Math.round(f * 100)}%"></i>`;
      }
      return html + '</span>';
    },
  };
}
</script>
<?php endif; ?>
