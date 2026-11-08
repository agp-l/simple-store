# Oznámení objednávek do telefonu

V administraci otevři **Nastavení → Oznámení**. Modul je ve výchozím stavu vypnutý a nemá předvyplněné téma. Stávající objednávky ani ostatní nastavení se nemění.

1. V aplikaci ntfy v telefonu povol oznámení a přidej odběr svého tématu na serveru `https://ntfy.sh`.
2. Do **Tajná adresa ntfy** vlož celou adresu `https://ntfy.sh/tvoje-tema`. Je podporován pouze tento HTTPS server, nikoli libovolné adresy nebo přesměrování.
3. Pro chráněné téma vlož přístupový token s právem publikovat. V telefonu potřebuješ právo číst stejné téma. Token je v administraci viditelný; v databázi používá stávající šifrování a soubor `config/.smtp-key.php`.
4. Ulož nastavení a stiskni **Poslat testovací zprávu**. Test používá uložené hodnoty, funguje i s vypnutým modulem a nevytváří objednávku.
5. Po ověření zprávy zaškrtni **Zapnout oznámení nových objednávek** a ulož.

Název nechráněného tématu není přístupové oprávnění: kdo jej zná, může zprávy číst i publikovat. Pro zákaznické údaje používej soukromé téma s řízeným přístupem. Volitelně lze přidat e-mail a telefon; standardně se posílá jméno příjemce, číslo a čas objednávky (Praha), položky, varianty, počty a ceny, doprava, celková částka a aktuální stav platby. Kliknutí vede do administrace vyžadující přihlášení, nikoli na zákaznický odkaz s přístupovým tokenem. Velmi dlouhé seznamy se zkrátí s odkazem na detail.

Po chybě formuláře zůstanou rozepsané hodnoty viditelné; stav Zapnuto/Vypnuto ukazuje naposledy uložené nastavení. Po úspěšném testu nebo ručním opakování se stránka přesměruje, aby její obnovení neposlalo zprávu znovu. Podrobný návod a pravidla opakování najdeš v rozbalovacích sekcích.

## Doručování a opakování

Nová objednávka se nejprve uloží a potvrdí v databázi, teprve potom se připraví a odešle oznámení. Okamžitý HTTP pokus má časový limit 4 sekundy; selhání ntfy nemění objednávku ani platbu. Při havárii procesu mezi potvrzením objednávky a vložením do fronty nemusí oznámení vzniknout; zdrojem pravdy je vždy přehled objednávek.

Při dočasně nečitelném přístupovém tokenu zůstane oznámení ve frontě a nespotřebuje pokus o odeslání. Po obnovení klíče je lze odeslat cronem nebo ručně. Pozdní výsledek staršího pokusu nesmí přepsat novější pokus.

Fronta ukládá jen číslo a ID objednávky, otisk tématu a stav doručení, nikoli druhou kopii osobních údajů. Jedna objednávka má jeden záznam. Atomické převzetí brání souběžnému odeslání dvěma pracovníky.

- **Čeká / Chyba:** stávající `mail-cron.php` nebo `php tools/mail-worker.php` zpracuje až 3 oznámení na spuštění. Automaticky nejvýše 5 pokusů s odstupem alespoň 15 minut. Pro tuto obnovu musí být e-mailový cron již nastavený; týdenní cron ověřování cen je jiný.
- **Odesláno:** server ntfy přijetí potvrdil. Není to potvrzení, že telefon zprávu zobrazil. Tyto zprávy se znovu neposílají.
- **Nepotvrzeno:** spojení nebo proces skončil bez jistého výsledku. Automatické opakování by mohlo vytvořit duplicitu; nejprve zkontroluj telefon a případně potvrď ruční opakování.
- **Vynecháno:** objednávka již neexistuje, ID patří jinému číslu objednávky nebo se změnilo téma. Zpráva se neposílá na novou adresu.

Vypnutí modulu zastaví nové zprávy i zpracování čekajících. Opětovné zapnutí může obnovit dříve čekající zprávy. Přerušený stav Odesílá se po 5 minutách označí jako Nepotvrzeno. V nastavení je posledních 30 záznamů a ruční opakování s potvrzením.

První uložení vytvoří dvě nové tabulky pomocí `CREATE TABLE IF NOT EXISTS`. Jsou také součástí běžné aktualizace SQL schématu. Uložené nastavení včetně přístupového tokenu zahrnuje existující JSON export/import; fronta není nastavení a neimportuje se. Prázdný token při běžném uložení ponechá původní, odstranění vyžaduje samostatné zaškrtnutí. Zálohuj také šifrovací soubor instalace. Hromadný reset obchodních dat vyčistí frontu společně s objednávkami, ale nastavení ponechá.

## Ověření změn

`tests/ntfy.php` ověřuje adresy, validaci, zprávy, Unicode, soukromé údaje, odpovědi HTTP a formulář. `tests/ntfy-database.php` používá výhradně falešný transport a kontroluje deduplikaci, souběžné převzetí, opakování, vypnutí, změnu tématu, import/export a zachování objednávky při chybě konfigurace. Prohlížečový test administrace zahrnuje novou stránku na mobilních i desktopových šířkách. Žádný test neodesílá zprávy na skutečné ntfy.
