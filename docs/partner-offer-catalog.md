# Alternativní nabídky partnerů

Administrace **Ceny dodavatelů → Pokrytí partnerů a všechny nabídky** má dva odlišné údaje: uložené zdroje a čerstvě ověřené konkrétní nabídky. Odkaz na stránku modelu sám neprokazuje cenu ani dostupnost barvy a velikosti. Nenalezený odkaz také neprokazuje, že obchod daný model neprodává.

## Přidání zdrojů

Stáhni export nabídek s identitami variant a prázdnou šablonu katalogu. Přidej pouze věcně odpovídající modely od existujících partnerů:

```json
{
  "format": "simple-store.partner-sources.v1",
  "products": [{
    "product_key": "0123456789abcdef0123456789abcdef",
    "language": "cs",
    "sources": [{"label": "Partner", "url": "https://partner.example/p/model"}]
  }]
}
```

Modelový zdroj se uloží jako společný odkaz a neověřený zdroj aktivních variant. Přesný zdroj může navíc obsahovat `variant_key` a `choices` z aktuálního exportu; kontroluje se jejich vlastnictví a shoda při přípravě i zápisu. Import pouze přidává, opakované URL přeskočí, odstraněné variantní odkazy neobnovuje. Nejde o přepsání úplného produktu. Není dovoleno dodat ceny, dostupnost, výsledky nebo čas ověření. Nezmění se primární partner, sklad ani cena. Dávka se připraví v obvyklém náhledu JSON importu a každý produkt se zapíše atomicky.

## Průběžné načítání

První tlačítko zařazení zapne denní kontrolu všech nabídek; další běhy zajišťuje existující worker. `shop_supplier_offer_scans` udržuje samostatnou obnovitelnou frontu. `shop_supplier_offers` ukládá poslední výsledek každého produktu, varianty, partnera a zdrojové URL: přesnou cenu v CZK, zaokrouhlenou cenu jádra, dostupnost, skutečně zveřejněný počet, čas načtení a vyhodnocení, důkaz identity a případnou chybu. Prázdný `variant_key` znamená základní produkt bez výběru variant.

Čtení sdílí `PriceManager` robots, cache, host reservations, SSRF ochranu a limit jediného HTTP požadavku na krok. Alternativy dostávají každý třetí krok, i když primární cena úspěšně prošla. Kontrola alternativ nic nepřebírá do prodeje. Čerstvý zdroj se nestahuje znovu kvůli každé velikosti. Přesný parser může společný dokument použít pouze při prokázané identitě kombinace. Změny vstupů zneplatní starší výsledek; odebrané zdroje ani vyřazené varianty se nepočítají. Pozastavení společného workeru zastaví i tuto frontu.

## Statistika

Produkty se počítají jednou bez ohledu na počet velikostí a jazyků. Samostatně se vykazují aktivní varianty, čerstvě ověřené produkty, kompletní pokrytí všech variant a produkty s alespoň jednou dostupnou variantou. Poslední kus se nepovažuje za nabízený. Rozporné nabídky stejného partnera a varianty se nepočítají mezi použitelné. Po 24 hodinách ověření vyprší. Překryv podle odkazů neznamená záruku splnitelnosti konkrétního košíku.

## Výběr prodávajících v košíku

`CartPartnerPlanner` při načtení košíku i dalších kroků sestaví nabídky pouze pro produkty v košíku. `PartnerAllocation` hledá nejmenší počet prodávajících pro celý asistovaný nákup; při shodném počtu upřednostní nižší nákupní cenu, pak původního partnera. Stejná varianta ve více jazycích sdílí limit kusů. Vlastní prodej se do výběru nezapojuje.

Alternativa vyžaduje aktuální zdroj a přesnou variantu, shodný otisk katalogu, ověření mladší než 24 hodin, potvrzený úplný profil prodávajícího a dostatek kusů. Poslední kus ani nejasná dostupnost nestačí; obecné „skladem“ umožní nejvýše tři kusy jedné varianty. Rozporné čerstvé nabídky partnera se vyřadí. Nákupní cena alternativy nesmí překročit dosavadní nákupní cenu položky. Zákaznická cena zůstává stejná, rozpis zboží a služby odpovídá vybranému partnerovi. Základní publikovaná nabídka vybraného partnera zůstává zálohou, dokud nezávislé ověřování nemá novější rozporný výsledek; nadále podléhá běžné kontrole produktové dostupnosti a množství.

Košík nestahuje partnerské weby a nepřepisuje primárního partnera v katalogu. Nabídky obnovuje společný worker. Nejvýše 20 000 stavů prohledávání chrání odezvu před neobvykle složitým katalogem; po vyčerpání se použije nejlepší nalezené platné řešení, ne slib matematického minima. U současného malého počtu partnerů se prohledají všechny relevantní kombinace.

Přehled „Kdo vám zboží dodá“ v košíku i před odesláním ukazuje skutečné skupiny položek. Podepsaný souhrn zahrnuje vybraného prodávajícího, cenu i nabídku. Před vytvořením objednávky se znovu ověřují aktuální zamčená data, včetně transakcí s dříve otevřeným databázovým snímkem. Změna podmínek vyžaduje nový souhrn. Objednávka uchovává neměnnou kopii prodávajícího, nákupní ceny, poplatku, zdrojové a vyhodnocené URL i času pozorování (`supplier_selection`). Administrace objednávky nabízí odkaz na tuto konkrétní nabídku. Opakovaný požadavek na již uloženou objednávku jejího partnera nezmění.

Nejde zatím o výpočet přesné částky poštovného jednotlivých partnerů: nemáme jejich sazby, limity dopravy zdarma ani pravidla rozdělení zásilek. Minimalizuje se počet prodávajících při zachování nákupního rozpočtu, nikoli garantovaný počet balíků nebo úspora v Kč. Zákazníkovi se partnerské poštovné automaticky nepřičítá; platí stávající doprava obchodu.
