<?php

/**
 * Generates RedEnlace payment QR codes through the documented QR API.
 *
 * The API documentation identifies transaction_id_atc as an interceptor
 * header, but does not document how its value must be generated. It is
 * intentionally not sent until RedEnlace confirms that rule.
 */
class RedEnlaceQRClient
{
    const GENERATE_PATH = '/qr/simple/v2/generate';
    const VERIFY_PATH = '/qr/simple/v2/verify/';
    const DEFAULT_CONNECT_TIMEOUT = 10;
    const DEFAULT_TIMEOUT = 30;
    const MAX_QR_PNG_BYTES = 1048576;
    const AMOUNT_PLACEHOLDER = '__REDENLACE_JSON_AMOUNT__';

    /** @var RedEnlaceOAuthClient */
    private $oauthClient;

    /** @var string */
    private $generateUrl;

    /** @var string */
    private $verifyUrl;

    /** @var string */
    private $clientId;

    /** @var callable|null */
    private $httpTransport;

    /** @var int */
    private $maxQrPngBytes;

    /**
     * @param RedEnlaceOAuthClient $oauthClient
     * @param string $generateUrl Complete HTTPS QR generation endpoint.
     * @param string $clientId Client ID supplied by RedEnlace.
     * @param callable|null $httpTransport Test-only transport. It must return
     *   an array with status and body keys.
     * @param int $maxQrPngBytes Maximum decoded PNG size accepted locally.
     */
    public function __construct(
        RedEnlaceOAuthClient $oauthClient,
        $generateUrl,
        $clientId,
        $httpTransport = null,
        $maxQrPngBytes = self::MAX_QR_PNG_BYTES
    ) {
        $this->assertHttpsUrl($generateUrl);

        if (!is_string($clientId) || trim($clientId) === '') {
            throw new InvalidArgumentException('RedEnlace client ID is required.');
        }

        if ($httpTransport !== null && !is_callable($httpTransport)) {
            throw new InvalidArgumentException('The HTTP transport must be callable.');
        }

        if (!is_int($maxQrPngBytes) || $maxQrPngBytes < 1) {
            throw new InvalidArgumentException('The QR PNG size limit must be a positive integer.');
        }

        $this->oauthClient = $oauthClient;
        $this->generateUrl = trim($generateUrl);
        $this->verifyUrl = preg_replace('#/generate$#', '/verify/', $this->generateUrl);
        if (!is_string($this->verifyUrl) || $this->verifyUrl === $this->generateUrl) {
            throw new InvalidArgumentException('RedEnlace QR verification URL could not be determined.');
        }
        $this->clientId = trim($clientId);
        $this->httpTransport = $httpTransport;
        $this->maxQrPngBytes = $maxQrPngBytes;
    }

    /**
     * @param string $baseUrl RedEnlace environment base URL.
     * @param string $clientId Client ID supplied by RedEnlace.
     * @return RedEnlaceQRClient
     */
    public static function fromBaseUrl(RedEnlaceOAuthClient $oauthClient, $baseUrl, $clientId)
    {
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            throw new InvalidArgumentException('RedEnlace base URL is required.');
        }

        return new self(
            $oauthClient,
            rtrim(trim($baseUrl), '/') . self::GENERATE_PATH,
            $clientId
        );
    }

    /**
     * Generates a QR code. This method performs a network request only when
     * it is explicitly called.
     *
     * @param array $request QR request fields documented by RedEnlace.
     * @return array Safe normalized QR result; it never includes credentials.
     */
    public function generate(array $request)
    {
        $payload = $this->buildPayload($request);
        $token = $this->oauthClient->getAccessToken();

        if (!isset($token['access_token']) || !is_string($token['access_token']) || $token['access_token'] === '') {
            throw new RuntimeException('RedEnlace OAuth did not provide an access token.');
        }

        $json = $this->encodePayload($payload, $request['monto']);

        $response = $this->sendRequest(array(
            'access_token: ' . $token['access_token'],
            'client_id: ' . $this->clientId,
            'Content-Type: application/json',
            'Accept: application/json'
        ), $json);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException($this->buildHttpErrorMessage($response));
        }

        $body = json_decode($response['body'], true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
            throw new RuntimeException('RedEnlace QR generation returned invalid JSON.');
        }

        return $this->normalizeResponse($body);
    }

    /**
     * Obtains the authoritative state of a QR using its ATC reference.
     *
     * @param string $atcReference Reference returned by QR generation.
     * @return array Normalized verification data.
     */
    public function verify($atcReference)
    {
        if (!is_string($atcReference) || trim($atcReference) === '') {
            throw new InvalidArgumentException('RedEnlace ATC reference is required for verification.');
        }

        $token = $this->oauthClient->getAccessToken();
        if (!isset($token['access_token']) || !is_string($token['access_token']) || $token['access_token'] === '') {
            throw new RuntimeException('RedEnlace OAuth did not provide an access token.');
        }

        $response = $this->sendGetRequest(
            $this->verifyUrl . rawurlencode(trim($atcReference)),
            array(
                'access_token: ' . $token['access_token'],
                'client_id: ' . $this->clientId,
                'Content-Type: application/json',
                'Accept: application/json'
            )
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('RedEnlace QR verification failed with HTTP status ' . $response['status'] . '.');
        }

        $body = json_decode($response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)
            || !isset($body['success']) || $body['success'] !== true
            || !isset($body['data']) || !is_array($body['data'])) {
            throw new RuntimeException('RedEnlace QR verification returned an invalid response.');
        }

        $data = $body['data'];
        foreach (array('estado', 'importe', 'moneda', 'numeroReferenciaOriginante', 'numeroReferencia') as $field) {
            $this->assertRequiredScalar($data, $field);
        }
        if (!is_numeric($data['importe'])) {
            throw new RuntimeException('RedEnlace QR verification returned an invalid amount.');
        }

        return array(
            'estado' => (string) $data['estado'],
            'importe' => (string) $data['importe'],
            'moneda' => (string) $data['moneda'],
            'numeroReferenciaOriginante' => (string) $data['numeroReferenciaOriginante'],
            'numeroReferencia' => (string) $data['numeroReferencia']
        );
    }

    /**
     * @param array $request
     * @return array
     */
    private function buildPayload(array $request)
    {
        $requiredFields = array(
            'glosa',
            'moneda',
            'monto',
            'numeroReferencia',
            'vigencia',
            'idEstablecimiento',
            'nombreEstablecimiento'
        );

        foreach ($requiredFields as $field) {
            $this->assertRequiredScalar($request, $field);
        }

        if ($request['moneda'] !== 'BOB') {
            throw new InvalidArgumentException('RedEnlace QR currency must be BOB.');
        }

        if (!is_numeric($request['monto']) || (float) $request['monto'] <= 0) {
            throw new InvalidArgumentException('RedEnlace QR amount must be a positive decimal.');
        }

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $request['monto'])) {
            throw new InvalidArgumentException('RedEnlace QR amount can contain no more than two decimal places.');
        }

        if (!ctype_digit((string) $request['vigencia']) || (int) $request['vigencia'] < 1) {
            throw new InvalidArgumentException('RedEnlace QR validity must be a positive integer.');
        }

        if (!ctype_digit((string) $request['idEstablecimiento']) || (int) $request['idEstablecimiento'] < 1) {
            throw new InvalidArgumentException('RedEnlace establishment ID must be a positive integer.');
        }

        if (!isset($request['webhook']) || !is_array($request['webhook'])) {
            throw new InvalidArgumentException('RedEnlace QR webhook data is required.');
        }

        foreach (array('url', 'key', 'value') as $field) {
            $this->assertRequiredScalar($request['webhook'], $field);
        }

        return array(
            'glosa' => $request['glosa'],
            'moneda' => $request['moneda'],
            // Encode this placeholder as a JSON numeric literal with its two
            // decimal places preserved; do not send a JSON string.
            'monto' => self::AMOUNT_PLACEHOLDER,
            'numeroReferencia' => $request['numeroReferencia'],
            'vigencia' => (int) $request['vigencia'],
            'idEstablecimiento' => (int) $request['idEstablecimiento'],
            'nombreEstablecimiento' => $request['nombreEstablecimiento'],
            'webhook' => array(
                'url' => $request['webhook']['url'],
                'key' => $request['webhook']['key'],
                'value' => $request['webhook']['value']
            )
        );
    }

    /**
     * Encodes the documented decimal amount with exactly two decimal places.
     * PHP floats discard trailing zeroes (for example 11.30 becomes 11.3),
     * which some gateway validators treat as a different decimal scale.
     *
     * @param array $payload
     * @param string|int|float $amount
     * @return string
     */
    private function encodePayload(array $payload, $amount)
    {
        $json = json_encode($payload);
        if ($json === false) {
            throw new RuntimeException('The RedEnlace QR request could not be encoded as JSON.');
        }

        $amount = (string) $amount;
        $parts = explode('.', $amount, 2);
        $integer = ltrim($parts[0], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';
        $decimal = $integer . '.' . $fraction;

        $json = str_replace('"' . self::AMOUNT_PLACEHOLDER . '"', $decimal, $json);
        if (strpos($json, self::AMOUNT_PLACEHOLDER) !== false) {
            throw new RuntimeException('The RedEnlace QR amount could not be encoded.');
        }

        return $json;
    }

    /**
     * @param array $response
     * @return array
     */
    private function normalizeResponse(array $response)
    {
        if (!isset($response['success']) || $response['success'] !== true) {
            throw new RuntimeException('RedEnlace QR generation did not report success.');
        }

        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new RuntimeException('RedEnlace QR generation response is incomplete.');
        }

        $data = $response['data'];
        foreach (array('numeroReferencia', 'estado', 'fechaExpiracion', 'numeroReferenciaOriginante', 'qr') as $field) {
            $this->assertRequiredScalar($data, $field);
        }

        if (!is_string($data['qr'])) {
            throw new RuntimeException('RedEnlace QR image must be a Base64 PNG string.');
        }

        $this->assertPngBase64($data['qr']);

        return array(
            'numeroReferencia' => (string) $data['numeroReferencia'],
            'estado' => (string) $data['estado'],
            'fechaExpiracion' => (string) $data['fechaExpiracion'],
            'numeroReferenciaOriginante' => (string) $data['numeroReferenciaOriginante'],
            'qr' => $data['qr']
        );
    }

    /**
     * @param array $headers
     * @param string $json
     * @return array
     */
    private function sendRequest(array $headers, $json)
    {
        if ($this->httpTransport !== null) {
            $response = call_user_func($this->httpTransport, $this->generateUrl, $headers, $json);
            return $this->validateTransportResponse($response);
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for RedEnlace QR generation.');
        }

        $ch = curl_init($this->generateUrl);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialize the RedEnlace QR HTTP client.');
        }

        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::DEFAULT_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ));

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Unable to request RedEnlace QR generation.');
        }

        return array('status' => $status, 'body' => $body);
    }

    /**
     * @param string $url
     * @param array $headers
     * @return array
     */
    private function sendGetRequest($url, array $headers)
    {
        if ($this->httpTransport !== null) {
            $response = call_user_func($this->httpTransport, $url, $headers, null);
            return $this->validateTransportResponse($response);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for RedEnlace QR verification.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize the RedEnlace QR verification client.');
        }
        curl_setopt_array($ch, array(
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::DEFAULT_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ));
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Unable to request RedEnlace QR verification.');
        }
        return array('status' => $status, 'body' => $body);
    }

    /**
     * @param mixed $response
     * @return array
     */
    private function validateTransportResponse($response)
    {
        if (!is_array($response)
            || !isset($response['status'], $response['body'])
            || !is_int($response['status'])
            || !is_string($response['body'])) {
            throw new RuntimeException('The RedEnlace QR test transport returned an invalid response.');
        }

        return $response;
    }

    /**
     * Extracts only documented, non-sensitive validation identifiers from an
     * error response. It never logs or exposes the complete provider body.
     *
     * @param array $response
     * @return string
     */
    private function buildHttpErrorMessage(array $response)
    {
        $message = 'RedEnlace QR generation failed with HTTP status ' . $response['status'] . '.';
        $body = json_decode($response['body'], true);

        if (!is_array($body) || !isset($body['errors']) || !is_array($body['errors'])) {
            return $message;
        }

        $identifiers = array();
        foreach ($body['errors'] as $error) {
            if (!is_array($error)) {
                continue;
            }

            $field = isset($error['field']) ? $this->sanitizeDiagnosticIdentifier($error['field']) : '';
            $code = isset($error['code']) ? $this->sanitizeDiagnosticIdentifier($error['code']) : '';
            if ($field !== '' || $code !== '') {
                $identifier = trim($field . ($field !== '' && $code !== '' ? ':' : '') . $code);
                if ($field === 'monto' && isset($error['message'])) {
                    $detail = $this->sanitizeAmountDiagnostic($error['message']);
                    if ($detail !== '') {
                        $identifier .= ' (' . $detail . ')';
                    }
                }
                $identifiers[] = $identifier;
            }
        }

        return empty($identifiers)
            ? $message
            : $message . ' Validation: ' . implode(', ', array_slice($identifiers, 0, 5)) . '.';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function sanitizeDiagnosticIdentifier($value)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $value);
        return substr($value, 0, 80);
    }

    /**
     * Keeps the provider's amount-validation detail useful for administrators
     * while excluding control characters and limiting its size.
     *
     * @param mixed $value
     * @return string
     */
    private function sanitizeAmountDiagnostic($value)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', (string) $value);
        return substr(trim($value), 0, 160);
    }

    /**
     * @param array $values
     * @param string $field
     * @return void
     */
    private function assertRequiredScalar(array $values, $field)
    {
        if (!array_key_exists($field, $values)
            || !is_scalar($values[$field])
            || trim((string) $values[$field]) === '') {
            throw new InvalidArgumentException('RedEnlace QR field ' . $field . ' is required.');
        }
    }

    /**
     * @param string $base64
     * @return void
     */
    private function assertPngBase64($base64)
    {
        $png = base64_decode($base64, true);

        if ($png === false || strlen($png) > $this->maxQrPngBytes) {
            throw new RuntimeException('RedEnlace QR image has an invalid Base64 format or exceeds the local size limit.');
        }

        if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('RedEnlace QR image is not a PNG.');
        }
    }

    /**
     * @param string $url
     * @return void
     */
    private function assertHttpsUrl($url)
    {
        $normalizedUrl = is_string($url) ? trim($url) : '';
        $parts = $normalizedUrl !== '' ? parse_url($normalizedUrl) : false;

        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https') {
            throw new InvalidArgumentException('RedEnlace QR URL must be a valid HTTPS URL.');
        }
    }
}
