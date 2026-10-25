Warning: truncated output (original token count: 21516)
Total output lines: 414

# Dobrodruzi — jednoduchý obchod a CMS

Jednoduchý obchod, redakční systém a zákaznický účet v PHP 8.1+ a MySQL. Obchod a zákaznický účet sdílejí hlavičku a patičku z `view/`; přihlášená administrace má vlastní šablonu. Architektura a důvody jednotlivých rozhodnutí jsou popsané v [docs/architecture.md](docs/architecture.md).

Online platby: [Comgate](docs/comgate.md), [GoPay](docs/gopay.md) a [BTCPay Server](docs/btcpay.md). GoPay používá oficiální PHP SDK; BTCPay komunikuje se serverem přes Greenfield API.

Aktuální kritické nálezy, kroky před nasazením a pořadí dalších oprav jsou v [auditu projektu](docs/audit-2026-10.md).

[Upravený plán odkazů partnerů u variant](docs/variant-supplier-architecture.md) zachovává současné režimy „Prodáváme my“ a „Asistovaný nákup“. Partner znamená jiný e-shop výhradně pro asistenci. Plán nejdřív doplňuje variantní odkazy a až potom přesnou partnerskou dostupnost; nezavádí dodavatele vlastního skladu ani jeho plošnou migraci. Etapy A/B/C jsou implementované: soukromé odkazy variant, opakovatelné převzetí, editor v tabulce, JSON, `?variant=...` a volitelné párování přesných URL z čerstvých nabídek. Automatické řízení partnerské dostupnosti D následuje samostatně.

Testovací hosting Webglobe: [postup nasazení přes FTP a aktualizace databáze](docs/deploy-webglobe.md). GitHub workflow po úspěšných testech zveřejní změny pouze po zapnutí repository variable a nastavení FTP secrets. Soukromé konfigurace a nahrané fotografie se nepřenášejí ani nemažou.

**Nejdřív spusť v kořeni projektu:**

```bash
composer install
cp config/database.example.php config/database.php
```

Pak otevři `config/database.php` a nastav `host`, `port`, `database`, `user` a `password` podle svého MySQL. V příkladu jsou přihlašovací údaje běžné pro místní XAMPP (`root` bez hesla); pokud máš heslo nastavené, doplň ho. **Pokud už máš vlastní `config/database.php` z předchozí verze, nekopíruj příklad znovu:** původní klíč `dsn` aplikace také umí přečíst, stačí zkontrolovat uživatele a heslo. Tento soubor je schválně ignorovaný Gitem, aby se heslo nedostalo na GitHub.

## Spuštění v Apache / XAMPP / LAMPP

1. Nakopíruj celý repozitář do složky, kterou obsluhuje Apache. Web nemá složku `public/`; přístup ke zdrojovým a konfiguračním souborům blokuje `.htaccess`. Apache musí mít povolené `mod_rewrite` a `AllowOverride` pro tuto složku.
2. Ve složce projektu spusť `composer install`. Composer načítá třídy ze `src/` přes namespace `SimpleStore\` a nainstaluje MeekroDB a oficiální GoPay PHP SDK. PHP potřebuje verzi 8.1 nebo novější a rozšíření `mysqli`, `curl` a `json`. Pokud používáš LAMPP, spouštěj příkazový skript přes stejné PHP jako web: `/opt/lampp/bin/php tools/content.php …`. Ověř `/opt/lampp/bin/php -v`; starší PHP 8.0 už s aktuálním SDK GoPay nestačí. CI na PHP 8.0 kontroluje pouze syntaxi vlastních souborů, aby starší XAMPP místo nejasné chyby parsování neblokovala nově přidaná syntaxe; není to příslib provozu celého obchodu na PHP 8.0.
3. V MySQL vytvoř tabulku importem jediného aktuálního souboru. V LAMPP použij `/opt/lampp/bin/mysql -u root < database/schema.sql` (pokud má root heslo, přidej `-p`); když na této cestě klient není, zkus `/opt/lampp/bin/mariadb` nebo import souboru v phpMyAdmin. Samotné `mysql` může chybět v systémové proměnné PATH, i když je MySQL součástí LAMPP. Příkazy `CREATE DATABASE IF NOT EXISTS`, `USE` a `CREATE TABLE IF NOT EXISTS` už v souboru jsou. Pokud hosting nedovolí `CREATE DATABASE`, vytvoř databázi v hostingu a v kopii importovaného SQL vynech první dva příkazy.
4. Pokud ještě nemáš `config/database.php`, zkopíruj `config/database.example.php` do `config/database.php` a vyplň přístupové údaje. Tento soubor je v `.gitignore` a nesmí se commitovat.
5. Otevři adresu složky projektu v Apache. Úvod bez databáze zobrazí pokyny k nastavení, ale nevydává ukázkové produkty za skutečné. Blog, menu, redakční stránky a produkty potřebují importované tabulky. Prohlížej `/cs`, `/cs/kategorie-produktu/spani/spacaky`, `/cs/blog` a `/cs/o-nas` po založení obsahu.

Konfigurace databáze je obyčejný PHP soubor, který `return [...]` vrací pojmenované hodnoty. Aplikace jej načítá jen při připojení k databázi; nepoužívá globální proměnné. Původní zápis s `dsn` také funguje. Ladicí výpisy jsou na veřejném hostingu ve výchozím stavu vypnuté; v místním Apache je lze zapnout proměnnou prostředí `SIMPLE_STORE_DEBUG=1`. Pokud se objeví holá chyba 500 bez stránky aplikace, zkontroluj Apache error log a podporu `.htaccess`/`mod_rewrite`. Příkaz `ini_set()` nemůže zobrazit chybu parsování v tomtéž souboru, pokud se kvůli ní PHP vůbec nespustí.

## Administrace obsahu

Administrace je na `http://localhost/simple-store/admin/`. Účty jsou v tabulce `shop_users`; ukládá se pouze hash hesla. **Po stažení nové verze projektu** otevři v administraci **Databáze** (`/admin/database/`) a klikni na **Aktualizovat SQL tabulky**, pokud nástroj ukáže dostupnou aktualizaci. Použije aktuální `database/schema.sql` a databázi z `config/database.php`. Příkazy `CREATE DATABASE` a `USE` z tohoto souboru na webu nespouští. Import přes terminál nebo phpMyAdmin je stále dostupný:

```bash
cd /opt/lampp/htdocs/simple-store
git pull
/opt/lampp/bin/mysql -u root -p < database/schema.sql
```

Pokud má root účet bez hesla, vynech `-p`; pokud příkaz `/opt/lampp/bin/mysql` neexistuje, importuj soubor v phpMyAdmin. **Zapomenuté heslo nelze přečíst zpět.** Pro změnu hesla správce spusť `/opt/lampp/bin/php tools/admin.php --reset --email=tvuj@email.cz`: existujícímu účtu nastaví nové náhodné heslo. Heslo se zobrazí jednou v terminálu a předchozí přihlášené relace přestanou fungovat. Při prvním nasazení založ správce příkazem `/opt/lampp/bin/php tools/admin.php create --email=tvuj@email.cz`; e-mail slouží k přihlášení. Interní hodnota `username` v databázi zůstává kvůli stávajícím vazbám a není přihlašovacím údajem.

Pokud znáš původní heslo a chceš ho zachovat, místo `--reset` spusť jednorázově `/opt/lampp/bin/php tools/admin.php --migrate --email=tvuj@email.cz`. Převezme původní interní jméno a hash z `config/admin.php`, pokud ještě v databázi žádný správce není. Starý soubor pak web už nepoužívá; po ověření přihlášení jej můžeš smazat. Databázové přihlašovací údaje zůstávají v `config/database.php`. Administrace obsahuje seznam dokumentů a jejich přímou úpravu. Přihlášení chrání PHP session, zápisy ověřuje CSRF token. Na veřejném webu nezapínej `SIMPLE_STORE_DEBUG`.

V administraci se ukládá otisk posledního úspěšně použitého schématu. Po přerušení můžeš stejnou aktualizaci spustit znovu; současný soubor je opakovatelný a existující obsah ani heslo správce nemaže. Nová prázdná instalace stále potřebuje první import a založení správce mimo administraci, protože bez tabulky `users` se nelze přihlásit. Rychlé kontroly: `/opt/lampp/bin/php tests/schema-updater.php`, `/opt/lampp/bin/php tests/admin-auth.php` a `/opt/lampp/bin/php tests/database-connection.php`.

### Společný vzhled a zákaznický účet

Dokument stránky skládá `view/shell.php`. Obchod a zákaznický účet používají stejnou hlavičku, navigaci a patičku; přihlášení zákazníka vzhled obchodu nemění. Přihlášená administrace má vlastní kompaktní hlavičku `view/admin/header.php`, menu přímo u levého okraje a stejně širokou pracovní plochu ve všech sekcích včetně Dokladů a plateb. Její oddělené `assets/admin-layout.css` a `assets/admin-layout.js` se do zákaznické části nenačítají. Na mobilu se admin menu vysouvá tlačítkem, bez JavaScriptu zůstává dostupné nad obsahem. Zkratky pro tvorbu obsahu jsou v hlavičce pod **Rychlé akce**. Administrace má vlastní `view/admin/sidebar.php`: menu široké 264 px ve skupinách Prodej, Obsah a katalog a Správa, s kompaktním odhlášením. Náhled jako návštěvník je v hlavičce pod **E-shop**. Záložky objednávek, nastavení, dokladů a importu používají jednotný vzhled; na mobilu se posouvají vodorovně. Zákaznické menu zůstává v `view/panel/sidebar.php`. Oba panely sdílejí `view/panel/layout.php` a základní styly `assets/panel.css` + `assets/panel-orders.css` + `assets/panel-actions.css`. Veřejný web načítá `style.css`, detail produktu `assets/product-detail.css`, úpravy na stránce `assets/inline-edit.css` a obsah `assets/content.css`; pokladna má `assets/checkout.css`. Navigaci obchodu připravuje třída `StorefrontMenus`.

Odkazy **Přihlášení / účet** a **Registrace** v hlavičce vedou na `/account/` a `/account/register/`. Registrace používá e-mail, jméno a heslo alespoň o 10 znacích; v `config/site.php` ji lze vypnout volbou `customer_registration`. Zákazník se přihlašuje odděleně od správce. V nastavení mění jméno, telefon, e-mail (po zopakování a zadání současného hesla) a heslo. Spravuje až 20 uložených adres; českou adresu lze v pokladně vybrat bez opětovného vyplňování. Sekce **Objednávky** rozděluje vlastní objednávky na aktivní a historii (dokončené, zrušené, testovací), stránkuje je a otevírá podrobnosti. Nákup hosta lze k účtu přidat jeho soukromým odkazem, pouze pokud má v objednávce stejný e-mail; pouhé číslo objednávky nestačí. Stavy vyřízení mění správce v detailu objednávky, odeslání a dokončení povoluje až po označení platby jako přijaté. V sekci **Platby** je popsaný aktuální bankovní převod. Čísla platebních karet ani CVV se nezadávají a neukládají. Změna e-mailu zatím neověřuje vlastnictví nové adresy e-mailovou zprávou a obnovu zapomenutého hesla zajišťuje jednorázový odkaz zaslaný e-mailem.

V **Účty a administrátoři** (`/admin/users/`) lze založit zákazníka i dalšího správce, filtrovat podle role/stavu a hledat podle jména, e-mailu, telefonu či identifikátoru. Detail umožňuje upravit kontakty, roli, stav a všechny položky adresáře, zobrazit poslední objednávky, ručně nastavit heslo nebo v prohlížeči vygenerovat nové a zkopírovat je před uložením. Heslo se do databáze ukládá pouze jako hash; automaticky se nikomu neposílá. Každý administrátor má plná oprávnění a vlastní přihlašovací e-mail. Interní identifikátor lze při založení nechat prázdný, vytvoří se automaticky.

Před prvním použitím nové správy účtů spusť **Databáze → Aktualizovat SQL tabulky** (sloupec `shop_users.auth_version`). Přihlášení původního správce a aktualizace databáze fungují i před migrací. Změna hesla, e-mailu, role nebo stavu ukončí předchozí relace a zruší staré obnovovací odkazy. Opětovné povolení účtu staré relace neoživí. Vlastní účet nelze smazat, zablokovat ani zbavit role správce; nejdřív použij dalšího správce. Souběžné úpravy účtů jsou zamčené a starší otevřený formulář nepřepíše novější změnu.

Smazání vyžaduje potvrzení a odstraní účet, uložené adresy a obnovovací odkazy. Objednávky, reklamace, doklady a auditní historie zůstanou zachované, odpojí se pouze vazba na účet. Změny adresáře nepřepisují adresy v dřívějších objednávkách. Původní správce nadále používá dřívější přihlašovací e-mail z nastavení obchodu; první uložení jeho detailu nebo ruční změna hesla tento e-mail sváže přímo s účtem. Potom už změny kontaktního e-mailu obchodu jeho přihlášení neovlivní. Ostatní správci tento společný e-mail k přihlášení nepoužívají.

### Vzhled a texty zákaznických e-mailů

V **Nastavení obchodu → E-maily** upravíš předměty a úvody objednávkových zpráv, faktury, potvrzení reklamace a odstoupení, odpovědi i obnovu hesla. Společné texty zahrnují podpis, patičku, tlačítka, názvy údajů, stavy reklamací a vysvětlení partnerského dodání. Texty se ukládají do stávajících poštovních tabulek; tato úprava nepřidává databázovou migraci. Přesně rozpoznané staré výchozí úvody se aktualizují, vlastní formulace a volby odesílání zůstávají zachované.

**Zobrazit náhled** pracuje s rozepsaným formulářem bez ukládání a odesílání. Ukázku přepneš mezi vlastním, partnerským a kombinovaným nákupem a mezi mobilní a širokou šířkou. K dispozici je i textová verze. Obnovení výchozích textů upraví pouze danou skupinu formuláře. Změny začnou platit po uložení pro nově vytvořené zprávy; již uložená fronta se hromadně nepřepisuje. Skutečné odeslání zkouší pouze samostatné tlačítko testu doručení.

Partnerská položka uvádí skutečného prodávajícího a rozpis zboží a služby z objednávky. Potvrzení a přijetí platby zachovávají sjednané dodání a přesnou žádost o dřívější zahájení; více stejných partnerů se v údajích seskupuje. Samotná externí expedice vlastního prodeje se nezaměňuje s asistovaným nákupem. Texty si nevymýšlejí nedostupnost na vlastním skladu. Smluvní dokumenty se přikládají z původního snímku a odkaz na podmínky asistence se zobrazuje jen pro publikovanou stránku. Platba po stornu používá vlastní šablonu a neoznamuje obnovení objednávky.

HTML a prostý text mají společný obsah. Šablona používá tabulkový rozvrh, vložené styly a čitelné mobilní zobrazení bez obrázků načítaných od třetích stran. Náhledy testují běžný prohlížeč; jednotlivé poštovní aplikace mohou styly vykreslovat mírně odlišně. Automatické interní mezistavy zůstávají ve výchozím nastavení vypnuté. Obnova hesla má stále jednorázový odkaz na 30 minut a používá stejné nastavení odesílatele i adresy pro odpověď.

### Doručování e-mailů z fronty

Odeslání po události se zkouší hned. Pokud poštovní server zprávu odmítne, zůstane ve frontě a plánovač ji může zopakovat. Nejdřív spusť **Administrace → Databáze → Aktualizovat SQL tabulky** (přidá `attempted_at` a `next_attempt_at`), nastav odesílatele v **Nastavení obchodu → E-maily** a ověř testovací e-mail. Plánovač bere nejvýše 20 zpráv v jednom CLI běhu a automaticky zkusí jednu zprávu maximálně pětkrát; po neúspěchu čeká postupně 5, 10, 20 a 40 minut. Vyčerpané zprávy zůstávají viditelné v **Doklady a platby → E-maily** a správce je může zkusit ručně i po pátém pokusu.

Na hostingu s terminálovým cronem lze každých pět minut spustit `php /úplná/cesta/k/obchodu/tools/mail-worker.php --limit=20`. Skript je určen pouze pro CLI; adresář `tools/` je na webu blokován. Limit lze nastavit od 1 do 50. Například záznam v běžném crontabu:

```cron
*/5 * * * * /usr/bin/php /úplná/cesta/k/obchodu/tools/mail-worker.php --limit=20
```

[Webglobe CRON](https://www.webglobe.cz/poradna/cron) vyžaduje skript dostupný na doméně. Pro jeho URL plánovač je samostatný `mail-cron.php`, který při jednom volání zpracuje nejvýše tři zprávy. V terminálu vytvoř tajný klíč pomocí `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` a ulož jej pouze na serveru do Git ignorovaného `config/mail-cron.php`:

```php
<?php
return ['token' => 'SEM_VLOŽ_64_HEXADECIMÁLNÍCH_ZNAKŮ'];
```

V plánovači nastav každých pět minut **HTTPS** adresu `https://tvuj-obchod.cz/mail-cron.php?token=TVŮJ_KLÍČ`. Webglobe při založení úlohy ověřuje URL metodou HEAD: s platným klíčem skript odpoví bez odeslání zpráv, běžné spuštění GET pak frontu zpracuje. Skript ověřuje celý 64znakový klíč; neschvaluje požadavek jen podle IP adresy. Chraň URL v administraci hostingu a přístupových logách; při jejím vyzrazení vytvoř nový klíč a změň URL úlohy. `config/mail-cron.php` neukládej do Gitu. Bez konfigurace nebo s chybným klíčem se e-maily nezpracují.

Pokud proces skončí během odesílání nebo se ztratí konečné potvrzení SMTP po předání obsahu zprávy, řádek zůstane ve stavu `sending` a plánovač jej **automaticky neopakuje**. Po 10 minutách jej otevři v **Doklady a platby → E-maily** a ověř podle adresy, předmětu a času pokusu log poštovního serveru. Teprve podle potvrzeného výsledku označ zprávu jako přijatou, nebo povol nový pokus. Není-li výsledek dohledatelný, nech ji ve stavu `sending` a vyřeš doručení individuálně; bez ověření by další odeslání mohlo vytvořit duplicitu. Stav `sent` znamená přijetí poštovním serverem, nikoli jisté doručení do schránky.

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

Widget Zásilkovny **vyžaduje její 16znakový veřejný API klíč** a schválený účet; otevřený režim bez klíče Zásilkovna nenabízí. V klientské sekci najdi *API klíč* (nikoli API heslo) a vyplň jej v **Nastavení obchodu → Zásilkovna – mapa výdejních míst**. Klíč je záměrně viditelný ve stránce pro widget. Pokud veřejný klíč chybí, zapnutá doprava Zásilkovnou zůstane dostupná: zákazník otevře veřejnou mapu a název a adresu výdejního místa opíše ručně. Kód místa je v tomto režimu volitelný. Takto uložené místo není ověřené API; před pozdějším podáním přes API je nutné zadat ID a ověřit ho. Ostatní způsoby dopravy fungují dál. PHP musí umět navázat odchozí HTTPS spojení s `widget.packeta.com` (přes cURL nebo `allow_url_fopen`); mapa se načítá v zákazníkově prohlížeči. Při nedostupném ověřování objednávka Zásilkovnou nepokračuje, aby se neuložila neplatná pobočka. Pro ověření na XAMPP potřebuješ připojení k internetu a platný klíč; samotný test kódu `php tests/packeta-pickup.php` používá simulovanou odpověď.

Pro automatické podání použij v administraci **Databáze → Aktualizovat SQL tabulky** (přidá `shop_packeta_shipments`). V **Nastavení obchodu → Podávání zásilek** vyplň soukromé **API heslo** a **označení odesílatele**. V [seznamu odesílatelů v klientské sekci](https://client.packeta.com/senders/) zkopíruj přesně hodnotu *Označení* ze čtvrtého sloupce; do API se posílá jako `eshop`. Nejde nutně o název firmy. Pokud odesílatel chybí, nejprve ho tam vytvoř a vyčkej schválení účtu. Chyba `eshop_id: Není zadán odesilatel zásilky` značí, že Zásilkovna odeslanou hodnotu nepřiřadila k odesílateli. Heslo se nikdy nevypisuje zpět do formuláře; prázdné pole při dalším uložení ponechá současné heslo. Hosting musí mít PHP SimpleXML a odchozí HTTPS na `www.zasilkovna.cz`; funguje cURL nebo povolené `allow_url_fopen`.

V detailu zaplacené objednávky s dopravou Zásilkovnou zkontroluj jméno, e-mail a telefon příjemce a zadej hmotnost zabaleného balíku. Tlačítko **Vytvořit zásilku u Zásilkovny** odešle údaje pomocí `createPacket` s číslem objednávky, bez dobírky, a uloží vrácené číslo `Z…`. Lze stáhnout PDF štítek. U **Zásilkovna domů HD** se použije český dopravce `106`; před podáním zkontroluj rozdělenou ulici, číslo domu, město a PSČ. Údaje lze pro podání opravit bez přepsání původní objednávky. Po vytvoření zásilky vyžádej číslo dopravce tlačítkem a stáhni jeho přepravní štítek. Vytvoření zásilky samo nepřepne objednávku do stavu *Odesláno*; ten nastav až při předání balíku.

V detailu objednávky lze sledovat aktivní zásilku a před fyzickým předáním potvrdit její **storno u Zásilkovny**. Storno zásilky nemění objednávku ani platbu. Po potvrzeném stornu zůstane staré číslo v historii, případný stav *Připraveno k odeslání* se vrátí na *Připravuje se* a správce může opravit údaje pro nové podání, včetně ID výdejního místa. Změněné místo se před podáním ověřuje veřejným klíčem widgetu; u starší neověřené objednávky se ověří i původní ID. Pokud odpověď API není jistá, nové podání je zablokované: výsledek ověř v klientské sekci Zásilkovny a ručně potvrď, zda byla zásilka stornována, nebo zůstala aktivní. Bez fyzického předání lze objednávku vést jako *Připravuje se* či *Připraveno k odeslání*; po předání jako *Odesláno* a po doručení jako *Dokončeno*. Stav platby je samostatný. Seznam objednávek má filtry pro jednotlivé kroky a zobrazuje zvlášť stav vytvoření/storna zásilky; zákazník vidí stav i odkaz na sledování aktivní zásilky ve svém účtu. Pro storno nejprve spusť **Databáze → Aktualizovat SQL tabulky** (historie `shop_packeta_cancelled_shipments` a index stavů).

Pokud zásilku vyřizuje **externí dodavatel**, v panelu *Vyřízení* zvol tuto možnost, případně napiš je…7516 tokens truncated…orie hlavičky, `category_tabs` načítá přímé děti právě zobrazené kategorie, `utility` tvoří Blog a publikované stránky označené pro horní odkazy, `footer` obsahuje výchozí informační odkazy. Správa menu v administraci ukládá změny po jednotlivých jazycích do `navigation_menus`. Pokud tabulka nebo konkrétní řádek chybí, platí výchozí nastavení v `config/menus.php`; stará navigace se po aktualizaci neztratí.

### Správa kategorií a menu v administraci

Po přihlášení otevři **Kategorie** na `admin.php?section=categories`. Strom má celou šířku pracovní plochy; ukazuje počet publikovaných produktů včetně podkategorií a skutečnou viditelnost. Hledání zachovává potřebné nadřazené větve. Podkategorie lze sbalit. Celou větev přesuneš úchytem (myší i dotykem), šipkami nebo změnou čísla a tlačítkem **Uložit pořadí**. Přesun je mezi sourozenci stejného rodiče; mění pouze pořadí, nikoli stálé adresy či zařazení produktů. Číselné řazení funguje i bez JavaScriptu. Souběžnou změnu stromu server rozpozná a vyžádá obnovení stránky.

Skrytí rodiče skryje i jeho podkategorie v navigaci. Zboží zůstane dostupné v katalogu či nadřazené kategorii. Prázdné podkategorie se skrývají automaticky, aniž by se měnila uložená volba viditelnosti. Novou kategorii vytvoříš pod stromem nebo přes ikonu + u rodiče; názvy a viditelnost upravíš přes ikonu tužky.

**Menu** na `admin.php?section=menus` má tři kroky: **Kde se menu zobrazí**, **Z čeho menu vznikne** a **Vlastní odkazy**. Karty umístění ukazují hlavičku, podkategorie, horní odkazy a patičku. Vedle zdroje je přehled odkazů z uloženého nastavení; u kontextové lišty je uvedena ukázková kategorie. Změna formuláře ovlivní web až po uložení.

Zdroj **Kategorie** přebírá názvy, hierarchii a pořadí z katalogu. Zdroj **Stránky** čte společný výběr publikovaných stránek v daném jazyce; výběr a pořadí lze upravit přímo v libovolném menu s tímto zdrojem. Změna umístění stránky tvoří její novou revizi. Zdroj **Pouze vlastní odkazy** používá jen ručně sestavený seznam. Vlastní odkazy se u automatických zdrojů připojí za automatický obsah a přepnutím zdroje nezmizí. Pole cíle se přizpůsobují zvolenému typu. Vnoření nenabízí samotný odkaz ani jeho potomky. HTTPS odkaz se otevře v nové záložce; `mailto:` otevře poštovní aplikaci. Skrytou kategorii lze do menu připravit; veřejný odkaz se zobrazí až při její skutečné viditelnosti.

### Zálohy navigace a textů v JSON

V **Menu** a **Kategoriích** je stejná **Záloha menu a kategorií**: export všech umístění, vlastních odkazů, kategorií a voleb zařazení stránek ve všech nastavených jazycích. **Nastavení → Texty webu** a **Nastavení → E-maily** mají vlastní oddělené zálohy. Exportuje se uložené nastavení, nikoli rozepsaný formulář. Formát je `simple-store-settings`, `version: 1`, druh `navigation`, `site-texts` nebo `email-texts`.

1. Stáhni JSON jako zálohu před úpravami.
2. Pro obnovu vyber soubor `.json` do 2 MiB (nebo vlož JSON) a zobraz přehled nových, změněných a shodných položek. U změn lze porovnat původní a nové hodnoty. Náhled nic neukládá.
3. Potvrď **Importovat změny**. Import se uloží v jedné transakci; chyba vrátí všechny zápisy. Změní-li se od náhledu nastavení nebo soubor, je nutný nový náhled.

Menu se obnovuje podle jazyka a umístění; jeho vlastní odkazy se **nahradí seznamem ze zálohy**, nepřidávají se znovu. Kategorie se sloučí podle jazyka a stálé adresy, chybějící se doplní a kategorie mimo zálohu zůstanou zachované. Produkty, jejich varianty, historické revize a objednávky se nemění. Vložit tutéž zálohu opakovaně nevytváří duplicity ani další revize nezměněných stránek. Záloha neobsahuje obsah stránek; chybějící stránku je před obnovou jejího zařazení nutné obnovit samostatným importem stránek. Neexistující rodiče, cyklické odkazy, nepodporované jazyky a nebezpečné cíle se odmítnou před zápisem.

Texty webu se aktualizují podle jazyka a názvu pole; texty e-mailů podle šablony a společného popisku. Přenášejí se pouze uvedené texty. SMTP přihlašovací údaje, identity odesílatele, kontaktní adresy, vypínače automatického odesílání a již vytvořené zprávy se neexportují ani nepřepisují. Import neposílá e-maily. Povinné zástupné značky pro ceny a jiné údaje podléhají stejné kontrole jako běžný editor.

Správa používá existující tabulky; tato změna nepotřebuje novou migraci. Zápisy vyžadují přihlášeného správce a CSRF token. Náhled importu je navíc podepsaný pro konkrétní obsah a stav nastavení.

Kontroly: `php tests/category-editor.php`, `php tests/menu-definitions.php`, `php tests/configuration-backup.php`, databázový `php tests/configuration-backup-database.php` a `node --test tests/admin-structure-browser.mjs`. CI ověřuje MySQL i MariaDB, opakovaný import, vrácení částečné změny, ochranu produktů a tajných údajů, mobilní přetažení, číselné řazení i formulář bez JavaScriptu.

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

Tato podoba funguje pro **verzovaný obsah**. Objednávky mají vlastní trvalé číslo, náhodný přístupový token, snímky položek a ochranu proti dvojímu odeslání. Skladové rezervace a platební pokusy mají samostatné tabulky a pravidla stavových přechodů; před ostrým provozem projdi [plán přejímky](docs/audit-2026-10.md). Přidání dalšího jazyka vyžaduje přeložit i texty rozhraní; sloupec `language` počítá s dvoupísmenným kódem.

Po stažení této verze otevři **Databáze** v administraci a použij aktuální schéma. Rozšíří `shop_orders` pro objednávky a platby, přičemž starší řádky zachová. Kontroly bez databáze: `/opt/lampp/bin/php tests/admin-render.php`, `/opt/lampp/bin/php tests/schema-updater.php`, `/opt/lampp/bin/php tests/customer-account.php` a `/opt/lampp/bin/php tests/checkout-order.php`.

## Daňová evidence OSVČ

Po aktualizaci v **Administrace → Databáze** otevři **Daňová evidence OSVČ → Údaje OSVČ** a vyplň skutečné jméno, IČO, sídlo a e-mail odesílatele. Číslo účtu na vydané faktuře vychází ze snímku platebních údajů dané objednávky. Režim je určen pro OSVČ vedoucí daňovou evidenci, která **není plátcem DPH**; režim plátce a automatický výpočet přiznání nejsou implementované.

- **Pohyby peněz (peněžní deník):** stejné zápisy jako v Daňové evidenci, s podrobnými filtry, opravami a převody. Nová přesná párování Fio u nastavené OSVČ vytvářejí příjem automaticky (vypínatelné v Podnikatel a režim); ostatní skutečné příjmy a výdaje se doplňují podle podkladů. Zachovává se rozlišení banky/hotovosti a daňového zařazení. Roční CSV export zahrnuje i záznamy mimo zobrazených posledních 500 řádků. Chybný záznam lze opravit nebo vyřadit s důvodem a historií změny. Označení objednávky jako zaplacené nenahrazuje datum připsání peněz. Její skutečnou úhradu zapiš na detailu objednávky jen jednou.
- **Majetek a dluhy:** ručně vedené pohledávky, dluhy a majetek; nezaplacené objednávky se zobrazují odděleně. Konec roku vyžaduje kontrolu skutečných stavů.
- **Sklad a prodeje:** došlé kusy a inventurní rozdíly se zapisují ke klíči produktu, položky nové objednávky mají snímek počtu a ceny. Počítaný úbytek používá stav odesláno/dokončeno. Starší objednávky doplňuje opakovatelná akce po 100 objednávkách. Roční prodeje a skladové pohyby lze exportovat do CSV. Fyzický stav je nutné porovnat s evidencí.
- **Faktury:** na detailu zaplacené objednávky ověř odběratele a vystav fakturu z roční číselné řady. Tisková stránka má volbu pro uložení PDF v prohlížeči; zákazník ji uvidí přes svůj soukromý odkaz na objednávku. Faktura obsahuje snímky údajů z doby vystavení. Oprava čísla vyžaduje důvod, zachovává historii a nepovolí opětovné použití starého čísla. Již odeslaný e-mail se po opravě sám nepřepíše.
- **E-maily:** nákup vytvoří potvrzení s položkami, cenou dopravy a údaji k platbě; vystavení faktury vytvoří zprávu s jejím úplným textem. V **Nastavení obchodu → E-maily** nastav odesílatele, veřejnou HTTPS adresu obchodu, SMTP server, port, zabezpečení, přihlašovací jméno a heslo schránky. Bez SMTP se dál používá PHP `mail()` hostingu. Šablony událostí jsou upravitelné, fronta umožňuje opakování neúspěšného doručení. Tlačítkem **Odeslat testovací e-mail** ověř nastavení přímo se svou schránkou. Přijetí zprávy poštovním serverem ještě nezaručuje doručení do složky doručené pošty. Heslo se v databázi ukládá šifrovaně, klíč v `config/.smtp-key.php` vzniká při prvním uložení SMTP hesla a musí se zálohovat spolu s databází. PHP musí mít OpenSSL, webový proces musí mít právo zápisu do `config/` při vytvoření klíče. E-mail faktury neobsahuje PDF přílohu; PDF si lze vytisknout ze soukromé stránky objednávky.
- **Obchodní podmínky při nákupu:** po aktualizaci SQL tabulek se při vytvoření objednávky uloží kopie právě publikovaných podmínek v `shop_order_legal_snapshots`. V těle potvrzovacího e-mailu a zprávy o přijetí platby je jen odkaz na stránku; přesné znění platné při objednání je v samostatné textové příloze `obchodni-podminky.txt`. Zpráva ve frontě uchovává tuto přílohu pro bezpečné opakování odeslání. Pozdější editace stránky nepřepisuje starší objednávku; starým objednávkám systém nepřiděluje novou verzi podmínek. Odkazy se zobrazí jen pro zveřejněné stránky a při nastavené veřejné HTTPS adrese. Administrace → Nastavení obchodu → E-maily upozorňuje na chybějící zveřejnění, nevyplněné údaje `[DOPLNIT]`, příliš dlouhý dokument a chybějící URL. Před ostrým prodejem uprav skutečné právní údaje a stránky zveřejni.

### Reklamace a vrácení

Po aktualizaci SQL tabulek vzniknou `shop_after_sales_cases` a `shop_after_sales_events`. Zákazník otevře formulář ze soukromého detailu objednávky nebo ze svého účtu; přímý odkaz má také v e-mailu, pokud je nastavena veřejná HTTPS adresa. Reklamace ukládá položku, variantu, počet, popis vady a požadované řešení. Odstoupení od smlouvy má zvláštní potvrzovací krok, po kterém vznikne samostatný případ. Zákazník si může potvrzení s časem podání vytisknout a sledovat průběh. Účet ukáže i případ k dříve hostovské objednávce, kterou si zákazník přiřadil.

V **Administrace → Reklamace a vrácení** jsou otevřené případy, hledání, písemný výsledek, datum převzetí zboží, historie a ruční potvrzení již provedené vratky. Veřejné změny vytvářejí e-mail ve frontě i tehdy, když automatické stavové zprávy objednávek vypneš. Stav pošty kontroluj ve frontě; `sent` znamená přijetí poštovním serverem, nikoli prokázané přečtení zákazníkem. Skutečné peníze vracej v bance či platební bráně a odtok zaznamenej v peněžním deníku. Pokyny a adresu pro fyzické vrácení napiš zákazníkovi při změně stavu. Před prodejem doplň identifikační údaje prodávajícího v nastavení dokladů, veřejnou HTTPS adresu a vyzkoušej odesílání e-mailů.

- **Obnova hesla:** správce zadá vlastní přihlašovací e-mail na `/admin/forgot-password/`, zákazník na `/account/forgot-password/`. Každý dostane odkaz pouze ke svému účtu; zpráva formuláře neprozradí existenci adresy. Nastav odesílatele a veřejnou HTTPS adresu skutečné instalace (bez `/cs`) v Nastavení obchodu → E-maily. Společný kontaktní e-mail je určen oznámením obchodu, přihlašovací adresy správců se mění ve správě účtů. Obnovovací odkaz platí 30 minut, nejvýše jedna žádost za pět minut, a po použití či změně hesla zanikne. Záložní CLI obnova konkrétního správce: `php tools/admin.php --reset --email=tvuj@email.cz`. Dalšího správce lze také ručně přenastavit z jiného administrátorského účtu bez funkčního SMTP.

Starou testovací objednávku lze v administraci smazat; úplný jednorázový reset testovacích obchodních dat je popsán v [auditu](docs/audit-2026-10.md). Skutečnou objednávku lze odstranit z provozního seznamu i po zaplacení, vystavení faktury nebo předání dopravci. Vydaný doklad zůstane v evidenci faktur, peněžní zápis a rozpis zaplacených položek v daňové evidenci; u Comgate, GoPay a BTCPay zůstane pokus o platbu a jeho ověřený stav pro případnou pozdější notifikaci. Číslo zásilky a původní stav zůstávají v interní databázi pro dohledání u dopravce. Smazání objednávky **nestornuje platbu ani skutečnou zásilku**. Probíhající nebo nejisté založení platby u brány i nejasné podání/storno u Zásilkovny musí správce nejprve vyjasnit, aby se neztratilo spárování transakce nebo číslo zásilky. Před použitím této funkce aktualizuj SQL tabulky.

# Sklad produktů

**Varianty a hromadný start:** v **Nastavení obchodu → Produkty → Hromadné vytvoření variant** lze z uložených velikostí, barev a dalších voleb vytvořit kombinace se stejným počátečním počtem kusů (např. 10 každé). Nejdřív se zobrazí náhled; existující varianty se zachovají. [Postup, sklad a převod produktů](docs/product-variants.md).

**Prodávající:** profily mají samostatný web a zálohu JSON s náhledem importu. Opakovaný import zachovává místní identitu i přiřazené produkty; starší objednávky nemění. [Postup a připravené profily Pod 7 kilo, HUDY a Alzy](docs/purchase-partners.md).

Po aktualizaci SQL tabulek v administraci otevři produkt a klikni na **Upravit tento produkt přímo na stránce**. V části **Volné kusy skladem** nastav ověřený počet a ulož jej. V přehledu všech produktů se tento počet zobrazuje správci, zákazník vidí jen „Skladem“ nebo „Není skladem“; konkrétní počet není veřejně vypisován. Nové i stávající produkty začínají na **0 kusech** a je potřeba je před prodejem naplnit. Stav „Na objednávku“ dovoluje objednávat i při nule, ruční „Není skladem“ prodej blokuje.

U produktu bez skladu variant patří počet produktu jako celku a sdílí ho všechny jazykové verze i jeho volby. Po zapnutí skladu variant má každá kombinace vlastní počet sdílený napříč překlady. Objednávka odečte kusy v jediné databázové transakci; současné objednávky tak nemohou prodat stejný poslední kus. Storno dosud neodeslané objednávky je vrátí, opětovné otevření zrušené objednávky je musí znovu zajistit. Při vlastní expedici se po odeslání rezervace označí jako spotřebovaná a pozdější smazání objednávky již počet automaticky nenavyšuje. Přepnutí na externího dodavatele uvolní vlastní kusy; návrat k vlastní expedici je znovu rezervuje a při nedostatku kusů odmítne změnu. Ruční přepsání počtu v editoru aktualizuje dostupné kusy a odmítne uložení, pokud se mezitím změnily.

Evidence pohybů v účetní části má jiný účel než provozní počet volných kusů. Pokud do ní zapíšeš příjem nebo výdej, uprav dostupný počet i v produktu; samotný účetní zápis jej nepřepíše.

## Správa objednávek

Po nasazení otevři **Administrace → Databáze → Aktualizovat SQL tabulky**. Přehled objednávek ukazuje samostatně stav platby a vyřízení, způsob platby, dopravu, číslo vystavené faktury a celkovou částku. Lze hledat podle čísla objednávky, e-mailu nebo variabilního symbolu a filtrovat podle stavu a způsobu platby. U jednotlivých objednávek jsou běžné stavové akce a potvrzení bankovního převodu dostupné přímo ze seznamu; potvrzení převodu vždy vyžaduje ruční kontrolu bankovního výpisu. Vrácená platba přes GoPay nesmí být nabízena k expedici.

Detail obsahuje položky s obrázky a odkazy na aktuální produkty, zkopírovatelné údaje pro dopravce a externího dodavatele a přímý odkaz na vystavenou fakturu. Formuláře k dopravcům, opravám a zásahům jsou sbalené, dokud je správce nepotřebuje. Stav „Přijata“ znamená přijetí objednávky; „Připravena k odeslání“ připravený balík a „Předána dopravci“ skutečné předání. Vytvoření podkladů nebo štítku samo o sobě neznamená předání.

Dopravce lze před odesláním změnit na některého zapnutého dopravce doručujícího domů. U původní objednávky na výdejní místo je nutné vyplnit a potvrdit ověřenou adresu zákazníka. Původně sjednaná metoda, cena dopravy a vystavená faktura zůstávají součástí neměnného nákupního dokladu; změna platí jen pro skutečné podání. Aktivní zásilku u Zásilkovny je třeba před přesměrováním vyřešit a podklady k jinému dopravci před změnou výslovně ověřit.

Správce může trvale odstranit objednávku ze seznamu i po platbě nebo expedici. Smazání v obchodě nestornuje platbu ani fyzicky podanou zásilku. U skutečných obchodů zůstává vystavená faktura, údaje o transakci, nutná položková účetní evidence a minimální reference na zásilku; testovací objednávky lze odstranit bez těchto účetních podkladů. Sekce „Nedávno smazané objednávky“ se v běžném přehledu nezobrazuje.

## Import produktů z AI

Administrace → **Import a export** nabízí výběr produktů podle kategorií a jazyka, hromadný JSON export/import a obnovu produktových dat, stručný seznam názvů a adres pro AI i jednotlivý JSON nebo ZIP se složkami produktů (`product.json` a `images/`), včetně variant, parametrů, obsahových bloků a soukromých dodavatelů. ZIP zpracuje produkty postupně a vadné přeskočí; fotografie ukládá do vlastní knihovny s miniaturami. Galerie nemá limit 12 obrázků. Detail produktu nabízí **JSON pro AI** pro bezpečnou úpravu aktuální revize. [Formát a postup](docs/product-import.md).

### Featured blog articles in the catalog

The “Kam dál?” panel includes up to three published article cards above the category links.
Until an administrator customizes the selection, it uses the three latest published posts.
In the storefront article editor, use “Zobrazit v Kam dál?” to add an article. The panel's
“Vybrat články do tohoto boxu” controls reorder or remove selected articles. Removing all
articles leaves only the category links. Drafts and deleted articles are never shown publicly.
Cards use the first image block as their cover and link to the article; the heading links to the blog.
The language-specific selection is stored in `shop_catalog_post_selections`, created by the schema
or on the first authenticated selection change. No manual migration is required for this feature.

### Category visibility and empty folders

“Zobrazovat na webu” controls category navigation, not the products assigned to it.
Hidden folders remain selectable in the product editor, bulk JSON/ZIP import and category administration.
Published products in hidden folders remain in the parent catalog and the full catalog; their links
and breadcrumbs use the nearest visible ancestor. Hiding a parent also hides its navigation subtree.
Empty subcategories are automatically omitted from generated and category-targeted manual menus.
Counts include current published products in every descendant, regardless of stock or folder visibility.
This never changes the saved visibility switch: enabled folders reappear after a product is published,
while manually hidden folders stay hidden. Root sections are not automatically hidden for emptiness.
The category administrator shows an eye icon and distinguishes manual hiding from automatic empty hiding.

### Přehlednost dokladů a plateb

Navigace rozlišuje každodenní práci, uzavření roku a nastavení. Restart historie je pod Údržbou v nastavení evidence; původní adresy zůstávají funkční. Číselné řady mají přehled výchozích řad pro každý druh, sbalené úpravy, průvodce zkratka/rok/počet číslic a živý náhled. Náhled nic nerezervuje. Existující vlastní formáty se otevřením nemění a formulář funguje i bez JavaScriptu. Hledání ve společné evidenci dohledá platbu také podle propojeného čísla faktury či výpisu.

Administrace má antracitové menu, téměř černý text a ploché bílé karty s jasnými rámečky bez stínování. Horizontální navigace používá větší ikonky ve samostatných barevných políčkách. Seznam objednávek spojuje zákazníka, platbu s fakturou, doručení s vyřízením a přímé akce do čtyř sloupců; na menších obrazovkách se mění na karty. Potvrzení platby, storno i změna stavu jsou dostupné bez rozbalování, s původními povinnými potvrzeními a kontrolami. Časy v objednávkách se zobrazují jako den, měsíc, rok a hodina v pásmu Europe/Prague; databáze i vyhodnocení splatnosti zůstávají v UTC. Detail zvýrazňuje objednané varianty a umožňuje kopírovat jednotlivé kontaktní a doručovací údaje bez názvů polí. Horizontální záložky při najetí zvýrazňují text a spodní linku bez změny pozadí. Barevné ikonky, záložky a ovládací prvky v `admin-layout.css` rozlišují prodej, obsah, finance a nastavení. Barvy navigace jsou navázané na klíče sekcí, nikoli jejich pořadí; zelená zůstává pro úspěšné stavy a příjmy. Vzhled zákaznického webu je oddělený. Příjmy Fio a jejich omezení popisuje [docs/fio.md](docs/fio.md). Program není účetnictví s.r.o., nepřipravuje DPH ani automatické přiznání a neodhaduje neznámé výdaje, inventuru či skutečné datum dodání.
