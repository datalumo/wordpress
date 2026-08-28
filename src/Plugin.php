<?php

namespace Datalumo\Wp;

use Datalumo\Wp\Admin\Ajax;
use Datalumo\Wp\Admin\SettingsPage;
use Datalumo\Wp\Connect\Grant;
use Datalumo\Wp\Embed\Embed;
use Datalumo\Wp\Search\ClickTracking;
use Datalumo\Wp\Search\Interceptor;
use Datalumo\Wp\Search\Summary;
use Datalumo\Wp\Support\UpdateChecker;
use Datalumo\Wp\Sync\BulkSync;
use Datalumo\Wp\Sync\ContentSync;

class Plugin
{
    private static ?Plugin $instance = null;

    private bool $booted = false;

    public static function instance(): Plugin
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        // Outside admin-only hooks so WP-CLI and management tools see updates too.
        UpdateChecker::register();

        add_action('plugins_loaded', function (): void {
            load_plugin_textdomain('datalumo', false, dirname(plugin_basename(DATALUMO_FILE)) . '/languages');

            // Deferred — WooCommerce may load after us.
            if (Integration\WooCommerce::isActive()) {
                (new Integration\WooCommerce())->register();
            }
        });

        if (is_admin()) {
            (new SettingsPage())->register();
            (new Ajax())->register();
            Grant::register();
        }

        (new ContentSync())->register();
        (new BulkSync())->register();
        (new Interceptor())->register();
        (new Summary())->register();
        (new ClickTracking())->register();
        (new Embed())->register();
        (new Integration\HostNavigation())->register();

        add_action('admin_notices', [$this, 'configurationNotice']);
    }

    public function configurationNotice(): void
    {
        if (! current_user_can('manage_options') || Support\Options::get('api_token')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen !== null && $screen->id === 'settings_page_datalumo') {
            return;
        }

        printf(
            '<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Datalumo is almost ready — connect your account to start syncing content.', 'datalumo'),
            esc_url(admin_url('options-general.php?page=datalumo')),
            esc_html__('Open settings', 'datalumo'),
        );
    }

    public static function deactivate(): void
    {
        if (! function_exists('as_unschedule_all_actions')) {
            return;
        }

        foreach (['datalumo_push_page', 'datalumo_push_batch', 'datalumo_delete_page'] as $hook) {
            as_unschedule_all_actions($hook, [], 'datalumo');
        }
    }
}
