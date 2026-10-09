<?php
// ============================================================================
// HERO / LOTTIE
// Plays the shortcut icons that have a Lottie file (see icons.php). The player
// is fetched only when such an icon is on the page. Until the file has loaded,
// and if it fails, the drawn icon stays.
// ============================================================================
@$SECURE or die('Access Denied!');
?>
<script data-cfasync="false">
  (function () {
    if (window.__heroLottie) return;
    window.__heroLottie = true;

    function play(el) {
      var box = document.createElement('span');
      box.className = 'hr-lottie-box';
      var anim = window.lottie.loadAnimation({ container: box, renderer: 'svg', loop: false, autoplay: false, path: el.dataset.lottie });
      var segment = null;
      anim.addEventListener('DOMLoaded', function () {
        // a Lordicon file holds several states: play the one made for hover
        var marks = (anim.animationData && anim.animationData.markers) || [];
        for (var i = 0; i < marks.length; i++) {
          if (String(marks[i].cm || '').indexOf('hover') !== -1) { segment = [marks[i].tm, marks[i].tm + marks[i].dr]; break; }
        }
        if (segment) anim.goToAndStop(segment[0], true);
        el.innerHTML = '';
        el.appendChild(box);
        el.classList.add('is-ready');
      });
      var host = el.closest('.hr-shortcut') || el;
      var run = function () {
        if (!el.classList.contains('is-ready')) return;
        if (segment) anim.playSegments(segment, true);
        else anim.goToAndPlay(0, true);
      };
      host.addEventListener('mouseenter', run);
      host.addEventListener('focus', run);
    }

    function start() {
      var icons = document.querySelectorAll('.hr-lottie[data-lottie]');
      if (!icons.length) return;
      var s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie_light.min.js';
      s.setAttribute('data-cfasync', 'false');
      s.onload = function () { if (window.lottie) icons.forEach(play); };
      document.head.appendChild(s);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
  })();
</script>
