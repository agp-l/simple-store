<span class="delivery-estimate" data-variant-delivery <?= $availability !== 'in_stock' ? 'hidden' : '' ?> title="<?= $escape($deliveryEstimate['title']) ?>">
  <?= \SimpleStore\Rendering\Icon::render('truck') ?>
  <span><strong><?= $escape($deliveryEstimate['text']) ?></strong><small><?= $escape($deliveryEstimate['note']) ?></small></span>
</span>
