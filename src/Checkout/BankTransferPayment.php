<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** Bank data is copied into each order so later configuration changes cannot change a payment. */
final class BankTransferPayment
{
    private string $iban;
    private string $accountDisplay;
    private string $recipient;

    public function __construct(string $iban, string $accountDisplay, string $recipient)
    {
        $this->iban = self::validatedIban($iban);
        $this->accountDisplay = self::validatedAccountDisplay($accountDisplay, $this->iban);
        $this->recipient = self::text($recipient, 120, 'Příjemce platby');
    }

    /** Persist this snapshot with the order rather than reading current config on confirmation. */
    public function snapshot(): array
    {
        return [
            'iban' => $this->iban,
            'account_display' => $this->accountDisplay,
            'recipient' => $this->recipient,
        ];
    }

    /** A historical order can be displayed after bank settings are removed or changed. */
    public static function fromOrder(array $order): self
    {
        $snapshot = $order['payment_details'] ?? null;
        if (!is_array($snapshot)) {
            throw new InvalidArgumentException('Objednávka neobsahuje platební údaje.');
        }
        return new self((string) ($snapshot['iban'] ?? ''),
            (string) ($snapshot['account_display'] ?? ''), (string) ($snapshot['recipient'] ?? ''));
    }

    /**
     * Return human-readable bank data and the Czech QR Platba SPAYD payload.
     * The QR image generator encodes the returned `spayd` string locally.
     */
    public function details(array $order): array
    {
        if (($order['payment_method'] ?? null) !== 'bank_transfer') {
            throw new InvalidArgumentException('Objednávka nepoužívá platbu převodem.');
        }
        $saved = self::fromOrder($order);
        $amount = $order['total_czk'] ?? null;
        $vs = $order['variable_symbol'] ?? null;
        if (!is_int($amount) && !(is_string($amount) && ctype_digit($amount))) {
            throw new InvalidArgumentException('Neplatná částka objednávky.');
        }
        $amount = (int) $amount;
        if ($amount < 1 || $amount > 9999999 || !is_string($vs) ||
            preg_match('/^[0-9]{1,10}$/D', $vs) !== 1) {
            throw new InvalidArgumentException('Neplatná částka nebo variabilní symbol.');
        }
        return $saved->snapshot() + [
            'amount_czk' => $amount,
            'variable_symbol' => $vs,
            'due_at' => $order['payment_due_at'] ?? null,
            'spayd' => 'SPD*1.0*ACC:' . $saved->iban . '*AM:' . number_format($amount, 2, '.', '') .
                '*CC:CZK*X-VS:' . $vs,
        ];
    }

    private static function validatedIban(string $iban): string
    {
        $iban = strtoupper(str_replace(' ', '', trim($iban)));
        // The first checkout accepts CZK domestic transfers to a Czech bank account.
        if (preg_match('/^CZ[0-9]{22}$/D', $iban) !== 1) {
            throw new InvalidArgumentException('Zadej český IBAN pro platbu v Kč.');
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $modulo = 0;
        foreach (str_split($rearranged) as $character) {
            $digits = ctype_alpha($character) ? (string) (ord($character) - 55) : $character;
            foreach (str_split($digits) as $digit) {
                $modulo = ($modulo * 10 + (int) $digit) % 97;
            }
        }
        if ($modulo !== 1) {
            throw new InvalidArgumentException('IBAN nesouhlasí s kontrolními číslicemi.');
        }
        return $iban;
    }

    private static function text(string $value, int $max, string $label): string
    {
        $value = trim($value);
        if (preg_match('/^.{1,' . $max . '}$/usD', $value) !== 1 ||
            preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('Zkontroluj pole: ' . $label . '.');
        }
        return $value;
    }

    private static function validatedAccountDisplay(string $account, string $iban): string
    {
        $account = str_replace(' ', '', trim($account));
        if (preg_match('/^(?:([0-9]{1,6})-)?([0-9]{1,10})\/([0-9]{4})$/D', $account, $parts) !== 1) {
            throw new InvalidArgumentException('Zadej číslo účtu ve formátu předčíslí-číslo/kód banky.');
        }
        $prefix = isset($parts[1]) ? ltrim($parts[1], '0') : '';
        $number = ltrim($parts[2], '0');
        if ($prefix !== ltrim(substr($iban, 8, 6), '0') ||
            $number !== ltrim(substr($iban, 14, 10), '0') || $parts[3] !== substr($iban, 4, 4)) {
            throw new InvalidArgumentException('Číslo účtu neodpovídá zadanému IBANu.');
        }
        return $account;
    }
}
