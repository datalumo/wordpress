<?php

namespace Datalumo\Wp\Search;

use Datalumo\Wp\Api\ApiException;
use Datalumo\Wp\Api\Client;
use Datalumo\Wp\Support\Options;
use WP_Query;

/**
 * Replaces WordPress native search with Datalumo. Hits carry the WP post id
 * as external_id and rehydrate into a pool of WP_Post objects — meta/tax
 * constraints enforced WP-side, integration hooks applied, orderby honoured
 * — before local pagination. Any API error falls back to native search.
 */
class Interceptor
{
    /**
     * The API returns relevance-ranked hits without offset pagination, so we
     * fetch one ranked set and page through it locally.
     */
    private const MAX_RESULTS = 50;

    /**
     * This request's interception, for click attribution: the search session
     * id and each pooled permalink's displayed rank. Null when nothing was
     * intercepted.
     *
     * @var array{session_id: ?string, ranks: array<string, int>}|null
     */
    private static ?array $lastSearch = null;

    public function register(): void
    {
        add_filter('posts_pre_query', [$this, 'intercept'], 10, 2);
    }

    /**
     * @return array{session_id: ?string, ranks: array<string, int>}|null
     */
    public static function lastSearch(): ?array
    {
        return self::$lastSearch;
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
        } catch (ApiException) {
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

        // Zero-based to match the widget SDK wire convention (dashboard shows
        // #rank+1). Keyed by absolute permalink; the browser matcher normalises
        // slashes/encoding before comparing.
        $ranks = [];

        foreach ($pool as $index => $post) {
            $permalink = get_permalink($post);

            if (is_string($permalink) && $permalink !== '') {
                $ranks[$permalink] = $index;
            }
        }

        self::$lastSearch = [
            'session_id' => isset($response['session_id']) ? (string) $response['session_id'] : null,
            'ranks' => $ranks,
        ];

        $perPage = (int) $query->get('posts_per_page') ?: (int) get_option('posts_per_page', 10);
        $page = max(1, (int) $query->get('paged'));

        $query->found_posts = count($pool);
        $query->max_num_pages = (int) ceil(count($pool) / max(1, $perPage));

        return array_slice($pool, ($page - 1) * $perPage, $perPage);
    }

    /**
     * Resolve hit ids to published posts in ranked order, with the query's own
     * meta/tax constraints enforced WP-side. Integrations adjust args via
     * datalumo_resolve_args (WooCommerce hidden products, price filter).
     *
     * @param  array<int, int>  $ids
     * @return array<int, \WP_Post>
     */
    private function resolvePool(array $ids, WP_Query $query): array
    {
        if ($ids === []) {
            return [];
        }

        // A scoped search (?post_type=product) must scope the pool too, or
        // off-type hits inflate found_posts for a template that won't render them.
        $postType = $query->get('post_type');

        $args = [
            'post__in' => $ids,
            'orderby' => 'post__in',
            'post_type' => $postType ?: 'any',
            'post_status' => 'publish',
            'posts_per_page' => count($ids),
            'ignore_sticky_posts' => true,
        ];

        $metaQuery = $query->get('meta_query');

        if (is_array($metaQuery) && $metaQuery !== []) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- copied from the main search query.
            $args['meta_query'] = $metaQuery;
        }

        if (! empty($query->tax_query->queries)) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- copied from the main search query.
            $args['tax_query'] = $query->tax_query->queries;
        }

        $args = apply_filters('datalumo_resolve_args', $args, $query);

        $posts = get_posts($args);

        // orderby => post__in isn't reliable: other plugins (Woo catalog
        // ordering) hook pre_get_posts and reset the order, so enforce
        // the ranking in PHP.
        $rank = array_flip(array_values($ids));

        usort($posts, fn ($a, $b) => ($rank[$a->ID] ?? PHP_INT_MAX) <=> ($rank[$b->ID] ?? PHP_INT_MAX));

        return $posts;
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
        // Prefer the original request value. Woo's pre_get_posts rewrites
        // query_vars['orderby'] to meta_value_num, which is not in our map.
        $raw = $query->query['orderby'] ?? $query->get('orderby');
        $orderby = is_string($raw) ? sanitize_key($raw) : '';

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

        // Only intercept a type-scoped query if every requested type is
        // covered by the enhanced-search setting.
        $scope = (array) Options::get('enhanced.post_types', []);
        $requested = array_filter((array) $query->get('post_type'));

        if ($scope !== [] && $requested !== [] && array_diff($requested, $scope) !== []) {
            return false;
        }

        return (bool) apply_filters('datalumo_should_intercept_search', true, $query);
    }
}
