# Potvrzení aktuálního souhrnu objednávky

Závěrečný souhrn v pokladně je chráněný pro **vlastní prodej, asistovaný nákup
i smíšený košík**. Pokud se mezi zobrazením souhrnu a odesláním změní cena,
položky, množství, prodávající, režim prodeje, rozpis asistence, doprava, kontakt
nebo vybraná platba, objednávka se neuloží. Zákazník dostane aktualizovaný souhrn
a sám ho znovu potvrdí. Košík a kontaktní údaje zůstanou zachované.

`CheckoutReview` podepisuje údaje načtené serverem. Formulář obsahuje podpis
u každé objednávky, také bez JavaScriptu. Chybějící, poškozený či zastaralý podpis
se odmítne před vytvořením objednávky, rezervací skladu, poštou nebo platbou.
Tím se řeší například zvýšení ceny produktu či dopravy, zatímco zákazník nechá
souhrn otevřený, nebo změna košíku v druhé kartě prohlížeče.

HMAC používá neveřejný `checkout_key` uložený v relaci, který se mění při
úpravách košíku, doručení a platby i po dokončení objednávky. Veřejný CSRF token
zůstává samostatnou kontrolou POST formuláře a neslouží jako podpisový klíč.
Opětovně otevřené souhrny stejného pokusu se shodnými údaji zůstávají platné;
starý souhrn nelze použít po vyprázdnění a opětovném naplnění košíku.

U asistence se dál ověřují také publikované verze smluvních podmínek a souhlasy.
Změna adresy výdejního místa při ověření u dopravce aktualizuje uložené doručení
a vyžádá nové potvrzení, aby zákazník neuvízl v opakované chybě se starou adresou.

Ochrana potvrzení nemění ceny, sklad ani již přijaté objednávky. Nenahrazuje
transakční kontrolu dostupných kusů v `OrderRepository` a skladových repository.
Nasazení nevyžaduje migraci; dříve otevřený souhrn je nutné jednou obnovit.

Ověření: `tests/checkout-review.php` (skutečný kontrolér a hranice zápisu),
`tests/assisted-purchase-database.php` (reálné změny cen a režimů na MySQL/MariaDB)
a testy vykreslení pokladny.
