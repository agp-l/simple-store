# Comgate v obchodě

Platební brána používá REST API v2.0. Platba vzniká na serveru, objednávka a každý pokus o platbu se ukládají zvlášť. Prohlížeč zákazníka se přesměruje na přesnou URL z odpovědi Comgate. Samotný návrat z brány objednávku neoznačí jako zaplacenou: příchozí JSON notifikace i ruční kontrola stavu se ověřují autentizovaným dotazem na Comgate a porovnáním ID transakce, částky v haléřích, měny CZK, čísla objednávky a testovacího režimu.

## Zapojení

1. V administraci v **Databáze** aktualizujte SQL tabulky. Nová tabulka `shop_comgate_payments` zachovává jednotlivé pokusy a `shop_invoices.payment_method` rozlišuje způsob platby na faktuře.
2. V **Nastavení obchodu** zadejte merchant ID, secret a veřejnou HTTPS adresu kořene této instalace (například `https://obchod.cz` nebo `https://obchod.cz/simple-store`). Nejdřív zapněte **Testovací platby** a potom **Povolit Comgate**. Tajný klíč nepatří do Git repozitáře.
3. V klientském portálu Comgate nastavte adresu pro PUSH na `https://obchod.cz/comgate-callback.php` (při instalaci v podsložce `https://obchod.cz/simple-store/comgate-callback.php`). Návratové adresy PAID, PENDING a CANCELLED nastavuje aplikace jednotlivé platbě přes API; v portálu lze jako výchozí adresu nastavit veřejnou stránku obchodu. Pro připojení API povolte odchozí veřejnou IP adresu hostingu. PHP potřebuje rozšíření `curl` a platný systémový seznam certifikátů pro HTTPS.
4. Vytvořte zkušební objednávku a dokončete virtuální testovací platbu. Zkontrolujte callback, zaplacení v objednávce, fakturu a možnost připravit zásilku. Zrušená platba umožní nový pokus; stav `AUTHORIZED` objednávku nezaplatí.
5. Teprve po povolení ostrého provozu Comgate vypněte testovací režim. Nové objednávky pak používají produkční platby. Dříve vytvořené testovací platby zachovávají svůj režim v uloženém pokusu.

Comgate neposkytuje veřejně zdokumentované anonymní merchant ID a secret. Bez přístupů lze spustit automatizované testy s falešným API (`tests/comgate-database.php` v CI), ale nelze provést skutečnou testovací platbu na jejich bráně. Testovací režim Comgate stále vyžaduje vlastní přístupové údaje. Při nejasné odpovědi na založení platby se další požadavek automaticky neodešle, aby nevznikla druhá transakce; stav je nutné prověřit v klientském portálu podle čísla objednávky.

Oficiální podklady: [REST API](https://apidoc.comgate.cz/api/rest/), [PUSH notifikace](https://apidoc.comgate.cz/push-notifikace/), [testování a zprovoznění](https://help.comgate.eu/docs/open-source-reseni).
