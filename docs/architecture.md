# Návrh jádra Simple Store

## Smysl první etapy

CMS řeší stránky, blog a nyní také produktové karty a detail. Produkty mají vlastní revize; skladové pohyby, objednávky a platby budou samostatné další etapy. Přístup správce je v tabulce `users`; heslo se ukládá pouze jako hash. Reset provádí příkaz `tools/admin.php --reset` z terminálu a zneplatní staré přihlášené relace.

## Jak jde požadavek aplikací

1. Apache ponechá obrázky a CSS jako soubory. Ostatní URL předá do `index.php`.
2. `UrlManager` rozdělí cestu na úseky, určí jazyk a udrží správný prefix, pokud je projekt v podsložce.
3. `UrlManager::route()` určí obchod, kategorii, produkt, seznam článků, článek, stránku nebo 404; `index.php` vybere odpovídající databázový obsah.
4. `CategoryRepository` načte strom kategorií a `ContentRepository` aktuální publikovanou revizi. `MenuManager` sestaví pojmenovaná menu pro jednotlivá místa šablony.
5. `PageRenderer` pošle data do stávajících PHP pohledů ve `view/`. Nepoužívá Twig ani databázi.

## Složky a názvy

| Cesta | Úkol |
| --- | --- |
| `src/Navigation/UrlManager.php` | Jazyk, tvar veřejné trasy a lokální odkazy. Zachovává název a metodu `getSegment()` ze starého CMS. |
| `src/Navigation/MenuManager.php` | Vytvoří pojmenované stromy odkazů pro libovolná místa šablony, žádné HTML uvnitř třídy. |
| `src/Category/CategoryRepository.php` | Jeden SQL zdroj pro kořeny, přímé děti, strom a drobečkovou navigaci. |
| `src/Category/CategoryPath.php` | Ověření cesty, její rodič, podstrom a převod starých kódů produktů při čtení. |
| `config/menus.php` | Přiřazuje zdroj a kořen kategorického stromu ke jménu každého menu. |
| `src/Navigation/Slugger.php` | Navrhne adresu z českého nadpisu; ruční slug má přednost. |
| `src/Content/ContentRepository.php` | SQL dotazy, publikovaný obsah a ukládání revizí. |
| `src/Content/ContentBody.php` | Bloky textu, seznamu, tabulky a fotografie v jediném sloupci body; starý prostý text se načítá jako jeden blok. |
| `src/Content/ContentInlineEditor.php` | Převod jedné drobné úpravy na nový úplný snímek dokumentu. |
| `src/Rendering/PageRenderer.php` | Vybere schválený PHP pohled a předá mu data. |
| `src/Product/ProductRepository.php` | Produkty, publikovaný katalog a revize v jedné produktové tabulce. |
| `src/Product/ProductDetails.php` | Ověří a připraví volitelné výběry, technické údaje, galerii a bloky obsahu. |
| `src/Product/ProductInlineEditor.php` | Výchozí koncept a převod jedné malé úpravy na kompletní ověřený snímek. |
| `src/Admin/inline-product.php` | Přijme ověřený požadavek z náhledu, uloží revizi a vrátí její číslo. |
| `config/site.php` | Výchozí a podporované jazyky. |
| `config/database.php` | Místní údaje k DB, není ve verzovacím systému. |
| `src/Admin/AdminUserRepository.php` | Čte účet správce z `users`, zakládá ho a mění hash hesla. |
| `tools/admin.php` | První účet, import starého souboru a reset hesla. |
| `admin.php`, `view/admin/` | Přihlášení a úpravy obsahu, bez zásahu do veřejného vzhledu. |
| `database/schema.sql` | Jediný aktuální soubor pro vytvoření celé databáze. |
| `view/` | HTML a malé výpisy proměnných; současná grafika obchodu. |

## Jedna tabulka pro stránky, články a historii

Jeden `document_key` je trvalá identita stránky nebo článku. `language` je jazyk konkrétního textu; překlady stejného obsahu sdílejí `document_key`, ale každý mají vlastní revize a URL. Každé uložení přidá **nový řádek** do `content_revisions`. Původní text, titulek, slug, stav i nastavení menu zůstanou na starém řádku, dokud nedosáhnou limitu historie. Dvě pomocná pole `active_document_key` a `active_slug` mají hodnotu pouze na současné revizi: díky unikátním indexům může být pro každý dokument a jazyk právě jedna současná revize a každá publikovaná URL může patřit nejvýše jednomu dokumentu. Změna současné revize v transakci vynuluje tato dvě pole na starém řádku, vloží nový řádek a případně smaže nejstarší neaktivní řádky daného dokumentu a jazyka.

`body` původně obsahoval prostý text. Nově může obsahovat JSON se značkou `simple-store-blocks-v1` a seřazenými bloky. `ContentBody` při čtení rozpozná obě podoby a nikdy nevkládá libovolné HTML z databáze do šablony. První uložení staré stránky v editoru vytvoří revizi s bloky, ale její starší textové revize zůstanou beze změny. Nový sloupec ani další vazby mezi tabulkami nejsou potřeba.

Produkty používají obdobnou tabulku `product_revisions`, protože jejich cena, kategorie, dostupnost a cesta k obrázku nejsou vlastnosti článků. Všechny údaje produktu jsou na jednom řádku a jeho změna vloží nový řádek; starší revize zůstávají k nahlédnutí do limitu historie. Sloupec `details_json` je snímek skupin výběru (název a seznam možností), technických údajů (název a hodnota), galerie a seřazených bloků obsahu. Kód čte staré `sizes` jako skupinu Velikost, pokud produkt dosud nemá `details_json`. Poškozený JSON se na veřejném webu zobrazí jako prázdné detaily místo chyby nebo nebezpečného obrázku. Tyto možnosti jsou **společné pro produkt s jednou cenou a dostupností**; systém zatím nespravuje samostatné skladové kusy pro kombinace. Katalog zobrazuje pouze skutečně publikované produkty, prázdný obchod zobrazí informaci o připravované nabídce.

## Úprava produktu v jeho náhledu

Administrace ukazuje seznam produktů a tlačítko pro založení neveřejného konceptu se čtyřmi ukázkovými bloky. Detail `/cs/produkt/slug?edit=1` může načíst i neveřejný produkt, ale jen pokud má návštěvník platné přihlášení správce. Běžná adresa produktu čte pouze publikovanou revizi. Neveřejný náhled má `noindex` a odpověď `Cache-Control: private, no-store`.

`assets/inline-editor.js` po opuštění textového pole odešle jednu změnu spolu s CSRF tokenem a očekávaným číslem revize do `admin.php`. Editor řadí požadavky za sebe, aby rychlé úpravy používaly správné číslo revize. `ProductInlineEditor` vezme aktuální kompletní revizi, změní jen povolené pole nebo jeden blok a připraví původní všechna ostatní data k uložení. `ProductRepository::saveRevision()` ověří obsah i původní číslo revize a vloží nový řádek v transakci. Obnova starší verze používá stejný zápis a vytváří další revizi. Při chybě se editor zastaví a zobrazí chybu, takže další zápisy nevycházejí ze zastaralého stavu.

Stránky a články používají stejný postup přes `assets/content-editor.js`, `src/Admin/inline-content.php` a `ContentInlineEditor`. `index.php` nabízí draft jen při platné relaci správce a parametru `?edit=1`. Běžná adresa načítá pouze publikované dokumenty. Jedno uložení mění jeden blok nebo jedno pole a `ContentRepository::saveRevision()` zapíše nový úplný řádek s kontrolou revize; historii lze obnovit stejnou cestou. Administrace zobrazuje seznam a dvě tlačítka pro založení neveřejného konceptu.

Základní cesty jsou `/`, `/cs`, `/cs/kategorie-produktu/spani/spacaky`, `/cs/produkt/nazev`, `/cs/blog`, `/cs/blog/nazev-clanku`, `/cs/o-nas`. Jazyk vybírá výhradně URL, nikoli cookie nebo session. Nyní je zapnutá jen čeština; další jazyk vyžaduje také přeložené texty rozhraní a řádky se stejnými cestami v `catalog_categories`. Chybějící překlad zobrazí 404, aby se potichu nepodstrčil obsah v jiném jazyce.

## Kategorie bez dalších vztahových tabulek

`catalog_categories` ukládá `language`, stabilní `path`, čitelný `title`, pořadí a příznak `enabled`. Cesta `obleceni/muzi/bundy` sama určuje rodiče `obleceni/muzi`, proto není potřeba `parent_id` ani spojování tabulek. `CategoryRepository::tree()` zahrne jen povolené větve, kterým existují všechny rodičovské cesty. Ruční přidání potomka znamená vložení jednoho řádku. Po změně `path` musíš opravit i cesty potomků a přiřazení produktu; proto je `path` trvalý identifikátor a pro změnu nápisu uprav pouze `title`.

Produktová revize nadále ukládá kořen do `category` a zbytek cesty do `subcategory`. Editor ukazuje jedno pole celé cesty a repository ji při ukládání rozdělí. Původní `spacaky`, `stany` a objemové kódy batohů jsou součástí podmínek SQL, bez zpětného přepsání revizí. Kořenová kategorie zahrnuje produkty všech podkategorií, podsekce pouze svůj podstrom. SQL filtruje publikované produkty podle jazyka, cesty a hledání; řazení a omezení počtu řádků probíhají ve stejném dotazu. Při velkém katalogu lze později doplnit index pro kategorii a hledání.

`MenuManager::links()` bere jméno místa z `config/menus.php`. `categories` vrací děti zadaného rodiče, `content` publikované stránky označené pro menu a případně Blog, `manual` ručně zapsaný strom odkazů. Každá položka má `label`, `href`, `active`, `children` a u kategorií také `path`. Hlavička vykreslí jen vrchní úroveň; vnořené položky může později zobrazit například rozbalovací menu bez zásahu do modelu. `category_tabs` ukazuje aktuální děti nebo sourozence listové kategorie a tím zpřístupňuje i hluboké větve.

## Co může zůstat bez databázových vazeb

Revize jednoho dokumentu či produktu jsou úplné snímky. Společný `document_key` / `product_key` a unikátní indexy určují identitu, jazyk, číslo a aktuální adresu. Zápis v transakci a kontrola očekávané revize zabraňují přepsání novější práce. Při vytváření překladu repository ověří, že původní identita už existuje. Není nutná další tabulka pro jednotlivé bloky ani cizí klíč na každý odstavec. Limit 50 revizí na identitu a jazyk nastavuje `config/site.php`; po úspěšném vložení se v téže transakci odstraní jen starší neaktivní snímky. Již existující dlouhé historie se zkrátí při příštím uložení daného obsahu.

Kategorie používají stabilní řetězcovou cestu a překlady mají stejné cesty. Repository před uložením produktu ověřuje, že zapnutá kategorie a její rodiče existují. Kdyby někdo ručně smazal kategorii SQL příkazem, neexistuje cizí klíč, který by změnu zastavil; navázané produkty bude třeba najít a přiřadit znovu. U objednávek, plateb a skladových pohybů už tato volnost nedává smysl: musí mít vlastní identitu, přesná pravidla konzistence a vazbu na produkt nebo jeho snímek v okamžiku nákupu.

Veřejný katalog čte 12 produktů a blog 6 článků v jedné dávce. SQL používá `LIMIT` a `OFFSET`; současně kontroluje jeden další řádek kvůli zobrazení odkazu na další dávku. Odkaz funguje i bez JavaScriptu, s JavaScriptem načte JSON s HTML kartami ze stejné URL a připojí je k seznamu. Pokud během procházení někdo mění publikovaný obsah, posun mezi dávkami může některou kartu zopakovat či přeskočit; při větším provozu lze přejít na kurzorové stránkování. `database/schema.sql` zůstává jediným aktuálním schématem; tento krok nepřidává tabulky.

## Hranice zabezpečení

Pro běžný hosting zůstává kořen projektu také kořenem webu. `.htaccess` na Apache blokuje `src/`, `config/`, `database/`, `view/`, `vendor/` a další neveřejné soubory ještě před pravidlem pro směrování. Na jiném serveru je potřeba odpovídající zákaz v jeho nastavení. Přihlášení administrátora používá silné náhodné heslo, PHP session a kontrolu CSRF tokenu u každého POST; editor přistupuje k databázi až po přihlášení. Administraci na veřejné doméně provozuj pouze přes HTTPS a s vypnutým ladicím výpisem chyb.
