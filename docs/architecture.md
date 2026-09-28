# Návrh jádra Simple Store

## Smysl první etapy

Katalog zůstává současnou ukázkou. Nové CMS řeší stránky a blog, tedy obsah, který může mít více verzí. Produkty, sklad a platby budou samostatné další etapy; nepřidáváme teď tabulky, které žádný kód nepoužívá. Jediný administrátor má jméno a hash hesla v místním `config/admin.php`, vytvořeném příkazem v terminálu. To nepotřebuje další tabulku ani vztahy mezi tabulkami.

## Jak jde požadavek aplikací

1. Apache ponechá obrázky a CSS jako soubory. Ostatní URL předá do `index.php`.
2. `UrlManager` rozdělí cestu na úseky, určí jazyk a udrží správný prefix, pokud je projekt v podsložce.
3. `index.php` rozhodne, zda zobrazit obchod, seznam článků, článek, stránku, nebo 404.
4. `ContentRepository` načte jen aktuální publikovanou revizi. `MenuManager` sestaví odkazy z publikovaných stránek označených pro menu.
5. `PageRenderer` pošle data do stávajících PHP pohledů ve `view/`. Nepoužívá Twig ani databázi.

## Složky a názvy

| Cesta | Úkol |
| --- | --- |
| `src/Navigation/UrlManager.php` | Jen adresa, jazyk a lokální odkazy. Zachovává název a metodu `getSegment()` ze starého CMS. |
| `src/Navigation/MenuManager.php` | Vytvoří pole odkazů pro hlavičku, žádné HTML uvnitř třídy. |
| `src/Content/ContentRepository.php` | SQL dotazy, publikovaný obsah a ukládání revizí. |
| `src/Rendering/PageRenderer.php` | Vybere schválený PHP pohled a předá mu data. |
| `config/site.php` | Výchozí a podporované jazyky. |
| `config/database.php` | Místní údaje k DB, není ve verzovacím systému. |
| `config/admin.php` | Místní jméno a hash hesla administrátora; není ve verzovacím systému. |
| `admin.php`, `view/admin/` | Přihlášení a úpravy obsahu, bez zásahu do veřejného vzhledu. |
| `database/schema.sql` | Jediný aktuální soubor pro vytvoření celé databáze. |
| `view/` | HTML a malé výpisy proměnných; současná grafika obchodu. |

## Jedna tabulka pro stránky, články a historii

Jeden `document_key` je trvalá identita stránky nebo článku. `language` je jazyk konkrétního textu; překlady stejného obsahu sdílejí `document_key`, ale každý mají vlastní revize a URL. Každé uložení přidá **nový řádek** do `content_revisions`. Původní text, titulek, slug, stav i nastavení menu zůstanou na starém řádku. Dvě pomocná pole `active_document_key` a `active_slug` mají hodnotu pouze na současné revizi: díky unikátním indexům může být pro každý dokument a jazyk právě jedna současná revize a každá publikovaná URL může patřit nejvýše jednomu dokumentu. Změna současné revize v transakci vynuluje tato dvě pole na starém řádku a vloží nový řádek. Starý obsah se nikdy nepřepisuje.

Základní cesty jsou `/`, `/cs`, `/cs/blog`, `/cs/blog/nazev-clanku`, `/cs/o-nas`. Jazyk vybírá výhradně URL, nikoli cookie nebo session. Nyní je zapnutá jen čeština; další jazyk vyžaduje také přeložené texty rozhraní. Chybějící překlad zobrazí 404, aby se potichu nepodstrčil obsah v jiném jazyce.

## Hranice zabezpečení

Pro běžný hosting zůstává kořen projektu také kořenem webu. `.htaccess` na Apache blokuje `src/`, `config/`, `database/`, `view/`, `vendor/` a další neveřejné soubory ještě před pravidlem pro směrování. Na jiném serveru je potřeba odpovídající zákaz v jeho nastavení. Přihlášení administrátora používá silné náhodné heslo, PHP session a kontrolu CSRF tokenu u každého POST; původní hodnoty z formuláře se používají jen po ověření přístupu. Administraci na veřejné doméně provozuj pouze přes HTTPS a s vypnutým ladicím výpisem chyb.
