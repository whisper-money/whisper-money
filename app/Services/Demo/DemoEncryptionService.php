<?php

namespace App\Services\Demo;

class DemoEncryptionService
{
    private const PBKDF2_ITERATIONS = 100000;

    private const SALT_LENGTH = 16;

    private const IV_LENGTH = 12;

    private const KEY_LENGTH = 32;

    /**
     * Generate a deterministic salt from a seed string.
     */
    public function generateSalt(string $seed = 'demo'): string
    {
        $salt = hash('sha256', $seed, true);

        return base64_encode(substr($salt, 0, self::SALT_LENGTH));
    }

    /**
     * Derive an AES key from password and salt using PBKDF2.
     */
    public function deriveKey(string $password, string $saltBase64): string
    {
        $salt = base64_decode($saltBase64);

        return hash_pbkdf2(
            'sha256',
            $password,
            $salt,
            self::PBKDF2_ITERATIONS,
            self::KEY_LENGTH,
            true
        );
    }

    /**
     * Encrypt plaintext using AES-256-GCM.
     *
     * @return array{encrypted: string, iv: string}
     */
    public function encrypt(string $plaintext, string $key, ?string $deterministicIvSeed = null): array
    {
        if ($deterministicIvSeed !== null) {
            $iv = substr(hash('sha256', $deterministicIvSeed, true), 0, self::IV_LENGTH);
        } else {
            $iv = random_bytes(self::IV_LENGTH);
        }

        $tag = '';
        $encrypted = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        $encryptedWithTag = $encrypted.$tag;

        return [
            'encrypted' => base64_encode($encryptedWithTag),
            'iv' => base64_encode($iv),
        ];
    }

    /**
     * Decrypt ciphertext using AES-256-GCM.
     */
    public function decrypt(string $encryptedBase64, string $key, string $ivBase64): string
    {
        $encryptedWithTag = base64_decode($encryptedBase64);
        $iv = base64_decode($ivBase64);

        $tagLength = 16;
        $encrypted = substr($encryptedWithTag, 0, -$tagLength);
        $tag = substr($encryptedWithTag, -$tagLength);

        $decrypted = openssl_decrypt(
            $encrypted,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $decrypted;
    }
}
