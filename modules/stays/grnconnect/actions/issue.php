<?php
/**
 * GRNCONNECT BOOKING ISSUE HANDLER
 * =====================================
 * Completes a hotel reservation via the GRNConnect API.
 *
 * Flow:
 *  1. Load booking + selected rate token (packed in rooms.php)
 *  2. Bundled refetch for fresh search_id / rate_key
 *  3. Mandatory recheck (guaranteed details before book)
 *  4. POST the booking with holder + booking_items (paxes)
 *  5. Persist booking_reference (PNR) + status
 */

if (!function_exists('grnconnect_issue_booking_logic')) {
    function grnconnect_issue_booking_logic($db, $invoiceId)
    {
        require_once __DIR__ . '/../api.php';
        require_once __DIR__ . '/../selection.php';
        require_once __DIR__ . '/../validation.php';

        $returnConfirmed = function ($reference, $bookingId, $booking) use ($invoiceId) {
            return [
                'status'            => true,
                'success'           => true,
                'booking_status'    => 'confirmed',
                'Prn'               => $reference,
                'booking_reference' => $reference,
                'reference'         => $reference,
                'message'           => 'Booking completed successfully',
                'invoice_id'        => $invoiceId,
                'response_error'    => '',
                'data'              => [
                    'booking_id'        => $booking['id'],
                    'invoice_id'        => $invoiceId,
                    'pnr'               => $reference,
                    'grn_booking_id'    => $bookingId,
                    'status'            => 'confirmed',
                ],
            ];
        };

        try {
            if (!$db) throw new Exception('Database connection not available');
            if (empty($invoiceId)) throw new Exception('Missing invoice_id parameter');

            $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module' => 'grnconnect']);
            if (!$booking) throw new Exception('Booking not found');

            // Already confirmed?
            if (($booking['booking_status'] ?? '') === 'confirmed' && !empty($booking['pnr'])) {
                $bd = json_decode($booking['booking_data'], true) ?: [];
                return $returnConfirmed($booking['pnr'], $bd['grn_booking_id'] ?? null, $booking);
            }

            $bookingData = json_decode($booking['booking_data'], true);
            if (!is_array($bookingData)) throw new Exception('Invalid booking data');

            $module = grnconnect_module($db);
            if (!$module) throw new Exception('GRNConnect module not found');
            $client = GRNConnectClient::fromModule($module);
            if (!$client->hasCredentials()) throw new Exception('GRNConnect API credentials not configured');

            // ---- Collect selected rate tokens -----------------------------
            $selectedRooms = $bookingData['selected_rooms'] ?? [];
            $tokens = [];
            foreach ($selectedRooms as $room) {
                $rk = $room['option']['rate_key'] ?? ($room['rate_key'] ?? '');
                $unpacked = grnconnect_unpack_rate($rk);
                if ($unpacked) {
                    $unpacked['quantity'] = max(1, (int)($room['quantity'] ?? 1));
                    $unpacked['_room_name'] = (string)($room['room_type'] ?? ($unpacked['description'] ?? ''));
                    $tokens[] = $unpacked;
                }
            }
            if (empty($tokens)) {
                throw new Exception('No GRNConnect rate found on this booking. Please search and select the room again.');
            }

            $first     = $tokens[0];
            $hotelCode = $first['hotel_code'];
            $cityCode  = $first['city_code'];
            $checkin   = $first['checkin'] ?: grnconnect_to_iso_date($bookingData['checkin'] ?? '');
            $checkout  = $first['checkout'] ?: grnconnect_to_iso_date($bookingData['checkout'] ?? '');
            $nationality = strtoupper(substr(trim($bookingData['nationality'] ?? 'US'), 0, 2));
            $currency  = $bookingData['currency'] ?? ($module['currency'] ?? 'USD');

            // ---- Re-run availability for a fresh search_id ----------------
            $roomsData = $bookingData['rooms_data'] ?? [];
            $occupancy = [];
            foreach ($roomsData as $r) {
                $occupancy[] = [
                    'adults'     => (int)($r['adults'] ?? 2),
                    'children'   => (int)($r['children'] ?? 0),
                    'child_ages' => $r['childAges'] ?? $r['child_ages'] ?? [],
                ];
            }
            $grnRooms = grnconnect_rooms_from_input(!empty($occupancy) ? $occupancy : [['adults' => 2]]);

            // Revalidate the persisted selection at issue time. One rate_key is
            // one atomic package and its declared room allocation must exactly
            // cover the requested rooms.
            $selectionValidation = grnconnect_validate_package_selection($grnRooms, $selectedRooms);
            if (empty($selectionValidation['ok'])) {
                throw new Exception(
                    $selectionValidation['message'] ?? 'Invalid GRN room package selection. Please search again.'
                );
            }

            $uniqueRateKeys = [];
            foreach ($tokens as $t) {
                $uniqueRateKeys[(string)($t['rate_key'] ?? '')] = true;
            }
            $isMultiRate = count($uniqueRateKeys) > 1;

            $perRoomPaxes = grnconnect_build_paxes($bookingData, json_decode($booking['travellers'], true) ?: []);
            $bookingItems = [];
            $searchId = '';
            $groupCode = '';
            $cityCodeFinal = $cityCode;
            $recheckMeta = [];

            if (!$isMultiRate) {
                // ---- Bundled / single rate_key flow ----
                $token = $tokens[0];
                $checkoutRecheck = is_array($bookingData['grn_recheck'] ?? null) ? $bookingData['grn_recheck'] : [];
                if (!empty($checkoutRecheck['search_id'])) {
                    $token['search_id']  = $checkoutRecheck['search_id'];
                    $token['group_code'] = $checkoutRecheck['group_code'] ?? ($token['group_code'] ?? '');
                    $token['rate_key']   = $checkoutRecheck['rate_key'] ?? ($token['rate_key'] ?? '');
                }

                try {
                    if (function_exists('grnconnect_issue_recheck_bundled_rate')) {
                        $rechecked = grnconnect_issue_recheck_bundled_rate(
                            $client,
                            $token,
                            $checkoutRecheck,
                            $grnRooms,
                            $nationality,
                            $currency
                        );
                    } else {
                        $rechecked = grnconnect_mandatory_recheck(
                            $client,
                            (string)($token['search_id'] ?? ''),
                            (string)($token['rate_key'] ?? ''),
                            (string)($token['group_code'] ?? '')
                        );
                    }
                    $searchId = $rechecked['search_id'];
                    $cityCodeFinal = (string)($rechecked['city_code'] ?? ($checkoutRecheck['city_code'] ?? $cityCode));
                } catch (\Throwable $e) {
                    throw new Exception(grnconnect_unavailable_message());
                }

                $searchId   = $rechecked['search_id'];
                $groupCode  = $rechecked['group_code'];
                $finalRate  = $rechecked['rate'];
                $rateKey    = $rechecked['rate_key'];
                // Recheck room_code only — never the availability/packed-token room_code.
                $roomCode   = (string)($finalRate['room_code'] ?? '');
                $matchRooms = function_exists('grnconnect_booking_rate_rooms')
                    ? grnconnect_booking_rate_rooms($finalRate)
                    : (is_array($finalRate['rooms'] ?? null) ? array_values($finalRate['rooms']) : []);
                $perRoomRefs = [];
                foreach ($matchRooms as $rateRoom) {
                    $perRoomRefs[] = trim((string)($rateRoom['room_reference'] ?? ''));
                }
                if (!empty($perRoomRefs)) {
                    $token['room_references'] = $perRoomRefs;
                    $token['room_reference']  = $perRoomRefs[0];
                }
                if (empty($matchRooms)) {
                    throw new Exception('GRNConnect recheck returned no rooms for this rate. Please search again and reselect rooms.');
                }

                // One rate_key = exactly the rooms GRN returned for that key.
                $allocNeeded = max(1, count($matchRooms), (int)($finalRate['no_of_rooms'] ?? 0));

                grnconnect_assert_recheck_bookable(
                    $finalRate,
                    is_array($rechecked['raw'] ?? null) ? $rechecked['raw'] : [],
                    $token,
                    $allocNeeded
                );
                $issueNet = function_exists('grnconnect_rate_price_total')
                    ? (float)grnconnect_rate_price_total($finalRate)
                    : (float)($finalRate['price'] ?? 0);
                // Compare against checkout-accepted price if user confirmed a change
                $checkoutMeta = $checkoutRecheck;
                grnconnect_assert_issue_price_accepted($checkoutMeta, $issueNet);

                $itemRooms = grnconnect_build_booking_item_rooms(
                    $matchRooms,
                    $perRoomPaxes,
                    $token,
                    $booking,
                    0,
                    count($matchRooms),
                    $grnRooms
                );

                $item = ['rate_key' => $rateKey, 'rooms' => $itemRooms];
                if ($roomCode !== '') {
                    $item['room_code'] = $roomCode;
                }
                $bookingItems = [$item];

                $recheckMeta = [
                    'search_id'    => $searchId,
                    'group_code'   => $groupCode,
                    'rate_key'     => $rateKey,
                    'room_code'    => $roomCode,
                    'hotel_code'   => $hotelCode,
                    'city_code'    => $cityCodeFinal,
                    'rechecked_at' => date('c'),
                    'bundled'      => true,
                    'no_of_rooms'  => count($matchRooms),
                    'room_references' => $perRoomRefs,
                    'reused_checkout_recheck' => !empty($rechecked['reused_checkout_recheck']),
                    'same_session' => !empty($rechecked['same_session']),
                ];
            } else {
                // ---- Non-bundled / partial: one booking_item per unique rate_key ----
                // Partial: a rate may itself contain multiple rooms (allocation_size > 1).
                $paxIndex = 0;
                $groupCodes = [];
                $seenRawKeys = [];
                $checkoutRechecks = [];
                if (!empty($bookingData['grn_rechecks']) && is_array($bookingData['grn_rechecks'])) {
                    $checkoutRechecks = $bookingData['grn_rechecks'];
                } elseif (!empty($bookingData['grn_recheck']) && is_array($bookingData['grn_recheck'])) {
                    $checkoutRechecks = [$bookingData['grn_recheck']];
                }

                foreach ($tokens as $tokenIndex => $token) {
                    $rawKey = (string)($token['rate_key'] ?? '');
                    if ($rawKey !== '' && isset($seenRawKeys[$rawKey])) {
                        continue;
                    }
                    if ($rawKey !== '') {
                        $seenRawKeys[$rawKey] = true;
                    }

                    $expectedGroup = (string)($token['group_code'] ?? '');
                    $savedRecheck = function_exists('grnconnect_find_checkout_recheck')
                        ? grnconnect_find_checkout_recheck($checkoutRechecks, $rawKey, $expectedGroup)
                        : null;

                    if (is_array($savedRecheck)) {
                        $rechecked = grnconnect_rechecked_from_checkout($savedRecheck);
                        $searchId = $rechecked['search_id'] ?: $searchId;
                        $cityCodeFinal = (string)($savedRecheck['city_code'] ?? $cityCodeFinal);
                    } else {
                        $fresh = grnconnect_resolve_fresh_bundled_rate($client, $token, $grnRooms, $nationality, $currency);
                        $searchId = $fresh['search_id'] ?: $searchId;
                        $cityCodeFinal = (string)($fresh['city_code'] ?? $cityCodeFinal);

                        $rechecked = grnconnect_mandatory_recheck(
                            $client,
                            $searchId,
                            (string)($fresh['rate']['rate_key'] ?? ''),
                            (string)($fresh['rate']['group_code'] ?? ($token['group_code'] ?? ''))
                        );
                    }

                    $searchId  = $rechecked['search_id'] ?: $searchId;
                    $groupCode = $rechecked['group_code'] ?: $groupCode;
                    $gc = trim((string)$rechecked['group_code']);
                    if ($gc !== '') {
                        $groupCodes[$gc] = true;
                    }

                    $finalRate  = $rechecked['rate'];
                    $rateKey    = $rechecked['rate_key'];
                    $roomCode   = (string)($finalRate['room_code'] ?? '');
                    $matchRooms = function_exists('grnconnect_booking_rate_rooms')
                        ? grnconnect_booking_rate_rooms($finalRate)
                        : (is_array($finalRate['rooms'] ?? null) ? array_values($finalRate['rooms']) : []);
                    $roomCount  = max(1, count($matchRooms), (int)($finalRate['no_of_rooms'] ?? ($token['rooms_in_rate'] ?? ($token['no_of_rooms'] ?? 1))));

                    // Protective: availability after issue-time recheck (before Booking API)
                    grnconnect_assert_recheck_bookable(
                        $finalRate,
                        is_array($rechecked['raw'] ?? null) ? $rechecked['raw'] : [],
                        $token,
                        $roomCount
                    );

                    $itemRooms = grnconnect_build_booking_item_rooms(
                        $matchRooms,
                        $perRoomPaxes,
                        $token,
                        $booking,
                        $paxIndex,
                        $roomCount,
                        $grnRooms
                    );
                    $paxIndex += max(1, count($itemRooms));

                    $item = [
                        'rate_key' => $rateKey,
                        'rooms'    => $itemRooms,
                    ];
                    if ($roomCode !== '') {
                        $item['room_code'] = $roomCode;
                    }
                    $bookingItems[] = $item;
                }

                if (count($groupCodes) > 1) {
                    throw new Exception('Selected rooms must belong to the same rate group. Please search again and choose compatible rooms.');
                }
                if ($groupCode === '' && !empty($groupCodes)) {
                    $groupCode = array_key_first($groupCodes);
                }

                $recheckMeta = [
                    'search_id'    => $searchId,
                    'group_code'   => $groupCode,
                    'hotel_code'   => $hotelCode,
                    'city_code'    => $cityCodeFinal,
                    'rechecked_at' => date('c'),
                    'bundled'      => false,
                    'partial'      => count($bookingItems) > 1 && count($seenRawKeys) < count($grnRooms),
                    'items_count'  => count($bookingItems),
                ];
            }

            // Keep checkout acceptance fields when refreshing issue-time recheck meta
            $priorCheckoutRecheck = is_array($bookingData['grn_recheck'] ?? null) ? $bookingData['grn_recheck'] : [];
            if (!empty($priorCheckoutRecheck['price_accepted'])) {
                $recheckMeta['price_accepted'] = true;
                $recheckMeta['accepted_net_price'] = $priorCheckoutRecheck['accepted_net_price']
                    ?? $priorCheckoutRecheck['updated_price']
                    ?? null;
                $recheckMeta['previous_price'] = $priorCheckoutRecheck['previous_price'] ?? null;
                $recheckMeta['updated_price'] = $priorCheckoutRecheck['updated_price'] ?? null;
                $recheckMeta['currency'] = $priorCheckoutRecheck['currency'] ?? ($recheckMeta['currency'] ?? $currency);
            }
            $bookingData['grn_recheck'] = $recheckMeta;
            $cityCode = $cityCodeFinal;

            // ---- Holder (lead guest) --------------------------------------
            $travellers   = json_decode($booking['travellers'], true) ?: [];
            $primaryGuest = $travellers['primary_guest'] ?? [];
            $holder = [
                'title'              => grnconnect_pax_title($primaryGuest['title'] ?? 'Mr'),
                'name'               => grnconnect_sanitize_pax_name(
                    $primaryGuest['first_name'] ?? $booking['first_name'] ?? 'Guest',
                    'Guest'
                ),
                'surname'            => grnconnect_sanitize_pax_name(
                    $primaryGuest['last_name'] ?? $booking['last_name'] ?? 'Traveler',
                    'Traveler'
                ),
                'email'              => $primaryGuest['email'] ?? $booking['email'] ?? '',
                'phone_number'       => preg_replace('/\D+/', '', (string)($primaryGuest['phone'] ?? $booking['phone'] ?? '')),
                'client_nationality' => $nationality,
            ];

            // ---- Booking payload ------------------------------------------
            $payload = [
                'search_id'        => $searchId,
                'hotel_code'       => $hotelCode,
                'city_code'        => $cityCode,
                'group_code'       => $groupCode,
                'booking_name'     => $invoiceId,
                'agent_reference'  => $invoiceId,
                'checkin'          => $checkin,
                'checkout'         => $checkout,
                'payment_type'     => 'AT_WEB',
                'purpose_of_travel'=> 1,
                'holder'           => $holder,
                'booking_items'    => $bookingItems,
                'booking_comments' => $booking['special_requests'] ?? '',
            ];

            foreach ($bookingItems as $bi) {
                $itemRooms = is_array($bi['rooms'] ?? null) ? $bi['rooms'] : [];
                $hasRef = false;
                $missingRef = false;
                foreach ($itemRooms as $roomEl) {
                    if (trim((string)($roomEl['room_reference'] ?? '')) !== '') {
                        $hasRef = true;
                    } else {
                        $missingRef = true;
                    }
                }
                if ($hasRef && $missingRef) {
                    throw new Exception('GRNConnect booking failed: room_references are incomplete for this rate. Please search again and reselect rooms.');
                }
            }

            $db->update('bookings', [
                'booking_status' => 'processing',
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);

            $res = $client->book($payload);

            if (!$res['success']) {
                $db->update('bookings', [
                    'booking_status' => 'failed',
                    'error_response' => json_encode($res['data'] ?: ['error' => $res['error']]),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ], ['id' => $booking['id']]);
                throw new Exception('GRNConnect booking failed: ' . ($res['error'] ?: 'unknown error'));
            }

            $data = $res['data'];
            // Booking payload may be at top level or under "booking"/"bookings"[0].
            $node = $data['booking'] ?? ($data['bookings'][0] ?? $data);
            $reference     = $node['booking_reference'] ?? $data['booking_reference'] ?? '';
            $grnBookingId  = $node['booking_id'] ?? $data['booking_id'] ?? '';
            $bookingStatus = strtolower($node['booking_status'] ?? $data['booking_status'] ?? '');

            if ($reference === '') {
                $db->update('bookings', [
                    'booking_status' => 'processing',
                    'error_response' => json_encode($data),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ], ['id' => $booking['id']]);
                throw new Exception('GRNConnect booking response missing booking_reference. Check with GRN Ops before marking failed.');
            }

            if (in_array($bookingStatus, ['pending', 'processing', ''], true)) {
                for ($attempt = 0; $attempt < 12; $attempt++) {
                    sleep(5);
                    $fetch = $client->getBooking($reference, 'GRN');
                    if (!$fetch['success']) {
                        $agentFetch = $client->getBookingByAgentRef($invoiceId);
                        if ($agentFetch['success']) {
                            $fetch = $agentFetch;
                        }
                    }
                    if (!$fetch['success']) continue;

                    $fetched = $fetch['data']['booking'] ?? ($fetch['data']['bookings'][0] ?? $fetch['data']);
                    if (!is_array($fetched)) continue;

                    $liveStatus = strtolower($fetched['booking_status'] ?? '');
                    $liveRef    = $fetched['booking_reference'] ?? $reference;
                    if ($liveRef !== '') $reference = $liveRef;
                    if ($liveStatus === 'confirmed') {
                        $bookingStatus = 'confirmed';
                        $data = $fetch['data'];
                        break;
                    }
                    if (in_array($liveStatus, ['failed', 'rejected', 'cancelled'], true)) {
                        $bookingStatus = $liveStatus;
                        $data = $fetch['data'];
                        break;
                    }
                }
            }

            $bookingData['grn_booking_id']        = $grnBookingId;
            $bookingData['grn_booking_reference'] = $reference;
            $bookingData['grn_search_id']         = $searchId;

            if (in_array($bookingStatus, ['failed', 'rejected'], true)) {
                $db->update('bookings', [
                    'booking_status'   => 'failed',
                    'pnr'              => $reference,
                    'booking_response' => json_encode($data),
                    'booking_data'     => json_encode($bookingData),
                    'updated_at'       => date('Y-m-d H:i:s'),
                ], ['id' => $booking['id']]);
                throw new Exception('GRNConnect booking was rejected by supplier.');
            }

            $finalStatus = ($bookingStatus === 'confirmed') ? 'confirmed' : 'processing';

            $db->update('bookings', [
                'booking_status'   => $finalStatus,
                'pnr'              => $reference,
                'booking_response' => json_encode($data),
                'booking_data'     => json_encode($bookingData),
                'updated_at'       => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);

            if ($finalStatus !== 'confirmed') {
                return [
                    'status'            => true,
                    'success'           => true,
                    'booking_status'    => 'processing',
                    'Prn'               => $reference,
                    'booking_reference' => $reference,
                    'reference'         => $reference,
                    'message'           => 'Booking submitted and pending supplier confirmation. Reference saved.',
                    'invoice_id'        => $invoiceId,
                    'response_error'    => '',
                    'data'              => [
                        'booking_id'        => $booking['id'],
                        'invoice_id'        => $invoiceId,
                        'pnr'               => $reference,
                        'grn_booking_id'    => $grnBookingId,
                        'status'            => 'processing',
                    ],
                ];
            }

            return $returnConfirmed($reference, $grnBookingId, $booking);

        } catch (Exception $e) {
            if (isset($booking['id'])) {
                $db->update('bookings', [
                    'error_response' => json_encode([
                        'error'     => 'exception',
                        'message'   => $e->getMessage(),
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $booking['id']]);
            }
            error_log('GRNConnect Booking Error: ' . $e->getMessage() . ' (Invoice: ' . ($invoiceId ?? 'N/A') . ')');
            return [
                'status'            => false,
                'success'           => false,
                'booking_status'    => 'failed',
                'error'             => 'booking_error',
                'Prn'               => '',
                'booking_reference' => '',
                'reference'         => '',
                'message'           => $e->getMessage(),
                'response_error'    => $e->getMessage(),
            ];
        }
    }
}

// ----------------------------------------------------------------------------
// HELPERS
// ----------------------------------------------------------------------------

if (!function_exists('grnconnect_match_rate')) {
    /**
     * Find a fresh rate matching a stored token.
     *
     * New tokens carry the ordered signature of every rooms[] component. That
     * signature is authoritative because group_code and even room_code may be
     * shared by distinct King/Twin or mixed-room packages.
     */
    function grnconnect_match_rate($rates, $token, $preferredGroup = '')
    {
        $wantRateKey  = (string)($token['rate_key'] ?? '');
        $wantRoomCode = $token['room_code'] ?? '';
        $wantRoomType = $token['room_type'] ?? '';
        $wantMeal     = $token['meal'] ?? '';
        $wantNet      = (float)($token['net_price'] ?? 0);
        $wantSignature = (string)($token['room_signature'] ?? '');

        // Existing search session: exact rate_key is always the same package.
        foreach ($rates as $r) {
            if ($wantRateKey !== '' && (string)($r['rate_key'] ?? '') === $wantRateKey) {
                return $r;
            }
        }

        // Fresh search session: match the complete ordered room composition,
        // meal and group, then choose the closest equivalent price.
        if ($wantSignature !== '' && function_exists('grnconnect_rate_room_signature')) {
            $best = null;
            $bestDiff = PHP_FLOAT_MAX;
            foreach ($rates as $r) {
                if (!is_array($r)) continue;
                if ($preferredGroup !== '' && (string)($r['group_code'] ?? '') !== $preferredGroup) continue;
                if (grnconnect_rate_room_signature($r) !== $wantSignature) continue;
                if ($wantMeal !== '' && strcasecmp(grnconnect_meal_plan($r), $wantMeal) !== 0) continue;
                $price = function_exists('grnconnect_rate_price_total')
                    ? grnconnect_rate_price_total($r)
                    : (float)($r['price'] ?? 0);
                $diff = abs($price - $wantNet);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = $r;
                }
            }
            // Never silently substitute a different package composition.
            return $best;
        }

        // Legacy tokens without a component signature.
        foreach ($rates as $r) {
            if ($wantRoomCode !== '' && ($r['room_code'] ?? '') === $wantRoomCode) {
                if ($preferredGroup === '' || ($r['group_code'] ?? '') === $preferredGroup) {
                    return $r;
                }
            }
        }

        // Same room_type, closest price (respect preferred group when set).
        $best = null; $bestDiff = PHP_FLOAT_MAX;
        foreach ($rates as $r) {
            if ($preferredGroup !== '' && ($r['group_code'] ?? '') !== $preferredGroup) continue;
            $roomType = $r['rooms'][0]['room_type'] ?? '';
            if ($wantRoomType !== '' && strcasecmp($roomType, $wantRoomType) !== 0) continue;
            $price = (float)($r['price'] ?? 0);
            $diff  = abs($price - $wantNet);
            if ($diff < $bestDiff) { $bestDiff = $diff; $best = $r; }
        }
        if ($best) return $best;

        // Fallback: cheapest rate (respect preferred group when set).
        foreach ($rates as $r) {
            if ($preferredGroup !== '' && ($r['group_code'] ?? '') !== $preferredGroup) continue;
            $price = (float)($r['price'] ?? 0);
            if ($price > 0 && $price < $bestDiff) { $bestDiff = $price; $best = $r; }
        }
        return $best ?: ($rates[0] ?? null);
    }
}

if (!function_exists('grnconnect_pax_title')) {
    /**
     * Normalize a title to a GRNConnect-accepted value.
     * GRNConnect v3 ONLY accepts: "Mr.", "Ms.", "Mrs.", "Mstr." (note the dot).
     */
    function grnconnect_pax_title($title)
    {
        $key = strtolower(rtrim(trim((string)$title), '.'));
        $map = [
            'mr'     => 'Mr.',
            'mrs'    => 'Mrs.',
            'ms'     => 'Ms.',
            'miss'   => 'Ms.',
            'mstr'   => 'Mstr.',
            'master' => 'Mstr.',
            'mr.'    => 'Mr.',
            'mrs.'   => 'Mrs.',
            'ms.'    => 'Ms.',
        ];
        return $map[$key] ?? 'Mr.';
    }
}

if (!function_exists('grnconnect_default_pax')) {
    function grnconnect_default_pax($booking)
    {
        return [[
            'title'   => 'Mr.',
            'name'    => $booking['first_name'] ?? 'Guest',
            'surname' => $booking['last_name'] ?? 'Traveler',
            'type'    => 'AD',
        ]];
    }
}

if (!function_exists('grnconnect_pax_counts')) {
    function grnconnect_pax_counts(array $paxes)
    {
        $adults = 0;
        $children = 0;
        foreach ($paxes as $pax) {
            if (($pax['type'] ?? '') === 'CH') {
                $children++;
            } else {
                $adults++;
            }
        }
        return [$adults, $children];
    }
}

if (!function_exists('grnconnect_sanitize_pax_name')) {
    /** GRN pax name/surname: letters only, no digits or special characters. */
    function grnconnect_sanitize_pax_name($value, $fallback = 'Guest')
    {
        $name = preg_replace('/[^A-Za-z]/', '', (string)$value);
        if ($name === '') {
            $name = preg_replace('/[^A-Za-z]/', '', (string)$fallback) ?: 'Guest';
        }
        return $name;
    }
}

if (!function_exists('grnconnect_alpha_suffix')) {
    /** 1 => A, 2 => B, … 26 => Z, 27 => AA (letters only — GRN rejects digits in names). */
    function grnconnect_alpha_suffix($n)
    {
        $n = max(1, (int)$n);
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }
}

if (!function_exists('grnconnect_unique_pax_name')) {
    /** GRN requires guest names to be unique and contain letters only (no digits). */
    function grnconnect_unique_pax_name($base, $suffix, array &$usedNames)
    {
        $base = grnconnect_sanitize_pax_name($base, 'Guest');
        $name = $base;
        $i = 0;
        while (isset($usedNames[strtolower($name)])) {
            $i++;
            $name = $base . grnconnect_alpha_suffix($i);
        }
        $usedNames[strtolower($name)] = true;
        return $name;
    }
}

if (!function_exists('grnconnect_search_room_ages')) {
    function grnconnect_search_room_ages(array $searchRoom)
    {
        $ages = $searchRoom['children_ages'] ?? [];
        if (!is_array($ages)) {
            $ages = [];
        }
        if (function_exists('grnconnect_normalize_child_ages')) {
            return grnconnect_normalize_child_ages($ages, count($ages));
        }
        $out = [];
        foreach ($ages as $a) {
            $a = (int)$a;
            $out[] = $a >= 1 ? min(17, $a) : 1;
        }
        return $out;
    }
}

if (!function_exists('grnconnect_align_paxes_to_rate_room')) {
    /**
     * Force pax AD/CH counts + child ages to match GRN search/rate occupancy.
     * Infants are age 1 (GRN children_ages range is 1–17).
     */
    function grnconnect_align_paxes_to_rate_room(array $paxes, array $rateRoom, array $booking, array $searchRoom = [])
    {
        $needAdults = (int)($rateRoom['no_of_adults'] ?? 0);
        $needChildren = (int)($rateRoom['no_of_children'] ?? 0);

        // Fall back to search occupancy when rate room omits priced counts.
        if ($needAdults < 1 && $needChildren < 1 && !empty($searchRoom)) {
            $needAdults = max(1, (int)($searchRoom['adults'] ?? 1));
            $searchAges = grnconnect_search_room_ages($searchRoom);
            $needChildren = count($searchAges);
        }
        if ($needAdults < 1 && $needChildren < 1) {
            return !empty($paxes) ? $paxes : grnconnect_default_pax($booking);
        }
        $needAdults = max(1, $needAdults);

        $adults = [];
        $children = [];
        foreach ($paxes as $pax) {
            if (($pax['type'] ?? '') === 'CH') {
                $children[] = $pax;
            } else {
                $adults[] = $pax;
            }
        }

        $fallbackSurname = grnconnect_sanitize_pax_name($booking['last_name'] ?? 'Traveler', 'Traveler');
        while (count($adults) < $needAdults) {
            $adults[] = [
                'title'   => 'Mr.',
                'name'    => 'Guest',
                'surname' => $fallbackSurname,
                'type'    => 'AD',
            ];
        }
        if (count($adults) > $needAdults) {
            $adults = array_slice($adults, 0, $needAdults);
        }

        while (count($children) < $needChildren) {
            $children[] = [
                'title'   => 'Mstr.',
                'name'    => 'Child',
                'surname' => $fallbackSurname,
                'type'    => 'CH',
                'age'     => 1,
            ];
        }
        if (count($children) > $needChildren) {
            $children = array_slice($children, 0, $needChildren);
        }

        // Ages must match the search request. Prefer search room, then rate room.
        $ages = grnconnect_search_room_ages($searchRoom);
        if (count($ages) < $needChildren) {
            $rateAges = $rateRoom['children_ages'] ?? [];
            if (!is_array($rateAges)) {
                $rateAges = [];
            }
            if (function_exists('grnconnect_normalize_child_ages')) {
                $rateAges = grnconnect_normalize_child_ages($rateAges, $needChildren);
            }
            foreach ($rateAges as $i => $age) {
                if (!isset($ages[$i])) {
                    $ages[$i] = $age;
                }
            }
        }
        if (function_exists('grnconnect_normalize_child_ages')) {
            $ages = grnconnect_normalize_child_ages($ages, $needChildren);
        }

        foreach ($children as $i => &$child) {
            $age = isset($ages[$i]) ? (int)$ages[$i] : (int)($child['age'] ?? 1);
            $child['age'] = $age >= 1 ? min(17, $age) : 1;
            $child['type'] = 'CH';
        }
        unset($child);

        return array_merge($adults, $children);
    }
}

if (!function_exists('grnconnect_build_booking_item_rooms')) {
    /**
     * Map guest paxes onto GRN rate rooms by occupancy.
     * Each booking room takes room_reference from that same rooms[] entry
     * (search if bookable, refetch/recheck if that rate type). Never copy
     * another room's reference.
     */
    function grnconnect_build_booking_item_rooms(
        array $matchRooms,
        array $perRoomPaxes,
        array $token,
        array $booking,
        $paxOffset = 0,
        $roomCount = null,
        array $searchRooms = []
    ) {
        $matchRooms = array_values(array_filter($matchRooms, 'is_array'));
        $searchRooms = array_values($searchRooms);

        if ($roomCount === null) {
            $roomCount = max(1, count($matchRooms));
        }
        $roomCount = max(1, (int)$roomCount);

        if (count($matchRooms) < $roomCount) {
            throw new Exception(
                'GRN rate returned ' . count($matchRooms) . ' room(s) but booking needs '
                . $roomCount . '. Please search again and reselect rooms.'
            );
        }
        $matchRooms = array_slice($matchRooms, 0, $roomCount);

        $candidates = array_values(array_slice($perRoomPaxes, (int)$paxOffset, $roomCount));
        while (count($candidates) < $roomCount) {
            $candidates[] = grnconnect_default_pax($booking);
        }

        // Pair each rate room with a search room by occupancy (stable order).
        $searchUsed = array_fill(0, count($searchRooms), false);
        $candUsed = array_fill(0, count($candidates), false);
        $usedNames = [];
        $itemRooms = [];

        foreach ($matchRooms as $q => $rateRoom) {
            $needAdults = (int)($rateRoom['no_of_adults'] ?? 0);
            $needChildren = (int)($rateRoom['no_of_children'] ?? 0);

            $searchIdx = null;
            foreach ($searchRooms as $si => $sr) {
                if (!empty($searchUsed[$si])) {
                    continue;
                }
                $sAdults = max(1, (int)($sr['adults'] ?? 2));
                $sChildren = count(grnconnect_search_room_ages($sr));
                if ($needAdults > 0 || $needChildren > 0) {
                    if ($sAdults === $needAdults && $sChildren === $needChildren) {
                        $searchIdx = $si;
                        break;
                    }
                }
            }
            if ($searchIdx === null) {
                foreach ($searchRooms as $si => $sr) {
                    if (empty($searchUsed[$si])) {
                        $searchIdx = $si;
                        break;
                    }
                }
            }
            if ($searchIdx === null && isset($searchRooms[$q])) {
                $searchIdx = $q;
            }
            if ($searchIdx !== null) {
                $searchUsed[$searchIdx] = true;
            }
            $searchRoom = ($searchIdx !== null) ? ($searchRooms[$searchIdx] ?? []) : ($searchRooms[$q] ?? []);

            $picked = null;
            $targetAdults = $needAdults > 0 ? $needAdults : max(1, (int)($searchRoom['adults'] ?? 2));
            $targetChildren = $needChildren > 0 ? $needChildren : count(grnconnect_search_room_ages($searchRoom));
            foreach ($candidates as $i => $paxes) {
                if (!empty($candUsed[$i])) {
                    continue;
                }
                [$haveAdults, $haveChildren] = grnconnect_pax_counts($paxes);
                if ($haveAdults === $targetAdults && $haveChildren === $targetChildren) {
                    $picked = $i;
                    break;
                }
            }
            if ($picked === null) {
                foreach ($candidates as $i => $paxes) {
                    if (empty($candUsed[$i])) {
                        $picked = $i;
                        break;
                    }
                }
            }
            if ($picked === null) {
                $picked = 0;
            }
            $candUsed[$picked] = true;

            $paxes = grnconnect_align_paxes_to_rate_room(
                $candidates[$picked] ?? grnconnect_default_pax($booking),
                $rateRoom,
                $booking,
                is_array($searchRoom) ? $searchRoom : []
            );

            // Enforce unique guest names (GRN booking rule).
            foreach ($paxes as $pi => &$pax) {
                $base = ($pax['type'] ?? '') === 'CH' ? 'Child' : 'Guest';
                if (!empty($pax['name']) && !preg_match('/^Guest\d*$/i', $pax['name']) && !preg_match('/^Child\d*$/i', $pax['name'])) {
                    $base = $pax['name'];
                }
                $pax['name'] = grnconnect_unique_pax_name($base, ($q + 1) * 10 + $pi + 1, $usedNames);
                $pax['surname'] = grnconnect_sanitize_pax_name($pax['surname'] ?? 'Traveler', 'Traveler');
            }
            unset($pax);

            $roomEl = ['paxes' => $paxes];

            // Per-room value from this rate's rooms[] (search / refetch / recheck).
            $ref = trim((string)($rateRoom['room_reference'] ?? ''));
            if ($ref !== '') {
                $roomEl['room_reference'] = $ref;
            }

            $itemRooms[] = $roomEl;
        }

        return $itemRooms;
    }
}

if (!function_exists('grnconnect_build_paxes')) {
    /**
     * Build a list of pax arrays, one entry per physical room, from the booking
     * occupancy (rooms_data) and traveller details. The lead guest is placed as
     * the first adult of the first room (required by GRNConnect from Feb 2026).
     */
    function grnconnect_build_paxes($bookingData, $travellers)
    {
        $roomsData    = $bookingData['rooms_data'] ?? [];
        $primaryGuest = $travellers['primary_guest'] ?? [];
        $travelers    = $travellers['travelers'] ?? [];

        if (empty($roomsData)) {
            $roomsData = [['adults' => (int)($bookingData['adults'] ?? 2), 'children' => (int)($bookingData['children'] ?? 0), 'childAges' => []]];
        }

        $usedNames = [];
        $perRoom = [];
        foreach ($roomsData as $idx => $room) {
            $adults = max(1, (int)($room['adults'] ?? 2));
            $rawAges = $room['childAges'] ?? $room['child_ages'] ?? $room['children_ages'] ?? [];
            if (!is_array($rawAges)) {
                $rawAges = [];
            }
            $children = (int)($room['children'] ?? 0);
            if ($children < 1) {
                $children = count($rawAges);
            }
            if (function_exists('grnconnect_normalize_child_ages')) {
                $ages = grnconnect_normalize_child_ages($rawAges, $children);
            } else {
                $ages = [];
                foreach (array_slice($rawAges, 0, $children) as $a) {
                    $a = (int)$a;
                    $ages[] = $a >= 1 ? min(17, $a) : 1;
                }
                while (count($ages) < $children) {
                    $ages[] = 1;
                }
            }
            $children = count($ages);
            $roomTravelers = $travelers['room_' . $idx] ?? [];
            $paxes = [];
            $surnameFallback = grnconnect_sanitize_pax_name($primaryGuest['last_name'] ?? 'Traveler', 'Traveler');

            for ($a = 0; $a < $adults; $a++) {
                $t = $roomTravelers['adult_' . $a] ?? [];
                $fname = trim($t['first_name'] ?? '');
                $lname = trim($t['last_name'] ?? '');
                $title = grnconnect_pax_title($t['title'] ?? 'Mr');
                if ($idx === 0 && $a === 0) {
                    $fname = $fname ?: ($primaryGuest['first_name'] ?? 'Guest');
                    $lname = $lname ?: ($primaryGuest['last_name'] ?? 'Traveler');
                    $title = grnconnect_pax_title($primaryGuest['title'] ?? 'Mr');
                } else {
                    $fname = $fname ?: 'Guest';
                    $lname = $lname ?: $surnameFallback;
                }
                $fname = grnconnect_unique_pax_name($fname, ($idx + 1) * 10 + $a + 1, $usedNames);
                $lname = grnconnect_sanitize_pax_name($lname, $surnameFallback);
                $paxes[] = ['title' => $title, 'name' => $fname, 'surname' => $lname, 'type' => 'AD'];
            }

            for ($c = 0; $c < $children; $c++) {
                $t = $roomTravelers['child_' . $c] ?? [];
                $age = (int)($ages[$c] ?? ($t['age'] ?? 1));
                $fname = trim($t['first_name'] ?? '') ?: 'Child';
                $lname = trim($t['last_name'] ?? '') ?: $surnameFallback;
                $fname = grnconnect_unique_pax_name($fname, ($idx + 1) * 10 + $adults + $c + 1, $usedNames);
                $lname = grnconnect_sanitize_pax_name($lname, $surnameFallback);
                $paxes[] = [
                    'title'   => 'Mstr.',
                    'name'    => $fname,
                    'surname' => $lname,
                    'type'    => 'CH',
                    'age'     => $age >= 1 ? min(17, $age) : 1,
                ];
            }

            if (empty($paxes)) {
                $fname = grnconnect_unique_pax_name($primaryGuest['first_name'] ?? 'Guest', 1, $usedNames);
                $paxes[] = [
                    'title' => 'Mr.',
                    'name' => $fname,
                    'surname' => $surnameFallback,
                    'type' => 'AD',
                ];
            }
            $perRoom[] = $paxes;
        }

        return $perRoom;
    }
}

// ----------------------------------------------------------------------------
// ROUTE
// ----------------------------------------------------------------------------
$router->post('/stays/grnconnect/issue', function () use ($db) {
    if (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    $payload   = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;
    $invoiceId = $payload['invoice_id'] ?? '';

    $send = function ($data) {
        if (ob_get_level()) ob_clean();
        echo json_encode($data);
        exit;
    };

    if (empty($invoiceId)) {
        $send([
            'status'         => false,
            'success'        => false,
            'booking_status' => 'failed',
            'error'          => 'booking_error',
            'message'        => 'Missing invoice_id parameter',
            'response_error' => 'Missing invoice_id parameter',
        ]);
    }

    $result = grnconnect_issue_booking_logic($db, $invoiceId);
    if (isset($result['status']) && $result['status'] === false) {
        http_response_code(400);
    }
    $send($result);
});
