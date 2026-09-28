# Dobrodruzi — PHP šablona

Tento adresář obsahuje rozdělenou šablonu e-shopu. `index.php` zobrazuje katalog a `produkt-topo-terraventure.php` detail ukázkového produktu. Obojí sestavuje `view/layout.php`:

| Soubor | Obsah |
| --- | --- |
| `view/head.php` | Nastavení HTML `<head>`, titulek, meta popis, font a CSS. |
| `view/header.php` | Horní lišta, hledání, nápisy a hory. |
| `view/menu.php` | Hlavní navigace kategorií. |
| `view/body.php` | Katalog, filtr batohů a ukázkové produkty. |
| `view/product-body.php` | Stránka ukázkového produktu. |
| `view/footer.php` | Patička. |
| `view/cart.php` | Ukázkový košík. |
| `view/layout.php` | Společná kostra a načtení pohledů. |
| `style.css` | Vzhled včetně nastavení rozměru hor v `.mountain-edge`. |
| `assets/app.js`, `assets/product.js` | Ukázkové filtrování, hledání, mobilní menu, košík a množství produktu. |

Spuštění pod PHP: v tomto adresáři `php -S localhost:8000`, poté otevřít `http://localhost:8000/index.php`. Náhled bez PHP je v `index.html` a `produkt-topo-terraventure.html`; při úpravě pohledů se obnoví příkazem `python3 tools/render_preview.py`.

Po kliknutí na **Batohy** v hlavním menu se zobrazí všechny karty s `data-category="batohy"`. Ve filtru pod nadpisem katalogu se objeví **Všechny batohy**, **Batohy do 25 l**, **Batohy 25–50 l**, **Batohy nad 50 l** a **Příslušenství k batohům**. Pro přiřazení produktu k podkategorii nastavte na jeho `<article>` `data-subcategory="do-25"`, `"25-50"`, `"nad-50"` nebo `"prislusenstvi"`. Dvě ukázkové karty batohu mají objem 24 l a patří do první skupiny; další tři skupiny zatím nemají ukázkové produkty a ukážou prázdný stav. Karty z jiných skupin se do filtru batohů nezapočítávají.

Vstupní PHP skripty předávají do pohledu `$page`, `$pageTitle` a `$pageDescription`. Až vznikne objektový kontroler, může tyto údaje poskytovat on; vzhledové pohledy zůstávají oddělené. Filtrování a košík jsou zatím klientská demonstrace: košík je pouze v prohlížeči, bez objednávky a backendu.
