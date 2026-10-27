<section class="content-admin-panel" aria-label="Správa blogu"><div class="inline-toolbar">
          <a class="inline-small" href="<?= htmlspecialchars($siteRoot . 'admin/content-import/?type=post&language=' . $language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= \SimpleStore\Rendering\Icon::render('upload') ?> Import JSON článku</a>
          <div><strong><?= \SimpleStore\Rendering\Icon::render('book') ?> Správa blogu</strong><p>Hlavní článek je vždy první a má velkou kartu. Za ním jsou připnuté články, potom ostatní od nejnověji upravených. V kategorii se zobrazí jen články, které do ní patří.</p><p>První místo vybíráš zvlášť od připnutí. Výběrem jiného hlavního článku předchozí první místo nahradíš.</p></div>
          <a class="inline-small" href="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath.'admin/?section=settings&tab=appearance#blog-texts') ?>"><?= \SimpleStore\Rendering\Icon::render('edit') ?> Upravit úvod blogu</a>
          <a class="inline-small" href="<?= htmlspecialchars($siteRoot . $language . '/blog?manage=1', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= \SimpleStore\Rendering\Icon::render('list') ?> Články a koncepty</a>
        </div></section>
