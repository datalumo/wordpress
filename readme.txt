=== Datalumo ===
Contributors: jeffreyvanrossum
Tags: search, ai, chatbot, rag
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
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

= 1.0.0 =
* Complete rewrite against the Datalumo v1 API (sources/pages/widgets).
* New: signed visitor identity for logged-in users.
* New: streamed AI search summaries rendered directly from the browser.
