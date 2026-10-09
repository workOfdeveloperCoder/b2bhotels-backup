<?php
// HERO / AI PANEL — AI Trip Planner form. Moved here from home.php unchanged.
@$SECURE or die('Access Denied!');
?>
<div class="w-full p-4 md:p-6">
   <div class="rounded-2xl border border-primary/30 bg-white p-4 md:p-5">
      <div class="flex items-center gap-2 mb-3">
         <span class="material-symbols-outlined text-primary">auto_awesome</span>
         <h3 class="text-base md:text-lg font-semibold text-gray-900">AI Trip Planner</h3>
      </div>

      <div class="relative">
         <div
            id="ai-home-q"
            x-ref="aiSearchInput"
            class="w-full rounded-xl border-0 bg-transparent px-0 py-1 text-sm md:text-base leading-relaxed text-gray-700 focus:outline-none focus:ring-0 min-h-[110px] empty:before:content-[attr(data-placeholder)] empty:before:text-gray-400"
            contenteditable="true"
            role="textbox"
            data-placeholder="Describe the trip you need… (e.g. Round trip Dubai to Paris in October for 2 adults, beach resort stay)"
            @input="onAiInput()"
         ></div>
      </div>

      <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 border-t border-primary/10 pt-3">
         <span class="inline-flex items-center gap-1">
            <span class="material-symbols-outlined text-sm">info</span>
            Minimum 10 characters for better recommendations
         </span>
         <span id="ai-home-chars">0 chars</span>
      </div>
   </div>

   <div class="mt-4 flex items-center gap-2" x-show="previewAiSuggestions().length" x-cloak>
      <div class="flex items-center gap-2 min-w-0 flex-1 overflow-x-auto scrollbar-none flex-nowrap">
         <span class="text-sm text-gray-500 shrink-0">Try these:</span>
         <template x-for="(s, i) in previewAiSuggestions()" :key="'ai-prev-'+i">
            <button type="button"
               class="ai-suggestion-chip inline-flex items-center shrink-0 rounded-full border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-700 hover:border-primary/40 hover:bg-primary/5 transition-colors"
               :title="s.query || s.label"
               @click.stop.prevent="applyAiSuggestion(s)">
               <span class="inline-flex items-center gap-1 min-w-0 truncate"
                     x-html="formatSuggestionLabel(s.chip || s.label)"></span>
            </button>
         </template>
      </div>
      <button type="button"
         x-show="hasMoreAiSuggestions()"
         x-cloak
         class="inline-flex items-center gap-1.5 shrink-0 rounded-full border border-primary/30 bg-primary/10 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary/15 hover:border-primary/40 transition-colors"
         @click.stop.prevent="aiMoreOpen = true">
         <span class="material-symbols-outlined text-base">apps</span>
         Try more
      </button>
   </div>

   <!-- Try more suggestions modal -->
   <div x-show="aiMoreOpen"
        x-cloak
        class="fixed inset-0 z-[120] flex items-end sm:items-center justify-center p-0 sm:p-4"
        style="display: none;"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ai-more-title"
        @keydown.escape.window="aiMoreOpen = false">
      <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-[2px]"
           @click="aiMoreOpen = false"></div>
      <div class="relative w-full sm:max-w-lg md:max-w-xl max-h-[85vh] sm:max-h-[80vh] flex flex-col rounded-t-2xl sm:rounded-2xl bg-white shadow-2xl border border-primary/20 overflow-hidden"
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-2 sm:scale-95"
           x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
           x-transition:leave="transition ease-in duration-150"
           x-transition:leave-start="opacity-100"
           x-transition:leave-end="opacity-0 translate-y-2"
           @click.stop>
         <div class="shrink-0 px-5 pt-5 pb-4 border-b border-primary/10 bg-gradient-to-br from-primary/10 via-white to-white">
            <div class="flex items-start justify-between gap-3">
               <div class="flex items-start gap-3 min-w-0">
                  <div class="w-10 h-10 rounded-xl bg-primary text-primary-foreground flex items-center justify-center shrink-0 shadow-sm">
                     <span class="material-symbols-outlined text-xl">auto_awesome</span>
                  </div>
                  <div class="min-w-0">
                     <h3 id="ai-more-title" class="text-base font-semibold text-slate-900">More trip ideas</h3>
                     <p class="text-xs text-slate-500 mt-0.5">Pick one to fill the AI search box</p>
                  </div>
               </div>
               <button type="button"
                       class="w-9 h-9 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 inline-flex items-center justify-center shrink-0"
                       @click="aiMoreOpen = false"
                       aria-label="Close">
                  <span class="material-symbols-outlined text-xl">close</span>
               </button>
            </div>
         </div>
         <div class="flex-1 overflow-y-auto overscroll-contain p-4 sm:p-5 space-y-2">
            <template x-for="(s, i) in moreAiSuggestions()" :key="'ai-more-'+i">
               <button type="button"
                       class="w-full text-left group rounded-xl border border-slate-200 bg-white hover:border-primary/40 hover:bg-primary/5 px-3.5 py-3 transition-all"
                       @click="applyAiSuggestion(s)">
                  <div class="flex items-start gap-3">
                     <span class="mt-0.5 inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary/10 text-primary group-hover:bg-white border border-primary/20 shrink-0"
                           x-html="formatSuggestionLabel(aiSuggestionIconToken(s))"></span>
                     <span class="min-w-0 flex-1 text-sm text-slate-700 leading-snug"
                           x-text="s.query || s.label"></span>
                     <span class="material-symbols-outlined text-slate-300 group-hover:text-primary text-lg shrink-0 mt-0.5">arrow_forward</span>
                  </div>
               </button>
            </template>
         </div>
         <div class="shrink-0 px-5 py-3 border-t border-slate-100 bg-slate-50/80 flex items-center justify-between gap-2">
            <span class="text-xs text-slate-500" x-text="moreAiSuggestions().length + ' more ideas'"></span>
            <button type="button" class="btn light text-xs py-1.5 px-3" @click="aiMoreOpen = false">Close</button>
         </div>
      </div>
   </div>

   <div class="mt-4 relative z-30">
      <button type="button"
         id="ai-home-search-btn"
         class="btn relative z-30"
         onclick="if(window.homeAiSearch){window.homeAiSearch.submit();}return false;">
         <span class="material-symbols-outlined text-base">auto_awesome</span>
         <span>Search with AI</span>
      </button>
   </div>

   <p id="ai-home-error" class="mt-3 text-sm text-red-600 min-h-[1.25rem]"></p>
</div>
