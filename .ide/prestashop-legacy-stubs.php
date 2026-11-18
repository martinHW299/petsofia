
<?php

/**
 * PrestaShop 1.7.8.2 legacy class aliases.
 *
 * IDE-only declarations for Intelephense.
 *
 * DO NOT include, execute or deploy this file.
 */

abstract class PaymentModule extends PaymentModuleCore
{
}

class Configuration extends ConfigurationCore
{
}

class Tools extends ToolsCore
{
}

class Validate extends ValidateCore
{
}

class ModuleFrontController extends ModuleFrontControllerCore
{
    /** @var Redenlace */
    public $module;

    /** @var ContextCore */
    public $context;

    /** @var array */
    public $errors = array();
}

class Cart extends CartCore
{
}

class Order extends OrderCore
{
}

class PrestaShopLogger extends PrestaShopLoggerCore
{
}
