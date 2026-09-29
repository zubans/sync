<?php

namespace App\Tests\Api;

use App\Entity\ApkBlob;
use App\Service\ApkStorage;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AppApiTest extends DatabaseWebTestCase
{
    private const DEVICE = ['installId' => 'install-aaaa-0001', 'model' => 'Pixel'];

    private string $token;
    private string $apk;
    private string $apkSha;

    protected function setUp(): void
    {
        parent::setUp();
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir').'/var/storage/apk_test');

        $this->createUser('anna@example.com');
        $this->token = $this->login('anna@example.com');
        $this->apk = random_bytes(3000);
        $this->apkSha = hash('sha256', $this->apk);
    }

    public function testOnlyNonPlayApksAreRequested(): void
    {
        $response = $this->inventory([
            $this->app('org.example.sideloaded', $this->apkSha, \strlen($this->apk), installer: null),
            $this->app('com.whatsapp', str_repeat('a', 64), 100, installer: 'com.android.vending'),
        ]);

        self::assertSame([$this->apkSha], $response['missing']);
    }

    public function testChunkedUploadWithResumeAndDownload(): void
    {
        $this->inventory([$this->app('org.example.sideloaded', $this->apkSha, \strlen($this->apk))]);
        $url = '/api/apk/uploads/'.$this->apkSha;

        self::assertSame(0, $this->api('GET', $url, token: $this->token)['offset']);

        $this->putChunk($url, 0, substr($this->apk, 0, 1000));
        self::assertResponseIsSuccessful();

        // Обрыв связи: клиент спрашивает, с какого места продолжать.
        self::assertSame(1000, $this->api('GET', $url, token: $this->token)['offset']);

        // Неверное смещение — 409 и правильное место.
        $this->putChunk($url, 500, substr($this->apk, 500));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1000, json_decode($this->client->getResponse()->getContent(), true)['offset']);

        $this->putChunk($url, 1000, substr($this->apk, 1000));
        self::assertSame(3000, json_decode($this->client->getResponse()->getContent(), true)['offset']);

        $this->api('POST', $url.'/complete', token: $this->token);
        self::assertResponseStatusCodeSame(201);

        // Повторная инвентаризация больше не просит этот файл.
        self::assertSame([], $this->inventory([$this->app('org.example.sideloaded', $this->apkSha, \strlen($this->apk))])['missing']);

        $apps = $this->api('GET', '/api/apps', token: $this->token)['apps'];
        self::assertTrue($apps[0]['backedUp']);

        $this->client->request('GET', '/api/apk/'.$this->apkSha, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertResponseIsSuccessful();
        self::assertSame($this->apk, $this->client->getInternalResponse()->getContent());
    }

    public function testCorruptedUploadIsRejected(): void
    {
        $this->inventory([$this->app('org.example.sideloaded', $this->apkSha, \strlen($this->apk))]);
        $url = '/api/apk/uploads/'.$this->apkSha;

        $this->putChunk($url, 0, str_repeat('x', \strlen($this->apk)));
        $this->api('POST', $url.'/complete', token: $this->token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->api('GET', $url, token: $this->token)['offset']);
    }

    public function testOversizedUploadIsRejected(): void
    {
        $this->inventory([$this->app('org.example.sideloaded', $this->apkSha, 10)]);

        $this->putChunk('/api/apk/uploads/'.$this->apkSha, 0, $this->apk);

        self::assertResponseStatusCodeSame(413);
    }

    public function testCannotUploadOrDownloadForeignFiles(): void
    {
        $this->inventory([$this->app('org.example.sideloaded', $this->apkSha, \strlen($this->apk))]);

        $this->createUser('boris@example.com');
        $boris = $this->login('boris@example.com');

        $this->api('GET', '/api/apk/uploads/'.$this->apkSha, token: $boris);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/apk/'.$this->apkSha, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$boris]);
        self::assertResponseStatusCodeSame(404);

        // Приложения из Play по умолчанию не загружаются.
        $playSha = str_repeat('b', 64);
        $this->inventory([$this->app('com.whatsapp', $playSha, 100, installer: 'com.android.vending')]);
        $this->api('GET', '/api/apk/uploads/'.$playSha, token: $this->token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testListShowsLatestVersionAndHidesRemoved(): void
    {
        $this->inventory([
            $this->app('org.example.a', str_repeat('1', 64), 10, versionCode: 5),
            $this->app('org.example.removed', str_repeat('2', 64), 10),
        ]);
        $this->inventory([$this->app('org.example.a', str_repeat('3', 64), 10, versionCode: 7)], ['installId' => 'install-bbbb-0002'] + self::DEVICE);
        // На первом устройстве удалили org.example.removed.
        $this->inventory([$this->app('org.example.a', str_repeat('1', 64), 10, versionCode: 5)]);

        $apps = $this->api('GET', '/api/apps', token: $this->token)['apps'];

        self::assertSame(['org.example.a'], array_column($apps, 'packageName'));
        self::assertSame(7, $apps[0]['versionCode']);
        self::assertFalse($apps[0]['backedUp']);
    }

    public function testCurrentAndPreviousVersionsAreKeptOlderAreDeleted(): void
    {
        $storage = static::getContainer()->get(ApkStorage::class);
        [$v1, $v1sha] = [$this->apk, $this->apkSha];
        $this->inventory([$this->app('org.example.sideloaded', $v1sha, \strlen($v1))]);
        $this->uploadWhole($v1sha, $v1);

        // Обновили до v2: v1 становится прошлой и остаётся на сервере.
        [$v2, $v2sha] = $this->version(2000);
        self::assertSame([$v2sha], $this->inventory([$this->app('org.example.sideloaded', $v2sha, 2000, versionCode: 2)])['missing']);
        $this->uploadWhole($v2sha, $v2);
        self::assertTrue($storage->has($v1sha));

        $app = $this->api('GET', '/api/apps', token: $this->token)['apps'][0];
        self::assertSame(2, $app['versionCode']);
        self::assertSame(1, $app['previous']['versionCode']);
        self::assertSame($v1sha, $app['previous']['files'][0]['sha256']);
        // Прошлую версию можно скачать для отката.
        $this->client->request('GET', '/api/apk/'.$v1sha, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertResponseIsSuccessful();

        // Обновили до v3: прошлой становится v2, а v1 больше не нужна.
        [, $v3sha] = $this->version(1500);
        $this->inventory([$this->app('org.example.sideloaded', $v3sha, 1500, versionCode: 3)]);
        self::assertFalse($storage->has($v1sha));
        self::assertTrue($storage->has($v2sha));
        self::assertSame(2, $this->api('GET', '/api/apps', token: $this->token)['apps'][0]['previous']['versionCode']);
    }

    public function testPreviousVersionIsNotLostIfNewOneWasNotUploaded(): void
    {
        $storage = static::getContainer()->get(ApkStorage::class);
        $this->inventory([$this->app('org.example.sideloaded', $this->apkSha, \strlen($this->apk))]);
        $this->uploadWhole($this->apkSha, $this->apk);

        // v2 так и не загрузилась (обрыв связи), а приложение уже обновилось до v3.
        [, $v2sha] = $this->version(2000);
        $this->inventory([$this->app('org.example.sideloaded', $v2sha, 2000, versionCode: 2)]);
        [, $v3sha] = $this->version(1500);
        $this->inventory([$this->app('org.example.sideloaded', $v3sha, 1500, versionCode: 3)]);

        // Откатиться всё ещё можно на v1 — единственную сохранённую.
        self::assertTrue($storage->has($this->apkSha));
        self::assertSame(1, $this->api('GET', '/api/apps', token: $this->token)['apps'][0]['previous']['versionCode']);
    }

    /** @return array{string, string} байты и SHA-256 «новой версии» APK */
    private function version(int $size): array
    {
        $bytes = random_bytes($size);

        return [$bytes, hash('sha256', $bytes)];
    }

    public function testApkOfRemovedAppIsDeleted(): void
    {
        $storage = static::getContainer()->get(ApkStorage::class);
        $this->inventory([$this->app('org.example.sideloaded', $this->apkSha, \strlen($this->apk))]);
        $this->uploadWhole($this->apkSha, $this->apk);

        $this->inventory([]);

        self::assertFalse($storage->has($this->apkSha));
    }

    private function uploadWhole(string $sha, string $bytes): void
    {
        $this->putChunk('/api/apk/uploads/'.$sha, 0, $bytes);
        $this->api('POST', '/api/apk/uploads/'.$sha.'/complete', token: $this->token);
        self::assertResponseStatusCodeSame(201);
    }

    /** @return array<string, mixed> */
    private function app(string $package, string $sha, int $size, ?string $installer = null, int $versionCode = 1): array
    {
        return [
            'packageName' => $package,
            'label' => $package,
            'versionName' => '1.0',
            'versionCode' => $versionCode,
            'installer' => $installer,
            'signingSha256' => str_repeat('c', 64),
            'files' => [['name' => 'base.apk', 'sha256' => $sha, 'size' => $size]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $apps
     * @param array<string, string>      $device
     *
     * @return array<string, mixed>
     */
    private function inventory(array $apps, array $device = self::DEVICE): array
    {
        $response = $this->api('POST', '/api/apps/inventory', ['device' => $device, 'apps' => $apps], $this->token);
        self::assertResponseIsSuccessful();

        return $response;
    }

    private function putChunk(string $url, int $offset, string $bytes): void
    {
        $this->client->request('PUT', $url.'?offset='.$offset, server: [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], content: $bytes);
    }
}
