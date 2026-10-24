# Evidence OSVČ a asistovaný nákup

Stav implementace a ověření: 24. 10. 2026. Modul je určen pro OSVČ neplátce DPH. Vychází ze skutečných pohybů a jejich podkladů; nepotvrzuje zaplacení pouze podle stavu objednávky. Nesestavuje daňové přiznání, přehledy pojistného ani evidenci DPH. Před aktualizací zálohuj databázi a potom použij **Administrace → Databáze → aktualizace SQL**.

## Běžná práce

- **Peněžní deník:** datum, účet, účel, daňové zařazení, částka s haléři, protistrana, reference a volitelná objednávka. Hledání, filtry, oddělené příjmy/výdaje, součty výběru a CSV všech vyfiltrovaných řádků. Účty: banka, hotovost, platební brána, krypto, nepeněžní plnění.
- **Platby a asistovaný nákup:** vyhledej objednávku, zapiš skutečný příjem, platbu dodavateli nebo vratku. Vlastní část a svěřené peníze lze zadat jedním formulářem i při částečné platbě. Dostupné jsou i dosud nezaplacené a zrušené objednávky, protože skutečný pohyb se musí dát doložit bez ohledu na provozní stav. Zápis nenastavuje objednávku jako zaplacenou a neodesílá peníze.
- **Majetek a dluhy:** otevřená pohledávka, závazek nebo karta majetku, reference, množství, poznámka k ocenění a odpisům. Lze upravit, uzavřít, znovu otevřít, vyřadit a obnovit. U částečné úhrady uprav zbývající částku a do důvodu uveď vazbu na zápis platby. Roční stav uchovej zvlášť v inventuře.
- **Inventura a uzávěrka:** záznam skutečného stavu a hodnoty, evidenční hodnota, rozdíl a podklad ocenění. Vlastní zásoby se neplní z nabídky partnerského zboží. Nepeněžní úpravy příjmů/výdajů (například daňový odpis) se vedou samostatně; inventura ani uzávěrkový záznam nevytvářejí fiktivní peněžní pohyb. Záznam inventury obsahuje datum zjištění; pro roční inventuru zadej poslední den příslušného období.
- **Faktury:** původní doklady si zachovávají snímek údajů. Lze vystavit samostatné snížení ceny / opravu neplátce DPH s důvodem, částkou a odkazem na původní fakturu. Opravy v součtu nepřekročí její vlastní plnění. Chybný opravný doklad lze vyřadit s důvodem a vystavit nový; zůstává dohledatelný. Tisk / PDF se provádí prohlížečem. Opravu předej zákazníkovi, systém ji sám neposílá.

Uložení oprav zachovává původní údaje a správce v historii. Verze chrání před přepsáním mezitím změněného záznamu. Nové formuláře mají jednorázový identifikátor uložený ve stejné databázové transakci; dvojí odeslání téhož formuláře nevytvoří druhou platbu. Nejde o automatické rozpoznání stejné bankovní transakce vložené z jiného formuláře: reference stále kontroluj.

## Příklad 1 800 Kč = zboží 1 678 Kč + služba 122 Kč

Toto členění předpokládá skutečné jednání **jménem a na účet zákazníka** a doložené oddělení jeho peněz. Samotná volba v administraci takový vztah nevytváří. Při vlastním nákupu a následném prodeji se eviduje vlastní obchod, nikoli pouze marže.

| Skutečný pohyb | Účel v deníku | Daňové zařazení | Kč |
| --- | --- | --- | ---: |
| Přijato od zákazníka za službu | Úhrada vlastního prodeje / služby | Zdanitelný příjem | 122 |
| Přijato na nákup za zákazníka | Přijetí svěřených peněz | Nedaňový příjem | 1 678 |
| Zaplaceno partnerovi | Platba dodavateli za zákazníka | Nedaňový výdaj | 1 678 |
| Partner vrátil celou kupní cenu | Dodavatel vrátil svěřené peníze | Nedaňový příjem | 1 678 |
| Kupní cena vrácena zákazníkovi | Vrácení svěřených peněz zákazníkovi | Nedaňový výdaj | 1 678 |
| Vrácena vlastní odměna | Vrácení vlastní tržby | Vrácení zdanitelného příjmu | 122 |

Vlastní faktura je na 122 Kč (plus případná vlastní doprava a vlastní zboží). Doklad partnera vystavený zákazníkovi předej zákazníkovi; eviduj jeho referenci. Svěřené peníze se nesmějí současně uplatnit jako daňový nákup vlastních zásob. Jiná skutečná cena partnera neznamená automatické zvýšení odměny: rozdíl vypořádej podle smlouvy a dolož.

Zůstatek svěřených peněz = přijaté svěřené peníze + vratky dodavatelů − platby dodavatelům − vratky svěřených peněz zákazníkovi. Vyúčtování ukazuje celou historii objednávky. Záporný zůstatek upozorní například na předfinancování nebo chybějící zápis, ale neblokuje zaznamenání skutečnosti. Při více dodavatelích zapisuj jejich platby jednotlivě s protistranou a referencí.

**Zůstatky k 31. 12. · CSV** rekonstruují stav z pohybů až do konce zvoleného roku, včetně předchozích let a smazaných objednávek. Není to pouze součet pohybů daného roku. Svěřené pohyby propojuj s objednávkou; nepřiřazené záznamy je nutné jednotlivě dohledat v deníku. Průběžné vyúčtování lze vytisknout / uložit do PDF; obsahuje aktuální stav evidence, nikoli neměnný vystavený daňový doklad.

## Brány, BTC a vratky

U brány zapiš hrubý přijatý příjem na účet „Platební brána“ podle podkladů a okamžiku, kdy jde o tvůj příjem, zvlášť poplatek a pak vlastní převod čisté výplaty z brány na banku. Výplatu znovu nezapisuj jako tržbu. Souhrnná výplata může zahrnovat více objednávek. Převod vytvoří atomicky dva nedaňové zápisy; případné pozdější opravy obou stran je třeba sladit podle výpisu.

U BTC / jiné měny deník uloží původní množství až na osm desetinných míst a doložené ocenění v Kč. Nepoužívá orientační aktuální kurz z cenovky. Rozdělenou vlastní a svěřenou část zapiš odděleně v deníku se správným účelem a odpovídajícím množstvím každé části. Pozdější směna, úbytek a daňová pořizovací hodnota krypta vyžadují vlastní podklady a samostatný zápis či uzávěrkovou úpravu. Program nepočítá automaticky kryptoměnové daňové výnosy, pořizovací ceny ani osvobození.

Potvrzené vratky z reklamací jsou ve vyúčtování vidět pro kontrolu. Automaticky nepřepisují deník, protože jejich provozní záznam nemusí obsahovat datum skutečné platby a správný rozpad vlastní/svěřené části. Zapiš vratku jednou podle výpisu, s referencí případu. Opravný doklad a bankovní vratka jsou dvě různé skutečnosti; vystavení dokladu samo neprokazuje úhradu. U vratky z minulého roku a při změně daňového režimu ověř dopad v příslušném roce; automatické procentní výdaje se z této tabulky nepočítají.

## Roční kontrola a uchování

1. Porovnej kompletní deník s výpisy banky, bran, pokladnou a doklady. Zahrň i ostatní podnikatelské činnosti mimo tento e-shop.
2. Zkontroluj otevřené pohledávky, závazky, svěřené peníze a majetek; zůstatky banky, hotovosti a krypta dolož výpisem / inventurou, včetně počátečních stavů.
3. Zapiš skutečné roční stavy zásob, hmotného majetku, pohledávek a dluhů. Ocenění, odpisy a daňový dopad rozdílů stanov podle konkrétních podkladů; běžná dostupnost produktu není daňová inventura.
4. Případné daňové úpravy uveď v uzávěrce s důvodem a referencí. Souhrn peněžního deníku je vykazuje odděleně; není úplným výpočtem základu daně.
5. Exportuj deník, evidenci majetku/dluhů, inventuru/úpravy a svěřené zůstatky. Zálohuj i databázi s historií a snímky dokladů.
6. Uchovávej originály faktur, smluv, pověření, partnerových dokladů, výpisů a podkladů ocenění. Zdejší formuláře ukládají reference a poznámky, nikoli soubory těchto originálů. Evidence se uchovává pro období, u kterých dosud neuplynula lhůta pro stanovení daně; nelze obecně automaticky mazat vše po třech letech.

## Ověřené podklady a hranice

- [Finanční správa – Podnikatel (OSVČ)](https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/fyzicke-osoby/podnikatel-osvc): evidence příjmů/výdajů, majetku a dluhů, rozdíl mezi skutečnými a procentními výdaji.
- [Zákon č. 586/1992 Sb., e-Sbírka](https://e-sbirka.gov.cz/sb/1992/586), zejména § 7b (obsah, ocenění, zjištění stavu na konci období, uchování), § 5 odst. 6 (vrácené dříve zdaněné příjmy) a § 23–25 (úpravy a výdaje). Vyhledané aktuální znění označuje účinnost od 1. 8. 2026.
- [GFŘ – daňové posouzení transakcí s kryptoměnami, 2022](https://financnisprava.gov.cz/assets/cs/prilohy/d-seznam-dani/Info_kryptomeny_GFR.pdf): základní metodika ocenění a transakcí; starší materiál sám neobsahuje pozdější zákonné změny.
- [Finanční správa – ostatní příjmy, kryptoaktiva](https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/fyzicke-osoby/ostatni) a [uzavřený koordinační výbor 04/2026](https://financnisprava.gov.cz/assets/cs/prilohy/d-prispevky-kv-kdp/Zapis_KV_KDP_04_26.pdf): nové osvobození nelze bez dalšího vztáhnout na podnikatelské krypto v obchodním majetku.

Členění konkrétního asistovaného nákupu v tomto modulu je implementací popsaného smluvního modelu, nikoli závazným stanoviskem správce daně. Software neposuzuje platnost pověření ani každého jednotlivého nákladu. Automatická bankovní synchronizace stavu objednávky zůstává oddělena od účetního zápisu.

## Technické ověření

`tests/osvc-evidence-database.php`: split 1 800/1 678/122, částečné platby a vratky s haléři, dodavatelské vratky nezkreslující příjem objednávky, převod bez druhého zdanění, replay a rollback, konfliktní verze, CSV a ochrana před vzorci, krypto s oceněním, majetek/inventura a opravy dokladů. Běží na MySQL i MariaDB. `tests/tax-evidence-browser.mjs`: desktop/mobil, zobrazení a zachování rozepsaného formuláře, přesné částky a pomocné ovládání; bez závislosti na produkčním účtu. Migrace je opakovatelná, nedělá automatickou uzávěrku ani fiktivní doplnění starých plateb.
