<?php
// ============================================================================
// eSIM / PLANS — WINDOW
// The window with all plans of one country: local plans first, a switch for the
// kind of plan and for the validity, a button on every plan. "Select" opens the
// booking page of the country with that plan chosen.
// Include it INSIDE the element that carries the Alpine data (esimPlans mixin);
// it moves itself to <body>, so nothing of the page can clip it.
// Built from the modal component (app/views/includes/featured/modal.php).
// ============================================================================
@$SECURE or die('Access Denied!');

$esimChevron = '<svg class="fc-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
?>
<template x-teleport="body">
  <div class="fm" :class="{ 'is-open': country }" @click.self="close()" role="dialog" aria-modal="true" :aria-hidden="!country" :aria-label="country ? country.title : ''">
    <div class="fm-box">

      <div class="fm-head">
        <span class="fc-tile"><img :src="country ? country.flag : ''" alt=""></span>
        <div style="min-width:0">
          <h3 class="fm-title" x-text="country ? country.country_name : ''"></h3>
          <div class="fm-sub">
            <span x-show="plans.length" x-text="plansText(plans.length)"></span>
            <span class="fc-signal" x-show="country && country.network" :data-level="country ? level(country) : 2">
              <span x-text="country ? country.network : ''"></span>
              <span class="fc-bars"><i></i><i></i><i></i><i></i></span>
            </span>
          </div>
        </div>
        <button type="button" class="fm-close" x-ref="plansClose" @click="close()" aria-label="<?= htmlspecialchars((string) T::close) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </div>

      <div class="fm-tools" x-show="plans.length > 1">
        <!-- kind of plan: only when the country has both kinds -->
        <div class="segmented" x-show="count('local') && count('global')">
          <button type="button" class="segmented-item" :class="kind === 'all' ? 'is-active' : ''" @click="setKind('all')"><?= htmlspecialchars((string) T::all) ?></button>
          <button type="button" class="segmented-item" :class="kind === 'local' ? 'is-active' : ''" @click="setKind('local')"><?= htmlspecialchars((string) T::esim_local) ?></button>
          <button type="button" class="segmented-item" :class="kind === 'global' ? 'is-active' : ''" @click="setKind('global')"><?= htmlspecialchars((string) T::esim_global) ?></button>
        </div>
        <!-- validity -->
        <div class="fm-chips" x-show="durations().length > 1">
          <button type="button" class="fm-pick" :class="days === 0 ? 'is-active' : ''" @click="days = 0"><?= htmlspecialchars((string) T::all) ?></button>
          <template x-for="d in durations()" :key="d">
            <button type="button" class="fm-pick" :class="days === d ? 'is-active' : ''" @click="days = d" x-text="d + ' ' + words.days"></button>
          </template>
        </div>
      </div>

      <div class="fm-body" x-ref="plansBody">
        <template x-if="loadingPlans">
          <div aria-hidden="true"><i class="fm-skel"></i><i class="fm-skel"></i><i class="fm-skel"></i><i class="fm-skel"></i></div>
        </template>

        <template x-if="!loadingPlans && plansError">
          <div class="fm-note">
            <div x-text="plansError"></div>
            <button type="button" class="btn" @click="load()"><?= htmlspecialchars((string) T::try_again) ?></button>
          </div>
        </template>

        <template x-if="country && !loadingPlans && !plansError && !plans.length">
          <div class="fm-note"><?= htmlspecialchars((string) T::esim_no_plans) ?></div>
        </template>

        <template x-for="group in groups()" :key="group.kind + kind + days">
          <div>
            <div class="fm-group" x-show="kind === 'all' && count('local') && count('global')"
                 x-text="group.kind === 'local' ? '<?= htmlspecialchars((string) T::esim_local, ENT_QUOTES) ?>' : '<?= htmlspecialchars((string) T::esim_global, ENT_QUOTES) ?>'"></div>
            <template x-for="(plan, n) in group.plans" :key="plan.id">
              <a class="fm-row" :href="planUrl(plan)" :style="{ '--n': n }">
                <div class="fm-row-main">
                  <div class="fm-row-title">
                    <b x-text="plan.data_limit"></b>
                    <span x-text="plan.duration"></span>
                  </div>
                  <div class="fm-row-facts">
                    <span class="fc-chip" x-show="plan.operator" x-text="plan.operator"></span>
                    <span class="fc-chip" x-show="group.kind === 'global'" x-text="plan.country"></span>
                  </div>
                </div>
                <div class="fm-row-price">
                  <span class="fc-price"><small x-text="plan.currency"></small><b x-text="money(plan.price)"></b></span>
                </div>
                <span class="btn"><?= htmlspecialchars((string) T::select) ?><?= $esimChevron ?></span>
              </a>
            </template>
          </div>
        </template>
      </div>

      <div class="fm-foot">
        <span x-text="plans.length && shown() !== plans.length ? shown() + ' / ' + plans.length : ''"></span>
        <a :href="country ? country.url : '#'"><?= htmlspecialchars((string) T::esim_open_country) ?></a>
      </div>

    </div>
  </div>
</template>
