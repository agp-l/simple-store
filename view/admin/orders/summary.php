      <section class="panel-panel">
        <div class="panel-panel-head"><h2>Objednávka <?= $escape($order['order_number'] ?? '') ?></h2>
          <div class="panel-order-head-actions">
            <?php if ($orderInvoice !== null): ?><a class="panel-order-document" href="<?= $escape($invoiceDetailUrl . '&print=1') ?>" target="_blank" rel="noopener noreferrer">Faktura <?= $escape($orderInvoice['document_number']) ?> ↗</a><?php endif; ?>
            <span class="panel-order-state <?= $paymentHighlight ? 'is-paid' : 'is-pending' ?>"><?= $escape($paymentDisplayLabel) ?></span>
            <span class="panel-order-state"><?= $escape($orderFulfillmentLabel($order['status'] ?? '')) ?></span>
          </div></div>
        <p class="panel-help">Přijato <?= $escape($order['created_at'] ?? '') ?> · <?= $escape($shipping['label'] ?? $shipping['method'] ?? 'Doprava neuvedena') ?></p>
        <h3 class="panel-order-lines-title">Objednané zboží</h3>
        <div class="panel-order-lines">
          <?php foreach (($order['items'] ?? []) as $item): ?>
            <?php if (!is_array($item)): continue; endif; ?>
            <?php
            $productLanguage = (string) ($item['language'] ?? 'cs');
            $productIdentity = (string) ($item['product_key'] ?? '') . ':' . $productLanguage;
            $productUrl = $orderProductLinks[$productIdentity] ?? '';
            $imagePath = (string) ($item['image_path'] ?? '');
            $thumbnailPath = \SimpleStore\Media\MediaPath::variant($imagePath, 'thumb');
            $imageUrl = str_starts_with($thumbnailPath, 'images/') ? $basePath . $thumbnailPath :
                (preg_match('~^https?://~iD', $thumbnailPath) === 1 &&
                filter_var($thumbnailPath, FILTER_VALIDATE_URL) ? $thumbnailPath : '');
            ?>
            <div class="panel-order-line">
              <?php if ($productUrl !== ''): ?><a class="panel-order-product-image" href="<?= $escape($productUrl) ?>" aria-label="Otevřít produkt <?= $escape($item['name'] ?? '') ?>">
                <?php if ($imageUrl !== ''): ?><img src="<?= $escape($imageUrl) ?>" alt="" loading="lazy" width="64" height="64"><?php else: ?><span aria-hidden="true">▦</span><?php endif; ?>
              </a><?php elseif ($imageUrl !== ''): ?><span class="panel-order-product-image"><img src="<?= $escape($imageUrl) ?>" alt="" loading="lazy" width="64" height="64"></span><?php endif; ?>
              <div><?php if ($productUrl !== ''): ?><a class="panel-order-product-name" href="<?= $escape($productUrl) ?>"><?= $escape($item['name'] ?? '') ?></a><?php else: ?><strong><?= $escape($item['name'] ?? '') ?></strong><?php endif; ?>
                <small><?= (int) ($item['quantity'] ?? 0) ?> ks × <?= $orderMoney($item['unit_price_czk'] ?? 0) ?>
                <?php foreach (($item['options'] ?? []) as $option => $value): ?>
                  <?php if (is_scalar($value)): ?> · <?= $escape($option) ?>: <?= $escape($value) ?><?php endif; ?>
                <?php endforeach; ?></small>
                <?php if (!empty($orderSupplierLinks[$item['product_key'] ?? ''])): ?>
                  <div class="panel-order-suppliers"><span>Dodavatelé:</span>
                    <?php foreach ($orderSupplierLinks[$item['product_key']] as $supplier): ?>
                      <a href="<?= $escape($supplier['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= $escape($supplier['url']) ?>"><?= $escape($supplier['label']) ?> ↗</a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <strong><?= $orderMoney((int) ($item['quantity'] ?? 0) * (int) ($item['unit_price_czk'] ?? 0)) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
        <dl class="panel-order-totals">
          <div><dt>Produkty</dt><dd><?= $orderMoney($order['subtotal_czk'] ?? 0) ?></dd></div>
          <div><dt>Doprava</dt><dd><?= $orderMoney($order['shipping_czk'] ?? 0) ?></dd></div>
          <div><dt>Celkem</dt><dd><?= $orderMoney($order['total_czk'] ?? 0) ?></dd></div>
        </dl>
      </section>
