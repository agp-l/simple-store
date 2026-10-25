# Odkazy partnerů u variant a dostupnost asistovaného nákupu

Stav: **upravený návrh k implementaci**, 25. 10. 2026. Upřesnění uživatele zde nahrazuje širší návrh z commitů `dd14f4f` a `2e26198`. Současný provoz popisují [varianty](product-variants.md), [asistovaný nákup](assisted-purchase.md) a [cenový automat](supplier-prices.md). Tento dokument sám nemění kód, databázi, ceny ani sklad.

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

Limit 3 není jen maximum jednoho košíku, které lze obejít více souběžnými objednávkami. Využije se nynější transakční omezení množství a rezervací. U asistence jde o rezervaci **našeho prodejního limitu**, nikoli o rezervaci u partnera.

Kontrola nesmí slepě nastavit volný limit zpět na 3 nebo `N`, pokud z něj již čerpají rozpracované asistované objednávky. Opakované vyhodnocení téhož načtení nic nenavyšuje. Čerstvé načtení a naše nevyřízené závazky se vyhodnotí společně, konzervativně vůči nejasnému výsledku nákupu u partnera. Samotné označení externí expedice nesmí před vyřešením závazku znovu uvolnit celý partnerský limit.

Před aktivací otestovat dokončení, storno, obnovení objednávky, externí expedici, opakovanou kontrolu a souběh. Současné `release()` a `reopen()` mění kusy bez rozlišení původu limitu; doplnit kontroly respektující režim objednávky a novější stav partnera. Vrácení staré rezervace nesmí přičíst kusy do jiného režimu nebo překročit nový limit po zjištění vyprodání. Zachová se dosavadní historie a mechanismus, nikoli nekontrolované přičítání po změně dostupnosti.

## Přepnutí režimu produktu

Přepnutí nikdy nepromění partnerský limit 3 ve tři fyzické kusy a vlastní počet nevydává za počet partnera. Správce uvidí, který režim a množství se použijí. Návrat na vlastní prodej vyžaduje ověření skutečného počtu; přechod na asistenci platný partnerský údaj nebo výslovně nastavený počáteční limit.

S nevyřešenými rezervacemi se přepnutí zablokuje do bezpečného vypořádání. Staré objednávky zachovají původní režim a ceny; jejich pozdější storno či obnovení musí respektovat přechod režimu, nikoli přičíst staré kusy do nové interpretace zásoby.

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
| A. Odkazy variant | Jedna doplňující tabulka, editor, soukromý JSON; opakovatelné převzetí společných odkazů ke všem současným variantám. | Stejné ceny, režimy, kusy, variantní ID a objednávky před/po; přesný odkaz se nepřepíše. |
| B. Přímý odkaz | `?variant=...` pro existující varianty. | Reload, bez JS, mobil, neplatné ID, jazyk a správná varianta v košíku. |
| C. Přesné URL partnerů | Ruční doplnění a automatické návrhy ze současných zdrojů. | Převzatý odkaz na jinou velikost není potvrzený; prodávající se nepřepne. |
| D. Dostupnost asistence | Pravidla 3 / skutečný počet / poslední kus, texty, ochrana rezervací a přepnutí režimu. | Náhled dopadu a malý vzorek; vlastní sklad chráněný, souběh objednávek neobejde limit. |

Každá etapa má vlastní commit a odpovídající testy. Schéma je doplňující, s podporou prefixu tabulek a MySQL/MariaDB. Převzetí odkazů běží po produktech s kontrolou verzí, pokračováním po přerušení a bez zásahu do skladu. GET nesmí spouštět migraci ani vzdálené načítání.

Není důvod kvůli etapě A převádět 179 jednoduchých produktů na nový skladový model, znovu slučovat produkty ani řešit připravený, ale nezapnutý sklad variant Altra Lone Peak/Petzl Actik. Odkazy připojíme k existujícím identitám. Tyto počty pocházejí z inventury 25. 10. 2026, před zápisem je nutné zjistit aktuální stav.

## Odložené části původního návrhu

Nový registr produktů, povinná výchozí varianta každého produktu, samostatné tabulky identifikátorů/atributů, velkoobchodní nabídky, další fyzické sklady, rozsáhlé nové API a automatický nákup nejsou součástí tohoto úkolu. Závazný směr je **současný systém + odkazy partnerů po variantách + následně přesná dostupnost asistence**.
