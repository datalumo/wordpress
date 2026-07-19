=== Datalumo ===
Contributors: jeffreyvanrossum
Tags: search, ai, chatbot, rag
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.0.6
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

== Installation ==

1. Install and activate the plugin.
2. In your Datalumo dashboard, create an API token (pages abilities) and a source of type API.
3. Go to Settings → Datalumo, paste your organisation ID and token, and connect.
4. Pick which post types sync to which source, and run the first full sync.
5. Add a widget key to enable the chat, search box, or enhanced search.

== Changelog ==

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
* GitHub auto-updates via Plugin Update Checker.
* Dutch and Chinese translations.
