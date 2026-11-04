# Přehled obchodu

Po přihlášení vede `/admin/` na dashboard. Přímý odkaz je `/admin/dashboard/`.
Staré odkazy do editoru s `key`, `language`, `type`, `status`, `q` nebo `offset`
zůstávají funkční; stránky lze otevřít přes `/admin/contents/`.

Výchozí rozsah obsahuje zveřejněné produkty. Přepínač dovoluje zahrnout koncepty.
Počítá se jednou každý produktový klíč z aktuálních revizí: přednost má zveřejněný
překlad, potom hlavní jazyk obchodu, potom první jazyk podle abecedy. Historie a
smazané produkty se nezapočítávají.

- **Jeden kus od produktu:** nejnižší známá dodavatelská cena aktivní varianty,
  samostatně nejnižší prodejní cena. Mohou patřit různým variantám.
- **Jeden kus od varianty:** součet všech zapnutých variant; jednoduchý produkt
  představuje jednu položku. Výběry vlastností bez skladu variant nevytvářejí
  další skladové položky.
- **Dodavatelské ceny:** cena konkrétní varianty má přednost před společnou cenou.
  Chybějící cena se nevydává za nulovou cenu: zobrazuje se počet chybějících cen
  a neúplnost součtu. Bez aktivních variant nelze produkt ocenit.
- **Vlastní sklad:** pouze režim `own`, volné počty kusů po rezervacích. Externí
  dostupnost se do našeho počtu kusů ani hodnoty skladu nezapočítává.
- **Doplnění skladu:** po jednom kusu každé externí položky a každé vlastní položky
  s nulovým volným počtem. Už vlastněné kusy se znovu nenakupují.
- **Dostupnost:** produkt s alespoň jednou aktivní variantou skladem je skladem;
  jinak má přednost stav na objednávku před nedostupností.

Jde o přehled aktuálně uložených cen v Kč, ne historické pořizovací náklady.
Částky neobsahují dopravu ani přepočet DPH; neověřují živou dostupnost dodavatelů.
Dashboard nezapisuje data, nemění schéma a nespouští cron ani vzdálené požadavky.
Pět hromadných čtení probíhá v jednom databázovém snímku.

Ověření: `php tests/dashboard.php`, `php tests/dashboard-database.php` (testovací
databáze), `node --test tests/admin-layout-browser.mjs` (Playwright).
