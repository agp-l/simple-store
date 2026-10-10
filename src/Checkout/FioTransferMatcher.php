<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use RuntimeException;

/** Exact CZK, account and variable-symbol checks before changing an order. */
final class FioTransferMatcher
{
    public static function account(array $info, string $expected): string
    {
        if ((!is_string($info['accountId'] ?? null) && !is_int($info['accountId'] ?? null)) ||
            (!is_string($info['bankId'] ?? null) && !is_int($info['bankId'] ?? null)) ||
            !is_string($info['currency'] ?? null) || !is_string($info['iban'] ?? null)) {
            throw new RuntimeException('Fio nevrátilo platnou identifikaci účtu.');
        }
        $account = (string) $info['accountId'];
        $bank = (string) $info['bankId'];
        $currency = (string) $info['currency'];
        $iban = (string) $info['iban'];
        $expectedKey = self::canonicalAccount($expected);
        $expectedIban = $expectedKey === '' ? '' : BankTransferPayment::ibanForCzechAccount($expected);
        $accountDigits = ltrim($account, '0');
        // Fio documents accountId as up to 16 digits: it may contain the prefix
        // together with the main account number. The IBAN identifies both parts.
        $number = ltrim(substr($expectedIban, 14, 10), '0');
        $fullNumber = ltrim(substr($expectedIban, 8, 16), '0');
        if ($expectedKey === '' || preg_match('/^[0-9]{1,16}$/D', $account) !== 1 ||
            $bank !== '2010' || $currency !== 'CZK' ||
            !hash_equals($expectedIban, $iban) ||
            (!hash_equals($number, $accountDigits) && !hash_equals($fullNumber, $accountDigits))) {
            throw new RuntimeException('Token Fio patří jinému účtu nebo měně než účet nastavený pro ověřování.');
        }
        return $expectedKey;
    }

    public static function movement(array $row): ?array
    {
        $id = self::value($row, 22);
        $vs = self::value($row, 5);
        $currency = self::value($row, 14);
        $amount = self::value($row, 1);
        if ((!is_string($id) && !is_int($id)) ||
            !preg_match('/^[0-9]{1,20}$/D', (string) $id) ||
            (!is_string($vs) && !is_int($vs)) ||
            !preg_match('/^[0-9]{1,10}$/D', (string) $vs) ||
            $currency !== 'CZK' ||
            (!is_string($amount) && !is_int($amount) && !is_float($amount))) return null;
        // Fio reports CZK as a decimal. Never round a fractional bank movement up to
        // an integer order total, even when the fractional part is very small.
        if (is_float($amount) && floor($amount) !== $amount) return null;
        if (preg_match('/^([1-9][0-9]{0,6})(?:\.0+)?$/D', (string) $amount, $whole) !== 1) return null;
        return ['id' => (string) $id, 'vs' => (string) $vs, 'amount' => (int) $whole[1]];
    }

    public static function matchesOrder(array $order, array $movement, string $account): bool
    {
        if (($order['payment_method'] ?? '') !== 'bank_transfer' ||
            ($order['payment_status'] ?? '') !== 'pending' ||
            ($order['status'] ?? '') !== 'new' ||
            (string) ($order['variable_symbol'] ?? '') !== $movement['vs'] ||
            (int) ($order['total_czk'] ?? 0) !== $movement['amount']) return false;
        $details = json_decode((string) ($order['payment_details_json'] ?? ''), true);
        return is_array($details) && isset($details['account_display']) &&
            self::canonicalAccount((string) $details['account_display']) === $account;
    }

    private static function value(array $row, int $column): mixed
    {
        $field = $row['column' . $column] ?? null;
        return is_array($field) ? ($field['value'] ?? null) : null;
    }

    public static function canonicalAccount(string $account): string
    {
        if (preg_match('/^(?:([0-9]{1,6})-)?([0-9]{1,10})\/2010$/D', $account, $parts) !== 1) return '';
        return ltrim($parts[1] ?? '', '0') . ':' . ltrim($parts[2], '0') . '/2010';
    }
}
