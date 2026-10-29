# Stránky, články a knihovna médií

V administraci **Stránky** přepínají záložky mezi stránkami a články. U článku nastavuje **Náhledový obrázek** samostatný obrázek pro blog a doporučení pod produkty. Automatický režim zachovává původní chování (první obrazový blok); volba **Bez obrázku** je samostatná. Volba se ukládá do revize a přenáší v JSON exportu obsahu.

**Fotografie a média** zobrazuje místní rastrové obrázky v `images/`, včetně starších adresářů a fotografií produktů, stránek a článků. Filtry **Nahráno k produktům / stránkám / článkům** se standardně otevřou jako složky pojmenované podle aktuálního obsahu. Překlady sdílejí jednu složku, přednost má výchozí jazyk obchodu. Po odstranění produktu fotografie zůstávají ve složce označené „bez aktuálního záznamu“. Přepínač **Složky / Obrázky** umožňuje i plochý přehled. Hledání zahrnuje názvy produktů a souborů, nerozlišuje českou diakritiku; řadit lze podle názvu, stáří a velikosti.

Ve složce produktu tlačítko **Přidat fotografie** přidává do galerie a zachovává existující hlavní snímek. U nového produktu s výchozím obrázkem se první fotografie stane hlavní. U stránek a článků přibudou obrazové bloky. Vše se ukládá jako nová revize s kontrolou souběžné editace. Výběr celé složky pro export nebo zařazení zahrne všechny její obrázky, i když v přehledu hledáš jen podle jednoho názvu. Kliknutí na fotografii otevře náhled s listováním šipkami a kopírováním adresy.

Vlastní složky a podsložky jsou organizační štítky: zařazení a přejmenování nemění URL, revize obsahu ani přiřazení produktů. Systémové filtry „Nahráno k …“ označují původ souboru, nikoli úplný seznam jeho současných použití. Externí obrázky na jiných serverech knihovna nestahuje.

Nové nahrání vytvoří bezpečně překódovaný obrázek do 1800 px, kartu do 960 px a miniaturu do 240 px. Limity jsou 12 MiB / 20 Mpx na fotografii, 20 souborů v dávce; platí také limity PHP uvedené u formuláře. Správce samostatně nezveřejňuje ani nevkládá obrázky. V editorech lze zvolit **Celá knihovna médií**, složku a hledání, následně snímek přiřadit do vybraného místa.

Exportované ZIP kolekce obsahují `manifest.json` a obrázky (nikoli odvozené miniatury). Export složky zahrnuje podsložky; limit je 200 obrázků a 100 MiB. Import přijímá tento exportní formát, ověřuje kontrolní součty, strukturu a limity, obrázky znovu dekóduje a vytvoří miniatury. Existující snímky rozpoznává podle SHA-256, složky podle názvu a rodiče. Opakovaný import nevytváří další kopie. GIF/AVIF ze starších souborů vyžadují podporu dekodéru GD; import animovaného snímku vytváří statickou fotografii.

Jde o přenos kolekce, nikoli úplnou obnovu webu: již existující obrázky zachovají URL, nově importované mají vlastní adresy. ZIP import nepřepisuje dokumenty a produkty ani automaticky neopravuje jejich odkazy. Pro kompletní obnovu použij také zálohu databáze a adresáře `images/`.

Organizace knihovny je v chráněném `images/media/.library.json`; změny používají zámek a atomické přejmenování. Nasazení aplikace toto úložiště ani uživatelské fotografie nepřepisuje. Selhání importu odstraní pouze právě vytvořené soubory a ponechá původní metadata. ZIP se nikdy nerozbaluje do veřejného adresáře. Správce nepřidává hromadné mazání; tím lze složky bezpečně používat i pro soubory odkazované ze starších revizí nebo mimo obchod.

Ověření: `tests/content-cover.php`, `tests/media-catalog.php`, `tests/media-archive.php` (GD + ZIP), `tests/admin-media-browser.mjs` (Playwright). Integrační test ZIP je součástí obou databázových CI úloh; pro vlastní běh databázi nepotřebuje.

Další ověření: `tests/media-browse.php`, `tests/media-owners-database.php` (MySQL/MariaDB), `tests/media-folders-browser.mjs`.

## Odebrání a smazání fotografie produktu

V editoru otevři **Hlavní obrázek** nebo **Přidat fotografii**. U přiřazeného snímku je **Odebrat z galerie** nebo **Odebrat hlavní fotografii**. Změna se ihned uloží jako nová revize, bez dalšího tlačítka Uložit. Po odebrání hlavní fotografie se hlavní stane další snímek galerie, případně výchozí zástupný obrázek. Výběr zahrnuje i používané obrázky ze společné knihovny a starších adresářů. Po zavření výběru se editor obnoví.

**Smazat soubor** je samostatná nevratná akce s potvrzením; odstraní originál i miniatury. Je dostupná pouze u spravovaných souborů nahraných k danému obsahu, které nepoužívá žádná aktuální jazyková verze produktu, varianta (ani vypnutá), stránka, článek ani uložená objednávka. Starší revize a odkazy mimo obchod smazání neblokují a po smazání přestanou obrázek zobrazovat. Soubory z jiné či společné knihovny lze z produktu odebrat, ale tímto editorem je nelze fyzicky smazat.

Přiřazení fotografie variantám a blokům popisu je samostatné. Karta jejich použití označí a vysvětlí, kde je změnit. Odebrání z galerie tato přiřazení nemaže; obrázek používaný variantou se proto může dál zobrazovat mezi fotografiemi produktu. Kontrola aktuální revize brání přepsání souběžné úpravy. Odebrání používá cestu snímku, takže řazení či odstranění duplicit neposune cíl dalšího kliknutí.

Ověření odebrání a mazání: `tests/media-library.php`, `tests/media-deletion.php`, `tests/media-gallery-database.php` a `tests/media-gallery-browser.mjs`.
