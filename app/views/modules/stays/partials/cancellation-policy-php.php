<?php
/**
 * Structured cancellation policy card (server-rendered).
 *
 * @var array  $cancellationDetails Structured details from option.cancellation_details
 * @var string $cancellationText    Fallback plain-text policy
 * @var bool   $showHeader
 */
$cancellationDetails = is_array($cancellationDetails ?? null) ? $cancellationDetails : [];
$cancellationText    = trim((string)($cancellationText ?? ''));
$showHeader          = $showHeader ?? true;

$underCancellation = !empty($cancellationDetails['under_cancellation']);
$tag = (string)($cancellationDetails['refundability_tag'] ?? '');
$isPartial = $underCancellation || $tag === 'partially_refundable';
$isRefundable = !$isPartial && (
    $tag === 'refundable'
    || !empty($cancellationDetails['free_cancellation'])
    || !empty($cancellationDetails['cancel_by_date'])
    || !empty($cancellationDetails['cancel_by_date_display'])
);

$cancelByDisp = trim((string)($cancellationDetails['cancel_by_date_display']
    ?? (function_exists('grnconnect_format_policy_datetime')
        ? grnconnect_format_policy_datetime($cancellationDetails['cancel_by_date'] ?? '')
        : ($cancellationDetails['cancel_by_date'] ?? ''))));
$details      = (isset($cancellationDetails['details']) && is_array($cancellationDetails['details']))
    ? $cancellationDetails['details'] : [];
$noShowText   = trim((string)($cancellationDetails['no_show_fee_text'] ?? ''));
if ($noShowText === '' && !empty($cancellationDetails['no_show_fee']) && function_exists('grnconnect_format_no_show_fee')) {
    $noShowText = grnconnect_format_no_show_fee($cancellationDetails['no_show_fee']);
}
$noShowNote   = trim((string)($cancellationDetails['no_show_note']
    ?? (defined('T::no_show_fee_note') ? T::no_show_fee_note : 'No show fee applies when the guest does not show up for check-in or attempts to cancel on check-in.')));
$policyText   = trim((string)($cancellationDetails['policy_text'] ?? $cancellationDetails['text'] ?? ''));
if ($policyText === '') $policyText = $cancellationText;

$hasStructured = $isRefundable
    || $isPartial
    || !empty($details)
    || $noShowText !== '';

if (!$hasStructured && $policyText === '') {
    return;
}
?>
<div class="cancel-policy-card border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden bg-white dark:bg-gray-800 text-left">
    <?php if ($showHeader): ?>
    <div class="px-3 py-2.5 border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40">
        <p class="text-xs font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-1.5">
            <span class="material-symbols-outlined !text-sm text-yellow-700 dark:text-yellow-300">policy</span>
            <?= defined('T::cancellation_policy') ? T::cancellation_policy : 'Cancellation Policy' ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="p-3 space-y-3.5">
        <?php if ($isRefundable): ?>
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="w-1 self-stretch min-h-[12px] rounded-full bg-emerald-500"></span>
                <p class="text-[10px] font-bold tracking-wider uppercase text-emerald-700 dark:text-emerald-400"><?= defined('T::refundable') ? T::refundable : 'Refundable' ?></p>
            </div>
            <?php if ($cancelByDisp !== ''): ?>
            <p class="text-xs text-gray-700 dark:text-gray-300 pl-3">
                <?= defined('T::cancel_free_until') ? T::cancel_free_until : 'Cancel free until' ?>
                <?= htmlspecialchars($cancelByDisp) ?>
            </p>
            <?php endif; ?>
        </div>
        <?php elseif ($isPartial): ?>
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="w-1 self-stretch min-h-[12px] rounded-full bg-amber-500"></span>
                <p class="text-[10px] font-bold tracking-wider uppercase text-amber-800 dark:text-amber-400"><?= defined('T::partially_refundable') ? T::partially_refundable : 'Partially Refundable' ?></p>
            </div>
            <p class="text-xs text-gray-700 dark:text-gray-300 pl-3">
                <?= defined('T::partial_refund_fee_note') ? T::partial_refund_fee_note : 'A cancellation fee will be deducted from your refund.' ?>
            </p>
        </div>
        <?php endif; ?>

        <?php if (!empty($details)): ?>
        <div>
            <div class="flex items-center gap-2 mb-1.5">
                <span class="w-1 self-stretch min-h-[12px] rounded-full bg-amber-500"></span>
                <p class="text-[10px] font-bold tracking-wider uppercase text-amber-800 dark:text-amber-400"><?= defined('T::cancellation_charges') ? T::cancellation_charges : 'Cancellation Charges' ?></p>
            </div>
            <div class="pl-3 space-y-2">
                <?php foreach ($details as $detail): ?>
                    <?php
                    if (!is_array($detail)) continue;
                    $fromDisp = trim((string)($detail['from_display']
                        ?? (function_exists('grnconnect_format_policy_datetime')
                            ? grnconnect_format_policy_datetime($detail['from'] ?? '')
                            : ($detail['from'] ?? ''))));
                    $chargeDisp = trim((string)($detail['charge_display'] ?? ''));
                    if ($chargeDisp === '' && function_exists('grnconnect_format_charge_amount')) {
                        $chargeDisp = isset($detail['allowed_cancellation']) && $detail['allowed_cancellation'] === false
                            ? 'Non-cancellable'
                            : grnconnect_format_charge_amount($detail);
                    }
                    if ($fromDisp === '' && $chargeDisp === '') continue;
                    ?>
                    <div class="border border-gray-200 dark:border-gray-600 rounded-md px-2.5 py-2 bg-gray-50 dark:bg-gray-900/30">
                        <div class="grid grid-cols-[52px_1fr] gap-x-2 gap-y-1 text-[11px] leading-snug">
                            <span class="font-medium text-gray-500 dark:text-gray-400"><?= defined('T::cancellation_from') ? T::cancellation_from : 'From' ?>:</span>
                            <span class="text-gray-800 dark:text-gray-200"><?= htmlspecialchars($fromDisp !== '' ? $fromDisp : '—') ?></span>
                            <span class="font-medium text-gray-500 dark:text-gray-400"><?= defined('T::cancellation_charge') ? T::cancellation_charge : 'Charge' ?>:</span>
                            <span class="text-gray-800 dark:text-gray-200"><?= htmlspecialchars($chargeDisp !== '' ? $chargeDisp : '—') ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($noShowText !== ''): ?>
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="w-1 self-stretch min-h-[12px] rounded-full bg-rose-500"></span>
                <p class="text-[10px] font-bold tracking-wider uppercase text-rose-700 dark:text-rose-400"><?= defined('T::no_show_charge') ? T::no_show_charge : 'No-Show Charge' ?></p>
            </div>
            <p class="text-xs text-gray-700 dark:text-gray-300 pl-3">
                <span class="font-medium text-gray-500 dark:text-gray-400"><?= defined('T::cancellation_charge') ? T::cancellation_charge : 'Charge' ?>:</span>
                <?= htmlspecialchars($noShowText) ?>
            </p>
            <?php if ($noShowNote !== ''): ?>
            <p class="text-[10px] text-gray-500 dark:text-gray-400 pl-3 mt-1.5 leading-relaxed">
                *<?= htmlspecialchars($noShowNote) ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!$hasStructured && $policyText !== ''): ?>
        <p class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-line"><?= htmlspecialchars($policyText) ?></p>
        <?php endif; ?>
    </div>
</div>
