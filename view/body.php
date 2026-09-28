<?php $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
  <main class="wrap" id="produkty">
    <nav class="breadcrumbs" aria-label="Drobečková navigace">
      <a href="<?= $escape($siteRoot . $language) ?>">Úvod</a>
      <?php foreach ($categoryTrail as $i => $crumb): ?>
        <span aria-hidden="true">/</span>
        <?php if ($i === count($categoryTrail) - 1): ?><span><?= $escape($crumb['title']) ?></span>
        <?php else: ?><a href="<?= $escape($siteRoot . $language . '/kategorie-produktu/' . $crumb['path']) ?>"><?= $escape($crumb['title']) ?></a><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($categoryTrail === []): ?><span aria-hidden="true">/</span><span>Vybavení do přírody</span><?php endif; ?>
    </nav>
    <div class="section-heading">
      <div>
        <h2 id="section-title"><?= $escape($currentCategory['title'] ?? 'Objevte vybavení') ?></h2>
        <p id="section-description"><?= $currentCategory === null ? 'Poctivý výběr pro pohodlí na stezce i mimo ni.' : 'Vybavení na každou cestu. Vyberte si z nabídky níže.' ?></p>
      </div><span class="result-count" id="result-count" aria-live="polite">Zobrazeno <?= $showSamples ? '12' : count($products) ?> produktů</span>
    </div>
    <div class="tools">
      <div class="filter-groups">
        <nav class="filters" aria-label="Hlavní kategorie">
          <a class="filter<?= $currentCategory === null ? ' active' : '' ?>" href="<?= $escape($siteRoot . $language) ?>#produkty" <?= $currentCategory === null ? 'aria-current="page"' : '' ?>>Vše</a>
          <?php foreach ($primaryMenu as $link): ?><a class="filter<?= $link['active'] ? ' active' : '' ?>" href="<?= $escape($link['href']) ?>#produkty" <?= $link['active'] ? 'aria-current="page"' : '' ?>><?= $escape($link['label']) ?></a><?php endforeach; ?>
        </nav>
        <?php if ($categoryMenuRoot !== null && $categoryMenu !== []): ?>
          <nav class="backpack-filters" aria-label="Podkategorie <?= $escape($categoryMenuRoot['title']) ?>">
            <a class="filter<?= $currentCategory['path'] === $categoryMenuRoot['path'] ? ' active' : '' ?>" href="<?= $escape($siteRoot . $language . '/kategorie-produktu/' . $categoryMenuRoot['path']) ?>#produkty">Všechny <?= $escape($categoryMenuRoot['title']) ?></a>
            <?php foreach ($categoryMenu as $link): ?><a class="filter<?= $link['active'] ? ' active' : '' ?>" href="<?= $escape($link['href']) ?>#produkty" <?= $link['active'] ? 'aria-current="page"' : '' ?>><?= $escape($link['label']) ?></a><?php endforeach; ?>
          </nav>
        <?php endif; ?>
      </div><label class="sort-wrap">Řadit podle
        <select id="sort">
          <option value="default">Doporučené</option>
          <option value="price-asc">Od nejlevnějšího</option>
          <option value="price-desc">Od nejdražšího</option>
          <option value="name">Podle názvu</option>
        </select></label>
    </div>
    <section class="catalog" id="catalog" aria-label="Nabídka produktů">
      <?php if ($products !== []): ?>
        <?php foreach ($products as $index => $product): require __DIR__ . '/product-card.php'; endforeach; ?>
      <?php elseif ($showSamples): ?>
      <!-- Each product card has its own image; change its src attribute to replace the photo. -->
      <!-- Original photo: https://gramino.cz/wp-content/uploads/2026/03/Topo-Athletic-Terraventure-5-Men-Grey-Clay-02.jpg -->
      <article class="product-card" data-category="boty" data-price="3990" data-name="topo athletic terraventure 5 men's" id="produkt-1">
        <div class="product-media">
          <a href="<?= $siteRoot ?>produkt-topo-terraventure.php" aria-label="Zobrazit Topo Athletic Terraventure 5 Men's">
            <img class="product-image" src="<?= $siteRoot ?>images/topo-karta.webp" alt="Trailová bota Topo Athletic Terraventure 5 v barvě Grey / Clay" decoding="async">
          </a>
          <span class="media-label">Doprava zdarma</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Topo Athletic</p>
          <h3><a href="<?= $siteRoot ?>produkt-topo-terraventure.php">Terraventure 5 Men's</a></h3>
          <p>Trailové boty Topo patří mezi jedny z nejoblíbenějších na dálkových trecích.</p>
          <div class="product-meta"><span class="stock"><span class="stock-dot" aria-hidden="true"></span>Skladem</span></div>
          <div class="product-action"><strong>3 990 Kč</strong><button class="add-button" type="button" data-add="0" aria-label="Přidat Topo Athletic Terraventure 5 Men's do ukázkového košíku"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" /></svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="stany" data-price="3710" data-name="aliaska" id="produkt-2">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/stan.webp" alt="Aliaska" loading="lazy" decoding="async">
          <span class="media-label">Stany</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Rock Empire®</p>
          <h3>Aliaska</h3>
          <div class="product-meta"><span class="stock"><span class="stock-dot" aria-hidden="true"></span>Skladem</span>
          </div>
          <div class="product-action"><strong>3 710 Kč</strong><button class="add-button" type="button" data-add="1"
              aria-label="Přidat Aliaska do ukázkového košíku"><svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="spacaky" data-price="1199" data-name="little star exp"
        id="produkt-3">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/spacak.webp" alt="Little Star EXP" loading="lazy" decoding="async">
          <span class="media-label">Spacáky</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Deuter</p>
          <h3>Little Star EXP</h3>
          <div class="product-meta"><span class="stock"><span class="stock-dot" aria-hidden="true"></span>Skladem</span>
          </div>
          <div class="product-action"><strong>1 199 Kč</strong><button class="add-button" type="button" data-add="2"
              aria-label="Přidat Little Star EXP do ukázkového košíku"><svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="vybaveni" data-price="1199" data-name="křesadlo firestarter box"
        id="produkt-4">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/kresadlo.webp" alt="Křesadlo Firestarter Box" loading="lazy" decoding="async">
          <span class="media-label">Vybavení</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Pinguin</p>
          <h3>Křesadlo Firestarter Box</h3>
          <div class="product-meta"><span class="stock"><span class="stock-dot" aria-hidden="true"></span>Skladem</span>
          </div>
          <div class="product-action"><strong>1 199 Kč</strong><button class="add-button" type="button" data-add="3"
              aria-label="Přidat Křesadlo Firestarter Box do ukázkového košíku"><svg viewBox="0 0 24 24"
                aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="batohy" data-subcategory="do-25" data-price="2315" data-name="ac aera 24" id="produkt-5">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/batoh.webp" alt="AC Aera 24" loading="lazy" decoding="async">
          <span class="media-label">Batohy</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Deuter</p>
          <h3>AC Aera 24</h3>
          <div class="product-meta"><span class="stock stock-wait"><span class="stock-dot" aria-hidden="true"></span>Do
              7 dnů</span></div>
          <div class="product-action"><strong>2 315 Kč</strong><button class="add-button" type="button" data-add="4"
              aria-label="Přidat AC Aera 24 do ukázkového košíku"><svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="stany" data-price="3710" data-name="aliaska" id="produkt-6">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/stan.webp" alt="Aliaska" loading="lazy" decoding="async">
          <span class="media-label">Stany</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Rock Empire®</p>
          <h3>Aliaska</h3>
          <div class="product-meta"><span class="stock"><span class="stock-dot" aria-hidden="true"></span>Skladem</span>
          </div>
          <div class="product-action"><strong>3 710 Kč</strong><button class="add-button" type="button" data-add="5"
              aria-label="Přidat Aliaska do ukázkového košíku"><svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="spacaky" data-price="1199" data-name="little star exp"
        id="produkt-7">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/spacak.webp" alt="Little Star EXP" loading="lazy" decoding="async">
          <span class="media-label">Spacáky</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Deuter</p>
          <h3>Little Star EXP</h3>
          <div class="product-meta"><span class="stock"><span class="stock-dot" aria-hidden="true"></span>Skladem</span>
          </div>
          <div class="product-action"><strong>1 199 Kč</strong><button class="add-button" type="button" data-add="6"
              aria-label="Přidat Little Star EXP do ukázkového košíku"><svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="vybaveni" data-price="1199" data-name="křesadlo firestarter box"
        id="produkt-8">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/kresadlo.webp" alt="Křesadlo Firestarter Box" loading="lazy" decoding="async">
          <span class="media-label">Vybavení</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Pinguin</p>
          <h3>Křesadlo Firestarter Box</h3>
          <div class="product-meta"><span class="stock"><span class="stock-dot" aria-hidden="true"></span>Skladem</span>
          </div>
          <div class="product-action"><strong>1 199 Kč</strong><button class="add-button" type="button" data-add="7"
              aria-label="Přidat Křesadlo Firestarter Box do ukázkového košíku"><svg viewBox="0 0 24 24"
                aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="batohy" data-subcategory="do-25" data-price="2315" data-name="ac aera 24" id="produkt-9">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/batoh.webp" alt="AC Aera 24" loading="lazy" decoding="async">
          <span class="media-label">Batohy</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Deuter</p>
          <h3>AC Aera 24</h3>
          <div class="product-meta"><span class="stock stock-wait"><span class="stock-dot" aria-hidden="true"></span>Do
              7 dnů</span></div>
          <div class="product-action"><strong>2 315 Kč</strong><button class="add-button" type="button" data-add="8"
              aria-label="Přidat AC Aera 24 do ukázkového košíku"><svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="stany" data-price="3710" data-name="aliaska" id="produkt-10">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/stan.webp" alt="Aliaska" loading="lazy" decoding="async">
          <span class="media-label">Stany</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Rock Empire®</p>
          <h3>Aliaska</h3>
          <div class="product-meta"><span class="stock"><span class="stock-dot" aria-hidden="true"></span>Skladem</span>
          </div>
          <div class="product-action"><strong>3 710 Kč</strong><button class="add-button" type="button" data-add="9"
              aria-label="Přidat Aliaska do ukázkového košíku"><svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="spacaky" data-price="1199" data-name="little star exp"
        id="produkt-11">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/spacak.webp" alt="Little Star EXP" loading="lazy" decoding="async">
          <span class="media-label">Spacáky</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Deuter</p>
          <h3>Little Star EXP</h3>
          <div class="product-meta"><span class="stock"><span class="stock-dot" aria-hidden="true"></span>Skladem</span>
          </div>
          <div class="product-action"><strong>1 199 Kč</strong><button class="add-button" type="button" data-add="10"
              aria-label="Přidat Little Star EXP do ukázkového košíku"><svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <article class="product-card" data-category="vybaveni" data-price="1199" data-name="křesadlo firestarter box"
        id="produkt-12">
        <div class="product-media">
          <img class="product-image" src="<?= $siteRoot ?>images/kresadlo.webp" alt="Křesadlo Firestarter Box" loading="lazy" decoding="async">
          <span class="media-label">Vybavení</span>
        </div>
        <div class="product-body">
          <p class="product-brand">Pinguin</p>
          <h3>Křesadlo Firestarter Box</h3>
          <div class="product-meta"><span class="stock"><span class="stock-dot" aria-hidden="true"></span>Skladem</span>
          </div>
          <div class="product-action"><strong>1 199 Kč</strong><button class="add-button" type="button" data-add="11"
              aria-label="Přidat Křesadlo Firestarter Box do ukázkového košíku"><svg viewBox="0 0 24 24"
                aria-hidden="true">
                <path d="M4 5h2l2 10h10l2-7H7M9 19h.01M18 19h.01" />
              </svg><span>Do košíku</span></button></div>
        </div>
      </article>
      <?php endif; ?>
    </section>
    <div class="empty" id="empty" <?= $products !== [] || $showSamples ? 'hidden' : '' ?>>Zatím tu nejsou žádné produkty. Zkuste jinou kategorii.</div>
    <section class="category-panel" id="kategorie" aria-labelledby="category-title">
      <div class="category-panel-copy">
        <h2 id="category-title">Kam dál?</h2>
        <p>Zvolte si směr a vyberte výbavu pro další cestu.</p>
      </div>
      <div class="category-list"><?php foreach ($primaryMenu as $link): ?><a href="<?= $escape($link['href']) ?>#produkty"><?= $escape($link['label']) ?></a><?php endforeach; ?></div>
    </section>
  </main>
