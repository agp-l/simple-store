<?php
declare(strict_types=1);

use SimpleStore\AfterSales\CaseNotice;
use SimpleStore\AfterSales\CaseRepository;

$e = static fn (mixed $value): string => htmlspecialchars((string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isWithdraw = ($preview['kind'] ?? '') === 'withdrawal';
?>
<main class="panel-area" id="obsah"><div class="panel-wrap" style="max-width:850px;padding-block:32px 60px">
  <section class="panel-panel">
    <p class="panel-eyebrow">Péče o zákazníky</p><h1>Reklamace a vrácení zboží</h1>
    <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <?php if ($case !== null): ?>
      <h2><?= $e(CaseRepository::KINDS[$case['kind']] ?? 'Podání') ?> · <?= $e($case['case_number']) ?></h2>
      <p><strong><?= $e(CaseRepository::STATUSES[$case['status']] ?? $case['status']) ?></strong> ·
        podáno <?= $e(CaseNotice::time((string) $case['submitted_at'])) ?></p>
      <?php if ($case['kind'] === 'complaint'): ?><p>Potvrzení přijetí reklamace včetně data, obsahu, požadovaného řešení a kontaktu najdete níže. Uložte si tuto stránku nebo ji vytiskněte.</p>
      <?php else: ?><p>Vaše výslovné odstoupení od smlouvy jsme zaznamenali v uvedený čas. Toto potvrzení si uložte.</p><?php endif; ?>
      <?php if ($mailState !== 'sent'): ?><p class="panel-notice" role="status">Potvrzení e-mailem zatím nemá ověřené doručení (stav: <?= $e(match ($mailState) {
        'queued' => 'čeká na odeslání', 'failed' => 'odeslání selhalo',
        'sending' => 'ověřuje se', 'missing' => 'e-mail zatím není připraven', default => $mailState,
      }) ?>). Tato stránka zůstává k dispozici pro zobrazení a tisk.</p><?php endif; ?>
      <dl class="panel-order-facts">
        <div><dt>Objednávka</dt><dd><?= $e($case['order_number']) ?></dd></div>
        <div><dt>Datum a čas podání</dt><dd><?= $e(CaseNotice::time((string) $case['submitted_at'])) ?></dd></div>
        <?php $seller = json_decode((string) ($case['seller_json'] ?? ''), true); ?>
        <?php if (is_array($seller) && !empty($seller['name'])): ?><div><dt>Prodávající</dt><dd><?= $e($seller['name']) ?><?= empty($seller['ico']) ? '' : ' · IČO ' . $e($seller['ico']) ?><br><?= $e(trim((string) ($seller['street'] ?? '') . ', ' . (string) ($seller['postal_code'] ?? '') . ' ' . (string) ($seller['city'] ?? ''), ', ')) ?><?= empty($seller['email']) ? '' : ' · ' . $e($seller['email']) ?></dd></div><?php endif; ?>
        <div><dt>Zboží</dt><dd><?= $e($case['quantity']) ?> × <?= $e(CaseNotice::itemLabel($case)) ?> · <?= $e(number_format((int) $case['unit_price_czk'], 0, ',', ' ')) ?> Kč/ks</dd></div>
        <div><dt>Jméno a kontakt</dt><dd><?= $e($case['customer_name']) ?><?= $case['customer_company'] === '' ? '' : ' · ' . $e($case['customer_company']) ?><br><?= $e($case['customer_email']) ?><?= $case['customer_phone'] === '' ? '' : ' · ' . $e($case['customer_phone']) ?></dd></div>
        <?php if ($case['delivered_on'] !== null): ?><div><dt>Datum převzetí podle zákazníka</dt><dd><?= $e($case['delivered_on']) ?></dd></div><?php endif; ?>
        <div><dt><?= $case['kind'] === 'withdrawal' ? 'Prohlášení' : 'Popis vady' ?></dt><dd><?= $case['kind'] === 'withdrawal' ? 'Odstupuji od kupní smlouvy pro uvedené zboží a množství. ' : '' ?><?= nl2br($e($case['description'])) ?></dd></div>
        <div><dt>Požadované řešení</dt><dd><?= $e(CaseRepository::REMEDIES[$case['requested_solution']] ?? $case['requested_solution']) ?></dd></div>
        <?php if ($case['received_at'] !== null): ?><div><dt>Zboží převzato</dt><dd><?= $e(CaseNotice::time((string) $case['received_at'])) ?></dd></div><?php endif; ?>
        <?php if ($case['resolved_at'] !== null): ?><div><dt>Písemné vyřízení</dt><dd><?= $e(CaseNotice::time((string) $case['resolved_at'])) ?> · <?= $e(CaseRepository::REMEDIES[$case['resolution_type']] ?? $case['status']) ?><br><?= nl2br($e($case['resolution_text'])) ?><?= empty($case['repair_duration']) ? '' : '<br>Doba opravy: ' . $e($case['repair_duration']) ?></dd></div><?php endif; ?>
        <?php if ($case['refunded_at'] !== null): ?><div><dt>Zaznamenané vrácení peněz</dt><dd><?= $e(number_format((int) $case['refund_amount_czk'], 0, ',', ' ')) ?> Kč · <?= $e(CaseNotice::time((string) $case['refunded_at'])) ?> · reference <?= $e($case['refund_reference']) ?></dd></div><?php endif; ?>
      </dl>
      <h3>Průběh případu</h3><ol class="panel-order-list">
        <?php foreach ($case['events'] as $event): ?><?php if ((int) $event['visible_to_customer'] !== 1) continue; ?>
          <li><strong><?= $e(CaseNotice::time((string) $event['created_at'])) ?> · <?= $e(CaseRepository::STATUSES[$event['status']] ?? $event['status']) ?></strong><br><?= nl2br($e($event['message'])) ?></li>
        <?php endforeach; ?>
      </ol>
      <p class="panel-help">Číslo případu uvádějte při komunikaci s obchodem. Odeslání formuláře není automatickým uznáním reklamace ani potvrzením výše vracené částky.</p>
      <p><button class="panel-button" type="button" onclick="window.print()">Vytisknout potvrzení</button></p>
    <?php elseif ($preview !== null && $order !== null && $isWithdraw): ?>
      <?php $item = $order['items'][(int) $preview['item_line'] - 1] ?? []; ?>
      <h2>Potvrdit odstoupení od smlouvy</h2>
      <p>Prohlašuji, že odstupuji od kupní smlouvy k objednávce <strong><?= $e($order['order_number']) ?></strong> v rozsahu <strong><?= $e($preview['quantity']) ?> × <?= $e($item['name'] ?? '') ?></strong>. Sdělení: <?= $e($preview['description'] ?: 'Bez dalšího sdělení.') ?></p>
      <p>Datum převzetí podle vás: <?= $e($preview['delivered_on'] ?: 'neuvedeno') ?>. Kontakt: <?= $e($order['customer_email']) ?>.</p>
      <p>Odesláním následujícího formuláře se odstoupení zapíše s přesným časem a přijde potvrzení e-mailem, je-li služba nastavena.</p>
      <form class="panel-form" method="post" action="<?= $e($supportUrl) ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="confirm-withdrawal">
        <label class="panel-check"><input type="checkbox" name="confirmed" value="1" required> Potvrzuji, že chci od uvedeného nákupu odstoupit.</label>
        <button class="panel-button" type="submit">Odeslat odstoupení</button>
      </form><p><a href="<?= $e($supportUrl . '?order=' . $preview['order_token']) ?>">← Zpět k objednávce</a></p>
    <?php elseif ($order !== null): ?>
      <h2>Objednávka <?= $e($order['order_number']) ?></h2>
      <?php if ($cases !== []): ?><h3>Dosavadní podání</h3><ul><?php foreach ($cases as $record): ?>
        <li><a href="<?= $e($supportUrl . '?case=' . $record['case_token']) ?>"><?= $e($record['case_number']) ?></a> · <?= $e($record['item_name']) ?> · <?= $e(CaseRepository::STATUSES[$record['status']] ?? $record['status']) ?></li>
      <?php endforeach; ?></ul><?php endif; ?>
      <?php if ($ready): ?>
        <p>Vyberte reklamaci kvůli vadě, nebo odstoupení od smlouvy při vrácení zboží. U odstoupení vás před odesláním čeká ještě samostatné potvrzení.</p>
        <div class="panel-grid">
          <section class="panel-panel"><h3>Reklamace vady</h3>
            <form class="panel-form" method="post" action="<?= $e($supportUrl) ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="submit-complaint"><input type="hidden" name="kind" value="complaint">
              <input type="hidden" name="order_token" value="<?= $e($orderToken) ?>"><input type="hidden" name="request_key" value="<?= $e($requestKey) ?>">
              <label>Zboží <select name="item_line" required><?php foreach ($order['items'] as $index => $item): ?><option value="<?= $index + 1 ?>"><?= $e($item['name'] ?? 'Položka') ?> · <?= (int) ($item['quantity'] ?? 0) ?> ks</option><?php endforeach; ?></select></label>
              <label>Počet reklamovaných kusů <input type="number" name="quantity" min="1" max="200" value="1" required></label>
              <label>Popis vady <textarea name="description" rows="5" minlength="10" maxlength="5000" required></textarea></label>
              <label>Požadované řešení <select name="requested_solution" required><?php foreach (CaseRepository::REMEDIES as $code => $label): ?><option value="<?= $e($code) ?>"><?= $e($label) ?></option><?php endforeach; ?></select></label>
              <label>Datum převzetí (pokud víte) <input type="date" name="delivered_on" max="<?= $e(gmdate('Y-m-d')) ?>"></label>
              <button class="panel-button" type="submit">Podat reklamaci</button>
            </form>
          </section>
          <section class="panel-panel"><h3>Vrácení zboží</h3>
            <p class="panel-help">Odstoupení od smlouvy můžete podat bez uvedení důvodu. Standardní lhůta je 14 dní od převzetí; výjimky a další postup uvádí stránka Výměna a vrácení zboží.</p>
            <form class="panel-form" method="post" action="<?= $e($supportUrl) ?>">
              <input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="review-withdrawal"><input type="hidden" name="order_token" value="<?= $e($orderToken) ?>"><input type="hidden" name="request_key" value="<?= $e(bin2hex(random_bytes(32))) ?>">
              <label>Zboží <select name="item_line" required><?php foreach ($order['items'] as $index => $item): ?><option value="<?= $index + 1 ?>"><?= $e($item['name'] ?? 'Položka') ?> · <?= (int) ($item['quantity'] ?? 0) ?> ks</option><?php endforeach; ?></select></label>
              <label>Počet vracených kusů <input type="number" name="quantity" min="1" max="200" value="1" required></label>
              <label>Datum převzetí (pokud víte) <input type="date" name="delivered_on" max="<?= $e(gmdate('Y-m-d')) ?>"></label>
              <label>Další sdělení (volitelné) <textarea name="description" rows="3" maxlength="5000"></textarea></label>
              <button class="panel-button" type="submit">Pokračovat k potvrzení →</button>
            </form>
          </section>
        </div>
      <?php else: ?><p>U této objednávky nyní nejde podat nový případ přes formulář. Kontaktujte obchod a uveďte číslo objednávky.</p><?php endif; ?>
    <?php else: ?><p>Formulář otevřete ze soukromého odkazu své objednávky nebo ze zákaznického účtu. Nemáte-li odkaz, kontaktujte obchod.</p><?php endif; ?>
  </section>
</div></main>
