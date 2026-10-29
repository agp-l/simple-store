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

Katalog nyní používá [výběr partnerů celého košíku](basket-partners.md). Samotná kontrola nabídek dál nemění výchozího partnera produktu ani přijaté objednávky.
