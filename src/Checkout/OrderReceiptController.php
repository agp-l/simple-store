<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use SimpleStore\Navigation\UrlManager;
use SimpleStore\Rendering\PageRenderer;
use SimpleStore\Accounting\InvoiceRepository;
use Throwable;

/** Customer-facing order receipt, payment retry and provider return boundary. */
final class OrderReceiptController
{
    public function __construct(
        private UrlManager $url,
        private PageRenderer $renderer,
        private array $shared,
        private CartSession $cart,
        private OrderRepository $orders,
        private ?InvoiceRepository $invoices,
        private ?ComgatePaymentService $comgate,
        private ?GoPayPaymentService $gopay,
        private ?BTCPayPaymentService $btcpay,
        private ?OrderTrackingRepository $orderTracking
    ) {
    }

    public function handle(array $route): void
    {
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
        $this->render($order, $paymentNotice, $responseStatus, $gopayGatewayUrl, $invoice);
        return;
    }

    /** The GoPay hosted gateway is opened by a POST form, even on a repeated payment attempt. */
    public function render(
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

    private function redirect(string $target): void
    {
        header('Location: ' . $target, true, 303);
        exit;
    }
}
