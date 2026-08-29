<?php

namespace Datalumo\Wp\Sync;

use WP_Post;

/**
 * Turns a WordPress post into a Datalumo page payload. The payload upserts
 * on external_id (the WP post id), so re-syncing never duplicates.
 */
class PagePreparer
{
    /**
     * @param array<int, array{name: string, type: string, source: string, searchable?: bool}> $metaMappings
     */
    public function prepare(WP_Post $post, array $metaMappings = []): ?array
    {
        // Integrations can veto indexing (e.g. products hidden from search).
        if (! apply_filters('datalumo_is_indexable', true, $post)) {
            return null;
        }

        $content = $this->cleanContent($post);

        $meta = [
            'post_type' => $post->post_type,
            'categories' => $this->termSlugs($post->ID, 'category'),
            'tags' => $this->termSlugs($post->ID, 'post_tag'),
            'author' => get_the_author_meta('display_name', (int) $post->post_author),
            'published_at' => get_post_time('c', true, $post),
            'modified_at' => get_post_modified_time('c', true, $post),
        ];

        $searchableLines = [];

        foreach ($metaMappings as $mapping) {
            $value = $this->resolveMapping($post, $mapping);

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $meta[$mapping['name']] = $value;

            // Only page content is indexed, so searchable mapped fields ride
            // along in the body as labelled lines.
            if (! empty($mapping['searchable'])) {
                $searchableLines[] = sprintf(
                    '%s: %s',
                    $mapping['name'],
                    is_array($value) ? implode(', ', $value) : $value,
                );
            }
        }

        if ($searchableLines !== []) {
            $content .= "\n\n<p>" . esc_html(implode("\n", $searchableLines)) . '</p>';
        }

        $payload = [
            'external_id' => (string) $post->ID,
            'name' => html_entity_decode(get_the_title($post) ?: '(untitled)', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'content' => $content,
            'content_mime' => 'text/html',
            'source_url' => get_permalink($post),
            'meta' => array_filter($meta, fn ($value) => $value !== null && $value !== '' && $value !== []),
        ];

        $payload = apply_filters('datalumo_page_payload', $payload, $post);

        if (! is_array($payload)) {
            return null;
        }

        // WooCommerce may fill an empty body (short description, SKU, attributes).
        if (trim(wp_strip_all_tags((string) ($payload['content'] ?? ''))) === '') {
            return null;
        }

        return $payload;
    }

    /**
     * Rendered content without theme side effects: shortcodes stripped (or
     * rendered on opt-in) and blocks rendered, but never the full the_content
     * filter chain — page builders hook heavy markup there.
     */
    private function cleanContent(WP_Post $post): string
    {
        $this->ensureWooCommerceNotices();

        $raw = (string) $post->post_content;

        $raw = apply_filters('datalumo_render_shortcodes', false)
            ? do_shortcode($raw)
            : strip_shortcodes($raw);

        $raw = wpautop($raw);

        try {
            $html = wp_filter_content_tags(wptexturize(do_blocks($raw)));
        } catch (\Throwable) {
            // WooCommerce Blocks (cart, checkout) call frontend helpers that
            // are not loaded under WP-Cron. Keep the rest of the batch going.
            $html = wp_filter_content_tags(wptexturize($raw));
        }

        return trim(wp_strip_all_tags($html) === '' ? '' : $html);
    }

    /**
     * WooCommerce only loads notice helpers on the storefront. Action
     * Scheduler still runs do_blocks(), and Hydration::cache_store_notices()
     * fatals if wc_get_notices() is missing.
     */
    private function ensureWooCommerceNotices(): void
    {
        if (function_exists('wc_get_notices') || ! defined('WC_ABSPATH')) {
            return;
        }

        $file = WC_ABSPATH.'includes/wc-notice-functions.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }

    /**
     * @return array<int, string>
     */
    private function termSlugs(int $postId, string $taxonomy): array
    {
        $terms = get_the_terms($postId, $taxonomy);

        return is_array($terms) ? array_values(wp_list_pluck($terms, 'slug')) : [];
    }

    private function resolveMapping(WP_Post $post, array $mapping): string|array|null
    {
        return match ($mapping['type'] ?? '') {
            'post_meta' => (string) get_post_meta($post->ID, $mapping['source'], true) ?: null,
            'taxonomy' => $this->termSlugs($post->ID, $mapping['source']),
            default => null,
        };
    }
}
