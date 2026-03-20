<?php
/**
 * EncryptionService — secures API keys using WP AUTH_KEY.
 */

declare(strict_types = 1)
;

namespace IdiomatticWP\Support;

class EncryptionService
{

    private string $method = 'aes-256-cbc';

    /**
     * Encrypt a sensitive value.
     */
    public function encrypt(string $value): string
    {
        $key = $this->getKey();
        $ivLength = openssl_cipher_iv_length($this->method);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt($value, $this->method, $key, 0, $iv);

        // Return IV + Encrypted data
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a value.
     */
    public function decrypt(string $encrypted): string
    {
        $key = $this->getKey();
        $data = base64_decode($encrypted);

        $ivLength = openssl_cipher_iv_length($this->method);
        $iv = substr($data, 0, $ivLength);
        $cipherText = substr($data, $ivLength);

        $decrypted = openssl_decrypt($cipherText, $this->method, $key, 0, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $decrypted;
    }

    private function getKey(): string
    {
        // Use salt from wp-config if available
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'default-salt-idiomattic';
        $secureSalt = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'idiomattic-fallback-2026';

        return hash_pbkdf2('sha256', $salt, $secureSalt, 1000, 32, true);
    }
}
