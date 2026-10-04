<?php
declare(strict_types=1);

$accountingGuideUrl = $adminUrl . '?section=accounting&tab=';
?>
<section class="panel-panel">
  <h2>Nejdřív urči svůj daňový režim</h2>
  <p>OSVČ může uplatňovat <strong>skutečné výdaje</strong>, <strong>výdaje procentem z příjmů</strong>, nebo po oznámení finančnímu úřadu vstoupit do <strong>paušálního režimu</strong>. Výdaje procentem a paušální daň nejsou totéž: u paušální daně platíš podle zvoleného pásma společnou měsíční zálohu na daň, sociální a zdravotní pojištění. Volba v <a href="<?= $escape($accountingGuideUrl . 'settings') ?>">nastavení</a> pouze upravuje tvoji interní evidenci; aplikace žádné oznámení úřadům nepodává ani za tebe zálohy neplatí.</p>
  <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Režim</th><th>Co vést pro daň z příjmů</th><th>Co tu použít</th></tr></thead><tbody>
    <tr><td><strong>Skutečné výdaje</strong></td><td>Příjmy a výdaje v daňovém členění, majetek a dluhy; u zboží také zásoby a zjištění skutečného stavu na konci roku.</td><td><a href="<?= $escape($accountingGuideUrl . 'money') ?>">Peněžní deník</a>, <a href="<?= $escape($accountingGuideUrl . 'balances') ?>">pohledávky a majetek</a>, provozní <a href="<?= $escape($adminUrl . '?section=products') ?>">sklad produktů</a> a fyzická inventura.</td></tr>
    <tr><td><strong>Výdaje procentem z příjmů</strong></td><td>Záznamy o příjmech a pohledávkách. Jednotlivé nákupy zboží už nelze přičítat k paušálním výdajům.</td><td><a href="<?= $escape($accountingGuideUrl . 'overview') ?>">Kniha dokladů</a> a <a href="<?= $escape($accountingGuideUrl . 'money') ?>">příjmy</a>; skutečné výdaje můžeš sledovat pro vlastní přehled, nejsou však dalšími daňovými výdaji v tomto režimu.</td></tr>
    <tr><td><strong>Paušální daň</strong></td><td>Průběžně sleduj skutečně přijaté příjmy ze všech samostatných činností, jejich druh a podmínky zvoleného pásma. Podklady zachovej i pro případ, že za rok budeš muset podat přiznání.</td><td><a href="<?= $escape($accountingGuideUrl . 'settings') ?>">Zaznamenej režim a pásmo</a>, veď doklady a kontroluj příjmy i měsíční úhrady; e-shop nezná tvoje příjmy a okolnosti mimo obchod.</td></tr>
  </tbody></table></div>
  <p class="panel-help">U běžné živnosti bývá sazba 60 % s limitem výdajů 1,2 mil. Kč, pro jiné činnosti platí jiné sazby a limity. Přechod mezi režimy může vyžadovat úpravy základu daně. Ověř typ své činnosti a zvolený rok podle <a href="https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/fyzicke-osoby/podnikatel-osvc" target="_blank" rel="noopener noreferrer">Finanční správy</a>.</p>
</section>

<section class="panel-panel">
  <h2>Paušální daň v roce 2026: co udělat</h2>
  <ol class="panel-guide-steps">
    <li><strong>Ověř podmínky a podej oznámení.</strong> Režim je pro fyzickou osobu OSVČ s příjmy ze samostatné činnosti do 2 mil. Kč, která není plátcem DPH ani nemá povinnost se registrovat jako plátce (výjimkou je identifikovaná osoba), není v insolvenci ani společníkem v. o. s. či komplementářem k. s. Při vstupu nesmí mít běžný příjem ze zaměstnání, příjmy zdaňované srážkou jsou výjimkou. Již podnikající museli vstup pro rok 2026 oznámit finančnímu úřadu do 12. ledna 2026; při nově zahajované činnosti lze oznámení podat nejpozději v den zahájení. Dřívější účast obvykle pokračuje automaticky. <a href="https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/pausalni-dan/obecne-informace" target="_blank" rel="noopener noreferrer">Podmínky a oznámení podle Finanční správy</a>.</li>
    <li><strong>Vyber pásmo podle všech činností, nejen e-shopu.</strong> Podívej se na příjmy předchozího roku a na druh každé samostatné činnosti. Procenta v následující tabulce označují výdaje, které <em>by bylo možné</em> u daného druhu činnosti uplatnit; v paušálním režimu se podle nich výdaje neodečítají.</li>
  </ol>
  <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Pásmo</th><th>Horní hranice rozhodných příjmů ze všech samostatných činností</th><th>Záloha měsíčně v roce 2026</th></tr></thead><tbody>
    <tr><td>I.</td><td>Do 1 mil. Kč vždy; do 1,5 mil. Kč, pokud alespoň 75 % příjmů pochází z činností s možnými 60% nebo 80% výdaji; do 2 mil. Kč při alespoň 75 % příjmů z činností s možnými 80% výdaji.</td><td>9 162 Kč</td></tr>
    <tr><td>II.</td><td>Do 1,5 mil. Kč vždy; do 2 mil. Kč, pokud alespoň 75 % příjmů pochází z činností s možnými 60% nebo 80% výdaji.</td><td>16 745 Kč</td></tr>
    <tr><td>III.</td><td>Do 2 mil. Kč bez rozlišení druhu činnosti.</td><td>27 139 Kč</td></tr>
  </tbody></table></div>
  <p>Zálohu posílej finančnímu úřadu zpravidla <strong>do 20. dne měsíce</strong>. Při zahájení činnosti se první záloha platí až do 20. dne následujícího měsíce spolu se zálohou za tento následující měsíc. V I. pásmu se částka pro rok 2026 zpětně snížila z 9 984 Kč na 9 162 Kč; kdo za leden až červen platil 9 984 Kč, má přeplatek 4 932 Kč, který lze za podmínek Finanční správy započíst na další zálohy nebo požádat o vrácení. <a href="https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/pausalni-dan/informace-k-institutu-pausalni-dane-pro-rok-2025" target="_blank" rel="noopener noreferrer">Aktuální tabulka a pravidla pro rok 2026</a>.</p>
  <p class="panel-help">Kontroluj také jiné příjmy mimo obchod: příjmy z další živnosti či povolání vstupují do limitu pásma; u vybraných zdanitelných příjmů z kapitálu, nájmu a ostatních příjmů platí pro paušální daň zvláštní limit úhrnem 50 000 Kč. Sleduj i případné zaměstnání a změnu v DPH. Aplikace tyto údaje nezná a nerozhodne, zda podmínky skutečně splňuješ. Pokud daň za rok nakonec není rovna paušální dani, musíš podat přiznání a přehledy ČSSZ i zdravotní pojišťovně. Při splnění všech podmínek se nepodávají. <a href="https://www.cssz.gov.cz/osvc-v-pausalnim-rezimu" target="_blank" rel="noopener noreferrer">ČSSZ: přehledy v paušálním režimu</a>.</p>
</section>

<section class="panel-panel">
  <h2>Co udělat u jedné objednávky</h2>
  <ol class="panel-guide-steps">
    <li><strong>Přijde objednávka.</strong> Dostane číslo objednávky a případný variabilní symbol. Je to závazek k vyřízení a při neuhrazené objednávce podklad k pohledávce; samo o sobě nejde o přijaté peníze.</li>
    <li><strong>Ověříš úhradu.</strong> U převodu porovnáš bankovní výpis a objednávku označíš jako zaplacenou. U brány ověříš její stav a potom vyúčtování a skutečný převod na účet. Stav „zaplaceno“ v objednávce ještě nedokládá bankovní příjem.</li>
    <li><strong>Vystavíš doklad.</strong> V <a href="<?= $escape($adminUrl . '?section=orders') ?>">detailu zaplacené objednávky</a> vystavíš fakturu s vlastní řadou F<strong>ROK</strong>-000001, zobrazíš ji a můžeš ji odeslat zákazníkovi. Původní číslo po opravě zůstává v historii. Číslo objednávky, číslo faktury a variabilní symbol plní různé účely.</li>
    <li><strong>Zapíšeš skutečný peněžní pohyb.</strong> Převod propojíš s objednávkou na jejím detailu. Výplatu brány, poplatek nebo jinou platbu vložíš do <a href="<?= $escape($accountingGuideUrl . 'money') ?>">peněžního deníku</a> s datem, částkou, popisem a referencí z výpisu. Zabráníš tím záměně fakturované částky a přijaté platby.</li>
    <li><strong>Uschováš podklad a vyřešíš rozdíly.</strong> Výpis, vyúčtování brány, nákupní doklad či potvrzení o vrácení peněz musíš mít k dispozici mimo tuto aplikaci. Chybné nebo vrácené platby oprav v objednávce <em>i</em> v deníku a zkontroluj již vydaný doklad.</li>
  </ol>
  <p class="panel-help">Spotřebiteli musí prodávající vydat doklad o koupi na jeho žádost; automatické vystavení po prodeji je praktický postup. <a href="https://coi.gov.cz/pro-spotrebitele/spotrebitelsky-pruvodce/" target="_blank" rel="noopener noreferrer">Česká obchodní inspekce: doklad o koupi</a>.</p>
</section>

<section class="panel-panel">
  <h2>Jak číst „daňové zařazení“</h2>
  <p>Jde o odpověď na otázku, zda konkrétní <em>skutečně přijaté nebo zaplacené</em> peníze vstupují do základu daně. Nejde o druh faktury ani stav objednávky.</p>
  <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Volba v deníku</th><th>Příklad</th><th>V souhrnu</th></tr></thead><tbody>
    <tr><td>Zdanitelný příjem</td><td>Přijatá platba za prodané zboží.</td><td>Započte se mezi příjmy.</td></tr>
    <tr><td>Nezdanitelný příjem</td><td>Přesun vlastních peněz do podnikatelské pokladny.</td><td>Nezapočte se.</td></tr>
    <tr><td>Daňový výdaj</td><td>Podložený nákup zboží pro prodej při skutečných výdajích.</td><td>Započte se mezi skutečné výdaje.</td></tr>
    <tr><td>Nedaňový výdaj</td><td>Přesun peněz mezi vlastní bankou a pokladnou; při procentních výdajích nákup zboží pro interní přehled.</td><td>Nesnižuje základ v souhrnu.</td></tr>
  </tbody></table></div>
  <p class="panel-help">Příklady nenahrazují posouzení konkrétní platby. U platebních bran a kryptoměn je nutné sladit výpisy, poplatky, kurz a případné vratky; tento modul to automaticky nepáruje.</p>
</section>

<section class="panel-panel">
  <h2>Kontrola na konci roku</h2>
  <ul class="panel-guide-steps">
    <li>Porovnej <a href="<?= $escape($accountingGuideUrl . 'money') ?>">deník</a> s bankovními výpisy, pokladnou a vyúčtováním bran. Vyexportuj CSV a doplň chybějící podklady.</li>
    <li>U skutečných výdajů ověř otevřené <a href="<?= $escape($accountingGuideUrl . 'balances') ?>">pohledávky, dluhy a majetek</a> a fyzicky zjisti zásoby. Sklad produktů je provozní počet kusů; neobsahuje sám o sobě nákupní ceny ani hotovou inventuru.</li>
    <li>Připrav podklady pro přiznání daně z příjmů a přehledy pro <a href="https://www.cssz.gov.cz/osvc-v-pausalnim-rezimu" target="_blank" rel="noopener noreferrer">ČSSZ</a> a zdravotní pojišťovnu, pokud se na tebe vztahují. V paušálním režimu nejprve ověř skutečné splnění podmínek za celý rok. Aplikace přiznání ani přehledy nesestavuje.</li>
    <li>Sleduj zvlášť své povinnosti k DPH. Tato aplikace vystavuje doklad neplátce a nevede evidenci DPH. Pokud se staneš plátcem, používej odpovídající fakturaci a evidenci.</li>
  </ul>
  <p class="panel-help">Právní základ: <a href="https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/fyzicke-osoby/podnikatel-osvc" target="_blank" rel="noopener noreferrer">Finanční správa – daňová evidence a výdaje procentem</a>; <a href="https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/dotazy-a-odpovedi/dan-z-prijmu-fyzickych-osob/aktualne-k-dani-z-prijmu-fyzickych-osob-za-zdanovaci-obdobi-2016" target="_blank" rel="noopener noreferrer">její výklad evidence zásob</a>. Uvedený dotaz k zásobám je starší; aktuální sazby a lhůty vždy ověř pro svůj rok. Projekt EET 2.0 je podle <a href="https://financnisprava.gov.cz/cs/dane/eet-2-0" target="_blank" rel="noopener noreferrer">Finanční správy</a> ve vývoji; zde jej zatím nevykazujeme jako existující povinnost.</p>
</section>
