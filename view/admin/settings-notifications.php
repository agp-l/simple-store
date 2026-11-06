<?php declare(strict_types=1); ?>
<?php if($ntfyNotice!==''): ?><p class="panel-notice" role="status"><?= $escape($ntfyNotice) ?></p><?php endif; ?>
<?php if($ntfy!==null): ?>
<form class="panel-form panel-settings-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=settings&tab=notifications') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="notifications"><input type="hidden" name="action" value="ntfy-save">
<section class="panel-panel panel-settings-block">
<h2>Oznámení nových objednávek přes ntfy</h2>
<p>Po uložení nové objednávky dostaneš číslo, čas, jméno zákazníka, zboží s variantami a počty kusů, cenu a odkaz do administrace. Oznámení objednávky ještě neznamená, že je zaplacená.</p>
<label><input type="checkbox" name="enabled" value="1" <?= $ntfy['enabled']?'checked':'' ?>> Zapnout oznámení nových objednávek</label>
<label>Tajná adresa ntfy<input type="url" name="address" value="<?= $escape($ntfy['topic']!==''?'https://ntfy.sh/'.$ntfy['topic']:'') ?>" maxlength="81" autocomplete="off" spellcheck="false" placeholder="https://ntfy.sh/tvoje-tajne-tema"></label>
<p class="panel-help">Vlož celou adresu svého tématu na https://ntfy.sh/. Adresa se používá jen na serveru a v administraci. Nechráněné téma může číst každý, kdo zná jeho název. Pro údaje zákazníků použij soukromé téma s řízeným přístupem.</p>
<label>Přístupový token (volitelný)<input type="text" name="token" value="<?= $escape($ntfy['token']) ?>" maxlength="512" autocomplete="off" spellcheck="false"></label>
<p class="panel-help">Pro chráněné téma použij token s právem publikovat. Prázdné pole ponechá uložený token. Token je v databázi šifrovaný a v této administraci viditelný.</p>
<label><input type="checkbox" name="clear_token" value="1"> Smazat uložený token (nejdřív vyprázdni pole)</label>
<label>Priorita<select name="priority"><?php foreach([1=>'Minimální',2=>'Nízká',3=>'Běžná',4=>'Vysoká',5=>'Naléhavá'] as $value=>$label): ?><option value="<?= $value ?>" <?= $ntfy['priority']===$value?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
<label><input type="checkbox" name="include_contact" value="1" <?= $ntfy['include_contact']?'checked':'' ?>> Přidat také e-mail a telefon zákazníka</label>
<button class="button" type="submit">Uložit oznámení</button>
</section></form>
<section class="panel-panel"><h2>Jak to nastavit v telefonu</h2>
<ol><li>Nainstaluj aplikaci ntfy do telefonu a povol jí oznámení.</li><li>Na ntfy.sh si připrav vlastní téma, ideálně soukromé s přístupovými právy. V aplikaci přidej odběr stejného tématu na serveru <strong>https://ntfy.sh</strong>; pro chráněné téma se přihlas účtem s právem číst.</li><li>Sem vlož celou tajnou adresu tématu a případný token, vyber prioritu a klikni na <strong>Uložit oznámení</strong>.</li><li>Pošli test tlačítkem níže a ověř doručení v telefonu. Test používá poslední uložené nastavení a funguje i při vypnutém modulu.</li><li>Zaškrtni <strong>Zapnout oznámení nových objednávek</strong> a znovu ulož.</li></ol>
<p>Staré objednávky se zpětně nerozesílají. Při výpadku ntfy zůstane objednávka uložená. Odmítnuté zprávy zkusí znovu stávající e-mailový cron / <code>tools/mail-worker.php</code>, nejvýše pět pokusů s odstupem alespoň 15 minut. Bez tohoto cronu použij ruční opakování níže. Vypnutí pozastaví čekající zprávy; po zapnutí se mohou odeslat. Změna tématu staré zprávy nepřesměruje na jiné téma.</p>
<form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=settings&tab=notifications') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="notifications"><button type="submit" name="action" value="ntfy-test" class="button button--ghost">Poslat testovací zprávu</button></form>
</section>
<?php endif; ?>
<section class="panel-panel"><h2>Posledních 30 oznámení</h2><p>Odesláno znamená přijetí serverem ntfy; doručení do telefonu ověř v aplikaci. Nepotvrzené zprávy automaticky neopakujeme, protože již mohly dorazit.</p>
<?php if($ntfyRows===[]): ?><p>Zatím žádná oznámení.</p><?php endif; ?>
<?php foreach($ntfyRows as $row): ?><article class="panel-settings-block">
<h3><?= $escape($row['order_number']) ?></h3>
<p><?= $escape(\SimpleStore\Rendering\LocalDateTime::czech($row['created_at'])) ?> · <?= $escape(['pending'=>'Čeká','sending'=>'Odesílá se','sent'=>'Odesláno','failed'=>'Chyba','uncertain'=>'Nepotvrzeno','skipped'=>'Vynecháno'][$row['state']]??$row['state']) ?> · Pokusy: <?= (int)$row['attempts'] ?></p>
<?php if($row['last_error']!==''): ?><p><?= $escape($row['last_error']) ?></p><?php endif; ?>
<?php if(in_array($row['state'],['pending','failed','uncertain'],true)): ?><form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=settings&tab=notifications') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="notifications"><input type="hidden" name="order_id" value="<?= (int)$row['order_id'] ?>"><label><input type="checkbox" name="confirm_retry" value="1" required> Zkontroloval jsem telefon. Opakování může vytvořit duplicitní zprávu.</label> <button type="submit" name="action" value="ntfy-retry" class="button button--ghost">Zkusit odeslat znovu</button></form><?php endif; ?>
</article><?php endforeach; ?></section>
