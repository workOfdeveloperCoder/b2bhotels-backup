<?php
// ============================================================================
// HERO / BACKGROUND
// The site cover image, kept alive: it drifts and zooms slowly for 16 seconds,
// then the same picture fades in again from the start (two layers take turns).
// While the page scrolls the picture moves slower than the page (parallax).
// The picture has its own height: a form that gets taller or shorter never
// moves or resizes it.
// ============================================================================
@$SECURE or die('Access Denied!');

$heroCover = versionedAssetUrl('uploads/global/cover.png');
?>
<div class="hr-bg" id="heroBg">
  <div class="hr-bg-move">
    <img class="hr-bg-layer" alt="" decoding="async" sizes="100vw" data-src="<?= htmlspecialchars($heroCover, ENT_QUOTES) ?>">
    <img class="hr-bg-layer is-b" alt="" decoding="async" sizes="100vw" data-src="<?= htmlspecialchars($heroCover, ENT_QUOTES) ?>">
  </div>
</div>
<div class="hr-shade" id="heroShade"></div>
<script data-cfasync="false">
  (function () {
    var bg = document.getElementById('heroBg');
    var hero = bg && bg.closest('.hr');
    if (!hero) return;

    // SHOW THE PICTURE ONCE IT HAS LOADED
    var layers = bg.querySelectorAll('.hr-bg-layer');
    var ready = function () { bg.classList.add('is-ready'); };
    layers[0].addEventListener('load', ready, { once: true });
    layers[0].addEventListener('error', ready, { once: true });
    layers.forEach(function (img) { img.src = img.dataset.src; });
    if (layers[0].complete && layers[0].naturalWidth) ready();

    // THE PICTURE IS AS TALL AS THE HERO HAS EVER BEEN: IT GROWS, IT NEVER SHRINKS,
    // SO SWITCHING TO A SHORTER FORM LEAVES IT EXACTLY WHERE IT IS
    var tall = 0, wide = 0;
    var fit = function () {
      if (window.innerWidth !== wide) { wide = window.innerWidth; tall = 0; }
      var h = hero.offsetHeight;
      if (h > tall) { tall = h; bg.style.setProperty('--hr-bg-h', h + 'px'); }
    };
    window.heroFitBackground = fit;
    window.addEventListener('load', fit);
    window.addEventListener('resize', fit);
    if ('ResizeObserver' in window) new ResizeObserver(fit).observe(hero);

    // PARALLAX (NOT ON PHONES, NOT WHEN THE VISITOR ASKED FOR LESS MOTION)
    var calm = window.matchMedia('(prefers-reduced-motion: reduce)');
    var shade = document.getElementById('heroShade');
    var head = hero.querySelector('.hr-head');
    var queued = false;
    var move = function () {
      queued = false;
      var on = !calm.matches && window.innerWidth >= 768;
      var h = hero.offsetHeight || 1;
      var p = on ? Math.min(Math.max(window.scrollY / h, 0), 1) : 0;
      bg.style.setProperty('--hr-par', (p * 0.3 * h).toFixed(1) + 'px');
      if (shade) shade.style.opacity = String(1 - Math.min(p, 0.5));
      if (!head) head = hero.querySelector('.hr-head');
      if (head) head.style.transform = p ? 'translate3d(0,' + (p * 0.2 * head.offsetHeight).toFixed(1) + 'px,0)' : '';
    };
    window.addEventListener('scroll', function () {
      if (queued) return;
      queued = true;
      requestAnimationFrame(move);
    }, { passive: true });
  })();
</script>
