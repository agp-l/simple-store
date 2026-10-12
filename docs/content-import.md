# JSON stránek a článků

V editaci libovolné stránky nebo článku jsou tlačítka **Export JSON** a **Import JSON**. Export stáhne aktuální uloženou verzi; import otevře formulář s jejím JSONem a předvolenou aktualizací. Rozpracované přímé úpravy se před přechodem dokončí.

Nový obsah lze importovat také přes **Stránky → Import JSON**, **Správa blogu → Import JSON článku** nebo **Import a export → Import a export stránek a článků**. Vyber soubor `.json` nebo vlož text, zkontroluj **Náhled importu** a potvrď uložení. Nahraný soubor má přednost před vloženým textem. Limit je 4 MiB; server musí mít dostatečné `upload_max_filesize` a `post_max_size`.

- **Nový koncept** vytvoří nový dokument, vždy skrytý a mimo horní odkazy. Klíč a revizi případně přítomné v exportu nepoužije k přepsání původního dokumentu. Při kopírování změň `slug`, pokud už se stejná adresa používá. Publikovat lze následně v editoru.
- **Aktualizovat existující** vyžaduje aktuální `document_key`, `revision`, `type` a `language` z exportu. Uloží novou revizi a zachová dostupnou historii. Změněnou nebo smazanou verzi odmítne; nevytvoří za ni jiný dokument. Ostatní jazykové verze zůstanou zachované.
- Při aktualizaci neuvedená pole zůstanou zachovaná. `sections` nahrazuje všechny bloky, prázdné `[]` je vymaže. Chybějící `published` zachová stávající viditelnost; explicitní `true` či `false` ji změní. Náhled ukazuje výsledný stav.
- Změníš-li po náhledu JSON nebo způsob importu, musíš náhled zopakovat. Chybný JSON zůstává ve formuláři k opravě.
- Export obsahuje jednu aktuální jazykovou verzi, nikoli historii, jiné překlady nebo nastavení výběru článků v katalogu. Čistý JSON nepřenáší soubory fotografií. Při přesunu mezi instalacemi přenes také odpovídající soubory v `images/` nebo fotografie nahraj a znovu vyber v editoru.

## Formát

Minimální nový dokument:

```json
{
  "format": "simple-store.content.v1",
  "type": "page",
  "language": "cs",
  "content": {
    "title": "O nás",
    "slug": "o-nas",
    "summary": "Jsme dobrodruzi.",
    "sections": [
      {"type": "text", "heading": "Náš příběh", "body": "Text s **tučným písmem** a [odkazem](https://example.org)."},
      {"type": "list", "heading": "Co máme rádi", "body": "Hory\nDlouhé cesty"},
      {"type": "table", "heading": "Přehled", "body": "Rok | Událost\n2026 | Začátek"},
      {"type": "image", "heading": "Fotografie", "body": "images/batoh.webp"}
    ]
  }
}
```

Pro článek změň `type` na `post`; jeho adresa bude `/cs/blog/o-nas`. Pro stránku je `type` `page`. `language` musí být povolený v konfiguraci webu. Pokud nový dokument nemá `slug`, vytvoří se z názvu. Volitelná pole uvnitř `content`: `published` a `visible_in_menu` jako skutečné JSON boolean hodnoty, `menu_order` jako celé číslo 0–65535. Články se do horních odkazů nepřidávají.

Místo `sections` lze použít `body` s prostým textem; vznikne jeden textový blok. Obě pole současně nejsou povolená. HTML se nespouští. Je podporováno nejvýše 60 bloků; blok má `type`, `heading` a `body`. Nové obrazové bloky používají lokální cesty podporované knihovnou fotografií. Nadpis dokumentu má nejvýše 255 znaků, nově upravený perex článku nejvýše 180 znaků. JSON uložený s UTF-8 BOM i obalený v Markdown bloku `json` lze vložit přímo.

Export navíc obsahuje `document_key` a `revision` na nejvyšší úrovni. Při zadání AI požaduj jejich zachování spolu s `format`, `language` a `type`. Pro částečnou aktualizaci stačí v `content` ponechat pole, která se mají změnit. Neznámá pole a typové chyby import odmítá.

Nejsou potřeba změny SQL schématu. Přístup má pouze přihlášený správce, POST kontroluje CSRF a revize se ukládá stávající databázovou transakcí. Kontroly: `php tests/content-import.php`, `php tests/content-import-controller.php` a s testovací MySQL/MariaDB `php tests/content-import-database.php`.
