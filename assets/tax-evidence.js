(() => {
  const rules = {own_receipt: ['income','taxable'], principal_receipt: ['income','nontaxable'], supplier_payment: ['expense','nondeductible'], supplier_return: ['income','nontaxable'], principal_return: ['expense','nondeductible'], own_return: ['expense','income_return'], fee: ['expense','deductible']};
  document.querySelectorAll('[data-ledger-form]').forEach(form => {
    form.elements.purpose.addEventListener('change', () => {
      const rule = rules[form.elements.purpose.value];
      if (rule) [form.elements.direction.value, form.elements.tax_kind.value] = rule;
      if (form.elements.purpose.value === 'transfer') form.elements.tax_kind.value = form.elements.direction.value === 'income' ? 'nontaxable' : 'nondeductible';
    });
    form.elements.direction.addEventListener('change', () => {
      if (form.elements.purpose.value === 'manual') form.elements.tax_kind.value = form.elements.direction.value === 'income' ? 'taxable' : 'deductible';
    });
  });
  document.querySelectorAll('[data-funds-form]').forEach(form => {
    const button = form.querySelector('[data-order-split]');
    button?.addEventListener('click', () => {
      form.elements.own_czk.value = form.elements.movement.value.startsWith('supplier_') ? '0' : button.dataset.own;
      form.elements.principal_czk.value = button.dataset.principal;
    });
    form.elements.movement.addEventListener('change', () => {
      // Changing purpose must not carry an old receipt amount into a refund.
      form.elements.own_czk.value = '0';
      form.elements.principal_czk.value = '0';
    });
  });
})();
