<?php
/**
 * GRNConnect Recheck → Booking validation helpers
 * ================================================
 * Protective checks only — does not replace search/refetch/recheck/book logic.
 *
 * - Price change: compare selection/refetch net vs recheck net; require accept
 * - Availability: block booking when rate/bundle/identifiers/qty are invalid
 */

if (!function_exists('grnconnect_unavailable_message')) {
    function grnconnect_unavailable_message()
    {
        return 'Sorry, this room is no longer available. Please select another option.';
    }
}

if (!function_exists('grnconnect_price_change_message')) {
    function grnconnect_price_change_message()
    {
        return 'The price has changed. Please review the updated price before continuing.';
    }
}

if (!function_exists('grnconnect_prices_differ')) {
    /** True when absolute difference exceeds tolerance (default 0.01). */
    function grnconnect_prices_differ($a, $b, $tolerance = 0.01)
    {
        return abs((float)$a - (float)$b) > (float)$tolerance;
    }
}

if (!function_exists('grnconnect_build_price_comparison')) {
    /**
     * Compare stored selection/refetch price with latest recheck price.
     *
     * @param float       $previousPrice Price stored at selection (net preferred)
     * @param float       $updatedPrice  Price from recheck response (net)
     * @param string      $currency
     * @param bool|null   $supplierFlag  GRN response_body.price_changed when present
     * @return array{
     *   changed:bool,
     *   previous_price:float,
     *   updated_price:float,
     *   currency:string,
     *   message:?string
     * }
     */
    function grnconnect_build_price_comparison($previousPrice, $updatedPrice, $currency, $supplierFlag = null)
    {
        $prev = round((float)$previousPrice, 2);
        $next = round((float)$updatedPrice, 2);
        $currency = strtoupper(trim((string)$currency)) ?: 'USD';

        // Primary: numeric compare against stored selection/refetch net
        $changed = ($prev > 0 && grnconnect_prices_differ($prev, $next));

        // Also honour GRN response_body.price_changed when both amounts are known
        if ($supplierFlag === true && $prev > 0 && $next > 0 && grnconnect_prices_differ($prev, $next)) {
            $changed = true;
        }

        return [
            'changed'         => $changed,
            'previous_price'  => $prev,
            'updated_price'   => $next,
            'currency'        => $currency,
            'message'         => $changed ? grnconnect_price_change_message() : null,
        ];
    }
}

if (!function_exists('grnconnect_evaluate_recheck_availability')) {
    /**
     * Evaluate whether a rechecked rate is still bookable.
     *
     * Handles: missing rate, unavailable status, invalid rate_key/group_code,
     * missing bundled rooms, insufficient room quantity.
     *
     * @param array $rate              Parsed recheck rate
     * @param array $rawRecheckData    Raw recheck response body (optional)
     * @param array $token             Packed-token fields from selection (optional)
     * @param int   $requestedRooms    Occupancy room count from search (optional)
     * @return array{available:bool, reason:?string, message:?string}
     */
    function grnconnect_evaluate_recheck_availability(array $rate, array $rawRecheckData = [], array $token = [], $requestedRooms = 0)
    {
        $fail = static function ($reason) {
            return [
                'available' => false,
                'reason'    => $reason,
                'message'   => grnconnect_unavailable_message(),
            ];
        };

        if (empty($rate)) {
            return $fail('missing_rate');
        }

        $rateKey   = trim((string)($rate['rate_key'] ?? ''));
        $groupCode = trim((string)($rate['group_code'] ?? ''));
        if ($rateKey === '' || $groupCode === '') {
            return $fail('invalid_identifiers');
        }

        // Explicit unavailable / sold-out signals from supplier
        $statusCandidates = [
            $rate['status'] ?? null,
            $rate['availability'] ?? null,
            $rate['availability_status'] ?? null,
            $rawRecheckData['status'] ?? null,
            $rawRecheckData['hotel']['status'] ?? null,
        ];
        foreach ($statusCandidates as $status) {
            if ($status === null || $status === '') {
                continue;
            }
            $normalized = strtolower(trim((string)$status));
            if (in_array($normalized, [
                'unavailable', 'not_available', 'notavailable',
                'soldout', 'sold_out', 'sold-out',
                'failed', 'error', 'invalid', 'expired',
            ], true)) {
                return $fail('unavailable_status');
            }
            if ($normalized === '0' || $normalized === 'false' || $normalized === 'no') {
                return $fail('unavailable_status');
            }
        }

        // After recheck, rate should be bookable (not still requiring recheck-only)
        $rateType = strtolower(trim((string)($rate['rate_type'] ?? 'bookable')));
        if ($rateType !== '' && !in_array($rateType, ['bookable', 'recheck'], true)) {
            return $fail('invalid_rate_type');
        }

        $roomsNode = is_array($rate['rooms'] ?? null) ? $rate['rooms'] : [];
        $roomsInRate = max(
            (int)($rate['no_of_rooms'] ?? 0),
            count($roomsNode)
        );

        // Needed rooms must be this rate/package allocation, not the whole
        // search occupancy. Mixing two single-room rates for a 2-room stay
        // would otherwise mark each rate as quantity_unavailable.
        $needed = max(
            (int)($token['rooms_in_rate'] ?? 0),
            (int)($token['no_of_rooms'] ?? 0),
            (int)($token['allocation_size'] ?? 0)
        );
        if ($needed < 1) {
            $needed = max(1, (int)$requestedRooms);
        }

        // Bundled / multi-room package missing room nodes
        $isBundled = !empty($token['bundled'])
            || ((int)($token['rooms_in_rate'] ?? 0) > 1)
            || ((int)($token['allocation_size'] ?? 0) > 1)
            || $needed > 1;
        if ($isBundled && empty($roomsNode)) {
            return $fail('bundle_missing');
        }

        // Quantity no longer available for THIS rate/package
        if ($needed > 0 && $roomsInRate > 0 && $roomsInRate < $needed) {
            return $fail('quantity_unavailable');
        }

        // Price must exist
        $price = 0.0;
        if (function_exists('grnconnect_rate_price_total')) {
            $price = (float)grnconnect_rate_price_total($rate);
        } elseif (isset($rate['price'])) {
            $price = (float)$rate['price'];
        }
        if ($price <= 0) {
            return $fail('no_price');
        }

        return [
            'available' => true,
            'reason'    => null,
            'message'   => null,
        ];
    }
}

if (!function_exists('grnconnect_assert_recheck_bookable')) {
    /**
     * Throw unavailable message when recheck rate cannot be booked.
     * Call immediately after mandatory recheck, before Booking API.
     */
    function grnconnect_assert_recheck_bookable(array $rate, array $rawRecheckData = [], array $token = [], $requestedRooms = 0)
    {
        $result = grnconnect_evaluate_recheck_availability($rate, $rawRecheckData, $token, $requestedRooms);
        if (empty($result['available'])) {
            throw new Exception($result['message'] ?: grnconnect_unavailable_message());
        }
        return $result;
    }
}

if (!function_exists('grnconnect_assert_issue_price_accepted')) {
    /**
     * Before Booking API: if checkout recorded an accepted recheck price,
     * ensure issue-time recheck still matches (within tolerance).
     * Blocks silent overwrite when supplier price moved again after accept.
     *
     * @param array $grnRecheckMeta booking_data.grn_recheck (may include acceptance)
     * @param float $issueNetPrice  Net price from issue-time recheck
     */
    function grnconnect_assert_issue_price_accepted(array $grnRecheckMeta, $issueNetPrice)
    {
        if (empty($grnRecheckMeta['price_accepted'])) {
            return;
        }

        $accepted = $grnRecheckMeta['accepted_net_price']
            ?? $grnRecheckMeta['updated_price']
            ?? null;

        if ($accepted === null || (float)$accepted <= 0) {
            return;
        }

        if (grnconnect_prices_differ((float)$accepted, (float)$issueNetPrice)) {
            $currency = strtoupper((string)($grnRecheckMeta['currency'] ?? 'USD'));
            throw new Exception(
                grnconnect_price_change_message()
                . ' Previous: ' . number_format((float)$accepted, 2, '.', '') . ' ' . $currency
                . ' / Updated: ' . number_format((float)$issueNetPrice, 2, '.', '') . ' ' . $currency
            );
        }
    }
}
