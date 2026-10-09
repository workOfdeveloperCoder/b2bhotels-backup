<?php
// CRUISE INVOICE — expects $booking, $bookingData, $travellersData, $invoiceId
@$SECURE or die('Access Denied!');

$bd = $bookingData;
$cabin = (array) ($bd['cabin'] ?? []);
$lead = (array) ($travellersData['primary_guest'] ?? []);
$others = array_filter((array) ($travellersData['travelers'] ?? []), 'is_array');

// Stored amounts are in the booking currency; show them in the visitor's currency
$bookCur = (string) ($booking['currency_markup'] ?: 'USD');
$showCur = (string) ($_SESSION['app_currency'] ?? $bookCur);
$rate = getCurrencyConversionRate($db, $bookCur, $showCur);
?>
<div class="bg-gray-200 dark:bg-gray-900 min-h-screen py-6">
    <div class="container mx-auto px-4">

        <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
            <a href="<?= root ?>cruises" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?= T::cruises ?></a>
            <span class="material-symbols-outlined !text-base">chevron_right</span>
            <span class="text-gray-700 text-[13px] font-medium"><?= T::invoice ?> #<?= htmlspecialchars($invoiceId) ?></span>
        </div>

        <?php if (!empty($_SESSION['payment_notice'])):
            $notice = $_SESSION['payment_notice'];
            $cls = ['warning' => ['alert-warning', 'warning'], 'error' => ['alert-error', 'error']][$notice['type'] ?? ''] ?? ['alert-success', 'check_circle'];
            unset($_SESSION['payment_notice']); ?>
        <div class="alert <?= $cls[0] ?> mb-6">
            <span class="material-symbols-outlined"><?= $cls[1] ?></span>
            <div>
                <p class="font-semibold"><?= htmlspecialchars($notice['title'] ?? T::payment_update) ?></p>
                <p class="text-sm"><?= htmlspecialchars($notice['message'] ?? '') ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if (($booking['booking_status'] ?? '') === 'confirmed'): ?>
        <div class="alert alert-success mb-6">
            <span class="material-symbols-outlined">check_circle</span>
            <div>
                <p class="font-semibold">Your cruise booking is confirmed</p>
                <p class="text-sm">Booking reference <strong><?= htmlspecialchars($invoiceId) ?></strong>. We will send your cruise documents by email.</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            <div class="lg:col-span-2 space-y-3">

                <!-- INVOICE + CUSTOMER -->
                <div class="card p-0">
                    <div class="card-header"><div><span class="card-header-icon">receipt_long</span><h3><?= T::invoice_details ?></h3></div></div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                            <div class="space-y-2">
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::invoice_information ?></h4>
                                <div class="flex justify-between"><span class="text-gray-600"><?= T::invoice_id ?>:</span><span class="font-medium">#<?= htmlspecialchars($invoiceId) ?></span></div>
                                <div class="flex justify-between"><span class="text-gray-600"><?= T::booking_date ?>:</span><span class="font-medium"><?= date('d M Y, H:i', strtotime($booking['booking_date'])) ?></span></div>
                                <div class="flex justify-between"><span class="text-gray-600"><?= T::booking_type ?>:</span><span class="font-medium">Cruise</span></div>
                                <?php if ($booking['pnr']): ?><div class="flex justify-between"><span class="text-gray-600"><?= T::pnr ?>:</span><span class="font-medium"><?= htmlspecialchars($booking['pnr']) ?></span></div><?php endif; ?>
                            </div>
                            <div class="space-y-2">
                                <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3"><?= T::customer_information ?></h4>
                                <div class="flex justify-between"><span class="text-gray-600"><?= T::name ?>:</span><span class="font-medium"><?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></span></div>
                                <div class="flex justify-between"><span class="text-gray-600"><?= T::email ?>:</span><span class="font-medium"><?= htmlspecialchars($booking['email']) ?></span></div>
                                <div class="flex justify-between"><span class="text-gray-600"><?= T::phone ?>:</span><span class="font-medium">+<?= htmlspecialchars($booking['phone_country_code'] . ' ' . $booking['phone']) ?></span></div>
                                <div class="flex justify-between"><span class="text-gray-600"><?= T::nationality ?>:</span><span class="font-medium"><?= htmlspecialchars((string) $booking['nationality']) ?></span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CRUISE -->
                <div class="card p-0">
                    <div class="card-header"><div><span class="card-header-icon">directions_boat</span><h3>Cruise information</h3></div></div>
                    <div class="card-body">
                        <div class="flex items-start gap-4 pb-4 mb-4 border-b border-gray-200 dark:border-gray-700">
                            <img src="<?= htmlspecialchars((string) ($bd['image'] ?? '')) ?>" alt="" class="w-24 h-20 object-cover rounded-xl border border-gray-200 flex-shrink-0" onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                            <div class="flex-1 min-w-0">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-gray-100"><?= htmlspecialchars((string) ($bd['cruise_name'] ?? 'Cruise')) ?></h4>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars((string) ($bd['operator_name'] ?? '')) ?> · <?= htmlspecialchars((string) ($bd['ship_name'] ?? '')) ?></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-gray-600">Departs:</span><span class="font-medium"><?= !empty($bd['start_date']) ? date('D, d M Y', strtotime($bd['start_date'])) : '-' ?></span></div>
                            <div class="flex justify-between"><span class="text-gray-600">Returns:</span><span class="font-medium"><?= !empty($bd['end_date']) ? date('D, d M Y', strtotime($bd['end_date'])) : '-' ?></span></div>
                            <div class="flex justify-between"><span class="text-gray-600">Duration:</span><span class="font-medium"><?= (int) ($bd['nights'] ?? 0) ?> nights</span></div>
                            <div class="flex justify-between"><span class="text-gray-600">Guests:</span><span class="font-medium"><?= (int) $booking['adults'] ?> adult<?= $booking['adults'] > 1 ? 's' : '' ?><?= $booking['childs'] > 0 ? ', ' . (int) $booking['childs'] . ' child' . ($booking['childs'] > 1 ? 'ren' : '') : '' ?></span></div>
                            <div class="flex justify-between gap-3"><span class="text-gray-600">Embark:</span><span class="font-medium text-right"><?= htmlspecialchars((string) ($bd['embark_port'] ?? '')) ?></span></div>
                            <div class="flex justify-between gap-3"><span class="text-gray-600">Disembark:</span><span class="font-medium text-right"><?= htmlspecialchars((string) (($bd['disembark_port'] ?? '') ?: ($bd['embark_port'] ?? ''))) ?></span></div>
                            <div class="flex justify-between gap-3"><span class="text-gray-600">Cabin:</span><span class="font-medium text-right"><?= htmlspecialchars((string) ($cabin['name'] ?? '')) ?> <?= !empty($cabin['code']) ? '(' . htmlspecialchars($cabin['code']) . ')' : '' ?></span></div>
                            <?php if (!empty($cabin['fare'])): ?><div class="flex justify-between gap-3"><span class="text-gray-600">Fare:</span><span class="font-medium text-right"><?= htmlspecialchars($cabin['fare']) ?></span></div><?php endif; ?>
                        </div>

                        <?php if (!empty($bd['price_lines'])):
                            $subtotalStored = (float) ($booking['price_markup'] ?? 0) - (float) ($booking['tax'] ?? 0);
                            $sumPp = array_sum(array_column($bd['price_lines'], 'pp')) ?: 1; ?>
                        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1"><span class="material-symbols-outlined text-base">payments</span><?= T::pricing_details ?></h4>
                            <div class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                                <?php foreach ($bd['price_lines'] as $l): ?>
                                <div class="flex justify-between"><span><?= htmlspecialchars((string) $l['label']) ?></span><span><?= htmlspecialchars($showCur) ?> <?= number_format($l['pp'] / $sumPp * $subtotalStored * $rate, 2) ?></span></div>
                                <?php endforeach; ?>
                                <div class="flex justify-between font-semibold mt-2 pt-2 border-t border-gray-200 dark:border-gray-700"><span><?= T::subtotal ?>:</span><span><?= htmlspecialchars($showCur) ?> <?= number_format($subtotalStored * $rate, 2) ?></span></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- GUESTS -->
                <div class="card p-0">
                    <div class="card-header"><div><span class="card-header-icon">group</span><h3><?= T::travellers_information ?></h3></div></div>
                    <div class="card-body space-y-3">
                        <div class="p-3 bg-blue-50 rounded-lg border border-blue-200 text-sm">
                            <h4 class="font-medium text-blue-700 mb-1"><?= T::primary_guest ?></h4>
                            <p><strong><?= htmlspecialchars(trim(($lead['title'] ?? '') . ' ' . ($lead['first_name'] ?? $booking['first_name']) . ' ' . ($lead['last_name'] ?? $booking['last_name']))) ?></strong></p>
                            <p class="text-gray-600"><?= htmlspecialchars($booking['email']) ?></p>
                        </div>
                        <?php if ($others): ?>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <?php foreach ($others as $t):
                                $initials = strtoupper(substr((string) ($t['first_name'] ?? ''), 0, 1) . substr((string) ($t['last_name'] ?? ''), 0, 1)); ?>
                            <div class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center text-white font-semibold text-sm flex-shrink-0"><?= htmlspecialchars($initials ?: 'NA') ?></div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold truncate"><?= htmlspecialchars(trim(($t['title'] ?? '') . ' ' . ($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''))) ?></p>
                                    <p class="text-xs text-gray-500"><?= ucfirst((string) ($t['type'] ?? 'adult')) ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($booking['special_requests']): ?>
                        <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="font-medium text-gray-700 mb-2"><?= T::special_requests ?></h4>
                            <p class="text-sm text-gray-600 bg-yellow-50 p-3 rounded"><?= nl2br(htmlspecialchars($booking['special_requests'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-1">
                <?php $moduleType = 'cruises'; include views . 'includes/invoice/summary.php'; ?>
            </div>
        </div>
    </div>
</div>

<script>
function downloadInvoice() {
    window.location.href = '<?= root ?>api/cruises/booking/download-invoice/<?= htmlspecialchars($invoiceId) ?>';
}

function processPayment() {
    const gateway = document.getElementById('payment_gateway')?.value;
    if (!gateway) { alert('<?= T::please_select_a_payment_gateway ?>'); return; }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= root ?>payment/process';
    [['invoice_id', '<?= htmlspecialchars($invoiceId) ?>'], ['gateway_id', gateway]].forEach(([n, v]) => {
        const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
}

function requestCancellation() {
    if (!confirm('<?= T::cancellation_confirmation_message ?>')) return;
    fetch('<?= root ?>api/cruises/booking/request-cancellation', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ invoice_id: '<?= htmlspecialchars($invoiceId) ?>' })
    })
    .then(r => r.json())
    .then(d => { alert(d.success ? '<?= T::cancellation_request_submitted_successfully ?>' : (d.message || '<?= T::failed_to_submit_cancellation_request ?>')); if (d.success) location.reload(); })
    .catch(() => alert('<?= T::network_error_try_again ?>'));
}
</script>
