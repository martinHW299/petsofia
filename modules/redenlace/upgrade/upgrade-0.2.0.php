<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_2_0($module)
{
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'redenlace_transaction` ('
        . '`id_redenlace_transaction` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
        . '`id_cart` INT UNSIGNED NOT NULL, `id_order` INT UNSIGNED NOT NULL, '
        . '`originating_reference` VARCHAR(100) NOT NULL, `atc_reference` VARCHAR(100) DEFAULT NULL, '
        . '`amount` DECIMAL(20,6) NOT NULL, `currency` CHAR(3) NOT NULL, `status` VARCHAR(32) NOT NULL, '
        . '`expires_at` VARCHAR(64) DEFAULT NULL, `qr_base64` MEDIUMTEXT DEFAULT NULL, '
        . '`date_add` DATETIME NOT NULL, `date_upd` DATETIME NOT NULL, '
        . 'PRIMARY KEY (`id_redenlace_transaction`), UNIQUE KEY `redenlace_cart` (`id_cart`), '
        . 'UNIQUE KEY `redenlace_originating_reference` (`originating_reference`), '
        . 'UNIQUE KEY `redenlace_atc_reference` (`atc_reference`)) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

    return $module->registerHook('paymentOptions')
        && $module->registerHook('paymentReturn')
        && Db::getInstance()->execute($sql)
        && Configuration::updateValue(Redenlace::CONFIG_ENABLED, (bool) Configuration::get(Redenlace::CONFIG_ENABLED))
        && Configuration::updateValue(Redenlace::CONFIG_ENVIRONMENT, Configuration::get(Redenlace::CONFIG_ENVIRONMENT) ?: 'sandbox')
        && Configuration::updateValue(Redenlace::CONFIG_QR_VALIDITY, (int) Configuration::get(Redenlace::CONFIG_QR_VALIDITY) ?: 300);
}
