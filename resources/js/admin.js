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

            var payload = {
                organisation_id: document.getElementById('datalumo-organisation-id').value.trim(),
                token: document.getElementById('datalumo-token').value.trim(),
            };

            // Use the URL on screen (may not be saved yet). Field is absent
            // when DATALUMO_API_URL is defined in wp-config.
            var apiUrlField = document.getElementById('datalumo-api-url');

            if (apiUrlField) {
                payload.api_url = apiUrlField.value.trim();
            }

            post('datalumo_connect', payload).then(function (response) {
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

    function syncEl(selector, syncId) {
        return document.querySelector(selector + '[data-sync="' + syncId + '"]');
    }

    /**
     * Reflect a running/idle sync across its row: while a run is in flight the
     * "Sync now" button is disabled and the row shows a spinner, so it's clear
     * one is already going and can't be started twice.
     */
    function applyRunning(syncId, running) {
        var controls = syncEl('.datalumo-sync-controls', syncId);
        var start = syncEl('.datalumo-sync-start', syncId);
        var cancel = syncEl('.datalumo-sync-cancel', syncId);

        if (start) {
            start.disabled = running;
        }

        if (cancel) {
            cancel.disabled = ! running;
        }

        if (controls) {
            controls.classList.toggle('is-syncing', running);
        }
    }

    function setStatus(syncId, text, tone) {
        var status = syncEl('.datalumo-sync-status', syncId);

        if (! status) {
            return;
        }

        status.textContent = text;
        status.classList.toggle('is-error', tone === 'error');
        status.classList.toggle('is-success', tone === 'success');
    }

    function pollStatus(syncId) {
        post('datalumo_sync_status', { sync_id: syncId }).then(function (response) {
            if (! response.success) {
                return;
            }

            var state = response.data;

            if (state.error) {
                applyRunning(syncId, false);
                setStatus(syncId, state.error, 'error');

                return;
            }

            if (state.running) {
                applyRunning(syncId, true);
                setStatus(syncId, sprintf(config.i18n.syncProgress, state.processed, state.total));
                pollTimers[syncId] = setTimeout(function () { pollStatus(syncId); }, 3000);
            } else {
                applyRunning(syncId, false);

                if (state.total > 0) {
                    setStatus(syncId, config.i18n.syncDone + ' (' + state.processed + '/' + state.total + ')', 'success');
                }
            }
        });
    }

    document.querySelectorAll('.datalumo-sync-start').forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) {
                return;
            }

            var syncId = button.getAttribute('data-sync');

            // Lock the control and show progress straight away, before the
            // first status poll comes back.
            applyRunning(syncId, true);
            setStatus(syncId, config.i18n.syncStarting);
            clearTimeout(pollTimers[syncId]);

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
