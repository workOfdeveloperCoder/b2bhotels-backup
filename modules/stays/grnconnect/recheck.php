<?php
// ============================================================================
// GRNCONNECT RATE RECHECK - MANDATORY BEFORE BOOKING
// ============================================================================
// ENDPOINT: POST /stays/grnconnect/recheck
// POST api/v3/hotels/availability/<sid>/rates/?action=recheck
//
// Supports:
//  - Bundled: one rate_key covering all rooms
//  - Non-bundled multi-room: multiple rate_keys (same group_code) as separate items
// ============================================================================

$router->post('/stays/grnconnect/recheck', function () use ($db) {
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    $send = function ($data, $code = 200) {
        if (ob_get_level()) ob_clean();
        http_response_code($code);
        echo json_encode($data);
        exit;
    };

    try {
        require_once __DIR__ . '/api.php';
        require_once __DIR__ . '/selection.php';
        require_once __DIR__ . '/validation.php';

        $input         = json_decode(file_get_contents('php://input'), true) ?: [];
        $hotelId       = (string)($input['hotel_id'] ?? '');
        $checkin       = grnconnect_to_iso_date($input['checkin'] ?? '');
        $checkout      = grnconnect_to_iso_date($input['checkout'] ?? '');
        $nationality   = strtoupper(substr(trim($input['nationality'] ?? 'US'), 0, 2));
        $currency      = $input['currency'] ?? $_SESSION['app_currency'] ?? 'USD';
        $selectedRooms = $input['selected_rooms'] ?? [];
        $nights        = max(1, (int)($input['nights'] ?? grnconnect_nights($checkin, $checkout)));

        if ($hotelId === '') {
            $send(['success' => false, 'message' => 'Missing hotel_id'], 400);
        }
        if ($checkin === '' || $checkout === '') {
            $send(['success' => false, 'message' => 'Missing checkin/checkout dates'], 400);
        }
        if (!is_array($selectedRooms) || empty($selectedRooms)) {
            $send(['success' => false, 'message' => 'No selected rooms to recheck'], 400);
        }

        $module = grnconnect_module($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            $send(['success' => false, 'message' => 'GRNConnect module not configured'], 400);
        }
        $client   = GRNConnectClient::fromModule($module);
        $grnRooms = grnconnect_rooms_from_request($input);

        // Treat each rate_key as an atomic package. Validate the submitted
        // allocation before deduping so package room counts cannot be altered
        // client-side and different package components cannot be split.
        $selectionValidation = grnconnect_validate_package_selection($grnRooms, $selectedRooms);
        if (empty($selectionValidation['ok'])) {
            $send([
                'success' => false,
                'message' => $selectionValidation['message'] ?? 'Invalid GRN room package selection.',
            ], 400);
        }

        // Bundled/partial: same rate_key may appear on multiple slots — recheck once each
        $selectedRooms = grnconnect_dedupe_selected_rate_tokens($selectedRooms);

        $tokenCache   = [];
        $verifiedRooms = [];
        $groupCodes   = [];
        $anyPriceChanged = false;
        $primaryPayload = null;
        $recheckItems = [];
        $seenRecheckKeys = [];
        $priceComparisons = [];
        $aggPrevious = 0.0;
        $aggUpdated = 0.0;
        $priceCurrency = $currency;

        foreach ($selectedRooms as $index => $roomSel) {
            if (!is_array($roomSel)) {
                $send(['success' => false, 'message' => 'Invalid selected room at index ' . $index], 400);
            }

            $rateToken = $roomSel['option']['rate_key'] ?? ($roomSel['rate_key'] ?? '');
            if ($rateToken === '') {
                $send(['success' => false, 'message' => 'Missing GRN rate token on selected room ' . ($index + 1)], 400);
            }

            $cacheKey = function_exists('grnconnect_recheck_dedupe_key')
                ? grnconnect_recheck_dedupe_key($rateToken)
                : $rateToken;
            if ($cacheKey === '') {
                $cacheKey = $rateToken;
            }

            if (!isset($tokenCache[$cacheKey])) {
                $token = grnconnect_unpack_rate($rateToken);
                if (!$token) {
                    $send(['success' => false, 'message' => 'Invalid GRN rate token on selected room ' . ($index + 1)], 400);
                }

                $payload = grnconnect_build_recheck_payload(
                    $client,
                    $module,
                    $db,
                    $token,
                    $grnRooms,
                    $nationality,
                    $currency,
                    $nights
                );
                $tokenCache[$cacheKey] = $payload;
            }

            $payload = $tokenCache[$cacheKey];
            if ($primaryPayload === null) {
                $primaryPayload = $payload;
            }

            $gc = trim((string)($payload['group_code'] ?? ''));
            if ($gc !== '') {
                $groupCodes[$gc] = true;
            }
            if (!empty($payload['price_changed'])) {
                $anyPriceChanged = true;
            }

            if (!empty($payload['price_comparison'])) {
                $priceComparisons[] = $payload['price_comparison'];
            }
            $aggPrevious += (float)($payload['previous_price'] ?? 0);
            $aggUpdated  += (float)($payload['updated_price'] ?? 0);
            if (!empty($payload['price_currency'])) {
                $priceCurrency = $payload['price_currency'];
            }

            // Keep packed token on option in sync with rechecked rate identifiers
            $option = $payload['option'];
            if (is_array($option) && !empty($payload['grn_recheck'])) {
                $freshToken = grnconnect_unpack_rate($option['rate_key'] ?? '');
                if (is_array($freshToken)) {
                    $freshToken['search_id']  = $payload['search_id'];
                    $freshToken['group_code'] = $payload['group_code'];
                    $freshToken['rate_key']   = $payload['rate_key'];
                    $freshToken['room_code']  = $payload['room_code'] ?? ($freshToken['room_code'] ?? '');
                    $liveRefs = [];
                    if (!empty($payload['grn_recheck']['room_references']) && is_array($payload['grn_recheck']['room_references'])) {
                        $liveRefs = array_values(array_filter(array_map('strval', $payload['grn_recheck']['room_references'])));
                    } elseif (function_exists('grnconnect_rate_room_references') && !empty($payload['rate']) && is_array($payload['rate'])) {
                        $liveRefs = grnconnect_rate_room_references($payload['rate']);
                    }
                    if (!empty($liveRefs)) {
                        $freshToken['room_references'] = $liveRefs;
                        $freshToken['room_reference']  = $liveRefs[0];
                    }
                    // Keep selection net on token for audit; booking uses recheck net after accept
                    if (isset($payload['updated_price'])) {
                        $freshToken['net_price'] = (float)$payload['updated_price'];
                        $freshToken['price']     = (float)($option['total_price'] ?? $payload['updated_price']);
                    }
                    $option['rate_key']       = grnconnect_pack_rate($freshToken);
                    $option['group_code']     = $payload['group_code'];
                }
            }

            $item = [
                'index'            => $index,
                'room_index'       => (int)($roomSel['room_index'] ?? ($index + 1)),
                'search_id'        => $payload['search_id'],
                'group_code'       => $payload['group_code'],
                'rate_key'         => $payload['rate_key'],
                'option'           => $option,
                'rechecked_at'     => $payload['rechecked_at'],
                'price_changed'    => $payload['price_changed'],
                'previous_price'   => $payload['previous_price'] ?? null,
                'updated_price'    => $payload['updated_price'] ?? null,
                'price_currency'   => $payload['price_currency'] ?? $currency,
                'price_comparison' => $payload['price_comparison'] ?? null,
                'available'        => $payload['available'] ?? true,
                'grn_recheck'      => $payload['grn_recheck'],
                'allocation_size'  => (int)($option['rooms_in_rate'] ?? ($option['allocation_size'] ?? 1)),
            ];
            $verifiedRooms[] = $item;
            $recheckKey = grnconnect_recheck_dedupe_key($payload['rate_key'] ?? '')
                . '|' . trim((string)($payload['group_code'] ?? ''));
            if ($recheckKey !== '|' && !isset($seenRecheckKeys[$recheckKey]) && !empty($payload['grn_recheck'])) {
                $recheckItems[] = $payload['grn_recheck'];
                $seenRecheckKeys[$recheckKey] = true;
            }
        }

        if (count($groupCodes) > 1) {
            $send([
                'success' => false,
                'message' => 'Selected rooms must belong to the same rate group. Please choose compatible room options.',
            ], 400);
        }

        $uniqueCount = count($tokenCache);
        $requested   = max(1, count($grnRooms));
        $mode = 'single';
        if ($requested > 1) {
            if ($uniqueCount === 1) {
                $mode = 'bundled';
            } elseif ($uniqueCount === $requested) {
                $mode = 'non_bundled';
            } else {
                $mode = 'partial';
            }
        }

        // Deduped tokens: aggregate prices once per unique rate (already summed via unique payloads)
        if ($uniqueCount > 0 && $uniqueCount < count($selectedRooms)) {
            $aggPrevious = 0.0;
            $aggUpdated  = 0.0;
            foreach ($tokenCache as $payload) {
                $aggPrevious += (float)($payload['previous_price'] ?? 0);
                $aggUpdated  += (float)($payload['updated_price'] ?? 0);
            }
        }

        $send([
            'success' => true,
            'data'    => [
                'hotel_id'         => $hotelId,
                'rooms'            => $verifiedRooms,
                'search_id'        => $primaryPayload['search_id'] ?? '',
                'group_code'       => $primaryPayload['group_code'] ?? '',
                'rate_key'         => $primaryPayload['rate_key'] ?? '',
                'option'           => $primaryPayload['option'] ?? null,
                'rechecked_at'     => $primaryPayload['rechecked_at'] ?? date('c'),
                'price_changed'    => $anyPriceChanged,
                'previous_price'   => round($aggPrevious, 2),
                'updated_price'    => round($aggUpdated, 2),
                'price_currency'   => $priceCurrency,
                'price_comparison' => [
                    'changed'        => $anyPriceChanged,
                    'previous_price' => round($aggPrevious, 2),
                    'updated_price'  => round($aggUpdated, 2),
                    'currency'       => $priceCurrency,
                    'message'        => $anyPriceChanged
                        ? (function_exists('grnconnect_price_change_message') ? grnconnect_price_change_message() : null)
                        : null,
                    'items'          => $priceComparisons,
                ],
                'available'        => true,
                'grn_recheck'      => $primaryPayload['grn_recheck'] ?? null,
                'grn_rechecks'     => $recheckItems,
                'bundled'          => $mode === 'bundled',
                'selection_mode'   => $mode,
            ],
        ]);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $isUnavailable = function_exists('grnconnect_unavailable_message')
            && $msg === grnconnect_unavailable_message();
        $send([
            'success'     => false,
            'message'     => $msg,
            'unavailable' => $isUnavailable,
            'error_code'  => $isUnavailable ? 'rate_unavailable' : 'recheck_failed',
        ], 400);
    }
});
