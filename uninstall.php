<?php

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('datalumo_settings');
delete_option('datalumo_sync_state');
delete_metadata('user', 0, 'datalumo_hide_setup_notice', '', true);

if (function_exists('as_unschedule_all_actions')) {
    foreach (['datalumo_push_page', 'datalumo_push_batch', 'datalumo_delete_page'] as $datalumo_hook) {
        as_unschedule_all_actions($datalumo_hook, [], 'datalumo');
    }
}
