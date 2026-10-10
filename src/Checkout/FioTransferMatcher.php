<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use RuntimeException;

/** Exact CZK, account and variable-symbol checks before changing an order. */
final class FioTransferMatcher
{
    public static function account(array $info, string $expected): string
    {
        $account = (string) ($info['accountId'] ?? '');
        $bank = (string) ($info['bankId'] ?? '');
        $currency = (string) ($info['currency'] ?? '');
        if (!preg_match('/^[0-9]{1,10}$/D', $account) || $bank !== '2010' || $currency !== 'CZK' ||
            !hash_equals(self::canonicalAccount($expected), self::canonicalAccount($account . '/' . $bank))) {
            throw new RuntimeException('Token Fio patří jinému účtu nebo měně než účet nastavený pro ověřování.');
        }
        return self::canonicalAccount($expected);
    }

    public static function movement(array $row): ?array
    {
        $id = self::value($row, 22);
        $vs = self::value($row, 5);
        $currency = self::value($row, 14);
        $amount = self::value($row, 1);
        if (!is_scalar($id) || !preg_match('/^[0-9]{1,20}$/D', (string) $id) ||
            !is_scalar($vs) || !preg_match('/^[0-9]{1,10}$/D', (string) $vs) ||
            $currency !== 'CZK' || !is_numeric($amount)) return null;
        $number = (float) $amount;
        if ($number < 1 || $number > 9999999 || abs($number - round($number)) > 0.000001) return null;
        return ['id' => (string) $id, 'vs' => (string) $vs, 'amount' => (int) round($number)];
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
