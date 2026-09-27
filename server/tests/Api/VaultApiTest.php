<?php

namespace App\Tests\Api;

use App\Tests\DatabaseWebTestCase;

final class VaultApiTest extends DatabaseWebTestCase
{
    private const ITEM = '0192f0c1-1111-7000-8000-000000000001';
    private const KEY = [
        'kdfAlgorithm' => 'pbkdf2-sha256',
        'kdfIterations' => 600000,
        'kdfSalt' => 'c2FsdHNhbHRzYWx0c2FsdA==',
        'protectedKey' => 'djEuZW5jcnlwdGVkLWtleQ==',
    ];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('anna@example.com');
        $this->token = $this->login('anna@example.com');
    }

    public function testVaultLifecycle(): void
    {
        $this->api('GET', '/api/vault', token: $this->token);
        self::assertResponseStatusCodeSame(404);

        $created = $this->api('POST', '/api/vault', self::KEY, $this->token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $created['revision']);

        $this->api('POST', '/api/vault', self::KEY, $this->token);
        self::assertResponseStatusCodeSame(409);

        $rekeyed = $this->api('PUT', '/api/vault/key', ['protectedKey' => 'bmV3LWtleQ=='] + self::KEY, $this->token);
        self::assertSame('bmV3LWtleQ==', $rekeyed['protectedKey']);

        $this->api('DELETE', '/api/vault', token: $this->token);
        self::assertResponseStatusCodeSame(204);
        $this->api('GET', '/api/vault', token: $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWeakKdfIsRejected(): void
    {
        $this->api('POST', '/api/vault', ['kdfIterations' => 1000] + self::KEY, $this->token);
        self::assertResponseStatusCodeSame(422);

        $argon = ['kdfAlgorithm' => 'argon2id', 'kdfIterations' => 2, 'kdfMemory' => 19456, 'kdfParallelism' => 1] + self::KEY;
        $this->api('POST', '/api/vault', ['kdfMemory' => 1024] + $argon, $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->api('POST', '/api/vault', ['kdfIterations' => 1] + $argon, $this->token);
        self::assertResponseStatusCodeSame(422);
        $this->api('POST', '/api/vault', ['kdfMemory' => null] + $argon, $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testArgon2idVaultAndUpgradeFromPbkdf2(): void
    {
        $this->createVault();

        // Клиент перешифровал ключ хранилища тем же мастер-паролем, но через Argon2id.
        $argon = ['kdfAlgorithm' => 'argon2id', 'kdfIterations' => 2, 'kdfMemory' => 19456, 'kdfParallelism' => 1] + self::KEY;
        $this->api('PUT', '/api/vault/key', $argon, $this->token);
        self::assertResponseIsSuccessful();

        $vault = $this->api('GET', '/api/vault', token: $this->token);
        self::assertSame('argon2id', $vault['kdfAlgorithm']);
        self::assertSame(19456, $vault['kdfMemory']);
        self::assertSame(1, $vault['kdfParallelism']);
        self::assertSame(2, $vault['kdfIterations']);
    }

    public function testConcurrentEditProducesConflictWithCurrentVersion(): void
    {
        $this->createVault();
        $created = $this->push([['id' => self::ITEM, 'baseRevision' => null, 'data' => 'djE=']]);
        self::assertSame('ok', $created['results'][0]['status']);
        $rev = $created['results'][0]['revision'];

        // Телефон A меняет пароль от ревизии $rev — принимается.
        $a = $this->push([['id' => self::ITEM, 'baseRevision' => $rev, 'data' => 'QQ==']]);
        self::assertSame('ok', $a['results'][0]['status']);

        // Телефон B правил ту же запись от старой ревизии — конфликт и текущая версия A.
        $b = $this->push([['id' => self::ITEM, 'baseRevision' => $rev, 'data' => 'Qg==']]);
        self::assertSame('conflict', $b['results'][0]['status']);
        self::assertSame('QQ==', $b['results'][0]['current']['data']);

        // B свёл версии и прислал результат от актуальной ревизии.
        $merged = $this->push([['id' => self::ITEM, 'baseRevision' => $b['results'][0]['current']['revision'], 'data' => 'QUI=']]);
        self::assertSame('ok', $merged['results'][0]['status']);
    }

    public function testCreatingExistingIdIsConflict(): void
    {
        $this->createVault();
        $this->push([['id' => self::ITEM, 'data' => 'djE=']]);

        $again = $this->push([['id' => self::ITEM, 'data' => 'djI=']]);

        self::assertSame('conflict', $again['results'][0]['status']);
    }

    public function testIncrementalPullReturnsChangesAndTombstones(): void
    {
        $this->createVault();
        $first = $this->push([
            ['id' => self::ITEM, 'data' => 'djE='],
            ['id' => '0192f0c1-2222-7000-8000-000000000002', 'data' => 'djI='],
        ]);
        $since = $first['revision'];

        $this->push([['id' => self::ITEM, 'baseRevision' => $first['results'][0]['revision'], 'deleted' => true]]);

        $all = $this->api('GET', '/api/vault/items', token: $this->token);
        self::assertCount(2, $all['items']);

        $changes = $this->api('GET', '/api/vault/items?since='.$since, token: $this->token);
        self::assertCount(1, $changes['items']);
        self::assertSame(self::ITEM, $changes['items'][0]['id']);
        self::assertTrue($changes['items'][0]['deleted']);
        self::assertNull($changes['items'][0]['data']);
        self::assertSame($changes['revision'], $changes['items'][0]['revision']);
    }

    public function testVaultsAreIsolated(): void
    {
        $this->createVault();
        $this->push([['id' => self::ITEM, 'data' => 'djE=']]);

        $this->createUser('boris@example.com');
        $boris = $this->login('boris@example.com');
        $this->api('GET', '/api/vault/items', token: $boris);
        self::assertResponseStatusCodeSame(404);

        $this->api('POST', '/api/vault', self::KEY, $boris);
        $result = $this->api('POST', '/api/vault/items', ['changes' => [['id' => self::ITEM, 'baseRevision' => 1, 'data' => 'eA==']]], $boris);
        // Тот же uuid у другого пользователя — это его собственная новая запись, чужая не затрагивается.
        self::assertSame('ok', $result['results'][0]['status']);
        self::assertSame('djE=', $this->api('GET', '/api/vault/items', token: $this->token)['items'][0]['data']);
    }

    public function testChangeWithoutDataIsRejected(): void
    {
        $this->createVault();

        $this->api('POST', '/api/vault/items', ['changes' => [['id' => self::ITEM]]], $this->token);

        self::assertResponseStatusCodeSame(422);
    }

    private function createVault(): void
    {
        $this->api('POST', '/api/vault', self::KEY, $this->token);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @param list<array<string, mixed>> $changes
     *
     * @return array<string, mixed>
     */
    private function push(array $changes): array
    {
        $response = $this->api('POST', '/api/vault/items', ['changes' => $changes], $this->token);
        self::assertResponseIsSuccessful();

        return $response;
    }
}
