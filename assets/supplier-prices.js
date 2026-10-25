(() => {
  'use strict';
  const config = document.querySelector('[data-supplier-runner]');
  if (!config) return;
  const scope = document.querySelector('#supplier-scope');
  const field = document.querySelector('#supplier-product-field');
  const retry = document.querySelector('#supplier-retry-field');
  const syncScope = () => { if (retry) { retry.hidden = !['review','failed','problems'].includes(scope.value); retry.querySelector('input').disabled = retry.hidden; } field.hidden = scope.value !== 'one'; field.querySelector('select').required = scope.value === 'one'; };
  scope.addEventListener('change', syncScope); syncScope();
  const pricing = document.querySelector('#supplier-pricing-form');
  if (pricing) {
    const preview = () => {
      const value = name => Number(pricing.elements[name].value);
      const step = value('pricing_step');
      for (const item of pricing.querySelectorAll('[data-pricing-example]')) {
        const cost = Number(item.dataset.pricingExample);
        const margin = value(cost < value('pricing_small_limit') ? 'pricing_small_margin' : 'pricing_margin');
        const price = Math.ceil((cost + margin) / step) * step;
        item.querySelector('strong').textContent = step > 0 && margin > 0 && price <= 10000000 ? `${price.toLocaleString('cs-CZ')} Kč` : 'Zkontroluj částky';
      }
    };
    pricing.addEventListener('input', preview);
  }
  const start = document.querySelector('#supplier-run');
  const stop = document.querySelector('#supplier-stop');
  const status = document.querySelector('#supplier-run-status');
  let running = false, timer, steps = 0, generation = 0;
  const halt = message => { running = false; generation++; clearTimeout(timer); start.disabled = false; stop.disabled = true; status.textContent = message; };
  const tick = async current => {
    if (!running || current !== generation) return;
    try {
      const response = await fetch(config.dataset.endpoint, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({csrf:config.dataset.csrf, action:'supplier-price-tick'})});
      if (response.status === 409) { if (running && current === generation) timer = setTimeout(() => tick(current), 15000); return; }
      if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Spojení nebo přihlášení vypršelo. Obnov stránku; fronta zůstává uložená.');
      const result = await response.json();
      if (!running || current !== generation) return;
      if (result.state === 'idle') { halt('Fronta je dokončená. Obnov výsledky pro zobrazení aktuálních cen.'); return; }
      if (result.state === 'paused') { halt('Fronta je pozastavená v nastavení plánování.'); start.disabled = true; return; }
      steps++;
      status.textContent = `Zpracování běží · ${steps} kroků. Další krok za ${result.wait} s. Výsledky zobrazíš odkazem „Obnovit výsledky“.`;
      timer = setTimeout(() => tick(current), Math.max(0.25,result.wait || 15)*1000);
    } catch (error) { if (current === generation) halt(error.message || 'Zpracování se přerušilo. Obnov stránku.'); }
  };
  start.addEventListener('click', () => { running = true; start.disabled = true; stop.disabled = false; status.textContent = 'Načítám další krok…'; tick(++generation); });
  if (config.dataset.autoStart === '1' && !start.disabled) start.click();
  stop.addEventListener('click', () => halt('Zpracování v tomto okně bylo přerušeno. Již odeslaný krok se dokončí; cron může pokračovat.'));
})();
