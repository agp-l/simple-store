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

## Priority a přiřazení z dodavatelských odkazů

U prodávajícího nastav **Prioritu při automatickém přiřazení**, celé číslo 1–9 999. Menší číslo vyhrává; výchozí je 100. Web se porovnává s doménou adres v produktové sekci **Dodavatelé**, bez rozdílu velikosti písmen a úvodního `www.`. Jiné poddomény se neslučují a názvy firem ani texty URL se neodhadují. Více odkazů na stejný obchod znamená jediného kandidáta; shodná nejlepší priorita různých profilů vyžaduje ruční rozhodnutí. Prázdný web se nepáruje.

V **Nastavení → Produkty → Přiřadit prodávající podle dodavatelů** je aktuální souhrn i rozbalovací seznam návrhů. **Doplnit chybějící prodávající** provede přiřazení jedním kliknutím; již zvoleného prodávajícího zachová. Samostatná rozbalovací volba **Přepočítat i již vybrané prodávající** dovoluje přepsat i ruční volbu podle nových priorit. Bez jednoznačné shody zůstane stávající výběr. Operace zahrnuje koncepty a všechny jazyky, každý produkt jednou. Změní pouze přiřazeného prodávajícího a verzi nastavení; nevytváří objednávky, nezapíná asistovaný nákup, nepotvrzuje spolupráci a nemění ceny ani sklad. Po změně prodávajícího je potřeba zkontrolovat uložené ceny dodavatele. Žádné ceny ani stránky dodavatelů se nestahují.

Před zápisem se v transakci znovu zkontrolují produkty, odkazy a profily včetně priorit. Při souběžné změně se celá operace odmítne a zobrazí nový přehled. Opakování stejného přiřazení nezvyšuje verze nezměněných produktů. Produktové odkazy ani starší objednávky se nepřepisují.

Správa produktů má filtr **Prodávající a dodavatelé**: bez prodávajícího, bez odkazů, chybí alespoň jedno, nebo chybí obojí. Filtr funguje společně s hledáním, kategorií, viditelností a stránkováním. Rozpracovaný, ale existující profil se počítá jako přiřazený; odkaz na již neexistující profil jako chybějící. Karty ukazují vybraného prodávajícího a počet odkazů pouze ve správě, zákaznický katalog tyto údaje nezobrazuje.

Výběr **Partner / dodavatel** omezuje správu produktů na konkrétní obchod (`?manage=1&partner_id=ID`). Zahrnuje zvoleného prodávajícího, společné odkazy i nesmazané odkazy variant, tedy také alternativní partnery a připravené vazby v režimu „Prodáváme my“. Doména se porovnává stejným pravidlem jako při přiřazení partnera; podobný název ani cizí poddoména nestačí. Explicitní identita variantního odkazu se započítá i při nesouladu webu, aby šla chybná vazba najít a opravit. Filtr se aplikuje před stránkováním a zachovává se při hledání, změně kategorie a viditelnosti. Kontrola „Bez odkazů“ a počet odkazů na kartě nově zahrnují i variantní odkazy; odstraněné záznamy se nepočítají.

V **Prodávající** vede počet produktů přímo na tento filtr. Počet zahrnuje všechny jazyky a každý aktuální produkt pouze jednou. Odkaz **Smazat** otevře samostatný blok v profilu. Smazání vyžaduje zaškrtnuté potvrzení, přihlášení správce, POST, CSRF a aktuální verzi profilu. Používaného partnera nelze smazat: nejprve změň prodávajícího a odeber jeho společné i variantní odkazy u všech navázaných produktů, včetně konceptů. Kontrola vazeb se před smazáním opakuje v transakci pod zámky; souběžná změna profilu je odmítnuta. Smazání nepřepíná způsob prodeje, nemaže produkty a nepřepisuje ceny, sklad, rezervace, starší objednávky ani historii cenových kontrol. Již smazané produkty a tombstony odkazů smazání profilu neblokují. Tato změna nevyžaduje migraci databáze.

JSON verze 1 nyní exportuje také celočíselné `priority`. Starší záloha bez tohoto pole zachová existující prioritu; novému profilu nastaví 100. Po nasazení aktualizuj SQL tabulky v administraci. Migrace při opakování nastavené priority nepřepisuje.
