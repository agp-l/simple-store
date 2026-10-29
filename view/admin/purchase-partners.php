<?php use SimpleStore\Product\PurchasePartnerRepository as Partners;
$partnerUsage ??= [];
$partnerCatalogUrl = ($basePath ?? '/') . ($site['default_language'] ?? 'cs') . '?manage=1&partner_id=';
?>
<link rel="stylesheet" href="<?= $escape(($basePath ?? '/') . 'assets/purchase-partners.css?v=' . filemtime(__DIR__ . '/../../assets/purchase-partners.css')) ?>">
<section class="panel-panel partner-directory">
  <h1>Prodávající pro asistovaný nákup</h1>
  <?php if (($_GET['deleted'] ?? '') === '1'): ?><p role="status" class="panel-notice">Prodávající byl smazán. Dřívější objednávky a historie cen zůstaly zachované.</p><?php endif; ?>
  <p>Společný seznam pro výběr ve „Způsobu prodeje“ u produktů. Rozpracované profily můžeš uložit a dokončit později.</p>
  <?php if (!$partnersReady): ?>
    <p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=database') ?>">Nejprve aktualizuj databázi.</a></p>
  <?php else: ?>
  <p class="panel-help">Pro přiřazení z dodavatelských odkazů vyplň web. Priorita 1 má přednost před 2; při stejné prioritě více prodávajících rozhodni ručně. Nastavení se použije při spuštění v Nastavení → Produkty.</p>
  <div class="partner-tools"><a class="panel-action" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners#partner-form') ?>"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Nový prodávající</a><a class="panel-icon-link" href="#partner-backup"><?= \SimpleStore\Rendering\Icon::render('download') ?> Import a export JSON</a></div>
  <form method="get" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>" class="panel-form">
    <input type="hidden" name="section" value="purchase-partners">
    <label>Hledat firmu, IČO, e-mail, adresu nebo web<input name="q" value="<?= $escape($partnerQuery) ?>" placeholder="Např. název obchodu nebo jeho doména"></label>
    <button class="panel-button" type="submit">Hledat</button>
    <?php if ($partnerQuery !== ''): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners') ?>">Zobrazit všechny</a><?php endif; ?>
  </form>
  <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Prodávající</th><th>IČO / kontakt</th><th>Priorita</th><th>Stav</th><th>Produkty</th><th></th></tr></thead><tbody>
  <?php foreach ($partnerRows as $row): $missing = Partners::missing($row); ?>
    <tr><td data-label="Prodávající"><strong><?= $escape(Partners::label($row)) ?></strong><?php if (Partners::validWebsite($row['website_url'] ?? '')): ?><a class="partner-website" href="<?= $escape($row['website_url']) ?>" target="_blank" rel="noopener noreferrer"><?= \SimpleStore\Rendering\Icon::render('external') ?> <?= $escape(parse_url($row['website_url'], PHP_URL_HOST)) ?></a><?php else: ?><small>Web zatím není vyplněný</small><?php endif; ?></td><td data-label="IČO / kontakt"><?= $escape($row['ico']) ?><br><?= $escape($row['email']) ?></td>
      <td data-label="Priorita"><strong><?= (int) ($row['priority'] ?? 100) ?></strong></td><td data-label="Stav"><span class="partner-state <?= $missing === [] ? 'is-ready' : 'is-draft' ?>"><?= $missing === [] ? 'Připravený' : 'Rozpracovaný' ?></span></td><td data-label="Produkty"><a href="<?= $escape($partnerCatalogUrl . (int) $row['id'] . '#produkty') ?>">Zobrazit (<?= count($partnerUsage[(int) $row['id']] ?? []) ?>)</a><small>Výběr prodávajícího i partnerské odkazy</small></td>
      <td data-label="Úprava" class="partner-row-actions"><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners&id=' . (int) $row['id'] . '#partner-form') ?>">Zobrazit / upravit</a><a class="partner-delete-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners&id=' . (int) $row['id'] . '#partner-delete') ?>">Smazat</a></td></tr>
  <?php endforeach; ?>
  <?php if ($partnerRows === []): ?><tr><td colspan="6">Žádní prodávající<?= $partnerQuery !== '' ? ' neodpovídají hledání' : ' zatím nejsou uloženi' ?>.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
<?php if ($partnersReady): ?>
<section class="panel-panel partner-editor" id="partner-form">
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
    <label>Priorita při automatickém přiřazení<input type="number" name="priority" min="1" max="9999" step="1" required value="<?= $escape($partnerForm['priority'] ?? 100) ?>"><small>Menší číslo má přednost. Výchozí hodnota je 100.</small></label>
    <fieldset><legend>🚚 Poštovné partnera pro společný košík</legend>
      <p>Interní odhad doručení po ČR za jednu objednávku u tohoto partnera. Nemění cenu dopravy zákazníkovi. Při více způsobech doručení zadej částku, kterou skutečně používáš.</p>
      <?php if ($deliveryCostsReady ?? false): ?>
      <label>Poštovné v Kč<input type="number" name="partner_shipping_czk" min="0" max="9999999" step="1" value="<?= $escape($partnerForm['partner_shipping_czk'] ?? '') ?>"><small>Prázdné = neznámé; 0 = doprava vždy zdarma.</small></label>
      <label>Doprava zdarma od nákupní částky v Kč<input type="number" name="partner_free_from_czk" min="0" max="9999999" step="1" value="<?= $escape($partnerForm['partner_free_from_czk'] ?? '') ?>"><small>Prázdné = bez hranice pro dopravu zdarma.</small></label>
      <?php else: ?><p>Pro zadání poštovného nejprve aktualizuj databázi v administraci.</p><?php endif; ?>
      <p>Košík porovnává nákupní ceny a poštovné. Pokud některé poštovné chybí, upřednostní méně partnerů a nepoužije dražší zboží. Alternativy musí mít čerstvě ověřenou přesnou variantu i dostatečnou dostupnost.</p>
    </fieldset>
    <button class="panel-button panel-action" type="submit">Uložit prodávajícího i poštovné</button>
    <p class="panel-help">Uložit můžeš i neúplné kontakty. Stav „Připravený“ se nastaví automaticky po doplnění všech údajů a potvrzení spolupráce.</p>
  </form>
  <?php if ((int) $partnerForm['id'] > 0): $partnerUsageCount = count($partnerUsage[(int) $partnerForm['id']] ?? []); ?>
  <div class="partner-delete" id="partner-delete">
    <h3>Smazat prodávajícího: <?= $escape(Partners::label($partnerForm)) ?></h3>
    <?php if ($partnerUsageCount > 0): ?>
      <p>Tento partner je stále použitý u <?= $partnerUsageCount ?> produktů. Nejprve změň jejich prodávajícího a odeber jeho odkazy, včetně odkazů u variant. Platí to i pro koncepty a produkty v režimu „Prodáváme my“.</p>
      <p><a href="<?= $escape($partnerCatalogUrl . (int) $partnerForm['id'] . '#produkty') ?>">Zobrazit produkty tohoto partnera (<?= $partnerUsageCount ?>) →</a></p>
    <?php else: ?>
      <p>Profil není použitý u žádného produktu a lze jej smazat. Dřívější objednávky i historie cen zůstanou zachované. Smazání profilu nelze vrátit; předem si můžeš uložit <a href="#partner-backup">export JSON</a>.</p>
    <?php endif; ?>
    <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners#partner-form') ?>" class="partner-delete-form">
      <input type="hidden" name="action" value="purchase-partner-delete"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int) $partnerForm['id'] ?>"><input type="hidden" name="version" value="<?= $escape($partnerForm['version']) ?>">
      <?php if ($partnerUsageCount === 0): ?><label><input type="checkbox" name="confirm_delete" value="1" required> Potvrzuji smazání tohoto profilu prodávajícího.</label><?php endif; ?>
      <button class="panel-button" type="submit" <?= $partnerUsageCount > 0 ? 'disabled' : '' ?>>Smazat prodávajícího</button>
    </form>
  </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/purchase-partner-transfer.php'; ?>
<?php endif; ?>
