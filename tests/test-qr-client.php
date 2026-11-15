<?php

require_once dirname(__DIR__) . '/modules/redenlace/classes/RedEnlaceOAuthClient.php';
require_once dirname(__DIR__) . '/modules/redenlace/classes/RedEnlaceQRClient.php';

class FakeRedEnlaceOAuthClient extends RedEnlaceOAuthClient
{
    public function __construct()
    {
        parent::__construct('https://sandbox.example.test/oauth', 'test-client', 'test-secret');
    }

    public function getAccessToken()
    {
        return array('access_token' => 'test-access-token');
    }
}

function fail($message)
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
}

function assertTrue($condition, $message)
{
    if (!$condition) {
        fail($message);
    }
}

function expectException($callback, $message)
{
    try {
        call_user_func($callback);
    } catch (Exception $exception) {
        return;
    }

    fail($message);
}

function validRequest()
{
    return array(
        'glosa' => 'Pedido de prueba',
        'moneda' => 'BOB',
        'monto' => '10.00',
        'numeroReferencia' => 'ORDER-1001',
        'vigencia' => '300',
        'idEstablecimiento' => 'EST-1',
        'nombreEstablecimiento' => 'Petsofia',
        'webhook' => array(
            'url' => 'https://shop.example.test/webhook',
            'key' => 'test-webhook-key',
            'value' => 'test-webhook-value'
        )
    );
}

function successResponse()
{
    return json_encode(array(
        'success' => true,
        'data' => array(
            'numeroReferencia' => 'ORDER-1001',
            'estado' => 'provider-response',
            'fechaExpiracion' => '2026-11-15T12:00:00Z',
            'numeroReferenciaOriginante' => 'ORDER-1001',
            'qr' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADElEQVR42mNk+M/wHwAF/gL+TH/0AAAAAElFTkSuQmCC'
        )
    ));
}

$capturedHeaders = array();
$capturedPayload = null;
$transport = function ($url, $headers, $json) use (&$capturedHeaders, &$capturedPayload) {
    $capturedHeaders = $headers;
    $capturedPayload = $json;

    return array('status' => 200, 'body' => successResponse());
};

$client = new RedEnlaceQRClient(
    new FakeRedEnlaceOAuthClient(),
    'https://sandbox.example.test/qr/simple/v2/generate',
    'test-client',
    $transport
);
$result = $client->generate(validRequest());

assertTrue($result['numeroReferencia'] === 'ORDER-1001', 'The successful response was not normalized.');
assertTrue(in_array('access_token: test-access-token', $capturedHeaders, true), 'The access_token header was not sent.');
assertTrue(in_array('client_id: test-client', $capturedHeaders, true), 'The client_id header was not sent.');
assertTrue(in_array('Content-Type: application/json', $capturedHeaders, true), 'The JSON content type was not sent.');
foreach ($capturedHeaders as $header) {
    assertTrue(stripos($header, 'transaction_id_atc:') !== 0, 'transaction_id_atc must not be sent without a documented value rule.');
}

if (!is_string($capturedPayload)) {
    fail('The QR request payload was not captured.');
}

$decodedPayload = json_decode($capturedPayload, true);

if (!is_array($decodedPayload)
    || !isset($decodedPayload['webhook'])
    || !is_array($decodedPayload['webhook'])) {
    fail('The webhook payload was not sent.');
}

assertTrue($decodedPayload['webhook']['url'] === 'https://shop.example.test/webhook', 'The webhook payload was not sent.');

$httpErrorClient = new RedEnlaceQRClient(
    new FakeRedEnlaceOAuthClient(),
    'https://sandbox.example.test/qr/simple/v2/generate',
    'test-client',
    function () {
        return array('status' => 500, 'body' => '{"error":"internal"}');
    }
);
expectException(function () use ($httpErrorClient) {
    $httpErrorClient->generate(validRequest());
}, 'HTTP errors must be rejected.');

$invalidJsonClient = new RedEnlaceQRClient(
    new FakeRedEnlaceOAuthClient(),
    'https://sandbox.example.test/qr/simple/v2/generate',
    'test-client',
    function () {
        return array('status' => 200, 'body' => '{invalid');
    }
);
expectException(function () use ($invalidJsonClient) {
    $invalidJsonClient->generate(validRequest());
}, 'Invalid JSON must be rejected.');

$incompleteResponseClient = new RedEnlaceQRClient(
    new FakeRedEnlaceOAuthClient(),
    'https://sandbox.example.test/qr/simple/v2/generate',
    'test-client',
    function () {
        return array('status' => 200, 'body' => '{"success":true,"data":{"numeroReferencia":"ORDER-1001"}}');
    }
);
expectException(function () use ($incompleteResponseClient) {
    $incompleteResponseClient->generate(validRequest());
}, 'Incomplete QR responses must be rejected.');

echo "RedEnlace QR client simulated tests passed." . PHP_EOL;
