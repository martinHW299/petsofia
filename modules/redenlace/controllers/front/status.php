<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'redenlace/classes/RedEnlaceTransactionRepository.php';

/**
 * Returns the already verified payment state for the current customer's cart.
 * It deliberately does not call RedEnlace or disclose transaction details.
 *
 * @property Redenlace $module
 */
class RedenlaceStatusModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    public $ssl = true;
    public $display_header = false;
    public $display_footer = false;

    public function postProcess()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->respond(405, array('status' => null));
        }

        $cart = $this->context->cart;
        $customer = $this->context->customer;
        if (!Validate::isLoadedObject($cart) || !Validate::isLoadedObject($customer)
            || (int) $cart->id_customer !== (int) $customer->id) {
            $this->respond(403, array('status' => null));
        }

        $transaction = (new RedEnlaceTransactionRepository())->findByCartId((int) $cart->id);
        if (!is_array($transaction)) {
            $this->respond(404, array('status' => null));
        }

        $allowedStates = array('PENDIENTE', 'PAGADO', 'CANCELADO', 'EXPIRADO', 'ERROR');
        $status = isset($transaction['status']) ? (string) $transaction['status'] : '';
        if (!in_array($status, $allowedStates, true)) {
            $this->respond(409, array('status' => null));
        }

        $this->respond(200, array('status' => $status));
    }

    private function respond($status, array $body)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($body);
        exit;
    }
}
