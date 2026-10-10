# Import produktů z AI: JSON a ZIP

V administraci otevři **Import z AI**, dostupný i nad katalogem. Nepotřebuje veřejné API ani další klíč: všechny akce vyžadují relaci správce a CSRF. SQL dodané AI se nikdy neprovádí. Databázová migrace není potřeba (používá existující produkty, sklad a tabulku dodavatelů).

## Přesné zadání pro AI

Celý aktuální prompt je v [`src/Product/import-prompt.txt`](../src/Product/import-prompt.txt). Stejný soubor administrace zobrazuje ke kopírování spolu se skutečným seznamem kategorií. Popisuje všechny podporované položky JSON, typy, limity, textové formátování, varianty, aktualizace i strukturu ZIP. Je to jediný zdroj zadání, aby dokumentace a formulář neobsahovaly různé formáty.

## Jeden produkt jako JSON

Vlož JSON → náhled → uložit. Nový produkt je výchozím nastavením koncept bez skladu. Bez obrázku se zobrazí neutrální náhled; fotky následně nahraj v detailu. Chyba ponechá JSON ve formuláři.

```json
{
  "format": "simple-store.product.v1",
  "language": "cs",
  "product": {
    "name": "Název produktu",
    "category_path": "vybaveni/powerbanky",
    "price_czk": 1960,
    "published": false,
    "summary": "Perex do 180 znaků.",
    "suppliers": [
      {"label": "Gramino", "url": "https://gramino.cz/produkt/topo-athletic-pursuit-3-mens/"}
    ]
  }
}
```

Odkaz v tomto minimálním příkladu demonstruje pole dodavatele; při skutečném importu musí odpovídat výrobku. Dodavatelé nejsou veřejnou součástí popisu. Zobrazují se ve stávající administraci produktu a objednávek.

## ZIP s jedním nebo více produkty

Příklad cest uvnitř ZIP:

```text
flextail-zero/product.json
flextail-zero/images/01-hlavni.jpg
flextail-zero/images/02-detail.webp
topo-pursuit/product.json
topo-pursuit/images/01-hlavni.png
```

Bez další společné nadřazené složky. V JSON se použije `images/01-hlavni.jpg`, nikoli celý název složky. Bez `image_path` se u nového produktu použije první fotografie podle názvu; bez `gallery` se ostatní fotografie přidají do galerie. Dodaná galerie určí přesné pořadí. Soubory jsou po načtení překódované standardní MediaLibrary na 1800, 960 a 240 px a uložené pod stabilním klíčem produktu. Nejsou pod veřejnými názvy z archivu. Žádný PHP ani jiný kód z archivu se nespouští.

1. Nahraj ZIP. Uvidíš frontu složek.
2. Spusť import. JavaScript posílá postupně jeden POST na produkt; bez JS každé kliknutí zpracuje jeden produkt.
3. Sleduj tabulku: uloženo / přeskočeno s důvodem / čeká.
4. Oprav pouze vadné složky a nahraj je v novém ZIP. Dokončené produkty se neopakují při obnovení stejné fronty.

Chyba fotografie nebo JSON přeskočí celý produkt. Nové soubory neúspěšného produktu se uklidí, databázová revize a dodavatelé se potvrzují společně. Dříve úspěšné produkty zůstanou. Přerušení běhu procesu nemusí umožnit určit výsledek posledního zápisu: taková položka se označí k ruční kontrole a neopakuje se automaticky, další produkty pokračují. Stav běží v soukromé dočasné složce navázané na relaci správce, ne ve veřejném webovém adresáři. Archiv se po dokončení odstraní; tlačítko ukončení odstraní i výsledek fronty. Staré dočasné balíčky se při novém uploadu čistí po 24 hodinách.

### Limity hostingu a bezpečnost

Vyžaduje PHP ZipArchive a GD s dekodéry použitých obrázků. ZIP do 128 MiB, rozbalená data do 512 MiB, nejvýše 200 produktů / 5000 souborů. Jeden obrázek do 12 MiB / 20 Mpx, product.json do 128 KiB. Menší upload_max_filesize/post_max_size hostingu mají přednost a zobrazují se ve formuláři. **Limit 12 fotografií galerie byl odstraněn**, včetně původního aplikačního limitu dávky; klasické HTTP nahrávání stále respektuje PHP max_file_uploads. Limity 30 obsahových bloků produktu a 60 bloků stránky se nezměnily.

Nebezpečné cesty, duplicitní ZIP cesty a nadměrný archiv se odmítají před zpracováním. Symlinky, šifrované položky, chybějící soubory, vadný CRC, poškozené obrázky nebo neplatný produkt mají vlastní chybu. Nepoužívá se extractTo ani stahování externích URL.

## Úpravy a dodavatelé

**JSON pro AI** v detailu exportuje `product_key`, aktuální `revision`, `language` a aktuální dodavatele. Pro úpravu zachovej identifikátory; vynechaná pole se nemění. Seznamy gallery/options/specifications/sections/**suppliers** se nahrazují celé; `[]` je vyprázdní. Dodavatelé jsou společné údaje produktu napříč jazyky. Stará revize se odmítne, stejně jako duplicitní slug u nového produktu.

ZIP úprava může nechat obrazová pole vynechaná. Uvedené cesty musí existovat v aktuálním ZIP. Nově přibalené obrázky bez explicitní gallery se přidají k dosavadní galerii; hlavní fotografie stávajícího produktu se změní jen explicitním image_path. Skladové kusy, rezervace a výběr na úvodní stránku import nemění.

## Externí fotografie

Nové HTTPS obrazové odkazy už nepřijímá JSON, ZIP, editor ani mediální API. Formulář ručního obrazového odkazu byl odstraněn. Staré snapshoty zůstávají čitelné, aby zmizení jedné externí fotografie neskrylo ostatní parametry a obsah. Před uložením nové revize takového produktu nahraj vlastní fotografie a odstraň staré externí reference. Tato změna neprovádí hromadné mazání existujících dat ani kopírování cizích obrázků.

Ukázka Flextail (`src/Product/examples/flextail.json`) obsahuje soukromý zdroj Gramino a referenční cenu zjištěnou 2026-10-10; fotografie se již nehotlinkuje. Před zveřejněním ověř vlastní cenu, kategorii a dostupnost.

## Třídy a testy

- ProductImport: formát, převod, export, validace dodavatelů.
- ProductZipArchive: validace archivu, bezpečné omezené čtení položek.
- ProductImportJob: soukromá fronta, zámek, checkpoint a oddělené výsledky.
- ProductPackageImporter: fotografie, mapování cest, revize a úklid chyb.
- ProductRepository a ProductSupplierLinkRepository: společný transakční zápis produktu a dodavatelů.

Testy: `tests/product-import.php`, `tests/product-import-database.php`, `tests/product-zip-database.php`, `tests/media-library.php`. ZIP testy běží na MySQL i MariaDB a ověřují úspěšný produkt mezi vadnými, 15 fotografií s miniaturami, odstranění fotek vadného produktu, dodavatele, rollback, neopakování hotové fronty a nebezpečné archivy.

## Hromadný export, zálohy a spolupráce s AI

Administrace → **Import a export** → **Export a seznam pro AI** zobrazuje aktuální produkty včetně konceptů a překladů. Lze filtrovat jazyk, kategorii včetně podkategorií a název/adresu. Zaškrtávací výběr se odesílá v jediném JSON poli, takže jej PHP `max_input_vars` nezkrátí. Tlačítko exportu všech výsledků filtru funguje i bez JavaScriptu. Bez filtrů exportuje celý katalog.

- **Úplný JSON**: identita, revize, jazyk, všechny podporované produktové údaje, dodavatelé a dostupné skladové kusy.
- **Seznam pro AI (TXT)**: název, úplná adresa, kategorie a veřejný/koncept. Pro šetření tokenů jej používej jako přehled katalogu; pro skutečné úpravy exportuj JSON jen příslušných produktů.
- Jde o zálohu aktuálních produktových dat. Fotografie jsou pouze jako cesty; jejich soubory, nastavení kategorií, historie revizí, objednávky a rezervace nejsou součástí JSON. Pro úplné zotavení webu zálohuj databázi a `images/`.

```json
{
  "format": "simple-store.products.v1",
  "products": [
    {
      "format": "simple-store.product.v1",
      "language": "cs",
      "product": {"name": "Nový batoh", "category_path": "batohy", "price_czk": 2500}
    }
  ]
}
```

Každý prvek `products` používá formát jednoho produktu. Export přidává kořenové `exported_at` a u položky `stock_quantity`. Aktualizace přidává `product_key` a `revision`; mění jen uvedená pole. Export limitujeme na stejné množství jako import (32 MiB, 10 000 položek), aby nevznikl nepoužitelný soubor. Produkt zpracovává společný validátor s limitem 128 KiB.

### Import a obnova

Na kartě **Hromadný JSON** nahraj soubor nebo vlož text. **Připravit a zkontrolovat** nic nemění v katalogu; uloží soukromou frontu a vypíše jednotlivé operace/chyby. Potom stiskni **Spustit / pokračovat v importu**. Každý požadavek provede nanejvýš jeden produkt v transakci. Vadné položky ostatní neblokují. Obnovení stránky umožní pokračovat, nejistý přerušený zápis se automaticky neopakuje. Dokončená fronta se znovu neprovede.

Běžný režim kontroluje revize z exportu. **Obnovit údaje ze zálohy** výslovně nahrazuje starší obsah jako novou revizi a dovede obnovit smazaný produkt pod původním klíčem; neodstraňuje ostatní produkty. Aktuální revize se zachytí při přípravě a ověří znovu při zápisu, takže další editace po přípravě není přepsána. Kategorie musí být aktivní, obrázky musí zůstat na webu a všechny údaje musí projít současnými validátory. Staré externí fotografie lze exportovat pro přehled, ale při importu je nutné je nahradit lokálními.

Sklad je výchozím stavem chráněn: nepřepisuje se, dokud správce nezaškrtne samostatnou volbu. Při zaškrtnutí se kontroluje počet kusů zaznamenaný při přípravě. Změna skladu mezi přípravou a importem způsobí rollback celé položky (včetně nové revize a dodavatelů). Sklad i dodavatelé jsou společné všem jazykům produktu. Při exportu více překladů musí být jejich skladové hodnoty shodné; sklad se v dávce nastaví u prvního výskytu.

`ProductBulkTransfer` drží formát a plán operací, `ProductImportJob` společnou frontu ZIP/JSON, `ProductRepository` atomický zápis. Všechny endpointy vyžadují správce a CSRF; exporty jsou `no-store`. Přehled produktů používá escapované hodnoty a běžné parametrizované SQL. Migrace databáze není potřeba.
