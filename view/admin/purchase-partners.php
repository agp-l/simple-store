<?php use SimpleStore\Product\PurchasePartnerRepository as Partners; ?>
<section class="panel-panel">
  <h1>Prodávající pro asistovaný nákup</h1>
  <p>Společný seznam pro výběr ve „Způsobu prodeje“ u produktů. Rozpracované profily můžeš uložit a dokončit později.</p>
  <?php if (!$partnersReady): ?>
    <p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=database') ?>">Nejprve aktualizuj databázi.</a></p>
  <?php else: ?>
  <p><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners#partner-form') ?>">+ Nový prodávající</a></p>
  <form method="get" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>" class="panel-form">
    <input type="hidden" name="section" value="purchase-partners">
    <label>Hledat jméno, IČO, e-mail nebo adresu<input name="q" value="<?= $escape($partnerQuery) ?>"></label>
    <button class="panel-button" type="submit">Hledat</button>
    <?php if ($partnerQuery !== ''): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners') ?>">Zobrazit všechny</a><?php endif; ?>
  </form>
  <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Prodávající</th><th>IČO / kontakt</th><th>Stav</th><th>Produkty</th><th></th></tr></thead><tbody>
  <?php foreach ($partnerRows as $row): $missing = Partners::missing($row); ?>
    <tr><td><?= $escape(Partners::label($row)) ?></td><td><?= $escape($row['ico']) ?><br><?= $escape($row['email']) ?></td>
      <td><?= $missing === [] ? 'Připravený' : 'Rozpracovaný' ?></td><td><?= (int) $row['product_count'] ?></td>
      <td><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners&id=' . (int) $row['id'] . '#partner-form') ?>">Zobrazit / upravit</a></td></tr>
  <?php endforeach; ?>
  <?php if ($partnerRows === []): ?><tr><td colspan="5">Žádní prodávající<?= $partnerQuery !== '' ? ' neodpovídají hledání' : ' zatím nejsou uloženi' ?>.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
<?php if ($partnersReady): ?>
<section class="panel-panel" id="partner-form">
  <h2><?= (int) $partnerForm['id'] > 0 ? 'Upravit prodávajícího' : 'Nový prodávající' ?></h2>
  <?php if (($_GET['saved'] ?? '') === '1' && $partnerError === ''): ?><p role="status" class="panel-notice">Prodávající byl uložen<?= $partnerMissing === [] ? '.' : ' jako rozpracovaný.' ?></p><?php endif; ?>
  <?php if ($partnerError !== ''): ?><p role="alert" class="panel-error"><?= $escape($partnerError) ?></p><?php endif; ?>
  <?php if ($partnerMissing !== []): ?><p>Pro použití při objednávce doplň: <?= $escape(implode(', ', $partnerMissing)) ?>. Rozpracovaný profil lze uložit a vybrat u produktu, ale nákup přes něj bude dostupný až po doplnění.</p><?php endif; ?>
  <?php if ((int) $partnerForm['id'] > 0): ?><p>Změny se projeví u všech produktů s tímto prodávajícím. Přijaté objednávky zůstávají beze změny. Pro jinou firmu vytvoř nový profil.</p>
    <p><a target="_blank" rel="noopener" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners&id=' . (int) $partnerForm['id']) ?>">Otevřít uložený profil v nové kartě</a></p>
  <?php endif; ?>
  <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners#partner-form') ?>" class="panel-form" novalidate>
    <input type="hidden" name="action" value="purchase-partner-save"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int) $partnerForm['id'] ?>"><input type="hidden" name="version" value="<?= $escape($partnerForm['version']) ?>">
    <?php $partnerFields = $partnerForm; $partnerPrefix = ''; require __DIR__ . '/../manage/purchase-partner-fields.php'; ?>
    <button class="panel-button panel-action" type="submit">Uložit prodávajícího</button>
    <p class="panel-help">Uložit můžeš i neúplné kontakty. Stav „Připravený“ se nastaví automaticky po doplnění všech údajů a potvrzení spolupráce.</p>
  </form>
</section>
<?php endif; ?>
