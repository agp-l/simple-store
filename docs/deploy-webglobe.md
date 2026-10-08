# Nasazení testovacího obchodu na Webglobe

Zdrojovým kódem je větev `main` na GitHubu. Databáze, produkty, účty a objednávky žijí na hostingu; fotografie jsou v `images/media/`. Release přenáší jen aplikaci, její statické soubory, závislosti z uzamčeného Composeru a aktuální `database/schema.sql`. Nepřepisuje `config/database.php`, místní soukromé konfigurace ani fotografie; vzdálená data automaticky nemaže.

## Jednorázové nastavení

1. Ve Webglobe Adminu otevři **Hosting → FTP a soubory → FTP účty** a zjisti skutečný FTP hostitel, přihlašovací jméno a heslo účtu omezeného přímo na složku `eshop.dobrodruzi.cz`. Odkaz na editaci FTP účtu není FTP hostitel. Kořen tohoto účtu je pro nasazení `/` a skript ověří, že obsahuje `index.php`, `.htaccess` a `config/database.php`.
2. V GitHub repozitáři otevři **Settings → Secrets and variables → Actions**. Do *repository secrets* ulož `WEBGLOBE_FTP_HOST`, `WEBGLOBE_FTP_USER` a `WEBGLOBE_FTP_PASSWORD`. Přihlašovací údaje nezapisuj do Gitu, workflow ani dokumentace. Staré SFTP secrets už nasazení nepoužívá a lze je z GitHubu odstranit.
3. V *repository variables* nastav `WEBGLOBE_DEPLOY_ENABLED` na `true`. V **Actions → Deploy Webglobe test shop → Run workflow** spusť první nasazení a ověř jeho výsledek. Každý další úspěšný běh **PHP checkout** vyvolaný pushem do `main` nasadí odpovídající commit automaticky. Neúspěšné testy, PR ani úpravy jen v místních souborech nenasazuje.

Přenos používá standardní FTP účet a port 21; **nevyžaduje SSH ani SFTP**. Klasické FTP přenáší heslo a obsah bez šifrování. Pokud hosting na stejném FTP účtu podporuje explicitní TLS (FTPS), lze v repository variables nastavit `WEBGLOBE_FTP_SECURITY=ftps`. Skript v tom případě vyžaduje TLS pro přihlášení i data a při neúspěchu se nepřepne na nešifrované FTP. Nasazovací účet omez na složku testovacího obchodu. V případě neobvyklého portu lze použít `WEBGLOBE_FTP_PORT` jako repository variable (výchozí je 21).

Workflow nainstaluje závislosti z `composer.lock`, vytvoří seznam souborů a přenese změny přes FTP. Cíl ověří podle již existující instalace. Na serveru zapíše `config/.deploy-manifest.json`, aby při dalším běhu přenášel jen změny. Kód neodstraňuje staré soubory z předchozích verzí. Při částečném neúspěchu přenosu marker neaktualizuje; opakovaný běh nahraje chybějící soubory znovu. Přepis souboru používá dočasný soubor, zálohu předchozí verze a přejmenování, ale celý web se nepřepíná atomicky.

### Místní kontrola balíku

Po `composer install --no-dev` lze vytvořit release v prázdném adresáři:

```bash
python3 scripts/build-release.py --output /tmp/simple-store-release
```

Se zadanými proměnnými prostředí `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD` lze spustit `python3 scripts/deploy-ftp.py --package /tmp/simple-store-release --dry-run`. Ověří přihlášení a kořen FTP účtu, ale nic nenahraje. Na FTP se pro `--dry-run` stejně přihlašuje. Volitelné `FTP_SECURITY=ftps` vynutí TLS. Heslo patří jen do prostředí procesu nebo GitHub secretu; nevkládej jej přímo do příkazu uloženého v historii shellu.

## Aktualizace databáze

FTP přenáší soubory a nespouští SQL. Po nasazení, v němž se změnil hash `database/schema.sql`, otevři přihlášenou **Administraci → Databáze** na testovacím webu a klikni na **Aktualizovat SQL tabulky**. Aplikace použije databázi v soukromém `config/database.php`; příkazy `CREATE DATABASE` a `USE` z instalačního SQL ignoruje. Proces má zámek a evidenci dokončení. Do GitHub Actions není pro tento postup potřeba ukládat heslo k databázi.

Před změnou schématu ulož zálohu databáze a médií. Neimportuj na hostovanou databázi přímo celý `database/schema.sql`: jeho první příkazy míří na instalační název `simple_store`, který se liší od názvu databáze na hostingu.

## Ověření a návrat

Po přenosu ověř `/cs`, detail produktu, košík, administraci a podle změny pokladnu. U změny schématu ověř v Administraci → Databáze stav „Aktuální“. Návrat k předchozímu kódu je opětovné nasazení staršího commitu; **databáze a její data se automaticky nevracejí**. Starší kód musí být kompatibilní s novým schématem nebo použij ověřenou zálohu.

Heslo FTP účtu dříve sdělené v chatu po nastavení GitHub secretu změň ve Webglobe Adminu. Aktualizuj potom i odpovídající secret; heslo nikdy neposílej do repozitáře.
