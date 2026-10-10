# Import produktu z AI

Administrace → **Import z AI**, také v liště nad katalogem. Vlož JSON → **Zkontrolovat a zobrazit náhled** → **Vytvořit produkt**. Formulář má i kopírovatelné zadání pro libovolnou AI, seznam skutečných kategorií a ukázku Flextail. Žádný API klíč ani SQL nejsou potřeba. Vyžaduje přihlášeného správce a CSRF token; není to veřejné API pro anonymní volání.

## Formát v1

```json
{
  "format": "simple-store.product.v1",
  "language": "cs",
  "product": {
    "name": "Název produktu",
    "category_path": "vybaveni/powerbanky",
    "price_czk": 1960,
    "brand": "Značka",
    "summary": "Perex do 180 znaků.",
    "description": "Vlastní popis produktu.",
    "published": false,
    "stock_status": "out_of_stock",
    "specifications": [{"name": "Hmotnost", "value": "145 g"}],
    "sections": [{"type": "text", "heading": "Vlastnosti", "body": "Text"}]
  }
}
```

Povinné jsou format, language a product; pro nový produkt také name, category_path a price_czk (celé Kč, 1–10 000 000). Kategorie musí již existovat a být aktivní. Nový produkt je standardně koncept bez skladové zásoby. Prázdný/nevyplněný slug se odvodí z názvu; duplicitní adresa se odmítne. Ostatní dostupná pole:

- `slug`, `brand`, `summary`, `description`: texty, stejné limity jako běžná editace.
- `published`: JSON boolean, nikoli řetězec. `stock_status`: `out_of_stock`, `in_stock`, `on_order`.
- `image_path`: HTTPS URL nebo podporovaná cesta v images/. Bez ní neutrální náhled.
- `gallery`: až 12 adres obrázků.
- `options`: až 8 objektů `{"name":"Barva","values":["Černá","Modrá"]}`.
- `specifications`: až 40 objektů s `name` a `value`.
- `sections`: až 30 objektů s `type`, volitelným `heading` a `body`. Typy: text, list (řádky oddělené `\n`), table (řádky `Název | Hodnota`), image (cesta obrázku v body).

JSON má nejvýše 128 KiB. Neznámá pole a nesprávné datové typy se odmítají. Texty nejsou SQL ani spustitelný kód. Náhled nic neukládá. Uložení znovu provede validaci a používá transakční ProductRepository, stejné revize a ochranu souběžných změn jako editor. Chyba ponechá vstup ve formuláři.

## Úprava existujícího produktu

V detailu jako správce klikni **JSON pro AI**. Export obsahuje `product_key` a `revision`. AI musí zachovat oba identifikátory a `language`. V product může poslat jen změněná pole. Vynechaná pole se zachovají; dodané seznamy se nahrazují celé, `[]` je vyprázdní. Import odmítne neaktuální revizi. Jazykový překlad novým klíčem automaticky nevytváří.

Skladové kusy, rezervace objednávek, výběr na titulní stránku a soukromé dodavatelské odkazy import nemění. Nastavují se v existujících ovládacích prvcích; AI nezná skutečný stav skladu. Samotné `in_stock` bez volných kusů nezpřístupní objednání.

## Fotografie a ukázka

Externí HTTPS adresy se pouze uloží, server nic nestahuje. Kdo chce vlastní soubory a miniatury, po importu použije Fotografie v detailu. Tím se používá již zavedené zpracování obrázků. Import neobsahuje stahování cizích URL do serverové sítě.

`src/Product/examples/flextail.json` je připravený koncept podle [Gramino](https://gramino.cz/produkt/flextail-zero-power-10000-power-bank/), ověřeno 2026-10-10. Referenční cena 1 960 Kč není automaticky potvrzená prodejní cena tohoto obchodu. Text je vlastní, fotografie je externí odkaz. Před zveřejněním zkontroluj kategorii, vlastní cenu, dostupnost a fotografie. Pouhé načtení ukázky nevytvoří záznam v databázi.

## Implementace a ověření

Formát: `src/Product/ProductImport.php`; ovládání: `src/Admin/product-import.php`; šablona: `view/admin/product-import.php`. POST na admin.php s akcí product-import-preview nebo product-import-save, JSON v poli json a platným csrf ze správní relace. Budoucí tokenové API může použít stejný převodník, ale musí mít vlastní autentizaci; hesla/tokeny se neposílají AI v JSON produktu.

`php tests/product-import.php` ověřuje výchozí koncept, ukázku, export/import bez změn, částečné změny, mazání kolekce, zastaralou revizi, chybné JSON i datové typy.
