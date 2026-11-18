<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_3_0($module)
{
    return Configuration::updateValue(
        Redenlace::CONFIG_QR_VALIDITY,
        (int) Configuration::get(Redenlace::CONFIG_QR_VALIDITY) ?: 300
    );
}
