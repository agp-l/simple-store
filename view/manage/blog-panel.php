<section class="content-admin-panel" aria-label="Správa blogu"><div class="inline-toolbar">
          <a class="inline-small" href="<?= htmlspecialchars($siteRoot . 'admin.php?section=content-import&type=post&language=' . $language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= \SimpleStore\Rendering\Icon::render('upload') ?> Import JSON článku</a>
          <div><strong><?= \SimpleStore\Rendering\Icon::render('book') ?> Správa blogu</strong><p>Články upravíš přímo na jejich stránce. Nový článek vytvoříš nahoře v liště Správa webu.</p></div>
          <a class="inline-small" href="<?= htmlspecialchars($siteRoot . $language . '/blog?manage=1', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= \SimpleStore\Rendering\Icon::render('list') ?> Články a koncepty</a>
        </div></section>
