# Přechod tabulek na prefix `shop_`

Šest tabulek starších instalací zatím nemá prefix: `users`, `customer_addresses`, `content_revisions`, `product_revisions`, `catalog_categories` a `navigation_menus`.

Převod probíhá ve dvou vydáních, protože aktualizace souborů webu a databáze nejsou jedna transakce:

1. V administraci otevři **Databáze**. Pokud některá z šesti tabulek chybí, spusť nejprve **Aktualizovat SQL tabulky**.
2. Ulož zálohu databáze a použij **Přejmenovat šest tabulek**. Nástroj zkontroluje kolize, přejmenuje existující tabulky bez kopírování dat a vytvoří dočasné zapisovatelné pohledy pod původními názvy. Aktuální PHP kód tak může během přechodu dál pracovat.
3. Po ověření stavu **přejmenováno** se vydá druhá změna, která přepne SQL schéma a všechna PHP volání na `shop_*`. Po dokončení FTP nasazení spusť v administraci **Aktualizovat SQL tabulky**.
4. V administraci klikni na **Odstranit staré pohledy**. Zůstanou pouze fyzické tabulky s prefixem `shop_`. Akce nejprve ověří, že pohledy ukazují na očekávané tabulky. Je opakovatelná při přerušeném odstranění.

Převod nic nemaže ani nevytváří nové prázdné tabulky místo obsahu. Pokud narazí na chybějící název nebo kolizi s jiným projektem, skončí bez přejmenování. Založení pohledů po `RENAME TABLE` může selhat například při nedostatečných právech; v takovém případě se nástroj pokusí obnovit původní názvy. Stará verze `database/schema.sql` se po přejmenování již nesmí spouštět; nový aktualizátor toto kontroluje.

Integrační test `tests/table-prefix-database.php` používá samostatnou dočasnou databázi a kontroluje zachování produktů, zápisy přes přechodné pohledy a vazbu účtu na obnovu hesla na MySQL i MariaDB.
