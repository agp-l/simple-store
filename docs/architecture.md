# Návrh jádra Simple Store

## Současný stav

CMS řeší stránky, blog a produkty s revizemi. Pokladna nabízí české doručení na adresu a výdejní místa, bankovní převod, Comgate, GoPay a BTCPay. Správce i zákazníci mají roli v `shop_users`; heslo se ukládá pouze jako hash. Host může objednat bez účtu. Heslo lze obnovit e-mailem nebo správcovským CLI nástrojem. Stav platby, expedice, dokladu a externího přepravce tvoří různé osy; nelze je odvodit pouze z jediného statusu objednávky. [Audit a otevřené otázky](audit-2026-10.md) rozlišují ověřené funkce od těch, které potřebují rozhodnutí před ostrým provozem.

## Jak jde požadavek aplikací

1. Apache ponechá obrázky a CSS jako soubory. Ostatní URL předá do `index.php`.
2. `UrlManager` rozdělí cestu na úseky, určí jazyk a udrží správný prefix, pokud je projekt v podsložce.
3. `UrlManager::route()` určí obchod, kategorii, produkt, blog, stránku, košík, pokladnu, potvrzení objednávky nebo 404; `index.php` vybere odpovídající data a předá nákupní trasy `CheckoutController`.
4. `CategoryRepository` načte strom kategorií a `ContentRepository` aktuální publikovanou revizi. `MenuDefinitionRepository` spojí výchozí místa s případnými změnami v SQL; `MenuManager` z nich sestaví odkazy.
5. `PageRenderer` pošle data do schválených PHP pohledů ve `view/`. `view/shell.php` společně vykreslí head, hlavičku a patičku. Nepoužívá Twig ani databázi.

## Složky a názvy

| Cesta | Úkol |
| --- | --- |
| `src/Navigation/UrlManager.php` | Jazyk, tvar veřejné trasy a lokální odkazy. Zachovává název a metodu `getSegment()` ze starého CMS. |
| `src/Navigation/MenuManager.php` | Vytvoří pojmenované stromy odkazů pro libovolná místa šablony, žádné HTML uvnitř třídy. |
| `src/Category/CategoryRepository.php` | Jeden SQL zdroj pro kořeny, přímé děti, strom a drobečkovou navigaci. |
| `src/Category/CategoryPath.php` | Ověření cesty, její rodič, podstrom a převod starých kódů produktů při čtení. |
| `config/menus.php` | Přiřazuje zdroj a kořen kategorického stromu ke jménu každého menu. |
| `src/Navigation/MenuDefinitionRepository.php` | Načte nastavení menu pro jazyk z `shop_navigation_menus`; bez řádku použije výchozí soubor. |
| `src/Navigation/StorefrontMenus.php` | Připraví stejnou navigaci pro veřejný web, správu a zákaznický účet. |
| `src/Navigation/Slugger.php` | Navrhne adresu z českého nadpisu; ruční slug má přednost. |
| `src/Content/ContentRepository.php` | SQL dotazy, publikovaný obsah a ukládání revizí. |
| `src/Content/ContentBody.php` | Bloky textu, seznamu, tabulky a fotografie v jediném sloupci body; starý prostý text se načítá jako jeden blok. |
| `src/Content/ContentInlineEditor.php` | Převod jedné drobné úpravy na nový úplný snímek dokumentu. |
| `src/Content/SiteCopyRepository.php` | Krátké texty hlavičky a patičky po jazycích; bez SQL aktualizace použije výchozí znění. |
| `src/Rendering/PageRenderer.php` | Vybere schválený PHP pohled a předá mu data. |
| `src/Product/ProductRepository.php` | Publikovaný katalog, omezený správcovský výpis, revize a odstranění produktu v transakci. |
| `src/Product/ProductDetails.php` | Ověří a připraví volitelné výběry, technické údaje, galerii a bloky obsahu. |
| `src/Product/ProductInlineEditor.php` | Výchozí koncept a převod jedné malé úpravy na kompletní ověřený snímek. |
| `src/Media/` | Stálé cesty, validace uploadu, převod obrázků a připojení dávky k jedné revizi. |
| `src/Admin/media-api.php`, `src/Admin/media.php` | Přihlášená knihovna fotografií pro konkrétní obsah podle jeho trvalého klíče. |
| `src/Media/MediaAttachment.php` | Výběr umístění snímků, sestavení jedné revize dávky a zjištění jejich použití. |
| `src/Checkout/CartSession.php`, `src/Checkout/CartService.php` | Samostatná anonymní session košíku a ověření aktuálních produktů i cen na serveru. |
| `src/Checkout/CheckoutController.php`, `OrderReceiptController.php`, `PickupSelection.php`, `view/checkout/` | Pokladna, ověření výdejních míst a potvrzení objednávky s opakováním online platby. |
| `src/Checkout/OrderRepository.php` | Transakční uložení objednávky, omezený správcovský výpis a ruční potvrzení platby. |
| `src/Checkout/BankTransferPayment.php`, `src/Checkout/ShippingPolicy.php` | Ověření českého účtu a cen dopravy, příprava platebních údajů. |
| `src/Checkout/*PaymentService.php`, `src/Checkout/*ApiClient.php` | Platební pokusy a ověřování stavu vzdálených bran; návrat prohlížeče sám platbu nepotvrzuje. |
| `src/Product/ProductStockRepository.php` | Rezervace, uvolnění a spotřebování volných kusů podle zdroje expedice. |
| `config/checkout.php` | Místní banka, doprava a obchodní podmínky; vychází z `config/checkout.example.php` a není ve verzovacím systému. |
| `src/Admin/orders.php`, `view/admin/orders.php` | Přehled přijatých objednávek a ruční kontrola přijatého bankovního převodu. |
| `src/Admin/delete-product.php` | Tenký POST vstup do kontroly revize a transakčního odstranění produktu. |
| `src/Admin/inline-product.php` | Přijme ověřený požadavek z náhledu, uloží revizi a vrátí její číslo. |
| `config/site.php` | Výchozí a podporované jazyky. |
| `config/database.php` | Místní údaje k DB, není ve verzovacím systému. |
| `src/Admin/AdminUserRepository.php` | Čte účet správce z `shop_users`, zakládá ho a mění hash hesla. |
| `tools/admin.php` | První účet, import starého souboru a reset hesla. |
| `admin.php`, `view/admin/` | Správcovské akce a jejich obsahový panel. |
| `account.php`, `src/Customer/`, `view/account/` | Registrace, zákaznická role, profil, adresy a čtení objednávek. |
| `src/Auth/RoleAuth.php` | Společná kontrola session a CSRF, s oddělenou cookie pro každou roli. |
| `view/shell.php`, `view/panel/`, `assets/panel.css` | Jedno záhlaví a patička; jedna postranní navigace a styly obou soukromých částí. |
| `database/schema.sql` | Jediný aktuální soubor pro vytvoření celé databáze. |
| `src/Database/SchemaUpdater.php` | Administrátorem spouštěná opakovatelná aktualizace schématu v připojené databázi; ukládá otisk a průběh v `shop_schema_updates`. |
| `src/Database/CommerceTestReset.php`, `tools/reset-test-commerce.php` | Výhradně ruční reset testovacích obchodních dat; není součástí aktualizace schématu. |
| `view/` | HTML a malé výpisy proměnných; současná grafika obchodu. |

## Jedna šablona, dvě soukromé části

`view/shell.php` vkládá jediný `<head>`, hlavičku, zvolený obsah, patičku a společné skripty. Veřejný `PageRenderer` předává svůj pohled přes `view/layout.php`. `view/admin/layout.php` a `view/account/layout.php` nastavují jen titulek, text v hero a obsah pro `view/panel/layout.php`. Obě soukromé části používají `view/panel/sidebar.php`; odkazy a aktivní položky mu dodávají samostatně. `view/head.php` načítá postupně `style.css` (základ a katalog), `assets/product-detail.css`, `assets/inline-edit.css`, `assets/content.css`, případně `assets/checkout.css`, `assets/media.css`, `assets/panel.css` a `assets/panel-orders.css`. Pořadí je důležité pro CSS kaskádu; styly panelů jsou pod `.panel-area`. Nadpis a podnadpis veřejné hlavičky, úvody katalogu a texty patičky se ukládají v `shop_site_copy`; nadpisy soukromých panelů zůstávají pracovními popisky. Menu spravuje `shop_navigation_menus`.

Zákaznický účet je na `account.php`, administrativa na `admin.php`. Oba používají `RoleAuth`, ale jiné názvy cookie a repository, která vracejí pouze správnou roli. Případná klientská session tedy nikdy nepovolí správcovský zápis. Registrační a editační POST požadavky ověřují CSRF; dotazy na adresy a historii objednávek účtu vždy filtrují `user_id`. `shop_users` obsahuje e-mail, jméno a telefon zákazníka; více adres je v `shop_customer_addresses`. Host může objednat s `user_id=NULL` a zadaným kontaktním e-mailem; jeho objednávka se v historii cizího účtu neobjeví.

Pokladna nabízí bankovní převod a po konfiguraci také Comgate, GoPay a BTCPay Server. Obchod nepřijímá čísla karet ani bezpečnostní kódy. Každá online brána má vlastní tabulku trvalých pokusů, přístupové údaje a návratový endpoint. `payment_method` určuje bránu u objednávky, `provider_reference` její vzdálené ID. Návrat zákazníka a příchozí notifikace samy platbu nepotvrzují: server ověřuje vzdálený stav autentizovaným požadavkem a porovnává objednávku, částku i měnu. GoPay používá oficiální PHP SDK; podrobnosti instalace jsou v [gopay.md](gopay.md), Comgate v [comgate.md](comgate.md).

## Košík a objednávka

`config/checkout.example.php` obsahuje výchozí dopravu a vypnuté online brány. `CheckoutSettingsRepository` načítá a ukládá ceny dopravy, bankovní údaje, odkaz na obchodní podmínky i klíče bran v databázi přes správu obchodu. `BankTransferPayment` ověřuje české bankovní údaje, `ShippingPolicy` ověřuje metody a ceny. Pokladna podporuje doručení na adresu i vybraná výdejní místa v ČR. Objednávka vyžaduje dostupnou dopravu, alespoň jednu nastavenou platební metodu, obchodní podmínky a aktuální SQL schéma.

Bankovní převod má vlastní přepínač. `FioStatementClient` čte přes TLS výpis za pevné období, `FioTransferMatcher` přesně páruje bankovní účet, měnu, celou částku a VS a `FioBankReconciler` atomicky potvrzuje čekající převod. Tabulky `shop_fio_requests` a `shop_fio_matches` řeší společný limit volání a jednorázové přiřazení pohybu. Admin tlačítko a CLI `tools/fio-worker.php` používají stejnou službu. Účet uložený v objednávce se musí shodovat s účtem potvrzeným ve výpisu; změna globálních nastavení staré objednávky nepřepíše. Comgate, GoPay a BTCPay mají oddělené poskytovatele a Fio se jejich stavů nedotýká.

Košík na `/cs/kosik` používá oddělenou krátkodobou PHP session a CSRF token; drží jen identitu produktu, počet kusů a zvolené možnosti. `CartService` při každém čtení ověří současnou publikovanou revizi, dostupnost, varianty a cenu. Neplatná položka zůstane viditelná k odebrání, ale zablokuje odeslání celé objednávky. `CheckoutController` vede zákazníka přes `/cs/pokladna`: kontakt a doručení, výběr platby, závěrečná kontrola a souhlas s obchodními podmínkami. `PickupSelection` ověřuje výdejní místa při uložení dopravy a znovu před založením objednávky. `OrderReceiptController` zpracovává soukromý odkaz na potvrzení, opakování online platby, návrat z brány a náhled faktury. Ceny a dopravné se berou pouze ze serveru. JavaScript vykresluje QR kód z řetězce SPAYD vytvořeného v PHP; údaje pro převod zůstávají čitelné i bez JavaScriptu.

`OrderRepository::create()` v jedné transakci vloží do `shop_orders` snímek názvů, cen, možností, dopravy, kontaktních a bankovních údajů. Změna produktu nebo účtu pak nemění již založenou objednávku. Náhodný `idempotency_key` z košíku a unikátní index zamezují dvojímu založení při opakovaném POST včetně souběžných požadavků. Objednávka dostane trvalé číslo, náhodný `order_token`, unikátní variabilní symbol a počáteční stav platby `pending`; produktové revize nejsou její jediný zdroj historických cen. `database/schema.sql` obsahuje opakovatelnou migraci starších řádků, které nedostanou vymyšlené bankovní údaje.

Stránka `/cs/objednavka/<token>` funguje i bez účtu jako soukromý odkaz; odkaz dorazí také e-mailem. Token je přístupový údaj k objednávce, proto odpověď používá `no-store`, `noindex` a pravidla pro referrer. Přihlášený zákazník vidí vlastní objednávky v účtu. `admin.php?section=orders` nabízí přehled a detail. Správce potvrzuje bankovní převod podle výpisu; Comgate, GoPay a BTCPay se aktualizují autentizovaným dotazem na příslušného poskytovatele. Potvrzené online platby lze dále fakturovat a expedovat stejným postupem jako převod.

## Jedna tabulka pro stránky, články a historii

Jeden `document_key` je trvalá identita stránky nebo článku. `language` je jazyk konkrétního textu; překlady stejného obsahu sdílejí `document_key`, ale každý mají vlastní revize a URL. Každé uložení přidá **nový řádek** do `shop_content_revisions`. Původní text, titulek, slug, stav i nastavení menu zůstanou na starém řádku, dokud nedosáhnou limitu historie. Dvě pomocná pole `active_document_key` a `active_slug` mají hodnotu pouze na současné revizi: díky unikátním indexům může být pro každý dokument a jazyk právě jedna současná revize a každá publikovaná URL může patřit nejvýše jednomu dokumentu. Změna současné revize v transakci vynuluje tato dvě pole na starém řádku, vloží nový řádek a případně smaže nejstarší neaktivní řádky daného dokumentu a jazyka.

`body` původně obsahoval prostý text. Nově může obsahovat JSON se značkou `simple-store-blocks-v1` a seřazenými bloky. `ContentBody` při čtení rozpozná obě podoby a nikdy nevkládá libovolné HTML z databáze do šablony. První uložení staré stránky v editoru vytvoří revizi s bloky, ale její starší textové revize zůstanou beze změny. Nový sloupec ani další vazby mezi tabulkami nejsou potřeba.

Produkty používají obdobnou tabulku `shop_product_revisions`, protože jejich cena, kategorie, dostupnost a cesta k obrázku nejsou vlastnosti článků. Všechny údaje produktu jsou na jednom řádku a jeho změna vloží nový řádek; starší revize zůstávají k nahlédnutí do limitu historie. Sloupec `details_json` je snímek skupin výběru (název a seznam možností), technických údajů (název a hodnota), galerie a seřazených bloků obsahu. Kód čte staré `sizes` jako skupinu Velikost, pokud produkt dosud nemá `details_json`. Poškozený JSON se na veřejném webu zobrazí jako prázdné detaily místo chyby nebo nebezpečného obrázku. Tyto možnosti jsou **společné pro produkt s jednou cenou a dostupností**; systém zatím nespravuje samostatné skladové kusy pro kombinace. Katalog zobrazuje pouze skutečně publikované produkty, prázdný obchod zobrazí informaci o připravované nabídce.

## Úprava produktu v jeho náhledu

Správce zakládá neveřejný koncept tlačítkem přímo na webu. Katalog `?manage=1` používá stejné produktové karty jako návštěvník, ale přidává koncepty, stav, hledání a filtry; dotaz je omezený na jednu dávku a nepřihlášenému vrací 404. Detail `/cs/produkt/slug?edit=1` může načíst i neveřejný produkt, ale jen při platném přihlášení správce. Běžná adresa produktu čte pouze publikovanou revizi. Neveřejný náhled má `noindex` a odpověď `Cache-Control: private, no-store`. Odstranění produktu vyžaduje aktuální číslo revize; v jedné transakci smaže revize daného klíče a jazyka, mediální soubory ponechá kvůli starým odkazům.

`assets/inline-editor.js` po opuštění textového pole odešle jednu změnu spolu s CSRF tokenem a očekávaným číslem revize do `admin.php`. Editor řadí požadavky za sebe, aby rychlé úpravy používaly správné číslo revize. `ProductInlineEditor` vezme aktuální kompletní revizi, změní jen povolené pole nebo jeden blok a připraví původní všechna ostatní data k uložení. `ProductRepository::saveRevision()` ověří obsah i původní číslo revize a vloží nový řádek v transakci. Obnova starší verze používá stejný zápis a vytváří další revizi. Při chybě se editor zastaví a zobrazí chybu, takže další zápisy nevycházejí ze zastaralého stavu.

Stránky a články používají stejný postup přes `assets/content-editor.js`, `src/Admin/inline-content.php` a `ContentInlineEditor`. `index.php` nabízí koncept jen při platné relaci správce a parametru `?edit=1`. Běžná adresa načítá pouze publikované dokumenty. Jedno uložení mění jeden blok nebo jedno pole a `ContentRepository::saveRevision()` zapíše nový úplný řádek s kontrolou revize; historii lze obnovit stejnou cestou. Správce zakládá obsah v liště webu, u článků jej upravuje v blogu. `admin.php?section=contents` zůstává omezeným vyhledávacím rejstříkem pro koncepty, skryté stránky a překlady, nikoli druhou verzí editoru.

Obrazové soubory jsou mimo SQL. `MediaLibrary` přijme jen skutečný PHP upload JPG/PNG a volitelně WebP, pokud ho GD umí číst; zkontroluje typ dat a obraz znovu zakóduje. Původní soubor se veřejně neukládá; vzniknou tři neměnné soubory WebP, pokud ho GD umí zapisovat, jinak JPG nebo PNG podle vstupu (PNG zachová průhlednost). Všechny varianty sdílejí příponu; již vytvořené URL se při změně podpory serveru nemění. Souborová cesta používá typ obsahu a jeho stabilní klíč, nikoli slug nebo kategorii. Knihovna se otevírá z konkrétního obsahu přes jeho klíč a aktuální kategorii ukazuje pouze jako popisek. Překlady se stejným klíčem mohou obrázek znovu vybrat z téže knihovny. `MediaAttachment` připraví jeden kompletní snímek pro celou dávku podle zvoleného umístění; repository ho uloží s kontrolou očekávané revize a při neúspěchu se soubory dávky odstraní. Knihovna čte odkazy v aktuální revizi, aby označila hlavní fotografii, galerii a obrazové bloky. Soubory ze starších revizí se automaticky neodstraňují, protože jejich URL mohou být použity i mimo databázi. Pro trvalost obsahu zálohuj SQL i `images/media/`.

Základní cesty jsou `/`, `/cs`, `/cs/kategorie-produktu/spani/spacaky`, `/cs/produkt/nazev`, `/cs/blog`, `/cs/blog/nazev-clanku`, `/cs/o-nas`. Jazyk vybírá výhradně URL, nikoli cookie nebo session. Nyní je zapnutá jen čeština; další jazyk vyžaduje také přeložené texty rozhraní a řádky se stejnými cestami v `shop_catalog_categories`. Chybějící překlad zobrazí 404, aby se potichu nepodstrčil obsah v jiném jazyce.

## Kategorie bez dalších vztahových tabulek

`shop_catalog_categories` ukládá `language`, stabilní `path`, čitelný `title`, pořadí a příznak `enabled`. Cesta `obleceni/muzi/bundy` sama určuje rodiče `obleceni/muzi`, proto není potřeba `parent_id` ani spojování tabulek. `CategoryRepository::tree()` zahrne jen povolené větve, kterým existují všechny rodičovské cesty. Administrace umožní přidat potomka nebo upravit název, pořadí a viditelnost bez zásahu do SQL. `path` zůstává neměnným identifikátorem, protože jej používají produkty, další větve a odkazy.

Produktová revize nadále ukládá kořen do `category` a zbytek cesty do `subcategory`. Editor ukazuje jedno pole celé cesty a repository ji při ukládání rozdělí. Původní `spacaky`, `stany` a objemové kódy batohů jsou součástí podmínek SQL, bez zpětného přepsání revizí. Kořenová kategorie zahrnuje produkty všech podkategorií, podsekce pouze svůj podstrom. SQL filtruje publikované produkty podle jazyka, cesty a hledání; řazení a omezení počtu řádků probíhají ve stejném dotazu. Při velkém katalogu lze později doplnit index pro kategorii a hledání.

`MenuManager::links()` stále bere jméno místa. Výchozí zdroje určuje `config/menus.php`; tabulka `shop_navigation_menus` může přepsat zdroj pro konkrétní jazyk a místo. Jeden řádek obsahuje nastavení a JSON seznam vlastních položek s identifikátorem a identifikátorem rodiče. Není tu cizí klíč ani další tabulka pro jednotlivé odkazy. Repository ověřuje cíle, rodiče a cykly, potom skládá strom pro existující `MenuManager`. Zdroj `categories` vrací děti dané větve, `content` publikované stránky v pořadí `menu_order` a případně Blog, `manual` vlastní odkazy. Vlastní položky se připojí také za automatickými odkazy, takže lze vložit externí adresu bez přepnutí zdroje. Ověřené HTTPS adresy mají v odkazu příznak pro `target="_blank"` s `rel="noopener noreferrer"`; e-mailové odkazy otevírají poštovní aplikaci. Hlavička a horní odkazy vnořené vlastní položky otevírají rozbalovacím prvkem; patička je vypisuje pod rodičem. `category_tabs` pracuje s aktuální kategorií a ukazuje její děti nebo sourozence.

## Co může zůstat bez databázových vazeb

Revize jednoho dokumentu či produktu jsou úplné snímky. Společný `document_key` / `product_key` a unikátní indexy určují identitu, jazyk, číslo a aktuální adresu. Zápis v transakci a kontrola očekávané revize zabraňují přepsání novější práce. Při vytváření překladu repository ověří, že původní identita už existuje. Není nutná další tabulka pro jednotlivé bloky ani cizí klíč na každý odstavec. Limit 50 revizí na identitu a jazyk nastavuje `config/site.php`; po úspěšném vložení se v téže transakci odstraní jen starší neaktivní snímky. Již existující dlouhé historie se zkrátí při příštím uložení daného obsahu.

Kategorie používají stabilní řetězcovou cestu a překlady mají stejné cesty. Repository před uložením produktu ověřuje, že zapnutá kategorie a její rodiče existují. Kdyby někdo ručně smazal kategorii SQL příkazem, neexistuje cizí klíč, který by změnu zastavil; navázané produkty bude třeba najít a přiřadit znovu. Objednávka proto ukládá vlastní úplný snímek zakoupených položek a částek; její historický obsah není závislý na budoucích úpravách či odstranění produktu. Volné kusy skladu jsou v `shop_product_inventory`; objednávky vytvářejí rezervace v `shop_order_stock_reservations`. Přepnutí na externího dodavatele rezervaci uvolní a návrat k vlastní expedici ji musí znovu získat v transakci. Samostatný peněžní deník, faktury a trvalé pokusy o platbu mají jiné účely než inventář a nelze je zaměňovat.

Veřejný katalog čte 12 produktů a blog 6 článků v jedné dávce. SQL používá `LIMIT` a `OFFSET`; současně kontroluje jeden další řádek kvůli zobrazení odkazu na další dávku. Odkaz funguje i bez JavaScriptu, s JavaScriptem načte JSON s HTML kartami ze stejné URL a připojí je k seznamu. Pokud během procházení někdo mění publikovaný obsah, posun mezi dávkami může některou kartu zopakovat či přeskočit; při větším provozu lze přejít na kurzorové stránkování. `database/schema.sql` zůstává jediným aktuálním schématem.

## Hranice zabezpečení

Pro běžný hosting zůstává kořen projektu také kořenem webu. `.htaccess` na Apache blokuje `src/`, `config/`, `database/`, `view/`, `vendor/` a další neveřejné soubory ještě před pravidlem pro směrování. Na jiném serveru je potřeba odpovídající zákaz v jeho nastavení. Přihlášení administrátora používá silné náhodné heslo, PHP session a kontrolu CSRF tokenu u každého POST; editor přistupuje k databázi až po přihlášení. Košík má vlastní session a CSRF token i pro hosta. Soukromá stránka objednávky se otevírá pouze znalostí náhodného tokenu; odpověď zakazuje ukládání do cache a indexování. Administraci a pokladnu na veřejné doméně provozuj pouze přes HTTPS a s vypnutým ladicím výpisem chyb.
