
<?php

/**
 * Obtains OAuth client-credentials tokens for RedEnlace server-side requests.
 */
class RedEnlaceOAuthClient
{
    const TOKEN_PATH = '/oauth-client-credentials/access-token?grant_type=client_credentials';

    private $tokenUrl;
    private $clientId;
    private $clientSecret;

    /**
     * @param string $tokenUrl Complete HTTPS OAuth token endpoint.
     * @param string $clientId OAuth client ID supplied by RedEnlace.
     * @param string $clientSecret OAuth client secret supplied by RedEnlace.
     */
    public function __construct($tokenUrl, $clientId, $clientSecret)
    {
        $this->assertTokenUrl($tokenUrl);
        $this->assertCredential($clientId, 'client ID');
        $this->assertCredential($clientSecret, 'client secret');

        $this->tokenUrl = $tokenUrl;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Creates a client from a RedEnlace environment base URL.
     *
     * @param string $baseUrl For example, the sandbox base URL from secure configuration.
     * @return RedEnlaceOAuthClient
     */
    public static function fromBaseUrl($baseUrl, $clientId, $clientSecret)
    {
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            throw new InvalidArgumentException('RedEnlace base URL is required.');
        }

        return new self(
            rtrim(trim($baseUrl), '/') . self::TOKEN_PATH,
            $clientId,
            $clientSecret
        );
    }

    /**
     * Creates a client from server-provided environment variables.
     *
     * REDENLACE_BASE_URL, REDENLACE_CLIENT_ID and REDENLACE_CLIENT_SECRET
     * must be configured in the PHP process environment. This method does not
     * load a .env file, so credentials are never read from the module package.
     *
     * @return RedEnlaceOAuthClient
     */
    public static function fromEnvironment()
    {
        $baseUrl = getenv('REDENLACE_BASE_URL');
        $clientId = getenv('REDENLACE_CLIENT_ID');
        $clientSecret = getenv('REDENLACE_CLIENT_SECRET');

        return self::fromBaseUrl($baseUrl, $clientId, $clientSecret);
    }

    /**
     * @return array OAuth response containing access_token, token_type and expires_in.
     */
    public function getAccessToken()
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for RedEnlace OAuth.');
        }

        $ch = curl_init($this->tokenUrl);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialize the OAuth HTTP client.');
        }

        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->clientId . ':' . $this->clientSecret,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ));

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Unable to request a RedEnlace OAuth token: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(
                'RedEnlace OAuth token request failed with HTTP status ' . $httpCode
            );
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new RuntimeException('RedEnlace OAuth returned an invalid JSON response.');
        }

        if (!isset($data['access_token']) || !is_string($data['access_token']) || $data['access_token'] === '') {
            throw new RuntimeException('RedEnlace OAuth response does not contain an access token.');
        }

        return $data;
    }

    private function assertTokenUrl($tokenUrl)
    {
        $url = is_string($tokenUrl) ? trim($tokenUrl) : '';
        $parts = $url !== '' ? parse_url($url) : false;

        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https') {
            throw new InvalidArgumentException('RedEnlace OAuth token URL must be a valid HTTPS URL.');
        }
    }

    private function assertCredential($credential, $name)
    {
        if (!is_string($credential) || trim($credential) === '') {
            throw new InvalidArgumentException('RedEnlace OAuth ' . $name . ' is required.');
        }
    }
}
