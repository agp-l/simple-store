# Odkazy partnerů u variant a dostupnost asistovaného nákupu

Stav: **etapy A/B/C a synchronizace limitů D implementované**, 26. 10. 2026. Upřesnění uživatele zde nahrazuje širší návrh z commitů `dd14f4f` a `2e26198`. Současný provoz popisují [varianty](product-variants.md), [asistovaný nákup](assisted-purchase.md) a [cenový automat](supplier-prices.md). Etapy A/B přidávají evidenci odkazů, editor, JSON a přímé URL. Etapa C doplňuje volitelné automatické ověřování přesných URL z čerstvých uložených nabídek; zapíná se v administraci cen. D je implementované v `PartnerStockPolicy` a `PartnerStockSynchronizer`; vlastní sklad zůstává oddělený.

## Význam partnera a režimů prodeje

**Partner / dodavatel znamená jiný e-shop používaný výhradně pro asistovaný nákup.** Systém neeviduje dodavatele zboží pro náš vlastní sklad a tato úprava takovou evidenci nezavádí.

Celý produkt má nadále právě jeden ze současných režimů:

| Režim | Význam množství | Text zákazníkovi |
| --- | --- | --- |
| **Prodáváme my** | Skutečné volné kusy našeho zboží; u variant zvlášť pro konkrétní kombinaci. | **Skladem** |
| **Asistovaný nákup** | Interní limit objednatelných kusů podle dostupnosti vybraného partnera. Není to naše fyzická zásoba. | **Skladem u partnera** |

U téhož produktu se režimy zákazníkovi nenabízejí současně. Různé produkty v jednom košíku mohou mít různý režim jako dosud. Změna odkazu nesmí sama přepnout režim. Vybraný prodávající zůstává v první etapě společný produktu; další partnerské odkazy jsou záložní zdroje. Automatické přepínání partnerů ani odlišný režim po variantách není součástí tohoto kroku.

Zachováme nynější produktové a variantní klíče, editor, cenotvorbu, rezervace a objednávkové snímky. **Nevzniká druhý souběžný sklad.** Současný údaj množství lze používat podle aktivního režimu. Původ partnerského limitu uložíme jako metadata kontroly, aby se skutečně pozorovaný počet u partnera nezaměnil za odhadovaný limit.

## Rozsah první změny

1. Umožnit uložit partnerský odkaz ke konkrétní variantě, včetně více partnerských e-shopů.
2. Zpočátku ke všem variantám převzít současné společné odkazy produktu. Označit je **Převzatý z produktu – přesná varianta dosud neověřena**.
3. Postupně nahradit převzaté odkazy přesnými odkazy na barvu, velikost a další parametry. Ručně opravené nebo ověřené odkazy hromadným kopírováním nepřepisovat.
4. Přidat vlastní přímou adresu varianty `?variant=<stávající variant_key>`.
5. Až v samostatné etapě zapnout načítání dostupnosti po přesně ověřené variantě a níže uvedená pravidla partnerského limitu.

Rozkopírování odkazu neověří jeho obsah. Odkaz na velikost 42 převzatý k velikosti 43 nedokládá její cenu ani dostupnost. Homepage nedokládá dostupnost žádné konkrétní varianty. První etapa doplní evidenci, ponechá současné ceny a zásoby a samotným označením odkazu za převzatý nově nezablokuje dosud nabízené produkty. Dosavadní přísné kontroly cenového automatu zůstávají zachované.

## Malá datová změna

Stávající `shop_product_supplier_links` zůstane společným výchozím seznamem. Doplnit jednu tabulku variantních odkazů, pracovně `shop_product_variant_supplier_links`:

| Údaj | Účel |
| --- | --- |
| Stálý klíč variantního odkazu | Úpravy, export a pozdější objednávkový snímek. |
| `product_key`, `variant_key` | Konkrétní současná varianta; ověřit její příslušnost k produktu. |
| `partner_key`, popisek, URL | Existující partner a přesný nebo zatím převzatý odkaz. Nevyřešený partner je rozpracovaný údaj, nikoli automaticky vybraný prodávající. |
| Původ a stav ověření | Odděleně `inherited` / `manual` / `discovered` a `unverified` / `verified` / `conflict`. Ruční vložení samo není strojově ověřená shoda. |
| Verze a čas kontroly | Ochrana souběžných zápisů a stáří údajů. |
| Omezená metadata výsledku | Identita varianty, cena, dostupnost, případný skutečný počet, původ množství a chyba; historie v dosavadní diagnostice. |

Na variantě může být více odkazů i více partnerů. Cenový automat musí jednoznačně určit použitý zdroj; rozporné odkazy stejného partnera vyžadují kontrolu. První etapa zachová současného zvoleného partnera produktu. Možnost volit jiného prodávajícího po variantě lze přidat později jako samostatnou změnu objednávkového souhrnu.

Variantní odkaz nesmí být závislý na číselném ID společného odkazu: současný `ProductSupplierLinkRepository::replace()` tyto řádky při importu nahrazuje. Původní URL/partner slouží jako informace o převzetí, vlastní identita variantního odkazu zůstává stálá. Opakovaný převod nezaloží duplicity. Změna společného URL připraví náhled úprav pouze dosud převzatých řádků, přesné odkazy zachová. Nová varianta může převzít stejné výchozí odkazy se stavem neověřeno.

Jednoduché produkty v první etapě ponechají současné společné odkazy. Převod každého jednoduchého produktu na jednu výchozí variantu se odkládá: pro přidání přesných odkazů není nutné měnit jeho sklad. Nevzniká nový registr produktů, soustava velkoobchodních nabídek ani nový systém prodejních režimů. Konkrétní SQL a import ověřit na MySQL/MariaDB s dosavadním prefixem tabulek.

## Dostupnost a počet u partnera

Pravidla platí **jen pro asistovaný nákup**, zvoleného partnera a přesně ověřenou variantu:

| Údaj partnera | Základ interního limitu | Zobrazení / nákup |
| --- | --- | --- |
| Skladem, bez čísla | Nejvýše **3 kusy**; skutečný pozorovaný počet zůstává neznámý. | **Skladem u partnera**. Nepsat „zbývají 3 kusy“. |
| Konkrétní počet `N ≥ 2` | Uložit skutečně uvedené `N`; základ limitu je `N`. | **Skladem u partnera**; při ověřeném nízkém počtu lze zobrazit číslo. |
| Přesně 1 kus / Poslední kus | **0 objednatelných kusů**. Pozorovaný počet 1 zachovat jako důvod. | Variantu nenabízet k nákupu. |
| Vyprodáno / 0 | 0 | Nedostupné u partnera. |
| Jen „poslední kusy“, bez čísla | Neodhadovat počet, nepoužít pravidlo běžného „skladem“. | Návrh konzervativního postupu: pozastavit nákup do ověření. |
| Chyba načtení, neznámá či příliš stará dostupnost | Nevytvořit nový limit 3. Zachovat poslední známý údaj, chybu a původní čas. | Neověřená dostupnost; dopad případného pozastavení před zapnutím ukázat v náhledu. |

„Nenabízet poslední kus“ se týká skutečného údaje partnera, nikoli pouhého poklesu naší interní kvóty na 1. Tři interní kusy nejsou tvrzení, že partner má tři kusy. Při `N ≥ 2` se podle zadání automaticky neodečítá další jeden kus jako rezerva; to by byla samostatná volba.

Navržený práh zobrazení docházejících kusů je **ověřený počet nejvýše 3**. U vlastního prodeje lze zobrazit i prodat „Poslední kus“. U asistence partnerův poslední kus nákup zakáže. Pokud rozpracované objednávky snížily interní limit, veřejný text jej nesmí vydávat za právě ověřený skutečný počet na cizím skladu.

Metadata rozliší `reported_quantity` (číslo nebo `null`), původ `reported` / `in_stock_cap` / `low_stock_unspecified` / `unknown`, čas a zdroj. Patří do výsledku kontroly či metadat variantního odkazu; nejde o druhý sloupec fyzického skladu ani nové skladiště.

### Limit, rezervace a opakované kontroly

Podle upřesnění zadání z 26. 10. je partnerské množství **limit jedné objednávky** z posledního ověřeného údaje. Objednávky ho trvale neodečítají. Kontrola v transakci stále ověřuje množství celé varianty a aktuální režim prodeje. Vlastní prodej používá skutečné rezervace beze změny.

Objednávka zapíše do transakční fronty požadavek na nové ověření. Více objednávek se sloučí; HTTP neběží během objednávky. Worker vyžádá pozorování po objednávce, nejdříve 15 minut od předchozího stažení URL. Platí robots.txt, prodlevy hostitele i HTTP blokace. Běžná automatická kontrola běží nejvýše jednou za 24 hodin; objednávka je výslovná výjimka s limitem pro URL.

Staré asistované rezervace se při stornu/obnovení označí, ale jejich kusy se nepřičítají ani neodečítají od partnerského limitu. Režim se čte z neměnného snímku objednávky. Starou vlastní objednávku po přepnutí na asistenci nelze obnovit do partnerského limitu.

Nejde o rezervaci zboží u partnera ani garanci okamžité dostupnosti: mezi ověřením a skutečným nákupem se cizí sklad může změnit. Nejasná kontrola zachová poslední známý údaj a viditelnou chybu kontroly; nevytváří odhadované kusy.

## Přepnutí režimu produktu

Přepnutí nikdy nepromění partnerský limit 3 ve tři fyzické kusy a vlastní počet nevydává za počet partnera. Správce uvidí, který režim a množství se použijí. Návrat na vlastní prodej vyžaduje ověření skutečného počtu; přechod na asistenci platný partnerský údaj nebo výslovně nastavený počáteční limit.

Automaticky spravovaný limit je označen v `shop_partner_stock_state` (partner a čas pozorování, žádný další sklad). Při změně partnera nebo návratu na vlastní prodej se tento limit vynuluje a starý údaj zneplatní. Správce nastaví skutečné vlastní kusy; u asistence je zařazena nová kontrola. Původní ručně zadané počáteční stavy bez automatického pozorování se touto migrací nepřepisují.

## Přesné odkazy a nepovinné identifikátory

U Hudy zachovat `variant` i `size`, u Gramina celé `v_id`. Načtená nabídka musí odpovídat místní barvě, velikosti i modelu. Parametry neodstraňovat obecným čištěním URL a variantní ID nevymýšlet podle číselné řady.

EAN/GTIN a výrobní kódy jsou **nepovinný pomocný důkaz**, pokud je partner skutečně poskytne. Uživatel je nemusí zadávat a jejich absence neblokuje vložení či používání správně ověřeného odkazu. Stačí metadata parseru, bez zvláštního katalogu identifikátorů. Ověření se může opřít o přesnou variantu a její explicitní vlastnosti; podobný název nestačí.

Automatické hledání přesných odkazů následuje po dokončení evidence. Nejprve existující zdroje Gramino/Hudy/Pod7kilo; jeden detail obsahující více variant načíst jednou. Zachovat dnešní limity, bezpečný HTTP klient a diagnostiku. Nalezení záložního odkazu samo nepřepne zákazníkova prodávajícího.

V režimu „Prodáváme my“ jsou partnerské odkazy neaktivní údaje pro případný budoucí přechod na asistenci. Nová partnerská automatika nesmí měnit vlastní sklad ani samovolně vlastní prodejní cenu. Před rozšířením ověřit odpovídající omezení nasazeného cenového automatu; existence odkazu sama není oprávněním zacházet s produktem jako s asistencí.

## Vlastní URL a objednávky

Navržený odkaz: `/cs/produkt/<slug>?variant=<variant_key>`, se současným ID. Server předvybere variantu, cenu, obrázek a dostupnost i bez JS; JS mění adresu při výběru a podporuje zpět/vpřed. Neexistující ID nesmí tiše vybrat jinou velikost. Partnerské URL zůstávají soukromé.

Dosavadní objednávkový snímek asistované položky se rozšíří o konkrétní použitý variantní odkaz a identitu. Interní URL se nezveřejní zákazníkovi. Budoucí změna odkazu, dostupnosti nebo partnera nepřepíše starou objednávku. Kontrola ceny neprovádí nákup na cizím e-shopu; automatizace nákupu je samostatná budoucí etapa.

## Pořadí malých změn

| Etapa | Výsledek | Ověření |
| --- | --- | --- |
| A. Odkazy variant — hotovo | Jedna doplňující tabulka, editor, soukromý JSON; opakovatelné převzetí společných odkazů ke všem současným variantám. | Stejné ceny, režimy, kusy, variantní ID a objednávky před/po; přesný odkaz se nepřepíše. |
| B. Přímý odkaz — hotovo | `?variant=...` pro existující varianty. | Reload, bez JS, mobil, neplatné ID, jazyk a správná varianta v košíku. |
| C. Přesné URL partnerů — hotovo | Ruční doplnění, náhled JSON a automatické přesné shody z čerstvých uložených zdrojů; ruční URL pouze jako návrhy. | Převzatý odkaz na jinou velikost není potvrzený; prodávající se nepřepne. |
| D. Dostupnost asistence | Pravidla 3 / skutečný počet / poslední kus, texty, ochrana rezervací a přepnutí režimu. | Náhled dopadu a malý vzorek; vlastní sklad chráněný, souběh objednávek neobejde limit. |

Každá etapa má vlastní commit a odpovídající testy. Schéma je doplňující, s podporou prefixu tabulek a MySQL/MariaDB. Převzetí odkazů běží po produktech s kontrolou verzí, pokračováním po přerušení a bez zásahu do skladu. GET nesmí spouštět migraci ani vzdálené načítání.

Není důvod kvůli etapě A převádět 179 jednoduchých produktů na nový skladový model, znovu slučovat produkty ani řešit připravený, ale nezapnutý sklad variant Altra Lone Peak/Petzl Actik. Odkazy připojíme k existujícím identitám. Tyto počty pocházejí z inventury 25. 10. 2026, před zápisem je nutné zjistit aktuální stav.

## Odložené části původního návrhu

Nový registr produktů, povinná výchozí varianta každého produktu, samostatné tabulky identifikátorů/atributů, velkoobchodní nabídky, další fyzické sklady, rozsáhlé nové API a automatický nákup nejsou součástí tohoto úkolu. Závazný směr je **současný systém + odkazy partnerů po variantách + následně přesná dostupnost asistence**.

## Historický plán a průběh z 26. 10. 2026

Následující seznam a datované záznamy zachycují tehdejší mezistavy, nikoli aktuální seznam úkolů. Později byly dodány přesné variantní cenové zdroje, adaptér 4camping, synchronizace partnerské dostupnosti a evidence alternativních nabídek. Aktuální pravidla jsou v [cenovém automatu](supplier-prices.md), [katalogu nabídek](partner-offer-catalog.md) a [kontrole projektu](audit-2026-10.md). Podle vět „zatím neaktivovat“ nebo „zůstává otevřené“ v historických záznamech se hotové části nevypínají ani znovu neimplementují.

1. **Aktuální krok — editor a ochrana režimu:** společný vzhled partnerů u produktu bez variant i u kombinací; fotografie vedle konkrétní nabídky; nadbytečné převzaté adresy oddělit od aktivních. Dosavadní společné zdroje zachovat pro cenový automat, dokud není převeden. Ochránit vlastní prodej před partnerskou cenovou automatikou včetně rozpracované fronty a ručního převzetí.
2. **Cenové jádro a evidence nabídek:** oddělit produkt/variantu, partnerskou nabídku a pozorování ceny/dostupnosti. Jednoduchý produkt má jednu základní nabídku bez zavádění umělého fyzického skladu. Připravit aditivní migraci společných zdrojů, stabilní ID a tombstones; cenu vybírat z aktivního partnera a přesné kombinace. Starou tabulku neodstraňovat, dokud není ověřen převod editoru, importů, exportů, fronty i objednávkových snímků. Společná modelová stránka je zdroj vyhledávání, nikoli druhá zákaznická nabídka. Worker rozdělit na výběr zdrojů, načtení/cache, párování, cenové rozhodnutí a atomický zápis; zachovat společné limity, zámky a pokračování po přerušení.
3. **Fotografie podle barev:** přiřazení již uložených souborů ke skupině barvy, nezávisle na velikosti. Každá skupina má seřazenou galerii a hlavní snímek. Nepovinné odlišné foto konkrétní kombinace má přednost. Editor ve stávajícím dialogu fotografií: přetažení i ovládání klávesnicí, otevření partnerské nabídky v nové kartě, viditelné nepřiřazené fotografie, hromadné použití pro všechny velikosti dané barvy. Změny s kontrolou verze; žádné hádání barev z názvů souborů a žádné kopírování souborů do dalších složek. Zobrazování galerie musí používat stejné přiřazení v editoru i u zákazníka.
4. **Dostupnost asistence (D):** po přesné identitě nabídky pravidla 3 / skutečný počet / poslední kus. Náhled dopadu, stáří důkazu, souběh rezervací a bezpečný přechod zpět na skutečný vlastní sklad. Zatím neaktivovat.
5. **Alza:** oddělit odmítnutí HTTP požadavku od zákazu v obsahu robots.txt a od chyby parseru. HTTP 403 při načtení robots znamená, že se parser ceny vůbec nespustil. Veřejný robots byl při průzkumu dostupný a běžné produktové cesty pro obecný bot nezakazoval; to nedokládá přístup z hostingu obchodu. Veřejně doložený vstupní XML feed pro prodejce na AlzaTrade není výstupní ceník Alzy. Případné oficiální API/feed zapojit až podle skutečné dokumentace a oprávnění účtu; nezavádět odhadnuté endpointy, maskování klienta ani obcházení odmítnutého přístupu.

Při každé etapě aktualizovat její stav a ověřit ceny, množství, režimy a identitu variant před/po. Etapy 2–4 jsou otevřené; tato změna je nepovažuje za hotové.

### Dodaný krok 1 (26. 10. 2026)

Editor jednoduchého produktu i variant používá společné šablony, viditelné formuláře a náhled přiřazené fotografie. Při změně fotografie v tabulce se náhled aktualizuje bez zápisu; platí až běžné uložení variant. Chybějící vlastní fotografie je výslovně označená jako společná, nikoli jako ověřená barva. Jediné tlačítko „Partnerské odkazy“ vede na aktivní editor.

Převzatý duplicitní odkaz je ve vedlejším rozbalení pouze tehdy, když stejná varianta a stejný partner mají čerstvě ověřený přesný cíl. Nejde o mazání. Společné zdroje u variantních produktů zůstávají jednou ve spodním rozbalení pro dosavadní cenový automat. Jednoduchý produkt nadále používá starou tabulku; shodný vzhled není migrace datového jádra. Formulář společných odkazů nově používá kontrolu obsahu a produktový zámek proti přepsání souběžné změny/importu.

Cenový automat zařazuje, zpracovává a přebírá ceny jen pro asistovaný nákup. Rozpracovanou kontrolu po přepnutí na vlastní prodej ukončí bez změny cen. Testy zahrnují i starý výsledek s aktuálním fingerprintem, odmítnutí opakování a zachování skladu a odkazů při přepnutí. Diagnostika HTTP 403 vysvětluje odmítnutí přístupu; přístup k Alze tím není vyřešen.


### Dodaný krok 1b a ochrana cenových podkladů (26. 10. 2026)

Zákaznický náhled reaguje již na barvu, velikost zůstává povinná pro vložení konkrétní varianty do košíku. `VariantImages` sdílí fotografii mezi velikostmi stejné barvy; vlastní snímek kombinace má přednost a vypnutá varianta není zdrojem děděného snímku. Stejná pravidla platí v PHP přímém odkazu i v administraci. Řazené galerie barev a přetahování v dialogu fotografií jsou stále otevřené.

Editor ukládá změněné varianty hromadně. „Přiřadit celé barvě“ připraví stejnou fotografii pro všechny její velikosti. Přímo u fotografie se pak zobrazí „Uložit všechny varianty“, které odesílá stejný formulář jako hlavní hromadné uložení nad tabulkou. Uloží i ostatní připravené změny variant. Označení „Čeká na uložení“ popisuje návrh, nikoli chybu ukládání. Partnerské odkazy mají výslovně samostatné uložení. Rozpracované varianty se při něm uchovají v dané kartě pouze při shodě verze inventáře; souběžná změna skladu nebo cen vyvolá upozornění a starý návrh se automaticky neobnoví. Bez JavaScriptu zůstává výslovně označené uložení jednotlivých řádků.

Cenový snapshot nově obsahuje stabilní identity aktivních variantních odkazů (`link_key`, `variant_key`, `partner_key`, `url`). Přidání, odebrání či změna adresy/partnera zneplatní starý výsledek při atomickém převzetí. Čas, verze a výsledek pravidelného ověření ani popisek cenu nezneplatňují. Evaluátor 7 jednorázově zopakuje nedořešené výsledky z cache. Tato ochrana **ještě nepřevádí výběr cenových zdrojů na variantní odkazy**: fronta nadále načítá společné zdroje a rozsah ceny prokazuje dosavadními adaptéry. Úplný převod nabídek, galerie a dostupnost D zůstávají otevřené.


### Dodaný krok 2a — variantní cenové zdroje (26. 10. 2026)

`VariantOfferPlan` vybírá aktivní variantní odkazy místo společných zdrojů u převedených
produktů. `VariantOfferEvaluation` váže jednotlivá pozorování na stabilní ID odkazu,
variantu a partnera. Worker je ukládá průběžně do úlohy a atomicky přebírá až úplný,
čerstvý a nerozporný soubor jednoho partnera. Neúplný soubor nelze schválit jako společnou
cenu. Při uloženém přesném URL se ověřuje právě jeho SKU; shodné vlastnosti jiného SKU
nepřepíšou ruční volbu. Původní modelové odkazy smí dodat jen prokázanou přesnou nabídku.
Smazané variantní odkazy neobnovují starý produktový zdroj.

Důkazy a časy jsou v diagnostice verze 3, offline replay a přehledu konkrétních variant
v historii cen. Tlačítko diagnostiky a chráněný POST `supplier-price-probe` ověřují načtení
bez změny cen, skladu či úloh; sdílí cache a omezení workeru. Schéma ani fyzický sklad
nebylo potřeba přestavovat. Jednoduché produkty bez aktivních kombinací zůstávají na
jedné společné nabídce. Rozšíření objednávkového snímku o partnerský link je dál otevřené.

Živá kontrola po nasazení 26. 10. 2026 v 16:06 českého času doložila všech 8 variant
Patizon D590 (různé dodavatelské ceny 9 966 / 10 439 / 10 490 Kč). Výsledek `checked`
uchoval produkty, prodejní nastavení, ceny variant i jejich nastavení přesně beze změny.
MySQL, MariaDB, PHP 8.1/8.4, kompatibilita syntaxe 8.0 a testy prohlížeče prošly.

Alza: nedostupný robots.txt se posuzuje dle RFC 9309 odděleně od veřejné produktové
stránky. Skutečný produktový požadavek z hostingu 26. 10. 2026 také vrátil 403;
plné načítání Alzy proto není hotové a vyžaduje povolený zdroj/přístup partnera.
Gramino, Pod7kilo, HUDY a 4camping mají doložené produktové odpovědi 200 s cenou.
4camping zatím nemá adaptér přesných barevných nabídek. Dalších 14 nevyřešených
produktů postrádá úplný důkaz variant, jeden jednoduchý produkt vyžaduje kontrolu
identity a šest Alza produktů nemá převzatou cenu. Tyto stavy nejsou důvod k hádání
vlastností nebo cen. Galerie barev, asistované množství/rezervace (D), doplnění chybějících
shod a objednávkové snímky zůstávají dalšími kroky.
