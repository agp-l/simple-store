  <main class="wrap" id="produkty">
    <nav class="breadcrumbs" aria-label="Drobečková navigace"><a href="#nahoru">Úvod</a><span
        aria-hidden="true">/</span><span id="breadcrumb-category">Vybavení do přírody</span></nav>
    <div class="section-heading">
      <div>
        <h2 id="section-title">Objevte vybavení</h2>
        <p id="section-description">Poctivý výběr pro pohodlí na stezce i mimo ni.</p>
      </div><span class="result-count" id="result-count" aria-live="polite">Zobrazeno 12 produktů</span>
    </div>
    <div class="tools">
      <div class="filter-groups">
      <div class="filters" role="group" aria-label="Filtrovat podle kategorie"><button class="filter active"
          type="button" data-filter="all" aria-pressed="true">Vše</button><button class="filter" type="button"
          data-filter="batohy" aria-pressed="false">Batohy</button><button class="filter" type="button"
          data-filter="stany" aria-pressed="false">Stany</button><button class="filter" type="button"
          data-filter="spacaky" aria-pressed="false">Spacáky</button><button class="filter" type="button"
          data-filter="vybaveni" aria-pressed="false">Vybavení</button><button class="filter" type="button"
          data-filter="obleceni" aria-pressed="false">Oblečení</button><button class="filter" type="button" data-filter="boty" aria-pressed="false">Boty</button></div>
      <div class="backpack-filters" id="backpack-filters" role="group" aria-label="Filtrovat batohy podle objemu" hidden>
        <button class="filter active" type="button" data-subcategory="all" aria-pressed="true">Všechny batohy</button>
        <button class="filter" type="button" data-subcategory="do-25" aria-pressed="false">Batohy do 25 l</button>
        <button class="filter" type="button" data-subcategory="25-50" aria-pressed="false">Batohy 25–50 l</button>
        <button class="filter" type="button" data-subcategory="nad-50" aria-pressed="false">Batohy nad 50 l</button>
        <button class="filter" type="button" data-subcategory="prislusenstvi" aria-pressed="false">Příslušenství k batohům</button>
      </div>
      </div><label class="sort-wrap">Řadit podle
        <select id="sort">
          <option value="default">Doporučené</option>
          <option value="price-asc">Od nejlevnějšího</option>
          <option value="price-desc">Od nejdražšího</option>
          <option value="name">Podle názvu</option>
        </select></label>
    </div>
    <section class="catalog" id="catalog" aria-label="Nabídka produktů">
      <!-- U batohů: data-subcategory="do-25", "25-50", "nad-50" nebo "prislusenstvi". -->
      <!-- Každá karta má vlastní <img src="...">. Fotku lze změnit úpravou atributu src. -->
      <!-- Původní fotografie: https://gramino.cz/wp-content/uploads/2026/03/Topo-Athletic-Terraventure-5-Men-Grey-Clay-02.jpg -->
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
    </section>
    <div class="empty" id="empty" hidden>V této ukázce tu zatím žádné produkty nejsou. Zkuste jiný filtr.</div>
    <section class="category-panel" id="kategorie" aria-labelledby="category-title">
      <div class="category-panel-copy">
        <h2 id="category-title">Kam dál?</h2>
        <p>Zvolte si směr a vyberte výbavu pro další cestu.</p>
      </div>
      <div class="category-list"><a href="<?= $siteRoot ?>index.php?category=batohy#produkty" data-filter="batohy">Batohy</a><a href="#produkty"
          data-filter="stany">Stany</a><a href="<?= $siteRoot ?>index.php?category=spacaky#produkty" data-filter="spacaky">Spacáky</a><a href="#produkty"
          data-filter="vybaveni">Drobné vybavení</a><a href="<?= $siteRoot ?>index.php?category=obleceni#produkty" data-filter="obleceni">Oblečení</a><a href="<?= $siteRoot ?>index.php?category=boty#produkty" data-filter="boty">Boty</a></div>
    </section>
  </main>
