<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('datalumo_settings');
delete_option('datalumo_sync_state');

if (function_exists('as_unschedule_all_actions')) {
    foreach (['datalumo_push_page', 'datalumo_push_batch', 'datalumo_delete_page'] as $hook) {
        as_unschedule_all_actions($hook, [], 'datalumo');
    }
}
