<?php

namespace Datalumo\Wp;

use Datalumo\Wp\Admin\Ajax;
use Datalumo\Wp\Admin\SettingsPage;
use Datalumo\Wp\Connect\Grant;
use Datalumo\Wp\Embed\Embed;
use Datalumo\Wp\Search\ClickTracking;
use Datalumo\Wp\Search\Interceptor;
use Datalumo\Wp\Search\Summary;
use Datalumo\Wp\Support\Assets;
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

        add_action('plugins_loaded', function (): void {
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

        add_action('admin_enqueue_scripts', [$this, 'enqueueSetupNotice']);
        add_action('admin_notices', [$this, 'configurationNotice']);
    }

    public function enqueueSetupNotice(string $hook): void
    {
        if ($hook !== 'index.php' || ! $this->shouldShowSetupNotice()) {
            return;
        }

        wp_enqueue_script(
            'datalumo-setup-notice',
            DATALUMO_URL . 'resources/js/setup-notice.js',
            [],
            Assets::version(),
            ['in_footer' => true],
        );

        wp_localize_script('datalumo-setup-notice', 'datalumoSetupNotice', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Ajax::NONCE),
        ]);
    }

    public function configurationNotice(): void
    {
        if (! $this->shouldShowSetupNotice()) {
            return;
        }

        printf(
            '<div class="notice notice-info is-dismissible datalumo-setup-notice"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Datalumo is almost ready — connect your account to start syncing content.', 'datalumo'),
            esc_url(admin_url('options-general.php?page=datalumo')),
            esc_html__('Open settings', 'datalumo'),
        );
    }

    private function shouldShowSetupNotice(): bool
    {
        if (! is_admin() || ! current_user_can('manage_options') || Support\Options::get('api_token')) {
            return false;
        }

        if (get_user_meta(get_current_user_id(), 'datalumo_hide_setup_notice', true)) {
            return false;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        return $screen !== null && $screen->id === 'dashboard';
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
