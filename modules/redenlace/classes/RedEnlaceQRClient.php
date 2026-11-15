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
    const DEFAULT_CONNECT_TIMEOUT = 10;
    const DEFAULT_TIMEOUT = 30;
    const MAX_QR_PNG_BYTES = 1048576;

    /** @var RedEnlaceOAuthClient */
    private $oauthClient;

    /** @var string */
    private $generateUrl;

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

        $json = json_encode($payload);

        if ($json === false) {
            throw new RuntimeException('The RedEnlace QR request could not be encoded as JSON.');
        }

        $response = $this->sendRequest(array(
            'access_token: ' . $token['access_token'],
            'client_id: ' . $this->clientId,
            'Content-Type: application/json',
            'Accept: application/json'
        ), $json);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('RedEnlace QR generation failed with HTTP status ' . $response['status'] . '.');
        }

        $body = json_decode($response['body'], true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
            throw new RuntimeException('RedEnlace QR generation returned invalid JSON.');
        }

        return $this->normalizeResponse($body);
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

        if (!isset($request['webhook']) || !is_array($request['webhook'])) {
            throw new InvalidArgumentException('RedEnlace QR webhook data is required.');
        }

        foreach (array('url', 'key', 'value') as $field) {
            $this->assertRequiredScalar($request['webhook'], $field);
        }

        return array(
            'glosa' => $request['glosa'],
            'moneda' => $request['moneda'],
            'monto' => $request['monto'],
            'numeroReferencia' => $request['numeroReferencia'],
            'vigencia' => $request['vigencia'],
            'idEstablecimiento' => $request['idEstablecimiento'],
            'nombreEstablecimiento' => $request['nombreEstablecimiento'],
            'webhook' => array(
                'url' => $request['webhook']['url'],
                'key' => $request['webhook']['key'],
                'value' => $request['webhook']['value']
            )
        );
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
