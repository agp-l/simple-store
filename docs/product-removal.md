# Trvalé odstranění produktu

V editoru produktu otevři **Odstranit produkt → Připravit smazání produktu**. První krok nic nemaže. Ukáže jazykové verze, varianty, partnerské zdroje, kontroly a rozdělení fotografií. Teprve potvrzení náhledu odstraní katalogovou identitu ve všech jazycích. Pro dočasné stažení z nabídky nadále slouží **Skrýt produkt**.

## Co se odstraní

- Všechny produktové revize a překlady, nastavení prodeje, skladové limity, varianty a jejich nastavení.
- Produktové i variantní partnerské odkazy, pozorované nabídky, cenová historie a fronty kontrol.
- Výběry produktu na úvodní stránce, v kategoriích a vazby souvisejících produktů. Výslovně prázdný výběr zůstane prázdný.
- Nepoužívané fotografie z vlastní spravované složky `images/media/products/<product_key>/`, včetně velikostí `card` a `thumb` a metadat v knihovně.

Odstraněný produkt se už nenabízí v katalogu, exportu, sitemapě ani cenových kontrolách. Položka ve starém zákaznickém košíku se při jeho příštím načtení označí jako nedostupná a zabrání dokončení nové objednávky, dokud ji zákazník neodebere. Již přijatá objednávka zůstává platná.

## Co zůstává

Objednávky, doklady, platby, reklamace, účetní a skladová historie i rezervace přijatých objednávek se nemažou. Mají vlastní snímky produktových údajů. Smazání produktu neruší objednávku, platbu ani zásilku.

Fotografie používané jiným produktem nebo jeho historickou revizí, variantou, článkem/stránkou včetně historie, objednávkou, fakturou či zálohou sloučení zůstanou na disku. Soubory převzaté z jiných vlastníků, společné knihovny nebo staré nespravované obrázky se automaticky nemažou: jejich vlastnictví nelze bezpečně odvodit z jedné produktové karty. Odkazy zkopírované mimo tento obchod nelze zjistit a odstraněné fotografie z nich přestanou fungovat.

U dříve veřejných adres zůstane pouze název a informace **Produkt už není v nabídce** (HTTP 410, bez možnosti koupě, bez indexace). Nejde o skrytý plný produkt. Existující přesměrování na jiný platný produkt a nově zveřejněný produkt na stejné adrese mají přednost. Ručně napsané odkazy v článcích či menu se nepřepisují. Nikdy nezveřejněný koncept veřejnou stránku nevytváří.

## Souběh a dokončení úklidu

Potvrzení používá náhled uložený v administrátorské session. Pod transakčními zámky znovu ověří produkt, překlady, sklad, varianty, partnerské odkazy i seznam vlastních fotografií. Změna vyžaduje nový náhled. Průběžné technické záznamy cenových kontrol náhled samy nezneplatňují; změna ceny či dostupnosti ano.

Mazání a cenové jádro sdílejí `Supplier\WorkerLock`. Pokud kontrola právě běží, mazání nic nezmění a nabídne zopakování. Historická objednávka ani opožděný požadavek nevytvoří frontu pro neexistující produkt.

Databázová transakce nejprve odstraní katalogová data a uloží seznam souborů do `shop_product_media_cleanup`. Teprve po úspěšném commitu začne mazání fotografií. Každé použití fotografie se znovu kontroluje; soubory mimo povolenou složku a symbolické odkazy se neodstraňují. Jedna dávka zpracuje nejvýše 100 fotografií. Přerušený nebo neúplný úklid lze dokončit v **Knihovna médií → Úklid odstraněných produktů**. Produkt se při chybě disku nevrací do nabídky. Opakované spuštění je bezpečné i po odstranění originálu nebo části miniatur. Znovuvytvořený produkt se stejným klíčem zastaví starý úklid.

Tento přehled není koš a produkt z něj nelze obnovit. Pro případnou obnovu je potřeba dříve pořízený export/záloha. Existující historie sloučení se zachovává, ale její běžné kontroly konzistence brání automatickému vrácení změněné či smazané rodiny.

## Nasazení a ověření

Spusť standardní aktualizaci **Administrace → Databáze**, případně `php tools/apply-schema.php --apply`. Migrace přidá pouze `shop_removed_product_urls` a `shop_product_media_cleanup`; žádný produkt sama neodstraní. Dokud tabulky nejsou připravené, nové mazání je zablokované s vysvětlením. Existující katalog dál funguje.

- `tests/product-removal-database.php`: skutečné MySQL a MariaDB, překlady, souběžné úpravy a upload, vyloučení workeru, rollback, historie objednávek, odkazy, sdílené fotografie, chyba disku, opakování a reimport.
- `tests/product-removal-render.php`: potvrzení serverového náhledu, escapování, úklid a veřejná stránka HTTP 410.
- `tests/product-removal-browser.mjs`: formulář, povinný souhlas, karty s/bez variant a mobilní i desktopové rozvržení.
- `tests/media-deletion.php`: vlastnictví souborů, symbolické odkazy a opakovaný úklid miniatur.
