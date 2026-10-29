# Comgate: příprava, testování a spuštění

Stav ověřený proti oficiální dokumentaci 29. 10. 2026. Integrace používá REST API v2.0, přesměrování na hostovanou bránu a CZK. Obchod nepřijímá čísla karet ani CVC. Automatické testy se simulovaným API nejsou potvrzením skutečného propojení s Comgate.

## Co je potřeba před aktivací

1. Pošlete poptávku pro skutečnou doménu obchodu. Comgate následně řeší smlouvu, ověření totožnosti a bankovního účtu a technické propojení. Připravte identifikační a kontaktní údaje, IČO, účet a popis podnikání. U online sjednání požadují také doklady totožnosti, doklad k účtu a identifikační platbu.
2. Na veřejném webu musí být úplné údaje provozovatele, adresa, e-mail a telefon; popisy a ceny produktů; funkční objednání; obchodní, reklamační, dodací a platební podmínky a ochrana osobních údajů. Zástupné texty typu `[doplnit]` před schválením odstraňte. HTTPS musí fungovat i na platebních endpointech.
3. **Popište asistovaný nákup pravdivě:** zákazník platí cenu zboží partnerského prodejce i odměnu za službu, zatímco zboží dodává jiný subjekt. Vyžádejte si výslovné přijetí tohoto modelu a inkasa celé částky. Kategorie `OTHER` v API není smluvním schválením modelu. Tato integrace nerozděluje výplatu mezi více obchodníků.
4. Publikovaný postup neuvádí povinné odevzdání zdrojového kódu. Připravte dostupný web a výsledky zkoušek; Comgate může individuálně požadovat další vysvětlení. Schválení poskytovatele nelze zaručit kontrolou kódu.

[Oficiální požadavky a sjednání](https://help.comgate.eu/docs/jak-ziskat-platebni-branu) · [Vlastní řešení](https://help.comgate.eu/docs/reseni-na-miru)

## Nastavení propojení

V e-shopu otevřete **Nastavení → Platby → Comgate** (`/admin/settings/?tab=payment&area=comgate`).

| Nastavení | Hodnota / kde ji získat |
| --- | --- |
| Merchant | ID **propojení obchodu**, nikoli automaticky Merchant ID organizace. Comgate: Integrace → Nastavení obchodu → Propojení obchodu. |
| Secret | Heslo stejného propojení; uložit jen do administrace, nikdy do Gitu. Prázdné pole zachová existující klíč. |
| Adresa instalace | Odvozuje se z `config/installation.json`; zde se samostatně nemění. |
| PUSH | Pro nynější doménu `https://dobrodruzi.cz/comgate-callback.php`. Přesnou adresu s případnou podsložkou ukazuje administrace. |
| PAID, PENDING, CANCELLED | Vyplňte všechna tři výchozí pole v portálu, např. `https://dobrodruzi.cz/cs`. API je u každé platby přepíše bezpečným návratem s tokenem na objednávku. PUSH patří do samostatného pole. |
| Povolené IP | Všechny veřejné **odchozí IPv4 adresy PHP hostingu**. Zjistit u Webglobe; adresa domény z DNS nemusí odpovídat. |
| Režim | Nejdřív zapnuté testování. Vlastní přístupy jsou potřeba i při simulaci. |

Hosting potřebuje PHP s cURL, důvěryhodné certifikáty a spojení na `payments.comgate.cz:443`. Callback musí přijímat JSON POST bez přihlášení, CAPTCHA a přesměrování. Běžný GET na callback vrací **405**; návrat bez platného tokenu **404**. Nejde o chybu instalace. V administraci Databáze musí být aktuální schéma včetně `shop_comgate_payments`.

[Oficiální FAQ k přístupům, URL a IP](https://apidoc.comgate.cz/faq/)

## Jak testovat po získání přístupů

Comgate má skutečný testovací režim s virtuální bankou, ve kterém se peníze nestrhávají. Ve veřejné dokumentaci není uvedeno univerzální anonymní merchant/secret. Testovací logy zpřístupňuje role **Tester**; po změně oprávnění se znovu přihlaste do portálu.

V obchodě se testovací metoda nabízí pouze přihlášenému správci mimo náhled návštěvníka. Použijte vlastní e-mail: potvrzení o založení objednávky se může normálně odeslat. Objednávka také rezervuje zboží.

| Zkouška | Očekávaný výsledek v e-shopu |
| --- | --- |
| Úspěšná simulace | Detail ukáže ověřené `paid · TEST`; skutečná úhrada zůstane čekající, bez faktury a expedice. |
| Zavření okna po zaplacení | Stejný výsledek doručí PUSH bez návratu zákazníka. |
| Zrušení | Pokus `cancelled`; objednávka není zaplacena, správce může zkusit novou testovací platbu. |
| Nedokončená platba | `pending`; návrat nesmí sám potvrdit úhradu. |
| Opakované otevření / notifikace | Nevznikne další aktivní transakce ani opakované potvrzení úhrady. |
| Chyba spojení při založení | `uncertain`; další založení je zablokované do vyjasnění, aby nevznikla dvojí platba. |

V Integrace → Testování porovnejte číslo objednávky, cenu a ID transakce. U PAID/PENDING má log obsahovat požadavek a odpověď založení i ověření stavu. Po zkoušce objednávky v e-shopu **stornujte**, aby se uvolnily rezervace. Přepnutí konfigurace na produkci nezmění uložené testovací pokusy; pro ostrou platbu vytvořte novou objednávku.

[Testovací platby a portál](https://help.comgate.eu/docs/navod-k-pouziti)

## Před ostrým provozem

- Vyčkejte na povolení Comgate; nevypínejte test jen proto, že je integrace hotová.
- Doplňte oficiální loga Visa a Mastercard do patičky a informace o Comgate do Doprava a platba. Zobrazujte jen skutečně povolené metody. [Oficiální loga a pokyny](https://help.comgate.eu/docs/loga-a-udaje-na-webu).
- Vypněte testování, proveďte malou skutečnou platbu a ověřte PUSH, zaplacení, účetní podklady a následnou refundaci.
- **Refundace se zadává v portálu Comgate.** Storno objednávky nebo záznam vratky v evidenci samo neposílá peníze zpět. Tato integrace nemá automatické API refundace ani přenos jejich výsledků; skutečné vrácení doložte a zaevidujte ručně.

## Ochrany a řešení chyb

Platba vychází z uložené objednávky v databázi, částka se posílá v haléřích. Čistý prodej vlastního zboží používá `PHYSICAL_GOODS_ONLY`; přítomnost asistované služby `OTHER`. Návrat zákazníka není důkaz zaplacení. PUSH ověřuje secret a následně server samostatně načte stav: kontroluje transakci, číslo objednávky, částku, CZK a režim uloženého pokusu. `AUTHORIZED` není úhrada. Ověřené skutečné PAID se nevrací zpět při opožděné CANCELLED notifikaci.

Callback potvrzuje HTTP 200 až po ověření a uložení. Dočasná chyba vrací 503, aby Comgate oznámení zopakovala. Neplatné údaje vrací 400. API má časové limity, limit odpovědi 64 KiB a nepovoluje libovolná přesměrování. Technické chyby se zapisují do serverového logu bez secretu.

U stavu `creating/uncertain` vyhledejte transakci v portálu podle čísla objednávky. Pokud chybí místní ID transakce, je nutné technické dořešení a bezpečné spárování; neodemykejte nový pokus pouhou změnou stavu v SQL. Vypnutí nabídky nových plateb nezastaví ověřování existujících transakcí. Při změně domény aktualizujte také propojení v Comgate; staré vytvořené návraty potřebují po přechodnou dobu původní endpointy.

## Ověření bez účtu

```sh
php tests/comgate.php
php tests/checkout-render.php
php tests/admin-render.php
# Pouze v izolované testovací databázi, nikdy proti živému obchodu:
MYSQL_TEST_PASSWORD=... php tests/comgate-database.php
```

CI ověřuje skutečné uložení a návaznosti objednávek na MySQL i MariaDB s falešným API: opakování, zrušení a pozdní úhradu, nesouhlas částky/měny/režimu, podvržený callback, nejasné založení, oddělení simulace i úhradu po smazání objednávky. Tyto kontroly nenahrazují test s vlastními údaji u Comgate.

[REST API](https://apidoc.comgate.cz/api/rest/) · [PUSH](https://apidoc.comgate.cz/push-notifikace/)
