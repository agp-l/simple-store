<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

/** Translate cURL error numbers without exposing token-bearing request URLs. */
final class FioTransportError
{
    public static function message(int $code, bool $tooLarge = false): string
    {
        if ($tooLarge) {
            return 'Výpis Fio přesáhl bezpečnostní limit 16 MB; v aplikaci je potřeba zkrátit období importu.';
        }

        $reason = match ($code) {
            5 => 'Hosting nedokáže přeložit adresu své proxy.',
            6 => 'Hosting nedokáže přeložit fioapi.fio.cz (DNS).',
            7 => 'Hosting se nemůže připojit k fioapi.fio.cz na portu 443.',
            23 => 'Přenos odpovědi byl přerušen.',
            28 => 'Fio neodpovědělo v časovém limitu.',
            35 => 'Nepodařilo se navázat šifrované spojení TLS.',
            52 => 'Fio ukončilo spojení bez odpovědi.',
            56 => 'Spojení se přerušilo při přijímání odpovědi.',
            60 => 'Ověření certifikátu Fio selhalo; na hostingu zkontroluj důvěryhodné CA certifikáty.',
            77 => 'Na hostingu nelze načíst důvěryhodné CA certifikáty.',
            default => 'Spojení s Fio selhalo. Podle čísla chyby zkontroluj síťové nastavení hostingu.',
        };

        return 'Výpis Fio nelze načíst (cURL ' . $code . '): ' . $reason;
    }
}
