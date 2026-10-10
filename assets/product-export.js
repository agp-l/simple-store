(() => {
  'use strict';
  const form = document.getElementById('product-export-form');
  if (!form) return;
  const choices = [...form.querySelectorAll('[data-product-choice]')];
  const all = form.querySelector('[data-select-all]');
  const update = () => {
    const selected = choices.filter(input => input.checked).map(input => input.value);
    form.querySelector('[name="selected_json"]').value = JSON.stringify(selected);
    form.querySelector('[data-selection-count]').textContent = selected.length;
    form.querySelector('[data-export-selected]').disabled = selected.length === 0;
    all.checked = choices.length > 0 && selected.length === choices.length;
    all.indeterminate = selected.length > 0 && selected.length < choices.length;
  };
  all.addEventListener('change', () => { choices.forEach(input => { input.checked = all.checked; }); update(); });
  choices.forEach(input => input.addEventListener('change', update));
  form.addEventListener('submit', update);
  update();
})();
