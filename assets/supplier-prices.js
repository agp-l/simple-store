(() => {
  'use strict';
  const config = document.querySelector('[data-supplier-runner]');
  if (!config) return;
  const scope = document.querySelector('#supplier-scope');
  const field = document.querySelector('#supplier-product-field');
  const syncScope = () => { field.hidden = scope.value !== 'one'; field.querySelector('select').required = scope.value === 'one'; };
  scope.addEventListener('change', syncScope); syncScope();
  const start = document.querySelector('#supplier-run');
  const stop = document.querySelector('#supplier-stop');
  const status = document.querySelector('#supplier-run-status');
  let running = false, timer, steps = 0;
  const halt = message => { running = false; clearTimeout(timer); start.disabled = false; stop.disabled = true; status.textContent = message; };
  const tick = async () => {
    if (!running) return;
    try {
      const response = await fetch(config.dataset.endpoint, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({csrf:config.dataset.csrf, action:'supplier-price-tick'})});
      if (response.status === 409) { if (running) timer = setTimeout(tick, 15000); return; }
      if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Spojení nebo přihlášení vypršelo. Obnov stránku; fronta zůstává uložená.');
      const result = await response.json();
      if (!running) return;
      if (result.state === 'idle') { halt('Fronta je dokončená. Obnov výsledky pro zobrazení aktuálních cen.'); return; }
      if (result.state === 'paused') { halt('Fronta je pozastavená v nastavení plánování.'); start.disabled = true; return; }
      steps++;
      status.textContent = `Zpracování běží · ${steps} kroků. Další krok za ${result.wait} s. Výsledky zobrazíš odkazem „Obnovit výsledky“.`;
      timer = setTimeout(tick, Math.max(2,result.wait || 15)*1000);
    } catch (error) { halt(error.message || 'Zpracování se přerušilo. Obnov stránku.'); }
  };
  start.addEventListener('click', () => { running = true; start.disabled = true; stop.disabled = false; status.textContent = 'Načítám další krok…'; tick(); });
  if (config.dataset.autoStart === '1' && !start.disabled) start.click();
  stop.addEventListener('click', () => halt('Zpracování v tomto okně bylo přerušeno. Již odeslaný krok se dokončí; cron může pokračovat.'));
})();
