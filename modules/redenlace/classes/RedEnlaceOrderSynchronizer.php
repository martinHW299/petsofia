<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Synchronizes an already verified RedEnlace payment with its PrestaShop
 * order. QR states other than PAGADO intentionally do not finalize an order.
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
     * @return bool True when the PrestaShop order is synchronized as paid.
     */
    public function synchronize(array $transaction, array $verified)
    {
        if ($verified['estado'] !== 'PAGADO') {
            return false;
        }
        if (empty($transaction['id_redenlace_transaction']) || empty($transaction['id_order'])) {
            throw new RuntimeException('The RedEnlace transaction has no associated order.');
        }

        $order = new Order((int) $transaction['id_order']);
        if (!Validate::isLoadedObject($order)
            || (int) $order->id_cart !== (int) $transaction['id_cart']
            || $order->module !== $this->module->name
            || !$this->matchesOrderAmountAndCurrency($order, $transaction)) {
            throw new RuntimeException('The verified RedEnlace transaction does not match its PrestaShop order.');
        }

        $paidStateId = (int) Configuration::get('PS_OS_PAYMENT');
        $pendingStateId = $this->module->getPendingOrderStateId();
        if ($paidStateId < 1 || $pendingStateId < 1) {
            throw new RuntimeException('The RedEnlace order-state configuration is incomplete.');
        }

        if ((int) $order->current_state === $paidStateId) {
            return $this->repository->markOrderSynchronized((int) $transaction['id_redenlace_transaction']);
        }

        // Existing Sandbox orders were created before the module had its own
        // pending state. They may safely be reconciled once because they are
        // already correlated to a verified RedEnlace transaction.
        $legacyPendingStateId = (int) Configuration::get('PS_OS_BANKWIRE');
        if ((int) $order->current_state !== $pendingStateId
            && (int) $order->current_state !== $legacyPendingStateId) {
            throw new RuntimeException('The PrestaShop order is not awaiting RedEnlace payment.');
        }

        $history = new OrderHistory();
        $history->id_order = (int) $order->id;
        $history->changeIdOrderState($paidStateId, $order);
        if (!$history->addWithemail()) {
            throw new RuntimeException('Unable to add the RedEnlace paid order history entry.');
        }

        if (!$this->repository->markOrderSynchronized((int) $transaction['id_redenlace_transaction'])) {
            throw new RuntimeException('Unable to record RedEnlace order synchronization.');
        }

        return true;
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
