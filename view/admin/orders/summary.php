      <section class="panel-panel">
        <div class="panel-panel-head"><h2>Objednávka <?= $escape($order['order_number'] ?? '') ?></h2>
          <div class="panel-order-head-actions">
            <?php if (($customerOrderUrl = $orderCustomerUrl($order)) !== ''): ?><a class="panel-icon-link panel-order-document" href="<?= \SimpleStore\Navigation\PanelUrl::escape($customerOrderUrl) ?>" target="_blank" rel="noopener noreferrer" title="Otevřít přehled, který vidí zákazník"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit objednávku</a><?php endif; ?>
            <?php if ($orderInvoice !== null): ?><a class="panel-icon-link panel-order-document" href="<?= \SimpleStore\Navigation\PanelUrl::escape($invoiceDetailUrl . '&print=1') ?>" target="_blank" rel="noopener noreferrer"><?= \SimpleStore\Rendering\Icon::render('document') ?> Faktura <?= $escape($orderInvoice['document_number']) ?></a><?php endif; ?>
            <span class="panel-order-state <?= $paymentHighlight ? 'is-paid' : 'is-pending' ?>"><?= $escape($paymentDisplayLabel) ?></span>
            <span class="panel-order-state"><?= $escape($orderFulfillmentLabel($order['status'] ?? '')) ?></span>
          </div></div>
        <p class="panel-help">Přijato <?= $escape(\SimpleStore\Rendering\LocalDateTime::czech($order['created_at'] ?? '')) ?> · <?= $escape($shipping['label'] ?? $shipping['method'] ?? 'Doprava neuvedena') ?></p>
        <h3 class="panel-order-lines-title">Objednané zboží</h3>
        <?php $assistanceConsent = $order['shipping_ordered']['assisted_purchase'] ?? $order['shipping']['assisted_purchase'] ?? null; ?>
        <?php if (is_array($assistanceConsent)): ?><p class="panel-help"><strong>Asistovaný nákup:</strong> <?= $assistanceConsent['early_start'] ? 'Zákazník výslovně požádal o zahájení služby před uplynutím 14 dnů.' : 'Zákazník nepožádal o předčasné zahájení. Nákup u partnera proveď až po uplynutí lhůty pro odstoupení.' ?> Nakupuj jménem zákazníka, doklad musí být vystaven zákazníkovi. Před zahájením ověř odeslání smluvního potvrzení v e-mailech objednávky.</p><?php endif; ?>
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
              <?php if ($productUrl !== ''): ?><a class="panel-order-product-image" href="<?= \SimpleStore\Navigation\PanelUrl::escape($productUrl) ?>" aria-label="Otevřít produkt <?= $escape($item['name'] ?? '') ?>">
                <?php if ($imageUrl !== ''): ?><img src="<?= $escape($imageUrl) ?>" alt="" loading="lazy" width="64" height="64"><?php else: ?><span aria-hidden="true">▦</span><?php endif; ?>
              </a><?php elseif ($imageUrl !== ''): ?><span class="panel-order-product-image"><img src="<?= $escape($imageUrl) ?>" alt="" loading="lazy" width="64" height="64"></span><?php else: ?><span class="panel-order-product-image" aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render('package') ?></span><?php endif; ?>
              <div class="panel-order-item-content"><?php if ($productUrl !== ''): ?><a class="panel-order-product-name" href="<?= \SimpleStore\Navigation\PanelUrl::escape($productUrl) ?>"><?= $escape($item['name'] ?? '') ?></a><?php else: ?><strong class="panel-order-product-name"><?= $escape($item['name'] ?? '') ?></strong><?php endif; ?>
                <p class="panel-order-item-quantity"><strong><?= (int) ($item['quantity'] ?? 0) ?> ks</strong><span><?= $orderMoney($item['unit_price_czk'] ?? 0) ?> / kus</span></p>
                <?php $orderedOptions = array_filter(is_array($item['options'] ?? null) ? $item['options'] : [], 'is_scalar'); ?>
                <?php if ($orderedOptions !== []): ?>
                  <div class="panel-order-variant-box">
                    <p><?= \SimpleStore\Rendering\Icon::render('settings') ?> Objednaná varianta</p>
                    <dl class="panel-order-variants">
                      <?php foreach ($orderedOptions as $option => $value): ?><div><dt><?= $escape($option) ?></dt><dd><?= $escape($value) ?></dd></div><?php endforeach; ?>
                    </dl>
                  </div>
                <?php endif; ?>
                <?php if (!empty($item['variant_sku'])): ?><small class="panel-order-item-sku">SKU: <?= $escape($item['variant_sku']) ?></small><?php endif; ?>
                <?php if (($item['sale_mode'] ?? 'own') === 'assisted'): ?><small><?= $escape(\SimpleStore\Checkout\AssistedPurchase::description($item)) ?></small><?php endif; ?>
                <?php $offerUrl = $item['procurement']['source']['url'] ?? ''; if (is_string($offerUrl) && \SimpleStore\Product\PurchasePartnerRepository::validWebsite($offerUrl)): ?><a href="<?= $escape($offerUrl) ?>" target="_blank" rel="noopener noreferrer">Otevřít vybranou nabídku partnera ↗</a><?php endif; ?>
                <?php if (!empty($orderSupplierLinks[$item['product_key'] ?? ''])): ?>
                  <div class="panel-order-suppliers"><span>Dodavatelé:</span>
                    <?php foreach ($orderSupplierLinks[$item['product_key']] as $supplier): ?>
                      <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($supplier['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= $escape($supplier['url']) ?>"><?= $escape($supplier['label']) ?> ↗</a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
              <div class="panel-order-line-total"><span>Celkem za položku</span><strong><?= $orderMoney((int) ($item['quantity'] ?? 0) * (int) ($item['unit_price_czk'] ?? 0)) ?></strong></div>
            </div>
          <?php endforeach; ?>
        </div>
        <dl class="panel-order-totals">
          <div><dt>Produkty</dt><dd><?= $orderMoney($order['subtotal_czk'] ?? 0) ?></dd></div>
          <div><dt>Doprava</dt><dd><?= $orderMoney($order['shipping_czk'] ?? 0) ?></dd></div>
          <div><dt>Celkem</dt><dd><?= $orderMoney($order['total_czk'] ?? 0) ?></dd></div>
        </dl>
      </section>
