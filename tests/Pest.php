<?php

/**
 * Pest bootstrap. The plugin has no WordPress runtime under test, so every WP
 * and Action Scheduler function the code touches is stubbed with Brain Monkey.
 * Options are backed by an in-memory store keyed by option name, which lets the
 * real Options accessor and BulkSync's readState/writeState work unchanged.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;

if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 1;

        public string $post_content = 'Body content.';

        public string $post_type = 'post';

        public int $post_author = 1;

        public string $post_title = '';
    }
}

uses()
    ->beforeEach(function () {
        Monkey\setUp();

        $GLOBALS['__options'] = [];
        $GLOBALS['__scheduled'] = [];
        $GLOBALS['__unscheduled'] = 0;
        $GLOBALS['__has_pending'] = false;
        $GLOBALS['__uuid_seq'] = 0;

        Functions\when('__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);

        Functions\when('get_option')->alias(
            fn ($name, $default = false) => $GLOBALS['__options'][$name] ?? $default,
        );
        Functions\when('update_option')->alias(function ($name, $value) {
            $GLOBALS['__options'][$name] = $value;

            return true;
        });
        Functions\when('wp_cache_delete')->justReturn(true);

        // Distinct token per run() so restart/supersede scenarios are testable.
        Functions\when('wp_generate_uuid4')->alias(fn () => 'run-'.(++$GLOBALS['__uuid_seq']));

        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 3]);

        // Action Scheduler: record scheduling calls rather than perform them.
        Functions\when('as_enqueue_async_action')->alias(function ($hook, $args = [], $group = '') {
            $GLOBALS['__scheduled'][] = ['type' => 'async', 'hook' => $hook, 'args' => $args, 'group' => $group];

            return count($GLOBALS['__scheduled']);
        });
        Functions\when('as_schedule_single_action')->alias(function ($timestamp, $hook, $args = [], $group = '') {
            $GLOBALS['__scheduled'][] = ['type' => 'single', 'when' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group];

            return count($GLOBALS['__scheduled']);
        });
        Functions\when('as_unschedule_all_actions')->alias(function () {
            $GLOBALS['__unscheduled']++;

            return null;
        });
        Functions\when('as_has_scheduled_action')->alias(fn () => $GLOBALS['__has_pending']);
    })
    ->afterEach(function () {
        Monkey\tearDown();
    })
    ->in('Unit');

/**
 * Seed one sync config into the in-memory options store.
 *
 * @param  array<int, string>  $postTypes
 */
function seedSyncConfig(string $id = 'sync-1', array $postTypes = ['post'], string $sourceId = 'src-1'): void
{
    $GLOBALS['__options']['datalumo_settings'] = [
        'syncs' => [
            ['id' => $id, 'source_id' => $sourceId, 'post_types' => $postTypes],
        ],
    ];
}

/**
 * The persisted run state for a sync (as BulkSync would read it back).
 *
 * @return array<string, mixed>
 */
function syncState(string $id = 'sync-1'): array
{
    return $GLOBALS['__options']['datalumo_sync_state'][$id] ?? [];
}

/**
 * Overwrite a sync's run state directly, to set up mid-run scenarios.
 *
 * @param  array<string, mixed>  $state
 */
function putSyncState(array $state, string $id = 'sync-1'): void
{
    $GLOBALS['__options']['datalumo_sync_state'][$id] = $state;
}

/**
 * Every scheduling call recorded this test, in order.
 *
 * @return array<int, array<string, mixed>>
 */
function scheduledActions(): array
{
    return $GLOBALS['__scheduled'];
}
