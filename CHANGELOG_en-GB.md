# 1.11.0
- New: learns spelling corrections from visitor behaviour (misspelling without click → similar term with click)
- Analytics: typing steps are no longer counted as separate searches

# 1.10.8
- Fix: search window returned error 500 with 0 results but matching categories (plural snippet had no form for 0)

# 1.10.7
- New plugin icon in Nova style
- Repository renamed to SW6-Nova-Suche

# 1.10.6
- With a redirect and no results, the search window only shows the redirect link (no "no results", request form, recommendations or sister shops); the term is not queued for Claude

# 1.10.5
- More accurate search counts: bots, prefetch and automated requests are ignored, the same term counts once per session within 30 minutes
- Links to sister shops open in a new tab

# 1.10.4
- Rewrite notice placed directly above the own (rewritten) results, below sister shop hits, with adjusted wording

# 1.10.3
- Sister shops are also searched when results only came from a rewrite; their hits are shown above the rewritten results

# 1.10.2
- Start view without highlights: popular searches in up to 4 columns instead of one long list

# 1.10.1
- Number search: numbers with a group prefix (e.g. "281-281569") are also found by the part after the prefix ("281569"); the index is rebuilt automatically after the update

# 1.10.0
- "Request this part" form right in the search window (email or phone, sent by mail, customer stays in the search)

# 1.9.0
- New: sister shop results with 0 hits, hint rules (word → hint with link), "Request this part" button with pre-filled contact form

# 1.8.0
- New: import search terms and clicks from CSV exports of a previous search service ("Import data" button in analytics, bin/console nova-search:import-stats)

# 1.7.2
- Bestseller highlights count orders instead of quantity

# 1.7.1
- New: switch "Only products with image" for highlights and no-results recommendations (default: on)

# 1.7.0
- New: synonyms and redirects as manageable lists under "Extensions → Nova Search" (search, active switch, edit, delete, sales channel per entry), CSV import/export, fix buttons in the "No results" table
- Entries from the former config text fields are migrated on update

# 1.6.0
- New: menu entry "Extensions → Nova Search" with analytics (KPIs with comparison, daily chart, terms, no results, clicked products, Claude costs, CSV export, settings button)

# 1.5.0
- New: recent searches, automatic highlights, property filters, rating stars, availability hint and add-to-cart button on cards, own text and recommendations when nothing is found, minimum characters setting

# 1.4.0
- New: checkbox "Exact part number only" in the search layer – shows only products with exactly this number; Store API parameter exactNumber

# 1.3.0
- New: number search – product, manufacturer, EAN and OE numbers (custom fields, properties) regardless of spaces, dots, dashes and slashes; also by prefix
- Number index is updated when products are saved, fully rebuilt hourly or via bin/console nova-search:numbers

# 1.2.0
- New: synonyms (one group per line) for search layer, search page and app; multi-word searches stay precise
- New: redirects for search terms – Enter goes straight to the target, the layer shows a link, the Store API returns `redirect`
- New: excluded products – closeout without stock, products, categories, tags or a yes/no custom field

# 1.1.0
- Monthly cost limit for the Claude stage (default 5 USD, 0 = off) with warning threshold (default 80 %)
- Usage is calculated per month and model from the reported tokens and stored
- Before every call the maximum cost must fit into the remaining budget; once the limit is reached terms stay open
- New command bin/console nova-search:claude-usage, export type claude-usage

# 1.0.0
- First release: search window with images, filters (brands, price), sorting and infinite scroll
- Learns from searches, clicks, cart and purchases (no personal data)
- Automatic popular searches, recently viewed in the start view
- Claude (Haiku 5.5) permanently improves searches without results – each term is sent only once
- Store API for apps (search, popular searches, tracking)
- CSV export of search data: bin/console nova-search:export
- The search window is off after installation and is enabled per sales channel
