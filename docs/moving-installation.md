# Doména a přesun instalace

**Nastavení obchodu → Doména a přesun → Hlavní adresa obchodu** umožňuje jedním polem a tlačítkem **Uložit adresu pro celý obchod** změnit všechny odvozené adresy najednou. Jednotlivé návratové adresy se neupravují. Změna začne platit ihned; ulož ji až při skutečném přesunu. Administrace zůstane po uložení na dosavadní adrese, aby šlo případný překlep opravit.

Veřejná adresa obchodu má jeden zdroj: **`config/installation.json`**. Platí pro PHP aplikaci i externí plánovač cen partnerů. Adresa se neodvozuje z HTTP Host a staré hodnoty v databázových nastaveních e-mailů, Google nebo plateb ji nepřepisují. Databáze se kvůli přesunu nemusí měnit.

Při prvním nasazení této verze vytvoří FTP nasazovač soubor z `config/installation.example.json`, pokud ještě neexistuje. Při dalších nasazeních jej zachová. Soubor je v `.gitignore`, mimo release manifest a chráněný adresářem `config/`. Při ruční instalaci jej zkopíruj z příkladu. Bez místního souboru PHP použije příklad; v produkci proto vždy ponech vlastní soubor.

Aktuální výchozí konfigurace zachovává původní adresu:

```json
{
  "public_base_url": "https://eshop.dobrodruzi.cz",
  "previous_base_urls": ["https://eshop.dobrodruzi.cz"]
}
```

Při přesunu na hlavní doménu změníš **jedinou hodnotu**:

```json
{
  "public_base_url": "https://dobrodruzi.cz",
  "previous_base_urls": ["https://eshop.dobrodruzi.cz"]
}
```

Další příklady hodnoty `public_base_url`:

| Umístění | Hodnota |
| --- | --- |
| Jiná poddoména | `https://obchod.dobrodruzi.cz` |
| Podsložka | `https://dobrodruzi.cz/eshop` |
| Vnořené složky | `https://example.cz/obchod/outdoor` |

Nepřidávej `/cs`, `/index.php`, parametry ani fragment. Používej HTTPS; HTTP je povolené jen pro localhost, 127.0.0.1 a ::1 při místním testování. Podsložky mohou mít písmena ASCII, číslice, pomlčku a podtržítko. IDN domény zadej jako ASCII/Punycode. Chybný JSON nebo adresa zastaví aplikaci s HTTP 503; oprav soubor přes FTP, ne hesla ani databázi.

`previous_base_urls` označuje pouze dřívější adresy **této aplikace**, ne všechny weby stejné značky. Administrace do seznamu automaticky doplní právě opouštěnou adresu. Při ruční změně souboru ji doplň sám. Nedoplňuj adresu expedic, pokud tam obchod ještě nebyl. Při zobrazování textů a ručních odkazů menu se staré absolutní interní odkazy přepočítají, stejně jako uložená cesta ke stránce podmínek v pokladně. Parametry variant a kotvy zůstanou zachované. Odkazy na jiné e-shopy zůstávají původní. Nejde o hromadný přepis databáze ani o HTTP přesměrování staré domény.

## Co se přizpůsobí

- Canonical odkazy, Open Graph, strukturovaná produktová data a sitemap vycházejí ze společné adresy.
- E-maily, obnova hesla a odkazy k objednávkám používají aktuální adresu. Při odesílání dříve vytvořené fronty se převedou známé staré interní odkazy v těle zprávy. Uložený záznam a původní právní příloha se nemění.
- Google callback i návraty a oznámení plateb mají odvozené adresy. API klíče, bankovní účty, SMTP a identifikátory klientů se nemění.
- Plánovač partnerů si načte stejný JSON přes stávající FTP spojení a sestaví adresu workeru. Nevyžaduje další proměnnou s doménou. HTTP přesměrování nesleduje, aby neposílal přístupový klíč na jiný server.
- Navigace, formuláře, obrázky, skripty, zákaznické a administrátorské relace používají instalační cestu odvozenou z `SCRIPT_NAME`. Musí odpovídat podsložce v konfiguraci. Přehled **Nastavení obchodu → Doména a přesun** upozorní na neshodu; SEO metadata se při ní nevydávají a sitemap vrací 503.

## Bezpečný postup skutečného přesunu

1. Zálohuj databázi a celou instalaci. Pokud hlavní doména již hostí jiný web, připrav mu samostatné umístění. Dvě aplikace nemohou současně obsluhovat stejný kořen URL.
2. Připrav DNS, HTTPS certifikát a document root nové adresy. Samotné přejmenování složky stačí jen tehdy, když tomu odpovídá konfigurace hostingu. Po Laravelu bývá document root nastavený na `www/public`; simple-store má `index.php` přímo v kořeni, takže cílem musí být například `www`. Pokud server vrací Not Found i pro `/index.php`, nejprve ověř tento kořen a přítomnost souboru. V podsložce musí nadřazený web předat její požadavky této aplikaci; nekopíruj jeho Laravel `.htaccess` přes soubor obchodu.
3. Při přenosu zachovej `.htaccess`, všechny místní konfigurace a klíče v `config/`, zejména `.smtp-key.php`, připojení k databázi, `vendor/` a nahrané soubory `images/media/`. SMTP a Google tajné údaje bez původního šifrovacího klíče nepůjdou rozšifrovat.
4. V okamžiku přepnutí ulož hlavní adresu v administraci, nebo ručně uprav `public_base_url`. SQL adresy se nehledají ani hromadně nenahrazují. Nepouštěj současně dvě nezávisle zapisující testovací/produkční kopie nad stejnou databází; měly by společnou frontu, sklad i objednávky.
5. Ověř FTP účet pro automatické nasazení: jeho kořen musí dál obsahovat `index.php`, `.htaccess` a `config/database.php` této aplikace. Účet omezený na původní adresář může po přejmenování potřebovat změnu cílové složky u poskytovatele. Totéž platí pro plánovač partnerů využívající stejné spojení.
6. U aktivních externích integrací uprav schválené adresy: Google Authorized redirect URIs; Comgate PUSH; BTCPay webhook; omezení domény u dopravců. GoPay předává URL při založení platby. Přesné aktuální hodnoty jsou v nové záložce administrace. Cron úlohy nastavené přímo u hostingu přepni na novou adresu a zachovej jejich autorizaci.
7. Před odstraněním původní adresy ověř rozpracované platby. Brána může posílat oznámení na URL uloženou při vytvoření platby. Zajisti po přechodnou dobu správné zpracování původních callbacků; nespoléhej na slepé 301 přesměrování POST požadavků. Veřejné GET stránky pak přesměruj jednotlivě na odpovídající nové adresy, ne všechny na titulní stránku.
8. V podsložce přidej pravidla a novou sitemap do **kořenového `/robots.txt` celé domény**; nesmí zničit pravidla druhého webu. Doplň novou adresu do nástrojů vyhledávačů podle jejich možností. Zachovej původní veřejné odkazy přesměrováním i pro již odeslané e-maily.
9. Ověř titulní stránku, produkt s variantou, obrázky, blog, administraci, registraci/přihlášení, obnovu hesla, košík, testovací platbu, e-mailovou frontu a běh kontrol partnerů. Cookies staré domény se nepřenášejí: zákazníci i správci se přihlásí znovu a původní anonymní košík se na novém hostu automaticky neobjeví.

Při návratu na původní umístění vrať soubory a původní adresu ve stejné konfiguraci, včetně externích nastavení. Protože není potřeba migrovat data ani měnit jejich klíče, objednávky a účty zůstávají ve stejné databázi. Tato aktualizace sama žádný přesun, DNS změnu ani přesměrování nezapíná.

## Vývoj a testování

`SIMPLE_STORE_INSTALLATION_FILE` může vybrat jiný soubor JSON pro izolovaný CLI/HTTP test. Není to druhý zdroj URL. Dřívější `SIMPLE_STORE_PUBLIC_BASE_URL` a vlastní `public_base_url` v `config/site.php` již nejsou použité. Pro běžný hosting ponech proměnnou nenastavenou a uprav pouze místní `config/installation.json`.

Kontroly: `php tests/installation.php`, `python3 tests/installation.py`, `php tests/checkout-settings.php`, `python3 tests/deployment.py`, `python3 tests/supplier-worker.py`. Úplná CI ověřuje i databáze, platební integrace a panelové směrování v kořeni i podsložce.

Uložení z administrace vyžaduje přihlášeného správce, platný CSRF token a aktuální revizi konfigurace. Soubor se vymění atomicky pod zámkem; otevřený starý formulář nepřepíše novější nastavení. Pokud hosting nepovolí zápis do složky `config`, zobrazí se chyba a původní konfigurace zůstane zachovaná. Uprav oprávnění vlastníka složky v hostingu nebo soubor přes FTP; nepovoluj veřejný zápis.
