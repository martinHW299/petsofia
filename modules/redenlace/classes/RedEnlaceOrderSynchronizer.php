<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Synchronizes an already verified RedEnlace payment with its PrestaShop
 * order. Terminal non-paid QR states receive their own visible, non-paid
 * PrestaShop order state.
 */
class RedEnlaceOrderSynchronizer
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
     * @param array $transaction Locally persisted RedEnlace transaction.
     * @param array $verified Authoritative response from /verify.
     * @return bool True when the PrestaShop order is synchronized.
     */
    public function synchronize(array $transaction, array $verified)
    {
        if (empty($transaction['id_redenlace_transaction']) || empty($transaction['id_order'])) {
            throw new RuntimeException('The RedEnlace transaction has no associated order.');
        }

        $targetStateId = $this->getTargetStateId($verified['estado']);
        if ($targetStateId === 0) {
            return false;
        }
        $pendingStateId = $this->module->getPendingOrderStateId();
        if ($targetStateId < 1 || $pendingStateId < 1) {
            throw new RuntimeException('The RedEnlace order-state configuration is incomplete.');
        }

        $db = Db::getInstance();
        if (!$db->execute('START TRANSACTION')) {
            throw new RuntimeException('Unable to start RedEnlace order synchronization.');
        }
        try {
            // Locks this transaction row so duplicate concurrent callbacks cannot
            // create duplicate order-history entries or e-mails.
            $lockedTransaction = $db->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'redenlace_transaction` WHERE id_redenlace_transaction = '
                . (int) $transaction['id_redenlace_transaction'] . ' FOR UPDATE'
            );
            if (!is_array($lockedTransaction)) {
                throw new RuntimeException('The RedEnlace transaction could not be locked.');
            }
            $order = new Order((int) $lockedTransaction['id_order']);
            if (!Validate::isLoadedObject($order)
                || (int) $order->id_cart !== (int) $lockedTransaction['id_cart']
                || $order->module !== $this->module->name
                || !$this->matchesOrderAmountAndCurrency($order, $lockedTransaction)) {
                throw new RuntimeException('The verified RedEnlace transaction does not match its PrestaShop order.');
            }

            $paidStateId = (int) Configuration::get('PS_OS_PAYMENT');
            if ((int) $order->current_state === $paidStateId || (int) $order->current_state === $targetStateId) {
                if (!$this->repository->markOrderSynchronized((int) $lockedTransaction['id_redenlace_transaction'])) {
                    throw new RuntimeException('Unable to record RedEnlace order synchronization.');
                }
                if (!$db->execute('COMMIT')) {
                    throw new RuntimeException('Unable to commit RedEnlace order synchronization.');
                }
                return true;
            }

            $legacyPendingStateId = (int) Configuration::get('PS_OS_BANKWIRE');
            if ((int) $order->current_state !== $pendingStateId && (int) $order->current_state !== $legacyPendingStateId) {
                throw new RuntimeException('The PrestaShop order is not awaiting RedEnlace payment.');
            }

            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState($targetStateId, $order);
            if (!$history->addWithemail()) {
                throw new RuntimeException('Unable to add the RedEnlace order history entry.');
            }
            if (!$this->repository->markOrderSynchronized((int) $lockedTransaction['id_redenlace_transaction'])) {
                throw new RuntimeException('Unable to record RedEnlace order synchronization.');
            }
            if (!$db->execute('COMMIT')) {
                throw new RuntimeException('Unable to commit RedEnlace order synchronization.');
            }
            return true;
        } catch (Exception $exception) {
            $db->execute('ROLLBACK');
            throw $exception;
        }
    }

    private function getTargetStateId($providerStatus)
    {
        if ($providerStatus === 'PAGADO') {
            return (int) Configuration::get('PS_OS_PAYMENT');
        }
        if ($providerStatus === 'CANCELADO') {
            return $this->module->getCancelledOrderStateId();
        }
        if ($providerStatus === 'EXPIRADO') {
            return $this->module->getExpiredOrderStateId();
        }
        if ($providerStatus === 'ERROR') {
            return $this->module->getErrorOrderStateId();
        }
        return 0;
    }

    private function matchesOrderAmountAndCurrency(Order $order, array $transaction)
    {
        $currency = new Currency((int) $order->id_currency);
        return Validate::isLoadedObject($currency)
            && hash_equals((string) $transaction['currency'], (string) $currency->iso_code)
            && $this->normalizeAmount($order->total_paid_tax_incl) === $this->normalizeAmount($transaction['amount']);
    }

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
