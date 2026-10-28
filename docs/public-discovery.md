# Veřejná data pro vyhledávače

Publikované detailové stránky obsahují serverem vykreslené schema.org JSON-LD. Jednoduchý produkt používá `Product`/`Offer`, variantový `ProductGroup`/`hasVariant`. Každá aktivní varianta má vlastní stabilní URL `?variant=…`, cenu CZK, dostupnost a odpovídající obrázek. Cena a dostupnost vycházejí ze stejného dekorovaného modelu jako zákaznický detail. Interní nákupní ceny, partnerské limity, poznámky, vypnuté varianty a koncepty se nepublikují. U asistovaného nákupu se zveřejňuje zákaznická cena služby/nákupu z detailu; volitelný údaj `seller` se nepřidává, aby neoznačil jiný právní vztah.

Kanonická adresa směřuje na veřejný detail. Variantové URL předvyberou konkrétní kombinaci, jejich canonical zůstává společný. Administrace, editace, hledání, filtry a chybové stránky používají `noindex`; nevkládají produktové JSON-LD. Platné stránky dalšího výpisu mají vlastní canonical s `offset`.

`/sitemap.xml` je index; `/sitemap-1.xml` a další části obsahují po nejvýše 1 000 aktuálních publikovaných produktů, stránek a článků. Na první části jsou také jazykové titulní stránky. Používá se allowlist jazyků a ověřená veřejná gramatika URL. Časy změn se nevymýšlejí. `/robots.txt` odkazuje na sitemap a omezuje soukromé cesty. Ochranu soukromých dat dál zajišťuje přihlášení a serverová pravidla, nikoliv robots.txt.

Adresy vycházejí z `public_base_url` v místním `config/installation.json` (viz [přesun instalace](moving-installation.md)), nikdy z HTTP Host. Při nasazení na jinou doménu či do podadresáře je nutné nastavit celý HTTPS kořen instalace. Neshoda podadresáře vypne absolutní metadata; sitemap vrátí 503. Veřejné discovery endpointy nevytvářejí relace, nevolají partnerské ani bitcoinové API a lze je cachovat 5 minut. Výpadek databáze vrací 503 s Retry-After; nevydává prázdnou úspěšnou mapu.

Strukturovaná data pomáhají strojovému porozumění; negarantují indexaci nebo zobrazení rozšířeného výsledku. Samostatné administrační JSON diagnostiky zůstávají neveřejné.
