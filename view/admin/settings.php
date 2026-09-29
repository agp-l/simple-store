<?php
declare(strict_types=1);
?>
<div class="panel-intro"><div><p class="panel-eyebrow">Pokladna</p><h1>Nastavení obchodu</h1>
  <p>Uprav dopravu, bankovní převod a stránku obchodních podmínek. Změny se použijí pro nové objednávky.</p></div></div>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Nastavení bylo uloženo.</p><?php endif; ?>
<?php if ($settingsError !== ''): ?><p class="panel-error" role="alert"><?= $escape($settingsError) ?></p><?php endif; ?>
<form class="panel-panel panel-form" method="post" action="<?= $escape($adminUrl . '?section=settings') ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
  <input type="hidden" name="action" value="save-checkout-settings">
  <h2>Výdejní místa a boxy</h2>
  <p class="panel-help">U výdejního místa zákazník vybere bod na mapě dopravce a opíše jeho název a adresu do objednávky. Přepravu a štítek zatím zadáváš ručně.</p>
  <?php foreach ($shippingCatalog as $code => $definition): ?>
    <?php if (str_ends_with($code, '_home') && $code === 'gls_home'): ?><h2>Na adresu</h2><?php endif; ?>
    <div class="panel-shipping-row"><strong><?= $escape($definition['label']) ?></strong><label>Cena v Kč<input type="number" name="shipping_price[<?= $escape($code) ?>]" value="<?= $escape($form['shipping_price'][$code]) ?>" min="0" max="100000" required></label><label class="panel-check"><input type="checkbox" name="shipping_enabled[<?= $escape($code) ?>]" value="1" <?= $form['shipping_enabled'][$code] === '1' ? 'checked' : '' ?>> Nabízet</label></div>
  <?php endforeach; ?>
  <h2>Bankovní převod</h2>
  <label>Číslo účtu<input name="account_display" value="<?= $escape($form['account_display']) ?>" placeholder="číslo/kód banky" autocomplete="off"></label>
  <label>IBAN (nepovinný, z čísla účtu se dopočítá)<input name="iban" value="<?= $escape($form['iban']) ?>" autocomplete="off"></label>
  <label>Jméno příjemce<input name="recipient" value="<?= $escape($form['recipient']) ?>" maxlength="120" autocomplete="off"></label>
  <label>Splatnost v dnech<input type="number" name="payment_due_days" value="<?= $escape($form['payment_due_days']) ?>" min="1" max="60" required></label>
  <h2>Obchodní podmínky</h2>
  <label>Adresa publikované stránky<input name="terms_url" value="<?= $escape($form['terms_url']) ?>" placeholder="<?= $escape($basePath . 'cs/obchodni-podminky') ?>"></label>
  <p class="panel-help">Můžeš je doplnit později. Až stránku vytvoříš a publikuješ, vlož sem její cestu začínající <?= $escape($basePath) ?>.</p>
  <h2>Místní vývoj</h2>
  <label class="panel-check"><input type="checkbox" name="local_test_checkout" value="1" <?= $form['local_test_checkout'] === '1' ? 'checked' : '' ?>> Povolit testovací objednávky na localhostu, pokud chybí bankovní účet</label>
  <p class="panel-help">Testovací objednávka nemá platební údaje ani QR kód. Mimo localhost je tento režim vypnutý.</p>
  <button class="panel-button" type="submit">Uložit nastavení</button>
</form>
