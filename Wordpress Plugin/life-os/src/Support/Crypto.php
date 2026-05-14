<?php

declare(strict_types=1);

namespace LifeOS\Support;

final class Crypto
{
    private const CIPHER = 'aes-256-cbc';

    public function encrypt(?string $plainText): string
    {
        if ($plainText === null || $plainText === '') {
            return '';
        }

        $key = $this->key();
        $iv = random_bytes(16);
        $cipherText = openssl_encrypt($plainText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($cipherText === false) {
            return '';
        }

        $mac = hash_hmac('sha256', $iv . $cipherText, $key, true);

        return base64_encode($iv . $mac . $cipherText);
    }

    public function decrypt(?string $payload): string
    {
        if ($payload === null || $payload === '') {
            return '';
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < 49) {
            return '';
        }

        $key = $this->key();
        $iv = substr($decoded, 0, 16);
        $mac = substr($decoded, 16, 32);
        $cipherText = substr($decoded, 48);
        $expected = hash_hmac('sha256', $iv . $cipherText, $key, true);

        if (! hash_equals($expected, $mac)) {
            return '';
        }

        $plainText = openssl_decrypt($cipherText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return $plainText === false ? '' : $plainText;
    }

    private function key(): string
    {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : 'life-os-auth-key')
            . '|'
            . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : 'life-os-auth-salt');

        return hash('sha256', $material, true);
    }
}

