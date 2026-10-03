# Dobrodruzi — jednoduchý obchod a CMS

Jednoduchý obchod, redakční systém a zákaznický účet v PHP 8.1+ a MySQL. Obchod, administrace i účet používají stejnou hlavičku a patičku z `view/`. Architektura a důvody jednotlivých rozhodnutí jsou popsané v [docs/architecture.md](docs/architecture.md).

Online platby: [Comgate](docs/comgate.md), [GoPay](docs/gopay.md) a [BTCPay Server](docs/btcpay.md). GoPay používá oficiální PHP SDK; BTCPay komunikuje se serverem přes Greenfield API.

**Nejdřív spusť v kořeni projektu:**

```bash
composer install
cp config/database.example.php config/database.php
```

Pak otevři `config/database.php` a nastav `host`, `port`, `database`, `user` a `password` podle svého MySQL. V příkladu jsou přihlašovací údaje běžné pro místní XAMPP (`root` bez hesla); pokud máš heslo nastavené, doplň ho. **Pokud už máš vlastní `config/database.php` z předchozí verze, nekopíruj příklad znovu:** původní klíč `dsn` aplikace také umí přečíst, stačí zkontrolovat uživatele a heslo. Tento soubor je schválně ignorovaný Gitem, aby se heslo nedostalo na GitHub.

## Spuštění v Apache / XAMPP / LAMPP

1. Nakopíruj celý repozitář do složky, kterou obsluhuje Apache. Web nemá složku `public/`; přístup ke zdrojovým a konfiguračním souborům blokuje `.htaccess`. Apache musí mít povolené `mod_rewrite` a `AllowOverride` pro tuto složku.
2. Ve složce projektu spusť `composer install`. Composer načítá třídy ze `src/` přes namespace `SimpleStore\` a nainstaluje MeekroDB a oficiální GoPay PHP SDK. PHP potřebuje verzi 8.1 nebo novější a rozšíření `mysqli`, `curl` a `json`. Pokud používáš LAMPP, spouštěj příkazový skript přes stejné PHP jako web: `/opt/lampp/bin/php tools/content.php …`. Ověř `/opt/lampp/bin/php -v`; starší PHP 8.0 už s aktuálním SDK GoPay nestačí.
3. V MySQL vytvoř tabulku importem jediného aktuálního souboru. V LAMPP použij `/opt/lampp/bin/mysql -u root < database/schema.sql` (pokud má root heslo, přidej `-p`); když na této cestě klient není, zkus `/opt/lampp/bin/mariadb` nebo import souboru v phpMyAdmin. Samotné `mysql` může chybět v systémové proměnné PATH, i když je MySQL součástí LAMPP. Příkazy `CREATE DATABASE IF NOT EXISTS`, `USE` a `CREATE TABLE IF NOT EXISTS` už v souboru jsou. Pokud hosting nedovolí `CREATE DATABASE`, vytvoř databázi v hostingu a v kopii importovaného SQL vynech první dva příkazy.
4. Pokud ještě nemáš `config/database.php`, zkopíruj `config/database.example.php` do `config/database.php` a vyplň přístupové údaje. Tento soubor je v `.gitignore` a nesmí se commitovat.
5. Otevři adresu složky projektu v Apache. Úvod bez databáze zobrazí pokyny k nastavení, ale nevydává ukázkové produkty za skutečné. Blog, menu, redakční stránky a produkty potřebují importované tabulky. Prohlížej `/cs`, `/cs/kategorie-produktu/spani/spacaky`, `/cs/blog` a `/cs/o-nas` po založení obsahu.

Konfigurace databáze je obyčejný PHP soubor, který `return [...]` vrací pojmenované hodnoty. Aplikace jej načítá jen při připojení k databázi; nepoužívá globální proměnné. Původní zápis s `dsn` také funguje. Při vývoji je v `config/site.php` zapnuto `'debug' => true`; PHP chyby, upozornění a zachycené výjimky se zobrazují na stránce. Až web skutečně zveřejníš, přepni jej na `false`. Pokud se stále objeví holá chyba 500 bez stránky aplikace, zkontroluj Apache error log a podporu `.htaccess`/`mod_rewrite`. Příkaz `ini_set()` nemůže zobrazit chybu parsování v tomtéž souboru, pokud se kvůli ní PHP vůbec nespustí.

## Administrace obsahu

Administrace je na `http://localhost/simple-store/admin.php`. Účty jsou v tabulce `users`; ukládá se pouze hash hesla. **Po stažení nové verze projektu** otevři v administraci **Databáze** (`admin.php?section=database`) a klikni na **Aktualizovat SQL tabulky**, pokud nástroj ukáže dostupnou aktualizaci. Použije aktuální `database/schema.sql` a databázi z `config/database.php`. Příkazy `CREATE DATABASE` a `USE` z tohoto souboru na webu nespouští. Import přes terminál nebo phpMyAdmin je stále dostupný:

```bash
cd /opt/lampp/htdocs/simple-store
git pull
/opt/lampp/bin/mysql -u root -p < database/schema.sql
```

Pokud má root účet bez hesla, vynech `-p`; pokud příkaz `/opt/lampp/bin/mysql` neexistuje, importuj soubor v phpMyAdmin. **Zapomenuté heslo nelze přečíst zpět.** Jen pokud chceš změnit heslo správce, spusť `/opt/lampp/bin/php tools/admin.php --reset`: vytvoří účet `admin`, pokud ještě není v databázi, nebo mu nastaví nové náhodné heslo. Heslo se zobrazí jednou v terminálu a předchozí přihlášené relace přestanou fungovat. Při prvním nasazení funguje také `/opt/lampp/bin/php tools/admin.php`.

Pokud znáš původní heslo a chceš ho zachovat, místo `--reset` spusť jednorázově `/opt/lampp/bin/php tools/admin.php --migrate`. Převezme původní jméno a hash z `config/admin.php`, pokud ještě v databázi žádný správce není. Starý soubor pak web už nepoužívá; po ověření přihlášení jej můžeš smazat. Databázové přihlašovací údaje zůstávají v `config/database.php`. Administrace obsahuje seznam dokumentů a jejich přímou úpravu. Přihlášení chrání PHP session, zápisy ověřuje CSRF token. Před zveřejněním webu vypni režim `debug`.

V administraci se ukládá otisk posledního úspěšně použitého schématu. Po přerušení můžeš stejnou aktualizaci spustit znovu; současný soubor je opakovatelný a existující obsah ani heslo správce nemaže. Nová prázdná instalace stále potřebuje první import a založení správce mimo administraci, protože bez tabulky `users` se nelze přihlásit. Rychlé kontroly: `/opt/lampp/bin/php tests/schema-updater.php`, `/opt/lampp/bin/php tests/admin-auth.php` a `/opt/lampp/bin/php tests/database-connection.php`.

### Společný vzhled a zákaznický účet

Hlavička, hlavní kategorie, horní odkazy, patička a košík se vykreslují v jediné šabloně `view/shell.php`. `view/head.php`, `view/header.php`, `view/menu.php` a `view/footer.php` jsou stejné pro obchod, administraci i zákaznickou zónu. Administrace má vlastní pracovní menu v levém panelu pod hlavičkou; na mobilu je panel nad obsahem. Zákazník na stejném místě uvidí jiné menu. Oba panely sdílejí `view/panel/layout.php`, `view/panel/sidebar.php` a `assets/panel.css`. Navigaci obchodu připravuje jedna třída `StorefrontMenus`, aby se změna menu projevila ve všech třech částech.

Odkazy **Přihlášení / účet** a **Registrace** v hlavičce vedou na `account.php`. Registrace používá e-mail, jméno a heslo alespoň o 12 znacích; v `config/site.php` ji lze vypnout volbou `customer_registration`. Zákazník se přihlašuje odděleně od správce. V nastavení mění jméno, telefon, e-mail (po zopakování a zadání současného hesla) a heslo. Spravuje až 20 uložených adres; českou adresu lze v pokladně vybrat bez opětovného vyplňování. Sekce **Objednávky** rozděluje vlastní objednávky na aktivní a historii (dokončené, zrušené, testovací), stránkuje je a otevírá podrobnosti. Nákup hosta lze k účtu přidat jeho soukromým odkazem, pouze pokud má v objednávce stejný e-mail; pouhé číslo objednávky nestačí. Stavy vyřízení mění správce v detailu objednávky, odeslání a dokončení povoluje až po označení platby jako přijaté. V sekci **Platby** je popsaný aktuální bankovní převod. Čísla platebních karet ani CVV se nezadávají a neukládají. Změna e-mailu zatím neověřuje vlastnictví nové adresy e-mailovou zprávou a automatický reset hesla e-mailem není připraven.

V `admin.php?section=users` lze zákazníky vyhledat, zobrazit jejich poslední objednávky, upravit kontakt, zablokovat nebo povolit přihlášení, změnit heslo a založit účet. Zablokování účet ani historii nemaže; změna hesla ukončí dřívější přihlášení.

Aktuální `database/schema.sql` doplní do `users` zákaznický e-mail, jméno a telefon, vytvoří `customer_addresses` a rozšíří `shop_orders` o údaje objednávek. Po stažení změn spusť **Databáze → Aktualizovat SQL tabulky** před použitím nové pokladny. Při aktualizaci přes administraci není třeba upravovat název databáze uvnitř SQL. Při ručním importu do jiné databáze než `simple_store` změň první `CREATE DATABASE` a `USE` v lokální kopii SQL. Zákaznické akce kontrolují roli, PHP session a CSRF token; každý dotaz na adresy a historii objednávek účtu je omezen ID přihlášeného zákazníka. Bez MySQL můžeš spustit `/opt/lampp/bin/php tests/customer-account.php` a `/opt/lampp/bin/php tests/account-render.php`.

### Košík, objednávka a bankovní převod

Košík na `/cs/kosik` ukládá vybrané produkty a množství do samostatné PHP session. Pokladna na `/cs/pokladna` kontroluje aktuálně publikované produkty a jejich ceny v databázi; cenu ani dopravu nepřebírá z prohlížeče. Zákazník může objednat i bez registrace, případně se při nákupu přihlásit nebo registrovat a vrátit se do pokladny. Zadá jméno, e-mail, telefon a adresu nebo výdejní místo, zkontroluje rozepsaný souhrn a potvrdí obchodní podmínky. Dostupné platby závisí na nastavení obchodu.

V administraci lze každou dopravu zapnout či vypnout a určit její cenu. Výchozí výdejní místa: PPL 80 Kč, Zásilkovna 90 Kč, GLS ParcelShop 59 Kč a Balíkovna 120 Kč. Na adresu: GLS 79 Kč, PPL 99 Kč, Česká pošta Balík Do ruky 121 Kč, DPD 99 Kč a Zásilkovna domů HD 121 Kč. Cenu položek i dopravy vypočítá server a objednávka si uloží vlastní kopii ceny a vybrané metody. Zásilkovna používá oficiální widget v6: zákazník v něm klikne na výdejní místo či Z-BOX, formulář uloží ID bodu a server před pokračováním i před vytvořením objednávky ověří jeho aktuální dostupnost a získá skutečný název a adresu. Bez výběru bodu objednávka nepokračuje.

PPL používá oficiální **Access Point Widget 2.0**. V [administraci widgetu PPL](https://klient.ppl.cz/widgetadmin) vytvoř a aktivuj veřejný API klíč, přidej doménu obchodu a klíč ulož v **Nastavení obchodu → PPL – mapa výdejních míst**. Pro zkoušku na localhost musí PPL povolit i tuto doménu. Zákazník zvolí ParcelShop nebo box v mapě; název, adresa a kód KM se doplní do pokladny i objednávky. Při neúplném výběru pokladna nepokračuje. Bez klíče zůstane dřívější ruční zadání adresy PPL, takže volba dopravy při vývoji nezmizí. Server kontroluje formát kódu KM, českou zemi a úplnost popisu; PPL pro Widget 2.0 zatím v dokumentaci neuvádí serverový endpoint k ověření vybraného bodu. Uložený popis je proto snímek odpovědi widgetu, nikoli nezávisle ověřená adresa. Mapa se načítá ze serveru PPL v prohlížeči a používá JavaScript. Podklad: [oficiální dokumentace Widgetu 2.0](https://ppl-widget2.apidog.io/7-kompletn%C3%AD-api-reference-2029223m0).

GLS – ParcelShop používá oficiální mapu **ShopDeliveryService** ve vloženém okně. Klíč pro tento výběr není potřeba. Zákazník v mapě zvolí místo a pokladna uloží jeho GLS ID, název a adresu; ruční opisování odpadá. Prohlížeč přijímá výběr jen ze známé domény mapy GLS a server kontroluje formát ID, českou zemi a úplnost údajů. Veřejná mapa neposkytuje serverový doklad o platnosti zaslaného ID, proto uloženou adresu ber jako snímek výběru; dostupnost pobočky se při odeslání objednávky nezjišťuje. Pro budoucí vytvoření zásilky u GLS je zásadní právě uložené ID. Výběr vyžaduje JavaScript a dostupnou mapu GLS. Podklad: [oficiální integrace GLS](https://gls-group.com/CZ/cs/odesilatele/klientska-zona/zakaznicka-reseni/psd/) a [návod ShopDeliveryService](https://e-balik.cz/images2/document/Implementace_PSD.pdf).

Balíkovna (Česká pošta) používá [oficiální mapu Balíkoven](https://www.balikovna.cz/documents/20124/15302845/Bal%C3%ADkovna-widget-implementace.pdf) ve vloženém okně, **bez API klíče**. Zákazník zvolí pobočku nebo box a formulář uloží ID, název, adresu a samostatné PSČ z pole `ZIP`. Prohlížeč přijme výběr jen z iframe domény České pošty; server kontroluje formát údajů a bez vybraného místa nepustí objednávku dál. Veřejná mapa neposkytuje nezávislé serverové ověření zaslaného ID ani pozdější dostupnosti pobočky. Při podání jsou ID místa a fyzické PSČ odlišné údaje: v datovém rozhraní pro zásilku NB se jako cíl používá ID Balíkovny; fyzická adresa a PSČ slouží také pro kontrolu vybraného místa. Vlastní štítek nevyráběj z údajů mapy, stáhni jej po podání v systému dopravce. Výběr vyžaduje JavaScript a dostupnou mapu České pošty.

### Podklady k podání Balíkovny a GLS

Nejdřív spusť **Administrace → Databáze → Aktualizovat SQL tabulky** (přidá `shop_carrier_shipments`). V detailu zaplacené objednávky expedované obchodem přes Balíkovnu, GLS ParcelShop nebo GLS na adresu vyplň skutečnou hmotnost a zkontroluj kontakty a adresu. Podklady lze upravovat až do zapsání čísla zásilky.

- **Balíkovna:** CSV se pro tento postup nenabízí. Uložené podklady ukazují příjemce, kontakt, hmotnost a ID výdejního místa. Zásilku vytvoř ručně v [podacím formuláři Balíkovny](https://www.balikovna.cz/cs/web/guest/poslat-balik) a použij tam vydaný štítek nebo podací kód. Samotný widget výdejních míst nepřiděluje číslo zásilky ani platný přepravní štítek.
- **GLS e-Balík:** Stáhni CSV pro výchozí import GLS: jeden řádek, **17 sloupců bez hlavičky a bez BOM**, UTF-8, oddělovač `;`. Pole 1 je prázdný rozměr (hmotnost je v poli 2), 3 a 4 jsou jméno a příjmení, 6 až 9 rozdělená adresa, 10 `CZ`, 11 a 12 kontakt, 15 variabilní symbol a 17 je ID ParcelShopu. U doručení na adresu zůstane pole 17 prázdné. Jméno a číslo domu při uložení zkontroluj. Odesílatele, cenu a dopravní službu ověř v náhledu importu a na skutečně vygenerovaném štítku; portál může postupně měnit požadovaný formát.

Uložení návrhu ani stažení CSV ještě nevytvoří zásilku ani štítek. Po úspěšném podání opiš skutečné číslo zásilky z portálu; objednávku označ jako *Odesláno* až po fyzickém předání. Pro CSV GLS není třeba API klíč, ale musíš mít přístup do e-Balíku. Přímé vytváření zásilek a štítků bez přepisu do portálu bude vyžadovat přístup, který poskytne dopravce. Společný návrh ukládá data také pro pozdější napojení API a může se rozšířit pro PPL.

Widget Zásilkovny **vyžaduje její 16znakový veřejný API klíč** a schválený účet; otevřený režim bez klíče Zásilkovna nenabízí. V klientské sekci najdi *API klíč* (nikoli API heslo) a vyplň jej v **Nastavení obchodu → Zásilkovna – mapa výdejních míst**. Klíč je záměrně viditelný ve stránce pro widget. Pokud chybí, doprava Zásilkovnou se zákazníkům nenabízí, i když ji máš zapnutou v seznamu metod. Ostatní způsoby dopravy fungují dál. PHP musí umět navázat odchozí HTTPS spojení s `widget.packeta.com` (přes cURL nebo `allow_url_fopen`); mapa se načítá v zákazníkově prohlížeči. Při nedostupném ověřování objednávka Zásilkovnou nepokračuje, aby se neuložila neplatná pobočka. Pro ověření na XAMPP potřebuješ připojení k internetu a platný klíč; samotný test kódu `php tests/packeta-pickup.php` používá simulovanou odpověď.

Pro automatické podání použij v administraci **Databáze → Aktualizovat SQL tabulky** (přidá `shop_packeta_shipments`). V **Nastavení obchodu → Podávání zásilek** vyplň soukromé **API heslo** a **označení odesílatele**. V [seznamu odesílatelů v klientské sekci](https://client.packeta.com/senders/) zkopíruj přesně hodnotu *Označení* ze čtvrtého sloupce; do API se posílá jako `eshop`. Nejde nutně o název firmy. Pokud odesílatel chybí, nejprve ho tam vytvoř a vyčkej schválení účtu. Chyba `eshop_id: Není zadán odesilatel zásilky` značí, že Zásilkovna odeslanou hodnotu nepřiřadila k odesílateli. Heslo se nikdy nevypisuje zpět do formuláře; prázdné pole při dalším uložení ponechá současné heslo. Hosting musí mít PHP SimpleXML a odchozí HTTPS na `www.zasilkovna.cz`; funguje cURL nebo povolené `allow_url_fopen`.

V detailu zaplacené objednávky s dopravou Zásilkovnou zkontroluj jméno, e-mail a telefon příjemce a zadej hmotnost zabaleného balíku. Tlačítko **Vytvořit zásilku u Zásilkovny** odešle údaje pomocí `createPacket` s číslem objednávky, bez dobírky, a uloží vrácené číslo `Z…`. Lze stáhnout PDF štítek. U **Zásilkovna domů HD** se použije český dopravce `106`; před podáním zkontroluj rozdělenou ulici, číslo domu, město a PSČ. Údaje lze pro podání opravit bez přepsání původní objednávky. Po vytvoření zásilky vyžádej číslo dopravce tlačítkem a stáhni jeho přepravní štítek. Vytvoření zásilky samo nepřepne objednávku do stavu *Odesláno*; ten nastav až při předání balíku.

V detailu objednávky lze sledovat aktivní zásilku a před fyzickým předáním potvrdit její **storno u Zásilkovny**. Storno zásilky nemění objednávku ani platbu. Po potvrzeném stornu zůstane staré číslo v historii, případný stav *Připraveno k odeslání* se vrátí na *Připravuje se* a správce může opravit údaje pro nové podání, včetně ID výdejního místa. Změněné místo se před podáním ověřuje veřejným klíčem widgetu; u starší neověřené objednávky se ověří i původní ID. Pokud odpověď API není jistá, nové podání je zablokované: výsledek ověř v klientské sekci Zásilkovny a ručně potvrď, zda byla zásilka stornována, nebo zůstala aktivní. Bez fyzického předání lze objednávku vést jako *Připravuje se* či *Připraveno k odeslání*; po předání jako *Odesláno* a po doručení jako *Dokončeno*. Stav platby je samostatný. Seznam objednávek má filtry pro jednotlivé kroky a zobrazuje zvlášť stav vytvoření/storna zásilky; zákazník vidí stav i odkaz na sledování aktivní zásilky ve svém účtu. Pro storno nejprve spusť **Databáze → Aktualizovat SQL tabulky** (historie `shop_packeta_cancelled_shipments` a index stavů).

Pokud zásilku vyřizuje **externí dodavatel**, v panelu *Vyřízení* zvol tuto možnost, případně napiš jeho jméno do poznámky, a nastav stav objednávky. Místní podání Zásilkovně se nevyžaduje a volba dodavatele se ukládá k objednávce i zobrazuje v seznamu. Stav *Odesláno* použij po potvrzení předání balíku dodavatelem. Pokud už má obchod aktivní nebo nejasnou zásilku v Zásilkovně, nejdřív ji dořeš či stornuj. Při expedici dodavatelem systém novou zásilku přes svůj účet Zásilkovny nevytvoří. Po nasazení této změny spusť **Databáze → Aktualizovat SQL tabulky**; přidá sloupce `fulfillment_source` a `fulfillment_note` k `shop_orders`.

Pokud Zásilkovna požadavek výslovně odmítne, lze údaje opravit a odeslání zopakovat. Při výpadku spojení nebo nečitelném výsledku se nový pokus zablokuje: podle čísla objednávky nejprve ověř stav v klientské sekci. Nalezenou zásilku můžeš k objednávce ručně doplnit; teprve po potvrzení, že u Zásilkovny nevznikla, povolit nový pokus. Klíč widgetu a API heslo mají odlišné účely. Kód z modulů WooCommerce a PrestaShop se nepřebírá, integrace volá zveřejněné Packeta API přímo.

Technický podklad: [dokumentace widgetu a ověření bodu](https://docs.packeta.com/docs/pudo-delivery/widget), [podání na výdejní místo](https://docs.packeta.com/guides/integration-by-service/packeta-pudo), [podání HD](https://docs.packeta.com/docs/packet-creation/home-delivery), [seznam dopravců](https://docs.packeta.com/docs/home-delivery/carriers) a [tisk štítků](https://docs.packeta.com/docs/label-printing/packeta-label).

Pro devět způsobů dopravy není potřeba nová tabulka: ceny a dostupnost se ukládají do již existující `shop_checkout_settings`. Pokud tuto tabulku instalace nemá, administrace ji při prvním uložení sama vytvoří.

Před přijímáním objednávek aktualizuj schéma v administraci (při první instalaci proveď import `database/schema.sql`) a založ místní nastavení:

```bash
cp config/checkout.example.php config/checkout.php
```

Devět doprav je zapnutých ve vzoru a použije se i při starším místním `config/checkout.php` s prázdným `shipping_methods`. Starší cena `home` se převede na PPL na adresu. V administraci otevři **Nastavení obchodu**: upravíš ceny a dostupnost jednotlivých doprav, klíče widgetů Zásilkovny a PPL, číslo účtu, příjemce, splatnost a cestu k publikované stránce obchodních podmínek. Český IBAN se z čísla účtu dopočítá, pokud ho nevyplníš. Nastavení uložené v administraci má přednost a vznikne v tabulce `shop_checkout_settings` automaticky při prvním uložení. Cesta k podmínkám musí být místní publikovaná stránka, například `/simple-store/cs/obchodni-podminky` nebo při provozu v kořeni `/cs/obchodni-podminky`. `config/checkout.php` je ignorovaný Gitem a nepatří na GitHub.

Jakmile je vyplněný platný bankovní účet a příjemce, lze odeslat skutečnou objednávku převodem i bez odkazu na podmínky. Jestliže odkaz na podmínky vyplníš, zobrazí se zákazníkovi u konečného potvrzení. Bez bankovního účtu na XAMPP přes `localhost` lze vytvořit **testovací objednávku** bez platby. Je jasně označená v pokladně, potvrzení i administraci a nevytváří bankovní údaje ani QR. Tento režim funguje jen při `debug=true`, přímo z místního počítače a na adrese `localhost` nebo `127.0.0.1`; vypneš jej v nastavení. Na veřejné doméně se testovací objednávka nevytvoří.

Po potvrzení běžné objednávky vznikne řádek v `shop_orders` s kopií položek, cen, dopravy a bankovních údajů platných při objednání. Stránka `/cs/objednavka/<token>` ukáže číslo účtu, IBAN, částku a jedinečný variabilní symbol. QR platba se vytvoří v prohlížeči z údajů připravených PHP; údaje pro ruční převod jsou k dispozici i bez JavaScriptu. **Objednávka hosta nemá přihlašovací účet:** odkaz s tokenem si musí zákazník uložit. Po nastavení odesílatele systém zařadí potvrzení do e-mailové fronty a pokusí se je odeslat. S odkazem zacházej jako se soukromým údajem, protože umožňuje zobrazit platební údaje objednávky. Přihlášený zákazník najde své objednávky také v účtu.

V `admin.php?section=orders` správce vidí přijaté objednávky. Po kontrole **částky a variabilního symbolu na bankovním výpisu** ručně označí bankovní převod jako přijatý; stav se z banky nenačítá automaticky. U Comgate, GoPay a BTCPay lze v detailu znovu ověřit stav přímo u poskytovatele. Náhodný klíč v session a unikátní `idempotency_key` brání dvojímu vytvoření téže objednávky při opakovaném odeslání. Pro podrobnosti o bitcoinové platbě viz [nastavení BTCPay](docs/btcpay.md).

### Opravy, mazání a čísla objednávek

Nová běžná objednávka má tvar `DB-26-1234567890`: posledních deset číslic tvoří její variabilní symbol, takže lze objednávku rychle párovat s převodem. Testovací objednávka má například `TEST-26-A1B2C3D4` a variabilní symbol nemá. Čísla a platební údaje dříve vytvořených objednávek se **nemění**. Číslo objednávky je identifikátor nákupu, nikoli číslo faktury nebo souvislá účetní řada. Symbol je jedinečný náhodný identifikátor; po smazání objednávky se jeho dřívější hodnota nemá záměrně znovu přidělovat.

V detailu objednávky je oddělená **oprava chybného stavu**. Odeslanou či dokončenou objednávku lze vrátit do přípravy jen po potvrzení, že balík ve skutečnosti nebyl předán; předčasně dokončenou lze vrátit na odeslanou, pokud dosud nebyla doručena. Nezaplacenou zrušenou objednávku lze znovu otevřít. Správce uvede důvod, systém v transakci zapíše původní a nový stav, správce a čas do `shop_order_admin_events`. Oprava nemění platbu, údaje objednávky ani stav u Zásilkovny. Pro stav připraveno/odesláno při vlastní expedici Zásilkovnou musí stále existovat aktivní zásilka. Po skutečném předání zásilky používej běžný postup, nikoli opravu.

**Chybně potvrzenou platbu převodem** může správce s důvodem vrátit na čekající stav. Původní potvrzení a částka zůstanou v historii finančních zásahů; výpis banky se tím nemění. **Trvale smazat** lze testovací objednávku i objednávku převodem včetně zaplacené a dokončené. Správce opíše přesné číslo, potvrdí akci a zadá důvod. Nepodané podklady GLS či Balíkovny se odstraní spolu s objednávkou. Mazání odmítne zásilku s přiděleným číslem, místní záznam Zásilkovny a případný vydaný doklad; nejdřív je třeba tyto vazby vyřešit. Odebraná objednávka zmizí také ze zákaznického účtu. Zůstane auditní záznam a u převodu finanční stopa s původním stavem, částkou a variabilním symbolem. Bankovní platbu nebo skutečně vydaný doklad je nutné řešit samostatně. Před použitím oprav a mazání spusť **Databáze → Aktualizovat SQL tabulky**, aby vznikly auditní tabulky.

### Účetní podklady

`admin.php?section=accounting` ukazuje po dnech ručně potvrzené platby, počet objednávek a součty zboží, dopravy i celkové částky. Období lze filtrovat a stáhnout UTF-8 CSV. Ve stejném období uvidíš také stránkovanou historii oprav plateb a smazání objednávek převodem podle **dne zásahu**; tato část není součástí CSV běžně zaplacených objednávek. Testovací objednávky se nezahrnují. Pole `payment_paid_at` ukládá **okamžik, kdy správce označil platbu jako přijatou**, nikoli nutně skutečný den připsání na účet. Výpis banky je pro účetní evidenci rozhodující; systém zatím nemá samostatnou evidenci vratek. I zaplacená zrušená objednávka zůstane v přehledu, dokud není její platba opravena nebo objednávka smazána.

Účetní podklady nejsou vydané faktury ani evidence DPH. Pro budoucí podnikání budou potřeba nastavit identifikaci a režim prodávajícího (například OSVČ a zda je plátcem DPH), fakturační údaje kupujícího a okamžik vystavení dokladu; pak zavést vlastní unikátní řadu vydaných dokladů, neměnnou kopii včetně položek a opravné doklady/vratky. Doporučený tvar vlastní řady je například `F2026-000001`, ale přidělí se **až při vystavení dokladu**. Číslo objednávky ani variabilní symbol se na číslo dokladu nepřevádějí. Dosavadní funkce sama zákazníkovi žádný doklad nevystavuje. Pro právní náležitosti pozdějšího vydávání je třeba vycházet z aktuálního zákona o DPH (zejména § 29 pro plátce) a zvoleného režimu podnikání.

Kontroly bez databáze: `/opt/lampp/bin/php tests/checkout-cart.php`, `/opt/lampp/bin/php tests/checkout-order.php` a `node --test tests/payment-qr.mjs`. Před ostrým provozem projdi celý nákup na své XAMPP instalaci včetně objednávky hosta, obnovení stránky s platbou a ručního potvrzení v administraci.

### Stránky a články: úpravy přímo na stránce

Po přihlášení klikni v liště přímo na webu na **＋ Stránka** nebo **＋ Článek**. Vznikne neveřejný koncept s ukázkovým úvodem, odstavcem, seznamem, tabulkou a fotografií. Kliknutím do textu jej přepíšeš, po opuštění pole se automaticky uloží a stránka se obnoví. Mezi bloky přidáš další text, seznam, tabulku či fotografii; blok lze přesunout, přepnout jeho typ nebo odebrat. Tlačítkem **Fotografie** otevřeš knihovnu, kde lze nahrát více souborů najednou; vloží se jako obrazové bloky na zvolené místo. Lze také vybrat již nahraný snímek nebo zadat cestu `images/nazev.webp` či HTTPS adresu. V textu funguje `**tučné**` a `[odkaz](https://example.org)`, řádky tabulky odděluje `|`.

Při první změně předvyplněného nadpisu se vytvoří i adresa (slug) z názvu; pozdější změny nadpisu adresu nepřepisují. Slug můžeš upravit ručně nahoře. Stránce lze nastavit zobrazení v horních odkazech a pořadí v menu; článek po publikování patří na `/cs/blog`. **Publikovat** zobrazí obsah návštěvníkům. Otevření publikované stránky přihlášeným správcem nabídne odkaz **Upravit přímo na stránce**; neveřejný koncept se načte pouze přihlášenému přes `?edit=1`. Každé uložení vytvoří nový řádek v `content_revisions`. Rozbalená historie umožní vrátit některou ze zachovaných verzí jako další revizi.

Existující stránky psané prostým textem zůstávají čitelné a při prvním přímém uložení se převedou na jeden textový blok. Staré revize se nepřepisují. **Žádné nové SQL není potřeba:** pokud už máš tabulku `content_revisions`, stačí `git pull`. Kontrolní test bez databáze: `/opt/lampp/bin/php tests/content-editor.php`.

Na `/cs/blog` má správce odkaz pro přidání článku a u každého publikovaného článku odkaz na úpravu. `?manage=1` zobrazí také rozepsané články. Stránky, které nejsou v menu, a další koncepty najdeš přes vyhledávání, filtry a stránkování v `admin.php?section=contents`; jejich editace otevře skutečnou stránku. Pokud v `config/site.php` později povolíš další jazyky, u dokumentu v tomto přehledu se objeví **Nový překlad**. Vytvoří samostatný neveřejný koncept pod stejným `document_key`.

## Produkty: úpravy přímo na stránce

Po přihlášení použij v liště webu **＋ Produkt**. Vznikne neveřejný koncept a ihned se otevře jeho stránka s ukázkovým obsahem: název, úvod, fotografie, varianty, technické údaje, odstavec, seznam, tabulka a fotografický blok. Před zveřejněním nahraď ukázkové údaje svými. V `?manage=1` na katalogu můžeš vyhledávat a filtrovat publikované i skryté produkty včetně konceptů; detail otevřeš rovnou k úpravě. Odkaz **Produkty** v administraci sem také vede. Běžný katalog stále zobrazuje jen publikované produkty.

### Výběr produktů na úvodní stránku

Po **Databáze → Aktualizovat SQL tabulky** otevři jako správce úvodní stránku a klikni na **Vybrat produkty na úvodní stránku**. Produkty můžeš hledat podle názvu nebo značky, přidávat, posouvat nahoru a dolů a odebírat. Výběr a pořadí se ukládají v `shop_homepage_selections` samostatně pro každý jazyk. Do výběru lze přidat nejvýše 24 zveřejněných produktů; skrytý produkt ze zobrazení pro zákazníka automaticky zmizí a v editoru ho můžeš odebrat. Přejmenování nebo změna adresy produktu výběr neporuší. Kategorie, vyhledávání a odkaz **Prohlédnout veškeré vybavení** dál procházejí celý katalog. Dokud nic nevybereš, úvodní stránka zůstane u dosavadního automatického výpisu. Volba **Vrátit automatický výpis katalogu** tuto podobu kdykoli obnoví.

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

`config/database.php` určuje databázi, do které se web připojuje. V administraci otevři **Databáze** a spusť aktualizaci; obsahuje opakovatelnou migraci sloupce `details_json` a existující produkty ani revize nemaže. Při ručním importu do jiné databáze změň první `CREATE DATABASE` a `USE` v lokální kopii SQL.

## Kategorie a menu

Po stažení nové verze spusť aktualizaci přes **Databáze**. Použije jediný aktuální soubor `database/schema.sql`, který obsahuje `CREATE TABLE catalog_categories` a `INSERT IGNORE` se všemi 58 požadovanými sekcemi a podsekcemi. Opakování zachová existující produkty, stránky, revize i upravené názvy kategorií. Ruční import v LAMPP:

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

Pro správu menu aktualizuj tabulky v administraci; tabulka `navigation_menus` uchovává vlastní odkazy. Všechny změny kategorií a menu ověřuje přihlášení správce a CSRF token.

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
| `src/Database/SchemaUpdater.php`, `view/admin/database.php` | Aktualizace schématu přihlášeným správcem. |
| `view/` | HTML pro obchod, blog, stránky, `<head>`, hlavičku, menu a patičku. |

Košík a pokladna vytvářejí skutečné objednávky v `shop_orders`, pokud je importované aktuální schéma a vyplněný `config/checkout.php`. Katalog vypisuje jen publikované produkty z databáze; koncept se veřejně neukáže před publikováním.

## Kontrola jádra a další hranice

`UrlManager::route()` rozpoznává jednotlivé typy adres a `index.php` k nim přiřazuje databázový obsah. Menu čte kategorie a stránky v PHP; JavaScript obsluhuje mobilní hamburger, připojení další dávky a vykreslení bankovního QR kódu. Košík, formuláře pokladny, ceny a zápis objednávky zpracovává PHP. Domovský odkaz i hledání zachovávají jazyk v URL.

Revize v `content_revisions` a `product_revisions` zůstávají celé v jednom řádku. Při uložení se v transakci označí stará revize jako neaktivní, vloží nová a smažou se revize starší než nastavený limit; unikátní indexy hlídají jednu současnou revizi a adresu. Editor při zápisu posílá očekávané číslo revize a odmítne zastaralou změnu. Při zakládání překladu se ověřuje existence původního dokumentu. V administraci se z historie načítají jen nadpisy a čísla revizí; úplná starší verze se načte až při obnově. Limit je `'revision_limit' => 50` v `config/site.php` pro každý produkt, stránku či článek **v každém jazyce zvlášť**. Čísla revizí rostou dál, i když se nejstarší řádky smažou. Starší existující dokument se pročistí při příštím uložení. Smazané revize už z historie nepůjde obnovit; před snížením limitu si případně zazálohuj databázi.

Tato podoba funguje pro **verzovaný obsah**. Objednávky mají vlastní trvalé číslo, náhodný přístupový token, snímky položek a ochranu proti dvojímu odeslání. Případné skladové pohyby a automatické platební služby budou vyžadovat další pravidla konzistence. Přidání dalšího jazyka vyžaduje přeložit i texty rozhraní; sloupec `language` počítá s dvoupísmenným kódem.

Po stažení této verze otevři **Databáze** v administraci a použij aktuální schéma. Rozšíří `shop_orders` pro objednávky a platby, přičemž starší řádky zachová. Kontroly bez databáze: `/opt/lampp/bin/php tests/admin-render.php`, `/opt/lampp/bin/php tests/schema-updater.php`, `/opt/lampp/bin/php tests/customer-account.php` a `/opt/lampp/bin/php tests/checkout-order.php`.

## Daňová evidence OSVČ

Po aktualizaci v **Administrace → Databáze** otevři **Daňová evidence OSVČ → Údaje OSVČ** a vyplň skutečné jméno, IČO, sídlo a e-mail odesílatele. Číslo účtu na vydané faktuře vychází ze snímku platebních údajů dané objednávky. Režim je určen pro OSVČ vedoucí daňovou evidenci, která **není plátcem DPH**; režim plátce a automatický výpočet přiznání nejsou implementované.

- **Peněžní deník:** ruční zápis skutečných příjmů a výdajů podle bankovního výpisu či pokladny, rozlišení banky/hotovosti a daňového zařazení. Roční CSV export zahrnuje i záznamy mimo zobrazených posledních 500 řádků. Chybný záznam lze opravit nebo vyřadit s důvodem a historií změny. Označení objednávky jako zaplacené nenahrazuje datum připsání peněz. Její skutečnou úhradu zapiš na detailu objednávky jen jednou.
- **Majetek a dluhy:** ručně vedené pohledávky, dluhy a majetek; nezaplacené objednávky se zobrazují odděleně. Konec roku vyžaduje kontrolu skutečných stavů.
- **Sklad a prodeje:** došlé kusy a inventurní rozdíly se zapisují ke klíči produktu, položky nové objednávky mají snímek počtu a ceny. Počítaný úbytek používá stav odesláno/dokončeno. Starší objednávky doplňuje opakovatelná akce po 100 objednávkách. Roční prodeje a skladové pohyby lze exportovat do CSV. Fyzický stav je nutné porovnat s evidencí.
- **Faktury:** na detailu zaplacené objednávky ověř odběratele a vystav fakturu z roční číselné řady. Tisková stránka má volbu pro uložení PDF v prohlížeči; zákazník ji uvidí přes svůj soukromý odkaz na objednávku. Faktura obsahuje snímky údajů z doby vystavení. Oprava čísla vyžaduje důvod, zachovává historii a nepovolí opětovné použití starého čísla. Již odeslaný e-mail se po opravě sám nepřepíše.
- **E-maily:** nákup vytvoří potvrzení s položkami, cenou dopravy a údaji k platbě; vystavení faktury vytvoří zprávu s jejím úplným textem. V **Nastavení obchodu → E-maily** nastav odesílatele, veřejnou HTTPS adresu obchodu, SMTP server, port, zabezpečení, přihlašovací jméno a heslo schránky. Bez SMTP se dál používá PHP `mail()` hostingu. Šablony událostí jsou upravitelné, fronta umožňuje opakování neúspěšného doručení. Tlačítkem **Odeslat testovací e-mail** ověř nastavení přímo se svou schránkou. Přijetí zprávy poštovním serverem ještě nezaručuje doručení do složky doručené pošty. Heslo se v databázi ukládá šifrovaně, klíč v `config/.smtp-key.php` vzniká při prvním uložení SMTP hesla a musí se zálohovat spolu s databází. PHP musí mít OpenSSL, webový proces musí mít právo zápisu do `config/` při vytvoření klíče. E-mail faktury neobsahuje PDF přílohu; PDF si lze vytisknout ze soukromé stránky objednávky.

- **Obnova hesla:** po aktualizaci schématu přibude `shop_password_resets`. V e-mailovém nastavení správce vyplň také **E-mail pro obnovu hesla správce** a veřejnou HTTPS adresu instalace (bez `/cs`, například `https://dobrodruzi.cz` nebo `https://example.cz/simple-store`). Na přihlašovací stránce účtu i administrace je odkaz **Zapomenuté heslo?**. Odkaz v e-mailu platí 30 minut a po použití či změně hesla přestane fungovat. Pro správce zůstává dostupná obnova přes `php tools/admin.php --reset`, když pošta nefunguje.

Testovací objednávku vytvořenou místním testovacím režimem může správce smazat bez účetních záznamů. Skutečnou objednávku lze odstranit z provozního seznamu i po zaplacení, vystavení faktury nebo předání dopravci. Vydaný doklad zůstane v evidenci faktur, peněžní zápis a rozpis zaplacených položek v daňové evidenci; u Comgate a GoPay zůstane pokus o platbu a jeho ověřený stav pro případnou pozdější notifikaci. Číslo zásilky a původní stav zůstávají v interní databázi pro dohledání u dopravce. Smazání objednávky **nestornuje platbu ani skutečnou zásilku**. Probíhající nebo nejisté založení platby u brány i nejasné podání/storno u Zásilkovny musí správce nejprve vyjasnit, aby se neztratilo spárování transakce nebo číslo zásilky. Před použitím této funkce aktualizuj SQL tabulky.

# Sklad produktů

Po aktualizaci SQL tabulek v administraci otevři produkt a klikni na **Upravit tento produkt přímo na stránce**. V části **Volné kusy skladem** nastav ověřený počet a ulož jej. V přehledu všech produktů se tento počet zobrazuje správci, zákazník vidí jen „Skladem“ nebo „Není skladem“; konkrétní počet není veřejně vypisován. Nové i stávající produkty začínají na **0 kusech** a je potřeba je před prodejem naplnit. Stav „Na objednávku“ dovoluje objednávat i při nule, ruční „Není skladem“ prodej blokuje.

Počet patří produktu jako celku a sdílí ho všechny jazykové verze i jeho volby (například barvy a velikosti). Objednávka odečte kusy v jediné databázové transakci; současné objednávky tak nemohou prodat stejný poslední kus. Storno dosud neodeslané objednávky je vrátí, opětovné otevření zrušené objednávky je musí znovu zajistit. Při odeslání se rezervace označí jako spotřebovaná a pozdější smazání objednávky již počet automaticky nenavyšuje. Ruční přepsání počtu v editoru aktualizuje dostupné kusy a odmítne uložení, pokud se mezitím změnily.

Evidence pohybů v účetní části má jiný účel než provozní počet volných kusů. Pokud do ní zapíšeš příjem nebo výdej, uprav dostupný počet i v produktu; samotný účetní zápis jej nepřepíše.

## Správa objednávek

Po nasazení otevři **Administrace → Databáze → Aktualizovat SQL tabulky**. Přehled objednávek ukazuje samostatně stav platby a vyřízení, způsob platby, dopravu, číslo vystavené faktury a celkovou částku. Lze hledat podle čísla objednávky, e-mailu nebo variabilního symbolu a filtrovat podle stavu a způsobu platby. U jednotlivých objednávek jsou běžné stavové akce a potvrzení bankovního převodu dostupné přímo ze seznamu; potvrzení převodu vždy vyžaduje ruční kontrolu bankovního výpisu. Vrácená platba přes GoPay nesmí být nabízena k expedici.

Detail obsahuje položky s obrázky a odkazy na aktuální produkty, zkopírovatelné údaje pro dopravce a externího dodavatele a přímý odkaz na vystavenou fakturu. Formuláře k dopravcům, opravám a zásahům jsou sbalené, dokud je správce nepotřebuje. Stav „Přijata“ znamená přijetí objednávky; „Připravena k odeslání“ připravený balík a „Předána dopravci“ skutečné předání. Vytvoření podkladů nebo štítku samo o sobě neznamená předání.

Dopravce lze před odesláním změnit na některého zapnutého dopravce doručujícího domů. U původní objednávky na výdejní místo je nutné vyplnit a potvrdit ověřenou adresu zákazníka. Původně sjednaná metoda, cena dopravy a vystavená faktura zůstávají součástí neměnného nákupního dokladu; změna platí jen pro skutečné podání. Aktivní zásilku u Zásilkovny je třeba před přesměrováním vyřešit a podklady k jinému dopravci před změnou výslovně ověřit.

Správce může trvale odstranit objednávku ze seznamu i po platbě nebo expedici. Smazání v obchodě nestornuje platbu ani fyzicky podanou zásilku. U skutečných obchodů zůstává vystavená faktura, údaje o transakci, nutná položková účetní evidence a minimální reference na zásilku; testovací objednávky lze odstranit bez těchto účetních podkladů. Sekce „Nedávno smazané objednávky“ se v běžném přehledu nezobrazuje.
