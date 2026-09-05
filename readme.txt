=== Datalumo ===
Contributors: jeffreyvr
Tags: search, ai, chatbot, rag
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your WordPress content to Datalumo and add AI-powered search and chat to your site.

== Description ==

Datalumo keeps a searchable, AI-ready copy of your WordPress content and gives your visitors better answers:

* **Enhanced search** — serve WordPress' native search results from Datalumo's ranking, with an optional streamed AI summary above the results. Falls back to native search automatically.
* **Content sync** — published posts and pages are pushed to your Datalumo knowledge base automatically; edits and deletions follow along.
* **Chat widget** — a floating assistant that answers from your content, with sources.
* **Search box** — drop-in search via the `[datalumo_search]` shortcode.
* **Chat actions** — add to cart, view cart, checkout, and open a page. Add them in Datalumo under Reply → What it can do.

Connect with Datalumo from Settings, or use Manual setup to paste an API token.

= External services =

The plugin talks to the Datalumo service.

* Service: [https://datalumo.app](https://datalumo.app)
* Documentation: [https://datalumo.app/docs](https://datalumo.app/docs)
* Terms of Service: [https://datalumo.app/terms](https://datalumo.app/terms)
* Privacy Policy: [https://datalumo.app/privacy](https://datalumo.app/privacy)

When a feature is enabled, the plugin may:

* Load the widget script from `https://datalumo.app/widget/v1/datalumo.js` (chat, search box, AI summary, and click tracking).
* Send published content to the Datalumo API: title, HTML, permalink, featured image URL, author display name, categories, tags, dates, and any custom field mappings. WooCommerce products also send short description, product categories and tags, visible attributes, SKU, and the product image when there is no featured image.
* On Connect with Datalumo, send this site's host and URL so the grant can return to this admin.
* Send the current page id as chat context, plus product id and SKU on product pages.
* Send visitor search queries when enhanced search is on.
* Send result-click events (URL, rank, search session) when enhanced search is on.
* Send a signed visitor id (`wp-user-{id}` HMAC) if you enable identity on the Chatbot tab. Optional, for custom implementations. The plugin does not resume conversations.

= Development =

Source code: [https://github.com/datalumo/wordpress](https://github.com/datalumo/wordpress)

== Installation ==

1. Install and activate the plugin.
2. Go to Settings → Datalumo and press Connect with Datalumo.
3. Pick or create widgets, then finish the checklist (what to sync, chat, search).
4. Or choose Manual setup, paste an API token, and press Connect & test.

== Frequently Asked Questions ==

= Do I need a Datalumo account? =

Yes. The plugin does not search or chat on its own. It connects WordPress to Datalumo.

= What data is sent to Datalumo? =

Only after you connect and turn a feature on. The list is under External services above.

= Can the chatbot add products to a WooCommerce cart? =

Yes, if WooCommerce is active. In Datalumo, add the WordPress Add to cart action. The visitor confirms in chat. Variable products can ask for options first.

= What other chat actions does the plugin handle? =

View cart and Open checkout (WooCommerce), and Open this page (a post or page by id, slug, or same-site URL). Add them from Reply → Plugin actions → WordPress.

== Changelog ==

= 1.0.0 =
* First WordPress.org release.
* Translations are supplied by translate.wordpress.org; locale files are not bundled.
* Jetpack Autoloader 6 and Action Scheduler 4.1.

= 0.2.0 =
* Featured images sync with the page. WooCommerce products use the product image when the post has none. Removing the image and syncing again clears it in Datalumo.

= 0.1.0 =
* Connect with Datalumo from Settings. Sign in, pick a knowledge base and widgets, then finish a short checklist for sync, chat, and search. You can still paste an API token for manual setup.
* WooCommerce: the chatbot can add a product to the cart, open the cart, or open checkout after the visitor confirms in chat. Variable products can ask for options first.
* The chatbot can open a WordPress post or page.
* Synced products include short description, categories, tags, visible attributes, and SKU, including variations.

= 0.0.7 =
* Full sync: when the push completes, the plugin now tells Datalumo to start indexing right away instead of waiting for its next background sweep. Best-effort — older Datalumo versions without the endpoint are unaffected.

= 0.0.6 =
* Full sync: a batch the server keeps rejecting no longer retries forever — the run stops and shows the reason (e.g. a server error on a specific post) instead of looping silently.
* Full sync: rejected payloads (4xx) and auth failures stop the run immediately; only genuinely transient errors (rate limit, 5xx, network) retry, and only up to a cap.

= 0.0.5 =
* Full sync: stopping and restarting a run now starts cleanly from the beginning instead of getting stuck at the point it was stopped.
* Full sync: the "Sync now" button is disabled with a spinner while a run is in progress, so a sync can't be started twice.
* Post titles with HTML entities (e.g. a curly apostrophe) are decoded before syncing.

= 0.0.4 =
* Enhanced search: results now render in Datalumo's relevance order — other plugins (e.g. WooCommerce catalog sorting) can no longer reorder them and bury top matches.
* Avoid a duplicate product-visibility filter on WooCommerce searches.

= 0.0.3 =
* Enhanced search: result clicks are matched by rank, so they're tracked even when the theme renders links outside the main results container.
* Result clicks tie to the originating search session for accurate analytics.

= 0.0.2 =
* Connect & test now uses the custom Datalumo base URL from the settings form.
* Persist the base URL on a successful connection; add a Save URL control on the connection tab.

= 0.0.1 =
* Initial public release.
* Content sync, chat/search widgets, and enhanced search.
* Dutch and Chinese translations.
