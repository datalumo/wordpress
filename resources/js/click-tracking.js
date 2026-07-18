/**
 * Reports result and summary clicks to Datalumo. One capture-phase listener
 * so the event still fires when the click navigates away; the SDK sends it
 * with keepalive, tied to the search session the interceptor returned.
 *
 * A result click counts when the link matches a ranked hit (the rank map is
 * the source of truth — no container dependency); summary links always count.
 */
(function () {
    'use strict';

    var config = window.datalumoClicks;

    if (! config || typeof window.Datalumo === 'undefined' || typeof window.Datalumo.headless !== 'function') {
        return;
    }

    var client = window.Datalumo.headless(
        config.widgetKey,
        config.sessionId ? { sessionId: config.sessionId } : {}
    );

    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;

        if (! link) {
            return;
        }

        if (link.closest('.datalumo-summary')) {
            client.trackEvent('click', { url: link.href, source: 'summary' });

            return;
        }

        var rank = rankFor(link.href);

        if (rank !== null) {
            client.trackEvent('click', { url: link.href, rank: rank, source: 'result' });
        }
    }, true);

    function rankFor(href) {
        var ranks = config.ranks || {};

        if (Object.prototype.hasOwnProperty.call(ranks, href)) {
            return ranks[href];
        }

        var wanted = normalise(href);

        if (! wanted) {
            return null;
        }

        for (var url in ranks) {
            if (Object.prototype.hasOwnProperty.call(ranks, url) && normalise(url) === wanted) {
                return ranks[url];
            }
        }

        return null;
    }

    /**
     * Compare permalinks by origin + path, ignoring query/hash, trailing slash,
     * and encoding — themes often differ from get_permalink on one of those.
     */
    function normalise(url) {
        if (! url) {
            return '';
        }

        try {
            var parsed = new URL(url, window.location.href);
            var path = decodeURIComponent(parsed.pathname).replace(/\/$/, '') || '';

            return (parsed.origin + path).toLowerCase();
        } catch (e) {
            return String(url)
                .replace(/[?#].*$/, '')
                .replace(/\/$/, '')
                .toLowerCase();
        }
    }
})();
