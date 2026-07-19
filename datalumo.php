<?php

/**
 * Datalumo
 *
 * @wordpress-plugin
 * Plugin Name:       Datalumo
 * Plugin URI:        https://github.com/datalumo/wordpress
 * Description:       Sync your WordPress content to Datalumo and add AI-powered search and chat to your site.
 * Version:           0.0.7
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Jeffrey van Rossum
 * Author URI:        https://vanrossum.dev
 * Text Domain:       datalumo
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

use Datalumo\Wp\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('DATALUMO_VERSION')) {
    define('DATALUMO_VERSION', '0.0.7');
    define('DATALUMO_FILE', __FILE__);
    define('DATALUMO_DIR', __DIR__);
    define('DATALUMO_URL', plugin_dir_url(__FILE__));
}

if (is_readable(__DIR__ . '/vendor/autoload_packages.php')) {
    require_once __DIR__ . '/vendor/autoload_packages.php';
}

if (is_readable(__DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php')) {
    require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

if (! function_exists('datalumo_plugin')) {
    function datalumo_plugin(): Plugin
    {
        return Plugin::instance();
    }
}

if (class_exists(Plugin::class)) {
    datalumo_plugin()->boot();

    register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
}
