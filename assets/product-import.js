(() => {
  'use strict';
  const form = document.getElementById('zip-step-form');
  if (!form) return;
  const button = form.querySelector('button');
  const message = form.querySelector('[data-zip-status]');
  const labels = {pending: 'Čeká', processing: 'Zpracovává se', success: 'Uloženo', error: 'Přeskočeno', interrupted: 'Přerušeno – ověř ručně'};
  let busy = false;
  const render = state => {
    const rows = document.getElementById('zip-result-rows');
    rows.replaceChildren();
    for (const item of state.items) {
      const row = document.createElement('tr');
      for (const text of [item.folder, labels[item.status] || item.status, item.message]) {
        const cell = document.createElement('td');
        cell.textContent = text;
        row.append(cell);
      }
      if (item.saved) {
        const link = document.createElement('a');
        link.href = form.dataset.basePath + encodeURIComponent(item.saved.language) + '/produkt/' + encodeURIComponent(item.saved.slug) + '?edit=1';
        link.textContent = ' Otevřít produkt';
        row.lastChild.append(link);
      }
      rows.append(row);
    }
    const pending = state.items.filter(item => ['pending', 'processing'].includes(item.status)).length;
    document.getElementById('zip-progress').textContent = `Zpracováno ${state.items.length - pending} / ${state.items.length} produktů.`;
    return pending;
  };
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    busy = true;
    button.disabled = true;
    let pending = 1;
    try {
      while (pending) {
        message.textContent = 'Importuji další produkt. Tuto stránku ponech otevřenou…';
        const body = new FormData(form);
        body.set('ajax', '1');
        const response = await fetch(form.action, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}});
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Server nevrátil výsledek. Obnov stránku a zkontroluj průběh; může být potřeba nové přihlášení.');
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.error || 'Import se přerušil. Obnov stránku a pokračuj.');
        pending = render(result.state);
      }
      message.textContent = 'Hotovo. Zkontroluj výsledky a případně oprav přeskočené produkty.';
    } catch (error) {
      message.textContent = `${error.message} Již uložené produkty zůstávají uložené. Po obnovení stránky lze pokračovat.`;
    } finally {
      busy = false;
      button.disabled = pending === 0;
    }
  });
})();
