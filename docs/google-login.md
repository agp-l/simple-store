# Přihlášení zákazníků přes Google

Nastavení je v **Nastavení obchodu → Přihlášení** (`/admin/settings/?tab=accounts`). Výchozí stav je vypnuto. Nejprve nasaď kód a aktualizuj databázi; nové tabulky nezasahují do existujících účtů.

## Aktivace

1. V Google Cloud / Google Auth Platform vytvoř OAuth klienta typu **Web application**. Nastav údaje aplikace, doménu, odkazy na podmínky a soukromí a publikum podle požadavků konzole.
2. Autorizovaná návratová adresa se odvozuje z `config/installation.json` a zobrazí se v administraci Přihlášení nebo Doména a přesun. Zkopíruj ji přesně do Google Cloud; při změně domény ji změň také tam. Nepřidávej `/cs`; jiná instalace nebo podsložka potřebuje vlastní přesnou adresu. Klientský JavaScript SDK ani Authorized JavaScript origins tento serverový tok nepoužívá.
3. Client ID, Client secret a návratovou adresu zadej do administrace a zapni přihlašování. Secret nikdy neposílej do repozitáře. Prázdné pole secret zachová uloženou hodnotu; změna Client ID vyžaduje nový secret.
4. V testovacím režimu povol konkrétní testovací účty. Pro veřejný provoz dokonči nastavení publika/produkčního stavu v Google Auth Platform. Vyžadované údaje jsou jen `openid email profile`.
5. Ověř registraci nového zákazníka, přihlášení existujícího propojeného účtu, návrat z pokladny, zrušení souhlasu, připojení k heslovému účtu, nastavení hesla a odpojení. Bez skutečných OAuth údajů lze automaticky otestovat protokol a databázi, nikoli živé přihlášení u Googlu.

## Chování účtů

- Identifikátorem je Google `sub`, nikoli e-mail. Stejný e-mail automaticky nespojí dva účty. Existující zákazník se nejprve přihlásí heslem (nebo obnoví heslo), potom Google připojí v Nastavení účtu a potvrdí současné heslo.
- Nový Google účet má prázdný, nepoužitelný hash hesla. Pro první heslo musí zákazník v posledních pěti minutách ověřit propojený Google účet. Lze také použít běžnou obnovu hesla e-mailem po odhlášení.
- Odpojení vyžaduje funkční heslo a jeho potvrzení. Nemění objednávky, adresy ani samotný účet. Zneplatní ostatní relace přes `auth_version`; aktuální relace se obnoví přihlášením heslem.
- Odpojení odstraní místní propojení. U Googlu lze zvlášť odebrat dříve udělený souhlas v nastavení Google účtu. Aplikace neuchovává přístupové ani obnovovací tokeny a nemá přístup k Gmailu, Disku ani kontaktům.
- Vypnutí registrace blokuje nové Google registrace, ale zachovává přihlášení již propojených zákazníků. Administrátorské účty se touto cestou nikdy nepřihlašují.

## Bezpečnost a údržba

Serverový Authorization Code tok používá POST s CSRF pro zahájení, náhodný jednorázový `state`, `nonce`, PKCE S256, desetiminutové vypršení a vazbu na identitu i aktuální konfiguraci. Propojení znovu kontroluje verzi účtu pod databázovým zámkem, aby mezitím změněné heslo nebo odhlášení nezpůsobily připojení ke staré relaci. Unikátní klíče v DB brání sdílení identity více účty.

Token se přijímá pouze jako přímá HTTPS odpověď pevného endpointu `https://oauth2.googleapis.com/token`, bez přesměrování a s ověřováním certifikátu a hostname. Integrita odpovědi je ověřena autentizací tohoto TLS spojení podle OpenID Connect Core 3.1.3.7, bod 6. Kontrolují se `iss`, `aud`, případné `azp`, `exp`, `iat`, `nonce`, `sub` a ověřený e-mail. Toto není obecný JWT validátor: nikdy sem nepřidávej přijetí `id_token` z prohlížeče bez validace podpisu knihovnou a Google JWKS. Přístupové tokeny se neukládají; nevyžaduje se offline přístup.

Client secret je šifrovaný existujícím AES-GCM mechanismem `MailCredential`. Záloha databáze proto potřebuje také existující chráněný soubor `config/.smtp-key.php`. Pole se neposílá zpět do HTML ani do exportů textů. Chyby výměny tokenu se logují bez tokenů, kódu a secretu. Provozní proxy/webserver nesmí logovat citlivé query callbacku; účet odpovídá `no-store` a `no-referrer`.

Testy: `php tests/google-oauth.php`, `php tests/google-identity-database.php` (MySQL/MariaDB), stávající testy účtů a obnovy hesla. Pro přihlašovací session v lokálním izolovaném prostředí použij zapisovatelný `session.save_path`.

Oficiální podklady: https://developers.google.com/identity/openid-connect/openid-connect a https://openid.net/specs/openid-connect-core-1_0.html#IDTokenValidation .
