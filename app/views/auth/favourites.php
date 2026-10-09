<?php
// ============================================================================
// USER FAVOURITES PAGE - WISHLISTS & SAVED LISTINGS
// ============================================================================
// FILE: app/views/auth/favourites.php
// PURPOSE: DISPLAY ALL USER SAVED FAVOURITES WITH INSTANT SEARCH, FILTERING,
//          SORTING, GRID/LIST VIEW TOGGLES, AND INTERACTIVE MANAGEMENT.
// ACCESS: AUTHENTICATED USERS ONLY
// THEME: V10 STANDARD COLOR PALETTE & DESIGN SYSTEM
// ============================================================================
@$SECURE or die('Access Denied!');

$favourites = $favourites ?? [];
$counts = $counts ?? ['all' => count($favourites)];
?>

<div class="bg" x-data="favouritesApp()">
   <div class="flex gap-5 container mb-8">

      <!-- ======================================================================
           SIDEBAR NAVIGATION
           ====================================================================== -->
      <div class="flex-shrink-0">
         <?php include views . "auth/sidebar.php"; ?>
      </div>

      <!-- ======================================================================
           MAIN CONTENT AREA (MATCHING DASHBOARD CONTAINER)
           ====================================================================== -->
      <div class="flex-1 min-w-0 pt-6 bg-white rounded-[8px] ml-0">

         <!-- ==================================================================
              PAGE HEADER (EXACTLY MATCHING DASHBOARD DESIGN & ICONS)
              ================================================================== -->
         <div class="flex items-center justify-between mb-5 sm:mb-6 gap-3">
            <div class="flex items-center gap-3 sm:gap-4 min-w-0">
               <!-- Mobile Menu Toggle -->
               <button @click="sidebarCollapsed = false"
                  class="lg:hidden flex items-center justify-center w-12 h-12 bg-blue-50 rounded-lg text-blue-600 hover:bg-blue-100 transition-colors shrink-0">
                  <span class="material-symbols-outlined text-2xl">menu</span>
               </button>

               <!-- Favourites Header Icon (Hidden on Mobile when menu button is shown) -->
               <div class="hidden lg:flex items-center justify-center w-12 h-12 bg-blue-100 rounded-lg shrink-0">
                  <span class="material-symbols-outlined text-2xl text-blue-600">favorite</span>
               </div>

               <!-- Page Title & Subtitle -->
               <div class="min-w-0">
                  <h1 class="font-bold text-xl sm:text-2xl text-gray-900 truncate"><?= T::favourites ?? 'Favourites' ?>
                  </h1>
                  <p class="text-gray-500 text-xs sm:text-sm mt-0.5 truncate">
                     <?= T::manage_your_favourites ?? 'Your Saved Listings & Wishlists' ?></p>
               </div>
            </div>

            <!-- Clear All Action Button -->
            <template x-if="items.length > 0">
               <button type="button" @click="clearAll()"
                  class="inline-flex items-center justify-center gap-1 sm:gap-1.5 px-3 sm:px-3.5 py-2 text-xs font-semibold rounded-lg text-rose-600 bg-rose-50 border border-rose-200 hover:bg-rose-100 hover:border-rose-300 transition-colors cursor-pointer shrink-0">
                  <span class="material-symbols-outlined text-base">delete</span>
                  <span class="whitespace-nowrap"><?= T::clear_all ?? 'Clear All' ?></span>
               </button>
            </template>
         </div>

         <!-- ==================================================================
              STATISTICS OVERVIEW CARDS (TOP 4 SUMMARY CARDS)
              ================================================================== -->
         <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-5 sm:mb-6">
            <!-- All Favourites -->
            <div @click="setActiveTab('all')" class="card cursor-pointer transition-all hover:shadow-md p-3.5 sm:p-4"
               :class="activeTab === 'all' ? 'border-blue-600 ring-2 ring-blue-600/20 bg-blue-50/20' : 'border-gray-200 bg-white'">
               <div class="flex items-center justify-between mb-2">
                  <span class="material-symbols-outlined text-xl text-blue-600">favorite</span>
                  <p class="text-xs font-medium text-gray-500 truncate"><?= T::all_favourites ?? 'All Favourites' ?></p>
               </div>
               <p class="text-2xl sm:text-3xl font-bold text-gray-900" x-text="items.length"></p>
            </div>

            <!-- Stays -->
            <div @click="setActiveTab('stays')" class="card cursor-pointer transition-all hover:shadow-md p-3.5 sm:p-4"
               :class="activeTab === 'stays' ? 'border-blue-600 ring-2 ring-blue-600/20 bg-blue-50/20' : 'border-gray-200 bg-white'">
               <div class="flex items-center justify-between mb-2">
                  <span class="material-symbols-outlined text-xl text-blue-600">hotel</span>
                  <p class="text-xs font-medium text-gray-500 truncate"><?= T::stays ?? 'Stays' ?></p>
               </div>
               <p class="text-2xl sm:text-3xl font-bold text-gray-900" x-text="counts.stays || 0"></p>
            </div>

            <!-- Flights -->
            <div @click="setActiveTab('flights')"
               class="card cursor-pointer transition-all hover:shadow-md p-3.5 sm:p-4"
               :class="activeTab === 'flights' ? 'border-blue-600 ring-2 ring-blue-600/20 bg-blue-50/20' : 'border-gray-200 bg-white'">
               <div class="flex items-center justify-between mb-2">
                  <span class="material-symbols-outlined text-xl text-blue-600">flight_takeoff</span>
                  <p class="text-xs font-medium text-gray-500 truncate"><?= T::flights ?? 'Flights' ?></p>
               </div>
               <p class="text-2xl sm:text-3xl font-bold text-gray-900" x-text="counts.flights || 0"></p>
            </div>

            <!-- Tours -->
            <div @click="setActiveTab('tours')" class="card cursor-pointer transition-all hover:shadow-md p-3.5 sm:p-4"
               :class="activeTab === 'tours' ? 'border-blue-600 ring-2 ring-blue-600/20 bg-blue-50/20' : 'border-gray-200 bg-white'">
               <div class="flex items-center justify-between mb-2">
                  <span class="material-symbols-outlined text-xl text-blue-600">tour</span>
                  <p class="text-xs font-medium text-gray-500 truncate"><?= T::tours ?? 'Tours' ?></p>
               </div>
               <p class="text-2xl sm:text-3xl font-bold text-gray-900" x-text="counts.tours || 0"></p>
            </div>
         </div>

         <!-- ==================================================================
              MODULE FILTER TABS (STANDARD V10 TABS COMPONENT)
              ================================================================== -->
         <div class="tabs-container mb-4">
            <div
               class="tabs-list !flex-nowrap !justify-start sm:flex-wrap !bg-gray-100 w-full overflow-x-auto max-w-full scrollbar-none gap-1 p-1">
               <template x-for="tab in visibleTabs" :key="tab.key">
                  <button type="button" @click="setActiveTab(tab.key)"
                     class="tabs-trigger shrink-0 flex-none sm:flex-1 text-xs py-1.5 px-3"
                     :class="activeTab === tab.key ? 'active' : ''">
                     <span class="material-symbols-outlined text-sm" x-text="tab.icon"></span>
                     <span x-text="tab.label"></span>
                     <template x-if="(counts[tab.key] || 0) > 0">
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full tabular-nums ml-1"
                           :class="activeTab === tab.key ? 'bg-blue-100 text-blue-700' : 'bg-gray-200 text-gray-700'"
                           x-text="counts[tab.key] || 0"></span>
                     </template>
                  </button>
               </template>
            </div>
         </div>

         <!-- ==================================================================
              SEARCH & CONTROLS TOOLBAR (RESPONSIVE STACK ON MOBILE)
              ================================================================== -->
         <template x-if="items.length > 0">
            <div
               class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2.5 sm:gap-3 mb-5 sm:mb-6">
               <!-- Search Field with Proper .input-group & .input-with-icons classes -->
               <div class="input-group flex-1">
                  <span class="input-icon-left material-symbols-outlined">search</span>
                  <input type="text" x-model="searchQuery" placeholder="<?= T::search ?? 'Search' ?>..."
                     class="input-with-icons w-full text-xs sm:text-sm" />
                  <button type="button" x-show="searchQuery.length > 0" @click="searchQuery = ''"
                     class="input-icon-right material-symbols-outlined text-sm hover:text-gray-900">
                     close
                  </button>
               </div>

               <!-- Right Controls: Sort Select & View Switchers -->
               <div class="flex items-center gap-2 sm:gap-3">
                  <!-- Sort Select with Standard .select class -->
                  <div class="flex-1 sm:w-52 sm:flex-none">
                     <select x-model="sortBy" class="select w-full text-xs sm:text-sm"
                        aria-label="<?= T::sort_favourites ?? 'Sort favourites' ?>">
                        <option value="newest"><?= T::newest_first ?? 'Newest first' ?></option>
                        <option value="oldest"><?= T::oldest_first ?? 'Oldest first' ?></option>
                        <option value="title_asc"><?= T::title_a_z ?? 'Title A-Z' ?></option>
                        <option value="price_asc"><?= T::price_low_high ?? 'Price: Low to High' ?></option>
                        <option value="price_desc"><?= T::price_high_low ?? 'Price: High to Low' ?></option>
                     </select>
                  </div>

                  <!-- Grid / List View Toggle Switch -->
                  <div class="flex items-center rounded-lg border border-gray-200 bg-gray-100 p-1 shrink-0">
                     <button type="button" @click="viewMode = 'grid'"
                        :class="viewMode === 'grid' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-900'"
                        class="flex h-8 w-8 items-center justify-center rounded-md transition-all cursor-pointer"
                        title="<?= T::grid_view ?? 'Grid View' ?>">
                        <span class="material-symbols-outlined text-lg">grid_view</span>
                     </button>
                     <button type="button" @click="viewMode = 'list'"
                        :class="viewMode === 'list' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-900'"
                        class="flex h-8 w-8 items-center justify-center rounded-md transition-all cursor-pointer"
                        title="<?= T::list_view ?? 'List View' ?>">
                        <span class="material-symbols-outlined text-lg">view_list</span>
                     </button>
                  </div>
               </div>
            </div>
         </template>

         <!-- ==================================================================
              FAVOURITES LISTINGS CONTAINER (GRID & LIST VIEWS)
              ================================================================== -->
         <template x-if="filteredItems.length > 0">
            <div>
               <!-- GRID VIEW -->
               <div x-show="viewMode === 'grid'" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-5">
                  <template x-for="item in filteredItems" :key="item.id">
                     <div
                        class="card overflow-hidden border border-gray-200 hover:border-blue-300 hover:shadow-md transition-all flex flex-col justify-between group">
                        <!-- Card Top Media Image -->
                        <div class="relative h-44 sm:h-48 w-full overflow-hidden bg-gray-100">
                           <template x-if="item.image && item.image.trim() !== ''">
                              <img :src="formatImageUrl(item.image)" :alt="item.title"
                                 class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                 loading="lazy"
                                 @error="$el.style.display='none'; $el.nextElementSibling.style.display='flex';" />
                           </template>
                           <div :style="(!item.image || item.image.trim() === '') ? 'display:flex;' : 'display:none;'"
                              class="h-full w-full flex items-center justify-center bg-gray-100 text-gray-400">
                              <span class="material-symbols-outlined text-4xl opacity-50"
                                 x-text="getModuleInfo(item.module).icon"></span>
                           </div>

                           <!-- Category Badge -->
                           <span :class="getModuleInfo(item.module).badge"
                              class="absolute left-3 top-3 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold capitalize shadow-xs backdrop-blur-xs">
                              <span class="material-symbols-outlined text-xs"
                                 x-text="getModuleInfo(item.module).icon"></span>
                              <span x-text="getModuleInfo(item.module).label"></span>
                           </span>

                           <!-- Heart Remove Icon Button -->
                           <button type="button" @click="removeFav(item.id, item.title)"
                              class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center rounded-full bg-white/90 text-rose-500 shadow-sm backdrop-blur-xs transition-transform hover:scale-110 hover:bg-rose-50 cursor-pointer"
                              title="<?= T::remove_favourite ?? 'Remove from Favourites' ?>">
                               <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                           </button>
                        </div>

                        <!-- Card Body Details -->
                        <div class="p-4 sm:p-5 flex-1 flex flex-col justify-between">
                           <div>
                              <a :href="formatHref(item.href, item.module)" class="block">
                                 <h3 class="text-base font-bold text-gray-900 group-hover:text-blue-600 transition-colors line-clamp-1"
                                    x-text="item.title"></h3>
                              </a>

                              <template x-if="item.subtitle && item.subtitle.trim() !== ''">
                                 <p class="mt-0.5 text-xs text-gray-500 line-clamp-1" x-text="item.subtitle"></p>
                              </template>

                              <template x-if="item.location && item.location.trim() !== ''">
                                 <p class="mt-2 flex items-center gap-1 text-xs text-gray-500 truncate">
                                    <span
                                       class="material-symbols-outlined text-sm shrink-0 text-gray-400">location_on</span>
                                    <span class="truncate" x-text="item.location"></span>
                                 </p>
                              </template>
                           </div>

                           <!-- Card Price & Footer Action -->
                           <div class="flex items-center justify-between border-t border-gray-100 pt-3.5 mt-4">
                              <div>
                                 <template x-if="item.rating">
                                    <div class="flex items-center gap-1 text-xs mb-0.5">
                                       <span
                                          class="material-symbols-outlined text-sm text-amber-400 fill-amber-400">star</span>
                                       <span class="font-bold text-gray-900" x-text="item.rating"></span>
                                       <template x-if="item.reviewsCount">
                                          <span class="text-[11px] text-gray-400"
                                             x-text="'(' + item.reviewsCount + ')'"></span>
                                       </template>
                                    </div>
                                 </template>
                                 <p class="text-base font-bold text-blue-600 leading-tight" x-text="item.price"></p>
                              </div>

                              <a :href="formatHref(item.href, item.module)"
                                 class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-600 text-blue-600 hover:text-white text-xs font-semibold transition-colors duration-150">
                                 <span><?= T::view ?? 'View' ?></span>
                                 <span class="material-symbols-outlined text-sm">open_in_new</span>
                              </a>
                           </div>
                        </div>
                     </div>
                  </template>
               </div>

               <!-- LIST VIEW -->
               <div x-show="viewMode === 'list'" class="flex flex-col gap-3">
                  <template x-for="item in filteredItems" :key="item.id">
                     <div
                        class="card p-3.5 sm:p-4 border border-gray-200 hover:border-blue-300 hover:shadow-md transition-all flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4 group">
                        <div class="flex items-center gap-3 sm:gap-4 min-w-0 flex-1">
                           <!-- List Thumbnail -->
                           <div
                              class="relative h-18 w-24 sm:h-20 sm:w-28 shrink-0 overflow-hidden rounded-xl bg-gray-100">
                              <template x-if="item.image && item.image.trim() !== ''">
                                 <img :src="formatImageUrl(item.image)" :alt="item.title"
                                    class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                    loading="lazy"
                                    @error="$el.style.display='none'; $el.nextElementSibling.style.display='flex';" />
                              </template>
                              <div
                                 :style="(!item.image || item.image.trim() === '') ? 'display:flex;' : 'display:none;'"
                                 class="h-full w-full flex items-center justify-center bg-gray-100 text-gray-400">
                                 <span class="material-symbols-outlined text-2xl opacity-50"
                                    x-text="getModuleInfo(item.module).icon"></span>
                              </div>
                           </div>

                           <!-- List Item Details -->
                           <div class="min-w-0 flex-1">
                              <div class="flex items-center gap-2 mb-1">
                                 <span :class="getModuleInfo(item.module).badge"
                                    class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[10px] font-bold capitalize">
                                    <span class="material-symbols-outlined text-[11px]"
                                       x-text="getModuleInfo(item.module).icon"></span>
                                    <span x-text="getModuleInfo(item.module).label"></span>
                                 </span>
                                 <template x-if="item.location && item.location.trim() !== ''">
                                    <span class="flex items-center gap-1 text-xs text-gray-500 truncate">
                                       <span class="material-symbols-outlined text-xs text-gray-400">location_on</span>
                                       <span class="truncate" x-text="item.location"></span>
                                    </span>
                                 </template>
                              </div>

                              <a :href="formatHref(item.href, item.module)" class="block">
                                 <h3 class="text-sm sm:text-base font-bold text-gray-900 group-hover:text-blue-600 transition-colors truncate"
                                    x-text="item.title"></h3>
                              </a>

                              <template x-if="item.subtitle && item.subtitle.trim() !== ''">
                                 <p class="text-xs text-gray-500 truncate mt-0.5" x-text="item.subtitle"></p>
                              </template>

                              <template x-if="item.rating">
                                 <div class="flex items-center gap-1 text-xs mt-1">
                                    <span
                                       class="material-symbols-outlined text-xs text-amber-400 fill-amber-400">star</span>
                                    <span class="font-bold text-gray-900" x-text="item.rating"></span>
                                    <template x-if="item.reviewsCount">
                                       <span class="text-[11px] text-gray-400"
                                          x-text="'(' + item.reviewsCount + ')'"></span>
                                    </template>
                                 </div>
                              </template>
                           </div>
                        </div>

                        <!-- List Price & Actions -->
                        <div
                           class="flex items-center justify-between sm:justify-end gap-3 border-t sm:border-t-0 pt-2.5 sm:pt-0 border-gray-100 shrink-0">
                           <div class="text-left sm:text-right">
                              <p class="text-base font-bold text-blue-600" x-text="item.price"></p>
                           </div>

                           <div class="flex items-center gap-2">
                              <button type="button" @click="removeFav(item.id, item.title)"
                                 class="flex h-8 w-8 sm:h-9 sm:w-9 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100 transition-colors cursor-pointer"
                                 title="<?= T::remove_favourite ?? 'Remove' ?>">
                                  <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                              </button>
                              <a :href="formatHref(item.href, item.module)"
                                 class="inline-flex items-center gap-1 px-3 sm:px-3.5 py-1.5 sm:py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold transition-colors duration-150">
                                 <span><?= T::view ?? 'View' ?></span>
                                 <span class="material-symbols-outlined text-sm">open_in_new</span>
                              </a>
                           </div>
                        </div>
                     </div>
                  </template>
               </div>
            </div>
         </template>

         <!-- ==================================================================
              EMPTY STATE DISPLAY (WHEN NO FAVOURITES EXIST)
              ================================================================== -->
         <template x-if="filteredItems.length === 0">
            <div class="card p-8 sm:p-14 border border-gray-200 flex flex-col items-center justify-center text-center">
               <!-- Empty State Icon Box -->
               <div
                  class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-gray-100 text-gray-400 flex items-center justify-center mx-auto mb-4">
                  <span class="material-symbols-outlined text-3xl" x-text="getModuleInfo(activeTab).icon"></span>
               </div>

               <!-- Empty Heading -->
               <h2 class="text-lg sm:text-xl font-bold text-gray-900">
                  <span x-show="activeTab === 'all'"><?= T::no_favourites_yet ?? 'No Saved Favourites Yet' ?></span>
                  <span x-show="activeTab !== 'all'"
                     x-text="'<?= T::no_saved_module ?? 'No Saved Items' ?> (' + getModuleInfo(activeTab).label + ')'"></span>
               </h2>

               <!-- Empty Description -->
               <p class="mt-2 max-w-md text-xs sm:text-sm text-gray-600 leading-relaxed">
                  <span
                     x-show="activeTab === 'all'"><?= T::favourites_empty_desc ?? 'Save your favourite hotels, flights, tours, and services for quick access later. Tap the heart icon on any listing to bookmark it here.' ?></span>
                  <span x-show="activeTab !== 'all'"
                     x-text="'<?= T::favourites_empty_module_desc ?? 'No items saved in this category yet.' ?>'"></span>
               </p>

               <!-- Quick Module Navigation Links -->
               <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
                  <template x-if="activeTab !== 'all'">
                     <a :href="getModuleInfo(activeTab).exploreHref"
                        class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition-colors shadow-xs">
                        <span class="material-symbols-outlined text-base" x-text="getModuleInfo(activeTab).icon"></span>
                        <span x-text="'<?= T::explore ?? 'Explore' ?> ' + getModuleInfo(activeTab).label"></span>
                     </a>
                  </template>

                  <template x-if="activeTab === 'all'">
                     <div class="flex flex-wrap items-center justify-center gap-2">
                        <a href="<?= root ?>stays"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition-colors shadow-xs">
                           <span class="material-symbols-outlined text-base">hotel</span>
                           <span><?= T::explore_stays ?? 'Explore Stays' ?></span>
                        </a>
                        <a href="<?= root ?>flights"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition-colors shadow-xs">
                           <span class="material-symbols-outlined text-base">flight_takeoff</span>
                           <span><?= T::search_flights ?? 'Search Flights' ?></span>
                        </a>
                        <a href="<?= root ?>tours"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition-colors shadow-xs">
                           <span class="material-symbols-outlined text-base">tour</span>
                           <span><?= T::explore_tours ?? 'Explore Tours' ?></span>
                        </a>
                        <a href="<?= root ?>cars"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition-colors shadow-xs">
                           <span class="material-symbols-outlined text-base">directions_car</span>
                           <span><?= T::book_a_car ?? 'Book a Car' ?></span>
                        </a>
                     </div>
                  </template>
               </div>

               <!-- Helper Tip -->
               <div
                  class="mt-8 flex items-center gap-2 rounded-lg border border-dashed border-gray-300 bg-gray-50 px-3.5 py-2.5 text-xs text-gray-500">
                  <span class="material-symbols-outlined text-rose-500 text-base shrink-0">favorite</span>
                  <span><?= T::favourites_hint ?? 'Tap the heart icon on any listing page to save items for comparison.' ?></span>
               </div>
            </div>
         </template>

      </div>
   </div>

   <!-- ==========================================================================
        REUSABLE DELETE / REMOVAL CONFIRMATION MODAL
        ========================================================================== -->
   <?php include views . "includes/delete-modal.php"; ?>

   <!-- ==========================================================================
        TOAST NOTIFICATION FEEDBACK
        ========================================================================== -->
   <div x-show="toastMessage !== null" x-cloak x-transition:enter="transition ease-out duration-300"
      x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
      x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0"
      x-transition:leave-end="opacity-0 translate-y-4"
      class="fixed bottom-6 right-6 z-[999999] flex items-center gap-2.5 px-4 py-3 rounded-lg shadow-xl text-xs font-semibold border text-white"
      :class="toastMessage && toastMessage.type === 'error' ? 'bg-rose-600 border-rose-700' : 'bg-gray-900 border-gray-800'"
      style="display: none;">
      <span class="material-symbols-outlined text-base"
         x-text="toastMessage && toastMessage.type === 'error' ? 'error' : 'check_circle'"></span>
      <span x-text="toastMessage ? toastMessage.text : ''"></span>
   </div>
</div>

<!-- ============================================================================
     ALPINE.JS COMPONENT LOGIC
     ============================================================================ -->
<script>
   function favouritesApp() {
      return {
         sidebarCollapsed: window.innerWidth < 1024,
         items: <?= json_encode($favourites ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
         activeTab: 'all',
         searchQuery: '',
         sortBy: 'newest',
         viewMode: 'grid',
         confirmModal: null,
         deleting: false,
         toastMessage: null,
         toastTimer: null,
         rootUrl: '<?= root ?>',
         csrfToken: '<?= CSRF::getToken() ?>',

         moduleTabs: [
            { key: "all", label: "<?= addslashes(T::all ?? 'All') ?>", icon: "favorite" },
            { key: "stays", label: "<?= addslashes(T::stays ?? 'Stays') ?>", icon: "hotel" },
            { key: "flights", label: "<?= addslashes(T::flights ?? 'Flights') ?>", icon: "flight_takeoff" },
            { key: "tours", label: "<?= addslashes(T::tours ?? 'Tours') ?>", icon: "tour" },
            { key: "cars", label: "<?= addslashes(T::cars ?? 'Cars') ?>", icon: "directions_car" },
            { key: "bus", label: "Bus", icon: "directions_bus" },
            { key: "umrah", label: "<?= addslashes(T::umrah ?? 'Umrah') ?>", icon: "dark_mode" },
            { key: "ferries", label: "Ferries", icon: "directions_boat" },
            { key: "esim", label: "eSIM", icon: "sim_card" },
            { key: "visa", label: "<?= addslashes(T::visa ?? 'Visa') ?>", icon: "description" },
            { key: "ai_trip", label: "AI Trip", icon: "auto_awesome" }
         ],

         moduleMeta: {
            stays: { label: "<?= addslashes(T::stays ?? 'Stays') ?>", icon: "hotel", badge: "bg-blue-50 text-blue-600 border border-blue-100", exploreHref: "<?= root ?>stays" },
            flights: { label: "<?= addslashes(T::flights ?? 'Flights') ?>", icon: "flight_takeoff", badge: "bg-sky-50 text-sky-600 border border-sky-100", exploreHref: "<?= root ?>flights" },
            tours: { label: "<?= addslashes(T::tours ?? 'Tours') ?>", icon: "tour", badge: "bg-emerald-50 text-emerald-600 border border-emerald-100", exploreHref: "<?= root ?>tours" },
            cars: { label: "<?= addslashes(T::cars ?? 'Cars') ?>", icon: "directions_car", badge: "bg-amber-50 text-amber-600 border border-amber-100", exploreHref: "<?= root ?>cars" },
            bus: { label: "Bus", icon: "directions_bus", badge: "bg-purple-50 text-purple-600 border border-purple-100", exploreHref: "<?= root ?>buses" },
            umrah: { label: "<?= addslashes(T::umrah ?? 'Umrah') ?>", icon: "dark_mode", badge: "bg-indigo-50 text-indigo-600 border border-indigo-100", exploreHref: "<?= root ?>umrah" },
            ferries: { label: "Ferries", icon: "directions_boat", badge: "bg-cyan-50 text-cyan-600 border border-cyan-100", exploreHref: "<?= root ?>ferries" },
            esim: { label: "eSIM", icon: "sim_card", badge: "bg-teal-50 text-teal-600 border border-teal-100", exploreHref: "<?= root ?>esim" },
            visa: { label: "<?= addslashes(T::visa ?? 'Visa') ?>", icon: "description", badge: "bg-rose-50 text-rose-600 border border-rose-100", exploreHref: "<?= root ?>visa" },
            ai_trip: { label: "AI Trip", icon: "auto_awesome", badge: "bg-violet-50 text-violet-600 border border-violet-100", exploreHref: "<?= root ?>ai-trip" },
         },
         enabledModules: <?= json_encode($enabledModules ?? ['stays', 'flights', 'tours', 'cars', 'visa']) ?>,

         get visibleTabs() {
            const enabled = Array.isArray(this.enabledModules) ? this.enabledModules.map(m => String(m).toLowerCase()) : [];
            const enabledSet = new Set(enabled);
            return this.moduleTabs.filter(tab => {
               if (tab.key === 'all') return true;
               return enabledSet.has(tab.key.toLowerCase()) || (this.counts[tab.key] || 0) > 0;
            });
         },

         get counts() {
            const map = { all: this.items.length };
            this.items.forEach(i => {
               const m = (i.module || 'stays').toLowerCase();
               map[m] = (map[m] || 0) + 1;
            });
            return map;
         },

         get filteredItems() {
            let list = this.items;
            if (this.activeTab !== 'all') {
               list = list.filter(i => (i.module || 'stays').toLowerCase() === this.activeTab);
            }
            if (this.searchQuery && this.searchQuery.trim() !== '') {
               const q = this.searchQuery.toLowerCase().trim();
               list = list.filter(i =>
                  (i.title && i.title.toLowerCase().includes(q)) ||
                  (i.subtitle && i.subtitle.toLowerCase().includes(q)) ||
                  (i.location && i.location.toLowerCase().includes(q))
               );
            }
            const priceVal = (p) => Number(String(p || '').replace(/[^0-9.-]/g, '')) || 0;
            return [...list].sort((a, b) => {
               if (this.sortBy === 'oldest') return String(a.addedAt || '').localeCompare(String(b.addedAt || ''));
               if (this.sortBy === 'title_asc') return (a.title || '').localeCompare(b.title || '');
               if (this.sortBy === 'price_asc') return priceVal(a.price) - priceVal(b.price);
               if (this.sortBy === 'price_desc') return priceVal(b.price) - priceVal(a.price);
               return String(b.addedAt || '').localeCompare(String(a.addedAt || ''));
            });
         },

         setActiveTab(tabKey) {
            this.activeTab = tabKey;
         },

         getModuleInfo(mod) {
            const key = (mod || 'stays').toLowerCase();
            return this.moduleMeta[key] || {
               label: key.charAt(0).toUpperCase() + key.slice(1),
               icon: "favorite",
               badge: "bg-gray-100 text-gray-600 border border-gray-200",
               exploreHref: this.rootUrl
            };
         },

         formatImageUrl(img) {
            if (!img || img.trim() === '') return '';
            if (img.startsWith('http://') || img.startsWith('https://')) return img;
            if (img.startsWith('/')) {
               return this.rootUrl + img.substring(1);
            }
            return this.rootUrl + img;
         },

         formatHref(href, mod) {
            if (!href || href === '#' || href.trim() === '') {
               return this.getModuleInfo(mod).exploreHref;
            }
            if (href.startsWith('http://') || href.startsWith('https://')) return href;
            if (href.startsWith('/')) {
               return this.rootUrl + href.substring(1);
            }
            return this.rootUrl + href;
         },

         removeFav(id, title) {
            this.confirmModal = {
               type: 'single',
               id: id,
               title: '<?= addslashes(T::remove_favourite ?? "Remove Favourite?") ?>',
               message: '<?= addslashes(T::this_action_cannot_be_undone ?? "This action cannot be undone.") ?>',
               btnText: '<?= addslashes(T::delete ?? "Delete") ?>'
            };
         },

         clearAll() {
            this.confirmModal = {
               type: 'all',
               title: '<?= addslashes(T::clear_all_favourites ?? "Clear All Favourites?") ?>',
               message: '<?= addslashes(T::this_action_cannot_be_undone ?? "This action cannot be undone.") ?>',
               btnText: '<?= addslashes(T::clear_all ?? "Clear All") ?>'
            };
         },

         closeModal() {
            if (this.deleting) return;
            this.confirmModal = null;
         },

         showToast(text, type = 'success') {
            if (this.toastTimer) clearTimeout(this.toastTimer);
            this.toastMessage = { text, type };
            this.toastTimer = setTimeout(() => {
               this.toastMessage = null;
            }, 3500);
         },

         async executeDelete() {
            if (!this.confirmModal) return;
            this.deleting = true;
            try {
               if (this.confirmModal.type === 'all') {
                  const res = await fetch(this.rootUrl + 'favourites/clear', {
                     method: 'POST',
                     headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                     },
                     body: JSON.stringify({ csrf_token: this.csrfToken })
                  });
                  const data = await res.json();
                  if (res.ok && data.status === 'success') {
                     this.items = [];
                     this.showToast('<?= addslashes(T::favourites_cleared ?? "All favourites cleared successfully") ?>');
                  } else {
                     throw new Error(data.message || 'Failed to clear favourites');
                  }
               } else if (this.confirmModal.id) {
                  const targetId = this.confirmModal.id;
                  const res = await fetch(this.rootUrl + 'favourites/remove', {
                     method: 'POST',
                     headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                     },
                     body: JSON.stringify({ id: targetId, csrf_token: this.csrfToken })
                  });
                  const data = await res.json();
                  if (res.ok && data.status === 'success') {
                     this.items = this.items.filter(i => String(i.id) !== String(targetId));
                     this.showToast('<?= addslashes(T::favourite_removed ?? "Favourite removed successfully") ?>');
                  } else {
                     throw new Error(data.message || 'Failed to remove favourite');
                  }
               }
            } catch (err) {
               this.showToast(err.message || 'Action failed', 'error');
            } finally {
               this.deleting = false;
               this.confirmModal = null;
            }
         }
      };
   }
</script>