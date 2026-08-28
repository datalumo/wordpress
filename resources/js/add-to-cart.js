/**
 * Handles Datalumo host actions named add_to_cart: POST to WordPress so
 * WooCommerce can add the item to this visitor's cart, then settle the chip.
 */
(function () {
    'use strict';

    var config = window.datalumoAddToCart;

    if (! config) {
        return;
    }

    var eventName = config.event || 'add_to_cart';

    window.addEventListener('datalumo:action', function (event) {
        var detail = event.detail || {};

        if (detail.name !== eventName) {
            return;
        }

        event.preventDefault();

        var payload = withPageProduct(
            detail.payload && typeof detail.payload === 'object' && ! Array.isArray(detail.payload)
                ? detail.payload
                : {},
        );

        var body = new FormData();
        body.append('action', 'datalumo_add_to_cart');
        body.append('_ajax_nonce', config.nonce);
        body.append('payload', JSON.stringify(payload));

        fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                var ok = Boolean(json && json.success);
                var data = (json && json.data) || {};
                var message = typeof data.message === 'string' && data.message
                    ? data.message
                    : ((config.i18n && (ok ? '' : config.i18n.failed)) || '');

                finish(detail, {
                    ok: ok,
                    message: message,
                    status: data.status,
                    layout: data.layout,
                    choices: data.choices,
                    url: data.url,
                });

                if (ok) {
                    refreshCart(data.fragments || {}, data.cart_hash || '');
                }
            })
            .catch(function () {
                finish(detail, {
                    ok: false,
                    message: (config.i18n && config.i18n.failed) || '',
                });
            });
    });

    function withPageProduct(payload) {
        var next = {};
        var key;

        for (key in payload) {
            if (Object.prototype.hasOwnProperty.call(payload, key)) {
                next[key] = payload[key];
            }
        }

        if (! hasProductId(next) && config.productId) {
            next.product_id = String(config.productId);
        }

        if (! next.page_url && typeof window.location === 'object' && window.location.href) {
            next.page_url = window.location.href;
        }

        return next;
    }

    function hasProductId(payload) {
        var keys = ['product_id', 'product', 'id', 'external_id'];
        var i;
        var value;

        for (i = 0; i < keys.length; i++) {
            value = parseInt(payload[keys[i]], 10);

            if (value > 0) {
                return true;
            }
        }

        return Boolean(payload.sku && String(payload.sku).trim());
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

    function refreshCart(fragments, cartHash) {
        if (typeof window.jQuery === 'undefined') {
            return;
        }

        var $ = window.jQuery;

        $.each(fragments, function (selector, html) {
            $(selector).replaceWith(html);
        });

        $(document.body).trigger('added_to_cart', [fragments, cartHash, null]);
        $(document.body).trigger('wc_fragment_refresh');
    }
})();
