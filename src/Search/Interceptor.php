<?php

namespace Datalumo\Wp\Search;

use Datalumo\Wp\Api\ApiException;
use Datalumo\Wp\Api\Client;
use Datalumo\Wp\Support\Options;
use WP_Query;

/**
 * Replaces WordPress native search with Datalumo search. Hits come back
 * with the WP post id as external_id and rehydrate into a pool of real
 * WP_Post objects — with the query's own meta/tax constraints enforced
 * WP-side, integration hooks applied (WooCommerce hidden products, price
 * filter), and the requested orderby honoured — before local pagination.
 * Any API problem falls back to native search; visitors never see an error.
 */
class Interceptor
{
    /**
     * The API returns relevance-ranked hits without offset pagination, so we
     * fetch one ranked set and page through it locally.
     */
    private const MAX_RESULTS = 50;

    public function register(): void
    {
        add_filter('posts_pre_query', [$this, 'intercept'], 10, 2);
    }

    /**
     * @return array<int, \WP_Post>|null null lets native search run
     */
    public function intercept(?array $posts, WP_Query $query): ?array
    {
        if (! $this->shouldIntercept($query)) {
            return $posts;
        }

        try {
            $response = (new Client())->search(
                (string) Options::get('enhanced.widget_key'),
                (string) $query->get('s'),
                min(self::MAX_RESULTS, (int) apply_filters('datalumo_search_max_results', self::MAX_RESULTS)),
            );
        } catch (ApiException $e) {
            error_log(sprintf('[Datalumo] search failed (%d): %s — falling back to native.', $e->status, $e->getMessage()));

            return null;
        }

        $ids = array_values(array_filter(array_map(
            fn ($hit) => isset($hit['external_id']) && ctype_digit((string) $hit['external_id'])
                ? (int) $hit['external_id']
                : null,
            $response['data'] ?? [],
        )));

        $pool = $this->resolvePool($ids, $query);
        $pool = $this->reorder($pool, $query);

        $perPage = (int) $query->get('posts_per_page') ?: (int) get_option('posts_per_page', 10);
        $page = max(1, (int) $query->get('paged'));

        $query->found_posts = count($pool);
        $query->max_num_pages = (int) ceil(count($pool) / max(1, $perPage));

        return array_slice($pool, ($page - 1) * $perPage, $perPage);
    }

    /**
     * Resolve hit ids to published posts, in ranked order, with the query's
     * own meta/tax constraints enforced WP-side — Datalumo ranks, WordPress
     * filters. Integrations adjust the args via datalumo_resolve_args
     * (WooCommerce: hidden products, the price-filter widget).
     *
     * @param  array<int, int>  $ids
     * @return array<int, \WP_Post>
     */
    private function resolvePool(array $ids, WP_Query $query): array
    {
        if ($ids === []) {
            return [];
        }

        $args = [
            'post__in' => $ids,
            'orderby' => 'post__in',
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => count($ids),
            'ignore_sticky_posts' => true,
            'suppress_filters' => true,
        ];

        $metaQuery = $query->get('meta_query');

        if (is_array($metaQuery) && $metaQuery !== []) {
            $args['meta_query'] = $metaQuery;
        }

        if (! empty($query->tax_query->queries)) {
            $args['tax_query'] = $query->tax_query->queries;
        }

        $args = apply_filters('datalumo_resolve_args', $args, $query);

        return get_posts($args);
    }

    /**
     * Honour an explicit orderby (Woo catalog sorts included, via the
     * datalumo_sort_map filter); relevance keeps Datalumo's ranking.
     *
     * @param  array<int, \WP_Post>  $pool
     * @return array<int, \WP_Post>
     */
    private function reorder(array $pool, WP_Query $query): array
    {
        $orderby = isset($_GET['orderby']) ? sanitize_key((string) wp_unslash($_GET['orderby'])) : '';

        if ($orderby === '' || $orderby === 'relevance' || $pool === []) {
            return $pool;
        }

        $map = apply_filters('datalumo_sort_map', [
            'date' => ['field' => 'post_date', 'order' => 'desc'],
            'title' => ['field' => 'post_title', 'order' => 'asc'],
        ]);

        $spec = $map[$orderby] ?? null;

        if ($spec === null) {
            return $pool;
        }

        usort($pool, function ($a, $b) use ($spec) {
            if (isset($spec['meta'])) {
                $va = get_post_meta($a->ID, $spec['meta'], true);
                $vb = get_post_meta($b->ID, $spec['meta'], true);
                $cmp = ! empty($spec['numeric'])
                    ? (float) $va <=> (float) $vb
                    : strcasecmp((string) $va, (string) $vb);
            } else {
                $field = (string) $spec['field'];
                $cmp = strcasecmp((string) $a->{$field}, (string) $b->{$field});
            }

            return ($spec['order'] ?? 'asc') === 'desc' ? -$cmp : $cmp;
        });

        return $pool;
    }

    private function shouldIntercept(WP_Query $query): bool
    {
        if (is_admin() || ! $query->is_main_query() || ! $query->is_search()) {
            return false;
        }

        if (trim((string) $query->get('s')) === '') {
            return false;
        }

        if (! Options::get('enhanced.enabled') || (string) Options::get('enhanced.widget_key') === '') {
            return false;
        }

        // Scope guard: when the query targets specific post types, only
        // intercept if they're all covered by the enhanced-search setting.
        $scope = (array) Options::get('enhanced.post_types', []);
        $requested = array_filter((array) $query->get('post_type'));

        if ($scope !== [] && $requested !== [] && array_diff($requested, $scope) !== []) {
            return false;
        }

        return (bool) apply_filters('datalumo_should_intercept_search', true, $query);
    }
}
