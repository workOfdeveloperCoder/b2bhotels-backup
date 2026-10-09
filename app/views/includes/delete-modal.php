<?php
// ============================================================================
// REUSABLE DELETE / CONFIRMATION MODAL COMPONENT
// ============================================================================
// FILE: app/views/includes/delete-modal.php
// PURPOSE: Reusable modern confirmation modal matching V10 UI standards
// COMPATIBILITY: Works with Alpine.js x-data states (confirmModal, deleting, closeModal, executeDelete)
// ============================================================================
@$SECURE or die('Access Denied!');
?>

<!-- Reusable Confirmation / Delete Modal Overlay -->
<div
   x-show="typeof confirmModal !== 'undefined' && confirmModal !== null"
   x-cloak
   class="fixed inset-0 z-[99999] flex items-center justify-center p-4 overflow-y-auto"
   style="display: none;"
>
   <!-- Backdrop Overlay -->
   <div
      x-show="typeof confirmModal !== 'undefined' && confirmModal !== null"
      x-transition:enter="transition ease-out duration-200"
      x-transition:enter-start="opacity-0"
      x-transition:enter-end="opacity-100"
      x-transition:leave="transition ease-in duration-150"
      x-transition:leave-start="opacity-100"
      x-transition:leave-end="opacity-0"
      @click="typeof closeModal === 'function' ? closeModal() : (confirmModal = null)"
      class="fixed inset-0 bg-black/40 backdrop-blur-xs transition-opacity"
   ></div>

   <!-- Modal Dialog Card (Matching Standard V10 Layout Exactly) -->
   <div
      x-show="typeof confirmModal !== 'undefined' && confirmModal !== null"
      x-transition:enter="transition ease-out duration-200"
      x-transition:enter-start="opacity-0 scale-95 translate-y-2"
      x-transition:enter-end="opacity-100 scale-100 translate-y-0"
      x-transition:leave="transition ease-in duration-150"
      x-transition:leave-start="opacity-100 scale-100 translate-y-0"
      x-transition:leave-end="opacity-0 scale-95 translate-y-2"
      class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 w-full max-w-sm overflow-hidden z-10 m-auto"
   >
      <!-- Modal Main Body -->
      <div class="px-6 pt-7 pb-6 text-center">
         <!-- Warning Triangle Icon Badge (Centered Circle) -->
         <div class="w-14 h-14 rounded-full bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-2xl text-red-500">warning</span>
         </div>

         <!-- Modal Title -->
         <h3 class="text-xl font-bold text-gray-900 mb-1.5 leading-snug">
            <template x-if="confirmModal && confirmModal.title">
               <span x-text="confirmModal.title"></span>
            </template>
            <template x-if="confirmModal && !confirmModal.title">
               <span>
                  <span x-show="confirmModal.type === 'all'"><?= T::clear_all_favourites ?? 'Clear All Favourites?' ?></span>
                  <span x-show="confirmModal.type !== 'all'"><?= T::remove_favourite ?? 'Delete Record?' ?></span>
               </span>
            </template>
         </h3>

         <!-- Modal Subtitle / Description -->
         <p class="text-sm text-gray-500 leading-normal">
            <template x-if="confirmModal && confirmModal.message">
               <span x-text="confirmModal.message"></span>
            </template>
            <template x-if="!confirmModal || !confirmModal.message">
               <span><?= T::this_action_cannot_be_undone ?? 'This action cannot be undone.' ?></span>
            </template>
         </p>
      </div>

      <!-- Modal Footer (Clean White Background with Divider) -->
      <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-end gap-3 bg-white">
         <button
            type="button"
            @click="typeof closeModal === 'function' ? closeModal() : (confirmModal = null)"
            :disabled="typeof deleting !== 'undefined' && deleting"
            class="px-5 py-2 text-sm font-semibold rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition-colors shadow-xs cursor-pointer disabled:opacity-50"
         >
            <?= T::cancel ?? 'Cancel' ?>
         </button>
         <button
            type="button"
            @click="typeof executeDelete === 'function' ? executeDelete() : (confirmModal = null)"
            :disabled="typeof deleting !== 'undefined' && deleting"
            class="inline-flex items-center justify-center gap-1.5 px-5 py-2 text-sm font-semibold rounded-lg bg-rose-600 hover:bg-rose-700 text-white transition-colors shadow-xs cursor-pointer disabled:opacity-50"
         >
            <span x-show="typeof deleting !== 'undefined' && deleting" class="inline-block animate-spin material-symbols-outlined text-sm">progress_activity</span>
            <span x-show="typeof deleting === 'undefined' || !deleting" class="material-symbols-outlined text-base">delete</span>
            <span x-text="confirmModal && confirmModal.type === 'all' ? '<?= T::clear_all ?? 'Clear All' ?>' : (confirmModal && confirmModal.btnText ? confirmModal.btnText : '<?= T::delete ?? 'Delete' ?>')"></span>
         </button>
      </div>
   </div>
</div>
