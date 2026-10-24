# Fio: kontroly plateb a bankovní podklady

Ověření k 24. 10. 2026, podle zdrojového kódu a [oficiální dokumentace Fio API](https://www.fio.cz/docs/cz/API_Bankovnictvi.pdf), verze 16. 10. 2025.

## Kdy se volá banka

| Situace | Automatický cron | Ruční tlačítko |
| --- | --- | --- |
| Nová nezaplacená objednávka do splatnosti | Nejvýše po 10 minutách | Ano |
| Nejvýše 7 dní po splatnosti | Po hodině, pokud už není žádná objednávka před splatností | Ano |
| Více než 7 dní po splatnosti | Sama nezpůsobí dotaz | Ano |
| Žádná vhodná objednávka na propojený účet | Žádný dotaz | Žádný dotaz |
| Zaplaceno, testovací či stornovaná objednávka, ručně opravená úhrada | Nepáruje | Ověř výpis a vyřeš ručně |

Je to jeden dotaz za účet a běh, nikoliv za každou objednávku. Tlačítko v přehledu prochází i staré čekající objednávky; tlačítko **Ověřit platbu u Fio** v detailu mění pouze vybranou objednávku. Pouhé otevření administrace ani zákaznické stránky Fio nevolá. Týden po splatnosti je praktická tolerance na opožděné převody, nikoli automatické storno. U historických objednávek bez splatnosti se pro plánování používá vytvoření + 7 dní.

Četnost je omezena uvnitř aplikace, takže příliš často nastavený cron nezpůsobí stejně časté dotazy. Nastav jej po 10 minutách; pokud jej spouštíš řidčeji, skutečná kontrola bude řidší. Chyba banky také spotřebuje interval. Ruční kontrola sdílí s cronem minimálně 35sekundový odstup. Databázový zámek brání souběhu po celou dobu přenosu a párování. Token vyhraď této instalaci — nesdílej jej s dalšími e-shopy nebo účetním programem, které používají jiné databáze.

## Rozsah a bezpečnost párování

- Volá se pouze čtecí `GET /v1/rest/periods/{token}/{od}/{do}/transactions.json`. Nemění zarážku posledního stažení u Fio a neposílá platební příkazy.
- Začátek určuje nejstarší vhodná objednávka, nejvýše 89 dní zpět včetně dneška (90 kalendářních dní). Starší bankovní platbu zkontroluj v internetbankingu. Dokumentace pro data starší 90 dní vyžaduje dočasné silně autorizované odemknutí historie; aplikace jej neprovádí.
- Musí souhlasit účet včetně IBAN, CZK, přesná celá částka, VS a nová čekající objednávka. Chybějící/neplatné datum, budoucí pohyb a pohyb ze dne před vytvořením objednávky se nepárují. Bankovní datum je lokální den banky, nikoli datum běhu cronu.
- Unikátní ID pohybu zabrání opakovanému použití a dvojímu podkladu. Značka použitého pohybu zůstává i po smazání objednávky; doklady mají vlastní historii. Úplný výslovný reset testovacích obchodních dat je zvláštní operace.
- Částečné platby, přeplatky, chybné VS, platby po stornu, vratky a bankovní storna vyžadují ruční vyhodnocení. Zákazník tedy může zaplatit i po splatnosti, ale automat nemůže zaručit nalezení libovolně opožděné platby.

## Co se automaticky uloží do evidence

Při novém úspěšném spárování vznikne při nainstalovaném současném schématu evidence **Bankovní pohyb z API (podklad)**. Obsahuje částku, datum bankovního pohybu, `Fio:ID`, vazbu na objednávku a kopii jednoho pohybu v JSON. Soubor obsahuje identifikaci vlastního účtu a údaje k této platbě, ne zůstatky či jiné pohyby. API token se neukládá. Příloha je v neveřejné databázi a stahuje ji přihlášený správce. Hash SHA-256 kontroluje uložený soubor, není elektronickým podpisem banky.

Podklad a potvrzení platby se uloží společně v jedné databázové transakci. Při selhání ukládání se obojí vrátí; další kontrola může bezpečně opakovat import. Automatický záznam v historii má původ „automatický import“ (interně admin ID 0). Použije se nastavená řada ostatních dokladů. Nevzniká další volání banky. Historické již zaplacené objednávky se tímto zpětně nedoplňují; jejich výpisy lze přiložit ručně.

V detailu objednávky se nabídne podklad a předvyplní bankovní datum a reference pro **Zapsat příjem**. Zápis potvrdí správce. Existující rozdělení vlastního příjmu a svěřených peněz asistovaného nákupu zůstává zachované. Podklad se automaticky propojí s příslušnými příjmovými zápisy a zahrne do archivu ZIP. Samotný dokument se nezapočítává do daňových součtů.

## Oficiální PDF výpisy a jednotlivá potvrzení

Fio dokumentuje PDF u oficiálních výpisů: `GET /v1/rest/by-id/{token}/{year}/{id}/transactions.pdf`; poslední vytvořený výpis zjistí `GET /v1/rest/lastStatement/{token}/statement`. Rok a ID jsou identifikace výpisu, nikoli ID jednotlivého pohybu. Samostatný endpoint pro PDF potvrzení jednoho pohybu v dokumentaci není uveden. Běžný export pohybů za období PDF nepodporuje.

Tato úprava automatický archiv celých PDF výpisů nezavádí. Pro tento e-shop je účelné ukládat podklad konkrétní platby z již získaných dat a oficiální výpis archivovat jednou za období, ne ho stahovat pro každou objednávku. Stažený PDF výpis lze již nyní nahrát do **Daňová evidence → Doklady → Bankovní výpis** a připojit k více zápisům. JSON podklad ani výpis nenahrazuje fakturu dodavatele nebo doklady k obsahu obchodu.

Případné budoucí automatické stahování celých výpisů má mít samostatný, nepovinný archiv: zjistit nové číslo výpisu, uložit každý účet/rok/číslo jen jednou a přiřadit pohyby podle ID. Musí sdílet limit tokenu, řešit historické odemknutí a nepokládat číslo výpisu za měsíc. Na osobním účtu by PDF obsahovalo i nesouvisející soukromé pohyby.

Daňová evidence sleduje členěné příjmy/výdaje i majetek a dluhy, proto pouhé označení objednávky „zaplaceno“ není kompletní evidencí. Viz [Finanční správa – OSVČ, daňová evidence](https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/fyzicke-osoby/podnikatel-osvc).

## Ověření

Regresní testy pokrývají přechod splatnosti, konec týdenní tolerance, omezení cronu, cizí účet, souběh dvou databázových spojení, ruční ověření staré objednávky, opakované pohyby a korekce. Testy evidence ověřují bankovní datum včetně přelomu roku, soukromý obsah přílohy, propojení pozdějšího peněžního zápisu, export ZIP a rollback platby při selhání ukládání přílohy. Bankovní transport je v testech simulovaný; netestuje se živý účet ani hostitelský cron.
