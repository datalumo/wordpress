/**
 * Persist dismissal of the dashboard setup notice.
 */
(function () {
    'use strict';

    var config = window.datalumoSetupNotice;

    if (! config) {
        return;
    }

    document.addEventListener('click', function (event) {
        var dismiss = event.target && event.target.closest
            ? event.target.closest('.datalumo-setup-notice .notice-dismiss')
            : null;

        if (! dismiss) {
            return;
        }

        var body = new FormData();
        body.append('action', 'datalumo_dismiss_setup_notice');
        body.append('_ajax_nonce', config.nonce);

        fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' });
    });
})();
