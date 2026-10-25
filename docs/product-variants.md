# Varianty produktu a samostatný sklad

Jeden produkt má společný název, popis, kategorii, galerii a výchozí cenu. Každá skutečně prodávaná kombinace vlastností má vlastní stabilní identitu, volné kusy, dostupnost, SKU a případně jinou cenu či fotografii. Názvy vlastností nejsou pevně dané: může jít o velikost, barvu, délku, objem nebo jejich kombinace. Varianty a sklad jsou společné pro překlady produktu.

## Použití v administraci

1. Otevři produkt k úpravě → **Varianty a sklad → Vytvořit varianty**.
2. Zapiš například `Velikost: S | M | L` a na další řádek `Barva: Modrá | Červená`. Vznikne šest kombinací. Pro jednu kombinaci napiš u každé vlastnosti jednu hodnotu. Nevyráběj kombinace, které dodavatel neprodává; lze je také vypnout sloupcem **Nabízet**.
3. Stiskni **Vytvořit kombinace**. Nové varianty vzniknou s nulou kusů a společnou cenou, i když produkt rezervují otevřené objednávky. Tvorba kombinací nemění režim skladu, dosavadní společné kusy ani rezervace objednávek. Opakované vytvoření stejných kombinací nepřidává duplicity.
4. Vyplň skutečné volné kusy a dostupnost v tabulce a stiskni **Uložit celou tabulku variant**. Pokud dosud používáš společný sklad, teprve po uložení otevři **Zapnout sklad variant** a potvrď přepnutí. To vynuluje původní společný počet; systém ho mezi barvy nebo velikosti nerozděluje. Dokud produkt rezervují objednávky, přepnutí je zablokované a panel vysvětlí proč. Příprava a úpravy tabulky jsou možné i během blokace. S JavaScriptem uloží celou tabulku také tlačítko na řádku. Bez JavaScriptu funguje uložení každého řádku zvlášť. Při konfliktu nebo chybě zůstanou zadané hodnoty ve formuláři; konflikt vyžaduje nový stav skladu, nepřepisuje se potichu.
5. V panelu **Prodejní cena produktu** (bez variant) nebo **Společná cena produktu** použij **Upravit cenu**, napiš částku v celých Kč a potvrď Enterem nebo kliknutím mimo pole. Cena je dostupná i u produktu bez variant; odkaz vede také od ceny dodavatele ve Způsobu prodeje. Nové varianty ji přebírají automaticky. U již vyplněných cen použij **Použít společnou cenu u všech variant** a **Uložit změny variant**. Tím vznikne trvalé přebírání, ne kopie dnešní částky; další změna ceny produktu se projeví u všech variant bez výjimky. Tlačítko **Vrátit původní ceny** vrátí právě připravené sjednocení. Sloupec **Vlastní cena Kč** vyplň jen u skutečně dražší nebo levnější varianty. Bez JavaScriptu tlačítko sjednocení rovnou uloží společné ceny, ostatní údaje ponechá beze změny.
6. Prázdná cena a volba fotografie **Společná** přebírají údaje hlavního produktu. Stav **Skladem** s nulou kusů je zákazníkovi nedostupný. **Na objednávku** dovoluje nákup bez lokální rezervace, stejně jako u jednoduchých produktů.

Zákazník vidí dostupnost, nikoli počet kusů. Výběr barvy a velikosti ukazuje jen nabízené kombinace; nemožné nebo vyprodané kombinace nelze objednat. PHP kontroluje identitu a cenu znovu při objednávce. Bez JavaScriptu funguje jeden úplný seznam kombinací s cenami. Katalog uvádí nejnižší variantní cenu (při různých cenách „od“) a podle této ceny také řadí.

Změna popisu ani obnova jeho starší revize nemění sklad variant. Vyřazená varianta zůstává interně dostupná pro historické rezervace; její původní vlastnosti se nepřepisují. Jinou kombinaci vytvoř nově. Změna režimu skladu není možná, dokud zde objednávka rezervuje lokální kusy: nejdřív ji vyřiď, stornuj nebo předej externímu dodavateli. Po návratu ke společnému skladu nastav jeho skutečný počet; uložené zásoby variant se automaticky nesčítají.

Hromadné nastavení skladu v **Nastavení → Produkty** nastaví zadaný počet a dostupnost každé nabízené variantě zvlášť. Vyřazené kombinace nezmění. Počet znamená volné kusy bez právě rezervovaných objednávek.

## Převod duplicitně založených velikostí

U hlavního produktu nejdřív vytvoř varianty pro kusy, které už v něm vedeš. Pak otevři **Převést samostatný produkt na variantu**, zadej slug převáděného produktu a přesně jednu kombinaci vlastností. Náhled ukáže název, kusy a cenu. Teprve potvrzení převede volné kusy, fotografii, cenu a soukromé dodavatelské odkazy.

Původní produkt se skryje ve všech jazycích a jeho obsah zůstane zachovaný jako koncept. Jeho veřejné adresy přesměrují na hlavní produkt, pokud je cílový překlad zveřejněný. Objednávky a jejich uložené položky se nemění. Opakované odeslání stejného převodu se odmítne. Zdroj nesmí mít vlastní sklad variant ani nevyřízené lokální rezervace. Před převodem ověř, že jde skutečně o tentýž výrobek; systém to neodhaduje podle podobnosti názvů.

Zachování konceptu umožňuje opravu omylu: ručně vrať správné kusy mezi původním produktem a variantou a původní produkt znovu zveřejni. Přesměrování platí pouze, pokud se původní veřejný produkt nenašel; po jeho zveřejnění se neuplatní. Není zde automatická reverzace již prodaných kusů.

## JSON a ZIP

Volitelné `product.variants`:

```json
{
  "enabled": true,
  "items": [
    {
      "choices": {"Velikost": "S", "Barva": "Modrá"},
      "quantity": 3,
      "stock_status": "in_stock",
      "sku": "BUNDA-MODRA-S",
      "price_czk": null,
      "image_path": "",
      "enabled": true
    }
  ]
}
```

U nového produktu lze vynechat `items[].id` i `variants.expected`; systém je vytvoří. Pro aktualizaci zachovej stabilní `id` a `expected` z čerstvého exportu. `expected` hlídá také změny skladu z objednávek, zatímco kořenové `revision` hlídá společný obsah. Vynechané `variants` nic nemění. Při jejich dodání seznam nahrazuje aktivní definice; vynechaná stará identita se vyřadí, nikoli fyzicky smaže. Prázdný seznam znamená produkt bez nabízených variant.

Jednotlivý JSON a ZIP zapisují výslovně uvedené skladové počty variant. Hromadný JSON je přepíše pouze s volbou **Přepsat také skladové počty**; jinak uchová stávající kusy a nové varianty mají nulu. Režim obnovy zálohy načte aktuální očekávanou verzi při přípravě importu, následné souběžné změny ale stále odmítne. Záloha více jazyků zapíše shodný společný sklad pouze jednou. ZIP kopie začíná vždy s nulou kusů, aby nekopírovala fyzickou zásobu.

Fotografie varianty v ZIP používá `images/soubor.jpg` a musí být přibalena. Po importu odkazuje na soubor v knihovně. Fotografie používaná variantou je chráněná před smazáním. Nejvýše 1 000 variant, 1–8 vlastností, 1 MiB JSON na produkt, 32 MiB hromadný JSON; hosting může mít menší limit POST.

## Implementace a ověření

- `ProductVariants`: nezávislá validace, generování kombinací a dostupnost.
- `ProductVariantRepository`: verzované definice a sklad, zápisy v transakci.
- `ProductVariantStock`: konkrétní rezervace a jejich vrácení/spotřebování.
- `ProductVariantForm` a `ProductVariantMerge`: vstup z formuláře a potvrzovaný převod duplicit.
- Tabulky `shop_product_variant_settings`, `shop_product_variants`, `shop_order_variant_reservations`, `shop_product_redirects`; schema aktualizace pouze přidává tabulky. První oprávněné uložení je vytvoří také samo. Nasazení nemění existující produktové záznamy ani jejich režim skladu.
- Objednávka uchovává `variant_key`, SKU, volby, skutečnou cenu a fotografii ve svém snímku. Storno či smazání neodeslané objednávky vrátí rezervaci přesně jednou. Odeslané kusy se pouhým smazáním objednávky nevracejí. Převod na externího dodavatele uvolní lokální rezervaci, návrat ji musí znovu získat.
- Inventární řádek hlavního produktu slouží jako společný zámek pro nákup i úpravy variant. U více produktů se zámky získávají podle klíče. Konflikty verze jsou viditelné, nikdy se nepřebíjí posledním zápisem.
- Testy: `tests/product-variants.php`, `tests/product-variants-database.php` (MySQL i MariaDB), `tests/product-variants-browser.mjs` (Chromium, desktop, mobil a bez JS).

## Hromadné vytvoření při spuštění obchodu

V **Nastavení obchodu → Produkty → Hromadné vytvoření variant** vyplň počet kusů každé nové varianty (předvyplněno **10**) a zobraz náhled. Systém použije uložené volby produktu, včetně staršího pole velikostí. Dvě barvy a tři velikosti vytvoří šest variant, při deseti kusech celkem šedesát kusů. Ověř, že všechny kombinace skutečně prodáváš.

Náhled vyjmenuje dotčené produkty, počet kombinací a původní společný sklad, který se po potvrzení vynuluje. Novým variantám nastaví zadaný počet a dostupnost Skladem; nula je zákazníkovi nedostupná. Cena a fotografie se dědí z hlavního produktu, SKU zůstává prázdné. SKU je nepovinný interní skladový kód, například `BUNDA-M-MODRA`; není to čárový kód.

- Existující varianty (i vypnuté) se přeskočí, aby se jejich identita a zásoby nepřepsaly. Pro doplnění jednotlivého produktu použij **Přidat kombinace**; pro úpravu zásob už vytvořených variant použij původní **Hromadná úprava produktů**.
- Produkty bez voleb, s rezervovanými kusy nebo s neplatnými/příliš početnými kombinacemi se přeskočí s důvodem. Limit zůstává 1 000 variant na produkt.
- Překlady sdílejí jediný sklad. Volby se berou z výchozího jazyka, není-li dostupný, z prvního překladu podle kódu jazyka. Koncepty se zahrnou, ale samy se nezveřejní. Popisy, dodavatelé a uložené ceny ani obrázky se nemění.
- Potvrzení používá jednorázový náhled v administrátorské relaci a transakci. Změna produktů, skladu nebo rezervací vyžaduje nový náhled. Opakované vytvoření nezaloží duplicitní varianty ani nepřičte další kusy.

V editoru lze výběrem varianty zobrazit její fotografii v hlavní galerii i ve zvětšení. Výchozí editovatelná cena produktu se při tomto náhledu nemění. Formulář převodu samostatného produktu po odeslání zůstává otevřený a stránka se přesune přímo k náhledu nebo chybě.


Ve **Způsobu prodeje → Nákupní ceny variant** lze obdobně použít společnou nákupní cenu dodavatele. Po sjednocení potvrď **Uložit způsob prodeje**. Jednotlivou nákupní cenu vyplň jen pro výjimku. Přepočet odměny používá skutečnou prodejní cenu každé varianty. Bez JavaScriptu tlačítko uloží celý formulář způsobu prodeje rovnou.

Formulář pro první varianty je otevřený, ukazuje počet zadaných kombinací a vysvětluje chybějící hodnoty nebo nepotvrzené přepnutí skladu. Po serverové chybě zůstává otevřený se zadanými hodnotami. Automatické obnovení po změně společné ceny počká na uložení rozepsané tabulky i formuláře nových kombinací. Vytvoření kombinací nejprve počká na dokončení ukládání ceny; neodešle se opakovaně při dvojkliku. Hromadné uložení tabulky nejprve vyčká na dokončení rozpracovaného ukládání ceny produktu. Chyba ani souběžná změna skladu se tiše nepřepíše; sklad, fotografie a SKU se sjednocením cen nemění.

## Odstranění původních karet po sloučení

V **Import a export → Sjednotit produkty** lze připravit náhled odstranění původních karet. Seznam vychází výhradně ze záloh dokončených sloučení, nikoli ze všech konceptů. Správce vybere skupiny a potvrdí odstranění.

Odstranění vyžaduje nezměněnou skrytou původní kartu bez skladu a objednávek, existující kombinace ve zveřejněném společném produktu a zachované přesměrování. Změna dat od náhledu operaci zastaví. Každá skupina se odstraní v jedné transakci. Mažou se karty a jejich revize; společné produkty, varianty, fotografie, historie objednávek a přesměrování se nemění. Úplná záloha před sloučením zůstává uložená. Automatické vrácení sloučení už po odstranění původních karet není dostupné.
