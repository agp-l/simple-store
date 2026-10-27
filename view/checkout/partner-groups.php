<?php
// Group the same server-priced snapshots that are signed in the order review.
$partnerGroups=[];
foreach ($checkoutItems as $groupItem) {
    if (($groupItem['sale_mode']??'own')!=='assisted' || ($groupItem['issue']??null)!==null) continue;
    $groupKey=$groupItem['supplier_selection']['partner_key']??$groupItem['seller']['ico'];
    $partnerGroups[$groupKey]['name']=$groupItem['seller']['name'];
    $partnerGroups[$groupKey]['items'][]=$groupItem;
}
?>
<?php if ($partnerGroups!==[]): ?>
<section class="checkout-partners" aria-labelledby="checkout-partners-title">
  <div class="checkout-partners-heading"><span class="checkout-partners-icon" aria-hidden="true">↗</span><div><span class="checkout-eyebrow">Asistovaný nákup</span><h2 id="checkout-partners-title">Kdo vám zboží dodá</h2></div><span class="checkout-partners-count"><?= count($partnerGroups)===1?'Jeden prodávající':'Počet prodávajících: '.count($partnerGroups) ?></span></div>
  <p>Společného prodávajícího vybíráme podle dostupných nabídek, abychom omezili rozdělení nákupu. Celková cena zboží v košíku se tím nezvyšuje.</p>
  <div class="checkout-partner-groups">
    <?php foreach ($partnerGroups as $group): ?>
    <div class="checkout-partner-group"><h3><?= $checkoutEscape($group['name']) ?></h3><ul>
      <?php foreach ($group['items'] as $groupItem): ?><li><span><?= $checkoutEscape($groupItem['name']) ?><?php if (($groupItem['options']??[])!==[]): ?><small><?= $checkoutEscape(implode(' · ',array_values($groupItem['options']))) ?></small><?php endif; ?></span><strong><?= (int)$groupItem['quantity'] ?> ks</strong></li><?php endforeach; ?>
    </ul></div>
    <?php endforeach; ?>
  </div>
  <?php if (count($partnerGroups)>1): ?><p class="checkout-partners-note">Položky od různých prodávajících mohou dorazit samostatně. Dopravu uvidíte společně v dalším kroku.</p><?php endif; ?>
</section>
<?php endif; ?>
