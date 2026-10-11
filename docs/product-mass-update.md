# Catalog-wide product updates

In **Nastavení obchodu → Produkty**, the administrator can independently set:

- Available quantity for every product (0–1,000,000; blank leaves it unchanged).
- Availability: in stock, on order, or out of stock.
- Visibility: publish or hide all products, including drafts and all languages.

A POST preview shows the selected changes and number of distinct products. A
second CSRF-protected POST confirms a server-side session plan. The one-use plan
is checked against the current catalog and stock under database locks. Concurrent
changes require a new preview. The operation is transactional: either all changes
commit or none do. Identical values are skipped. Content changes create revisions
with the existing retention limit; images, suppliers, prices and other fields are
preserved. No schema migration is required.

Quantity means **available to sell**, shared across variants and translations.
It does not reset existing order reservations. After a physical inventory, exclude
pieces already reserved for orders. Cancelling an existing reserved order can
subsequently return its pieces. In-stock products with zero pieces remain
unavailable to customers; on-order products can be ordered with zero pieces.

Implementation: `ProductMassUpdate`, `src/Admin/settings-products.php`, and
`view/admin/settings-products.php`. No JavaScript is needed.
