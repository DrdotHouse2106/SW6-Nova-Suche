# 1.19.0
- New: **“similar products”** (settings → tab “Customers also bought”, default off). Once a day – together with “customers also bought” and via `bin/console nova-search:also-bought` – products with the same part type (product custom field, default `nova_teileart`, leading digits) are stored as an additional cross-selling, best sellers first, at most 12. Shown on the product page as a tab next to the regular cross-selling; a selected vehicle filters the display. Own cross-sellings stay untouched

# 1.18.0
- **Settings clearly arranged in the module:** *Extensions → Nova Search* has a new tab **“Settings”** with sub-tabs **Search window** (instant search, start view, results) · **Learning & AI** (learning from customer behaviour, feedback, Claude) · **Finding results** (word parts, number search, excluded products) · **Customers also bought** – instead of one long page. Sales channel switch and inheritance as usual; “Save” stores the changes of all sub-tabs
- Same values as in the plugin configuration (`NovaSearch.config.*`), no migration. The classic plugin configuration keeps working and shows a hint with a link to the new tab at the top; the “Settings” button in analytics and lists now opens the tab as well
- Some help texts shortened and clarified (no functional change)

# 1.17.1
- “Customers also bought” is shown on the product page as **its own row below the regular cross-selling** instead of an additional tab. Regular cross-sellings stay unchanged in their tabs

# 1.17.0
- New: **“customers also bought”** from your own orders (settings card “Customers also bought”, default off). Once a day it is **precomputed** which products were bought together (cancelled orders excluded, variants counted on the main product) and a Shopware cross-selling with a fixed ID is created per product – the product page loads nothing extra. Configurable: title, period (default 36 months), minimum shared orders, maximum products, “never include” (e.g. deposit items). Compute now: `bin/console nova-search:also-bought`. Filtered by vehicle with Nova vehicle switcher 1.17.0 or later

# 1.16.0
- New: **results for other models.** If a vehicle is selected in the vehicle switcher and the search window finds nothing for it, it searches again without the vehicle filter: “No results for “42003” for your vehicle “Acadiane” – there are 3 results for other models”, followed by these results and a link “Clear vehicle selection and show all results”. Recommendations and partner shops are skipped then. Requires Nova vehicle switcher 1.16.0 (without it nothing changes)
- A zero-result term with an active vehicle filter is only queued for the Claude analysis if there are no results for other models either

# 1.15.0
- Security: **only terms that really have no results are sent to Claude.** Previously a request with a forged hit count of 0 ("bremse" although brake parts exist) could trigger a Claude rewrite that was then applied to every search for that term for 30 days. The background run now checks every term against the real product search right before asking Claude; terms with results are dropped. In addition, the search window beacon only queues a term when the server itself delivered it without results shortly before
- Security: a failing product search during a background check no longer counts as "0 hits" – nothing is learned, deleted or disabled, the next run checks again (learned corrections, 30-day re-check, popular terms, ranking). Terms consisting only of stop words still count as "no results"
- Performance/protection: **word parts** cache dictionary lookups for one hour in the object cache (per language and word part) and extend at most 4 words per search – long random input no longer triggers a series of expensive database queries. New words from the search index apply within an hour
- Protection: uncached search window requests are limited per IP (1,200 per hour). Above that, visitors still get results, but only the plain search without word parts, sister shops, categories and recommendations – so a misconfigured proxy does not lock anyone out
- Security: **IPv6 addresses count per /64 network** – all "per IP" limits and de-duplications could previously be bypassed by rotating addresses within one's own network. Concurrent requests are counted exactly using Shopware's lock component; only a hash is stored in the cache and lock files
- Security: the search result page now counts searches with the same per-IP and per-sales-channel limits as the search window
- Feedback: at most 300 per sales channel and hour (previously 1,000) and at most 10 texts per IP and day – further ratings count without text
- "Request a part": requests from other websites are rejected (`Sec-Fetch-Site`), limit per IPv6 /64; the hidden spam field is no longer called "website", so browser autofill no longer fills it and swallows real requests
- README: new section **Abuse protection** explaining `TRUSTED_PROXIES` – behind a proxy or CDN without it all visitors share one budget, with a too broad value (`0.0.0.0/0`) anyone can pick their own IP
- Development: unit tests for the pure logic (`php phpunit.phar`, no Shopware needed)
- Intentionally unchanged: the admin report "Zero results" still uses the hit count reported by the browser (affects statistics and CSV only, no longer Claude); redirects to other domains stay allowed (requires "edit settings"); cookie-based purchase attribution only affects verified products

# 1.14.1
- German storefront texts of the search layer now consistently use the formal „Sie“

# 1.14.0
- New: **search result feedback** – below the results in the search window and on the search result page (also with 0 hits) visitors see "Do you like the search result?" with thumbs up / down. After thumbs down an optional short text ("What were you looking for?", max. 200 characters), then "Thank you!". Accessible, follows the theme, mobile friendly
- Can be switched off per sales channel: *Configure → Search result feedback* (default on). Run `bin/console theme:compile` after the update
- Stored: sales channel, search term, number of hits determined by the server, rating, text, source and date – no IP addresses, customer or session data; deleted after 365 days, uninstalling without "keep data" drops the table
- Security as for learning signals: shop session, same origin, no bots, JSON only; limits per IP and sales channel; one rating per visitor, term and day; text sanitized and output as plain text only
- Analytics: new tab "Feedback" with 👍/👎 per term, hits, "Synonym"/"Redirect" buttons, visitor texts with "Delete" and CSV export (also `nova-search:export --type=feedback`)
- Store API: `POST /store-api/nova-search/feedback` for the shop app
- Privacy: README note added – the text is free input and may contain personal data

# 1.13.0
- New: **word parts** – Shopware only matches a search word at the beginning or end of a word, so "vergaser" found "Doppelvergaser" but not "Doppelvergasers" (genitive). Words of 5 or more letters now also match inside longer words; these hits rank behind exact ones
- New: a compound search term without results is split into two parts that must both match ("vergasersatz" → "vergaser" + words ending in "satz"). The first part must be a word from the shop, the second part must end the word, which avoids random splits like "vergas" + "ersatz"
- Synonyms benefit as well. Can be switched off under *Configure → Word parts*

# 1.12.0
- Security: learned spelling corrections hardened – counted once per visitor (session and IP) and day, applied only after observations on at least two different days and an hourly check that the term itself has no results while the correction has. Previously a single forged request was enough
- Existing learned corrections are re-checked once after the update (inactive until then) and disabled if they fail; learned corrections are now part of the 30-day re-check
- New in analytics ("Zero results"): list of all stored rewrites (Claude and learned) with a "Disable" button
- Security: storefront learning signals require an existing shop session, same origin and no bot; additional limits per client IP and per sales channel (Symfony RateLimiter), also for the Store API
- Security: popular searches use a server-side verified hit count instead of the browser value and require searches on at least three different days
- Security: learned ranking and "popular in search" only consider products the search finds for that term without learning; clicks and add-to-cart count once per IP, term and product per day
- Claude queue: new terms limited per IP, bots excluded, frequently searched terms first; instant evaluation on the search page at most three times per IP and hour
- Sister shop search: "nothing found" is cached for 10 minutes, calls limited per IP
- Administration: API routes require the "read settings" or "edit settings" privilege
- Cleanup: observed corrections and never resolved terms are deleted after 180 days; uninstall also removes the corrections table
- README: privacy notes clarified

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
