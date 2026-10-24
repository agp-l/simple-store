(() => {
  document.querySelectorAll('[data-numbering-form]').forEach(form => {
    const pattern = form.elements.pattern;
    const builder = form.querySelector('[data-number-builder]');
    const prefix = form.querySelector('[data-number-prefix]');
    const year = form.querySelector('[data-number-year]');
    const digits = form.querySelector('[data-number-digits]');
    const output = form.querySelector('[data-number-preview]');
    const restart = form.querySelector('[data-number-restart]');
    const preview = () => {
      const value = pattern.value;
      const sequence = Number(form.elements.next_number.value || form.dataset.next || 1);
      const calendarYear = Number(form.elements.counter_year.value || form.elements.year.value);
      const sequences = [...value.matchAll(/\{SEQ:([1-9])\}/g)];
      let result = '';
      if (sequences.length === 1 && /^[A-Z0-9{}:/-]+$/.test(value) && value.length <= 80 &&
          Number.isInteger(sequence) && sequence >= 1 && sequence <= 999999999 &&
          Number.isInteger(calendarYear) && calendarYear >= 2000 && calendarYear <= 2100) {
        result = value.replaceAll('{YYYY}', String(calendarYear)).replaceAll('{YY}', String(calendarYear).slice(-2))
          .replace(/\{SEQ:([1-9])\}/, (_, width) => String(sequence).padStart(Number(width), '0'));
      }
      output.textContent = /^[A-Z0-9][A-Z0-9/-]{0,39}$/.test(result) ? result : 'Zkontroluj formát, rok a pořadové číslo';
      restart.textContent = /\{YY(?:YY)?\}/.test(value) ? 'Každý rok začíná nové pořadí.' : 'Pořadí pokračuje napříč roky.';
    };
    const parse = () => {
      const match = pattern.value.match(/^(.*)\{SEQ:([1-9])\}$/);
      if (match) {
        const head = match[1].replace(/[-/]$/, '');
        const date = head.match(/\{(YYYY|YY)\}$/);
        prefix.value = (date ? head.slice(0, -date[0].length) : head).replace(/[-/]$/, '');
        year.value = date ? date[1] : '';
        digits.value = match[2];
      }
      preview();
    };
    const build = () => {
      prefix.value = prefix.value.toUpperCase().replace(/[^A-Z0-9/-]/g, '').replace(/[-/]+$/, '');
      pattern.value = [prefix.value, year.value ? `{${year.value}}` : '', `{SEQ:${digits.value || 4}}`].filter(Boolean).join('-');
      preview();
    };
    [prefix, year, digits].forEach(field => field.addEventListener('input', build));
    pattern.addEventListener('input', parse);
    form.elements.next_number.addEventListener('input', preview);
    form.elements.counter_year.addEventListener('input', preview);
    form.querySelectorAll('[data-number-preset]').forEach(button => button.addEventListener('click', () => {
      const suffix = {invoice: 'F', entry: 'PD', document: 'D'}[form.elements.kind.value];
      prefix.value = `${button.dataset.numberPreset}-${suffix}`;
      year.value = 'YYYY'; digits.value = '4';
      if (!form.elements.name.value) form.elements.name.value = `${{invoice:'Faktury',entry:'Peněžní zápisy',document:'Doklady'}[form.elements.kind.value]} · ${button.textContent}`;
      if (!form.elements.activity.value) form.elements.activity.value = button.textContent;
      build();
    }));
    document.querySelectorAll('a[href="#series-new"]').forEach(link => link.addEventListener('click', () => {
      document.getElementById('series-new').open = true;
    }));
    builder.hidden = false;
    parse();
  });
})();
