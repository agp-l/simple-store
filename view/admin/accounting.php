<?php
declare(strict_types=1);

$accountingMoney = static fn (mixed $value): string => number_format((int) $value, 0, ',', ' ') . ' Kč';
$accountingPaymentLabel = static fn (mixed $method): string => match ($method) {
    'bank_transfer' => 'Bankovní převod',
    'comgate' => 'Comgate',
    'gopay' => 'GoPay',
    'btcpay' => 'BTCPay',
    default => (string) $method,
};
$accountingFulfillmentLabel = static fn (mixed $status): string => match ($status) {
    'new' => 'Nová',
    'processing' => 'Připravuje se',
    'ready_to_ship' => 'Připraveno k odeslání',
    'shipped' => 'Odesláno',
    'completed' => 'Dokončeno',
    'cancelled' => 'Zrušeno',
    default => (string) $status,
};
$accountingTab ??= 'overview';
$taxYear ??= (int) date('Y');
$accountingTitles = ['overview' => 'Doklady a platby', 'money' => 'Peněžní deník',
    'balances' => 'Pohledávky a majetek', 'stock' => 'Starší skladové podklady',
    'invoices' => 'Faktury', 'orders' => 'Platby objednávek',
    'mail' => 'E-mailová fronta', 'settings' => 'Podnikatel a režim',
    'guide' => 'Jak vést evidenci'];
?>
<div class="panel-intro">
  <div><p class="panel-eyebrow">Evidence podnikání · OSVČ</p><h1><?= $escape($accountingTitles[$accountingTab]) ?></h1>
    <p><?= $accountingTab === 'overview' ? 'Objednávky, vystavené doklady a zapsané peníze pohromadě. U každého řádku vidíš, co ještě potřebuješ vyřešit.' : 'Údaje na této stránce navazují na knihu dokladů a plateb.' ?></p></div>
</div>
<nav class="panel-accounting-nav" aria-label="Evidence podnikání">
  <?php foreach (['overview', 'money', 'invoices', 'guide', 'settings'] as $key): ?>
    <a href="<?= $escape($adminUrl . '?section=accounting&tab=' . $key . '&year=' . $taxYear) ?>" <?= $accountingTab === $key ? 'aria-current="page"' : '' ?>><?= $escape($accountingTitles[$key]) ?></a>
  <?php endforeach; ?>
</nav>
<?php if (($taxSettings['legal_form'] ?? 'sole_trader') === 'company'): ?>
  <p class="panel-error" role="alert">Je nastavená obchodní společnost. Tato evidence je určena pro OSVČ; pro společnost není účetnictvím ani podkladem k přiznání. <a href="<?= $escape($adminUrl . '?section=accounting&tab=settings') ?>">Zkontrolovat právní formu</a>.</p>
<?php elseif (preg_match('/s\.?\s*r\.?\s*o\.?/iu', (string) ($taxSettings['name'] ?? '')) === 1): ?>
  <p class="panel-notice" role="status">Údaje prodávajícího obsahují „s.r.o.“, ale právní forma je nastavená na OSVČ. <a href="<?= $escape($adminUrl . '?section=accounting&tab=settings') ?>">Ověř právní formu</a>, než použiješ tento přehled pro daně.</p>
<?php endif; ?>
<?php if (in_array($accountingTab, ['balances', 'stock', 'orders', 'mail'], true)): ?>
  <p class="panel-help"><a href="<?= $escape($adminUrl . '?section=accounting&tab=overview&year=' . $taxYear) ?>">← Zpět ke knize dokladů</a></p>
<?php endif; ?>
<?php if ($accountingTab === 'orders'): ?>
<section class="panel-panel">
  <h2>Období</h2>
  <form class="panel-search" method="get" action="<?= $escape($adminUrl) ?>">
    <input type="hidden" name="section" value="accounting">
    <input type="hidden" name="tab" value="orders">
    <label>Od <input type="date" name="from" value="<?= $escape($accountingFrom) ?>" required></label>
    <label>Do <input type="date" name="to" value="<?= $escape($accountingTo) ?>" required></label>
    <button class="panel-button" type="submit">Zobrazit</button>
  </form>
  <p class="panel-help">Vyber nejvýše 366 dní. Testovací objednávky se nezahrnují.</p>
</section>
<?php if (!$accountingReady): ?>
  <p class="panel-error" role="alert">Nejdřív <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
<?php endif; ?>
<?php if ($accountingError !== ''): ?><p class="panel-error" role="alert"><?= $escape($accountingError) ?></p><?php endif; ?>
<?php if ($accountingReady && $accountingError === ''): ?>
  <section class="panel-panel">
    <div class="panel-panel-head"><h2>Zaplacené objednávky</h2>
      <a class="panel-button" href="<?= $escape($accountingExportUrl) ?>">Stáhnout CSV</a></div>
    <dl class="panel-order-facts">
      <div><dt>Počet objednávek</dt><dd><?= (int) $accountingTotals['count'] ?></dd></div>
      <div><dt>Zboží</dt><dd><?= $accountingMoney($accountingTotals['subtotal_czk']) ?></dd></div>
      <div><dt>Doprava</dt><dd><?= $accountingMoney($accountingTotals['shipping_czk']) ?></dd></div>
      <div><dt>Celkem potvrzeno v objednávkách</dt><dd><strong><?= $accountingMoney($accountingTotals['total_czk']) ?></strong></dd></div>
    </dl>
    <p class="panel-help">Jde o podklady z objednávek, nikoli o faktury, daňové doklady nebo evidenci DPH. Datum je okamžik ručního potvrzení převodu správcem nebo ověření platby u brány; u online plateb se může lišit od dne vyplacení na bankovní účet. Zrušené zaplacené objednávky zůstávají v součtu; vratky systém zatím neeviduje a je potřeba je ověřit ve výpisu účtu a vyúčtování brány.</p>
  </section>
  <section class="panel-panel">
    <h2>Seznam plateb</h2>
    <?php if ($accountingPage['items'] === []): ?><p class="panel-empty">Ve vybraném období nejsou potvrzené platby.</p><?php endif; ?>
    <div class="panel-order-list">
      <?php foreach ($accountingPage['items'] as $row): ?>
        <a class="panel-order-row" href="<?= $escape($adminUrl . '?section=orders&id=' . (int) $row['id']) ?>">
          <span><strong><?= $escape($row['order_number']) ?></strong><small>VS: <?= $escape($row['variable_symbol'] ?? '—') ?> · <?= $escape($row['payment_paid_at']) ?> UTC</small></span>
          <span><strong><?= $escape($row['customer_name']) ?></strong><small><?= $escape($row['customer_email'] ?? '') ?></small></span>
          <span><strong><?= $accountingMoney($row['total_czk']) ?></strong><small>Zboží <?= $accountingMoney($row['subtotal_czk']) ?> + doprava <?= $accountingMoney($row['shipping_czk']) ?></small></span>
          <span><?= $escape($accountingPaymentLabel($row['payment_method'])) ?><br><?= $escape($accountingFulfillmentLabel($row['status'])) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <nav class="panel-quick panel-order-pages" aria-label="Stránky účetních podkladů">
      <?php if ($accountingPreviousUrl !== ''): ?><a href="<?= $escape($accountingPreviousUrl) ?>">← Předchozí</a><?php endif; ?>
      <?php if ($accountingNextUrl !== ''): ?><a href="<?= $escape($accountingNextUrl) ?>">Další →</a><?php endif; ?>
    </nav>
  </section>
  <section class="panel-panel">
    <h2>Opravy plateb a smazané objednávky</h2>
    <p class="panel-help">Zásahy za vybrané období podle data změny (UTC). Původní potvrzení platby a částka zůstávají dohledatelné i po smazání objednávky. Tato evidence nenahrazuje bankovní výpis ani doklad.</p>
    <?php if (!$financialEventsReady): ?><p class="panel-help">Pro historii zásahů <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
    <?php if ($financialEventsReady && $financialChanges['items'] === []): ?><p class="panel-empty">V tomto období nejsou finanční opravy ani smazání.</p><?php endif; ?>
    <?php if ($financialEventsReady && $financialChanges['items'] !== []): ?>
      <ul>
        <?php foreach ($financialChanges['items'] as $change): ?>
          <?php $financialAction = match ($change['action']) {
              'payment_correction' => 'Oprava potvrzení platby',
              'order_deleted' => 'Smazání objednávky',
              'provider_payment_after_delete' => 'Platba potvrzena bránou po smazání',
              'provider_refund_after_delete' => 'Vrácení platby po smazání',
              'provider_partial_refund_deleted' => 'Částečné vrácení platby po smazání',
              default => 'Změna platby',
          }; ?>
          <li><strong><?= $escape($change['order_number']) ?></strong> · <?= $escape($financialAction) ?> · <?= (int) $change['admin_id'] === 0 ? 'platební brána' : 'správce #' . (int) $change['admin_id'] ?> · <?= $escape($change['created_at']) ?> UTC<br>
            VS <?= $escape($change['variable_symbol'] ?? '—') ?> · <?= $accountingMoney($change['total_czk']) ?> · původní platba <?= $escape($change['payment_status_before'] === 'paid' ? 'potvrzená' : 'čekající') ?><?php if ($change['payment_paid_at'] !== null): ?> · původně potvrzeno <?= $escape($change['payment_paid_at']) ?> UTC<?php endif; ?><br><?= $escape($change['reason']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <nav class="panel-quick panel-order-pages" aria-label="Stránky historie zásahů">
      <?php if ($financialPreviousUrl !== ''): ?><a href="<?= $escape($financialPreviousUrl) ?>">← Předchozí zásahy</a><?php endif; ?>
      <?php if ($financialNextUrl !== ''): ?><a href="<?= $escape($financialNextUrl) ?>">Další zásahy →</a><?php endif; ?>
    </nav>
  </section>
<?php endif; ?>
<?php elseif ($accountingTab === 'guide'): ?>
  <?php require __DIR__ . '/accounting-guide.php'; ?>
<?php else: ?>
  <?php require __DIR__ . '/tax.php'; ?>
<?php endif; ?>
