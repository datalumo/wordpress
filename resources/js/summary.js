/**
 * Renders an AI summary of the visitor's search into the results page.
 * Talks to the Datalumo API straight from the browser via the SDK loaded
 * from the app host; the answer arrives whole and renders as Markdown.
 * If anything fails, the box quietly removes itself.
 */
(function () {
    'use strict';

    var SPARKLE_ICON = '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">'
        + '<defs><linearGradient id="datalumo-sparkle" x1="0" y1="0" x2="24" y2="24" gradientUnits="userSpaceOnUse">'
        + '<stop offset="0" stop-color="#4285f4"/><stop offset="0.5" stop-color="#a855f7"/><stop offset="1" stop-color="#ec4899"/>'
        + '</linearGradient></defs>'
        + '<path fill="url(#datalumo-sparkle)" d="M12 2l2.4 7.6L22 12l-7.6 2.4L12 22l-2.4-7.6L2 12l7.6-2.4z"/></svg>';

    var CHEVRON_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="m6 9 6 6 6-6"/></svg>';

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
    box.innerHTML = '<div class="datalumo-summary-heading">' + SPARKLE_ICON
        + '<span>' + escapeHtml(config.i18n.heading) + '</span></div>'
        + '<div class="datalumo-summary-body" aria-live="polite" aria-label="' + escapeHtml(config.i18n.generating) + '">'
        + '<div class="datalumo-skeleton"><span></span><span></span><span class="datalumo-skeleton-short"></span></div>'
        + '</div>';

    if (config.position === 'append') {
        container.appendChild(box);
    } else {
        container.insertBefore(box, container.firstChild);
    }

    var body = box.querySelector('.datalumo-summary-body');

    window.Datalumo.headless(config.widgetKey)
        .summarize(config.query, { filters: config.filters || {} })
        .then(function (result) {
            if (! result.summarized || result.text === '') {
                box.remove();

                return;
            }

            body.innerHTML = '<div class="datalumo-summary-answer">'
                + window.Datalumo.markdown(result.text) + '</div>';

            // Collapsed by default; the toggle appears only when the
            // summary actually overflows the fixed height.
            if (body.scrollHeight > body.clientHeight + 4) {
                box.classList.add('datalumo-summary--overflowing');
                box.appendChild(buildToggle());
            }
        })
        .catch(function () {
            box.remove();
        });

    function buildToggle() {
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'datalumo-summary-more';
        toggle.setAttribute('aria-expanded', 'false');

        var label = document.createElement('span');
        label.textContent = config.i18n.show_more;

        toggle.appendChild(label);
        toggle.insertAdjacentHTML('beforeend', CHEVRON_ICON);

        toggle.addEventListener('click', function () {
            var expanded = box.classList.toggle('datalumo-summary--expanded');
            label.textContent = expanded ? config.i18n.show_less : config.i18n.show_more;
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });

        return toggle;
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

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value;

        return div.innerHTML;
    }
})();
