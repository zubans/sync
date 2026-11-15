<?php

namespace App\Tests\Service;

use App\Service\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testEncryptDecryptRoundtripAndMask(): void
    {
        $key = 'base64:'.base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $box = new SecretBox('fallback-app-secret', $key);

        $cipher = $box->encrypt('sk-live-abcdef1234');
        self::assertIsString($cipher);
        self::assertStringStartsWith('sbox:v1:', $cipher);
        self::assertStringNotContainsString('sk-live', $cipher);
        self::assertSame('sk-live-abcdef1234', $box->decrypt($cipher));
        self::assertSame('…1234', $box->mask($cipher));
    }

    public function testNullAndShortSecrets(): void
    {
        $box = new SecretBox('app-secret', null);

        self::assertNull($box->encrypt(null));
        self::assertNull($box->encrypt(''));
        self::assertNull($box->decrypt(null));
        self::assertNull($box->decrypt(''));
        self::assertNull($box->mask(null));

        $short = $box->encrypt('12345');
        self::assertSame('12345', $box->decrypt($short));
        self::assertSame('•••••', $box->mask($short));
    }

    public function testWrongKeyMaskAndDecryptFailure(): void
    {
        $boxA = new SecretBox('secret-a', null);
        $boxB = new SecretBox('secret-b', null);

        $cipher = $boxA->encrypt('top-secret-token');
        self::assertSame('•••• (не расшифрован)', $boxB->mask($cipher));

        $this->expectException(\RuntimeException::class);
        $boxB->decrypt($cipher);
    }
}
