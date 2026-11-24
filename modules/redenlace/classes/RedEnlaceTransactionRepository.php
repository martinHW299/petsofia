<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class RedEnlaceTransactionRepository
{
    /**
     * @param int $cartId
     * @return array|false
     */
    public function findByCartId($cartId)
    {
        return Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'redenlace_transaction` WHERE id_cart = ' . (int) $cartId);
    }

    /**
     * @param string $atcReference
     * @return array|false
     */
    public function findByAtcReference($atcReference)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'redenlace_transaction` WHERE atc_reference = \'' . pSQL($atcReference) . '\''
        );
    }

    /**
     * @param int $limit
     * @return array
     */
    public function findTransactionsForReconciliation($limit = 50)
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'redenlace_transaction`'
            . ' WHERE atc_reference IS NOT NULL AND atc_reference != \'\''
            . ' AND (status = \'PENDIENTE\' OR order_synced_at IS NULL)'
            . ' ORDER BY date_upd ASC LIMIT ' . max(1, min(100, (int) $limit))
        );
    }

    /**
     * Persists a provider state without allowing a delayed notification to
     * downgrade a payment already confirmed as paid.
     *
     * @param int $transactionId
     * @param string $status
     * @return bool
     */
    public function markVerifiedStatus($transactionId, $status)
    {
        $transaction = Db::getInstance()->getRow(
            'SELECT status FROM `' . _DB_PREFIX_ . 'redenlace_transaction` WHERE id_redenlace_transaction = ' . (int) $transactionId
        );
        if (!is_array($transaction)) {
            return false;
        }
        if ($transaction['status'] === 'PAGADO' && $status !== 'PAGADO') {
            return true;
        }

        return Db::getInstance()->update('redenlace_transaction', array(
            'status' => pSQL($status),
            'date_upd' => date('Y-m-d H:i:s')
        ), 'id_redenlace_transaction = ' . (int) $transactionId);
    }

    /**
     * @param int $transactionId
     * @return bool
     */
    public function markOrderSynchronized($transactionId)
    {
        return Db::getInstance()->update('redenlace_transaction', array(
            'order_synced_at' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s')
        ), 'id_redenlace_transaction = ' . (int) $transactionId);
    }

    /**
     * @param int $cartId
     * @param int $orderId
     * @param string $reference
     * @param string $amount Decimal value with two fractional digits.
     * @param string $currency ISO 4217 currency code.
     * @return bool
     */
    public function create($cartId, $orderId, $reference, $amount, $currency)
    {
        return Db::getInstance()->insert('redenlace_transaction', array(
            'id_cart' => (int) $cartId,
            'id_order' => (int) $orderId,
            'originating_reference' => pSQL($reference),
            'amount' => (string) $amount,
            'currency' => pSQL($currency),
            'status' => 'INITIALIZING',
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s')
        ));
    }

    /**
     * @param int $cartId
     * @param array $result Normalized result returned by RedEnlaceQRClient.
     * @return bool
     */
    public function markPending($cartId, array $result)
    {
        return Db::getInstance()->update('redenlace_transaction', array(
            'atc_reference' => pSQL($result['numeroReferencia']),
            'status' => pSQL($result['estado']),
            'expires_at' => pSQL($result['fechaExpiracion']),
            'qr_base64' => pSQL($result['qr'], true),
            'date_upd' => date('Y-m-d H:i:s')
        ), 'id_cart = ' . (int) $cartId);
    }
}
