# Dobrodruzi — jednoduchý obchod a CMS

Jednoduchý obchod, redakční systém a zákaznický účet v PHP 8 a MySQL. Obchod, administrace i účet používají stejnou hlavičku a patičku z `view/`. Architektura a důvody jednotlivých rozhodnutí jsou popsané v [docs/architecture.md](docs/architecture.md).

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

Administrace je na `http://localhost/simple-store/admin.php`. Účty jsou v tabulce `users`; ukládá se pouze hash hesla. Pro aktualizaci stávající instalace znovu importuj aktuální `database/schema.sql` do stejné databáze, kterou uvádí `config/database.php` (v phpMyAdmin nebo přes LAMPP):

```bash
cd /opt/lampp/htdocs/simple-store
git pull
/opt/lampp/bin/mysql -u root -p < database/schema.sql
```

Pokud má root účet bez hesla, vynech `-p`; pokud příkaz `/opt/lampp/bin/mysql` neexistuje, importuj soubor v phpMyAdmin. **Zapomenuté heslo nelze přečíst zpět.** Jen pokud chceš změnit heslo správce, spusť `/opt/lampp/bin/php tools/admin.php --reset`: vytvoří účet `admin`, pokud ještě není v databázi, nebo mu nastaví nové náhodné heslo. Heslo se zobrazí jednou v terminálu a předchozí přihlášené relace přestanou fungovat. Při prvním nasazení funguje také `/opt/lampp/bin/php tools/admin.php`.

Pokud znáš původní heslo a chceš ho zachovat, místo `--reset` spusť jednorázově `/opt/lampp/bin/php tools/admin.php --migrate`. Převezme původní jméno a hash z `config/admin.php`, pokud ještě v databázi žádný správce není. Starý soubor pak web už nepoužívá; po ověření přihlášení jej můžeš smazat. Databázové přihlašovací údaje zůstávají v `config/database.php`. Administrace obsahuje seznam dokumentů a jejich přímou úpravu. Přihlášení chrání PHP session, zápisy ověřuje CSRF token. Před zveřejněním webu vypni režim `debug`.

Po aktualizaci můžeš bezpečně znovu importovat aktuální `database/schema.sql`; import existující obsah ani heslo správce nemaže. Stránky a články žádné další tabulky nepotřebují. Rychlé kontroly: `/opt/lampp/bin/php tests/admin-auth.php`, `/opt/lampp/bin/php tests/database-connection.php` a `/opt/lampp/bin/php tests/url-manager.php`.

### Společný vzhled a zákaznický účet

Hlavička, hlavní kategorie, horní odkazy, patička a košík se vykreslují v jediné šabloně `view/shell.php`. `view/head.php`, `view/header.php`, `view/menu.php` a `view/footer.php` jsou stejné pro obchod, administraci i zákaznickou zónu. Administrace má vlastní pracovní menu v levém panelu pod hlavičkou; na mobilu je panel nad obsahem. Zákazník na stejném místě uvidí jiné menu. Oba panely sdílejí `view/panel/layout.php`, `view/panel/sidebar.php` a `assets/panel.css`. Navigaci obchodu připravuje jedna třída `StorefrontMenus`, aby se změna menu projevila ve všech třech částech.

Odkaz **Můj účet** v hlavičce vede na `account.php`. Registrace používá e-mail, jméno a heslo alespoň o 12 znacích; v `config/site.php` ji lze vypnout volbou `customer_registration`. Zákazník se přihlašuje odděleně od správce. Může změnit jméno, telefon a heslo a přidávat, upravovat či mazat své adresy. Sekce **Objednávky** čte jen objednávky navázané na jeho účet; objednávka hosta zde není. V sekci **Platby** je popsaný aktuální bankovní převod. Čísla platebních karet ani CVV se nezadávají a neukládají. E-mail účtu zatím nelze měnit bez ověření nové adresy a automatický reset zákaznického hesla e-mailem ještě není připraven.

Aktuální `database/schema.sql` doplní do `users` zákaznický e-mail, jméno a telefon, vytvoří `customer_addresses` a rozšíří `shop_orders` o údaje skutečné objednávky a platby. Import je opakovatelný. Po stažení změn jej spusť **před použitím pokladny**. Pokud je databáze v `config/database.php` pojmenována jinak než `simple_store`, uprav první `CREATE DATABASE` a `USE` v kopii SQL. Zákaznické akce kontrolují roli, PHP session a CSRF token; každý dotaz na adresy a historii objednávek účtu je omezen ID přihlášeného zákazníka. Bez MySQL můžeš spustit `/opt/lampp/bin/php tests/customer-account.php` a `/opt/lampp/bin/php tests/account-render.php`.

### Košík, objednávka a bankovní převod

Košík na `/cs/kosik` ukládá vybrané produkty a množství do samostatné PHP session. Pokladna na `/cs/pokladna` kontroluje aktuálně publikované produkty a jejich ceny v databázi; cenu ani dopravu nepřebírá z prohlížeče. Zákazník může objednat i bez registrace. Zadá jméno, e-mail, telefon a doručovací adresu, zkontroluje souhrn a potvrdí obchodní podmínky. Dostupné je zatím jen **doručení na adresu v ČR** a **platba převodem v Kč**. Samotné uvedení metody `pickup` v konfiguraci ještě nezapíná výběr výdejního místa.

Před přijímáním objednávek znovu importuj `database/schema.sql` a založ místní nastavení:

```bash
cp config/checkout.example.php config/checkout.php
```

V `config/checkout.php` vyplň `terms_url` jako místní cestu k publikované stránce obchodních podmínek, například `/simple-store/cs/obchodni-podminky` při provozu v podsložce nebo `/cs/obchodni-podminky` v kořeni domény. V `bank_transfer` nastav skutečný český IBAN, odpovídající tuzemské číslo účtu `account_display` ve tvaru `číslo/kód` nebo `předčíslí-číslo/kód`, jméno příjemce a případně splatnost `payment_due_days` (1–60 dní). Aplikace ověří kontrolní číslice IBANu i shodu s tuzemským číslem účtu. V `shipping_methods['home']` nastav název dopravy, celou cenu v Kč v `price_czk` a `requires_address => true`. Ceny v příkladu jsou pouze zakomentované vzory; dokud nevyplníš platný účet, dopravu a podmínky, objednávku nelze odeslat. `config/checkout.php` je ignorovaný Gitem a nepatří na GitHub.

Po potvrzení vznikne řádek v `shop_orders` s kopií položek, cen, dopravy a bankovních údajů platných při objednání. Stránka `/cs/objednavka/<token>` ukáže číslo účtu, IBAN, částku a jedinečný variabilní symbol. QR platba se vytvoří v prohlížeči z údajů připravených PHP; údaje pro ruční převod jsou k dispozici i bez JavaScriptu. **Objednávka hosta nemá přihlašovací účet:** odkaz s tokenem si musí zákazník uložit. Potvrzení se zatím neposílá e-mailem. S odkazem zacházej jako se soukromým údajem, protože umožňuje zobrazit platební údaje objednávky. Přihlášený zákazník najde své objednávky také v účtu.

V `admin.php?section=orders` správce vidí přijaté objednávky. Po kontrole **částky a variabilního symbolu na bankovním výpisu** ručně označí převod jako přijatý; stav se z banky nenačítá automaticky. Objednávka zatím nerezervuje skladové kusy ani nevytváří zásilku u dopravce. Sloupce `payment_method` a `provider_reference` připravují záznam pro další platební metody, například Comgate nebo BTCPay Server. Žádná z nich zatím není napojená. Náhodný klíč v session a unikátní `idempotency_key` brání dvojímu vytvoření téže objednávky při opakovaném odeslání. Ověřování webhooků a jejich idempotentní zpracování bude potřeba navrhnout až při integraci konkrétního poskytovatele.

Kontroly bez databáze: `/opt/lampp/bin/php tests/checkout-cart.php`, `/opt/lampp/bin/php tests/checkout-order.php` a `node --test tests/payment-qr.mjs`. Před ostrým provozem projdi celý nákup na své XAMPP instalaci včetně objednávky hosta, obnovení stránky s platbou a ručního potvrzení v administraci.

### Stránky a články: úpravy přímo na stránce

Po přihlášení klikni v liště přímo na webu na **＋ Stránka** nebo **＋ Článek**. Vznikne neveřejný koncept s ukázkovým úvodem, odstavcem, seznamem, tabulkou a fotografií. Kliknutím do textu jej přepíšeš, po opuštění pole se automaticky uloží a stránka se obnoví. Mezi bloky přidáš další text, seznam, tabulku či fotografii; blok lze přesunout, přepnout jeho typ nebo odebrat. Tlačítkem **Fotografie** otevřeš knihovnu, kde lze nahrát více souborů najednou; vloží se jako obrazové bloky na zvolené místo. Lze také vybrat již nahraný snímek nebo zadat cestu `images/nazev.webp` či HTTPS adresu. V textu funguje `**tučné**` a `[odkaz](https://example.org)`, řádky tabulky odděluje `|`.

Při první změně předvyplněného nadpisu se vytvoří i adresa (slug) z názvu; pozdější změny nadpisu adresu nepřepisují. Slug můžeš upravit ručně nahoře. Stránce lze nastavit zobrazení v horních odkazech a pořadí v menu; článek po publikování patří na `/cs/blog`. **Publikovat** zobrazí obsah návštěvníkům. Otevření publikované stránky přihlášeným správcem nabídne odkaz **Upravit přímo na stránce**; neveřejný koncept se načte pouze přihlášenému přes `?edit=1`. Každé uložení vytvoří nový řádek v `content_revisions`. Rozbalená historie umožní vrátit některou ze zachovaných verzí jako další revizi.

Existující stránky psané prostým textem zůstávají čitelné a při prvním přímém uložení se převedou na jeden textový blok. Staré revize se nepřepisují. **Žádné nové SQL není potřeba:** pokud už máš tabulku `content_revisions`, stačí `git pull`. Kontrolní test bez databáze: `/opt/lampp/bin/php tests/content-editor.php`.

Na `/cs/blog` má správce odkaz pro přidání článku a u každého publikovaného článku odkaz na úpravu. `?manage=1` zobrazí také rozepsané články. Stránky, které nejsou v menu, a další koncepty najdeš přes vyhledávání, filtry a stránkování v `admin.php?section=contents`; jejich editace otevře skutečnou stránku. Pokud v `config/site.php` později povolíš další jazyky, u dokumentu v tomto přehledu se objeví **Nový překlad**. Vytvoří samostatný neveřejný koncept pod stejným `document_key`.

## Produkty: úpravy přímo na stránce

Po přihlášení použij v liště webu **＋ Produkt**. Vznikne neveřejný koncept a ihned se otevře jeho stránka s ukázkovým obsahem: název, úvod, fotografie, varianty, technické údaje, odstavec, seznam, tabulka a fotografický blok. Před zveřejněním nahraď ukázkové údaje svými. V `?manage=1` na katalogu můžeš vyhledávat a filtrovat publikované i skryté produkty včetně konceptů; detail otevřeš rovnou k úpravě. Odkaz **Produkty** v administraci sem také vede. Běžný katalog stále zobrazuje jen publikované produkty.

Úpravy probíhají v náhledu detailu na `/cs/produkt/slug?edit=1`. Klikni přímo na nadpis, cenu, popis, nadpisy bloků, seznam, tabulku nebo technické údaje a přepiš je. Po odchodu z textu se změna sama uloží a stránka se obnoví s výsledným vzhledem. Klávesa Escape během psaní vrátí původní obsah. Pokud se uložení nezdaří, zobrazí se chyba u horní lišty; stránku obnov až po přečtení chyby. Každé úspěšné uložení vytvoří **novou kompletní revizi** v `product_revisions`; nejstarší revize nad nastavený limit se automaticky smaže.

Malými tlačítky mezi bloky vložíš text, seznam, tabulku nebo fotografii; bloky můžeš přesouvat, měnit jejich typ a odebírat. V detailu lze stejným způsobem přidat nebo odebrat variantu, technický parametr či fotografii v galerii. U položky **Upravit možnosti** napiš každou barvu, velikost nebo jinou možnost na nový řádek. Kategorie a dostupnost se vybírají v horní liště, adresu (slug) upravíš kliknutím. Při změně slugu se změní i adresa produktu; název se mění samostatně a slug sám nepřepisuje. Předchozí podobu lze načíst z rozbalené **Historie úprav** jako další novou revizi.

Fotografii změníš malým tlačítkem u obrázku. V knihovně můžeš vybrat již nahraný snímek, hromadně nahrát nové fotografie nebo vložit HTTPS adresu či starší cestu `images/nazev.webp`. Umístění záleží na tlačítku, kterým knihovnu otevřeš: hlavní snímek, přidání nebo nahrazení v galerii, případně blok popisu. Při nahrazení hlavní fotografie se původní přesune do galerie; první fotografie úplně nového produktu se stane hlavní i při přidání do galerie. Náhledy mají pevnou velikost a při větším počtu se posouvají vodorovně. Kliknutím na hlavní fotografii ji otevřeš přes celou obrazovku; zavřeš ji tlačítkem, kliknutím mimo fotografii nebo klávesou Escape. V textových blocích lze psát `**tučné**` a `[název odkazu](https://example.org)`; po uložení se zobrazí výsledné formátování. Typ bloku **Tabulka** používá na každém řádku zápis `Název | Hodnota`. Produktové varianty mají zatím společnou cenu a dostupnost; vybrané možnosti se ukládají do položky objednávky.

V detailu při úpravě je přímý odkaz na fotografie produktu a volba **Odstranit produkt**. Odstranění vyžaduje zaškrtnuté potvrzení a správnou aktuální revizi; smaže všechny jeho revize v daném jazyce. Nahrané soubory zůstanou na disku, aby se nerozbily starší odkazy. Již vytvořené objednávky mají vlastní kopii názvu, ceny a vybraných možností produktu.

### Správce fotografií

Fotografie otevři přímo v detailu produktu, stránky nebo článku; nemusíš hledat položku v dlouhém seznamu. Každý obsah má vlastní složku `images/media/products/<product_key>/`, `images/media/pages/<document_key>/` nebo `images/media/posts/<document_key>/`. Název a kategorie se mohou měnit, klíč zůstává stejný: staré odkazy a revize se proto nemusí přesouvat. Překlady stejné stránky či článku sdílejí složku a knihovnu, bloky však zůstávají oddělené podle jazyka. Knihovna označí hlavní snímek, snímky v galerii či blocích a soubory nepoužité v aktuální revizi; hlavní přiřazení se ukládá do produktu, nikoli do složky.

Nahrávej JPG a PNG; WebP můžeš nahrát, pokud ho serverové PHP umí číst. Jeden soubor může mít nejvýše 12 MB a 20 Mpx, jedna dávka nejvýše 12 souborů. Server vytvoří velikosti s delší stranou nejvýše **1800 px** pro detail a zvětšení, **960 px** pro kartu nebo blok a **240 px** pro miniaturu. Menší fotografie se nezvětšují. Pokud GD umí ukládat WebP, všechny výstupy jsou WebP (kvalita 86); jinak je výstupem pro JPG kvalitní JPEG a pro PNG průhlednost zachovávající PNG. Výběr více souborů vytvoří **jednu** novou revizi; při chybě jejího uložení se soubory z právě této dávky odstraní. Knihovna nabízí kopírování všech tří veřejných URL. Ručně zadaná HTTPS adresa zůstane externí; server ji nestahuje ani nepřevádí. Starší obrázky uložené přímo v `images/` dál fungují.

Pro nahrávání zapni v PHP rozšíření **GD** s podporou čtení JPG a PNG a `fileinfo`; podpora WebP není povinná. V XAMPP ověř aktivní PHP příkazem `/opt/lampp/bin/php -r 'var_dump(extension_loaded("gd"), extension_loaded("fileinfo"), function_exists("imagecreatefromjpeg"), function_exists("imagecreatefrompng"), function_exists("imagewebp"));'`; web a CLI musejí používat odpovídající PHP. Pokud je `imagewebp` `false`, není potřeba měnit `php.ini`: obrázky se uloží jako JPG/PNG. Zkontroluj zapisovatelnost složky `images/` pro Apache a v `php.ini` nastav `upload_max_filesize` alespoň `12M`, `post_max_size` větší než součet plánované dávky (např. `150M` pro více souborů) a pro velké snímky `memory_limit` alespoň `256M`. Na Webglobe ověř ve zvoleném tarifu GD, `fileinfo`, limity PHP a oprávnění zápisu; podpora WebP pro provoz není nutná. Úložiště `images/media/` je v `.gitignore`: **zálohuj ho společně s databází**, protože samotný `git pull` fotografie nepřenáší. Vlastní fotografie se automaticky nemažou, aby fungovaly odkazy ze starších revizí i zkopírované veřejné adresy. Není potřeba měnit SQL schéma. Kontrolní test: `/opt/lampp/bin/php tests/media-library.php`.

**XAMPP na Linuxu – práva pro fotografie:** Nejdřív ověř účet a skupinu Apache příkazem `grep -E '^(User|Group) ' /opt/lampp/etc/httpd.conf`. Pokud je skupina `daemon`, spusť v kořeni projektu `sudo chgrp -R daemon images/media` a `sudo chmod -R g+rwX images/media`. První příkaz zachová tvé vlastnictví souborů, druhý dovolí Apache vytvářet složky a ukládat fotografie. Měň práva jen na `images/media/`; nepoužívej `chmod 777` ani nespouštěj Apache jako root. Na Webglobe nepoužívej skupinu `daemon` z lokálního XAMPP: práva k `images/media/` nastav podle uživatele PHP na konkrétním hostingu.

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

`config/menus.php` pojmenovává výchozí místa na stránce. `primary` tvoří kategorie hlavičky, `category_tabs` načítá přímé děti právě zobrazené kategorie, `utility` tvoří Blog a publikované stránky označené pro horní odkazy, `footer` znovu používá kořenové kategorie. Správa menu v administraci ukládá změny po jednotlivých jazycích do `navigation_menus`. Pokud tabulka nebo konkrétní řádek chybí, platí výchozí nastavení v `config/menus.php`; stará navigace se po aktualizaci neztratí.

### Správa kategorií a menu v administraci

Po přihlášení otevři **Kategorie** na `admin.php?section=categories`. Můžeš vytvořit hlavní sekci nebo podkategorii, změnit název, pořadí mezi sousedy a zobrazení. Pořadí je číslo; menší číslo se ukáže dřív. Adresa `path` se po vytvoření nemění: produkty a další větve na ni odkazují. Skrytí rodiče skryje na webu také jeho podkategorie, v administraci zůstanou dostupné. Na stránce kategorie má přihlášený správce odkazy **Upravit kategorie** a **Přidat podkategorii**; v horních odkazech webu najde **Upravit menu**. Tyto zkratky otevřou stejný editor jako administrace.

Na `admin.php?section=menus` nastavíš pro hlavičku, horní odkazy, podkategorie a patičku zdroj **Kategorie**, **Publikované stránky** nebo **Vlastní odkazy**. U kategorií měníš jejich skutečné pořadí ve správě kategorií. Horní odkazy se stránkami mají přímo v editoru menu pole **V menu** a **Pořadí**; uložení vytvoří novou revizi stránky. Vlastní odkazy mohou vést na kategorii, stránku či blog, lze je vnořit a seřadit. Vnořené vlastní odkazy v hlavičce se otevírají rozbalovacím prvkem, v patičce se vypisují pod rodičem a mezi podkategoriemi se zobrazí jako další odkazy. Nové pojmenované místo lze založit v administraci, ale jeho výpis v další části webu vyžaduje zavolat `MenuManager::links('nazev')` v odpovídající šabloně.

Pro správu menu importuj `database/schema.sql` do databáze z `config/database.php`; tabulka `navigation_menus` uchovává vlastní odkazy. Všechny změny kategorií a menu ověřuje přihlášení správce a CSRF token.

Kontroly bez databáze: `/opt/lampp/bin/php tests/url-manager.php`, `/opt/lampp/bin/php tests/category-editor.php`, `/opt/lampp/bin/php tests/menu-definitions.php` a `/opt/lampp/bin/php tests/menu-manager.php`. Po importu také `/opt/lampp/bin/php tests/category-navigation.php`.

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
| `src/Navigation/MenuDefinitionRepository.php` | Databázové nastavení menu po jazycích a vlastní vnořené odkazy. |
| `src/Navigation/StorefrontMenus.php` | Stejné navigační položky pro všechny části webu. |
| `src/Category/CategoryRepository.php` | Jedna tabulka s kategoriemi a jejich stromem. |
| `src/Category/CategoryPath.php` | Práce s vnořenými cestami a čtení starých produktů. |
| `config/menus.php` | Určení zdroje pro jednotlivá místa menu. |
| `src/Database/ConnectionFactory.php` | Vytvoření připojení MeekroDB z lokální konfigurace. |
| `src/Admin/AdminAuth.php`, `src/Admin/AdminUserRepository.php` | Přihlášení správce z tabulky `users`, session, CSRF token a změna hesla z terminálu. |
| `src/Auth/RoleAuth.php`, `src/Customer/` | Sdílená práce se session, oddělená role zákazníka, profil a adresy. |
| `src/Product/ProductRepository.php` | Katalog, detail a každá revize produktu. |
| `src/Product/ProductInlineEditor.php` | Vzor konceptu a převod jedné přímé úpravy na celou produktovou revizi. |
| `src/Admin/inline-product.php` | Zabezpečené uložení a obnova revize při úpravě na stránce. |
| `src/Content/ContentRepository.php` | Čtení obsahu, historie a uložení nového řádku v transakci. |
| `src/Content/ContentBody.php` | Bloky ve stávajícím sloupci body a čtení původního prostého textu. |
| `src/Content/ContentInlineEditor.php` | Předvyplněný koncept a skládání nových kompletních revizí ze změn na stránce. |
| `src/Admin/inline-content.php` | Kontrolovaný zápis a obnova stránek a článků po přihlášení. |
| `src/Admin/categories.php`, `src/Admin/menus.php` | Správa kategorií, zdrojů menu a pořadí stránek. |
| `src/Checkout/`, `view/checkout/` | PHP košík, doprava, kontrola objednávky, její zápis a údaje pro bankovní převod. |
| `config/checkout.example.php` | Vzor místního nastavení bankovního účtu, dopravy a obchodních podmínek. |
| `src/Admin/orders.php`, `view/admin/orders.php` | Přehled objednávek a ruční potvrzení přijatého převodu. |
| `src/Rendering/PageRenderer.php` | PHP pohledy bez Twig. |
| `view/shell.php`, `view/panel/` | Jediný obal stránky a společné rozvržení obou soukromých částí. |
| `account.php`, `view/account/` | Zákaznické přihlášení, nastavení a přehled nákupů. |
| `database/schema.sql` | Vždy aktuální úplné schéma. |
| `view/` | HTML pro obchod, blog, stránky, `<head>`, hlavičku, menu a patičku. |

Košík a pokladna vytvářejí skutečné objednávky v `shop_orders`, pokud je importované aktuální schéma a vyplněný `config/checkout.php`. Katalog vypisuje jen publikované produkty z databáze; koncept se veřejně neukáže před publikováním.

## Kontrola jádra a další hranice

`UrlManager::route()` rozpoznává jednotlivé typy adres a `index.php` k nim přiřazuje databázový obsah. Menu čte kategorie a stránky v PHP; JavaScript obsluhuje mobilní hamburger, připojení další dávky a vykreslení bankovního QR kódu. Košík, formuláře pokladny, ceny a zápis objednávky zpracovává PHP. Domovský odkaz i hledání zachovávají jazyk v URL.

Revize v `content_revisions` a `product_revisions` zůstávají celé v jednom řádku. Při uložení se v transakci označí stará revize jako neaktivní, vloží nová a smažou se revize starší než nastavený limit; unikátní indexy hlídají jednu současnou revizi a adresu. Editor při zápisu posílá očekávané číslo revize a odmítne zastaralou změnu. Při zakládání překladu se ověřuje existence původního dokumentu. V administraci se z historie načítají jen nadpisy a čísla revizí; úplná starší verze se načte až při obnově. Limit je `'revision_limit' => 50` v `config/site.php` pro každý produkt, stránku či článek **v každém jazyce zvlášť**. Čísla revizí rostou dál, i když se nejstarší řádky smažou. Starší existující dokument se pročistí při příštím uložení. Smazané revize už z historie nepůjde obnovit; před snížením limitu si případně zazálohuj databázi.

Tato podoba funguje pro **verzovaný obsah**. Objednávky mají vlastní trvalé číslo, náhodný přístupový token, snímky položek a ochranu proti dvojímu odeslání. Případné skladové pohyby a automatické platební služby budou vyžadovat další pravidla konzistence. Přidání dalšího jazyka vyžaduje přeložit i texty rozhraní; sloupec `language` počítá s dvoupísmenným kódem.

Po této aktualizaci znovu importuj celý aktuální `database/schema.sql`: rozšiřuje `shop_orders` pro objednávky a bankovní platby, přičemž starší řádky zachová. Kontroly bez databáze: `/opt/lampp/bin/php tests/admin-render.php`, `/opt/lampp/bin/php tests/customer-account.php`, `/opt/lampp/bin/php tests/account-render.php`, `/opt/lampp/bin/php tests/checkout-cart.php` a `/opt/lampp/bin/php tests/checkout-order.php`.
