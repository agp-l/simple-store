# Platba bitcoinem přes BTCPay Server

Obchod vytváří faktury pomocí Greenfield API BTCPay Serveru. Cena objednávky zůstává v Kč, BTCPay podle nastavení svého obchodu zobrazí platební částku v bitcoinu. Platební stránku a QR kód poskytuje BTCPay; e-shop nepřijímá privátní klíče peněženky.

## Nastavení

1. V administraci e-shopu otevři **Databáze → Aktualizovat SQL tabulky**. Přibude tabulka `shop_btcpay_payments` pro pokusy o platbu.
2. V BTCPay vytvoř obchod, nastav mu bitcoinovou peněženku a zkopíruj **Store ID**. Založ API klíč omezený na tento obchod s oprávněními `btcpay.store.cancreateinvoice` a `btcpay.store.canviewinvoices`.
3. V BTCPay v nastavení obchodu vytvoř webhook pro události faktur (alespoň `InvoiceProcessing`, `InvoiceSettled`, `InvoiceExpired`, `InvoiceInvalid` a `InvoiceReceivedPayment`). Jeho veřejná URL je `https://tvoje-domena.cz/btcpay-callback.php`, případně s podsložkou před názvem PHP souboru. Zkopíruj **webhook secret**. Webhook musí používat HTTPS a být dostupný z BTCPay Serveru.
4. V administraci e-shopu otevři **Nastavení obchodu → BTCPay Server**. Vyplň HTTPS adresu instance, Store ID, API klíč, webhook secret a veřejnou HTTPS adresu kořene tohoto e-shopu. Pak platbu zapni. Tajné klíče se při dalším otevření formuláře nevypisují. Zálohuj databázi včetně těchto nastavení a neukládej skutečné klíče do Gitu.

BTCPay nemá společný vývojářský API klíč. Pro testy lze použít vlastní obchod a peněženku na [testnet demo instanci](https://testnet.demo.btcpayserver.org/). Vývojové testy v tomto repozitáři používají simulovaný HTTP transport, skutečnou databázi MySQL/MariaDB a nepotřebují tvůj klíč.

## Průběh platby

- Po objednávce vznikne jediná uložená faktura pro jeden pokus. Opakované kliknutí otevře stejnou fakturu. Je-li její vytvoření po síťové chybě nejasné, obchod další fakturu automaticky nezakládá; zkontroluj dané číslo objednávky v BTCPay.
- Návrat zákazníka a webhook jsou pouze podnět pro kontrolu. Server se vlastním API klíčem zeptá BTCPay na fakturu a porovná ID obchodu, číslo objednávky, fakturu, měnu a přesnou cenu. Objednávka je zaplacená až při stavu **Settled**. `New` a `Processing` čekají na potvrzení; po `Expired` nebo `Invalid` může zákazník založit další pokus. Podpis webhooku se ověřuje nad původním tělem HTTP požadavku.
- V detailu objednávky v administraci lze stav faktury také ručně obnovit. Expedice a vystavení faktury za nákup vyžadují ověřený vypořádaný pokus. Při smazání objednávky zůstane záznam externí platby pro případ opožděného potvrzení.

## Uvedení do provozu

Spusť zkušební objednávku a úhradu na testnetu; zkontroluj návrat zákazníka, příchod podepsaného webhooku a změnu objednávky ze *čeká na úhradu* na *zaplaceno*. Při přechodu na skutečnou instanci nastav její vlastní URL, Store ID, klíč a secret. Zkontroluj, že hosting může navázat odchozí HTTPS spojení z PHP cURL na BTCPay a že BTCPay dosáhne na veřejnou URL webhooku.

Rozhraní: [BTCPay Greenfield API](https://docs.btcpayserver.org/API/Greenfield/v1/) a [průvodce integrací](https://docs.btcpayserver.org/Development/ecommerce-integration-guide/).
