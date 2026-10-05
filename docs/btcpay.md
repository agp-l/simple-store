# Platba bitcoinem přes BTCPay Server

Obchod vytváří faktury pomocí Greenfield API BTCPay Serveru. Cena objednávky zůstává v Kč, BTCPay podle nastavení svého obchodu zobrazí platební částku v bitcoinu. Platební stránku a QR kód poskytuje BTCPay; e-shop nepřijímá privátní klíče peněženky.

Podporovaný je také [BTCPay Server Lite](https://github.com/agp-l/BTCPayServerLite) s on-chain platbou. E-shop používá store-scoped endpointy `POST /api/v1/stores/{storeId}/invoices` a `GET /api/v1/stores/{storeId}/invoices/{invoiceId}`. Přijímá platební odkaz běžného BTCPay `/i/{invoiceId}` i odkaz Lite `/pay?id={invoiceId}`, vždy na nastaveném serveru a ve stejné instalační podsložce.

## Nastavení

1. V administraci e-shopu otevři **Databáze → Aktualizovat SQL tabulky**. Přibude tabulka `shop_btcpay_payments` pro pokusy o platbu.
2. V BTCPay vytvoř obchod, nastav mu bitcoinovou peněženku a zkopíruj **Store ID**. Založ API klíč omezený na tento obchod s oprávněními `btcpay.store.cancreateinvoice` a `btcpay.store.canviewinvoices`.
3. V BTCPay v nastavení obchodu vytvoř webhook pro události faktur (alespoň `InvoiceProcessing`, `InvoiceSettled`, `InvoiceExpired`, `InvoiceInvalid` a `InvoiceReceivedPayment`). Jeho veřejná URL je `https://tvoje-domena.cz/btcpay-callback.php`, případně s podsložkou před názvem PHP souboru. Zkopíruj **webhook secret**. Webhook musí používat HTTPS a být dostupný z BTCPay Serveru.
4. V administraci e-shopu otevři **Nastavení obchodu → BTCPay Server**. Vyplň HTTPS adresu instance, Store ID, API klíč, webhook secret a veřejnou HTTPS adresu kořene tohoto e-shopu. Pak platbu zapni. Tajné klíče se při dalším otevření formuláře nevypisují. Zálohuj databázi včetně těchto nastavení a neukládej skutečné klíče do Gitu.

BTCPay nemá společný vývojářský API klíč. Pro testy lze použít vlastní obchod a peněženku na [testnet demo instanci](https://testnet.demo.btcpayserver.org/). Vývojové testy v tomto repozitáři používají simulovaný HTTP transport, skutečnou databázi MySQL/MariaDB a nepotřebují tvůj klíč.

### Konkrétně pro BTCPay Server Lite

- V Lite vytvoř samostatný obchod pro e-shop a použij jeho **ID obchodu** a **API klíč obchodu**. `stateless_api_key`, payout klíč a administrátorský klíč sem nepatří. Lite používá jeden klíč omezený na obchod; samostatné zaškrtávání invoice oprávnění se v něm neprovádí.
- V Lite vytvoř webhook se stejnou URL `https://tvoje-domena.cz/btcpay-callback.php`. Jeho tajný klíč vlož do nastavení e-shopu. Webhook vytvoř **před** zkušební objednávkou: Lite doručuje události pouze webhookům, které existovaly při vzniku faktury.
- `server_url` e-shopu musí odpovídat veřejné `app_url` v konfiguraci Lite, včetně podsložky, například `https://platby.obchod.cz/BTCPayLite`. Apache musí zpracovávat `.htaccess`, přepisovat API URL a předávat hlavičku `Authorization` do PHP. Obě veřejné adresy nastav s HTTPS.
- V Lite musí běžet `payment_worker.php` pro zjištění plateb a `webhook_cron.php` pro jejich doručení. Při příjmu přes XPUB naplánuj také `wallet_receive_sync.php` podle konfigurace Lite. Samotný platební časovač webhooky nedoručuje.
- Faktura se vytváří v `CZK`; Lite proto musí získat platný kurz BTC/CZK a mít funkční zdroj adres i pozorování blockchainu. Pouhé otevření platební stránky nebo odpověď `New` ještě neověřuje skutečný příjem bitcoinů.

Pro zkoušku dvou instalací běžících jen na `http://localhost` nestačí jejich místní dostupnost. Nastavení e-shopu vyžaduje veřejnou HTTPS návratovou adresu a Lite při standardní konfiguraci odmítá soukromou/loopback adresu webhooku. Použij veřejné HTTPS testovací instalace; automatické testy mohou použít izolované transporty a testovací databázi.

## Průběh platby

- Po objednávce vznikne jediná uložená faktura pro jeden pokus. Opakované kliknutí otevře stejnou fakturu. Je-li její vytvoření po síťové chybě nejasné, obchod další fakturu automaticky nezakládá; zkontroluj dané číslo objednávky v BTCPay.
- E-shop zatím neposílá hlavičku `Idempotency-Key`, kterou Lite pro vytváření faktur podporuje. Ochranu před opakovaným POST zajišťuje uložený platební pokus; automatické dohledání a bezpečné dokončení nejasného pokusu je další plánované zlepšení.
- Návrat zákazníka a webhook jsou pouze podnět pro kontrolu. Server se vlastním API klíčem zeptá BTCPay na fakturu a porovná ID obchodu, číslo objednávky, fakturu, měnu a přesnou cenu. Objednávka je zaplacená až při stavu **Settled**. `New` a `Processing` čekají na potvrzení; po `Expired` nebo `Invalid` může zákazník založit další pokus. Podpis webhooku se ověřuje nad původním tělem HTTP požadavku.
- V detailu objednávky v administraci lze stav faktury také ručně obnovit. Expedice a vystavení faktury za nákup vyžadují ověřený vypořádaný pokus. Při smazání objednávky zůstane záznam externí platby pro případ opožděného potvrzení.

## Uvedení do provozu

Spusť zkušební objednávku a úhradu na testnetu; zkontroluj návrat zákazníka, příchod podepsaného webhooku a změnu objednávky ze *čeká na úhradu* na *zaplaceno*. Při přechodu na skutečnou instanci nastav její vlastní URL, Store ID, klíč a secret. Zkontroluj, že hosting může navázat odchozí HTTPS spojení z PHP cURL na BTCPay a že BTCPay dosáhne na veřejnou URL webhooku.

Rozhraní: [BTCPay Greenfield API](https://docs.btcpayserver.org/API/Greenfield/v1/) a [průvodce integrací](https://docs.btcpayserver.org/Development/ecommerce-integration-guide/).

## Test propojení s BTC Pay Lite

`tests/btcpay-lite-integration.php` spouští oba projekty přes skutečné lokální HTTP a oddělené dočasné databáze MySQL/MariaDB. Používá aktuální třídy Lite, odvozování adres z veřejného testovacího XPUB, worker a webhook outbox; na straně e-shopu skutečný cURL klient, callback a návrat zákazníka. Kurz CZK a pozorování blockchainu jsou simulované. E-mail zachytí místní testovací příkaz, žádnou platbu ani zprávu ven neposílá.

V obou repozitářích nejprve spusť `composer install`. Test potřebuje PHP CLI s cURL, mysqli, PDO MySQL a GMP a přístup k testovací databázové instanci s právem `CREATE DATABASE` a `DROP DATABASE`. Vytvoří náhodně pojmenované databáze, po skončení je odstraní; nepoužívá existující databázi ani lokální konfiguraci aplikací. Použij vyhrazený testovací server, ne produkční přihlašovací údaje.

Z kořene simple-store:

```bash
BTCPAY_LITE_PATH=/cesta/k/BTCPayServerLite \
MYSQL_TEST_HOST=127.0.0.1 MYSQL_TEST_PORT=3306 \
MYSQL_TEST_USER=root MYSQL_TEST_PASSWORD='' \
php tests/btcpay-lite-integration.php
```

Když leží repozitáře vedle sebe pod názvy `simple-store` a `BTCPayServerLite`, `BTCPAY_LITE_PATH` není potřeba. Test ověří objednávku za 1 079 Kč, odpovídající BTC fakturu a odkaz `/pay?id=…`, rozdíl mezi `Processing`, `Settled` a `Expired`, potvrzenou částečnou platbu, opožděné potvrzení, podpis webhooku, odmítnutí nesouhlasící částky/měny/obchodu/objednávky a opakované doručení bez druhé rezervace skladu, faktury či platebního e-mailu. Lokální testovací router a transport webhooku nahrazují nasazení přes Apache a veřejné HTTPS/DNS. Test neověřuje skutečné Electrum, platbu na testnetu ani SMTP; ty zkontroluj zvlášť při nasazení.

Workflow **BTCPay Lite integration** spouští tento test při pull requestu, po změně `main` e-shopu nebo ručně v GitHub Actions. Stáhne aktuální `main` repozitáře BTC Pay Lite a používá vyhrazenou MariaDB 10.11 s PHP 8.2. Samotná změna v repozitáři BTC Pay Lite workflow e-shopu nespouští; po takové změně jej lze spustit ručně.
