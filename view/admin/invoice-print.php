<?php
declare(strict_types=1);

// The authenticated admin route supplies an immutable invoice snapshot.
$printEscape = static fn (mixed $value): string => htmlspecialchars((string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$printMoney = static fn (mixed $value): string => number_format((int) $value, 0, ',', ' ') . ' Kč';
$seller = $selectedInvoice['seller'];
$buyer = $selectedInvoice['buyer'];
?>
<!doctype html>
<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Faktura <?= $printEscape($selectedInvoice['document_number']) ?></title>
<style>
  body{font:16px/1.45 system-ui,sans-serif;color:#1c2833;max-width:820px;margin:2rem auto;padding:0 1.5rem}
  h1{font-size:2rem;margin-bottom:0}h2{font-size:1.1rem}small{color:#555}
  .columns{display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin:2rem 0}
  .columns p{margin:.25rem 0}table{border-collapse:collapse;width:100%;margin:1.5rem 0}
  th,td{text-align:left;border-bottom:1px solid #cbd5df;padding:.6rem .35rem}
  .money{text-align:right;white-space:nowrap}.total{text-align:right;font-size:1.35rem;font-weight:700}
  .toolbar{margin:1rem 0 2rem}.toolbar button{padding:.7rem 1.2rem;cursor:pointer}
  @media print{body{margin:0;max-width:none}.toolbar{display:none}@page{size:A4;margin:18mm}}
  @media(max-width:580px){.columns{grid-template-columns:1fr}table{font-size:.82rem}}
</style></head><body>
<div class="toolbar"><button type="button" onclick="window.print()">Vytisknout / uložit jako PDF</button></div>
<h1>Faktura <?= $printEscape($selectedInvoice['document_number']) ?></h1>
<small>Nejsem plátce DPH. · Objednávka <?= $printEscape($selectedInvoice['order_number']) ?></small>
<div class="columns">
  <section><h2>Dodavatel</h2><p><strong><?= $printEscape($seller['name'] ?? '') ?></strong></p>
    <p><?= $printEscape($seller['street'] ?? '') ?><br><?= $printEscape(($seller['postal_code'] ?? '') . ' ' . ($seller['city'] ?? '')) ?></p>
    <p>IČO: <?= $printEscape($seller['ico'] ?? '') ?></p>
    <?php if (($seller['email'] ?? '') !== ''): ?><p><?= $printEscape($seller['email']) ?></p><?php endif; ?>
    <?php if (($seller['phone'] ?? '') !== ''): ?><p><?= $printEscape($seller['phone']) ?></p><?php endif; ?>
  </section>
  <section><h2>Odběratel</h2><p><strong><?= $printEscape($buyer['name'] ?? '') ?></strong></p>
    <?php if (($buyer['street'] ?? '') !== ''): ?><p><?= $printEscape($buyer['street']) ?><br><?= $printEscape(($buyer['postal_code'] ?? '') . ' ' . ($buyer['city'] ?? '')) ?></p><?php endif; ?>
    <?php if (($buyer['ico'] ?? '') !== ''): ?><p>IČO: <?= $printEscape($buyer['ico']) ?></p><?php endif; ?>
    <p><?= $printEscape($buyer['email'] ?? '') ?></p>
  </section>
</div>
<div class="columns"><p>Datum vystavení: <strong><?= $printEscape($selectedInvoice['issue_date']) ?></strong><br>
  Splatnost: <?= $printEscape($selectedInvoice['due_date']) ?></p>
  <p>Platba: <?= match ($selectedInvoice['payment_method'] ?? 'bank_transfer') {
      'comgate' => 'online přes Comgate',
      'gopay' => 'online přes GoPay',
      'btcpay' => 'přes BTCPay Server',
      default => 'bankovním převodem',
  } ?>, při vystavení evidováno jako uhrazené<br>
  <?php if (($selectedInvoice['payment_method'] ?? 'bank_transfer') === 'bank_transfer'): ?>
    Účet: <?= $printEscape($selectedInvoice['bank_account']) ?><br>
    Variabilní symbol: <?= $printEscape($selectedInvoice['variable_symbol'] ?? '') ?>
  <?php else: ?>Objednávka: <?= $printEscape($selectedInvoice['order_number']) ?><?php endif; ?></p></div>
<table><thead><tr><th>Položka</th><th>Počet</th><th class="money">Cena za kus</th><th class="money">Celkem</th></tr></thead><tbody>
<?php foreach ($selectedInvoice['items'] as $item): ?>
  <tr><td><?= $printEscape($item['name'] ?? '') ?></td><td><?= (int) ($item['quantity'] ?? 0) ?></td>
    <td class="money"><?= $printMoney($item['unit_price_czk'] ?? 0) ?></td>
    <td class="money"><?= $printMoney((int) ($item['quantity'] ?? 0) * (int) ($item['unit_price_czk'] ?? 0)) ?></td></tr>
<?php endforeach; ?>
<tr><td>Doprava</td><td>1</td><td class="money"><?= $printMoney($selectedInvoice['shipping_czk']) ?></td>
  <td class="money"><?= $printMoney($selectedInvoice['shipping_czk']) ?></td></tr>
</tbody></table>
<p class="total">Celkem: <?= $printMoney($selectedInvoice['total_czk']) ?></p>
</body></html>
