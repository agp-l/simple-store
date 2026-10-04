<?php
declare(strict_types=1);

$accountingGuideUrl = $adminUrl . '?section=accounting&tab=';
?>
<section class="panel-panel">
  <h2>Nejdřív vyber, podle čeho uplatníš výdaje</h2>
  <p>Pro OSVČ existují dvě běžné možnosti: <strong>skutečné výdaje</strong> doložené doklady, nebo <strong>výdaje procentem z příjmů</strong>. Paušální daň je ještě jiný režim, který tato aplikace nezpracovává. Volba v <a href="<?= $escape($accountingGuideUrl . 'settings') ?>">nastavení</a> slouží k orientaci v této evidenci; sama za tebe nepodává přiznání ani nemění režim u finančního úřadu.</p>
  <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Režim</th><th>Co vést pro daň z příjmů</th><th>Co tu použít</th></tr></thead><tbody>
    <tr><td><strong>Skutečné výdaje</strong></td><td>Příjmy a výdaje v daňovém členění, majetek a dluhy; u zboží také zásoby a zjištění skutečného stavu na konci roku.</td><td><a href="<?= $escape($accountingGuideUrl . 'money') ?>">Peněžní deník</a>, <a href="<?= $escape($accountingGuideUrl . 'balances') ?>">pohledávky a majetek</a>, provozní <a href="<?= $escape($adminUrl . '?section=products') ?>">sklad produktů</a> a fyzická inventura.</td></tr>
    <tr><td><strong>Výdaje procentem z příjmů</strong></td><td>Záznamy o příjmech a pohledávkách. Jednotlivé nákupy zboží už nelze přičítat k paušálním výdajům.</td><td><a href="<?= $escape($accountingGuideUrl . 'overview') ?>">Kniha dokladů</a> a <a href="<?= $escape($accountingGuideUrl . 'money') ?>">příjmy</a>; skutečné výdaje můžeš sledovat pro vlastní přehled, nejsou však dalšími daňovými výdaji v tomto režimu.</td></tr>
  </tbody></table></div>
  <p class="panel-help">U běžné živnosti bývá sazba 60 % s limitem výdajů 1,2 mil. Kč, pro jiné činnosti platí jiné sazby a limity. Přechod mezi režimy může vyžadovat úpravy základu daně. Ověř typ své činnosti a zvolený rok podle <a href="https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/fyzicke-osoby/podnikatel-osvc" target="_blank" rel="noopener noreferrer">Finanční správy</a>.</p>
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
    <li>Připrav podklady pro přiznání daně z příjmů a přehledy pro <a href="https://eportal.cssz.cz/web/portal/-/tiskopisy/osvc-2025" target="_blank" rel="noopener noreferrer">ČSSZ</a> a zdravotní pojišťovnu, pokud se na tebe vztahují. Aplikace přiznání ani přehledy nesestavuje.</li>
    <li>Sleduj zvlášť své povinnosti k DPH. Tato aplikace vystavuje doklad neplátce a nevede evidenci DPH. Pokud se staneš plátcem, používej odpovídající fakturaci a evidenci.</li>
  </ul>
  <p class="panel-help">Právní základ: <a href="https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/fyzicke-osoby/podnikatel-osvc" target="_blank" rel="noopener noreferrer">Finanční správa – daňová evidence a výdaje procentem</a>; <a href="https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/dotazy-a-odpovedi/dan-z-prijmu-fyzickych-osob/aktualne-k-dani-z-prijmu-fyzickych-osob-za-zdanovaci-obdobi-2016" target="_blank" rel="noopener noreferrer">její výklad evidence zásob</a>. Uvedený dotaz k zásobám je starší; aktuální sazby a lhůty vždy ověř pro svůj rok. Projekt EET 2.0 je podle <a href="https://financnisprava.gov.cz/cs/dane/eet-2-0" target="_blank" rel="noopener noreferrer">Finanční správy</a> ve vývoji; zde jej zatím nevykazujeme jako existující povinnost.</p>
</section>
