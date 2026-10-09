<?php
// GRNConnect booking-page Alpine methods. Included inside bookingForm() when supplier is grnconnect.
?>
        hasValidGrnRecheck() {
            const r = this.grnRecheck;
            return !!(
                this.grnRecheckDone
                && r
                && typeof r === 'object'
                && String(r.search_id || '').trim()
                && String(r.rate_key || '').trim()
                && String(r.group_code || '').trim()
            );
        },

        buildGrnRoomsReturnUrl() {
            const bookingData = <?= json_encode($bookingData) ?>;
            const hotelName = (bookingData.hotel_name || 'hotel').toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '') || 'hotel';
            const hotelId = bookingData.hotel_id || '';
            const supplier = (bookingData.supplier || 'grnconnect').toLowerCase();
            const chain = '_';
            const checkin = bookingData.checkin || '';
            const checkout = bookingData.checkout || '';
            const nationality = (bookingData.nationality || 'US').toUpperCase();
            const roomsData = Array.isArray(bookingData.rooms_data) ? bookingData.rooms_data : [];
            const totalRooms = roomsData.length || Number(bookingData.rooms_count || 1) || 1;
            const roomConfigs = roomsData.length
                ? roomsData.map(r => {
                    const ages = (r.childAges && r.childAges.length) ? '-' + r.childAges.join('-') : '';
                    return `${r.adults || 2}-${r.children || 0}${ages}`;
                }).join('/')
                : '2-0';
            return `<?= root ?>stay/${hotelName}/${hotelId}/${supplier}/${chain}/${checkin}/${checkout}/${nationality}/${totalRooms}/${roomConfigs}`;
        },

        returnToGrnRoomSelection(message) {
            const msg = message || 'Sorry, this room is no longer available. Please select another option.';
            this.showError(msg);
            setTimeout(() => {
                window.location.href = this.buildGrnRoomsReturnUrl();
            }, 1800);
        },

        /**
         * Keep sidebar unit prices in sync with recheck total_price.
         * Updates: price_per_room, price_per_room_per_night, price_per_night.
         */
        syncGrnOptionUnitPrices(option) {
            if (!option || typeof option !== 'object') return option;
            const nights = Math.max(1, parseInt(this.nights, 10) || 1);
            const roomsInRate = Math.max(1, parseInt(option.rooms_in_rate || option.allocation_size || 1, 10) || 1);
            const total = parseFloat(option.total_price);
            if (!(total > 0)) return option;

            const pricePerRoom = Math.round((total / roomsInRate) * 100) / 100;
            const pricePerRoomPerNight = Math.round((total / roomsInRate / nights) * 100) / 100;
            const pricePerNight = Math.round((total / nights) * 100) / 100;

            return {
                ...option,
                price_per_room: pricePerRoom,
                price_per_room_per_night: pricePerRoomPerNight,
                price_per_night: pricePerNight,
            };
        },

        applyGrnVerifiedRoomPrices(rooms) {
            if (!Array.isArray(rooms)) return rooms;
            return rooms.map(room => {
                if (!room?.option) return room;
                const option = this.syncGrnOptionUnitPrices(room.option);
                return {
                    ...room,
                    option,
                    selected_rate: { ...(room.selected_rate || {}), ...option },
                };
            });
        },

        acceptGrnPriceChange() {
            if (!this.grnPriceChanged || this.grnPriceAccepted) return;

            // Apply pending room/price updates only after explicit accept
            if (this.grnPendingRoomsUpdate) {
                this.selectedRoomsData = this.applyGrnVerifiedRoomPrices(this.grnPendingRoomsUpdate);
                this.grnPendingRoomsUpdate = null;
            } else {
                // Fallback: recompute unit prices on current rooms from total_price
                this.selectedRoomsData = this.applyGrnVerifiedRoomPrices(this.selectedRoomsData);
            }
            if (this.grnPendingTotals) {
                const t = this.grnPendingTotals;
                this.totalAmountBase = t.totalAmountBase;
                this.totalAmount = t.totalAmount;
                this.totalAmountDisplay = t.totalAmountDisplay;
                if (typeof t.taxAmount !== 'undefined') {
                    this.taxAmount = t.taxAmount;
                    this.taxAmountDisplay = t.taxAmountDisplay;
                    this.totalWithTax = t.totalWithTax;
                    this.totalWithTaxDisplay = t.totalWithTaxDisplay;
                }
                this.grnPendingTotals = null;
            }

            this.grnPriceAccepted = true;
            if (this.grnRecheck && typeof this.grnRecheck === 'object') {
                this.grnRecheck = {
                    ...this.grnRecheck,
                    price_accepted: true,
                    price_changed: true,
                    previous_price: this.grnPreviousPrice,
                    updated_price: this.grnUpdatedPrice,
                    accepted_net_price: this.grnRecheck.accepted_net_price || this.grnUpdatedPrice,
                    currency: this.grnPriceCurrency,
                    accepted_at: new Date().toISOString(),
                };
            }
            this.showSuccess('Updated price accepted. You may continue with booking.');
        },

        rejectGrnPriceChange() {
            this.grnPendingRoomsUpdate = null;
            this.grnPendingTotals = null;
            this.grnPriceAccepted = false;
            window.location.href = this.buildGrnRoomsReturnUrl();
        },

        async runGrnRecheck(force = false) {
            // Booking page MUST call GRN recheck once per rate_key before book.
            // Share one in-flight promise so Alpine remounts cannot skip the API call.
            if (!window.__grnRecheckPromises) {
                window.__grnRecheckPromises = {};
            }
            const hash = this.bookingHash || 'grn';

            if (!force && this.hasValidGrnRecheck()) {
                return true;
            }

            if (!force && window.__grnRecheckPromises[hash]) {
                try {
                    const shared = await window.__grnRecheckPromises[hash];
                    if (shared && shared.ok) {
                        this.applyGrnRecheckResult(shared.data, shared.recheckedRooms);
                        return this.hasValidGrnRecheck();
                    }
                } catch (e) {
                    // Continue with a fresh attempt below.
                }
            }

            this.grnRecheckLoading = true;
            this.grnRecheckDone = false;
            this.grnRecheckError = '';
            this.grnPriceChanged = false;
            this.grnPriceAccepted = false;
            this.grnUnavailable = false;
            this.grnPendingRoomsUpdate = null;
            this.grnPendingTotals = null;

            const run = (async () => {
                const bookingData = <?= json_encode($bookingData) ?>;
                const response = await fetch('<?= root ?>modules/stays/grnconnect/recheck', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        hotel_id: bookingData.hotel_id,
                        checkin: bookingData.checkin,
                        checkout: bookingData.checkout,
                        nationality: bookingData.nationality,
                        currency: this.baseCurrency,
                        nights: this.nights,
                        rooms_data: bookingData.rooms_data || [],
                        selected_rooms: this.selectedRoomsData
                    })
                });

                const data = await response.json();

                if (!data.success) {
                    const err = new Error(data.message || 'Rate verification failed');
                    err.unavailable = !!(data.unavailable || data.error_code === 'rate_unavailable');
                    throw err;
                }

                const recheckedRooms = Array.isArray(data.data?.rooms) && data.data.rooms.length
                    ? data.data.rooms
                    : (data.data?.option ? [{ index: 0, option: data.data.option, grn_recheck: data.data.grn_recheck, price_changed: data.data.price_changed }] : []);

                if (!recheckedRooms.length) {
                    const err = new Error('Sorry, this room is no longer available. Please select another option.');
                    err.unavailable = true;
                    throw err;
                }

                const grnRecheck = data.data?.grn_recheck || null;
                if (!grnRecheck || !String(grnRecheck.search_id || '').trim()
                    || !String(grnRecheck.rate_key || '').trim()
                    || !String(grnRecheck.group_code || '').trim()) {
                    throw new Error('Rate verification did not return a valid GRN recheck result.');
                }

                return { ok: true, data: data.data, recheckedRooms };
            })();

            window.__grnRecheckPromises[hash] = run;

            try {
                const result = await run;
                this.applyGrnRecheckResult(result.data, result.recheckedRooms);
                return this.hasValidGrnRecheck();
            } catch (err) {
                console.error('GRN recheck failed:', err);
                const msg = err.message || 'Unable to verify rate with supplier.';
                this.grnRecheckError = msg;
                this.grnRecheckDone = false;
                this.grnRecheck = null;
                this.grnRechecks = [];
                if (err.unavailable || /no longer available|not available|unavailable/i.test(msg)) {
                    this.grnUnavailable = true;
                    this.returnToGrnRoomSelection(msg);
                }
                return false;
            } finally {
                this.grnRecheckLoading = false;
                this.loading = false;
                if (window.__grnRecheckPromises && window.__grnRecheckPromises[hash] === run) {
                    delete window.__grnRecheckPromises[hash];
                }
            }
        },

        applyGrnRecheckResult(data, recheckedRooms) {
            this.grnRecheck = data.grn_recheck || null;
            this.grnRechecks = data.grn_rechecks || (this.grnRecheck ? [this.grnRecheck] : []);
            this.grnPriceChanged = !!data.price_changed;
            this.grnPreviousPrice = parseFloat(data.previous_price ?? data.price_comparison?.previous_price ?? 0) || 0;
            this.grnUpdatedPrice = parseFloat(data.updated_price ?? data.price_comparison?.updated_price ?? 0) || 0;
            this.grnPriceCurrency = data.price_currency || data.price_comparison?.currency || this.baseCurrency || this.currency;
            this.grnPriceChangeMessage = data.price_comparison?.message
                || (this.grnPriceChanged ? 'The price has changed. Please review the updated price before continuing.' : '');
            this.grnRecheckError = '';
            this.grnUnavailable = false;
            // Only mark done when identifiers required for booking are present.
            this.grnRecheckDone = !!(
                this.grnRecheck
                && String(this.grnRecheck.search_id || '').trim()
                && String(this.grnRecheck.rate_key || '').trim()
                && String(this.grnRecheck.group_code || '').trim()
            );

            const previousTotal = parseFloat(this.totalAmountBase || 0);
            if (!this.grnPreviousPrice && previousTotal > 0) {
                this.grnPreviousPrice = previousTotal;
            }

            const byToken = {};
            (recheckedRooms || []).forEach(item => {
                const token = item.option?.rate_key || item.rate_key || '';
                if (token) byToken[token] = item;
            });

            const updatedRooms = this.selectedRoomsData.map((room, index) => {
                const roomIndex = Number(room.room_index || (index + 1));
                let verified = recheckedRooms.find(item => Number(item.room_index) === roomIndex)
                    || recheckedRooms.find(item => Number(item.index) === index);

                if (!verified && room.option?.rate_key && byToken[room.option.rate_key]) {
                    verified = byToken[room.option.rate_key];
                }
                if (!verified && room.primary === false && room.covers_from) {
                    const primary = this.selectedRoomsData.find(r =>
                        Number(r.room_index) === Number(room.covers_from) && r.primary !== false
                    );
                    if (primary?.option?.rate_key && byToken[primary.option.rate_key]) {
                        verified = byToken[primary.option.rate_key];
                    }
                }

                if (!verified?.option) return room;
                const mergedOption = this.syncGrnOptionUnitPrices({
                    ...room.option,
                    ...verified.option,
                });
                return {
                    ...room,
                    option: mergedOption,
                    selected_rate: { ...(room.selected_rate || room.option || {}), ...mergedOption },
                };
            });

            const seenPrices = new Set();
            const verifiedTotal = updatedRooms.reduce((sum, room) => {
                if (room.primary === false) return sum;
                const token = room.option?.rate_key || room.option?.option_id || '';
                if (token && seenPrices.has(token)) return sum;
                if (token) seenPrices.add(token);
                return sum + (parseFloat(room.option?.total_price || 0) || 0);
            }, 0);

            const pendingTotals = verifiedTotal > 0 ? {
                totalAmountBase: verifiedTotal,
                totalAmount: verifiedTotal,
                totalAmountDisplay: verifiedTotal * this.conversionRate,
            } : null;
            if (pendingTotals && this.hasTax) {
                const taxCalc = this.taxAmount / Math.max(previousTotal, 1);
                pendingTotals.taxAmount = verifiedTotal * taxCalc;
                pendingTotals.taxAmountDisplay = pendingTotals.taxAmount * this.conversionRate;
                pendingTotals.totalWithTax = verifiedTotal + pendingTotals.taxAmount;
                pendingTotals.totalWithTaxDisplay = pendingTotals.totalWithTax * this.conversionRate;
            }

            if (this.grnPriceChanged) {
                this.grnPendingRoomsUpdate = updatedRooms;
                this.grnPendingTotals = pendingTotals;
                if (this.grnRecheck && typeof this.grnRecheck === 'object') {
                    this.grnRecheck = {
                        ...this.grnRecheck,
                        price_changed: true,
                        price_accepted: false,
                        previous_price: this.grnPreviousPrice,
                        updated_price: this.grnUpdatedPrice,
                        currency: this.grnPriceCurrency,
                    };
                }
            } else {
                this.selectedRoomsData = this.applyGrnVerifiedRoomPrices(updatedRooms);
                if (pendingTotals) {
                    this.totalAmountBase = pendingTotals.totalAmountBase;
                    this.totalAmount = pendingTotals.totalAmount;
                    this.totalAmountDisplay = pendingTotals.totalAmountDisplay;
                    if (typeof pendingTotals.taxAmount !== 'undefined') {
                        this.taxAmount = pendingTotals.taxAmount;
                        this.taxAmountDisplay = pendingTotals.taxAmountDisplay;
                        this.totalWithTax = pendingTotals.totalWithTax;
                        this.totalWithTaxDisplay = pendingTotals.totalWithTaxDisplay;
                    }
                }
                this.grnPriceAccepted = true;
                if (this.grnRecheck && typeof this.grnRecheck === 'object') {
                    this.grnRecheck = {
                        ...this.grnRecheck,
                        price_changed: false,
                        price_accepted: true,
                        previous_price: this.grnPreviousPrice,
                        updated_price: this.grnUpdatedPrice || previousTotal,
                        accepted_net_price: this.grnRecheck.accepted_net_price
                            || this.grnUpdatedPrice
                            || previousTotal,
                        currency: this.grnPriceCurrency,
                    };
                }
            }
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
            if (tag === 'non_refundable') return '<?= defined("T::non_refundable") ? T::non_refundable : "Non-refundable" ?>';
            if (tag === 'refundable') return '<?= defined("T::fully_refundable") ? T::fully_refundable : (defined("T::refundable") ? T::refundable : "Fully refundable") ?>';
            return '<?= defined("T::non_refundable") ? T::non_refundable : "Non-refundable" ?>';
        },

        getRefundabilityClass(option = {}) {
            const tag = option.refundability_tag
                || (option.non_cancellable ? 'non_cancellable'
                    : (option.non_refundable ? 'non_refundable'
                        : (option.partially_refundable ? 'partially_refundable'
                            : (option.refundable ? 'refundable' : 'non_refundable'))));
            if (tag === 'refundable') return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300';
            if (tag === 'partially_refundable') return 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300';
            if (tag === 'non_cancellable') return 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-300';
            return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300';
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
            const tag = option.refundability_tag
                || (option.cancellation_details && option.cancellation_details.refundability_tag)
                || (option.partially_refundable ? 'partially_refundable' : '');
            return !!(cd.under_cancellation || tag === 'partially_refundable');
        },

        isRefundableStatus(option = {}) {
            if (this.isPartiallyRefundableStatus(option)) return false;
            const cd = option.cancellation_details || {};
            const tag = option.refundability_tag
                || (option.cancellation_details && option.cancellation_details.refundability_tag)
                || (option.refundable ? 'refundable' : '');
            return tag === 'refundable'
                || !!(cd.free_cancellation)
                || !!(cd.cancel_by_date || cd.cancel_by_date_display);
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
