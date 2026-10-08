# Nasazení testovacího obchodu na Webglobe

Zdrojovým kódem je větev `main` na GitHubu. Databáze, produkty, účty a objednávky žijí na hostingu; fotografie jsou v `images/media/`. Release přenáší jen aplikaci, její statické soubory, závislosti z uzamčeného Composeru a aktuální `database/schema.sql`. Nikdy nepřepisuje `config/database.php`, místní soukromé konfigurace ani fotografie; žádná vzdálená data automaticky nemaže.

## Jednorázové nastavení

1. Ve Webglobe zjisti přesný **SFTP server**, jméno FTP účtu a jeho **kořenovou složku pro eshop.dobrodruzi.cz**. Odkaz do Webglobe Adminu není adresa SFTP serveru. Webglobe uvádí SFTP na portu **222**. Ověř, že účet má přístup pouze k tomuto testovacímu webu a že ve zvolené složce jsou `index.php`, `.htaccess` a `config/database.php`. Pokud je FTP účet omezen přímo na složku webu, kořen může být `/`; toto je nutné zjistit výpisem SFTP, ne odhadovat.
2. Získej veřejný SSH host key serveru a ověř jeho fingerprint nezávisle s Webglobe. Do GitHub secret `WEBGLOBE_KNOWN_HOSTS` ulož známý klíč ve tvaru `[server]:222 ssh-ed25519 AAAA...` (přesný algoritmus podle serveru). Nezapínej automatické přijímání neznámého klíče.
3. V repozitáři na GitHubu otevři **Settings → Secrets and variables → Actions**. Ulož secrets `WEBGLOBE_SFTP_HOST`, `WEBGLOBE_SFTP_USER`, `WEBGLOBE_SFTP_PASSWORD`, `WEBGLOBE_SFTP_ROOT` a `WEBGLOBE_KNOWN_HOSTS`. Hesla a databázový soubor nevkládej do Gitu ani do textu workflow. Pro první zkušební běh nech proměnnou `WEBGLOBE_DEPLOY_ENABLED` vypnutou; po ověření nastav repository variable na `true`.
4. V **Actions → Deploy Webglobe test shop → Run workflow** spusť první nasazení. Další úspěšné běhy workflow **PHP checkout** vyvolané pushem do `main` nasadí odpovídající commit automaticky. Neúspěšné testy nebo PR se nenasadí.

Workflow nainstaluje závislosti z `composer.lock`, vytvoří přesný seznam souborů a přenese změny po SFTP. Cíl ověří podle již existující instalace. Na vzdáleném serveru zapíše `config/.deploy-manifest.json`, aby při dalším běhu přenášel jen změny. Kód neodstraňuje staré soubory z předchozích verzí. Pokud změna některý soubor vyřadí, je třeba jeho odstranění z hostingu zvlášť zkontrolovat. Při částečném neúspěchu přenosu marker neaktualizuje; opakovaný běh nahraje chybějící soubory znovu. Přepis jednoho souboru používá dočasný soubor a přejmenování, ale celý web se nepřepíná atomicky. Výrazné změny je vhodné nasazovat mimo návštěvnost.

### Místní kontrola balíku

Po `composer install --no-dev` lze vytvořit release v prázdném adresáři:

```bash
python3 scripts/build-release.py --output /tmp/simple-store-release
```

Se zadanými proměnnými prostředí a instalovaným `paramiko==3.5.1` lze spustit `python3 scripts/deploy-sftp.py --package /tmp/simple-store-release --dry-run`. Zkontroluje identitu serveru a správnou složku webu, ale nic nenahraje. Soubor `SFTP_KNOWN_HOSTS` je lokální soubor s ověřeným veřejným klíčem; heslo patří jen do prostředí procesu nebo do GitHub secretu. Argument `--dry-run` při chybějícím vzdáleném markeru ukáže počet souborů k prvnímu nahrání.

## Aktualizace databáze

FTP/SFTP přenáší soubory a samo nespouští SQL. Po nasazení, ve kterém se změnil hash `database/schema.sql`, otevři přihlášenou **Administraci → Databáze** na testovacím webu a klikni na **Aktualizovat SQL tabulky**. Aplikace použije právě databázi v soukromém `config/database.php`; příkazy `CREATE DATABASE` a `USE` z instalačního SQL ignoruje. Proces má zámek a evidenci dokončení. V konfiguraci Webglobe je možné otevřít **WebSSH** a spustit `php8.4 tools/apply-schema.php --status`, potom `php8.4 tools/apply-schema.php --apply` ze složky webu. CLI cesta pracuje se stejným aktualizátorem jako administrace.

Automatické spuštění SQL po SFTP nyní není zapnuté, protože samotný FTP účet nedovolí spustit PHP na hostingu. Webglobe WebSSH se zapíná na jednu hodinu; permanentní SSH je placená volba. Až bude ověřená cesta ke spuštění CLI z CI (SSH účet s omezenými právy nebo bezpečný tunel k DB), lze automatizovat i poslední krok bez vystavení databáze do internetu. Neimportuj na živou databázi přímo celý `database/schema.sql`: jeho první příkazy míří na instalační název `simple_store`, který se liší od názvu databáze na hostingu.

Před změnou schématu ulož zálohu databáze a médií. Webglobe uvádí denní zálohy DB v `DB_BACKUP` a posledních pět záloh, do 300 MB databáze. Záloha z hostingu nenahrazuje samostatnou kopii před náročnou aktualizací.

## Ověření a návrat

Po přenosu ověř `/cs`, detail produktu, košík, administraci a podle změny pokladnu. U změny schématu ověř v Administraci → Databáze stav „Aktuální“. Návrat k předchozímu kódu je opětovné nasazení staršího commitu; **databáze a její data se automaticky nevracejí**. Starší kód musí být kompatibilní s novým schématem nebo použij ověřenou zálohu. Neprovozuj současně dvě odlišné sady PHP souborů při zásadní změně databáze.

Přihlašovací údaje sdělené v chatu po nastavení secrets změň ve Webglobe; nové heslo DB hned synchronizuj s hostovaným `config/database.php`. Stačí účet FTP omezený na testovací web. K databázi není třeba posílat heslo do GitHub Actions pro tento postup.
