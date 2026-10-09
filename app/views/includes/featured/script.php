<?php
// ============================================================================
// FEATURED / SCRIPT
// featuredTrack(): what every featured row needs — move one screen of cards,
// and know when the row is at its start or its end (to switch the buttons off).
// A section mixes it into its own Alpine data:  { ...featuredTrack(), ... }
// The row is the element with x-ref="track".
// ============================================================================
@$SECURE or die('Access Denied!');

if (!empty($GLOBALS['__featuredScript'])) {
    return;
}
$GLOBALS['__featuredScript'] = true;
?>
<script data-cfasync="false">
  function featuredTrack() {
    return {
      atStart: true,
      atEnd: false,
      // dir: 1 = next, -1 = previous (the way of reading decides the side)
      move(dir) {
        const t = this.$refs.track;
        if (!t) return;
        // ONE SCREEN OF CARDS: THE CARDS THAT ARE IN VIEW MAKE ROOM FOR THE NEXT ONES
        const card = t.querySelector(".fc");
        const step = card ? card.getBoundingClientRect().width + 16 : 260;
        const screen = Math.max(step, Math.round(t.clientWidth / step) * step);
        const rtl = document.documentElement.dir === "rtl";
        t.scrollBy({ left: dir * (rtl ? -1 : 1) * screen, behavior: "smooth" });
      },
      sync() {
        const t = this.$refs.track;
        if (!t) return;
        // the first measure can come before the row is shown (width 0, so "at the end" and no
        // buttons): watch the row and measure again whenever its size or its cards change
        if (!this._ro && window.ResizeObserver) {
          this._ro = new ResizeObserver(() => this.sync());
          this._ro.observe(t);
          new MutationObserver(() => this.sync()).observe(t, { childList: true });
        }
        const x = Math.abs(t.scrollLeft);
        this.atStart = x <= 5;
        this.atEnd = Math.ceil(x + t.clientWidth) >= t.scrollWidth - 5;
      },
    };
  }
</script>
