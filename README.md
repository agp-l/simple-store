# Dobrodruzi — jednoduchý obchod a CMS

První základ vlastního redakčního systému v PHP 8 a MySQL. Grafika obchodu zůstává ve `view/`. Architektura a důvody jednotlivých rozhodnutí jsou popsané v [docs/architecture.md](docs/architecture.md).

**Nejdřív spusť v kořeni projektu:**

```bash
composer install
cp config/database.example.php config/database.php
```

Pak otevři `config/database.php` a nastav `host`, `port`, `database`, `user` a `password` podle svého MySQL. V příkladu jsou přihlašovací údaje běžné pro místní XAMPP (`root` bez hesla); pokud máš heslo nastavené, doplň ho. **Pokud už máš vlastní `config/database.php` z předchozí verze, nekopíruj příklad znovu:** původní klíč `dsn` aplikace také umí přečíst, stačí zkontrolovat uživatele a heslo. Tento soubor je schválně ignorovaný Gitem, aby se heslo nedostalo na GitHub.

## Spuštění v Apache / XAMPP / LAMPP

1. Nakopíruj celý repozitář do složky, kterou obsluhuje Apache. Web nemá složku `public/`; přístup ke zdrojovým a konfiguračním souborům blokuje `.htaccess`. Apache musí mít povolené `mod_rewrite` a `AllowOverride` pro tuto složku.
2. Ve složce projektu spusť `composer install`. Composer načítá třídy ze `src/` přes namespace `SimpleStore\` a nainstaluje MeekroDB 2.5.2. PHP potřebuje rozšíření `mysqli`. Pokud používáš LAMPP, spouštěj příkazový skript přes stejné PHP jako web: `/opt/lampp/bin/php tools/content.php …`.
3. V MySQL vytvoř tabulku importem jediného aktuálního souboru. V LAMPP použij `/opt/lampp/bin/mysql -u root < database/schema.sql` (pokud má root heslo, přidej `-p`); když na této cestě klient není, zkus `/opt/lampp/bin/mariadb` nebo import souboru v phpMyAdmin. Samotné `mysql` může chybět v systémové proměnné PATH, i když je MySQL součástí LAMPP. Příkazy `CREATE DATABASE IF NOT EXISTS`, `USE` a `CREATE TABLE IF NOT EXISTS` už v souboru jsou. Pokud hosting nedovolí `CREATE DATABASE`, vytvoř databázi v hostingu a v kopii importovaného SQL vynech první dva příkazy.
4. Pokud ještě nemáš `config/database.php`, zkopíruj `config/database.example.php` do `config/database.php` a vyplň přístupové údaje. Tento soubor je v `.gitignore` a nesmí se commitovat.
5. Otevři adresu složky projektu v Apache. Úvod obchodu funguje i před nastavením databáze; blog, menu a redakční stránky potřebují importované tabulky. Prohlížej `/cs`, `/cs/kategorie-produktu/spani/spacaky`, `/cs/blog` a `/cs/o-nas` po založení obsahu.

Konfigurace databáze je obyčejný PHP soubor, který `return [...]` vrací pojmenované hodnoty. Aplikace jej načítá jen při připojení k databázi; nepoužívá globální proměnné. Původní zápis s `dsn` také funguje. Při vývoji je v `config/site.php` zapnuto `'debug' => true`; PHP chyby, upozornění a zachycené výjimky se zobrazují na stránce. Až web skutečně zveřejníš, přepni jej na `false`. Pokud se stále objeví holá chyba 500 bez stránky aplikace, zkontroluj Apache error log a podporu `.htaccess`/`mod_rewrite`. Příkaz `ini_set()` nemůže zobrazit chybu parsování v tomtéž souboru, pokud se kvůli ní PHP vůbec nespustí.

## Administrace obsahu

Administrace je na `http://localhost/simple-store/admin.php`. Jednoho administrátora vytvoříš příkazem v kořeni projektu:

```bash
/opt/lampp/bin/php tools/admin.php
```

Příkaz jednou vypíše jméno `admin` a náhodně vygenerované heslo. Ulož si je; konfigurace ukládá jen jeho hash do `config/admin.php`, který se nesmí nahrávat na GitHub. Pro změnu hesla spusť `/opt/lampp/bin/php tools/admin.php --reset` a přihlas se novým heslem. Administrace umožňuje založit, upravit a publikovat stránku či článek. Obsahuje seznam aktuálních dokumentů a historii verzí; načtení starší verze do formuláře a její uložení vytvoří **další nový řádek**, nepřepíše historii. Přihlášení chrání PHP session, formuláře mají CSRF token. Před zveřejněním webu vypni režim `debug`.

Pokud administrace hlásí `Permission denied` při čtení `config/admin.php`, spusť v kořeni projektu `chmod 644 config/admin.php`. Apache obvykle běží pod jiným uživatelem než tvůj terminál; soubor musí být pro Apache čitelný. Přímé stažení souborů ze složky `config/` zakazuje `.htaccess`.

Po aktualizaci můžeš bezpečně znovu importovat aktuální `database/schema.sql`; import existující obsah nemaže. Stránky a články žádné další tabulky nepotřebují. Rychlé kontroly: `/opt/lampp/bin/php tests/admin-auth.php`, `/opt/lampp/bin/php tests/database-connection.php` a `/opt/lampp/bin/php tests/url-manager.php`.

## Produkty

Po této aktualizaci **znovu importuj aktuální `database/schema.sql`**. Soubor přidá `product_revisions` a stávající stránky ani historii nemaže:

```bash
/opt/lampp/bin/mysql -u root < database/schema.sql
```

Pokud má uživatel root heslo, přidej `-p`, nebo soubor importuj přes phpMyAdmin. **Po této aktualizaci je import potřeba zopakovat i u existující instalace**: jediný `schema.sql` přidá chybějící sloupec `details_json`, revize ani produkty nemaže. Při prvním vytváření jej založí rovnou. V administraci otevři **Produkty**. Povinné jsou název, kategorie, cena a hlavní obrázek. Adresa (slug) vzniká automaticky z názvu bez diakritiky; můžeš ji ručně změnit. Při následných úpravách produktu se automaticky nepřepíše, takže staré odkazy zůstanou funkční. Stejná pravidla platí i pro nadpisy stránek a článků. Pokud už existuje stejná adresa, editor požádá o jinou.

### Když při ukládání produktu chybí `details_json`

V `config/database.php` zjisti název databáze v položce `database`. **V phpMyAdmin vyber právě tuto databázi**, otevři záložku SQL a pro dosavadní tabulku spusť jednou:

```sql
ALTER TABLE product_revisions
  ADD COLUMN details_json LONGTEXT NULL AFTER description;
```

Tento příkaz zachová všechny produkty a jejich revize. Před jeho spuštěním můžeš v phpMyAdmin ověřit stav tabulky pomocí `SHOW COLUMNS FROM product_revisions LIKE 'details_json';`: prázdný výsledek znamená chybějící sloupec. Pokud sloupec již existuje, příkaz `ALTER TABLE` nespouštěj a zkontroluj, zda aplikace používá stejnou databázi, kterou jsi vybral v phpMyAdmin. Soubor `database/schema.sql` sám obsahuje opakovatelnou migraci, ale v úvodu výslovně používá databázi `simple_store`; pokud máš v konfiguraci jiné jméno, samotný import může aktualizovat jinou databázi než tu, do které se web připojuje. Po opravě obnov administraci.

Do hlavního obrázku napiš např. `images/batoh.webp` (soubor nahraj do složky `images/`) nebo HTTPS adresu. Do galerie napiš další cesty, jednu na řádek. Pod cenou ve formuláři můžeš přidat libovolné skupiny výběru: název `Barva` s možnostmi `Grey / Clay` a `Black`, další skupinu `Velikost` s možnostmi `42 EU` a `43 EU`; možnosti se píšou každá na nový řádek. Funguje i `Pozice zipu`, `Délka` a další vlastní názvy. V detailu musí zákazník vybrat hodnotu v každé skupině; košík ukáže přesně tyto hodnoty. Produkt s výběrem vede z karty přímo na detail. **Cena a dostupnost jsou nyní společné pro celý produkt, nikoli pro jednotlivé kombinace**; dostupnost po konkrétních velikostech a variantách vyžaduje další krok. Košík stále funguje jen jako ukázka v prohlížeči a neposílá objednávku.

Technické údaje vyplň jako dvojice názvu a hodnoty; v detailu vytvoří přehlednou tabulku. Dlouhý popis se skládá z bloků **Odstavce**, **Seznam** (každá položka na vlastní řádek), **Tabulka** (každý řádek ve tvaru `Název | Hodnota`) a **Fotografie** (cesta `images/nazev.webp` nebo HTTPS adresa). Blokům můžeš dát nadpisy a řadit je v pořadí ve formuláři. V textových blocích lze psát `**tučné**` a `[název odkazu](https://example.org)`; HTML značky se vypisují jako text. Původní produkt se starým seznamem velikostí půjde normálně otevřít i upravit; při dalším uložení se velikosti zobrazí jako volitelný výběr. V editoru vybereš libovolnou hloubku kategorie z jednoho přehledného seznamu. Po zaškrtnutí „Publikovat na webu“ se veřejně zobrazí karta a detail na `/cs/produkt/slug`. Dokud nevydáš první produkt, původní ukázkové karty zůstanou na úvodní stránce.

Při každém uložení se do tabulky vloží nová revize se všemi údaji včetně ceny, kategorie, cest k obrázkům, výběru a všech bloků. Historie umožňuje načíst starší verzi do editoru a znovu ji uložit. Tyto údaje se ukládají čitelně jako JSON ve sloupci `details_json` stejného řádku; neexistují další provázané tabulky. Rychlá kontrola po importu: `/opt/lampp/bin/php tests/product-details.php`.

## Kategorie a menu

Po aktualizaci znovu importuj **jediný aktuální** soubor `database/schema.sql`. Obsahuje `CREATE TABLE catalog_categories` a jeden `INSERT IGNORE` se všemi 58 požadovanými sekcemi a podsekcemi. Import můžeš opakovat: existující produkty, stránky a jejich revize zůstanou zachované, stejně jako případné úpravy názvů kategorií. V LAMPP:

```bash
cd /opt/lampp/htdocs/simple-store
/opt/lampp/bin/mysql -u root -p < database/schema.sql
```

Pokud má root účet bez hesla, vynech `-p`. Soubor začíná `CREATE DATABASE ... simple_store; USE simple_store;`, takže si ověř, že `config/database.php` ukazuje také do databáze **simple_store**. Při jiné databázi změň v lokální kopii SQL pouze její název před importem.

Kategorie žijí v jedné tabulce. Jejich `path` je neměnná cesta (např. `spani/spacaky` nebo `obleceni/muzi/bundy`), `title` je text na stránce. Přidání další úrovně nepotřebuje nový sloupec ani tabulku: vlož její cestu a dbej na existenci všech rodičů. Například:

```sql
INSERT INTO catalog_categories (language, path, title, sort_order)
VALUES ('cs', 'spani/spacaky/zimni', 'Zimní spacáky', 1);
```

Hlavní menu nahoře ukazuje jen šest kořenových kategorií. Na stránce `/cs/kategorie-produktu/spani` uvidíš nad produkty Spacáky, Quilty a ostatní přímé podsekce. Stránka `/cs/kategorie-produktu/spani/spacaky` ukáže produkty ze spacáků; existující produkty se starými kódy `spacaky`, `stany` a objemovými filtry batohů se přečtou i bez zásahu do historie revizí. Stránka hlubší kategorie ukáže její potomky, nebo vedlejší kategorie, pokud už potomky nemá. Vypnuté kategorie se nezobrazují a neexistující adresa vrací 404. Produkty v katalogu filtruje PHP na serveru; vyhledávání a řazení aktuálně vypsané stránky nadále obstarává prohlížeč.

`config/menus.php` pojmenovává místa na stránce. `primary` tvoří kategorie hlavičky, `category_tabs` načítá přímé děti právě zobrazené kategorie, `utility` tvoří Blog a publikované stránky označené pro horní odkazy, `footer` znovu používá kořenové kategorie. Můžeš přidat další místo s `source => categories` a `parent => 'spani'`, nebo `source => manual` s `items` obsahujícími `label`, `category` / `path` a vnořené `children`. Zobrazí se, až nové místo zavoláš přes `MenuManager::links('nazev')` a vypíšeš ve své šabloně. Třída vrací strom odkazů (`label`, `href`, `active`, `children`), takže HTML rozbalovacího menu lze přidat bez změny databáze nebo směrování.

Kontroly bez databáze: `/opt/lampp/bin/php tests/url-manager.php`, `/opt/lampp/bin/php tests/category-path.php` a `/opt/lampp/bin/php tests/menu-manager.php`. Po importu také `/opt/lampp/bin/php tests/category-navigation.php`.

## První stránka, článek a historie

Pro práci bez prohlížeče je také připravený příkazový skript, který **funguje pouze z terminálu**:

```bash
php tools/content.php create page cs o-nas 'O nás' 'Zde je náš příběh.'
php tools/content.php create post cs prvni-vyprava 'První výprava' 'Vyrazili jsme před svítáním.'
```

Výstup obsahuje `document_key` a `revision_number`. Po úpravě textu vytvoříš novou revizi (doplň vrácený klíč):

```bash
php tools/content.php update DOKUMENT_KLIC 1 page cs o-nas 'O nás' 'Nové znění, staré zůstává uložené.'
php tools/content.php history DOKUMENT_KLIC cs
php tests/url-manager.php
```

Webové adresy pak budou `/cs/o-nas` a `/cs/blog/prvni-vyprava`. Všechny publikované články se ukážou pod `/cs/blog`. Publikované stránky označené pro menu se objeví v horních odkazech vedle Blogu. Opakované `update` se stejným číslem revize skončí chybou, aby uživatel omylem nepřepsal novější práci. Při tvorbě překladu použiješ původní klíč dokumentu, nový jazyk a očekávanou revizi `0`; nový jazyk se nejprve musí přidat do `config/site.php` **spolu s překladem textů rozhraní a kategorií**.

## Co už tu je a co přijde později

| Soubor | Úkol |
| --- | --- |
| `index.php` | Jediné veřejné směrování CMS; úvod, kategorie, produkt, stránky, blog a 404. |
| `src/Navigation/UrlManager.php` | Části URL, jazyk a odkazy při instalaci v podsložce. |
| `src/Navigation/MenuManager.php` | Pojmenovaná menu kategorií, publikovaných stránek a ručních odkazů. |
| `src/Category/CategoryRepository.php` | Jedna tabulka s kategoriemi a jejich stromem. |
| `src/Category/CategoryPath.php` | Práce s vnořenými cestami a čtení starých produktů. |
| `config/menus.php` | Určení zdroje pro jednotlivá místa menu. |
| `src/Database/ConnectionFactory.php` | Vytvoření připojení MeekroDB z lokální konfigurace. |
| `src/Admin/AdminAuth.php` | Přihlášení jediného administrátora, session a CSRF token. |
| `src/Product/ProductRepository.php` | Katalog, detail a každá revize produktu. |
| `src/Content/ContentRepository.php` | Čtení obsahu, historie a uložení nového řádku v transakci. |
| `src/Rendering/PageRenderer.php` | PHP pohledy bez Twig. |
| `database/schema.sql` | Vždy aktuální úplné schéma. |
| `view/` | HTML pro obchod, blog, stránky, `<head>`, hlavičku, menu a patičku. |

Košík a placení jsou stále **ukázkou v prohlížeči**; nevyřizují objednávky. HTML soubory v kořeni jsou starší statické náhledy vzhledu a nespouštějí CMS; Apache je blokuje.
