# Ceny dodavatelů

Administrace: `/admin/supplier-prices/`. PHP 8.1+, cURL a stávající MySQL/MariaDB. Před prvním použitím aktualizovat SQL tabulky v Administrace → Databáze. Není potřeba Python, prohlížeč na hostingu ani externí služba.

## Provoz

1. V Prodávajících vyplnit web a priority. U produktu uložit Dodavatele a Vybraného prodávajícího. Shoduje se celá doména (s/bez `www`), ne pouhá část názvu. Duplicitní profily stejné domény se nepoužijí.
2. V manageru vybrat chybějící ceny, všechny produkty nebo jeden produkt. Zařazení zároveň spustí zpracování v otevřeném okně. Při chybě připojení lze pokračovat tlačítkem. Fronta je v databázi.
3. Šetrně: nejvýše 1 HTTP požadavek za 60 s na obchod. Zrychleně: nejvýše 1 za 15 s. Větší prodleva z robots.txt nebo Retry-After má přednost. Robots a každý krok přesměrování také spotřebují tento limit. Každý worker provede nejvýše jeden vzdálený požadavek; nečeká se pomocí sleep.
4. Nová kontrola produktu nejdříve 24 hodin od zařazení, i po neúspěchu. Rozpracovaná kontrola se neduplikuje. Tentýž URL se po dobu 24 h sdílí z cache mezi produkty. Limity platí společně pro všechna okna i cron.
5. Jednoznačná CZK cena vybraného prodávajícího se automaticky uloží do **Cena dodavatele**. Haléře se zaokrouhlí nahoru na celé Kč. Změna přes 30 %, nedostupnost, nejasný URL varianty a produkty s variantovým skladem vyžadují kontrolu. Vlastní dodavatelské ceny variant zůstávají zachované. Prodejní cena ani sklad se nemění.
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
