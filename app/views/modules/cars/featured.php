<?php
@$SECURE or die('Access Denied!');

// Render the section shell only when the cars module is enabled.
if (!in_array('cars', array_column($GLOBALS['modules'] ?? [], 'type'), true)) {
    return;
}
$cfCurrency = strtoupper($_SESSION['app_currency'] ?? 'USD');
$cfWords = ['all' => T::all, 'seats' => T::seats, 'bags' => T::bags, 'day' => T::day, 'transfer' => T::transfer];

require views . 'includes/featured/styles.php';
require views . 'includes/featured/modal.php';    // .fm-chips / .fm-pick for the row of places
require views . 'includes/featured/script.php';

$cfArrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$cfChevron = '<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
$cfStar = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2.5 2.9 6.1 6.6.8-4.9 4.6 1.3 6.5L12 17.3l-5.9 3.2 1.3-6.5L2.5 9.4l6.6-.8z"/></svg>';
$cfSpec = [
    'seat' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/></svg>',
    'gear' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="6" cy="6" r="2"/><circle cx="18" cy="6" r="2"/><circle cx="6" cy="18" r="2"/><path d="M6 8v8M6 12h12V8"/></svg>',
    'bag'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="7" width="16" height="13" rx="2"/><path d="M9 7V5a3 3 0 0 1 6 0v2"/></svg>',
    'fuel' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 21V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v16M3 21h14M15 9h2a2 2 0 0 1 2 2v6a1.5 1.5 0 0 0 3 0v-7l-3-3M8 7h4"/></svg>',
];
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout so the scroll check has a real position; content shows once loaded -->
<div style="min-height:420px" x-data="featuredCarsData(<?= htmlspecialchars(json_encode(root . 'api/cars/featured?currency=' . $cfCurrency), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($cfWords, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)" x-init="initFeatured()">
    <section class="fs<?= fsBand() ?>">
        <div class="container">

            <!-- SKELETON — VISIBLE FROM FIRST PAINT UNTIL THE CARS ARRIVE -->
            <div x-show="!loaded" aria-hidden="true">
              <div class="fs-head">
                <span class="fs-skel-line" style="width:220px;max-width:70%;height:36px"></span>
                <span class="fs-skel-line" style="width:96px;height:20px"></span>
              </div>
              <div class="fs-filters"><?php for ($cfN = 0; $cfN < 5; $cfN++): ?><span class="fs-skel-line" style="width:<?= 64 + $cfN * 12 ?>px;height:32px;border-radius:999px"></span><?php endfor; ?></div>
              <div class="fs-track" style="overflow:hidden">
                <?php for ($cfN = 0; $cfN < 4; $cfN++): ?>
                <div class="fc fc-skel fc-car">
                  <i style="aspect-ratio:16/10;border-radius:16px"></i>
                  <div class="fc-body"><i style="width:70%;height:18px;margin-top:14px"></i><i style="width:45%;height:12px;margin-top:8px"></i></div>
                  <div class="fc-foot"><i style="width:80px;height:26px"></i><i style="width:96px;height:38px;border-radius:8px"></i></div>
                </div>
                <?php endfor; ?>
              </div>
            </div>

            <div x-show="all.length" x-cloak>
              <div class="fs-head">
                <h2 class="fs-title">
                  <span class="fs-title-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v-3.5a2 2 0 0 1 .3-1L6.5 7A2 2 0 0 1 8.3 6h7.4a2 2 0 0 1 1.8 1l2.2 4.5a2 2 0 0 1 .3 1V16M4 16h16M4 16v2M20 16v2M7 12h10"/><circle cx="7.5" cy="16" r="1"/><circle cx="16.5" cy="16" r="1"/></svg>
                  </span>
                  <span><?= htmlspecialchars((string) T::featured_cars) ?></span>
                </h2>
                <a class="fs-more" href="<?= root ?>cars"><span><?= htmlspecialchars((string) T::view_all) ?></span><?= $cfArrow ?></a>
              </div>

              <!-- the places: one narrows the row, "All" widens it again -->
              <div class="fs-filters" x-show="locs.length > 1">
                <button type="button" class="fm-pick" :class="{ 'is-active': place === '' }" @click="pick('')"><span x-text="words.all"></span><i x-text="all.length"></i></button>
                <template x-for="loc in locs" :key="loc.slug">
                  <button type="button" class="fm-pick" :class="{ 'is-active': place === loc.slug }" @click="pick(loc.slug)"><span x-text="loc.name"></span><i x-text="loc.count"></i></button>
                </template>
              </div>

              <div class="fs-track" x-ref="track" @scroll.passive="sync()">
                <template x-for="(c, n) in items" :key="place + '-' + c.id">
                  <article class="fc fc-car" role="button" tabindex="0" :style="{ '--n': Math.min(n, 7) }"
                           @click="openBookingModal({ ...c, price: c.display_price_per_day })"
                           @keydown.enter.prevent="openBookingModal({ ...c, price: c.display_price_per_day })">
                    <div class="fc-car-photo">
                      <img :src="c.image" alt="" loading="lazy" decoding="async" @error="$el.src = '<?= root ?>uploads/no_img.jpg'">
                      <span class="fc-car-kind" x-text="c.service_type === 'transfer' ? words.transfer : (c.category || c.car_type || '')" x-show="c.service_type === 'transfer' || c.category || c.car_type"></span>
                      <span class="fc-car-rate" x-show="c.rating > 0"><?= $cfStar ?><span x-text="Number(c.rating || 0).toFixed(1)"></span></span>
                    </div>
                    <div class="fc-body">
                      <h3 class="fc-title" x-text="c.name"></h3>
                      <p class="fc-car-sub"><span x-text="[c.brand, c.model, c.year].filter(Boolean).join(' ')"></span><template x-if="c.location_name || c.location_city"><span> · <span x-text="c.location_name || c.location_city"></span></span></template></p>
                      <ul class="fc-car-specs">
                        <li><?= $cfSpec['seat'] ?><span x-text="c.passengers + ' ' + words.seats"></span></li>
                        <li><?= $cfSpec['gear'] ?><span x-text="c.transmission"></span></li>
                        <li><?= $cfSpec['bag'] ?><span x-text="c.baggage + ' ' + words.bags"></span></li>
                        <li><?= $cfSpec['fuel'] ?><span x-text="c.fuel_type || 'Petrol'"></span></li>
                      </ul>
                    </div>
                    <div class="fc-foot">
                      <div>
                        <span class="fc-from"><?= htmlspecialchars((string) T::from) ?></span>
                        <span class="fc-price"><small x-text="c.currency"></small><b x-text="money(c.display_price_per_day)"></b><span class="fc-night" x-text="c.service_type === 'transfer' ? words.transfer : '/ ' + words.day"></span></span>
                      </div>
                      <button type="button" class="btn" tabindex="-1"><?= htmlspecialchars((string) T::book_now) ?><?= $cfChevron ?></button>
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

        <!-- ============================================================================
             BOOKING MODAL - Collect dates/times/location before booking
             ============================================================================ -->
        <template x-teleport="body">
        <div x-cloak x-show="modalOpen" @keydown.escape.window="closeModal()"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-[100000] flex items-center justify-center p-4" style="display:none;">

            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="closeModal()"></div>

            <!-- Modal Content -->
            <div @click.stop
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                 class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden z-10">
                
                <!-- Modal Header -->
                <div class="p-5 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined text-blue-600">directions_car</span>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-900 dark:text-gray-100 text-lg" x-text="selectedCar?.name || '<?=T::book?>'"></h3>
                                <p class="text-xs text-gray-500" x-text="(selectedCar?.brand || '') + ' ' + (selectedCar?.model || '') + ' ' + (selectedCar?.year || '')"></p>
                            </div>
                        </div>
                        <button @click="closeModal()" class="w-8 h-8 rounded-full hover:bg-gray-100 flex items-center justify-center transition-colors">
                            <span class="material-symbols-outlined text-gray-400">close</span>
                        </button>
                    </div>
                </div>

                <!-- Modal Body -->
                <div class="p-5 space-y-4 max-h-[70vh] overflow-y-auto">

                    <!-- Alert -->
                    <div x-show="modalAlert" x-transition class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm flex items-center gap-2" style="display:none;">
                        <span class="material-symbols-outlined text-sm">error</span>
                        <span x-text="modalAlert"></span>
                    </div>

                    <!-- Service Type Badge -->
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm" :class="selectedCar?.service_type === 'transfer' ? 'text-teal-600' : 'text-blue-600'"
                              x-text="selectedCar?.service_type === 'transfer' ? 'airport_shuttle' : 'directions_car'"></span>
                        <span class="text-sm font-semibold capitalize" x-text="selectedCar?.service_type === 'transfer' ? '<?=T::airport?> <?=T::transfer?>' : '<?=T::car?> <?=T::rental?>'"></span>
                    </div>

                    <!-- Pickup Location (autocomplete) -->
                    <div class="form-control relative">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">location_on</span>
                            <span x-text="selectedCar?.service_type === 'transfer' ? '<?=T::from?> <?=T::location?>' : '<?=T::pickup?> <?=T::location?>'"></span>
                        </label>
                        <div class="relative">
                            <input type="text" x-ref="modalPickupInput" x-model="mPickupSearch" @input="handleModalPickupInput()"
                                placeholder="<?=T::city?> <?=T::or?> <?=T::airport?>" class="input w-full" autocomplete="off">
                            <div x-show="mPickupLoading" class="absolute right-2 top-1/2 -translate-y-1/2" style="display:none;">
                                <svg class="animate-spin h-4 w-4 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                            </div>
                            <button type="button" x-show="mPickupSelected" @click="clearModalPickup()" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" style="display:none;">
                                <span class="material-symbols-outlined text-sm">close</span>
                            </button>
                            <div x-show="mPickupResults.length > 0 && !mPickupSelected" x-transition class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-auto" style="display:none;">
                                <template x-for="loc in mPickupResults" :key="loc.id">
                                    <div @click="selectModalPickup(loc)" class="px-4 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0">
                                        <div class="flex items-start gap-2">
                                            <span class="material-symbols-outlined text-sm text-gray-400 mt-0.5" x-text="loc.type === 'airport' ? 'flight_takeoff' : 'location_on'"></span>
                                            <div class="font-medium text-sm" x-text="loc.display"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Dropoff Location -->
                    <div class="form-control relative">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm">location_on</span>
                            <span x-text="selectedCar?.service_type === 'transfer' ? '<?=T::to?> <?=T::location?>' : '<?=T::return?> <?=T::location?>'"></span>
                        </label>
                        <div class="relative">
                            <input type="text" x-ref="modalDropoffInput" x-model="mDropoffSearch" @input="handleModalDropoffInput()"
                                :placeholder="selectedCar?.service_type === 'transfer' ? '<?=T::city?> <?=T::or?> <?=T::airport?>' : '<?=T::same?> <?=T::as?> <?=T::pickup?>'"
                                class="input w-full" autocomplete="off">
                            <div x-show="mDropoffLoading" class="absolute right-2 top-1/2 -translate-y-1/2" style="display:none;">
                                <svg class="animate-spin h-4 w-4 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                            </div>
                            <button type="button" x-show="mDropoffSearch" @click="clearModalDropoff()" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" style="display:none;">
                                <span class="material-symbols-outlined text-sm">close</span>
                            </button>
                            <div x-show="mDropoffResults.length > 0 && !mDropoffSelected" x-transition class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-auto" style="display:none;">
                                <template x-for="loc in mDropoffResults" :key="loc.id">
                                    <div @click="selectModalDropoff(loc)" class="px-4 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-0">
                                        <div class="flex items-start gap-2">
                                            <span class="material-symbols-outlined text-sm text-gray-400 mt-0.5" x-text="loc.type === 'airport' ? 'flight_takeoff' : 'location_on'"></span>
                                            <div class="font-medium text-sm" x-text="loc.display"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Pickup & Dropoff Date+Time in one row -->
                    <div class="grid grid-cols-2 gap-3">
                        <!-- Pickup group -->
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">calendar_today</span>
                                <?=T::pickup?>
                            </label>
                            <div class="flex gap-1.5">
                                <input type="text" x-ref="modalPickupDate" class="FeaturedCarsPickup input w-full" readonly
                                    value="<?=date('d-m-Y', strtotime('+1 day'))?>">
                                <input type="text" x-ref="modalPickupTime" class="FeaturedCarsPickupTime input w-20 text-center shrink-0" readonly value="10:00">
                            </div>
                        </div>
                        <!-- Dropoff group -->
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">calendar_today</span>
                                <?=T::dropoff?>
                            </label>
                            <div class="flex gap-1.5">
                                <input type="text" x-ref="modalReturnDate" class="FeaturedCarsReturn input w-full" readonly
                                    value="<?=date('d-m-Y', strtotime('+3 days'))?>">
                                <input type="text" x-ref="modalReturnTime" class="FeaturedCarsReturnTime input w-20 text-center shrink-0" readonly value="10:00">
                            </div>
                        </div>
                    </div>

                    <!-- Transfer extras: Travellers -->
                    <div x-show="selectedCar?.service_type === 'transfer'" class="grid grid-cols-2 gap-3">
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">group</span>
                                <?=T::travellers?>
                            </label>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="if(mTravellers>1) mTravellers--" class="w-9 h-9 rounded-lg border border-gray-300 hover:border-blue-500 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-sm">remove</span>
                                </button>
                                <span class="w-8 text-center font-semibold" x-text="mTravellers"></span>
                                <button type="button" @click="if(mTravellers<20) mTravellers++" class="w-9 h-9 rounded-lg border border-gray-300 hover:border-blue-500 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-sm">add</span>
                                </button>
                            </div>
                        </div>
                        <div class="form-control">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">person</span>
                                <?=T::driver?> <?=T::age?>
                            </label>
                            <select x-model="mDriverAge" class="input w-full">
                                <option value="30">30+ <?=T::years?></option>
                                <option value="25">25+ <?=T::years?></option>
                                <option value="21">21-24 <?=T::years?></option>
                                <option value="18">18-20 <?=T::years?></option>
                            </select>
                        </div>
                    </div>

                </div>

                <!-- Modal Footer -->
                <div class="p-5 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs text-gray-500"><?=T::total?> <?=T::price?></p>
                            <p class="text-xl font-bold text-gray-900 dark:text-gray-100">
                                <span x-text="selectedCar?.currency || 'USD'"></span>
                                <span x-text="parseFloat(selectedCar?.price || 0).toFixed(2)"></span>
                            </p>
                        </div>
                        <button @click="submitFeaturedBooking()" :disabled="mSubmitting" class="btn px-6">
                            <template x-if="!mSubmitting">
                                <span class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg">shopping_cart</span>
                                    <?=T::book?> <?=T::now?>
                                </span>
                            </template>
                            <template x-if="mSubmitting">
                                <span class="flex items-center gap-2">
                                    <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/></svg>
                                    <?=T::processing?>...
                                </span>
                            </template>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        </template>

    </section>
</div>

<!-- ============================================================================
     FEATURED CARS ALPINE.JS COMPONENT
     ============================================================================ -->
<script>
function featuredCarsData(url, words) {
    return {
        ...featuredTrack(),
        words, locs: [], carsByLoc: {}, all: [], place: '', loaded: false,
        // the cards in view: every place, or one
        get items() { return this.place ? (this.carsByLoc[this.place] || []) : this.all; },
        pick(slug) {
            this.place = slug;
            this.$nextTick(() => { if (this.$refs.track) this.$refs.track.scrollTo({ left: 0 }); this.sync(); });
        },
        done() {
            this.loaded = true;
            this.$el.style.minHeight = '';           // release the reserved space
            if (!this.all.length) this.$el.style.display = 'none';
            this.$nextTick(() => this.sync());
        },
        money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },

        // Modal state
        modalOpen: false,
        modalAlert: '',
        selectedCar: null,
        mSubmitting: false,

        // Pickup location
        mPickupSearch: '',
        mPickupResults: [],
        mPickupSelected: null,
        mPickupLoading: false,

        // Dropoff location
        mDropoffSearch: '',
        mDropoffResults: [],
        mDropoffSelected: null,
        mDropoffLoading: false,

        // Transfer extras
        mTravellers: 2,
        mDriverAge: '30',

        // Datepicker instances
        _pickupDP: null,
        _returnDP: null,
        _dpInitialized: false,

        initFeatured() {
            // Lazy-fetch the card data (locations + cars) when the section nears the viewport.
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(url).then(r => r.json()).then(d => {
                        const x = d.data || {};
                        this.carsByLoc = x.cars_by_location || {};
                        this.locs = (x.locations || []).map(l => ({ ...l, count: (this.carsByLoc[l.slug] || []).length })).filter(l => l.count);
                        this.all = this.locs.flatMap(l => this.carsByLoc[l.slug] || []);
                        this.done();
                    }).catch(() => this.done());
                }
            };
            addEventListener('scroll', f, { passive: true });
            f();
        },

        liftFeaturedPicker(pickerClass) {
            var picker = window.jQuery ? window.jQuery(pickerClass) : null;
            if (!picker || !picker.length) return;

            picker.css({
                position: 'fixed',
                top: '50%',
                left: '50%',
                transform: 'translate(-50%, -50%)',
                zIndex: 100002,
                maxHeight: 'calc(100vh - 32px)',
                overflowY: 'auto'
            });

            window.jQuery('.datepicker-overlay, .timepicker-overlay').css({
                zIndex: 100001,
                backgroundColor: 'transparent'
            });
        },

        initModalDatepickers() {
            if (this._dpInitialized) return;

            this.$nextTick(() => {
                if (typeof window.jQuery === 'undefined') {
                    this._dpInitialized = true;
                    return;
                }

                var $modal = window.jQuery;
                var self = this;
                var now = new Date();
                now.setHours(0, 0, 0, 0);

                if ($modal.fn.datepicker && $modal('.FeaturedCarsPickup').length && $modal('.FeaturedCarsReturn').length) {
                    this._pickupDP = $modal('.FeaturedCarsPickup').datepicker({
                        format: 'dd-mm-yyyy',
                        onRender: function(date) {
                            return date.valueOf() < now.valueOf() ? 'disabled' : '';
                        }
                    }).on('show', function() {
                        self.liftFeaturedPicker('.datepicker');
                    }).on('changeDate', function(ev) {
                        var nextDay = new Date(ev.date);
                        nextDay.setDate(nextDay.getDate() + 1);
                        if (self._returnDP) {
                            self._returnDP.setValue(nextDay);
                            self._returnDP.fill();
                        }
                        self._pickupDP.hide();
                    }).data('datepicker');

                    this._returnDP = $modal('.FeaturedCarsReturn').datepicker({
                        format: 'dd-mm-yyyy',
                        onRender: function(date) {
                            return (self._pickupDP && self._pickupDP.date && date.valueOf() <= self._pickupDP.date.valueOf()) ? 'disabled' : '';
                        }
                    }).on('show', function() {
                        self.liftFeaturedPicker('.datepicker');
                    }).on('changeDate', function(ev) {
                        self._returnDP.hide();
                    }).data('datepicker');
                }

                if ($modal.fn.timepicker && $modal('.FeaturedCarsPickupTime').length) {
                    $modal('.FeaturedCarsPickupTime').timepicker({ defaultHour: 10, defaultMinute: 0 }).on('show', function() {
                        self.liftFeaturedPicker('.timepicker');
                    });
                }
                if ($modal.fn.timepicker && $modal('.FeaturedCarsReturnTime').length) {
                    $modal('.FeaturedCarsReturnTime').timepicker({ defaultHour: 10, defaultMinute: 0 }).on('show', function() {
                        self.liftFeaturedPicker('.timepicker');
                    });
                }

                this._dpInitialized = true;
            });
        },

        openBookingModal(carData) {
            this.selectedCar = carData;
            this.modalAlert = '';
            this.mSubmitting = false;

            // Pre-fill pickup with car location
            this.mPickupSearch = carData.location_name || carData.location_city || '';
            this.mPickupSelected = this.mPickupSearch ? { name: this.mPickupSearch, display: this.mPickupSearch } : null;

            // Reset dropoff
            this.mDropoffSearch = '';
            this.mDropoffSelected = null;
            this.mDropoffResults = [];

            this.mTravellers = 2;
            this.mDriverAge = '30';

            this.modalOpen = true;
            document.body.style.overflow = 'hidden';

            // Init datepickers on first open
            this.initModalDatepickers();
        },

        closeModal() {
            this.modalOpen = false;
            document.body.style.overflow = '';
        },

        // Location autocomplete handlers
        fetchModalLocations(query, callback) {
            if (query.length < 2) { callback([]); return; }
            $.ajax({
                url: '<?=root?>cars-location-suggestion',
                method: 'POST',
                data: { query },
                success: (data) => {
                    callback((data && data.results && Array.isArray(data.results)) ? data.results : []);
                },
                error: () => callback([])
            });
        },

        handleModalPickupInput() {
            if (this.mPickupSelected) this.mPickupSelected = null;
            this.mPickupLoading = true;
            this.fetchModalLocations(this.mPickupSearch.trim(), (results) => {
                this.mPickupResults = results;
                this.mPickupLoading = false;
            });
        },
        selectModalPickup(loc) {
            this.mPickupSelected = loc;
            this.mPickupSearch = loc.display;
            this.mPickupResults = [];
        },
        clearModalPickup() {
            this.mPickupSelected = null;
            this.mPickupSearch = '';
            this.mPickupResults = [];
        },

        handleModalDropoffInput() {
            if (this.mDropoffSelected) this.mDropoffSelected = null;
            this.mDropoffLoading = true;
            this.fetchModalLocations(this.mDropoffSearch.trim(), (results) => {
                this.mDropoffResults = results;
                this.mDropoffLoading = false;
            });
        },
        selectModalDropoff(loc) {
            this.mDropoffSelected = loc;
            this.mDropoffSearch = loc.display;
            this.mDropoffResults = [];
        },
        clearModalDropoff() {
            this.mDropoffSelected = null;
            this.mDropoffSearch = '';
            this.mDropoffResults = [];
        },

        submitFeaturedBooking() {
            this.modalAlert = '';

            // Validate pickup location
            const pickupLoc = this.mPickupSelected ? (this.mPickupSelected.name || this.mPickupSelected.display) : this.mPickupSearch.trim();
            if (!pickupLoc) {
                this.modalAlert = '<?=T::please?> <?=T::select?> <?=T::pickup?> <?=T::location?>';
                return;
            }

            // Validate dropoff for transfers
            const dropoffLoc = this.mDropoffSelected ? (this.mDropoffSelected.name || this.mDropoffSelected.display) : this.mDropoffSearch.trim();
            if (this.selectedCar?.service_type === 'transfer' && !dropoffLoc) {
                this.modalAlert = '<?=T::please?> <?=T::select?> <?=T::to?> <?=T::location?>';
                return;
            }

            // Get dates and times
            const pickupDate = this.$refs.modalPickupDate?.value || '';
            const returnDate = this.$refs.modalReturnDate?.value || '';
            const pickupTime = this.$refs.modalPickupTime?.value || '10:00';
            const returnTime = this.$refs.modalReturnTime?.value || '10:00';

            if (!pickupDate) {
                this.modalAlert = '<?=T::please?> <?=T::select?> <?=T::pickup?> <?=T::date?>';
                return;
            }
            if (!returnDate) {
                this.modalAlert = '<?=T::please?> <?=T::select?> <?=T::return?> <?=T::date?>';
                return;
            }

            // Validate dates for rental
            if (this.selectedCar?.service_type !== 'transfer') {
                const pp = pickupDate.split('-'), rp = returnDate.split('-');
                const pd = new Date(pp[2], pp[1]-1, pp[0]), rd = new Date(rp[2], rp[1]-1, rp[0]);
                if (rd <= pd) {
                    this.modalAlert = '<?=T::return?> <?=T::date?> <?=T::must?> <?=T::be?> <?=T::after?> <?=T::pickup?> <?=T::date?>';
                    return;
                }
            }

            this.mSubmitting = true;

            // Build search params (same structure as listing page)
            const searchParams = {
                service_type: this.selectedCar.service_type || 'rental',
                pickup_location: pickupLoc,
                dropoff_location: dropoffLoc || pickupLoc,
                pickup_date: pickupDate,
                return_date: returnDate,
                pickup_time: pickupTime,
                dropoff_time: returnTime,
                driver_age: this.mDriverAge,
                travellers: String(this.mTravellers),
                currency: this.selectedCar.currency || 'USD'
            };

            // Build car data payload (same structure as listing bookCar)
            const carData = {
                id: this.selectedCar.id,
                car_id: this.selectedCar.id,
                name: this.selectedCar.name,
                category: 'standard',
                image: this.selectedCar.image,
                price: this.selectedCar.price,
                total_price: this.selectedCar.price,
                display_price: this.selectedCar.price,
                currency: this.selectedCar.currency,
                transmission: this.selectedCar.transmission,
                fuel_type: this.selectedCar.fuel_type,
                passengers: this.selectedCar.passengers,
                baggage: this.selectedCar.baggage,
                doors: this.selectedCar.doors,
                supplier: 'cars',
                supplier_name: 'cars',
                pickup_location: pickupLoc,
                dropoff_location: dropoffLoc || pickupLoc,
                is_refundable: this.selectedCar.is_refundable
            };

            const payload = {
                car_data: carData,
                search_params: searchParams
            };

            fetch('<?=root?>cars/booking/save-draft', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(async r => {
                const text = (await r.text()).replace(/^\uFEFF/, '');
                let data = {};
                try {
                    data = text ? JSON.parse(text) : {};
                } catch (e) {
                    data = {
                        success: false,
                        message: text || 'Invalid server response'
                    };
                }

                if (!r.ok && !data.message && !data.error) {
                    data.message = 'Failed to save booking draft';
                }

                return data;
            })
            .then(data => {
                if (data.success) {
                    window.location.href = data.redirect;
                } else {
                    this.modalAlert = data.message || data.error || 'Failed to proceed';
                    this.mSubmitting = false;
                }
            })
            .catch(err => {
                console.error('Booking Error:', err);
                this.modalAlert = 'Something went wrong. Please try again.';
                this.mSubmitting = false;
            });
        }
    };
}
</script>
