<?php
// CRUISE CHECKOUT — expects $cruise (with 'sailing'), $quote, $adults, $children, $dateRef, $cabinKey, $old, $formError
@$SECURE or die('Access Denied!');

$sailing = $cruise['sailing'];
$cabin = $quote['cabin'];
$cur = $quote['currency'];
$countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['ORDER' => ['nicename' => 'ASC']]);
$user = !empty($_SESSION['user_id']) ? $db->get('users', ['first_name', 'last_name', 'email', 'phone', 'phone_country_code'], ['user_id' => $_SESSION['user_id']]) : [];
$val = fn($k, $d = '') => htmlspecialchars((string) ($old[$k] ?? $user[$k] ?? $d));
$defaultIso = strtoupper((string) ($old['country_code'] ?? ($_SESSION['app_currency_country'] ?? 'US')));
$guestTotal = $adults + $children;
$moduleType = 'cruises';
?>
<div class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    <div class="grid grid-cols-1 lg:grid-cols-2 min-h-screen">

        <!-- LEFT: GUEST DETAILS -->
        <div class="order-2 lg:order-1 bg-white dark:bg-gray-800">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pr-12 max-w-[650px] mx-auto lg:ml-auto lg:mr-0 w-full">

                <div class="flex gap-1 items-center text-sm text-gray-500 mb-5">
                    <a href="<?= root ?>cruises" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium"><?= T::cruises ?></a>
                    <span class="material-symbols-outlined !text-base">chevron_right</span>
                    <a href="<?= htmlspecialchars(_wgc_url($cruise['key'], $cruise['name'], ['date' => $dateRef, 'adults' => $adults, 'children' => $children])) ?>" class="text-slate-600 hover:text-slate-700 text-[13px] font-medium truncate max-w-[220px]"><?= htmlspecialchars($cruise['name']) ?></a>
                    <span class="material-symbols-outlined !text-base">chevron_right</span>
                    <span class="text-gray-700 text-[13px] font-medium"><?= T::booking ?></span>
                </div>

                <?php if ($formError): ?>
                <div class="alert alert-error mb-5"><span class="material-symbols-outlined">error</span><div><p class="text-sm font-medium"><?= htmlspecialchars($formError) ?></p></div></div>
                <?php endif; ?>

                <form method="POST" action="<?= root ?>cruises/booking/submit" id="cruiseBookingForm"
                      x-data="{ submitting: false, formData: { selected_payment: '<?= htmlspecialchars((string) ($old['payment'] ?? '')) ?>', terms: <?= !empty($old['terms']) ? 'true' : 'false' ?> } }"
                      x-init="$nextTick(() => { if (!formData.selected_payment) { const r = document.querySelector('input[name=payment]'); if (r) formData.selected_payment = document.getElementById('cruiseDefaultGateway')?.dataset.id || r.value; } })"
                      @submit="submitting = true">
                    <?= CSRF::tokenField() ?>
                    <input type="hidden" name="date_ref" value="<?= htmlspecialchars($dateRef) ?>">
                    <input type="hidden" name="cabin" value="<?= htmlspecialchars($cabinKey) ?>">
                    <input type="hidden" name="adults" value="<?= $adults ?>">
                    <input type="hidden" name="children" value="<?= $children ?>">

                    <!-- LEAD GUEST -->
                    <div class="card p-0 mb-5">
                        <div class="card-header"><div><span class="card-header-icon">person</span><h3>Lead guest</h3></div></div>
                        <div class="card-body space-y-3">
                            <div class="grid grid-cols-1 md:grid-cols-[110px_1fr_1fr] gap-3">
                                <div class="form-control">
                                    <label class="text-xs"><?= T::title ?? 'Title' ?> *</label>
                                    <select name="title" class="select" required>
                                        <?php foreach (['Mr', 'Mrs', 'Miss', 'Ms'] as $t): ?><option <?= ($old['title'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                                    <input type="text" name="first_name" class="input" value="<?= $val('first_name') ?>" required maxlength="100">
                                </div>
                                <div class="form-control">
                                    <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                                    <input type="text" name="last_name" class="input" value="<?= $val('last_name') ?>" required maxlength="100">
                                </div>
                            </div>
                            <div class="form-control">
                                <label class="text-xs"><?= T::email ?> *</label>
                                <input type="email" name="email" class="input" value="<?= $val('email') ?>" required maxlength="150">
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] gap-3">
                                <div class="form-control">
                                    <label class="text-xs">Country code *</label>
                                    <select name="country_code" class="select" required>
                                        <?php foreach ($countries as $co): ?>
                                        <option value="<?= $co['iso'] ?>" <?= $co['iso'] === $defaultIso ? 'selected' : '' ?>><?= htmlspecialchars($co['nicename']) ?> (+<?= $co['phonecode'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="text-xs"><?= T::phone ?> *</label>
                                    <input type="tel" name="phone" class="input" value="<?= $val('phone') ?>" required maxlength="20">
                                </div>
                            </div>
                            <div class="form-control">
                                <label class="text-xs"><?= T::nationality ?> *</label>
                                <select name="nationality" class="select" required>
                                    <?php $nat = strtoupper((string) ($old['nationality'] ?? $defaultIso)); foreach ($countries as $co): ?>
                                    <option value="<?= $co['iso'] ?>" <?= $co['iso'] === $nat ? 'selected' : '' ?>><?= htmlspecialchars($co['nicename']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- OTHER GUESTS -->
                    <?php if ($guestTotal > 1): ?>
                    <div class="card p-0 mb-5">
                        <div class="card-header"><div><span class="card-header-icon">group</span><h3>Other guests in the cabin</h3></div></div>
                        <div class="card-body space-y-4">
                            <?php for ($i = 2; $i <= $guestTotal; $i++):
                                $isChild = $i > $adults;
                                $g = (array) ($old['travelers'][$i] ?? []); ?>
                            <div class="p-3 border border-gray-200 dark:border-gray-600 rounded-lg">
                                <h5 class="font-medium text-sm mb-2 <?= $isChild ? 'text-blue-700' : 'text-gray-700' ?>"><?= $isChild ? 'Child ' . ($i - $adults) : 'Adult ' . $i ?></h5>
                                <div class="grid grid-cols-1 md:grid-cols-[110px_1fr_1fr] gap-3">
                                    <div class="form-control">
                                        <label class="text-xs"><?= T::title ?? 'Title' ?></label>
                                        <select name="travelers[<?= $i ?>][title]" class="select">
                                            <?php foreach ($isChild ? ['Master', 'Miss'] : ['Mr', 'Mrs', 'Miss', 'Ms'] as $t): ?><option <?= ($g['title'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-control">
                                        <label class="text-xs"><?= T::first ?> <?= T::name ?> *</label>
                                        <input type="text" name="travelers[<?= $i ?>][first_name]" class="input" value="<?= htmlspecialchars((string) ($g['first_name'] ?? '')) ?>" required maxlength="100">
                                    </div>
                                    <div class="form-control">
                                        <label class="text-xs"><?= T::last ?> <?= T::name ?> *</label>
                                        <input type="text" name="travelers[<?= $i ?>][last_name]" class="input" value="<?= htmlspecialchars((string) ($g['last_name'] ?? '')) ?>" required maxlength="100">
                                    </div>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- PAYMENT METHOD -->
                    <?php include views . 'includes/booking/payment-methods.php'; ?>
                    <span id="cruiseDefaultGateway" data-id="<?= htmlspecialchars((string) ($defaultGatewayId ?? '')) ?>" class="hidden"></span>

                    <!-- REQUESTS + TERMS -->
                    <div class="card p-0 mb-5">
                        <div class="card-header"><div><span class="card-header-icon">settings</span><h3><?= T::booking_options ?? 'Booking Options' ?></h3></div></div>
                        <div class="card-body">
                            <div class="form-control mb-5">
                                <label><?= T::special ?> <?= T::requests ?> (<?= T::optional ?>)</label>
                                <textarea name="special_requests" class="input" rows="3" maxlength="1000" placeholder="Dietary needs, accessibility, celebrations..."><?= htmlspecialchars((string) ($old['special_requests'] ?? '')) ?></textarea>
                            </div>
                            <div class="pt-5 border-t border-gray-200 dark:border-gray-700">
                                <div class="checkbox-item">
                                    <div class="checkbox-container">
                                        <input type="checkbox" id="terms" name="terms" value="1" x-model="formData.terms" class="checkbox-input" required>
                                        <div class="checkbox-custom"><span class="material-symbols-outlined text-white text-xs checkbox-icon">check</span></div>
                                    </div>
                                    <label for="terms" class="cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                                        <?= T::i_agree_to_the ?>
                                        <a href="<?= root ?>page/terms-of-use" target="_blank" class="text-blue-600 hover:underline"><?= T::terms ?> & <?= T::conditions ?></a>
                                        <?= T::and ?>
                                        <a href="<?= root ?>page/privacy-policy" target="_blank" class="text-blue-600 hover:underline"><?= T::privacy ?> <?= T::policy ?></a>
                                    </label>
                                </div>
                            </div>
                            <button type="submit" class="btn w-full mt-6" :disabled="submitting || !formData.terms" :class="{ 'opacity-50 cursor-not-allowed': !formData.terms }">
                                <span x-show="!submitting" class="material-symbols-outlined">lock</span>
                                <span x-show="submitting" x-cloak class="material-symbols-outlined animate-spin">progress_activity</span>
                                <span x-show="!submitting"><?= T::confirm ?> <?= T::booking ?></span>
                                <span x-show="submitting" x-cloak><?= T::processing ?>...</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- RIGHT: SUMMARY -->
        <div class="order-1 lg:order-2 bg-slate-100 lg:sticky lg:top-0 lg:self-start lg:max-h-screen lg:overflow-y-auto">
            <div class="p-3 sm:p-5 lg:p-10 lg:py-12 lg:pl-12 max-w-[550px] mx-auto lg:mr-auto lg:mx-0 w-full">
                <?php include views . 'includes/booking/timer.php'; ?>

                <div class="card p-0">
                    <div class="card-header"><div><span class="card-header-icon">receipt_long</span><h3><?= T::booking ?> <?= T::summary ?></h3></div></div>
                    <div class="card-body">
                        <div class="mb-3 p-4 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-700 rounded-lg border border-blue-100 dark:border-gray-600">
                            <div class="flex items-start gap-3 mb-3">
                                <img src="<?= htmlspecialchars($cruise['image']) ?>" alt="" class="w-16 h-16 object-cover rounded-lg border-2 border-white shadow-sm flex-shrink-0" onerror="this.src='<?= root ?>uploads/no_img.jpg'">
                                <div class="min-w-0">
                                    <h4 class="font-bold text-gray-900 dark:text-white text-sm leading-tight"><?= htmlspecialchars($cruise['name']) ?></h4>
                                    <p class="text-xs text-gray-600 mt-1"><?= htmlspecialchars($cruise['operator_name']) ?> · <?= htmlspecialchars($sailing['ship']) ?></p>
                                </div>
                            </div>
                            <div class="space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
                                <div class="flex justify-between"><span>Departs</span><span class="font-medium"><?= date('D, d M Y', strtotime($sailing['date'])) ?></span></div>
                                <?php if ($sailing['return']): ?><div class="flex justify-between"><span>Returns</span><span class="font-medium"><?= date('D, d M Y', strtotime($sailing['return'])) ?></span></div><?php endif; ?>
                                <div class="flex justify-between"><span>Duration</span><span class="font-medium"><?= $cruise['nights'] ?> nights</span></div>
                                <div class="flex justify-between gap-3"><span>Route</span><span class="font-medium text-right"><?= htmlspecialchars($sailing['embark']) ?> → <?= htmlspecialchars($sailing['disembark'] ?: $sailing['embark']) ?></span></div>
                                <div class="flex justify-between gap-3"><span>Cabin</span><span class="font-medium text-right"><?= htmlspecialchars($cabin['name']) ?> (<?= htmlspecialchars($cabin['code']) ?>)</span></div>
                                <?php if ($cabin['fare']): ?><div class="flex justify-between gap-3"><span>Fare</span><span class="font-medium text-right"><?= htmlspecialchars($cabin['fare']) ?></span></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="space-y-2 text-sm">
                            <?php foreach ($quote['lines'] as $l): ?>
                            <div class="flex justify-between text-gray-600 dark:text-gray-300"><span><?= htmlspecialchars($l['label']) ?></span><span><?= $cur ?> <?= number_format($l['amount'], 2) ?></span></div>
                            <?php endforeach; ?>
                            <div class="flex justify-between pt-2 border-t border-gray-200 dark:border-gray-700"><span><?= T::subtotal ?></span><span><?= $cur ?> <?= number_format($quote['subtotal'], 2) ?></span></div>
                            <div class="flex justify-between text-gray-600"><span><?= T::tax ?></span><span><?= $cur ?> <?= number_format($quote['tax'], 2) ?></span></div>
                            <div class="flex justify-between text-lg font-bold text-gray-900 dark:text-white pt-2 border-t border-gray-200 dark:border-gray-700"><span><?= T::total_amount ?></span><span><?= $cur ?> <?= number_format($quote['total'], 2) ?></span></div>
                        </div>
                        <p class="text-[11px] text-gray-500 mt-3">Your cabin is confirmed with the cruise line after payment. Port taxes, gratuities and onboard charges may apply and are set by the cruise line.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
