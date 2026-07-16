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
            'selector' => (string) Options::get('enhanced.summary_selector', ''),
            'position' => Options::get('enhanced.summary_position') === 'append' ? 'append' : 'prepend',
            'i18n' => [
                'heading' => __('Quick answer', 'datalumo'),
                'generating' => __('Summarising the best results…', 'datalumo'),
            ],
        ]);
    }
}
