/**
 * Renders an AI summary of the visitor's search into the results page.
 * Talks to the Datalumo API straight from the browser via the SDK loaded
 * from the app host; the answer arrives whole and renders as Markdown.
 * If anything fails, the box quietly removes itself.
 */
(function () {
    'use strict';

    var config = window.datalumoSummary;

    if (! config || typeof window.Datalumo === 'undefined') {
        return;
    }

    var container = findContainer(config.selector);

    if (! container) {
        return;
    }

    var box = document.createElement('div');
    box.className = 'datalumo-summary';
    box.innerHTML = '<div class="datalumo-summary-heading">' + escapeHtml(config.i18n.heading) + '</div>'
        + '<div class="datalumo-summary-body" aria-live="polite">' + escapeHtml(config.i18n.generating) + '</div>';

    if (config.position === 'append') {
        container.appendChild(box);
    } else {
        container.insertBefore(box, container.firstChild);
    }

    var body = box.querySelector('.datalumo-summary-body');

    window.Datalumo.headless(config.widgetKey)
        .summarize(config.query)
        .then(function (result) {
            if (! result.summarized || result.text === '') {
                box.remove();

                return;
            }

            body.innerHTML = window.Datalumo.markdown(result.text);
        })
        .catch(function () {
            box.remove();
        });

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

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value;

        return div.innerHTML;
    }
})();
