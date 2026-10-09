<?php
// ============================================================================
// HEADER / SCRIPT
// Behaviour of the bar (Alpine): scrolled state, mobile sheet, lists that are
// fetched from partials/* only when a menu is first opened, and fitting the
// menu to the space that is left in the bar.
// ============================================================================
@$SECURE or die('Access Denied!');
?>
<script>
  // A menu of the bar (Services, the CMS menus, More): with a mouse it opens on
  // hover and closes a moment after the pointer leaves (time to reach the panel);
  // on a touch screen it opens and closes on tap, as before.
  function hdHoverMenu() {
    return {
      open: false,
      timer: null,
      hover: window.matchMedia('(hover: hover) and (pointer: fine)').matches,
      enter() { if (!this.hover) return; clearTimeout(this.timer); this.open = true; },
      leave() { if (!this.hover) return; clearTimeout(this.timer); this.timer = setTimeout(() => { this.open = false; }, 180); },
      // a click on a menu the hover already opened keeps it open
      toggle() { this.open = this.hover ? true : !this.open; },
    };
  }

  function siteHeader(root) {
    return {
      mobile: false,
      scrolled: false,
      parts: {},
      init() {
        const update = () => { this.scrolled = window.scrollY > 8; };
        update();
        window.addEventListener('scroll', update, { passive: true });
        // Leaving phone width with the sheet open would leave it stuck open.
        window.matchMedia('(min-width: 1024px)').addEventListener('change', (e) => { if (e.matches) this.mobile = false; });

        // Keep the menu inside the bar: on load, when the font arrives (text gets
        // wider or narrower) and whenever the bar changes width.
        this.$nextTick(() => this.fitNav());
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => this.fitNav());
        window.addEventListener('load', () => this.fitNav(), { once: true });
        window.addEventListener('resize', () => this.fitNav(), { passive: true });
        if (window.ResizeObserver) {
          new ResizeObserver(() => this.fitNav()).observe(this.$el);
        }
      },
      loadPart(name) {
        if (this.parts[name]) return;
        fetch(root + 'partials/' + name)
          .then((r) => (r.ok ? r.text() : ''))
          .then((html) => { if (html) this.parts[name] = html; });
      },
      // Show as many menu items as fit between the logo and the controls on the
      // right. The rest are listed under "More".
      fitNav() {
        const nav = this.$refs.nav;
        const inner = this.$refs.inner;
        if (!nav || !inner) return;

        const items = Array.from(nav.querySelectorAll(':scope > [data-nav-item]'));
        const more = nav.querySelector(':scope > [data-nav-more]');
        const rows = more ? Array.from(more.querySelectorAll('[data-more-item]')) : [];
        const show = (count) => {
          items.forEach((el, i) => { el.hidden = i >= count; });
          rows.forEach((el, i) => { el.hidden = i < count; });
          if (more) more.hidden = count >= items.length;
        };

        // start from "everything visible" so real widths can be measured
        show(items.length);
        if (getComputedStyle(nav).display === 'none') return; // phone layout: the sheet is used

        const gap = parseFloat(getComputedStyle(nav).columnGap) || 0;
        const innerStyle = getComputedStyle(inner);
        const room = inner.clientWidth - parseFloat(innerStyle.paddingLeft) - parseFloat(innerStyle.paddingRight);

        // everything in the bar that is not the menu
        let fixed = 0;
        const sides = Array.from(inner.children);
        sides.forEach((side, s) => {
          const sideGap = parseFloat(getComputedStyle(side).columnGap) || 0;
          Array.from(side.children).forEach((child) => {
            if (child === nav || getComputedStyle(child).display === 'none') return;
            fixed += child.getBoundingClientRect().width + sideGap;
          });
          if (s > 0) fixed += parseFloat(innerStyle.columnGap) || 0;
        });

        const widths = items.map((el) => el.getBoundingClientRect().width);
        const available = room - fixed - 8; // 8px breathing space
        const total = widths.reduce((sum, w) => sum + w + gap, 0);
        if (total <= available) return;

        let moreWidth = 0;
        if (more) {
          more.hidden = false;
          moreWidth = more.getBoundingClientRect().width + gap;
        }
        let used = moreWidth;
        let count = 0;
        for (const w of widths) {
          if (used + w + gap > available) break;
          used += w + gap;
          count++;
        }
        show(count);
      },
    };
  }
</script>
