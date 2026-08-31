<?php

namespace Datalumo\Wp\Admin;

use Datalumo\Wp\Api\ApiException;
use Datalumo\Wp\Api\Client;
use Datalumo\Wp\Support\Credentials;
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
        add_action('wp_ajax_datalumo_dismiss_setup_notice', [$this, 'dismissSetupNotice']);
    }

    /**
     * Verify a pasted API token against GET /api/v1/me. The token identifies
     * the organisation; no organisation ID is required.
     *
     * An empty POST token falls back to the stored one. The password field
     * renders only a "saved" placeholder, never the secret. The base URL comes
     * from the form field (so an unsaved value works) and is persisted on success.
     */
    public function connect(): void
    {
        check_ajax_referer(self::NONCE);

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'datalumo')], 403);
        }

        $token = sanitize_text_field(wp_unslash((string) ($_POST['token'] ?? '')));
        $apiUrl = array_key_exists('api_url', $_POST)
            ? (esc_url_raw(wp_unslash((string) $_POST['api_url'])) ?: 'https://datalumo.app')
            : null;

        if ($token === '') {
            $token = (string) Options::get('api_token', '');
        }

        if ($token === '') {
            wp_send_json_error(['message' => __('An API token is required.', 'datalumo')]);
        }

        if (Credentials::looksLikeWidgetKey($token)) {
            wp_send_json_error(['message' => __('That looks like a widget key. Paste an API token from API keys instead.', 'datalumo')]);
        }

        if (Credentials::looksLikeSecret($token)) {
            wp_send_json_error(['message' => __('That looks like a widget secret. Paste an API token from API keys instead.', 'datalumo')]);
        }

        try {
            $me = (new Client())->me('', $token, $apiUrl);
        } catch (ApiException $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        $stored = [
            'api_token' => $token,
            'organisation' => $me['organisation'] ?? [],
            'sources' => $me['sources'] ?? [],
            'connected_via' => 'token',
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

    public function dismissSetupNotice(): void
    {
        $this->authorise();

        update_user_meta(get_current_user_id(), 'datalumo_hide_setup_notice', '1');

        wp_send_json_success();
    }

    private function syncId(): string
    {
        check_ajax_referer(self::NONCE);

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'datalumo')], 403);
        }

        return sanitize_text_field(wp_unslash((string) ($_POST['sync_id'] ?? '')));
    }

    private function authorise(): void
    {
        check_ajax_referer(self::NONCE);

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'datalumo')], 403);
        }
    }
}
