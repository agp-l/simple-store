# Přihlašování a provozní ochrana

Heslové přihlášení správce a zákazníka po kontrole CSRF rezervuje serverový pokus před ověřením hesla. Limity jsou oddělené pro obě role a platí i při nových cookies:

| Rozsah | Maximum za 5 minut |
| --- | --- |
| Účet a IP společně | 10 pokusů |
| Účet ze všech IP | 30 pokusů |
| IP přes všechny účty | 100 pokusů |

Počítají se všechny pokusy včetně úspěšných. Odmítnutý pokus neprodlužuje okno. Existující relace navíc zachovává původní pětiminutové omezení po pěti chybných heslech. Limity mají chránit i při střídání účtů/IP, nejsou náhradou ochrany hostingu proti rozsáhlému útoku. Útočník znalý e-mailu může dočasně vyčerpat rozpočet účtu; stávající přihlášené relace tím nezanikají a obnova hesla ani Google přihlášení se tímto limitem neblokují.

`LoginThrottle` uchovává pouze SHA-256 klíče rozsahů, čítače a expirace v soukromém `config/.login-throttle.json`. Jde o pseudonymizované provozní údaje, nikoli anonymizaci. Hesla ani samotné e-maily/IP se neukládají. Při příštím pokusu se prošlé položky odstraní; soubor má omezenou velikost i počet položek. Krátký souborový zámek a atomická výměna brání překročení limitu souběžnými procesy. Ověřování hesla probíhá až po uvolnění zámku.

PHP potřebuje zápis do `config/`, stejně jako při nastavení adresy instalace a vytváření šifrovacího klíče. Stav a zámek se necommitují, neexportují ani nepřepisují nasazením. Při nemožnosti zápisu, poškození souboru či obsazeném zámku se přihlášení odmítne s HTTP 503 a obecným sdělením; ochrana se potichu nevypíná. Příčina je v PHP error logu. Nepovoluj veřejný zápis do adresáře. Vyčerpání limitu vrací HTTP 429 a `Retry-After` se zbývajícím časem.

IP pochází pouze z `REMOTE_ADDR`; klientské `X-Forwarded-For` se nedůvěřuje. Při nasazení za proxy musí hosting bezpečně nastavit skutečnou IP od důvěryhodného proxy, jinak se její uživatelé dělí o rozpočet jedné IP. Souborový stav je určený pro jednu instalaci se sdíleným diskem; více nezávislých webových serverů potřebuje společné úložiště limitů.

Interní obnovení přihlášení po již ověřené změně hesla, registraci nebo odpojení Googlu tento anonymní vstup neopakuje. Obnova hesla má vlastní dosavadní omezení zpráv; registrace, reset a Google mají odlišné bezpečnostní hranice a nejsou tímto testem prohlášeny za kompletně auditované.

Ověření: `php tests/login-throttle.php` testuje účty, IP, normalizaci IPv6, expiraci, poruchy a souběžné procesy. `bash tests/panel-apache.sh` navíc ověřuje HTTP 429 a `Retry-After` i při novém cookie jar pro každý pokus, pro obě role v kořeni a podsložce. Původní testy autentizace a CSRF zůstávají.

Principy: [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html#login-throttling).
