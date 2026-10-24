(() => {
  'use strict';
  const showChoice = (formSelector, field, groups) => {
    document.querySelectorAll(formSelector).forEach(form => {
      const select = form.elements[field];
      if (!select) return;
      const update = () => form.querySelectorAll(`[${groups}]`).forEach(group => { group.hidden = group.getAttribute(groups) !== select.value; });
      select.addEventListener('change', update); update();
    });
  };
  showChoice('[data-menu-source]', 'source', 'data-source-fields');
  showChoice('[data-menu-destination]', 'destination_type', 'data-destination-fields');
  const form = document.querySelector('[data-category-order]');
  if (!form) return;
  const section = form.closest('.structure-catalog');
  const root = form.querySelector('[data-category-group=""]');
  const status = form.querySelector('[data-tree-status]');
  const nodes = [...root.querySelectorAll('[data-category-node]')];
  const row = node => node.querySelector(':scope > [data-category-row]');
  const childGroup = node => node.querySelector(':scope > [data-category-group]');
  let dirty = false, submitting = false, filtering = false, drag = null;
  const mark = message => {
    dirty = true; form.querySelector('.structure-order-save').classList.add('is-dirty');
    status.textContent = message || 'Pořadí je upravené. Změny potvrď tlačítkem Uložit pořadí.';
  };
  const siblings = node => [...node.parentElement.children];
  const refreshButtons = () => nodes.forEach(node => {
    row(node).querySelector('[data-tree-up]').disabled = filtering || !node.previousElementSibling;
    row(node).querySelector('[data-tree-down]').disabled = filtering || !node.nextElementSibling;
    row(node).querySelector('[data-tree-drag]').disabled = filtering;
  });
  const renumber = node => {
    siblings(node).forEach((sibling,index) => { row(sibling).querySelector('[data-tree-order]').value = (index + 1) * 10; });
    refreshButtons(); mark(`Nové pořadí: ${node.dataset.categoryName}. Ulož změny tlačítkem Uložit pořadí.`);
  };
  section.querySelectorAll('[data-tree-tools], [data-tree-drag], [data-tree-toggle], .structure-arrows').forEach(el => { el.hidden = false; });
  form.addEventListener('input', event => { if (event.target.matches('[data-tree-order]')) mark(); });
  form.addEventListener('submit', () => { submitting = true; });
  window.addEventListener('beforeunload', event => { if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; } });
  const expand = (node, open) => {
    const group = childGroup(node), toggle = row(node).querySelector('[data-tree-toggle]');
    if (group && toggle) { group.hidden = !open; toggle.setAttribute('aria-expanded', String(open)); }
  };
  form.addEventListener('click', event => {
    const button = event.target.closest('button'), node = button?.closest('[data-category-node]');
    if (!node) return;
    if (button.matches('[data-tree-toggle]')) expand(node, button.getAttribute('aria-expanded') !== 'true');
    if (button.matches('[data-tree-up]') && !filtering && node.previousElementSibling) { node.parentElement.insertBefore(node, node.previousElementSibling); renumber(node); button.focus(); }
    if (button.matches('[data-tree-down]') && !filtering && node.nextElementSibling) { node.parentElement.insertBefore(node.nextElementSibling, node); renumber(node); button.focus(); }
  });
  section.querySelector('[data-tree-expand]').addEventListener('click', () => nodes.forEach(node => expand(node, true)));
  section.querySelector('[data-tree-collapse]').addEventListener('click', () => { if (!filtering) nodes.forEach(node => expand(node, false)); });
  const normalize = value => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
  const search = section.querySelector('[data-tree-search]');
  const openBeforeSearch = new Map();
  search.addEventListener('input', () => {
    const query = normalize(search.value.trim());
    if (query && !filtering) nodes.forEach(node => openBeforeSearch.set(node, !childGroup(node)?.hidden));
    filtering = Boolean(query);
    const matches = new Set();
    nodes.forEach(node => {
      if (!query || normalize(`${node.dataset.categoryName} ${node.dataset.categoryNode}`).includes(query)) {
        let ancestor = node;
        while (ancestor?.matches('[data-category-node]')) { matches.add(ancestor); ancestor = ancestor.parentElement.closest('[data-category-node]'); }
        node.querySelectorAll('[data-category-node]').forEach(child => matches.add(child));
      }
    });
    nodes.forEach(node => { node.hidden = !matches.has(node); if (filtering) expand(node, true); else if (openBeforeSearch.has(node)) expand(node, openBeforeSearch.get(node)); });
    refreshButtons();
    status.textContent = filtering ? `Nalezeno ${matches.size} kategorií včetně jejich větví. Pro přesouvání vymaž hledání.` : (dirty ? 'Pořadí je upravené. Nezapomeň ho uložit.' : 'Adresy kategorií a přiřazení produktů se řazením nemění.');
  });
  root.addEventListener('pointerdown', event => {
    const handle = event.target.closest('[data-tree-drag]');
    if (!handle || filtering || event.button !== 0) return;
    const node = handle.closest('[data-category-node]');
    drag = {node, group: node.parentElement, startX: event.clientX, startY: event.clientY, active: false, moved: false, pointerId: event.pointerId, original: siblings(node)};
    event.preventDefault(); handle.focus();
  });
  window.addEventListener('pointermove', event => {
    if (!drag || event.pointerId !== drag.pointerId) return;
    if (!drag.active && Math.hypot(event.clientX-drag.startX,event.clientY-drag.startY)<6) return;
    drag.active = true; drag.node.classList.add('is-dragging'); root.classList.add('is-moving');
    event.preventDefault();
    const target = document.elementFromPoint(event.clientX,event.clientY)?.closest('[data-category-row]')?.parentElement;
    if (target && target !== drag.node && target.parentElement === drag.group) {
      const box = row(target).getBoundingClientRect();
      drag.group.insertBefore(drag.node,event.clientY < box.top+box.height/2 ? target : target.nextElementSibling);
      drag.moved = true;
    } else if (target && target !== drag.node && target.parentElement !== drag.group) {
      status.textContent = 'Přesouvej mezi kategoriemi stejného rodiče. Zařazení do jiné větve se tím nemění.';
    }
    if (event.clientY<80) window.scrollBy(0,-18);
    if (event.clientY>innerHeight-80) window.scrollBy(0,18);
  },{passive:false});
  const finish = event => {
    if (!drag || event.pointerId !== drag.pointerId) return;
    if (event.type === 'pointercancel') drag.original.forEach(node => drag.group.appendChild(node));
    else if (drag.moved) renumber(drag.node);
    drag.node.classList.remove('is-dragging'); root.classList.remove('is-moving'); drag = null; refreshButtons();
  };
  window.addEventListener('pointerup',finish); window.addEventListener('pointercancel',finish);
  refreshButtons();
})();
