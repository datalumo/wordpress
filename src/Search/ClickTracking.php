<?php

namespace Datalumo\Wp\Search;

use Datalumo\Wp\Embed\Embed;
use Datalumo\Wp\Support\Options;

/**
 * Click attribution on the search results page. One delegated listener
 * covers links inside the AI summary box and the intercepted native
 * results; events go straight to the Datalumo API through the SDK,
 * tagged with the search session the interceptor's API call returned so
 * each click ties back to the query that produced it.
 *
 * Result clicks are matched against the interceptor's permalink→rank map
 * (not a guessed results container), so theme markup that puts links
 * outside #primary / main still reports.
 */
class ClickTracking
{
    public function register(): void
    {
        // After Summary/Embed (default 10), so interception state is settled.
        add_action('wp_enqueue_scripts', [$this, 'enqueue'], 20);
    }

    public function enqueue(): void
    {
        if (! is_search()) {
            return;
        }

        $widgetKey = (string) Options::get('enhanced.widget_key');

        if ($widgetKey === '') {
            return;
        }

        $search = Interceptor::lastSearch();
        $summaryActive = wp_script_is('datalumo-summary', 'enqueued');

        // Nothing to attribute: native results without a Datalumo search,
        // and no summary box on the page either.
        if ($search === null && ! $summaryActive) {
            return;
        }

        wp_enqueue_script(Embed::SCRIPT_HANDLE);

        wp_enqueue_script(
            'datalumo-clicks',
            DATALUMO_URL . 'resources/js/click-tracking.js',
            [Embed::SCRIPT_HANDLE],
            DATALUMO_VERSION,
            ['in_footer' => true],
        );

        wp_localize_script('datalumo-clicks', 'datalumoClicks', [
            'widgetKey' => $widgetKey,
            'sessionId' => $search['session_id'] ?? null,
            'ranks' => $search['ranks'] ?? new \stdClass(),
            'selector' => (string) apply_filters('datalumo_results_selector', Options::get('enhanced.summary_selector', '')),
        ]);
    }
}
