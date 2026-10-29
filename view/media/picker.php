<dialog id="media-picker" class="media-picker" aria-labelledby="media-picker-title">
  <div class="media-picker-head"><div><p class="media-eyebrow">Knihovna fotografií</p><h2 id="media-picker-title">Vybrat fotografii</h2></div>
    <button type="button" class="media-close" data-media-close aria-label="Zavřít správce fotografií">×</button></div>
  <p class="media-note">JPG, PNG nebo WebP · každý soubor nejvýše 12 MB a 20 Mpx. Obrázky se zmenší do 1800, 960 a 240 px<?= function_exists('imagewebp') ? ' a uloží jako WebP' : ' a uloží jako JPG nebo PNG' ?>. Nahrání je rovnou vloží do obsahu.<?= function_exists('imagecreatefromwebp') ? '' : ' WebP se před nahráním převede v prohlížeči na JPG nebo PNG.' ?></p>
  <p class="media-note">U produktu se <strong>Odebrat</strong> ihned uloží; soubor zůstane pro další použití. <strong>Smazat soubor</strong> jej trvale odstraní i s miniaturami, pokud už se nikde nepoužívá. Přiřazení k variantám upravíš v tabulce variant.</p>
  <form id="media-upload-form" class="media-upload-form">
    <label>Fotografie z počítače <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" multiple required data-max-files="<?= (int) ini_get('max_file_uploads') ?>" data-webp-server-read="<?= function_exists('imagecreatefromwebp') ? '1' : '0' ?>"></label>
    <button type="submit" class="media-primary">Nahrát a vložit</button>
  </form>
  <p data-media-dialog-status role="status" aria-live="polite"></p>
  <form class="media-library-filter" id="media-library-filter">
    <label>Kde hledat <select name="scope"><option value="owner">Fotografie tohoto obsahu</option><option value="all">Celá knihovna médií</option></select></label>
    <label data-media-folder-label hidden>Složka <select name="folder"><option value="all">Všechny složky</option></select></label>
    <label>Hledat <input type="search" name="q" maxlength="200" placeholder="Název fotografie"></label>
    <button type="submit">Vyhledat</button>
  </form>
  <div class="media-actions"><button type="button" data-media-prev hidden>← Předchozí</button><span data-media-count></span><button type="button" data-media-next hidden>Další →</button></div>
  <div class="media-grid" id="media-dialog-grid"></div>
</dialog>
