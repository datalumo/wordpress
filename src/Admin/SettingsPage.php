<?php

namespace Datalumo\Wp\Admin;

use Datalumo\Wp\Api\ApiException;
use Datalumo\Wp\Api\Client;
use Datalumo\Wp\Support\Assets;
use Datalumo\Wp\Support\Credentials;
use Datalumo\Wp\Support\Options;

class SettingsPage
{
    public const NONCE = 'datalumo_settings';

    public const ASK_MAX_LENGTH = 280;

    private const TABS = ['connection', 'content-sync', 'chatbot', 'search-box', 'enhanced-search'];

    private bool $widgetKeyRejected = false;

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_options_page(
                __('Datalumo', 'datalumo'),
                __('Datalumo', 'datalumo'),
                'manage_options',
                'datalumo',
                [$this, 'render'],
            );
        });

        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_datalumo_save', [$this, 'save']);
    }

    public function assets(string $hook): void
    {
        if ($hook !== 'settings_page_datalumo') {
            return;
        }

        wp_enqueue_style('datalumo-admin', DATALUMO_URL . 'resources/css/admin.css', [], Assets::version());
        wp_enqueue_script('datalumo-admin', DATALUMO_URL . 'resources/js/admin.js', [], Assets::version(), ['in_footer' => true]);

        wp_localize_script('datalumo-admin', 'datalumoAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Ajax::NONCE),
            'i18n' => [
                /* translators: 1: organisation name, 2: number of sources */
                'connected' => __('Connected to %1$s — %2$d source(s) available.', 'datalumo'),
                'connectionFailed' => __('Connection failed:', 'datalumo'),
                'syncStarting' => __('Starting sync…', 'datalumo'),
                /* translators: 1: number of posts already synced, 2: total posts */
                'syncProgress' => __('%1$d of %2$d posts synced…', 'datalumo'),
                'syncDone' => __('Sync complete.', 'datalumo'),
            ],
        ]);
    }

    public function render(): void
    {
        $datalumo_tab = $this->requestedTab();

        if ($datalumo_tab === 'content-sync') {
            $this->refreshSources();
        }

        require DATALUMO_DIR . '/resources/views/settings.php';
    }

    /**
     * Other tabs are empty until a connection exists and the post-connect
     * checklist (if any) has been saved.
     */
    public static function setupIsReady(): bool
    {
        return Options::isConnected() && ! Options::get('setup_pending');
    }

    public static function docsUrl(string $hash = ''): string
    {
        $url = Options::baseUrl().'/docs/wordpress';

        return $hash !== '' ? $url.'#'.$hash : $url;
    }

    /**
     * Landing page for the settings "Need help?" form. Query `ask` is
     * honoured on first-party docs only, as a composer prefill.
     */
    public static function helpUrl(): string
    {
        return self::docsUrl();
    }

    /**
     * Pull a fresh source list (including knowledge base names) so the
     * picker stays current after a connect or a rename in Datalumo.
     */
    private function refreshSources(): void
    {
        if (! Options::isConnected()) {
            return;
        }

        try {
            $me = (new Client())->me('', (string) Options::get('api_token'));
        } catch (ApiException) {
            return;
        }

        if (isset($me['sources']) && is_array($me['sources'])) {
            Options::set('sources', $me['sources']);
        }
    }

    private function requestedTab(): string
    {
        if (! current_user_can('manage_options')) {
            return 'connection';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- settings tab is a navigation query arg.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        if (! self::setupIsReady()) {
            return 'connection';
        }

        return in_array($tab, self::TABS, true) ? $tab : 'connection';
    }

    public function save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'datalumo'));
        }

        check_admin_referer(self::NONCE);

        $input = wp_unslash($_POST);
        $tab = sanitize_key((string) ($input['datalumo_tab'] ?? 'connection'));

        if (! in_array($tab, self::TABS, true)) {
            $tab = 'connection';
        }

        match ($tab) {
            'content-sync' => $this->saveSyncs($input),
            'chatbot' => Options::merge(['chatbot' => [
                'enabled' => ! empty($input['chatbot_enabled']),
                'widget_key' => $this->sanitizeWidgetKey((string) ($input['chatbot_widget_key'] ?? ''), (string) Options::get('chatbot.widget_key', '')),
                'identity_enabled' => ! empty($input['chatbot_identity_enabled']),
                'signing_secret' => $this->sanitizeSigningSecret((string) ($input['chatbot_signing_secret'] ?? '')),
            ]]),
            'search-box' => Options::merge(['search_box' => [
                'widget_key' => $this->sanitizeWidgetKey((string) ($input['search_box_widget_key'] ?? ''), (string) Options::get('search_box.widget_key', '')),
            ]]),
            'enhanced-search' => Options::merge(['enhanced' => [
                'enabled' => ! empty($input['enhanced_enabled']),
                'widget_key' => $this->sanitizeWidgetKey((string) ($input['enhanced_widget_key'] ?? ''), (string) Options::get('enhanced.widget_key', '')),
                'post_types' => array_map('sanitize_key', (array) ($input['enhanced_post_types'] ?? [])),
                'summary_enabled' => ! empty($input['enhanced_summary_enabled']),
                'summary_selector' => sanitize_text_field((string) ($input['enhanced_summary_selector'] ?? '')),
                'summary_position' => ($input['enhanced_summary_position'] ?? '') === 'append' ? 'append' : 'prepend',
            ]]),
            default => $this->saveConnection($input),
        };

        $args = [
            'page' => 'datalumo',
            'tab' => $tab,
            '_wpnonce' => wp_create_nonce('datalumo_settings_updated'),
        ];

        if ($this->widgetKeyRejected) {
            $args['datalumo_notice'] = 'invalid_widget_key';
        } else {
            // options-head.php prints "Settings saved." when updated=1 on a Settings screen.
            $args['updated'] = '1';
        }

        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }

    private function saveConnection(array $input): void
    {
        // Base URL can be saved here without connecting; Connect & test also
        // persists it after a successful /me call (see Ajax::connect).
        if (array_key_exists('api_url', $input)) {
            Options::set('api_url', esc_url_raw((string) $input['api_url']) ?: 'https://datalumo.app');
        }
    }

    private function saveSyncs(array $input): void
    {
        $syncs = [];

        foreach ((array) ($input['syncs'] ?? []) as $row) {
            $sourceId = sanitize_text_field((string) ($row['source_id'] ?? ''));
            $postTypes = array_map('sanitize_key', (array) ($row['post_types'] ?? []));

            if ($sourceId === '' || $postTypes === []) {
                continue;
            }

            $mappings = [];

            foreach ((array) ($row['mappings'] ?? []) as $mapping) {
                $name = sanitize_key((string) ($mapping['name'] ?? ''));
                $source = sanitize_text_field((string) ($mapping['source'] ?? ''));

                if ($name === '' || $source === '') {
                    continue;
                }

                $mappings[] = [
                    'name' => $name,
                    'type' => ($mapping['type'] ?? '') === 'taxonomy' ? 'taxonomy' : 'post_meta',
                    'source' => $source,
                    'searchable' => ! empty($mapping['searchable']),
                ];
            }

            $syncs[] = [
                'id' => sanitize_key((string) ($row['id'] ?? '')) ?: wp_generate_uuid4(),
                'source_id' => $sourceId,
                'post_types' => $postTypes,
                'meta_mappings' => $mappings,
            ];
        }

        Options::set('syncs', $syncs);
    }

    private function sanitizeWidgetKey(string $value, string $current = ''): string
    {
        $value = sanitize_text_field($value);

        if ($value === '' || Credentials::looksLikeWidgetKey($value)) {
            return $value;
        }

        $this->widgetKeyRejected = true;

        return $current;
    }

    private function sanitizeSigningSecret(string $value): string
    {
        $value = sanitize_text_field($value);

        return $value !== '' ? $value : (string) Options::get('chatbot.signing_secret', '');
    }
}
