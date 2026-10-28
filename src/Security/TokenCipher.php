<?php

namespace App\Security;

/** Encrypts the GitHub tokens kept in the database (libsodium secretbox, key TOKEN_KEY). */
final class TokenCipher
{
    private string $key;

    public function __construct(string $tokenKey)
    {
        $key = base64_decode($tokenKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('TOKEN_KEY must be 32 bytes in base64');
        }
        $this->key = $key;
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encrypted): string
    {
        $raw = (string)base64_decode($encrypted, true);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if ($plain === false) {
            throw new \RuntimeException('a stored token could not be decrypted (TOKEN_KEY changed?)');
        }
        return $plain;
    }
}
