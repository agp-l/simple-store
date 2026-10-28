# Odhad doručení

V **Nastavení → Ceny → Kdy bude zboží u zákazníka** se zapíná společný odhad pro všechny zveřejněné produkty skladem, vlastní i partnerské. Zobrazuje se pod dostupností v katalogu (včetně dalších načtených stránek) a na detailu. Výběr varianty odhad skryje pro `on_order` a `out_of_stock`, dostupná varianta jej opět zobrazí. Sklad, ceny, dodavatelské kontroly a objednávky tento prezentační údaj nemění.

- `relative`: Zítra / Pozítří u vás, Za tři dny u vás, Za tři až čtyři pracovní dny u vás.
- `within`: U vás do tří dnů; používá horní mez.
- `date`: U vás 13. 7., případně rozsah dat. Při přechodu do jiného roku se vypíše i rok.
- Dolní a horní mez 1–30 dnů. Shodná čísla znamenají jeden termín.
- Pracovní režim vynechá víkendy a české svátky včetně Velkého pátku a Velikonočního pondělí. Kalendářní režim počítá všechny dny.
- Volitelná uzávěrka je v časové zóně Europe/Prague. Od uzávěrky včetně začne počítání následující den; nepracovní počáteční den se v pracovním režimu posune dopředu. Lhůta se přičítá k tomuto začátku, den zpracování se nepočítá. V pátek před uzávěrkou znamená 1 pracovní den pondělí; v sobotu znamená úterý.
- Relativní text zahrnuje posun po uzávěrce. „Zítra“ nikdy neoznačuje pondělí při páteční objednávce. Lhůta předpokládá objednání a zaplacení nyní; je označena jako orientační doručení po ČR, nikoli garance ani termín odeslání.

Před zapnutím zvol lhůtu odpovídající i nejpomalejšímu nabízenému partnerovi. Výchozí návrh je 3–4 pracovní dny, uzávěrka 12:00, zobrazení vypnuté. Uložený náhled v administraci používá tentýž výpočet jako veřejný web. Změny se uloží společně s nastavením cen.

Konfigurace je ve stávajícím `shop_checkout_settings.settings_json.delivery_estimate`. Nová SQL tabulka ani migrace nejsou potřeba. Ostatní části nastavení tuto konfiguraci zachovávají; starší formulář bez nového pole ji nepřepíše. Neplatná uložená konfigurace odhad bezpečně skryje. Datum se počítá jednou na serveru pro každý požadavek, bez síťového API a bez předávání platebních nastavení do veřejných šablon.

Kalendář vychází z [přehledu českých svátků ČNB](https://www.cnb.cz/cs/verejnost/servis-pro-media/harmonogramy-a-dalsi-informace/svatky-v-ceske-republice/). Při změně svátků je třeba upravit `DeliveryEstimate::isBusinessDay` a testy. Výpočet Velikonoc používá gregoriánský kalendář bez závislosti na rozšíření PHP calendar.

Ověření: `php tests/delivery-estimate.php`, `php tests/checkout-settings.php`, `php tests/catalog-render.php`, `php tests/product-variants.php`, `php tests/admin-render.php`, `node --test tests/product-variants-browser.mjs`.
