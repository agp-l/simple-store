<?php
declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$editing = ($form['document_key'] ?? '') !== '';
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Administrace · dobrodruzi</title>
  <link rel="stylesheet" href="<?= $escape($basePath) ?>assets/admin.css">
</head>
<body>
  <header class="admin-header">
    <div class="admin-wrap admin-header-inner">
      <a class="admin-brand" href="<?= $escape($adminUrl) ?>">dobrodruzi<span class="admin-brand-label"> / redakce</span></a>
      <?php if ($screen === 'editor' || $screen === 'products'): ?>
        <div class="admin-header-actions">
          <a href="<?= $escape($basePath) ?>index.php">Zobrazit obchod</a>
          <form method="post" action="<?= $escape($adminUrl) ?>">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <button class="admin-quiet" type="submit" name="action" value="logout">Odhlásit se</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </header>
  <main class="admin-wrap">
    <?php if ($screen === 'setup'): ?>
      <section class="admin-panel admin-centered">
        <p class="admin-eyebrow">První spuštění</p><h1>Vytvoř administrátora</h1>
        <p>V kořeni projektu spusť <code>/opt/lampp/bin/php tools/admin.php</code>. Příkaz jednou vypíše přístupové heslo; ulož si ho.</p>
      </section>
    <?php elseif ($screen === 'forbidden'): ?>
      <section class="admin-panel admin-centered"><h1>Přístup odepřen</h1><p>Obnov stránku a zkus akci znovu.</p></section>
    <?php elseif ($screen === 'error'): ?>
      <section class="admin-panel admin-centered"><h1>Administraci se nepodařilo načíst</h1><p class="admin-error"><?= $escape($error) ?></p></section>
    <?php elseif ($screen === 'login'): ?>
      <section class="admin-panel admin-centered">
        <p class="admin-eyebrow">Redakční systém</p><h1>Přihlášení</h1>
        <?php if ($error !== ''): ?><p class="admin-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
        <form method="post" action="<?= $escape($adminUrl) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
          <label>Uživatelské jméno<input name="username" autocomplete="username" required autofocus></label>
          <label>Heslo<input type="password" name="password" autocomplete="current-password" required></label>
          <button class="admin-button" name="action" value="login">Přihlásit se</button>
        </form>
      </section>
    <?php elseif ($screen === 'products'): ?>
      <?php require __DIR__ . '/products.php'; ?>
    <?php else: ?>
      <div class="admin-intro"><div><p class="admin-eyebrow">Obsah webu</p><h1>Stránky a články</h1>
        <p>Každé uložení vytvoří novou revizi. Starší text najdeš u dokumentu v historii.</p></div>
        <div class="admin-quick"><a href="<?= $escape($adminUrl . '?section=products') ?>">Produkty</a><a href="<?= $escape($adminUrl . '?type=page') ?>">+ Nová stránka</a><a href="<?= $escape($adminUrl . '?type=post') ?>">+ Nový článek</a></div>
      </div>
      <?php if ($notice !== ''): ?><p class="admin-notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
      <?php if ($error !== ''): ?><p class="admin-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
      <div class="admin-grid">
        <section class="admin-panel admin-list" aria-labelledby="admin-list-title">
          <h2 id="admin-list-title">Dokumenty <span><?= count($documents) ?></span></h2>
          <?php if ($documents === []): ?>
            <p class="admin-empty">Zatím tu nic není. Začni novou stránkou nebo článkem.</p>
          <?php endif; ?>
          <?php foreach ($documents as $document): ?>
            <?php $link = $adminUrl . '?' . http_build_query(['key' => $document['document_key'], 'language' => $document['language']]); ?>
            <a class="admin-document" href="<?= $escape($link) ?>">
              <strong><?= $escape($document['title']) ?></strong>
              <span><?= $document['type'] === 'post' ? 'Článek' : 'Stránka' ?> · <?= $escape($document['language']) ?> · <?= $escape($document['slug']) ?></span>
              <small><?= $document['published'] ? 'Publikováno' : 'Koncept' ?> · revize <?= $escape($document['revision_number']) ?></small>
            </a>
          <?php endforeach; ?>
        </section>
        <div class="admin-workspace">
          <section class="admin-panel" aria-labelledby="admin-editor-title">
            <div class="admin-section-heading"><p class="admin-eyebrow"><?= $editing ? 'Úprava dokumentu' : 'Nový dokument' ?></p>
              <h2 id="admin-editor-title"><?= $editing ? $escape($current['title'] ?? $form['title'] ?? '') : ($form['type'] === 'post' ? 'Nový článek' : 'Nová stránka') ?></h2></div>
            <form method="post" action="<?= $escape($adminUrl) ?>" class="admin-editor">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
              <input type="hidden" name="key" value="<?= $escape($form['document_key'] ?? '') ?>">
              <input type="hidden" name="revision" value="<?= $escape($form['revision_number'] ?? '') ?>">
              <div class="admin-fields-two">
                <label>Typ
                  <?php if ($editing): ?>
                    <input value="<?= ($form['type'] ?? '') === 'post' ? 'Článek' : 'Stránka' ?>" disabled><input type="hidden" name="type" value="<?= $escape($form['type']) ?>">
                  <?php else: ?>
                    <select name="type" required>
                      <option value="page" <?= ($form['type'] ?? '') === 'page' ? 'selected' : '' ?>>Stránka</option>
                      <option value="post" <?= ($form['type'] ?? '') === 'post' ? 'selected' : '' ?>>Článek</option>
                    </select>
                  <?php endif; ?>
                </label>
                <label>Jazyk
                  <?php if ($editing): ?>
                    <input value="<?= $escape($language) ?>" disabled><input type="hidden" name="language" value="<?= $escape($language) ?>">
                  <?php else: ?>
                    <select name="language" required>
                      <?php foreach ($site['languages'] as $code): ?>
                        <option value="<?= $escape($code) ?>" <?= ($form['language'] ?? $language) === $code ? 'selected' : '' ?>><?= $escape($code) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php endif; ?>
                </label>
              </div>
              <label>Nadpis<input name="title" maxlength="255" value="<?= $escape($form['title'] ?? '') ?>" required></label>
              <label>Adresa (slug)<input name="slug" maxlength="190" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= $escape($form['slug'] ?? '') ?>" placeholder="o-nas" required><small>Malá písmena bez diakritiky, číslice a spojovníky.</small></label>
              <label>Krátký úvod<textarea name="summary" rows="3"><?= $escape($form['summary'] ?? '') ?></textarea></label>
              <label>Text<textarea name="body" rows="14"><?= $escape($form['body'] ?? '') ?></textarea><small>Prozatím prostý text; na webu se bezpečně zobrazí s odstavci.</small></label>
              <div class="admin-fields-two">
                <label class="admin-checkbox"><input type="checkbox" name="published" value="1" <?= !empty($form['published']) ? 'checked' : '' ?>> Publikovat</label>
                <label class="admin-checkbox"><input type="checkbox" name="visible_in_menu" value="1" <?= !empty($form['visible_in_menu']) ? 'checked' : '' ?>> Zobrazit v menu</label>
              </div>
              <label>Pořadí v menu<input type="number" name="menu_order" min="0" max="65535" value="<?= $escape($form['menu_order'] ?? 0) ?>"></label>
              <button class="admin-button" type="submit">Uložit novou revizi</button>
            </form>
          </section>
          <?php if ($editing && $history !== []): ?>
            <section class="admin-panel admin-history" aria-labelledby="admin-history-title">
              <h2 id="admin-history-title">Historie verzí</h2>
              <p>Starší verzi načti do formuláře; po uložení vznikne nová revize.</p>
              <?php foreach ($history as $item): ?>
                <?php $restoreUrl = $adminUrl . '?' . http_build_query(['key' => $item['document_key'], 'language' => $item['language'], 'restore' => $item['revision_number']]); ?>
                <div class="admin-revision"><div><strong>Revize <?= $escape($item['revision_number']) ?></strong><span><?= $escape($item['saved_at']) ?> · <?= $escape($item['title']) ?></span></div>
                  <?php if ($item['active_document_key'] === null): ?><a href="<?= $escape($restoreUrl) ?>">Načíst verzi</a><?php else: ?><small>Aktuální</small><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </section>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
