=== Datalumo ===
Contributors: datalumo, jeffreyvr
Tags: search, ai, chatbot, rag
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.0.8
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your WordPress content to Datalumo and add AI-powered search and chat to your site.

== Description ==

Datalumo keeps a searchable, AI-ready copy of your WordPress content and gives your visitors better answers:

* **Content sync** — published posts and pages are pushed to your Datalumo knowledge base automatically; edits and deletions follow along.
* **Chat widget** — a floating assistant that answers from your content, with sources.
* **Search box** — drop-in search via the `[datalumo_search]` shortcode.
* **Enhanced search** — serve WordPress' native search results from Datalumo's ranking, with an optional streamed AI summary above the results. Falls back to native search automatically.
* **Visitor identity** — logged-in users can continue their chat conversations across visits, verified with a server-side signature.
* **Chat page actions**: the official plugin listens for confirmed chat events (`add_to_cart`, `view_cart`, `open_checkout`, `open_page`). Add those actions in Datalumo under Reply → What it can do.

This plugin is an interface to a [Datalumo](https://datalumo.app) instance. You need a Datalumo account (or a self-hosted instance), an API token, and — for chat or search widgets — a widget key. Nothing is sent until an administrator connects the site and turns a feature on.

= External services =

The plugin talks to the Datalumo instance you configure. The default host is `https://datalumo.app`; you can point it at your own instance instead.

* Service: [https://datalumo.app](https://datalumo.app)
* Documentation: [https://datalumo.app/docs](https://datalumo.app/docs)
* Terms of Service: [https://datalumo.app/terms](https://datalumo.app/terms)
* Privacy Policy: [https://datalumo.app/privacy](https://datalumo.app/privacy)

When a feature is enabled, the plugin may:

* Load the widget script from `{your Datalumo URL}/widget/v1/datalumo.js` (chat, search box, AI summary, and click tracking).
* Send published post content to the Datalumo API so it can be indexed (title, HTML, permalink, author display name, categories, tags, dates, and any custom field mappings you add).
* Send visitor search queries to Datalumo when enhanced search is on.
* Send result-click events (URL, rank, search session) when enhanced search is on.
* Send a signed visitor id (`wp-user-{id}` HMAC) when visitor identity is enabled on the Chatbot tab.

= Development =

Source code: [https://github.com/datalumo/wordpress](https://github.com/datalumo/wordpress)

Production dependencies are installed with `composer install --no-dev`.

== Installation ==

1. Install and activate the plugin.
2. In your Datalumo dashboard, create an API token (pages abilities) and a source of type API.
3. Go to Settings → Datalumo, paste your organisation ID and token, and connect.
4. Pick which post types sync to which source, and run the first full sync.
5. Add a widget key to enable the chat, search box, or enhanced search.

== Frequently Asked Questions ==

= Do I need a Datalumo account? =

Yes. The plugin does not search or chat on its own. It connects WordPress to a Datalumo instance — the hosted service at datalumo.app or one you run yourself.

= What data is sent to Datalumo? =

Only after you connect and enable a feature. Content sync sends the published posts you choose. Enhanced search sends visitor queries and optional result-click analytics. The chat and search widgets load Datalumo's script and talk to that instance. Visitor identity is off unless you turn it on.

= Can I use a self-hosted Datalumo instance? =

Yes. Set the Datalumo URL on the Connection tab before you connect.

= Can the chatbot add products to a WooCommerce cart? =

Yes, if WooCommerce is active. In Datalumo, add the WordPress Add to cart action (event add_to_cart, field product_id). The visitor confirms in chat. Variable products send colour, then that colour's sizes, as nested choice steps (up to 8 options per step); more than that opens the product page.

= What other chat actions does the plugin handle? =

View cart and Open checkout (WooCommerce), and Open this page (a post or page by id, slug, or same-site URL). Add them from Reply → Plugin actions → WordPress.


== Changelog ==

= 0.0.8 =
* Chat page actions: add_to_cart (WooCommerce, including variable products with nested colour/size choices), view_cart, open_checkout, and open_page.
* Product and other singular pages send page_id (and product_id on products) as chat context.

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
* Content sync, chat/search widgets, enhanced search, and visitor identity.
* GitHub auto-updates via Plugin Update Checker (removed ahead of the WordPress.org release).
* Dutch and Chinese translations.
