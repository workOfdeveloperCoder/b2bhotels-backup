<?php
// cruises-search.php - Cruise Search Form (choices come from the imported Widgety catalogue)
@$SECURE or die('Access Denied!');

$__wgcCatalog = dirname(__DIR__, 4) . '/modules/cruises/widgety/catalog.php';
if (!function_exists('_wgc_options') && is_file($__wgcCatalog)) { require_once $__wgcCatalog; }

$cruiseOptions = function_exists('_wgc_options') ? _wgc_options($db) : ['destinations' => [], 'operators' => [], 'months' => []];
$cruiseSearch  = $_SESSION['cruise_search'] ?? [];
$cruiseLengths = function_exists('_wgc_lengths') ? _wgc_lengths() : [];

// [value => label] lists for the dropdowns ('' = any)
$cruiseDropdowns = [
    'destination' => [
        'label' => 'Destination', 'icon' => 'public',
        'items' => ['' => 'Any Destination'] + array_combine($cruiseOptions['destinations'], $cruiseOptions['destinations']),
    ],
    'month' => [
        'label' => 'Departure Month', 'icon' => 'calendar_month',
        'items' => ['' => 'Any Month'] + $cruiseOptions['months'],
    ],
    'line' => [
        'label' => 'Cruise Line', 'icon' => 'directions_boat',
        'items' => ['' => 'Any Cruise Line'] + $cruiseOptions['operators'],
    ],
    'length' => [
        'label' => 'Cruise Length', 'icon' => 'schedule',
        'items' => ['any' => 'Any Length'] + array_map(fn($l) => $l[2], $cruiseLengths),
    ],
];
?>

<form class="space-y-3" method="GET" action="<?= root ?>cruises/search">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 lg:[grid-template-columns:1.3fr_1fr_1fr_1fr_1fr_150px]">

        <?php foreach ($cruiseDropdowns as $field => $dd):
            $items = [];
            foreach ($dd['items'] as $v => $n) { $items[] = ['value' => (string) $v, 'name' => (string) $n]; }
            $current = (string) ($cruiseSearch[$field] ?? array_key_first($dd['items']));
            if (!array_key_exists($current, $dd['items'])) { $current = (string) array_key_first($dd['items']); }
        ?>
        <div class="relative">
            <div class="input-dropdown relative" x-data='{
                open: false,
                selected: <?= htmlspecialchars(json_encode($current), ENT_QUOTES) ?>,
                items: <?= htmlspecialchars(json_encode($items), ENT_QUOTES) ?>,
                name() { return (this.items.find(i => i.value === this.selected) || this.items[0]).name; }
            }' @click.away="open = false">
                <input type="hidden" name="<?= $field ?>" :value="selected">
                <div @click="open = !open" class="field-box pr-9 cursor-pointer" :class="open ? 'is-open' : ''">
                    <span class="field-box-icon material-symbols-outlined"><?= $dd['icon'] ?></span>
                    <div class="field-box-content">
                        <span class="field-box-label"><?= $dd['label'] ?></span>
                        <span class="field-box-value truncate" x-text="name()"><?= htmlspecialchars($dd['items'][$current]) ?></span>
                    </div>
                    <span class="material-symbols-outlined field-box-chevron" :class="open ? 'rotate-180' : ''">expand_more</span>
                </div>
                <div class="input-dropdown-content max-h-72 overflow-y-auto" :class="open ? 'show' : ''">
                    <template x-for="item in items" :key="item.value">
                        <div class="input-dropdown-item flex items-center gap-2" :class="item.value === selected ? 'font-semibold' : ''"
                             @click="selected = item.value; open = false">
                            <span x-text="item.name"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Guests (one cabin: up to 4 guests) -->
        <div class="relative">
            <div class="input-dropdown relative" x-data="{
                open: false,
                adults: <?= (int) ($cruiseSearch['adults'] ?? 2) ?>,
                children: <?= (int) ($cruiseSearch['children'] ?? 0) ?>,
                text() {
                    let t = this.adults + ' Adult' + (this.adults > 1 ? 's' : '');
                    if (this.children > 0) t += ', ' + this.children + ' Child' + (this.children > 1 ? 'ren' : '');
                    return t;
                }
            }" @click.away="open = false">
                <input type="hidden" name="adults" :value="adults">
                <input type="hidden" name="children" :value="children">
                <div @click="open = !open" class="field-box pr-9 cursor-pointer" :class="open ? 'is-open' : ''">
                    <span class="field-box-icon material-symbols-outlined">group</span>
                    <div class="field-box-content">
                        <span class="field-box-label">Guests / Cabin</span>
                        <span class="field-box-value" x-text="text()">2 Adults</span>
                    </div>
                    <span class="material-symbols-outlined field-box-chevron" :class="open ? 'rotate-180' : ''">expand_more</span>
                </div>
                <div class="input-dropdown-content" :class="open ? 'show' : ''">
                    <?php foreach (['adults' => ['Adults', '18+ years', 1], 'children' => ['Children', 'Under 18', 0]] as $k => [$lbl, $sub, $min]): ?>
                    <div class="flex items-center justify-between px-3 py-2 border-b border-gray-100 last:border-0">
                        <div>
                            <div class="text-xs font-bold"><?= $lbl ?></div>
                            <div class="text-xs text-gray-500"><?= $sub ?></div>
                        </div>
                        <div class="flex items-center">
                            <button type="button" @click="if (<?= $k ?> > <?= $min ?>) <?= $k ?>--"
                                    class="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50">
                                <span class="material-symbols-outlined" style="font-size:16px">remove</span>
                            </button>
                            <span x-text="<?= $k ?>" class="w-8 text-center text-sm font-bold"></span>
                            <button type="button" @click="if (adults + children < 4) <?= $k ?>++"
                                    class="w-8 h-8 rounded-full border border-gray-300 flex items-center justify-center hover:bg-gray-50">
                                <span class="material-symbols-outlined" style="font-size:16px">add</span>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <p class="px-3 py-2 text-[11px] text-gray-500">Up to 4 guests share one cabin.</p>
                </div>
            </div>
        </div>

        <!-- Search Button -->
        <div>
            <button type="submit" class="btn search-btn w-full h-[67px] rounded-lg flex items-center justify-center gap-2 p-0" title="Search Cruises" aria-label="Search Cruises">
                <span class="search-btn-label"><?= T::search ?></span>
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22" fill="none"><path d="M15.4838 15.5099L18.3073 18.3334M17.4925 10.616C17.4925 14.454 14.3916 17.5654 10.5666 17.5654C6.74147 17.5654 3.64062 14.454 3.64062 10.616C3.64062 6.77801 6.74147 3.66669 10.5666 3.66669C14.3916 3.66669 17.4925 6.77801 17.4925 10.616Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
            </button>
        </div>
    </div>
</form>
