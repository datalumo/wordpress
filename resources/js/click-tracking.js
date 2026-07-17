/**
 * Reports result and summary clicks to Datalumo. One capture-phase
 * listener so the event still fires when the click navigates away; the
 * SDK sends it with keepalive, tied to the search session the server-side
 * interception returned. Result-container clicks only count when the link
 * matches a ranked result, so pagination and widget links stay out of the
 * data; summary links always count.
 */
(function () {
    'use strict';

    var config = window.datalumoClicks;

    if (! config || typeof window.Datalumo === 'undefined') {
        return;
    }

    var client = window.Datalumo.headless(
        config.widgetKey,
        config.sessionId ? { sessionId: config.sessionId } : {}
    );

    var container = findContainer(config.selector);

    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;

        if (! link) {
            return;
        }

        if (link.closest('.datalumo-summary')) {
            client.trackEvent('click', { url: link.href, source: 'summary' });

            return;
        }

        if (! container || ! container.contains(link)) {
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

        for (var url in ranks) {
            if (normalise(url) === wanted) {
                return ranks[url];
            }
        }

        return null;
    }

    function normalise(url) {
        return url.replace(/[?#].*$/, '').replace(/\/$/, '');
    }

    function findContainer(selector) {
        var candidates = selector
            ? [selector]
            : ['.wp-block-query', '#primary', '#main', '#content', 'main'];

        for (var i = 0; i < candidates.length; i++) {
            var el = document.querySelector(candidates[i]);

            if (el) {
                return el;
            }
        }

        return null;
    }
})();
