<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Rendering\PageRenderer;

/** HTTP boundary for the cart, delivery form, bank transfer and order receipt. */
final class CheckoutController
{
    private string $cartUrl;
    private string $checkoutUrl;
    private array $shippingOptions;
    private string $termsUrl;

    public function __construct(
        private UrlManager $url,
        private PageRenderer $renderer,
        private array $shared,
        private CartSession $cart,
        private CartService $cartService,
        private ShippingPolicy $shipping,
        private OrderRepository $orders,
        private ?BankTransferPayment $bank,
        private ?int $customerId,
        string $termsUrl,
        private bool $allowLocalPreview = false
    ) {
        $this->cartUrl = $url->path('kosik');
        $this->checkoutUrl = $url->path('pokladna');
        // A pickup point needs a real selector and verified address. The first checkout
        // offers only the configured home method, even if a pickup price is present.
        $this->shippingOptions = array_values(array_filter($shipping->options(),
            static fn (array $option): bool => $option['code'] === 'home'));
        $termsUrl = trim($termsUrl);
        $this->termsUrl = str_starts_with($termsUrl, $url->getBasePath()) &&
            preg_match('~^/(?:[a-z0-9]+(?:-[a-z0-9]+)*)(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*/?$~D', $termsUrl) === 1
                ? $termsUrl : '';
    }

    public function handle(array $route): void
    {
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: same-origin');
        header('X-Content-Type-Options: nosniff');
        $name = $route['name'] ?? '';
        if ($name === 'order') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !$this->orders->installed()) {
                $this->renderer->render('not-found', $this->shared, 404);
                return;
            }
            $order = $this->orders->findByToken((string) ($route['token'] ?? ''));
            if ($order === null) {
                $this->renderer->render('not-found', $this->shared, 404);
                return;
            }
            $this->renderer->render('complete', $this->shared + [
                'title' => 'Objednávka ' . $order['order_number'] . ' — dobrodruzi.cz',
                'privatePage' => true, 'compactHeader' => true, 'order' => $order,
                'orderUrl' => $this->url->path('objednavka/' . $order['order_token']),
                'bankPayment' => ($order['payment_method'] ?? '') === 'bank_transfer'
                    ? BankTransferPayment::fromOrder($order)->details($order) : [],
            ]);
            return;
        }
        if (!in_array($name, ['cart', 'checkout'], true)) {
            $this->renderer->render('not-found', $this->shared, 404);
            return;
        }
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->cart->validToken($_POST['csrf'] ?? null)) {
                $message = isset($_COOKIE['simple_store_cart'])
                    ? 'Platnost formuláře vypršela. Obnov stránku a odešli formulář znovu.'
                    : 'Prohlížeč neposlal cookie košíku. Povol soubory cookie a obnov stránku.';
                error_log('Cart CSRF rejected; cart cookie ' . (isset($_COOKIE['simple_store_cart']) ? 'present' : 'missing') . '.');
                $this->render($name === 'cart' ? 'cart' : 'shipping', $message, 403);
                return;
            }
            try {
                $action = self::field('action');
                if ($name === 'cart') {
                    $this->changeCart($action);
                    $this->redirect($this->cartUrl);
                } elseif ($action === 'delivery') {
                    $this->saveDelivery();
                    $this->redirect($this->checkoutUrl . '?step=payment');
                } elseif ($action === 'place') {
                    $this->placeOrder();
                    return;
                } else {
                    throw new InvalidArgumentException('Neznámá akce pokladny.');
                }
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $this->render($name === 'cart' ? 'cart' :
                    ((($_POST['action'] ?? '') === 'place') ? 'review' : 'shipping'), $error, 422);
                return;
            }
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            $this->renderer->render('not-found', $this->shared, 405);
            return;
        }
        if ($name === 'cart') {
            $this->render('cart');
            return;
        }
        $step = $_GET['step'] ?? 'shipping';
        if (!is_string($step) || !in_array($step, ['shipping', 'payment', 'review'], true)) {
            $this->renderer->render('not-found', $this->shared, 404);
            return;
        }
        $summary = $this->cartService->summary($this->cart);
        if (!$summary['can_continue']) {
            $this->redirect($this->cartUrl);
        }
        if ($step !== 'shipping' && !is_array($this->cart->state()['delivery'])) {
            $this->redirect($this->checkoutUrl . '?step=shipping');
        }
        $this->render($step);
    }

    private function changeCart(string $action): void
    {
        if ($action === 'add') {
            $key = self::field('product_key');
            $language = self::field('language');
            $choices = $_POST['options'] ?? [];
            if (!is_array($choices) || count($choices) > 8) {
                throw new InvalidArgumentException('Neplatný výběr produktu.');
            }
            $this->cartService->add($this->cart, $key, $language, array_values($choices), self::quantity());
            return;
        }
        if ($action === 'update') {
            $this->cart->update(self::field('line_id'), self::quantity());
            return;
        }
        if ($action === 'remove') {
            $this->cart->remove(self::field('line_id'));
            return;
        }
        throw new InvalidArgumentException('Neznámá akce košíku.');
    }

    private function saveDelivery(): void
    {
        if (!$this->cartService->summary($this->cart)['can_continue']) {
            throw new InvalidArgumentException('Před pokračováním zkontrolujte košík.');
        }
        $method = self::field('method');
        if ($method !== 'home' || $this->shipping->quote($method) === null ||
            $this->shippingOptions === []) {
            throw new InvalidArgumentException('Vybraný způsob dopravy není dostupný.');
        }
        $fields = [];
        foreach (['method', 'name', 'email', 'phone', 'street', 'city', 'postal_code', 'country'] as $field) {
            $fields[$field] = self::field($field);
        }
        $this->cart->setDelivery($fields);
    }

    private function placeOrder(): void
    {
        $testOrder = $this->testCheckout();
        if (!$testOrder && $this->termsUrl !== '' && self::field('terms') !== '1') {
            throw new InvalidArgumentException('Pro odeslání objednávky potvrďte obchodní podmínky.');
        }
        $summary = $this->cartService->summary($this->cart);
        $delivery = $this->cart->state()['delivery'];
        $price = is_array($delivery) && ($delivery['method'] ?? '') === 'home'
            ? $this->shipping->quote('home') : null;
        if (!$summary['can_continue'] || !is_array($delivery) || $price === null ||
            (!$testOrder && $this->bank === null) ||
            !$this->orders->installed()) {
            throw new InvalidArgumentException('Objednávku nyní nelze dokončit. Zkontrolujte košík, doručení a nastavení obchodu.');
        }
        if ($summary['subtotal_czk'] + $price > 9999999) {
            throw new InvalidArgumentException('Celková částka objednávky přesahuje dostupný limit.');
        }
        $label = $this->shippingOptions[0]['label'] ?? null;
        if (!is_string($label)) {
            throw new InvalidArgumentException('Doprava není dostupná.');
        }
        $shipping = $delivery;
        $shipping['label'] = $label;
        $shipping['recipient'] = $delivery['name'];
        $order = $this->orders->create($this->customerId, $delivery['email'], $summary['items'],
            $shipping, $price, $this->cart->checkoutKey(), $testOrder);
        $this->cart->clear();
        $this->redirect($this->url->path('objednavka/' . $order['order_token']));
    }

    private function render(string $step, string $error = '', int $status = 200): void
    {
        $summary = $this->cartService->summary($this->cart);
        $delivery = $this->cart->state()['delivery'] ?? null;
        $delivery = is_array($delivery) ? $delivery : [];
        if ($step === 'shipping' && $error !== '' && is_array($_POST)) {
            // Keep submitted contact details visible after a validation error.
            foreach (['method', 'name', 'email', 'phone', 'street', 'city', 'postal_code', 'country'] as $field) {
                if (is_string($_POST[$field] ?? null)) $delivery[$field] = $_POST[$field];
            }
        }
        $price = ($delivery['method'] ?? '') === 'home' ? $this->shipping->quote('home') : null;
        if ($price !== null && $summary['subtotal_czk'] !== null &&
            $summary['subtotal_czk'] + $price > 9999999 && $error === '') {
            $error = 'Celková částka včetně dopravy přesahuje limit objednávky. Uprav počet kusů v košíku.';
        }
        $installed = $this->orders->installed();
        $shippingConfigured = $this->shippingOptions !== [];
        $testCheckout = $this->testCheckout();
        $ready = $summary['can_continue'] && $shippingConfigured && $installed && $price !== null &&
            $summary['subtotal_czk'] + $price <= 9999999 &&
            ($testCheckout || $this->bank !== null);
        $data = array_merge($this->shared, [
            'title' => match ($step) {
                'cart' => 'Košík — dobrodruzi.cz',
                'shipping' => 'Doprava — dobrodruzi.cz',
                'payment' => 'Platba — dobrodruzi.cz',
                default => 'Kontrola objednávky — dobrodruzi.cz',
            },
            'privatePage' => true, 'compactHeader' => true,
            'cartUrl' => $this->cartUrl, 'checkoutUrl' => $this->checkoutUrl,
            'cartToken' => $this->cart->token(), 'cartCount' => $this->cart->count(),
            'checkout' => $summary, 'delivery' => $delivery,
            'shippingOptions' => $this->shippingOptions, 'selectedShippingPrice' => $price,
            'shippingConfigured' => $shippingConfigured, 'bankConfigured' => $this->bank !== null,
            'checkoutReady' => $ready, 'testCheckout' => $testCheckout, 'termsUrl' => $this->termsUrl,
            'error' => $error, 'step' => $step,
            'setupNotice' => !$installed ? 'Pro objednávky znovu importuj aktuální database/schema.sql.' : '',
        ]);
        $this->renderer->render($step, $data, $status);
    }

    private function testCheckout(): bool
    {
        return $this->allowLocalPreview && $this->bank === null;
    }

    private static function field(string $name): string
    {
        $value = $_POST[$name] ?? null;
        if (!is_string($value) || strlen($value) > 1000) {
            throw new InvalidArgumentException('Neplatné pole formuláře: ' . $name . '.');
        }
        return trim($value);
    }

    private static function quantity(): int
    {
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 99]]);
        if ($quantity === false) {
            throw new InvalidArgumentException('Počet kusů musí být od 1 do 99.');
        }
        return $quantity;
    }

    private function redirect(string $target): void
    {
        header('Location: ' . $target, true, 303);
        exit;
    }
}
