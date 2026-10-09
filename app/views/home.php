<?php

// themes/default/home.php
@$SECURE or die('Access Denied!');

// IF THE WEBSITE IS OFFLINE
if($GLOBALS['app']['site_offline'] == 1){
   include "offline.php";
   exit;
   die;
}

// SKIP MODULES WITH EMPTY/INVALID TYPE — OTHERWISE HOMEPAGE CREATES A BLANK
// TAB (E.G. eSIM WITH TYPE="") WHOSE SEARCH PARTIAL 404s AND SPINS FOREVER.
$modules = array_values(array_filter($GLOBALS['modules'] ?? [], function ($m) {
    $type = strtolower(trim((string)($m['type'] ?? '')));
    return $type !== '' && (bool) preg_match('/^[a-z]+$/', $type);
}));
$grouped = array_reduce($modules, function($c, $m) {
    $type = strtolower(trim((string)$m['type']));
    $c[$type] = $c[$type] ?? ['type' => $type, 'name' => $type, 'order' => $m['order'], 'icon' => $m['icon'] ?? '', 'modules' => []];
    if (empty($c[$type]['icon']) && !empty($m['icon'])) $c[$type]['icon'] = $m['icon'];
    $c[$type]['modules'][] = $m;
    return $c;
}, []);

$mods = array_values($grouped);
// Sort by active count descending
usort($mods, function($a, $b) {
    $aActive = count($a['modules']);
    $bActive = count($b['modules']);
    if ($bActive !== $aActive) {
        return $bActive - $aActive; // Most active first
    }

   // Stable alphabetical fallback when counts are equal
   return strcmp((string)$a['type'], (string)$b['type']);
});

// ICON MAPPING FOR MODULES
$iconMap = [
    'flights' => 'hgi-airplane-01',
    'stays' => 'hgi-bed-single-01',
    'cars' => 'hgi-car-01',
    'tours' => 'hgi-maps',
    'visa' => 'hgi-passport',
    'cruises' => 'hgi-ferry-boat',
    'esim' => 'hgi-smartphone-02'
];

$initialTabKey = $mods[array_key_first($mods)]['type'] ?? '';

// Build a JS-safe list of valid tab types so URL-hash sync can validate input.
$validTabTypes = array_values(array_map(fn($m) => $m['type'], $mods));

// Home AI search (only when Trip Planner is enabled in settings AND the plan
// includes it — AI Trip Planner is an Enterprise feature).
$aiSearchEnabled = (!function_exists('plan_allows') || plan_allows('ai_trip_planner'))
    && function_exists('aiTripIsEnabled') && aiTripIsEnabled($GLOBALS['db'] ?? null);
if ($aiSearchEnabled) {
    $validTabTypes[] = 'ai';
}

$aiSuggestionsData = function_exists('aiSuggestionsForSearch')
    ? aiSuggestionsForSearch($GLOBALS['db'] ?? null, 0, 20)
    : [];

$aiEnabledModuleTypes = ($aiSearchEnabled && function_exists('aiTripEnabledModuleTypes'))
    ? array_values(aiTripEnabledModuleTypes($GLOBALS['db'] ?? null))
    : [];
?>

<?php
// HERO — built from components in includes/hero/ (data, styles, icons, script,
// background, heading, tabs, panels, ai-panel, shortcuts).
require_once views . 'includes/hero/icons.php';
require views . 'includes/hero/data.php';
require views . 'includes/hero/styles.php';
require views . 'includes/hero/script.php';
?>
<style>
   [x-cloak] { display: none !important; }
   .ai-suggestion-chip { max-width: 16rem; }
   @media (min-width: 640px) { .ai-suggestion-chip { max-width: 15rem; } }
   @media (min-width: 1024px) { .ai-suggestion-chip { max-width: 16rem; } }
</style>

<div class="hr">
   <?php include views . 'includes/hero/background.php'; ?>

   <?php if (!empty($mods)): ?>
   <!-- HIDDEN PROBE: LETS waitTailwindCdn DETECT WHEN PLAY CDN FINISHED @apply (field-box) -->
   <div id="tw-cdn-ready-probe" class="field-box" aria-hidden="true"
        style="position:absolute;width:0;height:0;overflow:hidden;opacity:0;pointer-events:none;left:0;top:0"></div>
   <div class="hr-wrap" x-data="homeHero()" x-init="start()">
      <?php include views . 'includes/hero/heading.php'; ?>
      <?php include views . 'includes/hero/tabs.php'; ?>
      <?php include views . 'includes/hero/panels.php'; ?>
      <?php include views . 'includes/hero/shortcuts.php'; ?>
   </div>
   <?php if (!empty($aiSearchEnabled)): ?>
   <script data-cfasync="false">
   window.homeAiSearch = (function () {
      var root = <?= json_encode(root) ?>;
      var enabledModules = <?= json_encode(array_values(array_map('strtolower', $aiEnabledModuleTypes ?? [])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?>;
      if (!Array.isArray(enabledModules)) enabledModules = [];
      var moduleKeywords = {
         flights: ["flight", "flights", "fly", "airfare", "airline", "airport"],
         stays: ["hotel", "hotels", "stay", "stays", "resort", "apartment", "hostel"],
         cars: ["car", "cars", "rent a car", "rental", "vehicle"],
         tours: ["tour", "tours", "activity", "activities", "excursion"],
         visa: ["visa", "visas"],
         umrah: ["umrah", "hajj"],
         esim: ["esim", "e-sim", "e sim", "sim card", "data sim", "travel sim", "tourist sim", "data plan", "data package", "mobile data", "roaming"],
         cruises: ["cruise", "cruises"],
         ferries: ["ferry", "ferries"],
         bus: ["bus", "buses", "coach"],
         rail: ["rail", "train", "trains", "railway", "whoosh"]
      };
      var moduleLabels = {
         flights: "Flights", stays: "Hotels", cars: "Cars", tours: "Tours",
         visa: "Visa", umrah: "Umrah", esim: "eSIM", cruises: "Cruises",
         ferries: "Ferries", bus: "Bus", rail: "Trains"
      };
      var flightWords = ["flight", "flights", "fly", "airfare", "airline", "airport"];
      var stops = ["find","look","looking","get","getting","show","give","see","check","me","a","an","the","cheap","cheapest","need","want","book","search","please","for","from","with","and","or","my","our","some","any","best","good","last","minute","deal","deals","offer","offers","option","options","available","ticket","tickets","tomorrow","today","tonight","now","next","this","that","round","trip","one","way","flight","flights","fly","airfare"];
      function readQuery() {
         var el = document.getElementById("ai-home-q");
         return String(el && (el.innerText || el.textContent) || "").replace(/\s+/g, " ").trim();
      }
      function setError(msg) {
         var p = document.getElementById("ai-home-error");
         if (p) p.textContent = msg || "";
      }
      function updateChars() {
         var n = document.getElementById("ai-home-chars");
         if (n) n.textContent = readQuery().length + " chars";
      }
      function hasWord(text, word) {
         return (" " + text + " ").indexOf(" " + word + " ") !== -1;
      }
      function moduleLabel(mod) {
         var m = String(mod || "").toLowerCase();
         return moduleLabels[m] || (m ? (m.charAt(0).toUpperCase() + m.slice(1)) : "Travel");
      }
      function detectModules(q) {
         var text = String(q || "").toLowerCase();
         if (!text) return [];
         var found = [];
         Object.keys(moduleKeywords).forEach(function (mod) {
            var words = moduleKeywords[mod] || [];
            for (var i = 0; i < words.length; i++) {
               if (hasWord(text, String(words[i]).toLowerCase())) {
                  found.push(mod);
                  return;
               }
            }
         });
         return found;
      }
      function disabledModulesMessage(q) {
         var requested = detectModules(q);
         if (!requested.length) return "";
         var allowed = requested.filter(function (m) { return enabledModules.indexOf(m) !== -1; });
         if (allowed.length) return "";
         var labels = requested.map(moduleLabel).filter(Boolean);
         var avail = enabledModules.map(moduleLabel).filter(Boolean);
         var msg = labels.length === 1
            ? (labels[0] + " is not available for AI Trip right now.")
            : "That travel option is not available for AI Trip right now.";
         if (avail.length) {
            var examples = avail.slice(0, 3);
            msg += " Try " + examples.join(", ")
               + (avail.length > 3 ? ", or another shown on top" : "")
               + ".";
         } else {
            msg += " Please try another travel option.";
         }
         return msg;
      }
      function isFlightAsk(q) {
         var text = String(q || "").toLowerCase();
         var i;
         for (i = 0; i < flightWords.length; i++) {
            if (hasWord(text, flightWords[i])) return true;
         }
         return text.indexOf("round trip") !== -1 || text.indexOf("one way") !== -1 || text.indexOf("one-way") !== -1;
      }
      function cityBeforeFlight(q) {
         var text = String(q || "").toLowerCase().replace(/[^a-z0-9\s-]/g, " ");
         var words = text.split(/\s+/);
         var clean = [];
         var i;
         for (i = 0; i < words.length; i++) {
            if (words[i]) clean.push(words[i]);
         }
         words = clean;
         var flightAt = -1;
         for (i = 0; i < words.length; i++) {
            if (words[i] === "flight" || words[i] === "flights" || words[i] === "fly") {
               flightAt = i;
               break;
            }
         }
         if (flightAt < 1) return "";
         for (i = flightAt - 1; i >= 0; i--) {
            if (words[i].length >= 3 && stops.indexOf(words[i]) === -1) return words[i];
         }
         return "";
      }
      function cityAfterFlight(q) {
         var text = String(q || "").toLowerCase();
         var m = text.match(/\b(?:flights?|fly|airfare)\s+(?:to|for|in)\s+([a-z][a-z\-]{1,24}(?:\s+[a-z][a-z\-]{1,24}){0,2})\b/i);
         if (!m || !m[1]) return "";
         var place = String(m[1]).trim();
         var filler = /^(today|tomorrow|tonight|now|me|you|us|look|looking|find|get|show|give|need|want|book|search|please|cheap|cheapest|best|good|a|an|the|flights?|fly|hotels?|stays?|tours?|cars?|tickets?)$/i;
         if (!place || filler.test(place)) return "";
         return place;
      }
      function hasArrival(q) {
         var text = String(q || "").toLowerCase();
         if (text.indexOf(" to ") !== -1 || text.indexOf("to ") === 0) return true;
         if (cityAfterFlight(text) !== "") return true;
         return cityBeforeFlight(text) !== "";
      }
      function isStayAsk(q) {
         var text = String(q || "").toLowerCase();
         var words = ["hotel", "hotels", "stay", "stays", "resort", "apartment", "hostel"];
         var i;
         for (i = 0; i < words.length; i++) {
            if (hasWord(text, words[i])) return true;
         }
         return false;
      }
      function hasStayDestination(q) {
         var text = String(q || "").toLowerCase();
         if (hasArrival(text)) return true;
         var m = text.match(/\b(?:hotels?|stays?|stay|resort|hostel|apartment)\s+(?:in|at|near|around|for|to)\s+([a-z][a-z\-]{1,24}(?:\s+[a-z][a-z\-]{1,24}){0,2})\b/i);
         if (m && m[1]) {
            var place = String(m[1]).trim();
            var filler = /^(today|tomorrow|tonight|now|me|you|us|cheap|cheapest|best|tickets?|room|rooms|hotels?|stays?)$/i;
            if (place && !filler.test(place)) return true;
         }
         m = text.match(/\b([a-z][a-z\-]{1,24}(?:\s+[a-z][a-z\-]{1,24}){0,2})\s+(?:hotels?|stays?|stay|resort|hostel|apartment)\b/i);
         if (m && m[1]) {
            place = String(m[1]).trim();
            filler = /^(find|look|looking|need|want|book|search|please|cheap|cheapest|best|good|a|an|the|my|our|for)$/i;
            if (place && !filler.test(place)) return true;
         }
         m = text.match(/\b(?:in|at|near|to)\s+([a-z][a-z\-]{1,24}(?:\s+[a-z][a-z\-]{1,24}){0,2})\b/i);
         if (m && m[1]) {
            place = String(m[1]).trim();
            filler = /^(today|tomorrow|tonight|now|me|you|us|cheap|cheapest|best|tickets?|room|rooms|hotels?|stays?)$/i;
            if (place && !filler.test(place)) return true;
         }
         return false;
      }
      function submit() {
         var q = readQuery();
         setError("");
         if (q.length < 10) {
            setError("Please enter at least 10 characters for better recommendations.");
            return false;
         }
         var disabledMsg = disabledModulesMessage(q);
         if (disabledMsg) {
            setError(disabledMsg);
            return false;
         }
         if (isFlightAsk(q) && !hasArrival(q)) {
            setError("Please mention Arrival To in your prompt (for example, flights to Paris or flights in Dubai).");
            return false;
         }
         if (isStayAsk(q) && !hasStayDestination(q)) {
            setError("Please mention a hotel destination in your prompt (for example, hotels in Dubai).");
            return false;
         }
         try { sessionStorage.setItem("ai_trip_pending_q", q); } catch (err) {}
         window.location.href = root + "ai-trip";
         return false;
      }
      document.addEventListener("click", function (e) {
         var t = e.target;
         if (!t || !t.closest) return;
         if (t.closest("#ai-home-search-btn")) {
            e.preventDefault();
            submit();
         }
      }, true);
      document.addEventListener("input", function (e) {
         if (e.target && e.target.id === "ai-home-q") updateChars();
      }, true);
      document.addEventListener("keydown", function (e) {
         if (!e.target || e.target.id !== "ai-home-q") return;
         if (e.key !== "Enter" || e.shiftKey) return;
         e.preventDefault();
         submit();
      }, true);
      if (document.readyState === "loading") {
         document.addEventListener("DOMContentLoaded", updateChars);
      } else {
         updateChars();
      }
      return { submit: submit };
   })();
   </script>
   <?php endif; ?>
   <?php else: ?>
   <!-- MESSAGE WHEN NO MODULES AVAILABLE -->
   <div class="xl:max-w-6xl w-full px-3 mx-auto relative z-10 xl:pb-16 md:pb-14 pb-0">
      <div class="w-full bg-white rounded-3xl p-6 text-center">
         <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-6">
               <div class="flex items-start justify-center">
                  <span class="material-symbols-outlined text-red-600 mr-3 text-lg">error</span>
                  <div>
                     <h3 class="text-red-800 font-medium text-lg mb-2"><?php echo T::no_travel_modules_enabled ?></h3>
                     <p class="text-red-700 text-sm mb-4"><?php echo T::enable_at_least_one_travel_module ?></p>
                     <a href="<?=root?>admin/settings/modules" class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700">
                        <span class="material-symbols-outlined mr-2 text-sm">settings</span>
                        <?php echo T::enable_modules_now ?>
                     </a>
                  </div>
               </div>
            </div>
         <?php else: ?>
            <div class="w-16 h-16 mx-auto mb-4 bg-blue-100 rounded-full flex items-center justify-center">
               <span class="material-symbols-outlined text-blue-600 text-2xl">travel_explore</span>
            </div>
            <h3 class="text-gray-800 font-medium text-xl mb-2"><?php echo T::booking_services_coming_soon ?></h3>
            <p class="text-gray-600 text-sm"><?php echo T::check_back_soon_for_deals ?></p>
         <?php endif; ?>
      </div>
   </div>
   <?php endif; ?>
</div>

<div style="content-visibility:auto; contain-intrinsic-size: 1400px;">
<?php
// Show eSIM featured first, then other modules, then blogs
$enabledModules = array_column($modules, 'type');

// Show eSIM featured first if available (featured.php self-lazy-loads its data)
if (in_array('esim', $enabledModules) && file_exists(views.'modules/esim/featured.php')) {
    include views.'modules/esim/featured.php';
}

// Show featured sections for other module types.
// EACH SECTION IS SERVER-RENDERED AND STAYS IN THE DOM; A SKELETON SHOWS BY
// DEFAULT AND THE REAL CONTENT FADES IN WHEN THE SECTION SCROLLS INTO VIEW.
$featuredOrder = array_column($mods, 'type');
// Featured flights are manual flight deals, so they need the parent "flights"
// module row — an API-only client (e.g. only TBO active) has no featured flights.
// The same goes for stays, tours and cars: their featured items are the site's own
// listings (Admin > Stays / Tours / Cars), so they need the manual module of that type
// (hotels / tours / cars) switched on; API suppliers alone show no featured section.
$manualModuleNames = ['flights' => 'flights', 'stays' => 'hotels', 'tours' => 'tours', 'cars' => 'cars'];
$manualEnabled = [];
foreach ($modules as $__m) {
    $__type = $__m['type'] ?? '';
    if (isset($manualModuleNames[$__type]) && ($__m['name'] ?? '') === $manualModuleNames[$__type]) {
        $manualEnabled[$__type] = true;
    }
}
foreach ($featuredOrder as $modType) {
    if ($modType === 'esim') {
        continue; // Already shown above
    }
    if (isset($manualModuleNames[$modType]) && empty($manualEnabled[$modType])) {
        continue;
    }
    $featuredFile = views.'modules/'.$modType.'/featured.php';
    if (!in_array($modType, $enabledModules) || !file_exists($featuredFile)) {
        continue;
    }
?>
<div x-data="{ shown: false }"
     x-init='const io = new IntersectionObserver((e) => { if (e[0].isIntersecting) { shown = true; io.disconnect() } }, { rootMargin: "140px" }); io.observe($el)'>
    <div x-show="!shown" class="container py-5 mt-5">
        <div class="animate-pulse">
            <div class="h-6 w-48 bg-gray-200 rounded mb-2"></div>
            <div class="h-3 w-72 bg-gray-100 rounded mb-5"></div>
            <div class="flex gap-3 overflow-hidden">
                <?php for ($i = 0; $i < 5; $i++): ?>
                <div class="w-[250px] h-40 bg-gray-100 rounded-xl border border-gray-200 shrink-0"></div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    <div x-show="shown" x-cloak
         x-transition:enter="transition-opacity duration-500"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">
        <?php include $featuredFile; ?>
    </div>
</div>
<?php } ?>

<div x-data="{ shown: false }"
     x-init='const io = new IntersectionObserver((e) => { if (e[0].isIntersecting) { shown = true; io.disconnect() } }, { rootMargin: "140px" }); io.observe($el)'>
    <div x-show="!shown" class="container py-5 mt-5">
        <div class="animate-pulse">
            <div class="h-6 w-48 bg-gray-200 rounded mb-2"></div>
            <div class="h-3 w-72 bg-gray-100 rounded mb-5"></div>
            <div class="flex gap-3 overflow-hidden">
                <?php for ($i = 0; $i < 5; $i++): ?>
                <div class="w-[250px] h-40 bg-gray-100 rounded-xl border border-gray-200 shrink-0"></div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    <div x-show="shown" x-cloak
         x-transition:enter="transition-opacity duration-500"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100">
        <?php include views.'modules/blogs/featured.php'; ?>
    </div>
</div>

<?php
// Mobile App Section - Dynamic from already-loaded global settings
$showApps = (($GLOBALS['app']['show_apps'] ?? '1') == '1');
$androidLink = $GLOBALS['app']['android_store'] ?? '';
$iosLink = $GLOBALS['app']['ios_store'] ?? '';
$hasAppLinks = !empty($androidLink) || !empty($iosLink);

if ($showApps && $hasAppLinks):
?>
<div class="container" style="margin-top:20px;margin-bottom:28px">
<?php
$maIos = $iosLink;
$maAndroid = $androidLink;
include views . 'includes/mobile-app/section.php';
?>
</div>
<?php endif; ?>
</div>