<?php
// ============================================================================
// HOTEL BOOKING PAGE - GUEST INFORMATION & PAYMENT COLLECTION
// ============================================================================
// PURPOSE: THIS PAGE HANDLES THE FINAL STEP OF THE BOOKING PROCESS
// FUNCTIONALITY:
//   - COLLECTS PRIMARY GUEST CONTACT DETAILS (NAME, EMAIL, PHONE)
//   - GATHERS ALL TRAVELER INFORMATION FOR EACH ROOM/GUEST
//   - DISPLAYS HOTEL SUMMARY WITH IMAGE AND BASIC INFO
//   - SHOWS SELECTED ROOMS WITH PRICING BREAKDOWN
//   - HANDLES PAYMENT GATEWAY SELECTION
//   - VALIDATES ALL INPUT DATA BEFORE SUBMISSION
//   - SUPPORTS BOTH GUEST CHECKOUT AND LOGGED-IN USER BOOKING
//   - IMPLEMENTS AUTO-FILL FOR LOGGED-IN USERS
//   - INCLUDES COUNTDOWN TIMER TO PREVENT SESSION EXPIRY
//
// DATA FLOW:
//   1. USER SELECTS ROOMS ON HOTEL DETAILS PAGE
//   2. BOOKING DRAFT IS SAVED TO DATABASE WITH UNIQUE HASH
//   3. USER IS REDIRECTED TO THIS PAGE VIA /stays/booking/{hash}
//   4. SESSION DATA IS RETRIEVED AND DISPLAYED
//   5. USER FILLS IN REQUIRED INFORMATION
//   6. ON SUBMIT: DATA IS VALIDATED AND BOOKING IS CONFIRMED
//   7. USER IS REDIRECTED TO INVOICE PAGE
//
// IMPORTANT SECURITY FEATURES:
//   - CSRF TOKEN VALIDATION ON FORM SUBMISSION
//   - SESSION-BASED BOOKING DATA (NOT URL PARAMETERS)
//   - HASH-BASED BOOKING IDENTIFICATION (PREVENTS TAMPERING)
//   - SECURE ACCESS CONTROL (@$SECURE CHECK)
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// RETRIEVE BOOKING DATA FROM SESSION
// ============================================================================
// WHY SESSION INSTEAD OF URL PARAMETERS?
//   - SECURITY: PREVENTS URL MANIPULATION AND DATA TAMPERING
//   - DATA SIZE: BOOKING DATA CAN BE LARGE (MULTIPLE ROOMS, TRAVELERS)
//   - PRIVACY: SENSITIVE INFORMATION STAYS SERVER-SIDE
//   - SIMPLICITY: CLEAN URLS WITHOUT COMPLEX QUERY STRINGS
//
// THE BOOKING HASH IS USED FOR:
//   - UNIQUE IDENTIFICATION OF THE BOOKING DRAFT IN DATABASE
//   - URL ROUTING (/stays/booking/{hash})
//   - PREVENTING DUPLICATE BOOKINGS
// ============================================================================
$bookingData = $_SESSION['booking_data'] ?? null;
$bookingHash = $_SESSION['booking_hash'] ?? null;

// ============================================================================
// VALIDATION: REDIRECT TO SEARCH IF NO BOOKING DATA EXISTS
// ============================================================================
// THIS PREVENTS:
//   - DIRECT ACCESS TO BOOKING PAGE WITHOUT SELECTING ROOMS
//   - EXPIRED OR CLEARED SESSION DATA
//   - BROWSER BACK BUTTON ISSUES AFTER BOOKING COMPLETION
// ============================================================================
if (!$bookingData || !$bookingHash) {
    header('Location: ' . root . 'stays');
    exit;
}

// ============================================================================
// EXTRACT AND DECODE BOOKING PARAMETERS
// ============================================================================
// THESE PARAMETERS ARE SET WHEN USER SELECTS ROOMS ON HOTEL DETAILS PAGE
// THEY ARE SAVED IN logs_bookings TABLE WITH STATUS='draft'
//
// KEY PARAMETERS:
//   - hotel_id: UNIQUE IDENTIFIER FOR THE HOTEL
//   - hotel_name: DISPLAY NAME OF THE HOTEL
//   - supplier: API PROVIDER (e.g., 'hotelbeds', 'custom')
//   - checkin/checkout: STAY DATES IN Y-m-d FORMAT
//   - nationality: GUEST NATIONALITY (AFFECTS PRICING IN SOME APIS)
//   - selectedRooms: ARRAY OF SELECTED ROOM OPTIONS WITH QUANTITIES
// ============================================================================
$hotelId = $bookingData['hotel_id'] ?? '';
$hotelName = $bookingData['hotel_name'] ?? '';
$supplier = $bookingData['supplier'] ?? '';
$checkin = $bookingData['checkin'] ?? '';
$checkout = $bookingData['checkout'] ?? '';
$nationality = $bookingData['nationality'] ?? '';

// Get booking created time for timer
$bookingCreatedAt = $_SESSION['booking_created_at'] ?? date('Y-m-d H:i:s');

$selectedRooms = $bookingData['selected_rooms'] ?? [];
if (is_string($selectedRooms)) {
    $decodedSelectedRooms = json_decode($selectedRooms, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedSelectedRooms)) {
        $selectedRooms = $decodedSelectedRooms;
    }
}
if (!is_array($selectedRooms)) {
    $selectedRooms = [];
}

$roomsData = $bookingData['rooms_data'] ?? [];
if (is_string($roomsData)) {
    $decodedRoomsData = json_decode($roomsData, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedRoomsData)) {
        $roomsData = $decodedRoomsData;
    }
}
if (!is_array($roomsData)) {
    $roomsData = [];
}

if (empty($roomsData)) {
    $fallbackChildren = (int)($bookingData['total_children'] ?? 0);
    $childAgesSource = $bookingData['child_ages'] ?? [];
    if (is_string($childAgesSource)) {
        $decodedChildAges = json_decode($childAgesSource, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedChildAges)) {
            $childAgesSource = $decodedChildAges;
        }
    }
    if (!is_array($childAgesSource)) {
        $childAgesSource = [];
    }

    $roomsData = [[
        'adults' => max(1, (int)($bookingData['total_adults'] ?? 1)),
        'children' => max(0, $fallbackChildren),
        'childAges' => array_values(array_slice($childAgesSource, 0, max(0, $fallbackChildren)))
    ]];
}
$nights = $bookingData['nights'] ?? 0;
$totalAmount = $bookingData['total_amount'] ?? 0;
$currency = $bookingData['currency'] ?? 'USD';
$isGrnConnect = strtolower((string)$supplier) === 'grnconnect';

// Get base currency (for payments) and display currency (for user interface)
$baseCurrency = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
$displayCurrency = $db->get('currencies', ['name', 'rate'], ['name' => $_SESSION['app_currency'] ?? 'USD']);

$baseCurrencyCode = $baseCurrency['name'] ?? 'USD';
$displayCurrencyCode = $displayCurrency['name'] ?? $currency;
$conversionRate = 1;

// Calculate conversion rate from base to display currency
if ($baseCurrency && $displayCurrency && $baseCurrencyCode !== $displayCurrencyCode) {
    $conversionRate = $displayCurrency['rate'] / $baseCurrency['rate'];
}

// Convert amounts for display
$totalAmountDisplay = $totalAmount * $conversionRate;
$totalAmountBase = $totalAmount; // Always store/charge in base currency

// Calculate tax using the actual supplier (e.g., 'hotelbeds', 'agoda', or 'hotels')
$moduleData = $db->get('modules', ['tax', 'tax_type'], ['name' => $supplier, 'status' => '1']);
$taxType = $moduleData['tax_type'] ?? 'percentage';

// 1. Calculate in BASE Currency (for database/submission)
if ($taxType === 'fixed') {
    $taxCalculationBase = calculateTax($totalAmountBase, $supplier, $db, $baseCurrencyCode, $baseCurrencyCode);
} else {
    $taxCalculationBase = calculateTax($totalAmountBase, $supplier, $db);
}
$taxAmountBase = $taxCalculationBase['tax_amount'];
$totalWithTaxBase = $taxCalculationBase['total_with_tax'];

// 2. Calculate in DISPLAY Currency (for UI)
if ($taxType === 'fixed') {
    // Fixed tax: Calculate on Base Amount, but Convert to Display Amount
    $taxCalculationDisplay = calculateTax($totalAmountBase, $supplier, $db, $baseCurrencyCode, $displayCurrencyCode);
    $taxAmount = $taxCalculationDisplay['tax_amount'];
} else {
    // Percentage tax: Calculate directly on Display Amount
    $taxCalculationDisplay = calculateTax($totalAmountDisplay, $supplier, $db);
    $taxAmount = $taxCalculationDisplay['tax_amount'];
}

$totalWithTax = $totalAmountDisplay + $taxAmount; // Add Display Tax to Display Total

$taxAmountDisplay = $taxAmount; // For UI
$totalWithTaxDisplay = $totalWithTax; // For UI
$hasTax = $taxAmountBase > 0;

// Additional validation
if (empty($hotelId) || empty($selectedRooms)) {
    header('Location: ' . root . 'stays');
    exit;
}

// Get countries for nationality dropdown
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['status' => 'active', 'ORDER' => ['nicename' => 'ASC']]);

// Get payment gateways for payment selection (`default` is a reserved SQL word)
try {
    $paymentGateways = $db->query("
        SELECT `id`, `name`, `note`, `type`, `status`, `order`, `default` AS is_default
        FROM `payment_gateways`
        WHERE `status` = 1
        ORDER BY `default` DESC, `order` ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $paymentGateways = [];
}

// Check if user is logged in and fetch user data
$isUserLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$loggedInUser = null;

if ($isUserLoggedIn) {
    // Fetch user details from database
    $loggedInUser = $db->get('users', [
        'id',
        'user_id',
        'title',
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_country_code'
    ], ['user_id' => $_SESSION['user_id']]);

    // If user not found, clear session
    if (!$loggedInUser) {
        $isUserLoggedIn = false;
        unset($_SESSION['user_id']);
    }
}

// Filter out wallet balance for non-authenticated users
if (!$isUserLoggedIn) {
    $paymentGateways = array_filter($paymentGateways, function($gateway) {
        // Filter by name (legacy) or by type column
        if (!empty($gateway['type']) && $gateway['type'] === 'internal_wallet') {
            return false;
        }
        return stripos($gateway['name'], 'wallet') === false;
    });
    $paymentGateways = array_values($paymentGateways);
}

$defaultGatewayId = '';
foreach ($paymentGateways as $gateway) {
    if (($gateway['is_default'] ?? $gateway['default'] ?? 0) == 1) {
        $defaultGatewayId = (string) ($gateway['id'] ?? '');
        break;
    }
}
if ($defaultGatewayId === '' && !empty($paymentGateways)) {
    $defaultGatewayId = (string) ($paymentGateways[0]['id'] ?? '');
}
?>

<!-- Shopify-Style Checkout Layout -->
<div class="min-h-screen bg-gradient-to-r from-white to-slate-200" x-data="bookingForm()" x-init="init()">

    <?php include views . 'includes/booking/loading.php'; ?>

    <div class="grid grid-cols-1 lg:grid-cols-5 min-h-screen container">

        <!-- Left Column: 60% - Full White Background -->
        <div class="bg-white px-6 py-4 lg:px-8 lg:py-6 order-2 lg:order-1 lg:col-span-3">

            <!-- Header Section -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <div class="flex justify-between items-center w-full">
                    <div class="flex items-center gap-5">
                        <a href="javascript:history.back()" class="btn secondary inline-flex items-center justify-start w-13 h-12 rounded-lg bg-white border border-slate-200 text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                            <span class="material-symbols-outlined text-xl">arrow_back</span>
                        </a>
                        <div>
                            <h1 class="text-1xl font-bold text-slate-800">
                                <?= T::booking ?>
                            </h1>
                            <p class="text-sm text-slate-600 mt-1">
                                Complete your hotel booking
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center">
                        <a href="<?= root ?>" class="flex items-center">
                            <img src="<?= root ?>uploads/global/logo.png" alt="logo" class="h-8 w-auto mb-2">
                        </a>
                    </div>
                </div>
            </div>

                <!-- Alert Messages -->
            <div x-show="showAlert"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 transform -translate-y-2"
                x-transition:enter-end="opacity-100 transform translate-y-0"
                class="mb-5" style="display: none;">
                <div class="alert" :class="alertType === 'error' ? 'alert-error' : 'alert-success'">
                    <span class="material-symbols-outlined" x-text="alertType === 'error' ? 'error' : 'check_circle'"></span>
                    <p x-text="alertMessage"></p>
                    <button @click="showAlert = false" class="ml-auto">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
            </div>

            <form @submit.prevent="submitBooking" class="space-y-5">

                <!-- ========================================== -->
                <!-- HOTEL INFORMATION CARD                      -->
                <!-- ========================================== -->
                <!-- PURPOSE: DISPLAY HOTEL SUMMARY WITH IMAGE  -->
                <!-- SHOWS: NAME, STARS, ADDRESS, DATES, NIGHTS -->
                <!-- DATA SOURCE: hotelData (FROM ALPINE.JS)    -->
                <!-- ========================================== -->


                <div @guest-updated.window="handleGuestUpdate($event.detail)">
                <?php
                // ============================================================================
                // INCLUDE REUSABLE BOOKING AUTHENTICATION COMPONENT
                // ============================================================================
                include views . 'includes/booking/booking-auth.php';
                ?>
                </div>

                <!-- ========================================== -->
                <!-- TRAVELERS DETAILS SECTION                  -->
                <!-- ========================================== -->
                <!-- PURPOSE: COLLECT TRAVELER INFO FOR BOOKING -->
                <!-- FEATURES:                                  -->
                <!--   - SEPARATE FORMS FOR EACH ROOM           -->
                <!--   - ADULT AND CHILD TRAVELERS              -->
                <!--   - LEAD TRAVELER AUTO-SYNCS WITH GUEST   -->
                <!--   - DISABLED FIELDS WHEN SYNCED            -->
                <!--   - TITLE, FIRST NAME, LAST NAME REQUIRED  -->
                <!--   - CHILD AGE DISPLAY (NON-EDITABLE)       -->
                <!-- ========================================== -->
                <?php foreach ($roomsData as $roomIndex => $room): ?>
                <div class="card p-0 mb-5">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">hotel</span>
                            <h3><?= T::room ?> <?= $roomIndex + 1 ?></h3>
                        </div>
                        <div>
                            <span><?= $room['adults'] ?> <?= T::adult ?><?= $room['adults'] > 1 ? T::s : '' ?><?= $room['children'] > 0 ? ', ' . $room['children'] . ' ' . ( $room['children'] > 1 ? T::children : T::child ) : '' ?></span>
                        </div>
                    </div>
                    <div class="card-body">

                                <!-- Adults in this room -->
                                <?php for ($adultIndex = 0; $adultIndex < $room['adults']; $adultIndex++): ?>
                                    <?php if ($roomIndex === 0 && $adultIndex === 0): ?>
                                        <div class="mb-3 p-4 bg-white border border-gray-200 dark:bg-gray-800 dark:border-gray-600 rounded-lg">
                                            <!-- LEAD TRAVELER INDICATOR WITH SYNC STATUS -->
                                            <div class="flex items-center justify-between mb-2">
                                                <h5 class="font-medium text-gray-700 dark:text-gray-300 text-sm">
                                                    <?= T::adult ?> <?= $adultIndex + 1 ?> (<?= T::lead_traveler ?>)
                                                </h5>
                                                <span class="text-xs" :class="formData.booking_for_someone_else ? 'text-indigo-600' : 'text-gray-500'">
                                                    <span x-show="!formData.booking_for_someone_else"><?= T::synced_with_guest_details ?></span>
                                                    <span x-show="formData.booking_for_someone_else"><?= T::editable ?></span>
                                                </span>
                                            </div>
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::title ?> *</label>
                                                    <select x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.title"
                                                            class="select"
                                                            :disabled="!formData.booking_for_someone_else"
                                                            required>
                                                        <option value="Mr.">Mr.</option>
                                                        <option value="Ms.">Ms.</option>
                                                        <option value="Mrs.">Mrs.</option>
                                                    </select>
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                                                    <input type="text"
                                                           x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.first_name"
                                                           class="input"
                                                           :disabled="!formData.booking_for_someone_else"
                                                           maxlength="30" pattern="[A-Za-z ]+" title="Letters and spaces only, max 30 characters" @input="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.first_name = sanitizeGuestName($event.target.value)"
                                                           required>
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                                                    <input type="text"
                                                           x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.last_name"
                                                           class="input"
                                                           :disabled="!formData.booking_for_someone_else"
                                                           maxlength="30" pattern="[A-Za-z ]+" title="Letters and spaces only, max 30 characters" @input="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.last_name = sanitizeGuestName($event.target.value)"
                                                           required>
                                                </div>
                                            </div>
                                            <!-- HELP TEXT FOR LOCKED FIELDS -->
                                            <p class="text-xs text-gray-500 mt-2" x-show="!formData.booking_for_someone_else">
                                                <?= T::lead_traveler_help_text ?>
                                            </p>
                                        </div>
                                    <?php else: ?>
                                        <div class="mb-3 p-4 bg-white border border-gray-200 dark:bg-gray-800 dark:border-gray-600 rounded-lg">
                                            <h5 class="font-medium text-gray-700 dark:text-gray-300 mb-2 text-sm">
                                                <?= T::adult ?> <?= $adultIndex + 1 ?>
                                            </h5>
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::title ?></label>
                                                    <select x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.title" class="select">
                                                        <option value="Mr.">Mr.</option>
                                                        <option value="Ms.">Ms.</option>
                                                        <option value="Mrs.">Mrs.</option>
                                                    </select>
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::first ?> <?= T::name ?></label>
                                                    <input type="text"
                                                           x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.first_name"
                                                           class="input"
                                                           maxlength="30" pattern="[A-Za-z ]+" title="Letters and spaces only, max 30 characters" @input="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.first_name = sanitizeGuestName($event.target.value)">
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::last ?> <?= T::name ?></label>
                                                    <input type="text"
                                                           x-model="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.last_name"
                                                           class="input"
                                                           maxlength="30" pattern="[A-Za-z ]+" title="Letters and spaces only, max 30 characters" @input="formData.travelers.room_<?= $roomIndex ?>.adult_<?= $adultIndex ?>.last_name = sanitizeGuestName($event.target.value)">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <!-- Children in this room -->
                                <?php if ($room['children'] > 0): ?>
                                    <?php for ($childIndex = 0; $childIndex < $room['children']; $childIndex++): ?>
                                        <div class="mb-3 p-4 bg-white border border-gray-200 dark:bg-gray-800 dark:border-gray-600 rounded-lg">
                                            <h5 class="font-medium text-blue-700 dark:text-blue-300 mb-2 text-sm">
                                                <?= T::child ?> <?= $childIndex + 1 ?> (<?= T::age ?>: <?= $room['childAges'][$childIndex] ?? '1' ?>)
                                            </h5>
                                            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::title ?></label>
                                                    <select x-model="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.title" class="select">
                                                        <option value="Mstr.">Mstr.</option>
                                                        <option value="Ms.">Ms.</option>
                                                    </select>
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::first ?> <?= T::name ?></label>
                                                    <input type="text"
                                                           x-model="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.first_name"
                                                           class="input"
                                                           maxlength="30" pattern="[A-Za-z ]+" title="Letters and spaces only, max 30 characters" @input="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.first_name = sanitizeGuestName($event.target.value)">
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::last ?> <?= T::name ?></label>
                                                    <input type="text"
                                                           x-model="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.last_name"
                                                           class="input"
                                                           maxlength="30" pattern="[A-Za-z ]+" title="Letters and spaces only, max 30 characters" @input="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.last_name = sanitizeGuestName($event.target.value)">
                                                </div>
                                                <div class="form-control">
                                                    <label class="text-xs"><?= T::age ?></label>
                                                    <input type="number"
                                                           value="<?= $room['childAges'][$childIndex] ?? '1' ?>"
                                                           x-model="formData.travelers.room_<?= $roomIndex ?>.child_<?= $childIndex ?>.age"
                                                           class="input"
                                                           min="1"
                                                           max="17"
                                                           readonly>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- ========================================== -->
                <!-- PAYMENT GATEWAY SELECTION                  -->
                <!-- ========================================== -->
                <!-- PURPOSE: ALLOW USER TO CHOOSE PAYMENT      -->
                <!-- FEATURES:                                  -->
                <!--   - RADIO BUTTONS FOR GATEWAY SELECTION    -->
                <!--   - DISPLAYS GATEWAY NOTES/INSTRUCTIONS    -->
                <!--   - WALLET OPTION FOR LOGGED-IN USERS ONLY -->
                <!--   - DEFAULT GATEWAY PRE-SELECTED           -->
                <!-- DATA SOURCE: $paymentGateways (PHP)        -->
                <!-- ========================================== -->
                <?php include views.'includes/booking/payment-methods.php'; ?>


                <!-- ========================================== -->
                <!-- BOOKING OPTIONS & SPECIAL REQUESTS         -->
                <!-- ========================================== -->
                <!-- PURPOSE: COLLECT ADDITIONAL PREFERENCES    -->
                <!-- INCLUDES:                                  -->
                <!--   - SPECIAL REQUESTS TEXTAREA              -->
                <!--   - TERMS & CONDITIONS CHECKBOX            -->
                <!--   - CSRF TOKEN FOR SECURITY                -->
                <!--   - SUBMIT BUTTON WITH LOADING STATE       -->
                <!-- ========================================== -->
                <div class="card p-0 mb-5">
                    <div class="card-header">
                        <div>
                            <span class="card-header-icon">settings</span>
                            <h3><?= T::booking_options ?></h3>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Special Requests -->
                        <div class="form-control mb-6">
                            <label><?= T::special ?> <?= T::requests ?> (<?= T::optional ?>)</label>
                            <textarea x-model="formData.special_requests"
                                      class="input"
                                      rows="3"
                                      placeholder="<?= T::any ?> <?= T::special ?> <?= T::requests ?> <?= T::or ?> <?= T::notes ?>..."></textarea>
                        </div>

                        <?php if ($isGrnConnect): ?>
                        <!-- GRNConnect mandatory recheck (guaranteed rate details) -->
                        <div class="mb-6 p-4 rounded-lg border"
                             :class="grnRecheckError ? 'border-red-200 bg-red-50' : (grnRecheckDone ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50')">
                            <h4 class="font-semibold text-sm mb-2 flex items-center gap-1"
                                :class="grnRecheckError ? 'text-red-800' : (grnRecheckDone ? 'text-green-800' : 'text-amber-800')">
                                <span class="material-symbols-outlined text-base" x-text="grnRecheckLoading ? 'hourglass_top' : (grnRecheckDone ? 'verified' : 'policy')"></span>
                                Verified Rate Details
                            </h4>

                            <p x-show="grnRecheckLoading" class="text-xs text-amber-800">Verifying live rate with supplier...</p>
                            <p x-show="grnRecheckError" class="text-xs text-red-700" x-text="grnRecheckError"></p>

                            <!-- Price change confirmation (blocks booking until accepted) -->
                            <div x-show="grnRecheckDone && grnPriceChanged && !grnPriceAccepted"
                                 class="mb-3 p-3 rounded-lg border border-amber-300 bg-amber-50 space-y-2">
                                <p class="text-xs text-amber-900 font-medium"
                                   x-text="grnPriceChangeMessage || 'The price has changed. Please review the updated price before continuing.'"></p>
                                <div class="grid grid-cols-2 gap-2 text-xs text-gray-800">
                                    <div class="p-2 bg-white rounded border border-gray-200">
                                        <p class="text-[10px] uppercase text-gray-500 mb-0.5">Previous price</p>
                                        <p class="font-semibold"
                                           x-text="`${Number(grnPreviousPrice || 0).toFixed(2)} ${grnPriceCurrency || ''}`"></p>
                                    </div>
                                    <div class="p-2 bg-white rounded border border-amber-200">
                                        <p class="text-[10px] uppercase text-amber-700 mb-0.5">Updated price</p>
                                        <p class="font-semibold text-amber-900"
                                           x-text="`${Number(grnUpdatedPrice || 0).toFixed(2)} ${grnPriceCurrency || ''}`"></p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2 pt-1">
                                    <button type="button"
                                            @click="acceptGrnPriceChange()"
                                            class="px-3 py-1.5 text-xs font-semibold rounded bg-primary text-white hover:opacity-90">
                                        Accept updated price
                                    </button>
                                    <button type="button"
                                            @click="rejectGrnPriceChange()"
                                            class="px-3 py-1.5 text-xs font-semibold rounded border border-gray-300 bg-white text-gray-700 hover:bg-gray-50">
                                        Choose another room
                                    </button>
                                </div>
                            </div>

                            <p x-show="grnRecheckDone && grnPriceChanged && grnPriceAccepted" class="text-xs text-green-800 font-medium mb-2">
                                Updated price accepted. Booking will continue with the latest verified price.
                            </p>
                            <p x-show="grnRecheckDone && !grnPriceChanged" class="text-xs text-green-800 mb-2">
                                Rate verified. Price unchanged.
                            </p>

                            <template x-if="grnRecheckDone && selectedRoomsData.length && (grnPriceAccepted || !grnPriceChanged)">
                                <div class="space-y-4 text-xs text-gray-700">
                                    <template x-for="(room, roomIndex) in selectedRoomsData" :key="'grn-verify-' + roomIndex">
                                        <div class="p-3 bg-white rounded-lg border border-gray-200 space-y-3">
                                            <div class="flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    <p class="font-semibold text-sm text-gray-900"
                                                       x-text="selectedRoomsData.length > 1 ? `Room ${roomIndex + 1}: ${room.room_name}` : room.room_name"></p>
                                                    <p x-show="room.quantity > 1" class="text-[11px] text-gray-500"
                                                       x-text="isGrnPackage(room) ? `${room.quantity}-room package` : `×${room.quantity}`"></p>
                                                </div>
                                                <div class="flex flex-wrap gap-2 justify-end">
                                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold"
                                                          :class="getRefundabilityClass(room.option)"
                                                          x-text="getRefundabilityLabel(room.option)"></span>
                                                    <span class="px-2 py-0.5 rounded-full bg-gray-50 border text-[11px]"
                                                          x-text="room.option.board_name || room.option.meal_plan"></span>
                                                </div>
                                            </div>

                                            <p x-show="!isGrnConnect && room.option.cancellation_text" class="whitespace-pre-line">
                                                <span class="font-semibold"><?= defined('T::cancellation_policy') ? T::cancellation_policy : 'Cancellation' ?>:</span>
                                                <span x-text="room.option.cancellation_text"></span>
                                            </p>

                                            <template x-if="room.option.rate_comments_list && room.option.rate_comments_list.length">
                                                <div>
                                                    <p class="font-semibold mb-1"><?= defined('T::rate_comments') ? T::rate_comments : 'Rate Comments' ?></p>
                                                    <template x-for="(comment, idx) in room.option.rate_comments_list" :key="idx">
                                                        <div class="mb-1">
                                                            <span class="font-medium" x-text="comment.label || comment.key"></span>:
                                                            <span class="whitespace-pre-line" x-html="comment.value"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>

                                            <template x-if="room.option.other_inclusions && room.option.other_inclusions.length">
                                                <div>
                                                    <p class="font-semibold mb-1">Other Inclusions</p>
                                                    <ul class="list-disc pl-4">
                                                        <template x-for="(inc, idx) in room.option.other_inclusions" :key="idx">
                                                            <li x-text="inc"></li>
                                                        </template>
                                                    </ul>
                                                </div>
                                            </template>

                                            <template x-if="room.option.rate_rooms && room.option.rate_rooms.length">
                                                <div>
                                                    <p class="font-semibold mb-1"><?= defined('T::room_details') ? T::room_details : 'Room Details' ?></p>
                                                    <template x-for="(rr, idx) in room.option.rate_rooms" :key="idx">
                                                        <div class="mb-2 p-2 bg-gray-50 rounded border border-gray-200">
                                                            <p class="font-medium capitalize" x-text="`${rr.index ? 'Room ' + rr.index + ': ' : ''}${rr.room_type || 'Room'}`"></p>
                                                            <p class="text-[11px] text-gray-500" x-text="rr.pax_label"></p>
                                                            <p x-show="rr.description" class="text-[11px] mt-1 whitespace-pre-line" x-text="rr.description"></p>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>

                                            <template x-if="room.option.price_details_groups && room.option.price_details_groups.length">
                                                <div>
                                                    <p class="font-semibold mb-1"><?= defined('T::price_breakdown') ? T::price_breakdown : 'Price Breakdown' ?></p>
                                                    <template x-for="(group, gIdx) in room.option.price_details_groups" :key="gIdx">
                                                        <p class="text-[11px] font-semibold uppercase text-purple-700 mt-1" x-text="group.key"></p>
                                                        <template x-for="(line, lIdx) in group.items" :key="lIdx">
                                                            <div class="flex justify-between text-[11px] py-0.5">
                                                                <span>
                                                                    <span x-text="line.name"></span>
                                                                    <span class="text-gray-400" x-text="line.included ? '(included)' : '(not included)'"></span>
                                                                </span>
                                                                <span x-text="line.amount_type === 'percent' ? `${line.amount}%` : `${Number(line.amount || 0).toFixed(2)} ${line.currency || ''}`"></span>
                                                            </div>
                                                        </template>
                                                    </template>
                                                </div>
                                            </template>

                                            <template x-if="room.option.safe2stay && room.option.safe2stay.length">
                                                <div>
                                                    <p class="font-semibold mb-1">Safe2Stay</p>
                                                    <template x-for="(item, idx) in room.option.safe2stay" :key="idx">
                                                        <div class="mb-1">
                                                            <span class="font-medium" x-text="item.label || item.key"></span>:
                                                            <span class="whitespace-pre-line" x-html="item.value"></span>
                                                        </div>
                                                    </template>
                                                </div>
                                            </template>

                                            <template x-if="room.option.excluded_taxes && room.option.excluded_taxes.length">
                                                <div>
                                                    <p class="font-semibold mb-1 text-amber-800"><?= defined('T::excluded_taxes') ? T::excluded_taxes : 'Pay at hotel' ?></p>
                                                    <template x-for="(tax, idx) in room.option.excluded_taxes" :key="idx">
                                                        <div class="text-[11px] text-amber-700"
                                                             x-text="`${tax.name}: ${tax.clientAmount || tax.amount} ${tax.clientCurrency || tax.hotelCurrency || ''}`"></div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                        <?php endif; ?>

                        <!-- Cancellation Policy -->
                        <div x-show="selectedRoomsData.some(room => room.option.cancellation_text || room.option.cancellation_details)"
                             class="mb-6">
                            <template x-for="(room, index) in selectedRoomsData" :key="'cancel-' + index">
                                <div x-show="room.option.cancellation_text || room.option.cancellation_details" class="mb-3 last:mb-0">
                                    <?php if ($isGrnConnect): ?>
                                    <div x-show="selectedRoomsData.length > 1" class="flex flex-wrap items-center gap-2 mb-2">
                                        <p class="text-xs font-semibold text-gray-800 dark:text-gray-200"
                                           x-text="`${room.room_name} (×${room.quantity})`"></p>
                                        <span class="text-[11px] px-2 py-0.5 rounded-full font-semibold"
                                              :class="getRefundabilityClass(room.option)"
                                              x-text="getRefundabilityLabel(room.option)"></span>
                                    </div>
                                    <?php $cpExpr = 'room.option'; $showHeader = true; include __DIR__ . '/../partials/cancellation-policy-alpine.php'; ?>
                                    <?php else: ?>
                                    <div class="p-4 rounded-lg border border-yellow-200 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-900/20">
                                        <h4 class="font-semibold text-sm text-yellow-800 dark:text-yellow-300 mb-2 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-base">policy</span>
                                            <?= defined('T::cancellation_policy') ? T::cancellation_policy : 'Cancellation Policy' ?>
                                        </h4>
                                        <p class="text-xs text-gray-700 dark:text-gray-400 leading-relaxed whitespace-pre-line"
                                           x-text="room.option.cancellation_text"></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </template>
                        </div>

                        <!-- Additional Notes Section -->
                        <div x-show="selectedRoomsData.some(room => room.option.additional_notes && room.option.additional_notes.all_messages && room.option.additional_notes.all_messages.length)" 
                            class="mb-6 p-4 rounded-lg border border-blue-200 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20">
                            <h4 class="font-semibold text-sm text-blue-800 dark:text-blue-300 mb-3 flex items-center gap-1">
                                <span class="material-symbols-outlined text-base">info</span>
                                <?= defined('T::addition_notes') ? T::addition_notes : 'Addition Notes' ?>
                            </h4>

                            <template x-for="(room, roomIndex) in selectedRoomsData" :key="roomIndex">
                                <div x-show="room.option.additional_notes && room.option.additional_notes.all_messages && room.option.additional_notes.all_messages.length">
                                    
                                    <!-- Room Header (if multiple rooms) -->
                                    <div x-show="selectedRoomsData.length > 1" class="mb-2">
                                        <p class="text-xs font-semibold text-blue-700 dark:text-blue-400" 
                                        x-text="`${room.room_name} (×${room.quantity})`"></p>
                                    </div>

                                    <!-- Messages Loop -->
                                    <template x-for="(message, msgIndex) in room.option.additional_notes.all_messages" :key="msgIndex">
                                        <div class="mb-3 last:mb-0">
                                            <!-- Message Type Badge -->
                                            <div class="flex items-center gap-2 mb-2">
                                                <span class="text-xs px-2 py-0.5 rounded font-medium"
                                                    :class="{
                                                        'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300': message.type === 'General',
                                                        'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300': message.type === 'Internal Note',
                                                        'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300': !['General', 'Internal Note'].includes(message.type)
                                                    }"
                                                    x-text="message.type">
                                                </span>
                                            </div>

                                            <!-- Message Content (HTML) -->
                                            <div class="text-xs text-gray-700 dark:text-gray-400 leading-relaxed prose prose-sm max-w-none
                                                        [&>span]:inline [&>b]:font-semibold [&>br]:block [&>br]:my-1"
                                                x-html="message.html">
                                            </div>

                                            <!-- Divider between messages (if not last) -->
                                            <div x-show="msgIndex < room.option.additional_notes.all_messages.length - 1" 
                                                class="mt-3 pt-3 border-t border-dashed border-blue-200 dark:border-blue-700">
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Divider between rooms (if multiple rooms and not last) -->
                                    <div x-show="selectedRoomsData.length > 1 && roomIndex < selectedRoomsData.length - 1" 
                                        class="mt-4 pt-4 border-t border-blue-300 dark:border-blue-600">
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Terms & Conditions -->
                        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                            <div class="checkbox-item">
                                <div class="checkbox-container">
                                    <input type="checkbox"
                                           id="terms_accepted"
                                           x-model="formData.terms_accepted"
                                           class="checkbox-input"
                                           required>
                                    <div class="checkbox-custom">
                                        <span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span>
                                    </div>
                                </div>
                                <label for="terms_accepted" class="cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                                    <?= T::i_agree_to_the ?>
                                    <a href="<?= root ?>page/terms-of-use" target="_blank" class="text-blue-600 hover:underline"><?= T::terms ?> & <?= T::conditions ?></a>
                                    <?= T::and ?>
                                    <a href="<?= root ?>page/privacy-policy" target="_blank" class="text-blue-600 hover:underline"><?= T::privacy ?> <?= T::policy ?></a>
                                </label>
                            </div>
                        </div>

                        <!-- CSRF Token -->
                        <?= CSRF::tokenField() ?>

                        <!-- Submit Button -->
                        <button type="submit"
                                class="btn w-full mt-6"
                                :disabled="submitting || !formData.terms_accepted"
                                :class="{ 'opacity-50 cursor-not-allowed': !formData.terms_accepted }">
                            <span x-show="!submitting" class="material-symbols-outlined">lock</span>
                            <span x-show="submitting" class="material-symbols-outlined animate-spin">progress_activity</span>
                            <span x-show="!submitting"><?= T::confirm ?> <?= T::booking ?></span>
                            <span x-show="submitting"><?= T::processing ?>...</span>
                        </button>

                        <!-- Terms validation message -->
                        <div x-show="!formData.terms_accepted" class="mt-2">
                            <p class="text-sm text-red-600 dark:text-red-400 text-center">
                                <span class="material-symbols-outlined !text-[16px]">info</span>
                                <?= T::please_accept_terms_to_proceed ?>
                            </p>
                        </div>
                    </div>
                </div>
            </form>

        </div><!-- Close left column -->

        <!-- Right Column: Summary - Fixed with Scrollable Content -->
        <div class="bg-slate-200 px-6 py-6 order-1 lg:order-2 lg:col-span-2 lg:sticky lg:top-0 flex flex-col">

            <!-- Booking Countdown Timer -->
            <?php
            $moduleType = 'stays';
            // include views . 'includes/booking/timer.php';
            ?>

            <!-- Booking Summary Card -->
            <div class="card p-0 mb-5">
                <div class="card-header">
                    <div>
                        <span class="card-header-icon">receipt_long</span>
                        <h3><?= T::booking ?> <?= T::summary ?></h3>
                    </div>
                </div>
                <div class="card-body">

                    <!-- Hotel Information & Booking Details -->
                    <div class="mb-4 p-4 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-700 rounded-lg border border-blue-200 dark:border-gray-600">
                        <!-- Hotel Image & Name -->
                        <div x-show="!loading && hotelData" x-cloak class="flex items-start gap-3 mb-3">
                            <div class="w-16 h-16 flex-shrink-0">
                                <img
                                    :src="hotelData?.images?.[0] || hotelData?.image || '<?= root ?>uploads/no_img.jpg'"
                                    class="w-full h-full object-cover rounded-lg border-2 border-white dark:border-gray-600 shadow-sm"
                                    onerror="this.src='<?= root ?>uploads/no_img.jpg'"
                                    :alt="hotelData?.name"
                                >
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="font-bold text-gray-900 dark:text-white text-sm mb-1 leading-tight" x-text="hotelData?.name"></h4>
                                <div class="flex items-center gap-0 mb-2" x-show="hotelData?.stars">
                                    <template x-for="i in 5" :key="i">
                                        <svg class="w-4 h-4 -ml-1" :class="i <= hotelData?.stars ? 'text-orange-500' : 'text-gray-300'" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                        </svg>
                                    </template>
                                </div>
                                <p class="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1">
                                    <span class="material-symbols-outlined !text-xs">location_on</span>
                                    <span class=" line-clamp-2" x-text="hotelData?.address || hotelData?.location"></span>
                                </p>
                            </div>
                        </div>

                        <!-- Check-in/out & Nights -->
                        <div class="flex flex-wrap items-center justify-between text-xs bg-white dark:bg-gray-700 rounded-md p-2 mb-2">
                            <div class="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                                <span class="material-symbols-outlined !text-sm text-blue-600">login</span>
                                <span class="font-medium"><?= date('d M Y', strtotime($checkin)) ?></span>
                            </div>
                            <div class="flex items-center gap-1 px-2 py-0.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-full font-semibold">
                                <span class="material-symbols-outlined !text-sm">nights_stay</span>
                                <span><?= $nights ?> <?= T::night ?><?= $nights > 1 ? T::s : '' ?></span>
                            </div>
                            <div class="flex items-center gap-1 text-gray-700 dark:text-gray-300">
                                <span class="material-symbols-outlined !text-sm text-red-600">logout</span>
                                <span class="font-medium"><?= date('d M Y', strtotime($checkout)) ?></span>
                            </div>
                        </div>

                        <!-- Nationality -->
                        <div class="flex items-center gap-2 text-xs bg-white dark:bg-gray-700 rounded-md p-2 mb-3">
                            <span class="material-symbols-outlined text-blue-600 !text-sm">flag</span>
                            <span class="text-gray-600 dark:text-gray-400"><?= T::nationality ?>:</span>
                            <span class="font-semibold text-blue-700 dark:text-blue-300">
                                <?php
                                $nationalityCountry = array_filter($countries, function($country) use ($nationality) {
                                    return $country['iso'] === $nationality;
                                });
                                $nationalityCountry = reset($nationalityCountry);
                                echo $nationalityCountry ? $nationalityCountry['nicename'] : $nationality;
                                ?>
                            </span>
                        </div>

                        <!-- Selected Rooms Section -->
                        <div class="pt-3 border-t border-blue-200 dark:border-gray-600">
                            <h4 class="text-xs font-bold text-gray-700 dark:text-gray-300 mb-3 uppercase tracking-wide flex items-center gap-1">
                                <span class="material-symbols-outlined !text-sm">bed</span>
                                <?= T::selected_rooms ?>
                            </h4>
                            <div class="space-y-2">
                        <template x-for="(room, index) in selectedRoomsData" :key="index">
                            <div class="p-3 bg-white dark:bg-blue-800 rounded-lg border border-blue-200 dark:border-blue-700">
                                <div class="flex justify-between items-start mb-2">
                                    <h4 class="font-semibold text-sm text-gray-900 dark:text-gray-100" x-text="room.room_name"></h4>
                                    <span class="text-xs text-gray-600 dark:text-gray-400"
                                          x-text="isGrnPackage(room) ? `${room.quantity} rooms` : `×${room.quantity}`"></span>
                                </div>

                                <div class="space-y-1 text-xs text-gray-600 dark:text-gray-400">
                                    <div class="flex justify-between">
                                        <span><?= T::price ?> <?= T::per ?> <?= T::night ?>:</span>
                                        <span x-text="`${getCurrencySymbol()}${convertToDisplay(parseFloat(room.option.price_per_room || room.option.price_per_night)).toFixed(2)}`"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span><?= T::nights ?>:</span>
                                        <span><?= $nights ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span><?= T::quantity ?>:</span>
                                        <span x-text="room.quantity"></span>
                                    </div>
                                    <div class="flex justify-between font-semibold text-gray-900 dark:text-gray-100 pt-2 border-t border-gray-200 dark:border-gray-600">
                                        <span><?= T::subtotal ?>:</span>
                                        <?php if ($isGrnConnect): ?>
                                            <span x-text="`${getCurrencySymbol()}${convertToDisplay(getSelectedRoomSubtotal(room)).toFixed(2)}`"></span>
                                        <?php else: ?>
                                            <span x-text="`${getCurrencySymbol()}${convertToDisplay(parseFloat(room.option.total_price || 0) * room.quantity).toFixed(2)}`"></span>
                                        <?php endif ?>
                                      
                                    </div>
                                </div>

                                <div x-show="isGrnPackage(room) && ((room.option.package_rooms || room.option.rate_rooms || []).length)"
                                     class="mt-3 rounded-lg border border-blue-200 bg-blue-50/70 p-2.5">
                                    <p class="text-[11px] font-semibold text-blue-800 mb-1.5">Package rooms</p>
                                    <template x-for="(packageRoom, packageRoomIndex) in (room.option.package_rooms || room.option.rate_rooms || [])"
                                              :key="`checkout-package-${index}-${packageRoomIndex}`">
                                        <div class="py-1.5 border-t border-blue-100 first:border-t-0 text-xs">
                                            <p class="font-medium text-gray-800"
                                               x-text="`Room ${packageRoomIndex + 1}: ${packageRoom.room_type || packageRoom.description || 'Room'}`"></p>
                                            <p x-show="packageRoom.pax_label"
                                               class="text-[11px] text-gray-500"
                                               x-text="packageRoom.pax_label"></p>
                                        </div>
                                    </template>
                                </div>

                                <div x-show="room.option.excluded_taxes && room.option.excluded_taxes.length" class="pt-2 mt-2 border-t border-dashed border-gray-200 dark:border-gray-600">
                                    <p class="text-xs font-medium text-red-600 dark:text-red-400 mb-1">
                                        <?= T::excluded_taxes ?? 'Excluded Taxes (Payable at hotel)' ?>
                                    </p>

                                    <template x-for="(tax, tIndex) in room.option.excluded_taxes" :key="tIndex">
                                        <div class="flex justify-between text-xs text-gray-600 dark:text-gray-400">
                                            <span x-text="tax.subType"></span>
                                            <span 
                                                x-text="`${getCurrencySymbol(tax.clientCurrency)}${parseFloat(tax.clientAmount).toFixed(2)}`">
                                            </span>
                                        </div>
                                    </template>
                                </div>

                                <!-- Room Features -->
                                <div class="mt-2 pt-2 dark:border-gray-600 flex flex-wrap gap-1">
                                    <span
                                        x-show="room.option.board_name"
                                        class="text-xs px-2 py-0.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded font-medium"
                                        x-text="room.option.board_name
                                    .toLowerCase()
                                    .replace(/\b\w/g, char => char.toUpperCase())">
                                    </span>
                                    <span x-show="room.option.breakfast_included" class="text-xs px-2 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded">
                                        <?= T::breakfast ?>
                                    </span>
                                    <?php if ($isGrnConnect): ?>
                                    <span class="text-xs px-2 py-0.5 rounded font-semibold"
                                          :class="getRefundabilityClass(room.option)"
                                          x-text="getRefundabilityLabel(room.option)"></span>
                                    <?php else: ?>
                                    <span x-show="room.option.refundable" class="text-xs px-2 py-0.5 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded">
                                        <?= T::refundable ?>
                                    </span>
                                    <?php endif; ?>
                                    <span x-show="room.option.cancellation_free" class="text-xs px-2 py-0.5 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 rounded">
                                        <?= T::free ?> <?= T::cancellation ?>
                                    </span>
                                    <?php if (!$isGrnConnect): ?>
                                    <span x-show="room.option.has_promotion || room.option.discount_percentage > 0"
                                          class="text-xs px-2 py-0.5 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded font-semibold"
                                          :title="room.option.promotion_text || ''"
                                          x-text="room.option.promotion_label || (`${room.option.discount_percentage}% <?= T::off ?? 'off' ?>`)"></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </template>
                            </div>
                        </div>
                    </div>

                    <!-- Price Breakdown -->
                    <div class="pt-4 border rounded-lg p-4 dark:border-gray-700 space-y-2 text-sm">
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::rooms ?> <?= T::total ?>:</span>
                            <span x-text="`${getCurrencySymbol()}${calculateSubtotal().toFixed(2)}`"></span>
                        </div>
                        <div class="flex justify-between text-gray-600 dark:text-gray-400">
                            <span><?= T::taxes ?> & <?= T::fees ?>:</span>
                            <span>
                                <?php if ($hasTax): ?>
                                    <span x-text="`${getCurrencySymbol()}${calculateTaxAmount().toFixed(2)}`"></span>
                                <?php else: ?>
                                    <?= T::included ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <!-- Promo Code Discount -->
                        <div class="flex justify-between text-green-600 dark:text-green-400" x-show="promoApplied" x-cloak>
                            <span class="flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm">confirmation_number</span>
                                <?= T::promo_code ?>: <span class="font-mono font-semibold" x-text="promoCode"></span>
                            </span>
                            <span class="font-semibold" x-text="`-${getCurrencySymbol()}${promoDiscountDisplay.toFixed(2)}`"></span>
                        </div>
                        <div class="flex justify-between text-lg font-bold text-gray-900 dark:text-gray-100 pt-2 border-t border-gray-200 dark:border-gray-700">
                            <span><?= T::total ?>:</span>
                            <div class="text-right">
                                <span class="text-lg font-bold" x-text="`${getCurrencySymbol()}${calculateFinalTotal().toFixed(2)}`"></span>
                                <div class="text-sm text-blue-600 font-medium mt-1" x-show="baseCurrency !== displayCurrency">
                                    <?= T::you_will_be_charged ?>: <span x-text="`${getBaseCurrencySymbol()}${calculateFinalTotalBase().toFixed(2)}`"></span>
                                </div>
                                <div class="text-xs text-gray-500 mt-1" x-show="baseCurrency !== displayCurrency">
                                    <?= T::all_payments_processed_in ?> <span x-text="baseCurrency"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Promo Code Component -->
                    <?php include 'app/views/components/promo-code.php'; ?>

                    <!-- Important Notes -->
                    <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                        <div class="flex gap-2">
                            <span class="material-symbols-outlined text-blue-600 dark:text-blue-400 flex-shrink-0 text-xl">info</span>
                            <div class="text-xs text-blue-900 dark:text-blue-100 space-y-1">
                                <p>✓ <?= T::confirmation ?> <?= T::will_be_sent ?> <?= T::to ?> <?= T::email ?></p>
                                <p>✓ <?= T::payment ?> <?= T::is ?> <?= T::secure ?></p>
                                <p>✓ <?= T::no ?> <?= T::hidden ?> <?= T::charges ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- Close right column -->

    </div><!-- Close grid -->

</div><!-- Close min-h-screen wrapper -->

<style>
    footer, header, .cart-button {
        display: none;
    }
</style>

<script>
// ============================================================================
// ALPINE.JS BOOKING FORM COMPONENT
// ============================================================================
// THIS IS THE MAIN JAVASCRIPT COMPONENT THAT HANDLES:
//   - FORM DATA MANAGEMENT (REACTIVE STATE)
//   - HOTEL DATA FETCHING AND DISPLAY
//   - TRAVELER DATA INITIALIZATION
//   - LEAD TRAVELER SYNCHRONIZATION WITH GUEST DETAILS
//   - CURRENCY CONVERSION CALCULATIONS
//   - FORM VALIDATION AND SUBMISSION
//   - ERROR AND SUCCESS MESSAGE HANDLING
//   - COUNTDOWN REDIRECT AFTER SUCCESSFUL BOOKING
//
// ALPINE.JS REACTIVITY:
//   - ALL PROPERTIES ARE REACTIVE (AUTO-UPDATE UI)
//   - $watch() IS USED TO OBSERVE CHANGES
//   - x-model CREATES TWO-WAY DATA BINDING
//   - x-show/x-if FOR CONDITIONAL RENDERING
//
// DATA FLOW:
//   1. COMPONENT INITIALIZES WITH PHP DATA
//   2. fetchHotelData() LOADS HOTEL INFORMATION
//   3. initializeTravelers() CREATES TRAVELER OBJECTS
//   4. USER FILLS FORM (REACTIVE UPDATES)
//   5. submitBooking() SENDS DATA TO API
//   6. SUCCESS: COUNTDOWN AND REDIRECT TO INVOICE
// ============================================================================
function bookingForm() {
    return {
        // ====================================================================
        // UI STATE MANAGEMENT
        // ====================================================================
        loading: true,              // SHOWS SKELETON LOADER WHILE FETCHING DATA
        submitting: false,          // DISABLES SUBMIT BUTTON DURING PROCESSING
        showAlert: false,           // TOGGLES ALERT MESSAGE VISIBILITY
        alertType: 'error',         // 'error' OR 'success' FOR MESSAGE STYLING
        alertMessage: '',           // TEXT TO DISPLAY IN ALERT
        showBookingLoader: false,   // SHOWS FULL-PAGE LOADING OVERLAY DURING BOOKING

        // ====================================================================
        // BOOKING DATA (FROM PHP SESSION)
        // ====================================================================
        hotelData: null,            // HOTEL DETAILS (NAME, IMAGE, ADDRESS, STARS)
        selectedRoomsData: <?= json_encode($selectedRooms) ?>, // ARRAY OF SELECTED ROOM OPTIONS
        bookingHash: '<?= $bookingHash ?>', // UNIQUE BOOKING IDENTIFIER
        hotelName: '<?= $hotelName ?>',     // HOTEL DISPLAY NAME
        supplier: '<?= $supplier ?>',       // API PROVIDER (hotelbeds, custom, etc.)
        isGrnConnect: <?= $isGrnConnect ? 'true' : 'false' ?>,
        <?php if ($isGrnConnect): ?>
        grnRecheckLoading: false,
        grnRecheckDone: false,
        grnRecheckError: '',
        grnRecheck: null,
        grnRechecks: [],
        grnPriceChanged: false,
        grnPriceAccepted: false,
        grnPreviousPrice: 0,
        grnUpdatedPrice: 0,
        grnPriceCurrency: '<?= $currency ?>',
        grnPriceChangeMessage: '',
        grnPendingRoomsUpdate: null,
        grnPendingTotals: null,
        grnUnavailable: false,
        <?php endif; ?>
        nights: <?= $nights ?>,             // NUMBER OF NIGHTS FOR STAY

        // ====================================================================
        // CURRENCY AND PRICING
        // ====================================================================
        // WHY TWO CURRENCIES?
        //   - baseCurrency: USED FOR PAYMENT PROCESSING (USD, EUR, etc.)
        //   - displayCurrency: SHOWN TO USER (CAN BE DIFFERENT)
        //   - conversionRate: CONVERTS BASE TO DISPLAY
        // EXAMPLE: BASE=USD, DISPLAY=PKR, RATE=280
        //   - User sees: PKR 28,000
        //   - System charges: USD 100
        // ====================================================================
        currency: '<?= $currency ?>',       // ORIGINAL CURRENCY FROM API
        displayCurrency: '<?= $displayCurrencyCode ?>',  // USER'S PREFERRED CURRENCY
        baseCurrency: '<?= $baseCurrencyCode ?>',        // SYSTEM BASE CURRENCY
        conversionRate: <?= $conversionRate ?>,          // CONVERSION MULTIPLIER
        totalAmount: <?= $totalAmount ?>,
        totalAmountDisplay: <?= $totalAmountDisplay ?>,
        totalAmountBase: <?= $totalAmountBase ?>,
        taxAmount: <?= $taxAmountBase ?>,
        taxAmountDisplay: <?= $taxAmountDisplay ?>,
        hasTax: <?= $hasTax ? 'true' : 'false' ?>,
        totalWithTax: <?= $totalWithTaxBase ?>,
        totalWithTaxDisplay: <?= $totalWithTaxDisplay ?>,
        nights: <?= $nights ?>,

        // Promo Code
        promoCode: '',
        promoApplied: false,
        promoDiscount: 0,
        promoDiscountDisplay: 0,
        promoMessage: '',
        promoLoading: false,
        promoError: false,

        bookingType: 'guest',
        isLoggingIn: false,

        formData: {
            primary_guest: {
                title: '<?= $isUserLoggedIn && !empty($loggedInUser['title']) ? $loggedInUser['title'] : '' ?>',
                first_name: '<?= $isUserLoggedIn ? addslashes($loggedInUser['first_name']) : '' ?>',
                last_name: '<?= $isUserLoggedIn ? addslashes($loggedInUser['last_name']) : '' ?>',
                email: '<?= $isUserLoggedIn ? addslashes($loggedInUser['email']) : '' ?>',
                country_code: '<?= $isUserLoggedIn && !empty($loggedInUser['phone_country_code']) ? $loggedInUser['phone_country_code'] : $nationality ?>',
                phone: '<?= $isUserLoggedIn && !empty($loggedInUser['phone']) ? addslashes($loggedInUser['phone']) : '' ?>'
            },
            booking_for_someone_else: false,
            travelers: {},
            selected_payment: <?= json_encode($defaultGatewayId) ?>,
            special_requests: '',
            terms_accepted: false
        },

        quickLogin() {
            const email = document.getElementById('quick_login_email').value;
            const password = document.getElementById('quick_login_password').value;

            if (!email || !password) {
                alert('<?= T::please_enter_both_email_and_password ?>');
                return;
            }

            // Show loading state
            this.isLoggingIn = true;

            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?= root ?>api/booking/quick-login';

            // Add CSRF token
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = document.querySelector('input[name=csrf_token]').value;
            form.appendChild(csrfInput);

            // Add email
            const emailInput = document.createElement('input');
            emailInput.type = 'hidden';
            emailInput.name = 'email';
            emailInput.value = email;
            form.appendChild(emailInput);

            // Add password
            const passwordInput = document.createElement('input');
            passwordInput.type = 'hidden';
            passwordInput.name = 'password';
            passwordInput.value = password;
            form.appendChild(passwordInput);

            // Add redirect
            const redirectInput = document.createElement('input');
            redirectInput.type = 'hidden';
            redirectInput.name = 'redirect_to';
            redirectInput.value = window.location.href;
            form.appendChild(redirectInput);

            document.body.appendChild(form);
            form.submit();
        },

        // ====================================================================
        // INITIALIZATION METHOD - CALLED WHEN COMPONENT MOUNTS
        // ====================================================================
        // EXECUTION ORDER:
        //   1. fetchHotelData() - LOAD HOTEL INFORMATION FROM SESSION
        //   2. initializeTravelers() - CREATE TRAVELER INPUT STRUCTURE
        //   3. $nextTick() - WAIT FOR DOM UPDATES
        //   4. syncLeadTravelerWithGuest() - COPY GUEST DATA TO ROOM 0 ADULT 0
        //   5. setupLeadTravelerWatchers() - OBSERVE CHANGES FOR AUTO-SYNC
        // ====================================================================
        handleGuestUpdate(data) {
            if (data.primary_guest) {
                // Keep local primary_guest in sync for submission
                this.formData.primary_guest = { ...data.primary_guest };
            }
            if (typeof data.booking_for_someone_else !== 'undefined') {
                this.formData.booking_for_someone_else = data.booking_for_someone_else;
            }
            // Trigger lead traveler sync
            this.syncLeadTravelerWithGuest();
        },

        init() {
            this.fetchHotelData();
            this.initializeTravelers();
            this.$nextTick(() => {
                this.syncLeadTravelerWithGuest();
                this.setupLeadTravelerWatchers();
            });
            if (this.isGrnConnect) {
                // Always verify rate on booking page load (GRN requires recheck before book).
                this.$nextTick(() => this.runGrnRecheck());
            }
        },

        <?php if ($isGrnConnect): ?>
        hasValidGrnRecheck() {
            const r = this.grnRecheck;
            return !!(
                this.grnRecheckDone
                && r
                && typeof r === 'object'
                && String(r.search_id || '').trim()
                && String(r.rate_key || '').trim()
                && String(r.group_code || '').trim()
            );
        },

        buildGrnRoomsReturnUrl() {
            const bookingData = <?= json_encode($bookingData) ?>;
            const hotelName = (bookingData.hotel_name || 'hotel').toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '') || 'hotel';
            const hotelId = bookingData.hotel_id || '';
            const supplier = (bookingData.supplier || 'grnconnect').toLowerCase();
            const chain = '_';
            const checkin = bookingData.checkin || '';
            const checkout = bookingData.checkout || '';
            const nationality = (bookingData.nationality || 'US').toUpperCase();
            const roomsData = Array.isArray(bookingData.rooms_data) ? bookingData.rooms_data : [];
            const totalRooms = roomsData.length || Number(bookingData.rooms_count || 1) || 1;
            const roomConfigs = roomsData.length
                ? roomsData.map(r => {
                    const ages = (r.childAges && r.childAges.length) ? '-' + r.childAges.join('-') : '';
                    return `${r.adults || 2}-${r.children || 0}${ages}`;
                }).join('/')
                : '2-0';
            return `<?= root ?>stay/${hotelName}/${hotelId}/${supplier}/${chain}/${checkin}/${checkout}/${nationality}/${totalRooms}/${roomConfigs}`;
        },

        returnToGrnRoomSelection(message) {
            const msg = message || 'Sorry, this room is no longer available. Please select another option.';
            this.showError(msg);
            setTimeout(() => {
                window.location.href = this.buildGrnRoomsReturnUrl();
            }, 1800);
        },

        /**
         * Keep sidebar unit prices in sync with recheck total_price.
         * Updates: price_per_room, price_per_room_per_night, price_per_night.
         */
        syncGrnOptionUnitPrices(option) {
            if (!option || typeof option !== 'object') return option;
            const nights = Math.max(1, parseInt(this.nights, 10) || 1);
            const roomsInRate = Math.max(1, parseInt(option.rooms_in_rate || option.allocation_size || 1, 10) || 1);
            const total = parseFloat(option.total_price);
            if (!(total > 0)) return option;

            const pricePerRoom = Math.round((total / roomsInRate) * 100) / 100;
            const pricePerRoomPerNight = Math.round((total / roomsInRate / nights) * 100) / 100;
            const pricePerNight = Math.round((total / nights) * 100) / 100;

            return {
                ...option,
                price_per_room: pricePerRoom,
                price_per_room_per_night: pricePerRoomPerNight,
                price_per_night: pricePerNight,
            };
        },

        applyGrnVerifiedRoomPrices(rooms) {
            if (!Array.isArray(rooms)) return rooms;
            return rooms.map(room => {
                if (!room?.option) return room;
                const option = this.syncGrnOptionUnitPrices(room.option);
                return {
                    ...room,
                    option,
                    selected_rate: { ...(room.selected_rate || {}), ...option },
                };
            });
        },

        acceptGrnPriceChange() {
            if (!this.grnPriceChanged || this.grnPriceAccepted) return;

            // Apply pending room/price updates only after explicit accept
            if (this.grnPendingRoomsUpdate) {
                this.selectedRoomsData = this.applyGrnVerifiedRoomPrices(this.grnPendingRoomsUpdate);
                this.grnPendingRoomsUpdate = null;
            } else {
                // Fallback: recompute unit prices on current rooms from total_price
                this.selectedRoomsData = this.applyGrnVerifiedRoomPrices(this.selectedRoomsData);
            }
            if (this.grnPendingTotals) {
                const t = this.grnPendingTotals;
                this.totalAmountBase = t.totalAmountBase;
                this.totalAmount = t.totalAmount;
                this.totalAmountDisplay = t.totalAmountDisplay;
                if (typeof t.taxAmount !== 'undefined') {
                    this.taxAmount = t.taxAmount;
                    this.taxAmountDisplay = t.taxAmountDisplay;
                    this.totalWithTax = t.totalWithTax;
                    this.totalWithTaxDisplay = t.totalWithTaxDisplay;
                }
                this.grnPendingTotals = null;
            }

            this.grnPriceAccepted = true;
            if (this.grnRecheck && typeof this.grnRecheck === 'object') {
                this.grnRecheck = {
                    ...this.grnRecheck,
                    price_accepted: true,
                    price_changed: true,
                    previous_price: this.grnPreviousPrice,
                    updated_price: this.grnUpdatedPrice,
                    accepted_net_price: this.grnRecheck.accepted_net_price || this.grnUpdatedPrice,
                    currency: this.grnPriceCurrency,
                    accepted_at: new Date().toISOString(),
                };
            }
            this.showSuccess('Updated price accepted. You may continue with booking.');
        },

        rejectGrnPriceChange() {
            this.grnPendingRoomsUpdate = null;
            this.grnPendingTotals = null;
            this.grnPriceAccepted = false;
            window.location.href = this.buildGrnRoomsReturnUrl();
        },

        async runGrnRecheck(force = false) {
            // Booking page MUST call GRN recheck once per rate_key before book.
            // Share one in-flight promise so Alpine remounts cannot skip the API call.
            if (!window.__grnRecheckPromises) {
                window.__grnRecheckPromises = {};
            }
            const hash = this.bookingHash || 'grn';

            if (!force && this.hasValidGrnRecheck()) {
                return true;
            }

            if (!force && window.__grnRecheckPromises[hash]) {
                try {
                    const shared = await window.__grnRecheckPromises[hash];
                    if (shared && shared.ok) {
                        this.applyGrnRecheckResult(shared.data, shared.recheckedRooms);
                        return this.hasValidGrnRecheck();
                    }
                } catch (e) {
                    // Continue with a fresh attempt below.
                }
            }

            this.grnRecheckLoading = true;
            this.grnRecheckDone = false;
            this.grnRecheckError = '';
            this.grnPriceChanged = false;
            this.grnPriceAccepted = false;
            this.grnUnavailable = false;
            this.grnPendingRoomsUpdate = null;
            this.grnPendingTotals = null;

            const run = (async () => {
                const bookingData = <?= json_encode($bookingData) ?>;
                const response = await fetch('<?= root ?>modules/stays/grnconnect/recheck', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        hotel_id: bookingData.hotel_id,
                        checkin: bookingData.checkin,
                        checkout: bookingData.checkout,
                        nationality: bookingData.nationality,
                        currency: this.baseCurrency,
                        nights: this.nights,
                        rooms_data: bookingData.rooms_data || [],
                        selected_rooms: this.selectedRoomsData
                    })
                });

                const data = await response.json();

                if (!data.success) {
                    const err = new Error(data.message || 'Rate verification failed');
                    err.unavailable = !!(data.unavailable || data.error_code === 'rate_unavailable');
                    throw err;
                }

                const recheckedRooms = Array.isArray(data.data?.rooms) && data.data.rooms.length
                    ? data.data.rooms
                    : (data.data?.option ? [{ index: 0, option: data.data.option, grn_recheck: data.data.grn_recheck, price_changed: data.data.price_changed }] : []);

                if (!recheckedRooms.length) {
                    const err = new Error('Sorry, this room is no longer available. Please select another option.');
                    err.unavailable = true;
                    throw err;
                }

                const grnRecheck = data.data?.grn_recheck || null;
                if (!grnRecheck || !String(grnRecheck.search_id || '').trim()
                    || !String(grnRecheck.rate_key || '').trim()
                    || !String(grnRecheck.group_code || '').trim()) {
                    throw new Error('Rate verification did not return a valid GRN recheck result.');
                }

                return { ok: true, data: data.data, recheckedRooms };
            })();

            window.__grnRecheckPromises[hash] = run;

            try {
                const result = await run;
                this.applyGrnRecheckResult(result.data, result.recheckedRooms);
                return this.hasValidGrnRecheck();
            } catch (err) {
                console.error('GRN recheck failed:', err);
                const msg = err.message || 'Unable to verify rate with supplier.';
                this.grnRecheckError = msg;
                this.grnRecheckDone = false;
                this.grnRecheck = null;
                this.grnRechecks = [];
                if (err.unavailable || /no longer available|not available|unavailable/i.test(msg)) {
                    this.grnUnavailable = true;
                    this.returnToGrnRoomSelection(msg);
                }
                return false;
            } finally {
                this.grnRecheckLoading = false;
                this.loading = false;
                if (window.__grnRecheckPromises && window.__grnRecheckPromises[hash] === run) {
                    delete window.__grnRecheckPromises[hash];
                }
            }
        },

        applyGrnRecheckResult(data, recheckedRooms) {
            this.grnRecheck = data.grn_recheck || null;
            this.grnRechecks = data.grn_rechecks || (this.grnRecheck ? [this.grnRecheck] : []);
            this.grnPriceChanged = !!data.price_changed;
            this.grnPreviousPrice = parseFloat(data.previous_price ?? data.price_comparison?.previous_price ?? 0) || 0;
            this.grnUpdatedPrice = parseFloat(data.updated_price ?? data.price_comparison?.updated_price ?? 0) || 0;
            this.grnPriceCurrency = data.price_currency || data.price_comparison?.currency || this.baseCurrency || this.currency;
            this.grnPriceChangeMessage = data.price_comparison?.message
                || (this.grnPriceChanged ? 'The price has changed. Please review the updated price before continuing.' : '');
            this.grnRecheckError = '';
            this.grnUnavailable = false;
            // Only mark done when identifiers required for booking are present.
            this.grnRecheckDone = !!(
                this.grnRecheck
                && String(this.grnRecheck.search_id || '').trim()
                && String(this.grnRecheck.rate_key || '').trim()
                && String(this.grnRecheck.group_code || '').trim()
            );

            const previousTotal = parseFloat(this.totalAmountBase || 0);
            if (!this.grnPreviousPrice && previousTotal > 0) {
                this.grnPreviousPrice = previousTotal;
            }

            const byToken = {};
            (recheckedRooms || []).forEach(item => {
                const token = item.option?.rate_key || item.rate_key || '';
                if (token) byToken[token] = item;
            });

            const updatedRooms = this.selectedRoomsData.map((room, index) => {
                const roomIndex = Number(room.room_index || (index + 1));
                let verified = recheckedRooms.find(item => Number(item.room_index) === roomIndex)
                    || recheckedRooms.find(item => Number(item.index) === index);

                if (!verified && room.option?.rate_key && byToken[room.option.rate_key]) {
                    verified = byToken[room.option.rate_key];
                }
                if (!verified && room.primary === false && room.covers_from) {
                    const primary = this.selectedRoomsData.find(r =>
                        Number(r.room_index) === Number(room.covers_from) && r.primary !== false
                    );
                    if (primary?.option?.rate_key && byToken[primary.option.rate_key]) {
                        verified = byToken[primary.option.rate_key];
                    }
                }

                if (!verified?.option) return room;
                const mergedOption = this.syncGrnOptionUnitPrices({
                    ...room.option,
                    ...verified.option,
                });
                return {
                    ...room,
                    option: mergedOption,
                    selected_rate: { ...(room.selected_rate || room.option || {}), ...mergedOption },
                };
            });

            const seenPrices = new Set();
            const verifiedTotal = updatedRooms.reduce((sum, room) => {
                if (room.primary === false) return sum;
                const token = room.option?.rate_key || room.option?.option_id || '';
                if (token && seenPrices.has(token)) return sum;
                if (token) seenPrices.add(token);
                return sum + (parseFloat(room.option?.total_price || 0) || 0);
            }, 0);

            const pendingTotals = verifiedTotal > 0 ? {
                totalAmountBase: verifiedTotal,
                totalAmount: verifiedTotal,
                totalAmountDisplay: verifiedTotal * this.conversionRate,
            } : null;
            if (pendingTotals && this.hasTax) {
                const taxCalc = this.taxAmount / Math.max(previousTotal, 1);
                pendingTotals.taxAmount = verifiedTotal * taxCalc;
                pendingTotals.taxAmountDisplay = pendingTotals.taxAmount * this.conversionRate;
                pendingTotals.totalWithTax = verifiedTotal + pendingTotals.taxAmount;
                pendingTotals.totalWithTaxDisplay = pendingTotals.totalWithTax * this.conversionRate;
            }

            if (this.grnPriceChanged) {
                this.grnPendingRoomsUpdate = updatedRooms;
                this.grnPendingTotals = pendingTotals;
                if (this.grnRecheck && typeof this.grnRecheck === 'object') {
                    this.grnRecheck = {
                        ...this.grnRecheck,
                        price_changed: true,
                        price_accepted: false,
                        previous_price: this.grnPreviousPrice,
                        updated_price: this.grnUpdatedPrice,
                        currency: this.grnPriceCurrency,
                    };
                }
            } else {
                this.selectedRoomsData = this.applyGrnVerifiedRoomPrices(updatedRooms);
                if (pendingTotals) {
                    this.totalAmountBase = pendingTotals.totalAmountBase;
                    this.totalAmount = pendingTotals.totalAmount;
                    this.totalAmountDisplay = pendingTotals.totalAmountDisplay;
                    if (typeof pendingTotals.taxAmount !== 'undefined') {
                        this.taxAmount = pendingTotals.taxAmount;
                        this.taxAmountDisplay = pendingTotals.taxAmountDisplay;
                        this.totalWithTax = pendingTotals.totalWithTax;
                        this.totalWithTaxDisplay = pendingTotals.totalWithTaxDisplay;
                    }
                }
                this.grnPriceAccepted = true;
                if (this.grnRecheck && typeof this.grnRecheck === 'object') {
                    this.grnRecheck = {
                        ...this.grnRecheck,
                        price_changed: false,
                        price_accepted: true,
                        previous_price: this.grnPreviousPrice,
                        updated_price: this.grnUpdatedPrice || previousTotal,
                        accepted_net_price: this.grnRecheck.accepted_net_price
                            || this.grnUpdatedPrice
                            || previousTotal,
                        currency: this.grnPriceCurrency,
                    };
                }
            }
        },
        <?php endif; ?>

        // ====================================================================
        // FETCH HOTEL DATA FROM BOOKING SESSION
        // ====================================================================
        // WHY NOT API CALL?
        //   - DATA ALREADY AVAILABLE IN bookingData SESSION
        //   - FASTER PAGE LOAD (NO NETWORK REQUEST)
        //   - CONSISTENT WITH WHAT USER SELECTED
        //
        // HOTEL DATA INCLUDES:
        //   - name: HOTEL NAME
        //   - images: ARRAY OF IMAGE URLS
        //   - address: PHYSICAL ADDRESS OR LOCATION
        //   - stars: STAR RATING (0-5)
        // ====================================================================
        async fetchHotelData() {
            try {
                // Use hotel data from booking session
                const bookingData = <?= json_encode($bookingData) ?>;

                this.hotelData = {
                    name: this.hotelName,
                    images: bookingData.hotel_images || [],
                    image: (bookingData.hotel_images && bookingData.hotel_images[0]) || null,
                    address: bookingData.hotel_address || '',
                    location: bookingData.hotel_address || '',
                    stars: bookingData.hotel_stars || 0
                };

                console.log('Hotel data loaded:', this.hotelData);
            } catch (err) {
                console.error('Error loading hotel data:', err);
                // Use fallback data
                this.hotelData = {
                    name: this.hotelName,
                    images: [],
                    address: '',
                    stars: 0
                };
            }
            this.loading = false;
        },

        // ====================================================================
        // INITIALIZE TRAVELER DATA STRUCTURE
        // ====================================================================
        // PURPOSE: CREATE REACTIVE OBJECTS FOR EACH TRAVELER INPUT
        //
        // STRUCTURE CREATED:
        //   travelers = {
        //     room_0: {
        //       adult_0: { title: '', first_name: '', last_name: '' },
        //       adult_1: { title: '', first_name: '', last_name: '' },
        //       child_0: { title: '', first_name: '', last_name: '', age: 5 }
        //     },
        //     room_1: {
        //       adult_0: { title: '', first_name: '', last_name: '' }
        //     }
        //   }
        //
        // WHY THIS STRUCTURE?
        //   - ALLOWS x-model BINDING: formData.travelers.room_0.adult_0.first_name
        //   - EASY VALIDATION: LOOP THROUGH ROOMS AND TRAVELERS
        //   - CLEAR ORGANIZATION: MATCHES PHP LOOP IN HTML
        // ====================================================================
        initializeTravelers() {
            // LOAD ROOM CONFIGURATION FROM PHP
            const roomsData = <?= json_encode($roomsData) ?>;

            roomsData.forEach((room, roomIndex) => {
                // LOOP THROUGH ADULTS IN THIS ROOM
                for (let adultIndex = 0; adultIndex < room.adults; adultIndex++) {
                    if (!this.formData.travelers[`room_${roomIndex}`]) {
                        this.formData.travelers[`room_${roomIndex}`] = {};
                    }

                    this.formData.travelers[`room_${roomIndex}`][`adult_${adultIndex}`] = {
                        title: 'Mr.',
                        first_name: '',
                        last_name: ''
                    };
                }

                // Initialize children for each room
                for (let childIndex = 0; childIndex < room.children; childIndex++) {
                    if (!this.formData.travelers[`room_${roomIndex}`]) {
                        this.formData.travelers[`room_${roomIndex}`] = {};
                    }

                    this.formData.travelers[`room_${roomIndex}`][`child_${childIndex}`] = {
                        title: 'Mstr.',
                        first_name: '',
                        last_name: '',
                        age: room.childAges[childIndex] || 1
                    };
                }
            });
        },

        setupLeadTravelerWatchers() {
            this.$watch('formData.booking_for_someone_else', value => {
                if (!value) {
                    this.syncLeadTravelerWithGuest();
                }
            });

            ['title', 'first_name', 'last_name'].forEach(field => {
                this.$watch(`formData.primary_guest.${field}`, () => {
                    if (!this.formData.booking_for_someone_else) {
                        this.syncLeadTravelerWithGuest();
                    }
                });
            });
        },

        ensureLeadTravelerEntry() {
            if (!this.formData.travelers['room_0']) {
                this.formData.travelers['room_0'] = {};
            }
            if (!this.formData.travelers['room_0']['adult_0']) {
                this.formData.travelers['room_0']['adult_0'] = {
                    title: '',
                    first_name: '',
                    last_name: ''
                };
            }
            return this.formData.travelers['room_0']['adult_0'];
        },

        syncLeadTravelerWithGuest() {
            const leadTraveler = this.ensureLeadTravelerEntry();
            leadTraveler.title = this.formData.primary_guest.title || '';
            leadTraveler.first_name = this.formData.primary_guest.first_name || '';
            leadTraveler.last_name = this.formData.primary_guest.last_name || '';
        },

        getTotalGuests() {
            return this.selectedRoomsData.reduce((total, room) => {
                return total + (room.option.max_adults * room.quantity);
            }, 0);
        },

        getCurrencySymbol(currencyCode = null) {
            const currency = currencyCode || this.displayCurrency;
            return currency + ' ';
        },

        <?php if ($isGrnConnect): ?>
        getRefundabilityLabel(option = {}) {
            if (option.refundability_label) return option.refundability_label;
            const tag = option.refundability_tag
                || (option.non_cancellable ? 'non_cancellable'
                    : (option.non_refundable ? 'non_refundable'
                        : (option.partially_refundable ? 'partially_refundable'
                            : (option.refundable ? 'refundable' : 'non_refundable'))));
            if (tag === 'non_cancellable') return '<?= defined("T::non_cancellable") ? T::non_cancellable : "Non-Cancellable" ?>';
            if (tag === 'partially_refundable') return '<?= defined("T::partially_refundable") ? T::partially_refundable : "Partially refundable" ?>';
            if (tag === 'non_refundable') return '<?= defined("T::non_refundable") ? T::non_refundable : "Non-refundable" ?>';
            if (tag === 'refundable') return '<?= defined("T::fully_refundable") ? T::fully_refundable : (defined("T::refundable") ? T::refundable : "Fully refundable") ?>';
            return '<?= defined("T::non_refundable") ? T::non_refundable : "Non-refundable" ?>';
        },

        getRefundabilityClass(option = {}) {
            const tag = option.refundability_tag
                || (option.non_cancellable ? 'non_cancellable'
                    : (option.non_refundable ? 'non_refundable'
                        : (option.partially_refundable ? 'partially_refundable'
                            : (option.refundable ? 'refundable' : 'non_refundable'))));
            if (tag === 'refundable') return 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300';
            if (tag === 'partially_refundable') return 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300';
            if (tag === 'non_cancellable') return 'bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-300';
            return 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300';
        },

        hasStructuredCancellation(option = {}) {
            const cd = option.cancellation_details || {};
            return !!(
                this.isRefundableStatus(option)
                || this.isPartiallyRefundableStatus(option)
                || (cd.details && cd.details.length)
                || this.formatNoShowFee(option)
            );
        },

        isPartiallyRefundableStatus(option = {}) {
            const cd = option.cancellation_details || {};
            const tag = option.refundability_tag
                || (option.cancellation_details && option.cancellation_details.refundability_tag)
                || (option.partially_refundable ? 'partially_refundable' : '');
            return !!(cd.under_cancellation || tag === 'partially_refundable');
        },

        isRefundableStatus(option = {}) {
            if (this.isPartiallyRefundableStatus(option)) return false;
            const cd = option.cancellation_details || {};
            const tag = option.refundability_tag
                || (option.cancellation_details && option.cancellation_details.refundability_tag)
                || (option.refundable ? 'refundable' : '');
            return tag === 'refundable'
                || !!(cd.free_cancellation)
                || !!(cd.cancel_by_date || cd.cancel_by_date_display);
        },

        formatPolicyDateTime(value) {
            if (!value) return '';
            if (typeof value === 'string' && /IST\s*$/i.test(value) && !value.includes('T')) {
                return value;
            }
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return String(value);
            const day = String(date.getDate()).padStart(2, '0');
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const month = months[date.getMonth()];
            const year = date.getFullYear();
            let hours = date.getHours();
            const minutes = String(date.getMinutes()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            if (hours === 0) hours = 12;
            return `${day} ${month} ${year}, ${String(hours).padStart(2, '0')}:${minutes} ${ampm} IST`;
        },

        formatCancelByDate(option = {}) {
            const cd = option.cancellation_details || {};
            if (cd.cancel_by_date_display) return cd.cancel_by_date_display;
            if (!cd.cancel_by_date) return '';
            return this.formatPolicyDateTime(cd.cancel_by_date);
        },

        formatPolicyChargeAmount(fee) {
            if (fee === null || fee === undefined || fee === '') return '';
            if (typeof fee === 'string' || typeof fee === 'number') return String(fee);
            if (fee.charge_display) return fee.charge_display;
            if (fee.nights !== null && fee.nights !== undefined && fee.nights !== '') {
                const n = Number(fee.nights);
                if (!Number.isFinite(n) || n <= 0) return '';
                return n === 1 ? "Cost of 1 night's stay" : `Cost of ${n} nights' stay`;
            }
            if (fee.percent !== null && fee.percent !== undefined && fee.percent !== '') {
                return `${fee.percent}% of total stay`;
            }
            if (fee.flat_fee !== null && fee.flat_fee !== undefined && fee.flat_fee !== '') {
                return `${fee.currency || ''} ${fee.flat_fee}`.trim();
            }
            if (fee.amount !== null && fee.amount !== undefined && fee.amount !== '') {
                return `${fee.currency || ''} ${fee.amount}`.trim();
            }
            return '';
        },

        formatNoShowFee(option = {}) {
            const cd = option.cancellation_details || {};
            if (cd.no_show_fee_text) return cd.no_show_fee_text;
            return this.formatPolicyChargeAmount(cd.no_show_fee);
        },

        formatCancellationFrom(detail = {}) {
            if (detail.from_display) return detail.from_display;
            return this.formatPolicyDateTime(detail.from) || '—';
        },

        formatCancellationCharge(detail = {}) {
            if (detail.charge_display) return detail.charge_display;
            if (detail.allowed_cancellation === false) return 'Non-cancellable';
            return this.formatPolicyChargeAmount(detail) || '—';
        },
        <?php endif; ?>

        getBaseCurrencySymbol() {
            return this.getCurrencySymbol(this.baseCurrency);
        },

        convertToDisplay(baseAmount) {
            return baseAmount * this.conversionRate;
        },

        convertToBase(displayAmount) {
            return displayAmount / this.conversionRate;
        },

        sanitizeGuestName(value) {
            return String(value || '').replace(/[^A-Za-z ]/g, '').replace(/\s+/g, ' ').slice(0, 30);
        },

        isGrnPackage(room) {
            if (String(this.supplier || '').toLowerCase() !== 'grnconnect') return false;
            const option = room?.option || {};
            return option.is_package === true
                || (parseInt(option.rooms_in_rate || option.allocation_size || 1, 10) || 1) > 1;
        },

        getSelectedRoomSubtotal(room) {
            const total = parseFloat(room?.option?.total_price || 0);
            return this.isGrnPackage(room)
                ? total
                : total * (parseInt(room?.quantity || 1, 10) || 1);
        },

        getSelectedRoomNetTotal(room) {
            const net = parseFloat(room?.option?.original_price || room?.option?.base_price || 0);
            return this.isGrnPackage(room)
                ? net
                : net * (parseInt(room?.quantity || 1, 10) || 1);
        },

        calculateSubtotal() {
            // Use pre-calculated total from booking data for consistency
            const baseAmount = this.totalAmount || this.selectedRoomsData.reduce((total, room) => {
                return total + this.getSelectedRoomSubtotal(room);
            }, 0);
            return this.convertToDisplay(baseAmount);
        },

        calculateSubtotalBase() {
            return this.totalAmount || this.selectedRoomsData.reduce((total, room) => {
                return total + this.getSelectedRoomSubtotal(room);
            }, 0);
        },

        calculateTaxAmount() {
            return this.taxAmountDisplay;
        },

        calculateTaxAmountBase() {
            return this.taxAmount;
        },

        calculateFinalTotal() {
            if (this.hasTax) {
                return this.totalWithTaxDisplay - this.promoDiscountDisplay;
            }
            return this.calculateSubtotal() - this.promoDiscountDisplay;
        },

        calculateFinalTotalBase() {
            if (this.hasTax) {
                return this.totalWithTax - this.promoDiscount;
            }
            return this.calculateSubtotalBase() - this.promoDiscount;
        },

        // ====================================================================
        // PROMO CODE METHODS
        // ====================================================================
        async applyPromoCode() {
            if (!this.promoCode.trim()) return;
            this.promoLoading = true;
            this.promoError = false;
            this.promoMessage = '';
            try {
                const orderAmount = this.hasTax ? this.totalWithTax : this.calculateSubtotalBase();
                const resp = await fetch('<?=root?>api/promo/validate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        code: this.promoCode.trim().toUpperCase(),
                        module: 'stays',
                        order_amount: orderAmount,
                        currency: this.baseCurrency
                    })
                });
                const data = await resp.json();
                if (data.success) {
                    this.promoApplied = true;
                    this.promoCode = data.data.code;
                    this.promoDiscount = data.data.discount_amount;
                    this.promoDiscountDisplay = data.data.discount_amount * this.conversionRate;
                    this.promoMessage = data.message;
                    this.promoError = false;
                } else {
                    this.promoError = true;
                    this.promoMessage = data.message;
                }
            } catch (e) {
                this.promoError = true;
                this.promoMessage = '<?= T::failed_to_validate_promo_code ?>';
            }
            this.promoLoading = false;
        },

        removePromoCode() {
            this.promoCode = '';
            this.promoApplied = false;
            this.promoDiscount = 0;
            this.promoDiscountDisplay = 0;
            this.promoMessage = '';
            this.promoError = false;
        },

        // ====================================================================
        // SUBMIT BOOKING TO SERVER
        // ====================================================================
        // PROCESS:
        //   1. VALIDATE TERMS ACCEPTANCE (REQUIRED BY LAW)
        //   2. VALIDATE REQUIRED FIELDS (NAME, EMAIL, PAYMENT)
        //   3. GET CSRF TOKEN (SECURITY)
        //   4. PREPARE PAYLOAD WITH ALL DATA
        //   5. SEND POST REQUEST TO API
        //   6. HANDLE SUCCESS: SHOW COUNTDOWN AND REDIRECT
        //   7. HANDLE ERROR: DISPLAY ERROR MESSAGE
        //
        // IMPORTANT NOTES:
        //   - ALWAYS SEND BASE CURRENCY AMOUNTS (FOR PAYMENT)
        //   - DISPLAY AMOUNTS ARE FOR REFERENCE ONLY
        //   - CSRF TOKEN PREVENTS CROSS-SITE REQUEST FORGERY
        //   - BOOKING_HASH LINKS TO EXISTING DRAFT
        // ====================================================================
        async submitBooking() {
            // STEP 1: VALIDATE TERMS & CONDITIONS CHECKBOX
            if (!this.formData.terms_accepted) {
                this.showError('<?= T::please ?> <?= T::accept ?> <?= T::terms ?> & <?= T::conditions ?>');
                return;
            }

            // STEP 2: VALIDATE REQUIRED FIELDS
            if (!this.formData.primary_guest.first_name || !this.formData.primary_guest.last_name ||
                !this.formData.primary_guest.email || !this.formData.selected_payment) {
                this.showError('<?= T::please_fill_required_fields ?>');
                return;
            }

            if (this.isGrnConnect) {
                if (this.grnRecheckLoading || window.__grnRecheckPromises?.[this.bookingHash]) {
                    this.showError('Please wait for rate verification to complete before booking.');
                    return;
                }
                if (!this.hasValidGrnRecheck()) {
                    // Force a recheck attempt if somehow skipped on page load.
                    this.showError('Verifying live rate with supplier. Please wait...');
                    const ok = await this.runGrnRecheck(true);
                    if (!ok || !this.hasValidGrnRecheck()) {
                        this.showError(this.grnRecheckError || 'Please wait for rate verification to complete before booking.');
                        return;
                    }
                }
                if (this.grnRecheckError) {
                    this.showError(this.grnRecheckError);
                    return;
                }
                if (this.grnUnavailable) {
                    this.showError('Sorry, this room is no longer available. Please select another option.');
                    return;
                }
                if (this.grnPriceChanged && !this.grnPriceAccepted) {
                    this.showError('The price has changed. Please review the updated price before continuing.');
                    return;
                }
            }

            this.submitting = true;
            this.showBookingLoader = true;

            try {
                // Get guest details from independent component
                const guestComponent = Alpine.$data(document.querySelector('[x-data*="bookingAuth"]'));
                if (!guestComponent) {
                    this.showError('Guest details component not found.');
                    this.submitting = false;
                    return;
                }

                // Get CSRF token
                const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
                if (!csrfToken) {
                    this.showError('<?= T::security_token_missing_refresh_page ?>');
                    this.submitting = false;
                    return;
                }

                const response = await fetch('<?=root?>api/stay/booking/submit', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        booking_hash: this.bookingHash,
                        guest_details: {
                            ...this.formData,
                            primary_guest: guestComponent.primary_guest,
                            booking_for_someone_else: guestComponent.booking_for_someone_else,
                            booking_type: guestComponent.bookingType || 'guest'
                        },
                        final_total: this.calculateFinalTotalBase(), // Always submit base currency amount with tax
                        display_total: this.calculateFinalTotal(),    // Display amount for reference with tax
                        subtotal: this.calculateSubtotalBase(), // Subtotal without tax
                        tax_amount: this.calculateTaxAmountBase(), // Tax amount in base currency
                        promo_code: this.promoApplied ? this.promoCode : '',
                        promo_discount: this.promoApplied ? this.promoDiscount : 0,
                        base_currency: this.baseCurrency,
                        display_currency: this.displayCurrency,
                        selected_rooms: this.selectedRoomsData,
                        grn_recheck: this.grnRecheck,
                        grn_rechecks: this.grnRechecks,
                        actual_amount: this.selectedRoomsData.reduce((sum, room) => {
                            return sum + this.getSelectedRoomNetTotal(room);
                        }, 0),
                        total_amount: this.calculateSubtotalBase()
                    })
                });

                const data = await response.json();

                if (data.success) {
                    const status = String(data.booking_status || '').toLowerCase();
                    const confirmed = status === 'confirmed' || status === 'ticketed';
                    const successMsg = confirmed
                        ? '<?= T::booking ?> <?= T::confirmed ?>! <?= T::redirecting ?>...'
                        : (status === 'processing'
                            ? 'Booking submitted. Waiting for supplier confirmation...'
                            : 'Booking submitted. Redirecting to invoice...');
                    if (data.countdown) {
                        this.showCountdownRedirect(data.redirect_url || '<?=root?>invoice/' + data.booking_id, successMsg);
                    } else {
                        this.showSuccess(successMsg);
                        setTimeout(() => {
                            window.location.href = data.redirect_url || '<?=root?>invoice/' + data.booking_id;
                        }, 2000);
                    }
                } else {
                    this.showError(data.message || '<?= T::booking ?> <?= T::failed ?>. <?= T::please ?> <?= T::try ?> <?= T::again ?>.');
                }
            } catch (err) {
                console.error('Booking error:', err);
                this.showError('<?= T::network ?> <?= T::error ?>. <?= T::please ?> <?= T::try ?> <?= T::again ?>.');
            } finally {
                this.submitting = false;
            }
        },

        showError(message) {
            this.alertType = 'error';
            this.alertMessage = message;
            this.showAlert = true;
            window.scrollTo({ top: 0, behavior: 'smooth' });
            setTimeout(() => this.showAlert = false, 5000);
        },

        showSuccess(message) {
            this.alertType = 'success';
            this.alertMessage = message;
            this.showAlert = true;
            window.scrollTo({ top: 0, behavior: 'smooth' });

            // Auto-hide after 5 seconds and mark as dismissed
            setTimeout(() => {
                this.showAlert = false;
                // Store in localStorage to never show again
                localStorage.setItem('booking_success_dismissed', 'true');
            }, 5000);
        },

        // COUNTDOWN ANIMATION AND REDIRECT
        showCountdownRedirect(redirectUrl, successMessage) {
            let countdown = 3;
            const submitButton = document.querySelector('button[type="submit"]');

            // DISABLE FORM
            const form = document.querySelector('form');
            const inputs = form.querySelectorAll('input, select, textarea, button');
            inputs.forEach(input => input.disabled = true);

            this.showSuccess(successMessage || 'Booking submitted. Redirecting to invoice...');

            // COUNTDOWN ANIMATION
            const countdownInterval = setInterval(() => {
                if (countdown > 0) {
                    submitButton.innerHTML = `
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                            <span class="text-2xl font-bold animate-pulse">${countdown}</span>
                        </div>
                    `;
                    countdown--;
                } else {
                    submitButton.innerHTML = `
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined animate-spin">sync</span>
                            <span><?= T::processing ?>...</span>
                        </div>
                    `;
                    clearInterval(countdownInterval);

                    // REDIRECT TO INVOICE
                    setTimeout(() => {
                        window.location.href = redirectUrl;
                    }, 500);
                }
            }, 1000);
        }
    }
}

</script>