<?php

namespace App\Service;

/**
 * Шифрование ключей и OAuth-токенов ИИ-провайдеров в БД через libsodium secretbox
 * (по образцу KB_ENCRYPTION_KEY в KB). На фронт возвращается только маска `…abcd`.
 */
final class SecretBox
{
    private const PREFIX = 'sbox:v1:';

    public function __construct(
        private readonly string $appSecret,
        private readonly ?string $encryptionKey = null,
    ) {
    }

    public function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return null;
        }
        $key = $this->deriveKey();
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

        return self::PREFIX.base64_encode($nonce.$cipher);
    }

    public function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, \strlen(self::PREFIX)), true);
        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Повреждён зашифрованный ключ провайдера.');
        }
        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->deriveKey());
        if ($plain === false) {
            throw new \RuntimeException('Не удалось расшифровать ключ: проверьте APP_ENCRYPTION_KEY / APP_SECRET.');
        }

        return $plain;
    }

    /** Маска для интерфейса (как в KB): `…abcd`, без раскрытия секрета. */
    public function mask(?string $storedOrPlain): ?string
    {
        if ($storedOrPlain === null || $storedOrPlain === '') {
            return null;
        }
        try {
            $plain = str_starts_with($storedOrPlain, self::PREFIX) ? (string) $this->decrypt($storedOrPlain) : $storedOrPlain;
        } catch (\Throwable) {
            return '•••• (не расшифрован)';
        }
        if ($plain === '') {
            return null;
        }
        $len = mb_strlen($plain);

        return $len <= 6 ? str_repeat('•', $len) : '…'.mb_substr($plain, -4);
    }

    private function deriveKey(): string
    {
        $configured = trim((string) $this->encryptionKey);
        if ($configured !== '') {
            if (str_starts_with($configured, 'base64:')) {
                $decoded = base64_decode(substr($configured, 7), true);
                if ($decoded !== false && \strlen($decoded) === \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                    return $decoded;
                }
            }
            $decoded = base64_decode($configured, true);
            if ($decoded !== false && \strlen($decoded) === \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $decoded;
            }

            return hash_hkdf('sha256', $configured, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'sync-ai-secrets-v1');
        }

        $fallback = trim($this->appSecret);
        if ($fallback === '') {
            throw new \RuntimeException('Для шифрования ключей задайте APP_ENCRYPTION_KEY или APP_SECRET.');
        }

        return hash_hkdf('sha256', $fallback, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'sync-ai-secrets-v1');
    }
}
