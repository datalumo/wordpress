<?php

namespace Datalumo\Wp\Sync;

use Datalumo\Wp\Api\ApiException;
use Datalumo\Wp\Api\Client;
use Datalumo\Wp\Support\Options;

/**
 * Full re-index of a sync config's published posts, batched through Action
 * Scheduler. Each batch schedules the next on completion, so the run can't
 * burst — pacing is delegated to the API's rate limiter, whose Retry-After
 * is honoured. A transiently failed batch reschedules itself without advancing.
 */
class BulkSync
{
    public const BATCH_HOOK = 'datalumo_push_batch';

    public const GROUP = 'datalumo';

    public function register(): void
    {
        add_action(self::BATCH_HOOK, [$this, 'processBatch'], 10, 2);
    }

    public function start(string $syncId): array
    {
        $sync = $this->findSync($syncId);

        if ($sync === null) {
            return ['error' => __('Unknown sync configuration.', 'datalumo')];
        }

        $total = 0;

        foreach ($sync['post_types'] as $postType) {
            $counts = wp_count_posts($postType);
            $total += (int) ($counts->publish ?? 0);
        }

        $this->writeState($syncId, [
            'total' => $total,
            'processed' => 0,
            'started' => time(),
            'finished' => null,
        ]);

        as_enqueue_async_action(self::BATCH_HOOK, [$syncId, 0], self::GROUP);

        return $this->status($syncId);
    }

    public function cancel(string $syncId): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::BATCH_HOOK, null, self::GROUP);
        }

        $state = $this->readState($syncId);
        $state['finished'] = time();
        $this->writeState($syncId, $state);
    }

    public function processBatch(string $syncId, int $offset): void
    {
        $sync = $this->findSync($syncId);

        if ($sync === null) {
            return;
        }

        $posts = get_posts([
            'post_type' => $sync['post_types'],
            'post_status' => 'publish',
            'numberposts' => $this->batchSize(),
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]);

        if ($posts === []) {
            // Ran past the last post — the chain ends here.
            $state = $this->readState($syncId);
            $state['finished'] = time();
            $this->writeState($syncId, $state);

            return;
        }

        $preparer = new PagePreparer();
        $pages = [];

        foreach ($posts as $post) {
            $payload = $preparer->prepare($post, $sync['meta_mappings'] ?? []);

            if ($payload !== null) {
                $pages[] = $payload;
            }
        }

        try {
            if ($pages !== []) {
                (new Client())->pushBatch($sync['source_id'], $pages);
            }
        } catch (ApiException $e) {
            error_log(sprintf('[Datalumo] bulk batch at %d failed (%d): %s', $offset, $e->status, $e->getMessage()));

            // A full plan stops the whole run — remaining batches hit the same
            // wall. Auth failures likewise never retry.
            if ($e->isQuota()) {
                $state = $this->readState($syncId);
                $state['error'] = $e->getMessage();
                $state['finished'] = time();
                $this->writeState($syncId, $state);
            } elseif (! $e->isAuthentication()) {
                // Rate limited → resume per Retry-After; else back off five minutes.
                $delay = $e->isRateLimited() ? ($e->retryAfter ?? 60) : 5 * MINUTE_IN_SECONDS;

                as_schedule_single_action(time() + $delay, self::BATCH_HOOK, [$syncId, $offset], self::GROUP);
            }

            return;
        }

        $state = $this->readState($syncId);
        $state['processed'] = min($state['total'] ?? 0, ($state['processed'] ?? 0) + count($posts));
        $this->writeState($syncId, $state);

        // Chain the next batch immediately — completion-based scheduling
        // can't burst, so no stagger needed.
        as_enqueue_async_action(self::BATCH_HOOK, [$syncId, $offset + $this->batchSize()], self::GROUP);
    }

    public function status(string $syncId): array
    {
        $state = $this->readState($syncId);

        return [
            'total' => (int) ($state['total'] ?? 0),
            'processed' => (int) ($state['processed'] ?? 0),
            'error' => (string) ($state['error'] ?? ''),
            'running' => ($state['started'] ?? null) !== null
                && ($state['finished'] ?? null) === null
                && $this->hasPendingBatches(),
        ];
    }

    private function hasPendingBatches(): bool
    {
        if (! function_exists('as_has_scheduled_action')) {
            return false;
        }

        return as_has_scheduled_action(self::BATCH_HOOK, null, self::GROUP);
    }

    private function findSync(string $syncId): ?array
    {
        foreach ((array) Options::get('syncs', []) as $sync) {
            if (($sync['id'] ?? null) === $syncId) {
                return $sync;
            }
        }

        return null;
    }

    private function readState(string $syncId): array
    {
        wp_cache_delete(Options::SYNC_STATE, 'options');

        $all = get_option(Options::SYNC_STATE, []);

        return is_array($all) ? ($all[$syncId] ?? []) : [];
    }

    private function writeState(string $syncId, array $state): void
    {
        wp_cache_delete(Options::SYNC_STATE, 'options');

        $all = get_option(Options::SYNC_STATE, []);
        $all = is_array($all) ? $all : [];
        $all[$syncId] = $state;

        update_option(Options::SYNC_STATE, $all, false);
    }

    private function batchSize(): int
    {
        // The API caps batch pushes at 50 pages per request.
        return min(50, max(1, (int) apply_filters('datalumo_bulk_sync_batch_size', 50)));
    }
}
