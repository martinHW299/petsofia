<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Re-checks locally pending or not-yet-synchronized QR transactions. It only
 * trusts the RedEnlace verify endpoint through RedEnlaceWebhookProcessor.
 */
class RedEnlacePendingReconciler
{
    /** @var RedEnlaceWebhookProcessor */
    private $processor;

    /** @var RedEnlaceTransactionRepository */
    private $repository;

    public function __construct($module, $processor = null, $repository = null)
    {
        $this->repository = $repository === null ? new RedEnlaceTransactionRepository() : $repository;
        $this->processor = $processor === null ? new RedEnlaceWebhookProcessor($module, $this->repository) : $processor;
    }

    /**
     * @param int $limit
     * @return array
     */
    public function reconcile($limit = 50)
    {
        $result = array('processed' => 0, 'failed' => 0);
        $transactions = $this->repository->findTransactionsForReconciliation($limit);
        if (!is_array($transactions)) {
            return $result;
        }

        foreach ($transactions as $transaction) {
            try {
                $this->processor->reconcile((string) $transaction['atc_reference']);
                ++$result['processed'];
            } catch (Exception $exception) {
                ++$result['failed'];
                PrestaShopLogger::addLog(
                    'RedEnlace reconciliation failed for transaction #' . (int) $transaction['id_redenlace_transaction'] . '.',
                    2,
                    null,
                    'Redenlace',
                    (int) $transaction['id_redenlace_transaction'],
                    true
                );
            }
        }

        return $result;
    }
}
