<?php
declare(strict_types=1);
$printEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$original = json_decode($selectedCorrection['invoice_json'],true,512,JSON_THROW_ON_ERROR);
$seller = json_decode($original['seller_json'],true,512,JSON_THROW_ON_ERROR);
$buyer = json_decode($original['buyer_json'],true,512,JSON_THROW_ON_ERROR);
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Opravný doklad <?= $printEscape($selectedCorrection['document_number']) ?></title><?php require __DIR__ . '/evidence-print-style.php'; ?></head><body>
<div class="toolbar"><button type="button" onclick="window.print()">Tisk / uložit jako PDF</button></div>
<h1>Opravný doklad <?= $printEscape($selectedCorrection['document_number']) ?></h1><p>Neplátce DPH · oprava k faktuře <?= $printEscape($original['document_number']) ?> ze dne <?= $printEscape($original['issue_date']) ?><br>Datum vystavení: <?= $printEscape($selectedCorrection['issue_date']) ?> · Objednávka <?= $printEscape($original['order_number']) ?></p>
<?php if ($selectedCorrection['voided_at'] !== null): ?><p class="notice"><strong>VYŘAZENÝ DOKLAD</strong> · <?= $printEscape($selectedCorrection['void_reason']) ?> · <?= $printEscape($selectedCorrection['voided_at']) ?> UTC</p><?php endif; ?>
<div class="columns"><?php foreach (['Poskytovatel / prodávající'=>$seller,'Zákazník'=>$buyer] as $label=>$party): ?><section><h2><?= $printEscape($label) ?></h2><p><?= $printEscape($party['name'] ?? '') ?><br><?= $printEscape($party['street'] ?? '') ?><br><?= $printEscape(($party['postal_code'] ?? '') . ' ' . ($party['city'] ?? '')) ?><?php if (($party['ico'] ?? '') !== ''): ?><br>IČO <?= $printEscape($party['ico']) ?><?php endif; ?><br><?= $printEscape($party['email'] ?? '') ?></p></section><?php endforeach; ?></div>
<table><thead><tr><th>Důvod a rozsah opravy</th><th class="money">Snížení ceny</th></tr></thead><tbody><tr><td><?= $printEscape($selectedCorrection['reason']) ?></td><td class="money">−<?= $printEscape(\SimpleStore\Accounting\TaxMoney::format($selectedCorrection['amount_czk'])) ?></td></tr></tbody></table>
<p class="total">Snížení celkem: −<?= $printEscape(\SimpleStore\Accounting\TaxMoney::format($selectedCorrection['amount_czk'])) ?></p><p class="muted">Doklad upravuje vlastní plnění na uvedené faktuře. Nepotvrzuje odeslání vratky. Vypořádání svěřených prostředků pro nákup jménem zákazníka je vedeno samostatně.</p>
</body></html>
