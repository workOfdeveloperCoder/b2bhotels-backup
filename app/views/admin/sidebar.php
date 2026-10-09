<?php
// ADMIN SIDEBAR NAVIGATION COMPONENT
// Only render for admin users
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {

// GET CURRENT PAGE PATH FOR ACTIVE STATE DETECTION
$current_route = $_SERVER['REQUEST_URI'] ?? '';
$current_path = parse_url($current_route, PHP_URL_PATH) ?? '';
$current_path = rtrim($current_path, '/'); // Remove trailing slash for consistent matching

/**
 * CHECK IF MENU ITEM SHOULD BE ACTIVE
 * Returns true if current page matches the menu item or any of its children
 *
 * @param string $url Main menu item URL
 * @param array|null $submenu Array of submenu items to check
 * @return bool True if menu should be marked as active
 */
function isMenuActive($url, $submenu = null) {
    // READ THE PATH HERE: THE SIDEBAR IS INCLUDED INSIDE A ROUTE FUNCTION, SO THE
    // FILE'S $current_path IS LOCAL TO THAT FUNCTION AND `global` NEVER SAW IT.
    $current_path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

    // VALIDATE INPUT
    if (!$current_path || !$url || $url === '#') return false;

    // ONLY MATCH ADMIN PAGES - Prevent homepage false positives
    if (strpos($current_path, '/admin') === false) return false;

    // GET CLEAN PATH FROM URL
    $menu_path = parse_url($url, PHP_URL_PATH);
    if (!$menu_path || $menu_path === '#') return false;
    $menu_path = rtrim($menu_path, '/');

    // CHECK EXACT MATCH OR HIERARCHICAL MATCH (for sub-pages). A parent whose own
    // link is not an admin page (root + '#') matches through its sub-items only.
    if (strpos($menu_path, '/admin') !== false && ($current_path === $menu_path || strpos($current_path, $menu_path . '/') === 0)) {
        return true;
    }

    // CHECK SUBMENU ITEMS RECURSIVELY
    if ($submenu && is_array($submenu)) {
        foreach ($submenu as $sub) {
            // CHECK SUBMENU URL
            if (isset($sub['url']) && $sub['url'] && $sub['url'] !== '#') {
                $sub_path = parse_url($sub['url'], PHP_URL_PATH);
                if ($sub_path) {
                    $sub_path = rtrim($sub_path, '/');
                    if ($current_path === $sub_path || strpos($current_path, $sub_path . '/') === 0) {
                        return true;
                    }
                }
            }

            // CHECK NESTED SUBMENU ITEMS (3rd level)
            if (isset($sub['nested']) && is_array($sub['nested'])) {
                foreach ($sub['nested'] as $nested) {
                    if (isset($nested['url']) && $nested['url'] && $nested['url'] !== '#') {
                        $nested_path = parse_url($nested['url'], PHP_URL_PATH);
                        if ($nested_path) {
                            $nested_path = rtrim($nested_path, '/');
                            if ($current_path === $nested_path || strpos($current_path, $nested_path . '/') === 0) {
                                return true;
                            }
                        }
                    }
                }
            }
        }
    }
    return false;
}

// FETCH USER ROLES FROM DATABASE
global $db;
$userRoles = $db->select('users_roles', ['id', 'type_name'], ['ORDER' => ['id' => 'ASC']]);

// BUILD USERS SUBMENU - Single level with all users, then role types
$usersSubmenu = [
    ['name' => T::all_users, 'icon' => 'people', 'url' => root.admin.'/users'],
];

// ADD EACH USER ROLE DYNAMICALLY
foreach ($userRoles as $role) {
    $roleSlug = strtolower(str_replace(' ', '-', $role['type_name']));
    $usersSubmenu[] = [
        // the agent role opens the Agents page (agencies as cards), so it is named for that page
        'name' => $roleSlug === 'agent' ? 'Agents' : $role['type_name'],
        'icon' => 'badge',
        'url' => root.admin.'/users?search_col=role&q='.$roleSlug
    ];
}
// NEWSLETTER: the people who signed up with the footer form
$usersSubmenu[] = ['name' => T::newsletter, 'icon' => 'mark_email_read', 'url' => root.admin.'/newsletter'];

// CHECK MODULE STATUS - Determine which modules are enabled
$flights = $db->count('modules', ['type' => 'flights', 'status' => 1, 'active' => 1]);
// Manual flights = the parent "flights" module row (not API suppliers like TBO).
// It alone controls the Flights management menu (manual flights, airlines,
// airports); an API-only client (e.g. TBO) must not see it.
$manualFlights = $db->count('modules', ['name' => 'flights', 'type' => 'flights', 'status' => 1, 'active' => 1]);
$stays = $db->count('modules', ['type' => 'stays', 'status' => 1, 'active' => 1]);
$tours = $db->count('modules', ['type' => 'tours', 'status' => 1, 'active' => 1]);
$visa_count = $db->count('modules', ['type' => 'visa', 'status' => 1, 'active' => 1]);
$cars = $db->count('modules', ['type' => 'cars', 'status' => 1, 'active' => 1]);
$umrah_count = $db->count('modules', ['type' => 'umrah', 'status' => 1, 'active' => 1]);
$bus_count = $db->count('modules', ['type' => 'bus', 'status' => 1, 'active' => 1]);
$rail_count = $db->count('modules', ['type' => 'rail', 'status' => 1, 'active' => 1]);
$ferries_count = $db->count('modules', ['type' => 'ferries', 'status' => 1, 'active' => 1]);
$esim_count = $db->count('modules', ['type' => 'esim', 'status' => 1, 'active' => 1]);

// ADD DIVIDER AND USER ROLES MANAGEMENT PAGE
$usersSubmenu[] = ['divider' => true];
$usersSubmenu[] = [];

// BUILD BOOKINGS SUBMENU - Dynamically based on enabled modules
$bookingsSubmenu = [
  ['name' => T::all . ' ' . T::bookings, 'icon' => 'library_books', 'url' => root.admin.'/bookings']
];

// ADD FLIGHTS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($flights > 0) {
  $bookingsSubmenu[] = ['name' => T::flights . ' ' . T::bookings, 'icon' => 'flight_takeoff', 'url' => root.admin.'/bookings/flights'];
}

// ADD STAYS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($stays > 0) {
  $bookingsSubmenu[] = ['name' => T::stays . ' ' . T::bookings, 'icon' => 'hotel', 'url' => root.admin.'/bookings/stays'];
}

// ADD TOURS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($tours > 0) {
  $bookingsSubmenu[] = ['name' => T::tours . ' ' . T::bookings, 'icon' => 'tour', 'url' => root.admin.'/bookings/tours'];
}

// ADD VISAS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($visa_count > 0) {
  $bookingsSubmenu[] = ['name' => T::visa . ' ' . T::bookings, 'icon' => 'assignment', 'url' => root.admin.'/bookings/visa'];
}

// ADD CARS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($cars > 0) {
  $bookingsSubmenu[] = ['name' => T::cars . ' ' . T::bookings, 'icon' => 'directions_car', 'url' => root.admin.'/bookings/cars'];
}

// ADD UMRAH BOOKINGS ONLY IF MODULE IS ACTIVE
if ($umrah_count > 0) {
  $bookingsSubmenu[] = ['name' => (T::umrah ?? 'Umrah') . ' ' . T::bookings, 'icon' => 'mosque', 'url' => root.admin.'/bookings/umrah'];
}

// ADD BUS BOOKINGS ONLY IF MODULE IS ACTIVE
if ($bus_count > 0) {
  $bookingsSubmenu[] = ['name' => (T::bus ?? 'Bus') . ' ' . T::bookings, 'icon' => 'directions_bus', 'url' => root.admin.'/bookings/bus'];
}

// ADD RAIL BOOKINGS ONLY IF MODULE IS ACTIVE
if ($rail_count > 0) {
  $bookingsSubmenu[] = ['name' => (T::rail ?? 'Rail') . ' ' . T::bookings, 'icon' => 'directions_railway', 'url' => root.admin.'/bookings/rail'];
}

// ADD FERRIES BOOKINGS ONLY IF MODULE IS ACTIVE
if ($ferries_count > 0) {
  $bookingsSubmenu[] = ['name' => (T::ferries ?? 'Ferries') . ' ' . T::bookings, 'icon' => 'directions_boat', 'url' => root.admin.'/bookings/ferries'];
}

// ADD ESIM BOOKINGS ONLY IF MODULE IS ACTIVE
if ($esim_count > 0) {
  $bookingsSubmenu[] = ['name' => (T::esim ?? 'eSIM') . ' ' . T::bookings, 'icon' => 'sim_card', 'url' => root.admin.'/bookings/esim'];
}

// INITIALIZE ADMIN MENU STRUCTURE - Start with core menu items
$adminMenu = [
  [
    'icon' => 'library_books',
    'name' => T::bookings,
    'url' => root.admin.'/bookings',
    'submenu' => $bookingsSubmenu
  ],
  [
    'icon' => 'group',
    'name' => T::users,
    'url' => root.admin.'/users',
    'submenu' => $usersSubmenu
  ],
];

// ADD STAYS MENU ONLY IF MODULE IS ACTIVE
if ($stays > 0) {
    $adminMenu[] = [
        'icon' => 'hotel',
        'name' => T::stays,
        'url' => root.admin.'/stays',
        'submenu' => [
            ['name' => T::stays, 'icon' => 'hotel', 'url' => root.admin.'/stays'],
            ['name' => T::stays_settings, 'icon' => 'settings', 'url' => root.admin.'/stays/settings']
        ]
    ];
}

// ADD FLIGHTS MENU ONLY IF THE MANUAL FLIGHTS MODULE IS ACTIVE
if ($manualFlights > 0) {
    $adminMenu[] = [
        'icon' => 'flight_takeoff',
        'name' => T::flights,
        'url' => root.admin.'/flights',
        'submenu' => [
            ['name' => T::flights, 'icon' => 'flight_takeoff', 'url' => root.admin.'/flights'],
            ['name' => T::airlines, 'icon' => 'airlines', 'url' => root.admin.'/flights-airlines'],
            ['name' => T::airports, 'icon' => 'airplane_ticket', 'url' => root.admin.'/flights-airports']
        ]
    ];
}

// ADD TOURS MENU ONLY IF MODULE IS ACTIVE
if ($tours > 0) {
    $adminMenu[] = [
        'icon' => 'tour',
        'name' => T::tours,
        'url' => root.admin.'/tours',
        'submenu' => [
            ['name' => T::tours, 'icon' => 'tour', 'url' => root.admin.'/tours'],
            ['name' => T::tours_settings, 'icon' => 'settings', 'url' => root.admin.'/tours/settings']
        ]
    ];
}

// ADD UMRAH MENU ONLY IF MODULE IS ACTIVE
if ($umrah_count > 0) {
    $adminMenu[] = [
        'icon' => 'mosque',
        'name' => T::umrah ?? 'Umrah',
        'url' => root.admin.'/umrah',
        'submenu' => [
            ['name' => T::umrah ?? 'Umrah', 'icon' => 'mosque', 'url' => root.admin.'/umrah'],
            ['name' => T::umrah_settings ?? 'Umrah Settings', 'icon' => 'settings', 'url' => root.admin.'/umrah/settings']
        ]
    ];
}

// ADD VISAS MENU ONLY IF MODULE IS ACTIVE
if ($visa_count > 0) {
    $adminMenu[] = [
        'icon' => 'assignment',
        'name' => T::visa,
        'url' => root.admin.'/visa',
        'submenu' => [
            ['name' => T::visa, 'icon' => 'assignment', 'url' => root.admin.'/visa'],
            ['name' => T::visa_settings ?? 'Visa Settings', 'icon' => 'settings', 'url' => root.admin.'/visa/settings']
        ]
    ];
}

// ADD CARS MENU ONLY IF MODULE IS ACTIVE
if ($cars > 0) {
    $adminMenu[] = [
        'icon' => 'directions_car',
        'name' => T::cars,
        'url' => root.admin.'/cars',
        'submenu' => [
            ['name' => T::cars, 'icon' => 'directions_car', 'url' => root.admin.'/cars'],
            ['name' => T::cars_settings, 'icon' => 'settings', 'url' => root.admin.'/cars/settings']
        ]
    ];
}

// ADD BUS MENU ONLY IF MODULE IS ACTIVE
if ($bus_count > 0) {
    $adminMenu[] = [
        'icon' => 'directions_bus',
        'name' => T::bus ?? 'Bus',
        'url' => root.admin.'/bus',
        'submenu' => [
            ['name' => T::buses ?? 'Buses', 'icon' => 'directions_bus', 'url' => root.admin.'/bus'],
            ['name' => T::operators ?? 'Operators', 'icon' => 'badge', 'url' => root.admin.'/bus/operators']
        ]
    ];
}

// CONTINUE BUILDING MENU - Add remaining static menu items
$adminMenu = array_merge($adminMenu, [
    [
        'icon' => 'folder', 'name' => T::pages, 'url' => root.'#',
        'submenu' => [
            ['name' => T::pages, 'icon' => 'article', 'url' => root.admin.'/cms/pages'],
            ['name' => T::menus, 'icon' => 'dashboard', 'url' => root.admin.'/cms/menus']
        ]
    ],
    [
        'icon' => 'book', 'name' => T::blogs, 'url' => root.'#',
        'submenu' => [
            ['name' => T::blogs, 'icon' => 'newspaper', 'url' => root.admin.'/blogs'],
            ['name' => T::blog_categories, 'icon' => 'category', 'url' => root.admin.'/blogs/categories']
        ]
    ],
    // ['icon' => 'bar_chart', 'name' => T::analytics, 'url' => root.admin.'/analytics'],
    [
        'icon' => 'settings', 'name' => T::settings, 'url' => root.admin.'/settings',
        'submenu' => [
            [
                'name' => T::general_settings,
                'icon' => 'tune',
                'has_submenu' => true,
                'nested' => [
                    ['name' => T::general, 'icon' => 'settings', 'url' => root.admin.'/settings#general'],
                    ['name' => T::seo, 'icon' => 'search', 'url' => root.admin.'/settings#seo'],
                    ['name' => T::branding, 'icon' => 'palette', 'url' => root.admin.'/settings#branding'],
                    ['name' => T::themes, 'icon' => 'format_paint', 'url' => root.admin.'/settings#themes'],
                    ['name' => T::accounts, 'icon' => 'account_circle', 'url' => root.admin.'/settings#accounts'],
                    ['name' => T::contact, 'icon' => 'contact_mail', 'url' => root.admin.'/settings#contact'],
                    ['name' => T::notifications, 'icon' => 'notifications', 'url' => root.admin.'/settings#notifications'],
                    ['name' => T::social_media, 'icon' => 'share', 'url' => root.admin.'/settings#social'],
                    ['name' => T::apps, 'icon' => 'apps', 'url' => root.admin.'/settings#apps'],
                    ['name' => T::tracking, 'icon' => 'analytics', 'url' => root.admin.'/settings#tracking'],
                    ['name' => T::booking, 'icon' => 'analytics', 'url' => root.admin.'/settings#booking'],
                    ...(plan_allows('ai_trip_planner') ? [['name' => (T::ai ?? 'AI'), 'icon' => 'smart_toy', 'url' => root.admin.'/settings#ai']] : []),
                ]
            ],
            ['name' => (T::mobile_app ?? 'Mobile App'), 'icon' => 'smartphone', 'url' => root.admin.'/settings/app'],
            ['name' => T::modules, 'icon' => 'extension', 'url' => root.admin.'/settings/modules'],
            ['name' => T::payment_gateways, 'icon' => 'payment', 'url' => root.admin.'/settings/gateways'],
            // ['name' => T::users_roles, 'icon' => 'admin_panel_settings', 'url' => root.admin.'/users/roles'],
            ['name' => T::languages, 'icon' => 'translate', 'url' => root.admin.'/settings/languages'],
            ['name' => T::currencies, 'icon' => 'currency_exchange', 'url' => root.admin.'/settings/currencies'],
            ['name' => T::countries, 'icon' => 'map', 'url' => root.admin.'/settings/countries'],
            ['name' => T::locations, 'icon' => 'japanese_flag', 'url' => root.admin.'/settings/locations'],
            ...(plan_allows('lifetime_updates') ? [['name' => T::updates, 'icon' => 'refresh', 'url' => root.'updates', 'target' => '_blank']] : []),
            ['name' => (T::database_update ?? 'Database Update'), 'icon' => 'database', 'url' => root.admin.'/updates/database'],
            ['name' => T::database, 'icon' => 'database', 'url' => root.admin.'/database'],
            ['name' => T::system_info, 'icon' => 'info', 'url' => root.admin.'/settings/system-info']
        ]
    ],
    [
        'icon' => 'finance', 'name' => T::finance, 'url' => root.admin.'/finance',
        'dot' => ($db->count('deposit', ['status' => 'pending']) > 0),
        'submenu' => [
            ['name' => T::credits, 'icon' => 'tune', 'url' => root.admin.'/finance/credits'],
            ['name' => T::deposits, 'icon' => 'account_balance_wallet', 'url' => root.admin.'/finance/deposit', 'badge' => $db->count('deposit', ['status' => 'pending'])],
            ['name' => T::transactions, 'icon' => 'receipt_long', 'url' => root.admin.'/finance/transactions'],
            ['name' => T::promo_codes, 'icon' => 'confirmation_number', 'url' => root.admin.'/promo-codes'],

            // ['name' => T::invoices, 'icon' => 'description', 'url' => root.admin.'/finance/invoices'],
            // ['name' => T::payments, 'icon' => 'payment', 'url' => root.admin.'/finance/payments'],
        ]
    ],
    [
        'icon' => 'analytics', 'name' => T::reports, 'url' => root.'#',
        'submenu' => [
            ['name' => T::booking.' '.T::logs, 'icon' => 'history', 'url' => root.admin.'/reports/booking-logs'],
            ['name' => T::search. ' '.T::logs, 'icon' => 'search', 'url' => root.admin.'/reports/search-logs'],
            ['name' => T::webhook.' '.T::logs, 'icon' => 'webhook', 'url' => root.admin.'/reports/webhook-logs'],
            ['name' => T::booking.' '.T::reports, 'icon' => 'assessment', 'url' => root.admin.'/reports/bookings'],
            ['name' => T::users.' '.T::reports, 'icon' => 'people_outline', 'url' => root.admin.'/reports/users'],
            ['name' => T::transactions.' '.T::reports, 'icon' => 'receipt_long', 'url' => root.admin.'/reports/transactions'],
            ['name' => T::deposit.' '.T::reports, 'icon' => 'receipt_long', 'url' => root.admin.'/reports/deposit']
        ]
    ],
    [
        'icon' => 'support_agent', 'name' => T::support, 'url' => root.'#',
        'submenu' => [
            ['name' => T::support_tickets, 'icon' => 'confirmation_number', 'url' => root.admin.'/support/tickets']
        ]
    ]
]);

// GROUP THE MENU (design system §47.7: spaced-caps group labels). Keyed by the
// item's icon, which is unique per top-level item in this file; order = rank.
$asGroups = [
    'operations' => T::ad_operations,
    'inventory'  => T::ad_inventory,
    'content'    => T::content,
    'system'     => T::system,
];
$asRank = [
    'library_books' => ['operations', 1], 'group' => ['operations', 2], 'finance' => ['operations', 3], 'support_agent' => ['operations', 4],
    'hotel' => ['inventory', 1], 'flight_takeoff' => ['inventory', 2], 'tour' => ['inventory', 3], 'mosque' => ['inventory', 4],
    'assignment' => ['inventory', 5], 'directions_car' => ['inventory', 6], 'directions_bus' => ['inventory', 7],
    'folder' => ['content', 1], 'book' => ['content', 2],
    'analytics' => ['system', 1], 'settings' => ['system', 2],
];
$asGroupOrder = array_flip(array_keys($asGroups));
foreach ($adminMenu as &$asItem) {
    [$asItem['group'], $asItem['rank']] = $asRank[$asItem['icon']] ?? ['system', 9];
}
unset($asItem);
usort($adminMenu, fn($a, $b) => [$asGroupOrder[$a['group']], $a['rank']] <=> [$asGroupOrder[$b['group']], $b['rank']]);

// PRE-CALCULATE WHICH MENUS SHOULD BE EXPANDED BASED ON CURRENT URL
// IMPORTANT: This MUST be after all adminMenu items are added (including conditional modules)
$expandedMenus = [];

foreach ($adminMenu as $index => $item) {
    $shouldExpand = false;

    // CHECK IF CURRENT PAGE MATCHES ANY SUBMENU ITEM
    if (isset($item['submenu']) && is_array($item['submenu'])) {
        foreach ($item['submenu'] as $sub) {
            // CHECK DIRECT SUBMENU URL
            if (isset($sub['url']) && $sub['url'] && $sub['url'] !== '#') {
                $sub_path = parse_url($sub['url'], PHP_URL_PATH);
                if ($sub_path) {
                    $sub_path = rtrim($sub_path, '/');
                    // EXACT MATCH or HIERARCHICAL MATCH (starts with path)
                    if ($current_path === $sub_path || strpos($current_path, $sub_path . '/') === 0) {
                        $shouldExpand = true;
                        break;
                    }
                }
            }

            // CHECK NESTED ITEMS (3rd level menus)
            if (isset($sub['nested']) && is_array($sub['nested'])) {
                foreach ($sub['nested'] as $nested) {
                    if (isset($nested['url']) && $nested['url'] && $nested['url'] !== '#') {
                        $nested_path = parse_url($nested['url'], PHP_URL_PATH);
                        if ($nested_path) {
                            $nested_path = rtrim($nested_path, '/');
                            // EXACT MATCH or HIERARCHICAL MATCH
                            if ($current_path === $nested_path || strpos($current_path, $nested_path . '/') === 0) {
                                $shouldExpand = true;
                                break 2; // Break out of both nested loops
                            }
                        }
                    }
                }
            }
        }
    }

    // ADD TO EXPANDED MENUS ARRAY IF MATCH FOUND
    if ($shouldExpand) {
        $expandedMenus[$index] = true;
    }
}

// Ensure proper JSON encoding for JavaScript
$expandedMenusJson = json_encode($expandedMenus, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
$adminMenuJson = json_encode($adminMenu, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);

// FETCH RECENT BOOKINGS FOR NOTIFICATIONS (exclude AI trip child invoices only:
// AITC + exactly 8 chars — parents are AIT + 10 hex and can start with AITC)
$recentNotifBookings = $db->select("bookings", [
    "id", "invoice_id", "module_type", "first_name", "last_name", "booking_date", "created_at", "booking_status"
], [
    "invoice_id[!~]" => "AITC________",
    "ORDER" => ["created_at" => "DESC"],
    "LIMIT" => 5
]) ?: [];
$notifCount = count($recentNotifBookings);
?>

<!-- ADMIN SIDEBAR WRAPPER
     LOADING MANAGEMENT: STATE LIVES IN THE sidebarOpen COOKIE SO THE SERVER
     RENDERS THE CORRECT BODY OFFSET AND RAIL WIDTH ON FIRST PAINT. THE RAIL
     SHOWS A CENTERED SPINNER, AND THE MENU CONTENT FADES IN AS SOON AS THE
     ICON FONT IS READY — NOT AT FULL PAGE LOAD. -->
<?php $sidebarExpanded = (($_COOKIE['pt_sidebar'] ?? '') === 'expanded'); ?>
<div class="as-wrap" x-data='{
    "sidebarOpen": <?= $sidebarExpanded ? "true" : "false" ?>,
    "hoverOpen": false,
    "mobileOpen": false,
    "notifOpen": false,
    "userMenuOpen": false,
    "ready": false,
    "switching": false,
    "openMenus": <?= $expandedMenusJson ?>,
    "menuItems": <?= $adminMenuJson ?>,
    "searchQuery": "",
    "currentHash": window.location.hash || "#general",
    "highlightText": function(text, query) {
        if (!query) return text;
        const escapedQuery = query.replace(/[-\/\\^$*+?.()|[\]{}]/g, "\\$&");
        const regex = new RegExp("(" + escapedQuery + ")", "gi");
        return text.replace(regex, `<mark>$1</mark>`);
    },
    "isMobile": function() { return window.innerWidth < 1024 },
    "isExpanded": function() { return this.isMobile() || (this.sidebarOpen || this.hoverOpen) },
    "init": function() {
        this.$nextTick(() => { if (this.$refs.panel) this.$refs.panel.removeAttribute("style") });
        const go = () => { this.ready = true };
        if (document.fonts && document.fonts.load) {
            Promise.race([
                document.fonts.load(`20px "Material Symbols Outlined"`),
                new Promise(res => setTimeout(res, 2500))
            ]).then(go, go);
        } else {
            setTimeout(go, 800);
        }
        if (window.innerWidth < 1024) {
            this.sidebarOpen = false;
            document.body.classList.remove("sidebar-open", "sidebar-closed");
        }
    },
    "clipPulse": function() {
        this.switching = true;
        clearTimeout(this._swT);
        this._swT = setTimeout(() => { this.switching = false }, 260);
    },
    "toggleSidebar": function() {
        const isDesktop = window.innerWidth >= 1024;
        if (isDesktop) {
            this.clipPulse();
            this.sidebarOpen = !this.sidebarOpen;
            document.cookie = "pt_sidebar=" + (this.sidebarOpen ? "expanded" : "collapsed") + "; path=/; max-age=31536000; SameSite=Lax";
            document.body.classList.toggle("sidebar-open", this.sidebarOpen);
            document.body.classList.toggle("sidebar-closed", !this.sidebarOpen);
            this.hoverOpen = false;
        } else {
            this.mobileOpen = !this.mobileOpen;
        }
    },
    "toggleMenu": function(index) {
        this.openMenus[index] = !this.openMenus[index];
    },
    "isMenuOpen": function(index) {
        if (this.searchQuery) {
            const item = this.menuItems[index];
            if (item && item.submenu && Array.isArray(item.submenu)) {
                const query = this.searchQuery.toLowerCase();
                for (const sub of item.submenu) {
                    if (sub.name && sub.name.toLowerCase().includes(query)) return true;
                    if (sub.nested && Array.isArray(sub.nested)) {
                        for (const nested of sub.nested) {
                            if (nested.name && nested.name.toLowerCase().includes(query)) return true;
                        }
                    }
                }
            }
        }
        return this.openMenus[index] === true;
    },
    "itemMatches": function(item) {
        if (!this.searchQuery) return true;
        if (!item) return false;
        const query = this.searchQuery.toLowerCase();
        if (item.name && item.name.toLowerCase().includes(query)) return true;
        if (item.submenu && Array.isArray(item.submenu)) {
            for (const sub of item.submenu) {
                if (sub.name && sub.name.toLowerCase().includes(query)) return true;
                if (sub.nested && Array.isArray(sub.nested)) {
                    for (const nested of sub.nested) {
                        if (nested.name && nested.name.toLowerCase().includes(query)) return true;
                    }
                }
            }
        }
        return false;
    },
    "subMatches": function(sub, parentItem) {
        if (!this.searchQuery) return true;
        if (!sub) return false;
        const query = this.searchQuery.toLowerCase();
        if (parentItem && parentItem.name && parentItem.name.toLowerCase().includes(query)) return true;
        if (sub.name && sub.name.toLowerCase().includes(query)) return true;
        if (sub.nested && Array.isArray(sub.nested)) {
            for (const nested of sub.nested) {
                if (nested.name && nested.name.toLowerCase().includes(query)) return true;
            }
        }
        return false;
    },
    "nestedMatches": function(nested, subItem, parentItem) {
        if (!this.searchQuery) return true;
        if (!nested) return false;
        const query = this.searchQuery.toLowerCase();
        if (parentItem && parentItem.name && parentItem.name.toLowerCase().includes(query)) return true;
        if (subItem && subItem.name && subItem.name.toLowerCase().includes(query)) return true;
        return !!(nested.name && nested.name.toLowerCase().includes(query));
    }
}'
     @keydown.escape.window="mobileOpen = false"
     @hashchange.window="currentHash = window.location.hash || '#general'">

    <?php require __DIR__ . '/sidebar/styles.php'; ?>

    <!-- PHONES: BACKDROP + MENU BUTTON -->
    <div x-show="mobileOpen" x-transition.opacity @click="mobileOpen = false" class="as-backdrop lg:hidden" style="display:none"></div>
    <button type="button" @click="toggleSidebar()" class="as-burger lg:hidden" aria-label="<?= htmlspecialchars((string) T::menu) ?>">
        <span class="material-symbols-outlined">menu</span>
    </button>

    <!-- SIDEBAR PANEL — THE INLINE STYLE PAINTS THE RAIL AT ITS SERVER-KNOWN WIDTH
         BEFORE TAILWIND / ALPINE LOAD; ALPINE REMOVES IT IN init() -->
    <aside @mouseenter="if(!sidebarOpen && !isMobile()) { hoverOpen = true; clipPulse() }"
           @mouseleave="if(!sidebarOpen) clipPulse(); hoverOpen = false"
           x-ref="panel"
           style="position:fixed;top:0;left:0;bottom:0;z-index:100;width:<?= $sidebarExpanded ? 260 : 64 ?>px;background:#0b0f19;border-right:1px solid #232c40;overflow:hidden"
           class="as<?= $sidebarExpanded ? ' is-wide' : '' ?>"
           :class="{ 'is-wide': isExpanded(), 'flex': mobileOpen, 'hidden': !mobileOpen, 'lg:flex': !mobileOpen }"
           aria-label="<?= htmlspecialchars((string) T::dashboard) ?>">

        <!-- LOADER UNTIL THE ICON FONT IS READY -->
        <div x-show="!ready" x-transition.opacity class="as-spin">
            <svg class="animate-spin" width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#232c40" stroke-width="1.5"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke="var(--as-accent)" stroke-width="1.5" stroke-linecap="round"></path></svg>
        </div>

        <!-- HEAD -->
        <div class="as-head as-fade" :class="ready && 'is-ready'">
            <a href="<?= root . admin ?>/dashboard" class="as-brand" x-show="isExpanded()">
                <span class="as-brand-tile"><span class="material-symbols-outlined">dashboard</span></span>
                <span><?= T::dashboard ?></span>
            </a>
            <button type="button" @click="toggleSidebar()" class="as-icon-btn"
                    :aria-label="isExpanded() ? '<?= htmlspecialchars((string) T::ad_collapse, ENT_QUOTES) ?>' : '<?= htmlspecialchars((string) T::ad_expand, ENT_QUOTES) ?>'">
                <svg x-show="isExpanded()" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3"/><path d="M15 3v18"/><path d="m10 15-3-3 3-3"/></svg>
                <svg x-show="!isExpanded()" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3"/><path d="M15 3v18"/><path d="m8 9 3 3-3 3"/></svg>
            </button>
        </div>

        <!-- SEARCH -->
        <div class="as-search as-fade" :class="ready && 'is-ready'">
            <div x-show="isExpanded()" class="as-search-field">
                <span class="material-symbols-outlined">search</span>
                <input type="text" x-model="searchQuery" x-ref="searchInput" placeholder="<?= htmlspecialchars((string) T::ad_search_menu) ?>" aria-label="<?= htmlspecialchars((string) T::ad_search_menu) ?>">
                <button type="button" x-show="searchQuery" @click="searchQuery = ''" class="as-search-clear" aria-label="<?= htmlspecialchars((string) T::clear) ?>">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <button type="button" x-show="!isExpanded()" @click="toggleSidebar(); $nextTick(() => $refs.searchInput.focus())" class="as-icon-btn" aria-label="<?= htmlspecialchars((string) T::ad_search_menu) ?>">
                <span class="material-symbols-outlined" style="font-size:20px">search</span>
            </button>
        </div>

        <!-- MENU -->
        <nav class="as-nav as-fade" :class="ready && 'is-ready'">
            <?php
            // OPEN ONE .as-group PER GROUP; ITS LABEL HIDES WHILE SEARCHING OR IN THE RAIL
            $asOpenGroup = null;
            foreach ($adminMenu as $index => $item):
                if ($item['group'] !== $asOpenGroup):
                    if ($asOpenGroup !== null) echo '</div>';
                    $asOpenGroup = $item['group'];
            ?>
            <div class="as-group">
                <div class="as-label" x-show="isExpanded() && !searchQuery"><?= htmlspecialchars((string) $asGroups[$asOpenGroup]) ?></div>
                <div class="as-sep" x-show="!isExpanded()"></div>
            <?php endif; ?>
            <?php
                $menu_path = parse_url($item['url'] ?? '#', PHP_URL_PATH);
                $menu_path = $menu_path ? rtrim($menu_path, '/') : '';
                $isDirectActive = $menu_path && strpos($current_path, '/admin') !== false && ($current_path === $menu_path);
                $isActive = isMenuActive($item['url'] ?? '#', $item['submenu'] ?? null);
            ?>

            <?php if (!empty($item['submenu'])): ?>
                <!-- GROUP ITEM WITH SUB-ITEMS -->
                <div x-show="itemMatches(menuItems[<?= $index ?>])">
                    <button type="button" @click="isExpanded() ? toggleMenu(<?= $index ?>) : toggleSidebar()"
                            class="as-row<?= $isActive ? ' is-current' : '' ?>"
                            :aria-expanded="isMenuOpen(<?= $index ?>) ? 'true' : 'false'"
                            title="<?= htmlspecialchars((string) $item['name']) ?>">
                        <span class="as-ico">
                            <span class="material-symbols-outlined"><?= $item['icon'] ?></span>
                            <?php if (!empty($item['dot'])): ?><span class="as-dot"></span><?php endif; ?>
                        </span>
                        <span x-show="isExpanded()" class="as-name" x-html="highlightText(menuItems[<?= $index ?>].name, searchQuery)"><?= $item['name'] ?></span>
                        <svg x-show="isExpanded()" class="as-chev" :class="isMenuOpen(<?= $index ?>) && 'is-open'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>

                    <div x-show="isMenuOpen(<?= $index ?>) && isExpanded()" x-collapse class="as-sub">
                        <?php foreach ($item['submenu'] as $subindex => $subitem): ?>
                            <?php if (!empty($subitem['divider'])): ?>
                                <div class="as-sub-sep" x-show="!searchQuery"></div>
                            <?php elseif (!empty($subitem['has_submenu'])): ?>
                                <?php
                                    $isNestedParentActive = false;
                                    foreach ($subitem['nested'] ?? [] as $nested) {
                                        $nested_check_path = parse_url($nested['url'] ?? '', PHP_URL_PATH);
                                        if ($nested_check_path && strpos($current_path, '/admin') !== false && $current_path === rtrim($nested_check_path, '/')) {
                                            $isNestedParentActive = true;
                                            break;
                                        }
                                    }
                                ?>
                                <!-- THIRD LEVEL -->
                                <div x-data="{
                                        localNestedOpen: <?= $isNestedParentActive ? 'true' : 'false' ?>,
                                        isNestedOpen() {
                                            if (searchQuery) {
                                                const query = searchQuery.toLowerCase();
                                                for (const nested of (menuItems[<?= $index ?>].submenu[<?= $subindex ?>].nested || [])) {
                                                    if (nested.name && nested.name.toLowerCase().includes(query)) return true;
                                                }
                                            }
                                            return this.localNestedOpen;
                                        }
                                     }" x-show="subMatches(menuItems[<?= $index ?>].submenu[<?= $subindex ?>], menuItems[<?= $index ?>])">
                                    <button type="button" @click="localNestedOpen = !localNestedOpen"
                                            class="as-subrow<?= $isNestedParentActive ? ' is-open-parent' : '' ?>"
                                            :aria-expanded="isNestedOpen() ? 'true' : 'false'">
                                        <span class="as-name" x-html="highlightText(menuItems[<?= $index ?>].submenu[<?= $subindex ?>].name, searchQuery)"><?= $subitem['name'] ?></span>
                                        <svg class="as-chev" :class="isNestedOpen() && 'is-open'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                    <div x-show="isNestedOpen()" x-collapse class="as-deep">
                                        <?php foreach ($subitem['nested'] ?? [] as $nestedIndex => $nestedItem): ?>
                                            <?php
                                                $nested_path = parse_url($nestedItem['url'], PHP_URL_PATH);
                                                $fragment = parse_url($nestedItem['url'], PHP_URL_FRAGMENT) ?? '';
                                                $isNestedActive = $nested_path && strpos($current_path, '/admin') !== false && $current_path === rtrim($nested_path, '/');
                                            ?>
                                            <a href="<?= $nestedItem['url'] ?>" class="as-subrow is-deep"
                                               :class="(<?= $isNestedActive ? 'true' : 'false' ?> && currentHash === '#<?= $fragment ?>') && 'is-active'"
                                               x-show="nestedMatches(menuItems[<?= $index ?>].submenu[<?= $subindex ?>].nested[<?= $nestedIndex ?>], menuItems[<?= $index ?>].submenu[<?= $subindex ?>], menuItems[<?= $index ?>])">
                                                <span class="as-name" x-html="highlightText(menuItems[<?= $index ?>].submenu[<?= $subindex ?>].nested[<?= $nestedIndex ?>].name, searchQuery)"><?= $nestedItem['name'] ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php elseif (!empty($subitem['url'])): ?>
                                <?php
                                    // ACTIVE = SAME PATH AND, WHEN THE LINK HAS A QUERY, THE SAME QUERY VALUES
                                    $isSubitemActive = false;
                                    $subitem_path = parse_url($subitem['url'], PHP_URL_PATH);
                                    $subitem_query = parse_url($subitem['url'], PHP_URL_QUERY);
                                    if ($subitem_path && strpos($current_path, '/admin') !== false && $current_path === rtrim($subitem_path, '/')) {
                                        $current_params = [];
                                        parse_str($_SERVER['QUERY_STRING'] ?? '', $current_params);
                                        unset($current_params['url']);
                                        $subitem_params = [];
                                        if ($subitem_query) parse_str($subitem_query, $subitem_params);
                                        if ($subitem_params) {
                                            $isSubitemActive = true;
                                            foreach ($subitem_params as $key => $val) {
                                                if (($current_params[$key] ?? null) !== $val) { $isSubitemActive = false; break; }
                                            }
                                        } else {
                                            $isSubitemActive = ($current_params['search_col'] ?? '') !== 'role';
                                        }
                                    }
                                ?>
                                <a href="<?= $subitem['url'] ?>"<?= !empty($subitem['target']) ? ' target="' . $subitem['target'] . '" rel="noopener"' : '' ?>
                                   class="as-subrow<?= $isSubitemActive ? ' is-active' : '' ?>"<?= $isSubitemActive ? ' aria-current="page"' : '' ?>
                                   x-show="subMatches(menuItems[<?= $index ?>].submenu[<?= $subindex ?>], menuItems[<?= $index ?>])">
                                    <span class="as-name" x-html="highlightText(menuItems[<?= $index ?>].submenu[<?= $subindex ?>].name, searchQuery)"><?= $subitem['name'] ?></span>
                                    <?php if (!empty($subitem['badge'])): ?><span class="as-badge"><?= (int) $subitem['badge'] ?></span><?php endif; ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- SINGLE ITEM -->
                <a href="<?= $item['url'] ?>" class="as-row<?= $isDirectActive ? ' is-active' : '' ?>"<?= $isDirectActive ? ' aria-current="page"' : '' ?>
                   x-show="itemMatches(menuItems[<?= $index ?>])" title="<?= htmlspecialchars((string) $item['name']) ?>">
                    <span class="as-ico"><span class="material-symbols-outlined"><?= $item['icon'] ?></span></span>
                    <span x-show="isExpanded()" class="as-name" x-html="highlightText(menuItems[<?= $index ?>].name, searchQuery)"><?= $item['name'] ?></span>
                </a>
            <?php endif; ?>
            <?php endforeach; if ($asOpenGroup !== null) echo '</div>'; ?>
        </nav>

        <!-- USER CARD -->
        <?php
            $asUserName = trim((string) ($_SESSION['user_name'] ?? 'User'));
            $asInitials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', $asUserName) ?: ['U'], 0, 2))));
        ?>
        <div class="as-foot as-fade" :class="ready && 'is-ready'">
            <button type="button" @click.stop="userMenuOpen = !userMenuOpen" class="as-user" :aria-expanded="userMenuOpen ? 'true' : 'false'">
                <span class="as-avatar"><?= htmlspecialchars($asInitials ?: 'U') ?></span>
                <span x-show="isExpanded()" class="as-user-text">
                    <span class="as-user-name"><?= htmlspecialchars($asUserName) ?></span>
                    <span class="as-user-role"><?= htmlspecialchars((string) ($_SESSION['user_role'] ?? 'admin')) ?></span>
                </span>
                <svg x-show="isExpanded()" class="as-chev" :class="userMenuOpen && 'is-open'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 15-6-6-6 6"/></svg>
            </button>
            <div x-show="userMenuOpen" x-transition.opacity @click.away="userMenuOpen = false" class="as-menu" style="display:none">
                <a href="<?= root ?>profile"><span class="material-symbols-outlined">person</span><?= T::profile ?></a>
                <a href="<?= root . admin ?>/settings"><span class="material-symbols-outlined">settings</span><?= T::settings ?></a>
                <hr>
                <a href="<?= root ?>logout" class="is-danger"><span class="material-symbols-outlined">logout</span><?= T::logout ?></a>
            </div>
        </div>
    </aside>
</div>

<?php
    // Show setup progress alert only on admin pages that contain 'admin' in URL.
    // HIDDEN ON localhost AND phptravels.net (DEV/DEMO ENVIRONMENTS).
    $current_uri  = $_SERVER['REQUEST_URI'] ?? '';
    $alertHost    = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    $alertHost    = preg_replace('/^www\./', '', $alertHost);
    $hiddenHosts  = ['localhost', '127.0.0.1', '::1', 'phptravels.net'];
    if (!in_array($alertHost, $hiddenHosts, true)
        && strpos($current_uri, admin) !== false
        && strpos($current_uri, '/get-started') === false) {
        require_once __DIR__ . '/setup-progress-alert.php';
    }
?>

<?php } ?>
