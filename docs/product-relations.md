# Related products

Relations are explicit, symmetric pairs of stable product keys, scoped by language.
`shop_product_relations` stores one canonical (sorted) pair and a plain-text note.
A relation is not automatically a compatibility claim: administrators should
verify model/variant compatibility before describing it as such.

Public detail pages resolve current published products and current URLs. Drafts,
missing translations and deleted products are not shown. Admin editing includes
drafts, a server-side name search (48 results; narrow the query), note editing and
removal. Removing a relation removes both directions. Product deletion removes
its relations in that language. Renaming or revising a product retains its links.

The table is additive and included in schema.sql. It is also created on first
authenticated write; public reads and import preparation never create it. All
admin writes require the shared admin authentication and CSRF policy. Pair writes
lock current products in stable key order and compare the prior note to prevent
stale overwrites. Identical replays are safe. Notes are escaped in HTML.

## JSON import and export

Choose “Související produkty — propojení a poznámky (JSON)” in export to export
links touching the selected products, including links to unselected products.
This is separate from the full product snapshot; include it when backing up links.
Import via Hromadný JSON in normal update mode without stock restoration:

```json
{
  "format": "simple-store.product-relations.v1",
  "products": [
    {
      "product_key": "0123456789abcdef0123456789abcdef",
      "language": "cs",
      "related": [
        {"product_key": "abcdef0123456789abcdef0123456789", "note": "Why these products work together."}
      ]
    }
  ]
}
```

`name` is optional and informational. `note` is at most 500 characters on one line.
Supply each undirected pair only once, with up to 100 links per item. Existing
products in the stated language are required, including drafts. `revision` is
unnecessary: imports update only links, not descriptions, prices, photos, stock
or product revisions. Omitted links and empty arrays remove nothing. Each source
item is atomic; invalid items are skipped and other products continue. Existing
notes may be updated, but intervening changes after preparation are rejected.

Tests cover reverse lookup, idempotence, additive imports, draft visibility,
current slugs, content preservation, stale edits, self-links, export round trips,
removal and product-deletion cleanup.
