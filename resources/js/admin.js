/**
 * Settings-page behaviour: the connect & test flow and bulk-sync controls.
 */
(function () {
    'use strict';

    var config = window.datalumoAdmin;

    if (! config) {
        return;
    }

    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('_ajax_nonce', config.nonce);

        Object.keys(data || {}).forEach(function (key) {
            body.append(key, data[key]);
        });

        return fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) { return response.json(); });
    }

    function sprintf(template) {
        var args = Array.prototype.slice.call(arguments, 1);
        var index = 0;

        return template.replace(/%(\d+\$)?[sd]/g, function (match, position) {
            var i = position ? parseInt(position, 10) - 1 : index++;

            return String(args[i]);
        });
    }

    // --- Connection tab ---------------------------------------------------

    var connectButton = document.getElementById('datalumo-connect');

    if (connectButton) {
        connectButton.addEventListener('click', function () {
            var result = document.getElementById('datalumo-connect-result');

            connectButton.disabled = true;
            result.textContent = '…';

            post('datalumo_connect', {
                organisation_id: document.getElementById('datalumo-organisation-id').value.trim(),
                token: document.getElementById('datalumo-token').value.trim(),
            }).then(function (response) {
                connectButton.disabled = false;

                if (response.success) {
                    var organisation = response.data.organisation || {};
                    result.textContent = sprintf(
                        config.i18n.connected,
                        organisation.name || organisation.id || '',
                        (response.data.sources || []).length
                    );
                    result.classList.add('is-success');
                } else {
                    result.textContent = config.i18n.connectionFailed + ' ' + ((response.data || {}).message || '');
                    result.classList.add('is-error');
                }
            });
        });
    }

    // --- Content sync tab -------------------------------------------------

    var pollTimers = {};

    function pollStatus(syncId) {
        var status = document.querySelector('.datalumo-sync-status[data-sync="' + syncId + '"]');

        post('datalumo_sync_status', { sync_id: syncId }).then(function (response) {
            if (! response.success || ! status) {
                return;
            }

            var state = response.data;

            if (state.error) {
                status.textContent = state.error;
                status.classList.add('is-error');

                return;
            }

            if (state.running) {
                status.textContent = sprintf(config.i18n.syncProgress, state.processed, state.total);
                pollTimers[syncId] = setTimeout(function () { pollStatus(syncId); }, 3000);
            } else if (state.total > 0) {
                status.textContent = config.i18n.syncDone + ' (' + state.processed + '/' + state.total + ')';
            }
        });
    }

    document.querySelectorAll('.datalumo-sync-start').forEach(function (button) {
        button.addEventListener('click', function () {
            var syncId = button.getAttribute('data-sync');

            post('datalumo_sync_start', { sync_id: syncId }).then(function () {
                pollStatus(syncId);
            });
        });
    });

    document.querySelectorAll('.datalumo-sync-cancel').forEach(function (button) {
        button.addEventListener('click', function () {
            var syncId = button.getAttribute('data-sync');

            clearTimeout(pollTimers[syncId]);
            post('datalumo_sync_cancel', { sync_id: syncId }).then(function () {
                pollStatus(syncId);
            });
        });
    });

    document.querySelectorAll('.datalumo-sync-status[data-sync]').forEach(function (status) {
        pollStatus(status.getAttribute('data-sync'));
    });
})();
