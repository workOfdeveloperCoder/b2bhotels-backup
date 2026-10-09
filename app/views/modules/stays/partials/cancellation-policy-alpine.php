<?php
/**
 * Structured cancellation policy card (Alpine.js bindings).
 * Used for GRNConnect rates that expose cancellation_details.
 *
 * @var string $cpExpr Alpine expression resolving to a room/rate option object.
 *                     Defaults to "option". Use "room.option" on booking page.
 * @var bool   $showHeader Whether to render the "Cancellation Policy" header.
 */
$cpExpr = $cpExpr ?? 'option';
$showHeader = $showHeader ?? true;
$cdExpr = "({$cpExpr}.cancellation_details || {})";
?>
<div class="cancel-policy-card border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden bg-white dark:bg-gray-800 text-left">
    <?php if ($showHeader): ?>
    <div class="px-3 py-2.5 border-b border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/40">
        <p class="text-xs font-semibold text-gray-900 dark:text-gray-100"><?= defined('T::cancellation_policy') ? T::cancellation_policy : 'Cancellation Policy' ?></p>
    </div>
    <?php endif; ?>

    <div class="p-3 space-y-3.5">
        <?php /* Refundable — under_cancellation=false */ ?>
        <div x-show="isRefundableStatus(<?= $cpExpr ?>)">
            <div class="flex items-center gap-2 mb-1">
                <span class="w-1 self-stretch min-h-[12px] rounded-full bg-emerald-500"></span>
                <p class="text-[10px] font-bold tracking-wider uppercase text-emerald-700 dark:text-emerald-400"><?= defined('T::refundable') ? T::refundable : 'Refundable' ?></p>
            </div>
            <p class="text-xs text-gray-700 dark:text-gray-300 pl-3"
               x-show="formatCancelByDate(<?= $cpExpr ?>)">
                <span><?= defined('T::cancel_free_until') ? T::cancel_free_until : 'Cancel free until' ?></span>
                <span x-text="formatCancelByDate(<?= $cpExpr ?>)"></span>
            </p>
        </div>

        <?php /* Partially refundable — under_cancellation=true */ ?>
        <div x-show="isPartiallyRefundableStatus(<?= $cpExpr ?>)">
            <div class="flex items-center gap-2 mb-1">
                <span class="w-1 self-stretch min-h-[12px] rounded-full bg-amber-500"></span>
                <p class="text-[10px] font-bold tracking-wider uppercase text-amber-800 dark:text-amber-400"><?= defined('T::partially_refundable') ? T::partially_refundable : 'Partially Refundable' ?></p>
            </div>
            <p class="text-xs text-gray-700 dark:text-gray-300 pl-3">
                <?= defined('T::partial_refund_fee_note') ? T::partial_refund_fee_note : 'A cancellation fee will be deducted from your refund.' ?>
            </p>
        </div>

        <?php /* Cancellation charges */ ?>
        <div x-show="<?= $cdExpr ?>.details && <?= $cdExpr ?>.details.length">
            <div class="flex items-center gap-2 mb-1.5">
                <span class="w-1 self-stretch min-h-[12px] rounded-full bg-amber-500"></span>
                <p class="text-[10px] font-bold tracking-wider uppercase text-amber-800 dark:text-amber-400"><?= defined('T::cancellation_charges') ? T::cancellation_charges : 'Cancellation Charges' ?></p>
            </div>
            <div class="pl-3 space-y-2">
                <template x-for="(detail, dIdx) in (<?= $cdExpr ?>.details || [])" :key="dIdx">
                    <div class="border border-gray-200 dark:border-gray-600 rounded-md px-2.5 py-2 bg-gray-50 dark:bg-gray-900/30">
                        <div class="grid grid-cols-[52px_1fr] gap-x-2 gap-y-1 text-[11px] leading-snug">
                            <span class="font-medium text-gray-500 dark:text-gray-400"><?= defined('T::cancellation_from') ? T::cancellation_from : 'From' ?>:</span>
                            <span class="text-gray-800 dark:text-gray-200" x-text="formatCancellationFrom(detail)"></span>
                            <span class="font-medium text-gray-500 dark:text-gray-400"><?= defined('T::cancellation_charge') ? T::cancellation_charge : 'Charge' ?>:</span>
                            <span class="text-gray-800 dark:text-gray-200" x-text="formatCancellationCharge(detail)"></span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <?php /* No-show charge */ ?>
        <div x-show="formatNoShowFee(<?= $cpExpr ?>)">
            <div class="flex items-center gap-2 mb-1">
                <span class="w-1 self-stretch min-h-[12px] rounded-full bg-rose-500"></span>
                <p class="text-[10px] font-bold tracking-wider uppercase text-rose-700 dark:text-rose-400"><?= defined('T::no_show_charge') ? T::no_show_charge : 'No-Show Charge' ?></p>
            </div>
            <p class="text-xs text-gray-700 dark:text-gray-300 pl-3">
                <span class="font-medium text-gray-500 dark:text-gray-400"><?= defined('T::cancellation_charge') ? T::cancellation_charge : 'Charge' ?>:</span>
                <span x-text="formatNoShowFee(<?= $cpExpr ?>)"></span>
            </p>
            <p class="text-[10px] text-gray-500 dark:text-gray-400 pl-3 mt-1.5 leading-relaxed">
                *<span x-text="<?= $cdExpr ?>.no_show_note || '<?= htmlspecialchars(defined('T::no_show_fee_note') ? T::no_show_fee_note : 'No show fee applies when the guest does not show up for check-in or attempts to cancel on check-in.', ENT_QUOTES) ?>'"></span>
            </p>
        </div>

        <?php /* Fallback: plain policy text when structured blocks are empty */ ?>
        <div x-show="!hasStructuredCancellation(<?= $cpExpr ?>) && (<?= $cpExpr ?>.cancellation_text || <?= $cdExpr ?>.policy_text || <?= $cdExpr ?>.text)"
             class="text-xs text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-line"
             x-text="<?= $cpExpr ?>.cancellation_text || <?= $cdExpr ?>.policy_text || <?= $cdExpr ?>.text">
        </div>
    </div>
</div>
