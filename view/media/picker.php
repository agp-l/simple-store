<dialog id="media-picker" class="media-picker" aria-labelledby="media-picker-title">
  <div class="media-picker-head"><div><p class="media-eyebrow">Knihovna fotografií</p><h2 id="media-picker-title">Vybrat fotografii</h2></div>
    <button type="button" class="media-close" data-media-close aria-label="Zavřít správce fotografií">×</button></div>
  <p class="media-note">JPG, PNG nebo WebP · nejvýše 12 souborů po 12 MB a 20 Mpx. Obrázky se převedou na WebP v rozměrech do 1800, 960 a 240 px. Nahrání je rovnou vloží do obsahu.</p>
  <form id="media-upload-form" class="media-upload-form">
    <label>Fotografie z počítače <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></label>
    <button type="submit" class="media-primary">Nahrát a vložit</button>
  </form>
  <form id="media-url-form" class="media-url-form">
    <label>Vložit vlastní odkaz nebo starší soubor v images/
      <input type="text" name="path" placeholder="https://… nebo images/nazev.webp" maxlength="1000" required></label>
    <button type="submit">Použít adresu</button>
  </form>
  <p data-media-dialog-status role="status" aria-live="polite"></p>
  <h3>Už nahrané soubory</h3>
  <div class="media-grid" id="media-dialog-grid"></div>
</dialog>
