<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Rendering\PageRenderer;
use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Accounting\InvoiceRepository;
use Throwable;

/** HTTP boundary for the cart, delivery form, payment choice and order receipt. */
final class CheckoutController
{
    private string $cartUrl;
    private string $checkoutUrl;
    private array $shippingOptions;
    private string $termsUrl;
    private PacketaPickupPoint $packeta;
    private PplPickupPoint $ppl;
    private GlsPickupPoint $gls;
    private BalikovnaPickupPoint $balikovna;

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
        private array $customerProfile = [],
        private array $customerAddresses = [],
        ?PacketaPickupPoint $packeta = null,
        ?PplPickupPoint $ppl = null,
        ?GlsPickupPoint $gls = null,
        ?BalikovnaPickupPoint $balikovna = null,
        private ?OrderMailQueue $mailQueue = null,
        private string $mailSender = '',
        private ?InvoiceRepository $invoices = null,
        private ?ComgatePaymentService $comgate = null,
        private ?GoPayPaymentService $gopay = null,
        private ?BTCPayPaymentService $btcpay = null,
        private ?OrderTrackingRepository $orderTracking = null
    ) {
        $this->cartUrl = $url->path('kosik');
        $this->checkoutUrl = $url->path('pokladna');
        $this->packeta = $packeta ?? new PacketaPickupPoint();
        $this->ppl = $ppl ?? new PplPickupPoint();
        $this->gls = $gls ?? new GlsPickupPoint();
        $this->balikovna = $balikovna ?? new BalikovnaPickupPoint();
        $this->shippingOptions = $shipping->options();
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
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if (!in_array($method, ['GET', 'POST'], true) || !$this->orders->installed()) {
                $this->renderer->render('not-found', $this->shared, 404);
                return;
            }
            $order = $this->orders->findByToken((string) ($route['token'] ?? ''));
            if ($order === null) {
                $this->renderer->render('not-found', $this->shared, 404);
                return;
            }
            $paymentNotice = '';
            $responseStatus = 200;
            $gopayGatewayUrl = '';
            if ($method === 'POST') {
                if (!$this->cart->validToken($_POST['csrf'] ?? null)) {
                    $paymentNotice = 'Platnost formuláře vypršela. Obnov stránku a zkus platbu znovu.';
                    $responseStatus = 403;
                } elseif (!in_array($_POST['action'] ?? null, ['comgate_pay', 'gopay_pay', 'btcpay_pay'], true) ||
                    str_replace('_pay', '', (string) $_POST['action']) !== ($order['payment_method'] ?? '') ||
                    ($order['payment_status'] ?? '') === 'paid') {
                    $this->renderer->render('not-found', $this->shared, 404);
                    return;
                } elseif (match ($_POST['action']) {
                    'comgate_pay' => $this->comgate, 'gopay_pay' => $this->gopay,
                    default => $this->btcpay,
                } === null) {
                    $paymentNotice = 'Online platba je nyní nedostupná. Kontaktujte prosím obchod.';
                    $responseStatus = 503;
                } else {
                    try {
                        $service = match ($_POST['action']) {
                            'comgate_pay' => $this->comgate, 'gopay_pay' => $this->gopay,
                            default => $this->btcpay,
                        };
                        if ($_POST['action'] === 'btcpay_pay') $order = $service->refresh($order);
                        $gatewayUrl = $service->initiate($order);
                        if ($_POST['action'] === 'gopay_pay') {
                            $gopayGatewayUrl = $gatewayUrl;
                        } else {
                            $this->redirect($gatewayUrl);
                        }
                    } catch (Throwable $error) {
                        error_log('Online payment ' . (int) $order['id'] . ' initiation failed: ' . $error->getMessage());
                        $paymentNotice = 'Platební bránu se nepodařilo otevřít. Objednávka zůstala uložená a není zaplacená. Zkus to později nebo kontaktuj obchod.';
                        $responseStatus = 503;
                    }
                }
            } elseif (($order['payment_method'] ?? '') === 'comgate' &&
                ($_GET['comgate_return'] ?? '') === '1' && $this->comgate !== null) {
                try {
                    $order = $this->comgate->refresh($order);
                } catch (Throwable $error) {
                    error_log('Comgate payment ' . (int) $order['id'] . ' status check failed: ' . $error->getMessage());
                    $paymentNotice = 'Stav platby se zatím nepodařilo ověřit. Obnov stránku později.';
                }
            } elseif (($order['payment_method'] ?? '') === 'gopay' &&
                ($_GET['gopay_return'] ?? '') === '1' && $this->gopay !== null) {
                try {
                    $order = $this->gopay->refresh($order);
                } catch (Throwable $error) {
                    error_log('GoPay payment ' . (int) $order['id'] . ' status check failed: ' . $error->getMessage());
                    $paymentNotice = 'Stav platby se zatím nepodařilo ověřit. Obnov stránku později.';
                }
            } elseif (($order['payment_method'] ?? '') === 'btcpay' && $this->btcpay !== null) {
                try {
                    $order = $this->btcpay->refresh($order);
                } catch (Throwable $error) {
                    error_log('BTCPay payment ' . (int) $order['id'] . ' status check failed: ' . $error->getMessage());
                    $paymentNotice = 'Stav bitcoinové platby se zatím nepodařilo ověřit. Obnov stránku později.';
                }
            }
            if ($method === 'GET' && ($_GET['comgate_error'] ?? '') === '1' && $paymentNotice === '') {
                $paymentNotice = 'Platební bránu se nepodařilo otevřít. Objednávka zůstala uložená. Platbu lze zkusit znovu.';
            }
            if ($method === 'GET' && ($_GET['gopay_error'] ?? '') === '1' && $paymentNotice === '') {
                $paymentNotice = 'Platební bránu se nepodařilo otevřít. Objednávka zůstala uložená. Platbu lze zkusit znovu.';
            }
            if ($method === 'GET' && ($_GET['btcpay_error'] ?? '') === '1' && $paymentNotice === '') {
                $paymentNotice = 'Bitcoinovou platbu se nepodařilo otevřít. Objednávka zůstala uložená. Prověř stav před opakováním.';
            }
            $invoice = $this->invoices?->byOrder((int) $order['id']);
            if ($method === 'GET' && ($_GET['invoice'] ?? '') === '1') {
                if ($invoice === null) {
                    $this->renderer->render('not-found', $this->shared, 404);
                    return;
                }
                $selectedInvoice = $invoice;
                require __DIR__ . '/../../view/admin/invoice-print.php';
                return;
            }
            $this->renderOrder($order, $paymentNotice, $responseStatus, $gopayGatewayUrl, $invoice);
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
                } elseif ($action === 'payment') {
                    $this->savePayment();
                    $this->redirect($this->checkoutUrl . '?step=review');
                } elseif ($action === 'place') {
                    $this->placeOrder();
                    return;
                } else {
                    throw new InvalidArgumentException('Neznámá akce pokladny.');
                }
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $this->render($name === 'cart' ? 'cart' :
                    match ($_POST['action'] ?? '') {
                        'place' => 'review', 'payment' => 'payment', default => 'shipping',
                    }, $error, 422);
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
        if ($this->shipping->method($method) === null) {
            throw new InvalidArgumentException('Vybraný způsob dopravy není dostupný.');
        }
        $fields = [];
        foreach (['method', 'name', 'email', 'phone', 'street', 'city', 'postal_code', 'country',
            'pickup_point', 'pickup_address', 'pickup_code', 'pickup_postal_code'] as $field) {
            $fields[$field] = str_starts_with($field, 'pickup_') && !isset($_POST[$field])
                ? '' : self::field($field);
        }
        if (ShippingPolicy::isPickup($method)) {
            $fields['street'] = $fields['city'] = $fields['postal_code'] = '';
            if ($method !== 'balikovna_pickup') $fields['pickup_postal_code'] = '';
            if ($method === 'zasilkovna_pickup' && $this->packeta->isConfigured()) {
                $fields = array_replace($fields, $this->packeta->verify(self::field('packeta_point_id')));
            } elseif ($method === 'ppl_pickup' && $this->ppl->isConfigured()) {
                $fields = array_replace($fields, $this->ppl->selection(
                    self::field('ppl_point_code'), self::field('ppl_point_name'),
                    self::field('ppl_point_address'), self::field('ppl_point_country')
                ));
            } elseif ($method === 'gls_pickup') {
                $fields = array_replace($fields, $this->gls->selection(
                    self::field('gls_point_id'), self::field('gls_point_name'),
                    self::field('gls_point_address'), self::field('gls_point_country')
                ));
            } elseif ($method === 'balikovna_pickup') {
                $fields = array_replace($fields, $this->balikovna->selection(
                    self::field('balikovna_point_id'), self::field('balikovna_point_name'),
                    self::field('balikovna_point_address'), self::field('balikovna_point_zip'),
                    self::field('balikovna_point_type')
                ));
            }
        } else {
            $fields['pickup_point'] = $fields['pickup_address'] = $fields['pickup_code'] =
                $fields['pickup_postal_code'] = '';
        }
        $this->cart->setDelivery($fields);
    }

    private function savePayment(): void
    {
        if (!$this->cartService->summary($this->cart)['can_continue'] ||
            !is_array($this->cart->state()['delivery'])) {
            throw new InvalidArgumentException('Před výběrem platby zkontrolujte dopravu a košík.');
        }
        $method = self::field('payment_method');
        if (!$this->paymentAvailable($method)) {
            throw new InvalidArgumentException('Vybraný způsob platby není dostupný.');
        }
        if ($method === 'gopay' && strlen((string) ($this->cart->state()['delivery']['email'] ?? '')) > 128) {
            throw new InvalidArgumentException('Pro GoPay zkraťte e-mail na nejvýše 128 znaků. Údaj upravíte v dopravě.');
        }
        $this->cart->setPaymentMethod($method);
    }

    private function selectedPaymentMethod(): string
    {
        $selected = $this->cart->state()['payment_method'];
        if (is_string($selected)) return $selected;
        if ($this->bank !== null) return 'bank_transfer';
        if ($this->comgate !== null && $this->comgate->canInitiate()) return 'comgate';
        if ($this->gopay !== null && $this->gopay->canInitiate()) return 'gopay';
        return 'btcpay';
    }

    private function paymentAvailable(string $method): bool
    {
        return $method === 'bank_transfer' && $this->bank !== null ||
            $method === 'comgate' && $this->comgate !== null && $this->comgate->canInitiate() ||
            $method === 'gopay' && $this->gopay !== null && $this->gopay->canInitiate() ||
            $method === 'btcpay' && $this->btcpay !== null && $this->btcpay->canInitiate();
    }

    private function placeOrder(): void
    {
        $paymentMethod = $this->selectedPaymentMethod();
        if ($this->termsUrl !== '' && self::field('terms') !== '1') {
            throw new InvalidArgumentException('Pro odeslání objednávky potvrďte obchodní podmínky.');
        }
        $summary = $this->cartService->summary($this->cart);
        $delivery = $this->cart->state()['delivery'];
        if ($paymentMethod === 'gopay' && is_array($delivery) &&
            strlen((string) ($delivery['email'] ?? '')) > 128) {
            throw new InvalidArgumentException('Pro GoPay zkraťte e-mail na nejvýše 128 znaků. Údaj upravíte v dopravě.');
        }
        $methodCode = is_array($delivery) ? (string) ($delivery['method'] ?? '') : '';
        $selected = ShippingPolicy::known($methodCode) ? $this->shipping->method($methodCode) : null;
        $price = $selected['price_czk'] ?? null;
        if (!$summary['can_continue'] || !is_array($delivery) || $price === null ||
            !$this->paymentAvailable($paymentMethod) ||
            !$this->orders->installed()) {
            throw new InvalidArgumentException('Objednávku nyní nelze dokončit. Zkontrolujte košík, doručení a nastavení obchodu.');
        }
        if ($methodCode === 'zasilkovna_pickup' && $this->packeta->isConfigured()) {
            $delivery = array_replace($delivery, $this->packeta->verify((string) ($delivery['pickup_code'] ?? '')));
        } elseif ($methodCode === 'ppl_pickup' && $this->ppl->isConfigured()) {
            $delivery = array_replace($delivery, $this->ppl->selection(
                (string) ($delivery['pickup_code'] ?? ''), (string) ($delivery['pickup_point'] ?? ''),
                (string) ($delivery['pickup_address'] ?? ''), (string) ($delivery['country'] ?? '')
            ));
        } elseif ($methodCode === 'gls_pickup') {
            $delivery = array_replace($delivery, $this->gls->selection(
                (string) ($delivery['pickup_code'] ?? ''), (string) ($delivery['pickup_point'] ?? ''),
                (string) ($delivery['pickup_address'] ?? ''), (string) ($delivery['country'] ?? '')
            ));
        } elseif ($methodCode === 'balikovna_pickup') {
            $delivery = array_replace($delivery, $this->balikovna->selection(
                (string) ($delivery['pickup_code'] ?? ''), (string) ($delivery['pickup_point'] ?? ''),
                (string) ($delivery['pickup_address'] ?? ''),
                (string) ($delivery['pickup_postal_code'] ?? ''), 'BALIKOVNY'
            ));
        }
        if ($summary['subtotal_czk'] + $price > 9999999) {
            throw new InvalidArgumentException('Celková částka objednávky přesahuje dostupný limit.');
        }
        $label = $selected['label'] ?? null;
        if (!is_string($label)) {
            throw new InvalidArgumentException('Doprava není dostupná.');
        }
        $shipping = $delivery;
        $shipping['label'] = $label;
        $shipping['recipient'] = $delivery['name'];
        if ($methodCode === 'zasilkovna_pickup') {
            $shipping['pickup_verified'] = $this->packeta->isConfigured();
            $shipping['pickup_source'] = $this->packeta->isConfigured() ? 'packeta_widget' : 'manual';
        }
        if ($methodCode === 'ppl_pickup' && $this->ppl->isConfigured()) $shipping['pickup_source'] = 'ppl_widget';
        if ($methodCode === 'gls_pickup') $shipping['pickup_source'] = 'gls_map';
        if ($methodCode === 'balikovna_pickup') $shipping['pickup_source'] = 'balikovna_map';
        $order = $this->orders->create($this->customerId, $delivery['email'], $summary['items'],
            $shipping, $price, $this->cart->checkoutKey(), $paymentMethod);
        if ($this->mailQueue !== null) {
            try {
                $gateway = match ($paymentMethod) {
                    'comgate' => $this->comgate, 'gopay' => $this->gopay,
                    'btcpay' => $this->btcpay, default => null,
                };
                $orderUrl = $gateway !== null
                    ? $gateway->receiptUrl($order, $this->url->getLanguage()) : '';
                $messageId = $this->mailQueue->enqueueOrder($order, $orderUrl);
                if ($messageId !== null && $this->mailQueue->sender($this->mailSender) !== '') {
                    $this->mailQueue->dispatch($messageId, $this->mailSender, true);
                }
            } catch (Throwable $error) {
                error_log('Order ' . $order['order_number'] . ' notification failed: ' . $error->getMessage());
            }
        }
        $this->cart->clear();
        if (in_array($paymentMethod, ['comgate', 'gopay', 'btcpay'], true)) {
            try {
                $gateway = match ($paymentMethod) {
                    'comgate' => $this->comgate, 'gopay' => $this->gopay,
                    default => $this->btcpay,
                };
                $gatewayUrl = $gateway->initiate($order);
                if ($paymentMethod === 'gopay') {
                    $this->renderOrder($order, '', 200, $gatewayUrl);
                    return;
                }
                $this->redirect($gatewayUrl);
            } catch (Throwable $error) {
                error_log($paymentMethod . ' payment ' . (int) $order['id'] . ' initiation failed: ' . $error->getMessage());
                $this->redirect($this->url->path('objednavka/' . $order['order_token']) . '?' . $paymentMethod . '_error=1');
            }
        }
        $this->redirect($this->url->path('objednavka/' . $order['order_token']));
    }

    /** The GoPay hosted gateway is opened by a POST form, even on a repeated payment attempt. */
    private function renderOrder(
        array $order,
        string $paymentNotice = '',
        int $responseStatus = 200,
        string $gopayGatewayUrl = '',
        ?array $invoice = null
    ): void {
        if ($invoice === null) $invoice = $this->invoices?->byOrder((int) $order['id']);
        $order['customer_tracking'] = ['number' => '', 'url' => ''];
        if ($this->orderTracking !== null &&
            in_array($order['status'] ?? '', ['ready_to_ship', 'shipped', 'completed'], true)) {
            try {
                $order['customer_tracking'] = $this->orderTracking->forOrder((int) $order['id']);
            } catch (Throwable $error) {
                error_log('Order ' . (int) $order['id'] . ' tracking lookup failed: ' . $error->getMessage());
            }
        }
        $orderUrl = $this->url->path('objednavka/' . $order['order_token']);
        $this->renderer->render('complete', array_replace($this->shared, [
            'title' => 'Objednávka ' . $order['order_number'] . ' — dobrodruzi.cz',
            'privatePage' => true, 'compactHeader' => true, 'order' => $order,
            'cartCount' => $this->cart->count(),
            'orderUrl' => $orderUrl,
            'invoiceUrl' => $invoice !== null ? $orderUrl . '?invoice=1' : '',
            'bankPayment' => ($order['payment_method'] ?? '') === 'bank_transfer'
                ? BankTransferPayment::fromOrder($order)->details($order) : [],
            'comgateAvailable' => $this->comgate !== null && $this->comgate->canInitiate(),
            'comgateState' => $this->comgate !== null && $this->comgate->installed()
                ? $this->comgate->state((int) $order['id']) : null,
            'gopayAvailable' => $this->gopay !== null && $this->gopay->canInitiate(),
            'gopayState' => $this->gopay !== null && $this->gopay->installed()
                ? $this->gopay->state((int) $order['id']) : null,
            'btcpayAvailable' => $this->btcpay !== null && $this->btcpay->canInitiate(),
            'btcpayState' => $this->btcpay !== null && $this->btcpay->installed()
                ? $this->btcpay->state((int) $order['id']) : null,
            'gopayGatewayUrl' => $gopayGatewayUrl,
            'paymentNotice' => $paymentNotice, 'cartToken' => $this->cart->token(),
        ]), $responseStatus);
    }

    private function render(string $step, string $error = '', int $status = 200): void
    {
        $summary = $this->cartService->summary($this->cart);
        $delivery = $this->cart->state()['delivery'] ?? null;
        $delivery = is_array($delivery) ? $delivery : [];
        if ($step === 'shipping' && $this->customerId !== null) {
            $delivery += [
                'name' => (string) ($this->customerProfile['display_name'] ?? ''),
                'email' => (string) ($this->customerProfile['email'] ?? ''),
                'phone' => (string) ($this->customerProfile['phone'] ?? ''),
            ];
            $chosen = $_GET['address'] ?? null;
            if (is_string($chosen) && ctype_digit($chosen)) {
                foreach ($this->customerAddresses as $address) {
                    if ((int) ($address['id'] ?? 0) !== (int) $chosen ||
                        ($address['country'] ?? '') !== 'CZ') continue;
                    $delivery = array_merge($delivery, [
                        'name' => $address['recipient'], 'phone' => $address['phone'] ?: $delivery['phone'],
                        'street' => $address['street'], 'city' => $address['city'],
                        'postal_code' => $address['postal_code'], 'country' => 'CZ',
                    ]);
                    break;
                }
            }
        }
        if ($step === 'shipping' && $error !== '' && is_array($_POST)) {
            // Keep submitted contact details visible after a validation error.
            foreach (['method', 'name', 'email', 'phone', 'street', 'city', 'postal_code', 'country',
                'pickup_point', 'pickup_address', 'pickup_code', 'pickup_postal_code'] as $field) {
                if (is_string($_POST[$field] ?? null)) $delivery[$field] = $_POST[$field];
            }
        }
        $methodCode = (string) ($delivery['method'] ?? '');
        $pplSelection = $methodCode === 'ppl_pickup' ? [
            'code' => (string) ($delivery['pickup_code'] ?? ''),
            'name' => (string) ($delivery['pickup_point'] ?? ''),
            'address' => (string) ($delivery['pickup_address'] ?? ''),
            'country' => 'CZ',
        ] : ['code' => '', 'name' => '', 'address' => '', 'country' => ''];
        if ($step === 'shipping' && $error !== '' && $methodCode === 'ppl_pickup' &&
            $this->ppl->isConfigured()) {
            foreach (['code', 'name', 'address', 'country'] as $field) {
                $raw = $_POST['ppl_point_' . $field] ?? null;
                if (is_string($raw) && strlen($raw) <= 190) $pplSelection[$field] = $raw;
            }
        }
        $glsSelection = $methodCode === 'gls_pickup' ? [
            'id' => (string) ($delivery['pickup_code'] ?? ''),
            'name' => (string) ($delivery['pickup_point'] ?? ''),
            'address' => (string) ($delivery['pickup_address'] ?? ''),
            'country' => 'CZ',
        ] : ['id' => '', 'name' => '', 'address' => '', 'country' => ''];
        if ($step === 'shipping' && $error !== '' && $methodCode === 'gls_pickup') {
            foreach (['id', 'name', 'address', 'country'] as $field) {
                $raw = $_POST['gls_point_' . $field] ?? null;
                if (is_string($raw) && strlen($raw) <= 190) $glsSelection[$field] = $raw;
            }
        }
        $balikovnaSelection = $methodCode === 'balikovna_pickup' ? [
            'id' => (string) ($delivery['pickup_code'] ?? ''),
            'name' => (string) ($delivery['pickup_point'] ?? ''),
            'address' => (string) ($delivery['pickup_address'] ?? ''),
            'zip' => (string) ($delivery['pickup_postal_code'] ?? ''),
            'type' => 'BALIKOVNY',
        ] : ['id' => '', 'name' => '', 'address' => '', 'zip' => '', 'type' => ''];
        if ($step === 'shipping' && $error !== '' && $methodCode === 'balikovna_pickup') {
            foreach (['id', 'name', 'address', 'zip', 'type'] as $field) {
                $raw = $_POST['balikovna_point_' . $field] ?? null;
                if (is_string($raw) && strlen($raw) <= 190) $balikovnaSelection[$field] = $raw;
            }
        }
        $selected = ShippingPolicy::known($methodCode) ? $this->shipping->method($methodCode) : null;
        $price = $selected['price_czk'] ?? null;
        if ($price !== null && $summary['subtotal_czk'] !== null &&
            $summary['subtotal_czk'] + $price > 9999999 && $error === '') {
            $error = 'Celková částka včetně dopravy přesahuje limit objednávky. Uprav počet kusů v košíku.';
        }
        $installed = $this->orders->installed();
        $shippingConfigured = $this->shippingOptions !== [];
        $paymentMethod = $this->selectedPaymentMethod();
        $baseReady = $summary['can_continue'] && $shippingConfigured && $installed && $price !== null &&
            $summary['subtotal_czk'] + $price <= 9999999;
        $ready = $baseReady && $this->paymentAvailable($paymentMethod);
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
            'customerAddresses' => $this->customerAddresses,
            'customerProfile' => $this->customerProfile,
            'shippingOptions' => $this->shippingOptions, 'selectedShippingPrice' => $price,
            'packetaApiKey' => $this->packeta->apiKey(), 'packetaOptions' => PacketaPickupPoint::options(),
            'pplWidgetKey' => $this->ppl->apiKey(), 'pplSelection' => $pplSelection,
            'glsSelection' => $glsSelection,
            'balikovnaSelection' => $balikovnaSelection,
            'shippingConfigured' => $shippingConfigured, 'bankConfigured' => $this->bank !== null,
            'comgateConfigured' => $this->comgate !== null && $this->comgate->canInitiate(),
            'gopayConfigured' => $this->gopay !== null && $this->gopay->canInitiate(),
            'btcpayConfigured' => $this->btcpay !== null && $this->btcpay->canInitiate(),
            'paymentMethod' => $paymentMethod,
            'paymentStepReady' => $baseReady && ($this->bank !== null ||
                $this->comgate !== null && $this->comgate->canInitiate() ||
                $this->gopay !== null && $this->gopay->canInitiate() ||
                $this->btcpay !== null && $this->btcpay->canInitiate()),
            'checkoutReady' => $ready, 'termsUrl' => $this->termsUrl,
            'error' => $error, 'step' => $step,
            'setupNotice' => !$installed ? 'Pro objednávky znovu importuj aktuální database/schema.sql.' : '',
        ]);
        $this->renderer->render($step, $data, $status);
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
