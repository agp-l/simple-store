<?php
declare(strict_types=1);

class MeekroDB
{
    public array $orders = [];
    public array $invoices = [];
    public array $history = [];
    public array $outbox = [];
    public array $sequences = [];
    public array $entries = [];
    public array $entryEvents = [];
    public array $sales = [];
    public array $stock = [];
    public array $btcpayPayments = [];
    private ?array $snapshot = null;

    public function startTransaction(): void
    {
        $this->snapshot = [$this->invoices, $this->history, $this->outbox,
            $this->sequences, $this->entries, $this->entryEvents];
    }

    public function commit(): void { $this->snapshot = null; }

    public function rollback(): void
    {
        if ($this->snapshot !== null) {
            [$this->invoices, $this->history, $this->outbox,
                $this->sequences, $this->entries, $this->entryEvents] = $this->snapshot;
            $this->snapshot = null;
        }
    }

    public function queryFirstField(string $sql, mixed ...$args): mixed
    {
        if (str_contains($sql, 'information_schema.TABLES') ||
            str_contains($sql, 'information_schema.COLUMNS')) return 1;
        if (str_contains($sql, 'FROM shop_invoice_number_events')) {
            return count(array_filter($this->history, static fn (array $row): bool =>
                $row['old_number'] === $args[0] || $row['new_number'] === $args[1]));
        }
        if (str_contains($sql, 'FROM shop_invoices WHERE document_number=')) {
            return count(array_filter($this->invoices, static fn (array $row): bool =>
                $row['document_number'] === $args[0]));
        }
        if (str_contains($sql, 'FROM shop_mail_outbox WHERE event_key=')) {
            foreach ($this->outbox as $id => $row) if ($row['event_key'] === $args[0]) return $id;
            return null;
        }
        throw new RuntimeException('Unexpected first field: ' . $sql);
    }

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        if (str_contains($sql, 'FROM shop_mail_settings')) return null;
        if (str_contains($sql, 'FROM shop_tax_settings')) return null;
        if (str_contains($sql, 'FROM shop_btcpay_payments')) {
            $payment = $this->btcpayPayments[$args[0]] ?? null;
            return $payment !== null && $payment['invoice_id'] === $args[1] ?
                ['status' => $payment['status']] : null;
        }
        if (str_contains($sql, 'FROM shop_orders')) return $this->orders[$args[0]] ?? null;
        if (str_contains($sql, 'FROM shop_invoice_sequence')) {
            return ['next_number' => $this->sequences[$args[0]] ?? 0];
        }
        if (str_contains($sql, 'FROM shop_invoices WHERE order_id=')) {
            foreach ($this->invoices as $row) if ($row['order_id'] === $args[0]) {
                return str_contains($sql, 'SELECT id ') ? ['id' => $row['id']] : $row;
            }
            return null;
        }
        if (str_contains($sql, 'FROM shop_invoices WHERE id=')) {
            $row = $this->invoices[$args[0]] ?? null;
            return $row === null || !str_contains($sql, 'SELECT document_number') ? $row :
                ['document_number' => $row['document_number']];
        }
        if (str_contains($sql, 'FROM shop_mail_outbox WHERE id=')) {
            return $this->outbox[$args[0]] ?? null;
        }
        if (str_contains($sql, 'FROM shop_tax_entries WHERE id=')) {
            return $this->entries[$args[0]] ?? null;
        }
        throw new RuntimeException('Unexpected first row: ' . $sql);
    }

    public function insert(string $table, array $row): void
    {
        if ($table === 'shop_invoices') {
            $row['id'] = count($this->invoices) + 1;
            $row['emailed_at'] = null;
            $this->invoices[$row['id']] = $row;
        } elseif ($table === 'shop_invoice_number_events') {
            $this->history[] = $row;
        } elseif ($table === 'shop_tax_entries') {
            $row['id'] = count($this->entries) + 1;
            $this->entries[$row['id']] = $row;
        } elseif ($table === 'shop_tax_entry_events') {
            $this->entryEvents[] = $row;
        } else {
            throw new RuntimeException('Unexpected insert: ' . $table);
        }
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, 'FROM shop_mail_templates')) return [];
        if (str_contains($sql, 'INSERT IGNORE INTO shop_invoice_sequence')) {
            $this->sequences[$args[0]] ??= $args[1];
        } elseif (str_contains($sql, 'UPDATE shop_invoice_sequence')) {
            $this->sequences[$args[1]] = $args[0];
        } elseif (str_contains($sql, 'INSERT INTO shop_invoice_sequence')) {
            $this->sequences[$args[0]] = max($this->sequences[$args[0]] ?? 1, $args[1]);
        } elseif (str_contains($sql, 'UPDATE shop_invoices SET document_number')) {
            $this->invoices[$args[1]]['document_number'] = $args[0];
        } elseif (str_contains($sql, 'INSERT IGNORE INTO shop_mail_outbox')) {
            foreach ($this->outbox as $row) if ($row['event_key'] === $args[0]) return [];
            $id = count($this->outbox) + 1;
            $this->outbox[$id] = ['id' => $id, 'event_key' => $args[0], 'order_id' => $args[1],
                'recipient_email' => $args[2], 'subject' => $args[3], 'body_text' => $args[4], 'body_html' => $args[5],
                'state' => 'queued', 'attempts' => 0];
        } elseif (str_contains($sql, 'UPDATE shop_mail_outbox SET subject=')) {
            if (in_array($this->outbox[$args[3]]['state'], ['queued', 'failed'], true)) {
                $this->outbox[$args[3]]['subject'] = $args[0];
                $this->outbox[$args[3]]['body_text'] = $args[1];
                $this->outbox[$args[3]]['body_html'] = $args[2];
            }
        } elseif (str_contains($sql, 'UPDATE shop_mail_outbox SET state=%s, attempts=')) {
            $this->outbox[$args[1]]['state'] = $args[0];
            $this->outbox[$args[1]]['attempts']++;
        } elseif (str_contains($sql, 'UPDATE shop_mail_outbox SET state=%s, last_error=')) {
            $this->outbox[$args[3]]['state'] = $args[0];
            $this->outbox[$args[3]]['last_error'] = $args[1];
        } elseif (str_contains($sql, 'UPDATE shop_invoices SET emailed_at=')) {
            $this->invoices[$args[0]]['emailed_at'] = '2026-09-30 12:00:00';
        } elseif (str_contains($sql, 'UPDATE shop_tax_entries SET entry_date=')) {
            $this->entries[$args[8]] = array_replace($this->entries[$args[8]],
                array_combine(['entry_date', 'direction', 'account', 'tax_kind', 'amount_czk',
                    'description', 'counterparty', 'reference'], array_slice($args, 0, 8)));
        } elseif (str_contains($sql, 'DELETE FROM shop_tax_entries WHERE id=')) {
            unset($this->entries[$args[0]]);
        } elseif (str_contains($sql, 'FROM shop_tax_entries WHERE entry_date')) {
            $rows = array_values(array_filter($this->entries, static fn (array $row): bool =>
                $row['entry_date'] >= $args[0] && $row['entry_date'] < $args[1]));
            return array_slice($rows, $args[3], $args[2]);
        } elseif (str_contains($sql, 'FROM shop_tax_entry_events WHERE created_at')) {
            return array_reverse($this->entryEvents);
        } elseif (str_contains($sql, 'FROM shop_deleted_sale_lines')) {
            return array_slice($this->sales, $args[7], $args[6]);
        } elseif (str_contains($sql, 'FROM shop_stock_movements')) {
            return array_slice($this->stock, $args[3], $args[2]);
        } else {
            throw new RuntimeException('Unexpected SQL: ' . $sql);
        }
        return [];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Accounting\InvoiceRepository;
use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Accounting\TaxEvidenceRepository;

$db = new MeekroDB();
$db->orders[9] = ['id' => 9, 'order_number' => 'DB-26-1234567890',
    'payment_method' => 'bank_transfer', 'payment_status' => 'paid', 'status' => 'completed',
    'customer_email' => 'customer@example.test', 'subtotal_czk' => 900,
    'shipping_czk' => 90, 'total_czk' => 990, 'variable_symbol' => '1234567890',
    'payment_details_json' => '{"account_display":"TEST-ACCOUNT-ORDER"}',
    'items_json' => json_encode([['name' => '<Stan>', 'quantity' => 2,
        'unit_price_czk' => 450]], JSON_THROW_ON_ERROR)];
$seller = ['name' => 'Jan Novák', 'ico' => '12345678', 'street' => 'Hlavní 1',
    'city' => 'Brno', 'postal_code' => '60200', 'bank_account' => 'OLD/0000'];
$buyer = ['name' => 'Eva Nová', 'street' => '', 'city' => '', 'postal_code' => '', 'ico' => ''];
$repository = new InvoiceRepository($db);
$invoice = $repository->issue(9, $seller, $buyer);
$invoiceYear = (int) (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format('Y');
if (!preg_match('/^F[0-9]{4}-000001$/D', $invoice['document_number']) ||
    $invoice['total_czk'] !== 990 || $invoice['seller']['bank_account'] !== 'TEST-ACCOUNT-ORDER') {
    throw new RuntimeException('Invoice did not use a stable order and bank snapshot.');
}
try {
    $repository->issue(9, $seller, $buyer);
    throw new RuntimeException('Duplicate invoice accepted.');
} catch (InvalidArgumentException $expected) {}

$queue = new OrderMailQueue($db, static function (): bool { return false; });
$mailId = $queue->enqueueInvoice($invoice);
$repository->renumber((int) $invoice['id'], 'F' . $invoiceYear . '-000123', 1, 'Oprava číselné řady');
$fixed = $repository->byId((int) $invoice['id']);
$queue->enqueueInvoice($fixed);
if ($db->outbox[$mailId]['subject'] !== 'Faktura F' . $invoiceYear . '-000123' ||
    count($db->history) !== 1 || $db->sequences[$invoiceYear] < 124) {
    throw new RuntimeException('Renumbering did not refresh the queued mail and sequence.');
}
try {
    $repository->renumber((int) $invoice['id'], $invoice['document_number'], 1,
        'Návrat k původnímu číslu');
    throw new RuntimeException('An old invoice number was reused.');
} catch (InvalidArgumentException $expected) {}

if ($queue->dispatch($mailId, 'shop@example.test') ||
    $db->outbox[$mailId]['state'] !== 'failed' || $db->outbox[$mailId]['attempts'] !== 1) {
    throw new RuntimeException('Failed mail was not retained for retry.');
}
$accepted = [];
$reliable = new OrderMailQueue($db, static function (...$args) use (&$accepted): bool {
    $accepted = $args;
    return true;
});
if (!$reliable->dispatch($mailId, 'shop@example.test') ||
    $db->outbox[$mailId]['attempts'] !== 2 || $db->invoices[1]['emailed_at'] === null ||
    !str_contains($accepted[2], chunk_split(base64_encode($db->outbox[$mailId]['body_text']), 76, "\r\n")) ||
    !str_contains($accepted[3], 'multipart/alternative')) {
    throw new RuntimeException('Mail retry did not deliver the invoice snapshot.');
}
if ($reliable->dispatch($mailId, 'shop@example.test')) {
    throw new RuntimeException('Sent mail was delivered twice.');
}
$selectedInvoice = $fixed;
ob_start();
require dirname(__DIR__) . '/view/admin/invoice-print.php';
$html = (string) ob_get_clean();
if (!str_contains($html, '&lt;Stan&gt;') || str_contains($html, '<Stan>') ||
    !str_contains($html, '990 Kč')) {
    throw new RuntimeException('Printable invoice is not escaped or has the wrong amount.');
}
$db->orders[10] = array_replace($db->orders[9], [
    'id' => 10, 'order_number' => 'DB-COMGATE-2', 'payment_method' => 'comgate',
    'payment_details_json' => '{}', 'variable_symbol' => '1234567891',
    'customer_email' => 'online@example.test',
]);
$online = $repository->issue(10, $seller, $buyer);
if ($online['payment_method'] !== 'comgate' || $online['total_czk'] !== 990) {
    throw new RuntimeException('Paid gateway order did not produce a payment snapshot invoice.');
}
$onlineMail = $queue->enqueueInvoice($online);
if (!str_contains($db->outbox[$onlineMail]['body_text'], 'Uhrazeno online přes Comgate') ||
    str_contains($db->outbox[$onlineMail]['body_text'], 'Uhrazeno bankovním převodem')) {
    throw new RuntimeException('Gateway invoice mail misstates the payment method.');
}
$orderMail = $queue->enqueueOrder($db->orders[10] + [
    'items' => [['name' => 'Stan', 'quantity' => 2, 'unit_price_czk' => 450]],
    'shipping' => ['label' => 'Kurýr'],
]);
if ($orderMail === null || !str_contains($db->outbox[$orderMail]['body_text'], 'online přes Comgate') ||
    str_contains($db->outbox[$orderMail]['body_text'], 'Číslo účtu:')) {
    throw new RuntimeException('Gateway order email contains bank transfer instructions.');
}
$selectedInvoice = $online;
ob_start();
require dirname(__DIR__) . '/view/admin/invoice-print.php';
$onlineHtml = (string) ob_get_clean();
if (!str_contains($onlineHtml, 'online přes Comgate') ||
    str_contains($onlineHtml, 'Účet:') || str_contains($onlineHtml, 'bankovním převodem')) {
    throw new RuntimeException('Gateway invoice print misstates the payment method.');
}
$db->orders[11] = array_replace($db->orders[10], [
    'id' => 11, 'order_number' => 'DB-BTCPAY-3', 'payment_method' => 'btcpay',
    'provider_reference' => 'invoice-123', 'variable_symbol' => '1234567892',
]);
$db->btcpayPayments[11] = ['invoice_id' => 'invoice-123', 'status' => 'processing'];
try {
    $repository->issue(11, $seller, $buyer);
    throw new RuntimeException('Processing BTCPay invoice must not produce an accounting invoice.');
} catch (InvalidArgumentException $expected) {}
$db->btcpayPayments[11]['status'] = 'settled';
$db->btcpayPayments[11]['invoice_id'] = 'different-invoice';
try {
    $repository->issue(11, $seller, $buyer);
    throw new RuntimeException('Settlement of an unrelated BTCPay invoice must not issue an accounting invoice.');
} catch (InvalidArgumentException $expected) {}
$db->btcpayPayments[11]['invoice_id'] = 'invoice-123';
$bitcoinInvoice = $repository->issue(11, $seller, $buyer);
$bitcoinMail = $queue->enqueueInvoice($bitcoinInvoice);
if ($bitcoinInvoice['payment_method'] !== 'btcpay' ||
    !str_contains($db->outbox[$bitcoinMail]['body_text'], 'přes BTCPay Server') ||
    str_contains($db->outbox[$bitcoinMail]['body_text'], 'bankovním převodem')) {
    throw new RuntimeException('Confirmed BTCPay invoice or customer mail has wrong payment details.');
}
$selectedInvoice = $bitcoinInvoice;
ob_start();
require dirname(__DIR__) . '/view/admin/invoice-print.php';
$bitcoinHtml = (string) ob_get_clean();
if (!str_contains($bitcoinHtml, 'přes BTCPay Server') || str_contains($bitcoinHtml, 'Účet:')) {
    throw new RuntimeException('BTCPay accounting invoice print misstates the payment method.');
}
$bitcoinOrderMail = $queue->enqueueOrder($db->orders[11] + [
    'items' => [['name' => 'Stan', 'quantity' => 2, 'unit_price_czk' => 450]],
    'shipping' => ['label' => 'Kurýr'],
], 'https://shop.example/cs/objednavka/private-token');
if ($bitcoinOrderMail === null ||
    !str_contains($db->outbox[$bitcoinOrderMail]['body_text'], 'online přes BTCPay Server') ||
    !str_contains($db->outbox[$bitcoinOrderMail]['body_text'], 'https://shop.example') ||
    str_contains($db->outbox[$bitcoinOrderMail]['body_text'], 'Číslo účtu:')) {
    throw new RuntimeException('BTCPay order email must include payment instructions and order link.');
}
$tax = new TaxEvidenceRepository($db);
$movement = ['entry_date' => '2026-09-30', 'direction' => 'income', 'account' => 'bank',
    'tax_kind' => 'taxable', 'amount_czk' => 990, 'description' => '=PAYLOAD',
    'counterparty' => 'Eva Nová', 'reference' => 'bank-1'];
$tax->addEntry($movement);
$csv = fopen('php://temp', 'w+b');
$tax->writeLedgerCsv($csv, 2026);
rewind($csv);
$data = stream_get_contents($csv);
fclose($csv);
if (!str_contains($data, "'=PAYLOAD") || count($db->entries) !== 1) {
    throw new RuntimeException('Journal CSV allowed spreadsheet formulas.');
}
try {
    $tax->addEntry(array_replace($movement, ['direction' => 'expense']));
    throw new RuntimeException('Expense was accepted with income classification.');
} catch (InvalidArgumentException $expected) {}
$tax->amendEntry(1, array_replace($movement, ['amount_czk' => 995,
    'description' => 'Opravená úhrada']), 1, 'Oprava podle banky');
if ($db->entries[1]['amount_czk'] !== 995 || count($db->entryEvents) !== 1 ||
    !str_contains($db->entryEvents[0]['old_json'], '=PAYLOAD')) {
    throw new RuntimeException('Journal correction lost the previous entry.');
}
$tax->voidEntry(1, 1, 'Duplicitní záznam');
if ($db->entries !== [] || count($db->entryEvents) !== 2 ||
    $db->entryEvents[1]['action'] !== 'voided') {
    throw new RuntimeException('Voided journal entry still affects the ledger.');
}
$db->sales = [['created_at' => '2026-09-30 12:00:00', 'order_number' => 'DB-26-1',
    'product_key' => str_repeat('a', 32), 'name' => '=MALICIOUS', 'quantity' => 2,
    'unit_price_czk' => 450, 'status' => 'completed']];
$db->stock = [['movement_date' => '2026-09-30', 'product_key' => str_repeat('a', 32),
    'quantity_change' => 5, 'unit_cost_czk' => 200, 'description' => '=MALICIOUS',
    'reference' => 'N-1']];
foreach (['writeSalesCsv', 'writeStockCsv'] as $export) {
    $stream = fopen('php://temp', 'w+b');
    $tax->$export($stream, 2026);
    rewind($stream);
    $data = stream_get_contents($stream);
    fclose($stream);
    if (!str_contains($data, "'=MALICIOUS")) {
        throw new RuntimeException($export . ' did not safely export annual data.');
    }
}
echo "Tax invoice and mail tests passed.\n";
