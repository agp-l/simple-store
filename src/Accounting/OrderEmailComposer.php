<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

/** Customer wording is editable; prices, partners and consents come from the saved order. */
final class OrderEmailComposer
{
    public static function compose(string $event, array $order, array $template,
        array $tracking = [], string $orderUrl = '', string $publicBaseUrl = '',
        array $publishedLegal = [], string $legalLanguage = 'cs', array $configuration = []): array
    {
        $shipping = $event === 'order' ? ($order['shipping_ordered'] ?? $order['shipping'] ?? []) : ($order['shipping'] ?? []);
        if (!is_array($shipping)) $shipping = [];
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $vars = ['order_number' => (string) ($order['order_number'] ?? ''),
            'customer_name' => (string) ($shipping['recipient'] ?? $shipping['name'] ?? ''),
            'carrier' => (string) ($shipping['label'] ?? ''),
            'total' => MailContent::money((int) ($order['total_czk'] ?? 0))] + MailContent::variables($configuration);
        $copy = array_map(static fn (string $value): string => MailContent::fill($value, $vars), MailContent::copy($configuration));
        $configuration['templates'][$event] = $template;
        $late = $event === 'paid' && ($order['status'] ?? '') === 'cancelled';
        $message = MailContent::template($late ? 'late_paid' : $event, $configuration, $vars);
        // Compatibility with former defaults passed directly by older integrations.
        if (($order['fulfillment_source'] ?? '') === 'external' && $event === 'shipped' &&
            $message['message'] === 'Zásilku jsme předali dopravci. Podrobnosti ke sledování najdete níže.') {
            $message['message'] = 'Zásilka byla předána dopravci. Podrobnosti ke sledování najdete níže.';
        }
        $state = $copy['status_' . ($late ? 'late_paid' : $event)] ?? $copy['order'];
        $facts = [[$copy['status'], $state], [$copy['shipping'], $vars['carrier']], [$copy['total'], $vars['total']]];
        $lines = EmailLayout::factLines($facts);
        $html = EmailLayout::facts($facts);
        $assisted = array_filter($items, static fn (mixed $item): bool => is_array($item) && ($item['sale_mode'] ?? '') === 'assisted');
        $showContract = in_array($event, ['order', 'paid'], true);
        if ($assisted !== [] && $showContract && !$late && ($order['status'] ?? '') !== 'cancelled') {
            $lines[] = ''; $lines[] = $copy['partner_heading']; $lines[] = $copy['partner_intro'];
            $html .= EmailLayout::notice($copy['partner_intro']);
        }
        if ($event === 'order') {
            $method = (string) ($order['payment_method'] ?? '');
            if ($method === 'bank_transfer') {
                $bank = is_array($order['payment_details'] ?? null) ? $order['payment_details'] : [];
                $paymentFacts = [[$copy['bank_account'], (string) ($bank['account_display'] ?? '')],
                    [$copy['variable_symbol'], (string) ($order['variable_symbol'] ?? '')],
                    [$copy['due_date'], MailContent::date((string) ($order['payment_due_at'] ?? ''))]];
                $lines = array_merge($lines, ['', $copy['bank_heading'], $copy['bank_intro']], EmailLayout::factLines($paymentFacts));
                $html .= EmailLayout::heading($copy['bank_heading']) . EmailLayout::paragraph($copy['bank_intro']) . EmailLayout::facts($paymentFacts);
            } else {
                $gateway = ['comgate' => 'Comgate', 'gopay' => 'GoPay', 'btcpay' => 'BTCPay Server'][$method] ?? $method;
                $paymentLabel = MailContent::fill($copy['online_payment'], ['gateway' => $gateway]);
                $lines = array_merge($lines, ['', $copy['payment'] . ': ' . $paymentLabel, $copy['online_pending']]);
                $html .= EmailLayout::heading($copy['payment']) . EmailLayout::paragraph($paymentLabel . "\n" . $copy['online_pending']);
            }
        } elseif ($late || $event === 'cancelled') {
            $note = $copy[$late ? 'late_payment' : 'cancelled_payment'];
            $lines[] = ''; $lines[] = $note;
            $html .= EmailLayout::notice($note);
        } elseif ($event === 'paid' || ($order['payment_status'] ?? '') === 'paid') {
            $lines[] = ''; $lines[] = $copy['payment_confirmed'];
            $html .= '<p style="margin:16px 0;color:#254e3b;font-weight:bold">' . EmailLayout::escape($copy['payment_confirmed']) . '</p>';
        }
        $showTracking = in_array($event, ['shipped', 'tracking', 'completed'], true);
        $trackingNumber = trim((string) ($tracking['number'] ?? ''));
        $trackingUrl = EmailLayout::safeUrl((string) ($tracking['url'] ?? ''));
        if ($showTracking && ($event !== 'completed' || $trackingNumber !== '' || $trackingUrl !== '')) {
            $html .= EmailLayout::heading($copy['tracking']);
            if ($trackingNumber !== '') {
                $lines[] = $copy['tracking_number'] . ': ' . $trackingNumber;
                $html .= EmailLayout::paragraph($copy['tracking_number'] . ': ' . $trackingNumber);
            }
            if ($trackingNumber === '' && $trackingUrl === '') {
                $lines[] = $copy['tracking_pending'];
                $html .= EmailLayout::paragraph($copy['tracking_pending']);
            }
        }
        $orderUrl = EmailLayout::safeUrl($orderUrl);
        $links = [[$copy['button_tracking'], $showTracking ? $trackingUrl : ''], [$copy['button_order'], $orderUrl]];
        $html .= EmailLayout::actions($links);
        foreach ($links as [$label, $url]) if ($url !== '') $lines[] = $label . ': ' . $url;
        $lines[] = ''; $lines[] = $copy['items'] . ':';
        $html .= EmailLayout::heading($copy['items']) . self::itemsHtml($items, $copy, false);
        $partners = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $quantity = (int) ($item['quantity'] ?? 0);
            $lines[] = $quantity . ' × ' . (string) ($item['name'] ?? '') . ' — ' . MailContent::money($quantity * (int) ($item['unit_price_czk'] ?? 0));
            foreach (self::options($item) as $option) $lines[] = '  ' . $option;
            if (($item['sale_mode'] ?? '') === 'assisted') {
                $lines[] = self::partnerItem($item, $copy);
                $lines[] = self::partnerPrices($item, $copy);
                $seller = is_array($item['seller'] ?? null) ? $item['seller'] : [];
                // Group only identical snapshots; different delivery agreements remain visible.
                $key = hash('sha256', json_encode([$seller, $item['delivery_note'] ?? ''], JSON_THROW_ON_ERROR));
                $partners[$key] = [[$copy['seller'], (string) ($seller['name'] ?? '')],
                    [$copy['ico'], (string) ($seller['ico'] ?? '')], [$copy['seller_address'], (string) ($seller['address'] ?? '')],
                    [$copy['seller_contact'], (string) ($seller['email'] ?? '')], [$copy['seller_terms'], (string) ($seller['terms_url'] ?? '')],
                    [$copy['delivery_terms'], (string) ($item['delivery_note'] ?? '')]];
            }
        }
        $totals = [[$copy['goods'], MailContent::money((int) ($order['subtotal_czk'] ?? ((int) ($order['total_czk'] ?? 0) - (int) ($order['shipping_czk'] ?? 0))))],
            [$copy['shipping'], MailContent::money((int) ($order['shipping_czk'] ?? 0))], [$copy['total'], $vars['total']]];
        $lines = array_merge($lines, EmailLayout::factLines($totals));
        $html .= EmailLayout::facts($totals);
        $address = self::addressLines($shipping, $copy);
        $lines = array_merge($lines, ['', $copy['delivery'] . ' · ' . $vars['carrier'] . ':'], $address);
        $html .= EmailLayout::heading($copy['delivery']) . EmailLayout::paragraph(implode("\n", array_merge([$vars['carrier']], $address)));
        if ($showContract && $partners !== []) {
            $html .= EmailLayout::heading($copy['partner_details']);
            $lines[] = ''; $lines[] = $copy['partner_details'];
            foreach ($partners as $partnerFacts) {
                $lines = array_merge($lines, EmailLayout::factLines($partnerFacts), ['']);
                $html .= EmailLayout::facts($partnerFacts);
            }
            $consent = $order['shipping_ordered']['assisted_purchase'] ?? $order['shipping']['assisted_purchase'] ?? null;
            if (is_array($consent)) {
                $note = $copy['assistance_contract'] . "\n" . (!empty($consent['early_start'])
                    ? $copy['early_request'] . ': ' . (string) ($consent['early_request_text'] ?? '') : $copy['assistance_wait']);
                $lines[] = $note;
                $html .= EmailLayout::notice($note);
            }
        }
        $footerLinks = [];
        $token = (string) ($order['order_token'] ?? '');
        $base = rtrim($publicBaseUrl, '/');
        if (EmailLayout::safeUrl($base) !== '' && parse_url($base, PHP_URL_QUERY) === null && parse_url($base, PHP_URL_FRAGMENT) === null) {
            if (preg_match('/^[a-f0-9]{64}$/D', $token) === 1) $footerLinks[] = [$copy['button_support'], $base . '/support.php?order=' . $token];
            if ($showContract && in_array($legalLanguage, ['cs', 'en'], true)) {
                foreach (['obchodni-podminky' => 'terms', 'vymena-a-vraceni-zbozi' => 'returns', 'reklamacni-rad' => 'complaints'] as $slug => $key) {
                    if (in_array($slug, $publishedLegal, true)) $footerLinks[] = [$copy[$key], $base . '/' . $legalLanguage . '/' . $slug];
                }
            }
        }
        return EmailLayout::message($message['subject'], $copy['order_heading'], $message['message'], $html, $lines,
            $configuration, $state, $footerLinks);
    }

    public static function itemsHtml(array $items, array $copy, bool $invoice = false): string
    {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;table-layout:fixed;border-collapse:collapse;font-size:16px">';
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $quantity = (int) ($item['quantity'] ?? 0);
            $price = (int) ($item['unit_price_czk'] ?? 0);
            $html .= '<tr><td style="padding:18px 14px 18px 0;border-bottom:1px solid #e7ebef;vertical-align:top;overflow-wrap:anywhere"><strong style="font-size:17px;line-height:1.45">' . EmailLayout::escape($item['name'] ?? '') . '</strong>';
            $options = self::options($item);
            if ($options !== []) $html .= '<div style="margin-top:7px;font-size:14px;font-weight:bold;color:#634812">' . EmailLayout::escape(implode(' · ', $options)) . '</div>';
            if (!$invoice && ($item['sale_mode'] ?? '') === 'assisted') {
                $html .= '<div style="margin-top:8px;font-size:14px;color:#283443">' . EmailLayout::escape(self::partnerItem($item, $copy)) . '</div>' .
                    '<div style="margin-top:4px;font-size:13px;line-height:1.55;color:#283443">' . EmailLayout::escape(self::partnerPrices($item, $copy)) . '</div>';
            }
            $html .= '</td><td width="30%" align="right" style="padding:18px 0;border-bottom:1px solid #e7ebef;vertical-align:top;overflow-wrap:anywhere"><strong>' . EmailLayout::escape(MailContent::money($quantity * $price)) . '</strong><div style="margin-top:6px;font-size:13px;color:#283443">' . EmailLayout::escape($quantity . ' × ' . MailContent::money($price)) . '</div></td></tr>';
        }
        return $html . '</table>';
    }

    private static function partnerItem(array $item, array $copy): string
    {
        return MailContent::fill($copy['partner_item'], ['seller_name' => (string) ($item['seller']['name'] ?? '')]);
    }

    private static function partnerPrices(array $item, array $copy): string
    {
        return MailContent::fill($copy['partner_prices'], ['goods_price' => MailContent::money((int) ($item['supplier_price_czk'] ?? 0)),
            'service_fee' => MailContent::money((int) ($item['assistance_fee_czk'] ?? 0))]);
    }

    public static function options(array $item): array
    {
        $options = $item['options'] ?? [];
        if (!is_array($options)) return [];
        $lines = [];
        foreach ($options as $name => $value) if (is_string($name) && is_scalar($value)) $lines[] = $name . ': ' . (string) $value;
        return $lines;
    }

    private static function addressLines(array $shipping, array $copy): array
    {
        $lines = [];
        foreach (['recipient', 'company'] as $field) {
            $value = trim((string) ($shipping[$field] ?? ($field === 'recipient' ? $shipping['name'] ?? '' : '')));
            if ($value !== '') $lines[] = $value;
        }
        if (trim((string) ($shipping['pickup_point'] ?? '')) !== '') {
            $lines[] = $copy['pickup'] . ': ' . $shipping['pickup_point'];
            if (!empty($shipping['pickup_address'])) $lines[] = (string) $shipping['pickup_address'];
            if (!empty($shipping['pickup_code'])) $lines[] = $copy['pickup_code'] . ': ' . $shipping['pickup_code'];
        } else {
            if (!empty($shipping['street'])) $lines[] = (string) $shipping['street'];
            $city = trim((string) ($shipping['postal_code'] ?? '') . ' ' . (string) ($shipping['city'] ?? ''));
            if ($city !== '') $lines[] = $city;
        }
        $country = trim((string) ($shipping['country'] ?? ''));
        if ($country !== '') $lines[] = $country === 'CZ' ? $copy['country_cz'] : $country;
        foreach (['phone', 'email'] as $field) if (!empty($shipping[$field])) $lines[] = $copy[$field] . ': ' . $shipping[$field];
        return $lines;
    }
}
