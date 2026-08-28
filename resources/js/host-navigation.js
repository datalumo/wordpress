/**
 * Handles Datalumo host actions that open a page: cart, checkout, a
 * WordPress post, or a form on this page (or another page).
 */
(function () {
    'use strict';

    var config = window.datalumoHostNavigation;

    if (! config) {
        return;
    }

    var events = config.events || ['view_cart', 'open_checkout', 'open_page', 'start_form'];

    window.addEventListener('datalumo:action', function (event) {
        var detail = event.detail || {};

        if (events.indexOf(detail.name) === -1) {
            return;
        }

        event.preventDefault();

        var payload = isPlainObject(detail.payload) ? detail.payload : {};

        if (detail.name === 'view_cart') {
            go(detail, config.cartUrl, (config.i18n && config.i18n.no_cart) || '');

            return;
        }

        if (detail.name === 'open_checkout') {
            go(detail, config.checkoutUrl, (config.i18n && config.i18n.no_checkout) || '');

            return;
        }

        if (detail.name === 'start_form') {
            var form = findForm(payload);

            if (form) {
                scrollToForm(form);
                finish(detail, { ok: true, message: '' });

                return;
            }
        }

        resolveThenGo(detail, payload);
    });

    bootPendingForm();

    function resolveThenGo(detail, payload) {
        var body = new FormData();
        var request = {};
        var key;

        for (key in payload) {
            if (Object.prototype.hasOwnProperty.call(payload, key)) {
                request[key] = payload[key];
            }
        }

        request.event = detail.name;
        body.append('action', 'datalumo_host_navigation');
        body.append('_ajax_nonce', config.nonce);
        body.append('payload', JSON.stringify(request));

        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                var data = (json && json.data) || {};
                var url = typeof data.url === 'string' ? data.url : '';

                if (json && json.success && isSameSite(url)) {
                    finish(detail, { ok: true, message: '' });
                    window.location.assign(url);

                    return;
                }

                finish(detail, {
                    ok: false,
                    message: (typeof data.message === 'string' && data.message)
                        || (config.i18n && config.i18n.failed)
                        || '',
                });
            })
            .catch(function () {
                finish(detail, {
                    ok: false,
                    message: (config.i18n && config.i18n.failed) || '',
                });
            });
    }

    function go(detail, url, missingMessage) {
        if (! isSameSite(url)) {
            finish(detail, { ok: false, message: missingMessage || (config.i18n && config.i18n.failed) || '' });

            return;
        }

        finish(detail, { ok: true, message: '' });
        window.location.assign(url);
    }

    function findForm(payload) {
        var selector = sanitizeSelector(payload.selector);
        var el;

        if (selector) {
            el = document.querySelector(selector);

            if (el) {
                return el;
            }
        }

        var id = String(payload.form_id || '').replace(/[^\w-]/g, '');

        if (id) {
            el = firstMatch([
                '#' + id,
                '#wpcf7-f' + id,
                '#wpforms-' + id,
                '#gform_' + id,
                '#gform_wrapper_' + id,
            ]);

            if (el) {
                return el;
            }
        }

        return document.querySelector(
            'form.wpcf7-form, form.wpforms-form, .gform_wrapper form, form:not([role="search"]):not(.search-form):not(.woocommerce-cart-form):not(.woocommerce-checkout)',
        );
    }

    function firstMatch(selectors) {
        var i;
        var el;

        for (i = 0; i < selectors.length; i++) {
            el = document.querySelector(selectors[i]);

            if (el) {
                return el;
            }
        }

        return null;
    }

    function sanitizeSelector(value) {
        var selector = String(value || '').trim();

        if (selector === '') {
            return '';
        }

        if (/^#?[A-Za-z][\w-]*$/.test(selector)) {
            return selector.charAt(0) === '#' ? selector : '#' + selector;
        }

        if (/^\.[A-Za-z][\w-]*$/.test(selector)) {
            return selector;
        }

        if (/^[A-Za-z][\w-]*(\.[A-Za-z][\w-]*)?(#[A-Za-z][\w-]*)?$/.test(selector)) {
            return selector;
        }

        return '';
    }

    function scrollToForm(el) {
        if (typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }

        var focusable = el.matches && el.matches('input, textarea, select')
            ? el
            : el.querySelector('input:not([type="hidden"]), textarea, select');

        if (focusable && typeof focusable.focus === 'function') {
            focusable.focus({ preventScroll: true });
        }
    }

    function bootPendingForm() {
        var params = new URLSearchParams(window.location.search);
        var pending = params.get('datalumo_form') || '';
        var hash = (window.location.hash || '').replace(/^#/, '');
        var form = findForm({
            form_id: pending || (hash && hash !== 'datalumo-form' ? hash : ''),
        });

        if (form && (pending || hash === 'datalumo-form' || hash)) {
            scrollToForm(form);
        }
    }

    function isSameSite(url) {
        if (! url || typeof url !== 'string') {
            return false;
        }

        try {
            var parsed = new URL(url, window.location.href);
            var home = String(config.homeHost || window.location.hostname).toLowerCase();
            var host = parsed.hostname.toLowerCase();

            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
                return false;
            }

            return host === home || host === 'www.' + home || 'www.' + host === home;
        } catch (error) {
            return false;
        }
    }

    function isPlainObject(value) {
        return Boolean(value) && typeof value === 'object' && ! Array.isArray(value);
    }

    function finish(detail, result) {
        if (typeof detail.respond === 'function') {
            detail.respond(result);

            return;
        }

        window.dispatchEvent(new CustomEvent('datalumo:action-result', {
            detail: {
                answer_id: detail.answer_id,
                tool_id: detail.tool_id,
                ok: result.ok !== false,
                message: result.message || '',
            },
        }));
    }
})();
