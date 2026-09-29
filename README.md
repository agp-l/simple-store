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
5. Otevři adresu složky projektu v Apache. Úvod bez databáze zobrazí pokyny k nastavení, ale nevydává ukázkové produkty za skutečné. Blog, menu, redakční stránky a produkty potřebují importované tabulky. Prohlížej `/cs`, `/cs/kategorie-produktu/spani/spacaky`, `/cs/blog` a `/cs/o-nas` po založení obsahu.

Konfigurace databáze je obyčejný PHP soubor, který `return [...]` vrací pojmenované hodnoty. Aplikace jej načítá jen při připojení k databázi; nepoužívá globální proměnné. Původní zápis s `dsn` také funguje. Při vývoji je v `config/site.php` zapnuto `'debug' => true`; PHP chyby, upozornění a zachycené výjimky se zobrazují na stránce. Až web skutečně zveřejníš, přepni jej na `false`. Pokud se stále objeví holá chyba 500 bez stránky aplikace, zkontroluj Apache error log a podporu `.htaccess`/`mod_rewrite`. Příkaz `ini_set()` nemůže zobrazit chybu parsování v tomtéž souboru, pokud se kvůli ní PHP vůbec nespustí.

## Administrace obsahu

Administrace je na `http://localhost/simple-store/admin.php`. Jednoho administrátora vytvoříš příkazem v kořeni projektu:

```bash
/opt/lampp/bin/php tools/admin.php
```

Příkaz jednou vypíše jméno `admin` a náhodně vygenerované heslo. Ulož si je; konfigurace ukládá jen jeho hash do `config/admin.php`, který se nesmí nahrávat na GitHub. Pro změnu hesla spusť `/opt/lampp/bin/php tools/admin.php --reset` a přihlas se novým heslem. Administrace obsahuje seznam aktuálních dokumentů, jejich založení a odkazy na přímou úpravu stránky či článku. Přihlášení chrání PHP session, zápisy ověřuje CSRF token. Před zveřejněním webu vypni režim `debug`.

Pokud administrace hlásí `Permission denied` při čtení `config/admin.php`, spusť v kořeni projektu `chmod 644 config/admin.php`. Apache obvykle běží pod jiným uživatelem než tvůj terminál; soubor musí být pro Apache čitelný. Přímé stažení souborů ze složky `config/` zakazuje `.htaccess`.

Po aktualizaci můžeš bezpečně znovu importovat aktuální `database/schema.sql`; import existující obsah nemaže. Stránky a články žádné další tabulky nepotřebují. Rychlé kontroly: `/opt/lampp/bin/php tests/admin-auth.php`, `/opt/lampp/bin/php tests/database-connection.php` a `/opt/lampp/bin/php tests/url-manager.php`.

### Stránky a články: úpravy přímo na stránce

V `admin.php` klikni na **Vytvořit stránku** nebo **Vytvořit článek**. Vznikne neveřejný koncept s ukázkovým úvodem, odstavcem, seznamem, tabulkou a fotografií. Kliknutím do textu jej přepíšeš, po opuštění pole se automaticky uloží a stránka se obnoví. Mezi bloky přidáš další text, seznam, tabulku či fotografii; blok lze přesunout, přepnout jeho typ nebo odebrat. Fotografii zadáš jako cestu `images/nazev.webp` k již uloženému souboru nebo pomocí HTTPS adresy. V textu funguje `**tučné**` a `[odkaz](https://example.org)`, řádky tabulky odděluje `|`.

Při první změně předvyplněného nadpisu se vytvoří i adresa (slug) z názvu; pozdější změny nadpisu adresu nepřepisují. Slug můžeš upravit ručně nahoře. Stránce lze nastavit zobrazení v horních odkazech a pořadí v menu; článek po publikování patří na `/cs/blog`. **Publikovat** zobrazí obsah návštěvníkům. Otevření publikované stránky přihlášeným správcem nabídne odkaz **Upravit přímo na stránce**; neveřejný koncept se načte pouze přihlášenému přes `?edit=1`. Každé uložení vytvoří nový řádek v `content_revisions`. Rozbalená historie umožní vrátit některou ze zachovaných verzí jako další revizi.

Existující stránky psané prostým textem zůstávají čitelné a při prvním přímém uložení se převedou na jeden textový blok. Staré revize se nepřepisují. **Žádné nové SQL není potřeba:** pokud už máš tabulku `content_revisions`, stačí `git pull`. Kontrolní test bez databáze: `/opt/lampp/bin/php tests/content-editor.php`.

Pokud v `config/site.php` později povolíš další jazyky, u dokumentu v administraci se objeví **Nový překlad**. Vytvoří samostatný neveřejný koncept ve vybraném jazyce pod stejným `document_key`. Jeho nadpis, adresu a bloky pak přepíšeš přímo na stránce překladu.

## Produkty: úpravy přímo na stránce

Po přihlášení otevři v `admin.php` sekci **Produkty**. Tlačítko **Vytvořit produkt** založí neveřejný koncept a ihned otevře jeho stránku s ukázkovým obsahem: název, úvod, fotografie, varianty, technické údaje, odstavec, seznam, tabulka a fotografický blok. Před zveřejněním nahraď ukázkové údaje svými. Další produkt můžeš založit i tlačítkem nad editovaným detailem.

Úpravy probíhají v náhledu detailu na `/cs/produkt/slug?edit=1`. Klikni přímo na nadpis, cenu, popis, nadpisy bloků, seznam, tabulku nebo technické údaje a přepiš je. Po odchodu z textu se změna sama uloží a stránka se obnoví s výsledným vzhledem. Klávesa Escape během psaní vrátí původní obsah. Pokud se uložení nezdaří, zobrazí se chyba u horní lišty; stránku obnov až po přečtení chyby. Každé úspěšné uložení vytvoří **novou kompletní revizi** v `product_revisions`; nejstarší revize nad nastavený limit se automaticky smaže.

Malými tlačítky mezi bloky vložíš text, seznam, tabulku nebo fotografii; bloky můžeš přesouvat, měnit jejich typ a odebírat. V detailu lze stejným způsobem přidat nebo odebrat variantu, technický parametr či fotografii v galerii. U položky **Upravit možnosti** napiš každou barvu, velikost nebo jinou možnost na nový řádek. Kategorie a dostupnost se vybírají v horní liště, adresu (slug) upravíš kliknutím. Při změně slugu se změní i adresa produktu; název se mění samostatně a slug sám nepřepisuje. Předchozí podobu lze načíst z rozbalené **Historie úprav** jako další novou revizi.

Fotografii změníš malým tlačítkem u obrázku: zadáš HTTPS adresu nebo cestu `images/nazev.webp`. Soubor pro lokální cestu musí být již nahraný do složky `images/`. V textových blocích lze psát `**tučné**` a `[název odkazu](https://example.org)`; po uložení se zobrazí výsledné formátování. Typ bloku **Tabulka** používá na každém řádku zápis `Název | Hodnota`. Produktové varianty mají zatím společnou cenu a dostupnost a košík je pouze ukázkou v prohlížeči.

**Publikovat produkt** zveřejní kartu a detail až po tvém výslovném potvrzení; do té doby koncept uvidí jen přihlášený správce. Na veřejně přístupném produktu uvidí správce odkaz **Upravit tento produkt**, návštěvník žádné editační prvky ani CSRF token nedostane. `admin.php` ověřuje přihlášení a token při každém zápisu. Při souběžných úpravách editor odmítne zastaralou revizi, aby nedošlo k přepsání novější práce.

Tento krok **nemění databázové schéma**. Pokud již máš importované aktuální `database/schema.sql` z předchozí aktualizace, stačí `git pull`. Pro kontrolu bez databáze spusť `/opt/lampp/bin/php tests/inline-editor.php` a `/opt/lampp/bin/php tests/product-details.php`. Po importu zůstává platný i test kategorií `/opt/lampp/bin/php tests/category-navigation.php`.

### Když chybí `details_json`

`config/database.php` určuje databázi, do které se web připojuje. V phpMyAdmin vyber právě ji a importuj aktuální `database/schema.sql`; obsahuje opakovatelnou migraci sloupce `details_json` a existující produkty ani revize nemaže. Soubor na začátku používá databázi `simple_store`, takže pokud máš v konfiguraci jiný název, změň jej v lokální kopii SQL před importem.

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

Hlavní menu nahoře ukazuje jen šest kořenových kategorií. Na stránce `/cs/kategorie-produktu/spani` uvidíš nad produkty Spacáky, Quilty a ostatní přímé podsekce. Stránka `/cs/kategorie-produktu/spani/spacaky` ukáže produkty ze spacáků; existující produkty se starými kódy `spacaky`, `stany` a objemovými filtry batohů se přečtou i bez zásahu do historie revizí. Stránka hlubší kategorie ukáže její potomky, nebo vedlejší kategorie, pokud už potomky nemá. Vypnuté kategorie se nezobrazují a neexistující adresa vrací 404. Produkty se filtrují, hledají i řadí v SQL. Katalog ukáže 12 produktů, blog 6 článků; další připojí tlačítko **Načíst další** bez obnovení stránky. Bez JavaScriptu tlačítko otevře další dávku jako běžný odkaz.

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

Webové adresy pak budou `/cs/o-nas` a `/cs/blog/prvni-vyprava`. Publikované články se zobrazují na `/cs/blog` po dávkách. Publikované stránky označené pro menu se objeví v horních odkazech vedle Blogu. Opakované `update` se stejným číslem revize skončí chybou, aby uživatel omylem nepřepsal novější práci. Při tvorbě překladu použiješ původní klíč dokumentu, nový jazyk a očekávanou revizi `0`; nový jazyk se nejprve musí přidat do `config/site.php` **spolu s překladem textů rozhraní a kategorií**.

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
| `src/Product/ProductInlineEditor.php` | Vzor konceptu a převod jedné přímé úpravy na celou produktovou revizi. |
| `src/Admin/inline-product.php` | Zabezpečené uložení a obnova revize při úpravě na stránce. |
| `src/Content/ContentRepository.php` | Čtení obsahu, historie a uložení nového řádku v transakci. |
| `src/Content/ContentBody.php` | Bloky ve stávajícím sloupci body a čtení původního prostého textu. |
| `src/Content/ContentInlineEditor.php` | Předvyplněný koncept a skládání nových kompletních revizí ze změn na stránce. |
| `src/Admin/inline-content.php` | Kontrolovaný zápis a obnova stránek a článků po přihlášení. |
| `src/Rendering/PageRenderer.php` | PHP pohledy bez Twig. |
| `database/schema.sql` | Vždy aktuální úplné schéma. |
| `view/` | HTML pro obchod, blog, stránky, `<head>`, hlavičku, menu a patičku. |

Košík a placení jsou stále **ukázkou v prohlížeči**; nevyřizují objednávky. Katalog teď vypisuje jen publikované produkty z databáze. Tlačítko pro vytvoření produktu v administraci připraví neveřejný koncept s ukázkovými texty; žádný koncept se veřejně neukáže před publikováním.

## Kontrola jádra a další hranice

`UrlManager::route()` rozpoznává jednotlivé typy adres a `index.php` k nim přiřazuje databázový obsah. Menu čte kategorie a stránky v PHP; JavaScript obsluhuje mobilní hamburger, připojení další dávky a ukázkový košík. Původní statické HTML náhledy a zkušební PHP detail byly odstraněny. Domovský odkaz i hledání zachovávají jazyk v URL.

Revize v `content_revisions` a `product_revisions` zůstávají celé v jednom řádku. Při uložení se v transakci označí stará revize jako neaktivní, vloží nová a smažou se revize starší než nastavený limit; unikátní indexy hlídají jednu současnou revizi a adresu. Editor při zápisu posílá očekávané číslo revize a odmítne zastaralou změnu. Při zakládání překladu se ověřuje existence původního dokumentu. V administraci se z historie načítají jen nadpisy a čísla revizí; úplná starší verze se načte až při obnově. Limit je `'revision_limit' => 50` v `config/site.php` pro každý produkt, stránku či článek **v každém jazyce zvlášť**. Čísla revizí rostou dál, i když se nejstarší řádky smažou. Starší existující dokument se pročistí při příštím uložení. Smazané revize už z historie nepůjde obnovit; před snížením limitu si případně zazálohuj databázi.

Tohle je rozumná podoba pro **verzovaný obsah**, ale neznamená to, že každou budoucí tabulku lze držet bez vztahů. Objednávky, platby a skladové pohyby budou potřebovat trvalé identifikátory produktů, pravidla konzistence a pravděpodobně i vztahy mezi záznamy. Přidání dalšího jazyka vyžaduje přeložit i texty rozhraní; sloupec `language` počítá s dvoupísmenným kódem.

Tento úklid **nemění databázové schéma**. Po `git pull` není potřeba žádný SQL příkaz. Kontroly bez databáze: `/opt/lampp/bin/php tests/url-manager.php`, `/opt/lampp/bin/php tests/menu-manager.php`, `/opt/lampp/bin/php tests/revision-guards.php` a `/opt/lampp/bin/php tests/catalog-render.php`.
