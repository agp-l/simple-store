<?php
declare(strict_types=1);
$ntfyDraft ??= null;
$ntfyValues=$ntfy===null ? null : \SimpleStore\Notification\NtfySettings::formValues($ntfy,$ntfyDraft);
$ntfyEnabled=$ntfy!==null && $ntfy['enabled']===true;
$ntfyAction=\SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=settings&tab=notifications');
$ntfyStates=['pending'=>'Čeká','sending'=>'Odesílá se','sent'=>'Odesláno','failed'=>'Chyba','uncertain'=>'Nepotvrzeno','skipped'=>'Vynecháno'];
?>
<link rel="stylesheet" href="<?= $escape(($basePath??'/').'assets/admin-notifications.css?v='.filemtime(__DIR__.'/../../assets/admin-notifications.css')) ?>">
<div class="ntfy-settings">
<?php if($ntfyNotice!==''): ?><p class="panel-notice" role="status"><?= $escape($ntfyNotice) ?></p><?php endif; ?>
<div class="ntfy-heading"><div><p class="ntfy-eyebrow">Objednávky v telefonu</p><h2>Oznámení přes ntfy</h2><p>Hned víš, kdo objednal, jaké zboží a za kolik.</p></div><span class="ntfy-badge <?= $ntfyEnabled?'ntfy-badge--sent':'' ?>"><?= $ntfy===null?'Nastavení nelze načíst':($ntfyEnabled?'Zapnuto':'Vypnuto') ?></span></div>
<div class="ntfy-columns">
<?php if($ntfyValues!==null): ?>
<form class="panel-form ntfy-card ntfy-form" method="post" action="<?= $ntfyAction ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="notifications"><input type="hidden" name="action" value="ntfy-save">
<div class="ntfy-card-heading"><h3>Nastavení oznámení</h3><p>Změny se projeví po uložení.</p></div>
<?php if($ntfyDraft!==null): ?><p class="panel-error" role="alert">Změny nejsou uložené. Oprav označený problém a zkus to znovu. Stav nahoře odpovídá uloženému nastavení.</p><?php endif; ?>
<label class="panel-check ntfy-enable"><input type="checkbox" name="enabled" value="1" <?= $ntfyValues['enabled']?'checked':'' ?>><span>Zapnout oznámení nových objednávek<small>Po úspěšném uložení objednávky do obchodu.</small></span></label>
<label>Tajná adresa ntfy<input type="url" name="address" value="<?= $escape($ntfyValues['address']) ?>" maxlength="81" autocomplete="off" spellcheck="false" placeholder="https://ntfy.sh/tvoje-tajne-tema" aria-describedby="ntfy-address-help"></label>
<p id="ntfy-address-help" class="ntfy-help">Vlož celou adresu svého tématu na <strong>https://ntfy.sh/</strong>. V telefonu odebírej stejné téma.</p>
<label><span>Přístupový token <span class="ntfy-optional">volitelný</span></span><input type="text" name="token" value="<?= $escape($ntfyValues['token']) ?>" maxlength="512" autocomplete="off" spellcheck="false" aria-describedby="ntfy-token-help"></label>
<p id="ntfy-token-help" class="ntfy-help">Pro chráněné téma potřebuje token právo publikovat. Prázdné pole zachová uložený token.</p>
<label class="panel-check ntfy-clear"><input type="checkbox" name="clear_token" value="1" <?= $ntfyValues['clear_token']?'checked':'' ?>><span>Smazat uložený token <small>Nejdřív vyprázdni pole výše.</small></span></label>
<label>Priorita oznámení<select name="priority"><?php foreach([1=>'Minimální',2=>'Nízká',3=>'Běžná',4=>'Vysoká',5=>'Naléhavá'] as $value=>$label): ?><option value="<?= $value ?>" <?= $ntfyValues['priority']===(string)$value?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
<label class="panel-check"><input type="checkbox" name="include_contact" value="1" <?= $ntfyValues['include_contact']?'checked':'' ?>><span>Přidat e-mail a telefon zákazníka<small>Jméno příjemce a přehled zboží jsou součástí každé zprávy.</small></span></label>
<div class="ntfy-form-footer"><button class="button" type="submit">Uložit oznámení</button><span>Uloží pouze nastavení ntfy.</span></div>
</form>
<?php endif; ?>
<aside class="ntfy-aside" aria-label="Test a návod ntfy">
<section class="ntfy-card ntfy-test"><div class="ntfy-card-heading"><h3>Ověřit připojení</h3><p>Pošli si nezávaznou testovací zprávu.</p></div>
<p>Test používá <strong>naposledy uloženou adresu a token</strong>. Funguje i při vypnutém modulu a nevytváří objednávku.</p>
<?php if($ntfy!==null): ?><form method="post" action="<?= $ntfyAction ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="notifications"><button type="submit" name="action" value="ntfy-test" class="button button--ghost" <?= $ntfy['topic']===''?'disabled':'' ?>>Poslat testovací zprávu</button></form><?php endif; ?>
<?php if($ntfy!==null && $ntfy['topic']===''): ?><p class="ntfy-help">Nejdřív vyplň adresu a ulož nastavení.</p><?php endif; ?></section>
<section class="ntfy-card"><h3>Co uvidíš v telefonu</h3><ul class="ntfy-content-list"><li>Číslo objednávky, čas a zákazníka</li><li>Zboží, varianty a počty kusů</li><li>Celkovou cenu včetně dopravy</li><li>Stav platby a odkaz do administrace</li></ul><p class="ntfy-help">Nová objednávka ještě neznamená přijatou platbu. Dlouhý seznam zboží se zkrátí; celý je v detailu objednávky.</p></section>
<details class="ntfy-card ntfy-guide"><summary>Jak nastavit telefon</summary><ol><li>Nainstaluj aplikaci ntfy a povol oznámení.</li><li>Přidej odběr svého tématu na serveru <strong>https://ntfy.sh</strong>. Pro chráněné téma se přihlas účtem s právem číst.</li><li>Sem vlož celou adresu tématu a případný token. Klikni na <strong>Uložit oznámení</strong>.</li><li>Pošli test a ověř doručení v telefonu.</li><li>Zapni oznámení nových objednávek a znovu ulož.</li></ol></details>
<p class="ntfy-privacy">Adresa sama není heslo: nechráněné téma může číst každý, kdo zná jeho název. Pro údaje zákazníků použij soukromé téma s řízeným přístupem.</p>
</aside></div>
<section class="ntfy-card ntfy-history"><div class="ntfy-card-heading"><h3>Historie doručení</h3><p>Posledních 30 oznámení. Odesláno znamená přijetí serverem ntfy, nikoli potvrzení z telefonu.</p></div>
<?php if($ntfyRows===[]): ?><div class="ntfy-empty"><strong>Zatím žádná oznámení</strong><p>Po zapnutí zde uvidíš výsledky odesílání nových objednávek. Testovací zprávy se do historie objednávek neukládají.</p></div><?php endif; ?>
<?php foreach($ntfyRows as $row): $state=(string)$row['state']; ?>
<article class="ntfy-delivery"><div class="ntfy-delivery-heading"><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=orders&id='.(int)$row['order_id']) ?>"><?= $escape($row['order_number']) ?></a><span class="ntfy-badge ntfy-badge--<?= $escape(array_key_exists($state,$ntfyStates)?$state:'unknown') ?>"><?= $escape($ntfyStates[$state]??$state) ?></span></div>
<p class="ntfy-delivery-meta">Vytvořeno <?= $escape(\SimpleStore\Rendering\LocalDateTime::czech($row['created_at'])) ?> <span>· Pokusy: <?= (int)$row['attempts'] ?></span><?php if(!empty($row['sent_at'])): ?> <span>· Odesláno <?= $escape(\SimpleStore\Rendering\LocalDateTime::czech($row['sent_at'])) ?></span><?php elseif(!empty($row['attempted_at'])): ?> <span>· Poslední pokus <?= $escape(\SimpleStore\Rendering\LocalDateTime::czech($row['attempted_at'])) ?></span><?php endif; ?></p>
<?php if($row['last_error']!==''): ?><p class="ntfy-delivery-error"><?= $escape($row['last_error']) ?></p><?php endif; ?>
<?php if($state==='failed' && (int)$row['attempts']>=5): ?><p class="ntfy-help">Automatické pokusy jsou vyčerpané. Oprav příčinu a případně opakuj ručně.</p><?php endif; ?>
<?php if(in_array($state,['pending','failed','uncertain'],true)): ?>
<?php if($ntfyEnabled): ?><details class="ntfy-retry"><summary>Opakovat odeslání</summary><form method="post" action="<?= $ntfyAction ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="notifications"><input type="hidden" name="order_id" value="<?= (int)$row['order_id'] ?>"><label class="panel-check"><input type="checkbox" name="confirm_retry" value="1" required><span>Zkontroloval jsem telefon. Opakování může vytvořit duplicitní zprávu.</span></label><button type="submit" name="action" value="ntfy-retry" class="button button--ghost">Zkusit odeslat znovu</button></form></details><?php else: ?><p class="ntfy-help">Odesílání je pozastavené. Pro opakování nejdřív zapni a ulož oznámení.</p><?php endif; ?>
<?php endif; ?></article><?php endforeach; ?>
<details class="ntfy-guide ntfy-delivery-guide"><summary>Jak funguje doručování a opakování</summary><p>Nová objednávka je nejdřív uložena. Výpadek ntfy nezablokuje nákup a staré objednávky se zpětně nerozesílají.</p><p>Čekající a odmítnuté zprávy zpracuje stávající e-mailový cron nebo <code>tools/mail-worker.php</code>: nejvýše 5 pokusů s odstupem alespoň 15 minut. Týdenní kontrola cen je jiný cron. Bez e-mailového cronu použij ruční opakování.</p><p>Nepotvrzená zpráva již mohla dorazit, proto ji automaticky neopakujeme. Nejdřív zkontroluj telefon. Vypnutí pozastaví čekající zprávy; po zapnutí se mohou odeslat. Změna tématu je nepřesměruje jinam.</p></details>
</section></div>
