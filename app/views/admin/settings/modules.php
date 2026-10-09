<?php
// Get modules data from database - order is now maintained in DB
$modules = $db->select('modules', '*', [ 'active' => '1' ], [
    'ORDER' => [
        'type' => 'ASC',
        'order' => 'ASC',

    ]
]);

// Organize modules by type
$modulesByType = [];
$totalActive = 0;

foreach ($modules as $module) {
    $type = $module['type'];
    if (!isset($modulesByType[$type])) {
        $modulesByType[$type] = [];
    }

    $modulesByType[$type][] = $module;
    if ($module['status'] == 1) $totalActive++;
}

// Category icons and colors
$categories = [
    'flights' => ['icon' => 'flight', 'color' => '#3b82f6', 'label' => T::flights],
    'stays' => ['icon' => 'hotel', 'color' => '#10b981', 'label' => T::stays],
    'tours' => ['icon' => 'tour', 'color' => '#f59e0b', 'label' => T::tours],
    'cars' => ['icon' => 'directions_car', 'color' => '#8b5cf6', 'label' => T::cars],
    'bus' => ['icon' => 'directions_bus', 'color' => '#f43f5e', 'label' => T::bus ?? 'Bus'],
    'visa' => ['icon' => 'description', 'color' => '#ef4444', 'label' => T::visa],
    'umrah' => ['icon' => 'mosque', 'color' => '#16a34a', 'label' => T::umrah ?? 'Umrah'],
    'ferries' => ['icon' => 'directions_boat', 'color' => '#0ea5e9', 'label' => T::ferries ?? 'Ferries'],
    'rail' => ['icon' => 'directions_railway', 'color' => '#4f46e5', 'label' => 'Rail'],
    'cruises' => ['icon' => 'sailing', 'color' => '#06b6d4', 'label' => T::cruises],
    'esim' => ['icon' => 'sim_card', 'color' => '#0f6fff', 'label' => T::esim ?? 'eSIM'],
    'others' => ['icon' => 'reviews', 'color' => '#00aa6c', 'label' => T::others],
    'extra' => ['icon' => 'apps', 'color' => '#6366f1', 'label' => 'Extra']
];

// Count active modules per category and sort
$categoriesWithCount = [];
foreach ($categories as $key => $cat) {
    if (isset($modulesByType[$key]) && !empty($modulesByType[$key])) {
        $activeCount = count(array_filter($modulesByType[$key], function($m) {
            return $m['status'] == 1;
        }));

        $categoriesWithCount[$key] = [
            'data' => $cat,
            'activeCount' => $activeCount,
            'totalCount' => count($modulesByType[$key])
        ];
    }
}

// Sort categories by active count (descending)
uasort($categoriesWithCount, function($a, $b) {
    if ($b['activeCount'] != $a['activeCount']) {
        return $b['activeCount'] - $a['activeCount']; // Most active first
    }
    return $b['totalCount'] - $a['totalCount']; // Then by total count
});

// Default to the category with the most active modules (first after the sort
// above), unless a hash explicitly selects a category.
$defaultCategory = array_key_first($categoriesWithCount) ?? 'all';
?>

<?php
// ============================================================================
// VIEW  (styles: settings/modules/list-styles.php, classes .am-*)
// ============================================================================
require __DIR__ . '/modules/list-styles.php';

// Categories that have modules; any other hash falls back to the default one.
$moduleCategoryKeys = array_keys($categoriesWithCount);

// Sort: switched-on modules first, then a stable type / order grouping.
usort($modules, function ($a, $b) {
    if ($b['status'] != $a['status']) return intval($b['status']) - intval($a['status']);
    if ($a['type'] !== $b['type']) return strcmp($a['type'], $b['type']);
    return intval($a['order']) - intval($b['order']);
});

// What the toolbar shows for each category (and "all").
$amCatMeta = ['all' => ['label' => T::all . ' ' . mb_strtolower((string) T::modules), 'total' => count($modules), 'active' => $totalActive]];
foreach ($categoriesWithCount as $key => $catData) {
    $amCatMeta[$key] = ['label' => (string) $catData['data']['label'], 'total' => $catData['totalCount'], 'active' => $catData['activeCount']];
}
// Search index: one entry per card, in card order.
$amIndex = array_map(fn($m) => [
    'type' => (string) $m['type'],
    'q'    => mb_strtolower((function_exists('module_display_name') ? module_display_name($m) : $m['name']) . ' ' . $m['name']),
], $modules);

// "percentage 3.000" -> "3%", "fixed 12.500" -> "12.5 USD"
$amMarkup = function ($type, $value, $currency) {
    $num = rtrim(rtrim(number_format((float) ($value ?? 0), 3, '.', ''), '0'), '.');
    return $type === 'fixed' ? $num . ' ' . ($currency ?: 'USD') : $num . '%';
};
?>

<!-- Category filter: CSS hides the other types from the first paint (no flash of
     every module on refresh); Alpine only switches the grid's data-cat. -->
<style>
    #modulesGrid { transition: opacity .16s ease, transform .16s ease; }
    #modulesGrid.is-switching { opacity: 0; transform: translateY(6px); }
    #modulesGrid > .am-card { animation: amCardIn .32s cubic-bezier(.22, 1, .36, 1) both; }
    #modulesGrid.is-switching > .am-card { animation: none; }
    @keyframes amCardIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
    <?php foreach ($moduleCategoryKeys as $catKey): ?>
    #modulesGrid[data-cat="<?= htmlspecialchars($catKey) ?>"] > .am-card:not([data-type="<?= htmlspecialchars($catKey) ?>"]) { display: none; }
    <?php endforeach; ?>
    @media (prefers-reduced-motion: reduce) { #modulesGrid, #modulesGrid > .am-card { transition: none; animation: none; } }
</style>

<div class="am container" x-data="{
    categories: <?= htmlspecialchars(json_encode($moduleCategoryKeys), ENT_QUOTES) ?>,
    meta: <?= htmlspecialchars(json_encode($amCatMeta, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,
    index: <?= htmlspecialchars(json_encode($amIndex, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,
    fallback: '<?= $defaultCategory ?>',
    activeCategory: '<?= $defaultCategory ?>',
    q: '',
    timer: null,
    fromHash() {
        const h = decodeURIComponent(window.location.hash.substring(1));
        return h === 'all' || this.categories.includes(h) ? h : this.fallback;
    },
    init() {
        this.activeCategory = this.fromHash();
        // Back / forward buttons and links that change the hash.
        window.addEventListener('hashchange', () => this.show(this.fromHash()));
    },
    setCategory(category) {
        if (category === this.activeCategory) return;
        // replaceState: no jump to the top and no history entry per click.
        history.replaceState(null, '', window.location.pathname + window.location.search + (category === 'all' ? '' : '#' + category));
        this.show(category);
    },
    show(category) {
        if (category === this.activeCategory) return;
        const grid = this.$refs.grid;
        clearTimeout(this.timer);
        grid.classList.add('is-switching');
        this.timer = setTimeout(() => {
            this.activeCategory = category;
            grid.dataset.cat = category;
            requestAnimationFrame(() => grid.classList.remove('is-switching'));
        }, 160);
    },
    hit(i) { const t = this.q.trim().toLowerCase(); return !t || this.index[i].q.includes(t); },
    shown() {
        return this.index.filter((m, i) => (this.activeCategory === 'all' || m.type === this.activeCategory) && this.hit(i)).length;
    }
}">

    <!-- FLASH MESSAGE -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-top:20px">
            <span class="material-icon material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <p><?= isset($_SESSION['message']['key']) ? constant('T::' . $_SESSION['message']['key']) : $_SESSION['message']['text'] ?></p>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- PAGE HEADER -->
    <div class="am-head">
        <div>
            <p class="am-eyebrow"><?= T::settings ?></p>
            <h1 class="am-title"><?= T::modules ?></h1>
            <p class="am-lead"><?= T::manage_your_travel_service_modules ?></p>
        </div>
        <div class="am-kpis">
            <div class="am-kpi is-live"><span class="am-kpi-label"><?= T::active ?></span><span class="am-kpi-value"><?= $totalActive ?></span></div>
            <div class="am-kpi"><span class="am-kpi-label"><?= T::total ?></span><span class="am-kpi-value"><?= count($modules) ?></span></div>
            <div class="am-kpi"><span class="am-kpi-label"><?= T::categories ?></span><span class="am-kpi-value"><?= count($categoriesWithCount) ?></span></div>
        </div>
    </div>

    <div class="am-layout">

        <!-- CATEGORY RAIL -->
        <nav class="am-rail" aria-label="<?= htmlspecialchars((string) T::categories) ?>">
            <div class="am-rail-head"><span><?= T::categories ?></span><span><?= count($categoriesWithCount) ?></span></div>
            <div class="am-rail-list">
                <?php foreach ($categoriesWithCount as $key => $catData): $cat = $catData['data']; ?>
                    <button type="button" class="am-cat<?= $key === $defaultCategory ? ' is-active' : '' ?>" @click="setCategory('<?= $key ?>')"
                            :class="{ 'is-active': activeCategory === '<?= $key ?>' }" :aria-pressed="activeCategory === '<?= $key ?>' ? 'true' : 'false'">
                        <span class="material-symbols-outlined"><?= $cat['icon'] ?></span>
                        <span class="am-cat-name"><?= $cat['label'] ?></span>
                        <span class="am-count" title="<?= htmlspecialchars(T::active . ' / ' . T::total) ?>"><?= $catData['activeCount'] ?>/<?= $catData['totalCount'] ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </nav>

        <!-- MODULES -->
        <section class="min-w-0">
            <div class="am-bar">
                <div>
                    <h2 class="am-bar-title" x-text="meta[activeCategory]?.label"><?= htmlspecialchars($amCatMeta[$defaultCategory]['label'] ?? '') ?></h2>
                    <p class="am-bar-meta" x-text="`${meta[activeCategory]?.total ?? 0} <?= htmlspecialchars(mb_strtolower((string) T::modules), ENT_QUOTES) ?> · ${meta[activeCategory]?.active ?? 0} <?= htmlspecialchars(mb_strtolower((string) T::active), ENT_QUOTES) ?>`"></p>
                </div>
                <label class="am-find">
                    <span class="material-symbols-outlined">search</span>
                    <input type="search" x-model="q" placeholder="<?= htmlspecialchars((string) T::search) ?>…" aria-label="<?= htmlspecialchars((string) T::search) ?>">
                </label>
            </div>

            <div id="modulesGrid" x-ref="grid" class="am-grid" data-cat="<?= htmlspecialchars($defaultCategory) ?>" x-show="shown() > 0">
                <script>
                // Runs before the cards are parsed: pick the category from the hash now.
                (function () {
                    var cats = <?= json_encode($moduleCategoryKeys) ?>;
                    var h = decodeURIComponent(location.hash.slice(1));
                    if (h === 'all' || cats.indexOf(h) !== -1) document.getElementById('modulesGrid').dataset.cat = h;
                })();
                </script>
                <?php foreach ($modules as $i => $module):
                    if ($module['active'] != 1) continue;
                    $_modLabel = function_exists('module_display_name') ? module_display_name($module) : ucwords(str_replace('_', ' ', $module['name']));
                    $_modImg = function_exists('module_icon_url') ? module_icon_url($module) : null;
                    $_type = strtolower((string) $module['type']);
                    $_on = (string) $module['status'] === '1';
                ?>
                <article class="am-card" data-type="<?= htmlspecialchars($module['type']) ?>" x-show="hit(<?= $i ?>)">
                    <div class="am-card-top">
                        <div class="am-logo">
                            <?php if ($_modImg): ?>
                                <img src="<?= htmlspecialchars($_modImg) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="material-symbols-outlined"><?= htmlspecialchars(preg_match('/^[a-z_]+$/', (string) $module['icon']) ? $module['icon'] : 'extension') ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="am-ident">
                            <h3 class="am-name"><?= htmlspecialchars($_modLabel) ?></h3>
                            <p class="am-type"><?= htmlspecialchars($categories[$_type]['label'] ?? ucfirst($_type)) ?></p>
                        </div>
                        <!-- STATUS TOGGLE (submits at once; the hash keeps the category after the redirect) -->
                        <form method="POST" action="<?= root . admin ?>/settings/modules/toggle#<?= $module['type'] ?>" x-data="{ isSubmitting: false }" @submit="isSubmitting = true">
                            <input type="hidden" name="module_id" value="<?= $module['id'] ?>">
                            <input type="hidden" name="status" value="<?= $_on ? 0 : 1 ?>">
                            <label class="switch-container switch-md switch-blue" title="<?= htmlspecialchars($_on ? T::active : T::inactive) ?>">
                                <input type="checkbox" class="switch-input" <?= $_on ? 'checked' : '' ?> :disabled="isSubmitting" onchange="this.form.submit()" aria-label="<?= htmlspecialchars($_modLabel) ?>">
                                <div class="switch-track"><div class="switch-thumb"></div></div>
                            </label>
                        </form>
                    </div>

                    <span class="am-status<?= $_on ? ' is-on' : '' ?>"><i></i><?= $_on ? T::active : T::inactive ?></span>

                    <dl class="am-facts">
                        <?php if ($_type === 'others'): ?>
                            <div class="am-fact"><dt>Service</dt><dd><?= T::reviews ?></dd></div>
                            <div class="am-fact"><dt><?= T::language ?></dt><dd><?= htmlspecialchars(trim((string) ($module['c2'] ?? '')) ?: 'en') ?></dd></div>
                            <div class="am-fact"><dt><?= T::reviews ?> / page</dt><dd><?= (int) ($module['c3'] ?? 0) > 0 ? min(20, (int) $module['c3']) : 5 ?></dd></div>
                        <?php elseif (strtolower((string) $module['name']) === 'airalo'): ?>
                            <div class="am-fact"><dt>Pricing</dt><dd>Per-package</dd></div>
                            <div class="am-fact"><dt>Countries</dt><dd><?= (int) $db->count('airalo_countries', ['status' => 1]) ?></dd></div>
                            <div class="am-fact"><dt>Rules</dt><dd><?= (int) $db->count('airalo_packages') ?></dd></div>
                        <?php else: ?>
                            <div class="am-fact"><dt><?= T::markup ?> <?= T::b2c ?></dt><dd><?= htmlspecialchars($amMarkup($module['markup_type_b2c'], $module['markup_b2c'], $module['currency'])) ?></dd></div>
                            <div class="am-fact"><dt><?= T::markup ?> <?= T::b2b ?></dt><dd><?= htmlspecialchars($amMarkup($module['markup_type_b2b'], $module['markup_b2b'], $module['currency'])) ?></dd></div>
                            <div class="am-fact"><dt><?= T::currency ?></dt><dd><?= htmlspecialchars($module['currency'] ?: 'USD') ?></dd></div>
                        <?php endif; ?>
                    </dl>

                    <div class="am-actions">
                        <a class="am-btn" href="<?= root . admin . '/settings/modules/' . $module['id'] ?>">
                            <span class="material-symbols-outlined">tune</span><?= T::settings ?>
                        </a>
                        <a class="am-btn is-ghost" href="https://docs.phptravels.com/modules/<?= strtolower($module['type']) ?>/<?= strtolower($module['name']) ?>" target="_blank" rel="noopener">
                            <span class="material-symbols-outlined">description</span><?= T::docs ?>
                        </a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <div class="am-empty" x-show="shown() === 0" style="display:none">
                <span class="material-symbols-outlined">search_off</span>
                <?= T::no_results_found ?>
            </div>
        </section>
    </div>
</div>
