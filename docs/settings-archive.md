# Záloha nastavení JSON

V administraci otevři **Nastavení → Export** a stáhni JSON. Záloha obsahuje skutečné přístupové klíče a SMTP/Google secret v přenositelné podobě. Uchovávej ji soukromě. Export nic nezapisuje do databáze a nespouští dotazy do platebních bran ani odesílání pošty.

## Obnova

1. Nahraj JSON v téže záložce. Výchozí režim pouze doplní prázdné/chybějící hodnoty; `false` a `0` jsou vyplněné hodnoty. Pro obnovu odlišných již vyplněných hodnot vyber výslovně přepsání.
2. Prohlédni současné a navržené hodnoty. Prázdné řetězce, `null`, prázdná pole a vynechané klíče nikdy nic nemažou. Kolekce se slučují, chybějící položky zůstávají.
3. Stáhni zálohu současného stavu před importem, potvrď její uložení a teprve potom použij změny. Náhled platí 30 minut a je uložen šifrovaně v administrační session.
4. Pokud mezitím někdo nastavení změnil, vytvoř nový náhled. Import ověřuje celý otisk nastavení pod zámky databáze. Chyba kteréhokoli zápisu vrátí celou transakci. Souběžný pracovník dodavatelských cen import dočasně zablokuje.

Exportovaný soubor lze upravit na částečný import ponecháním potřebných oblastí/polí v `data`. Zachovej `format` a `version`. Změna identity účtu brány vyžaduje odpovídající klíče. Automatická kontrola ověří formát a návaznosti, nikoli platnost klíče u poskytovatele.

## Rozsah

Automaticky se přenáší nastavení objednávek (dopravci, ceny a metody dopravy, bankovní účet, Fio, Comgate, GoPay, BTCPay, odhady doručení, orientační BTC ceny a odkaz na podmínky), Google, SMTP, texty webu a e-mailové šablony, údaje provozovatele, importní prompt a globální volby dodavatelských cen. Uložené vlastní hodnoty mají v exportu přednost před výchozími. Neznámé již uložené položky se zachovávají.

Sekce `reference_only` přikládá doménu, předchozí adresy, souborovou konfiguraci, menu, kategorie, partnery, jejich dopravní náklady a číselné řady. Import ji nezapisuje: změnu domény proveď přes **Doména a přesun**, menu a partnery přes jejich vlastní import. Cizí ID nebo čítače účetních dokladů se nesmějí slepě přepisovat. Existující Google/platební návratové adresy se dál odvozují z konfigurace cílové instalace.

Cron klíče jsou uložené jako jednosměrné otisky; export otisky zachovává, ale nedokáže z nich obnovit původní URL klíč. SMTP a Google secret se při importu zašifrují klíčem cílové instalace. Pokud nelze původní secret dešifrovat, export se odmítne místo vydání neúplné zálohy.

Nejde o úplnou zálohu databáze nebo souborů: nejsou zde objednávky, zákazníci, účty správců, produkty/varianty, fotografie, účetní zápisy ani databázové heslo. Pro obnovu celého e-shopu zálohuj také databázi a soubory včetně chráněné konfigurace a `config/.smtp-key.php`. Nasazení této funkce nemá migraci ani automatický zápis do tabulek nastavení.

API klíče se na výslovný požadavek správce zobrazují v escapovaných administračních polích. Odpovědi administrace i JSON používají `no-store`. Vyprázdnění pole v běžném formuláři klíč zachová; odstranění vyžaduje navíc příslušné zaškrtávací pole. Přístupové údaje se neposílají zákazníkům ani do logů importu.
