<?php
declare(strict_types=1);
$ledgerFilters ??= [];
$ledgerSummary ??= $taxSummary;
$editing = $taxEditEntry !== null;
$entryDraft = $taxEditEntry ?? ['entry_date' => $today, 'direction' => 'income', 'account' => 'bank', 'tax_kind' => 'taxable', 'purpose' => 'manual', 'currency' => 'CZK', 'order_id' => $_GET['order_id'] ?? ''];
if ($accountingError !== '' && in_array($_POST['action'] ?? '', ['tax-add-entry','tax-amend-entry'], true)) $entryDraft = array_replace($entryDraft, $_POST);
$field = static fn (string $key, mixed $fallback = ''): string => is_scalar($entryDraft[$key] ?? $fallback) ? (string) ($entryDraft[$key] ?? $fallback) : '';
$accounts = \SimpleStore\Accounting\TaxEvidenceRepository::ACCOUNTS;
$purposes = \SimpleStore\Accounting\TaxEvidenceRepository::PURPOSES;
$ledgerUrl = $taxUrl . '&tab=money&year=' . $taxYear;
?>
<section class="panel-panel">
  <div class="panel-panel-head"><h2>Peněžní deník <?= (int) $taxYear ?></h2><a class="panel-button" href="<?= $escape($ledgerUrl . '&download=ledger&' . http_build_query($ledgerFilters)) ?>">Export CSV</a></div>
  <p class="panel-help">Zapisuj skutečné platby podle podkladů. <a href="<?= $escape($taxUrl . '&tab=funds&year=' . $taxYear) ?>">Platby objednávek a asistovaný nákup</a> umí rozdělit vlastní příjem a svěřené peníze jedním formulářem.</p>
  <form class="panel-ledger-filters" method="get" action="<?= $escape($adminUrl) ?>">
    <input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="money"><input type="hidden" name="year" value="<?= (int) $taxYear ?>">
    <label>Hledat<input name="q" maxlength="150" placeholder="Doklad, popis, protistrana…" value="<?= $escape($ledgerFilters['q'] ?? '') ?>"></label>
    <?php foreach (['account' => ['Účet',$accounts], 'purpose' => ['Účel',$purposes], 'tax_kind' => ['Daňové zařazení',$taxKind]] as $key => [$label,$choices]): ?>
    <label><?= $escape($label) ?><select name="<?= $key ?>"><option value="">Vše</option><?php foreach ($choices as $value => $text): ?><option value="<?= $value ?>" <?= ($ledgerFilters[$key] ?? '') === $value ? 'selected' : '' ?>><?= $escape($text) ?></option><?php endforeach; ?></select></label>
    <?php endforeach; ?>
    <label>Od<input type="date" name="from" value="<?= $escape($ledgerFilters['from'] ?? '') ?>"></label><label>Do<input type="date" name="to" value="<?= $escape($ledgerFilters['to'] ?? '') ?>"></label>
    <label>Objednávka ID<input name="order_id" inputmode="numeric" value="<?= $escape($ledgerFilters['order_id'] ?? '') ?>"></label>
    <div class="panel-ledger-actions"><button class="panel-button">Filtrovat</button><a href="<?= $escape($ledgerUrl) ?>">Zrušit filtry</a></div>
  </form>
  <dl class="panel-ledger-totals">
    <?php foreach (['received' => 'Příjmy celkem', 'paid' => 'Výdaje celkem', 'income' => 'Daňové příjmy po vratkách', 'expenses' => 'Daňové výdaje po vratkách'] as $key => $label): ?><div><dt><?= $escape($label) ?></dt><dd><?= $taxMoney($ledgerSummary[$key] ?? 0) ?></dd></div><?php endforeach; ?>
  </dl><p class="panel-help">Součty odpovídají filtrům. Převody mezi vlastními účty jsou v obratech obou účtů; nejsou další tržbou.</p>
</section>
<section class="panel-panel" id="ledger-edit">
  <details <?= $editing || ($accountingError !== '' && in_array($_POST['action'] ?? '', ['tax-add-entry','tax-amend-entry'], true)) ? 'open' : '' ?> class="panel-accounting-details">
    <summary><?= $editing ? 'Upravit zápis #' . (int) $taxEditEntry['id'] : '+ Přidat peněžní pohyb' ?></summary>
    <form class="panel-form panel-evidence-form" method="post" action="<?= $escape($ledgerUrl . ($editing ? '&edit_entry=' . (int) $taxEditEntry['id'] : '')) ?>" data-ledger-form>
      <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="money"><input type="hidden" name="year" value="<?= (int) $taxYear ?>"><input type="hidden" name="action" value="<?= $editing ? 'tax-amend-entry' : 'tax-add-entry' ?>">
      <input type="hidden" name="request_key" value="<?= $escape($field('request_key', bin2hex(random_bytes(16)))) ?>">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $taxEditEntry['id'] ?>"><input type="hidden" name="version" value="<?= $escape($field('version', 1)) ?>"><input type="hidden" name="order_number" value="<?= $escape($field('order_number')) ?>"><?php endif; ?>
      <label>Datum skutečné platby<input type="date" name="entry_date" value="<?= $escape($field('entry_date')) ?>" required></label>
      <label>Účet<select name="account"><?php foreach ($accounts as $key => $label): ?><option value="<?= $key ?>" <?= $field('account') === $key ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
      <label>Účel<select name="purpose"><?php foreach ($purposes as $key => $label): ?><option value="<?= $key ?>" <?= $field('purpose') === $key ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
      <label>Pohyb<select name="direction"><option value="income" <?= $field('direction') === 'income' ? 'selected' : '' ?>>Příjem</option><option value="expense" <?= $field('direction') === 'expense' ? 'selected' : '' ?>>Výdaj</option></select></label>
      <label>Daňové zařazení<select name="tax_kind"><?php foreach ($taxKind as $key => $label): ?><option value="<?= $key ?>" <?= $field('tax_kind') === $key ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
      <label>Částka Kč<input name="amount_czk" inputmode="decimal" placeholder="0,00" value="<?= $escape($field('amount_czk')) ?>" required></label>
      <label class="panel-evidence-wide">Popis<input name="description" maxlength="255" value="<?= $escape($field('description')) ?>" required></label>
      <label>Protistrana<input name="counterparty" maxlength="190" value="<?= $escape($field('counterparty')) ?>"></label>
      <label>Doklad / reference výpisu<input name="reference" maxlength="100" value="<?= $escape($field('reference')) ?>"></label>
      <label>Objednávka ID · volitelné<input name="order_id" inputmode="numeric" value="<?= $escape($field('order_id')) ?>"></label>
      <details class="panel-evidence-wide" <?= $field('currency', 'CZK') !== 'CZK' ? 'open' : '' ?>><summary>Cizí měna a kryptoměna</summary><div class="panel-evidence-subgrid">
        <label>Měna<input name="currency" maxlength="3" value="<?= $escape($field('currency', 'CZK')) ?>" placeholder="CZK / EUR / BTC" required></label>
        <label>Původní částka / množství<input name="foreign_amount" inputmode="decimal" value="<?= $escape($field('foreign_amount')) ?>"></label>
        <label>Podklad ocenění v Kč<input name="valuation_note" maxlength="255" value="<?= $escape($field('valuation_note')) ?>" placeholder="Kurz, datum, zdroj, případně TXID"></label>
      </div><p class="panel-help">Korunovou hodnotu vyplň podle doloženého ocenění transakce. U rozdělené platby uveď ke každé části jen odpovídající množství. Pozdější směna krypta se eviduje zvlášť.</p></details>
      <?php if ($editing): ?><label class="panel-evidence-wide">Důvod opravy<input name="reason" minlength="8" maxlength="190" value="<?= $escape($field('reason')) ?>" required></label><?php endif; ?>
      <div class="panel-ledger-actions panel-evidence-wide"><button class="panel-button" type="submit"><?= $editing ? 'Uložit opravu' : 'Přidat pohyb' ?></button><?php if ($editing): ?><a href="<?= $escape($ledgerUrl) ?>">Zavřít úpravu</a><?php endif; ?></div>
    </form>
    <?php if ($editing): ?><details><summary>Vyřadit chybný zápis</summary><form class="panel-form" method="post" action="<?= $escape($ledgerUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="money"><input type="hidden" name="year" value="<?= (int) $taxYear ?>"><input type="hidden" name="action" value="tax-void-entry"><input type="hidden" name="id" value="<?= (int) $taxEditEntry['id'] ?>"><input type="hidden" name="version" value="<?= (int) $taxEditEntry['version'] ?>"><label>Důvod<input name="reason" minlength="8" maxlength="190" required></label><label class="panel-check"><input type="checkbox" name="confirmed" value="1" required> Vyřadit z deníku, ponechat v historii.</label><button class="panel-button">Vyřadit zápis</button></form></details><?php endif; ?>
  </details>
</section>
<details class="panel-panel" <?= $accountingError !== '' && ($_POST['action'] ?? '') === 'tax-transfer' ? 'open' : '' ?>><summary>Převod mezi vlastními korunovými účty</summary>
  <?php $transferDraft = $accountingError !== '' && ($_POST['action'] ?? '') === 'tax-transfer' ? $_POST : []; ?>
  <form class="panel-form panel-evidence-form" method="post" action="<?= $escape($ledgerUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="money"><input type="hidden" name="year" value="<?= (int) $taxYear ?>"><input type="hidden" name="action" value="tax-transfer"><input type="hidden" name="request_key" value="<?= $escape($transferDraft['request_key'] ?? bin2hex(random_bytes(16))) ?>">
    <label>Datum<input type="date" name="entry_date" value="<?= $escape($transferDraft['entry_date'] ?? $today) ?>" required></label>
    <?php foreach (['from_account'=>'Odkud','to_account'=>'Kam'] as $key=>$label): ?><label><?= $label ?><select name="<?= $key ?>"><?php foreach (['bank','cash','clearing'] as $account): ?><option value="<?= $account ?>" <?= ($transferDraft[$key] ?? ($key === 'from_account' ? 'clearing' : 'bank')) === $account ? 'selected' : '' ?>><?= $escape($accounts[$account]) ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
    <label>Částka Kč<input name="amount_czk" inputmode="decimal" value="<?= $escape($transferDraft['amount_czk'] ?? '') ?>" required></label><label>Reference<input name="reference" maxlength="100" value="<?= $escape($transferDraft['reference'] ?? '') ?>"></label><button class="panel-button">Zapsat oba pohyby</button>
  </form><p class="panel-help">U brány nejdřív zapiš hrubý příjem a poplatek podle vyúčtování. Výplatu čisté částky na bankovní účet eviduj tímto převodem.</p>
</details>
<section class="panel-panel">
  <div class="panel-table-wrap"><table class="panel-table panel-ledger-table"><thead><tr><th>Datum / doklad</th><th>Popis / účel</th><th>Protistrana / objednávka</th><th>Účet</th><th>Daňové zařazení</th><th>Příjem Kč</th><th>Výdaj Kč</th><th></th></tr></thead><tbody>
    <?php foreach ($taxEntries as $entry): ?><tr>
      <td><?= $escape($entry['entry_date']) ?><small><?= $escape($entry['reference']) ?></small></td>
      <td><strong><?= $escape($entry['description']) ?></strong><small><?= $escape($purposes[$entry['purpose']] ?? $entry['purpose']) ?></small></td>
      <td><?= $escape($entry['counterparty']) ?><?php if ($entry['order_id'] !== null): ?><small><a href="<?= $escape($taxUrl . '&tab=funds&order_id=' . (int) $entry['order_id'] . '&year=' . $taxYear) ?>"><?= $escape($entry['order_number'] ?: '#' . $entry['order_id']) ?></a></small><?php elseif ($entry['order_number'] !== ''): ?><small><?= $escape($entry['order_number']) ?> · archiv</small><?php endif; ?></td>
      <td><?= $escape($accounts[$entry['account']] ?? $entry['account']) ?><?php if ($entry['currency'] !== 'CZK'): ?><small><?= $escape($entry['foreign_amount'] . ' ' . $entry['currency']) ?></small><small><?= $escape($entry['valuation_note']) ?></small><?php endif; ?></td>
      <td><?= $escape($taxKind[$entry['tax_kind']] ?? $entry['tax_kind']) ?></td>
      <td class="panel-table-money"><?= $entry['direction'] === 'income' ? $taxMoney($entry['amount_czk']) : '—' ?></td><td class="panel-table-money"><?= $entry['direction'] === 'expense' ? $taxMoney($entry['amount_czk']) : '—' ?></td>
      <td><a href="<?= $escape($ledgerUrl . '&edit_entry=' . (int) $entry['id'] . '#ledger-edit') ?>">Upravit</a></td>
    </tr><?php endforeach; ?>
    <?php if ($taxEntries === []): ?><tr><td colspan="8">Tomuto výběru neodpovídá žádný pohyb.</td></tr><?php endif; ?>
  </tbody></table></div>
  <nav class="panel-ledger-actions" aria-label="Stránky deníku"><?php if ($taxEntriesPreviousUrl !== ''): ?><a href="<?= $escape($taxEntriesPreviousUrl) ?>">← Předchozí</a><?php endif; ?><?php if ($taxEntriesNextUrl !== ''): ?><a href="<?= $escape($taxEntriesNextUrl) ?>">Další →</a><?php endif; ?></nav>
  <p class="panel-help">50 řádků na stránku. CSV obsahuje všechny pohyby odpovídající filtrům. Originály dokladů a výpisů uschovej pod uvedenými referencemi.</p>
</section>
<details class="panel-panel"><summary>Historie oprav · posledních 100 zásahů do vybraného roku</summary>
  <?php foreach ($taxEntryHistory as $change): ?><details><summary>#<?= (int) $change['entry_id'] ?> · <?= $escape($change['created_at']) ?> UTC · <?= $escape($change['reason']) ?></summary><p>Správce #<?= (int) $change['admin_id'] ?> · <?= $change['action'] === 'voided' ? 'Vyřazení' : 'Oprava' ?></p><div class="panel-audit-values"><pre><?= $escape(json_encode(json_decode($change['old_json'], true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre><pre><?= $escape($change['new_json'] === null ? 'Vyřazeno z deníku' : json_encode(json_decode($change['new_json'], true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre></div></details><?php endforeach; ?>
</details>
<script src="<?= $escape(($basePath ?? '/') . 'assets/tax-evidence.js') ?>" defer></script>
