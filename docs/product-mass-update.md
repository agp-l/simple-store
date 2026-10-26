# Catalog-wide product updates

In **Nastavení obchodu → Produkty**, the administrator can independently set:

- Available quantity for every product (0–1,000,000; blank leaves it unchanged).
- Availability: in stock, on order, or out of stock.
- Visibility: publish or hide all products, including drafts and all languages.

A POST preview shows the selected changes, number of distinct products and number that will actually change. The same difference plan drives the write; a zero-change preview offers no apply button. A
second CSRF-protected POST confirms a server-side session plan. The one-use plan
is checked against the current catalog and stock under database locks. Concurrent
changes require a new preview. The operation is transactional: either all changes
commit or none do. Identical values are skipped. Content changes create revisions
with the existing retention limit; images, suppliers, prices and other fields are
preserved. No schema migration is required.

Quantity means **available to sell**, shared across translations. For products using variant inventory, the chosen quantity and availability apply to every enabled variant separately; retired variants are left unchanged. The preview fingerprint includes variant configuration and stock.
Stock-only edits update only quantities/statuses and bump a changed variant inventory version once. They do not run variant generation or source inheritance, rewrite retired variants, or disable managed variant pricing. Repeated identical input leaves both rows and versions unchanged.
It does not reset existing order reservations. After a physical inventory, exclude
pieces already reserved for orders. Cancelling an existing reserved order can
subsequently return its pieces. In-stock products with zero pieces remain
unavailable to customers; on-order products can be ordered with zero pieces.

Implementation: `ProductMassUpdate`, `src/Admin/settings-products.php`, and
`view/admin/settings-products.php`. No JavaScript is needed.

The same screen also offers **Hromadné vytvoření variant** for initial setup. It
creates combinations from saved product options and gives each new variant the
entered stock (default 10). It has a separate preview and confirmation so it never
implicitly resets existing variants. Products with any variant definitions,
without options, with active simple-stock reservations or invalid combinations
are skipped with a reason. The catalog's default language supplies the options,
with the first available translation as fallback; inventory is created once per
product. Old shared stock is reset when variant mode is enabled. Common content
and publication state are preserved. All writes occur in one transaction and a
stale catalog/stock/reservation snapshot is rejected.

Implementation: `ProductVariantBulkCreate`; database regression coverage:
`tests/product-variant-bulk-database.php`.

## Přepnutí způsobu prodeje

V **Nastavení → Produkty → Hromadné přepnutí způsobu prodeje** vyber
**Asistovaný nákup** nebo **Prodáváme my**, zobraz náhled a potvrď přepnutí.
Změna zahrne všechny současné produkty včetně konceptů a variant, každou
produktovou identitu pouze jednou napříč překlady. Nové produkty si ponechávají
vlastní nastavení; nejde o trvalé globální přepsání jejich režimu.

Prodávající, společné i jednotlivé nákupní ceny, prodejní ceny, sklad, viditelnost
a existující objednávky se zachovají. Návrat na asistovaný nákup proto využije
dříve uložené údaje. Hromadné přepnutí pouze mění režim a verzi jeho nastavení.

Náhled asistovaného nákupu automaticky vypíše produkty s chybějícím či
neověřeným prodávajícím, chybějící nákupní cenou nebo nákupní cenou vyšší než
prodejní. Kontroluje nabízené varianty i ceny všech překladů. Přepnout lze také
rozpracované produkty; jejich nákup zůstává blokovaný stávající kontrolou košíku
až do doplnění údajů. Náhled není kontrolou dostupnosti skladu.

Potvrzení používá jednorázový plán v administrátorské relaci a CSRF token.
Změna produktu, režimu, cen variant nebo profilu prodávajícího vyžaduje nový
náhled; samotný pohyb skladových kusů jej neruší. Celé přepnutí se uloží v jedné
transakci. Již shodný režim se znovu nezapisuje. Starší otevřený formulář
produktu nemůže hromadnou změnu tiše přepsat.

Implementace: `ProductSalesMassUpdate`. Bez nové databázové migrace a bez JS.

## Kontrola nástrojů 26. 10. 2026

Přiřazení prodávajících používá aktuální variantní zdroje stejně jako cenové jádro a vyžaduje kompletní pokrytí aktivních variant jedním partnerem. Při výměně partnera se vymažou jeho předchozí nákupní ceny, nikoli prodejní ceny; nový obchod nesmí převzít starou cenu bez ověření. Podrobnosti jsou v `purchase-partners.md`.

Nové hromadně vytvořené varianty přebírají společné odkazy jako neověřené výchozí zdroje. Vytvoření variant neověřuje přesnou barvu, velikost ani dostupnost u partnera. Hromadné přepnutí způsobu prodeje nadále zachovává existující počty a nákupní ceny stejného prodávajícího; při přechodu na vlastní prodej je nutné ověřit skutečný vlastní sklad. Hromadná úprava počtů v asistovaném režimu mění ruční limit nabídky. Automatická pravidla pro dostupnost partnerů a poslední kus nejsou součástí těchto nástrojů.

Každý nástroj má samostatný náhled. „Zrušit náhled“ ruší pouze jeho plán v relaci (`cancel_preview=mass|sale|variants`), takže ho nelze odeslat ze staré stránky. Náhledy ostatních nástrojů zůstávají zachované. Zapomenuté zaškrtnutí potvrzení tvorby variant zachová náhled k doplnění; chyba při samotném zápisu spotřebuje starý plán a vyžaduje nový náhled.

Rozšířené regrese pokrývají variantní přiřazení bez společných zdrojů, neúplné pokrytí, změnu/odebrání odkazu po náhledu, vymazání nákupních cen při výměně partnera, zděděné odkazy nových variant, opakovanou hromadnou úpravu skladu bez vedlejších zápisů, zrušení plánu a formuláře všech nástrojů na mobilu i desktopu.
