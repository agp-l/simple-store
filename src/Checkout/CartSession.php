<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use RuntimeException;

/** An anonymous cart has its own short-lived PHP session, independent of either login role. */
final class CartSession
{
    private const SESSION_NAME = 'simple_store_cart';
    private const MAX_LINES = 40;
    private const MAX_QUANTITY = 99;
    private const MAX_UNITS = 200;
    private ?string $requestSessionId = null;

    public function __construct(private string $cookiePath)
    {
        if (!str_starts_with($cookiePath, '/') || !str_ends_with($cookiePath, '/')) {
            throw new InvalidArgumentException('Cart cookie path must be an absolute directory.');
        }
    }

    /** Reading also creates the CSRF token used by anonymous POST forms. */
    public function state(): array
    {
        return $this->access(static fn (array &$session): array => [
            'csrf' => $session['checkout_csrf'],
            'checkout_key' => $session['checkout_key'],
            'items' => $session['checkout_items'],
            'delivery' => $session['checkout_delivery'],
        ]);
    }

    public function token(): string
    {
        return $this->state()['csrf'];
    }

    public function validToken(mixed $token): bool
    {
        return is_string($token) && hash_equals($this->token(), $token);
    }

    /** One immutable submission identity until an order succeeds and the cart is cleared. */
    public function checkoutKey(): string
    {
        return $this->state()['checkout_key'];
    }

    public function count(): int
    {
        return array_sum(array_column($this->state()['items'], 'quantity'));
    }

    /** Store identities only. Names and prices must be looked up on every read. */
    public function add(string $key, string $language, array $options, int $quantity = 1): string
    {
        self::validQuantity($quantity);
        $options = self::normalizeOptions($options);
        $lineId = self::lineId($key, $language, $options);
        $this->access(static function (array &$session) use ($lineId, $key, $language, $options, $quantity): void {
            $items = &$session['checkout_items'];
            $current = (int) ($items[$lineId]['quantity'] ?? 0);
            $next = $current + $quantity;
            if ($next > self::MAX_QUANTITY || count($items) >= self::MAX_LINES && $current === 0 ||
                array_sum(array_column($items, 'quantity')) + $quantity > self::MAX_UNITS) {
                throw new InvalidArgumentException('Košík je plný nebo je požadovaný počet kusů příliš vysoký.');
            }
            $items[$lineId] = compact('key', 'language', 'options') + ['quantity' => $next];
            $session['checkout_key'] = bin2hex(random_bytes(32));
        });
        return $lineId;
    }

    public function update(string $lineId, int $quantity): void
    {
        self::validLineId($lineId);
        self::validQuantity($quantity);
        $this->access(static function (array &$session) use ($lineId, $quantity): void {
            $items = &$session['checkout_items'];
            if (!isset($items[$lineId])) {
                throw new InvalidArgumentException('Položka již není v košíku.');
            }
            $units = array_sum(array_column($items, 'quantity')) - $items[$lineId]['quantity'] + $quantity;
            if ($units > self::MAX_UNITS) {
                throw new InvalidArgumentException('Košík může obsahovat nejvýše 200 kusů.');
            }
            if ($items[$lineId]['quantity'] !== $quantity) {
                $items[$lineId]['quantity'] = $quantity;
                $session['checkout_key'] = bin2hex(random_bytes(32));
            }
        });
    }

    public function remove(string $lineId): void
    {
        self::validLineId($lineId);
        $this->access(static function (array &$session) use ($lineId): void {
            if (isset($session['checkout_items'][$lineId])) {
                unset($session['checkout_items'][$lineId]);
                $session['checkout_key'] = bin2hex(random_bytes(32));
            }
        });
    }

    /** Passing null clears contact and shipping input without touching cart lines. */
    public function setDelivery(?array $delivery): void
    {
        if ($delivery !== null) {
            $delivery = self::normalizeDelivery($delivery);
        }
        $this->access(static function (array &$session) use ($delivery): void {
            if ($session['checkout_delivery'] !== $delivery) {
                $session['checkout_delivery'] = $delivery;
                $session['checkout_key'] = bin2hex(random_bytes(32));
            }
        });
    }

    public function clear(): void
    {
        $this->access(static function (array &$session): void {
            $session['checkout_items'] = [];
            $session['checkout_delivery'] = null;
            $session['checkout_csrf'] = bin2hex(random_bytes(32));
            $session['checkout_key'] = bin2hex(random_bytes(32));
        });
    }

    /** Stable identity across form submissions and reordered option groups. */
    public static function lineId(string $key, string $language, array $options): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
            preg_match('/^[a-z]{2}$/D', $language) !== 1) {
            throw new InvalidArgumentException('Neplatný identifikátor produktu.');
        }
        $options = self::normalizeOptions($options);
        return hash('sha256', $language . "\0" . $key . "\0" .
            json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string|int, string> */
    private static function normalizeOptions(array $options): array
    {
        if (count($options) > 8) {
            throw new InvalidArgumentException('Produkt má příliš mnoho vybraných možností.');
        }
        $normalized = [];
        foreach ($options as $name => $value) {
            if ((!is_string($name) && !is_int($name)) || !is_string($value)) {
                throw new InvalidArgumentException('Neplatný výběr produktu.');
            }
            $name = trim((string) $name);
            $value = trim($value);
            if ($name === '' || $value === '' || strlen($name) > 80 || strlen($value) > 120 ||
                preg_match('/[\x00-\x1f\x7f]/', $name . $value) ||
                preg_match('//u', $name . $value) !== 1 || isset($normalized[$name])) {
                throw new InvalidArgumentException('Neplatný výběr produktu.');
            }
            $normalized[$name] = $value;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function normalizeDelivery(array $fields): array
    {
        $limits = ['method' => 20, 'name' => 120, 'email' => 254, 'phone' => 40,
            'street' => 190, 'city' => 120, 'postal_code' => 20, 'country' => 2,
            'pickup_point' => 190];
        if (array_diff(array_keys($fields), array_keys($limits)) !== []) {
            throw new InvalidArgumentException('Neplatný údaj doručení.');
        }
        $result = [];
        foreach ($limits as $key => $limit) {
            $value = $fields[$key] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException('Neplatný údaj doručení.');
            }
            $value = trim($value);
            if (strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value) ||
                preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException('Neplatný údaj doručení.');
            }
            $result[$key] = $value;
        }
        if (!in_array($result['method'], ['home', 'pickup'], true) || $result['name'] === '' ||
            filter_var($result['email'], FILTER_VALIDATE_EMAIL) === false ||
            $result['phone'] === '' || $result['country'] !== 'CZ' ||
            ($result['method'] === 'home' && ($result['street'] === '' || $result['city'] === '' ||
                $result['postal_code'] === '')) ||
            ($result['method'] === 'pickup' && $result['pickup_point'] === '')) {
            throw new InvalidArgumentException('Doplň kontakt a adresu nebo výdejní místo v České republice.');
        }
        return $result;
    }

    private static function validQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException('Počet kusů musí být od 1 do 99.');
        }
    }

    private static function validLineId(string $lineId): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $lineId) !== 1) {
            throw new InvalidArgumentException('Neplatná položka košíku.');
        }
    }

    private function access(callable $operation): mixed
    {
        if (session_status() !== PHP_SESSION_NONE) {
            throw new RuntimeException('Close the active login session before accessing the cart.');
        }
        // PHP retains the previous session ID after session_write_close().
        // The in-request ID below preserves an anonymous cart before its cookie arrives.
        session_id('');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name(self::SESSION_NAME);
        if ($this->requestSessionId !== null) {
            session_id($this->requestSessionId);
        }
        session_set_cookie_params([
            'lifetime' => 0, 'path' => $this->cookiePath,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('PHP sessions are unavailable. Check the session directory.');
        }
        $this->requestSessionId = session_id();
        try {
            if (!is_string($_SESSION['checkout_csrf'] ?? null) ||
                preg_match('/^[a-f0-9]{64}$/D', $_SESSION['checkout_csrf']) !== 1) {
                $_SESSION['checkout_csrf'] = bin2hex(random_bytes(32));
            }
            if (!is_string($_SESSION['checkout_key'] ?? null) ||
                preg_match('/^[a-f0-9]{64}$/D', $_SESSION['checkout_key']) !== 1) {
                $_SESSION['checkout_key'] = bin2hex(random_bytes(32));
            }
            if (!is_array($_SESSION['checkout_items'] ?? null)) {
                $_SESSION['checkout_items'] = [];
            }
            if (!is_array($_SESSION['checkout_delivery'] ?? null)) {
                $_SESSION['checkout_delivery'] = null;
            }
            return $operation($_SESSION);
        } finally {
            session_write_close();
            session_id('');
        }
    }
}
