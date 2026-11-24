<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'redenlace/classes/RedEnlaceTransactionRepository.php';
require_once _PS_MODULE_DIR_ . 'redenlace/classes/RedEnlaceOrderSynchronizer.php';
require_once _PS_MODULE_DIR_ . 'redenlace/classes/RedEnlaceWebhookProcessor.php';

/**
 * Public RedEnlace callback endpoint. It intentionally has no customer
 * session requirement because RedEnlace calls it server-to-server.
 *
 * @property Redenlace $module
 */
class RedenlaceWebhookModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    public $ssl = true;
    public $display_header = false;
    public $display_footer = false;

    public function postProcess()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, array('received' => false));
        }

        try {
            $result = (new RedEnlaceWebhookProcessor($this->module))->process(file_get_contents('php://input'), $_SERVER);
            $this->log('RedEnlace webhook verified for ATC reference ' . $result['atc_reference'] . ' with state ' . $result['status'] . '.', 1);
            $this->respond(200, array('received' => true));
        } catch (RedEnlaceWebhookException $exception) {
            $this->log('RedEnlace webhook rejected: HTTP ' . (int) $exception->httpStatus . '.', 2);
            $this->respond($exception->httpStatus, array('received' => false));
        } catch (Exception $exception) {
            $this->log('RedEnlace webhook could not be processed.', 3);
            $this->respond(500, array('received' => false));
        }
    }

    private function respond($status, array $body)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body);
        exit;
    }

    private function log($message, $severity)
    {
        PrestaShopLogger::addLog($message, $severity, null, 'Redenlace', 0, true);
    }
}
