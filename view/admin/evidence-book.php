<?php
declare(strict_types=1);

use SimpleStore\Accounting\EvidenceBookRowPresenter;
?>
    <section class="panel-panel">
      <div class="panel-panel-head"><h2>Kniha dokladů a plateb · <?= (int) $taxYear ?></h2></div>
      <p>Řádek objednávky ukazuje <strong>hodnotu objednávky</strong>, fakturu a případný propojený zápis v deníku. Samostatný peněžní zápis ukazuje <strong>skutečně zapsaný pohyb</strong>. Objednávka může být vidět ve více letech, pokud platba či doklad vznikly v jiném roce. Částky v této tabulce nesčítej jako příjmy ani jako výsledek pro daňové přiznání.</p>
      <form class="panel-search" method="get" action="<?= $escape($adminUrl) ?>">
        <input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="overview">
        <label>Rok<input type="number" name="year" min="2000" max="2100" value="<?= (int) $taxYear ?>" required></label>
        <label>Hledat objednávku, fakturu, VS nebo doklad<input name="q" maxlength="100" value="<?= $escape($evidenceSearch) ?>"></label>
        <button class="panel-button" type="submit">Zobrazit</button>
      </form>
      <?php if (!$accountingReady || !$invoicesReady): ?><p class="panel-error">Pro společnou knihu nejprve <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
      <?php if ($accountingReady && $invoicesReady): ?>
        <?php if ($evidencePage['items'] === []): ?><p class="panel-empty">V tomto roce nejsou odpovídající doklady ani peněžní zápisy.</p><?php endif; ?>
        <div class="panel-table-wrap"><table class="panel-table panel-evidence-table"><thead><tr><th>Datum · zdroj</th><th>Číslo a vazby</th><th>Osoba / účel</th><th>Stav platby</th><th>Zápis v deníku</th><th>Hodnota / pohyb</th><th>Další krok</th></tr></thead><tbody>
        <?php foreach ($evidencePage['items'] as $row): ?>
          <?php $presenter = new EvidenceBookRowPresenter($row, $adminUrl, $taxYear);
          $nextStep = $presenter->nextStep(); ?>
          <tr>
            <td><?= $escape($row['activity_date']) ?><br><small><?= $escape($presenter->source()) ?></small></td>
            <td>
              <?php if ($row['order_id'] !== null): ?>
                <a href="<?= $escape($adminUrl . '?section=orders&id=' . (int) $row['order_id']) ?>"><strong><?= $escape($row['order_number']) ?></strong></a>
              <?php elseif ($row['kind'] === 'invoice'): ?>
                <strong><?= $escape($row['order_number']) ?></strong><small> · smazaná objednávka</small>
              <?php endif; ?>
              <?php if ($row['invoice_id'] !== null): ?>
                <br><a href="<?= $escape($taxUrl . '&tab=invoices&year=' . $taxYear . '&invoice_id=' . (int) $row['invoice_id']) ?>">Faktura <?= $escape($row['invoice_number']) ?></a>
              <?php endif; ?>
              <?php if ($row['kind'] === 'entry'): ?>
                <strong><?= $escape($row['entry_reference'] !== '' ? $row['entry_reference'] : 'Zápis #' . $row['entry_id']) ?></strong>
              <?php endif; ?>
              <?php if ($row['variable_symbol'] !== null && $row['variable_symbol'] !== ''): ?>
                <br><small>VS <?= $escape($row['variable_symbol']) ?></small>
              <?php endif; ?>
            </td>
            <td><?= $escape($row['kind'] === 'entry' ?
                ($row['counterparty'] !== '' ? $row['counterparty'] : $row['description']) :
                $row['customer_email']) ?>
              <?php if ($row['kind'] === 'entry' && $row['counterparty'] !== ''): ?>
                <br><small><?= $escape($row['description']) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($row['kind'] === 'order'): ?>
                <strong><?= $escape($presenter->payment()) ?></strong><br>
                <small><?= $escape($accountingPaymentLabel($row['payment_method'])) ?></small>
                <?php if ($row['payment_paid_at'] !== null): ?><br><small><?= $escape($row['payment_paid_at']) ?> UTC</small><?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <?php if ($row['kind'] === 'order' && $row['receipt_id'] !== null): ?>
                Zapsán <?= $escape($row['receipt_date']) ?><br><small><?= $taxMoney($row['receipt_amount_czk']) ?></small>
              <?php elseif ($row['kind'] === 'order'): ?>
                <?= $row['payment_method'] === 'bank_transfer' ? 'Příjem nezapsán' : 'Vyúčtování brány zvlášť' ?>
              <?php elseif ($row['kind'] === 'entry'): ?>
                <strong><?= $row['entry_direction'] === 'income' ? 'Příjem' : 'Výdaj' ?></strong><br>
                <small><?= $escape($taxKind[$row['entry_tax_kind']] ?? $row['entry_tax_kind']) ?></small>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="panel-table-money"><?= $row['kind'] === 'entry' ? ($row['entry_direction'] === 'expense' ? '−' : '+') : '' ?><?= $taxMoney($row['amount_czk']) ?><br><small><?= $row['kind'] === 'order' ? 'Hodnota objednávky' : ($row['kind'] === 'invoice' ? 'Hodnota faktury' : 'Zápis v deníku') ?></small></td>
            <td><?php if ($nextStep !== null): ?><a href="<?= $escape($nextStep['href']) ?>"><?= $escape($nextStep['label']) ?></a><?php else: ?><span class="panel-help"><?= $escape($presenter->completionLabel()) ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <nav class="panel-quick panel-order-pages" aria-label="Stránky knihy dokladů">
          <?php if ($evidencePreviousUrl !== ''): ?><a href="<?= $escape($evidencePreviousUrl) ?>">← Předchozí</a><?php endif; ?>
          <?php if ($evidenceNextUrl !== ''): ?><a href="<?= $escape($evidenceNextUrl) ?>">Další →</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    </section>
