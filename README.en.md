# Nova Search for Shopware 6

🇩🇪 [Deutsche Version](README.md)

Fast **search window** for Shopware 6.7: clicking the search field opens a large window with product images, filters and sorting – no external search service, no running costs.

## Features

- Start view: popular searches and highlight products (slider)
- While typing: product cards with images, brand filter with counts, price slider, sorting, matching categories, infinite scroll
- Mobile: full-screen window, two columns, collapsible filters
- Highlighted search words
- Keyboard: ↓/↑ through results, Esc closes, Enter opens the full result page
- Mobile: full-width panel, two product columns
- Uses Shopware's own product search (MySQL or OpenSearch), computes only the brand and price facets, HTTP-cacheable, ~15 KB JavaScript

## Learning & Claude

- **Learns from behaviour:** searches, clicks, add-to-cart and purchases after a search are counted without IPs, customer or session IDs in the database (search terms are free text, though – a name typed into the search box is stored like any term; mention this in your privacy policy). Products that perform well for a term move up for exactly that term (relevance sorting only), but only after an hourly background check confirms the search finds them for that term. Popular searches are determined automatically (min. count, at least three different days, hits checked server-side, blocklist). Since 1.12.0 signals require an existing shop session, are limited per client IP (only a hash, at most 24 h in the object cache) and per sales channel, and clicks/corrections count once per IP and day.
- **Learns spelling corrections (1.11.0):** a search without a click followed by a similarly spelled search with a click (e.g. "vergser" → "vergaser") is counted; after 2 observations (configurable) on at least two different days the misspelling is searched as the correction automatically – only if the misspelling itself has no results and the correction has (checked hourly). Stored rewrites can be disabled under Analytics → Zero results. Only small spelling differences with identical numbers count. Typing steps ("ra", "rah", "rahmen") are no longer counted as separate searches.
- **Recently viewed** list in the start view (browser localStorage, consent "comfort features" by default).
- **Claude (`claude-haiku-5-5`, off by default):** search terms without results are sent once, in batches, to Claude via the official Anthropic PHP SDK. Every suggested alternative is verified against the real product search; only working rewrites are stored permanently and applied automatically ("Results for …"). Only search terms plus shop/category/brand names are sent – no customer data. Estimated cost: ~2–3 cents per 1,000 new zero-result terms.
- Requires Shopware's scheduled tasks and message queue (or `bin/console nova-search:rewrite` via cron).
- Interface for other plugins: `document` events `nova:search-open`, `nova:search-close`, `nova:search` (`{ term, total }`), `nova:products-rendered` (`{ container }`); cards carry `data-product-information`, lists `data-nova-list-id`/`data-nova-list-name`.

## Installation

```bash
cd <shopware-root>/custom/plugins
git clone https://github.com/DrdotHouse2106/SW6-Nova-Suche.git NovaSearch
cd <shopware-root>
bin/console plugin:refresh
bin/console plugin:install --activate NovaSearch
bin/console theme:compile
bin/console cache:clear
```

Configure it under *Extensions → My extensions → Nova Search → Configure*.


## Store API for apps

All routes are `POST` with a JSON body and the usual `sw-access-key` / `sw-context-token` headers.

- `POST /store-api/nova-search/search` – `{ search, p?, limit? (24, max 48), order?, properties?, manufacturer? }` → `{ ids, total, page, limit, sorting, availableSortings: [{ key, label }], rewrite: null | { original, term } }`. Uses the learned ranking and stored Claude rewrites. Does **not** count the search (the app reports each final search once via `track`); zero-result terms are only queued for rewriting. Missing/too short `search` → `400`.
- Admin API routes require `system_config:read` (view) or `system_config:update` (change) since 1.12.0.
- `POST /store-api/nova-search/popular` – `{ limit? (10, max 20) }` → `{ terms: [...] }`.
- `POST /store-api/nova-search/track` – `{ events: [{ type: "search"|"click"|"cart"|"purchase", term, productId?, total?, quantity? }] }` → `204`. Max. 20 events per call and 300 per context token and hour. `purchase` only counts if the logged-in customer actually ordered the product within 48 hours, once per token and product. Only term, product ID and counters are stored.

Shopware's own `/store-api/search` also uses the learned ranking and rewrites (no counting).

With the "Dynamic monthly delivery dates" plugin and its "show in listings" option enabled, search cards show the next shipping date.

## Claude cost limit

Monthly cost limit for the Claude stage (default **5 USD**, `0` = Claude off). It applies to **all sales channels together** – set it under "All sales channels". Token usage reported by the API is converted with the model prices (USD per 1M tokens: haiku-5-5 0.10/0.50, above 100K prompt tokens 0.50/2.50; sonnet-5-5 2/10; opus-5-5 4/20; fable-5-1 10/50; unknown models at the highest price) and stored per calendar month (UTC). Before every call the maximum cost of the call must fit into the remaining budget; once the limit is reached no further requests are made and open terms wait for the next month (also for the immediate evaluation on the search page). A warning threshold (default 80 %) and the limit are logged once per month. Show usage: `bin/console nova-search:claude-usage`; export: `nova-search:export --type=claude-usage`.

Recommendation: create a dedicated **workspace with a spend limit** in the Anthropic Console and create the API key there (limits apply per workspace). The plugin counter is an estimate – the Console bill is authoritative.

## Exporting search data

`bin/console nova-search:export` writes `suchbegriffe.csv` (terms with searches, hits, clicks, carts, orders, CTR), `suchbegriffe-produkte.csv` (term × product) and `claude-umschreibungen.csv` (Claude rewrites). Options: `--type=terms|products|rewrites`, `--sales-channel=<id>`, `--days=N`, `--min-searches=N`, `--output=<dir>`, `--delimiter=;`. UTF-8 with BOM, formula-like values are escaped, no customer data.

## Compatibility

Shopware 6.7.x (checked against 6.7.15). License: MIT.

## Troubleshooting

Known pitfalls from real installations. **Please add new ones** (or open an issue).

- **Nothing changes after install:** the search window is off by default – enable it per sales channel.
- **No styling:** run `theme:compile` and `cache:clear`.
- **Two search overlays:** disable other search extensions in that sales channel.
- **Composer / "Class Anthropic\Client not found":** run `composer require anthropic-ai/sdk` in the shop root; everything except the Claude stage works without it.
- **Claude stops mid-month:** monthly cost limit reached – check `bin/console nova-search:claude-usage`, raise the limit under "All sales channels".
- **Claude does nothing:** API key, Claude switch, scheduled tasks and message queue must run; test with `bin/console nova-search:rewrite`.
- **No typo tolerance:** use OpenSearch, or rely on the Claude stage.

## Search rules and exclusions

*Extensions → Nova Search → tabs **Synonyms** / **Redirects**:* each rule is a list entry with search, active switch, edit and delete, optionally limited to one sales channel. **Synonyms** are groups of equivalent terms (e.g. `brake pads, brake shoes`), **redirects** send an exact term to a shop path or `https://` URL. CSV import/export (exports of other search services are detected), and "Synonym"/"Redirect" buttons in the analytics "No results" table. Entries from the former config text fields (≤ 1.6.0) are migrated automatically. Multi-word searches keep Shopware's AND logic. *Excluded products:* closeout products without stock, products (incl. variants), categories, tags or a yes/no custom field. Applies to the search layer, search page and Store API (`redirect` field).

## Importing data from a previous search service (1.8.0)

*Extensions → Nova Search → Analytics → Import data* (or `bin/console nova-search:import-stats --sales-channel=<ID> files…`) reads CSV exports with search terms (`search_term`, `total_searches`, `total_clicks`) and clicked products (`clicked_product_link`, `search_query`, `total_searches_clicks`; products are matched by the product number at the end of the link). Values are added to existing counters, each file is imported only once per sales channel.

## When this shop has nothing (1.9.0)

- **Sister shops** (config, per sales channel): with 0 results the search window also searches up to 3 other sales channels of the same installation and shows their products linking to that shop's domain.
- **Hints** (*Extensions → Nova Search → Hints*): if one of the words appears in the search term, a hint with optional link is shown (`{term}` in the link is replaced by the search term).
- **"Request this part"** (1.10.0): with 0 results a small form opens right in the search window – email or phone is enough, the request is mailed to the shop, the customer stays in the search. Optional link to the contact page with pre-filled subject and search term.

## Word parts (1.13.0)

Shopware only matches a search word at the beginning or end of a word, so "vergaser" finds "Doppelvergaser" but not "Doppelvergasers" (genitive). With *Configure → Word parts* (default on), words of 5 or more letters also match inside longer words (ranked behind exact hits), and a compound search term without results is split into two parts that must both match ("vergasersatz" → "vergaser" + words ending in "satz"). The first part must be a word from the shop and the second part must end the word, so random splits like "vergas" + "ersatz" do not happen. Different words for the same thing ("Überholsatz" vs. "Überholung") still need synonyms.

## Number search (1.3.0)

Terms that look like a number (at least 3 digits, no other words) are looked up in a normalized **number index** first: product number, manufacturer number, EAN and optionally custom fields and properties (e.g. OE numbers). Spaces, dots, dashes and slashes are ignored (`25043 25031` = `2504325031`). If nothing matches, the regular search runs. A checkbox "Exact part number only" next to the search field restricts results to products with exactly this number (Store API: `exactNumber: true`). Rebuild: `bin/console nova-search:numbers` (also hourly after settings changed).

## Search layer additions (1.5.0)

Recent searches as chips (browser only, with consent), automatic highlights (bestsellers, popular in search, newest), property filters (e.g. vehicle model), rating stars, "currently unavailable" hint and an "add to cart" button on cards, own text and product recommendations when nothing is found, configurable minimum characters.

## Analytics (1.6.0)

*Extensions → Nova Search* shows KPIs with comparison to the previous period, searches per day, top terms, searches without results (with Claude rewrite status), clicked products, Claude costs and CSV export per table. Daily values are counted from version 1.6.0 on.

## Roadmap

Planned features (synonyms, redirects, admin analytics, recommendations, product finder, shopping assistant …) are listed in [ROADMAP.md](ROADMAP.md) (German).
