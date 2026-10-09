<?php
// ============================================================================
// BLOGS / FEATURED
// The latest posts on the home page, as teasers: the picture on one side with
// the category on it, on the other the date and the reading time, the title,
// a line of the text and "Read more". Two to a row, four posts. Built from
// the featured components (app/views/includes/featured/, card variant
// .fc-post). The posts come from /api/blogs, asked only when the section is
// about to be seen.
// ============================================================================
@$SECURE or die('Access Denied!');

$bfLang = $_SESSION['app_language'] ?? 'en';
$bfWords = ['minRead' => T::min_read, 'locale' => ['ch' => 'zh', 'vn' => 'vi'][$bfLang] ?? $bfLang];

require views . 'includes/featured/styles.php';

$bfArrow = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
?>
<style>[x-cloak]{display:none!important}</style>

<!-- Wrapper stays in layout so the scroll check has a real position; content shows once loaded -->
<section class="fs<?= fsBand() ?>" style="min-height:420px"
         x-data="blogsFeatured(<?= htmlspecialchars(json_encode(root . 'api/blogs?limit=4&lang=' . $bfLang), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(root), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($bfWords, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
  <div class="container">

    <!-- SKELETON — VISIBLE FROM FIRST PAINT UNTIL THE POSTS ARRIVE -->
    <div x-show="!loaded" aria-hidden="true">
      <div class="fs-head">
        <span class="fs-skel-line" style="width:280px;max-width:70%;height:36px"></span>
        <span class="fs-skel-line" style="width:96px;height:20px"></span>
      </div>
      <div class="fs-grid-2">
        <?php for ($bfN = 0; $bfN < 4; $bfN++): ?>
        <div class="fc fc-skel fc-post">
          <i style="flex:0 0 42%;min-height:172px;border-radius:16px"></i>
          <div class="fc-post-body"><i style="width:120px;height:12px"></i><i style="width:90%;height:20px;margin-top:12px"></i><i style="width:70%;height:20px;margin-top:6px"></i><i style="width:100%;height:14px;margin-top:12px"></i></div>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <div x-show="items.length" x-cloak>
      <div class="fs-head">
        <h2 class="fs-title">
          <span class="fs-title-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5V5.5M20 18v3H6.5M8 7h8M8 11h8"/></svg>
          </span>
          <span><?= htmlspecialchars((string) T::blog_smart_reads) ?></span>
        </h2>
        <a class="fs-more" href="<?= root ?>blog"><span><?= htmlspecialchars((string) T::view_all) ?></span><?= $bfArrow ?></a>
      </div>

      <div class="fs-grid-2">
        <template x-for="(b, n) in items" :key="b.id">
          <a class="fc fc-post" :href="link(b)" :style="{ '--n': n }">
            <div class="fc-post-photo">
              <img :src="pic(b)" alt="" loading="lazy" decoding="async" @error="$el.src = '<?= root ?>uploads/no_img.jpg'">
              <span class="fc-chip" x-show="b.category_name" x-text="b.category_name"></span>
            </div>
            <div class="fc-post-body">
              <div class="fc-post-kicker"><span x-text="date(b)"></span><i></i><span x-text="readTime(b)"></span></div>
              <h3 class="fc-title" x-text="b.post_title"></h3>
              <p class="fc-post-text" x-show="excerpt(b)" x-text="excerpt(b)"></p>
              <span class="fc-post-more"><?= htmlspecialchars((string) T::read_more) ?><?= $bfArrow ?></span>
            </div>
          </a>
        </template>
      </div>
    </div>

  </div>
</section>

<script data-cfasync="false">
function blogsFeatured(url, root, words) {
    return {
        items: [], loaded: false,

        init() {
            // Fetch the posts only when the section is about to enter the viewport.
            const f = () => {
                if (this._l) return;
                if (this.$el.getBoundingClientRect().top < innerHeight + 400) {
                    this._l = 1;
                    removeEventListener('scroll', f);
                    fetch(url).then(r => r.json())
                        .then(d => { this.items = ((d.data && d.data.blogs) || []).slice(0, 4); this.done(); })
                        .catch(() => this.done());
                }
            };
            addEventListener('scroll', f, { passive: true });
            f();
        },
        done() {
            this.loaded = true;
            this.$el.style.minHeight = '';           // release the reserved space
            if (!this.items.length) this.$el.style.display = 'none';
        },
        link(b) { return root + 'blog/' + b.post_slug; },
        pic(b) { return b.post_img ? root + String(b.post_img).replace(/^\/+/, '') : root + 'uploads/no_img.jpg'; },
        // the words of the post without their tags
        text(b) {
            if (b._text === undefined) {
                const box = document.createElement('div');
                box.innerHTML = b.post_desc || '';
                b._text = (box.textContent || '').replace(/\s+/g, ' ').trim();
            }
            return b._text;
        },
        excerpt(b) {
            const t = this.text(b);
            return t.length > 150 ? t.slice(0, 150).replace(/\s+\S*$/, '') + '…' : t;
        },
        // about 200 words a minute
        readTime(b) {
            const n = Math.max(1, Math.round(this.text(b).split(' ').filter(Boolean).length / 200));
            return n + ' ' + words.minRead;
        },
        date(b) {
            try {
                return new Date(String(b.published_at || b.created_at).replace(' ', 'T')).toLocaleDateString(words.locale, { month: 'short', day: 'numeric', year: 'numeric' });
            } catch (e) {
                return '';
            }
        },
    };
}
</script>
