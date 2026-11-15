
<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/RedEnlaceOAuthClient.php';
require_once __DIR__ . '/classes/RedEnlaceQRClient.php';
require_once __DIR__ . '/classes/RedEnlaceSecretStorage.php';

class Redenlace extends PaymentModule
{
    const CONFIG_SANDBOX_BASE_URL = 'REDENLACE_SANDBOX_BASE_URL';
    const CONFIG_SANDBOX_CLIENT_ID = 'REDENLACE_SANDBOX_CLIENT_ID';
    const CONFIG_SANDBOX_CLIENT_SECRET = 'REDENLACE_SANDBOX_CLIENT_SECRET';
    const CONFIG_ESTABLISHMENT_ID = 'REDENLACE_ESTABLISHMENT_ID';
    const CONFIG_ESTABLISHMENT_NAME = 'REDENLACE_ESTABLISHMENT_NAME';
    const CONFIG_WEBHOOK_URL = 'REDENLACE_WEBHOOK_URL';
    const CONFIG_WEBHOOK_KEY = 'REDENLACE_WEBHOOK_KEY';
    const CONFIG_WEBHOOK_VALUE = 'REDENLACE_WEBHOOK_VALUE';
    const CONFIG_QR_TEST_LOCK = 'REDENLACE_QR_TEST_LOCK';
    const CONFIG_QR_TEST_RESULT_PREFIX = 'REDENLACE_QR_TEST_RESULT_';

    public function __construct()
    {
        $this->name = 'redenlace';
        $this->tab = 'payments_gateways';
        $this->version = '0.1.0';
        $this->author = 'LogicaTI';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('RedEnlace QR');
        $this->description = $this->l(
            'Modulo de pagos QR mediante RedEnlace.'
        );

        $this->ps_versions_compliancy = array(
            'min' => '1.7.8.0',
            'max' => '1.7.8.99'
        );
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue(self::CONFIG_SANDBOX_BASE_URL, '')
            && Configuration::updateValue(self::CONFIG_SANDBOX_CLIENT_ID, '');
    }

    /**
     * Back Office configuration for the sandbox OAuth connection.
     *
     * No checkout hook is registered during this phase.
     *
     * @return string
     */
    public function getContent()
    {
        if (!$this->canViewConfiguration()) {
            return $this->displayError($this->l('You do not have permission to access this configuration.'));
        }

        $output = '';

        if (Tools::isSubmit('submitRedenlaceConfiguration')) {
            $output .= $this->processConfigurationSubmission();
        } elseif (Tools::isSubmit('submitRedenlaceSandboxConnection')) {
            $output .= $this->processSandboxConnectionTest();
        } elseif (Tools::isSubmit('submitRedenlaceQrTest')) {
            $output .= $this->processQrTestGeneration();
        } elseif (Tools::getValue('redenlaceQrTestResult') === '1') {
            $output .= $this->consumeQrTestResult();
        }

        return $output . $this->renderConfigurationForm() . $this->renderQrTestForm();
    }

    private function processConfigurationSubmission()
    {
        if (!$this->canModifyConfiguration() || !$this->isValidAdminToken()) {
            return $this->displayError($this->l('Invalid or expired security token.'));
        }

        $baseUrl = trim((string) Tools::getValue(self::CONFIG_SANDBOX_BASE_URL));
        $clientId = trim((string) Tools::getValue(self::CONFIG_SANDBOX_CLIENT_ID));
        $clientSecret = (string) Tools::getValue(self::CONFIG_SANDBOX_CLIENT_SECRET);
        $establishmentId = trim((string) Tools::getValue(self::CONFIG_ESTABLISHMENT_ID));
        $establishmentName = trim((string) Tools::getValue(self::CONFIG_ESTABLISHMENT_NAME));
        $webhookUrl = trim((string) Tools::getValue(self::CONFIG_WEBHOOK_URL));
        $webhookKey = trim((string) Tools::getValue(self::CONFIG_WEBHOOK_KEY));
        $webhookValue = (string) Tools::getValue(self::CONFIG_WEBHOOK_VALUE);

        if (!$this->isValidHttpsUrl($baseUrl)) {
            return $this->displayError($this->l('The Sandbox URL must be a valid HTTPS URL.'));
        }

        if ($clientId === '') {
            return $this->displayError($this->l('The Client ID is required.'));
        }

        if ($establishmentId === '' || $establishmentName === '') {
            return $this->displayError($this->l('The establishment ID and name are required.'));
        }

        if (!$this->isValidEstablishmentName($establishmentName)) {
            return $this->displayError($this->l('The establishment name contains unsupported characters.'));
        }

        if (!$this->isValidHttpsUrl($webhookUrl) || $webhookKey === '') {
            return $this->displayError($this->l('The webhook URL must be HTTPS and the webhook key is required.'));
        }

        try {
            $secretStorage = new RedEnlaceSecretStorage();
            $encryptedSecret = $clientSecret !== ''
                ? $secretStorage->encrypt($clientSecret)
                : Configuration::get(self::CONFIG_SANDBOX_CLIENT_SECRET);
            $encryptedWebhookValue = $webhookValue !== ''
                ? $secretStorage->encrypt($webhookValue)
                : Configuration::get(self::CONFIG_WEBHOOK_VALUE);
        } catch (Exception $exception) {
            return $this->displayError($this->l('A secret could not be stored securely.'));
        }

        if (!is_string($encryptedSecret) || $encryptedSecret === ''
            || !is_string($encryptedWebhookValue) || $encryptedWebhookValue === '') {
            return $this->displayError($this->l('The Client Secret and webhook secret value are required.'));
        }

        if (!Configuration::updateValue(self::CONFIG_SANDBOX_BASE_URL, $baseUrl)
            || !Configuration::updateValue(self::CONFIG_SANDBOX_CLIENT_ID, $clientId)
            || !Configuration::updateValue(self::CONFIG_SANDBOX_CLIENT_SECRET, $encryptedSecret)
            || !Configuration::updateValue(self::CONFIG_ESTABLISHMENT_ID, $establishmentId)
            || !Configuration::updateValue(self::CONFIG_ESTABLISHMENT_NAME, $establishmentName)
            || !Configuration::updateValue(self::CONFIG_WEBHOOK_URL, $webhookUrl)
            || !Configuration::updateValue(self::CONFIG_WEBHOOK_KEY, $webhookKey)
            || !Configuration::updateValue(self::CONFIG_WEBHOOK_VALUE, $encryptedWebhookValue)) {
            return $this->displayError($this->l('The Sandbox configuration could not be saved.'));
        }

        return $this->displayConfirmation($this->l('Sandbox configuration saved.'));
    }

    private function processSandboxConnectionTest()
    {
        if (!$this->canModifyConfiguration() || !$this->isValidAdminToken()) {
            return $this->displayError($this->l('Invalid or expired security token.'));
        }

        try {
            $client = $this->createSandboxOAuthClient();
            $token = $client->getAccessToken();
        } catch (Exception $exception) {
            return $this->displayError(
                $this->l('Sandbox authentication could not be completed. Verify the saved URL and credentials.')
            );
        }

        $tokenType = isset($token['token_type']) && is_string($token['token_type'])
            ? $token['token_type']
            : $this->l('Not provided');
        $expiresIn = isset($token['expires_in']) && is_numeric($token['expires_in'])
            ? (int) $token['expires_in']
            : null;

        $message = $this->l('Sandbox authentication successful.')
            . ' ' . $this->l('Token type:') . ' ' . Tools::safeOutput($tokenType) . '.';

        if ($expiresIn !== null) {
            $message .= ' ' . $this->l('Validity:') . ' ' . $expiresIn . ' ' . $this->l('seconds') . '.';
        }

        return $this->displayConfirmation($message);
    }

    private function createSandboxOAuthClient()
    {
        $baseUrl = Configuration::get(self::CONFIG_SANDBOX_BASE_URL);
        $clientId = Configuration::get(self::CONFIG_SANDBOX_CLIENT_ID);
        $establishmentId = Configuration::get(self::CONFIG_ESTABLISHMENT_ID);
        $establishmentName = Configuration::get(self::CONFIG_ESTABLISHMENT_NAME);
        $webhookUrl = Configuration::get(self::CONFIG_WEBHOOK_URL);
        $webhookKey = Configuration::get(self::CONFIG_WEBHOOK_KEY);
        $encryptedSecret = Configuration::get(self::CONFIG_SANDBOX_CLIENT_SECRET);

        if (!is_string($encryptedSecret) || $encryptedSecret === '') {
            throw new RuntimeException('Sandbox credentials are not configured.');
        }

        $secretStorage = new RedEnlaceSecretStorage();
        $clientSecret = $secretStorage->decrypt($encryptedSecret);

        return RedEnlaceOAuthClient::fromBaseUrl($baseUrl, $clientId, $clientSecret);
    }

    private function processQrTestGeneration()
    {
        if (!$this->canModifyConfiguration() || !$this->isValidAdminToken()) {
            return $this->displayError($this->l('Invalid or expired security token.'));
        }

        $amount = trim((string) Tools::getValue('redenlace_qr_test_amount', '1.00'));
        $validity = trim((string) Tools::getValue('redenlace_qr_test_validity', '300'));
        if (!is_numeric($amount) || (float) $amount <= 0 || !ctype_digit($validity) || (int) $validity <= 0) {
            return $this->displayError($this->l('Enter a positive amount and validity in seconds.'));
        }

        if ((int) Configuration::get(self::CONFIG_QR_TEST_LOCK) > time() - 90) {
            return $this->displayError($this->l('A QR test generation is already in progress.'));
        }
        Configuration::updateValue(self::CONFIG_QR_TEST_LOCK, (string) time());

        try {
            $secretStorage = new RedEnlaceSecretStorage();
            $webhookSecret = $secretStorage->decrypt(Configuration::get(self::CONFIG_WEBHOOK_VALUE));
            $oauthClient = $this->createSandboxOAuthClient();
            $qrClient = RedEnlaceQRClient::fromBaseUrl(
                $oauthClient,
                Configuration::get(self::CONFIG_SANDBOX_BASE_URL),
                Configuration::get(self::CONFIG_SANDBOX_CLIENT_ID)
            );
            $result = $qrClient->generate(array(
                'glosa' => 'Pago de prueba Petsofia',
                'moneda' => 'BOB',
                'monto' => $amount,
                'numeroReferencia' => 'PETSOFIA-TEST-' . time() . '-' . strtoupper(bin2hex(random_bytes(8))),
                'vigencia' => $validity,
                'idEstablecimiento' => Configuration::get(self::CONFIG_ESTABLISHMENT_ID),
                'nombreEstablecimiento' => Configuration::get(self::CONFIG_ESTABLISHMENT_NAME),
                'webhook' => array(
                    'url' => Configuration::get(self::CONFIG_WEBHOOK_URL),
                    'key' => Configuration::get(self::CONFIG_WEBHOOK_KEY),
                    'value' => $webhookSecret
                )
            ));
            $result['monto'] = $amount;
            $result['moneda'] = 'BOB';
            $feedback = array('success' => true, 'result' => $result);
        } catch (Exception $exception) {
            $feedback = array('success' => false);
        }

        Configuration::deleteByName(self::CONFIG_QR_TEST_LOCK);
        if (!Configuration::updateValue($this->getQrTestResultKey(), base64_encode(json_encode($feedback)))) {
            return $this->displayError($this->l('The QR test result could not be stored.'));
        }

        Tools::redirectAdmin($this->context->link->getAdminLink(
            'AdminModules', true, array(), array('configure' => $this->name, 'redenlaceQrTestResult' => 1)
        ));

        return '';
    }

    private function consumeQrTestResult()
    {
        $key = $this->getQrTestResultKey();
        $stored = Configuration::get($key);
        Configuration::deleteByName($key);
        $json = is_string($stored) ? base64_decode($stored, true) : false;
        $feedback = $json === false ? null : json_decode($json, true);

        if (!is_array($feedback) || empty($feedback['success'])) {
            return $this->displayError($this->l('The QR test could not be generated. Verify the saved configuration and try again.'));
        }

        return $this->renderQrTestResult($feedback['result']);
    }

    private function getQrTestResultKey()
    {
        return self::CONFIG_QR_TEST_RESULT_PREFIX . (int) $this->context->employee->id;
    }

    private function renderConfigurationForm()
    {
        $actionUrl = $this->context->link->getAdminLink(
            'AdminModules',
            true,
            array(),
            array('configure' => $this->name)
        );
        $baseUrl = Configuration::get(self::CONFIG_SANDBOX_BASE_URL);
        $clientId = Configuration::get(self::CONFIG_SANDBOX_CLIENT_ID);
        $establishmentId = Configuration::get(self::CONFIG_ESTABLISHMENT_ID);
        $establishmentName = Configuration::get(self::CONFIG_ESTABLISHMENT_NAME);
        $webhookUrl = Configuration::get(self::CONFIG_WEBHOOK_URL);
        $webhookKey = Configuration::get(self::CONFIG_WEBHOOK_KEY);
        $token = Tools::getAdminTokenLite('AdminModules');

        $html = '<form action="' . Tools::safeOutput($actionUrl) . '" method="post">';
        $html .= '<div class="panel">';
        $html .= '<h3><i class="icon-cog"></i> ' . $this->l('RedEnlace Sandbox') . '</h3>';
        $html .= '<p>' . $this->l('Configure the Sandbox credentials used only to test OAuth connectivity.') . '</p>';
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label required" for="redenlace_sandbox_base_url">'
            . $this->l('TEST URL (Sandbox)') . '</label>';
        $html .= '<input type="url" class="form-control" id="redenlace_sandbox_base_url" name="'
            . self::CONFIG_SANDBOX_BASE_URL . '" required value="' . Tools::safeOutput($baseUrl) . '">';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label required" for="redenlace_sandbox_client_id">'
            . $this->l('Client ID') . '</label>';
        $html .= '<input type="text" class="form-control" id="redenlace_sandbox_client_id" name="'
            . self::CONFIG_SANDBOX_CLIENT_ID . '" required value="' . Tools::safeOutput($clientId) . '">';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label class="control-label required" for="redenlace_sandbox_client_secret">'
            . $this->l('Client Secret') . '</label>';
        $html .= '<input type="password" class="form-control" id="redenlace_sandbox_client_secret" name="'
            . self::CONFIG_SANDBOX_CLIENT_SECRET . '" required autocomplete="new-password" value="">';
        $html .= '<p class="help-block">' . $this->l('The secret is never displayed. Leave blank to retain its saved value.') . '</p>';
        $html .= '</div>';
        $html .= '<div class="form-group"><label class="control-label required" for="redenlace_establishment_id">'
            . $this->l('Establishment ID') . '</label>';
        $html .= '<input type="text" class="form-control" id="redenlace_establishment_id" name="'
            . self::CONFIG_ESTABLISHMENT_ID . '" required value="' . Tools::safeOutput($establishmentId) . '"></div>';
        $html .= '<div class="form-group"><label class="control-label required" for="redenlace_establishment_name">'
            . $this->l('Establishment name') . '</label>';
        $html .= '<input type="text" class="form-control" id="redenlace_establishment_name" name="'
            . self::CONFIG_ESTABLISHMENT_NAME . '" required value="' . Tools::safeOutput($establishmentName) . '"></div>';
        $html .= '<div class="form-group"><label class="control-label required" for="redenlace_webhook_url">'
            . $this->l('Webhook URL') . '</label>';
        $html .= '<input type="url" class="form-control" id="redenlace_webhook_url" name="'
            . self::CONFIG_WEBHOOK_URL . '" required value="' . Tools::safeOutput($webhookUrl) . '"></div>';
        $html .= '<div class="form-group"><label class="control-label required" for="redenlace_webhook_key">'
            . $this->l('Webhook key') . '</label>';
        $html .= '<input type="text" class="form-control" id="redenlace_webhook_key" name="'
            . self::CONFIG_WEBHOOK_KEY . '" required value="' . Tools::safeOutput($webhookKey) . '"></div>';
        $html .= '<div class="form-group"><label class="control-label required" for="redenlace_webhook_value">'
            . $this->l('Webhook secret value') . '</label>';
        $html .= '<input type="password" class="form-control" id="redenlace_webhook_value" name="'
            . self::CONFIG_WEBHOOK_VALUE . '" autocomplete="new-password" value="">';
        $html .= '<p class="help-block">' . $this->l('Encrypted and never displayed. Leave blank to retain its saved value.') . '</p>';
        $html .= '</div>';
        $html .= '<input type="hidden" name="token" value="' . Tools::safeOutput($token) . '">';
        $html .= '<div class="panel-footer">';
        $html .= '<button type="submit" name="submitRedenlaceConfiguration" class="btn btn-default pull-right">';
        $html .= '<i class="process-icon-save"></i> ' . $this->l('Save') . '</button>';
        $html .= '<button type="submit" name="submitRedenlaceSandboxConnection" class="btn btn-default">';
        $html .= '<i class="icon-plug"></i> ' . $this->l('Test Sandbox connection') . '</button>';
        $html .= '</div></div></form>';

        return $html;
    }

    private function renderQrTestForm()
    {
        $actionUrl = $this->context->link->getAdminLink(
            'AdminModules', true, array(), array('configure' => $this->name)
        );
        $token = Tools::getAdminTokenLite('AdminModules');
        $html = '<form action="' . Tools::safeOutput($actionUrl) . '" method="post" id="redenlace-qr-test-form"><div class="panel">';
        $html .= '<h3><i class="icon-qrcode"></i> ' . $this->l('Generate Sandbox test QR') . '</h3>';
        $html .= '<p>' . $this->l('This explicit action creates no PrestaShop order and does not confirm any payment.') . '</p>';
        $html .= '<div class="form-group"><label class="control-label">' . $this->l('Description') . '</label>';
        $html .= '<input type="text" class="form-control" value="Pago de prueba Petsofia" readonly></div>';
        $html .= '<div class="form-group"><label class="control-label">' . $this->l('Currency') . '</label>';
        $html .= '<input type="text" class="form-control" value="BOB" readonly></div>';
        $html .= '<div class="form-group"><label class="control-label required" for="redenlace_qr_test_amount">' . $this->l('Amount') . '</label>';
        $html .= '<input type="text" class="form-control" id="redenlace_qr_test_amount" name="redenlace_qr_test_amount" required value="1.00"></div>';
        $html .= '<div class="form-group"><label class="control-label required" for="redenlace_qr_test_validity">' . $this->l('Validity (seconds)') . '</label>';
        $html .= '<input type="number" min="1" step="1" class="form-control" id="redenlace_qr_test_validity" name="redenlace_qr_test_validity" required value="300"></div>';
        $html .= '<input type="hidden" name="token" value="' . Tools::safeOutput($token) . '">';
        $html .= '<div class="panel-footer"><button type="submit" name="submitRedenlaceQrTest" class="btn btn-primary" id="redenlace-qr-test-submit">';
        $html .= '<i class="icon-qrcode"></i> ' . $this->l('Generate test QR') . '</button></div></div></form>';
        $html .= '<script>document.getElementById("redenlace-qr-test-form").addEventListener("submit",function(){document.getElementById("redenlace-qr-test-submit").disabled=true;});</script>';

        return $html;
    }

    private function renderQrTestResult(array $result)
    {
        foreach (array('numeroReferenciaOriginante', 'numeroReferencia', 'estado', 'monto', 'moneda', 'fechaExpiracion', 'qr') as $field) {
            if (!isset($result[$field]) || !is_scalar($result[$field])) {
                return $this->displayError($this->l('The QR test result is incomplete.'));
            }
        }

        $html = '<div class="panel"><h3><i class="icon-check"></i> ' . $this->l('Sandbox test QR generated') . '</h3><dl class="dl-horizontal">';
        $html .= '<dt>' . $this->l('Originating reference') . '</dt><dd>' . Tools::safeOutput($result['numeroReferenciaOriginante']) . '</dd>';
        $html .= '<dt>' . $this->l('ATC reference') . '</dt><dd>' . Tools::safeOutput($result['numeroReferencia']) . '</dd>';
        $html .= '<dt>' . $this->l('Status') . '</dt><dd>' . Tools::safeOutput($result['estado']) . '</dd>';
        $html .= '<dt>' . $this->l('Amount') . '</dt><dd>' . Tools::safeOutput($result['monto']) . ' ' . Tools::safeOutput($result['moneda']) . '</dd>';
        $html .= '<dt>' . $this->l('Expiration') . '</dt><dd>' . Tools::safeOutput($result['fechaExpiracion']) . '</dd></dl>';
        $html .= '<p><img alt="' . $this->l('RedEnlace payment QR') . '" src="data:image/png;base64,' . htmlspecialchars($result['qr'], ENT_QUOTES, 'UTF-8') . '"></p></div>';

        return $html;
    }

    private function canViewConfiguration()
    {
        return isset($this->context->employee)
            && Validate::isLoadedObject($this->context->employee)
            && $this->context->employee->can('view', 'AdminModules');
    }

    private function canModifyConfiguration()
    {
        return isset($this->context->employee)
            && Validate::isLoadedObject($this->context->employee)
            && $this->context->employee->can('edit', 'AdminModules');
    }

    private function isValidAdminToken()
    {
        $token = Tools::getValue('token');
        $expectedToken = Tools::getAdminTokenLite('AdminModules');

        return is_string($token) && hash_equals($expectedToken, $token);
    }

    /**
     * @param string $url
     * @return bool
     */
    private function isValidHttpsUrl($url)
    {
        $parts = filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && $parts['scheme'] === 'https';
    }

    /**
     * @param string $name
     * @return bool
     */
    private function isValidEstablishmentName($name)
    {
        return is_string($name)
            && preg_match('/^[A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ ]+$/u', $name) === 1;
    }
}
