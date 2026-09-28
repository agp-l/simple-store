# Dobrodruzi — jednoduchý obchod a CMS

První základ vlastního redakčního systému v PHP 8 a MySQL. Grafika obchodu zůstává ve `view/`. Architektura a důvody jednotlivých rozhodnutí jsou popsané v [docs/architecture.md](docs/architecture.md).

**Nejdřív spusť v kořeni projektu:**

```bash
composer install
cp config/database.example.php config/database.php
```

Pak otevři `config/database.php` a nastav své MySQL `dsn`, `user` a `password`. Příklad používá zástupné údaje, sám se k databázi nepřipojí. Pokud Composer nebo tento místní konfigurační soubor chybí, web teď ukáže konkrétní pokyny místo prázdné chyby 500 a příkazový skript vypíše chybějící kroky. Soubor `config/database.php` je schválně ignorovaný Gitem, aby se heslo nedostalo na GitHub.

## Spuštění v Apache / XAMPP / LAMPP

1. Nakopíruj celý repozitář do složky, kterou obsluhuje Apache. Web nemá složku `public/`; přístup ke zdrojovým a konfiguračním souborům blokuje `.htaccess`. Apache musí mít povolené `mod_rewrite` a `AllowOverride` pro tuto složku.
2. Ve složce projektu spusť `composer install`. Composer načítá třídy ze `src/` přes namespace `SimpleStore\` a nainstaluje MeekroDB. PHP potřebuje rozšíření `pdo_mysql`.
3. V MySQL vytvoř tabulku importem jediného aktuálního souboru: `mysql -u root -p < database/schema.sql` nebo vlož celý soubor do phpMyAdmin. Příkazy `CREATE DATABASE IF NOT EXISTS`, `USE` a `CREATE TABLE IF NOT EXISTS` už v souboru jsou. Pokud hosting nedovolí `CREATE DATABASE`, vytvoř databázi v hostingu a v kopii importovaného SQL vynech první dva příkazy.
4. Zkopíruj `config/database.example.php` jako `config/database.php` a uprav `dsn`, uživatele a heslo podle svého MySQL. Tento soubor je v `.gitignore` a nesmí se commitovat.
5. Otevři adresu složky projektu v Apache. Úvod obchodu funguje i před nastavením databáze; blog a redakční stránky potřebují importovanou tabulku. Prohlížej `/cs`, `/cs/blog` a `/cs/o-nas` po založení obsahu.

Pro ladění je v `config/site.php` zapnuto `'debug' => true`; PHP chyby, upozornění a zachycené výjimky se zobrazují na stránce. Až web skutečně zveřejníš, přepni jej na `false`. Pokud se stále objeví holá chyba 500 bez stránky aplikace, jde o chybu Apache ještě před spuštěním PHP; zkontroluj jeho error log a podporu `.htaccess`/`mod_rewrite`. Příkaz `ini_set()` nemůže zobrazit chybu parsování v tomtéž souboru, pokud se kvůli ní PHP vůbec nespustí.

## První stránka, článek a historie

Zatím není administrační přihlášení ani formulář pro editaci. Pro vyzkoušení jádra je připravený příkazový skript, který **funguje pouze z terminálu**:

```bash
php tools/content.php create page cs o-nas 'O nás' 'Zde je náš příběh.'
php tools/content.php create post cs prvni-vyprava 'První výprava' 'Vyrazili jsme před svítáním.'
```

Výstup obsahuje `document_key` a `revision_number`. Po úpravě textu vytvoříš novou revizi (doplň vrácený klíč):

```bash
php tools/content.php update DOKUMENT_KLIC 1 page cs o-nas 'O nás' 'Nové znění, staré zůstává uložené.'
php tools/content.php history DOKUMENT_KLIC cs
php tests/url-manager.php
```

Webové adresy pak budou `/cs/o-nas` a `/cs/blog/prvni-vyprava`. Všechny publikované články se ukážou pod `/cs/blog`. Nové stránky se automaticky objeví v hlavním menu. Opakované `update` se stejným číslem revize skončí chybou, aby uživatel omylem nepřepsal novější práci. Při tvorbě překladu použiješ původní klíč dokumentu, nový jazyk a očekávanou revizi `0`; nový jazyk se nejprve musí přidat do `config/site.php` **spolu s překladem textů rozhraní**.

## Co už tu je a co přijde později

| Soubor | Úkol |
| --- | --- |
| `index.php` | Jediné veřejné směrování CMS; úvod, stránky, blog a 404. |
| `src/Navigation/UrlManager.php` | Části URL, jazyk a odkazy při instalaci v podsložce. |
| `src/Navigation/MenuManager.php` | Odkazy publikovaných stránek a blogu. |
| `src/Content/ContentRepository.php` | Čtení obsahu, historie a uložení nového řádku v transakci. |
| `src/Rendering/PageRenderer.php` | PHP pohledy bez Twig. |
| `database/schema.sql` | Vždy aktuální úplné schéma. |
| `view/` | HTML pro obchod, blog, stránky, `<head>`, hlavičku, menu a patičku. |

Produkty a košík jsou stále **ukázkou v prohlížeči**; nákup ani úpravy přes administraci zatím nefungují. Karty batohů používají `data-category="batohy"` a `data-subcategory="do-25"`, `"25-50"`, `"nad-50"` nebo `"prislusenstvi"`. HTML soubory v kořeni jsou starší statické náhledy vzhledu a nespouštějí CMS; Apache je blokuje. Příští etapou bude jednoduché přihlášení a formulář stránek/článků s výběrem a obnovou revizí.
