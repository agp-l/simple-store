<?php
$deliveryEstimateForm ??= \SimpleStore\Product\DeliveryEstimate::defaults();
$deliveryPreview = $settingsError === '' ? \SimpleStore\Product\DeliveryEstimate::present(array_replace($deliveryEstimateForm, ['enabled' => true])) : null;
?>
<section class="panel-panel panel-settings-block" id="delivery-estimate">
  <h2><?= \SimpleStore\Rendering\Icon::render('truck') ?> Kdy bude zboží u zákazníka</h2>
  <p class="panel-help">Společný odhad doručení pod dostupností v katalogu i detailu. Platí pro všechny produkty skladem, včetně partnerských nabídek. U nedostupného zboží a zboží na objednávku se nezobrazuje.</p>
  <label class="panel-check"><input type="checkbox" name="delivery_estimate[enabled]" value="1" <?= $deliveryEstimateForm['enabled'] ? 'checked' : '' ?>> Zobrazovat odhad doručení zákazníkům</label>
  <label>Způsob zobrazení<select name="delivery_estimate[format]">
    <?php foreach (\SimpleStore\Product\DeliveryEstimate::FORMATS as $value => $label): ?><option value="<?= $escape($value) ?>" <?= $deliveryEstimateForm['format'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?>
  </select></label>
  <div class="panel-delivery-days">
    <label>Nejdříve za (dnů)<input type="number" name="delivery_estimate[min_days]" min="1" max="30" required value="<?= $escape($deliveryEstimateForm['min_days']) ?>"></label>
    <label>Nejpozději za (dnů)<input type="number" name="delivery_estimate[max_days]" min="1" max="30" required value="<?= $escape($deliveryEstimateForm['max_days']) ?>"></label>
  </div>
  <p class="panel-help">Pro jeden termín zadej obě čísla stejně: 1 a 1 pro zítřek, 2 a 2 pro pozítří. Pro rozmezí například 3 a 4. Formulace „do … dnů“ používá nejpozdější termín. Příklady ve výběru se přizpůsobí tvým číslům.</p>
  <label>Jak počítat dny<select name="delivery_estimate[day_type]">
    <option value="business" <?= $deliveryEstimateForm['day_type'] === 'business' ? 'selected' : '' ?>>Pracovní dny — bez víkendů a českých svátků</option>
    <option value="calendar" <?= $deliveryEstimateForm['day_type'] === 'calendar' ? 'selected' : '' ?>>Kalendářní dny — včetně víkendů a svátků</option>
  </select></label>
  <label>Denní uzávěrka objednávek<input type="time" name="delivery_estimate[cutoff]" value="<?= $escape($deliveryEstimateForm['cutoff']) ?>"></label>
  <p class="panel-help">Český čas. Po uzávěrce počítáme od následujícího dne. V pracovním režimu se začátek i doručení posouvají na pracovní den. Prázdná uzávěrka znamená příjem po celý den. „Zítra“ a „Pozítří“ se ukážou jen tehdy, když termín skutečně připadá na tento den.</p>
  <?php if ($deliveryPreview !== null): ?>
    <div class="panel-delivery-preview"><small>Náhled uloženého nastavení pro objednávku nyní<?= !$deliveryEstimateForm['enabled'] ? ' · zobrazení je vypnuté' : '' ?></small><strong><?= $escape($deliveryPreview['text']) ?></strong><span><?= $escape($deliveryPreview['note']) ?></span></div>
  <?php endif; ?>
  <p class="panel-help">Jde o odhad doručení po ČR při objednání a zaplacení nyní, nikoli o den odeslání. Vyber lhůtu, kterou zvládá i tvůj nejpomalejší partner. Změny uložíš společně s cenami tlačítkem níže; náhled se poté přepočítá.</p>
</section>
