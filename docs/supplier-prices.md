# Ceny dodavatelů

[Upravený plán variantních odkazů](variant-supplier-architecture.md) používá partnery výhradně pro asistovaný nákup. Převzaté společné odkazy se nejdříve přiřadí variantám jako neověřené; přesné párování a pravidla partnerského množství následují samostatně. Následující dokumentace popisuje nasazený cenový automat; pravidla z plánu zatím nejsou implementovaná.

Administrace: `/admin/supplier-prices/`. PHP 8.1+, cURL a stávající MySQL/MariaDB. Před prvním použitím aktualizovat SQL tabulky v Administrace → Databáze. Není potřeba Python, prohlížeč na hostingu ani externí služba.

## Provoz

1. V Prodávajících vyplnit web a priority. U produktu uložit Dodavatele a Vybraného prodávajícího. Shoduje se celá doména (s/bez `www`), ne pouhá část názvu. Duplicitní profily stejné domény se nepoužijí.
2. V manageru vybrat chybějící ceny, všechny produkty nebo jeden produkt. Zařazení zároveň spustí zpracování v otevřeném okně. Při chybě připojení lze pokračovat tlačítkem. Fronta je v databázi.
3. Šetrně: rozestupy 60–90 s na obchod. Zrychleně: 15–23 s. Diagnosticky: 1–2 s. Větší prodleva z robots.txt nebo Retry-After má přednost. Robots a každý krok přesměrování také spotřebují tento limit. Každý worker provede nejvýše jeden vzdálený požadavek; nečeká se pomocí sleep.
4. Běžná nová kontrola produktu nejdříve 24 hodin od zařazení i dokončení. Pro nevyřešené výsledky existuje níže popsané ruční opakování. Rozpracovaná kontrola se neduplikuje. Tentýž URL se po dobu 24 h sdílí z cache mezi produkty. Limity platí společně pro všechna okna i cron.
5. Jednoznačná CZK cena vybraného prodávajícího se automaticky uloží do **Cena dodavatele**. Haléře se zaokrouhlí nahoru na celé Kč. Změna přes 30 %, nejasný URL varianty a varianty bez důkazu společné ceny vyžadují kontrolu. Vlastní dodavatelské ceny variant zůstávají zachované. Společná prodejní cena se může přepočítat podle zapnuté cenotvorby; sklad se nemění.
6. Záložní odkaz podle priority se použije až při chybě předchozího. Zjištěná záložní cena je návrh: tlačítko převezme cenu a prodávajícího společně. Vyžaduje dokončený profil nového prodávajícího. U vlastních dodavatelských cen variant je nutná společná úprava v produktu, protože ceny jiného obchodu by nebyly platné.
7. Při převzetí se ověřuje stáří nabídky, nezměněná data produktu, odkazů, prodávajících a variant. Cena vyšší než nejnižší relevantní prodejní cena je zablokovaná. Historické objednávky se nikdy nepřepisují.

## Cron

**Nestačí spouštět jediný požadavek jednou týdně.** Týdenní je zařazení produktů; frontu odbavuje krátký worker pravidelně:

```cron
* * * * * php /absolutni/cesta/eshop/tools/supplier-price-worker.php
```

Alternativa pro hosting s webovým cronem: v manageru vytvořit soukromý klíč a nastavit v hostingu adresu `https://vlastni-domena/supplier-price-cron.php?key=...` každou minutu, případně každých 5 minut. Odpověď HEAD jen ověří klíč, nic nespustí. Klíč je uložen pouze jako SHA-256, jeho výměna ihned zneplatní starou adresu. Veřejně jej nesdílet.

V manageru zapnout **Jednou týdně zařadit všechny produkty**. První zařazení proběhne při následujícím kroku, další nejdříve za 7 dní. Po zavření prohlížeče běží zpracování pouze pokud byl cron skutečně nastaven na hostingu. Pozastavení v manageru platí i pro cron. Obnovení nezruší denní limity.

## Spolehlivost a hranice

- Čte se schema.org Product/Offer z JSON-LD v HTML nebo JSON bloku v Markdownu (např. Gramino). U ProductGroup se vybere nabídka přesného URL varianty. Doporučené zboží, ItemList, cenové intervaly AggregateOffer, cizí měny a podmíněné klubové nabídky se nezaměňují za běžnou cenu.
- U Pod 7 kilo může Offer odkazovat na společný model. Konkrétní variantu uznáme pouze při shodě kanonické adresy, vybraného ID varianty a produktu, EANu, kódu výrobce, přesné ceny a měny v serverovém JSON. Jde o ověření dodatečných dat, nikoli o odstranění kontroly variant. Starší návrhy „Ke kontrole“ zůstanou v historii. Lze je ověřit a převzít běžným tlačítkem nebo zařadit k opakování podle pravidel níže.
- Není to univerzální čtečka libovolného webu. Chybějící strukturovaná nabídka, cena pouze v JavaScriptu, přihlášení nebo ochrana proti robotům znamenají zaznamenaný neúspěch. Žádné obcházení CAPTCHA, rotace proxy nebo předstírání jiného robota. Pro takový obchod je další cestou domluvený XML/CSV feed nebo API; nový formát musí mít vlastní ověřený parser.
- Jedna veřejná cena neznamená všechny velikosti/barvy. Odkaz má ukazovat na konkrétní odpovídající produkt/variantu. Dostupnost dodavatele je informativní, nemění sklad e-shopu ani automaticky nezakazuje prodej.
- Přesměrování pouze v rámci stejné domény obchodu, nejvýše 3, každý krok kontroluje robots. Přechod jinam musí správce opravit v odkazu.
- Pouze veřejné HTTPS URL bez přihlašovacích údajů a jiných portů. DNS adresy jsou ověřeny a spojení připnuto na ověřenou IP. TLS kontrola zůstává aktivní, bez cookies. Limit těla 2 MB, spojení 4 s, HTTP požadavek 12 s. Privátní sítě/metadata serverů nejsou dostupné.
- Databázový advisory lock vylučuje souběh workerů. Rezervace hostu i URL se uloží před HTTP požadavkem; přerušené načtení se znovu nezkouší v krátké smyčce. Cache neukládá HTML produktů, jen výsledek; expirovaná cache se průběžně uklízí. Historie zůstává.

## 4camping

`docs/examples/prodavajici-4camping.json` používá stávající přenositelný formát prodávajících. Import rozpoznává již existující profil. Údaje ověřeny 25. 10. 2026 na:
- https://www.4camping.cz/stranky/obchodni-podminky/
- https://www.4camping.cz/stranky/kontakt/

Profil ForCamping s.r.o. – 4camping, IČO 24295825, je záměrně rozpracovaný (`confirmed: false`), import netvrdí uzavřenou spolupráci. Priorita 100 je upravitelná.

## Nastavitelná společná prodejní cena

V manageru je samostatná cenotvorba (po migraci zůstává vypnutá, dokud ji správce nezapne).
Výchozí minimum odměny je 50 Kč pro nákup pod 1 000 Kč a 100 Kč od 1 000 Kč.
K nákupní ceně se přičte minimum a výsledek se zaokrouhlí **nahoru** na násobek 100 Kč:
30 → 100, 99 → 200, 1 130 → 1 300, 1 210 → 1 400. Všechny čtyři částky lze změnit.
Uložení nastavení samo nepřeceňuje katalog; pravidlo se použije při dalším převzetí nabídky,
i když je dodavatelská cena stejná. Přepočet a převzetí nákupní ceny jsou jedna transakce.
Každý změněný jazyk dostane novou produktovou revizi. Přijaté objednávky, sklad ani vlastní
prodejní/dodavatelské ceny variant se nepřepisují. Cena varianty pod jejím nákupním nákladem
celou změnu zastaví ke kontrole. Historie kontroly obsahuje původní a novou společnou cenu.

## Opakování, diagnostika a tempo

Běžná kontrola zůstává nejvýše jednou za 24 hodin. U posledního výsledku „Ke kontrole“ nebo
„Nepodařilo se“ lze hromadně i jednotlivě požádat o nové vyhodnocení. Bez zaškrtnutí nového
stažení pracuje výhradně s platnou uloženou nabídkou a nikdy nevytvoří HTTP požadavek.
Výslovné nové stažení je ruční výjimka: nejvýše **jeden dodatečný pokus na produkt za 24 hodin**,
nejdříve 15 minut po předchozí kontrole. Jeden produkt má vždy nejvýše jednu čekající kontrolu.
Stejná URL se v tomto opakování sdílí mezi produkty pomocí času obnovení; HTTP 403/429/503
ani cache robots.txt se vynuceným opakováním neobcházejí. Historické výsledky zůstávají dohledatelné
ve stavu „Znovu zařazeno“. Opakování samo není schválení nejasné ceny.

Tempo lze změnit i již zařazeným produktům: šetrné 60–90 s, zrychlené 15–23 s,
diagnostické 1–2 s na obchod. Rozestup má náhodný přídavek pro rozložení zátěže;
User-Agent se pravdivě identifikuje jako DobrodruziSupplierCheck. Současně běží jediný
HTTP požadavek, Crawl-delay, Retry-After a rezervované prodlevy mají vždy přednost.
Desetiminutové dokončení nelze garantovat, zejména při pomalé odezvě, blokaci nebo cronu
spouštěném jednou za minutu. Změna tempa nezkracuje existující bezpečnostní prodlevu.
Dočasné chyby spojení/408/429/5xx mají nejvýše jeden automatický pokus za 24 hodin;
403 a trvale nejednoznačné nabídky vyžadují vyřešení příčiny. Při změně produktu před
zpracováním se kontrola nejvýše dvakrát obnoví s aktuálním nastavením.

Parser ukládá kompaktní sadu strukturovaných nabídek, nikoliv dodavatelské HTML. Cena
společná všem explicitním variantám jednoho ProductGroup nebo ověřeným variantsInfo
Pod 7 kilo již sama o sobě nevyžaduje ruční schválení společné nákupní ceny. Rozdílné ceny,
členské nabídky a neznámé varianty tento postup nesplňují. U Gramina lze opravit zastaralý
variantní odkaz pouze jedinou přesnou shodou **celého** názvu varianty ve stejném modelu;
ignoruje se pouze velikost písmen, mezery a typ pomlčky. Oprava odkazu se zaznamená a uloží
spolu s převzetím ceny. Neprovádí se hledání podobných produktů ani odhadování ID.

Export diagnostiky JSON obsahuje poslední kontrolu každého produktu, dodavatelské odkazy,
strukturované nabídky, důvody odmítnutí, časy a stav prodlev obchodů. Neobsahuje zákazníky,
objednávky, hesla ani klíč cronu. Je to diagnostický výstup, nikoliv soubor pro import cen;
nelze jím obejít identifikaci variant nebo ruční schválení. Starší cache bez sady nabídek
zůstane použitelná do vypršení, ale nové pravidlo parseru vyžaduje nové stažení.

Platné odpovědi z cache a známá přesměrování se zpracují po malých místních dávkách
(nejvýše 24 kroků), nikoli s prodlevou mezi každým místním čtením. Jeden tick stále smí
provést nejvýše jeden HTTP požadavek. Trvalé přesměrování 301/308 na stejném obchodě
může opravit uložený odkaz, pokud se zároveň přesně shodne celý název produktu nebo
skupiny variant s katalogem. Neznámý typ historického přesměrování tuto podmínku nesplňuje.

U Gramina se navíc porovnává přesný model, velikost a všechny aktivní místní barevné
kombinace. Zápis `D890`/`D 890` a základní české/anglické názvy barev se normalizují;
obchodní názvy odstínů se neodhadují. Rozpoznává se také výslovná strana zipu, velikost
v centimetrech a objem v litrech. Například `Patizon D890 – S` s červenou a modrou
variantou smí převzít společnou cenu pouze tehdy, když jsou obě přesně doložené a stejně
drahé. Chybějící kombinace, neznámá vlastnost, rozpor velikostí nebo
odlišné ceny automatické převzetí zastaví. Vlastní ceny variant zůstávají zachované.
Pro jednu přesnou variantu lze opravit variantní URL; pro několik stejně drahých barev
se uloží odkaz společného modelu a důkaz všech zahrnutých nabídek. Toto vyhodnocení
funguje i nad dříve uloženou sadou nabídek bez dalších dotazů. Diagnostický export
obsahuje také konečnou nabídku za uloženými přesměrováními a místní kombinace variant.
Typografické značky ™/® a různé apostrofy nemění identitu modelu; normalizují se i
výslovné dvojbarevné kombinace (např. žlutá/stříbrná). Rozdíl pánské/dámské se nezahazuje.


## Diagnostika a automatické opravy (verze 4)

Počítadla a výchozí seznam ukazují pouze **nejnovější kontrolu každého existujícího produktu**.
Aktualizováno zahrnuje i ověření stejné ceny. Přepínač celé historie zpřístupní starší kontroly
včetně odstraněných karet; jejich záznamy se nemažou.

JSON export verze 2 obsahuje aktuální cenový snapshot, aktivní varianty, cache jednotlivých
zdrojů, `active`, souhrn příčin `summary`, strojový kód `issue` a skutečné časy běhu workerů.
Stejná data poskytuje `GET /admin/supplier-prices/?format=json` v přihlášené administrátorské
relaci. Nepřihlášený přístup zůstává zablokovaný běžným přihlášením administrace.
Export neobsahuje zákazníky, objednávky ani přístupové klíče. Pro práci bez GUI:

```sh
php tools/supplier-price-worker.php --diagnostics > diagnostics.json
php tools/supplier-price-worker.php --replay diagnostics.json
php tools/supplier-price-worker.php --retry-cached
php tools/supplier-price-worker.php --tick
```

`--replay` funguje místně bez připojení k databázi i bez sítě. Ukáže, co nová pravidla rozpoznají;
neukládá ceny a nenahrazuje kontrolu aktuálnosti v transakci. `--retry-cached` pouze znovu
zařadí nevyřešené současné produkty. Po nasazení nové verze vyhodnocování se tento krok
provede jednou automaticky při nejbližším běhu workeru. Nové stahování nadále dodržuje denní
limity, robots.txt a prodlevy dodavatelů.
Pokud stará cache ještě neobsahuje vlastnosti potřebné pro přesné párování, worker sám
naplánuje jedno nové načtení: nejdříve 15 minut po předchozí kontrole a při vyčerpaném
dodatečném pokusu až 24 hodin po jeho dokončení. Další běhy fronty tento termín neposouvají.

Gramino má veřejné rozhraní popsané v `https://gramino.cz/openapi.json` a
`https://gramino.cz/.well-known/ai-catalog.json`. Pro `/p/…` se používá `/api/md/p/…`
se zachovaným `v_id`; robots se kontroluje pro obě cesty. Cena stále pochází z JSON-LD,
vlastnosti jediného SKU navíc z jeho oddílu Specifikace. Související produkty se ignorují.
Pod 7 kilo poskytuje úplné varianty v serverovém JSON `variantsInfo`; kontroluje se ID produktu,
ID varianty, vybraná cena a příslušnost URL. HUDY poskytuje barvu a velikost v JSON-LD variantách.
Každá aktivní místní kombinace musí mít přesnou shodu. Více místních kombinací s různými cenami
se nepřevede na jednu nejlevnější cenu. Vlastní ceny variant nadále vyžadují zapnutou automatiku.
Pokud je zapnutá společná cenotvorba a produkt ještě nemá žádné vlastní prodejní ani dodavatelské
ceny variant ani uložené vypnutí variantové automatiky, lze při přesném doložení všech kombinací
založit samostatné automatické ceny. Každá kombinace dostane svoji cenu dodavatele a svůj přepočet;
povolení automatiky se uloží v téže transakci. Ruční ceny ani výslovné vypnutí se nepřepisují.

Ověřená cena vyprodaného zboží se může aktualizovat. Dostupnost dodavatele zůstává informací;
sklad, publikování produktu ani zákaznické objednávky se nemění. Rozdílný prodávající, neznámá
varianta, skok přes 30 % a prodej pod náklady mají stále své kontroly.

Převody názvů rozměrů jsou omezené na konkrétní model a dodavatelskou URL. Podklady:
- https://gramino.cz/p/nemo-tensor-elite
- https://gramino.cz/p/nemo-tensor-all-season
- https://gramino.cz/p/therm-a-rest-neoair-xlite-nxt
- https://gramino.cz/p/sea-to-summit-frontier-ul-collapsible-bowl
- https://gramino.cz/p/eva-foam-pad-3-mm

## Samostatný worker v GitHub Actions

Workflow `Supplier price worker` běží každých 5 minut a po úspěšném nasazení. GitHub může
naplánovaný běh opozdit; nejde o záruku přesného času. Každý běh zpracovává frontu nejvýše
4 minuty, čeká podle odpovědí workeru a používá stejné databázové zámky a prodlevy jako
administrace. Týdenní zařazování a pozastavení se dál řídí uloženým nastavením obchodu.

Workflow využije stávající připojení pro nasazení. Při prvním běhu vytvoří samostatný náhodný
klíč v chráněném `config/supplier-worker.php`; existující klíč nikdy nepřepíše. Klíč není v repozitáři,
v URL ani ve výpisu úlohy. Přes HTTPS hlavičku autorizuje pouze `POST /supplier-price-cron.php`
a nemůže číst diagnostiku ani upravovat produkty jinak než standardním workerem.
Přesměrování HTTPS klient odmítne, takže klíč neodešle na jiný server. Původní hostingový
cron s uloženým SHA-256 klíčem dál funguje. Samostatný klíč lze zneplatnit odstraněním souboru
a vypnutím tohoto workflow, aby jej další běh znovu nevytvořil.

Administrace a JSON rozlišují poslední krok z prohlížeče a skutečný běh cronu/CLI.
Samotné zapnutí týdenního plánování už nelze zaměnit za důkaz běžícího workeru.
