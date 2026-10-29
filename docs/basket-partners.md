# Výběr partnerů košíku

Od 29. 10. 2026 košík používá `CartPartnerSelection` a `PartnerBasketOptimizer`. Výchozí partner v katalogu se nepřepisuje. Výběr platí pro aktuální košík a následně se uloží do objednávky. Produkty „Prodáváme my“ se do partnerské optimalizace nezapočítávají a dál rezervují vlastní sklad.

## Nastavení

1. V **Ceny dodavatelů → Pokrytí partnerů a všechny nabídky** přidej přesné alternativní odkazy a zapni jejich denní kontrolu. Pouhý odkaz bez výsledku nestačí.
2. V **Prodávající → Upravit prodávajícího → Poštovné partnera pro společný košík** vyplň běžné poštovné a případnou hranici dopravy zdarma. Jde o interní odhad jedné objednávky po ČR; checkout zatím přijímá jen CZ. Zadej tarif skutečně používaného způsobu doručení, včetně případných příplatků. Nejde o automaticky stažený ceník dopravy.
3. Prázdné poštovné znamená **neznámé**, nula znamená **zdarma**. Prázdná hranice znamená žádný práh dopravy zdarma. Uloží se společně s profilem, v jedné transakci a pod jeho verzí.

Tabulka `shop_partner_delivery_costs` vznikne aktualizací databáze nebo autentizovaným workerem, stejně jako provozní tabulky katalogu nabídek. Nevyplňujeme odhadované sazby za správce. Tarif je uložený podle stabilního `partner_key`; při smazání nepoužitého partnera se odstraní. Úplná DB záloha ho zahrnuje, dosavadní JSON přenos profilů partnerů tarif nepřenáší.

## Pravidla výběru

- Počítá se celý košík. Stejný produkt/varianta ve více jazycích či řádcích sdílí součet požadovaných kusů a jednoho partnera; jednu variantu mezi partnery nedělíme.
- Alternativa musí být aktuálně přiřazená ke konkrétní variantě, ověřená před méně než 24 hodinami, s platným otiskem vstupů a kompletním potvrzeným profilem partnera. Změny zdrojů nebo variant staré důkazy zneplatní.
- Dostupnost se řídí `PartnerStockPolicy`: samotné „skladem“ dovoluje maximálně 3 ks, zveřejněný počet musí pokrýt součet kusů, poslední kus se nenabízí. Rozporné současné nabídky stejné varianty/partnera se vyloučí.
- Používá se nákupní `amount`, zaokrouhlený nahoru na celé Kč kvůli současnému celočíselnému účetnímu modelu. Pro samotné porovnání nákladů a dosažení dopravy zdarma se používá původní částka v haléřích, aby zaokrouhlení nesprávně nepřekročilo hranici. `price_czk` z katalogu nabídek je odvozená prodejní cena a pro náklady se nepoužije.
- Pokud známe poštovné všech zvažovaných partnerů, minimalizuje se **součet nákupů + poštovné jednou za každého použitého partnera**, včetně hranice dopravy zdarma. Při shodě vyhraje méně partnerů, pak jejich priorita a stabilní identita. Dva partneři mohou být levnější než jeden. Známé celkové náklady převyšující cenu partnerského zboží checkout zastaví; zákaznické poštovné se do tohoto ochranného rozpočtu nezapočítává.
- Pokud některé sazby chybí, minimalizuje se počet partnerů, poté nákupní cena. Žádná alternativa v tomto režimu nesmí mít vyšší jednotkovou nákupní cenu než dosavadní nabídka. Nelze tím zaručit nejnižší skutečné poštovné bez znalosti tarifů.
- Pro původního partnera zachováváme kompatibilitu s ručně spravovanou nákupní cenou a aktuálním produktovým limitem, pokud neexistuje současné ověření, které jí odporuje. Čerstvé ověření s nulovým skladem či nesprávným množstvím nelze obejít starou ruční cenou. Přepnutí na alternativu vždy vyžaduje ověření. Běžné kontroly prodejnosti produktu a skladu před optimalizací zůstávají; optimalizátor sám nevrací vyřazené produkty do nabídky.

## Výkon a konzistence

Košík čte místní katalog pouze pro své produkty. Nespouští žádné HTTP dotazy na partnery. Obnovu nabídek dál zajišťuje existující worker a jeho cache/limity. Složitost hledání roste s počtem kombinací: deterministické hledání má rozpočet 100 000 uzlů. Nejdřív připraví úplný výchozí plán a všechny možné plány s jedním partnerem; při dosažení limitu vrátí nejlepší nalezený úplný plán, nikdy částečný košík. `partner_plan.complete=false` označuje, že nebylo prokázáno globální optimum. U běžných menších košíků se hledání dokončí.

Zákazník vidí zvolené prodávající už v košíku i u jednotlivých položek. Konečná cena zboží a zákaznický tarif dopravy se kvůli přepnutí nemění. Změní se rozpis zboží/služby. Kontrola objednávky podepisuje celý snímek včetně prodávajícího, nákupní ceny, zdroje a tarifu. Změna před odesláním vyžaduje nový souhlas se souhrnem.

Při rezervaci pod produktovým zámkem se znovu ověří vybraná nabídka, množství, profil a tarif; žádný jiný partner se už tiše nevybírá. Přijatá objednávka zachová `procurement` a původní údaje prodávajícího. Administrátor má v detailu objednávky odkaz na vybranou nabídku. Asistovaný nákup nesnižuje vlastní sklad. Kontrola je místní snímek dostupnosti, nikoliv rezervace u partnera; skutečnou dostupnost před provedením nákupu stále musí potvrdit partner. Post-order refresh dosavadního primárního produktu zůstává, alternativy se dál obnovují v denním katalogu.

## Ověření

- `tests/partner-basket.php`: konsolidace, levnější rozdělení, doprava zdarma, limit hledání, porovnání s úplným průchodem náhodných malých košíků, přesnost variant, konflikt cen, poslední kus, neúplný partner a podpis souhrnu.
- `tests/partner-basket-database.php`: skutečný košík, cílené čtení katalogu, tarify, opětovné ověření, změna množství/expirace/odebrání odkazu a nesnižování partnerského limitu.
- Stávající testy asistovaného nákupu kontrolují zmrazení snímku v objednávce, smluvní údaje, e-maily a oddělení vlastního skladu.
