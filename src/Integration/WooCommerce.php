<?php

namespace Datalumo\Wp\Integration;

use WP_Query;

/**
 * WooCommerce search behaviour on the interceptor's hooks: catalog sorts,
 * hidden-product exclusion, and the price-filter widget. Registered only
 * when WooCommerce is active.
 */
class WooCommerce
{
    public static function isActive(): bool
    {
        return class_exists('WooCommerce');
    }

    public function register(): void
    {
        add_filter('datalumo_sort_map', [$this, 'sortMap']);
        add_filter('datalumo_resolve_args', [$this, 'resolveArgs'], 10, 2);
    }

    /**
     * WooCommerce catalog orderings, resolved against product meta.
     *
     * @param  array<string, array<string, mixed>>  $map
     * @return array<string, array<string, mixed>>
     */
    public function sortMap(array $map): array
    {
        return $map + [
            'price' => ['meta' => '_price', 'order' => 'asc', 'numeric' => true],
            'price-desc' => ['meta' => '_price', 'order' => 'desc', 'numeric' => true],
            'popularity' => ['meta' => 'total_sales', 'order' => 'desc', 'numeric' => true],
            'rating' => ['meta' => '_wc_average_rating', 'order' => 'desc', 'numeric' => true],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function resolveArgs(array $args, WP_Query $query): array
    {
        return $this->applyPriceFilter($this->excludeHidden($args));
    }

    /**
     * Exclude products marked "hidden from search" in Woo catalog visibility.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function excludeHidden(array $args): array
    {
        if (! function_exists('wc_get_product_visibility_term_ids')) {
            return $args;
        }

        // A Woo product search already carries this clause (copied from the
        // main query), so skip it rather than add a duplicate.
        foreach ((array) ($args['tax_query'] ?? []) as $clause) {
            if (($clause['taxonomy'] ?? null) === 'product_visibility') {
                return $args;
            }
        }

        $excluded = wc_get_product_visibility_term_ids()['exclude-from-search'] ?? 0;

        if ($excluded) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_visibility',
                'field' => 'term_taxonomy_id',
                'terms' => [$excluded],
                'operator' => 'NOT IN',
            ];
        }

        return $args;
    }

    /**
     * Honour the min_price/max_price the Woo price-filter widget puts on the
     * search URL against the result pool.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function applyPriceFilter(array $args): array
    {
        $min = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (float) $_GET['min_price'] : null;
        $max = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (float) $_GET['max_price'] : null;

        if ($min === null && $max === null) {
            return $args;
        }

        if ($min !== null && $max !== null) {
            $clause = ['key' => '_price', 'type' => 'NUMERIC', 'compare' => 'BETWEEN', 'value' => [$min, $max]];
        } elseif ($min !== null) {
            $clause = ['key' => '_price', 'type' => 'NUMERIC', 'compare' => '>=', 'value' => $min];
        } else {
            $clause = ['key' => '_price', 'type' => 'NUMERIC', 'compare' => '<=', 'value' => $max];
        }

        $existing = $args['meta_query'] ?? [];
        $args['meta_query'] = $existing ? ['relation' => 'AND', $existing, $clause] : [$clause];

        return $args;
    }
}
