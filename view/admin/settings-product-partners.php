<?php
use SimpleStore\Product\{ProductPartnerAssignment, PurchasePartnerRepository};
$partnerAssignmentReady ??= false;
?>
<section class="panel-panel panel-settings-block partner-assignment" id="product-partner-assignment">
  <h2><?= \SimpleStore\Rendering\Icon::render('external') ?> Přiřadit prodávající podle dodavatelů</h2>
  <p>Partnerské odkazy produktů a jednotlivých variant porovnáme se seznamem <strong>Prodávající</strong>. Při více shodách vyhraje nejnižší číslo priority. U variant musí jeden partner pokrýt všechny nabízené varianty.</p>
  <p><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners') ?>">Nastavit weby a priority prodávajících →</a></p>
  <?php if (!$partnerAssignmentReady): ?>
    <p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=database') ?>">Nejprve aktualizuj databázi.</a></p>
  <?php else: ?>
    <?php foreach ($partnerAssignmentPlans as $assignmentScope => $assignmentPlan): ?>
      <?php if ($assignmentScope === 'all'): ?><details class="partner-assignment-replace"><summary>Přepočítat i již vybrané prodávající</summary><p>Tato volba nahradí i ruční výběr, pokud odkazy a priority určují jiného prodávajícího. Bez jednoznačné shody původní prodávající zůstane. Při změně prodávajícího se vymažou jeho dosavadní nákupní ceny, včetně cen variant. Do načtení nebo doplnění nových cen nebude asistovaný nákup těchto produktů dostupný.</p><?php else: ?><h3>Doplnit jen chybějící přiřazení</h3><p>Produkty s vybraným prodávajícím zůstanou zachované. U nově přiřazených se případné staré nákupní ceny vymažou.</p><?php endif; ?>
      <div class="partner-assignment-counts" role="status">
        <span><strong><?= $assignmentPlan['counts']['change'] ?></strong> k přiřazení</span>
        <span><strong><?= $assignmentPlan['counts']['same'] + $assignmentPlan['counts']['kept'] ?></strong> ponechaných</span>
        <span><strong><?= $assignmentPlan['counts']['no_links'] + $assignmentPlan['counts']['no_match'] + $assignmentPlan['counts']['conflict'] + ($assignmentPlan['counts']['incomplete'] ?? 0) ?></strong> k doplnění / kontrole</span>
      </div>
      <?php $assignmentDetails = array_values(array_filter($assignmentPlan['items'], static fn (array $item): bool => !in_array($item['state'], ['same', 'kept'], true))); ?>
      <?php if ($assignmentDetails !== []): ?>
      <details><summary>Zobrazit návrhy a důvody přeskočení (<?= count($assignmentDetails) ?>)</summary>
        <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Produkt</th><th>Výsledek</th></tr></thead><tbody>
        <?php foreach (array_slice($assignmentDetails, 0, 100) as $assignmentItem): $assignmentProduct = $assignmentItem['product']; ?>
          <tr><td><a target="_blank" rel="noopener" href="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . $assignmentProduct['language'] . '/produkt/' . rawurlencode($assignmentProduct['slug']) . '?edit=1#supplier-links') ?>"><?= $escape($assignmentProduct['name']) ?> ↗</a></td>
            <td><?php if ($assignmentItem['state'] === 'change'): ?>
              <strong><?= $escape(PurchasePartnerRepository::label($assignmentItem['partner'])) ?></strong> · priorita <?= (int) $assignmentItem['partner']['priority'] ?>
              <?php if ($assignmentItem['current'] !== null): ?><br>Původně: <?= $escape(PurchasePartnerRepository::label($assignmentItem['current'])) ?><?php endif; ?>
              <br><a target="_blank" rel="noopener noreferrer" href="<?= $escape($assignmentItem['link']['url']) ?>">Dodavatelský odkaz pro shodu ↗</a>
              <?php if (PurchasePartnerRepository::missing($assignmentItem['partner']) !== []): ?><br><small>Rozpracovaný profil – před objednávkou doplň a ověř.</small><?php endif; ?>
            <?php else: ?><?= $escape(['no_links' => 'Chybí aktivní partnerský odkaz.', 'no_match' => 'Žádný platný partner neodpovídá uloženým odkazům.', 'incomplete' => 'Žádný partner nemá odkazy pro všechny nabízené varianty.', 'conflict' => 'Shodná priorita: ' . implode(', ', $assignmentItem['names'] ?? [])][$assignmentItem['state']]) ?><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?php if (count($assignmentDetails) > 100): ?><p>Zobrazeno prvních 100 výsledků. Přiřazení zahrne celý katalog.</p><?php endif; ?>
      </details>
      <?php endif; ?>
      <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=products#product-partner-assignment') ?>">
        <input type="hidden" name="action" value="products-partners-assign"><input type="hidden" name="tab" value="products"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
        <input type="hidden" name="assignment_scope" value="<?= $escape($assignmentScope) ?>"><input type="hidden" name="assignment_token" value="<?= $escape(ProductPartnerAssignment::token($assignmentPlan, $csrf)) ?>">
        <button class="panel-action panel-button" type="submit" <?= $assignmentPlan['counts']['change'] === 0 ? 'disabled' : '' ?>><?= $assignmentScope === 'missing' ? 'Doplnit chybějící prodávající' : 'Přepočítat prodávající podle priorit' ?> (<?= $assignmentPlan['counts']['change'] ?>)</button>
      </form>
      <?php if ($assignmentScope === 'all'): ?></details><?php endif; ?>
    <?php endforeach; ?>
    <details class="partner-assignment-help"><summary>Jak funguje párování</summary>
  <p class="panel-help">Porovnává se přesná doména bez úvodního www; různé poddomény se neslučují. U variant používáme stejný výběr zdrojů jako cenové kontroly: přesný odkaz má přednost před převzatým, odstraněné odkazy se neobnovují a nenabízené varianty se vynechají. Společné odkazy slouží pro jednoduché produkty a varianty, které ještě nemají vlastní odkazy. Při shodné prioritě více firem produkt přeskočíme. Zahrnuté jsou i koncepty a překlady, každý produkt pouze jednou. Lze přiřadit i rozpracovaný profil, který je potřeba před objednávkou dokončit.</p>
    </details>
    <p class="panel-help">Prodejní ceny, sklad, způsob prodeje a přijaté objednávky se nemění. Přiřazení nespouští načítání cen ani nepotvrzuje spolupráci s prodávajícím. Novému prodávajícímu nepřebíráme nákupní ceny starého.</p>
    <div class="partner-assignment-links">
      <a href="<?= $escape($basePath . $site['default_language'] . '?manage=1&supplier_state=no_partner#produkty') ?>">Produkty bez prodávajícího →</a>
      <a href="<?= $escape($basePath . $site['default_language'] . '?manage=1&supplier_state=no_links#produkty') ?>">Produkty bez dodavatelského odkazu →</a>
      <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=supplier-prices') ?>">Načtení cen a kontrola partnerských odkazů →</a>
    </div>
  <?php endif; ?>
</section>
