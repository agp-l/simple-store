# Prodávající: web, import a export

V **Administrace → Prodávající** má profil samostatný nepovinný **Web prodávajícího**. Je to hlavní HTTP/HTTPS adresa obchodu, nikoli odkaz na konkrétní produkt ani náhrada obchodních podmínek. Web se zadává i při založení nového prodávajícího z detailu produktu. Starší profily bez webu zůstávají použitelné.

**Exportovat prodávající do JSON** stáhne celý seznam bez ohledu na hledání. Soubor přenáší kontaktní údaje, web, podmínky, stav ověření a stálý `key`. Lokální číselná ID, verze záznamů ani vazby na produkty se nepřenášejí. Zálohu před změnou ulož.

Import přijímá soubor nebo vložený JSON do 2 MiB a 1 000 profilů. Nejprve zobrazí náhled nových, změněných a shodných profilů; změněné hodnoty lze porovnat. Teprve **Importovat profily** provede celou dávku v transakci. Pokud se od náhledu změní seznam, obsah souboru nebo ověřovací token, import se odmítne bez částečného zápisu.

Párování probíhá nejprve podle `key`, jinak podle jednoznačného osmimístného IČO. Při shodě IČO zůstává původní místní ID i `key`, takže produkty nepřijdou o svého prodávajícího. Rozpracované profily bez úplného IČO potřebují `key` z exportu. Nejednoznačné shody, dva zápisy stejného profilu a kolize identifikátoru s jiným IČO se odmítají. Opakovaný import shodných dat nevytváří další profily ani nezvyšuje jejich verzi. Import nemaže profily, které v souboru nejsou. Starší objednávky uchovávají své původní údaje o prodávajícím.

Formát: `format: "simple-store-purchase-partners"`, `version: 1`, pole `partners`. Profil obsahuje textová pole `name`, `ico`, `address`, `email`, `website_url`, `terms_url` (nevyplněná mohou být prázdná) a boolean `confirmed`. `key` lze u nového profilu s úplným IČO vynechat. `confirmed` znamená skutečné ověření nákupu jménem zákazníka a vystavení dokladu zákazníkovi; samotné dohledání kontaktů toto ověření nenahrazuje.

Po nasazení této změny použij **Databáze → Aktualizovat tabulky**. Migrace doplní volitelný web a stálé identifikátory; zachová ID, kontakty i stav ověření stávajících prodávajících. Opakování migrace existující identifikátory nemění.

## Připravený soubor

[Pod 7 kilo, HUDY a Alza](examples/prodavajici-pod7kilo-hudy-alza.json) obsahuje veřejné údaje ověřené 25. 10. 2026:

- Pod 7 kilo: [obchodní podmínky](https://www.pod7kilo.cz/stranky/obchodni-podminky/) a [kontakt](https://www.pod7kilo.cz/stranky/kontakt/).
- HUDY: [obchodní podmínky](https://www.hudy.cz/obchodni-podminky) a [kontakty e-shopu](https://www.hudy.cz/kontakty). Použit je obecný e-mail objednávek, nikoli osobní kontakt zaměstnance. Kamenné franšízy mohou mít jiného provozovatele.
- Alza: [obchodní podmínky](https://www.alza.cz/obchodni-podminky-art10.htm). Aktuálně uvádějí telefon a kontaktní formulář; obecný e-mail nebyl spolehlivě ověřen, proto zůstává prázdný.

Všechny tři profily mají `confirmed: false`. Import je připraví k doplnění a ověření, neoznačí je automaticky za schválené partnery ani je nepřiřadí k produktům.
