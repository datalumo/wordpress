<?php

namespace Datalumo\Wp\Admin;

use Datalumo\Wp\Api\ApiException;
use Datalumo\Wp\Api\Client;
use Datalumo\Wp\Support\Options;
use Datalumo\Wp\Sync\BulkSync;

class Ajax
{
    public const NONCE = 'datalumo_admin';

    public function register(): void
    {
        add_action('wp_ajax_datalumo_connect', [$this, 'connect']);
        add_action('wp_ajax_datalumo_sync_start', [$this, 'syncStart']);
        add_action('wp_ajax_datalumo_sync_cancel', [$this, 'syncCancel']);
        add_action('wp_ajax_datalumo_sync_status', [$this, 'syncStatus']);
    }

    /**
     * Verify the pasted organisation id + token against /me; on success cache
     * the organisation and its sources so the pickers render offline.
     *
     * The token input is type=password and never re-renders a saved value
     * (only a "saved" placeholder), so an empty POST token falls back to the
     * stored one — same as re-testing without re-pasting the secret.
     *
     * The base URL is taken from the form field when present so Connect &
     * test uses the value on screen even if Settings has not been saved yet.
     * On success it is persisted with the token.
     */
    public function connect(): void
    {
        $this->authorise();

        $organisationId = sanitize_text_field((string) ($_POST['organisation_id'] ?? ''));
        $token = sanitize_text_field((string) ($_POST['token'] ?? ''));
        $apiUrl = array_key_exists('api_url', $_POST)
            ? (esc_url_raw((string) wp_unslash($_POST['api_url'])) ?: 'https://datalumo.app')
            : null;

        if ($organisationId === '') {
            $organisationId = (string) Options::get('organisation.id', '');
        }

        if ($token === '') {
            $token = (string) Options::get('api_token', '');
        }

        if ($organisationId === '' || $token === '') {
            wp_send_json_error(['message' => __('Both the organisation ID and an API token are required.', 'datalumo')]);
        }

        try {
            $me = (new Client())->me($organisationId, $token, $apiUrl);
        } catch (ApiException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        $stored = [
            'api_token' => $token,
            'organisation' => $me['organisation'] ?? ['id' => $organisationId],
            'sources' => $me['sources'] ?? [],
        ];

        if ($apiUrl !== null) {
            $stored['api_url'] = $apiUrl;
        }

        Options::merge($stored);

        wp_send_json_success([
            'organisation' => $me['organisation'] ?? null,
            'sources' => $me['sources'] ?? [],
        ]);
    }

    public function syncStart(): void
    {
        $this->authorise();

        wp_send_json_success((new BulkSync())->start($this->syncId()));
    }

    public function syncCancel(): void
    {
        $this->authorise();

        (new BulkSync())->cancel($this->syncId());

        wp_send_json_success();
    }

    public function syncStatus(): void
    {
        $this->authorise();

        wp_send_json_success((new BulkSync())->status($this->syncId()));
    }

    private function syncId(): string
    {
        return sanitize_text_field((string) ($_POST['sync_id'] ?? ''));
    }

    private function authorise(): void
    {
        check_ajax_referer(self::NONCE);

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'datalumo')], 403);
        }
    }
}
