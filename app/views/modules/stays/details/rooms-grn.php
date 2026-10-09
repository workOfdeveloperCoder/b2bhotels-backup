<!--
    AVAILABLE ROOMS SECTION

    This component displays available room types and their rate options for a hotel booking.

    FEATURES:
    - Room filtering (by type, price range, sort options)
    - Multiple rate options per room type
    - Multiple selections allowed (users can book multiple options from same room)
    - Quantity selector for each rate option
    - Real-time price calculation with fixed footer bar
    - Responsive card-based layout
    - Image gallery integration with FSLightbox

    ALPINE.JS COMPONENT: hotelRooms()
    Manages all room data, filtering, selection, and booking logic

    DATA FLOW:
    1. On init: Fetch rooms from API based on hotel/date/guest parameters
    2. Extract filter options (room types, price ranges) from fetched data
    3. Apply filters and display results in card layout
    4. User selects rate options with quantities
    5. On continue: Save booking draft and redirect to booking page
-->
<?php $isGrnConnectRooms = true; ?>
<div class="mb-24" x-data="hotelRooms()">

    <!--
        ROOM FILTERS SECTION
        Allows filtering rooms by type, price range, and sorting
        Only visible when rooms are loaded successfully
        Single-row inline layout with purple theme
    -->
    <div x-show="!loading && !error && rooms.length > 0" x-cloak class="mb-6 card">
        <div class="flex flex-wrap gap-4 items-end">
            <!-- Filter Icon & Info -->
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-full bg-purple-50 flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-outlined text-purple-600 text-[20px]">filter_list</span>
                </div>
                <div>
                    <h3 class="text-[13px] font-semibold text-gray-900"><?= T::filter_rooms ?? 'Filter Rooms' ?></h3>
                    <p class="text-xs text-gray-500">
                        <span x-text="getFilteredCount()"></span> <?= T::rooms_available ?? 'Available' ?>
                    </p>
                </div>
            </div>
            <!-- Room Type Filter -->
            <div class="flex-1 min-w-[180px]">
                <select x-model="roomFilters.selectedType" @change="applyRoomFilters()" class="select h-[42px] !rounded-full">
                    <option value=""><?= T::all_types ?? 'All Types' ?></option>
                    <template x-for="type in availableRoomTypes" :key="type">
                        <option :value="type" x-text="type"></option>
                    </template>
                </select>
            </div>

            <!-- Price Range Filter -->
            <div class="flex-1 min-w-[220px]">
                <div class="bg-gray-50 rounded-full p-2 px-4 border h-[42px] flex items-center gap-2">
                    <span class="text-xs text-gray-600 whitespace-nowrap" x-text="getCurrencySymbol(currency) + roomFilters.priceRange[0]"></span>
                    <div id="roomPriceSlider" class="flex-1"></div>
                    <span class="text-xs text-gray-600 whitespace-nowrap" x-text="getCurrencySymbol(currency) + roomFilters.priceRange[1]"></span>
                </div>
            </div>

            <!-- Sort By -->
            <div class="flex-1 min-w-[180px]">
                <select x-model="roomFilters.sortBy" @change="applyRoomFilters()" class="select h-[42px] !rounded-full">
                    <option value="default"><?= T::default ?? 'Default' ?></option>
                    <option value="price_low"><?= T::price_low_to_high ?? 'Price: Low to High' ?></option>
                    <option value="price_high"><?= T::price_high_to_low ?? 'Price: High to Low' ?></option>
                    <option value="name_asc"><?= T::name_a_z ?? 'Name: A-Z' ?></option>
                    <option value="name_desc"><?= T::name_z_a ?? 'Name: Z-A' ?></option>
                </select>
            </div>

            <!-- Reset Button -->
            <div>
                <button @click="resetRoomFilters()" class="btn light h-[42px] px-4 !rounded-full">
                    <span class="material-symbols-outlined text-base">restart_alt</span>
                    <?= T::reset ?? 'Reset' ?>
                </button>
            </div>
        </div>
    </div>

<style>
    /*
     * CUSTOM STYLING FOR PRICE RANGE SLIDER (noUiSlider)
     * Applies purple gradient theme matching the site design
     * Customizes handle appearance and track styling
     */
    #roomPriceSlider .noUi-connect {
        background: linear-gradient(to right, #9333ea, #a855f7, #c084fc);
    }
    #roomPriceSlider .noUi-handle {
        border: 3px solid #9333ea;
        border-radius: 50%;
        background: white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        cursor: pointer;
        width: 16px;
        height: 16px;
    }
    #roomPriceSlider .noUi-handle:before,
    #roomPriceSlider .noUi-handle:after {
        display: none;
    }
    #roomPriceSlider.noUi-horizontal {
        height: 5px;
    }
    #roomPriceSlider.noUi-horizontal .noUi-handle {
        width: 16px;
        height: 16px;
        right: -8px;
        top: -6px;
    }
    #roomPriceSlider.noUi-target {
        background: #e5e7eb;
        border-radius: 4px;
        border: none;
        box-shadow: none;
        padding: 0 8px
    }

    /* Bottom bar positioning based on sidebar state */
    @media (min-width: 1024px) {
        body.sidebar-open .room-booking-bar {
            left: 200px;
        }
        body.sidebar-closed .room-booking-bar {
            left: 64px;
        }
    }

    /* Toast animations */
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }

    .animate-slide-in-right {
        animation: slideInRight 0.3s ease-out;
    }

    .animate-slide-out-right {
        animation: slideOutRight 0.3s ease-in;
    }

    /* Rate detail popovers (price, comments, cancellation, etc.) */
    .rate-popover-wrap {
        position: relative;
        display: inline-flex;
    }
    .rate-popover {
        display: none;
        width: min(360px, 88vw);
        height: auto;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,.12);
        padding: 8px;
        text-align: left;
        pointer-events: none;
    }
    .rate-popover-wrap.is-open .rate-popover {
        display: block;
        pointer-events: auto;
    }
    .rate-info-btn {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 9999px;
        border: 1px solid #e5e7eb;
        background: #fff;
        color: #4b5563;
        font-size: 11px;
        line-height: 1.2;
        cursor: help;
    }
    .rate-info-btn:hover {
        border-color: #c4b5fd;
        color: #6d28d9;
        background: #faf5ff;
    }
</style>

    <!--
        LOADING STATE
        Displays animated skeleton while fetching room data from API
    -->
    <div x-show="loading" class="">
        <h4 class="mb-4 flex gap-2">
        <i class="material-symbols-outlined animate-spin">restart_alt</i>
        <?= T::loading ?? 'Loading...' ?> <?= T::rooms ?? 'Rooms' ?></h4>
        <div class="space-y-4">
            <div class="animate-pulse">
                <!-- <div class="h-6 bg-gray-200 rounded w-40 mb-4"></div> -->
                <div class="h-32 bg-gray-200 rounded mb-3"></div>
                <div class="h-32 bg-gray-200 rounded"></div>
            </div>
        </div>
    </div>

    <!--
        ERROR STATE
        Displays error message with retry button when API request fails
    -->
    <div x-show="error && !loading" class="p-4">
        <div class="bg-red-50 border border-red-200 rounded-3xl p-4 text-center">
            <svg class="w-10 h-10 text-red-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-red-800 font-medium text-sm" x-text="errorMessage"></p>
            <button @click="fetchRooms()" class="mt-2 px-4 py-2 bg-red-600 text-white rounded-full hover:bg-red-700 transition text-sm">
                <?= T::try_again ?>
            </button>
        </div>
    </div>

    <!--
        INQUIRY REQUIRED NOTICE
        Shown when rooms are available but pricing is not provided by supplier
        Users need to contact for manual quote
    -->
    <div x-show="!loading && !error && rooms.length > 0 && (!rooms[0].options || rooms[0].options.length === 0)" class="mb-4 bg-blue-50 border border-blue-200 rounded-3xl p-4">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="text-blue-900 font-medium text-sm"><?= T::booking_inquiry_required ?></p>
                <p class="text-blue-700 text-xs mt-1"><?= T::booking_inquiry_description ?></p>
            </div>
        </div>
    </div>

    <!--
        ROOM CARDS LAYOUT
        Each card represents one room type with all its rate options

        STRUCTURE PER CARD:
        1. Room Header: Image, name, capacity, amenities, options count
        2. Rate Options Table: All available booking options with features
        3. Each row: Rate type, features, price, quantity selector, select button

        SELECTION LOGIC:
        - Users can select multiple options from same room type
        - Quantity can be adjusted per option (0 = unselected)
        - Selected options highlighted with blue background
        - Total price updates in real-time in fixed footer
    -->

    <div x-show="!loading && !error && rooms.length > 0" class="space-y-4">
        <template x-for="(roomData, roomIndex) in filteredRooms" :key="roomData.room_id">
            <div x-show="roomData.visible" class="card overflow-hidden p-0">
                <!--
                    ROOM HEADER SECTION
                    Displays room overview information:
                    - Single image with gallery badge
                    - Room name (capitalized)
                    - Capacity (adults & children)
                    - First 5 amenities
                    - Options count badge
                -->
                <div class="flex flex-col md:flex-row gap-4 p-4 bg-gray-50 border-b border-gray-200">
                    <!--
                        IMAGE GALLERY
                        Shows single room image with click to open FSLightbox gallery
                        Badge displays total image count
                        Falls back to no_img.jpg if no images available
                    -->
                    <div class="flex gap-2 flex-shrink-0">
                        <template x-if="roomData.room_images && roomData.room_images.length > 0">
                            <div class="relative w-20 h-20 md:w-20 md:h-20 bg-gray-200 rounded-2xl overflow-hidden cursor-pointer hover:opacity-90 transition-opacity"
                                 @click="openRoomImageGallery(roomData.room_images, 0)">
                                <img :src="roomData.room_images[0]" alt="Room view" class="w-full h-full object-cover" onerror="this.onerror=null;this.src='<?= root ?>uploads/no_img.jpg'">
                                <div x-show="roomData.room_images.length > 1" class="absolute bottom-1 right-1 bg-black bg-opacity-60 text-white text-xs px-2 py-0.5 rounded flex items-center gap-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M4 4h7V2H4a2 2 0 0 0-2 2v7h2V4zm6 9l-4 5h12l-3-4l-2.03 2.71L10 13zm7-4.5c0-.83-.67-1.5-1.5-1.5S14 7.67 14 8.5s.67 1.5 1.5 1.5S17 9.33 17 8.5zM20 2h-7v2h7v7h2V4a2 2 0 0 0-2-2zm0 18h-7v2h7a2 2 0 0 0 2-2v-7h-2v7zM4 13H2v7a2 2 0 0 0 2 2h7v-2H4v-7z"/>
                                    </svg>
                                    <span x-text="roomData.room_images.length"></span>
                                </div>
                            </div>
                        </template>
                        <template x-if="!roomData.room_images || roomData.room_images.length === 0">
                            <div class="relative w-20 h-20 md:w-20 md:h-20 bg-gray-200 rounded-2xl overflow-hidden cursor-pointer hover:opacity-90 transition-opacity">
                                <img src="<?= root ?>uploads/no_img.jpg" alt="No image available" class="w-full h-full object-cover">
                            </div>
                        </template>
                    </div>

                    <!--
                        ROOM INFORMATION
                        Displays room name, capacity, and amenities
                    -->
                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <h3 class="text-lg font-semibold text-gray-900 capitalize" x-text="roomData.room_name"></h3>
                            <?php if ($isGrnConnectRooms): ?>
                            <span x-show="roomData.is_package"
                                  class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 text-[11px] font-semibold text-blue-700">
                                <span class="material-symbols-outlined text-[14px]">stacks</span>
                                <span x-text="`${roomData.package_room_count || 1}-room package`"></span>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($isGrnConnectRooms): ?>
                        <p x-show="!roomData.is_package && roomData.room_type_name && roomData.room_type_name !== roomData.room_name"
                           class="text-sm text-gray-600 mb-2 leading-relaxed capitalize"
                           x-text="roomData.room_type_name"></p>
                        <p x-show="roomData.description && roomData.description !== roomData.room_name && roomData.description !== roomData.room_type_name"
                           class="text-sm text-gray-600 mb-2 leading-relaxed"
                           x-text="roomData.description"></p>
                        <div x-show="roomData.is_package && roomData.package_rooms && roomData.package_rooms.length"
                             class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-2">
                            <template x-for="(packageRoom, packageRoomIndex) in roomData.package_rooms"
                                      :key="`${roomData.room_id}-component-${packageRoomIndex}`">
                                <div class="rounded-lg border border-blue-100 bg-white p-3">
                                    <div class="flex items-start gap-2">
                                        <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-100 text-[11px] font-bold text-blue-700"
                                              x-text="packageRoomIndex + 1"></span>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-gray-900 capitalize"
                                               x-text="packageRoom.room_type || 'Room'"></p>
                                            <p x-show="packageRoom.pax_label"
                                               class="text-xs text-gray-500"
                                               x-text="packageRoom.pax_label"></p>
                                            <p x-show="packageRoom.description && packageRoom.description !== packageRoom.room_type"
                                               class="mt-1 text-xs leading-relaxed text-gray-600"
                                               x-text="packageRoom.description"></p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <?php endif; ?>

                        <!--
                            CAPACITY DISPLAY
                            Shows adults count (always visible)
                            Shows children count (conditional - only if > 0)
                        -->
                        <div class="flex gap-4 mb-3 text-sm text-gray-700">
                            <div class="flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4s-4 1.79-4 4s1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                </svg>
                                <span x-text="`${roomData.max_adults} ${roomData.max_adults > 1 ? '<?= T::adults ?>' : '<?= T::adult ?>'}`"></span>
                            </div>
                            <div x-show="roomData.max_children > 0" class="flex items-center gap-1.5">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                    <path fill="currentColor" d="M12 5C10.89 5 10 5.89 10 7s.89 2 2 2s2-.89 2-2s-.89-2-2-2m-3 8v7h2v-5h2v5h2v-7h-6m0-3c0-1.66 1.34-3 3-3s3 1.34 3 3h2c0-2.76-2.24-5-5-5s-5 2.24-5 5h2z"/>
                                </svg>
                                <span x-text="`${roomData.max_children} ${roomData.max_children > 1 ? '<?= T::children ?>' : '<?= T::child ?>'}`"></span>
                            </div>
                        </div>

                        <!--
                            AMENITIES DISPLAY
                            Shows first 5 amenities with checkmark icons
                            Displays "+X more" if additional amenities exist
                        -->
                        <div x-show="roomData.amenities && roomData.amenities.length > 0" class="flex flex-wrap gap-2">
                            <template x-for="amenity in roomData.amenities.slice(0, 5)" :key="amenity.id">
                                <span class="inline-flex items-center gap-1 px-2 py-1 bg-white border border-gray-200 rounded-full text-xs text-gray-700">
                                    <svg class="w-3 h-3 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                    <span x-text="amenity.name"></span>
                                </span>
                            </template>
                            <span x-show="roomData.amenities.length > 5" class="text-xs text-gray-500 py-1" x-text="`+${roomData.amenities.length - 5} <?= T::more ?>`"></span>
                        </div>
                    </div>

                    <!--
                        OPTIONS COUNT BADGE
                        Displays number of available rate options for this room
                    -->
                    <div class="flex items-start">
                        <?php if ($isGrnConnectRooms): ?>
                        <span class="inline-flex items-center justify-center px-3 py-1.5 bg-blue-50 text-blue-700 rounded-full text-sm font-medium"
                              x-text="roomData.is_package
                                ? 'Bundle offer'
                                : `${roomData.options?.length || 0} ${(roomData.options?.length || 0) === 1 ? '<?= T::rate ?>' : '<?= T::rates ?>'}`"></span>
                        <?php else: ?>
                        <span class="inline-flex items-center justify-center px-3 py-1.5 bg-blue-50 text-blue-700 rounded-full text-sm font-medium"
                              x-text="`${roomData.options?.length || 0} ${(roomData.options?.length || 0) === 1 ? '<?= T::rate ?>' : '<?= T::rates ?>'}`"></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!--
                    NO PRICING AVAILABLE STATE
                    Shown when room exists but supplier doesn't provide pricing
                    Users must contact directly for quotes
                -->
                <div x-show="!roomData.options || roomData.options.length === 0" class="p-6 text-center bg-amber-50 rounded-b-3xl">
                    <svg class="w-10 h-10 text-amber-500 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <h4 class="text-amber-900 font-semibold text-sm mb-1"><?= T::contact_for_pricing ?></h4>
                    <p class="text-amber-700 text-xs mb-3"><?= T::contact_for_pricing_description ?></p>
                    <a href="<?= root ?>contact" class="inline-flex items-center gap-2 px-4 py-2 bg-amber-600 text-white rounded-full hover:bg-amber-700 transition text-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <?= T::contact_us ?>
                    </a>
                </div>

                <!--
                    RATE OPTIONS TABLE
                    Displays all available booking options for this room type

                    COLUMNS:
                    1. Rate Type: Refundable/Non-refundable, breakfast, discounts
                    2. Features: Breakfast included, free cancellation (desktop only)
                    3. Price: Per night + total for entire stay
                    4. Quantity: Dropdown selector (0 = unselected)
                    5. Action: Select/Selected button (fixed 120px width)

                    RESPONSIVE BEHAVIOR:
                    - Features column hidden on mobile (shown in Rate Type column instead)
                    - Table scrolls horizontally on small screens
                -->
                <div x-show="roomData.options && roomData.options.length > 0" class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-100 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-700 uppercase"><?= T::rate_type ?? 'Rate Type' ?></th>
                                <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-700 uppercase hidden sm:table-cell"><?= T::features ?? 'Features' ?></th>
                                <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-700 uppercase"><?= T::price ?? 'Price' ?></th>
                                <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-700 uppercase w-28">
                                    <?= $isGrnConnectRooms ? (T::rooms ?? 'Rooms') : (T::qty ?? 'Qty') ?>
                                </th>
                                <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-700 uppercase w-32"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(option, optIndex) in roomData.options" :key="optIndex">
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors"
                                    :class="isOptionSelected(roomData.room_id, optIndex) ? 'bg-blue-50' : ''">

                                    <!--
                                        RATE TYPE COLUMN
                                        Shows refundability, breakfast inclusion, discounts
                                        On mobile: Also displays features inline
                                    -->
                                    <td class="px-4 py-3">
                                        <?php if ($isGrnConnectRooms): ?>
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold mb-1"
                                              :class="getRefundabilityClass(option)"
                                              x-text="getRefundabilityLabel(option)"></span>
                                        <div x-show="option.occupancy_label"
                                             class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium mb-1 ml-1 bg-blue-50 text-blue-700"
                                             x-text="option.occupancy_label"></div>
                                        <?php else: ?>
                                        <div class="font-medium text-gray-900 mb-1" x-text="option.refundable ? '<?= T::refundable ?>' : '<?= T::non_refundable ?>'"></div>
                                        <?php endif; ?>
                                        <div x-show="option.breakfast_included" class="text-xs text-green-600 font-medium">+ <?= T::breakfast ?></div>
                                        <?php if (!$isGrnConnectRooms): ?>
                                        <div x-show="option.has_promotion || option.discount_percentage > 0"
                                             :title="option.promotion_text || ''"
                                             class="inline-flex items-center gap-1 px-2 py-0.5 bg-red-50 text-red-600 rounded-full text-xs font-medium mt-1">
                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/>
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/>
                                            </svg>
                                            <span x-text="option.promotion_label || `${option.discount_percentage}% <?= T::off ?>`"></span>
                                        </div>
                                        <?php endif; ?>
                                        <!-- Mobile Features -->
                                        <div class="sm:hidden mt-2 space-y-1">
                                            <div class="flex items-center gap-1.5 text-xs text-gray-600">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                                </svg>
                                                <span x-text="option.board_name ? option.board_name : (option.breakfast_included ? '<?= T::breakfast_included ?>' : '<?= T::room_only ?>')"></span>
                                            </div>
                                            <div x-show="option.cancellation_free" class="flex items-center gap-1.5 text-xs text-gray-600">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span><?= T::free_cancellation ?></span>
                                            </div>
                                            <?php if ($isGrnConnectRooms): ?>
                                            <div class="flex flex-wrap gap-1 pt-1">
                                                <div x-show="hasCancellationDetails(option)" class="rate-popover-wrap"
                                                     @mouseenter="positionRatePopover($event)" @mouseleave="hideRatePopover($event)">
                                                    <button type="button" class="rate-info-btn" tabindex="0"><?= T::cancellation_policy ?? 'Cancellation' ?></button>
                                                    <div class="rate-popover left">
                                                        <?php $cpExpr = 'option'; $showHeader = true; include __DIR__ . '/../partials/cancellation-policy-alpine.php'; ?>
                                                    </div>
                                                </div>
                                                <div x-show="option.has_rate_comments || (option.rate_comments_list && option.rate_comments_list.length)" class="rate-popover-wrap"
                                                     @mouseenter="positionRatePopover($event)" @mouseleave="hideRatePopover($event)">
                                                    <button type="button" class="rate-info-btn" tabindex="0"><?= T::rate_comments ?? 'Comments' ?></button>
                                                    <div class="rate-popover left">
                                                        <template x-for="(comment, cIdx) in (option.rate_comments_list || [])" :key="cIdx">
                                                            <div class="mb-2">
                                                                <p class="text-[11px] font-semibold" x-text="comment.label || comment.key"></p>
                                                                <p class="text-[11px] text-gray-600 whitespace-pre-line" x-html="comment.value"></p>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                                <div x-show="option.other_inclusions && option.other_inclusions.length" class="rate-popover-wrap"
                                                     @mouseenter="positionRatePopover($event)" @mouseleave="hideRatePopover($event)">
                                                    <button type="button" class="rate-info-btn" tabindex="0"><?= T::inclusions ?? 'Inclusions' ?></button>
                                                    <div class="rate-popover left">
                                                        <ul class="list-disc pl-4">
                                                            <template x-for="(inc, iIdx) in option.other_inclusions" :key="iIdx">
                                                                <li class="text-[11px] text-gray-600" x-text="inc"></li>
                                                            </template>
                                                        </ul>
                                                    </div>
                                                </div>
                                                <div x-show="option.safe2stay && option.safe2stay.length" class="rate-popover-wrap"
                                                     @mouseenter="positionRatePopover($event)" @mouseleave="hideRatePopover($event)">
                                                    <button type="button" class="rate-info-btn" tabindex="0">Safe2Stay</button>
                                                    <div class="rate-popover left">
                                                        <template x-for="(item, sIdx) in option.safe2stay" :key="sIdx">
                                                            <div class="mb-1">
                                                                <p class="text-[11px] font-semibold" x-text="item.label || item.key"></p>
                                                                <p class="text-[11px] text-gray-600" x-html="item.value == 'Y' ? 'Yes' : item.value == 'N' ? 'No' : item.value"></p>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!--
                                        FEATURES COLUMN
                                        Meal plan, cancellation, rate comments, inclusions, safe2stay
                                    -->
                                    <td class="px-4 py-3 hidden sm:table-cell">
                                        <div class="space-y-1.5 text-xs text-gray-600">
                                            <div class="flex items-center gap-1.5">
                                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                                                </svg>
                                                <span x-text="option.board_name ? option.board_name : (option.breakfast_included ? '<?= T::breakfast ?>' : '<?= T::room_only ?>')"></span>
                                            </div>
                                            <div x-show="option.cancellation_free" class="flex items-center gap-1.5">
                                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span><?= T::free_cancellation ?></span>
                                            </div>

                                            <?php if ($isGrnConnectRooms): ?>
                                            <div class="flex flex-wrap gap-1.5 pt-1">
                                                <div x-show="hasCancellationDetails(option)" class="rate-popover-wrap"
                                                     @mouseenter="positionRatePopover($event)" @mouseleave="hideRatePopover($event)">
                                                    <button type="button" class="rate-info-btn" tabindex="0">
                                                        <span class="material-symbols-outlined text-[14px]">policy</span>
                                                        <?= T::cancellation_policy ?? 'Cancellation' ?>
                                                    </button>
                                                    <div class="rate-popover left">
                                                        <?php $cpExpr = 'option'; $showHeader = true; include __DIR__ . '/../partials/cancellation-policy-alpine.php'; ?>
                                                    </div>
                                                </div>

                                                <div x-show="option.has_rate_comments || (option.rate_comments_list && option.rate_comments_list.length)" class="rate-popover-wrap"
                                                     @mouseenter="positionRatePopover($event)" @mouseleave="hideRatePopover($event)">
                                                    <button type="button" class="rate-info-btn" tabindex="0">
                                                        <span class="material-symbols-outlined text-[14px]">comment</span>
                                                        <?= T::rate_comments ?? 'Rate Comments' ?>
                                                    </button>
                                                    <div class="rate-popover left">
                                                        <p class="text-xs font-semibold text-gray-900 mb-2"><?= T::rate_comments ?? 'Rate Comments' ?></p>
                                                        <template x-if="option.rate_comments_list && option.rate_comments_list.length">
                                                            <div class="space-y-2">
                                                                <template x-for="(comment, cIdx) in option.rate_comments_list" :key="cIdx">
                                                                    <div>
                                                                        <p class="text-[11px] font-semibold text-gray-800" x-text="comment.label || comment.key"></p>
                                                                        <p class="text-[11px] text-gray-600 whitespace-pre-line" x-html="comment.value"></p>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                        <template x-if="!(option.rate_comments_list && option.rate_comments_list.length) && option.rate_comments">
                                                            <p class="text-[11px] text-gray-600 whitespace-pre-line" x-text="option.rate_comments"></p>
                                                        </template>
                                                    </div>
                                                </div>

                                                <div x-show="option.other_inclusions && option.other_inclusions.length" class="rate-popover-wrap"
                                                     @mouseenter="positionRatePopover($event)" @mouseleave="hideRatePopover($event)">
                                                    <button type="button" class="rate-info-btn" tabindex="0">
                                                        <span class="material-symbols-outlined text-[14px]">redeem</span>
                                                        <?= T::inclusions ?? 'Inclusions' ?>
                                                    </button>
                                                    <div class="rate-popover left">
                                                        <p class="text-xs font-semibold text-gray-900 mb-2"><?= defined('T::other_inclusions') ? T::other_inclusions : 'Other Inclusions' ?></p>
                                                        <ul class="list-disc pl-4 space-y-1">
                                                            <template x-for="(inc, iIdx) in option.other_inclusions" :key="iIdx">
                                                                <li class="text-[11px] text-gray-600" x-text="inc"></li>
                                                            </template>
                                                        </ul>
                                                    </div>
                                                </div>

                                                <div x-show="option.safe2stay && option.safe2stay.length" class="rate-popover-wrap"
                                                     @mouseenter="positionRatePopover($event)" @mouseleave="hideRatePopover($event)">
                                                    <button type="button" class="rate-info-btn" tabindex="0">
                                                        <span class="material-symbols-outlined text-[14px]">health_and_safety</span>
                                                        Safe2Stay
                                                    </button>
                                                    <div class="rate-popover left">
                                                        <p class="text-xs font-semibold text-gray-900 mb-2">Safe2Stay</p>
                                                        <template x-for="(item, sIdx) in option.safe2stay" :key="sIdx">
                                                            <div class="mb-2">
                                                                <p class="text-[11px] font-semibold text-gray-800" x-text="item.label || item.key"></p>
                                                                <p class="text-[11px] text-gray-600 whitespace-pre-line" x-html="item.value == 'Y' ? 'Yes' : item.value == 'N' ? 'No' : item.value"></p>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!--
                                        PRICE COLUMN + price_details hover (net / hotel charges)
                                    -->
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex justify-end items-center gap-1">
                                            <?php if ($isGrnConnectRooms): ?>
                                            <div class="font-bold text-base text-gray-900" x-text="`${getCurrencySymbol(currency)}${parseFloat(option.price_per_room_per_night || 0).toFixed(2)}`"></div>
                                            <?php else: ?>
                                            <div class="font-bold text-base text-gray-900" x-text="`${getCurrencySymbol(currency)}${parseFloat(option.price_per_night || 0).toFixed(2)}`"></div>
                                            <?php endif; ?>
                                            <?php if ($isGrnConnectRooms): ?>
                                            <div class="rate-popover-wrap" x-show="hasPriceDetails(option)"
                                                 @mouseenter="positionRatePopover($event)" @mouseleave="hideRatePopover($event)">
                                                <button type="button" class="text-gray-400 hover:text-purple-600" tabindex="0" aria-label="Price breakdown">
                                                    <span class="material-symbols-outlined text-[18px]">info</span>
                                                </button>
                                                <div class="rate-popover">
                                                    <p class="text-xs font-semibold text-gray-900 mb-2"><?= T::price_breakdown ?? 'Price Breakdown' ?></p>
                                                    <template x-for="(group, gIdx) in (option.price_details_groups || [])" :key="gIdx">
                                                        <div class="mb-2">
                                                            <p class="text-[11px] font-semibold text-purple-700 uppercase mb-1" x-text="group.key"></p>
                                                            <template x-for="(line, lIdx) in group.items" :key="lIdx">
                                                                <div class="flex justify-between gap-2 text-[11px] py-0.5 border-b border-gray-50">
                                                                    <span class="text-gray-700">
                                                                        <span x-text="line.name"></span>
                                                                        <span class="text-gray-400" x-text="line.included ? '(included)' : '(not included)'"></span>
                                                                    </span>
                                                                    <span class="text-gray-900 font-medium" x-text="formatChargeAmount(line)"></span>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </template>
                                                    <div x-show="option.excluded_taxes && option.excluded_taxes.length" class="mt-2 pt-2 border-t border-amber-100">
                                                        <p class="text-[11px] font-semibold text-amber-800 mb-1"><?= T::excluded_taxes ?? 'Pay at hotel' ?></p>
                                                        <template x-for="(tax, tIdx) in option.excluded_taxes" :key="tIdx">
                                                            <div class="text-[11px] text-amber-700" x-text="`${tax.name}: ${tax.clientAmount || tax.amount} ${tax.clientCurrency || tax.hotelCurrency || ''}`"></div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($isGrnConnectRooms): ?>
                                        <div class="text-xs text-gray-500"><?= T::per ?> <?= T::room ?> <?= T::per ?> <?= T::night ?></div>
                                        <div class="text-xs text-gray-600 mt-1">
                                            <span x-text="`${getCurrencySymbol(currency)}${parseFloat(option.price_per_room || 0).toFixed(2)}`"></span>
                                            <span class="text-gray-500"><?= T::per ?> <?= T::room ?></span>
                                            <span class="text-gray-400 mx-0.5">·</span>
                                            <span x-text="`${getCurrencySymbol(currency)}${parseFloat(option.total_price || 0).toFixed(2)}`"></span>
                                            <span class="text-gray-500"><?= T::total ?? 'total' ?></span>
                                        </div>
                                        <?php else: ?>
                                        <div class="text-xs text-gray-500"><?= T::per_night ?? 'per night' ?></div>
                                        <div class="text-xs text-gray-600 mt-1">
                                            <span x-text="`${getCurrencySymbol(currency)}${parseFloat(option.total_price || 0).toFixed(2)}`"></span>
                                            <span class="text-gray-500"><?= T::total ?? 'total' ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- QTY -->
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($isGrnConnectRooms): ?>
                                        <span class="inline-flex items-center justify-center min-w-[44px] px-3 py-1.5 rounded-full bg-gray-100 text-gray-700 text-sm font-semibold"
                                              x-text="isOptionSelected(roomData.room_id, optIndex) ? optionRoomCount(option) : 0"></span>
                                        <?php else: ?>
                                        <select x-model="optionQuantities[`${roomData.room_id}_${optIndex}`]"
                                                @change="updateOptionQuantity(roomData.room_id, optIndex, parseInt($event.target.value))"
                                                class="select w-16 px-3 py-1.5 text-sm border-gray-300 rounded-full">
                                            <template x-for="qty in (option.available_quantity || 1) + 1" :key="qty">
                                                <option :value="qty - 1" x-text="qty - 1"></option>
                                            </template>
                                        </select>
                                        <?php endif; ?>
                                    </td>

                                    <td class="px-4 py-3 text-center">
                                        <button @click="selectRoomOption(roomData, option, optIndex)"
                                                class="px-4 py-2 rounded-full font-medium text-sm transition-all whitespace-nowrap min-w-[120px]"
                                                :class="isOptionSelected(roomData.room_id, optIndex) ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'">
                                            <span x-show="!isOptionSelected(roomData.room_id, optIndex)"><?= T::select ?></span>
                                            <span x-show="isOptionSelected(roomData.room_id, optIndex)" class="flex items-center justify-center gap-1.5">
                                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                                    <path d="m9.55 17.308l-4.97-4.97l.714-.713l4.256 4.256l9.156-9.156l.713.714z"/>
                                                </svg>
                                                <span><?= T::selected ?></span>
                                            </span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>

    <!--
        NO ROOMS AVAILABLE STATE
        Shown when API returns successfully but no rooms match criteria
    -->
    <div x-show="!loading && !error && rooms.length === 0" class="p-8 text-center bg-white rounded-3xl border border-gray-200">
        <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
        </svg>
        <p class="text-gray-500 text-sm"><?= T::no_rooms_available ?></p>
    </div>

    <!--
        FIXED FOOTER BAR
        Appears at bottom when user has selected at least one room option

        DISPLAYS:
        - Total number of rooms selected (sum of all quantities)
        - Summary of selected rooms (e.g., "2x Deluxe Room, 1x Suite")
        - Total price for entire booking
        - Continue button to proceed to booking page

        BEHAVIOR:
        - Slides up from bottom with animation when first selection made
        - Updates in real-time as user changes selections/quantities
        - Sticky position ensures always visible
        - Z-index 50 keeps it above other content
    -->
    <div x-show="getTotalSelectedRooms() > 0"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="transform translate-y-full"
         x-transition:enter-end="transform translate-y-0"
         class="room-booking-bar fixed bottom-0 left-0 right-0 bg-white border-t-2 border-gray-200 shadow-2xl z-50">
        <div class="container mx-auto px-4 py-3 md:py-4">
            <div class="flex flex-col md:flex-row gap-4 items-center justify-between">
                <div class="text-center md:text-left w-full md:w-auto">
                    <p class="text-xs text-gray-600 mb-1"><?= T::selected_rooms ?></p>
                    <h4 class="font-semibold text-gray-900 text-base" x-text="`${getTotalSelectedRooms()} <?= T::room ?>${getTotalSelectedRooms() > 1 ? '<?= T::s ?>' : ''} <?= T::selected_lower ?>`"></h4>
                    <p class="text-xs text-gray-500" x-text="getSelectedRoomsSummary()"></p>
                    <?php if ($isGrnConnectRooms): ?>
                    <p class="text-xs mt-1"
                       :class="getTotalSelectedRooms() === requiredRooms ? 'text-green-600' : 'text-amber-600 font-medium'"
                       x-text="getTotalSelectedRooms() === requiredRooms
                         ? 'Requested room count matched'
                         : `Select ${requiredRooms - getTotalSelectedRooms()} more room${(requiredRooms - getTotalSelectedRooms()) !== 1 ? 's' : ''}`"></p>
                    <?php endif; ?>
                </div>
                <div class="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
                    <div class="text-center md:text-right">
                        <p class="text-xs text-gray-600"><?= T::total_price ?></p>
                        <p class="text-xl md:text-2xl font-bold text-gray-900" x-text="`${getCurrencySymbol(currency)}${getTotalPrice().toFixed(2)}`"></p>
                        <?php if ($isGrnConnectRooms): ?>
                        <p class="text-xs text-gray-500">
                            <span x-text="getTotalSelectedRooms()"></span>
                            <span x-text="getTotalSelectedRooms() === 1 ? <?= json_encode(T::room) ?> : <?= json_encode(T::rooms) ?>"></span>
                            ×
                            <span x-text="`${getCurrencySymbol(currency)}${getGrnPricePerRoomPerNight().toFixed(2)}`"></span>
                            ×
                            <span><?= (int)$nights ?></span>
                            <span x-text="stayNights === 1 ? <?= json_encode(T::night) ?> : <?= json_encode(T::nights) ?>"></span>
                        </p>
                        <?php else: ?>
                        <p class="text-xs text-gray-500" x-text="`<?= T::for ?> ${<?= $nights ?>} <?= T::night ?>${<?= $nights ?> > 1 ? '<?= T::s ?>' : ''}`"></p>
                        <?php endif; ?>
                    </div>
                    <button
                        @click="bookNow()"
                        :disabled="bookingLoading"
                        class="w-full md:w-auto px-6 md:px-8 py-2.5 md:py-3 bg-primary text-white rounded-full hover:bg-primary/90 transition font-semibold text-base md:text-lg shadow-lg disabled:opacity-75 flex items-center justify-center gap-2">
                        <span x-show="!bookingLoading" class="flex items-center gap-2">
                            <span class="material-symbols-outlined" style="font-size: 20px;">lock</span>
                            <?= T::continue_booking ?? 'Continue Booking' ?>
                        </span>
                        <span x-show="bookingLoading" class="flex items-center justify-center gap-2">
                            <div class="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent"></div>
                            <?= T::processing ?? 'Processing' ?>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
<?php
$grnSearchId = '';
if ($isGrnConnectRooms) {
    $grnSearchId = (string)($stayDetail['search_id'] ?? '');
    if ($grnSearchId === '' && !empty($urlParts)) {
        $sidIndex = 8 + max(1, (int)$totalRooms);
        if (isset($urlParts[$sidIndex])) {
            $candidateSid = trim((string)$urlParts[$sidIndex]);
            if ($candidateSid !== ''
                && !in_array(strtolower($candidateSid), ['unknown', '_', 'null'], true)
                && !preg_match('/^\d+-\d+/', $candidateSid)
                && preg_match('/^[a-z0-9_-]{8,}$/i', $candidateSid)
            ) {
                $grnSearchId = $candidateSid;
            }
        }
    }
}
?>
/*
 * HOTEL ROOMS ALPINE.JS COMPONENT
 *
 * Manages all room data, filtering, selection, and booking functionality
 *
 * STATE PROPERTIES:
 * - rooms: All available rooms from API
 * - loading: Loading state for API request
 * - error: Error state for failed requests
 * - currency: Currency code (USD, EUR, etc.)
 * - selectedRooms: Selected options with quantities
 * - optionQuantities: Quantity per option
 * - filteredRooms: Rooms after applying filters
 * - roomFilters: Current filter values
 * - availableRoomTypes: Unique room type names
 * - roomPriceRange: Min/max prices
 * - roomPriceSlider: noUiSlider instance
 *
 * KEY METHODS:
 * - fetchRooms(): Load room data from API
 * - extractRoomFilters(): Build filter options from room data
 * - applyRoomFilters(): Filter and sort rooms based on current filters
 * - selectRoomOption(): Toggle selection of a rate option
 * - updateOptionQuantity(): Update quantity for selected option
 * - continueBooking(): Save draft and redirect to booking page
 */
function hotelRooms() {
    return {
        rooms: [],
        loading: true,
        error: false,
        errorMessage: '',
        isPlaceholderPricing: false,
        currency: 'USD',
        isGrnConnect: <?= $isGrnConnectRooms ? 'true' : 'false' ?>,
        stayNights: <?= (int)$nights ?>,
        requiredRooms: <?= (int)$totalRooms ?>,
        totalAdults: <?= (int)$totalAdults ?>,
        totalChildren: <?= (int)$totalChildren ?>,
        roomsConfig: <?= json_encode($roomsData) ?>,
        grnSearchId: '<?= addslashes($grnSearchId) ?>',
        mixableRates: false,
        selectedRooms: {},
        optionQuantities: {},
        bookingLoading: false,

        // Room Filters
        filteredRooms: [],
        roomFiltersExpanded: false,
        roomFilters: {
            selectedType: '',
            priceRange: [0, 100000],
            sortBy: 'default'
        },
        availableRoomTypes: [],
        roomPriceRange: { min: 0, max: 100000 },
        roomPriceSlider: null,

        getCurrencySymbol(currency) {
            return (currency || 'USD') + ' ';
        },

        getRefundabilityLabel(option = {}) {
            if (option.refundability_label) return option.refundability_label;
            const tag = option.refundability_tag
                || (option.non_cancellable ? 'non_cancellable'
                    : (option.non_refundable ? 'non_refundable'
                        : (option.partially_refundable ? 'partially_refundable'
                            : (option.refundable ? 'refundable' : 'non_refundable'))));
            if (tag === 'non_cancellable') return '<?= defined("T::non_cancellable") ? T::non_cancellable : "Non-Cancellable" ?>';
            if (tag === 'partially_refundable') return '<?= defined("T::partially_refundable") ? T::partially_refundable : "Partially refundable" ?>';
            if (tag === 'non_refundable') return '<?= T::non_refundable ?? "Non-refundable" ?>';
            if (tag === 'refundable') return '<?= defined("T::fully_refundable") ? T::fully_refundable : (T::refundable ?? "Fully refundable") ?>';
            return '<?= T::non_refundable ?? "Non-refundable" ?>';
        },

        getRefundabilityClass(option = {}) {
            const tag = option.refundability_tag
                || (option.non_cancellable ? 'non_cancellable'
                    : (option.non_refundable ? 'non_refundable'
                        : (option.partially_refundable ? 'partially_refundable'
                            : (option.refundable ? 'refundable' : 'non_refundable'))));
            if (tag === 'refundable') return 'bg-green-100 text-green-800';
            if (tag === 'partially_refundable') return 'bg-amber-100 text-amber-800';
            if (tag === 'non_cancellable') return 'bg-gray-100 text-gray-800';
            return 'bg-red-100 text-red-800';
        },

        hasPriceDetails(option = {}) {
            return !!(option.price_details_groups && option.price_details_groups.length);
        },

        hasCancellationDetails(option = {}) {
            const tag = this.getCancellationTag(option);
            if (tag === 'non_cancellable' || tag === 'non_refundable') return false;
            const cd = option.cancellation_details || {};
            return !!(option.cancellation_text || option.cancellation_policy
                || cd.cancel_by_date
                || cd.free_cancellation
                || cd.policy_text
                || (cd.details && cd.details.length)
                || cd.no_show_fee_text
                || cd.no_show_fee);
        },

        hasStructuredCancellation(option = {}) {
            const cd = option.cancellation_details || {};
            return !!(
                this.isRefundableStatus(option)
                || this.isPartiallyRefundableStatus(option)
                || (cd.details && cd.details.length)
                || this.formatNoShowFee(option)
            );
        },

        isPartiallyRefundableStatus(option = {}) {
            const cd = option.cancellation_details || {};
            const tag = this.getCancellationTag(option);
            return !!(cd.under_cancellation || tag === 'partially_refundable');
        },

        isRefundableStatus(option = {}) {
            if (this.isPartiallyRefundableStatus(option)) return false;
            const cd = option.cancellation_details || {};
            const tag = this.getCancellationTag(option);
            return tag === 'refundable'
                || !!(cd.free_cancellation)
                || !!(cd.cancel_by_date || cd.cancel_by_date_display);
        },

        getCancellationTag(option = {}) {
            if (option.cancellation_details && option.cancellation_details.refundability_tag) {
                return option.cancellation_details.refundability_tag;
            }
            return option.refundability_tag
                || (option.non_cancellable ? 'non_cancellable'
                    : (option.non_refundable ? 'non_refundable'
                        : (option.partially_refundable ? 'partially_refundable'
                            : (option.refundable ? 'refundable' : 'non_refundable'))));
        },

        formatPolicyDateTime(value) {
            if (!value) return '';
            if (typeof value === 'string' && /IST\s*$/i.test(value) && !value.includes('T')) {
                return value;
            }
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return String(value);
            const day = String(date.getDate()).padStart(2, '0');
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const month = months[date.getMonth()];
            const year = date.getFullYear();
            let hours = date.getHours();
            const minutes = String(date.getMinutes()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            if (hours === 0) hours = 12;
            return `${day} ${month} ${year}, ${String(hours).padStart(2, '0')}:${minutes} ${ampm} IST`;
        },

        formatCancelByDate(option = {}) {
            const cd = option.cancellation_details || {};
            if (cd.cancel_by_date_display) return cd.cancel_by_date_display;
            if (!cd.cancel_by_date) return '';
            return this.formatPolicyDateTime(cd.cancel_by_date);
        },

        formatPolicyChargeAmount(fee) {
            if (fee === null || fee === undefined || fee === '') return '';
            if (typeof fee === 'string' || typeof fee === 'number') return String(fee);
            if (fee.charge_display) return fee.charge_display;
            if (fee.nights !== null && fee.nights !== undefined && fee.nights !== '') {
                const n = Number(fee.nights);
                if (!Number.isFinite(n) || n <= 0) return '';
                return n === 1 ? "Cost of 1 night's stay" : `Cost of ${n} nights' stay`;
            }
            if (fee.percent !== null && fee.percent !== undefined && fee.percent !== '') {
                return `${fee.percent}% of total stay`;
            }
            if (fee.flat_fee !== null && fee.flat_fee !== undefined && fee.flat_fee !== '') {
                return `${fee.currency || ''} ${fee.flat_fee}`.trim();
            }
            if (fee.amount !== null && fee.amount !== undefined && fee.amount !== '') {
                return `${fee.currency || ''} ${fee.amount}`.trim();
            }
            return '';
        },

        formatNoShowFee(option = {}) {
            const cd = option.cancellation_details || {};
            if (cd.no_show_fee_text) return cd.no_show_fee_text;
            return this.formatPolicyChargeAmount(cd.no_show_fee);
        },

        formatCancellationFrom(detail = {}) {
            if (detail.from_display) return detail.from_display;
            return this.formatPolicyDateTime(detail.from) || '—';
        },

        formatCancellationCharge(detail = {}) {
            if (detail.charge_display) return detail.charge_display;
            if (detail.allowed_cancellation === false) return 'Non-cancellable';
            return this.formatPolicyChargeAmount(detail) || '—';
        },

        formatChargeAmount(line) {
            if (!line || line.amount === null || line.amount === undefined) return '';
            if (line.amount_type === 'percent') return `${line.amount}%`;
            const cur = line.currency ? ` ${line.currency}` : '';
            return `${Number(line.amount).toFixed(2)}${cur}`;
        },

        formatCancellationDetail(detail) {
            if (!detail) return '';
            const from = this.formatCancellationFrom(detail);
            const charge = this.formatCancellationCharge(detail);
            const fromPrefix = from && from !== '—' ? `From ${from}: ` : '';
            return `${fromPrefix}${charge}`;
        },

        positionRatePopover(event) {
            const wrap = event.currentTarget;
            const popover = wrap.querySelector('.rate-popover');
            if (!popover) return;

            this.hideAllRatePopovers();

            const trigger = wrap.querySelector('button') || wrap;
            const rect = trigger.getBoundingClientRect();
            const gap = 8;
            const width = Math.min(360, window.innerWidth - 20);

            popover.style.visibility = 'hidden';
            popover.style.display = 'block';
            popover.style.position = 'fixed';
            popover.style.width = `${width}px`;
            popover.style.zIndex = '9999';

            const popHeight = popover.offsetHeight || 280;
            let top = rect.bottom + gap;
            let left = popover.classList.contains('left') ? rect.left : rect.right - width;

            if (top + popHeight > window.innerHeight - gap) {
                top = Math.max(gap, rect.top - popHeight - gap);
            }
            left = Math.max(gap, Math.min(left, window.innerWidth - width - gap));

            popover.style.top = `${top}px`;
            popover.style.left = `${left}px`;
            popover.style.right = 'auto';
            popover.style.visibility = 'visible';
            wrap.classList.add('is-open');
        },

        hideRatePopover(event) {
            const wrap = event.currentTarget;
            wrap.classList.remove('is-open');
            const popover = wrap.querySelector('.rate-popover');
            if (!popover) return;
            popover.style.display = '';
            popover.style.position = '';
            popover.style.top = '';
            popover.style.left = '';
            popover.style.right = '';
            popover.style.width = '';
            popover.style.zIndex = '';
            popover.style.visibility = '';
        },

        hideAllRatePopovers() {
            document.querySelectorAll('.rate-popover-wrap.is-open').forEach((wrap) => {
                this.hideRatePopover({ currentTarget: wrap });
            });
        },

        // INITIALIZATION - Called automatically by Alpine.js when component mounts
        init() {
            this.fetchRooms();
        },

        /*
         * FETCH AVAILABLE ROOMS
         * Calls API endpoint to get all available rooms and rate options
         *
         * API ENDPOINTS:
         * - HotelBeds supplier: /modules/stays/hotelbeds/rooms
         * - Other suppliers: /modules/stays/hotels/rooms
         *
         * REQUEST PAYLOAD:
         * - hotel_id, supplier, checkin, checkout, nationality, rooms config
         *
         * RESPONSE HANDLING:
         * - Sets rooms array with all room types and options
         * - Initializes option quantities to 0
         * - Extracts filter options and applies default filters
         */
        async fetchRooms() {
            this.loading = true;
            this.error = false;

            try {
                const supplier = '<?= $supplier ?>';
                const endpoint = '<?=root?>modules/stays/'+supplier+'/rooms';

                // Prepare body
                <?php
                $checkinFormatted = date('Y-m-d', strtotime($checkin));
                $checkoutFormatted = date('Y-m-d', strtotime($checkout));

                // Ensure childAges exists for each room
                foreach ($roomsData as &$room) {
                    if (!isset($room['childAges'])) {
                        $room['childAges'] = [];
                    }
                }

                // Get Base Currency
                $baseCurrencyData = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
                $baseCurrencyCode = $baseCurrencyData['name'] ?? 'USD';
                $baseCurrencyRate = $baseCurrencyData['rate'] ?? 1;

                // Get Display Currency (Session)
                $sessionCurrency = $_SESSION['app_currency'] ?? $baseCurrencyCode;
                $displayCurrencyData = $db->get('currencies', ['name', 'rate'], ['name' => $sessionCurrency]);
                $displayCurrencyCode = $displayCurrencyData['name'] ?? $baseCurrencyCode;
                $displayCurrencyRate = $displayCurrencyData['rate'] ?? 1;

                // Calculate Conversion Rate (Base to Display)
                // Rate to convert Display -> Base = 1 / (DisplayRate / BaseRate)
                // Or simply: BaseAmount = DisplayAmount * (BaseRate / DisplayRate)
                $conversionFactor = 1;
                if ($displayCurrencyRate > 0) {
                    $conversionFactor = $baseCurrencyRate / $displayCurrencyRate;
                }
                ?>

                const requestPayload = {
                    hotel_id: '<?= $hotelId ?>',
                    supplier: supplier,
                    checkin: '<?= $checkin ?>',
                    checkout: '<?= $checkout ?>',
                    adults: <?= $totalAdults ?>,
                    children: <?= $totalChildren ?>,
                    rooms: <?= $totalRooms ?>,
                    nationality: '<?= $nationality ?>',
                    currency: '<?= $_SESSION['app_currency'] ?? 'USD' ?>',
                    rooms_data: this.roomsConfig
                };

                if (this.isGrnConnect && this.grnSearchId) {
                    requestPayload.search_id = this.grnSearchId;
                }

                // ✅ Add hotel_chain only for Travelport
                const hotelChain = '<?= $hotelChain ?>';
                if (hotelChain && hotelChain !== '_') {
                    requestPayload.hotel_chain = hotelChain;
                }

                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestPayload)
                });

                const data = await response.json();

                if (data.success) {
                    this.rooms = data.data.rooms || [];
                    this.currency = data.data.currency || 'USD';
                    this.mixableRates = !!(data.data.mixable);
                    if (data.data.search_id) this.grnSearchId = data.data.search_id;

                    // CRITICAL: Store book_hash from API response
                    this.book_hash = data.data.book_hash || '';

                    // Store dev mode info and test credentials if present
                    this.dev_mode_data = {
                        enabled: data.data.dev_mode_enabled || false,
                        test_hotel_id: data.data.actual_hotel_id_used || null,
                        prebook_status: data.data.prebook_status || null
                    };

                    if (this.dev_mode_data.enabled) {
                        console.log('🧪 DEV MODE ACTIVE');
                        console.log('📍 Test Hotel Used:', this.dev_mode_data.test_hotel_id);
                        console.log('✅ Prebook Status:', this.dev_mode_data.prebook_status);
                    }

                    // Inject Currency Info
                    this.baseCurrency = '<?= $baseCurrencyCode ?>';
                    this.conversionFactor = <?= $conversionFactor ?>;

                    this.isPlaceholderPricing = data.data.is_placeholder_pricing || false;
                    // Initialize option quantities
                    this.rooms.forEach(room => {
                        if (room.options) {
                            room.options.forEach((opt, idx) => {
                                this.optionQuantities[`${room.room_id}_${idx}`] = 0;
                            });
                        }
                    });

                    // Extract filter data and initialize filters
                    this.extractRoomFilters();
                    this.applyRoomFilters();
                } else {
                    this.error = true;
                    this.errorMessage = data.message || 'Failed to load rooms';
                }
            } catch (err) {
                console.error('Error fetching rooms:', err);
                this.error = true;
                this.errorMessage = 'Network error. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        roomCapacity(roomData, option) {
            const fromOcc = option?.max_room_occupancy || {};
            const maxAdults = Math.max(
                1,
                parseInt(fromOcc.max_adults || 0, 10) || 0,
                parseInt(roomData?.max_adults || 0, 10) || 0,
                parseInt(option?.adults || 0, 10) || 0,
                2
            );
            const maxChildren = Math.max(
                0,
                parseInt(fromOcc.max_children || 0, 10) || 0,
                parseInt(roomData?.max_children || 0, 10) || 0,
                parseInt(option?.children || 0, 10) || 0
            );
            return { maxAdults, maxChildren };
        },

        assignedAdults(excludeKey = null) {
            return Object.entries(this.selectedRooms).reduce((sum, [k, room]) => {
                if (excludeKey && k === excludeKey) return sum;
                return sum + (parseInt(room.adults, 10) || 0);
            }, 0);
        },

        assignedChildren(excludeKey = null) {
            return Object.entries(this.selectedRooms).reduce((sum, [k, room]) => {
                if (excludeKey && k === excludeKey) return sum;
                return sum + (parseInt(room.children, 10) || 0);
            }, 0);
        },

        remainingAdults(excludeKey = null) {
            return Math.max(0, this.totalAdults - this.assignedAdults(excludeKey));
        },

        remainingChildren(excludeKey = null) {
            return Math.max(0, this.totalChildren - this.assignedChildren(excludeKey));
        },

        adultChoices(roomId, optionIndex, roomData, option) {
            const key = `${roomId}_${optionIndex}`;
            const cap = this.roomCapacity(roomData, option).maxAdults;
            const current = parseInt(this.selectedRooms[key]?.adults, 10) || 1;
            const maxAllowed = Math.min(cap, this.remainingAdults(key) + current);
            const choices = [];
            for (let n = 1; n <= Math.max(1, maxAllowed); n++) choices.push(n);
            if (!choices.includes(current) && current > 0) choices.push(current);
            return choices.sort((a, b) => a - b);
        },

        childChoices(roomId, optionIndex, roomData, option) {
            const key = `${roomId}_${optionIndex}`;
            const cap = this.roomCapacity(roomData, option).maxChildren;
            const current = parseInt(this.selectedRooms[key]?.children, 10) || 0;
            const maxAllowed = Math.min(cap, this.remainingChildren(key) + current);
            const choices = [];
            for (let n = 0; n <= Math.max(0, maxAllowed); n++) choices.push(n);
            if (!choices.includes(current) && current >= 0) choices.push(current);
            return [...new Set(choices)].sort((a, b) => a - b);
        },

        suggestAdults(roomData, option) {
            const cap = this.roomCapacity(roomData, option).maxAdults;
            const rem = this.remainingAdults();
            if (rem <= 0) return 1;
            const slotIndex = Object.keys(this.selectedRooms).length;
            const slot = Array.isArray(this.roomsConfig) ? this.roomsConfig[slotIndex] : null;
            if (slot && slot.adults) {
                return Math.max(1, Math.min(cap, rem, parseInt(slot.adults, 10) || 1));
            }
            const roomsLeftIncludingThis = Math.max(1, this.requiredRooms - slotIndex);
            const leaveForOthers = Math.max(0, roomsLeftIncludingThis - 1);
            const maxTake = Math.max(1, rem - leaveForOthers);
            return Math.max(1, Math.min(cap, maxTake, rem));
        },

        suggestChildren(roomData, option) {
            const cap = this.roomCapacity(roomData, option).maxChildren;
            const rem = this.remainingChildren();
            if (rem <= 0 || cap <= 0) return 0;
            const slotIndex = Object.keys(this.selectedRooms).length;
            const slot = Array.isArray(this.roomsConfig) ? this.roomsConfig[slotIndex] : null;
            if (slot) {
                const slotChildren = parseInt(slot.children, 10) || 0;
                return Math.max(0, Math.min(cap, rem, slotChildren));
            }
            const roomsLeftIncludingThis = Math.max(1, this.requiredRooms - slotIndex);
            const leaveForOthers = Math.max(0, roomsLeftIncludingThis - 1);
            const maxTake = Math.max(0, rem - leaveForOthers);
            return Math.min(cap, maxTake, rem);
        },

        updateRoomGuests(roomId, optionIndex, roomData, option, field, value) {
            const key = `${roomId}_${optionIndex}`;
            const sel = this.selectedRooms[key];
            if (!sel) return;
            const cap = this.roomCapacity(roomData, option);
            value = parseInt(value, 10) || 0;
            if (field === 'adults') {
                const maxAllowed = Math.min(cap.maxAdults, this.remainingAdults(key) + (sel.adults || 0));
                sel.adults = Math.max(1, Math.min(maxAllowed, value));
            } else {
                const maxAllowed = Math.min(cap.maxChildren, this.remainingChildren(key) + (sel.children || 0));
                sel.children = Math.max(0, Math.min(maxAllowed, value));
                const ages = Array.isArray(sel.childAges) ? sel.childAges.slice(0, sel.children) : [];
                while (ages.length < sel.children) ages.push(1);
                sel.childAges = ages;
            }
            this.selectedRooms[key] = { ...sel };
        },

        optionRoomCount(option) {
            if (!this.isGrnConnect) return 1;
            const rateRooms = option?.package_rooms || option?.rate_rooms;
            return Math.max(
                1,
                parseInt(option?.allocation_size || 0, 10) || 0,
                parseInt(option?.rooms_in_rate || 0, 10) || 0,
                Array.isArray(rateRooms) ? rateRooms.length : 0
            );
        },

        /*
         * UPDATE OPTION QUANTITY
         */
        updateOptionQuantity(roomId, optionIndex, quantity) {
            const key = `${roomId}_${optionIndex}`;
            this.optionQuantities[key] = quantity;

            // If quantity is 0, remove from selected rooms
            if (quantity === 0 && this.selectedRooms[key]) {
                delete this.selectedRooms[key];
            } else if (quantity > 0 && this.selectedRooms[key]) {
                // Update quantity in selected room
                this.selectedRooms[key].quantity = quantity;
            }
        },

        getOptionQuantity(roomId, optionIndex) {
            const key = `${roomId}_${optionIndex}`;
            return this.optionQuantities[key] || 0;
        },

        /*
         * SELECT/UNSELECT ROOM OPTION
         * Called when user clicks Select/Selected button
         *
         * BEHAVIOR:
         * - If already selected: Unselect and reset quantity to 0
         * - If not selected: Add to selectedRooms with quantity (auto-set to 1 if 0)
         * - Multiple options from same room type are allowed
         *
         * SELECTION KEY FORMAT: 'room_id_optionIndex'
         */
        selectRoomOption(roomData, option, optionIndex) {
            const key = `${roomData.room_id}_${optionIndex}`;
            const roomCount = Math.max(1, this.optionRoomCount(option));

            if (this.selectedRooms[key]) {
                const next = { ...this.selectedRooms };
                delete next[key];
                this.selectedRooms = next;
                this.optionQuantities[key] = 0;
                return;
            }

            if (this.isGrnConnect) {
                const group = (option.group_code || '').trim();
                const packageId = String(option.package_id || option.option_id || '');

                // A multi-room rate_key replaces earlier selections because
                // all of its component rooms must travel together.
                if (roomCount > 1) {
                    this.selectedRooms = {};
                    Object.keys(this.optionQuantities).forEach(k => { this.optionQuantities[k] = 0; });
                }

                const existingGroups = Object.values(this.selectedRooms)
                    .map(s => (s.option?.group_code || '').trim())
                    .filter(Boolean);
                if (group && existingGroups.length && !existingGroups.includes(group)) {
                    vt.warn('Selected rooms must be from the same rate group. Please choose a compatible room option.');
                    return;
                }

                // A multi-room rate_key is one atomic package. Selecting it
                // replaces prior component selections; its rooms[] cannot be
                // split, independently toggled, or combined with another copy.
                const nextSelected = this.getTotalSelectedRooms() + roomCount;
                if (nextSelected > this.requiredRooms) {
                    this.selectedRooms = {};
                    Object.keys(this.optionQuantities).forEach(k => { this.optionQuantities[k] = 0; });
                }

                this.optionQuantities[key] = roomCount;
                this.selectedRooms = {
                    ...this.selectedRooms,
                    [key]: {
                        quantity: roomCount,
                        option_index: optionIndex,
                        option: option,
                        room_id: roomData.room_id,
                        room_name: roomData.room_name,
                        adults: option.adults || null,
                        children: option.children || 0,
                        childAges: [],
                        package: roomCount > 1,
                        package_id: packageId
                    }
                };
                return;
            }

            let quantity = this.getOptionQuantity(roomData.room_id, optionIndex);
            if (quantity === 0) {
                this.optionQuantities[key] = 1;
                quantity = 1;
            }

            this.selectedRooms[key] = {
                quantity: quantity,
                option_index: optionIndex,
                option: option,
                room_id: roomData.room_id,
                room_name: roomData.room_name
            };
        },

        /*
         * OPEN ROOM IMAGE GALLERY
         * Opens FSLightbox gallery to view room images
         *
         * FEATURES:
         * - Filters out no_img.jpg placeholder images
         * - Trims whitespace from image URLs
         * - Creates fresh lightbox instance to prevent conflicts
         * - Explicitly sets type as 'image' for each source
         * - Fallback to new tab if FSLightbox fails to load
         */
        openRoomImageGallery(images, startIndex = 0) {
            if (!images || images.length === 0) return;

            // Filter out no_img.jpg images and ensure valid URLs
            const validImages = images
                .filter(img => img && !img.includes('no_img.jpg'))
                .map(img => img.trim());

            if (validImages.length === 0) return;

            // Ensure startIndex is within bounds
            const slideIndex = Math.max(0, Math.min(startIndex, validImages.length - 1)) + 1;

            // Check if FSLightbox is loaded
            if (typeof FsLightbox === 'undefined') {
                console.error('FSLightbox is not loaded yet');
                // Fallback - open first image in new tab
                if (validImages.length > 0) {
                    window.open(validImages[startIndex] || validImages[0], '_blank');
                }
                return;
            }

            try {
                // Destroy existing instance to prevent conflicts
                if (window.fsLightboxInstances?.roomGallery) {
                    delete window.fsLightboxInstances.roomGallery;
                }

                // Create fresh lightbox instance with sources
                const lightbox = new FsLightbox();
                lightbox.props.sources = validImages;
                lightbox.props.types = validImages.map(() => 'image');
                lightbox.props.slide = slideIndex;

                // Store instance
                if (!window.fsLightboxInstances) {
                    window.fsLightboxInstances = {};
                }
                window.fsLightboxInstances.roomGallery = lightbox;

                // Open lightbox
                lightbox.open();
            } catch (error) {
                console.error('Error opening FSLightbox:', error);
                // Fallback - open image in new tab
                window.open(validImages[startIndex] || validImages[0], '_blank');
            }
        },

        isOptionSelected(roomId, optionIndex) {
            const key = `${roomId}_${optionIndex}`;
            return this.selectedRooms[key] !== undefined;
        },

        /*
         * EXTRACT FILTER OPTIONS FROM ROOM DATA
         * Analyzes fetched rooms to build filter dropdowns and ranges
         *
         * EXTRACTS:
         * - availableRoomTypes: Unique room type names (sorted)
         * - roomPriceRange: Min/max prices from all options
         *
         * TRIGGERS: Initializes noUiSlider for price range
         */
        extractRoomFilters() {
            // Extract unique room types
            this.availableRoomTypes = [...new Set(this.rooms.map(r => r.room_name))].sort();

            // Calculate price range from all room options
            let minPrice = Infinity;
            let maxPrice = 0;

            this.rooms.forEach(room => {
                if (room.options && room.options.length > 0) {
                    room.options.forEach(opt => {
                        const price = parseFloat(opt.total_price);
                        if (price < minPrice) minPrice = price;
                        if (price > maxPrice) maxPrice = price;
                    });
                }
            });

            this.roomPriceRange.min = minPrice === Infinity ? 0 : Math.floor(minPrice);
            this.roomPriceRange.max = maxPrice === 0 ? 100000 : Math.ceil(maxPrice);
            this.roomFilters.priceRange = [this.roomPriceRange.min, this.roomPriceRange.max];

            // Initialize price slider
            this.$nextTick(() => {
                setTimeout(() => this.initRoomPriceSlider(), 300);
            });
        },

        /*
         * INITIALIZE PRICE RANGE SLIDER
         * Creates noUiSlider instance with dynamic min/max from room data
         *
         * CONFIGURATION:
         * - Range: Dynamic based on actual room prices
         * - Step: Calculated as 1% of total range
         * - Connect: True (fills bar between handles)
         * - Events: 'update' for real-time, 'change' for filter trigger
         */
        initRoomPriceSlider() {
            const slider = document.getElementById('roomPriceSlider');
            if (!slider || typeof noUiSlider === 'undefined') return;

            if (this.roomPriceSlider) {
                this.roomPriceSlider.destroy();
            }

            this.roomPriceSlider = noUiSlider.create(slider, {
                start: [this.roomPriceRange.min, this.roomPriceRange.max],
                connect: true,
                range: {
                    'min': this.roomPriceRange.min,
                    'max': this.roomPriceRange.max
                },
                step: Math.ceil((this.roomPriceRange.max - this.roomPriceRange.min) / 100),
                format: {
                    to: (value) => Math.round(value),
                    from: (value) => Number(value)
                }
            });

            this.roomPriceSlider.on('update', (values) => {
                this.roomFilters.priceRange = [parseInt(values[0]), parseInt(values[1])];
            });

            this.roomPriceSlider.on('change', () => {
                this.applyRoomFilters();
            });
        },

        /*
         * APPLY ROOM FILTERS
         * Filters and sorts rooms based on current filter selections
         *
         * FILTER LOGIC:
         * 1. Room Type: Exact match filtering
         * 2. Price Range: Checks if ANY option falls within range
         * 3. Sorting: price_low, price_high, name_asc, name_desc, or default
         */
        applyRoomFilters() {
            let filtered = this.rooms.map(room => ({...room, visible: true}));

            // Filter by room type
            if (this.roomFilters.selectedType) {
                filtered = filtered.filter(r => r.room_name === this.roomFilters.selectedType);
            }

            // Filter by price range - check if ANY option is within range
            filtered = filtered.filter(room => {
                if (!room.options || room.options.length === 0) return true;
                return room.options.some(opt => {
                    const price = parseFloat(opt.total_price);
                    return price >= this.roomFilters.priceRange[0] && price <= this.roomFilters.priceRange[1];
                });
            });

            // Sort rooms
            if (this.roomFilters.sortBy === 'price_low') {
                filtered.sort((a, b) => {
                    const priceA = a.options?.[0]?.total_price || 0;
                    const priceB = b.options?.[0]?.total_price || 0;
                    return parseFloat(priceA) - parseFloat(priceB);
                });
            } else if (this.roomFilters.sortBy === 'price_high') {
                filtered.sort((a, b) => {
                    const priceA = a.options?.[0]?.total_price || 0;
                    const priceB = b.options?.[0]?.total_price || 0;
                    return parseFloat(priceB) - parseFloat(priceA);
                });
            }

            this.filteredRooms = filtered;
        },

        /*
         * RESET ALL FILTERS
         * Clears all filter selections and resets to defaults
         */
        resetRoomFilters() {
            this.roomFilters.selectedType = '';
            this.roomFilters.priceRange = [this.roomPriceRange.min, this.roomPriceRange.max];
            this.roomFilters.sortBy = 'default';

            if (this.roomPriceSlider) {
                this.roomPriceSlider.set([this.roomPriceRange.min, this.roomPriceRange.max]);
            }

            this.applyRoomFilters();
        },

        getFilteredCount() {
            return this.filteredRooms.filter(r => r.visible).length;
        },

        // GET TOTAL SELECTED ROOMS - Sum of all quantities across all selections
        getTotalSelectedRooms() {
            return Object.values(this.selectedRooms).reduce((total, room) => total + room.quantity, 0);
        },

        // GET TOTAL BOOKING PRICE - Formula: Sum of (option.total_price * quantity)
        getTotalPrice() {
            return Object.values(this.selectedRooms).reduce((total, room) => {
                const price = parseFloat(room.option?.total_price) || 0;
                if (room.package) return total + price;
                return total + (price * (parseInt(room.quantity, 10) || 1));
            }, 0);
        },

        getGrnPricePerRoom() {
            const rooms = this.getTotalSelectedRooms();
            if (rooms <= 0) return 0;
            return this.getTotalPrice() / rooms;
        },

        getGrnPricePerRoomPerNight() {
            const nights = Math.max(1, parseInt(this.stayNights, 10) || 1);
            return this.getGrnPricePerRoom() / nights;
        },

        // GET SELECTED ROOMS SUMMARY - Creates text like "2x Deluxe Room, 1x Suite"
        getSelectedRoomsSummary() {
            const rooms = Object.values(this.selectedRooms);
            if (rooms.length === 0) return '';

            if (this.isGrnConnect) {
                return rooms.map(r => {
                    const label = r.option?.package_label || r.room_name;
                    return r.package
                        ? `${r.room_name}: ${label}`
                        : `${parseInt(r.quantity, 10) || 1}x ${label}`;
                }).join(' · ');
            }

            if (rooms.length === 1) {
                const room = rooms[0];
                return `${room.quantity}x ${room.room_name}`;
            }

            return rooms.map(r => `${r.quantity}x ${r.room_name}`).join(', ');
        },

        /*
         * BOOK NOW
         */
        async bookNow() {
            const requiredRooms = <?= intval($totalRooms) ?>;
            const selections = Object.values(this.selectedRooms);
            const selectedCount = this.getTotalSelectedRooms();

            if (!selections.length || selectedCount === 0) {
                vt.warn('<?= T::please_select_room ?? "Please select at least one room" ?>');
                return;
            }

            if (this.isGrnConnect) {
                if (selectedCount !== requiredRooms) {
                    vt.warn(`You searched for ${requiredRooms} room(s). Please select room options totaling ${requiredRooms}.`);
                    return;
                }
                const groups = [...new Set(
                    selections.map(s => (s.option?.group_code || '').trim()).filter(Boolean)
                )];
                if (groups.length > 1) {
                    vt.warn('Selected rooms must be from the same rate group. Please choose compatible room options.');
                    return;
                }
            } else if (selectedCount !== requiredRooms) {
                vt.warn('<?= defined("T::update_search_for_rooms") ? T::update_search_for_rooms : "You have searched for {required} room(s). You must select {required} room(s) or update your search results." ?>'.replace(/\{required\}/g, requiredRooms));
                return;
            }

            this.bookingLoading = true;

            try {
                // Determine robust conversion factor based on API currency
                const apiCurrency = this.currency || 'USD';
                const baseCurrency = this.baseCurrency || 'USD';

                let dynamicFactor = 1;
                if (this.currencyRates && this.currencyRates[apiCurrency] && this.currencyRates[baseCurrency]) {
                    const apiRate = parseFloat(this.currencyRates[apiCurrency]);
                    const baseRate = parseFloat(this.currencyRates[baseCurrency]);
                    if (apiRate > 0) {
                        dynamicFactor = baseRate / apiRate;
                    }
                } else {
                    // Fallback to PHP injected factor if rates missing (unlikely)
                    dynamicFactor = this.conversionFactor || 1;
                }

                // Build complete booking data for each selected room and convert prices to Base Currency
                const selectedRoomsData = Object.values(this.selectedRooms).map(room => {
                    // Start with deep copy of option
                    const optionBase = JSON.parse(JSON.stringify(room.option));

                    // Convert monetary fields to Base Currency
                    ['price_per_night', 'price_per_room', 'price_per_room_per_night', 'total_price', 'price', 'old_price', 'base_price'].forEach(key => {
                        if (optionBase[key] !== undefined && optionBase[key] !== null) {
                            optionBase[key] = (parseFloat(optionBase[key]) * dynamicFactor).toFixed(2);
                        }
                    });

                    // Find the full room data to get images
                    const fullRoomData = this.rooms.find(r => r.room_id === room.room_id);
                    const roomImages = fullRoomData?.room_images || [];
                    const roomMainImage = fullRoomData?.room_main_image || roomImages[0] || null;

                    return {
                        room_id: room.room_id,
                        room_name: room.room_name,
                        quantity: room.quantity,
                        option_index: room.option_index,
                        option: optionBase,
                        room_images: roomImages,
                        room_main_image: roomMainImage,
                        adults: room.adults || null,
                        children: room.children || 0,
                        childAges: room.childAges || []
                    };
                });

                const roomsDataForBooking = <?= json_encode($roomsData) ?>;

                // Get hotel data from parent component (stay.php)
                const hotelData = window.hotelDetailsComponent?.hotelData || {};

                // Prepare booking data
                // Calculate Base Currency Total (using dynamic factor)
                const totalDisplay = this.getTotalPrice();
                const totalBase = totalDisplay * dynamicFactor;

                // Calculate Net Total (Actual Price without markup)
                // RATEHAWK COMMISSION FIX: Use original_price (already includes all nights)
                const actualTotalDisplay = Object.values(this.selectedRooms).reduce((total, room) => {
                    // original_price and base_price already include total for all nights
                    // If not available, fallback to price_per_night (for backward compatibility)
                    const basePrice = parseFloat(room.option.original_price || room.option.base_price || 0);
                    return total + (room.package ? basePrice : (basePrice * room.quantity));
                }, 0);
                const actualTotalBase = actualTotalDisplay * dynamicFactor;

                // Prepare booking data
                const bookingData = {
                    hotel_id: '<?= $hotelId ?>',
                    hotel_name: hotelData.name || '<?= $hotelName ?>',
                    hotel_images: hotelData.images || [],
                    hotel_address: hotelData.address || hotelData.location || '',
                    hotel_email: hotelData.email || '',
                    hotel_phone_number: hotelData.phone_number || '',
                    hotel_stars: hotelData.stars || 0,
                    accommodation_type: hotelData.accommodation_type,
                    supplier: '<?= $supplier ?>',
                    checkin: '<?= $checkin ?>',
                    checkout: '<?= $checkout ?>',
                    nights: <?= $nights ?>,
                    nationality: '<?= $nationality ?>',
                    rooms_data: roomsDataForBooking,
                    adults: <?= $totalAdults ?>,
                    children: <?= $totalChildren ?>,
                    selected_rooms: selectedRoomsData,

                    // SEND BASE CURRENCY TOTALS
                    actual_amount: actualTotalBase.toFixed(2), // Net Total
                    total_amount: totalBase.toFixed(2),       // Selling Total
                    currency: baseCurrency,

                    // KEEP DISPLAY DATA FOR REFERENCE
                    display_actual_total: actualTotalDisplay,
                    display_total: totalDisplay,
                    display_currency: apiCurrency,

                    rooms_count: this.getTotalSelectedRooms(),
                    created_at: new Date().toISOString(),

                    ...(this.isGrnConnect ? {
                        price_per_room: (this.getGrnPricePerRoom() * dynamicFactor).toFixed(2),
                        price_per_room_per_night: (this.getGrnPricePerRoomPerNight() * dynamicFactor).toFixed(2),
                        display_price_per_room: this.getGrnPricePerRoom().toFixed(2),
                        display_price_per_room_per_night: this.getGrnPricePerRoomPerNight().toFixed(2),
                    } : {}),

                    // CRITICAL: Include book_hash from rooms API response
                    book_hash: this.book_hash || '',

                    // DEV MODE: Auto-include test credentials when dev_mode is enabled
                    ratehawk_dev_mode: this.dev_mode_data.enabled,
                    ratehawk_test_hotel_id: this.dev_mode_data.test_hotel_id,
                    ratehawk_prebook_status: this.dev_mode_data.prebook_status
                };

                // Save booking draft
                const response = await fetch('<?= root ?>api/stay/booking/save-draft', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(bookingData)
                });

                const result = await response.json();

                if (result.success && result.hash) {
                    // Redirect to booking page with hash
                    window.location.href = '<?= root ?>stays/booking/' + result.hash;
                } else {
                    vt.error(result.message || '<?= T::booking_failed ?? "Booking failed. Please try again." ?>');
                }
            } catch (error) {
                console.error('Error creating booking:', error);
                vt.error('<?= T::error_creating_booking ?? "Error creating booking. Please try again." ?>');
                this.bookingLoading = false; // Only reset on error
            }
        },
    }
}
</script>

<!--
    FSLIGHTBOX LIBRARY LOADER

    Loads FSLightbox JavaScript library with CDN fallbacks

    FEATURES:
    - Attempts multiple CDN sources if one fails
    - Logs success/failure for debugging
    - Used for room image galleries

    CDN SOURCES:
    1. jsDelivr (primary)
    2. unpkg (backup)
    3. fslightbox.com (final fallback)
-->
<script>
// Load FSLightbox with multiple CDN fallbacks
(function() {
    const cdnUrls = [
        'https://cdn.jsdelivr.net/npm/fslightbox@3.4.1/index.js',
        'https://unpkg.com/fslightbox@3.4.1/index.js',
        'https://fslightbox.com/javascripts/fsLightbox.js'
    ];

    let currentIndex = 0;

    function loadScript() {
        if (currentIndex >= cdnUrls.length) {
            console.error('All FSLightbox CDNs failed to load');
            return;
        }

        const script = document.createElement('script');
        script.src = cdnUrls[currentIndex];
        script.onload = function() {
            console.log('FSLightbox loaded successfully from:', cdnUrls[currentIndex]);
        };
        script.onerror = function() {
            console.warn('Failed to load FSLightbox from:', cdnUrls[currentIndex]);
            currentIndex++;
            loadScript();
        };
        document.head.appendChild(script);
    }

    loadScript();
})();
</script>
