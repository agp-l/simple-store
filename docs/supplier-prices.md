# Ceny dodavatelů

[Upravený plán variantních odkazů](variant-supplier-architecture.md) používá partnery výhradně pro asistovaný nákup. Převzaté společné odkazy se nejdříve přiřadí variantám jako neověřené; volitelné přesné párování popisuje následující oddíl. Pravidla partnerského množství následují samostatně. Následující dokumentace popisuje nasazený cenový automat; variantní odkazy už mají vlastní evidenci a soukromý JSON. U asistovaného nákupu se dostupnost a limit množství řídí ověřenou nabídkou vybraného partnera.

Administrace: `/admin/supplier-prices/`. PHP 8.1+, cURL a stávající MySQL/MariaDB. Před prvním použitím aktualizovat SQL tabulky v Administrace → Databáze. Není potřeba Python, prohlížeč na hostingu ani externí služba.

## Provoz

1. V Prodávajících vyplnit web a priority. U produktu uložit Dodavatele a Vybraného prodávajícího. Shoduje se celá doména (s/bez `www`), ne pouhá část názvu. Duplicitní profily stejné domény se nepoužijí.
2. V manageru vybrat chybějící ceny, všechny produkty nebo jeden produkt. Zařazení zároveň spustí zpracování v otevřeném okně. Při chybě připojení lze pokračovat tlačítkem. Fronta je v databázi.
3. Šetrně: rozestupy 60–90 s na obchod. Zrychleně: 15–23 s. Diagnosticky: 1–2 s. Větší prodleva z robots.txt nebo Retry-After má přednost. Robots a každý krok přesměrování také spotřebují tento limit. Každý worker provede nejvýše jeden vzdálený požadavek; nečeká se pomocí sleep.
4. Běžná nová kontrola produktu nejdříve 24 hodin od zařazení i dokončení. Pro nevyřešené výsledky existuje níže popsané ruční opakování. Rozpracovaná kontrola se neduplikuje. Tentýž URL se po dobu 24 h sdílí z cache mezi produkty. Limity platí společně pro všechna okna i cron.
5. Jednoznačná CZK cena vybraného prodávajícího se automaticky uloží do **Cena dodavatele**. Haléře se zaokrouhlí nahoru na celé Kč. Změna přes 30 %, nejasný URL varianty a varianty bez důkazu společné ceny vyžadují kontrolu. Vlastní dodavatelské ceny variant zůstávají zachované. Společná prodejní cena se může přepočítat podle zapnuté cenotvorby; vlastní sklad se nemění. U asistovaného nákupu se samostatně synchronizuje partnerský limit.
6. Záložní odkaz podle priority se použije až při chybě předchozího. Zjištěná záložní cena je návrh: tlačítko převezme cenu a prodávajícího společně. Vyžaduje dokončený profil nového prodávajícího. U ručně řízených dodavatelských cen variant je nutná společná úprava v produktu. Při povolené automatice lze schválit úplný soubor přesných variantních nabídek; staré nákupní ceny jiného obchodu se nepřenášejí.
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
- Jedna veřejná cena neznamená všechny velikosti/barvy. Odkaz má ukazovat na konkrétní odpovídající produkt/variantu. Dostupnost přesně ověřené nabídky vybraného partnera řídí limit asistovaného nákupu: skladem bez počtu = 3, doložený počet >= 2 = tento počet, poslední kus nebo vyprodáno = 0.
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
Každý změněný jazyk dostane novou produktovou revizi. Přijaté objednávky, vlastní sklad ani vlastní
prodejní/dodavatelské ceny variant se nepřepisují. Cena varianty pod jejím nákupním nákladem
celou změnu zastaví ke kontrole. Historie kontroly obsahuje původní a novou společnou cenu.

## Opakování, diagnostika a tempo

Běžná kontrola zůstává nejvýše jednou za 24 hodin. U posledního výsledku „Ke kontrole“ nebo
„Nepodařilo se“ lze hromadně i jednotlivě požádat o nové vyhodnocení. Bez zaškrtnutí nového
stažení se nejprve vyhodnotí platná uložená nabídka. Neúplný dokument ze starší verze
parseru může spustit níže popsanou jednorázovou údržbovou obnovu po aktualizaci parseru.
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
provede jednou automaticky při nejbližším běhu workeru. Běžné kontroly dodržují denní
limity; každé stahování dodržuje robots.txt a prodlevy dodavatelů.
Pokud stará cache ještě neobsahuje vlastnosti potřebné pro přesné párování, worker sám
naplánuje jedno údržbové načtení dotčené URL mimo běžný denní limit: nejdříve 15 minut
po skutečném předchozím požadavku. Další běhy fronty tento termín neposouvají. Úspěšná
i neúspěšná odpověď nahradí starý dokument, takže aktualizace parseru nevytvoří smyčku.
Uložené HTTP 403/429/503, pravidla robots.txt a prodlevy partnera se neobcházejí.

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

Ověřená cena vyprodaného zboží se může aktualizovat. Dostupnost přesně ověřené nabídky vybraného partnera samostatně aktualizuje limit asistovaného nákupu;
vlastní sklad, publikování produktu ani zákaznické objednávky se nemění. Rozdílný prodávající, neznámá
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
administrace. Denní ověření a požadavky po objednávkách se řídí společnou frontou; pozastavení platí i pro cron.

Workflow využije stávající připojení pro nasazení. Při prvním běhu vytvoří samostatný náhodný
klíč v chráněném `config/supplier-worker.php`; existující klíč nikdy nepřepíše. Klíč není v repozitáři,
v URL ani ve výpisu úlohy. Přes HTTPS hlavičku autorizuje pouze `POST /supplier-price-cron.php`
a nemůže číst diagnostiku ani upravovat produkty jinak než standardním workerem.
Přesměrování HTTPS klient odmítne, takže klíč neodešle na jiný server. Původní hostingový
cron s uloženým SHA-256 klíčem dál funguje. Samostatný klíč lze zneplatnit odstraněním souboru
a vypnutím tohoto workflow, aby jej další běh znovu nevytvořil.

Administrace a JSON rozlišují poslední krok z prohlížeče a skutečný běh cronu/CLI.
Pravidelné plánování samo o sobě není důkaz běžícího workeru; rozhoduje zaznamenaný heartbeat cronu.

## Přesné partnerské odkazy variant (etapa C)

V administraci cen zapni **Automaticky přiřazovat ověřené odkazy variant**. Párování běží se stejným workerem, nejvýše tři produkty v jednom kroku, po dokončení celého průchodu s odstupem pěti minut. Pozastavení fronty pozastaví i párování. Nastavení je na nové instalaci vypnuté a nemění cenotvorbu.

`VariantLinkDiscovery` čte pouze uložené nabídky z běžných kontrol cen; neprovádí HTTP požadavky. Čas načtení i platnost musí být mladší než 24 hodin. Umí využít společnou modelovou stránku pro více variant a bezpečná uložená přesměrování. Neplatná či chybějící nabídka se nepovažuje za důkaz dostupnosti.

Přesná shoda vyžaduje podporovaný zdroj (Gramino, Pod7kilo nebo strukturovaná nabídka HUDY), shodu modelu a vlastností a právě jeden explicitní URL jedné nabídky. Shodné ceny několika barev nejsou důkazem identity. HUDY vyžaduje parametr `variant` a při velikosti také `size`. Rozporné zdroje, chybějící partner, nejasná kombinace a nepodporovaný web zůstávají označené s důvodem.

Převzatý odkaz se nahradí jen přesnou shodou. Ručně zadaná odlišná adresa se nepřepíše, uloží se návrh. Existující přesný odkaz a dříve odebraná adresa blokují duplicitu. Identita odkazu, zdroj převzetí a původní URL se zachovají; opakování se stejnými daty nezvyšuje verzi. Transakce drží zámek produktu a kontroluje verzi odkazu. Výsledky včetně času nabídky jsou vidět přímo u varianty v tabulce.

Soukromé nástroje (vyžadují přihlášení administrátora):

- `GET /admin/supplier-prices/?format=variant-link-plan` — náhled bez zápisu a bez síťového načítání, případně `&product_key=<32hex>`.
- Tlačítko **Export náhledu párování (JSON)** — stejný report ke stažení, formát `simple-store.variant-link-plan.v1`.
- `GET /admin/supplier-prices/?format=variant-links` — skutečně uložené odkazy a výsledky; dřívější export/import zůstává zachovaný.
- `php tools/supplier-price-worker.php --variant-link-plan` — náhled přes CLI. Zápisy provádí běžný `--tick` jen při zapnutém párování.

Párování zapisuje pouze partnerské odkazy a jejich důkazy. Nemění vybraného prodávajícího, režim prodeje, ceny, vlastní zásoby, rezervace ani objednávky. Pravidla dostupnosti 3 / skutečný počet / poslední kus provádí oddělený `PartnerStockSynchronizer`.

## Cenové jádro nad přesnými nabídkami variant (26. 10. 2026)

Fronta sestavuje zdroje z `shop_product_variant_supplier_links`. Stabilní `link_key`,
`variant_key` a `partner_key` propojují důkaz s konkrétní kombinací a obchodem. Odstraněné
odkazy jsou tombstones: nesmí obnovit starý společný zdroj. Ruční/discovered odkaz má přednost
před původním inherited odkazem stejné varianty a partnera. Produkty bez aktivních variant
nebo dosud bez variantních odkazů zachovávají společné zdroje.

Každá načtená stránka přidá do trvalého záznamu úlohy ověřené nabídky (`variant_observations`).
Až po zpracování všech odkazů jednoho partnera se ověří úplnost aktivních variant, rozpory
mezi odkazy a stáří každého důkazu. Ceny různých partnerů se neskládají dohromady. Neúplný
nebo rozporný soubor nelze ručně schválit jako jednu společnou cenu. Výsledné přesné URL,
částky, dostupnost a časy jsou v `canonical_variant_offers`, v JSON diagnostice verze 3
 i v rozbalitelném přehledu u výsledku kontroly. Změny se uloží v jedné transakci po opětovné
kontrole odkazů a produktu. Skok přes 30 % se kontroluje také pro každou jednotlivou variantu.

Čerstvá společná modelová stránka může posloužit jako důkaz pro několik variant, pokud obsahuje
jejich konkrétní nabídky. Nepřepíše existující odpověď přesného URL ani nesprávný ruční odkaz.
Časy zůstávají původní, opakované čtení cache jejich platnost neprodlužuje. Offline replay
používá stejnou variantovou agregaci jako worker.

### Diagnostika načítání a Alza

U výsledku je tlačítko **Ověřit načítání bez změny cen**. Soukromý POST
`/admin/supplier-prices/` s `action=supplier-price-probe`, `job_id`, platným CSRF a volitelným
`format=json` vrací stav přístupu k první aktuální nabídce produktu. Nezapisuje ceny, sklad
ani stav úloh. Sdílí 24hodinovou URL cache, zámek, prodlevy a blokace s workerem. Jedno volání
provede nejvýše jeden HTTP požadavek; při prvním ověření robots nebo přesměrování vrátí pokyn
k pokračování po prodlevě. Pozastavená fronta zastaví i diagnostické HTTP požadavky.

Podle RFC 9309 §2.3.1.3 samotný nedostupný robots.txt (403/404/410) nezakazuje veřejnou
produktovou stránku. Tyto odpovědi proto umožní běžný požadavek na produkt. Explicitní
Disallow, autentizace, 429, 5xx a síťové chyby zůstávají blokující. Odpověď 403 samotného
produktu dál ukončí načítání a nastaví prodlevu. User-Agent zůstává pravdivý, nepoužívají
se přihlášení, proxy ani neveřejná API. Dostupnost Alzy je nutné ověřit ze skutečného hostingu;
změna interpretace robots sama nezaručuje, že Alza povolí produktové požadavky.

Zdroj: https://www.rfc-editor.org/rfc/rfc9309.html#section-2.3.1.3

## Změna partnera a barevné odkazy 4camping (26. 10. 2026)

- Cenový transport přijímá veřejné HTTPS adresy s fragmentem (`#oranzova`). Fragment je součást identity nabídky, ale není součást HTTP požadavku ani klíče cache. Všechny barvy jedné stránky sdílejí odpověď i blokaci; změna fragmentu neobchází denní limity. Přesměrování v rámci partnera zachovává fragment, pokud jej nová adresa neurčuje.
- Parser 5 načítá tabulku `variantsInfo` 4camping z jediného serverového JSON, bez spouštění JavaScriptu. Kontroluje produkt a ID varianty, doménu a cestu nabídky, měnu CZK a shodu veřejné schema.org ceny s vybranou variantou nebo minimem modelu. Při vypnutých samostatných URL páruje fragment s přesnou cestou SKU z tabulky. Neznámá barva, nejednoznačná adresa, jiný produkt či měna cenu neaktualizují. Sklad zůstává beze změny.
- Opakování kontroly porovnává aktuální zdroje s tehdejším nastavením. Při novém partnerovi/adrese kontroluje novou nabídku s běžnou cache a prodlevami, bez nutnosti vynutit opakované stažení starého obchodu. Stejné zdroje se bez zaškrtnutí znovu pouze vyhodnotí z cache. Totéž platí při změně zdrojů již čekající úlohy.
- Historie jasně odděluje aktuální partnerské odkazy od odkazů použitých při starých pokusech. Starý důkaz se nemaže, ale změněný produkt nelze přecenit starým návrhem. Opakování historického řádku s novějším výsledkem vysvětlí, že je potřeba otevřít aktuální kontrolu.
- Diagnostika používá aktuální nabídku i při spuštění ze starého výsledku. Zobrazuje konkrétní načtenou cenu a případný důvod ruční kontroly. Nemění cenová rozhodnutí, sklad ani produkt; využívá společnou cache a nejvýše jeden HTTP požadavek. Cena uložená například jako 5 Kč proto při načtení reálné ceny stále správně vyžaduje kontrolu změny nad 30 %.

### Exact identities and catalog conventions (parser 6)

Partner attributes accept explicit height/size, battery capacity, zipper side and
product version fields. Pod 7 kilo's slash-separated attribute labels preserve
slashes inside colour names. A model page may publish the minimum price while
preselecting a more expensive SKU; every variant still keeps its own table price.
A precise variant page must agree with that SKU's price. Contradictory prices fail
closed. Capacity comes only from the technical parameter table.

HUDY single-SKU model URLs are accepted only when the selected size ID, detail
barcode and product name, page URL, and purchase link all agree. No purchase is
made. Explicit copied SKU links can disambiguate identical labels; other copied
combinations must independently match their own SKU.

Catalog translations are supplier/model scoped in `SupplierCatalogAliases`.
Luxury Fillo requires the expanded version; HyperBead requires explicit model
technology evidence. Generic colour similarity is not identity evidence. The
sanitized fixtures in `supplier-identity-regressions.json` exercise the observed
failures and rejection of unrelated models, colours and SKU selectors. Parser and
evaluator versions trigger one maintenance refresh for obsolete, incomplete
documents, including changed links that reuse an old cached model. This explicit
release-time exception to daily freshness waits at least 15 minutes from the
actual HTTP fetch and preserves robots rules, host cooldowns and denied responses.
Successful or failed fetches replace the legacy document, preventing refresh loops; own-stock counts and reservations are unchanged; assisted order limits are now synchronized separately.

## Dostupnost partnerů a bezpečné aktualizace (26. 10. 2026)

- `PartnerStockPolicy` čte počet pouze ze strukturovaného `inventoryLevel.value` nebo z dostupnosti přesného SKU. Nečte počty z celé stránky. `LimitedAvailability` bez přesného počtu se nenabízí. Neznámý či rozporný údaj nemění poslední známý limit.
- `PartnerStockSynchronizer` synchronizuje počet a dostupnost ve stejné transakci, odděleně od schvalování ceny. Změna ceny přes 30 % proto neblokuje vyprodání. Záložní partner, nesprávná varianta, zastaralý snímek a režim Prodáváme my jsou vyloučeny. Každá aktivní varianta vyžaduje svůj důkaz; shodná cena není důkazem shodného skladu.
- Objednávka asistence limit neodečítá; `shop_partner_refresh_requests` je slučovací transakční fronta. Chyba/rollback objednávky nezanechá nový požadavek. Worker respektuje 15minutovou prodlevu URL, robots a blokace. Pravidelná dostupnost se ověřuje denně, také při vypnuté starší týdenní cenové volbě; globální pozastavení platí pro vše. GUI už místo nadbytečné týdenní volby popisuje denní a poobjednávkové ověření.
- `shop_partner_stock_state` chrání automatický limit před převodem na fyzické kusy při změně režimu/partnera. Historické asistované rezervace se při stornu nevracejí do nového limitu.
- Nové provozní tabulky vytvoří autentizovaný worker před prvním krokem (stejné definice jsou ve `schema.sql`). Není potřeba DDL z veřejné stránky. První spuštění zařadí existující asistované produkty k ověření. Nový parser si staré odpovědi obnoví se zachováním limitů.
- Historie kontrol obsahuje aktuální fotografii produktu a audit změněných limitů. Obrázek není součástí cenového fingerprintu. Správa produktů filtruje efektivní dostupnost před stránkováním, včetně nulového počtu a variant.

## Kurz BTC

`BitcoinRateRepository` sdílí kurz v databázi a zamyká aktualizaci napříč procesy. Za běžného provozu provede nejvýše jeden dotaz za 15 minut za instalaci (96/24 h). Termín dalšího pokusu uloží **před HTTP**. Chyby mají exponenciální prodlevu od 15 minut do dne; HTTP 429 alespoň hodinu, 401/403 alespoň den, delší `Retry-After` má přednost. Časová prodleva funguje i bez dosud uloženého kurzu. Platný starší kurz lze zobrazit nejvýše 2 hodiny od času zdroje, potom zůstane pouze Kč. Testy používají falešný transport, poskytovatele nezatěžují.

## Normalizace identity a diagnostika (27. 10. 2026)

`VariantAttributes` sjednocuje oddělené i starší složené názvy vlastností, včetně „Barva a velikost“, „Velikost a barva“ a „Varianta“. Vnitřní zápis S/M zůstává jednou velikostí. Překládají se celé barevné výrazy; odstín, materiál, model a rok se nezahazují. Typované vlastnosti JSON-LD doplňují neúplný název SKU, rozpory vedou k odmítnutí. Tabulka výškových pásem Cumulus platí pouze na příslušných adresách Pod 7 kilo.

Parser 8 zachovává také shodnou vlastnost opakovanou v JSON-LD a specifikaci jediného SKU; rozporné hodnoty dál odmítá. Staré neúplné dokumenty se jednorázově obnoví s běžnými prodlevami. Vyhodnocovač 12 znovu posoudí starší nevyřešené kontroly; párovač 2 obnoví průchod odkazy. Stále platí cache, robots, prodlevy a ochrana ručně uložených odkazů. `VariantIdentityDiagnostic` popisuje chybějící údaje a rozdílné hodnoty. HTTP/robots chyby zůstávají připojené ke konkrétním variantám a neztratí se za obecným hlášením neúplnosti. Záložní obchod vyžaduje vědomé převzetí prodávajícího. Nedoloženou kombinaci nelze automaticky převést na jinou barvu, materiál či ročník.

Další ověřené katalogové názvy zůstávají omezené na konkrétní modely a cesty: TravelRest (český název cestovního polštáře), Sea to Summit Aeros Ultralight Traveller (pořadí názvu a označení Pillow), Copper Spur (Tangelo / Tangelo Red), Liteway Pokkie (UltraGrid / Ultra Grid), Hyperlite (T / Tall). Aevor Trip Pack Proof navíc vyžaduje slovo Proof ve vlastnostech příslušné nabídky; samotný obecný model Trip Pack nestačí. Technické označení HyperBead musí být stále doložené u nabídky. Podklady ověřené 27. 10. 2026:

- https://www.bigagnes.com/collections/hyperbead-tents/products/copper-spur-ul-three
- https://gramino.cz/p/big-agnes-copper-spur-ul3
- https://gramino.cz/p/big-agnes-copper-spur-ul3-xl
- https://gramino.cz/p/sea-to-summit-ultralight-aeros-traveller-pillow
- https://www.pod7kilo.cz/p/polstar-travelrest-ruzne-barvy/
- Příslušné veřejné variantové dokumenty v regresním vzorku `tests/fixtures/supplier-label-translations.json`; ceny ve vzorku jsou záměrně syntetické. Testuje se identita, nikoliv cenový seznam.
# Nastavení automatických cen variant

V editoru produktu → Způsob prodeje → Nákupní ceny variant je volba **Cenotvorba variant**.
Automatický režim lze výslovně zapnout i pro sloučené nebo importované produkty s vlastními cenami.
Uložení pouze změní předvolbu a zařadí kontrolu. Ceny se převezmou až po ověření přesných nabídek
všech aktivních variant u vybraného partnera; cenové skoky a nejasná identita zůstávají ke kontrole.
Výchozí režim zachovává původní pravidlo: automatika se sama zapíná jen bez vlastních cen.
Ruční úprava prodejní ceny varianty automatický režim nadále vypíná. Formulář chrání nastavení
proti souběžné změně; starší klient bez této volby předvolbu nemění. Vlastní sklad se neaktualizuje.
### Schválení přesných nabídek záložního partnera

Tlačítko „Použít cenu i prodávajícího“ může převzít celý soubor přesných
variantních nabídek, pokud má produkt povolenou automatickou cenotvorbu
(nebo splňuje její výchozí podmínky bez vlastních cen). Před uložením se
znovu ověří úplnost, stáří a shoda nabídek všech aktivních variant.
Změna partnera a cen proběhne v jedné transakci; nákupní ceny neaktivních
variant od původního partnera se nepřenášejí. Dostupnost nového partnera
se následně obnoví přes frontu. Ručně vypnutá cenotvorba zůstává vypnutá.
