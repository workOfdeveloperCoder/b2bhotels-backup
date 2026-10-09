// Fast Page Loader with Smooth Transitions
(function () {
    let hidden = false;

    function hide() {
        if (hidden) return;
        hidden = true;
        const loader = document.getElementById('page-loader');
        if (!loader) return;
        loader.classList.add('hide');
        setTimeout(() => { loader.style.display = 'none'; }, 300);
    }

    function show() {
        hidden = false;
        const loader = document.getElementById('page-loader');
        if (!loader) return;
        loader.style.display = 'flex';
        loader.classList.remove('hide');
    }

    // Hide on every possible ready signal
    if (document.readyState === 'complete') {
        hide();
    } else {
        document.addEventListener('DOMContentLoaded', hide);
        window.addEventListener('load', hide);
    }

    // Fallback: hide after 1.8s no matter what
    setTimeout(hide, 1800);

    // Handle back/forward cache
    window.addEventListener('pageshow', e => {
        if (e.persisted) hide();
    });

    // Show loader on navigation clicks
    document.addEventListener('click', e => {
        const link = e.target.closest('a[href]');
        if (!link) return;

        const href = link.getAttribute('href');

        if (!href ||
            href[0] === '#' ||
            href.startsWith('javascript:') ||
            link.target === '_blank' ||
            link.download ||
            (href.includes('://') && !href.startsWith(location.origin))) {
            return;
        }

        e.preventDefault();
        show();

        requestAnimationFrame(() => { location.href = href; });
    }, true);
})();
