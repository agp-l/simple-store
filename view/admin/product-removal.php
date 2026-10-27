<?php $removalJobs??=[]; ?>
<div class="panel-intro"><div><p class="panel-eyebrow">Produkty / odstranění</p><h1><?= $removalPlan?'Zkontroluj smazání produktu':'Odstraněné produkty a fotografie' ?></h1><p>Produkt zmizí z nabídky i automatických kontrol. Objednávky a doklady zůstávají zachované.</p></div></div>
<?php if ($removalError!==''): ?><p class="panel-error" role="alert"><?= $escape($removalError) ?></p><?php endif; ?>
<?php if ($removalPlan): $p=$removalPlan; ?>
<section class="panel-panel product-removal-plan">
  <h2><?= $escape($p['name']) ?></h2>
  <p><strong>Odstranění je nevratné a platí pro všechny jazykové verze tohoto produktu.</strong> Jestli ho chceš jen dočasně skrýt, vrať se do editoru a použij „Skrýt produkt“.</p>
  <div class="product-removal-grid">
    <section class="product-removal-card is-delete"><h3><?= \SimpleStore\Rendering\Icon::render('delete') ?> Odstraní se</h3><ul>
      <li><?= count($p['current']) ?> jazykových verzí a <?= (int)$p['counts']['shop_product_revisions'] ?> uložených revizí</li>
      <li><?= (int)$p['counts']['shop_product_variants'] ?> variant včetně skladových údajů</li>
      <li><?= (int)($p['counts']['shop_product_supplier_links']+$p['counts']['shop_product_variant_supplier_links']) ?> partnerských odkazů</li>
      <li><?= (int)$p['counts']['shop_supplier_price_jobs'] ?> záznamů kontrol cen; fronty ověřování a alternativní nabídky</li>
      <li>Zařazení na úvodní stránku, doporučení v kategoriích a související produkty</li>
      <li><strong><?= count($p['photos']['delete']) ?> nepotřebných fotografií</strong> z vlastní složky produktu, včetně náhledů</li>
    </ul></section>
    <section class="product-removal-card is-keep"><h3><?= \SimpleStore\Rendering\Icon::render('check') ?> Zůstane zachované</h3><ul>
      <li><?= (int)$p['orders'] ?> objednávek s tímto produktem; jejich položky, platby a průběh vyřízení</li>
      <li>Faktury, účetní a skladová historie, rezervace již přijatých objednávek a zálohy sloučení</li>
      <li><strong><?= count($p['photos']['retain']) ?> fotografií</strong> používaných jinde nebo v historii</li>
      <li>Fotografie převzaté z jiných složek a společné knihovny</li>
      <li>Na dříve zveřejněných adresách pouze informace „Produkt už není v nabídce“</li>
    </ul></section>
  </div>
  <details><summary>Jazykové verze a adresy</summary><ul><?php foreach ($p['current'] as $lang=>$product): ?><li><?= $escape(strtoupper($lang).' · '.$product['name'].' · /'.$lang.'/produkt/'.$product['slug']) ?></li><?php endforeach; ?></ul></details>
  <p>Vybrané fotografie se ještě jednou zkontrolují při mazání. Soubory s novým použitím zůstanou zachované. Smazané fotografie už nebudou dostupné ani ze zkopírovaných odkazů mimo tento obchod.</p>
  <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="delete-product"><input type="hidden" name="phase" value="confirm"><input type="hidden" name="preview_id" value="<?= $escape($removalPreviewId) ?>">
    <label><input type="checkbox" name="confirm" value="1" required> Potvrzuji trvalé smazání produktu ve všech jazycích a jeho nepotřebných fotografií podle tohoto náhledu.</label>
    <div class="product-removal-actions"><button class="panel-button panel-action--danger" type="submit"><?= \SimpleStore\Rendering\Icon::render('delete') ?> Trvale smazat produkt</button><a href="<?= $escape($basePath.$p['language'].'/produkt/'.$p['current'][$p['language']]['slug'].'?edit=1') ?>">Zpět do editoru</a></div>
  </form>
</section>
<?php endif; ?>
<?php if ($removalResult): $r=$removalResult; ?>
<section class="panel-panel"><h2>Produkt je odstraněný z nabídky</h2><p><strong><?= $escape($r['name']) ?></strong> už není v katalogu, exportu produktů ani ve frontě partnerských kontrol.</p>
  <p>Fotografie: <strong><?= (int)$r['counts']['deleted'] ?> smazáno</strong> · <?= (int)$r['counts']['retained'] ?> zachováno kvůli použití · <?= (int)$r['counts']['pending'] ?> čeká na úklid.</p>
  <?php if ($r['counts']['pending']>0): ?><p class="panel-notice">Produkt zůstává odstraněný. U fotografií pokračuj v úklidu; při opakované chybě zkontroluj práva ke složce images/.</p><?php require __DIR__.'/product-removal-retry.php'; ?><?php endif; ?>
  <a class="panel-icon-link" href="<?= $escape($basePath.$site['default_language'].'?manage=1#produkty') ?>">← Správa produktů</a>
</section>
<?php endif; ?>
<?php if (!$removalPlan): ?>
<section class="panel-panel"><h2>Poslední odstraněné produkty</h2><p>Historie úklidu uchovává pouze název a seznam souborů. Nejde o koš pro obnovení produktu.</p>
<?php if ($removalJobs===[]): ?><p>Zatím nebyl tímto nástrojem odstraněn žádný produkt.</p><?php endif; ?>
<?php foreach ($removalJobs as $r): ?><article class="product-removal-card"><h3><?= $escape($r['name']) ?></h3><p><?= (int)$r['counts']['deleted'] ?> fotografií smazáno · <?= (int)$r['counts']['retained'] ?> zachováno · <?= (int)$r['counts']['pending'] ?> čeká</p><?php if ($r['counts']['pending']>0): require __DIR__.'/product-removal-retry.php'; endif; ?></article><?php endforeach; ?>
</section>
<?php endif; ?>
