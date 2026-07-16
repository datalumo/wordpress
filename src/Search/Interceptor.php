<?php

namespace Datalumo\Wp\Search;

use Datalumo\Wp\Api\ApiException;
use Datalumo\Wp\Api\Client;
use Datalumo\Wp\Support\Options;
use WP_Query;

/**
 * Replaces WordPress native search with Datalumo search. Hits come back with
 * the WP post id as external_id, so results rehydrate into real WP_Post
 * objects and the theme renders them exactly as before — just better ranked.
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

        $widgetKey = (string) Options::get('enhanced.widget_key');

        try {
            $response = (new Client())->search(
                $widgetKey,
                (string) $query->get('s'),
                min(self::MAX_RESULTS, (int) apply_filters('datalumo_search_max_results', self::MAX_RESULTS)),
            );
        } catch (ApiException $e) {
            error_log(sprintf('[Datalumo] search failed (%d): %s — falling back to native.', $e->status, $e->getMessage()));

            return null;
        }

        $externalIds = array_values(array_filter(array_map(
            fn ($hit) => isset($hit['external_id']) && ctype_digit((string) $hit['external_id'])
                ? (int) $hit['external_id']
                : null,
            $response['data'] ?? [],
        )));

        if ($externalIds === []) {
            $query->found_posts = 0;
            $query->max_num_pages = 0;

            return [];
        }

        $perPage = (int) $query->get('posts_per_page') ?: (int) get_option('posts_per_page', 10);
        $page = max(1, (int) $query->get('paged'));
        $pageIds = array_slice($externalIds, ($page - 1) * $perPage, $perPage);

        $found = get_posts([
            'post_type' => 'any',
            'post_status' => 'publish',
            'post__in' => $pageIds ?: [0],
            'orderby' => 'post__in',
            'numberposts' => -1,
            'suppress_filters' => false,
        ]);

        $query->found_posts = count($externalIds);
        $query->max_num_pages = (int) ceil(count($externalIds) / max(1, $perPage));

        return $found;
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
