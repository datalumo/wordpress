<?php

namespace Datalumo\Wp\Admin;

use Datalumo\Wp\Support\Options;

class SettingsPage
{
    public const NONCE = 'datalumo_settings';

    private const TABS = ['connection', 'content-sync', 'chatbot', 'search-box', 'enhanced-search'];

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

        wp_enqueue_style('datalumo-admin', DATALUMO_URL . 'resources/css/admin.css', [], DATALUMO_VERSION);
        wp_enqueue_script('datalumo-admin', DATALUMO_URL . 'resources/js/admin.js', [], DATALUMO_VERSION, ['in_footer' => true]);

        wp_localize_script('datalumo-admin', 'datalumoAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Ajax::NONCE),
            'i18n' => [
                'connected' => __('Connected to %s — %d source(s) available. Save the page to keep it.', 'datalumo'),
                'connectionFailed' => __('Connection failed:', 'datalumo'),
                'syncProgress' => __('%1$d of %2$d posts synced…', 'datalumo'),
                'syncDone' => __('Sync complete.', 'datalumo'),
            ],
        ]);
    }

    public function render(): void
    {
        $tab = isset($_GET['tab']) && in_array($_GET['tab'], self::TABS, true) ? $_GET['tab'] : 'connection';

        require DATALUMO_DIR . '/resources/views/settings.php';
    }

    public function save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'datalumo'));
        }

        check_admin_referer(self::NONCE);

        $tab = sanitize_key((string) ($_POST['datalumo_tab'] ?? 'connection'));
        $input = wp_unslash($_POST);

        match ($tab) {
            'content-sync' => $this->saveSyncs($input),
            'chatbot' => Options::merge(['chatbot' => [
                'enabled' => ! empty($input['chatbot_enabled']),
                'widget_key' => sanitize_text_field((string) ($input['chatbot_widget_key'] ?? '')),
                'identity_enabled' => ! empty($input['chatbot_identity_enabled']),
                'signing_secret' => sanitize_text_field((string) ($input['chatbot_signing_secret'] ?? '')),
            ]]),
            'search-box' => Options::merge(['search_box' => [
                'widget_key' => sanitize_text_field((string) ($input['search_box_widget_key'] ?? '')),
            ]]),
            'enhanced-search' => Options::merge(['enhanced' => [
                'enabled' => ! empty($input['enhanced_enabled']),
                'widget_key' => sanitize_text_field((string) ($input['enhanced_widget_key'] ?? '')),
                'post_types' => array_map('sanitize_key', (array) ($input['enhanced_post_types'] ?? [])),
                'summary_enabled' => ! empty($input['enhanced_summary_enabled']),
                'summary_selector' => sanitize_text_field((string) ($input['enhanced_summary_selector'] ?? '')),
                'summary_position' => ($input['enhanced_summary_position'] ?? '') === 'append' ? 'append' : 'prepend',
            ]]),
            default => $this->saveConnection($input),
        };

        wp_safe_redirect(add_query_arg(
            ['page' => 'datalumo', 'tab' => $tab, 'updated' => '1'],
            admin_url('options-general.php'),
        ));
        exit;
    }

    private function saveConnection(array $input): void
    {
        // The token itself is saved by the AJAX connect flow (so it's tested
        // before it's stored); the form only carries the base URL override.
        if (array_key_exists('api_url', $input)) {
            Options::set('api_url', esc_url_raw((string) $input['api_url']) ?: 'https://datalumo.com');
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
}
