<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'redenlace/classes/RedEnlaceTransactionRepository.php';
require_once _PS_MODULE_DIR_ . 'redenlace/classes/RedEnlaceOrderSynchronizer.php';
require_once _PS_MODULE_DIR_ . 'redenlace/classes/RedEnlaceWebhookProcessor.php';
require_once _PS_MODULE_DIR_ . 'redenlace/classes/RedEnlacePendingReconciler.php';

/**
 * Authenticated cPanel Cron endpoint for server-side QR reconciliation.
 * The token is never sent to a browser or logged.
 *
 * @property Redenlace $module
 */
class RedenlaceCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    public $ssl = true;
    public $display_header = false;
    public $display_footer = false;

    public function postProcess()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, array('processed' => 0, 'failed' => 0));
        }

        $token = isset($_SERVER['HTTP_X_REDENLACE_CRON_TOKEN']) ? $_SERVER['HTTP_X_REDENLACE_CRON_TOKEN'] : null;
        try {
            if (!is_string($token) || !hash_equals($this->module->getCronToken(), $token)) {
                $this->respond(401, array('processed' => 0, 'failed' => 0));
            }
            $result = (new RedEnlacePendingReconciler($this->module))->reconcile(50);
            $this->respond(200, $result);
        } catch (Exception $exception) {
            PrestaShopLogger::addLog('RedEnlace scheduled reconciliation failed.', 3, null, 'Redenlace', 0, true);
            $this->respond(500, array('processed' => 0, 'failed' => 0));
        }
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
