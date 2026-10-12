<?php
/**
 * GRNConnect room-selection helpers.
 *
 * Supports bundled / non-bundled / partial-bundled allocation without
 * rewriting the shared booking draft → recheck → issue pipeline.
 */

if (!function_exists('grnconnect_rate_allocation_size')) {
    /** How many physical rooms a rate covers. */
    function grnconnect_rate_allocation_size(array $rate)
    {
        $fromRooms = function_exists('grnconnect_rate_rooms_nodes')
            ? count(grnconnect_rate_rooms_nodes($rate))
            : (is_array($rate['rooms'] ?? null) ? count($rate['rooms']) : 0);
        $fromNo    = (int)($rate['no_of_rooms'] ?? 0);
        return max(1, $fromRooms, $fromNo);
    }
}

if (!function_exists('grnconnect_rate_mode')) {
    /** Classify rate relative to total requested rooms. */
    function grnconnect_rate_mode(array $rate, int $requestedRooms)
    {
        $size = grnconnect_rate_allocation_size($rate);
        if ($size >= $requestedRooms && $requestedRooms > 1) {
            return 'bundled';
        }
        if ($size > 1) {
            return 'partial';
        }
        return 'single';
    }
}

if (!function_exists('grnconnect_slot_from_grn_room')) {
    function grnconnect_slot_from_grn_room(array $room, int $index)
    {
        $adults = max(1, (int)($room['adults'] ?? 2));
        $ages   = $room['children_ages'] ?? [];
        if (!is_array($ages)) {
            $ages = [];
        }
        $ages     = array_values(array_map('intval', $ages));
        $children = count($ages);

        $parts = [$adults . ' Adult' . ($adults > 1 ? 's' : '')];
        if ($children > 0) {
            $childPart = $children . ' Child' . ($children > 1 ? 'ren' : '');
            if (!empty($ages)) {
                $childPart .= ' (age' . (count($ages) > 1 ? 's' : '') . ': ' . implode(', ', $ages) . ')';
            }
            $parts[] = $childPart;
        }

        return [
            'room_index'  => $index,
            'adults'      => $adults,
            'children'    => $children,
            'child_ages'  => $ages,
            'label'       => implode(' + ', $parts),
            'title'       => 'Room ' . $index . ' (' . implode(' + ', $parts) . ')',
        ];
    }
}

if (!function_exists('grnconnect_occupancy_compatible')) {
    /**
     * Whether a rate room element can host a requested slot occupancy.
     * Uses priced occupancy when present; falls back to max_room_occupancy.
     */
    function grnconnect_occupancy_compatible(array $rateRoom, array $slot)
    {
        $needAdults   = max(1, (int)($slot['adults'] ?? 1));
        $needChildren = max(0, (int)($slot['children'] ?? 0));

        $maxOcc = is_array($rateRoom['max_room_occupancy'] ?? null) ? $rateRoom['max_room_occupancy'] : [];
        $capAdults = (int)($maxOcc['max_adults'] ?? 0);
        $capChildren = (int)($maxOcc['max_children'] ?? 0);

        $pricedAdults = (int)($rateRoom['no_of_adults'] ?? 0);
        $pricedChildren = (int)($rateRoom['no_of_children'] ?? 0);

        // Prefer exact priced occupancy when GRN set it for this search room
        if ($pricedAdults > 0 || $pricedChildren > 0) {
            return $pricedAdults === $needAdults && $pricedChildren === $needChildren;
        }

        if ($capAdults < 1) {
            $capAdults = max(2, $needAdults);
        }
        if ($capAdults < $needAdults) {
            return false;
        }
        if ($capChildren < $needChildren) {
            return false;
        }
        return true;
    }
}

if (!function_exists('grnconnect_rate_fits_slots')) {
    /** Check consecutive slots against rate rooms starting at $startIndex (0-based). */
    function grnconnect_rate_fits_slots(array $rate, array $slots, int $startIndex)
    {
        $rateRooms = is_array($rate['rooms'] ?? null) ? array_values($rate['rooms']) : [];
        $size      = grnconnect_rate_allocation_size($rate);
        if ($size < 1 || empty($slots)) {
            return false;
        }
        if ($startIndex < 0 || ($startIndex + $size) > count($slots)) {
            return false;
        }

        for ($i = 0; $i < $size; $i++) {
            $rateRoom = $rateRooms[$i] ?? ($rateRooms[0] ?? []);
            if (!is_array($rateRoom)) {
                $rateRoom = [];
            }
            if (!grnconnect_occupancy_compatible($rateRoom, $slots[$startIndex + $i])) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('grnconnect_merge_rate_lists')) {
    /** Merge rate arrays, keyed by rate_key (later entries keep first). */
    function grnconnect_merge_rate_lists(array ...$lists)
    {
        $out = [];
        foreach ($lists as $list) {
            foreach ($list as $rate) {
                if (!is_array($rate)) {
                    continue;
                }
                $key = (string)($rate['rate_key'] ?? '');
                if ($key === '') {
                    $out[] = $rate;
                    continue;
                }
                if (!isset($out[$key])) {
                    $out[$key] = $rate;
                }
            }
        }
        return array_values($out);
    }
}

if (!function_exists('grnconnect_enrich_room_option')) {
    /** Attach selection metadata used by the per-slot UI. */
    function grnconnect_enrich_room_option(array $option, array $rate, int $requestedRooms, int $startRoomIndex = 1)
    {
        $size = grnconnect_rate_allocation_size($rate);
        $mode = grnconnect_rate_mode($rate, $requestedRooms);

        $option['allocation_size']   = $size;
        $option['rate_mode']         = $mode;
        $option['no_of_rooms']       = max(1, (int)($rate['no_of_rooms'] ?? $size));
        $option['mixable']           = $size < $requestedRooms;
        $option['covers_from_index'] = $startRoomIndex;
        $option['covers_to_index']   = $startRoomIndex + $size - 1;
        $option['available_quantity'] = 1;

        $typeName = '';
        if (!empty($option['rate_rooms'][0]['room_type'])) {
            $typeName = (string)$option['rate_rooms'][0]['room_type'];
        } elseif (!empty($option['rate_rooms'][0]['description'])) {
            $typeName = (string)$option['rate_rooms'][0]['description'];
        }
        $option['room_type_label'] = $typeName !== '' ? $typeName : (string)($option['occupancy_label'] ?? 'Room');

        if ($size > 1) {
            $option['selection_label'] = function_exists('grnconnect_rate_package_label')
                ? grnconnect_rate_package_label($rate)
                : ($option['room_type_label'] . ' × ' . $size);
        } else {
            $option['selection_label'] = $option['room_type_label'];
        }

        return $option;
    }
}

if (!function_exists('grnconnect_build_room_slots')) {
    /**
     * Build per-requested-room offer lists.
     *
     * Each slot lists rates that can START at that room index
     * (singles for that occupancy, partial/bundled packages spanning forward).
     *
     * @return array{slots: array, legacy_rooms: array}
     */
    function grnconnect_build_room_slots(array $grnRooms, array $rates, array $optionCtx, array $images = [])
    {
        $requested = max(1, count($grnRooms));
        $slots     = [];
        for ($i = 0; $i < $requested; $i++) {
            $base = grnconnect_slot_from_grn_room($grnRooms[$i] ?? ['adults' => 2], $i + 1);
            $base['offers'] = [];
            $slots[] = $base;
        }

        $roomImages = $images['urls'] ?? [];
        $mainImage  = $images['main_image'] ?? '';
        $hotelId    = (string)($optionCtx['hotel_id'] ?? '');

        $legacyGrouped = [];
        $seenOfferKeys = []; // slotIndex|rate_key

        foreach ($rates as $rate) {
            if (!is_array($rate)) {
                continue;
            }
            $basePrice = function_exists('grnconnect_rate_price_total')
                ? grnconnect_rate_price_total($rate)
                : (float)($rate['price'] ?? 0);
            if ($basePrice <= 0) {
                continue;
            }

            $size = grnconnect_rate_allocation_size($rate);
            if ($size > $requested) {
                continue;
            }

            $displayName = function_exists('grnconnect_rate_package_label')
                ? grnconnect_rate_package_label($rate)
                : grnconnect_rate_display_name($rate);

            $optionCtx['room_name'] = $displayName;
            $optionCtx['mixable']   = $size < $requested;
            $built = grnconnect_build_room_option($rate, $optionCtx);
            if (empty($built)) {
                continue;
            }

            for ($start = 0; $start <= $requested - $size; $start++) {
                if (!grnconnect_rate_fits_slots($rate, $slots, $start)) {
                    continue;
                }

                $offerKey = $start . '|' . (string)($rate['rate_key'] ?? md5($displayName . $start));
                if (isset($seenOfferKeys[$offerKey])) {
                    continue;
                }
                $seenOfferKeys[$offerKey] = true;

                $enriched = grnconnect_enrich_room_option($built, $rate, $requested, $start + 1);
                $slots[$start]['offers'][] = $enriched;
            }

            // Legacy flat list (room-type cards) — group by display name
            $legacyGrouped[$displayName][] = [
                'rate'   => $rate,
                'option' => grnconnect_enrich_room_option($built, $rate, $requested, 1),
            ];
        }

        // Sort offers: singles first, then by price
        foreach ($slots as &$slot) {
            usort($slot['offers'], static function ($a, $b) {
                $sa = (int)($a['allocation_size'] ?? 1);
                $sb = (int)($b['allocation_size'] ?? 1);
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }
                return ((float)($a['total_price'] ?? 0)) <=> ((float)($b['total_price'] ?? 0));
            });
        }
        unset($slot);

        $legacyRooms = [];
        foreach ($legacyGrouped as $roomName => $items) {
            $options = [];
            $seen    = [];
            $maxA = 1;
            $maxC = 0;
            foreach ($items as $item) {
                $opt = $item['option'];
                $rk  = (string)($opt['option_id'] ?? '');
                if ($rk !== '' && isset($seen[$rk])) {
                    continue;
                }
                if ($rk !== '') {
                    $seen[$rk] = true;
                }
                $maxA = max($maxA, (int)($opt['adults'] ?? 1));
                $maxC = max($maxC, (int)($opt['children'] ?? 0));
                $options[] = $opt;
            }
            if (empty($options)) {
                continue;
            }
            $first = $options[0];
            $firstRoom = $first['rate_rooms'][0] ?? [];
            $legacyRooms[] = [
                'room_id'             => md5($hotelId . '|' . $roomName),
                'room_type_id'        => md5($hotelId . '|' . $roomName),
                'room_name'           => $roomName,
                'room_type_name'      => $firstRoom['room_type'] ?? $roomName,
                'description'         => (string)($firstRoom['description'] ?? ''),
                'rate_rooms'          => $first['rate_rooms'] ?? [],
                'is_package'          => ((int)($first['allocation_size'] ?? 1)) > 1,
                'max_adults'          => $maxA,
                'max_children'        => $maxC,
                'bed_type'            => function_exists('grnconnect_bed_type')
                    ? grnconnect_bed_type($firstRoom)
                    : 'Standard',
                'amenities'           => [],
                'room_images'         => $roomImages,
                'room_main_image'     => $mainImage,
                'min_price_per_night' => round(min(array_column($options, 'price_per_night')), 2),
                'options_count'       => count($options),
                'options'             => $options,
            ];
        }

        return [
            'slots'        => $slots,
            'legacy_rooms' => $legacyRooms,
        ];
    }
}

if (!function_exists('grnconnect_dedupe_selected_rate_tokens')) {
    /**
     * Collapse slot rows that share the same GRN rate_key.
     *
     * Important: packed tokens (GRN.base64...) can differ even when the inner
     * GRN rate_key is identical (metadata refresh after checkout recheck).
     * Deduping by packed string caused duplicate GRN recheck calls for one
     * bundled package. Always prefer the unpacked supplier rate_key.
     */
    function grnconnect_dedupe_selected_rate_tokens(array $selectedRooms)
    {
        $unique = [];
        foreach ($selectedRooms as $sel) {
            if (!is_array($sel)) {
                continue;
            }
            $token = (string)($sel['option']['rate_key'] ?? ($sel['rate_key'] ?? ''));
            if ($token === '') {
                $unique[] = $sel;
                continue;
            }

            $dedupeKey = $token;
            if (function_exists('grnconnect_unpack_rate')) {
                $unpacked = grnconnect_unpack_rate($token);
                $innerKey = is_array($unpacked) ? trim((string)($unpacked['rate_key'] ?? '')) : '';
                if ($innerKey !== '') {
                    $dedupeKey = $innerKey;
                }
            }

            if (!isset($unique[$dedupeKey])) {
                $unique[$dedupeKey] = $sel;
            }
        }
        return array_values($unique);
    }
}

if (!function_exists('grnconnect_validate_package_selection')) {
    /**
     * Validate the atomic GRN package allocations submitted by the UI.
     *
     * A rate_key identifies one selected package. group_code only identifies
     * compatible rates; it must not be used to merge distinct packages.
     *
     * @return array{ok:bool,message?:string,allocated_rooms?:int,unique_rates?:int,mode?:string}
     */
    function grnconnect_validate_package_selection(array $requestedRooms, array $selectedRooms)
    {
        $needed = max(1, count($requestedRooms));
        $allocated = 0;
        $groups = [];
        $rawRateKeys = [];

        foreach ($selectedRooms as $index => $selection) {
            if (!is_array($selection)) {
                return ['ok' => false, 'message' => 'Invalid selected room package at index ' . $index . '.'];
            }

            $option = is_array($selection['option'] ?? null) ? $selection['option'] : $selection;
            $packed = (string)($option['rate_key'] ?? ($selection['rate_key'] ?? ''));
            $token = function_exists('grnconnect_unpack_rate') ? grnconnect_unpack_rate($packed) : null;
            if (!is_array($token) || empty($token['rate_key'])) {
                return ['ok' => false, 'message' => 'Invalid GRN rate package at index ' . $index . '.'];
            }

            $rawRateKey = (string)$token['rate_key'];
            if (isset($rawRateKeys[$rawRateKey])) {
                return ['ok' => false, 'message' => 'The same GRN rate package was selected more than once.'];
            }
            $rawRateKeys[$rawRateKey] = true;

            $allocation = max(
                1,
                (int)($option['allocation_size'] ?? 0),
                (int)($option['rooms_in_rate'] ?? 0),
                (int)($token['rooms_in_rate'] ?? 0),
                (int)($token['no_of_rooms'] ?? 0)
            );
            $submittedQuantity = max(1, (int)($selection['quantity'] ?? $allocation));
            if ($submittedQuantity !== $allocation) {
                return [
                    'ok' => false,
                    'message' => 'Selected GRN package room count does not match its rate allocation.',
                ];
            }
            $allocated += $allocation;

            $group = trim((string)($option['group_code'] ?? ($token['group_code'] ?? '')));
            if ($group !== '') {
                $groups[$group] = true;
            }
        }

        if ($allocated !== $needed) {
            return [
                'ok' => false,
                'message' => 'Selected GRN packages cover ' . $allocated . ' room(s), but ' . $needed . ' room(s) were requested.',
            ];
        }
        if (count($groups) > 1) {
            return [
                'ok' => false,
                'message' => 'Selected rooms must belong to the same rate group. Please choose compatible room options.',
            ];
        }

        $uniqueRates = count($rawRateKeys);
        return [
            'ok' => true,
            'allocated_rooms' => $allocated,
            'unique_rates' => $uniqueRates,
            'mode' => $uniqueRates === 1 && $needed > 1 ? 'bundled' : ($needed > 1 ? 'non_bundled' : 'single'),
        ];
    }
}

if (!function_exists('grnconnect_validate_slot_selection')) {
    /**
     * Validate frontend selection before draft/recheck.
     *
     * @return array{ok:bool, message?:string, mode?:string, unique_rates?:int}
     */
    function grnconnect_validate_slot_selection(array $slots, array $selectedRooms)
    {
        $needed = count($slots);
        if ($needed < 1) {
            return ['ok' => false, 'message' => 'No rooms requested.'];
        }

        $byIndex = [];
        foreach ($selectedRooms as $sel) {
            if (!is_array($sel)) {
                continue;
            }
            $idx = (int)($sel['room_index'] ?? 0);
            if ($idx < 1) {
                continue;
            }
            $byIndex[$idx] = $sel;
        }

        for ($i = 1; $i <= $needed; $i++) {
            if (empty($byIndex[$i]['option']['rate_key']) && empty($byIndex[$i]['selected_rate']['rate_key'])) {
                return ['ok' => false, 'message' => 'Please select a rate for Room ' . $i . '.'];
            }
        }

        $groups = [];
        $tokens = [];
        foreach ($byIndex as $sel) {
            $opt = $sel['option'] ?? ($sel['selected_rate'] ?? []);
            if (!is_array($opt)) {
                $opt = [];
            }
            $gc = trim((string)($opt['group_code'] ?? ''));
            if ($gc !== '') {
                $groups[$gc] = true;
            }
            $token = (string)($opt['rate_key'] ?? '');
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }

        if (count($groups) > 1) {
            return [
                'ok'      => false,
                'message' => 'Selected rooms must belong to the same rate group. Please choose compatible room options.',
            ];
        }

        $uniqueRates = count($tokens);
        $mode = 'single';
        if ($needed > 1) {
            if ($uniqueRates === 1) {
                $mode = 'bundled';
            } elseif ($uniqueRates === $needed) {
                $mode = 'non_bundled';
            } else {
                $mode = 'partial';
            }
        }

        return [
            'ok'           => true,
            'mode'         => $mode,
            'unique_rates' => $uniqueRates,
            'group_code'   => $groups ? array_key_first($groups) : '',
        ];
    }
}
