
<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/RedEnlaceOAuthClient.php';
require_once __DIR__ . '/classes/RedEnlaceSecretStorage.php';

class Redenlace extends PaymentModule
{
    const CONFIG_SANDBOX_BASE_URL = 'REDENLACE_SANDBOX_BASE_URL';
    const CONFIG_SANDBOX_CLIENT_ID = 'REDENLACE_SANDBOX_CLIENT_ID';
    const CONFIG_SANDBOX_CLIENT_SECRET = 'REDENLACE_SANDBOX_CLIENT_SECRET';

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
        }

        return $output . $this->renderConfigurationForm();
    }

    private function processConfigurationSubmission()
    {
        if (!$this->canModifyConfiguration() || !$this->isValidAdminToken()) {
            return $this->displayError($this->l('Invalid or expired security token.'));
        }

        $baseUrl = trim((string) Tools::getValue(self::CONFIG_SANDBOX_BASE_URL));
        $clientId = trim((string) Tools::getValue(self::CONFIG_SANDBOX_CLIENT_ID));
        $clientSecret = (string) Tools::getValue(self::CONFIG_SANDBOX_CLIENT_SECRET);

        if (!$this->isValidHttpsUrl($baseUrl)) {
            return $this->displayError($this->l('The Sandbox URL must be a valid HTTPS URL.'));
        }

        if ($clientId === '') {
            return $this->displayError($this->l('The Client ID is required.'));
        }

        if ($clientSecret === '') {
            return $this->displayError($this->l('The Client Secret is required.'));
        }

        try {
            $secretStorage = new RedEnlaceSecretStorage();
            $encryptedSecret = $secretStorage->encrypt($clientSecret);
        } catch (Exception $exception) {
            return $this->displayError($this->l('The Client Secret could not be stored securely.'));
        }

        if (!Configuration::updateValue(self::CONFIG_SANDBOX_BASE_URL, $baseUrl)
            || !Configuration::updateValue(self::CONFIG_SANDBOX_CLIENT_ID, $clientId)
            || !Configuration::updateValue(self::CONFIG_SANDBOX_CLIENT_SECRET, $encryptedSecret)) {
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
        $encryptedSecret = Configuration::get(self::CONFIG_SANDBOX_CLIENT_SECRET);

        if (!is_string($encryptedSecret) || $encryptedSecret === '') {
            throw new RuntimeException('Sandbox credentials are not configured.');
        }

        $secretStorage = new RedEnlaceSecretStorage();
        $clientSecret = $secretStorage->decrypt($encryptedSecret);

        return RedEnlaceOAuthClient::fromBaseUrl($baseUrl, $clientId, $clientSecret);
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
        $html .= '<p class="help-block">' . $this->l('The secret is never displayed after it is saved.') . '</p>';
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

    private function isValidHttpsUrl($url)
    {
        $parts = filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && $parts['scheme'] === 'https';
    }
}
