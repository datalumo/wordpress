<?php

namespace Datalumo\Wp\Sync;

use Datalumo\Wp\Api\ApiException;
use Datalumo\Wp\Api\Client;
use Datalumo\Wp\Support\Options;
use WP_Post;

/**
 * Real-time sync: publishing a post pushes it to every sync config covering
 * its post type; unpublishing or deleting removes it. Work runs through
 * Action Scheduler so editors never wait on the API.
 */
class ContentSync
{
    public const PUSH_HOOK = 'datalumo_push_page';

    public const DELETE_HOOK = 'datalumo_delete_page';

    public const GROUP = 'datalumo';

    public function register(): void
    {
        add_action('transition_post_status', [$this, 'onStatusChange'], 10, 3);
        add_action('before_delete_post', [$this, 'onDelete'], 10, 2);
        add_action(self::PUSH_HOOK, [$this, 'processPush'], 10, 2);
        add_action(self::DELETE_HOOK, [$this, 'processDelete'], 10, 2);
    }

    public function onStatusChange(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if (wp_is_post_autosave($post) || wp_is_post_revision($post) || ! Options::isConnected()) {
            return;
        }

        foreach ($this->syncsForPostType($post->post_type) as $sync) {
            if ($newStatus === 'publish') {
                $this->schedule(self::PUSH_HOOK, [$post->ID, $sync['id']]);
            } elseif ($oldStatus === 'publish') {
                $this->schedule(self::DELETE_HOOK, [$post->ID, $sync['id']]);
            }
        }
    }

    public function onDelete(int $postId, ?WP_Post $post = null): void
    {
        $post ??= get_post($postId);

        if ($post === null || $post->post_status !== 'publish' || ! Options::isConnected()) {
            return;
        }

        foreach ($this->syncsForPostType($post->post_type) as $sync) {
            $this->schedule(self::DELETE_HOOK, [$postId, $sync['id']]);
        }
    }

    public function processPush(int $postId, string $syncId): void
    {
        $sync = $this->findSync($syncId);
        $post = get_post($postId);

        if ($sync === null || $post === null || $post->post_status !== 'publish') {
            return;
        }

        $payload = (new PagePreparer())->prepare($post, $sync['meta_mappings'] ?? []);

        if ($payload === null) {
            return;
        }

        try {
            (new Client())->pushPage($sync['source_id'], $payload);
        } catch (ApiException $e) {
            $this->retryLater(self::PUSH_HOOK, [$postId, $syncId], $e);
        }
    }

    public function processDelete(int $postId, string $syncId): void
    {
        $sync = $this->findSync($syncId);

        if ($sync === null) {
            return;
        }

        try {
            (new Client())->deletePage($sync['source_id'], (string) $postId);
        } catch (ApiException $e) {
            if ($e->status === 404) {
                return; // Never synced or already gone — done either way.
            }

            $this->retryLater(self::DELETE_HOOK, [$postId, $syncId], $e);
        }
    }

    /**
     * @return array<int, array{id: string, source_id: string, post_types: array, meta_mappings?: array}>
     */
    private function syncsForPostType(string $postType): array
    {
        $syncs = Options::get('syncs', []);

        return array_values(array_filter(
            is_array($syncs) ? $syncs : [],
            fn ($sync) => in_array($postType, $sync['post_types'] ?? [], true),
        ));
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

    private function schedule(string $hook, array $args): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, $args, self::GROUP);
        }
    }

    private function retryLater(string $hook, array $args, ApiException $e): void
    {
        // Auth failures and a full plan won't heal on retry — don't reschedule.
        if ($e->isAuthentication() || $e->isQuota() || ! function_exists('as_schedule_single_action')) {
            return;
        }

        as_schedule_single_action(time() + 5 * MINUTE_IN_SECONDS, $hook, $args, self::GROUP);
    }
}
