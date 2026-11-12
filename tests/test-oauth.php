<?php

/**
 * Local OAuth connectivity test. This file is outside the module so it is not
 * included when modules/redenlace is deployed through FTP.
 *
 * Run from the repository root:
 * php tests/test-oauth.php
 */

function fail($message)
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
}

function loadLocalEnvironment($path)
{
    if (!is_readable($path)) {
        fail('Local .env file not found or not readable: ' . $path);
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        fail('Unable to read the local .env file.');
    }

    foreach ($lines as $lineNumber => $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        if (strpos($line, 'export ') === 0) {
            $line = trim(substr($line, 7));
        }

        $separator = strpos($line, '=');

        if ($separator === false) {
            fail('Invalid .env entry at line ' . ($lineNumber + 1) . '.');
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
            fail('Invalid variable name at line ' . ($lineNumber + 1) . '.');
        }

        if (strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

loadLocalEnvironment(dirname(__DIR__) . '/.env');
require_once dirname(__DIR__) . '/modules/redenlace/classes/RedEnlaceOAuthClient.php';

foreach (array('REDENLACE_BASE_URL', 'REDENLACE_CLIENT_ID', 'REDENLACE_CLIENT_SECRET') as $name) {
    if (getenv($name) === false || trim(getenv($name)) === '') {
        fail($name . ' must be defined in .env.');
    }
}

try {
    $client = RedEnlaceOAuthClient::fromEnvironment();
    $token = $client->getAccessToken();
} catch (Exception $exception) {
    fail($exception->getMessage());
}

if (!isset($token['access_token']) || !is_string($token['access_token']) || $token['access_token'] === '') {
    fail('OAuth response did not contain a valid access token.');
}

echo 'OAuth connection successful.' . PHP_EOL;

if (isset($token['token_type']) && is_string($token['token_type'])) {
    echo 'Token type: ' . $token['token_type'] . PHP_EOL;
}

if (isset($token['expires_in'])) {
    echo 'Token expires in: ' . (int) $token['expires_in'] . ' seconds.' . PHP_EOL;
}
