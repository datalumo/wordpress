<?php

namespace Datalumo\Wp\Search;

use Datalumo\Wp\Embed\Embed;
use Datalumo\Wp\Support\Options;

/**
 * AI summary above native search results. The browser talks to the API
 * directly through the Datalumo JS SDK (streaming), so no server proxy —
 * the site's domain in the widget's website list is the only requirement.
 */
class Summary
{
    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        if (! is_search() || ! Options::get('enhanced.summary_enabled')) {
            return;
        }

        $widgetKey = (string) Options::get('enhanced.widget_key');
        $query = trim((string) get_search_query());

        if ($widgetKey === '' || $query === '') {
            return;
        }

        // A single keyword is navigation, not a question — skip the whole
        // summary (no assets, no skeleton flash). The server enforces its
        // own gate; this just avoids mounting a box that would never fill.
        if (! $this->worthSummarising($query)) {
            return;
        }

        wp_enqueue_script(Embed::SCRIPT_HANDLE);

        wp_enqueue_style(
            'datalumo-summary',
            DATALUMO_URL . 'resources/css/summary.css',
            [],
            DATALUMO_VERSION,
        );

        wp_enqueue_script(
            'datalumo-summary',
            DATALUMO_URL . 'resources/js/summary.js',
            [Embed::SCRIPT_HANDLE],
            DATALUMO_VERSION,
            ['in_footer' => true],
        );

        wp_localize_script('datalumo-summary', 'datalumoSummary', [
            'widgetKey' => $widgetKey,
            'query' => $query,
            'filters' => $this->activeFilters(),
            'selector' => (string) Options::get('enhanced.summary_selector', ''),
            'position' => Options::get('enhanced.summary_position') === 'append' ? 'append' : 'prepend',
            'i18n' => [
                'heading' => __('AI Summary', 'datalumo'),
                'show_more' => __('Show more', 'datalumo'),
                'generating' => __('Summarising the best results…', 'datalumo'),
            ],
        ]);
    }

    /**
     * Filters the visitor applied to the search, so the summary covers the
     * same slice of content as the result list. Currently the post_type of
     * a scoped search (e.g. ?post_type=product); themes can extend via the
     * datalumo_summary_filters filter.
     *
     * @return array<string, string>
     */
    private function activeFilters(): array
    {
        $filters = [];

        $postType = get_query_var('post_type');

        if (is_array($postType)) {
            $postType = count($postType) === 1 ? reset($postType) : '';
        }

        if (is_string($postType) && $postType !== '' && $postType !== 'any') {
            $filters['post_type'] = $postType;
        }

        return (array) apply_filters('datalumo_summary_filters', $filters);
    }

    /**
     * Mirrors the search widget's client gate: at least three words, a
     * trailing question mark, or enough length for space-less scripts.
     */
    private function worthSummarising(string $query): bool
    {
        if (str_ends_with($query, '?') || str_ends_with($query, '？')) {
            return true;
        }

        $words = count(preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return $words >= 3 || mb_strlen($query) >= 12;
    }
}
