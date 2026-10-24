<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use InvalidArgumentException;
use SimpleStore\Checkout\AssistedPurchase;
use SimpleStore\AfterSales\CaseNotice;

/** Deterministic fictitious data: a preview never reads customers or sends mail. */
final class MailPreview
{
    public static function compose(string $code, string $mode, array $configuration): array
    {
        if (!isset(MailContent::definitions()[$code]) || !in_array($mode, ['own', 'assisted', 'mixed'], true)) {
            throw new InvalidArgumentException('Vyber šablonu a typ ukázkové objednávky.');
        }
        $order = self::order($mode);
        if (isset(MailSettingsRepository::EVENTS[$code]) || $code === 'late_paid') {
            $order['payment_status'] = in_array($code, ['order', 'cancelled'], true) ? 'pending' : 'paid';
            $order['status'] = $code === 'late_paid' ? 'cancelled' : ($code === 'order' ? 'new' : $code);
            return OrderEmailComposer::compose($code === 'late_paid' ? 'paid' : $code, $order,
                $configuration['templates'][$code === 'late_paid' ? 'paid' : $code] ?? MailContent::definitions()[$code],
                ['number' => 'UKAZKA-123456', 'url' => 'https://example.com/sledovani'],
                'https://example.com/cs/objednavka/ukazka', 'https://example.com',
                array_merge($mode !== 'assisted' ? ['obchodni-podminky', 'reklamacni-rad'] : [],
                    $mode !== 'own' ? [AssistedPurchase::TERMS_SLUG] : []), 'cs', $configuration);
        }
        if ($code === 'invoice') {
            $invoice = ['id' => 0, 'document_number' => 'FA-2026-0042', 'order_number' => $order['order_number'],
                'issue_date' => '2026-10-24', 'seller' => ['name' => 'Ukázkový obchod', 'ico' => '12345678',
                    'street' => 'Lesní 12', 'postal_code' => '602 00', 'city' => 'Brno'],
                'buyer' => ['name' => 'Eva Nová', 'street' => 'Polní 8', 'postal_code' => '110 00', 'city' => 'Praha', 'email' => 'eva@example.com'],
                'items' => AssistedPurchase::invoiceItems($order['items']), 'shipping_czk' => $order['shipping_czk'],
                'total_czk' => $order['total_czk'] - AssistedPurchase::principal($order['items']),
                'payment_method' => 'bank_transfer', 'variable_symbol' => $order['variable_symbol']];
            return ServiceEmailComposer::invoice($invoice, $configuration);
        }
        if (in_array($code, ['password_reset', 'admin_password_reset'], true)) {
            return ServiceEmailComposer::passwordReset($code === 'password_reset' ? 'customer' : 'admin',
                'https://example.com/account/reset-password/?token=ukazka', $configuration);
        }
        if ($code === 'test') return ServiceEmailComposer::test($configuration);
        $case = ['id' => 0, 'case_number' => 'R-2026-0042', 'case_token' => str_repeat('b', 64),
            'kind' => $code === 'withdrawal_receipt' ? 'withdrawal' : 'complaint', 'status' => 'reviewing',
            'order_number' => $order['order_number'], 'item_name' => 'Lehký turistický batoh',
            'item_options_json' => json_encode(['Barva' => 'Modrá', 'Velikost' => 'M']),
            'quantity' => 1, 'unit_price_czk' => 1800, 'customer_name' => 'Eva Nová', 'customer_company' => '',
            'customer_email' => 'eva@example.com', 'customer_phone' => '+420 777 123 456',
            'description' => 'Na popruhu se uvolnil šev.', 'requested_solution' => 'repair',
            'submitted_at' => '2026-10-24 10:30:00', 'delivered_on' => '2026-10-20',
            'resolved_at' => null, 'resolution_type' => null, 'repair_duration' => null, 'refunded_at' => null,
            'seller_json' => json_encode(['name' => 'Ukázkový obchod', 'ico' => '12345678', 'street' => 'Lesní 12',
                'postal_code' => '602 00', 'city' => 'Brno', 'email' => 'obchod@example.com'])];
        return $code === 'case_event' ? CaseNotice::event($case,
            'Děkujeme za doplnění fotografií. Batoh nám prosím zašlete na adresu uvedenou v přehledu. Jakmile dorazí, ozveme se vám.',
            'https://example.com/support.php?case=ukazka', $configuration)
            : CaseNotice::receipt($case, 'https://example.com/support.php?case=ukazka', $configuration);
    }

    public static function order(string $mode): array
    {
        $item = ['name' => 'Lehký turistický batoh', 'quantity' => 1, 'unit_price_czk' => 1800,
            'options' => ['Barva' => 'Modrá', 'Velikost' => 'M']];
        if ($mode !== 'own') $item += ['sale_mode' => 'assisted', 'supplier_price_czk' => 1678, 'assistance_fee_czk' => 122,
            'seller' => ['name' => 'Partnerský outdoorový obchod', 'ico' => '12345678', 'address' => 'Horská 3, 602 00 Brno',
                'email' => 'partner@example.com', 'terms_url' => 'https://example.com/podminky'], 'delivery_note' => AssistedPurchase::DELIVERY_POLICY];
        $items = [$item];
        if ($mode === 'mixed') $items[] = ['name' => 'Turistická láhev', 'quantity' => 1, 'unit_price_czk' => 490, 'options' => ['Objem' => '1 litr']];
        $shipping = ['label' => 'PPL na adresu', 'recipient' => 'Eva Nová', 'street' => 'Polní 8', 'city' => 'Praha',
            'postal_code' => '110 00', 'country' => 'CZ', 'phone' => '+420 777 123 456'];
        if ($mode !== 'own') $shipping['assisted_purchase'] = ['early_start' => true, 'early_request_text' => AssistedPurchase::EARLY_REQUEST];
        $subtotal = $mode === 'mixed' ? 2290 : 1800;
        return ['id' => 0, 'order_number' => 'DB-26-1234567890', 'order_token' => str_repeat('a', 64),
            'customer_email' => 'eva@example.com', 'total_czk' => $subtotal + 79, 'subtotal_czk' => $subtotal, 'shipping_czk' => 79,
            'shipping' => $shipping, 'shipping_ordered' => $shipping, 'items' => $items, 'payment_method' => 'bank_transfer',
            'payment_details' => ['account_display' => '123456789/2010'], 'variable_symbol' => '1234567890',
            'payment_due_at' => '2026-10-31 12:00:00'];
    }
}
