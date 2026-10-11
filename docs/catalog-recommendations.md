# Category recommendations and catalog discovery

Public category pages include all descendants and default to random products.
The random order is a SHA-256 ordering of product identity and a per-navigation
seed. Pagination links carry the same seed, sort and weight filter; an unchanged
catalog therefore has no duplicate cards across pages. A new visit gets a new
seed. Concurrent publication/deletion can still change offset-based pages.

Each category has its own ordered recommendations in
`shop_category_product_selections`, keyed by language and category path. Parent
recommendations do not automatically become child recommendations. Up to 24 tips
can be selected for each category. Public queries still enforce category,
publication, search and weight constraints. Matching tips are first for every
sort, with a “Náš tip” badge; remaining slots contain the rest of the selection.
Explicit price/name sorts apply to the non-recommended remainder.

Administrators can add/remove the current product from the homepage or any of
its ancestor categories in the product panel. Drag recommendation handles in the
category's “Náš tip” panel, or in the existing homepage editor, to save their
order. Alt + Up/Down is the keyboard alternative. Reorders require administrator
login, CSRF, the exact current membership and the previously displayed order;
stale saves are rejected. Failed requests restore the last acknowledged order.

The additive table is included in schema.sql and created on the first admin
recommendation change if absent; public requests do not create database tables.
No existing product fields or media are changed.

Weight filtering reads structured specifications named Hmotnost, Váha or Weight
(optionally with a g/kg unit in the label). It accepts a single numeric g/kg
value, including decimal commas. Ranges, variant-specific descriptions, unknown
units and missing weights are excluded when the filter is active. Nothing is
inferred from marketing text. Clear the limit to include all products again.
No new weight column or product import format is needed. Filtering scans SQL
pages until enough matching rows are found; a large catalog may later benefit
from a normalized, indexed weight projection maintained during imports/edits.
