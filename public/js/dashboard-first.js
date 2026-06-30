/*
 * Impact360 - put the Computer "Dashboard" tab first.
 *
 * GLPI 11 has no server-side hook to reorder item-form tabs (order = core
 * registration + weight; plugin tabs land at the bottom). So we reorder the
 * rendered .nav-tabs client-side: find the tab whose link points at the
 * ComputerDashboard tab and move its <li> to the front. Idempotent; scoped to
 * the Computer form so it's a no-op everywhere else.
 *
 * @license GPL-3.0-or-later
 */
(function () {
    'use strict';

    // Only the Computer form page carries this tab.
    if (!/\/Computer\.form\.php/.test(location.pathname)) {
        return;
    }

    function promote() {
        // The tab key in the link href is URL-encoded
        // (GlpiPlugin%5CImpact360%5CComputerDashboard%241); match on the class
        // name, which is unique enough.
        var links = document.querySelectorAll('ul.nav-tabs a[href*="ComputerDashboard"]');
        for (var i = 0; i < links.length; i++) {
            var li = links[i].closest('li');
            if (!li || !li.parentNode) {
                continue;
            }
            var ul = li.parentNode;
            if (ul.firstElementChild !== li) {
                ul.insertBefore(li, ul.firstElementChild);
            }
        }
    }

    if (document.readyState !== 'loading') {
        promote();
    } else {
        document.addEventListener('DOMContentLoaded', promote);
    }

    // GLPI may (re)render the tab rail after the initial DOM (async). Watch
    // briefly, then stop — promote() is idempotent so re-runs are cheap.
    try {
        var obs = new MutationObserver(function () { promote(); });
        obs.observe(document.body, { childList: true, subtree: true });
        setTimeout(function () { obs.disconnect(); }, 4000);
    } catch (e) { /* MutationObserver unavailable — DOMContentLoaded run suffices */ }
})();
