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
