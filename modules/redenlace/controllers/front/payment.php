<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'redenlace/classes/RedEnlaceTransactionRepository.php';

/**
 * @property Redenlace $module
 */
class RedenlacePaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        try {
            $this->assertCartContext();
            $this->module->assertCheckoutConfiguration();
            $transaction = $this->getOrCreateTransaction();
            if ($transaction['status'] !== 'PENDIENTE' || empty($transaction['qr_base64'])) {
                throw new RuntimeException('No QR is available for this transaction.');
            }
            $transaction['expires_at_unix'] = $this->getExpirationTimestamp($transaction);
            $transaction['expiry_countdown'] = $this->formatDuration(
                max(0, $transaction['expires_at_unix'] - time())
            );
            $transaction['status_url'] = $this->context->link->getModuleLink(
                $this->module->name,
                'status',
                array(),
                true
            );
            $transaction['confirmation_url'] = $this->context->link->getPageLink(
                'order-confirmation',
                true,
                null,
                'id_cart=' . (int) $transaction['id_cart']
                . '&id_module=' . (int) $this->module->id
                . '&id_order=' . (int) $transaction['id_order']
                . '&key=' . rawurlencode($this->context->customer->secure_key)
            );
            $this->context->smarty->assign(array('transaction' => $transaction));
        } catch (Exception $exception) {
            $this->logInitiationFailure($exception);
            $this->errors[] = $this->module->l('No fue posible iniciar el pago QR. Intenta nuevamente o elige otro método de pago.', 'payment');
        }
    }

    public function initContent()
    {
        parent::initContent();
        $this->setTemplate('module:redenlace/views/templates/front/payment.tpl');
    }

    public function setMedia()
    {
        parent::setMedia();
        $this->registerJavascript(
            'module-redenlace-payment-status',
            'modules/' . $this->module->name . '/views/js/payment.js',
            array('position' => 'bottom', 'priority' => 150)
        );
        $this->registerStylesheet(
            'module-redenlace-payment-status',
            'modules/' . $this->module->name . '/views/css/payment.css',
            array('media' => 'all', 'priority' => 150)
        );
    }

    private function assertCartContext()
    {
        $cart = $this->context->cart;
        if (!$this->module->isPaymentAvailable() || !Validate::isLoadedObject($cart)
            || !Validate::isLoadedObject($this->context->customer)
            || (int) $cart->id_customer !== (int) $this->context->customer->id
            || $cart->getOrderTotal(true, Cart::BOTH) <= 0) {
            throw new RuntimeException('Invalid payment cart context.');
        }
    }

    private function getOrCreateTransaction()
    {
        $repository = new RedEnlaceTransactionRepository();
        $cart = $this->context->cart;
        $transaction = $repository->findByCartId($cart->id);
        if (is_array($transaction)) {
            return $transaction;
        }

        $orderId = (int) Order::getIdByCartId($cart->id);
        if ($orderId < 1) {
            $this->module->validateOrder(
                $cart->id,
                $this->module->getPendingOrderStateId(),
                $cart->getOrderTotal(true, Cart::BOTH),
                $this->module->l('Pago mediante código QR', 'payment'),
                null,
                array(),
                (int) $this->context->currency->id,
                false,
                $this->context->customer->secure_key
            );
            $orderId = (int) $this->module->currentOrder;
        }
        if ($orderId < 1) {
            throw new RuntimeException('Unable to create the pending order.');
        }

        // ATC rejected alphanumeric references. The PrestaShop order ID is a
        // unique numeric identifier and matches the documented example format.
        $reference = (string) $orderId;
        $amount = number_format($cart->getOrderTotal(true, Cart::BOTH), 2, '.', '');
        if (!$repository->create($cart->id, $orderId, $reference, $amount, 'BOB')) {
            throw new RuntimeException('Unable to persist the payment transaction.');
        }

        $client = RedEnlaceQRClient::fromBaseUrl(
            $this->module->createOAuthClient(),
            $this->module->getBaseUrl(),
            Configuration::get(Redenlace::CONFIG_CLIENT_ID)
        );
        $result = $client->generate(array(
            'glosa' => 'Pago de pedido ' . $orderId,
            'moneda' => 'BOB',
            'monto' => $amount,
            'numeroReferencia' => $reference,
            'vigencia' => (int) Configuration::get(Redenlace::CONFIG_QR_VALIDITY),
            'idEstablecimiento' => (int) Configuration::get(Redenlace::CONFIG_ESTABLISHMENT_ID),
            'nombreEstablecimiento' => Configuration::get(Redenlace::CONFIG_ESTABLISHMENT_NAME),
            'webhook' => $this->module->getWebhookConfiguration()
        ));
        if (!$repository->markPending($cart->id, $result)) {
            throw new RuntimeException('Unable to persist the generated QR transaction.');
        }
        $transaction = $repository->findByCartId($cart->id);
        if (!is_array($transaction)) {
            throw new RuntimeException('Unable to reload the QR transaction.');
        }
        return $transaction;
    }

    /**
     * Produces an absolute server-derived expiry time for the browser timer.
     * Reloading the page therefore never restarts the QR validity period.
     *
     * @param array $transaction
     * @return int Unix timestamp.
     */
    private function getExpirationTimestamp(array $transaction)
    {
        if (!empty($transaction['expires_at'])) {
            try {
                $expiration = new DateTime($transaction['expires_at']);
                return $expiration->getTimestamp();
            } catch (Exception $exception) {
                // Fall back to the locally persisted creation time below.
            }
        }

        $createdAt = !empty($transaction['date_add']) ? strtotime($transaction['date_add']) : false;
        if ($createdAt === false) {
            throw new RuntimeException('Unable to calculate the QR expiration time.');
        }

        return $createdAt + (int) Configuration::get(Redenlace::CONFIG_QR_VALIDITY);
    }

    /**
     * @param int $seconds
     * @return string
     */
    private function formatDuration($seconds)
    {
        $seconds = max(0, (int) $seconds);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    /**
     * Records a diagnostic without exposing credentials, access tokens, the
     * webhook secret, or provider payloads to the customer-facing response.
     *
     * @param Exception $exception
     * @return void
     */
    private function logInitiationFailure(Exception $exception)
    {
        $message = preg_replace('/[\r\n]+/', ' ', $exception->getMessage());
        PrestaShopLogger::addLog(
            'QR payment initiation failed for cart #' . (int) $this->context->cart->id . ': ' . $message,
            3,
            null,
            'Cart',
            (int) $this->context->cart->id,
            true
        );
    }
}
