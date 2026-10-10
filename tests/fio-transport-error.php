<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\FioTransportError;

$expected = [
    6 => 'DNS', 7 => '443', 28 => 'časový limit',
    35 => 'TLS', 60 => 'certifikátu', 77 => 'CA certifikáty',
];
foreach ($expected as $code => $diagnostic) {
    $message = FioTransportError::message($code);
    if (!str_contains($message, 'cURL ' . $code) || !str_contains($message, $diagnostic)) {
        throw new RuntimeException('Fio transport failure ' . $code . ' was not explained.');
    }
}
if (!str_contains(FioTransportError::message(23, true), '16 MB') ||
    str_contains(FioTransportError::message(23, true), 'cURL 23')) {
    throw new RuntimeException('The oversized Fio response needs a distinct message.');
}
if (!str_contains(FioTransportError::message(99), 'cURL 99')) {
    throw new RuntimeException('Unrecognized cURL errors need a safe numeric diagnostic.');
}
if (!str_contains(FioTransportError::message(28, false, 'čekání na odpověď banky'),
    'časový limit 40 sekund (čekání na odpověď banky)')) {
    throw new RuntimeException('A Fio timeout should identify which request phase stalled.');
}

echo "Fio transport diagnostics passed.\n";
