<?php
declare(strict_types=1);

use SimpleStore\AfterSales\CaseNotice;
use SimpleStore\AfterSales\CaseRepository;

$e = $escape;
?>
<div class="panel-intro"><div><p class="panel-eyebrow">Péče o zákazníky</p><h1>Reklamace a vrácení</h1>
  <p>Podání, písemné potvrzení, průběh a výsledek na jednom místě. Lhůtu reklamace sleduj od podání, nikoli od převzetí balíku.</p></div></div>
<?php if (!$returnsReady): ?><p class="panel-error" role="alert">Nejdřív <a href="<?= $e($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
<?php if ($returnError !== ''): ?><p class="panel-error" role="alert"><?= $e($returnError) ?></p><?php endif; ?>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Změna případu byla uložena.</p><?php endif; ?>
<?php if (($_GET['deleted'] ?? '') === '1'): ?><p class="panel-notice" role="status">Případ byl odstraněn.</p><?php endif; ?>
<?php if (($_GET['mail_problem'] ?? '') === '1'): ?><p class="panel-error" role="alert">E-mail o změně se nepodařilo připravit. Zkontroluj frontu zpráv a nastavení SMTP; vyřízení je uložené na této stránce.</p><?php endif; ?>
<?php if ($returnCase !== null): ?>
  <p><a class="panel-text-link" href="<?= $e($returnBaseUrl) ?>">← Všechny případy</a></p>
  <section class="panel-panel">
    <p class="panel-eyebrow"><?= $e(CaseRepository::KINDS[$returnCase['kind']] ?? $returnCase['kind']) ?></p>
    <h2><?= $e($returnCase['case_number']) ?> · <?= $e(CaseRepository::STATUSES[$returnCase['status']] ?? $returnCase['status']) ?></h2>
    <?php if (!CaseNotice::sellerReady($returnCase)): ?><p class="panel-error" role="alert">Údaje prodávajícího v potvrzení nejsou úplné. Doplň je v <a href="<?= $e($adminUrl . '?section=accounting&tab=settings') ?>">nastavení dokladů</a> a zákazníkovi pošli opravené identifikační údaje samostatně. Již vydané potvrzení uchovává původní snapshot.</p><?php endif; ?>
    <p>Objednávka <?php if ($returnCase['order_id'] !== null): ?><a href="<?= $e($adminUrl . '?section=orders&id=' . (int) $returnCase['order_id']) ?>"><?= $e($returnCase['order_number']) ?></a><?php else: ?><?= $e($returnCase['order_number']) ?><?php endif; ?> · Podáno <?= $e(CaseNotice::time((string) $returnCase['submitted_at'])) ?></p>
    <?php if ($returnCase['kind'] === 'complaint' && !in_array($returnCase['status'], ['resolved', 'rejected'], true)): ?>
      <p><strong>Orientační 30. den od podání:</strong> <?= $e((new \DateTimeImmutable((string) $returnCase['submitted_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Prague'))->modify('+30 days')->format('j. n. Y')) ?>. Právní konec lhůty může změnit víkend, svátek nebo dohoda se zákazníkem; ověř jej individuálně.</p>
    <?php endif; ?>
    <dl class="panel-order-facts">
      <div><dt>Zboží</dt><dd><?= (int) $returnCase['quantity'] ?> × <?= $e(CaseNotice::itemLabel($returnCase)) ?> · <?= $e(number_format((int) $returnCase['unit_price_czk'], 0, ',', ' ')) ?> Kč/ks</dd></div>
      <div><dt>Kontakt</dt><dd><?= $e($returnCase['customer_name']) ?><?= $returnCase['customer_company'] === '' ? '' : ' · ' . $e($returnCase['customer_company']) ?><br><?= $e($returnCase['customer_email']) ?> · <?= $e($returnCase['customer_phone']) ?></dd></div>
      <?php if ($returnCase['delivered_on'] !== null): ?><div><dt>Převzato podle zákazníka</dt><dd><?= $e($returnCase['delivered_on']) ?></dd></div><?php endif; ?>
      <div><dt><?= $returnCase['kind'] === 'complaint' ? 'Popis vady' : 'Sdělení a odstoupení' ?></dt><dd><?= $returnCase['kind'] === 'withdrawal' ? 'Zákazník odstoupil od smlouvy pro tuto položku. ' : '' ?><?= nl2br($e($returnCase['description'])) ?></dd></div>
      <div><dt>Požadavek</dt><dd><?= $e(CaseRepository::REMEDIES[$returnCase['requested_solution']] ?? $returnCase['requested_solution']) ?></dd></div>
      <?php if ($returnCase['received_at'] !== null): ?><div><dt>Zboží fyzicky přijato</dt><dd><?= $e(CaseNotice::time((string) $returnCase['received_at'])) ?></dd></div><?php endif; ?>
      <?php if ($returnCase['resolved_at'] !== null): ?><div><dt>Písemné vyřízení</dt><dd><?= $e(CaseNotice::time((string) $returnCase['resolved_at'])) ?> · <?= $e($returnCase['resolution_type']) ?><br><?= nl2br($e($returnCase['resolution_text'])) ?><?= empty($returnCase['repair_duration']) ? '' : '<br>Doba opravy: ' . $e($returnCase['repair_duration']) ?></dd></div><?php endif; ?>
      <?php if ($returnCase['refunded_at'] !== null): ?><div><dt>Ověřené vrácení peněz</dt><dd><?= $e(number_format((int) $returnCase['refund_amount_czk'], 0, ',', ' ')) ?> Kč · <?= $e(CaseNotice::time((string) $returnCase['refunded_at'])) ?><br>Reference <?= $e($returnCase['refund_reference']) ?></dd></div><?php endif; ?>
    </dl>
    <p><a class="panel-text-link" href="<?= $e($basePath . 'support.php?case=' . $returnCase['case_token']) ?>" target="_blank" rel="noopener noreferrer">Zobrazit tisknutelné potvrzení a výsledek ↗</a></p>
    <?php if ($returnMail !== 'sent'): ?><p class="panel-error" role="alert">Potvrzení přijetí e-mailem nemá ověřené odeslání (<?= $e($returnMail) ?>). Zkontroluj <a href="<?= $e($adminUrl . '?section=accounting&tab=mail') ?>">frontu e-mailů a SMTP</a> a pošli zákazníkovi potvrzení také přímo, pokud jej nelze doručit automaticky. Vypnutí volitelných stavových e-mailů neplatí pro potvrzení a vyřízení těchto podání.</p><?php endif; ?>
    <?php foreach ($returnMessages as $mailRow): ?><?php if ($mailRow['state'] === 'sent') continue; ?><p class="panel-help">Zpráva <?= $e($mailRow['event_key']) ?>: <?= $e($mailRow['state']) ?><?= $mailRow['last_error'] === null ? '' : ' · ' . $e($mailRow['last_error']) ?></p><?php endforeach; ?>
  </section>
  <div class="panel-grid panel-grid-catalog"><section class="panel-panel"><h2>Posunout vyřízení</h2>
    <form class="panel-form" method="post" action="<?= $e($returnBaseUrl . '&id=' . (int) $returnCase['id']) ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="return-update"><input type="hidden" name="id" value="<?= (int) $returnCase['id'] ?>">
      <label>Nový stav <select name="state" required>
        <option value="awaiting_goods">Čekáme na zboží</option><option value="received">Zboží fyzicky převzato</option><option value="reviewing">Posuzujeme</option><option value="resolved">Vyřízeno</option><option value="rejected">Zamítnuto s odůvodněním</option><option value="submitted">Znovu otevřít</option><option value="note">Jen interní poznámka</option>
      </select></label>
      <label>Skutečný způsob vyřízení (při stavu Vyřízeno) <select name="resolution_type"><option value="">Vyber při vyřízení</option><?php foreach (CaseRepository::REMEDIES as $code => $label): ?><option value="<?= $e($code) ?>"><?= $e($label) ?></option><?php endforeach; ?></select></label>
      <label>Doba opravy (pokud byla skutečně provedena) <input name="repair_duration" maxlength="190" placeholder="Například od 2. do 5. října 2026"></label>
      <label>Text zákazníkovi / důvod zamítnutí / interní poznámka <textarea name="message" rows="4" maxlength="5000"></textarea></label>
      <p class="panel-help">U vyřízení a zamítnutí napiš písemný výsledek. U interní poznámky zpráva zákazníkovi neodejde. Zamítnutí odstoupení neposuzuj automaticky jen podle zákazníkem zadaného data.</p>
      <button class="panel-button" type="submit">Uložit stav a informovat zákazníka</button>
    </form>
  </section><section class="panel-panel"><h2>Historie a vrácení peněz</h2>
    <ol><?php foreach ($returnCase['events'] as $event): ?><li><strong><?= $e(CaseNotice::time((string) $event['created_at'])) ?></strong> · <?= $e(CaseRepository::STATUSES[$event['status']] ?? $event['status']) ?><?= (int) $event['visible_to_customer'] === 0 ? ' · interní' : '' ?><br><?= nl2br($e($event['message'])) ?></li><?php endforeach; ?></ol>
    <?php if ($returnCase['status'] === 'resolved' && $returnCase['resolution_type'] === 'refund' && $returnCase['refunded_at'] === null): ?>
      <form class="panel-form" method="post" action="<?= $e($returnBaseUrl . '&id=' . (int) $returnCase['id']) ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="return-refund"><input type="hidden" name="id" value="<?= (int) $returnCase['id'] ?>">
        <label>Skutečně vrácená částka v Kč <input type="number" name="amount_czk" min="1" max="<?= (int) $returnCase['order_total_czk'] ?>" required></label>
        <label>Reference platby / výpisu <input name="reference" maxlength="120" required></label>
        <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Ověřil/a jsem skutečné odeslání peněz v bance nebo platební bráně.</label>
        <button class="panel-button" type="submit">Zapsat ověřené vrácení peněz</button>
      </form>
    <?php endif; ?>
    <p class="panel-help">Toto zaznamenání samo neposílá peníze ani nevytváří účetní vratku. Skutečný odtok zaneste zvlášť do <a href="<?= $e($adminUrl . '?section=accounting&tab=money') ?>">Peněžního deníku</a> a připojte bankovní nebo platební podklad.</p>
  </section></div>
  <section class="panel-panel"><details><summary>Smazat chybné nebo testovací podání</summary>
    <p>Trvale smaže případ, historii a zprávy z místní e-mailové fronty. Již odeslaný e-mail tím příjemci nezmizí. Zákonné doklady a povinnosti uchování posuzuj zvlášť.</p>
    <form class="panel-form" method="post" action="<?= $e($returnBaseUrl . '&id=' . (int) $returnCase['id']) ?>">
      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="return-delete"><input type="hidden" name="id" value="<?= (int) $returnCase['id'] ?>">
      <label>Opiš číslo případu <?= $e($returnCase['case_number']) ?> <input name="confirmation" required autocomplete="off"></label>
      <button class="panel-button" type="submit">Trvale smazat tento případ</button>
    </form>
  </details></section>
<?php elseif ($returnsReady): ?>
  <section class="panel-panel"><h2>Poslední případy</h2>
    <form method="get" action="<?= $e($adminUrl) ?>"><input type="hidden" name="section" value="returns">
      <label>Hledat číslo případu, objednávky nebo e-mail <input name="q" value="<?= $e($returnSearch) ?>" maxlength="100"></label>
      <button class="panel-button" type="submit">Hledat</button>
      <?php if ($returnSearch !== ''): ?><a href="<?= $e($returnBaseUrl) ?>">Zrušit hledání</a><?php endif; ?>
    </form>
    <?php if ($returnRows === []): ?><p>Zatím nebyla podána žádná reklamace ani žádost o vrácení.</p><?php else: ?>
      <div class="panel-table-scroll"><table class="panel-table"><thead><tr><th>Případ</th><th>Objednávka</th><th>Zboží</th><th>Podáno</th><th>Stav</th><th></th></tr></thead><tbody>
      <?php foreach ($returnRows as $entry): ?><tr>
        <td><strong><?= $e($entry['case_number']) ?></strong><br><?= $e(CaseRepository::KINDS[$entry['kind']] ?? $entry['kind']) ?></td>
        <td><?= $e($entry['order_number']) ?><br><?= $e($entry['customer_email']) ?></td>
        <td><?= $e($entry['item_name']) ?></td><td><?= $e(CaseNotice::time((string) $entry['submitted_at'])) ?></td>
        <td><?= $e(CaseRepository::STATUSES[$entry['status']] ?? $entry['status']) ?><?php if ($entry['kind'] === 'complaint' && !in_array($entry['status'], ['resolved', 'rejected'], true) && strtotime($entry['submitted_at'] . ' UTC') + 30 * 86400 < time()): ?><br><strong>Prověř lhůtu</strong><?php endif; ?></td>
        <td><a href="<?= $e($returnBaseUrl . '&id=' . (int) $entry['id']) ?>">Otevřít →</a></td>
      </tr><?php endforeach; ?></tbody></table></div>
      <div class="panel-order-pages"><?php if ($returnOffset > 0): ?><a href="<?= $e($returnBaseUrl . '&q=' . rawurlencode($returnSearch) . '&offset=' . max(0, $returnOffset - 40)) ?>">← Předchozí</a><?php endif; ?><?php if ($returnPage['nextOffset'] !== null): ?><a href="<?= $e($returnBaseUrl . '&q=' . rawurlencode($returnSearch) . '&offset=' . $returnPage['nextOffset']) ?>">Další →</a><?php endif; ?></div>
    <?php endif; ?>
  </section>
<?php endif; ?>
