      <div class="nav-band">
        <nav class="wrap nav-inner" id="main-nav" aria-label="Kategorie obchodu">
          <?php foreach ($primaryMenu as $link): ?>
            <?php if ($manualPrimaryMenu && $link['children'] !== []): ?><?php require __DIR__ . '/nav-dropdown.php'; ?>
            <?php else: ?><a href="<?= htmlspecialchars($link['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $link['active'] ? 'class="active"' : '' ?>><?= htmlspecialchars($link['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a><?php endif; ?>
          <?php endforeach; ?>
        </nav>
      </div>
