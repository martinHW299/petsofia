<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Validates RedEnlace webhook notifications and reconciles them against the
 * authoritative verify endpoint. It does not trust the notification body as
 * payment proof.
 */
class RedEnlaceWebhookProcessor
{
    /** @var Redenlace */
    private $module;

    /** @var RedEnlaceTransactionRepository */
    private $repository;

    public function __construct($module, $repository = null)
    {
        if (!$module instanceof Redenlace) {
            throw new InvalidArgumentException('A RedEnlace module instance is required.');
        }
        if ($repository !== null && !$repository instanceof RedEnlaceTransactionRepository) {
            throw new InvalidArgumentException('A RedEnlace transaction repository is required.');
        }
        $this->module = $module;
        $this->repository = $repository === null ? new RedEnlaceTransactionRepository() : $repository;
    }

    /**
     * @param string $rawBody
     * @param array $server Server headers provided by PHP.
     * @return array Safe result for operational logging.
     */
    public function process($rawBody, array $server)
    {
        if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 65536) {
            throw new RedEnlaceWebhookException('Invalid webhook body.', 400);
        }
        $contentType = isset($server['CONTENT_TYPE']) ? $server['CONTENT_TYPE'] : (isset($server['HTTP_CONTENT_TYPE']) ? $server['HTTP_CONTENT_TYPE'] : '');
        if (!is_string($contentType) || stripos($contentType, 'application/json') !== 0) {
            throw new RedEnlaceWebhookException('Unsupported webhook content type.', 415);
        }

        $configuration = $this->module->getWebhookConfiguration();
        $header = $this->getHeader($server, $configuration['key']);
        if (!is_string($header) || !hash_equals($configuration['value'], $header)) {
            throw new RedEnlaceWebhookException('Webhook authentication failed.', 401);
        }

        $payload = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            throw new RedEnlaceWebhookException('Invalid webhook JSON.', 400);
        }

        $atcReference = $this->extractAtcReference($payload);
        $transaction = $this->repository->findByAtcReference($atcReference);
        if (!is_array($transaction)) {
            throw new RedEnlaceWebhookException('Webhook transaction was not found.', 404);
        }

        $client = RedEnlaceQRClient::fromBaseUrl(
            $this->module->createOAuthClient(),
            $this->module->getBaseUrl(),
            Configuration::get(Redenlace::CONFIG_CLIENT_ID)
        );
        $verified = $client->verify($atcReference);
        $this->assertMatchesTransaction($transaction, $verified, $atcReference);

        if (!$this->repository->markVerifiedStatus(
            (int) $transaction['id_redenlace_transaction'],
            $verified['estado']
        )) {
            throw new RuntimeException('Unable to persist the verified RedEnlace status.');
        }

        return array('atc_reference' => $atcReference, 'status' => $verified['estado']);
    }

    private function getHeader(array $server, $headerName)
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return isset($server[$serverKey]) ? $server[$serverKey] : null;
    }

    private function extractAtcReference(array $payload)
    {
        $reference = isset($payload['numeroReferencia']) ? $payload['numeroReferencia'] : null;
        if ($reference === null && isset($payload['data']) && is_array($payload['data']) && isset($payload['data']['numeroReferencia'])) {
            $reference = $payload['data']['numeroReferencia'];
        }
        if (!is_scalar($reference) || trim((string) $reference) === '' || strlen((string) $reference) > 100) {
            throw new RedEnlaceWebhookException('Webhook ATC reference is missing.', 400);
        }
        return trim((string) $reference);
    }

    private function assertMatchesTransaction(array $transaction, array $verified, $atcReference)
    {
        $allowedStates = array('PENDIENTE', 'PAGADO', 'CANCELADO', 'EXPIRADO', 'ERROR');
        $storedAmount = $this->normalizeAmount($transaction['amount']);
        $verifiedAmount = $this->normalizeAmount($verified['importe']);
        if (!in_array($verified['estado'], $allowedStates, true)
            || !hash_equals((string) $transaction['atc_reference'], $atcReference)
            || !hash_equals($atcReference, $verified['numeroReferencia'])
            || !hash_equals((string) $transaction['currency'], $verified['moneda'])
            || $storedAmount === '' || $verifiedAmount === '' || $storedAmount !== $verifiedAmount) {
            throw new RedEnlaceWebhookException('Webhook verification does not match the transaction.', 422);
        }
    }

    /**
     * Compares decimal values without using floating-point arithmetic.
     */
    private function normalizeAmount($amount)
    {
        $amount = trim((string) $amount);
        if (preg_match('/^(\d+)(?:\.(\d{1,6}))?$/', $amount, $matches) !== 1) {
            return '';
        }
        $integer = ltrim($matches[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = isset($matches[2]) ? $matches[2] : '';
        if (strlen($fraction) > 2 && trim(substr($fraction, 2), '0') !== '') {
            return '';
        }
        return $integer . '.' . str_pad(substr($fraction, 0, 2), 2, '0');
    }
}

class RedEnlaceWebhookException extends RuntimeException
{
    /** @var int */
    public $httpStatus;

    public function __construct($message, $httpStatus)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}
