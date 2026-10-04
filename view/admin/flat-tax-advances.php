<?php
declare(strict_types=1);

use SimpleStore\Accounting\FlatTaxRateSchedule;

$advanceMonths = [1 => 'Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen',
    'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'];
$monthlyReference = FlatTaxRateSchedule::monthly($taxYear, (int) ($taxYearMode['flat_tax_band'] ?? 1));
$advanceByMonth = [];
foreach ($flatTaxAdvanceRows as $advance) $advanceByMonth[(int) $advance['tax_month']][] = $advance;
?>
<section class="panel-panel">
  <div class="panel-panel-head"><h2>Paušální zálohy · <?= (int) $taxYear ?></h2>
    <a href="<?= $escape($taxUrl . '&tab=guide&year=' . $taxYear) ?>">Podmínky a pásma ↗</a></div>
  <p>Jde o tvoje <strong>ručně zapsané skutečné platby</strong>, ne o stav účtu u finančního úřadu. Zapsaná záloha se současně vloží do peněžního deníku jako nedaňový výdaj. Z této tabulky nelze určit nedoplatek ani splnění podmínek režimu.</p>
  <?php if (!$flatTaxAdvancesReady): ?>
    <p class="panel-error">Pro evidenci záloh <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
  <?php else: ?>
    <?php if ($monthlyReference !== null): ?>
      <p class="panel-help">Publikovaná měsíční záloha <?= (int) $taxYear ?> pro pásmo <?= (int) $taxYearMode['flat_tax_band'] ?>: <strong><?= $taxMoney($monthlyReference) ?></strong>. V prvním pásmu byla částka za leden až červen původně vyšší a později zpětně snížena; přeplatek může být započten. Proto se záznamy níže automaticky neoznačují jako zaplacené či dlužné.</p>
    <?php else: ?>
      <p class="panel-help">Pro tento rok zde nemáme ověřenou sazbu. Výši a splatnost ověř u <a href="https://financnisprava.gov.cz/cs/dane/dane/dan-z-prijmu/pausalni-dan/obecne-informace" target="_blank" rel="noopener noreferrer">Finanční správy</a>.</p>
    <?php endif; ?>
    <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Měsíc, ke kterému platbu přiřazuješ</th><th>Záznamy skutečných úhrad</th></tr></thead><tbody>
      <?php foreach ($advanceMonths as $monthNumber => $monthName): ?>
        <tr><td><strong><?= $escape($monthName) ?></strong></td><td>
          <?php if (empty($advanceByMonth[$monthNumber])): ?><span class="panel-help">V tomto CMS bez záznamu; ověř výpis nebo zápočet.</span><?php endif; ?>
          <?php foreach ($advanceByMonth[$monthNumber] ?? [] as $advance): ?>
            <?php if ($advance['active_entry_id'] === null || $advance['direction'] !== 'expense' || $advance['tax_kind'] !== 'nondeductible'): ?>
              <span class="panel-error">Navázaný zápis #<?= (int) $advance['entry_id'] ?> byl vyřazen nebo změněn. Zkontroluj deník a podklad.</span>
            <?php else: ?>
              <?= $escape($advance['entry_date']) ?> · <strong><?= $taxMoney($advance['amount_czk']) ?></strong>
              <?php if ($advance['reference'] !== ''): ?> · <?= $escape($advance['reference']) ?><?php endif; ?>
              · <a href="<?= $escape($taxUrl . '&tab=money&year=' . substr((string) $advance['entry_date'], 0, 4) . '&edit_entry=' . (int) $advance['entry_id']) ?>">Zobrazit v deníku</a><br>
            <?php endif; ?>
          <?php endforeach; ?>
        </td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($taxYearMode['flat_tax_confirmed']): ?>
      <details class="panel-entry-add"><summary>Zapsat skutečně uhrazenou zálohu</summary>
        <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="tax-add-flat-advance"><input type="hidden" name="year" value="<?= (int) $taxYear ?>">
          <label>Měsíc, kterého se platba týká<select name="tax_month"><?php foreach ($advanceMonths as $monthNumber => $monthName): ?><option value="<?= $monthNumber ?>"><?= $escape($monthName) ?></option><?php endforeach; ?></select></label>
          <label>Datum skutečné úhrady<input name="entry_date" type="date" value="<?= $escape($today) ?>" required></label>
          <label>Zaplacená částka v Kč<input name="amount_czk" type="number" min="1" required></label>
          <label>Úhrada z<select name="account"><option value="bank">Bankovní účet</option><option value="cash">Hotovost</option></select></label>
          <label>Reference z výpisu<input name="reference" maxlength="100"></label>
          <label>Poznámka k platbě nebo přeplatku<input name="note" maxlength="100"></label>
          <p class="panel-help">Zadej to, co bylo skutečně zaplaceno. Zápočet přeplatku bez nové platby sem nevkládej jako peněžní pohyb; dolož jej záznamem finančního úřadu a ponech poznámku ve svých podkladech.</p>
          <button class="panel-button" type="submit">Zapsat úhradu do deníku</button>
        </form>
      </details>
    <?php else: ?>
      <p class="panel-help">Formulář pro úhradu zpřístupníš potvrzením oznámeného režimu v <a href="<?= $escape($taxUrl . '&tab=settings&year=' . $taxYear) ?>">nastavení roku</a>.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>
