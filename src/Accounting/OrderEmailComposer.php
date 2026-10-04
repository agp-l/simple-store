<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

/** Safe HTML and text alternatives built from immutable order data and editable copy. */
final class OrderEmailComposer
{
    public static function compose(string $event, array $order, array $template,
        array $tracking = [], string $orderUrl = '', string $publicBaseUrl = '',
        array $publishedLegal = [], string $termsText = '', string $legalLanguage = 'cs'): array
    {
        // The confirmation reflects the checkout snapshot. Later notices use the
        // corrected dispatch destination, if an administrator changed it.
        $shipping = $event === 'order' ? ($order['shipping_ordered'] ?? $order['shipping'] ?? [])
            : ($order['shipping'] ?? []);
        if (!is_array($shipping)) $shipping = [];
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $number = (string) ($order['order_number'] ?? '');
        $name = (string) ($shipping['recipient'] ?? $shipping['name'] ?? '');
        $carrier = (string) ($shipping['label'] ?? 'Doprava');
        $total = number_format((int) ($order['total_czk'] ?? 0), 0, ',', ' ') . ' Kč';
        $replace = ['{order_number}' => $number, '{customer_name}' => $name,
            '{total}' => $total, '{carrier}' => $carrier];
        $subject = strtr($template['subject'], $replace);
        $intro = strtr($template['message'], $replace);
        $paidAfterCancellation = $event === 'paid' && ($order['status'] ?? '') === 'cancelled';
        if ($paidAfterCancellation) {
            // Merchant-defined 'paid' copy usually promises shipping. A late verified
            // settlement must not imply that a cancelled order was reopened.
            $subject = 'Platba za zrušenou objednávku ' . $number . ' dorazila';
            $intro = 'Platbu jsme obdrželi až po zrušení objednávky. Objednávka zůstává zrušená. Platbu prověříme a ozveme se ohledně jejího vrácení. Máte-li dotaz, odpovězte na tento e-mail.';
        }
        $label = $paidAfterCancellation ? 'Platba po zrušení objednávky' :
            (MailSettingsRepository::EVENTS[$event]['label'] ?? 'Objednávka');
        $lines = [trim($intro), '', 'Objednávka ' . $number, 'Stav: ' .
            $label];
        $facts = ['Stav' => $label,
            'Doprava' => $carrier, 'Celkem' => $total];
        $lines[] = '';
        $lines[] = 'Objednané zboží:';
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $quantity = (int) ($item['quantity'] ?? 0);
            $lines[] = $quantity . ' × ' . (string) ($item['name'] ?? '') . ' — ' .
                number_format($quantity * (int) ($item['unit_price_czk'] ?? 0), 0, ',', ' ') . ' Kč';
            foreach (self::options($item) as $option) $lines[] = '  ' . $option;
        }
        $lines[] = 'Zboží: ' . number_format((int) ($order['subtotal_czk'] ??
            ((int) ($order['total_czk'] ?? 0) - (int) ($order['shipping_czk'] ?? 0))), 0, ',', ' ') . ' Kč';
        $lines[] = 'Doprava: ' . number_format((int) ($order['shipping_czk'] ?? 0), 0, ',', ' ') . ' Kč';
        $lines[] = 'Celkem: ' . $total;
        $lines[] = '';
        $lines[] = 'Doručení · ' . $carrier . ':';
        $address = self::addressLines($shipping);
        foreach ($address as $line) $lines[] = $line;
        if ($event === 'order') {
            $method = (string) ($order['payment_method'] ?? '');
            if ($method === 'bank_transfer') {
                $bank = is_array($order['payment_details'] ?? null) ? $order['payment_details'] : [];
                $lines[] = '';
                $lines[] = 'Platba bankovním převodem';
                $lines[] = 'Číslo účtu: ' . (string) ($bank['account_display'] ?? '');
                $lines[] = 'Variabilní symbol: ' . (string) ($order['variable_symbol'] ?? '');
                $lines[] = 'Splatnost: ' . (string) ($order['payment_due_at'] ?? '');
                $facts['Platba'] = 'Bankovní převod';
                $facts['Číslo účtu'] = (string) ($bank['account_display'] ?? '');
                $facts['Variabilní symbol'] = (string) ($order['variable_symbol'] ?? '');
                $facts['Splatnost'] = (string) ($order['payment_due_at'] ?? '');
            } else {
                $gateway = ['comgate' => 'Comgate', 'gopay' => 'GoPay', 'btcpay' => 'BTCPay Server'][$method] ?? 'online';
                $lines[] = '';
                $lines[] = 'Zvolená platba: online přes ' . $gateway . '.';
                $lines[] = 'O výsledku platby rozhoduje potvrzení brány.';
                $facts['Platba'] = 'Online přes ' . $gateway;
            }
        } elseif ($event === 'cancelled') {
            $lines[] = '';
            $lines[] = 'Objednávka byla zrušena. Pokud jste již zaplatili, odpovězte na tento e-mail.';
            if (($order['payment_status'] ?? '') === 'paid') $facts['Platba'] = 'Přijata; zrušení prověřujeme';
        } elseif ($paidAfterCancellation) {
            $lines[] = '';
            $lines[] = 'Platba dorazila po zrušení objednávky. Zásilku nyní nepřipravujeme.';
            $facts['Platba'] = 'Přijata po zrušení';
        } elseif ($event === 'paid' || ($order['payment_status'] ?? '') === 'paid') {
            $lines[] = '';
            $lines[] = 'Platba byla potvrzena.';
            $facts['Platba'] = 'Potvrzena';
        }
        $trackingNumber = trim((string) ($tracking['number'] ?? ''));
        $trackingUrl = self::safeHttps((string) ($tracking['url'] ?? ''));
        $showTracking = in_array($event, ['shipped', 'tracking'], true) ||
            ($event === 'completed' && ($trackingNumber !== '' || $trackingUrl !== ''));
        if ($showTracking) {
            if ($trackingNumber !== '') {
                $lines[] = 'Číslo zásilky: ' . $trackingNumber;
                $facts['Číslo zásilky'] = $trackingNumber;
            }
            if ($trackingUrl !== '') {
                $lines[] = 'Sledování zásilky: ' . $trackingUrl;
            }
            if ($trackingNumber === '' && $trackingUrl === '') {
                $lines[] = 'Dopravce zatím nepřidělil číslo zásilky. Jakmile jej budeme mít, pošleme doplnění.';
            }
        }
        $orderUrl = self::safeHttps($orderUrl);
        if ($orderUrl !== '') {
            $lines[] = '';
            $lines[] = 'Detail a stav objednávky: ' . $orderUrl;
        }
        $legalLinks = [];
        $publicBaseUrl = rtrim($publicBaseUrl, '/');
        if (in_array($event, ['order', 'paid'], true) && in_array($legalLanguage, ['cs', 'en'], true) &&
            self::safeHttps($publicBaseUrl) !== '' &&
            parse_url($publicBaseUrl, PHP_URL_QUERY) === null && parse_url($publicBaseUrl, PHP_URL_FRAGMENT) === null) {
            foreach (['obchodni-podminky' => 'Obchodní podmínky',
                'vymena-a-vraceni-zbozi' => 'Výměna a vrácení zboží',
                'reklamacni-rad' => 'Reklamační řád'] as $slug => $title) {
                if (in_array($slug, $publishedLegal, true)) {
                    $legalLinks[$title] = $publicBaseUrl . '/' . $legalLanguage . '/' . $slug;
                }
            }
        }
        if ($legalLinks !== []) {
            $lines[] = '';
            foreach ($legalLinks as $title => $url) $lines[] = $title . ': ' . $url;
        }
        if (in_array($event, ['order', 'paid'], true) && $termsText !== '') {
            $lines[] = '';
            $lines[] = 'OBCHODNÍ PODMÍNKY PLATNÉ PŘI OBJEDNÁNÍ';
            $lines[] = $termsText;
        }
        $lines[] = '';
        $lines[] = 'Děkujeme, tým dobrodruzi.cz';
        $text = implode("\n", $lines) . "\n";
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $factsHtml = '';
        foreach ($facts as $label => $value) {
            if ($value === '') continue;
            $factsHtml .= '<tr><th align="left" style="padding:9px 12px;color:#5f6b62;font-weight:500;border-bottom:1px solid #e5ebe2">' .
                $escape($label) . '</th><td align="right" style="padding:9px 12px;border-bottom:1px solid #e5ebe2">' .
                $escape($value) . '</td></tr>';
        }
        $itemsHtml = '';
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $quantity = (int) ($item['quantity'] ?? 0);
            $options = self::options($item);
            $itemsHtml .= '<tr><td style="padding:12px 0;border-bottom:1px solid #e5ebe2;vertical-align:top"><strong>' .
                $escape((string) ($item['name'] ?? '')) . '</strong>' .
                ($options === [] ? '' : '<br><span style="color:#617064;font-size:12px">' .
                    $escape(implode(' · ', $options)) . '</span>') .
                '</td><td align="right" style="padding:12px 0;border-bottom:1px solid #e5ebe2;vertical-align:top;white-space:nowrap">' .
                $escape($quantity . ' × ' . number_format((int) ($item['unit_price_czk'] ?? 0), 0, ',', ' ') . ' Kč') .
                '<br><strong>' . $escape(number_format($quantity * (int) ($item['unit_price_czk'] ?? 0),
                    0, ',', ' ') . ' Kč') . '</strong></td></tr>';
        }
        $addressHtml = '<p style="margin:0 0 8px;color:#617064;font-size:13px">' . $escape($carrier) . '</p>';
        foreach ($address as $line) $addressHtml .= '<div>' . $escape($line) . '</div>';
        $actions = '';
        foreach (['Sledovat zásilku' => $showTracking ? $trackingUrl : '',
            'Zobrazit objednávku' => $orderUrl] as $label => $url) {
            if ($url !== '') $actions .= '<a href="' . $escape($url) .
                '" style="display:inline-block;background:#426f40;color:#fff;padding:11px 16px;margin:6px 10px 0 0;text-decoration:none;font-size:14px;font-weight:700">' .
                $escape($label) . '</a>';
        }
        $note = in_array($event, ['shipped', 'tracking'], true) && $trackingNumber === '' && $trackingUrl === ''
            ? '<p style="color:#5f6b62">Dopravce zatím nepřidělil sledovací údaje. Pošleme je, jakmile je budeme mít.</p>' : '';
        $legalHtml = '';
        foreach ($legalLinks as $title => $url) {
            $legalHtml .= '<a href="' . $escape($url) . '" style="display:inline-block;color:#426f40;margin:0 12px 5px 0;text-decoration:underline">' .
                $escape($title) . '</a>';
        }
        $termsHtml = in_array($event, ['order', 'paid'], true) && $termsText !== ''
            ? '<section style="margin-top:28px;padding-top:18px;border-top:1px solid #dfe6dc">' .
                '<h2 style="font-size:16px;color:#263a2b">Obchodní podmínky platné při objednání</h2>' .
                '<div style="color:#455248;font-size:12px">' . nl2br($escape($termsText)) . '</div></section>' : '';
        $html = '<!doctype html><html lang="cs"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>' .
            '<body style="margin:0;padding:0;background:#f1f4ef;color:#263129;font:15px/1.55 Arial,Helvetica,sans-serif">' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f1f4ef"><tr><td align="center" style="padding:24px 12px">' .
            '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;border-collapse:collapse;background:#fff;border:1px solid #dfe6dc">' .
            '<tr><td style="background:#263a2b;color:#fff;padding:22px 28px;font-size:22px;font-weight:700">dobrodruzi<span style="color:#bde18d">.cz</span></td></tr>' .
            '<tr><td style="padding:26px 28px"><p style="margin:0 0 6px;color:#426f40;font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase">' .
            $escape($label) . '</p>' .
            '<h1 style="margin:0 0 17px;font-size:24px;line-height:1.3;color:#263a2b">Objednávka ' . $escape($number) . '</h1>' .
            '<p style="margin:0 0 19px">' . nl2br($escape($intro)) . '</p>' .
            '<table role="presentation" width="100%" style="width:100%;border-collapse:collapse;background:#f3f7ef;font-size:14px">' . $factsHtml . '</table>' .
            '<h2 style="margin:24px 0 8px;font-size:16px;color:#263a2b">Objednané zboží</h2>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px">' . $itemsHtml .
            '<tr><td style="padding:9px 0 0;color:#617064">Zboží</td><td align="right" style="padding:9px 0 0">' .
                $escape(number_format((int) ($order['subtotal_czk'] ?? ((int) ($order['total_czk'] ?? 0) -
                    (int) ($order['shipping_czk'] ?? 0))), 0, ',', ' ') . ' Kč') . '</td></tr>' .
            '<tr><td style="padding:5px 0;color:#617064">Doprava</td><td align="right" style="padding:5px 0">' .
                $escape(number_format((int) ($order['shipping_czk'] ?? 0), 0, ',', ' ') . ' Kč') . '</td></tr>' .
            '<tr><td style="padding:10px 0;border-top:1px solid #dfe6dc;font-size:17px;font-weight:700">Celkem</td><td align="right" style="padding:10px 0;border-top:1px solid #dfe6dc;font-size:17px;font-weight:700">' . $escape($total) . '</td></tr></table>' .
            '<h2 style="margin:22px 0 8px;font-size:16px;color:#263a2b">Doručení</h2><div style="font-size:14px;line-height:1.65">' . $addressHtml . '</div>' .
            ($trackingNumber === '' || !$showTracking ? '' :
                '<h2 style="margin:22px 0 8px;font-size:16px;color:#263a2b">Sledování zásilky</h2><p style="margin:0">Číslo zásilky: <strong style="font-size:18px">' . $escape($trackingNumber) . '</strong></p>') .
            $note . '<div style="margin-top:18px">' . $actions . '</div>' . $termsHtml .
            '<p style="margin:26px 0 0;color:#617064;font-size:13px">Děkujeme, tým dobrodruzi.cz</p></td></tr>' .
            '<tr><td style="padding:18px 28px;background:#f7f9f5;border-top:1px solid #dfe6dc;color:#617064;font-size:12px">' .
                'Tato zpráva se týká vaší objednávky. Můžete na ni odpovědět.' .
                ($legalHtml === '' ? '' : '<div style="margin-top:10px">' . $legalHtml . '</div>') .
            '</td></tr></table></td></tr></table></body></html>';
        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }

    /** @return list<string> */
    private static function options(array $item): array
    {
        $options = $item['options'] ?? [];
        if (!is_array($options)) return [];
        $lines = [];
        foreach ($options as $name => $value) {
            if (is_string($name) && is_scalar($value)) $lines[] = $name . ': ' . (string) $value;
        }
        return $lines;
    }

    /** @return list<string> */
    private static function addressLines(array $shipping): array
    {
        $lines = [];
        foreach (['recipient', 'company'] as $field) {
            $value = trim((string) ($shipping[$field] ?? ($field === 'recipient' ? $shipping['name'] ?? '' : '')));
            if ($value !== '') $lines[] = $value;
        }
        if (trim((string) ($shipping['pickup_point'] ?? '')) !== '') {
            $lines[] = 'Výdejní místo / box: ' . $shipping['pickup_point'];
            if (trim((string) ($shipping['pickup_address'] ?? '')) !== '') $lines[] = (string) $shipping['pickup_address'];
            if (trim((string) ($shipping['pickup_code'] ?? '')) !== '') $lines[] = 'Kód místa: ' . $shipping['pickup_code'];
        } else {
            if (trim((string) ($shipping['street'] ?? '')) !== '') $lines[] = (string) $shipping['street'];
            $city = trim((string) ($shipping['postal_code'] ?? '') . ' ' . (string) ($shipping['city'] ?? ''));
            if ($city !== '') $lines[] = $city;
        }
        $country = trim((string) ($shipping['country'] ?? ''));
        if ($country !== '') $lines[] = $country === 'CZ' ? 'Česká republika' : $country;
        if (trim((string) ($shipping['phone'] ?? '')) !== '') $lines[] = 'Telefon: ' . $shipping['phone'];
        if (trim((string) ($shipping['email'] ?? '')) !== '') $lines[] = 'E-mail: ' . $shipping['email'];
        return $lines;
    }

    private static function safeHttps(string $url): string
    {
        return $url !== '' && strlen($url) <= 1000 &&
            preg_match('/[\x00-\x20\x7f]/', $url) !== 1 && filter_var($url, FILTER_VALIDATE_URL) !== false &&
            parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_USER) === null &&
            parse_url($url, PHP_URL_PASS) === null ? $url : '';
    }
}
