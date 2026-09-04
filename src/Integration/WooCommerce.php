<?php

namespace Datalumo\Wp\Integration;

use WP_Post;
use WP_Query;

/**
 * WooCommerce search behaviour on the interceptor's hooks: catalog sorts,
 * hidden-product exclusion, and the price-filter widget. Also registers
 * add-to-cart for Datalumo host actions. Registered only when WooCommerce
 * is active.
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
        add_filter('datalumo_page_payload', [$this, 'enrichProduct'], 10, 2);

        (new AddToCart())->register();
    }

    /**
     * Default product fields for search and chat: short description,
     * product categories/tags, visible attributes, and SKU (including
     * variation SKUs). A mapping that already set sku is left alone.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enrichProduct(array $payload, WP_Post $post): array
    {
        if (! in_array($post->post_type, ['product', 'product_variation'], true)
            || ! function_exists('wc_get_product')) {
            return $payload;
        }

        $product = wc_get_product($post->ID);

        if (! $product) {
            return $payload;
        }

        $payload = $this->attachShortDescription($payload, $product);
        $payload = $this->attachTaxonomies($payload, $post);
        $payload = $this->attachAttributes($payload, $product);

        $payload = $this->attachSku($payload, $product);

        return $this->attachThumbnail($payload, $product);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attachShortDescription(array $payload, object $product): array
    {
        if (! method_exists($product, 'get_short_description')) {
            return $payload;
        }

        $short = trim(wp_strip_all_tags((string) $product->get_short_description()));

        if ($short === '') {
            return $payload;
        }

        $html = '<p>'.esc_html($short).'</p>';
        $content = trim((string) ($payload['content'] ?? ''));

        if ($content === '') {
            $payload['content'] = $html;
        } elseif (! str_contains($content, $short)) {
            $payload['content'] = $html."\n\n".$content;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attachTaxonomies(array $payload, WP_Post $post): array
    {
        $categoryNames = $this->termField($post->ID, 'product_cat', 'name');
        $categorySlugs = $this->termField($post->ID, 'product_cat', 'slug');
        $tagNames = $this->termField($post->ID, 'product_tag', 'name');
        $tagSlugs = $this->termField($post->ID, 'product_tag', 'slug');
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $lines = [];

        if ($categorySlugs !== []) {
            $meta['categories'] = $categorySlugs;
            $lines[] = 'category: '.implode(', ', $categoryNames !== [] ? $categoryNames : $categorySlugs);
        }

        if ($tagSlugs !== []) {
            $meta['tags'] = $tagSlugs;
            $lines[] = 'tag: '.implode(', ', $tagNames !== [] ? $tagNames : $tagSlugs);
        }

        $payload['meta'] = $meta;

        return $this->appendSearchableLines($payload, $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attachAttributes(array $payload, object $product): array
    {
        if (! method_exists($product, 'get_attributes')) {
            return $payload;
        }

        $attributes = [];
        $lines = [];

        foreach ($product->get_attributes() as $attribute) {
            if (! is_object($attribute)) {
                continue;
            }

            if (method_exists($attribute, 'get_visible') && ! $attribute->get_visible()) {
                continue;
            }

            $label = $this->attributeLabel($attribute);
            $values = $this->attributeValues($product, $attribute);

            if ($label === '' || $values === []) {
                continue;
            }

            $attributes[$label] = $values;
            $lines[] = $label.': '.implode(', ', $values);
        }

        if ($attributes === []) {
            return $payload;
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        if (! isset($meta['attributes'])) {
            $meta['attributes'] = $attributes;
            $payload['meta'] = $meta;
        }

        return $this->appendSearchableLines($payload, $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attachSku(array $payload, object $product): array
    {
        $skus = $this->productSkus($product);

        if ($skus === []) {
            return $payload;
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        if (isset($meta['sku']) && $meta['sku'] !== '' && $meta['sku'] !== []) {
            return $payload;
        }

        $meta['sku'] = count($skus) === 1 ? $skus[0] : $skus;
        $payload['meta'] = $meta;

        return $this->appendSearchableLines($payload, ['sku: '.implode(', ', $skus)]);
    }

    /**
     * Product image when the post has no featured image of its own
     * (variations often inherit the parent's).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attachThumbnail(array $payload, object $product): array
    {
        if (! empty($payload['thumbnail']) || ! method_exists($product, 'get_image_id')) {
            return $payload;
        }

        $imageId = (int) $product->get_image_id();

        if ($imageId <= 0) {
            return $payload;
        }

        $url = wp_get_attachment_image_url($imageId, 'large');

        if (is_string($url) && $url !== '') {
            if (str_starts_with(strtolower($url), 'http://')) {
                $url = 'https://'.substr($url, 7);
            }

            $payload['thumbnail'] = $url;
        }

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    private function productSkus(object $product): array
    {
        $skus = [];
        $own = trim((string) $product->get_sku());

        if ($own !== '') {
            $skus[] = $own;
        }

        if (method_exists($product, 'is_type') && $product->is_type('variable') && method_exists($product, 'get_children')) {
            foreach ($product->get_children() as $childId) {
                $child = wc_get_product((int) $childId);

                if (! $child) {
                    continue;
                }

                $sku = trim((string) $child->get_sku());

                if ($sku !== '') {
                    $skus[] = $sku;
                }
            }
        }

        return array_values(array_unique($skus));
    }

    /**
     * @return array<int, string>
     */
    private function termField(int $postId, string $taxonomy, string $field): array
    {
        $terms = get_the_terms($postId, $taxonomy);

        if (! is_array($terms)) {
            return [];
        }

        $values = [];

        foreach ($terms as $term) {
            $value = trim((string) (is_object($term) ? ($term->{$field} ?? '') : ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function attributeLabel(object $attribute): string
    {
        $name = method_exists($attribute, 'get_name') ? (string) $attribute->get_name() : '';

        if (function_exists('wc_attribute_label') && $name !== '') {
            $label = trim((string) wc_attribute_label($name));

            if ($label !== '') {
                return $label;
            }
        }

        return $name;
    }

    /**
     * @return array<int, string>
     */
    private function attributeValues(object $product, object $attribute): array
    {
        if (method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy()
            && method_exists($attribute, 'get_name')
            && function_exists('wc_get_product_terms')) {
            $id = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
            $terms = wc_get_product_terms($id, $attribute->get_name(), ['fields' => 'names']);

            return is_array($terms)
                ? array_values(array_filter(array_map(strval(...), $terms)))
                : [];
        }

        $options = method_exists($attribute, 'get_options') ? $attribute->get_options() : [];

        return array_values(array_filter(array_map(strval(...), (array) $options)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $lines
     * @return array<string, mixed>
     */
    private function appendSearchableLines(array $payload, array $lines): array
    {
        if ($lines === []) {
            return $payload;
        }

        $payload['content'] = rtrim((string) ($payload['content'] ?? ''))
            ."\n\n<p>".esc_html(implode("\n", $lines)).'</p>';

        return $payload;
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
        return $this->applyPriceFilter($this->excludeHidden($args), $query);
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
    private function applyPriceFilter(array $args, WP_Query $query): array
    {
        $minRaw = sanitize_text_field((string) $query->get('min_price'));
        $maxRaw = sanitize_text_field((string) $query->get('max_price'));

        $min = $minRaw !== '' && is_numeric($minRaw) ? (float) $minRaw : null;
        $max = $maxRaw !== '' && is_numeric($maxRaw) ? (float) $maxRaw : null;

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
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- WooCommerce price filter on the result pool.
        $args['meta_query'] = $existing ? ['relation' => 'AND', $existing, $clause] : [$clause];

        return $args;
    }
}
