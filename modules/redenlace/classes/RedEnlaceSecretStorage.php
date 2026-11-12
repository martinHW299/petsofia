<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Encrypts module secrets before persistence in PrestaShop Configuration.
 */
class RedEnlaceSecretStorage
{
    const CIPHER = 'aes-256-cbc';
    const PREFIX = 'v1:';

    /**
     * @param string $secret
     * @return string
     */
    public function encrypt($secret)
    {
        if (!is_string($secret) || $secret === '') {
            throw new InvalidArgumentException('A secret is required.');
        }

        $this->assertOpenSslAvailable();

        $key = $this->getEncryptionKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $ciphertext = openssl_encrypt($secret, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt the secret.');
        }

        $authenticationTag = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        return self::PREFIX . base64_encode($iv . $authenticationTag . $ciphertext);
    }

    /**
     * @param string $storedSecret
     * @return string
     */
    public function decrypt($storedSecret)
    {
        if (!is_string($storedSecret) || strpos($storedSecret, self::PREFIX) !== 0) {
            throw new RuntimeException('The stored secret is invalid.');
        }

        $this->assertOpenSslAvailable();

        $payload = base64_decode(substr($storedSecret, strlen(self::PREFIX)), true);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 32;

        if ($payload === false || strlen($payload) <= $ivLength + $tagLength) {
            throw new RuntimeException('The stored secret is invalid.');
        }

        $iv = substr($payload, 0, $ivLength);
        $authenticationTag = substr($payload, $ivLength, $tagLength);
        $ciphertext = substr($payload, $ivLength + $tagLength);
        $key = $this->getEncryptionKey();
        $expectedTag = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        if (!hash_equals($expectedTag, $authenticationTag)) {
            throw new RuntimeException('The stored secret could not be verified.');
        }

        $secret = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($secret === false || $secret === '') {
            throw new RuntimeException('The stored secret could not be decrypted.');
        }

        return $secret;
    }

    private function assertOpenSslAvailable()
    {
        if (!extension_loaded('openssl') || !function_exists('openssl_encrypt')) {
            throw new RuntimeException('The OpenSSL PHP extension is required.');
        }
    }

    private function getEncryptionKey()
    {
        if (!defined('_COOKIE_KEY_') || _COOKIE_KEY_ === '') {
            throw new RuntimeException('The PrestaShop installation key is unavailable.');
        }

        return hash('sha256', _COOKIE_KEY_ . '|redenlace-secret-storage', true);
    }
}
