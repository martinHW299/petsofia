<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/RedEnlaceOAuthClient.php';
require_once __DIR__ . '/classes/RedEnlaceQRClient.php';
require_once __DIR__ . '/classes/RedEnlaceSecretStorage.php';

class Redenlace extends PaymentModule
{
    const CONFIG_ENABLED = 'REDENLACE_ENABLED';
    const CONFIG_ENVIRONMENT = 'REDENLACE_ENVIRONMENT';
    const CONFIG_CLIENT_ID = 'REDENLACE_CLIENT_ID';
    const CONFIG_CLIENT_SECRET = 'REDENLACE_CLIENT_SECRET';
    const CONFIG_ESTABLISHMENT_ID = 'REDENLACE_ESTABLISHMENT_ID';
    const CONFIG_ESTABLISHMENT_NAME = 'REDENLACE_ESTABLISHMENT_NAME';
    const CONFIG_QR_VALIDITY = 'REDENLACE_QR_VALIDITY';
    const CONFIG_WEBHOOK_URL = 'REDENLACE_WEBHOOK_URL';
    const CONFIG_WEBHOOK_KEY = 'REDENLACE_WEBHOOK_KEY';
    const CONFIG_WEBHOOK_VALUE = 'REDENLACE_WEBHOOK_VALUE';
    const WEBHOOK_HEADER_NAME = 'X-RedEnlace-Webhook-Token';
    const SANDBOX_BASE_URL = 'https://atcgwapitest.redenlace.com.bo/sandbox/';
    const PRODUCTION_BASE_URL = 'https://api.redenlace.com.bo/';

    public function __construct()
    {
        $this->name = 'redenlace';
        $this->tab = 'payments_gateways';
        $this->version = '0.3.0';
        $this->author = 'LogicaTI';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('RedEnlace QR Simple');
        $this->description = $this->l('Pagos QR mediante RedEnlace.');
        $this->ps_versions_compliancy = array('min' => '1.7.8.0', 'max' => '1.7.8.99');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->installTransactionTable()
            && Configuration::updateValue(self::CONFIG_ENABLED, false)
            && Configuration::updateValue(self::CONFIG_ENVIRONMENT, 'sandbox')
            && Configuration::updateValue(self::CONFIG_QR_VALIDITY, 300);
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->isPaymentAvailable()) {
            return array();
        }
        $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setCallToActionText($this->l('Pagar mediante código QR'));
        $option->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true));
        return array($option);
    }

    public function hookPaymentReturn($params)
    {
        return $this->active ? $this->l('Tu pago QR está pendiente de confirmación.') : '';
    }

    public function getContent()
    {
        if (!$this->canViewConfiguration()) {
            return $this->displayError($this->l('No tienes permiso para acceder a esta configuración.'));
        }
        $output = '';
        if (Tools::isSubmit('submitRedenlaceConfiguration')) {
            $output = $this->processConfigurationSubmission();
        } elseif (Tools::isSubmit('submitRedenlaceOAuthTest')) {
            $output = $this->processOAuthTest();
        }
        return $output . $this->renderConfigurationForm();
    }

    public function getBaseUrl()
    {
        return Configuration::get(self::CONFIG_ENVIRONMENT) === 'production'
            ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;
    }

    public function createOAuthClient()
    {
        $storedSecret = Configuration::get(self::CONFIG_CLIENT_SECRET);
        if (!is_string($storedSecret) || $storedSecret === '') {
            throw new RuntimeException('RedEnlace Client Secret is not configured.');
        }
        $storage = new RedEnlaceSecretStorage();
        return RedEnlaceOAuthClient::fromBaseUrl($this->getBaseUrl(), Configuration::get(self::CONFIG_CLIENT_ID), $storage->decrypt($storedSecret));
    }

    public function getWebhookConfiguration()
    {
        $url = trim((string) Configuration::get(self::CONFIG_WEBHOOK_URL));
        if (!$this->isValidHttpsUrl($url)) {
            throw new RuntimeException('The RedEnlace webhook URL must be a valid HTTPS URL.');
        }

        $storedValue = Configuration::get(self::CONFIG_WEBHOOK_VALUE);
        $storage = new RedEnlaceSecretStorage();
        if (!is_string($storedValue) || $storedValue === '') {
            try {
                $storedValue = $storage->encrypt(bin2hex(random_bytes(32)));
            } catch (Exception $exception) {
                throw new RuntimeException('Unable to generate the RedEnlace webhook secret.');
            }
            if (!Configuration::updateValue(self::CONFIG_WEBHOOK_VALUE, $storedValue)) {
                throw new RuntimeException('Unable to store the RedEnlace webhook secret.');
            }
        }

        return array(
            'url' => $url,
            'key' => self::WEBHOOK_HEADER_NAME,
            'value' => $storage->decrypt($storedValue)
        );
    }

    public function isPaymentAvailable()
    {
        if (!$this->active || !Configuration::get(self::CONFIG_ENABLED) || !isset($this->context->currency)
            || $this->context->currency->iso_code !== 'BOB') {
            return false;
        }
        try {
            $this->assertCheckoutConfiguration();
        } catch (Exception $exception) {
            return false;
        }
        return true;
    }

    public function assertCheckoutConfiguration()
    {
        if (!in_array(Configuration::get(self::CONFIG_ENVIRONMENT), array('sandbox', 'production'), true)
            || trim((string) Configuration::get(self::CONFIG_CLIENT_ID)) === ''
            || trim((string) Configuration::get(self::CONFIG_CLIENT_SECRET)) === ''
            || !ctype_digit((string) Configuration::get(self::CONFIG_ESTABLISHMENT_ID))
            || trim((string) Configuration::get(self::CONFIG_ESTABLISHMENT_NAME)) === ''
            || (int) Configuration::get(self::CONFIG_QR_VALIDITY) < 1) {
            throw new RuntimeException('RedEnlace checkout configuration is incomplete.');
        }
        $this->getWebhookConfiguration();
    }

    private function processConfigurationSubmission()
    {
        if (!$this->canModifyConfiguration() || !$this->isValidAdminToken()) {
            return $this->displayError($this->l('Token de seguridad inválido o vencido.'));
        }
        $enabled = (bool) Tools::getValue(self::CONFIG_ENABLED);
        $environment = Tools::getValue(self::CONFIG_ENVIRONMENT);
        $clientId = trim((string) Tools::getValue(self::CONFIG_CLIENT_ID));
        $clientSecret = (string) Tools::getValue(self::CONFIG_CLIENT_SECRET);
        $establishmentId = trim((string) Tools::getValue(self::CONFIG_ESTABLISHMENT_ID));
        $establishmentName = trim((string) Tools::getValue(self::CONFIG_ESTABLISHMENT_NAME));
        $validity = trim((string) Tools::getValue(self::CONFIG_QR_VALIDITY));
        $webhookUrl = trim((string) Tools::getValue(self::CONFIG_WEBHOOK_URL));
        if (!in_array($environment, array('sandbox', 'production'), true) || $clientId === ''
            || !ctype_digit($establishmentId) || (int) $establishmentId < 1 || $establishmentName === ''
            || !ctype_digit($validity) || (int) $validity < 1 || !$this->isValidHttpsUrl($webhookUrl)) {
            return $this->displayError($this->l('Revisa los campos obligatorios de la configuración.'));
        }
        try {
            $storedSecret = $clientSecret !== '' ? (new RedEnlaceSecretStorage())->encrypt($clientSecret) : Configuration::get(self::CONFIG_CLIENT_SECRET);
        } catch (Exception $exception) {
            return $this->displayError($this->l('No se pudo almacenar el Client Secret de forma segura.'));
        }
        if (!is_string($storedSecret) || $storedSecret === '') {
            return $this->displayError($this->l('El Client Secret es obligatorio.'));
        }
        if (!Configuration::updateValue(self::CONFIG_ENABLED, $enabled)
            || !Configuration::updateValue(self::CONFIG_ENVIRONMENT, $environment)
            || !Configuration::updateValue(self::CONFIG_CLIENT_ID, $clientId)
            || !Configuration::updateValue(self::CONFIG_CLIENT_SECRET, $storedSecret)
            || !Configuration::updateValue(self::CONFIG_ESTABLISHMENT_ID, $establishmentId)
            || !Configuration::updateValue(self::CONFIG_ESTABLISHMENT_NAME, $establishmentName)
            || !Configuration::updateValue(self::CONFIG_QR_VALIDITY, (int) $validity)
            || !Configuration::updateValue(self::CONFIG_WEBHOOK_URL, $webhookUrl)) {
            return $this->displayError($this->l('No se pudo guardar la configuración.'));
        }
        return $this->displayConfirmation($this->l('Configuración guardada.'));
    }

    private function processOAuthTest()
    {
        if (!$this->canModifyConfiguration() || !$this->isValidAdminToken()) {
            return $this->displayError($this->l('Token de seguridad inválido o vencido.'));
        }
        try {
            $token = $this->createOAuthClient()->getAccessToken();
        } catch (Exception $exception) {
            return $this->displayError($this->l('No se pudo autenticar con RedEnlace. Revisa el ambiente y las credenciales guardadas.'));
        }
        $expiresIn = isset($token['expires_in']) && is_numeric($token['expires_in']) ? (int) $token['expires_in'] : null;
        return $this->displayConfirmation($expiresIn === null ? $this->l('Conexión con RedEnlace completada.') : sprintf($this->l('Conexión con RedEnlace completada. Vigencia del token: %d segundos.'), $expiresIn));
    }

    private function renderConfigurationForm()
    {
        $actionUrl = $this->context->link->getAdminLink('AdminModules', true, array(), array('configure' => $this->name));
        $token = Tools::getAdminTokenLite('AdminModules');
        $enabled = (bool) Configuration::get(self::CONFIG_ENABLED);
        $environment = Configuration::get(self::CONFIG_ENVIRONMENT) ?: 'sandbox';
        $html = '<form action="' . Tools::safeOutput($actionUrl) . '" method="post"><div class="panel"><h3><i class="icon-qrcode"></i> ' . $this->l('RedEnlace QR Simple') . '</h3>';
        $html .= '<fieldset><legend>' . $this->l('Configuración general') . '</legend><div class="form-group"><label class="control-label">' . $this->l('Habilitar pagos QR') . '</label><div class="radio"><label><input type="radio" name="' . self::CONFIG_ENABLED . '" value="1"' . ($enabled ? ' checked' : '') . '> ' . $this->l('Sí') . '</label></div><div class="radio"><label><input type="radio" name="' . self::CONFIG_ENABLED . '" value="0"' . (!$enabled ? ' checked' : '') . '> ' . $this->l('No') . '</label></div></div><div class="form-group"><label class="control-label required" for="redenlace_environment">' . $this->l('Ambiente') . '</label><select class="form-control" id="redenlace_environment" name="' . self::CONFIG_ENVIRONMENT . '"><option value="sandbox"' . ($environment === 'sandbox' ? ' selected' : '') . '>' . $this->l('Sandbox') . '</option><option value="production"' . ($environment === 'production' ? ' selected' : '') . '>' . $this->l('Producción') . '</option></select></div></fieldset>';
        $html .= '<fieldset><legend>' . $this->l('Credenciales de RedEnlace') . '</legend>' . $this->textInput(self::CONFIG_CLIENT_ID, 'redenlace_client_id', $this->l('Client ID'), Configuration::get(self::CONFIG_CLIENT_ID), 'text') . $this->textInput(self::CONFIG_CLIENT_SECRET, 'redenlace_client_secret', $this->l('Client Secret'), '', 'password') . '<p class="help-block">' . $this->l('El secreto está cifrado y nunca se muestra. Déjalo vacío para conservar el valor guardado.') . '</p></fieldset>';
        $html .= '<fieldset><legend>' . $this->l('Datos del establecimiento') . '</legend>' . $this->textInput(self::CONFIG_ESTABLISHMENT_ID, 'redenlace_establishment_id', $this->l('ID del establecimiento'), Configuration::get(self::CONFIG_ESTABLISHMENT_ID), 'number') . $this->textInput(self::CONFIG_ESTABLISHMENT_NAME, 'redenlace_establishment_name', $this->l('Nombre del establecimiento'), Configuration::get(self::CONFIG_ESTABLISHMENT_NAME), 'text') . '</fieldset>';
        $html .= '<fieldset><legend>' . $this->l('Configuración del pago') . '</legend>' . $this->textInput(self::CONFIG_QR_VALIDITY, 'redenlace_qr_validity', $this->l('Vigencia del QR (segundos)'), Configuration::get(self::CONFIG_QR_VALIDITY) ?: 300, 'number') . $this->textInput(self::CONFIG_WEBHOOK_URL, 'redenlace_webhook_url', $this->l('URL pública HTTPS del webhook'), Configuration::get(self::CONFIG_WEBHOOK_URL), 'url') . '<p class="help-block">' . $this->l('El secreto del webhook se genera automáticamente, se guarda cifrado y nunca se muestra.') . '</p><p class="help-block">' . $this->l('Validar los límites permitidos con ATC.') . '</p></fieldset>';
        $html .= '<input type="hidden" name="token" value="' . Tools::safeOutput($token) . '"><div class="panel-footer"><button type="submit" name="submitRedenlaceConfiguration" class="btn btn-default pull-right"><i class="process-icon-save"></i> ' . $this->l('Guardar configuración') . '</button><button type="submit" name="submitRedenlaceOAuthTest" class="btn btn-default"><i class="icon-plug"></i> ' . $this->l('Probar conexión con RedEnlace') . '</button></div></div></form>';
        return $html;
    }

    private function textInput($name, $id, $label, $value, $type)
    {
        return '<div class="form-group"><label class="control-label required" for="' . $id . '">' . $label . '</label><input class="form-control" id="' . $id . '" name="' . $name . '" type="' . $type . '" required' . ($type === 'number' ? ' min="1" step="1"' : '') . ' value="' . Tools::safeOutput($value) . '"></div>';
    }

    private function installTransactionTable()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'redenlace_transaction` (`id_redenlace_transaction` INT UNSIGNED NOT NULL AUTO_INCREMENT, `id_cart` INT UNSIGNED NOT NULL, `id_order` INT UNSIGNED NOT NULL, `originating_reference` VARCHAR(100) NOT NULL, `atc_reference` VARCHAR(100) DEFAULT NULL, `amount` DECIMAL(20,6) NOT NULL, `currency` CHAR(3) NOT NULL, `status` VARCHAR(32) NOT NULL, `expires_at` VARCHAR(64) DEFAULT NULL, `qr_base64` MEDIUMTEXT DEFAULT NULL, `date_add` DATETIME NOT NULL, `date_upd` DATETIME NOT NULL, PRIMARY KEY (`id_redenlace_transaction`), UNIQUE KEY `redenlace_cart` (`id_cart`), UNIQUE KEY `redenlace_originating_reference` (`originating_reference`), UNIQUE KEY `redenlace_atc_reference` (`atc_reference`)) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;');
    }

    private function canViewConfiguration()
    {
        return isset($this->context->employee) && Validate::isLoadedObject($this->context->employee) && $this->context->employee->can('view', 'AdminModules');
    }

    private function canModifyConfiguration()
    {
        return isset($this->context->employee) && Validate::isLoadedObject($this->context->employee) && $this->context->employee->can('edit', 'AdminModules');
    }

    private function isValidAdminToken()
    {
        $token = Tools::getValue('token');
        return is_string($token) && hash_equals(Tools::getAdminTokenLite('AdminModules'), $token);
    }

    private function isValidHttpsUrl($url)
    {
        $parts = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;
        return is_array($parts) && isset($parts['scheme'], $parts['host']) && $parts['scheme'] === 'https';
    }

    private function isValidHeaderName($name)
    {
        return is_string($name) && preg_match('/^[A-Za-z0-9-]+$/', $name) === 1;
    }
}
