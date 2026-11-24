<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_4_0($module)
{
    $table = _DB_PREFIX_ . 'redenlace_transaction';
    $column = Db::getInstance()->getValue('SHOW COLUMNS FROM `' . bqSQL($table) . '` LIKE \'order_synced_at\'');
    if ($column === false) {
        if (!Db::getInstance()->execute('ALTER TABLE `' . bqSQL($table) . '` ADD `order_synced_at` DATETIME DEFAULT NULL AFTER `qr_base64`')) {
            return false;
        }
    }

    return $module->installPendingOrderState();
}
