<?php
// CRUISE SEARCH RESULTS — expects $filters, $results (current page), $total, $page, $pages, $options
@$SECURE or die('Access Denied!');

$cardQuery = ['adults' => $filters['adults'], 'children' => $filters['children']];
$pageUrl = fn(array $over) => root . 'cruises/search?' . http_build_query(array_filter(array_merge($filters, $over),
    fn($v) => $v !== '' && $v !== null && $v !== 'any'));
$lengths = _wgc_lengths();
?>
<div class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 py-4">
        <div class="container">
            <?php include dirname(__DIR__) . '/cruises-search.php'; ?>
        </div>
    </div>

    <div class="container py-6">
        <?php if (!empty($_SESSION['message'])): ?>
            <div class="alert alert-warning mb-4"><span class="material-symbols-outlined">info</span><div><?= htmlspecialchars($_SESSION['message']['text'] ?? '') ?></div></div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-5">

            <!-- FILTERS -->
            <aside class="lg:col-span-1" x-data="{ open: window.innerWidth >= 1024 }">
                <button type="button" @click="open = !open" class="lg:hidden btn light w-full justify-center mb-3">
                    <span class="material-symbols-outlined">tune</span> <span x-text="open ? 'Hide filters' : 'Show filters'">Show filters</span>
                </button>
                <form method="GET" action="<?= root ?>cruises/search" class="card p-0 lg:sticky lg:top-4" x-show="open" x-cloak @change="$el.submit()">
                    <input type="hidden" name="adults" value="<?= (int) $filters['adults'] ?>">
                    <input type="hidden" name="children" value="<?= (int) $filters['children'] ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($filters['sort']) ?>">
                    <div class="card-header">
                        <div><span class="card-header-icon">tune</span><h3>Filters</h3></div>
                        <a href="<?= root ?>cruises/search" class="text-xs text-blue-700 hover:underline">Reset</a>
                    </div>
                    <div class="card-body space-y-5 text-sm">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Search</label>
                            <input type="text" name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Cruise or ship name" class="input w-full">
                        </div>

                        <?php
                        $groupsUi = [
                            'destination' => ['Destination', ['' => 'Any'] + array_combine($options['destinations'], $options['destinations'])],
                            'line'        => ['Cruise line', ['' => 'Any'] + $options['operators']],
                            'length'      => ['Length', ['any' => 'Any'] + array_map(fn($l) => $l[2], $lengths)],
                        ];
                        foreach ($groupsUi as $field => [$label, $choices]): ?>
                        <div>
                            <div class="text-xs font-semibold text-gray-500 uppercase mb-2"><?= $label ?></div>
                            <div class="space-y-1 max-h-56 overflow-y-auto pr-1">
                                <?php foreach ($choices as $val => $text):
                                    $checked = (string) $filters[$field] === (string) $val || ($val === '' && $filters[$field] === ''); ?>
                                <label class="flex items-center gap-2 cursor-pointer py-0.5">
                                    <input type="radio" name="<?= $field ?>" value="<?= htmlspecialchars((string) $val) ?>" <?= $checked ? 'checked' : '' ?> class="accent-blue-600">
                                    <span class="<?= $checked ? 'font-semibold text-gray-900 dark:text-white' : 'text-gray-700 dark:text-gray-300' ?>"><?= htmlspecialchars($text) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div>
                            <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Departure month</div>
                            <select name="month" class="select w-full">
                                <option value="">Any month</option>
                                <?php foreach ($options['months'] as $ym => $label): ?>
                                <option value="<?= $ym ?>" <?= $filters['month'] === $ym ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Departure port</div>
                            <select name="port" class="select w-full">
                                <option value="">Any port</option>
                                <?php foreach ($options['ports'] as $port): ?>
                                <option value="<?= htmlspecialchars($port) ?>" <?= $filters['port'] === $port ? 'selected' : '' ?>><?= htmlspecialchars($port) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </form>
            </aside>

            <!-- RESULTS -->
            <main class="lg:col-span-3 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-white"><?= $total ?> cruise<?= $total === 1 ? '' : 's' ?> found</h1>
                        <p class="text-xs text-gray-500">Prices per person, based on <?= (int) $filters['adults'] ?> adult<?= $filters['adults'] > 1 ? 's' : '' ?><?= $filters['children'] ? ' + ' . (int) $filters['children'] . ' child' . ($filters['children'] > 1 ? 'ren' : '') : '' ?> sharing a cabin</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-gray-500">Sort by</span>
                        <?php foreach (['date' => 'Departure', 'price' => 'Lowest price', 'price_desc' => 'Highest price', 'nights' => 'Length'] as $s => $lbl): ?>
                        <a href="<?= htmlspecialchars($pageUrl(['sort' => $s, 'page' => null])) ?>"
                           class="px-3 py-1.5 rounded-full border text-xs <?= $filters['sort'] === $s ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-gray-200 text-gray-700 hover:border-blue-500' ?>"><?= $lbl ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (!$results): ?>
                <div class="card p-10 text-center">
                    <span class="material-symbols-outlined text-5xl text-gray-300">directions_boat</span>
                    <h3 class="text-lg font-semibold mt-2">No cruises match your search</h3>
                    <p class="text-sm text-gray-500 mb-4">Try a different month, destination or cruise line.</p>
                    <a href="<?= root ?>cruises/search" class="btn inline-flex">Show all cruises</a>
                </div>
                <?php else: ?>
                    <?php foreach ($results as $c) { include __DIR__ . '/cruise-card.php'; } ?>
                <?php endif; ?>

                <?php if ($pages > 1): ?>
                <nav class="flex justify-center gap-1 pt-2">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <a href="<?= htmlspecialchars($pageUrl(['page' => $i])) ?>"
                       class="w-9 h-9 flex items-center justify-center rounded-lg text-sm <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-200 hover:border-blue-500' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </nav>
                <?php endif; ?>
            </main>
        </div>
    </div>
</div>
