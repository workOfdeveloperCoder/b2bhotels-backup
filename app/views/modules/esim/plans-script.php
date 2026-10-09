<?php
// ============================================================================
// eSIM / PLANS — SCRIPT
// esimPlans(words): what every eSIM list needs. A page mixes it into its own
// Alpine data:  { ...esimPlans(words), ... }
//   - words and numbers of a card: level(), dataText(), daysText(), money()
//   - the window with the plans of one country: open(), close(), groups() ...
// $esimWords holds the words in the language of the visitor.
// The window itself is printed by plans-modal.php.
// ============================================================================
@$SECURE or die('Access Denied!');

$esimWords = [
    'days'      => (string) T::esim_days,
    'all'       => (string) T::view_all,
    'unlimited' => mb_convert_case((string) T::unlimited, MB_CASE_TITLE, 'UTF-8'),
    'browse'    => (string) T::esim_browse_destinations,
    'plans'     => (string) T::esim_plans_count,
    'error'     => (string) T::esim_plans_error,
    'showing'   => (string) T::esim_showing,
    'note'      => (string) T::esim_note,
];

if (!empty($GLOBALS['__esimPlansScript'])) {
    return;
}
$GLOBALS['__esimPlansScript'] = true;
?>
<script data-cfasync="false">
function esimPlans(words) {
    return {
        words,
        // the window with the plans of one country
        country: null, plans: [], kind: 'all', days: 0, loadingPlans: false, plansError: '', opener: null, kept: {},

        // ------------------------------------------------------------ CARDS
        // bars that are lit: 5G = 4, 4G = 3, 3G = 2, not known = 2
        level(item) {
            return ({ '5G': 4, '4G': 3, '3G': 2 })[item.network] || 2;
        },
        size(mb) {
            if (mb >= 1024) return (Math.round(mb / 102.4) / 10) + ' GB';
            return mb + ' MB';
        },
        dataText(item) {
            if (!item.plans) return '';
            if (!item.mb_min) return item.unlimited ? words.unlimited : '';
            if (item.unlimited) return this.size(item.mb_min) + ' – ' + words.unlimited;
            if (item.mb_max > item.mb_min) return this.size(item.mb_min) + ' – ' + this.size(item.mb_max);
            return this.size(item.mb_min);
        },
        daysText(item) {
            if (!item.plans || !item.days_min) return '';
            const range = item.days_max > item.days_min ? item.days_min + '–' + item.days_max : String(item.days_min);
            return range + ' ' + words.days;
        },
        plansText(n) {
            return words.plans.replace('{n}', n);
        },
        money(n) {
            return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        // ------------------------------------------------------------ PLANS OF A COUNTRY
        open(item, from) {
            this.opener = from || null;
            this.country = item;
            this.kind = 'all';
            this.days = 0;
            this.plans = [];
            this.plansError = '';
            document.documentElement.style.overflow = 'hidden';
            this.load();
            this.$nextTick(() => {
                if (this.$refs.plansBody) this.$refs.plansBody.scrollTop = 0;
                if (this.$refs.plansClose) this.$refs.plansClose.focus({ preventScroll: true });
            });
        },
        close() {
            if (!this.country) return;
            this.country = null;
            document.documentElement.style.overflow = '';
            if (this.opener && this.opener.focus) this.opener.focus({ preventScroll: true });
        },
        load() {
            const item = this.country;
            if (!item) return;
            if (this.kept[item.id]) { this.plans = this.kept[item.id]; return; }
            this.loadingPlans = true;
            this.plansError = '';
            fetch(item.url + 'packages?currency=' + encodeURIComponent(item.currency || ''))
                .then(r => r.json())
                .then(d => {
                    if (!d.success) throw new Error(d.message || words.error);
                    const plans = (d.packages || []).slice().sort((a, b) => Number(a.price || 0) - Number(b.price || 0));
                    this.kept[item.id] = plans;
                    if (this.country === item) this.plans = plans;
                })
                .catch(() => { if (this.country === item) this.plansError = words.error; })
                .finally(() => { if (this.country === item) this.loadingPlans = false; });
        },
        kindOf(plan) {
            return plan.package_type === 'global' ? 'global' : 'local';
        },
        count(kind) {
            return this.plans.filter(p => this.kindOf(p) === kind).length;
        },
        // the validities on offer (7, 15, 30 ...) for the kind of plan that is shown
        durations() {
            const seen = {};
            this.plans.forEach(p => {
                if (p.days > 0 && (this.kind === 'all' || this.kindOf(p) === this.kind)) seen[p.days] = true;
            });
            return Object.keys(seen).map(Number).sort((a, b) => a - b);
        },
        setKind(kind) {
            this.kind = kind;
            if (this.days && !this.durations().includes(this.days)) this.days = 0;
        },
        // local plans first, then the regional and global ones; each by price
        groups() {
            if (this.loadingPlans || this.plansError) return [];
            return ['local', 'global']
                .filter(k => this.kind === 'all' || this.kind === k)
                .map(k => ({ kind: k, plans: this.plans.filter(p => this.kindOf(p) === k && (!this.days || p.days === this.days)) }))
                .filter(g => g.plans.length);
        },
        shown() {
            return this.groups().reduce((n, g) => n + g.plans.length, 0);
        },
        planUrl(plan) {
            return this.country ? this.country.url + '?package=' + encodeURIComponent(plan.id) : '#';
        },
    };
}
</script>
