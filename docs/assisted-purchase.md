# Asistovaný nákup z katalogu

Produkty mají dva smluvní režimy: vlastní prodej (výchozí) a asistovaný nákup jménem zákazníka. Způsob expedice „vlastní / externí“ v administraci objednávek zůstává samostatný údaj: přímé odeslání dodavatelem samo o sobě nemění prodávajícího.

Upřesnění provozního záměru 25. 10. 2026: partner/dodavatel v katalogu znamená jiný e-shop výhradně pro asistovaný nákup. Dodavatele vlastního skladu obchod neeviduje; jeden produkt zákazníkovi nabízí právě jeden z uvedených režimů. [Upravený plán variantních odkazů a partnerské dostupnosti](variant-supplier-architecture.md) zachovává toto rozdělení. Pravidla limitu 3 při neurčitém „skladem“ a vyřazení partnerova posledního kusu jsou v plánu, dosavadní implementace níže je zatím automaticky nevynucuje.

## Nastavení

1. V administraci **Databáze** použij opakovatelnou aktualizaci `database/schema.sql`. Přidává pouze tabulky partnerů a nastavení prodeje; existující produkty zůstávají vlastní prodej.
2. Importuj upravenou stránku `obchodni-podminky-asistovaneho-nakupu`. Doplň skutečné údaje stejného provozovatele do obou sad podmínek, kontaktů a účetnictví. Účinnost podmínek musí odpovídat skutečnému spuštění služby.
3. Na detailu produktu otevři **Upravit produkt → Způsob prodeje**. Vyber vlastní prodej nebo asistenci; u asistence zadej nákupní cenu a partnera. Společný seznam najdeš v **Administrace → Prodávající** i v **Nastavení obchodu → Produkty**. Uloženého prodávajícího lze zobrazit a upravit; změna kontaktů se projeví u všech jeho produktů, předchozí objednávky mají neměnný snímek. Pro jinou právnickou či fyzickou osobu založ nový profil. Neveřejné zdrojové odkazy zůstávají oddělené.
4. Konečnou prodejní cenu nadále zadáváš jako dosud. Obchod používá celé Kč: 1 800 − 1 678 = 122 Kč. Cena dodavatele nesmí být vyšší než konečná cena. U variant lze zadat odlišnou nákupní cenu; prázdné pole dědí cenu produktu. Haléřové ceny se nesmějí svévolně zaokrouhlit na jinou cenu smlouvy; tato verze celočíselného ceníku je nepodporuje.
5. Udržuj skutečnou dostupnost. Stav **Na objednávku** dovoluje nákup bez rezervace místních kusů; stav **Skladem** dál používá existující množstevní limit a rezervaci, ale veřejně se u asistence popisuje jako „Dostupné u partnera“. Žádné automatické objednávání ani kontrola skladu cizího webu zde není. Nabízené způsoby dopravy musí partner skutečně umožňovat; jejich cena musí pokrýt i více zásilek v kombinovaném košíku.
6. Zapni a ověř automatické potvrzovací e-maily. Asistence vyžaduje publikované podmínky a připravenou frontu potvrzení. Po objednání ověř doručení potvrzení před zahájením služby; fronta ukládá přesné podmínky a souhlasy. E-mail potvrzuje již uzavřenou smlouvu, není jejím dalším schválením.

Nastavení prodeje je společné jazykovým verzím produktu a samostatné vůči textovým revizím. JSON produktů/soukromých dodavatelských odkazů je v této změně nepřenáší; nový import proto bezpečně zůstává vlastním prodejem, aktualizace textu stávajícího produktu režim nemaže. Překlad textu podmínek musí být zveřejněný i pro jazyk, v němž asistenci nabízíš.

## Co zákazník uvidí

Karta a detail: decentní „Asistovaný nákup · služba v ceně“. Košík a závěrečný souhrn ponechávají viditelné označení asistence, jméno prodávajícího a cenu zboží i již zahrnuté služby. Sídlo, IČO, kontakt a odkaz na jeho podmínky jsou v označeném rozbalovacím detailu, dostupném i bez JavaScriptu. Společné podmínky obstarání a doručení jsou uvedeny jednou pod seznamem položek. Konkrétního partnera vybírá správce, zákazník jej nemusí vybírat. Změna prodávajícího či rozpisu ceny po zobrazení závěrečného souhrnu vyžaduje novou kontrolu a odeslání.

Smíšená objednávka zachová obě sady podmínek. U asistence zákazník potvrzuje pověření a může zvlášť požádat o zahájení služby během 14 dnů. Bez této žádosti neprováděj nákup u partnera před uplynutím lhůty pro odstoupení (včetně zákonného posunu posledního dne). Aplikace nákup na cizím webu sama neprovádí; pokyn vidíš u objednávky.

Smlouva o asistenci vzniká úspěšným odesláním závazné objednávky. Kupní smlouvu s partnerem následně uzavíráš jako zástupce zákazníka podle podmínek partnera. Partner musí znát kupujícího; nepoužívej své IČO jako odběratele, má-li jít o tento režim. Běžný vlastní nákup s následným přeprodejem do tohoto režimu nepatří. Bez předchozího souhlasu neměň prodávajícího, zboží ani sjednanou vyšší cenu. Úspora z levnějšího nákupu není automaticky další odměnou.

Před zapnutím platební brány pro tento model ověř její souhlas s přijímáním části platby určené jinému prodávajícímu. Výjimka z regulace platebních služeb závisí na skutečném zastoupení při uzavírání podkladové smlouvy; nelze ji založit jen označením platby jako zálohy. Ani BTC není prostředek, jak tuto otázku obejít. Konkrétní smlouvy s partnery a tok peněz musí posoudit právník, ne pouze text podmínek.

## Doklady a evidence

Aktualizace 24. 10. 2026: deník nyní podporuje haléře, rozdělené i částečné platby, dodavatelské úhrady a vratky, roční zůstatky, inventuru a opravné doklady. Postup a hranice jsou v [Evidenci OSVČ](osvc-evidence.md). Celočíselný katalog se tím nemění.

Objednávka a brána přijímají celou cenu. Vlastní faktura obsahuje vlastní zboží, asistenci a sjednanou vlastní dopravu; svěřená kupní cena je samostatně popsána mimo její součet. Partnerův doklad za zboží předej zákazníkovi. U příkladu 1 800 Kč a dopravy 79 Kč: vlastní faktura 201 Kč, prostředky pro partnera 1 678 Kč, celá přijatá platba 1 879 Kč.

Ruční zápis ověřeného bankovního příjmu rozdělí platbu na zdanitelný příjem za vlastní plnění a nezdanitelné svěřené prostředky. U platebních bran a BTC zůstává evidence vyúčtování a skutečných pohybů ruční jako dosud. Předání peněz partnerovi zaznamenej jako nedaňový výdaj; nevytvářej z této částky zároveň daňově uznatelný nákup vlastních zásob. Použití peněz, nevyčerpaný zůstatek, vrácení a doklad partnera je potřeba doložit. Změny skutečných nákupních cen či vratky po objednávce se neprovádějí přepisem jejího historického souhrnu.

Přehled přijatých plateb uvádí celou platbu, nikoli automaticky daňový příjem. Daňový příjem určuje členění peněžního deníku. Řádky partnerského zboží nevstupují do evidence prodeje vlastních zásob. Funkce fakturace zůstává určena neplátci DPH; přechod na plátcovství vyžaduje samostatnou úpravu.

Formulář reklamace/odstoupení na vlastním webu u asistovaných položek řeší **naši službu**. Uvádí zvlášť prodávajícího a jeho kontakt pro zboží. Služba může být vedlejší smlouvou podle § 1836a; neoznačuj odměnu za vždy nevratnou. Peníze lze podle skutečného vyúčtování vracet samostatně, nikoli automaticky podle stavu objednávky. BTC vratku proveď podle sjednaného původního přepočtu nebo individuální dohody; aplikace peníze sama nevrací.

## Ověřené právní podklady (19. 10. 2026)

- [ČOI: nákup přes internet, identity na tržišti, odstoupení od služby](https://coi.gov.cz/faq/a-nakup-pres-internet-2/).
- [Občanský zákoník](https://www.e-sbirka.cz/sb/2012/89): zejména § 436, § 441, § 1820, § 1824a, § 1826a, § 1829, § 1834, § 1836, § 1836a, § 1837, § 2430–2444. Pro tento katalogový model je zveřejnění konkrétního partnera v závěrečném souhrnu zvoleno jako bezpečné transparentní řešení, nikoli tvrzení, že každý příkaz k nákupu musí vždy předem určit konkrétního třetího prodávajícího.
- [ČNB RS2023-14](https://www.cnb.cz/cs/dohled-financni-trh/legislativni-zakladna/stanoviska-k-regulaci-financniho-trhu/RS2023-14): odůvodnění výjimky obchodního zastoupení a nutnosti skutečné účasti na uzavření podkladové smlouvy.
- [MF: online odstoupení od 1. 1. 2027](https://mf.gov.cz/cs/ministerstvo/media/tiskove-zpravy/2026/od-ledna-2027-zacnou-platit-nova-pravidla-na-ochra-65128) a [zákon 159/2026 Sb.](https://e-sbirka.gov.cz/sb/2026/159). Přehled objednávky odkazuje na existující dvoukrokový formulář s potvrzením obsahu a času. Provozovatel musí zajistit funkční doručování potvrzení a snadnou dostupnost této možnosti po celou zákonnou lhůtu.

Žádný vzor podmínek sám nezaručí právní bezrizikovost. Zejména skutečnou identitu provozovatele, smlouvy s partnery, přijímání peněz a používané platební brány nelze odvodit ze zdrojového kódu.

## Rozpracovaní prodávající a nastavení

- Aktualizace databáze přidá k profilům potvrzení spolupráce a verzi pro ochranu souběžných úprav. Stávající profily zůstanou potvrzené; nové vyžadují vlastní potvrzení.
- Prodávajícího lze uložit s neúplnou adresou, IČO, rozepsaným e-mailem či URL. Seznam rozlišuje připravené a rozpracované profily a ukazuje počet přiřazených produktů. Prázdný nový profil bez jediného údaje se nevytváří.
- Způsob prodeje lze uložit i bez nákupní ceny nebo dokončeného prodávajícího. Taková asistovaná nabídka zatím neumožní objednávku. Po doplnění se zpřístupní automaticky; zákaznické ceny se nepřepočítávají ani nenavyšují.
- Přepnutí na vlastní prodej zachová vyplněné partnerské údaje pro další použití. Neveřejná nákupní cena zůstává neveřejná mimo odsouhlasený rozpis asistované objednávky.
- Při chybě validace se zachovají zadané hodnoty. Produktový formulář je obnoví z administrátorské relace po přesměrování; formulář seznamu prodávajících je zobrazí přímo. Při souběžné úpravě se nezmění očekávaná verze: otevři aktuální profil v nové kartě a porovnej změny.
- Úprava vybraného prodávajícího se otevírá v nové kartě, aby nezmizel rozpracovaný produktový formulář. Nového prodávajícího lze vytvořit i přímo v produktu: rozbal „Nový prodávající“, vyplň údaje a použij „Uložit nového prodávajícího a nastavení“. Tím se nový profil přiřadí k produktu.

## Kompaktní editor způsobu prodeje

Sekce má samostatné rozložení přes šířku editoru. U vlastního prodeje se partnerská pole s JavaScriptem sbalí, ale zůstanou součástí odeslaného formuláře. Po chybě se zobrazí i u vlastního prodeje, aby šly opravit. Bez JavaScriptu zůstávají dostupná všechna pole.

Náhled odměny používá základní cenu produktu a zvlášť ceny jednotlivých variant; konečné ověření stále provádí server. Výběr prodávajícího aktualizuje odkaz na jeho profil. Vyplnění nového profilu jej automaticky vybere k vytvoření; tlačítko Zrušit nového vymaže pouze tato rozepsaná pole a vrátí předchozí výběr.

Editor označuje neuložené změny a varuje při odchodu. Automatické obnovení po úpravě produktu počká, dokud je formulář způsobu prodeje rozpracovaný. Uložení ceny produktu aktualizuje náhled odměny bez ztráty formuláře.


## Dodání bez pole u každého produktu (20. 10. 2026)

Pole Doba dodání se již nevyplňuje a nebrání objednání. Nový košík používá společné pravidlo `AssistedPurchase::DELIVERY_POLICY`: nákup se obstará bez zbytečného odkladu po zaplacení a s respektováním žádosti o dřívější zahájení služby; prodávající dodává podle svých podmínek, nejpozději do 30 dnů od uzavření kupní smlouvy s ním. Jinou lhůtu smí zástupce sjednat až po předchozím souhlasu zákazníka. Nejde o 30 dnů od objednání služby ani o odklad ověřování dostupnosti až po závazné koupi.

Toto je společné vymezení pověření pro všechny nové asistované nákupy, které musí provoz skutečně dodržet. Není to automatická kontrola partnerova webu. Před nákupem u partnera ověř jeho podmínky; pokud s tímto pověřením nejsou slučitelné, vyžádej si souhlas zákazníka před uzavřením kupní smlouvy. Publikované obchodní podmínky služby musí odpovídat tomuto postupu. Zákonnou informaci o dodání nelze nahradit pouhým neurčitým příslibem, že ji zákazník zjistí později.

Původní sloupec v databázi zůstává kvůli kompatibilitě, formulář do něj nezapisuje. Historické objednávky a jejich potvrzení dál zobrazují původní uložený termín. Nové pravidlo se ukládá do snímku nové objednávky a je součástí podepsaného souhrnu.

Ověření: [ČOI – dodání zboží](https://coi.gov.cz/faq/7-kdy-musi-prodavajici-zbozi-dodat/) (§ 1811 odst. 2 písm. d), § 2159 a § 2159a) a [ČOI – informace na tržištích](https://coi.gov.cz/faq/6-jak-funguji-on-line-trziste/). Rozbalení identifikačních detailů je zvolené provedení rozhraní; není to obecná právní výjimka z informační povinnosti. Povaha služby, rozpis ceny a konkrétní prodávající zůstávají viditelné před závaznou objednávkou, úplné údaje zůstávají i v potvrzení.

## Doplňkové BTC ceny

Orientační BTC se zobrazují na samostatném řádku, menším běžným písmem, v katalogu, detailu a souvisejících produktech, košíku, dopravě, souhrnu, potvrzení a zákaznickém účtu. U dílčího rozpisu asistence jsou jen Kč; BTC je u celkové ceny položky. Při vypnutém přepočtu nebo nedostupném kurzu BTC řádek nevzniká. Obchod aktuálně nemá přepínač hlavní měny na BTC; účetní a objednávkové ceny zůstávají Kč. Skutečnou částku a kurz bitcoinové platby určuje BTCPay, jeho platební rozhraní tyto styly nemění.
