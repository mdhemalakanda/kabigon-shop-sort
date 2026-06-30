# Kabigon Shop Sort v1.3.6

Auto sort dropdown for WooCommerce product archives on **Kabigon Shop** (Astra + Elementor Loop Grid).

## No shortcode required

Paste as a **WPCode → PHP Snippet → Run Everywhere** snippet. The toolbar appears automatically on:

- `/shop-2/` (Shop page)
- Product category archives (e.g. `/product-category/keytag/`)
- Native WooCommerce shop / taxonomy / product search archives

Optional shortcode `[kabigon_shop_sort]` only if you need the dropdown in a custom location.

## Sort options

- Default
- Best selling
- Newest
- Price: low to high
- Price: high to low
- Top rated (when reviews enabled)

## How it hooks in (Kabigon live stack)

| Layer | Hook |
|-------|------|
| Elementor Pro `loop-grid.product` | `elementor/frontend/widget/before_render` + `elementor/query/query_args` |
| Classic WooCommerce loop | `woocommerce_before_shop_loop` |
| Astra fallback | `astra_primary_content_top` |
| Block product grids | `query_loop_block_query_vars` |

## Install via WPCode

1. **Code Snippets → + Add Snippet → Add Your Custom Code (PHP)**
2. Paste contents of `kabigon-shop-sort.php`
3. **Auto Insert: Run Everywhere** (same as your other snippets)
4. Activate
5. Clear **WP Rocket** cache

## E2E test report

Full end-to-end verification with **live screenshots** (client-friendly):

- [reports/e2e-report-2026-06-30.html](reports/e2e-report-2026-06-30.html) — open in browser after clone
- [GitHub Pages](https://mdhemalakanda.github.io/kabigon-shop-sort/reports/e2e-report-2026-06-30.html) — view online (after Pages enabled)

## Changelog

### 1.3.6
- Force `suppress_filters => false` on Elementor / archive product queries so WooCommerce `posts_clauses` popularity/price SQL runs.
- Hook `elementor/query/{id}` (incl. `current_query`) with a `WP_Query` instance — fixes Loop Grid **Current Query** when `query_args` alone is not enough.
- Register `kss_catalog_orderby` / `kss_sql_sorted` as public query vars; register `posts_clauses` on `init` when `?orderby=` is present.

### 1.3.5
- Apply sort to the main archive `WP_Query` on `wp` so Elementor Loop Grid **Current Query** clones popularity/price order correctly.
- Keep SQL `posts_clauses` handler registration on requests with `?orderby=`.

### 1.3.4
- Relax `posts_clauses` guards for Elementor secondary product queries (no `is_sortable_view()` gate when `kss_catalog_orderby` or URL orderby is set).
- Register `posts_clauses` handler early on `wp` when a SQL sort is requested.
- Stop calling `WC()->query->get_catalog_ordering_args()` before query run (avoids conflicting WC clause filters).

### 1.3.3
- Fix Best selling on Elementor Loop Grid: apply `total_sales` sort in SQL **before** pagination (not per-page re-sort).
- Strip Elementor `product_query_order: asc` conflicts from loop query args.
- Run `elementor/query/query_args`, `pre_get_posts`, and `posts_clauses` at max priority.
- Remove `maybe_resort_posts` page-only fallback (it hid the pagination bug).

### 1.3.2
- Guaranteed Best selling / price / rating via `posts_results` re-sort when Elementor overrides SQL order.
- `woocommerce_product_query` + max-priority `posts_clauses` / `pre_get_posts`.

### 1.3.1
- Fix Best selling / price / rating on Elementor Loop Grid: apply sort to **secondary** product queries (`pre_get_posts`), not only `main_query`.
- `posts_clauses` fallback reads URL orderby when Elementor strips custom query vars.

### 1.3.0
- Run `posts_clauses` at priority 999 so Elementor cannot overwrite sort.
- Scope sorting with `kss_apply_sort` / `kss_catalog_orderby` query vars.

### 1.2.0
- Fix price, best selling, and top rated sorts on Elementor Loop Grid (uses `wc_product_meta_lookup` via `posts_clauses`).
- Add `pre_get_posts` backup so secondary product queries receive the selected orderby.

### 1.1.0
- No shortcode needed on Elementor Loop Grid archives (shop + categories).
- Elementor query sorting via `?orderby=popularity` etc.

### 1.0.0
- Initial release with shortcode and block support.
