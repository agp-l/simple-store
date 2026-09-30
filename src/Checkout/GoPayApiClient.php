<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use Closure;
use RuntimeException;

/** Adapter around GoPay's maintained PHP SDK, with a transport seam for local tests. */
final class GoPayApiClient
{
    private ?Closure $transport;

    public function __construct(
        private string $goid,
        private string $clientId,
        private string $clientSecret,
        private bool $test = true,
        ?callable $transport = null
    ) {
        if (preg_match('/^[0-9]{1,30}$/D', $goid) !== 1 ||
            trim($clientId) === '' || trim($clientSecret) === '') {
            throw new RuntimeException('Přístup k platební bráně GoPay není nastaven.');
        }
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    /** Keep the original environment for an attempt even when new payments change mode. */
    public function forMode(bool $test): self
    {
        return new self($this->goid, $this->clientId, $this->clientSecret, $test, $this->transport);
    }

    public function create(array $payment): array
    {
        return $this->request('create', $payment);
    }

    public function status(string $id): array
    {
        if (preg_match('/^[0-9]{1,30}$/D', $id) !== 1) {
            throw new RuntimeException('Neplatné ID platby GoPay.');
        }
        return $this->request('status', $id);
    }

    private function request(string $operation, array|string $argument): array
    {
        if ($this->transport !== null) {
            $response = ($this->transport)(
                $operation, $argument, $this->goid, $this->clientId, $this->clientSecret, $this->test
            );
            if (!is_array($response)) throw new RuntimeException('GoPay nevrátila platnou odpověď.');
            return $response;
        }
        if (!class_exists(\GoPay\Api::class)) {
            throw new RuntimeException('Nainstaluj GoPay PHP SDK pomocí Composeru.');
        }
        $sdk = \GoPay\Api::payments([
            'goid' => $this->goid,
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'gatewayUrl' => $this->test
                ? 'https://gw.sandbox.gopay.com/api'
                : 'https://gate.gopay.cz/api',
            'language' => \GoPay\Definition\Language::CZECH,
            'timeout' => 15,
        ]);
        $response = $operation === 'create'
            ? $sdk->createPayment($argument)
            : $sdk->getStatus($argument);
        // The SDK returns a Response even for HTTP errors. Never include raw SDK
        // responses here: they can contain customer data or integration secrets.
        if (!$response->hasSucceed()) {
            $code = (int) $response->statusCode;
            $message = 'GoPay odmítla požadavek (HTTP ' . $code . ').';
            if ($operation === 'create' && $code >= 400 && $code < 500) {
                throw new GoPayApiRejectedException($message);
            }
            throw new RuntimeException($message);
        }
        if (!is_array($response->json)) {
            throw new RuntimeException('GoPay nevrátila platnou odpověď.');
        }
        return $response->json;
    }
}
