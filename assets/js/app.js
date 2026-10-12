// The search boxes show "Oct 13, 2026". The stays address must use 13-10-2026.
// A space in that path makes Apache return 403 before PHP runs.
window.SearchDate = {
    months: {
        jan: '01', feb: '02', mar: '03', apr: '04', may: '05', jun: '06',
        jul: '07', aug: '08', sep: '09', oct: '10', nov: '11', dec: '12'
    },
    getValue: function (input) {
        if (!input) return '';
        var raw = String(input.value || '').trim();
        if (/^\d{2}-\d{2}-\d{4}$/.test(raw)) return raw;
        var display = raw.match(/^([A-Za-z]+)\s+(\d{1,2}),?\s*(\d{4})$/);
        if (!display) return raw;
        var month = this.months[display[1].slice(0, 3).toLowerCase()];
        if (!month) return raw;
        var day = display[2].length === 1 ? '0' + display[2] : display[2];
        return day + '-' + month + '-' + display[3];
    }
};

// Search dropdowns (stays, flights, cars, tours, bus, eSIM).
// Alpine reads these when the form starts. They have to exist before Alpine runs.
window.flIsDesktop = function () {
    return window.matchMedia('(min-width: 1024px)').matches;
};

// Place a teleported panel under its trigger on desktop.
// On a phone the panel is full screen (inset-0) and does not need coordinates.
window.flAnchorPanel = function (triggerId, panelId) {
    var trigger = document.getElementById(triggerId);
    var panel = document.getElementById(panelId);
    if (!trigger || !panel) return;

    if (!window.flIsDesktop()) {
        panel.style.top = '';
        panel.style.left = '';
        panel.style.right = '';
        panel.style.width = '';
        panel.style.maxHeight = '';
        return;
    }

    var rect = trigger.getBoundingClientRect();
    var width = Math.max(rect.width, 280);
    var left = rect.left;
    var maxLeft = Math.max(8, window.innerWidth - width - 8);
    if (left > maxLeft) left = maxLeft;
    if (left < 8) left = 8;

    panel.style.top = rect.bottom + 'px';
    panel.style.left = left + 'px';
    panel.style.right = 'auto';
    panel.style.width = width + 'px';
    panel.style.maxHeight = Math.max(180, window.innerHeight - rect.bottom - 12) + 'px';
};

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
