<?php
use SimpleStore\Navigation\PanelUrl;
use SimpleStore\Rendering\Icon;
$priceUrl=PanelUrl::pretty($adminUrl.'?section=supplier-prices');
$date=static fn($value):string=>(int)$value>0?(new DateTimeImmutable('@'.(int)$value))->setTimezone(new DateTimeZone('Europe/Prague'))->format('j. n. Y H:i'):'—';
$states=['queued'=>'Ve frontě','review'=>'Ke kontrole','failed'=>'Nepodařilo se','applied'=>'Aktualizováno','checked'=>'Cena ověřena','dismissed'=>'Ponecháno'];
$availability=['in_stock'=>'Skladem u dodavatele','out_of_stock'=>'U dodavatele není skladem','on_order'=>'Na objednávku / předprodej','unknown'=>'Dostupnost neuvedena'];
?>
<link rel="stylesheet" href="<?= $escape($basePath.'assets/supplier-prices.css?v='.filemtime(__DIR__.'/../../assets/supplier-prices.css')) ?>">
<div class="supplier-manager">
<section class="panel-panel supplier-intro">
  <div><p class="panel-eyebrow">Produkty / dodavatelé</p><h1>Ceny dodavatelů</h1><p>Přehled cen a dostupnosti, bez každodenního otevírání jednotlivých obchodů.</p></div>
  <span class="supplier-limit"><?= Icon::render('clock') ?> Nejvýše 1 kontrola produktu za 24 hodin</span>
</section>
<?php if(!$pricesReady): ?><section class="panel-panel"><h2>Připravit databázi</h2><p>Pro uložení fronty a historie nejprve <a href="<?= PanelUrl::escape($adminUrl.'?section=database') ?>">aktualizuj SQL tabulky</a>.</p></section>
<?php else: ?>
<?php if($pricesNotice!==''): ?><p class="panel-notice" role="status"><?= $escape($pricesNotice) ?></p><?php endif; ?>
<?php if($pricesError!==''): ?><p class="panel-error" role="alert"><?= $escape($pricesError) ?></p><?php endif; ?>
<div class="supplier-stats">
<?php foreach(['queued'=>'Čeká na načtení','review'=>'Potřebuje kontrolu','applied'=>'Aktualizovaných cen','failed'=>'Neúspěšných kontrol'] as $state=>$label): ?>
<a class="supplier-stat supplier-stat--<?= $state ?>" href="<?= $escape($priceUrl.'?status='.$state.'#price-history') ?>"><strong><?= (int)$pricesDashboard['counts'][$state] ?></strong><span><?= $label ?></span></a>
<?php endforeach; ?>
</div>
<div class="supplier-columns">
<section class="panel-panel supplier-start"><h2><?= Icon::render('refresh') ?> Spustit kontrolu</h2>
<p>Nejdřív použijeme odkazy vybraného prodávajícího. Pak zkusíme ostatní známé obchody podle priority (nižší číslo má přednost).</p>
<form method="post" action="<?= $escape($priceUrl) ?>" class="panel-form" id="supplier-start-form">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="supplier-price-start">
<label>Co zkontrolovat<select name="scope" id="supplier-scope"><option value="missing">Produkty bez dodavatelské ceny</option><option value="all">Všechny produkty s dodavatelským odkazem</option><option value="one">Jeden vybraný produkt</option></select></label>
<label id="supplier-product-field">Konkrétní produkt<select name="product_key"><option value="">Vyber produkt pro jednotlivou kontrolu</option><?php foreach($pricesDashboard['products']as$product): ?><option value="<?= $escape($product['product_key']) ?>"><?= $escape($product['name']) ?></option><?php endforeach; ?></select></label>
<fieldset class="supplier-speeds"><legend>Tempo načítání</legend><label><input type="radio" name="speed" value="normal" checked><span><strong>Šetrné</strong><small>Nejvýše 1 požadavek za minutu na obchod</small></span></label><label><input type="radio" name="speed" value="fast"><span><strong>Zrychlené</strong><small>Nejvýše 1 požadavek za 15 sekund na obchod</small></span></label></fieldset>
<p class="panel-help">Prodlevy obchodu mají vždy přednost. Stejný odkaz znovu nestahujeme po dobu 24 hodin. Zrychlení nezruší denní limit.</p>
<button type="submit" class="panel-button panel-action"><?= Icon::render('plus') ?> Zařadit do fronty</button>
</form>
</section>
<section class="panel-panel supplier-running"><h2><?= Icon::render('clock') ?> Zpracování fronty</h2>
<p id="supplier-run-status" role="status" aria-live="polite"><?= (int)$pricesSettings['paused']?'Fronta je pozastavená.':'Fronta je připravená ke zpracování.' ?></p>
<div class="supplier-actions"><button type="button" class="panel-button" id="supplier-run" <?= (int)$pricesSettings['paused']?'disabled':'' ?>>Zpracovávat v tomto okně</button><button type="button" class="panel-button" id="supplier-stop" disabled>Přerušit v tomto okně</button></div>
<p>Každý krok načte nejvýše jednu stránku. Během práce můžeš toto okno nechat otevřené. Po zavření pokračuje jen nastavený cron; fronta se neztratí.</p>
<p><a href="<?= $escape($priceUrl.'#price-history') ?>">Obnovit výsledky</a> · Poslední krok: <strong><?= $escape($date($pricesSettings['last_tick'])) ?></strong></p>
<div class="supplier-note"><strong>Co se uloží automaticky?</strong><p>Jednoznačná cena vybraného prodávajícího. Záložní obchod, varianty, nedostupné zboží a změny nad 30 % čekají na kontrolu. Prodejní cenu, vlastní sklad a přijaté objednávky neměníme.</p><p>Ceny v haléřích se kvůli formátu cen e-shopu zaokrouhlí nahoru na celé Kč.</p></div>
</section>
</div>
<section class="panel-panel supplier-history" id="price-history"><div class="supplier-heading"><div><h2>Historie a výsledky</h2><p>Časy jsou uvedené v českém místním čase. Dostupnost je informace od dodavatele, ne tvůj sklad.</p></div><a class="panel-icon-link" href="<?= PanelUrl::escape($adminUrl.'?section=purchase-partners') ?>">Prodávající a priority <?= Icon::render('arrow-right') ?></a></div>
<form method="get" action="<?= $escape($priceUrl) ?>" class="supplier-filters"><label>Hledat produkt<input name="q" value="<?= $escape($pricesQuery) ?>" placeholder="Název produktu"></label><label>Výsledek<select name="status"><option value="">Všechny výsledky</option><?php foreach($states as $value=>$label): ?><option value="<?= $value ?>" <?= $pricesFilter===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><button class="panel-button">Zobrazit</button></form>
<?php if($pricesDashboard['rows']===[]): ?><div class="supplier-empty"><?= Icon::render('package') ?><h3>Zatím žádné výsledky</h3><p>Začni jedním produktem. Uvidíš zdroj ceny, dostupnost i případný důvod, proč ji nelze převzít.</p></div><?php endif; ?>
<div class="supplier-results">
<?php foreach($pricesDashboard['rows'] as $job): $data=json_decode($job['data_json'],true);$result=json_decode($job['result_json']??'null',true);$product=$data['snapshot']['products'][0];$editUrl=$basePath.$product['language'].'/produkt/'.rawurlencode($product['slug']).'?edit=1'; ?>
<article class="supplier-result supplier-result--<?= $escape($job['status']) ?>">
<div class="supplier-result-title"><a href="<?= $escape($editUrl) ?>" target="_blank" rel="noopener"><?= $escape($job['product_name']) ?></a><span class="supplier-badge"><?= $escape($states[$job['status']]??$job['status']) ?></span></div>
<div class="supplier-result-body"><div><dl class="supplier-times"><div><dt>Zařazeno</dt><dd><?= $escape($date($job['created_at'])) ?></dd></div><div><dt>Zkontrolováno</dt><dd><?= $escape($date($job['checked_at'])) ?></dd></div><div><dt>Cena aktualizována</dt><dd><?= $escape($date($job['applied_at'])) ?></dd></div></dl><p><?= $escape($job['message']?:'Čeká na volný termín pro obchod.') ?></p>
<?php if($result): ?><p class="supplier-source"><a href="<?= $escape($result['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= Icon::render('external') ?> <?= $escape($result['partner_name']) ?></a> · <?= $escape($availability[$result['availability']]??'') ?></p><?php endif; ?>
<?php if(!empty($data['trace'])): ?><details><summary>Průběh pokusů (<?= count($data['trace']) ?>)</summary><ul><?php foreach($data['trace']as$attempt): ?><li><strong><?= $escape($attempt['seller']) ?></strong> · <?= $escape($date($attempt['time'])) ?><br><?= $escape($attempt['message']) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
</div>
<?php if($result): ?><div class="supplier-price"><span><?= $result['old_price']===null?'Cena nebyla nastavena':number_format((int)$result['old_price'],0,',',' ').' Kč' ?></span><strong><?= number_format($result['price'],0,',',' ') ?> <small>Kč</small></strong><small>Načteno <?= $escape($date($result['observed_at'])) ?></small>
<?php if($job['status']==='review'): ?><form method="post" action="<?= $escape($priceUrl.'#price-history') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
<?php if(!empty($data['snapshot']['variantSettings']['enabled'])): ?><label class="supplier-confirm"><input type="checkbox" name="variants" value="1"> Cena platí pro varianty používající základní dodavatelskou cenu. Vlastní ceny variant zůstanou.</label><?php endif; ?>
<button class="panel-button" name="action" value="supplier-price-apply"><?= $result['fallback']?'Použít cenu i prodávajícího':'Použít cenu' ?></button><button class="supplier-dismiss" name="action" value="supplier-price-dismiss">Ponechat beze změny</button></form><?php endif; ?>
</div><?php endif; ?></div>
</article><?php endforeach; ?>
</div>
<nav class="supplier-pagination" aria-label="Stránky historie"><?php $pages=max(1,(int)ceil($pricesDashboard['total']/30));$current=$pricesDashboard['page'];foreach(['Předchozí'=>$current-1,'Další'=>$current+1]as$label=>$page):if($page<1||$page>$pages)continue; ?><a href="<?= $escape($priceUrl.'?'.http_build_query(['status'=>$pricesFilter,'q'=>$pricesQuery,'page'=>$page]).'#price-history') ?>"><?= $label ?></a><?php endforeach; ?><span>Strana <?= $current ?> / <?= $pages ?> · <?= $pricesDashboard['total'] ?> kontrol</span></nav>
</section>
<section class="panel-panel supplier-cron" id="supplier-cron"><h2>Týdenní automatická kontrola</h2><p>Na hostingu nastav spouštění workeru <strong>každou minutu</strong> (případně každých 5 minut). Worker zařadí produkty jednou za 7 dní a mezi tím po malých krocích zpracovává frontu. Jediné spuštění týdně by celou frontu nezpracovalo.</p>
<form method="post" action="<?= $escape($priceUrl.'#supplier-cron') ?>" class="panel-form"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="supplier-price-settings"><label class="supplier-check"><input type="checkbox" name="weekly" value="1" <?= (int)$pricesSettings['weekly_enabled']?'checked':'' ?>> Jednou týdně zařadit všechny produkty (šetrné tempo)</label><label class="supplier-check"><input type="checkbox" name="paused" value="1" <?= (int)$pricesSettings['paused']?'checked':'' ?>> Pozastavit zpracování fronty včetně cronu</label><button class="panel-button">Uložit plánování</button></form>
<p>Další týdenní zařazení: <strong><?= (int)$pricesSettings['weekly_enabled']?$escape($date($pricesSettings['next_weekly'])):'Vypnuto' ?></strong>. Nastavení zde samo nevytvoří úlohu u hostingu.</p>
<details><summary>Nastavení cronu pro hosting</summary><p>Pro cron s příkazem PHP použij <code>php /cesta/k/eshopu/tools/supplier-price-worker.php</code>. Pro webový cron vytvoř soukromou adresu níže. Nikomu ji veřejně neposílej; její znalost umožňuje spouštět připravenou frontu.</p>
<form method="post" action="<?= $escape($priceUrl.'#supplier-cron') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><button class="panel-button" name="action" value="supplier-price-key"><?= empty($pricesSettings['cron_hash'])?'Vytvořit chráněnou adresu cronu':'Vyměnit klíč cronu' ?></button></form></details>
<?php if($cronKey!==''): ?><label>Soukromá cesta cronu (připoj za doménu e-shopu)<input readonly value="<?= $escape($basePath.'supplier-price-cron.php?key='.$cronKey) ?>" autocomplete="off"></label><?php endif; ?>
</section>
<script src="<?= $escape($basePath.'assets/supplier-prices.js?v='.filemtime(__DIR__.'/../../assets/supplier-prices.js')) ?>" defer data-supplier-runner data-auto-start="<?= !empty($pricesAutoStart)?'1':'0' ?>" data-endpoint="<?= $escape($priceUrl) ?>" data-csrf="<?= $escape($csrf) ?>"></script>
<?php endif; ?></div>
