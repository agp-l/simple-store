<?php
// Shared by the partner directory and inline product creation. Drafts need no browser validation.
$partnerValue = static fn (string $field): string => is_string($partnerFields[$field] ?? null) ? $partnerFields[$field] : '';
?>
<label>Obchodní firma / jméno podnikatele <input name="<?= $partnerPrefix ?>name" maxlength="190" value="<?= $escape($partnerValue('name')) ?>"></label>
<label>IČO <input name="<?= $partnerPrefix ?>ico" inputmode="numeric" maxlength="8" value="<?= $escape($partnerValue('ico')) ?>"></label>
<label>Sídlo a adresa pro stížnosti <input name="<?= $partnerPrefix ?>address" maxlength="500" value="<?= $escape($partnerValue('address')) ?>"></label>
<label>E-mail prodávajícího <input type="email" name="<?= $partnerPrefix ?>email" maxlength="190" value="<?= $escape($partnerValue('email')) ?>"></label>
<label>HTTPS adresa obchodních podmínek <input type="url" name="<?= $partnerPrefix ?>terms_url" maxlength="1000" value="<?= $escape($partnerValue('terms_url')) ?>"></label>
<label><input type="checkbox" name="<?= $partnerPrefix ?>confirmed" value="1" <?= (string) ($partnerFields['confirmed'] ?? '') === '1' ? 'checked' : '' ?>> Ověřil jsem, že partner umožňuje nákup jménem zákazníka a doklad vystaví zákazníkovi.</label>
