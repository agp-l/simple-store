<?php
$seriesNextNumbers ??= [];
$numberKinds = \SimpleStore\Accounting\DocumentNumbering::KINDS;
$defaultPatterns = ['invoice'=>'F{YYYY}-{SEQ:6}', 'entry'=>'PD{YYYY}-{SEQ:6}', 'document'=>'D{YYYY}-{SEQ:6}'];
?>
<section class="panel-panel numbering-intro">
  <div class="panel-panel-head"><div><p class="panel-eyebrow">Nastavíš jednou, čísla vznikají sama</p><h2>Číselné řady podle e-shopu nebo činnosti</h2></div><a class="panel-button" href="#series-new">+ Nová řada</a></div>
  <p>Každý druh dokladu má jednu výchozí řadu. Faktury z objednávek i automatické zápisy Fio ji použijí bez dalšího vybírání. Pro jinou činnost můžeš založit vlastní řadu a vybrat ji při zadání dokladu.</p>
  <div class="numbering-defaults">
  <?php foreach ($numberKinds as $kind => $label):
    $defaults = array_values(array_filter($documentSeries, static fn(array $row): bool => $row['kind']===$kind && (int)$row['is_default']===1));
    $default = $defaults[0] ?? null;
  ?>
    <div data-number-kind="<?= $kind ?>"><span><?= $escape($label) ?></span><strong><?= $escape($default['name'] ?? 'Automatická základní řada') ?></strong><code><?= $escape(\SimpleStore\Accounting\DocumentNumbering::format($default['pattern'] ?? $defaultPatterns[$kind], $taxYear, 1)) ?></code><small>Ukázka formátu · <?= $escape(($default['activity'] ?? '') ?: 'bez rozlišení činnosti') ?></small></div>
  <?php endforeach; ?>
  </div>
  <p class="panel-help">Používej odlišné zkratky pro faktury, peněžní zápisy a ostatní doklady, např. ESH-F, ESH-PD a ESH-D. Původní doklady se nemění. Číslo objednávky a variabilní symbol mají vlastní číslování.</p>
</section>
<?php
$seriesForms = array_merge($documentSeries, [['id'=>0, 'name'=>'', 'kind'=>'document', 'pattern'=>'D{YYYY}-{SEQ:6}', 'activity'=>'', 'is_default'=>0, 'version'=>1]]);
foreach ($seriesForms as $series):
    $failed = $accountingError!=='' && ($_POST['action']??'')==='evidence-series-save' && (int)($_POST['id']??0)===(int)$series['id'];
    if ($failed) { $series=array_replace($series,$_POST); $series['is_default']=$_POST['is_default']??0; }
    $sf=static fn(string $key): string => is_scalar($series[$key]??null)?(string)$series[$key]:'';
    $new=(int)$series['id']===0;
    $next=$seriesNextNumbers[(int)$series['id']][str_contains($sf('pattern'),'{YY')?$taxYear:0]??1;
    try { $preview=\SimpleStore\Accounting\DocumentNumbering::format($sf('pattern'),$taxYear,$next); }
    catch (\InvalidArgumentException $e) { $preview='Zkontroluj formát čísla'; }
?>
<details class="panel-panel numbering-series" id="<?= $new?'series-new':'series-'.(int)$series['id'] ?>" data-number-kind="<?= $escape($sf('kind')) ?>" <?= $new||$failed?'open':'' ?>>
  <summary><span class="numbering-series-label"><strong><?= $new?'Nová číselná řada':$escape($sf('name')) ?></strong><small><?= $escape($numberKinds[$sf('kind')]??'') ?> · <?= $escape($sf('activity')?:'Bez rozlišení činnosti') ?></small></span><?php if(!$new):?><code><?= $escape($preview) ?></code><span class="numbering-badge"><?= $sf('is_default')==='1'?'Výchozí':'Volitelná' ?></span><?php endif;?></summary>
  <form class="panel-form panel-evidence-form numbering-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($taxUrl.'&tab=numbering&year='.$taxYear) ?>" data-numbering-form data-next="<?= (int)$next ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="evidence-series-save"><input type="hidden" name="tab" value="numbering"><input type="hidden" name="year" value="<?= (int)$taxYear ?>"><input type="hidden" name="id" value="<?= (int)$series['id'] ?>"><input type="hidden" name="version" value="<?= $escape($sf('version')) ?>">
    <label>Název řady<input name="name" value="<?= $escape($sf('name')) ?>" placeholder="Např. Faktury e-shopu" maxlength="120" required></label>
    <label>Druh dokladů<?php if(!$new):?><input type="hidden" name="kind" value="<?= $escape($sf('kind')) ?>"><input value="<?= $escape($numberKinds[$sf('kind')]??'') ?>" readonly><small>Druh existující řady se nemění.</small><?php else:?><select name="kind"><?php foreach($numberKinds as $key=>$label):?><option value="<?= $key ?>" <?= $sf('kind')===$key?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach;?></select><?php endif;?></label>
    <label class="panel-evidence-wide">Činnost / e-shop<input name="activity" value="<?= $escape($sf('activity')) ?>" placeholder="E-shop Dobrodruzi, masáže…" maxlength="120"><small>Přenese se k novému dokladu i do filtru Daňové evidence.</small></label>
    <fieldset class="panel-evidence-wide numbering-builder" data-number-builder hidden><legend>Sestav číslo jednoduše</legend>
      <?php if($new):?><div class="numbering-presets"><span>Začít podle vzoru:</span><button type="button" data-number-preset="ESH">E-shop</button><button type="button" data-number-preset="SLU">Služby</button></div><?php endif;?>
      <div class="numbering-controls"><label>Zkratka<input data-number-prefix maxlength="24" placeholder="ESH-F"></label><label>Rok v čísle<select data-number-year><option value="YYYY">Celý rok · <?= (int)$taxYear ?></option><option value="YY">Zkrácený · <?= substr((string)$taxYear,-2) ?></option><option value="">Bez roku · průběžná řada</option></select></label><label>Počet číslic<select data-number-digits><?php for($digits=1;$digits<=9;$digits++):?><option value="<?= $digits ?>"><?= $digits ?> · <?= str_pad('1',$digits,'0',STR_PAD_LEFT) ?></option><?php endfor;?></select></label></div>
      <p class="panel-help">Změna těchto voleb sestaví formát s pomlčkami. Vlastní formát můžeš upravit níže.</p>
    </fieldset>
    <label class="panel-evidence-wide">Formát čísla<input name="pattern" value="<?= $escape($sf('pattern')) ?>" maxlength="80" spellcheck="false" required><small>{YYYY} = celý rok, {YY} = krátký rok, {SEQ:4} = alespoň 4 číslice. Povolená jsou A–Z, číslice, pomlčka a lomítko.</small></label>
    <div class="panel-evidence-wide numbering-preview"><span>Náhled čísla</span><output data-number-preview aria-live="polite"><?= $escape($preview) ?></output><small data-number-restart><?= str_contains($sf('pattern'),'{YY')?'Každý rok začíná nové pořadí.':'Pořadí pokračuje napříč roky.' ?></small><small>Číslo se rezervuje až při vytvoření dokladu. Již použitá čísla se přeskočí.</small></div>
    <details class="panel-evidence-wide numbering-counter" <?= $sf('next_number')!==''?'open':'' ?>><summary>Pokračovat po přechodu z jiného systému</summary><p class="panel-help">Běžně nech prázdné: systém pokračuje sám. Vyplň pouze tehdy, pokud potřebuješ navázat na dřívější číslování.</p><div class="panel-evidence-subgrid"><label>Další pořadové číslo<input name="next_number" inputmode="numeric" min="1" max="999999999" type="number" placeholder="Automaticky pokračovat" value="<?= $escape($sf('next_number')) ?>"></label><label>Pro rok<input name="counter_year" type="number" min="2000" max="2100" value="<?= $escape($sf('counter_year')?:$taxYear) ?>" required><small>U řady bez roku platí jedno pořadí pro všechny roky.</small></label></div></details>
    <label class="panel-check panel-evidence-wide"><input type="checkbox" name="is_default" value="1" <?= $sf('is_default')==='1'?'checked':'' ?>> Používat automaticky jako výchozí pro tento druh dokladů</label>
    <p class="panel-help panel-evidence-wide">Výchozí může být jen jedna řada daného druhu v tomto e-shopu. Ostatní zůstávají na výběr. U dalších instalací používej jiné zkratky.</p>
    <button class="panel-button" type="submit"><?= $new?'Vytvořit řadu':'Uložit změny řady' ?></button>
  </form>
</details>
<?php endforeach; ?>
<script src="<?= $escape(($basePath ?? '/') . 'assets/evidence-numbering.js') ?>" defer></script>
