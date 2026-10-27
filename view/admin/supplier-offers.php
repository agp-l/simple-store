<?php
$offerUrl=$priceUrl.'?tab=offers'; $summary=$offersReport['summary'];
$partnerFilter=is_string($_GET['partner']??null)?$_GET['partner']:'';
$offerStatus=is_string($_GET['offer_status']??null)?$_GET['offer_status']:'';
$offerSearch=is_string($_GET['q']??null)?trim(substr($_GET['q'],0,200)):'';
$statusLabels=['unverified'=>'Čeká na ověření','verified'=>'Přesná nabídka ověřena','expired'=>'Ověření vypršelo','error'=>'Nepotvrzená shoda / chyba'];
$partnerNames=array_column($summary['partners'],'name','partner_key');
$display=[];
foreach ($offersReport['products'] as $product) {
    if ($offerSearch!=='' && preg_match('/'.preg_quote($offerSearch,'/').'/iu',$product['name'])!==1) continue;
    $variants=array_column($product['variants'],'choices','variant_key');
    foreach ($product['offers'] as $offer) {
        if ($partnerFilter!=='' && $offer['partner_key']!==$partnerFilter) continue;
        if ($offerStatus!=='' && $offer['status']!==$offerStatus) continue;
        $display[]=['product'=>$product,'offer'=>$offer,'choices'=>(array)($variants[$offer['variant_key']]??[])];
    }
}
$total=count($display); $pages=max(1,(int)ceil($total/50)); $page=min($pages,max(1,(int)($_GET['page']??1))); $display=array_slice($display,($page-1)*50,50);
?>
<?php if($pricesNotice!==''): ?><p class="panel-notice" role="status"><?= $escape($pricesNotice) ?></p><?php endif; ?>
<?php if($pricesError!==''): ?><p class="panel-error" role="alert"><?= $escape($pricesError) ?></p><?php endif; ?>
<section class="panel-panel supplier-coverage">
<p class="panel-eyebrow">Podklady pro společný nákup u partnera</p><h2>Pokrytí katalogu</h2>
<p><strong><?= (int)$summary['products'] ?> produktů</strong> · <?= (int)$summary['variants'] ?> aktivních variant včetně základních · <strong><?= (int)$summary['multiple_partners'] ?> produktů s více partnery</strong>.</p>
<p>Odkaz je pouze nalezený zdroj. Přesná nabídka musí odpovídat modelu, barvě i velikosti. Čerstvá znamená ověřená v posledních 24 hodinách. Počty ukazují uložené zdroje; obchod bez odkazu může produkt také prodávat.</p>
<div class="supplier-table-scroll"><table class="supplier-offer-table"><thead><tr><th>Partner</th><th>Produkty s odkazem</th><th>Alespoň jedna ověřená varianta</th><th>Ověřeny všechny varianty</th><th>Produkty s dostupnou variantou</th><th>Dostupné / ověřené varianty</th><th>Chyby / vypršelé</th></tr></thead><tbody>
<?php foreach($summary['partners'] as $partner): ?><tr><th><a href="<?= $escape($offerUrl.'&partner='.$partner['partner_key'].'#offer-list') ?>"><?= $escape($partner['name']) ?></a></th><td><?= $partner['linked_products'] ?></td><td><?= $partner['verified_products'] ?></td><td><?= $partner['complete_products'] ?></td><td><strong><?= $partner['available_products'] ?></strong></td><td><?= $partner['available_variants'] ?> / <?= $partner['verified_variants'] ?></td><td><?= $partner['errors'] ?> / <?= $partner['expired'] ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="panel-help">Dostupnost respektuje pravidlo posledního kusu: poslední 1 kus nenabízíme. „Skladem“ bez počtu dovoluje nejvýše 3 kusy v našem obchodě, nepotvrzuje fyzický počet u partnera. Dostupný produkt zde znamená alespoň jednu dostupnou variantu, nikoli všechny barvy a velikosti.</p>
<?php if($summary['overlaps']!==[]): ?><details><summary>Překryvy dvojic partnerů podle uložených odkazů</summary><ul><?php foreach($summary['overlaps'] as $pair): ?><li><?= $escape(($partnerNames[$pair['left']]??'Partner').' + '.($partnerNames[$pair['right']]??'Partner')) ?>: <strong><?= $pair['products'] ?></strong> produktů</li><?php endforeach; ?></ul></details><?php endif; ?>
</section>
<div class="supplier-columns">
<section class="panel-panel"><h2>Ověřit všechny partnery</h2><p>Kontrola projde i alternativy za úspěšným primárním partnerem. Výsledky ukládá samostatně. Prodávající, prodejní cena, sklad a přijaté objednávky se tímto porovnáním nemění.</p>
<form method="post" action="<?= $escape($offerUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><button name="action" value="supplier-offers-start" class="panel-button panel-action">Zařadit chybějící a starší nabídky</button></form>
<p>Ve frontě: <strong><?= (int)$offersReport['queued_products'] ?></strong> produktů. Automatická obnova je denní a používá stejnou cache i prodlevy jako Ceny dodavatelů.</p>
<p id="supplier-run-status" role="status" aria-live="polite">Fronta pokračuje nastaveným cronem nebo v tomto okně.</p>
<div class="supplier-actions"><button type="button" class="panel-button" id="supplier-run" <?= !empty($pricesSettings['paused'])?'disabled':'' ?>>Zpracovávat v tomto okně</button><button type="button" class="panel-button" id="supplier-stop" disabled>Přerušit v tomto okně</button><a href="<?= $escape($offerUrl.'&refresh='.bin2hex(random_bytes(6))) ?>">Obnovit výsledky</a></div>
</section>
<section class="panel-panel"><h2>Hromadně přidat zdroje JSON</h2><p>Nový formát <code>simple-store.partner-sources.v1</code> pouze přidává. Opakovaný import nevytvoří duplicity, vynechané zdroje zůstanou. Ceny a ověření nelze importem podvrhnout.</p>
<div class="supplier-actions"><a class="panel-button" href="<?= $escape($priceUrl.'?format=partner-offers') ?>">Export variant, nabídek a statistiky</a><a class="panel-button" href="<?= $escape($priceUrl.'?format=partner-source-template') ?>">Prázdná šablona katalogu</a></div>
<details><summary>Formát a pravidla pro AI</summary><p>Ke každému produktu použij jeho skutečný <code>product_key</code> a <code>language</code>. Do <code>sources</code> přidej <code>label</code> a HTTPS <code>url</code> existujícího partnera. Bez <code>variant_key</code> jde o stránku modelu, která čeká na ověření všech kombinací. Pro přesnou variantu přidej její <code>variant_key</code> a <code>choices</code> z exportu. Zachovej query i fragment adresy. Nezaměňuj generaci, materiál, pohlaví, délku nebo šířku. Neodvozuj dostupnost z výsledku vyhledávače. Není-li shoda jistá, zdroj nepřidávej.</p>
<pre class="supplier-json-example"><?= $escape('{"format":"simple-store.partner-sources.v1","products":[{"product_key":"KLÍČ_Z_EXPORTU","language":"cs","sources":[{"label":"Partner","url":"https://partner.example/p/model"}]}]}') ?></pre></details>
<form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=product-import&tab=bulk') ?>" class="panel-form" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="product-bulk-prepare"><label>Soubor JSON<input type="file" name="json_file" accept=".json,application/json"></label><label>Nebo vložit JSON<textarea name="bulk_json" rows="5" placeholder="Vlož zdroje k přidání"></textarea></label><button class="panel-button">Připravit náhled přidání</button></form>
</section></div>
<section class="panel-panel" id="offer-list"><h2>Nabídky jednotlivých variant</h2>
<form method="get" action="<?= $escape($priceUrl) ?>" class="supplier-filters"><input type="hidden" name="tab" value="offers"><label>Produkt<input name="q" value="<?= $escape($offerSearch) ?>"></label><label>Partner<select name="partner"><option value="">Všichni partneři</option><?php foreach($summary['partners'] as $partner): ?><option value="<?= $escape($partner['partner_key']) ?>" <?= $partnerFilter===$partner['partner_key']?'selected':'' ?>><?= $escape($partner['name']) ?></option><?php endforeach; ?></select></label><label>Stav<select name="offer_status"><option value="">Všechny nabídky</option><?php foreach($statusLabels as $key=>$label): ?><option value="<?= $key ?>" <?= $offerStatus===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><button class="panel-button">Filtrovat</button></form>
<div class="supplier-table-scroll"><table class="supplier-offer-table"><thead><tr><th>Produkt / varianta</th><th>Partner / odkaz</th><th>Nákupní cena</th><th>Dostupnost / kusy</th><th>Ověření</th></tr></thead><tbody>
<?php foreach($display as $item): $product=$item['product'];$offer=$item['offer']; ?><tr><td><div class="supplier-offer-product"><?php if($product['image_path']!==''): ?><img src="<?= $escape($basePath.ltrim($product['image_path'],'/')) ?>" alt="" loading="lazy"><?php endif; ?><div><a href="<?= $escape($basePath.$product['language'].'/produkt/'.rawurlencode($product['slug']).'?edit=1') ?>"><?= $escape($product['name']) ?></a><small><?= $escape($item['choices']===[]?'Základní varianta':implode(' / ',array_values($item['choices']))) ?></small></div></div></td><td><a href="<?= $escape($offer['resolved_url']?:$offer['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($offer['partner_name']) ?> ↗</a></td><td><?= $offer['amount']===null?'—':$escape(number_format((float)$offer['amount'],2,',',' ').' Kč') ?></td><td><?= $escape($availability[$offer['availability']]??'Dostupnost neuvedena') ?><small><?= $offer['reported_quantity']===null?'Přesný počet neuveden':('U partnera '.$offer['reported_quantity'].' ks') ?></small></td><td><span class="supplier-badge"><?= $escape($statusLabels[$offer['status']]??$offer['status']) ?></span><small>Načteno <?= $escape($date($offer['observed_at'])) ?><br>Kontrola <?= $escape($date($offer['checked_at'])) ?></small><?php if($offer['message']!==''): ?><small><?= $escape($offer['message']) ?></small><?php endif; ?></td></tr><?php endforeach; ?>
<?php if($display===[]): ?><tr><td colspan="5">Pro tento filtr zatím nejsou uložené nabídky.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="supplier-pagination" aria-label="Stránky nabídek"><?php foreach(['Předchozí'=>$page-1,'Další'=>$page+1] as $label=>$target): if($target<1||$target>$pages)continue; ?><a href="<?= $escape($priceUrl.'?'.http_build_query(['tab'=>'offers','partner'=>$partnerFilter,'offer_status'=>$offerStatus,'q'=>$offerSearch,'page'=>$target]).'#offer-list') ?>"><?= $label ?></a><?php endforeach; ?><span>Strana <?= $page ?> / <?= $pages ?> · <?= $total ?> nabídek</span></nav>
</section>
<script src="<?= $escape($basePath.'assets/supplier-prices.js?v='.filemtime(__DIR__.'/../../assets/supplier-prices.js')) ?>" defer data-supplier-runner data-auto-start="<?= !empty($pricesAutoStart)?'1':'0' ?>" data-endpoint="<?= $escape($priceUrl) ?>" data-csrf="<?= $escape($csrf) ?>"></script>
