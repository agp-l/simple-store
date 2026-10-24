<div data-account-password class="users-password">
  <label><?= $account === null ? 'Počáteční heslo' : 'Nové heslo' ?><input type="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
  <div class="users-actions">
    <button class="panel-button" type="button" data-generate-password hidden>Vygenerovat heslo</button>
    <button class="panel-button" type="button" data-show-password hidden>Zobrazit</button>
    <button class="panel-button" type="button" data-copy-password hidden>Kopírovat</button>
  </div>
  <p class="panel-help" role="status" aria-live="polite">Heslo můžeš zadat ručně. Nové heslo předej majiteli účtu; e-mailem se automaticky neposílá.</p>
</div>
