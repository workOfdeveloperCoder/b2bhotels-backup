<?php
// ============================================================================
// HOTELSTON — ROOM OCCUPANCY LIMITS
// API v2 specification: each room takes 1 to 6 adults and 0 to 3 children.
// A request outside these limits must not be sent to Hotelston at all (it is
// a certification requirement), so every endpoint checks the rooms first.
// ============================================================================

if (!defined('HOTELSTON_MAX_ADULTS_PER_ROOM')) {
    define('HOTELSTON_MAX_ADULTS_PER_ROOM', 6);
    define('HOTELSTON_MAX_CHILDREN_PER_ROOM', 3);
}

if (!function_exists('hotelstonOccupancyError')) {
    /**
     * Returns a message when the selected rooms cannot be sent to Hotelston,
     * or null when every room is within the limits.
     *
     * $roomsData  per-room list: [['adults' => 2, 'children' => 1, 'childAges' => [5]], ...]
     * $rooms, $adults, $children  totals, used only when no per-room list was posted
     */
    function hotelstonOccupancyError($roomsData, int $rooms = 1, int $adults = 2, int $children = 0): ?string
    {
        $list = [];
        if (is_array($roomsData) && !empty($roomsData)) {
            foreach ($roomsData as $room) {
                $room = (array) $room;
                $ages = $room['childAges'] ?? ($room['child_ages'] ?? null);
                $list[] = [
                    (int) ($room['adults'] ?? 2),
                    is_array($ages) && $ages ? count($ages) : max(0, (int) ($room['children'] ?? 0)),
                ];
            }
        } else {
            $rooms = max(1, $rooms);
            $list[] = [(int) ceil(max(1, $adults) / $rooms), (int) ceil(max(0, $children) / $rooms)];
        }

        foreach ($list as $i => [$roomAdults, $roomChildren]) {
            $label = count($list) > 1 ? 'Room ' . ($i + 1) . ': ' : '';
            if ($roomAdults > HOTELSTON_MAX_ADULTS_PER_ROOM) {
                return ucfirst($label . 'a room can take up to ' . HOTELSTON_MAX_ADULTS_PER_ROOM
                     . ' adults. Please add another room for the extra guests.');
            }
            if ($roomAdults < 1) {
                return ucfirst($label . 'a room needs at least 1 adult.');
            }
            if ($roomChildren > HOTELSTON_MAX_CHILDREN_PER_ROOM) {
                return ucfirst($label . 'a room can take up to ' . HOTELSTON_MAX_CHILDREN_PER_ROOM
                     . ' children. Please add another room for the extra guests.');
            }
        }

        return null;
    }
}
